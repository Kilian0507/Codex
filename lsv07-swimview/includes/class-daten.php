<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Was eine angemeldete Person zu sehen bekommt.
 *
 * Jede Methode hier prüft selbst, ob der Bereich für diese Person
 * freigegeben ist, und schränkt dann auf die Mannschaften ein, für die das
 * gilt. Die Prüfung steht absichtlich HIER und nicht nur im Endpunkt: So
 * kann weder ein neuer Endpunkt noch ein PDF-Erzeuger sie versehentlich
 * überspringen. Wer etwas nicht sehen darf, bekommt eine leere Liste — nicht
 * eine halbe.
 *
 * Fremde Daten kommen nirgends vor. Vermerke und Aufgaben anderer Kinder
 * werden nicht gefiltert dargestellt, sondern gar nicht erst geladen.
 */
class LSV07S_Daten {

    /** Die sechs Zustände des Mannschaftsbuchs, wie dort benannt. */
    const ZUSTAENDE = [
        'anwesend'       => 'Anwesend',
        'abwesend'       => 'Abwesend',
        'krank'          => 'Krank',
        'entschuldigt'   => 'Entschuldigt',
        'beurlaubt'      => 'Beurlaubt',
        'unentschuldigt' => 'Unentschuldigt',
    ];

    const VERMERK_ARTEN = [
        'ziel'      => 'Trainingsziel',
        'hinweis'   => 'Hinweis',
        'sonstiges' => 'Sonstiges',
    ];

    const AUFGABE_ARTEN = [
        'material'  => 'Sachen nicht mitgebracht',
        'bank'      => 'Auf der Bank gesessen',
        'sonstiges' => 'Sonstiger Vermerk',
    ];

    /** So lange nach dem letzten Wettkampftag bleibt er noch stehen. */
    const WK_NACHLAUF_TAGE = 10;

    /**
     * Die Strecken in der Reihenfolge, in der der Verein sie führt, mit
     * ausgeschriebenem Namen.
     *
     * Beides ist nötig: „100R" sagt einem Kind nichts, und nach dem Code
     * sortiert stünde 100 m vor 50 m und 1500 m ganz oben. Die Liste ist
     * dieselbe wie im internen Bereich — der Prüfstand vergleicht sie mit
     * dessen Quelltext, damit sie nicht auseinanderlaufen.
     */
    const STRECKEN = [
        '50F'   => '50 m Freistil',
        '100F'  => '100 m Freistil',
        '200F'  => '200 m Freistil',
        '400F'  => '400 m Freistil',
        '800F'  => '800 m Freistil',
        '1500F' => '1500 m Freistil',
        '50B'   => '50 m Brust',
        '100B'  => '100 m Brust',
        '200B'  => '200 m Brust',
        '50R'   => '50 m Rücken',
        '100R'  => '100 m Rücken',
        '200R'  => '200 m Rücken',
        '50S'   => '50 m Schmetterling',
        '100S'  => '100 m Schmetterling',
        '200S'  => '200 m Schmetterling',
        '100L'  => '100 m Lagen',
        '200L'  => '200 m Lagen',
        '400L'  => '400 m Lagen',
    ];

    /** Der ausgeschriebene Name einer Strecke, sonst ihr Code. */
    public static function strecke_name( $code ) {
        return self::STRECKEN[ $code ] ?? (string) $code;
    }

    /**
     * Zeilen mit einer Spalte `strecke` in die Reihenfolge der Liste
     * bringen. Was nicht darin steht, hängt hinten an — eine unbekannte
     * Strecke soll nicht verschwinden, nur weil sie neu ist.
     */
    private static function nach_strecke( array $zeilen, $feld = 'strecke' ) {
        $rang = array_flip( array_keys( self::STRECKEN ) );
        usort( $zeilen, function ( $a, $b ) use ( $rang, $feld ) {
            $ra = $rang[ $a[ $feld ] ] ?? 999;
            $rb = $rang[ $b[ $feld ] ] ?? 999;
            if ( $ra !== $rb ) return $ra <=> $rb;
            return strcmp( (string) $a[ $feld ], (string) $b[ $feld ] );
        } );
        return $zeilen;
    }

    // ── Startdaten ──────────────────────────────────────────────────────

