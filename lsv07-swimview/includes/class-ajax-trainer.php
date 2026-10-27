<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Endpunkte des Trainerportals.
 *
 * Hier ist jemand bei WordPress angemeldet — darum der gewohnte Nonce und
 * `wp_ajax_` ohne nopriv. Geprüft wird bei jedem Aufruf zweierlei: ob das
 * Konto überhaupt Zugang hat, und ob die genannte Mannschaft ihm gehört.
 * Die zweite Prüfung steht in JEDEM Endpunkt und nicht nur beim Laden der
 * Liste: Eine Mannschafts-ID kommt aus dem Browser und ist deshalb ein
 * Wunsch, keine Tatsache.
 */
class LSV07S_Ajax_Trainer {

    public static function init() {
        $map = [
            'lsv07s_t_basis'      => 'basis',
            'lsv07s_t_mannschaft' => 'mannschaft',
            'lsv07s_t_freigabe'   => 'freigabe',
            'lsv07s_t_plan'       => 'plan_freigabe',
            'lsv07s_t_senden'     => 'senden',
            'lsv07s_t_loeschen'   => 'loeschen',
            'lsv07s_t_datei_weg'  => 'datei_weg',
            'lsv07s_t_code'       => 'code_neu',
            'lsv07s_t_code_weg'   => 'code_weg',
            'lsv07s_t_abmelden'   => 'sitzungen_beenden',
            'lsv07s_t_sicherheit' => 'sicherheit',
            'lsv07s_t_spur'       => 'spur',
            'lsv07s_t_vergessen'  => 'vergessen',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $methode ] );
        }
    }

    // ── Gemeinsames ─────────────────────────────────────────────────────

    private static function pruefen() {
        if ( ! check_ajax_referer( 'lsv07s_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Die Sitzung ist abgelaufen. Bitte Seite neu laden.' ], 403 );
        }
        if ( ! LSV07S_Trainer::hat_zugang() ) {
            wp_send_json_error( [ 'message' => 'Dafür fehlt die Berechtigung.' ], 403 );
        }
    }

    private static function mannschaft_pruefen() {
        self::pruefen();
        $mid = (int) ( $_POST['mannschaft_id'] ?? 0 );
        if ( ! LSV07S_Trainer::darf_mannschaft( $mid ) ) {
            wp_send_json_error( [ 'message' => 'Diese Mannschaft gehört nicht zu deinen.' ], 403 );
        }
        return $mid;
    }

    // ── Grunddaten ──────────────────────────────────────────────────────

    public static function basis() {
        self::pruefen();

        $mannschaften = [];
        foreach ( LSV07S_Trainer::mannschaften() as $id => $name ) {
            $mannschaften[] = [ 'id' => (int) $id, 'name' => $name ];
        }

        $bereiche = [];
        foreach ( LSV07S_Freigabe::BEREICHE as $key => $b ) {
            $bereiche[] = [ 'key' => $key, 'name' => $b['name'], 'text' => $b['text'] ];
        }

        $plaene = [];
        foreach ( LSV07S_Trainer::eigene_plaene() as $p ) {
            $plaene[] = [
                'id'    => (int) $p['id'],
                'titel' => (string) $p['titel'] !== '' ? (string) $p['titel'] : 'Trainingsplan',
                'von'   => (string) $p['ersteller_name'],
                'stand' => substr( (string) $p['geaendert_am'], 0, 10 ),
            ];
        }

        wp_send_json_success( [
            'mannschaften' => $mannschaften,
            'bereiche'     => $bereiche,
            'plaene'       => $plaene,
            'ist_leitung'  => LSV07S_Trainer::ist_leitung(),
            'max_mb'       => LSV07S_Dateien::mb(),
            'hinweis'      => LSV07S_Intern::hinweis(),
            'sicherheit'   => self::sicherheitsstand(),
            'https'        => LSV07S_Login::verbindung_sicher(),
        ] );
    }

    /** Stand einer Mannschaft: Häkchen, freigegebene Pläne, Schwimmer, Nachrichten. */
    public static function mannschaft() {
        $mid = self::mannschaft_pruefen();

        $schwimmer = [];
        foreach ( LSV07S_Intern::schwimmer_der_mannschaft( $mid ) as $s ) {
            $schwimmer[] = [
                'id'       => (int) $s['id'],
                'name'     => trim( $s['last_name'] . ', ' . $s['first_name'], ', ' ),
                'jahrgang' => substr( (string) $s['birth_date'], 0, 4 ),
                'dsv'      => (string) $s['dsv_id'],
            ];
        }

        wp_send_json_success( [
            'freigaben'   => LSV07S_Freigabe::fuer_mannschaft( $mid ),
            'plaene_frei' => LSV07S_Freigabe::plaene_der_mannschaft( $mid ),
            'schwimmer'   => $schwimmer,
            'codes'       => LSV07S_Code::stand( self::ids_der_mannschaft( $mid ) ),
            'nachrichten' => self::nachrichten_der_mannschaft( $mid ),
        ] );
    }

    /**
     * Die Nachrichten einer Mannschaft samt Anhängen und der Zahl derer,
     * die sie gelesen haben.
     */
    private static function nachrichten_der_mannschaft( $mannschaft_id ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, schwimmer_id, betreff, text, von_name, erstellt_am
               FROM {$p}lsv07s_nachricht
              WHERE mannschaft_id = %d
           ORDER BY erstellt_am DESC, id DESC LIMIT 100",
            (int) $mannschaft_id ), ARRAY_A );
        if ( ! $rows ) return [];

        /* Namen der Einzelempfänger in einem Rutsch statt je Zeile. */
        $sids = [];
        foreach ( $rows as $r ) if ( (int) $r['schwimmer_id'] ) $sids[] = (int) $r['schwimmer_id'];
        $namen = [];
        if ( $sids && LSV07S_Intern::da( 'mv_swimmers' ) ) {
            $sids = array_values( array_unique( $sids ) );
            $pl   = implode( ',', array_fill( 0, count( $sids ), '%d' ) );
            $n    = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, first_name, last_name FROM {$p}mv_swimmers WHERE id IN ($pl)",
                ...$sids ), ARRAY_A );
            foreach ( (array) $n as $x ) {
                $namen[ (int) $x['id'] ] = trim( $x['last_name'] . ', ' . $x['first_name'], ', ' );
            }
        }

        $out = [];
        foreach ( $rows as $r ) {
            $id = (int) $r['id'];
            $gelesen = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}lsv07s_gelesen WHERE nachricht_id = %d", $id ) );
            $out[] = [
                'id'        => $id,
                'betreff'   => (string) $r['betreff'],
                'text'      => (string) $r['text'],
                'von'       => (string) $r['von_name'],
                'am'        => (string) $r['erstellt_am'],
                'an'        => (int) $r['schwimmer_id'] === 0
                               ? 'die ganze Mannschaft'
                               : ( $namen[ (int) $r['schwimmer_id'] ] ?? 'eine Person' ),
                'einzeln'   => (int) $r['schwimmer_id'] !== 0,
                'gelesen'   => $gelesen,
                'dateien'   => LSV07S_Dateien::zu_nachricht( $id ),
            ];
        }
        return $out;
    }

    // ── Freigaben ───────────────────────────────────────────────────────

    public static function freigabe() {
        $mid     = self::mannschaft_pruefen();
        $bereich = isset( $_POST['bereich'] ) ? sanitize_key( wp_unslash( $_POST['bereich'] ) ) : '';
        if ( ! LSV07S_Freigabe::ist_bereich( $bereich ) ) {
            wp_send_json_error( [ 'message' => 'Diesen Bereich gibt es nicht.' ], 400 );
        }
        $aktiv = ! empty( $_POST['aktiv'] );

        LSV07S_Freigabe::setzen( $mid, $bereich, $aktiv, get_current_user_id() );
        self::protokoll( $aktiv ? 'swimview.freigabe.an' : 'swimview.freigabe.aus',
            [ 'mannschaft_id' => $mid, 'bereich' => $bereich ] );

        wp_send_json_success( [
            'freigaben' => LSV07S_Freigabe::fuer_mannschaft( $mid ),
            'message'   => LSV07S_Freigabe::BEREICHE[ $bereich ]['name']
                           . ( $aktiv ? ' ist jetzt sichtbar.' : ' ist nicht mehr sichtbar.' ),
        ] );
    }

    public static function plan_freigabe() {
        $mid  = self::mannschaft_pruefen();
        $pid  = (int) ( $_POST['plan_id'] ?? 0 );
        $aktiv = ! empty( $_POST['aktiv'] );

        if ( ! LSV07S_Trainer::darf_plan( $pid ) ) {
            wp_send_json_error( [ 'message' => 'Diesen Trainingsplan kannst du nicht freigeben.' ], 403 );
        }
        LSV07S_Freigabe::plan_setzen( $mid, $pid, $aktiv, get_current_user_id() );
        self::protokoll( $aktiv ? 'swimview.plan.an' : 'swimview.plan.aus',
            [ 'mannschaft_id' => $mid, 'plan_id' => $pid ] );

        wp_send_json_success( [
            'plaene_frei' => LSV07S_Freigabe::plaene_der_mannschaft( $mid ),
            'message'     => $aktiv ? 'Der Plan ist freigegeben.' : 'Die Freigabe ist zurückgenommen.',
        ] );
    }

    // ── Nachrichten ─────────────────────────────────────────────────────

    public static function senden() {
        $mid = self::mannschaft_pruefen();

        $betreff = isset( $_POST['betreff'] ) ? sanitize_text_field( wp_unslash( $_POST['betreff'] ) ) : '';
        $text    = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
        $betreff = mb_substr( trim( $betreff ), 0, 160 );
        $text    = mb_substr( trim( $text ), 0, 5000 );
        $sid     = (int) ( $_POST['schwimmer_id'] ?? 0 );

        if ( $betreff === '' ) {
            wp_send_json_error( [ 'message' => 'Bitte gib einen Betreff an.' ], 400 );
        }
        if ( $text === '' && empty( $_FILES['datei'] ) ) {
            wp_send_json_error( [ 'message' => 'Bitte schreibe etwas oder häng eine Datei an.' ], 400 );
        }

        /* Ein Einzelempfänger muss zu dieser Mannschaft gehören — sonst
           bekäme jemand eine Nachricht in einer Mannschaft, in der er nicht
           ist, und niemand fände sie wieder. */
        if ( $sid ) {
            $drin = false;
            foreach ( LSV07S_Intern::schwimmer_der_mannschaft( $mid ) as $s ) {
                if ( (int) $s['id'] === $sid ) { $drin = true; break; }
            }
            if ( ! $drin ) {
                wp_send_json_error( [ 'message' => 'Diese Person ist nicht in dieser Mannschaft.' ], 400 );
            }
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( $p . 'lsv07s_nachricht', [
            'mannschaft_id' => $mid,
            'schwimmer_id'  => $sid,
            'betreff'       => $betreff,
            'text'          => $text,
            'von_user'      => get_current_user_id(),
            'von_name'      => LSV07S_Intern::trainer_name( get_current_user_id() ),
            'erstellt_am'   => current_time( 'mysql' ),
        ], [ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ] );

        $nid = (int) $wpdb->insert_id;
        if ( ! $nid ) {
            wp_send_json_error( [ 'message' => 'Die Nachricht konnte nicht gespeichert werden.' ], 500 );
        }

        /* Anhänge. Scheitert einer, bleibt die Nachricht trotzdem stehen —
           sie hat ja einen Text. Was nicht ankam, wird aber gesagt. */
        $warnungen = [];
        if ( ! empty( $_FILES['datei'] ) && is_array( $_FILES['datei'] ) ) {
            foreach ( self::dateien_einzeln( $_FILES['datei'] ) as $datei ) {
                $r = LSV07S_Dateien::ablegen( $nid, $datei );
                if ( empty( $r['ok'] ) ) {
                    $warnungen[] = ( $datei['name'] ?? 'Datei' ) . ': ' . ( $r['message'] ?? 'Fehler' );
                }
            }
        }

        self::protokoll( 'swimview.nachricht', [
            'mannschaft_id' => $mid, 'schwimmer_id' => $sid, 'nachricht_id' => $nid ] );

        wp_send_json_success( [
            'nachrichten' => self::nachrichten_der_mannschaft( $mid ),
            'warnungen'   => $warnungen,
            'message'     => $warnungen
                             ? 'Die Nachricht ist raus, aber nicht jede Datei kam mit.'
                             : ( $sid ? 'Die Nachricht ist zugestellt.'
                                      : 'Die Nachricht steht für die ganze Mannschaft bereit.' ),
        ] );
    }

    /**
     * Mehrere Dateien aus einem Feld in einzelne Datei-Arrays zerlegen.
     * PHP dreht bei `name="datei[]"` die Struktur um: ein Array je
     * Eigenschaft statt eines Arrays je Datei.
     */
    private static function dateien_einzeln( array $feld ) {
        if ( ! is_array( $feld['name'] ) ) return [ $feld ];
        $out = [];
        foreach ( array_keys( $feld['name'] ) as $i ) {
            if ( (int) $feld['error'][ $i ] === UPLOAD_ERR_NO_FILE ) continue;
            $out[] = [
                'name'     => $feld['name'][ $i ],
                'type'     => $feld['type'][ $i ],
                'tmp_name' => $feld['tmp_name'][ $i ],
                'error'    => $feld['error'][ $i ],
                'size'     => $feld['size'][ $i ],
            ];
        }
        return $out;
    }

    public static function loeschen() {
        $mid = self::mannschaft_pruefen();
        $nid = (int) ( $_POST['id'] ?? 0 );

        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT mannschaft_id FROM {$p}lsv07s_nachricht WHERE id = %d", $nid ), ARRAY_A );
        if ( ! $row || (int) $row['mannschaft_id'] !== $mid ) {
            wp_send_json_error( [ 'message' => 'Diese Nachricht gibt es hier nicht.' ], 404 );
        }

        LSV07S_Dateien::alle_loeschen( $nid );
        $wpdb->delete( $p . 'lsv07s_gelesen',   [ 'nachricht_id' => $nid ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07s_nachricht', [ 'id' => $nid ], [ '%d' ] );
        self::protokoll( 'swimview.nachricht.weg', [ 'mannschaft_id' => $mid, 'nachricht_id' => $nid ] );

        wp_send_json_success( [
            'nachrichten' => self::nachrichten_der_mannschaft( $mid ),
            'message'     => 'Die Nachricht ist gelöscht.',
        ] );
    }

    public static function datei_weg() {
        $mid = self::mannschaft_pruefen();
        $did = (int) ( $_POST['id'] ?? 0 );

        $datei = LSV07S_Dateien::holen( $did );
        if ( ! $datei ) wp_send_json_error( [ 'message' => 'Diese Datei gibt es nicht mehr.' ], 404 );

        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT mannschaft_id FROM {$p}lsv07s_nachricht WHERE id = %d",
            (int) $datei['nachricht_id'] ), ARRAY_A );
        if ( ! $row || (int) $row['mannschaft_id'] !== $mid ) {
            wp_send_json_error( [ 'message' => 'Diese Datei gehört nicht zu deiner Mannschaft.' ], 403 );
        }

        LSV07S_Dateien::loeschen( $did );
        wp_send_json_success( [
            'nachrichten' => self::nachrichten_der_mannschaft( $mid ),
            'message'     => 'Die Datei ist gelöscht.',
        ] );
    }

    // ── Zugangscodes ────────────────────────────────────────────────────

    /**
     * Prüft, dass dieses Kind zu einer Mannschaft dieses Kontos gehört.
     * Steht in jedem Code-Endpunkt: Eine Schwimmer-Nummer kommt aus dem
     * Browser und ist damit ein Wunsch, keine Tatsache.
     */
    private static function kind_pruefen( $mid ) {
        $sid = (int) ( $_POST['schwimmer_id'] ?? 0 );
        foreach ( LSV07S_Intern::schwimmer_der_mannschaft( $mid ) as $s ) {
            if ( (int) $s['id'] === $sid ) return $sid;
        }
        wp_send_json_error( [ 'message' => 'Diese Person ist nicht in dieser Mannschaft.' ], 403 );
    }

    /**
     * Erzeugt einen neuen Zugangscode und gibt ihn EINMAL im Klartext
     * zurück. Danach ist er nur noch als Hash gespeichert und nie wieder
     * anzuzeigen — auch nicht für die Verwaltung.
     */
    public static function code_neu() {
        $mid = self::mannschaft_pruefen();
        $sid = self::kind_pruefen( $mid );

        $klartext = LSV07S_Code::neu_setzen( $sid, get_current_user_id() );
        self::protokoll( 'swimview.code.neu', [ 'mannschaft_id' => $mid, 'schwimmer_id' => $sid ] );

        wp_send_json_success( [
            'code'      => $klartext,
            'codes'     => LSV07S_Code::stand( self::ids_der_mannschaft( $mid ) ),
            'message'   => 'Der Code gilt ab sofort. Er ist jetzt einmal zu sehen — danach nicht mehr.',
            'hinweis'   => 'Gib ihn der Person persönlich oder auf Papier. Nicht per E-Mail '
                         . 'und nicht im Gruppenchat: Dort steht er dann dauerhaft.',
        ] );
    }

    public static function code_weg() {
        $mid = self::mannschaft_pruefen();
        $sid = self::kind_pruefen( $mid );

        LSV07S_Code::entfernen( $sid, get_current_user_id() );
        self::protokoll( 'swimview.code.weg', [ 'mannschaft_id' => $mid, 'schwimmer_id' => $sid ] );

        wp_send_json_success( [
            'codes'   => LSV07S_Code::stand( self::ids_der_mannschaft( $mid ) ),
            'message' => LSV07S_Code::modus() === LSV07S_Code::PFLICHT
                ? 'Der Code ist weg. Solange ein Code Pflicht ist, kommt diese Person damit nicht mehr hinein.'
                : 'Der Code ist weg.',
        ] );
    }

    /** Beendet alle Sitzungen eines Kindes — etwa nach einem Geräteverlust. */
    public static function sitzungen_beenden() {
        $mid = self::mannschaft_pruefen();
        $sid = self::kind_pruefen( $mid );

        LSV07S_Login::alle_sitzungen_beenden( $sid );
        LSV07S_DB::spur( 'sitzung.beendet', $sid, 'durch Trainer' );
        self::protokoll( 'swimview.abmelden', [ 'mannschaft_id' => $mid, 'schwimmer_id' => $sid ] );

        wp_send_json_success( [ 'message' => 'Diese Person ist jetzt überall abgemeldet.' ] );
    }

    private static function ids_der_mannschaft( $mid ) {
        $ids = [];
        foreach ( LSV07S_Intern::schwimmer_der_mannschaft( $mid ) as $s ) $ids[] = (int) $s['id'];
        return $ids;
    }

    /**
     * Löscht alles, was SwimView über ein Kind selbst führt.
     *
     * Das sind: der Zugangscode, alle Sitzungen, alle Lesemarken, die
     * Nachrichten, die nur an dieses Kind gingen (samt Dateien), und die
     * Prüfspur. Was im internen Bereich steht — Anwesenheit, Vermerke,
     * Bestzeiten — bleibt unangetastet: Das gehört nicht SwimView, und es
     * dort zu löschen wäre eine Entscheidung, die woanders hingehört.
     *
     * Für das Recht auf Löschung nach Art. 17 DSGVO, soweit es SwimView
     * betrifft — und für den schlichten Fall, dass ein Kind den Verein
     * verlässt.
     */
    public static function vergessen() {
        global $wpdb;
        $mid = self::mannschaft_pruefen();
        $sid = self::kind_pruefen( $mid );
        $p   = $wpdb->prefix;

        /* Gelöscht wird in LSV07S_Privacy — dort steht der EINZIGE Löschweg.
           Derselbe Vorgang kommt auch über die Werkzeuge von WordPress
           (Art. 17); zwei Listen desselben Vorgangs würden auseinanderlaufen,
           und der Unterschied fiele niemandem auf. Mannschaftsnachrichten
           bleiben dabei, sie gehören allen. */
        LSV07S_Privacy::schwimmer_vergessen( $sid );

        self::protokoll( 'swimview.vergessen', [ 'mannschaft_id' => $mid, 'schwimmer_id' => $sid ] );

        wp_send_json_success( [
            'codes'       => LSV07S_Code::stand( self::ids_der_mannschaft( $mid ) ),
            'nachrichten' => self::nachrichten_der_mannschaft( $mid ),
            'message'     => 'Alles gelöscht, was SwimView über diese Person geführt hat. '
                           . 'Die Daten im internen Bereich sind unberührt.',
        ] );
    }

    // ── Sicherheitseinstellungen ────────────────────────────────────────

    /**
     * Wie streng der Zugangscode gilt und wie lange Nachrichten bleiben.
     * Das ist eine Einstellung für den ganzen Verein, nicht für eine
     * Mannschaft — darum nur für die Verwaltung.
     */
    public static function sicherheit() {
        self::pruefen();
        if ( ! LSV07S_Trainer::ist_leitung() ) {
            wp_send_json_error( [ 'message' => 'Das darf nur die Verwaltung ändern.' ], 403 );
        }

        if ( isset( $_POST['modus'] ) ) {
            $m = sanitize_key( wp_unslash( $_POST['modus'] ) );
            if ( ! LSV07S_Code::modus_setzen( $m ) ) {
                wp_send_json_error( [ 'message' => 'Diese Einstellung gibt es nicht.' ], 400 );
            }
            self::protokoll( 'swimview.codemodus', [ 'modus' => $m ] );
            LSV07S_DB::spur( 'einstellung.code', 0, 'Zugangscode: ' . $m );
        }

        if ( isset( $_POST['nachricht_tage'] ) ) {
            $t = max( 0, min( 3650, (int) $_POST['nachricht_tage'] ) );
            LSV07S_DB::config_setzen( 'swimview_nachricht_tage', $t );
            LSV07S_DB::spur( 'einstellung.aufbewahrung', 0, 'Nachrichten: ' . $t . ' Tage' );
        }

        if ( isset( $_POST['csp_streng'] ) ) {
            LSV07S_DB::config_setzen( 'swimview_csp_streng', empty( $_POST['csp_streng'] ) ? '0' : '1' );
        }

        wp_send_json_success( self::sicherheitsstand() );
    }

    public static function sicherheitsstand() {
        return [
            'modus'          => LSV07S_Code::modus(),
            'nachricht_tage' => (int) LSV07S_DB::config( 'swimview_nachricht_tage', 365 ),
            'csp_streng'     => (string) LSV07S_DB::config( 'swimview_csp_streng', '0' ) === '1',
            'ruhe_stunden'   => (int) round( LSV07S_DB::SITZUNG_RUHE / 3600 ),
            'max_tage'       => (int) round( LSV07S_DB::SITZUNG_MAX / 86400 ),
            'spur_tage'      => LSV07S_DB::AUFBEWAHRUNG_SPUR,
        ];
    }

    // ── Prüfspur ────────────────────────────────────────────────────────

    /**
     * Die letzten Vorgänge zu den Kindern dieser Mannschaft: Anmeldungen,
     * Fehlversuche, Sperren. Damit ein Missbrauch auffällt — und damit ein
     * Trainer sieht, ob der Code überhaupt angekommen ist.
     */
    public static function spur() {
        global $wpdb;
        $mid = self::mannschaft_pruefen();
        $ids = self::ids_der_mannschaft( $mid );
        if ( ! $ids ) wp_send_json_success( [ 'eintraege' => [] ] );

        $p     = $wpdb->prefix;
        $platz = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT was, schwimmer_id, hinweis, am
               FROM {$p}lsv07s_spur
              WHERE schwimmer_id IN ($platz)
           ORDER BY am DESC, id DESC LIMIT 60", ...$ids ), ARRAY_A );

        $namen = [];
        foreach ( LSV07S_Intern::schwimmer_der_mannschaft( $mid ) as $s ) {
            $namen[ (int) $s['id'] ] = $s['last_name'] . ', ' . $s['first_name'];
        }

        $wort = [
            'anmeldung.ok'      => 'angemeldet',
            'anmeldung.fehl'    => 'Fehlversuch',
            'anmeldung.gesperrt'=> 'war gesperrt',
            'abmeldung'         => 'abgemeldet',
            'sitzung.fremd'     => 'Sitzung abgewiesen',
            'sitzung.beendet'   => 'vom Trainer abgemeldet',
            'code.neu'          => 'Code erzeugt',
            'code.weg'          => 'Code entfernt',
        ];

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'was'   => $wort[ $r['was'] ] ?? (string) $r['was'],
                'name'  => $namen[ (int) $r['schwimmer_id'] ] ?? '',
                'am'    => (string) $r['am'],
            ];
        }
        wp_send_json_success( [ 'eintraege' => $out ] );
    }

    /** Ins Protokoll des internen Bereichs, wenn es eines gibt. */
    private static function protokoll( $was, array $daten ) {
        if ( class_exists( 'LSV07I_Log' ) && method_exists( 'LSV07I_Log', 'write' ) ) {
            LSV07I_Log::write( $was, $daten );
        }
    }
}
