<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Home
 * ----------------
 * Liefert die Daten der Startseite:
 *  - lsv07i_home_widgets_get / _save  Schnellzugriffe des Nutzers
 *  - lsv07i_home_team_stats           letzte 5 Trainings einer Gruppe
 *  - lsv07i_home_top_swimmers         Top 5 nach Bestzeiten
 *  - lsv07i_home_uebersicht           Abrechnung, Wettkampf, Training
 *
 * Die Werte sind rollenabhängig — ein normaler Trainer sieht nur
 * seine eigenen Gruppen/Daten, Admin bzw. Wart sehen alles.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Home {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_home_widgets_get', [ __CLASS__, 'widgets_get' ] );
        add_action( 'wp_ajax_lsv07i_home_widgets_save', [ __CLASS__, 'widgets_save' ] );
        add_action( 'wp_ajax_lsv07i_home_team_stats',    [ __CLASS__, 'team_stats' ] );
        add_action( 'wp_ajax_lsv07i_home_top_swimmers',  [ __CLASS__, 'top_swimmers' ] );
        add_action( 'wp_ajax_lsv07i_home_uebersicht',    [ __CLASS__, 'uebersicht' ] );
    }

    /**
     * Der Katalog der Schnellzugriffe: EIN Eintrag je Ansicht, die es im
     * internen Bereich gibt.
     *
     * Ab 9.4.0 ist das vollständig. Vorher standen hier 13 von über 40
     * Ansichten — wer etwa nur die Atteste oder den Trainingsplan
     * betreute, konnte sich dafür keinen Schnellzugriff legen, obwohl er
     * das Recht dazu hatte. Das war keine Entscheidung, sondern eine
     * Liste, die nicht mitgewachsen ist.
     *
     * `recht` sagt, wer den Eintrag wählen darf, und ist EIN Schlüssel
     * aus der Zugriffskarte (LSV07I_Access::get_access_map()):
     *
     *   • ein Reiterschlüssel aus `tabs`  — z.B. 'sw_bz', 'adm_log'.
     *     Genau derselbe Schlüssel, an dem auch der Reiter hängt. Er
     *     ist wahr, sobald jemand das Lese- ODER das Bearbeitenrecht
     *     des Moduls hat; die Karte rechnet das aus, nicht diese Liste.
     *   • ein Flagge daneben — 'is_admin', 'trainer',
     *     'is_schwimmwart', 'is_finanzwart', 'zugang'.
     *
     * Damit kann der Katalog nicht auseinanderlaufen: Ein Reiter, der
     * sichtbar ist, hat seinen Schnellzugriff, und ein Schnellzugriff
     * ohne sichtbaren Reiter gibt es nicht. Ein neues Modul braucht
     * hier eine Zeile — und die Prüfung meldet, wenn sie fehlt.
     *
     * `jump` ist der Bereich, `tab` das Panel darin (leer = der Bereich
     * selbst). `bereich` ist nur die Farbe der Kachel.
     */
    const WIDGETS = [
        // ── SCHWIMMEN ────────────────────────────────────────────────
        [ 'id'=>'s.mb','icon'=>'buch',     'bereich'=>'s','jump'=>'s','tab'=>'i-p-mb',         'recht'=>'sw_mb',
          'label'=>'Mannschaftsbuch',       'kurz'=>'Buch',          'sub'=>'Der Trainingstag' ],
        [ 'id'=>'s.anw','icon'=>'haken',    'bereich'=>'s','jump'=>'s','tab'=>'i-p-anw',        'recht'=>'sw_anw',
          'label'=>'Anwesenheit',           'kurz'=>'Anwesenheit',   'sub'=>'Wettkampf & Ausfälle' ],
        [ 'id'=>'s.mann','icon'=>'gruppe',   'bereich'=>'s','jump'=>'s','tab'=>'i-p-mann',       'recht'=>'sw_mann',
          'label'=>'Mannschaftsverwaltung', 'kurz'=>'Mannschaften',  'sub'=>'Schwimmer & Trainer' ],
        [ 'id'=>'s.attest','icon'=>'attest', 'bereich'=>'s','jump'=>'s','tab'=>'i-p-attest',     'recht'=>'sw_attest',
          'label'=>'Atteste',               'kurz'=>'Atteste',       'sub'=>'Ablauf im Blick' ],
        [ 'id'=>'s.bz','icon'=>'stoppuhr',     'bereich'=>'s','jump'=>'s','tab'=>'i-p-bz',         'recht'=>'sw_bz',
          'label'=>'Bestzeiten',            'kurz'=>'Bestzeiten',    'sub'=>'Zeiten einlesen' ],
        [ 'id'=>'s.refl','icon'=>'blatt',   'bereich'=>'s','jump'=>'s','tab'=>'i-p-refl',       'recht'=>'sw_refl',
          'label'=>'Reflexionsbögen',       'kurz'=>'Reflexion',     'sub'=>'Saison-Reflexion' ],
        [ 'id'=>'s.akte','icon'=>'ausweis',   'bereich'=>'s','jump'=>'s','tab'=>'i-p-akte',       'recht'=>'sw_akte',
          'label'=>'Akte',                  'kurz'=>'Akte',          'sub'=>'Alles zu einer Person' ],
        [ 'id'=>'s.trainer','icon'=>'person','bereich'=>'s','jump'=>'s','tab'=>'i-p-sw-trainer', 'recht'=>'sw_trainer',
          'label'=>'Trainerübersicht',      'kurz'=>'Trainer',       'sub'=>'Wer betreut was' ],
        [ 'id'=>'s.wk','icon'=>'pokal',     'bereich'=>'s','jump'=>'s','tab'=>'i-p-wk',         'recht'=>'sw_wk',
          'label'=>'Wettkämpfe',            'kurz'=>'Wettkämpfe',    'sub'=>'Termine & Ergebnisse' ],
        [ 'id'=>'s.meld','icon'=>'blatt',   'bereich'=>'s','jump'=>'s','tab'=>'i-p-meld',       'recht'=>'sw_meld',
          'label'=>'Meldungen',             'kurz'=>'Meldungen',     'sub'=>'Wettkampfmeldungen' ],
        [ 'id'=>'s.spr','icon'=>'tausch',    'bereich'=>'s','jump'=>'s','tab'=>'i-p-spr',        'recht'=>'sw_spr',
          'label'=>'Springer',              'kurz'=>'Springer',      'sub'=>'Vertretungen finden' ],
        [ 'id'=>'s.tp','icon'=>'kalender',     'bereich'=>'s','jump'=>'s','tab'=>'i-p-tp',         'recht'=>'sw_tp',
          'label'=>'Trainingsplan',         'kurz'=>'Plan',          'sub'=>'Pläne und Vorlagen' ],

        // ── TRIATHLON ────────────────────────────────────────────────
        [ 'id'=>'tri.mann','icon'=>'gruppe',   'bereich'=>'tri','jump'=>'tri','tab'=>'i-p-tri-mann',       'recht'=>'tri_mann',
          'label'=>'Gruppen & Sportler',  'kurz'=>'Gruppen',     'sub'=>'Triathlon verwalten' ],
        [ 'id'=>'tri.anw','icon'=>'haken',    'bereich'=>'tri','jump'=>'tri','tab'=>'i-p-tri-anw',        'recht'=>'tri_anw',
          'label'=>'Anwesenheit (Tri)',   'kurz'=>'Anwesenheit', 'sub'=>'Training erfassen' ],
        [ 'id'=>'tri.trainer','icon'=>'person','bereich'=>'tri','jump'=>'tri','tab'=>'i-p-tri-trainer-ub', 'recht'=>'tri_trainer',
          'label'=>'Trainer (Tri)',       'kurz'=>'Trainer',     'sub'=>'Wer betreut was' ],

        // ── FITNESS ──────────────────────────────────────────────────
        [ 'id'=>'fit.mann','icon'=>'gruppe',   'bereich'=>'fit','jump'=>'fit','tab'=>'i-p-fit-mann',       'recht'=>'fit_mann',
          'label'=>'Gruppen & Sportler',    'kurz'=>'Gruppen',     'sub'=>'Fitness verwalten' ],
        [ 'id'=>'fit.anw','icon'=>'haken',    'bereich'=>'fit','jump'=>'fit','tab'=>'i-p-fit-anw',        'recht'=>'fit_anw',
          'label'=>'Anwesenheit (Fitness)', 'kurz'=>'Anwesenheit', 'sub'=>'Training erfassen' ],
        [ 'id'=>'fit.trainer','icon'=>'person','bereich'=>'fit','jump'=>'fit','tab'=>'i-p-fit-trainer-ub', 'recht'=>'fit_trainer',
          'label'=>'Trainer (Fitness)',     'kurz'=>'Trainer',     'sub'=>'Wer betreut was' ],

        /* ── ABRECHNUNG ──────────────────────────────────────────────
           Ein Bereich seit 9.5.0; die Reiter stammen aus den früheren
           Bereichen „Trainer" und „Verwaltung". `weg` sagt, zu welchem
           Abrechnungsweg ein Schnellzugriff gehört — die Reiter zu
           „intern", der Verweis zu „link". Welcher gilt, fragt der
           Filter unten; diese Zeilen entscheiden es nicht. */
        [ 'id'=>'abr','icon'=>'geld',             'bereich'=>'abr','jump'=>'abr','tab'=>'i-p-tr-abr',      'recht'=>'trainer',        'weg'=>'abr-intern',
          'label'=>'Meine Abrechnung',          'kurz'=>'Abrechnung',  'sub'=>'Quartal einreichen' ],
        [ 'id'=>'t.sonder','icon'=>'stern',       'bereich'=>'abr','jump'=>'abr','tab'=>'i-p-tr-sonder',   'recht'=>'tr_sonder',      'weg'=>'abr-intern',
          'label'=>'Sonderabrechnung',          'kurz'=>'Sonder',      'sub'=>'Tag und Stunden' ],
        [ 'id'=>'t.sportler','icon'=>'schwimmer', 'bereich'=>'abr','jump'=>'abr','tab'=>'i-p-tr-sportler', 'recht'=>'tr_sportler',    'weg'=>'abr-intern',
          'label'=>'Meine Sportler',            'kurz'=>'Sportler',    'sub'=>'Anlegen und pflegen' ],
        [ 'id'=>'t.sd','icon'=>'ausweis',         'bereich'=>'abr','jump'=>'abr','tab'=>'i-p-tr-sd',       'recht'=>'trainer',        'weg'=>'abr-intern',
          'label'=>'Stammdaten',                'kurz'=>'Stammdaten',  'sub'=>'Für die Abrechnung' ],
        [ 'id'=>'v.abr','icon'=>'haken',          'bereich'=>'abr','jump'=>'abr','tab'=>'i-p-vw-sw',       'recht'=>'is_schwimmwart', 'weg'=>'abr-intern',
          'label'=>'Eingereichte Abrechnungen', 'kurz'=>'Eingereicht', 'sub'=>'Prüfen und genehmigen' ],
        [ 'id'=>'v.kasse','icon'=>'kasse',        'bereich'=>'abr','jump'=>'abr','tab'=>'i-p-vw-kw',       'recht'=>'is_finanzwart',  'weg'=>'abr-intern',
          'label'=>'Kassenwart',                'kurz'=>'Kasse',       'sub'=>'Genehmigt und Jahr' ],
        [ 'id'=>'abr.link','icon'=>'geld',        'bereich'=>'abr','jump'=>'abr','tab'=>'',               'recht'=>'abrechnung',     'weg'=>'abr-link',
          'label'=>'Abrechnung',                'kurz'=>'Abrechnung',  'sub'=>'Im eigenen Plugin' ],

        // ── KOMMUNIKATION ────────────────────────────────────────────
        [ 'id'=>'n.inbox','icon'=>'mail', 'bereich'=>'n', 'jump'=>'n', 'tab'=>'', 'recht'=>'zugang',
          'label'=>'Posteingang', 'kurz'=>'Nachrichten', 'sub'=>'Interne Nachrichten' ],
        [ 'id'=>'tk.liste','icon'=>'ticket','bereich'=>'tk','jump'=>'tk','tab'=>'', 'recht'=>'zugang',
          'label'=>'Tickets',     'kurz'=>'Tickets',     'sub'=>'Anfragen an den Verein' ],

        // ── ADMINISTRATION ───────────────────────────────────────────
        [ 'id'=>'a.import','icon'=>'import',    'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-import',       'recht'=>'is_admin',
          'label'=>'Daten einlesen',      'kurz'=>'Einlesen',    'sub'=>'Alle Datenarten' ],
        [ 'id'=>'a.pers-csv','icon'=>'import',  'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-personen-csv', 'recht'=>'is_admin',
          'label'=>'Personen-Assistent',  'kurz'=>'Assistent',   'sub'=>'Personen mit Konten' ],
        [ 'id'=>'a.import-bz','icon'=>'stoppuhr', 'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-import-bz',    'recht'=>'is_admin',
          'label'=>'Bestzeiten einlesen', 'kurz'=>'Zeiten',      'sub'=>'Weg zu den Bestzeiten' ],
        [ 'id'=>'a.rechte','icon'=>'schild',    'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-rechte',       'recht'=>'adm_rechte',
          'label'=>'Rechteverwaltung',    'kurz'=>'Rechte',      'sub'=>'Wer darf was' ],
        [ 'id'=>'a.saison','icon'=>'kalender',    'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-saison',       'recht'=>'adm_saison',
          'label'=>'Saisons',             'kurz'=>'Saisons',     'sub'=>'Zeiträume des Vereins' ],
        [ 'id'=>'a.mann','icon'=>'schwimmer',      'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-mannschaften', 'recht'=>'is_admin',
          'label'=>'Mannschaften & Gruppen','kurz'=>'Mannschaften','sub'=>'Alle Sparten' ],
        [ 'id'=>'a.zeiten','icon'=>'uhr',    'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-zeiten',       'recht'=>'is_admin',
          'label'=>'Trainingszeiten',     'kurz'=>'Zeiten',      'sub'=>'Wann trainiert wer' ],
        [ 'id'=>'a.stamm','icon'=>'ausweis',     'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-stammdaten',   'recht'=>'is_admin',
          'label'=>'Stammdaten (Admin)',  'kurz'=>'Stammdaten',  'sub'=>'Daten der Trainer' ],
        [ 'id'=>'a.cfg','icon'=>'zahnrad',       'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-cfg',          'recht'=>'is_admin',
          'label'=>'Konfiguration',       'kurz'=>'Konfig',      'sub'=>'Schalter des Plugins' ],
        [ 'id'=>'a.personen','icon'=>'gruppe',  'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-personen-tab', 'recht'=>'is_admin',
          'label'=>'Personen',            'kurz'=>'Personen',    'sub'=>'Über alle Sparten' ],
        [ 'id'=>'a.trainer','icon'=>'person',   'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-trainer',      'recht'=>'is_admin',
          'label'=>'Trainer (Admin)',     'kurz'=>'Trainer',     'sub'=>'Anlegen und zuordnen' ],
        [ 'id'=>'a.sportler','icon'=>'ausweis',  'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-sportler',     'recht'=>'is_admin',
          'label'=>'Sportler (Admin)',    'kurz'=>'Sportler',    'sub'=>'Über alle Sparten' ],
        [ 'id'=>'a.atteste','icon'=>'attest',   'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-atteste',      'recht'=>'adm_atteste',
          'label'=>'Atteste (Übersicht)', 'kurz'=>'Atteste',     'sub'=>'Blick über alle' ],
        [ 'id'=>'a.ben','icon'=>'glocke',       'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-ben',          'recht'=>'is_admin',
          'label'=>'Benachrichtigungen',  'kurz'=>'Nachricht',   'sub'=>'An alle Konten' ],
        [ 'id'=>'a.mail','icon'=>'mail',      'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-mail',         'recht'=>'is_admin',
          'label'=>'Mails',               'kurz'=>'Mails',       'sub'=>'Welche Mail an wen' ],
        [ 'id'=>'a.log','icon'=>'log',       'bereich'=>'a','jump'=>'a','tab'=>'i-p-adm-log',          'recht'=>'adm_log',
          'label'=>'Log',                 'kurz'=>'Log',         'sub'=>'Was ist passiert' ],
    ];

    /**
     * Darf dieses Konto den Eintrag wählen?
     *
     * Beantwortet wird die Frage aus der Zugriffskarte — derselben, aus
     * der die Reiter kommen. Diese Methode entscheidet nichts selbst;
     * sie sucht den Schlüssel.
     */
    private static function darf( $recht, $karte ) {
        $tabs = $karte['tabs'] ?? [];
        /* Ein Reiterschlüssel: wahr, sobald das Modul mit Lese- ODER
           Bearbeitenrecht offen ist. */
        if ( isset( $tabs[ $recht ] ) ) return (bool) $tabs[ $recht ];

        switch ( $recht ) {
            case 'is_admin':       return ! empty( $karte['is_admin'] );
            case 'is_schwimmwart': return ! empty( $karte['is_schwimmwart'] );
            case 'is_finanzwart':  return ! empty( $karte['is_finanzwart'] );
            case 'trainer':        return ! empty( $karte['trainer'] );
            /* Der Verweis-Bereich steht jedem offen, der irgendeine
               Abrechnung sehen darf — genau wie im Template ($cT||$cV). */
            case 'abrechnung':     return ! empty( $karte['trainer'] )
                                       || ! empty( $karte['is_schwimmwart'] )
                                       || ! empty( $karte['is_finanzwart'] );
            case 'zugang':         return LSV07I_Access::has_any_access();
        }
        /* Ein Schlüssel, den die Zugriffskarte nicht kennt, öffnet
           nichts. „Unbekannt" heisst nicht „erlaubt". */
        return false;
    }

    /**
     * Liste der für den aktuellen Nutzer verfügbaren Widgets (Quicklinks),
     * gefiltert auf seine Berechtigung. Pro Bereich gruppiert.
     */
    private static function available_widgets() {
        $karte = LSV07I_Access::get_access_map();
        $out = [];
        foreach ( self::WIDGETS as $w ) {
            if ( ! self::darf( $w['recht'], $karte ) ) continue;
            /* Ein Schnellzugriff auf einen Teil der Abrechnung, den der
               geltende Weg nicht anbietet, führte auf eine Kachel, die
               nichts öffnet: Im Weg „link" gibt es die Reiter nicht, im
               Weg „intern" den Verweis nicht. Dieselbe Frage, dieselbe
               Antwort wie im Bereich selbst — gestellt wird sie nur an
               einer Stelle. */
            if ( isset( $w['weg'] ) && class_exists( 'LSV07I_Abrechnungsweg' )
                 && ! LSV07I_Abrechnungsweg::bereich_offen( $w['weg'] ) ) continue;
            unset( $w['recht'], $w['weg'] );   // gehört nicht in den Browser
            $out[] = $w;
        }
        return $out;
    }

    public static function widgets_get() {
        LSV07I_Access::check( 'intern' );
        $uid = get_current_user_id();
        $aktiv = get_user_meta( $uid, 'lsv07i_home_widgets', true );
        if ( ! is_array( $aktiv ) ) $aktiv = [];
        wp_send_json_success( [
            'available' => self::available_widgets(),
            'active'    => array_values( $aktiv ),
        ] );
    }

    public static function widgets_save() {
        LSV07I_Access::check( 'intern' );
        $uid = get_current_user_id();
        $raw = $_POST['ids'] ?? '[]';
        if ( is_string( $raw ) ) $raw = json_decode( wp_unslash( $raw ), true );
        if ( ! is_array( $raw ) ) $raw = [];

        // Nur erlaubte IDs zulassen (Defense-in-Depth: kein User-Input direkt speichern)
        $valid_ids = array_column( self::available_widgets(), 'id' );
        $clean = array_values( array_intersect( array_map( 'sanitize_text_field', $raw ), $valid_ids ) );

        update_user_meta( $uid, 'lsv07i_home_widgets', $clean );
        wp_send_json_success( [ 'active' => $clean ] );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  KACHELN DER NEUEN STARTSEITE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Alle Gruppen, auf die der Nutzer Zugriff hat — über alle drei Sparten.
     * Admin bzw. der jeweilige Wart sieht alles, ein Trainer nur die eigenen.
     * Jeder Eintrag trägt seine Sparte, damit die Statistik-Abfrage weiß,
     * welche Tabellen sie befragen muss.
     */
    /**
     * Gibt es diese Tabelle? Fehlt sie, wird gar nicht erst danach gefragt:
     * wpdb schreibt bei eingeschaltetem WP_DEBUG einen HTML-Fehler direkt in
     * die Ausgabe, und der läuft der JSON-Antwort voraus. Der Browser kann
     * sie dann nicht mehr lesen und meldet nur noch „Ein Fehler ist
     * aufgetreten." — ohne zu sagen, was los ist.
     */
    private static function tabelle_da( $tabelle ) {
        global $wpdb;
        static $bekannt = [];
        if ( isset( $bekannt[ $tabelle ] ) ) return $bekannt[ $tabelle ];
        $gefunden = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabelle ) );
        $bekannt[ $tabelle ] = ( $gefunden === $tabelle );
        return $bekannt[ $tabelle ];
    }

    /**
     * Eine Abfrage stellen, ohne dass ein Datenbankfehler die ganze Antwort
     * zerlegt: Fehlt die Tabelle, kommt eine leere Liste. Scheitert die
     * Abfrage trotzdem (etwa weil eine Spalte fehlt), wird der Ersatztext
     * versucht — und der Fehler in $GLOBALS gemerkt, damit die Kachel
     * hinterher sagen kann, warum sie leer ist.
     */
    private static function sicher_abfragen( $tabellen, $sql, $ersatz_sql = '' ) {
        global $wpdb;
        foreach ( (array) $tabellen as $t ) {
            if ( ! self::tabelle_da( $t ) ) {
                $GLOBALS['lsv07i_home_fehler'][] = 'Die Tabelle ' . $t . ' fehlt.';
                return [];
            }
        }
        $still = $wpdb->hide_errors();          // kein HTML vor der JSON-Antwort
        $rows  = $wpdb->get_results( $sql, ARRAY_A );
        $fehler = $wpdb->last_error;
        if ( $fehler && $ersatz_sql ) {
            $wpdb->last_error = '';
            $rows   = $wpdb->get_results( $ersatz_sql, ARRAY_A );
            $fehler = $wpdb->last_error;
        }
        if ( $still ) $wpdb->show_errors();
        if ( $fehler ) $GLOBALS['lsv07i_home_fehler'][] = $fehler;
        return (array) $rows;
    }

    private static function eigene_gruppen() {
        global $wpdb;
        $p   = $wpdb->prefix;
        $out = [];

        // ── Schwimmen ────────────────────────────────────────────────────
        if ( LSV07I_Access::is_intern() ) {
            /* sieht_alle_mannschaften() statt nur Admin/Schwimmwart: Wer das
               Recht "alle Mannschaftsdaten sehen" hat, bekommt sie auch hier
               zur Auswahl. Vorher blieb die Liste fuer ihn leer — er war weder
               Admin noch Wart und hatte womoeglich kein Trainer-Profil. Dann
               verschwand die ganze Kachel, und es gab keine Mannschaft zum
               Auswaehlen. */
            $alle = method_exists( 'LSV07I_Access', 'sieht_alle_mannschaften' )
                ? LSV07I_Access::sieht_alle_mannschaften()
                : ( LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart() );
            $tid  = (int) LSV07I_Access::get_trainer_id();
            $rows = [];
            /* Ersatztext ohne sort_order: Die Mannschaftstabelle gehört nicht
               diesem Plugin. Fehlt dort die Sortierspalte, sollen die
               Mannschaften trotzdem kommen — nach Namen sortiert statt gar
               nicht. Vorher blieb die Auswahl in so einem Fall leer. */
            if ( $alle ) {
                $rows = self::sicher_abfragen(
                    [ $p . 'lsv07_gruppen' ],
                    "SELECT id, name FROM {$p}lsv07_gruppen ORDER BY sort_order ASC, name ASC",
                    "SELECT id, name FROM {$p}lsv07_gruppen ORDER BY name ASC" );
            } elseif ( $tid ) {
                $rows = self::sicher_abfragen(
                    [ $p . 'lsv07_gruppen', $p . 'lsv07i_trainer_mannschaft' ],
                    $wpdb->prepare(
                        "SELECT g.id, g.name FROM {$p}lsv07_gruppen g
                           JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.mannschaft_id = g.id
                          WHERE tm.trainer_id = %d
                       ORDER BY g.sort_order ASC, g.name ASC", $tid ),
                    $wpdb->prepare(
                        "SELECT g.id, g.name FROM {$p}lsv07_gruppen g
                           JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.mannschaft_id = g.id
                          WHERE tm.trainer_id = %d
                       ORDER BY g.name ASC", $tid ) );
            }
            foreach ( (array) $rows as $r ) {
                $out[] = [ 'sparte' => 'schwimmen', 'id' => (int) $r['id'], 'name' => $r['name'] ];
            }
        }

        // ── Triathlon ────────────────────────────────────────────────────
        if ( LSV07I_Access::is_tri_intern() ) {
            $alle = LSV07I_Access::is_admin() || LSV07I_Access::is_triathlonwart();
            $tid  = (int) LSV07I_Access::get_tri_trainer_id();
            $rows = [];
            if ( $alle ) {
                $rows = self::sicher_abfragen( [ $p . 'lsv07i_tri_gruppen' ],
                    "SELECT id, name FROM {$p}lsv07i_tri_gruppen ORDER BY name ASC" );
            } elseif ( $tid ) {
                $rows = self::sicher_abfragen(
                    [ $p . 'lsv07i_tri_gruppen', $p . 'lsv07i_tri_trainer_gruppe' ],
                    $wpdb->prepare(
                        "SELECT g.id, g.name FROM {$p}lsv07i_tri_gruppen g
                           JOIN {$p}lsv07i_tri_trainer_gruppe tg ON tg.gruppe_id = g.id
                          WHERE tg.trainer_id = %d ORDER BY g.name ASC", $tid ) );
            }
            foreach ( (array) $rows as $r ) {
                $out[] = [ 'sparte' => 'triathlon', 'id' => (int) $r['id'], 'name' => $r['name'] ];
            }
        }

        // ── Fitness ──────────────────────────────────────────────────────
        if ( LSV07I_Access::is_fit_intern() ) {
            $alle = LSV07I_Access::is_admin() || LSV07I_Access::is_fitnesswart();
            $tid  = (int) LSV07I_Access::get_fit_trainer_id();
            $rows = [];
            if ( $alle ) {
                $rows = self::sicher_abfragen( [ $p . 'lsv07i_fit_gruppen' ],
                    "SELECT id, name FROM {$p}lsv07i_fit_gruppen ORDER BY name ASC" );
            } elseif ( $tid ) {
                $rows = self::sicher_abfragen(
                    [ $p . 'lsv07i_fit_gruppen', $p . 'lsv07i_fit_trainer_gruppe' ],
                    $wpdb->prepare(
                        "SELECT g.id, g.name FROM {$p}lsv07i_fit_gruppen g
                           JOIN {$p}lsv07i_fit_trainer_gruppe tg ON tg.gruppe_id = g.id
                          WHERE tg.trainer_id = %d ORDER BY g.name ASC", $tid ) );
            }
            foreach ( (array) $rows as $r ) {
                $out[] = [ 'sparte' => 'fitness', 'id' => (int) $r['id'], 'name' => $r['name'] ];
            }
        }

        return $out;
    }

    /** Nur die Schwimm-Mannschaften (für Bestzeiten-Auswertung). */
    private static function eigene_mannschaften() {
        return array_values( array_filter( self::eigene_gruppen(),
            fn( $g ) => $g['sparte'] === 'schwimmen' ) );
    }

    /**
     * Die letzten 5 Trainings einer Gruppe mit Anwesend/Gesamt je Termin.
     * Liefert zusätzlich die Gruppenliste für den Umschalter in der Kachel.
     */
    public static function team_stats() {
        LSV07I_Access::check( 'intern' );
        /* Ein unerwarteter Fehler soll eine lesbare Meldung ergeben und keine
           abgebrochene Antwort: Ohne gültiges JSON zeigt die Kachel nur „Ein
           Fehler ist aufgetreten." — und niemand weiß, woran es lag. */
        try {
            $daten = self::team_stats_inner();
        } catch ( Throwable $e ) {
            error_log( 'LSV07I Startseite: ' . $e->getMessage() );
            wp_send_json_error( [ 'message' => LSV07I_Access::is_admin()
                ? 'Die Trainings konnten nicht geladen werden: ' . $e->getMessage()
                : 'Die Trainings konnten nicht geladen werden.' ] );
        }
        // Gesendet wird ausserhalb des try-Blocks: wp_send_json_* beendet die
        // Anfrage, und dieses Beenden soll nicht als Fehler gelten.
        wp_send_json_success( $daten );
    }

    /** Stellt die Daten der Kachel zusammen und gibt sie zurueck. */
    private static function team_stats_inner() {
        global $wpdb;
        $p = $wpdb->prefix;

        $GLOBALS['lsv07i_home_fehler'] = [];

        /* Die Mannschaftsliste wird zuerst geholt und danach IMMER
           mitgeschickt — auch wenn die Trainingszahlen scheitern. Sonst
           reisst ein Fehler in der Statistik die Auswahl mit, und man kommt
           an keine andere Mannschaft mehr heran. Genau das war der Fall:
           „Ein Fehler ist aufgetreten" und eine leere Auswahl. */
        $gruppen = self::eigene_gruppen();
        if ( empty( $gruppen ) ) {
            return [
                'gruppen' => [], 'auswahl' => '', 'trainings' => [],
                'fehler'  => self::fehlertext(),
            ];
        }

        // Auswahl kommt als "sparte:id" — ohne Angabe die zuletzt gemerkte
        $wunsch = sanitize_text_field( (string) ( $_POST['auswahl'] ?? '' ) );
        if ( $wunsch === '' ) {
            $wunsch = (string) get_user_meta( get_current_user_id(), 'lsv07i_home_gruppe', true );
        }
        $treffer = null;
        foreach ( $gruppen as $g ) {
            if ( $g['sparte'] . ':' . $g['id'] === $wunsch ) { $treffer = $g; break; }
        }
        if ( ! $treffer ) {
            $treffer = $gruppen[0];
        } else {
            update_user_meta( get_current_user_id(), 'lsv07i_home_gruppe', $wunsch );
        }

        // Je Sparte die passenden Tabellen
        $karte = [
            'schwimmen' => [ "{$p}lsv07i_anwesenheit",     "{$p}lsv07i_anwesenheit_eintraege",     'mannschaft_id', 'schwimmer' ],
            'triathlon' => [ "{$p}lsv07i_tri_anwesenheit", "{$p}lsv07i_tri_anwesenheit_eintraege", 'gruppe_id',     'sportler'  ],
            'fitness'   => [ "{$p}lsv07i_fit_anwesenheit", "{$p}lsv07i_fit_anwesenheit_eintraege", 'gruppe_id',     'sportler'  ],
        ];
        $rows = [];
        if ( isset( $karte[ $treffer['sparte'] ] ) ) {
            [ $tbl_anw, $tbl_eint, $spalte, $typ ] = $karte[ $treffer['sparte'] ];
            $rows = self::sicher_abfragen( [ $tbl_anw, $tbl_eint ], $wpdb->prepare(
                "SELECT a.id, a.training_datum, a.ausgefallen,
                        (SELECT COUNT(*) FROM $tbl_eint e
                          WHERE e.anwesenheit_id = a.id AND e.teilnehmer_typ = %s
                            AND e.status = 'anwesend') AS anwesend,
                        (SELECT COUNT(*) FROM $tbl_eint e
                          WHERE e.anwesenheit_id = a.id AND e.teilnehmer_typ = %s) AS gesamt
                   FROM $tbl_anw a
                  WHERE a.$spalte = %d
               ORDER BY a.training_datum DESC, a.id DESC
                  LIMIT 5", $typ, $typ, $treffer['id'] ) );
        }

        // Älteste zuerst, damit die Balken zeitlich von links nach rechts laufen
        $trainings = array_reverse( (array) $rows );
        foreach ( $trainings as &$t ) {
            $t['id']          = (int) $t['id'];
            $t['anwesend']    = (int) $t['anwesend'];
            $t['gesamt']      = (int) $t['gesamt'];
            $t['ausgefallen'] = (int) $t['ausgefallen'];
            $t['quote']       = $t['gesamt'] > 0 ? (int) round( $t['anwesend'] / $t['gesamt'] * 100 ) : 0;
        }
        unset( $t );

        $liste = array_map( fn( $g ) => [
            'wert'   => $g['sparte'] . ':' . $g['id'],
            'name'   => $g['name'],
            'sparte' => $g['sparte'],
        ], $gruppen );

        return [
            'gruppen'   => $liste,
            'auswahl'   => $treffer['sparte'] . ':' . $treffer['id'],
            'trainings' => array_values( $trainings ),
            'fehler'    => self::fehlertext(),
        ];
    }

    /**
     * Was ist beim Zusammensuchen schiefgegangen? Für alle in verständlichen
     * Worten, für Administratoren zusätzlich der technische Grund — nur so
     * lässt sich am Ende sagen, welche Tabelle oder Spalte fehlt. Ohne Befund
     * kommt ein leerer Text, dann zeigt die Kachel nichts dergleichen an.
     */
    private static function fehlertext() {
        $liste = array_values( array_unique( (array) ( $GLOBALS['lsv07i_home_fehler'] ?? [] ) ) );
        if ( ! $liste ) return '';
        foreach ( $liste as $z ) { error_log( 'LSV07I Startseite: ' . $z ); }
        if ( LSV07I_Access::is_admin() ) {
            return 'Die Trainingszahlen sind unvollständig: ' . implode( ' ', $liste );
        }
        return 'Die Trainingszahlen sind gerade unvollständig. Bitte einem Administrator Bescheid geben.';
    }

    /**
     * Die 5 besten Schwimmer nach Bestzeiten. Gewertet wird, wie oft ein
     * Schwimmer innerhalb seiner Mannschaft auf einer Strecke vorne liegt:
     * ein 1. Platz zählt 3 Punkte, ein 2. Platz 2, ein 3. Platz 1.
     * So entsteht aus den streckenweisen Zeiten eine Gesamtrangliste.
     */
    public static function top_swimmers() {
        LSV07I_Access::check( 'schwimmen_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaften = self::eigene_mannschaften();
        if ( empty( $mannschaften ) ) wp_send_json_success( [ 'swimmer' => [] ] );
        $mids = implode( ',', array_map( fn( $m ) => (int) $m['id'], $mannschaften ) );

        // Alle Bestzeiten der erlaubten Mannschaften, je Strecke aufsteigend
        $rows = $wpdb->get_results(
            "SELECT b.swimmer_id, b.swimmer_name, b.mannschaft_id, b.strecke, b.zeit_sek,
                    g.name AS mannschaft_name
               FROM {$p}lsv07i_bestzeiten b
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = b.mannschaft_id
              WHERE b.mannschaft_id IN ($mids) AND b.zeit_sek > 0
           ORDER BY b.mannschaft_id ASC, b.strecke ASC, b.zeit_sek ASC", ARRAY_A );
        if ( empty( $rows ) ) wp_send_json_success( [ 'swimmer' => [] ] );

        // Je Mannschaft und Strecke die ersten drei Plätze werten
        $punkte_je_platz = [ 3, 2, 1 ];
        $wertung = [];
        $aktuell = '';
        $platz   = 0;
        foreach ( $rows as $r ) {
            $gruppe = $r['mannschaft_id'] . '|' . $r['strecke'];
            if ( $gruppe !== $aktuell ) { $aktuell = $gruppe; $platz = 0; }
            if ( $platz >= 3 ) continue;

            $key = $r['swimmer_id'] > 0 ? 's' . (int) $r['swimmer_id'] : 'n' . $r['swimmer_name'];
            if ( ! isset( $wertung[ $key ] ) ) {
                $wertung[ $key ] = [
                    'swimmer_id'      => (int) $r['swimmer_id'],
                    'name'            => $r['swimmer_name'],
                    'mannschaft_name' => $r['mannschaft_name'] ?: '',
                    'punkte'          => 0,
                    'erste_plaetze'   => 0,
                    'strecken'        => 0,
                ];
            }
            $wertung[ $key ]['punkte']   += $punkte_je_platz[ $platz ];
            $wertung[ $key ]['strecken'] += 1;
            if ( $platz === 0 ) $wertung[ $key ]['erste_plaetze'] += 1;
            $platz++;
        }

        $liste = array_values( $wertung );
        usort( $liste, function( $a, $b ) {
            if ( $b['punkte'] !== $a['punkte'] ) return $b['punkte'] <=> $a['punkte'];
            if ( $b['erste_plaetze'] !== $a['erste_plaetze'] ) return $b['erste_plaetze'] <=> $a['erste_plaetze'];
            return strcasecmp( $a['name'], $b['name'] );
        } );

        wp_send_json_success( [ 'swimmer' => array_slice( $liste, 0, 5 ) ] );
    }

    /**
     * Kompakte Infos für die drei kleinen Kacheln unten:
     * eigener Abrechnungsbetrag im laufenden Quartal, nächster Wettkampf,
     * nächstes Training.
     */
    public static function uebersicht() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p     = $wpdb->prefix;
        $heute = current_time( 'Y-m-d' );

        // ── Abrechnungen des laufenden Quartals über alle Sparten ─────────
        // Ein Trainer kann in mehreren Sparten abrechnen; die Kachel zeigt die
        // Summe. Der Status wird nur gezeigt, wenn alle Abrechnungen denselben
        // haben — sonst wäre die Angabe irreführend.
        $quartal  = 'Q' . (int) ceil( (int) current_time( 'n' ) / 3 );
        $jahr     = (int) current_time( 'Y' );
        $summe    = 0.0;
        $status   = '';
        $anz_abr  = 0;
        $tids = [
            'schwimmen' => (int) LSV07I_Access::get_trainer_id(),
            'triathlon' => (int) LSV07I_Access::get_tri_trainer_id(),
            'fitness'   => (int) LSV07I_Access::get_fit_trainer_id(),
        ];
        $status_set = [];
        foreach ( $tids as $bereich => $tid ) {
            if ( ! $tid ) continue;
            $abr = $wpdb->get_row( $wpdb->prepare(
                "SELECT a.id, a.abr_status,
                        COALESCE((SELECT SUM(t.stunden * sd.stundensatz)
                                    FROM {$p}lsv07i_abr_trainingstage t
                                    JOIN {$p}lsv07i_trainer_stammdaten sd ON sd.trainer_id = a.trainer_id
                                   WHERE t.abrechnung_id = a.id AND t.manuell_geloescht = 0), 0)
                      + COALESCE((SELECT SUM(betrag) FROM {$p}lsv07i_abr_wettkampf
                                   WHERE abrechnung_id = a.id AND manuell_geloescht = 0), 0)
                      + COALESCE((SELECT SUM(betrag) FROM {$p}lsv07i_abr_kilometer
                                   WHERE abrechnung_id = a.id), 0) AS summe
                   FROM {$p}lsv07i_abrechnung a
                  WHERE a.trainer_id = %d AND a.bereich = %s
                    AND a.quartal = %s AND a.jahr = %d
               ORDER BY a.id DESC LIMIT 1",
                $tid, $bereich, $quartal, $jahr ), ARRAY_A );
            if ( ! $abr ) continue;
            $summe += (float) $abr['summe'];
            $status_set[ (string) $abr['abr_status'] ] = true;
            $anz_abr++;
        }
        if ( count( $status_set ) === 1 ) $status = (string) array_key_first( $status_set );

        // ── Nächster Wettkampf ────────────────────────────────────────────
        $next_wk    = null;
        $sieht_alle = LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart();
        if ( LSV07I_Access::is_intern() ) {
            if ( $sieht_alle ) {
                $next_wk = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, name, datum_von, datum_bis, ort FROM {$p}lsv07i_wettkampf
                      WHERE datum_bis >= %s ORDER BY datum_von ASC LIMIT 1", $heute ), ARRAY_A );
            } elseif ( $tid ) {
                $next_wk = $wpdb->get_row( $wpdb->prepare(
                    "SELECT DISTINCT w.id, w.name, w.datum_von, w.datum_bis, w.ort
                       FROM {$p}lsv07i_wettkampf w
                       JOIN {$p}lsv07i_wettkampf_mannschaft wm ON wm.wettkampf_id = w.id
                       JOIN {$p}lsv07i_trainer_mannschaft tm
                            ON tm.mannschaft_id = wm.mannschaft_id AND tm.trainer_id = %d
                      WHERE w.datum_bis >= %s
                   ORDER BY w.datum_von ASC LIMIT 1", $tid, $heute ), ARRAY_A );
            }
        }

        // ── Nächstes Training aus den Wochen-Slots errechnen ──────────────
        // Die Slots beschreiben nur Wochentag + Uhrzeit; daraus wird der
        // nächstgelegene echte Termin gebildet — über alle Sparten hinweg.
        $next_training = null;
        $gruppen = self::eigene_gruppen();
        if ( ! empty( $gruppen ) ) {
            $nach_sparte = [];
            foreach ( $gruppen as $g ) $nach_sparte[ $g['sparte'] ][] = (int) $g['id'];

            $slot_karte = [
                'schwimmen' => [ "{$p}lsv07i_training_slots", 'mannschaft_id', "{$p}lsv07_gruppen" ],
                'triathlon' => [ "{$p}lsv07i_tri_slots",      'gruppe_id',     "{$p}lsv07i_tri_gruppen" ],
                'fitness'   => [ "{$p}lsv07i_fit_slots",      'gruppe_id',     "{$p}lsv07i_fit_gruppen" ],
            ];

            $jetzt    = strtotime( current_time( 'Y-m-d H:i:s' ) );
            $heute_wt = (int) current_time( 'N' );   // 1 = Montag … 7 = Sonntag
            $bester   = null;

            foreach ( $nach_sparte as $sparte => $ids ) {
                if ( ! isset( $slot_karte[ $sparte ] ) ) continue;
                [ $tbl_slot, $spalte, $tbl_gruppe ] = $slot_karte[ $sparte ];
                $in    = implode( ',', array_map( 'absint', $ids ) );
                $slots = $wpdb->get_results(
                    "SELECT s.wochentag, s.zeit_von, s.zeit_bis, g.name AS gruppe_name
                       FROM $tbl_slot s
                  LEFT JOIN $tbl_gruppe g ON g.id = s.$spalte
                      WHERE s.$spalte IN ($in)", ARRAY_A );

                foreach ( (array) $slots as $sl ) {
                    $wt = (int) $sl['wochentag'];
                    if ( $wt < 1 || $wt > 7 ) continue;
                    $diff = ( $wt - $heute_wt + 7 ) % 7;
                    $ts   = strtotime( current_time( 'Y-m-d' ) . ' +' . $diff . ' days ' . $sl['zeit_von'] );
                    if ( ! $ts ) continue;
                    if ( $ts < $jetzt ) $ts = strtotime( '+7 days', $ts );  // heute schon vorbei
                    if ( $bester === null || $ts < $bester['ts'] ) {
                        $bester = [
                            'ts'       => $ts,
                            'gruppe'   => $sl['gruppe_name'] ?: '',
                            'sparte'   => $sparte,
                            'zeit_von' => substr( (string) $sl['zeit_von'], 0, 5 ),
                            'zeit_bis' => substr( (string) $sl['zeit_bis'], 0, 5 ),
                        ];
                    }
                }
            }
            if ( $bester ) {
                $next_training = [
                    'datum'    => date( 'Y-m-d', $bester['ts'] ),
                    'gruppe'   => $bester['gruppe'],
                    'sparte'   => $bester['sparte'],
                    'zeit_von' => $bester['zeit_von'],
                    'zeit_bis' => $bester['zeit_bis'],
                ];
            }
        }

        wp_send_json_success( [
            'abrechnung' => [
                'summe'    => round( $summe, 2 ),
                'status'   => $status,
                'quartal'  => $quartal,
                'jahr'     => $jahr,
                'vorhanden'=> $anz_abr > 0,
            ],
            'naechster_wettkampf' => $next_wk,
            'naechstes_training'  => $next_training,
        ] );
    }

}
