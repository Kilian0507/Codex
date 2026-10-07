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
        foreach ( [ 'nachrichten', 'nachricht_gelesen', 'ansicht_setzen' ] as $a ) {
            add_action( 'wp_ajax_lsv07a_sys_' . $a, [ __CLASS__, $a ] );
        }
    }

    /**
     * Die eigenen Mitteilungen. Wird regelmässig abgefragt, deshalb
     * schlank: nur Zahl und Liste, keine Berechnungen.
     */
    public static function nachrichten() {
        LSV07A_Access::check( 'zugang' );
        $uid = get_current_user_id();

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
        LSV07A_Access::check( 'zugang' );
        $uid = get_current_user_id();
        $id  = absint( $_POST['id'] ?? 0 );
        // gelesen() schränkt selbst noch einmal auf das eigene Konto ein —
        // eine fremde ID bewirkt deshalb nichts.
        LSV07A_Nachricht::gelesen( $uid, $id );
        wp_send_json_success( [ 'offen' => LSV07A_Nachricht::offen( $uid ) ] );
    }

    /**
     * Rollenansicht starten oder beenden. Nur der echte Administrator —
     * und `ist_admin_echt()` ist entscheidend, denn wer gerade in der
     * Ansicht steht, gilt dort nicht als Administrator und käme sonst
     * nicht wieder heraus.
     */
    public static function ansicht_setzen() {
        LSV07A_Access::check( 'zugang' );
        if ( ! LSV07A_Rollen::ist_admin_echt() ) {
            wp_send_json_error( [ 'message' => 'Die Rollenansicht ist der Administration vorbehalten.' ], 403 );
        }
        $rolle = sanitize_text_field( $_POST['rolle'] ?? '' );
        if ( $rolle !== '' && ! in_array( $rolle, [ 'trainer', 'wart', 'kasse' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Diese Rolle gibt es nicht.' ] );
        }
        if ( ! LSV07A_Rollenansicht::setzen( $rolle ) ) {
            wp_send_json_error( [ 'message' => 'Die Rollenansicht liess sich nicht umstellen.' ] );
        }
        wp_send_json_success( [
            'message' => $rolle === ''
                ? 'Rollenansicht beendet.'
                : 'Sie sehen die Abrechnung jetzt als ' . ucfirst( $rolle ) . '.',
            'ansicht' => LSV07A_Rollenansicht::karte(),
        ] );
    }
}
