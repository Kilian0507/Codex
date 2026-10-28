<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stammdaten pflegen: Mannschaften, Sportler, Trainer, Trainingszeiten,
 * Saisons — eine Fassung für alle drei Sparten.
 *
 * Im internen Bereich liegt das dreifach (Schwimmen eigen, Triathlon und
 * Fitness als Kopien voneinander) und zusätzlich verstreut: Einen
 * Schwimmer kann man an SECHS Stellen anlegen oder ändern — Admin →
 * Sportler, Trainer → Sportler, Admin → Personen, Sportler-Import,
 * Personen-Import und im Profil. Entsprechend schreibt jede Stelle etwas
 * anderes, und eine davon vergisst das Protokoll.
 *
 * Hier gibt es je Sache eine Funktion. Jede prüft das Recht, jede schreibt
 * ins Protokoll, und jede gibt entweder `true`/eine Nummer zurück oder den
 * Satz, der dem Nutzer gesagt wird.
 *
 * Grundsatz beim Löschen: Was Spuren hat, wird nicht gelöscht, sondern
 * stillgelegt. Ein gelöschter Schwimmer mit Anwesenheitszeilen hinterlässt
 * sonst Einträge, die auf niemanden mehr zeigen — und die Statistik eines
 * ganzen Jahres wird still falsch.
 */
class LSV07S_Stamm {

    /**
     * Eine eingegebene Adresse prüfen, BEVOR sie gesäubert wird.
     *
     * `sanitize_email()` macht aus Unsinn eine leere Zeichenkette. Eine
     * Prüfung danach kann deshalb nie zuschlagen: Wer „keine-adresse"
     * eintippt, bekommt keinen Hinweis — das Feld ist nur still leer, und
     * die Erinnerungsmail geht später an niemanden. Also erst fragen,
     * dann säubern.
     *
     * @return array [ Adresse, Fehlertext|null ]
     */
    private static function adresse( $roh ) {
        $roh = trim( (string) $roh );
        if ( $roh === '' ) return [ '', null ];
        if ( ! is_email( $roh ) ) {
            return [ '', 'Die Adresse „' . $roh . '" ist keine E-Mail-Adresse.' ];
        }
        return [ sanitize_email( $roh ), null ];
    }

    // ── Mannschaften und Gruppen ─────────────────────────────────────────

    /**
     * Mannschaft anlegen oder umbenennen.
     *
     * @return int|string Die Nummer, oder der Satz für den Nutzer.
     */
    public static function mannschaft_speichern( $sparte, $id, $name, $sortierung = 0 ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::da( $sparte ) ) return 'Diese Sparte ist nicht eingerichtet.';
        $id   = (int) $id;
        $name = trim( sanitize_text_field( (string) $name ) );
        if ( $name === '' ) return 'Bitte einen Namen angeben.';
        if ( mb_strlen( $name ) > 100 ) return 'Der Name ist zu lang (höchstens 100 Zeichen).';

        $recht = LSV07S_Sparte::recht_gruppe( $sparte, $id ? 'update' : 'create' );
        if ( ! LSV07S_Rechte::darf( $recht ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }

        $t = LSV07S_Sparte::tabelle( $sparte, 't_gruppen' );

        /* Der Name ist eindeutig (UNIQUE KEY uq_name). Ohne diese Abfrage
           käme ein Datenbankfehler statt eines Satzes, den man versteht. */
        $belegt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE name = %s AND id <> %d LIMIT 1", $name, $id ) );
        if ( $belegt ) return 'Diesen Namen gibt es schon.';

        if ( $id ) {
            $alt = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $t WHERE id = %d LIMIT 1", $id ), ARRAY_A );
            if ( ! $alt ) return 'Diese Mannschaft gibt es nicht.';
            $wpdb->update( $t, [ 'name' => $name, 'sort_order' => (int) $sortierung ],
                [ 'id' => $id ], [ '%s', '%d' ], [ '%d' ] );
            LSV07S_Log::schreibe( 'mannschaft.geaendert', [
                'bereich' => $sparte, 'ziel_typ' => 'mannschaft', 'ziel_id' => $id,
                'ziel_name' => $name,
                'details' => $alt['name'] !== $name ? 'vorher: ' . $alt['name'] : 'Reihenfolge',
            ] );
            return $id;
        }

        $ok = $wpdb->insert( $t, [ 'name' => $name, 'sort_order' => (int) $sortierung ],
            [ '%s', '%d' ] );
        if ( ! $ok ) return 'Die Mannschaft konnte nicht angelegt werden.';
        $neu = (int) $wpdb->insert_id;
        LSV07S_Log::schreibe( 'mannschaft.angelegt', [
            'bereich' => $sparte, 'ziel_typ' => 'mannschaft', 'ziel_id' => $neu,
            'ziel_name' => $name,
        ] );
        return $neu;
    }

