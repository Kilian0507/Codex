<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Alle Endpunkte, ein Muster.
 *
 * Im internen Bereich gibt es drei Arten, einen Endpunkt anzumelden, 31
 * Klassen mit eigenen Toren und 257 Aktionen. Hier steht eine Liste:
 * Name, Methode, verlangtes Recht. Das Tor ist immer dasselbe und steht
 * nicht in der Methode — damit kein Endpunkt je ohne Tor dastehen kann,
 * weil jemand eine Zeile vergessen hat.
 *
 * Alle Aktionen heissen lsv07s_*. Der interne Bereich benutzt lsv07i_*;
 * die beiden können sich also nicht in die Quere kommen, auch wenn beide
 * Plugins laufen.
 *
 * Nur angemeldete Aufrufe: `wp_ajax_nopriv_` wird nirgends angemeldet.
 */
class LSV07S_Ajax {

    /**
     * Endpunkt => [ Methode, verlangtes Recht ].
     *
     * Ein leeres Recht heisst „angemeldet, mit Zugang, nicht gesperrt" —
     * mehr verlangt zum Beispiel die eigene Aufgabenliste nicht.
     */
    private static function karte() {
        return [
            'start'       => [ 'start',       '' ],
            'suche'       => [ 'suche',       '' ],
            'mannschaften'=> [ 'mannschaften', '' ],
            'mannschaft'  => [ 'mannschaft',  '' ],
            'anw_tag'     => [ 'anw_tag',     '' ],
            'anw_setzen'  => [ 'anw_setzen',  '' ],
            'anw_ausfall' => [ 'anw_ausfall', '' ],
            'anw_quote'   => [ 'anw_quote',   '' ],
            'protokoll'   => [ 'protokoll',   LSV07S_Rechte::ADMIN_CONFIG_READ ],
        ];
    }

    public static function init() {
        foreach ( self::karte() as $aktion => $def ) {
            add_action( 'wp_ajax_lsv07s_' . $aktion, function () use ( $def ) {
                LSV07S_Zugang::tor( $def[1] );
                call_user_func( [ __CLASS__, $def[0] ] );
            } );
        }
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private static function sparte() {
        $s = sanitize_key( $_POST['sparte'] ?? $_GET['sparte'] ?? LSV07S_Sparte::SCHWIMMEN );
        if ( ! LSV07S_Sparte::gibt( $s ) ) {
            LSV07S_Zugang::nein( 'Diese Sparte gibt es nicht.', 400 );
        }
        if ( ! LSV07S_Sparte::da( $s ) ) {
            LSV07S_Zugang::nein( 'Diese Sparte ist in dieser Anlage nicht eingerichtet.', 400 );
        }
        return $s;
    }

    private static function zahl( $name ) {
        return absint( $_POST[ $name ] ?? $_GET[ $name ] ?? 0 );
    }

    private static function text( $name, $laenge = 255 ) {
        $w = (string) ( $_POST[ $name ] ?? $_GET[ $name ] ?? '' );
        return substr( sanitize_text_field( $w ), 0, $laenge );
    }

    // ── Die Endpunkte ────────────────────────────────────────────────────

    /** Was die Oberfläche beim Öffnen braucht: Aufgaben und die Karte. */
    public static function start() {
        LSV07S_Zugang::ja( [
            'aufgaben' => LSV07S_Aufgaben::alle(),
            'karte'    => LSV07S_Rechte::karte(),
            'heute'    => LSV07S_Datum::heute(),
            'saison'   => LSV07S_Datum::saison_aktiv(),
        ] );
    }

    public static function suche() {
        LSV07S_Zugang::ja( LSV07S_Suche::suche( self::text( 'wort', 80 ) ) );
    }

    public static function mannschaften() {
        $sparte = self::sparte();
        LSV07S_Zugang::ja( [
            'sparte'       => $sparte,
            'wort_einer'   => LSV07S_Sparte::wort( $sparte, 'einer' ),
            'wort_gruppe'  => LSV07S_Sparte::wort( $sparte, 'gruppe_name' ),
            'mannschaften' => LSV07S_Mannschaft::erlaubte( $sparte ),
        ] );
    }

    public static function mannschaft() {
        $sparte = self::sparte();
        $id     = self::zahl( 'mannschaft_id' );
        $mann   = LSV07S_Mannschaft::holen( $sparte, $id );
        if ( ! $mann ) LSV07S_Zugang::nein( 'Diese Mannschaft gibt es nicht.', 404 );

        /* Sehen darf, wer sie in seiner Liste hat — die Liste entscheidet,
           nicht die Nummer in der Adresse. Sonst wäre jede Mannschaft über
           eine geratene Nummer zu öffnen. */
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( $id, $erlaubt, true ) ) {
            LSV07S_Zugang::nein( 'Für diese Mannschaft fehlt Ihnen die Berechtigung.', 403 );
        }

        LSV07S_Zugang::ja( [
            'mannschaft'   => $mann,
            'sportler'     => LSV07S_Mannschaft::sportler( $sparte, $id ),
            'slots'        => LSV07S_Mannschaft::slots( $sparte, $id ),
            'darf_aendern' => LSV07S_Rechte::darf_mannschaft( $id ),
        ] );
    }

