<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wettkampfmeldungen: je Wettkampf und Mannschaft eine Meldeliste.
 *
 * Der Kopf hält Abschnittszahl und Zahl der Wettkampfnummern, die
 * Startzeilen jeweils Abschnitt, Wettkampfnummer, Strecke und eine
 * Meldezeit. Name und Jahrgang liegen in der Startzeile als Kopie: Eine
 * abgegebene Meldung bleibt richtig, auch wenn der Schwimmer später die
 * Mannschaft wechselt oder aus dem System verschwindet.
 *
 * Was hier anders ist als im internen Bereich:
 *
 * 1. **Die Meldung sagt, was ihr fehlt.** Dort kann man eine Liste
 *    abgeben, in der die Hälfte der Starts keine Meldezeit hat und zwei
 *    Schwimmer am Wettkampftag kein gültiges Attest haben — niemand
 *    weist darauf hin. Hier steht beides in der Antwort, und zwar
 *    geprüft auf den Wettkampftag, nicht auf heute.
 * 2. **Die Meldezeit kann aus der Bestzeit kommen.** Dort tippt man sie
 *    ab, obwohl sie im Nachbarreiter steht.
 * 3. **Jede Änderung steht im Protokoll** — dort nur Anlegen und
 *    Ändern des Kopfes, nicht die Startliste.
 *
 * Was hier NICHT anders ist, obwohl es naheliegt: Es gibt keinen Stand
 * „abgegeben". Dafür bräuchte `lsv07i_meldung` eine Spalte, und SwimSys
 * ändert das Schema des internen Bereichs nicht, solange der läuft (die
 * Begründung steht im Kopf des Plugins). An dem Tag, an dem SwimSys das
 * Schema übernimmt, kann sie dazukommen.
 */
class LSV07S_Meldung {

    /** Meldezeit: 1:02,45 oder 32,10. Punkt gilt wie Komma. */
    const ZEIT_MUSTER = '/^\d{0,2}:?\d{1,2}[,]\d{1,2}$/';

    // ── Schalter ─────────────────────────────────────────────────────────

