<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Benachrichtigungen und Rollenansicht.
 *
 * Beides betrifft nur die eigene Person: Mitteilungen gehören dem Konto,
 * das sie bekommen hat, und die Rollenansicht ändert nur die eigene Sicht.
 * Deshalb steht in jeder Abfrage hier die eigene Konto-ID — eine fremde ID
 * von aussen wird nirgends angenommen.
 */
class LSV07A_Ajax_System {

    public static function init() {
        foreach ( [ 'nachrichten', 'nachricht_gelesen', 'ansicht_setzen',
                    'ansicht_konten' ] as $a ) {
            add_action( 'wp_ajax_lsv07a_sys_' . $a, [ __CLASS__, $a ] );
        }
    }

    /**
     * Die eigenen Mitteilungen. Wird regelmässig abgefragt, deshalb
     * schlank: nur Zahl und Liste, keine Berechnungen.
     */
    public static function nachrichten() {
        LSV07A_Access::check( 'zugang' );
        /* In einer Personenansicht die Mitteilungen DIESER Person: Genau
           darum geht es — zu sehen, was sie sieht. Gelesen wird nur;
           als gelesen markieren bleibt der eigenen Sicht vorbehalten. */
        $uid = LSV07A_Access::blick();

        // Beim Abholen zugleich prüfen, ob ein Quartal abrechenbar wurde.
        // So braucht es keine Hintergrundaufgabe, die irgendwann still
        // ausfällt — und es kostet nur einen Einfügeversuch, der bei
        // Dubletten am eindeutigen Schlüssel scheitert.
        LSV07A_Nachricht::quartal_faellig( $uid );

        wp_send_json_success( [
            'offen' => LSV07A_Nachricht::offen( $uid ),
            'liste' => array_map( function ( $n ) {
                return [
                    'id'      => (int) $n['id'],
                    'art'     => $n['art'],
                    'titel'   => $n['titel'],
                    'text'    => $n['text'],
                    'bereich' => $n['ziel_bereich'],
                    'ziel_id' => (int) $n['ziel_id'],
                    'gelesen' => ! empty( $n['gelesen_am'] ),
                    'zeit'    => $n['erstellt_am'],
                ];
            }, LSV07A_Nachricht::liste( $uid ) ),
        ] );
    }

    public static function nachricht_gelesen() {
        /* Mit `true`: In einer Personenansicht ist auch das gesperrt. Dort
           stehen fremde Mitteilungen in der Liste, und sie als gelesen zu
           markieren wäre ein Eingriff in fremde Daten. */
        LSV07A_Access::check( 'zugang', true );
        $uid = get_current_user_id();
        $id  = absint( $_POST['id'] ?? 0 );
        // gelesen() schränkt selbst noch einmal auf das eigene Konto ein —
        // eine fremde ID bewirkt deshalb nichts.
        LSV07A_Nachricht::gelesen( $uid, $id );
        wp_send_json_success( [ 'offen' => LSV07A_Nachricht::offen( $uid ) ] );
    }

    /**
     * Personenansicht starten oder beenden. Nur der echte Administrator —
     * und `ist_admin_echt()` ist entscheidend, denn wer gerade in einer
     * Ansicht steht, hat dort vielleicht gar keine Adminrolle und käme
     * sonst nicht wieder heraus.
     */
    public static function ansicht_setzen() {
        LSV07A_Access::check( 'zugang' );
        if ( ! LSV07A_Rollen::ist_admin_echt() ) {
            wp_send_json_error( [
                'message' => 'Die Personenansicht ist der Administration vorbehalten.' ], 403 );
        }
        $person = absint( $_POST['person'] ?? 0 );
        $erg = LSV07A_Rollenansicht::setzen( $person );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );

        $karte = LSV07A_Rollenansicht::karte();
        wp_send_json_success( [
            'message' => $person === 0
                ? 'Personenansicht beendet.'
                : 'Sie sehen die Abrechnung jetzt so, wie ' . $karte['name'] . ' sie sieht.',
            'ansicht' => $karte,
        ] );
    }

    /**
     * Wen man sich ansehen kann: alle Konten mit mindestens einer Rolle,
     * ausser dem eigenen.
     */
    public static function ansicht_konten() {
        LSV07A_Access::check( 'zugang' );
        if ( ! LSV07A_Rollen::ist_admin_echt() ) {
            wp_send_json_error( [
                'message' => 'Die Personenansicht ist der Administration vorbehalten.' ], 403 );
        }
        $ich = get_current_user_id();
        $out = [];
        foreach ( LSV07A_Rollen::konten() as $k ) {
            if ( (int) $k['wp_user_id'] === $ich ) continue;
            $out[] = [
                'wp_user_id' => (int) $k['wp_user_id'],
                'name'       => $k['name'],
                'rollen'     => $k['rollen'],
                'abteilung'  => $k['abteilung'],
                'existiert'  => (bool) $k['existiert'],
            ];
        }
        wp_send_json_success( [ 'konten' => $out,
                                'ansicht' => LSV07A_Rollenansicht::karte() ] );
    }
}
