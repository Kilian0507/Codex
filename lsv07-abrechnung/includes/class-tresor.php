<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der Tresor: Rechnungsbelege und Bankverbindungen liegen VERSCHLÜSSELT.
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
 * Server-Einstellung, sondern am Schlüssel.
 *
 * Verfahren: **AES-256-GCM**. Es verschlüsselt und beglaubigt in einem
 * Durchgang: Eine veränderte Datei fällt beim Entschlüsseln auf, statt
 * stillschweigend Unsinn zu liefern. Fehlt OpenSSL, wird auf
 * `sodium_crypto_secretbox` ausgewichen (seit PHP 7.2 dabei). Fehlt beides,
 * wird NICHT heimlich im Klartext abgelegt — dann nimmt das Plugin keine
 * Belege an und sagt es.
 *
 * ── Der Schlüsselring ───────────────────────────────────────────────────
 *
 * Bevorzugt steht der Schlüssel in `wp-config.php` als `LSV07A_SCHLUESSEL`.
 * Dann schützt er auch gegen den, der nur eine Datenbanksicherung in die
 * Hand bekommt. Ohne die Konstante liegt er wie bisher in der Datenbank
 * (Option `lsv07a_schluessel`).
 *
 * Wichtig ist, dass beim Umstieg NICHTS unlesbar wird — Belege und IBANs
 * sind echte Daten, ohne zweite Kopie. Darum gibt es einen Ring statt eines
 * einzelnen Schlüssels:
 *
 *  - Geschrieben wird immer mit dem **Hauptschlüssel** (Konstante, sonst
 *    Datenbank).
 *  - Gelesen wird mit jedem Schlüssel im Ring: Konstante, Datenbank-Schlüssel
 *    (bleibt als Alt-Schlüssel lesbar, auch wenn die Konstante dazukommt),
 *    und auf Wunsch weitere alte Schlüssel aus `LSV07A_SCHLUESSEL_ALT`
 *    (durch Komma getrennt) — für einen Schlüsselwechsel.
 *  - Neue Inhalte tragen eine **Schlüssel-ID** (4 Bytes, aus dem Schlüssel
 *    abgeleitet), damit beim Lesen gleich der richtige Schlüssel gewählt
 *    wird. Alte Inhalte ohne ID werden mit jedem Schlüssel des Rings
 *    probiert; die Beglaubigung von GCM bzw. secretbox verhindert, dass ein
 *    falscher Schlüssel stillschweigend Unsinn liefert.
 *
 * ── Die Schlüsseldatei (seit 1.18.0) ─────────────────────────────────
 *
 * Ohne Konstante legt das Plugin den Schlüssel selbst in eine Datei
 * EINE EBENE ÜBER DEM WEBORDNER (`…/lsv07-schluessel/abrechnung.php`).
 * Dort hat sie keine Adresse im Netz, und sie steckt weder in der
 * Datenbank noch in einem WordPress-Backup (BackWPup & Co. sichern nur den
 * WordPress-Ordner). Ein gestohlenes Backup öffnet dann keinen Beleg und
 * keine IBAN mehr.
 *
 *  - Angelegt wird sie einmal, mit exklusivem Anlegen (zwei gleichzeitige
 *    Aufrufe bekommen dieselbe Datei) und erst benutzt, nachdem sie
 *    wieder eingelesen wurde.
 *  - Geht das nicht (Hoster erlaubt kein Schreiben über dem Webordner),
 *    bleibt alles beim Datenbank-Schlüssel — und die Verwaltung sagt es.
 *  - FEHLT die Datei später (Serverumzug ohne sie), wird NICHT still ein
 *    neuer Schlüssel erzeugt: Neue Belege würden sonst unter einem anderen
 *    Schlüssel liegen als die alten. Dann nimmt das Plugin keine Belege an,
 *    bis die Notfallkopie eingespielt ist.
 *  - Die alten Inhalte werden danach umgeschlüsselt (LSV07A_Umschluesseln);
 *    erst wenn alles mit dem neuen Schlüssel lesbar ist, wandert der alte
 *    Datenbank-Schlüssel als Lese-Schlüssel in die Datei und wird aus der
 *    Datenbank gelöscht. So geht unterwegs nichts verloren.
 *
 * Ein **zu schwacher** Schlüssel (nicht 32 Bytes, oder offensichtlich kein
 * Zufall — etwa 32 gleiche Zeichen) wird nicht zum Schreiben benutzt. Er
 * bleibt aber im Ring, damit bereits damit Verschlüsseltes lesbar bleibt.
 */
