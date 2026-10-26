<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Eigene Mannschaften — solche, die es nur in der Abrechnung gibt.
 *
 * Der interne Bereich führt die Mannschaften des Trainingsbetriebs in
 * `lsv07_gruppen`. Die werden hier **ausschliesslich gelesen** und nie
 * geändert; es wird dort nichts angelegt, nichts umbenannt und nichts
 * gelöscht. Wird dieses Plugin entfernt, ist der interne Bereich genau so,
 * wie er vorher war.
 *
 * Nicht alles, was in einer Abrechnungsgliederung steht, gehört aber in den
 * Trainingsbetrieb: ein Vorstand, eine Geschäftsstelle, eine Projektgruppe,
 * ein Förderverein. Solche Einheiten entstehen hier — neben den internen,
 * nicht statt ihrer.
 *
 * Zwei Festlegungen:
 *
 *  1. **Eigene Mannschaften tauchen in der Abrechnung selbst nicht auf.**
 *     Trainings, Wettkämpfe und Pauschalen hängen an den Mannschaften des
 *     internen Bereichs; eine eigene Mannschaft hat dort keine Entsprechung
 *     und würde nur eine Zeile erzeugen, zu der es keine Daten gibt. Sie
 *     dient der Gliederung und der Frage „wer genehmigt", sonst nichts.
 *  2. **Gelöscht wird nur, was nirgends hängt.** Hängt eine eigene
 *     Mannschaft an einer Abteilung, wird sie abgeschaltet statt gelöscht —
 *     dann bleibt der Name in alten Zuordnungen lesbar.
 */
class LSV07A_Mannschaft {

    private static function t() { global $wpdb; return $wpdb->prefix . 'lsv07a_mannschaft'; }

    // ── Lesen ────────────────────────────────────────────────────────────

