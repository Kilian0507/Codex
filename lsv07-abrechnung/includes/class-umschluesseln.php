<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Alte Belege und IBANs auf den Schlüssel in der Schlüsseldatei umstellen.
 *
 * Warum. Liegt der Schlüssel in einer Datei über dem Webordner (siehe
 * LSV07A_Tresor), sind NEUE Inhalte gegen eine gestohlene Datenbank oder ein
 * gestohlenes WordPress-Backup geschützt. Die ALTEN sind es erst, wenn sie
 * mit diesem Schlüssel neu verschlüsselt sind und der alte Schlüssel nicht
 * mehr in der Datenbank steht. Das erledigt diese Klasse — im Hintergrund,
 * in kleinen Portionen, ohne dass jemand etwas tun muss.
 *
 * Reihenfolge, damit unterwegs NICHTS verloren geht:
 *  1. Belege, dann IBANs der Personen, dann IBANs auf Rechnungen
 *     umschlüsseln. Jeder neue Inhalt wird VOR dem Schreiben mit dem neuen
 *     Schlüssel allein geöffnet und verglichen; Dateien werden über eine
 *     Nachbardatei ersetzt, Felder nur, wenn sie sich inzwischen nicht
 *     geändert haben.
 *  2. Prüfen: Lässt sich ALLES mit dem neuen Schlüssel allein öffnen?
 *  3. Erst dann wandert der alte Datenbank-Schlüssel als Lese-Schlüssel in
 *     die Schlüsseldatei und wird aus der Datenbank gelöscht. Selbst wenn
 *     die Prüfung etwas übersehen hätte, bleibt er damit verfügbar — nur
 *     nicht mehr in der Datenbank.
 *
 * Schlägt etwas fehl, bleibt der alte Schlüssel stehen, und die Verwaltung
 * zeigt, was nicht ging.
 */
class LSV07A_Umschluesseln {

    const STAND = 'lsv07a_umschluesseln';
    const HAKEN = 'lsv07a_umschluesseln';
    const SPERRE = 'lsv07a_umschluesseln_lauf';

    const PHASEN = [ 'belege', 'person', 'rechnung', 'pruef_belege', 'pruef_person', 'pruef_rechnung', 'abschluss' ];

    const TEXTE = [
        'belege'         => 'Belege werden umgeschlüsselt',
        'person'         => 'Bankverbindungen der Personen werden umgeschlüsselt',
        'rechnung'       => 'Bankverbindungen auf Rechnungen werden umgeschlüsselt',
        'pruef_belege'   => 'Belege werden geprüft',
        'pruef_person'   => 'Bankverbindungen werden geprüft',
        'pruef_rechnung' => 'Bankverbindungen auf Rechnungen werden geprüft',
        'abschluss'      => 'Alter Schlüssel wird aus der Datenbank entfernt',
        'fertig'         => 'Abgeschlossen — alle Inhalte liegen unter dem Schlüssel in der Schlüsseldatei',
        'fehler'         => 'Angehalten — der alte Schlüssel bleibt vorerst in der Datenbank',
    ];

    public static function init() {
        add_action( self::HAKEN, [ __CLASS__, 'cron' ] );
    }

    private static function t( $n ) { global $wpdb; return $wpdb->prefix . $n; }

    /** Gilt das hier überhaupt? Nur, wenn der Hauptschlüssel aus der Datei kommt. */
    public static function zustaendig() {
        return class_exists( 'LSV07A_Tresor' ) && LSV07A_Tresor::quelle() === 'datei';
    }

    private static function lesen() {
        $s = get_option( self::STAND, [] );
        $s = is_array( $s ) ? $s : [];
        return $s + [ 'phase' => 'belege', 'cursor' => 0, 'neu' => 0, 'fehlt' => 0,
                      'klartext' => 0, 'fehler_ids' => [], 'meldung' => '', 'fertig_am' => '' ];
    }

