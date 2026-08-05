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
        'mail_wk_approve_anfrage'   => '1', // Neuer Wettkampf → alle mit Freigabe-Recht
        'mail_wk_erinnerung_meldeergebnis' => '1', // 3 Tage vor WK-Beginn → Erinnerungsadressen
        'mail_wk_erinnerung_protokoll'     => '1', // 1 Tag nach WK-Ende → Erinnerungsadressen
    ];

    /**
     * Globaler Mail-Schalter — optional zusätzlich ein Feature-Toggle.
     */
    public static function is_enabled( $toggle_key = null ) {
        if ( (string) LSV07I_DB::get_config( 'mail_deaktiviert', '0' ) === '1' ) {
            return false;
        }
        if ( $toggle_key !== null ) {
            $default = self::TOGGLES[ $toggle_key ] ?? '1';
            if ( (string) LSV07I_DB::get_config( $toggle_key, $default ) !== '1' ) {
                return false;
            }
        }
        return true;
    }

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
     * Sendet eine Testmail: exakt dieselbe Vorlage wie die echte
     * Benachrichtigung, aber mit Beispieldaten, an eine frei gewählte
     * Adresse — ignoriert bewusst BEIDE Toggles (global + je Mailtyp),
     * damit man auch eine gerade deaktivierte Benachrichtigung ansehen
     * kann, bevor man sie einschaltet.
     */
    public static function send_test( $to, $subject, $body ) {
        if ( empty( $to ) || ! is_email( $to ) ) return false;
        $site    = get_bloginfo( 'name' );
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site . ' <' . get_option( 'admin_email' ) . '>',
        ];
        return wp_mail( $to, '[' . $site . '] [Testmail] ' . $subject, $body, $headers );
    }

    /**
     * Betreff + Text einer Benachrichtigung mit Beispieldaten, zur
     * Vorschau per Testmail. Wortlaut ist bewusst identisch mit der
     * jeweiligen echten Versandstelle (siehe Methoden unten bzw.
     * class-cron.php / class-ajax-abrechnung.php) — nur mit
     * Platzhalter-Namen/-Daten statt echten Personen.
     *
     * @return array{0:string,1:string}|null [subject, body] oder null
     *         bei unbekanntem $toggle_key.
     */
    public static function test_inhalt( $toggle_key ) {
        $muster = [
            'mail_attest_trainer' => [
                'Attest läuft bald ab – Max Mustermann',
                "Hallo,\n\n"
                . "Das Schwimmattest von Max Mustermann (A-Mannschaft) "
                . "läuft am 15.03.2026 ab (in 21 Tagen).\n\n"
                . "Bitte die Eltern / Kontaktperson informieren.\n\n"
                . "Automatische Benachrichtigung.",
            ],
            'mail_attest_kontakt' => [
                'Attest läuft bald ab – Max Mustermann',
                "Hallo Erika Mustermann,\n\n"
                . "das Schwimmattest von Max Mustermann (A-Mannschaft) "
                . "läuft am 15.03.2026 ab (in 21 Tagen).\n\n"
                . "Bitte sorgen Sie dafür, dass ein neues Attest rechtzeitig beim Verein eingereicht wird.\n\n"
                . "Bei Fragen wenden Sie sich an den Vereinsadministrator.\n\n"
                . "LSV07 Schwimmverein",
            ],
            'mail_anwesenheit_trainer' => [
                'Anwesenheit fehlt – A-Mannschaft',
                "Hallo Trainer Beispiel,\n\n"
                . "die Anwesenheit fuer das Training am Montag, 2026-03-09 "
                . "(17:00 - 18:30, A-Mannschaft) wurde bisher nicht eingetragen.\n\n"
                . "Bitte trage die Anwesenheit nach oder markiere das Training als ausgefallen.\n\n"
                . "Automatische Erinnerung des LSV07 Systems.",
            ],
            'mail_springer_eintrag' => [
                'Springer eingetragen – A-Mannschaft',
                "Hallo,\n\n"
                . "Max Mustermann hat sich als Springer für folgendes Training eingetragen:\n\n"
                . "Mannschaft: A-Mannschaft\n"
                . "Datum: Montag, 09.03.2026\n"
                . "Zeit: 17:00 – 18:30\n\n"
                . "Automatische Benachrichtigung.",
            ],
            'mail_springer_austrag' => [
                'Springer ausgetragen – A-Mannschaft',
                "Hallo,\n\n"
                . "Max Mustermann hat sich als Springer wieder ausgetragen:\n\n"
                . "Mannschaft: A-Mannschaft\n"
                . "Datum: 09.03.2026\n"
                . "Zeit: 17:00 – 18:30\n\n"
                . "Automatische Benachrichtigung.",
            ],
            'mail_bestzeiten_trainer' => [
                'Neue Bestzeiten – A-Mannschaft',
                "Hallo,\n\n"
                . "Für die Mannschaft \"A-Mannschaft\" wurden 12 neue Bestzeiten hochgeladen.\n\n"
                . "Bitte im internen Bereich unter Bestzeiten einsehen.\n\n"
                . "Automatische Benachrichtigung.",
            ],
            'mail_abr_schwimmwart' => [
                'Neue Abrechnung eingereicht – Max Mustermann',
                "Hallo,\n\n"
                . "Der Trainer Max Mustermann hat seine Abrechnung für Q1 2026 eingereicht.\n\n"
                . "Bitte im internen Bereich unter Verwaltung prüfen.\n\n"
                . "Automatische Benachrichtigung.",
            ],
            'mail_abr_kassenwart' => [
                'Abrechnung genehmigt – Max Mustermann',
                "Hallo,\n\n"
                . "Die Abrechnung von Max Mustermann für Q1 2026 wurde genehmigt.\n\n"
                . "Gesamtbetrag: 123,45 €\n\n"
                . "Bitte im internen Bereich unter Kassenwart → Jahresübersicht einsehen und als bezahlt markieren.\n\n"
                . "Automatische Benachrichtigung.",
            ],
            'mail_abr_trainer' => [
                'Abrechnung genehmigt – Q1 2026',
                "Hallo Max Mustermann,\n\n"
                . "Ihre Abrechnung für Q1 2026 wurde genehmigt.\n\n"
                . "Bitte im internen Bereich unter Trainer → Meine Abrechnung einsehen.\n\n"
                . "Automatische Benachrichtigung.",
            ],
            'mail_wk_approve_anfrage' => [
                'Neuer Wettkampf wartet auf Freigabe – Herbstpokal',
                "Hallo,\n\n"
                . "ein neuer Wettkampf wurde angelegt und wartet auf Freigabe:\n\n"
                . "Name: Herbstpokal\n"
                . "Ort: Stadtbad Musterstadt\n"
                . "Zeitraum: 10.10.2026 – 11.10.2026\n\n"
                . "Bitte im internen Bereich unter Schwimmen → Wettkämpfe prüfen und freigeben.\n\n"
                . "Automatische Benachrichtigung.",
            ],
            'mail_wk_erinnerung_meldeergebnis' => [
                'Erinnerung: Meldeergebnis hochladen – Herbstpokal',
                "Hallo,\n\n"
                . "der Wettkampf \"Herbstpokal\" (Stadtbad Musterstadt, 10.10.2026 – 11.10.2026) beginnt in 3 Tagen.\n\n"
                . "Bitte das Meldeergebnis im internen Bereich unter Schwimmen → Wettkämpfe hochladen, sobald es vorliegt.\n\n"
                . "Automatische Erinnerung.",
            ],
            'mail_wk_erinnerung_protokoll' => [
                'Erinnerung: Protokoll hochladen – Herbstpokal',
                "Hallo,\n\n"
                . "der Wettkampf \"Herbstpokal\" (Stadtbad Musterstadt, 10.10.2026 – 11.10.2026) ist beendet.\n\n"
                . "Bitte das Protokoll im internen Bereich unter Schwimmen → Wettkämpfe hochladen.\n\n"
                . "Automatische Erinnerung.",
            ],
        ];
        return $muster[ $toggle_key ] ?? null;
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

    // ── Wettkampf: neu angelegt → alle mit Freigabe-Recht ─────────────────────
    public static function wettkampf_freigabe_anfrage( $emails, $name, $ort, $zeitraum ) {
        if ( empty( $emails ) ) return;
        self::send(
            $emails,
            'Neuer Wettkampf wartet auf Freigabe – ' . $name,
            "Hallo,\n\n"
            . "ein neuer Wettkampf wurde angelegt und wartet auf Freigabe:\n\n"
            . "Name: $name\n"
            . "Ort: $ort\n"
            . "Zeitraum: $zeitraum\n\n"
            . "Bitte im internen Bereich unter Schwimmen → Wettkämpfe prüfen und freigeben.\n\n"
            . "Automatische Benachrichtigung.",
            'mail_wk_approve_anfrage'
        );
    }

    // ── Wettkampf: 3 Tage vor Beginn → Erinnerungsadressen ─────────────────────
    public static function wettkampf_erinnerung_meldeergebnis( $emails, $name, $ort, $zeitraum ) {
        if ( empty( $emails ) ) return;
        self::send(
            $emails,
            'Erinnerung: Meldeergebnis hochladen – ' . $name,
            "Hallo,\n\n"
            . "der Wettkampf \"$name\" ($ort, $zeitraum) beginnt in 3 Tagen.\n\n"
            . "Bitte das Meldeergebnis im internen Bereich unter Schwimmen → Wettkämpfe hochladen, sobald es vorliegt.\n\n"
            . "Automatische Erinnerung.",
            'mail_wk_erinnerung_meldeergebnis'
        );
    }

    // ── Wettkampf: 1 Tag nach Ende → Erinnerungsadressen ───────────────────────
    public static function wettkampf_erinnerung_protokoll( $emails, $name, $ort, $zeitraum ) {
        if ( empty( $emails ) ) return;
        self::send(
            $emails,
            'Erinnerung: Protokoll hochladen – ' . $name,
            "Hallo,\n\n"
            . "der Wettkampf \"$name\" ($ort, $zeitraum) ist beendet.\n\n"
            . "Bitte das Protokoll im internen Bereich unter Schwimmen → Wettkämpfe hochladen.\n\n"
            . "Automatische Erinnerung.",
            'mail_wk_erinnerung_protokoll'
        );
    }
}
