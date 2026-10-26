<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Das Rechnungsarchiv — und was sich daraus herunterladen lässt.
 *
 * Gedacht ist es für zwei Leute: die Kassenprüfung, die am Jahresende
 * nachvollziehen will, wohin das Geld gegangen ist, und das Finanzamt oder
 * den Steuerberater, der eine Jahresmappe bekommt. Beide wollen nicht
 * klicken, sondern ein Paket.
 *
 * Darum gibt es drei Dinge:
 *
 *  1. **Das Rechnungsblatt** (PDF): eine Seite je Rechnung mit allem, was
 *     die Überweisung ausmacht — Empfänger, Betrag, Zweck, Verwendungszweck,
 *     und wer wann genehmigt und bezahlt hat. Der Girocode steht mit darauf:
 *     Wer das Blatt ausdruckt, kann es scannen, und wer es ablegt, hat die
 *     Buchung samt Prüfspur auf einem Blatt.
 *  2. **Die Jahresübersicht** (CSV und PDF): eine Zeile je Rechnung. Die CSV
 *     geht in die Buchhaltung, das PDF in den Ordner.
 *  3. **Das Jahrespaket** (ZIP): die Übersicht und je Rechnung ein Ordner
 *     mit Blatt und Originalbelegen.
 *
 * Die Belege werden dabei NICHT umgewandelt, sondern genau so eingepackt,
 * wie sie hochgeladen wurden. Ein umgerechnetes Foto ist kein Originalbeleg
 * mehr, und genau darauf kommt es bei einer Prüfung an.
 */
class LSV07A_Archiv {

    /** Wie der Girocode im PDF aussieht: Kantenlänge in Punkten. */
    const QR_KANTE = 110;

    /**
     * Obergrenze für ein Jahrespaket.
     *
     * Das Archiv entsteht im Arbeitsspeicher: Puffer, Zentralverzeichnis
     * und am Ende die Zusammenfügung — in der Spitze etwa das Dreifache der
     * Paketgröße. Ein Verein mit vielen großen Belegfotos sprengt damit das
     * `memory_limit`, und dann steht die Kassenprüfung vor einer weißen
     * Seite ohne Erklärung. Darum wird VORHER gerechnet und notfalls
     * freundlich abgelehnt.
     */
    const ZIP_MAX_BYTES = 157286400;   // 150 MB

    /** Wie viel Platz ein Rechnungsblatt im Paket ungefähr braucht. */
    const BLATT_BYTES = 30720;         // 30 kB

    // ── Ein Blatt je Rechnung ────────────────────────────────────────────

