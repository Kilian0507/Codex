<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Mannschaftsbuch
 * ---------------------------
 * Das Arbeitsheft einer Mannschaft. Ein Eintrag je Trainingstag mit:
 *
 *   Anwesenheit     sechs Zustände je Sportler (lsv07i_mb_anwesenheit)
 *   Dokumentation   ein Freitext zum Training (lsv07i_mb_tag.dokumentation)
 *   Vermerke        für die ganze Mannschaft oder eine Person
 *   Aufgaben        für eine Person, mit Haken „erledigt"
 *   Bahnenbelegung  welche Person auf welcher Bahn (lsv07i_mb_tag.bahnen)
 *
 * Verhältnis zur bestehenden Anwesenheit
 * --------------------------------------
 * Die Anwesenheit im Reiter „Anwesenheit" bleibt, wie sie ist — mit ihren
 * drei Zuständen. Beide Module arbeiten auf demselben Trainingstag:
 *
 *   • Beim Öffnen eines Tages, für den im Buch noch nichts steht, wird die
 *     dort erfasste Anwesenheit übernommen und als Altbestand ausgewiesen.
 *   • Wird im Buch ein Zustand gesetzt, wandert er zusätzlich in die alte
 *     Tabelle — auf ihre drei Zustände heruntergebrochen. Sonst stünde im
 *     Reiter „Anwesenheit", in der Akte und im Saisonbericht niemand
 *     anwesend, nur weil der Tag im Buch geführt wurde.
 *
 * Die Zuordnung ist festgelegt und steht in NEU_ZU_ALT: krank,
 * entschuldigt und beurlaubt gelten dort als entschuldigt, abwesend und
 * unentschuldigt als abwesend.
 *
 * Rechte: mb_read zum Sehen, mb_edit zum Schreiben — beides mit Rückfall
 * auf die Bereichsrolle, wie bei der Anwesenheit. Zusätzlich und davon
 * unabhängig gilt immer: geschrieben wird nur für Mannschaften, die der
 * Person gehören (Admin und Schwimmwart für alle).
 */

class LSV07I_Ajax_Mannschaftsbuch {

    /** Die sechs Zustände der Anwesenheitsführung. */
    const ZUSTAENDE = [
        'anwesend'       => 'Anwesend',
        'abwesend'       => 'Abwesend',
        'krank'          => 'Krank',
        'entschuldigt'   => 'Entschuldigt',
        'beurlaubt'      => 'Beurlaubt',
        'unentschuldigt' => 'Unentschuldigt',
    ];

    /** Zustand des Buches → Zustand der alten Anwesenheit. */
    const NEU_ZU_ALT = [
        'anwesend'       => 'anwesend',
        'abwesend'       => 'abwesend',
        'krank'          => 'entschuldigt',
        'entschuldigt'   => 'entschuldigt',
        'beurlaubt'      => 'entschuldigt',
        'unentschuldigt' => 'abwesend',
    ];

    /** Zustand der alten Anwesenheit → Zustand des Buches (Altbestand). */
    const ALT_ZU_NEU = [
        'anwesend'     => 'anwesend',
        'abwesend'     => 'abwesend',
        'entschuldigt' => 'entschuldigt',
    ];

    /** Arten eines Vermerks. */
    const VERMERK_ARTEN = [
        'ziel'      => 'Trainingsziel',
        'hinweis'   => 'Hinweis',
        'sonstiges' => 'Sonstiges',
    ];

    /** Arten einer Aufgabe. */
    const AUFGABE_ARTEN = [
        'material'  => 'Sachen nicht mitgebracht',
        'bank'      => 'Auf der Bank gesessen',
        'sonstiges' => 'Sonstiger Vermerk',
    ];

    /** Höchstzahl Bahnen einer Belegung. */
    const BAHNEN_MAX = 12;

