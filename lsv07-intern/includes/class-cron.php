<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Cron {

    public static function init() {
        add_action( 'lsv07i_anwesenheit_check', [ __CLASS__, 'check_anwesenheit' ] );
        add_action( 'lsv07i_attest_check',      [ __CLASS__, 'check_attest' ] );

        if ( ! wp_next_scheduled( 'lsv07i_anwesenheit_check' ) ) {
            wp_schedule_event( time(), 'hourly', 'lsv07i_anwesenheit_check' );
        }
        if ( ! wp_next_scheduled( 'lsv07i_attest_check' ) ) {
            // Täglich morgens um 8 Uhr (grob)
            wp_schedule_event( strtotime( 'tomorrow 8:00' ), 'daily', 'lsv07i_attest_check' );
        }
    }

    public static function unschedule() {
        foreach ( [ 'lsv07i_anwesenheit_check', 'lsv07i_attest_check' ] as $hook ) {
            $ts = wp_next_scheduled( $hook );
            if ( $ts ) wp_unschedule_event( $ts, $hook );
        }
    }

    /**
     * Prüft ob Trainings ohne Anwesenheitseintrag existieren und mahnt Trainer.
     */
    public static function check_anwesenheit() {
        global $wpdb;
        $p = $wpdb->prefix;

        $stunden = (int) LSV07I_DB::get_config( 'anwesenheit_mahnung_stunden', 48 );
        if ( $stunden <= 0 ) return;

        $jetzt     = current_time( 'timestamp' );
        $heute     = date( 'Y-m-d', $jetzt );
        $wochentag = (int) date( 'N', $jetzt );

        $slots = $wpdb->get_results(
            "SELECT s.*, g.name AS mannschaft_name
               FROM {$p}lsv07i_training_slots s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id",
            ARRAY_A
        );

        foreach ( $slots as $slot ) {
            $slot_wt      = (int) $slot['wochentag'];
            $tage_zurueck = ( $wochentag - $slot_wt + 7 ) % 7;

            if ( $tage_zurueck === 0 ) {
                $slot_ende_ts = strtotime( $heute . ' ' . $slot['zeit_bis'] );
                if ( $slot_ende_ts > $jetzt ) continue;
                $training_datum = $heute;
            } else {
                $training_datum = date( 'Y-m-d', $jetzt - ( $tage_zurueck * 86400 ) );
            }

            $slot_ende_ts = strtotime( $training_datum . ' ' . $slot['zeit_bis'] );
            $diff_stunden = ( $jetzt - $slot_ende_ts ) / 3600;
            if ( $diff_stunden < 1 || $diff_stunden > ( $stunden + 2 ) ) continue;

            $vorhanden = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_anwesenheit WHERE slot_id = %d AND training_datum = %s LIMIT 1",
                $slot['id'], $training_datum
            ) );
            if ( $vorhanden ) continue;

            $ausgefallen = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_training_ausfall WHERE slot_id = %d AND training_datum = %s LIMIT 1",
                $slot['id'], $training_datum
            ) );
            if ( $ausgefallen ) continue;

            $flag_key = 'lsv07i_mahnung_' . $slot['id'] . '_' . $training_datum;
            if ( get_option( $flag_key ) ) continue;

            $trainer_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT trainer_id FROM {$p}lsv07i_trainer_mannschaft WHERE mannschaft_id = %d",
                $slot['mannschaft_id']
            ) );

            $wt_name = [ 1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',
                         5=>'Freitag',6=>'Samstag',7=>'Sonntag' ][ $slot['wochentag'] ] ?? '';

            foreach ( $trainer_ids as $tid ) {
                $trainer = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$p}lsv07i_trainer WHERE id = %d AND aktiv = 1 LIMIT 1", $tid
                ), ARRAY_A );
                if ( ! $trainer || ! $trainer['email'] ) continue;

                LSV07I_Mail::send(
                    $trainer['email'],
                    '[LSV07] Anwesenheit fehlt – ' . $slot['mannschaft_name'],
                    "Hallo {$trainer['name']},\n\n"
                    . "die Anwesenheit fuer das Training am $wt_name, $training_datum "
                    . "({$slot['zeit_von']} - {$slot['zeit_bis']}, {$slot['mannschaft_name']}) "
                    . "wurde bisher nicht eingetragen.\n\n"
                    . "Bitte trage die Anwesenheit nach oder markiere das Training als ausgefallen.\n\n"
                    . "Automatische Erinnerung des LSV07 Systems.",
                    'mail_anwesenheit_trainer'
                );
            }
            update_option( $flag_key, true );
        }
    }

    /**
     * Prüft täglich ob Schwimmer-Atteste ablaufen und benachrichtigt Kontaktperson 1.
     */
    public static function check_attest() {
        if ( LSV07I_DB::get_config( 'mail_deaktiviert', '0' ) === '1' ) return;

        // Mindestens ein Attest-Toggle muss aktiv sein
        $notify_kontakt = LSV07I_DB::get_config( 'mail_attest_kontakt', '1' ) === '1';
        $notify_trainer = LSV07I_DB::get_config( 'mail_attest_trainer', '1' ) === '1';
        if ( ! $notify_kontakt && ! $notify_trainer ) return;

        global $wpdb;
        $p    = $wpdb->prefix;
        $tage = max( 1, (int) LSV07I_DB::get_config( 'attest_warnung_tage', 21 ) );

        $datum_von = date( 'Y-m-d', strtotime( "+$tage days" ) );
        $datum_bis = date( 'Y-m-d', strtotime( '+' . ( $tage + 1 ) . ' days' ) );

        $schwimmer = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, g.name AS mannschaft_name, g.id AS team_id
               FROM {$p}mv_swimmers s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
              WHERE s.active = 1
                AND s.attest_expires IS NOT NULL
                AND s.attest_expires >= %s
                AND s.attest_expires < %s",
            $datum_von, $datum_bis
        ), ARRAY_A );

        foreach ( $schwimmer as $sw ) {
            $flag = 'lsv07i_attest_mail_' . $sw['id'] . '_' . date( 'Y-m-d' );
            if ( get_transient( $flag ) ) continue;

            $name     = $sw['first_name'] . ' ' . $sw['last_name'];
            $ablauf   = date_create( $sw['attest_expires'] );
            $ablauf_f = $ablauf ? $ablauf->format( 'd.m.Y' ) : $sw['attest_expires'];

            $sent = false;

            // Mail an Kontaktperson
            if ( $notify_kontakt || $attest_aktiv ) {
                $kontakt = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$p}lsv07i_kontakte
                      WHERE swimmer_id = %d ORDER BY sort_order ASC LIMIT 1",
                    $sw['id']
                ), ARRAY_A );

                if ( $kontakt && $kontakt['email'] ) {
                    LSV07I_Mail::send(
                        $kontakt['email'],
                        'Attest läuft bald ab – ' . $name,
                        "Hallo {$kontakt['name']},\n\n"
                        . "das Schwimmattest von $name ({$sw['mannschaft_name']}) "
                        . "läuft am $ablauf_f ab (in $tage Tagen).\n\n"
                        . "Bitte sorgen Sie dafür, dass ein neues Attest rechtzeitig beim Verein eingereicht wird.\n\n"
                        . "Bei Fragen wenden Sie sich an den Vereinsadministrator.\n\n"
                        . "LSV07 Schwimmverein",
                        'mail_attest_kontakt'
                    );
                    $sent = true;
                }
            }

            // Mail an alle Trainer der Mannschaft
            if ( $notify_trainer && $sw['team_id'] ) {
                $trainer_emails = LSV07I_Mail::get_trainer_emails_fuer_mannschaft( $sw['team_id'] );
                if ( ! empty( $trainer_emails ) ) {
                    LSV07I_Mail::send(
                        $trainer_emails,
                        'Attest läuft bald ab – ' . $name,
                        "Hallo,\n\n"
                        . "Das Schwimmattest von $name ({$sw['mannschaft_name']}) "
                        . "läuft am $ablauf_f ab (in $tage Tagen).\n\n"
                        . "Bitte die Eltern / Kontaktperson informieren.\n\n"
                        . "Automatische Benachrichtigung.",
                        'mail_attest_trainer'
                    );
                    $sent = true;
                }
            }

            if ( $sent ) {
                set_transient( $flag, true, 23 * HOUR_IN_SECONDS );
            }
        }
    }
}
