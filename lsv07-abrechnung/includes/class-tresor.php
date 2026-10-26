<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Tresor: Rechnungsbelege liegen VERSCHLÜSSELT auf der Platte.
 *
 * Warum. Bisher hing der Schutz der Belege an drei Dingen: einer `.htaccess`
 * im Ordner, einem Zufallsnamen und der Hoffnung, dass der Webserver die
 * `.htaccess` liest. Nur: **nginx liest sie nicht**, und wer auf fremdem
 * Webspace sitzt, kann die Server-Konfiguration nicht ändern. Dann liegen
 * Rechnungen mit Namen, Anschriften und Bankverbindungen abrufbar im Netz,
 * und das Plugin kann nichts dagegen tun.
 *
 * Mit Verschlüsselung kann es etwas dagegen tun. Wer die Datei bekommt,
 * bekommt Rauschen. Der Schutz hängt dann nicht mehr an einer
 * Server-Einstellung, sondern am Schlüssel — und der liegt nicht im
 * Dateisystem.
 *
 * Verfahren: **AES-256-GCM**. Es verschlüsselt und beglaubigt in einem
 * Durchgang: Eine veränderte Datei fällt beim Entschlüsseln auf, statt
 * stillschweigend Unsinn zu liefern. Fehlt OpenSSL, wird auf
 * `sodium_crypto_secretbox` ausgewichen (seit PHP 7.2 dabei). Fehlt beides,
 * wird NICHT heimlich im Klartext abgelegt — es wird gesagt.
 *
 * Der Schlüssel steht in `wp-config.php` als `LSV07A_SCHLUESSEL`, sonst in
 * der Datenbank. Beides hat eine Kehrseite, und beide sind benannt:
 *
 *  - **In der Datenbank** (Vorgabe): Ein Sicherungsband der Datenbank trägt
 *    den Schlüssel mit, die Belege lassen sich also nach einer
 *    Wiederherstellung öffnen. Dafür nützt er nichts, wenn jemand Datenbank
 *    UND Dateien in die Hand bekommt. Gegen den Fall, um den es hier geht —
 *    ein ausgelieferter Ordner —, hilft er vollständig.
 *  - **In wp-config.php**: Schützt auch gegen den, der nur die Datenbank
 *    hat. Dafür sind die Belege verloren, wenn die Datei verloren geht und
 *    nur die Datenbank gesichert war.
 *
 * Das Dateiformat trägt eine Kennung und eine Versionsnummer, damit eine
 * spätere Fassung alte Dateien noch lesen kann.
 */
class LSV07A_Tresor {

    /** Die Kennung am Dateianfang. Daran ist ein Tresor-Inhalt zu erkennen. */
    const KENNUNG = "LSV07A1";

    const V_GCM     = 1;   // AES-256-GCM
    const V_SODIUM  = 2;   // sodium_crypto_secretbox (XSalsa20-Poly1305)

    const OPTION = 'lsv07a_schluessel';

    private static $cache = null;

    /**
     * Welches Verfahren dieser Server kann.
     *
     * @return string 'gcm', 'sodium' oder 'keins'
     */
    public static function verfahren() {
        if ( function_exists( 'openssl_encrypt' )
             && in_array( 'aes-256-gcm', array_map( 'strtolower',
                    (array) openssl_get_cipher_methods() ), true ) ) {
            return 'gcm';
        }
        if ( function_exists( 'sodium_crypto_secretbox' ) ) return 'sodium';
        return 'keins';
    }

    public static function kann() { return self::verfahren() !== 'keins'; }