    /**
     * Mannschaft löschen — nur, wenn nichts daran hängt.
     *
     * Der interne Bereich löscht die Gruppe und ihre Zuordnungen und lässt
     * Anwesenheitssitzungen, Bestzeiten und Meldungen auf eine Nummer
     * zeigen, die es nicht mehr gibt.
     */
    public static function mannschaft_loeschen( $sparte, $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht_gruppe( $sparte, 'delete' ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $mann = LSV07S_Mannschaft::holen( $sparte, $id );
        if ( ! $mann ) return 'Diese Mannschaft gibt es nicht.';

        $kader = LSV07S_Mannschaft::sportler( $sparte, $id, false );
        if ( $kader !== [] ) {
            return 'In dieser Mannschaft stehen noch ' . count( $kader )
                 . ' Personen. Bitte zuerst umziehen oder stilllegen.';
        }

        $t_anw = LSV07S_Sparte::tabelle( $sparte, 't_anw' );
        $gsp   = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        $sitzungen = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t_anw WHERE $gsp = %d", $id ) );
        if ( $sitzungen > 0 ) {
            return 'Zu dieser Mannschaft gibt es ' . $sitzungen . ' erfasste Trainings. '
                 . 'Sie bleibt deshalb bestehen — benennen Sie sie um, wenn sie nicht '
                 . 'mehr gebraucht wird.';
        }

        /* Erst protokollieren, dann löschen: danach ist nicht mehr zu
           ermitteln, was dort stand. */
        LSV07S_Log::schreibe( 'mannschaft.geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'mannschaft', 'ziel_id' => $id,
            'ziel_name' => $mann['name'],
        ] );

        $t_slots = LSV07S_Sparte::tabelle( $sparte, 't_slots' );
        $wpdb->delete( $t_slots, [ $gsp => $id ], [ '%d' ] );
        $t_tg = LSV07S_Sparte::tabelle( $sparte, 't_tr_gruppe' );
        $wpdb->delete( $t_tg, [ LSV07S_Sparte::feld( $sparte, 'tr_gruppe_gr' ) => $id ], [ '%d' ] );
        $wpdb->delete( LSV07S_Sparte::tabelle( $sparte, 't_gruppen' ), [ 'id' => $id ], [ '%d' ] );
        return true;
    }

    // ── Sportler ─────────────────────────────────────────────────────────

    /**
     * Sportler anlegen oder ändern.
     *
     * $daten: vorname, nachname, geburtsdatum, email, dsv_id, notizen,
     *         attest_bis (nur Schwimmen), aktiv, mannschaften (Feld von
     *         Nummern), kontakte (nur Schwimmen).
     */
    public static function sportler_speichern( $sparte, $id, array $daten ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::da( $sparte ) ) return 'Diese Sparte ist nicht eingerichtet.';
        $id = (int) $id;

        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht_sportler( $sparte, $id ? 'update' : 'create' ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }

        $vorname  = trim( sanitize_text_field( (string) ( $daten['vorname'] ?? '' ) ) );
        $nachname = trim( sanitize_text_field( (string) ( $daten['nachname'] ?? '' ) ) );
        if ( $nachname === '' ) return 'Bitte den Nachnamen angeben.';

        $geburt = LSV07S_Datum::rein( $daten['geburtsdatum'] ?? null );
        if ( ! empty( $daten['geburtsdatum'] ) && $geburt === null ) {
            return 'Dieses Geburtsdatum gibt es nicht.';
        }
        if ( $geburt !== null && $geburt > LSV07S_Datum::heute() ) {
            return 'Das Geburtsdatum liegt in der Zukunft.';
        }
        /* Bei Schwimmen ist das Geburtsdatum Pflicht: Die Spalte ist
           NOT NULL, und bei Meldungen zählt der Jahrgang. Ohne diese
           Abfrage käme statt eines Satzes ein Datenbankfehler. */
        if ( $geburt === null && ! $id && LSV07S_Sparte::feld( $sparte, 'geburt_pflicht' ) ) {
            return 'Bitte das Geburtsdatum angeben — daran hängt der Jahrgang für Meldungen.';
        }

        $mannschaften = array_values( array_unique( array_map( 'absint',
            (array) ( $daten['mannschaften'] ?? [] ) ) ) );
        $mannschaften = array_values( array_filter( $mannschaften ) );