    public static function anw_tag() {
        $sparte = self::sparte();
        $id     = self::zahl( 'mannschaft_id' );
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Sparte::recht( $sparte, 'anw.read' ),
                                            LSV07S_Rechte::MANNSCHAFTSBUCH_READ ] ) ) {
            LSV07S_Zugang::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( $id, $erlaubt, true ) ) {
            LSV07S_Zugang::nein( 'Für diese Mannschaft fehlt Ihnen die Berechtigung.', 403 );
        }
        $tag = LSV07S_Anwesenheit::tag( $sparte, $id, self::text( 'datum', 10 ) );
        if ( ! $tag ) LSV07S_Zugang::nein( 'Diesen Trainingstag gibt es nicht.', 404 );
        $tag['zustaende'] = LSV07S_Anwesenheit::ZUSTAENDE;
        LSV07S_Zugang::ja( $tag );
    }

    public static function anw_setzen() {
        $sparte = self::sparte();
        $erg = LSV07S_Anwesenheit::setzen(
            $sparte,
            self::zahl( 'mannschaft_id' ),
            self::text( 'datum', 10 ),
            self::zahl( 'person_id' ),
            sanitize_key( $_POST['status'] ?? '' ),
            self::zahl( 'verspaetung' ),
            self::text( 'verspaetung_grund', 200 )
        );
        if ( $erg !== true ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( [ 'message' => 'Gespeichert.' ] );
    }

    public static function anw_ausfall() {
        $sparte = self::sparte();
        $erg = LSV07S_Anwesenheit::ausfall(
            $sparte,
            self::zahl( 'mannschaft_id' ),
            self::text( 'von', 10 ),
            self::text( 'bis', 10 ),
            self::text( 'grund', 255 ),
            ! empty( $_POST['an'] )
        );
        if ( $erg !== true ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( [ 'message' => ! empty( $_POST['an'] )
            ? 'Das Training ist als ausgefallen vermerkt.'
            : 'Die Absage ist zurückgenommen.' ] );
    }

    public static function anw_quote() {
        $sparte = self::sparte();
        $id     = self::zahl( 'mannschaft_id' );
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Sparte::recht( $sparte, 'anw.read' ),
                                            LSV07S_Rechte::MANNSCHAFTSBUCH_READ ] ) ) {
            LSV07S_Zugang::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( $id, $erlaubt, true ) ) {
            LSV07S_Zugang::nein( 'Für diese Mannschaft fehlt Ihnen die Berechtigung.', 403 );
        }
        $von = self::text( 'von', 10 );
        $bis = self::text( 'bis', 10 );
        if ( $von === '' || $bis === '' ) {
            $s   = LSV07S_Datum::saison_aktiv();
            $von = $s ? $s['start_datum'] : date( 'Y-01-01', strtotime( LSV07S_Datum::heute() ) );
            $bis = LSV07S_Datum::heute();
        }
        LSV07S_Zugang::ja( [
            'von'   => $von,
            'bis'   => $bis,
            'zeilen'=> LSV07S_Anwesenheit::quote( $sparte, $id, $von, $bis ),
        ] );
    }

    public static function protokoll() {
        LSV07S_Zugang::ja( [
            'zeilen' => LSV07S_Log::letzte( self::zahl( 'anzahl' ) ?: 100, self::zahl( 'offset' ) ),
        ] );
    }
}
