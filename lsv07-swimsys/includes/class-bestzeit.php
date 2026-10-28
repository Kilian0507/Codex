<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bestzeiten: je Schwimmer und Strecke eine Zeit.
 *
 * Erfasst wird über einen eingefügten Textblock aus einer Tabelle —
 * erst eine Vorschau mit Zuordnung, dann das Übernehmen. Das ist der
 * Weg, auf dem Bestzeiten im Verein tatsächlich ankommen: als
 * kopierter Block aus der Auswertung eines Wettkampfs.
 *
 * Was hier anders ist als im internen Bereich:
 *
 * 1. **Die Vorschau sagt, was sie ersetzt.** Dort steht nach dem
 *    Übernehmen, wie viele Zeiten ersetzt wurden; davor sieht man es
 *    nicht. Hier steht neben jeder Zeit die bisherige, und ob die neue
 *    schneller oder langsamer ist. Eine langsamere Zeit ist meistens
 *    ein Vertipper oder eine Zeit aus einem anderen Jahr.
 * 2. **Eine Zuordnung, die nicht sicher ist, bleibt leer** und muss von
 *    Hand gewählt werden. Dort gibt es Stufen bis „Tippfehler", die
 *    automatisch zuschlagen.
 * 3. **Das Übernehmen löscht nicht mehr, als es ersetzt.** Dort werden
 *    beim Import ALLE Zeiten eines Schwimmers gelöscht, auch Strecken,
 *    die im Block gar nicht vorkommen. Wer einen Block mit einer
 *    einzigen Strecke einfügt, verliert die anderen siebzehn.
 * 4. **Jede Änderung steht im Protokoll** — dort gar nicht.
 *
 * Was hier NICHT geht: eine Verlaufsliste je Strecke (Datum, Wettkampf,
 * Bahnlänge). `lsv07i_bestzeiten` hat einen eindeutigen Schlüssel über
 * Schwimmer und Strecke und keine Spalte für das Datum; ein Verlauf
 * bräuchte eine Änderung am Schema des internen Bereichs, und die nimmt
 * SwimSys sich nicht, solange der läuft.
 */
class LSV07S_Bestzeit {

    /** Die Strecken, in derselben Schreibweise wie im internen Bereich. */
    const STRECKEN = [
        '50F'   => '50m Freistil',
        '100F'  => '100m Freistil',
        '200F'  => '200m Freistil',
        '400F'  => '400m Freistil',
        '800F'  => '800m Freistil',
        '1500F' => '1500m Freistil',
        '50B'   => '50m Brust',
        '100B'  => '100m Brust',
        '200B'  => '200m Brust',
        '50R'   => '50m Rücken',
        '100R'  => '100m Rücken',
        '200R'  => '200m Rücken',
        '50S'   => '50m Schmetterling',
        '100S'  => '100m Schmetterling',
        '200S'  => '200m Schmetterling',
        '100L'  => '100m Lagen',
        '200L'  => '200m Lagen',
        '400L'  => '400m Lagen',
    ];

    // ── Rechte ───────────────────────────────────────────────────────────

