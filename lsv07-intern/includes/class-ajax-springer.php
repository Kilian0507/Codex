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
     * Trainer traegt sich als Springer ein
     */
    public static function eintragen() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $trainer_id = LSV07I_Access::get_trainer_id();
        if ( ! $trainer_id ) {
            wp_send_json_error( [ 'message' => 'Sie sind nicht als Trainer eingetragen.' ] );
        }

        $slot_id = absint( $_POST['slot_id'] ?? 0 );
        $datum   = sanitize_text_field( $_POST['datum'] ?? '' );

        if ( ! $slot_id || ! $datum ) {
            wp_send_json_error( [ 'message' => 'Unvollstaendige Daten.' ] );
        }

        // Pruefe: nicht eigene Mannschaft
        $slot = $wpdb->get_row( $wpdb->prepare(
            "SELECT mannschaft_id FROM {$p}lsv07i_training_slots WHERE id = %d", $slot_id
        ), ARRAY_A );
        if ( ! $slot ) wp_send_json_error( [ 'message' => 'Training nicht gefunden.' ] );

        $eigene = LSV07I_DB::get_trainer_mannschaften( $trainer_id );
        if ( in_array( (string) $slot['mannschaft_id'], $eigene, false ) ) {
            wp_send_json_error( [ 'message' => 'Sie sind bereits fester Trainer fuer diese Mannschaft.' ] );
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
        $trainer_obj = LSV07I_DB::get_trainer_by_user( $trainer_id );
        $springer_name = $trainer_obj ? $trainer_obj['name'] : wp_get_current_user()->display_name;
        LSV07I_Mail::springer_eingetragen( $slot_id, $datum, $springer_name );

        wp_send_json_success();
    }

    /**
     * Trainer traegt sich aus
     */
    public static function austragen() {
        LSV07I_Access::check( 'intern' );
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
        $trainer_obj = LSV07I_DB::get_trainer_by_user( $trainer_id );
        $springer_name = $trainer_obj ? $trainer_obj['name'] : wp_get_current_user()->display_name;
        LSV07I_Mail::springer_ausgetragen( $slot_id, $datum, $springer_name );

        wp_send_json_success();
    }
}
