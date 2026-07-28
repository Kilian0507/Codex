<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stammdaten-Übersicht: liefert eine einheitliche Liste aller Personen
 * (Trainer/Sportler) und Mannschaften/Gruppen über alle drei Sparten.
 *
 * Wird vom neuen "Stammdaten"-Tab im Admin-Bereich aufgerufen.
 */
class LSV07I_Ajax_Stammdaten {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_stammdaten_list', [ __CLASS__, 'list' ] );
    }

    public static function list() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
        if ( ! current_user_can( 'administrator' ) && ! LSV07I_Access::is_admin() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $items = [];

        try {
            // ── Schwimmen: Mannschaften ──
            $rows = $wpdb->get_results(
                "SELECT id, name FROM {$p}lsv07_gruppen ORDER BY sort_order ASC, name ASC", ARRAY_A );
            if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                $items[] = [
                    'sparte'  => 'schwimmen',
                    'typ'     => 'mannschaft',
                    'id'      => (int) $r['id'],
                    'name'    => $r['name'],
                    'detail'  => 'Mannschaft',
                    'panel'   => 'i-p-adm-mn',
                ];
            }

            // ── Schwimmen: Trainer ──
            $rows = $wpdb->get_results(
                "SELECT id, name, email FROM {$p}lsv07i_trainer ORDER BY name ASC", ARRAY_A );
            if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                $items[] = [
                    'sparte'  => 'schwimmen',
                    'typ'     => 'trainer',
                    'id'      => (int) $r['id'],
                    'name'    => $r['name'],
                    'detail'  => $r['email'] ?: '',
                    'panel'   => 'i-p-adm-tr',
                ];
            }

            // ── Schwimmen: Schwimmer ──
            $rows = $wpdb->get_results(
                "SELECT s.id, s.last_name, s.first_name, s.birth_date, g.name AS mann
                   FROM {$p}mv_swimmers s
              LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
                  WHERE s.active = 1
               ORDER BY s.last_name ASC, s.first_name ASC", ARRAY_A );
            if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                $items[] = [
                    'sparte'  => 'schwimmen',
                    'typ'     => 'sportler',
                    'id'      => (int) $r['id'],
                    'name'    => trim( $r['last_name'] . ', ' . $r['first_name'] ),
                    'detail'  => $r['mann'] ?: '–',
                    'panel'   => 'i-p-adm-sw',
                ];
            }

            // ── Triathlon: Gruppen ──
            $tbl = $p . 'lsv07i_tri_gruppen';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
                $rows = $wpdb->get_results( "SELECT id, name FROM $tbl ORDER BY sort_order ASC, name ASC", ARRAY_A );
                if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                    $items[] = [
                        'sparte' => 'triathlon', 'typ' => 'mannschaft',
                        'id' => (int) $r['id'], 'name' => $r['name'],
                        'detail' => 'Gruppe', 'panel' => 'i-p-adm-tri-gr',
                    ];
                }
            }

            // ── Triathlon: Trainer ──
            $tbl = $p . 'lsv07i_tri_trainer';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
                $rows = $wpdb->get_results(
                    "SELECT id, name, email FROM $tbl ORDER BY name ASC", ARRAY_A );
                if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                    $items[] = [
                        'sparte' => 'triathlon', 'typ' => 'trainer',
                        'id' => (int) $r['id'],
                        'name' => $r['name'],
                        'detail' => $r['email'] ?: '',
                        'panel' => 'i-p-adm-tri-tr',
                    ];
                }
            }

            // ── Triathlon: Sportler ──
            $tbl = $p . 'lsv07i_tri_sportler';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
                $rows = $wpdb->get_results(
                    "SELECT s.id, s.last_name, s.first_name,
                            ( SELECT g.name FROM {$p}lsv07i_tri_gruppen g
                                JOIN {$p}lsv07i_tri_sportler_gruppe sg ON sg.gruppe_id = g.id
                               WHERE sg.sportler_id = s.id LIMIT 1 ) AS gruppe
                       FROM $tbl s
                      WHERE s.aktiv = 1
                   ORDER BY s.last_name ASC", ARRAY_A );
                if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                    $items[] = [
                        'sparte' => 'triathlon', 'typ' => 'sportler',
                        'id' => (int) $r['id'],
                        'name' => trim( $r['last_name'] . ', ' . $r['first_name'] ),
                        'detail' => $r['gruppe'] ?: '–',
                        'panel' => 'i-p-adm-tri-sp',
                    ];
                }
            }

            // ── Fitness: Gruppen ──
            $tbl = $p . 'lsv07i_fit_gruppen';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
                $rows = $wpdb->get_results( "SELECT id, name FROM $tbl ORDER BY sort_order ASC, name ASC", ARRAY_A );
                if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                    $items[] = [
                        'sparte' => 'fitness', 'typ' => 'mannschaft',
                        'id' => (int) $r['id'], 'name' => $r['name'],
                        'detail' => 'Gruppe', 'panel' => 'i-p-adm-fit-gr',
                    ];
                }
            }

            // ── Fitness: Trainer ──
            $tbl = $p . 'lsv07i_fit_trainer';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
                $rows = $wpdb->get_results(
                    "SELECT id, name, email FROM $tbl ORDER BY name ASC", ARRAY_A );
                if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                    $items[] = [
                        'sparte' => 'fitness', 'typ' => 'trainer',
                        'id' => (int) $r['id'],
                        'name' => $r['name'],
                        'detail' => $r['email'] ?: '',
                        'panel' => 'i-p-adm-fit-tr',
                    ];
                }
            }

            // ── Fitness: Sportler ──
            $tbl = $p . 'lsv07i_fit_sportler';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
                $rows = $wpdb->get_results(
                    "SELECT s.id, s.last_name, s.first_name,
                            ( SELECT g.name FROM {$p}lsv07i_fit_gruppen g
                                JOIN {$p}lsv07i_fit_sportler_gruppe sg ON sg.gruppe_id = g.id
                               WHERE sg.sportler_id = s.id LIMIT 1 ) AS gruppe
                       FROM $tbl s
                      WHERE s.aktiv = 1
                   ORDER BY s.last_name ASC", ARRAY_A );
                if ( is_array( $rows ) ) foreach ( $rows as $r ) {
                    $items[] = [
                        'sparte' => 'fitness', 'typ' => 'sportler',
                        'id' => (int) $r['id'],
                        'name' => trim( $r['last_name'] . ', ' . $r['first_name'] ),
                        'detail' => $r['gruppe'] ?: '–',
                        'panel' => 'i-p-adm-fit-sp',
                    ];
                }
            }

            wp_send_json_success( $items );
        } catch ( Throwable $e ) {
            wp_send_json_error( [
                'message' => 'Server-Fehler: ' . $e->getMessage(),
                'file'    => basename( $e->getFile() ),
                'line'    => $e->getLine(),
            ] );
        }
    }
}
