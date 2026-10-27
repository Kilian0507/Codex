<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Endpunkte der „Anderen Abrechnung" — eigene Rechnungen, Genehmigung
 * durch die Abteilungsleitung, Zahlung durch die Kasse und das Archiv.
 *
 * Jeder Weg beginnt mit LSV07A_Access::check(); darüber hinaus fragt jeder
 * Weg die EINE Frage, die zu ihm gehört: Ist das meine Rechnung? Gehört sie
 * zu meiner Abteilung? Ist sie genehmigt? Die Antworten stehen in
 * LSV07A_Rechnung — hier wird nur gefragt, nie neu entschieden.
 */
class LSV07A_Ajax_Rechnung {

    public static function init() {
        $map = [
            /* Die eigene Sicht */
            'lsv07a_r_meine'      => 'meine',
            'lsv07a_r_neu'        => 'neu',
            'lsv07a_r_speichern'  => 'speichern',
            'lsv07a_r_weg'        => 'weg',
            'lsv07a_r_einreichen' => 'einreichen',
            'lsv07a_r_zurueckholen' => 'zurueckholen',
            'lsv07a_r_beleg'      => 'beleg',
            'lsv07a_r_beleg_weg'  => 'beleg_weg',
            /* Die Abteilungsleitung */
            'lsv07a_r_pruefung'   => 'pruefung',
            'lsv07a_r_genehmigen' => 'genehmigen',
            'lsv07a_r_zurueck'    => 'zurueck',
            /* Löschen: Wer darf, steht in LSV07A_Loeschung — Leitung in der
               eigenen Abteilung, Kasse ab genehmigt, Administration immer,
               die Person selbst solange es ein Entwurf ist. */
            'lsv07a_r_loeschen'   => 'loeschen',
            /* Die Kasse */
            'lsv07a_r_kasse'      => 'kasse',
            'lsv07a_r_giro'       => 'giro',
            'lsv07a_r_bezahlt'    => 'bezahlt',
            /* Das Archiv */
            'lsv07a_r_archiv'     => 'archiv',
            /* Herunterladen. Diese drei antworten nicht mit JSON, sondern
               mit einer Datei — darum per GET, damit der Browser sie als
               Download behandelt. Der Einmal-Schlüssel wandert dafür in die
               Adresse; geprüft wird genau wie überall sonst. */
            'lsv07a_r_datei'      => 'datei',
            'lsv07a_r_blatt'      => 'blatt',
            'lsv07a_r_jahr'       => 'jahr',
        ];
        foreach ( $map as $aktion => $methode ) {
            /* Kein nopriv: Ohne Anmeldung gibt es hier nichts. */
            add_action( 'wp_ajax_' . $aktion, [ __CLASS__, $methode ] );
        }
    }

    /** Naht für die Prüfungen: Wohin eine fertige Datei geht. */
    public static $ausgabe = null;

    private static function id() { return absint( $_POST['rechnung_id'] ?? 0 ); }

    /** Die Felder, die aus dem Formular kommen dürfen. */
    private static function felder() {
        $f = [];
        foreach ( [ 'titel', 'zweck', 'betrag', 'rechnungsdatum', 'kommentar',
                    'empf_name', 'empf_iban', 'empf_bic' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $f[ $n ] = sanitize_text_field( wp_unslash( $_POST[ $n ] ) );
        }
        if ( isset( $_POST['empf_eigen'] ) ) {
            $f['empf_eigen'] = (string) $_POST['empf_eigen'] === '1';
        }
        return $f;
    }

    /** Was die Oberfläche braucht, um ein Formular zu füllen. */
    private static function rahmen() {
        $uid  = LSV07A_Access::blick();
        $pers = LSV07A_Person::holen( $uid );
        return [
            'abteilung_id' => (int) $pers['abteilung_id'],
            'abteilung'    => LSV07A_Abteilung::name( $pers['abteilung_id'] ),
            'stammdaten'   => [
                'kontoinhaber' => (string) $pers['kontoinhaber'],
                'iban'         => (string) $pers['iban'],
                'bic'          => (string) $pers['bic'],
                /* Ohne Zahlungsdaten geht nichts — das soll die Oberfläche
                   sagen können, bevor jemand eine Rechnung tippt. */
                'vollstaendig' => trim( (string) $pers['kontoinhaber'] ) !== ''
                                  && LSV07A_QR::iban( $pers['iban'] ) !== '',
            ],
        ];
    }

    // ── Die eigene Sicht ─────────────────────────────────────────────────

    public static function meine() {
        LSV07A_Access::check( 'zugang' );
        $jahr = (int) ( $_POST['jahr'] ?? 0 );
        wp_send_json_success( [
            'rechnungen' => LSV07A_Rechnung::meine( LSV07A_Access::blick(), $jahr ),
            'rahmen'     => self::rahmen(),
        ] );
    }

    public static function neu() {
        LSV07A_Access::check( 'zugang', true );
        $erg = LSV07A_Rechnung::anlegen( get_current_user_id(), self::felder() );
        if ( ! is_int( $erg ) ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'rechnung_id' => $erg,
                                'rechnung'    => LSV07A_Rechnung::voll( $erg ) ] );
    }

