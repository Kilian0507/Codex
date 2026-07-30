<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Wettkampf_Dateien
 *
 * Verwaltet die drei PDF-Dokumente eines Wettkampfs: Ausschreibung,
 * Meldeergebnis, Protokoll. Ein Dokument je Typ — ein erneuter Upload
 * ersetzt die vorherige Datei (UNIQUE KEY wettkampf_id+typ in der DB).
 *
 * Speicherort: wp-content/uploads/lsv07i-private/wettkampf/<wettkampf_id>/<random>.pdf
 * Der Ordner ist wie bei den Schwimmer-Dateien per .htaccess/index.html vor
 * Direktzugriff geschützt — der einzige Weg an die Datei ist der
 * Download-Endpunkt in LSV07I_Ajax_Wettkampf mit eigener Berechtigungslogik
 * (Ausschreibung eines freigegebenen Wettkampfs ist öffentlich, alles
 * andere braucht Login + Leserecht).
 *
 * Erlaubter Typ: ausschließlich PDF. Max-Größe: 10 MB (Ausschreibungen/
 * Protokolle können mehrseitig mit Bildern sein).
 */
class LSV07I_Wettkampf_Dateien {

    const MAX_SIZE = 10485760; // 10 MB
    const TYPEN    = [ 'ausschreibung', 'meldeergebnis', 'protokoll' ];

    public static function base_dir() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/wettkampf';
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

    public static function dir_for( $wettkampf_id ) {
        $dir = trailingslashit( self::base_dir() ) . (int) $wettkampf_id;
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        return $dir;
    }

    /**
     * Validiert eine hochgeladene Datei. Ausschließlich PDF — geprüft über
     * die tatsächlichen Bytes (finfo), nicht über den vom Browser
     * mitgesendeten (fälschbaren) $_FILES['type'].
     */
    public static function validate_upload( array $file ) {
        if ( empty( $file ) || ! isset( $file['error'] ) ) {
            return [ 'ok' => false, 'message' => 'Keine Datei übermittelt.' ];
        }
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return [ 'ok' => false, 'message' => 'Upload-Fehler (Code ' . $file['error'] . ').' ];
        }
        if ( $file['size'] > self::MAX_SIZE ) {
            return [ 'ok' => false, 'message' => 'Datei ist zu groß (max. 10 MB).' ];
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

        // Rudimentäre Struktur-Prüfung: eine echte PDF beginnt mit "%PDF-".
        // Verhindert, dass eine umbenannte Fremddatei trotz zufällig
        // passendem MIME-Sniffing durchrutscht.
        $head = @file_get_contents( $file['tmp_name'], false, null, 0, 5 );
        if ( $head !== '%PDF-' ) {
            return [ 'ok' => false, 'message' => 'Datei ist keine gültige PDF-Datei.' ];
        }

        return [ 'ok' => true, 'message' => '', 'mime' => 'application/pdf' ];
    }

    /**
     * Speichert die Datei für einen Wettkampf + Typ. Ersetzt ein bestehendes
     * Dokument desselben Typs (alte Datei wird gelöscht).
     */
    public static function store_upload( $wettkampf_id, $typ, array $file ) {
        if ( ! in_array( $typ, self::TYPEN, true ) ) {
            return new WP_Error( 'invalid_type', 'Unbekannter Dokumenttyp.' );
        }
        $val = self::validate_upload( $file );
        if ( ! $val['ok'] ) {
            return new WP_Error( 'invalid_upload', $val['message'] );
        }

        global $wpdb;
        $p   = $wpdb->prefix;
        $dir = self::dir_for( $wettkampf_id );

        $rand        = bin2hex( random_bytes( 8 ) );
        $stored_name = $rand . '.pdf';
        $target      = trailingslashit( $dir ) . $stored_name;

        if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) {
            return new WP_Error( 'move_failed', 'Datei konnte nicht gespeichert werden.' );
        }
        @chmod( $target, 0644 );

