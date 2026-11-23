<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Tresor des internen Bereichs: Atteste, Schwimmer-Dateien,
 * Trainingspläne, Wettkampf-Dokumente, Ticket- und Chat-Anhänge und
 * Profilbilder liegen VERSCHLÜSSELT auf der Platte.
 *
 * Warum. Die Dateien lagen bisher im Klartext unter uploads/lsv07i-private,
 * geschützt nur durch die Sperre des Webservers. Fehlt die (nginx liest keine
 * .htaccess) oder gelangt ein Backup in fremde Hände, lagen Atteste von
 * Kindern offen. Verschlüsselt bekommt, wer die Datei hat, nur Rauschen.
 *
 * Bauart wie im Abrechnungs-Plugin (LSV07I_Tresor), aber eigenständig —
 * beide Plugins laufen auch einzeln:
 *
 *  - AES-256-GCM (Rückfall: sodium secretbox); jede Datei an ihren
 *    Dateinamen gebunden, eine Datei lässt sich nicht unter fremdem Namen
 *    unterschieben.
 *  - Der Schlüssel liegt in einer Datei EINE EBENE ÜBER DEM WEBORDNER
 *    (`…/lsv07-schluessel/intern.php`) — nicht in der Datenbank und in
 *    keinem WordPress-Backup. Nur wo das nicht geht, in der Datenbank.
 *    Vorrang hat die Konstante LSV07I_SCHLUESSEL in wp-config.php
 *    (Notfallkopie).
 *  - Fehlt die Schlüsseldatei später, wird NICHT still ein neuer Schlüssel
 *    erzeugt; neue Dateien werden dann abgelehnt, bis die Notfallkopie
 *    eingetragen ist.
 *  - Alte Dateien (Klartext aus der Zeit davor) verschlüsselt
 *    LSV07I_Umschluesseln im Hintergrund.
 */
class LSV07I_Tresor {

    /** Die Kennung am Anfang. Daran ist ein Tresor-Inhalt zu erkennen. */
    const KENNUNG = "LSV07I1";

    /* Alte Formate ohne Schlüssel-ID — bleiben lesbar. */
    const V_GCM     = 1;   // AES-256-GCM
    const V_SODIUM  = 2;   // sodium_crypto_secretbox (XSalsa20-Poly1305)
    /* Neue Formate mit 4 Byte Schlüssel-ID hinter der Versionsnummer. */
    const V_GCM_ID    = 3;
    const V_SODIUM_ID = 4;

    const OPTION = 'lsv07i_schluessel';

    /** Wo die Schlüsseldatei liegt — nur der Pfad, kein Geheimnis. */
    const DATEI_OPTION = 'lsv07i_schluessel_datei';
    /** Kopf der Schlüsseldatei. `exit` zuerst: Selbst wenn sie je über eine
        Adresse erreichbar wäre, käme eine leere Seite heraus. */
    const DATEI_KOPF = "<?php exit; ?>\nLSV07I-SCHLUESSEL-1\n";
    /** Merkt sich, dass das Anlegen hier nicht geht — damit nicht jeder
        Seitenaufruf es neu versucht. */
    const DATEI_NICHT = 'lsv07i_schluesseldatei_nicht';

    /** Ein Schlüssel muss mindestens so viele verschiedene Bytes haben.
        32 echte Zufallsbytes haben im Mittel ~30; weniger als 16 heisst:
        Da hat jemand „aaaa…" oder „1234…" eingetragen. */
    const MIN_VERSCHIEDEN = 16;

    /** @var array|null ['haupt' => string|null, 'ring' => [id => key], 'fehler' => string] */
    private static $ring = null;

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

    // ── Schlüssel ────────────────────────────────────────────────────────

    /** Die Schlüssel-ID: 4 Bytes, aus dem Schlüssel abgeleitet. Verrät
        nichts über den Schlüssel selbst (HMAC mit festem Text). */
    private static function id( $k ) {
        return substr( hash_hmac( 'sha256', 'lsv07i-schluessel-id', $k, true ), 0, 4 );
    }

    /** Ist dieser Schlüssel stark genug, um damit NEU zu verschlüsseln? */
    public static function stark( $k ) {
        if ( ! is_string( $k ) || strlen( $k ) !== 32 ) return false;
        return count( array_unique( str_split( $k ) ) ) >= self::MIN_VERSCHIEDEN;
    }

