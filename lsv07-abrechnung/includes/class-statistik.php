<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Alle Zahlen der Statistik. Eine Stelle je Kennzahl.
 *
 * Hier wird nur gerechnet — kein Tor, keine Ausgabe. Wer was sehen darf,
 * beantwortet `rahmen()`; die Endpunkte in LSV07A_Ajax_Statistik prüfen
 * die Rolle und geben weiter, was `rahmen()` erlaubt.
 *
 * ── Warum fast alles in PHP gerechnet wird ──────────────────────────────
 * Die Posten eines Jahres werden EINMAL geholt und dann in einem Durchlauf
 * nach Quartal, Monat, Wochentag, Typ, Mannschaft, Abteilung, Quelle und
 * Abrechnungsart aufgeteilt. Das ist Absicht:
 *  - Eine Abfrage statt je Sicht eine. Kein N+1, auch nicht bei zwanzig
 *    Auswertungen auf derselben Seite.
 *  - Keine datenbankeigenen Datumsfunktionen (MONTH, WEEKDAY, DATEDIFF).
 *    Damit läuft dieselbe Abfrage im Prüfstand gegen SQLite wie in der
 *    Anlage gegen MySQL — geprüft wird also das echte SQL.
 *
 * ── Was hier bewusst NICHT entsteht ────────────────────────────────────
 *  - Keine Rangliste fremder Personen für Trainer. Die Sicht „eigene"
 *    filtert auf das eigene Konto, und zwar im SQL.
 *  - Keine Liegezeit je Genehmiger. Gemessen wird je STUFE, nicht je Kopf.
 *    Eine Kennzahl über die Arbeitsgeschwindigkeit einer namentlich
 *    genannten Person ist Leistungsüberwachung; dafür ist das hier nicht
 *    da.
 *  - Nichts aus lsv07a_log. Das Protokoll ist Nachweis. Wer es auswertet,
 *    fängt an es zu pflegen, und dann ist es kein Nachweis mehr.
 *  - Keine Zahl über einzelne Sportler. Hier geht es um Trainerabrechnung.
 */
class LSV07A_Statistik {

    /** Die drei Sichten. Mehr gibt es nicht. */
    const SICHTEN = [ 'eigene', 'abteilung', 'alle' ];

    private static function t( $n ) { global $wpdb; return $wpdb->prefix . $n; }