    /**
     * Der Schlüssel: 32 Bytes.
     *
     * Aus `LSV07A_SCHLUESSEL` in wp-config.php, sonst aus der Datenbank —
     * und dort beim ersten Mal erzeugt. Erzeugt wird mit `random_bytes()`;
     * scheitert das, gibt es KEINEN Ersatz aus schwächerer Quelle, sondern
     * null. Ein Schlüssel, der zu erraten ist, wäre schlimmer als keiner,
     * weil er Sicherheit vortäuscht.
     */
    public static function schluessel() {
        if ( self::$cache !== null ) return self::$cache ?: null;

        if ( defined( 'LSV07A_SCHLUESSEL' ) && LSV07A_SCHLUESSEL ) {
            $k = self::aus_text( (string) LSV07A_SCHLUESSEL );
            if ( $k !== null ) return self::$cache = $k;
            /* Steht dort etwas Unbrauchbares, wird nicht stillschweigend auf
               die Datenbank ausgewichen: Sonst läge die Hälfte der Belege
               unter dem einen und die andere Hälfte unter dem anderen
               Schlüssel, und niemand wüsste, warum sich manche nicht mehr
               öffnen lassen. `false` heisst „versucht und gescheitert" —
               nur so merkt sich der Zwischenspeicher auch ein Nein. */
            self::$cache = false;
            return null;
        }

        $roh = get_option( self::OPTION, '' );
        if ( is_string( $roh ) && $roh !== '' ) {
            $k = self::aus_text( $roh );
            if ( $k !== null ) return self::$cache = $k;
        }

        /* Erster Beleg: Schlüssel erzeugen. */
        try {
            $k = random_bytes( 32 );
        } catch ( Throwable $e ) {
            self::$cache = false;
            return null;
        }
        /* autoload = no: Der Schlüssel gehört nicht in jeden Seitenaufruf. */
        update_option( self::OPTION, base64_encode( $k ), false );
        return self::$cache = $k;
    }

    /** Den Zwischenspeicher vergessen — nur für die Prüfungen. */
    public static function vergessen() { self::$cache = null; }

    /** 32 Bytes aus base64, hex oder roh. */
    private static function aus_text( $t ) {
        $t = trim( $t );
        if ( $t === '' ) return null;
        if ( strlen( $t ) === 64 && ctype_xdigit( $t ) ) {
            $k = @hex2bin( $t );
            if ( is_string( $k ) && strlen( $k ) === 32 ) return $k;
        }
        $k = base64_decode( $t, true );
        if ( is_string( $k ) && strlen( $k ) === 32 ) return $k;
        if ( strlen( $t ) === 32 ) return $t;
        return null;
    }

    // ── Verschlüsseln und entschlüsseln ──────────────────────────────────

    /**
     * Klartext zu Tresor-Inhalt.
     *
     * @param string $klartext
     * @param string $bindung  Woran der Inhalt gebunden wird — hier der
     *                         Dateiname auf der Platte. Eine Datei lässt
     *                         sich damit nicht in einen anderen Ordner
     *                         umhängen: Die Beglaubigung schlägt fehl.
     * @return string|null Null, wenn dieser Server es nicht kann.
     */
    public static function verschluesseln( $klartext, $bindung = '' ) {
        $k = self::schluessel();
        if ( $k === null ) return null;
        $v = self::verfahren();

        if ( $v === 'gcm' ) {
            $iv  = random_bytes( 12 );
            $tag = '';
            $ct  = openssl_encrypt( (string) $klartext, 'aes-256-gcm', $k,
                OPENSSL_RAW_DATA, $iv, $tag, (string) $bindung, 16 );
            if ( $ct === false || strlen( (string) $tag ) !== 16 ) return null;
            return self::KENNUNG . chr( self::V_GCM ) . $iv . $tag . $ct;
        }

        if ( $v === 'sodium' ) {
            /* secretbox beglaubigt selbst, kennt aber keine Zusatzdaten.
               Die Bindung wandert darum in den Klartext und wird beim Lesen
               verglichen. */
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $inhalt = pack( 'n', strlen( (string) $bindung ) ) . (string) $bindung . (string) $klartext;
            $ct = sodium_crypto_secretbox( $inhalt, $nonce, $k );
            if ( ! is_string( $ct ) ) return null;
            return self::KENNUNG . chr( self::V_SODIUM ) . $nonce . $ct;
        }

        return null;
    }

    /** Trägt dieser Inhalt die Kennung des Tresors? */
    public static function ist_tresor( $roh ) {
        return strncmp( (string) $roh, self::KENNUNG, strlen( self::KENNUNG ) ) === 0;
    }

