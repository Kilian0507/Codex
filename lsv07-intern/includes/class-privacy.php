<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Privacy {

    public static function init() {
        add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers',   [ __CLASS__, 'register_eraser' ] );
    }

    public static function register_exporter( $exporters ) {
        $exporters['lsv07-intern'] = [
            'exporter_friendly_name' => 'LSV07 Interner Bereich',
            'callback'               => [ __CLASS__, 'export_data' ],
        ];
        return $exporters;
    }

    public static function register_eraser( $erasers ) {
        $erasers['lsv07-intern'] = [
            'eraser_friendly_name' => 'LSV07 Interner Bereich',
            'callback'             => [ __CLASS__, 'erase_data' ],
        ];
        return $erasers;
    }

    public static function export_data( $email, $page = 1 ) {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) return [ 'data' => [], 'done' => true ];

        global $wpdb;
        $p = $wpdb->prefix;

        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainer WHERE wp_user_id = %d LIMIT 1", $user->ID
        ), ARRAY_A );

        $export_items = [];

        if ( $trainer ) {
            $export_items[] = [
                'group_id'    => 'lsv07i_trainer',
                'group_label' => 'LSV07 Trainer-Profil',
                'item_id'     => 'trainer-' . $trainer['id'],
                'data'        => [
                    [ 'name' => 'Name',       'value' => $trainer['name'] ],
                    [ 'name' => 'E-Mail',     'value' => $trainer['email'] ],
                ],
            ];

            $stammdaten = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_trainer_stammdaten WHERE trainer_id = %d LIMIT 1",
                $trainer['id']
            ), ARRAY_A );

            if ( $stammdaten ) {
                $export_items[] = [
                    'group_id'    => 'lsv07i_stammdaten',
                    'group_label' => 'LSV07 Abrechnungs-Stammdaten',
                    'item_id'     => 'stamm-' . $trainer['id'],
                    'data'        => [
                        [ 'name' => 'IBAN',    'value' => self::mask_iban( $stammdaten['iban'] ) ],
                        [ 'name' => 'BIC',     'value' => $stammdaten['bic'] ],
                        [ 'name' => 'Strasse', 'value' => $stammdaten['strasse'] ],
                        [ 'name' => 'PLZ',     'value' => $stammdaten['plz'] ],
                        [ 'name' => 'Ort',     'value' => $stammdaten['ort'] ],
                    ],
                ];
            }
        }

        return [ 'data' => self::export_weitere( $export_items, $user->ID ), 'done' => true ];
    }

    /**
     * Ergaenzt den Export um alle weiteren personenbezogenen Datenkategorien:
     * Chat-Nachrichten, Tickets, Aktivitaetsprotokoll und Rechte-Zuweisungen.
     * (Art. 15 DSGVO verlangt Auskunft ueber ALLE gespeicherten Daten.)
     */
    private static function export_weitere( $export_items, $uid ) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Chat-/Konversationsnachrichten (letzte 500)
        $msgs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, erstellt_am, text FROM {$p}lsv07i_konv_msg
              WHERE von_typ = 'user' AND von_id = %d AND geloescht_am IS NULL
              ORDER BY id DESC LIMIT 500", $uid ), ARRAY_A );
        foreach ( (array) $msgs as $m ) {
            $export_items[] = [
                'group_id'    => 'lsv07i_nachrichten',
                'group_label' => 'LSV07 Chat-Nachrichten',
                'item_id'     => 'msg-' . $m['id'],
                'data'        => [
                    [ 'name' => 'Datum',  'value' => $m['erstellt_am'] ],
                    [ 'name' => 'Inhalt', 'value' => wp_strip_all_tags( (string) $m['text'] ) ],
                ],
            ];
        }

        // Tickets
        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, nummer, titel, status, erstellt_am FROM {$p}lsv07i_tickets
              WHERE ersteller_user_id = %d", $uid ), ARRAY_A );
        foreach ( (array) $tickets as $tk ) {
            $export_items[] = [
                'group_id'    => 'lsv07i_tickets',
                'group_label' => 'LSV07 Tickets',
                'item_id'     => 'ticket-' . $tk['id'],
                'data'        => [
                    [ 'name' => 'Nummer',   'value' => $tk['nummer'] ],
                    [ 'name' => 'Titel',    'value' => $tk['titel'] ],
                    [ 'name' => 'Status',   'value' => $tk['status'] ],
                    [ 'name' => 'Erstellt', 'value' => $tk['erstellt_am'] ],
                ],
            ];
        }

        // Aktivitaetsprotokoll (letzte 500)
        $logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, aktion, bereich, zeitpunkt, ip FROM {$p}lsv07i_log
              WHERE user_id = %d ORDER BY id DESC LIMIT 500", $uid ), ARRAY_A );
        foreach ( (array) $logs as $l ) {
            $export_items[] = [
                'group_id'    => 'lsv07i_log',
                'group_label' => 'LSV07 Aktivitaetsprotokoll',
                'item_id'     => 'log-' . $l['id'],
                'data'        => [
                    [ 'name' => 'Aktion',             'value' => $l['aktion'] ],
                    [ 'name' => 'Bereich',            'value' => $l['bereich'] ],
                    [ 'name' => 'Zeitpunkt',          'value' => $l['zeitpunkt'] ],
                    [ 'name' => 'IP (anonymisiert)',  'value' => $l['ip'] ],
                ],
            ];
        }

        // Rechte-Zuweisungen
        $rechte = $wpdb->get_col( $wpdb->prepare(
            "SELECT recht FROM {$p}lsv07i_user_rechte WHERE user_id = %d", $uid ) );
        if ( $rechte ) {
            $export_items[] = [
                'group_id'    => 'lsv07i_rechte',
                'group_label' => 'LSV07 Zugewiesene Rechte',
                'item_id'     => 'rechte-' . $uid,
                'data'        => [
                    [ 'name' => 'Rechte', 'value' => implode( ', ', $rechte ) ],
                ],
            ];
        }

        return $export_items;
    }

    public static function erase_data( $email, $page = 1 ) {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) return [ 'items_removed' => 0, 'items_retained' => 0, 'messages' => [], 'done' => true ];

        global $wpdb;
        $p = $wpdb->prefix;

        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainer WHERE wp_user_id = %d LIMIT 1", $user->ID
        ), ARRAY_A );

        $removed = 0;
        $messages = [];

        if ( $trainer ) {
            $wpdb->delete( $p . 'lsv07i_trainer_stammdaten', [ 'trainer_id' => $trainer['id'] ], [ '%d' ] );
            // Trainer anonymisieren statt loeschen (fuer Statistik-Integritaet)
            $wpdb->update( $p . 'lsv07i_trainer',
                [ 'email' => '', 'wp_user_id' => 0, 'name' => 'Anonymisierter Trainer' ],
                [ 'id'    => $trainer['id'] ],
                [ '%s', '%d', '%s' ], [ '%d' ]
            );
            $removed++;
            $messages[] = 'Trainer-Profil wurde anonymisiert; zugehoerige Abrechnungen '
                        . 'bleiben ohne Personenbezug erhalten (Aufbewahrungspflicht, '
                        . 'Art. 17 Abs. 3 lit. b DSGVO).';
        }

        // Aktivitaetsprotokoll des Users vollstaendig loeschen
        $n = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07i_log WHERE user_id = %d", $user->ID ) );
        if ( $n ) $removed += (int) $n;

        // Rechte-Zuweisungen loeschen
        $n = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07i_user_rechte WHERE user_id = %d", $user->ID ) );
        if ( $n ) $removed += (int) $n;

        // Chat-Nachrichten anonymisieren: Inhalte bleiben fuer die uebrigen
        // Teilnehmer konsistent, aber der Personenbezug wird entfernt.
        $n = $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}lsv07i_konv_msg
                SET von_name = 'Geloeschter Benutzer', von_email = NULL
              WHERE von_typ = 'user' AND von_id = %d", $user->ID ) );
        if ( $n ) { $removed += (int) $n; $messages[] = 'Chat-Nachrichten wurden anonymisiert.'; }

        // Konversations-Teilnahmen anonymisieren
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}lsv07i_konv_teilnehmer
                SET teilnehmer_name = 'Geloeschter Benutzer', teilnehmer_email = NULL
              WHERE teilnehmer_typ = 'user' AND teilnehmer_id = %d", $user->ID ) );

        return [
            'items_removed'  => $removed,
            'items_retained' => 0,
            'messages'       => $messages,
            'done'           => true,
        ];
    }

    private static function mask_iban( $iban ) {
        if ( strlen( $iban ) < 8 ) return $iban;
        return substr( $iban, 0, 4 ) . str_repeat( '*', strlen( $iban ) - 8 ) . substr( $iban, -4 );
    }
}
