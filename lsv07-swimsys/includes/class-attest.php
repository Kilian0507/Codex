<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Atteste: Ablaufdatum, Datei, Archiv, Erinnerung.
 *
 * Ein ärztliches Attest ist ein Gesundheitsdatum nach Art. 9 DSGVO. Es
 * wird deshalb anders behandelt als ein Name: eigener Schalter, eigener
 * Ordner ausserhalb des Netzzugriffs, Ausgabe nur durch das Tor, jeder
 * Zugriff im Protokoll.
 *
 * Drei Dinge sind anders als im internen Bereich:
 *
 * 1. **Am Ablauftag gilt das Attest noch.** Dort rechnet
 *    `LSV07I_DB::attest_status` mit `strtotime(Datum) < time()` — damit
 *    ist ein Attest am aufgedruckten Datum bereits abgelaufen.
 * 2. **Die Erinnerung hat ein Fenster.** Dort mailt der Cron nur, wenn
 *    der Ablauf GENAU in `attest_warnung_tage` liegt (`>= +21 Tage AND
 *    < +22 Tage`). Ein ausgefallener Cron-Tag heisst: keine Mail, nie.
 *    Und ein bereits abgelaufenes Attest bekommt überhaupt keine.
 * 3. **„Zuletzt erinnert" steht in der Liste.** Dort steht es nur im
 *    Protokoll und in einem Transient — man sieht also nicht, wer schon
 *    eine Mail bekam.
 *
 * Es gibt zwei Module im internen Bereich (`attest_*` und `attv_*`) mit
 * zwei verschiedenen Standardtexten für dieselbe Mail. Hier eines.
 */
class LSV07S_Attest {

    /** Der Stand eines Attests. */
    const KEIN      = 'kein';
    const GUELTIG   = 'gueltig';
    const LAEUFT_AB = 'laeuft_ab';
    const ABGELAUFEN= 'abgelaufen';

    const NAMEN = [
        self::KEIN       => 'Kein Attest',
        self::GUELTIG    => 'Gültig',
        self::LAEUFT_AB  => 'Läuft ab',
        self::ABGELAUFEN => 'Abgelaufen',
    ];

    /** Wie viele Tage im Voraus gewarnt wird. */
    public static function warnung_tage() {
        $t = (int) LSV07S_Tabellen::config( 'attest_warnung_tage', 30 );
        return $t > 0 ? $t : 30;
    }

    /**
     * Der Stand zu einem Ablaufdatum.
     *
     * Am Ablauftag ist das Attest GÜLTIG: Auf dem Papier steht ein Datum,
     * und an diesem Tag gilt es. Im internen Bereich ist es an diesem Tag
     * schon abgelaufen — der Schwimmer wird also einen Tag zu früh vom
     * Training ausgeschlossen.
     */
    public static function stand( $datum, $heute = null ) {
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return self::KEIN;
        $h = LSV07S_Datum::rein( $heute ?? LSV07S_Datum::heute() ) ?? LSV07S_Datum::heute();
        if ( $d < $h ) return self::ABGELAUFEN;
        $frist = date( 'Y-m-d', strtotime( $h . ' +' . self::warnung_tage() . ' days' ) );
        return $d <= $frist ? self::LAEUFT_AB : self::GUELTIG;
    }

    public static function stand_name( $stand ) {
        return self::NAMEN[ $stand ] ?? $stand;
    }

    private static function darf_lesen() {
        return LSV07S_Schalter::an( 'schwimmen/atteste' );
    }

    private static function darf_aendern() {
        return LSV07S_Schalter::an( 'schwimmen/atteste' );
    }

    // ── Liste ────────────────────────────────────────────────────────────

