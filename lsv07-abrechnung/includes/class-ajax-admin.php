<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Verwaltung: Konten und Rollen, Sätze, Pauschalen, Saisons und
 * Trainingszeiten.
 *
 * Saisons und Trainingszeiten gehören dem internen Bereich — hier werden
 * GENAU DIESE Tabellen gepflegt, nicht eigene Kopien. Sonst gäbe es zwei
 * Wahrheiten darüber, wann trainiert wird, und die Abrechnung würde früher
 * oder später etwas anderes rechnen, als im Trainingsplan steht.
 */
class LSV07A_Ajax_Admin {

    public static function init() {
        $aktionen = [
            'uebersicht', 'konten', 'konto_speichern', 'wp_konten',
            'config_speichern', 'pauschalen', 'pauschale_speichern',
            'saisons', 'saison_speichern', 'saison_loeschen', 'saison_aktivieren',
            'slots', 'slot_speichern', 'slot_loeschen', 'protokoll',
        ];
        foreach ( $aktionen as $a ) {
            add_action( 'wp_ajax_lsv07a_adm_' . $a, [ __CLASS__, $a ] );
        }
    }

    private static function tbl( $n ) { global $wpdb; return $wpdb->prefix . $n; }
    private static function itbl( $n ) { return LSV07A_Intern::tabelle( $n ); }

    public static function uebersicht() {
        LSV07A_Access::check( 'admin' );
        wp_send_json_success( [
            'config'         => LSV07A_DB::config_alle(),
            'mannschaften'   => LSV07A_Intern::mannschaften(),
            'hinweis_intern' => LSV07A_Intern::hinweis(),
            'arten'          => array_map( fn( $a ) => [ 'wert' => $a, 'name' => LSV07A_Berechnung::art_name( $a ) ],
                                           LSV07A_DB::ARTEN ),
            'rollen'         => LSV07A_DB::ROLLEN,
        ] );
    }

    // ── Konten und Rollen ────────────────────────────────────────────────

    public static function konten() {
        LSV07A_Access::check( 'admin' );
        $konten = LSV07A_Rollen::konten();
        foreach ( $konten as &$k ) {
            $k['trainer_id'] = LSV07A_Intern::trainer_id( $k['wp_user_id'] );
            $k['art_name']   = LSV07A_Berechnung::art_name( $k['abrechnungsart'] );
        }
        wp_send_json_success( [ 'konten' => $konten, 'hinweis_intern' => LSV07A_Intern::hinweis() ] );
    }

    /** WordPress-Konten zur Auswahl, wenn jemand neu aufgenommen wird. */
    public static function wp_konten() {
        LSV07A_Access::check( 'admin' );
        $suche = sanitize_text_field( $_POST['suche'] ?? '' );
        $args  = [ 'number' => 50, 'orderby' => 'display_name', 'order' => 'ASC' ];
        if ( $suche !== '' ) { $args['search'] = '*' . $suche . '*'; }
        $users = get_users( $args );
        $out = [];
        foreach ( $users as $u ) {
            $out[] = [
                'wp_user_id' => (int) $u->ID,
                'name'       => $u->display_name,
                'email'      => $u->user_email,
                'rollen'     => LSV07A_Rollen::rollen( $u->ID ),
            ];
        }
        wp_send_json_success( $out );
    }

