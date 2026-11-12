<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Tore der Statistik. Gerechnet wird in LSV07A_Statistik.
 *
 * Drei Endpunkte für die drei Sichten — und für alle drei dieselbe
 * Antwortform, damit die Oberfläche einen einzigen Zeichner braucht.
 *
 *  - eigene     Jedes Konto mit Zugang: nur das eigene Konto.
 *  - abteilung  Abteilungsleitung: genau die Konten ihrer Abteilung —
 *               dieselbe Menge, über die sie auch entscheidet.
 *  - alle       Wart, Kasse, Administration.
 *
 * Die Rolle allein entscheidet nicht, WELCHE Zeilen herauskommen: Das tut
 * LSV07A_Statistik::rahmen(), und zwar im SQL. Gibt rahmen() null zurück,
 * darf dieses Konto diese Sicht nicht haben — dann 403, und keine
 * stillschweigend engere Zahl, die etwas anderes bedeutet als der Reiter
 * behauptet.
 */
class LSV07A_Ajax_Statistik {

    public static function init() {
        add_action( 'wp_ajax_lsv07a_stat_eigene',    [ __CLASS__, 'eigene' ] );
        add_action( 'wp_ajax_lsv07a_stat_abteilung', [ __CLASS__, 'abteilung' ] );
        add_action( 'wp_ajax_lsv07a_stat_alle',      [ __CLASS__, 'alle' ] );
    }

    private static function jahr_aus_post() {
        $jahr = (int) ( $_POST['jahr'] ?? date( 'Y' ) );
        if ( ! LSV07A_Berechnung::jahr_gueltig( $jahr ) ) {
            wp_send_json_error( [ 'message' => LSV07A_Meldung::jahr() ] );
        }
        return $jahr;
    }

    /**
     * Die eigenen Zahlen.
     *
     * Tor ist „zugang" und nicht „trainer": Eine Rechnung darf jedes Konto
     * mit Zugang einreichen, auch eines ohne Trainerrolle. Vorher lief
     * genau dieses Konto in eine Fehlermeldung — es durfte seine eigenen
     * Zahlen nicht sehen. Mehr Zeilen gibt das nicht heraus: Der Rahmen
     * filtert weiterhin auf das eigene Konto.
     */
    public static function eigene() {
        LSV07A_Access::check( 'zugang' );
        self::antwort( 'eigene' );
    }

    public static function abteilung() {
        LSV07A_Access::check( 'leiter' );
        self::antwort( 'abteilung' );
    }

    public static function alle() {
        LSV07A_Access::check( 'statistik' );
        self::antwort( 'alle' );
    }

    /** Eine Antwortform für alle drei Sichten. */
    private static function antwort( $sicht ) {
        $jahr = self::jahr_aus_post();
        $r    = LSV07A_Statistik::rahmen( $sicht, LSV07A_Access::blick() );

        if ( $r === null ) {
            wp_send_json_error( [ 'message' => self::absage( $sicht ) ], 403 );
        }

        $abr   = LSV07A_Statistik::abrechnungen( $r, $jahr );
        $rg    = LSV07A_Statistik::rechnungen( $r, $jahr );
        $durch = LSV07A_Statistik::durchlauf( $r, $jahr );
        $stau  = LSV07A_Statistik::rueckstau( $r, $jahr );

        $u = get_userdata( (int) $r['uid'] );

        wp_send_json_success( [
            'jahr'         => $jahr,
            'sicht'        => $r['sicht'],
            'beschriftung' => $r['beschriftung'],
            'name'         => $u ? $u->display_name : ( 'Konto ' . (int) $r['uid'] ),
            'kennzahlen'   => LSV07A_Statistik::kennzahlen( $r, $jahr, $abr, $rg, $durch, $stau ),
            'abrechnung'   => $abr,
            'rechnung'     => $rg,
            'durchlauf'    => $durch,
            'rueckstau'    => $stau,
            /* Nur in der eigenen Sicht: Für den ganzen Verein müsste der
               interne Bereich je Trainer einzeln befragt werden. */
            'nicht_uebernommen' => $sicht === 'eigene'
                ? LSV07A_Statistik::nicht_uebernommen( (int) $r['uid'], $jahr ) : null,
            'namen' => [
                'typ'    => array_map( [ 'LSV07A_Berechnung', 'typ_name' ],
                                array_combine( LSV07A_DB::POSTEN_TYPEN, LSV07A_DB::POSTEN_TYPEN ) ),
                'status' => array_map( [ 'LSV07A_Berechnung', 'status_name' ],
                                array_combine( LSV07A_DB::STATUS, LSV07A_DB::STATUS ) ),
                'art'    => array_map( [ 'LSV07A_Berechnung', 'art_name' ],
                                array_combine( LSV07A_DB::ARTEN, LSV07A_DB::ARTEN ) ),
                'monat'  => [ 1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                              'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' ],
            ],
        ] );
    }

    private static function absage( $sicht ) {
        if ( $sicht === 'alle' ) {
            return 'Die Zahlen aller Konten sehen nur Wart, Kasse und Administration. '
                 . 'Ihre eigenen finden Sie unter „Meine".';
        }
        if ( $sicht === 'abteilung' ) {
            return 'Die Zahlen einer Abteilung sieht nur deren Leitung.';
        }
        return 'Dafür fehlt Ihrem Konto die Berechtigung.';
    }
}
