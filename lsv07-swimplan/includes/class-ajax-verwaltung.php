<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Endpunkte der Verwaltung: Konten, Rollen, Mannschaften.
 *
 * Hier ist immer jemand bei WordPress angemeldet — darum `wp_ajax_` ohne
 * nopriv und der gewohnte Nonce. Geprüft wird bei JEDEM Aufruf dreierlei:
 * der Nonce, die Rolle, und ob die genannte Mannschaft dem Anrufer gehört.
 * Die dritte Prüfung steht in jedem Endpunkt und nicht nur beim Laden der
 * Liste: Eine Mannschaftsnummer kommt aus dem Browser und ist damit ein
 * Wunsch, keine Tatsache.
 *
 * Wer was darf:
 *
 *   Verwaltung — Konten eintragen, Rollen setzen, Mannschaften anlegen,
 *                Trainer zuteilen, Mitgliedschaften, Einstellungen, Prüfspur
 *   Trainer    — die Mitgliedschaften der EIGENEN Mannschaften; sonst nichts
 *
 * Ein Trainer kann also jemanden in seine Mannschaft aufnehmen, aber
 * niemanden zum Trainer machen und keine Rolle ändern. Das ist die Grenze,
 * an der aus „darf planen" sonst „darf alles" würde.
 */
class LSV07P_Ajax_Verwaltung {

