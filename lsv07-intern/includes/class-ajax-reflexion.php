<?php
/**
 * AJAX-Endpunkte für Reflexionsbögen (Trainer-Seite, eingeloggt).
 * Öffentliche Schwimmer-Abgabe läuft über class-reflexion-public.php.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Reflexion {

    public static function init() {
        $actions = [
            'refl_fragen_get',     // Stammfragen einer Mannschaft laden
            'refl_fragen_save',    // Stammfragen einer Mannschaft speichern (ganze Liste)
            'refl_boegen_erstellen', // Bögen für alle Schwimmer einer Mannschaft anlegen
            'refl_uebersicht',     // Übersicht aller Bögen (Status: offen/abgegeben)
            'refl_bogen_ansehen',  // Einen Bogen samt Antworten laden
            'refl_freischalten',   // Abgegebenen Bogen wieder auf 'offen' setzen
            'refl_bogen_loeschen', // Bogen löschen
            'refl_bogen_archivieren', // Bogen archivieren / aus Archiv holen
            'refl_auswertung',     // Antworten aller Bögen einer Mannschaft/Saison gebündelt
        ];
        foreach ( $actions as $a ) {
            add_action( 'wp_ajax_lsv07i_' . $a, [ __CLASS__, $a ] );
        }
    }

    private static function nonce() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
    }

    // ── Stammfragen laden ─────────────────────────────────────────────────────
    public static function refl_fragen_get() {
        self::nonce();
        LSV07I_Access::check( 'sw_refl_read' );
        $mann = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $mann ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung für diese Mannschaft.' ], 403 );
        }
        wp_send_json_success( [ 'fragen' => LSV07I_Reflexion::fragen( $mann ) ] );
    }

    // ── Stammfragen speichern (komplette Liste ersetzen) ───────────────────────
    public static function refl_fragen_save() {
        self::nonce();
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;
        $mann = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $mann ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung für diese Mannschaft.' ], 403 );
        }

        $fragen_raw = $_POST['fragen'] ?? '[]';
        $fragen = json_decode( wp_unslash( $fragen_raw ), true );
        if ( ! is_array( $fragen ) ) $fragen = [];

        // Alte Fragen dieser Mannschaft komplett ersetzen (inaktiv setzen, damit
        // Snapshots in alten Bögen erhalten bleiben — die haben fragen_snapshot).
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07i_refl_fragen WHERE mannschaft_id = %d", $mann
        ) );

        $uid = get_current_user_id();
        $sort = 0;
        foreach ( $fragen as $f ) {
            $text = trim( wp_strip_all_tags( (string) ( is_array( $f ) ? ( $f['frage'] ?? '' ) : $f ) ) );
            if ( $text === '' ) continue;
            $wpdb->insert( $p . 'lsv07i_refl_fragen', [
                'mannschaft_id' => $mann,
                'frage'         => mb_substr( $text, 0, 1000 ),
                'sort_order'    => $sort++,
                'aktiv'         => 1,
                'erstellt_von'  => $uid,
            ], [ '%d', '%s', '%d', '%d', '%d' ] );
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'reflexion.fragen.save', [
                'bereich'  => 'Reflexion',
                'ziel_typ' => 'mannschaft',
                'ziel_id'  => $mann,
                'details'  => count( $fragen ) . ' Fragen',
            ] );
        }
        wp_send_json_success( [ 'fragen' => LSV07I_Reflexion::fragen( $mann ) ] );
    }

    // ── Bögen erstellen für alle Schwimmer einer Mannschaft ────────────────────
    public static function refl_boegen_erstellen() {
        self::nonce();
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;
        $mann = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $mann ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung für diese Mannschaft.' ], 403 );
        }

        $saison_id = LSV07I_Saison::active_id();
        if ( ! $saison_id ) {
            wp_send_json_error( [ 'message' => 'Keine aktive Saison gesetzt. Bitte zuerst eine Saison aktivieren.' ] );
        }

        $fragen = LSV07I_Reflexion::fragen( $mann );
        if ( empty( $fragen ) ) {
            wp_send_json_error( [ 'message' => 'Bitte zuerst Stammfragen für diese Mannschaft anlegen.' ] );
        }

        // Schwimmer der Mannschaft holen
        $schwimmer = LSV07I_DB::get_schwimmer( $mann );
        if ( empty( $schwimmer ) ) {
            wp_send_json_error( [ 'message' => 'Keine Schwimmer in dieser Mannschaft.' ] );
        }

        $uid = get_current_user_id();
        $fragen_snapshot = wp_json_encode( $fragen );
        $erstellt = 0;
        $uebersprungen = 0;
        // Klartext-Schlüssel werden NUR in dieser Antwort zurückgegeben und
        // danach nie wieder — in der DB liegt ausschließlich der Hash.
        $neue_schluessel = [];
        // Schwimmer ohne Geburtsdatum können sich nicht einloggen (2. Faktor fehlt)
        $ohne_geburtsdatum = [];

        foreach ( $schwimmer as $s ) {
            $swimmer_id   = (int) $s['id'];
            $swimmer_name = trim( $s['first_name'] . ' ' . $s['last_name'] );

            // Existiert schon ein Bogen für diesen Schwimmer in dieser Saison?
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_refl_bogen WHERE saison_id = %d AND swimmer_id = %d",
                $saison_id, $swimmer_id
            ) );
            if ( $exists ) { $uebersprungen++; continue; }

            // Geburtsdatum vorhanden? Ohne → 2. Faktor unmöglich → Trainer warnen
            $hat_geb = ( LSV07I_Reflexion::geburtsdatum( $swimmer_id ) !== '' );
            if ( ! $hat_geb ) $ohne_geburtsdatum[] = $swimmer_name;

            // Eindeutigen Schlüssel + Token erzeugen
            $schluessel = self::eindeutiger_schluessel();
            $token = bin2hex( random_bytes( 16 ) );

            $wpdb->insert( $p . 'lsv07i_refl_bogen', [
                'saison_id'         => $saison_id,
                'mannschaft_id'     => $mann,
                'swimmer_id'        => $swimmer_id,
                'swimmer_name'      => $swimmer_name,
                'schluessel_hash'   => LSV07I_Reflexion::schluessel_hash( $schluessel ),
                'token'             => $token,
                'status'            => 'offen',
                'fragen_snapshot'   => $fragen_snapshot,
                'erstellt_von'      => $uid,
            ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ] );
            $neue_schluessel[] = [
                'swimmer_name'   => $swimmer_name,
                'schluessel'     => $schluessel, // nur jetzt im Klartext
                'kein_geburtsdatum' => ! $hat_geb,
            ];
            $erstellt++;
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'reflexion.boegen.erstellen', [
                'bereich'  => 'Reflexion',
                'ziel_typ' => 'mannschaft',
                'ziel_id'  => $mann,
                'details'  => "$erstellt erstellt, $uebersprungen übersprungen",
            ] );
        }

        wp_send_json_success( [
            'erstellt'           => $erstellt,
            'uebersprungen'      => $uebersprungen,
            'uebersicht'         => self::lade_uebersicht( $mann, $saison_id ),
            'public_link'        => self::public_link(),
            'schluessel'         => $neue_schluessel, // EINMALIG — danach nicht mehr abrufbar
            'ohne_geburtsdatum'  => $ohne_geburtsdatum,
        ] );
    }

    /** Erzeugt einen Schlüssel, dessen Hash noch nicht vergeben ist. */
    private static function eindeutiger_schluessel() {
        global $wpdb;
        $p = $wpdb->prefix;
        for ( $i = 0; $i < 20; $i++ ) {
            $k = LSV07I_Reflexion::gen_schluessel();
            $hash = LSV07I_Reflexion::schluessel_hash( $k );
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_refl_bogen WHERE schluessel_hash = %s", $hash
            ) );
            if ( ! $exists ) return $k;
        }
        // Fallback (extrem unwahrscheinlich)
        return LSV07I_Reflexion::gen_schluessel() . '-' . random_int( 10, 99 );
    }

    // ── Übersicht: alle Bögen einer Mannschaft in der aktiven Saison ───────────
    public static function refl_uebersicht() {
        self::nonce();
        LSV07I_Access::check( 'sw_refl_read' );
        $mann = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $mann ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung für diese Mannschaft.' ], 403 );
        }
        $saison_id = absint( $_POST['saison_id'] ?? 0 ) ?: LSV07I_Saison::active_id();

        wp_send_json_success( [
            'uebersicht'  => self::lade_uebersicht( $mann, $saison_id ),
            'saison_id'   => $saison_id,
            'mannschaften'=> self::meine_mannschaften_details(),
            'public_link' => self::public_link(),
        ] );
    }

    private static function lade_uebersicht( $mann, $saison_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( ! $saison_id ) return [];

        // EIN gemeinsamer Link für alle (kein personenbezogener Token).
        // Der Schlüssel ist nach dem Erstellen NICHT mehr abrufbar (nur Hash in DB).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, swimmer_id, swimmer_name,
                    status, erstellt_am, abgegeben_am, archiviert_am
               FROM {$p}lsv07i_refl_bogen
              WHERE mannschaft_id = %d AND saison_id = %d
              ORDER BY swimmer_name ASC",
            $mann, $saison_id
        ), ARRAY_A );

        return $rows;
    }

    /** Gemeinsamer öffentlicher Link (für alle Schwimmer identisch). */
    public static function public_link() {
        return add_query_arg( 'lsv07i_reflexion', '1', self::public_base_url() );
    }

    /** URL der Seite, auf der der Shortcode liegt (Fallback: Startseite). */
    private static function public_base_url() {
        $page_id = (int) get_option( 'lsv07i_reflexion_page_id', 0 );
        if ( $page_id ) {
            $url = get_permalink( $page_id );
            if ( $url ) return $url;
        }
        return home_url( '/' );
    }

    private static function meine_mannschaften_details() {
        global $wpdb;
        $p = $wpdb->prefix;
        $ids = LSV07I_Reflexion::meine_mannschaften();
        if ( empty( $ids ) ) return [];
        $in = implode( ',', array_map( 'absint', $ids ) );
        return $wpdb->get_results(
            "SELECT id, name FROM {$p}lsv07_gruppen WHERE id IN ($in) ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );
    }

    // ── Einen Bogen samt Antworten ansehen ─────────────────────────────────────
    public static function refl_bogen_ansehen() {
        self::nonce();
        LSV07I_Access::check( 'sw_refl_read' );
        global $wpdb;
        $p = $wpdb->prefix;
        $bogen_id = absint( $_POST['bogen_id'] ?? 0 );

        $bogen = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_refl_bogen WHERE id = %d", $bogen_id
        ), ARRAY_A );
        if ( ! $bogen ) wp_send_json_error( [ 'message' => 'Bogen nicht gefunden.' ] );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $bogen['mannschaft_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        $antworten = $wpdb->get_results( $wpdb->prepare(
            "SELECT typ, frage_text, antwort_text, strecke, zeit_aktuell, wunschzeit, sort_order
               FROM {$p}lsv07i_refl_antwort WHERE bogen_id = %d ORDER BY sort_order ASC, id ASC",
            $bogen_id
        ), ARRAY_A );

        // Verschlüsselte Felder fürs Anzeigen entschlüsseln
        foreach ( $antworten as &$a ) {
            if ( isset( $a['antwort_text'] ) ) $a['antwort_text'] = LSV07I_Reflexion::decrypt( $a['antwort_text'] );
            if ( isset( $a['wunschzeit'] ) )   $a['wunschzeit']   = LSV07I_Reflexion::decrypt( $a['wunschzeit'] );
        }
        unset( $a );

        wp_send_json_success( [ 'bogen' => $bogen, 'antworten' => $antworten ] );
    }

    // ── Abgegebenen Bogen wieder freischalten (für Korrekturen) ────────────────
    public static function refl_freischalten() {
        self::nonce();
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;
        $bogen_id = absint( $_POST['bogen_id'] ?? 0 );

        $bogen = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_refl_bogen WHERE id = %d", $bogen_id
        ), ARRAY_A );
        if ( ! $bogen ) wp_send_json_error( [ 'message' => 'Bogen nicht gefunden.' ] );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $bogen['mannschaft_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        $wpdb->update( $p . 'lsv07i_refl_bogen',
            [ 'status' => 'offen', 'abgegeben_am' => null ],
            [ 'id' => $bogen_id ],
            [ '%s', '%s' ], [ '%d' ]
        );
        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'reflexion.bogen.freischalten', [
                'bereich' => 'Reflexion', 'ziel_typ' => 'bogen', 'ziel_id' => $bogen_id,
                'ziel_name' => $bogen['swimmer_name'],
            ] );
        }
        wp_send_json_success();
    }

    // ── Bogen löschen ──────────────────────────────────────────────────────────
    public static function refl_bogen_loeschen() {
        self::nonce();
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;
        $bogen_id = absint( $_POST['bogen_id'] ?? 0 );

        $bogen = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_refl_bogen WHERE id = %d", $bogen_id
        ), ARRAY_A );
        if ( ! $bogen ) wp_send_json_error( [ 'message' => 'Bogen nicht gefunden.' ] );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $bogen['mannschaft_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        $wpdb->delete( $p . 'lsv07i_refl_antwort', [ 'bogen_id' => $bogen_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_refl_bogen', [ 'id' => $bogen_id ], [ '%d' ] );
        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'reflexion.bogen.loeschen', [
                'bereich' => 'Reflexion', 'ziel_typ' => 'bogen', 'ziel_id' => $bogen_id,
                'ziel_name' => $bogen['swimmer_name'],
            ] );
        }
        wp_send_json_success();
    }

    // ── Bogen archivieren / aus Archiv zurückholen ─────────────────────────────
    public static function refl_bogen_archivieren() {
        self::nonce();
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;
        $bogen_id = absint( $_POST['bogen_id'] ?? 0 );
        // on=1 → archivieren, on=0 → zurückholen
        $archivieren = ! empty( $_POST['on'] );

        $bogen = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_refl_bogen WHERE id = %d", $bogen_id
        ), ARRAY_A );
        if ( ! $bogen ) wp_send_json_error( [ 'message' => 'Bogen nicht gefunden.' ] );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $bogen['mannschaft_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        if ( $archivieren ) {
            $wpdb->update( $p . 'lsv07i_refl_bogen',
                [ 'status' => 'archiviert', 'archiviert_am' => current_time( 'mysql' ) ],
                [ 'id' => $bogen_id ],
                [ '%s', '%s' ], [ '%d' ]
            );
        } else {
            // Zurückholen: war es abgegeben (abgegeben_am gesetzt) → 'abgegeben', sonst 'offen'
            $neuer_status = ! empty( $bogen['abgegeben_am'] ) ? 'abgegeben' : 'offen';
            $wpdb->update( $p . 'lsv07i_refl_bogen',
                [ 'status' => $neuer_status, 'archiviert_am' => null ],
                [ 'id' => $bogen_id ],
                [ '%s', '%s' ], [ '%d' ]
            );
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'reflexion.bogen.' . ( $archivieren ? 'archivieren' : 'entarchivieren' ), [
                'bereich' => 'Reflexion', 'ziel_typ' => 'bogen', 'ziel_id' => $bogen_id,
                'ziel_name' => $bogen['swimmer_name'],
            ] );
        }
        wp_send_json_success();
    }

    /**
     * Auswertung: alle Antworten der abgegebenen Boegen einer Mannschaft/
     * Saison, gebuendelt pro Frage. Die Antworten sind Freitext — es gibt
     * daher bewusst keine Durchschnitte, sondern die Antworten je Frage
     * untereinander (mit Schwimmer-Name), um Muster erkennbar zu machen.
     */
    public static function refl_auswertung() {
        self::nonce();
        LSV07I_Access::check( 'sw_refl_read' );
        $mann = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! LSV07I_Reflexion::darf_mannschaft( $mann ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung für diese Mannschaft.' ], 403 );
        }
        $saison_id = absint( $_POST['saison_id'] ?? 0 ) ?: LSV07I_Saison::active_id();

        global $wpdb;
        $p = $wpdb->prefix;

        $gesamt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_refl_bogen
              WHERE mannschaft_id = %d AND saison_id = %d",
            $mann, $saison_id ) );
        $abgegeben = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_refl_bogen
              WHERE mannschaft_id = %d AND saison_id = %d AND status = 'abgegeben'",
            $mann, $saison_id ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ant.frage_text, ant.antwort_text, b.swimmer_name
               FROM {$p}lsv07i_refl_antwort ant
               JOIN {$p}lsv07i_refl_bogen b ON b.id = ant.bogen_id
              WHERE b.mannschaft_id = %d AND b.saison_id = %d
                AND b.status = 'abgegeben' AND ant.typ = 'frage'
           ORDER BY ant.frage_id ASC, b.swimmer_name ASC",
            $mann, $saison_id ), ARRAY_A );

        // Nach Frage gruppieren (Reihenfolge der ersten Nennung beibehalten)
        $fragen = [];
        foreach ( (array) $rows as $r ) {
            $f = trim( (string) $r['frage_text'] );
            if ( $f === '' ) continue;
            if ( ! isset( $fragen[ $f ] ) ) {
                $fragen[ $f ] = [ 'frage' => $f, 'antworten' => [] ];
            }
            $fragen[ $f ]['antworten'][] = [
                'name' => $r['swimmer_name'],
                'text' => (string) $r['antwort_text'],
            ];
        }

        wp_send_json_success( [
            'saison_id' => $saison_id,
            'gesamt'    => $gesamt,
            'abgegeben' => $abgegeben,
            'fragen'    => array_values( $fragen ),
        ] );
    }
}
