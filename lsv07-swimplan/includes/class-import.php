<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Der CSV-Import — je Kategorie einer.
 *
 * Fünf Kategorien, und jede dort, wo sie hingehört: Schwimmer unter
 * Schwimmer, Mitglieder in der Mannschaft, Zeiten bei den Leistungen, Sets im
 * Plan oder in der Vorlage. Es gibt bewusst keinen einen grossen Import für
 * alles: Eine Datei, in der Schwimmer, Mannschaften und Zeiten durcheinander
 * stehen, lässt sich weder sinnvoll prüfen noch sinnvoll melden.
 *
 * Zwei Festlegungen ziehen sich durch:
 *
 *  1. **Erst prüfen, dann übernehmen.** Jeder Import läuft zweimal durch
 *     dieselbe Schleife — einmal trocken (`$trocken = true`), und was dabei
 *     herauskommt, ist die Vorschau. Zwei getrennte Wege würden früher oder
 *     später auseinanderlaufen, und dann zeigte die Vorschau etwas anderes,
 *     als danach passiert.
 *  2. **Eine fehlerhafte Zeile hält den Rest nicht auf.** Sie wird gemeldet
 *     und übersprungen. Alles-oder-nichts hiesse bei einer Datei mit 200
 *     Zeilen und einem Tippfehler: nichts.
 *
 * Die Rechte sind dieselben wie überall sonst — der Import ist kein zweiter,
 * schwächerer Weg an die Daten, sondern dieselben Funktionen in einer
 * Schleife.
 */
class LSV07P_Import {

    /**
     * Die Kategorien. `ziel` sagt, was mitgeschickt werden muss:
     * '' (nichts), 'team', 'plan' oder 'vorlage'.
     */
    const KATEGORIEN = [
        'schwimmer'   => [ 'name' => 'Schwimmer',              'ziel' => '' ],
        'mannschaften' => [ 'name' => 'Mannschaften',          'ziel' => '' ],
        'mitglieder'  => [ 'name' => 'Mitglieder einer Mannschaft', 'ziel' => 'team' ],
        'zeiten'      => [ 'name' => 'Zeiten',                 'ziel' => '' ],
        'sets'        => [ 'name' => 'Sets eines Plans',       'ziel' => 'plan' ],
        'vorlage_sets' => [ 'name' => 'Sets einer Vorlage',    'ziel' => 'vorlage' ],
    ];

