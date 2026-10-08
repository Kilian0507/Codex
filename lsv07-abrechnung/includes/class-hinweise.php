<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auffälligkeiten in einer Abrechnung.
 *
 * Der Wart prüft von Hand, und von Hand übersieht man Dinge: denselben
 * Tag zweimal, ein Datum aus dem falschen Quartal, einen Betrag, der
 * dreimal so hoch ist wie sonst. Diese Klasse sucht genau solche Muster
 * und schreibt dazu, was ihr aufgefallen ist.
 *
 * Drei Grundsätze:
 *
 *   1. **Nichts wird blockiert.** Ein Hinweis ist ein Hinweis. Es gibt
 *      gute Gründe für zwei Trainings an einem Tag und für ein teures
 *      Quartal. Entschieden wird vom Menschen.
 *   2. **Jeder Hinweis nennt die Zahlen.** „Auffällig" allein hilft
 *      niemandem; „312,00 € gegenüber sonst 130,00 €" schon.
 *   3. **Lieber schweigen als raten.** Wo die Vergleichsgrundlage fehlt
 *      (die erste Abrechnung einer Person), wird nichts gemeldet.
 */
class LSV07A_Hinweise {

    /* Ab wann gilt ein Quartal als „deutlich mehr als sonst": das
       Anderthalbfache des bisherigen Schnitts UND mindestens 50 € mehr.
       Der zweite Teil verhindert Meldungen bei Kleinbeträgen, wo sich
       das Vielfache schnell ergibt. */
    const FAKTOR    = 1.5;
    const MINDEST   = 50.0;
    /* Ein einzelner Posten fällt auf, wenn er den Löwenanteil ausmacht. */
    const ANTEIL    = 0.4;
    const POSTEN_AB = 100.0;

    /**
     * Alle Auffälligkeiten einer Abrechnung.
     *
     * Jeder Eintrag: stufe (hinweis|warnung), text, posten_id (0 = die
     * ganze Abrechnung betreffend).
     */
    public static function fuer( $abr ) {
        $abr_id = (int) ( is_array( $abr ) ? $abr['id'] : $abr );
        global $wpdb;
        $abr = is_array( $abr ) ? $abr : $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07a_abrechnung WHERE id = %d", $abr_id ), ARRAY_A );
        if ( ! $abr ) return [];

        $posten = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07a_posten WHERE abrechnung_id = %d
              ORDER BY datum ASC, id ASC", $abr_id ), ARRAY_A ) ?: [];

        $out = [];
        foreach ( [ 'doppelt', 'ausserhalb', 'grosser_posten', 'mehr_als_sonst' ] as $pruefung ) {
            foreach ( self::$pruefung( $abr, $posten ) as $h ) $out[] = $h;
        }
        return $out;
    }

    // ── Die einzelnen Prüfungen ──────────────────────────────────────────

    /** Derselbe Tag, dieselbe Art, dieselbe Bezeichnung — mehr als einmal. */
    private static function doppelt( $abr, $posten ) {
        $gruppen = [];
        foreach ( $posten as $p ) {
            $schluessel = $p['typ'] . '|' . $p['datum'] . '|' . mb_strtolower( trim( $p['bezeichnung'] ) );
            $gruppen[ $schluessel ][] = $p;
        }
        $out = [];
        foreach ( $gruppen as $zeilen ) {
            if ( count( $zeilen ) < 2 ) continue;
            $erst = $zeilen[0];
            $out[] = [
                'stufe'     => 'warnung',
                'posten_id' => (int) $zeilen[ count( $zeilen ) - 1 ]['id'],
                'text'      => count( $zeilen ) . '× am ' . self::datum( $erst['datum'] ) . ': „'
                             . ( trim( $erst['bezeichnung'] ) ?: LSV07A_Berechnung::typ_name( $erst['typ'] ) )
                             . '". Zusammen ' . self::eur( array_sum( array_column( $zeilen, 'betrag' ) ) )
                             . '. Bitte nachsehen, ob das doppelt erfasst wurde.',
            ];
        }
        return $out;
    }

