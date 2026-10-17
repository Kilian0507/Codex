<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Attestverwaltung
 * ----------------------------
 * Modul „Atteste": Mannschaft für Mannschaft, Sportlerin für Sportler —
 * wann läuft das sportärztliche Attest ab, wo liegt es, und wer muss
 * erinnert werden.
 *
 *   Ablaufdatum    direkt im Feld änderbar (lsv07i_mb-frei, gespeichert
 *                  wird in mv_swimmers.attest_expires — derselben Spalte,
 *                  aus der die Akte, die Meldungen, der Reiter „Atteste"
 *                  und die nächtliche Erinnerung lesen)
 *   Datei          PDF oder Bild je Sportler
 *   Archiv         ältere Atteste bleiben erhalten, angehängt ist immer
 *                  nur das neueste
 *   Erinnerung     Mail an die Kontaktperson, mit der Vorlage aus
 *                  Admin → Atteste
 *
 * Gespeichert werden die Dateien über LSV07I_Schwimmer_Dateien — im
 * geschützten Ordner, mit derselben Prüfung von Typ und Grösse wie bei
 * den übrigen Schwimmer-Dateien. Hier kommen nur zwei Spalten dazu:
 * `art` kennzeichnet ein Attest, `aktuell` das angehängte.
 *
 * Rechte: attest.read zum Sehen, attest.edit zum Ändern und Hochladen,
 * attest.mail zum Erinnern (das alte Recht „Attest-Erinnerungen
 * verschicken" aus dem Admin-Bereich gilt weiterhin mit). Geschrieben
 * wird immer nur für die eigenen Mannschaften; Admin und Schwimmwart
 * dürfen für alle.
 */

class LSV07I_Ajax_Attestverwaltung {

    /** Kennzeichen in lsv07i_schwimmer_dateien.art */
    const ART = 'attest';

    /** Beschriftungen der vier Zustände. */
    const ZUSTAENDE = [
        'gueltig'    => 'Gültig',
        'laeuft_ab'  => 'Läuft ab',
        'abgelaufen' => 'Abgelaufen',
        'keines'     => 'Kein Attest',
    ];

    public static function init() {
        $map = [
            'lsv07i_attv_basis'   => 'basis',
            'lsv07i_attv_liste'   => 'liste',
            'lsv07i_attv_datum'   => 'datum_setzen',
            'lsv07i_attv_upload'  => 'upload',
            'lsv07i_attv_archiv'  => 'archiv',
            'lsv07i_attv_del'     => 'loeschen',
            'lsv07i_attv_mail'    => 'mail',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $methode ] );
        }
        // Der Download läuft über GET, damit ein Link im Browser reicht.
        add_action( 'wp_ajax_lsv07i_attv_datei', [ __CLASS__, 'datei' ] );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Tabelle
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Die beiden Spalten nachrüsten, die ein Attest von einer sonstigen
     * Schwimmer-Datei unterscheiden. Läuft einmal pro Request.
     */
    private static function spalten_sicherstellen() {
        static $geprueft = false;
        if ( $geprueft ) return;
        $geprueft = true;
        self::spalten_nachruesten( $GLOBALS['wpdb']->prefix );
    }

    /** 8.30.0 — art und aktuell in lsv07i_schwimmer_dateien. */
    public static function spalten_nachruesten( $p ) {
        global $wpdb;
        $spalten = $wpdb->get_results( "SHOW COLUMNS FROM {$p}lsv07i_schwimmer_dateien" );
        if ( empty( $spalten ) ) return;
        $namen = wp_list_pluck( $spalten, 'Field' );
        if ( ! in_array( 'art', $namen, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}lsv07i_schwimmer_dateien
                           ADD COLUMN art VARCHAR(20) NOT NULL DEFAULT '' AFTER schwimmer_id" );
        }
        if ( ! in_array( 'aktuell', $namen, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}lsv07i_schwimmer_dateien
                           ADD COLUMN aktuell TINYINT(1) NOT NULL DEFAULT 1 AFTER art" );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Rechte
    // ═══════════════════════════════════════════════════════════════════════

    private static function kann( $recht ) {
        if ( LSV07I_Access::is_admin() ) return true;
        return class_exists( 'LSV07I_Permissions' ) && LSV07I_Permissions::can_current( $recht );
    }

    /** Lesen: eigenes Recht oder die Bereichsrolle, wie bei der Anwesenheit. */
    private static function darf_lesen() {
        return LSV07I_Access::is_intern() || self::kann( LSV07I_Permissions::ATTEST_READ );
    }

    private static function darf_schreiben() {
        return LSV07I_Access::is_intern() || self::kann( LSV07I_Permissions::ATTEST_EDIT );
    }

    /** Erinnern: das Modulrecht oder das alte Recht aus dem Admin-Bereich. */
    private static function darf_mailen() {
        return self::kann( LSV07I_Permissions::ATTEST_MAIL )
            || self::kann( LSV07I_Permissions::ADMIN_ATTEST_MAIL );
    }

    /**
     * Mannschaften, die der Nutzer sehen darf. null = alle.
     * Dieselbe Regel wie im Mannschaftsbuch: Trainer sehen die eigenen.
     */
    private static function erlaubte_mannschaften() {
        if ( LSV07I_Access::is_admin_raw() || LSV07I_Access::is_schwimmwart() ) return null;
        $tid = (int) LSV07I_Access::get_trainer_id();
        if ( ! $tid ) return [];
        $ids = LSV07I_DB::get_trainer_mannschaften( $tid );
        return array_values( array_filter( array_map( 'intval', (array) $ids ), fn( $i ) => $i > 0 ) );
    }

    /**
     * Darf der Nutzer diesen Sportler sehen? Entscheidend ist seine
     * Mannschaft — wer zu keiner gehört, bleibt den Verwaltenden
     * vorbehalten.
     */
    private static function darf_schwimmer( $schwimmer_id ) {
        global $wpdb;
        $erlaubt = self::erlaubte_mannschaften();
        if ( $erlaubt === null ) return true;
        if ( ! $erlaubt ) return false;
        $team = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}mv_swimmers WHERE id = %d", (int) $schwimmer_id ) );
        return $team && in_array( $team, $erlaubt, true );
    }

    /** Bricht ab, wenn der Sportler nicht zum Nutzer gehört. */
    private static function pruefe_schwimmer( $schwimmer_id ) {
        if ( ! $schwimmer_id ) wp_send_json_error( [ 'message' => 'Keine Person angegeben.' ] );
        if ( ! self::darf_schwimmer( $schwimmer_id ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Person fehlt die Berechtigung.' ] );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Hilfen
    // ═══════════════════════════════════════════════════════════════════════

    /** Datum prüfen; leer gibt null (= kein Attest hinterlegt). */
    private static function datum( $wert ) {
        $wert = trim( (string) $wert );
        if ( $wert === '' || $wert === '0000-00-00' ) return null;
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $t ) ) return false;
        return checkdate( (int) $t[2], (int) $t[3], (int) $t[1] ) ? $wert : false;
    }

    /** Das angehängte Attest eines Sportlers (oder null). */
    private static function aktuelles_attest( $schwimmer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, dateiname, mime_type, groesse, hochgeladen_am, hochgeladen_von
               FROM {$p}lsv07i_schwimmer_dateien
              WHERE schwimmer_id = %d AND art = %s AND aktuell = 1
           ORDER BY hochgeladen_am DESC, id DESC LIMIT 1",
            (int) $schwimmer_id, self::ART ), ARRAY_A );
    }

    /** Wie viele ältere Atteste liegen im Archiv? */
    private static function archiv_zahl( $schwimmer_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lsv07i_schwimmer_dateien
              WHERE schwimmer_id = %d AND art = %s AND aktuell = 0",
            (int) $schwimmer_id, self::ART ) );
    }

    /**
     * Die Adresse, an die erinnert wird: die der ersten Kontaktperson.
     * Gibt es keine, gilt die des Sportlers selbst — lieber an ihn als
     * gar nicht.
     */
    private static function empfaenger( $schwimmer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.email AS eigene,
                    ( SELECT k.email FROM {$p}lsv07i_kontakte k
                       WHERE k.swimmer_id = s.id AND k.email <> ''
                    ORDER BY k.sort_order ASC, k.id ASC LIMIT 1 ) AS kontakt,
                    ( SELECT k.name FROM {$p}lsv07i_kontakte k
                       WHERE k.swimmer_id = s.id AND k.email <> ''
                    ORDER BY k.sort_order ASC, k.id ASC LIMIT 1 ) AS kontakt_name
               FROM {$p}mv_swimmers s WHERE s.id = %d", (int) $schwimmer_id ), ARRAY_A );
        if ( ! $row ) return [ 'email' => '', 'name' => '', 'quelle' => '' ];
        if ( ! empty( $row['kontakt'] ) ) {
            return [ 'email' => $row['kontakt'], 'name' => (string) $row['kontakt_name'], 'quelle' => 'kontakt' ];
        }
        if ( ! empty( $row['eigene'] ) ) {
            return [ 'email' => $row['eigene'], 'name' => '', 'quelle' => 'eigene' ];
        }
        return [ 'email' => '', 'name' => '', 'quelle' => '' ];
    }

    private static function person( $uid ) {
        $uid = (int) $uid;
        if ( ! $uid ) return '';
        $u = get_userdata( $uid );
        return $u ? $u->display_name : '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Endpunkte
    // ═══════════════════════════════════════════════════════════════════════

    public static function basis() {
        LSV07I_Access::check( 'attest_read' );
        self::spalten_sicherstellen();

        $erlaubt = self::erlaubte_mannschaften();
        $mannschaften = [];
        foreach ( (array) LSV07I_DB::get_mannschaften() as $g ) {
            if ( $erlaubt !== null && ! in_array( (int) $g['id'], $erlaubt, true ) ) continue;
            $mannschaften[] = [ 'id' => (int) $g['id'], 'name' => $g['name'] ];
        }

        wp_send_json_success( [
            'mannschaften'   => $mannschaften,
            'zustaende'      => self::ZUSTAENDE,
            'warnung_tage'   => (int) LSV07I_DB::get_config( 'attest_warnung_tage', 30 ),
            'darf_schreiben' => self::darf_schreiben(),
            'darf_mailen'    => self::darf_mailen(),
            'max_mb'         => round( LSV07I_Schwimmer_Dateien::MAX_SIZE / 1048576 ),
        ] );
    }

    /**
     * Die Liste: Mannschaft für Mannschaft, darin die Sportler mit Datum,
     * Zustand, angehängtem Attest, Archivzahl und Empfänger.
     */
    public static function liste() {
        LSV07I_Access::check( 'attest_read' );
        self::spalten_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $nur_mann = absint( $_POST['mannschaft_id'] ?? 0 );
        $zustand  = sanitize_text_field( $_POST['zustand'] ?? '' );
        $suche    = trim( sanitize_text_field( wp_unslash( $_POST['suche'] ?? '' ) ) );

        $erlaubt = self::erlaubte_mannschaften();
        if ( $erlaubt !== null && ! $erlaubt ) {
            wp_send_json_success( [ 'mannschaften' => [], 'zahl' => [] ] );
        }
        if ( $nur_mann && $erlaubt !== null && ! in_array( $nur_mann, $erlaubt, true ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ] );
        }

        $wo = [ 's.active = 1' ];
        if ( $nur_mann )           $wo[] = $wpdb->prepare( 's.team_id = %d', $nur_mann );
        elseif ( $erlaubt !== null ) $wo[] = 's.team_id IN (' . implode( ',', $erlaubt ) . ')';
        if ( $suche !== '' ) {
            $wie = '%' . $wpdb->esc_like( $suche ) . '%';
            $wo[] = $wpdb->prepare( '(s.first_name LIKE %s OR s.last_name LIKE %s)', $wie, $wie );
        }

        $rows = $wpdb->get_results(
            "SELECT s.id, s.first_name, s.last_name, s.birth_date, s.team_id,
                    s.attest_expires, g.name AS mannschaft_name
               FROM {$p}mv_swimmers s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
              WHERE " . implode( ' AND ', $wo ) . "
           ORDER BY g.sort_order ASC, g.name ASC, s.last_name ASC, s.first_name ASC",
            ARRAY_A );

        $gruppen = [];
        $zahl    = array_fill_keys( array_keys( self::ZUSTAENDE ), 0 );

        foreach ( (array) $rows as $s ) {
            $status = LSV07I_DB::attest_status( $s['attest_expires'] );
            if ( $zustand !== '' && $status !== $zustand ) continue;
            $zahl[ $status ] = ( $zahl[ $status ] ?? 0 ) + 1;

            $datei = self::aktuelles_attest( (int) $s['id'] );
            $emp   = self::empfaenger( (int) $s['id'] );
            $jahr  = (int) substr( (string) $s['birth_date'], 0, 4 );

            $schluessel = (int) $s['team_id'];
            if ( ! isset( $gruppen[ $schluessel ] ) ) {
                $gruppen[ $schluessel ] = [
                    'mannschaft_id' => $schluessel,
                    'name'          => $s['mannschaft_name'] ?: 'Ohne Mannschaft',
                    'schwimmer'     => [],
                ];
            }
            $gruppen[ $schluessel ]['schwimmer'][] = [
                'id'          => (int) $s['id'],
                'name'        => trim( $s['last_name'] . ', ' . $s['first_name'] ),
                'jahrgang'    => ( $jahr >= 1900 && $jahr <= 2100 ) ? $jahr : 0,
                'datum'       => $s['attest_expires'] ?: '',
                'status'      => $status,
                'datei'       => $datei ? [
                    'id'        => (int) $datei['id'],
                    'name'      => $datei['dateiname'],
                    'mime'      => $datei['mime_type'],
                    'groesse'   => (int) $datei['groesse'],
                    'am'        => $datei['hochgeladen_am'],
                    'von'       => self::person( $datei['hochgeladen_von'] ),
                ] : null,
                'archiv'      => self::archiv_zahl( (int) $s['id'] ),
                'empfaenger'  => $emp['email'],
                'kontakt_name'=> $emp['name'],
                'an_kontakt'  => $emp['quelle'] === 'kontakt' ? 1 : 0,
            ];
        }

        wp_send_json_success( [
            'mannschaften' => array_values( $gruppen ),
            'zahl'         => $zahl,
        ] );
    }

    /** Das Ablaufdatum ändern — leer heisst: kein Attest hinterlegt. */
    public static function datum_setzen() {
        LSV07I_Access::check( 'attest_edit' );
        self::spalten_sicherstellen();
        global $wpdb;

        $sid = absint( $_POST['schwimmer_id'] ?? 0 );
        self::pruefe_schwimmer( $sid );

        $datum = self::datum( $_POST['datum'] ?? '' );
        if ( $datum === false ) wp_send_json_error( [ 'message' => 'Das ist kein gültiges Datum.' ] );

        $ok = $wpdb->update( $wpdb->prefix . 'mv_swimmers',
            [ 'attest_expires' => $datum ], [ 'id' => $sid ], [ '%s' ], [ '%d' ] );
        if ( $ok === false ) {
            wp_send_json_error( [ 'message' => 'Konnte nicht gespeichert werden: '
                . ( $wpdb->last_error ?: 'unbekannter Grund' ) ] );
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'attest.datum', [
                'bereich'  => 'Atteste',
                'ziel_typ' => 'schwimmer',
                'ziel_id'  => $sid,
                'details'  => $datum ?: 'entfernt',
            ] );
        }

        wp_send_json_success( [
            'datum'   => $datum ?: '',
            'status'  => LSV07I_DB::attest_status( $datum ),
            'message' => $datum ? 'Datum gespeichert.' : 'Datum entfernt.',
        ] );
    }

    /**
     * Ein Attest hochladen. Das bisherige wandert dabei ins Archiv —
     * angehängt ist immer nur das neueste.
     */
    public static function upload() {
        LSV07I_Access::check( 'attest_edit' );
        self::spalten_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $sid = absint( $_POST['schwimmer_id'] ?? 0 );
        self::pruefe_schwimmer( $sid );

        if ( empty( $_FILES['datei'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Datei übermittelt.' ] );
        }

        // Ein mitgeschicktes Datum gilt als neues Ablaufdatum — beim
        // Hochladen steht es meist auf dem Attest.
        $datum = self::datum( $_POST['datum'] ?? '' );
        if ( $datum === false ) wp_send_json_error( [ 'message' => 'Das ist kein gültiges Datum.' ] );

        $id = LSV07I_Schwimmer_Dateien::store_upload( $sid, $_FILES['datei'], 'Attest' );
        if ( is_wp_error( $id ) ) {
            wp_send_json_error( [ 'message' => $id->get_error_message() ] );
        }

        // Erst alles Alte ins Archiv, dann das Neue anhängen — in dieser
        // Reihenfolge, damit nie zwei Atteste zugleich als aktuell gelten.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}lsv07i_schwimmer_dateien SET aktuell = 0
              WHERE schwimmer_id = %d AND art = %s AND id <> %d",
            $sid, self::ART, (int) $id ) );
        $wpdb->update( $p . 'lsv07i_schwimmer_dateien',
            [ 'art' => self::ART, 'aktuell' => 1 ],
            [ 'id' => (int) $id ], [ '%s', '%d' ], [ '%d' ] );

        if ( $datum ) {
            $wpdb->update( $p . 'mv_swimmers', [ 'attest_expires' => $datum ],
                [ 'id' => $sid ], [ '%s' ], [ '%d' ] );
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'attest.upload', [
                'bereich'   => 'Atteste',
                'ziel_typ'  => 'schwimmer',
                'ziel_id'   => $sid,
                'ziel_name' => sanitize_file_name( $_FILES['datei']['name'] ?? '?' ),
                'details'   => 'Datei-ID ' . (int) $id . ( $datum ? ', gültig bis ' . $datum : '' ),
            ] );
        }

        $aktuell = self::aktuelles_attest( $sid );
        wp_send_json_success( [
            'datei'   => $aktuell ? [
                'id'      => (int) $aktuell['id'],
                'name'    => $aktuell['dateiname'],
                'mime'    => $aktuell['mime_type'],
                'groesse' => (int) $aktuell['groesse'],
                'am'      => $aktuell['hochgeladen_am'],
                'von'     => self::person( $aktuell['hochgeladen_von'] ),
            ] : null,
            'archiv'  => self::archiv_zahl( $sid ),
            'datum'   => $datum ?: (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT attest_expires FROM {$p}mv_swimmers WHERE id = %d", $sid ) ),
            'message' => 'Attest hochgeladen.',
        ] );
    }

    /** Das Archiv eines Sportlers: alle früheren Atteste. */
    public static function archiv() {
        LSV07I_Access::check( 'attest_read' );
        self::spalten_sicherstellen();
        global $wpdb;

        $sid = absint( $_POST['schwimmer_id'] ?? 0 );
        self::pruefe_schwimmer( $sid );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, dateiname, mime_type, groesse, hochgeladen_am, hochgeladen_von, aktuell
               FROM {$wpdb->prefix}lsv07i_schwimmer_dateien
              WHERE schwimmer_id = %d AND art = %s AND aktuell = 0
           ORDER BY hochgeladen_am DESC, id DESC",
            $sid, self::ART ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'      => (int) $r['id'],
                'name'    => $r['dateiname'],
                'mime'    => $r['mime_type'],
                'groesse' => (int) $r['groesse'],
                'am'      => $r['hochgeladen_am'],
                'von'     => self::person( $r['hochgeladen_von'] ),
            ];
        }
        wp_send_json_success( [ 'dateien' => $out ] );
    }

    /**
     * Ein Attest löschen. War es das angehängte, rückt das jüngste aus
     * dem Archiv nach — sonst stünde da plötzlich gar keines mehr,
     * obwohl welche vorhanden sind.
     */
    public static function loeschen() {
        LSV07I_Access::check( 'attest_edit' );
        self::spalten_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $id  = absint( $_POST['id'] ?? 0 );
        $row = $id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT id, schwimmer_id, art, aktuell, dateiname
               FROM {$p}lsv07i_schwimmer_dateien WHERE id = %d", $id ), ARRAY_A ) : null;
        if ( ! $row || $row['art'] !== self::ART ) {
            wp_send_json_error( [ 'message' => 'Attest nicht gefunden.' ] );
        }
        $sid = (int) $row['schwimmer_id'];
        self::pruefe_schwimmer( $sid );

        $war_aktuell = (int) $row['aktuell'] === 1;
        LSV07I_Schwimmer_Dateien::delete( $id );

        if ( $war_aktuell ) {
            $nach = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_schwimmer_dateien
                  WHERE schwimmer_id = %d AND art = %s
               ORDER BY hochgeladen_am DESC, id DESC LIMIT 1",
                $sid, self::ART ), ARRAY_A );
            if ( $nach ) {
                $wpdb->update( $p . 'lsv07i_schwimmer_dateien', [ 'aktuell' => 1 ],
                    [ 'id' => (int) $nach['id'] ], [ '%d' ], [ '%d' ] );
            }
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'attest.delete', [
                'bereich'   => 'Atteste',
                'ziel_typ'  => 'schwimmer',
                'ziel_id'   => $sid,
                'ziel_name' => $row['dateiname'],
            ] );
        }

        $aktuell = self::aktuelles_attest( $sid );
        wp_send_json_success( [
            'datei'   => $aktuell ? [
                'id'      => (int) $aktuell['id'],
                'name'    => $aktuell['dateiname'],
                'mime'    => $aktuell['mime_type'],
                'groesse' => (int) $aktuell['groesse'],
                'am'      => $aktuell['hochgeladen_am'],
                'von'     => self::person( $aktuell['hochgeladen_von'] ),
            ] : null,
            'archiv'  => self::archiv_zahl( $sid ),
            'message' => 'Attest gelöscht.',
        ] );
    }

    /**
     * Ein Attest herunterladen. Eigener Weg statt des allgemeinen
     * Datei-Downloads: Hier entscheidet das Attest-Recht und die
     * Mannschaft, und es kommen nur Atteste heraus — kein beliebiger
     * Griff in die Schwimmer-Dateien.
     */
    public static function datei() {
        $nonce = $_REQUEST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'lsv07i_nonce' ) ) {
            status_header( 403 ); echo 'Ungültige Anfrage.'; exit;
        }
        if ( ! is_user_logged_in() || ! self::darf_lesen() ) {
            status_header( 403 ); echo 'Keine Berechtigung.'; exit;
        }
        self::spalten_sicherstellen();

        $id  = absint( $_REQUEST['id'] ?? 0 );
        $row = $id ? LSV07I_Schwimmer_Dateien::get( $id ) : null;
        if ( ! $row || ( $row['art'] ?? '' ) !== self::ART ) {
            status_header( 404 ); echo 'Attest nicht gefunden.'; exit;
        }
        if ( ! self::darf_schwimmer( (int) $row['schwimmer_id'] ) ) {
            status_header( 403 ); echo 'Keine Berechtigung.'; exit;
        }
        if ( ! file_exists( $row['path'] ) ) {
            status_header( 404 ); echo 'Datei nicht gefunden.'; exit;
        }

        nocache_headers();
        header( 'Content-Type: ' . $row['mime_type'] );
        header( 'Content-Length: ' . filesize( $row['path'] ) );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( $row['dateiname'] ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        while ( ob_get_level() > 0 ) ob_end_clean();
        readfile( $row['path'] );
        exit;
    }

    /**
     * Erinnerung an die Kontaktperson. Betreff und Text kommen aus
     * derselben Vorlage wie im Reiter Admin → Atteste — es soll nicht
     * zwei verschiedene Erinnerungen desselben Vereins geben.
     */
    public static function mail() {
        LSV07I_Access::check( 'attest_mail' );
        self::spalten_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $sid = absint( $_POST['schwimmer_id'] ?? 0 );
        self::pruefe_schwimmer( $sid );

        $s = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, first_name, last_name, attest_expires
               FROM {$p}mv_swimmers WHERE id = %d", $sid ), ARRAY_A );
        if ( ! $s ) wp_send_json_error( [ 'message' => 'Person nicht gefunden.' ] );

        $status = LSV07I_DB::attest_status( $s['attest_expires'] );
        if ( $status === 'gueltig' ) {
            wp_send_json_error( [ 'message' => 'Dieses Attest ist noch gültig — eine Erinnerung wäre verwirrend.' ] );
        }

        $emp = self::empfaenger( $sid );
        if ( $emp['email'] === '' || ! is_email( $emp['email'] ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Person ist keine E-Mail-Adresse hinterlegt.' ] );
        }

        $betreff = LSV07I_DB::get_config( 'attest_mail_betreff',
            'Erinnerung: Sportärztliches Attest läuft ab' );
        $text = LSV07I_DB::get_config( 'attest_mail_text',
            "Hallo {vorname},\n\ndas sportärztliche Attest von {name} läuft am {datum} ab bzw. ist bereits abgelaufen.\n\nBitte reiche zeitnah ein aktuelles Attest ein.\n\nSportliche Grüße\nDein LSV 07" );

        $datum_de = $s['attest_expires']
            ? date_i18n( 'd.m.Y', strtotime( $s['attest_expires'] ) ) : '-';
        $ersatz = [
            '{name}'     => trim( $s['first_name'] . ' ' . $s['last_name'] ),
            '{vorname}'  => $s['first_name'],
            '{nachname}' => $s['last_name'],
            '{datum}'    => $datum_de,
        ];

        $ok = wp_mail( $emp['email'], strtr( $betreff, $ersatz ), strtr( $text, $ersatz ) );
        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => 'Der Versand ist fehlgeschlagen (Mailserver).' ] );
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'attest.mail', [
                'bereich'   => 'Atteste',
                'ziel_typ'  => 'schwimmer',
                'ziel_id'   => $sid,
                'ziel_name' => $ersatz['{name}'],
                'details'   => $emp['email'],
            ] );
        }

        wp_send_json_success( [
            'message' => 'Erinnerung an ' . $emp['email'] . ' gesendet.',
        ] );
    }
}
