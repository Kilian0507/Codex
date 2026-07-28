<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Email_Versand — verarbeitet die E-Mail-Queue.
 *
 * Wird vom WP-Cron alle paar Minuten aufgerufen. Bündelt mehrere ausstehende
 * Nachrichten an denselben Empfänger zu einer einzigen E-Mail (Batch),
 * außer eine davon ist als dringend markiert — dann sofortiger Einzelversand.
 *
 * Antwort-Mechanismen:
 *  - Magic-Link: jede Mail enthält einen Token-Link zur Antwort-Seite
 *  - IMAP-Reply: Reply-To trägt den Token, IMAP-Watcher (separate Klasse)
 *    liest das Postfach aus und ordnet eingehende Mails zu
 */
class LSV07I_Email_Versand {

    /** Wie viele Versuche pro Mail */
    const MAX_VERSUCHE = 3;

    /** Cron-Intervall in Sekunden für Batch-Versand */
    const BATCH_INTERVAL = 600; // 10 Minuten

    public static function init() {
        // Cron-Hook registrieren
        add_action( 'lsv07i_email_queue_cron', [ __CLASS__, 'process_queue' ] );

        // Wenn noch nicht geplant: einplanen
        if ( ! wp_next_scheduled( 'lsv07i_email_queue_cron' ) ) {
            wp_schedule_event( time() + 60, 'lsv07i_5min', 'lsv07i_email_queue_cron' );
        }

        // Eigenes 5-Minuten-Intervall
        add_filter( 'cron_schedules', function( $s ) {
            if ( ! isset( $s['lsv07i_5min'] ) ) {
                $s['lsv07i_5min'] = [ 'interval' => 300, 'display' => 'Alle 5 Minuten' ];
            }
            return $s;
        } );
    }

    /**
     * Verarbeitet wartende Queue-Einträge.
     */
    public static function process_queue() {
        global $wpdb;
        $p = $wpdb->prefix;
        $tbl = $p . 'lsv07i_email_queue';

        // ─── Dringende sofort einzeln verschicken ────────────────────────
        $dringend = $wpdb->get_results(
            "SELECT * FROM $tbl WHERE status = 'warten' AND dringend = 1
              ORDER BY id ASC LIMIT 50",
            ARRAY_A
        );
        foreach ( (array) $dringend as $row ) {
            self::send_single( $row );
        }

        // ─── Restliche: pro Empfänger bündeln ────────────────────────────
        $alle = $wpdb->get_results(
            "SELECT * FROM $tbl WHERE status = 'warten'
              ORDER BY empfaenger ASC, id ASC LIMIT 500",
            ARRAY_A
        );
        $by_empf = [];
        foreach ( (array) $alle as $r ) {
            $by_empf[ $r['empfaenger'] ][] = $r;
        }
        foreach ( $by_empf as $empf => $rows ) {
            if ( count( $rows ) === 1 ) {
                self::send_single( $rows[0] );
            } else {
                self::send_batch( $empf, $rows );
            }
        }
    }

