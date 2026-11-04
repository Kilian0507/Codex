<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Import — eine Stelle für alles Einlesen (ab 9.0.0)
 *
 * Vorher hatte jede Datenart ihren eigenen Import oder gar keinen: ein
 * Trainer-Import hier, ein Schwimmer-Import dort, ein Assistent für
 * Personendaten, und für Mannschaften, Trainingszeiten, Gruppen oder
 * Atteste überhaupt keinen. Wer einen Verein in dieses System bringt,
 * tippt sonst Wochen lang.
 *
 * Hier steht der Weg einmal: Datei einlesen → Spalten zuordnen →
 * VORSCHAU → schreiben. Was sich je Datenart unterscheidet, steht in
 * einem Katalog (self::arten()): welche Spalten es gibt, welche
 * Pflicht sind, woran eine Zeile einen bestehenden Datensatz erkennt,
 * und wer sie einlesen darf.
 *
 * Drei Regeln, die hier und nur hier stehen:
 *
 * 1. DIE VORSCHAU IST DAS SCHREIBEN — mit abgeschaltetem Schreiben.
 *    Es gibt keinen zweiten Rechenweg für die Vorschau. Zwei Wege
 *    liefen auseinander, und dann zeigt die Vorschau etwas anderes,
 *    als hinterher in der Datenbank steht. Jedes INSERT/UPDATE/DELETE
 *    läuft durch ins()/upd()/del(), und die tun im Probelauf nichts.
 *
 * 2. EINE FEHLENDE SPALTE LÖSCHT NICHTS. Wer eine Liste ohne
 *    E-Mail-Spalte einliest, will keine E-Mail-Adressen löschen. Nur
 *    zugeordnete Spalten werden geschrieben.
 *
 * 3. EINE FEHLERHAFTE ZEILE HÄLT DEN REST NICHT AUF. Sie wird
 *    übersprungen und mit Zeilennummer und Grund gemeldet.
 */
class LSV07I_Import {

    /** Im Probelauf (Vorschau) wird nichts geschrieben. */
    private static $probe = false;

    /** Zähler des laufenden Durchgangs. */
    private static $zaehler = [];

    public static function init() {
        foreach ( [ 'analyse', 'vorschau', 'schreiben', 'muster' ] as $a ) {
            add_action( 'wp_ajax_lsv07i_import_' . $a, [ __CLASS__, $a ] );
        }
        add_action( 'wp_ajax_lsv07i_import_arten', [ __CLASS__, 'arten_endpunkt' ] );
    }

    // ─── KATALOG ─────────────────────────────────────────────────────────────

