<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Das Protokoll — dieselbe Tabelle, aber lückenlos geschrieben.
 *
 * Im internen Bereich fehlt der Protokolleintrag ganz in sieben Bereichen
 * (Mannschaftsbuch, Bestzeiten, Triathlon, Fitness, Schwimmen,
 * Personenverwaltung, Import) und in der Anwesenheit an vier von sechs
 * schreibenden Stellen. Was fehlt, fällt erst auf, wenn man wissen will,
 * wer etwas geändert hat — und dann ist es zu spät.
 *
 * In SwimSys schreibt jede Änderung hierher, und zwar bevor sie passiert,
 * wenn sie etwas unwiederbringlich macht. Geschrieben wird in
 * lsv07i_log, damit das Protokoll des Vereins eines bleibt.
 */
class LSV07S_Log {

    public static function schreibe( $aktion, array $o = [] ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'log' ) ) return false;
        $uid = (int) ( $o['uid'] ?? get_current_user_id() );
        $ok  = $wpdb->insert( LSV07S_Tabellen::t( 'log' ), [
            'user_id'   => $uid,
            'user_name' => $uid ? LSV07S_Person::konto_name( $uid ) : '',
            'aktion'    => substr( (string) $aktion, 0, 80 ),
            /* „swimsys" im Bereich, damit im Protokoll zu sehen ist, aus
               welchem Plugin eine Änderung kam, solange beide laufen. */
            'bereich'   => 'swimsys' . ( ! empty( $o['bereich'] ) ? '/' . $o['bereich'] : '' ),
            'ziel_typ'  => isset( $o['ziel_typ'] ) ? substr( (string) $o['ziel_typ'], 0, 40 ) : null,
            'ziel_id'   => isset( $o['ziel_id'] )  ? substr( (string) $o['ziel_id'], 0, 80 ) : null,
            'ziel_name' => isset( $o['ziel_name'] )? substr( (string) $o['ziel_name'], 0, 200 ) : null,
            'erfolg'    => isset( $o['erfolg'] ) ? ( $o['erfolg'] ? 1 : 0 ) : 1,
            'details'   => isset( $o['details'] ) ? (string) $o['details'] : null,
            'ip'        => self::ip(),
            'zeitpunkt' => LSV07S_Datum::jetzt(),
        ], [ '%d','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s' ] );
        return (bool) $ok;
    }

    /**
     * Die Adresse, gekürzt.
     *
     * Die vollständige IP ist ein personenbezogenes Datum, das für den
     * Zweck „wer hat das geändert" nicht nötig ist — die Kontonummer steht
     * ja daneben. Darum wird das letzte Viertel (IPv4) bzw. die zweite
     * Hälfte (IPv6) entfernt: genug, um einen Zugriff aus dem Vereinsnetz
     * von einem aus dem Ausland zu unterscheiden, zu wenig, um ein Gerät
     * zu verfolgen.
     */
    private static function ip() {
        $roh = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( $roh === '' ) return null;
        if ( strpos( $roh, ':' ) !== false ) {
            $teile = explode( ':', $roh );
            $teile = array_slice( $teile, 0, 4 );
            return implode( ':', $teile ) . '::';
        }
        $teile = explode( '.', $roh );
        if ( count( $teile ) !== 4 ) return null;
        $teile[3] = '0';
        return implode( '.', $teile );
    }

    /** Die letzten Einträge — für die Verwaltung. */
    public static function letzte( $anzahl = 100, $offset = 0 ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'log' ) ) return [];
        $anzahl = max( 1, min( 500, (int) $anzahl ) );
        $offset = max( 0, (int) $offset );
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'log' )
            . ' ORDER BY zeitpunkt DESC, id DESC LIMIT %d OFFSET %d',
            $anzahl, $offset ), ARRAY_A );
    }
}
