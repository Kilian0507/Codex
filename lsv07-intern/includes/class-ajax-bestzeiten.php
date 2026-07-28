<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Bestzeiten {

    const STRECKEN = [
        '50F'   => '50m Freistil',
        '100F'  => '100m Freistil',
        '200F'  => '200m Freistil',
        '400F'  => '400m Freistil',
        '800F'  => '800m Freistil',
        '1500F' => '1500m Freistil',
        '50B'   => '50m Brust',
        '100B'  => '100m Brust',
        '200B'  => '200m Brust',
        '50R'   => '50m Rücken',
        '100R'  => '100m Rücken',
        '200R'  => '200m Rücken',
        '50S'   => '50m Schmetterling',
        '100S'  => '100m Schmetterling',
        '200S'  => '200m Schmetterling',
        '100L'  => '100m Lagen',
        '200L'  => '200m Lagen',
        '400L'  => '400m Lagen',
    ];

    public static function init() {
        add_action( 'wp_ajax_lsv07i_bz_get',             [ __CLASS__, 'get'             ] );
        add_action( 'wp_ajax_lsv07i_bz_loeschen',        [ __CLASS__, 'loeschen'        ] );
        add_action( 'wp_ajax_lsv07i_bz_loeschen_einzeln',[ __CLASS__, 'loeschen_einzeln'] );
        add_action( 'wp_ajax_lsv07i_bz_paste_preview',   [ __CLASS__, 'paste_preview'   ] );
        add_action( 'wp_ajax_lsv07i_bz_paste_commit',    [ __CLASS__, 'paste_commit'    ] );
        add_action( 'wp_ajax_lsv07i_bz_export',          [ __CLASS__, 'export'          ] );
        add_action( 'wp_ajax_lsv07i_bz_swimmers',        [ __CLASS__, 'swimmers'        ] );
    }

// ── Nach erfolgreichem Import: Mail an Trainer ─────────────────────────────
    private static function notify_trainer_bestzeiten( $mannschaft_id, $anzahl ) {
        if ( $anzahl < 1 ) return;
        global $wpdb;
        $mann = $wpdb->get_row( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}lsv07_gruppen WHERE id = %d LIMIT 1",
            $mannschaft_id
        ), ARRAY_A );
        if ( ! $mann ) return;
        LSV07I_Mail::bestzeiten_hochgeladen( $mannschaft_id, $mann['name'], $anzahl );
    }

    // ── Bestzeiten laden ──────────────────────────────────────────────────────
    public static function get() {
        LSV07I_Access::check( 'sw_bz_read' );
        global $wpdb;
        $p             = $wpdb->prefix;
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $strecke       = sanitize_text_field( $_POST['strecke'] ?? '' );

        // Berechtigungsfilter:
        //  - Admin/Schwimmwart: sehen alle Bestzeiten
        //  - Trainer: nur Bestzeiten von Schwimmern in ihren Mannschaften
        $sieht_alle = LSV07I_Access::is_admin_raw() || LSV07I_Access::is_schwimmwart();

        $where = '1=1';
        $args  = [];
        if ( $mannschaft_id ) { $where .= ' AND b.mannschaft_id = %d'; $args[] = $mannschaft_id; }
        if ( $strecke && isset( self::STRECKEN[$strecke] ) ) { $where .= ' AND b.strecke = %s'; $args[] = $strecke; }

        if ( ! $sieht_alle ) {
            // Trainer-Mannschaften ermitteln
            $tid = (int) LSV07I_Access::get_trainer_id();
            $trainer_manns = $tid ? LSV07I_DB::get_trainer_mannschaften( $tid ) : [];
            $mann_ids = array_filter( array_map( 'intval', (array) $trainer_manns ), fn( $i ) => $i > 0 );
            if ( empty( $mann_ids ) ) {
                wp_send_json_success( [ 'eintraege' => [], 'strecken' => self::STRECKEN ] );
                return;
            }
            $where .= ' AND b.mannschaft_id IN (' . implode( ',', $mann_ids ) . ')';
        }

        $sql = "SELECT b.swimmer_id, b.mannschaft_id, b.swimmer_name, b.strecke, b.zeit_raw,
                       b.zeit_sek, b.importiert_am, g.name AS mannschaft_name
                  FROM {$p}lsv07i_bestzeiten b
             LEFT JOIN {$p}lsv07_gruppen g ON g.id = b.mannschaft_id
                 WHERE $where ORDER BY b.strecke ASC, b.zeit_sek ASC";

        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        $grouped = [];
        foreach ( $rows as $r ) $grouped[ $r['strecke'] ][] = $r;

        $result = [];
        foreach ( $grouped as $eintraege ) {
            $platz = 1;
            foreach ( $eintraege as $e ) { $e['platz'] = $platz++; $result[] = $e; }
        }

        wp_send_json_success( [ 'eintraege' => $result, 'strecken' => self::STRECKEN ] );
    }

    // ── Export: breite Tabelle (Schwimmer × Strecken) als CSV oder Druck-HTML ──
    public static function export() {
        LSV07I_Access::check( 'sw_bz_read' );
        global $wpdb;
        $p             = $wpdb->prefix;
        $format        = sanitize_text_field( $_POST['format'] ?? 'csv' );      // csv | pdf
        $scope         = sanitize_text_field( $_POST['scope'] ?? 'alle' );      // alle | mannschaft | schwimmer
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $swimmer_val   = sanitize_text_field( $_POST['swimmer_id'] ?? '' );     // 'id:<n>' oder 'name:<name>'

        $sieht_alle = LSV07I_Access::is_admin_raw() || LSV07I_Access::is_schwimmwart();

        $where = '1=1';
        $args  = [];

        // Scope-Filter
        if ( $scope === 'mannschaft' && $mannschaft_id ) {
            $where .= ' AND b.mannschaft_id = %d'; $args[] = $mannschaft_id;
        } elseif ( $scope === 'schwimmer' && $swimmer_val !== '' ) {
            // Wert kann 'id:<n>' oder 'name:<name>' sein (oder eine reine Zahl als Fallback)
            if ( strpos( $swimmer_val, 'name:' ) === 0 ) {
                $where .= ' AND b.swimmer_name = %s'; $args[] = substr( $swimmer_val, 5 );
            } elseif ( strpos( $swimmer_val, 'id:' ) === 0 ) {
                $where .= ' AND b.swimmer_id = %d'; $args[] = (int) substr( $swimmer_val, 3 );
            } else {
                $where .= ' AND b.swimmer_id = %d'; $args[] = (int) $swimmer_val;
            }
        }

        // Berechtigungsfilter: Trainer nur eigene Mannschaften
        if ( ! $sieht_alle ) {
            $tid = (int) LSV07I_Access::get_trainer_id();
            // get_trainer_mannschaften() liefert eine FLACHE Liste von IDs
            // (z.B. [5, 12, 18]) via $wpdb->get_col() — kein Array von
            // Zeilen mit ['id']-Schlüssel. Der bisherige Zugriff auf
            // $m['id'] löste in PHP 8 einen fatalen TypeError aus, sobald
            // ein Trainer (nicht Admin/Schwimmwart) exportieren wollte —
            // das war die Ursache für "Es ist ein Fehler aufgetreten".
            $trainer_manns = $tid ? LSV07I_DB::get_trainer_mannschaften( $tid ) : [];
            $mann_ids = array_filter( array_map( 'intval', (array) $trainer_manns ), fn( $i ) => $i > 0 );
            if ( empty( $mann_ids ) ) {
                wp_send_json_error( [ 'message' => 'Keine Mannschaften zugeordnet.' ] );
                return;
            }
            $where .= ' AND b.mannschaft_id IN (' . implode( ',', $mann_ids ) . ')';
        }

        $sql = "SELECT b.swimmer_id, b.mannschaft_id, b.swimmer_name, b.strecke, b.zeit_raw,
                       b.zeit_sek, g.name AS mannschaft_name
                  FROM {$p}lsv07i_bestzeiten b
             LEFT JOIN {$p}lsv07_gruppen g ON g.id = b.mannschaft_id
                 WHERE $where
              ORDER BY g.name ASC, b.swimmer_name ASC";

        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        if ( empty( $rows ) ) {
            wp_send_json_error( [ 'message' => 'Keine Bestzeiten für die gewählte Auswahl gefunden.' ] );
            return;
        }

        // Nach Schwimmer gruppieren: pro Schwimmer eine Zeile, Strecken als Spalten.
        // Schlüssel ist die swimmer_id, wenn vorhanden — sonst der Name. So werden
        // Schwimmer mit swimmer_id=0 (Altdaten) NICHT fälschlich verschmolzen.
        $swimmers = [];   // key => [ name, mannschaft, zeiten => [strecke => zeit_raw] ]
        foreach ( $rows as $r ) {
            $sid = (int) $r['swimmer_id'];
            $key = $sid > 0 ? 'id:' . $sid : 'name:' . $r['swimmer_name'];
            if ( ! isset( $swimmers[ $key ] ) ) {
                $swimmers[ $key ] = [
                    'name'       => $r['swimmer_name'],
                    'mannschaft' => $r['mannschaft_name'] ?: '–',
                    'zeiten'     => [],
                ];
            }
            // Wenn dieselbe Strecke mehrfach vorkommt, die schnellere Zeit behalten
            if ( isset( $swimmers[ $key ]['zeiten'][ $r['strecke'] ] ) ) {
                $alt = $swimmers[ $key ]['zeiten_sek'][ $r['strecke'] ] ?? PHP_FLOAT_MAX;
                if ( (float) $r['zeit_sek'] < $alt ) {
                    $swimmers[ $key ]['zeiten'][ $r['strecke'] ]     = $r['zeit_raw'];
                    $swimmers[ $key ]['zeiten_sek'][ $r['strecke'] ] = (float) $r['zeit_sek'];
                }
            } else {
                $swimmers[ $key ]['zeiten'][ $r['strecke'] ]     = $r['zeit_raw'];
                $swimmers[ $key ]['zeiten_sek'][ $r['strecke'] ] = (float) $r['zeit_sek'];
            }
        }

        // Schwimmer alphabetisch nach Name sortieren (Schlüssel sind gemischt id:/name:)
        uasort( $swimmers, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

        // ALLE Strecken als Spalten ausgeben — auch solche, für die (noch)
        // keine Zeit erfasst ist. Zuvor wurden leere Strecken herausgefiltert,
        // wodurch auf dem PDF je nach Auswahl unterschiedlich viele Spalten
        // erschienen und Strecken fehlten. Ein fester, vollständiger
        // Spaltensatz macht die Liste außerdem vergleichbar (gleiche Spalte =
        // gleiche Strecke, egal welcher Export).
        $alle_strecken = self::STRECKEN;

        // Titel je nach Scope
        $titel = 'Bestzeiten – Alle Schwimmer';
        if ( $scope === 'mannschaft' && $mannschaft_id ) {
            $mn = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}lsv07_gruppen WHERE id = %d", $mannschaft_id ) );
            $titel = 'Bestzeiten – ' . ( $mn ?: 'Mannschaft' );
        } elseif ( $scope === 'schwimmer' && $swimmer_val !== '' ) {
            $first = reset( $swimmers );
            $titel = 'Bestzeiten – ' . ( $first['name'] ?? 'Schwimmer' );
        }

        if ( $format === 'pdf' ) {
            $html = self::build_export_html( $titel, $alle_strecken, $swimmers );
            wp_send_json_success( [ 'html' => $html, 'titel' => $titel ] );
        } else {
            $csv = self::build_export_csv( $alle_strecken, $swimmers );
            $dateiname = self::slugify( $titel ) . '.csv';
            wp_send_json_success( [ 'csv' => $csv, 'dateiname' => $dateiname,
                                    'anzahl' => count( $swimmers ) ] );
        }
    }

    // ── Liste der Schwimmer, die Bestzeiten haben (für Export-Dropdown) ────────
    public static function swimmers() {
        LSV07I_Access::check( 'sw_bz_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $sieht_alle = LSV07I_Access::is_admin_raw() || LSV07I_Access::is_schwimmwart();
        $where = "1=1";
        if ( ! $sieht_alle ) {
            $tid = (int) LSV07I_Access::get_trainer_id();
            $trainer_manns = $tid ? LSV07I_DB::get_trainer_mannschaften( $tid ) : [];
            $mann_ids = array_filter( array_map( 'intval', (array) $trainer_manns ), fn( $i ) => $i > 0 );
            if ( empty( $mann_ids ) ) { wp_send_json_success( [] ); return; }
            $where .= ' AND b.mannschaft_id IN (' . implode( ',', $mann_ids ) . ')';
        }

        $rows = $wpdb->get_results(
            "SELECT DISTINCT b.swimmer_id, b.swimmer_name, g.name AS mannschaft_name
               FROM {$p}lsv07i_bestzeiten b
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = b.mannschaft_id
              WHERE $where
           ORDER BY b.swimmer_name ASC", ARRAY_A );

        // Eindeutiger Wert: 'id:<n>' wenn swimmer_id vorhanden, sonst 'name:<name>'.
        // Nach diesem Wert dedupliziert (mehrere Strecken pro Schwimmer).
        $seen = [];
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $sid = (int) $r['swimmer_id'];
            $val = $sid > 0 ? 'id:' . $sid : 'name:' . $r['swimmer_name'];
            if ( isset( $seen[ $val ] ) ) continue;
            $seen[ $val ] = true;
            $out[] = [
                'id'   => $val,
                'name' => $r['swimmer_name'] . ( $r['mannschaft_name'] ? ' (' . $r['mannschaft_name'] . ')' : '' ),
            ];
        }

        wp_send_json_success( $out );
    }

    private static function slugify( $text ) {
        $text = strtolower( $text );
        $text = strtr( $text, [ 'ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss' ] );
        $text = preg_replace( '/[^a-z0-9]+/', '_', $text );
        return trim( $text, '_' );
    }

    private static function build_export_csv( $strecken, $swimmers ) {
        $out = fopen( 'php://temp', 'r+' );
        fprintf( $out, chr(0xEF).chr(0xBB).chr(0xBF) ); // UTF-8 BOM für Excel
        // Kopfzeile
        $header = array_merge( [ 'Schwimmer', 'Mannschaft' ], array_values( $strecken ) );
        fputcsv( $out, $header, ';', '"', '\\' );
        foreach ( $swimmers as $sw ) {
            $zeile = [ $sw['name'], $sw['mannschaft'] ];
            foreach ( array_keys( $strecken ) as $code ) {
                $zeile[] = $sw['zeiten'][ $code ] ?? '';
            }
            fputcsv( $out, $zeile, ';', '"', '\\' );
        }
        rewind( $out );
        $csv = stream_get_contents( $out );
        fclose( $out );
        return $csv;
    }

    /**
     * Gruppiert die Streckencodes nach Lage (Suffix des Codes, z.B. '50F' → F).
     * Damit lässt sich eine zweizeilige, kompakte Kopfzeile bauen
     * ("Freistil" über "50 · 100 · 200 …") statt 18× "50m Freistil" —
     * nur so passen alle Strecken nebeneinander auf ein Querformat-Blatt.
     */
    private static function strecken_gruppen() {
        $lagen = [
            'F' => 'Freistil',
            'B' => 'Brust',
            'R' => 'Rücken',
            'S' => 'Schmetterling',
            'L' => 'Lagen',
        ];
        $out = [];
        foreach ( $lagen as $suffix => $name ) {
            $out[ $suffix ] = [ 'name' => $name, 'strecken' => [] ];
        }
        foreach ( array_keys( self::STRECKEN ) as $code ) {
            $suffix = substr( $code, -1 );
            $meter  = substr( $code, 0, -1 );
            if ( isset( $out[ $suffix ] ) ) {
                $out[ $suffix ]['strecken'][ $code ] = $meter;
            }
        }
        // Falls STRECKEN künftig erweitert/gekürzt wird: leere Gruppen weglassen
        return array_filter( $out, fn( $g ) => ! empty( $g['strecken'] ) );
    }

    private static function build_export_html( $titel, $strecken, $swimmers ) {
        $esc     = fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
        $datum   = date_i18n( 'd.m.Y' );
        $gruppen = self::strecken_gruppen();

        // Reihenfolge der Spalten einmal festlegen, damit Kopf und Zellen
        // garantiert synchron laufen.
        $codes = [];
        foreach ( $gruppen as $g ) {
            foreach ( array_keys( $g['strecken'] ) as $code ) $codes[] = $code;
        }

        // Zweizeilige Kopfzeile: Lage (zusammengefasst) über Streckenlänge
        $h  = '<thead><tr>';
        $h .= '<th class="nm" rowspan="2">Schwimmer</th>';
        $h .= '<th class="mn" rowspan="2">Mannschaft</th>';
        foreach ( $gruppen as $g ) {
            $h .= '<th class="grp" colspan="' . count( $g['strecken'] ) . '">' . $esc( $g['name'] ) . '</th>';
        }
        $h .= '</tr><tr>';
        foreach ( $gruppen as $g ) {
            foreach ( $g['strecken'] as $meter ) {
                $h .= '<th class="dist">' . $esc( $meter ) . '</th>';
            }
        }
        $h .= '</tr></thead><tbody>';

        foreach ( $swimmers as $sw ) {
            $h .= '<tr><td class="nm">' . $esc( $sw['name'] ) . '</td>'
                . '<td class="mn">' . $esc( $sw['mannschaft'] ) . '</td>';
            foreach ( $codes as $code ) {
                $z = $sw['zeiten'][ $code ] ?? '';
                $h .= '<td class="zt">' . $esc( $z ) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody>';

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            . '<title>' . $esc( $titel ) . '</title><style>'
            . '@page{size:A4 landscape;margin:8mm}'
            . 'body{font-family:Arial,Helvetica,sans-serif;margin:16px;color:#1a202c}'
            . 'h1{font-size:16px;margin:0 0 2px}'
            . '.meta{font-size:11px;color:#555;margin-bottom:10px}'
            /* table-layout:fixed => Spalten teilen sich die Breite gleichmäßig
               auf, statt dass einzelne lange Namen die Tabelle sprengen. */
            . 'table{border-collapse:collapse;width:100%;table-layout:fixed}'
            . 'th,td{border:1px solid #b6c2d2;padding:2px 3px;overflow:hidden}'
            . 'thead th{background:#1e3a5f;color:#fff;text-align:center;font-weight:600}'
            . 'th.grp{font-size:9.5px;border-bottom:1px solid #4a6d99}'
            . 'th.dist{font-size:9px;font-weight:500}'
            . 'th.nm,td.nm{text-align:left;width:32mm}'
            . 'th.mn,td.mn{text-align:left;width:22mm}'
            . 'th.nm,th.mn{font-size:9.5px}'
            . 'td{font-size:8.5px}'
            /* Lange Namen umbrechen statt abschneiden — kein Informationsverlust */
            . 'td.nm{font-weight:600;line-height:1.15;word-break:break-word}'
            . 'td.mn{line-height:1.15;word-break:break-word}'
            . 'td.zt{font-family:"Courier New",monospace;text-align:center;font-size:8px;letter-spacing:-.2px;white-space:nowrap}'
            . 'tbody tr:nth-child(even) td{background:#f1f5f9}'
            /* Kopfzeile auf jeder Druckseite wiederholen */
            . 'thead{display:table-header-group}'
            . 'tr{page-break-inside:avoid}'
            . '@media print{body{margin:0}.noprint{display:none}}'
            . '.noprint{margin-bottom:14px}'
            . '.btn{background:#3584e4;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:13px;cursor:pointer}'
            . '.hint{font-size:11px;color:#555;margin-top:6px}'
            . '</style></head><body>'
            . '<div class="noprint"><button class="btn" onclick="window.print()">Als PDF drucken / speichern</button>'
            . '<div class="hint">Tipp: Im Druckdialog <strong>Querformat</strong> wählen (ist voreingestellt), damit alle Strecken auf das Blatt passen.</div></div>'
            . '<h1>' . $esc( $titel ) . '</h1>'
            . '<div class="meta">Stand: ' . $esc( $datum ) . ' · ' . count( $swimmers ) . ' Schwimmer · alle ' . count( $codes ) . ' Strecken</div>'
            . '<table>' . $h . '</table>'
            . '<script>window.onload=function(){setTimeout(function(){window.print();},400);};</script>'
            . '</body></html>';
    }

    // ── Löschen ───────────────────────────────────────────────────────────────
    public static function loeschen() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage. Bitte Seite neu laden.' ], 403 );
        }
        if ( ! LSV07I_Access::is_admin_raw() && ! LSV07I_Access::is_schwimmwart() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ] );
        }
        global $wpdb;
        $alle = ! empty( $_POST['alle'] );
        $mid  = absint( $_POST['mannschaft_id'] ?? 0 );

        if ( $alle ) {
            // Komplett leeren — über alle Mannschaften
            $deleted = $wpdb->query( "DELETE FROM {$wpdb->prefix}lsv07i_bestzeiten" );
        } else {
            if ( ! $mid ) wp_send_json_error( [ 'message' => 'Mannschaft fehlt.' ] );
            $deleted = $wpdb->delete(
                $wpdb->prefix . 'lsv07i_bestzeiten',
                [ 'mannschaft_id' => $mid ],
                [ '%d' ]
            );
        }
        if ( $deleted === false ) {
            wp_send_json_error( [ 'message' => 'Datenbankfehler beim Löschen.' ] );
        }
        wp_send_json_success( [ 'deleted' => (int) $deleted ] );
    }

    // ── Einzelne Bestzeit löschen ─────────────────────────────────────────────
    public static function loeschen_einzeln() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage. Bitte Seite neu laden.' ], 403 );
        }
        if ( ! LSV07I_Access::is_admin_raw() && ! LSV07I_Access::is_schwimmwart() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ] );
        }
        global $wpdb;
        $mid     = absint( $_POST['mannschaft_id'] ?? 0 );
        $name    = sanitize_text_field( $_POST['swimmer_name'] ?? '' );
        $strecke = sanitize_text_field( $_POST['strecke'] ?? '' );
        if ( ! $mid || $name === '' || $strecke === '' ) {
            wp_send_json_error( [ 'message' => 'Unvollständige Daten.' ] );
        }
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'lsv07i_bestzeiten',
            [ 'mannschaft_id' => $mid, 'swimmer_name' => $name, 'strecke' => $strecke ],
            [ '%d', '%s', '%s' ]
        );
        if ( $deleted === false ) {
            wp_send_json_error( [ 'message' => 'Datenbankfehler beim Löschen.' ] );
        }
        wp_send_json_success( [ 'deleted' => (int) $deleted ] );
    }

    // ── XLSX → 2D-Array ───────────────────────────────────────────────────────
    private static function xlsx_to_array( $file ) {
        $zip = new ZipArchive();
        if ( $zip->open( $file ) !== true ) {
            // Kopie mit .xlsx-Endung versuchen
            $tmp2 = tempnam( sys_get_temp_dir(), 'lsv07' ) . '.xlsx';
            copy( $file, $tmp2 );
            $ok = $zip->open( $tmp2 );
            @unlink( $tmp2 );
            if ( $ok !== true ) return new WP_Error( 'zip', 'Kein gültiges XLSX.' );
        }

        // Shared Strings
        $shared = [];
        $ss = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $ss ) {
            $dom = new DOMDocument();
            @$dom->loadXML( $ss );
            foreach ( $dom->getElementsByTagName( 'si' ) as $si ) {
                $text = '';
                foreach ( $si->getElementsByTagName( 't' ) as $t ) $text .= $t->textContent;
                $shared[] = $text;
            }
        }

        // Sheet
        $sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        $zip->close();
        if ( ! $sheet ) return new WP_Error( 'sheet', 'Kein Sheet1.' );

        $dom2 = new DOMDocument();
        @$dom2->loadXML( $sheet );

        $rows = [];
        foreach ( $dom2->getElementsByTagName( 'row' ) as $row_el ) {
            $r = (int) $row_el->getAttribute( 'r' ) - 1;
            $row = [];
            foreach ( $row_el->getElementsByTagName( 'c' ) as $c ) {
                preg_match( '/^([A-Z]+)/', $c->getAttribute( 'r' ), $m );
                $col = 0;
                foreach ( str_split( $m[1] ) as $ch ) $col = $col * 26 + ord($ch) - 64;
                $col--;
                $t    = $c->getAttribute( 't' );
                $vn   = $c->getElementsByTagName( 'v' )->item(0);
                $v    = $vn ? $vn->textContent : null;
                if ( $v === null || $v === '' ) continue;
                $row[$col] = ($t === 's') ? ($shared[(int)$v] ?? '') : ( (float)$v === 0.0 ? '' : $v );
            }
            if ( ! empty( $row ) ) {
                $max = max( array_keys( $row ) );
                for ( $j = 0; $j <= $max; $j++ ) if ( ! isset( $row[$j] ) ) $row[$j] = null;
                ksort( $row );
                $rows[$r] = array_values( $row );
            } else {
                $rows[$r] = [];
            }
        }
        ksort( $rows );
        return array_values( $rows );
    }

    // ── Zeit → Sekunden ───────────────────────────────────────────────────────
    private static function zeit_zu_sekunden( $raw ) {
        $s = str_replace( ',', '.', trim( (string) $raw ) );
        if ( preg_match( '/^(\d+):(\d{1,2})\.(\d+)$/', $s, $m ) )
            return (float)$m[1]*60 + (float)$m[2] + (float)('0.'.$m[3]);
        if ( preg_match( '/^(\d+):(\d{1,2})$/', $s, $m ) )
            return (float)$m[1]*60 + (float)$m[2];
        if ( preg_match( '/^(\d+)\.(\d+)$/', $s, $m ) )
            return (float)$m[1] + (float)('0.'.$m[2]);
        return 0.0;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  PASTE-IMPORT (Copy/Paste aus Excel)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Parst aus Excel kopierten Text und liefert eine Vorschau.
     *
     * Erwartetes Format:
     *  Header-Zeile mit  "Name\t50F\t100F\t..."
     *  Datenzeilen mit   "Vorname Nachname (JJJJ)\t0:34,63\t..." oder
     *                    "Nachname, Vorname (JJJJ)\t0:34,63\t..."
     *
     * Liefert pro Zeile:
     *   - geparsten Namen + Geburtsjahr
     *   - gefundenen Schwimmer (mit ID) oder null
     *   - Liste der erkannten Zeiten (Strecke + Roh + Sekunden)
     *   - Status: ok | unbekannt | invalid
     */
    public static function paste_preview() {
        $nonce = $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'lsv07i_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Sitzung abgelaufen. Bitte Seite neu laden.' ] );
        }
        if ( ! self::darf_importieren() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung zum Import von Bestzeiten.' ] );
        }

        $text = (string) wp_unslash( $_POST['text'] ?? '' );
        if ( trim( $text ) === '' ) {
            wp_send_json_error( [ 'message' => 'Kein Text empfangen.' ] );
        }

        $parsed = self::parse_paste( $text );
        if ( is_wp_error( $parsed ) ) {
            wp_send_json_error( [ 'message' => $parsed->get_error_message() ] );
        }

        // Schwimmer mannschaftsübergreifend laden (samt aktueller Mannschaft)
        global $wpdb;
        $p = $wpdb->prefix;
        $schwimmer = $wpdb->get_results(
            "SELECT s.id, s.first_name, s.last_name, s.birth_date,
                    s.team_id AS mannschaft_id,
                    g.name   AS mannschaft_name
               FROM {$p}mv_swimmers s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
              ORDER BY s.last_name, s.first_name", ARRAY_A );

        // Match jede geparste Zeile
        $rows_out = [];
        foreach ( $parsed['rows'] as $r ) {
            $match = self::match_schwimmer( $r['name_raw'], $r['jahrgang'], $schwimmer );
            if ( ! $match ) {
                // Kein Treffer
                $r['matched']  = null;
                $r['status']   = 'unbekannt';
                $r['hinweis']  = '';
            } elseif ( ( $match['confidence'] ?? '' ) === 'ambiguous' ) {
                // Mehrere passende Schwimmer — Trainer muss zuordnen
                $r['matched']    = null;
                $r['status']     = 'ambiguous';
                $r['kandidaten'] = $match['kandidaten'];
                $r['hinweis']    = sprintf(
                    'Mehrere passende Schwimmer (%d) — bitte zuordnen.',
                    count( $match['kandidaten'] )
                );
            } elseif ( ( $match['confidence'] ?? '' ) === 'fuzzy_doppelname' ) {
                // Match über Doppelname-Toleranz — vorschlagen, aber bestätigen lassen
                $r['matched'] = $match;
                $r['status']  = 'fuzzy';
                $r['hinweis'] = $match['hinweis'] ?: 'Doppelname-Übereinstimmung — bitte prüfen.';
            } else {
                // Exakter Treffer
                $r['matched'] = $match;
                $r['status']  = 'ok';
                $r['hinweis'] = '';
            }
            // Alle verfügbaren Schwimmer mitgeben für manuelle Zuordnung
            // (wir liefern sie nur einmal, nicht pro Zeile)
            $rows_out[] = $r;
        }

        wp_send_json_success( [
            'strecken'  => $parsed['strecken'],
            'rows'      => $rows_out,
            // Für die manuelle Zuordnung im Frontend: Liste aller Schwimmer
            // (mannschaftsübergreifend, mit Mannschaft als Zusatzinfo)
            'schwimmer' => array_map( function( $sw ) {
                return [
                    'id'              => (int) $sw['id'],
                    'first_name'      => $sw['first_name'],
                    'last_name'       => $sw['last_name'],
                    'jahrgang'        => $sw['birth_date'] ? (int) substr( $sw['birth_date'], 0, 4 ) : 0,
                    'mannschaft_id'   => (int) ( $sw['mannschaft_id'] ?? 0 ),
                    'mannschaft_name' => $sw['mannschaft_name'] ?? '',
                ];
            }, $schwimmer ),
        ] );
    }

    /**
     * Speichert die zuvor in der Vorschau bestätigten Daten.
     * Erwartet ein JSON-Array mit Einträgen:
     *   { schwimmer_id, name, zeiten: { 50F: '0:34,63', 100F: '...', ... } }
     *
     * Vorgehensweise:
     *   - Pro Schwimmer werden alle alten Bestzeiten dieser Mannschaft gelöscht
     *   - Danach werden die neuen Zeiten geschrieben
     *   - Schwimmer ohne ID werden übersprungen
     */
    public static function paste_commit() {
        $nonce = $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'lsv07i_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Sitzung abgelaufen. Bitte Seite neu laden.' ] );
        }
        if ( ! self::darf_importieren() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung zum Import von Bestzeiten.' ] );
        }

        $rows_json = (string) wp_unslash( $_POST['rows_json'] ?? '' );
        $rows = json_decode( $rows_json, true );
        if ( ! is_array( $rows ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Daten.' ] );
        }

        global $wpdb;
        $p       = $wpdb->prefix;
        $user_id = get_current_user_id();
        $imported        = 0;
        $skipped_swimmer = 0;
        $skipped_zeiten  = 0;
        $ersetzt         = 0;   // vorhandene Zeiten, die überschrieben wurden
        $schwimmer_ok    = 0;   // Schwimmer, für die mindestens eine Zeit ankam

        foreach ( $rows as $r ) {
            $sid  = absint( $r['schwimmer_id'] ?? 0 );
            $name = sanitize_text_field( $r['name'] ?? '' );
            if ( ! $sid || $name === '' ) {
                $skipped_swimmer++;
                continue;
            }

            // Mannschaft des Schwimmers automatisch aus mv_swimmers ziehen
            // (das ist die "aktuelle" Mannschaft — fester swimmer_id bleibt, mannschaft_id folgt)
            $mannschaft_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT team_id FROM {$p}mv_swimmers WHERE id = %d", $sid
            ) );
            if ( ! $mannschaft_id ) {
                // Schwimmer ohne Mannschaft → trotzdem importieren, mannschaft_id=0
                $mannschaft_id = 0;
            }

            // Alte Zeiten dieses Schwimmers komplett löschen (per swimmer_id,
            // NICHT per Name). Die Anzahl merken, damit die Rückmeldung sagen
            // kann, wie viele bestehende Zeiten tatsächlich ersetzt wurden.
            $vorher = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}lsv07i_bestzeiten WHERE swimmer_id = %d", $sid
            ) );
            if ( $vorher > 0 ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$p}lsv07i_bestzeiten WHERE swimmer_id = %d", $sid
                ) );
                $ersetzt += $vorher;
            }

            $zeiten = (array) ( $r['zeiten'] ?? [] );
            $dieser_schwimmer = 0;
            foreach ( $zeiten as $strecke => $raw ) {
                if ( ! isset( self::STRECKEN[ $strecke ] ) ) continue;
                $raw = trim( (string) $raw );
                if ( $raw === '' || $raw === '0' ) { $skipped_zeiten++; continue; }
                $sek = self::zeit_zu_sekunden( $raw );
                if ( $sek <= 0 ) { $skipped_zeiten++; continue; }

                $wpdb->insert( $p . 'lsv07i_bestzeiten', [
                    'swimmer_id'     => $sid,
                    'mannschaft_id'  => $mannschaft_id,
                    'swimmer_name'   => $name,
                    'strecke'        => $strecke,
                    'zeit_raw'       => $raw,
                    'zeit_sek'       => $sek,
                    'importiert_von' => $user_id,
                ], [ '%d', '%d', '%s', '%s', '%s', '%f', '%d' ] );
                $imported++;
                $dieser_schwimmer++;
            }
            if ( $dieser_schwimmer > 0 ) $schwimmer_ok++;
        }

        // Rückmeldung in Klartext — sie erscheint im Erfolgs-Kasten der Vorschau
        $teile = [];
        $teile[] = $imported === 1 ? '1 Zeit gespeichert' : "$imported Zeiten gespeichert";
        $teile[] = $schwimmer_ok === 1 ? 'für 1 Schwimmer' : "für $schwimmer_ok Schwimmer";
        $satz = implode( ' ', $teile ) . '.';
        if ( $ersetzt > 0 ) {
            $satz .= $ersetzt === 1
                ? ' 1 bestehende Zeit wurde dabei ersetzt.'
                : " $ersetzt bestehende Zeiten wurden dabei ersetzt.";
        }
        if ( $skipped_swimmer > 0 ) {
            $satz .= $skipped_swimmer === 1
                ? ' 1 Zeile ohne Zuordnung wurde übersprungen.'
                : " $skipped_swimmer Zeilen ohne Zuordnung wurden übersprungen.";
        }
        if ( $skipped_zeiten > 0 ) {
            $satz .= $skipped_zeiten === 1
                ? ' 1 Zeitangabe war leer oder unlesbar.'
                : " $skipped_zeiten Zeitangaben waren leer oder unlesbar.";
        }

        wp_send_json_success( [
            'imported'        => $imported,
            'schwimmer'       => $schwimmer_ok,
            'ersetzt'         => $ersetzt,
            'skipped_swimmer' => $skipped_swimmer,
            'skipped_zeiten'  => $skipped_zeiten,
            'message'         => $satz,
        ] );
    }

    /**
     * Parst Paste-Text (Tab-getrennt aus Excel) in strukturierte Daten.
     */
    private static function parse_paste( $text ) {
        // Newlines normalisieren
        $text  = str_replace( [ "\r\n", "\r" ], "\n", $text );
        $lines = explode( "\n", $text );

        // Header-Zeile finden: erste Zeile, die mit "Name" beginnt
        $header_idx = null;
        $header     = null;
        foreach ( $lines as $i => $line ) {
            $cols = explode( "\t", $line );
            if ( isset( $cols[0] ) && strtolower( trim( $cols[0] ) ) === 'name' ) {
                $header_idx = $i;
                $header     = $cols;
                break;
            }
        }
        if ( $header_idx === null ) {
            return new WP_Error( 'no_header',
                'Header-Zeile mit "Name" als erster Spalte nicht gefunden. ' .
                'Bitte ganze Tabelle inklusive Überschrift kopieren.'
            );
        }

        // Strecken-Spalten erkennen
        $strecken_map = [];
        foreach ( $header as $ci => $h ) {
            $h = trim( (string) $h );
            if ( isset( self::STRECKEN[ $h ] ) ) {
                $strecken_map[ $ci ] = $h;
            }
        }
        if ( empty( $strecken_map ) ) {
            return new WP_Error( 'no_strecken',
                'Keine Strecken-Spalten erkannt (50F, 100F, 50B, ...). ' .
                'Header-Zeile prüfen.'
            );
        }

        // Datenzeilen
        $rows = [];
        for ( $i = $header_idx + 1; $i < count( $lines ); $i++ ) {
            $line = $lines[ $i ];
            if ( trim( $line ) === '' ) continue;
            $cols = explode( "\t", $line );
            $name_raw = trim( (string) ( $cols[0] ?? '' ) );
            if ( $name_raw === '' ) continue;

            // Geburtsjahr aus dem Namen extrahieren: "Vorname Nachname (2005)" oder "Nachname, Vorname (2005)"
            $jahrgang = null;
            $name_clean = $name_raw;
            if ( preg_match( '/^(.+?)\s*\((\d{4})\)\s*$/', $name_raw, $m ) ) {
                $name_clean = trim( $m[1] );
                $jahrgang   = (int) $m[2];
            }

            // Zeiten einsammeln
            $zeiten = [];
            foreach ( $strecken_map as $ci => $strecke ) {
                $raw = isset( $cols[ $ci ] ) ? trim( (string) $cols[ $ci ] ) : '';
                if ( $raw === '' || $raw === '0' ) continue;
                $sek = self::zeit_zu_sekunden( $raw );
                $zeiten[] = [
                    'strecke'  => $strecke,
                    'raw'      => $raw,
                    'sek'      => $sek,
                    'gueltig'  => $sek > 0,
                ];
            }

            $rows[] = [
                'name_raw'  => $name_raw,
                'name'      => $name_clean,
                'jahrgang'  => $jahrgang,
                'zeiten'    => $zeiten,
            ];
        }

        return [
            'strecken' => array_values( $strecken_map ),
            'rows'     => $rows,
        ];
    }

    /**
     * Versucht einen Schwimmer in der Mannschafts-Liste zu finden.
     * Match-Strategie:
     *   1. Name (egal ob "Vorname Nachname" oder "Nachname, Vorname")
     *   2. Geburtsjahr aus birth_date
     * Liefert Schwimmer-Array mit id, first_name, last_name, jahrgang oder null.
     */
    /**
     * Findet den Schwimmer zu einem CSV-Namen.
     * Rückgabe: ['id'=>…, 'first_name'=>…, 'last_name'=>…, 'jahrgang'=>…,
     *            'confidence'=>'exact'|'fuzzy_doppelname',
     *            'hinweis'=>'… optionale Erläuterung …']
     * oder ['confidence'=>'ambiguous', 'kandidaten'=>[…]]  bei Mehrdeutigkeit
     * oder null bei keinem Treffer.
     *
     * Toleranzen:
     *  - Komma-Format "Nachname, Vorname" und "Vorname Nachname"
     *  - Reihenfolge Vor-/Nachname egal (Heuristik)
     *  - Jahrgang in Klammern "(2010)" wird verglichen, wenn beide Seiten ihn haben
     *  - Doppelnamen: "Anna-Maria" matcht "Anna" (und umgekehrt), wenn ein Teil
     *    eines Doppelnamens (per Bindestrich oder Leerzeichen geteilt) dem
     *    Vergleichswert entspricht. Match-Stufe: fuzzy_doppelname.
     */
    /**
     * Ordnet einen Namen aus der Zwischenablage einem Schwimmer zu.
     *
     * Gesucht wird in mehreren Stufen, von streng nach tolerant. Sobald eine
     * Stufe Treffer liefert, entscheidet sie — so gewinnt eine exakte
     * Übereinstimmung immer gegen einen unscharfen Treffer.
     *
     *   1. exakt                Name stimmt zeichengenau (Groß/klein egal)
     *   2. Schreibweise         ü/ue/u, ä/ae/a, ö/oe/o, ß/ss werden gleichgesetzt
     *   3. Doppelname           „Anna" trifft „Anna-Maria", „Karl" trifft „Karl Heinz"
     *   4. nur ein Namensteil   nur Nachname (oder nur Vorname) angegeben
     *   5. Tippfehler           ein bis zwei abweichende Zeichen
     *
     * Stufe 1 und 2 gelten als sicher, ab Stufe 3 wird zur Bestätigung
     * vorgelegt. Mehrere Treffer innerhalb einer Stufe ⇒ „ambiguous".
     */
    public static function match_schwimmer( $name_raw, $jahrgang, $schwimmer_liste ) {
        // Name aus Eingabe normalisieren (führende/mehrfache Leerzeichen weg)
        $name_clean = preg_replace( '/\s+/', ' ', trim( (string) $name_raw ) );
        // Jahrgang in Klammern am Ende übernehmen, wenn keiner mitgegeben wurde
        if ( preg_match( '/^(.+?)\s*\((\d{4})\)\s*$/', $name_clean, $m ) ) {
            $name_clean = trim( $m[1] );
            if ( ! $jahrgang ) $jahrgang = (int) $m[2];
        }
        if ( $name_clean === '' ) return null;

        // Vor-/Nachname trennen
        $vorname = $nachname = '';
        $nur_ein_teil = false;
        if ( strpos( $name_clean, ',' ) !== false ) {
            list( $nachname, $vorname ) = array_map( 'trim', explode( ',', $name_clean, 2 ) );
            if ( $vorname === '' ) $nur_ein_teil = true;
        } else {
            $parts = preg_split( '/\s+/', $name_clean );
            if ( count( $parts ) >= 2 ) {
                $nachname = array_pop( $parts );
                $vorname  = implode( ' ', $parts );
            } else {
                $nachname     = $name_clean;
                $nur_ein_teil = true;
            }
        }

        $vorname_n  = self::norm( $vorname );
        $nachname_n = self::norm( $nachname );
        $vorname_h  = self::norm_hart( $vorname );
        $nachname_h = self::norm_hart( $nachname );

        // Treffer je Stufe sammeln
        $stufen = [ 'exakt' => [], 'schreibweise' => [], 'doppelname' => [], 'einzelteil' => [], 'tippfehler' => [] ];

        foreach ( $schwimmer_liste as $sw ) {
            $sw_jahr = ! empty( $sw['birth_date'] ) ? (int) substr( $sw['birth_date'], 0, 4 ) : 0;
            // Jahrgang muss passen, wenn beide Seiten ihn haben
            if ( $jahrgang && $sw_jahr && $jahrgang !== $sw_jahr ) continue;

            $sw_v = self::norm( $sw['first_name'] );
            $sw_n = self::norm( $sw['last_name'] );
            $sw_vh = self::norm_hart( $sw['first_name'] );
            $sw_nh = self::norm_hart( $sw['last_name'] );

            // ── 1) Exakt (auch mit vertauschter Reihenfolge) ──────────────
            if ( ! $nur_ein_teil
              && ( ( $sw_v === $vorname_n && $sw_n === $nachname_n )
                || ( $sw_v === $nachname_n && $sw_n === $vorname_n ) ) ) {
                $stufen['exakt'][] = self::result( $sw, $sw_jahr, 'exact', '' );
                continue;
            }

            // ── 2) Abweichende Schreibweise (Umlaute, ß) ──────────────────
            if ( ! $nur_ein_teil
              && ( ( $sw_vh === $vorname_h && $sw_nh === $nachname_h )
                || ( $sw_vh === $nachname_h && $sw_nh === $vorname_h ) ) ) {
                $sw_full = trim( $sw['first_name'] . ' ' . $sw['last_name'] );
                $stufen['schreibweise'][] = self::result( $sw, $sw_jahr, 'exact',
                    "Abweichende Schreibweise (Stammdaten: „{$sw_full}“)" );
                continue;
            }

            // ── 3) Doppelname ─────────────────────────────────────────────
            if ( ! $nur_ein_teil ) {
                $v_match      = self::name_teilmatch( $sw_vh, $vorname_h );
                $n_match      = self::name_teilmatch( $sw_nh, $nachname_h );
                $v_match_swap = self::name_teilmatch( $sw_vh, $nachname_h );
                $n_match_swap = self::name_teilmatch( $sw_nh, $vorname_h );
                if ( ( $v_match && $n_match ) || ( $v_match_swap && $n_match_swap ) ) {
                    $unterschiede = [];
                    if ( $sw_v !== $vorname_n && $sw_v !== $nachname_n ) {
                        $unterschiede[] = "Vorname Stammdaten „{$sw['first_name']}“, Eingabe „{$vorname}“";
                    }
                    if ( $sw_n !== $nachname_n && $sw_n !== $vorname_n ) {
                        $unterschiede[] = "Nachname Stammdaten „{$sw['last_name']}“, Eingabe „{$nachname}“";
                    }
                    $stufen['doppelname'][] = self::result( $sw, $sw_jahr, 'fuzzy_doppelname',
                        $unterschiede
                            ? 'Doppelname-Übereinstimmung: ' . implode( '; ', $unterschiede )
                            : 'Doppelname-Übereinstimmung' );
                    continue;
                }
            }

            // ── 4) Nur ein Namensteil angegeben ───────────────────────────
            if ( $nur_ein_teil ) {
                if ( $sw_nh === $nachname_h || $sw_vh === $nachname_h
                  || self::name_teilmatch( $sw_nh, $nachname_h )
                  || self::name_teilmatch( $sw_vh, $nachname_h ) ) {
                    $sw_full = trim( $sw['first_name'] . ' ' . $sw['last_name'] );
                    $stufen['einzelteil'][] = self::result( $sw, $sw_jahr, 'fuzzy_doppelname',
                        "Nur ein Namensteil angegeben — passt auf „{$sw_full}“" );
                }
                continue;
            }

            // ── 5) Tippfehler ─────────────────────────────────────────────
            $v_nah = self::fast_gleich( $sw_vh, $vorname_h );
            $n_nah = self::fast_gleich( $sw_nh, $nachname_h );
            if ( ( $v_nah && $n_nah )
              || ( $sw_vh === $vorname_h && $n_nah )
              || ( $sw_nh === $nachname_h && $v_nah ) ) {
                $sw_full = trim( $sw['first_name'] . ' ' . $sw['last_name'] );
                $stufen['tippfehler'][] = self::result( $sw, $sw_jahr, 'fuzzy_doppelname',
                    "Schreibweise weicht leicht ab (Stammdaten: „{$sw_full}“)" );
            }
        }

        // Erste Stufe mit Treffern entscheidet
        foreach ( $stufen as $treffer ) {
            if ( count( $treffer ) === 1 ) return $treffer[0];
            if ( count( $treffer ) > 1 ) {
                return [ 'confidence' => 'ambiguous', 'kandidaten' => $treffer ];
            }
        }
        return null;
    }

    /**
     * Zwei Namensteile gelten als "fast gleich", wenn sie sich nur in wenigen
     * Zeichen unterscheiden. Die erlaubte Abweichung wächst mit der Länge,
     * damit kurze Namen (Tim/Tom, Jan/Jon) nicht fälschlich zusammenfallen.
     */
    private static function fast_gleich( $a, $b ) {
        if ( $a === '' || $b === '' ) return false;
        if ( $a === $b ) return true;
        $len = min( mb_strlen( $a ), mb_strlen( $b ) );
        if ( $len < 4 ) return false;                 // zu kurz für Toleranz
        $erlaubt = $len >= 8 ? 2 : 1;
        if ( abs( mb_strlen( $a ) - mb_strlen( $b ) ) > $erlaubt ) return false;
        if ( levenshtein( $a, $b ) <= $erlaubt ) return true;
        // Vertauschte Nachbarzeichen („Jonsa" statt „Jonas") zählen als ein
        // Fehler; levenshtein() wertet sie als zwei.
        return self::nachbartausch( $a, $b );
    }

    /** Prüft, ob sich zwei gleich lange Wörter nur durch einen Tausch
     *  zweier benachbarter Zeichen unterscheiden. */
    private static function nachbartausch( $a, $b ) {
        if ( strlen( $a ) !== strlen( $b ) ) return false;
        $abw = [];
        for ( $i = 0; $i < strlen( $a ); $i++ ) {
            if ( $a[ $i ] !== $b[ $i ] ) {
                $abw[] = $i;
                if ( count( $abw ) > 2 ) return false;
            }
        }
        if ( count( $abw ) !== 2 ) return false;
        [ $i, $j ] = $abw;
        return $j === $i + 1 && $a[ $i ] === $b[ $j ] && $a[ $j ] === $b[ $i ];
    }

    /** Rückgabe-Struktur eines Treffers. */
    private static function result( $sw, $jahrgang, $confidence, $hinweis ) {
        return [
            'id'              => (int) $sw['id'],
            'first_name'      => $sw['first_name'],
            'last_name'       => $sw['last_name'],
            'jahrgang'        => $jahrgang,
            'mannschaft_id'   => (int) ( $sw['mannschaft_id'] ?? 0 ),
            'mannschaft_name' => $sw['mannschaft_name'] ?? '',
            'confidence'      => $confidence,
            'hinweis'         => $hinweis,
        ];
    }

    /**
     * Vergleicht einen Stammdaten-Namensteil ($soll) mit einem CSV-Namensteil
     * ($ist) und akzeptiert Doppelnamen-Übereinstimmungen.
     * Beide Werte müssen bereits normalisiert sein (lowercase, Whitespace).
     *
     * Match-Fälle:
     *  - Exakte Gleichheit
     *  - Einer der Werte ist Teil eines Doppelnamens des anderen (mit „-" oder Leerzeichen)
     *  - Beide haben Doppelnamen mit mindestens einem gemeinsamen Bestandteil
     */
    private static function name_teilmatch( $soll, $ist ) {
        if ( $soll === '' || $ist === '' ) return false;
        if ( $soll === $ist ) return true;
        // Zerlegen an "-" oder Leerzeichen (Doppelname-Bestandteile)
        $soll_parts = preg_split( '/[\s\-]+/', $soll, -1, PREG_SPLIT_NO_EMPTY );
        $ist_parts  = preg_split( '/[\s\-]+/', $ist,  -1, PREG_SPLIT_NO_EMPTY );
        // Mindestens ein gemeinsamer Bestandteil mit ≥ 2 Buchstaben
        foreach ( $soll_parts as $sp ) {
            if ( mb_strlen( $sp ) < 2 ) continue;
            if ( in_array( $sp, $ist_parts, true ) ) return true;
        }
        return false;
    }

    /**
     * Normalisiert Strings für Namensvergleich:
     * Kleinbuchstaben, Whitespace zusammenfassen, Umlaute behalten.
     */
    public static function norm( $s ) {
        $s = trim( (string) $s );
        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
        $s = preg_replace( '/\s+/', ' ', $s );
        return $s;
    }

    /**
     * Härtere Normalisierung für den Vergleich abweichender Schreibweisen:
     * Umlaute und ß werden auf ihre Grundform gebracht, damit „Müller",
     * „Mueller" und „Muller" alle als derselbe Name gelten. Ebenso werden
     * Akzente entfernt und Bindestriche zu Leerzeichen. Nur zum Vergleichen,
     * angezeigt wird immer die Originalschreibweise.
     */
    public static function norm_hart( $s ) {
        $s = self::norm( $s );
        // Zuerst die deutschen Sonderfälle mit zwei Buchstaben
        $s = strtr( $s, [
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
            'æ' => 'ae', 'ø' => 'o', 'å' => 'a',
        ] );
        // "ue"/"oe"/"ae" als Umschrift behandeln — nach der Umlaut-Ersetzung,
        // damit "Müller"→"muller" und "Mueller"→"muller" zusammenfallen.
        // Bewusst OHNE "ss"→"s": das würde sonst auch echte Namenspaare wie
        // Hasse/Hase zusammenfallen lassen. Für ß genügt die Ersetzung oben,
        // weil "Groß" und "Gross" beide auf "gross" landen.
        $s = strtr( $s, [ 'ae' => 'a', 'oe' => 'o', 'ue' => 'u' ] );
        // Verbleibende Akzente entfernen
        if ( function_exists( 'iconv' ) ) {
            $t = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s );
            if ( $t !== false && $t !== '' ) $s = strtolower( $t );
        }
        // Alles außer Buchstaben/Ziffern/Leerzeichen raus
        $s = preg_replace( '/[^a-z0-9 ]+/u', ' ', $s );
        $s = preg_replace( '/\s+/', ' ', trim( $s ) );
        return $s;
    }

    /**
     * Wer darf Bestzeiten importieren: Administrator, Schwimmwart oder wer
     * das Einzelrecht "Bestzeiten importieren" zugewiesen bekommen hat.
     * Ohne diese Ergänzung schlug der Import bei Trainern fehl, denen das
     * Recht ausdrücklich vergeben worden war.
     */
    private static function darf_importieren() {
        if ( LSV07I_Access::is_admin_raw() )    return true;
        if ( LSV07I_Access::is_schwimmwart() )  return true;
        return class_exists( 'LSV07I_Permissions' )
            && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_BESTZEIT_IMPORT );
    }

}
