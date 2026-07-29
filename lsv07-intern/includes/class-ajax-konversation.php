<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX-Endpunkte für das neue Konversations-System.
 *
 *   lsv07i_konv_list           – Konversationen des aktuellen Users
 *   lsv07i_konv_messages       – Nachrichten einer Konversation
 *   lsv07i_konv_send           – Nachricht senden
 *   lsv07i_konv_create         – Neue Konversation/Gruppe anlegen
 *   lsv07i_konv_add_teilnehmer – Teilnehmer hinzufügen
 *   lsv07i_konv_remove_teil    – Teilnehmer entfernen / verlassen
 *   lsv07i_konv_mark_read      – Bis zur Msg-ID als gelesen markieren
 *   lsv07i_konv_unread         – Ungelesen-Total für Topbar
 *   lsv07i_konv_search_users   – Userpicker für "Neuer Chat"
 *   lsv07i_konv_mannschaften   – Liste der Mannschaften für Auto-Verteiler
 */
class LSV07I_Ajax_Konversation {

    public static function init() {
        $actions = [
            'list', 'messages', 'send', 'create',
            'add_teilnehmer', 'remove_teil',
            'mark_read', 'unread',
            'search_users', 'mannschaften',
            'anhang_upload', 'anhang_dl',
            'msg_edit', 'msg_delete', 'msg_reaktion',
            'update_meta', 'delete_konv',
            'set_admin', 'leave',
            'heartbeat', 'read_status',
            'typing_set', 'typing_get',
        ];
        foreach ( $actions as $a ) {
            add_action( 'wp_ajax_lsv07i_konv_' . $a, [ __CLASS__, $a ] );
        }
        // anhang_dl ist token-basiert und laut eigenem Kommentar bewusst
        // OHNE Login-Zwang gedacht (z.B. für externe E-Mail-Empfänger) —
        // dafür braucht es zusaetzlich die nopriv-Variante, sonst bricht
        // WordPress bei nicht angemeldeten Aufrufen vorzeitig ab, ohne
        // dass anhang_dl() je erreicht wird.
        add_action( 'wp_ajax_nopriv_lsv07i_konv_anhang_dl', [ __CLASS__, 'anhang_dl' ] );
    }