    private static function darf_lesen() {
        return LSV07S_Schalter::an( 'schwimmen/meldung' );
    }
    private static function darf_aendern() {
        return LSV07S_Schalter::an( 'schwimmen/meldung' );
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** Die Meldungen, die dieses Konto sehen darf. */
    public static function liste( $wettkampf_id = 0 ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf_lesen() ) return [];
        if ( ! LSV07S_Tabellen::da( 'meldung' ) ) return [];

        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( $erlaubt === [] ) return [];

        $m  = LSV07S_Tabellen::t( 'meldung' );
        $w  = LSV07S_Tabellen::t( 'wettkampf' );
        $wo = [ 'm.mannschaft_id IN (' . implode( ',', array_map( 'intval', $erlaubt ) ) . ')' ];
        $werte = [];
        if ( $wettkampf_id ) {
            $wo[] = 'm.wettkampf_id = %d';
            $werte[] = (int) $wettkampf_id;
        }
        $sql = "SELECT m.*, w.name AS wk_name, w.ort AS wk_ort, w.datum_von, w.datum_bis"
             . " FROM $m m JOIN $w w ON w.id = m.wettkampf_id"
             . ' WHERE ' . implode( ' AND ', $wo )
             . ' ORDER BY w.datum_von DESC, m.id DESC';
        $zeilen = (array) $wpdb->get_results(
            $werte ? $wpdb->prepare( $sql, ...$werte ) : $sql, ARRAY_A );

        $namen = [];
        foreach ( LSV07S_Mannschaft::alle( $sparte ) as $g ) {
            $namen[ (int) $g['id'] ] = $g['name'];
        }

        return array_map( fn( $z ) => [
            'id'          => (int) $z['id'],
            'wettkampf_id'=> (int) $z['wettkampf_id'],
            'wettkampf'   => (string) $z['wk_name'],
            'ort'         => (string) $z['wk_ort'],
            'datum'       => (string) $z['datum_von'],
            'datum_de'    => LSV07S_Datum::de( $z['datum_von'] ),
            'mannschaft_id' => (int) $z['mannschaft_id'],
            'mannschaft'  => $namen[ (int) $z['mannschaft_id'] ] ?? '',
            'abschnitte'  => (int) $z['abschnitte'],
            'nummern'     => (int) $z['wettkampfnummern'],
            'starts'      => (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'meldung_start' )
                . ' WHERE meldung_id = %d', (int) $z['id'] ) ),
            'geaendert'   => LSV07S_Datum::de_zeit( $z['geaendert_am'] ?? null, '' ),
        ], $zeilen );
    }

    /** Eine Meldung mit Kopf, Starts, Kader und den offenen Punkten. */
    public static function holen( $id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $id = (int) $id;
        if ( ! self::darf_lesen() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $kopf = self::kopf( $id );
        if ( ! $kopf ) return 'Diese Meldung gibt es nicht.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, (int) $kopf['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $starts = self::starts( $id );
        $kader  = self::kader( (int) $kopf['mannschaft_id'], (string) $kopf['datum_von'] );

        return [
            'id'          => $id,
            'wettkampf_id'=> (int) $kopf['wettkampf_id'],
            'wettkampf'   => (string) $kopf['wk_name'],
            'ort'         => (string) $kopf['wk_ort'],
            'datum'       => (string) $kopf['datum_von'],
            'datum_de'    => LSV07S_Datum::de( $kopf['datum_von'] ),
            'mannschaft_id' => (int) $kopf['mannschaft_id'],
            'mannschaft'  => LSV07S_Mannschaft::holen( $sparte, (int) $kopf['mannschaft_id'] ),
            'abschnitte'  => (int) $kopf['abschnitte'],
            'nummern'     => (int) $kopf['wettkampfnummern'],
            'starts'      => $starts,
            'kader'       => $kader,
            'strecken'    => LSV07S_Bestzeit::STRECKEN,
            'offen'       => self::offene_punkte( $starts, $kader ),
            'darf_aendern'=> self::darf_aendern()
                             && LSV07S_Rechte::darf_mannschaft(
                                  $sparte, (int) $kopf['mannschaft_id'] ),
        ];
    }

    /** Der Kopf mit den Daten des Wettkampfs. */
    private static function kopf( $id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'meldung' ) ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT m.*, w.name AS wk_name, w.ort AS wk_ort, w.datum_von, w.datum_bis'
            . ' FROM ' . LSV07S_Tabellen::t( 'meldung' ) . ' m'
            . ' JOIN ' . LSV07S_Tabellen::t( 'wettkampf' ) . ' w ON w.id = m.wettkampf_id'
            . ' WHERE m.id = %d LIMIT 1', (int) $id ), ARRAY_A );
    }

    /** Die Startzeilen einer Meldung. */
    public static function starts( $meldung_id ) {
        global $wpdb;
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'meldung_start' )
            . ' WHERE meldung_id = %d ORDER BY sortierung ASC, id ASC',
            (int) $meldung_id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id'          => (int) $z['id'],
            'schwimmer_id'=> (int) $z['schwimmer_id'],
            'ist_staffel' => (int) ( $z['ist_staffel'] ?? 0 ) === 1,
            'name'        => (string) $z['schwimmer_name'],
            'jahrgang'    => (int) $z['jahrgang'],
            'abschnitt'   => (string) $z['abschnitt'],
            'wettkampf_nr'=> (string) $z['wettkampf_nr'],
            'strecke'     => (string) $z['strecke'],
            'strecke_name'=> LSV07S_Bestzeit::STRECKEN[ $z['strecke'] ] ?? (string) $z['strecke'],
            'meldezeit'   => (string) $z['meldezeit'],
            'attest_bis'  => $z['attest_bis'] ?? null,
            'attest_de'   => LSV07S_Datum::de( $z['attest_bis'] ?? null ),
        ], $zeilen );
    }

    /**
     * Der Kader zum Melden — mit dem Atteststand AM WETTKAMPFTAG und den
     * Bestzeiten, damit die Meldezeit nicht abgetippt werden muss.
     */
    private static function kader( $mannschaft_id, $wettkampftag ) {
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $raus = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ) as $s ) {
            $stand = LSV07S_Attest::stand( $s['attest_bis'], $wettkampftag );
            $raus[] = [
                'id' => $s['id'], 'name' => $s['name'], 'jahrgang' => $s['jahrgang'],
                'attest_bis' => $s['attest_bis'],
                'attest_de'  => LSV07S_Datum::de( $s['attest_bis'] ),
                'attest_stand' => $stand,
                'attest_name'  => LSV07S_Attest::stand_name( $stand ),
                'bestzeiten'   => LSV07S_Bestzeit::zu_person( (int) $s['id'] ),
            ];
        }
        return $raus;
    }

    /**
     * Was an dieser Meldung noch offen ist.
     *
     * Der interne Bereich nennt nichts davon: Eine Meldung ohne
     * Meldezeiten geht genauso heraus wie eine mit, und ein abgelaufenes
     * Attest fällt erst am Beckenrand auf.
     */
    private static function offene_punkte( array $starts, array $kader ) {
        $ohne_zeit = [];
        $attest = [];
        $nach_id = [];
        foreach ( $kader as $k ) $nach_id[ (int) $k['id'] ] = $k;

        foreach ( $starts as $s ) {
            if ( $s['ist_staffel'] ) continue;
            if ( $s['meldezeit'] === '' ) {
                $ohne_zeit[] = $s['name'] . ' (' . ( $s['strecke_name'] ?: '?' ) . ')';
            }
            $k = $nach_id[ (int) $s['schwimmer_id'] ] ?? null;
            if ( $k && in_array( $k['attest_stand'],
                    [ LSV07S_Attest::ABGELAUFEN, LSV07S_Attest::KEIN ], true ) ) {
                $attest[ $s['name'] ] = $k['attest_name'];
            }
        }

        $punkte = [];
        if ( $ohne_zeit !== [] ) {
            $punkte[] = [
                'art'  => 'meldezeit',
                'text' => count( $ohne_zeit ) . ' Start(s) ohne Meldezeit: '
                        . implode( ', ', array_slice( $ohne_zeit, 0, 8 ) )
                        . ( count( $ohne_zeit ) > 8 ? ' …' : '' ),
            ];
        }
        if ( $attest !== [] ) {
            $liste = [];
            foreach ( $attest as $name => $stand ) $liste[] = $name . ' (' . $stand . ')';
            $punkte[] = [
                'art'  => 'attest',
                'text' => 'Am Wettkampftag ohne gültiges Attest: ' . implode( ', ', $liste ),
            ];
        }
        return $punkte;
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /** Den Kopf anlegen oder ändern. Je Wettkampf und Mannschaft einer. */
    public static function kopf_speichern( $wettkampf_id, $mannschaft_id,
                                           $abschnitte, $nummern ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $wettkampf_id = (int) $wettkampf_id;
        $mannschaft_id = (int) $mannschaft_id;
        $abschnitte = max( 1, min( 60, (int) $abschnitte ) );
        $nummern = max( 1, min( 999, (int) $nummern ) );

        if ( ! self::darf_aendern() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! LSV07S_Tabellen::da( 'meldung' ) ) return 'Die Tabelle fehlt.';
        if ( ! $wettkampf_id ) return 'Bitte einen Wettkampf wählen.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $wk = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'wettkampf' ) . ' WHERE id = %d LIMIT 1',
            $wettkampf_id ), ARRAY_A );
        if ( ! $wk ) return 'Diesen Wettkampf gibt es nicht.';

        /* Melden kann nur, wer am Wettkampf beteiligt ist. Sonst steht
           plötzlich eine Meldeliste einer Mannschaft an einem Wettkampf,
           zu dem sie nicht eingeladen ist. */
        if ( ! in_array( $mannschaft_id,
                LSV07S_Wettkampf::mannschaft_ids( $wettkampf_id ), true ) ) {
            return 'Diese Mannschaft ist an dem Wettkampf nicht beteiligt. '
                 . 'Tragen Sie sie zuerst beim Wettkampf ein.';
        }

        $t = LSV07S_Tabellen::t( 'meldung' );
        $da = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE wettkampf_id = %d AND mannschaft_id = %d LIMIT 1",
            $wettkampf_id, $mannschaft_id ), ARRAY_A );

        if ( $da ) {
            $wpdb->update( $t, [
                'abschnitte' => $abschnitte,
                'wettkampfnummern' => $nummern,
                'geaendert_am' => LSV07S_Datum::jetzt(),
            ], [ 'id' => (int) $da['id'] ], [ '%d', '%d', '%s' ], [ '%d' ] );
            $id = (int) $da['id'];
        } else {
            $wpdb->insert( $t, [
                'wettkampf_id' => $wettkampf_id,
                'mannschaft_id' => $mannschaft_id,
                'abschnitte' => $abschnitte,
                'wettkampfnummern' => $nummern,
                'angelegt_von' => get_current_user_id(),
                'angelegt_am' => LSV07S_Datum::jetzt(),
                'geaendert_am' => LSV07S_Datum::jetzt(),
            ], [ '%d', '%d', '%d', '%d', '%d', '%s', '%s' ] );
            $id = (int) $wpdb->insert_id;
            if ( ! $id ) return 'Die Meldung konnte nicht angelegt werden.';
        }

        LSV07S_Log::schreibe( $da ? 'meldung.geaendert' : 'meldung.angelegt', [
            'bereich' => $sparte, 'ziel_typ' => 'meldung', 'ziel_id' => $id,
            'ziel_name' => (string) $wk['name'],
            'details' => $abschnitte . ' Abschnitte, ' . $nummern . ' Wettkampfnummern',
        ] );
        return [ 'id' => $id, 'message' => 'Gespeichert.' ];
    }

    /**
     * Die Startliste setzen.
     *
     * Die Liste ist ein Ganzes: Sie wird ersetzt, nicht Zeile für Zeile
     * geändert. Name und Jahrgang kommen aus den Stammdaten, nicht aus
     * dem Browser — was dort steht, kann geändert worden sein.
     */
    public static function starts_setzen( $meldung_id, array $liste ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $meldung_id = (int) $meldung_id;
        if ( ! self::darf_aendern() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $kopf = self::kopf( $meldung_id );
        if ( ! $kopf ) return 'Diese Meldung gibt es nicht.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, (int) $kopf['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( count( $liste ) > 500 ) {
            return 'Mehr als 500 Starts sind nicht vorgesehen.';
        }

        $stamm = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, (int) $kopf['mannschaft_id'] ) as $s ) {
            $stamm[ (int) $s['id'] ] = $s;
        }

        $zeilen = [];
        $sort = 0;
        $fremde = 0;
        foreach ( $liste as $e ) {
            if ( ! is_array( $e ) ) continue;

            if ( ! empty( $e['ist_staffel'] ) ) {
                /* Eine Staffel hat keinen einzelnen Schwimmer, keine
                   Meldezeit und kein Attest — nur Abschnitt, Nummer und
                   die Bezeichnung. */
                $zeilen[] = [
                    'meldung_id' => $meldung_id, 'schwimmer_id' => 0, 'ist_staffel' => 1,
                    'schwimmer_name' => '', 'jahrgang' => 0,
                    'abschnitt' => self::kurz( $e['abschnitt'] ?? '', 20 ),
                    'wettkampf_nr' => self::kurz( $e['wettkampf_nr'] ?? '', 20 ),
                    'strecke' => self::kurz( $e['strecke'] ?? '', 60 ),
                    'meldezeit' => '', 'attest_bis' => null, 'sortierung' => $sort++,
                ];
                continue;
            }

            $sid = absint( $e['schwimmer_id'] ?? 0 );
            if ( ! isset( $stamm[ $sid ] ) ) { $fremde++; continue; }

            /* Das Attestdatum kommt hier bewusst aus dem Formular: Für
               eine einzelne Meldung darf es abweichen (ein Attest, das
               erst zum Wettkampf vorliegt). Geschrieben wird nur die
               Startzeile; die Stammdaten bleiben, wie sie sind. */
            $attest = array_key_exists( 'attest_bis', $e )
                ? LSV07S_Datum::rein( $e['attest_bis'] )
                : ( $stamm[ $sid ]['attest_bis'] ?? null );

            $zeilen[] = [
                'meldung_id' => $meldung_id,
                'schwimmer_id' => $sid,
                'ist_staffel' => 0,
                'schwimmer_name' => (string) $stamm[ $sid ]['name'],
                'jahrgang' => (int) ( $stamm[ $sid ]['jahrgang'] ?? 0 ),
                'abschnitt' => self::kurz( $e['abschnitt'] ?? '', 20 ),
                'wettkampf_nr' => self::kurz( $e['wettkampf_nr'] ?? '', 20 ),
                'strecke' => self::kurz( $e['strecke'] ?? '', 60 ),
                'meldezeit' => self::zeit( $e['meldezeit'] ?? '' ),
                'attest_bis' => $attest,
                'sortierung' => $sort++,
            ];
        }

        $t = LSV07S_Tabellen::t( 'meldung_start' );
        $wpdb->delete( $t, [ 'meldung_id' => $meldung_id ], [ '%d' ] );
        foreach ( $zeilen as $z ) {
            $wpdb->insert( $t, $z,
                [ '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ] );
        }
        $wpdb->update( LSV07S_Tabellen::t( 'meldung' ),
            [ 'geaendert_am' => LSV07S_Datum::jetzt() ],
            [ 'id' => $meldung_id ], [ '%s' ], [ '%d' ] );

        LSV07S_Log::schreibe( 'meldung.starts', [
            'bereich' => $sparte, 'ziel_typ' => 'meldung', 'ziel_id' => $meldung_id,
            'ziel_name' => (string) $kopf['wk_name'],
            'details' => count( $zeilen ) . ' Start(s)'
                       . ( $fremde ? ', ' . $fremde . ' fremde übersprungen' : '' ),
        ] );

        $starts = self::starts( $meldung_id );
        return [
            'message' => count( $zeilen ) . ' Start(s) gespeichert.'
                       . ( $fremde
                           ? ' ' . $fremde . ' Zeile(n) betraf(en) Personen aus einer anderen '
                             . 'Mannschaft und wurden übersprungen.' : '' ),
            'starts' => $starts,
            'offen'  => self::offene_punkte( $starts,
                            self::kader( (int) $kopf['mannschaft_id'],
                                         (string) $kopf['datum_von'] ) ),
        ];
    }

    /** Eine Meldung löschen. */
    public static function loeschen( $id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $id = (int) $id;
        if ( ! LSV07S_Schalter::an( 'schwimmen/meldung' ) ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        $kopf = self::kopf( $id );
        if ( ! $kopf ) return 'Diese Meldung gibt es nicht.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, (int) $kopf['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        $zahl = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'meldung_start' )
            . ' WHERE meldung_id = %d', $id ) );

        $wpdb->delete( LSV07S_Tabellen::t( 'meldung_start' ), [ 'meldung_id' => $id ], [ '%d' ] );
        $wpdb->delete( LSV07S_Tabellen::t( 'meldung' ), [ 'id' => $id ], [ '%d' ] );

        LSV07S_Log::schreibe( 'meldung.geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'meldung', 'ziel_id' => $id,
            'ziel_name' => (string) $kopf['wk_name'],
            'details' => $zahl . ' Start(s)',
        ] );
        return [ 'message' => 'Gelöscht, mit ' . $zahl . ' Start(s).' ];
    }

    /**
     * Die Meldung als Textblock zum Weitergeben.
     *
     * Tabulatorgetrennt — das lässt sich in jede Tabelle einfügen und
     * braucht keinen Dateidownload. Im internen Bereich gibt es dafür
     * ein eigenes Recht; hier hängt es am Schalter der Ansicht.
     */
    public static function export( $id ) {
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! LSV07S_Schalter::an( 'schwimmen/meldung' ) ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        $kopf = self::kopf( (int) $id );
        if ( ! $kopf ) return 'Diese Meldung gibt es nicht.';
        if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, (int) $kopf['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $zeilen = [ implode( "\t", [ 'Abschnitt', 'WK-Nr', 'Strecke', 'Name',
                                     'Jahrgang', 'Meldezeit', 'Attest bis' ] ) ];
        foreach ( self::starts( (int) $id ) as $s ) {
            $zeilen[] = implode( "\t", [
                $s['abschnitt'], $s['wettkampf_nr'], $s['strecke_name'],
                $s['ist_staffel'] ? 'Staffel' : $s['name'],
                $s['jahrgang'] ?: '', $s['meldezeit'],
                $s['attest_bis'] ? LSV07S_Datum::de( $s['attest_bis'] ) : '',
            ] );
        }

        LSV07S_Log::schreibe( 'meldung.export', [
            'bereich' => $sparte, 'ziel_typ' => 'meldung', 'ziel_id' => (int) $id,
            'ziel_name' => (string) $kopf['wk_name'],
        ] );
        return [ 'text' => implode( "\n", $zeilen ),
                 'name' => 'Meldung ' . $kopf['wk_name'] ];
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private static function kurz( $wert, $laenge ) {
        $w = trim( sanitize_text_field( (string) $wert ) );
        return $w === '0' ? '' : mb_substr( $w, 0, $laenge );
    }

    /** Eine Meldezeit prüfen. Ein Punkt gilt als Komma, Leerzeichen fallen weg. */
    public static function zeit( $zeit ) {
        $z = trim( (string) $zeit );
        if ( $z === '' ) return '';
        $z = str_replace( [ ' ', '.' ], [ '', ',' ], $z );
        return preg_match( self::ZEIT_MUSTER, $z ) ? $z : '';
    }
}
