<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Trainingspläne als Datei.
 *
 * Viele Trainer haben ihre Pläne längst als PDF — aus Word, aus einer
 * Vorlage, vom Verband. Die sollen sie anhängen können, statt sie Set für
 * Set abzutippen. Eine Einheit kann also Sets haben, ein PDF, oder beides:
 * das PDF als Original und die Sets für die Meter- und Dauerrechnung.
 *
 * Ablage: wp-content/uploads/lsv07p-private/plan/<plan_id>/<zufall>.pdf
 *
 * Der ganze Ordner ist vor Direktzugriff geschützt; der einzige Weg an eine
 * Datei ist der Download-Endpunkt, und der fragt vorher dieselbe Frage wie
 * die Ansicht: Darf diese Person diesen Plan sehen? Der Dateiname auf der
 * Platte ist Zufall — wer eine Plannummer errät, errät damit keinen Pfad.
 *
 * **Nur PDF.** Ein Bild wäre bequem, aber ein abfotografierter Zettel ist
 * am Beckenrand nicht zu lesen; Office-Dateien können Makros tragen. Der
 * Typ wird an den tatsächlichen Bytes geprüft (finfo), nicht an dem, was
 * der Browser behauptet — das lässt sich frei behaupten.
 */
class LSV07P_Dateien {

    const MAX_BYTES = 15728640;              // 15 MB
    const ERLAUBT   = [ 'application/pdf' => 'pdf' ];

    public static function mb() {
        return (int) round( self::MAX_BYTES / 1048576 );
    }

    // ── Ablage ──────────────────────────────────────────────────────────