    /**
     * Eine Rechnung als eine PDF-Seite.
     *
     * @param array|int $r Die Rechnung (voll() oder ihre Nummer).
     * @return string Das PDF, oder '' wenn es die Rechnung nicht gibt.
     */
    public static function blatt( $r ) {
        if ( is_numeric( $r ) ) $r = LSV07A_Rechnung::voll( $r );
        if ( ! $r ) return '';

        $pdf    = new LSV07A_PDF();
        $verein = (string) LSV07A_DB::config( 'verein', '' );
        $links  = 56;
        $rechts = LSV07A_PDF::BREITE - 56;
        /* Gerechnet wird von oben nach unten; der Schreiber zählt von unten. */
        $y = 70;
        $z = fn( $oben ) => LSV07A_PDF::HOEHE - $oben;

        // Kopf
        $pdf->text( $links, $z( $y ), $verein !== '' ? $verein : 'Abrechnung', 9, false, 0.45 );
        $y += 22;
        $pdf->text( $links, $z( $y ), 'Rechnung ' . self::nummer( $r ), 18, true );
        $y += 16;
        $pdf->text( $links, $z( $y ), 'Andere Abrechnung', 9, false, 0.45 );

        /* Der Stand oben rechts — beim Durchblättern eines Ordners ist das
           die Angabe, nach der man sucht. */
        $pdf->text_rechts( $rechts, $z( 92 ), $r['status_name'], 11, true );
        $pdf->text_rechts( $rechts, $z( 70 ),
            'Erstellt ' . date_i18n( 'd.m.Y', current_time( 'timestamp' ) ), 8, false, 0.45 );

        $y += 22;
        $pdf->linie( $links, $z( $y ), $rechts, $z( $y ), 0.8, 0.3 );
        $y += 26;

        // Betreff und Betrag
        foreach ( LSV07A_PDF::umbruch( $r['titel'], $rechts - $links - 150, 14, true ) as $zeile ) {
            $pdf->text( $links, $z( $y ), $zeile, 14, true );
            $y += 19;
        }
        $pdf->text_rechts( $rechts, $z( $y - 19 ), self::geld( $r['betrag'] ), 16, true );
        $y += 8;

        // Die Angaben als zweispaltige Liste
        $zeilen = [
            [ 'Eingereicht von', $r['name'] ],
            [ 'Abteilung',       $r['abteilung'] !== '' ? $r['abteilung'] : 'keine zugeordnet' ],
            [ 'Rechnungsdatum',  self::datum( $r['rechnungsdatum'] ) ],
            [ 'Empfänger',       $r['empf_name'] ],
            [ 'IBAN',            self::iban_lesbar( $r['empf_iban'] ) ],
            [ 'BIC',             $r['empf_bic'] !== '' ? $r['empf_bic'] : '—' ],
            [ 'Verwendungszweck', self::zweck( $r ) ],
        ];
        foreach ( $zeilen as $i => $paar ) {
            $werte = LSV07A_PDF::umbruch( (string) $paar[1], $rechts - $links - 160, 10 );
            $hoehe = max( 17, count( $werte ) * 13 + 4 );
            if ( $i % 2 === 0 ) {
                $pdf->flaeche( $links, $z( $y + $hoehe - 4 ), $rechts - $links, $hoehe, 0.965 );
            }
            $pdf->text( $links + 6, $z( $y ), $paar[0], 9, false, 0.4 );
            foreach ( $werte as $n => $zeile ) {
                $pdf->text( $links + 150, $z( $y + $n * 13 ), $zeile, 10 );
            }
            $y += $hoehe;
        }

        $y += 16;

        // Der Girocode, wenn es einen geben darf
        $qr_unten = $y;
        /* Der Code kommt NUR aufs Blatt, wenn diese Person die
           Bankverbindung ohnehin vollständig sehen darf. Sonst hebelte das
           Blatt die Verkürzung aus: Oben stünde „DE02 … 2051", und darunter
           ein Code, aus dem jede Banking-App die ganze Nummer liest. Was in
           der Liste verdeckt ist, darf im PDF nicht offenliegen. */
        if ( in_array( $r['status'], [ 'genehmigt', 'bezahlt' ], true )
             && ! empty( $r['empf_iban_voll'] ) ) {
            $giro = LSV07A_Rechnung::giro( (int) $r['id'] );
            if ( empty( $giro['fehler'] ) ) {
                $reihen = LSV07A_QR::reihen( $giro['text'] );
                if ( $reihen ) {
                    self::qr_zeichnen( $pdf, $reihen, $links, $z( $y + self::QR_KANTE ), self::QR_KANTE );
                    $qx = $links + self::QR_KANTE + 18;
                    $pdf->text( $qx, $z( $y + 14 ), 'Überweisung per QR-Code', 11, true );
                    foreach ( LSV07A_PDF::umbruch(
                        'Mit der Banking-App scannen — Empfänger, IBAN, Betrag und '
                      . 'Verwendungszweck stehen dann fertig im Formular.',
                        $rechts - $qx, 9 ) as $n => $zeile ) {
                        $pdf->text( $qx, $z( $y + 30 + $n * 11 ), $zeile, 9, false, 0.35 );
                    }
                    $pdf->text( $qx, $z( $y + 70 ), 'Girocode nach EPC069-12', 8, false, 0.5 );
                    $qr_unten = $y + self::QR_KANTE + 14;
                }
            }
        }
        $y = $qr_unten + 18;

        // Belege
        $pdf->text( $links, $z( $y ), 'Belege', 11, true );
        $y += 16;
        if ( empty( $r['dateien'] ) ) {
            $pdf->text( $links, $z( $y ), 'kein Beleg hinterlegt', 10, false, 0.45 );
            $y += 14;
        } else {
            foreach ( $r['dateien'] as $d ) {
                $pdf->text( $links, $z( $y ), '- ' . $d['name'], 10 );
                $pdf->text_rechts( $rechts, $z( $y ), $d['groesse_text'], 9, false, 0.45 );
                $y += 14;
            }
        }

        // Die Prüfspur ganz unten — sie macht das Blatt erst belastbar.
        $y = LSV07A_PDF::HOEHE - 130;
        $pdf->linie( $links, $z( $y ), $rechts, $z( $y ), 0.6, 0.6 );
        $y += 16;
        $pdf->text( $links, $z( $y ), 'Weg dieser Rechnung', 10, true );
        $y += 15;
        foreach ( [
            [ 'Eingereicht', $r['eingereicht_am'] ],
            [ 'Genehmigt',   $r['genehmigt_am'] ],
            [ 'Bezahlt',     $r['bezahlt_am'] ],
        ] as $paar ) {
            $pdf->text( $links, $z( $y ), $paar[0], 9, false, 0.4 );
            $pdf->text( $links + 150, $z( $y ),
                $paar[1] ? self::zeitpunkt( $paar[1] ) : 'offen', 9 );
            $y += 13;
        }
        if ( trim( (string) $r['rueckgabe_grund'] ) !== '' ) {
            $y += 4;
            $pdf->text( $links, $z( $y ), 'Rückgabe', 9, false, 0.4 );
            foreach ( LSV07A_PDF::umbruch( $r['rueckgabe_grund'], $rechts - $links - 150, 9 ) as $zeile ) {
                $pdf->text( $links + 150, $z( $y ), $zeile, 9 );
                $y += 11;
            }
        }

        return $pdf->fertig();
    }

