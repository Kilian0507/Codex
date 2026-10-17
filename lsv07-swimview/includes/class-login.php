<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Anmeldung der Schwimmerinnen und Schwimmer: DSV-ID und Geburtsdatum.
 *
 * Diese beiden Angaben sind kein Passwort. Eine DSV-ID steht in jedem
 * Meldeergebnis und jedem Protokoll, und ein Geburtsdatum weiss im Verein
 * fast jeder. Wer beides kennt, kommt hinein. Darum gilt hier:
 *
 *  - Sichtbar ist nur, was ausdrücklich freigegeben wurde, und niemals etwas
 *    über ein anderes Kind (siehe LSV07S_Freigabe und LSV07S_Daten).
 *  - Jeder Fehlversuch zählt; nach wenigen Versuchen ist die Adresse für
 *    eine Weile gesperrt.
 *  - Die Rückmeldung verrät nie, WELCHE der beiden Angaben nicht stimmt.
 *    Sonst liesse sich damit prüfen, ob eine DSV-ID zum Verein gehört.
 *  - Die Anmeldung läuft nach acht Stunden Ruhe ab, spätestens nach 30 Tagen.
 *
 * Im Cookie steht ein Wähler und ein Geheimnis. Gespeichert wird nur der
 * Hash des Geheimnisses — wer die Tabelle liest, kann sich damit nicht
 * anmelden.
 */
class LSV07S_Login {

    const COOKIE      = 'lsv07s_sitzung';
    const COOKIE_CSRF = 'lsv07s_csrf';
    const MAX_VERSUCHE = 8;
    const SPERRE_MIN   = 15;

    private static $schwimmer = false;   // false = noch nicht nachgesehen

    /**
     * Wohin Cookies geschrieben werden. Im Betrieb ist das `setcookie()`.
     *
     * Dass hier eine Stelle mit Namen steht und kein direkter Aufruf, hat
     * einen Grund: `setcookie()` ist eine PHP-eigene Funktion und lässt
     * sich im Prüfstand nicht ersetzen. Ohne diese Stelle bliebe genau der
     * Weg ungeprüft, auf den hier am meisten ankommt — dass das
     * ausgegebene Cookie vom Server auch wieder angenommen wird. Ein
     * vertauschter Hash wäre sonst erst im Betrieb aufgefallen.
     *
     * Es ist ausdrücklich KEIN Schalter für Betriebsverhalten: Wer nichts
     * einhängt, bekommt setcookie(), und das Plugin selbst hängt nie etwas
     * ein.
     */
    public static $cookie_senke = null;

    // ── Eingaben aufbereiten ────────────────────────────────────────────

    /**
     * DSV-ID auf das Wesentliche bringen: Ziffern und Buchstaben. Auf
     * Ausweisen und in Listen steht sie mal mit Leerzeichen, mal mit
     * Bindestrich, mal mit Punkt.
     */
    public static function dsv_norm( $roh ) {
        $s = strtoupper( trim( (string) $roh ) );
        $s = preg_replace( '/[^A-Z0-9]/', '', $s );
        return (string) $s;
    }