    public static function basis() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07p-private/plan';
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
                "# LSV07 SwimPlan: Direktzugriff blockieren\n"
              . "Order Deny,Allow\nDeny from all\n"
              . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }
        $idx = $dir . '/index.html';
        if ( ! file_exists( $idx ) ) @file_put_contents( $idx, '<!-- still -->' );
        $readme = $dir . '/README-NGINX.txt';
        if ( ! file_exists( $readme ) ) {
            @file_put_contents( $readme,
                "Wenn dieser Server nginx benutzt, gehoert in die nginx.conf:\n\n"
              . "location ~* /wp-content/uploads/lsv07p-private/ {\n    deny all;\n    return 403;\n}\n\n"
              . "Apache liest die .htaccess von allein.\n" );
        }
    }

    public static function ordner( $plan_id ) {
        $dir = trailingslashit( self::basis() ) . (int) $plan_id;
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        return $dir;
    }

    // ── Prüfen und ablegen ──────────────────────────────────────────────

    /** Rückgabe [ ok, message, mime, endung ]. */
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
        if ( ! self::wirklich_hochgeladen( $datei['tmp_name'] ) ) {
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
            /* Ohne finfo bleibt nur, was WordPress aus dem Namen liest. */
            $pruef = wp_check_filetype( (string) $datei['name'] );
            $mime  = (string) ( $pruef['type'] ?? '' );
        }
        if ( ! isset( self::ERLAUBT[ $mime ] ) ) {
            return [ 'ok' => false, 'message' =>
                'Nur PDF. Ein Bild oder eine Word-Datei nimmt SwimPlan nicht an — '
              . 'ein abfotografierter Zettel ist am Beckenrand nicht zu lesen, und '
              . 'Office-Dateien können Makros tragen.' ];
        }

        /* Die ersten Bytes müssen die PDF-Kennung tragen. finfo liest
           dasselbe, aber zweimal hinsehen kostet hier nichts — und eine
           Datei, die sich nur als PDF ausgibt, fällt damit auch auf, wenn
           finfo auf dem Webspace fehlt. */
        $kopf = (string) @file_get_contents( $datei['tmp_name'], false, null, 0, 5 );
        if ( strpos( $kopf, '%PDF-' ) !== 0 ) {
            return [ 'ok' => false, 'message' => 'Das ist keine PDF-Datei.' ];
        }

        /* Die Endung kommt aus der Liste der erlaubten Typen und nicht aus
           dem Dateinamen: Was „plan.pdf.exe" heisst, landet so trotzdem als
           .pdf auf der Platte. Der Rückfall ist nur eine Vorsichtsmaßnahme
           — hier ist der Typ längst geprüft. */
        return [ 'ok' => true, 'mime' => $mime,
                 'endung' => self::ERLAUBT[ $mime ] ?? 'pdf' ];
    }

    /* Zwei Nähte für den Prüfstand: Dort gibt es keinen echten Upload, also
       auch kein is_uploaded_file() und kein move_uploaded_file(). */
    public static $pruefe_upload = true;

    private static function wirklich_hochgeladen( $pfad ) {
        if ( ! self::$pruefe_upload ) return file_exists( $pfad );
        return is_uploaded_file( $pfad );
    }

    private static function verschieben( $von, $nach ) {
        if ( ! self::$pruefe_upload ) return @rename( $von, $nach );
        return @move_uploaded_file( $von, $nach );
    }

    /**
     * Legt die Datei ab und trägt sie am Plan ein.
     * Rückgabe: [ ok, message, datei ]
     */
    public static function ablegen( $plan_id, array $datei ) {
        global $wpdb;
        $pruef = self::pruefen( $datei );
        if ( ! $pruef['ok'] ) return $pruef;

        $plan_id = (int) $plan_id;
        $name = sanitize_file_name( (string) $datei['name'] );
        if ( $name === '' ) $name = 'trainingsplan.pdf';
        $name = mb_substr( $name, 0, 200 );

        $ziel_name = bin2hex( random_bytes( 16 ) ) . '.' . $pruef['endung'];
        $ziel      = trailingslashit( self::ordner( $plan_id ) ) . $ziel_name;

        if ( ! self::verschieben( $datei['tmp_name'], $ziel ) ) {
            return [ 'ok' => false, 'message' => 'Die Datei konnte nicht gespeichert werden.' ];
        }
        @chmod( $ziel, 0640 );

        $wpdb->insert( $wpdb->prefix . 'lsv07p_datei', [
            'plan_id'         => $plan_id,
            'dateiname'       => $name,
            'gespeichert_als' => $ziel_name,
            'mime_type'       => $pruef['mime'],
            'groesse'         => (int) $datei['size'],
            'seiten'          => self::seiten( $ziel ),
            'hochgeladen_von' => (int) get_current_user_id(),
            'hochgeladen_am'  => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ] );

        $id = (int) $wpdb->insert_id;
        LSV07P_DB::spur( 'plan.datei.an', $plan_id, $name );
        LSV07P_Plan::nach_aenderung( $plan_id, 'Datei angehängt' );

        return [ 'ok' => true, 'datei' => self::zeile( self::holen( $id ) ) ];
    }

    /**
     * Wie viele Seiten hat das PDF? Gezählt wird grob über die
     * Seitenobjekte — das genügt für den Hinweis „3 Seiten" an der Datei
     * und kommt ohne Fremdbibliothek aus. Lässt es sich nicht lesen, steht
     * da eben nichts; eine falsche Zahl wäre schlimmer als keine.
     */
    public static function seiten( $pfad ) {
        $roh = (string) @file_get_contents( $pfad, false, null, 0, 2097152 );
        if ( $roh === '' ) return 0;
        if ( preg_match( '#/Type\s*/Pages[^>]*?/Count\s+(\d+)#s', $roh, $m ) ) {
            return min( 9999, (int) $m[1] );
        }
        $n = preg_match_all( '#/Type\s*/Page[^s]#', $roh );
        return $n > 0 ? min( 9999, $n ) : 0;
    }

    // ── Lesen ───────────────────────────────────────────────────────────

    public static function zu_plan( $plan_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_datei WHERE plan_id = %d ORDER BY id ASC",
            (int) $plan_id ), ARRAY_A ) ?: [];

        $out = [];
        foreach ( $rows as $r ) $out[] = self::zeile( $r );
        return $out;
    }

    private static function zeile( $r ) {
        if ( ! $r ) return null;
        return [
            'id'          => (int) $r['id'],
            'plan_id'     => (int) $r['plan_id'],
            'name'        => (string) $r['dateiname'],
            'mime'        => (string) $r['mime_type'],
            'groesse'     => (int) $r['groesse'],
            'groesse_text' => self::groesse_text( (int) $r['groesse'] ),
            'seiten'      => (int) $r['seiten'],
            'von'         => LSV07P_Rollen::name( (int) $r['hochgeladen_von'] ),
            'am'          => (string) $r['hochgeladen_am'],
        ];
    }

    public static function groesse_text( $bytes ) {
        $b = max( 0, (int) $bytes );
        if ( $b >= 1048576 ) return number_format_i18n( $b / 1048576, 1 ) . ' MB';
        return max( 1, (int) round( $b / 1024 ) ) . ' kB';
    }

    /** Eine Datei samt Pfad, oder null. */
    public static function holen( $id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_datei WHERE id = %d", (int) $id ), ARRAY_A );
        if ( ! $row ) return null;
        $row['pfad'] = trailingslashit( self::ordner( (int) $row['plan_id'] ) )
                     . $row['gespeichert_als'];
        return $row;
    }

    // ── Löschen ─────────────────────────────────────────────────────────

    public static function loeschen( $id ) {
        global $wpdb;
        $row = self::holen( $id );
        if ( ! $row ) return false;
        if ( file_exists( $row['pfad'] ) ) @unlink( $row['pfad'] );
        $wpdb->delete( $wpdb->prefix . 'lsv07p_datei', [ 'id' => (int) $id ], [ '%d' ] );
        LSV07P_DB::spur( 'plan.datei.weg', (int) $row['plan_id'], (string) $row['dateiname'] );
        return true;
    }

    /** Alle Dateien eines Plans — wenn der Plan weggeht. */
    public static function alle_loeschen( $plan_id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07p_datei WHERE plan_id = %d", (int) $plan_id ) );
        foreach ( (array) $ids as $id ) self::loeschen( (int) $id );

        $ordner = trailingslashit( self::basis() ) . (int) $plan_id;
        if ( is_dir( $ordner ) ) @rmdir( $ordner );
        return true;
    }

    /**
     * Die Dateien eines Plans an einen anderen hängen — als echte Kopie auf
     * der Platte. Ein Verweis wäre sparsamer, aber dann nähme das Löschen
     * der einen Einheit der anderen ihren Plan weg.
     */
    public static function kopieren( $von_plan, $nach_plan ) {
        global $wpdb;
        $nach_plan = (int) $nach_plan;
        $n = 0;

        foreach ( self::zu_plan( $von_plan ) as $d ) {
            $alt = self::holen( $d['id'] );
            if ( ! $alt || ! file_exists( $alt['pfad'] ) ) continue;

            $ziel_name = bin2hex( random_bytes( 16 ) ) . '.pdf';
            $ziel      = trailingslashit( self::ordner( $nach_plan ) ) . $ziel_name;
            if ( ! @copy( $alt['pfad'], $ziel ) ) continue;
            @chmod( $ziel, 0640 );

            $wpdb->insert( $wpdb->prefix . 'lsv07p_datei', [
                'plan_id'         => $nach_plan,
                'dateiname'       => (string) $alt['dateiname'],
                'gespeichert_als' => $ziel_name,
                'mime_type'       => (string) $alt['mime_type'],
                'groesse'         => (int) $alt['groesse'],
                'seiten'          => (int) $alt['seiten'],
                'hochgeladen_von' => (int) get_current_user_id(),
                'hochgeladen_am'  => current_time( 'mysql' ),
            ], [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ] );
            $n++;
        }
        return $n;
    }

    /**
     * Dateien, deren Plan es nicht mehr gibt. Das sollte nicht vorkommen —
     * aber wenn doch (abgebrochenes Löschen, Eingriff von Hand), liegen
     * sonst für immer Dateien mit Namen von Kindern auf der Platte.
     * Läuft einmal am Tag mit.
     */
    public static function verwaiste_aufraeumen() {
        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = $wpdb->get_col(
            "SELECT d.id FROM {$p}lsv07p_datei d
              WHERE NOT EXISTS ( SELECT 1 FROM {$p}lsv07p_plan pl WHERE pl.id = d.plan_id )" );
        foreach ( (array) $ids as $id ) self::loeschen( (int) $id );
        return count( (array) $ids );
    }
}