    /** Die Spalten je Kategorie: feldname => [ Schreibweisen, die gelten ]. */
    private static function felder( $kategorie ) {
        switch ( $kategorie ) {
            case 'schwimmer':
                return [
                    'vorname'  => [ 'Vorname', 'Vorname(n)', 'Firstname', 'First Name' ],
                    'nachname' => [ 'Nachname', 'Name', 'Familienname', 'Lastname', 'Surname' ],
                    'jahrgang' => [ 'Jahrgang', 'Geburtsjahr', 'JG', 'Jahr' ],
                    'dsv_id'   => [ 'DSV-ID', 'DSV', 'DSV-Nummer', 'Startpassnummer', 'ID' ],
                    'notiz'    => [ 'Notiz', 'Bemerkung', 'Hinweis', 'Kommentar' ],
                ];
            case 'mannschaften':
                return [
                    'name'        => [ 'Name', 'Mannschaft', 'Gruppe', 'Trainingsgruppe' ],
                    'beschreibung' => [ 'Beschreibung', 'Bemerkung', 'Notiz' ],
                    'sortierung'  => [ 'Sortierung', 'Reihenfolge', 'Position', 'Nr' ],
                ];
            case 'mitglieder':
                return [
                    'vorname'  => [ 'Vorname', 'Firstname' ],
                    'nachname' => [ 'Nachname', 'Name', 'Familienname', 'Lastname' ],
                    'dsv_id'   => [ 'DSV-ID', 'DSV', 'DSV-Nummer', 'Startpassnummer' ],
                    'jahrgang' => [ 'Jahrgang', 'Geburtsjahr', 'JG' ],
                    'von'      => [ 'Von', 'Seit', 'Eintritt', 'Startdatum', 'Dabei seit' ],
                    'bis'      => [ 'Bis', 'Austritt', 'Enddatum' ],
                ];
            case 'zeiten':
                return [
                    'vorname'   => [ 'Vorname', 'Firstname' ],
                    'nachname'  => [ 'Nachname', 'Name', 'Familienname', 'Lastname' ],
                    'dsv_id'    => [ 'DSV-ID', 'DSV', 'DSV-Nummer', 'Startpassnummer' ],
                    'strecke_m' => [ 'Strecke', 'Strecke_m', 'Meter', 'Distanz' ],
                    'lage'      => [ 'Lage', 'Schwimmart', 'Disziplin', 'Stil' ],
                    'bahnlaenge' => [ 'Bahnlänge', 'Bahn', 'Becken', 'Bahnlaenge' ],
                    'zeit'      => [ 'Zeit', 'Endzeit', 'Ergebnis' ],
                    'datum'     => [ 'Datum', 'Erreicht am', 'Wettkampftag' ],
                    'wettkampf' => [ 'Wettkampf', 'Veranstaltung', 'Meeting' ],
                    'offiziell' => [ 'Offiziell', 'Amtlich', 'Wettkampfzeit' ],
                    'bemerkung' => [ 'Bemerkung', 'Notiz', 'Hinweis' ],
                ];
            case 'sets':
            case 'vorlage_sets':
                return [
                    'titel'    => [ 'Titel', 'Name', 'Set' ],
                    'wiederholungen' => [ 'Wiederholungen', 'Wdh', 'Anzahl', 'Mal' ],
                    'strecke_m' => [ 'Strecke', 'Strecke_m', 'Meter', 'Distanz' ],
                    'lage'     => [ 'Lage', 'Schwimmart', 'Disziplin', 'Stil' ],
                    'intervall_sek' => [ 'Intervall', 'Takt', 'Abgang' ],
                    'pause_sek' => [ 'Pause' ],
                    'material' => [ 'Material', 'Hilfsmittel' ],
                    'hinweis'  => [ 'Hinweis', 'Bemerkung' ],
                    'beschreibung' => [ 'Beschreibung', 'Übungen', 'Uebungen' ],
                ];
        }
        return [];
    }

    /** Welche Spalten unbedingt da sein müssen. */
    private static function pflicht( $kategorie ) {
        switch ( $kategorie ) {
            case 'schwimmer':    return [ 'nachname' ];
            case 'mannschaften': return [ 'name' ];
            case 'mitglieder':   return [ 'nachname' ];
            case 'zeiten':       return [ 'nachname', 'strecke_m', 'zeit', 'datum' ];
            case 'sets':
            case 'vorlage_sets': return [ 'strecke_m' ];
        }
        return [];
    }

    /**
     * Die Musterdatei.
     *
     * Sie ist kein Beiwerk: Ohne sie rät jeder Verein die Spaltennamen, und
     * der erste Versuch geht schief. Darin stehen die Spalten in der
     * Reihenfolge, in der sie gelesen werden, und zwei bis drei Zeilen, die
     * zeigen, wie ein Wert aussieht — Zeiten mit Komma, Daten deutsch, Ja/Nein
     * als „ja".
     */
    public static function muster( $kategorie ) {
        $zeilen = [];
        switch ( $kategorie ) {
            case 'schwimmer':
                $zeilen = [
                    [ 'Vorname', 'Nachname', 'Jahrgang', 'DSV-ID', 'Notiz' ],
                    [ 'Mia', 'Musterkind', '2012', '1234567', '' ],
                    [ 'Noah', 'Neuling', '2013', '', 'neu seit Mai' ],
                    [ 'Charlotte', 'Wiedenbrück-Hagedorn', '2012', '', '' ],
                ];
                break;
            case 'mannschaften':
                $zeilen = [
                    [ 'Name', 'Beschreibung', 'Sortierung' ],
                    [ 'C-Mannschaft', 'Jahrgang 2012 bis 2014', '10' ],
                    [ 'B-Mannschaft', '', '20' ],
                ];
                break;
            case 'mitglieder':
                $zeilen = [
                    [ 'Vorname', 'Nachname', 'Jahrgang', 'DSV-ID', 'Von', 'Bis' ],
                    [ 'Mia', 'Musterkind', '2012', '1234567', '01.01.2026', '' ],
                    [ 'Noah', 'Neuling', '2013', '', '01.05.2026', '' ],
                ];
                break;
            case 'zeiten':
                $zeilen = [
                    [ 'Vorname', 'Nachname', 'DSV-ID', 'Strecke', 'Lage', 'Bahnlänge',
                      'Zeit', 'Datum', 'Wettkampf', 'Offiziell', 'Bemerkung' ],
                    [ 'Mia', 'Musterkind', '1234567', '50', 'Freistil', '25',
                      '31,40', '01.04.2026', 'Kreismeisterschaft', 'ja', '' ],
                    [ 'Mia', 'Musterkind', '1234567', '100', 'Brust', '25',
                      '1:28,10', '01.04.2026', 'Kreismeisterschaft', 'ja', '' ],
                    [ 'Noah', 'Neuling', '', '50', 'Rücken', '50',
                      '40,00', '12.05.2026', '', 'nein', 'im Training' ],
                ];
                break;
            case 'sets':
            case 'vorlage_sets':
                $zeilen = [
                    [ 'Titel', 'Wiederholungen', 'Strecke', 'Lage', 'Intervall', 'Pause',
                      'Material', 'Hinweis', 'Beschreibung' ],
                    [ 'Einschwimmen', '1', '400', 'Einschwimmen', '8:00', '', '', '', '' ],
                    [ 'Technikblock', '4', '50', 'Technik', '', '0:20', 'Brett',
                      'Fußspitzen gestreckt',
                      "Fäuste\nEinarmig rechts\nEinarmig links\nGanze Lage" ],
                    [ 'Hauptserie', '8', '50', 'Rücken', '1:00', '2:00', 'Paddles', '', '' ],
                ];
                break;
            default:
                return '';
        }
        return LSV07P_CSV::schreiben( $zeilen );
    }