    public static function init() {
        $map = [
            'lsv07p_v_basis'       => 'basis',
            'lsv07p_v_kandidaten'  => 'kandidaten',
            'lsv07p_v_person'      => 'person_setzen',
            'lsv07p_v_person_aus'  => 'person_aus',
            'lsv07p_v_person_an'   => 'person_an',
            'lsv07p_v_team'        => 'team_speichern',
            'lsv07p_v_team_daten'  => 'team_daten',
            'lsv07p_v_trainer'     => 'trainer',
            'lsv07p_v_mitglied'    => 'mitglied_auf',
            'lsv07p_v_mitglied_aus' => 'mitglied_aus',
            'lsv07p_v_config'      => 'config',
            'lsv07p_v_spur'        => 'spur',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $methode ] );
        }
    }

    // ── Gemeinsames ─────────────────────────────────────────────────────

    private static function nonce() {
        if ( ! check_ajax_referer( 'lsv07p_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                [ 'message' => 'Die Sitzung ist abgelaufen. Bitte Seite neu laden.' ], 403 );
        }
    }

    /** Angemeldet und eingetragen — sonst Ende. */
    private static function zugang() {
        self::nonce();
        if ( ! LSV07P_Rollen::hat_zugang() ) {
            wp_send_json_error( [ 'message' => 'Dafür fehlt die Berechtigung.' ], 403 );
        }
    }

    private static function admin() {
        self::zugang();
        if ( ! LSV07P_Rollen::ist_admin() ) {
            wp_send_json_error( [ 'message' => 'Das darf nur die Verwaltung.' ], 403 );
        }
    }

    /** Eine Mannschaft, die dem Anrufer gehört — oder Ende. */
    private static function team() {
        self::zugang();
        $id = (int) ( $_POST['team_id'] ?? 0 );
        if ( ! LSV07P_Rollen::darf_team( $id ) ) {
            wp_send_json_error( [ 'message' => 'Diese Mannschaft gehört nicht zu deinen.' ], 403 );
        }
        return $id;
    }

    private static function feld( $name, $standard = '' ) {
        return isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : $standard;
    }

    // ── Grunddaten ──────────────────────────────────────────────────────

    public static function basis() {
        self::zugang();

        $teams = [];
        foreach ( LSV07P_Mannschaft::alle() as $t ) {
            $id = (int) $t['id'];
            if ( ! LSV07P_Rollen::darf_team( $id ) ) continue;

            $trainer = [];
            foreach ( LSV07P_Mannschaft::trainer_von_team( $id ) as $u ) {
                $trainer[] = [ 'user_id' => (int) $u, 'name' => LSV07P_Rollen::name( $u ) ];
            }
            $teams[] = [
                'id'          => $id,
                'name'        => (string) $t['name'],
                'beschreibung' => (string) ( $t['beschreibung'] ?? '' ),
                'aktiv'       => (int) $t['aktiv'] === 1,
                'sortierung'  => (int) $t['sortierung'],
                'trainer'     => $trainer,
                'mitglieder'  => count( LSV07P_Mannschaft::mitglieder( $id ) ),
            ];
        }

        $aus = [
            'rolle'      => LSV07P_Rollen::rolle(),
            'rolle_name' => LSV07P_Rollen::ROLLEN[ LSV07P_Rollen::rolle() ] ?? '',
            'ist_admin'  => LSV07P_Rollen::ist_admin(),
            'name'       => LSV07P_Rollen::name( LSV07P_Rollen::user_id() ),
            'teams'      => $teams,
            'rollen'     => LSV07P_Rollen::ROLLEN,
        ];

        /* Die Kontenliste und die Einstellungen gehen nur an die
           Verwaltung. Ein Trainer braucht sie nicht und soll die Liste
           aller Konten des Vereins nicht bekommen. */
        if ( LSV07P_Rollen::ist_admin() ) {
            $aus['personen'] = LSV07P_Rollen::alle();
            $aus['config']   = [
                'richtwert_100' => LSV07P_Rechnung::richtwert_100(),
                'richtwert_text' => LSV07P_Rechnung::mmss( LSV07P_Rechnung::richtwert_100() ),
            ];
        }

        wp_send_json_success( $aus );
    }

    /**
     * WordPress-Konten, die noch keinen SwimPlan-Eintrag haben — zum
     * Eintragen. Gesucht wird erst ab zwei Zeichen: Eine Liste aller
     * Konten des Vereins soll nicht auf einen Klick herausfallen.
     */
    public static function kandidaten() {
        self::admin();

        $suche = trim( (string) self::feld( 'suche' ) );
        if ( mb_strlen( $suche ) < 2 ) {
            wp_send_json_success( [ 'treffer' => [], 'hinweis' => 'Bitte mindestens zwei Zeichen.' ] );
        }

        $users = get_users( [
            'search'         => '*' . $suche . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name', 'user_nicename' ],
            'number'         => 20,
            'orderby'        => 'display_name',
        ] );

        $treffer = [];
        foreach ( $users as $u ) {
            $vorhanden = LSV07P_Rollen::person_roh( $u->ID );
            $treffer[] = [
                'user_id'   => (int) $u->ID,
                'name'      => $u->display_name,
                'login'     => $u->user_login,
                'email'     => $u->user_email,
                'rolle'     => $vorhanden ? (string) $vorhanden['rolle'] : '',
                'vorhanden' => (bool) $vorhanden,
            ];
        }
        wp_send_json_success( [ 'treffer' => $treffer ] );
    }

    // ── Konten und Rollen ───────────────────────────────────────────────

    public static function person_setzen() {
        self::admin();

        $user  = (int) self::feld( 'user_id', 0 );
        $rolle = (string) self::feld( 'rolle' );
        $notiz = (string) self::feld( 'notiz' );

        if ( ! LSV07P_Rollen::ist_rolle( $rolle ) ) {
            wp_send_json_error( [ 'message' => 'Diese Rolle gibt es nicht.' ], 400 );
        }

        /* Sich selbst die Verwaltungsrolle nehmen geht nicht — sonst
           schliesst sich der letzte Verwalter aus und niemand kommt mehr
           an die Rollen. */
        if ( $user === LSV07P_Rollen::user_id() && $rolle !== LSV07P_Rollen::ADMIN ) {
            wp_send_json_error( [ 'message' =>
                'Die eigene Verwaltungsrolle lässt sich hier nicht abgeben. '
              . 'Das muss ein anderes Verwaltungskonto tun.' ], 400 );
        }

        if ( ! LSV07P_Rollen::setzen( $user, $rolle, 1, $notiz ) ) {
            wp_send_json_error( [ 'message' => 'Zu dieser Nummer gibt es kein WordPress-Konto.' ], 400 );
        }
        wp_send_json_success( [ 'personen' => LSV07P_Rollen::alle() ] );
    }

    public static function person_aus() {
        self::admin();
        $user = (int) self::feld( 'user_id', 0 );

        if ( $user === LSV07P_Rollen::user_id() ) {
            wp_send_json_error( [ 'message' =>
                'Das eigene Konto lässt sich hier nicht abschalten.' ], 400 );
        }
        LSV07P_Rollen::deaktivieren( $user );
        wp_send_json_success( [ 'personen' => LSV07P_Rollen::alle() ] );
    }

    public static function person_an() {
        self::admin();
        LSV07P_Rollen::aktivieren( (int) self::feld( 'user_id', 0 ) );
        wp_send_json_success( [ 'personen' => LSV07P_Rollen::alle() ] );
    }

    // ── Mannschaften ────────────────────────────────────────────────────

    public static function team_speichern() {
        self::admin();

        $id   = (int) self::feld( 'id', 0 );
        $name = (string) self::feld( 'name' );

        if ( $id ) {
            if ( ! LSV07P_Mannschaft::aendern( $id, [
                'name'         => $name,
                'beschreibung' => (string) self::feld( 'beschreibung' ),
                'aktiv'        => (int) self::feld( 'aktiv', 1 ),
                'sortierung'   => (int) self::feld( 'sortierung', 0 ),
            ] ) ) {
                wp_send_json_error( [ 'message' => 'Der Name darf nicht leer sein.' ], 400 );
            }
        } else {
            $id = LSV07P_Mannschaft::anlegen( $name,
                (string) self::feld( 'beschreibung' ), (int) self::feld( 'sortierung', 0 ) );
            if ( ! $id ) {
                wp_send_json_error( [ 'message' => 'Der Name darf nicht leer sein.' ], 400 );
            }
        }
        wp_send_json_success( [ 'id' => $id ] );
    }

    /** Eine Mannschaft im Einzelnen: Trainer und Mitglieder. */
    public static function team_daten() {
        $id   = self::team();
        $team = LSV07P_Mannschaft::holen( $id );
        if ( ! $team ) wp_send_json_error( [ 'message' => 'Die Mannschaft ist nicht da.' ], 404 );

        $trainer = [];
        foreach ( LSV07P_Mannschaft::trainer_von_team( $id ) as $u ) {
            $trainer[] = [ 'user_id' => (int) $u, 'name' => LSV07P_Rollen::name( $u ) ];
        }

        /* Zum Aufnehmen: eingetragene Schwimmer, die noch nicht drin sind.
           Das ist eine kurze Liste und sie enthält nur Konten, die ohnehin
           in SwimPlan stehen. */
        $drin = [];
        foreach ( LSV07P_Mannschaft::mitglieder( $id ) as $m ) $drin[ $m['user_id'] ] = true;

        $frei = [];
        foreach ( LSV07P_Rollen::alle( LSV07P_Rollen::SCHWIMMER ) as $p ) {
            if ( ! $p['aktiv'] || isset( $drin[ $p['user_id'] ] ) ) continue;
            $frei[] = [ 'user_id' => $p['user_id'], 'name' => $p['name'] ];
        }

        wp_send_json_success( [
            'id'          => $id,
            'name'        => (string) $team['name'],
            'beschreibung' => (string) ( $team['beschreibung'] ?? '' ),
            'aktiv'       => (int) $team['aktiv'] === 1,
            'trainer'     => $trainer,
            'mitglieder'  => LSV07P_Mannschaft::mitglieder( $id, true ),
            'frei'        => $frei,
            'darf_trainer' => LSV07P_Rollen::ist_admin(),
        ] );
    }

    public static function trainer() {
        self::admin();

        $team = (int) self::feld( 'team_id', 0 );
        $user = (int) self::feld( 'user_id', 0 );
        $weg  = (string) self::feld( 'weg' ) === '1';

        if ( ! LSV07P_Mannschaft::holen( $team ) ) {
            wp_send_json_error( [ 'message' => 'Die Mannschaft ist nicht da.' ], 404 );
        }

        if ( $weg ) {
            LSV07P_Mannschaft::trainer_entziehen( $team, $user );
        } elseif ( ! LSV07P_Mannschaft::trainer_zuteilen( $team, $user ) ) {
            wp_send_json_error( [ 'message' =>
                'Trainer einer Mannschaft kann nur werden, wer in SwimPlan als Trainer '
              . 'oder Verwaltung eingetragen ist.' ], 400 );
        }

        $trainer = [];
        foreach ( LSV07P_Mannschaft::trainer_von_team( $team ) as $u ) {
            $trainer[] = [ 'user_id' => (int) $u, 'name' => LSV07P_Rollen::name( $u ) ];
        }
        wp_send_json_success( [ 'trainer' => $trainer ] );
    }

    /** Aufnehmen darf auch der Trainer der Mannschaft — es ist seine. */
    public static function mitglied_auf() {
        $team = self::team();
        $user = (int) self::feld( 'user_id', 0 );

        if ( ! LSV07P_Rollen::person( $user ) ) {
            wp_send_json_error( [ 'message' =>
                'Dieses Konto ist in SwimPlan nicht eingetragen oder abgeschaltet.' ], 400 );
        }
        if ( ! LSV07P_Mannschaft::aufnehmen( $team, $user, (string) self::feld( 'start' ) ) ) {
            wp_send_json_error( [ 'message' =>
                'Die Person ist in dieser Mannschaft bereits laufend eingetragen.' ], 400 );
        }
        wp_send_json_success( [ 'mitglieder' => LSV07P_Mannschaft::mitglieder( $team, true ) ] );
    }

    public static function mitglied_aus() {
        $team = self::team();
        $mid  = (int) self::feld( 'mitglied_id', 0 );

        /* Die Nummer der Mitgliedschaft muss zu DIESER Mannschaft gehören.
           Ohne diese Prüfung liesse sich über eine eigene Mannschaft die
           Mitgliedschaft in einer fremden beenden. */
        $gehoert = false;
        foreach ( LSV07P_Mannschaft::mitglieder( $team, true ) as $m ) {
            if ( (int) $m['id'] === $mid ) { $gehoert = true; break; }
        }
        if ( ! $gehoert ) {
            wp_send_json_error( [ 'message' => 'Diese Mitgliedschaft gehört nicht zu dieser Mannschaft.' ], 403 );
        }

        LSV07P_Mannschaft::beenden( $mid, (string) self::feld( 'ende' ) );
        wp_send_json_success( [ 'mitglieder' => LSV07P_Mannschaft::mitglieder( $team, true ) ] );
    }

    // ── Einstellungen und Prüfspur ──────────────────────────────────────

    public static function config() {
        self::admin();

        if ( isset( $_POST['richtwert_100'] ) ) {
            $roh = (string) self::feld( 'richtwert_100' );
            /* Eingegeben wird „1:50" oder „110" — beides ist gemeint. */
            $sek = strpos( $roh, ':' ) !== false
                ? (int) round( LSV07P_Zeiten::parse( $roh ) / 100 )
                : (int) $roh;
            if ( $sek < 40 || $sek > 300 ) {
                wp_send_json_error( [ 'message' =>
                    'Der Richtwert muss zwischen 0:40 und 5:00 auf 100 m liegen.' ], 400 );
            }
            LSV07P_DB::config_setzen( 'richtwert_100', $sek );
            LSV07P_DB::spur( 'config', 0, 'Richtwert ' . $sek . ' s' );
        }

        wp_send_json_success( [
            'richtwert_100'  => LSV07P_Rechnung::richtwert_100(),
            'richtwert_text' => LSV07P_Rechnung::mmss( LSV07P_Rechnung::richtwert_100() ),
        ] );
    }

    /**
     * Die Prüfspur. Sie steht nur der Verwaltung offen und enthält keine
     * Klartext-Adressen — nur Hashes.
     */
    public static function spur() {
        self::admin();

        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_spur ORDER BY id DESC LIMIT %d", 200 ), ARRAY_A ) ?: [];

        $aus = [];
        foreach ( $rows as $r ) {
            $aus[] = [
                'was'     => (string) $r['was'],
                'wer'     => LSV07P_Rollen::name( (int) $r['user_id'] ),
                'ziel'    => (int) $r['ziel_id'],
                'hinweis' => (string) $r['hinweis'],
                'am'      => LSV07P_Bericht::de_zeit( $r['am'] ),
            ];
        }
        wp_send_json_success( [ 'spur' => $aus, 'tage' => LSV07P_DB::SPUR_TAGE ] );
    }
}
