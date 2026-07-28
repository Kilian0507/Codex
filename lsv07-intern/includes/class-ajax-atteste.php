<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Attest-Verwaltung (Admin-Tab "Atteste").
 *
 * Zeigt alle Schwimmer, deren Attest in den naechsten 30 Tagen ablaeuft
 * oder bereits abgelaufen ist. Der Admin pflegt eine Standard-Mailvorlage
 * (Betreff + Text mit Platzhaltern) und verschickt Erinnerungen MANUELL
 * per Button — es gibt bewusst KEINEN automatischen Versand.
 *
 * Platzhalter in der Vorlage: {name}, {vorname}, {nachname}, {datum}
 */
class LSV07I_Ajax_Atteste {

    const CFG_BETREFF = 'attest_mail_betreff';
    const CFG_TEXT    = 'attest_mail_text';

    public static function init() {
        foreach ( [ 'liste', 'template_get', 'template_save', 'email_update', 'mail_send' ] as $a ) {
            add_action( 'wp_ajax_lsv07i_attest_' . $a, [ __CLASS__, $a ] );
        }
    }

    /** Zugriff: WP-Admin, Plugin-Admin oder dediziertes Attest-Mail-Recht. */
    private static function check() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
        if ( current_user_can( 'administrator' ) || LSV07I_Access::is_admin() ) return;
        if ( class_exists( 'LSV07I_Permissions' )
          && LSV07I_Permissions::can_current( LSV07I_Permissions::ADMIN_ATTEST_MAIL ) ) return;
        wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
    }

    /** Alle Atteste, die in <=30 Tagen ablaufen oder bereits abgelaufen sind.
     *  E-Mail-Logik: Steht im Schwimmer-Datensatz eine Adresse (auch eine
     *  im Attest-Tab manuell geaenderte), gilt diese. Sonst wird die
     *  Adresse der ersten Kontaktperson vorbefuellt — so ist praktisch
     *  immer eine Empfaenger-Adresse eingetragen, bleibt aber aenderbar. */
    public static function liste() {
        self::check();
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT s.id, s.first_name, s.last_name, s.attest_expires,
                    g.name AS mannschaft,
                    DATEDIFF( s.attest_expires, CURDATE() ) AS tage,
                    COALESCE( NULLIF( s.email, '' ), k.email, '' ) AS email,
                    CASE WHEN ( s.email IS NULL OR s.email = '' ) AND k.email IS NOT NULL
                         THEN 1 ELSE 0 END AS email_von_kontakt,
                    k.name AS kontakt_name
               FROM {$p}mv_swimmers s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
          LEFT JOIN {$p}lsv07i_kontakte k ON k.id = (
                        SELECT k2.id FROM {$p}lsv07i_kontakte k2
                         WHERE k2.swimmer_id = s.id AND k2.email <> ''
                      ORDER BY k2.sort_order ASC, k2.id ASC LIMIT 1 )
              WHERE s.active = 1
                AND s.attest_expires IS NOT NULL
                AND s.attest_expires <= DATE_ADD( CURDATE(), INTERVAL 30 DAY )
           ORDER BY s.attest_expires ASC, s.last_name ASC",
            ARRAY_A
        );
        wp_send_json_success( $rows ?: [] );
    }

    public static function template_get() {
        self::check();
        wp_send_json_success( [
            'betreff' => LSV07I_DB::get_config( self::CFG_BETREFF,
                'Erinnerung: Sportärztliches Attest läuft ab' ),
            'text'    => LSV07I_DB::get_config( self::CFG_TEXT,
                "Hallo {vorname},\n\ndas sportärztliche Attest von {name} läuft am {datum} ab bzw. ist bereits abgelaufen.\n\nBitte reiche zeitnah ein aktuelles Attest beim Trainer oder der Geschäftsstelle ein, damit die Teilnahme am Training weiterhin möglich ist.\n\nSportliche Grüße\nDein LSV 07" ),
        ] );
    }

    public static function template_save() {
        self::check();
        $betreff = sanitize_text_field( wp_unslash( $_POST['betreff'] ?? '' ) );
        $text    = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
        if ( $betreff === '' || $text === '' ) {
            wp_send_json_error( [ 'message' => 'Betreff und Text dürfen nicht leer sein.' ] );
        }
        LSV07I_DB::set_config( self::CFG_BETREFF, $betreff );
        LSV07I_DB::set_config( self::CFG_TEXT, $text );
        LSV07I_Log::write( 'attest_vorlage_gespeichert', [ 'bereich' => 'atteste' ] );
        wp_send_json_success( [ 'message' => 'Vorlage gespeichert.' ] );
    }

    /** E-Mail-Adresse eines Schwimmers direkt aus dem Attest-Tab anpassen. */
    public static function email_update() {
        self::check();
        global $wpdb;
        $id    = absint( $_POST['swimmer_id'] ?? 0 );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Ungültiger Schwimmer.' ] );
        if ( $email !== '' && ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige E-Mail-Adresse.' ] );
        }
        $wpdb->update( $wpdb->prefix . 'mv_swimmers',
            [ 'email' => $email ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
        LSV07I_Log::write( 'attest_email_geaendert', [
            'bereich' => 'atteste', 'ziel_id' => $id, 'details' => $email,
        ] );
        wp_send_json_success( [ 'message' => 'E-Mail aktualisiert.' ] );
    }

    /** Erinnerungs-Mail an einen Schwimmer verschicken (manuell, per Button).
     *  Empfaenger folgt derselben Logik wie die Liste: Schwimmer-E-Mail,
     *  sonst die der ersten Kontaktperson — sonst wuerde die Liste eine
     *  Adresse anzeigen, der Versand aber fehlschlagen.                  */
    public static function mail_send() {
        self::check();
        global $wpdb;
        $id = absint( $_POST['swimmer_id'] ?? 0 );
        $s  = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.id, s.first_name, s.last_name, s.attest_expires,
                    COALESCE( NULLIF( s.email, '' ),
                        ( SELECT k.email FROM {$wpdb->prefix}lsv07i_kontakte k
                           WHERE k.swimmer_id = s.id AND k.email <> ''
                        ORDER BY k.sort_order ASC, k.id ASC LIMIT 1 ),
                        '' ) AS email
               FROM {$wpdb->prefix}mv_swimmers s WHERE s.id = %d", $id ), ARRAY_A );
        if ( ! $s ) wp_send_json_error( [ 'message' => 'Schwimmer nicht gefunden.' ] );
        if ( empty( $s['email'] ) || ! is_email( $s['email'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine gültige E-Mail-Adresse hinterlegt.' ] );
        }

        $betreff = LSV07I_DB::get_config( self::CFG_BETREFF, 'Erinnerung: Attest läuft ab' );
        $text    = LSV07I_DB::get_config( self::CFG_TEXT, 'Das Attest von {name} läuft am {datum} ab.' );

        $datum_de = $s['attest_expires']
            ? date_i18n( 'd.m.Y', strtotime( $s['attest_expires'] ) ) : '-';
        $repl = [
            '{name}'     => trim( $s['first_name'] . ' ' . $s['last_name'] ),
            '{vorname}'  => $s['first_name'],
            '{nachname}' => $s['last_name'],
            '{datum}'    => $datum_de,
        ];
        $betreff = strtr( $betreff, $repl );
        $text    = strtr( $text, $repl );

        $ok = wp_mail( $s['email'], $betreff, $text );
        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => 'Versand fehlgeschlagen (Mailserver).' ] );
        }
        LSV07I_Log::write( 'attest_mail_gesendet', [
            'bereich'   => 'atteste',
            'ziel_id'   => $id,
            'ziel_name' => $repl['{name}'],
            'details'   => $s['email'],
        ] );
        wp_send_json_success( [ 'message' => 'Mail an ' . $s['email'] . ' gesendet.' ] );
    }
}
