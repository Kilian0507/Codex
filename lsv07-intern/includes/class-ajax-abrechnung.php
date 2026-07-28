<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Abrechnung {

    public static function init() {
        $actions = [
            'lsv07i_abr_get_or_create',
            'lsv07i_abr_save_trainingstag',
            'lsv07i_abr_delete_trainingstag',
            'lsv07i_abr_save_wettkampf',
            'lsv07i_abr_delete_wettkampf',
            'lsv07i_abr_save_kilometer',
            'lsv07i_abr_delete_kilometer',
            'lsv07i_abr_einreichen',
            'lsv07i_abr_genehmigen',
            'lsv07i_abr_zurueckgeben',
            'lsv07i_abr_get_alle',
            'lsv07i_abr_save_stammdaten',
            'lsv07i_abr_get_stammdaten',
            'lsv07i_abr_delete_abrechnung',
            'lsv07i_abr_bezahlt',
            'lsv07i_abr_jahresuebersicht',
            'lsv07i_abr_kosten_mannschaft',
            'lsv07i_abr_csv_export',
            'lsv07i_abr_toggle_vorbereitung',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_abr_', '', $action ) ] );
        }
    }

    // ── Komplette Abrechnung laden ────────────────────────────────────────────
    public static function load_abrechnung( $id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, t.name AS trainer_name
               FROM {$p}lsv07i_abrechnung a
          LEFT JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
              WHERE a.id = %d LIMIT 1",
            $id
        ), ARRAY_A );
        if ( ! $abr ) return null;

        $abr['trainingstage'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_trainingstage WHERE abrechnung_id = %d AND manuell_geloescht = 0 ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        $abr['wettkampf'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_wettkampf
              WHERE abrechnung_id = %d AND manuell_geloescht = 0
           ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        $abr['kilometer'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_kilometer WHERE abrechnung_id = %d ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        // Sonderabrechnung-Einträge (Tag+Stunden). Für die normalen Sparten
        // (schwimmen/triathlon/fitness) bleibt diese Liste leer und geht mit
        // 0,00 € in die Summe ein — daher unten unbedingt (nicht nur für
        // bereich='sonder') berücksichtigt, ohne dass sich an den anderen
        // Beträgen etwas ändert.
        $abr['sonder'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_sonder WHERE abrechnung_id = %d AND manuell_geloescht = 0 ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        // Gesamt-Berechnung
        // Stundensatz: erst per trainer_id, dann Fallback über wp_user_id
        $stammdaten = $wpdb->get_row( $wpdb->prepare(
            "SELECT stundensatz
               FROM {$p}lsv07i_trainer_stammdaten WHERE trainer_id = %d LIMIT 1",
            $abr['trainer_id']
        ), ARRAY_A );
        if ( ! $stammdaten ) {
            $uid = get_current_user_id();
            if ( $uid ) {
                $stammdaten = $wpdb->get_row( $wpdb->prepare(
                    "SELECT ts.stundensatz
                       FROM {$p}lsv07i_trainer_stammdaten ts
                       JOIN {$p}lsv07i_trainer t ON t.id = ts.trainer_id
                      WHERE t.wp_user_id = %d AND t.aktiv = 1 LIMIT 1",
                    $uid
                ), ARRAY_A );
            }
        }
        $stundensatz  = $stammdaten ? (float) $stammdaten['stundensatz'] : 0.0;

        // Trainings-Stunden = Summe der Original-Stunden aus den Trainingstage-Einträgen
        $training_stunden  = (float) array_sum( array_column( $abr['trainingstage'], 'stunden' ) );
        $training_betrag   = round( $training_stunden * $stundensatz, 2 );

        // Vorbereitungs-Bonus: 0.25h pro Trainingstag, ABER nur für die einzeln
        // mit vorbereitung_aktiv=1 markierten Trainings. Wird separat ausgewiesen.
        $anzahl_trainings        = count( $abr['trainingstage'] );
        $anzahl_mit_vorbereitung = 0;
        foreach ( $abr['trainingstage'] as $t ) {
            if ( ! empty( $t['vorbereitung_aktiv'] ) ) $anzahl_mit_vorbereitung++;
        }
        $vorbereitung_stunden = round( $anzahl_mit_vorbereitung * 0.25, 2 );
        $vorbereitung_betrag  = round( $vorbereitung_stunden * $stundensatz, 2 );

        $wettkampf_betrag  = (float) array_sum( array_column( $abr['wettkampf'], 'betrag' ) );
        $km_betrag         = (float) array_sum( array_column( $abr['kilometer'], 'betrag' ) );
        $sonder_stunden    = (float) array_sum( array_column( $abr['sonder'], 'stunden' ) );
        $sonder_betrag     = round( $sonder_stunden * $stundensatz, 2 );
        $gesamt            = round( $training_betrag + $vorbereitung_betrag + $wettkampf_betrag + $km_betrag + $sonder_betrag, 2 );

        $abr['sonder_stunden']              = round( $sonder_stunden, 2 );
        $abr['sonder_betrag']               = $sonder_betrag;

        $abr['stundensatz']                = $stundensatz;
        $abr['training_stunden']           = round( $training_stunden, 2 );
        $abr['training_betrag']            = $training_betrag;
        $abr['anzahl_trainings']           = $anzahl_trainings;
        $abr['anzahl_mit_vorbereitung']    = $anzahl_mit_vorbereitung;
        $abr['vorbereitung_stunden']       = $vorbereitung_stunden;
        $abr['vorbereitung_betrag']        = $vorbereitung_betrag;
        $abr['wettkampf_betrag']           = round( $wettkampf_betrag, 2 );
        $abr['km_betrag']                  = round( $km_betrag, 2 );
        $abr['gesamt']                     = $gesamt;

        return $abr;
    }

    // ── Abrechnung laden / erstellen ──────────────────────────────────────────
    public static function get_or_create() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $trainer_id = self::get_or_create_trainer_id();
        if ( ! $trainer_id ) wp_send_json_error( [ 'message' => 'Kein Trainer-Profil gefunden. Bitte im Admin-Bereich ein Trainer-Profil anlegen.' ] );

        $quartal = sanitize_text_field( $_POST['quartal'] ?? 'Q' . ceil( date('n') / 3 ) );
        $jahr    = absint( $_POST['jahr'] ?? date('Y') );

        $bereich = sanitize_text_field( $_POST['bereich'] ?? 'schwimmen' );
        if ( ! in_array( $bereich, [ 'schwimmen', 'triathlon', 'fitness' ], true ) ) $bereich = 'schwimmen';

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_abrechnung WHERE trainer_id = %d AND bereich = %s AND quartal = %s AND jahr = %d LIMIT 1",
            $trainer_id, $bereich, $quartal, $jahr
        ) );

        if ( ! $id ) {
            $wpdb->insert( $p . 'lsv07i_abrechnung', [
                'trainer_id' => $trainer_id, 'bereich' => $bereich, 'quartal' => $quartal,
                'jahr' => $jahr, 'abr_status' => 'entwurf',
            ], [ '%d', '%s', '%s', '%d', '%s' ] );
            $id = $wpdb->insert_id;
        }

        // Anwesenheiten vorausfüllen
        self::prefill_trainingstage( $id, $trainer_id, $quartal, $jahr );
        self::prefill_wettkampf( $id, $trainer_id, $quartal, $jahr );

        // Config für Frontend mitschicken
        $abr = self::load_abrechnung( $id );
        $abr['config'] = [
            'km_satz'      => (float) LSV07I_DB::get_config( 'km_satz', 0.30 ),
            'km_min'       => (float) LSV07I_DB::get_config( 'km_min', 20 ),
            'wk_pauschale' => (float) LSV07I_DB::get_config( 'wk_pauschale', 25 ),
        ];
        $abr['mannschaften'] = LSV07I_DB::get_mannschaften();

        wp_send_json_success( $abr );
    }

    private static function prefill_trainingstage( $abr_id, $trainer_id, $quartal, $jahr ) {
        global $wpdb;
        $p           = $wpdb->prefix;
        $quartal_num = (int) substr( $quartal, 1 );
        $monat_von   = ( ( $quartal_num - 1 ) * 3 ) + 1;
        $datum_von   = sprintf( '%d-%02d-01', $jahr, $monat_von );
        $datum_bis   = date( 'Y-m-t', strtotime( sprintf( '%d-%02d-01', $jahr, $monat_von + 2 ) ) );

        // Anwesenheiten (Trainer als anwesend markiert)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.training_datum, g.name AS mannschaft_name,
                    TIMESTAMPDIFF(MINUTE, s.zeit_von, s.zeit_bis) / 60.0 AS stunden,
                    a.id AS anwesenheit_id
               FROM {$p}lsv07i_anwesenheit a
               JOIN {$p}lsv07i_training_slots s ON s.id = a.slot_id
               JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.mannschaft_id = a.mannschaft_id AND tm.trainer_id = %d
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = a.mannschaft_id
               JOIN {$p}lsv07i_anwesenheit_eintraege ae ON ae.anwesenheit_id = a.id
                    AND ae.teilnehmer_typ = 'trainer' AND ae.teilnehmer_id = %d AND ae.status = 'anwesend'
              WHERE a.training_datum BETWEEN %s AND %s AND a.ausgefallen = 0
                AND NOT EXISTS (SELECT 1 FROM {$p}lsv07i_abr_trainingstage t2
                                 WHERE t2.abrechnung_id = %d AND t2.anwesenheit_id = a.id)",
            $trainer_id, $trainer_id, $datum_von, $datum_bis, $abr_id
        ), ARRAY_A );

        foreach ( $rows as $r ) {
            $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                'abrechnung_id'   => $abr_id,
                'datum'           => $r['training_datum'],
                'mannschaft_name' => $r['mannschaft_name'],
                'stunden'         => round( max( 0.5, (float) $r['stunden'] ), 2 ),
                'quelle'          => 'anwesenheit',
                'anwesenheit_id'  => $r['anwesenheit_id'],
            ], [ '%d', '%s', '%s', '%f', '%s', '%d' ] );
        }

        // Springer-Schichten
        $spr = $wpdb->get_results( $wpdb->prepare(
            "SELECT sp.training_datum, g.name AS mannschaft_name,
                    TIMESTAMPDIFF(MINUTE, s.zeit_von, s.zeit_bis) / 60.0 AS stunden,
                    sp.id AS springer_id
               FROM {$p}lsv07i_springer sp
               JOIN {$p}lsv07i_training_slots s ON s.id = sp.slot_id
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id
              WHERE sp.trainer_id = %d AND sp.training_datum BETWEEN %s AND %s
                AND NOT EXISTS (SELECT 1 FROM {$p}lsv07i_abr_trainingstage t2
                                 WHERE t2.abrechnung_id = %d AND t2.springer_id = sp.id)",
            $trainer_id, $datum_von, $datum_bis, $abr_id
        ), ARRAY_A );

        foreach ( $spr as $r ) {
            $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                'abrechnung_id'   => $abr_id,
                'datum'           => $r['training_datum'],
                'mannschaft_name' => $r['mannschaft_name'],
                'stunden'         => round( max( 0.5, (float) $r['stunden'] ), 2 ),
                'quelle'          => 'springer',
                'springer_id'     => $r['springer_id'],
            ], [ '%d', '%s', '%s', '%f', '%s', '%d' ] );
        }
    }

    /**
     * Wettkampf-Tage, an denen der Trainer anwesend war, in die Abrechnung übernehmen.
     * Respektiert manuell_geloescht-Flag und bereits bestehende Einträge.
     */
    private static function prefill_wettkampf( $abr_id, $trainer_id, $quartal, $jahr ) {
        global $wpdb;
        $p           = $wpdb->prefix;
        $quartal_num = (int) substr( $quartal, 1 );
        $monat_von   = ( ( $quartal_num - 1 ) * 3 ) + 1;
        $datum_von   = sprintf( '%d-%02d-01', $jahr, $monat_von );
        $datum_bis   = date( 'Y-m-t', strtotime( sprintf( '%d-%02d-01', $jahr, $monat_von + 2 ) ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT t.id AS wettkampf_tag_id, t.datum, t.abschnitte_plan,
                    w.name AS wettkampf_name, w.pauschale,
                    a.mannschaft_id,
                    COALESCE(e.abschnitte, 0) AS abschnitte_erfasst
               FROM {$p}lsv07i_wettkampf_anw_eintraege e
               JOIN {$p}lsv07i_wettkampf_anwesenheit a ON a.id = e.wk_anwesenheit_id
               JOIN {$p}lsv07i_wettkampf_tage t        ON t.id = a.wettkampf_tag_id
               JOIN {$p}lsv07i_wettkampf w             ON w.id = t.wettkampf_id
              WHERE e.teilnehmer_typ = 'trainer'
                AND e.teilnehmer_id = %d
                AND e.status = 'anwesend'
                AND t.datum BETWEEN %s AND %s
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}lsv07i_abr_wettkampf aw
                     WHERE aw.abrechnung_id = %d AND aw.wettkampf_tag_id = t.id
                )",
            $trainer_id, $datum_von, $datum_bis, $abr_id
        ), ARRAY_A );

        foreach ( $rows as $r ) {
            $abschnitte = (int) $r['abschnitte_erfasst'];
            if ( ! $abschnitte ) $abschnitte = max( 1, (int) $r['abschnitte_plan'] );
            $pauschale  = (float) $r['pauschale'];
            if ( $pauschale <= 0 ) $pauschale = (float) LSV07I_DB::get_config( 'wk_pauschale', 25 );
            $betrag = round( $abschnitte * $pauschale, 2 );

            $wpdb->insert( $p . 'lsv07i_abr_wettkampf', [
                'abrechnung_id'    => $abr_id,
                'wettkampf_tag_id' => $r['wettkampf_tag_id'],
                'mannschaft_id'    => $r['mannschaft_id'],
                'datum'            => $r['datum'],
                'name'             => $r['wettkampf_name'],
                'abschnitte'       => $abschnitte,
                'pauschale'        => $pauschale,
                'betrag'           => $betrag,
                'quelle'           => 'anwesenheit',
            ], [ '%d', '%d', '%d', '%s', '%s', '%d', '%f', '%f', '%s' ] );
        }
    }

    // ── Trainingstag ──────────────────────────────────────────────────────────
    public static function save_trainingstag() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p               = $wpdb->prefix;
        $abr_id          = absint( $_POST['abrechnung_id'] ?? 0 );
        $id              = absint( $_POST['id'] ?? 0 );
        $datum           = sanitize_text_field( $_POST['datum'] ?? '' );
        $mannschaft_name = sanitize_text_field( $_POST['mannschaft_name'] ?? '' );
        $stunden         = round( (float) ( $_POST['stunden'] ?? 0 ), 2 );
        $begruendung     = sanitize_textarea_field( $_POST['begruendung'] ?? '' );

        if ( ! $abr_id || ! $datum || $stunden <= 0 ) {
            wp_send_json_error( [ 'message' => 'Bitte alle Pflichtfelder ausfüllen.' ] );
        }
        self::assert_owns( $abr_id );

        if ( $id ) {
            $wpdb->update( $p . 'lsv07i_abr_trainingstage',
                [ 'datum' => $datum, 'mannschaft_name' => $mannschaft_name, 'stunden' => $stunden, 'begruendung' => $begruendung ],
                [ 'id' => $id ], [ '%s', '%s', '%f', '%s' ], [ '%d' ]
            );
        } else {
            if ( ! $begruendung ) wp_send_json_error( [ 'message' => 'Bitte eine Begründung für manuell hinzugefügte Trainings angeben.' ] );
            $wpdb->insert( $p . 'lsv07i_abr_trainingstage',
                [ 'abrechnung_id' => $abr_id, 'datum' => $datum, 'mannschaft_name' => $mannschaft_name,
                  'stunden' => $stunden, 'quelle' => 'manuell', 'begruendung' => $begruendung ],
                [ '%d', '%s', '%s', '%f', '%s', '%s' ]
            );
        }
        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    public static function delete_trainingstag() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT abrechnung_id, quelle FROM {$p}lsv07i_abr_trainingstage WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Eintrag nicht gefunden.' ] );
        self::assert_owns( $row['abrechnung_id'] );

        // Auto-befüllte Einträge (aus Anwesenheit oder Springer) werden nur als gelöscht markiert,
        // damit prefill sie nicht beim nächsten Laden wieder einfügt.
        if ( in_array( $row['quelle'], [ 'anwesenheit', 'springer' ], true ) ) {
            $wpdb->update(
                $p . 'lsv07i_abr_trainingstage',
                [ 'manuell_geloescht' => 1 ],
                [ 'id' => $id ],
                [ '%d' ],
                [ '%d' ]
            );
        } else {
            // Manuell hinzugefügte Einträge können wirklich gelöscht werden.
            $wpdb->delete( $p . 'lsv07i_abr_trainingstage', [ 'id' => $id ], [ '%d' ] );
        }

        wp_send_json_success( self::load_abrechnung( $row['abrechnung_id'] ) );
    }

    // ── +15min-Vorbereitungs-Checkbox pro Trainingstag toggeln ────────────────
    public static function toggle_vorbereitung() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $on = ! empty( $_POST['on'] ) ? 1 : 0;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT abrechnung_id FROM {$p}lsv07i_abr_trainingstage WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Trainingstag nicht gefunden.' ] );
        self::assert_owns( $row['abrechnung_id'] );

        $wpdb->update(
            $p . 'lsv07i_abr_trainingstage',
            [ 'vorbereitung_aktiv' => $on ],
            [ 'id' => $id ],
            [ '%d' ], [ '%d' ]
        );
        wp_send_json_success( self::load_abrechnung( $row['abrechnung_id'] ) );
    }

    // ── Wettkampf ─────────────────────────────────────────────────────────────
    public static function save_wettkampf() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $abr_id     = absint( $_POST['abrechnung_id'] ?? 0 );
        $id         = absint( $_POST['id'] ?? 0 );
        $datum      = sanitize_text_field( $_POST['datum'] ?? '' );
        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $abschnitte = max( 1, absint( $_POST['abschnitte'] ?? 1 ) );
        $wk_tag_id  = absint( $_POST['wettkampf_tag_id'] ?? 0 );
        $mann_id    = absint( $_POST['mannschaft_id'] ?? 0 );

        if ( ! $abr_id || ! $datum ) wp_send_json_error( [ 'message' => 'Pflichtfelder fehlen.' ] );
        self::assert_owns( $abr_id );

        // Wenn ein Wettkampftag angegeben wurde: Pauschale und Name aus dem
        // Wettkampf-Snapshot laden (damit das konsistent bleibt)
        if ( $wk_tag_id ) {
            $wk = $wpdb->get_row( $wpdb->prepare(
                "SELECT w.name AS wettkampf_name, w.pauschale
                   FROM {$p}lsv07i_wettkampf_tage t
                   JOIN {$p}lsv07i_wettkampf w ON w.id = t.wettkampf_id
                  WHERE t.id = %d LIMIT 1",
                $wk_tag_id
            ), ARRAY_A );
            if ( $wk ) {
                if ( ! $name ) $name = $wk['wettkampf_name'];
                $pauschale = (float) $wk['pauschale'];
            }
        }
        if ( ! isset( $pauschale ) || $pauschale <= 0 ) {
            $pauschale = (float) LSV07I_DB::get_config( 'wk_pauschale', 25 );
        }
        $betrag = round( $abschnitte * $pauschale, 2 );

        $data = [ 'datum' => $datum, 'name' => $name, 'abschnitte' => $abschnitte,
                  'pauschale' => $pauschale, 'betrag' => $betrag ];

        if ( $id ) {
            // Bearbeiten (nur diese 5 Felder ändern)
            $wpdb->update( $p . 'lsv07i_abr_wettkampf', $data, [ 'id' => $id ],
                [ '%s', '%s', '%d', '%f', '%f' ], [ '%d' ] );
        } else {
            $data['abrechnung_id'] = $abr_id;
            // Wenn wettkampf_tag_id gegeben → Quelle 'anwesenheit' (offene Tage)
            // Sonst: reiner manueller Eintrag
            if ( $wk_tag_id ) {
                $data['wettkampf_tag_id'] = $wk_tag_id;
                $data['mannschaft_id']    = $mann_id;
                $data['quelle']           = 'anwesenheit';
            } else {
                $data['quelle']           = 'manuell';
            }
            $wpdb->insert( $p . 'lsv07i_abr_wettkampf', $data );
        }
        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    public static function delete_wettkampf() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_wettkampf WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Nicht gefunden.' ] );
        self::assert_owns( $row['abrechnung_id'] );

        // Automatisch erzeugte Einträge (aus der Wettkampf-Anwesenheit) werden
        // nur als gelöscht markiert, damit Statuswechsel sie nicht erneut anlegen.
        if ( ( $row['quelle'] ?? 'manuell' ) === 'anwesenheit' ) {
            $wpdb->update( $p . 'lsv07i_abr_wettkampf',
                [ 'manuell_geloescht' => 1 ],
                [ 'id' => $id ], [ '%d' ], [ '%d' ]
            );
        } else {
            $wpdb->delete( $p . 'lsv07i_abr_wettkampf', [ 'id' => $id ], [ '%d' ] );
        }
        wp_send_json_success( self::load_abrechnung( $row['abrechnung_id'] ) );
    }

    // ── Kilometer ─────────────────────────────────────────────────────────────
    public static function save_kilometer() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p            = $wpdb->prefix;
        $abr_id       = absint( $_POST['abrechnung_id'] ?? 0 );
        $id           = absint( $_POST['id'] ?? 0 );
        $datum        = sanitize_text_field( $_POST['datum'] ?? '' );
        $beschreibung = sanitize_text_field( $_POST['beschreibung'] ?? '' );
        $km_einfach   = round( (float) ( $_POST['km'] ?? 0 ), 2 );
        $tage         = max( 1, absint( $_POST['tage'] ?? 1 ) );
        // Eingegeben wird die einfache Strecke. Pro gefahrenem Tag fallen eine
        // Hin- und eine Rückfahrt an, daher: einfache Strecke × 2 × Anzahl Tage.
        $km           = round( $km_einfach * 2 * $tage, 2 );

        if ( ! $abr_id || ! $datum || $km_einfach <= 0 ) wp_send_json_error( [ 'message' => 'Pflichtfelder fehlen.' ] );
        self::assert_owns( $abr_id );

        $km_satz = (float) LSV07I_DB::get_config( 'km_satz', 0.30 );
        $km_min  = (float) LSV07I_DB::get_config( 'km_min', 0 );

        // Mindestkilometer beziehen sich auf die einfache Strecke
        if ( $km_min > 0 && $km_einfach < $km_min ) {
            wp_send_json_error( [ 'message' => "Mindestens {$km_min} km erforderlich (aktuell: {$km_einfach} km)." ] );
        }

        $betrag = round( $km * $km_satz, 2 );
        $data   = [ 'datum' => $datum, 'beschreibung' => $beschreibung,
                    'km' => $km, 'tage' => $tage, 'satz' => $km_satz, 'betrag' => $betrag ];
        $formats = [ '%s', '%s', '%f', '%d', '%f', '%f' ];

        if ( $id ) {
            $wpdb->update( $p . 'lsv07i_abr_kilometer', $data, [ 'id' => $id ], $formats, [ '%d' ] );
        } else {
            $data['abrechnung_id'] = $abr_id;
            $formats[]             = '%d';
            $wpdb->insert( $p . 'lsv07i_abr_kilometer', $data, $formats );
        }
        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    public static function delete_kilometer() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT abrechnung_id FROM {$p}lsv07i_abr_kilometer WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Nicht gefunden.' ] );
        self::assert_owns( $row['abrechnung_id'] );
        $wpdb->delete( $p . 'lsv07i_abr_kilometer', [ 'id' => $id ], [ '%d' ] );
        wp_send_json_success( self::load_abrechnung( $row['abrechnung_id'] ) );
    }

    // ── Einreichen / Genehmigen / Zurückgeben ─────────────────────────────────
    public static function einreichen() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $abr    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abrechnung WHERE id = %d LIMIT 1", $abr_id
        ), ARRAY_A );

        if ( ! $abr || ( ! LSV07I_Access::is_admin() && (int) $abr['trainer_id'] !== LSV07I_Access::get_trainer_id() ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ] );
        }
        if ( ! in_array( $abr['abr_status'], [ 'entwurf', 'zurueck' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung kann nicht mehr eingereicht werden.' ] );
        }

        $wpdb->update( $p . 'lsv07i_abrechnung',
            [ 'abr_status' => 'eingereicht', 'eingereicht_am' => current_time( 'mysql' ) ],
            [ 'id' => $abr_id ], [ '%s', '%s' ], [ '%d' ]
        );

        $mail_sw = LSV07I_DB::get_config( 'mail_schwimmwart', get_option( 'admin_email' ) );
        $trainer = LSV07I_DB::get_trainer_by_user( get_current_user_id() );
        $name    = $trainer ? $trainer['name'] : wp_get_current_user()->display_name;
        LSV07I_Mail::send( $mail_sw,
            "Neue Abrechnung eingereicht – $name",
            "Hallo,\n\nDer Trainer $name hat seine Abrechnung für {$abr['quartal']} {$abr['jahr']} eingereicht.\n\nBitte im internen Bereich unter Verwaltung prüfen.\n\nAutomatische Benachrichtigung.",
            'mail_abr_schwimmwart'
        );

        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    public static function genehmigen() {
        LSV07I_Access::check( 'verwaltung' );  // Schwimmwart ODER Admin
        global $wpdb;
        $p = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $wpdb->update( $p . 'lsv07i_abrechnung',
            [ 'abr_status' => 'genehmigt', 'genehmigt_am' => current_time( 'mysql' ) ],
            [ 'id' => $abr_id ], [ '%s', '%s' ], [ '%d' ]
        );
        $abr = self::load_abrechnung( $abr_id );

        // Mail an Kassenwart
        LSV07I_Mail::abrechnung_genehmigt_kassenwart(
            $abr['trainer_name'] ?? '',
            $abr['quartal'], $abr['jahr'], $abr['gesamt'] ?? 0
        );
        // Mail an Trainer
        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainer WHERE id = %d LIMIT 1", $abr['trainer_id']
        ), ARRAY_A );
        if ( $trainer && $trainer['email'] ) {
            LSV07I_Mail::abrechnung_status_trainer(
                $trainer['email'], $trainer['name'], 'genehmigt',
                $abr['quartal'], $abr['jahr']
            );
        }
        wp_send_json_success( $abr );
    }

    public static function zurueckgeben() {
        LSV07I_Access::check( 'verwaltung' );  // Schwimmwart ODER Admin
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $grund  = sanitize_textarea_field( $_POST['grund'] ?? '' );
        if ( ! $grund ) wp_send_json_error( [ 'message' => 'Bitte einen Rückgabe-Grund angeben.' ] );

        $wpdb->update( $p . 'lsv07i_abrechnung',
            [ 'abr_status' => 'zurueck', 'rueckgabe_grund' => $grund ],
            [ 'id' => $abr_id ], [ '%s', '%s' ], [ '%d' ]
        );

        $abr     = self::load_abrechnung( $abr_id );
        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainer WHERE id = %d LIMIT 1", $abr['trainer_id']
        ), ARRAY_A );
        if ( $trainer && $trainer['email'] ) {
            LSV07I_Mail::abrechnung_status_trainer(
                $trainer['email'], $trainer['name'], 'zurueck',
                $abr['quartal'], $abr['jahr'], $grund
            );
        }
        wp_send_json_success( $abr );
    }

    public static function get_alle() {
        LSV07I_Access::check( 'verwaltung' );  // schwimmwart ODER finanzwart
        global $wpdb;
        $p      = $wpdb->prefix;
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        $where  = $status ? $wpdb->prepare( 'WHERE a.abr_status = %s', $status ) : 'WHERE 1=1';

        $rows = $wpdb->get_results(
            "SELECT a.*, t.name AS trainer_name
               FROM {$p}lsv07i_abrechnung a
          LEFT JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
              $where ORDER BY a.eingereicht_am DESC, a.erstellt_am DESC",
            ARRAY_A
        );
        if ( ! $rows ) { wp_send_json_success( [] ); }

        /* Performance: Frueher lief hier pro Zeile load_abrechnung() mit je
           5 Einzelqueries (N+1-Problem — bei 100 Abrechnungen 500+ Queries).
           Jetzt werden alle Details fuer ALLE Abrechnungen in 4 Batch-Queries
           geholt und in PHP zugeordnet; die Berechnung ist identisch zu
           load_abrechnung(), nur eben ueber das gesamte Set.               */
        $ids    = array_map( fn( $r ) => (int) $r['id'], $rows );
        $ids_in = implode( ',', $ids );
        $tids   = array_unique( array_map( fn( $r ) => (int) $r['trainer_id'], $rows ) );
        $tids_in = implode( ',', $tids ?: [ 0 ] );

        $group = function( $list ) {
            $out = [];
            foreach ( (array) $list as $r ) { $out[ (int) $r['abrechnung_id'] ][] = $r; }
            return $out;
        };
        $tage = $group( $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_abr_trainingstage
              WHERE abrechnung_id IN ($ids_in) AND manuell_geloescht = 0
           ORDER BY datum ASC", ARRAY_A ) );
        $wk = $group( $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_abr_wettkampf
              WHERE abrechnung_id IN ($ids_in) AND manuell_geloescht = 0
           ORDER BY datum ASC", ARRAY_A ) );
        $km = $group( $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_abr_kilometer
              WHERE abrechnung_id IN ($ids_in) ORDER BY datum ASC", ARRAY_A ) );
        $sonder = $group( $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_abr_sonder
              WHERE abrechnung_id IN ($ids_in) AND manuell_geloescht = 0
           ORDER BY datum ASC", ARRAY_A ) );

        $satz_rows = $wpdb->get_results(
            "SELECT trainer_id, stundensatz FROM {$p}lsv07i_trainer_stammdaten
              WHERE trainer_id IN ($tids_in)", ARRAY_A );
        $saetze = [];
        foreach ( (array) $satz_rows as $sr ) { $saetze[ (int) $sr['trainer_id'] ] = (float) $sr['stundensatz']; }

        foreach ( $rows as &$row ) {
            $id  = (int) $row['id'];
            $row['trainingstage'] = $tage[ $id ] ?? [];
            $row['wettkampf']     = $wk[ $id ] ?? [];
            $row['kilometer']     = $km[ $id ] ?? [];
            $row['sonder']        = $sonder[ $id ] ?? [];

            $stundensatz      = $saetze[ (int) $row['trainer_id'] ] ?? 0.0;
            $training_stunden = (float) array_sum( array_column( $row['trainingstage'], 'stunden' ) );
            $training_betrag  = round( $training_stunden * $stundensatz, 2 );

            $anzahl_mit_vorbereitung = 0;
            foreach ( $row['trainingstage'] as $t ) {
                if ( ! empty( $t['vorbereitung_aktiv'] ) ) $anzahl_mit_vorbereitung++;
            }
            $vorbereitung_stunden = round( $anzahl_mit_vorbereitung * 0.25, 2 );
            $vorbereitung_betrag  = round( $vorbereitung_stunden * $stundensatz, 2 );

            $wettkampf_betrag = (float) array_sum( array_column( $row['wettkampf'], 'betrag' ) );
            $km_betrag        = (float) array_sum( array_column( $row['kilometer'], 'betrag' ) );
            $sonder_stunden   = (float) array_sum( array_column( $row['sonder'], 'stunden' ) );
            $sonder_betrag    = round( $sonder_stunden * $stundensatz, 2 );

            $row['stundensatz']             = $stundensatz;
            $row['training_stunden']        = round( $training_stunden, 2 );
            $row['training_betrag']         = $training_betrag;
            $row['anzahl_trainings']        = count( $row['trainingstage'] );
            $row['anzahl_mit_vorbereitung'] = $anzahl_mit_vorbereitung;
            $row['vorbereitung_stunden']    = $vorbereitung_stunden;
            $row['vorbereitung_betrag']     = $vorbereitung_betrag;
            $row['wettkampf_betrag']        = round( $wettkampf_betrag, 2 );
            $row['km_betrag']               = round( $km_betrag, 2 );
            $row['sonder_stunden']          = round( $sonder_stunden, 2 );
            $row['sonder_betrag']           = $sonder_betrag;
            $row['gesamt']                  = round( $training_betrag + $vorbereitung_betrag + $wettkampf_betrag + $km_betrag + $sonder_betrag, 2 );
        }
        unset( $row );
        wp_send_json_success( $rows );
    }

    // ── Stammdaten ────────────────────────────────────────────────────────────
    public static function save_stammdaten() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $trainer_id = self::get_or_create_trainer_id();
        if ( ! $trainer_id ) wp_send_json_error( [ 'message' => 'Kein Trainer-Profil gefunden und konnte nicht erstellt werden.' ] );

        $iban            = sanitize_text_field( $_POST['iban']            ?? '' );
        $empfaenger_name = sanitize_text_field( $_POST['empfaenger_name'] ?? '' );
        $stundensatz     = round( (float) ( $_POST['stundensatz'] ?? 0 ), 2 );

        $iban_clean = strtoupper( preg_replace( '/\s+/', '', $iban ) );
        if ( $iban_clean && ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban_clean ) ) {
            wp_send_json_error( [ 'message' => 'Bitte eine gültige IBAN eingeben.' ] );
        }

        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_trainer_stammdaten
                (trainer_id, iban, empfaenger_name, stundensatz)
             VALUES (%d, %s, %s, %f)
             ON DUPLICATE KEY UPDATE
                iban             = VALUES(iban),
                empfaenger_name  = VALUES(empfaenger_name),
                stundensatz      = VALUES(stundensatz)",
            $trainer_id, $iban_clean, $empfaenger_name, $stundensatz
        ) );

        if ( $result === false ) {
            error_log( 'LSV07I abrechnung DB: ' . $wpdb->last_error );
            wp_send_json_error( [ 'message' => 'Datenbankfehler. Bitte erneut versuchen.' ] );
        }

        wp_send_json_success();
    }

    public static function get_stammdaten() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;

        // get_or_create stellt sicher dass ein Trainer-Profil existiert (wichtig für Admins)
        $trainer_id = self::get_or_create_trainer_id();

        $config = [
            'km_satz'      => (float) LSV07I_DB::get_config( 'km_satz', 0.30 ),
            'km_min'       => (float) LSV07I_DB::get_config( 'km_min', 0 ),
            'wk_pauschale' => (float) LSV07I_DB::get_config( 'wk_pauschale', 25 ),
        ];

        if ( ! $trainer_id ) {
            wp_send_json_success( [ 'stammdaten' => [], 'config' => $config ] );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07i_trainer_stammdaten WHERE trainer_id = %d LIMIT 1",
            $trainer_id
        ), ARRAY_A );

        wp_send_json_success( [ 'stammdaten' => $row ?: [], 'config' => $config ] );
    }

    // ── Hilfsfunktion: Besitz prüfen ──────────────────────────────────────────
    public static function assert_owns( $abr_id ) {
        // Admins und Verwaltungsrollen (Schwimmwart/Finanzwart) dürfen alle Abrechnungen bearbeiten
        if ( LSV07I_Access::is_admin() ) return;
        if ( LSV07I_Access::is_schwimmwart() || LSV07I_Access::is_finanzwart() ) return;
        // Auch wer die Verwaltungs-Rechte per Rechteverwaltung besitzt, darf alle bearbeiten
        if ( class_exists( 'LSV07I_Permissions' )
          && ( LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_READ )
            || LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_KASSE_READ ) ) ) return;
        global $wpdb;
        $trainer_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT trainer_id FROM {$wpdb->prefix}lsv07i_abrechnung WHERE id = %d LIMIT 1", $abr_id
        ) );
        if ( $trainer_id !== LSV07I_Access::get_trainer_id() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
    }
    // ── Hilfsfunktion: Trainer-ID ermitteln, ggf. Auto-Profil für Admin ──────
    public static function get_or_create_trainer_id() {
        $trainer_id = LSV07I_Access::get_trainer_id();
        if ( $trainer_id ) return $trainer_id;

        // Admin ohne Trainer-Profil: automatisch eines anlegen
        if ( ! LSV07I_Access::is_admin() ) return 0;

        return self::create_trainer_profile_for_current_user();
    }

    /**
     * Legt für den aktuell angemeldeten Nutzer ein Trainer-Profil an, sofern
     * noch keines existiert (Race-Condition-sicher per Nochmals-Prüfung).
     * Extrahiert aus get_or_create_trainer_id(), damit auch andere Aufrufer
     * (z.B. die Sonderabrechnung — deren Nutzer oft KEINE Admins sind und
     * dort bewusst kein Trainer-Profil brauchen, um trainieren zu dürfen)
     * dieselbe Anlage-Logik nutzen können, nachdem SIE selbst die passende
     * Berechtigung bereits geprüft haben.
     */
    public static function create_trainer_profile_for_current_user() {
        global $wpdb;
        $p       = $wpdb->prefix;
        $user    = wp_get_current_user();
        $name    = $user->display_name ?: $user->user_login;
        $email   = $user->user_email;
        $user_id = $user->ID;

        // Nochmals prüfen ob inzwischen eines existiert
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_trainer WHERE wp_user_id = %d AND aktiv = 1 LIMIT 1",
            $user_id
        ) );
        if ( $existing ) return (int) $existing;

        // Anlegen
        $wpdb->insert( $p . 'lsv07i_trainer', [
            'name'       => $name,
            'email'      => $email,
            'wp_user_id' => $user_id,
            'aktiv'      => 1,
        ], [ '%s', '%s', '%d', '%d' ] );

        return $wpdb->insert_id ?: 0;
    }


    // ── Abrechnung löschen (Kassenwart/Admin) ─────────────────────────────────
    public static function delete_abrechnung() {
        LSV07I_Access::check( 'verwaltung' );
        // Löschen ist eine irreversible Aktion und daher ausschließlich
        // Administratoren vorbehalten – auch Kassenwart/Schwimmwart dürfen das nicht.
        if ( ! LSV07I_Access::is_admin_raw() ) {
            wp_send_json_error( [ 'message' => 'Nur Administratoren dürfen Abrechnungen löschen.' ] );
        }
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        if ( ! $abr_id ) wp_send_json_error( [ 'message' => 'Keine Abrechnung angegeben.' ] );

        $wpdb->delete( $p . 'lsv07i_abr_trainingstage', [ 'abrechnung_id' => $abr_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_abr_wettkampf',    [ 'abrechnung_id' => $abr_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_abr_kilometer',     [ 'abrechnung_id' => $abr_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_abrechnung',        [ 'id' => $abr_id ],            [ '%d' ] );

        wp_send_json_success();
    }

    // ── Als bezahlt markieren (Kassenwart/Admin) ──────────────────────────────
    public static function bezahlt() {
        LSV07I_Access::check( 'verwaltung' );
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $status = absint( $_POST['bezahlt'] ?? 1 );
        if ( ! $abr_id ) wp_send_json_error( [ 'message' => 'Keine Abrechnung angegeben.' ] );

        if ( $status ) {
            $wpdb->update( $p . 'lsv07i_abrechnung',
                [ 'bezahlt' => 1, 'bezahlt_am' => current_time( 'mysql' ) ],
                [ 'id' => $abr_id ], [ '%d', '%s' ], [ '%d' ]
            );
            // Mail an Trainer
            $abr2 = self::load_abrechnung( $abr_id );
            $trainer2 = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_trainer WHERE id = %d LIMIT 1", $abr2['trainer_id']
            ), ARRAY_A );
            if ( $trainer2 && $trainer2['email'] ) {
                LSV07I_Mail::abrechnung_status_trainer(
                    $trainer2['email'], $trainer2['name'], 'bezahlt',
                    $abr2['quartal'], $abr2['jahr']
                );
            }
        } else {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}lsv07i_abrechnung SET bezahlt=0, bezahlt_am=NULL WHERE id=%d", $abr_id
            ) );
        }

        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    // ── Jahresübersicht für Kassenwart ────────────────────────────────────────
    public static function jahresuebersicht() {
        LSV07I_Access::check( 'verwaltung' );
        global $wpdb;
        $p    = $wpdb->prefix;
        $jahr = absint( $_POST['jahr'] ?? date( 'Y' ) );

        // Alle Trainer mit mindestens einer Abrechnung in diesem Jahr
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                t.id AS trainer_id,
                t.name AS trainer_name,
                a.quartal,
                a.id AS abr_id,
                a.abr_status,
                a.bezahlt,
                a.bezahlt_am,
                a.genehmigt_am,
                ts.stundensatz,
                COALESCE(
                    (SELECT SUM(tr.stunden)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS training_stunden,
                COALESCE(
                    (SELECT COUNT(*)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS anzahl_trainings,
                COALESCE(
                    (SELECT SUM(CASE WHEN tr.vorbereitung_aktiv = 1 THEN 1 ELSE 0 END)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS anzahl_mit_vorbereitung,
                COALESCE(
                    (SELECT SUM(wk.betrag)
                       FROM {$p}lsv07i_abr_wettkampf wk
                      WHERE wk.abrechnung_id = a.id AND wk.manuell_geloescht = 0),
                    0
                ) AS wettkampf_betrag,
                COALESCE(
                    (SELECT SUM(km.betrag)
                       FROM {$p}lsv07i_abr_kilometer km
                      WHERE km.abrechnung_id = a.id),
                    0
                ) AS km_betrag,
                COALESCE(
                    (SELECT SUM(so.stunden)
                       FROM {$p}lsv07i_abr_sonder so
                      WHERE so.abrechnung_id = a.id AND so.manuell_geloescht = 0),
                    0
                ) AS sonder_stunden
               FROM {$p}lsv07i_abrechnung a
               JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
          LEFT JOIN {$p}lsv07i_trainer_stammdaten ts ON ts.trainer_id = a.trainer_id
              WHERE a.jahr = %d
           ORDER BY t.name ASC, a.quartal ASC",
            $jahr
        ), ARRAY_A );

        // Zu einer Struktur zusammenfassen: trainer => quartal => daten
        $trainer_map = [];
        foreach ( $rows as $r ) {
            $tid  = $r['trainer_id'];
            $qrtl = $r['quartal'];
            if ( ! isset( $trainer_map[ $tid ] ) ) {
                $trainer_map[ $tid ] = [
                    'trainer_id'   => $tid,
                    'trainer_name' => $r['trainer_name'],
                    'quartale'     => [],
                    'gesamt'       => 0.0,
                    'bezahlt'      => 0.0,
                    'offen'        => 0.0,
                ];
            }
            $satz                = (float) ( $r['stundensatz'] ?? 0 );
            $anzahl_trainings    = (int) $r['anzahl_trainings'];
            $anzahl_mit_vorb     = (int) $r['anzahl_mit_vorbereitung'];
            $training_btg        = round( (float) $r['training_stunden'] * $satz, 2 );
            $vorb_stunden        = round( $anzahl_mit_vorb * 0.25, 2 );
            $vorb_btg            = round( $vorb_stunden * $satz, 2 );
            $sonder_btg          = round( (float) $r['sonder_stunden'] * $satz, 2 );
            $gesamt              = round( $training_btg + $vorb_btg + (float) $r['wettkampf_betrag'] + (float) $r['km_betrag'] + $sonder_btg, 2 );

            $neu = [
                'abr_id'                  => $r['abr_id'],
                'abr_status'              => $r['abr_status'],
                'bezahlt'                 => (int) $r['bezahlt'],
                'bezahlt_am'              => $r['bezahlt_am'],
                'genehmigt_am'            => $r['genehmigt_am'],
                'stundensatz'             => $satz,
                'training_stunden'        => round( (float) $r['training_stunden'], 2 ),
                'training_betrag'         => $training_btg,
                'anzahl_mit_vorbereitung' => $anzahl_mit_vorb,
                'vorbereitung_stunden'    => $vorb_stunden,
                'vorbereitung_betrag'     => $vorb_btg,
                'wettkampf_betrag'        => round( (float) $r['wettkampf_betrag'], 2 ),
                'km_betrag'               => round( (float) $r['km_betrag'], 2 ),
                'sonder_betrag'           => $sonder_btg,
                'gesamt'                  => $gesamt,
            ];

            if ( isset( $trainer_map[ $tid ]['quartale'][ $qrtl ] ) ) {
                // Zweiter Datensatz im selben Quartal (z.B. zusaetzlich zur
                // regulaeren Trainings-Abrechnung eine Sonderabrechnung) —
                // Betraege ADDIEREN statt zu ueberschreiben, sonst wuerde die
                // Quartalszelle nur die zuletzt verarbeitete Abrechnung
                // zeigen und Geld "verschwinden" lassen.
                $ex = $trainer_map[ $tid ]['quartale'][ $qrtl ];
                $trainer_map[ $tid ]['quartale'][ $qrtl ] = [
                    'abr_id'                  => $ex['abr_id'],
                    'abr_status'              => ( $ex['abr_status'] === 'eingereicht' || $neu['abr_status'] === 'eingereicht' ) ? 'eingereicht' : $ex['abr_status'],
                    'bezahlt'                 => ( $ex['bezahlt'] && $neu['bezahlt'] ) ? 1 : 0,
                    'bezahlt_am'              => $ex['bezahlt_am'] ?: $neu['bezahlt_am'],
                    'genehmigt_am'            => $ex['genehmigt_am'] ?: $neu['genehmigt_am'],
                    'stundensatz'             => $satz,
                    'training_stunden'        => round( $ex['training_stunden'] + $neu['training_stunden'], 2 ),
                    'training_betrag'         => round( $ex['training_betrag'] + $neu['training_betrag'], 2 ),
                    'anzahl_mit_vorbereitung' => $ex['anzahl_mit_vorbereitung'] + $neu['anzahl_mit_vorbereitung'],
                    'vorbereitung_stunden'    => round( $ex['vorbereitung_stunden'] + $neu['vorbereitung_stunden'], 2 ),
                    'vorbereitung_betrag'     => round( $ex['vorbereitung_betrag'] + $neu['vorbereitung_betrag'], 2 ),
                    'wettkampf_betrag'        => round( $ex['wettkampf_betrag'] + $neu['wettkampf_betrag'], 2 ),
                    'km_betrag'               => round( $ex['km_betrag'] + $neu['km_betrag'], 2 ),
                    'sonder_betrag'           => round( $ex['sonder_betrag'] + $neu['sonder_betrag'], 2 ),
                    'gesamt'                  => round( $ex['gesamt'] + $neu['gesamt'], 2 ),
                ];
            } else {
                $trainer_map[ $tid ]['quartale'][ $qrtl ] = $neu;
            }

            $trainer_map[ $tid ]['gesamt'] += $gesamt;
            if ( $r['bezahlt'] ) {
                $trainer_map[ $tid ]['bezahlt'] += $gesamt;
            } else {
                $trainer_map[ $tid ]['offen'] += $gesamt;
            }
        }

        // Rundung der Jahrestotals
        foreach ( $trainer_map as &$t ) {
            $t['gesamt']  = round( $t['gesamt'], 2 );
            $t['bezahlt'] = round( $t['bezahlt'], 2 );
            $t['offen']   = round( $t['offen'], 2 );
        }
        unset( $t );

        // Verfügbare Jahre für Dropdown
        $jahre = $wpdb->get_col(
            "SELECT DISTINCT jahr FROM {$p}lsv07i_abrechnung ORDER BY jahr DESC LIMIT 10"
        );

        wp_send_json_success( [
            'trainer' => array_values( $trainer_map ),
            'jahre'   => $jahre,
            'jahr'    => $jahr,
        ] );
    }

    /**
     * Kostenauswertung nach Mannschaft (fuer Vorstand/Kassenwart).
     * Summiert Trainings- und Vorbereitungskosten je Mannschaft und Quartal
     * eines Jahres. Basis sind ausschliesslich GENEHMIGTE Abrechnungen —
     * Entwuerfe und offene Einreichungen verfaelschen die Kostensicht.
     * Wettkampf- und Fahrtkosten haben keine Mannschaftszuordnung und werden
     * daher separat als Gesamtsumme ausgewiesen.
     */
    public static function kosten_mannschaft() {
        LSV07I_Access::check( 'verwaltung' );
        global $wpdb;
        $p    = $wpdb->prefix;
        $jahr = absint( $_POST['jahr'] ?? date( 'Y' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tr.mannschaft_name,
                    a.quartal,
                    ROUND( SUM( tr.stunden * COALESCE( ts.stundensatz, 0 ) ), 2 ) AS training,
                    ROUND( SUM( CASE WHEN tr.vorbereitung_aktiv = 1
                                     THEN 0.25 * COALESCE( ts.stundensatz, 0 )
                                     ELSE 0 END ), 2 ) AS vorbereitung
               FROM {$p}lsv07i_abr_trainingstage tr
               JOIN {$p}lsv07i_abrechnung a
                 ON a.id = tr.abrechnung_id AND a.jahr = %d AND a.abr_status = 'genehmigt'
          LEFT JOIN {$p}lsv07i_trainer_stammdaten ts ON ts.trainer_id = a.trainer_id
              WHERE tr.manuell_geloescht = 0
           GROUP BY tr.mannschaft_name, a.quartal
           ORDER BY tr.mannschaft_name ASC, a.quartal ASC",
            $jahr
        ), ARRAY_A );

        // Struktur: mannschaft => quartale-Map + Jahressumme
        $map = [];
        foreach ( (array) $rows as $r ) {
            $mn = $r['mannschaft_name'] !== '' ? $r['mannschaft_name'] : '(ohne Mannschaft)';
            if ( ! isset( $map[ $mn ] ) ) {
                $map[ $mn ] = [ 'mannschaft' => $mn, 'quartale' => [], 'gesamt' => 0.0 ];
            }
            $summe = round( (float) $r['training'] + (float) $r['vorbereitung'], 2 );
            $map[ $mn ]['quartale'][ $r['quartal'] ] = $summe;
            $map[ $mn ]['gesamt'] += $summe;
        }
        foreach ( $map as &$m ) { $m['gesamt'] = round( $m['gesamt'], 2 ); }
        unset( $m );

        // Nicht mannschaftsbezogene Kosten (Wettkampf + Fahrt) als Kontext
        $sonstige = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                ROUND( COALESCE( (SELECT SUM(wk.betrag)
                    FROM {$p}lsv07i_abr_wettkampf wk
                    JOIN {$p}lsv07i_abrechnung a2 ON a2.id = wk.abrechnung_id
                   WHERE a2.jahr = %d AND a2.abr_status = 'genehmigt' AND wk.manuell_geloescht = 0 ), 0 ), 2 ) AS wettkampf,
                ROUND( COALESCE( (SELECT SUM(km.betrag)
                    FROM {$p}lsv07i_abr_kilometer km
                    JOIN {$p}lsv07i_abrechnung a3 ON a3.id = km.abrechnung_id
                   WHERE a3.jahr = %d AND a3.abr_status = 'genehmigt' ), 0 ), 2 ) AS kilometer",
            $jahr, $jahr
        ), ARRAY_A );

        wp_send_json_success( [
            'jahr'       => $jahr,
            'mannschaften' => array_values( $map ),
            'sonstige'   => $sonstige ?: [ 'wettkampf' => 0, 'kilometer' => 0 ],
        ] );
    }

    // ── CSV-Export ────────────────────────────────────────────────────────────
    public static function csv_export() {
        LSV07I_Access::check( 'verwaltung' );
        global $wpdb;
        $p    = $wpdb->prefix;
        $jahr = absint( $_POST['jahr'] ?? 0 );
        $where = $jahr
            ? $wpdb->prepare( 'AND a.jahr = %d', $jahr )
            : '';

        $rows = $wpdb->get_results(
            "SELECT
                t.name AS trainer_name,
                t.email AS trainer_email,
                a.quartal,
                a.jahr,
                a.abr_status,
                a.bezahlt,
                a.genehmigt_am,
                a.bezahlt_am,
                ts.stundensatz,
                ts.iban,
                COALESCE(
                    (SELECT SUM(tr.stunden)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS training_stunden,
                COALESCE(
                    (SELECT SUM(CASE WHEN tr.vorbereitung_aktiv = 1 THEN 1 ELSE 0 END)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS anzahl_mit_vorbereitung,
                COALESCE(
                    (SELECT SUM(wk.betrag)
                       FROM {$p}lsv07i_abr_wettkampf wk
                      WHERE wk.abrechnung_id = a.id AND wk.manuell_geloescht = 0),
                    0
                ) AS wettkampf_betrag,
                COALESCE(
                    (SELECT SUM(km.betrag)
                       FROM {$p}lsv07i_abr_kilometer km
                      WHERE km.abrechnung_id = a.id),
                    0
                ) AS km_betrag,
                COALESCE(
                    (SELECT SUM(so.stunden)
                       FROM {$p}lsv07i_abr_sonder so
                      WHERE so.abrechnung_id = a.id AND so.manuell_geloescht = 0),
                    0
                ) AS sonder_stunden
               FROM {$p}lsv07i_abrechnung a
               JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
          LEFT JOIN {$p}lsv07i_trainer_stammdaten ts ON ts.trainer_id = a.trainer_id
              WHERE a.abr_status IN ('genehmigt')
                $where
           ORDER BY t.name ASC, a.jahr ASC, a.quartal ASC",
            ARRAY_A
        );

        // Sichtbar machen statt still zu verschlucken: schlägt die Abfrage
        // fehl (z.B. fehlende Tabelle/Spalte auf dieser Installation), kam
        // vorher eine "erfolgreiche" aber leere CSV-Datei zurück — das sah
        // nach nichts aus, obwohl im Hintergrund ein echter Fehler vorlag.
        if ( $wpdb->last_error ) {
            wp_send_json_error( [ 'message' => 'Datenbankfehler beim CSV-Export: ' . $wpdb->last_error ] );
        }
        if ( ! is_array( $rows ) ) $rows = [];

        // CSV bauen
        $csv  = "ï»¿"; // UTF-8 BOM für Excel
        $csv .= "Trainer;E-Mail;IBAN;Quartal;Jahr;Status;Stundensatz;Training Std.;Training €;Vorb. Std.;Vorb. €;Wettkampf €;Fahrtkosten €;Sonderabrechnung €;Gesamt €;Genehmigt am;Bezahlt;Bezahlt am\n";

        foreach ( $rows as $r ) {
            $satz             = (float) ( $r['stundensatz'] ?? 0 );
            $anzahl_mit_vorb  = (int) $r['anzahl_mit_vorbereitung'];
            $training         = round( (float) $r['training_stunden'] * $satz, 2 );
            $vorb_stunden     = round( $anzahl_mit_vorb * 0.25, 2 );
            $vorb_betrag      = round( $vorb_stunden * $satz, 2 );
            $sonder_betrag    = round( (float) $r['sonder_stunden'] * $satz, 2 );
            $gesamt           = round( $training + $vorb_betrag + (float) $r['wettkampf_betrag'] + (float) $r['km_betrag'] + $sonder_betrag, 2 );

            $fmt = function( $n ) { return number_format( (float) $n, 2, ',', '' ); };

            $csv .= implode( ';', [
                '"' . str_replace( '"', '""', $r['trainer_name'] ) . '"',
                '"' . str_replace( '"', '""', $r['trainer_email'] ?? '' ) . '"',
                '"' . str_replace( '"', '""', $r['iban'] ?? '' ) . '"',
                $r['quartal'],
                $r['jahr'],
                $r['abr_status'],
                $fmt( $satz ),
                $fmt( $r['training_stunden'] ),
                $fmt( $training ),
                $fmt( $vorb_stunden ),
                $fmt( $vorb_betrag ),
                $fmt( $r['wettkampf_betrag'] ),
                $fmt( $r['km_betrag'] ),
                $fmt( $sonder_betrag ),
                $fmt( $gesamt ),
                $r['genehmigt_am'] ? substr( $r['genehmigt_am'], 0, 10 ) : '',
                $r['bezahlt'] ? 'Ja' : 'Nein',
                $r['bezahlt_am'] ? substr( $r['bezahlt_am'], 0, 10 ) : '',
            ] ) . "\n";
        }

        wp_send_json_success( [ 'csv' => $csv, 'rows' => count( $rows ) ] );
    }


}