class LSV07A_Tresor {

    /** Die Kennung am Anfang. Daran ist ein Tresor-Inhalt zu erkennen. */
    const KENNUNG = "LSV07A1";

    /* Alte Formate ohne Schlüssel-ID — bleiben lesbar. */
    const V_GCM     = 1;   // AES-256-GCM
    const V_SODIUM  = 2;   // sodium_crypto_secretbox (XSalsa20-Poly1305)
    /* Neue Formate mit 4 Byte Schlüssel-ID hinter der Versionsnummer. */
    const V_GCM_ID    = 3;
    const V_SODIUM_ID = 4;

    const OPTION = 'lsv07a_schluessel';

    /** Wo die Schlüsseldatei liegt — nur der Pfad, kein Geheimnis. */
    const DATEI_OPTION = 'lsv07a_schluessel_datei';
    /** Kopf der Schlüsseldatei. `exit` zuerst: Selbst wenn sie je über eine
        Adresse erreichbar wäre, käme eine leere Seite heraus. */
    const DATEI_KOPF = "<?php exit; ?>\nLSV07A-SCHLUESSEL-1\n";
    /** Merkt sich, dass das Anlegen hier nicht geht — damit nicht jeder
        Seitenaufruf es neu versucht. */
    const DATEI_NICHT = 'lsv07a_schluesseldatei_nicht';

    /** Ein Schlüssel muss mindestens so viele verschiedene Bytes haben.
        32 echte Zufallsbytes haben im Mittel ~30; weniger als 16 heisst:
        Da hat jemand „aaaa…" oder „1234…" eingetragen. */
    const MIN_VERSCHIEDEN = 16;

