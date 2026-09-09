<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_TP_Dateien
 *
 * PDF-Anhänge eines Trainingsplans (z. B. eingescannte Pläne, Vorlagen des
 * Verbands). Anders als bei den Wettkampf-Dokumenten gibt es keine festen
 * Typen — ein Plan kann beliebig viele PDFs haben, in der gespeicherten
 * Reihenfolge.
 *
 * Speicherort: wp-content/uploads/lsv07i-private/trainingsplan/<plan_id>/<random>.pdf
 * Der Ordner ist wie bei den übrigen privaten Dateien per .htaccess/index.html
 * vor Direktzugriff geschützt — der einzige Weg an die Datei führt über den
 * Download-Endpunkt in LSV07I_Ajax_Trainingsplan, der die Leseberechtigung
 * des Plans prüft.
 *
 * Erlaubter Typ: ausschließlich PDF, geprüft über die tatsächlichen Bytes.
 * Max-Größe: 20 MB (gescannte Pläne sind schnell groß).
 */
class LSV07I_TP_Dateien {

    const MAX_SIZE  = 20971520; // 20 MB
    const MAX_ANZAHL = 20;      // je Trainingsplan

    public static function base_dir() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/trainingsplan';
        if ( ! is_dir( $base ) ) {
            wp_mkdir_p( $base );
            self::write_protection_files( dirname( $base ) );
        } elseif ( ! file_exists( dirname( $base ) . '/.htaccess' ) ) {
            self::write_protection_files( dirname( $base ) );
        }
        return $base;
    }

    private static function write_protection_files( $dir ) {
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "# LSV07-Intern: Direktzugriff blockieren\nOrder Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }
        $index = $dir . '/index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '<!-- silence -->' );
        }
        $readme = $dir . '/README-NGINX.txt';
        if ( ! file_exists( $readme ) ) {
            @file_put_contents( $readme, "Wenn dein Server nginx nutzt, ergänze in der nginx.conf:\n\nlocation ~* /wp-content/uploads/lsv07i-private/ {\n    deny all;\n    return 403;\n}\n\nApache nutzt die .htaccess automatisch.\n" );
        }
    }

    public static function dir_for( $plan_id ) {
        $dir = trailingslashit( self::base_dir() ) . (int) $plan_id;
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        return $dir;
    }

    /** Legt die Tabelle an, falls sie fehlt. Läuft höchstens einmal je Request. */
    public static function tabelle_sicherstellen() {
        static $geprueft = false;
        if ( $geprueft ) return;
        $geprueft = true;
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_tp_datei'" ) ) return;
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tp_datei (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id          INT UNSIGNED NOT NULL,
            dateiname        VARCHAR(255) NOT NULL DEFAULT '',
            gespeichert_als  VARCHAR(100) NOT NULL DEFAULT '',
            groesse          INT UNSIGNED NOT NULL DEFAULT 0,
            sortierung       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            hochgeladen_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
            hochgeladen_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_plan (plan_id, sortierung)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    }

    /**
     * Prüft eine hochgeladene Datei. Ausschließlich PDF — geprüft über die
     * tatsächlichen Bytes (finfo), nicht über den vom Browser gesendeten
     * (fälschbaren) Typ.
     */
    public static function validate_upload( array $file ) {
        if ( empty( $file ) || ! isset( $file['error'] ) ) {
            return [ 'ok' => false, 'message' => 'Keine Datei übermittelt.' ];
        }
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return [ 'ok' => false, 'message' => 'Upload-Fehler (Code ' . $file['error'] . ').' ];
        }
        if ( $file['size'] > self::MAX_SIZE ) {
            return [ 'ok' => false, 'message' => 'Datei ist zu groß (max. 20 MB).' ];
        }
        if ( $file['size'] <= 0 ) {
            return [ 'ok' => false, 'message' => 'Datei ist leer.' ];
        }

        $finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
        if ( $finfo ) {
            $real_mime = finfo_file( $finfo, $file['tmp_name'] );
            finfo_close( $finfo );
        } else {
            $real_mime = function_exists( 'mime_content_type' )
                ? mime_content_type( $file['tmp_name'] )
                : ( $file['type'] ?? '' );
        }
        if ( $real_mime !== 'application/pdf' ) {
            return [ 'ok' => false, 'message' => 'Nur PDF-Dateien sind erlaubt.' ];
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( $ext !== 'pdf' ) {
            return [ 'ok' => false, 'message' => 'Nur die Dateiendung .pdf ist erlaubt.' ];
        }

        // Eine echte PDF beginnt mit "%PDF-". Verhindert, dass eine
        // umbenannte Fremddatei trotz passendem MIME-Sniffing durchrutscht.
        $head = @file_get_contents( $file['tmp_name'], false, null, 0, 5 );
        if ( $head !== '%PDF-' ) {
            return [ 'ok' => false, 'message' => 'Datei ist keine gültige PDF-Datei.' ];
        }

        return [ 'ok' => true, 'message' => '' ];
    }

    /** Speichert eine hochgeladene PDF für einen Trainingsplan. */
    public static function store_upload( $plan_id, array $file ) {
        self::tabelle_sicherstellen();
        $val = self::validate_upload( $file );
        if ( ! $val['ok'] ) {
            return new WP_Error( 'invalid_upload', $val['message'] );
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $anzahl = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_tp_datei WHERE plan_id = %d", $plan_id ) );
        if ( $anzahl >= self::MAX_ANZAHL ) {
            return new WP_Error( 'zu_viele', 'Mehr als ' . self::MAX_ANZAHL . ' PDFs je Plan sind nicht vorgesehen.' );
        }

        $dir         = self::dir_for( $plan_id );
        $stored_name = bin2hex( random_bytes( 8 ) ) . '.pdf';
        $target      = trailingslashit( $dir ) . $stored_name;

        if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) {
            return new WP_Error( 'move_failed', 'Datei konnte nicht gespeichert werden.' );
        }
        @chmod( $target, 0644 );

        $sort = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sortierung), -1) + 1 FROM {$p}lsv07i_tp_datei WHERE plan_id = %d", $plan_id ) );

        $wpdb->insert( $p . 'lsv07i_tp_datei', [
            'plan_id'         => (int) $plan_id,
            'dateiname'       => sanitize_file_name( $file['name'] ),
            'gespeichert_als' => $stored_name,
            'groesse'         => (int) $file['size'],
            'sortierung'      => $sort,
            'hochgeladen_von' => get_current_user_id(),
            'hochgeladen_am'  => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%d', '%d', '%d', '%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( ! $id ) {
            @unlink( $target );
            return new WP_Error( 'db_failed', 'Datei konnte nicht gespeichert werden.' );
        }
        return self::get( $id );
    }

    public static function get( $id ) {
        self::tabelle_sicherstellen();
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_tp_datei WHERE id = %d LIMIT 1", $id ), ARRAY_A );
        if ( ! $row ) return null;
        $row['path'] = trailingslashit( self::dir_for( $row['plan_id'] ) ) . $row['gespeichert_als'];
        return $row;
    }

    public static function list_for( $plan_id ) {
        self::tabelle_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, plan_id, dateiname, groesse
               FROM {$p}lsv07i_tp_datei
              WHERE plan_id = %d
           ORDER BY sortierung ASC, id ASC",
            $plan_id
        ), ARRAY_A ) ?: [];
    }

    public static function delete( $id ) {
        $row = self::get( $id );
        if ( ! $row ) return false;
        if ( ! empty( $row['path'] ) && file_exists( $row['path'] ) ) @unlink( $row['path'] );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'lsv07i_tp_datei', [ 'id' => (int) $id ], [ '%d' ] );
        return true;
    }

    /** Alle Dateien eines Plans entfernen (beim Löschen des Plans). */
    public static function delete_all_for( $plan_id ) {
        self::tabelle_sicherstellen();
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_tp_datei WHERE plan_id = %d", $plan_id ), ARRAY_A );
        foreach ( (array) $rows as $r ) self::delete( (int) $r['id'] );
        $dir = trailingslashit( self::base_dir() ) . (int) $plan_id;
        if ( is_dir( $dir ) ) @rmdir( $dir );
    }
}
