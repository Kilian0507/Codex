<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Anfragen: Fragen, Ideen, Fehler, Organisatorisches.
 *
 * Jede Anfrage hat eine Nummer, eine Kategorie, eine Dringlichkeit und
 * einen Stand; darunter stehen Kommentare, und was am Stand oder an der
 * Zuständigkeit geändert wird, steht als Ereignis dazwischen.
 *
 * Was hier anders ist als im internen Bereich:
 *
 * 1. **Eine Anfrage lässt sich ändern.** Dort gibt es anlegen,
 *    kommentieren, Stand setzen und löschen — aber Titel und
 *    Beschreibung sind nach dem Absenden fest. Ein Tippfehler im Titel
 *    bleibt für immer stehen, und wer etwas vergessen hat, schreibt
 *    einen Kommentar, den später niemand mitliest.
 * 2. **Der Verlauf steht vollständig da.** Dort wird eine
 *    Standänderung als Systemzeile geschrieben, eine Änderung an Titel
 *    oder Beschreibung aber nicht — hier beides.
 * 3. **Wer eine Anfrage stellt, kann sie zurückziehen**, solange
 *    niemand sie bearbeitet hat. Dort kann nur löschen, wer alles
 *    sehen darf; der Fragende bleibt mit seiner Frage stehen.
 * 4. **Jede Änderung steht im Protokoll** — dort keine.
 */
class LSV07S_Anfragen {

    const KATEGORIEN = [
        'frage'          => 'Frage / Hilfe',
        'verbesserung'   => 'Verbesserungsvorschlag / Idee',
        'bug'            => 'Fehler im Plugin',
        'organisation'   => 'Organisatorisches',
        'schwimmer'      => 'Schwimmer hinzufügen oder verschieben',
        'datenaenderung' => 'Daten ändern',
    ];

    const DRINGLICHKEIT = [
        'niedrig'  => 'Niedrig',
        'normal'   => 'Normal',
        'hoch'     => 'Hoch',
        'dringend' => 'Dringend',
    ];

    const STAENDE = [
        'offen'       => 'Offen',
        'in_arbeit'   => 'In Arbeit',
        'wartet'      => 'Wartet auf Rückmeldung',
        'erledigt'    => 'Erledigt',
        'geschlossen' => 'Geschlossen',
    ];

    /** Stände, in denen eine Anfrage als abgeschlossen gilt. */
    const FERTIG = [ 'erledigt', 'geschlossen' ];

    // ── Schalter ─────────────────────────────────────────────────────────

    /** Wer alle Anfragen sieht, bearbeitet sie auch. */
    private static function sieht_alle() {
        return LSV07S_Schalter::an( 'reden/anfragen_alle' );
    }