    private static function schreiben( array $s ) {
        update_option( self::STAND, $s, false );
    }

    /** Für die Verwaltung. */
    public static function stand() {
        if ( ! self::zustaendig() ) {
            return [ 'noetig' => false, 'phase' => '', 'text' => '' ];
        }
        $s = self::lesen();
        return [
            'noetig'    => true,
            'phase'     => $s['phase'],
            'text'      => self::TEXTE[ $s['phase'] ] ?? $s['phase'],
            'fertig'    => $s['phase'] === 'fertig',
            'fehler'    => $s['phase'] === 'fehler',
            'meldung'   => (string) $s['meldung'],
            'neu'       => (int) $s['neu'],
            'fehlt'     => (int) $s['fehlt'],
            'klartext'  => (int) $s['klartext'],
            'fertig_am' => (string) $s['fertig_am'],
            'alt_in_db' => (string) get_option( LSV07A_Tresor::OPTION, '' ) !== '',
        ];
    }

    /** Im Hintergrund weitermachen (WP-Cron), solange noch etwas offen ist. */
    public static function planen() {
        if ( ! function_exists( 'wp_schedule_single_event' ) || ! self::zustaendig() ) return;
        $s = self::lesen();
        if ( in_array( $s['phase'], [ 'fertig', 'fehler' ], true ) ) return;
        if ( ! wp_next_scheduled( self::HAKEN ) ) wp_schedule_single_event( time() + 30, self::HAKEN );
    }

    public static function cron() {
        self::schritt( 20 );
        self::planen();
    }

    /** Nach einem Fehler von vorn beginnen (Knopf in der Verwaltung). */
    public static function neu_starten() {
        $s = self::lesen();
        if ( $s['phase'] !== 'fehler' ) return;
        self::schreiben( [ 'phase' => 'belege', 'cursor' => 0 ] );
        self::planen();
    }

    /**
     * Arbeiten, bis die Zeit um ist oder nichts mehr offen ist.
     * Nie zwei Läufe zugleich (Sperre über ein Transient).
     */
    public static function schritt( $sekunden = 8 ) {
        if ( ! self::zustaendig() ) return self::stand();
        if ( get_transient( self::SPERRE ) ) return self::stand();
        set_transient( self::SPERRE, 1, 120 );
        $ende = microtime( true ) + max( 1, (int) $sekunden );
        try {
            while ( microtime( true ) < $ende ) {
                $s = self::lesen();
                if ( in_array( $s['phase'], [ 'fertig', 'fehler' ], true ) ) break;
                $s = self::portion( $s );
                self::schreiben( $s );
            }
        } finally {
            delete_transient( self::SPERRE );
        }
        return self::stand();
    }

