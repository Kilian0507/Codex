<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Seite, auf der ein Schwimmer seinen Reflexionsbogen ausfüllt.
 *
 * Aufruf: `<irgendeine Seite>?swimsys_reflexion=1`. Der Link ist für
 * **alle** gleich und enthält nichts Personenbezogenes — wer ihn
 * weitergibt, gibt keinen Namen weiter. Hereingelassen wird nur, wer
 * zwei Dinge hat:
 *
 * 1. den Zugangsschlüssel (ABC-DEF-GHJ), den der Trainer ausgibt,
 * 2. das Geburtsdatum des Schwimmers.
 *
 * Niemand muss sich anmelden. Entsprechend ist alles, was eine Anmeldung
 * sonst leistet, hier von Hand gebaut:
 *
 * - **Kein Auskunft über das, was fehlt.** Falscher Schlüssel und
 *   falsches Geburtsdatum geben denselben Satz. Sonst könnte man erst
 *   Schlüssel raten und dann Geburtsdaten.
 * - **CSRF**: ein eigenes Token, als Cookie und im Formular (Double
 *   Submit) — ohne angemeldetes Konto gibt es keine Nonce von WordPress.
 * - **Sperre nach Fehlversuchen**, über die gehashte Adresse. Der
 *   Schlüsselraum ist 31^9; die Sperre ist gegen den, der es trotzdem
 *   versucht.
 * - **Die Daten kommen erst nach der Anmeldung.** Die erste Seite ist
 *   nur das Formular. Fragen, Bestzeiten und Antworten holt die Seite in
 *   einem zweiten Aufruf, der das Cookie braucht.
 * - **CSP** mit Nonce für das eine Skript, `default-src 'none'`.
 *
 * Zwei Dinge sind hier anders als im internen Bereich:
 *
 * 1. **Die Sperre wird länger**: 15 Minuten, dann eine Stunde, dann
 *    vier. Dort wird der Zähler nach jeder Sperre auf 0 gesetzt — damit
 *    hat ein Angreifer dauerhaft zehn Versuche je Viertelstunde.
 * 2. **Eine Sperre steht im Protokoll.** Dort steht sie nirgends;
 *    niemand erfährt je, dass jemand Schlüssel durchprobiert hat.
 *    Geschrieben wird die gekürzte Adresse, kein Klartext.
 *
 * Die Tabellen sind dieselben wie im internen Bereich; dessen Seite
 * (`?lsv07i_reflexion=`) kann daneben weiterlaufen.
 */
class LSV07S_Reflexion_Public {

    /** Der Schlüssel in der Adresse. */
    const SCHALTER = 'swimsys_reflexion';

    /** Nach so vielen Fehlversuchen wird gesperrt. */
    const VERSUCHE_MAX = 10;

    /** Die Sperre, in Minuten, je nach Zahl der schon abgelaufenen Sperren. */
    const SPERREN = [ 15, 60, 240 ];

    /** Für den Prüfstand: die Antwort einsammeln statt sie auszugeben. */
    public static $ausgabe = null;

    public static function init() {
        add_action( 'template_redirect', [ __CLASS__, 'vielleicht' ] );
    }

    public static function vielleicht() {
        if ( empty( $_GET[ self::SCHALTER ] ) ) return;
        self::kopfzeilen();
        self::behandeln();
        exit;
    }

