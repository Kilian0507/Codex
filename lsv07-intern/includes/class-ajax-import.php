<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Excel/CSV Import für Trainer und Schwimmer.
 * Unterstützt .csv und .xlsx (als CSV konvertiert per SheetJS im Frontend).
 * Die eigentliche Verarbeitung passiert serverseitig per CSV-String.
 */
class LSV07I_Ajax_Import {

    public static function init() {
        $ajax_actions = [
            'lsv07i_import_trainer',
            'lsv07i_import_schwimmer',
        ];
        foreach ( $ajax_actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_import_', '', $action ) ] );
        }
        // Sample-Downloads über init-Hook (kein AJAX, direkter Download)
        add_action( 'init', [ __CLASS__, 'handle_sample_download' ] );
    }

    // ── Beispieldateien (direkter Download via init-Hook) ────────────────────

    public static function handle_sample_download() {
        if ( empty( $_GET['lsv07i_sample'] ) ) return;
        if ( ! is_user_logged_in() || ! current_user_can( 'administrator' ) ) {
            wp_die( 'Keine Berechtigung.' );
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'lsv07i_sample' ) ) {
            wp_die( 'Ungültige Anfrage.' );
        }
        $type = sanitize_key( $_GET['lsv07i_sample'] );
        if ( $type === 'trainer' ) {
            self::send_csv( self::trainer_sample_rows(), 'trainer-import-vorlage.csv' );
        } elseif ( $type === 'schwimmer' ) {
            self::send_csv( self::schwimmer_sample_rows(), 'schwimmer-import-vorlage.csv' );
        }
    }

    private static function trainer_sample_rows() {
        return [
            [ 'Nachname','Vorname','Geburtsdatum','Telefon','Email Privat','Email Geschaeftlich',
              'Trainervertrag','Fuehrungszeugnis bis','Verhaltenskodex','Datenschutz',
              'Rettungsschwimmer bis','Trainerlizenz','Lizenznummer','Lizenz gueltig bis' ],
            [ 'Mustermann','Max','1985-06-15','01701234567','max@privat.de','max@verein.de',
              '1','2026-12-31','1','1','2027-06-30','1','T-12345','2028-01-01' ],
            [ 'Musterfrau','Maria','1990-03-22','','maria@privat.de','',
              '0','','0','1','','0','','' ],
        ];
    }

    private static function schwimmer_sample_rows() {
        return [
            [ 'Nachname','Vorname','Geburtsdatum','Mannschaft','DSV-ID','Attest bis','Datenschutz',
              'Kontakt1 Name','Kontakt1 Telefon','Kontakt1 Email',
              'Kontakt2 Name','Kontakt2 Telefon','Kontakt2 Email',
              'Kontakt3 Name','Kontakt3 Telefon','Kontakt3 Email' ],
            [ 'Musterkind','Anna','2010-04-01','Gruppe A','DSV-987654','2025-09-30','1',
              'Mustermann Max','01701234567','max@privat.de',
              'Musterfrau Maria','01702345678','maria@privat.de',
              '','','' ],
            [ 'Beispielsohn','Ben','2012-08-15','Gruppe A; Gruppe B','','','0',
              'Beispiel Elternteil','01703456789','',
              '','','', '','','' ],
        ];
    }

    private static function send_csv( $rows, $filename ) {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr(0xEF).chr(0xBB).chr(0xBF) ); // UTF-8 BOM für Excel
        foreach ( $rows as $row ) {
            fputcsv( $out, $row, ';', '"', '\\' );
        }
        fclose( $out );
        exit;
    }

    // ── Trainer Import ────────────────────────────────────────────────────────

    public static function trainer() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p = $wpdb->prefix;

        $csv_data = sanitize_textarea_field( wp_unslash( $_POST['csv_data'] ?? '' ) );
        if ( ! $csv_data ) wp_send_json_error( [ 'message' => 'Keine Daten übermittelt.' ] );

        $rows     = self::parse_csv( $csv_data );
        if ( count( $rows ) < 2 ) wp_send_json_error( [ 'message' => 'Datei ist leer oder hat keine Datenzeilen.' ] );

        $header   = array_map( 'mb_strtolower', array_map( 'trim', $rows[0] ) );
        $imported = 0;
        $errors   = [];

        for ( $i = 1; $i < count( $rows ); $i++ ) {
            $row = $rows[$i];
            if ( count( array_filter( $row ) ) === 0 ) continue; // Leerzeile

            $col = self::map_row( $header, $row );

            $nachname  = self::g( $col, [ 'nachname', 'name', 'last_name', 'lastname' ] );
            $vorname   = self::g( $col, [ 'vorname', 'first_name', 'firstname' ] );
            $geburt    = self::parse_date( self::g( $col, [ 'geburtsdatum', 'geburt', 'birth', 'birthdate', 'birth_date' ] ) );

            if ( ! $nachname || ! $vorname || ! $geburt ) {
                $errors[] = "Zeile " . ($i+1) . ": Nachname, Vorname oder Geburtsdatum fehlt – übersprungen.";
                continue;
            }

            $jahr         = date( 'Y', strtotime( $geburt ) );
            $display_name = $nachname . ', ' . $vorname . ' (' . $jahr . ')';
            $name_compat  = trim( $vorname . ' ' . $nachname );

            $data = [
                'name'             => $name_compat,
                'vorname'          => $vorname,
                'email'            => self::g( $col, [ 'email privat','email_privat','email' ] ),
                'email_privat'     => self::g( $col, [ 'email privat','email_privat','email' ] ),
                'email_geschaeft'  => self::g( $col, [ 'email geschaeftlich','email_geschaeftlich','email geschäftlich' ] ),
                'telefon'          => self::g( $col, [ 'telefon','phone','tel' ] ),
                'geburtsdatum'     => $geburt,
                'trainervertrag'   => self::bool_val( self::g( $col, [ 'trainervertrag' ] ) ),
                'fuehrungszeugnis' => self::parse_date( self::g( $col, [ 'fuehrungszeugnis bis','führungszeugnis bis','fuehrungszeugnis' ] ) ) ?: null,
                'verhaltenskodex'  => self::bool_val( self::g( $col, [ 'verhaltenskodex' ] ) ),
                'datenschutz'      => self::bool_val( self::g( $col, [ 'datenschutz' ] ) ),
                'rettungsschwimmer'=> self::parse_date( self::g( $col, [ 'rettungsschwimmer bis','rettungsschwimmer' ] ) ) ?: null,
                'trainerlizenz'    => self::bool_val( self::g( $col, [ 'trainerlizenz' ] ) ),
                'lizenznummer'     => self::g( $col, [ 'lizenznummer' ] ),
                'lizenz_gueltig'   => self::parse_date( self::g( $col, [ 'lizenz gueltig bis','lizenz_gueltig','lizenz gültig bis' ] ) ) ?: null,
                'display_name'     => $display_name,
                'aktiv'            => 1,
                'wp_user_id'       => 0,
            ];

            // Duplikat prüfen (gleicher display_name)
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_trainer WHERE display_name = %s AND aktiv = 1 LIMIT 1",
                $display_name
            ) );

            if ( $existing_id ) {
                // Update
                unset( $data['aktiv'], $data['wp_user_id'] );
                $wpdb->update( $p . 'lsv07i_trainer', $data, [ 'id' => $existing_id ] );
            } else {
                $wpdb->insert( $p . 'lsv07i_trainer', $data );
            }
            $imported++;
        }

        wp_send_json_success( [
            'imported' => $imported,
            'errors'   => $errors,
            'trainer'  => LSV07I_DB::get_all_trainer(),
        ] );
    }

    // ── Schwimmer Import ──────────────────────────────────────────────────────

    public static function schwimmer() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p         = $wpdb->prefix;
        $mann_id   = absint( $_POST['mannschaft_id'] ?? 0 ); // Optionale Standard-Mannschaft

        $csv_data = sanitize_textarea_field( wp_unslash( $_POST['csv_data'] ?? '' ) );
        if ( ! $csv_data ) wp_send_json_error( [ 'message' => 'Keine Daten übermittelt.' ] );

        $rows   = self::parse_csv( $csv_data );
        if ( count( $rows ) < 2 ) wp_send_json_error( [ 'message' => 'Datei ist leer oder hat keine Datenzeilen.' ] );

        $header   = array_map( 'mb_strtolower', array_map( 'trim', $rows[0] ) );
        $imported = 0;
        $errors   = [];

        for ( $i = 1; $i < count( $rows ); $i++ ) {
            $row = $rows[$i];
            if ( count( array_filter( $row ) ) === 0 ) continue;

            $col = self::map_row( $header, $row );

            $nachname = self::g( $col, [ 'nachname', 'last_name', 'name' ] );
            $vorname  = self::g( $col, [ 'vorname', 'first_name' ] );
            $geburt   = self::parse_date( self::g( $col, [ 'geburtsdatum', 'geburt', 'birth_date', 'birth' ] ) );

            if ( ! $nachname || ! $vorname || ! $geburt ) {
                $errors[] = "Zeile " . ($i+1) . ": Nachname, Vorname oder Geburtsdatum fehlt – übersprungen.";
                continue;
            }

            $jahr         = date( 'Y', strtotime( $geburt ) );
            $display_name = $nachname . ', ' . $vorname . ' (' . $jahr . ')';

            // Mannschaft(en) aus Spalte auflösen (Name → ID, Semikolon- oder Kommagetrennt)
            $mann_raw_col = self::g( $col, [ 'mannschaft', 'mannschaften', 'gruppe', 'gruppen', 'team' ] );
            $gruppe_ids   = [];
            if ( $mann_raw_col ) {
                $mann_names = array_filter( array_map( 'trim', preg_split( '/[;,]+/', $mann_raw_col ) ) );
                foreach ( $mann_names as $mname ) {
                    $gid = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$p}lsv07_gruppen WHERE name = %s LIMIT 1", $mname
                    ) );
                    if ( $gid ) $gruppe_ids[] = (int) $gid;
                }
            }
            // Fallback: Standard-Mannschaft aus Dropdown
            if ( empty( $gruppe_ids ) && $mann_id ) {
                $gruppe_ids[] = $mann_id;
            }
            $primary_team = ! empty( $gruppe_ids ) ? $gruppe_ids[0] : 0;

            $data = [
                'last_name'      => $nachname,
                'first_name'     => $vorname,
                'birth_date'     => $geburt,
                'email'          => self::g( $col, [ 'email' ] ),
                'dsv_id'         => self::g( $col, [ 'dsv-id','dsv_id','dsv id','dsvid' ] ),
                'attest_expires' => self::parse_date( self::g( $col, [ 'attest bis','attest_expires','attest' ] ) ) ?: null,
                'datenschutz'    => self::bool_val( self::g( $col, [ 'datenschutz' ] ) ),
                'display_name'   => $display_name,
                'team_id'        => $primary_team,
                'active'         => 1,
            ];

            // Duplikat prüfen
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}mv_swimmers WHERE last_name = %s AND first_name = %s AND birth_date = %s AND active = 1 LIMIT 1",
                $nachname, $vorname, $geburt
            ) );

            if ( $existing_id ) {
                unset( $data['active'] );
                $data['team_id'] = $primary_team ?: (int) $wpdb->get_var(
                    $wpdb->prepare( "SELECT team_id FROM {$p}mv_swimmers WHERE id = %d", $existing_id )
                );
                $wpdb->update( $p . 'mv_swimmers', $data, [ 'id' => $existing_id ] );
                $sw_id = $existing_id;
            } else {
                $wpdb->insert( $p . 'mv_swimmers', $data );
                $sw_id = $wpdb->insert_id;
            }

            // Bestzeiten-Mannschaft mitziehen
            if ( $sw_id ) {
                $wpdb->update(
                    $p . 'lsv07i_bestzeiten',
                    [ 'mannschaft_id' => (int) ( $data['team_id'] ?? 0 ) ],
                    [ 'swimmer_id'    => (int) $sw_id ],
                    [ '%d' ], [ '%d' ]
                );
            }

            // Mannschafts-Zuordnungen setzen (M:N)
            if ( $sw_id && ! empty( $gruppe_ids ) ) {
                // Bei neuem Schwimmer: setzen. Bei Update: nur ergänzen, nicht löschen
                foreach ( array_unique( $gruppe_ids ) as $gid ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$p}lsv07i_swimmer_gruppen (swimmer_id, gruppe_id) VALUES (%d, %d)",
                        $sw_id, $gid
                    ) );
                }
            } elseif ( $sw_id && $mann_id && ! $existing_id ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}lsv07i_swimmer_gruppen (swimmer_id, gruppe_id) VALUES (%d, %d)",
                    $sw_id, $mann_id
                ) );
            }

            // Kontaktpersonen importieren (bis 3)
            if ( $sw_id ) {
                $kontakte_import = [];
                for ( $k = 1; $k <= 3; $k++ ) {
                    $kt_name  = self::g( $col, [ "kontakt{$k} name", "kontakt {$k} name" ] );
                    $kt_tel   = self::g( $col, [ "kontakt{$k} telefon", "kontakt {$k} telefon" ] );
                    $kt_email = self::g( $col, [ "kontakt{$k} email", "kontakt {$k} email" ] );
                    if ( $kt_name || $kt_tel || $kt_email ) {
                        $kontakte_import[] = [ 'name' => $kt_name, 'telefon' => $kt_tel, 'email' => $kt_email ];
                    }
                }
                if ( ! empty( $kontakte_import ) ) {
                    LSV07I_DB::save_kontakte( $sw_id, $kontakte_import );
                }
            }

            $imported++;
        }

        wp_send_json_success( [
            'imported'  => $imported,
            'errors'    => $errors,
            'schwimmer' => LSV07I_DB::get_schwimmer(),
        ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function parse_csv( $text ) {
        $text  = str_replace( [ "\r\n", "\r" ], "\n", $text );
        $lines = explode( "\n", trim( $text ) );
        $rows  = [];
        // Trennzeichen erkennen: Semikolon oder Komma
        $delim = strpos( $lines[0], ';' ) !== false ? ';' : ',';
        foreach ( $lines as $line ) {
            if ( trim( $line ) === '' ) continue;
            $rows[] = str_getcsv( $line, $delim, '"', '\\' );
        }
        return $rows;
    }

    /**
     * Baut ein assoziatives Array aus Header-Indizes und Zeilenwerten.
     */
    private static function map_row( $header, $row ) {
        $out = [];
        foreach ( $header as $i => $key ) {
            $out[ $key ] = isset( $row[$i] ) ? trim( $row[$i] ) : '';
        }
        return $out;
    }

    /**
     * Holt einen Wert aus dem assoziativen Array anhand mehrerer möglicher Schlüssel.
     */
    private static function g( $col, $keys ) {
        foreach ( $keys as $k ) {
            $k = mb_strtolower( trim( $k ) );
            if ( isset( $col[$k] ) && $col[$k] !== '' ) return $col[$k];
        }
        return '';
    }

    /**
     * Konvertiert verschiedene Datumsformate nach Y-m-d.
     */
    private static function parse_date( $val ) {
        if ( ! $val ) return '';
        $val = trim( $val );
        // Bereits ISO
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ) return $val;
        // DD.MM.YYYY
        if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $val, $m ) ) {
            return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
        }
        // Excel-Seriennummer
        if ( is_numeric( $val ) && $val > 40000 ) {
            $ts = ( (int) $val - 25569 ) * 86400;
            return date( 'Y-m-d', $ts );
        }
        $ts = strtotime( $val );
        return $ts ? date( 'Y-m-d', $ts ) : '';
    }

    private static function bool_val( $val ) {
        if ( $val === '' || $val === null ) return 0;
        return in_array( strtolower( trim( $val ) ), [ '1', 'ja', 'yes', 'true', 'x', 'j' ], true ) ? 1 : 0;
    }
}