    /**
     * Eine Anfrage stellen darf jeder mit Zugang.
     *
     * Das ist Absicht: Wer nicht fragen kann, schreibt eine Mail, und
     * die steht nirgends. Gelesen wird sie nur von der eigenen Person
     * und von denen, die alle sehen.
     */
    private static function darf_stellen() {
        return LSV07S_Schalter::zugang();
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** Die Anfragen, die dieses Konto sehen darf. */
    public static function liste( $stand = '' ) {
        global $wpdb;
        if ( ! self::darf_stellen() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! LSV07S_Tabellen::da( 'tickets' ) ) return [];
        $uid = (int) get_current_user_id();
        $t   = LSV07S_Tabellen::t( 'tickets' );

        $wo = [ '1=1' ];
        $werte = [];
        if ( ! self::sieht_alle() ) {
            /* Nicht nur die eigenen: Auch eine Anfrage, die mir
               zugewiesen ist, gehört mir — sonst sieht der Zuständige
               sie nicht, wenn er nicht alles sehen darf. */
            $wo[] = '( ersteller_user_id = %d OR zugewiesen_an = %d )';
            $werte[] = $uid;
            $werte[] = $uid;
        }
        $stand = sanitize_key( $stand );
        if ( $stand === 'offen_alle' ) {
            $wo[] = "status NOT IN ('erledigt','geschlossen')";
        } elseif ( isset( self::STAENDE[ $stand ] ) ) {
            $wo[] = 'status = %s';
            $werte[] = $stand;
        }

        $sql = "SELECT * FROM $t WHERE " . implode( ' AND ', $wo )
             . ' ORDER BY aktualisiert_am DESC, id DESC LIMIT 200';
        $zeilen = (array) $wpdb->get_results(
            $werte ? $wpdb->prepare( $sql, ...$werte ) : $sql, ARRAY_A );

        $raus = [];
        foreach ( $zeilen as $z ) {
            $raus[] = self::zeile( $z, $uid );
        }
        return [
            'anfragen' => $raus,
            'kategorien' => self::KATEGORIEN,
            'dringlichkeit' => self::DRINGLICHKEIT,
            'staende' => self::STAENDE,
            'sieht_alle' => self::sieht_alle(),
            'offen' => self::offene_zahl(),
        ];
    }

    private static function zeile( $z, $uid ) {
        global $wpdb;
        return [
            'id'        => (int) $z['id'],
            'nummer'    => (string) $z['nummer'],
            'titel'     => (string) $z['titel'],
            'kategorie' => (string) $z['kategorie'],
            'kategorie_name' => self::KATEGORIEN[ $z['kategorie'] ] ?? (string) $z['kategorie'],
            'dringend'  => (string) $z['prioritaet'],
            'dringend_name' => self::DRINGLICHKEIT[ $z['prioritaet'] ] ?? (string) $z['prioritaet'],
            'stand'     => (string) $z['status'],
            'stand_name'=> self::STAENDE[ $z['status'] ] ?? (string) $z['status'],
            'fertig'    => in_array( (string) $z['status'], self::FERTIG, true ),
            'von'       => LSV07S_Person::konto_name( $z['ersteller_user_id'] ),
            'von_id'    => (int) $z['ersteller_user_id'],
            'eigene'    => (int) $z['ersteller_user_id'] === (int) $uid,
            'zustaendig'=> $z['zugewiesen_an']
                             ? LSV07S_Person::konto_name( $z['zugewiesen_an'] ) : '',
            'zustaendig_id' => (int) ( $z['zugewiesen_an'] ?? 0 ),
            'am'        => LSV07S_Datum::de_zeit( $z['erstellt_am'] ),
            'geaendert' => LSV07S_Datum::de_zeit( $z['aktualisiert_am'] ?? null, '' ),
            'kommentare'=> (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'ticket_komm' )
                . ' WHERE ticket_id = %d AND ist_system_event = 0', (int) $z['id'] ) ),
        ];
    }

    /** Wie viele offene Anfragen gibt es für dieses Konto? */
    public static function offene_zahl( $uid = 0 ) {
        global $wpdb;
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid || ! LSV07S_Tabellen::da( 'tickets' ) ) return 0;
        $t = LSV07S_Tabellen::t( 'tickets' );
        $fertig = "'" . implode( "','", self::FERTIG ) . "'";
        if ( LSV07S_Schalter::an( 'reden/anfragen_alle', $uid ) ) {
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $t WHERE status NOT IN ($fertig)" );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE status NOT IN ($fertig)"
            . ' AND ( ersteller_user_id = %d OR zugewiesen_an = %d )', $uid, $uid ) );
    }

    /** Eine Anfrage mit ihrem Verlauf. */
    public static function holen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_stellen() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $t = LSV07S_Tabellen::t( 'tickets' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $z ) return 'Diese Anfrage gibt es nicht.';

        $uid = (int) get_current_user_id();
        if ( ! self::sieht_alle() && (int) $z['ersteller_user_id'] !== $uid
             && (int) ( $z['zugewiesen_an'] ?? 0 ) !== $uid ) {
            return 'Diese Anfrage gehört nicht zu Ihnen.';
        }

        $komm = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'ticket_komm' )
            . ' WHERE ticket_id = %d ORDER BY erstellt_am ASC, id ASC', $id ), ARRAY_A );

        $anfrage = self::zeile( $z, $uid );
        $anfrage['beschreibung'] = (string) ( $z['beschreibung'] ?? '' );
        $anfrage['verlauf'] = array_map( fn( $k ) => [
            'id'     => (int) $k['id'],
            'von'    => LSV07S_Person::konto_name( $k['autor_user_id'] ),
            'eigene' => (int) $k['autor_user_id'] === $uid,
            'system' => (int) $k['ist_system_event'] === 1,
            'text'   => (string) ( $k['text'] ?? '' ),
            'am'     => LSV07S_Datum::de_zeit( $k['erstellt_am'] ),
        ], $komm );
        $anfrage['darf_bearbeiten'] = self::sieht_alle();
        /* Ändern darf, wer sie gestellt hat — solange niemand sie
           bearbeitet hat. Danach steht sie nicht mehr allein. */
        $anfrage['darf_aendern'] = self::sieht_alle()
            || ( (int) $z['ersteller_user_id'] === $uid
                 && (string) $z['status'] === 'offen' );
        $anfrage['darf_zurueckziehen'] = $anfrage['darf_aendern'];
        return $anfrage;
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /** Eine Anfrage stellen. */
    public static function stellen( $titel, $beschreibung, $kategorie, $dringend ) {
        global $wpdb;
        if ( ! self::darf_stellen() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! LSV07S_Tabellen::da( 'tickets' ) ) return 'Die Tabelle fehlt.';

        $titel = mb_substr( trim( sanitize_text_field( (string) $titel ) ), 0, 255 );
        if ( $titel === '' ) return 'Bitte einen Titel angeben.';
        if ( mb_strlen( $titel ) < 8 ) {
            return 'Bitte einen Titel mit mindestens acht Zeichen — „Hilfe" allein '
                 . 'sagt niemandem, worum es geht, und eine Anfrage ohne Gegenstand '
                 . 'bleibt liegen.';
        }
        $beschreibung = wp_kses( trim( (string) $beschreibung ), [] );
        if ( mb_strlen( $beschreibung ) > 20000 ) return 'Die Beschreibung ist zu lang.';
        $kategorie = sanitize_key( $kategorie );
        if ( ! isset( self::KATEGORIEN[ $kategorie ] ) ) $kategorie = 'frage';
        $dringend = sanitize_key( $dringend );
        if ( ! isset( self::DRINGLICHKEIT[ $dringend ] ) ) $dringend = 'normal';

        $t = LSV07S_Tabellen::t( 'tickets' );
        $wpdb->insert( $t, [
            'nummer' => '',
            'titel' => $titel,
            'beschreibung' => $beschreibung,
            'kategorie' => $kategorie,
            'prioritaet' => $dringend,
            'status' => 'offen',
            'ersteller_user_id' => (int) get_current_user_id(),
            'erstellt_am' => LSV07S_Datum::jetzt(),
            'aktualisiert_am' => LSV07S_Datum::jetzt(),
        ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 'Die Anfrage konnte nicht gespeichert werden.';

        /* Die Nummer entsteht aus der Nummer der Zeile: Sie ist eindeutig,
           ohne eine zweite Zählung zu führen. Im internen Bereich wird
           dafür das Maximum gelesen und um eins erhöht — zwei Anfragen
           im selben Augenblick bekommen dort dieselbe Nummer. */
        $nummer = 'A-' . date( 'Y', strtotime( LSV07S_Datum::heute() ) ) . '-'
                . str_pad( (string) $id, 4, '0', STR_PAD_LEFT );
        $wpdb->update( $t, [ 'nummer' => $nummer ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );

        LSV07S_Log::schreibe( 'anfrage.gestellt', [
            'bereich' => 'reden', 'ziel_typ' => 'anfrage', 'ziel_id' => $id,
            'ziel_name' => $titel,
            'details' => self::KATEGORIEN[ $kategorie ] . ', '
                       . self::DRINGLICHKEIT[ $dringend ],
        ] );
        return [ 'id' => $id, 'nummer' => $nummer, 'message' => 'Die Anfrage ist gestellt.' ];
    }

    /** Titel, Beschreibung, Kategorie und Dringlichkeit ändern. */
    public static function aendern( $id, $titel, $beschreibung, $kategorie, $dringend ) {
        global $wpdb;
        $id = (int) $id;
        $t = LSV07S_Tabellen::t( 'tickets' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $z ) return 'Diese Anfrage gibt es nicht.';

        $uid = (int) get_current_user_id();
        $eigene = (int) $z['ersteller_user_id'] === $uid;
        if ( ! self::sieht_alle() && ! ( $eigene && (string) $z['status'] === 'offen' ) ) {
            return 'Diese Anfrage lässt sich nicht mehr ändern. Schreiben Sie einen '
                 . 'Kommentar — dann steht beides da.';
        }

        $titel = mb_substr( trim( sanitize_text_field( (string) $titel ) ), 0, 255 );
        if ( mb_strlen( $titel ) < 8 ) return 'Bitte einen Titel mit mindestens acht Zeichen.';
        $beschreibung = wp_kses( trim( (string) $beschreibung ), [] );
        if ( mb_strlen( $beschreibung ) > 20000 ) return 'Die Beschreibung ist zu lang.';
        $kategorie = sanitize_key( $kategorie );
        if ( ! isset( self::KATEGORIEN[ $kategorie ] ) ) $kategorie = (string) $z['kategorie'];
        $dringend = sanitize_key( $dringend );
        if ( ! isset( self::DRINGLICHKEIT[ $dringend ] ) ) {
            $dringend = (string) $z['prioritaet'];
        }

        $wpdb->update( $t, [
            'titel' => $titel, 'beschreibung' => $beschreibung,
            'kategorie' => $kategorie, 'prioritaet' => $dringend,
            'aktualisiert_am' => LSV07S_Datum::jetzt(),
        ], [ 'id' => $id ], [ '%s', '%s', '%s', '%s', '%s' ], [ '%d' ] );

        /* Was geändert wurde, steht im Verlauf — sonst wundert sich
           später jemand, warum der Titel nicht zum ersten Kommentar
           passt. */
        $was = [];
        if ( (string) $z['titel'] !== $titel ) $was[] = 'Titel';
        if ( (string) ( $z['beschreibung'] ?? '' ) !== $beschreibung ) $was[] = 'Beschreibung';
        if ( (string) $z['kategorie'] !== $kategorie ) {
            $was[] = 'Kategorie: ' . self::KATEGORIEN[ $kategorie ];
        }
        if ( (string) $z['prioritaet'] !== $dringend ) {
            $was[] = 'Dringlichkeit: ' . self::DRINGLICHKEIT[ $dringend ];
        }
        if ( $was ) {
            self::ereignis( $id, 'geändert — ' . implode( ', ', $was ) );
            LSV07S_Log::schreibe( 'anfrage.geaendert', [
                'bereich' => 'reden', 'ziel_typ' => 'anfrage', 'ziel_id' => $id,
                'ziel_name' => $titel, 'details' => implode( ', ', $was ),
            ] );
        }
        return [ 'message' => $was ? 'Geändert.' : 'Nichts geändert.' ];
    }

    /** Einen Kommentar schreiben. */
    public static function kommentieren( $id, $text ) {
        global $wpdb;
        $id = (int) $id;
        $anfrage = self::holen( $id );
        if ( is_string( $anfrage ) ) return $anfrage;

        $text = wp_kses( trim( (string) $text ), [] );
        if ( $text === '' ) return 'Bitte etwas schreiben.';
        if ( mb_strlen( $text ) > 20000 ) return 'Der Kommentar ist zu lang.';

        $wpdb->insert( LSV07S_Tabellen::t( 'ticket_komm' ), [
            'ticket_id' => $id,
            'autor_user_id' => (int) get_current_user_id(),
            'text' => $text,
            'ist_system_event' => 0,
            'erstellt_am' => LSV07S_Datum::jetzt(),
        ], [ '%d', '%d', '%s', '%d', '%s' ] );

        $wpdb->update( LSV07S_Tabellen::t( 'tickets' ),
            [ 'aktualisiert_am' => LSV07S_Datum::jetzt() ],
            [ 'id' => $id ], [ '%s' ], [ '%d' ] );

        /* Eine Rückmeldung auf „wartet auf Rückmeldung" bringt die
           Anfrage zurück in Arbeit: Sonst wartet sie weiter, obwohl die
           Antwort längst da ist. */
        if ( (string) $anfrage['stand'] === 'wartet'
             && (int) $anfrage['von_id'] === (int) get_current_user_id() ) {
            $wpdb->update( LSV07S_Tabellen::t( 'tickets' ),
                [ 'status' => 'in_arbeit' ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
            self::ereignis( $id, 'Rückmeldung eingegangen — wieder in Arbeit' );
        }

        LSV07S_Log::schreibe( 'anfrage.kommentiert', [
            'bereich' => 'reden', 'ziel_typ' => 'anfrage', 'ziel_id' => $id,
            'ziel_name' => (string) $anfrage['titel'],
        ] );
        return [ 'message' => 'Gespeichert.' ];
    }

    /** Den Stand setzen. */
    public static function stand_setzen( $id, $stand ) {
        global $wpdb;
        $id = (int) $id;
        $stand = sanitize_key( $stand );
        if ( ! self::sieht_alle() ) {
            return 'Den Stand einer Anfrage setzt, wer die Anfragen bearbeitet.';
        }
        if ( ! isset( self::STAENDE[ $stand ] ) ) return 'Diesen Stand gibt es nicht.';
        $t = LSV07S_Tabellen::t( 'tickets' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $z ) return 'Diese Anfrage gibt es nicht.';
        if ( (string) $z['status'] === $stand ) {
            return [ 'message' => 'Dieser Stand ist schon gesetzt.' ];
        }

        $daten = [ 'status' => $stand, 'aktualisiert_am' => LSV07S_Datum::jetzt() ];
        $form  = [ '%s', '%s' ];
        $daten['geschlossen_am'] = in_array( $stand, self::FERTIG, true )
            ? LSV07S_Datum::jetzt() : null;
        $form[] = '%s';
        $wpdb->update( $t, $daten, [ 'id' => $id ], $form, [ '%d' ] );

        self::ereignis( $id, 'Stand: ' . self::STAENDE[ $z['status'] ]
            . ' → ' . self::STAENDE[ $stand ] );
        LSV07S_Log::schreibe( 'anfrage.stand', [
            'bereich' => 'reden', 'ziel_typ' => 'anfrage', 'ziel_id' => $id,
            'ziel_name' => (string) $z['titel'],
            'details' => (string) $z['status'] . ' → ' . $stand,
        ] );
        return [ 'message' => 'Stand: ' . self::STAENDE[ $stand ] . '.' ];
    }

    /** Zuständig machen. */
    public static function zustaendig( $id, $uid_neu ) {
        global $wpdb;
        $id = (int) $id;
        $uid_neu = (int) $uid_neu;
        if ( ! self::sieht_alle() ) {
            return 'Die Zuständigkeit setzt, wer die Anfragen bearbeitet.';
        }
        $t = LSV07S_Tabellen::t( 'tickets' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $z ) return 'Diese Anfrage gibt es nicht.';
        if ( $uid_neu && ! LSV07S_Schalter::zugang( $uid_neu ) ) {
            return 'Dieses Konto hat keinen Zugang und kann nicht zuständig sein.';
        }

        $wpdb->update( $t, [ 'zugewiesen_an' => $uid_neu ?: null,
                             'aktualisiert_am' => LSV07S_Datum::jetzt() ],
            [ 'id' => $id ], [ '%d', '%s' ], [ '%d' ] );

        self::ereignis( $id, $uid_neu
            ? 'Zuständig: ' . LSV07S_Person::konto_name( $uid_neu )
            : 'Zuständigkeit aufgehoben' );
        LSV07S_Log::schreibe( 'anfrage.zustaendig', [
            'bereich' => 'reden', 'ziel_typ' => 'anfrage', 'ziel_id' => $id,
            'ziel_name' => (string) $z['titel'],
            'details' => $uid_neu ? LSV07S_Person::konto_name( $uid_neu ) : 'niemand',
        ] );
        return [ 'message' => 'Gespeichert.' ];
    }

    /**
     * Eine Anfrage zurückziehen oder löschen.
     *
     * Wer sie gestellt hat, darf sie zurückziehen, solange sie offen
     * ist. Wer die Anfragen bearbeitet, darf immer löschen — mit allem,
     * was darunter steht.
     */
    public static function loeschen( $id ) {
        global $wpdb;
        $id = (int) $id;
        $t = LSV07S_Tabellen::t( 'tickets' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $z ) return 'Diese Anfrage gibt es nicht.';

        $uid = (int) get_current_user_id();
        $eigene = (int) $z['ersteller_user_id'] === $uid;
        if ( ! self::sieht_alle() && ! ( $eigene && (string) $z['status'] === 'offen' ) ) {
            return 'Eine Anfrage, an der schon gearbeitet wird, lässt sich nicht '
                 . 'zurückziehen. Schreiben Sie einen Kommentar.';
        }

        $zahl = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'ticket_komm' )
            . ' WHERE ticket_id = %d', $id ) );
        $wpdb->delete( LSV07S_Tabellen::t( 'ticket_komm' ), [ 'ticket_id' => $id ], [ '%d' ] );
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );

        LSV07S_Log::schreibe( $eigene ? 'anfrage.zurueckgezogen' : 'anfrage.geloescht', [
            'bereich' => 'reden', 'ziel_typ' => 'anfrage', 'ziel_id' => $id,
            'ziel_name' => (string) $z['titel'],
            'details' => $zahl . ' Eintrag/Einträge im Verlauf',
        ] );
        return [ 'message' => $eigene ? 'Zurückgezogen.' : 'Gelöscht.' ];
    }

    /** Eine Zeile im Verlauf, die das System schreibt. */
    private static function ereignis( $id, $text ) {
        global $wpdb;
        $wpdb->insert( LSV07S_Tabellen::t( 'ticket_komm' ), [
            'ticket_id' => (int) $id,
            'autor_user_id' => (int) get_current_user_id(),
            'text' => mb_substr( sanitize_text_field( (string) $text ), 0, 500 ),
            'ist_system_event' => 1,
            'erstellt_am' => LSV07S_Datum::jetzt(),
        ], [ '%d', '%d', '%s', '%d', '%s' ] );
    }
}