    /**
     * Den QR-Code als Flächen zeichnen.
     *
     * Ein PDF kennt kein SVG, aber es kennt Rechtecke. Je Modul eines zu
     * setzen wäre eine Anweisung je Punkt — bei 77×77 Modulen also fast
     * 6000. Darum werden waagerechte Läufe zusammengefasst: Das ergibt
     * dieselbe Zeichnung mit einem Bruchteil der Anweisungen.
     *
     * Gezeichnet wird mit einem halben Punkt Überlappung. Ohne sie bleiben
     * bei manchen Anzeigeprogrammen hellste Linien zwischen den Läufen
     * stehen, und daran scheitert ein Scanner.
     */
    private static function qr_zeichnen( LSV07A_PDF $pdf, array $reihen, $x, $y_unten, $kante ) {
        $n = count( $reihen );
        if ( ! $n ) return;
        /* Vier Module Rand sind im Standard vorgeschrieben — ohne ihn findet
           ein Scanner den Code nicht. */
        $rand  = 4;
        $ganz  = $n + 2 * $rand;
        $modul = $kante / $ganz;

        $pdf->flaeche( $x, $y_unten, $kante, $kante, 1.0 );   // weisser Grund
        for ( $r = 0; $r < $n; $r++ ) {
            $c = 0;
            while ( $c < $n ) {
                if ( empty( $reihen[ $r ][ $c ] ) ) { $c++; continue; }
                $start = $c;
                while ( $c < $n && ! empty( $reihen[ $r ][ $c ] ) ) $c++;
                $breite = ( $c - $start ) * $modul;
                $pdf->flaeche(
                    $x + ( $rand + $start ) * $modul,
                    /* Die erste Reihe liegt OBEN; im PDF zählt y von unten. */
                    $y_unten + $kante - ( $rand + $r + 1 ) * $modul,
                    $breite + 0.5, $modul + 0.5, 0.0 );
            }
        }
    }

    // ── Die Jahresübersicht ──────────────────────────────────────────────

    /**
     * Die Übersicht als CSV.
     *
     * Semikolon als Trenner und eine BOM davor: So öffnet Excel die Datei
     * in deutscher Einstellung auf Anhieb richtig — ohne BOM stehen Umlaute
     * als Kauderwelsch darin, und mit Komma landet alles in einer Spalte.
     * Beträge mit Komma als Dezimalzeichen, damit sich damit rechnen lässt.
     */
    public static function uebersicht_csv( $jahr, array $liste ) {
        $spalten = [ 'Nummer', 'Rechnungsdatum', 'Betreff', 'Abteilung', 'Eingereicht von',
                     'Empfänger', 'IBAN', 'BIC', 'Betrag EUR', 'Verwendungszweck',
                     'Eingereicht am', 'Genehmigt am', 'Bezahlt am', 'Belege' ];
        $zeilen = [ $spalten ];
        $summe  = 0.0;
        foreach ( $liste as $r ) {
            $summe += (float) $r['betrag'];
            $zeilen[] = [
                self::nummer( $r ),
                self::datum( $r['rechnungsdatum'] ),
                $r['titel'],
                $r['abteilung'],
                $r['name'],
                $r['empf_name'],
                self::iban_lesbar( $r['empf_iban'] ),
                $r['empf_bic'],
                number_format( (float) $r['betrag'], 2, ',', '' ),
                self::zweck( $r ),
                self::zeitpunkt( $r['eingereicht_am'] ),
                self::zeitpunkt( $r['genehmigt_am'] ),
                self::zeitpunkt( $r['bezahlt_am'] ),
                count( $r['dateien'] ),
            ];
        }
        $zeilen[] = [];
        $zeilen[] = [ 'Summe ' . (int) $jahr, '', '', '', '', '', '', '',
                      number_format( $summe, 2, ',', '' ) ];

        $out = "\xEF\xBB\xBF";
        foreach ( $zeilen as $z ) {
            $felder = [];
            foreach ( $z as $w ) $felder[] = self::csv_feld( $w );
            $out .= implode( ';', $felder ) . "\r\n";
        }
        return $out;
    }