    public static function speichern() {
        LSV07A_Access::check( 'zugang', true );
        $id = self::id();
        $r  = LSV07A_Rechnung::holen( $id );
        if ( ! $r ) wp_send_json_error( [ 'message' => 'Diese Rechnung gibt es nicht.' ], 404 );
        if ( ! LSV07A_Rechnung::darf_aendern( $r ) ) {
            wp_send_json_error( [ 'message' =>
                'Diese Rechnung gehört nicht dir oder ist bereits eingereicht.' ], 403 );
        }
        $erg = LSV07A_Rechnung::aendern( $id, self::felder() );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'rechnung' => LSV07A_Rechnung::voll( $id ) ] );
    }

    public static function weg() {
        LSV07A_Access::check( 'zugang', true );
        /* Derselbe Weg wie beim Löschen aus Prüfung und Kasse: Es gibt eine
           Stelle, die „wer darf was" beantwortet. Für den eigenen Entwurf
           verlangt sie keine Begründung — er war nie eingereicht. */
        $erg = LSV07A_Loeschung::rechnung( self::id(),
            sanitize_textarea_field( $_POST['grund'] ?? '' ) );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'message' => 'Rechnung gelöscht.' ] );
    }

    /**
     * Eine Rechnung löschen — aus Prüfung, Kasse oder Verwaltung heraus.
     *
     * Das Tor steht auf „zugang": Jedes Konto mit Zugang darf den Endpunkt
     * rufen, und erst LSV07A_Loeschung entscheidet, ob es DIESE Rechnung
     * sein darf. So gibt es eine Antwort statt drei Endpunkte mit je eigener
     * Rechteprüfung.
     */
    public static function loeschen() {
        LSV07A_Access::check( 'zugang', true );
        $erg = LSV07A_Loeschung::rechnung( self::id(),
            sanitize_textarea_field( $_POST['grund'] ?? '' ) );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ], 403 );
        wp_send_json_success( [ 'message' => 'Rechnung gelöscht.' ] );
    }

    public static function einreichen() {
        LSV07A_Access::check( 'zugang', true );
        $id = self::id();
        $r  = LSV07A_Rechnung::holen( $id );
        if ( ! $r ) wp_send_json_error( [ 'message' => 'Diese Rechnung gibt es nicht.' ], 404 );
        if ( (int) $r['wp_user_id'] !== get_current_user_id() ) {
            wp_send_json_error( [ 'message' => 'Das ist nicht deine Rechnung.' ], 403 );
        }
        $erg = LSV07A_Rechnung::einreichen( $id );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );

        LSV07A_Nachricht::rechnung_eingereicht( LSV07A_Rechnung::holen( $id ) );
        wp_send_json_success( [ 'rechnung' => LSV07A_Rechnung::voll( $id ),
                                'message'  => 'Eingereicht. Die Abteilungsleitung entscheidet.' ] );
    }

    public static function zurueckholen() {
        LSV07A_Access::check( 'zugang', true );
        $erg = LSV07A_Rechnung::zurueckholen( self::id() );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'rechnung' => LSV07A_Rechnung::voll( self::id() ) ] );
    }

    public static function beleg() {
        LSV07A_Access::check( 'zugang', true );
        $id = absint( $_POST['rechnung_id'] ?? 0 );
        $r  = LSV07A_Rechnung::holen( $id );
        if ( ! $r ) wp_send_json_error( [ 'message' => 'Diese Rechnung gibt es nicht.' ], 404 );
        if ( ! LSV07A_Rechnung::darf_aendern( $r ) ) {
            wp_send_json_error( [ 'message' =>
                'Diese Rechnung gehört nicht dir oder ist bereits eingereicht.' ], 403 );
        }
        if ( empty( $_FILES['datei'] ) || ! is_array( $_FILES['datei'] ) ) {
            wp_send_json_error( [ 'message' => 'Es ist keine Datei angekommen.' ] );
        }
        if ( (int) ( $_FILES['datei']['error'] ?? 1 ) !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'Der Upload ist unterwegs abgebrochen.' ] );
        }

        $erg = LSV07A_Rechnung::ablegen( $id, $_FILES['datei'] );
        if ( empty( $erg['ok'] ) ) wp_send_json_error( [ 'message' => $erg['message'] ] );
        wp_send_json_success( [ 'rechnung' => LSV07A_Rechnung::voll( $id ) ] );
    }

    public static function beleg_weg() {
        LSV07A_Access::check( 'zugang', true );
        $did = absint( $_POST['datei_id'] ?? 0 );
        $d   = LSV07A_Rechnung::datei_holen( $did );
        if ( ! $d ) wp_send_json_error( [ 'message' => 'Diesen Beleg gibt es nicht.' ], 404 );
        $erg = LSV07A_Rechnung::datei_loeschen( $did );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ], 403 );
        wp_send_json_success( [ 'rechnung' => LSV07A_Rechnung::voll( (int) $d['rechnung_id'] ) ] );
    }

    // ── Die Abteilungsleitung ────────────────────────────────────────────

    public static function pruefung() {
        LSV07A_Access::check( 'leiter' );
        $status = sanitize_text_field( $_POST['status'] ?? 'eingereicht' );
        wp_send_json_success( [
            'rechnungen' => LSV07A_Rechnung::zur_genehmigung( LSV07A_Access::blick(), $status ),
            'abteilung'  => LSV07A_Abteilung::beschriftung( LSV07A_Access::blick() ),
            'offen'      => count( LSV07A_Rechnung::zur_genehmigung(
                                LSV07A_Access::blick(), 'eingereicht' ) ),
        ] );
    }

    public static function genehmigen() {
        LSV07A_Access::check( 'leiter', true );
        $id  = self::id();
        $erg = LSV07A_Rechnung::genehmigen( $id );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ], 403 );
        LSV07A_Nachricht::rechnung_entschieden( LSV07A_Rechnung::holen( $id ), 'genehmigt' );
        wp_send_json_success( [ 'message' => 'Genehmigt. Die Kasse kann jetzt überweisen.' ] );
    }

    public static function zurueck() {
        LSV07A_Access::check( 'leiter', true );
        $id    = self::id();
        $grund = sanitize_textarea_field( wp_unslash( $_POST['grund'] ?? '' ) );
        $erg   = LSV07A_Rechnung::zurueckgeben( $id, $grund );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ], 403 );
        LSV07A_Nachricht::rechnung_entschieden( LSV07A_Rechnung::holen( $id ), 'zurueck' );
        wp_send_json_success( [ 'message' => 'Zurückgegeben.' ] );
    }

    // ── Die Kasse ────────────────────────────────────────────────────────

    public static function kasse() {
        LSV07A_Access::check( 'kasse' );
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        $jahr   = (int) ( $_POST['jahr'] ?? 0 );
        $liste  = LSV07A_Rechnung::fuer_kasse( $status, $jahr );

        $offen = 0.0; $bezahlt = 0.0;
        foreach ( $liste as $r ) {
            if ( $r['status'] === 'bezahlt' ) $bezahlt += $r['betrag']; else $offen += $r['betrag'];
        }
        wp_send_json_success( [
            'rechnungen'    => $liste,
            'summe_offen'   => round( $offen, 2 ),
            'summe_bezahlt' => round( $bezahlt, 2 ),
        ] );
    }

    /**
     * Der QR-Code zu einer Rechnung.
     *
     * Nur für genehmigte und bezahlte: Ein Girocode ist eine fertige
     * Überweisung. Solange niemand genehmigt hat, soll es sie nicht geben —
     * auch nicht als Bild, das jemand versehentlich scannt.
     */
    public static function giro() {
        LSV07A_Access::check( 'kasse' );
        $id = self::id();
        $r  = LSV07A_Rechnung::holen( $id );
        if ( ! $r ) wp_send_json_error( [ 'message' => 'Diese Rechnung gibt es nicht.' ], 404 );
        if ( ! in_array( $r['status'], [ 'genehmigt', 'bezahlt' ], true ) ) {
            wp_send_json_error( [ 'message' =>
                'Diese Rechnung ist noch nicht genehmigt — einen QR-Code gibt es dafür nicht.' ], 403 );
        }

        $giro = LSV07A_Rechnung::giro( $id );
        if ( isset( $giro['fehler'] ) ) wp_send_json_error( [ 'message' => $giro['fehler'] ] );

        wp_send_json_success( [
            'svg'      => $giro['svg'],
            'zweck'    => $giro['zweck'],
            'rechnung' => LSV07A_Rechnung::voll( $id ),
        ] );
    }

    public static function bezahlt() {
        LSV07A_Access::check( 'kasse', true );
        $id  = self::id();
        $erg = LSV07A_Rechnung::bezahlt( $id );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        LSV07A_Nachricht::rechnung_entschieden( LSV07A_Rechnung::holen( $id ), 'bezahlt' );
        wp_send_json_success( [ 'message' => 'Als bezahlt vermerkt.' ] );
    }

    // ── Das Archiv ───────────────────────────────────────────────────────

    /**
     * Das Archiv sieht, wer bezahlt (Kasse) oder verwaltet. Eine
     * Abteilungsleitung sieht ihre eigenen Entscheidungen in der Prüfung —
     * das Archiv ist die Sicht der Kassenprüfung auf alles Bezahlte.
     */
    public static function archiv() {
        LSV07A_Access::check( 'kasse' );
        $jahr = (int) ( $_POST['jahr'] ?? 0 );
        wp_send_json_success( [
            'jahre'      => LSV07A_Rechnung::archiv_jahre(),
            'jahr'       => $jahr,
            'rechnungen' => $jahr ? LSV07A_Rechnung::archiv( $jahr ) : [],
        ] );
    }

    // ── Herunterladen ────────────────────────────────────────────────────

    /** Die Adresse, unter der ein Download liegt. */
    public static function adresse( $aktion, array $args = [] ) {
        return add_query_arg( array_merge( [
            'action' => 'lsv07a_r_' . $aktion,
            'nonce'  => wp_create_nonce( 'lsv07a_nonce' ),
        ], $args ), admin_url( 'admin-ajax.php' ) );
    }

    /** Abbrechen mit einer Seite statt mit JSON — es läuft ein Download. */
    private static function raus( $code, $text ) {
        status_header( $code );
        wp_die( esc_html( $text ), 'Nicht möglich', [ 'response' => $code ] );
    }

    /**
     * Vor dem ersten Byte jeden Puffer leeren. Eine einzige Leerzeile aus
     * einem anderen Plugin landete sonst mitten in der Datei und machte sie
     * unbrauchbar.
     */
    private static function senden( $inhalt, $typ, $name, $inline = false ) {
        /* Naht für die Prüfungen. Ein Download endet mit exit — ein Test
           käme danach nicht weiter und könnte nicht nachsehen, WAS
           ausgeliefert wurde. Im Betrieb ist sie nie gesetzt. */
        if ( self::$ausgabe !== null ) {
            call_user_func( self::$ausgabe, [
                'inhalt' => $inhalt, 'typ' => $typ, 'name' => $name, 'inline' => (bool) $inline ] );
            return;
        }
        while ( ob_get_level() > 0 ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: ' . $typ );
        header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' )
                . '; filename="' . $name . '"' );
        header( 'Content-Length: ' . strlen( $inhalt ) );
        header( 'X-Content-Type-Options: nosniff' );
        echo $inhalt;
        exit;
    }

    /**
     * Ein einzelner Beleg.
     *
     * Die Datei liegt ausserhalb des Webverzeichnisses; dieser Endpunkt ist
     * der einzige Weg an sie heran, und er stellt dieselbe Frage wie die
     * Ansicht: Darf diese Person diese Rechnung sehen?
     *
     * Ausgeliefert wird NICHT als `inline`, sondern als Download — ein PDF
     * oder ein Bild, das der Browser im eigenen Fenster öffnet, läuft in
     * der Herkunft der Seite; als Anhang kann es das nicht.
     */
    public static function datei() {
        LSV07A_Access::check( 'zugang' );
        $did = absint( $_GET['datei_id'] ?? 0 );
        $d   = LSV07A_Rechnung::datei_holen( $did );

        /* „Gibt es nicht" und „darfst du nicht" antworten GLEICH.
           Unterschiedliche Antworten wären ein Orakel: Wer Nummern
           durchzählt, erführe sonst, welche Belege es gibt — und damit,
           wie viele Rechnungen der Verein führt. */
        $r = $d ? LSV07A_Rechnung::holen( (int) $d['rechnung_id'] ) : null;
        if ( ! $d || ! $r || ! LSV07A_Rechnung::darf_sehen( $r ) || ! is_file( $d['pfad'] ) ) {
            self::raus( 404, 'Diesen Beleg gibt es nicht, oder er gehört zu einer Rechnung, '
                           . 'die Sie nicht sehen dürfen.' );
        }

        /* Über inhalt(): Die Datei liegt verschlüsselt auf der Platte, und
           der Rohstand darf nirgends herausgehen. */
        $inhalt = LSV07A_Rechnung::inhalt( $d );
        if ( $inhalt === null ) {
            self::raus( 500, 'Dieser Beleg liess sich nicht entschlüsseln. Wurde der '
                           . 'Schlüssel in wp-config.php geändert oder die Datei verändert, '
                           . 'ist sie nicht mehr lesbar.' );
        }

        /* Der Typ kommt aus der eigenen Tabelle und wurde beim Hochladen an
           den Bytes geprüft — nicht aus dem, was jetzt jemand mitschickt. */
        $typ = isset( LSV07A_Rechnung::ERLAUBT[ $d['mime_type'] ] )
             ? (string) $d['mime_type'] : 'application/octet-stream';
        $endung = LSV07A_Rechnung::ERLAUBT[ $typ ] ?? 'bin';

        /* Protokolliert wird nur, wenn jemand einen FREMDEN Beleg liest.
           Dass die eigene Rechnung geöffnet wird, ist kein Vorgang, über den
           Buch geführt werden müsste — und ein Protokoll, in dem das
           Alltägliche steht, verdeckt das Besondere. */
        if ( (int) $r['wp_user_id'] !== get_current_user_id() ) {
            LSV07A_Log::schreibe( 'rechnung.beleg_gelesen', [
                'ziel_typ' => 'rechnung', 'ziel_id' => (int) $r['id'] ] );
        }
        self::senden( $inhalt, $typ, LSV07A_Rechnung::dateiname( $r, $endung ) );
    }

    /** Das Rechnungsblatt als PDF. */
    public static function blatt() {
        LSV07A_Access::check( 'zugang' );
        $id = absint( $_GET['rechnung_id'] ?? 0 );
        $r  = LSV07A_Rechnung::holen( $id );
        /* Wie beim Beleg: eine Antwort für beide Fälle. */
        if ( ! $r || ! LSV07A_Rechnung::darf_sehen( $r ) ) {
            self::raus( 404, 'Diese Rechnung gibt es nicht, oder sie gehört zu einem '
                           . 'anderen Konto.' );
        }

        $pdf = LSV07A_Archiv::blatt( LSV07A_Rechnung::voll( $id ) );
        if ( $pdf === '' ) self::raus( 500, 'Das Blatt liess sich nicht erzeugen.' );
        self::senden( $pdf, 'application/pdf', LSV07A_Rechnung::dateiname( $r, 'pdf' ) );
    }

    /**
     * Ein ganzes Jahr als ZIP.
     *
     * Nur Kasse und Administration: Das Paket enthält jede Bankverbindung
     * und jeden Beleg eines Jahres. Das ist die Sicht der Kassenprüfung und
     * keine, die man nebenbei vergibt.
     */
    public static function jahr() {
        LSV07A_Access::check( 'kasse' );
        $jahr = absint( $_GET['jahr'] ?? 0 );
        if ( $jahr < 2000 || $jahr > 2200 ) self::raus( 400, 'Dieses Jahr gibt es nicht.' );

        $paket = LSV07A_Archiv::zip( $jahr );
        if ( ! $paket ) self::raus( 404, 'Für ' . $jahr . ' ist nichts als bezahlt vermerkt.' );
        /* Zu groß für den Arbeitsspeicher: Das wird GESAGT, statt in einer
           weißen Seite zu enden, die niemand deuten kann. */
        if ( isset( $paket['fehler'] ) ) self::raus( 413, $paket['fehler'] );
        self::senden( $paket['inhalt'], 'application/zip', $paket['name'] );
    }
}
