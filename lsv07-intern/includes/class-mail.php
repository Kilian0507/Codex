<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Mail {

    // Config-Keys für alle Mail-Toggles
    const TOGGLES = [
        'mail_attest_trainer'       => '1', // Attest-Ablauf → alle Trainer der Mannschaft
        'mail_attest_kontakt'       => '1', // Attest-Ablauf → Kontaktperson
        'mail_anwesenheit_trainer'  => '1', // Anwesenheit fehlt → Trainer
        'mail_springer_eintrag'     => '1', // Springer eingetragen → Trainer Mannschaft
        'mail_springer_austrag'     => '1', // Springer ausgetragen → Trainer Mannschaft
        'mail_bestzeiten_trainer'   => '1', // Neue Bestzeiten → Trainer Mannschaft
        'mail_abr_schwimmwart'      => '1', // Abrechnung eingereicht → Schwimmwart
        'mail_abr_kassenwart'       => '1', // Abrechnung genehmigt → Kassenwart
        'mail_abr_trainer'          => '1', // Abrechnung-Status → Trainer
    ];

    /**
     * Sendet eine E-Mail über WP (nutzt automatisch WP SMTP Plugin).
     * Prüft globalen Deaktivierungs-Toggle und individuellen Feature-Toggle.
     */
    public static function send( $to, $subject, $body, $toggle_key = null ) {
        // Globaler Deaktivierungs-Schalter
        if ( (string) LSV07I_DB::get_config( 'mail_deaktiviert', '0' ) === '1' ) {
            return false;
        }
        // Feature-spezifischer Toggle
        if ( $toggle_key !== null ) {
            $default = self::TOGGLES[ $toggle_key ] ?? '1';
            if ( (string) LSV07I_DB::get_config( $toggle_key, $default ) !== '1' ) {
                return false;
            }
        }
        if ( empty( $to ) ) return false;

        $site    = get_bloginfo( 'name' );
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site . ' <' . get_option( 'admin_email' ) . '>',
        ];
        return wp_mail( $to, '[' . $site . '] ' . $subject, $body, $headers );
    }

    /**
     * Alle Trainer-E-Mails einer Mannschaft ermitteln.
     */
    public static function get_trainer_emails_fuer_mannschaft( $mannschaft_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT t.email
               FROM {$p}lsv07i_trainer t
               JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.trainer_id = t.id
              WHERE tm.mannschaft_id = %d AND t.aktiv = 1 AND t.email != ''",
            $mannschaft_id
        ) );
    }

    /**
     * E-Mail an alle Kassenwarte (finanzwart-Rolle).
     */
    public static function get_kassenwart_emails() {
        $users = get_users( [ 'role' => 'lsv07_finanzwart' ] );
        $emails = [];
        foreach ( $users as $u ) {
            if ( $u->user_email ) $emails[] = $u->user_email;
        }
        // Fallback: admin_email
        if ( empty( $emails ) ) {
            $emails[] = get_option( 'admin_email' );
        }
        return $emails;
    }

    // ── Springer eingetragen → Trainer der Mannschaft ─────────────────────────
    public static function springer_eingetragen( $slot_id, $datum, $springer_name ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $slot = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, g.name AS mannschaft_name
               FROM {$p}lsv07i_training_slots s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id
              WHERE s.id = %d LIMIT 1",
            $slot_id
        ), ARRAY_A );
        if ( ! $slot ) return;

        $emails = self::get_trainer_emails_fuer_mannschaft( $slot['mannschaft_id'] );
        if ( empty( $emails ) ) return;

        $datum_de = date( 'd.m.Y', strtotime( $datum ) );
        $wt = [ 1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',5=>'Freitag',6=>'Samstag',7=>'Sonntag' ];
        $wochentag = $wt[ (int) $slot['wochentag'] ] ?? '';

        self::send(
            $emails,
            'Springer eingetragen – ' . $slot['mannschaft_name'],
            "Hallo,\n\n" .
            $springer_name . " hat sich als Springer für folgendes Training eingetragen:\n\n" .
            "Mannschaft: " . $slot['mannschaft_name'] . "\n" .
            "Datum: " . $wochentag . ", " . $datum_de . "\n" .
            "Zeit: " . substr( $slot['zeit_von'], 0, 5 ) . " – " . substr( $slot['zeit_bis'], 0, 5 ) . "\n\n" .
            "Automatische Benachrichtigung.",
            'mail_springer_eintrag'
        );
    }

    // ── Springer ausgetragen → Trainer der Mannschaft ─────────────────────────
    public static function springer_ausgetragen( $slot_id, $datum, $springer_name ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $slot = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, g.name AS mannschaft_name
               FROM {$p}lsv07i_training_slots s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id
              WHERE s.id = %d LIMIT 1",
            $slot_id
        ), ARRAY_A );
        if ( ! $slot ) return;

        $emails = self::get_trainer_emails_fuer_mannschaft( $slot['mannschaft_id'] );
        if ( empty( $emails ) ) return;

        $datum_de = date( 'd.m.Y', strtotime( $datum ) );

        self::send(
            $emails,
            'Springer ausgetragen – ' . $slot['mannschaft_name'],
            "Hallo,\n\n" .
            $springer_name . " hat sich als Springer wieder ausgetragen:\n\n" .
            "Mannschaft: " . $slot['mannschaft_name'] . "\n" .
            "Datum: " . $datum_de . "\n" .
            "Zeit: " . substr( $slot['zeit_von'], 0, 5 ) . " – " . substr( $slot['zeit_bis'], 0, 5 ) . "\n\n" .
            "Automatische Benachrichtigung.",
            'mail_springer_austrag'
        );
    }

    // ── Neue Bestzeiten → Trainer der Mannschaft ──────────────────────────────
    public static function bestzeiten_hochgeladen( $mannschaft_id, $mannschaft_name, $anzahl ) {
        $emails = self::get_trainer_emails_fuer_mannschaft( $mannschaft_id );
        if ( empty( $emails ) ) return;

        self::send(
            $emails,
            'Neue Bestzeiten – ' . $mannschaft_name,
            "Hallo,\n\n" .
            "Für die Mannschaft \"$mannschaft_name\" wurden $anzahl neue Bestzeiten hochgeladen.\n\n" .
            "Bitte im internen Bereich unter Bestzeiten einsehen.\n\n" .
            "Automatische Benachrichtigung.",
            'mail_bestzeiten_trainer'
        );
    }

    // ── Abrechnung genehmigt → Kassenwart ─────────────────────────────────────
    public static function abrechnung_genehmigt_kassenwart( $trainer_name, $quartal, $jahr, $gesamt ) {
        $emails = self::get_kassenwart_emails();
        $betrag = number_format( $gesamt, 2, ',', '.' ) . ' €';

        self::send(
            $emails,
            'Abrechnung genehmigt – ' . $trainer_name,
            "Hallo,\n\n" .
            "Die Abrechnung von $trainer_name für $quartal $jahr wurde genehmigt.\n\n" .
            "Gesamtbetrag: $betrag\n\n" .
            "Bitte im internen Bereich unter Kassenwart → Jahresübersicht einsehen und als bezahlt markieren.\n\n" .
            "Automatische Benachrichtigung.",
            'mail_abr_kassenwart'
        );
    }

    // ── Abrechnung-Status → Trainer ───────────────────────────────────────────
    public static function abrechnung_status_trainer( $trainer_email, $trainer_name, $status, $quartal, $jahr, $grund = '' ) {
        if ( ! $trainer_email ) return;
        $status_texte = [
            'genehmigt'  => 'genehmigt',
            'zurueck'    => 'zurückgegeben',
            'bezahlt'    => 'als bezahlt markiert',
        ];
        $status_text = $status_texte[ $status ] ?? $status;

        $body = "Hallo $trainer_name,\n\n" .
            "Ihre Abrechnung für $quartal $jahr wurde $status_text.\n";
        if ( $grund ) {
            $body .= "\nGrund: $grund\n";
        }
        if ( $status === 'zurueck' ) {
            $body .= "\nBitte korrigieren und erneut einreichen.\n";
        }
        $body .= "\nBitte im internen Bereich unter Trainer → Meine Abrechnung einsehen.\n\n" .
            "Automatische Benachrichtigung.";

        self::send( $trainer_email, 'Abrechnung ' . $status_text . ' – ' . $quartal . ' ' . $jahr, $body, 'mail_abr_trainer' );
    }
}