    /**
     * Ein Feld für die CSV.
     *
     * Führt der Wert mit =, +, - oder @, bekommt er ein vorangestelltes
     * Apostroph. Ohne das deutet Excel ihn als Formel — ein Betreff wie
     * „=Hallenmiete" würde dann ausgeführt statt angezeigt („CSV Injection").
     * Der Betreff kommt aus einem Eingabefeld, also wird er behandelt wie
     * alles, was aus einem Eingabefeld kommt.
     */
    private static function csv_feld( $wert ) {
        $s = (string) $wert;
        $s = str_replace( [ "\r\n", "\r", "\n" ], ' ', $s );
        if ( $s !== '' && strpos( "=+-@\t", $s[0] ) !== false ) $s = "'" . $s;
        return '"' . str_replace( '"', '""', $s ) . '"';
    }

    /** Die Übersicht als PDF-Tabelle, über so viele Seiten wie nötig. */
    public static function uebersicht_pdf( $jahr, array $liste ) {
        $pdf    = new LSV07A_PDF();
        $verein = (string) LSV07A_DB::config( 'verein', '' );
        $links  = 42;
        $rechts = LSV07A_PDF::BREITE - 42;
        $z      = fn( $oben ) => LSV07A_PDF::HOEHE - $oben;

        /* Spalten: Nummer, Datum, Betreff, Empfänger, Betrag. */
        $sp = [ $links, $links + 62, $links + 112, $links + 300, $rechts ];

        $summe = 0.0;
        foreach ( $liste as $r ) $summe += (float) $r['betrag'];

        $kopf = function () use ( $pdf, $jahr, $verein, $links, $rechts, $sp, $z, $liste, $summe ) {
            $pdf->text( $links, $z( 56 ), $verein !== '' ? $verein : 'Abrechnung', 9, false, 0.45 );
            $pdf->text( $links, $z( 78 ), 'Rechnungsarchiv ' . (int) $jahr, 16, true );
            $pdf->text_rechts( $rechts, $z( 78 ), self::geld( $summe ), 14, true );
            $pdf->text_rechts( $rechts, $z( 58 ),
                count( $liste ) . ' bezahlte Rechnungen', 9, false, 0.45 );
            $pdf->linie( $links, $z( 94 ), $rechts, $z( 94 ), 0.8, 0.3 );

            $pdf->text( $sp[0], $z( 110 ), 'Nummer', 9, true, 0.3 );
            $pdf->text( $sp[1], $z( 110 ), 'Datum', 9, true, 0.3 );
            $pdf->text( $sp[2], $z( 110 ), 'Betreff', 9, true, 0.3 );
            $pdf->text( $sp[3], $z( 110 ), 'Empfänger', 9, true, 0.3 );
            $pdf->text_rechts( $sp[4], $z( 110 ), 'Betrag', 9, true, 0.3 );
            $pdf->linie( $links, $z( 116 ), $rechts, $z( 116 ), 0.5, 0.65 );
        };

        $kopf();
        $y = 132;
        $i = 0;
        foreach ( $liste as $r ) {
            if ( $y > LSV07A_PDF::HOEHE - 70 ) {
                $pdf->seite_neu();
                $kopf();
                $y = 132;
            }
            if ( $i % 2 === 1 ) $pdf->flaeche( $links, $z( $y + 11 ), $rechts - $links, 15, 0.965 );
            $pdf->text( $sp[0], $z( $y ), self::nummer( $r ), 9 );
            $pdf->text( $sp[1], $z( $y ), self::datum( $r['rechnungsdatum'] ), 9 );
            $pdf->text( $sp[2], $z( $y ), self::kurz( $r['titel'], $sp[3] - $sp[2] - 8, 9 ), 9 );
            $pdf->text( $sp[3], $z( $y ), self::kurz( $r['empf_name'], $sp[4] - $sp[3] - 60, 9 ), 9 );
            $pdf->text_rechts( $sp[4], $z( $y ), self::geld( $r['betrag'] ), 9 );
            $y += 15;
            $i++;
        }

        $pdf->linie( $links, $z( $y + 2 ), $rechts, $z( $y + 2 ), 0.8, 0.3 );
        $y += 18;
        $pdf->text( $sp[0], $z( $y ), 'Summe', 10, true );
        $pdf->text_rechts( $sp[4], $z( $y ), self::geld( $summe ), 11, true );

        return $pdf->fertig();
    }