    /**
     * Alle Datenarten, die eingelesen werden können.
     *
     * 'macher' nennt das Paar aus Prüfen und Schreiben: Es gibt pro
     * Macher GENAU EINE Methode, die beides tut — siehe Regel 1.
     */
    public static function arten() {
        $wochentag = 'Wochentag (Mo, Di, … oder 1–7)';
        $arten = [

            // ── Personen ─────────────────────────────────────────────
            'personen' => [
                'name'    => 'Personendaten',
                'bereich' => 'Personen',
                'hinweis' => 'Die zentrale Personenliste über alle Sparten. '
                           . 'Sparten und Rollen in einer Spalte, z.B. '
                           . '„schwimmen:sportler; triathlon:trainer".',
                'recht'   => 'personen.import',
                'macher'  => 'personen',
                'spalten' => [
                    'nachname'        => [ 'label' => 'Nachname', 'pflicht' => true ],
                    'vorname'         => [ 'label' => 'Vorname',  'pflicht' => true ],
                    'geburtsdatum'    => [ 'label' => 'Geburtsdatum', 'typ' => 'datum' ],
                    'geschlecht'      => [ 'label' => 'Geschlecht (m/w/d)' ],
                    'email'           => [ 'label' => 'E-Mail', 'typ' => 'email' ],
                    'telefon'         => [ 'label' => 'Telefon' ],
                    'dsv_id'          => [ 'label' => 'DSV-ID' ],
                    'mitgliedsnummer' => [ 'label' => 'Mitgliedsnummer' ],
                    'sparten_rollen'  => [ 'label' => 'Sparten und Rollen' ],
                    'notizen'         => [ 'label' => 'Notizen' ],
                    'aktiv'           => [ 'label' => 'Aktiv (ja/nein)', 'typ' => 'ja_nein' ],
                ],
                'schluessel' => [ 'nachname', 'vorname', 'geburtsdatum' ],
            ],

            // ── Schwimmen ────────────────────────────────────────────
            'mannschaften' => [
                'name'    => 'Mannschaften (Schwimmen)',
                'bereich' => 'Schwimmen',
                'hinweis' => 'Der Name ist eindeutig: Eine Mannschaft, die es '
                           . 'schon gibt, wird nicht doppelt angelegt.',
                'recht'   => 'schwimmen.mannschaft.create',
                'macher'  => 'gruppe',
                'ziel'    => [ 'tabelle' => 'lsv07_gruppen', 'hat_aktiv' => false ],
                'spalten' => [
                    'name'       => [ 'label' => 'Name', 'pflicht' => true ],
                    'sortierung' => [ 'label' => 'Sortierung (Zahl)', 'typ' => 'zahl' ],
                ],
                'schluessel' => [ 'name' ],
            ],
            'schwimmer' => [
                'name'    => 'Sportler (Schwimmen)',
                'bereich' => 'Schwimmen',
                'hinweis' => 'Eine Mannschaft, die es noch nicht gibt, wird '
                           . 'angelegt. Erkannt wird ein Sportler an Nachname, '
                           . 'Vorname und Geburtsdatum.',
                'recht'   => 'schwimmen.schwimmer.import',
                'macher'  => 'schwimmer',
                'spalten' => [
                    'nachname'     => [ 'label' => 'Nachname', 'pflicht' => true ],
                    'vorname'      => [ 'label' => 'Vorname',  'pflicht' => true ],
                    'geburtsdatum' => [ 'label' => 'Geburtsdatum', 'typ' => 'datum', 'pflicht' => true ],
                    'mannschaft'   => [ 'label' => 'Mannschaft' ],
                    'email'        => [ 'label' => 'E-Mail', 'typ' => 'email' ],
                    'dsv_id'       => [ 'label' => 'DSV-ID' ],
                    'attest'       => [ 'label' => 'Attest gültig bis', 'typ' => 'datum' ],
                    'datenschutz'  => [ 'label' => 'Datenschutz unterschrieben (ja/nein)', 'typ' => 'ja_nein' ],
                    'notizen'      => [ 'label' => 'Notizen' ],
                ],
                'schluessel' => [ 'nachname', 'vorname', 'geburtsdatum' ],
            ],
            'trainer' => [
                'name'    => 'Trainer (Schwimmen)',
                'bereich' => 'Schwimmen',
                'hinweis' => 'Mannschaften mit Semikolon trennen. Eine '
                           . 'Mannschaft, die es noch nicht gibt, wird angelegt.',
                'recht'   => 'schwimmen.schwimmer.import',
                'macher'  => 'trainer',
                'ziel'    => [ 'tabelle' => 'lsv07i_trainer',
                               'zuordnung' => 'lsv07i_trainer_mannschaft',
                               'zu_spalte' => 'mannschaft_id',
                               'gruppen_tabelle' => 'lsv07_gruppen',
                               'gruppen_aktiv' => false ],
                'spalten' => [
                    'name'         => [ 'label' => 'Name', 'pflicht' => true ],
                    'email'        => [ 'label' => 'E-Mail', 'typ' => 'email' ],
                    'mannschaften' => [ 'label' => 'Mannschaften' ],
                    'aktiv'        => [ 'label' => 'Aktiv (ja/nein)', 'typ' => 'ja_nein' ],
                ],
                'schluessel' => [ 'name' ],
            ],
            'zeiten' => [
                'name'    => 'Trainingszeiten (Schwimmen)',
                'bereich' => 'Schwimmen',
                'hinweis' => 'Je Zeile eine Trainingszeit. ' . $wochentag . '.',
                'recht'   => 'schwimmen.slot.create',
                'macher'  => 'zeiten',
                'ziel'    => [ 'tabelle' => 'lsv07i_training_slots',
                               'gruppen_spalte' => 'mannschaft_id',
                               'gruppen_tabelle' => 'lsv07_gruppen',
                               'gruppen_aktiv' => false ],
                'spalten' => [
                    'gruppe'    => [ 'label' => 'Mannschaft', 'pflicht' => true ],
                    'wochentag' => [ 'label' => $wochentag, 'typ' => 'wochentag', 'pflicht' => true ],
                    'von'       => [ 'label' => 'Von (HH:MM)', 'typ' => 'zeit', 'pflicht' => true ],
                    'bis'       => [ 'label' => 'Bis (HH:MM)', 'typ' => 'zeit', 'pflicht' => true ],
                ],
                'schluessel' => [ 'gruppe', 'wochentag', 'von' ],
            ],
            'kontakte' => [
                'name'    => 'Kontaktpersonen',
                'bereich' => 'Schwimmen',
                'hinweis' => 'Der Sportler wird über seinen Namen gefunden — '
                           . '„Nachname, Vorname (JJJJ)" ist am eindeutigsten. '
                           . 'Mehrere Kontakte je Sportler: mehrere Zeilen.',
                'recht'   => 'schwimmen.schwimmer.import',
                'macher'  => 'kontakte',
                'spalten' => [
                    'sportler' => [ 'label' => 'Sportler (Name)', 'pflicht' => true ],
                    'name'     => [ 'label' => 'Name der Kontaktperson', 'pflicht' => true ],
                    'telefon'  => [ 'label' => 'Telefon' ],
                    'email'    => [ 'label' => 'E-Mail', 'typ' => 'email' ],
                ],
                'schluessel' => [ 'sportler', 'name' ],
            ],
            'atteste' => [
                'name'    => 'Atteste (Ablaufdatum)',
                'bereich' => 'Schwimmen',
                'hinweis' => 'Setzt nur das Ablaufdatum an einem bestehenden '
                           . 'Sportler. Angelegt wird dabei niemand.',
                'recht'   => 'attest.edit',
                'macher'  => 'atteste',
                'spalten' => [
                    'sportler' => [ 'label' => 'Sportler (Name)', 'pflicht' => true ],
                    'attest'   => [ 'label' => 'Attest gültig bis', 'typ' => 'datum', 'pflicht' => true ],
                ],
                'schluessel' => [ 'sportler' ],
            ],

            // ── Verein ───────────────────────────────────────────────
            'saisons' => [
                'name'    => 'Saisons',
                'bereich' => 'Verein',
                'hinweis' => 'Der Name ist eindeutig. „Aktiv" setzt die Saison '
                           . 'NICHT auf aktiv — das bleibt der Saison-Verwaltung '
                           . 'überlassen, damit nicht aus Versehen zwei Saisons '
                           . 'gleichzeitig laufen.',
                'recht'   => 'admin.saison.manage',
                'macher'  => 'saisons',
                'spalten' => [
                    'name'  => [ 'label' => 'Name', 'pflicht' => true ],
                    'start' => [ 'label' => 'Start', 'typ' => 'datum', 'pflicht' => true ],
                    'ende'  => [ 'label' => 'Ende', 'typ' => 'datum' ],
                ],
                'schluessel' => [ 'name' ],
            ],
        ];

        /* ── Triathlon und Fitness ───────────────────────────────────
           Dieselben vier Arten, dieselben Macher — nur andere Tabellen.
           Zweimal aufgeschrieben wären es zwei Dinge, die auseinander
           laufen. */
        $sparten = [
            'tri' => [ 'name' => 'Triathlon', 'wort' => 'Gruppe',
                       'gruppen' => 'lsv07i_tri_gruppen',
                       'sportler' => 'lsv07i_tri_sportler',
                       'sportler_gruppe' => 'lsv07i_tri_sportler_gruppe',
                       'trainer' => 'lsv07i_tri_trainer',
                       'trainer_gruppe' => 'lsv07i_tri_trainer_gruppe',
                       'slots' => 'lsv07i_tri_slots',
                       'recht_sp' => 'triathlon.sportler.import',
                       'recht_gr' => 'triathlon.gruppe.create',
                       'recht_sl' => 'triathlon.slot.create' ],
            'fit' => [ 'name' => 'Fitness', 'wort' => 'Gruppe',
                       'gruppen' => 'lsv07i_fit_gruppen',
                       'sportler' => 'lsv07i_fit_sportler',
                       'sportler_gruppe' => 'lsv07i_fit_sportler_gruppe',
                       'trainer' => 'lsv07i_fit_trainer',
                       'trainer_gruppe' => 'lsv07i_fit_trainer_gruppe',
                       'slots' => 'lsv07i_fit_slots',
                       'recht_sp' => 'fitness.sportler.import',
                       'recht_gr' => 'fitness.gruppe.create',
                       'recht_sl' => 'fitness.slot.create' ],
        ];
        foreach ( $sparten as $k => $s ) {
            $arten[ $k . '_gruppen' ] = [
                'name'    => 'Gruppen (' . $s['name'] . ')',
                'bereich' => $s['name'],
                'hinweis' => 'Eine Gruppe, die es schon gibt, wird nicht doppelt angelegt.',
                'recht'   => $s['recht_gr'],
                'macher'  => 'gruppe',
                'ziel'    => [ 'tabelle' => $s['gruppen'], 'hat_aktiv' => true ],
                'spalten' => [
                    'name'       => [ 'label' => 'Name', 'pflicht' => true ],
                    'sortierung' => [ 'label' => 'Sortierung (Zahl)', 'typ' => 'zahl' ],
                    'aktiv'      => [ 'label' => 'Aktiv (ja/nein)', 'typ' => 'ja_nein' ],
                ],
                'schluessel' => [ 'name' ],
            ];
            $arten[ $k . '_sportler' ] = [
                'name'    => 'Sportler (' . $s['name'] . ')',
                'bereich' => $s['name'],
                'hinweis' => 'Gruppen mit Semikolon trennen. Eine Gruppe, die es '
                           . 'noch nicht gibt, wird angelegt.',
                'recht'   => $s['recht_sp'],
                'macher'  => 'sportler',
                'ziel'    => [ 'tabelle' => $s['sportler'],
                               'zuordnung' => $s['sportler_gruppe'],
                               'zu_spalte' => 'gruppe_id',
                               'zu_person' => 'sportler_id',
                               'gruppen_tabelle' => $s['gruppen'],
                               'gruppen_aktiv' => true ],
                'spalten' => [
                    'nachname'     => [ 'label' => 'Nachname', 'pflicht' => true ],
                    'vorname'      => [ 'label' => 'Vorname',  'pflicht' => true ],
                    'geburtsdatum' => [ 'label' => 'Geburtsdatum', 'typ' => 'datum' ],
                    'email'        => [ 'label' => 'E-Mail', 'typ' => 'email' ],
                    'gruppen'      => [ 'label' => 'Gruppen' ],
                    'notizen'      => [ 'label' => 'Notizen' ],
                    'aktiv'        => [ 'label' => 'Aktiv (ja/nein)', 'typ' => 'ja_nein' ],
                ],
                'schluessel' => [ 'nachname', 'vorname', 'geburtsdatum' ],
            ];
            $arten[ $k . '_trainer' ] = [
                'name'    => 'Trainer (' . $s['name'] . ')',
                'bereich' => $s['name'],
                'hinweis' => 'Gruppen mit Semikolon trennen.',
                'recht'   => $s['recht_sp'],
                'macher'  => 'trainer',
                'ziel'    => [ 'tabelle' => $s['trainer'],
                               'zuordnung' => $s['trainer_gruppe'],
                               'zu_spalte' => 'gruppe_id',
                               'gruppen_tabelle' => $s['gruppen'],
                               'gruppen_aktiv' => true ],
                'spalten' => [
                    'name'         => [ 'label' => 'Name', 'pflicht' => true ],
                    'email'        => [ 'label' => 'E-Mail', 'typ' => 'email' ],
                    'mannschaften' => [ 'label' => 'Gruppen' ],
                    'aktiv'        => [ 'label' => 'Aktiv (ja/nein)', 'typ' => 'ja_nein' ],
                ],
                'schluessel' => [ 'name' ],
            ];
            $arten[ $k . '_zeiten' ] = [
                'name'    => 'Trainingszeiten (' . $s['name'] . ')',
                'bereich' => $s['name'],
                'hinweis' => 'Je Zeile eine Trainingszeit. ' . $wochentag . '.',
                'recht'   => $s['recht_sl'],
                'macher'  => 'zeiten',
                'ziel'    => [ 'tabelle' => $s['slots'],
                               'gruppen_spalte' => 'gruppe_id',
                               'gruppen_tabelle' => $s['gruppen'],
                               'gruppen_aktiv' => true ],
                'spalten' => [
                    'gruppe'    => [ 'label' => 'Gruppe', 'pflicht' => true ],
                    'wochentag' => [ 'label' => $wochentag, 'typ' => 'wochentag', 'pflicht' => true ],
                    'von'       => [ 'label' => 'Von (HH:MM)', 'typ' => 'zeit', 'pflicht' => true ],
                    'bis'       => [ 'label' => 'Bis (HH:MM)', 'typ' => 'zeit', 'pflicht' => true ],
                ],
                'schluessel' => [ 'gruppe', 'wochentag', 'von' ],
            ];
        }

        return $arten;
    }

