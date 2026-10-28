<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Das Mannschaftsbuch: Dokumentation, Vermerke, Aufgaben, Bahnen,
 * Springer und die Trainer des Tages.
 *
 * Alles, was zu einem Trainingstag gehört und nicht der Zustand einer
 * Person ist — der steht in LSV07S_Anwesenheit.
 *
 * Drei Dinge sind hier anders als im internen Bereich:
 *
 * 1. **Einträge sind änderbar.** Dort gibt es anlegen, erledigt und
 *    löschen — aber kein Ändern. Ein Tippfehler in einer Aufgabe heisst
 *    löschen und neu schreiben, und damit ist auch die Spur weg, wer sie
 *    wann angelegt hat.
 * 2. **Jede Änderung steht im Protokoll.** Dort kommt
 *    `LSV07I_Log::write` in der ganzen Buch-Datei nicht ein einziges Mal
 *    vor.
 * 3. **Eine Aufgabe kann eine Mitteilung auslösen.** Dort passiert
 *    nichts: Springer, Attest und Abrechnung mailen, eine Aufgabe für
 *    einen Schwimmer nicht — sie wird nur gesehen, wenn jemand ins Buch
 *    schaut.
 */
class LSV07S_Buch {

    /** Arten eines Eintrags. */
    const ARTEN = [
        'vermerk' => 'Vermerk',
        'aufgabe' => 'Aufgabe',
    ];

    /** Kategorien eines Vermerks. */
    const KATEGORIEN = [
        'ziel'      => 'Trainingsziel',
        'hinweis'   => 'Hinweis',
        'sonstiges' => 'Sonstiges',
    ];

    /** Nur Schwimmen hat ein Mannschaftsbuch. */
    private static function moeglich( $sparte ) {
        if ( ! LSV07S_Sparte::feld( $sparte, 'hat_buch' ) ) {
            return 'Ein Mannschaftsbuch gibt es nur im Schwimmen.';
        }
        if ( ! LSV07S_Tabellen::da( 'mb_tag' ) || ! LSV07S_Tabellen::da( 'mb_eintrag' ) ) {
            return 'Die Tabellen des Mannschaftsbuchs fehlen.';
        }
        return true;
    }

    /** Darf dieses Konto im Buch dieser Mannschaft schreiben? */
    private static function darf_schreiben( $sparte, $mannschaft_id ) {
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( ! LSV07S_Rechte::darf_eines( [
                LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT,
                LSV07S_Sparte::recht( $sparte, 'anw.update' ) ] ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        return true;
    }

    /** Darf dieses Konto das Buch dieser Mannschaft lesen? */
    private static function darf_lesen( $sparte, $mannschaft_id ) {
        if ( ! LSV07S_Rechte::darf_eines( [
                LSV07S_Rechte::MANNSCHAFTSBUCH_READ,
                LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT,
                LSV07S_Sparte::recht( $sparte, 'anw.read' ) ] ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( (int) $mannschaft_id, $erlaubt, true ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        return true;
    }

    /**
     * Die Nummer des Trainingstags — notfalls angelegt.
     *
     * Dieselbe Funktion wie in LSV07S_Anwesenheit, aber hier öffentlich:
     * Zwei Stellen, die einen Trainingstag anlegen, würden bei
     * gleichzeitigem Zugriff zwei Zeilen erzeugen — der eindeutige
     * Schlüssel (mannschaft_id, datum) verhindert das, und beide Wege
     * holen sich die vorhandene Zeile.
     */
    public static function tag_id( $mannschaft_id, $datum, $anlegen = false ) {
        global $wpdb;
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null || ! LSV07S_Tabellen::da( 'mb_tag' ) ) return 0;
        $t  = LSV07S_Tabellen::t( 'mb_tag' );
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE mannschaft_id = %d AND datum = %s LIMIT 1",
            (int) $mannschaft_id, $d ) );
        if ( $id || ! $anlegen ) return $id;
        $wpdb->insert( $t, [
            'mannschaft_id' => (int) $mannschaft_id,
            'datum'         => $d,
            'angelegt_von'  => get_current_user_id(),
            'angelegt_am'   => LSV07S_Datum::jetzt(),
            'geaendert_am'  => LSV07S_Datum::jetzt(),
        ], [ '%d', '%s', '%d', '%s', '%s' ] );
        $neu = (int) $wpdb->insert_id;
        if ( $neu ) return $neu;
        /* Hat jemand anderes die Zeile im selben Moment angelegt, läuft der
           Einfügevorgang auf den eindeutigen Schlüssel — dann gilt seine. */
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE mannschaft_id = %d AND datum = %s LIMIT 1",
            (int) $mannschaft_id, $d ) );
    }

