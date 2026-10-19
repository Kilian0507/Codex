<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die PDFs.
 *
 * Downloads laufen über GET, damit der Browser sie als Datei behandelt und
 * man sie auf dem Telefon weitergeben kann. Der Nonce steht darum in der
 * Adresse und nicht in einem POST-Feld — geprüft wird er genauso.
 *
 * Jeder Weg prüft in dieser Reihenfolge: angemeldet, eingetragen, Nonce,
 * und dann die Berechtigung für genau das, was angefordert wurde. Erst
 * danach wird überhaupt etwas geladen. Die Prüfung ist dieselbe, die auch
 * die Ansicht benutzt — ein PDF kann also nie mehr enthalten als der
 * Bildschirm.
 */
class LSV07P_Ajax_Download {

    /**
     * Wo der Aufruf endet. Normal ist das `exit`; der Prüfstand hängt hier
     * eine Ausnahme ein, damit er nach einem Download weiterlaufen kann.
     * Ohne diese Naht wäre jeder Download-Weg ungeprüft — und das sind
     * genau die Wege, die Dateien mit Namen von Kindern herausgeben.
     */
    public static $raus = null;

    public static function init() {
        /* Kein nopriv: Ohne WordPress-Anmeldung gibt es hier nichts. */
        add_action( 'wp_ajax_lsv07p_pdf',    [ __CLASS__, 'pdf' ] );
        add_action( 'wp_ajax_lsv07p_datei',  [ __CLASS__, 'datei' ] );
    }

    private static function raus() {
        if ( self::$raus ) call_user_func( self::$raus );
        exit;
    }

    private static function schluss( $code, $text ) {
        status_header( $code );
        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        echo esc_html( $text );
        self::raus();
    }