    /**
     * Den Ring aufbauen. Einmal je Aufruf.
     *
     * Der Datenbank-Schlüssel wird nur ERZEUGT, wenn keine Konstante gesetzt
     * ist und noch keiner existiert — und dann mit add_option(), das nur
     * anlegt, wenn es den Eintrag noch nicht gibt. Zwei gleichzeitige erste
     * Uploads konnten vorher mit update_option() je einen eigenen Schlüssel
     * schreiben; der zweite überschrieb den ersten, und die erste Datei war
     * für immer verloren. Nach dem Anlegen wird erneut GELESEN: Gewonnen hat,
     * wer zuerst schrieb, und alle benutzen dessen Schlüssel.
     */
    private static function ring() {
        if ( self::$ring !== null ) return self::$ring;
        $ring = []; $haupt = null; $fehler = '';

        $konstante = defined( 'LSV07I_SCHLUESSEL' ) && LSV07I_SCHLUESSEL;
        if ( $konstante ) {
            $k = self::aus_text( (string) LSV07I_SCHLUESSEL );
            if ( $k === null ) {
                /* Steht dort etwas Unbrauchbares, wird NICHT auf die
                   Datenbank ausgewichen: Wer die Konstante setzt, will, dass
                   der Schlüssel nicht in der Datenbank steht. Dann lieber
                   keine neuen Dateien als Dateien unter dem falschen Schlüssel. */
                $fehler = 'LSV07I_SCHLUESSEL in wp-config.php ist unbrauchbar: Er muss 32 Bytes '
                        . 'lang sein (base64 oder 64 Hexzeichen).';
            } else {
                $ring[ self::id( $k ) ] = $k;
                if ( self::stark( $k ) ) {
                    $haupt = $k;
                } else {
                    $fehler = 'LSV07I_SCHLUESSEL in wp-config.php ist zu schwach (kein Zufall). '
                            . 'Er wird nur noch zum Lesen benutzt. Bitte einen neuen erzeugen, etwa mit '
                            . '„openssl rand -base64 32", und den alten unter LSV07I_SCHLUESSEL_ALT '
                            . 'eintragen.';
                }
            }
        }

        /* Ohne Konstante: die Schlüsseldatei über dem Webordner. */
        $datei = $konstante ? null : self::datei_laden( true );
        if ( $datei !== null && $datei['status'] === 'ok' ) {
            foreach ( array_merge( [ $datei['haupt'] ], $datei['alt'] ) as $k ) {
                if ( ! isset( $ring[ self::id( $k ) ] ) ) $ring[ self::id( $k ) ] = $k;
            }
            if ( self::stark( $datei['haupt'] ) ) $haupt = $datei['haupt'];
            else $fehler = 'Der Schlüssel in der Schlüsseldatei ist zu schwach und wird nur noch zum Lesen benutzt.';
        } elseif ( $datei !== null && $datei['status'] === 'fehlt' ) {
            $fehler = 'Die Schlüsseldatei fehlt oder ist nicht lesbar (' . $datei['pfad'] . '). '
                    . 'Bitte den Schlüssel aus der Notfallkopie in wp-config.php eintragen '
                    . '(LSV07I_SCHLUESSEL und LSV07I_SCHLUESSEL_ALT) oder die Datei zurückspielen.';
        }
        $datei_aktiv = $datei !== null && $datei['status'] !== 'keine';

        /* Der Datenbank-Schlüssel bleibt IMMER lesbar — auch wenn inzwischen
           eine Konstante oder Schlüsseldatei da ist. Sonst wären alle Dateien
           von vorher mit einem Schlag verloren. Neu angelegt wird er nur,
           wenn es weder Konstante noch Schlüsseldatei gibt. */
        $roh = get_option( self::OPTION, '' );
        if ( ! $konstante && ! $datei_aktiv && ( ! is_string( $roh ) || $roh === '' ) ) {
            $roh = self::db_schluessel_anlegen();
        }
        if ( is_string( $roh ) && $roh !== '' ) {
            $k = self::aus_text( $roh );
            if ( $k !== null ) {
                if ( ! isset( $ring[ self::id( $k ) ] ) ) $ring[ self::id( $k ) ] = $k;
                if ( ! $konstante && ! $datei_aktiv ) {
                    if ( self::stark( $k ) ) $haupt = $k;
                    else $fehler = 'Der Schlüssel in der Datenbank ist zu schwach und wird nur noch '
                                 . 'zum Lesen benutzt. Bitte LSV07I_SCHLUESSEL in wp-config.php setzen.';
                }
            }
        }
        if ( ! $konstante && ! $datei_aktiv && $haupt === null && $fehler === '' ) {
            $fehler = 'Für die Dateien liess sich kein Schlüssel erzeugen oder lesen.';
        }

        /* Alte Schlüssel für einen Wechsel: nur lesen, nie schreiben. */
        if ( defined( 'LSV07I_SCHLUESSEL_ALT' ) && LSV07I_SCHLUESSEL_ALT ) {
            foreach ( preg_split( '/[\s,;]+/', (string) LSV07I_SCHLUESSEL_ALT ) as $t ) {
                $k = self::aus_text( $t );
                if ( $k !== null && ! isset( $ring[ self::id( $k ) ] ) ) $ring[ self::id( $k ) ] = $k;
            }
        }

        $quelle = $konstante ? 'konstante'
                : ( $datei_aktiv ? ( $datei['status'] === 'ok' ? 'datei' : 'datei_fehlt' ) : 'datenbank' );
        return self::$ring = [ 'haupt' => $haupt, 'ring' => $ring, 'fehler' => $fehler,
                               'quelle' => $quelle, 'datei' => $datei ];
    }

