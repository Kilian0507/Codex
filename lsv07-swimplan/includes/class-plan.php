<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Trainingseinheit — anlegen, aus Sets füllen, veröffentlichen.
 *
 * Vier Zustände, wie im Konzept:
 *
 *   draft      — in Arbeit, sieht nur der Trainer
 *   published  — veröffentlicht, die Freigabe entscheidet, wer es sieht
 *   completed  — stattgefunden
 *   cancelled  — abgesagt; bleibt stehen, damit die Historie stimmt
 *
 * Drei Festlegungen, die über Sichtbarkeit entscheiden:
 *
 *  1. **Veröffentlicht heisst noch nicht sichtbar.** Sichtbar ist ein Plan
 *     für den, der in der Freigabe steht — als Mannschaft oder als Person.
 *     Ein Plan ohne Freigabe sieht niemand ausser seinem Trainer.
 *
 *  2. **Eine Freigabe geht an eine Mannschaft ODER an eine Person**, nie an
 *     beides in einer Zeile. Sonst wäre nicht zu sagen, was gemeint ist.
 *
 *  3. **Die Mannschaftsfreigabe gilt zum Datum des Plans.** Wer im Juni die
 *     Mannschaft verlassen hat, sieht die Pläne bis Juni weiter und die von
 *     Juli nicht mehr. Wer heute aufgenommen wird, sieht den Plan von
 *     nächster Woche. Darum fragt die Prüfung nicht „ist Mitglied", sondern
 *     „war Mitglied an diesem Tag".
 *
 * Und eine über Nachvollziehbarkeit: Nach der Veröffentlichung wird **jede**
 * Änderung mit einer vollständigen Abschrift festgehalten und die
 * Versionsnummer gezählt. Ein Plan, der sich unter den Schwimmern
 * stillschweigend ändert, ist schlimmer als ein falscher Plan.
 */
class LSV07P_Plan {

    const STATUS = [
        'draft'     => 'Entwurf',
        'published' => 'Veröffentlicht',
        'completed' => 'Durchgeführt',
        'cancelled' => 'Abgesagt',
    ];

    /** Von wo nach wo. Alles andere wird abgelehnt. */
    const WEGE = [
        'draft'     => [ 'published', 'cancelled' ],
        'published' => [ 'completed', 'cancelled', 'draft' ],
        'completed' => [ 'published' ],
        'cancelled' => [ 'draft' ],
    ];

    public static function ist_status( $s ) {
        return isset( self::STATUS[ $s ] );
    }

    public static function status_name( $s ) {
        return self::STATUS[ $s ] ?? (string) $s;
    }

    // ── Lesen ───────────────────────────────────────────────────────────

    public static function holen( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_plan WHERE id = %d", (int) $id ), ARRAY_A );
    }

