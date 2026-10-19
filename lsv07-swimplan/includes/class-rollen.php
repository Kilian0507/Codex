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
 * Zwei Rollen:
 *
 *   admin    — Verwaltung: Konten, Schwimmer, Mannschaften, alle Pläne
 *   coach    — Trainer: die eigenen Mannschaften, eigene Pläne, die Zeiten
 *              seiner Schwimmer
 *
 * **Schwimmer haben kein Konto.** Sie melden sich nirgends an und kommen
 * nicht in SwimPlan hinein; sie sind Datensätze (siehe LSV07P_Schwimmer).
 * Bis 1.2.0 war das anders — da war jedes Kind ein WordPress-Konto mit der
 * Rolle `swimmer`. Das hiess, für jedes Kind ein Konto anzulegen, mit dem
 * sich jemand anmelden kann; eine Angriffsfläche, die niemand braucht,
 * wenn ohnehin niemand damit hineinsoll.
 *
 * Zwei Festlegungen, die wichtiger sind als sie aussehen:
 *
 *  1. **Wer WordPress verwalten darf, verwaltet auch SwimPlan.** Ein Konto
 *     mit `manage_options` ist hier Verwaltung — ohne Eintrag, ohne
 *     Einrichtung, ab dem ersten Aufruf. Der Eintrag wird dabei still
 *     angelegt, damit das Konto auch in der Liste steht.
 *
 *     Das ist eine bewusste Entscheidung des Vereins, und sie hat einen
 *     Preis: Jeder, der aus irgendeinem Grund WordPress-Administrator ist,
 *     sieht damit die Trainingsdaten von Kindern. Dafür spricht, dass er
 *     ohnehin an sie herankäme — wer Plugins bearbeiten darf, kann sich
 *     jedes Recht selbst geben. Ein Schloss, das jeder Administrator in
 *     zwei Minuten aufbekommt, hätte nur so getan, als wäre es eines.
 *     **Wer hier nicht hineinsehen soll, darf also kein
 *     WordPress-Administrator sein.**
 *
 *     Jedes ANDERE Konto kommt weiterhin nur mit Eintrag hinein — Trainer
 *     und Schwimmer werden benannt und nicht abgeleitet.
 *
 *  2. **Die Rolle wird serverseitig geprüft, in jedem Endpunkt.** Nicht
 *     über ausgeblendete Schaltflächen. Ein Schwimmer, der eine fremde
 *     Plan-Nummer von Hand aufruft, bekommt 403 und keine Daten.
 */
class LSV07P_Rollen {

    const ADMIN   = 'admin';
    const TRAINER = 'coach';

    const ROLLEN = [
        self::ADMIN   => 'Verwaltung',
        self::TRAINER => 'Trainer',
    ];

    /** Das Recht in WordPress, das hier die Verwaltungsrolle bedeutet. */
    const ADMIN_CAP = 'manage_options';

    /** Einmal je Aufruf nachsehen genügt. */
    private static $ich = false;

    public static function ist_rolle( $r ) {
        return isset( self::ROLLEN[ $r ] );
    }

    /**
     * Darf dieses Konto WordPress verwalten? Für das angemeldete Konto
     * fragt das `current_user_can`, für ein anderes `user_can` — beide
     * beziehen auch Rollen ein, die ein Plugin vergeben hat.
     */
    public static function ist_wp_admin( $user_id = 0 ) {
        $user_id = (int) $user_id;
        if ( ! $user_id || $user_id === (int) get_current_user_id() ) {
            return (bool) current_user_can( self::ADMIN_CAP );
        }
        if ( ! function_exists( 'user_can' ) ) return false;
        return (bool) user_can( $user_id, self::ADMIN_CAP );
    }

    /**
     * Der Eintrag des angemeldeten Kontos, oder null.
     * null heisst: kein Zugang.
     */
    public static function ich() {
        if ( self::$ich !== false ) return self::$ich;
        self::$ich = null;

        if ( ! is_user_logged_in() ) return null;
        $id = (int) get_current_user_id();

        /* Ein WordPress-Administrator bekommt seinen Eintrag beim ersten
           Aufruf von selbst. Geschrieben wird er, damit das Konto auch in
           der Verwaltungsliste und in der Prüfspur auftaucht — sonst
           verwaltete jemand mit, der nirgends steht. */
        if ( self::ist_wp_admin( $id ) ) self::admin_nachtragen( $id );

        self::$ich = self::person( $id );
        return self::$ich;
    }

    /** Für den Prüfstand und für Wechsel innerhalb eines Aufrufs. */
    public static function vergessen() {
        self::$ich = false;
    }

    /**
     * Trägt einen WordPress-Administrator als Verwaltung ein, wenn er noch
     * nicht oder anders darin steht. Das läuft höchstens einmal je Konto
     * wirklich los.
     */
    private static function admin_nachtragen( $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_person WHERE wp_user_id = %d", $user_id ), ARRAY_A );

        if ( $row && (string) $row['rolle'] === self::ADMIN && (int) $row['aktiv'] === 1 ) {
            return;
        }
        if ( ! $row ) {
            $wpdb->insert( $p . 'lsv07p_person', [
                'wp_user_id'   => $user_id,
                'rolle'        => self::ADMIN,
                'aktiv'        => 1,
                'notiz'        => 'automatisch: Administrator der WordPress-Seite',
                'angelegt_von' => $user_id,
                'angelegt_am'  => current_time( 'mysql' ),
            ], [ '%d', '%s', '%d', '%s', '%d', '%s' ] );
            LSV07P_DB::spur( 'person.wp-admin', $user_id, 'automatisch eingetragen' );
            return;
        }
        $wpdb->update( $p . 'lsv07p_person',
            [ 'rolle' => self::ADMIN, 'aktiv' => 1 ],
            [ 'wp_user_id' => $user_id ], [ '%s', '%d' ], [ '%d' ] );
        LSV07P_DB::spur( 'person.wp-admin', $user_id, 'auf Verwaltung gesetzt' );
    }

