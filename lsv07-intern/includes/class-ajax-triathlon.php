<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Triathlon {

    public static function init() {
        $actions = [
            'lsv07i_tri_get_data',
            'lsv07i_tri_get_slots',
            'lsv07i_tri_save_slot',
            'lsv07i_tri_delete_slot',
            'lsv07i_tri_anw_get_or_create',
            'lsv07i_tri_anw_save_eintrag',
            'lsv07i_tri_anw_trainer_abr',
            'lsv07i_tri_anw_mark_ausfall',
            'lsv07i_tri_anw_get_sessions',
            'lsv07i_tri_save_gruppe',
            'lsv07i_tri_delete_gruppe',
            'lsv07i_tri_get_sportler',
            'lsv07i_tri_save_sportler',
            'lsv07i_tri_delete_sportler',
            'lsv07i_tri_get_trainer',
            'lsv07i_tri_save_trainer',
            'lsv07i_tri_delete_trainer',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_tri_', '', $action ) ] );
        }
    }

    // ── Stammdaten laden (Gruppen + Trainer) ──────────────────────────────────
    public static function get_data() {
        LSV07I_Access::check( 'tri_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $gruppen = $wpdb->get_results(
            "SELECT g.*, COUNT(sg.sportler_id) AS sportler_anzahl
               FROM {$p}lsv07i_tri_gruppen g
          LEFT JOIN {$p}lsv07i_tri_sportler_gruppe sg ON sg.gruppe_id = g.id
           GROUP BY g.id ORDER BY g.sort_order ASC, g.name ASC",
            ARRAY_A
        );

        $trainer = $wpdb->get_results(
            "SELECT t.*, GROUP_CONCAT(tg.gruppe_id) AS gruppe_ids
               FROM {$p}lsv07i_tri_trainer t
          LEFT JOIN {$p}lsv07i_tri_trainer_gruppe tg ON tg.trainer_id = t.id
              WHERE t.aktiv = 1
           GROUP BY t.id ORDER BY t.name ASC",
            ARRAY_A
        );

        $slots = $wpdb->get_results(
            "SELECT s.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_slots s
          LEFT JOIN {$p}lsv07i_tri_gruppen g ON g.id = s.gruppe_id
           ORDER BY s.gruppe_id ASC, s.wochentag ASC, s.zeit_von ASC",
            ARRAY_A
        );

        wp_send_json_success( [ 'gruppen' => $gruppen ?: [], 'trainer' => $trainer ?: [], 'slots' => $slots ?: [] ] );
    }

    // ── Sportler laden (mit Gruppen) ──────────────────────────────────────────
    public static function get_sportler() {
        LSV07I_Access::check( 'tri_read' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $gruppe_id  = absint( $_POST['gruppe_id'] ?? 0 );

        if ( $gruppe_id ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT s.*
                   FROM {$p}lsv07i_tri_sportler s
                   JOIN {$p}lsv07i_tri_sportler_gruppe sg ON sg.sportler_id = s.id AND sg.gruppe_id = %d
                  WHERE s.aktiv = 1
               ORDER BY s.last_name ASC, s.first_name ASC",
                $gruppe_id
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results(
                "SELECT s.* FROM {$p}lsv07i_tri_sportler s WHERE s.aktiv = 1
               ORDER BY s.last_name ASC, s.first_name ASC",
                ARRAY_A
            );
        }

        // Gruppen-Zuordnungen + Gruppennamen in 2 statt 2N Queries laden
        if ( ! empty( $rows ) ) {
            $sportler_ids = array_filter( array_map( fn( $r ) => (int) $r['id'], $rows ), fn( $i ) => $i > 0 );
            if ( ! empty( $sportler_ids ) ) {
                $ids_in = implode( ',', $sportler_ids );
                $sg_rows = $wpdb->get_results(
                    "SELECT sportler_id, gruppe_id FROM {$p}lsv07i_tri_sportler_gruppe
                      WHERE sportler_id IN ($ids_in)", ARRAY_A );
                $sg_map = []; $alle_gruppen = [];
                foreach ( $sg_rows as $sg ) {
                    $sid = (int) $sg['sportler_id']; $gid = (int) $sg['gruppe_id'];
                    $sg_map[ $sid ][] = $gid;
                    $alle_gruppen[ $gid ] = true;
                }
                $gn_map = [];
                if ( ! empty( $alle_gruppen ) ) {
                    $gids_in = implode( ',', array_keys( $alle_gruppen ) );
                    $gn_rows = $wpdb->get_results(
                        "SELECT id, name FROM {$p}lsv07i_tri_gruppen
                          WHERE id IN ($gids_in) ORDER BY sort_order ASC", ARRAY_A );
                    foreach ( $gn_rows as $gn ) $gn_map[ (int) $gn['id'] ] = $gn['name'];
                }
                foreach ( $rows as &$row ) {
                    $sid = (int) $row['id'];
                    $row['gruppe_ids'] = $sg_map[ $sid ] ?? [];
                    $row['gruppen_namen'] = array_values( array_filter( array_map(
                        fn( $gid ) => $gn_map[ $gid ] ?? null, $row['gruppe_ids']
                    ) ) );
                }
                unset( $row );
            }
        }

        wp_send_json_success( $rows ?: [] );
    }

    // ── Sportler speichern (Neu / Bearbeiten) ─────────────────────────────────
    public static function save_sportler() {
        LSV07I_Access::check( 'triathlonwart' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $id         = absint( $_POST['id'] ?? 0 );
        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name']  ?? '' );
        $birth_date = sanitize_text_field( $_POST['birth_date'] ?? '' );
        $email      = sanitize_email(      $_POST['email']      ?? '' );
        $notes      = sanitize_textarea_field( $_POST['notes']  ?? '' );
        $gruppe_ids = array_filter( array_map( 'absint', (array) ( $_POST['gruppe_ids'] ?? [] ) ) );

        if ( ! $last_name ) {
            wp_send_json_error( [ 'message' => 'Nachname ist Pflicht.' ] );
        }

        $data = [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'birth_date' => $birth_date ?: null,
            'email'      => $email,
            'notes'      => $notes,
            'aktiv'      => 1,
        ];
        $fmt = [ '%s', '%s', '%s', '%s', '%s', '%d' ];

        if ( $id ) {
            $wpdb->update( $p . 'lsv07i_tri_sportler', $data, [ 'id' => $id ], $fmt, [ '%d' ] );
        } else {
            $wpdb->insert( $p . 'lsv07i_tri_sportler', $data, $fmt );
            $id = $wpdb->insert_id;
        }

        // Gruppen-Zuordnung aktualisieren
        $wpdb->delete( $p . 'lsv07i_tri_sportler_gruppe', [ 'sportler_id' => $id ], [ '%d' ] );
        foreach ( $gruppe_ids as $gid ) {
            $wpdb->insert( $p . 'lsv07i_tri_sportler_gruppe',
                [ 'sportler_id' => $id, 'gruppe_id' => $gid ], [ '%d', '%d' ]
            );
        }

        wp_send_json_success( [ 'id' => $id ] );
    }

    // ── Sportler löschen ─────────────────────────────────────────────────────
    public static function delete_sportler() {
        LSV07I_Access::check( 'triathlonwart' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $wpdb->delete( $p . 'lsv07i_tri_sportler_gruppe', [ 'sportler_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_tri_sportler',        [ 'id'          => $id ], [ '%d' ] );
        wp_send_json_success();
    }

    // ── Gruppe speichern ──────────────────────────────────────────────────────
    public static function save_gruppe() {
        LSV07I_Access::check( 'triathlonwart' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $id         = absint( $_POST['id']         ?? 0 );
        $name       = sanitize_text_field( $_POST['name']       ?? '' );
        $sort_order = absint( $_POST['sort_order'] ?? 0 );

        if ( ! $name ) wp_send_json_error( [ 'message' => 'Name ist Pflicht.' ] );

        if ( $id ) {
            $wpdb->update( $p . 'lsv07i_tri_gruppen',
                [ 'name' => $name, 'sort_order' => $sort_order ],
                [ 'id'   => $id ], [ '%s', '%d' ], [ '%d' ]
            );
        } else {
            $wpdb->insert( $p . 'lsv07i_tri_gruppen',
                [ 'name' => $name, 'sort_order' => $sort_order ], [ '%s', '%d' ]
            );
            $id = $wpdb->insert_id;
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    // ── Gruppe löschen ────────────────────────────────────────────────────────
    public static function delete_gruppe() {
        LSV07I_Access::check( 'triathlonwart' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $wpdb->delete( $p . 'lsv07i_tri_sportler_gruppe', [ 'gruppe_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_tri_trainer_gruppe',  [ 'gruppe_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_tri_gruppen',         [ 'id'        => $id ], [ '%d' ] );
        wp_send_json_success();
    }

    // ── Trainer laden ─────────────────────────────────────────────────────────
    public static function get_trainer() {
        // Kontaktdaten aller Trainer → Admin oder eigenes Leserecht.
        LSV07I_Access::check( 'tri_trainer_read' );
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT t.*, GROUP_CONCAT(tg.gruppe_id) AS gruppe_ids
               FROM {$p}lsv07i_tri_trainer t
          LEFT JOIN {$p}lsv07i_tri_trainer_gruppe tg ON tg.trainer_id = t.id
              WHERE t.aktiv = 1
           GROUP BY t.id ORDER BY t.name ASC",
            ARRAY_A
        );
        wp_send_json_success( $rows ?: [] );
    }

    // ── Trainer speichern ─────────────────────────────────────────────────────
    public static function save_trainer() {
        LSV07I_Access::check( 'triathlonwart' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $id         = absint( $_POST['id']         ?? 0 );
        $name       = sanitize_text_field( $_POST['name']       ?? '' );
        $email      = sanitize_email(      $_POST['email']      ?? '' );
        $wp_user_id = absint( $_POST['wp_user_id'] ?? 0 );
        $gruppe_ids = array_filter( array_map( 'absint', (array) ( $_POST['gruppe_ids'] ?? [] ) ) );

        if ( ! $name ) wp_send_json_error( [ 'message' => 'Name ist Pflicht.' ] );

        $data = [ 'name' => $name, 'email' => $email, 'wp_user_id' => $wp_user_id ?: null, 'aktiv' => 1 ];
        $fmt  = [ '%s', '%s', '%d', '%d' ];

        if ( $id ) {
            $wpdb->update( $p . 'lsv07i_tri_trainer', $data, [ 'id' => $id ], $fmt, [ '%d' ] );
        } else {
            $wpdb->insert( $p . 'lsv07i_tri_trainer', $data, $fmt );
            $id = $wpdb->insert_id;
        }

        $wpdb->delete( $p . 'lsv07i_tri_trainer_gruppe', [ 'trainer_id' => $id ], [ '%d' ] );
        foreach ( $gruppe_ids as $gid ) {
            $wpdb->insert( $p . 'lsv07i_tri_trainer_gruppe',
                [ 'trainer_id' => $id, 'gruppe_id' => $gid ], [ '%d', '%d' ]
            );
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    // ── Trainer löschen ───────────────────────────────────────────────────────
    public static function delete_trainer() {
        LSV07I_Access::check( 'triathlonwart' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $wpdb->delete( $p . 'lsv07i_tri_trainer_gruppe', [ 'trainer_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_tri_trainer',        [ 'id'         => $id ], [ '%d' ] );
        wp_send_json_success();
    }

    // ── Triathlon Trainingszeiten ────────────────────────────────────────────

    public static function get_slots() {
        LSV07I_Access::check( 'tri_read' );
        global $wpdb;
        $p         = $wpdb->prefix;
        $gruppe_id = absint( $_POST['gruppe_id'] ?? 0 );
        $where     = $gruppe_id ? $wpdb->prepare( 'WHERE s.gruppe_id = %d', $gruppe_id ) : '';
        $rows = $wpdb->get_results(
            "SELECT s.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_slots s
          LEFT JOIN {$p}lsv07i_tri_gruppen g ON g.id = s.gruppe_id
             $where
           ORDER BY s.gruppe_id ASC, s.wochentag ASC, s.zeit_von ASC",
            ARRAY_A
        );
        wp_send_json_success( $rows ?: [] );
    }

    public static function save_slot() {
        LSV07I_Access::check( 'triathlonwart' );
        global $wpdb;
        $p         = $wpdb->prefix;
        $id        = absint( $_POST['id']        ?? 0 );
        $gruppe_id = absint( $_POST['gruppe_id'] ?? 0 );
        $wochentag = absint( $_POST['wochentag'] ?? 1 );
        $zeit_von  = sanitize_text_field( $_POST['zeit_von'] ?? '00:00' );
        $zeit_bis  = sanitize_text_field( $_POST['zeit_bis'] ?? '00:00' );

        if ( ! $gruppe_id ) wp_send_json_error( [ 'message' => 'Gruppe ist Pflicht.' ] );

        $data = [ 'gruppe_id' => $gruppe_id, 'wochentag' => $wochentag, 'zeit_von' => $zeit_von, 'zeit_bis' => $zeit_bis ];
        $fmt  = [ '%d', '%d', '%s', '%s' ];

        if ( $id ) {
            $wpdb->update( $p . 'lsv07i_tri_slots', $data, [ 'id' => $id ], $fmt, [ '%d' ] );
        } else {
            $wpdb->insert( $p . 'lsv07i_tri_slots', $data, $fmt );
            $id = $wpdb->insert_id;
        }

        // Alle Slots zurückgeben für JS-Update
        $slots = $wpdb->get_results(
            "SELECT s.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_slots s
          LEFT JOIN {$p}lsv07i_tri_gruppen g ON g.id = s.gruppe_id
           ORDER BY s.gruppe_id ASC, s.wochentag ASC, s.zeit_von ASC",
            ARRAY_A
        );
        wp_send_json_success( [ 'id' => $id, 'slots' => $slots ] );
    }

    public static function delete_slot() {
        LSV07I_Access::check( 'triathlonwart' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $wpdb->delete( $p . 'lsv07i_tri_slots', [ 'id' => $id ], [ '%d' ] );
        $slots = $wpdb->get_results(
            "SELECT s.*, g.name AS gruppe_name FROM {$p}lsv07i_tri_slots s
          LEFT JOIN {$p}lsv07i_tri_gruppen g ON g.id = s.gruppe_id
           ORDER BY s.gruppe_id ASC, s.wochentag ASC",
            ARRAY_A
        );
        wp_send_json_success( [ 'slots' => $slots ] );
    }

    // ── Triathlon Anwesenheit ─────────────────────────────────────────────────

    public static function anw_get_or_create() {
        LSV07I_Access::check( 'tri_intern' );
        global $wpdb;
        $p       = $wpdb->prefix;
        $slot_id = absint( $_POST['slot_id'] ?? 0 );
        $datum   = sanitize_text_field( $_POST['datum'] ?? '' );

        if ( ! $slot_id || ! $datum ) {
            wp_send_json_error( [ 'message' => 'Fehlende Angaben.' ] );
        }

        // Slot-Infos laden
        $slot = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_slots s
               JOIN {$p}lsv07i_tri_gruppen g ON g.id = s.gruppe_id
              WHERE s.id = %d LIMIT 1",
            $slot_id
        ), ARRAY_A );
        if ( ! $slot ) wp_send_json_error( [ 'message' => 'Trainingszeit nicht gefunden.' ] );

        $gruppe_id = (int) $slot['gruppe_id'];

        // Existierende Session suchen
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_anwesenheit a
               JOIN {$p}lsv07i_tri_gruppen g ON g.id = a.gruppe_id
              WHERE a.slot_id = %d AND a.training_datum = %s LIMIT 1",
            $slot_id, $datum
        ), ARRAY_A );

        if ( ! $session ) {
            $wpdb->insert( $p . 'lsv07i_tri_anwesenheit', [
                'gruppe_id'      => $gruppe_id,
                'slot_id'        => $slot_id,
                'training_datum' => $datum,
                'ausgefallen'    => 0,
                'erstellt_von'   => get_current_user_id(),
            ], [ '%d', '%d', '%s', '%d', '%d' ] );
            $session = $wpdb->get_row( $wpdb->prepare(
                "SELECT a.*, g.name AS gruppe_name
                   FROM {$p}lsv07i_tri_anwesenheit a
                   JOIN {$p}lsv07i_tri_gruppen g ON g.id = a.gruppe_id
                  WHERE a.id = %d LIMIT 1",
                $wpdb->insert_id
            ), ARRAY_A );
        }

        // Einträge laden
        $eintraege = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_tri_anwesenheit_eintraege WHERE anwesenheit_id = %d",
            $session['id']
        ), ARRAY_A );

        // Sportler der Gruppe laden
        $sportler = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.first_name, s.last_name
               FROM {$p}lsv07i_tri_sportler s
               JOIN {$p}lsv07i_tri_sportler_gruppe sg ON sg.sportler_id = s.id AND sg.gruppe_id = %d
              WHERE s.aktiv = 1
           ORDER BY s.last_name ASC, s.first_name ASC",
            $gruppe_id
        ), ARRAY_A );

        wp_send_json_success( [
            'session'  => $session,
            'eintraege'=> $eintraege,
            'sportler' => $sportler,
        ] );
    }

    public static function anw_save_eintrag() {
        LSV07I_Access::check( 'tri_intern' );
        global $wpdb;
        $p              = $wpdb->prefix;
        $anwesenheit_id = absint( $_POST['anwesenheit_id'] ?? 0 );
        $sportler_id    = absint( $_POST['sportler_id']    ?? 0 );
        $status         = sanitize_text_field( $_POST['status'] ?? 'anwesend' );

        if ( ! in_array( $status, [ 'anwesend', 'abwesend', 'entschuldigt' ], true ) ) {
            $status = 'anwesend';
        }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_tri_anwesenheit_eintraege (anwesenheit_id, sportler_id, status)
             VALUES (%d, %d, %s)
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            $anwesenheit_id, $sportler_id, $status
        ) );

        wp_send_json_success();
    }

    /**
     * Triathlon-Trainer-Abrechnung automatisch befüllen wenn Anwesenheit gespeichert wird.
     * Wird vom JS nach dem Speichern aller Einträge aufgerufen.
     */
    public static function anw_trainer_abr() {
        LSV07I_Access::check( 'tri_intern' );
        global $wpdb;
        $p              = $wpdb->prefix;
        $anwesenheit_id = absint( $_POST['anwesenheit_id'] ?? 0 );
        if ( ! $anwesenheit_id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        // Tri-Trainer-ID des aktuellen Nutzers ermitteln
        $tri_trainer_id = LSV07I_Access::get_tri_trainer_id();
        if ( ! $tri_trainer_id ) {
            // Kein Tri-Trainer-Profil → kein Eintrag nötig
            wp_send_json_success( [ 'skipped' => true ] );
        }

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_anwesenheit a
               JOIN {$p}lsv07i_tri_gruppen g ON g.id = a.gruppe_id
              WHERE a.id = %d LIMIT 1",
            $anwesenheit_id
        ), ARRAY_A );
        if ( ! $session ) wp_send_json_error( [ 'message' => 'Session nicht gefunden.' ] );

        $monat   = (int) date( 'm', strtotime( $session['training_datum'] ) );
        $jahr    = (int) date( 'Y', strtotime( $session['training_datum'] ) );
        $quartal = 'Q' . ceil( $monat / 3 );

        // Triathlon-Abrechnung holen oder anlegen (bereich = 'triathlon')
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_abrechnung
              WHERE trainer_id = %d AND bereich = 'triathlon' AND quartal = %s AND jahr = %d LIMIT 1",
            $tri_trainer_id, $quartal, $jahr
        ), ARRAY_A );

        if ( ! $abr ) {
            $wpdb->insert( $p . 'lsv07i_abrechnung', [
                'trainer_id' => $tri_trainer_id,
                'bereich'    => 'triathlon',
                'quartal'    => $quartal,
                'jahr'       => $jahr,
                'abr_status' => 'entwurf',
            ], [ '%d', '%s', '%s', '%d', '%s' ] );
            $abr_id = $wpdb->insert_id;
        } else {
            $abr_id = $abr['id'];
        }

        // Trainingstag nur eintragen wenn noch nicht vorhanden
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_abr_trainingstage
              WHERE abrechnung_id = %d AND datum = %s AND quelle = 'triathlon' LIMIT 1",
            $abr_id, $session['training_datum']
        ) );

        if ( ! $exists ) {
            $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                'abrechnung_id'    => $abr_id,
                'datum'            => $session['training_datum'],
                'mannschaft_name'  => $session['gruppe_name'],
                'stunden'          => 2.0,
                'quelle'           => 'triathlon',
                'begruendung'      => 'Triathlon-Training (automatisch)',
                'manuell_geloescht'=> 0,
            ], [ '%d', '%s', '%s', '%f', '%s', '%s', '%d' ] );
        }

        wp_send_json_success( [ 'abr_id' => $abr_id ] );
    }

    public static function anw_mark_ausfall() {
        LSV07I_Access::check( 'tri_intern' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $slot_id    = absint( $_POST['slot_id']    ?? 0 );
        $gruppe_id  = absint( $_POST['gruppe_id']  ?? 0 );
        $datum      = sanitize_text_field( $_POST['datum']      ?? '' );
        $ausgefallen= absint( $_POST['ausgefallen'] ?? 1 );
        $grund      = sanitize_text_field( $_POST['grund']      ?? '' );

        if ( ( ! $slot_id && ! $gruppe_id ) || ! $datum ) wp_send_json_error( [ 'message' => 'Fehlende Angaben.' ] );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_tri_anwesenheit (gruppe_id, slot_id, training_datum, ausgefallen, ausfall_grund, erstellt_von)
             VALUES (%d, %d, %s, %d, %s, %d)
             ON DUPLICATE KEY UPDATE ausgefallen = VALUES(ausgefallen), ausfall_grund = VALUES(ausfall_grund)",
            $gruppe_id ?: 0, $slot_id ?: 0, $datum, $ausgefallen, $grund, get_current_user_id()
        ) );

        wp_send_json_success();
    }

    public static function anw_get_sessions() {
        LSV07I_Access::check( 'tri_anw_read' );
        global $wpdb;
        $p         = $wpdb->prefix;
        $gruppe_id = absint( $_POST['gruppe_id'] ?? 0 );
        $monat     = sanitize_text_field( $_POST['monat'] ?? date( 'Y-m' ) );

        $where = $gruppe_id ? $wpdb->prepare( 'AND a.gruppe_id = %d', $gruppe_id ) : '';
        if ( $monat && preg_match( '/^\d{4}-\d{2}$/', $monat ) ) {
            $where .= $wpdb->prepare( ' AND a.training_datum LIKE %s', $monat . '-%' );
        }

        // Vergangene Saisons: nur Admins duerfen weiter zurueckblaettern als
        // den Beginn der aktiven Saison — unabhaengig vom angefragten Monat
        // (gleiches Prinzip wie bei der Schwimmen-Anwesenheit).
        if ( ! LSV07I_Access::is_admin() && class_exists( 'LSV07I_Saison' ) ) {
            $aktiv = LSV07I_Saison::active();
            if ( $aktiv && ! empty( $aktiv['start_datum'] ) ) {
                $where .= $wpdb->prepare( ' AND a.training_datum >= %s', $aktiv['start_datum'] );
            }
        }

        $rows = $wpdb->get_results(
            "SELECT a.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_anwesenheit a
               JOIN {$p}lsv07i_tri_gruppen g ON g.id = a.gruppe_id
              WHERE a.training_datum <= CURDATE() $where
           ORDER BY a.training_datum DESC LIMIT 50",
            ARRAY_A
        );

        wp_send_json_success( $rows ?: [] );
    }
}
