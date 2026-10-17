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

    /**
     * Gezählt wird zweifach, und beides ist nötig:
     *
     *  - je ADRESSE fängt den ab, der viele Kinder durchprobiert;
     *  - je KONTO den, der ein bestimmtes Kind von wechselnden Adressen aus
     *    angeht. Ohne diese Zählung nützte die erste wenig, denn Adressen
     *    sind billig.
     *
     * Die Zählung je Konto kann man missbrauchen, um ein Kind absichtlich
     * auszusperren. Darum ist ihre Sperre kürzer und ihr Zähler grosszügiger
     * als die der Adresse: Ein ärgerlicher Scherz soll niemanden einen Tag
     * lang aussperren, ein ernsthafter Angriff aber nicht durchkommen.
     */
    public static function ip_hash() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        /* Die Adresse wird nur gehasht abgelegt — keine Klartext-IP. Der
           Hash ist an das WordPress-Salz gebunden; ohne das ist aus ihm
           keine Adresse zurückzurechnen. */
        return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) . '|lsv07s-throttle' );
    }

    private static function konto_hash( $dsv_norm ) {
        return hash_hmac( 'sha256', (string) $dsv_norm, wp_salt( 'auth' ) . '|lsv07s-konto' );
    }

    /** Grenzen je Art: [ Versuche, Sperre in Minuten ]. */
    private static function grenze( $art ) {
        return $art === 'konto' ? [ 12, 10 ] : [ self::MAX_VERSUCHE, self::SPERRE_MIN ];
    }

    private static function gesperrt_bis( $art, $schluessel ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $bis = $wpdb->get_var( $wpdb->prepare(
            "SELECT gesperrt_bis FROM {$p}lsv07s_throttle
              WHERE art = %s AND schluessel = %s", $art, $schluessel ) );
        if ( ! $bis ) return 0;
        $t = strtotime( $bis . ' UTC' );
        return $t > time() ? $t : 0;
    }

    /** Ist die aufrufende Adresse gerade gesperrt? */
    public static function ist_gesperrt() {
        return self::gesperrt_bis( 'ip', self::ip_hash() ) > 0;
    }

    /** Ist dieses Konto gerade gesperrt? */
    public static function konto_gesperrt( $dsv_norm ) {
        return self::gesperrt_bis( 'konto', self::konto_hash( $dsv_norm ) ) > 0;
    }

    private static function fehlversuch_art( $art, $schluessel ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        list( $max, $minuten ) = self::grenze( $art );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT versuche, letzter_am FROM {$p}lsv07s_throttle
              WHERE art = %s AND schluessel = %s", $art, $schluessel ), ARRAY_A );

        $jetzt    = gmdate( 'Y-m-d H:i:s' );
        $versuche = $row ? (int) $row['versuche'] : 0;
        /* Nach der Sperrdauer Ruhe fängt die Zählung von vorn an. */
        if ( $row && strtotime( $row['letzter_am'] . ' UTC' ) < time() - $minuten * 60 ) {
            $versuche = 0;
        }
        $versuche++;

        $gesperrt = null;
        if ( $versuche >= $max ) {
            $gesperrt = gmdate( 'Y-m-d H:i:s', time() + $minuten * 60 );
            $versuche = 0;
        }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07s_throttle (art, schluessel, versuche, gesperrt_bis, letzter_am)
             VALUES (%s, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE versuche = VALUES(versuche),
                                     gesperrt_bis = VALUES(gesperrt_bis),
                                     letzter_am = VALUES(letzter_am)",
            $art, $schluessel, $versuche, $gesperrt, $jetzt ) );
    }

    private static function fehlversuch( $dsv_norm = '' ) {
        self::fehlversuch_art( 'ip', self::ip_hash() );
        if ( $dsv_norm !== '' ) {
            self::fehlversuch_art( 'konto', self::konto_hash( $dsv_norm ) );
        }
    }

    private static function fehlversuche_weg( $dsv_norm = '' ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( $p . 'lsv07s_throttle',
            [ 'art' => 'ip', 'schluessel' => self::ip_hash() ], [ '%s', '%s' ] );
        if ( $dsv_norm !== '' ) {
            $wpdb->delete( $p . 'lsv07s_throttle',
                [ 'art' => 'konto', 'schluessel' => self::konto_hash( $dsv_norm ) ], [ '%s', '%s' ] );
        }
    }

    // ── Anmelden ────────────────────────────────────────────────────────

    /**
     * Prüft die Angaben und legt bei Erfolg eine Sitzung an.
     *
     * Geprüft werden DREI Dinge: DSV-ID, Geburtsdatum und — sofern verlangt
     * — der Zugangscode. Alle drei werden IMMER geprüft, auch wenn schon
     * das erste nicht stimmt, und es wird nie gesagt, welches davon falsch
     * war. Sonst liesse sich damit herausfinden, welche DSV-ID zum Verein
     * gehört und für wen ein Code besteht.
     *
     * Rückgabe: [ 'ok' => true, 'schwimmer' => [...] ]
     *        oder [ 'ok' => false, 'message' => '…', 'gesperrt' => bool ]
     */
    public static function anmelden( $dsv_roh, $datum_roh, $code_roh = '' ) {
        /* Über eine unverschlüsselte Verbindung wird hier gar nichts
           ausgetauscht. Wer das Formular über http öffnet, schickte DSV-ID,
           Geburtsdatum und Code im Klartext durchs Netz — und bekäme danach
           ein Sitzungs-Cookie, das jeder im selben WLAN mitlesen kann. */
        if ( ! self::verbindung_sicher() ) {
            return [ 'ok' => false, 'unsicher' => true,
                     'message' => 'Diese Seite ist gerade nicht über eine verschlüsselte '
                                . 'Verbindung erreichbar (kein HTTPS). Aus Sicherheitsgründen '
                                . 'ist die Anmeldung dann gesperrt. Bitte sag deinem Trainer Bescheid.' ];
        }

        $dsv   = self::dsv_norm( $dsv_roh );
        $datum = self::datum_norm( $datum_roh );

        if ( self::ist_gesperrt()
          || ( $dsv !== '' && self::konto_gesperrt( $dsv ) ) ) {
            LSV07S_DB::spur( 'anmeldung.gesperrt', 0, '', self::ip_hash() );
            return [ 'ok' => false, 'gesperrt' => true,
                     'message' => 'Zu viele Fehlversuche. Bitte warte ein paar Minuten und versuche es dann noch einmal.' ];
        }

        /* Absurde Eingaben gar nicht erst weiterreichen — aber als
           Fehlversuch zählen, sonst liesse sich die Sperre mit Müll umgehen. */
        if ( $dsv === '' || strlen( $dsv ) > 40 || $datum === '' ) {
            self::fehlversuch( $dsv );
            return [ 'ok' => false, 'message' => self::FEHLER ];
        }

        $s          = LSV07S_Intern::schwimmer_by_dsv( $dsv );
        $datum_soll = $s ? substr( (string) $s['birth_date'], 0, 10 ) : '';
        $datum_ok   = $s && $datum_soll !== '' && hash_equals( $datum_soll, $datum );

        /* Der Code wird auch dann geprüft, wenn die Person gar nicht
           gefunden wurde — damit ein unbekanntes Konto genauso lange
           braucht wie ein bekanntes. */
        $code_noetig = $s ? LSV07S_Code::verlangt( (int) $s['id'] ) : true;
        $code_ok     = $code_noetig
            ? LSV07S_Code::passt( $s ? (int) $s['id'] : 0, $code_roh )
            : true;

        if ( ! $datum_ok || ! $code_ok ) {
            self::fehlversuch( $dsv );
            LSV07S_DB::spur( 'anmeldung.fehl', $s ? (int) $s['id'] : 0, '', self::ip_hash() );
            /* Welche Meldung kommt, hängt ALLEIN an der vereinsweiten
               Einstellung — nie daran, ob für dieses Konto ein Code besteht.
               Täte es das, verriete die Meldung in der Stufe „optional",
               ob es diese DSV-ID im Verein überhaupt gibt: Für ein bekanntes
               Kind ohne Code käme die kurze Meldung, für eine unbekannte
               Nummer die lange. Genau diese Auskunft soll es nicht geben. */
            return [ 'ok' => false,
                     'message' => LSV07S_Code::modus() === LSV07S_Code::AUS
                                  ? self::FEHLER : self::FEHLER_CODE ];
        }

        self::fehlversuche_weg( $dsv );
        self::sitzung_anlegen( (int) $s['id'] );
        self::$schwimmer = $s;
        LSV07S_DB::spur( 'anmeldung.ok', (int) $s['id'], '', self::ip_hash() );
        return [ 'ok' => true, 'schwimmer' => $s ];
    }

    /**
     * Läuft das hier über eine verschlüsselte Verbindung?
     *
     * `is_ssl()` sieht einen vorgelagerten Lastverteiler nicht; die
     * üblichen Kopfzeilen dafür werden mitgelesen. Dass sie fälschbar sind,
     * spielt hier keine Rolle: Wer sie fälschen kann, sitzt ohnehin schon
     * zwischen Browser und Server.
     */
    public static function verbindung_sicher() {
        if ( is_ssl() ) return true;
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
          && strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) === 'https' ) return true;
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_SSL'] )
          && strtolower( (string) $_SERVER['HTTP_X_FORWARDED_SSL'] ) === 'on' ) return true;
        /* Entwicklungsrechner: dort gibt es kein Zertifikat, und niemand
           sitzt dazwischen. Nur localhost, nichts sonst. */
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
        $host = preg_replace( '/:\d+$/', '', $host );
        return in_array( $host, [ 'localhost', '127.0.0.1', '[::1]' ], true );
    }

    /* Eine Meldung für jeden Fehlschlag. Wer sie liest, lernt nichts über
       den Datenbestand — insbesondere nicht, WELCHE Angabe falsch war. */
    const FEHLER = 'DSV-ID oder Geburtsdatum stimmt nicht. Bitte prüfe deine Eingabe. '
                 . 'Deine DSV-ID steht in jedem Meldeergebnis; im Zweifel fragst du deinen Trainer.';
    const FEHLER_CODE = 'DSV-ID, Geburtsdatum oder Zugangscode stimmt nicht. Bitte prüfe deine '
                      . 'Eingabe. Den Zugangscode bekommst du von deinem Trainer.';

    // ── Sitzung ─────────────────────────────────────────────────────────

    private static function sitzung_anlegen( $schwimmer_id ) {
        global $wpdb;
        $p        = $wpdb->prefix;
        $selektor = bin2hex( random_bytes( 16 ) );
        $geheim   = bin2hex( random_bytes( 32 ) );

        $wpdb->insert( $p . 'lsv07s_sitzung', [
            'selektor'      => $selektor,
            'geheim_hash'   => hash( 'sha256', $geheim ),
            'geheim_vor'    => '',
            'gewechselt_am' => gmdate( 'Y-m-d H:i:s' ),
            'schwimmer_id'  => (int) $schwimmer_id,
            'erstellt_am'   => gmdate( 'Y-m-d H:i:s' ),
            'gesehen_am'    => gmdate( 'Y-m-d H:i:s' ),
        ], [ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );

        self::cookie_setzen( $selektor . ':' . $geheim );
        self::aufraeumen();
    }

    /**
     * Wie das Sitzungs-Cookie heisst.
     *
     * Über HTTPS mit dem Präfix `__Host-`. Das ist kein Schmuck: Ein Cookie
     * mit diesem Präfix nimmt der Browser nur an, wenn es `Secure` ist, auf
     * `/` liegt und KEINE Domain trägt. Damit kann keine Nebendomain
     * (etwa eine kompromittierte Unterseite des Vereins) ein Cookie für
     * diesen Pfad setzen und uns eine fremde Sitzung unterschieben.
     *
     * Ohne HTTPS geht das Präfix nicht — dort heisst es schlicht wie bisher.
     * Angemeldet wird ohne HTTPS ohnehin niemand.
     */
    public static function cookie_name() {
        return self::verbindung_sicher() && is_ssl()
            ? '__Host-' . self::COOKIE
            : self::COOKIE;
    }

    private static function csrf_name() {
        return self::verbindung_sicher() && is_ssl()
            ? '__Host-' . self::COOKIE_CSRF
            : self::COOKIE_CSRF;
    }

    /**
     * Das Cookie läuft mit der SITZUNG ab, nicht nach Tagen: Es bekommt
     * kein Verfallsdatum und ist weg, sobald der Browser zugeht. Wie lange
     * es gilt, entscheidet allein der Server (Ruhe- und Höchstdauer). Ein
     * Cookie, das 30 Tage auf der Platte liegt, überlebt sonst jedes
     * Abmelden am Server und jeden Geräteverlust.
     */
    private static function cookie_setzen( $wert ) {
        self::cookie_schreiben( self::cookie_name(), $wert, 0 );
        $_COOKIE[ self::cookie_name() ] = $wert;
    }

    private static function cookie_loeschen() {
        self::cookie_schreiben( self::cookie_name(), '', time() - 31536000 );
        unset( $_COOKIE[ self::cookie_name() ] );
    }

    /** Die einzige Stelle im Plugin, die ein Cookie schreibt. */
    private static function cookie_schreiben( $name, $wert, $ablauf ) {
        $opt = [
            'expires'  => $ablauf,
            'path'     => '/',
            'secure'   => self::verbindung_sicher(),
            'httponly' => true,
            /* Strict, nicht Lax: Diese Anwendung wird nie aus einer fremden
               Seite heraus aufgerufen. Mit Lax ginge das Cookie bei jedem
               Klick von aussen mit — und damit bei jedem Link, den jemand
               irgendwo platziert. */
            'samesite' => 'Strict',
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
        $s     = self::schwimmer();
        if ( $teile ) {
            $wpdb->delete( $wpdb->prefix . 'lsv07s_sitzung', [ 'selektor' => $teile[0] ], [ '%s' ] );
        }
        self::cookie_loeschen();
        if ( $s ) LSV07S_DB::spur( 'abmeldung', (int) $s['id'], '', self::ip_hash() );
        self::$schwimmer = null;
    }

    /**
     * Beendet ALLE Sitzungen einer Person. Nötig, wenn der Zugangscode
     * gewechselt oder entzogen wird: Danach soll das alte Gerät nichts mehr
     * können, auch wenn sein Cookie noch gültig wäre.
     */
    public static function alle_sitzungen_beenden( $schwimmer_id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'lsv07s_sitzung',
            [ 'schwimmer_id' => (int) $schwimmer_id ], [ '%d' ] );
        self::$schwimmer = false;
    }

    private static function cookie_teile() {
        $roh = isset( $_COOKIE[ self::cookie_name() ] )
             ? (string) $_COOKIE[ self::cookie_name() ] : '';
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
            "SELECT selektor, geheim_hash, geheim_vor, gewechselt_am,
                    schwimmer_id, erstellt_am, gesehen_am
               FROM {$p}lsv07s_sitzung WHERE selektor = %s", $teile[0] ), ARRAY_A );
        if ( ! $row ) return null;

        $gezeigt = hash( 'sha256', $teile[1] );
        $aktuell = hash_equals( (string) $row['geheim_hash'], $gezeigt );
        $voriges = ! $aktuell && (string) $row['geheim_vor'] !== ''
                   && hash_equals( (string) $row['geheim_vor'], $gezeigt );

        if ( ! $aktuell && ! $voriges ) {
            /* Den Wähler gibt es, das Geheimnis passt nicht. Entweder hat
               jemand geraten, oder hier wird ein altes Cookie vorgezeigt,
               dessen Geheimnis zweimal überholt ist. In beiden Fällen: diese
               Sitzung beenden und es festhalten. Nur DIESE — nicht alle
               Sitzungen der Person: Ein Browser-Tab, der lange offen lag,
               soll niemanden überall aussperren. Häuft es sich bei einem
               Kind, steht das in der Prüfspur. */
            $wpdb->delete( $p . 'lsv07s_sitzung', [ 'selektor' => $row['selektor'] ], [ '%s' ] );
            LSV07S_DB::spur( 'sitzung.fremd', (int) $row['schwimmer_id'],
                'Geheimnis passte nicht', self::ip_hash() );
            self::cookie_loeschen();
            return null;
        }

        $jetzt = time();
        if ( strtotime( $row['gesehen_am'] . ' UTC' ) < $jetzt - LSV07S_DB::SITZUNG_RUHE
          || strtotime( $row['erstellt_am'] . ' UTC' ) < $jetzt - LSV07S_DB::SITZUNG_MAX ) {
            $wpdb->delete( $p . 'lsv07s_sitzung', [ 'selektor' => $row['selektor'] ], [ '%s' ] );
            self::cookie_loeschen();
            return null;
        }

        $s = LSV07S_Intern::schwimmer( (int) $row['schwimmer_id'] );
        /* Wer aus dem Verein ausgetragen wurde, kommt auch mit gültigem
           Cookie nicht mehr hinein. */
        if ( ! $s || (int) $s['active'] !== 1 ) {
            $wpdb->delete( $p . 'lsv07s_sitzung', [ 'selektor' => $row['selektor'] ], [ '%s' ] );
            self::cookie_loeschen();
            return null;
        }

        self::sitzung_nachfuehren( $row, $aktuell, $jetzt );

        return self::$schwimmer = $s;
    }

    /** Wie oft das Sitzungsgeheimnis wechselt (Sekunden). */
    const WECHSEL_NACH = 900;

    /**
     * Zeitstempel fortschreiben und das Geheimnis gegebenenfalls wechseln.
     *
     * Warum wechseln: Ein abgefangenes Cookie gilt sonst, bis es von selbst
     * abläuft. Mit dem Wechsel bekommt der rechtmässige Browser ein neues
     * Geheimnis, und sobald einer von beiden das alte vorzeigt, fliegt die
     * Sitzung auf.
     *
     * Warum das vorige noch gilt: Diese Oberfläche schickt mehrere Abfragen
     * gleichzeitig los. Ohne Übergangsfrist verlöre die zweite Abfrage, die
     * noch mit dem alten Geheimnis unterwegs ist, die Anmeldung mitten im
     * Betrieb — und das Kind stünde ohne Grund wieder vor der Anmeldung.
     */
    private static function sitzung_nachfuehren( array $row, $aktuell, $jetzt ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $gewechselt = $row['gewechselt_am']
            ? strtotime( $row['gewechselt_am'] . ' UTC' )
            : strtotime( $row['erstellt_am'] . ' UTC' );

        /* Gewechselt wird nur, wenn das aktuelle Geheimnis vorgezeigt wurde.
           Kommt gerade das vorige, läuft noch eine ältere Abfrage — dann
           wäre ein weiterer Wechsel genau das, was man nicht will. */
        if ( $aktuell && $gewechselt < $jetzt - self::WECHSEL_NACH ) {
            $neu = bin2hex( random_bytes( 32 ) );
            $wpdb->update( $p . 'lsv07s_sitzung', [
                'geheim_hash'   => hash( 'sha256', $neu ),
                'geheim_vor'    => (string) $row['geheim_hash'],
                'gewechselt_am' => gmdate( 'Y-m-d H:i:s', $jetzt ),
                'gesehen_am'    => gmdate( 'Y-m-d H:i:s', $jetzt ),
            ], [ 'selektor' => $row['selektor'] ],
               [ '%s', '%s', '%s', '%s' ], [ '%s' ] );
            self::cookie_setzen( $row['selektor'] . ':' . $neu );
            return;
        }

        /* Höchstens einmal je Minute schreiben — sonst kostet jede Abfrage
           einen Schreibzugriff. */
        if ( strtotime( $row['gesehen_am'] . ' UTC' ) < $jetzt - 60 ) {
            $wpdb->update( $p . 'lsv07s_sitzung', [ 'gesehen_am' => gmdate( 'Y-m-d H:i:s', $jetzt ) ],
                [ 'selektor' => $row['selektor'] ], [ '%s' ], [ '%s' ] );
        }
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
        $name      = self::csrf_name();
        $vorhanden = isset( $_COOKIE[ $name ] ) ? (string) $_COOKIE[ $name ] : '';
        if ( preg_match( '/^[a-f0-9]{64}$/', $vorhanden ) ) {
            return $token = $vorhanden;
        }
        $token = bin2hex( random_bytes( 32 ) );
        /* Auch dieses Cookie endet mit der Sitzung des Browsers. */
        self::cookie_schreiben( $name, $token, 0 );
        $_COOKIE[ $name ] = $token;
        return $token;
    }

    public static function csrf_ok( $eingereicht ) {
        $name   = self::csrf_name();
        $cookie = isset( $_COOKIE[ $name ] ) ? (string) $_COOKIE[ $name ] : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $cookie ) ) return false;
        if ( ! is_string( $eingereicht ) ) return false;
        if ( ! hash_equals( $cookie, $eingereicht ) ) return false;

        /* Zusätzlich zur doppelten Einreichung: Eine Anfrage, die aus einer
           fremden Seite kommt, wird abgewiesen. Der Browser schickt bei
           SameSite=Strict zwar ohnehin kein Cookie mit — aber ein zweites
           Schloss an derselben Tür kostet hier nichts.
           Fehlt die Kopfzeile ganz (ältere Browser), wird nicht abgelehnt:
           Dann trägt die doppelte Einreichung allein. */
        $ort = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] )
             ? strtolower( (string) $_SERVER['HTTP_SEC_FETCH_SITE'] ) : '';
        if ( $ort !== '' && ! in_array( $ort, [ 'same-origin', 'same-site', 'none' ], true ) ) {
            return false;
        }
        return true;
    }
}