    public static function sets( $plan_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_set WHERE plan_id = %d ORDER BY position ASC, id ASC",
            (int) $plan_id ), ARRAY_A ) ?: [];
    }

    /**
     * Plan, Sets, Rechnung und Freigaben in einem Stück — so, wie die
     * Oberfläche und der PDF-Bericht ihn brauchen.
     *
     * `$fuer_schwimmer` lässt weg, was nur den Trainer angeht: die internen
     * Notizen. Das Ziel der Einheit bleibt, das soll ein Schwimmer wissen.
     */
    public static function voll( $id, $fuer_schwimmer = false ) {
        $plan = self::holen( $id );
        if ( ! $plan ) return null;

        $sets = self::sets( $id );
        $r    = LSV07P_Rechnung::plan( $plan, $sets );

        $zeilen = [];
        foreach ( $sets as $i => $s ) {
            $rr = $r['zeilen'][ $i ] ?? [];
            $zeilen[] = [
                'id'       => (int) $s['id'],
                'position' => (int) $s['position'],
                'titel'    => (string) $s['titel'],
                'wiederholungen' => (int) $s['wiederholungen'],
                'strecke_m' => (int) $s['strecke_m'],
                'lage'     => (string) $s['lage'],
                'lage_name' => LSV07P_Rechnung::lage_name( $s['lage'] ),
                'intervall_sek' => (int) $s['intervall_sek'],
                'intervall_text' => (int) $s['intervall_sek']
                    ? LSV07P_Rechnung::mmss( (int) $s['intervall_sek'] ) : '',
                'pause_sek' => (int) $s['pause_sek'],
                'pause_text' => (int) $s['pause_sek']
                    ? LSV07P_Rechnung::mmss( (int) $s['pause_sek'] ) : '',
                'material' => (string) $s['material'],
                'hinweis'  => (string) ( $s['hinweis'] ?? '' ),
                'meter'    => $rr['meter'] ?? 0,
                'dauer_sek' => $rr['dauer_sek'] ?? 0,
                'dauer_text' => LSV07P_Rechnung::mmss( $rr['dauer_sek'] ?? 0 ),
                'geschaetzt' => ! empty( $rr['geschaetzt'] ),
                'knapp'    => ! empty( $rr['knapp'] ),
                'halbe_bahn' => ! empty( $rr['halbe_bahn'] ),
                'kurz'     => $rr['text'] ?? '',
            ];
        }

        $out = [
            'id'          => (int) $plan['id'],
            'titel'       => (string) $plan['titel'],
            'datum'       => (string) $plan['datum'],
            'datum_text'  => self::datum_lang( $plan['datum'] ),
            'startzeit'   => $plan['startzeit'] ? substr( (string) $plan['startzeit'], 0, 5 ) : '',
            'dauer_min'   => (int) $plan['dauer_min'],
            'bahnlaenge'  => (int) $plan['bahnlaenge'],
            'ziel'        => (string) $plan['ziel'],
            'status'      => (string) $plan['status'],
            'status_name' => self::status_name( $plan['status'] ),
            'version'     => (int) $plan['version'],
            'zusatz_min'  => (int) $plan['zusatz_min'],
            'trainer'     => LSV07P_Rollen::name( (int) $plan['trainer_user'] ),
            'trainer_user' => (int) $plan['trainer_user'],
            'geaendert_am' => (string) $plan['geaendert_am'],
            'sets'        => $zeilen,
            'summe'       => [
                'meter'      => $r['meter'],
                'meter_text' => LSV07P_Rechnung::meter_text( $r['meter'] ),
                'bahnen'     => $r['bahnen'],
                'dauer_min'  => $r['dauer_min'],
                'dauer_text' => $r['dauer_text'],
                'geschaetzt' => $r['geschaetzt'],
                'soll_min'   => $r['soll_min'],
                'abweichung_min' => $r['abweichung_min'],
                'passt'      => $r['passt'],
                'lagen'      => $r['lagen'],
            ],
        ];

        if ( ! $fuer_schwimmer ) {
            $out['notizen']  = (string) ( $plan['notizen'] ?? '' );
            $out['freigabe'] = self::freigabe( $id );
        }
        return $out;
    }

    // ── Rechte ──────────────────────────────────────────────────────────

    /**
     * Darf das angemeldete Konto diesen Plan ÄNDERN?
     * Der eigene Plan, oder alles für die Verwaltung. Ein zweiter Trainer
     * derselben Mannschaft darf mitlesen, aber nicht in den Plan eines
     * Kollegen schreiben — wer ihn übernehmen will, kopiert ihn.
     */
    public static function darf_aendern( $id ) {
        if ( ! LSV07P_Rollen::darf_planen() ) return false;
        $plan = self::holen( $id );
        if ( ! $plan ) return false;
        if ( LSV07P_Rollen::ist_admin() ) return true;
        return (int) $plan['trainer_user'] === LSV07P_Rollen::user_id();
    }

    /**
     * Darf das angemeldete Konto diesen Plan SEHEN?
     *
     * Das ist die Frage aus der Abnahme: „Ein Schwimmer darf auch bei einem
     * direkten Aufruf einer fremden Trainings-URL keinen Zugriff erhalten."
     * Jeder Endpunkt, der einen Plan herausgibt — Ansicht, PDF, Vollbild,
     * Splitscreen — fragt hier und nirgends anders.
     */
    public static function darf_sehen( $id ) {
        if ( ! LSV07P_Rollen::hat_zugang() ) return false;
        $plan = self::holen( $id );
        if ( ! $plan ) return false;

        if ( LSV07P_Rollen::ist_admin() ) return true;

        $ich = LSV07P_Rollen::user_id();
        if ( LSV07P_Rollen::ist_trainer() ) {
            if ( (int) $plan['trainer_user'] === $ich ) return true;
            /* Mitlesen: Pläne, die für eine der eigenen Mannschaften
               freigegeben sind. Ein Trainer soll wissen, was seine
               Mannschaft bekommt, auch wenn sie zwei Trainer hat. */
            foreach ( self::freigabe_teams( $id ) as $t ) {
                if ( LSV07P_Rollen::darf_team( $t ) ) return true;
            }
            return false;
        }

        return self::sichtbar_fuer( $plan, $ich );
    }

    /**
     * Sieht dieser Schwimmer diesen Plan?
     * Veröffentlicht (oder durchgeführt) und freigegeben — an ihn selbst
     * oder an eine Mannschaft, in der er zum Datum des Plans war.
     */
    public static function sichtbar_fuer( $plan, $wp_user_id ) {
        if ( is_numeric( $plan ) ) $plan = self::holen( $plan );
        if ( ! $plan ) return false;
        $wp_user_id = (int) $wp_user_id;
        if ( ! $wp_user_id ) return false;

        if ( ! in_array( (string) $plan['status'], [ 'published', 'completed' ], true ) ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $direkt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07p_freigabe
              WHERE plan_id = %d AND wp_user_id = %d", (int) $plan['id'], $wp_user_id ) );
        if ( $direkt > 0 ) return true;

        foreach ( self::freigabe_teams( (int) $plan['id'] ) as $t ) {
            if ( LSV07P_Mannschaft::war_mitglied( $t, $wp_user_id, $plan['datum'] ) ) return true;
        }
        return false;
    }

    // ── Schreiben ───────────────────────────────────────────────────────

    public static function anlegen( array $f ) {
        global $wpdb;
        if ( ! LSV07P_Rollen::darf_planen() ) return 0;

        $datum = LSV07P_Mannschaft::datum( $f['datum'] ?? '' );
        if ( $datum === '' ) return 0;

        $p = $wpdb->prefix;
        $wpdb->insert( $p . 'lsv07p_plan', [
            'trainer_user' => LSV07P_Rollen::user_id(),
            'titel'        => self::text( $f['titel'] ?? '', 200 ),
            'datum'        => $datum,
            'startzeit'    => self::zeit( $f['startzeit'] ?? '' ) ?: null,
            'dauer_min'    => self::zahl( $f['dauer_min'] ?? 0, 0, 600 ),
            'bahnlaenge'   => self::bahn( $f['bahnlaenge'] ?? 25 ),
            'ziel'         => self::text( $f['ziel'] ?? '', 150 ),
            'status'       => 'draft',
            'notizen'      => self::mehrzeilig( $f['notizen'] ?? '', 5000 ),
            'version'      => 1,
            'zusatz_min'   => self::zahl( $f['zusatz_min'] ?? 0, 0, 120 ),
            'erstellt_am'  => current_time( 'mysql' ),
            'geaendert_am' => current_time( 'mysql' ),
        ], [ '%d','%s','%s','%s','%d','%d','%s','%s','%s','%d','%d','%s','%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( $id ) LSV07P_DB::spur( 'plan.neu', $id, $datum );
        return $id;
    }

    public static function aendern( $id, array $f ) {
        global $wpdb;
        $id   = (int) $id;
        $plan = self::holen( $id );
        if ( ! $plan ) return false;

        $daten = [];
        $form  = [];
        $setz  = function ( $name, $wert, $typ ) use ( &$daten, &$form ) {
            $daten[ $name ] = $wert; $form[] = $typ;
        };

        if ( isset( $f['titel'] ) )      $setz( 'titel', self::text( $f['titel'], 200 ), '%s' );
        if ( isset( $f['ziel'] ) )       $setz( 'ziel', self::text( $f['ziel'], 150 ), '%s' );
        if ( isset( $f['notizen'] ) )    $setz( 'notizen', self::mehrzeilig( $f['notizen'], 5000 ), '%s' );
        if ( isset( $f['dauer_min'] ) )  $setz( 'dauer_min', self::zahl( $f['dauer_min'], 0, 600 ), '%d' );
        if ( isset( $f['zusatz_min'] ) ) $setz( 'zusatz_min', self::zahl( $f['zusatz_min'], 0, 120 ), '%d' );
        if ( isset( $f['bahnlaenge'] ) ) $setz( 'bahnlaenge', self::bahn( $f['bahnlaenge'] ), '%d' );

        if ( isset( $f['datum'] ) ) {
            $d = LSV07P_Mannschaft::datum( $f['datum'] );
            if ( $d === '' ) return false;
            $setz( 'datum', $d, '%s' );
        }
        if ( isset( $f['startzeit'] ) ) {
            $z = self::zeit( $f['startzeit'] );
            $daten['startzeit'] = $z === '' ? null : $z;
            $form[] = '%s';
        }

        if ( ! $daten ) return true;

        $daten['geaendert_am'] = current_time( 'mysql' );
        $form[] = '%s';

        $wpdb->update( $wpdb->prefix . 'lsv07p_plan', $daten, [ 'id' => $id ], $form, [ '%d' ] );
        self::nach_aenderung( $id, 'Kopfdaten geändert' );
        return true;
    }

    /**
     * Löschen gibt es nur für Entwürfe und Absagen. Ein veröffentlichter
     * Plan wird abgesagt oder abgeschlossen — die Schwimmer haben ihn
     * gesehen, und was sie gesehen haben, verschwindet nicht rückwirkend.
     */
    public static function loeschen( $id ) {
        global $wpdb;
        $id   = (int) $id;
        $plan = self::holen( $id );
        if ( ! $plan ) return false;
        if ( ! in_array( (string) $plan['status'], [ 'draft', 'cancelled' ], true ) ) return false;

        $p = $wpdb->prefix;
        $wpdb->delete( $p . 'lsv07p_set',      [ 'plan_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07p_freigabe', [ 'plan_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07p_version',  [ 'plan_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07p_plan',     [ 'id' => $id ],      [ '%d' ] );
        LSV07P_DB::spur( 'plan.weg', $id, (string) $plan['datum'] );
        return true;
    }

    /**
     * Denselben Inhalt noch einmal, an einem anderen Tag. Das ist der
     * häufigste Vorgang überhaupt — ein Training wiederholt sich. Kopiert
     * werden Kopf und Sets, nicht Freigabe, Status und Versionen: die
     * Kopie ist ein frischer Entwurf.
     */
    public static function kopieren( $id, $datum = '' ) {
        global $wpdb;
        $alt = self::holen( $id );
        if ( ! $alt ) return 0;

        $neu = self::anlegen( [
            'titel'      => $alt['titel'],
            'datum'      => LSV07P_Mannschaft::datum( $datum ) ?: $alt['datum'],
            'startzeit'  => $alt['startzeit'],
            'dauer_min'  => $alt['dauer_min'],
            'bahnlaenge' => $alt['bahnlaenge'],
            'ziel'       => $alt['ziel'],
            'notizen'    => $alt['notizen'],
            'zusatz_min' => $alt['zusatz_min'],
        ] );
        if ( ! $neu ) return 0;

        foreach ( self::sets( $id ) as $s ) {
            self::set_anlegen( $neu, $s, false );
        }
        LSV07P_DB::spur( 'plan.kopie', $neu, 'von Plan ' . (int) $id );
        return $neu;
    }

    // ── Sets ────────────────────────────────────────────────────────────

    public static function set_anlegen( $plan_id, array $f, $spur = true ) {
        global $wpdb;
        $plan_id = (int) $plan_id;
        if ( ! self::holen( $plan_id ) ) return 0;

        $p   = $wpdb->prefix;
        $pos = isset( $f['position'] )
            ? self::zahl( $f['position'], 0, 999 )
            : 1 + (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(MAX(position), 0) FROM {$p}lsv07p_set WHERE plan_id = %d",
                $plan_id ) );

        $wpdb->insert( $p . 'lsv07p_set', [
            'plan_id'       => $plan_id,
            'position'      => $pos,
            'titel'         => self::text( $f['titel'] ?? '', 200 ),
            'wiederholungen' => self::zahl( $f['wiederholungen'] ?? 1, 1, 99 ),
            'strecke_m'     => self::zahl( $f['strecke_m'] ?? 0, 0, 5000 ),
            'lage'          => self::lage( $f['lage'] ?? 'frei' ),
            'intervall_sek' => self::zahl( $f['intervall_sek'] ?? 0, 0, 3600 ),
            'pause_sek'     => self::zahl( $f['pause_sek'] ?? 0, 0, 3600 ),
            'material'      => self::text( $f['material'] ?? '', 200 ),
            'hinweis'       => self::mehrzeilig( $f['hinweis'] ?? '', 1000 ),
        ], [ '%d','%d','%s','%d','%d','%s','%d','%d','%s','%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( $id && $spur ) self::nach_aenderung( $plan_id, 'Set hinzugefügt' );
        return $id;
    }

    public static function set_holen( $set_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_set WHERE id = %d", (int) $set_id ), ARRAY_A );
    }

    public static function set_aendern( $set_id, array $f ) {
        global $wpdb;
        $set = self::set_holen( $set_id );
        if ( ! $set ) return false;

        $daten = [];
        $form  = [];
        $paare = [
            'titel'          => [ 'text', '%s', 200 ],
            'material'       => [ 'text', '%s', 200 ],
            'hinweis'        => [ 'mehrzeilig', '%s', 1000 ],
            'wiederholungen' => [ 'zahl', '%d', [ 1, 99 ] ],
            'strecke_m'      => [ 'zahl', '%d', [ 0, 5000 ] ],
            'intervall_sek'  => [ 'zahl', '%d', [ 0, 3600 ] ],
            'pause_sek'      => [ 'zahl', '%d', [ 0, 3600 ] ],
            'position'       => [ 'zahl', '%d', [ 0, 999 ] ],
        ];
        foreach ( $paare as $name => $wie ) {
            if ( ! isset( $f[ $name ] ) ) continue;
            if ( $wie[0] === 'zahl' ) {
                $daten[ $name ] = self::zahl( $f[ $name ], $wie[2][0], $wie[2][1] );
            } elseif ( $wie[0] === 'mehrzeilig' ) {
                $daten[ $name ] = self::mehrzeilig( $f[ $name ], $wie[2] );
            } else {
                $daten[ $name ] = self::text( $f[ $name ], $wie[2] );
            }
            $form[] = $wie[1];
        }
        if ( isset( $f['lage'] ) ) {
            $daten['lage'] = self::lage( $f['lage'] ); $form[] = '%s';
        }
        if ( ! $daten ) return true;

        $wpdb->update( $wpdb->prefix . 'lsv07p_set', $daten,
            [ 'id' => (int) $set_id ], $form, [ '%d' ] );
        self::nach_aenderung( (int) $set['plan_id'], 'Set geändert' );
        return true;
    }

    public static function set_loeschen( $set_id ) {
        global $wpdb;
        $set = self::set_holen( $set_id );
        if ( ! $set ) return false;
        $wpdb->delete( $wpdb->prefix . 'lsv07p_set', [ 'id' => (int) $set_id ], [ '%d' ] );
        self::nach_aenderung( (int) $set['plan_id'], 'Set entfernt' );
        return true;
    }

    /** Neue Reihenfolge. Nur Sets dieses Plans, alles andere wird übergangen. */
    public static function sets_ordnen( $plan_id, array $ids ) {
        global $wpdb;
        $plan_id = (int) $plan_id;
        $erlaubt = [];
        foreach ( self::sets( $plan_id ) as $s ) $erlaubt[ (int) $s['id'] ] = true;

        $pos = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( ! isset( $erlaubt[ $id ] ) ) continue;
            $pos++;
            $wpdb->update( $wpdb->prefix . 'lsv07p_set', [ 'position' => $pos ],
                [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        }
        if ( $pos ) self::nach_aenderung( $plan_id, 'Reihenfolge geändert' );
        return true;
    }

    // ── Status ──────────────────────────────────────────────────────────

    /**
     * Zustandswechsel. Ohne Set lässt sich nichts veröffentlichen — ein
     * leerer Plan bei den Schwimmern ist schlimmer als kein Plan.
     *
     * Gibt true oder einen Text, der sagt, warum nicht.
     */
    public static function status_setzen( $id, $neu ) {
        global $wpdb;
        $id   = (int) $id;
        $plan = self::holen( $id );
        if ( ! $plan ) return 'Der Plan ist nicht da.';
        if ( ! self::ist_status( $neu ) ) return 'Diesen Zustand gibt es nicht.';

        $alt = (string) $plan['status'];
        if ( $alt === $neu ) return true;
        if ( ! in_array( $neu, self::WEGE[ $alt ] ?? [], true ) ) {
            return 'Von „' . self::status_name( $alt ) . '" nach „'
                 . self::status_name( $neu ) . '" geht es nicht.';
        }
        if ( $neu === 'published' && ! self::sets( $id ) ) {
            return 'Ein Plan ohne Sets lässt sich nicht veröffentlichen.';
        }

        $wpdb->update( $wpdb->prefix . 'lsv07p_plan',
            [ 'status' => $neu, 'geaendert_am' => current_time( 'mysql' ) ],
            [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );

        LSV07P_DB::spur( 'plan.status', $id, $alt . ' → ' . $neu );

        /* Beim ersten Veröffentlichen entsteht Abschrift Nummer eins. Wird
           ein Plan erneut veröffentlicht, nachdem er zurück im Entwurf war,
           zählt die Version hoch — die Schwimmer bekommen dann etwas
           anderes zu sehen als vorher.

           Ein Wechsel auf „durchgeführt" oder „abgesagt" wird
           festgehalten, zählt aber NICHT hoch: Am Inhalt des Plans hat sich
           nichts geändert, und eine Versionsnummer, die bei jedem Häkchen
           steigt, sagt bald nichts mehr. */
        if ( $neu === 'published' ) {
            if ( self::hat_abschrift( $id ) ) {
                self::nach_aenderung( $id, 'wieder veröffentlicht' );
            } else {
                self::abschrift_schreiben( $id, 'veröffentlicht' );
            }
        } else {
            self::nach_aenderung( $id, self::status_name( $neu ), false );
        }
        return true;
    }

    // ── Freigabe ────────────────────────────────────────────────────────

    public static function freigabe( $plan_id ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_freigabe WHERE plan_id = %d ORDER BY id ASC",
            (int) $plan_id ), ARRAY_A ) ?: [];

        $out = [];
        foreach ( $rows as $r ) {
            $team = (int) $r['team_id'];
            $user = (int) $r['wp_user_id'];
            $t    = $team ? LSV07P_Mannschaft::holen( $team ) : null;
            $out[] = [
                'id'      => (int) $r['id'],
                'team_id' => $team,
                'user_id' => $user,
                'art'     => $team ? 'team' : 'person',
                'name'    => $team ? ( $t ? (string) $t['name'] : '(Mannschaft gelöscht)' )
                                   : LSV07P_Rollen::name( $user ),
                'am'      => (string) $r['frei_am'],
            ];
        }
        return $out;
    }

    public static function freigabe_teams( $plan_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT team_id FROM {$p}lsv07p_freigabe WHERE plan_id = %d AND team_id > 0",
            (int) $plan_id ) ) );
    }

    /**
     * Freigabe an eine Mannschaft. Der Trainer darf nur eigene
     * Mannschaften eintragen — sonst könnte er den Plan einer fremden
     * Mannschaft unterschieben.
     */
    public static function freigeben_team( $plan_id, $team_id ) {
        global $wpdb;
        $plan_id = (int) $plan_id;
        $team_id = (int) $team_id;
        if ( ! self::holen( $plan_id ) ) return false;
        if ( ! LSV07P_Rollen::darf_team( $team_id ) ) return false;

        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$p}lsv07p_freigabe
                 (plan_id, team_id, wp_user_id, frei_am, frei_von)
             VALUES (%d, %d, 0, %s, %d)",
            $plan_id, $team_id, current_time( 'mysql' ), LSV07P_Rollen::user_id() ) );
        LSV07P_DB::spur( 'plan.frei.team', $plan_id, 'Mannschaft ' . $team_id );
        return true;
    }

    /**
     * Freigabe an eine einzelne Person — nur an jemanden, den der
     * Angemeldete überhaupt sehen darf.
     */
    public static function freigeben_person( $plan_id, $wp_user_id ) {
        global $wpdb;
        $plan_id    = (int) $plan_id;
        $wp_user_id = (int) $wp_user_id;
        if ( ! self::holen( $plan_id ) ) return false;
        if ( ! LSV07P_Rollen::darf_person( $wp_user_id ) ) return false;
        if ( ! LSV07P_Rollen::person( $wp_user_id ) ) return false;

        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$p}lsv07p_freigabe
                 (plan_id, team_id, wp_user_id, frei_am, frei_von)
             VALUES (%d, 0, %d, %s, %d)",
            $plan_id, $wp_user_id, current_time( 'mysql' ), LSV07P_Rollen::user_id() ) );
        LSV07P_DB::spur( 'plan.frei.person', $plan_id, 'Konto ' . $wp_user_id );
        return true;
    }

    public static function freigabe_entfernen( $plan_id, $freigabe_id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_freigabe WHERE id = %d AND plan_id = %d",
            (int) $freigabe_id, (int) $plan_id ), ARRAY_A );
        if ( ! $row ) return false;

        $wpdb->delete( $p . 'lsv07p_freigabe', [ 'id' => (int) $freigabe_id ], [ '%d' ] );
        LSV07P_DB::spur( 'plan.frei.weg', (int) $plan_id,
            $row['team_id'] ? 'Mannschaft ' . (int) $row['team_id']
                            : 'Konto ' . (int) $row['wp_user_id'] );
        return true;
    }

    // ── Versionen ───────────────────────────────────────────────────────

    /**
     * Nach einer Änderung: Ist der Plan veröffentlicht, zählt die Version
     * hoch und es entsteht eine Abschrift. Ein Entwurf wird nur mit dem
     * Änderungsdatum versehen — bei ihm gibt es nichts nachzuvollziehen,
     * weil ihn niemand gesehen hat.
     */
    public static function nach_aenderung( $plan_id, $was, $zaehlen = true ) {
        global $wpdb;
        $plan_id = (int) $plan_id;
        $plan    = self::holen( $plan_id );
        if ( ! $plan ) return;

        $wpdb->update( $wpdb->prefix . 'lsv07p_plan',
            [ 'geaendert_am' => current_time( 'mysql' ) ],
            [ 'id' => $plan_id ], [ '%s' ], [ '%d' ] );

        if ( ! in_array( (string) $plan['status'], [ 'published', 'completed' ], true ) ) return;

        if ( $zaehlen ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}lsv07p_plan SET version = version + 1 WHERE id = %d",
                $plan_id ) );
        }
        self::abschrift_schreiben( $plan_id, (string) $was );
    }

    /** Gibt es zu diesem Plan überhaupt schon eine Abschrift? */
    public static function hat_abschrift( $plan_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07p_version WHERE plan_id = %d", (int) $plan_id ) ) > 0;
    }

    /** Der vollständige Stand als JSON — lesbar auch nach zehn Änderungen. */
    public static function abschrift_schreiben( $plan_id, $was ) {
        global $wpdb;
        $plan_id = (int) $plan_id;
        $plan    = self::holen( $plan_id );
        if ( ! $plan ) return false;

        $abschrift = wp_json_encode( [
            'plan'     => $plan,
            'sets'     => self::sets( $plan_id ),
            'freigabe' => self::freigabe( $plan_id ),
        ] );

        $wpdb->insert( $wpdb->prefix . 'lsv07p_version', [
            'plan_id'   => $plan_id,
            'version'   => (int) $plan['version'],
            'abschrift' => (string) $abschrift,
            'was'       => mb_substr( (string) $was, 0, 120 ),
            'von_user'  => (int) get_current_user_id(),
            'am'        => current_time( 'mysql' ),
        ], [ '%d', '%d', '%s', '%s', '%d', '%s' ] );
        return true;
    }

    public static function versionen( $plan_id ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, version, was, von_user, am FROM {$p}lsv07p_version
              WHERE plan_id = %d ORDER BY version DESC, id DESC", (int) $plan_id ), ARRAY_A ) ?: [];

        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'id'      => (int) $r['id'],
                'version' => (int) $r['version'],
                'was'     => (string) $r['was'],
                'wer'     => LSV07P_Rollen::name( (int) $r['von_user'] ),
                'am'      => (string) $r['am'],
            ];
        }
        return $out;
    }

    // ── Listen ──────────────────────────────────────────────────────────

    /**
     * Die Pläne, die das angemeldete Trainerkonto sehen darf, in einem
     * Zeitraum. Verwaltung sieht alle, ein Trainer die eigenen und die
     * seiner Mannschaften.
     */
    public static function liste_trainer( $von, $bis, $status = '' ) {
        global $wpdb;
        if ( ! LSV07P_Rollen::darf_planen() ) return [];

        $p   = $wpdb->prefix;
        $von = LSV07P_Mannschaft::datum( $von ) ?: '1970-01-01';
        $bis = LSV07P_Mannschaft::datum( $bis ) ?: '2999-12-31';

        $wo   = [ 'pl.datum BETWEEN %s AND %s' ];
        $args = [ $von, $bis ];

        if ( ! LSV07P_Rollen::ist_admin() ) {
            $ich   = LSV07P_Rollen::user_id();
            $teams = LSV07P_Rollen::eigene_teams();
            if ( $teams ) {
                $platz  = implode( ',', array_fill( 0, count( $teams ), '%d' ) );
                $wo[]   = "(pl.trainer_user = %d OR EXISTS (
                               SELECT 1 FROM {$p}lsv07p_freigabe fg
                                WHERE fg.plan_id = pl.id AND fg.team_id IN ($platz)))";
                $args[] = $ich;
                foreach ( $teams as $t ) $args[] = (int) $t;
            } else {
                $wo[]   = 'pl.trainer_user = %d';
                $args[] = $ich;
            }
        }
        if ( $status !== '' && self::ist_status( $status ) ) {
            $wo[]   = 'pl.status = %s';
            $args[] = $status;
        }

        $sql = "SELECT pl.* FROM {$p}lsv07p_plan pl WHERE " . implode( ' AND ', $wo )
             . ' ORDER BY pl.datum ASC, pl.startzeit ASC, pl.id ASC';

        return self::kurzliste( $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A ) ?: [] );
    }

    /**
     * Die Pläne eines Schwimmers. Dieselbe Prüfung wie `sichtbar_fuer`,
     * nur in SQL — und die Mitgliedschaft wieder zum Datum des Plans.
     */
    public static function liste_schwimmer( $wp_user_id, $von = '', $bis = '' ) {
        global $wpdb;
        $wp_user_id = (int) $wp_user_id;
        if ( ! $wp_user_id ) return [];

        $p   = $wpdb->prefix;
        $von = LSV07P_Mannschaft::datum( $von ) ?: '1970-01-01';
        $bis = LSV07P_Mannschaft::datum( $bis ) ?: '2999-12-31';

        $sql = "SELECT pl.* FROM {$p}lsv07p_plan pl
                 WHERE pl.status IN ('published','completed')
                   AND pl.datum BETWEEN %s AND %s
                   AND (
                     EXISTS ( SELECT 1 FROM {$p}lsv07p_freigabe f
                               WHERE f.plan_id = pl.id AND f.wp_user_id = %d )
                     OR EXISTS ( SELECT 1 FROM {$p}lsv07p_freigabe f2
                                   JOIN {$p}lsv07p_team_mitglied m
                                     ON m.team_id = f2.team_id
                                  WHERE f2.plan_id = pl.id AND f2.team_id > 0
                                    AND m.wp_user_id = %d
                                    AND m.start_datum <= pl.datum
                                    AND (m.ende_datum IS NULL OR m.ende_datum >= pl.datum) )
                   )
              ORDER BY pl.datum DESC, pl.startzeit DESC, pl.id DESC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $von, $bis, $wp_user_id, $wp_user_id ),
            ARRAY_A ) ?: [];
        return self::kurzliste( $rows );
    }

    /** Die Zeilen für Listen und Kalender — ohne Sets, aber mit den Metern. */
    private static function kurzliste( array $rows ) {
        $out = [];
        foreach ( $rows as $r ) {
            $sets = self::sets( (int) $r['id'] );
            $re   = LSV07P_Rechnung::plan( $r, $sets );
            $out[] = [
                'id'          => (int) $r['id'],
                'titel'       => (string) $r['titel'],
                'datum'       => (string) $r['datum'],
                'datum_text'  => self::datum_lang( $r['datum'] ),
                'startzeit'   => $r['startzeit'] ? substr( (string) $r['startzeit'], 0, 5 ) : '',
                'status'      => (string) $r['status'],
                'status_name' => self::status_name( $r['status'] ),
                'version'     => (int) $r['version'],
                'bahnlaenge'  => (int) $r['bahnlaenge'],
                'ziel'        => (string) $r['ziel'],
                'trainer'     => LSV07P_Rollen::name( (int) $r['trainer_user'] ),
                'trainer_user' => (int) $r['trainer_user'],
                'sets'        => count( $sets ),
                'meter'       => $re['meter'],
                'meter_text'  => LSV07P_Rechnung::meter_text( $re['meter'] ),
                'dauer_min'   => $re['dauer_min'],
                'dauer_text'  => $re['dauer_text'],
                'frei_an'     => array_map( function ( $f ) { return $f['name']; },
                                            self::freigabe( (int) $r['id'] ) ),
            ];
        }
        return $out;
    }

    // ── Kleinkram ───────────────────────────────────────────────────────

    public static function text( $roh, $max ) {
        return mb_substr( trim( sanitize_text_field( (string) $roh ) ), 0, (int) $max );
    }

    public static function mehrzeilig( $roh, $max ) {
        return mb_substr( trim( sanitize_textarea_field( (string) $roh ) ), 0, (int) $max );
    }

    public static function zahl( $roh, $min, $max ) {
        return max( (int) $min, min( (int) $max, (int) $roh ) );
    }

    public static function lage( $roh ) {
        $l = (string) $roh;
        return LSV07P_Rechnung::ist_lage( $l ) ? $l : 'frei';
    }

    /** 25 oder 50 — etwas anderes gibt es in einem Schwimmbad nicht. */
    public static function bahn( $roh ) {
        return (int) $roh === 50 ? 50 : 25;
    }

    /** HH:MM oder HH:MM:SS → HH:MM:00, sonst leer. */
    public static function zeit( $roh ) {
        $s = trim( (string) $roh );
        if ( $s === '' ) return '';
        if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m ) ) return '';
        $h = (int) $m[1]; $i = (int) $m[2];
        if ( $h > 23 || $i > 59 ) return '';
        return sprintf( '%02d:%02d:00', $h, $i );
    }

    /** „Montag, 19. Oktober 2026" — ohne Abkürzungen, das liest sich besser. */
    public static function datum_lang( $ymd ) {
        $d = LSV07P_Mannschaft::datum( $ymd );
        if ( $d === '' ) return '';
        $t = strtotime( $d . ' 12:00:00' );
        $tage   = [ 'Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag' ];
        $monate = [ '', 'Januar','Februar','März','April','Mai','Juni',
                        'Juli','August','September','Oktober','November','Dezember' ];
        return $tage[ (int) gmdate( 'w', $t ) ] . ', ' . (int) gmdate( 'j', $t ) . '. '
             . $monate[ (int) gmdate( 'n', $t ) ] . ' ' . gmdate( 'Y', $t );
    }
}
