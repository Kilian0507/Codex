<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wer ist wer, und was darf er.
 *
 * SwimPlan führt keine eigene Anmeldung. Wer hier etwas sehen will, ist bei
 * WordPress angemeldet — Passwörter, Sitzungen, Zurücksetzen und, wenn der
 * Verein mag, Zwei-Faktor sind damit Sache von WordPress und nicht Sache
 * dieses Plugins. Was SwimPlan führt, ist die ROLLE eines Kontos.
 *
 * Drei Rollen, wie im Konzept:
 *
 *   admin    — Verwaltung: Konten, Mannschaften, alle Pläne
 *   coach    — Trainer: die eigenen Mannschaften, eigene Pläne
 *   swimmer  — Schwimmer: die eigenen Pläne und die eigenen Zeiten
 *
 * Zwei Festlegungen, die wichtiger sind als sie aussehen:
 *
 *  1. **Ein Konto ohne Eintrag kommt nicht hinein.** Auch der Administrator
 *     der WordPress-Seite nicht, solange er sich nicht selbst einträgt. Es
 *     wäre bequemer gewesen, `manage_options` als Verwaltungsrolle zu
 *     lesen — dann hätte aber jeder, der aus irgendeinem Grund
 *     WordPress-Administrator ist, Zugriff auf die Trainingsdaten von
 *     Kindern. Wer sie sehen soll, wird benannt.
 *
 *  2. **Die Rolle wird serverseitig geprüft, in jedem Endpunkt.** Nicht
 *     über ausgeblendete Schaltflächen. Ein Schwimmer, der eine fremde
 *     Plan-Nummer von Hand aufruft, bekommt 403 und keine Daten.
 */
class LSV07P_Rollen {

    const ADMIN   = 'admin';
    const TRAINER = 'coach';
    const SCHWIMMER = 'swimmer';

    const ROLLEN = [
        self::ADMIN     => 'Verwaltung',
        self::TRAINER   => 'Trainer',
        self::SCHWIMMER => 'Schwimmer',
    ];

    /** Einmal je Aufruf nachsehen genügt. */
    private static $ich = false;

    public static function ist_rolle( $r ) {
        return isset( self::ROLLEN[ $r ] );
    }

    /**
     * Der Eintrag des angemeldeten Kontos, oder null.
     * null heisst: kein Zugang — egal, was das Konto in WordPress darf.
     */
    public static function ich() {
        if ( self::$ich !== false ) return self::$ich;
        self::$ich = null;

        if ( ! is_user_logged_in() ) return null;
        self::$ich = self::person( get_current_user_id() );
        return self::$ich;
    }

    /** Für den Prüfstand und für Wechsel innerhalb eines Aufrufs. */
    public static function vergessen() {
        self::$ich = false;
    }

    public static function person( $user_id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_person WHERE wp_user_id = %d", (int) $user_id ), ARRAY_A );
        if ( ! $row ) return null;
        /* Ein deaktiviertes Konto kommt nicht hinein — seine Historie
           bleibt aber stehen. Das war die Vorgabe: deaktivieren, nicht
           löschen. */
        if ( (int) $row['aktiv'] !== 1 ) return null;
        if ( ! self::ist_rolle( $row['rolle'] ) ) return null;
        return $row;
    }

    /** Auch wenn deaktiviert — für die Verwaltungsliste. */
    public static function person_roh( $user_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_person WHERE wp_user_id = %d", (int) $user_id ), ARRAY_A );
    }

    public static function hat_zugang() {
        return self::ich() !== null;
    }

    public static function rolle() {
        $i = self::ich();
        return $i ? (string) $i['rolle'] : '';
    }

    public static function user_id() {
        $i = self::ich();
        return $i ? (int) $i['wp_user_id'] : 0;
    }

    public static function ist_admin()     { return self::rolle() === self::ADMIN; }
    public static function ist_trainer()   { return self::rolle() === self::TRAINER; }
    public static function ist_schwimmer() { return self::rolle() === self::SCHWIMMER; }

    /** Verwaltung und Trainer dürfen planen. */
    public static function darf_planen() {
        return self::ist_admin() || self::ist_trainer();
    }

    /**
     * Die Mannschaften, die das angemeldete Konto BEARBEITEN darf.
     * Verwaltung: alle. Trainer: die zugeteilten. Schwimmer: keine.
     */
    public static function eigene_teams() {
        if ( self::ist_admin() )   return LSV07P_Mannschaft::alle_ids();
        if ( self::ist_trainer() ) return LSV07P_Mannschaft::teams_von_trainer( self::user_id() );
        return [];
    }

    public static function darf_team( $team_id ) {
        $team_id = (int) $team_id;
        if ( ! $team_id ) return false;
        return in_array( $team_id, self::eigene_teams(), true );
    }

