<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Magic_Link — Antwort-Seite für externe Empfänger.
 *
 * Wird aufgerufen über ?lsv07i_reply=<token>. Zeigt die Konversation und
 * ein einfaches Antwort-Formular. Keine Login-Pflicht — Authentifizierung
 * läuft rein über den Token, der nur dem Empfänger der E-Mail bekannt ist.
 *
 * Token sind 32-Zeichen-Hex (16 Bytes random). Sie laufen 90 Tage nach dem
 * Versand der E-Mail ab.
 */
class LSV07I_Magic_Link {

    const TOKEN_TTL_DAYS = 90;

    public static function init() {
        add_action( 'template_redirect', [ __CLASS__, 'maybe_handle' ] );
    }

    public static function maybe_handle() {
        if ( empty( $_GET['lsv07i_reply'] ) ) return;
        $token = sanitize_text_field( $_GET['lsv07i_reply'] );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            self::render_error( 'Ungültiger Link.' );
            exit;
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $queue = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_email_queue WHERE reply_token = %s LIMIT 1", $token
        ), ARRAY_A );

        if ( ! $queue ) {
            self::render_error( 'Dieser Antwort-Link ist nicht (mehr) gültig.' );
            exit;
        }

        // TTL prüfen
        $age_days = ( time() - strtotime( $queue['erstellt_am'] ) ) / 86400;
        if ( $age_days > self::TOKEN_TTL_DAYS ) {
            self::render_error( 'Dieser Antwort-Link ist abgelaufen.' );
            exit;
        }

        $msg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_msg WHERE id = %d", (int) $queue['msg_id']
        ), ARRAY_A );
        if ( ! $msg ) {
            self::render_error( 'Konversation nicht gefunden.' );
            exit;
        }
        $konv = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv WHERE id = %d", (int) $msg['konv_id']
        ), ARRAY_A );

        // POST → Antwort speichern
        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST['lsv07i_reply_text'] )
             && wp_verify_nonce( $_POST['_lsv07i_nonce'] ?? '', 'lsv07i_reply_' . $token ) ) {
            $text = wp_kses_post( wp_unslash( $_POST['lsv07i_reply_text'] ) );
            if ( trim( $text ) === '' ) {
                self::render_form( $konv, $msg, $queue, 'Bitte eine Nachricht eingeben.' );
                exit;
            }
            $new_id = LSV07I_Konversation::nachricht_senden( $konv['id'], $text, [
                'von_typ'   => $queue['empfaenger_typ'],
                'von_id'    => 0,
                'von_email' => $queue['empfaenger'],
                'von_name'  => $queue['empfaenger'],
            ] );
            if ( is_wp_error( $new_id ) ) {
                self::render_form( $konv, $msg, $queue, $new_id->get_error_message() );
                exit;
            }
            LSV07I_Log::write( 'nachrichten.extern.reply', [
                'bereich'   => 'Nachrichten',
                'ziel_typ'  => 'konversation',
                'ziel_id'   => $konv['id'],
                'ziel_name' => $queue['empfaenger'] . ' → Konv #' . $konv['id'],
                'details'   => 'Via Magic Link',
            ] );
            self::render_success();
            exit;
        }

        // GET → Formular zeigen
        self::render_form( $konv, $msg, $queue );
        exit;
    }

    private static function render_form( $konv, $msg, $queue, $error = '' ) {
        $titel    = $konv['name'] ?: 'Nachricht';
        $absender = $msg['von_name'] ?: 'Verein';
        $datum    = mysql2date( 'd.m.Y H:i', $msg['erstellt_am'] );
        $nonce    = wp_create_nonce( 'lsv07i_reply_' . $queue['reply_token'] );

        self::header( $titel );
        echo '<div class="card">';
        echo '<div class="meta">Original-Nachricht von <strong>' . esc_html( $absender ) . '</strong> · ' . esc_html( $datum ) . '</div>';
        echo '<div class="orig">' . nl2br( esc_html( $msg['text'] ) ) . '</div>';
        echo '</div>';

        echo '<div class="card">';
        echo '<h3>Antwort</h3>';
        if ( $error ) {
            echo '<div class="error">' . esc_html( $error ) . '</div>';
        }
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="_lsv07i_nonce" value="' . esc_attr( $nonce ) . '">';
        echo '<textarea name="lsv07i_reply_text" rows="6" required placeholder="Ihre Antwort..."></textarea>';
        echo '<button type="submit">Antwort senden</button>';
        echo '</form>';
        echo '<div class="hint">Diese Antwort wird als Nachricht im Vereinssystem gespeichert und an die Trainer-/Vereinsverantwortlichen weitergegeben.</div>';
        echo '</div>';
        self::footer();
    }

    private static function render_success() {
        self::header( 'Antwort gesendet' );
        echo '<div class="card center">';
        echo '<h2>✓ Vielen Dank</h2>';
        echo '<p>Ihre Antwort wurde gesendet. Sie können dieses Fenster nun schließen.</p>';
        echo '</div>';
        self::footer();
    }

    private static function render_error( $msg ) {
        self::header( 'Fehler' );
        echo '<div class="card center">';
        echo '<h2 style="color:#c41e3a">Hinweis</h2>';
        echo '<p>' . esc_html( $msg ) . '</p>';
        echo '<p style="font-size:13px;color:#64748b">Bitte wenden Sie sich direkt an die Vereinsverantwortlichen.</p>';
        echo '</div>';
        self::footer();
    }

    private static function header( $title ) {
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . esc_html( $title ) . '</title>';
        echo '<style>
            body{font-family:-apple-system,Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#1a202c;margin:0;padding:20px}
            .wrap{max-width:600px;margin:0 auto}
            .head{background:#3c7efc;color:#fff;padding:18px 24px;border-radius:10px 10px 0 0;margin-bottom:0}
            .head h1{margin:0;font-size:20px}
            .card{background:#fff;border:1px solid #e2e8f0;padding:20px 24px;margin-bottom:14px}
            .card:first-of-type{border-radius:0 0 10px 10px;border-top:0}
            .card+.card{border-radius:10px}
            .meta{font-size:12px;color:#64748b;margin-bottom:8px}
            .orig{background:#f8fafc;padding:12px;border-radius:6px;font-size:14px;line-height:1.5;white-space:pre-wrap}
            h3{margin:0 0 12px;font-size:16px}
            textarea{width:100%;box-sizing:border-box;padding:10px;font-family:inherit;font-size:14px;border:1px solid #cbd5e1;border-radius:6px;resize:vertical}
            button{margin-top:10px;padding:10px 24px;background:#3c7efc;color:#fff;border:0;border-radius:6px;font-size:14px;cursor:pointer}
            button:hover{background:#2563eb}
            .error{background:#fee2e2;color:#991b1b;padding:8px 12px;border-radius:6px;margin-bottom:10px;font-size:13px}
            .hint{font-size:12px;color:#64748b;margin-top:10px}
            .center{text-align:center}
        </style></head><body><div class="wrap">';
        echo '<div class="head"><h1>' . esc_html( $title ) . '</h1></div>';
    }

    private static function footer() {
        echo '</div></body></html>';
    }
}
