<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Konversation — zentrale Operationen am neuen Nachrichten-Modell.
 *
 * Vier Aktions-Bereiche:
 *  - Konversationen anlegen/listen
 *  - Teilnehmer hinzufügen/entfernen
 *  - Nachrichten senden/abrufen
 *  - Ungelesen-Zähler
 *
 * Externe Empfänger (Eltern via Kontaktperson oder freie E-Mail) werden hier
 * als reguläre Teilnehmer geführt — der Versand-Mechanismus für sie liegt in
 * LSV07I_Email_Versand (Iter 3).
 */
class LSV07I_Konversation {

    /**
     * Konversation anlegen.
     *
     * @param string $typ   'direkt' | 'gruppe' | 'verteiler'
     * @param array  $opts  ['name', 'mannschaft_id', 'mannschaft_sparte', 'auto_typ', 'teilnehmer']
     * @return int|WP_Error  Konv-ID bei Erfolg
     */
    public static function create( $typ, array $opts = [] ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_konv';

        if ( ! in_array( $typ, [ 'direkt', 'gruppe', 'verteiler' ], true ) ) {
            return new WP_Error( 'invalid_typ', 'Ungültiger Konversations-Typ.' );
        }

        $data = [
            'typ'              => $typ,
            'name'             => isset( $opts['name'] ) ? mb_substr( (string) $opts['name'], 0, 200 ) : null,
            'mannschaft_id'    => isset( $opts['mannschaft_id'] ) ? (int) $opts['mannschaft_id'] : null,
            'mannschaft_sparte'=> isset( $opts['mannschaft_sparte'] ) ? (string) $opts['mannschaft_sparte'] : null,
            'auto_typ'         => isset( $opts['auto_typ'] ) ? (string) $opts['auto_typ'] : null,
            'erstellt_von'     => get_current_user_id(),
            'letzte_aktivitaet'=> current_time( 'mysql' ),
        ];
        $result = $wpdb->insert( $tbl, $data, [ '%s', '%s', '%d', '%s', '%s', '%d', '%s' ] );
        if ( $result === false ) {
            do_action( 'lsv07i_db_error_log', $wpdb->last_error ); error_log( 'LSV07I DB: ' . $wpdb->last_error ); return new WP_Error( 'db_error', 'Datenbankfehler.' );
        }
        $konv_id = (int) $wpdb->insert_id;

        // Initiale Teilnehmer-Liste übernehmen falls mitgegeben
        if ( ! empty( $opts['teilnehmer'] ) && is_array( $opts['teilnehmer'] ) ) {
            foreach ( $opts['teilnehmer'] as $t ) {
                self::teilnehmer_hinzufuegen( $konv_id, $t );
            }
        }

        // Ersteller (= aktueller User) ist automatisch Admin der Gruppe.
        // Bei Direkt-Chats ist das egal — wir setzen es trotzdem, schadet nicht.
        $wpdb->update(
            $wpdb->prefix . 'lsv07i_konv_teilnehmer',
            [ 'ist_admin' => 1 ],
            [
                'konv_id'        => $konv_id,
                'teilnehmer_typ' => 'user',
                'teilnehmer_id'  => get_current_user_id(),
            ],
            [ '%d' ], [ '%d', '%s', '%d' ]
        );

        return $konv_id;
    }