    // ── Die Schlüsseldatei ───────────────────────────────────────────────

    /** Der gespeicherte Pfad der Schlüsseldatei, oder ''. */
    private static function datei_pfad() {
        $p = function_exists( 'get_option' ) ? get_option( self::DATEI_OPTION, '' ) : '';
        $p = is_string( $p ) ? $p : '';
        if ( $p === '' || strpos( $p, "\0" ) !== false || substr( $p, -4 ) !== '.php' ) return '';
        return $p;
    }

    /**
     * Die Schlüsseldatei laden — und, wenn es noch keine gibt, anlegen.
     *
     * @return array status 'ok' (mit haupt, alt, pfad), 'fehlt' (Pfad
     *               bekannt, Datei weg oder kaputt) oder 'keine'.
     */
    private static function datei_laden( $anlegen ) {
        $pfad = self::datei_pfad();
        if ( $pfad === '' ) {
            if ( ! $anlegen ) return [ 'status' => 'keine', 'pfad' => '' ];
            $pfad = self::datei_anlegen();
            if ( $pfad === '' ) return [ 'status' => 'keine', 'pfad' => '' ];
        }
        $inhalt = self::datei_parsen( $pfad );
        if ( $inhalt === null ) return [ 'status' => 'fehlt', 'pfad' => $pfad ];
        return [ 'status' => 'ok', 'pfad' => $pfad ] + $inhalt;
    }

    /** Haupt- und Lese-Schlüssel aus der Datei, oder null. */
    private static function datei_parsen( $pfad ) {
        $roh = @file_get_contents( $pfad );
        if ( ! is_string( $roh ) || strpos( $roh, 'LSV07I-SCHLUESSEL-1' ) === false ) return null;
        $haupt = null; $alt = [];
        foreach ( preg_split( '/\r?\n/', $roh ) as $z ) {
            if ( ! preg_match( '/^(haupt|alt)=([A-Za-z0-9+\/=]{40,})$/', trim( $z ), $m ) ) continue;
            $k = self::aus_text( $m[2] );
            if ( $k === null ) continue;
            if ( $m[1] === 'haupt' && $haupt === null ) $haupt = $k;
            elseif ( $m[1] === 'alt' ) $alt[] = $k;
        }
        return $haupt === null ? null : [ 'haupt' => $haupt, 'alt' => $alt ];
    }

    /**
     * Wo die Schlüsseldatei hin soll: eine Ebene über dem Webordner, oder
     * der Ordner aus LSV07I_SCHLUESSEL_ORDNER. Nie innerhalb des Webordners.
     * '' heisst: Das lässt sich hier nicht sicher bestimmen.
     */
    public static function datei_ordner_vorschlag() {
        $doc = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : false;
        if ( defined( 'LSV07I_SCHLUESSEL_ORDNER' ) && is_string( LSV07I_SCHLUESSEL_ORDNER )
             && LSV07I_SCHLUESSEL_ORDNER !== '' ) {
            $ordner = rtrim( LSV07I_SCHLUESSEL_ORDNER, '/\\' );
        } else {
            /* Ohne Webordner (Kommandozeile, Cron ohne Webserver) wird nicht
               geraten — angelegt wird beim nächsten normalen Seitenaufruf. */
            if ( ! $doc ) return '';
            $abs = realpath( ABSPATH );
            /* Liegt WordPress nicht im Webordner, ist die Lage unklar:
               lieber nichts anlegen als an einer falschen Stelle. */
            if ( ! $abs || strpos( rtrim( $abs, '/' ) . '/', rtrim( $doc, '/' ) . '/' ) !== 0 ) return '';
            $eltern = dirname( $doc );
            if ( $eltern === $doc || strlen( $eltern ) < 2 ) return '';
            $ordner = $eltern . '/lsv07-schluessel';
        }
        if ( $doc && strpos( rtrim( $ordner, '/' ) . '/', rtrim( $doc, '/' ) . '/' ) === 0 ) return '';
        return $ordner;
    }

