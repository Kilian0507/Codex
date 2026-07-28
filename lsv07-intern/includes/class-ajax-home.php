<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Home
 * ----------------
 * Liefert die Daten der Startseite:
 *  - lsv07i_home_widgets_get / _save  Schnellzugriffe des Nutzers
 *  - lsv07i_home_team_stats           letzte 5 Trainings einer Gruppe
 *  - lsv07i_home_top_swimmers         Top 5 nach Bestzeiten
 *  - lsv07i_home_uebersicht           Abrechnung, Wettkampf, Training
 *
 * Die Werte sind rollenabhängig — ein normaler Trainer sieht nur
 * seine eigenen Gruppen/Daten, Admin bzw. Wart sehen alles.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Home {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_home_widgets_get', [ __CLASS__, 'widgets_get' ] );
        add_action( 'wp_ajax_lsv07i_home_widgets_save', [ __CLASS__, 'widgets_save' ] );
        add_action( 'wp_ajax_lsv07i_home_team_stats',    [ __CLASS__, 'team_stats' ] );
        add_action( 'wp_ajax_lsv07i_home_top_swimmers',  [ __CLASS__, 'top_swimmers' ] );
        add_action( 'wp_ajax_lsv07i_home_uebersicht',    [ __CLASS__, 'uebersicht' ] );
    }

    /**
     * Liste der für den aktuellen Nutzer verfügbaren Widgets (Quicklinks),
     * gefiltert auf seine Berechtigung. Pro Bereich gruppiert.
     */
    private static function available_widgets() {
        // Jeder Eintrag: id, bereich-key, label, kurz (Beschriftung unter dem
        // Symbol auf der Startseite), sub, target (jump+tab)
        $all = [
            // SCHWIMMEN
            [ 'id'=>'s.mann',  'bereich'=>'s',   'label'=>'Mannschaftsverwaltung', 'kurz'=>'Mannschaften', 'sub'=>'Schwimmer & Trainer',     'jump'=>'s',   'tab'=>'i-p-mann' ],
            [ 'id'=>'s.anw',   'bereich'=>'s',   'label'=>'Anwesenheit',           'kurz'=>'Anwesenheit',  'sub'=>'Training & Wettkämpfe',   'jump'=>'s',   'tab'=>'i-p-anw'  ],
            [ 'id'=>'s.wk',    'bereich'=>'s',   'label'=>'Wettkämpfe',            'kurz'=>'Wettkämpfe',   'sub'=>'Termine & Ergebnisse',    'jump'=>'s',   'tab'=>'i-p-wk'   ],
            [ 'id'=>'s.bz',    'bereich'=>'s',   'label'=>'Bestzeiten',            'kurz'=>'Bestzeiten',   'sub'=>'Zeiten importieren',      'jump'=>'s',   'tab'=>'i-p-bz'   ],
            [ 'id'=>'s.spr',   'bereich'=>'s',   'label'=>'Springer',              'kurz'=>'Springer',     'sub'=>'Vertretungen finden',     'jump'=>'s',   'tab'=>'i-p-spr'  ],
            [ 'id'=>'s.refl',  'bereich'=>'s',   'label'=>'Reflexionsbögen',       'kurz'=>'Reflexion',    'sub'=>'Saison-Reflexion',        'jump'=>'s',   'tab'=>'i-p-refl' ],
            // TRIATHLON
            [ 'id'=>'tri.mann','bereich'=>'tri', 'label'=>'Gruppen & Sportler',    'kurz'=>'Gruppen',      'sub'=>'Triathlon verwalten',     'jump'=>'tri', 'tab'=>'i-p-tri-mann' ],
            [ 'id'=>'tri.anw', 'bereich'=>'tri', 'label'=>'Anwesenheit (Tri)',     'kurz'=>'Anwesenheit',  'sub'=>'Training erfassen',       'jump'=>'tri', 'tab'=>'i-p-tri-anw'  ],
            // FITNESS
            [ 'id'=>'fit.mann','bereich'=>'fit', 'label'=>'Gruppen & Sportler',    'kurz'=>'Gruppen',      'sub'=>'Fitness verwalten',       'jump'=>'fit', 'tab'=>'i-p-fit-mann' ],
            [ 'id'=>'fit.anw', 'bereich'=>'fit', 'label'=>'Anwesenheit (Fitness)', 'kurz'=>'Anwesenheit',  'sub'=>'Training erfassen',       'jump'=>'fit', 'tab'=>'i-p-fit-anw'  ],
            // ABRECHNUNG / VERWALTUNG / NACHRICHTEN
            [ 'id'=>'abr',     'bereich'=>'t',   'label'=>'Abrechnungen',          'kurz'=>'Abrechnung',   'sub'=>'Trainer-Abrechnungen',    'jump'=>'t',   'tab'=>''        ],
            [ 'id'=>'v.pers',  'bereich'=>'v',   'label'=>'Personenverwaltung',    'kurz'=>'Personen',     'sub'=>'Mitglieder & Trainer',    'jump'=>'v',   'tab'=>'i-p-pers' ],
            [ 'id'=>'n.inbox', 'bereich'=>'n',   'label'=>'Posteingang',           'kurz'=>'Nachrichten',  'sub'=>'Interne Nachrichten',     'jump'=>'n',   'tab'=>''        ],
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

    // ─────────────────────────────────────────────────────────────────────
    //  KACHELN DER NEUEN STARTSEITE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Alle Gruppen, auf die der Nutzer Zugriff hat — über alle drei Sparten.
     * Admin bzw. der jeweilige Wart sieht alles, ein Trainer nur die eigenen.
     * Jeder Eintrag trägt seine Sparte, damit die Statistik-Abfrage weiß,
     * welche Tabellen sie befragen muss.
     */
    private static function eigene_gruppen() {
        global $wpdb;
        $p   = $wpdb->prefix;
        $out = [];

        // ── Schwimmen ────────────────────────────────────────────────────
        if ( LSV07I_Access::is_intern() ) {
            $alle = LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart();
            $tid  = (int) LSV07I_Access::get_trainer_id();
            $rows = [];
            if ( $alle ) {
                $rows = $wpdb->get_results(
                    "SELECT id, name FROM {$p}lsv07_gruppen ORDER BY sort_order ASC, name ASC", ARRAY_A );
            } elseif ( $tid ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT g.id, g.name FROM {$p}lsv07_gruppen g
                       JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.mannschaft_id = g.id
                      WHERE tm.trainer_id = %d
                   ORDER BY g.sort_order ASC, g.name ASC", $tid ), ARRAY_A );
            }
            foreach ( (array) $rows as $r ) {
                $out[] = [ 'sparte' => 'schwimmen', 'id' => (int) $r['id'], 'name' => $r['name'] ];
            }
        }

        // ── Triathlon ────────────────────────────────────────────────────
        if ( LSV07I_Access::is_tri_intern() ) {
            $alle = LSV07I_Access::is_admin() || LSV07I_Access::is_triathlonwart();
            $tid  = (int) LSV07I_Access::get_tri_trainer_id();
            $rows = [];
            if ( $alle ) {
                $rows = $wpdb->get_results(
                    "SELECT id, name FROM {$p}lsv07i_tri_gruppen ORDER BY name ASC", ARRAY_A );
            } elseif ( $tid ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT g.id, g.name FROM {$p}lsv07i_tri_gruppen g
                       JOIN {$p}lsv07i_tri_trainer_gruppe tg ON tg.gruppe_id = g.id
                      WHERE tg.trainer_id = %d ORDER BY g.name ASC", $tid ), ARRAY_A );
            }
            foreach ( (array) $rows as $r ) {
                $out[] = [ 'sparte' => 'triathlon', 'id' => (int) $r['id'], 'name' => $r['name'] ];
            }
        }

        // ── Fitness ──────────────────────────────────────────────────────
        if ( LSV07I_Access::is_fit_intern() ) {
            $alle = LSV07I_Access::is_admin() || LSV07I_Access::is_fitnesswart();
            $tid  = (int) LSV07I_Access::get_fit_trainer_id();
            $rows = [];
            if ( $alle ) {
                $rows = $wpdb->get_results(
                    "SELECT id, name FROM {$p}lsv07i_fit_gruppen ORDER BY name ASC", ARRAY_A );
            } elseif ( $tid ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT g.id, g.name FROM {$p}lsv07i_fit_gruppen g
                       JOIN {$p}lsv07i_fit_trainer_gruppe tg ON tg.gruppe_id = g.id
                      WHERE tg.trainer_id = %d ORDER BY g.name ASC", $tid ), ARRAY_A );
            }
            foreach ( (array) $rows as $r ) {
                $out[] = [ 'sparte' => 'fitness', 'id' => (int) $r['id'], 'name' => $r['name'] ];
            }
        }

        return $out;
    }

    /** Nur die Schwimm-Mannschaften (für Bestzeiten-Auswertung). */
    private static function eigene_mannschaften() {
        return array_values( array_filter( self::eigene_gruppen(),
            fn( $g ) => $g['sparte'] === 'schwimmen' ) );
    }

    /**
     * Die letzten 5 Trainings einer Gruppe mit Anwesend/Gesamt je Termin.
     * Liefert zusätzlich die Gruppenliste für den Umschalter in der Kachel.
     */
    public static function team_stats() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $gruppen = self::eigene_gruppen();
        if ( empty( $gruppen ) ) {
            wp_send_json_success( [ 'gruppen' => [], 'auswahl' => '', 'trainings' => [] ] );
        }

        // Auswahl kommt als "sparte:id" — ohne Angabe die zuletzt gemerkte
        $wunsch = sanitize_text_field( (string) ( $_POST['auswahl'] ?? '' ) );
        if ( $wunsch === '' ) {
            $wunsch = (string) get_user_meta( get_current_user_id(), 'lsv07i_home_gruppe', true );
        }
        $treffer = null;
        foreach ( $gruppen as $g ) {
            if ( $g['sparte'] . ':' . $g['id'] === $wunsch ) { $treffer = $g; break; }
        }
        if ( ! $treffer ) {
            $treffer = $gruppen[0];
        } else {
            update_user_meta( get_current_user_id(), 'lsv07i_home_gruppe', $wunsch );
        }

        // Je Sparte die passenden Tabellen
        $karte = [
            'schwimmen' => [ "{$p}lsv07i_anwesenheit",     "{$p}lsv07i_anwesenheit_eintraege",     'mannschaft_id', 'schwimmer' ],
            'triathlon' => [ "{$p}lsv07i_tri_anwesenheit", "{$p}lsv07i_tri_anwesenheit_eintraege", 'gruppe_id',     'sportler'  ],
            'fitness'   => [ "{$p}lsv07i_fit_anwesenheit", "{$p}lsv07i_fit_anwesenheit_eintraege", 'gruppe_id',     'sportler'  ],
        ];
        [ $tbl_anw, $tbl_eint, $spalte, $typ ] = $karte[ $treffer['sparte'] ];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.training_datum, a.ausgefallen,
                    (SELECT COUNT(*) FROM $tbl_eint e
                      WHERE e.anwesenheit_id = a.id AND e.teilnehmer_typ = %s
                        AND e.status = 'anwesend') AS anwesend,
                    (SELECT COUNT(*) FROM $tbl_eint e
                      WHERE e.anwesenheit_id = a.id AND e.teilnehmer_typ = %s) AS gesamt
               FROM $tbl_anw a
              WHERE a.$spalte = %d
           ORDER BY a.training_datum DESC, a.id DESC
              LIMIT 5", $typ, $typ, $treffer['id'] ), ARRAY_A );

        // Älteste zuerst, damit die Balken zeitlich von links nach rechts laufen
        $trainings = array_reverse( (array) $rows );
        foreach ( $trainings as &$t ) {
            $t['id']          = (int) $t['id'];
            $t['anwesend']    = (int) $t['anwesend'];
            $t['gesamt']      = (int) $t['gesamt'];
            $t['ausgefallen'] = (int) $t['ausgefallen'];
            $t['quote']       = $t['gesamt'] > 0 ? (int) round( $t['anwesend'] / $t['gesamt'] * 100 ) : 0;
        }
        unset( $t );

        $liste = array_map( fn( $g ) => [
            'wert'   => $g['sparte'] . ':' . $g['id'],
            'name'   => $g['name'],
            'sparte' => $g['sparte'],
        ], $gruppen );

        wp_send_json_success( [
            'gruppen'   => $liste,
            'auswahl'   => $treffer['sparte'] . ':' . $treffer['id'],
            'trainings' => array_values( $trainings ),
        ] );
    }

    /**
     * Die 5 besten Schwimmer nach Bestzeiten. Gewertet wird, wie oft ein
     * Schwimmer innerhalb seiner Mannschaft auf einer Strecke vorne liegt:
     * ein 1. Platz zählt 3 Punkte, ein 2. Platz 2, ein 3. Platz 1.
     * So entsteht aus den streckenweisen Zeiten eine Gesamtrangliste.
     */
    public static function top_swimmers() {
        LSV07I_Access::check( 'schwimmen_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaften = self::eigene_mannschaften();
        if ( empty( $mannschaften ) ) wp_send_json_success( [ 'swimmer' => [] ] );
        $mids = implode( ',', array_map( fn( $m ) => (int) $m['id'], $mannschaften ) );

        // Alle Bestzeiten der erlaubten Mannschaften, je Strecke aufsteigend
        $rows = $wpdb->get_results(
            "SELECT b.swimmer_id, b.swimmer_name, b.mannschaft_id, b.strecke, b.zeit_sek,
                    g.name AS mannschaft_name
               FROM {$p}lsv07i_bestzeiten b
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = b.mannschaft_id
              WHERE b.mannschaft_id IN ($mids) AND b.zeit_sek > 0
           ORDER BY b.mannschaft_id ASC, b.strecke ASC, b.zeit_sek ASC", ARRAY_A );
        if ( empty( $rows ) ) wp_send_json_success( [ 'swimmer' => [] ] );

        // Je Mannschaft und Strecke die ersten drei Plätze werten
        $punkte_je_platz = [ 3, 2, 1 ];
        $wertung = [];
        $aktuell = '';
        $platz   = 0;
        foreach ( $rows as $r ) {
            $gruppe = $r['mannschaft_id'] . '|' . $r['strecke'];
            if ( $gruppe !== $aktuell ) { $aktuell = $gruppe; $platz = 0; }
            if ( $platz >= 3 ) continue;

            $key = $r['swimmer_id'] > 0 ? 's' . (int) $r['swimmer_id'] : 'n' . $r['swimmer_name'];
            if ( ! isset( $wertung[ $key ] ) ) {
                $wertung[ $key ] = [
                    'swimmer_id'      => (int) $r['swimmer_id'],
                    'name'            => $r['swimmer_name'],
                    'mannschaft_name' => $r['mannschaft_name'] ?: '',
                    'punkte'          => 0,
                    'erste_plaetze'   => 0,
                    'strecken'        => 0,
                ];
            }
            $wertung[ $key ]['punkte']   += $punkte_je_platz[ $platz ];
            $wertung[ $key ]['strecken'] += 1;
            if ( $platz === 0 ) $wertung[ $key ]['erste_plaetze'] += 1;
            $platz++;
        }

        $liste = array_values( $wertung );
        usort( $liste, function( $a, $b ) {
            if ( $b['punkte'] !== $a['punkte'] ) return $b['punkte'] <=> $a['punkte'];
            if ( $b['erste_plaetze'] !== $a['erste_plaetze'] ) return $b['erste_plaetze'] <=> $a['erste_plaetze'];
            return strcasecmp( $a['name'], $b['name'] );
        } );

        wp_send_json_success( [ 'swimmer' => array_slice( $liste, 0, 5 ) ] );
    }

    /**
     * Kompakte Infos für die drei kleinen Kacheln unten:
     * eigener Abrechnungsbetrag im laufenden Quartal, nächster Wettkampf,
     * nächstes Training.
     */
    public static function uebersicht() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p     = $wpdb->prefix;
        $heute = current_time( 'Y-m-d' );

        // ── Abrechnungen des laufenden Quartals über alle Sparten ─────────
        // Ein Trainer kann in mehreren Sparten abrechnen; die Kachel zeigt die
        // Summe. Der Status wird nur gezeigt, wenn alle Abrechnungen denselben
        // haben — sonst wäre die Angabe irreführend.
        $quartal  = 'Q' . (int) ceil( (int) current_time( 'n' ) / 3 );
        $jahr     = (int) current_time( 'Y' );
        $summe    = 0.0;
        $status   = '';
        $anz_abr  = 0;
        $tids = [
            'schwimmen' => (int) LSV07I_Access::get_trainer_id(),
            'triathlon' => (int) LSV07I_Access::get_tri_trainer_id(),
            'fitness'   => (int) LSV07I_Access::get_fit_trainer_id(),
        ];
        $status_set = [];
        foreach ( $tids as $bereich => $tid ) {
            if ( ! $tid ) continue;
            $abr = $wpdb->get_row( $wpdb->prepare(
                "SELECT a.id, a.abr_status,
                        COALESCE((SELECT SUM(t.stunden * sd.stundensatz)
                                    FROM {$p}lsv07i_abr_trainingstage t
                                    JOIN {$p}lsv07i_trainer_stammdaten sd ON sd.trainer_id = a.trainer_id
                                   WHERE t.abrechnung_id = a.id AND t.manuell_geloescht = 0), 0)
                      + COALESCE((SELECT SUM(betrag) FROM {$p}lsv07i_abr_wettkampf
                                   WHERE abrechnung_id = a.id AND manuell_geloescht = 0), 0)
                      + COALESCE((SELECT SUM(betrag) FROM {$p}lsv07i_abr_kilometer
                                   WHERE abrechnung_id = a.id), 0) AS summe
                   FROM {$p}lsv07i_abrechnung a
                  WHERE a.trainer_id = %d AND a.bereich = %s
                    AND a.quartal = %s AND a.jahr = %d
               ORDER BY a.id DESC LIMIT 1",
                $tid, $bereich, $quartal, $jahr ), ARRAY_A );
            if ( ! $abr ) continue;
            $summe += (float) $abr['summe'];
            $status_set[ (string) $abr['abr_status'] ] = true;
            $anz_abr++;
        }
        if ( count( $status_set ) === 1 ) $status = (string) array_key_first( $status_set );

        // ── Nächster Wettkampf ────────────────────────────────────────────
        $next_wk    = null;
        $sieht_alle = LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart();
        if ( LSV07I_Access::is_intern() ) {
            if ( $sieht_alle ) {
                $next_wk = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, name, datum_von, datum_bis, ort FROM {$p}lsv07i_wettkampf
                      WHERE datum_bis >= %s ORDER BY datum_von ASC LIMIT 1", $heute ), ARRAY_A );
            } elseif ( $tid ) {
                $next_wk = $wpdb->get_row( $wpdb->prepare(
                    "SELECT DISTINCT w.id, w.name, w.datum_von, w.datum_bis, w.ort
                       FROM {$p}lsv07i_wettkampf w
                       JOIN {$p}lsv07i_wettkampf_mannschaft wm ON wm.wettkampf_id = w.id
                       JOIN {$p}lsv07i_trainer_mannschaft tm
                            ON tm.mannschaft_id = wm.mannschaft_id AND tm.trainer_id = %d
                      WHERE w.datum_bis >= %s
                   ORDER BY w.datum_von ASC LIMIT 1", $tid, $heute ), ARRAY_A );
            }
        }

        // ── Nächstes Training aus den Wochen-Slots errechnen ──────────────
        // Die Slots beschreiben nur Wochentag + Uhrzeit; daraus wird der
        // nächstgelegene echte Termin gebildet — über alle Sparten hinweg.
        $next_training = null;
        $gruppen = self::eigene_gruppen();
        if ( ! empty( $gruppen ) ) {
            $nach_sparte = [];
            foreach ( $gruppen as $g ) $nach_sparte[ $g['sparte'] ][] = (int) $g['id'];

            $slot_karte = [
                'schwimmen' => [ "{$p}lsv07i_training_slots", 'mannschaft_id', "{$p}lsv07_gruppen" ],
                'triathlon' => [ "{$p}lsv07i_tri_slots",      'gruppe_id',     "{$p}lsv07i_tri_gruppen" ],
                'fitness'   => [ "{$p}lsv07i_fit_slots",      'gruppe_id',     "{$p}lsv07i_fit_gruppen" ],
            ];

            $jetzt    = strtotime( current_time( 'Y-m-d H:i:s' ) );
            $heute_wt = (int) current_time( 'N' );   // 1 = Montag … 7 = Sonntag
            $bester   = null;

            foreach ( $nach_sparte as $sparte => $ids ) {
                if ( ! isset( $slot_karte[ $sparte ] ) ) continue;
                [ $tbl_slot, $spalte, $tbl_gruppe ] = $slot_karte[ $sparte ];
                $in    = implode( ',', array_map( 'absint', $ids ) );
                $slots = $wpdb->get_results(
                    "SELECT s.wochentag, s.zeit_von, s.zeit_bis, g.name AS gruppe_name
                       FROM $tbl_slot s
                  LEFT JOIN $tbl_gruppe g ON g.id = s.$spalte
                      WHERE s.$spalte IN ($in)", ARRAY_A );

                foreach ( (array) $slots as $sl ) {
                    $wt = (int) $sl['wochentag'];
                    if ( $wt < 1 || $wt > 7 ) continue;
                    $diff = ( $wt - $heute_wt + 7 ) % 7;
                    $ts   = strtotime( current_time( 'Y-m-d' ) . ' +' . $diff . ' days ' . $sl['zeit_von'] );
                    if ( ! $ts ) continue;
                    if ( $ts < $jetzt ) $ts = strtotime( '+7 days', $ts );  // heute schon vorbei
                    if ( $bester === null || $ts < $bester['ts'] ) {
                        $bester = [
                            'ts'       => $ts,
                            'gruppe'   => $sl['gruppe_name'] ?: '',
                            'sparte'   => $sparte,
                            'zeit_von' => substr( (string) $sl['zeit_von'], 0, 5 ),
                            'zeit_bis' => substr( (string) $sl['zeit_bis'], 0, 5 ),
                        ];
                    }
                }
            }
            if ( $bester ) {
                $next_training = [
                    'datum'    => date( 'Y-m-d', $bester['ts'] ),
                    'gruppe'   => $bester['gruppe'],
                    'sparte'   => $bester['sparte'],
                    'zeit_von' => $bester['zeit_von'],
                    'zeit_bis' => $bester['zeit_bis'],
                ];
            }
        }

        wp_send_json_success( [
            'abrechnung' => [
                'summe'    => round( $summe, 2 ),
                'status'   => $status,
                'quartal'  => $quartal,
                'jahr'     => $jahr,
                'vorhanden'=> $anz_abr > 0,
            ],
            'naechster_wettkampf' => $next_wk,
            'naechstes_training'  => $next_training,
        ] );
    }

}