    /** Eine Portion der laufenden Phase. */
    private static function portion( array $s ) {
        global $wpdb;
        $phase = $s['phase'];

        if ( $phase === 'abschluss' ) {
            if ( ! LSV07A_Tresor::db_schluessel_verlegen() ) {
                $s['phase'] = 'fehler';
                $s['meldung'] = 'Der alte Schlüssel liess sich nicht in die Schlüsseldatei übernehmen. '
                              . 'Er bleibt darum in der Datenbank; es ist nichts verloren.';
                return $s;
            }
            $s['phase'] = 'fertig';
            $s['fertig_am'] = current_time( 'mysql' );
            LSV07A_Log::schreibe( 'tresor.umgeschluesselt', [
                'details' => (int) $s['neu'] . ' Inhalte umgeschlüsselt' ] );
            return $s;
        }

        $belege = in_array( $phase, [ 'belege', 'pruef_belege' ], true );
        $tabelle = $belege ? 'lsv07a_rechnung_datei'
                 : ( in_array( $phase, [ 'person', 'pruef_person' ], true ) ? 'lsv07a_person' : 'lsv07a_rechnung' );
        $spalte  = $tabelle === 'lsv07a_person' ? 'iban' : 'empf_iban';
        $felder  = $belege ? 'id' : "id, wp_user_id, $spalte";
        $zeilen  = $wpdb->get_results( $wpdb->prepare(
            "SELECT $felder FROM " . self::t( $tabelle ) . " WHERE id > %d ORDER BY id ASC LIMIT %d",
            (int) $s['cursor'], $belege ? 20 : 100 ), ARRAY_A ) ?: [];

        if ( ! $zeilen ) {
            $i = array_search( $phase, self::PHASEN, true );
            /* Nach den Prüfphasen: Gab es Fehler, wird angehalten. */
            if ( $phase === 'pruef_rechnung' && $s['fehler_ids'] ) {
                $s['phase'] = 'fehler';
                $s['meldung'] = count( $s['fehler_ids'] ) . ' Inhalt(e) liessen sich nicht mit dem neuen '
                              . 'Schlüssel öffnen (' . implode( ', ', array_slice( $s['fehler_ids'], 0, 10 ) ) . '). '
                              . 'Der alte Schlüssel bleibt darum in der Datenbank.';
                return $s;
            }
            /* Vor den Prüfphasen wird die Fehlerliste geleert: Geprüft wird
               der Stand NACH dem Umschlüsseln. */
            if ( $phase === 'rechnung' ) $s['fehler_ids'] = [];
            $s['phase']  = self::PHASEN[ $i + 1 ];
            $s['cursor'] = 0;
            return $s;
        }

        $pruefen = strpos( $phase, 'pruef_' ) === 0;
        foreach ( $zeilen as $z ) {
            $s['cursor'] = (int) $z['id'];
            if ( $belege ) {
                $erg = $pruefen ? self::beleg_pruefen( (int) $z['id'] ) : self::beleg_umschluesseln( (int) $z['id'] );
                $kennung = 'Beleg ' . (int) $z['id'];
            } else {
                $wo  = $tabelle === 'lsv07a_person' ? 'person' : 'rechnung';
                $erg = $pruefen ? self::feld_pruefen( $wo, $z, $spalte )
                                : self::feld_umschluesseln( $tabelle, $spalte, $wo, $z );
                $kennung = ( $wo === 'person' ? 'IBAN Person ' : 'IBAN Rechnung ' ) . (int) $z['id'];
            }
            if ( $erg === 'neu' ) $s['neu']++;
            elseif ( $erg === 'fehlt' && $pruefen ) $s['fehlt']++;
            elseif ( $erg === 'klartext' && $pruefen ) $s['klartext']++;
            elseif ( $erg === 'fehler' && count( $s['fehler_ids'] ) < 200 ) $s['fehler_ids'][] = $kennung;
        }
        return $s;
    }

    // ── Belege ───────────────────────────────────────────────────────────

    private static function beleg_umschluesseln( $id ) {
        $z = LSV07A_Rechnung::datei_holen( $id );
        if ( ! $z || ! is_file( $z['pfad'] ) ) return 'fehlt';
        $roh = @file_get_contents( $z['pfad'] );
        if ( $roh === false ) return 'fehler';
        $name = (string) $z['gespeichert_als'];
        if ( LSV07A_Tresor::ist_tresor( $roh ) && ! LSV07A_Tresor::braucht_umschluesseln( $roh ) ) return 'ok';

        /* Klartext aus der Zeit vor dem Tresor wird gleich mit verschlüsselt. */
        $kl = LSV07A_Tresor::ist_tresor( $roh ) ? LSV07A_Tresor::entschluesseln( $roh, $name ) : $roh;
        if ( $kl === null ) return 'fehler';
        $neu = LSV07A_Tresor::verschluesseln( $kl, $name );
        if ( $neu === null || LSV07A_Tresor::entschluesseln_nur_haupt( $neu, $name ) !== $kl ) return 'fehler';

        try { $tmp = $z['pfad'] . '.neu-' . bin2hex( random_bytes( 4 ) ); } catch ( Throwable $e ) { return 'fehler'; }
        if ( @file_put_contents( $tmp, $neu ) === false ) return 'fehler';
        if ( @file_get_contents( $tmp ) !== $neu || ! @rename( $tmp, $z['pfad'] ) ) {
            @unlink( $tmp );
            return 'fehler';
        }
        return 'neu';
    }