    /**
     * Darf das angemeldete Konto die Daten DIESER Person sehen?
     *
     *  - Sich selbst: immer.
     *  - Verwaltung: jeden.
     *  - Trainer: jeden, der in einer seiner Mannschaften ist (oder war).
     *  - Schwimmer: nur sich selbst.
     *
     * Diese eine Frage entscheidet über Zeiten, Profile und Leistungen.
     * Darum steht sie hier und nicht verstreut in den Endpunkten.
     */
    public static function darf_person( $wp_user_id ) {
        $wp_user_id = (int) $wp_user_id;
        if ( ! $wp_user_id || ! self::hat_zugang() ) return false;
        if ( $wp_user_id === self::user_id() ) return true;
        if ( self::ist_admin() ) return true;
        if ( ! self::ist_trainer() ) return false;

        $meine = self::eigene_teams();
        if ( ! $meine ) return false;
        foreach ( LSV07P_Mannschaft::teams_von_schwimmer( $wp_user_id, true ) as $t ) {
            if ( in_array( (int) $t, $meine, true ) ) return true;
        }
        return false;
    }

    // ── Verwaltung der Rollen ───────────────────────────────────────────

    /**
     * Trägt ein Konto ein oder ändert seine Rolle.
     * Wer das darf, prüft der Endpunkt — hier wird nur geschrieben.
     */
    public static function setzen( $wp_user_id, $rolle, $aktiv = 1, $notiz = '' ) {
        global $wpdb;
        if ( ! self::ist_rolle( $rolle ) ) return false;
        $wp_user_id = (int) $wp_user_id;
        if ( ! $wp_user_id || ! get_userdata( $wp_user_id ) ) return false;

        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07p_person (wp_user_id, rolle, aktiv, notiz, angelegt_von, angelegt_am)
             VALUES (%d, %s, %d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE rolle = VALUES(rolle), aktiv = VALUES(aktiv),
                                     notiz = VALUES(notiz)",
            $wp_user_id, $rolle, $aktiv ? 1 : 0, mb_substr( (string) $notiz, 0, 200 ),
            (int) get_current_user_id(), current_time( 'mysql' ) ) );

        self::vergessen();
        LSV07P_DB::spur( 'person.rolle', $wp_user_id, $rolle . ( $aktiv ? '' : ' (deaktiviert)' ) );
        return true;
    }

    /**
     * Nimmt einem Konto den Zugang, ohne seine Daten anzurühren.
     * Löschen gibt es hier nicht: Trainingshistorie und Zeiten bleiben
     * erhalten, und ein gelöschter Eintrag liesse sie verwaisen.
     */
    public static function deaktivieren( $wp_user_id ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'lsv07p_person', [ 'aktiv' => 0 ],
            [ 'wp_user_id' => (int) $wp_user_id ], [ '%d' ], [ '%d' ] );
        self::vergessen();
        LSV07P_DB::spur( 'person.aus', (int) $wp_user_id, 'deaktiviert' );
        return true;
    }

    public static function aktivieren( $wp_user_id ) {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'lsv07p_person', [ 'aktiv' => 1 ],
            [ 'wp_user_id' => (int) $wp_user_id ], [ '%d' ], [ '%d' ] );
        self::vergessen();
        LSV07P_DB::spur( 'person.an', (int) $wp_user_id, 'aktiviert' );
        return true;
    }

    /** Alle Einträge, für die Verwaltungsliste — auch die deaktivierten. */
    public static function alle( $rolle = '' ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $sql  = "SELECT * FROM {$p}lsv07p_person";
        $args = [];
        if ( $rolle !== '' && self::ist_rolle( $rolle ) ) {
            $sql .= ' WHERE rolle = %s';
            $args[] = $rolle;
        }
        $sql .= ' ORDER BY rolle ASC, wp_user_id ASC';

        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
                      : $wpdb->get_results( $sql, ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $u = get_userdata( (int) $r['wp_user_id'] );
            $out[] = [
                'user_id' => (int) $r['wp_user_id'],
                'name'    => $u ? $u->display_name : '(Konto gelöscht)',
                'email'   => $u ? $u->user_email : '',
                'rolle'   => (string) $r['rolle'],
                'rolle_name' => self::ROLLEN[ $r['rolle'] ] ?? $r['rolle'],
                'aktiv'   => (int) $r['aktiv'] === 1,
                'notiz'   => (string) $r['notiz'],
                'weg'     => ! $u,
            ];
        }
        return $out;
    }

    /**
     * Der Name eines Kontos, zum Anzeigen. Ein gelöschtes Konto bekommt
     * einen Platzhalter statt einer leeren Zeile — sonst steht in einer
     * alten Zeit niemand mehr.
     */
    public static function name( $wp_user_id ) {
        $u = get_userdata( (int) $wp_user_id );
        return $u ? $u->display_name : '(Konto gelöscht)';
    }
}
