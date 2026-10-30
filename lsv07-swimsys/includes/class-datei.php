<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Dateien an einer Person — der Reiter „Dateien" des internen Bereichs.
 *
 * Eine Einverständniserklärung, ein Mannschaftsfoto, eine unterschriebene
 * Vereinbarung: Papiere, die zu einer Person gehören, aber kein
 * Gesundheitsdatum sind. Sie liegen in DERSELBEN Tabelle wie die
 * Attestdateien (`lsv07i_schwimmer_dateien`) und im DEMSELBEN gesperrten
 * Ordner — getrennt werden sie nur durch die Spalte `art`.
 *
 * Diese Trennung ist der ganze Punkt dieser Klasse. Im internen Bereich
 * hängen an derselben Tabelle zwei verschiedene Rechte
 * (`schwimmen.datei.read` und `attest.read`), aber `LSV07I_Schwimmer_Dateien::list_for()`
 * filtert NICHT nach `art`: Wer dort „Dateien" lesen darf, bekommt die
 * Atteste derselben Person mitgeliefert. Das ist eine Hintertür in
 * Art.-9-Daten. Hier filtert jede Abfrage, in beide Richtungen:
 *
 *   - `LSV07S_Attest` nimmt nur `art = 'attest'`,
 *   - diese Klasse nimmt nur `art <> 'attest'`.
 *
 * Und wenn die Spalte `art` fehlt, zeigt diese Klasse GAR NICHTS statt
 * alles: Ohne die Spalte ist ein Attest von einem Mannschaftsfoto nicht
 * zu unterscheiden, und dann ist die leere Liste die einzige Antwort, die
 * kein Gesundheitsdatum ausgibt. Die Spalte zieht `LSV07S_Schema` selbst
 * nach, der interne Bereich ab 8.30.0 ebenfalls — die leere Liste ist
 * also der Zustand einer alten Anlage, nicht der Normalfall.
 */
class LSV07S_Datei {

    /** Das Kennzeichen, das diese Klasse schreibt. */
    const ART = 'datei';

    /** Das Kennzeichen, das ihr NICHT gehört. */
    const ART_ATTEST = 'attest';