    public static function konto_speichern() {
        LSV07A_Access::check( 'admin', true );
        $uid = absint( $_POST['wp_user_id'] ?? 0 );
        if ( ! $uid || ! get_userdata( $uid ) ) {
            wp_send_json_error( [ 'message' => 'Dieses WordPress-Konto gibt es nicht.' ] );
        }

        $rollen = json_decode( wp_unslash( $_POST['rollen'] ?? '[]' ), true );
        if ( ! is_array( $rollen ) ) $rollen = [];

        /* Niemand darf sich selbst die Administratorrolle wegnehmen, solange
           er der einzige ist — sonst kommt an die Verwaltung niemand mehr
           heran. WordPress-Administratoren bleiben als Ventil außen vor. */
        if ( $uid === get_current_user_id() && ! in_array( 'admin', $rollen, true )
             && ! user_can( $uid, 'administrator' ) ) {
            global $wpdb;
            $andere = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::tbl( 'lsv07a_rolle' ) . "
                  WHERE rolle = 'admin' AND wp_user_id != %d", $uid ) );
            if ( $andere === 0 ) {
                wp_send_json_error( [ 'message' => 'Sie sind die einzige Administration. '
                    . 'Bitte zuerst jemand anderen dazu machen, sonst kommt niemand mehr in die Verwaltung.' ] );
            }
        }

        $gesetzt = LSV07A_Rollen::setzen( $uid, $rollen );

        $satz = (float) str_replace( ',', '.', (string) ( $_POST['stundensatz'] ?? 0 ) );
        if ( $satz < 0 || $satz > 1000 ) {
            wp_send_json_error( [ 'message' => 'Der Stundensatz muss zwischen 0 und 1000 € liegen.' ] );
        }
        $art = sanitize_text_field( $_POST['abrechnungsart'] ?? 'zeiten' );
        if ( ! in_array( $art, LSV07A_DB::ARTEN, true ) ) {
            wp_send_json_error( [ 'message' => 'Unbekannte Abrechnungsart.' ] );
        }

        $ok = LSV07A_Person::speichern( $uid, [
            'stundensatz'    => $satz,
            'abrechnungsart' => $art,
            'aktiv'          => ! empty( $_POST['aktiv'] ) ? 1 : 0,
            'notiz'          => sanitize_textarea_field( $_POST['notiz'] ?? '' ),
        ] );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'Das Konto konnte nicht gespeichert werden.' ] );

        LSV07A_Log::schreibe( 'konto.gespeichert', [
            'ziel_typ' => 'person', 'ziel_id' => $uid,
            'details'  => 'Rollen: ' . ( implode( ', ', $gesetzt ) ?: 'keine' )
                        . ' · ' . number_format( $satz, 2, ',', '.' ) . ' €/Std · '
                        . LSV07A_Berechnung::art_name( $art ) ] );

        wp_send_json_success( [ 'message' => 'Konto gespeichert.', 'rollen' => $gesetzt ] );
    }

    // ── Sätze ────────────────────────────────────────────────────────────

    public static function config_speichern() {
        LSV07A_Access::check( 'admin', true );
        $zahlen = [
            'wk_satz'       => [ 0, 1000 ],
            'km_satz'       => [ 0, 10 ],
            'km_mindest'    => [ 0, 1000 ],
            'wartezeit_min' => [ 0, 240 ],
        ];
        foreach ( $zahlen as $k => [ $min, $max ] ) {
            if ( ! isset( $_POST[ $k ] ) ) continue;
            $w = (float) str_replace( ',', '.', (string) $_POST[ $k ] );
            if ( $w < $min || $w > $max ) {
                wp_send_json_error( [ 'message' => 'Der Wert für „' . $k . '" muss zwischen '
                    . $min . ' und ' . $max . ' liegen.' ] );
            }
            LSV07A_DB::config_set( $k, $w );
        }
        if ( isset( $_POST['km_hin_rueck'] ) ) {
            LSV07A_DB::config_set( 'km_hin_rueck', ! empty( $_POST['km_hin_rueck'] ) ? '1' : '0' );
        }
        if ( isset( $_POST['verein'] ) ) {
            LSV07A_DB::config_set( 'verein', sanitize_text_field( $_POST['verein'] ) );
        }
        LSV07A_Log::schreibe( 'einstellungen.gespeichert' );
        wp_send_json_success( [ 'message' => 'Einstellungen gespeichert.',
                                'config' => LSV07A_DB::config_alle() ] );
    }

    // ── Pauschalen je Mannschaft ─────────────────────────────────────────

    public static function pauschalen() {
        LSV07A_Access::check( 'admin' );
        $werte = LSV07A_Berechnung::pauschalen();
        $out = [];
        foreach ( LSV07A_Intern::mannschaften() as $m ) {
            $mid  = (int) $m['id'];
            $tage = [];
            for ( $t = 0; $t <= 7; $t++ ) {
                $tage[ $t ] = isset( $werte[ $mid ][ $t ] ) ? (float) $werte[ $mid ][ $t ] : null;
            }
            $abweichend = 0;
            for ( $t = 1; $t <= 7; $t++ ) if ( $tage[ $t ] !== null ) $abweichend++;
            $out[] = [
                'mannschaft_id' => $mid,
                'name'          => $m['name'],
                // Der allgemeine Betrag; null heisst "nichts hinterlegt"
                'betrag'        => $tage[0],
                'tage'          => $tage,
                'abweichend'    => $abweichend,
            ];
        }
        wp_send_json_success( [
            'pauschalen'    => $out,
            'tag_namen'     => array_map( fn( $t ) => LSV07A_Berechnung::wochentag_name( $t ), range( 0, 7 ) ),
            'hinweis_intern'=> LSV07A_Intern::hinweis(),
        ] );
    }

    /**
     * Einen Pauschalbetrag speichern — für eine Mannschaft und einen
     * Wochentag. Wochentag 0 ist der allgemeine Betrag, der gilt, wenn
     * für den konkreten Tag nichts hinterlegt ist.
     *
     * Ein leeres Feld LÖSCHT den Eintrag. Das ist etwas anderes als eine
     * 0: Ohne Eintrag greift der allgemeine Betrag wieder, mit einer 0
     * ist dieser Tag ausdrücklich unbezahlt.
     */
    public static function pauschale_speichern() {
        LSV07A_Access::check( 'admin', true );
        global $wpdb;
        $mid = absint( $_POST['mannschaft_id'] ?? 0 );
        $tag = absint( $_POST['wochentag'] ?? 0 );
        if ( ! $mid ) wp_send_json_error( [ 'message' => 'Keine Mannschaft angegeben.' ] );
        if ( $tag > 7 ) wp_send_json_error( [ 'message' => 'Diesen Wochentag gibt es nicht.' ] );

        $roh = trim( (string) ( $_POST['betrag'] ?? '' ) );
        $t   = self::tbl( 'lsv07a_pauschale' );

        if ( $roh === '' ) {
            $wpdb->delete( $t, [ 'mannschaft_id' => $mid, 'wochentag' => $tag ], [ '%d', '%d' ] );
            LSV07A_Log::schreibe( 'pauschale.entfernt', [
                'ziel_typ' => 'mannschaft', 'ziel_id' => $mid,
                'details'  => LSV07A_Berechnung::wochentag_name( $tag ) ] );
            LSV07A_Intern::cache_leeren();
            wp_send_json_success( [ 'message' => 'Eintrag entfernt.' ] );
        }

        $betrag = (float) str_replace( ',', '.', $roh );
        if ( $betrag < 0 || $betrag > 10000 ) {
            wp_send_json_error( [ 'message' => 'Der Betrag muss zwischen 0 und 10.000 € liegen.' ] );
        }
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $t (mannschaft_id, wochentag, betrag)
             VALUES (%d, %d, %f) ON DUPLICATE KEY UPDATE betrag = VALUES(betrag)",
            $mid, $tag, $betrag ) );
        LSV07A_Log::schreibe( 'pauschale.gespeichert', [
            'ziel_typ' => 'mannschaft', 'ziel_id' => $mid,
            'details'  => LSV07A_Berechnung::wochentag_name( $tag ) . ': '
                          . number_format( $betrag, 2, ',', '.' ) . ' EUR je Training' ] );
        wp_send_json_success( [ 'message' => 'Pauschale gespeichert.' ] );
    }

    // ── Saisons (geteilt mit dem internen Bereich) ───────────────────────

    public static function saisons() {
        LSV07A_Access::check( 'admin' );
        if ( ! LSV07A_Intern::da( 'lsv07i_saisons' ) ) {
            wp_send_json_success( [ 'saisons' => [], 'hinweis' => LSV07A_Intern::hinweis() ] );
        }
        wp_send_json_success( [ 'saisons' => LSV07A_Intern::saisons(), 'hinweis' => '' ] );
    }

    public static function saison_speichern() {
        LSV07A_Access::check( 'admin', true );
        global $wpdb;
        if ( ! LSV07A_Intern::da( 'lsv07i_saisons' ) ) {
            wp_send_json_error( [ 'message' => LSV07A_Intern::hinweis() ] );
        }
        $id    = absint( $_POST['id'] ?? 0 );
        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $start = sanitize_text_field( $_POST['start_datum'] ?? '' );
        $ende  = sanitize_text_field( $_POST['ende_datum'] ?? '' );

        if ( $name === '' ) wp_send_json_error( [ 'message' => 'Bitte der Saison einen Namen geben.' ] );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
            wp_send_json_error( [ 'message' => 'Bitte ein Anfangsdatum angeben.' ] );
        }
        /* Das Enddatum ist der Schutz der Historie: Ohne es würden neue
           Trainingszeiten rückwirkend auf alte Abrechnungen durchschlagen. */
        if ( $ende !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ende ) ) {
            wp_send_json_error( [ 'message' => 'Das Enddatum ist kein gültiges Datum.' ] );
        }
        if ( $ende !== '' && $ende < $start ) {
            wp_send_json_error( [ 'message' => 'Das Enddatum liegt vor dem Anfangsdatum.' ] );
        }

        // Überschneidung prüfen — zwei Saisons zur selben Zeit machen die
        // Zuordnung einer Trainingszeit mehrdeutig.
        $t = self::itbl( 'lsv07i_saisons' );
        $ende_sql = $ende === '' ? '9999-12-31' : $ende;
        $kollision = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM $t
              WHERE id != %d
                AND start_datum <= %s
                AND COALESCE(NULLIF(ende_datum, '0000-00-00'), '9999-12-31') >= %s
              LIMIT 1", $id, $ende_sql, $start ), ARRAY_A );
        if ( $kollision ) {
            wp_send_json_error( [ 'message' => 'Dieser Zeitraum überschneidet sich mit der Saison „'
                . $kollision['name'] . '". Bitte die Zeiträume trennen.' ] );
        }

        $daten = [ 'name' => $name, 'start_datum' => $start,
                   'ende_datum' => $ende === '' ? null : $ende ];
        if ( $id ) {
            $ok = $wpdb->update( $t, $daten, [ 'id' => $id ], [ '%s','%s','%s' ], [ '%d' ] );
        } else {
            $daten['erstellt_von'] = get_current_user_id();
            $ok = $wpdb->insert( $t, $daten, [ '%s','%s','%s','%d' ] );
            $id = (int) $wpdb->insert_id;
        }
        if ( $ok === false ) {
            wp_send_json_error( [ 'message' => 'Die Saison konnte nicht gespeichert werden: '
                . ( $wpdb->last_error ?: 'eventuell gibt es den Namen schon' ) ] );
        }
        LSV07A_Log::schreibe( 'saison.gespeichert', [ 'ziel_typ' => 'saison', 'ziel_id' => $id,
            'details' => $name . ' (' . $start . ' bis ' . ( $ende ?: 'offen' ) . ')' ] );
        LSV07A_Intern::cache_leeren();
        wp_send_json_success( [ 'message' => 'Saison gespeichert.', 'id' => $id ] );
    }

    public static function saison_aktivieren() {
        LSV07A_Access::check( 'admin', true );
        global $wpdb;
        if ( ! LSV07A_Intern::da( 'lsv07i_saisons' ) ) {
            wp_send_json_error( [ 'message' => LSV07A_Intern::hinweis() ] );
        }
        $id = absint( $_POST['id'] ?? 0 );
        $t  = self::itbl( 'lsv07i_saisons' );
        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d", $id ) ) ) {
            wp_send_json_error( [ 'message' => 'Saison nicht gefunden.' ] );
        }
        $wpdb->query( "UPDATE $t SET aktiv = 0" );
        $wpdb->update( $t, [ 'aktiv' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'saison.aktiviert', [ 'ziel_typ' => 'saison', 'ziel_id' => $id ] );
        LSV07A_Intern::cache_leeren();
        wp_send_json_success( [ 'message' => 'Saison aktiviert.' ] );
    }

    public static function saison_loeschen() {
        LSV07A_Access::check( 'admin', true );
        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        $t  = self::itbl( 'lsv07i_saisons' );
        $ts = self::itbl( 'lsv07i_training_slots' );
        $anzahl = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $ts WHERE saison_id = %d", $id ) );
        if ( $anzahl > 0 ) {
            wp_send_json_error( [ 'message' => 'An dieser Saison hängen noch ' . $anzahl
                . ' Trainingszeiten. Bitte diese zuerst entfernen — sonst verlieren alte '
                . 'Abrechnungen ihre Stundenangaben.' ] );
        }
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        LSV07A_Log::schreibe( 'saison.geloescht', [ 'ziel_typ' => 'saison', 'ziel_id' => $id ] );
        LSV07A_Intern::cache_leeren();
        wp_send_json_success( [ 'message' => 'Saison gelöscht.' ] );
    }

    // ── Trainingszeiten (geteilt mit dem internen Bereich) ───────────────

    public static function slots() {
        LSV07A_Access::check( 'admin' );
        $saison_id = isset( $_POST['saison_id'] ) ? absint( $_POST['saison_id'] ) : null;
        wp_send_json_success( [
            'slots'        => LSV07A_Intern::slots( $saison_id ),
            'mannschaften' => LSV07A_Intern::mannschaften(),
            'saisons'      => LSV07A_Intern::saisons(),
            'hinweis'      => LSV07A_Intern::hinweis(),
        ] );
    }

    public static function slot_speichern() {
        LSV07A_Access::check( 'admin', true );
        global $wpdb;
        if ( ! LSV07A_Intern::da( 'lsv07i_training_slots' ) ) {
            wp_send_json_error( [ 'message' => LSV07A_Intern::hinweis() ] );
        }
        $id    = absint( $_POST['id'] ?? 0 );
        $sid   = absint( $_POST['saison_id'] ?? 0 );
        $mid   = absint( $_POST['mannschaft_id'] ?? 0 );
        $tag   = (int) ( $_POST['wochentag'] ?? 1 );
        $von   = sanitize_text_field( $_POST['zeit_von'] ?? '' );
        $bis   = sanitize_text_field( $_POST['zeit_bis'] ?? '' );

        if ( ! $sid ) wp_send_json_error( [ 'message' => 'Bitte eine Saison wählen — ohne sie lässt sich die Zeit später nicht zuordnen.' ] );
        if ( ! $mid ) wp_send_json_error( [ 'message' => 'Bitte eine Mannschaft wählen.' ] );
        if ( $tag < 1 || $tag > 7 ) wp_send_json_error( [ 'message' => 'Unmöglicher Wochentag.' ] );
        if ( ! preg_match( '/^\d{2}:\d{2}/', $von ) || ! preg_match( '/^\d{2}:\d{2}/', $bis ) ) {
            wp_send_json_error( [ 'message' => 'Bitte Anfangs- und Endzeit angeben.' ] );
        }
        if ( substr( $bis, 0, 5 ) <= substr( $von, 0, 5 ) ) {
            wp_send_json_error( [ 'message' => 'Die Endzeit muss nach der Anfangszeit liegen.' ] );
        }

        $t = self::itbl( 'lsv07i_training_slots' );
        $daten = [ 'saison_id' => $sid, 'mannschaft_id' => $mid, 'wochentag' => $tag,
                   'zeit_von' => $von, 'zeit_bis' => $bis ];
        if ( $id ) {
            $ok = $wpdb->update( $t, $daten, [ 'id' => $id ], [ '%d','%d','%d','%s','%s' ], [ '%d' ] );
        } else {
            $ok = $wpdb->insert( $t, $daten, [ '%d','%d','%d','%s','%s' ] );
            $id = (int) $wpdb->insert_id;
        }
        if ( $ok === false ) {
            wp_send_json_error( [ 'message' => 'Die Trainingszeit konnte nicht gespeichert werden: '
                . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' ) ] );
        }
        LSV07A_Log::schreibe( 'trainingszeit.gespeichert', [ 'ziel_typ' => 'slot', 'ziel_id' => $id,
            'details' => 'Wochentag ' . $tag . ', ' . $von . '–' . $bis ] );
        LSV07A_Intern::cache_leeren();
        wp_send_json_success( [ 'message' => 'Trainingszeit gespeichert.', 'id' => $id ] );
    }

    public static function slot_loeschen() {
        LSV07A_Access::check( 'admin', true );
        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        $t  = self::itbl( 'lsv07i_training_slots' );
        /* Hängt daran Anwesenheit, verlieren alte Abrechnungen ihre
           Stundenangabe. Deshalb wird gewarnt statt stillschweigend gelöscht. */
        if ( LSV07A_Intern::da( 'lsv07i_anwesenheit' ) ) {
            $anzahl = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::itbl( 'lsv07i_anwesenheit' ) . " WHERE slot_id = %d", $id ) );
            if ( $anzahl > 0 && empty( $_POST['trotzdem'] ) ) {
                wp_send_json_error( [ 'code' => 'hat_anwesenheit', 'anzahl' => $anzahl,
                    'message' => 'An dieser Trainingszeit hängen ' . $anzahl . ' erfasste Trainings. '
                    . 'Wird sie gelöscht, haben deren Abrechnungsposten keine Stundenangabe mehr.' ] );
            }
        }
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        LSV07A_Log::schreibe( 'trainingszeit.geloescht', [ 'ziel_typ' => 'slot', 'ziel_id' => $id ] );
        LSV07A_Intern::cache_leeren();
        wp_send_json_success( [ 'message' => 'Trainingszeit gelöscht.' ] );
    }

    public static function protokoll() {
        LSV07A_Access::check( 'admin' );
        $zeilen = LSV07A_Log::liste( 200 );
        foreach ( $zeilen as &$z ) {
            $u = get_userdata( $z['wp_user_id'] );
            $z['wer'] = $u ? $u->display_name : ( 'Konto ' . $z['wp_user_id'] );
        }
        wp_send_json_success( $zeilen );
    }
}