    /**
     * Teilnehmer hinzufügen. Wird als idempotent behandelt (UNIQUE-Key).
     *
     * @param int   $konv_id
     * @param array $t  ['typ' => user|kontakt|email, 'id' => ..., 'email' => ..., 'name' => ...]
     * @return int|false  Teilnehmer-ID oder false
     */
    public static function teilnehmer_hinzufuegen( $konv_id, array $t ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_konv_teilnehmer';
        $typ = $t['typ'] ?? 'user';
        if ( ! in_array( $typ, [ 'user', 'kontakt', 'email' ], true ) ) return false;

        // E-Mail-Validierung für email-Typ
        $email = isset( $t['email'] ) ? sanitize_email( $t['email'] ) : null;
        if ( $typ === 'email' && ! $email ) return false;

        // Falls 'email'-Typ aber zu einem WP-User passt: in 'user' umwandeln.
        // Interne User bekommen Nachrichten im Chat, nicht per Mail.
        if ( $typ === 'email' && $email ) {
            $wp_user = get_user_by( 'email', $email );
            if ( $wp_user ) {
                $typ = 'user';
                $t['id'] = (int) $wp_user->ID;
                $t['name'] = $wp_user->display_name ?: $wp_user->user_login;
                $email = null; // teilnehmer_email für user-Typ leer lassen
            }
        }

        // Auch für 'kontakt'-Typ: prüfen ob die Kontakt-E-Mail einem WP-User
        // gehört. Falls ja, lieber als user-Teilnehmer anlegen.
        if ( $typ === 'kontakt' && ! empty( $t['id'] ) ) {
            $kontakt_email = $wpdb->get_var( $wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}lsv07i_kontakte WHERE id = %d",
                (int) $t['id']
            ) );
            if ( $kontakt_email ) {
                $wp_user = get_user_by( 'email', $kontakt_email );
                if ( $wp_user ) {
                    $typ = 'user';
                    $t['id'] = (int) $wp_user->ID;
                    $t['name'] = $wp_user->display_name ?: $wp_user->user_login;
                    $email = null;
                }
            }
        }

        $data = [
            'konv_id'          => (int) $konv_id,
            'teilnehmer_typ'   => $typ,
            'teilnehmer_id'    => (int) ( $t['id'] ?? 0 ),
            'teilnehmer_email' => $email,
            'teilnehmer_name'  => isset( $t['name'] ) ? mb_substr( (string) $t['name'], 0, 200 ) : null,
            'hinzugefuegt_von' => get_current_user_id(),
            'hinzugefuegt_am'  => current_time( 'mysql' ),
        ];
        $wpdb->insert( $tbl, $data, [ '%d', '%s', '%d', '%s', '%s', '%d', '%s' ] );
        return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
    }

    /**
     * Findet eine bestehende Direkt-Konversation zwischen zwei Usern, oder null.
     */
    public static function direkt_zwischen( $u1, $u2 ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $u1 = (int) $u1; $u2 = (int) $u2;
        if ( $u1 === $u2 ) return null;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT k.id FROM {$p}lsv07i_konv k
              WHERE k.typ = 'direkt'
                AND EXISTS (SELECT 1 FROM {$p}lsv07i_konv_teilnehmer t1
                             WHERE t1.konv_id = k.id AND t1.teilnehmer_typ='user' AND t1.teilnehmer_id = %d)
                AND EXISTS (SELECT 1 FROM {$p}lsv07i_konv_teilnehmer t2
                             WHERE t2.konv_id = k.id AND t2.teilnehmer_typ='user' AND t2.teilnehmer_id = %d)
              LIMIT 1",
            $u1, $u2
        ) );
        return $row ? (int) $row : null;
    }

    /**
     * Liste aller Konversationen eines Users (sortiert nach letzter Aktivität).
     */
    public static function liste_fuer_user( $user_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT k.*, t.gelesen_bis, t.stumm,
                    (SELECT MAX(id) FROM {$p}lsv07i_konv_msg
                      WHERE konv_id = k.id AND geloescht_am IS NULL) AS letzte_msg_id,
                    (SELECT COUNT(*) FROM {$p}lsv07i_konv_msg
                      WHERE konv_id = k.id AND id > t.gelesen_bis
                        AND geloescht_am IS NULL) AS ungelesen
               FROM {$p}lsv07i_konv k
               JOIN {$p}lsv07i_konv_teilnehmer t
                 ON t.konv_id = k.id
                AND t.teilnehmer_typ = 'user'
                AND t.teilnehmer_id  = %d
                AND t.verlassen_am IS NULL
              WHERE k.geschlossen = 0
              ORDER BY k.letzte_aktivitaet DESC, k.id DESC",
            (int) $user_id
        ), ARRAY_A ) ?: [];
    }

    /**
     * Nachricht senden. Wird auch von Externen aufgerufen (über Magic Link
     * oder IMAP-Reply), dann ist von_typ != 'user'.
     */
    public static function nachricht_senden( $konv_id, $text, array $opts = [] ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $text = trim( (string) $text );
        if ( $text === '' ) return new WP_Error( 'empty', 'Nachricht ist leer.' );

        $von_typ   = $opts['von_typ']   ?? 'user';
        $von_id    = (int) ( $opts['von_id'] ?? get_current_user_id() );
        $von_email = isset( $opts['von_email'] ) ? sanitize_email( $opts['von_email'] ) : null;
        $von_name  = isset( $opts['von_name'] )  ? mb_substr( (string) $opts['von_name'], 0, 200 ) : null;
        $dringend  = ! empty( $opts['dringend'] ) ? 1 : 0;

        $wpdb->insert( $p . 'lsv07i_konv_msg', [
            'konv_id'    => (int) $konv_id,
            'von_typ'    => $von_typ,
            'von_id'     => $von_id,
            'von_email'  => $von_email,
            'von_name'   => $von_name,
            'text'       => $text,
            'dringend'   => $dringend,
            'erstellt_am'=> current_time( 'mysql' ),
        ], [ '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' ] );
        $msg_id = (int) $wpdb->insert_id;
        // ACHTUNG: Hier fehlten die geschweiften Klammern. Dadurch lief das
        // return IMMER — jede gesendete Nachricht meldete "Datenbankfehler",
        // obwohl sie gespeichert war. In der Folge wurde auch der Anhang nie
        // hochgeladen, weil der Upload erst nach erfolgreichem Senden startet.
        if ( ! $msg_id ) {
            $fehler = $wpdb->last_error ?: 'Nachricht konnte nicht gespeichert werden.';
            do_action( 'lsv07i_db_error_log', $fehler );
            error_log( 'LSV07I DB: ' . $fehler );
            return new WP_Error( 'db_error', 'Die Nachricht konnte nicht gespeichert werden: ' . $fehler );
        }

        // letzte_aktivitaet der Konversation aktualisieren
        $wpdb->update( $p . 'lsv07i_konv',
            [ 'letzte_aktivitaet' => current_time( 'mysql' ) ],
            [ 'id' => (int) $konv_id ], [ '%s' ], [ '%d' ]
        );

        // Absender hat die Nachricht implizit gelesen
        if ( $von_typ === 'user' && $von_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}lsv07i_konv_teilnehmer
                    SET gelesen_bis = %d
                  WHERE konv_id = %d
                    AND teilnehmer_typ = 'user'
                    AND teilnehmer_id = %d",
                $msg_id, (int) $konv_id, $von_id
            ) );
        }
        return $msg_id;
    }

    /**
     * Markiert die Konversation als gelesen bis zur angegebenen Msg-ID.
     */
    public static function gelesen_bis( $konv_id, $user_id, $msg_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}lsv07i_konv_teilnehmer
                SET gelesen_bis = GREATEST(gelesen_bis, %d)
              WHERE konv_id = %d
                AND teilnehmer_typ = 'user'
                AND teilnehmer_id = %d",
            (int) $msg_id, (int) $konv_id, (int) $user_id
        ) );
    }

    /**
     * Anzahl ungelesener Nachrichten über alle Konversationen eines Users.
     * Wird für den Topbar-Zähler genutzt.
     */
    public static function ungelesen_total( $user_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(cnt), 0) FROM (
                SELECT (SELECT COUNT(*) FROM {$p}lsv07i_konv_msg m
                         WHERE m.konv_id = t.konv_id
                           AND m.id > t.gelesen_bis
                           AND m.geloescht_am IS NULL
                           AND NOT (m.von_typ = 'user' AND m.von_id = t.teilnehmer_id)) AS cnt
                  FROM {$p}lsv07i_konv_teilnehmer t
                 WHERE t.teilnehmer_typ = 'user'
                   AND t.teilnehmer_id = %d
                   AND t.verlassen_am IS NULL
                   AND t.stumm = 0
              ) AS sub",
            (int) $user_id
        ) );
    }

    /**
     * Prüft ob ein User Admin einer Konversation ist. WordPress-Admins
     * (administrator-Capability) gelten überall als Admin.
     */
    public static function ist_admin( $konv_id, $user_id ) {
        if ( user_can( $user_id, 'administrator' ) ) return true;
        global $wpdb;
        $p = $wpdb->prefix;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT ist_admin FROM {$p}lsv07i_konv_teilnehmer
              WHERE konv_id = %d
                AND teilnehmer_typ = 'user'
                AND teilnehmer_id = %d
                AND verlassen_am IS NULL
              LIMIT 1",
            (int) $konv_id, (int) $user_id
        ) );
    }

    /**
     * Zählt aktive Admins einer Konversation. Wichtig: man darf sich nicht
     * selbst als letzten Admin entfernen.
     */
    public static function anzahl_admins( $konv_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_konv_teilnehmer
              WHERE konv_id = %d
                AND teilnehmer_typ = 'user'
                AND ist_admin = 1
                AND verlassen_am IS NULL",
            (int) $konv_id
        ) );
    }

    /**
     * Konversation umbenennen + Mannschaft-Anhang aktualisieren.
     */
    public static function update_meta( $konv_id, $name ) {
        global $wpdb;
        $name = trim( wp_strip_all_tags( (string) $name ) );
        $wpdb->update(
            $wpdb->prefix . 'lsv07i_konv',
            [ 'name' => mb_substr( $name, 0, 200 ) ],
            [ 'id' => (int) $konv_id ],
            [ '%s' ], [ '%d' ]
        );
        return true;
    }

    /**
     * Konversation komplett löschen (inkl. Nachrichten, Anhänge, Reaktionen).
     * Direkt-Chats sollten nicht so gelöscht werden — sie bleiben für den
     * anderen Teilnehmer sichtbar, falls einer "verlässt".
     */
    public static function delete_konv( $konv_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $konv_id = (int) $konv_id;
        if ( ! $konv_id ) return false;

        // Anhänge-Dateien auf der Festplatte löschen
        $anh = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.gespeichert_als
               FROM {$p}lsv07i_konv_anhang a
          LEFT JOIN {$p}lsv07i_konv_msg m ON m.id = a.msg_id
              WHERE m.konv_id = %d", $konv_id
        ), ARRAY_A );
        $up = wp_upload_dir();
        $dir = trailingslashit( $up['basedir'] ) . 'lsv07i-private/nachrichten/' . $konv_id;
        foreach ( (array) $anh as $a ) {
            $f = trailingslashit( $dir ) . $a['gespeichert_als'];
            if ( file_exists( $f ) ) @unlink( $f );
        }
        if ( is_dir( $dir ) ) @rmdir( $dir );

        // DB-Einträge
        $wpdb->query( $wpdb->prepare(
            "DELETE r FROM {$p}lsv07i_konv_reaktion r
        INNER JOIN {$p}lsv07i_konv_msg m ON m.id = r.msg_id
             WHERE m.konv_id = %d", $konv_id ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE a FROM {$p}lsv07i_konv_anhang a
        INNER JOIN {$p}lsv07i_konv_msg m ON m.id = a.msg_id
             WHERE m.konv_id = %d", $konv_id ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE q FROM {$p}lsv07i_email_queue q
        INNER JOIN {$p}lsv07i_konv_msg m ON m.id = q.msg_id
             WHERE m.konv_id = %d", $konv_id ) );
        $wpdb->delete( $p . 'lsv07i_konv_msg',         [ 'konv_id' => $konv_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_konv_teilnehmer',  [ 'konv_id' => $konv_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_konv',             [ 'id'      => $konv_id ], [ '%d' ] );
        return true;
    }

    // ─── Online-Status-Tracking via Usermeta ──────────────────────────────
    // Wir speichern den letzten "Lebenszeichen"-Timestamp pro User in einer
    // einzelnen Usermeta-Zeile. Spart eine eigene Tabelle und integriert sich
    // sauber mit dem WordPress-User-Modell.

    /** Schwelle, ab der ein User als offline gilt (Sek). */
    const ONLINE_TIMEOUT = 90;

    /** Wie lange ein Typing-Ping gültig ist (Sek). */
    const TYPING_TIMEOUT = 4;

    /** Aktuellen User als gerade aktiv markieren. */
    public static function touch_online( $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return;
        update_user_meta( $user_id, 'lsv07i_last_seen', time() );
    }

    /** Online-Status für eine Liste von User-IDs. Liefert assoc [id => bool]. */
    public static function online_status( array $user_ids ) {
        $out = [];
        if ( ! $user_ids ) return $out;
        $now = time();
        foreach ( $user_ids as $uid ) {
            $uid = (int) $uid;
            if ( ! $uid ) continue;
            $ts = (int) get_user_meta( $uid, 'lsv07i_last_seen', true );
            $out[ $uid ] = ( $ts && ( $now - $ts ) < self::ONLINE_TIMEOUT );
        }
        return $out;
    }

    // ─── Typing-Indikator ─────────────────────────────────────────────────
    // Wir speichern pro Konversation einen Transient mit Map [user_id => ts].
    // Beim Tippen sendet der Client alle 2 Sek einen Ping, der den eigenen
    // Eintrag aktualisiert. Empfänger pollen den State alle 2 Sek.
    // Transient-TTL ist 10 Sek — falls niemand mehr pingt, wird er von WP
    // selbst aufgeräumt.

    private static function typing_key( $konv_id ) {
        return 'lsv07i_typing_' . (int) $konv_id;
    }

    /**
     * Aktuellen User als "tippt gerade" in der Konversation markieren.
     * Direkt-Chats und Gruppen sind unterstützt, Verteiler explizit nicht
     * (User-Wunsch: Verteiler-Antworten gehen 1:1, kein gemeinsames Tippen).
     */
    public static function typing_set( $konv_id ) {
        $konv_id = (int) $konv_id;
        if ( ! $konv_id ) return false;
        $uid = get_current_user_id();
        if ( ! $uid ) return false;

        global $wpdb;
        $typ = $wpdb->get_var( $wpdb->prepare(
            "SELECT typ FROM {$wpdb->prefix}lsv07i_konv WHERE id = %d", $konv_id
        ) );
        if ( $typ === 'verteiler' ) return false;

        $key = self::typing_key( $konv_id );
        $map = get_transient( $key );
        if ( ! is_array( $map ) ) $map = [];

        // Alte Einträge (älter TYPING_TIMEOUT) aussortieren — sonst wächst die
        // Map ewig wenn User mit "typt"-Status verschwinden.
        $now = time();
        foreach ( $map as $k => $ts ) {
            if ( $now - (int) $ts > self::TYPING_TIMEOUT ) unset( $map[ $k ] );
        }

        $map[ $uid ] = $now;
        set_transient( $key, $map, 10 ); // 10 Sek TTL als Sicherheitsnetz
        return true;
    }

    /**
     * Liefert Liste der gerade tippenden User in einer Konversation.
     * Schließt den abfragenden User selbst aus.
     *
     * @return array  Liste von [ ['id'=>..., 'name'=>...], ... ]
     */
    public static function typing_get( $konv_id ) {
        $konv_id = (int) $konv_id;
        if ( ! $konv_id ) return [];

        $key = self::typing_key( $konv_id );
        $map = get_transient( $key );
        if ( ! is_array( $map ) || ! $map ) return [];

        $now = time();
        $me  = get_current_user_id();
        $out = [];
        $changed = false;
        foreach ( $map as $uid => $ts ) {
            if ( $now - (int) $ts > self::TYPING_TIMEOUT ) {
                unset( $map[ $uid ] );
                $changed = true;
                continue;
            }
            if ( (int) $uid === $me ) continue; // sich selbst ausblenden
            $u = get_user_by( 'id', (int) $uid );
            if ( $u ) {
                $out[] = [
                    'id'   => (int) $uid,
                    'name' => $u->display_name ?: $u->user_login,
                ];
            }
        }
        if ( $changed ) set_transient( $key, $map, 10 );
        return $out;
    }
}
