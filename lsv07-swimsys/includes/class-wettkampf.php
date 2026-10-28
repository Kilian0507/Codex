<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wettkämpfe: Stammdaten, Tage, Freigabe, Dokumente, Anwesenheit,
 * Statistik.
 *
 * Ein Wettkampf hat Name, Ort, einen Datumsbereich, beteiligte
 * Mannschaften und je Tag eine Zeile mit der geplanten Abschnittszahl.
 * Je Tag und Mannschaft gibt es eine Anwesenheitsliste; ein dort als
 * anwesend eingetragener Trainer bekommt einen Eintrag in seiner
 * Abrechnung (Abschnitte × Pauschale).
 *
 * Was hier anders ist als im internen Bereich:
 *
 * 1. **Die Abrechnung wird nur angefasst, solange sie offen ist.**
 *    Dort hängt der Eintrag am Anwesenheitshaken, ohne nach dem Stand
 *    der Abrechnung zu fragen: Wer einen Trainer nachträglich auf
 *    abwesend setzt, löscht eine Zeile aus einer bereits eingereichten
 *    oder genehmigten Abrechnung — und der Betrag der Abrechnung ändert
 *    sich hinter dem Rücken der Kasse. Hier wird eine Abrechnung ab
 *    „eingereicht" nicht mehr verändert; stattdessen sagt die Antwort,
 *    dass der Eintrag stehen bleibt und warum.
 * 2. **Jede Änderung steht im Protokoll** — dort nur Freigabe und
 *    Löschen.
 * 3. **Ein Wettkampftag in der Zukunft** nimmt keine Anwesenheit an.
 *    Dort lässt sich für den nächsten Monat schon abrechnen.
 * 4. **Löschen sagt, was es mitnimmt**, und weist ab, wenn an einem Tag
 *    eine Abrechnung hängt, die nicht mehr offen ist (§ 147 AO).
 * 5. **Die Freigabe trägt ihren Grund.** Dort heisst „kann nicht
 *    freigegeben werden" immer dasselbe; hier steht, ob die
 *    Ausschreibung fehlt oder der Haken.
 */
class LSV07S_Wettkampf {

    /** Die drei Dokumente eines Wettkampfs. */
    const DOKUMENTE = [
        'ausschreibung'  => 'Ausschreibung',
        'meldeergebnis'  => 'Meldeergebnis',
        'protokoll'      => 'Protokoll',
    ];

    /** Die Zustände der Wettkampf-Anwesenheit. Drei, nicht sechs. */
    const ZUSTAENDE = [
        'anwesend'     => 'Anwesend',
        'abwesend'     => 'Abwesend',
        'entschuldigt' => 'Entschuldigt',
    ];

    /** Abrechnungen in diesen Ständen dürfen noch nachziehen. */
    const OFFEN = [ 'entwurf', 'zurueck', 'zurueckgegeben' ];

    // ── Rechte ───────────────────────────────────────────────────────────

