<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rollen dieses Systems.
 *
 * Die Konten sind WordPress-Konten, die Rollen aber NICHT: Wer hier
 * Trainer, Wart, Kasse oder Administrator ist, steht in lsv07a_rolle und
 * wird vom Administrator dieses Plugins vergeben. WordPress-Rollen bleiben
 * davon unberührt.
 *
 * Eine Ausnahme als Sicherheitsventil: Ein echter WordPress-Administrator
 * gilt immer auch hier als Administrator. Sonst könnte sich nach einer
 * frischen Installation niemand die erste Rolle geben.
 */
class LSV07A_Rollen {

    private static $cache = [];

    /** Alle Rollen einer Person. */
    public static function rollen( $wp_user_id ) {
        $wp_user_id = (int) $wp_user_id;
        if ( isset( self::$cache[ $wp_user_id ] ) ) return self::$cache[ $wp_user_id ];
        if ( ! $wp_user_id ) return self::$cache[ $wp_user_id ] = [];

        global $wpdb;
        $rollen = $wpdb->get_col( $wpdb->prepare(
            "SELECT rolle FROM {$wpdb->prefix}lsv07a_rolle WHERE wp_user_id = %d", $wp_user_id ) );
        $rollen = array_values( array_intersect( (array) $rollen, LSV07A_DB::ROLLEN ) );

        if ( user_can( $wp_user_id, 'administrator' ) && ! in_array( 'admin', $rollen, true ) ) {
            $rollen[] = 'admin';
        }
        return self::$cache[ $wp_user_id ] = $rollen;
    }

    public static function hat( $rolle, $wp_user_id = null ) {
        $wp_user_id = $wp_user_id === null ? get_current_user_id() : (int) $wp_user_id;
        $rollen = self::rollen( $wp_user_id );
        // Der Administrator kann alles, was die anderen können.
        if ( in_array( 'admin', $rollen, true ) ) return true;
        return in_array( $rolle, $rollen, true );
    }

    /** Ohne die Admin-Großzügigkeit — für "ist wirklich Administrator". */
    public static function ist_genau( $rolle, $wp_user_id = null ) {
        $wp_user_id = $wp_user_id === null ? get_current_user_id() : (int) $wp_user_id;
        return in_array( $rolle, self::rollen( $wp_user_id ), true );
    }

    public static function ist_admin( $uid = null )   { return self::ist_genau( 'admin', $uid ); }
    public static function ist_wart( $uid = null )    { return self::hat( 'wart', $uid ); }
    public static function ist_kasse( $uid = null )   { return self::hat( 'kasse', $uid ); }
    public static function ist_trainer( $uid = null ) { return self::hat( 'trainer', $uid ); }

    /** Darf diese Person das System überhaupt öffnen? */
    public static function hat_zugang( $uid = null ) {
        $uid = $uid === null ? get_current_user_id() : (int) $uid;
        return ! empty( self::rollen( $uid ) );
    }

    public static function setzen( $wp_user_id, array $rollen ) {
        global $wpdb;
        $wp_user_id = (int) $wp_user_id;
        $rollen = array_values( array_unique( array_intersect( $rollen, LSV07A_DB::ROLLEN ) ) );
        $wpdb->delete( $wpdb->prefix . 'lsv07a_rolle', [ 'wp_user_id' => $wp_user_id ], [ '%d' ] );
        foreach ( $rollen as $r ) {
            $wpdb->insert( $wpdb->prefix . 'lsv07a_rolle',
                [ 'wp_user_id' => $wp_user_id, 'rolle' => $r ], [ '%d', '%s' ] );
        }
        unset( self::$cache[ $wp_user_id ] );
        return $rollen;
    }

    /** Alle Konten mit mindestens einer Rolle, mit Stammdaten. */
    public static function konten() {
        global $wpdb;
        $p = $wpdb->prefix;
        $zeilen = $wpdb->get_results(
            "SELECT r.wp_user_id, GROUP_CONCAT(r.rolle ORDER BY r.rolle) AS rollen
               FROM {$p}lsv07a_rolle r GROUP BY r.wp_user_id", ARRAY_A ) ?: [];

        $out = [];
        foreach ( $zeilen as $z ) {
            $uid = (int) $z['wp_user_id'];
            $u   = get_userdata( $uid );
            $pers = LSV07A_Person::holen( $uid );
            $out[] = [
                'wp_user_id'     => $uid,
                'name'           => $u ? $u->display_name : ( 'Konto ' . $uid . ' (gelöscht)' ),
                'email'          => $u ? $u->user_email : '',
                'rollen'         => explode( ',', (string) $z['rollen'] ),
                'stundensatz'    => (float) $pers['stundensatz'],
                'abrechnungsart' => $pers['abrechnungsart'],
                'aktiv'          => (int) $pers['aktiv'],
                'existiert'      => (bool) $u,
            ];
        }
        usort( $out, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
        return $out;
    }
}

/**
 * Die Stammdaten eines Kontos: Stundensatz und Abrechnungsart legt der
 * Administrator fest, die Zahlungsdaten pflegt die Person selbst.
 */
class LSV07A_Person {

    public static function holen( $wp_user_id ) {
        global $wpdb;
        $zeile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07a_person WHERE wp_user_id = %d", (int) $wp_user_id ), ARRAY_A );
        if ( $zeile ) return $zeile;
        return [
            'id' => 0, 'wp_user_id' => (int) $wp_user_id, 'stundensatz' => '0.00',
            'abrechnungsart' => 'zeiten', 'iban' => '', 'bic' => '', 'kontoinhaber' => '',
            'strasse' => '', 'plz' => '', 'ort' => '', 'aktiv' => 1, 'notiz' => '',
        ];
    }

    /** Legt die Zeile an, falls es noch keine gibt. */
    public static function sicherstellen( $wp_user_id ) {
        global $wpdb;
        $wp_user_id = (int) $wp_user_id;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lsv07a_person WHERE wp_user_id = %d", $wp_user_id ) );
        if ( $id ) return (int) $id;
        $wpdb->insert( $wpdb->prefix . 'lsv07a_person', [ 'wp_user_id' => $wp_user_id ], [ '%d' ] );
        return (int) $wpdb->insert_id;
    }

    public static function speichern( $wp_user_id, array $felder ) {
        global $wpdb;
        self::sicherstellen( $wp_user_id );
        $erlaubt = [ 'stundensatz', 'abrechnungsart', 'iban', 'bic', 'kontoinhaber',
                     'strasse', 'plz', 'ort', 'aktiv', 'notiz' ];
        $daten = [];
        foreach ( $erlaubt as $f ) if ( array_key_exists( $f, $felder ) ) $daten[ $f ] = $felder[ $f ];
        if ( ! $daten ) return true;
        if ( isset( $daten['abrechnungsart'] ) && ! in_array( $daten['abrechnungsart'], LSV07A_DB::ARTEN, true ) ) {
            $daten['abrechnungsart'] = 'zeiten';
        }
        return false !== $wpdb->update( $wpdb->prefix . 'lsv07a_person', $daten,
            [ 'wp_user_id' => (int) $wp_user_id ], null, [ '%d' ] );
    }
}