    // ── Das Jahrespaket ──────────────────────────────────────────────────

    /**
     * Ein ganzes Jahr als ZIP.
     *
     * Aufbau — so, dass man sich darin ohne Erklärung zurechtfindet:
     *
     *   Rechnungen-2026/Uebersicht.csv
     *   Rechnungen-2026/Uebersicht.pdf
     *   Rechnungen-2026/RG-0042_Startbloecke/Rechnungsblatt.pdf
     *   Rechnungen-2026/RG-0042_Startbloecke/Beleg-1_rechnung.pdf
     *
     * Der Beleg behält seinen hochgeladenen Namen, bekommt aber eine Nummer
     * davor: Zwei Fotos heissen schon mal beide „IMG_0001.jpg", und im
     * selben Ordner ginge dann eines verloren.
     */
    public static function zip( $jahr ) {
        $jahr  = (int) $jahr;
        $liste = LSV07A_Rechnung::archiv( $jahr );
        if ( ! $liste ) return null;

        /* Erst messen, dann bauen. */
        $groesse = self::jahr_groesse( $jahr, count( $liste ) );
        $grenze  = self::zip_grenze();
        if ( $groesse > $grenze ) {
            return [ 'fehler' =>
                'Das Paket für ' . $jahr . ' wäre etwa ' . self::mb( $groesse ) . ' groß und passt '
                . 'nicht in den Arbeitsspeicher dieses Servers (' . self::mb( $grenze ) . ' nutzbar). '
                . 'Laden Sie die Übersicht und die einzelnen Rechnungsblätter herunter, oder lassen '
                . 'Sie das Speicherlimit von PHP anheben.' ];
        }

        $zip   = new LSV07A_Zip();
        $wurzel = 'Rechnungen-' . $jahr;
        /* time() und nicht current_time('timestamp'): Im ZIP steht die Zeit
           als UTC-Stempel, und ein um die Zeitzone verschobener Wert macht
           aus einer Datei von heute eine von morgen. */
        $zeit  = time();

        $zip->datei( $wurzel . '/Uebersicht.csv', self::uebersicht_csv( $jahr, $liste ), $zeit );
        $zip->datei( $wurzel . '/Uebersicht.pdf', self::uebersicht_pdf( $jahr, $liste ), $zeit );

        foreach ( $liste as $r ) {
            $ordner = $wurzel . '/' . self::ordnername( $r );
            $zip->datei( $ordner . '/Rechnungsblatt.pdf', self::blatt( $r ),
                         self::stempel( $r['bezahlt_am'], $zeit ) );

            $n = 0;
            foreach ( $r['dateien'] as $d ) {
                $n++;
                $voll = LSV07A_Rechnung::datei_holen( (int) $d['id'] );
                if ( ! $voll || ! is_file( $voll['pfad'] ) ) continue;
                $inhalt = @file_get_contents( $voll['pfad'] );
                if ( $inhalt === false ) continue;
                $zip->datei( $ordner . '/Beleg-' . $n . '_' . self::sauber( $d['name'] ),
                             $inhalt, self::stempel( $d['am'], $zeit ) );
            }
        }

        LSV07A_Log::schreibe( 'archiv.jahr', [
            'ziel_typ' => 'archiv', 'ziel_id' => $jahr,
            'details'  => count( $liste ) . ' Rechnungen, ' . $zip->anzahl() . ' Dateien' ] );

        return [
            'name'   => 'Rechnungen-' . $jahr . '.zip',
            'inhalt' => $zip->fertig(),
            'anzahl' => count( $liste ),
        ];
    }

