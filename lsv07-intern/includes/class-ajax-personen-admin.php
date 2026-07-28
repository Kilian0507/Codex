<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Personen_Admin
 * --------------------------
 * AJAX-Endpoints für die zentrale Benutzerverwaltung im Admin-Bereich.
 * Nutzt die in Iteration 1 angelegte Service-Klasse LSV07I_Personen.
 *
 * Endpoints:
 *   - lsv07i_pers_list             Liste mit Filter
 *   - lsv07i_pers_get              Eine Person inkl. Zuordnungen
 *   - lsv07i_pers_save             Speichern (insert oder update)
 *   - lsv07i_pers_delete           Löschen (mit Schutz: Personen mit
 *                                  legacy-IDs können nicht gelöscht werden,
 *                                  damit alte UIs nicht plötzlich Datensätze
 *                                  vermissen)
 *   - lsv07i_pers_csv_import       CSV-Import
 *   - lsv07i_pers_csv_template     Beispieldatei-Download
 *   - lsv07i_pers_get_mannschaften Mannschaftsliste pro Sparte (für Modals)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Personen_Admin {

    public static function init() {
        $actions = [
            'lsv07i_pers_list',
            'lsv07i_pers_get',
            'lsv07i_pers_save',
            'lsv07i_pers_delete',
            'lsv07i_pers_csv_import',
            'lsv07i_pers_csv_template',
            'lsv07i_pers_get_mannschaften',
            'lsv07i_pers_personen_list',
            'lsv07i_pers_wp_users',
        ];
        foreach ( $actions as $a ) {
            add_action( 'wp_ajax_' . $a, [ __CLASS__, str_replace( 'lsv07i_pers_', '', $a ) ] );
        }
    }

    private static function require_admin() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Sitzung abgelaufen. Bitte Seite neu laden.' ] );
        }
        if ( ! LSV07I_Access::is_admin_raw() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ] );
        }
    }

    /**
     * Kleinbuchstaben — nutzt mb_strtolower nur, wenn die mbstring-Extension
     * verfügbar ist, sonst strtolower als Fallback. Ohne diesen Schutz crasht
     * der CSV-Import auf Servern ohne mbstring (Fatal Error, leere Antwort).
     */
    private static function lower( $s ) {
        return function_exists( 'mb_strtolower' )
            ? mb_strtolower( (string) $s, 'UTF-8' )
            : strtolower( (string) $s );
    }

    public static function list() {
        self::require_admin();
        $args = [
            'sparte'        => sanitize_text_field( $_POST['sparte'] ?? '' ) ?: null,
            'rolle'         => sanitize_text_field( $_POST['rolle']  ?? '' ) ?: null,
            'mannschaft_id' => isset( $_POST['mannschaft_id'] ) && $_POST['mannschaft_id'] !== ''
                                  ? (int) $_POST['mannschaft_id'] : null,
            'aktiv'         => isset( $_POST['aktiv'] ) && $_POST['aktiv'] === '0' ? false : true,
            'suche'         => sanitize_text_field( $_POST['suche'] ?? '' ),
            'limit'         => 1000,
        ];
        $rows = LSV07I_Personen::liste( $args );

        // Anreichern mit Sparten/Rollen pro Person für Tabellen-Anzeige
        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = array_map( fn( $r ) => (int) $r['id'], $rows );
        if ( ! empty( $ids ) ) {
            $in = implode( ',', $ids );
            $sr = $wpdb->get_results(
                "SELECT person_id, sparte, rolle FROM {$p}lsv07i_personen_sparten_rolle
                  WHERE person_id IN ($in) AND aktiv = 1"
            );
            $byPerson = [];
            foreach ( $sr as $r ) {
                $byPerson[ $r->person_id ][] = [ 'sparte' => $r->sparte, 'rolle' => $r->rolle ];
            }
            foreach ( $rows as &$row ) {
                $row['sparten_rollen'] = $byPerson[ $row['id'] ] ?? [];
                $row['quelle']         = 'personen'; // editierbar
            }
            unset( $row );
        }

        // ─── Zusätzlich: alle Personen aus den anderen Sparten-Tabellen ───
        // Diese sind read-only — sie werden im jeweiligen Detail-Tab bearbeitet.
        $extra = self::sammle_externe_personen();
        // Filter nach Suche / Sparte anwenden
        $suche  = strtolower( $args['suche'] );
        $sparte = $args['sparte'];
        $extra  = array_filter( $extra, function( $e ) use ( $suche, $sparte ) {
            if ( $sparte && $e['__sparte_for_filter'] !== $sparte ) return false;
            if ( $suche !== '' ) {
                $hay = strtolower( $e['vorname'] . ' ' . $e['nachname'] . ' ' . ( $e['email'] ?? '' ) );
                if ( strpos( $hay, $suche ) === false ) return false;
            }
            return true;
        } );
        // Felder bereinigen
        foreach ( $extra as &$e ) unset( $e['__sparte_for_filter'] );
        unset( $e );

        $rows = array_merge( $rows, array_values( $extra ) );

        // Nach Nachname sortieren
        usort( $rows, function( $a, $b ) {
            return strcasecmp( ( $a['nachname'] ?? '' ), ( $b['nachname'] ?? '' ) );
        } );

        wp_send_json_success( [ 'rows' => $rows ] );
    }

    /**
     * Schlanke Liste NUR der echten Personen aus lsv07i_personen, die mit
     * einem WP-Account verknüpft sind (für den Admin-Tab "Personen"). Diese
     * Personen erscheinen über ihren WP-Account automatisch in der
     * Rechteverwaltung. Externe Sportler/Trainer werden hier NICHT gemischt.
     */
    public static function personen_list() {
        self::require_admin();
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT id, vorname, nachname, geburtsdatum, email, telefon, wp_user_id, aktiv
               FROM {$p}lsv07i_personen
              WHERE wp_user_id > 0
           ORDER BY nachname ASC, vorname ASC", ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $wp_user = get_user_by( 'id', (int) $r['wp_user_id'] );
            $out[] = [
                'id'           => (int) $r['id'],
                'vorname'      => $r['vorname'],
                'nachname'     => $r['nachname'],
                'geburtsdatum' => $r['geburtsdatum'],
                'email'        => $r['email'],
                'telefon'      => $r['telefon'],
                'wp_user_id'   => (int) $r['wp_user_id'],
                'wp_user_name' => $wp_user ? $wp_user->display_name : '(unbekannt)',
                'wp_user_login'=> $wp_user ? $wp_user->user_login : '',
                'aktiv'        => (int) $r['aktiv'],
            ];
        }
        wp_send_json_success( [ 'rows' => $out ] );
    }

    /**
     * Liste der WordPress-Accounts für das Verknüpfungs-Dropdown.
     * Markiert, welche bereits einer Person zugeordnet sind.
     */
    public static function wp_users() {
        self::require_admin();
        global $wpdb;
        $p = $wpdb->prefix;

        $belegt = [];
        $rows = $wpdb->get_results( "SELECT wp_user_id, id FROM {$p}lsv07i_personen WHERE wp_user_id > 0" );
        foreach ( (array) $rows as $r ) $belegt[ (int) $r->wp_user_id ] = (int) $r->id;

        $users = get_users( [
            'number'  => 500,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => [ 'ID', 'display_name', 'user_login', 'user_email' ],
        ] );

        $out = [];
        foreach ( $users as $u ) {
            $uid = (int) $u->ID;
            $out[] = [
                'id'        => $uid,
                'name'      => $u->display_name ?: $u->user_login,
                'login'     => $u->user_login,
                'email'     => $u->user_email,
                'belegt_von'=> $belegt[ $uid ] ?? 0,
            ];
        }
        wp_send_json_success( [ 'users' => $out ] );
    }

    /**
     * Sammelt Personen aus den anderen Tabellen (Schwimmer, Tri-Sportler,
     * Fit-Sportler, Trainer aller Sparten). Liefert sie im selben Format
     * wie die Personen-Tabelle, aber mit Flag quelle != 'personen'.
     */
    private static function sammle_externe_personen() {
        global $wpdb;
        $p = $wpdb->prefix;
        $out = [];
        $running_id = -1; // negative IDs, damit kein Konflikt mit personen.id

        $add = function( $vor, $nach, $email, $sparte, $rolle, $quelle, $orig_id ) use ( &$out, &$running_id ) {
            $out[] = [
                'id'             => $running_id--,
                'vorname'        => $vor,
                'nachname'       => $nach,
                'geburtsdatum'   => '',
                'email'          => $email,
                'aktiv'          => 1,
                'sparten_rollen' => [ [ 'sparte' => $sparte, 'rolle' => $rolle ] ],
                'quelle'         => $quelle,
                'orig_id'        => (int) $orig_id,
                '__sparte_for_filter' => $sparte,
            ];
        };

        // Schwimmer
        $rows = $wpdb->get_results(
            "SELECT id, first_name, last_name FROM {$p}mv_swimmers WHERE active = 1", ARRAY_A );
        if ( $rows ) foreach ( $rows as $r ) {
            $add( $r['first_name'], $r['last_name'], '', 'schwimmen', 'sportler', 'mv_swimmers', $r['id'] );
        }

        // Schwimm-Trainer
        $rows = $wpdb->get_results(
            "SELECT id, name, email FROM {$p}lsv07i_trainer WHERE aktiv = 1", ARRAY_A );
        if ( $rows ) foreach ( $rows as $r ) {
            $add( $r['name'], '', $r['email'] ?? '', 'schwimmen', 'trainer', 'lsv07i_trainer', $r['id'] );
        }

        // Triathlon
        $tbl = $p . 'lsv07i_tri_sportler';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
            $rows = $wpdb->get_results(
                "SELECT id, first_name, last_name FROM $tbl WHERE aktiv = 1", ARRAY_A );
            if ( $rows ) foreach ( $rows as $r ) {
                $add( $r['first_name'], $r['last_name'], '', 'triathlon', 'sportler', 'tri_sportler', $r['id'] );
            }
        }
        $tbl = $p . 'lsv07i_tri_trainer';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
            $rows = $wpdb->get_results(
                "SELECT id, name, email FROM $tbl", ARRAY_A );
            if ( $rows ) foreach ( $rows as $r ) {
                $add( $r['name'], '', $r['email'] ?? '', 'triathlon', 'trainer', 'tri_trainer', $r['id'] );
            }
        }

        // Fitness
        $tbl = $p . 'lsv07i_fit_sportler';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
            $rows = $wpdb->get_results(
                "SELECT id, first_name, last_name FROM $tbl WHERE aktiv = 1", ARRAY_A );
            if ( $rows ) foreach ( $rows as $r ) {
                $add( $r['first_name'], $r['last_name'], '', 'fitness', 'sportler', 'fit_sportler', $r['id'] );
            }
        }
        $tbl = $p . 'lsv07i_fit_trainer';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) ) {
            $rows = $wpdb->get_results(
                "SELECT id, name, email FROM $tbl", ARRAY_A );
            if ( $rows ) foreach ( $rows as $r ) {
                $add( $r['name'], '', $r['email'] ?? '', 'fitness', 'trainer', 'fit_trainer', $r['id'] );
            }
        }
        return $out;
    }

    public static function get() {
        self::require_admin();
        $id = (int) ( $_POST['id'] ?? 0 );
        $p  = LSV07I_Personen::get( $id );
        if ( ! $p ) wp_send_json_error( [ 'message' => 'Nicht gefunden.' ] );
        wp_send_json_success( $p );
    }

    public static function save() {
        self::require_admin();
        $id   = isset( $_POST['id'] ) && $_POST['id'] ? (int) $_POST['id'] : null;

        // Reiner Personen-Modus (Tab "Personen"): WP-Account ist Pflicht.
        // Wird über das Flag 'require_wp' gesteuert, das der Personen-Tab sendet.
        $require_wp = ! empty( $_POST['require_wp'] );
        $wp_user_id = (int) ( $_POST['wp_user_id'] ?? 0 );

        if ( $require_wp ) {
            if ( $wp_user_id <= 0 ) {
                wp_send_json_error( [ 'message' => 'Bitte einen WordPress-Account verknüpfen.' ] );
            }
            if ( ! get_user_by( 'id', $wp_user_id ) ) {
                wp_send_json_error( [ 'message' => 'Der gewählte WordPress-Account existiert nicht.' ] );
            }
            // Eindeutigkeit: ein WP-Account darf nur einer Person zugeordnet sein
            global $wpdb;
            $p = $wpdb->prefix;
            $belegt = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_personen WHERE wp_user_id = %d AND id != %d LIMIT 1",
                $wp_user_id, (int) ( $id ?? 0 )
            ) );
            if ( $belegt ) {
                wp_send_json_error( [ 'message' => 'Dieser WordPress-Account ist bereits einer anderen Person zugeordnet.' ] );
            }
        }

        $data = [
            'vorname'         => $_POST['vorname']         ?? '',
            'nachname'        => $_POST['nachname']        ?? '',
            'geburtsdatum'    => $_POST['geburtsdatum']    ?? '',
            'geschlecht'      => $_POST['geschlecht']      ?? '',
            'email'           => $_POST['email']           ?? '',
            'telefon'         => $_POST['telefon']         ?? '',
            'dsv_id'          => $_POST['dsv_id']          ?? '',
            'mitgliedsnummer' => $_POST['mitgliedsnummer'] ?? '',
            'wp_user_id'      => $wp_user_id,
            'aktiv'           => isset( $_POST['aktiv'] ) ? (int) $_POST['aktiv'] : 1,
            'notes'           => $_POST['notes']           ?? '',
        ];
        $res = LSV07I_Personen::speichern( $data, $id );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( [ 'message' => $res->get_error_message() ] );
        }
        $person_id = (int) $res;

        // Sparten/Rollen-Liste verarbeiten (kommt als JSON)
        $sr_json = wp_unslash( $_POST['sparten_rollen_json'] ?? '[]' );
        $sr      = json_decode( $sr_json, true );
        if ( is_array( $sr ) ) {
            LSV07I_Personen::set_sparten_rollen( $person_id, $sr );
        }

        // Mannschafts-Liste
        $mn_json = wp_unslash( $_POST['mannschaften_json'] ?? '[]' );
        $mn      = json_decode( $mn_json, true );
        if ( is_array( $mn ) ) {
            LSV07I_Personen::set_mannschaften( $person_id, $mn );
        }

        wp_send_json_success( [ 'id' => $person_id ] );
    }

    public static function delete() {
        self::require_admin();
        $id = (int) ( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        // Sicherheits-Check: Personen mit Legacy-Verknüpfung dürfen nicht
        // gelöscht werden, weil sie mit alten Tabellen verbunden sind.
        // Das verhindert Inkonsistenzen mit den noch laufenden alten UIs.
        global $wpdb;
        $p = $wpdb->prefix;
        $p_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT legacy_swimmer_id, legacy_trainer_id,
                    legacy_tri_sportler_id, legacy_tri_trainer_id
               FROM {$p}lsv07i_personen WHERE id = %d", $id
        ) );
        if ( ! $p_row ) wp_send_json_error( [ 'message' => 'Person nicht gefunden.' ] );
        if ( $p_row->legacy_swimmer_id || $p_row->legacy_trainer_id
          || $p_row->legacy_tri_sportler_id || $p_row->legacy_tri_trainer_id ) {
            wp_send_json_error( [ 'message' =>
                'Diese Person ist mit den alten Sportler-/Trainer-Tabellen verknüpft '
                . 'und kann hier nicht gelöscht werden. Bitte zuerst dort entfernen.'
            ] );
        }

        LSV07I_Personen::loeschen( $id );
        wp_send_json_success();
    }

    public static function get_mannschaften() {
        self::require_admin();
        global $wpdb;
        $p = $wpdb->prefix;
        $out = [
            'schwimmen' => $wpdb->get_results(
                "SELECT id, name FROM {$p}lsv07_gruppen ORDER BY name", ARRAY_A
            ),
            'triathlon' => [],
            'fitness'   => [],
        ];
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_tri_gruppen'" ) ) {
            $out['triathlon'] = $wpdb->get_results(
                "SELECT id, name FROM {$p}lsv07i_tri_gruppen ORDER BY name", ARRAY_A
            );
        }
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_fit_gruppen'" ) ) {
            $out['fitness'] = $wpdb->get_results(
                "SELECT id, name FROM {$p}lsv07i_fit_gruppen WHERE aktiv = 1 ORDER BY name", ARRAY_A
            );
        }
        wp_send_json_success( $out );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CSV-IMPORT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Liefert die Beispiel-CSV als Download.
     */
    public static function csv_template() {
        self::require_admin();
        $rows = [
            [ 'Vorname','Nachname','Geburtsdatum','Geschlecht','Email','Telefon','DSV-ID','Mitgliedsnummer','Rollen','Mannschaften' ],
            [ 'Max',  'Mustermann','2005-06-15','M','max@example.com',     '0151 1234567','12345','M-001','schwimmen:sportler,triathlon:sportler','schwimmen:A-Mannschaft|triathlon:Tri-Gruppe-1' ],
            [ 'Anna', 'Beispiel',  '2010-03-22','W','anna@example.com',    '',           '',     'M-002','schwimmen:sportler','schwimmen:B-Mannschaft' ],
            [ 'Peter','Trainer',   '1985-11-30','M','peter@example.com',   '',           '',     '',     'schwimmen:trainer,fitness:trainer','schwimmen:A-Mannschaft|schwimmen:B-Mannschaft|fitness:Kraftsport' ],
        ];
        // Direkter CSV-Download: Header setzen und beenden
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="personen-vorlage.csv"' );
        $fp = fopen( 'php://output', 'w' );
        // BOM für Excel-Kompatibilität
        fwrite( $fp, "\xEF\xBB\xBF" );
        foreach ( $rows as $r ) fputcsv( $fp, $r, ';' );
        fclose( $fp );
        exit;
    }

    /**
     * CSV-Import als zwei-stufiger Prozess: zuerst Vorschau, dann Commit.
     * Im POST: 'phase' = 'preview' oder 'commit', 'csv' = Datei-Inhalt
     *
     * Format-Regeln:
     *   - Trennzeichen: Semikolon (Excel-Standard in DE-Locale)
     *   - Header: muss Spalten "Vorname" und "Nachname" enthalten
     *   - Sparten-Rollen-Spalte: kommagetrennt, Format "sparte:rolle"
     *     z.B. "schwimmen:sportler,triathlon:trainer"
     *   - Mannschaften-Spalte: pipe-getrennt, Format "sparte:mannschaftsname"
     *     z.B. "schwimmen:A-Mannschaft|triathlon:Tri-Gruppe-1"
     *
     * Match auf bestehende Personen erfolgt über Vorname+Nachname+Geburtsjahr.
     */
    public static function csv_import() {
        self::require_admin();
        $phase = sanitize_text_field( $_POST['phase'] ?? 'preview' );
        $csv   = (string) wp_unslash( $_POST['csv'] ?? '' );
        if ( trim( $csv ) === '' ) wp_send_json_error( [ 'message' => 'Keine CSV-Daten empfangen.' ] );

        $parsed = self::parse_csv( $csv );
        if ( is_wp_error( $parsed ) ) {
            wp_send_json_error( [ 'message' => $parsed->get_error_message() ] );
        }

        if ( $phase === 'preview' ) {
            wp_send_json_success( [ 'rows' => $parsed['rows'], 'header' => $parsed['header'] ] );
        }
        if ( $phase !== 'commit' ) {
            wp_send_json_error( [ 'message' => 'Unbekannte Phase.' ] );
        }

        // COMMIT: alle gültigen Zeilen einspielen
        $imp_neu       = 0;
        $imp_aktual    = 0;
        $skipped       = 0;
        foreach ( $parsed['rows'] as $row ) {
            if ( ! empty( $row['_fehler'] ) ) { $skipped++; continue; }
            $person_id = self::commit_zeile( $row );
            if ( $person_id === 0 ) { $skipped++; continue; }
            if ( ! empty( $row['_match_id'] ) ) $imp_aktual++;
            else                                $imp_neu++;
        }
        wp_send_json_success( [
            'neu'       => $imp_neu,
            'aktual'    => $imp_aktual,
            'skipped'   => $skipped,
            'message'   => "$imp_neu neu angelegt, $imp_aktual aktualisiert"
                           . ( $skipped > 0 ? ", $skipped übersprungen" : '' ) . '.',
        ] );
    }

    private static function parse_csv( $text ) {
        // BOM entfernen
        $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
        $text = str_replace( [ "\r\n", "\r" ], "\n", $text );
        $lines = explode( "\n", $text );
        if ( count( $lines ) < 2 ) {
            return new WP_Error( 'csv', 'Mindestens Header + eine Datenzeile nötig.' );
        }

        // Trenner heuristisch: Semikolon zählen vs. Komma in Header-Zeile
        $sep = ( substr_count( $lines[0], ';' ) >= substr_count( $lines[0], ',' ) ) ? ';' : ',';

        $header = self::csv_split( $lines[0], $sep );
        $header = array_map( 'trim', $header );
        $headerL = array_map( [ __CLASS__, 'lower' ], $header );

        // Nötige Spalten ermitteln
        $idx = function( $needles ) use ( $headerL ) {
            foreach ( (array) $needles as $n ) {
                $i = array_search( self::lower( $n ), $headerL, true );
                if ( $i !== false ) return $i;
            }
            return null;
        };
        $iVor   = $idx( [ 'vorname', 'first_name' ] );
        $iNach  = $idx( [ 'nachname', 'last_name', 'name' ] );
        if ( $iVor === null || $iNach === null ) {
            return new WP_Error( 'csv', 'Spalten "Vorname" und "Nachname" sind Pflicht.' );
        }
        $iGeb   = $idx( [ 'geburtsdatum', 'geburt', 'birth_date', 'birthday' ] );
        $iGes   = $idx( [ 'geschlecht', 'sex', 'gender' ] );
        $iMail  = $idx( [ 'email', 'e-mail' ] );
        $iTel   = $idx( [ 'telefon', 'phone' ] );
        $iDsv   = $idx( [ 'dsv-id', 'dsv_id', 'dsvid' ] );
        $iMitgl = $idx( [ 'mitgliedsnummer', 'mitgl-nr', 'mnr' ] );
        $iRoll  = $idx( [ 'rollen', 'sparten', 'rolle' ] );
        $iMann  = $idx( [ 'mannschaften', 'mannschaft' ] );

        $rows = [];
        for ( $i = 1; $i < count( $lines ); $i++ ) {
            $line = $lines[ $i ];
            if ( trim( $line ) === '' ) continue;
            $cols = self::csv_split( $line, $sep );
            $vor  = trim( (string) ( $cols[ $iVor ]  ?? '' ) );
            $nach = trim( (string) ( $cols[ $iNach ] ?? '' ) );
            if ( $vor === '' || $nach === '' ) continue;

            $row = [
                'vorname'         => $vor,
                'nachname'        => $nach,
                'geburtsdatum'    => $iGeb   !== null ? trim( (string) ( $cols[ $iGeb ]   ?? '' ) ) : '',
                'geschlecht'      => $iGes   !== null ? trim( (string) ( $cols[ $iGes ]   ?? '' ) ) : '',
                'email'           => $iMail  !== null ? trim( (string) ( $cols[ $iMail ]  ?? '' ) ) : '',
                'telefon'         => $iTel   !== null ? trim( (string) ( $cols[ $iTel ]   ?? '' ) ) : '',
                'dsv_id'          => $iDsv   !== null ? trim( (string) ( $cols[ $iDsv ]   ?? '' ) ) : '',
                'mitgliedsnummer' => $iMitgl !== null ? trim( (string) ( $cols[ $iMitgl ] ?? '' ) ) : '',
                'rollen_raw'      => $iRoll  !== null ? trim( (string) ( $cols[ $iRoll ]  ?? '' ) ) : '',
                'mann_raw'        => $iMann  !== null ? trim( (string) ( $cols[ $iMann ]  ?? '' ) ) : '',
            ];

            // Sparten-Rollen parsen
            $row['sparten_rollen'] = [];
            $row['_fehler']        = '';
            if ( $row['rollen_raw'] !== '' ) {
                foreach ( explode( ',', $row['rollen_raw'] ) as $sr ) {
                    $sr = trim( $sr );
                    if ( strpos( $sr, ':' ) === false ) continue;
                    list( $sp, $ro ) = array_map( 'trim', explode( ':', $sr, 2 ) );
                    $sp = self::lower( $sp );
                    $ro = self::lower( $ro );
                    if ( ! in_array( $sp, LSV07I_Personen::SPARTEN, true ) ) {
                        $row['_fehler'] = "Unbekannte Sparte: $sp"; continue;
                    }
                    if ( ! in_array( $ro, LSV07I_Personen::ROLLEN, true ) ) {
                        $row['_fehler'] = "Unbekannte Rolle: $ro"; continue;
                    }
                    $row['sparten_rollen'][] = [ 'sparte' => $sp, 'rolle' => $ro ];
                }
            }

            // Mannschaften parsen + auf vorhandene Mannschaften matchen
            $row['mannschaften'] = self::parse_mannschaften( $row['mann_raw'] );

            // Existenz-Match
            $match = self::match_person( $row['vorname'], $row['nachname'], $row['geburtsdatum'] );
            $row['_match_id']  = $match ? (int) $match->id : 0;
            $row['_match_str'] = $match ? trim( $match->vorname . ' ' . $match->nachname ) . ' (#' . $match->id . ')' : '';

            $rows[] = $row;
        }

        return [ 'header' => $header, 'rows' => $rows ];
    }

    /**
     * Parst die Mannschaften-Spalte und versucht, jeden Eintrag auf eine
     * existierende Mannschaft zu matchen.
     * Format: "schwimmen:A-Mannschaft|triathlon:Tri-Gruppe-1"
     */
    private static function parse_mannschaften( $raw ) {
        $out = [];
        if ( trim( $raw ) === '' ) return $out;
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( explode( '|', $raw ) as $part ) {
            $part = trim( $part );
            if ( strpos( $part, ':' ) === false ) continue;
            list( $sp, $name ) = array_map( 'trim', explode( ':', $part, 2 ) );
            $sp = self::lower( $sp );
            if ( ! in_array( $sp, LSV07I_Personen::SPARTEN, true ) ) continue;

            $tab = '';
            if ( $sp === 'schwimmen' ) $tab = $p . 'lsv07_gruppen';
            if ( $sp === 'triathlon' ) $tab = $p . 'lsv07i_tri_gruppen';
            if ( $sp === 'fitness' )   $tab = $p . 'lsv07i_fit_gruppen';
            if ( ! $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $tab ) . "'" ) ) continue;

            $mid = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `$tab` WHERE LOWER(name) = LOWER(%s) LIMIT 1", $name
            ) );
            if ( ! $mid ) {
                $out[] = [ 'sparte' => $sp, 'name' => $name, 'mannschaft_id' => 0,
                           'fehler' => "Mannschaft '$name' in $sp nicht gefunden" ];
                continue;
            }
            $out[] = [ 'sparte' => $sp, 'name' => $name, 'mannschaft_id' => (int) $mid ];
        }
        return $out;
    }

    private static function match_person( $vor, $nach, $gebd ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $gebd ) {
            $jahr = (int) substr( $gebd, 0, 4 );
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, vorname, nachname FROM {$p}lsv07i_personen
                  WHERE LOWER(vorname) = LOWER(%s)
                    AND LOWER(nachname) = LOWER(%s)
                    AND YEAR(geburtsdatum) = %d
                  LIMIT 1",
                $vor, $nach, $jahr
            ) );
            if ( $row ) return $row;
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, vorname, nachname FROM {$p}lsv07i_personen
              WHERE LOWER(vorname) = LOWER(%s) AND LOWER(nachname) = LOWER(%s)
              LIMIT 1",
            $vor, $nach
        ) );
    }

    /**
     * Eine Vorschau-Zeile in die DB schreiben (insert oder update).
     */
    private static function commit_zeile( $row ) {
        $data = [
            'vorname'         => $row['vorname'],
            'nachname'        => $row['nachname'],
            'geburtsdatum'    => $row['geburtsdatum'],
            'geschlecht'      => $row['geschlecht'],
            'email'           => $row['email'],
            'telefon'         => $row['telefon'],
            'dsv_id'          => $row['dsv_id'],
            'mitgliedsnummer' => $row['mitgliedsnummer'],
        ];
        $id = $row['_match_id'] ?: null;
        $res = LSV07I_Personen::speichern( $data, $id );
        if ( is_wp_error( $res ) ) return 0;
        $person_id = (int) $res;

        if ( ! empty( $row['sparten_rollen'] ) ) {
            // Update-Modus: Sparten ergänzen, nicht ersetzen
            // (damit Bestehendes nicht versehentlich überschrieben wird)
            global $wpdb;
            $p = $wpdb->prefix;
            foreach ( $row['sparten_rollen'] as $sr ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}lsv07i_personen_sparten_rolle
                       (person_id, sparte, rolle, aktiv) VALUES (%d, %s, %s, 1)",
                    $person_id, $sr['sparte'], $sr['rolle']
                ) );
            }
        }
        if ( ! empty( $row['mannschaften'] ) ) {
            global $wpdb;
            $p = $wpdb->prefix;
            foreach ( $row['mannschaften'] as $m ) {
                if ( empty( $m['mannschaft_id'] ) ) continue;
                // Rolle aus den sparten_rollen ableiten — wenn die Person in
                // dieser Sparte Trainer ist, ist sie auch in der Mannschaft Trainer
                $rolle = 'sportler';
                foreach ( $row['sparten_rollen'] as $sr ) {
                    if ( $sr['sparte'] === $m['sparte'] && $sr['rolle'] === 'trainer' ) {
                        $rolle = 'trainer';
                    }
                }
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}lsv07i_personen_mannschaft
                       (person_id, sparte, mannschaft_id, rolle)
                     VALUES (%d, %s, %d, %s)",
                    $person_id, $m['sparte'], (int) $m['mannschaft_id'], $rolle
                ) );
            }
        }
        return $person_id;
    }

    /**
     * CSV-Zeile mit Berücksichtigung von Quoting splitten.
     */
    private static function csv_split( $line, $sep ) {
        $fp = fopen( 'php://memory', 'r+' );
        fwrite( $fp, $line );
        rewind( $fp );
        $row = fgetcsv( $fp, 0, $sep );
        fclose( $fp );
        return $row ?: [];
    }
}
