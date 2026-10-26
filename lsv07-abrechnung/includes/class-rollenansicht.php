<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Personenansicht: Die Administration sieht die Anwendung so, wie sie EINE
 * BESTIMMTE PERSON sieht.
 *
 * Früher liess sich in eine Rolle schauen. Das beantwortete die falsche
 * Frage: „Was sieht ein Wart" ist eine Abstraktion, „warum sieht Sabine ihre
 * Abrechnung nicht" ist das Problem, das jemand meldet. Darum wird jetzt in
 * eine Person geschaut — mit ihren Rollen, ihrer Abteilung, ihren
 * Mannschaften, ihren Abrechnungen.
 *
 * Vier Regeln, die das Ganze ungefährlich machen:
 *
 * 1. **NUR DIE ADMINISTRATION.** Und zwar die echte: `ist_admin_echt()`
 *    fragt die wirklichen Rollen ab und nicht die der Ansicht. Sonst käme
 *    niemand mehr heraus, der in eine Trainerin hineingeschaut hat.
 * 2. **NUR SEHEN, NIE HANDELN.** Solange sie läuft, ist jeder schreibende
 *    Vorgang gesperrt — auch an den eigenen Daten. Es wird nichts im Namen
 *    der betrachteten Person getan, und im Protokoll steht nie ihr Name.
 * 3. **EINSCHRÄNKEN, NIE ERWEITERN.** Wer sie einschaltet, ist bereits
 *    Administrator und sieht ohnehin alles. Die Ansicht nimmt Rechte weg,
 *    sie gibt keine. Ein Weg zu mehr Rechten ist sie also nicht.
 * 4. **SICHTBAR UND BEFRISTET.** Ein Band steht über der Seite, es nennt den
 *    Namen, und nach zwei Stunden endet die Ansicht von selbst.
 *
 * Es wird NICHT in ein fremdes Konto geschlüpft: Die Anmeldung bleibt die
 * eigene, die Sitzung bleibt die eigene. Es ändert sich nur, wessen Sicht
 * die Lesewege zeigen.
 */
class LSV07A_Rollenansicht {

    const META   = 'lsv07a_personenansicht';
    /* Der Schlüssel der früheren Rollenansicht. Er wird beim ersten Mal
       aufgeräumt, damit niemand mit einer halben alten Ansicht dasteht. */
    const META_ALT = 'lsv07a_rollenansicht';
    const DAUER  = 7200;   // zwei Stunden

    private static $geladen = null;

    /**
     * Das Konto, in das gerade geschaut wird — oder 0.
     *
     * @return int
     */
    public static function person() {
        if ( self::$geladen !== null ) return self::$geladen;
        $uid = get_current_user_id();
        /* ist_admin_echt(), nicht ist_admin(): Letzteres fragt wieder hier
           nach, was sich im Kreis drehen würde. */
        if ( ! $uid || ! LSV07A_Rollen::ist_admin_echt( $uid ) ) return self::$geladen = 0;

        /* Eine alte Rollenansicht gilt nicht weiter. */
        if ( get_user_meta( $uid, self::META_ALT, true ) ) {
            delete_user_meta( $uid, self::META_ALT );
        }

        $wert = get_user_meta( $uid, self::META, true );
        if ( ! is_array( $wert ) || empty( $wert['person'] ) ) return self::$geladen = 0;
        if ( ( (int) ( $wert['bis'] ?? 0 ) ) < time() ) {
            delete_user_meta( $uid, self::META );
            return self::$geladen = 0;
        }
        $person = (int) $wert['person'];
        /* In sich selbst zu schauen ist keine Ansicht. */
        if ( $person === $uid ) return self::$geladen = 0;
        /* Gibt es das Konto nicht mehr, endet die Ansicht. */
        if ( ! get_userdata( $person ) ) {
            delete_user_meta( $uid, self::META );
            return self::$geladen = 0;
        }
        return self::$geladen = $person;
    }

    public static function laeuft() { return self::person() > 0; }

