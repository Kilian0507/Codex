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
            wp_send_json_error( [ 'message' => 'Sie sind nicht mehr angemeldet — die Sitzung ist '
                . 'abgelaufen. Bitte die Seite neu laden und erneut anmelden. '
                . 'Die letzte Eingabe wurde nicht gespeichert.', 'code' => 'abgemeldet' ], 403 );
        }
        /* VOR dem Einmal-Schlüssel: Eine zu grosse Anfrage wirft PHP
           komplett weg, mitsamt dem Schlüssel. Ohne diese Frage bekäme
           sie die Antwort „Schlüssel abgelaufen" — die falsche. */
        $zu_gross = LSV07A_Meldung::anfrage_zu_gross();
        if ( $zu_gross !== '' ) {
            wp_send_json_error( [ 'message' => $zu_gross, 'code' => 'zu_gross' ], 413 );
        }
        if ( ! check_ajax_referer( 'lsv07a_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Der Sicherheitsschlüssel dieser Seite ist abgelaufen — '
                . 'das passiert, wenn sie lange offen stand oder Sie sich zwischendurch neu '
                . 'angemeldet haben. Bitte die Seite neu laden. '
                . 'Die letzte Eingabe wurde nicht gespeichert.', 'code' => 'schluessel' ], 403 );
        }
        if ( $schreibt ) LSV07A_Rollenansicht::sperre_pruefen();

        $ok = false;
        switch ( $tor ) {
            case 'zugang':  $ok = LSV07A_Rollen::hat_zugang();   break;
            case 'trainer': $ok = LSV07A_Rollen::ist_trainer();  break;
            case 'wart':    $ok = LSV07A_Rollen::ist_wart();     break;
            /* Die Abteilungsleitung entscheidet über die Rechnungen ihrer
               Abteilung. Das Tor fragt nur nach der Rolle — WELCHE Rechnung
               jemand entscheiden darf, beantwortet
               LSV07A_Rechnung::darf_genehmigen() im Endpunkt. Die Rolle
               allein öffnet also nichts. */
            case 'leiter':  $ok = LSV07A_Rollen::ist_leiter();   break;
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
            'wart' => 'Rolle Wart', 'leiter' => 'Rolle Abteilungsleitung',
            'kasse' => 'Rolle Kasse', 'admin' => 'Rolle Administrator',
            'statistik' => 'Statistik',
        ][ $tor ] ?? $tor;
    }

    /**
     * Was die Oberfläche von den Rechten wissen muss. Maßgeblich bleibt
     * immer die Prüfung im Endpunkt — das hier steuert nur, was sichtbar ist.
     */
    /** Naht für die Prüfungen: Wer das Cookie setzt. Im Betrieb nie gesetzt. */
    public static $cookie_setzer = null;

    /**
     * Der Oberfläche sagen, dass ein Download BEGINNT.
     *
     * Ein Download ist keine Antwort, die ein Skript lesen kann — der
     * Browser legt die Datei ab und meldet nichts. Solange der Server ein
     * Jahrespaket packt, stand der Knopf deshalb einfach still. Jetzt
     * schickt die Oberfläche eine Zufallsmarke mit, und der Server legt
     * sie in dem Moment als Cookie zurück, in dem die Datei kommt.
     *
     * Was das Cookie NICHT ist: kein Recht, keine Sitzung, keine Angabe
     * über die Person oder die Datei. Es trägt genau die Marke, die der
     * Browser selbst gewürfelt hat, lebt eine Minute, und der Server liest
     * es nie. Darum darf — und muss — ein Skript es lesen können.
     *
     * Die Marke wird trotzdem streng geprüft: Sie landet in einer
     * Kopfzeile, und dort hat nichts zu stehen, was nicht aus Kleinbuchstaben
     * und Ziffern besteht.
     *
     * @return string Die gesetzte Marke, oder '' wenn keine gültige kam.
     */
    public static function download_signal() {
        $marke = isset( $_GET['dl'] ) && is_string( $_GET['dl'] ) ? $_GET['dl'] : '';
        if ( ! preg_match( '/\A[a-z0-9]{8,32}\z/', $marke ) ) return '';
        $wie = [
            'expires'  => time() + 60,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ];
        if ( self::$cookie_setzer !== null ) {
            call_user_func( self::$cookie_setzer, 'lsv07a_dl', $marke, $wie );
            return $marke;
        }
        if ( ! headers_sent() ) setcookie( 'lsv07a_dl', $marke, $wie );
        return $marke;
    }

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

    /**
     * Wessen Sicht die LESEWEGE zeigen.
     *
     * Ohne Personenansicht das eigene Konto. Läuft eine, das betrachtete —
     * und zwar NUR beim Lesen: Jeder Schreibweg nimmt weiterhin
     * `get_current_user_id()`, und in der Personenansicht ist er ohnehin
     * gesperrt. Es handelt immer die angemeldete Person, nie die
     * betrachtete.
     */
    public static function blick() {
        return LSV07A_Rollenansicht::blick_uid();
    }

    /** Läuft gerade eine Personenansicht? */
    public static function blickt_fremd() {
        return LSV07A_Rollenansicht::laeuft();
    }

    public static function karte() {
        /* Die Karte beschreibt, WAS ZU SEHEN IST — also die betrachtete
           Person, wenn eine Ansicht läuft. Nur zwei Angaben beschreiben
           weiterhin mich selbst: `ist_admin_echt` und `ansicht`. An ihnen
           hängt der Weg wieder hinaus. */
        $uid = self::blick();
        $u   = get_userdata( $uid );
        return [
            'user_id'   => $uid,
            'name'      => $u ? $u->display_name : wp_get_current_user()->display_name,
            'rollen'    => LSV07A_Rollen::rollen( $uid ),
            'ist_admin' => LSV07A_Rollen::ist_admin( $uid ),
            /* Absichtlich OHNE $uid: Ob ICH Administrator bin, entscheidet
               darüber, ob das Band zum Beenden der Ansicht erscheint. */
            'ist_admin_echt' => LSV07A_Rollen::ist_admin_echt(),
            'ansicht'   => LSV07A_Rollenansicht::karte(),
            /* Die eigene Abteilung — die Oberfläche sagt damit, wer über die
               eigenen Rechnungen entscheidet, und warnt, wenn noch keine
               Abteilung zugeordnet ist. */
            'abteilung' => [
                'id'     => LSV07A_Abteilung::von_konto( $uid ),
                'name'   => LSV07A_Abteilung::name( LSV07A_Abteilung::von_konto( $uid ) ),
                'leitet' => LSV07A_Abteilung::leitet( $uid ),
            ],
            /* Die Quartalsabrechnung sieht nur, wer im internen Bereich
               einer Mannschaft zugeordnet ist: Ohne Mannschaft gibt es
               dort nichts abzurechnen, und es stünden nur leere Listen da.
               Lässt sich die Zuordnung nicht nachsehen (interner Bereich
               fehlt), gilt „ja" — ein Ausfall dort darf niemandem die
               eigene Abrechnung wegnehmen. */
            'mannschaft' => [
                'hat'     => LSV07A_Intern::hat_mannschaft( $uid ),
                'bekannt' => LSV07A_Intern::mannschaft_bekannt(),
            ],
            'tabs'      => [
                'eigene'       => LSV07A_Rollen::ist_trainer( $uid )
                                  && LSV07A_Intern::hat_mannschaft( $uid ),
                'zahlungsdaten'=> LSV07A_Rollen::ist_trainer( $uid ),
                'pruefung'     => LSV07A_Rollen::ist_wart( $uid ),
                /* Eine Rechnung darf jedes Konto mit Zugang einreichen —
                   auch eines ohne Trainerrolle: Material kauft auch, wer
                   nicht am Beckenrand steht. */
                'rechnungen'   => LSV07A_Rollen::hat_zugang( $uid ),
                'genehmigung'  => LSV07A_Rollen::ist_leiter( $uid ),
                'kasse'        => LSV07A_Rollen::ist_kasse( $uid ),
                'archiv'       => LSV07A_Rollen::ist_kasse( $uid ),
                'statistik'    => LSV07A_Rollen::hat_zugang( $uid ),
                'verwaltung'   => LSV07A_Rollen::ist_admin( $uid ),
            ],
        ];
    }
}
