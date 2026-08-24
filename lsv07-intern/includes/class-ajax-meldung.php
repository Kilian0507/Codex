<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Meldung
 * -------------------
 * Wettkampfmeldungen: pro Wettkampf und Mannschaft eine Meldeliste.
 *
 * Ablauf in der Oberfläche:
 *   1. Kopf     — Wettkampf, Mannschaft, Anzahl Abschnitte, Anzahl
 *                 Wettkampfnummern (lsv07i_meldung)
 *   2. Auswahl  — welche Schwimmer melden und wie oft jeder startet
 *   3. Tabelle  — pro Start eine Zeile mit Abschnitt, Wettkampfnummer,
 *                 Strecke und optionaler Meldezeit (lsv07i_meldung_start)
 *
 * Name und Jahrgang liegen in der Start-Zeile als Kopie. Eine einmal
 * abgegebene Meldung bleibt damit korrekt, auch wenn der Schwimmer später
 * die Mannschaft wechselt oder aus dem System entfernt wird.
 *
 * Rechte: ausschliesslich über die granulare Rechteverwaltung
 * (schwimmen.meldung.*). Es gibt bewusst keinen Fallback auf die
 * Schwimmen-Rolle — wer melden darf, wird ausdrücklich benannt.
 * Administratoren dürfen immer.
 */

class LSV07I_Ajax_Meldung {

    /** Zeitformat der Meldezeit, z.B. 1:02,45 oder 32,10 */
    const ZEIT_MUSTER = '/^\d{0,2}:?\d{1,2}[,.]\d{1,2}$/';