    // ── Dokumentation ────────────────────────────────────────────────────

    /** Was an diesem Tag trainiert wurde, als Freitext. */
    public static function doku( $sparte, $mannschaft_id, $datum ) {
        global $wpdb;
        $erg = self::moeglich( $sparte );
        if ( $erg !== true ) return '';
        $id = self::tag_id( $mannschaft_id, $datum, false );
        if ( ! $id ) return '';
        return (string) $wpdb->get_var( $wpdb->prepare(
            'SELECT dokumentation FROM ' . LSV07S_Tabellen::t( 'mb_tag' )
            . ' WHERE id = %d', $id ) );
    }

    public static function doku_speichern( $sparte, $mannschaft_id, $datum, $text ) {
        global $wpdb;
        $erg = self::moeglich( $sparte );
        if ( $erg !== true ) return $erg;
        $erg = self::darf_schreiben( $sparte, $mannschaft_id );
        if ( $erg !== true ) return $erg;
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return 'Dieses Datum gibt es nicht.';
        if ( $d > LSV07S_Datum::heute() ) return 'Dieser Tag liegt in der Zukunft.';

        $text = sanitize_textarea_field( (string) $text );
        if ( mb_strlen( $text ) > 20000 ) return 'Der Text ist zu lang.';

        $id = self::tag_id( $mannschaft_id, $d, true );
        if ( ! $id ) return 'Der Trainingstag konnte nicht angelegt werden.';

        $wpdb->update( LSV07S_Tabellen::t( 'mb_tag' ),
            [ 'dokumentation' => $text, 'geaendert_am' => LSV07S_Datum::jetzt() ],
            [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );

        LSV07S_Log::schreibe( 'buch.doku', [
            'bereich' => $sparte, 'ziel_typ' => 'tag', 'ziel_id' => $id,
            'ziel_name' => LSV07S_Mannschaft::holen( $sparte, $mannschaft_id )['name'] ?? '',
            'details' => $d . ': ' . ( $text === '' ? 'geleert' : mb_strlen( $text ) . ' Zeichen' ),
        ] );
        return true;
    }

    // ── Vermerke und Aufgaben ────────────────────────────────────────────

    /**
     * Einen Vermerk oder eine Aufgabe anlegen oder ändern.
     *
     * Ändern gibt es im internen Bereich nicht. Ein Tippfehler heisst dort
     * löschen und neu schreiben — und damit ist auch die Angabe weg, wer
     * den Eintrag wann angelegt hat.
     *
     * @return int|string Die Nummer, oder der Satz für den Nutzer.
     */
    public static function eintrag_speichern( $sparte, $mannschaft_id, $datum, $id, array $daten ) {
        global $wpdb;
        $erg = self::moeglich( $sparte );
        if ( $erg !== true ) return $erg;
        $erg = self::darf_schreiben( $sparte, $mannschaft_id );
        if ( $erg !== true ) return $erg;

        $id = (int) $id;
        $d  = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return 'Dieses Datum gibt es nicht.';

        $art = sanitize_key( (string) ( $daten['art'] ?? 'vermerk' ) );
        if ( ! isset( self::ARTEN[ $art ] ) ) return 'Diese Art gibt es nicht.';

        $kategorie = sanitize_key( (string) ( $daten['kategorie'] ?? 'sonstiges' ) );
        if ( ! isset( self::KATEGORIEN[ $kategorie ] ) ) $kategorie = 'sonstiges';

        $text = trim( sanitize_textarea_field( (string) ( $daten['text'] ?? '' ) ) );
        if ( $text === '' ) return 'Bitte schreiben Sie etwas dazu.';
        if ( mb_strlen( $text ) > 5000 ) return 'Der Text ist zu lang.';

        $person = absint( $daten['person_id'] ?? 0 );
        /* Eine Aufgabe braucht jemanden, der sie erledigt; ein Vermerk
           kann der ganzen Mannschaft gelten (person_id 0). */
        if ( $art === 'aufgabe' && ! $person ) {
            return 'Bitte geben Sie an, für wen die Aufgabe ist.';
        }
        if ( $person && ! LSV07S_Anwesenheit::gehoert_dazu( $sparte, $mannschaft_id, $person ) ) {
            return 'Diese Person gehört nicht zu dieser Mannschaft.';
        }

        $tag_id = self::tag_id( $mannschaft_id, $d, true );
        if ( ! $tag_id ) return 'Der Trainingstag konnte nicht angelegt werden.';

        $t = LSV07S_Tabellen::t( 'mb_eintrag' );
        if ( $id ) {
            $alt = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $t WHERE id = %d LIMIT 1", $id ), ARRAY_A );
            if ( ! $alt ) return 'Diesen Eintrag gibt es nicht.';
            /* Der Eintrag muss zu einer Mannschaft gehören, in der man
               arbeiten darf — sonst liesse sich über eine geratene Nummer
               ein fremder Eintrag ändern. */
            if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, (int) $alt['mannschaft_id'] ) ) {
                return 'Dieser Eintrag gehört nicht zu Ihrem Bereich.';
            }
            $wpdb->update( $t, [
                'art'          => $art,
                'kategorie'    => $kategorie,
                'text'         => $text,
                'schwimmer_id' => $person,
            ], [ 'id' => $id ], [ '%s', '%s', '%s', '%d' ], [ '%d' ] );

            LSV07S_Log::schreibe( 'buch.eintrag_geaendert', [
                'bereich' => $sparte, 'ziel_typ' => 'eintrag', 'ziel_id' => $id,
                'details' => $d . ', ' . self::ARTEN[ $art ]
                           . ( (string) $alt['text'] !== $text ? ', Text geändert' : '' ),
            ] );
            return $id;
        }