    /** Der Dateiname der Musterdatei. */
    public static function muster_name( $kategorie ) {
        $k = isset( self::KATEGORIEN[ $kategorie ] ) ? $kategorie : 'schwimmer';
        return 'SwimPlan-Muster-' . $k . '.csv';
    }

    /**
     * Prüfen oder übernehmen.
     *
     * `$trocken = true` schreibt nichts und liefert nur, was passieren würde.
     * Beides läuft durch dieselbe Schleife — das ist die ganze Zusicherung,
     * dass die Vorschau stimmt.
     *
     * Rückgabe: [ 'kategorie', 'spalten', 'fehlend', 'zeilen' => [ … ],
     *             'zahl' => [ 'neu' => n, 'geaendert' => n, 'weg' => n,
     *                         'fehler' => n ] ]
     */
    public static function lauf( $kategorie, $roh, $ziel_id = 0, $trocken = true ) {
        if ( ! isset( self::KATEGORIEN[ $kategorie ] ) ) {
            return [ 'fehler' => 'Diese Kategorie gibt es nicht.' ];
        }
        if ( ! LSV07P_Rollen::darf_planen() ) {
            return [ 'fehler' => 'Das darf nur ein Trainer.' ];
        }

        $darf = self::darf( $kategorie, $ziel_id );
        if ( $darf !== true ) return [ 'fehler' => $darf ];

        $gelesen = LSV07P_CSV::lesen( $roh );
        if ( isset( $gelesen['fehler'] ) ) return [ 'fehler' => $gelesen['fehler'] ];

        $zeilen = $gelesen['zeilen'];
        $kopf   = array_shift( $zeilen );
        $felder = self::felder( $kategorie );
        $zu     = LSV07P_CSV::kopf_zuordnen( $kopf, $felder );

        $fehlend = [];
        foreach ( self::pflicht( $kategorie ) as $p ) {
            if ( ! isset( $zu[ $p ] ) ) $fehlend[] = $felder[ $p ][0];
        }
        if ( $fehlend ) {
            return [ 'fehler' => 'In der Kopfzeile fehlt: ' . implode( ', ', $fehlend )
                   . '. Lade dir die Musterdatei herunter — dort stehen alle Spalten.' ];
        }
        if ( ! $zeilen ) {
            return [ 'fehler' => 'Unter der Kopfzeile steht keine einzige Zeile.' ];
        }

        $aus  = [];
        $zahl = [ 'neu' => 0, 'geaendert' => 0, 'weg' => 0, 'fehler' => 0 ];

        foreach ( $zeilen as $i => $zeile ) {
            $erg = self::zeile( $kategorie, $zeile, $zu, $ziel_id, $trocken );
            $erg['nr'] = $i + 2;            // +2: Kopfzeile und Zählung ab 1
            $aus[] = $erg;
            if ( isset( $zahl[ $erg['art'] ] ) ) $zahl[ $erg['art'] ]++;
        }

        if ( ! $trocken && ( $zahl['neu'] || $zahl['geaendert'] ) ) {
            LSV07P_DB::spur( 'import.' . $kategorie, (int) $ziel_id,
                $zahl['neu'] . ' neu, ' . $zahl['geaendert'] . ' geändert' );
        }

        return [
            'kategorie' => $kategorie,
            'name'      => self::KATEGORIEN[ $kategorie ]['name'],
            'spalten'   => array_keys( $zu ),
            'trenner'   => $gelesen['trenner'] === "\t" ? 'Tabulator' : $gelesen['trenner'],
            'zeilen'    => $aus,
            'zahl'      => $zahl,
            'trocken'   => (bool) $trocken,
        ];
    }