    private static function beleg_pruefen( $id ) {
        $z = LSV07A_Rechnung::datei_holen( $id );
        if ( ! $z || ! is_file( $z['pfad'] ) ) return 'fehlt';
        $roh = @file_get_contents( $z['pfad'] );
        if ( $roh === false ) return 'fehler';
        if ( ! LSV07A_Tresor::ist_tresor( $roh ) ) return 'klartext';
        return LSV07A_Tresor::entschluesseln_nur_haupt( $roh, (string) $z['gespeichert_als'] ) === null ? 'fehler' : 'ok';
    }

    // ── IBAN-Felder ──────────────────────────────────────────────────────

    private static function feld_umschluesseln( $tabelle, $spalte, $wo, array $z ) {
        global $wpdb;
        $wert = (string) $z[ $spalte ];
        if ( $wert === '' ) return 'ok';
        $bind = LSV07A_Tresor::iban_bindung( $wo, (int) $z['wp_user_id'] );

        if ( LSV07A_Tresor::ist_feld( $wert ) ) {
            $roh = base64_decode( substr( $wert, strlen( LSV07A_Tresor::FELD ) ), true );
            if ( ! is_string( $roh ) ) return 'fehler';
            if ( ! LSV07A_Tresor::braucht_umschluesseln( $roh ) ) return 'ok';
            $kl = LSV07A_Tresor::feld_lesen( $wert, $bind );
            if ( $kl === '' ) return 'fehler';
        } elseif ( strpos( $wert, ':' ) !== false ) {
            return 'fehler';   // unbekanntes Format — nicht anfassen
        } else {
            /* Klartext-IBAN: mit verschlüsseln, wenn die Spalte es fasst. */
            if ( ! LSV07A_DB::spalte_fasst( $tabelle, $spalte, 200 ) ) return 'klartext';
            $kl = $wert;
        }

        $neu = LSV07A_Tresor::feld_verschluesseln( $kl, $bind );
        if ( ! is_string( $neu ) || $neu === '' ) return 'fehler';
        $roh_neu = base64_decode( substr( $neu, strlen( LSV07A_Tresor::FELD ) ), true );
        if ( LSV07A_Tresor::entschluesseln_nur_haupt( $roh_neu, $bind ) !== $kl ) return 'fehler';

        /* Nur ersetzen, wenn das Feld noch den gelesenen Wert hat — wer
           gerade seine IBAN ändert, wird nicht überschrieben. */
        $n = $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::t( $tabelle ) . " SET $spalte = %s WHERE id = %d AND $spalte = %s",
            $neu, (int) $z['id'], $wert ) );
        return $n === 1 ? 'neu' : 'ok';
    }

    private static function feld_pruefen( $wo, array $z, $spalte ) {
        $wert = (string) $z[ $spalte ];
        if ( $wert === '' ) return 'ok';
        if ( ! LSV07A_Tresor::ist_feld( $wert ) ) return strpos( $wert, ':' ) === false ? 'klartext' : 'fehler';
        $roh = base64_decode( substr( $wert, strlen( LSV07A_Tresor::FELD ) ), true );
        if ( ! is_string( $roh ) ) return 'fehler';
        $bind = LSV07A_Tresor::iban_bindung( $wo, (int) $z['wp_user_id'] );
        return LSV07A_Tresor::entschluesseln_nur_haupt( $roh, $bind ) === null ? 'fehler' : 'ok';
    }
}
