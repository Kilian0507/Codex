<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Nachrichten {

    public static function init() {
        $actions = [
            'lsv07i_msg_get_inbox',
            'lsv07i_msg_get_sent',
            'lsv07i_msg_send',
            'lsv07i_msg_send_mannschaft',
            'lsv07i_msg_preview_mannschaft',
            'lsv07i_msg_mark_read',
            'lsv07i_msg_delete',
            'lsv07i_msg_get_users',
            'lsv07i_msg_unread_count',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_msg_', '', $action ) ] );
        }
    }

    // ── Posteingang ──────────────────────────────────────────────────────────
    public static function get_inbox() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $uid = get_current_user_id();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT n.*, u.display_name AS von_name
               FROM {$wpdb->prefix}lsv07i_nachrichten n
               JOIN {$wpdb->prefix}users u ON u.ID = n.von_user_id
              WHERE n.an_user_id = %d
           ORDER BY n.erstellt_am DESC LIMIT 100",
            $uid
        ), ARRAY_A );
        wp_send_json_success( $rows ?: [] );
    }

    // ── Gesendet ─────────────────────────────────────────────────────────────
    public static function get_sent() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $uid = get_current_user_id();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT n.*, COALESCE(u.display_name, 'Unbekannt') AS an_name
               FROM {$wpdb->prefix}lsv07i_nachrichten n
          LEFT JOIN {$wpdb->prefix}users u ON u.ID = n.an_user_id
              WHERE n.von_user_id = %d
           ORDER BY n.erstellt_am DESC LIMIT 100",
            $uid
        ), ARRAY_A );
        wp_send_json_success( $rows ?: [] );
    }

    // ── Senden an Nutzer (mit Mail an hinterlegte Kontaktperson-Mail) ─────────
    public static function send() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p         = $wpdb->prefix;
        $uid       = get_current_user_id();
        $betreff   = sanitize_text_field( $_POST['betreff'] ?? '' );
        $nachricht = sanitize_textarea_field( $_POST['nachricht'] ?? '' );
        $an_raw    = sanitize_text_field( $_POST['an_user_ids'] ?? '' );
        $an_ids    = array_filter( array_map( 'absint', explode( ',', $an_raw ) ) );
        $an_ids    = array_values( array_filter( $an_ids, fn($id) => $id !== $uid ) );

        if ( empty( $an_ids ) || ! $betreff || ! $nachricht ) {
            wp_send_json_error( [ 'message' => 'Bitte alle Felder ausfüllen und mindestens einen Empfänger wählen.' ] );
        }

        $jetzt  = current_time( 'mysql' );
        $count  = 0;
        $mailed = 0;
        $von_name = wp_get_current_user()->display_name;

        foreach ( $an_ids as $an_uid ) {
            $an_user = get_userdata( $an_uid );
            if ( ! $an_user ) continue;

            // Im System speichern
            $wpdb->insert( $p . 'lsv07i_nachrichten', [
                'von_user_id' => $uid,
                'an_user_id'  => $an_uid,
                'betreff'     => $betreff,
                'nachricht'   => $nachricht,
                'gelesen'     => 0,
                'erstellt_am' => $jetzt,
            ], [ '%d', '%d', '%s', '%s', '%d', '%s' ] );
            $count++;

            // Mail an Kontaktperson des Schwimmers (falls vorhanden)
            // Schwimmer der diesen WP-Account hat — gibt es keinen, kein Mail
            // Stattdessen: Mail an die E-Mail des Trainer-Profils
            $trainer = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_trainer WHERE wp_user_id = %d AND aktiv = 1 LIMIT 1",
                $an_uid
            ), ARRAY_A );

            $mail_to = '';
            if ( $trainer ) {
                $mail_to = $trainer['email_geschaeft'] ?: $trainer['email_privat'] ?: $trainer['email'] ?: '';
            }

            // Schwimmer die diesen WP-Account haben → Kontaktperson
            // (Schwimmer haben keine WP-Accounts normalerweise, aber falls doch)

            if ( $mail_to && LSV07I_Mail::is_enabled() ) {
                LSV07I_Mail::send(
                    $mail_to,
                    '[LSV07] ' . $betreff,
                    "<p>Nachricht von <strong>{$von_name}</strong>:</p><p>" . nl2br( esc_html( $nachricht ) ) . "</p><hr><p style='font-size:12px;color:#888'>Diese Nachricht wurde über das Vereinssystem gesendet.</p>"
                );
                $mailed++;
            }
        }

        if ( $count === 0 ) wp_send_json_error( [ 'message' => 'Keine gültigen Empfänger.' ] );
        wp_send_json_success( [ 'message' => 'Nachricht an ' . $count . ' Empfänger gesendet' . ($mailed ? ', ' . $mailed . ' E-Mail(s) verschickt' : '') . '.', 'count' => $count ] );
    }

    // ── Senden an ganze Mannschaft ────────────────────────────────────────────
    public static function send_mannschaft() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p             = $wpdb->prefix;
        $uid           = get_current_user_id();
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $betreff       = sanitize_text_field( $_POST['betreff']   ?? '' );
        $nachricht     = sanitize_textarea_field( $_POST['nachricht'] ?? '' );

        // Ausgeschlossene E-Mail-Adressen (vom Nutzer abgewählt)
        $excluded_raw = sanitize_text_field( $_POST['excluded_emails'] ?? '' );
        $excluded     = array_filter( array_map( 'trim', array_map( 'strtolower', explode( ',', $excluded_raw ) ) ) );

        if ( ! $mannschaft_id || ! $betreff || ! $nachricht ) {
            wp_send_json_error( [ 'message' => 'Bitte alle Felder ausfüllen.' ] );
        }

        $mann = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07_gruppen WHERE id = %d LIMIT 1", $mannschaft_id
        ), ARRAY_A );
        if ( ! $mann ) wp_send_json_error( [ 'message' => 'Mannschaft nicht gefunden.' ] );

        $jetzt    = current_time( 'mysql' );
        $von_name = wp_get_current_user()->display_name;
        $mailed   = 0;
        $mail_sent_to = [];

        // 1. Alle Schwimmer der Mannschaft → Kontaktpersonen per Mail
        $schwimmer = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.first_name, s.last_name
               FROM {$p}mv_swimmers s
          LEFT JOIN {$p}lsv07i_swimmer_gruppen sg ON sg.swimmer_id = s.id
              WHERE s.active = 1 AND (s.team_id = %d OR sg.gruppe_id = %d)
           GROUP BY s.id",
            $mannschaft_id, $mannschaft_id
        ), ARRAY_A );

        foreach ( $schwimmer as $sw ) {
            $kontakte = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_kontakte WHERE swimmer_id = %d ORDER BY sort_order ASC LIMIT 3",
                $sw['id']
            ), ARRAY_A );
            foreach ( $kontakte as $k ) {
                if ( ! $k['email'] ) continue;
                if ( in_array( strtolower( $k['email'] ), $excluded, true ) ) continue;
                if ( in_array( $k['email'], $mail_sent_to, true ) ) continue;
                $mail_sent_to[] = $k['email'];
                $sw_name = esc_html( $sw['first_name'] . ' ' . $sw['last_name'] );
                LSV07I_Mail::send(
                    $k['email'],
                    '[LSV07 ' . esc_html( $mann['name'] ) . '] ' . $betreff,
                    "<p>Nachricht von <strong>{$von_name}</strong> bezüglich <strong>" . esc_html( $mann['name'] ) . "</strong>:</p><p>" . nl2br( esc_html( $nachricht ) ) . "</p><p style='font-size:12px;color:#888'>Kontaktperson für: {$sw_name}</p>"
                );
                $mailed++;
            }
        }

        // 2. Trainer der Mannschaft per Mail
        $trainer = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.* FROM {$p}lsv07i_trainer t
               JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.trainer_id = t.id
              WHERE tm.mannschaft_id = %d AND t.aktiv = 1",
            $mannschaft_id
        ), ARRAY_A );

        foreach ( $trainer as $t ) {
            $mail_to = $t['email_geschaeft'] ?: $t['email_privat'] ?: $t['email'] ?: '';
            if ( ! $mail_to ) continue;
            if ( in_array( strtolower( $mail_to ), $excluded, true ) ) continue;
            if ( in_array( $mail_to, $mail_sent_to, true ) ) continue;
            $mail_sent_to[] = $mail_to;

            // Im System als Nachricht speichern (wenn WP-Account vorhanden)
            if ( $t['wp_user_id'] ) {
                $wpdb->insert( $p . 'lsv07i_nachrichten', [
                    'von_user_id' => $uid, 'an_user_id' => $t['wp_user_id'],
                    'betreff' => $betreff, 'nachricht' => $nachricht,
                    'gelesen' => 0, 'erstellt_am' => $jetzt,
                ], [ '%d', '%d', '%s', '%s', '%d', '%s' ] );
            }
            LSV07I_Mail::send(
                $mail_to,
                '[LSV07 ' . esc_html( $mann['name'] ) . '] ' . $betreff,
                "<p>Nachricht von <strong>{$von_name}</strong> an Mannschaft <strong>" . esc_html( $mann['name'] ) . "</strong>:</p><p>" . nl2br( esc_html( $nachricht ) ) . "</p>"
            );
            $mailed++;
        }

        wp_send_json_success( [ 'message' => $mailed . ' E-Mail(s) versendet an Kontaktpersonen und Trainer der Mannschaft ' . esc_html( $mann['name'] ) . '.', 'mailed' => $mailed ] );
    }

    // ── Empfänger-Liste ───────────────────────────────────────────────────────
    public static function get_users() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $trainer = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id AS trainer_id, t.display_name, t.name, t.email, t.email_privat, t.email_geschaeft, t.wp_user_id
               FROM {$p}lsv07i_trainer t
              WHERE t.aktiv = 1 AND t.wp_user_id IS NOT NULL
                AND t.wp_user_id != 0 AND t.wp_user_id != %d
           ORDER BY t.display_name ASC, t.name ASC",
            $uid
        ), ARRAY_A );

        $result = [];
        foreach ( $trainer as $t ) {
            $result[] = [
                'id'    => (int) $t['wp_user_id'],
                'name'  => $t['display_name'] ?: $t['name'],
                'email' => $t['email_geschaeft'] ?: $t['email_privat'] ?: $t['email'] ?: '',
            ];
        }
        wp_send_json_success( $result );
    }

    // ── Vorschau: wer bekommt die Mannschafts-Mail? ──────────────────────────
    public static function preview_mannschaft() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p             = $wpdb->prefix;
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! $mannschaft_id ) wp_send_json_error( [ 'message' => 'Keine Mannschaft.' ] );

        $mann = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07_gruppen WHERE id = %d LIMIT 1", $mannschaft_id
        ), ARRAY_A );
        if ( ! $mann ) wp_send_json_error( [ 'message' => 'Mannschaft nicht gefunden.' ] );

        $liste = [];

        // Trainer der Mannschaft
        $trainer = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.* FROM {$p}lsv07i_trainer t
               JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.trainer_id = t.id
              WHERE tm.mannschaft_id = %d AND t.aktiv = 1",
            $mannschaft_id
        ), ARRAY_A );

        foreach ( $trainer as $t ) {
            $mail = $t['email_geschaeft'] ?: $t['email_privat'] ?: $t['email'] ?: '';
            if ( ! $mail ) continue;
            $liste[] = [
                'typ'   => 'trainer',
                'name'  => $t['display_name'] ?: $t['name'],
                'email' => $mail,
                'rolle' => 'Trainer',
            ];
        }

        // Schwimmer der Mannschaft → Kontaktpersonen
        $schwimmer = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.first_name, s.last_name
               FROM {$p}mv_swimmers s
          LEFT JOIN {$p}lsv07i_swimmer_gruppen sg ON sg.swimmer_id = s.id
              WHERE s.active = 1 AND (s.team_id = %d OR sg.gruppe_id = %d)
           GROUP BY s.id",
            $mannschaft_id, $mannschaft_id
        ), ARRAY_A );

        foreach ( $schwimmer as $sw ) {
            $kontakte = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_kontakte WHERE swimmer_id = %d ORDER BY sort_order ASC LIMIT 3",
                $sw['id']
            ), ARRAY_A );
            foreach ( $kontakte as $k ) {
                if ( empty( $k['email'] ) ) continue;
                $liste[] = [
                    'typ'   => 'kontakt',
                    'name'  => $k['name'] ?: '(unbenannt)',
                    'email' => $k['email'],
                    'rolle' => 'Kontakt: ' . $sw['first_name'] . ' ' . $sw['last_name'],
                ];
            }
        }

        // Dedup nach Email (erste Instanz gewinnt)
        $seen = [];
        $unique = [];
        foreach ( $liste as $item ) {
            $key = strtolower( $item['email'] );
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $unique[] = $item;
        }

        wp_send_json_success( [
            'mannschaft' => $mann['name'],
            'empfaenger' => $unique,
        ] );
    }

    // ── Ungelesene Anzahl ─────────────────────────────────────────────────────
    public static function unread_count() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $uid = get_current_user_id();
        $cnt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lsv07i_nachrichten WHERE an_user_id = %d AND gelesen = 0", $uid
        ) );
        wp_send_json_success( [ 'count' => $cnt ] );
    }

    // ── Löschen ───────────────────────────────────────────────────────────────
    public static function delete() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();
        $id  = absint( $_POST['id'] ?? 0 );
        // Darf nur eigene Nachrichten löschen (empfangen oder gesendet)
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07i_nachrichten WHERE id = %d AND (an_user_id = %d OR von_user_id = %d)",
            $id, $uid, $uid
        ) );
        wp_send_json_success();
    }

    // ── Gelesen markieren ─────────────────────────────────────────────────────
    public static function mark_read() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $id  = absint( $_POST['id'] ?? 0 );
        $uid = get_current_user_id();
        $wpdb->update( $wpdb->prefix . 'lsv07i_nachrichten',
            [ 'gelesen' => 1 ], [ 'id' => $id, 'an_user_id' => $uid ], [ '%d' ], [ '%d', '%d' ] );
        wp_send_json_success();
    }
}