    /**
     * Was die Oberfläche beim Start braucht: Begrüßung, Mannschaften, das
     * Menü der freigegebenen Bereiche und zwei Zahlen fürs Dashboard.
     *
     * Steht hier und nicht im Endpunkt, weil es ZWEI Wege hierher gibt: die
     * fertig ausgelieferte Seite (damit beim ersten Bild schon etwas steht)
     * und die Abfrage nach dem Anmelden. Zwei Zusammenstellungen desselben
     * Menüs würden früher oder später verschiedene Bereiche zeigen.
     */
    public static function startdaten( array $s ) {
        $sid   = (int) $s['id'];
        $karte = LSV07S_Freigabe::fuer_schwimmer( $sid );

        $bereiche = [];
        foreach ( LSV07S_Freigabe::BEREICHE as $key => $b ) {
            if ( empty( $karte[ $key ] ) ) continue;
            /* Für die Kachel der Kinder, nicht der Text fürs Trainerportal. */
            $bereiche[] = [ 'key' => $key, 'name' => $b['name'],
                            'text' => $b['kachel'], 'icon' => $b['icon'] ];
        }

        $namen = LSV07S_Intern::mannschaft_namen(
            LSV07S_Intern::mannschaften_von_schwimmer( $sid ) );

        /* Zwei Zahlen fürs Dashboard — aber nur, wenn der Bereich auch frei
           ist. Eine Zahl über gesperrte Daten wäre schon eine Auskunft. */
        $offen = 0;
        if ( ! empty( $karte['aufgaben'] ) ) {
            $a = self::aufgaben( $sid );
            $offen = (int) $a['offen'];
        }
        $ungelesen = 0;
        if ( ! empty( $karte['nachrichten'] ) ) {
            $n = self::nachrichten( $sid );
            $ungelesen = (int) $n['ungelesen'];
        }

        return [
            'angemeldet'     => true,
            'vorname'        => (string) $s['first_name'],
            'nachname'       => (string) $s['last_name'],
            'jahrgang'       => substr( (string) $s['birth_date'], 0, 4 ),
            'mannschaften'   => array_values( $namen ),
            'bereiche'       => $bereiche,
            'aufgaben_offen' => $offen,
            'ungelesen'      => $ungelesen,
        ];
    }

    // ── Zeitraum ────────────────────────────────────────────────────────

    /**
     * Ab wann wird gezeigt. Vorrang hat der Start der laufenden Saison —
     * das ist der Zeitraum, über den im Verein geredet wird. Gibt es keine
     * Saison, sind es zwölf Monate.
     */
    public static function zeitraum_start() {
        $saison = LSV07S_Intern::saison_aktiv();
        if ( $saison && ! empty( $saison['start_datum'] ) ) {
            return substr( (string) $saison['start_datum'], 0, 10 );
        }
        return gmdate( 'Y-m-d', strtotime( '-12 months' ) );
    }

    private static function platzhalter( array $ids ) {
        return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    }

    // ── Anwesenheiten ───────────────────────────────────────────────────

    /**
     * Die eigenen Anwesenheiten aus dem Mannschaftsbuch, neueste zuerst,
     * samt Verspätung. Dazu eine Zählung je Zustand.
     */
    public static function anwesenheit( $schwimmer_id, $von = '', $bis = '' ) {
        global $wpdb;
        $leer = [ 'tage' => [], 'zahl' => [], 'quote' => null, 'von' => '', 'bis' => '' ];

        $mannschaften = LSV07S_Freigabe::mannschaften_fuer( $schwimmer_id, 'anwesenheit' );
        if ( ! $mannschaften ) return $leer;
        if ( ! LSV07S_Intern::alle_da( [ 'lsv07i_mb_tag', 'lsv07i_mb_anwesenheit' ] ) ) return $leer;

        $p   = $wpdb->prefix;
        $von = $von ?: self::zeitraum_start();
        $bis = $bis ?: gmdate( 'Y-m-d' );
        $pl  = self::platzhalter( $mannschaften );

        /* Verspätungsspalten gibt es erst ab 8.27.0 des internen Bereichs. */
        $hat_spaet = LSV07S_Intern::spalte_da( 'lsv07i_mb_anwesenheit', 'verspaetung' );
        $spalten   = $hat_spaet
            ? 'a.verspaetung, a.verspaetung_grund'
            : "0 AS verspaetung, '' AS verspaetung_grund";

        $args = array_merge( $mannschaften, [ $von, $bis, (int) $schwimmer_id ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.datum, t.mannschaft_id, a.status, $spalten
               FROM {$p}lsv07i_mb_tag t
               JOIN {$p}lsv07i_mb_anwesenheit a ON a.tag_id = t.id
              WHERE t.mannschaft_id IN ($pl)
                AND t.datum BETWEEN %s AND %s
                AND a.schwimmer_id = %d
           ORDER BY t.datum DESC",
            ...$args
        ), ARRAY_A );

        $namen = LSV07S_Intern::mannschaft_namen( $mannschaften );
        $zahl  = [];
        foreach ( array_keys( self::ZUSTAENDE ) as $z ) $zahl[ $z ] = 0;

        $tage = [];
        foreach ( (array) $rows as $r ) {
            $st = isset( self::ZUSTAENDE[ $r['status'] ] ) ? $r['status'] : 'abwesend';
            $zahl[ $st ]++;
            $tage[] = [
                'datum'      => substr( (string) $r['datum'], 0, 10 ),
                'mannschaft' => $namen[ (int) $r['mannschaft_id'] ] ?? '',
                'status'     => $st,
                'status_name'=> self::ZUSTAENDE[ $st ],
                'verspaetung'=> (int) $r['verspaetung'],
                'grund'      => (string) $r['verspaetung_grund'],
            ];
        }

        /* Die Quote zählt das, was man als „da gewesen" bezeichnen würde,
           gegen alle erfassten Termine. Entschuldigt ist nicht da gewesen —
           aber auch kein Vorwurf; darum steht die Zahl daneben und nicht
           allein. */
        $gesamt = array_sum( $zahl );
        $quote  = $gesamt > 0 ? round( $zahl['anwesend'] / $gesamt * 100 ) : null;

        return [ 'tage' => $tage, 'zahl' => $zahl, 'quote' => $quote,
                 'von' => $von, 'bis' => $bis ];
    }