        /* Zuordnen darf man nur, wo man selbst arbeiten darf. Sonst könnte
           ein Trainer jemanden in eine fremde Mannschaft schreiben. */
        foreach ( $mannschaften as $m ) {
            if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $m ) ) {
                $name = LSV07S_Mannschaft::holen( $sparte, $m );
                return 'Für die Mannschaft „' . ( $name['name'] ?? $m )
                     . '" fehlt Ihnen die Berechtigung.';
            }
        }

        $t   = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $vn  = LSV07S_Sparte::feld( $sparte, 'vorname' );
        $nn  = LSV07S_Sparte::feld( $sparte, 'nachname' );
        $gb  = LSV07S_Sparte::feld( $sparte, 'geburt' );
        $akt = LSV07S_Sparte::feld( $sparte, 'aktiv' );

        [ $mail, $fehler ] = self::adresse( $daten['email'] ?? '' );
        if ( $fehler !== null ) return $fehler;

        $zeile = [
            $vn  => $vorname,
            $nn  => $nachname,
            'email' => $mail,
            'notes' => sanitize_textarea_field( (string) ( $daten['notizen'] ?? '' ) ),
        ];
        $formate = [ '%s', '%s', '%s', '%s' ];

        /* Das Geburtsdatum nur mitschreiben, wenn eines kommt: Beim Ändern
           ohne Angabe bleibt das gespeicherte stehen, statt geleert zu
           werden — und bei Schwimmen ginge das Leeren gar nicht, weil die
           Spalte NOT NULL ist. */
        if ( $geburt !== null ) {
            $zeile[ $gb ] = $geburt;
            $formate[] = '%s';
        }

        /* Nur setzen, was es in dieser Sparte gibt: Triathlon und Fitness
           haben keine DSV-ID und kein Attest. */
        if ( LSV07S_Sparte::feld( $sparte, 'hat_attest' ) ) {
            $zeile['dsv_id'] = sanitize_text_field( (string) ( $daten['dsv_id'] ?? '' ) );
            $formate[] = '%s';

            /* Das Attest ist ein Gesundheitsdatum (Art. 9 DSGVO) und
               deshalb an ein eigenes Recht geknüpft — wer den Datensatz
               pflegen darf, darf es auch, aber nur, wenn ein Datum
               wirklich mitkommt. Sonst fiele jedes Speichern von Namen
               und Zuordnung über diese Prüfung. */
            if ( array_key_exists( 'attest_bis', $daten ) && $daten['attest_bis'] !== '' ) {
                $attest = LSV07S_Datum::rein( $daten['attest_bis'] );
                if ( $attest === null ) return 'Dieses Attestdatum gibt es nicht.';
                if ( ! LSV07S_Rechte::darf_eines( [
                        LSV07S_Rechte::ATTEST_EDIT,
                        LSV07S_Sparte::recht_sportler( $sparte, $id ? 'update' : 'create' ),
                     ] ) ) {
                    return 'Für das Attest fehlt Ihnen die Berechtigung.';
                }
                $zeile['attest_expires'] = $attest;
                $formate[] = '%s';
            }
        }

        /* „aktiv" nur ändern, wenn es ausdrücklich mitkommt. Der interne
           Bereich setzt es bei JEDEM Speichern auf 1 — eine stillgelegte
           Person wird dort durch eine Namenskorrektur wieder aktiv. */
        if ( array_key_exists( 'aktiv', $daten ) ) {
            $zeile[ $akt ] = ! empty( $daten['aktiv'] ) ? 1 : 0;
            $formate[] = '%d';
        } elseif ( ! $id ) {
            $zeile[ $akt ] = 1;
            $formate[] = '%d';
        }

        if ( $id ) {
            $alt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
                $id ), ARRAY_A );
            if ( ! $alt ) return 'Diese Person gibt es nicht.';
            /* Ändern darf man nur, wen man auch sieht. */
            if ( ! self::sportler_in_reichweite( $sparte, $id ) ) {
                return 'Diese Person gehört nicht zu Ihrem Bereich.';
            }
            $wpdb->update( $t, $zeile, [ 'id' => $id ], $formate, [ '%d' ] );
        } else {
            if ( $mannschaften === [] ) {
                return 'Bitte mindestens eine ' . LSV07S_Sparte::wort( $sparte, 'gruppe_name' )
                     . ' angeben.';
            }
            /* team_id gibt es bei Schwimmen noch als Altlast: Die
               Mehrfachzuordnung steht in der Zuordnungstabelle, aber
               Abfragen des internen Bereichs lesen teilweise noch die
               Spalte. Sie bekommt die erste Mannschaft. */
            if ( $sparte === LSV07S_Sparte::SCHWIMMEN ) {
                $zeile['team_id'] = $mannschaften[0];
                $formate[] = '%d';
            }
            $ok = $wpdb->insert( $t, $zeile, $formate );
            if ( ! $ok ) return 'Die Person konnte nicht angelegt werden.';
            $id = (int) $wpdb->insert_id;
        }

        if ( $mannschaften !== [] ) {
            self::zuordnung_setzen( $sparte, $id, $mannschaften );
        }
        if ( LSV07S_Sparte::feld( $sparte, 'hat_attest' ) && isset( $daten['kontakte'] ) ) {
            $erg = self::kontakte_setzen( $id, (array) $daten['kontakte'] );
            if ( $erg !== true ) return $erg;
        }

        LSV07S_Log::schreibe( 'sportler.gespeichert', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $id,
            'ziel_name' => LSV07S_Person::listen_name( $vorname, $nachname ),
            'details' => $mannschaften !== []
                ? count( $mannschaften ) . ' Zuordnung(en)' : 'ohne Zuordnung',
        ] );
        return $id;
    }

    /** Steht diese Person in einer Mannschaft, in der ich arbeiten darf? */
    public static function sportler_in_reichweite( $sparte, $person_id ) {
        if ( LSV07S_Rechte::sieht_alles( $sparte ) ) return true;
        foreach ( self::zuordnung( $sparte, $person_id ) as $m ) {
            if ( LSV07S_Rechte::darf_mannschaft( $sparte, $m ) ) return true;
        }
        return false;
    }

    /** Die Mannschaftsnummern einer Person. */
    public static function zuordnung( $sparte, $person_id ) {
        global $wpdb;
        $t  = LSV07S_Sparte::tabelle( $sparte, 't_sp_gruppe' );
        $sp = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_sp' );
        $gr = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_gr' );
        return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT $gr FROM $t WHERE $sp = %d", (int) $person_id ) ) );
    }

    /**
     * Die Zuordnung setzen.
     *
     * Mannschaften, in denen die handelnde Person nicht arbeiten darf,
     * bleiben unberührt — sonst nimmt ein Trainer beim Speichern
     * versehentlich die Zuordnung zu einer fremden Mannschaft weg.
     */
    private static function zuordnung_setzen( $sparte, $person_id, array $mannschaften ) {
        global $wpdb;
        $t  = LSV07S_Sparte::tabelle( $sparte, 't_sp_gruppe' );
        $sp = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_sp' );
        $gr = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_gr' );

        $vorher = self::zuordnung( $sparte, $person_id );
        foreach ( $vorher as $m ) {
            if ( in_array( $m, $mannschaften, true ) ) continue;
            if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $m ) ) continue;   // fremd: bleibt
            $wpdb->delete( $t, [ $sp => (int) $person_id, $gr => $m ], [ '%d', '%d' ] );
        }
        foreach ( $mannschaften as $m ) {
            if ( in_array( $m, $vorher, true ) ) continue;
            $wpdb->insert( $t, [ $sp => (int) $person_id, $gr => $m ], [ '%d', '%d' ] );
        }
    }

    /**
     * Kontaktpersonen eines Schwimmers ersetzen.
     *
     * Die Tabelle kennt kein Kennzeichen „Hauptkontakt" — die REIHENFOLGE
     * entscheidet: Was in `sort_order` zuerst steht, ist der Kontakt, den
     * die Erinnerungsmail anschreibt (so liest es der interne Bereich,
     * `class-ajax-attestverwaltung.php:197`). Wer das nicht weiss, ändert
     * die Reihenfolge und wundert sich, dass die Mail an eine andere
     * Person geht; deshalb steht es hier.
     */
    private static function kontakte_setzen( $schwimmer_id, array $kontakte ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'kontakte' ) ) return true;
        $t = LSV07S_Tabellen::t( 'kontakte' );
        $sauber = [];
        foreach ( $kontakte as $k ) {
            $name = trim( sanitize_text_field( (string) ( $k['name'] ?? '' ) ) );
            $tel  = trim( sanitize_text_field( (string) ( $k['telefon'] ?? '' ) ) );
            [ $mail, $fehler ] = self::adresse( $k['email'] ?? '' );
            if ( $fehler !== null ) return $fehler;
            if ( $name === '' && $mail === '' && $tel === '' ) continue;
            $sauber[] = [ 'name' => $name, 'email' => $mail, 'telefon' => $tel,
                          'primaer' => ! empty( $k['primaer'] ) ];
        }

        /* Die als Hauptkontakt angekreuzte Person wandert nach vorne; ist
           keine angekreuzt, bleibt die Reihenfolge der Eingabe. */
        usort( $sauber, fn( $a, $b ) => ( $b['primaer'] ? 1 : 0 ) - ( $a['primaer'] ? 1 : 0 ) );

        $wpdb->delete( $t, [ 'swimmer_id' => (int) $schwimmer_id ], [ '%d' ] );
        foreach ( $sauber as $i => $s ) {
            $wpdb->insert( $t, [
                'swimmer_id' => (int) $schwimmer_id,
                'name'       => $s['name'],
                'telefon'    => $s['telefon'],
                'email'      => $s['email'],
                'sort_order' => $i,
            ], [ '%d', '%s', '%s', '%s', '%d' ] );
        }
        return true;
    }

    /** Die Kontaktpersonen eines Schwimmers; der erste ist der Hauptkontakt. */
    public static function kontakte( $schwimmer_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'kontakte' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'kontakte' )
            . ' WHERE swimmer_id = %d ORDER BY sort_order ASC, id ASC',
            (int) $schwimmer_id ), ARRAY_A );
        $raus = [];
        foreach ( $zeilen as $i => $z ) {
            $raus[] = [
                'id'      => (int) $z['id'],
                'name'    => (string) $z['name'],
                'email'   => (string) $z['email'],
                'telefon' => (string) $z['telefon'],
                'primaer' => $i === 0,
            ];
        }
        return $raus;
    }

    /**
     * Stilllegen oder wieder aufnehmen.
     *
     * Der gewöhnliche Weg, wenn jemand aufhört: Die Daten bleiben für die
     * Auswertung des Jahres erhalten, die Person steht nicht mehr im
     * Kader. Der interne Bereich kennt nur Löschen.
     */
    public static function sportler_stilllegen( $sparte, $id, $aktiv = false ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht_sportler( $sparte, 'update' ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! self::sportler_in_reichweite( $sparte, $id ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }
        $t   = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $akt = LSV07S_Sparte::feld( $sparte, 'aktiv' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Person gibt es nicht.';

        $wpdb->update( $t, [ $akt => $aktiv ? 1 : 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        LSV07S_Log::schreibe( $aktiv ? 'sportler.aufgenommen' : 'sportler.stillgelegt', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $id,
            'ziel_name' => LSV07S_Person::aus_zeile( $zeile, $sparte ),
        ] );
        return true;
    }

    /**
     * Löschen — nur ohne Spuren.
     *
     * Wer Anwesenheit, Bestzeiten oder Meldungen hat, wird stillgelegt und
     * nicht gelöscht. Sonst zeigen diese Zeilen auf eine Nummer, die es
     * nicht mehr gibt, und keine Auswertung stimmt mehr.
     */
    public static function sportler_loeschen( $sparte, $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht_sportler( $sparte, 'delete' ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! self::sportler_in_reichweite( $sparte, $id ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }
        $t = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Person gibt es nicht.';

        $spuren = self::spuren( $sparte, $id );
        if ( $spuren !== [] ) {
            return 'Zu dieser Person gibt es ' . implode( ', ', $spuren )
                 . '. Bitte stilllegen statt löschen — sonst zeigen diese Einträge '
                 . 'auf niemanden mehr.';
        }

        LSV07S_Log::schreibe( 'sportler.geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $id,
            'ziel_name' => LSV07S_Person::aus_zeile( $zeile, $sparte ),
            'details' => 'ohne Spuren',
        ] );

        $t_zu = LSV07S_Sparte::tabelle( $sparte, 't_sp_gruppe' );
        $wpdb->delete( $t_zu, [ LSV07S_Sparte::feld( $sparte, 'sp_gruppe_sp' ) => $id ], [ '%d' ] );
        if ( LSV07S_Sparte::feld( $sparte, 'hat_attest' ) && LSV07S_Tabellen::da( 'kontakte' ) ) {
            $wpdb->delete( LSV07S_Tabellen::t( 'kontakte' ), [ 'swimmer_id' => $id ], [ '%d' ] );
        }
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return true;
    }

    /** Was an einer Person hängt — als Liste in Worten. */
    public static function spuren( $sparte, $person_id ) {
        global $wpdb;
        $person_id = (int) $person_id;
        $raus = [];

        $t_ein = LSV07S_Sparte::feld( $sparte, 't_anw_eintrag' );
        if ( LSV07S_Tabellen::da( $t_ein ) ) {
            $tsp  = LSV07S_Sparte::feld( $sparte, 'teiln_spalte' );
            $ttyp = LSV07S_Sparte::feld( $sparte, 'teiln_typ_spalte' );
            $wo   = $ttyp !== '' ? "AND $ttyp = 'schwimmer'" : '';
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( $t_ein )
                . " WHERE $tsp = %d $wo", $person_id ) );
            if ( $n ) $raus[] = $n . ' Anwesenheitseinträge';
        }

        if ( LSV07S_Sparte::feld( $sparte, 'hat_buch' ) && LSV07S_Tabellen::da( 'mb_anwesenheit' ) ) {
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'mb_anwesenheit' )
                . ' WHERE schwimmer_id = %d', $person_id ) );
            if ( $n ) $raus[] = $n . ' Einträge im Mannschaftsbuch';
        }

        if ( $sparte === LSV07S_Sparte::SCHWIMMEN && LSV07S_Tabellen::da( 'meldung_start' ) ) {
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'meldung_start' )
                . ' WHERE schwimmer_id = %d', $person_id ) );
            if ( $n ) $raus[] = $n . ' Meldungen';
        }
        return $raus;
    }

    /**
     * In eine andere Mannschaft umziehen.
     *
     * Der interne Bereich zieht dabei nur die Zuordnung und die Bestzeiten
     * mit und lässt die Reflexionsbögen bei der alten Mannschaft stehen.
     * Hier gehen sie mit, und der Umzug steht als Umzug im Protokoll — mit
     * beiden Mannschaften, damit man ihn später nachvollziehen kann.
     */
    public static function sportler_umziehen( $sparte, $person_id, $von, $nach ) {
        global $wpdb;
        $person_id = (int) $person_id;
        $von  = (int) $von;
        $nach = (int) $nach;

        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht_sportler( $sparte, 'update' ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( $von === $nach ) return 'Das ist dieselbe Mannschaft.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $von ) ) {
            return 'Für die bisherige Mannschaft fehlt Ihnen die Berechtigung.';
        }
        $ziel = LSV07S_Mannschaft::holen( $sparte, $nach );
        if ( ! $ziel ) return 'Die Zielmannschaft gibt es nicht.';
        if ( ! in_array( $von, self::zuordnung( $sparte, $person_id ), true ) ) {
            return 'Diese Person steht nicht in der angegebenen Mannschaft.';
        }

        $t  = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $person_id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Person gibt es nicht.';

        $t_zu = LSV07S_Sparte::tabelle( $sparte, 't_sp_gruppe' );
        $sp   = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_sp' );
        $gr   = LSV07S_Sparte::feld( $sparte, 'sp_gruppe_gr' );
        $wpdb->delete( $t_zu, [ $sp => $person_id, $gr => $von ], [ '%d', '%d' ] );
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $t_zu ($sp, $gr) VALUES (%d, %d)
             ON DUPLICATE KEY UPDATE $gr = VALUES($gr)", $person_id, $nach ) );

        if ( $sparte === LSV07S_Sparte::SCHWIMMEN ) {
            /* team_id als Altlast mitführen, solange der interne Bereich
               sie noch liest. */
            $wpdb->update( $t, [ 'team_id' => $nach ], [ 'id' => $person_id ],
                [ '%d' ], [ '%d' ] );
            if ( LSV07S_Tabellen::da( 'bestzeiten' ) ) {
                $wpdb->update( LSV07S_Tabellen::t( 'bestzeiten' ),
                    [ 'mannschaft_id' => $nach ],
                    [ 'mannschaft_id' => $von, 'swimmer_id' => $person_id ],
                    [ '%d' ], [ '%d', '%d' ] );
            }
            if ( LSV07S_Tabellen::da( 'reflexion' ) ) {
                $wpdb->update( LSV07S_Tabellen::t( 'reflexion' ),
                    [ 'mannschaft_id' => $nach ],
                    [ 'mannschaft_id' => $von, 'swimmer_id' => $person_id ],
                    [ '%d' ], [ '%d', '%d' ] );
            }
        }

        $alt = LSV07S_Mannschaft::holen( $sparte, $von );
        LSV07S_Log::schreibe( 'sportler.umgezogen', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $person_id,
            'ziel_name' => LSV07S_Person::aus_zeile( $zeile, $sparte ),
            'details' => ( $alt['name'] ?? $von ) . ' → ' . $ziel['name'],
        ] );
        return true;
    }

    // ── Trainer ──────────────────────────────────────────────────────────

    /** Trainer anlegen oder ändern, samt Zuordnung zu Mannschaften. */
    public static function trainer_speichern( $sparte, $id, array $daten ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::da( $sparte ) ) return 'Diese Sparte ist nicht eingerichtet.';
        $id = (int) $id;

        /* Trainer anzulegen heisst, jemandem Zugang zu Daten zu geben —
           das darf nur, wer Mannschaften verwalten darf. Ein Leserecht auf
           die Trainerliste genügt dafür nicht. */
        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht_gruppe( $sparte, 'update' ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }

        $name = trim( sanitize_text_field( (string) ( $daten['name'] ?? '' ) ) );
        if ( $name === '' ) return 'Bitte einen Namen angeben.';
        [ $mail, $fehler ] = self::adresse( $daten['email'] ?? '' );
        if ( $fehler !== null ) return $fehler;
        $konto = absint( $daten['wp_user_id'] ?? 0 );
        if ( $konto && ! get_user_by( 'id', $konto ) ) return 'Dieses Konto gibt es nicht.';

        $mannschaften = array_values( array_unique( array_map( 'absint',
            (array) ( $daten['mannschaften'] ?? [] ) ) ) );
        $mannschaften = array_values( array_filter( $mannschaften ) );
        foreach ( $mannschaften as $m ) {
            if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $m ) ) {
                return 'Für eine der gewählten Mannschaften fehlt Ihnen die Berechtigung.';
            }
        }

        $t = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        $zeile = [
            'name'       => $name,
            'email'      => $mail,
            'wp_user_id' => $konto,
        ];
        $formate = [ '%s', '%s', '%d' ];
        if ( array_key_exists( 'aktiv', $daten ) ) {
            $zeile['aktiv'] = ! empty( $daten['aktiv'] ) ? 1 : 0;
            $formate[] = '%d';
        } elseif ( ! $id ) {
            $zeile['aktiv'] = 1;
            $formate[] = '%d';
        }

        if ( $id ) {
            $alt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
                $id ), ARRAY_A );
            if ( ! $alt ) return 'Diesen Trainer gibt es nicht.';
            $wpdb->update( $t, $zeile, [ 'id' => $id ], $formate, [ '%d' ] );
        } else {
            $ok = $wpdb->insert( $t, $zeile, $formate );
            if ( ! $ok ) return 'Der Trainer konnte nicht angelegt werden.';
            $id = (int) $wpdb->insert_id;
        }

        $t_tg = LSV07S_Sparte::tabelle( $sparte, 't_tr_gruppe' );
        $gr   = LSV07S_Sparte::feld( $sparte, 'tr_gruppe_gr' );
        $vorher = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT $gr FROM $t_tg WHERE trainer_id = %d", $id ) ) );
        foreach ( $vorher as $m ) {
            if ( in_array( $m, $mannschaften, true ) ) continue;
            if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $m ) ) continue;
            $wpdb->delete( $t_tg, [ 'trainer_id' => $id, $gr => $m ], [ '%d', '%d' ] );
        }
        foreach ( $mannschaften as $m ) {
            if ( in_array( $m, $vorher, true ) ) continue;
            $wpdb->insert( $t_tg, [ 'trainer_id' => $id, $gr => $m ], [ '%d', '%d' ] );
        }

        LSV07S_Log::schreibe( 'trainer.gespeichert', [
            'bereich' => $sparte, 'ziel_typ' => 'trainer', 'ziel_id' => $id,
            'ziel_name' => $name,
            'details' => $konto ? 'mit Konto ' . $konto : 'ohne Konto',
        ] );
        return $id;
    }

    /** Trainer löschen — nur ohne abgerechnete Stunden. */
    public static function trainer_loeschen( $sparte, $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht_gruppe( $sparte, 'delete' ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $t = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diesen Trainer gibt es nicht.';

        if ( $sparte === LSV07S_Sparte::SCHWIMMEN && LSV07S_Tabellen::da( 'abrechnung' ) ) {
            $n = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'abrechnung' )
                . ' WHERE trainer_id = %d', $id ) );
            if ( $n ) {
                return 'Zu diesem Trainer gibt es ' . $n . ' Abrechnungen. '
                     . 'Bitte stilllegen statt löschen — die Abrechnungen müssen '
                     . 'zehn Jahre aufbewahrt werden (§ 147 AO).';
            }
        }

        LSV07S_Log::schreibe( 'trainer.geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'trainer', 'ziel_id' => $id,
            'ziel_name' => (string) $zeile['name'],
        ] );
        $wpdb->delete( LSV07S_Sparte::tabelle( $sparte, 't_tr_gruppe' ),
            [ 'trainer_id' => $id ], [ '%d' ] );
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return true;
    }

    /** Die Trainer einer Sparte, mit ihren Mannschaften. */
    public static function trainer_liste( $sparte ) {
        global $wpdb;
        if ( ! LSV07S_Sparte::da( $sparte ) ) return [];
        $t    = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        $t_tg = LSV07S_Sparte::tabelle( $sparte, 't_tr_gruppe' );
        $gr   = LSV07S_Sparte::feld( $sparte, 'tr_gruppe_gr' );
        $zeilen = (array) $wpdb->get_results(
            "SELECT * FROM $t ORDER BY aktiv DESC, name ASC", ARRAY_A );
        $raus = [];
        foreach ( $zeilen as $z ) {
            $ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT $gr FROM $t_tg WHERE trainer_id = %d", (int) $z['id'] ) ) );
            $namen = [];
            foreach ( $ids as $m ) {
                $mann = LSV07S_Mannschaft::holen( $sparte, $m );
                if ( $mann ) $namen[] = $mann['name'];
            }
            $raus[] = [
                'id'           => (int) $z['id'],
                'name'         => (string) $z['name'],
                'email'        => (string) $z['email'],
                'wp_user_id'   => (int) $z['wp_user_id'],
                'aktiv'        => (int) $z['aktiv'] === 1,
                'mannschaften' => $ids,
                'mann_namen'   => $namen,
            ];
        }
        return $raus;
    }

    // ── Trainingszeiten ──────────────────────────────────────────────────

    /**
     * Trainingszeit anlegen oder ändern.
     *
     * Die Saison kommt mit: Ohne sie gilt der Slot in jeder Saison, und
     * genau daraus entstand im internen Bereich der Fehler, dass nach dem
     * Saisonwechsel alte Stunden in der Abrechnung standen.
     */
    public static function slot_speichern( $sparte, $id, $mannschaft_id, $wochentag,
                                           $von, $bis, $saison_id = null ) {
        global $wpdb;
        $id = (int) $id;
        $mannschaft_id = (int) $mannschaft_id;

        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht( $sparte,
                'slot.' . ( $id ? 'update' : 'create' ) ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        $wochentag = (int) $wochentag;
        if ( $wochentag < 1 || $wochentag > 7 ) return 'Bitte einen Wochentag wählen.';

        $von = self::uhrzeit( $von );
        $bis = self::uhrzeit( $bis );
        if ( $von === null || $bis === null ) return 'Bitte Zeiten als HH:MM angeben.';
        if ( $von === $bis ) return 'Anfang und Ende sind gleich.';
        $stunden = LSV07S_Datum::dauer_stunden( $von, $bis );
        if ( $stunden > 8 ) {
            return 'Dieses Training wäre ' . str_replace( '.', ',', (string) $stunden )
                 . ' Stunden lang. Bitte prüfen.';
        }

        if ( $saison_id === null ) {
            $s = LSV07S_Datum::saison_aktiv();
            $saison_id = $s ? (int) $s['id'] : 0;
        }
        $saison_id = (int) $saison_id;

        $t   = LSV07S_Sparte::tabelle( $sparte, 't_slots' );
        $gsp = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        $hat_saison = LSV07S_Mannschaft::spalte_da( $t, 'saison_id' );

        /* Überschneidung in derselben Mannschaft, am selben Tag, in
           derselben Saison. Zwei Trainings, die sich überlappen, machen
           jede Stundenrechnung falsch — und bisher liess sich das
           eintragen. */
        $wo = $hat_saison ? $wpdb->prepare( ' AND saison_id = %d', $saison_id ) : '';
        $andere = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE $gsp = %d AND wochentag = %d AND id <> %d $wo",
            $mannschaft_id, $wochentag, $id ), ARRAY_A );
        foreach ( $andere as $a ) {
            if ( $von < substr( $a['zeit_bis'], 0, 5 ) && $bis > substr( $a['zeit_von'], 0, 5 ) ) {
                return 'Das überschneidet sich mit '
                     . LSV07S_Datum::spanne( $a['zeit_von'], $a['zeit_bis'] ) . '.';
            }
        }

        $zeile = [
            $gsp        => $mannschaft_id,
            'wochentag' => $wochentag,
            'zeit_von'  => $von . ':00',
            'zeit_bis'  => $bis . ':00',
        ];
        $formate = [ '%d', '%d', '%s', '%s' ];
        if ( $hat_saison ) { $zeile['saison_id'] = $saison_id; $formate[] = '%d'; }

        if ( $id ) {
            $alt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
                $id ), ARRAY_A );
            if ( ! $alt ) return 'Diese Trainingszeit gibt es nicht.';
            $wpdb->update( $t, $zeile, [ 'id' => $id ], $formate, [ '%d' ] );
        } else {
            $ok = $wpdb->insert( $t, $zeile, $formate );
            if ( ! $ok ) return 'Die Trainingszeit konnte nicht angelegt werden.';
            $id = (int) $wpdb->insert_id;
        }

        $mann = LSV07S_Mannschaft::holen( $sparte, $mannschaft_id );
        LSV07S_Log::schreibe( 'trainingszeit.gespeichert', [
            'bereich' => $sparte, 'ziel_typ' => 'slot', 'ziel_id' => $id,
            'ziel_name' => $mann['name'] ?? '',
            'details' => LSV07S_Datum::wochentag_name( $wochentag ) . ' '
                       . $von . '–' . $bis
                       . ( $hat_saison ? ', Saison ' . $saison_id : '' ),
        ] );
        return $id;
    }

    /**
     * Trainingszeit löschen.
     *
     * Erfasste Trainings bleiben: Sie hängen an der Sitzung, nicht am
     * Slot. Gemeldet wird aber, wie viele es sind — wer eine Zeit löscht,
     * an der 40 Trainings hängen, soll es wissen.
     */
    public static function slot_loeschen( $sparte, $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht( $sparte, 'slot.delete' ) ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $t   = LSV07S_Sparte::tabelle( $sparte, 't_slots' );
        $gsp = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Trainingszeit gibt es nicht.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, (int) $zeile[ $gsp ] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $erfasst = 0;
        $t_anw = LSV07S_Sparte::feld( $sparte, 't_anw' );
        if ( LSV07S_Tabellen::da( $t_anw )
             && LSV07S_Mannschaft::spalte_da( LSV07S_Tabellen::t( $t_anw ), 'slot_id' ) ) {
            $erfasst = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( $t_anw )
                . ' WHERE slot_id = %d', $id ) );
        }

        LSV07S_Log::schreibe( 'trainingszeit.geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'slot', 'ziel_id' => $id,
            'details' => LSV07S_Datum::spanne( $zeile['zeit_von'], $zeile['zeit_bis'] )
                       . ', ' . $erfasst . ' erfasste Trainings bleiben erhalten',
        ] );

        /* Ausfälle hängen am Slot und wären nach dem Löschen verwaist. */
        if ( LSV07S_Tabellen::da( 'ausfall' ) ) {
            $wpdb->delete( LSV07S_Tabellen::t( 'ausfall' ), [ 'slot_id' => $id ], [ '%d' ] );
        }
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return [ 'erfasst' => $erfasst ];
    }

    /** „19:30" oder „19:30:00" → „19:30", sonst null. */
    private static function uhrzeit( $wert ) {
        $w = trim( (string) $wert );
        if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $w, $m ) ) return null;
        $st = (int) $m[1];
        $mi = (int) $m[2];
        if ( $st > 23 || $mi > 59 ) return null;
        return sprintf( '%02d:%02d', $st, $mi );
    }

    // ── Saisons ──────────────────────────────────────────────────────────

    /** Alle Saisons, die laufende zuerst erkennbar. */
    public static function saisons() {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'saisons' ) ) return [];
        $zeilen = (array) $wpdb->get_results(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'saisons' )
            . ' ORDER BY start_datum DESC', ARRAY_A );
        return array_map( fn( $z ) => [
            'id'     => (int) $z['id'],
            'name'   => (string) $z['name'],
            'von'    => (string) $z['start_datum'],
            'bis'    => $z['ende_datum'],
            'aktiv'  => (int) $z['aktiv'] === 1,
        ], $zeilen );
    }

    /** Saison anlegen oder ändern. */
    public static function saison_speichern( $id, $name, $von, $bis = null ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf( 'admin.saison.manage' ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! LSV07S_Tabellen::da( 'saisons' ) ) return 'Die Tabelle fehlt.';
        $id   = (int) $id;
        $name = trim( sanitize_text_field( (string) $name ) );
        if ( $name === '' ) return 'Bitte einen Namen angeben.';
        $von = LSV07S_Datum::rein( $von );
        if ( $von === null ) return 'Bitte ein Startdatum angeben.';
        $bis = $bis === null || $bis === '' ? null : LSV07S_Datum::rein( $bis );
        if ( $bis !== null && $bis <= $von ) return 'Das Ende liegt vor dem Anfang.';

        $t = LSV07S_Tabellen::t( 'saisons' );
        $belegt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $t WHERE name = %s AND id <> %d LIMIT 1", $name, $id ) );
        if ( $belegt ) return 'Diesen Namen gibt es schon.';

        /* Saisons dürfen sich nicht überlappen: Sonst liefert „die Saison
           dieses Datums" zwei Antworten, und welche gilt, entscheidet die
           Sortierung — also der Zufall. */
        $andere = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE id <> %d", $id ), ARRAY_A );
        foreach ( $andere as $a ) {
            $a_von = (string) $a['start_datum'];
            $a_bis = $a['ende_datum'] ?: '9999-12-31';
            $e_bis = $bis ?: '9999-12-31';
            if ( $von <= $a_bis && $e_bis >= $a_von ) {
                return 'Dieser Zeitraum überschneidet sich mit „' . $a['name'] . '".';
            }
        }

        $zeile = [ 'name' => $name, 'start_datum' => $von, 'ende_datum' => $bis ];
        if ( $id ) {
            $wpdb->update( $t, $zeile, [ 'id' => $id ], [ '%s', '%s', '%s' ], [ '%d' ] );
        } else {
            $zeile['aktiv']        = 0;
            $zeile['erstellt_von'] = get_current_user_id();
            $wpdb->insert( $t, $zeile, [ '%s', '%s', '%s', '%d', '%d' ] );
            $id = (int) $wpdb->insert_id;
        }
        LSV07S_Log::schreibe( 'saison.gespeichert', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'saison', 'ziel_id' => $id,
            'ziel_name' => $name,
            'details' => $von . ' bis ' . ( $bis ?: 'offen' ),
        ] );
        return $id;
    }

    /**
     * Die laufende Saison setzen.
     *
     * Genau eine ist aktiv. Das ist nicht Bequemlichkeit, sondern die
     * Grundlage von „ab wann darf jemand zurückblättern" — bei zwei
     * aktiven Saisons hängt die Antwort an der Sortierung.
     */
    public static function saison_aktiv_setzen( $id ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf( 'admin.saison.manage' ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $id = (int) $id;
        $t  = LSV07S_Tabellen::t( 'saisons' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Saison gibt es nicht.';

        $wpdb->query( "UPDATE $t SET aktiv = 0" );
        $wpdb->update( $t, [ 'aktiv' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        LSV07S_Log::schreibe( 'saison.aktiv', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'saison', 'ziel_id' => $id,
            'ziel_name' => (string) $zeile['name'],
        ] );
        return true;
    }

    /** Saison löschen — nur, wenn keine Trainingszeit daran hängt. */
    public static function saison_loeschen( $id ) {
        global $wpdb;
        if ( ! LSV07S_Rechte::darf( 'admin.saison.manage' ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $id = (int) $id;
        $t  = LSV07S_Tabellen::t( 'saisons' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Saison gibt es nicht.';
        if ( (int) $zeile['aktiv'] === 1 ) {
            return 'Das ist die laufende Saison. Bitte zuerst eine andere setzen.';
        }

        $haengt = 0;
        foreach ( LSV07S_Sparte::alle() as $sparte ) {
            if ( ! LSV07S_Sparte::da( $sparte ) ) continue;
            $ts = LSV07S_Sparte::tabelle( $sparte, 't_slots' );
            if ( ! LSV07S_Mannschaft::spalte_da( $ts, 'saison_id' ) ) continue;
            $haengt += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $ts WHERE saison_id = %d", $id ) );
        }
        if ( $haengt > 0 ) {
            return 'An dieser Saison hängen ' . $haengt . ' Trainingszeiten. '
                 . 'Sie bleibt deshalb bestehen.';
        }

        LSV07S_Log::schreibe( 'saison.geloescht', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'saison', 'ziel_id' => $id,
            'ziel_name' => (string) $zeile['name'],
        ] );
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return true;
    }
}