    private static function darf_lesen() {
        return LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_BESTZEIT_READ );
    }
    private static function darf_importieren() {
        return LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_BESTZEIT_IMPORT );
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** Die Bestzeiten einer Person, nach Strecke. */
    public static function zu_person( $schwimmer_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'bestzeiten' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT strecke, zeit_raw, zeit_sek FROM ' . LSV07S_Tabellen::t( 'bestzeiten' )
            . ' WHERE swimmer_id = %d', (int) $schwimmer_id ), ARRAY_A );
        $raus = [];
        foreach ( $zeilen as $z ) $raus[ (string) $z['strecke'] ] = (string) $z['zeit_raw'];
        return $raus;
    }

    /**
     * Die Bestzeiten einer Mannschaft als Tafel: je Person eine Zeile,
     * je Strecke eine Spalte.
     */
    public static function tafel( $mannschaft_id ) {
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $zeilen = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ) as $s ) {
            $zeilen[] = [
                'id' => $s['id'], 'name' => $s['name'], 'jahrgang' => $s['jahrgang'],
                'zeiten' => self::zu_person( (int) $s['id'] ),
            ];
        }
        return [
            'mannschaft' => LSV07S_Mannschaft::holen( $sparte, $mannschaft_id ),
            'strecken' => self::STRECKEN,
            'zeilen' => $zeilen,
            'darf_importieren' => self::darf_importieren(),
            'darf_loeschen' => LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_BESTZEIT_DELETE ),
        ];
    }

    // ── Zeiten rechnen ───────────────────────────────────────────────────

    /**
     * Eine Zeitangabe in Sekunden. 0 heisst: nicht lesbar.
     *
     * Erlaubt sind 1:02,45 · 1:02.45 · 62,45 · 62.45 · 1:02 — alles, was
     * aus einer Auswertung kommt.
     */
    public static function sekunden( $roh ) {
        $s = str_replace( ',', '.', trim( (string) $roh ) );
        if ( $s === '' ) return 0.0;
        if ( preg_match( '/^(\d+):(\d{1,2})\.(\d{1,3})$/', $s, $m ) ) {
            return (float) $m[1] * 60 + (float) $m[2] + (float) ( '0.' . $m[3] );
        }
        if ( preg_match( '/^(\d+):(\d{1,2})$/', $s, $m ) ) {
            return (float) $m[1] * 60 + (float) $m[2];
        }
        if ( preg_match( '/^(\d+)\.(\d{1,3})$/', $s, $m ) ) {
            return (float) $m[1] + (float) ( '0.' . $m[2] );
        }
        if ( preg_match( '/^\d{1,4}$/', $s ) ) return (float) $s;
        return 0.0;
    }

    /** Sekunden als Zeit, wie man sie schreibt. */
    public static function als_zeit( $sek ) {
        $sek = (float) $sek;
        if ( $sek <= 0 ) return '';
        $min = (int) floor( $sek / 60 );
        $rest = $sek - $min * 60;
        $zahl = number_format( $rest, 2, ',', '' );
        if ( $min > 0 && $rest < 10 ) $zahl = '0' . $zahl;
        return $min > 0 ? $min . ':' . $zahl : $zahl;
    }

    // ── Einfügen: Vorschau ───────────────────────────────────────────────

    /**
     * Einen eingefügten Textblock lesen und zeigen, was er bedeuten
     * würde — ohne etwas zu schreiben.
     *
     * Erwartet wird eine Kopfzeile, die mit „Name" beginnt, und danach
     * je Person eine Zeile. Die Spalten sind Tabulatoren oder
     * Semikolons; die Streckennamen in der Kopfzeile werden auf die
     * bekannten Strecken abgebildet.
     */
    public static function vorschau( $text, $mannschaft_id ) {
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! self::darf_importieren() ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $gelesen = self::lesen( (string) $text );
        if ( is_string( $gelesen ) ) return $gelesen;
        [ $strecken, $zeilen ] = $gelesen;

        $kader = LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id );

        $raus = [];
        foreach ( $zeilen as $z ) {
            $treffer = self::zuordnen( $z['name'], $z['jahrgang'], $kader );
            $zeiten = [];
            foreach ( $z['zeiten'] as $strecke => $roh ) {
                $sek = self::sekunden( $roh );
                if ( $sek <= 0 ) continue;
                $alt = $treffer ? ( self::zu_person( (int) $treffer['id'] )[ $strecke ] ?? '' ) : '';
                $alt_sek = $alt !== '' ? self::sekunden( $alt ) : 0.0;
                $zeiten[] = [
                    'strecke' => $strecke,
                    'strecke_name' => self::STRECKEN[ $strecke ],
                    'zeit' => self::als_zeit( $sek ),
                    'sekunden' => round( $sek, 2 ),
                    'bisher' => $alt,
                    /* Eine langsamere Zeit ist meistens ein Vertipper
                       oder aus einem anderen Jahr — sie wird gezeigt,
                       aber nicht verschwiegen. */
                    'langsamer' => $alt_sek > 0 && $sek > $alt_sek,
                    'schneller' => $alt_sek > 0 && $sek < $alt_sek,
                ];
            }
            $raus[] = [
                'name' => $z['name'],
                'jahrgang' => $z['jahrgang'],
                'schwimmer_id' => $treffer ? (int) $treffer['id'] : 0,
                'zugeordnet' => $treffer ? (string) $treffer['name'] : '',
                'sicher' => $treffer ? (bool) $treffer['sicher'] : false,
                'zeiten' => $zeiten,
            ];
        }

        $offen = 0;
        foreach ( $raus as $r ) if ( ! $r['schwimmer_id'] ) $offen++;

        return [
            'strecken' => array_values( array_unique( $strecken ) ),
            'zeilen' => $raus,
            'kader' => array_map( fn( $s ) => [
                'id' => $s['id'], 'name' => $s['name'], 'jahrgang' => $s['jahrgang'] ], $kader ),
            'hinweis' => $offen > 0
                ? $offen . ' Zeile(n) sind keiner Person sicher zuzuordnen. Bitte dort '
                  . 'die Person wählen — eine Vermutung wäre schlimmer als eine Lücke.'
                : '',
        ];
    }

    /**
     * Den Block in Kopfzeile und Zeilen zerlegen.
     *
     * Zurück kommt [ Strecken je Spalte, Zeilen ] oder ein Satz, was
     * fehlt.
     */
    private static function lesen( $text ) {
        $text = str_replace( [ "\r\n", "\r" ], "\n", (string) $text );
        if ( trim( $text ) === '' ) return 'Bitte den kopierten Block einfügen.';
        if ( strlen( $text ) > 200000 ) return 'Der Block ist zu groß.';

        $zeilen = array_values( array_filter( explode( "\n", $text ),
            fn( $z ) => trim( $z ) !== '' ) );

        $kopf = null;
        $start = 0;
        foreach ( $zeilen as $i => $z ) {
            $teile = self::spalten( $z );
            if ( mb_strtolower( trim( $teile[0] ?? '' ) ) === 'name' ) {
                $kopf = $teile;
                $start = $i + 1;
                break;
            }
        }
        if ( $kopf === null ) {
            return 'In dem Block fehlt die Kopfzeile. Die erste Spalte der Kopfzeile '
                 . 'muss „Name" heißen, danach je Spalte eine Strecke.';
        }

        $strecken = [];
        foreach ( $kopf as $i => $titel ) {
            if ( $i === 0 ) continue;
            $s = self::strecke( $titel );
            if ( $s !== '' ) $strecken[ $i ] = $s;
        }
        if ( $strecken === [] ) {
            return 'In der Kopfzeile ist keine bekannte Strecke zu finden. Erlaubt sind '
                 . 'Angaben wie „100 Freistil", „100F" oder „100m Brust".';
        }

        $raus = [];
        for ( $i = $start; $i < count( $zeilen ); $i++ ) {
            $teile = self::spalten( $zeilen[ $i ] );
            $name = trim( $teile[0] ?? '' );
            if ( $name === '' ) continue;
            $jahrgang = 0;
            if ( preg_match( '/^(.+?)\s*\((\d{4})\)\s*$/', $name, $m ) ) {
                $name = trim( $m[1] );
                $jahrgang = (int) $m[2];
            }
            $zeiten = [];
            foreach ( $strecken as $spalte => $strecke ) {
                $wert = trim( $teile[ $spalte ] ?? '' );
                if ( $wert === '' || $wert === '-' || $wert === '0' ) continue;
                $zeiten[ $strecke ] = $wert;
            }
            if ( $zeiten === [] ) continue;
            $raus[] = [ 'name' => $name, 'jahrgang' => $jahrgang, 'zeiten' => $zeiten ];
        }
        if ( $raus === [] ) return 'In dem Block steht keine lesbare Zeit.';
        if ( count( $raus ) > 300 ) return 'Mehr als 300 Zeilen auf einmal sind zu viel.';
        return [ $strecken, $raus ];
    }

    /** Eine Zeile in Spalten zerlegen: Tabulator, sonst Semikolon. */
    private static function spalten( $zeile ) {
        $z = rtrim( (string) $zeile, "\n" );
        $teile = strpos( $z, "\t" ) !== false ? explode( "\t", $z ) : explode( ';', $z );
        return array_map( fn( $t ) => trim( $t ), $teile );
    }

    /**
     * Einen Streckennamen auf einen Schlüssel abbilden.
     *
     * „100 Freistil", „100m Freistil", „100F", „100 F" — alles dasselbe.
     */
    public static function strecke( $titel ) {
        $t = mb_strtolower( trim( (string) $titel ) );
        if ( $t === '' ) return '';
        foreach ( self::STRECKEN as $k => $name ) {
            if ( $t === mb_strtolower( $k ) ) return $k;
            if ( $t === mb_strtolower( $name ) ) return $k;
        }
        if ( ! preg_match( '/(\d{2,4})/', $t, $m ) ) return '';
        $meter = (int) $m[1];
        $art = '';
        if ( preg_match( '/frei|kraul|\bf\b|^\d+\s*f$/u', $t ) )           $art = 'F';
        elseif ( preg_match( '/brust|\bb\b|^\d+\s*b$/u', $t ) )            $art = 'B';
        elseif ( preg_match( '/rück|ruck|ruecken|\br\b|^\d+\s*r$/u', $t ) ) $art = 'R';
        elseif ( preg_match( '/schmett|delphin|delfin|butterfly|\bs\b|^\d+\s*s$/u', $t ) ) $art = 'S';
        elseif ( preg_match( '/lagen|\bl\b|^\d+\s*l$/u', $t ) )            $art = 'L';
        if ( $art === '' ) return '';
        $schluessel = $meter . $art;
        return isset( self::STRECKEN[ $schluessel ] ) ? $schluessel : '';
    }

    /**
     * Einen Namen einer Person im Kader zuordnen.
     *
     * Zurück kommt der Treffer mit `sicher` — sicher heisst: Vorname und
     * Nachname stimmen (nach Groß-/Kleinschreibung und Umlauten), und es
     * gibt genau einen solchen. Alles andere ist ein Vorschlag, der von
     * Hand bestätigt werden muss. Der interne Bereich ordnet bis zur
     * Stufe „Tippfehler" automatisch zu — und schreibt im Zweifel die
     * Zeit der falschen Person.
     */
    public static function zuordnen( $name_roh, $jahrgang, array $kader ) {
        $name = preg_replace( '/\s+/', ' ', trim( (string) $name_roh ) );
        if ( $name === '' ) return null;
        if ( preg_match( '/^(.+?)\s*\((\d{4})\)\s*$/', $name, $m ) ) {
            $name = trim( $m[1] );
            if ( ! $jahrgang ) $jahrgang = (int) $m[2];
        }

        if ( strpos( $name, ',' ) !== false ) {
            [ $nach, $vor ] = array_map( 'trim', explode( ',', $name, 2 ) );
        } else {
            $teile = preg_split( '/\s+/', $name );
            if ( count( $teile ) >= 2 ) {
                $nach = array_pop( $teile );
                $vor = implode( ' ', $teile );
            } else {
                $nach = $name;
                $vor = '';
            }
        }
        $ziel = self::norm( $vor . ' ' . $nach );
        $ziel_nur_nach = self::norm( $nach );

        $voll = [];
        $nur_nach = [];
        foreach ( $kader as $s ) {
            /* Der Listenname ist „Nachname, Vorname" — für den Vergleich
               wird beides in dieselbe Form gebracht. */
            $teile = array_map( 'trim', explode( ',', (string) $s['name'], 2 ) );
            $s_nach = $teile[0] ?? '';
            $s_vor  = $teile[1] ?? '';
            $jahr   = (int) ( $s['jahrgang'] ?? 0 );
            if ( $jahrgang && $jahr && $jahrgang !== $jahr ) continue;

            if ( self::norm( $s_vor . ' ' . $s_nach ) === $ziel ) {
                $voll[] = [ 'id' => $s['id'], 'name' => $s['name'] ];
            } elseif ( self::norm( $s_nach ) === $ziel_nur_nach ) {
                $nur_nach[] = [ 'id' => $s['id'], 'name' => $s['name'] ];
            }
        }

        if ( count( $voll ) === 1 ) return $voll[0] + [ 'sicher' => true ];
        if ( count( $voll ) > 1 )   return null;   // zwei gleiche Namen: niemand entscheidet das
        if ( count( $nur_nach ) === 1 && $vor === '' ) {
            return $nur_nach[0] + [ 'sicher' => false ];
        }
        return null;
    }

    /** Umlaute, Groß-/Kleinschreibung und Zeichen weg. */
    public static function norm( $s ) {
        $t = mb_strtolower( trim( (string) $s ) );
        $t = str_replace( [ 'ä', 'ö', 'ü', 'ß', 'é', 'è', 'á', 'à', 'í', 'ó', 'ú' ],
                          [ 'ae', 'oe', 'ue', 'ss', 'e', 'e', 'a', 'a', 'i', 'o', 'u' ], $t );
        $t = preg_replace( '/[^a-z0-9 ]/', '', $t );
        return trim( preg_replace( '/\s+/', ' ', $t ) );
    }

    // ── Einfügen: übernehmen ─────────────────────────────────────────────

    /**
     * Die bestätigten Zeilen übernehmen.
     *
     * `$zeilen`: Feld aus [ schwimmer_id, zeiten => [ strecke => zeit ] ].
     * Ersetzt werden nur die Strecken, die wirklich kommen — der interne
     * Bereich löscht alle Zeiten der Person, auch die Strecken, die im
     * Block nicht vorkamen.
     */
    public static function uebernehmen( $mannschaft_id, array $zeilen ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! self::darf_importieren() ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( ! LSV07S_Tabellen::da( 'bestzeiten' ) ) return 'Die Tabelle fehlt.';

        $kader = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ) as $s ) {
            $kader[ (int) $s['id'] ] = $s;
        }

        $t = LSV07S_Tabellen::t( 'bestzeiten' );
        $neu = 0; $ersetzt = 0; $fremd = 0; $unlesbar = 0; $personen = 0;

        foreach ( $zeilen as $z ) {
            $sid = absint( $z['schwimmer_id'] ?? 0 );
            if ( ! isset( $kader[ $sid ] ) ) { $fremd++; continue; }
            $hat = false;

            foreach ( (array) ( $z['zeiten'] ?? [] ) as $strecke => $roh ) {
                $strecke = (string) $strecke;
                if ( ! isset( self::STRECKEN[ $strecke ] ) ) { $unlesbar++; continue; }
                $sek = self::sekunden( $roh );
                if ( $sek <= 0 ) { $unlesbar++; continue; }

                $da = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM $t WHERE swimmer_id = %d AND strecke = %s LIMIT 1",
                    $sid, $strecke ), ARRAY_A );
                $zeile = [
                    'swimmer_id' => $sid,
                    'mannschaft_id' => $mannschaft_id,
                    'swimmer_name' => (string) $kader[ $sid ]['name'],
                    'strecke' => $strecke,
                    'zeit_raw' => self::als_zeit( $sek ),
                    'zeit_sek' => round( $sek, 3 ),
                    'importiert_von' => get_current_user_id(),
                    'importiert_am' => LSV07S_Datum::jetzt(),
                ];
                if ( $da ) {
                    $wpdb->update( $t, $zeile, [ 'id' => (int) $da['id'] ],
                        [ '%d', '%d', '%s', '%s', '%s', '%f', '%d', '%s' ], [ '%d' ] );
                    $ersetzt++;
                } else {
                    $wpdb->insert( $t, $zeile,
                        [ '%d', '%d', '%s', '%s', '%s', '%f', '%d', '%s' ] );
                    $neu++;
                }
                $hat = true;
            }
            if ( $hat ) $personen++;
        }

        LSV07S_Log::schreibe( 'bestzeiten.import', [
            'bereich' => $sparte, 'ziel_typ' => 'mannschaft', 'ziel_id' => $mannschaft_id,
            'details' => $neu . ' neu, ' . $ersetzt . ' ersetzt, ' . $personen . ' Person(en)',
        ] );

        $satz = $neu . ' neue Zeit(en), ' . $ersetzt . ' ersetzt, für '
              . $personen . ' Person(en).';
        if ( $fremd )    $satz .= ' ' . $fremd . ' Zeile(n) betraf(en) fremde Personen.';
        if ( $unlesbar ) $satz .= ' ' . $unlesbar . ' Zeitangabe(n) waren leer oder unlesbar.';
        return [ 'message' => $satz, 'neu' => $neu, 'ersetzt' => $ersetzt ];
    }

    // ── Löschen ──────────────────────────────────────────────────────────

    /** Eine einzelne Zeit löschen. */
    public static function loeschen( $schwimmer_id, $strecke ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $schwimmer_id = (int) $schwimmer_id;
        $strecke = (string) $strecke;
        if ( ! LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_BESTZEIT_DELETE ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, $schwimmer_id ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }
        if ( ! isset( self::STRECKEN[ $strecke ] ) ) return 'Diese Strecke gibt es nicht.';

        $t = LSV07S_Tabellen::t( 'bestzeiten' );
        $da = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE swimmer_id = %d AND strecke = %s LIMIT 1",
            $schwimmer_id, $strecke ), ARRAY_A );
        if ( ! $da ) return 'Für diese Strecke ist keine Zeit erfasst.';

        $wpdb->delete( $t, [ 'id' => (int) $da['id'] ], [ '%d' ] );
        LSV07S_Log::schreibe( 'bestzeiten.geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $schwimmer_id,
            'details' => self::STRECKEN[ $strecke ] . ': ' . (string) $da['zeit_raw'],
        ] );
        return [ 'message' => 'Gelöscht.' ];
    }

    /**
     * Alle Zeiten einer Mannschaft löschen.
     *
     * Ein eigenes Recht, und die Antwort sagt, wie viele es waren —
     * „Gelöscht." allein wäre bei 300 Zeiten zu wenig.
     */
    public static function alle_loeschen( $mannschaft_id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_BESTZEIT_DELETE_ALL ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        $ids = array_map( 'intval',
            array_column( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id, false ), 'id' ) );
        if ( $ids === [] ) return [ 'message' => 'In dieser Mannschaft ist niemand.' ];

        $t = LSV07S_Tabellen::t( 'bestzeiten' );
        $in = implode( ',', $ids );
        $zahl = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE swimmer_id IN ($in)" );
        $wpdb->query( "DELETE FROM $t WHERE swimmer_id IN ($in)" );

        LSV07S_Log::schreibe( 'bestzeiten.alle_geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'mannschaft', 'ziel_id' => $mannschaft_id,
            'details' => $zahl . ' Zeit(en)',
        ] );
        return [ 'message' => $zahl . ' Zeit(en) gelöscht.' ];
    }

    /** Die Tafel als Textblock — dieselbe Form, die die Vorschau liest. */
    public static function export( $mannschaft_id ) {
        $tafel = self::tafel( $mannschaft_id );
        if ( is_string( $tafel ) ) return $tafel;

        $strecken = array_keys( self::STRECKEN );
        $kopf = array_merge( [ 'Name' ],
            array_map( fn( $k ) => self::STRECKEN[ $k ], $strecken ) );
        $zeilen = [ implode( "\t", $kopf ) ];
        foreach ( $tafel['zeilen'] as $z ) {
            $spalten = [ $z['name'] . ( $z['jahrgang'] ? ' (' . $z['jahrgang'] . ')' : '' ) ];
            foreach ( $strecken as $k ) $spalten[] = $z['zeiten'][ $k ] ?? '';
            $zeilen[] = implode( "\t", $spalten );
        }
        return [ 'text' => implode( "\n", $zeilen ),
                 'name' => 'Bestzeiten ' . ( $tafel['mannschaft']['name'] ?? '' ) ];
    }
}