    /**
     * Der Ablauf.
     *
     * Getrennt von `vielleicht()`, damit der Prüfstand ihn ohne `exit`
     * rufen kann.
     */
    public static function behandeln() {
        $bogen = null;
        if ( ! empty( $_COOKIE['lsv07s_refl_auf'] ) ) {
            $bogen = self::bogen_aus_cookie( (string) wp_unslash( $_COOKIE['lsv07s_refl_auf'] ) );
        }

        $tat = isset( $_POST['refl_tat'] )
            ? sanitize_key( wp_unslash( $_POST['refl_tat'] ) ) : '';

        if ( $tat === 'auf' ) {
            $erg = self::aufschliessen();
            if ( is_string( $erg ) ) return self::seite_schluessel( $erg );
            $bogen = $erg;
        }

        if ( $tat === 'inhalt' ) {
            if ( ! $bogen ) return self::json( [ 'ok' => false,
                'fehler' => 'Nicht angemeldet.' ], 401 );
            if ( ! self::csrf_passt() ) return self::json( [ 'ok' => false,
                'fehler' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.' ], 403 );
            return self::json( [ 'ok' => true, 'html' => self::bogen_html( $bogen ) ] );
        }

        if ( $tat === 'abgeben' ) {
            if ( ! $bogen ) return self::json( [ 'ok' => false,
                'fehler' => 'Deine Sitzung ist abgelaufen. Bitte gib deinen '
                          . 'Zugangsschlüssel erneut ein.' ], 401 );
            if ( ! self::csrf_passt() ) return self::json( [ 'ok' => false,
                'fehler' => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.' ], 403 );
            if ( (string) $bogen['status'] !== 'offen' ) {
                return self::json( [ 'ok' => false,
                    'fehler' => 'Dieser Bogen ist abgegeben und lässt sich nicht mehr '
                              . 'ändern. Dein Trainer kann ihn wieder freischalten.' ] );
            }
            return self::abgeben( $bogen );
        }

        if ( ! $bogen ) return self::seite_schluessel();
        return self::seite_huelle();
    }

    // ── Anmelden ─────────────────────────────────────────────────────────

    /**
     * Schlüssel und Geburtsdatum prüfen.
     *
     * Zurück kommt der Bogen oder ein Satz für die Seite. Welcher der
     * beiden Faktoren falsch war, steht nie dabei.
     */
    private static function aufschliessen() {
        if ( self::gesperrt() ) {
            return 'Zu viele Fehlversuche. Bitte warte einige Minuten und '
                 . 'versuche es dann erneut.';
        }
        if ( ! self::csrf_passt() ) {
            return 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.';
        }
        $eingabe = LSV07S_Reflexion::norm_schluessel(
            (string) wp_unslash( $_POST['schluessel'] ?? '' ) );
        if ( $eingabe === '' || strlen( $eingabe ) > 40 ) {
            self::fehlversuch();
            return 'Bitte gib einen gültigen Zugangsschlüssel ein.';
        }
        $geb = (string) wp_unslash( $_POST['geburtsdatum'] ?? '' );

        $bogen = self::bogen_zu_schluessel( $eingabe );
        /* Ein Satz für beide Fälle: Sonst sagt die Seite, welcher Teil
           schon stimmt — und man rät erst Schlüssel, dann Geburtsdaten. */
        if ( ! $bogen || strlen( $geb ) > 20
             || ! LSV07S_Reflexion::geburtsdatum_passt( (int) $bogen['swimmer_id'], $geb ) ) {
            self::fehlversuch();
            return 'Zugangsschlüssel oder Geburtsdatum stimmt nicht. Bitte '
                 . 'überprüfe deine Eingabe.';
        }

        self::versuche_weg();
        self::cookie_setzen( $bogen );
        return $bogen;
    }

    /** Den Bogen über den Hash des Schlüssels finden. */
    private static function bogen_zu_schluessel( $norm ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'reflexion' ) ) return null;
        $t = LSV07S_Tabellen::t( 'reflexion' );
        $z = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE schluessel_hash = %s LIMIT 1",
            LSV07S_Reflexion::schluessel_hash( $norm ) ), ARRAY_A );
        return $z ?: null;
    }

    /**
     * Das Cookie, mit dem die Seite weiss, welcher Bogen offen ist.
     *
     * Es trägt die Nummer und eine Signatur über Nummer und
     * Schlüssel-Hash. Wer die Nummer im Cookie ändert, kommt nicht an
     * einen anderen Bogen; und wird ein neuer Schlüssel ausgegeben,
     * passt die Signatur nicht mehr — das Cookie gilt dann nicht.
     */
    private static function cookie_setzen( $bogen ) {
        $wert = (int) $bogen['id'] . '|' . self::signatur( $bogen );
        if ( ! headers_sent() ) {
            setcookie( 'lsv07s_refl_auf', $wert, [
                'expires'  => self::jetzt_ts() + 6 * 3600,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict',
            ] );
        }
        $_COOKIE['lsv07s_refl_auf'] = $wert;
    }

