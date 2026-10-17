<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Alles, was als Datei herausgeht: die PDF-Berichte und die Anhänge.
 *
 * Downloads laufen über GET, damit der Browser sie als Datei behandelt und
 * man sie auf dem Telefon auch weitergeben kann. Dadurch gibt es hier kein
 * POST-Feld mit dem Token — geprüft wird der Token aus der Adresse gegen
 * dasselbe Cookie wie sonst.
 *
 * Jeder Weg prüft dreierlei, in dieser Reihenfolge: angemeldet, Token,
 * freigegeben. Erst danach wird überhaupt etwas geladen.
 */
class LSV07S_Ajax_Download {

    public static function init() {
        $map = [
            'lsv07s_pdf'       => 'pdf',
            'lsv07s_anhang'    => 'anhang',
            'lsv07s_wk_datei'  => 'wk_datei',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action,        [ __CLASS__, $methode ] );
            add_action( 'wp_ajax_nopriv_' . $action, [ __CLASS__, $methode ] );
        }
    }

    private static function schluss( $code, $text ) {
        status_header( $code );
        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo $text;
        exit;
    }

    /** Angemeldete Person samt Tokenprüfung — oder Ende. */
    private static function ich() {
        $token = isset( $_GET['token'] ) ? (string) wp_unslash( $_GET['token'] ) : '';
        if ( ! LSV07S_Login::csrf_ok( $token ) ) {
            self::schluss( 403, 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.' );
        }
        $s = LSV07S_Login::schwimmer();
        if ( ! $s ) self::schluss( 401, 'Du bist nicht mehr angemeldet.' );
        return $s;
    }

    private static function datei_kopf( $name, $typ, $laenge ) {
        nocache_headers();
        header( 'Content-Type: ' . $typ );
        header( 'Content-Length: ' . (int) $laenge );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        /* Der Browser soll den Typ nicht raten — sonst wird aus einem
           hochgeladenen Bild im Zweifel etwas Ausführbares. */
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Robots-Tag: noindex, nofollow' );
    }

    // ── PDF ─────────────────────────────────────────────────────────────

    public static function pdf() {
        $s       = self::ich();
        $bereich = isset( $_GET['bereich'] ) ? sanitize_key( wp_unslash( $_GET['bereich'] ) ) : '';
        $von     = isset( $_GET['von'] ) ? LSV07S_Login::datum_norm( wp_unslash( $_GET['von'] ) ) : '';
        $bis     = isset( $_GET['bis'] ) ? LSV07S_Login::datum_norm( wp_unslash( $_GET['bis'] ) ) : '';
        $plan    = (int) ( $_GET['plan_id'] ?? 0 );

        $ergebnis = self::bericht( $s, $bereich, $von, $bis, $plan );
        if ( empty( $ergebnis['ok'] ) ) {
            self::schluss( (int) $ergebnis['code'], (string) $ergebnis['message'] );
        }

        self::datei_kopf( $ergebnis['datei'], 'application/pdf', strlen( $ergebnis['inhalt'] ) );
        echo $ergebnis['inhalt'];
        exit;
    }

    /**
     * Den Bericht bauen — Prüfung inbegriffen.
     *
     * Getrennt von pdf(), weil dort `exit` steht: Was mit `exit` endet,
     * lässt sich nicht prüfen, und gerade die Prüfung „ist dieser Bereich
     * für diese Person überhaupt frei" gehört zu dem, was geprüft gehört.
     * pdf() liefert nur noch aus, was hier entschieden wurde.
     *
     * Rückgabe: [ ok, inhalt, datei ] oder [ ok => false, code, message ].
     */
    public static function bericht( array $s, $bereich, $von = '', $bis = '', $plan_id = 0 ) {
        $sid    = (int) $s['id'];
        $person = trim( $s['first_name'] . ' ' . $s['last_name'] );

        if ( ! LSV07S_Freigabe::ist_bereich( $bereich ) ) {
            return [ 'ok' => false, 'code' => 400, 'message' => 'Diesen Bereich gibt es nicht.' ];
        }
        if ( ! LSV07S_Freigabe::darf( $sid, $bereich ) ) {
            return [ 'ok' => false, 'code' => 403,
                     'message' => 'Dieser Bereich ist für deine Mannschaft nicht freigegeben.' ];
        }

        switch ( $bereich ) {
            case 'anwesenheit':   $inhalt = self::pdf_anwesenheit( $sid, $person, $von, $bis ); break;
            case 'vermerke':      $inhalt = self::pdf_vermerke( $sid, $person, $von, $bis );    break;
            case 'aufgaben':      $inhalt = self::pdf_aufgaben( $sid, $person, $von, $bis );    break;
            case 'dokumentation': $inhalt = self::pdf_doku( $sid, $person, $von, $bis );        break;
            case 'wettkaempfe':   $inhalt = self::pdf_wettkaempfe( $sid, $person );             break;
            case 'bestzeiten':    $inhalt = self::pdf_bestzeiten( $sid, $person );              break;
            case 'reflexion':     $inhalt = self::pdf_reflexion( $sid, $person );               break;
            case 'nachrichten':   $inhalt = self::pdf_nachrichten( $sid, $person );             break;
            case 'trainingsplan':
                $inhalt = self::pdf_trainingsplan( $sid, $person, $plan_id );
                if ( $inhalt === null ) {
                    return [ 'ok' => false, 'code' => 403,
                             'message' => 'Dieser Trainingsplan ist für dich nicht freigegeben.' ];
                }
                break;
            default:
                return [ 'ok' => false, 'code' => 400, 'message' => 'Dafür gibt es keinen Bericht.' ];
        }

        return [ 'ok' => true, 'inhalt' => $inhalt,
                 'datei' => LSV07S_Bericht::dateiname( $bereich, $person ) ];
    }

    private static function spanne( $von, $bis ) {
        if ( ! $von && ! $bis ) return '';
        return LSV07S_Bericht::de( $von ) . ' bis ' . LSV07S_Bericht::de( $bis );
    }

    private static function pdf_anwesenheit( $sid, $person, $von, $bis ) {
        $d = LSV07S_Daten::anwesenheit( $sid, $von, $bis );
        $b = new LSV07S_Bericht( 'Anwesenheiten', $person, self::spanne( $d['von'], $d['bis'] ) );

        $b->ueberschrift( 'Übersicht' );
        $teile = [];
        foreach ( LSV07S_Daten::ZUSTAENDE as $k => $name ) {
            if ( empty( $d['zahl'][ $k ] ) ) continue;
            $teile[] = $name . ': ' . (int) $d['zahl'][ $k ];
        }
        $gesamt = array_sum( $d['zahl'] );
        $b->absatz( $gesamt
            ? $gesamt . ' erfasste Termine — ' . implode( ' · ', $teile )
            : 'In diesem Zeitraum ist noch nichts erfasst.' );
        if ( $d['quote'] !== null ) {
            $b->absatz( 'Anwesend an ' . (int) $d['quote'] . " % der erfassten Termine.", 10, 0.35 );
        }

        $b->ueberschrift( 'Die einzelnen Termine' );
        $zeilen = [];
        foreach ( $d['tage'] as $t ) {
            $spaet = $t['verspaetung'] > 0
                   ? $t['verspaetung'] . ' Min.' . ( $t['grund'] !== '' ? ' (' . $t['grund'] . ')' : '' )
                   : '';
            $zeilen[] = [ LSV07S_Bericht::de( $t['datum'] ), $t['mannschaft'], $t['status_name'], $spaet ];
        }
        $b->tabelle( [
            [ 'name' => 'Datum',      'breite' => 1.1 ],
            [ 'name' => 'Mannschaft', 'breite' => 1.5 ],
            [ 'name' => 'Zustand',    'breite' => 1.3 ],
            [ 'name' => 'Zu spät',    'breite' => 1.6 ],
        ], $zeilen );

        return $b->fertig();
    }

    private static function pdf_vermerke( $sid, $person, $von, $bis ) {
        $d = LSV07S_Daten::vermerke( $sid, $von, $bis );
        $b = new LSV07S_Bericht( 'Vermerke der Mannschaft', $person, self::spanne( $d['von'], $d['bis'] ) );
        $b->hinweis( 'Hier stehen nur Vermerke, die für die ganze Mannschaft gelten.' );

        $zeilen = [];
        foreach ( $d['eintraege'] as $e ) {
            $zeilen[] = [ LSV07S_Bericht::de( $e['datum'] ), $e['art'], $e['text'] ];
        }
        $b->tabelle( [
            [ 'name' => 'Datum', 'breite' => 1.1 ],
            [ 'name' => 'Art',   'breite' => 1.3 ],
            [ 'name' => 'Vermerk', 'breite' => 4.0 ],
        ], $zeilen );
        return $b->fertig();
    }

    private static function pdf_aufgaben( $sid, $person, $von, $bis ) {
        $d = LSV07S_Daten::aufgaben( $sid, $von, $bis );
        $b = new LSV07S_Bericht( 'Aufgaben', $person, self::spanne( $d['von'], $d['bis'] ) );
        $b->absatz( $d['offen'] === 1
            ? 'Eine Aufgabe ist noch offen.'
            : ( $d['offen'] > 0 ? $d['offen'] . ' Aufgaben sind noch offen.'
                                : 'Es ist nichts mehr offen.' ) );

        $zeilen = [];
        foreach ( $d['eintraege'] as $e ) {
            $zeilen[] = [
                LSV07S_Bericht::de( $e['datum'] ),
                $e['fuer_alle'] ? 'ganze Mannschaft' : 'nur du',
                $e['art'],
                $e['text'],
                $e['erledigt'] ? 'erledigt' : 'offen',
            ];
        }
        $b->tabelle( [
            [ 'name' => 'Datum',  'breite' => 1.0 ],
            [ 'name' => 'Für',    'breite' => 1.2 ],
            [ 'name' => 'Art',    'breite' => 1.6 ],
            [ 'name' => 'Aufgabe','breite' => 2.6 ],
            [ 'name' => 'Stand',  'breite' => 0.9 ],
        ], $zeilen );
        return $b->fertig();
    }

    private static function pdf_doku( $sid, $person, $von, $bis ) {
        $d = LSV07S_Daten::dokumentation( $sid, $von, $bis );
        $b = new LSV07S_Bericht( 'Dokumentation', $person, self::spanne( $d['von'], $d['bis'] ) );

        if ( ! $d['tage'] ) {
            $b->absatz( 'In diesem Zeitraum ist nichts dokumentiert.' );
            return $b->fertig();
        }
        foreach ( $d['tage'] as $t ) {
            $b->ueberschrift( LSV07S_Bericht::de( $t['datum'] )
                . ( $t['mannschaft'] !== '' ? ' — ' . $t['mannschaft'] : '' ) );
            $b->absatz( $t['text'] );
        }
        return $b->fertig();
    }

    private static function pdf_wettkaempfe( $sid, $person ) {
        $d = LSV07S_Daten::wettkaempfe( $sid );
        $b = new LSV07S_Bericht( 'Wettkämpfe', $person, 'Stand ' . date_i18n( 'd.m.Y' ) );

        if ( ! $d['wettkaempfe'] ) {
            $b->absatz( 'Zurzeit steht kein Wettkampf an.' );
            return $b->fertig();
        }

        foreach ( $d['wettkaempfe'] as $w ) {
            $zeit = $w['von'] === $w['bis']
                  ? LSV07S_Bericht::de( $w['von'] )
                  : LSV07S_Bericht::de( $w['von'] ) . ' bis ' . LSV07S_Bericht::de( $w['bis'] );
            $b->ueberschrift( $w['name'] );
            $b->absatz( $zeit . ( $w['ort'] !== '' ? ' · ' . $w['ort'] : '' ) );

            $unterlagen = [];
            foreach ( $w['dokumente'] as $dok ) $unterlagen[] = $dok['name'];
            $b->absatz( $unterlagen
                ? 'Unterlagen: ' . implode( ', ', $unterlagen )
                  . ' — herunterladen kannst du sie in SwimView.'
                : 'Es liegen noch keine Unterlagen vor.', 9.5, 0.35 );

            foreach ( $w['links'] as $l ) {
                $b->absatz( $l['titel'] . ': ' . $l['url'], 9, 0.35 );
            }
            $b->luft( 4 );
        }
        return $b->fertig();
    }

    private static function pdf_bestzeiten( $sid, $person ) {
        $d = LSV07S_Daten::bestzeiten( $sid );
        $b = new LSV07S_Bericht( 'Bestzeiten', $person, 'Stand ' . date_i18n( 'd.m.Y' ) );

        $zeilen = [];
        foreach ( $d['zeiten'] as $z ) {
            $zeilen[] = [ $z['name'], $z['zeit'], LSV07S_Bericht::de( $z['stand'] ) ];
        }
        $b->tabelle( [
            [ 'name' => 'Strecke', 'breite' => 2.0 ],
            [ 'name' => 'Zeit',    'breite' => 1.4, 'rechts' => true ],
            [ 'name' => 'Stand',   'breite' => 1.4, 'rechts' => true ],
        ], $zeilen );
        return $b->fertig();
    }

    private static function pdf_reflexion( $sid, $person ) {
        $d = LSV07S_Daten::reflexion( $sid );
        $saison = $d['bogen'] ? (string) $d['bogen']['saison'] : '';
        $b = new LSV07S_Bericht( 'Reflexionsbogen', $person, $saison );

        if ( ! $d['bogen'] ) {
            $b->absatz( 'Für dich gibt es in dieser Saison keinen Reflexionsbogen.' );
            return $b->fertig();
        }

        $b->absatz( $d['status'] === 'abgegeben'
            ? 'Abgegeben am ' . LSV07S_Bericht::de( $d['abgegeben_am'] ) . '.'
            : 'Noch nicht abgegeben.' );

        if ( $d['fragen'] ) {
            $b->ueberschrift( 'Fragen' );
            foreach ( $d['fragen'] as $f ) {
                $b->absatz( $f['frage'], 10 );
                $b->absatz( $f['antwort'] !== '' ? $f['antwort'] : '— noch nichts eingetragen —', 10, 0.3 );
                $b->luft( 2 );
            }
        }

        if ( $d['zeiten'] ) {
            $b->ueberschrift( 'Zeiten und Ziele' );
            $zeilen = [];
            foreach ( $d['zeiten'] as $z ) {
                $zeilen[] = [ $z['name'], $z['zeit'], $z['wunschzeit'] ];
            }
            $b->tabelle( [
                [ 'name' => 'Strecke',    'breite' => 2.0 ],
                [ 'name' => 'Bestzeit',   'breite' => 1.4, 'rechts' => true ],
                [ 'name' => 'Wunschzeit', 'breite' => 1.4, 'rechts' => true ],
            ], $zeilen );
        }
        return $b->fertig();
    }

    private static function pdf_nachrichten( $sid, $person ) {
        $d = LSV07S_Daten::nachrichten( $sid );
        $b = new LSV07S_Bericht( 'Nachrichten', $person, 'Stand ' . date_i18n( 'd.m.Y' ) );

        if ( ! $d['nachrichten'] ) {
            $b->absatz( 'Es liegt keine Nachricht vor.' );
            return $b->fertig();
        }
        foreach ( $d['nachrichten'] as $n ) {
            $b->ueberschrift( $n['betreff'] );
            $b->absatz( LSV07S_Bericht::de_zeit( $n['am'] )
                . ' · von ' . ( $n['von'] !== '' ? $n['von'] : 'Trainerteam' )
                . ( $n['nur_fuer_mich'] ? ' · nur für dich' : ' · an die ganze Mannschaft' ),
                9.5, 0.35 );
            if ( $n['text'] !== '' ) $b->absatz( $n['text'] );
            if ( $n['dateien'] ) {
                $namen = [];
                foreach ( $n['dateien'] as $f ) $namen[] = $f['name'];
                $b->absatz( 'Dateien: ' . implode( ', ', $namen ), 9.5, 0.35 );
            }
            $b->luft( 4 );
        }
        return $b->fertig();
    }

    /** null heisst: diesen Plan gibt es für diese Person nicht. */
    private static function pdf_trainingsplan( $sid, $person, $plan_id = 0 ) {
        $plan_id = (int) $plan_id;

        if ( $plan_id ) {
            $plan = LSV07S_Daten::trainingsplan( $sid, $plan_id );
            if ( ! $plan ) return null;
            $b = new LSV07S_Bericht( $plan['titel'], $person,
                'Stand ' . LSV07S_Bericht::de( $plan['stand'] ) );
            self::plan_in_bericht( $b, $plan );
            return $b->fertig();
        }

        /* Ohne Plan-Nummer: alle freigegebenen hintereinander. Wer den Knopf
           in der Übersicht drückt, will nicht neun einzelne Dateien. */
        $liste = LSV07S_Daten::trainingsplaene( $sid );
        $b = new LSV07S_Bericht( 'Trainingspläne', $person, 'Stand ' . date_i18n( 'd.m.Y' ) );
        if ( ! $liste['plaene'] ) {
            $b->absatz( 'Für dich ist zurzeit kein Trainingsplan freigegeben.' );
            return $b->fertig();
        }
        foreach ( $liste['plaene'] as $kurz ) {
            $plan = LSV07S_Daten::trainingsplan( $sid, (int) $kurz['id'] );
            if ( ! $plan ) continue;
            $b->ueberschrift( $plan['titel'] );
            self::plan_in_bericht( $b, $plan, false );
            $b->luft( 6 );
        }
        return $b->fertig();
    }

    private static function plan_in_bericht( LSV07S_Bericht $b, array $plan, $mit_kopf = true ) {
        if ( $mit_kopf ) {
            $b->absatz( ( $plan['von'] !== '' ? 'Von ' . $plan['von'] . ' · ' : '' )
                . 'Stand ' . LSV07S_Bericht::de( $plan['stand'] ), 9.5, 0.35 );
        }
        $zeilen = [];
        foreach ( $plan['einheiten'] as $e ) {
            $zeilen[] = [
                $e['anzahl'],
                $e['strecke'],
                trim( $e['beschreibung'] . ( $e['kommentar'] !== '' ? "\n" . $e['kommentar'] : '' ) ),
                $e['ausruestung'],
            ];
        }
        $b->tabelle( [
            [ 'name' => 'Anzahl',      'breite' => 0.8 ],
            [ 'name' => 'Strecke',     'breite' => 1.1 ],
            [ 'name' => 'Was',         'breite' => 3.2 ],
            [ 'name' => 'Ausrüstung',  'breite' => 1.3 ],
        ], $zeilen );
    }

    // ── Dateien ─────────────────────────────────────────────────────────

    /** Ein Anhang aus einer Nachricht. */
    public static function anhang() {
        $s  = self::ich();
        $id = (int) ( $_GET['id'] ?? 0 );

        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'nachrichten' ) ) {
            self::schluss( 403, 'Nachrichten sind für deine Mannschaft nicht freigegeben.' );
        }
        $datei = LSV07S_Daten::darf_anhang( (int) $s['id'], $id );
        if ( ! $datei ) self::schluss( 403, 'Diese Datei gibt es für dich nicht.' );
        if ( ! file_exists( $datei['pfad'] ) ) self::schluss( 404, 'Die Datei ist nicht mehr da.' );

        self::datei_kopf( self::sauber( $datei['dateiname'] ),
            $datei['mime_type'] ?: 'application/octet-stream', filesize( $datei['pfad'] ) );
        readfile( $datei['pfad'] );
        exit;
    }

    /**
     * Eine Wettkampf-Unterlage. Die Datei gehört dem internen Bereich; wo
     * sie liegt, weiss dessen Klasse. Gibt es sie nicht, wird der Pfad nach
     * demselben Muster gebildet — sonst hinge der Download daran, dass beide
     * Plugins in derselben Fassung installiert sind.
     */
    public static function wk_datei() {
        $s  = self::ich();
        $id = (int) ( $_GET['id'] ?? 0 );

        if ( ! LSV07S_Freigabe::darf( (int) $s['id'], 'wettkaempfe' ) ) {
            self::schluss( 403, 'Wettkämpfe sind für deine Mannschaft nicht freigegeben.' );
        }
        $dok = LSV07S_Daten::darf_wk_dokument( (int) $s['id'], $id );
        if ( ! $dok ) self::schluss( 403, 'Diese Unterlage gibt es für dich nicht.' );

        $pfad = self::wk_pfad( $dok );
        if ( ! $pfad || ! file_exists( $pfad ) ) self::schluss( 404, 'Die Datei ist nicht mehr da.' );

        self::datei_kopf( self::sauber( $dok['dateiname'] ),
            $dok['mime_type'] ?: 'application/pdf', filesize( $pfad ) );
        readfile( $pfad );
        exit;
    }

    private static function wk_pfad( array $dok ) {
        $name = (string) $dok['gespeichert_als'];
        /* Kein Ausbrechen aus dem Ordner: Der Name kommt zwar aus der
           eigenen Datenbank, aber ein Pfadbestandteil darin hätte hier
           nichts zu suchen. */
        if ( $name === '' || strpbrk( $name, "/\\\0" ) !== false ) return '';

        if ( class_exists( 'LSV07I_Wettkampf_Dateien' )
          && method_exists( 'LSV07I_Wettkampf_Dateien', 'dir_for' ) ) {
            $dir = LSV07I_Wettkampf_Dateien::dir_for( (int) $dok['wettkampf_id'] );
            if ( $dir ) return trailingslashit( $dir ) . $name;
        }

        $up = wp_upload_dir();
        return trailingslashit( $up['basedir'] ) . 'lsv07i-private/wettkampf/'
             . (int) $dok['wettkampf_id'] . '/' . $name;
    }

    /** Ein Dateiname, der in einem Content-Disposition-Kopf nichts anrichtet. */
    private static function sauber( $name ) {
        $n = str_replace( [ '"', "\r", "\n", "\0" ], '', (string) $name );
        $n = sanitize_file_name( $n );
        return $n !== '' ? $n : 'datei';
    }
}
