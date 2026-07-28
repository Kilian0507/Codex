<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Imap_Reader — liest das Antwort-Postfach via IMAP und ordnet
 * eingehende Mails den Konversationen zu (Reply-Token im To/Reply-To).
 *
 * Aktiviert sich nur wenn die Konfiguration vollständig ist. Setup:
 *   1. Admin → Konfiguration: email_imap_host, email_imap_user, email_imap_pass eintragen
 *   2. Mail-Subadressing einrichten: nachricht-*@deine-domain.de leitet auf den IMAP-Account
 *   3. Cronjob läuft alle 5 Minuten und ruft fetch_replies() auf
 *
 * Voraussetzung: php-imap-Extension auf dem Server. Wenn nicht da, läuft
 * das System OHNE IMAP weiter — Magic Link bleibt der Antwort-Weg.
 */
class LSV07I_Imap_Reader {

    public static function init() {
        add_action( 'lsv07i_imap_cron', [ __CLASS__, 'fetch_replies' ] );
        if ( ! wp_next_scheduled( 'lsv07i_imap_cron' ) ) {
            wp_schedule_event( time() + 120, 'lsv07i_5min', 'lsv07i_imap_cron' );
        }
    }

    /**
     * Prüft ob alles für IMAP-Zugriff bereit ist.
     */
    public static function is_configured() {
        if ( ! function_exists( 'imap_open' ) ) return false;
        $host = LSV07I_DB::get_config( 'email_imap_host', '' );
        $user = LSV07I_DB::get_config( 'email_imap_user', '' );
        $pass = LSV07I_DB::get_config( 'email_imap_pass', '' );
        return $host && $user && $pass;
    }

    /**
     * Hauptmethode — wird vom Cron aufgerufen.
     */
    public static function fetch_replies() {
        if ( ! self::is_configured() ) return;

        $host = LSV07I_DB::get_config( 'email_imap_host', '' );
        $user = LSV07I_DB::get_config( 'email_imap_user', '' );
        $pass = LSV07I_DB::get_config( 'email_imap_pass', '' );
        $port = (int) LSV07I_DB::get_config( 'email_imap_port', 993 );

        // IMAP-Verbindung. Format: {host:port/imap/ssl}INBOX
        $mbox_str = '{' . $host . ':' . $port . '/imap/ssl}INBOX';
        $mbox = @imap_open( $mbox_str, $user, $pass, OP_HALFOPEN );
        if ( ! $mbox ) {
            error_log( 'LSV07I IMAP: Verbindung fehlgeschlagen: ' . imap_last_error() );
            return;
        }
        $mbox = @imap_reopen( $mbox, $mbox_str ) ? $mbox : @imap_open( $mbox_str, $user, $pass );
        if ( ! $mbox ) return;

        // Ungelesene Mails seit letztem Lauf
        $emails = imap_search( $mbox, 'UNSEEN' );
        if ( ! $emails ) {
            imap_close( $mbox );
            return;
        }

        foreach ( $emails as $email_number ) {
            self::process_one( $mbox, $email_number );
        }

        imap_close( $mbox );
    }