    /**
     * Der Eintrag eines Kontos, oder null.
     *
     * Ein WordPress-Administrator ist hier IMMER Verwaltung — auch wenn in
     * der Tabelle etwas anderes steht und auch, wenn der Eintrag
     * abgeschaltet wurde. Alles andere wäre eine Behauptung: Wer
     * `manage_options` hat, kann sich jedes Recht ohnehin selbst geben.
     */
    public static function person( $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( ! $user_id ) return null;

        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_person WHERE wp_user_id = %d", $user_id ), ARRAY_A );

        if ( self::ist_wp_admin( $user_id ) ) {
            if ( ! $row ) {
                /* Noch nicht nachgetragen (etwa, weil hier nach einem
                   FREMDEN Konto gefragt wird): Die Rolle gilt trotzdem. */
                $row = [
                    'wp_user_id' => $user_id, 'rolle' => self::ADMIN, 'aktiv' => 1,
                    'notiz' => 'automatisch: Administrator der WordPress-Seite',
                    'angelegt_von' => 0, 'angelegt_am' => '',
                ];
            }
            $row['rolle'] = self::ADMIN;
            $row['aktiv'] = 1;
            $row['wp']    = 1;
            return $row;
        }

        if ( ! $row ) return null;
        /* Ein deaktiviertes Konto kommt nicht hinein — seine Historie
           bleibt aber stehen. Das war die Vorgabe: deaktivieren, nicht
           löschen. */
        if ( (int) $row['aktiv'] !== 1 ) return null;
        if ( ! self::ist_rolle( $row['rolle'] ) ) return null;
        $row['wp'] = 0;
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

    public static function ist_admin()   { return self::rolle() === self::ADMIN; }
    public static function ist_trainer() { return self::rolle() === self::TRAINER; }

    /**
     * Wer hineinkommt, darf auch planen: Es gibt nur noch die beiden
     * Rollen, und beide arbeiten mit Plänen. Die Methode bleibt, weil in
     * jedem Endpunkt steht, was er verlangt — und weil „darf_planen" sagt,
     * worum es geht.
     */
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
     * Darf das angemeldete Konto die Daten dieses SCHWIMMERS sehen?
     *
     * Die Frage steht in LSV07P_Schwimmer::darf() — dort, wo auch die
     * Schwimmer selbst stehen. Hier bleibt nur der Verweis, damit niemand
     * sie an zwei Stellen beantwortet.
     */
    public static function darf_schwimmer( $schwimmer_id ) {
        return LSV07P_Schwimmer::darf( $schwimmer_id );
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
        $wp_user_id = (int) $wp_user_id;

        /* Einen WordPress-Administrator abzuschalten wäre wirkungslos: Beim
           nächsten Aufruf trägt er sich von selbst wieder ein. Lieber
           „geht nicht" sagen als etwas tun, das nichts bewirkt. */
        if ( self::ist_wp_admin( $wp_user_id ) ) return false;

        $wpdb->update( $wpdb->prefix . 'lsv07p_person', [ 'aktiv' => 0 ],
            [ 'wp_user_id' => $wp_user_id ], [ '%d' ], [ '%d' ] );
        self::vergessen();
        LSV07P_DB::spur( 'person.aus', $wp_user_id, 'deaktiviert' );
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

        $rolle_filter = ( $rolle !== '' && self::ist_rolle( $rolle ) ) ? (string) $rolle : '';

        /* Gelesen wird ohne Filter: Welche Rolle gilt, entscheidet sich erst
           mit dem Blick nach WordPress. Gefiltert wird darum unten. */
        $rows = $wpdb->get_results(
            "SELECT * FROM {$p}lsv07p_person ORDER BY rolle ASC, wp_user_id ASC", ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $id = (int) $r['wp_user_id'];
            $u  = get_userdata( $id );
            /* Ein WordPress-Administrator steht hier als Verwaltung, auch
               wenn in der Zeile etwas anderes stünde — angezeigt wird, was
               gilt, nicht was gespeichert ist. */
            $wp = self::ist_wp_admin( $id );
            $rolle = $wp ? self::ADMIN : (string) $r['rolle'];
            /* Gefiltert wird nach der Rolle, die GILT: Ein Konto, das in der
               Tabelle als Schwimmer steht, aber WordPress verwaltet, gehört
               nicht in die Liste der Schwimmer. */
            if ( $rolle_filter !== '' && $rolle !== $rolle_filter ) continue;
            $out[] = [
                'user_id' => $id,
                'name'    => $u ? $u->display_name : '(Konto gelöscht)',
                'email'   => $u ? $u->user_email : '',
                'rolle'   => $rolle,
                'rolle_name' => self::ROLLEN[ $rolle ] ?? $rolle,
                'aktiv'   => $wp ? true : ( (int) $r['aktiv'] === 1 ),
                'notiz'   => (string) $r['notiz'],
                'weg'     => ! $u,
                'wp'      => $wp,
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
