<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Springer {

    public static function init() {
        $actions = [
            'lsv07i_springer_get_plan',
            'lsv07i_springer_eintragen',
            'lsv07i_springer_austragen',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_springer_', '', $action ) ] );
        }
    }

    /**
     * Liefert den Springer-Plan: alle Slots + wer sich eingetragen hat
     */
    public static function get_plan() {
        LSV07I_Access::check( 'sw_spr_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $von = sanitize_text_field( $_POST['von'] ?? date( 'Y-m-d' ) );
        $bis = sanitize_text_field( $_POST['bis'] ?? date( 'Y-m-d', strtotime( '+28 days' ) ) );

        $slots = LSV07I_DB::get_slots();
        $trainer_id = LSV07I_Access::get_trainer_id();

        // Eigene Mannschaften ermitteln um diese auszuschliessen
        $eigene_mannschaften = $trainer_id
            ? LSV07I_DB::get_trainer_mannschaften( $trainer_id )
            : [];

        // Springer-Eintraege im Zeitraum
        $springer = $wpdb->get_results( $wpdb->prepare(
            "SELECT sp.*, t.name AS trainer_name, g.name AS mannschaft_name, s.wochentag, s.zeit_von, s.zeit_bis, s.mannschaft_id
               FROM {$p}lsv07i_springer sp
               JOIN {$p}lsv07i_training_slots s ON s.id = sp.slot_id
          LEFT JOIN {$p}lsv07i_trainer t ON t.id = sp.trainer_id
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id
              WHERE sp.training_datum BETWEEN %s AND %s
           ORDER BY sp.training_datum ASC, s.zeit_von ASC",
            $von, $bis
        ), ARRAY_A );

        // Ausgefallene Trainings
        $ausfaelle = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_training_ausfall WHERE training_datum BETWEEN %s AND %s",
            $von, $bis
        ), ARRAY_A );
        $ausfall_set = [];
        foreach ( $ausfaelle as $a ) {
            $ausfall_set[ $a['slot_id'] . '_' . $a['training_datum'] ] = true;
        }

        // Eintragungen pro Slot/Datum zaehlen
        $count_map = [];
        foreach ( $springer as $sp ) {
            $key = $sp['slot_id'] . '_' . $sp['training_datum'];
            $count_map[ $key ] = ( $count_map[ $key ] ?? 0 ) + 1;
        }

        wp_send_json_success( [
            'slots'             => $slots,
            'springer'          => $springer,
            'eigene_mannschaften' => $eigene_mannschaften,
            'count_map'         => $count_map,
            'ausfall_set'       => $ausfall_set,
            'trainer_id'        => $trainer_id,
            'von'               => $von,
            'bis'               => $bis,
        ] );
    }

    /**
     * Trainer traegt sich als Springer ein.
     *
     * Ein Springer-Eintrag ist bezahlte Zeit: Die Abrechnung übernimmt ihn
     * als Trainingstag. Vorher genügte dafür irgendein Schwimmen-Recht und
     * ein beliebiger Text als Datum — man konnte sich für vergangene oder
     * erfundene Tage eintragen. Darum hier, in dieser Reihenfolge:
     *
     *  1. Das Recht „Sich als Springer eintragen" (steht in der Vorlage
     *     Trainer Schwimmen und damit auch beim Schwimmwart).
     *  2. Für wen: sich selbst. Einen anderen Trainer tragen nur
     *     Administration und Schwimmwart ein (Parameter trainer_id).
     *  3. Ein echtes Datum, nicht in der Vergangenheit (rückwirkend nur
     *     Administration/Schwimmwart) und höchstens ein Jahr voraus.
     *  4. Die Trainingszeit gibt es, und sie liegt auf diesem Wochentag.
     *  5. Die Abrechnung des Quartals ist noch offen — in eine eingereichte
     *     oder genehmigte Abrechnung kommt kein neuer bezahlter Tag.
     */
    public static function eintragen() {
        LSV07I_Access::check( 'intern' );
        if ( ! LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_SPRINGER_EINTRAGEN ) ) {
            wp_send_json_error( [ 'message' => 'Zum Eintragen als Springer fehlt die Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;

        $verwaltung = LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart();
        $eigene_id  = (int) LSV07I_Access::get_trainer_id();
        $trainer_id = absint( $_POST['trainer_id'] ?? 0 ) ?: $eigene_id;
        if ( ! $trainer_id ) {
            wp_send_json_error( [ 'message' => 'Sie sind nicht als Trainer eingetragen.' ] );
        }
        if ( $trainer_id !== $eigene_id ) {
            if ( ! $verwaltung ) {
                wp_send_json_error( [ 'message' => 'Eintragen können Sie nur sich selbst.' ], 403 );
            }
            $aktiv = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_trainer WHERE id = %d AND aktiv = 1", $trainer_id ) );
            if ( ! $aktiv ) wp_send_json_error( [ 'message' => 'Trainer nicht gefunden.' ] );
        }

        $slot_id = absint( $_POST['slot_id'] ?? 0 );
        $datum   = sanitize_text_field( $_POST['datum'] ?? '' );

        if ( ! $slot_id || ! $datum ) {
            wp_send_json_error( [ 'message' => 'Unvollstaendige Daten.' ] );
        }
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $m )
          || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiges Datum.' ] );
        }
        $heute = current_time( 'Y-m-d' );
        if ( $datum < $heute && ! $verwaltung ) {
            wp_send_json_error( [ 'message' => 'Für vergangene Trainings kann man sich nicht nachträglich eintragen.' ] );
        }
        if ( $datum > date( 'Y-m-d', strtotime( $heute . ' +1 year' ) ) ) {
            wp_send_json_error( [ 'message' => 'So weit im Voraus ist kein Eintrag möglich.' ] );
        }

        // Pruefe: Trainingszeit existiert und liegt auf diesem Wochentag
        $slot = $wpdb->get_row( $wpdb->prepare(
            "SELECT mannschaft_id, wochentag FROM {$p}lsv07i_training_slots WHERE id = %d", $slot_id
        ), ARRAY_A );
        if ( ! $slot ) wp_send_json_error( [ 'message' => 'Training nicht gefunden.' ] );
        if ( (int) $slot['wochentag'] !== (int) date( 'N', strtotime( $datum ) ) ) {
            wp_send_json_error( [ 'message' => 'An diesem Tag findet dieses Training nicht statt.' ] );
        }

        // Pruefe: nicht eigene Mannschaft
        $eigene = LSV07I_DB::get_trainer_mannschaften( $trainer_id );
        if ( in_array( (string) $slot['mannschaft_id'], array_map( 'strval', (array) $eigene ), true ) ) {
            wp_send_json_error( [ 'message' => 'Sie sind bereits fester Trainer fuer diese Mannschaft.' ] );
        }

        // Pruefe: Abrechnung des Quartals noch offen
        $quartal = 'Q' . ceil( (int) $m[2] / 3 );
        $status  = $wpdb->get_var( $wpdb->prepare(
            "SELECT abr_status FROM {$p}lsv07i_abrechnung
              WHERE trainer_id = %d AND bereich = 'schwimmen' AND quartal = %s AND jahr = %d LIMIT 1",
            $trainer_id, $quartal, (int) $m[1] ) );
        if ( $status !== null && ! LSV07I_Ajax_Abrechnung::ist_bearbeitbar( $status ) ) {
            wp_send_json_error( [ 'message' => 'Die Abrechnung für ' . $quartal . ' ' . (int) $m[1]
                . ' ist bereits eingereicht — ein neuer Springer-Einsatz kann dort nicht mehr hinzukommen.' ] );
        }

        // Pruefe: Ausgefallen?
        $ausgefallen = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_training_ausfall WHERE slot_id = %d AND training_datum = %s LIMIT 1",
            $slot_id, $datum
        ) );
        if ( $ausgefallen ) {
            wp_send_json_error( [ 'message' => 'Dieses Training ist als ausgefallen markiert.' ] );
        }

        // Pruefe: max. 2 Springer pro Slot/Datum
        $anzahl = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_springer WHERE slot_id = %d AND training_datum = %s",
            $slot_id, $datum
        ) );
        if ( $anzahl >= 2 ) {
            wp_send_json_error( [ 'message' => 'Fuer dieses Training sind bereits 2 Springer eingetragen.' ] );
        }

        // Eintragen
        $result = $wpdb->insert( $p . 'lsv07i_springer', [
            'slot_id'        => $slot_id,
            'training_datum' => $datum,
            'trainer_id'     => $trainer_id,
        ], [ '%d', '%s', '%d' ] );

        if ( $result === false ) {
            wp_send_json_error( [ 'message' => 'Sie sind fuer dieses Training bereits als Springer eingetragen.' ] );
        }

        // Mail an Trainer der Mannschaft
        $springer_name = self::trainer_name( $trainer_id );
        LSV07I_Mail::springer_eingetragen( $slot_id, $datum, $springer_name );

        wp_send_json_success();
    }

    /** Name eines Trainer-Profils (vorher wurde hier die Trainer-ID als Konto-ID nachgeschlagen). */
    private static function trainer_name( $trainer_id ) {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}lsv07i_trainer WHERE id = %d", (int) $trainer_id ) );
        return $name ?: wp_get_current_user()->display_name;
    }

    /**
     * Trainer traegt sich aus
     */
    public static function austragen() {
        LSV07I_Access::check( 'intern' );
        // Das eigene Recht dazu (Vorlage Trainer Schwimmen) — wie beim Eintragen.
        if ( ! LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_SPRINGER_AUSTRAGEN ) ) {
            wp_send_json_error( [ 'message' => 'Zum Austragen fehlt die Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;

        $trainer_id = LSV07I_Access::get_trainer_id();
        if ( ! $trainer_id ) {
            wp_send_json_error( [ 'message' => 'Sie sind nicht als Trainer eingetragen.' ] );
        }

        $slot_id = absint( $_POST['slot_id'] ?? 0 );
        $datum   = sanitize_text_field( $_POST['datum'] ?? '' );

        // Datum in der Vergangenheit? Nur Admin kann das
        if ( strtotime( $datum ) < strtotime( 'today' ) && ! LSV07I_Access::is_admin() ) {
            wp_send_json_error( [ 'message' => 'Vergangene Eintraege koennen nicht geaendert werden.' ] );
        }

        $wpdb->delete( $p . 'lsv07i_springer', [
            'slot_id'        => $slot_id,
            'training_datum' => $datum,
            'trainer_id'     => $trainer_id,
        ], [ '%d', '%s', '%d' ] );

        // Mail an Trainer der Mannschaft
        $springer_name = self::trainer_name( $trainer_id );
        LSV07I_Mail::springer_ausgetragen( $slot_id, $datum, $springer_name );

        wp_send_json_success();
    }
}