    /**
     * Verarbeitet eine einzelne Mail.
     */
    private static function process_one( $mbox, $email_number ) {
        $overview = imap_fetch_overview( $mbox, $email_number, 0 );
        if ( empty( $overview[0] ) ) return;
        $h = $overview[0];

        // Token entweder aus To-Adresse oder aus X-LSV07I-Token-Header extrahieren
        $token = '';
        $to = $h->to ?? '';
        if ( preg_match( '/nachricht-([a-f0-9]{32})@/', $to, $m ) ) {
            $token = $m[1];
        }
        if ( ! $token ) {
            // Fallback: Header lesen
            $raw_header = imap_fetchheader( $mbox, $email_number );
            if ( preg_match( '/X-LSV07I-Token:\s*([a-f0-9]{32})/i', $raw_header, $m ) ) {
                $token = $m[1];
            }
        }
        if ( ! $token ) {
            // Keine Zuordnung möglich — als gelesen markieren und überspringen
            imap_setflag_full( $mbox, $email_number, '\Seen' );
            return;
        }

        // Token in Queue suchen
        global $wpdb;
        $p = $wpdb->prefix;
        $queue = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_email_queue WHERE reply_token = %s LIMIT 1", $token
        ), ARRAY_A );
        if ( ! $queue ) {
            imap_setflag_full( $mbox, $email_number, '\Seen' );
            return;
        }

        // Body abrufen (Plain-Text bevorzugt)
        $body = self::fetch_body( $mbox, $email_number );
        if ( ! $body ) {
            imap_setflag_full( $mbox, $email_number, '\Seen' );
            return;
        }

        // Reply-Quoting entfernen (alles ab erstem >-Zeilenbeginn oder Standard-Trennern)
        $clean = self::strip_reply_quote( $body );
        $clean = trim( $clean );
        if ( $clean === '' ) {
            imap_setflag_full( $mbox, $email_number, '\Seen' );
            return;
        }

        // Msg-ID der ursprünglichen Mail holen, daraus Konv-ID
        $msg = $wpdb->get_row( $wpdb->prepare(
            "SELECT konv_id FROM {$p}lsv07i_konv_msg WHERE id = %d", (int) $queue['msg_id']
        ), ARRAY_A );
        if ( ! $msg ) {
            imap_setflag_full( $mbox, $email_number, '\Seen' );
            return;
        }

        // Antwort als neue Nachricht speichern
        $new_id = LSV07I_Konversation::nachricht_senden( (int) $msg['konv_id'], $clean, [
            'von_typ'   => $queue['empfaenger_typ'],
            'von_id'    => 0,
            'von_email' => $queue['empfaenger'],
            'von_name'  => $queue['empfaenger'],
        ] );

        LSV07I_Log::write( 'nachrichten.extern.imap_reply', [
            'bereich'   => 'Nachrichten',
            'ziel_typ'  => 'konversation',
            'ziel_id'   => (int) $msg['konv_id'],
            'ziel_name' => $queue['empfaenger'] . ' → Konv #' . $msg['konv_id'],
            'details'   => 'Via IMAP-Reply',
        ] );

        imap_setflag_full( $mbox, $email_number, '\Seen' );
    }

    /**
     * Liest den Plain-Text-Body. Fällt zurück auf HTML wenn nötig.
     */
    private static function fetch_body( $mbox, $email_number ) {
        $structure = imap_fetchstructure( $mbox, $email_number );

        // Multipart: nach text/plain suchen
        if ( ! empty( $structure->parts ) ) {
            foreach ( $structure->parts as $idx => $part ) {
                if ( $part->type === 0 && strcasecmp( $part->subtype, 'PLAIN' ) === 0 ) {
                    $body = imap_fetchbody( $mbox, $email_number, $idx + 1 );
                    return self::decode_part( $body, $part );
                }
            }
            // Kein Plain — HTML als Fallback
            foreach ( $structure->parts as $idx => $part ) {
                if ( $part->type === 0 && strcasecmp( $part->subtype, 'HTML' ) === 0 ) {
                    $body = imap_fetchbody( $mbox, $email_number, $idx + 1 );
                    $body = self::decode_part( $body, $part );
                    return strip_tags( $body );
                }
            }
        }

        // Einfache Mail
        $body = imap_body( $mbox, $email_number );
        return self::decode_part( $body, $structure );
    }

    private static function decode_part( $body, $part ) {
        if ( ! $part ) return $body;
        if ( ! empty( $part->encoding ) ) {
            switch ( $part->encoding ) {
                case 3: $body = base64_decode( $body ); break;
                case 4: $body = quoted_printable_decode( $body ); break;
            }
        }
        // Charset
        $charset = 'UTF-8';
        if ( ! empty( $part->parameters ) ) {
            foreach ( $part->parameters as $p ) {
                if ( strtolower( $p->attribute ) === 'charset' ) {
                    $charset = $p->value;
                }
            }
        }
        if ( strtoupper( $charset ) !== 'UTF-8' && function_exists( 'mb_convert_encoding' ) ) {
            $body = mb_convert_encoding( $body, 'UTF-8', $charset );
        }
        return $body;
    }

    /**
     * Versucht den eigentlichen Antwort-Text vom Quote zu trennen.
     * Heuristisch — funktioniert für die häufigsten Mail-Programme.
     */
    private static function strip_reply_quote( $body ) {
        // Outlook-Stil: "Von: ..." oder "From: ..."
        $patterns = [
            '/^(Von|From):.*$/m',
            '/^-{3,}\s*Originalnachricht.*$/m',
            '/^_{3,}\s*$/m',
            '/^\s*Am .+ schrieb .+:\s*$/m',
            '/^\s*On .+ wrote:\s*$/m',
        ];
        foreach ( $patterns as $re ) {
            if ( preg_match( $re, $body, $m, PREG_OFFSET_CAPTURE ) ) {
                $body = substr( $body, 0, $m[0][1] );
            }
        }
        // Zeilen die mit > anfangen weg
        $lines = preg_split( "/\r?\n/", $body );
        $kept  = [];
        foreach ( $lines as $line ) {
            if ( substr( ltrim( $line ), 0, 1 ) === '>' ) continue;
            $kept[] = $line;
        }
        return implode( "\n", $kept );
    }
}
