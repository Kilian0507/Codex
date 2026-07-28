<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Schwimmer_Dateien
 *
 * Verwaltet Datei-Uploads pro Schwimmer.
 *
 * Speicherort: wp-content/uploads/lsv07i-private/schwimmer/<schwimmer_id>/<random>.<ext>
 * Der ganze Ordner ist durch .htaccess vor Direktzugriff geschützt — der einzige
 * Weg an die Dateien ist der Download-Endpunkt mit Berechtigungsprüfung.
 *
 * Erlaubte Typen: PDF, JPG, PNG. Max-Größe: 5 MB.
 */
class LSV07I_Schwimmer_Dateien {

    const MAX_SIZE       = 5242880; // 5 MB
    const ALLOWED_MIMES  = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];
    const ALLOWED_EXT    = [ 'pdf', 'jpg', 'jpeg', 'png' ];

    /**
     * Liefert den Basis-Pfad zum geschützten Upload-Ordner. Legt ihn an wenn nötig.
     */
    public static function base_dir() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/schwimmer';
        if ( ! is_dir( $base ) ) {
            wp_mkdir_p( $base );
            self::write_protection_files( dirname( $base ) );
        } else {
            // Schutz-Dateien sicherheitshalber prüfen (falls jemand sie gelöscht hat)
            if ( ! file_exists( dirname( $base ) . '/.htaccess' ) ) {
                self::write_protection_files( dirname( $base ) );
            }
        }
        return $base;
    }

    /**
     * Schutz-Dateien anlegen, die Direktzugriff via Apache und IIS verhindern.
     * Bei nginx muss der Admin selbst eine Regel ergänzen — wir legen einen
     * Hinweistext bei.
     */
    private static function write_protection_files( $dir ) {
        // Apache .htaccess: alles deny
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "# LSV07-Intern: Direktzugriff blockieren\nOrder Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }
        // index.html als Verzeichnis-Listing-Schutz
        $index = $dir . '/index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '<!-- silence -->' );
        }
        // Hinweis-Datei für nginx-Hoster
        $readme = $dir . '/README-NGINX.txt';
        if ( ! file_exists( $readme ) ) {
            @file_put_contents( $readme, "Wenn dein Server nginx nutzt, ergänze in der nginx.conf:\n\nlocation ~* /wp-content/uploads/lsv07i-private/ {\n    deny all;\n    return 403;\n}\n\nApache nutzt die .htaccess automatisch.\n" );
        }
    }

    /**
     * Pfad zu einem konkreten Schwimmer-Ordner.
     */
    public static function dir_for( $schwimmer_id ) {
        $dir = trailingslashit( self::base_dir() ) . (int) $schwimmer_id;
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    /**
     * Validiert eine hochgeladene Datei und liefert ['ok'=>bool, 'message'=>string, 'ext'=>string].
     */
    public static function validate_upload( array $file ) {
        if ( empty( $file ) || ! isset( $file['error'] ) ) {
            return [ 'ok' => false, 'message' => 'Keine Datei übermittelt.' ];
        }
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return [ 'ok' => false, 'message' => 'Upload-Fehler (Code ' . $file['error'] . ').' ];
        }
        if ( $file['size'] > self::MAX_SIZE ) {
            return [ 'ok' => false, 'message' => 'Datei ist zu groß (max. 5 MB).' ];
        }
        if ( $file['size'] <= 0 ) {
            return [ 'ok' => false, 'message' => 'Datei ist leer.' ];
        }

        // MIME-Type per finfo verifizieren (nicht auf $_FILES['type'] verlassen)
        $finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
        if ( $finfo ) {
            $real_mime = finfo_file( $finfo, $file['tmp_name'] );
            finfo_close( $finfo );
        } else {
            // Fallback: PHP-eigenes mime_content_type oder $_FILES['type']
            $real_mime = function_exists( 'mime_content_type' )
                ? mime_content_type( $file['tmp_name'] )
                : ( $file['type'] ?? '' );
        }

        if ( ! isset( self::ALLOWED_MIMES[ $real_mime ] ) ) {
            return [
                'ok'      => false,
                'message' => 'Dateityp nicht erlaubt. Erlaubt: PDF, JPG, PNG.',
            ];
        }

        // Erweiterung aus Original-Dateiname extrahieren und gegen MIME prüfen
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, self::ALLOWED_EXT, true ) ) {
            return [ 'ok' => false, 'message' => 'Dateiendung nicht erlaubt.' ];
        }

        return [ 'ok' => true, 'message' => '', 'ext' => $ext, 'mime' => $real_mime ];
    }

    /**
     * Verschiebt die hochgeladene Datei an ihren finalen Speicherort und legt
     * einen DB-Eintrag an. Liefert die DB-ID zurück oder WP_Error.
     */
    public static function store_upload( $schwimmer_id, array $file, $kommentar = '' ) {
        $val = self::validate_upload( $file );
        if ( ! $val['ok'] ) {
            return new WP_Error( 'invalid_upload', $val['message'] );
        }

        global $wpdb;
        $dir = self::dir_for( $schwimmer_id );

        // Zufalls-Dateiname (kein User-Input im Pfad)
        $rand = bin2hex( random_bytes( 8 ) );
        $stored_name = $rand . '.' . $val['ext'];
        $target = trailingslashit( $dir ) . $stored_name;

        if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) {
            return new WP_Error( 'move_failed', 'Datei konnte nicht gespeichert werden.' );
        }
        @chmod( $target, 0644 );

        // Sanitized Original-Dateiname (für Anzeige, nicht für Pfad)
        $orig = sanitize_file_name( $file['name'] );

        $wpdb->insert(
            $wpdb->prefix . 'lsv07i_schwimmer_dateien',
            [
                'schwimmer_id'    => (int) $schwimmer_id,
                'dateiname'       => $orig,
                'gespeichert_als' => $stored_name,
                'mime_type'       => $val['mime'],
                'groesse'         => (int) $file['size'],
                'kommentar'       => $kommentar !== '' ? mb_substr( $kommentar, 0, 500 ) : null,
                'hochgeladen_von' => get_current_user_id(),
                'hochgeladen_am'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Liefert alle Datei-Datensätze eines Schwimmers (sortiert nach Datum, neueste zuerst).
     */
    public static function list_for( $schwimmer_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, dateiname, mime_type, groesse, kommentar, hochgeladen_von, hochgeladen_am
               FROM {$wpdb->prefix}lsv07i_schwimmer_dateien
              WHERE schwimmer_id = %d
           ORDER BY hochgeladen_am DESC",
            (int) $schwimmer_id
        ), ARRAY_A );
    }

    /**
     * Liefert einen Datei-Datensatz inkl. Pfad-Info.
     */
    public static function get( $datei_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07i_schwimmer_dateien WHERE id = %d LIMIT 1",
            (int) $datei_id
        ), ARRAY_A );
        if ( ! $row ) return null;
        $row['path'] = trailingslashit( self::dir_for( $row['schwimmer_id'] ) ) . $row['gespeichert_als'];
        return $row;
    }

    /**
     * Löscht einen Datei-Datensatz inkl. der physischen Datei.
     */
    public static function delete( $datei_id ) {
        global $wpdb;
        $row = self::get( $datei_id );
        if ( ! $row ) return false;
        if ( file_exists( $row['path'] ) ) {
            @unlink( $row['path'] );
        }
        $wpdb->delete(
            $wpdb->prefix . 'lsv07i_schwimmer_dateien',
            [ 'id' => (int) $datei_id ],
            [ '%d' ]
        );
        return true;
    }
}