    /**
     * Die Schlüsseldatei einmalig anlegen.
     *
     * @return string Pfad der Datei, oder '' wenn es hier nicht geht.
     */
    private static function datei_anlegen() {
        if ( ! function_exists( 'add_option' ) ) return '';
        if ( function_exists( 'get_transient' ) && get_transient( self::DATEI_NICHT ) ) return '';

        $nicht = function () {
            if ( function_exists( 'set_transient' ) ) set_transient( self::DATEI_NICHT, 1, HOUR_IN_SECONDS );
            return '';
        };
        /* Ohne Webordner (Cron, Kommandozeile) lässt sich der Ort nicht
           bestimmen. Das heisst nicht „geht hier nicht" — also nichts
           merken, der nächste normale Seitenaufruf versucht es. */
        if ( empty( $_SERVER['DOCUMENT_ROOT'] ) && ! defined( 'LSV07I_SCHLUESSEL_ORDNER' ) ) return '';
        $ordner = self::datei_ordner_vorschlag();
        if ( $ordner === '' ) return $nicht();
        if ( ! is_dir( $ordner ) ) @mkdir( $ordner, 0700, true );
        if ( ! is_dir( $ordner ) || ! is_writable( $ordner ) ) return $nicht();
        /* Doppelt hält besser: Liegt der Ordner bei einem Hoster doch im
           Netz, sperrt Apache ihn hiermit. */
        if ( ! file_exists( $ordner . '/.htaccess' ) ) {
            @file_put_contents( $ordner . '/.htaccess', "Require all denied\nDeny from all\n" );
        }

        $pfad = $ordner . '/intern.php';
        $selbst = false;
        if ( ! file_exists( $pfad ) ) {
            try { $k = random_bytes( 32 ); } catch ( Throwable $e ) { return $nicht(); }
            /* 'x': nur anlegen, wenn es die Datei noch nicht gibt. Zwei
               gleichzeitige Aufrufe schreiben so nicht zwei Schlüssel. */
            $fh = @fopen( $pfad, 'x' );
            if ( $fh ) {
                $selbst = true;
                @fwrite( $fh, self::DATEI_KOPF . 'haupt=' . base64_encode( $k ) . "\n" );
                @fclose( $fh );
                @chmod( $pfad, 0600 );
            }
        }
        /* Gelesen wird, was WIRKLICH dort steht — der eigene Schlüssel oder
           der eines gleichzeitigen Aufrufs. */
        $inhalt = self::datei_parsen( $pfad );
        if ( $inhalt === null || ! self::stark( $inhalt['haupt'] ) ) {
            if ( $selbst ) @unlink( $pfad );
            return $nicht();
        }
        add_option( self::DATEI_OPTION, $pfad, '', 'no' );
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( self::DATEI_OPTION, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }
        $gespeichert = get_option( self::DATEI_OPTION, '' );
        if ( $gespeichert !== $pfad ) return is_string( $gespeichert ) ? $gespeichert : '';
        if ( $selbst && class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'tresor.schluesseldatei', [ 'bereich' => 'System', 'details' => 'angelegt' ] );
        }
        /* Alte Inhalte liegen noch unter dem Datenbank-Schlüssel — umschlüsseln. */
        if ( class_exists( 'LSV07I_Umschluesseln' ) ) LSV07I_Umschluesseln::planen();
        return $pfad;
    }

    /**
     * Einen Schlüssel als Lese-Schlüssel in die Datei aufnehmen.
     * Geschrieben wird in eine Nachbardatei, die geprüft und dann in einem
     * Zug umbenannt wird — eine halb geschriebene Schlüsseldatei gibt es nie.
     */
    public static function alt_in_datei( $k ) {
        $d = self::ring()['datei'];
        if ( ! is_array( $d ) || $d['status'] !== 'ok' || ! is_string( $k ) || strlen( $k ) !== 32 ) return false;
        $inh = self::datei_parsen( $d['pfad'] );
        if ( $inh === null ) return false;
        foreach ( array_merge( [ $inh['haupt'] ], $inh['alt'] ) as $x ) {
            if ( hash_equals( $x, $k ) ) return true;
        }
        $alt  = array_merge( $inh['alt'], [ $k ] );
        $text = self::DATEI_KOPF . 'haupt=' . base64_encode( $inh['haupt'] ) . "\n";
        foreach ( $alt as $a ) $text .= 'alt=' . base64_encode( $a ) . "\n";
        try { $tmp = $d['pfad'] . '.neu-' . bin2hex( random_bytes( 4 ) ); } catch ( Throwable $e ) { return false; }
        if ( @file_put_contents( $tmp, $text ) === false ) return false;
        @chmod( $tmp, 0600 );
        $pruef = self::datei_parsen( $tmp );
        if ( $pruef === null || ! hash_equals( $pruef['haupt'], $inh['haupt'] ) || count( $pruef['alt'] ) !== count( $alt ) ) {
            @unlink( $tmp );
            return false;
        }
        if ( ! @rename( $tmp, $d['pfad'] ) ) { @unlink( $tmp ); return false; }
        self::vergessen();
        return true;
    }

    /**
     * Den alten Datenbank-Schlüssel in die Schlüsseldatei verlegen und aus
     * der Datenbank löschen — der letzte Schritt des Umschlüsselns.
     * Gelöscht wird erst, wenn die Datei ihn nachweislich enthält.
     */
    public static function db_schluessel_verlegen() {
        if ( self::quelle() !== 'datei' ) return false;
        $roh = get_option( self::OPTION, '' );
        if ( ! is_string( $roh ) || $roh === '' ) return true;
        $k = self::aus_text( $roh );
        if ( $k === null ) return false;
        if ( ! self::alt_in_datei( $k ) ) return false;
        $d = self::ring()['datei'];
        $inh = is_array( $d ) && $d['status'] === 'ok' ? self::datei_parsen( $d['pfad'] ) : null;
        $drin = false;
        foreach ( $inh ? array_merge( [ $inh['haupt'] ], $inh['alt'] ) : [] as $x ) {
            if ( hash_equals( $x, $k ) ) $drin = true;
        }
        if ( ! $drin ) return false;
        delete_option( self::OPTION );
        if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( self::OPTION, 'options' );
        self::vergessen();
        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'tresor.db_schluessel', [ 'bereich' => 'System', 'details' => 'in Schlüsseldatei verlegt' ] );
        }
        return true;
    }

    // ── Für das Umschlüsseln ─────────────────────────────────────────────

    /** Kennung des Hauptschlüssels (verrät nichts über ihn), oder ''. */
    public static function haupt_id() {
        $k = self::ring()['haupt'];
        return $k === null ? '' : bin2hex( self::id( $k ) );
    }

    /** Woher der Hauptschlüssel kommt: konstante, datei, datei_fehlt, datenbank. */
    public static function quelle() { return self::ring()['quelle']; }

    /**
     * Ist dieser Tresor-Inhalt mit einem ANDEREN als dem Hauptschlüssel
     * geschrieben (oder im alten Format ohne Schlüssel-ID)?
     */
    public static function braucht_umschluesseln( $roh ) {
        $k = self::ring()['haupt'];
        if ( $k === null || ! self::ist_tresor( $roh ) ) return false;
        $p = strlen( self::KENNUNG );
        if ( strlen( $roh ) < $p + 5 ) return true;
        $v = ord( $roh[ $p ] );
        if ( $v !== self::V_GCM_ID && $v !== self::V_SODIUM_ID ) return true;
        return ! hash_equals( self::id( $k ), substr( $roh, $p + 1, 4 ) );
    }

    /**
     * Entschlüsseln NUR mit dem Hauptschlüssel — für die Schlussprüfung:
     * Lässt sich alles ohne die alten Schlüssel öffnen?
     */
    public static function entschluesseln_nur_haupt( $roh, $bindung = '' ) {
        $r = self::ring();
        if ( $r['haupt'] === null ) return null;
        $voll = self::$ring;
        self::$ring['ring'] = [ self::id( $r['haupt'] ) => $r['haupt'] ];
        try {
            return self::entschluesseln( $roh, $bindung );
        } finally {
            self::$ring = $voll;
        }
    }

    /**
     * Die Schlüssel als Notfallkopie — fertig zum Eintragen in wp-config.php.
     * Nur für die Verwaltung, nach Passwortprüfung (siehe Ajax_Admin).
     */
    public static function notfallkopie() {
        $r = self::ring();
        if ( $r['haupt'] === null ) return null;
        $alt = [];
        foreach ( $r['ring'] as $k ) {
            if ( ! hash_equals( $k, $r['haupt'] ) ) $alt[] = base64_encode( $k );
        }
        return [ 'haupt' => base64_encode( $r['haupt'] ), 'alt' => $alt ];
    }

    /**
     * Den Datenbank-Schlüssel anlegen — nur, wenn es noch keinen gibt.
     *
     * Erzeugt wird mit `random_bytes()`; scheitert das, gibt es KEINEN
     * Ersatz aus schwächerer Quelle. Ein Schlüssel, der zu erraten ist,
     * wäre schlimmer als keiner, weil er Sicherheit vortäuscht.
     *
     * @return string Der Inhalt der Option nach dem Anlegen (der eigene
     *                oder der eines gleichzeitigen Aufrufs), oder ''.
     */
    private static function db_schluessel_anlegen() {
        try {
            $k = random_bytes( 32 );
        } catch ( Throwable $e ) {
            return '';
        }
        /* autoload = no: Der Schlüssel gehört nicht in jeden Seitenaufruf. */
        if ( function_exists( 'add_option' ) ) {
            add_option( self::OPTION, base64_encode( $k ), '', 'no' );
            /* Den Zwischenspeicher von WordPress umgehen: Gelesen werden soll,
               was WIRKLICH in der Datenbank steht. */
            if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( self::OPTION, 'options' );
            if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( 'notoptions', 'options' );
        } else {
            update_option( self::OPTION, base64_encode( $k ), false );
        }
        $roh = get_option( self::OPTION, '' );
        return is_string( $roh ) ? $roh : '';
    }

    /**
     * Der Schlüssel, mit dem neu verschlüsselt wird — oder null.
     *
     * Für Aufrufer, die nur wissen wollen, ob überhaupt einer da ist.
     */
    public static function schluessel() {
        return self::ring()['haupt'];
    }

    /** Den Zwischenspeicher vergessen — nur für die Prüfungen. */
    public static function vergessen() { self::$ring = null; }

    /** 32 Bytes aus base64, hex oder roh. */
    private static function aus_text( $t ) {
        $t = trim( (string) $t );
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
     * Klartext zu Tresor-Inhalt — immer mit dem Hauptschlüssel und seiner ID.
     *
     * @param string $klartext
     * @param string $bindung  Woran der Inhalt gebunden wird — der
     *                         Dateiname auf der Platte (siehe bindung()). Ein Inhalt lässt sich damit
     *                         nicht an eine andere Stelle umhängen: Die
     *                         Beglaubigung schlägt fehl.
     * @return string|null Null, wenn dieser Server es nicht kann oder kein
     *                     brauchbarer Schlüssel da ist. NIE Klartext.
     */
    public static function verschluesseln( $klartext, $bindung = '' ) {
        $k = self::ring()['haupt'];
        if ( $k === null ) return null;
        $v = self::verfahren();

        try {
            if ( $v === 'gcm' ) {
                $kopf = self::KENNUNG . chr( self::V_GCM_ID ) . self::id( $k );
                $iv   = random_bytes( 12 );
                $tag  = '';
                /* Der Kopf gehört mit in die Beglaubigung: Wer Version oder
                   Schlüssel-ID verändert, bekommt beim Lesen null. */
                $ct = openssl_encrypt( (string) $klartext, 'aes-256-gcm', $k,
                    OPENSSL_RAW_DATA, $iv, $tag, $kopf . (string) $bindung, 16 );
                if ( $ct === false || strlen( (string) $tag ) !== 16 ) return null;
                return $kopf . $iv . $tag . $ct;
            }

            if ( $v === 'sodium' ) {
                /* secretbox beglaubigt selbst, kennt aber keine Zusatzdaten.
                   Die Bindung wandert darum in den Klartext und wird beim
                   Lesen verglichen. */
                $kopf  = self::KENNUNG . chr( self::V_SODIUM_ID ) . self::id( $k );
                $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                $inhalt = pack( 'n', strlen( (string) $bindung ) ) . (string) $bindung . (string) $klartext;
                $ct = sodium_crypto_secretbox( $inhalt, $nonce, $k );
                if ( ! is_string( $ct ) ) return null;
                return $kopf . $nonce . $ct;
            }
        } catch ( Throwable $e ) {
            return null;
        }
        return null;
    }

    /** Trägt dieser Inhalt die Kennung des Tresors? */
    public static function ist_tresor( $roh ) {
        return strncmp( (string) $roh, self::KENNUNG, strlen( self::KENNUNG ) ) === 0;
    }

    /**
     * Tresor-Inhalt zu Klartext — mit dem passenden Schlüssel aus dem Ring.
     *
     * @return string|null Null, wenn kein Schlüssel passt, der Inhalt
     *                     verändert wurde oder das Verfahren fehlt. Es wird
     *                     NICHTS teilweise zurückgegeben: Halb
     *                     entschlüsselte Bytes wären schlimmer als nichts.
     */
    public static function entschluesseln( $roh, $bindung = '' ) {
        $roh = (string) $roh;
        if ( ! self::ist_tresor( $roh ) ) return null;
        $p = strlen( self::KENNUNG );
        if ( strlen( $roh ) < $p + 1 ) return null;
        $version = ord( $roh[ $p ] );
        $ring    = self::ring()['ring'];
        if ( ! $ring ) return null;

        if ( $version === self::V_GCM_ID || $version === self::V_SODIUM_ID ) {
            if ( strlen( $roh ) < $p + 5 ) return null;
            $kid  = substr( $roh, $p + 1, 4 );
            $kopf = substr( $roh, 0, $p + 5 );
            $rest = substr( $roh, $p + 5 );
            /* Mit ID: genau dieser Schlüssel. Fehlt er im Ring, ist der
               Inhalt mit einem Schlüssel geschrieben, den diese Anlage nicht
               (mehr) kennt — raten hilft dann nicht. */
            if ( ! isset( $ring[ $kid ] ) ) return null;
            $k = $ring[ $kid ];
            return $version === self::V_GCM_ID
                ? self::gcm_auf( $rest, $k, $kopf . (string) $bindung )
                : self::sodium_auf( $rest, $k, (string) $bindung );
        }

        if ( $version === self::V_GCM || $version === self::V_SODIUM ) {
            /* Altes Format ohne ID: jeden Schlüssel des Rings probieren. */
            $rest = substr( $roh, $p + 1 );
            foreach ( $ring as $k ) {
                $kl = $version === self::V_GCM
                    ? self::gcm_auf( $rest, $k, (string) $bindung )
                    : self::sodium_auf( $rest, $k, (string) $bindung );
                if ( $kl !== null ) return $kl;
            }
            return null;
        }

        /* Eine Kennung mit unbekannter Version: Das ist ein Inhalt aus einer
           NEUEREN Fassung des Plugins. Raten wäre falsch. */
        return null;
    }

    private static function gcm_auf( $rest, $k, $aad ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) return null;
        if ( strlen( $rest ) < 28 ) return null;
        $kl = openssl_decrypt( substr( $rest, 28 ), 'aes-256-gcm', $k,
            OPENSSL_RAW_DATA, substr( $rest, 0, 12 ), substr( $rest, 12, 16 ), $aad );
        return $kl === false ? null : $kl;
    }

    private static function sodium_auf( $rest, $k, $bindung ) {
        if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) return null;
        $nb = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ( strlen( $rest ) < $nb + 1 ) return null;
        try {
            $kl = sodium_crypto_secretbox_open( substr( $rest, $nb ), substr( $rest, 0, $nb ), $k );
        } catch ( Throwable $e ) {
            return null;
        }
        if ( ! is_string( $kl ) || strlen( $kl ) < 2 ) return null;
        $len = unpack( 'n', substr( $kl, 0, 2 ) )[1];
        if ( strlen( $kl ) < 2 + $len ) return null;
        if ( ! hash_equals( $bindung, substr( $kl, 2, $len ) ) ) return null;
        return substr( $kl, 2 + $len );
    }

    // ── Abgelegte Dateien ────────────────────────────────────────────────

    /** Woran eine Datei gebunden wird: ihren Namen auf der Platte. EINE Stelle. */
    public static function bindung( $pfad ) {
        return 'datei:' . basename( (string) $pfad );
    }

    /**
     * Der Klartext einer abgelegten Datei — der EINZIGE Weg, sie zu lesen.
     * Alte Dateien aus der Zeit vor dem Tresor kommen unverändert zurück
     * (LSV07I_Umschluesseln verschlüsselt sie im Hintergrund).
     *
     * @return string|null Null, wenn die Datei fehlt oder sich nicht öffnen lässt.
     */
    public static function datei_inhalt( $pfad ) {
        $pfad = (string) $pfad;
        if ( $pfad === '' || ! is_file( $pfad ) ) return null;
        $roh = @file_get_contents( $pfad );
        if ( $roh === false ) return null;
        if ( ! self::ist_tresor( $roh ) ) return $roh;
        return self::entschluesseln( $roh, self::bindung( $pfad ) );
    }

    /**
     * Klartext verschlüsselt ablegen. Geschrieben wird in eine Nachbardatei,
     * die geprüft und dann in einem Zug umbenannt wird. Klartext landet NIE
     * auf der Platte: Geht die Verschlüsselung nicht, wird nichts abgelegt.
     */
    public static function datei_ablegen( $pfad, $klartext ) {
        $ct = self::verschluesseln( (string) $klartext, self::bindung( $pfad ) );
        if ( $ct === null ) return false;
        try { $tmp = $pfad . '.neu-' . bin2hex( random_bytes( 4 ) ); } catch ( Throwable $e ) { return false; }
        if ( @file_put_contents( $tmp, $ct ) === false ) return false;
        /* Zurücklesen und öffnen, BEVOR die Datei an ihren Platz kommt. */
        $zurueck = @file_get_contents( $tmp );
        if ( $zurueck !== $ct || self::entschluesseln( $zurueck, self::bindung( $pfad ) ) !== (string) $klartext
             || ! @rename( $tmp, $pfad ) ) {
            @unlink( $tmp );
            return false;
        }
        @chmod( $pfad, 0640 );
        return true;
    }

    /**
     * Eine hochgeladene Datei verschlüsselt ablegen — statt
     * move_uploaded_file(). Die temporäre Datei wird danach gelöscht.
     */
    public static function hochladen( $tmp, $ziel ) {
        if ( ! is_uploaded_file( (string) $tmp ) && ! ( defined( 'LSV07I_PRUEFSTAND' ) && LSV07I_PRUEFSTAND ) ) return false;
        $klar = @file_get_contents( $tmp );
        if ( $klar === false ) return false;
        $ok = self::datei_ablegen( $ziel, $klar );
        unset( $klar );
        @unlink( $tmp );
        return $ok;
    }

    /**
     * Eine schon abgelegte Datei vor Ort (neu) verschlüsseln — für alte
     * Klartext-Dateien und beim Schlüsselwechsel.
     *
     * @return string 'neu', 'ok' (war schon richtig) oder 'fehler'
     */
    public static function datei_verschluesseln( $pfad ) {
        $roh = @file_get_contents( $pfad );
        if ( $roh === false ) return 'fehler';
        if ( self::ist_tresor( $roh ) && ! self::braucht_umschluesseln( $roh ) ) return 'ok';
        $klar = self::ist_tresor( $roh ) ? self::entschluesseln( $roh, self::bindung( $pfad ) ) : $roh;
        if ( $klar === null ) return 'fehler';
        return self::datei_ablegen( $pfad, $klar ) ? 'neu' : 'fehler';
    }

    /** Die Datei ausliefern (Inhalt + Länge). Kopfzeilen setzt der Aufrufer vorher. */
    public static function ausgeben( $klartext ) {
        header( 'Content-Length: ' . strlen( (string) $klartext ) );
        while ( ob_get_level() > 0 ) ob_end_clean();
        echo $klartext;
    }

    // ── Auskunft für die Verwaltung ──────────────────────────────────────

    /** Leer, wenn alles in Ordnung ist; sonst der Grund. */
    public static function hinweis() {
        if ( ! self::kann() ) {
            return 'Dieser Server kann weder AES-256-GCM (OpenSSL) noch sodium. Dateien '
                 . 'werden deshalb NICHT angenommen. Bitten Sie den Hoster, die OpenSSL-Erweiterung für PHP zu aktivieren.';
        }
        $r = self::ring();
        if ( $r['haupt'] === null ) {
            return $r['fehler'] . ' Bis das behoben ist, werden keine Dateien angenommen.';
        }
        if ( $r['fehler'] !== '' ) return $r['fehler'];
        if ( $r['quelle'] === 'datenbank' ) {
            return 'Der Schlüssel liegt in der Datenbank, weil dieser Server keine Datei über dem '
                 . 'Webordner zulässt. Wer eine Datenbanksicherung samt Dateien hat, kann damit '
                 . 'Atteste und Anhänge öffnen. Abhilfe: LSV07I_SCHLUESSEL in wp-config.php setzen.';
        }
        return '';
    }

    /** Was die Verwaltung über den Tresor anzeigt. Nie ein Schlüssel. */
    public static function karte() {
        $v = self::verfahren();
        $r = self::ring();
        return [
            'verfahren' => $v,
            'name'      => [ 'gcm' => 'AES-256-GCM', 'sodium' => 'XSalsa20-Poly1305 (sodium)',
                             'keins' => 'keines' ][ $v ] ?? $v,
            'aktiv'     => self::kann() && $r['haupt'] !== null,
            'schluessel_aus' => [ 'konstante' => 'wp-config.php', 'datei' => 'Schlüsseldatei',
                                  'datei_fehlt' => 'Schlüsseldatei (fehlt!)', 'datenbank' => 'Datenbank' ][ $r['quelle'] ] ?? 'Datenbank',
            'quelle'    => $r['quelle'],
            'datei'     => is_array( $r['datei'] ) ? (string) $r['datei']['pfad'] : '',
            'umschluesseln' => class_exists( 'LSV07I_Umschluesseln' ) ? LSV07I_Umschluesseln::stand() : null,
            /* Wie viele Schlüssel nur noch zum Lesen da sind — damit sichtbar
               ist, dass ein alter Schlüssel nicht einfach weg darf. */
            'lese_schluessel' => max( 0, count( $r['ring'] ) - ( $r['haupt'] !== null ? 1 : 0 ) ),
            'hinweis'   => self::hinweis(),
            /* Die Notfallkopie gibt es nur für die WordPress-Administration —
               der Knopf erscheint auch nur dort. */
            'notfall'   => current_user_can( 'manage_options' ),
        ];
    }
}