    public static function init() {
        $map = [
            'lsv07i_meld_list'        => 'list_all',
            'lsv07i_meld_get'         => 'get',
            'lsv07i_meld_save_kopf'   => 'save_kopf',
            'lsv07i_meld_save_starts' => 'save_starts',
            'lsv07i_meld_delete'      => 'delete',
            'lsv07i_meld_schwimmer'   => 'schwimmer',
            'lsv07i_meld_basis'       => 'basis',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $methode ] );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Hilfen
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Legt die beiden Tabellen an, falls sie fehlen. Normalerweise erledigt
     * das der Versions-Block beim Plugin-Update; bricht der ab, würde der
     * Bereich sonst dauerhaft mit einem SQL-Fehler antworten. Läuft nur
     * einmal pro Request und nur in den Meldungs-Endpunkten.
     */
    private static function tabellen_sicherstellen() {
        static $geprueft = false;
        if ( $geprueft ) return;
        $geprueft = true;
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_meldung'" ) ) {
            // Tabellen da — nur noch die in 7.65.0 ergänzte Spalte prüfen
            $att = $wpdb->get_results( "SHOW COLUMNS FROM {$p}lsv07i_meldung_start LIKE 'attest_bis'" );
            if ( empty( $att ) ) {
                $wpdb->query( "ALTER TABLE {$p}lsv07i_meldung_start
                               ADD COLUMN attest_bis DATE DEFAULT NULL AFTER meldezeit" );
            }
            // 8.10.0: Staffel-Starts. Eine Staffel hat keinen einzelnen
            // Schwimmer, nur Abschnitt, Wettkampfnummer und eine frei
            // eingetippte Streckenbezeichnung (z. B. "4x50 Freistil") —
            // die passt nicht in die alten 10 Zeichen der Strecken-Spalte.
            $stf = $wpdb->get_results( "SHOW COLUMNS FROM {$p}lsv07i_meldung_start LIKE 'ist_staffel'" );
            if ( empty( $stf ) ) {
                $wpdb->query( "ALTER TABLE {$p}lsv07i_meldung_start
                               ADD COLUMN ist_staffel TINYINT(1) NOT NULL DEFAULT 0 AFTER schwimmer_id" );
            }
            $str = $wpdb->get_row( "SHOW COLUMNS FROM {$p}lsv07i_meldung_start LIKE 'strecke'" );
            if ( $str && stripos( (string) $str->Type, 'varchar(10)' ) !== false ) {
                $wpdb->query( "ALTER TABLE {$p}lsv07i_meldung_start
                               MODIFY COLUMN strecke VARCHAR(60) NOT NULL DEFAULT ''" );
            }
            return;
        }
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_meldung (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            wettkampf_id     INT UNSIGNED NOT NULL,
            mannschaft_id    INT UNSIGNED NOT NULL,
            abschnitte       TINYINT UNSIGNED NOT NULL DEFAULT 1,
            wettkampfnummern SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            angelegt_von     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            angelegt_am      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            geaendert_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wk_mann (wettkampf_id, mannschaft_id),
            KEY idx_mannschaft (mannschaft_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_meldung_start (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            meldung_id      INT UNSIGNED NOT NULL,
            schwimmer_id    INT UNSIGNED NOT NULL DEFAULT 0,
            ist_staffel     TINYINT(1) NOT NULL DEFAULT 0,
            schwimmer_name  VARCHAR(200) NOT NULL DEFAULT '',
            jahrgang        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            abschnitt       TINYINT UNSIGNED NOT NULL DEFAULT 0,
            wettkampf_nr    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            strecke         VARCHAR(60) NOT NULL DEFAULT '',
            meldezeit       VARCHAR(12) NOT NULL DEFAULT '',
            attest_bis      DATE DEFAULT NULL,
            sortierung      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_meldung (meldung_id, sortierung)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    }

    /**
     * Mannschaften, die der angemeldete Nutzer bearbeiten darf.
     * Rückgabe null = alle (Admin/Schwimmwart), sonst Liste von IDs.
     */
    private static function erlaubte_mannschaften() {
        if ( LSV07I_Access::is_admin_raw() || LSV07I_Access::is_schwimmwart() ) return null;
        $tid = (int) LSV07I_Access::get_trainer_id();
        if ( ! $tid ) return [];
        $ids = LSV07I_DB::get_trainer_mannschaften( $tid );
        return array_values( array_filter( array_map( 'intval', (array) $ids ), fn( $i ) => $i > 0 ) );
    }

    /** Prüft, ob der Nutzer diese Mannschaft melden darf. */
    private static function darf_mannschaft( $mannschaft_id ) {
        $erlaubt = self::erlaubte_mannschaften();
        if ( $erlaubt === null ) return true;
        return in_array( (int) $mannschaft_id, $erlaubt, true );
    }

    /** Jahrgang aus einem Geburtsdatum (leer wenn unbekannt). */
    private static function jahrgang( $geburtsdatum ) {
        $jahr = (int) substr( (string) $geburtsdatum, 0, 4 );
        return ( $jahr >= 1900 && $jahr <= 2100 ) ? $jahr : 0;
    }

    /**
     * Rechtefrage ohne Abbruch — anders als LSV07I_Access::check(), das bei
     * fehlendem Recht sofort mit einer Fehlerantwort aussteigt.
     */
    private static function darf( $recht ) {
        if ( LSV07I_Access::is_admin() ) return true;
        return class_exists( 'LSV07I_Permissions' )
            && LSV07I_Permissions::can_current( $recht );
    }

    /** Strecken-Kürzel prüfen — leer erlaubt, sonst muss es die Liste kennen. */
    private static function strecke_pruefen( $kuerzel ) {
        $kuerzel = trim( (string) $kuerzel );
        if ( $kuerzel === '' ) return '';
        $bekannt = class_exists( 'LSV07I_Ajax_Bestzeiten' ) ? LSV07I_Ajax_Bestzeiten::STRECKEN : [];
        return isset( $bekannt[ $kuerzel ] ) ? $kuerzel : '';
    }

    /**
     * Streckenbezeichnung einer Staffel. Anders als bei einem Einzelstart
     * gibt es hier keine feste Auswahlliste — Staffeln werden je nach
     * Ausschreibung sehr unterschiedlich bezeichnet ("4x50 Freistil",
     * "4 x 100 Lagen mixed"). Daher freier Text, nur bereinigt und auf die
     * Spaltenbreite begrenzt.
     */
    private static function staffel_strecke_pruefen( $text ) {
        $text = sanitize_text_field( (string) $text );
        // Mehrfache Leerzeichen zusammenziehen, damit die Liste ruhig wirkt.
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        if ( function_exists( 'mb_substr' ) ) return mb_substr( $text, 0, 60 );
        return substr( $text, 0, 60 );
    }

    /**
     * Meldezeit vereinheitlichen: Punkt wird zu Komma, Leerzeichen fliegen
     * raus. Unpassende Eingaben werden verworfen statt gespeichert.
     */
    private static function zeit_pruefen( $zeit ) {
        $zeit = trim( (string) $zeit );
        if ( $zeit === '' ) return '';
        $zeit = str_replace( [ ' ', '.' ], [ '', ',' ], $zeit );
        return preg_match( self::ZEIT_MUSTER, $zeit ) ? $zeit : '';
    }

    /**
     * Datum aus dem Formular prüfen. Erlaubt ist ein leeres Feld oder ein
     * echtes Datum im Format JJJJ-MM-TT; alles andere wird verworfen, damit
     * kein Unsinn in der DATE-Spalte landet.
     */
    private static function datum_pruefen( $wert ) {
        $wert = trim( (string) $wert );
        if ( $wert === '' || $wert === '0000-00-00' ) return null;
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $t ) ) return null;
        if ( ! checkdate( (int) $t[2], (int) $t[3], (int) $t[1] ) ) return null;
        return $wert;
    }

    /** Meldung inkl. Wettkampf- und Mannschaftsname laden (oder null). */
    private static function kopf_laden( $meldung_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT m.*, w.name AS wettkampf_name, w.ort, w.datum_von, w.datum_bis,
                    g.name AS mannschaft_name
               FROM {$p}lsv07i_meldung m
          LEFT JOIN {$p}lsv07i_wettkampf w ON w.id = m.wettkampf_id
          LEFT JOIN {$p}lsv07_gruppen  g ON g.id = m.mannschaft_id
              WHERE m.id = %d",
            absint( $meldung_id )
        ), ARRAY_A );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Endpunkte
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Auswahllisten für Schritt 1: Wettkämpfe und Mannschaften, jeweils
     * beschränkt auf das, was der Nutzer melden darf.
     */
    public static function basis() {
        LSV07I_Access::check( 'sw_meld_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $erlaubt = self::erlaubte_mannschaften();

        $mannschaften = [];
        foreach ( (array) LSV07I_DB::get_mannschaften() as $g ) {
            if ( $erlaubt !== null && ! in_array( (int) $g['id'], $erlaubt, true ) ) continue;
            $mannschaften[] = [ 'id' => (int) $g['id'], 'name' => $g['name'] ];
        }

        // Wettkämpfe: die letzten zwei Jahre und alles Kommende reichen für
        // die Meldung — ältere Wettkämpfe blähen die Liste nur auf.
        $wettkaempfe = $wpdb->get_results(
            "SELECT id, name, ort, datum_von, datum_bis
               FROM {$p}lsv07i_wettkampf
              WHERE datum_bis >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
           ORDER BY datum_von DESC, id DESC",
            ARRAY_A
        );
        // Teilnehmende Mannschaften je Wettkampf mitgeben, damit die
        // Oberfläche die Mannschaftsauswahl passend vorfiltern kann.
        $zuord = [];
        if ( ! empty( $wettkaempfe ) ) {
            $ids = array_filter( array_map( fn( $w ) => (int) $w['id'], $wettkaempfe ), fn( $i ) => $i > 0 );
            if ( ! empty( $ids ) ) {
                $in = implode( ',', $ids );
                $rows = $wpdb->get_results(
                    "SELECT wettkampf_id, mannschaft_id
                       FROM {$p}lsv07i_wettkampf_mannschaft
                      WHERE wettkampf_id IN ($in)", ARRAY_A );
                foreach ( $rows as $r ) {
                    $zuord[ (int) $r['wettkampf_id'] ][] = (int) $r['mannschaft_id'];
                }
            }
        }
        foreach ( $wettkaempfe as &$w ) {
            $w['id']            = (int) $w['id'];
            $w['mannschaften']  = $zuord[ $w['id'] ] ?? [];
        }
        unset( $w );

        wp_send_json_success( [
            'wettkaempfe'  => $wettkaempfe,
            'mannschaften' => $mannschaften,
            'strecken'     => class_exists( 'LSV07I_Ajax_Bestzeiten' ) ? LSV07I_Ajax_Bestzeiten::STRECKEN : [],
            'darf_edit'    => self::darf( LSV07I_Permissions::SCHWIMMEN_MELDUNG_EDIT ),
            'darf_delete'  => self::darf( LSV07I_Permissions::SCHWIMMEN_MELDUNG_DELETE ),
            'darf_export'  => self::darf( LSV07I_Permissions::SCHWIMMEN_MELDUNG_EXPORT ),
        ] );
    }

    /** Alle Meldelisten, die der Nutzer sehen darf. */
    public static function list_all() {
        LSV07I_Access::check( 'sw_meld_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $erlaubt = self::erlaubte_mannschaften();
        $where   = '1=1';
        if ( $erlaubt !== null ) {
            if ( empty( $erlaubt ) ) { wp_send_json_success( [] ); return; }
            $where .= ' AND m.mannschaft_id IN (' . implode( ',', $erlaubt ) . ')';
        }

        $rows = $wpdb->get_results(
            "SELECT m.id, m.wettkampf_id, m.mannschaft_id, m.abschnitte, m.wettkampfnummern,
                    m.geaendert_am, w.name AS wettkampf_name, w.datum_von, w.datum_bis,
                    g.name AS mannschaft_name,
                    (SELECT COUNT(*) FROM {$p}lsv07i_meldung_start s WHERE s.meldung_id = m.id) AS starts
               FROM {$p}lsv07i_meldung m
          LEFT JOIN {$p}lsv07i_wettkampf w ON w.id = m.wettkampf_id
          LEFT JOIN {$p}lsv07_gruppen  g ON g.id = m.mannschaft_id
              WHERE $where
           ORDER BY w.datum_von DESC, m.id DESC",
            ARRAY_A
        );

        wp_send_json_success( $rows ?: [] );
    }

    /** Eine Meldeliste mit allen Starts. */
    public static function get() {
        LSV07I_Access::check( 'sw_meld_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $id   = absint( $_POST['id'] ?? 0 );
        $kopf = $id ? self::kopf_laden( $id ) : null;
        if ( ! $kopf ) wp_send_json_error( [ 'message' => 'Meldung nicht gefunden.' ] );
        if ( ! self::darf_mannschaft( $kopf['mannschaft_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ] );
        }

        $starts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_meldung_start
              WHERE meldung_id = %d ORDER BY sortierung ASC, id ASC",
            $id
        ), ARRAY_A );

        wp_send_json_success( [ 'kopf' => $kopf, 'starts' => $starts ?: [] ] );
    }

    /**
     * Schritt 1 speichern. Gibt es für Wettkampf × Mannschaft schon eine
     * Meldung, wird sie aktualisiert statt eine zweite anzulegen.
     */
    public static function save_kopf() {
        LSV07I_Access::check( 'sw_meld_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $wettkampf_id  = absint( $_POST['wettkampf_id'] ?? 0 );
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $abschnitte    = max( 1, min( 60, absint( $_POST['abschnitte'] ?? 1 ) ) );
        $nummern       = max( 1, min( 999, absint( $_POST['wettkampfnummern'] ?? 1 ) ) );

        if ( ! $wettkampf_id )  wp_send_json_error( [ 'message' => 'Bitte einen Wettkampf wählen.' ] );
        if ( ! $mannschaft_id ) wp_send_json_error( [ 'message' => 'Bitte eine Mannschaft wählen.' ] );
        if ( ! self::darf_mannschaft( $mannschaft_id ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ] );
        }

        $wk = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$p}lsv07i_wettkampf WHERE id = %d", $wettkampf_id ), ARRAY_A );
        if ( ! $wk ) wp_send_json_error( [ 'message' => 'Wettkampf nicht gefunden.' ] );

        $vorhanden = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_meldung WHERE wettkampf_id = %d AND mannschaft_id = %d",
            $wettkampf_id, $mannschaft_id
        ) );

        if ( $vorhanden ) {
            $ok = $wpdb->update(
                $p . 'lsv07i_meldung',
                [ 'abschnitte' => $abschnitte, 'wettkampfnummern' => $nummern,
                  'geaendert_am' => current_time( 'mysql' ) ],
                [ 'id' => (int) $vorhanden ],
                [ '%d', '%d', '%s' ], [ '%d' ]
            );
            if ( $ok === false ) {
                $fehler = $wpdb->last_error ?: 'Meldung konnte nicht gespeichert werden.';
                wp_send_json_error( [ 'message' => 'Speichern fehlgeschlagen: ' . $fehler ] );
            }
            $id = (int) $vorhanden;
        } else {
            $ok = $wpdb->insert(
                $p . 'lsv07i_meldung',
                [
                    'wettkampf_id'     => $wettkampf_id,
                    'mannschaft_id'    => $mannschaft_id,
                    'abschnitte'       => $abschnitte,
                    'wettkampfnummern' => $nummern,
                    'angelegt_von'     => get_current_user_id(),
                    'angelegt_am'      => current_time( 'mysql' ),
                    'geaendert_am'     => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%d', '%d', '%d', '%s', '%s' ]
            );
            if ( ! $ok ) {
                $fehler = $wpdb->last_error ?: 'Meldung konnte nicht angelegt werden.';
                wp_send_json_error( [ 'message' => 'Anlegen fehlgeschlagen: ' . $fehler ] );
            }
            $id = (int) $wpdb->insert_id;
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( $vorhanden ? 'meldung.update' : 'meldung.create', [
                'bereich'   => 'Schwimmen',
                'ziel_typ'  => 'meldung',
                'ziel_id'   => $id,
                'ziel_name' => $wk['name'],
                'details'   => $abschnitte . ' Abschnitte, ' . $nummern . ' Wettkampfnummern',
            ] );
        }

        wp_send_json_success( [
            'id'    => $id,
            'kopf'  => self::kopf_laden( $id ),
            'neu'   => ! $vorhanden,
        ] );
    }

    /**
     * Schritt 2 + 3 speichern: die komplette Startliste wird ersetzt.
     * Erwartet $_POST['starts'] als JSON-Liste von Objekten mit
     * schwimmer_id, abschnitt, wettkampf_nr, strecke, meldezeit.
     */
    public static function save_starts() {
        LSV07I_Access::check( 'sw_meld_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $meldung_id = absint( $_POST['meldung_id'] ?? 0 );
        $kopf = $meldung_id ? self::kopf_laden( $meldung_id ) : null;
        if ( ! $kopf ) wp_send_json_error( [ 'message' => 'Meldung nicht gefunden.' ] );
        if ( ! self::darf_mannschaft( $kopf['mannschaft_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ] );
        }

        $roh = wp_unslash( $_POST['starts'] ?? '[]' );
        $liste = json_decode( (string) $roh, true );
        if ( ! is_array( $liste ) ) wp_send_json_error( [ 'message' => 'Startliste nicht lesbar.' ] );
        if ( count( $liste ) > 500 ) {
            wp_send_json_error( [ 'message' => 'Mehr als 500 Starts sind nicht vorgesehen.' ] );
        }

        // Namen und Jahrgänge frisch aus der Mannschaft holen, damit nichts
        // aus dem Browser übernommen wird, was dort manipuliert sein könnte.
        $stamm = [];
        foreach ( (array) LSV07I_DB::get_schwimmer( (int) $kopf['mannschaft_id'] ) as $s ) {
            $stamm[ (int) $s['id'] ] = [
                'name'       => trim( $s['first_name'] . ' ' . $s['last_name'] ),
                'jahrgang'   => self::jahrgang( $s['birth_date'] ),
                'attest_bis' => self::datum_pruefen( $s['attest_expires'] ?? '' ),
            ];
        }

        $max_abschnitt = (int) $kopf['abschnitte'];
        $max_nummer    = (int) $kopf['wettkampfnummern'];

        $zeilen = [];
        $sort   = 0;
        foreach ( $liste as $eintrag ) {
            if ( ! is_array( $eintrag ) ) continue;

            // ── Staffel ────────────────────────────────────────────────
            // Kein einzelner Schwimmer, keine Meldezeit, kein Attest —
            // nur Abschnitt, Wettkampfnummer und die frei eingetippte
            // Streckenbezeichnung.
            if ( ! empty( $eintrag['ist_staffel'] ) ) {
                $abschnitt = absint( $eintrag['abschnitt'] ?? 0 );
                if ( $abschnitt > $max_abschnitt ) $abschnitt = 0;
                $nummer = absint( $eintrag['wettkampf_nr'] ?? 0 );
                if ( $nummer > $max_nummer ) $nummer = 0;

                $zeilen[] = [
                    'meldung_id'     => $meldung_id,
                    'schwimmer_id'   => 0,
                    'ist_staffel'    => 1,
                    'schwimmer_name' => '',
                    'jahrgang'       => 0,
                    'abschnitt'      => $abschnitt,
                    'wettkampf_nr'   => $nummer,
                    'strecke'        => self::staffel_strecke_pruefen( $eintrag['strecke'] ?? '' ),
                    'meldezeit'      => '',
                    'attest_bis'     => null,
                    'sortierung'     => $sort++,
                ];
                continue;
            }

            $sid = absint( $eintrag['schwimmer_id'] ?? 0 );
            if ( ! isset( $stamm[ $sid ] ) ) continue;   // fremder Schwimmer: überspringen

            $abschnitt = absint( $eintrag['abschnitt'] ?? 0 );
            if ( $abschnitt > $max_abschnitt ) $abschnitt = 0;
            $nummer = absint( $eintrag['wettkampf_nr'] ?? 0 );
            if ( $nummer > $max_nummer ) $nummer = 0;

            // Attest-Datum: anders als Name und Jahrgang kommt es bewusst aus
            // dem Formular — es darf für diese eine Meldung abweichen. Fehlt
            // es, greifen die Stammdaten. Geschrieben wird nur hier, die
            // Stammdaten des Schwimmers bleiben unverändert.
            $attest = array_key_exists( 'attest_bis', $eintrag )
                ? self::datum_pruefen( $eintrag['attest_bis'] )
                : $stamm[ $sid ]['attest_bis'];

            $zeilen[] = [
                'meldung_id'     => $meldung_id,
                'schwimmer_id'   => $sid,
                'ist_staffel'    => 0,
                'schwimmer_name' => $stamm[ $sid ]['name'],
                'jahrgang'       => $stamm[ $sid ]['jahrgang'],
                'abschnitt'      => $abschnitt,
                'wettkampf_nr'   => $nummer,
                'strecke'        => self::strecke_pruefen( $eintrag['strecke'] ?? '' ),
                'meldezeit'      => self::zeit_pruefen( $eintrag['meldezeit'] ?? '' ),
                'attest_bis'     => $attest,
                'sortierung'     => $sort++,
            ];
        }

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07i_meldung_start WHERE meldung_id = %d", $meldung_id ) );

        foreach ( $zeilen as $z ) {
            $ok = $wpdb->insert( $p . 'lsv07i_meldung_start', $z,
                [ '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ] );
            if ( ! $ok ) {
                $fehler = $wpdb->last_error ?: 'Ein Start konnte nicht gespeichert werden.';
                wp_send_json_error( [ 'message' => 'Speichern fehlgeschlagen: ' . $fehler ] );
            }
        }

        $wpdb->update( $p . 'lsv07i_meldung',
            [ 'geaendert_am' => current_time( 'mysql' ) ],
            [ 'id' => $meldung_id ], [ '%s' ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'meldung.starts', [
                'bereich'   => 'Schwimmen',
                'ziel_typ'  => 'meldung',
                'ziel_id'   => $meldung_id,
                'ziel_name' => $kopf['wettkampf_name'] . ' — ' . $kopf['mannschaft_name'],
                'details'   => count( $zeilen ) . ' Starts gespeichert',
            ] );
        }

        $gespeichert = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_meldung_start
              WHERE meldung_id = %d ORDER BY sortierung ASC, id ASC", $meldung_id ), ARRAY_A );

        wp_send_json_success( [
            'anzahl'  => count( $zeilen ),
            'starts'  => $gespeichert ?: [],
            'message' => count( $zeilen ) === 1
                ? 'Meldung gespeichert — 1 Start.'
                : 'Meldung gespeichert — ' . count( $zeilen ) . ' Starts.',
        ] );
    }

    /** Meldeliste samt Starts löschen. */
    public static function delete() {
        LSV07I_Access::check( 'sw_meld_delete' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $id   = absint( $_POST['id'] ?? 0 );
        $kopf = $id ? self::kopf_laden( $id ) : null;
        if ( ! $kopf ) wp_send_json_error( [ 'message' => 'Meldung nicht gefunden.' ] );
        if ( ! self::darf_mannschaft( $kopf['mannschaft_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ] );
        }

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07i_meldung_start WHERE meldung_id = %d", $id ) );
        $wpdb->delete( $p . 'lsv07i_meldung', [ 'id' => $id ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'meldung.delete', [
                'bereich'   => 'Schwimmen',
                'ziel_typ'  => 'meldung',
                'ziel_id'   => $id,
                'ziel_name' => $kopf['wettkampf_name'] . ' — ' . $kopf['mannschaft_name'],
            ] );
        }

        wp_send_json_success( [ 'message' => 'Meldung gelöscht.' ] );
    }

    /** Schwimmer einer Mannschaft mit Jahrgang — Grundlage für Schritt 2. */
    public static function schwimmer() {
        LSV07I_Access::check( 'sw_meld_read' );
        self::tabellen_sicherstellen();

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! $mannschaft_id ) wp_send_json_error( [ 'message' => 'Keine Mannschaft angegeben.' ] );
        if ( ! self::darf_mannschaft( $mannschaft_id ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ] );
        }

        $out = [];
        foreach ( (array) LSV07I_DB::get_schwimmer( $mannschaft_id ) as $s ) {
            $out[] = [
                'id'         => (int) $s['id'],
                'name'       => trim( $s['first_name'] . ' ' . $s['last_name'] ),
                'nachname'   => $s['last_name'],
                'vorname'    => $s['first_name'],
                'jahrgang'   => self::jahrgang( $s['birth_date'] ),
                // Vorbelegung für die Meldetabelle — dort einzeln änderbar
                'attest_bis' => self::datum_pruefen( $s['attest_expires'] ?? '' ),
            ];
        }

        wp_send_json_success( $out );
    }
}
