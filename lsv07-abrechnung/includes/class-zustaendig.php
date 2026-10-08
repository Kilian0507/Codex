<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wer prüft welche Mannschaften.
 *
 * In einem kleinen Verein prüft ein Wart alles. Ab einer gewissen Größe
 * will die Leichtathletik-Abteilung nicht die Abrechnungen der Schwimmer
 * sehen — und umgekehrt. Darum lässt sich je Wart hinterlegen, für welche
 * Mannschaften er zuständig ist.
 *
 * Die wichtigste Regel steht gleich am Anfang: **Kein Eintrag heisst
 * „für alle".** Ein bestehender Verein mit einem einzigen Wart soll nach
 * einem Update nicht vor einer leeren Liste stehen und sich fragen, wo
 * die Abrechnungen geblieben sind. Eingeschränkt wird nur, wer bewusst
 * eingeschränkt wurde.
 *
 * Zugeordnet wird über die POSTEN: Eine Abrechnung gehört in den Bereich
 * eines Warts, wenn mindestens ein Trainingsposten darin zu einer seiner
 * Mannschaften gehört. Das ist genauer als eine feste Zuordnung der
 * Person, denn wer zwei Mannschaften trainiert, taucht dann bei beiden
 * Warten auf — und beide sehen dieselbe Abrechnung, weil sie nun einmal
 * beides enthält.
 */
class LSV07A_Zustaendig {

    private static function tbl() {
        global $wpdb;
        return $wpdb->prefix . 'lsv07a_wart_bereich';
    }

