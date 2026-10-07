<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rollenansicht: Die Administration sieht die Anwendung so, wie eine
 * Trainerin, ein Wart oder die Kasse sie sieht.
 *
 * Drei Regeln, die das Ganze ungefährlich machen:
 *
 * 1. EINSCHRÄNKEN, NIE ERWEITERN. Die Ansicht entfernt Rechte. Sie kann
 *    niemandem etwas geben, was er nicht ohnehin hätte — wer sie einschaltet,
 *    ist bereits Administrator und darf alles. Sie ist also kein Weg zu mehr
 *    Rechten, sondern zu weniger.
 * 2. NUR SEHEN. Solange sie läuft, sind alle schreibenden Vorgänge gesperrt.
 *    Sonst könnte die Administration versehentlich eine fremde Abrechnung
 *    einreichen oder genehmigen — und im Protokoll stünde der falsche Name.
 * 3. SICHTBAR UND BEFRISTET. Ein Band steht über der Seite, und nach zwei
 *    Stunden endet sie von selbst. Niemand soll vergessen, dass er gerade
 *    durch fremde Augen schaut.
 *
 * Es wird NICHT in ein fremdes Konto geschlüpft: Die Person bleibt sie
 * selbst, es ändert sich nur, welche Rollen für sie gelten. Fremde
 * Abrechnungen bleiben deshalb genauso geschützt wie vorher.
 */
class LSV07A_Rollenansicht {

    const META   = 'lsv07a_rollenansicht';
    const DAUER  = 7200;   // zwei Stunden

    private static $geladen = null;

    /** Die gerade gewählte Rolle — oder '' für die eigene Sicht. */
    public static function aktiv() {
        if ( self::$geladen !== null ) return self::$geladen;
        $uid = get_current_user_id();
        /* ist_admin_echt(), nicht ist_admin(): Letzteres fragt wieder hier
           nach, was sich im Kreis drehen würde — und wer in der Ansicht
           steht, gilt dort gerade NICHT als Administrator, käme also nie
           wieder heraus. */
        if ( ! $uid || ! LSV07A_Rollen::ist_admin_echt( $uid ) ) return self::$geladen = '';

        $wert = get_user_meta( $uid, self::META, true );
        if ( ! is_array( $wert ) || empty( $wert['rolle'] ) ) return self::$geladen = '';
        if ( ( (int) ( $wert['bis'] ?? 0 ) ) < time() ) {
            delete_user_meta( $uid, self::META );
            return self::$geladen = '';
        }
        $rolle = (string) $wert['rolle'];
        if ( ! in_array( $rolle, [ 'trainer', 'wart', 'kasse' ], true ) ) return self::$geladen = '';
        return self::$geladen = $rolle;
    }

    public static function laeuft() { return self::aktiv() !== ''; }

    /** Einschalten. Nur der Administrator, nur die drei echten Rollen. */
    public static function setzen( $rolle ) {
        $uid = get_current_user_id();
        if ( ! LSV07A_Rollen::ist_admin_echt( $uid ) ) return false;
        self::$geladen = null;
        if ( $rolle === '' ) {
            delete_user_meta( $uid, self::META );
            LSV07A_Log::schreibe( 'rollenansicht.beendet' );
            return true;
        }
        if ( ! in_array( $rolle, [ 'trainer', 'wart', 'kasse' ], true ) ) return false;
        update_user_meta( $uid, self::META, [ 'rolle' => $rolle, 'bis' => time() + self::DAUER ] );
        LSV07A_Log::schreibe( 'rollenansicht.gestartet', [ 'details' => $rolle ] );
        return true;
    }

    /**
     * Welche Rollen gelten gerade? Ohne Ansicht die echten, mit Ansicht
     * genau die eine — und 'admin' ist dann bewusst NICHT dabei, sonst
     * würde die Großzügigkeit des Administrators alles wieder aufmachen.
     */
    public static function rollen_jetzt( $echte ) {
        $r = self::aktiv();
        return $r === '' ? $echte : [ $r ];
    }

    /**
     * Tor für schreibende Vorgänge. Wer in der Rollenansicht steht, darf
     * schauen, aber nichts verändern — weder an eigenen noch an fremden
     * Abrechnungen.
     */
    public static function schreiben_erlaubt() {
        return ! self::laeuft();
    }

    public static function sperre_pruefen() {
        if ( self::laeuft() ) {
            wp_send_json_error( [
                'message' => 'In der Rollenansicht sind Änderungen gesperrt. '
                           . 'Beenden Sie die Ansicht oben im Band, um wieder zu arbeiten.',
                'code'    => 'rollenansicht',
            ], 403 );
        }
    }

    /** Was die Oberfläche über die Ansicht wissen muss. */
    public static function karte() {
        $r = self::aktiv();
        if ( $r === '' ) return [ 'aktiv' => false, 'rolle' => '', 'name' => '', 'bis' => 0 ];
        $uid  = get_current_user_id();
        $wert = get_user_meta( $uid, self::META, true );
        return [
            'aktiv' => true,
            'rolle' => $r,
            'name'  => [ 'trainer' => 'Trainer', 'wart' => 'Wart', 'kasse' => 'Kasse' ][ $r ] ?? $r,
            'bis'   => (int) ( $wert['bis'] ?? 0 ),
        ];
    }
}