    /** Eine Art oder null. */
    public static function art( $key ) {
        $alle = self::arten();
        return $alle[ $key ] ?? null;
    }

    // ─── ZUGANG ──────────────────────────────────────────────────────────────

    /**
     * Darf das angemeldete Konto diese Art einlesen?
     *
     * Jede Art hat ihr eigenes Recht. Ein einzelnes „darf importieren"
     * für alles wäre ein Generalschlüssel: Wer Trainingszeiten
     * einlesen darf, bekäme damit auch die Personendaten des Vereins.
     */
    public static function darf( $key ) {
        $art = self::art( $key );
        if ( ! $art ) return false;
        if ( LSV07I_Access::is_admin() ) return true;
        if ( ! class_exists( 'LSV07I_Permissions' ) ) return false;
        return LSV07I_Permissions::can_current( $art['recht'] );
    }

    private static function gatter( $key ) {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
        if ( ! LSV07I_Access::is_intern() && ! LSV07I_Access::is_admin()
             && ! LSV07I_Access::is_tri_intern() && ! LSV07I_Access::is_fit_intern() ) {
            wp_send_json_error( [ 'message' => 'Kein Zugriff auf den internen Bereich.' ], 403 );
        }
        if ( $key !== null && ! self::darf( $key ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Datenart fehlt das Recht zum Einlesen.' ], 403 );
        }
    }

    // ─── ENDPUNKTE ───────────────────────────────────────────────────────────

    /** Welche Arten darf dieses Konto einlesen? */
    public static function arten_endpunkt() {
        self::gatter( null );
        $out = [];
        foreach ( self::arten() as $key => $art ) {
            if ( ! self::darf( $key ) ) continue;
            $spalten = [];
            foreach ( $art['spalten'] as $feld => $def ) {
                $spalten[] = [
                    'feld'    => $feld,
                    'label'   => $def['label'],
                    'pflicht' => ! empty( $def['pflicht'] ),
                ];
            }
            $out[ $key ] = [
                'name'    => $art['name'],
                'bereich' => $art['bereich'],
                'hinweis' => $art['hinweis'],
                'spalten' => $spalten,
            ];
        }
        wp_send_json_success( [ 'arten' => $out ] );
    }

    /** Eine Musterdatei: Kopfzeile plus eine Beispielzeile. */
    public static function muster() {
        $key = sanitize_text_field( $_POST['art'] ?? '' );
        self::gatter( $key );
        $art = self::art( $key );

        $kopf = [];
        $bsp  = [];
        foreach ( $art['spalten'] as $feld => $def ) {
            $kopf[] = $def['label'];
            $bsp[]  = self::beispiel( $feld, $def );
        }
        $csv = self::csv_zeile( $kopf ) . "\r\n" . self::csv_zeile( $bsp ) . "\r\n";
        wp_send_json_success( [
            'dateiname' => 'muster-' . $key . '.csv',
            'csv'       => $csv,
        ] );
    }

    private static function beispiel( $feld, $def ) {
        $typ = $def['typ'] ?? 'text';
        if ( $typ === 'datum' )     return '01.03.2012';
        if ( $typ === 'zeit' )      return '17:30';
        if ( $typ === 'zahl' )      return '10';
        if ( $typ === 'ja_nein' )   return 'ja';
        if ( $typ === 'wochentag' ) return 'Mo';
        if ( $typ === 'email' )     return 'name@example.de';
        if ( $feld === 'nachname' ) return 'Berg';
        if ( $feld === 'vorname' )  return 'Mia';
        if ( $feld === 'name' )     return 'Beispiel';
        if ( $feld === 'sportler' ) return 'Berg, Mia (2012)';
        if ( $feld === 'gruppe' )   return 'Wettkampf';
        if ( $feld === 'mannschaften' || $feld === 'gruppen' ) return 'Wettkampf; Anfänger';
        if ( $feld === 'sparten_rollen' ) return 'schwimmen:sportler';
        return '';
    }

    /** Schritt 1: Spalten der Datei erkennen und vorbelegen. */
    public static function analyse() {
        $key = sanitize_text_field( $_POST['art'] ?? '' );
        self::gatter( $key );
        $art = self::art( $key );

        $csv = (string) wp_unslash( $_POST['csv'] ?? '' );
        if ( trim( $csv ) === '' ) wp_send_json_error( [ 'message' => 'Keine Daten empfangen.' ] );
        $delim = (string) ( $_POST['delimiter'] ?? '' );
        if ( ! in_array( $delim, [ ';', ',', "\t", '|' ], true ) ) $delim = self::trennzeichen( $csv );

        $roh = self::csv_zeilen( $csv, $delim );
        if ( count( $roh ) < 2 ) {
            wp_send_json_error( [ 'message' => 'Die Datei enthält keine Datenzeilen.' ] );
        }
        $kopf = array_map( 'trim', array_shift( $roh ) );

        $spalten = [];
        foreach ( $kopf as $i => $name ) {
            $bsp = [];
            foreach ( $roh as $z ) {
                $w = trim( (string) ( $z[ $i ] ?? '' ) );
                if ( $w !== '' ) $bsp[] = $w;
                if ( count( $bsp ) >= 3 ) break;
            }
            $spalten[] = [
                'index'     => $i,
                'name'      => $name !== '' ? $name : 'Spalte ' . ( $i + 1 ),
                'beispiele' => $bsp,
            ];
        }

        wp_send_json_success( [
            'delimiter' => $delim,
            'zeilen'    => count( $roh ),
            'spalten'   => $spalten,
            'zuordnung' => self::zuordnung_raten( $kopf, $art ),
        ] );
    }

    /** Schritt 2: Vorschau — nichts wird geschrieben. */
    public static function vorschau() {
        self::lauf( true );
    }

    /** Schritt 3: schreiben. */
    public static function schreiben() {
        self::lauf( false );
    }

    /**
     * Der eine Durchgang. $probe entscheidet nur, ob geschrieben wird.
     *
     * Beide Endpunkte laufen durch dieselbe Schleife, dieselben
     * Prüfungen, dieselben Macher. Das ist der Grund, aus dem die
     * Vorschau stimmt.
     */
    private static function lauf( $probe ) {
        $key = sanitize_text_field( $_POST['art'] ?? '' );
        self::gatter( $key );
        $art = self::art( $key );

        $csv = (string) wp_unslash( $_POST['csv'] ?? '' );
        if ( trim( $csv ) === '' ) wp_send_json_error( [ 'message' => 'Keine Daten empfangen.' ] );
        $delim = (string) ( $_POST['delimiter'] ?? '' );
        if ( ! in_array( $delim, [ ';', ',', "\t", '|' ], true ) ) $delim = self::trennzeichen( $csv );

        $zu_raw = $_POST['zuordnung'] ?? '[]';
        $zu     = is_string( $zu_raw ) ? json_decode( wp_unslash( $zu_raw ), true ) : $zu_raw;
        if ( ! is_array( $zu ) ) $zu = [];
        /* Nur Felder, die diese Art kennt. Was aus dem Browser kommt,
           ist ein Vorschlag. */
        $zu = array_map( function ( $f ) use ( $art ) {
            $f = is_string( $f ) ? $f : '';
            return isset( $art['spalten'][ $f ] ) ? $f : '';
        }, array_values( $zu ) );

        /* Pflichtspalten müssen zugeordnet sein. Ohne diese Prüfung
           liefe der Import an und jede Zeile würde einzeln scheitern —
           tausend Fehlermeldungen statt einer Aussage. */
        $fehlend = [];
        foreach ( $art['spalten'] as $feld => $def ) {
            if ( empty( $def['pflicht'] ) ) continue;
            if ( ! in_array( $feld, $zu, true ) ) $fehlend[] = $def['label'];
        }
        if ( $fehlend ) {
            wp_send_json_error( [ 'message' => 'Diese Pflichtspalten sind nicht zugeordnet: '
                                               . implode( ', ', $fehlend ) ] );
        }

        $roh = self::csv_zeilen( $csv, $delim );
        if ( count( $roh ) < 2 ) wp_send_json_error( [ 'message' => 'Die Datei enthält keine Datenzeilen.' ] );
        array_shift( $roh );
        if ( count( $roh ) > 5000 ) {
            wp_send_json_error( [ 'message' => 'Mehr als 5000 Zeilen auf einmal. '
                                             . 'Bitte die Datei aufteilen.' ] );
        }

        self::$probe   = (bool) $probe;
        self::$zaehler = [ 'neu' => 0, 'aenderung' => 0, 'unveraendert' => 0, 'fehler' => 0 ];
        $macher = [ __CLASS__, 'mach_' . $art['macher'] ];

        $zeilen = [];
        foreach ( $roh as $nr => $z ) {
            $werte = [];
            foreach ( $zu as $i => $feld ) {
                if ( $feld === '' ) continue;
                $werte[ $feld ] = trim( (string) ( $z[ $i ] ?? '' ) );
            }
            $erg = self::zeile_pruefen( $art, $werte );
            if ( $erg === null ) {
                $erg = call_user_func( $macher, $key, $art, $werte );
            }
            $erg['zeile'] = $nr + 2;   // +1 Kopfzeile, +1 für „menschliche" Zählung
            self::$zaehler[ $erg['status'] ] = ( self::$zaehler[ $erg['status'] ] ?? 0 ) + 1;
            $zeilen[] = $erg;
        }
        self::$probe = false;

        if ( ! $probe ) {
            LSV07I_Log::write( 'import.' . $key, [
                'bereich'  => 'Admin',
                'ziel_typ' => 'import',
                'details'  => $art['name'] . ': ' . self::$zaehler['neu'] . ' neu, '
                            . self::$zaehler['aenderung'] . ' geändert, '
                            . self::$zaehler['unveraendert'] . ' unverändert, '
                            . self::$zaehler['fehler'] . ' Fehler',
            ] );
        }

        wp_send_json_success( [
            'probe'   => (bool) $probe,
            'zaehler' => self::$zaehler,
            /* Höchstens 300 Zeilen zurück: Eine Liste mit 5000 Zeilen
               liest niemand, und sie macht die Antwort unbrauchbar
               gross. Die Zähler nennen immer alles. */
            'zeilen'  => array_slice( $zeilen, 0, 300 ),
            'gekuerzt'=> count( $zeilen ) > 300,
        ] );
    }

    // ─── GEMEINSAME PRÜFUNG ──────────────────────────────────────────────────

    /** Pflichtfelder und Formate. Gibt bei einem Fehler das Ergebnis zurück. */
    private static function zeile_pruefen( $art, &$werte ) {
        foreach ( $art['spalten'] as $feld => $def ) {
            $wert = $werte[ $feld ] ?? '';
            if ( ! empty( $def['pflicht'] ) && $wert === '' ) {
                return self::fehler( 'Pflichtfeld „' . $def['label'] . '" ist leer.' );
            }
            if ( $wert === '' ) continue;
            $typ = $def['typ'] ?? 'text';
            if ( $typ === 'datum' ) {
                $d = self::datum( $wert );
                if ( ! $d ) return self::fehler( '„' . $def['label'] . '": „' . $wert . '" ist kein Datum.' );
                $werte[ $feld ] = $d;
            } elseif ( $typ === 'zeit' ) {
                $t = self::zeit( $wert );
                if ( ! $t ) return self::fehler( '„' . $def['label'] . '": „' . $wert . '" ist keine Uhrzeit.' );
                $werte[ $feld ] = $t;
            } elseif ( $typ === 'wochentag' ) {
                $w = self::wochentag( $wert );
                if ( ! $w ) return self::fehler( '„' . $def['label'] . '": „' . $wert . '" ist kein Wochentag.' );
                $werte[ $feld ] = $w;
            } elseif ( $typ === 'zahl' ) {
                if ( ! is_numeric( str_replace( ',', '.', $wert ) ) ) {
                    return self::fehler( '„' . $def['label'] . '": „' . $wert . '" ist keine Zahl.' );
                }
                $werte[ $feld ] = (int) $wert;
            } elseif ( $typ === 'ja_nein' ) {
                $j = self::ja_nein( $wert );
                if ( $j === null ) return self::fehler( '„' . $def['label'] . '": „' . $wert . '" ist kein ja/nein.' );
                $werte[ $feld ] = $j;
            } elseif ( $typ === 'email' ) {
                /* Eine kaputte E-Mail-Adresse hält die Zeile nicht auf —
                   sie wird weggelassen und gemeldet. Sonst scheitert ein
                   ganzer Sportler an einem Tippfehler im Elternkontakt. */
                if ( ! is_email( $wert ) ) {
                    $werte[ $feld ] = '';
                    $werte['__hinweis'][] = 'E-Mail „' . $wert . '" sieht nicht wie eine Adresse aus und wurde weggelassen.';
                }
            }
        }
        return null;
    }

    private static function fehler( $text ) {
        return [ 'status' => 'fehler', 'fehler' => $text, 'name' => '', 'aenderungen' => [] ];
    }

    private static function ergebnis( $status, $name, $aenderungen = [], $werte = [] ) {
        return [
            'status'      => $status,
            'fehler'      => '',
            'name'        => $name,
            'aenderungen' => $aenderungen,
            'hinweise'    => $werte['__hinweis'] ?? [],
        ];
    }

    // ─── SCHREIBEN NUR ÜBER DIESE DREI ───────────────────────────────────────

    private static function ins( $tabelle, $daten ) {
        if ( self::$probe ) return 0;
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . $tabelle, $daten );
        return (int) $wpdb->insert_id;
    }
    private static function upd( $tabelle, $daten, $wo ) {
        if ( self::$probe ) return 0;
        global $wpdb;
        return (int) $wpdb->update( $wpdb->prefix . $tabelle, $daten, $wo );
    }
    private static function del( $tabelle, $wo ) {
        if ( self::$probe ) return 0;
        global $wpdb;
        return (int) $wpdb->delete( $wpdb->prefix . $tabelle, $wo );
    }