    /**
     * Schickt eine einzelne Mail.
     */
    private static function send_single( array $row ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $msg = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv_msg WHERE id = %d", (int) $row['msg_id']
        ), ARRAY_A );
        if ( ! $msg ) {
            self::mark_failed( $row['id'], 'Nachricht nicht gefunden' );
            return;
        }

        $konv = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_konv WHERE id = %d", (int) $msg['konv_id']
        ), ARRAY_A );

        $absender_name = $msg['von_name'] ?: self::user_name( (int) $msg['von_id'] );
        $konv_titel    = $konv['name'] ?: 'Persönliche Nachricht';

        $subject = '[' . self::vereinsname() . '] ' . $konv_titel;
        $body    = self::build_html_body( $absender_name, $konv_titel, $msg['text'], $row['reply_token'], $msg['id'] );
        $headers = self::build_headers( $row['reply_token'] );

        $sent = wp_mail( $row['empfaenger'], $subject, $body, $headers );
        if ( $sent ) {
            $wpdb->update( $p . 'lsv07i_email_queue', [
                'status'      => 'gesendet',
                'gesendet_am' => current_time( 'mysql' ),
            ], [ 'id' => (int) $row['id'] ], [ '%s', '%s' ], [ '%d' ] );
        } else {
            self::mark_failed( $row['id'], 'wp_mail() gab false zurück' );
        }
    }

    /**
     * Bündelt mehrere Nachrichten an denselben Empfänger zu einer Mail.
     */
    private static function send_batch( $empfaenger, array $rows ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $first = $rows[0];
        $msg_ids = array_map( fn( $r ) => (int) $r['msg_id'], $rows );
        $in = implode( ',', $msg_ids );
        $msgs = $wpdb->get_results(
            "SELECT m.*, k.name AS konv_name
               FROM {$p}lsv07i_konv_msg m
          LEFT JOIN {$p}lsv07i_konv k ON k.id = m.konv_id
              WHERE m.id IN ($in)
              ORDER BY m.erstellt_am ASC",
            ARRAY_A
        );

        $subject = '[' . self::vereinsname() . '] ' . count( $msgs ) . ' neue Nachrichten';
        $body    = self::build_batch_body( $msgs, $first['reply_token'] );
        $headers = self::build_headers( $first['reply_token'] );

        $sent = wp_mail( $empfaenger, $subject, $body, $headers );
        if ( $sent ) {
            $ids = array_map( fn( $r ) => (int) $r['id'], $rows );
            $in2 = implode( ',', $ids );
            $wpdb->query( "UPDATE {$p}lsv07i_email_queue
                              SET status = 'gesendet', gesendet_am = '" . current_time( 'mysql' ) . "'
                            WHERE id IN ($in2)" );
        } else {
            foreach ( $rows as $r ) {
                self::mark_failed( $r['id'], 'wp_mail() gab false zurück (batch)' );
            }
        }
    }

    private static function mark_failed( $queue_id, $reason ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT versuche FROM {$p}lsv07i_email_queue WHERE id = %d", $queue_id
        ), ARRAY_A );
        $versuche = (int) ( $row['versuche'] ?? 0 ) + 1;
        $status   = $versuche >= self::MAX_VERSUCHE ? 'fehler' : 'warten';
        $wpdb->update( $p . 'lsv07i_email_queue', [
            'versuche'    => $versuche,
            'status'      => $status,
            'fehler_text' => $reason,
        ], [ 'id' => (int) $queue_id ], [ '%d', '%s', '%s' ], [ '%d' ] );
    }

    private static function vereinsname() {
        return get_option( 'blogname', 'Verein' );
    }

    private static function user_name( $user_id ) {
        if ( ! $user_id ) return 'System';
        $u = get_user_by( 'id', $user_id );
        return $u ? ( $u->display_name ?: $u->user_login ) : '(unbekannt)';
    }

    /**
     * Baut HTML-Body für eine einzelne Nachricht.
     */
    private static function build_html_body( $absender, $konv_titel, $text, $token, $msg_id ) {
        $reply_url = self::reply_url( $token );
        $text_html = nl2br( esc_html( $text ) );
        return '<!DOCTYPE html><html lang="de"><body style="font-family:Arial,sans-serif;color:#1a202c;max-width:600px;margin:0 auto;padding:20px">
            <div style="background:#3c7efc;color:#fff;padding:14px 20px;border-radius:8px 8px 0 0">
                <h2 style="margin:0;font-size:18px">' . esc_html( $konv_titel ) . '</h2>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-top:0;padding:20px;border-radius:0 0 8px 8px">
                <p style="margin:0 0 12px;color:#475569;font-size:13px">Von <strong>' . esc_html( $absender ) . '</strong></p>
                <div style="font-size:15px;line-height:1.6;color:#1a202c">' . $text_html . '</div>
                <hr style="border:0;border-top:1px solid #e2e8f0;margin:20px 0">
                <p style="margin:0;font-size:13px;color:#475569">
                    <strong>Antworten:</strong> Antworte einfach auf diese E-Mail
                    — oder nutze <a href="' . esc_url( $reply_url ) . '" style="color:#3c7efc">diesen Link</a>.
                </p>
            </div>
            <p style="margin:14px 0;font-size:11px;color:#94a3b8;text-align:center">
                Diese E-Mail wurde automatisch versendet vom Vereinssystem.
                <br>Bitte teilen Sie diesen Link nicht weiter — er gehört nur Ihnen.
            </p>
        </body></html>';
    }

    /**
     * Baut HTML-Body für eine gebündelte Mail mit mehreren Nachrichten.
     */
    private static function build_batch_body( array $msgs, $token ) {
        $reply_url = self::reply_url( $token );
        $items = '';
        foreach ( $msgs as $m ) {
            $absender = $m['von_name'] ?: self::user_name( (int) $m['von_id'] );
            $zeit     = mysql2date( 'd.m.Y H:i', $m['erstellt_am'] );
            $items   .= '<div style="margin-bottom:16px;padding:12px;background:#f8fafc;border-left:3px solid #3c7efc;border-radius:4px">
                <div style="font-size:12px;color:#64748b;margin-bottom:4px">
                    <strong>' . esc_html( $m['konv_name'] ?: 'Konversation' ) . '</strong>
                    · ' . esc_html( $absender ) . ' · ' . $zeit . '
                </div>
                <div style="font-size:14px;line-height:1.5">' . nl2br( esc_html( $m['text'] ) ) . '</div>
            </div>';
        }
        return '<!DOCTYPE html><html lang="de"><body style="font-family:Arial,sans-serif;color:#1a202c;max-width:600px;margin:0 auto;padding:20px">
            <div style="background:#3c7efc;color:#fff;padding:14px 20px;border-radius:8px 8px 0 0">
                <h2 style="margin:0;font-size:18px">' . count( $msgs ) . ' neue Nachrichten</h2>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-top:0;padding:20px;border-radius:0 0 8px 8px">
                ' . $items . '
                <hr style="border:0;border-top:1px solid #e2e8f0;margin:20px 0">
                <p style="margin:0;font-size:13px;color:#475569">
                    <strong>Antworten:</strong> Antworte auf diese E-Mail
                    — oder über <a href="' . esc_url( $reply_url ) . '" style="color:#3c7efc">diesen Link</a>.
                </p>
            </div>
        </body></html>';
    }

    /**
     * Antwort-Headers: From, Reply-To mit Token.
     */
    private static function build_headers( $token ) {
        $from_name = self::vereinsname();
        $from_addr = get_option( 'admin_email', 'noreply@example.com' );

        // Reply-To: nachricht-<token>@<reply-domain> — Domain konfigurierbar
        $reply_domain = LSV07I_DB::get_config( 'email_reply_domain', '' );
        $reply_addr   = $reply_domain
            ? 'nachricht-' . $token . '@' . $reply_domain
            : $from_addr; // Fallback: kein IMAP-Reply möglich

        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . $from_addr . '>',
            'Reply-To: ' . $reply_addr,
            'X-LSV07I-Token: ' . $token,
        ];
    }

    /**
     * URL zur Magic-Link-Antwortseite.
     */
    private static function reply_url( $token ) {
        return add_query_arg( [
            'lsv07i_reply' => $token,
        ], home_url( '/' ) );
    }
}