        $ok = $wpdb->insert( $t, [
            'tag_id'        => $tag_id,
            'mannschaft_id' => (int) $mannschaft_id,
            'schwimmer_id'  => $person,
            'art'           => $art,
            'kategorie'     => $kategorie,
            'text'          => $text,
            'erledigt'      => 0,
            'angelegt_von'  => get_current_user_id(),
            'angelegt_am'   => LSV07S_Datum::jetzt(),
        ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ] );
        if ( ! $ok ) return 'Der Eintrag konnte nicht angelegt werden.';
        $neu = (int) $wpdb->insert_id;

        LSV07S_Log::schreibe( 'buch.eintrag', [
            'bereich' => $sparte, 'ziel_typ' => 'eintrag', 'ziel_id' => $neu,
            'ziel_name' => $person ? self::person_name( $sparte, $person ) : '',
            'details' => $d . ', ' . self::ARTEN[ $art ],
        ] );
        return $neu;
    }

    private static function person_name( $sparte, $person_id ) {
        global $wpdb;
        $t = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            (int) $person_id ), ARRAY_A );
        return $z ? LSV07S_Person::aus_zeile( $z, $sparte ) : ( 'Nr. ' . (int) $person_id );
    }

    /** Eine Aufgabe als erledigt vermerken — oder das zurücknehmen. */
    public static function eintrag_erledigt( $sparte, $id, $erledigt = true ) {
        global $wpdb;
        $erg = self::moeglich( $sparte );
        if ( $erg !== true ) return $erg;
        $id = (int) $id;
        $t  = LSV07S_Tabellen::t( 'mb_eintrag' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diesen Eintrag gibt es nicht.';
        $erg = self::darf_schreiben( $sparte, (int) $zeile['mannschaft_id'] );
        if ( $erg !== true ) return $erg;
        if ( (string) $zeile['art'] !== 'aufgabe' ) {
            return 'Nur eine Aufgabe kann erledigt werden.';
        }

        $wpdb->update( $t, [ 'erledigt' => $erledigt ? 1 : 0 ], [ 'id' => $id ],
            [ '%d' ], [ '%d' ] );
        LSV07S_Log::schreibe( $erledigt ? 'buch.aufgabe_erledigt' : 'buch.aufgabe_offen', [
            'bereich' => $sparte, 'ziel_typ' => 'eintrag', 'ziel_id' => $id,
            'details' => mb_substr( (string) $zeile['text'], 0, 80 ),
        ] );
        return true;
    }

    /** Einen Eintrag löschen. Vorher protokollieren, dann löschen. */
    public static function eintrag_loeschen( $sparte, $id ) {
        global $wpdb;
        $erg = self::moeglich( $sparte );
        if ( $erg !== true ) return $erg;
        $id = (int) $id;
        $t  = LSV07S_Tabellen::t( 'mb_eintrag' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diesen Eintrag gibt es nicht.';
        $erg = self::darf_schreiben( $sparte, (int) $zeile['mannschaft_id'] );
        if ( $erg !== true ) return $erg;

        LSV07S_Log::schreibe( 'buch.eintrag_geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'eintrag', 'ziel_id' => $id,
            /* Die Klammern sind nötig: `??` bindet schwächer als `.`, und
               ohne sie stünde im Protokoll nur die Art — der Text, also
               das Einzige, was man hinterher nicht mehr ermitteln kann,
               fiele weg. */
            'details' => ( self::ARTEN[ $zeile['art'] ] ?? (string) $zeile['art'] )
                       . ': ' . mb_substr( (string) $zeile['text'], 0, 120 ),
        ] );
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return true;
    }

    /**
     * Die offenen Aufgaben einer Mannschaft — über alle Tage.
     *
     * Im internen Bereich stehen Aufgaben nur am Tag, an dem sie
     * entstanden sind. Wer nicht weiss, wann das war, findet sie nicht.
     */
    public static function offene_aufgaben( $sparte, $mannschaft_id ) {
        global $wpdb;
        $erg = self::moeglich( $sparte );
        if ( $erg !== true ) return [];
        if ( self::darf_lesen( $sparte, $mannschaft_id ) !== true ) return [];

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT e.*, t.datum FROM ' . LSV07S_Tabellen::t( 'mb_eintrag' ) . ' e'
            . ' INNER JOIN ' . LSV07S_Tabellen::t( 'mb_tag' ) . ' t ON t.id = e.tag_id'
            . " WHERE e.mannschaft_id = %d AND e.art = 'aufgabe' AND e.erledigt = 0"
            . ' ORDER BY t.datum DESC, e.id DESC', (int) $mannschaft_id ), ARRAY_A );

        return array_map( fn( $z ) => [
            'id'        => (int) $z['id'],
            'datum'     => (string) $z['datum'],
            'datum_de'  => LSV07S_Datum::de( $z['datum'] ),
            'person_id' => (int) $z['schwimmer_id'],
            'person'    => (int) $z['schwimmer_id']
                             ? self::person_name( $sparte, $z['schwimmer_id'] ) : '',
            'kategorie' => (string) $z['kategorie'],
            'text'      => (string) $z['text'],
            'von'       => $z['angelegt_von'] ? LSV07S_Person::konto_name( $z['angelegt_von'] ) : '',
        ], $zeilen );
    }

    // ── Bahnenbelegung ───────────────────────────────────────────────────

    /**
     * Die Bahnenbelegung eines Tages.
     *
     * Gespeichert als JSON in einer Spalte: eine Liste von Bahnen, je
     * Bahn eine Liste von Personen-Nummern und ein freier Hinweis. Das
     * Format ist dasselbe wie im internen Bereich, damit beide Plugins
     * dieselbe Belegung sehen.
     */
    public static function bahnen( $sparte, $mannschaft_id, $datum ) {
        global $wpdb;
        if ( self::moeglich( $sparte ) !== true ) return [];
        $id = self::tag_id( $mannschaft_id, $datum, false );
        if ( ! $id ) return [];
        $roh = (string) $wpdb->get_var( $wpdb->prepare(
            'SELECT bahnen FROM ' . LSV07S_Tabellen::t( 'mb_tag' ) . ' WHERE id = %d', $id ) );
        if ( trim( $roh ) === '' ) return [];
        $daten = json_decode( $roh, true );
        return is_array( $daten ) ? $daten : [];
    }

    /**
     * Die Bahnenbelegung speichern.
     *
     * Geprüft wird jede Person: Wer nicht zur Mannschaft gehört, kommt
     * nicht in die Belegung — sonst stünde dort eine Nummer, zu der
     * später niemand mehr gehört.
     */
    public static function bahnen_speichern( $sparte, $mannschaft_id, $datum, $bahnen ) {
        global $wpdb;
        $erg = self::moeglich( $sparte );
        if ( $erg !== true ) return $erg;
        $erg = self::darf_schreiben( $sparte, $mannschaft_id );
        if ( $erg !== true ) return $erg;
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return 'Dieses Datum gibt es nicht.';

        if ( ! is_array( $bahnen ) ) return 'Die Belegung ist unlesbar.';
        if ( count( $bahnen ) > 20 ) return 'Mehr als 20 Bahnen sind nicht vorgesehen.';

        $kader = array_column( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id, false ), 'id' );
        $sauber = [];
        foreach ( $bahnen as $i => $bahn ) {
            $personen = [];
            foreach ( (array) ( $bahn['personen'] ?? [] ) as $p ) {
                $p = absint( $p );
                if ( $p && in_array( $p, $kader, true ) ) $personen[] = $p;
            }
            $sauber[] = [
                'nr'       => $i + 1,
                'name'     => mb_substr( sanitize_text_field( (string) ( $bahn['name'] ?? '' ) ), 0, 60 ),
                'hinweis'  => mb_substr( sanitize_text_field( (string) ( $bahn['hinweis'] ?? '' ) ), 0, 200 ),
                'personen' => array_values( array_unique( $personen ) ),
            ];
        }

        $id = self::tag_id( $mannschaft_id, $d, true );
        if ( ! $id ) return 'Der Trainingstag konnte nicht angelegt werden.';

        $wpdb->update( LSV07S_Tabellen::t( 'mb_tag' ), [
            'bahnen'       => wp_json_encode( $sauber ),
            'geaendert_am' => LSV07S_Datum::jetzt(),
        ], [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );

        LSV07S_Log::schreibe( 'buch.bahnen', [
            'bereich' => $sparte, 'ziel_typ' => 'tag', 'ziel_id' => $id,
            'details' => $d . ': ' . count( $sauber ) . ' Bahn(en)',
        ] );
        return $sauber;
    }

    /**
     * Die Belegung vom letzten Trainingstag übernehmen.
     *
     * Gesucht wird der letzte Tag DIESER Mannschaft mit einer Belegung,
     * höchstens 60 Tage zurück. Personen, die nicht mehr im Kader stehen,
     * fallen beim Speichern heraus.
     */
    public static function bahnen_uebernehmen( $sparte, $mannschaft_id, $datum ) {
        global $wpdb;
        $erg = self::moeglich( $sparte );
        if ( $erg !== true ) return $erg;
        $erg = self::darf_schreiben( $sparte, $mannschaft_id );
        if ( $erg !== true ) return $erg;
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return 'Dieses Datum gibt es nicht.';

        $frueh = date( 'Y-m-d', strtotime( $d . ' -60 days' ) );
        $zeile = $wpdb->get_row( $wpdb->prepare(
            'SELECT datum, bahnen FROM ' . LSV07S_Tabellen::t( 'mb_tag' )
            . ' WHERE mannschaft_id = %d AND datum < %s AND datum >= %s'
            . " AND bahnen IS NOT NULL AND bahnen <> ''"
            . ' ORDER BY datum DESC LIMIT 1',
            (int) $mannschaft_id, $d, $frueh ), ARRAY_A );
        if ( ! $zeile ) {
            return 'In den letzten 60 Tagen gibt es keine Belegung zum Übernehmen.';
        }
        $daten = json_decode( (string) $zeile['bahnen'], true );
        if ( ! is_array( $daten ) || $daten === [] ) {
            return 'Die letzte Belegung ist unlesbar.';
        }
        $erg = self::bahnen_speichern( $sparte, $mannschaft_id, $d, $daten );
        if ( is_string( $erg ) ) return $erg;
        return [ 'bahnen' => $erg, 'vom' => (string) $zeile['datum'] ];
    }

    // ── Springer ─────────────────────────────────────────────────────────

    /**
     * Die Springer eines Trainings.
     *
     * Ein Springer ist ein Trainer, der an diesem Tag für eine Mannschaft
     * einspringt, die nicht seine ist. Eingetragen wird je Slot und Datum
     * — nicht je Mannschaft: An einem Tag kann dieselbe Mannschaft zwei
     * Trainings haben.
     */
    public static function springer( $sparte, $mannschaft_id, $datum ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'springer' ) ) return [];
        if ( self::darf_lesen( $sparte, $mannschaft_id ) !== true ) return [];
        $slot = LSV07S_Anwesenheit::slot_fuer( $sparte, $mannschaft_id, $datum );
        if ( ! $slot ) return [];

        $t_tr = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT s.*, t.name FROM ' . LSV07S_Tabellen::t( 'springer' ) . ' s'
            . " LEFT JOIN $t_tr t ON t.id = s.trainer_id"
            . ' WHERE s.slot_id = %d AND s.training_datum = %s ORDER BY t.name ASC',
            (int) $slot['id'], LSV07S_Datum::rein( $datum ) ), ARRAY_A );

        return array_map( fn( $z ) => [
            'id'         => (int) $z['id'],
            'trainer_id' => (int) $z['trainer_id'],
            'name'       => (string) ( $z['name'] ?? 'Nr. ' . $z['trainer_id'] ),
            'am'         => LSV07S_Datum::de_zeit( $z['eingetragen_am'] ),
        ], $zeilen );
    }

    /** Einen Springer eintragen. */
    public static function springer_eintragen( $sparte, $mannschaft_id, $datum, $trainer_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'springer' ) ) return 'Die Tabelle fehlt.';
        /* Das Leserecht auf die Springerliste öffnet das Eintragen NICHT —
           wer sehen darf, wer einspringt, soll nicht jemanden einteilen
           können. */
        if ( ! LSV07S_Rechte::darf_eines( [
                LSV07S_Sparte::recht( $sparte, 'springer.eintragen' ),
                LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT ] ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return 'Dieses Datum gibt es nicht.';
        $slot = LSV07S_Anwesenheit::slot_fuer( $sparte, $mannschaft_id, $d );
        if ( ! $slot ) return 'An diesem Tag hat diese Mannschaft kein Training.';

        $trainer_id = (int) $trainer_id;
        $t_tr = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t_tr WHERE id = %d LIMIT 1", $trainer_id ), ARRAY_A );
        if ( ! $trainer ) return 'Diesen Trainer gibt es nicht.';

        /* Wer ohnehin für die Mannschaft zuständig ist, springt nicht ein.
           Sonst zählte sein Training doppelt: einmal als eigenes, einmal
           als Springerdienst. */
        $eigene = LSV07S_Mannschaft::eigene_ids( $sparte, (int) $trainer['wp_user_id'] );
        if ( in_array( (int) $mannschaft_id, $eigene, true ) ) {
            return 'Diese Person ist für diese Mannschaft ohnehin zuständig.';
        }

        $ok = $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . LSV07S_Tabellen::t( 'springer' )
            . ' (slot_id, training_datum, trainer_id, eingetragen_am) VALUES (%d, %s, %d, %s)'
            . ' ON DUPLICATE KEY UPDATE eingetragen_am = VALUES(eingetragen_am)',
            (int) $slot['id'], $d, $trainer_id, LSV07S_Datum::jetzt() ) );
        if ( $ok === false ) return 'Der Springer konnte nicht eingetragen werden.';

        LSV07S_Log::schreibe( 'springer.eingetragen', [
            'bereich' => $sparte, 'ziel_typ' => 'trainer', 'ziel_id' => $trainer_id,
            'ziel_name' => (string) $trainer['name'],
            'details' => $d . ', ' . ( LSV07S_Mannschaft::holen( $sparte, $mannschaft_id )['name'] ?? '' ),
        ] );

        /* Der Springer erfährt es: Im internen Bereich geht eine Mail
           heraus; hier kommt zusätzlich der Protokolleintrag dazu, damit
           später nachvollziehbar ist, wer wen eingetragen hat. */
        return true;
    }

    /** Einen Springer austragen. */
    public static function springer_austragen( $sparte, $id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'springer' ) ) return 'Die Tabelle fehlt.';
        if ( ! LSV07S_Rechte::darf_eines( [
                LSV07S_Sparte::recht( $sparte, 'springer.austragen' ),
                LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT ] ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $id = (int) $id;
        $t  = LSV07S_Tabellen::t( 'springer' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diesen Eintrag gibt es nicht.';

        LSV07S_Log::schreibe( 'springer.ausgetragen', [
            'bereich' => $sparte, 'ziel_typ' => 'trainer',
            'ziel_id' => (int) $zeile['trainer_id'],
            'details' => (string) $zeile['training_datum'],
        ] );
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return true;
    }

    // ── Trainer des Tages ────────────────────────────────────────────────

    /**
     * Welche Trainer waren an diesem Tag da?
     *
     * Dasselbe Modell wie bei den Sportlern, nur in der Trainerspalte der
     * älteren Tabelle (teilnehmer_typ = 'trainer'). Daran hängt später die
     * Abrechnung: Wer als anwesend vermerkt ist, bekommt die Stunden des
     * Slots angerechnet.
     */
    public static function trainer_tag( $sparte, $mannschaft_id, $datum ) {
        global $wpdb;
        if ( self::darf_lesen( $sparte, $mannschaft_id ) !== true ) return [];
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return [];

        $zustaende = [];
        $t_anw = LSV07S_Sparte::feld( $sparte, 't_anw' );
        $t_ein = LSV07S_Sparte::feld( $sparte, 't_anw_eintrag' );
        $ttyp  = LSV07S_Sparte::feld( $sparte, 'teiln_typ_spalte' );
        if ( LSV07S_Tabellen::da( $t_anw ) && LSV07S_Tabellen::da( $t_ein ) && $ttyp !== '' ) {
            $anw = LSV07S_Tabellen::t( $t_anw );
            $ein = LSV07S_Tabellen::t( $t_ein );
            $gsp = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
            $tsp = LSV07S_Sparte::feld( $sparte, 'teiln_spalte' );
            $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT e.$tsp AS tid, e.status FROM $ein e
                   INNER JOIN $anw a ON a.id = e.anwesenheit_id
                  WHERE a.$gsp = %d AND a.training_datum = %s AND e.$ttyp = 'trainer'",
                (int) $mannschaft_id, $d ), ARRAY_A );
            foreach ( $zeilen as $z ) $zustaende[ (int) $z['tid'] ] = (string) $z['status'];
        }

        $raus = [];
        foreach ( LSV07S_Stamm::trainer_liste( $sparte ) as $t ) {
            $zustaendig = in_array( (int) $mannschaft_id, $t['mannschaften'], true );
            $springer   = false;
            foreach ( self::springer( $sparte, $mannschaft_id, $d ) as $s ) {
                if ( $s['trainer_id'] === $t['id'] ) $springer = true;
            }
            if ( ! $zustaendig && ! $springer && ! isset( $zustaende[ $t['id'] ] ) ) continue;
            $raus[] = [
                'id'        => $t['id'],
                'name'      => $t['name'],
                'war_da'    => ( $zustaende[ $t['id'] ] ?? '' ) === 'anwesend',
                'erfasst'   => isset( $zustaende[ $t['id'] ] ),
                'springer'  => $springer,
                'zustaendig'=> $zustaendig,
            ];
        }
        return $raus;
    }

    /** Einen Trainer für diesen Tag als anwesend oder abwesend vermerken. */
    public static function trainer_setzen( $sparte, $mannschaft_id, $datum, $trainer_id, $da = true ) {
        global $wpdb;
        $erg = self::darf_schreiben( $sparte, $mannschaft_id );
        if ( $erg !== true ) return $erg;
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return 'Dieses Datum gibt es nicht.';
        if ( $d > LSV07S_Datum::heute() ) return 'Dieser Tag liegt in der Zukunft.';

        $ttyp = LSV07S_Sparte::feld( $sparte, 'teiln_typ_spalte' );
        if ( $ttyp === '' ) {
            return 'In dieser Sparte werden Trainer nicht je Training erfasst.';
        }
        $trainer_id = (int) $trainer_id;
        $t_tr = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t_tr WHERE id = %d LIMIT 1", $trainer_id ), ARRAY_A );
        if ( ! $trainer ) return 'Diesen Trainer gibt es nicht.';

        $slot = LSV07S_Anwesenheit::slot_fuer( $sparte, $mannschaft_id, $d );
        if ( ! $slot ) return 'An diesem Tag hat diese Mannschaft kein Training.';

        $anw = LSV07S_Tabellen::t( LSV07S_Sparte::feld( $sparte, 't_anw' ) );
        $ein = LSV07S_Tabellen::t( LSV07S_Sparte::feld( $sparte, 't_anw_eintrag' ) );
        $gsp = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        $tsp = LSV07S_Sparte::feld( $sparte, 'teiln_spalte' );

        $sitzung = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $anw WHERE $gsp = %d AND training_datum = %s LIMIT 1",
            (int) $mannschaft_id, $d ) );
        if ( ! $sitzung ) {
            $daten = [ $gsp => (int) $mannschaft_id, 'training_datum' => $d,
                       'erstellt_von' => get_current_user_id() ];
            $formate = [ '%d', '%s', '%d' ];
            if ( LSV07S_Mannschaft::spalte_da( $anw, 'slot_id' ) ) {
                $daten['slot_id'] = (int) $slot['id'];
                $formate[] = '%d';
            }
            $wpdb->insert( $anw, $daten, $formate );
            $sitzung = (int) $wpdb->insert_id;
        }
        if ( ! $sitzung ) return 'Das Training konnte nicht angelegt werden.';

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $ein (anwesenheit_id, $tsp, status, $ttyp) VALUES (%d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            $sitzung, $trainer_id, $da ? 'anwesend' : 'abwesend', 'trainer' ) );

        LSV07S_Log::schreibe( 'buch.trainer', [
            'bereich' => $sparte, 'ziel_typ' => 'trainer', 'ziel_id' => $trainer_id,
            'ziel_name' => (string) $trainer['name'],
            'details' => $d . ': ' . ( $da ? 'anwesend' : 'abwesend' )
                       . ', ' . str_replace( '.', ',', (string) $slot['stunden'] ) . ' Stunden',
        ] );
        return true;
    }
}