    /** Ein Datum, das gar nicht in das abgerechnete Quartal gehört. */
    private static function ausserhalb( $abr, $posten ) {
        [ $von, $bis ] = self::quartalsgrenzen( $abr['quartal'], (int) $abr['jahr'] );
        $out = [];
        foreach ( $posten as $p ) {
            if ( $p['datum'] >= $von && $p['datum'] <= $bis ) continue;
            $out[] = [
                'stufe'     => 'warnung',
                'posten_id' => (int) $p['id'],
                'text'      => 'Der ' . self::datum( $p['datum'] ) . ' liegt nicht im abgerechneten '
                             . 'Zeitraum (' . self::datum( $von ) . ' bis ' . self::datum( $bis ) . ').',
            ];
        }
        return $out;
    }

    /** Ein einzelner Posten, der den Löwenanteil ausmacht. */
    private static function grosser_posten( $abr, $posten ) {
        $gesamt = array_sum( array_map( fn( $p ) => (float) $p['betrag'], $posten ) );
        if ( $gesamt <= 0 || count( $posten ) < 3 ) return [];
        $out = [];
        foreach ( $posten as $p ) {
            $betrag = (float) $p['betrag'];
            if ( $betrag < self::POSTEN_AB || $betrag / $gesamt < self::ANTEIL ) continue;
            $out[] = [
                'stufe'     => 'hinweis',
                'posten_id' => (int) $p['id'],
                'text'      => 'Dieser Posten macht mit ' . self::eur( $betrag ) . ' allein '
                             . round( $betrag / $gesamt * 100 ) . ' % der Abrechnung aus.',
            ];
        }
        return $out;
    }

    /**
     * Deutlich mehr als in den bisherigen Quartalen derselben Person.
     *
     * Verglichen wird nur mit ABGESCHLOSSENEN Abrechnungen (genehmigt
     * oder bezahlt) — ein Entwurf ist keine Vergleichsgrösse, und eine
     * zurückgegebene erst recht nicht.
     */
    private static function mehr_als_sonst( $abr, $posten ) {
        global $wpdb;
        $gesamt = array_sum( array_map( fn( $p ) => (float) $p['betrag'], $posten ) );
        if ( $gesamt <= 0 ) return [];

        $frueher = $wpdb->get_col( $wpdb->prepare(
            "SELECT COALESCE(SUM(p.betrag),0) AS summe
               FROM {$wpdb->prefix}lsv07a_abrechnung a
          LEFT JOIN {$wpdb->prefix}lsv07a_posten p ON p.abrechnung_id = a.id
              WHERE a.wp_user_id = %d AND a.id <> %d
                AND a.status IN ('genehmigt','bezahlt')
           GROUP BY a.id
           ORDER BY a.jahr DESC, a.quartal DESC
              LIMIT 4", (int) $abr['wp_user_id'], (int) $abr['id'] ) ) ?: [];

        /* Ohne mindestens zwei abgeschlossene Quartale gibt es keinen
           Schnitt, der etwas aussagt — dann lieber nichts sagen. */
        if ( count( $frueher ) < 2 ) return [];
        $schnitt = array_sum( array_map( 'floatval', $frueher ) ) / count( $frueher );
        if ( $schnitt <= 0 ) return [];

        if ( $gesamt < $schnitt * self::FAKTOR || $gesamt - $schnitt < self::MINDEST ) return [];
        return [ [
            'stufe'     => 'hinweis',
            'posten_id' => 0,
            'text'      => 'Mit ' . self::eur( $gesamt ) . ' liegt dieses Quartal deutlich über dem '
                         . 'bisherigen Schnitt von ' . self::eur( $schnitt ) . ' aus '
                         . count( $frueher ) . ' Quartalen.',
        ] ];
    }

    // ── Kleinkram ────────────────────────────────────────────────────────

    public static function quartalsgrenzen( $quartal, $jahr ) {
        $monat = [ 'Q1' => 1, 'Q2' => 4, 'Q3' => 7, 'Q4' => 10 ][ $quartal ] ?? 1;
        $von   = sprintf( '%04d-%02d-01', $jahr, $monat );
        $bis   = date( 'Y-m-t', mktime( 0, 0, 0, $monat + 2, 1, $jahr ) );
        return [ $von, $bis ];
    }

    private static function eur( $v ) {
        return number_format( (float) $v, 2, ',', '.' ) . ' €';
    }

    private static function datum( $d ) {
        $z = strtotime( (string) $d );
        return $z ? date( 'd.m.Y', $z ) : (string) $d;
    }
}
