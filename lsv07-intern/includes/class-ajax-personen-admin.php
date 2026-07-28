<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Personen_Admin
 * --------------------------
 * AJAX-Endpoints für die zentrale Benutzerverwaltung im Admin-Bereich.
 * Nutzt die in Iteration 1 angelegte Service-Klasse LSV07I_Personen.
 *
 * Endpoints:
 *   - lsv07i_pers_personen_list    Liste aller Personen inkl. Sparten/Rollen
 *   - lsv07i_pers_get              Eine Person inkl. Zuordnungen
 *   - lsv07i_pers_save             Speichern (insert oder update)
 *   - lsv07i_pers_delete           Löschen (mit Schutz: Personen mit
 *                                  legacy-IDs können nicht gelöscht werden,
 *                                  damit alte UIs nicht plötzlich Datensätze
 *                                  vermissen)
 *   - lsv07i_pers_csv_analyse      CSV-Spalten erkennen + Zuordnung vorschlagen
 *   - lsv07i_pers_csv_import       CSV-Vorschau und -Import mit Zuordnung
 *   - lsv07i_pers_csv_template     Beispieldatei-Download
 *   - lsv07i_pers_get_mannschaften Mannschaftsliste pro Sparte (für Modals)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Personen_Admin {

    public static function init() {
        $actions = [
            'lsv07i_pers_get',
            'lsv07i_pers_save',
            'lsv07i_pers_delete',
            'lsv07i_pers_csv_analyse',
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

    /**
     * Alle Personen aus lsv07i_personen inkl. Sparten/Rollen und — falls
     * verknüpft — dem WordPress-Account. Basis für den Admin-Tab "Personen".
     */
    public static function personen_list() {
        self::require_admin();
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT id, vorname, nachname, geburtsdatum, geschlecht, email, telefon,
                    dsv_id, mitgliedsnummer, wp_user_id, aktiv
               FROM {$p}lsv07i_personen
           ORDER BY nachname ASC, vorname ASC", ARRAY_A );
        if ( empty( $rows ) ) wp_send_json_success( [ 'rows' => [] ] );

        // Sparten/Rollen in einer Abfrage nachladen statt pro Person
        $ids = array_map( fn( $r ) => (int) $r['id'], $rows );
        $in  = implode( ',', $ids );
        $sr  = $wpdb->get_results(
            "SELECT person_id, sparte, rolle FROM {$p}lsv07i_personen_sparten_rolle
              WHERE person_id IN ($in) AND aktiv = 1
           ORDER BY sparte ASC, rolle ASC" );
        $nach_person = [];
        foreach ( (array) $sr as $r ) {
            $nach_person[ (int) $r->person_id ][] = [ 'sparte' => $r->sparte, 'rolle' => $r->rolle ];
        }

        $out = [];
        foreach ( $rows as $r ) {
            $uid     = (int) $r['wp_user_id'];
            $wp_user = $uid ? get_user_by( 'id', $uid ) : null;
            $out[] = [
                'id'              => (int) $r['id'],
                'vorname'         => $r['vorname'],
                'nachname'        => $r['nachname'],
                'geburtsdatum'    => $r['geburtsdatum'],
                'geschlecht'      => $r['geschlecht'],
                'email'           => $r['email'],
                'telefon'         => $r['telefon'],
                'dsv_id'          => $r['dsv_id'],
                'mitgliedsnummer' => $r['mitgliedsnummer'],
                'wp_user_id'      => $uid,
                'wp_user_name'    => $wp_user ? $wp_user->display_name : ( $uid ? '(Account gelöscht)' : '' ),
                'wp_user_login'   => $wp_user ? $wp_user->user_login : '',
                'aktiv'           => (int) $r['aktiv'],
                'sparten_rollen'  => $nach_person[ (int) $r['id'] ] ?? [],
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

        // WordPress-Account ist optional. Wird einer angegeben, muss er
        // existieren und darf noch keiner anderen Person gehören.
        $wp_user_id = (int) ( $_POST['wp_user_id'] ?? 0 );

        if ( $wp_user_id > 0 ) {
            if ( ! get_user_by( 'id', $wp_user_id ) ) {
                wp_send_json_error( [ 'message' => 'Der gewählte WordPress-Account existiert nicht.' ] );
            }
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
    //
    //  Ablauf in drei Schritten:
    //    1. lsv07i_pers_csv_analyse  – Datei-Inhalt schicken, Server liefert
    //       erkannte Spalten, Beispielwerte und einen Zuordnungs-Vorschlag.
    //    2. lsv07i_pers_csv_import (phase=preview) – mit der vom Nutzer
    //       bestätigten Zuordnung; liefert die fertig interpretierten Zeilen.
    //    3. lsv07i_pers_csv_import (phase=commit)  – schreibt sie in die DB.
    //
    //  Die Zuordnung ist eine Liste: Spaltenindex → Feldschlüssel. Spalten
    //  ohne Zuordnung werden ignoriert. Mehrere Spalten dürfen auf dasselbe
    //  Feld zeigen, wenn dieses als 'mehrfach' markiert ist (z.B. drei
    //  Mannschafts-Spalten).
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Katalog aller Zielfelder, denen eine CSV-Spalte zugeordnet werden kann.
     *   gruppe   – Überschrift im Zuordnungs-Dropdown
     *   mehrfach – mehrere Spalten erlaubt, Werte werden zusammengeführt
     *   aliase   – Header-Namen für die automatische Vorbelegung
     */
    public static function csv_felder() {
        return [
            'vorname' => [
                'label' => 'Vorname', 'gruppe' => 'Person',
                'aliase' => [ 'vorname', 'first name', 'first_name', 'firstname', 'rufname' ],
            ],
            'nachname' => [
                'label' => 'Nachname', 'gruppe' => 'Person',
                'aliase' => [ 'nachname', 'name', 'last name', 'last_name', 'lastname', 'familienname', 'surname' ],
            ],
            'name_voll' => [
                'label' => 'Name (Vor- und Nachname in einer Spalte)', 'gruppe' => 'Person',
                'aliase' => [ 'nachname vorname', 'vorname nachname', 'name vorname',
                              'voller name', 'full name', 'name komplett', 'anzeigename', 'display_name', 'display name' ],
            ],
            'geburtsdatum' => [
                'label' => 'Geburtsdatum', 'gruppe' => 'Person',
                'aliase' => [ 'geburtsdatum', 'geburtstag', 'geburt', 'geb', 'geb.', 'birth_date', 'birthdate', 'birthday', 'date of birth', 'dob' ],
            ],
            'geschlecht' => [
                'label' => 'Geschlecht', 'gruppe' => 'Person',
                'aliase' => [ 'geschlecht', 'sex', 'gender', 'm/w' ],
            ],
            'email' => [
                'label' => 'E-Mail', 'gruppe' => 'Kontakt',
                'aliase' => [ 'email', 'e-mail', 'e mail', 'mail', 'emailadresse', 'e-mail-adresse', 'mailadresse' ],
            ],
            'telefon' => [
                'label' => 'Telefon', 'gruppe' => 'Kontakt',
                'aliase' => [ 'telefon', 'telefonnummer', 'tel', 'tel.', 'phone', 'handy', 'mobil', 'mobile', 'rufnummer' ],
            ],
            'dsv_id' => [
                'label' => 'DSV-ID', 'gruppe' => 'Verein',
                'aliase' => [ 'dsv-id', 'dsv id', 'dsv_id', 'dsvid', 'dsv' ],
            ],
            'mitgliedsnummer' => [
                'label' => 'Mitgliedsnummer', 'gruppe' => 'Verein',
                'aliase' => [ 'mitgliedsnummer', 'mitgliedsnr', 'mitglied-nr', 'mitgl-nr', 'mnr', 'mitgliedsnr.' ],
            ],
            'aktiv' => [
                'label' => 'Aktiv (ja/nein)', 'gruppe' => 'Verein',
                'aliase' => [ 'aktiv', 'active', 'status' ],
            ],
            'notizen' => [
                'label' => 'Notizen', 'gruppe' => 'Verein', 'mehrfach' => true,
                'aliase' => [ 'notizen', 'notiz', 'notes', 'bemerkung', 'bemerkungen', 'kommentar', 'anmerkung' ],
            ],
            'wp_user' => [
                'label' => 'WordPress-Konto (Benutzername, E-Mail oder ID)', 'gruppe' => 'Verein',
                'aliase' => [ 'wordpress', 'wp-konto', 'wp konto', 'wp_user', 'wp-benutzer', 'benutzername', 'username', 'login', 'user' ],
            ],
            'rolle_schwimmen' => [
                'label' => 'Rolle Schwimmen (sportler / trainer)', 'gruppe' => 'Zuordnung',
                'aliase' => [ 'rolle schwimmen', 'schwimmen', 'schwimmen rolle' ],
            ],
            'rolle_triathlon' => [
                'label' => 'Rolle Triathlon (sportler / trainer)', 'gruppe' => 'Zuordnung',
                'aliase' => [ 'rolle triathlon', 'triathlon', 'triathlon rolle' ],
            ],
            'rolle_fitness' => [
                'label' => 'Rolle Fitness (sportler / trainer)', 'gruppe' => 'Zuordnung',
                'aliase' => [ 'rolle fitness', 'fitness', 'fitness rolle' ],
            ],
            'mannschaft_schwimmen' => [
                'label' => 'Mannschaft(en) Schwimmen', 'gruppe' => 'Zuordnung', 'mehrfach' => true,
                'aliase' => [ 'mannschaft schwimmen', 'mannschaft', 'mannschaften', 'team', 'teams' ],
            ],
            'mannschaft_triathlon' => [
                'label' => 'Gruppe(n) Triathlon', 'gruppe' => 'Zuordnung', 'mehrfach' => true,
                'aliase' => [ 'gruppe triathlon', 'mannschaft triathlon', 'tri-gruppe' ],
            ],
            'mannschaft_fitness' => [
                'label' => 'Gruppe(n) Fitness', 'gruppe' => 'Zuordnung', 'mehrfach' => true,
                'aliase' => [ 'gruppe fitness', 'mannschaft fitness', 'fit-gruppe' ],
            ],
            'rollen_kombi' => [
                'label' => 'Sparten+Rollen kombiniert (schwimmen:sportler,…)', 'gruppe' => 'Kombinierte Spalten', 'mehrfach' => true,
                'aliase' => [ 'rollen', 'rolle', 'sparten', 'sparte' ],
            ],
            'mannschaften_kombi' => [
                'label' => 'Mannschaften kombiniert (schwimmen:A-Team|…)', 'gruppe' => 'Kombinierte Spalten', 'mehrfach' => true,
                'aliase' => [ 'mannschaften kombiniert', 'sparte:mannschaft' ],
            ],
        ];
    }

    /** Sparte → Mannschafts-Tabelle. */
    private static function mannschaft_tabelle( $sparte ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $sparte === 'schwimmen' ) return $p . 'lsv07_gruppen';
        if ( $sparte === 'triathlon' ) return $p . 'lsv07i_tri_gruppen';
        if ( $sparte === 'fitness' )   return $p . 'lsv07i_fit_gruppen';
        return '';
    }

    /**
     * Schritt 1: CSV analysieren. Liefert Spalten, Beispielzeilen und einen
     * Zuordnungs-Vorschlag, damit die UI die Dropdowns vorbelegen kann.
     */
    public static function csv_analyse() {
        self::require_admin();
        $csv   = (string) wp_unslash( $_POST['csv'] ?? '' );
        $delim = (string) ( $_POST['delimiter'] ?? '' );
        if ( trim( $csv ) === '' ) {
            wp_send_json_error( [ 'message' => 'Keine CSV-Daten empfangen.' ] );
        }

        if ( ! in_array( $delim, [ ';', ',', "\t", '|' ], true ) ) {
            $delim = self::detect_delimiter( $csv );
        }
        $raw = self::parse_raw( $csv, $delim );
        if ( count( $raw ) < 2 ) {
            wp_send_json_error( [ 'message' => 'Die Datei enthält keine Datenzeilen (nur eine Kopfzeile oder leer).' ] );
        }

        $header = array_map( 'trim', array_shift( $raw ) );
        $spalten = [];
        foreach ( $header as $i => $name ) {
            $beispiele = [];
            foreach ( $raw as $zeile ) {
                $wert = trim( (string) ( $zeile[ $i ] ?? '' ) );
                if ( $wert !== '' ) $beispiele[] = $wert;
                if ( count( $beispiele ) >= 3 ) break;
            }
            $spalten[] = [
                'index'     => $i,
                'name'      => $name !== '' ? $name : 'Spalte ' . ( $i + 1 ),
                'beispiele' => $beispiele,
            ];
        }

        wp_send_json_success( [
            'delimiter'  => $delim,
            'spalten'    => $spalten,
            'zeilen'     => count( $raw ),
            'felder'     => self::csv_felder(),
            'mapping'    => self::auto_mapping( $header ),
            'sparten'    => LSV07I_Personen::SPARTEN,
        ] );
    }

    /**
     * Schritt 2 + 3: Vorschau bzw. Import mit der übergebenen Zuordnung.
     */
    public static function csv_import() {
        self::require_admin();
        $phase = sanitize_text_field( $_POST['phase'] ?? 'preview' );
        if ( ! in_array( $phase, [ 'preview', 'commit' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Unbekannte Phase.' ] );
        }

        $csv = (string) wp_unslash( $_POST['csv'] ?? '' );
        if ( trim( $csv ) === '' ) {
            wp_send_json_error( [ 'message' => 'Keine CSV-Daten empfangen.' ] );
        }

        $delim = (string) ( $_POST['delimiter'] ?? '' );
        if ( ! in_array( $delim, [ ';', ',', "\t", '|' ], true ) ) {
            $delim = self::detect_delimiter( $csv );
        }

        $mapping = json_decode( (string) wp_unslash( $_POST['mapping'] ?? '[]' ), true );
        if ( ! is_array( $mapping ) ) $mapping = [];
        $mapping = self::clean_mapping( $mapping );
        if ( ! in_array( 'vorname', $mapping, true ) && ! in_array( 'name_voll', $mapping, true ) ) {
            wp_send_json_error( [ 'message' => 'Bitte eine Spalte dem Vornamen zuordnen (oder eine Spalte mit vollständigem Namen).' ] );
        }
        if ( ! in_array( 'nachname', $mapping, true ) && ! in_array( 'name_voll', $mapping, true ) ) {
            wp_send_json_error( [ 'message' => 'Bitte eine Spalte dem Nachnamen zuordnen (oder eine Spalte mit vollständigem Namen).' ] );
        }

        $opt = [
            'abgleich'      => sanitize_text_field( $_POST['abgleich'] ?? 'name_jahr' ),
            'leere_ueber'   => ( $_POST['leere_ueberspringen'] ?? '1' ) !== '0',
            'zuordnung'     => ( $_POST['zuordnung_modus'] ?? 'ergaenzen' ) === 'ersetzen' ? 'ersetzen' : 'ergaenzen',
            'nur_update'    => ! empty( $_POST['nur_update'] ),
        ];
        if ( ! in_array( $opt['abgleich'], [ 'name_jahr', 'name', 'dsv_id', 'mitgliedsnummer', 'email' ], true ) ) {
            $opt['abgleich'] = 'name_jahr';
        }

        $raw = self::parse_raw( $csv, $delim );
        if ( count( $raw ) < 2 ) {
            wp_send_json_error( [ 'message' => 'Die Datei enthält keine Datenzeilen.' ] );
        }
        array_shift( $raw ); // Kopfzeile

        $rows = [];
        foreach ( $raw as $nr => $zeile ) {
            $row = self::zeile_aufbauen( $zeile, $mapping, $opt );
            if ( $row === null ) continue; // komplett leere Zeile
            $row['_nr'] = $nr + 2;         // 1-basiert inkl. Kopfzeile
            $rows[] = $row;
        }
        if ( empty( $rows ) ) {
            wp_send_json_error( [ 'message' => 'Keine verwertbaren Datenzeilen gefunden.' ] );
        }

        if ( $phase === 'preview' ) {
            wp_send_json_success( [ 'rows' => $rows ] );
        }

        $neu = 0; $aktual = 0; $skipped = 0; $fehler = [];
        foreach ( $rows as $row ) {
            if ( $row['_fehler'] !== '' ) {
                $skipped++;
                continue;
            }
            $res = self::commit_zeile( $row, $opt );
            if ( is_wp_error( $res ) ) {
                $skipped++;
                $fehler[] = 'Zeile ' . $row['_nr'] . ': ' . $res->get_error_message();
                continue;
            }
            if ( $row['_match_id'] ) $aktual++;
            else                     $neu++;
        }

        wp_send_json_success( [
            'neu'     => $neu,
            'aktual'  => $aktual,
            'skipped' => $skipped,
            'fehler'  => $fehler,
            'message' => "$neu neu angelegt, $aktual aktualisiert"
                         . ( $skipped > 0 ? ", $skipped übersprungen" : '' ) . '.',
        ] );
    }

    /**
     * Beispiel-CSV zum Download. Enthält alle unterstützten Spalten, damit
     * klar ist, welche Daten übernommen werden können.
     */
    public static function csv_template() {
        self::require_admin();
        $rows = [
            [ 'Vorname','Nachname','Geburtsdatum','Geschlecht','E-Mail','Telefon','DSV-ID',
              'Mitgliedsnummer','Aktiv','Notizen','WordPress-Konto',
              'Rolle Schwimmen','Rolle Triathlon','Rolle Fitness',
              'Mannschaft Schwimmen','Gruppe Triathlon','Gruppe Fitness' ],
            [ 'Max','Mustermann','15.06.2005','m','max@example.com','0151 1234567','12345',
              'M-001','ja','Wechselt im Sommer in die A-Mannschaft','max.mustermann',
              'sportler','sportler','',
              'A-Mannschaft','Tri-Gruppe-1','' ],
            [ 'Anna','Beispiel','22.03.2010','w','anna@example.com','','',
              'M-002','ja','','',
              'sportler','','',
              'B-Mannschaft','','' ],
            [ 'Peter','Trainer','30.11.1985','m','peter@example.com','','',
              '','ja','','peter.trainer',
              'trainer','','trainer',
              'A-Mannschaft; B-Mannschaft','','Kraftsport' ],
        ];
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="personen-vorlage.csv"' );
        $fp = fopen( 'php://output', 'w' );
        fwrite( $fp, "\xEF\xBB\xBF" ); // BOM für Excel
        foreach ( $rows as $r ) fputcsv( $fp, $r, ';', '"', '\\' );
        fclose( $fp );
        exit;
    }

    // ── CSV-Helfer ───────────────────────────────────────────────────────────

    /**
     * Zählt die Kandidaten in der Kopfzeile — außerhalb von Anführungszeichen,
     * damit ein 'Nachname, Vorname' im Header nicht das Komma gewinnen lässt.
     */
    private static function detect_delimiter( $text ) {
        $text  = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
        $text  = str_replace( [ "\r\n", "\r" ], "\n", $text );
        $erste = strtok( $text, "\n" );
        if ( $erste === false ) return ';';

        $zaehler = [ ';' => 0, ',' => 0, "\t" => 0, '|' => 0 ];
        $in_quote = false;
        $len = strlen( $erste );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $erste[ $i ];
            if ( $c === '"' ) { $in_quote = ! $in_quote; continue; }
            if ( ! $in_quote && isset( $zaehler[ $c ] ) ) $zaehler[ $c ]++;
        }
        arsort( $zaehler );
        $bester = array_key_first( $zaehler );
        return $zaehler[ $bester ] > 0 ? $bester : ';';
    }

    /**
     * CSV in ein Array von Zeilen zerlegen. Läuft über einen Stream statt
     * zeilenweise, damit Felder mit Zeilenumbrüchen in Anführungszeichen
     * korrekt zusammenbleiben.
     */
    private static function parse_raw( $text, $delim ) {
        $text = preg_replace( '/^\xEF\xBB\xBF/', '', $text );
        $fp   = fopen( 'php://temp', 'r+' );
        fwrite( $fp, $text );
        rewind( $fp );
        $rows = [];
        while ( ( $zeile = fgetcsv( $fp, 0, $delim, '"', '\\' ) ) !== false ) {
            // fgetcsv liefert [null] für Leerzeilen
            if ( $zeile === [ null ] ) continue;
            if ( count( array_filter( $zeile, fn( $v ) => trim( (string) $v ) !== '' ) ) === 0 ) continue;
            $rows[] = $zeile;
        }
        fclose( $fp );
        return $rows;
    }

    /**
     * Vorbelegung der Zuordnung anhand der Kopfzeile.
     * Erst exakte Alias-Treffer, dann Teilstring-Treffer.
     */
    private static function auto_mapping( $header ) {
        $felder  = self::csv_felder();
        $mapping = array_fill( 0, count( $header ), '' );
        $vergeben = [];

        $normalisieren = function( $s ) {
            $s = self::lower( trim( (string) $s ) );
            $s = strtr( $s, [ 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ] );
            return trim( preg_replace( '/[\s._\-,;()\[\]]+/', ' ', $s ) );
        };

        foreach ( [ 'exakt', 'teil' ] as $runde ) {
            foreach ( $header as $i => $name ) {
                if ( $mapping[ $i ] !== '' ) continue;
                $h = $normalisieren( $name );
                if ( $h === '' ) continue;
                foreach ( $felder as $key => $feld ) {
                    if ( isset( $vergeben[ $key ] ) && empty( $feld['mehrfach'] ) ) continue;
                    foreach ( $feld['aliase'] as $alias ) {
                        $a = $normalisieren( $alias );
                        $treffer = $runde === 'exakt' ? ( $h === $a ) : ( strpos( $h, $a ) !== false );
                        if ( ! $treffer ) continue;
                        $mapping[ $i ]   = $key;
                        $vergeben[ $key ] = true;
                        continue 3;
                    }
                }
            }
        }
        return $mapping;
    }

    /** Unbekannte Feldschlüssel und Mehrfach-Belegungen aussortieren. */
    private static function clean_mapping( $mapping ) {
        $felder   = self::csv_felder();
        $out      = [];
        $vergeben = [];
        foreach ( array_values( $mapping ) as $i => $key ) {
            $key = is_string( $key ) ? trim( $key ) : '';
            if ( $key === '' || ! isset( $felder[ $key ] ) ) { $out[ $i ] = ''; continue; }
            if ( isset( $vergeben[ $key ] ) && empty( $felder[ $key ]['mehrfach'] ) ) { $out[ $i ] = ''; continue; }
            $vergeben[ $key ] = true;
            $out[ $i ] = $key;
        }
        return $out;
    }

    /**
     * Eine CSV-Zeile anhand der Zuordnung in einen Personen-Datensatz
     * übersetzen. Gibt null zurück, wenn die Zeile komplett leer ist.
     */
    private static function zeile_aufbauen( $zeile, $mapping, $opt ) {
        $felder = self::csv_felder();

        // Werte je Zielfeld einsammeln (mehrere Spalten → mehrere Werte)
        $werte = [];
        $leer  = true;
        foreach ( $mapping as $i => $key ) {
            if ( $key === '' ) continue;
            $wert = trim( (string) ( $zeile[ $i ] ?? '' ) );
            if ( $wert !== '' ) $leer = false;
            $werte[ $key ][] = $wert;
        }
        if ( $leer ) return null;

        $einzel = function( $key ) use ( $werte ) {
            foreach ( $werte[ $key ] ?? [] as $w ) {
                if ( $w !== '' ) return $w;
            }
            return '';
        };
        $alle = function( $key ) use ( $werte ) {
            return array_values( array_filter( $werte[ $key ] ?? [], fn( $w ) => $w !== '' ) );
        };

        $fehler = [];

        // ── Name ─────────────────────────────────────────────────────────────
        $vorname  = $einzel( 'vorname' );
        $nachname = $einzel( 'nachname' );
        if ( ( $vorname === '' || $nachname === '' ) && $einzel( 'name_voll' ) !== '' ) {
            list( $v2, $n2 ) = self::split_name( $einzel( 'name_voll' ) );
            if ( $vorname === '' )  $vorname  = $v2;
            if ( $nachname === '' ) $nachname = $n2;
        }
        if ( $vorname === '' || $nachname === '' ) {
            $fehler[] = 'Vor- oder Nachname fehlt';
        }

        // ── Stammdaten ───────────────────────────────────────────────────────
        $geburtsdatum = '';
        if ( isset( $werte['geburtsdatum'] ) && $einzel( 'geburtsdatum' ) !== '' ) {
            $geburtsdatum = self::parse_datum( $einzel( 'geburtsdatum' ) );
            if ( $geburtsdatum === '' ) {
                $fehler[] = 'Geburtsdatum "' . $einzel( 'geburtsdatum' ) . '" nicht lesbar';
            }
        }

        $email = $einzel( 'email' );
        if ( $email !== '' && ! is_email( $email ) ) {
            $fehler[] = 'E-Mail "' . $email . '" ist ungültig';
            $email = '';
        }

        $row = [
            'vorname'         => $vorname,
            'nachname'        => $nachname,
            'geburtsdatum'    => $geburtsdatum,
            'geschlecht'      => LSV07I_Personen::clean_gender( $einzel( 'geschlecht' ) ) ?? '',
            'email'           => $email,
            'telefon'         => $einzel( 'telefon' ),
            'dsv_id'          => $einzel( 'dsv_id' ),
            'mitgliedsnummer' => $einzel( 'mitgliedsnummer' ),
            'notes'           => implode( "\n", $alle( 'notizen' ) ),
            '_hat'            => [], // welche Felder die CSV überhaupt liefert
        ];
        foreach ( [ 'vorname', 'nachname', 'geburtsdatum', 'geschlecht', 'email',
                    'telefon', 'dsv_id', 'mitgliedsnummer', 'notes' ] as $f ) {
            $quelle = $f === 'notes' ? 'notizen' : $f;
            $row['_hat'][ $f ] = isset( $werte[ $quelle ] )
                || ( in_array( $f, [ 'vorname', 'nachname' ], true ) && isset( $werte['name_voll'] ) );
        }

        // ── Aktiv-Kennzeichen ────────────────────────────────────────────────
        $row['aktiv']         = 1;
        $row['_hat']['aktiv'] = isset( $werte['aktiv'] );
        if ( $row['_hat']['aktiv'] ) {
            $row['aktiv'] = self::parse_bool( $einzel( 'aktiv' ) );
        }

        // ── WordPress-Konto ──────────────────────────────────────────────────
        $row['wp_user_id']         = 0;
        $row['wp_user_name']       = '';
        $row['_hat']['wp_user_id'] = isset( $werte['wp_user'] );
        if ( $row['_hat']['wp_user_id'] && $einzel( 'wp_user' ) !== '' ) {
            $such = $einzel( 'wp_user' );
            $user = is_numeric( $such ) ? get_user_by( 'id', (int) $such ) : null;
            if ( ! $user ) $user = get_user_by( 'login', $such );
            if ( ! $user ) $user = get_user_by( 'email', $such );
            if ( ! $user ) {
                $fehler[] = 'WordPress-Konto "' . $such . '" nicht gefunden';
                $row['_hat']['wp_user_id'] = false;
            } else {
                $row['wp_user_id']   = (int) $user->ID;
                $row['wp_user_name'] = $user->display_name ?: $user->user_login;
            }
        }

        // ── Sparten & Rollen ─────────────────────────────────────────────────
        $sparten_rollen = [];
        foreach ( LSV07I_Personen::SPARTEN as $sparte ) {
            foreach ( $alle( 'rolle_' . $sparte ) as $wert ) {
                $rolle = self::parse_rolle( $wert );
                if ( $rolle === null ) {
                    $fehler[] = 'Rolle "' . $wert . '" bei ' . $sparte . ' unbekannt';
                    continue;
                }
                if ( $rolle === '' ) continue; // "nein"/"-" → keine Zuordnung
                $sparten_rollen[ $sparte . '|' . $rolle ] = [ 'sparte' => $sparte, 'rolle' => $rolle ];
            }
        }
        foreach ( $alle( 'rollen_kombi' ) as $roh ) {
            foreach ( preg_split( '/[,;|]+/', $roh ) as $teil ) {
                $teil = trim( $teil );
                if ( $teil === '' ) continue;
                if ( strpos( $teil, ':' ) === false ) {
                    $fehler[] = 'Rollen-Eintrag "' . $teil . '" braucht das Format sparte:rolle';
                    continue;
                }
                list( $sp, $ro ) = array_map( 'trim', explode( ':', $teil, 2 ) );
                $sp    = self::lower( $sp );
                $rolle = self::parse_rolle( $ro );
                if ( ! in_array( $sp, LSV07I_Personen::SPARTEN, true ) ) {
                    $fehler[] = 'Unbekannte Sparte: ' . $sp;
                    continue;
                }
                if ( $rolle === null || $rolle === '' ) {
                    $fehler[] = 'Unbekannte Rolle: ' . $ro;
                    continue;
                }
                $sparten_rollen[ $sp . '|' . $rolle ] = [ 'sparte' => $sp, 'rolle' => $rolle ];
            }
        }

        // ── Mannschaften ─────────────────────────────────────────────────────
        $mannschaften = [];
        foreach ( LSV07I_Personen::SPARTEN as $sparte ) {
            foreach ( $alle( 'mannschaft_' . $sparte ) as $roh ) {
                foreach ( self::namen_aufteilen( $roh ) as $name ) {
                    $mannschaften[] = self::mannschaft_aufloesen( $sparte, $name );
                }
            }
        }
        foreach ( $alle( 'mannschaften_kombi' ) as $roh ) {
            foreach ( explode( '|', $roh ) as $teil ) {
                $teil = trim( $teil );
                if ( $teil === '' ) continue;
                if ( strpos( $teil, ':' ) === false ) {
                    $fehler[] = 'Mannschafts-Eintrag "' . $teil . '" braucht das Format sparte:name';
                    continue;
                }
                list( $sp, $name ) = array_map( 'trim', explode( ':', $teil, 2 ) );
                $sp = self::lower( $sp );
                if ( ! in_array( $sp, LSV07I_Personen::SPARTEN, true ) ) {
                    $fehler[] = 'Unbekannte Sparte: ' . $sp;
                    continue;
                }
                foreach ( self::namen_aufteilen( $name ) as $einzelname ) {
                    $mannschaften[] = self::mannschaft_aufloesen( $sp, $einzelname );
                }
            }
        }
        foreach ( $mannschaften as $m ) {
            if ( ! empty( $m['fehler'] ) ) $fehler[] = $m['fehler'];
        }

        // Mannschaft ohne passende Sparten-Rolle → implizit als Sportler führen,
        // sonst läge die Person in einer Mannschaft ohne Sparten-Zugehörigkeit.
        foreach ( $mannschaften as $m ) {
            if ( empty( $m['mannschaft_id'] ) ) continue;
            $hat_sparte = false;
            foreach ( $sparten_rollen as $sr ) {
                if ( $sr['sparte'] === $m['sparte'] ) { $hat_sparte = true; break; }
            }
            if ( ! $hat_sparte ) {
                $sparten_rollen[ $m['sparte'] . '|sportler' ] = [ 'sparte' => $m['sparte'], 'rolle' => 'sportler' ];
            }
        }

        $row['sparten_rollen'] = array_values( $sparten_rollen );
        $row['mannschaften']   = $mannschaften;

        // ── Abgleich mit bestehenden Personen ────────────────────────────────
        $match = self::match_person( $row, $opt['abgleich'] );
        $row['_match_id']  = $match ? (int) $match->id : 0;
        $row['_match_str'] = $match
            ? trim( $match->vorname . ' ' . $match->nachname ) . ' (#' . $match->id . ')'
            : '';

        if ( ! $row['_match_id'] && $opt['nur_update'] ) {
            $fehler[] = 'Keine bestehende Person gefunden (Modus „nur aktualisieren")';
        }

        $row['_fehler'] = implode( ' · ', $fehler );
        return $row;
    }

    /** "Mustermann, Max" bzw. "Max Mustermann" auftrennen. */
    private static function split_name( $voll ) {
        $voll = trim( preg_replace( '/\s+/', ' ', $voll ) );
        if ( $voll === '' ) return [ '', '' ];
        if ( strpos( $voll, ',' ) !== false ) {
            list( $nach, $vor ) = array_map( 'trim', explode( ',', $voll, 2 ) );
            return [ $vor, $nach ];
        }
        $teile = explode( ' ', $voll );
        if ( count( $teile ) === 1 ) return [ '', $teile[0] ];
        $nach = array_pop( $teile );
        return [ implode( ' ', $teile ), $nach ];
    }

    /** Mehrere Mannschaftsnamen in einer Zelle trennen. */
    private static function namen_aufteilen( $roh ) {
        $teile = preg_split( '/[;,]+/', (string) $roh );
        return array_values( array_filter( array_map( 'trim', $teile ), fn( $n ) => $n !== '' ) );
    }

    /** Mannschaftsname → ID in der jeweiligen Sparte. */
    private static function mannschaft_aufloesen( $sparte, $name ) {
        global $wpdb;
        $tab = self::mannschaft_tabelle( $sparte );
        $out = [ 'sparte' => $sparte, 'name' => $name, 'mannschaft_id' => 0 ];
        if ( ! $tab || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tab ) ) ) {
            $out['fehler'] = 'Für ' . $sparte . ' gibt es keine Mannschaftsverwaltung';
            return $out;
        }
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `$tab` WHERE LOWER(name) = LOWER(%s) LIMIT 1", $name
        ) );
        if ( ! $id ) {
            $out['fehler'] = 'Mannschaft "' . $name . '" (' . $sparte . ') existiert nicht';
            return $out;
        }
        $out['mannschaft_id'] = (int) $id;
        return $out;
    }

    /**
     * Rolle deuten. Rückgabe: 'sportler'|'trainer' bei Treffer, '' wenn die
     * Zelle bewusst leer/verneint ist, null bei unverständlichem Wert.
     */
    private static function parse_rolle( $wert ) {
        $w = self::lower( trim( (string) $wert ) );
        if ( $w === '' || in_array( $w, [ '-', '0', 'nein', 'no', 'false', 'n' ], true ) ) return '';
        if ( in_array( $w, [ 'sportler', 'sportlerin', 'athlet', 'athletin', 'schwimmer',
                             'schwimmerin', 'aktiv', 'aktive', 'mitglied' ], true ) ) return 'sportler';
        if ( in_array( $w, [ 'trainer', 'trainerin', 'coach', 'uebungsleiter',
                             'übungsleiter', 'betreuer', 'betreuerin' ], true ) ) return 'trainer';
        // "ja"/"1"/"x" in einer Sparten-Spalte heißt: dabei, Rolle Sportler
        if ( in_array( $w, [ '1', 'ja', 'yes', 'x', 'true', 'j' ], true ) ) return 'sportler';
        return null;
    }

    private static function parse_bool( $wert ) {
        $w = self::lower( trim( (string) $wert ) );
        if ( $w === '' ) return 1;
        if ( in_array( $w, [ '0', 'nein', 'no', 'false', 'n', 'inaktiv', 'ausgetreten', '-' ], true ) ) return 0;
        return 1;
    }

    /** Datumsformate deuten. Leerer String = nicht lesbar. */
    private static function parse_datum( $wert ) {
        $wert = trim( (string) $wert );
        if ( $wert === '' ) return '';
        if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $wert, $m ) ) {
            return self::datum_bauen( $m[1], $m[2], $m[3] );
        }
        if ( preg_match( '#^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})$#', $wert, $m ) ) {
            $jahr = (int) $m[3];
            if ( $jahr < 100 ) $jahr += $jahr <= (int) date( 'y' ) ? 2000 : 1900;
            return self::datum_bauen( $jahr, $m[2], $m[1] );
        }
        // Excel-Seriennummer (Tage seit 1900-01-00)
        if ( ctype_digit( $wert ) && (int) $wert > 1000 && (int) $wert < 80000 ) {
            return gmdate( 'Y-m-d', ( (int) $wert - 25569 ) * 86400 );
        }
        return '';
    }

    private static function datum_bauen( $j, $m, $t ) {
        $j = (int) $j; $m = (int) $m; $t = (int) $t;
        if ( ! checkdate( $m, $t, $j ) ) return '';
        return sprintf( '%04d-%02d-%02d', $j, $m, $t );
    }

    /** Bestehende Person nach dem gewählten Kriterium suchen. */
    private static function match_person( $row, $abgleich ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $sel = "SELECT id, vorname, nachname FROM {$p}lsv07i_personen";

        if ( $abgleich === 'dsv_id' ) {
            if ( $row['dsv_id'] === '' ) return null;
            return $wpdb->get_row( $wpdb->prepare( "$sel WHERE dsv_id = %s LIMIT 1", $row['dsv_id'] ) );
        }
        if ( $abgleich === 'mitgliedsnummer' ) {
            if ( $row['mitgliedsnummer'] === '' ) return null;
            return $wpdb->get_row( $wpdb->prepare(
                "$sel WHERE mitgliedsnummer = %s LIMIT 1", $row['mitgliedsnummer'] ) );
        }
        if ( $abgleich === 'email' ) {
            if ( $row['email'] === '' ) return null;
            return $wpdb->get_row( $wpdb->prepare(
                "$sel WHERE email <> '' AND LOWER(email) = LOWER(%s) LIMIT 1", $row['email'] ) );
        }
        if ( $row['vorname'] === '' || $row['nachname'] === '' ) return null;
        if ( $abgleich === 'name_jahr' && $row['geburtsdatum'] !== '' ) {
            return $wpdb->get_row( $wpdb->prepare(
                "$sel WHERE LOWER(vorname) = LOWER(%s) AND LOWER(nachname) = LOWER(%s)
                        AND YEAR(geburtsdatum) = %d LIMIT 1",
                $row['vorname'], $row['nachname'], (int) substr( $row['geburtsdatum'], 0, 4 )
            ) );
        }
        return $wpdb->get_row( $wpdb->prepare(
            "$sel WHERE LOWER(vorname) = LOWER(%s) AND LOWER(nachname) = LOWER(%s) LIMIT 1",
            $row['vorname'], $row['nachname']
        ) );
    }

    /**
     * Eine geprüfte Zeile schreiben.
     *
     * Beim Aktualisieren werden ausschließlich Felder überschrieben, die in
     * der CSV auch zugeordnet sind. Alles andere — insbesondere die
     * WordPress-Verknüpfung und die Notizen — bleibt unangetastet.
     */
    private static function commit_zeile( $row, $opt ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $bestand = [];
        if ( $row['_match_id'] ) {
            $bestand = (array) $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_personen WHERE id = %d", $row['_match_id']
            ), ARRAY_A );
            if ( ! $bestand ) return new WP_Error( 'weg', 'Person wurde zwischenzeitlich gelöscht.' );
        }

        $daten = [
            'vorname'         => $bestand['vorname']         ?? '',
            'nachname'        => $bestand['nachname']        ?? '',
            'geburtsdatum'    => $bestand['geburtsdatum']    ?? '',
            'geschlecht'      => $bestand['geschlecht']      ?? '',
            'email'           => $bestand['email']           ?? '',
            'telefon'         => $bestand['telefon']         ?? '',
            'dsv_id'          => $bestand['dsv_id']          ?? '',
            'mitgliedsnummer' => $bestand['mitgliedsnummer'] ?? '',
            'notes'           => $bestand['notes']           ?? '',
            'wp_user_id'      => (int) ( $bestand['wp_user_id'] ?? 0 ),
            'aktiv'           => (int) ( $bestand['aktiv']      ?? 1 ),
        ];

        foreach ( [ 'vorname', 'nachname', 'geburtsdatum', 'geschlecht', 'email',
                    'telefon', 'dsv_id', 'mitgliedsnummer', 'notes', 'aktiv', 'wp_user_id' ] as $feld ) {
            if ( empty( $row['_hat'][ $feld ] ) ) continue;
            $neu = $feld === 'wp_user_id' ? $row['wp_user_id'] : $row[ $feld ];
            // Leere Zelle überspringen, damit ein Import ohne Wert nichts löscht
            if ( $opt['leere_ueber'] && ( $neu === '' || $neu === null ) ) continue;
            $daten[ $feld ] = $neu;
        }

        // Ein WP-Konto darf nur einer Person gehören
        if ( $daten['wp_user_id'] > 0 ) {
            $belegt = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_personen WHERE wp_user_id = %d AND id <> %d LIMIT 1",
                $daten['wp_user_id'], (int) $row['_match_id']
            ) );
            if ( $belegt ) {
                return new WP_Error( 'wp_belegt',
                    'Das WordPress-Konto ist bereits Person #' . (int) $belegt . ' zugeordnet.' );
            }
        }

        $res = LSV07I_Personen::speichern( $daten, $row['_match_id'] ?: null );
        if ( is_wp_error( $res ) ) return $res;
        $person_id = (int) $res;

        $rollen = $row['sparten_rollen'];
        $manns  = array_values( array_filter( $row['mannschaften'], fn( $m ) => ! empty( $m['mannschaft_id'] ) ) );

        if ( $opt['zuordnung'] === 'ersetzen' ) {
            // Nur ersetzen, wenn die CSV überhaupt etwas liefert — sonst würde
            // ein Import ohne Zuordnungs-Spalten alle Zuordnungen löschen.
            if ( ! empty( $rollen ) ) LSV07I_Personen::set_sparten_rollen( $person_id, $rollen );
            if ( ! empty( $manns ) ) {
                $liste = [];
                foreach ( $manns as $m ) {
                    $liste[] = [
                        'sparte'        => $m['sparte'],
                        'mannschaft_id' => (int) $m['mannschaft_id'],
                        'rolle'         => self::rolle_fuer_sparte( $rollen, $m['sparte'] ),
                    ];
                }
                LSV07I_Personen::set_mannschaften( $person_id, $liste );
            }
        } else {
            foreach ( $rollen as $sr ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}lsv07i_personen_sparten_rolle
                       (person_id, sparte, rolle, aktiv) VALUES (%d, %s, %s, 1)",
                    $person_id, $sr['sparte'], $sr['rolle']
                ) );
            }
            foreach ( $manns as $m ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}lsv07i_personen_mannschaft
                       (person_id, sparte, mannschaft_id, rolle) VALUES (%d, %s, %d, %s)",
                    $person_id, $m['sparte'], (int) $m['mannschaft_id'],
                    self::rolle_fuer_sparte( $rollen, $m['sparte'] )
                ) );
            }
        }

        return $person_id;
    }

    /** Ist die Person in der Sparte Trainer, gilt das auch in der Mannschaft. */
    private static function rolle_fuer_sparte( $sparten_rollen, $sparte ) {
        foreach ( $sparten_rollen as $sr ) {
            if ( $sr['sparte'] === $sparte && $sr['rolle'] === 'trainer' ) return 'trainer';
        }
        return 'sportler';
    }
}