    /** Darf der Anrufer in dieser Kategorie überhaupt etwas tun? */
    private static function darf( $kategorie, $ziel_id ) {
        switch ( self::KATEGORIEN[ $kategorie ]['ziel'] ) {
            case 'team':
                if ( ! LSV07P_Rollen::darf_team( (int) $ziel_id ) ) {
                    return 'Diese Mannschaft gehört nicht zu deinen.';
                }
                break;
            case 'plan':
                if ( ! LSV07P_Plan::darf_aendern( (int) $ziel_id ) ) {
                    return 'Diesen Plan darfst du nicht ändern.';
                }
                break;
            case 'vorlage':
                if ( ! LSV07P_Vorlage::darf_aendern( (int) $ziel_id ) ) {
                    return 'Diese Vorlage darfst du nicht ändern.';
                }
                break;
        }
        /* Mannschaften anzulegen ist Sache der Verwaltung — wie überall. */
        if ( $kategorie === 'mannschaften' && ! LSV07P_Rollen::ist_admin() ) {
            return 'Mannschaften legt die Verwaltung an.';
        }
        return true;
    }

    // ── Die einzelnen Zeilen ────────────────────────────────────────────

    private static function zeile( $kategorie, array $zeile, array $zu, $ziel_id, $trocken ) {
        switch ( $kategorie ) {
            case 'schwimmer':    return self::z_schwimmer( $zeile, $zu, $trocken );
            case 'mannschaften': return self::z_mannschaft( $zeile, $zu, $trocken );
            case 'mitglieder':   return self::z_mitglied( $zeile, $zu, $ziel_id, $trocken );
            case 'zeiten':       return self::z_zeit( $zeile, $zu, $trocken );
            case 'sets':         return self::z_set( $zeile, $zu, $ziel_id, $trocken, false );
            case 'vorlage_sets': return self::z_set( $zeile, $zu, $ziel_id, $trocken, true );
        }
        return [ 'art' => 'fehler', 'text' => '', 'grund' => 'Unbekannte Kategorie.' ];
    }

    private static function fehler( $text, $grund ) {
        return [ 'art' => 'fehler', 'text' => $text, 'grund' => $grund ];
    }

