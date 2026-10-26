<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ein ZIP-Archiv, selbst geschrieben.
 *
 * Gebraucht für genau eine Sache: Das Rechnungsarchiv soll sich **jahrweise**
 * herunterladen lassen — ein Ordner mit allen Belegen eines Jahres und einer
 * Übersicht, wie ihn der Steuerberater oder die Kassenprüfung bekommt.
 *
 * Warum nicht `ZipArchive`: Die Erweiterung ist auf vielen Vereins-Hostern
 * nicht aktiv, und dann stünde die Kassenprüfung vor einem Knopf, der nichts
 * tut. Das Format selbst ist überschaubar, solange man nicht komprimiert —
 * und das braucht es hier nicht: PDFs und Fotos sind bereits komprimiert,
 * ein zweiter Durchgang bringt wenige Prozent und kostet Rechenzeit, die ein
 * geteilter Server nicht hat.
 *
 * Geschrieben wird darum mit Methode 0 („stored"), und zwar in einen Puffer
 * und nicht in eine Datei: Ein Jahr Vereinsrechnungen sind einige Dutzend
 * Megabyte, kein Gigabyte.
 */
class LSV07A_Zip {

    private $eintraege = [];
    private $puffer    = '';

    /**
     * Eine Datei aufnehmen.
     *
     * @param string $name   Pfad im Archiv, mit / als Trenner.
     * @param string $inhalt Die Bytes.
     * @param int    $zeit   Unix-Zeit der Datei; 0 nimmt jetzt.
     */
    public function datei( $name, $inhalt, $zeit = 0 ) {
        /* Ein abschliessender / kennzeichnet ein Verzeichnis und muss den
           Namensfilter überleben — sonst entsteht daraus eine leere Datei,
           und der Explorer zeigt keinen Ordner. */
        $ist_ordner = substr( (string) $name, -1 ) === '/';
        $name = self::name_saeubern( $name );
        if ( $name === '' ) return false;
        if ( $ist_ordner ) $name .= '/';
        $inhalt = (string) $inhalt;
        $zeit   = $zeit ?: time();

        $crc    = crc32( $inhalt );
        $laenge = strlen( $inhalt );
        $dos    = self::dos_zeit( $zeit );
        $offset = strlen( $this->puffer );

        /* Der lokale Dateikopf. Version 2.0, keine Besonderheiten, Methode 0.
           Bit 11 des Flaggenfelds sagt „Dateiname ist UTF-8" — ohne das
           zeigt der Windows-Explorer Umlaute falsch an. */
        $this->puffer .= "\x50\x4b\x03\x04"            // Signatur
            . pack( 'v', 20 )                          // benötigte Version
            . pack( 'v', 0x0800 )                      // Flaggen: Name in UTF-8
            . pack( 'v', 0 )                           // Methode 0 = gespeichert
            . pack( 'V', $dos )                        // Zeit und Datum
            . pack( 'V', $crc )
            . pack( 'V', $laenge )                     // komprimiert
            . pack( 'V', $laenge )                     // unkomprimiert
            . pack( 'v', strlen( $name ) )
            . pack( 'v', 0 )                           // keine Zusatzfelder
            . $name
            . $inhalt;

        $this->eintraege[] = [
            'name' => $name, 'crc' => $crc, 'laenge' => $laenge,
            'dos' => $dos, 'offset' => $offset,
        ];
        return true;
    }

    /** Ein Verzeichnis anlegen — damit auch leere Ordner sichtbar sind. */
    public function ordner( $name ) {
        $name = rtrim( (string) $name, '/' );
        if ( trim( $name ) === '' ) return false;
        return $this->datei( $name . '/', '' );
    }

    /** Das fertige Archiv. */
    public function fertig() {
        $zentral = '';
        foreach ( $this->eintraege as $e ) {
            $zentral .= "\x50\x4b\x01\x02"              // Signatur
                . pack( 'v', 0x031E )                   // erzeugt unter Unix, Version 3.0
                . pack( 'v', 20 )
                . pack( 'v', 0x0800 )
                . pack( 'v', 0 )
                . pack( 'V', $e['dos'] )
                . pack( 'V', $e['crc'] )
                . pack( 'V', $e['laenge'] )
                . pack( 'V', $e['laenge'] )
                . pack( 'v', strlen( $e['name'] ) )
                . pack( 'v', 0 )                        // Zusatzfeld
                . pack( 'v', 0 )                        // Kommentar
                . pack( 'v', 0 )                        // Datenträger
                . pack( 'v', 0 )                        // interne Merkmale
                . pack( 'V', 0 )                        // externe Merkmale
                . pack( 'V', $e['offset'] )
                . $e['name'];
        }

        $ende = "\x50\x4b\x05\x06"
            . pack( 'v', 0 ) . pack( 'v', 0 )
            . pack( 'v', count( $this->eintraege ) )
            . pack( 'v', count( $this->eintraege ) )
            . pack( 'V', strlen( $zentral ) )
            . pack( 'V', strlen( $this->puffer ) )
            . pack( 'v', 0 );

        return $this->puffer . $zentral . $ende;
    }

    public function anzahl() { return count( $this->eintraege ); }

    /**
     * Ein Name im Archiv darf nicht aus dem Archiv herausführen.
     *
     * Ein Eintrag wie `../../wp-config.php` ist der bekannte „Zip-Slip":
     * Beim Entpacken landet die Datei ausserhalb des Zielordners. Hier
     * entsteht das Archiv zwar aus eigenen Daten — aber ein Rechnungstitel
     * kommt aus einem Eingabefeld, und was aus einem Eingabefeld kommt,
     * wird geprüft.
     */
    private static function name_saeubern( $name ) {
        $name = str_replace( '\\', '/', (string) $name );
        $teile = [];
        foreach ( explode( '/', $name ) as $t ) {
            $t = trim( $t );
            if ( $t === '' || $t === '.' || $t === '..' ) continue;
            /* Steuerzeichen und die unter Windows verbotenen Zeichen heraus. */
            $t = preg_replace( '/[\x00-\x1F\x7F:*?"<>|]+/', '', $t );
            if ( $t === '' ) continue;
            $teile[] = mb_substr( $t, 0, 150 );
        }
        return implode( '/', $teile );
    }

    /** Zeit und Datum im MS-DOS-Format, wie ZIP es verlangt. */
    private static function dos_zeit( $zeit ) {
        /* Hart auf int: Kommt die Zeit aus einer Konfiguration oder einer
           Datenbankspalte, ist sie eine Zeichenkette — und getdate() wirft
           dann einen TypeError mitten im Schreiben des Archivs. */
        $t = getdate( (int) $zeit );
        /* Vor 1980 gibt es im DOS-Format nicht. */
        if ( $t['year'] < 1980 ) return ( 1 << 21 ) | ( 1 << 16 );
        return ( ( $t['year'] - 1980 ) << 25 ) | ( $t['mon'] << 21 ) | ( $t['mday'] << 16 )
             | ( $t['hours'] << 11 ) | ( $t['minutes'] << 5 ) | ( intdiv( $t['seconds'], 2 ) );
    }
}