    /**
     * Die Atteste einer Mannschaft.
     *
     * Mit Kontaktperson (die erste, also die, die die Erinnerung bekommt),
     * Dateien und dem Datum der letzten Erinnerung.
     */
    public static function liste( $mannschaft_id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf_lesen() ) return [];
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( (int) $mannschaft_id, $erlaubt, true ) ) return [];

        $raus = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ) as $s ) {
            $kontakte = LSV07S_Stamm::kontakte( $s['id'] );
            $raus[] = [
                'id'         => $s['id'],
                'name'       => $s['name'],
                'jahrgang'   => $s['jahrgang'],
                'bis'        => $s['attest_bis'],
                'bis_de'     => LSV07S_Datum::de( $s['attest_bis'] ),
                'stand'      => self::stand( $s['attest_bis'] ),
                'stand_name' => self::stand_name( self::stand( $s['attest_bis'] ) ),
                'tage'       => self::tage_bis( $s['attest_bis'] ),
                'kontakt'    => $kontakte ? $kontakte[0]['name'] : '',
                'kontakt_mail'=> $kontakte ? $kontakte[0]['email'] : '',
                'dateien'    => self::dateien( $s['id'] ),
                'erinnert'   => self::erinnert_am( $s['id'] ),
            ];
        }
        return $raus;
    }

    /** Tage bis zum Ablauf; negativ, wenn es vorbei ist. */
    public static function tage_bis( $datum ) {
        $d = LSV07S_Datum::rein( $datum );
        if ( $d === null ) return null;
        $heute = strtotime( LSV07S_Datum::heute() . ' 12:00:00' );
        return (int) round( ( strtotime( $d . ' 12:00:00' ) - $heute ) / DAY_IN_SECONDS );
    }

    /**
     * Das Ablaufdatum setzen.
     *
     * Ohne Datei: Manche Atteste werden nur vorgezeigt, und dann steht nur
     * das Datum da. Das ist ausdrücklich erlaubt — aber es wird
     * protokolliert, denn es ist eine Aussage über ein Gesundheitsdatum.
     */
    public static function datum_setzen( $schwimmer_id, $datum ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $schwimmer_id = (int) $schwimmer_id;
        if ( ! self::darf_aendern() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, $schwimmer_id ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }
        $t = LSV07S_Tabellen::t( 'schwimmer' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $schwimmer_id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Person gibt es nicht.';

        $neu = $datum === '' || $datum === null ? null : LSV07S_Datum::rein( $datum );
        if ( ! in_array( $datum, [ '', null ], true ) && $neu === null ) {
            return 'Dieses Datum gibt es nicht.';
        }
        /* Ein Attest, das in zehn Jahren abläuft, ist ein Tippfehler. */
        if ( $neu !== null && $neu > date( 'Y-m-d', strtotime( LSV07S_Datum::heute()
             . ' +3 years' ) ) ) {
            return 'Dieses Datum liegt mehr als drei Jahre in der Zukunft. Bitte prüfen.';
        }

        $wpdb->update( $t, [ 'attest_expires' => $neu ], [ 'id' => $schwimmer_id ],
            [ '%s' ], [ '%d' ] );

        LSV07S_Log::schreibe( 'attest.datum', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $schwimmer_id,
            'ziel_name' => LSV07S_Person::aus_zeile( $zeile, $sparte ),
            'details' => ( $zeile['attest_expires'] ?: 'ohne' ) . ' → ' . ( $neu ?: 'ohne' ),
        ] );
        return true;
    }

    // ── Dateien ──────────────────────────────────────────────────────────

    /**
     * Der Ordner für Attestdateien.
     *
     * DERSELBE wie im internen Bereich: Beide Plugins sollen dieselben
     * Dateien sehen. Der Ordner liegt in den Uploads, ist per .htaccess
     * für den Browser gesperrt, und ausgegeben wird nur durch das Tor.
     *
     * Verschlüsselt wird hier NICHT, obwohl es ein Gesundheitsdatum ist —
     * und das ist eine Abwägung, nicht ein Versehen: Der interne Bereich
     * liefert dieselben Dateien unverschlüsselt aus, und solange beide
     * Plugins laufen, würde eine Verschlüsselung dort jede Datei
     * unlesbar machen. Sobald der interne Bereich abgeschaltet ist, kann
     * die Ablage auf den Tresor der Abrechnung umgestellt werden; bis
     * dahin schützt der gesperrte Ordner.
     */
    public static function ordner() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/schwimmer';
        if ( ! is_dir( $base ) ) wp_mkdir_p( $base );
        self::ordner_sperren( dirname( $base ) );
        self::ordner_sperren( $base );
        return $base;
    }

    /**
     * Den Ordner gegen Direktzugriff sperren.
     *
     * Apache liest die .htaccess von selbst. nginx nicht — dafür liegt im
     * Ordner ein Hinweis mit der Regel, die in die Serverkonfiguration
     * gehört. Ohne ihn hält jemand den Ordner für geschützt, weil eine
     * .htaccess darin liegt, und sie tut nichts.
     *
     * Öffentlich, weil die Wettkampfdokumente im Nachbarordner liegen
     * und dieselbe Sperre brauchen — zwei Fassungen derselben drei
     * Dateien würden auseinanderlaufen.
     */
    public static function ordner_sperren( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $ht = $dir . '/.htaccess';
        if ( ! file_exists( $ht ) ) {
            @file_put_contents( $ht,
                "# Kein Direktzugriff. Ausgegeben wird nur durch das Plugin.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n" );
        }
        $idx = $dir . '/index.php';
        if ( ! file_exists( $idx ) ) @file_put_contents( $idx, "<?php // still.\n" );
        $hinweis = $dir . '/NGINX-LESEN.txt';
        if ( ! file_exists( $hinweis ) ) {
            @file_put_contents( $hinweis,
                "Dieser Ordner enthält Gesundheitsdaten (ärztliche Atteste).\n\n"
                . "Apache sperrt ihn über die .htaccess von selbst.\n"
                . "NGINX LIEST KEINE .htaccess. Dort muss in die Serverkonfiguration:\n\n"
                . "location ~* /wp-content/uploads/lsv07i-private/ {\n"
                . "    deny all;\n    return 403;\n}\n\n"
                . "Solange das fehlt, sind die Dateien über ihre Adresse erreichbar.\n" );
        }
    }

    /**
     * Das Kennzeichen eines Attests in `lsv07i_schwimmer_dateien`.
     *
     * In derselben Tabelle liegen auch die allgemeinen Dateien eines
     * Sportlers (Reiter „Dateien"), und die verlangen im internen Bereich
     * ein ANDERES Recht: `schwimmen.datei.read`. Ohne diesen Filter
     * öffnete der Attest-Schalter auch die — das wäre eine Hintertür in
     * einen Bereich, der nicht eingeschaltet ist.
     *
     * `aktuell` kennzeichnet das angehängte Attest: Es gibt je Person
     * höchstens eines, ältere wandern beim Hochladen ins Archiv.
     */
    const ART = 'attest';

    /** Gibt es die beiden Spalten? Der interne Bereich zieht sie in 8.30.0 nach. */
    private static function kennzeichen_da() {
        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        return LSV07S_Mannschaft::spalte_da( $t, 'art' )
            && LSV07S_Mannschaft::spalte_da( $t, 'aktuell' );
    }

    /** Die Attestdateien eines Schwimmers; das angehängte zuerst. */
    public static function dateien( $schwimmer_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'schwimmer_dateien' ) ) return [];
        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        $kennzeichen = self::kennzeichen_da();

        $sql = "SELECT * FROM $t WHERE schwimmer_id = %d"
             . ( $kennzeichen ? ' AND art = %s' : '' )
             . ' ORDER BY ' . ( $kennzeichen ? 'aktuell DESC, ' : '' )
             . 'hochgeladen_am DESC, id DESC';
        $werte = $kennzeichen ? [ (int) $schwimmer_id, self::ART ] : [ (int) $schwimmer_id ];

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$werte ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id'        => (int) $z['id'],
            'name'      => (string) $z['dateiname'],
            'groesse'   => (int) $z['groesse'],
            'typ'       => (string) $z['mime_type'],
            'kommentar' => (string) ( $z['kommentar'] ?? '' ),
            /* Ohne die Spalte gilt jede Datei als angehängt — dann gibt es
               die Unterscheidung in dieser Anlage noch nicht. */
            'aktuell'   => ! isset( $z['aktuell'] ) || (int) $z['aktuell'] === 1,
            'am'        => LSV07S_Datum::de_zeit( $z['hochgeladen_am'] ),
            'von'       => $z['hochgeladen_von']
                             ? LSV07S_Person::konto_name( $z['hochgeladen_von'] ) : '',
        ], $zeilen );
    }

    /**
     * Eine Zeile, die wirklich ein Attest ist.
     *
     * Jede Stelle, die über eine Datei-Nummer arbeitet, geht hier durch:
     * Eine allgemeine Sportlerdatei hat in den Attest-Endpunkten nichts
     * zu suchen.
     */
    private static function attest_zeile( $id ) {
        global $wpdb;
        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        $zeile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE id = %d LIMIT 1", (int) $id ), ARRAY_A );
        if ( ! $zeile ) return null;
        if ( self::kennzeichen_da() && (string) $zeile['art'] !== self::ART ) return null;
        return $zeile;
    }

    /** Was hochgeladen werden darf. */
    const TYPEN = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];
    const MAX_BYTES = 10485760;   // 10 MB

    /** Für Prüfungen: die Prüfung auf eine echte hochgeladene Datei umgehen. */
    public static $pruefe_upload = null;

    /**
     * Eine Attestdatei ablegen.
     *
     * Geprüft wird der INHALT, nicht die Endung: Ein als „attest.pdf"
     * benanntes PHP-Skript bleibt ein PHP-Skript. Der gespeicherte Name
     * wird neu gebildet, damit kein Pfad und kein Punkt aus dem
     * Originalnamen in den Ordner gelangt.
     */
    public static function datei_ablegen( $schwimmer_id, array $datei, $kommentar = '' ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $schwimmer_id = (int) $schwimmer_id;
        if ( ! self::darf_aendern() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, $schwimmer_id ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }
        if ( ! LSV07S_Tabellen::da( 'schwimmer_dateien' ) ) return 'Die Tabelle fehlt.';

        $tmp = (string) ( $datei['tmp_name'] ?? '' );
        if ( (int) ( $datei['error'] ?? 1 ) !== 0 || $tmp === '' ) {
            return 'Die Datei ist nicht angekommen.';
        }
        $da = self::$pruefe_upload ? ( self::$pruefe_upload )( $tmp ) : is_uploaded_file( $tmp );
        if ( ! $da ) return 'Die Datei ist nicht angekommen.';

        $groesse = (int) ( $datei['size'] ?? 0 );
        if ( $groesse <= 0 ) return 'Die Datei ist leer.';
        if ( $groesse > self::MAX_BYTES ) {
            return 'Die Datei ist größer als 10 MB.';
        }

        /* Der Inhalt entscheidet. `$_FILES['type']` kommt vom Browser und
           ist frei wählbar. */
        $typ = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
        if ( ! isset( self::TYPEN[ $typ ] ) ) {
            return 'Erlaubt sind PDF, JPG und PNG. Diese Datei ist '
                 . ( $typ ?: 'von unbekannter Art' ) . '.';
        }

        $ordner = self::ordner();
        $name   = 'attest-' . $schwimmer_id . '-' . time() . '-'
                . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.' . self::TYPEN[ $typ ];
        $ziel   = $ordner . '/' . $name;

        $ok = self::$pruefe_upload ? @copy( $tmp, $ziel ) : @move_uploaded_file( $tmp, $ziel );
        if ( ! $ok ) return 'Die Datei konnte nicht gespeichert werden.';
        @chmod( $ziel, 0640 );

        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        $zeile = [
            'schwimmer_id'    => $schwimmer_id,
            'dateiname'       => mb_substr( sanitize_file_name(
                                    (string) ( $datei['name'] ?? $name ) ), 0, 255 ),
            'gespeichert_als' => $name,
            'mime_type'       => $typ,
            'groesse'         => $groesse,
            'kommentar'       => mb_substr( sanitize_text_field( (string) $kommentar ), 0, 500 ),
            'hochgeladen_von' => get_current_user_id(),
            'hochgeladen_am'  => LSV07S_Datum::jetzt(),
        ];
        $art = [ '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ];
        if ( self::kennzeichen_da() ) {
            $zeile['art']     = self::ART;
            $zeile['aktuell'] = 1;
            $art[] = '%s';
            $art[] = '%d';
        }
        $wpdb->insert( $t, $zeile, $art );
        $neu = (int) $wpdb->insert_id;

        /* Höchstens ein Attest gilt als angehängt: Die älteren wandern ins
           Archiv — in dieser Reihenfolge, damit nie zwei zugleich
           angehängt sind. Der interne Bereich macht es genauso; täte
           SwimSys es nicht, zeigten beide Plugins verschiedene Atteste
           derselben Person. */
        if ( self::kennzeichen_da() ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE $t SET aktuell = 0 WHERE schwimmer_id = %d AND art = %s AND id <> %d",
                $schwimmer_id, self::ART, $neu ) );
        }

        LSV07S_Log::schreibe( 'attest.datei', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $schwimmer_id,
            'details' => $typ . ', ' . round( $groesse / 1024 ) . ' KB',
        ] );
        return $neu;
    }

    /**
     * Den Pfad einer Datei zur Ausgabe holen.
     *
     * Jeder Abruf steht im Protokoll: Bei einem Gesundheitsdatum gehört
     * zur Auskunft auch, wer es angesehen hat.
     */
    public static function datei_pfad( $id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf_lesen() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $zeile = self::attest_zeile( $id );
        if ( ! $zeile ) return 'Diese Datei gibt es nicht.';
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, (int) $zeile['schwimmer_id'] ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }

        $pfad = self::ordner() . '/' . basename( (string) $zeile['gespeichert_als'] );
        if ( ! is_file( $pfad ) ) return 'Die Datei liegt nicht mehr im Ordner.';

        LSV07S_Log::schreibe( 'attest.angesehen', [
            'bereich' => $sparte, 'ziel_typ' => 'person',
            'ziel_id' => (int) $zeile['schwimmer_id'],
            'details' => (string) $zeile['dateiname'],
        ] );
        return [ 'pfad' => $pfad, 'name' => (string) $zeile['dateiname'],
                 'typ' => (string) $zeile['mime_type'] ];
    }

    /** Eine Datei löschen — Zeile und Datei. */
    public static function datei_loeschen( $id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf_aendern() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        $zeile = self::attest_zeile( $id );
        if ( ! $zeile ) return 'Diese Datei gibt es nicht.';
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, (int) $zeile['schwimmer_id'] ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }

        LSV07S_Log::schreibe( 'attest.datei_geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'person',
            'ziel_id' => (int) $zeile['schwimmer_id'],
            'details' => (string) $zeile['dateiname'],
        ] );

        $pfad = self::ordner() . '/' . basename( (string) $zeile['gespeichert_als'] );
        if ( is_file( $pfad ) ) @unlink( $pfad );
        $wpdb->delete( $t, [ 'id' => (int) $id ], [ '%d' ] );
        return true;
    }

    // ── Erinnerung ───────────────────────────────────────────────────────

    /** Wann wurde zuletzt erinnert? Aus dem Protokoll, nicht aus einem Flag. */
    public static function erinnert_am( $schwimmer_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'log' ) ) return null;
        $z = $wpdb->get_var( $wpdb->prepare(
            'SELECT zeitpunkt FROM ' . LSV07S_Tabellen::t( 'log' )
            . " WHERE aktion = 'attest.erinnert' AND ziel_typ = 'person' AND ziel_id = %s"
            . ' ORDER BY zeitpunkt DESC LIMIT 1', (string) (int) $schwimmer_id ) );
        return $z ? LSV07S_Datum::de_zeit( $z ) : null;
    }

    /**
     * Wer eine Erinnerung braucht.
     *
     * Ein FENSTER statt eines einzelnen Tages: alles, was abgelaufen ist
     * oder in den nächsten `attest_warnung_tage` abläuft — und seit
     * mindestens 14 Tagen keine Erinnerung bekam. Im internen Bereich
     * trifft der Cron nur den einen Tag, an dem der Ablauf genau 21 Tage
     * entfernt ist; fällt der Lauf aus, gibt es nie eine Mail.
     */
    public static function faellig( $mannschaft_id = 0 ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $mannschaften = $mannschaft_id
            ? [ [ 'id' => (int) $mannschaft_id ] ]
            : LSV07S_Mannschaft::alle( $sparte );

        $raus = [];
        foreach ( $mannschaften as $m ) {
            foreach ( LSV07S_Mannschaft::sportler( $sparte, $m['id'] ) as $s ) {
                $stand = self::stand( $s['attest_bis'] );
                if ( ! in_array( $stand, [ self::ABGELAUFEN, self::LAEUFT_AB ], true ) ) continue;

                $zuletzt = $wpdb->get_var( $wpdb->prepare(
                    'SELECT zeitpunkt FROM ' . LSV07S_Tabellen::t( 'log' )
                    . " WHERE aktion = 'attest.erinnert' AND ziel_id = %s"
                    . ' ORDER BY zeitpunkt DESC LIMIT 1', (string) $s['id'] ) );
                if ( $zuletzt ) {
                    $tage = ( strtotime( LSV07S_Datum::heute() ) - strtotime( $zuletzt ) )
                          / DAY_IN_SECONDS;
                    if ( $tage < 14 ) continue;   // nicht zweimal in zwei Wochen
                }
                $kontakte = LSV07S_Stamm::kontakte( $s['id'] );
                $raus[] = [
                    'id'       => $s['id'],
                    'name'     => $s['name'],
                    'bis'      => $s['attest_bis'],
                    'stand'    => $stand,
                    'mannschaft' => $m['id'],
                    'empfaenger' => $kontakte && $kontakte[0]['email'] !== ''
                                      ? $kontakte[0]['email'] : '',
                    'kontakt'  => $kontakte ? $kontakte[0]['name'] : '',
                ];
            }
        }
        return $raus;
    }

    /**
     * Eine Erinnerung verschicken.
     *
     * Der Text kommt aus der Konfiguration (dieselben Schlüssel wie im
     * internen Bereich), mit Platzhaltern. Ohne Kontaktadresse wird nicht
     * verschickt — und das wird gesagt, statt still nichts zu tun.
     */
    public static function erinnern( $schwimmer_id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $schwimmer_id = (int) $schwimmer_id;
        if ( ! LSV07S_Schalter::an( 'schwimmen/atteste' ) ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, $schwimmer_id ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }
        $t = LSV07S_Tabellen::t( 'schwimmer' );
        $zeile = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $schwimmer_id ), ARRAY_A );
        if ( ! $zeile ) return 'Diese Person gibt es nicht.';

        $kontakte = LSV07S_Stamm::kontakte( $schwimmer_id );
        $an = $kontakte && $kontakte[0]['email'] !== '' ? $kontakte[0]['email'] : '';
        if ( $an === '' ) {
            return 'Für diese Person ist keine Kontaktadresse hinterlegt.';
        }

        $name = LSV07S_Person::aus_zeile( $zeile, $sparte );
        $bis  = LSV07S_Datum::de( $zeile['attest_expires'] ?? null );

        $betreff = (string) LSV07S_Tabellen::config( 'attest_mail_betreff',
            'Ärztliches Attest läuft ab' );
        $text = (string) LSV07S_Tabellen::config( 'attest_mail_text',
            "Guten Tag,\n\ndas ärztliche Attest von {name} läuft am {datum} ab.\n"
            . "Bitte reichen Sie ein neues Attest beim Trainer ein.\n\n"
            . "Mit sportlichen Grüßen\nLSV 07" );

        $ersetzt = str_replace(
            [ '{name}', '{datum}', '{kontakt}' ],
            [ $name, $bis, $kontakte[0]['name'] ],
            $text );

        $gesendet = wp_mail( $an, $betreff, $ersetzt );

        LSV07S_Log::schreibe( 'attest.erinnert', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $schwimmer_id,
            'ziel_name' => $name,
            'erfolg'  => $gesendet ? 1 : 0,
            /* Die Adresse NICHT ins Protokoll: Dass erinnert wurde, ist
               der Zweck; an wen, steht in den Kontaktdaten. Eine Adresse
               im Protokoll wäre eine zweite Kopie, die niemand braucht. */
            'details' => 'Ablauf ' . $bis . ( $gesendet ? '' : ', Versand fehlgeschlagen' ),
        ] );

        return $gesendet ? true : 'Die Mail konnte nicht verschickt werden.';
    }

    // ── Trainer: Führungszeugnis, Rettungsschwimmer, Lizenz ──────────────

    /**
     * Die Ablaufdaten der Trainer.
     *
     * Im internen Bereich gibt es die Spalten (`fuehrungszeugnis`,
     * `rettungsschwimmer`, `lizenz_gueltig`) und ein Eingabefeld dafür —
     * aber keine Prüfung und keine Erinnerung. Ein abgelaufenes
     * Führungszeugnis fällt damit niemandem auf, und das ist bei der
     * Arbeit mit Kindern der schwerere Fall von beiden.
     */
    public static function trainer_fristen() {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf_lesen() && ! LSV07S_Rechte::sieht_alles( $sparte ) ) return [];
        $t = LSV07S_Tabellen::t( 'trainer' );

        $felder = [
            'fuehrungszeugnis'  => 'Führungszeugnis',
            'rettungsschwimmer' => 'Rettungsschwimmabzeichen',
            'lizenz_gueltig'    => 'Trainerlizenz',
        ];
        $vorhanden = [];
        foreach ( $felder as $spalte => $name ) {
            if ( LSV07S_Mannschaft::spalte_da( $t, $spalte ) ) $vorhanden[ $spalte ] = $name;
        }
        if ( $vorhanden === [] ) return [];

        $zeilen = (array) $wpdb->get_results(
            "SELECT * FROM $t WHERE aktiv = 1 ORDER BY name ASC", ARRAY_A );
        $raus = [];
        foreach ( $zeilen as $z ) {
            $fristen = [];
            foreach ( $vorhanden as $spalte => $name ) {
                $datum = $z[ $spalte ] ?? null;
                $fristen[] = [
                    'was'        => $name,
                    'bis'        => $datum,
                    'bis_de'     => LSV07S_Datum::de( $datum ),
                    'stand'      => self::stand( $datum ),
                    'stand_name' => self::stand_name( self::stand( $datum ) ),
                ];
            }
            $raus[] = [
                'id'      => (int) $z['id'],
                'name'    => (string) $z['name'],
                'fristen' => $fristen,
            ];
        }
        return $raus;
    }
}
