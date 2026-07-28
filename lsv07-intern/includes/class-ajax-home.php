<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Home
 * ----------------
 * Liefert Quick-Stats für die Startseite des internen Bereichs:
 *  - Schwimmen: letzte Anwesenheit, offene Springer-Anfragen,
 *               aktuelle Quartalsabrechnung, nächster Wettkampf
 *  - Triathlon: letzte Anwesenheit, aktuelle Quartalsabrechnung,
 *               aktive Gruppen
 *
 * Die Werte sind rollenabhängig — ein normaler Trainer sieht nur
 * seine eigenen Mannschaften/Daten, Admin/Schwimmwart sehen alles.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Home {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_home_stats', [ __CLASS__, 'stats' ] );
        add_action( 'wp_ajax_lsv07i_home_widgets_get', [ __CLASS__, 'widgets_get' ] );
        add_action( 'wp_ajax_lsv07i_home_widgets_save', [ __CLASS__, 'widgets_save' ] );
    }

    /**
     * Liste der für den aktuellen Nutzer verfügbaren Widgets (Quicklinks),
     * gefiltert auf seine Berechtigung. Pro Bereich gruppiert.
     */
    private static function available_widgets() {
        // Jeder Eintrag: id, bereich-key, label, sub, target (jump+tab)
        $all = [
            // SCHWIMMEN
            [ 'id'=>'s.mann',  'bereich'=>'s',   'label'=>'Mannschaftsverwaltung', 'sub'=>'Schwimmer & Trainer',     'jump'=>'s',   'tab'=>'i-p-mann' ],
            [ 'id'=>'s.anw',   'bereich'=>'s',   'label'=>'Anwesenheit',           'sub'=>'Training & Wettkämpfe',   'jump'=>'s',   'tab'=>'i-p-anw'  ],
            [ 'id'=>'s.wk',    'bereich'=>'s',   'label'=>'Wettkämpfe',            'sub'=>'Termine & Ergebnisse',    'jump'=>'s',   'tab'=>'i-p-wk'   ],
            [ 'id'=>'s.bz',    'bereich'=>'s',   'label'=>'Bestzeiten',            'sub'=>'Zeiten importieren',      'jump'=>'s',   'tab'=>'i-p-bz'   ],
            [ 'id'=>'s.spr',   'bereich'=>'s',   'label'=>'Springer',              'sub'=>'Vertretungen finden',     'jump'=>'s',   'tab'=>'i-p-spr'  ],
            [ 'id'=>'s.refl',  'bereich'=>'s',   'label'=>'Reflexionsbögen',       'sub'=>'Saison-Reflexion',        'jump'=>'s',   'tab'=>'i-p-refl' ],
            // TRIATHLON
            [ 'id'=>'tri.mann','bereich'=>'tri', 'label'=>'Gruppen & Sportler',    'sub'=>'Triathlon verwalten',     'jump'=>'tri', 'tab'=>'i-p-tri-mann' ],
            [ 'id'=>'tri.anw', 'bereich'=>'tri', 'label'=>'Anwesenheit (Tri)',     'sub'=>'Training erfassen',       'jump'=>'tri', 'tab'=>'i-p-tri-anw'  ],
            // FITNESS
            [ 'id'=>'fit.mann','bereich'=>'fit', 'label'=>'Gruppen & Sportler',    'sub'=>'Fitness verwalten',       'jump'=>'fit', 'tab'=>'i-p-fit-mann' ],
            [ 'id'=>'fit.anw', 'bereich'=>'fit', 'label'=>'Anwesenheit (Fitness)', 'sub'=>'Training erfassen',       'jump'=>'fit', 'tab'=>'i-p-fit-anw'  ],
            // ABRECHNUNG / VERWALTUNG / NACHRICHTEN
            [ 'id'=>'abr',     'bereich'=>'t',   'label'=>'Abrechnungen',          'sub'=>'Trainer-Abrechnungen',    'jump'=>'t',   'tab'=>''        ],
            [ 'id'=>'v.pers',  'bereich'=>'v',   'label'=>'Personenverwaltung',    'sub'=>'Mitglieder & Trainer',    'jump'=>'v',   'tab'=>'i-p-pers' ],
            [ 'id'=>'n.inbox', 'bereich'=>'n',   'label'=>'Posteingang',           'sub'=>'Interne Nachrichten',     'jump'=>'n',   'tab'=>''        ],
        ];

        // Nach Berechtigung filtern (gleiches Schema wie Hauptnavigation)
        $cS    = LSV07I_Access::is_intern();
        $cTRI  = LSV07I_Access::is_tri_intern();
        $cFIT  = LSV07I_Access::is_fit_intern();
        $cT    = LSV07I_Access::is_trainer();
        $cV    = LSV07I_Access::is_schwimmwart() || LSV07I_Access::is_finanzwart();
        $cN    = is_user_logged_in();
        // Permissions ergänzen (analog zu templates/main.php)
        if ( class_exists( 'LSV07I_Permissions' ) ) {
            $cS   = $cS   || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_MANNSCHAFT_READ );
            $cTRI = $cTRI || LSV07I_Permissions::can_current( LSV07I_Permissions::TRIATHLON_GRUPPE_READ );
            $cFIT = $cFIT || LSV07I_Permissions::can_current( LSV07I_Permissions::FITNESS_GRUPPE_READ );
        }
        $erlaubt_bereich = [
            's' => $cS, 'tri' => $cTRI, 'fit' => $cFIT,
            't' => $cT, 'v' => $cV, 'n' => $cN,
        ];

        $out = [];
        foreach ( $all as $w ) {
            if ( ! empty( $erlaubt_bereich[ $w['bereich'] ] ) ) $out[] = $w;
        }
        return $out;
    }

    public static function widgets_get() {
        LSV07I_Access::check( 'intern' );
        $uid = get_current_user_id();
        $aktiv = get_user_meta( $uid, 'lsv07i_home_widgets', true );
        if ( ! is_array( $aktiv ) ) $aktiv = [];
        wp_send_json_success( [
            'available' => self::available_widgets(),
            'active'    => array_values( $aktiv ),
        ] );
    }

    public static function widgets_save() {
        LSV07I_Access::check( 'intern' );
        $uid = get_current_user_id();
        $raw = $_POST['ids'] ?? '[]';
        if ( is_string( $raw ) ) $raw = json_decode( wp_unslash( $raw ), true );
        if ( ! is_array( $raw ) ) $raw = [];

        // Nur erlaubte IDs zulassen (Defense-in-Depth: kein User-Input direkt speichern)
        $valid_ids = array_column( self::available_widgets(), 'id' );
        $clean = array_values( array_intersect( array_map( 'sanitize_text_field', $raw ), $valid_ids ) );

        update_user_meta( $uid, 'lsv07i_home_widgets', $clean );
        wp_send_json_success( [ 'active' => $clean ] );
    }

    public static function stats() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $trainer_id = (int) LSV07I_Access::get_trainer_id();
        $tri_tid    = (int) LSV07I_Access::get_tri_trainer_id();
        $fit_tid    = (int) LSV07I_Access::get_fit_trainer_id();
        $is_admin   = LSV07I_Access::is_admin();
        $is_schwimm = LSV07I_Access::is_schwimmwart();
        $is_tri_w   = LSV07I_Access::is_triathlonwart();
        $is_fit_w   = LSV07I_Access::is_fitnesswart();
        $sieht_alle = $is_admin || $is_schwimm;

        // ── SCHWIMMEN-STATS ──────────────────────────────────────────────
        $schwimmen = self::stats_schwimmen( $trainer_id, $sieht_alle );

        // ── TRIATHLON-STATS ──────────────────────────────────────────────
        $triathlon = self::stats_triathlon( $tri_tid, $is_admin || $is_tri_w );

        // ── FITNESS-STATS ────────────────────────────────────────────────
        $fitness   = self::stats_fitness( $fit_tid, $is_admin || $is_fit_w );

        wp_send_json_success( [
            'schwimmen' => $schwimmen,
            'triathlon' => $triathlon,
            'fitness'   => $fitness,
            'access'    => [
                'schwimmen' => LSV07I_Access::is_intern(),
                'triathlon' => LSV07I_Access::is_tri_intern(),
                'fitness'   => LSV07I_Access::is_fit_intern(),
            ],
        ] );
    }

    private static function stats_schwimmen( $trainer_id, $sieht_alle ) {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! LSV07I_Access::is_intern() ) return null;

        // Mannschaften, die für die Statistik relevant sind
        $mann_filter_sql = '';
        if ( ! $sieht_alle && $trainer_id ) {
            $mids = $wpdb->get_col( $wpdb->prepare(
                "SELECT mannschaft_id FROM {$p}lsv07i_trainer_mannschaft WHERE trainer_id = %d",
                $trainer_id
            ) );
            if ( empty( $mids ) ) {
                $mann_filter_sql = ' AND 1=0'; // keine eigenen → leer
            } else {
                $in = implode( ',', array_map( 'absint', $mids ) );
                $mann_filter_sql = " AND ts.mannschaft_id IN ($in)";
            }
        }

        // Letzte Anwesenheits-Session
        $letzte_anw = $wpdb->get_row(
            "SELECT a.id, a.training_datum, a.slot_id, g.name AS mannschaft_name,
                    (SELECT COUNT(*) FROM {$p}lsv07i_anwesenheit_eintraege e
                       WHERE e.anwesenheit_id = a.id AND e.teilnehmer_typ = 'schwimmer'
                         AND e.status = 'anwesend') AS anwesend_count,
                    (SELECT COUNT(*) FROM {$p}lsv07i_anwesenheit_eintraege e
                       WHERE e.anwesenheit_id = a.id AND e.teilnehmer_typ = 'schwimmer') AS gesamt_count
               FROM {$p}lsv07i_anwesenheit a
               JOIN {$p}lsv07i_training_slots ts ON ts.id = a.slot_id
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = ts.mannschaft_id
              WHERE 1=1 $mann_filter_sql
           ORDER BY a.training_datum DESC, a.id DESC
              LIMIT 1",
            ARRAY_A
        );

        // Offene Springer-Anfragen (Slots ohne ausreichend Trainer in der Zukunft, max 14 Tage)
        $heute      = current_time( 'Y-m-d' );
        $bis        = date( 'Y-m-d', strtotime( '+14 days', strtotime( $heute ) ) );
        $offene_spr = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_springer
              WHERE training_datum BETWEEN %s AND %s
                AND trainer_id IS NULL",
            $heute, $bis
        ) );

        // Aktuelles Quartal des Trainers (eigene Abrechnung)
        $quartal = 'Q' . ceil( (int) date( 'n' ) / 3 );
        $jahr    = (int) date( 'Y' );
        $abr     = null;
        if ( $trainer_id ) {
            $abr = $wpdb->get_row( $wpdb->prepare(
                "SELECT a.id, a.abr_status,
                        COALESCE((SELECT SUM(ts.stunden * ts2.stundensatz)
                                    FROM {$p}lsv07i_abr_trainingstage ts
                                    JOIN {$p}lsv07i_trainer_stammdaten ts2 ON ts2.trainer_id = a.trainer_id
                                   WHERE ts.abrechnung_id = a.id AND ts.manuell_geloescht = 0), 0)
                      + COALESCE((SELECT SUM(betrag) FROM {$p}lsv07i_abr_wettkampf
                                   WHERE abrechnung_id = a.id AND manuell_geloescht = 0), 0)
                      + COALESCE((SELECT SUM(betrag) FROM {$p}lsv07i_abr_kilometer
                                   WHERE abrechnung_id = a.id), 0)
                        AS summe
                   FROM {$p}lsv07i_abrechnung a
                  WHERE a.trainer_id = %d AND a.bereich = 'schwimmen'
                    AND a.quartal = %s AND a.jahr = %d LIMIT 1",
                $trainer_id, $quartal, $jahr
            ), ARRAY_A );
        }

        // Nächster Wettkampf (im Bereich der Mannschaften des Trainers)
        $next_wk = null;
        if ( $sieht_alle ) {
            $next_wk = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, name, datum_von, datum_bis FROM {$p}lsv07i_wettkampf
                  WHERE datum_bis >= %s ORDER BY datum_von ASC LIMIT 1",
                $heute
            ), ARRAY_A );
        } elseif ( $trainer_id ) {
            $next_wk = $wpdb->get_row( $wpdb->prepare(
                "SELECT DISTINCT w.id, w.name, w.datum_von, w.datum_bis
                   FROM {$p}lsv07i_wettkampf w
                   JOIN {$p}lsv07i_wettkampf_mannschaft wm ON wm.wettkampf_id = w.id
                   JOIN {$p}lsv07i_trainer_mannschaft tm
                        ON tm.mannschaft_id = wm.mannschaft_id AND tm.trainer_id = %d
                  WHERE w.datum_bis >= %s
               ORDER BY w.datum_von ASC LIMIT 1",
                $trainer_id, $heute
            ), ARRAY_A );
        }

        // Anzahl Mannschaften (eigene oder alle)
        $anz_mann = $sieht_alle
            ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}lsv07_gruppen WHERE aktiv = 1" )
            : (int) ( $trainer_id ? $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}lsv07i_trainer_mannschaft WHERE trainer_id = %d",
                $trainer_id
            ) ) : 0 );

        return [
            'letzte_anwesenheit'  => $letzte_anw,
            'offene_springer'     => $offene_spr,
            'abrechnung'          => $abr,
            'naechster_wettkampf' => $next_wk,
            'anz_mannschaften'    => $anz_mann,
            'quartal'             => $quartal,
            'jahr'                => $jahr,
        ];
    }

    private static function stats_triathlon( $tri_tid, $sieht_alle ) {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! LSV07I_Access::is_tri_intern() ) return null;

        // Letzte Anwesenheit
        $gruppen_filter = '';
        if ( ! $sieht_alle && $tri_tid ) {
            $gids = $wpdb->get_col( $wpdb->prepare(
                "SELECT gruppe_id FROM {$p}lsv07i_tri_trainer_gruppe WHERE trainer_id = %d",
                $tri_tid
            ) );
            if ( empty( $gids ) ) {
                $gruppen_filter = ' AND 1=0';
            } else {
                $in = implode( ',', array_map( 'absint', $gids ) );
                $gruppen_filter = " AND s.gruppe_id IN ($in)";
            }
        }

        $letzte_anw = $wpdb->get_row(
            "SELECT a.id, a.training_datum, a.slot_id, g.name AS gruppe_name,
                    (SELECT COUNT(*) FROM {$p}lsv07i_tri_anwesenheit_eintraege e
                       WHERE e.anwesenheit_id = a.id AND e.teilnehmer_typ = 'sportler'
                         AND e.status = 'anwesend') AS anwesend_count,
                    (SELECT COUNT(*) FROM {$p}lsv07i_tri_anwesenheit_eintraege e
                       WHERE e.anwesenheit_id = a.id AND e.teilnehmer_typ = 'sportler') AS gesamt_count
               FROM {$p}lsv07i_tri_anwesenheit a
               JOIN {$p}lsv07i_tri_slots s ON s.id = a.slot_id
          LEFT JOIN {$p}lsv07i_tri_gruppen g ON g.id = s.gruppe_id
              WHERE 1=1 $gruppen_filter
           ORDER BY a.training_datum DESC, a.id DESC LIMIT 1",
            ARRAY_A
        );

        // Aktuelle Triathlon-Abrechnung
        $quartal = 'Q' . ceil( (int) date( 'n' ) / 3 );
        $jahr    = (int) date( 'Y' );
        $abr     = null;
        if ( $tri_tid ) {
            $abr = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, abr_status FROM {$p}lsv07i_abrechnung
                  WHERE trainer_id = %d AND bereich = 'triathlon'
                    AND quartal = %s AND jahr = %d LIMIT 1",
                $tri_tid, $quartal, $jahr
            ), ARRAY_A );
        }

        $anz_gruppen = $sieht_alle
            ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}lsv07i_tri_gruppen WHERE aktiv = 1" )
            : (int) ( $tri_tid ? $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}lsv07i_tri_trainer_gruppe WHERE trainer_id = %d",
                $tri_tid
            ) ) : 0 );

        return [
            'letzte_anwesenheit' => $letzte_anw,
            'abrechnung'         => $abr,
            'anz_gruppen'        => $anz_gruppen,
            'quartal'            => $quartal,
            'jahr'               => $jahr,
        ];
    }

    private static function stats_fitness( $fit_tid, $sieht_alle ) {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! LSV07I_Access::is_fit_intern() ) return null;

        // Existieren die Tabellen schon? (Defensiv für Erstinstall)
        $tab = $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_fit_anwesenheit'" );
        if ( ! $tab ) return [
            'letzte_anwesenheit' => null,
            'abrechnung'         => null,
            'anz_gruppen'        => 0,
            'quartal'            => 'Q' . ceil( (int) date( 'n' ) / 3 ),
            'jahr'               => (int) date( 'Y' ),
        ];

        $gruppen_filter = '';
        if ( ! $sieht_alle && $fit_tid ) {
            $gids = $wpdb->get_col( $wpdb->prepare(
                "SELECT gruppe_id FROM {$p}lsv07i_fit_trainer_gruppe WHERE trainer_id = %d",
                $fit_tid
            ) );
            if ( empty( $gids ) ) {
                $gruppen_filter = ' AND 1=0';
            } else {
                $in = implode( ',', array_map( 'absint', $gids ) );
                $gruppen_filter = " AND s.gruppe_id IN ($in)";
            }
        }

        $letzte_anw = $wpdb->get_row(
            "SELECT a.id, a.training_datum, a.slot_id, g.name AS gruppe_name,
                    (SELECT COUNT(*) FROM {$p}lsv07i_fit_anwesenheit_eintraege e
                       WHERE e.anwesenheit_id = a.id
                         AND e.status = 'anwesend') AS anwesend_count,
                    (SELECT COUNT(*) FROM {$p}lsv07i_fit_anwesenheit_eintraege e
                       WHERE e.anwesenheit_id = a.id) AS gesamt_count
               FROM {$p}lsv07i_fit_anwesenheit a
          LEFT JOIN {$p}lsv07i_fit_slots s ON s.id = a.slot_id
          LEFT JOIN {$p}lsv07i_fit_gruppen g ON g.id = a.gruppe_id
              WHERE 1=1 $gruppen_filter
           ORDER BY a.training_datum DESC, a.id DESC LIMIT 1",
            ARRAY_A
        );

        $quartal = 'Q' . ceil( (int) date( 'n' ) / 3 );
        $jahr    = (int) date( 'Y' );
        $abr     = null;
        if ( $fit_tid ) {
            $abr = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, abr_status FROM {$p}lsv07i_abrechnung
                  WHERE trainer_id = %d AND bereich = 'fitness'
                    AND quartal = %s AND jahr = %d LIMIT 1",
                $fit_tid, $quartal, $jahr
            ), ARRAY_A );
        }

        $anz_gruppen = $sieht_alle
            ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}lsv07i_fit_gruppen WHERE aktiv = 1" )
            : (int) ( $fit_tid ? $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}lsv07i_fit_trainer_gruppe WHERE trainer_id = %d",
                $fit_tid
            ) ) : 0 );

        return [
            'letzte_anwesenheit' => $letzte_anw,
            'abrechnung'         => $abr,
            'anz_gruppen'        => $anz_gruppen,
            'quartal'            => $quartal,
            'jahr'               => $jahr,
        ];
    }
}
