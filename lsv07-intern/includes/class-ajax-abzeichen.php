<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Abzeichen (seit 9.13.0).
 *
 * Eine Mannschaft kann einen eigenen Abzeichen-Katalog haben (Bezeichnung,
 * optional ein Bild). Abzeichen werden den Schwimmern dieser Mannschaft
 * zugewiesen; ein Schwimmer kann mehrere haben.
 *
 * Wer darf was:
 *  - SEHEN: alle Trainer der Mannschaft (wer im Schwimmbereich ist und die
 *    Mannschaft nach der zentralen Zuständigkeit sehen darf), Schwimmwart
 *    und Admin.
 *  - ANLEGEN, ÄNDERN, LÖSCHEN, VERGEBEN: nur mit dem Recht „Abzeichen
 *    verwalten" — und nur für die EIGENEN Mannschaften (zentrale
 *    Zuständigkeit). Der Admin darf immer.
 *
 * Bilder werden beim Hochladen neu gezeichnet (GD, höchstens 256 Pixel,
 * PNG) — so landet kein eingeschleuster Inhalt und keine Metadaten im
 * Speicher — und verschlüsselt abgelegt (LSV07I_Tresor). Ausgeliefert wird
 * nur nach derselben Leseprüfung.
 */
class LSV07I_Ajax_Abzeichen {

    const MAX_ABZEICHEN = 200;      // je Mannschaft
    const MAX_NAME      = 80;
    const MAX_BYTES     = 3145728;  // 3 MB Upload
    const MAX_PIXEL     = 4000;     // Kantenlänge des Originals (Schutz vor Riesenbildern)
    const KANTE         = 256;      // gespeichertes Bild höchstens so gross

    public static function init() {
        foreach ( [ 'mannschaften', 'daten', 'speichern', 'loeschen', 'vergeben', 'bild' ] as $a ) {
            add_action( 'wp_ajax_lsv07i_abz_' . $a, [ __CLASS__, $a ] );
        }
    }