    /**
     * Geburtsdatum aus allem lesen, was ein Mensch eintippt: 1.2.2011,
     * 01.02.2011, 2011-02-01. Rückgabe Y-m-d oder leer.
     */
    public static function datum_norm( $roh ) {
        $s = trim( (string) $roh );
        if ( $s === '' ) return '';
        if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m ) ) {
            $j = (int) $m[1]; $mo = (int) $m[2]; $t = (int) $m[3];
        } elseif ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m ) ) {
            $t = (int) $m[1]; $mo = (int) $m[2]; $j = (int) $m[3];
        } elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m ) ) {
            // amerikanische Reihenfolge, wie sie ein Datumsfeld mit
            // englischer Spracheinstellung liefert
            $mo = (int) $m[1]; $t = (int) $m[2]; $j = (int) $m[3];
        } else {
            return '';
        }
        if ( ! checkdate( $mo, $t, $j ) ) return '';
        return sprintf( '%04d-%02d-%02d', $j, $mo, $t );
    }

    // ── Sperre gegen Durchprobieren ─────────────────────────────────────

    private static function ip_hash() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        // Die Adresse wird nur gehasht abgelegt — keine Klartext-IP.
        return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) . '|lsv07s-throttle' );
    }

    public static function ist_gesperrt() {
        global $wpdb;
        $p   = $wpdb->prefix;
        $bis = $wpdb->get_var( $wpdb->prepare(
            "SELECT gesperrt_bis FROM {$p}lsv07s_throttle WHERE ip_hash = %s", self::ip_hash() ) );
        if ( ! $bis ) return false;
        return strtotime( $bis . ' UTC' ) > time();
    }

    private static function fehlversuch() {
        global $wpdb;
        $p  = $wpdb->prefix;
        $ip = self::ip_hash();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT versuche, letzter_am FROM {$p}lsv07s_throttle WHERE ip_hash = %s", $ip ), ARRAY_A );

        $jetzt    = gmdate( 'Y-m-d H:i:s' );
        $versuche = $row ? (int) $row['versuche'] : 0;
        // Nach einer Viertelstunde Ruhe fängt die Zählung von vorn an.
        if ( $row && strtotime( $row['letzter_am'] . ' UTC' ) < time() - self::SPERRE_MIN * 60 ) {
            $versuche = 0;
        }
        $versuche++;

        $gesperrt = null;
        if ( $versuche >= self::MAX_VERSUCHE ) {
            $gesperrt = gmdate( 'Y-m-d H:i:s', time() + self::SPERRE_MIN * 60 );
            $versuche = 0;
        }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07s_throttle (ip_hash, versuche, gesperrt_bis, letzter_am)
             VALUES (%s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE versuche = VALUES(versuche),
                                     gesperrt_bis = VALUES(gesperrt_bis),
                                     letzter_am = VALUES(letzter_am)",
            $ip, $versuche, $gesperrt, $jetzt ) );
    }

    private static function fehlversuche_weg() {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'lsv07s_throttle', [ 'ip_hash' => self::ip_hash() ], [ '%s' ] );
    }

    // ── Anmelden ────────────────────────────────────────────────────────

    /**
     * Prüft DSV-ID und Geburtsdatum und legt bei Erfolg eine Sitzung an.
     *
     * Rückgabe: [ 'ok' => true, 'schwimmer' => [...] ]
     *        oder [ 'ok' => false, 'message' => '…', 'gesperrt' => bool ]
     */
    public static function anmelden( $dsv_roh, $datum_roh ) {
        if ( self::ist_gesperrt() ) {
            return [ 'ok' => false, 'gesperrt' => true,
                     'message' => 'Zu viele Fehlversuche. Bitte warte ein paar Minuten und versuche es dann noch einmal.' ];
        }

        $dsv   = self::dsv_norm( $dsv_roh );
        $datum = self::datum_norm( $datum_roh );

        /* Absurde Eingaben gar nicht erst in die Datenbank tragen. */
        if ( $dsv === '' || strlen( $dsv ) > 40 || $datum === '' ) {
            self::fehlversuch();
            return [ 'ok' => false, 'message' => self::FEHLER ];
        }

        $s = LSV07S_Intern::schwimmer_by_dsv( $dsv );
        /* Beide Faktoren werden IMMER beide geprüft und es wird nie gesagt,
           welcher nicht stimmte. Verglichen wird in gleichbleibender Zeit. */
        $datum_soll = $s ? substr( (string) $s['birth_date'], 0, 10 ) : '';
        $passt = $s && $datum_soll !== '' && hash_equals( $datum_soll, $datum );

        if ( ! $passt ) {
            self::fehlversuch();
            return [ 'ok' => false, 'message' => self::FEHLER ];
        }

        self::fehlversuche_weg();
        self::sitzung_anlegen( (int) $s['id'] );
        self::$schwimmer = $s;
        return [ 'ok' => true, 'schwimmer' => $s ];
    }

    /* Eine Meldung für jeden Fehlschlag. Wer sie liest, lernt nichts über
       den Datenbestand. */
    const FEHLER = 'DSV-ID oder Geburtsdatum stimmt nicht. Bitte prüfe deine Eingabe. '
                 . 'Deine DSV-ID steht in jedem Meldeergebnis; im Zweifel fragst du deinen Trainer.';

    // ── Sitzung ─────────────────────────────────────────────────────────

    private static function sitzung_anlegen( $schwimmer_id ) {
        global $wpdb;
        $p        = $wpdb->prefix;
        $selektor = bin2hex( random_bytes( 16 ) );
        $geheim   = bin2hex( random_bytes( 32 ) );

        $wpdb->insert( $p . 'lsv07s_sitzung', [
            'selektor'     => $selektor,
            'geheim_hash'  => hash( 'sha256', $geheim ),
            'schwimmer_id' => (int) $schwimmer_id,
            'erstellt_am'  => gmdate( 'Y-m-d H:i:s' ),
            'gesehen_am'   => gmdate( 'Y-m-d H:i:s' ),
        ], [ '%s', '%s', '%d', '%s', '%s' ] );

        self::cookie_setzen( $selektor . ':' . $geheim, time() + LSV07S_DB::SITZUNG_MAX );
        self::aufraeumen();
    }

    private static function cookie_setzen( $wert, $ablauf ) {
        self::cookie_schreiben( self::COOKIE, $wert, $ablauf );
        $_COOKIE[ self::COOKIE ] = $wert;
    }

    /** Die einzige Stelle im Plugin, die ein Cookie schreibt. */
    private static function cookie_schreiben( $name, $wert, $ablauf ) {
        $opt = [
            'expires'  => $ablauf,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if ( is_callable( self::$cookie_senke ) ) {
            call_user_func( self::$cookie_senke, $name, $wert, $opt );
            return;
        }
        if ( headers_sent() ) return;
        setcookie( $name, $wert, $opt );
    }

    /** Meldet ab: Sitzung weg, Cookie weg. */
    public static function abmelden() {
        global $wpdb;
        $teile = self::cookie_teile();
        if ( $teile ) {
            $wpdb->delete( $wpdb->prefix . 'lsv07s_sitzung', [ 'selektor' => $teile[0] ], [ '%s' ] );
        }
        self::cookie_setzen( '', time() - 3600 );
        unset( $_COOKIE[ self::COOKIE ] );
        self::$schwimmer = null;
    }

    private static function cookie_teile() {
        $roh = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
        if ( $roh === '' ) return null;
        $t = explode( ':', $roh, 2 );
        if ( count( $t ) !== 2 ) return null;
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $t[0] ) ) return null;
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $t[1] ) ) return null;
        return $t;
    }

    /**
     * Die angemeldete Person, oder null. Wird mehrfach je Aufruf gefragt —
     * nachgesehen wird einmal.
     */
    public static function schwimmer() {
        if ( self::$schwimmer !== false ) return self::$schwimmer;
        self::$schwimmer = null;

        $teile = self::cookie_teile();
        if ( ! $teile ) return null;

        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT selektor, geheim_hash, schwimmer_id, erstellt_am, gesehen_am
               FROM {$p}lsv07s_sitzung WHERE selektor = %s", $teile[0] ), ARRAY_A );
        if ( ! $row ) return null;

        if ( ! hash_equals( (string) $row['geheim_hash'], hash( 'sha256', $teile[1] ) ) ) {
            return null;
        }

        $jetzt = time();
        if ( strtotime( $row['gesehen_am'] . ' UTC' ) < $jetzt - LSV07S_DB::SITZUNG_RUHE
          || strtotime( $row['erstellt_am'] . ' UTC' ) < $jetzt - LSV07S_DB::SITZUNG_MAX ) {
            $wpdb->delete( $p . 'lsv07s_sitzung', [ 'selektor' => $row['selektor'] ], [ '%s' ] );
            return null;
        }

        $s = LSV07S_Intern::schwimmer( (int) $row['schwimmer_id'] );
        /* Wer aus dem Verein ausgetragen wurde, kommt auch mit gültigem
           Cookie nicht mehr hinein. */
        if ( ! $s || (int) $s['active'] !== 1 ) {
            $wpdb->delete( $p . 'lsv07s_sitzung', [ 'selektor' => $row['selektor'] ], [ '%s' ] );
            return null;
        }

        /* Höchstens einmal je Minute schreiben — sonst kostet jede Abfrage
           einen Schreibzugriff. */
        if ( strtotime( $row['gesehen_am'] . ' UTC' ) < $jetzt - 60 ) {
            $wpdb->update( $p . 'lsv07s_sitzung', [ 'gesehen_am' => gmdate( 'Y-m-d H:i:s' ) ],
                [ 'selektor' => $row['selektor'] ], [ '%s' ], [ '%s' ] );
        }

        return self::$schwimmer = $s;
    }

    public static function schwimmer_id() {
        $s = self::schwimmer();
        return $s ? (int) $s['id'] : 0;
    }

    /** Abgelaufene Sitzungen und alte Sperren wegräumen. */
    public static function aufraeumen() {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07s_sitzung
              WHERE gesehen_am < %s OR erstellt_am < %s",
            gmdate( 'Y-m-d H:i:s', time() - LSV07S_DB::SITZUNG_RUHE ),
            gmdate( 'Y-m-d H:i:s', time() - LSV07S_DB::SITZUNG_MAX ) ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07s_throttle WHERE letzter_am < %s",
            gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) );
    }

    // ── CSRF ────────────────────────────────────────────────────────────

    /**
     * Ein eigenes Token, weil hier niemand bei WordPress angemeldet ist und
     * wp_create_nonce() für abgemeldete Besucher für alle denselben Wert
     * liefert. Doppelte Einreichung: einmal im Cookie, einmal im Feld.
     */
    public static function csrf_token() {
        static $token = null;
        if ( $token !== null ) return $token;
        $vorhanden = isset( $_COOKIE[ self::COOKIE_CSRF ] ) ? (string) $_COOKIE[ self::COOKIE_CSRF ] : '';
        if ( preg_match( '/^[a-f0-9]{64}$/', $vorhanden ) ) {
            return $token = $vorhanden;
        }
        $token = bin2hex( random_bytes( 32 ) );
        self::cookie_schreiben( self::COOKIE_CSRF, $token, time() + 12 * 3600 );
        $_COOKIE[ self::COOKIE_CSRF ] = $token;
        return $token;
    }

    public static function csrf_ok( $eingereicht ) {
        $cookie = isset( $_COOKIE[ self::COOKIE_CSRF ] ) ? (string) $_COOKIE[ self::COOKIE_CSRF ] : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $cookie ) ) return false;
        if ( ! is_string( $eingereicht ) ) return false;
        return hash_equals( $cookie, $eingereicht );
    }
}