    /** prepare() ohne Platzhalter warnt — darum dieser Umweg. */
    private static function q( $sql, array $args ) {
        global $wpdb;
        return $args ? $wpdb->prepare( $sql, $args ) : $sql;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  WER DARF WAS SEHEN — eine Stelle
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Baut den Rahmen für eine Sicht — oder gibt null zurück, wenn das
     * Konto diese Sicht nicht haben darf.
     *
     * Bewusst null statt stillschweigend auf die engere Sicht
     * zurückzufallen: Eine Zahl, die etwas anderes bedeutet als der Reiter
     * sagt, ist schlimmer als eine Absage.
     *
     * `personen` ist die EINZIGE Einschränkung, und sie gilt für
     * Quartalsabrechnungen und Rechnungen gleich. null heisst „ohne
     * Einschränkung", die leere Liste heisst „nichts" — eine
     * Abteilungsleitung ohne Abteilung sieht keine fremden Zahlen, nicht
     * alle.
     */
    public static function rahmen( $sicht, $uid = 0 ) {
        $uid   = (int) ( $uid ?: LSV07A_Access::blick() );
        $sicht = (string) $sicht;
        if ( ! in_array( $sicht, self::SICHTEN, true ) ) return null;

        $ist_admin = LSV07A_Rollen::ist_admin( $uid );
        $darf_alle = LSV07A_Rollen::ist_wart( $uid ) || LSV07A_Rollen::ist_kasse( $uid ) || $ist_admin;
        $darf_abt  = LSV07A_Rollen::ist_leiter( $uid );

        if ( $sicht === 'alle' && ! $darf_alle ) return null;
        if ( $sicht === 'abteilung' && ! $darf_abt ) return null;

        $r = [ 'sicht' => $sicht, 'uid' => $uid, 'personen' => null,
               'rechnungen' => false, 'personen_zeigen' => false, 'beschriftung' => '' ];

        if ( $sicht === 'eigene' ) {
            $r['personen']     = [ $uid ];
            $r['rechnungen']   = true;   // die eigenen Rechnungen darf jeder sehen
            $r['beschriftung'] = 'nur das eigene Konto';
        } elseif ( $sicht === 'abteilung' ) {
            // Genau die Konten, über die diese Leitung auch entscheidet.
            $r['personen']        = LSV07A_Abteilung::konten_im_zugriff( $uid );
            $r['rechnungen']      = true;
            $r['personen_zeigen'] = true;
            /* Die Beschriftung muss sagen, was wirklich drinsteht.
               konten_im_zugriff() gibt der Administration ALLE Konten —
               sie darf ja über alle entscheiden. Stünde dann „Abteilung:
               keine Abteilung" darüber, behauptete die Seite das
               Gegenteil von dem, was sie zeigt. */
            $leitet = LSV07A_Abteilung::leitet( $uid );
            if ( $leitet ) {
                $r['beschriftung'] = 'Abteilung: ' . LSV07A_Abteilung::beschriftung( $uid );
            } elseif ( $r['personen'] === null ) {
                $r['beschriftung'] = 'alle Konten — die Administration leitet keine eigene Abteilung';
            } else {
                $r['beschriftung'] = 'keine Abteilung zugeordnet';
            }
        } else {
            /* Rechnungen gehen den Wart nichts an: Er prüft Stunden und
               Beträge der Quartalsabrechnung. Über Rechnungen entscheiden
               Abteilungsleitung und Kasse. */
            $r['rechnungen']      = LSV07A_Rollen::ist_kasse( $uid ) || $ist_admin;
            $r['personen_zeigen'] = true;
            $r['beschriftung']    = 'alle Konten';
        }
        return $r;
    }

    /** Die WHERE-Bedingung des Rahmens. Gibt [sql, args] zurück. */
    private static function wo( $r, $jahr, $alias ) {
        $teile = []; $args = [];
        if ( is_array( $r['personen'] ) ) {
            if ( ! $r['personen'] ) return [ '1 = 0', [] ];
            $teile[] = "$alias.wp_user_id IN (" . implode( ',', array_fill( 0, count( $r['personen'] ), '%d' ) ) . ")";
            foreach ( $r['personen'] as $p ) $args[] = (int) $p;
        }
        if ( $jahr ) { $teile[] = "$alias.jahr = %d"; $args[] = (int) $jahr; }
        return [ $teile ? implode( ' AND ', $teile ) : '1 = 1', $args ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HILFSRECHNUNGEN
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Median, nicht Mittelwert.
     *
     * Ein einziger Altfall, der ein Jahr lag, zieht den Mittelwert so weit
     * nach oben, dass die Zahl über den Normalfall nichts mehr sagt. Der
     * Median sagt: die Hälfte war schneller, die Hälfte langsamer.
     */
    public static function median( array $werte ) {
        $werte = array_values( array_filter( $werte, fn( $w ) => $w !== null ) );
        if ( ! $werte ) return null;
        sort( $werte, SORT_NUMERIC );
        $n = count( $werte );
        $m = intdiv( $n, 2 );
        return $n % 2 ? (float) $werte[ $m ] : round( ( $werte[ $m - 1 ] + $werte[ $m ] ) / 2, 1 );
    }

    /**
     * Tage zwischen zwei Zeitpunkten — gezählt in KALENDERTAGEN, nicht in
     * 24-Stunden-Schritten.
     *
     * Sonst hinge die Zahl an der Uhrzeit: Etwas, das gestern um 23 Uhr
     * eingereicht wurde, stünde heute früh mit „0 Tage" da, und derselbe
     * Vorgang von gestern früh mit „1 Tag". Wer fragt „wie lange liegt das
     * schon", meint „seit welchem Tag" — also wird auf den Tag gekürzt.
     *
     * null, wenn einer der beiden Zeitpunkte fehlt.
     */
    public static function tage_zwischen( $von, $bis ) {
        if ( empty( $von ) || empty( $bis ) ) return null;
        $a = strtotime( substr( (string) $von, 0, 10 ) );
        $b = strtotime( substr( (string) $bis, 0, 10 ) );
        if ( ! $a || ! $b ) return null;
        return (int) round( ( $b - $a ) / 86400 );
    }

    private static function monat_von( $datum ) {
        $z = strtotime( (string) $datum );
        return $z ? (int) date( 'n', $z ) : 0;
    }

    private static function quartal_von_monat( $m ) {
        $m = (int) $m;
        return $m >= 1 && $m <= 12 ? 'Q' . (int) ceil( $m / 3 ) : '';
    }

    private static function leere_quartale() {
        return array_fill_keys( [ 'Q1', 'Q2', 'Q3', 'Q4' ], 0.0 );
    }

    /**
     * Was ein Jahr voraussichtlich kostet.
     *
     * Gerechnet wird nur mit VOLL VERGANGENEN Quartalen: Das laufende ist
     * halb leer und würde die Rechnung nach unten ziehen. Für ein
     * abgelaufenes Jahr gibt es nichts hochzurechnen — da steht die Zahl
     * schon fest, und eine Schätzung daneben wäre nur verwirrend.
     */
    public static function hochrechnung( $jahr, array $je_quartal ) {
        $heute      = current_time( 'Y-m-d' );
        $jetzt_jahr = (int) date( 'Y', strtotime( $heute ) );
        $aus        = [ 'moeglich' => false, 'grund' => '', 'bisher' => 0.0,
                        'quartale' => 0, 'erwartet' => 0.0 ];

        if ( $jahr < $jetzt_jahr ) {
            $aus['grund'] = 'Das Jahr ist abgeschlossen — die Zahl steht fest.';
            return $aus;
        }
        if ( $jahr > $jetzt_jahr ) {
            $aus['grund'] = 'Das Jahr hat noch nicht begonnen.';
            return $aus;
        }

        $q_jetzt = (int) ceil( (int) date( 'n', strtotime( $heute ) ) / 3 );
        $fertig  = $q_jetzt - 1;
        if ( $fertig < 1 ) {
            $aus['grund'] = 'Noch kein Quartal ist vorbei — dafür fehlt die Grundlage.';
            return $aus;
        }

        $bisher = 0.0;
        foreach ( [ 'Q1', 'Q2', 'Q3', 'Q4' ] as $i => $q ) {
            if ( $i >= $fertig ) break;
            $bisher += (float) ( $je_quartal[ $q ] ?? 0 );
        }
        if ( $bisher <= 0 ) {
            $aus['grund'] = 'In den vergangenen Quartalen wurde nichts abgerechnet.';
            return $aus;
        }
        return [ 'moeglich' => true, 'grund' => '', 'bisher' => round( $bisher, 2 ),
                 'quartale' => $fertig, 'erwartet' => round( $bisher / $fertig * 4, 2 ) ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  QUARTALSABRECHNUNGEN — eine Abfrage, ein Durchlauf
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Alle Posten eines Jahres im Rahmen, mit ihrer Abrechnung.
     *
     * LEFT JOIN und nicht JOIN: Eine Abrechnung ohne Posten ist eine
     * Aussage („angefangen, nichts drin") und darf nicht verschwinden —
     * sonst sieht niemand den leeren Entwurf.
     */
    private static function posten_roh( $r, $jahr ) {
        global $wpdb;
        [ $wo, $args ] = self::wo( $r, $jahr, 'a' );
        $sql = "SELECT a.id AS abr_id, a.wp_user_id, a.quartal, a.status, a.abrechnungsart,
                       a.nachtrag_zu, a.rueckgabe_grund,
                       p.typ, p.datum, p.betrag, p.menge, p.menge_plan, p.tage,
                       p.mannschaft_id, p.beanstandet, p.quelle, p.wartezeit
                  FROM " . self::t( 'lsv07a_abrechnung' ) . " a
             LEFT JOIN " . self::t( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
                 WHERE $wo";
        return $wpdb->get_results( self::q( $sql, $args ), ARRAY_A ) ?: [];
    }

    /** Mannschaft → Abteilung, in einer Abfrage. */
    private static function abteilung_je_mannschaft() {
        global $wpdb;
        $zeilen = $wpdb->get_results(
            "SELECT mannschaft_id, abteilung_id FROM " . self::t( 'lsv07a_abteilung_mannschaft' ) . "
              WHERE quelle = 'intern'", ARRAY_A ) ?: [];
        $out = [];
        foreach ( $zeilen as $z ) $out[ (int) $z['mannschaft_id'] ] = (int) $z['abteilung_id'];
        return $out;
    }

    /**
     * Alles über die Quartalsabrechnungen eines Jahres.
     *
     * Ein Durchlauf über die Posten füllt jede Auswertung gleichzeitig.
     * Wer eine weitere braucht, hängt sie in dieselbe Schleife — nicht in
     * eine neue Abfrage.
     */
    public static function abrechnungen( $r, $jahr ) {
        $zeilen = self::posten_roh( $r, $jahr );
        $abt_von_mann = self::abteilung_je_mannschaft();
        $km_hin_rueck = (int) LSV07A_DB::config( 'km_hin_rueck', '1' ) === 1;

        $typen = LSV07A_DB::POSTEN_TYPEN;
        $std_typen = [ 'training', 'vorbereitung' ];

        $quartale = [];
        foreach ( [ 'Q1','Q2','Q3','Q4' ] as $q ) {
            $quartale[ $q ] = [ 'quartal' => $q, 'status' => 'offen',
                                'status_name' => 'Noch nichts erfasst',
                                'gesamt' => 0.0, 'anzahl' => 0,
                                'nach_typ' => array_fill_keys( $typen, 0.0 ) ];
        }

        $nach_typ    = array_fill_keys( $typen, 0.0 );
        $nach_status = array_fill_keys( LSV07A_DB::STATUS, 0.0 );
        $nach_monat  = array_fill( 1, 12, 0.0 );
        $nach_wtag   = [];
        foreach ( range( 1, 7 ) as $t ) $nach_wtag[ $t ] = [ 'betrag' => 0.0, 'stunden' => 0.0, 'anzahl' => 0 ];
        $nach_art    = [];
        $nach_quelle = [];
        $nach_mann   = [];
        $nach_abt    = [];
        $personen    = [];
        $beanstandet = [ 'anzahl' => 0, 'betrag' => 0.0, 'nach_typ' => array_fill_keys( $typen, 0.0 ) ];
        $plan        = [ 'anzahl' => 0, 'abweichend' => 0, 'ist' => 0.0, 'plan' => 0.0 ];
        $mengen      = [ 'stunden' => 0.0, 'abschnitte' => 0.0, 'wettkaempfe' => 0,
                         'fahrten' => 0, 'km' => 0.0, 'fahrten_ohne_geld' => 0,
                         'wartezeit_posten' => 0, 'wartezeit_betrag' => 0.0,
                         'km_hin_rueck' => $km_hin_rueck ];
        $abrechnungen = [];   // abr_id => status/quartal/person — für Zählungen
        $gesamt  = 0.0;
        $stunden = 0.0;

        foreach ( $zeilen as $z ) {
            $aid = (int) $z['abr_id'];
            $q   = (string) $z['quartal'];
            $uid = (int) $z['wp_user_id'];

            if ( ! isset( $abrechnungen[ $aid ] ) ) {
                $abrechnungen[ $aid ] = [ 'status' => $z['status'], 'quartal' => $q,
                                          'wp_user_id' => $uid,
                                          'nachtrag' => (int) $z['nachtrag_zu'] > 0 ];
                if ( isset( $quartale[ $q ] ) ) {
                    $quartale[ $q ]['anzahl']++;
                    $quartale[ $q ]['status']      = $z['status'];
                    $quartale[ $q ]['status_name'] = LSV07A_Berechnung::status_name( $z['status'] );
                }
            }
            if ( ! isset( $personen[ $uid ] ) ) {
                $u = get_userdata( $uid );
                $personen[ $uid ] = [ 'wp_user_id' => $uid,
                    'name' => $u ? $u->display_name : ( 'Konto ' . $uid ),
                    'gesamt' => 0.0, 'stunden' => 0.0, 'anzahl' => 0,
                    'nach_typ' => array_fill_keys( $typen, 0.0 ), 'quartale' => [] ];
            }
            $personen[ $uid ]['quartale'][ $q ] = LSV07A_Berechnung::status_name( $z['status'] );

            // Abrechnung ohne Posten: gezählt, aber es gibt nichts zu summieren.
            if ( ! $z['typ'] ) continue;

            $typ    = (string) $z['typ'];
            $betrag = (float) $z['betrag'];
            $menge  = (float) $z['menge'];
            $mid    = (int) $z['mannschaft_id'];
            $bekannt = in_array( $typ, $typen, true );

            $gesamt += $betrag;
            if ( $bekannt ) {
                $nach_typ[ $typ ] += $betrag;
                if ( isset( $quartale[ $q ] ) ) $quartale[ $q ]['nach_typ'][ $typ ] += $betrag;
                $personen[ $uid ]['nach_typ'][ $typ ] += $betrag;
            }
            if ( isset( $quartale[ $q ] ) ) $quartale[ $q ]['gesamt'] += $betrag;
            if ( isset( $nach_status[ $z['status'] ] ) ) $nach_status[ $z['status'] ] += $betrag;
            $personen[ $uid ]['gesamt'] += $betrag;
            $personen[ $uid ]['anzahl']++;

            // Monat und Wochentag aus dem Datum des Postens
            $m = self::monat_von( $z['datum'] );
            if ( $m ) $nach_monat[ $m ] += $betrag;

            // Abrechnungsart und Quelle
            $art = (string) $z['abrechnungsart'];
            if ( ! isset( $nach_art[ $art ] ) ) $nach_art[ $art ] = [ 'betrag' => 0.0, 'anzahl' => 0 ];
            $nach_art[ $art ]['betrag'] += $betrag;
            $nach_art[ $art ]['anzahl']++;

            $quelle = (string) ( $z['quelle'] ?: 'manuell' );
            if ( ! isset( $nach_quelle[ $quelle ] ) ) $nach_quelle[ $quelle ] = [ 'betrag' => 0.0, 'anzahl' => 0 ];
            $nach_quelle[ $quelle ]['betrag'] += $betrag;
            $nach_quelle[ $quelle ]['anzahl']++;

            // Mannschaft (und daraus die Abteilung)
            if ( ! isset( $nach_mann[ $mid ] ) ) {
                $nach_mann[ $mid ] = [ 'mannschaft_id' => $mid, 'betrag' => 0.0,
                                       'betrag_std' => 0.0, 'stunden' => 0.0, 'anzahl' => 0 ];
            }
            $nach_mann[ $mid ]['betrag'] += $betrag;
            $nach_mann[ $mid ]['anzahl']++;

            $aid_abt = $mid > 0 ? (int) ( $abt_von_mann[ $mid ] ?? 0 ) : 0;
            if ( ! isset( $nach_abt[ $aid_abt ] ) ) {
                $nach_abt[ $aid_abt ] = [ 'abteilung_id' => $aid_abt, 'betrag' => 0.0, 'anzahl' => 0 ];
            }
            $nach_abt[ $aid_abt ]['betrag'] += $betrag;
            $nach_abt[ $aid_abt ]['anzahl']++;

            // Stunden, und damit Wochentag und Euro je Stunde
            if ( in_array( $typ, $std_typen, true ) ) {
                $stunden += $menge;
                $mengen['stunden'] += $menge;
                $personen[ $uid ]['stunden'] += $menge;
                $nach_mann[ $mid ]['stunden']    += $menge;
                $nach_mann[ $mid ]['betrag_std'] += $betrag;
                $wt = LSV07A_Berechnung::wochentag( $z['datum'] );
                if ( isset( $nach_wtag[ $wt ] ) ) {
                    $nach_wtag[ $wt ]['betrag']  += $betrag;
                    $nach_wtag[ $wt ]['stunden'] += $menge;
                    $nach_wtag[ $wt ]['anzahl']++;
                }
            }

            // Mengen je Art
            if ( $typ === 'wettkampf' ) {
                $mengen['abschnitte'] += $menge;
                $mengen['wettkaempfe']++;
            }
            if ( $typ === 'fahrt' ) {
                $mengen['fahrten']++;
                $mengen['km'] += $menge * max( 1, (int) $z['tage'] ) * ( $km_hin_rueck ? 2 : 1 );
                /* 0 € bei einer Fahrt heisst: unter der Mindeststrecke.
                   Das erklärt Rückfragen, bevor sie kommen. */
                if ( $betrag <= 0 ) $mengen['fahrten_ohne_geld']++;
            }
            /* Der Zuschlag selbst steht nirgends getrennt in der Datenbank —
               nur das Merkmal, dass Wartezeit angerechnet wurde. Darum
               Anzahl und Betrag DER POSTEN, nicht die Summe des Zuschlags.
               Eine Zahl behaupten, die es nicht gibt, wäre schlimmer als
               keine. */
            if ( ! empty( $z['wartezeit'] ) ) {
                $mengen['wartezeit_posten']++;
                $mengen['wartezeit_betrag'] += $betrag;
            }

            // Beanstandete Posten
            if ( ! empty( $z['beanstandet'] ) ) {
                $beanstandet['anzahl']++;
                $beanstandet['betrag'] += $betrag;
                if ( $bekannt ) $beanstandet['nach_typ'][ $typ ] += $betrag;
            }

            // Plan gegen Ist — nur dort, wo es einen Plan gibt
            if ( $z['menge_plan'] !== null && $z['menge_plan'] !== '' ) {
                $pl = (float) $z['menge_plan'];
                $plan['anzahl']++;
                $plan['ist']  += $menge;
                $plan['plan'] += $pl;
                if ( abs( $pl - $menge ) > 0.001 ) $plan['abweichend']++;
            }
        }

        // ── Nachbereiten ──────────────────────────────────────────────
        foreach ( $quartale as &$qq ) {
            $qq['gesamt'] = round( $qq['gesamt'], 2 );
            foreach ( $qq['nach_typ'] as $t => $v ) $qq['nach_typ'][ $t ] = round( $v, 2 );
        }
        unset( $qq );

        $namen_mann = [];
        foreach ( LSV07A_Intern::mannschaften() as $m ) $namen_mann[ (int) $m['id'] ] = $m['name'];

        $mann_liste = [];
        foreach ( $nach_mann as $mid => $m ) {
            $m['name'] = $mid === 0
                ? 'Ohne Mannschaft (Fahrten, Wettkämpfe, Sonstiges)'
                : ( $namen_mann[ $mid ] ?? 'Mannschaft ' . $mid . ' (gelöscht)' );
            $m['betrag']  = round( $m['betrag'], 2 );
            $m['stunden'] = round( $m['stunden'], 2 );
            /* Euro je Stunde nur aus Training und Vorbereitung — eine Fahrt
               hat keine Stunden, sie würde den Wert verfälschen. */
            $m['eur_std'] = $m['stunden'] > 0 ? round( $m['betrag_std'] / $m['stunden'], 2 ) : null;
            unset( $m['betrag_std'] );
            $mann_liste[] = $m;
        }
        usort( $mann_liste, fn( $a, $b ) => $b['betrag'] <=> $a['betrag'] );

        $abt_liste = [];
        foreach ( $nach_abt as $aid2 => $a2 ) {
            $a2['name']   = $aid2 === 0 ? 'Ohne Abteilung' : LSV07A_Abteilung::name( $aid2 );
            $a2['betrag'] = round( $a2['betrag'], 2 );
            $abt_liste[] = $a2;
        }
        usort( $abt_liste, fn( $a, $b ) => $b['betrag'] <=> $a['betrag'] );

        foreach ( $personen as &$pp ) {
            $pp['gesamt']  = round( $pp['gesamt'], 2 );
            $pp['stunden'] = round( $pp['stunden'], 2 );
            foreach ( $pp['nach_typ'] as $t => $v ) $pp['nach_typ'][ $t ] = round( $v, 2 );
        }
        /* Ohne dieses unset bleibt $pp eine Referenz auf den letzten
           Eintrag — die nächste Schleife mit derselben Variable würde ihn
           still überschreiben. */
        unset( $pp );
        $pers_liste = array_values( $personen );
        usort( $pers_liste, fn( $a, $b ) => $b['gesamt'] <=> $a['gesamt'] );

        foreach ( $nach_typ as $t => $v )    $nach_typ[ $t ] = round( $v, 2 );
        foreach ( $nach_status as $s => $v ) $nach_status[ $s ] = round( $v, 2 );
        foreach ( $nach_monat as $m => $v )  $nach_monat[ $m ] = round( $v, 2 );
        foreach ( $nach_wtag as $t => $v ) {
            $nach_wtag[ $t ]['betrag']  = round( $v['betrag'], 2 );
            $nach_wtag[ $t ]['stunden'] = round( $v['stunden'], 2 );
            $nach_wtag[ $t ]['name']    = LSV07A_Berechnung::wochentag_name( $t );
        }
        foreach ( $nach_art as $a3 => $v )    $nach_art[ $a3 ]['betrag'] = round( $v['betrag'], 2 );
        foreach ( $nach_quelle as $a4 => $v ) $nach_quelle[ $a4 ]['betrag'] = round( $v['betrag'], 2 );

        $mengen['stunden']          = round( $mengen['stunden'], 2 );
        $mengen['abschnitte']       = round( $mengen['abschnitte'], 2 );
        $mengen['km']               = round( $mengen['km'], 2 );
        $mengen['wartezeit_betrag'] = round( $mengen['wartezeit_betrag'], 2 );
        $beanstandet['betrag']      = round( $beanstandet['betrag'], 2 );
        foreach ( $beanstandet['nach_typ'] as $t => $v ) $beanstandet['nach_typ'][ $t ] = round( $v, 2 );
        $plan['ist']  = round( $plan['ist'], 2 );
        $plan['plan'] = round( $plan['plan'], 2 );
        $plan['differenz'] = round( $plan['ist'] - $plan['plan'], 2 );

        $je_quartal = [];
        foreach ( $quartale as $name => $daten ) $je_quartal[ $name ] = $daten['gesamt'];

        /* Gerade zurückgegeben — der STAND VON HEUTE, nicht eine Quote
           über die Zeit. `rueckgabe_grund` wird beim erneuten Einreichen
           geleert, und aus dem Protokoll wird hier nichts gerechnet; eine
           Quote „wie oft kam es vor" liesse sich also nur erfinden. */
        $zurueck = [ 'anzahl' => 0, 'je_quartal' => array_fill_keys( [ 'Q1','Q2','Q3','Q4' ], 0 ) ];
        $anzahl_abr = 0; $nachtraege = 0;
        foreach ( $abrechnungen as $a5 ) {
            $anzahl_abr++;
            if ( $a5['nachtrag'] ) $nachtraege++;
            if ( $a5['status'] === 'zurueck' ) {
                $zurueck['anzahl']++;
                if ( isset( $zurueck['je_quartal'][ $a5['quartal'] ] ) ) $zurueck['je_quartal'][ $a5['quartal'] ]++;
            }
        }
        $zurueck['anteil'] = $anzahl_abr > 0 ? round( $zurueck['anzahl'] / $anzahl_abr * 100, 1 ) : null;

        return [
            'jahr'        => (int) $jahr,
            'gesamt'      => round( $gesamt, 2 ),
            'stunden'     => round( $stunden, 2 ),
            'anzahl'      => $anzahl_abr,
            'nachtraege'  => $nachtraege,
            'quartale'    => array_values( $quartale ),
            'je_quartal'  => $je_quartal,
            'nach_typ'    => $nach_typ,
            'nach_status' => $nach_status,
            'nach_monat'  => $nach_monat,
            'nach_wochentag'  => $nach_wtag,
            'nach_art'    => $nach_art,
            'nach_quelle' => $nach_quelle,
            'nach_mannschaft' => $mann_liste,
            'nach_abteilung'  => $abt_liste,
            'personen'    => $pers_liste,
            'beanstandet' => $beanstandet,
            'plan_ist'    => $plan,
            'mengen'      => $mengen,
            'zurueck'     => $zurueck,
            'vorjahr'     => self::jahr_vergleich( $r, (int) $jahr - 1 ),
            'hochrechnung'=> self::hochrechnung( (int) $jahr, $je_quartal ),
        ];
    }

    /** Summe und Quartalssummen eines Jahres — für den Vorjahresvergleich. */
    private static function jahr_vergleich( $r, $jahr ) {
        global $wpdb;
        [ $wo, $args ] = self::wo( $r, $jahr, 'a' );
        $zeilen = $wpdb->get_results( self::q(
            "SELECT a.quartal, COALESCE(SUM(p.betrag),0) AS betrag
               FROM " . self::t( 'lsv07a_abrechnung' ) . " a
          LEFT JOIN " . self::t( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
              WHERE $wo
           GROUP BY a.quartal", $args ), ARRAY_A ) ?: [];
        $q = self::leere_quartale(); $summe = 0.0;
        foreach ( $zeilen as $z ) {
            $b = round( (float) $z['betrag'], 2 );
            if ( isset( $q[ $z['quartal'] ] ) ) $q[ $z['quartal'] ] = $b;
            $summe += $b;
        }
        return [ 'jahr' => (int) $jahr, 'gesamt' => round( $summe, 2 ), 'quartale' => $q ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  RECHNUNGEN — der zweite Geldfluss
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Alles über die Rechnungen eines Jahres.
     *
     * Gibt null zurück, wenn der Rahmen keine Rechnungen zeigt — dann
     * fehlt der Block in der Oberfläche ganz, statt mit Nullen dazustehen.
     */
    public static function rechnungen( $r, $jahr ) {
        if ( empty( $r['rechnungen'] ) ) return null;
        global $wpdb;

        [ $wo, $args ] = self::wo( $r, $jahr, 'r' );
        /* Die Belege kommen als Zählung mit, nicht als zweite Abfrage je
           Rechnung. Gezählt wird in einer Unterabfrage, damit eine
           Rechnung mit drei Belegen nicht dreifach in der Summe landet. */
        $zeilen = $wpdb->get_results( self::q(
            "SELECT r.id, r.wp_user_id, r.abteilung_id, r.zweck, r.betrag, r.status,
                    r.rechnungsdatum, r.erstellt_am, r.empf_eigen,
                    r.eingereicht_am, r.genehmigt_am, r.bezahlt_am,
                    (SELECT COUNT(*) FROM " . self::t( 'lsv07a_rechnung_datei' ) . " d
                      WHERE d.rechnung_id = r.id) AS belege,
                    (SELECT COALESCE(SUM(d2.groesse),0) FROM " . self::t( 'lsv07a_rechnung_datei' ) . " d2
                      WHERE d2.rechnung_id = r.id) AS belege_bytes
               FROM " . self::t( 'lsv07a_rechnung' ) . " r
              WHERE $wo", $args ), ARRAY_A ) ?: [];

        $nach_status = array_fill_keys( LSV07A_DB::STATUS, 0.0 );
        $anz_status  = array_fill_keys( LSV07A_DB::STATUS, 0 );
        $nach_monat  = array_fill( 1, 12, 0.0 );
        $bezahlt_monat = array_fill( 1, 12, 0.0 );
        $nach_abt = []; $nach_zweck = []; $personen = [];
        $empf = [ 'eigen' => [ 'betrag' => 0.0, 'anzahl' => 0 ],
                  'dritte' => [ 'betrag' => 0.0, 'anzahl' => 0 ] ];
        $ohne_beleg = [ 'anzahl' => 0, 'betrag' => 0.0, 'nach_status' => array_fill_keys( LSV07A_DB::STATUS, 0 ) ];
        $belege = [ 'anzahl' => 0, 'bytes' => 0 ];
        $gesamt = 0.0;

        foreach ( $zeilen as $z ) {
            $betrag = (float) $z['betrag'];
            $st     = (string) $z['status'];
            $gesamt += $betrag;
            if ( isset( $nach_status[ $st ] ) ) { $nach_status[ $st ] += $betrag; $anz_status[ $st ]++; }

            /* Monat aus dem Rechnungsdatum — das ist der Monat, in den die
               Ausgabe gehört. Fehlt es (Entwurf), tritt das Anlagedatum
               ein, damit die Zeile nicht aus der Auswertung fällt. */
            $m = self::monat_von( $z['rechnungsdatum'] ?: $z['erstellt_am'] );
            if ( $m ) $nach_monat[ $m ] += $betrag;
            // Was wirklich rausging, und wann
            $mb = self::monat_von( $z['bezahlt_am'] );
            if ( $mb && $st === 'bezahlt' ) $bezahlt_monat[ $mb ] += $betrag;

            $abt = (int) $z['abteilung_id'];
            if ( ! isset( $nach_abt[ $abt ] ) ) $nach_abt[ $abt ] = [ 'abteilung_id' => $abt, 'betrag' => 0.0, 'anzahl' => 0 ];
            $nach_abt[ $abt ]['betrag'] += $betrag;
            $nach_abt[ $abt ]['anzahl']++;

            $zw = trim( (string) $z['zweck'] ) !== '' ? trim( (string) $z['zweck'] ) : '(ohne Zweck)';
            if ( ! isset( $nach_zweck[ $zw ] ) ) $nach_zweck[ $zw ] = [ 'zweck' => $zw, 'betrag' => 0.0, 'anzahl' => 0 ];
            $nach_zweck[ $zw ]['betrag'] += $betrag;
            $nach_zweck[ $zw ]['anzahl']++;

            $uid = (int) $z['wp_user_id'];
            if ( ! isset( $personen[ $uid ] ) ) {
                $u = get_userdata( $uid );
                $personen[ $uid ] = [ 'wp_user_id' => $uid,
                    'name' => $u ? $u->display_name : ( 'Konto ' . $uid ),
                    'betrag' => 0.0, 'anzahl' => 0 ];
            }
            $personen[ $uid ]['betrag'] += $betrag;
            $personen[ $uid ]['anzahl']++;

            $k = ! empty( $z['empf_eigen'] ) ? 'eigen' : 'dritte';
            $empf[ $k ]['betrag'] += $betrag;
            $empf[ $k ]['anzahl']++;

            $belege['anzahl'] += (int) $z['belege'];
            $belege['bytes']  += (int) $z['belege_bytes'];
            if ( (int) $z['belege'] === 0 ) {
                $ohne_beleg['anzahl']++;
                $ohne_beleg['betrag'] += $betrag;
                if ( isset( $ohne_beleg['nach_status'][ $st ] ) ) $ohne_beleg['nach_status'][ $st ]++;
            }
        }

        foreach ( $nach_status as $s => $v ) $nach_status[ $s ] = round( $v, 2 );
        foreach ( $nach_monat as $m => $v )  $nach_monat[ $m ] = round( $v, 2 );
        foreach ( $bezahlt_monat as $m => $v ) $bezahlt_monat[ $m ] = round( $v, 2 );
        foreach ( $empf as $k => $v )        $empf[ $k ]['betrag'] = round( $v['betrag'], 2 );
        $ohne_beleg['betrag'] = round( $ohne_beleg['betrag'], 2 );

        $abt_liste = [];
        foreach ( $nach_abt as $id => $a ) {
            $a['name']   = $id === 0 ? 'Ohne Abteilung' : LSV07A_Abteilung::name( $id );
            $a['betrag'] = round( $a['betrag'], 2 );
            $abt_liste[] = $a;
        }
        usort( $abt_liste, fn( $a, $b ) => $b['betrag'] <=> $a['betrag'] );

        $zweck_liste = [];
        foreach ( $nach_zweck as $z2 ) { $z2['betrag'] = round( $z2['betrag'], 2 ); $zweck_liste[] = $z2; }
        usort( $zweck_liste, fn( $a, $b ) => $b['betrag'] <=> $a['betrag'] );

        $pers_liste = [];
        foreach ( $personen as $p ) { $p['betrag'] = round( $p['betrag'], 2 ); $pers_liste[] = $p; }
        usort( $pers_liste, fn( $a, $b ) => $b['betrag'] <=> $a['betrag'] );

        // Quartale aus den Monaten — die Tabelle kennt nur das Jahr.
        $je_quartal = self::leere_quartale();
        foreach ( $nach_monat as $m => $v ) {
            $q = self::quartal_von_monat( $m );
            if ( $q ) $je_quartal[ $q ] = round( $je_quartal[ $q ] + $v, 2 );
        }

        return [
            'jahr'        => (int) $jahr,
            'gesamt'      => round( $gesamt, 2 ),
            'anzahl'      => count( $zeilen ),
            'nach_status' => $nach_status,
            'anzahl_status'=> $anz_status,
            'nach_monat'  => $nach_monat,
            'bezahlt_monat' => $bezahlt_monat,
            'nach_abteilung' => $abt_liste,
            'nach_zweck'  => $zweck_liste,
            'personen'    => $pers_liste,
            'empfaenger'  => $empf,
            'ohne_beleg'  => $ohne_beleg,
            'belege'      => $belege,
            'je_quartal'  => $je_quartal,
            'vorjahr'     => self::rechnungen_jahr( $r, (int) $jahr - 1 ),
            'hochrechnung'=> self::hochrechnung( (int) $jahr, $je_quartal ),
        ];
    }

    /** Nur Summe und Quartale eines Rechnungsjahres — für den Vergleich. */
    private static function rechnungen_jahr( $r, $jahr ) {
        global $wpdb;
        [ $wo, $args ] = self::wo( $r, $jahr, 'r' );
        $zeilen = $wpdb->get_results( self::q(
            "SELECT r.betrag, r.rechnungsdatum, r.erstellt_am
               FROM " . self::t( 'lsv07a_rechnung' ) . " r
              WHERE $wo", $args ), ARRAY_A ) ?: [];
        $q = self::leere_quartale(); $summe = 0.0;
        foreach ( $zeilen as $z ) {
            $b = (float) $z['betrag'];
            $summe += $b;
            $qn = self::quartal_von_monat( self::monat_von( $z['rechnungsdatum'] ?: $z['erstellt_am'] ) );
            if ( $qn ) $q[ $qn ] += $b;
        }
        foreach ( $q as $k => $v ) $q[ $k ] = round( $v, 2 );
        return [ 'jahr' => (int) $jahr, 'gesamt' => round( $summe, 2 ), 'quartale' => $q ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ZEIT — wie lange dauert es, und was liegt
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Liegezeiten und offene Stufen.
     *
     * BEWUSST OHNE JAHRESFILTER. „Der älteste offene Vorgang" ist nur dann
     * eine Warnung, wenn ein Fall von vorletztem Jahr auch auftaucht —
     * genau der ist das Problem. Die Liegezeiten dagegen werden am Jahr
     * der Einreichung gemessen, sonst vermischt sich alte und neue Praxis;
     * dafür nimmt `$jahr` teil.
     */
    public static function durchlauf( $r, $jahr ) {
        global $wpdb;
        $aus = [];

        // ── Quartalsabrechnungen ─────────────────────────────────────
        [ $wo_a, $args_a ] = self::wo( $r, 0, 'a' );
        $abr = $wpdb->get_results( self::q(
            "SELECT a.id, a.jahr, a.status, a.eingereicht_am, a.genehmigt_am, a.bezahlt_am,
                    COALESCE(s.betrag,0) AS betrag
               FROM " . self::t( 'lsv07a_abrechnung' ) . " a
          LEFT JOIN (SELECT abrechnung_id, SUM(betrag) AS betrag
                       FROM " . self::t( 'lsv07a_posten' ) . " GROUP BY abrechnung_id) s
                    ON s.abrechnung_id = a.id
              WHERE $wo_a", $args_a ), ARRAY_A ) ?: [];
        $aus['abrechnung'] = self::durchlauf_rechnen( $abr, $jahr );

        // ── Rechnungen ───────────────────────────────────────────────
        if ( ! empty( $r['rechnungen'] ) ) {
            [ $wo_r, $args_r ] = self::wo( $r, 0, 'r' );
            $rg = $wpdb->get_results( self::q(
                "SELECT r.id, r.jahr, r.status, r.betrag,
                        r.eingereicht_am, r.genehmigt_am, r.bezahlt_am
                   FROM " . self::t( 'lsv07a_rechnung' ) . " r
                  WHERE $wo_r", $args_r ), ARRAY_A ) ?: [];
            $aus['rechnung'] = self::durchlauf_rechnen( $rg, $jahr );
        } else {
            $aus['rechnung'] = null;
        }
        return $aus;
    }

    /** Dieselbe Rechnung für beide Vorgangsarten — eine Stelle. */
    private static function durchlauf_rechnen( array $zeilen, $jahr ) {
        $heute = current_time( 'mysql' );
        $pruef = []; $zahl = []; $ganz = [];
        $offen = [
            'eingereicht' => [ 'anzahl' => 0, 'betrag' => 0.0, 'aeltester_tage' => null, 'aeltester_am' => null ],
            'genehmigt'   => [ 'anzahl' => 0, 'betrag' => 0.0, 'aeltester_tage' => null, 'aeltester_am' => null ],
            'zurueck'     => [ 'anzahl' => 0, 'betrag' => 0.0, 'aeltester_tage' => null, 'aeltester_am' => null ],
            'entwurf'     => [ 'anzahl' => 0, 'betrag' => 0.0, 'aeltester_tage' => null, 'aeltester_am' => null ],
        ];

        foreach ( $zeilen as $z ) {
            $betrag = (float) $z['betrag'];
            $st     = (string) $z['status'];

            // Liegezeiten: am Jahr der Einreichung gemessen
            if ( ! $jahr || (int) $z['jahr'] === (int) $jahr ) {
                $t1 = self::tage_zwischen( $z['eingereicht_am'], $z['genehmigt_am'] );
                $t2 = self::tage_zwischen( $z['genehmigt_am'], $z['bezahlt_am'] );
                $t3 = self::tage_zwischen( $z['eingereicht_am'], $z['bezahlt_am'] );
                if ( $t1 !== null ) $pruef[] = max( 0, $t1 );
                if ( $t2 !== null ) $zahl[]  = max( 0, $t2 );
                if ( $t3 !== null ) $ganz[]  = max( 0, $t3 );
            }

            // Offene Stufen: Stand heute, über alle Jahre
            if ( ! isset( $offen[ $st ] ) ) continue;
            $seit = $st === 'eingereicht' ? $z['eingereicht_am']
                  : ( $st === 'genehmigt' ? $z['genehmigt_am'] : $z['eingereicht_am'] );
            $offen[ $st ]['anzahl']++;
            $offen[ $st ]['betrag'] += $betrag;
            $tage = self::tage_zwischen( $seit, $heute );
            if ( $tage !== null && ( $offen[ $st ]['aeltester_tage'] === null
                                     || $tage > $offen[ $st ]['aeltester_tage'] ) ) {
                $offen[ $st ]['aeltester_tage'] = max( 0, $tage );
                $offen[ $st ]['aeltester_am']   = substr( (string) $seit, 0, 10 );
            }
        }
        foreach ( $offen as $k => $v ) $offen[ $k ]['betrag'] = round( $v['betrag'], 2 );

        return [
            'pruefung' => [ 'median' => self::median( $pruef ), 'anzahl' => count( $pruef ) ],
            'zahlung'  => [ 'median' => self::median( $zahl ),  'anzahl' => count( $zahl ) ],
            'gesamt'   => [ 'median' => self::median( $ganz ),  'anzahl' => count( $ganz ) ],
            'offen'    => $offen,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  RÜCKSTAU — was liegengeblieben ist
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Wer im Rahmen für ein Quartal des Jahres nichts hat.
     *
     * Grundmenge sind die aktiven Konten mit Trainerrolle — ein Konto ohne
     * diese Rolle hat keine Quartalsabrechnung und fehlt daher auch nicht.
     * Namen stehen nur in den Sichten „Abteilung" und „alle"; in der
     * eigenen Sicht ist es ohnehin das eigene Konto.
     */
    public static function rueckstau( $r, $jahr ) {
        global $wpdb;

        // Grundmenge: aktive Trainerkonten im Rahmen
        $soll = [];
        foreach ( LSV07A_Rollen::konten() as $k ) {
            if ( ! in_array( 'trainer', (array) $k['rollen'], true ) ) continue;
            if ( empty( $k['aktiv'] ) ) continue;
            if ( is_array( $r['personen'] ) && ! in_array( (int) $k['wp_user_id'], $r['personen'], true ) ) continue;
            $soll[ (int) $k['wp_user_id'] ] = $k['name'];
        }

        [ $wo, $args ] = self::wo( $r, $jahr, 'a' );
        $da = $wpdb->get_results( self::q(
            "SELECT a.wp_user_id, a.quartal FROM " . self::t( 'lsv07a_abrechnung' ) . " a
              WHERE $wo", $args ), ARRAY_A ) ?: [];
        $hat = [];
        foreach ( $da as $z ) $hat[ (int) $z['wp_user_id'] ][ (string) $z['quartal'] ] = true;

        $fehlt = [];
        foreach ( [ 'Q1','Q2','Q3','Q4' ] as $q ) {
            $namen = [];
            foreach ( $soll as $uid => $name ) {
                if ( empty( $hat[ $uid ][ $q ] ) ) $namen[] = [ 'wp_user_id' => $uid, 'name' => $name ];
            }
            $fehlt[ $q ] = [ 'quartal' => $q, 'anzahl' => count( $namen ),
                             'personen' => ! empty( $r['personen_zeigen'] ) ? $namen : [] ];
        }

        // Entwürfe: Geld, das erfasst, aber nicht eingereicht ist
        $entwuerfe = $wpdb->get_row( self::q(
            "SELECT COUNT(DISTINCT a.id) AS anzahl, COALESCE(SUM(p.betrag),0) AS betrag
               FROM " . self::t( 'lsv07a_abrechnung' ) . " a
          LEFT JOIN " . self::t( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
              WHERE $wo AND a.status = 'entwurf'", $args ), ARRAY_A ) ?: [];

        $rg_entwuerfe = null;
        if ( ! empty( $r['rechnungen'] ) ) {
            [ $wo_r, $args_r ] = self::wo( $r, $jahr, 'r' );
            $rg_entwuerfe = $wpdb->get_row( self::q(
                "SELECT COUNT(*) AS anzahl, COALESCE(SUM(r.betrag),0) AS betrag
                   FROM " . self::t( 'lsv07a_rechnung' ) . " r
                  WHERE $wo_r AND r.status = 'entwurf'", $args_r ), ARRAY_A ) ?: [];
        }

        /* Bewusst entfernte Übernahmen. Eine Zahl, die eine Abfrage
           kostet — anders als „noch nie übernommen", das je Trainer im
           internen Bereich nachgesehen werden müsste (siehe
           nicht_uebernommen()). */
        $ausgeschlossen = (int) $wpdb->get_var( self::q(
            "SELECT COUNT(*) FROM " . self::t( 'lsv07a_nicht_auto' ) . " n
               JOIN " . self::t( 'lsv07a_abrechnung' ) . " a ON a.id = n.abrechnung_id
              WHERE $wo", $args ) );

        return [
            'soll'      => count( $soll ),
            'fehlt'     => array_values( $fehlt ),
            'entwuerfe' => [ 'anzahl' => (int) ( $entwuerfe['anzahl'] ?? 0 ),
                             'betrag' => round( (float) ( $entwuerfe['betrag'] ?? 0 ), 2 ) ],
            'rechnung_entwuerfe' => $rg_entwuerfe === null ? null
                : [ 'anzahl' => (int) ( $rg_entwuerfe['anzahl'] ?? 0 ),
                    'betrag' => round( (float) ( $rg_entwuerfe['betrag'] ?? 0 ), 2 ) ],
            'ausgeschlossen' => $ausgeschlossen,
        ];
    }

    /**
     * Trainings und Wettkampftage, die im internen Bereich stehen, aber in
     * keiner Abrechnung des Jahres angekommen sind.
     *
     * NUR JE PERSON. Für den ganzen Verein müsste der interne Bereich je
     * Trainer einzeln befragt werden — das wären Dutzende Abfragen für
     * eine Zahl. Die Oberfläche sagt das auch, statt die Zahl weglassen
     * und niemandem erklären, warum sie fehlt.
     *
     * Drei Abfragen, unabhängig von der Anzahl der Trainings.
     */
    public static function nicht_uebernommen( $uid, $jahr ) {
        global $wpdb;
        $uid  = (int) $uid;
        $jahr = (int) $jahr;
        $leer = [ 'moeglich' => false, 'grund' => '', 'trainings' => 0, 'wettkampftage' => 0,
                  'ausgeschlossen' => 0 ];

        $trainer_id = LSV07A_Intern::trainer_id( $uid );
        if ( ! $trainer_id ) {
            $leer['grund'] = 'Dieses Konto ist im internen Bereich keinem Trainer zugeordnet.';
            return $leer;
        }

        // Was schon als Posten drin ist
        $drin = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ref_typ, p.ref_id
               FROM " . self::t( 'lsv07a_posten' ) . " p
               JOIN " . self::t( 'lsv07a_abrechnung' ) . " a ON a.id = p.abrechnung_id
              WHERE a.wp_user_id = %d AND a.jahr = %d AND p.ref_typ IS NOT NULL",
            $uid, $jahr ), ARRAY_A ) ?: [];
        $schon = [];
        foreach ( $drin as $d ) $schon[ $d['ref_typ'] . ':' . (int) $d['ref_id'] ] = true;

        // Was bewusst ausgeschlossen wurde
        $aus = $wpdb->get_results( $wpdb->prepare(
            "SELECT n.ref_typ, n.ref_id
               FROM " . self::t( 'lsv07a_nicht_auto' ) . " n
               JOIN " . self::t( 'lsv07a_abrechnung' ) . " a ON a.id = n.abrechnung_id
              WHERE a.wp_user_id = %d AND a.jahr = %d", $uid, $jahr ), ARRAY_A ) ?: [];
        $weg = [];
        foreach ( $aus as $d ) $weg[ $d['ref_typ'] . ':' . (int) $d['ref_id'] ] = true;

        $von = $jahr . '-01-01';
        $bis = $jahr . '-12-31';

        $t_offen = 0;
        foreach ( LSV07A_Intern::trainings( $trainer_id, $von, $bis ) as $t ) {
            $k = ( $t['ref_typ'] ?? '' ) . ':' . (int) ( $t['ref_id'] ?? 0 );
            if ( isset( $schon[ $k ] ) || isset( $weg[ $k ] ) ) continue;
            $t_offen++;
        }
        $w_offen = 0;
        foreach ( LSV07A_Intern::wettkaempfe( $trainer_id, $von, $bis ) as $w ) {
            $k = ( $w['ref_typ'] ?? '' ) . ':' . (int) ( $w['ref_id'] ?? 0 );
            if ( isset( $schon[ $k ] ) || isset( $weg[ $k ] ) ) continue;
            $w_offen++;
        }

        return [ 'moeglich' => true, 'grund' => '', 'trainings' => $t_offen,
                 'wettkampftage' => $w_offen, 'ausgeschlossen' => count( $weg ) ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  KENNZAHLEN JE ROLLE — vier Zahlen, oben auf der Seite
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Die vier Zahlen, die zu dieser Rolle gehören.
     *
     * Nicht alles für alle: Was eine Rolle nicht entscheidet, braucht sie
     * auch nicht als Kennzahl. Jede Kachel nennt ihre Einheit, damit „12"
     * nicht für Euro gehalten wird.
     */
    public static function kennzahlen( $r, $jahr, array $abr, $rg, array $durch, array $stau ) {
        $uid = (int) $r['uid'];
        $jahr = (int) $jahr;

        // Die Summe beider Geldflüsse — eine Stelle, damit sie überall gleich ist.
        $jahr_gesamt = round( $abr['gesamt'] + ( $rg ? $rg['gesamt'] : 0 ), 2 );
        $offen_abr   = $durch['abrechnung']['offen'];
        $offen_rg    = $rg && ! empty( $durch['rechnung'] ) ? $durch['rechnung']['offen'] : null;

        if ( $r['sicht'] === 'eigene' ) {
            $wartet_betrag = $offen_abr['genehmigt']['betrag']
                           + ( $offen_rg ? $offen_rg['genehmigt']['betrag'] : 0 );
            $wartet_tage   = max( (int) $offen_abr['genehmigt']['aeltester_tage'],
                                  $offen_rg ? (int) $offen_rg['genehmigt']['aeltester_tage'] : 0 );
            $entwurf = round( $stau['entwuerfe']['betrag']
                            + (float) ( $stau['rechnung_entwuerfe']['betrag'] ?? 0 ), 2 );
            return [
                self::kachel( 'Jahr ' . $jahr, 'eur', $jahr_gesamt,
                    'Quartalsabrechnung und Rechnungen zusammen' ),
                $abr['hochrechnung']['moeglich']
                    ? self::kachel( 'Erwartet fürs Jahr', 'eur', $abr['hochrechnung']['erwartet'],
                        'aus ' . (int) $abr['hochrechnung']['quartale'] . ' vollen Quartal(en)' )
                    : self::kachel( 'Erwartet fürs Jahr', 'eur', null, $abr['hochrechnung']['grund'] ),
                self::kachel( 'Noch im Entwurf', 'eur', $entwurf,
                    'erfasst, aber nicht eingereicht' ),
                self::kachel( 'Wartet auf Auszahlung', 'eur', round( $wartet_betrag, 2 ),
                    $wartet_tage > 0
                        ? 'genehmigt, der älteste seit ' . $wartet_tage . ' Tagen'
                        : 'nichts genehmigt und unbezahlt' ),
            ];
        }

        if ( $r['sicht'] === 'abteilung' ) {
            return [
                self::kachel( 'Abteilung ' . $jahr, 'eur', $jahr_gesamt,
                    'Quartalsabrechnung und Rechnungen zusammen' ),
                self::kachel( 'Zu genehmigen', 'eur',
                    $offen_rg ? $offen_rg['eingereicht']['betrag'] : 0.0,
                    ( $offen_rg ? (int) $offen_rg['eingereicht']['anzahl'] : 0 ) . ' Rechnung(en) eingereicht' ),
                self::kachel( 'Ältester offener', 'tage',
                    $offen_rg ? $offen_rg['eingereicht']['aeltester_tage'] : null,
                    'eingereicht und noch nicht entschieden' ),
                self::kachel( 'Rechnungen ohne Beleg', 'stueck',
                    $rg ? $rg['ohne_beleg']['anzahl'] : 0,
                    $rg ? self::eur_text( $rg['ohne_beleg']['betrag'] ) : '' ),
            ];
        }

        // Sicht "alle": Administration, Kasse, Wart — in dieser Reihenfolge,
        // weil ein Konto mehrere Rollen haben kann und die weitreichendste
        // Rolle bestimmt, was oben steht.
        if ( LSV07A_Rollen::ist_admin( $uid ) ) {
            return [
                self::kachel( 'Jahr ' . $jahr, 'eur', $jahr_gesamt,
                    'Abrechnung ' . self::eur_text( $abr['gesamt'] )
                    . ( $rg ? ' · Rechnungen ' . self::eur_text( $rg['gesamt'] ) : '' ) ),
                self::kachel( 'Prüfung dauert', 'tage', $durch['abrechnung']['pruefung']['median'],
                    'Median über ' . (int) $durch['abrechnung']['pruefung']['anzahl'] . ' Vorgänge' ),
                self::kachel( 'Auszahlung dauert', 'tage', $durch['abrechnung']['zahlung']['median'],
                    'Median ab Genehmigung' ),
                self::kachel( 'Gerade zurückgegeben', 'stueck', $abr['zurueck']['anzahl'],
                    $abr['zurueck']['anteil'] === null
                        ? 'keine Abrechnungen in diesem Jahr'
                        : $abr['zurueck']['anteil'] . ' % der Abrechnungen des Jahres' ),
            ];
        }

        if ( LSV07A_Rollen::ist_kasse( $uid ) ) {
            $zu_zahlen = $offen_abr['genehmigt']['betrag']
                       + ( $offen_rg ? $offen_rg['genehmigt']['betrag'] : 0 );
            $vorjahr   = $abr['vorjahr']['gesamt'] + ( $rg ? $rg['vorjahr']['gesamt'] : 0 );
            return [
                self::kachel( 'Zu zahlen', 'eur', round( $zu_zahlen, 2 ),
                    'genehmigt und noch nicht überwiesen' ),
                self::kachel( 'Ältester genehmigter', 'tage', $offen_abr['genehmigt']['aeltester_tage'],
                    'wartet auf die Überweisung' ),
                self::kachel( 'Jahr ' . $jahr, 'eur', $jahr_gesamt,
                    'Vorjahr ' . self::eur_text( $vorjahr ) ),
                self::kachel( 'Auszahlung dauert', 'tage', $durch['abrechnung']['zahlung']['median'],
                    'Median ab Genehmigung' ),
            ];
        }

        if ( LSV07A_Rollen::ist_wart( $uid ) ) {
            $fehlt_max = 0;
            foreach ( $stau['fehlt'] as $f ) $fehlt_max = max( $fehlt_max, (int) $f['anzahl'] );
            return [
                self::kachel( 'Zu prüfen', 'eur', $offen_abr['eingereicht']['betrag'],
                    (int) $offen_abr['eingereicht']['anzahl'] . ' Abrechnung(en) eingereicht' ),
                self::kachel( 'Ältester offener', 'tage', $offen_abr['eingereicht']['aeltester_tage'],
                    'eingereicht und noch nicht geprüft' ),
                self::kachel( 'Beanstandete Posten', 'stueck', $abr['beanstandet']['anzahl'],
                    self::eur_text( $abr['beanstandet']['betrag'] ) ),
                self::kachel( 'Ohne Abrechnung', 'stueck', $fehlt_max,
                    'im schwächsten Quartal, von ' . (int) $stau['soll'] . ' Trainerkonten' ),
            ];
        }

        // Zugang, aber keine entscheidende Rolle.
        return [ self::kachel( 'Jahr ' . $jahr, 'eur', $jahr_gesamt, '' ) ];
    }

    /**
     * Eine Kachel. `einheit` steht dabei, damit "12" nicht fuer Euro
     * gehalten wird; `wert` null heisst "gibt es nicht", und dann sagt
     * `sub`, warum.
     */
    private static function kachel( $lbl, $einheit, $wert, $sub = '' ) {
        return [ 'lbl' => (string) $lbl, 'einheit' => (string) $einheit,
                 'wert' => $wert === null ? null : ( $einheit === 'eur' ? round( (float) $wert, 2 )
                           : ( $einheit === 'tage' ? (int) $wert : (int) $wert ) ),
                 'sub' => (string) $sub ];
    }

    /** Kurzer Euro-Text fuer die Unterzeile einer Kachel. */
    private static function eur_text( $w ) {
        return number_format( (float) $w, 2, ',', '.' ) . ' €';
    }
}