    // ── Tabellen ─────────────────────────────────────────────────────────
    public static function tabellen_sicherstellen() {
        static $ok = false;
        if ( $ok ) return;
        $ok = true;
        global $wpdb;
        $p = $wpdb->prefix;
        $cs = $wpdb->get_charset_collate();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_abzeichen'" ) ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abzeichen (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id INT UNSIGNED NOT NULL,
                name          VARCHAR(80) NOT NULL DEFAULT '',
                bild          VARCHAR(100) NOT NULL DEFAULT '',
                sortierung    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_mannschaft (mannschaft_id, sortierung)
            ) $cs" );
        }
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_abzeichen_vergabe'" ) ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abzeichen_vergabe (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abzeichen_id INT UNSIGNED NOT NULL,
                swimmer_id   INT UNSIGNED NOT NULL,
                vergeben_am  DATE NOT NULL,
                vergeben_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_vergabe (abzeichen_id, swimmer_id),
                KEY idx_swimmer (swimmer_id)
            ) $cs" );
        }
    }

    // ── Rechte ───────────────────────────────────────────────────────────
    /** Darf die Person in dieser Mannschaft Abzeichen anlegen und vergeben? */
    public static function darf_verwalten( $mannschaft_id ) {
        $mannschaft_id = (int) $mannschaft_id;
        if ( $mannschaft_id <= 0 ) return false;
        if ( LSV07I_Permissions::gesperrt( get_current_user_id() ) ) return false;
        if ( LSV07I_Access::is_admin() ) return true;
        return LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_ABZEICHEN_VERWALTEN )
            && LSV07I_Access::darf_mannschaft( $mannschaft_id );
    }

    /** Darf die Person die Abzeichen dieser Mannschaft sehen? */
    public static function darf_sehen( $mannschaft_id ) {
        $mannschaft_id = (int) $mannschaft_id;
        if ( $mannschaft_id <= 0 ) return false;
        if ( LSV07I_Permissions::gesperrt( get_current_user_id() ) ) return false;
        return LSV07I_Access::is_intern() && LSV07I_Access::darf_mannschaft( $mannschaft_id );
    }

    /**
     * Für den Reiter: Gibt es für diese Person überhaupt etwas zu sehen?
     * Ja, wer verwalten darf — oder wer eine Mannschaft sieht, die schon
     * Abzeichen hat. Andere Trainer bekommen keinen leeren Reiter.
     */
    public static function reiter_sichtbar() {
        if ( LSV07I_Permissions::gesperrt( get_current_user_id() ) ) return false;
        if ( LSV07I_Access::is_admin() ) return true;
        if ( LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_ABZEICHEN_VERWALTEN ) ) return true;
        if ( ! LSV07I_Access::is_intern() ) return false;
        global $wpdb;
        $p = $wpdb->prefix;
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_abzeichen'" ) ) return false;
        $erlaubt = LSV07I_Access::erlaubte_mannschaften();
        if ( $erlaubt === null ) return (bool) $wpdb->get_var( "SELECT 1 FROM {$p}lsv07i_abzeichen LIMIT 1" );
        if ( ! $erlaubt ) return false;
        return (bool) $wpdb->get_var( "SELECT 1 FROM {$p}lsv07i_abzeichen WHERE "
            . LSV07I_Access::sql_in( 'mannschaft_id', $erlaubt ) . " LIMIT 1" );
    }

    private static function tor() {
        LSV07I_Access::check( 'intern' );
        self::tabellen_sicherstellen();
    }

    private static function abzeichen( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07i_abzeichen WHERE id = %d", (int) $id ), ARRAY_A );
    }

    /** Gehört der Schwimmer (aktiv) zu dieser Mannschaft — Stamm oder zusätzliche Gruppe? */
    private static function schwimmer_in_mannschaft( $swimmer_id, $mannschaft_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}mv_swimmers s
              WHERE s.id = %d AND s.active = 1
                AND ( s.team_id = %d
                      OR EXISTS ( SELECT 1 FROM {$p}lsv07i_swimmer_gruppen g
                                   WHERE g.swimmer_id = s.id AND g.gruppe_id = %d ) )
              LIMIT 1",
            (int) $swimmer_id, (int) $mannschaft_id, (int) $mannschaft_id ) );
    }

    // ── Bild-Ablage ──────────────────────────────────────────────────────
    public static function ordner() {
        $up   = wp_upload_dir();
        $priv = trailingslashit( $up['basedir'] ) . 'lsv07i-private';
        $dir  = $priv . '/abzeichen';
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        if ( ! file_exists( $priv . '/.htaccess' ) ) {
            @file_put_contents( $priv . '/.htaccess', "# LSV07-Intern: Direktzugriff blockieren\nOrder Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }
        if ( ! file_exists( $priv . '/index.html' ) ) @file_put_contents( $priv . '/index.html', '<!-- silence -->' );
        if ( ! file_exists( $dir . '/index.html' ) ) @file_put_contents( $dir . '/index.html', '<!-- silence -->' );
        return $dir;
    }

    private static function bild_pfad( $name ) {
        $name = (string) $name;
        if ( ! preg_match( '/^abz-[A-Za-z0-9]{16}\.png$/', $name ) ) return '';
        return self::ordner() . '/' . $name;
    }

    /**
     * Hochgeladenes Bild prüfen, neu zeichnen (höchstens KANTE Pixel, PNG
     * mit Transparenz) und verschlüsselt ablegen. Liefert den Dateinamen
     * oder einen Fehlertext (beginnt mit „!").
     */
    private static function bild_annehmen( array $datei ) {
        if ( ! empty( $datei['error'] ) ) return '!' . LSV07I_Meldung::upload( (int) $datei['error'], 3 * 1048576 );
        $tmp = (string) ( $datei['tmp_name'] ?? '' );
        if ( $tmp === '' || ( ! is_uploaded_file( $tmp ) && ! defined( 'LSV07I_PRUEFSTAND' ) ) ) return '!Keine Datei empfangen.';
        if ( (int) $datei['size'] > self::MAX_BYTES || filesize( $tmp ) > self::MAX_BYTES ) return '!Das Bild ist zu groß (maximal 3 MB).';
        $info = @getimagesize( $tmp );
        $mime = $info['mime'] ?? '';
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true ) ) {
            return '!Nur JPG, PNG, WebP oder GIF sind erlaubt.';
        }
        if ( $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_PIXEL || $info[1] > self::MAX_PIXEL ) {
            return '!Das Bild ist zu groß (höchstens ' . self::MAX_PIXEL . ' Pixel Kantenlänge).';
        }
        if ( ! function_exists( 'imagecreatetruecolor' ) ) return '!Bilder können auf diesem Server nicht verarbeitet werden.';
        switch ( $mime ) {
            case 'image/jpeg': $q = @imagecreatefromjpeg( $tmp ); break;
            case 'image/png':  $q = @imagecreatefrompng( $tmp ); break;
            case 'image/gif':  $q = @imagecreatefromgif( $tmp ); break;
            case 'image/webp': $q = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $tmp ) : false; break;
            default:           $q = false;
        }
        if ( ! $q ) return '!Das Bild konnte nicht gelesen werden.';
        $bw = imagesx( $q ); $bh = imagesy( $q );
        $f  = min( 1, self::KANTE / max( $bw, $bh ) );
        $zw = max( 1, (int) round( $bw * $f ) ); $zh = max( 1, (int) round( $bh * $f ) );
        $z  = imagecreatetruecolor( $zw, $zh );
        imagealphablending( $z, false );
        imagesavealpha( $z, true );
        imagefilledrectangle( $z, 0, 0, $zw, $zh, imagecolorallocatealpha( $z, 0, 0, 0, 127 ) );
        imagecopyresampled( $z, $q, 0, 0, 0, 0, $zw, $zh, $bw, $bh );
        imagedestroy( $q );
        ob_start();
        $ok = imagepng( $z, null, 6 );
        $png = ob_get_clean();
        imagedestroy( $z );
        if ( ! $ok || ! $png ) return '!Das Bild konnte nicht verarbeitet werden.';
        $name = 'abz-' . wp_generate_password( 16, false, false ) . '.png';
        if ( ! LSV07I_Tresor::datei_ablegen( self::ordner() . '/' . $name, $png ) ) {
            return '!Das Bild konnte nicht gespeichert werden.';
        }
        return $name;
    }

    private static function bild_loeschen( $name ) {
        $pfad = self::bild_pfad( $name );
        if ( $pfad && is_file( $pfad ) ) @unlink( $pfad );
    }

    /** Dateiname → Bezeichnung: ohne Pfad und ohne Endung, bereinigt. */
    public static function name_aus_datei( $datei ) {
        /* Erst bereinigen (Tags weg), dann den Pfad abschneiden — sonst
           zerlegte ein „/" in einem Tag den Namen. Ältere Browser schicken
           den ganzen Pfad mit Backslashes. */
        $datei = trim( sanitize_text_field( (string) $datei ) );
        $datei = preg_replace( '#^.*[/\\\\]#', '', $datei );
        $name  = trim( preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', $datei ) );
        if ( mb_strlen( $name ) > self::MAX_NAME ) $name = rtrim( mb_substr( $name, 0, self::MAX_NAME ) );
        return $name;
    }

    // ── Endpunkte ────────────────────────────────────────────────────────
    /** Mannschaften, die man sehen darf — mit „darf verwalten" je Mannschaft. */
    public static function mannschaften() {
        self::tor();
        global $wpdb;
        $p = $wpdb->prefix;
        $erlaubt = LSV07I_Access::erlaubte_mannschaften();
        if ( $erlaubt !== null && ! $erlaubt ) wp_send_json_success( [ 'mannschaften' => [] ] );
        $wo = $erlaubt === null ? '1=1' : LSV07I_Access::sql_in( 'g.id', $erlaubt );
        $rows = $wpdb->get_results(
            "SELECT g.id, g.name,
                    ( SELECT COUNT(*) FROM {$p}lsv07i_abzeichen a WHERE a.mannschaft_id = g.id ) AS anzahl
               FROM {$p}lsv07_gruppen g WHERE $wo ORDER BY g.name ASC", ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [ 'id' => (int) $r['id'], 'name' => $r['name'], 'anzahl' => (int) $r['anzahl'],
                       'darf_verwalten' => self::darf_verwalten( (int) $r['id'] ) ];
        }
        wp_send_json_success( [ 'mannschaften' => $out ] );
    }

    /** Katalog, Schwimmer und Vergaben einer Mannschaft. */
    public static function daten() {
        self::tor();
        $mid = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! self::darf_sehen( $mid ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;
        $name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$p}lsv07_gruppen WHERE id = %d", $mid ) );
        $abz = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, bild FROM {$p}lsv07i_abzeichen WHERE mannschaft_id = %d ORDER BY sortierung ASC, id ASC", $mid ), ARRAY_A );
        $abz = array_map( fn( $a ) => [ 'id' => (int) $a['id'], 'name' => $a['name'], 'hat_bild' => $a['bild'] !== '' ], (array) $abz );
        $sw = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.first_name, s.last_name, s.birth_date
               FROM {$p}mv_swimmers s
              WHERE s.active = 1
                AND ( s.team_id = %d OR EXISTS ( SELECT 1 FROM {$p}lsv07i_swimmer_gruppen g
                                                  WHERE g.swimmer_id = s.id AND g.gruppe_id = %d ) )
           ORDER BY s.last_name ASC, s.first_name ASC", $mid, $mid ), ARRAY_A );
        $schwimmer = array_map( fn( $s ) => [
            'id'       => (int) $s['id'],
            'name'     => trim( $s['first_name'] . ' ' . $s['last_name'] ),
            'sortname' => trim( $s['last_name'] . ', ' . $s['first_name'], ', ' ),
            'jahrgang' => $s['birth_date'] ? substr( (string) $s['birth_date'], 0, 4 ) : '',
        ], (array) $sw );
        $vergaben = [];
        if ( $abz && $schwimmer ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT v.abzeichen_id, v.swimmer_id, v.vergeben_am
                   FROM {$p}lsv07i_abzeichen_vergabe v
                   JOIN {$p}lsv07i_abzeichen a ON a.id = v.abzeichen_id
                  WHERE a.mannschaft_id = %d", $mid ), ARRAY_A );
            $ids = array_flip( array_column( $schwimmer, 'id' ) );
            foreach ( (array) $rows as $r ) {
                if ( ! isset( $ids[ (int) $r['swimmer_id'] ] ) ) continue;   // nicht mehr in der Mannschaft
                $vergaben[] = [ 'abzeichen_id' => (int) $r['abzeichen_id'], 'swimmer_id' => (int) $r['swimmer_id'],
                                'vergeben_am' => (string) $r['vergeben_am'] ];
            }
        }
        wp_send_json_success( [
            'mannschaft'     => [ 'id' => $mid, 'name' => $name ],
            'abzeichen'      => $abz,
            'schwimmer'      => $schwimmer,
            'vergaben'       => $vergaben,
            'darf_verwalten' => self::darf_verwalten( $mid ),
        ] );
    }

    /** Abzeichen anlegen oder ändern (Bezeichnung, Bild). */
    public static function speichern() {
        self::tor();
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( $id ) {
            $alt = self::abzeichen( $id );
            if ( ! $alt ) wp_send_json_error( [ 'message' => 'Das Abzeichen gibt es nicht mehr.' ], 404 );
            $mid = (int) $alt['mannschaft_id'];
        } else {
            $alt = null;
            $mid = absint( $_POST['mannschaft_id'] ?? 0 );
        }
        if ( ! self::darf_verwalten( $mid ) ) {
            wp_send_json_error( [ 'message' => 'Abzeichen dieser Mannschaft darfst du nicht verwalten.' ], 403 );
        }
        $name = trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
        /* Nur ein Bild hochgeladen (auch mehrere nacheinander): Der Name ist
           der Dateiname ohne Endung — „Seepferdchen Gold.png" wird
           „Seepferdchen Gold". */
        if ( $name === '' && ! $id && ! empty( $_FILES['bild']['name'] ) ) {
            $name = self::name_aus_datei( (string) $_FILES['bild']['name'] );
        }
        if ( $name === '' ) wp_send_json_error( [ 'message' => 'Bitte eine Bezeichnung angeben.' ] );
        if ( mb_strlen( $name ) > self::MAX_NAME ) wp_send_json_error( [ 'message' => 'Die Bezeichnung ist zu lang (höchstens ' . self::MAX_NAME . ' Zeichen).' ] );
        if ( ! $id ) {
            $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}lsv07i_abzeichen WHERE mannschaft_id = %d", $mid ) );
            if ( $n >= self::MAX_ABZEICHEN ) wp_send_json_error( [ 'message' => 'Mehr als ' . self::MAX_ABZEICHEN . ' Abzeichen je Mannschaft sind nicht vorgesehen.' ] );
        }

        $bild = $alt ? (string) $alt['bild'] : '';
        $neues_bild = null;
        if ( ! empty( $_FILES['bild'] ) && ( $_FILES['bild']['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE ) {
            $erg = self::bild_annehmen( $_FILES['bild'] );
            if ( $erg[0] === '!' ) wp_send_json_error( [ 'message' => substr( $erg, 1 ) ] );
            $neues_bild = $erg;
        }
        $bild_weg = ! empty( $_POST['bild_weg'] );

        if ( $id ) {
            $daten = [ 'name' => $name ];
            if ( $neues_bild !== null ) $daten['bild'] = $neues_bild;
            elseif ( $bild_weg )        $daten['bild'] = '';
            $wpdb->update( $p . 'lsv07i_abzeichen', $daten, [ 'id' => $id ] );
            if ( ( $neues_bild !== null || $bild_weg ) && $bild !== '' ) self::bild_loeschen( $bild );
        } else {
            $sort = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sortierung),0)+1 FROM {$p}lsv07i_abzeichen WHERE mannschaft_id = %d", $mid ) );
            $wpdb->insert( $p . 'lsv07i_abzeichen', [
                'mannschaft_id' => $mid, 'name' => $name, 'bild' => $neues_bild ?? '',
                'sortierung' => min( 65535, $sort ), 'erstellt_von' => get_current_user_id(),
            ] );
            $id = (int) $wpdb->insert_id;
            if ( ! $id ) {
                if ( $neues_bild ) self::bild_loeschen( $neues_bild );
                wp_send_json_error( [ 'message' => LSV07I_Meldung::datenbank( 'Das Abzeichen konnte nicht gespeichert werden.' ) ] );
            }
        }
        LSV07I_Log::write( $alt ? 'abzeichen.update' : 'abzeichen.create', [
            'bereich' => 'Schwimmen', 'ziel_typ' => 'abzeichen', 'ziel_id' => $id, 'ziel_name' => $name ] );
        wp_send_json_success( [ 'id' => $id ] );
    }

    /** Abzeichen löschen — samt Vergaben und Bild. */
    public static function loeschen() {
        self::tor();
        global $wpdb;
        $p  = $wpdb->prefix;
        $a  = self::abzeichen( absint( $_POST['id'] ?? 0 ) );
        if ( ! $a ) wp_send_json_error( [ 'message' => 'Das Abzeichen gibt es nicht mehr.' ], 404 );
        if ( ! self::darf_verwalten( (int) $a['mannschaft_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Abzeichen dieser Mannschaft darfst du nicht verwalten.' ], 403 );
        }
        $wpdb->delete( $p . 'lsv07i_abzeichen_vergabe', [ 'abzeichen_id' => (int) $a['id'] ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_abzeichen', [ 'id' => (int) $a['id'] ], [ '%d' ] );
        if ( $a['bild'] !== '' ) self::bild_loeschen( $a['bild'] );
        LSV07I_Log::write( 'abzeichen.delete', [
            'bereich' => 'Schwimmen', 'ziel_typ' => 'abzeichen', 'ziel_id' => (int) $a['id'], 'ziel_name' => $a['name'] ] );
        wp_send_json_success( [ 'message' => 'Abzeichen gelöscht.' ] );
    }

    /** Abzeichen einem Schwimmer geben (an=1) oder wegnehmen (an=0). */
    public static function vergeben() {
        self::tor();
        global $wpdb;
        $p   = $wpdb->prefix;
        $a   = self::abzeichen( absint( $_POST['abzeichen_id'] ?? 0 ) );
        $sid = absint( $_POST['swimmer_id'] ?? 0 );
        $an  = ! empty( $_POST['an'] );
        if ( ! $a ) wp_send_json_error( [ 'message' => 'Das Abzeichen gibt es nicht mehr.' ], 404 );
        $mid = (int) $a['mannschaft_id'];
        if ( ! self::darf_verwalten( $mid ) ) {
            wp_send_json_error( [ 'message' => 'Abzeichen dieser Mannschaft darfst du nicht vergeben.' ], 403 );
        }
        if ( $an && ! self::schwimmer_in_mannschaft( $sid, $mid ) ) {
            wp_send_json_error( [ 'message' => 'Der Schwimmer gehört nicht zu dieser Mannschaft.' ], 403 );
        }
        if ( $an ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$p}lsv07i_abzeichen_vergabe (abzeichen_id, swimmer_id, vergeben_am, vergeben_von)
                 VALUES (%d, %d, %s, %d)", (int) $a['id'], $sid, current_time( 'Y-m-d' ), get_current_user_id() ) );
        } else {
            $wpdb->delete( $p . 'lsv07i_abzeichen_vergabe', [ 'abzeichen_id' => (int) $a['id'], 'swimmer_id' => $sid ], [ '%d', '%d' ] );
        }
        LSV07I_Log::write( $an ? 'abzeichen.vergeben' : 'abzeichen.entzogen', [
            'bereich' => 'Schwimmen', 'ziel_typ' => 'schwimmer', 'ziel_id' => $sid, 'ziel_name' => $a['name'] ] );
        $am = $an ? (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT vergeben_am FROM {$p}lsv07i_abzeichen_vergabe WHERE abzeichen_id = %d AND swimmer_id = %d", (int) $a['id'], $sid ) ) : '';
        wp_send_json_success( [ 'an' => $an, 'vergeben_am' => $am ] );
    }

    /** Bild eines Abzeichens ausliefern (nur, wer die Mannschaft sehen darf). */
    public static function bild() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) { status_header( 403 ); exit; }
        self::tabellen_sicherstellen();
        $a = self::abzeichen( absint( $_GET['id'] ?? 0 ) );
        if ( ! $a || ! self::darf_sehen( (int) $a['mannschaft_id'] ) ) { status_header( 404 ); exit; }
        $pfad = self::bild_pfad( $a['bild'] );
        $inhalt = $pfad ? LSV07I_Tresor::datei_inhalt( $pfad ) : null;
        if ( $inhalt === null ) { status_header( 404 ); exit; }
        $info = @getimagesizefromstring( $inhalt );
        if ( ( $info['mime'] ?? '' ) !== 'image/png' ) { status_header( 404 ); exit; }
        nocache_headers();
        header( 'Content-Type: image/png' );
        header( 'X-Content-Type-Options: nosniff' );
        header( "Content-Security-Policy: default-src 'none'" );
        LSV07I_Tresor::ausgeben( $inhalt );
        exit;
    }
}