    /**
     * Was hochgeladen werden darf.
     *
     * Dieselben drei Typen wie beim Attest. Mehr wäre ein grösserer
     * Angriffsweg für denselben Zweck: Was hier liegt, wird vorgezeigt,
     * nicht ausgeführt.
     */
    const TYPEN = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];

    /**
     * Die Obergrenze.
     *
     * 10 MB wie beim Attest. Der interne Bereich lässt für allgemeine
     * Dateien nur 5 MB zu (`LSV07I_Schwimmer_Dateien::MAX_SIZE`) — eine
     * Datei zwischen 5 und 10 MB nimmt SwimSys also an, und der interne
     * Bereich zeigt sie danach auch an, weil er beim Anzeigen nicht mehr
     * auf die Grösse schaut. Zwei verschiedene Grenzen in einem Plugin
     * wären die schlechtere Wahl: Der Hochladende sieht sonst beim Attest
     * und bei der Einverständniserklärung dieselbe Maske mit zwei
     * verschiedenen Zahlen.
     */
    const MAX_BYTES = 10485760;   // 10 MB

    /** Für Prüfungen: die Prüfung auf eine echte hochgeladene Datei umgehen. */
    public static $pruefe_upload = null;

    private static function darf() {
        return LSV07S_Schalter::an( 'schwimmen/dateien' );
    }

    /**
     * Gibt es die Spalte, die Attest von Datei trennt?
     *
     * Ohne sie arbeitet diese Klasse nicht — weder lesend noch
     * schreibend. Schreiben ohne `art` hiesse: eine allgemeine Datei
     * landet unmarkiert in der Tabelle, und `LSV07S_Attest::dateien()`
     * zählt sie beim nächsten Nachziehen der Spalte als Attest, weil der
     * Standardwert leer ist und ihr Filter dann greift. Lesen ohne `art`
     * hiesse: Atteste in der Dateiliste.
     */
    private static function trennung_da() {
        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        return LSV07S_Mannschaft::spalte_da( $t, 'art' );
    }

    /**
     * Warum die Liste leer ist — oder leer, wenn alles in Ordnung ist.
     *
     * Die Oberfläche zeigt diesen Satz an. Ohne ihn sieht eine Anlage
     * ohne die Spalte `art` aus wie eine Person ohne Dateien.
     */
    public static function grund() {
        if ( ! LSV07S_Tabellen::da( 'schwimmer_dateien' ) ) {
            return 'Die Tabelle für Dateien fehlt in dieser Anlage.';
        }
        if ( ! self::trennung_da() ) {
            return 'Dieser Anlage fehlt die Spalte, die eine allgemeine Datei von '
                 . 'einem ärztlichen Attest unterscheidet. Solange sie fehlt, werden '
                 . 'hier keine Dateien angezeigt — sonst stünden Gesundheitsdaten '
                 . 'in einer Liste, die dafür nicht eingeschaltet ist.';
        }
        return '';
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /**
     * Die allgemeinen Dateien einer Person; die neueste zuerst.
     *
     * Ohne Schalter, ohne Reichweite, ohne Trennspalte: leer. Diese
     * Methode gibt nie etwas aus, was sie nicht klar als allgemeine Datei
     * erkennt.
     */
    public static function liste( $person_id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $person_id = (int) $person_id;
        if ( ! self::darf() ) return [];
        if ( self::grund() !== '' ) return [];
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, $person_id ) ) return [];

        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE schwimmer_id = %d AND art <> %s"
            . ' ORDER BY hochgeladen_am DESC, id DESC',
            $person_id, self::ART_ATTEST ), ARRAY_A );

        return array_map( fn( $z ) => [
            'id'        => (int) $z['id'],
            'name'      => (string) $z['dateiname'],
            'groesse'   => (int) $z['groesse'],
            'groesse_kb'=> (int) round( ( (int) $z['groesse'] ) / 1024 ),
            'typ'       => (string) $z['mime_type'],
            'kommentar' => (string) ( $z['kommentar'] ?? '' ),
            'am'        => LSV07S_Datum::de_zeit( $z['hochgeladen_am'] ),
            'von'       => $z['hochgeladen_von']
                             ? LSV07S_Person::konto_name( $z['hochgeladen_von'] ) : '',
        ], $zeilen );
    }

    /**
     * Die Dateien einer ganzen Mannschaft, je Person.
     *
     * Die Ansicht braucht beides: die Person auch dann, wenn sie keine
     * Datei hat — sonst kann man an ihr keine ablegen.
     */
    public static function mannschaft( $mannschaft_id ) {
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf() ) return [];
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( (int) $mannschaft_id, $erlaubt, true ) ) return [];

        $raus = [];
        foreach ( LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id ) as $s ) {
            $raus[] = [
                'id'       => (int) $s['id'],
                'name'     => (string) $s['name'],
                'jahrgang' => $s['jahrgang'] ?? '',
                'aktiv'    => ! empty( $s['aktiv'] ),
                'dateien'  => self::liste( $s['id'] ),
            ];
        }
        return $raus;
    }

    /**
     * Eine Zeile, die wirklich eine allgemeine Datei ist.
     *
     * Jede Stelle, die über eine Datei-Nummer arbeitet, geht hier durch:
     * Eine Attestnummer hat in diesen Endpunkten nichts zu suchen. Ohne
     * diese Prüfung wäre der Datei-Schalter ein Weg an die Atteste.
     */
    private static function zeile( $id ) {
        global $wpdb;
        if ( self::grund() !== '' ) return null;
        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        $zeile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE id = %d LIMIT 1", (int) $id ), ARRAY_A );
        if ( ! $zeile ) return null;
        if ( (string) $zeile['art'] === self::ART_ATTEST ) return null;
        return $zeile;
    }

    // ── Ablegen ──────────────────────────────────────────────────────────

    /**
     * Eine Datei ablegen.
     *
     * Geprüft wird der INHALT, nicht die Endung, und der gespeicherte
     * Name wird neu gebildet — genau wie beim Attest. Der Originalname
     * steht nur in der Datenbank und nur zur Anzeige.
     */
    public static function ablegen( $person_id, array $datei, $kommentar = '' ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $person_id = (int) $person_id;
        if ( ! self::darf() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, $person_id ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }
        $grund = self::grund();
        if ( $grund !== '' ) return $grund;

        $tmp = (string) ( $datei['tmp_name'] ?? '' );
        if ( (int) ( $datei['error'] ?? 1 ) !== 0 || $tmp === '' ) {
            return 'Die Datei ist nicht angekommen.';
        }
        $da = self::$pruefe_upload ? ( self::$pruefe_upload )( $tmp ) : is_uploaded_file( $tmp );
        if ( ! $da ) return 'Die Datei ist nicht angekommen.';

        $groesse = (int) ( $datei['size'] ?? 0 );
        if ( $groesse <= 0 ) return 'Die Datei ist leer.';
        if ( $groesse > self::MAX_BYTES ) return 'Die Datei ist größer als 10 MB.';

        /* Der Inhalt entscheidet. `$_FILES['type']` kommt vom Browser und
           ist frei wählbar. */
        $typ = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
        if ( ! isset( self::TYPEN[ $typ ] ) ) {
            return 'Erlaubt sind PDF, JPG und PNG. Diese Datei ist '
                 . ( $typ ?: 'von unbekannter Art' ) . '.';
        }

        /* Derselbe Ordner wie beim Attest, derselbe Schutz. Die Sperre
           liegt an einer Stelle, damit nicht zwei Fassungen derselben
           .htaccess auseinanderlaufen. */
        $ordner = LSV07S_Attest::ordner();
        $name   = 'datei-' . $person_id . '-' . time() . '-'
                . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.' . self::TYPEN[ $typ ];
        $ziel   = $ordner . '/' . $name;

        $ok = self::$pruefe_upload ? @copy( $tmp, $ziel ) : @move_uploaded_file( $tmp, $ziel );
        if ( ! $ok ) return 'Die Datei konnte nicht gespeichert werden.';
        @chmod( $ziel, 0640 );

        $t = LSV07S_Tabellen::t( 'schwimmer_dateien' );
        $wpdb->insert( $t, [
            'schwimmer_id'    => $person_id,
            'dateiname'       => mb_substr( sanitize_file_name(
                                    (string) ( $datei['name'] ?? $name ) ), 0, 255 ),
            'gespeichert_als' => $name,
            'mime_type'       => $typ,
            'groesse'         => $groesse,
            'kommentar'       => mb_substr( sanitize_text_field( (string) $kommentar ), 0, 500 ),
            'hochgeladen_von' => get_current_user_id(),
            'hochgeladen_am'  => LSV07S_Datum::jetzt(),
            /* Das Kennzeichen wird gesetzt, nicht dem Standardwert
               überlassen. Eine leere `art` hiesse nur „nicht als Attest
               gekennzeichnet" — dasselbe, was eine Altzeile des internen
               Bereichs sagt. Geschrieben steht hier eine Aussage: Diese
               Zeile ist eine allgemeine Datei, und SwimSys hat sie
               angelegt. */
            'art'             => self::ART,
            'aktuell'         => 1,
        ], [ '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' ] );

        LSV07S_Log::schreibe( 'datei.abgelegt', [
            'bereich' => $sparte, 'ziel_typ' => 'person', 'ziel_id' => $person_id,
            'details' => $typ . ', ' . round( $groesse / 1024 ) . ' KB',
        ] );
        return (int) $wpdb->insert_id;
    }

    // ── Ausgeben und löschen ─────────────────────────────────────────────

    /**
     * Den Pfad einer Datei zur Ausgabe holen.
     *
     * Auch hier steht jeder Abruf im Protokoll. Eine
     * Einverständniserklärung ist kein Gesundheitsdatum, aber sie ist ein
     * personenbezogenes Dokument, und die Auskunft nach Art. 15 DSGVO
     * umfasst die Empfänger.
     */
    public static function pfad( $id ) {
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $zeile = self::zeile( $id );
        if ( ! $zeile ) return 'Diese Datei gibt es nicht.';
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, (int) $zeile['schwimmer_id'] ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }

        /* `basename()`, damit aus einem manipulierten Datenbankfeld kein
           Pfad nach oben wird. */
        $pfad = LSV07S_Attest::ordner() . '/' . basename( (string) $zeile['gespeichert_als'] );
        if ( ! is_file( $pfad ) ) return 'Die Datei liegt nicht mehr im Ordner.';

        LSV07S_Log::schreibe( 'datei.angesehen', [
            'bereich' => $sparte, 'ziel_typ' => 'person',
            'ziel_id' => (int) $zeile['schwimmer_id'],
            'details' => (string) $zeile['dateiname'],
        ] );
        return [ 'pfad' => $pfad, 'name' => (string) $zeile['dateiname'],
                 'typ' => (string) $zeile['mime_type'] ];
    }

    /** Eine Datei löschen — Zeile und Datei. */
    public static function loeschen( $id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        if ( ! self::darf() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        $zeile = self::zeile( $id );
        if ( ! $zeile ) return 'Diese Datei gibt es nicht.';
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, (int) $zeile['schwimmer_id'] ) ) {
            return 'Diese Person gehört nicht zu Ihrem Bereich.';
        }

        LSV07S_Log::schreibe( 'datei.geloescht', [
            'bereich' => $sparte, 'ziel_typ' => 'person',
            'ziel_id' => (int) $zeile['schwimmer_id'],
            'details' => (string) $zeile['dateiname'],
        ] );

        $pfad = LSV07S_Attest::ordner() . '/' . basename( (string) $zeile['gespeichert_als'] );
        if ( is_file( $pfad ) ) @unlink( $pfad );
        $wpdb->delete( LSV07S_Tabellen::t( 'schwimmer_dateien' ),
            [ 'id' => (int) $id ], [ '%d' ] );
        return true;
    }
}