    /**
     * Wie groß das Paket eines Jahres würde — aus den vermerkten
     * Dateigrößen, ohne eine einzige Datei zu lesen.
     */
    public static function jahr_groesse( $jahr, $anzahl = null ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $bytes = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(d.groesse),0)
               FROM {$p}lsv07a_rechnung_datei d
               JOIN {$p}lsv07a_rechnung r ON r.id = d.rechnung_id
              WHERE r.status = 'bezahlt' AND r.jahr = %d", (int) $jahr ) );
        if ( $anzahl === null ) {
            $anzahl = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}lsv07a_rechnung
                  WHERE status = 'bezahlt' AND jahr = %d", (int) $jahr ) );
        }
        return $bytes + (int) $anzahl * self::BLATT_BYTES;
    }

    /**
     * Wie viel von diesem Server für ein Paket zu haben ist.
     *
     * Ein Drittel des Speicherlimits, höchstens ZIP_MAX_BYTES: In der
     * Spitze liegen Puffer, Verzeichnis und das zusammengefügte Ergebnis
     * gleichzeitig im Speicher.
     */
    public static function zip_grenze() {
        $limit = function_exists( 'wp_convert_hr_to_bytes' )
            ? (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) )
            : 0;
        /* -1 heisst „kein Limit"; dann gilt unsere eigene Obergrenze. */
        if ( $limit <= 0 ) return self::ZIP_MAX_BYTES;
        return (int) min( self::ZIP_MAX_BYTES, intdiv( $limit, 3 ) );
    }

    private static function mb( $bytes ) {
        return $bytes >= 1048576
            ? round( $bytes / 1048576 ) . ' MB'
            : max( 1, (int) round( $bytes / 1024 ) ) . ' kB';
    }

    // ── Kleinkram ────────────────────────────────────────────────────────

    public static function nummer( $r ) {
        return 'RG-' . str_pad( (string) (int) ( $r['id'] ?? 0 ), 4, '0', STR_PAD_LEFT );
    }

    /** Der Verwendungszweck, wie er auf dem Kontoauszug erscheint. */
    private static function zweck( $r ) {
        $zweck = trim( (string) ( $r['zweck'] ?? '' ) );
        $nr    = self::nummer( $r );
        return $zweck === '' ? $nr : ( $nr . ' ' . $zweck );
    }

    private static function geld( $betrag ) {
        return number_format( (float) $betrag, 2, ',', '.' ) . ' EUR';
    }

    private static function datum( $iso ) {
        $t = strtotime( (string) $iso );
        return $t ? date_i18n( 'd.m.Y', $t ) : '—';
    }

    private static function zeitpunkt( $mysql ) {
        $t = strtotime( (string) $mysql );
        return $t ? date_i18n( 'd.m.Y H:i', $t ) : '';
    }

    private static function stempel( $mysql, $ersatz ) {
        $t = strtotime( (string) $mysql );
        return $t ?: $ersatz;
    }

    /** IBAN in Vierergruppen — so steht sie auf jedem Formular. */
    public static function iban_lesbar( $iban ) {
        $roh = (string) $iban;
        if ( trim( $roh ) === '' ) return '—';
        /* Eine gekürzte IBAN („DE02 … 2051") bleibt, wie sie ist. Durch die
           Vierergruppierung geschickt, würde daraus „DE02 …20 51" — die
           Auslassung rutschte in eine Gruppe und das Gekürzte sähe aus wie
           ein Tippfehler. */
        if ( mb_strpos( $roh, '…' ) !== false ) return $roh;
        $i = strtoupper( preg_replace( '/\s+/', '', $roh ) );
        return $i === '' ? '—' : trim( chunk_split( $i, 4, ' ' ) );
    }

    /** Auf eine Breite kürzen, mit Auslassungszeichen. */
    private static function kurz( $s, $maxbreite, $groesse ) {
        $s = (string) $s;
        if ( LSV07A_PDF::breite( $s, $groesse ) <= $maxbreite ) return $s;
        while ( $s !== '' && LSV07A_PDF::breite( $s . '...', $groesse ) > $maxbreite ) {
            $s = mb_substr( $s, 0, mb_strlen( $s ) - 1 );
        }
        return $s . '...';
    }

    /** Ein Name, der auf jedem Dateisystem und in jedem Entpacker lebt. */
    private static function sauber( $name ) {
        $name = strtr( (string) $name, [
            'ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ß'=>'ss' ] );
        $name = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
        $name = trim( preg_replace( '/-+/', '-', $name ), '-._' );
        return $name === '' ? 'Datei' : mb_substr( $name, 0, 80 );
    }

    private static function ordnername( $r ) {
        $titel = self::sauber( (string) $r['titel'] );
        return self::nummer( $r ) . ( $titel === 'Datei' ? '' : '_' . mb_substr( $titel, 0, 50 ) );
    }
}