    private static function z_schwimmer( array $zeile, array $zu, $trocken ) {
        $w = function ( $f, $s = '' ) use ( $zeile, $zu ) {
            return LSV07P_CSV::wert( $zeile, $zu, $f, $s );
        };
        $vor  = $w( 'vorname' );
        $nach = $w( 'nachname' );
        $name = trim( $vor . ' ' . $nach );
        if ( $name === '' ) return self::fehler( '', 'Ohne Namen geht es nicht.' );

        $felder = [ 'vorname' => $vor, 'nachname' => $nach,
                    'jahrgang' => $w( 'jahrgang' ), 'dsv_id' => $w( 'dsv_id' ),
                    'notiz' => $w( 'notiz' ) ];

        /* Schon da? Erkannt wird an der DSV-ID, sonst am Namen samt Jahrgang.
           Der Name allein reicht nicht: „Max Müller" gibt es in einem Verein
           zweimal, und dann überschriebe der Import den falschen. */
        $fund = self::schwimmer_finden( $w( 'dsv_id' ), $vor, $nach, $w( 'jahrgang' ) );
        if ( $fund['mehrdeutig'] ) {
            return self::fehler( $name, 'Diesen Namen gibt es mehrfach. Trage die '
                . 'DSV-ID oder den Jahrgang in die Datei ein — sonst wäre nicht zu '
                . 'sagen, wer gemeint ist.' );
        }
        $da = $fund['id'];

        if ( $da ) {
            if ( ! LSV07P_Schwimmer::darf( $da ) ) {
                return self::fehler( $name, 'Dieser Schwimmer ist in keiner deiner Mannschaften.' );
            }
            if ( $trocken ) {
                $pruef = LSV07P_Schwimmer::pruefen( $felder, $da );
                if ( $pruef !== true ) return self::fehler( $name, (string) $pruef );
            } else {
                $erg = LSV07P_Schwimmer::aendern( $da, $felder );
                if ( $erg !== true ) return self::fehler( $name, (string) $erg );
            }
            return [ 'art' => 'geaendert', 'text' => $name, 'grund' => 'war schon da' ];
        }

        if ( ! $trocken ) {
            $erg = LSV07P_Schwimmer::anlegen( $felder );
            if ( ! is_int( $erg ) ) return self::fehler( $name, (string) $erg );
        } else {
            /* Trocken wird dieselbe Prüfung gefahren, nur ohne zu schreiben —
               sonst meldete die Vorschau „neu" und der Import danach einen
               Fehler. */
            $pruef = LSV07P_Schwimmer::pruefen( $felder );
            if ( $pruef !== true ) return self::fehler( $name, (string) $pruef );
        }
        return [ 'art' => 'neu', 'text' => $name, 'grund' => '' ];
    }