    /**
     * Ein angehängtes PDF.
     *
     * Der Weg an die Datei führt NUR hier entlang: Der Ordner im
     * Upload-Verzeichnis ist vor Direktzugriff gesperrt, und der Dateiname
     * auf der Platte ist Zufall. Gefragt wird dieselbe Frage wie bei der
     * Ansicht — darf diese Person diesen Plan sehen.
     */
    public static function datei() {
        if ( ! is_user_logged_in() ) {
            self::schluss( 401, 'Du bist nicht angemeldet.' );
        }
        if ( ! wp_verify_nonce( self::g( '_wpnonce' ), 'lsv07p_nonce' ) ) {
            self::schluss( 403, 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.' );
        }
        if ( ! LSV07P_Rollen::hat_zugang() ) {
            self::schluss( 403, 'Dieses Konto ist für SwimPlan nicht eingetragen.' );
        }

        $d = LSV07P_Dateien::holen( (int) self::g( 'datei_id', '0' ) );
        /* Dieselbe Antwort für „gibt es nicht" und „darf ich nicht": Sonst
           liesse sich durch Probieren herausfinden, welche Dateien es gibt. */
        if ( ! $d || ! LSV07P_Plan::darf_sehen( (int) $d['plan_id'] ) ) {
            self::schluss( 403, 'Diese Datei ist für dich nicht freigegeben.' );
        }
        if ( ! file_exists( $d['pfad'] ) ) {
            self::schluss( 404, 'Die Datei ist nicht mehr da.' );
        }

        $inhalt = (string) file_get_contents( $d['pfad'] );
        self::datei_kopf( self::dateiname_sicher( (string) $d['dateiname'] ), strlen( $inhalt ) );
        echo $inhalt;
        self::raus();
    }

    /**
     * Der Name, mit dem die Datei beim Empfänger ankommt. Anführungszeichen
     * und Zeilenumbrüche müssen heraus: Sie würden die Kopfzeile
     * aufbrechen, und damit liesse sich eine zweite hineinschmuggeln.
     */
    private static function dateiname_sicher( $name ) {
        $n = preg_replace( '/[\x00-\x1F"\\\\]+/', '', $name );
        $n = trim( (string) $n );
        return $n !== '' ? mb_substr( $n, 0, 200 ) : 'trainingsplan.pdf';
    }

    private static function datei_kopf( $name, $laenge ) {
        nocache_headers();
        /* Nirgends zwischenspeichern: weder im Browser noch in einem
           Proxy. Ein Trainingsplan mit Namen von Kindern soll nach dem
           Abmelden nicht noch im Zwischenspeicher eines geteilten Tablets
           liegen. */
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
        header( 'Pragma: no-cache' );
        header( 'Content-Type: application/pdf' );
        header( 'Content-Length: ' . (int) $laenge );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        /* Falls das PDF doch im Browser geöffnet wird: nichts ausführen,
           nichts nachladen, nicht einbetten lassen. */
        header( "Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'none'" );
        header( 'X-Frame-Options: DENY' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
    }

    private static function g( $name, $standard = '' ) {
        return isset( $_GET[ $name ] ) ? (string) wp_unslash( $_GET[ $name ] ) : $standard;
    }

    public static function pdf() {
        if ( ! is_user_logged_in() ) {
            self::schluss( 401, 'Du bist nicht angemeldet.' );
        }
        if ( ! wp_verify_nonce( self::g( '_wpnonce' ), 'lsv07p_nonce' ) ) {
            self::schluss( 403, 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.' );
        }
        if ( ! LSV07P_Rollen::hat_zugang() ) {
            self::schluss( 403, 'Dieses Konto ist für SwimPlan nicht eingetragen.' );
        }

        $was = self::g( 'was', 'plan' );
        switch ( $was ) {
            case 'plan':       self::d_plan();       break;
            case 'plaene':     self::d_plaene();     break;
            case 'bestzeiten': self::d_bestzeiten(); break;
            case 'verlauf':    self::d_verlauf();    break;
            case 'mannschaft': self::d_mannschaft(); break;
            case 'rangliste':  self::d_rangliste();  break;
            default:           self::schluss( 400, 'Diesen Bericht gibt es nicht.' );
        }
    }

    /** Der fertige Bericht geht hinaus — oder, wenn er leer ist, ein Hinweis. */
    private static function senden( $inhalt, $name ) {
        if ( $inhalt === '' ) self::schluss( 404, 'Dazu gibt es nichts zu berichten.' );
        self::datei_kopf( $name, strlen( $inhalt ) );
        echo $inhalt;
        self::raus();
    }

    // ── Die einzelnen Berichte ──────────────────────────────────────────

    private static function d_plan() {
        $id = (int) self::g( 'plan_id', '0' );
        if ( ! LSV07P_Plan::darf_sehen( $id ) ) {
            self::schluss( 403, 'Dieser Plan ist für dich nicht freigegeben.' );
        }

        $plan = LSV07P_Plan::holen( $id );
        /* Der Dateiname nach dem Beispiel aus dem Konzept:
           C-Mannschaft_2026-10-19.pdf — erst, um was es geht, dann der
           Tag. Wer zehn Pläne herunterlädt, hat sie damit sortiert. */
        $titel = self::plan_name( $id, $plan );
        /* `aushang=1` lässt die Notizen des Trainers weg — das ist der
           Zettel, der an die Mannschaft geht. */
        self::senden(
            LSV07P_Bericht::plan( $id, self::g( 'aushang' ) === '1' ),
            LSV07P_Bericht::dateiname( $titel, $plan['datum'] ) );
    }

    /**
     * Woraus der Dateiname gemacht wird: der Name der Mannschaft, für die
     * der Plan freigegeben ist. Gibt es die nicht (oder mehrere), nimmt er
     * den Titel des Plans und zuletzt einfach „Trainingsplan".
     */
    private static function plan_name( $id, $plan ) {
        $zu    = LSV07P_Plan::zuordnung( $id );
        $teams = [];
        foreach ( $zu as $z ) {
            if ( $z['art'] === 'team' ) $teams[] = $z['name'];
        }
        if ( count( $teams ) === 1 ) return $teams[0];
        if ( ! empty( $plan['titel'] ) ) return (string) $plan['titel'];
        return 'Trainingsplan';
    }

    private static function d_plaene() {
        $roh = self::g( 'ids' );
        $ids = array_values( array_unique( array_filter(
            array_map( 'intval', explode( ',', $roh ) ) ) ) );
        $ids = array_slice( $ids, 0, LSV07P_Ajax_Buehne::BUEHNE_MAX );

        /* Jeder einzeln. Was nicht erlaubt ist, fällt weg — und wenn
           nichts übrig bleibt, gibt es 403 und keine leere Datei. */
        $erlaubt = [];
        foreach ( $ids as $id ) {
            if ( LSV07P_Plan::darf_sehen( $id ) ) $erlaubt[] = $id;
        }
        if ( ! $erlaubt ) self::schluss( 403, 'Davon ist nichts für dich freigegeben.' );

        self::senden(
            LSV07P_Bericht::plaene( $erlaubt, self::g( 'aushang' ) === '1' ),
            LSV07P_Bericht::dateiname( 'Trainingsplaene' ) );
    }

    private static function d_bestzeiten() {
        $sid = (int) self::g( 'schwimmer_id', '0' );
        if ( ! LSV07P_Schwimmer::darf( $sid ) ) {
            self::schluss( 403, 'Dieser Schwimmer ist in keiner deiner Mannschaften.' );
        }

        self::senden(
            LSV07P_Bericht::bestzeiten( $sid, self::g( 'offiziell' ) === '1' ),
            LSV07P_Bericht::dateiname( 'Bestzeiten-' . LSV07P_Schwimmer::name( $sid ) ) );
    }

    private static function d_verlauf() {
        $sid = (int) self::g( 'schwimmer_id', '0' );
        if ( ! LSV07P_Schwimmer::darf( $sid ) ) {
            self::schluss( 403, 'Dieser Schwimmer ist in keiner deiner Mannschaften.' );
        }

        $strecke = (int) self::g( 'strecke_m', '0' );
        $lage    = self::g( 'lage', 'frei' );
        $bahn    = (int) self::g( 'bahnlaenge', '25' );

        self::senden(
            LSV07P_Bericht::verlauf( $sid, $strecke, $lage, $bahn ),
            LSV07P_Bericht::dateiname(
                'Verlauf-' . $strecke . 'm-' . LSV07P_Rechnung::lage_name( $lage )
                . '-' . LSV07P_Schwimmer::name( $sid ) ) );
    }

    private static function d_mannschaft() {
        $team = (int) self::g( 'team_id', '0' );
        if ( ! LSV07P_Rollen::darf_team( $team ) ) {
            self::schluss( 403, 'Diese Mannschaft gehört nicht zu deinen.' );
        }
        $t = LSV07P_Mannschaft::holen( $team );
        self::senden( LSV07P_Bericht::mannschaft( $team ),
            LSV07P_Bericht::dateiname( $t ? (string) $t['name'] : 'Mannschaft' ) );
    }

    private static function d_rangliste() {
        $team = (int) self::g( 'team_id', '0' );
        if ( ! LSV07P_Rollen::darf_team( $team ) ) {
            self::schluss( 403, 'Diese Mannschaft gehört nicht zu deinen.' );
        }
        $strecke = (int) self::g( 'strecke_m', '0' );
        $lage    = self::g( 'lage', 'frei' );
        $bahn    = (int) self::g( 'bahnlaenge', '25' );
        $t       = LSV07P_Mannschaft::holen( $team );

        self::senden( LSV07P_Bericht::rangliste( $team, $strecke, $lage, $bahn ),
            LSV07P_Bericht::dateiname(
                'Rangliste-' . $strecke . 'm-' . LSV07P_Rechnung::lage_name( $lage )
                . '-' . ( $t ? (string) $t['name'] : '' ) ) );
    }
}