    // ─── MACHER ──────────────────────────────────────────────────────────────

    /** Gruppen und Mannschaften (Schwimmen, Triathlon, Fitness). */
    private static function mach_gruppe( $key, $art, $werte ) {
        global $wpdb;
        $t   = $art['ziel']['tabelle'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}$t WHERE name = %s LIMIT 1", $werte['name'] ), ARRAY_A );

        $daten = [];
        if ( isset( $werte['sortierung'] ) && $werte['sortierung'] !== '' ) {
            $daten['sort_order'] = (int) $werte['sortierung'];
        }
        if ( ! empty( $art['ziel']['hat_aktiv'] ) && isset( $werte['aktiv'] ) && $werte['aktiv'] !== '' ) {
            $daten['aktiv'] = (int) $werte['aktiv'];
        }

        if ( ! $row ) {
            $daten['name'] = $werte['name'];
            self::ins( $t, $daten );
            return self::ergebnis( 'neu', $werte['name'], [ 'angelegt' => $werte['name'] ], $werte );
        }
        $aend = self::unterschiede( $row, $daten );
        if ( ! $aend ) return self::ergebnis( 'unveraendert', $werte['name'], [], $werte );
        self::upd( $t, $daten, [ 'id' => (int) $row['id'] ] );
        return self::ergebnis( 'aenderung', $werte['name'], $aend, $werte );
    }