    /** Die Mannschaftsnummern, für die jemand zuständig ist. Leer = alle. */
    public static function bereiche( $wp_user_id ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT mannschaft_id FROM " . self::tbl() . " WHERE wp_user_id = %d",
            (int) $wp_user_id ) ) ?: [];
        return array_map( 'intval', $ids );
    }

    /** Alle Zuordnungen auf einmal — für die Verwaltung. */
    public static function alle() {
        global $wpdb;
        $zeilen = $wpdb->get_results(
            "SELECT wp_user_id, mannschaft_id FROM " . self::tbl(), ARRAY_A ) ?: [];
        $out = [];
        foreach ( $zeilen as $z ) {
            $out[ (int) $z['wp_user_id'] ][] = (int) $z['mannschaft_id'];
        }
        return $out;
    }

    /**
     * Die Zuordnung einer Person neu setzen. Eine leere Liste bedeutet
     * „für alle zuständig" und löscht damit alle Einträge.
     */
    public static function setzen( $wp_user_id, array $mannschaft_ids ) {
        global $wpdb;
        $uid = (int) $wp_user_id;
        $wpdb->delete( self::tbl(), [ 'wp_user_id' => $uid ], [ '%d' ] );
        $gesetzt = [];
        foreach ( $mannschaft_ids as $mid ) {
            $mid = (int) $mid;
            if ( $mid <= 0 || isset( $gesetzt[ $mid ] ) ) continue;
            $gesetzt[ $mid ] = true;
            $wpdb->insert( self::tbl(),
                [ 'wp_user_id' => $uid, 'mannschaft_id' => $mid ], [ '%d', '%d' ] );
        }
        return count( $gesetzt );
    }

    /** Sieht diese Person alles? Administration und Warte ohne Eintrag. */
    public static function unbeschraenkt( $wp_user_id = 0 ) {
        $uid = $wp_user_id ?: get_current_user_id();
        if ( LSV07A_Rollen::ist_admin() ) return true;
        return self::bereiche( $uid ) === [];
    }

    /**
     * Darf die aktuelle Person diese Abrechnung prüfen?
     *
     * Eine Abrechnung ohne jeden Mannschaftsbezug — nur Fahrten, nur
     * Sonstiges — gehört niemandem im Besonderen. Sie ist für alle Warte
     * sichtbar, denn sonst sähe sie keiner und bliebe für immer liegen.
     */
    public static function darf_pruefen( $abrechnung_id, $wp_user_id = 0 ) {
        global $wpdb;
        $uid = $wp_user_id ?: get_current_user_id();
        if ( self::unbeschraenkt( $uid ) ) return true;

        $meine = self::bereiche( $uid );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT mannschaft_id FROM {$wpdb->prefix}lsv07a_posten
              WHERE abrechnung_id = %d AND mannschaft_id > 0", (int) $abrechnung_id ) ) ?: [];
        if ( ! $ids ) return true;   // ohne Mannschaftsbezug: für alle sichtbar

        foreach ( $ids as $mid ) if ( in_array( (int) $mid, $meine, true ) ) return true;
        return false;
    }

    /**
     * Die Nummern aller Abrechnungen eines Quartals, die in den Bereich
     * der Person fallen. Gibt null zurück, wenn nicht eingeschränkt wird —
     * dann braucht die Liste gar nicht erst zu filtern.
     */
    public static function abrechnungen_im_bereich( $quartal, $jahr, $wp_user_id = 0 ) {
        global $wpdb;
        $uid = $wp_user_id ?: get_current_user_id();
        if ( self::unbeschraenkt( $uid ) ) return null;

        $meine = self::bereiche( $uid );
        if ( ! $meine ) return null;
        $platz = implode( ',', array_fill( 0, count( $meine ), '%d' ) );

        /* Zwei Mengen: Abrechnungen mit einem Posten aus meinen
           Mannschaften, und Abrechnungen ganz ohne Mannschaftsbezug. */
        $sql = "SELECT DISTINCT a.id
                  FROM {$wpdb->prefix}lsv07a_abrechnung a
             LEFT JOIN {$wpdb->prefix}lsv07a_posten p ON p.abrechnung_id = a.id
                 WHERE a.quartal = %s AND a.jahr = %d
              GROUP BY a.id
                HAVING SUM(CASE WHEN p.mannschaft_id IN ($platz) THEN 1 ELSE 0 END) > 0
                    OR SUM(CASE WHEN p.mannschaft_id > 0 THEN 1 ELSE 0 END) = 0";
        $werte = array_merge( [ $quartal, (int) $jahr ], $meine );
        $ids = $wpdb->get_col( $wpdb->prepare( $sql, $werte ) ) ?: [];
        return array_map( 'intval', $ids );
    }

    /**
     * Wen darf diese Person in der Liste „noch nichts erfasst" sehen?
     *
     * Hier greift die Zuordnung über die Posten nicht — es gibt ja noch
     * keine. Massstab ist deshalb, wen die Person im zurückliegenden Jahr
     * schon einmal geprüft hat: Wer in einer ihrer Mannschaften etwas
     * abgerechnet hat, gehört zu ihr. Das ist unscharf, aber es ist die
     * einzige Spur, die es gibt — und ohne sie würde niemand merken, dass
     * jemand gar nichts eingereicht hat.
     */
    public static function personen_im_bereich( $wp_user_id = 0 ) {
        global $wpdb;
        $uid = $wp_user_id ?: get_current_user_id();
        if ( self::unbeschraenkt( $uid ) ) return null;

        $meine = self::bereiche( $uid );
        if ( ! $meine ) return null;
        $platz = implode( ',', array_fill( 0, count( $meine ), '%d' ) );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT a.wp_user_id
               FROM {$wpdb->prefix}lsv07a_abrechnung a
               JOIN {$wpdb->prefix}lsv07a_posten p ON p.abrechnung_id = a.id
              WHERE p.mannschaft_id IN ($platz)", $meine ) ) ?: [];
        return array_map( 'intval', $ids );
    }

    /** Lesbar für die Oberfläche: „Jugend A, Masters" oder „alle Mannschaften". */
    public static function beschriftung( $wp_user_id = 0 ) {
        $uid = $wp_user_id ?: get_current_user_id();
        $meine = self::bereiche( $uid );
        if ( ! $meine ) return 'alle Mannschaften';
        $namen = [];
        foreach ( LSV07A_Intern::mannschaften() as $m ) {
            if ( in_array( (int) $m['id'], $meine, true ) ) $namen[] = $m['name'];
        }
        return $namen ? implode( ', ', $namen ) : 'keine Mannschaft';
    }
}