    // ── Vermerke der Mannschaft ─────────────────────────────────────────

    /**
     * Nur Vermerke, die für die ganze Mannschaft gelten (schwimmer_id = 0).
     * Vermerke zu einzelnen Kindern — auch die eigenen — stehen bewusst
     * nicht hier: Ein Vermerk über ein Kind ist eine Notiz des Trainers für
     * den Trainer, und was davon das Kind erfahren soll, sagt der Trainer
     * ihm selbst. Die Vorgabe nennt ausdrücklich „Vermerke der ganzen
     * Mannschaft".
     */
    public static function vermerke( $schwimmer_id, $von = '', $bis = '' ) {
        global $wpdb;
        $mannschaften = LSV07S_Freigabe::mannschaften_fuer( $schwimmer_id, 'vermerke' );
        if ( ! $mannschaften ) return [ 'eintraege' => [], 'von' => '', 'bis' => '' ];
        if ( ! LSV07S_Intern::alle_da( [ 'lsv07i_mb_tag', 'lsv07i_mb_eintrag' ] ) ) {
            return [ 'eintraege' => [], 'von' => '', 'bis' => '' ];
        }

        $p   = $wpdb->prefix;
        $von = $von ?: self::zeitraum_start();
        $bis = $bis ?: gmdate( 'Y-m-d' );
        $pl  = self::platzhalter( $mannschaften );

        $args = array_merge( $mannschaften, [ $von, $bis ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.kategorie, e.text, e.mannschaft_id, t.datum
               FROM {$p}lsv07i_mb_eintrag e
               JOIN {$p}lsv07i_mb_tag t ON t.id = e.tag_id
              WHERE e.mannschaft_id IN ($pl)
                AND e.art = 'vermerk'
                AND e.schwimmer_id = 0
                AND t.datum BETWEEN %s AND %s
           ORDER BY t.datum DESC, e.id DESC",
            ...$args
        ), ARRAY_A );

        $namen = LSV07S_Intern::mannschaft_namen( $mannschaften );
        $out   = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'        => (int) $r['id'],
                'datum'     => substr( (string) $r['datum'], 0, 10 ),
                'mannschaft'=> $namen[ (int) $r['mannschaft_id'] ] ?? '',
                'art'       => self::VERMERK_ARTEN[ $r['kategorie'] ] ?? 'Sonstiges',
                'text'      => (string) $r['text'],
            ];
        }
        return [ 'eintraege' => $out, 'von' => $von, 'bis' => $bis ];
    }

    // ── Aufgaben ────────────────────────────────────────────────────────

    /**
     * Die eigenen Aufgaben und die der ganzen Mannschaft — und nur die.
     * Die Abfrage selbst schränkt auf `schwimmer_id = 0 OR = ich` ein; es
     * wird also nie etwas über ein anderes Kind geladen und danach
     * weggefiltert.
     */
    public static function aufgaben( $schwimmer_id, $von = '', $bis = '' ) {
        global $wpdb;
        $mannschaften = LSV07S_Freigabe::mannschaften_fuer( $schwimmer_id, 'aufgaben' );
        if ( ! $mannschaften ) return [ 'eintraege' => [], 'offen' => 0, 'von' => '', 'bis' => '' ];
        if ( ! LSV07S_Intern::alle_da( [ 'lsv07i_mb_tag', 'lsv07i_mb_eintrag' ] ) ) {
            return [ 'eintraege' => [], 'offen' => 0, 'von' => '', 'bis' => '' ];
        }

        $p   = $wpdb->prefix;
        $von = $von ?: self::zeitraum_start();
        $bis = $bis ?: gmdate( 'Y-m-d' );
        $pl  = self::platzhalter( $mannschaften );

        $args = array_merge( $mannschaften, [ $von, $bis, (int) $schwimmer_id ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.kategorie, e.text, e.erledigt, e.schwimmer_id, e.mannschaft_id, t.datum
               FROM {$p}lsv07i_mb_eintrag e
               JOIN {$p}lsv07i_mb_tag t ON t.id = e.tag_id
              WHERE e.mannschaft_id IN ($pl)
                AND e.art = 'aufgabe'
                AND t.datum BETWEEN %s AND %s
                AND (e.schwimmer_id = 0 OR e.schwimmer_id = %d)
           ORDER BY e.erledigt ASC, t.datum DESC, e.id DESC",
            ...$args
        ), ARRAY_A );

        $namen = LSV07S_Intern::mannschaft_namen( $mannschaften );
        $out   = [];
        $offen = 0;
        foreach ( (array) $rows as $r ) {
            $erledigt = (int) $r['erledigt'] === 1;
            if ( ! $erledigt ) $offen++;
            $out[] = [
                'id'        => (int) $r['id'],
                'datum'     => substr( (string) $r['datum'], 0, 10 ),
                'mannschaft'=> $namen[ (int) $r['mannschaft_id'] ] ?? '',
                'art'       => self::AUFGABE_ARTEN[ $r['kategorie'] ] ?? 'Sonstiger Vermerk',
                'text'      => (string) $r['text'],
                'erledigt'  => $erledigt,
                'fuer_alle' => (int) $r['schwimmer_id'] === 0,
            ];
        }
        return [ 'eintraege' => $out, 'offen' => $offen, 'von' => $von, 'bis' => $bis ];
    }

    // ── Dokumentation ───────────────────────────────────────────────────

    public static function dokumentation( $schwimmer_id, $von = '', $bis = '' ) {
        global $wpdb;
        $mannschaften = LSV07S_Freigabe::mannschaften_fuer( $schwimmer_id, 'dokumentation' );
        if ( ! $mannschaften ) return [ 'tage' => [], 'von' => '', 'bis' => '' ];
        if ( ! LSV07S_Intern::da( 'lsv07i_mb_tag' ) ) return [ 'tage' => [], 'von' => '', 'bis' => '' ];

        $p   = $wpdb->prefix;
        $von = $von ?: self::zeitraum_start();
        $bis = $bis ?: gmdate( 'Y-m-d' );
        $pl  = self::platzhalter( $mannschaften );

        $args = array_merge( $mannschaften, [ $von, $bis ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.datum, t.mannschaft_id, t.dokumentation
               FROM {$p}lsv07i_mb_tag t
              WHERE t.mannschaft_id IN ($pl)
                AND t.datum BETWEEN %s AND %s
                AND t.dokumentation IS NOT NULL AND t.dokumentation <> ''
           ORDER BY t.datum DESC",
            ...$args
        ), ARRAY_A );

        $namen = LSV07S_Intern::mannschaft_namen( $mannschaften );
        $out   = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'datum'      => substr( (string) $r['datum'], 0, 10 ),
                'mannschaft' => $namen[ (int) $r['mannschaft_id'] ] ?? '',
                'text'       => (string) $r['dokumentation'],
            ];
        }
        return [ 'tage' => $out, 'von' => $von, 'bis' => $bis ];
    }

    // ── Wettkämpfe ──────────────────────────────────────────────────────

    /**
     * Die Wettkämpfe der eigenen Mannschaften. Es gelten drei Bedingungen,
     * und alle drei stehen in der Abfrage, nicht in der Darstellung:
     *
     *  1. Der Wettkampf ist freigegeben (`freigegeben = 1` im internen
     *     Bereich). Ein Wettkampf, über den die Trainer noch reden, hat auf
     *     dem Telefon eines Kindes nichts zu suchen.
     *  2. Er gehört zu einer Mannschaft dieser Person.
     *  3. Sein letzter Tag ist höchstens zehn Tage her.
     */
    public static function wettkaempfe( $schwimmer_id ) {
        global $wpdb;
        $mannschaften = LSV07S_Freigabe::mannschaften_fuer( $schwimmer_id, 'wettkaempfe' );
        if ( ! $mannschaften ) return [ 'wettkaempfe' => [] ];
        if ( ! LSV07S_Intern::alle_da( [ 'lsv07i_wettkampf', 'lsv07i_wettkampf_mannschaft' ] ) ) {
            return [ 'wettkaempfe' => [] ];
        }
        if ( ! LSV07S_Intern::spalte_da( 'lsv07i_wettkampf', 'freigegeben' ) ) {
            /* Ohne die Spalte lässt sich „freigegeben" nicht feststellen.
               Dann wird nichts gezeigt — raten wäre hier die falsche
               Richtung. */
            return [ 'wettkaempfe' => [] ];
        }

        $p     = $wpdb->prefix;
        $pl    = self::platzhalter( $mannschaften );
        $frist = gmdate( 'Y-m-d', time() - self::WK_NACHLAUF_TAGE * 86400 );

        $args = array_merge( $mannschaften, [ $frist ] );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT w.id, w.name, w.ort, w.datum_von, w.datum_bis
               FROM {$p}lsv07i_wettkampf w
               JOIN {$p}lsv07i_wettkampf_mannschaft wm ON wm.wettkampf_id = w.id
              WHERE wm.mannschaft_id IN ($pl)
                AND w.freigegeben = 1
                AND w.datum_bis >= %s
           ORDER BY w.datum_von ASC",
            ...$args
        ), ARRAY_A );

        $heute = gmdate( 'Y-m-d' );
        $out   = [];
        foreach ( (array) $rows as $r ) {
            $id = (int) $r['id'];
            $out[] = [
                'id'        => $id,
                'name'      => (string) $r['name'],
                'ort'       => (string) $r['ort'],
                'von'       => substr( (string) $r['datum_von'], 0, 10 ),
                'bis'       => substr( (string) $r['datum_bis'], 0, 10 ),
                'vorbei'    => substr( (string) $r['datum_bis'], 0, 10 ) < $heute,
                'laeuft'    => substr( (string) $r['datum_von'], 0, 10 ) <= $heute
                               && substr( (string) $r['datum_bis'], 0, 10 ) >= $heute,
                'dokumente' => self::wk_dokumente( $id ),
                'links'     => self::wk_links( $id ),
            ];
        }
        return [ 'wettkaempfe' => $out ];
    }

    /** Die drei Unterlagen eines Wettkampfs, soweit vorhanden. */
    private static function wk_dokumente( $wettkampf_id ) {
        global $wpdb;
        if ( ! LSV07S_Intern::da( 'lsv07i_wettkampf_dokument' ) ) return [];
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, typ, dateiname, groesse FROM {$p}lsv07i_wettkampf_dokument
              WHERE wettkampf_id = %d", (int) $wettkampf_id ), ARRAY_A );

        $namen = [ 'ausschreibung' => 'Ausschreibung',
                   'meldeergebnis' => 'Meldeergebnis',
                   'protokoll'     => 'Protokoll' ];
        $out = [];
        foreach ( (array) $rows as $r ) {
            if ( ! isset( $namen[ $r['typ'] ] ) ) continue;
            $out[] = [
                'id'      => (int) $r['id'],
                'typ'     => $r['typ'],
                'name'    => $namen[ $r['typ'] ],
                'datei'   => (string) $r['dateiname'],
                'groesse' => (int) $r['groesse'],
            ];
        }
        /* Immer in derselben Reihenfolge, egal wann was hochgeladen wurde. */
        usort( $out, function ( $a, $b ) use ( $namen ) {
            $r = array_keys( $namen );
            return array_search( $a['typ'], $r, true ) <=> array_search( $b['typ'], $r, true );
        } );
        return $out;
    }

    private static function wk_links( $wettkampf_id ) {
        global $wpdb;
        if ( ! LSV07S_Intern::da( 'lsv07i_wettkampf_link' ) ) return [];
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, titel, url FROM {$p}lsv07i_wettkampf_link
              WHERE wettkampf_id = %d ORDER BY sortierung ASC, id ASC",
            (int) $wettkampf_id ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $url = esc_url_raw( (string) $r['url'] );
            /* Nur http(s). Ein `javascript:`-Verweis in einer Liste, die
               Kinder antippen, wäre ein offenes Scheunentor. */
            if ( ! preg_match( '#^https?://#i', $url ) ) continue;
            $out[] = [
                'id'    => (int) $r['id'],
                'titel' => (string) $r['titel'] !== '' ? (string) $r['titel'] : 'Link',
                'url'   => $url,
            ];
        }
        return $out;
    }

    /**
     * Darf diese Person dieses Wettkampf-Dokument herunterladen? Geprüft
     * wird über dieselbe Liste, die auch die Ansicht füllt — nicht über eine
     * zweite, ähnliche Abfrage.
     */
    public static function darf_wk_dokument( $schwimmer_id, $dokument_id ) {
        global $wpdb;
        if ( ! LSV07S_Intern::da( 'lsv07i_wettkampf_dokument' ) ) return null;
        $p   = $wpdb->prefix;
        $dok = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf_dokument WHERE id = %d", (int) $dokument_id ), ARRAY_A );
        if ( ! $dok ) return null;

        $erlaubt = self::wettkaempfe( $schwimmer_id );
        foreach ( $erlaubt['wettkaempfe'] as $w ) {
            if ( (int) $w['id'] !== (int) $dok['wettkampf_id'] ) continue;
            foreach ( $w['dokumente'] as $d ) {
                if ( (int) $d['id'] === (int) $dokument_id ) return $dok;
            }
        }
        return null;
    }

    // ── Bestzeiten ──────────────────────────────────────────────────────

    public static function bestzeiten( $schwimmer_id ) {
        global $wpdb;
        if ( ! LSV07S_Freigabe::darf( $schwimmer_id, 'bestzeiten' ) ) return [ 'zeiten' => [] ];
        if ( ! LSV07S_Intern::da( 'lsv07i_bestzeiten' ) ) return [ 'zeiten' => [] ];

        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT strecke, zeit_raw, zeit_sek, importiert_am
               FROM {$p}lsv07i_bestzeiten
              WHERE swimmer_id = %d", (int) $schwimmer_id ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'strecke' => (string) $r['strecke'],
                'name'    => self::strecke_name( (string) $r['strecke'] ),
                'zeit'    => (string) $r['zeit_raw'],
                'sek'     => (float) $r['zeit_sek'],
                'stand'   => substr( (string) $r['importiert_am'], 0, 10 ),
            ];
        }
        return [ 'zeiten' => self::nach_strecke( $out ) ];
    }

    // ── Reflexionsbogen ─────────────────────────────────────────────────

    /**
     * Der eigene Bogen der laufenden Saison: Fragen, bisherige Antworten,
     * Bestzeiten mit Wunschzeitfeld. Gibt es keinen Bogen, wird auch keiner
     * angelegt — den legt der Trainer im internen Bereich an, und ob es
     * dieses Jahr einen gibt, entscheidet er.
     */
    public static function reflexion( $schwimmer_id ) {
        global $wpdb;
        $leer = [ 'bogen' => null, 'fragen' => [], 'zeiten' => [], 'status' => '' ];
        if ( ! LSV07S_Freigabe::darf( $schwimmer_id, 'reflexion' ) ) return $leer;
        if ( ! LSV07S_Intern::alle_da( [ 'lsv07i_refl_bogen', 'lsv07i_refl_antwort' ] ) ) return $leer;

        $saison = LSV07S_Intern::saison_aktiv();
        if ( ! $saison ) return $leer;

        $p     = $wpdb->prefix;
        $bogen = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_refl_bogen WHERE saison_id = %d AND swimmer_id = %d",
            (int) $saison['id'], (int) $schwimmer_id ), ARRAY_A );
        if ( ! $bogen ) return $leer;

        $fragen = json_decode( (string) ( $bogen['fragen_snapshot'] ?? '[]' ), true );
        if ( ! is_array( $fragen ) ) $fragen = [];

        /* Bisherige Antworten — der interne Bereich legt sie verschlüsselt
           ab, also wird mit SEINER Funktion gelesen. Eine zweite
           Verschlüsselung daneben wäre eine Fehlerquelle ohne Nutzen. */
        $antworten = [];
        $wunsch    = [];
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT typ, frage_id, antwort_text, strecke, wunschzeit
               FROM {$p}lsv07i_refl_antwort WHERE bogen_id = %d",
            (int) $bogen['id'] ), ARRAY_A );
        foreach ( (array) $rows as $r ) {
            if ( $r['typ'] === 'frage' ) {
                $antworten[ (int) $r['frage_id'] ] = self::entschluesseln( (string) $r['antwort_text'] );
            } elseif ( $r['typ'] === 'zeit' ) {
                $wunsch[ (string) $r['strecke'] ] = (string) $r['wunschzeit'];
            }
        }

        $fragen_aus = [];
        foreach ( $fragen as $f ) {
            $fid = (int) ( $f['id'] ?? 0 );
            $fragen_aus[] = [
                'id'      => $fid,
                'frage'   => (string) ( $f['frage'] ?? '' ),
                'antwort' => $antworten[ $fid ] ?? '',
            ];
        }

        /* Die Bestzeiten stehen im Bogen unabhängig davon, ob der Bereich
           „Bestzeiten" freigegeben ist: Ohne seine Zeit zu kennen, kann
           niemand eine Wunschzeit nennen. */
        $zeiten = [];
        if ( LSV07S_Intern::da( 'lsv07i_bestzeiten' ) ) {
            $bz = $wpdb->get_results( $wpdb->prepare(
                "SELECT strecke, zeit_raw FROM {$p}lsv07i_bestzeiten
                  WHERE swimmer_id = %d", (int) $schwimmer_id ), ARRAY_A );
            foreach ( (array) $bz as $z ) {
                $zeiten[] = [
                    'strecke'    => (string) $z['strecke'],
                    'name'       => self::strecke_name( (string) $z['strecke'] ),
                    'zeit'       => (string) $z['zeit_raw'],
                    'wunschzeit' => $wunsch[ (string) $z['strecke'] ] ?? '',
                ];
            }
            $zeiten = self::nach_strecke( $zeiten );
        }

        return [
            'bogen'  => [ 'id' => (int) $bogen['id'], 'saison' => (string) $saison['name'] ],
            'fragen' => $fragen_aus,
            'zeiten' => $zeiten,
            'status' => (string) $bogen['status'],
            'abgegeben_am' => $bogen['abgegeben_am'] ? substr( (string) $bogen['abgegeben_am'], 0, 10 ) : '',
        ];
    }

    private static function entschluesseln( $gespeichert ) {
        if ( class_exists( 'LSV07I_Reflexion' ) && method_exists( 'LSV07I_Reflexion', 'decrypt' ) ) {
            return (string) LSV07I_Reflexion::decrypt( $gespeichert );
        }
        return (string) $gespeichert;
    }

    private static function verschluesseln( $klartext ) {
        if ( class_exists( 'LSV07I_Reflexion' ) && method_exists( 'LSV07I_Reflexion', 'encrypt' ) ) {
            return (string) LSV07I_Reflexion::encrypt( $klartext );
        }
        return (string) $klartext;
    }

    /**
     * Antworten speichern. `$abgeben = true` setzt den Bogen auf abgegeben;
     * danach ist er nur noch zu lesen — wie beim Weg über den Zugangsschlüssel.
     *
     * Dies ist der einzige schreibende Zugriff von SwimView auf den internen
     * Bereich, und er geht in dieselben Zeilen wie dessen eigener Weg.
     */
    public static function reflexion_speichern( $schwimmer_id, array $antworten, array $wunschzeiten, $abgeben ) {
        global $wpdb;
        $stand = self::reflexion( $schwimmer_id );
        if ( ! $stand['bogen'] ) {
            return [ 'ok' => false, 'message' => 'Für dich gibt es in dieser Saison keinen Reflexionsbogen.' ];
        }
        if ( $stand['status'] === 'abgegeben' ) {
            return [ 'ok' => false, 'message' => 'Dein Bogen ist schon abgegeben und kann nicht mehr geändert werden.' ];
        }

        $p        = $wpdb->prefix;
        $bogen_id = (int) $stand['bogen']['id'];

        $wpdb->delete( $p . 'lsv07i_refl_antwort', [ 'bogen_id' => $bogen_id ], [ '%d' ] );

        $sort = 0;
        foreach ( $stand['fragen'] as $f ) {
            $text = isset( $antworten[ $f['id'] ] ) ? (string) $antworten[ $f['id'] ] : '';
            $text = mb_substr( sanitize_textarea_field( $text ), 0, 5000 );
            $wpdb->insert( $p . 'lsv07i_refl_antwort', [
                'bogen_id'     => $bogen_id,
                'typ'          => 'frage',
                'frage_id'     => (int) $f['id'],
                'frage_text'   => $f['frage'],
                'antwort_text' => self::verschluesseln( $text ),
                'sort_order'   => $sort++,
            ], [ '%d', '%s', '%d', '%s', '%s', '%d' ] );
        }

        foreach ( $stand['zeiten'] as $z ) {
            $wz = isset( $wunschzeiten[ $z['strecke'] ] ) ? (string) $wunschzeiten[ $z['strecke'] ] : '';
            $wz = mb_substr( sanitize_text_field( $wz ), 0, 255 );
            $wpdb->insert( $p . 'lsv07i_refl_antwort', [
                'bogen_id'     => $bogen_id,
                'typ'          => 'zeit',
                'strecke'      => $z['strecke'],
                'zeit_aktuell' => $z['zeit'],
                'wunschzeit'   => $wz,
                'sort_order'   => $sort++,
            ], [ '%d', '%s', '%s', '%s', '%s', '%d' ] );
        }

        if ( $abgeben ) {
            $wpdb->update( $p . 'lsv07i_refl_bogen',
                [ 'status' => 'abgegeben', 'abgegeben_am' => current_time( 'mysql' ) ],
                [ 'id' => $bogen_id ], [ '%s', '%s' ], [ '%d' ] );
        }

        return [ 'ok' => true, 'abgegeben' => (bool) $abgeben ];
    }

    // ── Trainingsplan ───────────────────────────────────────────────────

    /** Die Pläne, die für eine Mannschaft dieser Person freigegeben sind. */
    public static function trainingsplaene( $schwimmer_id ) {
        global $wpdb;
        $mannschaften = LSV07S_Freigabe::mannschaften_fuer( $schwimmer_id, 'trainingsplan' );
        if ( ! $mannschaften ) return [ 'plaene' => [] ];
        if ( ! LSV07S_Intern::da( 'lsv07i_trainingsplan' ) ) return [ 'plaene' => [] ];

        $p   = $wpdb->prefix;
        $ids = [];
        foreach ( $mannschaften as $mid ) {
            foreach ( LSV07S_Freigabe::plaene_der_mannschaft( $mid ) as $pid ) $ids[] = (int) $pid;
        }
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( ! $ids ) return [ 'plaene' => [] ];

        $pl   = self::platzhalter( $ids );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, titel, ersteller_name, geaendert_am
               FROM {$p}lsv07i_trainingsplan WHERE id IN ($pl)
           ORDER BY geaendert_am DESC", ...$ids ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'      => (int) $r['id'],
                'titel'   => (string) $r['titel'] !== '' ? (string) $r['titel'] : 'Trainingsplan',
                'von'     => (string) $r['ersteller_name'],
                'stand'   => substr( (string) $r['geaendert_am'], 0, 10 ),
            ];
        }
        return [ 'plaene' => $out ];
    }

    /** Ein einzelner Plan mit seinen Einheiten — nur wenn freigegeben. */
    public static function trainingsplan( $schwimmer_id, $plan_id ) {
        global $wpdb;
        $plan_id = (int) $plan_id;
        $liste   = self::trainingsplaene( $schwimmer_id );
        $treffer = null;
        foreach ( $liste['plaene'] as $pl ) {
            if ( (int) $pl['id'] === $plan_id ) { $treffer = $pl; break; }
        }
        if ( ! $treffer ) return null;

        $p    = $wpdb->prefix;
        $sess = [];
        if ( LSV07S_Intern::da( 'lsv07i_trainingsplan_session' ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT anzahl, strecke, beschreibung, ausruestung, kommentar
                   FROM {$p}lsv07i_trainingsplan_session
                  WHERE trainingsplan_id = %d ORDER BY sortierung ASC, id ASC",
                $plan_id ), ARRAY_A );
            foreach ( (array) $rows as $r ) {
                $sess[] = [
                    'anzahl'       => (string) $r['anzahl'],
                    'strecke'      => (string) $r['strecke'],
                    'beschreibung' => (string) $r['beschreibung'],
                    'ausruestung'  => (string) $r['ausruestung'],
                    'kommentar'    => (string) $r['kommentar'],
                ];
            }
        }

        $treffer['einheiten'] = $sess;
        return $treffer;
    }

    // ── Nachrichten ─────────────────────────────────────────────────────

    /**
     * Die Nachrichten an diese Person: an die ganze Mannschaft oder an sie
     * selbst. Die Abfrage schränkt auf beides ein, fremde Einzelnachrichten
     * werden nicht geladen.
     */
    public static function nachrichten( $schwimmer_id ) {
        global $wpdb;
        $mannschaften = LSV07S_Freigabe::mannschaften_fuer( $schwimmer_id, 'nachrichten' );
        if ( ! $mannschaften ) return [ 'nachrichten' => [], 'ungelesen' => 0 ];

        $p   = $wpdb->prefix;
        $pl  = self::platzhalter( $mannschaften );
        $args = array_merge( $mannschaften, [ (int) $schwimmer_id ] );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT n.id, n.mannschaft_id, n.schwimmer_id, n.betreff, n.text,
                    n.von_name, n.erstellt_am
               FROM {$p}lsv07s_nachricht n
              WHERE n.mannschaft_id IN ($pl)
                AND (n.schwimmer_id = 0 OR n.schwimmer_id = %d)
           ORDER BY n.erstellt_am DESC, n.id DESC
              LIMIT 200",
            ...$args
        ), ARRAY_A );

        $gelesen = [];
        if ( $rows ) {
            $ids = array_map( fn( $r ) => (int) $r['id'], $rows );
            $plg = self::platzhalter( $ids );
            $g   = $wpdb->get_col( $wpdb->prepare(
                "SELECT nachricht_id FROM {$p}lsv07s_gelesen
                  WHERE schwimmer_id = %d AND nachricht_id IN ($plg)",
                ...array_merge( [ (int) $schwimmer_id ], $ids ) ) );
            foreach ( (array) $g as $gid ) $gelesen[ (int) $gid ] = true;
        }

        $namen = LSV07S_Intern::mannschaft_namen( $mannschaften );
        $out   = [];
        $offen = 0;
        foreach ( (array) $rows as $r ) {
            $id = (int) $r['id'];
            $ist_gelesen = isset( $gelesen[ $id ] );
            if ( ! $ist_gelesen ) $offen++;
            $out[] = [
                'id'         => $id,
                'betreff'    => (string) $r['betreff'],
                'text'       => (string) $r['text'],
                'von'        => (string) $r['von_name'],
                'am'         => (string) $r['erstellt_am'],
                'mannschaft' => $namen[ (int) $r['mannschaft_id'] ] ?? '',
                'nur_fuer_mich' => (int) $r['schwimmer_id'] !== 0,
                'gelesen'    => $ist_gelesen,
                'dateien'    => LSV07S_Dateien::zu_nachricht( $id ),
            ];
        }
        return [ 'nachrichten' => $out, 'ungelesen' => $offen ];
    }

    /** Merkt sich, dass diese Person diese Nachricht gesehen hat. */
    public static function nachricht_gelesen( $schwimmer_id, $nachricht_id ) {
        global $wpdb;
        $alle = self::nachrichten( $schwimmer_id );
        $darf = false;
        foreach ( $alle['nachrichten'] as $n ) {
            if ( (int) $n['id'] === (int) $nachricht_id ) { $darf = true; break; }
        }
        if ( ! $darf ) return false;

        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$p}lsv07s_gelesen (nachricht_id, schwimmer_id, gelesen_am)
             VALUES (%d, %d, %s)",
            (int) $nachricht_id, (int) $schwimmer_id, current_time( 'mysql' ) ) );
        return true;
    }

    /** Darf diese Person diesen Anhang holen? */
    public static function darf_anhang( $schwimmer_id, $datei_id ) {
        $datei = LSV07S_Dateien::holen( $datei_id );
        if ( ! $datei ) return null;
        $alle = self::nachrichten( $schwimmer_id );
        foreach ( $alle['nachrichten'] as $n ) {
            if ( (int) $n['id'] === (int) $datei['nachricht_id'] ) return $datei;
        }
        return null;
    }
}
