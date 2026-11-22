<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Alte Dateien des internen Bereichs verschlüsseln bzw. auf den Schlüssel
 * in der Schlüsseldatei umstellen.
 *
 * Warum. Bis 9.9.0 lagen Atteste, Anhänge und Dokumente im Klartext unter
 * uploads/lsv07i-private. Neue Dateien verschlüsselt LSV07I_Tresor beim
 * Hochladen; die alten erledigt diese Klasse — im Hintergrund (WP-Cron) in
 * kleinen Portionen, ohne dass jemand etwas tun muss.
 *
 * Gearbeitet wird über den ORDNER, nicht über die Tabellen: So wird jede
 * abgelegte Datei erfasst, gleich aus welchem Bereich (Atteste,
 * Schwimmer-Dateien, Trainingspläne, Wettkampf-Dokumente, Ticket- und
 * Chat-Anhänge, Profilbilder). Die Schutzdateien (.htaccess, index.html …)
 * bleiben, wie sie sind.
 *
 * Reihenfolge wie im Abrechnungs-Plugin: umschlüsseln (jede Datei vor dem
 * Ersetzen mit dem neuen Schlüssel allein geöffnet und verglichen, Ersetzen
 * über eine Nachbardatei) → prüfen → erst dann einen etwaigen alten
 * Datenbank-Schlüssel in die Schlüsseldatei übernehmen und aus der
 * Datenbank löschen. Schlägt etwas fehl, wird angehalten; gelöscht wird
 * dann nichts.
 */
class LSV07I_Umschluesseln {

    const STAND  = 'lsv07i_umschluesseln';
    const HAKEN  = 'lsv07i_umschluesseln';
    const SPERRE = 'lsv07i_umschluesseln_lauf';
    const PHASEN = [ 'dateien', 'pruefen', 'abschluss' ];
    /** Dateien, die zum Schutz des Ordners dort liegen — nie anfassen. */
    const SCHUTZ = [ '.htaccess', 'index.html', 'index.php', 'README-NGINX.txt', 'README.txt' ];

    const TEXTE = [
        'dateien'   => 'Dateien werden verschlüsselt',
        'pruefen'   => 'Dateien werden geprüft',
        'abschluss' => 'Alter Schlüssel wird aus der Datenbank entfernt',
        'fertig'    => 'Abgeschlossen — alle Dateien liegen verschlüsselt',
        'fehler'    => 'Angehalten — es wurde nichts gelöscht',
    ];

    public static function init() {
        add_action( self::HAKEN, [ __CLASS__, 'cron' ] );
    }