    private static function nonce() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
        // Jede Aktivität im Chat aktualisiert den Online-Timestamp.
        // Zentral hier, statt in jeder Methode duplizieren.
        LSV07I_Konversation::touch_online();
    }

    private static function require_read() {
        if ( current_user_can( 'administrator' ) ) return;
        if ( LSV07I_Permissions::can_current( LSV07I_Permissions::NACHRICHTEN_READ ) ) return;
        wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
    }

    private static function require_send() {
        if ( current_user_can( 'administrator' ) ) return;
        if ( LSV07I_Permissions::can_current( LSV07I_Permissions::NACHRICHTEN_SEND ) ) return;
        wp_send_json_error( [ 'message' => 'Keine Berechtigung zum Senden.' ], 403 );
    }

    /**
     * Prüft ob der aktuelle User Teilnehmer der Konversation ist.
     * Liefert die Teilnehmer-Zeile oder sendet 403.
     */
    /** z.B. "2M" -> 2097152 (Bytes). Fuer verstaendliche Fehlermeldungen. */
    private static function bytes_from_ini( $val ) {
        $val = trim( (string) $val );
        if ( $val === '' ) return 0;
        $last = strtolower( $val[ strlen( $val ) - 1 ] );
        $num  = (int) $val;
        switch ( $last ) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }

    private static function format_bytes( $bytes ) {
        if ( $bytes <= 0 ) return '?';
        if ( $bytes >= 1024 * 1024 ) return round( $bytes / (1024*1024), 1 ) . ' MB';
        return round( $bytes / 1024 ) . ' KB';
    }

    private static function require_member( $konv_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_teilnehmer
              WHERE konv_id = %d
                AND teilnehmer_typ = 'user'
                AND teilnehmer_id = %d
                AND verlassen_am IS NULL
              LIMIT 1",
            (int) $konv_id, get_current_user_id()
        ), ARRAY_A );
        if ( ! $row && ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( [ 'message' => 'Du bist nicht Teil dieser Konversation.' ], 403 );
        }
        return $row;
    }

    // ─── Konversations-Liste ──────────────────────────────────────────────
    public static function list() {
        self::nonce();
        self::require_read();
        $uid  = get_current_user_id();
        $rows = LSV07I_Konversation::liste_fuer_user( $uid );

        // Für jede Konversation: Teilnehmer-Vorschau + letzte Nachricht laden
        global $wpdb;
        $p = $wpdb->prefix;
        $uid = get_current_user_id();
        // Teilnehmer + letzte Nachricht für ALLE Konversationen in einem Schwung
        if ( ! empty( $rows ) ) {
            $konv_ids = array_filter( array_map( fn( $k ) => (int) $k['id'], $rows ), fn( $i ) => $i > 0 );
            if ( ! empty( $konv_ids ) ) {
                $ids_in = implode( ',', $konv_ids );

                // Alle aktiven Teilnehmer in einer Abfrage
                $teil_rows = $wpdb->get_results(
                    "SELECT konv_id, id, teilnehmer_typ, teilnehmer_id, teilnehmer_email, teilnehmer_name, ist_admin
                       FROM {$p}lsv07i_konv_teilnehmer
                      WHERE konv_id IN ($ids_in) AND verlassen_am IS NULL
                   ORDER BY ist_admin DESC, hinzugefuegt_am ASC", ARRAY_A );
                $teil_map = [];
                $user_ids_to_resolve = [];
                foreach ( $teil_rows as $t ) {
                    $kid = (int) $t['konv_id']; unset( $t['konv_id'] );
                    // Pro Konv max. 50 anzeigen (wie vorher)
                    if ( ! isset( $teil_map[ $kid ] ) || count( $teil_map[ $kid ] ) < 50 ) {
                        $teil_map[ $kid ][] = $t;
                    }
                    if ( $t['teilnehmer_typ'] === 'user' && empty( $t['teilnehmer_name'] ) ) {
                        $user_ids_to_resolve[ (int) $t['teilnehmer_id'] ] = true;
                    }
                }

                // Letzte Nachricht je Konversation (greatest-n-per-group via JOIN)
                $msg_rows = $wpdb->get_results(
                    "SELECT m.konv_id, m.id, m.von_typ, m.von_id, m.von_name, m.text, m.erstellt_am
                       FROM {$p}lsv07i_konv_msg m
                       JOIN (
                           SELECT konv_id, MAX(id) AS max_id
                             FROM {$p}lsv07i_konv_msg
                            WHERE konv_id IN ($ids_in) AND geloescht_am IS NULL
                            GROUP BY konv_id
                       ) latest ON latest.konv_id = m.konv_id AND latest.max_id = m.id", ARRAY_A );
                $msg_map = [];
                foreach ( $msg_rows as $m ) {
                    $kid = (int) $m['konv_id']; unset( $m['konv_id'] );
                    $m['text_preview'] = mb_substr( preg_replace( "/\s+/u", ' ', $m['text'] ), 0, 80 );
                    if ( $m['von_typ'] === 'user' && empty( $m['von_name'] ) ) {
                        $user_ids_to_resolve[ (int) $m['von_id'] ] = true;
                    }
                    $msg_map[ $kid ] = $m;
                }

                // User-Display-Namen einmalig auflösen
                $user_name_map = [];
                if ( ! empty( $user_ids_to_resolve ) ) {
                    $uids_in = implode( ',', array_keys( $user_ids_to_resolve ) );
                    $u_rows = $wpdb->get_results(
                        "SELECT ID, user_login, display_name FROM {$wpdb->users} WHERE ID IN ($uids_in)", ARRAY_A );
                    foreach ( $u_rows as $u ) {
                        $user_name_map[ (int) $u['ID'] ] = $u['display_name'] ?: $u['user_login'];
                    }
                }

                $is_wp_admin = current_user_can( 'administrator' );
                foreach ( $rows as &$k ) {
                    $kid = (int) $k['id'];
                    $k['teilnehmer'] = $teil_map[ $kid ] ?? [];
                    // Namen auflösen
                    foreach ( $k['teilnehmer'] as &$t ) {
                        if ( $t['teilnehmer_typ'] === 'user' && empty( $t['teilnehmer_name'] ) ) {
                            $t['teilnehmer_name'] = $user_name_map[ (int) $t['teilnehmer_id'] ] ?? '(unbekannt)';
                        }
                    }
                    unset( $t );
                    // mein_admin-Flag
                    $k['mein_admin'] = $is_wp_admin ? 1 : 0;
                    if ( ! $is_wp_admin ) {
                        foreach ( $k['teilnehmer'] as $t ) {
                            if ( $t['teilnehmer_typ'] === 'user'
                              && (int) $t['teilnehmer_id'] === $uid
                              && (int) $t['ist_admin'] === 1 ) {
                                $k['mein_admin'] = 1; break;
                            }
                        }
                    }
                    // Letzte Nachricht
                    $last = $msg_map[ $kid ] ?? null;
                    if ( $last && $last['von_typ'] === 'user' && empty( $last['von_name'] ) ) {
                        $last['von_name'] = $user_name_map[ (int) $last['von_id'] ] ?? '?';
                    }
                    $k['letzte_msg'] = $last;
                }
                unset( $k );
            }
        }

        wp_send_json_success( [
            'konversationen' => array_values( $rows ),
            'user_id'        => $uid,
        ] );
    }

    // ─── Nachrichten einer Konversation ───────────────────────────────────
    public static function messages() {
        self::nonce();
        self::require_read();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );
        self::require_member( $konv_id );

        $since = isset( $_POST['since'] ) ? (int) $_POST['since'] : 0;
        global $wpdb;
        $p = $wpdb->prefix;

        $where_since = $since ? $wpdb->prepare( ' AND id > %d', $since ) : '';
        $msgs = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*
               FROM {$p}lsv07i_konv_msg m
              WHERE m.konv_id = %d
                AND m.geloescht_am IS NULL
                $where_since
              ORDER BY m.id ASC",
            $konv_id
        ), ARRAY_A );

        // User-Namen für 'user'-Absender auflösen
        $needs = [];
        foreach ( $msgs as $m ) {
            if ( $m['von_typ'] === 'user' && empty( $m['von_name'] ) ) {
                $needs[] = (int) $m['von_id'];
            }
        }
        $names = [];
        if ( $needs ) {
            $needs = array_unique( $needs );
            $users = get_users( [ 'include' => $needs, 'fields' => [ 'ID', 'display_name', 'user_login' ] ] );
            foreach ( $users as $u ) {
                $names[ (int) $u->ID ] = $u->display_name ?: $u->user_login;
            }
        }

        // Anhänge pro Nachricht laden (eine Query)
        $msg_ids = array_map( fn( $m ) => (int) $m['id'], $msgs );
        $anhaenge_by_msg = [];
        if ( $msg_ids ) {
            $in = implode( ',', $msg_ids );
            $rows = $wpdb->get_results(
                "SELECT id, msg_id, dateiname, mime_type, groesse, token
                   FROM {$p}lsv07i_konv_anhang
                  WHERE msg_id IN ($in)",
                ARRAY_A
            );
            foreach ( $rows as $a ) {
                $anhaenge_by_msg[ (int) $a['msg_id'] ][] = $a;
            }
        }

        // Reaktionen für die zurückgelieferten Nachrichten laden (aggregiert pro Emoji)
        $reakt_by_msg = [];
        if ( $msg_ids ) {
            $in = implode( ',', $msg_ids );
            $rows = $wpdb->get_results(
                "SELECT msg_id, emoji, user_id, COUNT(*) OVER (PARTITION BY msg_id, emoji) AS cnt
                   FROM {$p}lsv07i_konv_reaktion
                  WHERE msg_id IN ($in)",
                ARRAY_A
            );
            // Aggregation in PHP (kompatibel auch ohne window functions falls
            // MariaDB älter ist — wir verwerfen 'cnt' und zählen lokal)
            $uid = get_current_user_id();
            foreach ( (array) $rows as $r ) {
                $mid = (int) $r['msg_id'];
                $em  = $r['emoji'];
                if ( ! isset( $reakt_by_msg[ $mid ][ $em ] ) ) {
                    $reakt_by_msg[ $mid ][ $em ] = [ 'emoji' => $em, 'count' => 0, 'has_mine' => false ];
                }
                $reakt_by_msg[ $mid ][ $em ]['count']++;
                if ( (int) $r['user_id'] === $uid ) {
                    $reakt_by_msg[ $mid ][ $em ]['has_mine'] = true;
                }
            }
        }

        foreach ( $msgs as &$m ) {
            if ( empty( $m['von_name'] ) && $m['von_typ'] === 'user' ) {
                $m['von_name'] = $names[ (int) $m['von_id'] ] ?? '?';
            }
            // Profilbild bzw. Initialen des Absenders für die Anzeige im Chat
            $m['von_bild']      = '';
            $m['von_initialen'] = '';
            if ( $m['von_typ'] === 'user' ) {
                $m['von_bild']      = self::avatar_url( (int) $m['von_id'] );
                $m['von_initialen'] = self::avatar_initialen( (int) $m['von_id'], $m['von_name'] );
            }
            $m['anhaenge'] = $anhaenge_by_msg[ (int) $m['id'] ] ?? [];
            // Reaktionen als sortierte Liste (für stabile Anzeige)
            $r_list = array_values( $reakt_by_msg[ (int) $m['id'] ] ?? [] );
            $m['reaktionen'] = $r_list;
        }
        unset( $m );

        wp_send_json_success( [ 'messages' => $msgs ] );
    }

    // ─── Nachricht senden ─────────────────────────────────────────────────
    public static function send() {
        self::nonce();
        self::require_send();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );
        self::require_member( $konv_id );

        $text = wp_unslash( $_POST['text'] ?? '' );
        // Plain-Text behalten, aber gefährliche Tags weg
        $text = wp_kses_post( $text );

        $dringend = ! empty( $_POST['dringend'] );

        $msg_id = LSV07I_Konversation::nachricht_senden( $konv_id, $text, [
            'dringend' => $dringend,
        ] );
        if ( is_wp_error( $msg_id ) ) {
            wp_send_json_error( [ 'message' => $msg_id->get_error_message() ] );
        }

        // Externe Empfänger in die E-Mail-Queue legen (Iter 3 verarbeitet diese)
        self::queue_external_recipients( $konv_id, $msg_id, $dringend );

        wp_send_json_success( [ 'msg_id' => $msg_id ] );
    }

    /**
     * Legt für alle nicht-user-Teilnehmer (Kontakte, freie E-Mails) eine
     * Zeile in der E-Mail-Queue an. Der Versand selbst läuft im Cronjob.
     */
    private static function queue_external_recipients( $konv_id, $msg_id, $dringend ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $externs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_teilnehmer
              WHERE konv_id = %d
                AND teilnehmer_typ IN ('kontakt','email')
                AND verlassen_am IS NULL",
            (int) $konv_id
        ), ARRAY_A );

        foreach ( (array) $externs as $t ) {
            $empf = $t['teilnehmer_email'];
            if ( $t['teilnehmer_typ'] === 'kontakt' && ! $empf ) {
                // E-Mail aus Kontakte-Tabelle holen
                $empf = $wpdb->get_var( $wpdb->prepare(
                    "SELECT email FROM {$p}lsv07i_kontakte WHERE id = %d",
                    (int) $t['teilnehmer_id']
                ) );
            }
            if ( ! $empf || ! is_email( $empf ) ) continue;

            // Schutz: falls die E-Mail-Adresse einem WP-User gehört, NICHT mailen.
            // Interne User bekommen ihre Nachrichten über den Chat.
            if ( get_user_by( 'email', $empf ) ) continue;

            $token = bin2hex( random_bytes( 16 ) );
            $wpdb->insert( $p . 'lsv07i_email_queue', [
                'msg_id'         => (int) $msg_id,
                'empfaenger'     => $empf,
                'empfaenger_typ' => $t['teilnehmer_typ'],
                'reply_token'    => $token,
                'dringend'       => $dringend ? 1 : 0,
                'status'         => 'warten',
            ], [ '%d', '%s', '%s', '%s', '%d', '%s' ] );
        }
    }

    // ─── Neue Konversation anlegen ────────────────────────────────────────
    public static function create() {
        self::nonce();
        self::require_send();
        $typ  = sanitize_text_field( $_POST['typ'] ?? 'direkt' );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

        $teil_raw = $_POST['teilnehmer'] ?? '[]';
        if ( is_string( $teil_raw ) ) $teil_raw = json_decode( wp_unslash( $teil_raw ), true );
        if ( ! is_array( $teil_raw ) ) $teil_raw = [];

        // Direkt-Chat: bestehende Konversation wiederverwenden
        if ( $typ === 'direkt' ) {
            $other = null;
            foreach ( $teil_raw as $t ) {
                if ( ( $t['typ'] ?? '' ) === 'user' && (int) ( $t['id'] ?? 0 ) !== get_current_user_id() ) {
                    $other = (int) $t['id'];
                    break;
                }
            }
            if ( ! $other ) wp_send_json_error( [ 'message' => 'Direkt-Chat braucht einen anderen User.' ] );
            $existing = LSV07I_Konversation::direkt_zwischen( get_current_user_id(), $other );
            if ( $existing ) {
                wp_send_json_success( [ 'konv_id' => $existing, 'wiederverwendet' => true ] );
            }
        }

        // Berechtigungs-Check für Gruppen-Erstellung
        if ( $typ === 'gruppe' || $typ === 'verteiler' ) {
            if ( ! current_user_can( 'administrator' )
              && ! LSV07I_Permissions::can_current( LSV07I_Permissions::NACHRICHTEN_GRUPPE_CREATE ) ) {
                wp_send_json_error( [ 'message' => 'Keine Berechtigung zum Erstellen von Gruppen.' ], 403 );
            }
        }

        // Aktuellen User immer dazunehmen falls nicht schon drin
        $has_self = false;
        foreach ( $teil_raw as $t ) {
            if ( ( $t['typ'] ?? '' ) === 'user' && (int) ( $t['id'] ?? 0 ) === get_current_user_id() ) {
                $has_self = true;
            }
        }
        if ( ! $has_self ) {
            $teil_raw[] = [ 'typ' => 'user', 'id' => get_current_user_id() ];
        }

        $konv_id = LSV07I_Konversation::create( $typ, [
            'name'       => $name,
            'teilnehmer' => $teil_raw,
        ] );
        if ( is_wp_error( $konv_id ) ) {
            wp_send_json_error( [ 'message' => $konv_id->get_error_message() ] );
        }

        LSV07I_Log::write( 'nachrichten.konv.create', [
            'bereich'   => 'Nachrichten',
            'ziel_typ'  => 'konversation',
            'ziel_id'   => $konv_id,
            'ziel_name' => $name ?: ( $typ . ' (' . count( $teil_raw ) . ' Teilnehmer)' ),
        ] );
        wp_send_json_success( [ 'konv_id' => $konv_id ] );
    }

    // ─── Teilnehmer hinzufügen ────────────────────────────────────────────
    public static function add_teilnehmer() {
        self::nonce();
        self::require_send();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );
        self::require_member( $konv_id );

        // Nur Gruppen-Admins dürfen Teilnehmer hinzufügen
        if ( ! LSV07I_Konversation::ist_admin( $konv_id, get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => 'Nur Gruppen-Admins dürfen Teilnehmer hinzufügen.' ], 403 );
        }

        $typ   = sanitize_text_field( $_POST['typ'] ?? 'user' );
        $id    = absint( $_POST['id'] ?? 0 );
        $email = sanitize_email( $_POST['email'] ?? '' );
        $name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

        $t_id = LSV07I_Konversation::teilnehmer_hinzufuegen( $konv_id, [
            'typ' => $typ, 'id' => $id, 'email' => $email, 'name' => $name,
        ] );
        if ( ! $t_id ) wp_send_json_error( [ 'message' => 'Teilnehmer konnte nicht hinzugefügt werden.' ] );

        LSV07I_Log::write( 'nachrichten.teil.add', [
            'bereich'   => 'Nachrichten',
            'ziel_typ'  => 'konversation',
            'ziel_id'   => $konv_id,
            'ziel_name' => $name ?: $email,
        ] );
        wp_send_json_success( [ 'teilnehmer_id' => $t_id ] );
    }

    // ─── Teilnehmer entfernen (nur Admin) ─────────────────────────────────
    public static function remove_teil() {
        self::nonce();
        self::require_send();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        $t_id    = absint( $_POST['teilnehmer_id'] ?? 0 );
        if ( ! $konv_id || ! $t_id ) wp_send_json_error( [ 'message' => 'Parameter fehlen.' ] );
        self::require_member( $konv_id );

        global $wpdb;
        $p = $wpdb->prefix;
        $target = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_teilnehmer WHERE id = %d AND konv_id = %d",
            $t_id, $konv_id
        ), ARRAY_A );
        if ( ! $target ) wp_send_json_error( [ 'message' => 'Teilnehmer nicht gefunden.' ] );

        // Selbst-Entfernung ist immer erlaubt (= verlassen), für andere braucht's Admin
        $is_self = $target['teilnehmer_typ'] === 'user'
                && (int) $target['teilnehmer_id'] === get_current_user_id();
        if ( ! $is_self
          && ! LSV07I_Konversation::ist_admin( $konv_id, get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => 'Nur Gruppen-Admins dürfen Teilnehmer entfernen.' ], 403 );
        }

        // Letzten Admin nicht entfernen — sonst hat die Gruppe niemanden mehr
        if ( $is_self && (int) $target['ist_admin'] === 1 ) {
            $admin_count = LSV07I_Konversation::anzahl_admins( $konv_id );
            if ( $admin_count <= 1 ) {
                wp_send_json_error( [ 'message' => 'Sie sind der letzte Admin. Ernennen Sie zuerst einen anderen Admin.' ] );
            }
        }

        $wpdb->update( $p . 'lsv07i_konv_teilnehmer',
            [ 'verlassen_am' => current_time( 'mysql' ) ],
            [ 'id' => $t_id, 'konv_id' => $konv_id ],
            [ '%s' ], [ '%d', '%d' ]
        );
        LSV07I_Log::write( $is_self ? 'nachrichten.teil.leave' : 'nachrichten.teil.remove', [
            'bereich'   => 'Nachrichten',
            'ziel_typ'  => 'konversation',
            'ziel_id'   => $konv_id,
            'ziel_name' => $target['teilnehmer_name'] ?: $target['teilnehmer_email'] ?: 'ID ' . $target['teilnehmer_id'],
        ] );
        wp_send_json_success();
    }

    // ─── Bis Msg-ID als gelesen markieren ─────────────────────────────────
    public static function mark_read() {
        self::nonce();
        self::require_read();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        $msg_id  = absint( $_POST['msg_id']  ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );
        LSV07I_Konversation::gelesen_bis( $konv_id, get_current_user_id(), $msg_id );
        wp_send_json_success();
    }

    // ─── Ungelesen-Total ──────────────────────────────────────────────────
    public static function unread() {
        self::nonce();
        if ( ! current_user_can( 'administrator' ) && ! LSV07I_Access::has_any_access() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        $cnt = LSV07I_Konversation::ungelesen_total( get_current_user_id() );
        wp_send_json_success( [ 'count' => $cnt ] );
    }

    // ─── User-Suche für "Neuer Chat" ──────────────────────────────────────
    public static function search_users() {
        self::nonce();
        self::require_send();
        $q = sanitize_text_field( $_POST['q'] ?? '' );
        if ( strlen( $q ) < 2 ) wp_send_json_success( [ 'users' => [] ] );
        $users = get_users( [
            'search'         => '*' . $q . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 20,
            'fields'         => [ 'ID', 'display_name', 'user_email' ],
        ] );
        $out = [];
        foreach ( $users as $u ) {
            if ( (int) $u->ID === get_current_user_id() ) continue;
            $out[] = [
                'id'    => (int) $u->ID,
                'name'  => $u->display_name,
                'email' => $u->user_email,
            ];
        }
        wp_send_json_success( [ 'users' => $out ] );
    }

    // ─── Mannschaften für Auto-Verteiler ──────────────────────────────────
    public static function mannschaften() {
        self::nonce();
        self::require_send();
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT id, name FROM {$p}lsv07_gruppen ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );
        wp_send_json_success( [ 'mannschaften' => $rows ?: [] ] );
    }

    // ─── Anhang hochladen ─────────────────────────────────────────────────
    public static function anhang_upload() {
        self::nonce();
        if ( ! current_user_can( 'administrator' )
          && ! LSV07I_Permissions::can_current( LSV07I_Permissions::NACHRICHTEN_ANHANG_UPLOAD ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        $msg_id  = absint( $_POST['msg_id']  ?? 0 );
        if ( ! $konv_id || ! $msg_id ) {
            wp_send_json_error( [ 'message' => 'Parameter fehlen (Konversation/Nachricht).' ] );
        }
        if ( empty( $_FILES['datei'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Datei beim Server angekommen. Bitte erneut versuchen.' ] );
        }
        self::require_member( $konv_id );

        $file = $_FILES['datei'];

        // WICHTIG: PHP-Upload-Fehler ZUERST prüfen. Ohne diese Prüfung lief
        // der Code bei einem fehlgeschlagenen Server-Upload (z.B. Datei
        // groesser als das Server-Limit upload_max_filesize, das oft NIEDRIGER
        // ist als das clientseitig erlaubte 5-MB-Limit) einfach weiter und
        // erzeugte die IRREFUEHRENDE Meldung "Dateityp nicht erlaubt" — der
        // Datei-Inhalt (tmp_name) war dabei leer/ungueltig, nicht der Typ war
        // das Problem. Das war vermutlich die Hauptursache dafuer, dass der
        // Versand "nicht klappte" ohne verstaendlichen Grund.
        if ( ! isset( $file['error'] ) || $file['error'] !== UPLOAD_ERR_OK ) {
            $server_limit = self::format_bytes( self::bytes_from_ini( ini_get( 'upload_max_filesize' ) ) );
            $meldungen = [
                UPLOAD_ERR_INI_SIZE   => "Datei ist größer als das Server-Limit ($server_limit). Bitte eine kleinere Datei wählen.",
                UPLOAD_ERR_FORM_SIZE  => "Datei ist größer als das Server-Limit ($server_limit). Bitte eine kleinere Datei wählen.",
                UPLOAD_ERR_PARTIAL    => 'Datei wurde nur teilweise übertragen. Bitte erneut versuchen (evtl. instabile Verbindung).',
                UPLOAD_ERR_NO_FILE    => 'Keine Datei beim Server angekommen. Bitte erneut versuchen.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server-Konfigurationsfehler (kein temporäres Verzeichnis). Bitte den Administrator informieren.',
                UPLOAD_ERR_CANT_WRITE => 'Server konnte die Datei nicht schreiben. Bitte den Administrator informieren.',
                UPLOAD_ERR_EXTENSION  => 'Ein Server-Modul hat den Upload abgebrochen. Bitte den Administrator informieren.',
            ];
            $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            wp_send_json_error( [ 'message' => $meldungen[ $code ] ?? ( 'Upload fehlgeschlagen (Fehlercode ' . $code . ').' ) ] );
        }
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => 'Datei-Übertragung fehlgeschlagen. Bitte erneut versuchen.' ] );
        }

        // Validierung — selbe Whitelist wie Schwimmer-Dateien
        $allowed_mime = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
        ];
        if ( $file['size'] > 5 * 1024 * 1024 ) {
            wp_send_json_error( [ 'message' => 'Datei zu groß (max. 5 MB).' ] );
        }
        $finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
        $mime  = $finfo ? finfo_file( $finfo, $file['tmp_name'] )
                        : ( $file['type'] ?? '' );
        if ( $finfo ) finfo_close( $finfo );
        if ( ! isset( $allowed_mime[ $mime ] ) ) {
            wp_send_json_error( [ 'message' => 'Dateityp nicht erlaubt (erkannt: ' . esc_html( $mime ?: 'unbekannt' ) . '). Erlaubt: PDF, JPG, PNG.' ] );
        }
        $ext = $allowed_mime[ $mime ];

        // Geschützter Ordner: lsv07i-private/nachrichten/<konv_id>/
        $up   = wp_upload_dir();
        if ( ! empty( $up['error'] ) ) {
            wp_send_json_error( [ 'message' => 'Server-Upload-Verzeichnis nicht verfügbar: ' . esc_html( $up['error'] ) ] );
        }
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/nachrichten/' . (int) $konv_id;
        if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
            wp_send_json_error( [ 'message' => 'Server konnte das Zielverzeichnis nicht anlegen (Dateirechte?).' ] );
        }
        // .htaccess für Apache anlegen
        $protect = dirname( dirname( $base ) ) . '/.htaccess';
        if ( ! file_exists( $protect ) ) {
            @file_put_contents( $protect, "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }

        $rand = bin2hex( random_bytes( 8 ) );
        $stored = $rand . '.' . $ext;
        $target = trailingslashit( $base ) . $stored;
        if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) {
            wp_send_json_error( [ 'message' => 'Datei konnte nicht gespeichert werden (Dateirechte auf dem Server?).' ] );
        }
        @chmod( $target, 0644 );

        global $wpdb;
        $token = bin2hex( random_bytes( 16 ) );
        $ok = $wpdb->insert( $wpdb->prefix . 'lsv07i_konv_anhang', [
            'msg_id'         => $msg_id,
            'dateiname'      => sanitize_file_name( $file['name'] ),
            'gespeichert_als'=> $stored,
            'mime_type'      => $mime,
            'groesse'        => (int) $file['size'],
            'token'          => $token,
        ], [ '%d', '%s', '%s', '%s', '%d', '%s' ] );
        if ( $ok === false ) {
            @unlink( $target );
            wp_send_json_error( [ 'message' => 'Datenbankfehler beim Speichern des Anhangs' . ( $wpdb->last_error ? ': ' . $wpdb->last_error : '.' ) ] );
        }

        wp_send_json_success( [
            'anhang_id' => (int) $wpdb->insert_id,
            'token'     => $token,
        ] );
    }

    // ─── Anhang herunterladen ─────────────────────────────────────────────
    /**
     * Liefert einen Anhang aus. Die Dateien liegen außerhalb des Web-Roots,
     * deshalb dieser Umweg. Schlägt etwas fehl, kommt eine verständliche
     * Seite statt eines nackten Fehlertexts — sonst steht der Nutzer vor
     * einem leeren Tab und weiß nicht, woran es lag.
     */
    public static function anhang_dl() {
        $token = sanitize_text_field( $_GET['token'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            self::anhang_fehler( 400, 'Der Link ist unvollständig oder beschädigt.' );
        }
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, m.konv_id
               FROM {$p}lsv07i_konv_anhang a
          LEFT JOIN {$p}lsv07i_konv_msg m ON m.id = a.msg_id
              WHERE a.token = %s LIMIT 1", $token
        ), ARRAY_A );
        if ( ! $row ) {
            self::anhang_fehler( 404, 'Diese Datei gibt es nicht mehr.' );
        }

        // Wenn angemeldet: Teilnehmer-Check
        $uid = get_current_user_id();
        if ( $uid && ! current_user_can( 'administrator' ) ) {
            $is_member = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$p}lsv07i_konv_teilnehmer
                  WHERE konv_id = %d AND teilnehmer_typ='user' AND teilnehmer_id = %d
                    AND verlassen_am IS NULL LIMIT 1",
                (int) $row['konv_id'], $uid
            ) );
            if ( ! $is_member ) {
                self::anhang_fehler( 403, 'Du bist kein Teilnehmer dieser Unterhaltung.' );
            }
        }

        $path = self::anhang_pfad( $row );
        if ( ! $path ) {
            self::anhang_fehler( 404,
                'Die Datei liegt nicht mehr auf dem Server. Bitte den Absender, sie erneut zu senden.' );
        }

        // MIME notfalls aus der Endung ableiten — ein leeres Content-Type
        // führt sonst dazu, dass der Browser gar nichts anzeigt.
        $mime = (string) $row['mime_type'];
        if ( $mime === '' || strpos( $mime, '/' ) === false ) {
            $mime = self::mime_aus_endung( $path );
        }

        $dateiname = (string) ( $row['dateiname'] ?: basename( $path ) );

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $path ) );
        // Vorschaubare Typen inline ausliefern, damit der Browser sie anzeigt
        // statt sie ungefragt in den Download-Ordner zu legen.
        $inline_typen = [ 'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ];
        $disposition  = in_array( $mime, $inline_typen, true ) ? 'inline' : 'attachment';
        // Dateiname nach RFC 6266: ASCII-Fassung in Anführungszeichen plus
        // filename* für Umlaute. Vorher stand hier eine prozentkodierte
        // Fassung IN den Anführungszeichen — Browser zeigten dann wörtlich
        // "Trainingsplan%20W%C3%B6chentlich.pdf" als Dateinamen an.
        $ascii = preg_replace( '/[^\x20-\x7E]/', '_', $dateiname );
        $ascii = str_replace( [ '"', '\\' ], '_', $ascii );
        header( 'Content-Disposition: ' . $disposition
              . '; filename="' . $ascii . '"'
              . "; filename*=UTF-8''" . rawurlencode( $dateiname ) );
        header( 'X-Content-Type-Options: nosniff' );
        while ( ob_get_level() ) ob_end_clean();
        readfile( $path );
        exit;
    }

    /** Voller Pfad zu einem Anhang, oder '' wenn die Datei fehlt. */
    private static function anhang_pfad( $row ) {
        $up = wp_upload_dir();
        if ( ! empty( $up['error'] ) ) return '';
        // Nur den Dateinamen verwenden — kein Ausbruch aus dem Ordner
        $datei = basename( (string) $row['gespeichert_als'] );
        if ( $datei === '' ) return '';
        $pfad = trailingslashit( $up['basedir'] )
              . 'lsv07i-private/nachrichten/' . (int) $row['konv_id'] . '/' . $datei;
        return file_exists( $pfad ) ? $pfad : '';
    }

    /** MIME-Typ aus der Dateiendung, wenn in der Datenbank keiner steht. */
    private static function mime_aus_endung( $pfad ) {
        $karte = [
            'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        ];
        $ext = strtolower( pathinfo( $pfad, PATHINFO_EXTENSION ) );
        return $karte[ $ext ] ?? 'application/octet-stream';
    }

    /** Verständliche Fehlerseite statt eines nackten Textes im leeren Tab. */
    private static function anhang_fehler( $code, $text ) {
        status_header( (int) $code );
        header( 'Content-Type: text/html; charset=UTF-8' );
        while ( ob_get_level() ) ob_end_clean();
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Datei nicht verfügbar</title><style>'
           . 'body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;'
           . 'background:#eef3fb;color:#1c1d2e;display:flex;align-items:center;'
           . 'justify-content:center;min-height:100vh;padding:24px}'
           . '.k{background:#fff;border-radius:14px;padding:28px 26px;max-width:420px;'
           . 'box-shadow:0 10px 30px rgba(30,58,95,.14);text-align:center}'
           . 'h1{font-size:18px;margin:0 0 8px}p{margin:0;color:#454864;line-height:1.5}'
           . '</style></head><body><div class="k"><h1>Datei nicht verfügbar</h1><p>'
           . esc_html( $text ) . '</p></div></body></html>';
        exit;
    }

    // ─── Nachricht bearbeiten (nur eigene) ────────────────────────────────
    public static function msg_edit() {
        self::nonce();
        self::require_send();
        $msg_id = absint( $_POST['msg_id'] ?? 0 );
        $text   = wp_kses_post( wp_unslash( $_POST['text'] ?? '' ) );
        if ( ! $msg_id ) wp_send_json_error( [ 'message' => 'Msg-ID fehlt.' ] );
        if ( trim( $text ) === '' ) wp_send_json_error( [ 'message' => 'Nachricht ist leer.' ] );

        global $wpdb;
        $p = $wpdb->prefix;
        $msg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_msg WHERE id = %d LIMIT 1", $msg_id
        ), ARRAY_A );
        if ( ! $msg ) wp_send_json_error( [ 'message' => 'Nachricht nicht gefunden.' ] );
        if ( $msg['geloescht_am'] ) wp_send_json_error( [ 'message' => 'Gelöschte Nachricht kann nicht bearbeitet werden.' ] );

        // Bearbeiten darf nur der Absender selbst (auch Admin nicht — die Konsistenz
        // mit "Nur eigene Nachrichten" ist hier wichtiger als Admin-Allmacht).
        $is_owner = $msg['von_typ'] === 'user' && (int) $msg['von_id'] === get_current_user_id();
        if ( ! $is_owner ) {
            wp_send_json_error( [ 'message' => 'Nur eigene Nachrichten können bearbeitet werden.' ], 403 );
        }

        // Teilnehmer-Check für die Konversation
        self::require_member( (int) $msg['konv_id'] );

        $wpdb->update( $p . 'lsv07i_konv_msg', [
            'text'         => $text,
            'bearbeitet_am'=> current_time( 'mysql' ),
        ], [ 'id' => $msg_id ], [ '%s', '%s' ], [ '%d' ] );

        LSV07I_Log::write( 'nachrichten.msg.edit', [
            'bereich'  => 'Nachrichten',
            'ziel_typ' => 'message',
            'ziel_id'  => $msg_id,
        ] );
        wp_send_json_success( [ 'message' => 'Bearbeitet.' ] );
    }

    // ─── Nachricht löschen (eigene oder Admin) ────────────────────────────
    public static function msg_delete() {
        self::nonce();
        self::require_send();
        $msg_id = absint( $_POST['msg_id'] ?? 0 );
        if ( ! $msg_id ) wp_send_json_error( [ 'message' => 'Msg-ID fehlt.' ] );

        global $wpdb;
        $p = $wpdb->prefix;
        $msg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_msg WHERE id = %d LIMIT 1", $msg_id
        ), ARRAY_A );
        if ( ! $msg ) wp_send_json_error( [ 'message' => 'Nachricht nicht gefunden.' ] );
        if ( $msg['geloescht_am'] ) {
            // Schon gelöscht — idempotent
            wp_send_json_success( [ 'message' => 'Bereits gelöscht.' ] );
        }

        // Löschen darf: Absender selbst ODER Admin (current_user_can administrator)
        $is_owner = $msg['von_typ'] === 'user' && (int) $msg['von_id'] === get_current_user_id();
        $is_admin = current_user_can( 'administrator' );
        if ( ! $is_owner && ! $is_admin ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung zum Löschen.' ], 403 );
        }

        // Teilnehmer-Check für die Konversation (Admin darf trotzdem)
        if ( ! $is_admin ) {
            self::require_member( (int) $msg['konv_id'] );
        }

        // Soft-Delete (Text leeren, Flag setzen)
        $wpdb->update( $p . 'lsv07i_konv_msg', [
            'text'          => '',
            'geloescht_am'  => current_time( 'mysql' ),
            'geloescht_von' => get_current_user_id(),
        ], [ 'id' => $msg_id ], [ '%s', '%s', '%d' ], [ '%d' ] );

        LSV07I_Log::write( 'nachrichten.msg.delete', [
            'bereich'  => 'Nachrichten',
            'ziel_typ' => 'message',
            'ziel_id'  => $msg_id,
            'details'  => $is_admin && ! $is_owner ? 'durch Admin' : 'durch Absender',
        ] );
        wp_send_json_success( [ 'message' => 'Gelöscht.' ] );
    }

    // ─── Reaktion setzen/entfernen (Toggle) ───────────────────────────────
    public static function msg_reaktion() {
        self::nonce();
        self::require_read();
        $msg_id = absint( $_POST['msg_id'] ?? 0 );
        $emoji  = sanitize_text_field( wp_unslash( $_POST['emoji'] ?? '' ) );
        if ( ! $msg_id || $emoji === '' ) wp_send_json_error( [ 'message' => 'Parameter fehlen.' ] );

        // Whitelist von erlaubten Emojis — keine beliebigen Strings annehmen,
        // damit die DB-Tabelle nicht mit Müll vollläuft.
        $erlaubt = [ '👍', '❤', '❤️', '😂', '😮', '😢', '🙏', '🎉', '✅' ];
        if ( ! in_array( $emoji, $erlaubt, true ) ) {
            wp_send_json_error( [ 'message' => 'Emoji nicht erlaubt.' ] );
        }

        global $wpdb;
        $p = $wpdb->prefix;

        // Konversation existiert?
        $konv_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT konv_id FROM {$p}lsv07i_konv_msg WHERE id = %d", $msg_id
        ) );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Nachricht nicht gefunden.' ] );
        self::require_member( $konv_id );

        $uid = get_current_user_id();

        // Existiert die Reaktion schon? Dann entfernen (Toggle), sonst anlegen.
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_konv_reaktion
              WHERE msg_id = %d AND user_id = %d AND emoji = %s LIMIT 1",
            $msg_id, $uid, $emoji
        ) );

        if ( $existing ) {
            $wpdb->delete( $p . 'lsv07i_konv_reaktion', [ 'id' => $existing ], [ '%d' ] );
            wp_send_json_success( [ 'action' => 'removed' ] );
        }

        $wpdb->insert( $p . 'lsv07i_konv_reaktion', [
            'msg_id'  => $msg_id,
            'user_id' => $uid,
            'emoji'   => $emoji,
        ], [ '%d', '%d', '%s' ] );
        wp_send_json_success( [ 'action' => 'added' ] );
    }

    // ─── Gruppe umbenennen (nur Admin) ────────────────────────────────────
    public static function update_meta() {
        self::nonce();
        self::require_send();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        $name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );
        if ( trim( $name ) === '' ) wp_send_json_error( [ 'message' => 'Name darf nicht leer sein.' ] );

        self::require_member( $konv_id );
        if ( ! LSV07I_Konversation::ist_admin( $konv_id, get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => 'Nur Gruppen-Admins dürfen die Gruppe bearbeiten.' ], 403 );
        }

        LSV07I_Konversation::update_meta( $konv_id, $name );
        LSV07I_Log::write( 'nachrichten.konv.rename', [
            'bereich'   => 'Nachrichten',
            'ziel_typ'  => 'konversation',
            'ziel_id'   => $konv_id,
            'ziel_name' => $name,
        ] );
        wp_send_json_success( [ 'message' => 'Gruppe umbenannt.' ] );
    }

    // ─── Gruppe komplett löschen (nur Admin) ──────────────────────────────
    public static function delete_konv() {
        self::nonce();
        self::require_send();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );

        global $wpdb;
        $p = $wpdb->prefix;
        $konv = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv WHERE id = %d", $konv_id
        ), ARRAY_A );
        if ( ! $konv ) wp_send_json_error( [ 'message' => 'Konversation nicht gefunden.' ] );

        // Direkt-Chats können nicht "gelöscht" werden — nur verlassen
        if ( $konv['typ'] === 'direkt' ) {
            wp_send_json_error( [ 'message' => 'Direkt-Chats können nicht gelöscht werden — verlassen Sie sie stattdessen.' ] );
        }

        self::require_member( $konv_id );
        if ( ! LSV07I_Konversation::ist_admin( $konv_id, get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => 'Nur Gruppen-Admins dürfen die Gruppe löschen.' ], 403 );
        }

        LSV07I_Konversation::delete_konv( $konv_id );
        LSV07I_Log::write( 'nachrichten.konv.delete', [
            'bereich'   => 'Nachrichten',
            'ziel_typ'  => 'konversation',
            'ziel_id'   => $konv_id,
            'ziel_name' => $konv['name'] ?: ('ID ' . $konv_id),
        ] );
        wp_send_json_success( [ 'message' => 'Gruppe gelöscht.' ] );
    }

    // ─── Admin-Status setzen/entziehen (nur Admin) ────────────────────────
    public static function set_admin() {
        self::nonce();
        self::require_send();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        $t_id    = absint( $_POST['teilnehmer_id'] ?? 0 );
        $on      = ! empty( $_POST['on'] );
        if ( ! $konv_id || ! $t_id ) wp_send_json_error( [ 'message' => 'Parameter fehlen.' ] );

        self::require_member( $konv_id );
        if ( ! LSV07I_Konversation::ist_admin( $konv_id, get_current_user_id() ) ) {
            wp_send_json_error( [ 'message' => 'Nur Gruppen-Admins dürfen Admin-Rechte vergeben.' ], 403 );
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $target = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_teilnehmer WHERE id = %d AND konv_id = %d",
            $t_id, $konv_id
        ), ARRAY_A );
        if ( ! $target ) wp_send_json_error( [ 'message' => 'Teilnehmer nicht gefunden.' ] );

        // Nur user-Teilnehmer können Admin sein (Kontakte/E-Mails haben kein Login)
        if ( $target['teilnehmer_typ'] !== 'user' ) {
            wp_send_json_error( [ 'message' => 'Externe Teilnehmer können nicht Admin werden.' ] );
        }

        // Falls Self-Demote: prüfen ob noch andere Admins da sind
        $is_self = (int) $target['teilnehmer_id'] === get_current_user_id();
        if ( $is_self && ! $on ) {
            $admin_count = LSV07I_Konversation::anzahl_admins( $konv_id );
            if ( $admin_count <= 1 ) {
                wp_send_json_error( [ 'message' => 'Sie sind der letzte Admin. Ernennen Sie zuerst einen anderen Admin.' ] );
            }
        }

        $wpdb->update( $p . 'lsv07i_konv_teilnehmer',
            [ 'ist_admin' => $on ? 1 : 0 ],
            [ 'id' => $t_id ],
            [ '%d' ], [ '%d' ]
        );
        LSV07I_Log::write( $on ? 'nachrichten.admin.grant' : 'nachrichten.admin.revoke', [
            'bereich'   => 'Nachrichten',
            'ziel_typ'  => 'konversation',
            'ziel_id'   => $konv_id,
            'ziel_name' => $target['teilnehmer_name'] ?: 'ID ' . $target['teilnehmer_id'],
        ] );
        wp_send_json_success();
    }

    // ─── Konversation verlassen (eigene Selbst-Entfernung) ────────────────
    public static function leave() {
        self::nonce();
        self::require_send();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );

        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_teilnehmer
              WHERE konv_id = %d AND teilnehmer_typ='user' AND teilnehmer_id = %d
                AND verlassen_am IS NULL LIMIT 1",
            $konv_id, get_current_user_id()
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Sie sind nicht Teilnehmer dieser Konversation.' ] );

        if ( (int) $row['ist_admin'] === 1 ) {
            $admin_count = LSV07I_Konversation::anzahl_admins( $konv_id );
            if ( $admin_count <= 1 ) {
                wp_send_json_error( [ 'message' => 'Sie sind der letzte Admin. Ernennen Sie zuerst einen anderen Admin oder löschen Sie die Gruppe.' ] );
            }
        }

        $wpdb->update( $p . 'lsv07i_konv_teilnehmer',
            [ 'verlassen_am' => current_time( 'mysql' ) ],
            [ 'id' => $row['id'] ],
            [ '%s' ], [ '%d' ]
        );
        LSV07I_Log::write( 'nachrichten.teil.leave', [
            'bereich'   => 'Nachrichten',
            'ziel_typ'  => 'konversation',
            'ziel_id'   => $konv_id,
        ] );
        // Bei Direkt-Chats heißt "verlassen" schlicht: aus der eigenen Liste
        // nehmen. Die Gegenseite behält den Verlauf.
        $typ = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT typ FROM {$p}lsv07i_konv WHERE id = %d", $konv_id ) );
        wp_send_json_success( [
            'message' => $typ === 'direkt'
                ? 'Die Unterhaltung wurde aus deiner Liste entfernt.'
                : 'Du hast die Gruppe verlassen.',
        ] );
    }

    // ─── Heartbeat: User aktiv markieren + Online-Liste zurückgeben ───────
    // Frontend ruft das alle 30 Sek auf (im selben Polling wie list). Wir
    // markieren den eigenen User als aktiv und liefern Online-Status für die
    // mitgegebenen User-IDs (Teilnehmer der aktuellen Konversation).
    public static function heartbeat() {
        self::nonce();
        self::require_read();

        LSV07I_Konversation::touch_online();

        $ids_raw = $_POST['user_ids'] ?? '';
        if ( is_string( $ids_raw ) ) {
            $ids = array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) );
        } else if ( is_array( $ids_raw ) ) {
            $ids = array_filter( array_map( 'intval', $ids_raw ) );
        } else {
            $ids = [];
        }
        $ids = array_slice( array_unique( $ids ), 0, 200 );
        $status = LSV07I_Konversation::online_status( $ids );

        wp_send_json_success( [
            'online' => $status,
            'now'    => time(),
        ] );
    }

    // ─── Read-Status: wer hat die Nachrichten einer Konv gelesen ──────────
    // Liefert pro Teilnehmer den gelesen_bis-Wert. Frontend zeigt daraus
    // unter jeder Nachricht die "gelesen von ..."-Information.
    public static function read_status() {
        self::nonce();
        self::require_read();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );
        self::require_member( $konv_id );

        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT teilnehmer_typ, teilnehmer_id, teilnehmer_email, teilnehmer_name, gelesen_bis
               FROM {$p}lsv07i_konv_teilnehmer
              WHERE konv_id = %d AND verlassen_am IS NULL",
            $konv_id
        ), ARRAY_A );

        // Display-Namen für 'user'-Teilnehmer auflösen
        $out = [];
        foreach ( (array) $rows as $r ) {
            $name = $r['teilnehmer_name'];
            if ( $r['teilnehmer_typ'] === 'user' && ! $name ) {
                $u = get_user_by( 'id', (int) $r['teilnehmer_id'] );
                $name = $u ? ( $u->display_name ?: $u->user_login ) : '(?)';
            } elseif ( ! $name ) {
                $name = $r['teilnehmer_email'] ?: '(?)';
            }
            $out[] = [
                'typ'         => $r['teilnehmer_typ'],
                'id'          => (int) $r['teilnehmer_id'],
                'name'        => $name,
                'gelesen_bis' => (int) $r['gelesen_bis'],
            ];
        }
        wp_send_json_success( [ 'readers' => $out ] );
    }

    // ─── Typing-Indikator: "xy schreibt …" ────────────────────────────────
    // Wir speichern pro Konversation eine Map [user_id => last_typing_ts] in
    // einem Transient mit 10 Sek TTL. Frontend ruft typing_set alle ~3 Sek
    // auf während getippt wird, und typing_get beim Nachrichten-Polling.
    const TYPING_TTL = 10;

    public static function typing_set() {
        self::nonce();
        self::require_send();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );
        self::require_member( $konv_id );

        $uid = get_current_user_id();
        $key = 'lsv07i_typing_' . $konv_id;
        $map = get_transient( $key );
        if ( ! is_array( $map ) ) $map = [];

        // Stale-Entries (älter als TTL) rausfiltern
        $now = time();
        foreach ( $map as $u => $ts ) {
            if ( ( $now - (int) $ts ) > self::TYPING_TTL ) unset( $map[ $u ] );
        }
        $map[ $uid ] = $now;
        set_transient( $key, $map, self::TYPING_TTL + 5 );
        wp_send_json_success();
    }

    public static function typing_get() {
        self::nonce();
        self::require_read();
        $konv_id = absint( $_POST['konv_id'] ?? 0 );
        if ( ! $konv_id ) wp_send_json_error( [ 'message' => 'Konv-ID fehlt.' ] );
        self::require_member( $konv_id );

        $key = 'lsv07i_typing_' . $konv_id;
        $map = get_transient( $key );
        if ( ! is_array( $map ) ) $map = [];

        $now = time();
        $uid = get_current_user_id();
        $tippen = [];
        foreach ( $map as $u => $ts ) {
            $u = (int) $u;
            if ( $u === $uid ) continue; // mich selbst nicht zurückgeben
            if ( ( $now - (int) $ts ) > self::TYPING_TTL ) continue;
            $user = get_user_by( 'id', $u );
            $tippen[] = [
                'id'   => $u,
                'name' => $user ? ( $user->display_name ?: $user->user_login ) : '?',
            ];
        }
        wp_send_json_success( [ 'tippen' => $tippen ] );
    }

    /**
     * Profilbild-URL eines Nutzers für die Chat-Anzeige. Leere Zeichenkette,
     * wenn keines hinterlegt ist — dann zeigt die Oberfläche die Initialen.
     * Ergebnisse werden pro Request gemerkt, weil dieselben Absender in einer
     * Konversation vielfach vorkommen.
     */
    private static $avatar_cache = [];
    private static function avatar_url( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) return '';
        if ( ! isset( self::$avatar_cache[ $user_id ] ) ) {
            self::$avatar_cache[ $user_id ] = class_exists( 'LSV07I_Ajax_Profil' )
                ? LSV07I_Ajax_Profil::bild_url( $user_id )
                : '';
        }
        return self::$avatar_cache[ $user_id ];
    }

    /** Initialen als Rückfallebene, wenn kein Profilbild hinterlegt ist. */
    private static $init_cache = [];
    private static function avatar_initialen( $user_id, $fallback_name = '' ) {
        $user_id = (int) $user_id;
        if ( isset( self::$init_cache[ $user_id ] ) ) return self::$init_cache[ $user_id ];
        $u = $user_id ? get_user_by( 'id', $user_id ) : null;
        if ( $u && class_exists( 'LSV07I_Ajax_Profil' ) ) {
            $out = LSV07I_Ajax_Profil::initialen( $u );
        } else {
            $name = trim( (string) $fallback_name );
            $teile = $name !== '' ? preg_split( '/\s+/', $name ) : [];
            if ( count( $teile ) >= 2 ) {
                $out = mb_strtoupper( mb_substr( $teile[0], 0, 1 ) . mb_substr( $teile[1], 0, 1 ) );
            } elseif ( $name !== '' ) {
                $out = mb_strtoupper( mb_substr( $name, 0, 2 ) );
            } else {
                $out = '?';
            }
        }
        return self::$init_cache[ $user_id ] = $out;
    }

}
