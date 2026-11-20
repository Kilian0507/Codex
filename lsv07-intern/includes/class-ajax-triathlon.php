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

    // ── Zuständigkeit ─────────────────────────────────────────────────────────
    /*  Welche Gruppen jemand sehen darf, entscheidet allein
        LSV07I_Access::erlaubte_gruppen(). Die Helfer hier wenden diese Regel
        nur auf Sessions, Sportler und Trainer dieser Sparte an. Vorher
        prüfte kein einziger Endpunkt die Gruppe: Jedes Spartenmitglied sah
        alle Sportler mit Geburtsdatum und E-Mail und konnte in jeder Gruppe
        Anwesenheit schreiben. */
    const SPARTE = 'tri';

    /**
     * Lädt eine Anwesenheits-Session und bricht ab, wenn ihre Gruppe nicht
     * zur Person gehört. Fremde und nicht vorhandene Sessions geben dieselbe
     * Antwort — sonst ließe sich durch Durchzählen der IDs erfahren, welche
     * es gibt.
     */
    private static function session_pruefen( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $session = $id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_anwesenheit a
               JOIN {$p}lsv07i_tri_gruppen g ON g.id = a.gruppe_id
              WHERE a.id = %d LIMIT 1",
            (int) $id
        ), ARRAY_A ) : null;
        if ( ! $session || ! LSV07I_Access::darf_gruppe( self::SPARTE, (int) $session['gruppe_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Session nicht gefunden.' ], 404 );
        }
        return $session;
    }

    /** Wie session_pruefen(), aber für eine Trainingszeit (Slot). */
    private static function slot_pruefen( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $slot = $id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_slots s
               JOIN {$p}lsv07i_tri_gruppen g ON g.id = s.gruppe_id
              WHERE s.id = %d LIMIT 1",
            (int) $id
        ), ARRAY_A ) : null;
        if ( ! $slot || ! LSV07I_Access::darf_gruppe( self::SPARTE, (int) $slot['gruppe_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Trainingszeit nicht gefunden.' ], 404 );
        }
        return $slot;
    }

    /** Gehört der (aktive) Sportler zu dieser Gruppe? */
    private static function sportler_in_gruppe( $sportler_id, $gruppe_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}lsv07i_tri_sportler_gruppe sg
               JOIN {$p}lsv07i_tri_sportler s ON s.id = sg.sportler_id AND s.aktiv = 1
              WHERE sg.sportler_id = %d AND sg.gruppe_id = %d LIMIT 1",
            (int) $sportler_id, (int) $gruppe_id ) );
    }

    /** Ist der Trainer dieser Gruppe zugeordnet? */
    private static function trainer_in_gruppe( $trainer_id, $gruppe_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}lsv07i_tri_trainer_gruppe
              WHERE trainer_id = %d AND gruppe_id = %d LIMIT 1",
            (int) $trainer_id, (int) $gruppe_id ) );
    }

    /** Datum im Format JJJJ-MM-TT und ein echter Kalendertag? */
    private static function datum_gueltig( $datum ) {
        return is_string( $datum ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $m )
            && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }

    /**
     * Fehlt ein feines Recht? Administration und Wart dürfen immer.
     * Erzwungen werden nur Rechte, die in der Vorlage „Trainer Triathlon"
     * stehen — sonst wären gewöhnliche Trainer ausgesperrt.
     */
    private static function recht_pruefen( $recht ) {
        if ( LSV07I_Access::is_triathlonwart() ) return;
        if ( ! LSV07I_Permissions::can_current( $recht ) ) {
            wp_send_json_error( [ 'message' => 'Dafür fehlt die Berechtigung.' ], 403 );
        }
    }

    // ── Stammdaten laden (Gruppen + Trainingszeiten) ──────────────────────────
    public static function get_data() {
        LSV07I_Access::check( 'tri_read' );
        global $wpdb;
        $p       = $wpdb->prefix;
        $erlaubt = LSV07I_Access::erlaubte_gruppen( self::SPARTE );

        $gruppen = $wpdb->get_results(
            "SELECT g.id, g.name, g.sort_order, COUNT(sg.sportler_id) AS sportler_anzahl
               FROM {$p}lsv07i_tri_gruppen g
          LEFT JOIN {$p}lsv07i_tri_sportler_gruppe sg ON sg.gruppe_id = g.id
              WHERE " . LSV07I_Access::sql_in( 'g.id', $erlaubt ) . "
           GROUP BY g.id ORDER BY g.sort_order ASC, g.name ASC",
            ARRAY_A
        );

        /* Trainer: nur Name und Gruppen. Die Oberfläche liest diese Liste
           gar nicht (die Trainerübersicht holt sich get_trainer() mit eigenem
           Leserecht) — vorher gingen hier E-Mail und WP-Konto aller Trainer
           an jedes Spartenmitglied. Wer nicht alle Gruppen sieht, sieht nur
           die Trainer seiner Gruppen. */
        $trainer = $wpdb->get_results(
            "SELECT t.id, t.name, GROUP_CONCAT(tg.gruppe_id) AS gruppe_ids
               FROM {$p}lsv07i_tri_trainer t
          LEFT JOIN {$p}lsv07i_tri_trainer_gruppe tg
                 ON tg.trainer_id = t.id AND " . LSV07I_Access::sql_in( 'tg.gruppe_id', $erlaubt ) . "
              WHERE t.aktiv = 1
           GROUP BY t.id " . ( $erlaubt === null ? '' : 'HAVING gruppe_ids IS NOT NULL' ) . "
           ORDER BY t.name ASC",
            ARRAY_A
        );

        $slots = $wpdb->get_results(
            "SELECT s.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_slots s
          LEFT JOIN {$p}lsv07i_tri_gruppen g ON g.id = s.gruppe_id
              WHERE " . LSV07I_Access::sql_in( 's.gruppe_id', $erlaubt ) . "
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
        $erlaubt    = LSV07I_Access::erlaubte_gruppen( self::SPARTE );

        if ( $gruppe_id ) {
            LSV07I_Access::gruppe_pruefen( self::SPARTE, $gruppe_id );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT s.id, s.first_name, s.last_name, s.birth_date, s.email, s.notes
                   FROM {$p}lsv07i_tri_sportler s
                   JOIN {$p}lsv07i_tri_sportler_gruppe sg ON sg.sportler_id = s.id AND sg.gruppe_id = %d
                  WHERE s.aktiv = 1
               ORDER BY s.last_name ASC, s.first_name ASC",
                $gruppe_id
            ), ARRAY_A );
        } else {
            // Ohne Gruppe: nur Sportler der eigenen Gruppen. Wer alle sieht
            // (Wart, Administration), bekommt auch die ohne Gruppe.
            $nur_eigene = $erlaubt === null ? '' :
                "AND EXISTS (SELECT 1 FROM {$p}lsv07i_tri_sportler_gruppe sg
                              WHERE sg.sportler_id = s.id AND " . LSV07I_Access::sql_in( 'sg.gruppe_id', $erlaubt ) . ')';
            $rows = $wpdb->get_results(
                "SELECT s.id, s.first_name, s.last_name, s.birth_date, s.email, s.notes
                   FROM {$p}lsv07i_tri_sportler s
                  WHERE s.aktiv = 1 $nur_eigene
               ORDER BY s.last_name ASC, s.first_name ASC",
                ARRAY_A
            );
        }

        /* Felder nach Rolle. Name und Gruppen braucht jede Liste.
           Geburtsdatum und E-Mail zeigt der Reiter „Mannschaften" — sie
           gehen an Wart und an alle mit dem Recht „Sportler-Liste sehen"
           (steht in der Trainer-Vorlage). Notizen braucht nur das
           Bearbeiten-Formular, und das hat nur der Wart. */
        $ist_wart = LSV07I_Access::is_triathlonwart();
        $kontakt  = $ist_wart || LSV07I_Permissions::can_current( LSV07I_Permissions::TRIATHLON_SPORTLER_READ );

        // Gruppen-Zuordnungen + Gruppennamen in 2 statt 2N Queries laden
        $out = [];
        if ( ! empty( $rows ) ) {
            $sportler_ids = array_filter( array_map( fn( $r ) => (int) $r['id'], $rows ), fn( $i ) => $i > 0 );
            $sg_map = []; $gn_map = [];
            if ( ! empty( $sportler_ids ) ) {
                $ids_in = implode( ',', $sportler_ids );
                // Fremde Gruppen eines Sportlers bleiben unsichtbar — sonst
                // landete er in der Liste unter dem Namen der fremden Gruppe.
                $sg_rows = $wpdb->get_results(
                    "SELECT sportler_id, gruppe_id FROM {$p}lsv07i_tri_sportler_gruppe
                      WHERE sportler_id IN ($ids_in) AND " . LSV07I_Access::sql_in( 'gruppe_id', $erlaubt ), ARRAY_A );
                $alle_gruppen = [];
                foreach ( $sg_rows as $sg ) {
                    $sid = (int) $sg['sportler_id']; $gid = (int) $sg['gruppe_id'];
                    $sg_map[ $sid ][] = $gid;
                    $alle_gruppen[ $gid ] = true;
                }
                if ( ! empty( $alle_gruppen ) ) {
                    $gids_in = implode( ',', array_keys( $alle_gruppen ) );
                    $gn_rows = $wpdb->get_results(
                        "SELECT id, name FROM {$p}lsv07i_tri_gruppen
                          WHERE id IN ($gids_in) ORDER BY sort_order ASC", ARRAY_A );
                    foreach ( $gn_rows as $gn ) $gn_map[ (int) $gn['id'] ] = $gn['name'];
                }
            }
            foreach ( $rows as $row ) {
                $sid  = (int) $row['id'];
                $gids = $sg_map[ $sid ] ?? [];
                $s = [
                    'id'            => $sid,
                    'first_name'    => $row['first_name'],
                    'last_name'     => $row['last_name'],
                    'gruppe_ids'    => $gids,
                    'gruppen_namen' => array_values( array_filter( array_map(
                        fn( $gid ) => $gn_map[ $gid ] ?? null, $gids
                    ) ) ),
                ];
                if ( $kontakt ) {
                    $s['birth_date'] = $row['birth_date'];
                    $s['email']      = $row['email'];
                }
                if ( $ist_wart ) $s['notes'] = $row['notes'];
                $out[] = $s;
            }
        }

        wp_send_json_success( $out );
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
        // Die Übersicht zeigt Name, E-Mail und Gruppen. Das WP-Konto braucht
        // nur das Bearbeiten-Formular — und das hat nur der Wart.
        $wp_konto = LSV07I_Access::is_triathlonwart() ? ', t.wp_user_id' : '';
        $rows = $wpdb->get_results(
            "SELECT t.id, t.name, t.email{$wp_konto}, GROUP_CONCAT(tg.gruppe_id) AS gruppe_ids
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
        if ( $gruppe_id ) LSV07I_Access::gruppe_pruefen( self::SPARTE, $gruppe_id );
        $where     = 'WHERE ' . LSV07I_Access::sql_in( 's.gruppe_id', LSV07I_Access::erlaubte_gruppen( self::SPARTE ) )
                   . ( $gruppe_id ? $wpdb->prepare( ' AND s.gruppe_id = %d', $gruppe_id ) : '' );
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
        if ( ! self::datum_gueltig( $datum ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiges Datum.' ] );
        }

        // Gruppe prüfen, BEVOR eine Session angelegt wird.
        $slot      = self::slot_pruefen( $slot_id );
        $gruppe_id = (int) $slot['gruppe_id'];

        $suche = $wpdb->prepare(
            "SELECT a.*, g.name AS gruppe_name
               FROM {$p}lsv07i_tri_anwesenheit a
               JOIN {$p}lsv07i_tri_gruppen g ON g.id = a.gruppe_id
              WHERE a.slot_id = %d AND a.training_datum = %s LIMIT 1",
            $slot_id, $datum
        );
        $session = $wpdb->get_row( $suche, ARRAY_A );

        if ( ! $session ) {
            self::recht_pruefen( LSV07I_Permissions::TRIATHLON_ANW_CREATE );
            // IGNORE + erneut lesen: zwei gleichzeitige Aufrufe legen nicht
            // zwei Sessions an, und keiner bekommt eine leere Antwort.
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$p}lsv07i_tri_anwesenheit
                    (gruppe_id, slot_id, training_datum, ausgefallen, erstellt_von)
                 VALUES (%d, %d, %s, 0, %d)",
                $gruppe_id, $slot_id, $datum, get_current_user_id()
            ) );
            $session = $wpdb->get_row( $suche, ARRAY_A );
            if ( ! $session ) wp_send_json_error( [ 'message' => 'Anwesenheit konnte nicht angelegt werden.' ] );
        }

        /* Die Session hängt an der Gruppe, die sie beim Anlegen hatte. Wurde
           der Slot inzwischen einer anderen Gruppe gegeben, zählt die
           Gruppe der Session — sonst sähe der neue Trainer die alten
           Einträge der fremden Gruppe. */
        if ( ! LSV07I_Access::darf_gruppe( self::SPARTE, (int) $session['gruppe_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Trainingszeit nicht gefunden.' ], 404 );
        }

        $eintraege = $wpdb->get_results( $wpdb->prepare(
            "SELECT sportler_id, status FROM {$p}lsv07i_tri_anwesenheit_eintraege WHERE anwesenheit_id = %d",
            $session['id']
        ), ARRAY_A );

        // Für die Strichliste genügen Name und ID.
        $sportler = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.first_name, s.last_name
               FROM {$p}lsv07i_tri_sportler s
               JOIN {$p}lsv07i_tri_sportler_gruppe sg ON sg.sportler_id = s.id AND sg.gruppe_id = %d
              WHERE s.aktiv = 1
           ORDER BY s.last_name ASC, s.first_name ASC",
            (int) $session['gruppe_id']
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
        $status         = sanitize_text_field( $_POST['status'] ?? '' );

        if ( ! $anwesenheit_id || ! $sportler_id ) {
            wp_send_json_error( [ 'message' => 'Unvollständige Daten.' ] );
        }
        // Unbekannter Status wird abgelehnt statt still zu „anwesend" zu werden.
        if ( ! in_array( $status, [ 'anwesend', 'abwesend', 'entschuldigt' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiger Status.' ] );
        }
        self::recht_pruefen( LSV07I_Permissions::TRIATHLON_ANW_UPDATE );

        /* Session gehört zur eigenen Gruppe, und der Sportler gehört zu
           dieser Gruppe. Vorher ließ sich jede Session-ID mit jeder
           Sportler-ID füllen. */
        $session = self::session_pruefen( $anwesenheit_id );
        if ( ! self::sportler_in_gruppe( $sportler_id, (int) $session['gruppe_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Dieser Sportler gehört nicht zu diesem Training.' ], 403 );
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
     *
     * Eingetragen wird nur für die aufrufende Person selbst und nur, wenn sie
     * Trainer DIESER Gruppe ist. Vorher genügte irgendeine Triathlon-Session:
     * Jeder Trainer konnte sich fremde Trainings abrechnen — und die
     * Triathlon-Trainer-ID landete in der Spalte für das allgemeine
     * Trainerprofil, also in der Abrechnung eines beliebigen anderen
     * Trainers mit derselben Nummer.
     */
    public static function anw_trainer_abr() {
        LSV07I_Access::check( 'tri_intern' );
        global $wpdb;
        $p              = $wpdb->prefix;
        $anwesenheit_id = absint( $_POST['anwesenheit_id'] ?? 0 );
        if ( ! $anwesenheit_id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        $session = self::session_pruefen( $anwesenheit_id );

        // Kein Trainer dieser Gruppe (z. B. der Wart erfasst vertretungsweise)
        // → kein Eintrag. Kein Fehler: das JS ruft dies nach jedem Speichern auf.
        $tri_trainer_id = LSV07I_Access::get_tri_trainer_id();
        if ( ! $tri_trainer_id || ! self::trainer_in_gruppe( $tri_trainer_id, (int) $session['gruppe_id'] ) ) {
            wp_send_json_success( [ 'skipped' => true ] );
        }
        if ( ! empty( $session['ausgefallen'] ) ) {
            wp_send_json_success( [ 'skipped' => true ] );
        }

        /* lsv07i_abrechnung.trainer_id ist das allgemeine Trainerprofil
           (lsv07i_trainer) — für alle Sparten. Welches Profil das der Person
           ist, beantwortet die Abrechnung selbst; dieselbe Stelle legt es
           bei Bedarf an, wie beim Öffnen der eigenen Abrechnung. */
        $abr_trainer_id = class_exists( 'LSV07I_Ajax_Abrechnung' )
            ? (int) LSV07I_Ajax_Abrechnung::get_or_create_trainer_id() : 0;
        if ( ! $abr_trainer_id ) {
            wp_send_json_success( [ 'skipped' => true ] );
        }

        $ts      = strtotime( $session['training_datum'] );
        $monat   = (int) date( 'n', $ts );
        $jahr    = (int) date( 'Y', $ts );
        $quartal = 'Q' . ceil( $monat / 3 );

        // Triathlon-Abrechnung holen oder anlegen (bereich = 'triathlon')
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, abr_status FROM {$p}lsv07i_abrechnung
              WHERE trainer_id = %d AND bereich = 'triathlon' AND quartal = %s AND jahr = %d LIMIT 1",
            $abr_trainer_id, $quartal, $jahr
        ), ARRAY_A );

        if ( ! $abr ) {
            $wpdb->insert( $p . 'lsv07i_abrechnung', [
                'trainer_id' => $abr_trainer_id,
                'bereich'    => 'triathlon',
                'quartal'    => $quartal,
                'jahr'       => $jahr,
                'abr_status' => 'entwurf',
            ], [ '%d', '%s', '%s', '%d', '%s' ] );
            $abr_id = (int) $wpdb->insert_id;
        } else {
            // Eingereichte oder genehmigte Abrechnungen ändert kein Automatismus.
            if ( ! in_array( $abr['abr_status'], [ 'entwurf', 'zurueck' ], true ) ) {
                wp_send_json_success( [ 'skipped' => true ] );
            }
            $abr_id = (int) $abr['id'];
        }
        if ( ! $abr_id ) wp_send_json_error( [ 'message' => 'Abrechnung konnte nicht angelegt werden.' ] );

        /* Je Session ein Eintrag (über anwesenheit_id) — zwei Gruppen am
           selben Tag sind zwei Trainings. Alte Einträge ohne anwesenheit_id
           zählen weiter je Datum, damit nichts doppelt erscheint. Auch ein
           manuell gelöschter Eintrag zählt: der Trainer hat ihn bewusst
           entfernt. */
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_abr_trainingstage
              WHERE abrechnung_id = %d AND quelle = 'triathlon'
                AND ( anwesenheit_id = %d OR ( anwesenheit_id IS NULL AND datum = %s ) )
              LIMIT 1",
            $abr_id, (int) $session['id'], $session['training_datum']
        ) );

        if ( ! $exists ) {
            $stunden = self::stunden_der_trainingszeit( (int) $session['slot_id'] );
            $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                'abrechnung_id'    => $abr_id,
                'datum'            => $session['training_datum'],
                'mannschaft_name'  => $session['gruppe_name'],
                'stunden'          => $stunden,
                'stunden_plan'     => $stunden,
                'quelle'           => 'triathlon',
                'anwesenheit_id'   => (int) $session['id'],
                'begruendung'      => 'Triathlon-Training (automatisch)',
                'manuell_geloescht'=> 0,
            ], [ '%d', '%s', '%s', '%f', '%f', '%s', '%d', '%s', '%d' ] );
        }

        wp_send_json_success( [ 'abr_id' => $abr_id ] );
    }

    /**
     * Stunden aus der Trainingszeit (Slot) statt pauschal 2 h. Ohne Slot
     * oder ohne Zeiten (00:00–00:00) steht 0 da und muss nachgetragen
     * werden — eine erfundene Zahl sähe aus wie ein Ergebnis.
     */
    private static function stunden_der_trainingszeit( $slot_id ) {
        global $wpdb;
        $slot = $slot_id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT zeit_von, zeit_bis FROM {$wpdb->prefix}lsv07i_tri_slots WHERE id = %d LIMIT 1", $slot_id
        ), ARRAY_A ) : null;
        if ( ! $slot ) return 0.0;
        $sekunden = strtotime( '1970-01-01 ' . $slot['zeit_bis'] . ' UTC' ) - strtotime( '1970-01-01 ' . $slot['zeit_von'] . ' UTC' );
        return $sekunden > 0 ? round( $sekunden / 3600, 2 ) : 0.0;
    }

    public static function anw_mark_ausfall() {
        LSV07I_Access::check( 'tri_intern' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $slot_id    = absint( $_POST['slot_id']    ?? 0 );
        $gruppe_id  = absint( $_POST['gruppe_id']  ?? 0 );
        $datum      = sanitize_text_field( $_POST['datum']      ?? '' );
        $ausgefallen= absint( $_POST['ausgefallen'] ?? 1 ) ? 1 : 0;
        $grund      = mb_substr( sanitize_text_field( $_POST['grund'] ?? '' ), 0, 255 );

        /* Ohne Trainingszeit kein Ausfall. Früher reichte die Gruppe; die
           Session bekam dann slot_id 0 — und weil (slot_id, Datum) eindeutig
           ist, überschrieb der Ausfall einer Gruppe die Session einer
           anderen am selben Tag. Die Oberfläche schickt immer die
           Trainingszeit. */
        if ( ! $slot_id || ! $datum ) wp_send_json_error( [ 'message' => 'Fehlende Angaben.' ] );
        if ( ! self::datum_gueltig( $datum ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiges Datum.' ] );
        }

        $slot = self::slot_pruefen( $slot_id );
        if ( $gruppe_id && $gruppe_id !== (int) $slot['gruppe_id'] ) {
            wp_send_json_error( [ 'message' => 'Trainingszeit nicht gefunden.' ], 404 );
        }
        self::recht_pruefen( LSV07I_Permissions::TRIATHLON_ANW_AUSFALL );

        // Gibt es die Session schon, muss auch IHRE Gruppe passen.
        $vorhanden = $wpdb->get_var( $wpdb->prepare(
            "SELECT gruppe_id FROM {$p}lsv07i_tri_anwesenheit WHERE slot_id = %d AND training_datum = %s LIMIT 1",
            $slot_id, $datum ) );
        if ( $vorhanden !== null && ! LSV07I_Access::darf_gruppe( self::SPARTE, (int) $vorhanden ) ) {
            wp_send_json_error( [ 'message' => 'Trainingszeit nicht gefunden.' ], 404 );
        }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_tri_anwesenheit (gruppe_id, slot_id, training_datum, ausgefallen, ausfall_grund, erstellt_von)
             VALUES (%d, %d, %s, %d, %s, %d)
             ON DUPLICATE KEY UPDATE ausgefallen = VALUES(ausgefallen), ausfall_grund = VALUES(ausfall_grund)",
            (int) $slot['gruppe_id'], $slot_id, $datum, $ausgefallen, $grund, get_current_user_id()
        ) );

        wp_send_json_success();
    }

    public static function anw_get_sessions() {
        LSV07I_Access::check( 'tri_anw_read' );
        global $wpdb;
        $p         = $wpdb->prefix;
        $gruppe_id = absint( $_POST['gruppe_id'] ?? 0 );
        $monat     = sanitize_text_field( $_POST['monat'] ?? date( 'Y-m' ) );

        if ( $gruppe_id ) LSV07I_Access::gruppe_pruefen( self::SPARTE, $gruppe_id );
        // Ohne Gruppe: nur die eigenen Gruppen.
        $where = 'AND ' . LSV07I_Access::sql_in( 'a.gruppe_id', LSV07I_Access::erlaubte_gruppen( self::SPARTE ) );
        if ( $gruppe_id ) $where .= $wpdb->prepare( ' AND a.gruppe_id = %d', $gruppe_id );
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