    public static function holen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t() . " WHERE id = %d", $id ), ARRAY_A ) ?: null;
    }

    public static function name( $id ) {
        $m = self::holen( $id );
        return $m ? (string) $m['name'] : '';
    }

    /**
     * Alle eigenen Mannschaften.
     *
     * @param bool $nur_aktive Abgeschaltete weglassen.
     */
    public static function alle( $nur_aktive = false ) {
        global $wpdb;
        $wo = $nur_aktive ? 'WHERE aktiv = 1' : '';
        $zeilen = $wpdb->get_results(
            "SELECT * FROM " . self::t() . " $wo ORDER BY name ASC", ARRAY_A ) ?: [];

        $out = [];
        foreach ( $zeilen as $z ) {
            $id = (int) $z['id'];
            $out[] = [
                'id'        => $id,
                'name'      => (string) $z['name'],
                'notiz'     => (string) $z['notiz'],
                'aktiv'     => (int) $z['aktiv'] === 1,
                'abteilung_id' => LSV07A_Abteilung::zu_mannschaft( $id, 'eigen' ),
            ];
        }
        return $out;
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /** Anlegen. Gibt die Nummer zurück oder einen Text mit dem Grund. */
    public static function anlegen( array $f ) {
        global $wpdb;
        $name = trim( (string) ( $f['name'] ?? '' ) );
        if ( $name === '' ) return 'Ohne Namen keine Mannschaft.';
        $name = mb_substr( $name, 0, 120 );

        if ( self::name_vergeben( $name, 0 ) ) {
            return 'Eine eigene Mannschaft mit diesem Namen gibt es schon.';
        }
        /* Heisst im internen Bereich schon etwas so, wird GEWARNT und nicht
           verboten: Verbieten hiesse, dass der interne Bereich über die
           Abrechnung mitbestimmt, und genau das soll er nicht. Zwei gleiche
           Namen nebeneinander wären aber eine Falle, also kommt der Hinweis
           zurück — siehe `warnung()`. */

        $wpdb->insert( self::t(), [
            'name'        => $name,
            'notiz'       => mb_substr( trim( (string) ( $f['notiz'] ?? '' ) ), 0, 200 ),
            'aktiv'       => isset( $f['aktiv'] ) && empty( $f['aktiv'] ) ? 0 : 1,
            'erstellt_am' => current_time( 'mysql' ),
        ], [ '%s', '%s', '%d', '%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 'Die Mannschaft liess sich nicht anlegen.';
        LSV07A_Log::schreibe( 'mannschaft.neu',
            [ 'ziel_typ' => 'mannschaft', 'ziel_id' => $id, 'details' => $name ] );
        return $id;
    }

    public static function aendern( $id, array $f ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::holen( $id ) ) return 'Diese Mannschaft gibt es nicht.';

        $daten = []; $form = [];
        if ( isset( $f['name'] ) ) {
            $name = trim( (string) $f['name'] );
            if ( $name === '' ) return 'Ohne Namen keine Mannschaft.';
            if ( self::name_vergeben( $name, $id ) ) {
                return 'Eine eigene Mannschaft mit diesem Namen gibt es schon.';
            }
            $daten['name'] = mb_substr( $name, 0, 120 ); $form[] = '%s';
        }
        if ( isset( $f['notiz'] ) ) {
            $daten['notiz'] = mb_substr( trim( (string) $f['notiz'] ), 0, 200 ); $form[] = '%s';
        }
        if ( isset( $f['aktiv'] ) ) {
            $daten['aktiv'] = empty( $f['aktiv'] ) ? 0 : 1; $form[] = '%d';
        }
        if ( ! $daten ) return true;

        $wpdb->update( self::t(), $daten, [ 'id' => $id ], $form, [ '%d' ] );
        LSV07A_Log::schreibe( 'mannschaft.geaendert',
            [ 'ziel_typ' => 'mannschaft', 'ziel_id' => $id ] );
        return true;
    }

    /**
     * Löschen — nur, solange sie an keiner Abteilung hängt.
     *
     * Sonst verschwände der Name aus einer bestehenden Gliederung, und
     * niemand wüsste mehr, was dort einmal stand. Dann wird abgeschaltet.
     */
    public static function loeschen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::holen( $id ) ) return 'Diese Mannschaft gibt es nicht.';

        $abt = LSV07A_Abteilung::zu_mannschaft( $id, 'eigen' );
        if ( $abt ) {
            return 'Diese Mannschaft gehört zur Abteilung „' . LSV07A_Abteilung::name( $abt )
                 . '". Nimm sie dort zuerst heraus — oder schalte sie ab, dann bleibt die '
                 . 'Zuordnung erhalten.';
        }

        $wpdb->delete( self::t(), [ 'id' => $id ], [ '%d' ] );
        LSV07A_Log::schreibe( 'mannschaft.weg',
            [ 'ziel_typ' => 'mannschaft', 'ziel_id' => $id ] );
        return true;
    }

    private static function name_vergeben( $name, $ausser ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t() . " WHERE name = %s AND id <> %d",
            $name, (int) $ausser ) ) > 0;
    }

    /**
     * Heisst im internen Bereich schon eine Mannschaft so?
     *
     * Gibt einen Hinweistext zurück oder ''. Verboten wird es nicht: Der
     * interne Bereich soll über die Abrechnung nicht mitbestimmen. Aber zwei
     * gleich benannte Mannschaften nebeneinander wären eine Falle beim
     * Zuordnen, und davor wird gewarnt.
     */
    public static function warnung( $name ) {
        $name = mb_strtolower( trim( (string) $name ) );
        if ( $name === '' ) return '';
        foreach ( LSV07A_Intern::mannschaften() as $m ) {
            if ( mb_strtolower( (string) $m['name'] ) === $name ) {
                return 'Im internen Bereich gibt es bereits eine Mannschaft „' . $m['name']
                     . '". Beide lassen sich zuordnen, sind beim Auswählen aber kaum zu '
                     . 'unterscheiden — ein anderer Name erspart späteres Suchen.';
            }
        }
        return '';
    }
}
