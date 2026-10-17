<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Dateien, die Trainer an eine Nachricht hängen.
 *
 * Ablage: wp-content/uploads/lsv07s-private/nachricht/<nachricht_id>/<zufall>.<endung>
 * Der ganze Ordner ist vor Direktzugriff geschützt; der einzige Weg an eine
 * Datei ist der Download-Endpunkt, der vorher prüft, ob die anfragende
 * Person diese Nachricht überhaupt bekommen hat.
 *
 * Geprüft wird der Typ an den tatsächlichen Bytes (finfo), nicht am
 * Typ, den der Browser mitschickt — der lässt sich frei behaupten.
 */
class LSV07S_Dateien {

    const MAX_BYTES = 10485760; // 10 MB
    const ERLAUBT = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];
    /* Was ein Trainer typischerweise verteilt: Ausschreibungen, Fotos,
       Zeitpläne. Office-Dateien bewusst nicht — sie können Makros tragen,
       und ein PDF tut es auch. */

    public static function basis() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07s-private/nachricht';
        if ( ! is_dir( $base ) ) {
            wp_mkdir_p( $base );
            self::schutz( dirname( $base ) );
        } elseif ( ! file_exists( dirname( $base ) . '/.htaccess' ) ) {
            self::schutz( dirname( $base ) );
        }
        return $base;
    }

    private static function schutz( $dir ) {
        $ht = $dir . '/.htaccess';
        if ( ! file_exists( $ht ) ) {
            @file_put_contents( $ht,
                "# LSV07 SwimView: Direktzugriff blockieren\n"
              . "Order Deny,Allow\nDeny from all\n"
              . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }
        $idx = $dir . '/index.html';
        if ( ! file_exists( $idx ) ) @file_put_contents( $idx, '<!-- still -->' );
        $readme = $dir . '/README-NGINX.txt';
        if ( ! file_exists( $readme ) ) {
            @file_put_contents( $readme,
                "Wenn dieser Server nginx benutzt, gehoert in die nginx.conf:\n\n"
              . "location ~* /wp-content/uploads/lsv07s-private/ {\n    deny all;\n    return 403;\n}\n\n"
              . "Apache liest die .htaccess von allein.\n" );
        }
    }

    public static function ordner( $nachricht_id ) {
        $dir = trailingslashit( self::basis() ) . (int) $nachricht_id;
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        return $dir;
    }

    /**
     * Prüft eine hochgeladene Datei. Rückgabe [ ok, message, mime, endung ].
     */
    public static function pruefen( array $datei ) {
        if ( empty( $datei ) || ! isset( $datei['error'] ) ) {
            return [ 'ok' => false, 'message' => 'Es wurde keine Datei übermittelt.' ];
        }
        if ( $datei['error'] === UPLOAD_ERR_INI_SIZE || $datei['error'] === UPLOAD_ERR_FORM_SIZE ) {
            return [ 'ok' => false, 'message' => 'Die Datei ist zu groß (höchstens '
                     . self::mb() . ' MB).' ];
        }
        if ( $datei['error'] !== UPLOAD_ERR_OK ) {
            return [ 'ok' => false, 'message' => 'Beim Hochladen ist etwas schiefgegangen (Code '
                     . (int) $datei['error'] . ').' ];
        }
        if ( (int) $datei['size'] <= 0 ) {
            return [ 'ok' => false, 'message' => 'Die Datei ist leer.' ];
        }
        if ( (int) $datei['size'] > self::MAX_BYTES ) {
            return [ 'ok' => false, 'message' => 'Die Datei ist zu groß (höchstens '
                     . self::mb() . ' MB).' ];
        }
        if ( ! is_uploaded_file( $datei['tmp_name'] ) ) {
            return [ 'ok' => false, 'message' => 'Die Datei wurde nicht regulär hochgeladen.' ];
        }

        $mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $fi = finfo_open( FILEINFO_MIME_TYPE );
            if ( $fi ) {
                $mime = (string) finfo_file( $fi, $datei['tmp_name'] );
                finfo_close( $fi );
            }
        }
        if ( $mime === '' ) {
            /* Ohne finfo bleibt nur, was WordPress aus dem Namen liest. Dann
               wird wenigstens die Endung geprüft. */
            $pruef = wp_check_filetype( (string) $datei['name'] );
            $mime  = (string) ( $pruef['type'] ?? '' );
        }
        if ( ! isset( self::ERLAUBT[ $mime ] ) ) {
            return [ 'ok' => false, 'message' => 'Nur PDF, JPG, PNG und WEBP sind erlaubt.' ];
        }

        return [ 'ok' => true, 'mime' => $mime, 'endung' => self::ERLAUBT[ $mime ] ];
    }

    public static function mb() {
        return (int) round( self::MAX_BYTES / 1048576 );
    }

    /**
     * Legt die Datei ab und trägt sie an der Nachricht ein.
     * Rückgabe: [ ok, message, datei ]
     */
    public static function ablegen( $nachricht_id, array $datei ) {
        global $wpdb;
        $pruef = self::pruefen( $datei );
        if ( ! $pruef['ok'] ) return $pruef;

        $nachricht_id = (int) $nachricht_id;
        $name = sanitize_file_name( (string) $datei['name'] );
        if ( $name === '' ) $name = 'datei.' . $pruef['endung'];
        $name = mb_substr( $name, 0, 200 );

        $ziel_name = bin2hex( random_bytes( 16 ) ) . '.' . $pruef['endung'];
        $ziel      = trailingslashit( self::ordner( $nachricht_id ) ) . $ziel_name;

        if ( ! @move_uploaded_file( $datei['tmp_name'], $ziel ) ) {
            return [ 'ok' => false, 'message' => 'Die Datei konnte nicht gespeichert werden.' ];
        }
        @chmod( $ziel, 0640 );

        $p = $wpdb->prefix;
        $wpdb->insert( $p . 'lsv07s_nachricht_datei', [
            'nachricht_id'    => $nachricht_id,
            'dateiname'       => $name,
            'gespeichert_als' => $ziel_name,
            'mime_type'       => $pruef['mime'],
            'groesse'         => (int) $datei['size'],
            'hochgeladen_am'  => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%d', '%s' ] );

        return [ 'ok' => true, 'datei' => [
            'id'        => (int) $wpdb->insert_id,
            'name'      => $name,
            'mime'      => $pruef['mime'],
            'groesse'   => (int) $datei['size'],
        ] ];
    }

    /** Die Dateien einer Nachricht. */
    public static function zu_nachricht( $nachricht_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, dateiname, mime_type, groesse
               FROM {$p}lsv07s_nachricht_datei WHERE nachricht_id = %d ORDER BY id ASC",
            (int) $nachricht_id ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'      => (int) $r['id'],
                'name'    => $r['dateiname'],
                'mime'    => $r['mime_type'],
                'groesse' => (int) $r['groesse'],
            ];
        }
        return $out;
    }

    /** Eine Datei samt Pfad, oder null. */
    public static function holen( $id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07s_nachricht_datei WHERE id = %d", (int) $id ), ARRAY_A );
        if ( ! $row ) return null;
        $row['pfad'] = trailingslashit( self::ordner( (int) $row['nachricht_id'] ) )
                     . $row['gespeichert_als'];
        return $row;
    }

    /** Löscht eine Datei samt Eintrag. */
    public static function loeschen( $id ) {
        global $wpdb;
        $row = self::holen( $id );
        if ( ! $row ) return false;
        if ( file_exists( $row['pfad'] ) ) @unlink( $row['pfad'] );
        $wpdb->delete( $wpdb->prefix . 'lsv07s_nachricht_datei', [ 'id' => (int) $id ], [ '%d' ] );
        return true;
    }

    /** Alle Dateien einer Nachricht löschen (wenn die Nachricht weggeht). */
    public static function alle_loeschen( $nachricht_id ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07s_nachricht_datei WHERE nachricht_id = %d", (int) $nachricht_id ) );
        foreach ( (array) $rows as $id ) self::loeschen( (int) $id );
        $ordner = trailingslashit( self::basis() ) . (int) $nachricht_id;
        if ( is_dir( $ordner ) ) @rmdir( $ordner );
    }
}