    /**
     * Einen vorhandenen Schwimmer erkennen.
     *
     * In drei Stufen, von sicher nach wahrscheinlich:
     *
     *  1. **DSV-ID.** Sie ist eindeutig, auch wenn der Name anders
     *     geschrieben ist.
     *  2. **Name und Jahrgang.** Der übliche Fall.
     *  3. **Name allein — aber nur, wenn er eindeutig ist.** Viele
     *     Vereinslisten führen keinen Jahrgang; ohne diese Stufe würde
     *     dieselbe Liste beim zweiten Hochladen jeden noch einmal anlegen.
     *     Gibt es den Namen zweimal, wird das GEMELDET und nicht geraten:
     *     „Max Müller" gibt es in einem Verein zweimal, und dann überschriebe
     *     der Import den falschen.
     *
     * Rückgabe: [ 'id' => n, 'mehrdeutig' => bool ].
     */
    private static function schwimmer_finden( $dsv, $vor, $nach, $jahrgang ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $nach = trim( (string) $nach );
        $vor  = trim( (string) $vor );

        $dsv = LSV07P_Schwimmer::dsv( $dsv );
        if ( $dsv !== '' ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07p_schwimmer WHERE dsv_id = %s", $dsv ) );
            if ( $id ) return [ 'id' => $id, 'mehrdeutig' => false ];
        }

        if ( $nach === '' && $vor === '' ) return [ 'id' => 0, 'mehrdeutig' => false ];

        $jg = LSV07P_Schwimmer::jahrgang( $jahrgang );
        if ( $jg > 0 ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07p_schwimmer
                  WHERE nachname = %s AND vorname = %s AND jahrgang = %d",
                $nach, $vor, $jg ) );
            if ( $id ) return [ 'id' => $id, 'mehrdeutig' => false ];
        }

        /* Nur ohne Jahrgang in der Datei. Steht einer darin und passt er zu
           keinem Datensatz, dann ist es ein ANDERER Schwimmer — zwei Kinder
           desselben Namens aus verschiedenen Jahrgängen gibt es, und der
           Jahrgang in der Datei ist die Auskunft darüber, welches gemeint
           ist. Ihn zu übergehen hiesse, den falschen zu überschreiben. */
        if ( $jg > 0 ) return [ 'id' => 0, 'mehrdeutig' => false ];

        $treffer = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07p_schwimmer WHERE nachname = %s AND vorname = %s",
            $nach, $vor ) );
        if ( count( $treffer ) === 1 ) {
            return [ 'id' => (int) $treffer[0], 'mehrdeutig' => false ];
        }
        if ( count( $treffer ) > 1 ) {
            return [ 'id' => 0, 'mehrdeutig' => true ];
        }
        return [ 'id' => 0, 'mehrdeutig' => false ];
    }

    private static function z_mannschaft( array $zeile, array $zu, $trocken ) {
        $name = LSV07P_CSV::wert( $zeile, $zu, 'name' );
        if ( $name === '' ) return self::fehler( '', 'Ohne Namen keine Mannschaft.' );

        $besch = LSV07P_CSV::wert( $zeile, $zu, 'beschreibung' );
        $sort  = (int) LSV07P_CSV::wert( $zeile, $zu, 'sortierung', '0' );

        foreach ( LSV07P_Mannschaft::alle() as $t ) {
            if ( mb_strtolower( $t['name'] ) !== mb_strtolower( $name ) ) continue;
            if ( ! $trocken ) {
                LSV07P_Mannschaft::aendern( (int) $t['id'],
                    [ 'name' => $name, 'beschreibung' => $besch, 'sortierung' => $sort ] );
            }
            return [ 'art' => 'geaendert', 'text' => $name, 'grund' => 'war schon da' ];
        }

        if ( ! $trocken && ! LSV07P_Mannschaft::anlegen( $name, $besch, $sort ) ) {
            return self::fehler( $name, 'Die Mannschaft ist nicht entstanden.' );
        }
        return [ 'art' => 'neu', 'text' => $name, 'grund' => '' ];
    }

    private static function z_mitglied( array $zeile, array $zu, $team, $trocken ) {
        $w = function ( $f, $s = '' ) use ( $zeile, $zu ) {
            return LSV07P_CSV::wert( $zeile, $zu, $f, $s );
        };
        $vor  = $w( 'vorname' );
        $nach = $w( 'nachname' );
        $name = trim( $vor . ' ' . $nach );
        if ( $name === '' ) return self::fehler( '', 'Ohne Namen geht es nicht.' );

        $fund = self::schwimmer_finden( $w( 'dsv_id' ), $vor, $nach, $w( 'jahrgang' ) );
        if ( $fund['mehrdeutig'] ) {
            return self::fehler( $name, 'Diesen Namen gibt es mehrfach. Trage die '
                . 'DSV-ID oder den Jahrgang in die Datei ein.' );
        }
        $sid = $fund['id'];

        /* Wen es noch nicht gibt, legt der Import an: Sonst müsste man jede
           Mannschaftsliste zweimal hochladen — einmal unter Schwimmer und
           einmal hier. */
        if ( ! $sid ) {
            $felder = [ 'vorname' => $vor, 'nachname' => $nach,
                        'jahrgang' => $w( 'jahrgang' ), 'dsv_id' => $w( 'dsv_id' ) ];
            if ( $trocken ) {
                $pruef = LSV07P_Schwimmer::pruefen( $felder );
                if ( $pruef !== true ) return self::fehler( $name, (string) $pruef );
                return [ 'art' => 'neu', 'text' => $name,
                         'grund' => 'wird angelegt und aufgenommen' ];
            }
            $erg = LSV07P_Schwimmer::anlegen( $felder );
            if ( ! is_int( $erg ) ) return self::fehler( $name, (string) $erg );
            $sid = $erg;
        }

        $von = $w( 'von' ) !== '' ? LSV07P_Mannschaft::datum( $w( 'von' ) ) : '';
        if ( $w( 'von' ) !== '' && $von === '' ) {
            return self::fehler( $name, 'Das Datum in „Von" ist nicht zu lesen.' );
        }
        $bis = $w( 'bis' ) !== '' ? LSV07P_Mannschaft::datum( $w( 'bis' ) ) : '';
        if ( $w( 'bis' ) !== '' && $bis === '' ) {
            return self::fehler( $name, 'Das Datum in „Bis" ist nicht zu lesen.' );
        }

        /* Läuft die Mitgliedschaft schon, ist nichts zu tun. */
        foreach ( LSV07P_Mannschaft::mitglieder( $team ) as $m ) {
            if ( (int) $m['schwimmer_id'] !== (int) $sid ) continue;
            return [ 'art' => 'geaendert', 'text' => $name, 'grund' => 'ist schon dabei' ];
        }

        if ( ! $trocken ) {
            $mid = LSV07P_Mannschaft::aufnehmen( $team, $sid, $von );
            if ( ! $mid ) return self::fehler( $name, 'Die Aufnahme ging nicht.' );
            if ( $bis !== '' ) LSV07P_Mannschaft::beenden( $mid, $bis );
        }
        return [ 'art' => 'neu', 'text' => $name,
                 'grund' => $von !== '' ? ( 'dabei seit ' . LSV07P_Bericht::de( $von ) ) : '' ];
    }

    private static function z_zeit( array $zeile, array $zu, $trocken ) {
        $w = function ( $f, $s = '' ) use ( $zeile, $zu ) {
            return LSV07P_CSV::wert( $zeile, $zu, $f, $s );
        };
        $vor  = $w( 'vorname' );
        $nach = $w( 'nachname' );
        $name = trim( $vor . ' ' . $nach );

        $fund = self::schwimmer_finden( $w( 'dsv_id' ), $vor, $nach, $w( 'jahrgang' ) );
        if ( $fund['mehrdeutig'] ) {
            return self::fehler( $name, 'Diesen Namen gibt es mehrfach. Trage die '
                . 'DSV-ID oder den Jahrgang in die Datei ein — sonst stünde die Zeit '
                . 'womöglich beim falschen Kind.' );
        }
        $sid = $fund['id'];
        if ( ! $sid ) {
            return self::fehler( $name, 'Diesen Schwimmer gibt es nicht. Lege ihn zuerst an '
                . '— eine Zeit für einen Namen, den niemand kennt, wäre eine Zeit für '
                . 'niemanden.' );
        }
        if ( ! LSV07P_Schwimmer::darf( $sid ) ) {
            return self::fehler( $name, 'Dieser Schwimmer ist in keiner deiner Mannschaften.' );
        }

        $datum = LSV07P_Mannschaft::datum( $w( 'datum' ) );
        if ( $datum === '' ) return self::fehler( $name, 'Das Datum ist nicht zu lesen.' );

        $felder = [
            'schwimmer_id' => $sid,
            'zeit'        => $w( 'zeit' ),
            'strecke_m'   => (int) $w( 'strecke_m', '0' ),
            'lage'        => self::lage( $w( 'lage', 'frei' ) ),
            'bahnlaenge'  => (int) $w( 'bahnlaenge', '25' ),
            'erreicht_am' => $datum,
            'wettkampf'   => $w( 'wettkampf' ),
            'offiziell'   => LSV07P_CSV::ja( $w( 'offiziell' ) ),
            'bemerkung'   => $w( 'bemerkung' ),
        ];

        $kurz = $name . ' · ' . $felder['strecke_m'] . ' m '
              . LSV07P_Rechnung::lage_name( $felder['lage'] ) . ' · ' . $w( 'zeit' );

        /* Dieselbe Zeit zweimal hochzuladen soll sie nicht verdoppeln: Gleicher
           Schwimmer, gleiche Strecke, gleiche Lage, gleiche Bahn, gleicher Tag
           und gleiche Zeit ist dieselbe Zeit. */
        $hundert = LSV07P_Zeiten::parse( $w( 'zeit' ) );
        if ( $hundert > 0 ) {
            global $wpdb;
            $p = $wpdb->prefix;
            $da = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07p_zeit
                  WHERE schwimmer_id = %d AND strecke_m = %d AND lage = %s
                    AND bahnlaenge = %d AND erreicht_am = %s AND hundertstel = %d",
                $sid, $felder['strecke_m'], $felder['lage'], $felder['bahnlaenge'],
                $datum, $hundert ) );
            if ( $da ) {
                return [ 'art' => 'weg', 'text' => $kurz, 'grund' => 'steht schon so da' ];
            }
        }

        if ( $trocken ) {
            $pruef = LSV07P_Zeiten::pruefen( $felder );
            if ( $pruef !== true ) return self::fehler( $kurz, (string) $pruef );
        } else {
            $erg = LSV07P_Zeiten::erfassen( $felder );
            if ( ! is_int( $erg ) ) return self::fehler( $kurz, (string) $erg );
        }
        return [ 'art' => 'neu', 'text' => $kurz, 'grund' => '' ];
    }

    private static function z_set( array $zeile, array $zu, $ziel, $trocken, $vorlage ) {
        $w = function ( $f, $s = '' ) use ( $zeile, $zu ) {
            return LSV07P_CSV::wert( $zeile, $zu, $f, $s );
        };
        $strecke = (int) $w( 'strecke_m', '0' );
        if ( $strecke <= 0 ) return self::fehler( $w( 'titel' ), 'Ohne Strecke kein Set.' );

        $felder = [
            'titel'         => $w( 'titel' ),
            'wiederholungen' => max( 1, (int) $w( 'wiederholungen', '1' ) ),
            'strecke_m'     => $strecke,
            'lage'          => self::lage( $w( 'lage', 'frei' ) ),
            'intervall_sek' => self::sek( $w( 'intervall_sek' ) ),
            'pause_sek'     => self::sek( $w( 'pause_sek' ) ),
            'material'      => $w( 'material' ),
            'hinweis'       => $w( 'hinweis' ),
            'beschreibung'  => $w( 'beschreibung' ),
        ];

        $kurz = LSV07P_Rechnung::set_text( $felder['wiederholungen'], $strecke,
            $felder['lage'], $felder['intervall_sek'] );
        if ( $felder['titel'] !== '' ) $kurz = $felder['titel'] . ' — ' . $kurz;

        if ( ! $trocken ) {
            $neu = $vorlage
                 ? LSV07P_Vorlage::set_anlegen( (int) $ziel, $felder )
                 : LSV07P_Plan::set_anlegen( (int) $ziel, $felder, false );
            if ( ! $neu ) return self::fehler( $kurz, 'Das Set ist nicht entstanden.' );
        }
        return [ 'art' => 'neu', 'text' => $kurz, 'grund' => '' ];
    }

    /**
     * Eine Lage aus dem, was in einer Vereinstabelle steht. „Freistil", „F",
     * „Kraul", „FR" — gemeint ist immer dasselbe.
     */
    public static function lage( $roh ) {
        $formen = LSV07P_CSV::kurz_beide( $roh );
        $k = $formen[0];
        if ( $k === '' ) return 'frei';

        $wort = [
            'frei' => [ 'frei', 'freistil', 'f', 'fr', 'kraul', 'free', 'freestyle', 'fs' ],
            'ruecken' => [ 'ruecken', 'r', 'rk', 'back', 'backstroke', 'ru' ],
            'brust' => [ 'brust', 'b', 'br', 'breast', 'breaststroke' ],
            'delfin' => [ 'delfin', 'delphin', 's', 'schmetterling', 'butterfly', 'fly', 'sc' ],
            'lagen' => [ 'lagen', 'l', 'lg', 'medley', 'im', 'sl' ],
            'beine' => [ 'beine', 'bein', 'beinschlag', 'kick', 'kicken' ],
            'arme' => [ 'arme', 'arm', 'armzug', 'pull' ],
            'technik' => [ 'technik', 'tec', 'tech', 'drill' ],
            'wechsel' => [ 'wechsel', 'lagenwechsel', 'uebergang' ],
            'einschwimmen' => [ 'einschwimmen', 'ein', 'warmup', 'aufwaermen', 'einschw' ],
            'ausschwimmen' => [ 'ausschwimmen', 'aus', 'cooldown', 'auslockern', 'ausschw' ],
        ];
        /* Verglichen wird auf beiden Seiten in beiden Schreibweisen: Sonst
           fiele „Rucken" (ohne Umlaut getippt) durch, weil in der Liste
           „ruecken" steht — und zwar schweigend, mit Freistil als Folge. */
        foreach ( $wort as $lage => $namen ) {
            $alle = [];
            foreach ( $namen as $n ) {
                foreach ( LSV07P_CSV::kurz_beide( $n ) as $v ) $alle[ $v ] = true;
            }
            foreach ( $formen as $f ) {
                if ( isset( $alle[ $f ] ) ) return $lage;
            }
        }
        /* Steht dort etwas, das keine Lage ist, wird daraus Freistil und kein
           Fehler: Eine Lage ist kein Pflichtfeld, und eine ganze Zeile wegen
           „Kraulbeine" abzulehnen wäre unverhältnismässig. */
        return isset( LSV07P_Rechnung::LAGEN[ $k ] ) ? $k : 'frei';
    }

    /** „1:40", „100" oder „1:40,0" als Sekunden. */
    public static function sek( $roh ) {
        $t = trim( (string) $roh );
        if ( $t === '' ) return 0;
        if ( strpos( $t, ':' ) !== false ) {
            $teile = explode( ':', $t );
            $min = (int) array_shift( $teile );
            $sek = (int) round( (float) str_replace( ',', '.', implode( '', $teile ) ) );
            return max( 0, min( 3600, $min * 60 + $sek ) );
        }
        return max( 0, min( 3600, (int) round( (float) str_replace( ',', '.', $t ) ) ) );
    }
}