    /** Sportler Schwimmen (mv_swimmers + Mannschafts-Zuordnung). */
    private static function mach_schwimmer( $key, $art, $werte ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}mv_swimmers
              WHERE last_name = %s AND first_name = %s AND birth_date = %s LIMIT 1",
            $werte['nachname'], $werte['vorname'], $werte['geburtsdatum'] ), ARRAY_A );

        /* Mannschaft: Nur wenn die Spalte zugeordnet ist. Fehlt sie,
           bleibt die bestehende Mannschaft, wie sie ist (Regel 2). */
        $mann_id = null;
        if ( array_key_exists( 'mannschaft', $werte ) && $werte['mannschaft'] !== '' ) {
            $mann_id = self::gruppe_holen( 'lsv07_gruppen', $werte['mannschaft'], false );
        }

        $daten = [];
        if ( array_key_exists( 'email', $werte ) )       $daten['email']          = $werte['email'];
        if ( array_key_exists( 'dsv_id', $werte ) )      $daten['dsv_id']         = $werte['dsv_id'];
        if ( array_key_exists( 'attest', $werte ) )      $daten['attest_expires'] = $werte['attest'] ?: null;
        if ( array_key_exists( 'notizen', $werte ) )     $daten['notes']          = $werte['notizen'];
        if ( array_key_exists( 'datenschutz', $werte ) && $werte['datenschutz'] !== '' ) {
            $daten['datenschutz'] = (int) $werte['datenschutz'];
        }
        if ( $mann_id ) $daten['team_id'] = $mann_id;

        $name = $werte['nachname'] . ', ' . $werte['vorname'];
        if ( ! $row ) {
            $daten['last_name']  = $werte['nachname'];
            $daten['first_name'] = $werte['vorname'];
            $daten['birth_date'] = $werte['geburtsdatum'];
            $daten['active']     = 1;
            if ( ! isset( $daten['team_id'] ) ) $daten['team_id'] = 0;
            $id = self::ins( 'mv_swimmers', $daten );
            if ( $mann_id && $id ) {
                self::ins( 'lsv07i_swimmer_gruppen', [ 'swimmer_id' => $id, 'gruppe_id' => $mann_id ] );
            }
            return self::ergebnis( 'neu', $name, [ 'angelegt' => $name ], $werte );
        }

        $aend = self::unterschiede( $row, $daten );
        /* Die Zuordnungstabelle ist die zweite Stelle, an der die
           Mannschaft steht. Fehlt sie dort, ist das eine Änderung —
           auch wenn team_id schon stimmt. */
        if ( $mann_id ) {
            $hat = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}lsv07i_swimmer_gruppen
                  WHERE swimmer_id = %d AND gruppe_id = %d", (int) $row['id'], $mann_id ) );
            if ( ! $hat ) {
                $aend['Mannschaft'] = $werte['mannschaft'];
                self::ins( 'lsv07i_swimmer_gruppen',
                    [ 'swimmer_id' => (int) $row['id'], 'gruppe_id' => $mann_id ] );
            }
        }
        if ( ! $aend ) return self::ergebnis( 'unveraendert', $name, [], $werte );
        if ( $daten ) self::upd( 'mv_swimmers', $daten, [ 'id' => (int) $row['id'] ] );
        return self::ergebnis( 'aenderung', $name, $aend, $werte );
    }

    /** Sportler Triathlon/Fitness. */
    private static function mach_sportler( $key, $art, $werte ) {
        global $wpdb;
        $z = $art['ziel'];
        $t = $z['tabelle'];

        $wo = "last_name = %s AND first_name = %s";
        $args = [ $werte['nachname'], $werte['vorname'] ];
        if ( ! empty( $werte['geburtsdatum'] ) ) {
            $wo .= " AND birth_date = %s";
            $args[] = $werte['geburtsdatum'];
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}$t WHERE $wo LIMIT 1", $args ), ARRAY_A );

        $daten = [];
        if ( array_key_exists( 'email', $werte ) )   $daten['email'] = $werte['email'];
        if ( array_key_exists( 'notizen', $werte ) ) $daten['notes'] = $werte['notizen'];
        if ( array_key_exists( 'geburtsdatum', $werte ) && $werte['geburtsdatum'] !== '' ) {
            $daten['birth_date'] = $werte['geburtsdatum'];
        }
        if ( array_key_exists( 'aktiv', $werte ) && $werte['aktiv'] !== '' ) {
            $daten['aktiv'] = (int) $werte['aktiv'];
        }

        $name = $werte['nachname'] . ', ' . $werte['vorname'];
        $neu  = false;
        if ( ! $row ) {
            $daten['last_name']  = $werte['nachname'];
            $daten['first_name'] = $werte['vorname'];
            if ( ! isset( $daten['aktiv'] ) ) $daten['aktiv'] = 1;
            $id  = self::ins( $t, $daten );
            $neu = true;
        } else {
            $id = (int) $row['id'];
        }

        $aend = $neu ? [ 'angelegt' => $name ] : self::unterschiede( $row, $daten );
        if ( ! $neu && $daten && $aend ) self::upd( $t, $daten, [ 'id' => $id ] );

        $aend = array_merge( $aend, self::gruppen_zuordnen(
            $z, $id, $werte['gruppen'] ?? null, $z['zu_person'] ?? 'sportler_id' ) );

        if ( $neu )    return self::ergebnis( 'neu', $name, $aend, $werte );
        if ( ! $aend ) return self::ergebnis( 'unveraendert', $name, [], $werte );
        return self::ergebnis( 'aenderung', $name, $aend, $werte );
    }

    /** Trainer (alle drei Sparten). */
    private static function mach_trainer( $key, $art, $werte ) {
        global $wpdb;
        $z = $art['ziel'];
        $t = $z['tabelle'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}$t WHERE name = %s LIMIT 1", $werte['name'] ), ARRAY_A );

        $daten = [];
        if ( array_key_exists( 'email', $werte ) ) $daten['email'] = $werte['email'];
        if ( array_key_exists( 'aktiv', $werte ) && $werte['aktiv'] !== '' ) {
            $daten['aktiv'] = (int) $werte['aktiv'];
        }

        $neu = false;
        if ( ! $row ) {
            $daten['name'] = $werte['name'];
            if ( ! isset( $daten['aktiv'] ) ) $daten['aktiv'] = 1;
            $id  = self::ins( $t, $daten );
            $neu = true;
        } else {
            $id = (int) $row['id'];
        }

        $aend = $neu ? [ 'angelegt' => $werte['name'] ] : self::unterschiede( $row, $daten );
        if ( ! $neu && $daten && $aend ) self::upd( $t, $daten, [ 'id' => $id ] );
        $aend = array_merge( $aend, self::gruppen_zuordnen(
            $z, $id, $werte['mannschaften'] ?? null, 'trainer_id' ) );

        if ( $neu )    return self::ergebnis( 'neu', $werte['name'], $aend, $werte );
        if ( ! $aend ) return self::ergebnis( 'unveraendert', $werte['name'], [], $werte );
        return self::ergebnis( 'aenderung', $werte['name'], $aend, $werte );
    }

    /** Trainingszeiten (alle drei Sparten). */
    private static function mach_zeiten( $key, $art, $werte ) {
        global $wpdb;
        $z  = $art['ziel'];
        $t  = $z['tabelle'];
        $gs = $z['gruppen_spalte'];
        $gid = self::gruppe_holen( $z['gruppen_tabelle'], $werte['gruppe'], ! empty( $z['gruppen_aktiv'] ) );
        if ( ! $gid ) return self::fehler( 'Die Gruppe „' . $werte['gruppe'] . '" konnte nicht angelegt werden.' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}$t WHERE $gs = %d AND wochentag = %d AND zeit_von = %s LIMIT 1",
            $gid, (int) $werte['wochentag'], $werte['von'] ), ARRAY_A );

        $name = $werte['gruppe'] . ' · ' . self::wochentag_name( $werte['wochentag'] )
              . ' ' . substr( $werte['von'], 0, 5 ) . '–' . substr( $werte['bis'], 0, 5 );

        if ( ! $row ) {
            self::ins( $t, [ $gs => $gid, 'wochentag' => (int) $werte['wochentag'],
                             'zeit_von' => $werte['von'], 'zeit_bis' => $werte['bis'] ] );
            return self::ergebnis( 'neu', $name, [ 'angelegt' => $name ], $werte );
        }
        if ( substr( (string) $row['zeit_bis'], 0, 8 ) === $werte['bis'] ) {
            return self::ergebnis( 'unveraendert', $name, [], $werte );
        }
        self::upd( $t, [ 'zeit_bis' => $werte['bis'] ], [ 'id' => (int) $row['id'] ] );
        return self::ergebnis( 'aenderung', $name,
            [ 'Bis' => substr( (string) $row['zeit_bis'], 0, 5 ) . ' → ' . substr( $werte['bis'], 0, 5 ) ], $werte );
    }

    /** Kontaktpersonen eines Sportlers. */
    private static function mach_kontakte( $key, $art, $werte ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $sid = self::sportler_finden( $werte['sportler'] );
        if ( ! $sid ) return self::fehler( 'Kein Sportler zu „' . $werte['sportler'] . '" gefunden.' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_kontakte WHERE swimmer_id = %d AND name = %s LIMIT 1",
            $sid, $werte['name'] ), ARRAY_A );

        $daten = [];
        if ( array_key_exists( 'telefon', $werte ) ) $daten['telefon'] = $werte['telefon'];
        if ( array_key_exists( 'email', $werte ) )   $daten['email']   = $werte['email'];

        $name = $werte['sportler'] . ' → ' . $werte['name'];
        if ( ! $row ) {
            $daten['swimmer_id'] = $sid;
            $daten['name']       = $werte['name'];
            self::ins( 'lsv07i_kontakte', $daten );
            return self::ergebnis( 'neu', $name, [ 'angelegt' => $werte['name'] ], $werte );
        }
        $aend = self::unterschiede( $row, $daten );
        if ( ! $aend ) return self::ergebnis( 'unveraendert', $name, [], $werte );
        self::upd( 'lsv07i_kontakte', $daten, [ 'id' => (int) $row['id'] ] );
        return self::ergebnis( 'aenderung', $name, $aend, $werte );
    }

    /** Attest-Ablaufdaten an bestehenden Sportlern. */
    private static function mach_atteste( $key, $art, $werte ) {
        global $wpdb;
        $sid = self::sportler_finden( $werte['sportler'] );
        if ( ! $sid ) return self::fehler( 'Kein Sportler zu „' . $werte['sportler'] . '" gefunden.' );

        $alt = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT attest_expires FROM {$wpdb->prefix}mv_swimmers WHERE id = %d", $sid ) );
        if ( substr( $alt, 0, 10 ) === $werte['attest'] ) {
            return self::ergebnis( 'unveraendert', $werte['sportler'], [], $werte );
        }
        self::upd( 'mv_swimmers', [ 'attest_expires' => $werte['attest'] ], [ 'id' => $sid ] );
        return self::ergebnis( 'aenderung', $werte['sportler'],
            [ 'Attest' => ( $alt ? substr( $alt, 0, 10 ) : 'keines' ) . ' → ' . $werte['attest'] ], $werte );
    }

    /** Saisons. */
    private static function mach_saisons( $key, $art, $werte ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( ! empty( $werte['ende'] ) && $werte['ende'] < $werte['start'] ) {
            return self::fehler( 'Das Ende liegt vor dem Start.' );
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_saisons WHERE name = %s LIMIT 1", $werte['name'] ), ARRAY_A );

        $daten = [ 'start_datum' => $werte['start'] ];
        if ( array_key_exists( 'ende', $werte ) ) $daten['ende_datum'] = $werte['ende'] ?: null;

        if ( ! $row ) {
            $daten['name']  = $werte['name'];
            /* Nicht aktiv: Zwei aktive Saisons gleichzeitig wären ein
               Zustand, den die Anwesenheit nicht auflösen kann. Welche
               läuft, entscheidet die Saison-Verwaltung. */
            $daten['aktiv'] = 0;
            self::ins( 'lsv07i_saisons', $daten );
            return self::ergebnis( 'neu', $werte['name'], [ 'angelegt' => $werte['name'] ], $werte );
        }
        $aend = self::unterschiede( $row, $daten );
        if ( ! $aend ) return self::ergebnis( 'unveraendert', $werte['name'], [], $werte );
        self::upd( 'lsv07i_saisons', $daten, [ 'id' => (int) $row['id'] ] );
        return self::ergebnis( 'aenderung', $werte['name'], $aend, $werte );
    }

    /** Zentrale Personendaten. */
    private static function mach_personen( $key, $art, $werte ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $wo   = "nachname = %s AND vorname = %s";
        $args = [ $werte['nachname'], $werte['vorname'] ];
        if ( ! empty( $werte['geburtsdatum'] ) ) {
            $wo .= " AND geburtsdatum = %s";
            $args[] = $werte['geburtsdatum'];
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_personen WHERE $wo LIMIT 1", $args ), ARRAY_A );

        $karte = [ 'email' => 'email', 'telefon' => 'telefon', 'dsv_id' => 'dsv_id',
                   'mitgliedsnummer' => 'mitgliedsnummer', 'notizen' => 'notes',
                   'geschlecht' => 'geschlecht' ];
        $daten = [];
        foreach ( $karte as $feld => $spalte ) {
            if ( array_key_exists( $feld, $werte ) ) $daten[ $spalte ] = $werte[ $feld ];
        }
        if ( array_key_exists( 'geburtsdatum', $werte ) && $werte['geburtsdatum'] !== '' ) {
            $daten['geburtsdatum'] = $werte['geburtsdatum'];
        }
        if ( array_key_exists( 'aktiv', $werte ) && $werte['aktiv'] !== '' ) {
            $daten['aktiv'] = (int) $werte['aktiv'];
        }
        if ( isset( $daten['geschlecht'] ) ) {
            $g = strtolower( substr( (string) $daten['geschlecht'], 0, 1 ) );
            $daten['geschlecht'] = in_array( $g, [ 'm', 'w', 'd' ], true ) ? $g : null;
        }

        $name = $werte['nachname'] . ', ' . $werte['vorname'];
        $neu  = false;
        if ( ! $row ) {
            $daten['nachname'] = $werte['nachname'];
            $daten['vorname']  = $werte['vorname'];
            if ( ! isset( $daten['aktiv'] ) ) $daten['aktiv'] = 1;
            $id  = self::ins( 'lsv07i_personen', $daten );
            $neu = true;
        } else {
            $id = (int) $row['id'];
        }

        $aend = $neu ? [ 'angelegt' => $name ] : self::unterschiede( $row, $daten );
        if ( ! $neu && $daten && $aend ) self::upd( 'lsv07i_personen', $daten, [ 'id' => $id ] );

        /* Sparten und Rollen: „schwimmen:sportler; triathlon:trainer".
           Nur hinzufügen, nie wegnehmen — eine Liste ohne diese Spalte
           soll keine Zuordnung löschen (Regel 2). */
        if ( ! empty( $werte['sparten_rollen'] ) && $id ) {
            $erlaubt_sparte = [ 'schwimmen', 'triathlon', 'fitness' ];
            $erlaubt_rolle  = [ 'sportler', 'trainer', 'wart', 'andere' ];
            foreach ( self::liste( $werte['sparten_rollen'] ) as $paar ) {
                $teile  = array_map( 'trim', explode( ':', $paar, 2 ) );
                $sparte = strtolower( $teile[0] ?? '' );
                $rolle  = strtolower( $teile[1] ?? 'sportler' );
                if ( ! in_array( $sparte, $erlaubt_sparte, true ) ) {
                    $aend['übersprungen'] = 'Sparte „' . $paar . '" ist unbekannt';
                    continue;
                }
                if ( ! in_array( $rolle, $erlaubt_rolle, true ) ) {
                    $aend['übersprungen'] = 'Rolle „' . $paar . '" ist unbekannt';
                    continue;
                }
                $hat = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}lsv07i_personen_sparten_rolle
                      WHERE person_id = %d AND sparte = %s AND rolle = %s", $id, $sparte, $rolle ) );
                if ( $hat ) continue;
                self::ins( 'lsv07i_personen_sparten_rolle',
                    [ 'person_id' => $id, 'sparte' => $sparte, 'rolle' => $rolle, 'aktiv' => 1 ] );
                $aend[ $sparte ] = $rolle;
            }
        }

        if ( $neu )    return self::ergebnis( 'neu', $name, $aend, $werte );
        if ( ! $aend ) return self::ergebnis( 'unveraendert', $name, [], $werte );
        return self::ergebnis( 'aenderung', $name, $aend, $werte );
    }

    // ─── HILFEN ──────────────────────────────────────────────────────────────

    /**
     * Was ändert sich wirklich? Nur die Felder, die anders sind.
     *
     * Ohne diesen Vergleich stünde in der Vorschau bei jeder Zeile
     * „geändert", und niemand könnte sehen, was eine Datei wirklich
     * anfasst.
     */
    private static function unterschiede( $row, $daten ) {
        $aend = [];
        foreach ( $daten as $spalte => $neu ) {
            $alt = $row[ $spalte ] ?? null;
            $a   = $alt === null ? '' : (string) $alt;
            $n   = $neu === null ? '' : (string) $neu;
            // Datumsspalten mit Uhrzeit vergleichbar machen
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $a ) ) $a = substr( $a, 0, 10 );
            if ( $a === $n ) continue;
            /* Ein leerer Wert in einer zugeordneten Spalte LÖSCHT nicht:
               Wer eine Spalte leer lässt, hat dort keine Angabe — nicht
               die Angabe „nichts". Löschen geht über die Oberfläche. */
            if ( $n === '' ) continue;   // leer = keine Angabe
            $aend[ $spalte ] = ( $a === '' ? '—' : $a ) . ' → ' . $n;
        }
        return $aend;
    }

    /** Eine Gruppe nachschlagen oder anlegen. */
    private static function gruppe_holen( $tabelle, $name, $hat_aktiv ) {
        global $wpdb;
        $name = trim( (string) $name );
        if ( $name === '' ) return 0;
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}$tabelle WHERE name = %s LIMIT 1", $name ) );
        if ( $id ) return $id;
        $daten = [ 'name' => $name ];
        if ( $hat_aktiv ) $daten['aktiv'] = 1;
        $neu = self::ins( $tabelle, $daten );
        /* Im Probelauf gibt es keine Nummer — die Zeile gilt trotzdem
           als gültig, denn beim Schreiben wird die Gruppe angelegt. */
        return $neu ?: ( self::$probe ? -1 : 0 );
    }

    /** Gruppen eines Sportlers/Trainers ergänzen (nie wegnehmen). */
    private static function gruppen_zuordnen( $z, $id, $liste, $person_spalte ) {
        if ( $liste === null || trim( (string) $liste ) === '' ) return [];
        if ( empty( $z['zuordnung'] ) ) return [];
        global $wpdb;
        $aend = [];
        foreach ( self::liste( $liste ) as $gname ) {
            $gid = self::gruppe_holen( $z['gruppen_tabelle'], $gname, ! empty( $z['gruppen_aktiv'] ) );
            if ( $gid <= 0 ) continue;   // im Probelauf -1
            if ( $id > 0 ) {
                $hat = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}{$z['zuordnung']}
                      WHERE $person_spalte = %d AND {$z['zu_spalte']} = %d", $id, $gid ) );
                if ( $hat ) continue;
            }
            self::ins( $z['zuordnung'], [ $person_spalte => $id, $z['zu_spalte'] => $gid ] );
            $aend[ 'Gruppe' ] = trim( $gname );
        }
        return $aend;
    }

    /**
     * Einen Sportler über seinen Namen finden.
     *
     * Gesucht wird mit derselben Erkennung wie beim Bestzeiten-Import
     * (LSV07I_Ajax_Bestzeiten::match_schwimmer): Sie kennt „Nachname,
     * Vorname (JJJJ)", vertauschte Reihenfolge, Doppelnamen und
     * Schreibweisen. Eine zweite, eigene Erkennung würde irgendwann
     * andere Treffer liefern als die, die der Verein gewohnt ist.
     */
    private static function sportler_finden( $name_raw ) {
        global $wpdb;
        $liste = $wpdb->get_results(
            "SELECT id, first_name, last_name, birth_date FROM {$wpdb->prefix}mv_swimmers
              WHERE active = 1", ARRAY_A );
        if ( ! $liste ) return 0;
        if ( class_exists( 'LSV07I_Ajax_Bestzeiten' )
             && method_exists( 'LSV07I_Ajax_Bestzeiten', 'match_schwimmer' ) ) {
            $treffer = LSV07I_Ajax_Bestzeiten::match_schwimmer( $name_raw, null, $liste );
            if ( is_array( $treffer ) && ! empty( $treffer['id'] ) ) return (int) $treffer['id'];
            if ( is_numeric( $treffer ) && (int) $treffer > 0 ) return (int) $treffer;
        }
        /* Rückfall: genau der geschriebene Name. Lieber nichts finden
           als den Falschen treffen. */
        $n = preg_replace( '/\s*\(\d{4}\)\s*$/', '', trim( (string) $name_raw ) );
        if ( strpos( $n, ',' ) !== false ) {
            list( $nach, $vor ) = array_map( 'trim', explode( ',', $n, 2 ) );
        } else {
            $teile = preg_split( '/\s+/', $n );
            $nach  = array_pop( $teile );
            $vor   = implode( ' ', $teile );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}mv_swimmers
              WHERE last_name = %s AND first_name = %s AND active = 1 LIMIT 1", $nach, $vor ) );
    }

    private static function liste( $text ) {
        $teile = preg_split( '/[;,]/', (string) $text );
        $out = [];
        foreach ( $teile as $t ) {
            $t = trim( $t );
            if ( $t !== '' ) $out[] = $t;
        }
        return $out;
    }

    // ─── FORMATE ─────────────────────────────────────────────────────────────

    /**
     * Ein Datum erkennen.
     *
     * ABSICHTLICH ohne strtotime als letzten Rückfall: strtotime macht
     * aus „13/02/2012" den 13. Februar oder den 2. Januar, je nach
     * Laune der Eingabe, und aus „morgen" ein Datum. Was nicht einem
     * der bekannten Muster entspricht, ist ein Fehler in der Zeile —
     * und wird als solcher gemeldet, statt stillschweigend geraten.
     */
    public static function datum( $wert ) {
        $w = trim( (string) $wert );
        if ( $w === '' ) return '';
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $w, $m ) ) {
            return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "$m[1]-$m[2]-$m[3]" : '';
        }
        if ( preg_match( '/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})$/', $w, $m ) ) {
            $t = (int) $m[1]; $mo = (int) $m[2]; $j = (int) $m[3];
            if ( $j < 100 ) $j += ( $j <= 30 ? 2000 : 1900 );
            if ( ! checkdate( $mo, $t, $j ) ) return '';
            return sprintf( '%04d-%02d-%02d', $j, $mo, $t );
        }
        return '';
    }

    public static function zeit( $wert ) {
        $w = trim( (string) $wert );
        if ( $w === '' ) return '';
        if ( preg_match( '/^(\d{1,2})[:.](\d{2})(?::(\d{2}))?$/', $w, $m ) ) {
            $h = (int) $m[1]; $i = (int) $m[2]; $s = (int) ( $m[3] ?? 0 );
            if ( $h > 23 || $i > 59 || $s > 59 ) return '';
            return sprintf( '%02d:%02d:%02d', $h, $i, $s );
        }
        return '';
    }

    public static function wochentag( $wert ) {
        $w = strtolower( trim( (string) $wert ) );
        if ( $w === '' ) return 0;
        if ( ctype_digit( $w ) ) {
            $n = (int) $w;
            return ( $n >= 1 && $n <= 7 ) ? $n : 0;
        }
        /* GENAUE Schreibweisen, kein Anfangsbuchstabe: Eine Prüfung auf
           die ersten zwei Zeichen machte aus „Dings" einen Dienstag und
           aus „Modul" einen Montag. Lieber eine Zeile als Fehler melden
           als einen erfundenen Wochentag einlesen. */
        $karte = [
            'mo' => 1, 'mon' => 1, 'montag' => 1,
            'di' => 2, 'die' => 2, 'dienstag' => 2,
            'mi' => 3, 'mit' => 3, 'mittwoch' => 3,
            'do' => 4, 'don' => 4, 'donnerstag' => 4,
            'fr' => 5, 'fre' => 5, 'freitag' => 5,
            'sa' => 6, 'sam' => 6, 'samstag' => 6, 'sonnabend' => 6,
            'so' => 7, 'son' => 7, 'sonntag' => 7,
        ];
        $w = strtr( $w, [ 'ä' => 'a', 'ö' => 'o', 'ü' => 'u' ] );
        $w = rtrim( $w, '.' );
        return $karte[ $w ] ?? 0;
    }

    public static function wochentag_name( $n ) {
        $namen = [ 1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag' ];
        return $namen[ (int) $n ] ?? '?';
    }

    public static function ja_nein( $wert ) {
        $w = strtolower( trim( (string) $wert ) );
        if ( in_array( $w, [ 'ja', 'j', '1', 'wahr', 'true', 'x', 'yes', 'y' ], true ) ) return 1;
        if ( in_array( $w, [ 'nein', 'n', '0', 'falsch', 'false', '', 'no' ], true ) ) return 0;
        return null;
    }

    // ─── CSV ─────────────────────────────────────────────────────────────────

    /** Zeilen einer CSV. Eine Stelle für alle Importe. */
    public static function csv_zeilen( $text, $delim ) {
        $text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
        $fp   = fopen( 'php://temp', 'r+' );
        fwrite( $fp, $text );
        rewind( $fp );
        $rows = [];
        while ( ( $z = fgetcsv( $fp, 0, $delim, '"', '\\' ) ) !== false ) {
            if ( $z === [ null ] ) continue;
            if ( count( array_filter( $z, fn( $v ) => trim( (string) $v ) !== '' ) ) === 0 ) continue;
            $rows[] = $z;
        }
        fclose( $fp );
        return $rows;
    }

    /** Das Trennzeichen der ersten Zeile raten. */
    public static function trennzeichen( $text ) {
        $text  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
        $text  = str_replace( [ "\r\n", "\r" ], "\n", $text );
        $erste = strtok( $text, "\n" );
        if ( $erste === false ) return ';';
        $zaehler = [ ';' => 0, ',' => 0, "\t" => 0, '|' => 0 ];
        $in_quote = false;
        $len = strlen( $erste );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $erste[ $i ];
            if ( $c === '"' ) { $in_quote = ! $in_quote; continue; }
            if ( ! $in_quote && isset( $zaehler[ $c ] ) ) $zaehler[ $c ]++;
        }
        arsort( $zaehler );
        $erster = array_key_first( $zaehler );
        return $zaehler[ $erster ] > 0 ? $erster : ';';
    }

    private static function csv_zeile( $felder ) {
        $out = [];
        foreach ( $felder as $f ) {
            $f = (string) $f;
            $out[] = ( strpbrk( $f, ";\"\n\r" ) !== false )
                ? '"' . str_replace( '"', '""', $f ) . '"' : $f;
        }
        return implode( ';', $out );
    }

    /**
     * Die Zuordnung aus der Kopfzeile raten.
     *
     * Erst der genaue Name eines Feldes, dann sein Kurzname, dann ein
     * Teiltreffer. Jede Spalte wird höchstens einmal vergeben — zwei
     * Spalten auf dasselbe Feld hiesse, dass eine die andere
     * überschreibt, ohne dass jemand es sieht.
     */
    public static function zuordnung_raten( $kopf, $art ) {
        $norm = function ( $s ) {
            $s = strtolower( trim( (string) $s ) );
            $s = strtr( $s, [ 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ] );
            return trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
        };
        $kandidaten = [];
        foreach ( $art['spalten'] as $feld => $def ) {
            $worte = [ $norm( $feld ), $norm( $def['label'] ) ];
            // Der Teil vor der Klammer: „Attest gültig bis (…)" → „attest gueltig bis"
            $ohne = preg_replace( '/\(.*$/', '', $def['label'] );
            $worte[] = $norm( $ohne );
            $kandidaten[ $feld ] = array_values( array_unique( array_filter( $worte ) ) );
        }

        $zu = array_fill( 0, count( $kopf ), '' );
        $vergeben = [];
        // Runde 1: genaue Treffer
        foreach ( $kopf as $i => $name ) {
            $n = $norm( $name );
            if ( $n === '' ) continue;
            foreach ( $kandidaten as $feld => $worte ) {
                if ( isset( $vergeben[ $feld ] ) ) continue;
                if ( in_array( $n, $worte, true ) ) {
                    $zu[ $i ] = $feld; $vergeben[ $feld ] = true; break;
                }
            }
        }
        // Runde 2: Teiltreffer
        foreach ( $kopf as $i => $name ) {
            if ( $zu[ $i ] !== '' ) continue;
            $n = $norm( $name );
            if ( $n === '' ) continue;
            foreach ( $kandidaten as $feld => $worte ) {
                if ( isset( $vergeben[ $feld ] ) ) continue;
                foreach ( $worte as $w ) {
                    if ( $w !== '' && ( strpos( $n, $w ) !== false || strpos( $w, $n ) !== false ) ) {
                        $zu[ $i ] = $feld; $vergeben[ $feld ] = true; break 2;
                    }
                }
            }
        }
        return $zu;
    }
}
