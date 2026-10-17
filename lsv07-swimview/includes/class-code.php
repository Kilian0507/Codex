<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Zugangscode — der dritte Faktor neben DSV-ID und Geburtsdatum.
 *
 * Warum es ihn gibt: Eine DSV-ID steht in jedem Meldeergebnis, und ein
 * Geburtsdatum weiss im Verein fast jeder. Zwei Angaben, die halb
 * öffentlich sind, sind kein Zugangsschutz — sie sind eine Zuordnung. Der
 * Code macht daraus eine Anmeldung: Er ist zufällig, steht nirgends sonst
 * und wird auf Papier übergeben.
 *
 * Wie er abgelegt wird: NUR als Hash, und zwar mit `wp_hash_password()` —
 * demselben Verfahren, mit dem WordPress Passwörter ablegt (bcrypt bzw.
 * phpass, je nach Fassung). Wer die Datenbank liest, hat damit keinen Code.
 * Im Klartext ist er genau einmal zu sehen: in dem Augenblick, in dem er
 * erzeugt wird.
 *
 * Form: vier Blöcke zu vier Zeichen aus einem Alphabet ohne verwechselbare
 * Zeichen (kein O/0, kein I/1/L). Das sind rund 10^23 Möglichkeiten — mit
 * der Sperre gegen Durchprobieren praktisch nicht zu erraten, aber noch
 * abzutippen.
 */
class LSV07S_Code {

    /** Ohne 0/O, 1/I/L und ohne S/5 — auf Papier sonst zu leicht vertauscht. */
    const ALPHABET = 'ABCDEFGHJKMNPQRTUVWXYZ2346789';
    const BLOECKE  = 4;
    const LAENGE   = 4;

    /** Was die Konfiguration erlaubt. */
    const PFLICHT  = 'pflicht';
    const OPTIONAL = 'optional';
    const AUS      = 'aus';

    /**
     * Wie streng gilt der Code?
     *
     * Voreinstellung ist PFLICHT — ein Plugin, das Daten über Kinder zeigt,
     * wird nicht mit offenen Türen ausgeliefert. Dass dadurch zuerst
     * niemand hineinkommt, ist kein Verlust: Ohne Freigabe gäbe es ohnehin
     * nichts zu sehen, und der Trainer muss so oder so einmal tätig werden.
     */
    public static function modus() {
        $m = (string) LSV07S_DB::config( 'swimview_code_modus', self::PFLICHT );
        return in_array( $m, [ self::PFLICHT, self::OPTIONAL, self::AUS ], true )
            ? $m : self::PFLICHT;
    }

    public static function modus_setzen( $m ) {
        if ( ! in_array( $m, [ self::PFLICHT, self::OPTIONAL, self::AUS ], true ) ) return false;
        LSV07S_DB::config_setzen( 'swimview_code_modus', $m );
        return true;
    }

    /** Wird für diese Person ein Code verlangt? */
    public static function verlangt( $schwimmer_id ) {
        $m = self::modus();
        if ( $m === self::AUS )     return false;
        if ( $m === self::PFLICHT ) return true;
        /* OPTIONAL: verlangt wird er, sobald einer hinterlegt ist. Wer einen
           hat, muss ihn auch benutzen — sonst wäre er wertlos. */
        return self::vorhanden( $schwimmer_id );
    }

    public static function vorhanden( $schwimmer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $h = $wpdb->get_var( $wpdb->prepare(
            "SELECT code_hash FROM {$p}lsv07s_code WHERE schwimmer_id = %d", (int) $schwimmer_id ) );
        return is_string( $h ) && $h !== '';
    }

    /** Einen neuen Code würfeln — kryptografisch, nicht per rand(). */
    public static function wuerfeln() {
        $zeichen = self::ALPHABET;
        $n       = strlen( $zeichen );
        $bloecke = [];
        for ( $b = 0; $b < self::BLOECKE; $b++ ) {
            $teil = '';
            for ( $i = 0; $i < self::LAENGE; $i++ ) {
                $teil .= $zeichen[ random_int( 0, $n - 1 ) ];
            }
            $bloecke[] = $teil;
        }
        return implode( '-', $bloecke );
    }

    /**
     * Eingabe auf die Vergleichsform bringen: Grossbuchstaben, nur Zeichen
     * aus dem Alphabet. Bindestriche, Leerzeichen und Kleinschreibung sind
     * egal — niemand soll an der Schreibweise scheitern.
     *
     * Verwechselbare Zeichen werden NICHT „gerade gezogen". Das lag nahe —
     * wer ein O tippt, meint vielleicht die Null —, wäre aber falsch: Das
     * Alphabet enthält weder O noch 0, ein getipptes O ist also so oder so
     * ein Vertipper. Es auf irgendein vorhandenes Zeichen abzubilden machte
     * aus einem Vertipper stillschweigend einen anderen falschen Code. Dass
     * die Zeichen im Alphabet gar nicht erst vorkommen, ist die Vorsorge;
     * mehr braucht es nicht.
     */
    public static function norm( $roh ) {
        $s = strtoupper( trim( (string) $roh ) );
        $s = preg_replace( '/[^' . self::ALPHABET . ']/', '', $s );
        return (string) $s;
    }