    /**
     * Tresor-Inhalt zu Klartext.
     *
     * @return string|null Null, wenn der Schlüssel nicht passt, die Datei
     *                     verändert wurde oder das Verfahren fehlt. Es wird
     *                     NICHTS teilweise zurückgegeben: Halb
     *                     entschlüsselte Bytes wären schlimmer als nichts.
     */
    public static function entschluesseln( $roh, $bindung = '' ) {
        $roh = (string) $roh;
        if ( ! self::ist_tresor( $roh ) ) return null;
        $k = self::schluessel();
        if ( $k === null ) return null;

        $p = strlen( self::KENNUNG );
        if ( strlen( $roh ) < $p + 1 ) return null;
        $version = ord( $roh[ $p ] );
        $rest    = substr( $roh, $p + 1 );

        if ( $version === self::V_GCM ) {
            if ( ! function_exists( 'openssl_decrypt' ) ) return null;
            if ( strlen( $rest ) < 28 ) return null;
            $iv  = substr( $rest, 0, 12 );
            $tag = substr( $rest, 12, 16 );
            $ct  = substr( $rest, 28 );
            $kl  = openssl_decrypt( $ct, 'aes-256-gcm', $k,
                OPENSSL_RAW_DATA, $iv, $tag, (string) $bindung );
            return $kl === false ? null : $kl;
        }

        if ( $version === self::V_SODIUM ) {
            if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) return null;
            $nb = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if ( strlen( $rest ) < $nb + 1 ) return null;
            $nonce = substr( $rest, 0, $nb );
            $kl = sodium_crypto_secretbox_open( substr( $rest, $nb ), $nonce, $k );
            if ( ! is_string( $kl ) || strlen( $kl ) < 2 ) return null;
            $len = unpack( 'n', substr( $kl, 0, 2 ) )[1];
            if ( strlen( $kl ) < 2 + $len ) return null;
            if ( ! hash_equals( (string) $bindung, substr( $kl, 2, $len ) ) ) return null;
            return substr( $kl, 2 + $len );
        }

        /* Eine Kennung mit unbekannter Version: Das ist eine Datei aus einer
           NEUEREN Fassung des Plugins. Raten wäre falsch. */
        return null;
    }

    // ── Auskunft für die Verwaltung ──────────────────────────────────────

    /** Leer, wenn alles in Ordnung ist; sonst der Grund. */
    public static function hinweis() {
        if ( ! self::kann() ) {
            return 'Dieser Server kann weder AES-256-GCM (OpenSSL) noch sodium. '
                 . 'Rechnungsbelege werden deshalb UNVERSCHLÜSSELT abgelegt und sind nur '
                 . 'durch die .htaccess im Ordner und ihren Zufallsnamen geschützt. Bitten '
                 . 'Sie den Hoster, die OpenSSL-Erweiterung für PHP zu aktivieren.';
        }
        if ( self::schluessel() === null ) {
            return 'Für die Belege liess sich kein Schlüssel erzeugen oder lesen. Sie werden '
                 . 'deshalb unverschlüsselt abgelegt. Steht in wp-config.php ein '
                 . 'LSV07A_SCHLUESSEL, muss er 32 Bytes lang sein (base64 oder 64 Hexzeichen).';
        }
        return '';
    }

    /** Was die Verwaltung über den Tresor anzeigt. */
    public static function karte() {
        $v = self::verfahren();
        return [
            'verfahren' => $v,
            'name'      => [ 'gcm' => 'AES-256-GCM', 'sodium' => 'XSalsa20-Poly1305 (sodium)',
                             'keins' => 'keines' ][ $v ] ?? $v,
            'aktiv'     => self::kann() && self::schluessel() !== null,
            'schluessel_aus' => defined( 'LSV07A_SCHLUESSEL' ) && LSV07A_SCHLUESSEL
                                ? 'wp-config.php' : 'Datenbank',
            'hinweis'   => self::hinweis(),
        ];
    }
}