    /**
     * Wessen Sicht die Lesewege zeigen sollen.
     *
     * DIE eine Stelle, an der ein Lesepfad fragt „wessen Daten". Jeder
     * Schreibweg nimmt weiterhin `get_current_user_id()` — es handelt immer
     * die angemeldete Person, nie die betrachtete.
     */
    public static function blick_uid() {
        $p = self::person();
        return $p ?: get_current_user_id();
    }

    /**
     * Einschalten. Nur die echte Administration, nur ein Konto, das es
     * gibt, und nie das eigene.
     *
     * @return true|string true oder der Grund.
     */
    public static function setzen( $person ) {
        $uid = get_current_user_id();
        if ( ! LSV07A_Rollen::ist_admin_echt( $uid ) ) {
            return 'Nur die Administration kann in eine Person hineinsehen.';
        }
        self::$geladen = null;

        $person = (int) $person;
        if ( ! $person ) {
            delete_user_meta( $uid, self::META );
            delete_user_meta( $uid, self::META_ALT );
            LSV07A_Log::schreibe( 'personenansicht.beendet' );
            return true;
        }
        if ( $person === $uid ) {
            return 'Das ist Ihr eigenes Konto — dafür braucht es keine Ansicht.';
        }
        $u = get_userdata( $person );
        if ( ! $u ) return 'Dieses Konto gibt es nicht.';
        /* Ein Konto ohne Rolle hat in der Abrechnung gar keine Sicht. Das
           lässt sich ansehen — dann sieht man eben, dass nichts da ist. */

        update_user_meta( $uid, self::META, [ 'person' => $person, 'bis' => time() + self::DAUER ] );
        /* Protokolliert mit NAMEN: Wer in fremde Daten sieht, hinterlässt
           eine Spur. Das ist der Preis dafür, dass es überhaupt geht. */
        LSV07A_Log::schreibe( 'personenansicht.gestartet', [
            'ziel_typ' => 'person', 'ziel_id' => $person,
            'details'  => $u->display_name ] );
        return true;
    }

    /**
     * Welche Rollen gelten gerade?
     *
     * Ohne Ansicht die echten. Mit Ansicht genau die der betrachteten
     * Person — auch dann, wenn das weniger sind. Ist sie selbst
     * Administratorin, sieht man auch das; herauszukommen ist davon
     * unberührt, denn das Band fragt die ECHTEN Rollen.
     */
    public static function rollen_jetzt( $echte ) {
        $p = self::person();
        if ( ! $p ) return $echte;
        return LSV07A_Rollen::rollen( $p );
    }

    /**
     * Tor für schreibende Vorgänge. Wer in einer Personenansicht steht,
     * darf schauen, aber nichts verändern — weder an eigenen noch an
     * fremden Daten.
     */
    public static function schreiben_erlaubt() {
        return ! self::laeuft();
    }

    public static function sperre_pruefen() {
        if ( self::laeuft() ) {
            wp_send_json_error( [
                'message' => 'In der Personenansicht sind Änderungen gesperrt. Sie sehen nur, '
                           . 'was diese Person sieht. Beenden Sie die Ansicht oben im Band, '
                           . 'um wieder zu arbeiten.',
                'code'    => 'rollenansicht',
            ], 403 );
        }
    }

    /** Was die Oberfläche über die Ansicht wissen muss. */
    public static function karte() {
        $p = self::person();
        if ( ! $p ) {
            return [ 'aktiv' => false, 'person' => 0, 'name' => '', 'rollen' => [], 'bis' => 0 ];
        }
        $uid  = get_current_user_id();
        $wert = get_user_meta( $uid, self::META, true );
        $u    = get_userdata( $p );
        return [
            'aktiv'  => true,
            'person' => $p,
            'name'   => $u ? $u->display_name : ( 'Konto ' . $p ),
            'rollen' => LSV07A_Rollen::rollen( $p ),
            'bis'    => (int) ( $wert['bis'] ?? 0 ),
        ];
    }

    /** Nur für die Prüfungen: den Zwischenspeicher vergessen. */
    public static function vergessen() { self::$geladen = null; }
}