    private static function signatur( $bogen ) {
        return hash_hmac( 'sha256',
            (int) $bogen['id'] . '|' . (string) $bogen['schluessel_hash'],
            function_exists( 'wp_salt' ) ? wp_salt() : 'lsv07s-fallback' );
    }

    private static function bogen_aus_cookie( $wert ) {
        global $wpdb;
        $teile = explode( '|', $wert, 2 );
        if ( count( $teile ) !== 2 ) return null;
        $id = absint( $teile[0] );
        if ( ! $id || ! preg_match( '/^[a-f0-9]{64}$/', (string) $teile[1] ) ) return null;
        if ( ! LSV07S_Tabellen::da( 'reflexion' ) ) return null;
        $t = LSV07S_Tabellen::t( 'reflexion' );
        $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $b ) return null;
        return hash_equals( self::signatur( $b ), (string) $teile[1] ) ? $b : null;
    }

    // ── Sperre nach Fehlversuchen ────────────────────────────────────────

    /**
     * Die Adresse, gehasht.
     *
     * Gespeichert wird nie die Adresse selbst: Für das Zählen genügt ein
     * Wert, der für dieselbe Adresse derselbe ist.
     */
    private static function adresse_hash() {
        $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        return hash_hmac( 'sha256', $ip,
            LSV07S_Reflexion::schluessel_hash( 'sperre-adresse' ) );
    }

    /**
     * Die Uhr des Plugins, als Zeitstempel.
     *
     * EINE Uhr für alles: Gerechnet wird mit derselben Zeit, die auch
     * geschrieben wird (`LSV07S_Datum::jetzt()`). Die erste Fassung
     * verglich `strtotime( $gespeichert )` mit `time()` — sobald die
     * Uhr des Plugins von der des Rechners abweicht, lief der Zähler
     * der Fehlversuche bei jedem Versuch wieder auf 0, und die Sperre
     * griff nie. Im internen Bereich stehen beide Uhren ebenfalls
     * nebeneinander: `current_time('mysql')` ist Ortszeit, die Sperre
     * wird mit `gmdate()` geschrieben.
     */
    private static function jetzt_ts() {
        return (int) strtotime( LSV07S_Datum::jetzt() );
    }

    private static function gesperrt() {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'refl_sperre' ) ) return false;
        $t = LSV07S_Tabellen::t( 'refl_sperre' );
        $bis = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT gesperrt_bis FROM $t WHERE ip_hash = %s", self::adresse_hash() ) );
        return $bis !== '' && strtotime( $bis ) > self::jetzt_ts();
    }

    /**
     * Einen Fehlversuch zählen.
     *
     * Gesperrt wird bei jedem zehnten Fehlversuch, und jede Sperre ist
     * länger als die vorige: 15 Minuten, eine Stunde, vier Stunden. Der
     * Zähler läuft dafür über die Sperren hinweg weiter.
     *
     * Zwei Fristen, und beide haben einen Grund:
     *
     * - **15 Minuten Ruhe** setzen den Zähler zurück, SOLANGE noch keine
     *   Sperre gefallen ist. Sonst sammelt ein Schwimmer, der im Januar
     *   dreimal falsch tippt, bis März genug Versuche für eine Sperre.
     * - **24 Stunden Ruhe** setzen ihn in jedem Fall zurück. Sonst wäre
     *   wer einmal gesperrt war, für immer vorbelastet.
     *
     * Im internen Bereich wird der Zähler nach JEDER Sperre auf 0
     * gestellt. Wer wartet, hat dort dauerhaft zehn Versuche je
     * Viertelstunde — die Sperre kostet nur Zeit, sie hört nie auf.
     */
    private static function fehlversuch() {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'refl_sperre' ) ) return;
        $t = LSV07S_Tabellen::t( 'refl_sperre' );
        $ip = self::adresse_hash();
        $jetzt = LSV07S_Datum::jetzt();
        $z = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE ip_hash = %s LIMIT 1", $ip ), ARRAY_A );

        if ( ! $z ) {
            $wpdb->insert( $t, [ 'ip_hash' => $ip, 'versuche' => 1,
                                 'letzter_am' => $jetzt ], [ '%s', '%d', '%s' ] );
            return;
        }

        $versuche = (int) $z['versuche'];
        $ruhe = self::jetzt_ts() - (int) strtotime( (string) $z['letzter_am'] );
        if ( $ruhe > 24 * 3600
             || ( $ruhe > 15 * 60 && $versuche < self::VERSUCHE_MAX ) ) {
            $versuche = 0;
        }
        $versuche++;

        $gesperrt_bis = $z['gesperrt_bis'] ?? null;
        /* Jeder zehnte Fehlversuch sperrt. Dazwischen bleiben neun — wer
           nach einer Sperre weitermacht, landet in der nächsten, und die
           ist länger. */
        if ( $versuche % self::VERSUCHE_MAX === 0 ) {
            $stufe = (int) ( $versuche / self::VERSUCHE_MAX ) - 1;
            $minuten = self::SPERREN[ min( $stufe, count( self::SPERREN ) - 1 ) ];
            $gesperrt_bis = date( 'Y-m-d H:i:s', self::jetzt_ts() + $minuten * 60 );
            LSV07S_Log::schreibe( 'reflexion.sperre', [
                'bereich' => LSV07S_Sparte::SCHWIMMEN, 'erfolg' => 0,
                'details' => $versuche . ' Fehlversuche, gesperrt für '
                           . $minuten . ' Minuten',
            ] );
        }
        $wpdb->update( $t, [ 'versuche' => $versuche, 'gesperrt_bis' => $gesperrt_bis,
                             'letzter_am' => $jetzt ],
            [ 'id' => (int) $z['id'] ], [ '%d', '%s', '%s' ], [ '%d' ] );
    }

    private static function versuche_weg() {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'refl_sperre' ) ) return;
        $wpdb->delete( LSV07S_Tabellen::t( 'refl_sperre' ),
            [ 'ip_hash' => self::adresse_hash() ], [ '%s' ] );
    }

    // ── CSRF ─────────────────────────────────────────────────────────────

    /**
     * Ein eigenes Token, weil es kein angemeldetes Konto gibt.
     *
     * Es steht im Cookie und im Formular; verglichen werden beide
     * (Double Submit). Ohne das hätte eine fremde Seite den Bogen
     * abgeben können, sobald er einmal offen ist.
     */
    private static function csrf() {
        static $token = null;
        if ( $token !== null ) return $token;
        $da = (string) ( $_COOKIE['lsv07s_refl_csrf'] ?? '' );
        if ( preg_match( '/^[a-f0-9]{64}$/', $da ) ) {
            $token = $da;
            return $token;
        }
        $token = bin2hex( random_bytes( 32 ) );
        if ( ! headers_sent() ) {
            setcookie( 'lsv07s_refl_csrf', $token, [
                'expires'  => self::jetzt_ts() + 6 * 3600,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict',
            ] );
        }
        $_COOKIE['lsv07s_refl_csrf'] = $token;
        return $token;
    }

    private static function csrf_passt() {
        $cookie = (string) ( $_COOKIE['lsv07s_refl_csrf'] ?? '' );
        $feld   = (string) ( $_POST['refl_csrf'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $cookie ) ) return false;
        return hash_equals( $cookie, $feld );
    }

    // ── Kopfzeilen ───────────────────────────────────────────────────────

    private static function nonce() {
        static $n = null;
        if ( $n === null ) $n = base64_encode( random_bytes( 16 ) );
        return $n;
    }

    private static function kopfzeilen() {
        if ( headers_sent() ) return;
        $n = self::nonce();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: DENY' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        header( "Content-Security-Policy: default-src 'none'; "
            . "script-src 'nonce-$n'; style-src 'unsafe-inline'; "
            . "img-src 'self' data:; connect-src 'self'; form-action 'self'; "
            . "base-uri 'none'; frame-ancestors 'none'" );
    }

    // ── Der Bogen ────────────────────────────────────────────────────────

    /**
     * Was im Bogen steht.
     *
     * Erst hier verlassen die Fragen und die Bestzeiten den Server — in
     * einem Aufruf, der das Cookie braucht. Die erste Seite trägt nur
     * das Formular.
     */
    private static function bogen_html( $bogen ) {
        $offen = (string) $bogen['status'] === 'offen';
        $fragen = json_decode( (string) ( $bogen['fragen_snapshot'] ?? '[]' ), true );
        if ( ! is_array( $fragen ) ) $fragen = [];
        $alt = self::antworten( (int) $bogen['id'] );
        $zeiten = LSV07S_Bestzeit::zu_person( (int) $bogen['swimmer_id'] );

        $h = '<h2>Hallo ' . esc_html( self::vorname( $bogen ) ) . '</h2>';
        if ( ! $offen ) {
            $h .= '<p class="s-r-hinweis">Du hast diesen Bogen abgegeben. Wenn du '
                . 'noch etwas ändern willst, sag es deinem Trainer — er kann ihn '
                . 'wieder freischalten.</p>';
        } else {
            $h .= '<p class="s-r-hinweis">Was du hier schreibst, liest nur dein '
                . 'Trainer. Es liegt verschlüsselt in der Datenbank.</p>';
        }

        $h .= '<form id="s-r-form" method="post">'
            . '<input type="hidden" name="refl_tat" value="abgeben">'
            . '<input type="hidden" name="refl_csrf" value="'
            . esc_attr( self::csrf() ) . '">';

        foreach ( $fragen as $f ) {
            $fid = (int) ( $f['id'] ?? 0 );
            $wert = (string) ( $alt['frage'][ $fid ] ?? '' );
            $h .= '<div class="s-r-feld"><label for="s-r-f' . $fid . '">'
                . esc_html( (string) ( $f['frage'] ?? '' ) ) . '</label>'
                . '<textarea id="s-r-f' . $fid . '" name="frage[' . $fid . ']" '
                . 'rows="4" maxlength="5000"' . ( $offen ? '' : ' readonly' ) . '>'
                . esc_textarea( $wert ) . '</textarea></div>';
        }

        if ( $zeiten ) {
            $h .= '<h3>Deine Zeiten</h3>'
                . '<p class="s-r-hinweis">Links steht, was du geschwommen bist. '
                . 'Rechts kannst du schreiben, was du erreichen willst.</p>';
            foreach ( $zeiten as $strecke => $zeit ) {
                $name = LSV07S_Bestzeit::STRECKEN[ $strecke ] ?? $strecke;
                $wert = (string) ( $alt['zeit'][ $strecke ] ?? '' );
                $h .= '<div class="s-r-zeit"><div class="s-r-zeit-n">'
                    . esc_html( $name ) . '</div>'
                    . '<div class="s-r-zeit-i">' . esc_html( $zeit ) . '</div>'
                    . '<input type="text" name="wunsch[' . esc_attr( $strecke ) . ']" '
                    . 'value="' . esc_attr( $wert ) . '" maxlength="20" '
                    . 'placeholder="Wunschzeit"' . ( $offen ? '' : ' readonly' )
                    . '></div>';
            }
        }

        if ( $offen ) {
            $h .= '<button type="submit" class="s-r-btn">Bogen abgeben</button>'
                . '<p class="s-r-hinweis">Nach dem Abgeben kannst du nichts mehr '
                . 'ändern — dein Trainer kann den Bogen aber wieder '
                . 'freischalten.</p>';
        }
        $h .= '</form>';
        return $h;
    }

    /** Die schon gegebenen Antworten, entschlüsselt. */
    private static function antworten( $bogen_id ) {
        global $wpdb;
        $raus = [ 'frage' => [], 'zeit' => [] ];
        if ( ! LSV07S_Tabellen::da( 'refl_antwort' ) ) return $raus;
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'refl_antwort' )
            . ' WHERE bogen_id = %d', (int) $bogen_id ), ARRAY_A );
        foreach ( $zeilen as $z ) {
            if ( (string) $z['typ'] === 'frage' ) {
                $raus['frage'][ (int) $z['frage_id'] ] =
                    LSV07S_Reflexion::entschluesseln( $z['antwort_text'] ?? '' );
            } else {
                $raus['zeit'][ (string) $z['strecke'] ] =
                    LSV07S_Reflexion::entschluesseln( $z['wunschzeit'] ?? '' );
            }
        }
        return $raus;
    }

    /** Der Vorname aus „Nachname, Vorname" — die Seite spricht Kinder an. */
    private static function vorname( $bogen ) {
        $name = (string) $bogen['swimmer_name'];
        if ( strpos( $name, ',' ) !== false ) {
            $teile = array_map( 'trim', explode( ',', $name, 2 ) );
            return $teile[1] !== '' ? $teile[1] : $teile[0];
        }
        $teile = explode( ' ', trim( $name ) );
        return $teile[0];
    }

    // ── Abgeben ──────────────────────────────────────────────────────────

    private static function abgeben( $bogen ) {
        global $wpdb;
        $bogen_id = (int) $bogen['id'];
        if ( ! LSV07S_Tabellen::da( 'refl_antwort' ) ) {
            return self::json( [ 'ok' => false, 'fehler' => 'Die Tabelle fehlt.' ] );
        }
        $a = LSV07S_Tabellen::t( 'refl_antwort' );

        /* Die alten Antworten gehen: Nach einer Freischaltung schreibt
           der Bogen sich neu, und zwei Fassungen derselben Frage wären
           nicht auseinanderzuhalten. */
        $wpdb->delete( $a, [ 'bogen_id' => $bogen_id ], [ '%d' ] );

        $fragen = json_decode( (string) ( $bogen['fragen_snapshot'] ?? '[]' ), true );
        if ( ! is_array( $fragen ) ) $fragen = [];
        $ein = (array) ( $_POST['frage'] ?? [] );
        $sort = 0;
        $gefuellt = 0;
        foreach ( $fragen as $f ) {
            $fid = (int) ( $f['id'] ?? 0 );
            $text = isset( $ein[ $fid ] )
                ? sanitize_textarea_field( wp_unslash( (string) $ein[ $fid ] ) ) : '';
            $text = mb_substr( $text, 0, 5000 );
            if ( trim( $text ) !== '' ) $gefuellt++;
            $wpdb->insert( $a, [
                'bogen_id' => $bogen_id,
                'typ' => 'frage',
                'frage_id' => $fid,
                /* Die Frage wandert in die Antwort: Wird sie später
                   geändert, steht im Bogen weiter die, die beantwortet
                   wurde. */
                'frage_text' => mb_substr( (string) ( $f['frage'] ?? '' ), 0, 1000 ),
                'antwort_text' => LSV07S_Reflexion::verschluesseln( $text ),
                'sort_order' => $sort++,
            ], [ '%d', '%s', '%d', '%s', '%s', '%d' ] );
        }

        $wunsch = (array) ( $_POST['wunsch'] ?? [] );
        foreach ( LSV07S_Bestzeit::zu_person( (int) $bogen['swimmer_id'] ) as $strecke => $zeit ) {
            $w = isset( $wunsch[ $strecke ] )
                ? sanitize_text_field( wp_unslash( (string) $wunsch[ $strecke ] ) ) : '';
            $w = mb_substr( $w, 0, 20 );
            $wpdb->insert( $a, [
                'bogen_id' => $bogen_id,
                'typ' => 'zeit',
                'frage_id' => null,
                'frage_text' => null,
                'antwort_text' => null,
                'strecke' => mb_substr( (string) $strecke, 0, 10 ),
                'zeit_aktuell' => mb_substr( (string) $zeit, 0, 20 ),
                'wunschzeit' => LSV07S_Reflexion::verschluesseln( $w ),
                'sort_order' => $sort++,
            ], [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ] );
        }

        $wpdb->update( LSV07S_Tabellen::t( 'reflexion' ),
            [ 'status' => 'abgegeben', 'abgegeben_am' => LSV07S_Datum::jetzt() ],
            [ 'id' => $bogen_id ], [ '%s', '%s' ], [ '%d' ] );

        /* Im Protokoll steht, DASS abgegeben wurde — nicht, was
           drinsteht. Das liest der Trainer im Bogen, und dieses Lesen
           steht dann auch dort. */
        LSV07S_Log::schreibe( 'reflexion.abgegeben', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'person',
            'ziel_id' => (int) $bogen['swimmer_id'],
            'ziel_name' => (string) $bogen['swimmer_name'],
            'uid' => 0,
            'details' => $gefuellt . ' von ' . count( $fragen ) . ' Fragen beantwortet',
        ] );

        return self::json( [ 'ok' => true, 'html' =>
            '<div class="s-r-fertig"><div class="s-r-haken">✓</div>'
            . '<h2>Abgegeben</h2><p>Deine Antworten sind gespeichert. Dein Trainer '
            . 'kann sie jetzt lesen. Du kannst die Seite schließen.</p></div>' ] );
    }

    // ── Ausgabe ──────────────────────────────────────────────────────────

    private static function json( $daten, $status = 200 ) {
        if ( ! headers_sent() ) {
            if ( function_exists( 'status_header' ) ) status_header( $status );
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
        }
        self::$ausgabe = [ 'art' => 'json', 'status' => $status, 'daten' => $daten ];
        echo wp_json_encode( $daten );
        return self::$ausgabe;
    }

    /** Die Schlüsselabfrage. Hier steht kein Name und kein Datum. */
    private static function seite_schluessel( $fehler = '' ) {
        $body = '<div class="s-r-auf"><h2>Reflexionsbogen</h2>'
            . '<p>Bitte gib deinen Zugangsschlüssel und dein Geburtsdatum ein. '
            . 'Den Schlüssel hast du von deinem Trainer bekommen.</p>'
            . ( $fehler ? '<div class="s-r-fehler">' . esc_html( $fehler )
                        . '</div>' : '' )
            . '<form method="post">'
            . '<input type="hidden" name="refl_tat" value="auf">'
            . '<input type="hidden" name="refl_csrf" value="'
            . esc_attr( self::csrf() ) . '">'
            . '<label for="s-r-key">Zugangsschlüssel</label>'
            . '<input type="text" id="s-r-key" name="schluessel" placeholder="ABC-DEF-GHJ" '
            . 'autocomplete="off" autocapitalize="characters" maxlength="40" required>'
            . '<label for="s-r-geb">Geburtsdatum</label>'
            . '<input type="text" id="s-r-geb" name="geburtsdatum" placeholder="TT.MM.JJJJ" '
            . 'autocomplete="off" inputmode="numeric" maxlength="20" required>'
            . '<button type="submit" class="s-r-btn">Bogen öffnen</button>'
            . '</form></div>';
        return self::seite( 'Reflexionsbogen', $body );
    }

    /**
     * Die Hülle.
     *
     * Sie enthält absichtlich keine Bogendaten: Die holt das Skript in
     * einem zweiten Aufruf, der das Cookie braucht.
     */
    private static function seite_huelle() {
        $csrf = esc_attr( self::csrf() );
        $body = '<div id="s-r-app" class="s-r-laedt">'
              . '<p class="s-r-hinweis">Bogen wird geladen …</p></div>'
              . '<noscript><p class="s-r-fehler">Für diesen Bogen braucht die Seite '
              . 'JavaScript. Bitte schalte es ein oder wende dich an deinen '
              . 'Trainer.</p></noscript>';
        $skript = <<<JS
(function () {
  var app = document.getElementById('s-r-app');
  var csrf = '$csrf';
  function post(daten, dann) {
    var f = new FormData();
    for (var k in daten) f.append(k, daten[k]);
    fetch(window.location.href, { method: 'POST', body: f,
      credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(dann)
      .catch(function () {
        app.innerHTML = '<p class="s-r-fehler">Da ist etwas schiefgegangen. '
          + 'Bitte lade die Seite neu.</p>';
      });
  }
  function zeichne(a) {
    if (!a || !a.ok) {
      app.innerHTML = '<p class="s-r-fehler">'
        + ((a && a.fehler) ? a.fehler : 'Bitte lade die Seite neu.') + '</p>';
      return;
    }
    app.classList.remove('s-r-laedt');
    app.innerHTML = a.html;
    var form = document.getElementById('s-r-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var knopf = form.querySelector('button[type=submit]');
      if (knopf) { knopf.disabled = true; knopf.textContent = 'Wird gesendet …'; }
      var daten = { refl_tat: 'abgeben', refl_csrf: csrf };
      var fd = new FormData(form);
      fd.forEach(function (v, k) { daten[k] = v; });
      post(daten, zeichne);
    });
  }
  post({ refl_tat: 'inhalt', refl_csrf: csrf }, zeichne);
})();
JS;
        return self::seite( 'Reflexionsbogen', $body, $skript );
    }

    /** Eine Seite für sich: eigenes HTML, kein Theme, kein Fremdinhalt. */
    private static function seite( $titel, $body, $skript = '' ) {
        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . esc_html( $titel ) . '</title>'
            . '<style>' . self::stil() . '</style></head><body>'
            . '<main class="s-r-seite">' . $body . '</main>'
            . ( $skript ? '<script nonce="' . esc_attr( self::nonce() ) . '">'
                        . $skript . '</script>' : '' )
            . '</body></html>';
        self::$ausgabe = [ 'art' => 'html', 'status' => 200, 'html' => $html ];
        echo $html;
        return self::$ausgabe;
    }

    /**
     * Das Stilblatt steht hier.
     *
     * Eine eigene Datei bräuchte `style-src 'self'`; inline mit
     * `'unsafe-inline'` nur für Stil ist der kleinere Schnitt — Skripte
     * laufen weiter ausschliesslich mit Nonce.
     */
    private static function stil() {
        return <<<CSS
:root{--bl:#1d4ed8;--g10:#f6f8fc;--g30:#e3e9f2;--g130:#55637a;--g190:#16202e;
  --rt:#b4232a;--rt05:#fdf1f1}
*{box-sizing:border-box}
body{margin:0;background:var(--g10);color:var(--g190);
  font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.s-r-seite{max-width:640px;margin:0 auto;padding:24px 16px 48px}
h2{font-size:22px;margin:0 0 8px}
h3{font-size:17px;margin:24px 0 4px}
label{display:block;font-size:13px;font-weight:600;color:var(--g130);
  margin:14px 0 4px}
input,textarea{width:100%;font:inherit;font-weight:400;padding:10px 12px;
  border:1px solid var(--g30);border-radius:8px;background:#fff;min-height:44px}
textarea{min-height:88px;resize:vertical}
input:focus-visible,textarea:focus-visible,button:focus-visible{
  outline:2px solid var(--bl);outline-offset:2px}
.s-r-btn{margin-top:20px;min-height:46px;padding:0 20px;border:0;border-radius:8px;
  background:var(--bl);color:#fff;font:inherit;font-weight:600;cursor:pointer}
.s-r-btn[disabled]{opacity:.6;cursor:default}
.s-r-auf,.s-r-fertig,#s-r-app{background:#fff;border:1px solid var(--g30);
  border-radius:12px;padding:20px}
.s-r-hinweis{font-size:14px;color:var(--g130)}
.s-r-fehler{background:var(--rt05);border:1px solid var(--rt);border-radius:8px;
  padding:10px 12px;color:var(--rt);font-size:14px;margin:12px 0}
.s-r-feld{margin-bottom:16px}
.s-r-feld label{font-size:15px;color:var(--g190);font-weight:600}
.s-r-zeit{display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
.s-r-zeit-n{flex:1 1 140px;font-size:14px}
.s-r-zeit-i{font-variant-numeric:tabular-nums;font-size:14px;color:var(--g130);
  min-width:72px}
.s-r-zeit input{flex:0 1 140px;width:auto}
.s-r-haken{font-size:40px;color:#1b7a3d;text-align:center}
.s-r-fertig{text-align:center}
CSS;
    }
}