    public static function init() {
        $map = [
            'lsv07i_mb_basis'      => 'basis',
            'lsv07i_mb_tag'        => 'tag',
            'lsv07i_mb_status'     => 'status',
            'lsv07i_mb_spaet'      => 'verspaetung',
            'lsv07i_mb_standard'   => 'standard_setzen',
            'lsv07i_mb_doku'       => 'doku',
            'lsv07i_mb_eintrag'    => 'eintrag',
            'lsv07i_mb_eintrag_zu' => 'eintrag_zu',
            'lsv07i_mb_eintrag_del'=> 'eintrag_del',
            'lsv07i_mb_bahnen'     => 'bahnen',
            'lsv07i_mb_bahnen_save'=> 'bahnen_save',
            'lsv07i_mb_statistik'  => 'statistik',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $methode ] );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Tabellen
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Legt die drei Tabellen an, falls sie fehlen. Normalerweise erledigt
     * das der Versions-Block beim Update; bricht der ab, würde der Bereich
     * sonst dauerhaft mit einem SQL-Fehler antworten. Läuft einmal pro
     * Request und nur in den Endpunkten des Buches.
     */
    private static function tabellen_sicherstellen() {
        static $geprueft = false;
        if ( $geprueft ) return;
        $geprueft = true;
        global $wpdb;
        $p = $wpdb->prefix;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_mb_tag'" ) ) {
            self::spalten_nachruesten( $p );
            return;
        }
        foreach ( self::tabellen_sql( $p ) as $sql ) $wpdb->query( $sql );
    }

    /**
     * 8.27.0: Verspätung je Person und Tag. Nachgerüstet, damit ein Buch,
     * das schon geführt wird, nicht neu angelegt werden muss.
     */
    public static function spalten_nachruesten( $p ) {
        global $wpdb;
        $spalten = $wpdb->get_results( "SHOW COLUMNS FROM {$p}lsv07i_mb_anwesenheit" );
        $namen   = wp_list_pluck( $spalten, 'Field' );
        if ( ! in_array( 'verspaetung', $namen, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}lsv07i_mb_anwesenheit
                           ADD COLUMN verspaetung SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status" );
        }
        if ( ! in_array( 'verspaetung_grund', $namen, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}lsv07i_mb_anwesenheit
                           ADD COLUMN verspaetung_grund VARCHAR(200) NOT NULL DEFAULT '' AFTER verspaetung" );
        }
    }

    /**
     * Die CREATE-Texte an einer Stelle — so benutzen der Versions-Block im
     * Plugin-Kopf und die Selbstheilung hier denselben Aufbau und können
     * nicht auseinanderlaufen.
     */
    public static function tabellen_sql( $p ) {
        return [
            // Ein Trainingstag je Mannschaft. anwesenheit_id verweist auf die
            // Session der alten Anwesenheit, sofern es eine gibt.
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_mb_tag (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id  INT UNSIGNED NOT NULL,
                datum          DATE NOT NULL,
                anwesenheit_id INT UNSIGNED NOT NULL DEFAULT 0,
                dokumentation  TEXT NULL,
                bahnen         LONGTEXT NULL,
                angelegt_von   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                angelegt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_mann_datum (mannschaft_id, datum),
                KEY idx_datum (datum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // verspaetung: Minuten, um die jemand zu spät kam (0 = pünktlich).
            // Der Grund ist freiwillig.
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_mb_anwesenheit (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tag_id       INT UNSIGNED NOT NULL,
                schwimmer_id INT UNSIGNED NOT NULL,
                status       VARCHAR(20) NOT NULL DEFAULT 'anwesend',
                verspaetung  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                verspaetung_grund VARCHAR(200) NOT NULL DEFAULT '',
                geaendert_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tag_schwimmer (tag_id, schwimmer_id),
                KEY idx_schwimmer (schwimmer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            // Vermerke und Aufgaben in einer Tabelle: gleiche Form, nur die
            // Spalte art unterscheidet sie. schwimmer_id 0 heisst: gilt für
            // die ganze Mannschaft (nur bei Vermerken).
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_mb_eintrag (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tag_id        INT UNSIGNED NOT NULL,
                mannschaft_id INT UNSIGNED NOT NULL,
                schwimmer_id  INT UNSIGNED NOT NULL DEFAULT 0,
                art           VARCHAR(10) NOT NULL DEFAULT 'vermerk',
                kategorie     VARCHAR(20) NOT NULL DEFAULT 'sonstiges',
                text          TEXT NULL,
                erledigt      TINYINT(1) NOT NULL DEFAULT 0,
                angelegt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                angelegt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tag (tag_id),
                KEY idx_mann (mannschaft_id, schwimmer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Hilfen
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Mannschaften, die der angemeldete Nutzer führen darf.
     * null = alle (Admin/Schwimmwart), sonst Liste von IDs.
     */
    private static function erlaubte_mannschaften() {
        if ( LSV07I_Access::is_admin_raw() || LSV07I_Access::is_schwimmwart() ) return null;
        $tid = (int) LSV07I_Access::get_trainer_id();
        if ( ! $tid ) return [];
        $ids = LSV07I_DB::get_trainer_mannschaften( $tid );
        return array_values( array_filter( array_map( 'intval', (array) $ids ), fn( $i ) => $i > 0 ) );
    }

    private static function darf_mannschaft( $mannschaft_id ) {
        $erlaubt = self::erlaubte_mannschaften();
        if ( $erlaubt === null ) return true;
        return in_array( (int) $mannschaft_id, $erlaubt, true );
    }

    /** Bricht ab, wenn die Mannschaft nicht zur Person gehört. */
    private static function mannschaft_pruefen( $mannschaft_id ) {
        if ( ! $mannschaft_id ) {
            wp_send_json_error( [ 'message' => 'Bitte eine Mannschaft wählen.' ] );
        }
        if ( ! self::darf_mannschaft( $mannschaft_id ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ] );
        }
    }

    /**
     * Frühestes Datum, das die Person sehen darf. Wie im Reiter
     * „Anwesenheit": vergangene Saisons bleiben Administratoren
     * vorbehalten. Ohne Saisonverwaltung gilt keine Grenze.
     */
    private static function fruehestes_datum() {
        if ( LSV07I_Access::is_admin() ) return null;
        if ( ! class_exists( 'LSV07I_Saison' ) ) return null;
        $aktiv = LSV07I_Saison::active();
        if ( ! $aktiv || empty( $aktiv['start_datum'] ) ) return null;
        return $aktiv['start_datum'];
    }

    /** Datum prüfen; leer oder unsinnig gibt null. */
    private static function datum( $wert ) {
        $wert = trim( (string) $wert );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $wert, $t ) ) return null;
        return checkdate( (int) $t[2], (int) $t[3], (int) $t[1] ) ? $wert : null;
    }

    /** Text bereinigen und begrenzen. */
    private static function text( $wert, $max ) {
        $wert = sanitize_textarea_field( (string) wp_unslash( $wert ) );
        if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $wert ) > $max ? mb_substr( $wert, 0, $max ) : $wert;
        }
        return substr( $wert, 0, $max );
    }

    /**
     * Einzeiliger Text — Umbrüche und mehrfache Leerzeichen fallen weg.
     * Für Dinge, die in einer Zeile stehen: der Grund einer Verspätung,
     * der Name einer Bahn.
     */
    private static function text_zeile( $wert, $max ) {
        $wert = sanitize_text_field( (string) wp_unslash( $wert ) );
        $wert = trim( preg_replace( '/\s+/u', ' ', $wert ) );
        if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $wert ) > $max ? mb_substr( $wert, 0, $max ) : $wert;
        }
        return substr( $wert, 0, $max );
    }

    /** Kader einer Mannschaft, Nachname zuerst sortiert. */
    private static function kader( $mannschaft_id ) {
        $out = [];
        foreach ( (array) LSV07I_DB::get_schwimmer( $mannschaft_id ) as $s ) {
            $jahr = (int) substr( (string) ( $s['birth_date'] ?? '' ), 0, 4 );
            $out[] = [
                'id'       => (int) $s['id'],
                'name'     => trim( $s['last_name'] . ', ' . $s['first_name'] ),
                'kurz'     => trim( $s['first_name'] . ' ' . $s['last_name'] ),
                'vorname'  => $s['first_name'],
                'nachname' => $s['last_name'],
                'jahrgang' => ( $jahr >= 1900 && $jahr <= 2100 ) ? $jahr : 0,
            ];
        }
        return $out;
    }

    /**
     * Den Trainingstag holen, bei Bedarf anlegen. Der Tag ist durch
     * Mannschaft und Datum bestimmt — wie im Reiter „Anwesenheit", wo es
     * pro Mannschaft und Tag ein Training gibt.
     */
    private static function tag_holen( $mannschaft_id, $datum, $anlegen = false ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $tag = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_mb_tag WHERE mannschaft_id = %d AND datum = %s",
            $mannschaft_id, $datum
        ), ARRAY_A );
        if ( $tag || ! $anlegen ) return $tag ?: null;

        $wpdb->insert( $p . 'lsv07i_mb_tag', [
            'mannschaft_id'  => $mannschaft_id,
            'datum'          => $datum,
            'anwesenheit_id' => 0,
            'angelegt_von'   => get_current_user_id(),
            'angelegt_am'    => current_time( 'mysql' ),
            'geaendert_am'   => current_time( 'mysql' ),
        ], [ '%d', '%s', '%d', '%d', '%s', '%s' ] );
        if ( ! $wpdb->insert_id ) {
            // Zwei Browser gleichzeitig: der eindeutige Schlüssel hat
            // zugeschlagen, der Tag ist also schon da.
            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_mb_tag WHERE mannschaft_id = %d AND datum = %s",
                $mannschaft_id, $datum
            ), ARRAY_A );
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_mb_tag WHERE id = %d", (int) $wpdb->insert_id ), ARRAY_A );
    }

    private static function tag_beruehrt( $tag_id ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'lsv07i_mb_tag',
            [ 'geaendert_am' => current_time( 'mysql' ) ],
            [ 'id' => (int) $tag_id ], [ '%s' ], [ '%d' ] );
    }

    /**
     * Die Session der alten Anwesenheit zu diesem Trainingstag. Dieselbe
     * Auflösung wie dort: der Slot des Wochentags, sonst irgendeiner der
     * Mannschaft, sonst ein Platzhalter-Slot. $anlegen=false sucht nur.
     */
    private static function alte_session( $mannschaft_id, $datum, $anlegen = false ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_anwesenheit
              WHERE mannschaft_id = %d AND training_datum = %s
           ORDER BY id ASC LIMIT 1",
            $mannschaft_id, $datum
        ), ARRAY_A );
        if ( $session || ! $anlegen ) return $session ?: null;

        $wochentag = (int) date( 'N', strtotime( $datum ) );
        $slot_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_training_slots
              WHERE mannschaft_id = %d AND wochentag = %d ORDER BY zeit_von ASC LIMIT 1",
            $mannschaft_id, $wochentag ) );
        if ( ! $slot_id ) {
            $slot_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_training_slots
                  WHERE mannschaft_id = %d ORDER BY id ASC LIMIT 1", $mannschaft_id ) );
        }
        if ( ! $slot_id ) {
            $wpdb->insert( $p . 'lsv07i_training_slots', [
                'mannschaft_id' => $mannschaft_id,
                'wochentag'     => $wochentag,
                'zeit_von'      => '00:00:00',
                'zeit_bis'      => '00:00:00',
            ], [ '%d', '%d', '%s', '%s' ] );
            $slot_id = (int) $wpdb->insert_id;
        }
        if ( ! $slot_id ) return null;

        return LSV07I_DB::get_or_create_anwesenheit( $slot_id, $datum );
    }

    /**
     * Den Zustand in die alte Anwesenheit zurückschreiben, heruntergebrochen
     * auf ihre drei Zustände. Ohne das stünde dort, in der Akte und im
     * Saisonbericht niemand anwesend, nur weil der Tag im Buch geführt wurde.
     */
    private static function alte_anwesenheit_schreiben( $mannschaft_id, $datum, $schwimmer_id, $status ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $alt = self::NEU_ZU_ALT[ $status ] ?? null;
        if ( ! $alt ) return 0;

        $session = self::alte_session( $mannschaft_id, $datum, true );
        if ( ! $session ) return 0;

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_anwesenheit_eintraege
                (anwesenheit_id, teilnehmer_typ, teilnehmer_id, status)
             VALUES (%d, 'schwimmer', %d, %s)
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            (int) $session['id'], $schwimmer_id, $alt
        ) );
        return (int) $session['id'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Standardmannschaft
    // ═══════════════════════════════════════════════════════════════════════

    /** Name des Nutzerfeldes, in dem die Standardmannschaft liegt. */
    const META_STANDARD = 'lsv07i_standard_mannschaft';

    /**
     * Die Mannschaft, die für diese Person vorausgewählt wird. 0 heisst:
     * keine festgelegt.
     *
     * Geprüft wird bei jedem Lesen, ob die Mannschaft noch zu der Person
     * gehört. Wer eine Mannschaft abgibt, soll sie nicht weiter vorgesetzt
     * bekommen — und wer sie nie hätte sehen dürfen, erst recht nicht.
     */
    public static function standard_mannschaft( $uid = 0 ) {
        $uid = $uid ?: get_current_user_id();
        if ( ! $uid ) return 0;
        $id = (int) get_user_meta( $uid, self::META_STANDARD, true );
        if ( ! $id ) return 0;
        return self::darf_mannschaft( $id ) ? $id : 0;
    }

    /**
     * Standardmannschaft setzen oder löschen (0 = keine). Hängt nur am
     * Leserecht: Es ist eine Einstellung für einen selbst, kein Eingriff
     * ins Buch.
     */
    public static function standard_setzen() {
        LSV07I_Access::check( 'mb_read' );
        self::tabellen_sicherstellen();

        $uid = get_current_user_id();
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Nicht angemeldet.' ] );

        $id = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! $id ) {
            delete_user_meta( $uid, self::META_STANDARD );
            wp_send_json_success( [ 'mannschaft_id' => 0, 'message' => 'Standardmannschaft entfernt.' ] );
        }
        if ( ! self::darf_mannschaft( $id ) ) {
            wp_send_json_error( [ 'message' => 'Für diese Mannschaft fehlt die Berechtigung.' ] );
        }

        $name = '';
        foreach ( (array) LSV07I_DB::get_mannschaften() as $g ) {
            if ( (int) $g['id'] === $id ) { $name = $g['name']; break; }
        }
        if ( $name === '' ) wp_send_json_error( [ 'message' => 'Mannschaft nicht gefunden.' ] );

        update_user_meta( $uid, self::META_STANDARD, $id );
        wp_send_json_success( [
            'mannschaft_id' => $id,
            'name'          => $name,
            'message'       => $name . ' ist jetzt voreingestellt.',
        ] );
    }

    /** Anzeigename aus einer WordPress-Nutzer-ID (leer, wenn unbekannt). */
    private static function person( $uid ) {
        $uid = (int) $uid;
        if ( ! $uid ) return '';
        $u = get_userdata( $uid );
        return $u ? $u->display_name : '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Endpunkte: Grundlagen
    // ═══════════════════════════════════════════════════════════════════════

    /** Auswahllisten, Zustände, Arten und Rechte. */
    public static function basis() {
        LSV07I_Access::check( 'mb_read' );
        self::tabellen_sicherstellen();

        $erlaubt = self::erlaubte_mannschaften();
        $mannschaften = [];
        foreach ( (array) LSV07I_DB::get_mannschaften() as $g ) {
            if ( $erlaubt !== null && ! in_array( (int) $g['id'], $erlaubt, true ) ) continue;
            $mannschaften[] = [ 'id' => (int) $g['id'], 'name' => $g['name'] ];
        }

        wp_send_json_success( [
            'mannschaften'   => $mannschaften,
            'zustaende'      => self::ZUSTAENDE,
            'vermerk_arten'  => self::VERMERK_ARTEN,
            'aufgabe_arten'  => self::AUFGABE_ARTEN,
            'bahnen_max'     => self::BAHNEN_MAX,
            'darf_schreiben' => LSV07I_Access::is_admin()
                                || ( class_exists( 'LSV07I_Permissions' )
                                     && LSV07I_Permissions::can_current( LSV07I_Permissions::MANNSCHAFTSBUCH_EDIT ) )
                                || LSV07I_Access::is_intern(),
            'frueheste'      => self::fruehestes_datum(),
            'standard'       => self::standard_mannschaft(),
        ] );
    }

    /**
     * Einen Trainingstag laden: Kader, Zustände, Vermerke, Aufgaben,
     * Dokumentation. Gibt es im Buch noch keinen Zustand, kommt er aus der
     * alten Anwesenheit und ist als Altbestand gekennzeichnet.
     */
    public static function tag() {
        LSV07I_Access::check( 'mb_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum         = self::datum( $_POST['datum'] ?? '' );
        self::mannschaft_pruefen( $mannschaft_id );
        if ( ! $datum ) wp_send_json_error( [ 'message' => 'Bitte ein Datum wählen.' ] );

        $grenze = self::fruehestes_datum();
        if ( $grenze && $datum < $grenze ) {
            wp_send_json_error( [ 'message' => 'Dieser Tag liegt vor der laufenden Saison und ist nur für Admins einsehbar.' ] );
        }

        $tag    = self::tag_holen( $mannschaft_id, $datum );
        $tag_id = $tag ? (int) $tag['id'] : 0;
        $kader  = self::kader( $mannschaft_id );

        // Zustände aus dem Buch
        $gesetzt = [];
        $spaet   = [];
        if ( $tag_id ) {
            foreach ( (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT schwimmer_id, status, verspaetung, verspaetung_grund
                   FROM {$p}lsv07i_mb_anwesenheit WHERE tag_id = %d",
                $tag_id ), ARRAY_A ) as $r ) {
                $sid = (int) $r['schwimmer_id'];
                $gesetzt[ $sid ] = $r['status'];
                $spaet[ $sid ]   = [
                    'minuten' => (int) $r['verspaetung'],
                    'grund'   => (string) $r['verspaetung_grund'],
                ];
            }
        }

        // Altbestand: was der Reiter „Anwesenheit" für diesen Tag weiss
        $alt     = [];
        $session = self::alte_session( $mannschaft_id, $datum );
        if ( $session ) {
            foreach ( (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT teilnehmer_id, status FROM {$p}lsv07i_anwesenheit_eintraege
                  WHERE anwesenheit_id = %d AND teilnehmer_typ = 'schwimmer'",
                (int) $session['id'] ), ARRAY_A ) as $r ) {
                $neu = self::ALT_ZU_NEU[ $r['status'] ] ?? null;
                if ( $neu ) $alt[ (int) $r['teilnehmer_id'] ] = $neu;
            }
        }

        $anwesenheit = [];
        foreach ( $kader as $s ) {
            $id   = $s['id'];
            $satz = [ 'schwimmer_id' => $id, 'status' => '', 'altbestand' => 0,
                      'verspaetung' => (int) ( $spaet[ $id ]['minuten'] ?? 0 ),
                      'verspaetung_grund' => (string) ( $spaet[ $id ]['grund'] ?? '' ) ];
            if ( isset( $gesetzt[ $id ] ) ) {
                $satz['status'] = $gesetzt[ $id ];
            } elseif ( isset( $alt[ $id ] ) ) {
                $satz['status']     = $alt[ $id ];
                $satz['altbestand'] = 1;
            }
            $anwesenheit[] = $satz;
        }

        wp_send_json_success( [
            'tag' => [
                'id'            => $tag_id,
                'mannschaft_id' => $mannschaft_id,
                'datum'         => $datum,
                'dokumentation' => $tag ? (string) $tag['dokumentation'] : '',
                'geaendert_am'  => $tag ? $tag['geaendert_am'] : '',
            ],
            'schwimmer'     => $kader,
            'anwesenheit'   => $anwesenheit,
            'eintraege'     => $tag_id ? self::eintraege_laden( $tag_id ) : [],
            // Der Kommentar der alten Anwesenheit — nur zum Lesen, damit
            // nichts verloren geht, was dort schon steht.
            'alt_kommentar' => $session ? (string) ( $session['kommentar'] ?? '' ) : '',
            'alt_ausfall'   => $session ? (int) ( $session['ausgefallen'] ?? 0 ) : 0,
            'ausfall_grund' => '',
        ] );
    }

    /** Vermerke und Aufgaben eines Tages. */
    private static function eintraege_laden( $tag_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_mb_eintrag WHERE tag_id = %d ORDER BY id ASC",
            (int) $tag_id ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'           => (int) $r['id'],
                'schwimmer_id' => (int) $r['schwimmer_id'],
                'art'          => $r['art'],
                'kategorie'    => $r['kategorie'],
                'text'         => (string) $r['text'],
                'erledigt'     => (int) $r['erledigt'],
                'von'          => self::person( $r['angelegt_von'] ),
                'am'           => $r['angelegt_am'],
            ];
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Endpunkte: Schreiben
    // ═══════════════════════════════════════════════════════════════════════

    /** Einen Zustand setzen oder zurücknehmen. */
    public static function status() {
        LSV07I_Access::check( 'mb_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum         = self::datum( $_POST['datum'] ?? '' );
        $schwimmer_id  = absint( $_POST['schwimmer_id'] ?? 0 );
        $status        = sanitize_text_field( $_POST['status'] ?? '' );
        self::mannschaft_pruefen( $mannschaft_id );
        if ( ! $datum ) wp_send_json_error( [ 'message' => 'Bitte ein Datum wählen.' ] );
        if ( ! $schwimmer_id ) wp_send_json_error( [ 'message' => 'Keine Person angegeben.' ] );
        if ( $status !== '' && ! isset( self::ZUSTAENDE[ $status ] ) ) {
            wp_send_json_error( [ 'message' => 'Diesen Zustand gibt es nicht.' ] );
        }

        // Nur Personen der Mannschaft — der Kader ist der Maßstab, nicht das,
        // was der Browser schickt.
        $im_kader = false;
        foreach ( self::kader( $mannschaft_id ) as $s ) {
            if ( $s['id'] === $schwimmer_id ) { $im_kader = true; break; }
        }
        if ( ! $im_kader ) wp_send_json_error( [ 'message' => 'Diese Person gehört nicht zur Mannschaft.' ] );

        $tag = self::tag_holen( $mannschaft_id, $datum, true );
        if ( ! $tag ) wp_send_json_error( [ 'message' => 'Der Trainingstag konnte nicht angelegt werden.' ] );
        $tag_id = (int) $tag['id'];

        if ( $status === '' ) {
            $wpdb->delete( $p . 'lsv07i_mb_anwesenheit',
                [ 'tag_id' => $tag_id, 'schwimmer_id' => $schwimmer_id ], [ '%d', '%d' ] );
            // Auch in der alten Anwesenheit löschen. Beide Seiten werden
            // zusammengehalten; bliebe der Eintrag dort stehen, käme er
            // sofort als Altbestand zurück und das Zurücknehmen sähe aus,
            // als hätte es nicht gewirkt.
            $session = self::alte_session( $mannschaft_id, $datum );
            if ( $session ) {
                $wpdb->delete( $p . 'lsv07i_anwesenheit_eintraege', [
                    'anwesenheit_id' => (int) $session['id'],
                    'teilnehmer_typ' => 'schwimmer',
                    'teilnehmer_id'  => $schwimmer_id,
                ], [ '%d', '%s', '%d' ] );
            }
            self::tag_beruehrt( $tag_id );
            wp_send_json_success( [ 'status' => '', 'tag_id' => $tag_id ] );
        }

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_mb_anwesenheit (tag_id, schwimmer_id, status, geaendert_von, geaendert_am)
             VALUES (%d, %d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE status = VALUES(status),
                                     geaendert_von = VALUES(geaendert_von),
                                     geaendert_am  = VALUES(geaendert_am)",
            $tag_id, $schwimmer_id, $status, get_current_user_id(), current_time( 'mysql' )
        ) );

        // In die alte Anwesenheit zurückschreiben und den Tag damit verbinden
        $session_id = self::alte_anwesenheit_schreiben( $mannschaft_id, $datum, $schwimmer_id, $status );
        if ( $session_id && (int) $tag['anwesenheit_id'] !== $session_id ) {
            $wpdb->update( $p . 'lsv07i_mb_tag', [ 'anwesenheit_id' => $session_id ],
                [ 'id' => $tag_id ], [ '%d' ], [ '%d' ] );
        }
        self::tag_beruehrt( $tag_id );

        wp_send_json_success( [
            'status'  => $status,
            'tag_id'  => $tag_id,
            'alt'     => self::NEU_ZU_ALT[ $status ],
        ] );
    }

    /**
     * Verspätung je Person und Tag: Minuten und ein freiwilliger Grund.
     * 0 Minuten heisst pünktlich und löscht auch den Grund.
     *
     * Wer zu spät kommt, ist da — steht also noch kein Zustand, wird
     * „anwesend" gesetzt. Das spart einen Griff und entspricht dem, was
     * am Beckenrand passiert: Erst kommt jemand zu spät herein, dann wird
     * er eingetragen.
     */
    public static function verspaetung() {
        LSV07I_Access::check( 'mb_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum         = self::datum( $_POST['datum'] ?? '' );
        $schwimmer_id  = absint( $_POST['schwimmer_id'] ?? 0 );
        self::mannschaft_pruefen( $mannschaft_id );
        if ( ! $datum ) wp_send_json_error( [ 'message' => 'Bitte ein Datum wählen.' ] );
        if ( ! $schwimmer_id ) wp_send_json_error( [ 'message' => 'Keine Person angegeben.' ] );

        $im_kader = false;
        foreach ( self::kader( $mannschaft_id ) as $s ) {
            if ( $s['id'] === $schwimmer_id ) { $im_kader = true; break; }
        }
        if ( ! $im_kader ) wp_send_json_error( [ 'message' => 'Diese Person gehört nicht zur Mannschaft.' ] );

        // Mehr als ein halber Tag ist keine Verspätung mehr.
        $minuten = min( 720, absint( $_POST['minuten'] ?? 0 ) );
        $grund   = $minuten ? self::text_zeile( $_POST['grund'] ?? '', 200 ) : '';

        $tag = self::tag_holen( $mannschaft_id, $datum, true );
        if ( ! $tag ) wp_send_json_error( [ 'message' => 'Der Trainingstag konnte nicht angelegt werden.' ] );
        $tag_id = (int) $tag['id'];

        $vorhanden = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$p}lsv07i_mb_anwesenheit
              WHERE tag_id = %d AND schwimmer_id = %d", $tag_id, $schwimmer_id ), ARRAY_A );

        if ( $vorhanden ) {
            $wpdb->update( $p . 'lsv07i_mb_anwesenheit',
                [ 'verspaetung' => $minuten, 'verspaetung_grund' => $grund,
                  'geaendert_von' => get_current_user_id(), 'geaendert_am' => current_time( 'mysql' ) ],
                [ 'id' => (int) $vorhanden['id'] ], [ '%d', '%s', '%d', '%s' ], [ '%d' ] );
            $status = $vorhanden['status'];
        } else {
            // Ohne Zustand gäbe es keine Zeile, an der die Minuten hängen
            // könnten — wer zu spät kommt, ist anwesend.
            $status = 'anwesend';
            $wpdb->insert( $p . 'lsv07i_mb_anwesenheit', [
                'tag_id'            => $tag_id,
                'schwimmer_id'      => $schwimmer_id,
                'status'            => $status,
                'verspaetung'       => $minuten,
                'verspaetung_grund' => $grund,
                'geaendert_von'     => get_current_user_id(),
                'geaendert_am'      => current_time( 'mysql' ),
            ], [ '%d', '%d', '%s', '%d', '%s', '%d', '%s' ] );
            self::alte_anwesenheit_schreiben( $mannschaft_id, $datum, $schwimmer_id, $status );
        }

        self::tag_beruehrt( $tag_id );

        wp_send_json_success( [
            'tag_id'      => $tag_id,
            'status'      => $status,
            'verspaetung' => $minuten,
            'grund'       => $grund,
            'message'     => $minuten
                ? $minuten . ' Minuten Verspätung eingetragen.'
                : 'Verspätung entfernt.',
        ] );
    }

    /** Die Dokumentation eines Trainings speichern. */
    public static function doku() {
        LSV07I_Access::check( 'mb_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum         = self::datum( $_POST['datum'] ?? '' );
        self::mannschaft_pruefen( $mannschaft_id );
        if ( ! $datum ) wp_send_json_error( [ 'message' => 'Bitte ein Datum wählen.' ] );

        $text = self::text( $_POST['text'] ?? '', 8000 );
        $tag  = self::tag_holen( $mannschaft_id, $datum, true );
        if ( ! $tag ) wp_send_json_error( [ 'message' => 'Der Trainingstag konnte nicht angelegt werden.' ] );

        $wpdb->update( $wpdb->prefix . 'lsv07i_mb_tag',
            [ 'dokumentation' => $text !== '' ? $text : null, 'geaendert_am' => current_time( 'mysql' ) ],
            [ 'id' => (int) $tag['id'] ], [ '%s', '%s' ], [ '%d' ] );

        wp_send_json_success( [ 'tag_id' => (int) $tag['id'], 'message' => 'Dokumentation gespeichert.' ] );
    }

    /** Einen Vermerk oder eine Aufgabe anlegen. */
    public static function eintrag() {
        LSV07I_Access::check( 'mb_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum         = self::datum( $_POST['datum'] ?? '' );
        $schwimmer_id  = absint( $_POST['schwimmer_id'] ?? 0 );
        $art           = sanitize_text_field( $_POST['art'] ?? 'vermerk' );
        $kategorie     = sanitize_text_field( $_POST['kategorie'] ?? 'sonstiges' );
        $text          = self::text( $_POST['text'] ?? '', 2000 );
        self::mannschaft_pruefen( $mannschaft_id );
        if ( ! $datum ) wp_send_json_error( [ 'message' => 'Bitte ein Datum wählen.' ] );

        if ( ! in_array( $art, [ 'vermerk', 'aufgabe' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Unbekannte Art des Eintrags.' ] );
        }
        $arten = $art === 'aufgabe' ? self::AUFGABE_ARTEN : self::VERMERK_ARTEN;
        if ( ! isset( $arten[ $kategorie ] ) ) $kategorie = 'sonstiges';

        // Eine Aufgabe gehört immer zu einer Person; ein Vermerk darf für
        // die ganze Mannschaft gelten (schwimmer_id 0).
        if ( $art === 'aufgabe' && ! $schwimmer_id ) {
            wp_send_json_error( [ 'message' => 'Eine Aufgabe braucht eine Person.' ] );
        }
        if ( $schwimmer_id ) {
            $im_kader = false;
            foreach ( self::kader( $mannschaft_id ) as $s ) {
                if ( $s['id'] === $schwimmer_id ) { $im_kader = true; break; }
            }
            if ( ! $im_kader ) wp_send_json_error( [ 'message' => 'Diese Person gehört nicht zur Mannschaft.' ] );
        }
        // Ohne Text wäre „Sonstiges" nichts wert; die festen Arten sagen
        // dagegen von sich aus genug.
        if ( $text === '' && $kategorie === 'sonstiges' ) {
            wp_send_json_error( [ 'message' => 'Bitte einen Text eingeben.' ] );
        }

        $tag = self::tag_holen( $mannschaft_id, $datum, true );
        if ( ! $tag ) wp_send_json_error( [ 'message' => 'Der Trainingstag konnte nicht angelegt werden.' ] );

        $ok = $wpdb->insert( $wpdb->prefix . 'lsv07i_mb_eintrag', [
            'tag_id'        => (int) $tag['id'],
            'mannschaft_id' => $mannschaft_id,
            'schwimmer_id'  => $schwimmer_id,
            'art'           => $art,
            'kategorie'     => $kategorie,
            'text'          => $text,
            'erledigt'      => 0,
            'angelegt_von'  => get_current_user_id(),
            'angelegt_am'   => current_time( 'mysql' ),
        ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ] );
        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => 'Der Eintrag konnte nicht gespeichert werden: '
                . ( $wpdb->last_error ?: 'unbekannter Grund' ) ] );
        }

        self::tag_beruehrt( (int) $tag['id'] );
        wp_send_json_success( [
            'tag_id'    => (int) $tag['id'],
            'eintraege' => self::eintraege_laden( (int) $tag['id'] ),
            'message'   => $art === 'aufgabe' ? 'Aufgabe eingetragen.' : 'Vermerk eingetragen.',
        ] );
    }

    /** Eine Aufgabe als erledigt oder offen markieren. */
    public static function eintrag_zu() {
        LSV07I_Access::check( 'mb_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $id = absint( $_POST['id'] ?? 0 );
        $row = $id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_mb_eintrag WHERE id = %d", $id ), ARRAY_A ) : null;
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Eintrag nicht gefunden.' ] );
        self::mannschaft_pruefen( (int) $row['mannschaft_id'] );

        $erledigt = ! empty( $_POST['erledigt'] ) ? 1 : 0;
        $wpdb->update( $p . 'lsv07i_mb_eintrag', [ 'erledigt' => $erledigt ],
            [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        self::tag_beruehrt( (int) $row['tag_id'] );

        wp_send_json_success( [
            'eintraege' => self::eintraege_laden( (int) $row['tag_id'] ),
        ] );
    }

    /** Einen Vermerk oder eine Aufgabe löschen. */
    public static function eintrag_del() {
        LSV07I_Access::check( 'mb_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $id = absint( $_POST['id'] ?? 0 );
        $row = $id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_mb_eintrag WHERE id = %d", $id ), ARRAY_A ) : null;
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Eintrag nicht gefunden.' ] );
        self::mannschaft_pruefen( (int) $row['mannschaft_id'] );

        $wpdb->delete( $p . 'lsv07i_mb_eintrag', [ 'id' => $id ], [ '%d' ] );
        self::tag_beruehrt( (int) $row['tag_id'] );

        wp_send_json_success( [
            'eintraege' => self::eintraege_laden( (int) $row['tag_id'] ),
            'message'   => 'Eintrag gelöscht.',
        ] );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Endpunkte: Bahnenbelegung
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Belegung aus der Ablage lesen. Gespeichert wird sie als JSON, weil
     * eine Belegung immer nur ganz gelesen und ganz geschrieben wird —
     * eigene Zeilen brächten nichts als Fugen.
     */
    private static function bahnen_lesen( $roh, $kader_ids ) {
        $daten = json_decode( (string) $roh, true );
        $bahnen = [];
        if ( is_array( $daten ) && isset( $daten['bahnen'] ) && is_array( $daten['bahnen'] ) ) {
            foreach ( $daten['bahnen'] as $b ) {
                if ( ! is_array( $b ) ) continue;
                $ids = [];
                foreach ( (array) ( $b['schwimmer'] ?? [] ) as $sid ) {
                    $sid = (int) $sid;
                    // Wer die Mannschaft verlassen hat, verschwindet auch
                    // von der Bahn — sonst stünden Namen auf dem Blatt, die
                    // niemand mehr zuordnen kann.
                    if ( $sid && in_array( $sid, $kader_ids, true ) && ! in_array( $sid, $ids, true ) ) {
                        $ids[] = $sid;
                    }
                }
                $bahnen[] = [
                    'name'      => self::text_zeile( $b['name'] ?? '', 40 ),
                    'schwimmer' => $ids,
                ];
                if ( count( $bahnen ) >= self::BAHNEN_MAX ) break;
            }
        }
        return $bahnen;
    }

    public static function bahnen() {
        LSV07I_Access::check( 'mb_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum         = self::datum( $_POST['datum'] ?? '' );
        self::mannschaft_pruefen( $mannschaft_id );
        if ( ! $datum ) wp_send_json_error( [ 'message' => 'Bitte ein Datum wählen.' ] );

        $kader     = self::kader( $mannschaft_id );
        $kader_ids = array_map( fn( $s ) => $s['id'], $kader );

        $tag    = self::tag_holen( $mannschaft_id, $datum );
        $bahnen = $tag ? self::bahnen_lesen( $tag['bahnen'], $kader_ids ) : [];

        // Der letzte Tag mit einer Belegung — zum Übernehmen.
        $vorher = $wpdb->get_row( $wpdb->prepare(
            "SELECT datum, bahnen FROM {$p}lsv07i_mb_tag
              WHERE mannschaft_id = %d AND datum < %s AND bahnen IS NOT NULL AND bahnen <> ''
           ORDER BY datum DESC LIMIT 1",
            $mannschaft_id, $datum ), ARRAY_A );
        $vorlage = [];
        if ( $vorher ) $vorlage = self::bahnen_lesen( $vorher['bahnen'], $kader_ids );

        // Wer auf keiner Bahn steht, wartet am Beckenrand.
        $verteilt = [];
        foreach ( $bahnen as $b ) foreach ( $b['schwimmer'] as $sid ) $verteilt[] = $sid;
        $offen = array_values( array_diff( $kader_ids, $verteilt ) );

        wp_send_json_success( [
            'datum'         => $datum,
            'mannschaft_id' => $mannschaft_id,
            'schwimmer'     => $kader,
            'bahnen'        => $bahnen,
            'offen'         => $offen,
            'vorlage'       => [
                'datum'  => $vorher ? $vorher['datum'] : '',
                'bahnen' => $vorlage,
            ],
        ] );
    }

    public static function bahnen_save() {
        LSV07I_Access::check( 'mb_edit' );
        self::tabellen_sicherstellen();
        global $wpdb;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum         = self::datum( $_POST['datum'] ?? '' );
        self::mannschaft_pruefen( $mannschaft_id );
        if ( ! $datum ) wp_send_json_error( [ 'message' => 'Bitte ein Datum wählen.' ] );

        $kader_ids = array_map( fn( $s ) => $s['id'], self::kader( $mannschaft_id ) );
        $bahnen    = self::bahnen_lesen( wp_unslash( $_POST['bahnen'] ?? '' ), $kader_ids );

        // Eine Person steht auf höchstens einer Bahn.
        $gesehen = [];
        foreach ( $bahnen as $i => $b ) {
            $rein = [];
            foreach ( $b['schwimmer'] as $sid ) {
                if ( in_array( $sid, $gesehen, true ) ) continue;
                $gesehen[] = $sid;
                $rein[]    = $sid;
            }
            $bahnen[ $i ]['schwimmer'] = $rein;
        }

        $tag = self::tag_holen( $mannschaft_id, $datum, true );
        if ( ! $tag ) wp_send_json_error( [ 'message' => 'Der Trainingstag konnte nicht angelegt werden.' ] );

        $leer = true;
        foreach ( $bahnen as $b ) if ( $b['schwimmer'] ) { $leer = false; break; }

        $wpdb->update( $wpdb->prefix . 'lsv07i_mb_tag',
            [ 'bahnen'       => ( $bahnen && ! $leer ) ? wp_json_encode( [ 'bahnen' => $bahnen ] ) : null,
              'geaendert_am' => current_time( 'mysql' ) ],
            [ 'id' => (int) $tag['id'] ], [ '%s', '%s' ], [ '%d' ] );

        wp_send_json_success( [
            'bahnen'  => $bahnen,
            'message' => 'Bahnenbelegung gespeichert.',
        ] );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Endpunkt: Statistik
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Statistik über einen Zeitraum — für die ganze Mannschaft oder eine
     * Person. Gezählt wird das Buch; wo im Buch für einen Tag nichts steht,
     * zählt der Altbestand aus der alten Anwesenheit mit und wird eigens
     * ausgewiesen.
     */
    public static function statistik() {
        LSV07I_Access::check( 'mb_read' );
        self::tabellen_sicherstellen();
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $schwimmer_id  = absint( $_POST['schwimmer_id'] ?? 0 );
        $von           = self::datum( $_POST['von'] ?? '' ) ?: date( 'Y-m-01' );
        $bis           = self::datum( $_POST['bis'] ?? '' ) ?: date( 'Y-m-d' );
        self::mannschaft_pruefen( $mannschaft_id );
        if ( $von > $bis ) { $hilf = $von; $von = $bis; $bis = $hilf; }

        $grenze = self::fruehestes_datum();
        if ( $grenze && $von < $grenze ) $von = $grenze;

        $kader = self::kader( $mannschaft_id );
        $namen = [];
        foreach ( $kader as $s ) $namen[ $s['id'] ] = $s['name'];
        if ( $schwimmer_id && ! isset( $namen[ $schwimmer_id ] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Person gehört nicht zur Mannschaft.' ] );
        }

        // ── Tage des Buches ────────────────────────────────────────────────
        $tage = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, datum, dokumentation FROM {$p}lsv07i_mb_tag
              WHERE mannschaft_id = %d AND datum BETWEEN %s AND %s
           ORDER BY datum ASC",
            $mannschaft_id, $von, $bis ), ARRAY_A );

        $tag_ids   = [];
        $tag_datum = [];
        foreach ( (array) $tage as $t ) {
            $tag_ids[]                      = (int) $t['id'];
            $tag_datum[ (int) $t['id'] ]    = $t['datum'];
        }

        // ── Zustände aus dem Buch ─────────────────────────────────────────
        // je Person und Datum, damit der Altbestand nur dort einspringt,
        // wo im Buch wirklich nichts steht.
        $buch   = [];
        $spaet  = [];   // schwimmer_id → datum → [minuten, grund]
        if ( $tag_ids ) {
            $in = implode( ',', $tag_ids );
            foreach ( (array) $wpdb->get_results(
                "SELECT tag_id, schwimmer_id, status, verspaetung, verspaetung_grund
                   FROM {$p}lsv07i_mb_anwesenheit WHERE tag_id IN ($in)", ARRAY_A ) as $r ) {
                $datum = $tag_datum[ (int) $r['tag_id'] ] ?? '';
                if ( $datum === '' ) continue;
                $sid = (int) $r['schwimmer_id'];
                $buch[ $sid ][ $datum ] = $r['status'];
                if ( (int) $r['verspaetung'] > 0 ) {
                    $spaet[ $sid ][ $datum ] = [
                        'minuten' => (int) $r['verspaetung'],
                        'grund'   => (string) $r['verspaetung_grund'],
                    ];
                }
            }
        }

        // ── Altbestand aus der alten Anwesenheit ──────────────────────────
        $alt = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT s.training_datum AS datum, e.teilnehmer_id AS sid, e.status
               FROM {$p}lsv07i_anwesenheit s
               JOIN {$p}lsv07i_anwesenheit_eintraege e ON e.anwesenheit_id = s.id
              WHERE s.mannschaft_id = %d AND s.training_datum BETWEEN %s AND %s
                AND e.teilnehmer_typ = 'schwimmer'",
            $mannschaft_id, $von, $bis ), ARRAY_A ) as $r ) {
            $neu = self::ALT_ZU_NEU[ $r['status'] ] ?? null;
            if ( ! $neu ) continue;
            $sid = (int) $r['sid'];
            if ( isset( $buch[ $sid ][ $r['datum'] ] ) ) continue;   // das Buch gilt
            $alt[ $sid ][ $r['datum'] ] = $neu;
        }

        // ── Zählen ────────────────────────────────────────────────────────
        $leer = array_fill_keys( array_keys( self::ZUSTAENDE ), 0 );
        $personen = [];
        $summe    = $leer;
        $summe_termine = 0;
        $altbestand_zahl = 0;
        $alle_termine = [];
        $spaet_liste = [];
        $spaet_summe = [ 'faelle' => 0, 'minuten' => 0 ];

        foreach ( $kader as $s ) {
            $sid = $s['id'];
            if ( $schwimmer_id && $sid !== $schwimmer_id ) continue;
            $zahl = $leer;
            $termine = 0;
            $alt_zahl = 0;
            $verlauf = [];
            $quellen = [ 'buch' => $buch[ $sid ] ?? [], 'alt' => $alt[ $sid ] ?? [] ];
            foreach ( $quellen as $quelle => $tage_person ) {
                foreach ( $tage_person as $datum => $status ) {
                    if ( ! isset( $zahl[ $status ] ) ) continue;
                    $zahl[ $status ]++;
                    $termine++;
                    if ( $quelle === 'alt' ) $alt_zahl++;
                    $verlauf[ $datum ] = [
                        'status'      => $status,
                        'altbestand'  => $quelle === 'alt' ? 1 : 0,
                        'verspaetung' => (int) ( $spaet[ $sid ][ $datum ]['minuten'] ?? 0 ),
                        'grund'       => (string) ( $spaet[ $sid ][ $datum ]['grund'] ?? '' ),
                    ];
                    $alle_termine[ $datum ] = true;
                }
            }
            ksort( $verlauf );
            foreach ( $zahl as $k => $v ) $summe[ $k ] += $v;
            $summe_termine   += $termine;
            $altbestand_zahl += $alt_zahl;

            // Verspätungen: Anzahl und Minuten je Person, dazu eine Liste
            // für die Ausgabe.
            $spaet_faelle  = 0;
            $spaet_minuten = 0;
            foreach ( (array) ( $spaet[ $sid ] ?? [] ) as $datum => $v ) {
                $spaet_faelle++;
                $spaet_minuten += (int) $v['minuten'];
                $spaet_liste[] = [
                    'datum'        => $datum,
                    'schwimmer_id' => $sid,
                    'name'         => $s['name'],
                    'minuten'      => (int) $v['minuten'],
                    'grund'        => (string) $v['grund'],
                ];
            }
            $spaet_summe['faelle']  += $spaet_faelle;
            $spaet_summe['minuten'] += $spaet_minuten;

            $personen[] = [
                'schwimmer_id'      => $sid,
                'name'              => $s['name'],
                'jahrgang'          => $s['jahrgang'],
                'zahl'              => $zahl,
                'termine'           => $termine,
                'altbestand'        => $alt_zahl,
                'quote'             => $termine ? round( 100 * $zahl['anwesend'] / $termine ) : null,
                'verspaetungen'     => $spaet_faelle,
                'verspaetung_minuten' => $spaet_minuten,
                'verlauf'           => $schwimmer_id ? $verlauf : [],
            ];
        }

        // Jüngste zuerst, wie bei Vermerken und Aufgaben.
        usort( $spaet_liste, function ( $a, $b ) {
            return strcmp( $b['datum'], $a['datum'] );
        } );

        // ── Vermerke und Aufgaben ─────────────────────────────────────────
        $wo_person = $schwimmer_id ? $wpdb->prepare( ' AND e.schwimmer_id = %d', $schwimmer_id ) : '';
        $eintraege = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, t.datum
               FROM {$p}lsv07i_mb_eintrag e
               JOIN {$p}lsv07i_mb_tag t ON t.id = e.tag_id
              WHERE e.mannschaft_id = %d AND t.datum BETWEEN %s AND %s $wo_person
           ORDER BY t.datum DESC, e.id DESC",
            $mannschaft_id, $von, $bis ), ARRAY_A );

        $vermerke = [];
        $aufgaben = [];
        $aufgaben_zahl = array_fill_keys( array_keys( self::AUFGABE_ARTEN ), 0 );
        $aufgaben_offen = 0;
        $je_person = [];
        foreach ( (array) $eintraege as $e ) {
            $satz = [
                'id'           => (int) $e['id'],
                'datum'        => $e['datum'],
                'schwimmer_id' => (int) $e['schwimmer_id'],
                'name'         => (int) $e['schwimmer_id'] ? ( $namen[ (int) $e['schwimmer_id'] ] ?? '—' ) : '',
                'kategorie'    => $e['kategorie'],
                'text'         => (string) $e['text'],
                'erledigt'     => (int) $e['erledigt'],
                'von'          => self::person( $e['angelegt_von'] ),
            ];
            if ( $e['art'] === 'aufgabe' ) {
                $aufgaben[] = $satz;
                if ( isset( $aufgaben_zahl[ $e['kategorie'] ] ) ) $aufgaben_zahl[ $e['kategorie'] ]++;
                if ( ! (int) $e['erledigt'] ) $aufgaben_offen++;
                if ( $satz['schwimmer_id'] ) $je_person[ $satz['schwimmer_id'] ]['aufgaben'] =
                    ( $je_person[ $satz['schwimmer_id'] ]['aufgaben'] ?? 0 ) + 1;
            } else {
                $vermerke[] = $satz;
                if ( $satz['schwimmer_id'] ) $je_person[ $satz['schwimmer_id'] ]['vermerke'] =
                    ( $je_person[ $satz['schwimmer_id'] ]['vermerke'] ?? 0 ) + 1;
            }
        }
        foreach ( $personen as $i => $pz ) {
            $personen[ $i ]['aufgaben_zahl'] = $je_person[ $pz['schwimmer_id'] ]['aufgaben'] ?? 0;
            $personen[ $i ]['vermerke_zahl'] = $je_person[ $pz['schwimmer_id'] ]['vermerke'] ?? 0;
        }

        // ── Dokumentation ─────────────────────────────────────────────────
        $doku = [];
        foreach ( (array) $tage as $t ) {
            if ( trim( (string) $t['dokumentation'] ) === '' ) continue;
            $doku[] = [ 'datum' => $t['datum'], 'text' => (string) $t['dokumentation'] ];
        }

        wp_send_json_success( [
            'von'   => $von,
            'bis'   => $bis,
            'mannschaft_id' => $mannschaft_id,
            'schwimmer_id'  => $schwimmer_id,
            'schwimmer'     => $kader,
            'personen'      => $personen,
            'summe'         => [
                'zahl'       => $summe,
                'termine'    => $summe_termine,
                'altbestand' => $altbestand_zahl,
                'tage'       => count( $alle_termine ),
                'quote'      => $summe_termine ? round( 100 * $summe['anwesend'] / $summe_termine ) : null,
                'verspaetungen'       => $spaet_summe['faelle'],
                'verspaetung_minuten' => $spaet_summe['minuten'],
            ],
            'verspaetungen' => $spaet_liste,
            'vermerke'       => $vermerke,
            'aufgaben'       => $aufgaben,
            'aufgaben_zahl'  => $aufgaben_zahl,
            'aufgaben_offen' => $aufgaben_offen,
            'dokumentation'  => $doku,
        ] );
    }
}