        // Vorheriges Dokument dieses Typs entfernen (Datei + Zeile)
        $alt = self::get_by_typ( $wettkampf_id, $typ );
        if ( $alt && file_exists( $alt['path'] ) ) {
            @unlink( $alt['path'] );
        }

        $orig = sanitize_file_name( $file['name'] );
        $daten = [
            'wettkampf_id'    => (int) $wettkampf_id,
            'typ'             => $typ,
            'dateiname'       => $orig,
            'gespeichert_als' => $stored_name,
            'mime_type'       => $val['mime'],
            'groesse'         => (int) $file['size'],
            'hochgeladen_von' => get_current_user_id(),
            'hochgeladen_am'  => current_time( 'mysql' ),
        ];

        // INSERT ... ON DUPLICATE KEY UPDATE über die UNIQUE(wettkampf_id, typ)
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_wettkampf_dokument
                 (wettkampf_id, typ, dateiname, gespeichert_als, mime_type, groesse, hochgeladen_von, hochgeladen_am)
             VALUES (%d, %s, %s, %s, %s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE
                 dateiname = VALUES(dateiname), gespeichert_als = VALUES(gespeichert_als),
                 mime_type = VALUES(mime_type), groesse = VALUES(groesse),
                 hochgeladen_von = VALUES(hochgeladen_von), hochgeladen_am = VALUES(hochgeladen_am)",
            $daten['wettkampf_id'], $daten['typ'], $daten['dateiname'], $daten['gespeichert_als'],
            $daten['mime_type'], $daten['groesse'], $daten['hochgeladen_von'], $daten['hochgeladen_am']
        ) );

        return self::get_by_typ( $wettkampf_id, $typ );
    }

    /** Alle drei Dokumente eines Wettkampfs (nur vorhandene) als typ => Zeile. */
    public static function list_for( $wettkampf_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07i_wettkampf_dokument WHERE wettkampf_id = %d",
            (int) $wettkampf_id
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) $out[ $r['typ'] ] = $r;
        return $out;
    }

    public static function get_by_typ( $wettkampf_id, $typ ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07i_wettkampf_dokument WHERE wettkampf_id = %d AND typ = %s LIMIT 1",
            (int) $wettkampf_id, $typ
        ), ARRAY_A );
        if ( ! $row ) return null;
        $row['path'] = trailingslashit( self::dir_for( $row['wettkampf_id'] ) ) . $row['gespeichert_als'];
        return $row;
    }

    public static function get( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07i_wettkampf_dokument WHERE id = %d LIMIT 1", (int) $id
        ), ARRAY_A );
        if ( ! $row ) return null;
        $row['path'] = trailingslashit( self::dir_for( $row['wettkampf_id'] ) ) . $row['gespeichert_als'];
        return $row;
    }

    public static function delete( $id ) {
        global $wpdb;
        $row = self::get( $id );
        if ( ! $row ) return false;
        if ( file_exists( $row['path'] ) ) @unlink( $row['path'] );
        $wpdb->delete( $wpdb->prefix . 'lsv07i_wettkampf_dokument', [ 'id' => (int) $id ], [ '%d' ] );
        return true;
    }

    /** Alle Dokumente + Ordner eines Wettkampfs entfernen (beim Löschen des Wettkampfs). */
    public static function delete_all_for( $wettkampf_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT gespeichert_als FROM {$wpdb->prefix}lsv07i_wettkampf_dokument WHERE wettkampf_id = %d",
            (int) $wettkampf_id
        ), ARRAY_A );
        $dir = self::dir_for( $wettkampf_id );
        foreach ( (array) $rows as $r ) {
            $f = trailingslashit( $dir ) . $r['gespeichert_als'];
            if ( file_exists( $f ) ) @unlink( $f );
        }
        $wpdb->delete( $wpdb->prefix . 'lsv07i_wettkampf_dokument', [ 'wettkampf_id' => (int) $wettkampf_id ], [ '%d' ] );
        @rmdir( $dir );
    }
}
