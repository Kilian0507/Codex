<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Tickets
 * ------------------
 * Vereinsinternes Ticketsystem.
 *
 * Designprinzipien:
 *  - Jeder eingeloggte Nutzer kann Tickets erstellen und sieht standardmäßig
 *    NUR seine eigenen Tickets.
 *  - Nutzer mit Permission TICKETS_READ_ALL sehen alle Tickets (Bearbeiter).
 *  - Nachrichten und Anhänge funktionieren wie ein Chat-Verlauf — der
 *    Ticket-Body ist die erste Nachricht, alle Folge-Einträge sind
 *    Kommentare oder System-Events (Status, Zuweisung).
 *  - Status-Änderungen werden als System-Event-Kommentar dokumentiert
 *    UND zusätzlich ins zentrale Log geschrieben.
 *  - Anhänge: Bilder und Dokumente. Bilder werden im Frontend inline
 *    gerendert, andere Dateien als Download-Link. Dateien werden
 *    geschützt unter wp-content/uploads/lsv07i-private/tickets gelagert.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Tickets {

    const KATEGORIEN = [
        'frage'         => 'Frage / Hilfe',
        'verbesserung'  => 'Verbesserungsvorschlag / Idee',
        'bug'           => 'Bug / Fehler im Plugin',
        'organisation'  => 'Organisatorisches',
        'schwimmer'     => 'Schwimmer hinzufügen oder verschieben',
        'datenaenderung'=> 'Daten ändern',
    ];

    const PRIORITAETEN = [
        'niedrig'  => 'Niedrig',
        'normal'   => 'Normal',
        'hoch'     => 'Hoch',
        'dringend' => 'Dringend',
    ];

    const STATI = [
        'offen'       => 'Offen',
        'in_arbeit'   => 'In Arbeit',
        'wartet'      => 'Wartet auf Rückmeldung',
        'erledigt'    => 'Erledigt',
        'geschlossen' => 'Geschlossen',
    ];

    // Erlaubte Anhang-Typen
    const ALLOWED_MIME = [
        'image/jpeg'        => 'jpg',
        'image/png'         => 'png',
        'image/gif'         => 'gif',
        'image/webp'        => 'webp',
        'application/pdf'   => 'pdf',
        'text/plain'        => 'txt',
        'application/msword'                              => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel'                        => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'      => 'xlsx',
    ];
    const MAX_SIZE = 10 * 1024 * 1024; // 10 MB

    public static function init() {
        add_action( 'wp_ajax_lsv07i_tickets_list',        [ __CLASS__, 'list_tickets' ] );
        add_action( 'wp_ajax_lsv07i_tickets_get',         [ __CLASS__, 'get_ticket'   ] );
        add_action( 'wp_ajax_lsv07i_tickets_create',      [ __CLASS__, 'create'       ] );
        add_action( 'wp_ajax_lsv07i_tickets_comment',     [ __CLASS__, 'add_comment'  ] );
        add_action( 'wp_ajax_lsv07i_tickets_status',      [ __CLASS__, 'set_status'   ] );
        add_action( 'wp_ajax_lsv07i_tickets_assign',      [ __CLASS__, 'set_assignee' ] );
        add_action( 'wp_ajax_lsv07i_tickets_delete',      [ __CLASS__, 'delete'       ] );
        add_action( 'wp_ajax_lsv07i_tickets_upload',      [ __CLASS__, 'upload'       ] );
        // Anhang-Download über separaten Endpunkt (gibt Datei direkt aus)
        add_action( 'wp_ajax_lsv07i_tickets_anhang_dl',   [ __CLASS__, 'anhang_dl'    ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Prüft Nonce UND dass der Nutzer überhaupt Zugang zum internen Bereich
     * hat — bisher wurde hier nur is_user_logged_in() geprüft, wodurch
     * JEDER angemeldete WordPress-Nutzer (auch ohne jede Berechtigung im
     * LSV07-Rechtesystem, z.B. ein reiner Blog-Kommentator) das Ticket-
     * System nutzen konnte. Beendet mit 403 bei Fehlschlag.
     */
    private static function check_nonce() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Sitzung abgelaufen. Bitte Seite neu laden.' ], 403 );
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Nicht angemeldet.' ], 403 );
        }
        if ( ! LSV07I_Access::has_any_access() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung für den internen Bereich.' ], 403 );
        }
    }

    /** Sieht der aktuelle Nutzer alle Tickets (Bearbeiter) oder nur seine eigenen? */
    private static function sieht_alle() {
        if ( ! class_exists( 'LSV07I_Permissions' ) ) {
            return current_user_can( 'administrator' );
        }
        return LSV07I_Permissions::can_current( LSV07I_Permissions::TICKETS_READ_ALL );
    }

    /** Darf der Nutzer auf fremden Tickets antworten? */
    private static function darf_kommentar_fremd() {
        if ( ! class_exists( 'LSV07I_Permissions' ) ) {
            return current_user_can( 'administrator' );
        }
        return LSV07I_Permissions::can_current( LSV07I_Permissions::TICKETS_COMMENT_ANY );
    }

    /** Darf der Nutzer Status fremder Tickets ändern? */
    private static function darf_status_fremd() {
        if ( ! class_exists( 'LSV07I_Permissions' ) ) {
            return current_user_can( 'administrator' );
        }
        return LSV07I_Permissions::can_current( LSV07I_Permissions::TICKETS_STATUS_CHANGE );
    }

    /** Darf der Nutzer Tickets zuweisen? */
    private static function darf_zuweisen() {
        if ( ! class_exists( 'LSV07I_Permissions' ) ) {
            return current_user_can( 'administrator' );
        }
        return LSV07I_Permissions::can_current( LSV07I_Permissions::TICKETS_ASSIGN );
    }

    /** Darf der Nutzer Tickets löschen? */
    private static function darf_loeschen() {
        if ( ! class_exists( 'LSV07I_Permissions' ) ) {
            return current_user_can( 'administrator' );
        }
        return LSV07I_Permissions::can_current( LSV07I_Permissions::TICKETS_DELETE );
    }

    /** Anzeigename eines Users. */
    private static function user_label( $uid ) {
        if ( ! $uid ) return '–';
        $u = get_user_by( 'id', (int) $uid );
        if ( ! $u ) return '(unbekannt)';
        return $u->display_name ?: $u->user_login;
    }

    /** Speicherpfad für Anhänge (geschützt). */
    private static function uploads_dir() {
        $up = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/tickets';
        if ( ! is_dir( $base ) ) {
            wp_mkdir_p( $base );
            // .htaccess + index.html zum Schutz (analog zu schwimmer-dateien)
            $parent = dirname( $base );
            if ( ! file_exists( $parent . '/.htaccess' ) ) {
                @file_put_contents( $parent . '/.htaccess',
                    "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
            }
            if ( ! file_exists( $parent . '/index.html' ) ) {
                @file_put_contents( $parent . '/index.html', '<!-- silence -->' );
            }
        }
        return $base;
    }

    /** Nächste Ticket-Nummer im Format JJJJ-NNN (laufende Nummer pro Jahr). */
    private static function naechste_nummer() {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'lsv07i_tickets';
        $jahr = date( 'Y' );
        $last = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING_INDEX(nummer, '-', -1) AS UNSIGNED))
               FROM $tbl
              WHERE nummer LIKE %s",
            "$jahr-%"
        ) );
        return sprintf( '%s-%03d', $jahr, $last + 1 );
    }

    /** Anhänge zu Tickets/Kommentaren laden (Bulk). */
    private static function load_anhaenge( $ticket_id, $kommentar_ids = [] ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, ticket_id, kommentar_id, hochgeladen_von, dateiname,
                    mime_type, groesse, erstellt_am
               FROM {$p}lsv07i_ticket_anhang
              WHERE ticket_id = %d
           ORDER BY erstellt_am ASC",
            $ticket_id
        ), ARRAY_A );
        $out = [];
        foreach ( $rows as $r ) {
            $r['id']         = (int) $r['id'];
            $r['kommentar_id'] = $r['kommentar_id'] ? (int) $r['kommentar_id'] : null;
            $r['ist_bild']   = strpos( $r['mime_type'], 'image/' ) === 0;
            $r['dl_url']     = admin_url( 'admin-ajax.php' )
                             . '?action=lsv07i_tickets_anhang_dl&id=' . (int) $r['id']
                             . '&nonce=' . wp_create_nonce( 'lsv07i_nonce' );
            $out[] = $r;
        }
        return $out;
    }

    /** Status-Änderung als System-Event in den Verlauf schreiben. */
    private static function write_system_event( $ticket_id, $autor_uid, $text ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'lsv07i_ticket_kommentare', [
            'ticket_id'        => $ticket_id,
            'autor_user_id'    => $autor_uid,
            'text'             => $text,
            'ist_system_event' => 1,
        ], [ '%d', '%d', '%s', '%d' ] );
        return (int) $wpdb->insert_id;
    }

    // ══ Endpunkte ═════════════════════════════════════════════════════════════

    public static function list_tickets() {
        self::check_nonce();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();
        $status_filter = sanitize_text_field( $_POST['status'] ?? '' );

        // Sichtbarkeit: alle vs. nur eigene
        $where = '1=1';
        $args  = [];
        if ( ! self::sieht_alle() ) {
            $where .= ' AND ersteller_user_id = %d';
            $args[] = $uid;
        }
        if ( $status_filter && isset( self::STATI[ $status_filter ] ) ) {
            $where .= ' AND status = %s';
            $args[] = $status_filter;
        }

        $sql = "SELECT id, nummer, titel, kategorie, prioritaet, status,
                       ersteller_user_id, zugewiesen_an,
                       erstellt_am, aktualisiert_am, geschlossen_am
                  FROM {$p}lsv07i_tickets
                 WHERE $where
              ORDER BY aktualisiert_am DESC
                 LIMIT 200";
        $rows = $args
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        // Bulk: User-Labels + Kommentar-Anzahl
        $all_uids = [];
        foreach ( $rows as $r ) {
            if ( ! empty( $r['ersteller_user_id'] ) ) $all_uids[ (int) $r['ersteller_user_id'] ] = true;
            if ( ! empty( $r['zugewiesen_an'] ) )     $all_uids[ (int) $r['zugewiesen_an'] ]     = true;
        }
        $user_map = [];
        if ( ! empty( $all_uids ) ) {
            $uids_in = implode( ',', array_keys( $all_uids ) );
            $u_rows = $wpdb->get_results(
                "SELECT ID, user_login, display_name FROM {$wpdb->users} WHERE ID IN ($uids_in)", ARRAY_A );
            foreach ( $u_rows as $u ) {
                $user_map[ (int) $u['ID'] ] = $u['display_name'] ?: $u['user_login'];
            }
        }

        $msg_counts = [];
        if ( ! empty( $rows ) ) {
            $tids = array_filter( array_map( fn( $r ) => (int) $r['id'], $rows ), fn( $i ) => $i > 0 );
            if ( ! empty( $tids ) ) {
                $tids_in = implode( ',', $tids );
                $cnt = $wpdb->get_results(
                    "SELECT ticket_id, COUNT(*) AS anz
                       FROM {$p}lsv07i_ticket_kommentare
                      WHERE ticket_id IN ($tids_in) AND ist_system_event = 0
                   GROUP BY ticket_id", ARRAY_A );
                foreach ( $cnt as $c ) $msg_counts[ (int) $c['ticket_id'] ] = (int) $c['anz'];
            }
        }

        foreach ( $rows as &$r ) {
            $r['id']                  = (int) $r['id'];
            $r['ersteller_name']      = $user_map[ (int) $r['ersteller_user_id'] ] ?? '–';
            $r['zugewiesen_an_name']  = ! empty( $r['zugewiesen_an'] ) ? ( $user_map[ (int) $r['zugewiesen_an'] ] ?? '(unbekannt)' ) : '';
            $r['anzahl_kommentare']   = (int) ( $msg_counts[ (int) $r['id'] ] ?? 0 );
            $r['kategorie_label']     = self::KATEGORIEN[ $r['kategorie'] ] ?? $r['kategorie'];
            $r['prioritaet_label']    = self::PRIORITAETEN[ $r['prioritaet'] ] ?? $r['prioritaet'];
            $r['status_label']        = self::STATI[ $r['status'] ] ?? $r['status'];
        }
        unset( $r );

        wp_send_json_success( [
            'tickets'      => $rows,
            'sieht_alle'   => self::sieht_alle(),
            'kategorien'   => self::KATEGORIEN,
            'prioritaeten' => self::PRIORITAETEN,
            'stati'        => self::STATI,
        ] );
    }

    public static function get_ticket() {
        self::check_nonce();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();
        $id  = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Ticket-ID fehlt.' ] );

        $t = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_tickets WHERE id = %d", $id ), ARRAY_A );
        if ( ! $t ) wp_send_json_error( [ 'message' => 'Ticket nicht gefunden.' ] );

        // Zugriffsprüfung: eigene Tickets ODER Permission TICKETS_READ_ALL
        if ( (int) $t['ersteller_user_id'] !== $uid && ! self::sieht_alle() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        $t['id'] = (int) $t['id'];
        $t['ersteller_user_id'] = (int) $t['ersteller_user_id'];
        $t['zugewiesen_an']     = $t['zugewiesen_an'] ? (int) $t['zugewiesen_an'] : null;
        $t['ersteller_name']    = self::user_label( $t['ersteller_user_id'] );
        $t['zugewiesen_an_name']= $t['zugewiesen_an'] ? self::user_label( $t['zugewiesen_an'] ) : '';
        $t['kategorie_label']   = self::KATEGORIEN[ $t['kategorie'] ] ?? $t['kategorie'];
        $t['prioritaet_label']  = self::PRIORITAETEN[ $t['prioritaet'] ] ?? $t['prioritaet'];
        $t['status_label']      = self::STATI[ $t['status'] ] ?? $t['status'];

        // Kommentare laden
        $komms = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, autor_user_id, text, ist_system_event, erstellt_am
               FROM {$p}lsv07i_ticket_kommentare
              WHERE ticket_id = %d
           ORDER BY erstellt_am ASC, id ASC", $id
        ), ARRAY_A );

        foreach ( $komms as &$k ) {
            $k['id']               = (int) $k['id'];
            $k['autor_user_id']    = (int) $k['autor_user_id'];
            $k['autor_name']       = self::user_label( $k['autor_user_id'] );
            $k['ist_system_event'] = (int) $k['ist_system_event'];
        }
        unset( $k );

        // Anhänge zum Ticket laden und auf Kommentare verteilen
        $anhaenge = self::load_anhaenge( $id );
        $by_kid = [];
        $orphan = [];
        foreach ( $anhaenge as $a ) {
            if ( $a['kommentar_id'] ) $by_kid[ $a['kommentar_id'] ][] = $a;
            else $orphan[] = $a;
        }
        foreach ( $komms as &$k ) {
            $k['anhaenge'] = $by_kid[ $k['id'] ] ?? [];
        }
        unset( $k );

        // User-Liste für die Zuweisen-Auswahl (nur Bearbeiter)
        $users_for_assign = [];
        if ( self::darf_zuweisen() ) {
            $users = get_users( [ 'orderby' => 'display_name', 'fields' => [ 'ID', 'display_name', 'user_login' ] ] );
            foreach ( $users as $u ) {
                $users_for_assign[] = [
                    'id'    => (int) $u->ID,
                    'label' => $u->display_name ?: $u->user_login,
                ];
            }
        }

        wp_send_json_success( [
            'ticket'    => $t,
            'kommentare'=> $komms,
            'orphan_anhaenge' => $orphan,  // sollte nicht vorkommen, defensive
            'rechte'    => [
                'kann_status'   => ( (int) $t['ersteller_user_id'] === $uid ) || self::darf_status_fremd(),
                'kann_kommentar'=> ( (int) $t['ersteller_user_id'] === $uid ) || self::darf_kommentar_fremd(),
                'kann_zuweisen' => self::darf_zuweisen(),
                'kann_loeschen' => self::darf_loeschen(),
            ],
            'users_for_assign' => $users_for_assign,
            'stati'     => self::STATI,
        ] );
    }

    public static function create() {
        self::check_nonce();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $titel        = sanitize_text_field( $_POST['titel'] ?? '' );
        $beschreibung = wp_kses_post( wp_unslash( $_POST['beschreibung'] ?? '' ) );
        $kategorie    = sanitize_text_field( $_POST['kategorie'] ?? 'frage' );
        $prioritaet   = sanitize_text_field( $_POST['prioritaet'] ?? 'normal' );

        if ( $titel === '' ) wp_send_json_error( [ 'message' => 'Titel ist Pflicht.' ] );
        if ( ! isset( self::KATEGORIEN[ $kategorie ] ) ) $kategorie = 'frage';
        if ( ! isset( self::PRIORITAETEN[ $prioritaet ] ) ) $prioritaet = 'normal';

        $nummer = self::naechste_nummer();
        $wpdb->insert( $p . 'lsv07i_tickets', [
            'nummer'            => $nummer,
            'titel'             => $titel,
            'beschreibung'      => $beschreibung,
            'kategorie'         => $kategorie,
            'prioritaet'        => $prioritaet,
            'status'            => 'offen',
            'ersteller_user_id' => $uid,
        ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Ticket konnte nicht angelegt werden.' ] );

        // Erste Nachricht: die Beschreibung als regulärer Kommentar (ist_system_event=0)
        // — so taucht sie automatisch im Verlauf auf
        if ( $beschreibung !== '' ) {
            $wpdb->insert( $p . 'lsv07i_ticket_kommentare', [
                'ticket_id'        => $id,
                'autor_user_id'    => $uid,
                'text'             => $beschreibung,
                'ist_system_event' => 0,
            ], [ '%d', '%d', '%s', '%d' ] );
            $erster_kommentar_id = (int) $wpdb->insert_id;
        } else {
            $erster_kommentar_id = 0;
        }

        // Beim Create hochgeladene Anhang-IDs an den ersten Kommentar binden
        $anhang_ids = isset( $_POST['anhang_ids'] )
            ? array_filter( array_map( 'absint', (array) $_POST['anhang_ids'] ), fn( $i ) => $i > 0 )
            : [];
        if ( $anhang_ids ) {
            $ids_in = implode( ',', $anhang_ids );
            // Nur Anhänge des aktuellen Nutzers, die noch keinem Ticket zugewiesen sind
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}lsv07i_ticket_anhang
                    SET ticket_id = %d, kommentar_id = %d
                  WHERE id IN ($ids_in)
                    AND hochgeladen_von = %d
                    AND ticket_id = 0",
                $id, $erster_kommentar_id ?: null, $uid
            ) );
        }

        LSV07I_Log::write( 'ticket.create', [
            'bereich'   => 'Tickets',
            'ziel_typ'  => 'ticket',
            'ziel_id'   => $id,
            'ziel_name' => $nummer . ' — ' . $titel,
        ] );

        wp_send_json_success( [ 'id' => $id, 'nummer' => $nummer ] );
    }

    public static function add_comment() {
        self::check_nonce();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
        $text      = wp_kses_post( wp_unslash( $_POST['text'] ?? '' ) );
        $anhang_ids = isset( $_POST['anhang_ids'] )
            ? array_filter( array_map( 'absint', (array) $_POST['anhang_ids'] ), fn( $i ) => $i > 0 )
            : [];

        if ( ! $ticket_id ) wp_send_json_error( [ 'message' => 'Ticket-ID fehlt.' ] );
        if ( $text === '' && empty( $anhang_ids ) ) {
            wp_send_json_error( [ 'message' => 'Nachricht oder Anhang erforderlich.' ] );
        }

        $t = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, ersteller_user_id, titel, nummer FROM {$p}lsv07i_tickets WHERE id = %d",
            $ticket_id
        ), ARRAY_A );
        if ( ! $t ) wp_send_json_error( [ 'message' => 'Ticket nicht gefunden.' ] );

        // Zugriffsprüfung
        $ist_eigen = ( (int) $t['ersteller_user_id'] === $uid );
        if ( ! $ist_eigen && ! self::darf_kommentar_fremd() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung, in dieses Ticket zu schreiben.' ], 403 );
        }

        $wpdb->insert( $p . 'lsv07i_ticket_kommentare', [
            'ticket_id'        => $ticket_id,
            'autor_user_id'    => $uid,
            'text'             => $text,
            'ist_system_event' => 0,
        ], [ '%d', '%d', '%s', '%d' ] );
        $kid = (int) $wpdb->insert_id;

        // aktualisiert_am triggert automatisch durch ON UPDATE CURRENT_TIMESTAMP — wir
        // erzwingen es per dummy-Update, damit der Schreiber-Trigger auch ohne Spaltenänderung greift
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}lsv07i_tickets SET aktualisiert_am = CURRENT_TIMESTAMP WHERE id = %d",
            $ticket_id
        ) );

        // Anhänge an Kommentar binden
        if ( $anhang_ids && $kid ) {
            $ids_in = implode( ',', $anhang_ids );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}lsv07i_ticket_anhang
                    SET ticket_id = %d, kommentar_id = %d
                  WHERE id IN ($ids_in)
                    AND hochgeladen_von = %d
                    AND ticket_id = 0",
                $ticket_id, $kid, $uid
            ) );
        }

        LSV07I_Log::write( 'ticket.comment', [
            'bereich'   => 'Tickets',
            'ziel_typ'  => 'ticket',
            'ziel_id'   => $ticket_id,
            'ziel_name' => $t['nummer'] . ' — ' . $t['titel'],
        ] );

        wp_send_json_success( [ 'kommentar_id' => $kid ] );
    }

    public static function set_status() {
        self::check_nonce();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
        $status    = sanitize_text_field( $_POST['status'] ?? '' );
        if ( ! $ticket_id || ! isset( self::STATI[ $status ] ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Eingabe.' ] );
        }

        $t = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, ersteller_user_id, status, titel, nummer FROM {$p}lsv07i_tickets WHERE id = %d",
            $ticket_id
        ), ARRAY_A );
        if ( ! $t ) wp_send_json_error( [ 'message' => 'Ticket nicht gefunden.' ] );

        $ist_eigen = ( (int) $t['ersteller_user_id'] === $uid );
        if ( ! $ist_eigen && ! self::darf_status_fremd() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        if ( $t['status'] === $status ) {
            wp_send_json_success( [ 'unchanged' => true ] );
        }

        $update = [ 'status' => $status ];
        $formats = [ '%s' ];
        if ( in_array( $status, [ 'erledigt', 'geschlossen' ], true ) ) {
            $update['geschlossen_am'] = current_time( 'mysql' );
            $formats[] = '%s';
        } else {
            // Wieder offen → geschlossen_am zurücksetzen
            $update['geschlossen_am'] = null;
            $formats[] = '%s';
        }
        $wpdb->update( $p . 'lsv07i_tickets', $update, [ 'id' => $ticket_id ], $formats, [ '%d' ] );

        // System-Event in den Verlauf
        $alt_label = self::STATI[ $t['status'] ] ?? $t['status'];
        $neu_label = self::STATI[ $status ] ?? $status;
        self::write_system_event(
            $ticket_id, $uid,
            'Status geändert: ' . $alt_label . ' → ' . $neu_label
        );

        LSV07I_Log::write( 'ticket.status', [
            'bereich'   => 'Tickets',
            'ziel_typ'  => 'ticket',
            'ziel_id'   => $ticket_id,
            'ziel_name' => $t['nummer'] . ' — ' . $t['titel'],
            'detail'    => $alt_label . ' → ' . $neu_label,
        ] );

        wp_send_json_success( [ 'status' => $status ] );
    }

    public static function set_assignee() {
        self::check_nonce();
        if ( ! self::darf_zuweisen() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
        $zuw       = absint( $_POST['zugewiesen_an'] ?? 0 );

        $t = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, zugewiesen_an, titel, nummer FROM {$p}lsv07i_tickets WHERE id = %d",
            $ticket_id
        ), ARRAY_A );
        if ( ! $t ) wp_send_json_error( [ 'message' => 'Ticket nicht gefunden.' ] );

        if ( (int) $t['zugewiesen_an'] === $zuw ) {
            wp_send_json_success( [ 'unchanged' => true ] );
        }

        $wpdb->update( $p . 'lsv07i_tickets',
            [ 'zugewiesen_an' => $zuw ?: null ],
            [ 'id' => $ticket_id ],
            [ '%d' ], [ '%d' ]
        );

        $alt = $t['zugewiesen_an'] ? self::user_label( $t['zugewiesen_an'] ) : '–';
        $neu = $zuw ? self::user_label( $zuw ) : '–';
        self::write_system_event(
            $ticket_id, $uid,
            'Zuweisung geändert: ' . $alt . ' → ' . $neu
        );

        LSV07I_Log::write( 'ticket.assign', [
            'bereich'   => 'Tickets',
            'ziel_typ'  => 'ticket',
            'ziel_id'   => $ticket_id,
            'ziel_name' => $t['nummer'] . ' — ' . $t['titel'],
            'detail'    => $alt . ' → ' . $neu,
        ] );

        wp_send_json_success( [ 'zugewiesen_an' => $zuw ?: null ] );
    }

    public static function delete() {
        self::check_nonce();
        if ( ! self::darf_loeschen() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;

        $ticket_id = absint( $_POST['ticket_id'] ?? 0 );
        $t = $wpdb->get_row( $wpdb->prepare(
            "SELECT nummer, titel FROM {$p}lsv07i_tickets WHERE id = %d", $ticket_id ), ARRAY_A );
        if ( ! $t ) wp_send_json_error( [ 'message' => 'Ticket nicht gefunden.' ] );

        // Anhang-Dateien physisch löschen
        $anhaenge = $wpdb->get_col( $wpdb->prepare(
            "SELECT gespeichert_als FROM {$p}lsv07i_ticket_anhang WHERE ticket_id = %d",
            $ticket_id
        ) );
        $dir = self::uploads_dir();
        foreach ( $anhaenge as $fn ) {
            $path = $dir . '/' . $fn;
            if ( $fn && file_exists( $path ) ) @unlink( $path );
        }

        $wpdb->delete( $p . 'lsv07i_ticket_anhang',     [ 'ticket_id' => $ticket_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_ticket_kommentare', [ 'ticket_id' => $ticket_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_tickets',           [ 'id'        => $ticket_id ], [ '%d' ] );

        LSV07I_Log::write( 'ticket.delete', [
            'bereich'   => 'Tickets',
            'ziel_typ'  => 'ticket',
            'ziel_id'   => $ticket_id,
            'ziel_name' => $t['nummer'] . ' — ' . $t['titel'],
        ] );

        wp_send_json_success( [ 'deleted' => true ] );
    }

    /**
     * Upload eines Anhangs. Wird VOR dem Erstellen eines Tickets / Kommentars
     * aufgerufen — das Frontend bekommt die Anhang-ID zurück und sendet sie
     * dann beim create/add_comment mit. Solange ticket_id = 0 bleibt, ist
     * der Anhang noch "verwaist" und gehört nur dem hochladenden Nutzer.
     */
    public static function upload() {
        self::check_nonce();
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();

        if ( empty( $_FILES['datei']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => 'Keine Datei empfangen.' ] );
        }
        $err = (int) ( $_FILES['datei']['error'] ?? UPLOAD_ERR_NO_FILE );
        if ( $err !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'Upload-Fehler Code ' . $err ] );
        }

        $name = sanitize_file_name( $_FILES['datei']['name'] );
        $tmp  = $_FILES['datei']['tmp_name'];
        $size = (int) $_FILES['datei']['size'];

        if ( $size > self::MAX_SIZE ) {
            wp_send_json_error( [ 'message' => 'Datei zu groß (max. 10 MB).' ] );
        }

        // MIME erkennen (anstatt dem Client-Wert zu vertrauen)
        $mime = function_exists( 'mime_content_type' )
            ? mime_content_type( $tmp )
            : ( $_FILES['datei']['type'] ?? '' );
        if ( ! isset( self::ALLOWED_MIME[ $mime ] ) ) {
            wp_send_json_error( [ 'message' => 'Dateityp nicht erlaubt: ' . $mime ] );
        }
        $ext = self::ALLOWED_MIME[ $mime ];

        // Eindeutiger Dateiname
        $target_name = wp_generate_uuid4() . '.' . $ext;
        $dir         = self::uploads_dir();
        $target_path = $dir . '/' . $target_name;

        if ( ! @move_uploaded_file( $tmp, $target_path ) ) {
            wp_send_json_error( [ 'message' => 'Datei konnte nicht gespeichert werden.' ] );
        }
        @chmod( $target_path, 0644 );

        $wpdb->insert( $p . 'lsv07i_ticket_anhang', [
            'ticket_id'       => 0,           // wird später beim create/comment gebunden
            'kommentar_id'    => null,
            'hochgeladen_von' => $uid,
            'dateiname'       => $name,
            'gespeichert_als' => $target_name,
            'mime_type'       => $mime,
            'groesse'         => $size,
        ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ] );
        $id = (int) $wpdb->insert_id;

        wp_send_json_success( [
            'id'        => $id,
            'dateiname' => $name,
            'mime_type' => $mime,
            'groesse'   => $size,
            'ist_bild'  => strpos( $mime, 'image/' ) === 0,
            'dl_url'    => admin_url( 'admin-ajax.php' )
                          . '?action=lsv07i_tickets_anhang_dl&id=' . $id
                          . '&nonce=' . wp_create_nonce( 'lsv07i_nonce' ),
        ] );
    }

    /** Anhang-Download mit Berechtigungsprüfung. */
    public static function anhang_dl() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_die( 'Sitzung abgelaufen.', 'Fehler', [ 'response' => 403 ] );
        }
        if ( ! is_user_logged_in() ) {
            wp_die( 'Nicht angemeldet.', 'Fehler', [ 'response' => 403 ] );
        }
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = get_current_user_id();
        $id  = absint( $_GET['id'] ?? 0 );
        if ( ! $id ) wp_die( 'ID fehlt.', 'Fehler', [ 'response' => 400 ] );

        $a = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, t.ersteller_user_id
               FROM {$p}lsv07i_ticket_anhang a
          LEFT JOIN {$p}lsv07i_tickets t ON t.id = a.ticket_id
              WHERE a.id = %d", $id ), ARRAY_A );
        if ( ! $a ) wp_die( 'Anhang nicht gefunden.', 'Fehler', [ 'response' => 404 ] );

        // Zugriff:
        //  - Hochlader (auch wenn ticket_id=0, also vor dem Speichern)
        //  - Ersteller des zugehörigen Tickets
        //  - Nutzer mit TICKETS_READ_ALL
        $erlaubt = false;
        if ( (int) $a['hochgeladen_von'] === $uid ) $erlaubt = true;
        elseif ( $a['ticket_id'] && (int) $a['ersteller_user_id'] === $uid ) $erlaubt = true;
        elseif ( self::sieht_alle() ) $erlaubt = true;
        if ( ! $erlaubt ) wp_die( 'Keine Berechtigung.', 'Fehler', [ 'response' => 403 ] );

        $path = self::uploads_dir() . '/' . $a['gespeichert_als'];
        if ( ! file_exists( $path ) ) wp_die( 'Datei fehlt auf Server.', 'Fehler', [ 'response' => 404 ] );

        // Bei Bildern inline anzeigen, sonst als Download
        $is_image = strpos( $a['mime_type'], 'image/' ) === 0;
        nocache_headers();
        header( 'Content-Type: ' . $a['mime_type'] );
        header( 'Content-Length: ' . filesize( $path ) );
        if ( $is_image ) {
            header( 'Content-Disposition: inline; filename="' . rawurlencode( $a['dateiname'] ) . '"' );
        } else {
            header( 'Content-Disposition: attachment; filename="' . rawurlencode( $a['dateiname'] ) . '"' );
        }
        readfile( $path );
        exit;
    }
}
