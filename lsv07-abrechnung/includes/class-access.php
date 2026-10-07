<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ein Tor je Aufruf. Jeder AJAX-Endpunkt beginnt mit LSV07A_Access::check().
 *
 * Abgewiesen wird mit HTTP 403 UND einer Erklärung im Text — die Oberfläche
 * zeigt diesen Text an, damit niemand vor einem Knopf steht, der scheinbar
 * nichts tut.
 */
class LSV07A_Access {

    /**
     * @param string $tor      Welche Rolle gefragt ist.
     * @param bool   $schreibt Verändert der Vorgang etwas? Dann ist er in
     *                         der Rollenansicht gesperrt — dort wird nur
     *                         geschaut, damit niemand versehentlich im
     *                         Namen einer fremden Rolle handelt.
     */
    public static function check( $tor = 'zugang', $schreibt = false ) {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Bitte zuerst anmelden.' ], 403 );
        }
        if ( ! check_ajax_referer( 'lsv07a_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage. Bitte die Seite neu laden.' ], 403 );
        }
        if ( $schreibt ) LSV07A_Rollenansicht::sperre_pruefen();

        $ok = false;
        switch ( $tor ) {
            case 'zugang':  $ok = LSV07A_Rollen::hat_zugang();   break;
            case 'trainer': $ok = LSV07A_Rollen::ist_trainer();  break;
            case 'wart':    $ok = LSV07A_Rollen::ist_wart();     break;
            case 'kasse':   $ok = LSV07A_Rollen::ist_kasse();    break;
            case 'admin':   $ok = LSV07A_Rollen::ist_admin();    break;
            // Statistik sehen Wart, Kasse und Administrator über alle
            // Trainer; Trainer nur die eigene (das regelt der Endpunkt).
            case 'statistik': $ok = LSV07A_Rollen::hat_zugang(); break;
        }

        if ( ! $ok ) {
            wp_send_json_error( [
                'message' => 'Dafür fehlt Ihrem Konto die Berechtigung (' . self::tor_name( $tor ) . ').',
            ], 403 );
        }
    }

    private static function tor_name( $tor ) {
        return [
            'zugang' => 'Zugang zur Abrechnung', 'trainer' => 'Rolle Trainer',
            'wart' => 'Rolle Wart', 'kasse' => 'Rolle Kasse', 'admin' => 'Rolle Administrator',
            'statistik' => 'Statistik',
        ][ $tor ] ?? $tor;
    }

    /**
     * Was die Oberfläche von den Rechten wissen muss. Maßgeblich bleibt
     * immer die Prüfung im Endpunkt — das hier steuert nur, was sichtbar ist.
     */
    /**
     * Darf diese Person die Zahlungsdaten einer ANDEREN sehen?
     *
     * Nur die Kasse (sie überweist) und die Administration. Der Wart prüft
     * Stunden und Beträge — Bankverbindung und Wohnanschrift gehen ihn
     * nichts an, und was nicht gebraucht wird, wird auch nicht
     * herausgegeben.
     */
    public static function darf_zahlungsdaten( $uid = null ) {
        $uid = $uid === null ? get_current_user_id() : (int) $uid;
        return LSV07A_Rollen::ist_kasse( $uid ) || LSV07A_Rollen::ist_genau( 'admin', $uid );
    }

    public static function karte() {
        $uid = get_current_user_id();
        return [
            'user_id'   => $uid,
            'name'      => wp_get_current_user()->display_name,
            'rollen'    => LSV07A_Rollen::rollen( $uid ),
            'ist_admin' => LSV07A_Rollen::ist_admin(),
            'ist_admin_echt' => LSV07A_Rollen::ist_admin_echt(),
            'ansicht'   => LSV07A_Rollenansicht::karte(),
            'tabs'      => [
                'eigene'       => LSV07A_Rollen::ist_trainer(),
                'zahlungsdaten'=> LSV07A_Rollen::ist_trainer(),
                'pruefung'     => LSV07A_Rollen::ist_wart(),
                'kasse'        => LSV07A_Rollen::ist_kasse(),
                'statistik'    => LSV07A_Rollen::hat_zugang(),
                'verwaltung'   => LSV07A_Rollen::ist_admin(),
            ],
        ];
    }
}