    /** Die Länge, die eine vollständige Eingabe hat. */
    public static function soll_laenge() {
        return self::BLOECKE * self::LAENGE;
    }

    /**
     * Setzt einen neuen Code und gibt ihn EINMAL im Klartext zurück.
     * Ein vorhandener wird dabei ersetzt; alte Sitzungen dieser Person
     * werden beendet, denn der alte Code soll ab sofort nichts mehr nützen.
     */
    public static function neu_setzen( $schwimmer_id, $user_id = 0 ) {
        global $wpdb;
        $schwimmer_id = (int) $schwimmer_id;
        $klartext     = self::wuerfeln();
        $p            = $wpdb->prefix;

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07s_code (schwimmer_id, code_hash, gesetzt_von, gesetzt_am)
             VALUES (%d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash),
                                     gesetzt_von = VALUES(gesetzt_von),
                                     gesetzt_am = VALUES(gesetzt_am),
                                     letzte_nutzung = NULL",
            $schwimmer_id, wp_hash_password( self::norm( $klartext ) ),
            (int) $user_id, current_time( 'mysql' ) ) );

        LSV07S_Login::alle_sitzungen_beenden( $schwimmer_id );
        LSV07S_DB::spur( 'code.neu', $schwimmer_id, 'Zugangscode erzeugt' );

        return $klartext;
    }

    /** Nimmt einer Person den Code weg — damit kommt sie nicht mehr hinein. */
    public static function entfernen( $schwimmer_id, $user_id = 0 ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'lsv07s_code',
            [ 'schwimmer_id' => (int) $schwimmer_id ], [ '%d' ] );
        LSV07S_Login::alle_sitzungen_beenden( (int) $schwimmer_id );
        LSV07S_DB::spur( 'code.weg', (int) $schwimmer_id, 'Zugangscode entfernt' );
        unset( $user_id );
        return true;
    }

    /**
     * Prüft eine Eingabe gegen den hinterlegten Hash.
     *
     * Wichtig: Es wird IMMER gerechnet, auch wenn gar kein Code hinterlegt
     * ist. Sonst wäre an der Antwortzeit zu erkennen, für wen einer besteht
     * — und damit, welche DSV-ID zum Verein gehört.
     */
    public static function passt( $schwimmer_id, $eingabe ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $hash = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT code_hash FROM {$p}lsv07s_code WHERE schwimmer_id = %d", (int) $schwimmer_id ) );

        $norm = self::norm( $eingabe );

        if ( $hash === '' ) {
            /* Gegen einen Blindwert rechnen, damit es genauso lange dauert. */
            wp_check_password( $norm !== '' ? $norm : 'x', self::blindhash() );
            return false;
        }

        if ( ! wp_check_password( $norm, $hash ) ) return false;

        $wpdb->update( $p . 'lsv07s_code',
            [ 'letzte_nutzung' => current_time( 'mysql' ) ],
            [ 'schwimmer_id' => (int) $schwimmer_id ], [ '%s' ], [ '%d' ] );
        return true;
    }

    /** Ein Hash, gegen den nie etwas passt — nur für die gleiche Laufzeit. */
    private static function blindhash() {
        static $h = null;
        if ( $h === null ) $h = wp_hash_password( bin2hex( random_bytes( 16 ) ) );
        return $h;
    }

    /** Stand je Person, fürs Trainerportal — ohne den Code selbst. */
    public static function stand( array $schwimmer_ids ) {
        global $wpdb;
        $ids = array_values( array_unique( array_map( 'intval', $schwimmer_ids ) ) );
        if ( ! $ids ) return [];
        $p     = $wpdb->prefix;
        $platz = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT schwimmer_id, gesetzt_am, letzte_nutzung
               FROM {$p}lsv07s_code WHERE schwimmer_id IN ($platz)", ...$ids ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r['schwimmer_id'] ] = [
                'hat'            => true,
                'gesetzt_am'     => substr( (string) $r['gesetzt_am'], 0, 10 ),
                'letzte_nutzung' => $r['letzte_nutzung']
                                    ? substr( (string) $r['letzte_nutzung'], 0, 16 ) : '',
            ];
        }
        return $out;
    }
}