    private static function darf_lesen() {
        return LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_WETTKAMPF_READ );
    }

    /** Anlegen und Ändern sind zwei Rechte — beim Anlegen gibt es noch nichts. */
    private static function darf_schreiben( $neu ) {
        return LSV07S_Rechte::darf( $neu
            ? LSV07S_Rechte::SCHWIMMEN_WETTKAMPF_CREATE
            : LSV07S_Rechte::SCHWIMMEN_WETTKAMPF_UPDATE );
    }

    private static function darf_anwesenheit() {
        return LSV07S_Rechte::darf_eines( [
            LSV07S_Rechte::SCHWIMMEN_WETTKAMPF_ANW,
            LSV07S_Rechte::SCHWIMMEN_ANW_UPDATE ] );
    }

    /**
     * Darf dieses Konto diesen Wettkampf sehen?
     *
     * Ein Wettkampf gehört mehreren Mannschaften. Sichtbar ist er, wenn
     * eine davon zum Bereich des Kontos gehört — oder wenn das Konto
     * ohnehin alles sieht.
     */
    private static function in_reichweite( $wettkampf_id, $mannschaften = null ) {
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( LSV07S_Rechte::sieht_alles( $sparte ) ) return true;
        $meine = LSV07S_Mannschaft::eigene_ids( $sparte );
        if ( $meine === [] ) return false;
        $ids = $mannschaften === null ? self::mannschaft_ids( $wettkampf_id ) : $mannschaften;
        return array_intersect( $ids, $meine ) !== [];
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** Die Nummern der beteiligten Mannschaften. */
    public static function mannschaft_ids( $wettkampf_id ) {
        global $wpdb;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT mannschaft_id FROM ' . LSV07S_Tabellen::t( 'wk_mann' )
            . ' WHERE wettkampf_id = %d', (int) $wettkampf_id ) ) );
    }

    /**
     * Die Wettkämpfe, die dieses Konto sehen darf.
     *
     * Ohne Jahr das laufende und das kommende: Ein Wettkampf aus 2019
     * interessiert niemanden beim Öffnen der Ansicht, und die ganze
     * Liste wird mit den Jahren lang.
     */
    public static function liste( $jahr = 0, $mannschaft_id = 0 ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf_lesen() ) return [];
        if ( ! LSV07S_Tabellen::da( 'wettkampf' ) ) return [];

        $w = LSV07S_Tabellen::t( 'wettkampf' );
        $wo = [ '1=1' ];
        $werte = [];
        if ( $jahr > 0 ) {
            $wo[] = 'YEAR(datum_von) = %d';
            $werte[] = (int) $jahr;
        }
        $sql = "SELECT * FROM $w WHERE " . implode( ' AND ', $wo )
             . ' ORDER BY datum_von DESC, id DESC';
        $zeilen = (array) $wpdb->get_results(
            $werte ? $wpdb->prepare( $sql, ...$werte ) : $sql, ARRAY_A );

        $alle   = LSV07S_Mannschaft::alle( $sparte );
        $namen  = [];
        foreach ( $alle as $m ) $namen[ (int) $m['id'] ] = $m['name'];
        $sieht_alles = LSV07S_Rechte::sieht_alles( $sparte );
        $meine = $sieht_alles ? [] : LSV07S_Mannschaft::eigene_ids( $sparte );

        $raus = [];
        foreach ( $zeilen as $z ) {
            $ids = self::mannschaft_ids( $z['id'] );
            if ( ! $sieht_alles && array_intersect( $ids, $meine ) === [] ) continue;
            if ( $mannschaft_id && ! in_array( (int) $mannschaft_id, $ids, true ) ) continue;
            $raus[] = self::zeile( $z, $ids, $namen );
        }
        return $raus;
    }

    /** Ein Wettkampf mit allem, was die Ansicht braucht. */
    public static function holen( $id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        $z = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'wettkampf' ) . ' WHERE id = %d LIMIT 1',
            (int) $id ), ARRAY_A );
        if ( ! $z ) return 'Diesen Wettkampf gibt es nicht.';

        $ids = self::mannschaft_ids( $id );
        if ( ! self::in_reichweite( $id, $ids ) ) {
            return 'Dieser Wettkampf gehört nicht zu Ihrem Bereich.';
        }
        $namen = [];
        foreach ( LSV07S_Mannschaft::alle( $sparte ) as $m ) {
            $namen[ (int) $m['id'] ] = $m['name'];
        }
        $wk = self::zeile( $z, $ids, $namen );
        $wk['links'] = self::links( $id );
        return $wk;
    }

    /** Eine Zeile für die Ausgabe aufbereiten. */
    private static function zeile( $z, array $ids, array $namen ) {
        $frei = (int) ( $z['freigegeben'] ?? 0 ) === 1;
        /* Einmal fragen, zweimal verwenden: `freigabe_moeglich()` liest die
           Dokumente, und das zweite Mal wäre dieselbe Abfrage. */
        $moeglich = self::freigabe_moeglich( (int) $z['id'], $z );
        return [
            'id'          => (int) $z['id'],
            'name'        => (string) $z['name'],
            'ort'         => (string) $z['ort'],
            'datum_von'   => (string) $z['datum_von'],
            'datum_bis'   => (string) $z['datum_bis'],
            'spanne_de'   => LSV07S_Datum::de( $z['datum_von'] )
                             . ( $z['datum_bis'] !== $z['datum_von']
                                 ? ' – ' . LSV07S_Datum::de( $z['datum_bis'] ) : '' ),
            'pauschale'   => (float) ( $z['pauschale'] ?? 0 ),
            'mannschaften'=> array_values( array_map(
                fn( $i ) => [ 'id' => $i, 'name' => $namen[ $i ] ?? ( 'Nummer ' . $i ) ], $ids ) ),
            'tage'        => self::tage( (int) $z['id'] ),
            'freigegeben' => $frei,
            'freigegeben_am' => LSV07S_Datum::de_zeit( $z['freigegeben_am'] ?? null, '' ),
            'freigegeben_von'=> ( $z['freigegeben_von'] ?? 0 )
                                 ? LSV07S_Person::konto_name( $z['freigegeben_von'] ) : '',
            'ohne_ausschreibung' => (int) ( $z['ohne_ausschreibung'] ?? 0 ) === 1,
            'dokumente'   => self::dokumente( (int) $z['id'] ),
            'freigabe_moeglich' => $moeglich === true,
            'freigabe_grund'    => $moeglich,
        ];
    }

    /** Die Tage eines Wettkampfs, mit geplanter Abschnittszahl. */
    public static function tage( $wettkampf_id ) {
        global $wpdb;
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'wk_tage' )
            . ' WHERE wettkampf_id = %d ORDER BY datum ASC', (int) $wettkampf_id ), ARRAY_A );
        return array_map( fn( $t ) => [
            'id'              => (int) $t['id'],
            'datum'           => (string) $t['datum'],
            'datum_de'        => LSV07S_Datum::de( $t['datum'] ),
            'wochentag'       => LSV07S_Datum::wochentag_name(
                                   LSV07S_Datum::wochentag( $t['datum'] ) ),
            'abschnitte_plan' => (int) $t['abschnitte_plan'],
        ], $zeilen );
    }

    /** Die hochgeladenen Dokumente, nach Typ. */
    public static function dokumente( $wettkampf_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'wk_dokument' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'wk_dokument' )
            . ' WHERE wettkampf_id = %d', (int) $wettkampf_id ), ARRAY_A );
        $raus = [];
        foreach ( $zeilen as $z ) {
            $raus[ (string) $z['typ'] ] = [
                'id'    => (int) $z['id'],
                'typ'   => (string) $z['typ'],
                'name'  => (string) $z['dateiname'],
                'label' => self::DOKUMENTE[ $z['typ'] ] ?? (string) $z['typ'],
                'am'    => LSV07S_Datum::de_zeit( $z['hochgeladen_am'] ),
            ];
        }
        return $raus;
    }

    /** Die frei benannten Verweise eines Wettkampfs. */
    public static function links( $wettkampf_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'wk_link' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'wk_link' )
            . ' WHERE wettkampf_id = %d ORDER BY sortierung ASC, id ASC',
            (int) $wettkampf_id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id'    => (int) $z['id'],
            'titel' => (string) $z['titel'],
            'url'   => (string) $z['url'],
        ], $zeilen );
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Einen Wettkampf anlegen oder ändern.
     *
     * `$daten`: name, ort, datum_von, datum_bis, mannschaften (Feld von
     * Nummern), tage (Feld aus datum => abschnitte), ohne_ausschreibung,
     * links (Feld aus titel/url).
     */
    public static function speichern( $id, array $daten ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $id = (int) $id;
        if ( ! self::darf_schreiben( $id === 0 ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! LSV07S_Tabellen::da( 'wettkampf' ) ) return 'Die Tabelle fehlt.';

        $name = trim( sanitize_text_field( (string) ( $daten['name'] ?? '' ) ) );
        $ort  = trim( sanitize_text_field( (string) ( $daten['ort'] ?? '' ) ) );
        $von  = LSV07S_Datum::rein( $daten['datum_von'] ?? '' );
        $bis  = LSV07S_Datum::rein( $daten['datum_bis'] ?? '' );

        if ( $name === '' ) return 'Bitte einen Namen angeben.';
        if ( $ort === '' )  return 'Bitte einen Ort angeben.';
        if ( $von === null || $bis === null ) return 'Bitte Datum von und bis angeben.';
        if ( $bis < $von ) return '„Datum bis" liegt vor „Datum von".';
        /* Ein Wettkampf über mehr als zwei Wochen ist ein Tippfehler im
           Jahr — und er legt 300 Tage an. */
        if ( ( strtotime( $bis ) - strtotime( $von ) ) / DAY_IN_SECONDS > 14 ) {
            return 'Der Zeitraum ist länger als zwei Wochen. Bitte prüfen.';
        }

        $mann = array_values( array_unique( array_filter( array_map( 'absint',
            (array) ( $daten['mannschaften'] ?? [] ) ) ) ) );
        if ( $mann === [] ) return 'Bitte mindestens eine Mannschaft wählen.';

        /* Nur eigene Mannschaften — sonst hängt sich ein Trainer an einen
           Wettkampf, zu dem er nichts zu sagen hat, und sieht danach
           deren Kader in der Anwesenheit. */
        if ( ! LSV07S_Rechte::sieht_alles( $sparte ) ) {
            $meine = LSV07S_Mannschaft::eigene_ids( $sparte );
            $fremd = array_diff( $mann, $meine );
            if ( $fremd !== [] ) {
                return 'Für mindestens eine gewählte Mannschaft fehlt Ihnen die Berechtigung.';
            }
        }
        if ( $id && ! self::in_reichweite( $id ) ) {
            return 'Dieser Wettkampf gehört nicht zu Ihrem Bereich.';
        }

        $ohne = ! empty( $daten['ohne_ausschreibung'] ) ? 1 : 0;
        $t    = LSV07S_Tabellen::t( 'wettkampf' );
        $vorher = $id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE id = %d LIMIT 1", $id ), ARRAY_A ) : null;
        if ( $id && ! $vorher ) return 'Diesen Wettkampf gibt es nicht.';

        $zeile = [
            'name' => $name, 'ort' => $ort,
            'datum_von' => $von, 'datum_bis' => $bis,
        ];
        $form = [ '%s', '%s', '%s', '%s' ];
        if ( LSV07S_Mannschaft::spalte_da( $t, 'ohne_ausschreibung' ) ) {
            $zeile['ohne_ausschreibung'] = $ohne;
            $form[] = '%d';
        }

        /* Die Pauschale wird beim Anlegen festgeschrieben. Sie steht
           später in jeder Abrechnungszeile dieses Wettkampfs; eine
           Erhöhung im nächsten Jahr darf einen alten Wettkampf nicht
           nachträglich teurer machen. Bei einer Änderung bleibt sie
           deshalb stehen. */
        if ( ! $id ) {
            $zeile['pauschale'] = (float) LSV07S_Tabellen::config( 'wk_pauschale', 25 );
            $form[] = '%f';
            $zeile['angelegt_von'] = get_current_user_id();
            $form[] = '%d';
        }

        /* Ein freigegebener Wettkampf verliert die Freigabe, wenn sich
           ändert, was öffentlich steht: Name, Ort, Zeitraum. Sonst
           schlägt eine Änderung still auf der öffentlichen Seite durch,
           ohne dass jemand sie noch einmal angesehen hat. */
        $zurueckgezogen = false;
        if ( $vorher && (int) ( $vorher['freigegeben'] ?? 0 ) === 1
             && ( $vorher['name'] !== $name || $vorher['ort'] !== $ort
                  || $vorher['datum_von'] !== $von || $vorher['datum_bis'] !== $bis ) ) {
            $zeile['freigegeben'] = 0;
            $zeile['freigegeben_von'] = null;
            $zeile['freigegeben_am'] = null;
            $form[] = '%d'; $form[] = '%d'; $form[] = '%s';
            $zurueckgezogen = true;
        }

        if ( $id ) {
            $wpdb->update( $t, $zeile, [ 'id' => $id ], $form, [ '%d' ] );
        } else {
            $wpdb->insert( $t, $zeile, $form );
            $id = (int) $wpdb->insert_id;
            if ( ! $id ) return 'Der Wettkampf konnte nicht angelegt werden.';
        }

        $fehler = self::mannschaften_setzen( $id, $mann );
        if ( is_string( $fehler ) ) return $fehler;

        $tage = self::tage_setzen( $id, $von, $bis, (array) ( $daten['tage'] ?? [] ) );
        if ( is_string( $tage ) ) return $tage;

        if ( array_key_exists( 'links', $daten ) ) {
            self::links_setzen( $id, (array) $daten['links'] );
        }

        LSV07S_Log::schreibe( $vorher ? 'wettkampf.geaendert' : 'wettkampf.angelegt', [
            'bereich' => $sparte, 'ziel_typ' => 'wettkampf', 'ziel_id' => $id,
            'ziel_name' => $name,
            'details' => $von . ' bis ' . $bis . ', ' . count( $mann ) . ' Mannschaft(en)'
                       . ( $zurueckgezogen ? ', Freigabe zurückgezogen' : '' ),
        ] );

        return [
            'id' => $id,
            'freigabe_zurueckgezogen' => $zurueckgezogen,
            'message' => $zurueckgezogen
                ? 'Gespeichert. Die Freigabe wurde zurückgezogen, weil sich Name, Ort '
                  . 'oder Zeitraum geändert haben — bitte erneut freigeben.'
                : 'Gespeichert.',
        ];
    }

    /** Die beteiligten Mannschaften setzen. */
    private static function mannschaften_setzen( $wettkampf_id, array $mann ) {
        global $wpdb;
        $t = LSV07S_Tabellen::t( 'wk_mann' );

        /* Eine Mannschaft, die schon Anwesenheit erfasst hat, wird nicht
           einfach entfernt: Die Liste gehört zu einem Tag, der
           stattgefunden hat. */
        $alt = self::mannschaft_ids( $wettkampf_id );
        foreach ( array_diff( $alt, $mann ) as $weg ) {
            $zahl = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'wk_anw' ) . ' a'
                . ' JOIN ' . LSV07S_Tabellen::t( 'wk_tage' ) . ' t ON t.id = a.wettkampf_tag_id'
                . ' WHERE t.wettkampf_id = %d AND a.mannschaft_id = %d',
                (int) $wettkampf_id, (int) $weg ) );
            if ( $zahl > 0 ) {
                return 'Für eine Mannschaft, die Sie entfernen wollen, ist schon '
                     . 'Anwesenheit erfasst. Diese Mannschaft bleibt beteiligt.';
            }
        }

        $wpdb->delete( $t, [ 'wettkampf_id' => (int) $wettkampf_id ], [ '%d' ] );
        foreach ( $mann as $m ) {
            $wpdb->insert( $t, [ 'wettkampf_id' => (int) $wettkampf_id,
                                 'mannschaft_id' => (int) $m ], [ '%d', '%d' ] );
        }
        return true;
    }

    /**
     * Die Tage setzen.
     *
     * Ohne Angabe jeder Tag des Zeitraums mit einem Abschnitt. Ein Tag,
     * an dem Anwesenheit hängt, wird nicht gelöscht — sonst verschwindet
     * mit ihm die Anwesenheit und die Abrechnungszeile dazu.
     */
    private static function tage_setzen( $wettkampf_id, $von, $bis, array $wunsch ) {
        global $wpdb;
        $t = LSV07S_Tabellen::t( 'wk_tage' );

        $soll = [];
        foreach ( $wunsch as $w ) {
            $d = LSV07S_Datum::rein( is_array( $w ) ? ( $w['datum'] ?? '' ) : $w );
            if ( $d === null || $d < $von || $d > $bis ) continue;
            $a = is_array( $w ) ? max( 1, min( 20, absint( $w['abschnitte_plan'] ?? 1 ) ) ) : 1;
            $soll[ $d ] = $a;
        }
        if ( $soll === [] ) {
            for ( $ts = strtotime( $von ); $ts <= strtotime( $bis ); $ts += DAY_IN_SECONDS ) {
                $soll[ date( 'Y-m-d', $ts ) ] = 1;
            }
        }
        ksort( $soll );

        $da = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE wettkampf_id = %d", (int) $wettkampf_id ), ARRAY_A ) as $z ) {
            $da[ (string) $z['datum'] ] = $z;
        }

        foreach ( $soll as $datum => $abschnitte ) {
            if ( isset( $da[ $datum ] ) ) {
                if ( (int) $da[ $datum ]['abschnitte_plan'] !== $abschnitte ) {
                    $wpdb->update( $t, [ 'abschnitte_plan' => $abschnitte ],
                        [ 'id' => (int) $da[ $datum ]['id'] ], [ '%d' ], [ '%d' ] );
                }
                continue;
            }
            $wpdb->insert( $t, [ 'wettkampf_id' => (int) $wettkampf_id,
                'datum' => $datum, 'abschnitte_plan' => $abschnitte ],
                [ '%d', '%s', '%d' ] );
        }

        $behalten = [];
        foreach ( $da as $datum => $z ) {
            if ( isset( $soll[ $datum ] ) ) continue;
            $hat = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'wk_anw' )
                . ' WHERE wettkampf_tag_id = %d', (int) $z['id'] ) );
            if ( $hat > 0 ) { $behalten[] = LSV07S_Datum::de( $datum ); continue; }
            $wpdb->delete( $t, [ 'id' => (int) $z['id'] ], [ '%d' ] );
        }
        if ( $behalten !== [] ) {
            return 'Diese Tage bleiben stehen, weil dafür Anwesenheit erfasst ist: '
                 . implode( ', ', $behalten ) . '.';
        }
        return true;
    }

    /** Die Verweise setzen. Nur http(s), höchstens zehn. */
    private static function links_setzen( $wettkampf_id, array $liste ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'wk_link' ) ) return;
        $t = LSV07S_Tabellen::t( 'wk_link' );
        $wpdb->delete( $t, [ 'wettkampf_id' => (int) $wettkampf_id ], [ '%d' ] );
        $n = 0;
        foreach ( $liste as $l ) {
            if ( $n >= 10 ) break;
            $titel = trim( sanitize_text_field( (string) ( $l['titel'] ?? '' ) ) );
            $url   = trim( (string) ( $l['url'] ?? '' ) );
            if ( $titel === '' || $url === '' ) continue;
            if ( ! preg_match( '#^https?://#i', $url ) ) continue;
            $wpdb->insert( $t, [
                'wettkampf_id' => (int) $wettkampf_id,
                'titel' => mb_substr( $titel, 0, 60 ),
                'url'   => mb_substr( esc_url_raw( $url ), 0, 500 ),
                'sortierung' => $n,
                'hinzugefuegt_von' => get_current_user_id(),
            ], [ '%d', '%s', '%s', '%d', '%d' ] );
            $n++;
        }
    }

    /**
     * Einen Wettkampf löschen.
     *
     * Was dabei mitgeht, steht in der Antwort. Hängt an einem Tag eine
     * Abrechnung, die nicht mehr offen ist, wird abgewiesen: Eine
     * eingereichte oder genehmigte Abrechnung ist ein Buchungsbeleg
     * (§ 147 AO), und ihr Betrag darf sich nicht rückwirkend ändern.
     */
    public static function loeschen( $id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $id = (int) $id;
        if ( ! LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_WETTKAMPF_DELETE ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $t = LSV07S_Tabellen::t( 'wettkampf' );
        $wk = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1", $id ),
            ARRAY_A );
        if ( ! $wk ) return 'Diesen Wettkampf gibt es nicht.';
        if ( ! self::in_reichweite( $id ) ) {
            return 'Dieser Wettkampf gehört nicht zu Ihrem Bereich.';
        }

        $feste = self::feste_abrechnungen( $id );
        if ( $feste !== [] ) {
            return 'Dieser Wettkampf steht in einer Abrechnung, die nicht mehr offen ist ('
                 . implode( ', ', $feste ) . '). Eine eingereichte Abrechnung ist ein '
                 . 'Buchungsbeleg; ihr Betrag darf sich nicht rückwirkend ändern.';
        }

        $tage = self::tage( $id );
        $anw = 0;
        foreach ( $tage as $tag ) {
            $anw += (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'wk_anw' )
                . ' WHERE wettkampf_tag_id = %d', $tag['id'] ) );
        }

        foreach ( $tage as $tag ) self::tag_entfernen( $tag['id'] );
        $wpdb->delete( LSV07S_Tabellen::t( 'wk_mann' ), [ 'wettkampf_id' => $id ], [ '%d' ] );
        if ( LSV07S_Tabellen::da( 'wk_link' ) ) {
            $wpdb->delete( LSV07S_Tabellen::t( 'wk_link' ), [ 'wettkampf_id' => $id ], [ '%d' ] );
        }
        if ( LSV07S_Tabellen::da( 'wk_dokument' ) ) {
            foreach ( self::dokumente( $id ) as $d ) self::dokument_loeschen( $d['id'], true );
        }
        if ( LSV07S_Tabellen::da( 'meldung' ) ) {
            $meldungen = (array) $wpdb->get_col( $wpdb->prepare(
                'SELECT id FROM ' . LSV07S_Tabellen::t( 'meldung' )
                . ' WHERE wettkampf_id = %d', $id ) );
            foreach ( $meldungen as $m ) {
                $wpdb->delete( LSV07S_Tabellen::t( 'meldung_start' ),
                    [ 'meldung_id' => (int) $m ], [ '%d' ] );
                $wpdb->delete( LSV07S_Tabellen::t( 'meldung' ), [ 'id' => (int) $m ], [ '%d' ] );
            }
        } else {
            $meldungen = [];
        }
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );

        LSV07S_Log::schreibe( 'wettkampf.geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'wettkampf', 'ziel_id' => $id,
            'ziel_name' => (string) $wk['name'],
            'details' => count( $tage ) . ' Tag(e), ' . $anw . ' Anwesenheitsliste(n), '
                       . count( $meldungen ) . ' Meldung(en)',
        ] );

        return [ 'message' => 'Gelöscht: ' . count( $tage ) . ' Tag(e), ' . $anw
                            . ' Anwesenheitsliste(n), ' . count( $meldungen ) . ' Meldung(en).' ];
    }

    /**
     * Welche nicht mehr offenen Abrechnungen hängen an diesem Wettkampf?
     *
     * Zurück kommen ihre Bezeichnungen (Quartal und Jahr), damit die
     * Meldung sagen kann, welche es sind.
     */
    private static function feste_abrechnungen( $wettkampf_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'abr_wk' ) || ! LSV07S_Tabellen::da( 'abrechnung' ) ) {
            return [];
        }
        $offen = "'" . implode( "','", self::OFFEN ) . "'";
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT DISTINCT a.quartal, a.jahr FROM ' . LSV07S_Tabellen::t( 'abr_wk' ) . ' aw'
            . ' JOIN ' . LSV07S_Tabellen::t( 'abrechnung' ) . ' a ON a.id = aw.abrechnung_id'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wk_tage' ) . ' t ON t.id = aw.wettkampf_tag_id'
            . " WHERE t.wettkampf_id = %d AND a.abr_status NOT IN ($offen)",
            (int) $wettkampf_id ), ARRAY_A );
        return array_map( fn( $z ) => $z['quartal'] . '/' . $z['jahr'], $zeilen );
    }

    /** Einen Tag mit allem, was daran hängt, entfernen. */
    private static function tag_entfernen( $tag_id ) {
        global $wpdb;
        $anw = (array) $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM ' . LSV07S_Tabellen::t( 'wk_anw' )
            . ' WHERE wettkampf_tag_id = %d', (int) $tag_id ) );
        foreach ( $anw as $a ) {
            $wpdb->delete( LSV07S_Tabellen::t( 'wk_anw_eintr' ),
                [ 'wk_anwesenheit_id' => (int) $a ], [ '%d' ] );
            $wpdb->delete( LSV07S_Tabellen::t( 'wk_anw' ), [ 'id' => (int) $a ], [ '%d' ] );
        }
        if ( LSV07S_Tabellen::da( 'abr_wk' ) ) {
            $wpdb->delete( LSV07S_Tabellen::t( 'abr_wk' ),
                [ 'wettkampf_tag_id' => (int) $tag_id ], [ '%d' ] );
        }
        $wpdb->delete( LSV07S_Tabellen::t( 'wk_tage' ), [ 'id' => (int) $tag_id ], [ '%d' ] );
    }

    // ── Freigabe ─────────────────────────────────────────────────────────

    /**
     * Darf dieser Wettkampf freigegeben werden?
     *
     * Zurück kommt `true` oder der Grund — der interne Bereich gibt
     * immer denselben Satz zurück, auch wenn der Haken fehlt und nicht
     * die Datei.
     */
    public static function freigabe_moeglich( $wettkampf_id, $wk = null ) {
        global $wpdb;
        if ( $wk === null ) {
            $wk = $wpdb->get_row( $wpdb->prepare(
                'SELECT * FROM ' . LSV07S_Tabellen::t( 'wettkampf' ) . ' WHERE id = %d LIMIT 1',
                (int) $wettkampf_id ), ARRAY_A );
        }
        if ( ! $wk ) return 'Diesen Wettkampf gibt es nicht.';
        if ( ! empty( $wk['ohne_ausschreibung'] ) ) return true;
        $dok = self::dokumente( (int) $wettkampf_id );
        if ( isset( $dok['ausschreibung'] ) ) return true;
        return 'Vor der Freigabe gehört die Ausschreibung als PDF hierher. Gibt es zu '
             . 'diesem Wettkampf keine, setzen Sie den Haken „Zu diesem Wettkampf gibt '
             . 'es keine Ausschreibung".';
    }

    /** Freigeben — danach steht der Wettkampf öffentlich. */
    public static function freigeben( $id, $an = true ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $id = (int) $id;
        if ( ! LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_WETTKAMPF_APPROVE ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $t  = LSV07S_Tabellen::t( 'wettkampf' );
        $wk = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1", $id ),
            ARRAY_A );
        if ( ! $wk ) return 'Diesen Wettkampf gibt es nicht.';

        if ( $an ) {
            $moeglich = self::freigabe_moeglich( $id, $wk );
            if ( $moeglich !== true ) return $moeglich;
            $wpdb->update( $t, [
                'freigegeben' => 1,
                'freigegeben_von' => get_current_user_id(),
                'freigegeben_am' => LSV07S_Datum::jetzt(),
            ], [ 'id' => $id ], [ '%d', '%d', '%s' ], [ '%d' ] );
        } else {
            $wpdb->update( $t, [
                'freigegeben' => 0, 'freigegeben_von' => null, 'freigegeben_am' => null,
            ], [ 'id' => $id ], [ '%d', '%d', '%s' ], [ '%d' ] );
        }

        LSV07S_Log::schreibe( $an ? 'wettkampf.freigegeben' : 'wettkampf.freigabe_zurueck', [
            'bereich' => $sparte, 'ziel_typ' => 'wettkampf', 'ziel_id' => $id,
            'ziel_name' => (string) $wk['name'],
        ] );

        return [ 'message' => $an
            ? 'Freigegeben — der Wettkampf steht jetzt auf der öffentlichen Seite.'
            : 'Die Freigabe ist zurückgezogen.' ];
    }

    // ── Dokumente ────────────────────────────────────────────────────────

    /** Derselbe Ordner wie im internen Bereich, ebenso gesperrt. */
    public static function ordner() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/wettkampf';
        if ( ! is_dir( $base ) ) wp_mkdir_p( $base );
        LSV07S_Attest::ordner_sperren( dirname( $base ) );
        LSV07S_Attest::ordner_sperren( $base );
        return $base;
    }

    /** Für Prüfungen: die Prüfung auf einen echten Upload umgehen. */
    public static $pruefe_upload = null;

    /**
     * Ein Dokument ablegen. Je Typ genau eines — ein zweites ersetzt das
     * erste, samt Datei.
     */
    public static function dokument_ablegen( $wettkampf_id, $typ, array $datei ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $wettkampf_id = (int) $wettkampf_id;
        $typ = sanitize_key( $typ );
        if ( ! self::darf_schreiben( false ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! isset( self::DOKUMENTE[ $typ ] ) ) return 'Diese Art von Dokument gibt es nicht.';
        if ( ! LSV07S_Tabellen::da( 'wk_dokument' ) ) return 'Die Tabelle fehlt.';
        if ( ! self::in_reichweite( $wettkampf_id ) ) {
            return 'Dieser Wettkampf gehört nicht zu Ihrem Bereich.';
        }

        $tmp = (string) ( $datei['tmp_name'] ?? '' );
        if ( (int) ( $datei['error'] ?? 1 ) !== 0 || $tmp === '' ) {
            return 'Die Datei ist nicht angekommen.';
        }
        $da = self::$pruefe_upload ? ( self::$pruefe_upload )( $tmp ) : is_uploaded_file( $tmp );
        if ( ! $da ) return 'Die Datei ist nicht angekommen.';

        $groesse = (int) ( $datei['size'] ?? 0 );
        if ( $groesse <= 0 ) return 'Die Datei ist leer.';
        if ( $groesse > LSV07S_Attest::MAX_BYTES ) return 'Die Datei ist größer als 10 MB.';

        /* Auch hier entscheidet der Inhalt. Ein Meldeergebnis ist ein
           PDF; ein PHP-Skript mit der Endung .pdf ist keines. */
        $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
        if ( $mime !== 'application/pdf' ) {
            return 'Erlaubt ist nur PDF. Diese Datei ist '
                 . ( $mime ?: 'von unbekannter Art' ) . '.';
        }

        $ordner = self::ordner();
        $name   = 'wk-' . $wettkampf_id . '-' . $typ . '-'
                . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.pdf';
        $ziel   = $ordner . '/' . $name;
        $ok = self::$pruefe_upload ? @copy( $tmp, $ziel ) : @move_uploaded_file( $tmp, $ziel );
        if ( ! $ok ) return 'Die Datei konnte nicht gespeichert werden.';
        @chmod( $ziel, 0640 );

        $t = LSV07S_Tabellen::t( 'wk_dokument' );
        $alt = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE wettkampf_id = %d AND typ = %s LIMIT 1",
            $wettkampf_id, $typ ), ARRAY_A );
        if ( $alt ) {
            $altdatei = $ordner . '/' . basename( (string) $alt['gespeichert_als'] );
            if ( is_file( $altdatei ) ) @unlink( $altdatei );
            $wpdb->delete( $t, [ 'id' => (int) $alt['id'] ], [ '%d' ] );
        }

        $wpdb->insert( $t, [
            'wettkampf_id' => $wettkampf_id,
            'typ' => $typ,
            'dateiname' => mb_substr( sanitize_file_name(
                             (string) ( $datei['name'] ?? $name ) ), 0, 255 ),
            'gespeichert_als' => $name,
            'mime_type' => $mime,
            'groesse' => $groesse,
            'hochgeladen_von' => get_current_user_id(),
            'hochgeladen_am' => LSV07S_Datum::jetzt(),
        ], [ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ] );

        LSV07S_Log::schreibe( 'wettkampf.dokument', [
            'bereich' => $sparte, 'ziel_typ' => 'wettkampf', 'ziel_id' => $wettkampf_id,
            'details' => self::DOKUMENTE[ $typ ] . ', ' . round( $groesse / 1024 ) . ' KB'
                       . ( $alt ? ' (ersetzt)' : '' ),
        ] );
        return [ 'id' => (int) $wpdb->insert_id,
                 'message' => self::DOKUMENTE[ $typ ] . ' ist abgelegt.'
                            . ( $alt ? ' Die vorige Fassung wurde ersetzt.' : '' ) ];
    }

    /**
     * Den Pfad eines Dokuments holen.
     *
     * Ein freigegebener Wettkampf ist öffentlich, also auch seine
     * Dokumente — das ist der Zweck der Freigabe. Solange er NICHT
     * freigegeben ist, braucht es Anmeldung und Leserecht.
     */
    public static function dokument_pfad( $id ) {
        global $wpdb;
        $t = LSV07S_Tabellen::t( 'wk_dokument' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            (int) $id ), ARRAY_A );
        if ( ! $z ) return 'Dieses Dokument gibt es nicht.';

        $frei = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT freigegeben FROM ' . LSV07S_Tabellen::t( 'wettkampf' )
            . ' WHERE id = %d LIMIT 1', (int) $z['wettkampf_id'] ) );
        if ( ! $frei && ! self::darf_lesen() ) {
            return 'Dieser Wettkampf ist noch nicht freigegeben.';
        }

        $pfad = self::ordner() . '/' . basename( (string) $z['gespeichert_als'] );
        if ( ! is_file( $pfad ) ) return 'Die Datei liegt nicht mehr im Ordner.';
        return [ 'pfad' => $pfad, 'name' => (string) $z['dateiname'],
                 'typ' => (string) $z['mime_type'] ];
    }

    /** Ein Dokument löschen. */
    public static function dokument_loeschen( $id, $still = false ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! $still && ! self::darf_schreiben( false ) ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        $t = LSV07S_Tabellen::t( 'wk_dokument' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            (int) $id ), ARRAY_A );
        if ( ! $z ) return 'Dieses Dokument gibt es nicht.';
        if ( ! $still && ! self::in_reichweite( (int) $z['wettkampf_id'] ) ) {
            return 'Dieser Wettkampf gehört nicht zu Ihrem Bereich.';
        }

        $pfad = self::ordner() . '/' . basename( (string) $z['gespeichert_als'] );
        if ( is_file( $pfad ) ) @unlink( $pfad );
        $wpdb->delete( $t, [ 'id' => (int) $id ], [ '%d' ] );

        if ( ! $still ) {
            LSV07S_Log::schreibe( 'wettkampf.dokument_weg', [
                'bereich' => $sparte, 'ziel_typ' => 'wettkampf',
                'ziel_id' => (int) $z['wettkampf_id'],
                'details' => self::DOKUMENTE[ $z['typ'] ] ?? (string) $z['typ'],
            ] );

            /* Die Freigabe stand auf der Ausschreibung. Ist sie weg und
               der Haken nicht gesetzt, ist die Grundlage entfallen. */
            if ( (string) $z['typ'] === 'ausschreibung' ) {
                $wk = $wpdb->get_row( $wpdb->prepare(
                    'SELECT * FROM ' . LSV07S_Tabellen::t( 'wettkampf' )
                    . ' WHERE id = %d LIMIT 1', (int) $z['wettkampf_id'] ), ARRAY_A );
                if ( $wk && (int) ( $wk['freigegeben'] ?? 0 ) === 1
                     && empty( $wk['ohne_ausschreibung'] ) ) {
                    $wpdb->update( LSV07S_Tabellen::t( 'wettkampf' ),
                        [ 'freigegeben' => 0, 'freigegeben_von' => null,
                          'freigegeben_am' => null ],
                        [ 'id' => (int) $wk['id'] ], [ '%d', '%d', '%s' ], [ '%d' ] );
                    LSV07S_Log::schreibe( 'wettkampf.freigabe_zurueck', [
                        'bereich' => $sparte, 'ziel_typ' => 'wettkampf',
                        'ziel_id' => (int) $wk['id'], 'ziel_name' => (string) $wk['name'],
                        'details' => 'Ausschreibung gelöscht',
                    ] );
                    return [ 'message' => 'Gelöscht. Die Freigabe wurde zurückgezogen — '
                                        . 'ohne Ausschreibung ist ihre Grundlage entfallen.' ];
                }
            }
        }
        return true;
    }

    // ── Anwesenheit am Wettkampftag ──────────────────────────────────────

    /**
     * Die Anwesenheitsliste eines Tages für eine Mannschaft.
     *
     * Die Liste wird beim ersten Öffnen angelegt — aber nur, wenn das
     * Konto sie auch erfassen darf. Im internen Bereich legt schon das
     * Lesen eine Liste an; dann steht in der Datenbank eine leere
     * Session, nur weil jemand hingesehen hat.
     */
    public static function anw_tag( $tag_id, $mannschaft_id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $tag_id = (int) $tag_id;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';

        $tag = $wpdb->get_row( $wpdb->prepare(
            'SELECT t.*, w.name AS wk_name, w.ort AS wk_ort, w.pauschale, w.id AS wk_id'
            . ' FROM ' . LSV07S_Tabellen::t( 'wk_tage' ) . ' t'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wettkampf' ) . ' w ON w.id = t.wettkampf_id'
            . ' WHERE t.id = %d LIMIT 1', $tag_id ), ARRAY_A );
        if ( ! $tag ) return 'Diesen Wettkampftag gibt es nicht.';

        $beteiligt = self::mannschaft_ids( (int) $tag['wk_id'] );
        if ( ! in_array( $mannschaft_id, $beteiligt, true ) ) {
            return 'Diese Mannschaft ist an dem Wettkampf nicht beteiligt.';
        }
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $darf = self::darf_anwesenheit()
                && LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id )
                && (string) $tag['datum'] <= LSV07S_Datum::heute();

        $anw = self::session( $tag_id, $mannschaft_id, $darf );
        $eintraege = [];
        if ( $anw ) {
            foreach ( (array) $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM ' . LSV07S_Tabellen::t( 'wk_anw_eintr' )
                . ' WHERE wk_anwesenheit_id = %d', (int) $anw['id'] ), ARRAY_A ) as $e ) {
                $eintraege[ $e['teilnehmer_typ'] . ':' . (int) $e['teilnehmer_id'] ] = $e;
            }
        }

        $sportler = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ) as $s ) {
            $e = $eintraege[ 'schwimmer:' . $s['id'] ] ?? null;
            $sportler[] = [
                'id' => $s['id'], 'name' => $s['name'], 'jahrgang' => $s['jahrgang'],
                'status' => $e ? (string) $e['status'] : 'abwesend',
                'status_name' => self::ZUSTAENDE[ $e['status'] ?? 'abwesend' ] ?? 'Abwesend',
                'erfasst' => (bool) $e,
            ];
        }

        $trainer = [];
        foreach ( self::trainer_der_mannschaft( $mannschaft_id ) as $tr ) {
            $e = $eintraege[ 'trainer:' . $tr['id'] ] ?? null;
            $abschnitte = $e && (int) $e['abschnitte'] > 0
                ? (int) $e['abschnitte'] : (int) $tag['abschnitte_plan'];
            $trainer[] = [
                'id' => (int) $tr['id'], 'name' => (string) $tr['name'],
                'status' => $e ? (string) $e['status'] : 'abwesend',
                'status_name' => self::ZUSTAENDE[ $e['status'] ?? 'abwesend' ] ?? 'Abwesend',
                'abschnitte' => $abschnitte,
                'betrag' => round( $abschnitte * (float) $tag['pauschale'], 2 ),
                'erfasst' => (bool) $e,
            ];
        }

        return [
            'anwesenheit_id' => $anw ? (int) $anw['id'] : 0,
            'tag' => [
                'id' => $tag_id,
                'wettkampf_id' => (int) $tag['wk_id'],
                'wettkampf' => (string) $tag['wk_name'],
                'ort' => (string) $tag['wk_ort'],
                'datum' => (string) $tag['datum'],
                'datum_de' => LSV07S_Datum::de( $tag['datum'] ),
                'abschnitte_plan' => (int) $tag['abschnitte_plan'],
                'pauschale' => (float) $tag['pauschale'],
                'vorbei' => (string) $tag['datum'] <= LSV07S_Datum::heute(),
            ],
            'mannschaft' => LSV07S_Mannschaft::holen( $sparte, $mannschaft_id ),
            'sportler' => $sportler,
            'trainer' => $trainer,
            'zustaende' => self::ZUSTAENDE,
            'darf_aendern' => $darf,
        ];
    }

    /** Die Liste holen; anlegen nur, wenn erfasst werden darf. */
    private static function session( $tag_id, $mannschaft_id, $anlegen ) {
        global $wpdb;
        $t = LSV07S_Tabellen::t( 'wk_anw' );
        $anw = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE wettkampf_tag_id = %d AND mannschaft_id = %d LIMIT 1",
            (int) $tag_id, (int) $mannschaft_id ), ARRAY_A );
        if ( $anw || ! $anlegen ) return $anw ?: null;
        $wpdb->insert( $t, [
            'wettkampf_tag_id' => (int) $tag_id,
            'mannschaft_id' => (int) $mannschaft_id,
            'erstellt_von' => get_current_user_id(),
            'erstellt_am' => LSV07S_Datum::jetzt(),
        ], [ '%d', '%d', '%d', '%s' ] );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d",
            (int) $wpdb->insert_id ), ARRAY_A );
    }

    /** Die aktiven Trainer einer Mannschaft. */
    private static function trainer_der_mannschaft( $mannschaft_id ) {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT tr.id, tr.name FROM ' . LSV07S_Tabellen::t( 'trainer' ) . ' tr'
            . ' JOIN ' . LSV07S_Tabellen::t( 'trainer_mann' ) . ' tm ON tm.trainer_id = tr.id'
            . ' WHERE tm.mannschaft_id = %d AND tr.aktiv = 1 ORDER BY tr.name ASC',
            (int) $mannschaft_id ), ARRAY_A );
    }

    /**
     * Einen Zustand setzen.
     *
     * Bei einem Trainer zieht die Abrechnung nach — aber nur, solange sie
     * offen ist.
     */
    public static function anw_setzen( $tag_id, $mannschaft_id, $typ, $teilnehmer_id, $status ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $tag_id = (int) $tag_id;
        $mannschaft_id = (int) $mannschaft_id;
        $teilnehmer_id = (int) $teilnehmer_id;
        $typ = sanitize_key( $typ );
        $status = sanitize_key( $status );

        if ( ! self::darf_anwesenheit() ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! in_array( $typ, [ 'schwimmer', 'trainer' ], true ) ) {
            return 'Diese Art von Teilnehmer gibt es nicht.';
        }
        if ( ! isset( self::ZUSTAENDE[ $status ] ) ) {
            return 'Diesen Zustand gibt es nicht.';
        }
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $tag = $wpdb->get_row( $wpdb->prepare(
            'SELECT t.*, w.name AS wk_name, w.pauschale FROM '
            . LSV07S_Tabellen::t( 'wk_tage' ) . ' t JOIN '
            . LSV07S_Tabellen::t( 'wettkampf' ) . ' w ON w.id = t.wettkampf_id'
            . ' WHERE t.id = %d LIMIT 1', $tag_id ), ARRAY_A );
        if ( ! $tag ) return 'Diesen Wettkampftag gibt es nicht.';
        if ( (string) $tag['datum'] > LSV07S_Datum::heute() ) {
            return 'Dieser Wettkampftag liegt in der Zukunft — er hat noch nicht '
                 . 'stattgefunden.';
        }
        if ( ! in_array( $mannschaft_id, self::mannschaft_ids( (int) $tag['wettkampf_id'] ),
                         true ) ) {
            return 'Diese Mannschaft ist an dem Wettkampf nicht beteiligt.';
        }

        /* Wer eingetragen wird, muss dazugehören — sonst setzt eine
           getippte Nummer den Zustand einer fremden Person. */
        if ( $typ === 'schwimmer' ) {
            $ids = array_column( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ), 'id' );
            if ( ! in_array( $teilnehmer_id, array_map( 'intval', $ids ), true ) ) {
                return 'Diese Person gehört nicht zu dieser Mannschaft.';
            }
        } else {
            $ids = array_map( 'intval',
                array_column( self::trainer_der_mannschaft( $mannschaft_id ), 'id' ) );
            if ( ! in_array( $teilnehmer_id, $ids, true ) ) {
                return 'Dieser Trainer gehört nicht zu dieser Mannschaft.';
            }
        }

        $anw = self::session( $tag_id, $mannschaft_id, true );
        if ( ! $anw ) return 'Die Anwesenheitsliste konnte nicht angelegt werden.';

        $te = LSV07S_Tabellen::t( 'wk_anw_eintr' );
        $da = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $te WHERE wk_anwesenheit_id = %d AND teilnehmer_typ = %s"
            . ' AND teilnehmer_id = %d LIMIT 1',
            (int) $anw['id'], $typ, $teilnehmer_id ), ARRAY_A );
        if ( $da ) {
            $wpdb->update( $te, [ 'status' => $status ], [ 'id' => (int) $da['id'] ],
                [ '%s' ], [ '%d' ] );
        } else {
            $wpdb->insert( $te, [
                'wk_anwesenheit_id' => (int) $anw['id'],
                'teilnehmer_typ' => $typ,
                'teilnehmer_id' => $teilnehmer_id,
                'status' => $status,
                'abschnitte' => 0,
            ], [ '%d', '%s', '%d', '%s', '%d' ] );
        }

        $hinweis = '';
        if ( $typ === 'trainer' ) {
            $hinweis = $status === 'anwesend'
                ? self::abrechnung_nachziehen( $anw, $tag, $teilnehmer_id )
                : self::abrechnung_entfernen( $anw, $tag, $teilnehmer_id );
        }

        LSV07S_Log::schreibe( 'wettkampf.anwesenheit', [
            'bereich' => $sparte, 'ziel_typ' => $typ === 'trainer' ? 'trainer' : 'person',
            'ziel_id' => $teilnehmer_id,
            'details' => (string) $tag['wk_name'] . ', ' . LSV07S_Datum::de( $tag['datum'] )
                       . ': ' . self::ZUSTAENDE[ $status ],
        ] );

        return [ 'message' => 'Gespeichert.' . ( $hinweis ? ' ' . $hinweis : '' ) ];
    }

    /** Die Abschnitte eines Trainers an einem Tag setzen. */
    public static function anw_abschnitte( $tag_id, $mannschaft_id, $trainer_id, $abschnitte ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $tag_id = (int) $tag_id;
        $mannschaft_id = (int) $mannschaft_id;
        $trainer_id = (int) $trainer_id;
        $abschnitte = (int) $abschnitte;

        if ( ! self::darf_anwesenheit() ) {
            return 'Sie haben keine Berechtigung für diese Aktion.';
        }
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( $abschnitte < 1 || $abschnitte > 20 ) {
            return 'Die Zahl der Abschnitte liegt zwischen 1 und 20.';
        }

        $tag = $wpdb->get_row( $wpdb->prepare(
            'SELECT t.*, w.name AS wk_name, w.pauschale FROM '
            . LSV07S_Tabellen::t( 'wk_tage' ) . ' t JOIN '
            . LSV07S_Tabellen::t( 'wettkampf' ) . ' w ON w.id = t.wettkampf_id'
            . ' WHERE t.id = %d LIMIT 1', $tag_id ), ARRAY_A );
        if ( ! $tag ) return 'Diesen Wettkampftag gibt es nicht.';

        $anw = self::session( $tag_id, $mannschaft_id, false );
        if ( ! $anw ) return 'Für diesen Tag ist noch keine Anwesenheit erfasst.';

        $te = LSV07S_Tabellen::t( 'wk_anw_eintr' );
        $e = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $te WHERE wk_anwesenheit_id = %d AND teilnehmer_typ = 'trainer'"
            . ' AND teilnehmer_id = %d LIMIT 1', (int) $anw['id'], $trainer_id ), ARRAY_A );
        if ( ! $e ) return 'Dieser Trainer ist an diesem Tag nicht erfasst.';

        $wpdb->update( $te, [ 'abschnitte' => $abschnitte ], [ 'id' => (int) $e['id'] ],
            [ '%d' ], [ '%d' ] );

        $hinweis = '';
        if ( (string) $e['status'] === 'anwesend' ) {
            $hinweis = self::abrechnung_nachziehen( $anw, $tag, $trainer_id );
        }

        LSV07S_Log::schreibe( 'wettkampf.abschnitte', [
            'bereich' => $sparte, 'ziel_typ' => 'trainer', 'ziel_id' => $trainer_id,
            'details' => (string) $tag['wk_name'] . ', ' . LSV07S_Datum::de( $tag['datum'] )
                       . ': ' . $abschnitte . ' Abschnitt(e)',
        ] );
        return [ 'message' => 'Gespeichert.' . ( $hinweis ? ' ' . $hinweis : '' ) ];
    }

    /**
     * Die Abrechnungszeile zu einem Wettkampftag anlegen oder nachziehen.
     *
     * Nur in eine offene Abrechnung. Ist sie eingereicht, genehmigt oder
     * bezahlt, bleibt sie, wie sie ist — und die Antwort sagt das. Im
     * internen Bereich wird sie still verändert, und der Betrag einer
     * genehmigten Abrechnung stimmt hinterher nicht mehr.
     */
    private static function abrechnung_nachziehen( $anw, $tag, $trainer_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'abrechnung' ) || ! LSV07S_Tabellen::da( 'abr_wk' ) ) {
            return '';
        }
        $te = LSV07S_Tabellen::t( 'wk_anw_eintr' );
        $abschnitte = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT abschnitte FROM $te WHERE wk_anwesenheit_id = %d"
            . " AND teilnehmer_typ = 'trainer' AND teilnehmer_id = %d LIMIT 1",
            (int) $anw['id'], (int) $trainer_id ) );
        if ( $abschnitte < 1 ) $abschnitte = max( 1, (int) $tag['abschnitte_plan'] );

        $pauschale = (float) ( $tag['pauschale'] ?? 0 );
        if ( $pauschale <= 0 ) {
            $pauschale = (float) LSV07S_Tabellen::config( 'wk_pauschale', 25 );
        }
        $betrag = round( $abschnitte * $pauschale, 2 );

        $quartal = LSV07S_Datum::quartal( $tag['datum'] );
        $jahr    = LSV07S_Datum::jahr( $tag['datum'] );
        $ta = LSV07S_Tabellen::t( 'abrechnung' );

        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $ta WHERE trainer_id = %d AND bereich = 'schwimmen'"
            . ' AND quartal = %s AND jahr = %d LIMIT 1',
            (int) $trainer_id, $quartal, $jahr ), ARRAY_A );
        if ( ! $abr ) {
            $wpdb->insert( $ta, [
                'trainer_id' => (int) $trainer_id, 'bereich' => 'schwimmen',
                'quartal' => $quartal, 'jahr' => $jahr, 'abr_status' => 'entwurf',
            ], [ '%d', '%s', '%s', '%d', '%s' ] );
            $abr = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ta WHERE id = %d",
                (int) $wpdb->insert_id ), ARRAY_A );
        }
        if ( ! $abr ) return '';

        $tw = LSV07S_Tabellen::t( 'abr_wk' );
        $da = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tw WHERE wettkampf_tag_id = %d AND abrechnung_id = %d"
            . " AND quelle = 'anwesenheit' LIMIT 1",
            (int) $anw['wettkampf_tag_id'], (int) $abr['id'] ), ARRAY_A );

        if ( ! in_array( (string) $abr['abr_status'], self::OFFEN, true ) ) {
            return 'Die Abrechnung ' . $quartal . '/' . $jahr . ' ist '
                 . 'nicht mehr offen; der Wettkampf wurde dort '
                 . ( $da ? 'nicht geändert' : 'nicht eingetragen' ) . '.';
        }

        $zeile = [
            'datum' => (string) $tag['datum'],
            'name' => (string) $tag['wk_name'],
            'abschnitte' => $abschnitte,
            'pauschale' => $pauschale,
            'betrag' => $betrag,
        ];
        if ( $da ) {
            /* Eine von Hand gelöschte Zeile bleibt gelöscht: Der Trainer
               hat sie bewusst herausgenommen. */
            if ( ! empty( $da['manuell_geloescht'] ) ) {
                return 'Die Zeile in der Abrechnung ' . $quartal . '/' . $jahr
                     . ' wurde dort von Hand entfernt und bleibt entfernt.';
            }
            $wpdb->update( $tw, $zeile, [ 'id' => (int) $da['id'] ],
                [ '%s', '%s', '%d', '%f', '%f' ], [ '%d' ] );
            return '';
        }
        $zeile['abrechnung_id'] = (int) $abr['id'];
        $zeile['wettkampf_tag_id'] = (int) $anw['wettkampf_tag_id'];
        $zeile['mannschaft_id'] = (int) $anw['mannschaft_id'];
        $zeile['quelle'] = 'anwesenheit';
        $wpdb->insert( $tw, $zeile,
            [ '%s', '%s', '%d', '%f', '%f', '%d', '%d', '%d', '%s' ] );
        return 'In der Abrechnung ' . $quartal . '/' . $jahr . ' sind '
             . number_format( $betrag, 2, ',', '.' ) . ' € eingetragen.';
    }

    /** Die Abrechnungszeile wieder entfernen — nur aus einer offenen Abrechnung. */
    private static function abrechnung_entfernen( $anw, $tag, $trainer_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'abrechnung' ) || ! LSV07S_Tabellen::da( 'abr_wk' ) ) {
            return '';
        }
        $tw = LSV07S_Tabellen::t( 'abr_wk' );
        $ta = LSV07S_Tabellen::t( 'abrechnung' );
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT aw.id, a.abr_status, a.quartal, a.jahr FROM $tw aw"
            . " JOIN $ta a ON a.id = aw.abrechnung_id"
            . " WHERE aw.wettkampf_tag_id = %d AND aw.quelle = 'anwesenheit'"
            . ' AND a.trainer_id = %d',
            (int) $anw['wettkampf_tag_id'], (int) $trainer_id ), ARRAY_A );

        $gehalten = [];
        foreach ( $zeilen as $z ) {
            if ( ! in_array( (string) $z['abr_status'], self::OFFEN, true ) ) {
                $gehalten[] = $z['quartal'] . '/' . $z['jahr'];
                continue;
            }
            $wpdb->delete( $tw, [ 'id' => (int) $z['id'] ], [ '%d' ] );
        }
        if ( $gehalten !== [] ) {
            return 'In der Abrechnung ' . implode( ', ', array_unique( $gehalten ) )
                 . ' bleibt der Wettkampf stehen — sie ist nicht mehr offen. '
                 . 'Eine Korrektur gehört dort hinein, nicht hierher.';
        }
        return '';
    }

    // ── Statistik und offene Tage ────────────────────────────────────────

    /**
     * Wer war bei wie vielen Wettkampftagen dabei?
     *
     * Gezählt werden nur Tage, an denen die Mannschaft der Person
     * überhaupt beteiligt war — sonst sieht ein Schwimmer, der im Mai
     * dazukam, schlecht aus, weil er im März nicht da war.
     */
    public static function statistik( $mannschaft_id, $von = '', $bis = '' ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf_lesen() ) return [];
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) return [];

        $a = LSV07S_Datum::rein( $von );
        $b = LSV07S_Datum::rein( $bis );
        if ( $a === null ) {
            $s = LSV07S_Datum::saison_aktiv();
            $a = $s ? $s['start_datum'] : date( 'Y-01-01', strtotime( LSV07S_Datum::heute() ) );
        }
        if ( $b === null ) $b = LSV07S_Datum::heute();
        if ( $b < $a ) [ $a, $b ] = [ $b, $a ];

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT e.teilnehmer_id AS id, e.status, COUNT(*) AS zahl'
            . ' FROM ' . LSV07S_Tabellen::t( 'wk_anw_eintr' ) . ' e'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wk_anw' ) . ' a ON a.id = e.wk_anwesenheit_id'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wk_tage' ) . ' t ON t.id = a.wettkampf_tag_id'
            . " WHERE a.mannschaft_id = %d AND e.teilnehmer_typ = 'schwimmer'"
            . ' AND t.datum BETWEEN %s AND %s'
            . ' GROUP BY e.teilnehmer_id, e.status',
            $mannschaft_id, $a, $b ), ARRAY_A );

        $nach_id = [];
        foreach ( $zeilen as $z ) {
            $nach_id[ (int) $z['id'] ][ (string) $z['status'] ] = (int) $z['zahl'];
        }

        $raus = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ) as $s ) {
            $z = $nach_id[ (int) $s['id'] ] ?? [];
            $anwesend = (int) ( $z['anwesend'] ?? 0 );
            $gesamt = array_sum( $z );
            $raus[] = [
                'id' => $s['id'], 'name' => $s['name'],
                'anwesend' => $anwesend,
                'abwesend' => (int) ( $z['abwesend'] ?? 0 ),
                'entschuldigt' => (int) ( $z['entschuldigt'] ?? 0 ),
                'gesamt' => $gesamt,
                'quote' => $gesamt > 0 ? (int) round( $anwesend / $gesamt * 100 ) : 0,
            ];
        }
        return [ 'von' => $a, 'bis' => $b, 'zeilen' => $raus ];
    }

    /**
     * Wettkampftage, die ein Trainer noch von Hand in seine Abrechnung
     * holen kann: Er war dabei, aber es steht keine Zeile dazu.
     */
    public static function offene_tage( $trainer_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'abr_wk' ) ) return [];
        $trainer_id = (int) $trainer_id;
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT t.id, t.datum, t.abschnitte_plan, w.name, w.pauschale, a.mannschaft_id'
            . ' FROM ' . LSV07S_Tabellen::t( 'wk_anw_eintr' ) . ' e'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wk_anw' ) . ' a ON a.id = e.wk_anwesenheit_id'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wk_tage' ) . ' t ON t.id = a.wettkampf_tag_id'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wettkampf' ) . ' w ON w.id = t.wettkampf_id'
            . " WHERE e.teilnehmer_typ = 'trainer' AND e.teilnehmer_id = %d"
            . " AND e.status = 'anwesend'"
            . ' AND NOT EXISTS ( SELECT 1 FROM ' . LSV07S_Tabellen::t( 'abr_wk' ) . ' aw'
            . '   JOIN ' . LSV07S_Tabellen::t( 'abrechnung' ) . ' ab'
            . '     ON ab.id = aw.abrechnung_id'
            . '   WHERE aw.wettkampf_tag_id = t.id AND ab.trainer_id = %d )'
            . ' ORDER BY t.datum DESC',
            $trainer_id, $trainer_id ), ARRAY_A );

        return array_map( fn( $z ) => [
            'tag_id' => (int) $z['id'],
            'datum' => (string) $z['datum'],
            'datum_de' => LSV07S_Datum::de( $z['datum'] ),
            'name' => (string) $z['name'],
            'abschnitte' => (int) $z['abschnitte_plan'],
            'pauschale' => (float) $z['pauschale'],
            'betrag' => round( (int) $z['abschnitte_plan'] * (float) $z['pauschale'], 2 ),
        ], $zeilen );
    }
}