    /** Der Ordner mit allen abgelegten Dateien des internen Bereichs. */
    public static function ordner() {
        $up = wp_upload_dir( null, false );
        if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) return '';
        return rtrim( $up['basedir'], '/' ) . '/lsv07i-private';
    }

    /** Läuft das hier? Nur mit einem brauchbaren Hauptschlüssel. */
    public static function zustaendig() {
        return class_exists( 'LSV07I_Tresor' ) && LSV07I_Tresor::schluessel() !== null;
    }

    private static function lesen() {
        $s = get_option( self::STAND, [] );
        $s = is_array( $s ) ? $s : [];
        $s += [ 'phase' => 'dateien', 'cursor' => '', 'neu' => 0, 'klartext' => 0,
                'fehler_dateien' => [], 'meldung' => '', 'fertig_am' => '', 'schluessel' => '' ];
        /* Hat der Hauptschlüssel gewechselt (z. B. Datenbank → Schlüsseldatei,
           weil der Hoster es inzwischen zulässt), ist „fertig" nicht mehr
           fertig: Die Dateien liegen noch unter dem alten. Von vorn. */
        $kid = LSV07I_Tresor::haupt_id();
        if ( $kid !== '' && $s['schluessel'] !== $kid ) {
            $s = [ 'phase' => 'dateien', 'cursor' => '', 'neu' => 0, 'klartext' => 0,
                   'fehler_dateien' => [], 'meldung' => '', 'fertig_am' => '', 'schluessel' => $kid ];
        }
        return $s;
    }
    private static function schreiben( array $s ) { update_option( self::STAND, $s, false ); }

    public static function stand() {
        if ( ! self::zustaendig() ) return [ 'noetig' => false, 'phase' => '', 'text' => '' ];
        $s = self::lesen();
        return [
            'noetig'    => true,
            'phase'     => $s['phase'],
            'text'      => self::TEXTE[ $s['phase'] ] ?? $s['phase'],
            'fertig'    => $s['phase'] === 'fertig',
            'fehler'    => $s['phase'] === 'fehler',
            'meldung'   => (string) $s['meldung'],
            'neu'       => (int) $s['neu'],
            'klartext'  => (int) $s['klartext'],
            'fertig_am' => (string) $s['fertig_am'],
        ];
    }

    /** Im Hintergrund weitermachen, solange etwas offen ist. */
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

    /**
     * Von vorn beginnen — nach einem Fehler, oder wenn der Hauptschlüssel
     * gewechselt hat (dann ist „fertig" nicht mehr fertig).
     */
    public static function neu_starten() {
        self::schreiben( [ 'phase' => 'dateien', 'cursor' => '', 'schluessel' => LSV07I_Tresor::haupt_id() ] );
        self::planen();
    }

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

    /** Alle abgelegten Dateien, sortiert (relativ zum Ordner). */
    private static function alle_dateien() {
        $basis = self::ordner();
        if ( $basis === '' || ! is_dir( $basis ) ) return [];
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $basis, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $f ) {
            if ( ! $f->isFile() || $f->isLink() ) continue;
            $name = $f->getFilename();
            if ( in_array( $name, self::SCHUTZ, true ) ) continue;
            if ( strpos( $name, '.neu-' ) !== false ) continue;   // halbe Nachbardatei
            $out[] = substr( $f->getPathname(), strlen( $basis ) + 1 );
        }
        sort( $out, SORT_STRING );
        return $out;
    }

    private static function portion( array $s ) {
        $phase = $s['phase'];

        if ( $phase === 'abschluss' ) {
            if ( ! LSV07I_Tresor::db_schluessel_verlegen() && LSV07I_Tresor::quelle() === 'datei' ) {
                $s['phase'] = 'fehler';
                $s['meldung'] = 'Der alte Schlüssel liess sich nicht in die Schlüsseldatei übernehmen. '
                              . 'Er bleibt darum in der Datenbank; es ist nichts verloren.';
                return $s;
            }
            $s['phase'] = 'fertig';
            $s['fertig_am'] = current_time( 'mysql' );
            LSV07I_Log::write( 'tresor.umgeschluesselt', [ 'bereich' => 'System',
                'details' => (int) $s['neu'] . ' Dateien verschlüsselt' ] );
            return $s;
        }

        $basis   = self::ordner();
        $dateien = self::alle_dateien();
        $offen   = array_values( array_filter( $dateien, fn( $d ) => strcmp( $d, (string) $s['cursor'] ) > 0 ) );
        $portion = array_slice( $offen, 0, 25 );

        if ( ! $portion ) {
            if ( $phase === 'pruefen' && $s['fehler_dateien'] ) {
                $s['phase'] = 'fehler';
                $s['meldung'] = count( $s['fehler_dateien'] ) . ' Datei(en) sind nicht verschlüsselt oder lassen sich nicht öffnen ('
                              . implode( ', ', array_slice( $s['fehler_dateien'], 0, 5 ) ) . '). '
                              . 'Ein etwaiger alter Schlüssel bleibt darum in der Datenbank.';
                return $s;
            }
            if ( $phase === 'dateien' ) { $s['fehler_dateien'] = []; $s['klartext'] = 0; }
            $s['phase']  = self::PHASEN[ array_search( $phase, self::PHASEN, true ) + 1 ];
            $s['cursor'] = '';
            return $s;
        }

        foreach ( $portion as $rel ) {
            $s['cursor'] = $rel;
            $pfad = $basis . '/' . $rel;
            if ( ! is_file( $pfad ) ) continue;   // gerade gelöscht
            if ( $phase === 'dateien' ) {
                $erg = LSV07I_Tresor::datei_verschluesseln( $pfad );
                if ( $erg === 'neu' ) $s['neu']++;
            } else {
                $roh = @file_get_contents( $pfad );
                if ( $roh === false ) $erg = 'fehler';
                /* Nach der ersten Phase darf keine Datei mehr im Klartext liegen. */
                elseif ( ! LSV07I_Tresor::ist_tresor( $roh ) ) { $erg = 'fehler'; $s['klartext']++; }
                else $erg = LSV07I_Tresor::entschluesseln_nur_haupt( $roh, LSV07I_Tresor::bindung( $pfad ) ) === null
                          ? 'fehler' : 'ok';
            }
            if ( $erg === 'fehler' && count( $s['fehler_dateien'] ) < 200 ) $s['fehler_dateien'][] = $rel;
        }
        return $s;
    }
}
