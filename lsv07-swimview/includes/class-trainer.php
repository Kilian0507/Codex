<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wer im Trainerportal was darf.
 *
 * Gefragt ist das WordPress-Konto: Wer als Trainer im internen Bereich
 * geführt wird, verwaltet SwimView für SEINE Mannschaften. Verwaltung und
 * Schwimmwart sehen alle.
 *
 * Die Rechteprüfung liegt bewusst beim internen Bereich, solange er da ist —
 * ein zweites Rechtesystem daneben wäre eine Stelle mehr, an der jemand
 * vergisst, einen Zugang zu entziehen. Fehlt er, bleibt nur die
 * WordPress-Verwaltung übrig.
 */
class LSV07S_Trainer {

    /** Verwaltung oder Schwimmwart: sieht und verwaltet alle Mannschaften. */
    public static function ist_leitung() {
        if ( current_user_can( 'manage_options' ) ) return true;
        if ( class_exists( 'LSV07I_Access' ) ) {
            if ( method_exists( 'LSV07I_Access', 'is_admin_raw' ) && LSV07I_Access::is_admin_raw() ) return true;
            if ( method_exists( 'LSV07I_Access', 'is_schwimmwart' ) && LSV07I_Access::is_schwimmwart() ) return true;
        }
        return false;
    }

    /** Hat dieses Konto überhaupt im Trainerportal zu suchen? */
    public static function hat_zugang() {
        if ( ! is_user_logged_in() ) return false;
        if ( self::ist_leitung() ) return true;
        return self::trainer_id() > 0;
    }

    public static function trainer_id() {
        if ( ! is_user_logged_in() ) return 0;
        return LSV07S_Intern::trainer_id( get_current_user_id() );
    }

    /**
     * Die Mannschaften, die dieses Konto verwalten darf — als [ id => name ].
     * Leer heisst: keine.
     */
    public static function mannschaften() {
        if ( ! is_user_logged_in() ) return [];

        if ( self::ist_leitung() ) {
            $out = [];
            foreach ( LSV07S_Intern::alle_mannschaften() as $m ) {
                $out[ (int) $m['id'] ] = $m['name'];
            }
            return $out;
        }

        $tid = self::trainer_id();
        if ( ! $tid ) return [];
        $ids = LSV07S_Intern::trainer_mannschaften( $tid );
        if ( ! $ids ) return [];

        $namen = LSV07S_Intern::mannschaft_namen( $ids );
        $out   = [];
        /* Reihenfolge aus mannschaft_namen() übernehmen (sort_order), nicht
           die der Zuordnungstabelle. */
        foreach ( $namen as $id => $name ) $out[ (int) $id ] = $name;
        return $out;
    }

    /** Darf dieses Konto diese Mannschaft verwalten? */
    public static function darf_mannschaft( $mannschaft_id ) {
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! $mannschaft_id ) return false;
        return array_key_exists( $mannschaft_id, self::mannschaften() );
    }

    /**
     * Die Trainingspläne, die dieses Konto freigeben kann: die eigenen.
     * Die Verwaltung sieht alle — sie darf im internen Bereich ohnehin jeden
     * Plan öffnen.
     */
    public static function eigene_plaene() {
        global $wpdb;
        if ( ! LSV07S_Intern::da( 'lsv07i_trainingsplan' ) ) return [];
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        if ( self::ist_leitung() ) {
            return $wpdb->get_results(
                "SELECT id, titel, ersteller_name, geaendert_am
                   FROM {$p}lsv07i_trainingsplan
               ORDER BY geaendert_am DESC LIMIT 200", ARRAY_A ) ?: [];
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, titel, ersteller_name, geaendert_am
               FROM {$p}lsv07i_trainingsplan
              WHERE ersteller_id = %d
           ORDER BY geaendert_am DESC LIMIT 200", $uid ), ARRAY_A ) ?: [];
    }

    /**
     * Darf dieses Konto diesen Plan für eine Mannschaft freigeben? Nur den
     * eigenen — sonst gäbe ein Trainer den Plan eines anderen an Kinder
     * weiter, ohne dass der davon weiss.
     */
    public static function darf_plan( $plan_id ) {
        global $wpdb;
        if ( ! LSV07S_Intern::da( 'lsv07i_trainingsplan' ) ) return false;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT ersteller_id FROM {$p}lsv07i_trainingsplan WHERE id = %d", (int) $plan_id ), ARRAY_A );
        if ( ! $row ) return false;
        if ( self::ist_leitung() ) return true;
        return (int) $row['ersteller_id'] === get_current_user_id();
    }
}