    /** Vorsatz für verschlüsselte Felder in der Datenbank (z. B. IBAN).
        Eine IBAN enthält nie einen Doppelpunkt — der Vorsatz ist also
        eindeutig von einem Klartext zu unterscheiden. */
    const FELD = 'tresor1:';

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
        return substr( hash_hmac( 'sha256', 'lsv07a-schluessel-id', $k, true ), 0, 4 );
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
     * schreiben; der zweite überschrieb den ersten, und der erste Beleg war
     * für immer verloren. Nach dem Anlegen wird erneut GELESEN: Gewonnen hat,
     * wer zuerst schrieb, und alle benutzen dessen Schlüssel.
     */
    private static function ring() {
        if ( self::$ring !== null ) return self::$ring;
        $ring = []; $haupt = null; $fehler = '';

        $konstante = defined( 'LSV07A_SCHLUESSEL' ) && LSV07A_SCHLUESSEL;
        if ( $konstante ) {
            $k = self::aus_text( (string) LSV07A_SCHLUESSEL );
            if ( $k === null ) {
                /* Steht dort etwas Unbrauchbares, wird NICHT auf die
                   Datenbank ausgewichen: Wer die Konstante setzt, will, dass
                   der Schlüssel nicht in der Datenbank steht. Dann lieber
                   keine neuen Belege als Belege unter dem falschen Schlüssel. */
                $fehler = 'LSV07A_SCHLUESSEL in wp-config.php ist unbrauchbar: Er muss 32 Bytes '
                        . 'lang sein (base64 oder 64 Hexzeichen).';
            } else {
                $ring[ self::id( $k ) ] = $k;
                if ( self::stark( $k ) ) {
                    $haupt = $k;
                } else {
                    $fehler = 'LSV07A_SCHLUESSEL in wp-config.php ist zu schwach (kein Zufall). '
                            . 'Er wird nur noch zum Lesen benutzt. Bitte einen neuen erzeugen, etwa mit '
                            . '„openssl rand -base64 32", und den alten unter LSV07A_SCHLUESSEL_ALT '
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
                    . '(LSV07A_SCHLUESSEL und LSV07A_SCHLUESSEL_ALT) oder die Datei zurückspielen.';
        }
        $datei_aktiv = $datei !== null && $datei['status'] !== 'keine';

        /* Der Datenbank-Schlüssel bleibt IMMER lesbar — auch wenn inzwischen
           eine Konstante oder Schlüsseldatei da ist. Sonst wären alle Belege
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
                                 . 'zum Lesen benutzt. Bitte LSV07A_SCHLUESSEL in wp-config.php setzen.';
                }
            }
        }
        if ( ! $konstante && ! $datei_aktiv && $haupt === null && $fehler === '' ) {
            $fehler = 'Für die Belege liess sich kein Schlüssel erzeugen oder lesen.';
        }

        /* Alte Schlüssel für einen Wechsel: nur lesen, nie schreiben. */
        if ( defined( 'LSV07A_SCHLUESSEL_ALT' ) && LSV07A_SCHLUESSEL_ALT ) {
            foreach ( preg_split( '/[\s,;]+/', (string) LSV07A_SCHLUESSEL_ALT ) as $t ) {
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
        if ( ! is_string( $roh ) || strpos( $roh, 'LSV07A-SCHLUESSEL-1' ) === false ) return null;
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
     * der Ordner aus LSV07A_SCHLUESSEL_ORDNER. Nie innerhalb des Webordners.
     * '' heisst: Das lässt sich hier nicht sicher bestimmen.
     */
    public static function datei_ordner_vorschlag() {
        $doc = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : false;
        if ( defined( 'LSV07A_SCHLUESSEL_ORDNER' ) && is_string( LSV07A_SCHLUESSEL_ORDNER )
             && LSV07A_SCHLUESSEL_ORDNER !== '' ) {
            $ordner = rtrim( LSV07A_SCHLUESSEL_ORDNER, '/\\' );
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
        /* Die Personenansicht schaut nur — sie legt nichts an. */
        if ( class_exists( 'LSV07A_Rollenansicht' ) && LSV07A_Rollenansicht::laeuft() ) return '';
        if ( function_exists( 'get_transient' ) && get_transient( self::DATEI_NICHT ) ) return '';

        $nicht = function () {
            if ( function_exists( 'set_transient' ) ) set_transient( self::DATEI_NICHT, 1, HOUR_IN_SECONDS );
            return '';
        };
        /* Ohne Webordner (Cron, Kommandozeile) lässt sich der Ort nicht
           bestimmen. Das heisst nicht „geht hier nicht" — also nichts
           merken, der nächste normale Seitenaufruf versucht es. */
        if ( empty( $_SERVER['DOCUMENT_ROOT'] ) && ! defined( 'LSV07A_SCHLUESSEL_ORDNER' ) ) return '';
        $ordner = self::datei_ordner_vorschlag();
        if ( $ordner === '' ) return $nicht();
        if ( ! is_dir( $ordner ) ) @mkdir( $ordner, 0700, true );
        if ( ! is_dir( $ordner ) || ! is_writable( $ordner ) ) return $nicht();
        /* Doppelt hält besser: Liegt der Ordner bei einem Hoster doch im
           Netz, sperrt Apache ihn hiermit. */
        if ( ! file_exists( $ordner . '/.htaccess' ) ) {
            @file_put_contents( $ordner . '/.htaccess', "Require all denied\nDeny from all\n" );
        }

        $pfad = $ordner . '/abrechnung.php';
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
        if ( $selbst && class_exists( 'LSV07A_Log' ) ) {
            LSV07A_Log::schreibe( 'tresor.schluesseldatei', [ 'details' => 'angelegt' ] );
        }
        /* Alte Inhalte liegen noch unter dem Datenbank-Schlüssel — umschlüsseln. */
        if ( class_exists( 'LSV07A_Umschluesseln' ) ) LSV07A_Umschluesseln::planen();
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
        if ( class_exists( 'LSV07A_Log' ) ) {
            LSV07A_Log::schreibe( 'tresor.db_schluessel', [ 'details' => 'in Schlüsseldatei verlegt' ] );
        }
        return true;
    }

    // ── Für das Umschlüsseln ─────────────────────────────────────────────

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
     * @param string $bindung  Woran der Inhalt gebunden wird — bei Belegen
     *                         der Dateiname auf der Platte, bei einer IBAN
     *                         Tabelle und Konto. Ein Inhalt lässt sich damit
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

    // ── Felder in der Datenbank (IBAN) ───────────────────────────────────

    /**
     * Woran eine IBAN gebunden wird. EINE Stelle, damit Schreiben und Lesen
     * dieselbe Bindung benutzen: an Tabelle und Konto. Eine verschlüsselte
     * IBAN lässt sich so nicht in die Zeile einer anderen Person kopieren.
     */
    public static function iban_bindung( $wo, $wp_user_id ) {
        return 'iban:' . (string) $wo . ':' . (int) $wp_user_id;
    }

    /** Ist dieser Feldwert verschlüsselt? */
    public static function ist_feld( $wert ) {
        return strncmp( (string) $wert, self::FELD, strlen( self::FELD ) ) === 0;
    }

    /**
     * Einen Feldwert für die Datenbank verschlüsseln.
     *
     * @return string|null Der verschlüsselte Wert (mit Vorsatz), '' für einen
     *                     leeren Wert, oder null, wenn es nicht geht.
     */
    public static function feld_verschluesseln( $klartext, $bindung ) {
        $klartext = (string) $klartext;
        if ( $klartext === '' ) return '';
        $ct = self::verschluesseln( $klartext, $bindung );
        return $ct === null ? null : self::FELD . base64_encode( $ct );
    }

    /**
     * Einen Feldwert lesen — beide Formate.
     *
     * Klartext aus der Zeit vor der Verschlüsselung kommt unverändert
     * zurück; er wird erst beim nächsten Speichern verschlüsselt. Lässt sich
     * ein verschlüsselter Wert nicht öffnen, kommt '' zurück — nie der
     * Rohstand, denn der sähe aus wie eine (falsche) IBAN.
     */
    public static function feld_lesen( $wert, $bindung ) {
        $wert = (string) $wert;
        if ( ! self::ist_feld( $wert ) ) return $wert;
        $roh = base64_decode( substr( $wert, strlen( self::FELD ) ), true );
        if ( ! is_string( $roh ) ) return '';
        $kl = self::entschluesseln( $roh, $bindung );
        return $kl === null ? '' : $kl;
    }

    // ── Auskunft für die Verwaltung ──────────────────────────────────────

    /** Leer, wenn alles in Ordnung ist; sonst der Grund. */
    public static function hinweis() {
        if ( ! self::kann() ) {
            return 'Dieser Server kann weder AES-256-GCM (OpenSSL) noch sodium. Rechnungsbelege '
                 . 'werden deshalb NICHT angenommen, und Bankverbindungen werden unverschlüsselt '
                 . 'gespeichert. Bitten Sie den Hoster, die OpenSSL-Erweiterung für PHP zu aktivieren.';
        }
        $r = self::ring();
        if ( $r['haupt'] === null ) {
            return $r['fehler'] . ' Bis das behoben ist, werden keine Rechnungsbelege angenommen.';
        }
        if ( $r['fehler'] !== '' ) return $r['fehler'];
        if ( $r['quelle'] === 'datenbank' ) {
            return 'Der Schlüssel liegt in der Datenbank, weil dieser Server keine Datei über dem '
                 . 'Webordner zulässt. Wer eine Datenbanksicherung hat, kann damit Belege und '
                 . 'Bankverbindungen öffnen. Abhilfe: LSV07A_SCHLUESSEL in wp-config.php setzen.';
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
            'umschluesseln' => class_exists( 'LSV07A_Umschluesseln' ) ? LSV07A_Umschluesseln::stand() : null,
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
