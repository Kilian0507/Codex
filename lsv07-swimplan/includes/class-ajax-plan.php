<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Endpunkte des Trainerbereichs: Pläne, Sets, Freigaben, Bibliothek
 * und Zeiten.
 *
 * Jeder Endpunkt, der eine Plannummer annimmt, fragt zuerst
 * LSV07P_Plan::darf_sehen() oder ::darf_aendern() — und zwar jeder, auch
 * der, der „nur" eine Liste nachlädt. Eine Nummer aus dem Browser sagt
 * nichts darüber, wem sie gehört.
 *
 * Unterschieden werden zwei Fragen, die oft in einen Topf geworfen werden:
 *
 *   SEHEN   — der eigene Plan, und die Pläne, die einer der eigenen
 *             Mannschaften zugeordnet sind (zwei Trainer, eine Mannschaft)
 *   ÄNDERN  — nur der eigene Plan
 *
 * Wer den Plan eines Kollegen weiterverwenden will, kopiert ihn. Dann hat
 * er einen eigenen und ändert nicht den, auf den sich eine andere
 * Mannschaft schon eingerichtet hat.
 */
class LSV07P_Ajax_Plan {

    public static function init() {
        $map = [
            /* Pläne */
            'lsv07p_p_liste'     => 'liste',
            'lsv07p_p_holen'     => 'holen',
            'lsv07p_p_speichern' => 'speichern',
            'lsv07p_p_weg'       => 'weg',
            'lsv07p_p_kopie'     => 'kopie',
            'lsv07p_p_status'    => 'status',
            'lsv07p_p_zuordnen'  => 'zuordnen',
            'lsv07p_p_versionen' => 'versionen',
            'lsv07p_p_version'   => 'version',
            'lsv07p_p_schwimmer' => 'schwimmer',
            /* Sets */
            'lsv07p_p_set'       => 'set',
            'lsv07p_p_set_weg'   => 'set_weg',
            'lsv07p_p_ordnen'    => 'ordnen',
            /* Der Plan als Datei */
            'lsv07p_p_datei'     => 'datei',
            'lsv07p_p_datei_weg' => 'datei_weg',
            /* Bibliothek */
            'lsv07p_b_liste'     => 'b_liste',
            'lsv07p_b_holen'     => 'b_holen',
            'lsv07p_b_speichern' => 'b_speichern',
            'lsv07p_b_weg'       => 'b_weg',
            'lsv07p_b_set'       => 'b_set',
            'lsv07p_b_set_weg'   => 'b_set_weg',
            'lsv07p_b_ordnen'    => 'b_ordnen',
            'lsv07p_b_einfuegen' => 'b_einfuegen',
            'lsv07p_b_aus_plan'  => 'b_aus_plan',
            /* Zeiten */
            'lsv07p_z_liste'     => 'z_liste',
            'lsv07p_z_neu'       => 'z_neu',
            'lsv07p_z_aendern'   => 'z_aendern',
            'lsv07p_z_weg'       => 'z_weg',
            'lsv07p_z_rangliste' => 'z_rangliste',
        ];
        foreach ( $map as $action => $methode ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $methode ] );
        }
    }

    // ── Türen ───────────────────────────────────────────────────────────

    private static function wache() {
        if ( ! check_ajax_referer( 'lsv07p_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                [ 'message' => 'Die Sitzung ist abgelaufen. Bitte Seite neu laden.' ], 403 );
        }
        if ( ! LSV07P_Rollen::darf_planen() ) {
            wp_send_json_error( [ 'message' => 'Dafür fehlt die Berechtigung.' ], 403 );
        }
    }

    private static function plan_sehen() {
        self::wache();
        $id = (int) ( $_POST['plan_id'] ?? 0 );
        if ( ! LSV07P_Plan::darf_sehen( $id ) ) {
            wp_send_json_error( [ 'message' => 'Dieser Plan gehört nicht zu deinen.' ], 403 );
        }
        return $id;
    }

    private static function plan_aendern() {
        self::wache();
        $id = (int) ( $_POST['plan_id'] ?? 0 );
        if ( ! LSV07P_Plan::darf_aendern( $id ) ) {
            wp_send_json_error( [ 'message' =>
                'Diesen Plan darf nur ändern, wer ihn angelegt hat. '
              . 'Zum Weiterverwenden kopiere ihn.' ], 403 );
        }
        return $id;
    }

    /** Ein Set, dessen Plan dem Anrufer gehört — oder Ende. */
    private static function set_aendern() {
        self::wache();
        $sid = (int) ( $_POST['set_id'] ?? 0 );
        $set = LSV07P_Plan::set_holen( $sid );
        if ( ! $set || ! LSV07P_Plan::darf_aendern( (int) $set['plan_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Dieses Set gehört nicht zu deinen Plänen.' ], 403 );
        }
        return $set;
    }

    private static function feld( $name, $standard = '' ) {
        return isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : $standard;
    }

    /** Die Felder eines Sets, so wie sie aus dem Formular kommen. */
    private static function set_felder() {
        $f = [];
        foreach ( [ 'titel', 'lage', 'material', 'hinweis' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $f[ $n ] = (string) self::feld( $n );
        }
        foreach ( [ 'wiederholungen', 'strecke_m', 'intervall_sek', 'pause_sek', 'position' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $f[ $n ] = (int) self::feld( $n, 0 );
        }
        return $f;
    }

    // ── Pläne ───────────────────────────────────────────────────────────

    public static function liste() {
        self::wache();
        wp_send_json_success( [
            'plaene' => LSV07P_Plan::liste_trainer(
                (string) self::feld( 'von' ),
                (string) self::feld( 'bis' ),
                (string) self::feld( 'status' ) ),
            'status' => LSV07P_Plan::STATUS,
        ] );
    }

    public static function holen() {
        $id   = self::plan_sehen();
        $plan = LSV07P_Plan::voll( $id );
        if ( ! $plan ) wp_send_json_error( [ 'message' => 'Der Plan ist nicht da.' ], 404 );

        $plan['darf_aendern'] = LSV07P_Plan::darf_aendern( $id );
        $plan['lagen']        = self::lagen();
        $plan['wege']         = LSV07P_Plan::WEGE[ $plan['status'] ] ?? [];
        wp_send_json_success( $plan );
    }

    private static function lagen() {
        $aus = [];
        foreach ( LSV07P_Rechnung::LAGEN as $k => $v ) {
            $aus[] = [ 'key' => $k, 'name' => $v['name'], 'zeit' => (bool) $v['zeit'] ];
        }
        return $aus;
    }

    /**
     * Anlegen und Ändern in einem Endpunkt: Ohne `plan_id` ist es neu, mit
     * `plan_id` eine Änderung — und dann durch die Änderungstür.
     */
    public static function speichern() {
        $id = (int) ( $_POST['plan_id'] ?? 0 );

        $felder = [];
        foreach ( [ 'titel', 'datum', 'startzeit', 'ziel', 'notizen' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $felder[ $n ] = (string) self::feld( $n );
        }
        foreach ( [ 'dauer_min', 'bahnlaenge', 'zusatz_min' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $felder[ $n ] = (int) self::feld( $n, 0 );
        }

        if ( ! $id ) {
            self::wache();
            $neu = LSV07P_Plan::anlegen( $felder );
            if ( ! $neu ) {
                wp_send_json_error( [ 'message' => 'Ohne gültiges Datum geht es nicht.' ], 400 );
            }
            wp_send_json_success( [ 'plan_id' => $neu ] );
        }

        self::plan_aendern();
        if ( ! LSV07P_Plan::aendern( $id, $felder ) ) {
            wp_send_json_error( [ 'message' => 'Das Datum ist nicht zu lesen.' ], 400 );
        }
        wp_send_json_success( LSV07P_Plan::voll( $id ) );
    }

    public static function weg() {
        $id = self::plan_aendern();
        if ( ! LSV07P_Plan::loeschen( $id ) ) {
            wp_send_json_error( [ 'message' =>
                'Ein veröffentlichter Plan wird nicht gelöscht, sondern abgesagt — '
              . 'die Schwimmer haben ihn gesehen.' ], 400 );
        }
        wp_send_json_success( [ 'weg' => true ] );
    }

    public static function kopie() {
        $id  = self::plan_sehen();   // kopieren darf man auch einen fremden
        $neu = LSV07P_Plan::kopieren( $id, (string) self::feld( 'datum' ) );
        if ( ! $neu ) wp_send_json_error( [ 'message' => 'Die Kopie ist nicht entstanden.' ], 400 );
        wp_send_json_success( [ 'plan_id' => $neu ] );
    }

    public static function status() {
        $id  = self::plan_aendern();
        $neu = (string) self::feld( 'status' );

        $erg = LSV07P_Plan::status_setzen( $id, $neu );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ], 400 );

        $plan = LSV07P_Plan::voll( $id );
        /* Veröffentlicht, aber niemandem zugeordnet: Das ist erlaubt, aber
           fast immer nicht gemeint. Also sagen. */
        $warnung = ( $neu === 'published' && empty( $plan['zuordnung'] ) )
            ? 'Der Plan ist veröffentlicht, aber noch keiner Mannschaft zugeordnet — '
            . 'es steht also nicht dabei, für wen er ist.'
            : '';
        wp_send_json_success( [ 'plan' => $plan, 'warnung' => $warnung ] );
    }

    public static function zuordnen() {
        $id  = self::plan_aendern();
        $art = (string) self::feld( 'art' );

        if ( $art === 'weg' ) {
            if ( ! LSV07P_Plan::zuordnung_entfernen( $id, (int) self::feld( 'zuordnung_id', 0 ) ) ) {
                wp_send_json_error( [ 'message' =>
                    'Diese Zuordnung gehört nicht zu diesem Plan.' ], 403 );
            }
        } elseif ( $art === 'team' ) {
            if ( ! LSV07P_Plan::zuordnen_team( $id, (int) self::feld( 'team_id', 0 ) ) ) {
                wp_send_json_error( [ 'message' => 'Diese Mannschaft gehört nicht zu deinen.' ], 403 );
            }
        } elseif ( $art === 'schwimmer' ) {
            if ( ! LSV07P_Plan::zuordnen_schwimmer( $id, (int) self::feld( 'schwimmer_id', 0 ) ) ) {
                wp_send_json_error( [ 'message' =>
                    'Dieser Schwimmer ist in keiner deiner Mannschaften.' ], 403 );
            }
        } else {
            wp_send_json_error( [ 'message' => 'Unbekannte Art der Zuordnung.' ], 400 );
        }

        wp_send_json_success( [ 'zuordnung' => LSV07P_Plan::zuordnung( $id ),
                                'teilnehmer' => LSV07P_Plan::teilnehmer( $id ) ] );
    }

    public static function versionen() {
        $id = self::plan_sehen();
        wp_send_json_success( [ 'versionen' => LSV07P_Plan::versionen( $id ) ] );
    }

    /** Eine einzelne Abschrift — zum Nachlesen, was damals darin stand. */
    public static function version() {
        global $wpdb;
        $id  = self::plan_sehen();
        $vid = (int) self::feld( 'version_id', 0 );

        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_version WHERE id = %d AND plan_id = %d",
            $vid, $id ), ARRAY_A );
        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Diese Fassung gehört nicht zu diesem Plan.' ], 403 );
        }

        $daten = json_decode( (string) $row['abschrift'], true );
        wp_send_json_success( [
            'version' => (int) $row['version'],
            'was'     => (string) $row['was'],
            'wer'     => LSV07P_Rollen::name( (int) $row['von_user'] ),
            'am'      => LSV07P_Bericht::de_zeit( $row['am'] ),
            'abschrift' => is_array( $daten ) ? $daten : null,
        ] );
    }

    /**
     * Die Schwimmer, die der Anrufer sehen darf — für die Zeiterfassung
     * und die Freigabe an einzelne Personen. Nicht alle Konten des
     * Vereins, sondern die aus den eigenen Mannschaften.
     */
    public static function schwimmer() {
        self::wache();

        $aus = [];
        $hab = [];
        foreach ( LSV07P_Rollen::eigene_teams() as $t ) {
            $team = LSV07P_Mannschaft::holen( $t );
            foreach ( LSV07P_Mannschaft::mitglieder( $t ) as $m ) {
                $sid = (int) $m['schwimmer_id'];
                if ( isset( $hab[ $sid ] ) ) {
                    $aus[ $hab[ $sid ] ]['teams'][] = $team ? (string) $team['name'] : '';
                    continue;
                }
                $hab[ $sid ] = count( $aus );
                $aus[] = [
                    'schwimmer_id' => $sid,
                    'name'         => $m['name'],
                    'jahrgang'     => $m['jahrgang'],
                    'aktiv'        => $m['aktiv'],
                    'teams'        => [ $team ? (string) $team['name'] : '' ],
                ];
            }
        }
        usort( $aus, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        wp_send_json_success( [ 'schwimmer' => $aus ] );
    }

    // ── Sets ────────────────────────────────────────────────────────────

    public static function set() {
        $sid = (int) ( $_POST['set_id'] ?? 0 );

        if ( $sid ) {
            $set = self::set_aendern();
            LSV07P_Plan::set_aendern( $sid, self::set_felder() );
            wp_send_json_success( LSV07P_Plan::voll( (int) $set['plan_id'] ) );
        }

        $pid = self::plan_aendern();
        $neu = LSV07P_Plan::set_anlegen( $pid, self::set_felder() );
        if ( ! $neu ) wp_send_json_error( [ 'message' => 'Das Set ist nicht entstanden.' ], 400 );
        wp_send_json_success( LSV07P_Plan::voll( $pid ) );
    }

    public static function set_weg() {
        $set = self::set_aendern();
        LSV07P_Plan::set_loeschen( (int) $set['id'] );
        wp_send_json_success( LSV07P_Plan::voll( (int) $set['plan_id'] ) );
    }

    public static function ordnen() {
        $id  = self::plan_aendern();
        $roh = self::feld( 'ids', '' );
        $ids = is_array( $roh ) ? $roh : explode( ',', (string) $roh );
        LSV07P_Plan::sets_ordnen( $id, array_map( 'intval', $ids ) );
        wp_send_json_success( LSV07P_Plan::voll( $id ) );
    }

    // ── Der Plan als Datei ──────────────────────────────────────────────

    /**
     * Ein PDF an die Einheit hängen. Durch dieselbe Tür wie jede andere
     * Änderung: Nur wer den Plan ändern darf, hängt etwas daran.
     */
    public static function datei() {
        $pid = self::plan_aendern();

        $datei = isset( $_FILES['datei'] ) ? $_FILES['datei'] : [];
        $erg   = LSV07P_Dateien::ablegen( $pid, (array) $datei );
        if ( empty( $erg['ok'] ) ) {
            wp_send_json_error( [ 'message' => $erg['message'] ?? 'Das ging nicht.' ], 400 );
        }

        wp_send_json_success( [ 'datei' => $erg['datei'], 'plan' => LSV07P_Plan::voll( $pid ) ] );
    }

    /**
     * Eine Datei entfernen. Die Nummer der Datei muss zu DIESEM Plan
     * gehören — sonst liesse sich über den eigenen Plan die Datei eines
     * fremden löschen.
     */
    public static function datei_weg() {
        $pid = self::plan_aendern();
        $did = (int) self::feld( 'datei_id', 0 );

        $d = LSV07P_Dateien::holen( $did );
        if ( ! $d || (int) $d['plan_id'] !== $pid ) {
            wp_send_json_error( [ 'message' => 'Diese Datei gehört nicht zu diesem Plan.' ], 403 );
        }

        LSV07P_Dateien::loeschen( $did );
        LSV07P_Plan::nach_aenderung( $pid, 'Datei entfernt' );
        wp_send_json_success( LSV07P_Plan::voll( $pid ) );
    }

    // ── Bibliothek ──────────────────────────────────────────────────────

    public static function b_liste() {
        self::wache();
        wp_send_json_success( [
            'vorlagen'   => LSV07P_Vorlage::liste(
                (string) self::feld( 'kategorie' ), (string) self::feld( 'suche' ) ),
            'kategorien' => LSV07P_Vorlage::KATEGORIEN,
            'lagen'      => self::lagen(),
        ] );
    }

    private static function b_sehen() {
        self::wache();
        $id = (int) ( $_POST['vorlage_id'] ?? 0 );
        if ( ! LSV07P_Vorlage::darf_sehen( $id ) ) {
            wp_send_json_error( [ 'message' => 'Diese Vorlage ist nicht für dich freigegeben.' ], 403 );
        }
        return $id;
    }

    private static function b_aendern() {
        self::wache();
        $id = (int) ( $_POST['vorlage_id'] ?? 0 );
        if ( ! LSV07P_Vorlage::darf_aendern( $id ) ) {
            wp_send_json_error( [ 'message' =>
                'Ändern darf nur, wer die Vorlage angelegt hat. Eine öffentliche Vorlage '
              . 'ist zum Benutzen freigegeben, nicht zum Umschreiben.' ], 403 );
        }
        return $id;
    }

    public static function b_holen() {
        $id = self::b_sehen();
        $v  = LSV07P_Vorlage::voll( $id );
        if ( ! $v ) wp_send_json_error( [ 'message' => 'Die Vorlage ist nicht da.' ], 404 );
        $v['darf_aendern'] = LSV07P_Vorlage::darf_aendern( $id );
        wp_send_json_success( $v );
    }

    public static function b_speichern() {
        $id = (int) ( $_POST['vorlage_id'] ?? 0 );

        $felder = [
            'name'        => (string) self::feld( 'name' ),
            'beschreibung' => (string) self::feld( 'beschreibung' ),
            'kategorie'   => (string) self::feld( 'kategorie' ),
            'oeffentlich' => (string) self::feld( 'oeffentlich' ) === '1',
        ];

        if ( ! $id ) {
            self::wache();
            $neu = LSV07P_Vorlage::anlegen( $felder );
            if ( ! $neu ) wp_send_json_error( [ 'message' => 'Der Name fehlt.' ], 400 );
            wp_send_json_success( [ 'vorlage_id' => $neu ] );
        }

        self::b_aendern();
        if ( ! LSV07P_Vorlage::aendern( $id, $felder ) ) {
            wp_send_json_error( [ 'message' => 'Der Name darf nicht leer sein.' ], 400 );
        }
        wp_send_json_success( LSV07P_Vorlage::voll( $id ) );
    }

    public static function b_weg() {
        $id = self::b_aendern();
        LSV07P_Vorlage::loeschen( $id );
        wp_send_json_success( [ 'weg' => true ] );
    }

    public static function b_set() {
        $id  = self::b_aendern();
        $sid = (int) self::feld( 'set_id', 0 );

        if ( $sid ) {
            /* Das Set muss zu DIESER Vorlage gehören. */
            $set = LSV07P_Vorlage::set_holen( $sid );
            if ( ! $set || (int) $set['vorlage_id'] !== $id ) {
                wp_send_json_error( [ 'message' => 'Dieses Set gehört nicht zu dieser Vorlage.' ], 403 );
            }
            LSV07P_Vorlage::set_aendern( $sid, self::set_felder() );
        } else {
            LSV07P_Vorlage::set_anlegen( $id, self::set_felder() );
        }
        wp_send_json_success( LSV07P_Vorlage::voll( $id ) );
    }

    public static function b_set_weg() {
        $id  = self::b_aendern();
        $sid = (int) self::feld( 'set_id', 0 );
        $set = LSV07P_Vorlage::set_holen( $sid );
        if ( ! $set || (int) $set['vorlage_id'] !== $id ) {
            wp_send_json_error( [ 'message' => 'Dieses Set gehört nicht zu dieser Vorlage.' ], 403 );
        }
        LSV07P_Vorlage::set_loeschen( $sid );
        wp_send_json_success( LSV07P_Vorlage::voll( $id ) );
    }

    public static function b_ordnen() {
        $id  = self::b_aendern();
        $roh = self::feld( 'ids', '' );
        $ids = is_array( $roh ) ? $roh : explode( ',', (string) $roh );
        LSV07P_Vorlage::sets_ordnen( $id, array_map( 'intval', $ids ) );
        wp_send_json_success( LSV07P_Vorlage::voll( $id ) );
    }

    /**
     * Vorlage in einen Plan einfügen. Zwei Türen auf einmal: die Vorlage
     * muss freigegeben sein UND der Plan dem Anrufer gehören.
     */
    public static function b_einfuegen() {
        $vid = self::b_sehen();
        $pid = (int) self::feld( 'plan_id', 0 );
        if ( ! LSV07P_Plan::darf_aendern( $pid ) ) {
            wp_send_json_error( [ 'message' => 'Dieser Plan gehört nicht zu deinen.' ], 403 );
        }

        $n = LSV07P_Vorlage::einfuegen( $vid, $pid );
        if ( ! $n ) wp_send_json_error( [ 'message' => 'Die Vorlage hat keine Sets.' ], 400 );
        wp_send_json_success( [ 'anzahl' => $n, 'plan' => LSV07P_Plan::voll( $pid ) ] );
    }

    public static function b_aus_plan() {
        $pid = self::plan_sehen();
        $id  = LSV07P_Vorlage::aus_plan( $pid,
            (string) self::feld( 'name' ),
            (string) self::feld( 'kategorie' ),
            (string) self::feld( 'oeffentlich' ) === '1' );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Die Vorlage ist nicht entstanden.' ], 400 );
        wp_send_json_success( [ 'vorlage_id' => $id ] );
    }

    // ── Zeiten ──────────────────────────────────────────────────────────

    /** Der Schwimmer, dessen Zeiten gemeint sind — oder Ende. */
    private static function person() {
        self::wache();
        $sid = (int) ( $_POST['schwimmer_id'] ?? 0 );
        if ( ! LSV07P_Schwimmer::darf( $sid ) ) {
            wp_send_json_error( [ 'message' =>
                'Dieser Schwimmer ist in keiner deiner Mannschaften.' ], 403 );
        }
        return $sid;
    }

    public static function z_liste() {
        $sid = self::person();
        wp_send_json_success( [
            'name'      => LSV07P_Schwimmer::name( $sid ),
            'zeiten'    => LSV07P_Zeiten::liste( $sid ),
            'bestzeiten' => LSV07P_Zeiten::bestzeiten( $sid ),
            'strecken'  => LSV07P_Zeiten::STRECKEN,
            'lagen'     => LSV07P_Rechnung::lagen_mit_zeit(),
        ] );
    }

    public static function z_neu() {
        $sid = self::person();

        $erg = LSV07P_Zeiten::erfassen( [
            'schwimmer_id' => $sid,
            'zeit'        => (string) self::feld( 'zeit' ),
            'strecke_m'   => (int) self::feld( 'strecke_m', 0 ),
            'lage'        => (string) self::feld( 'lage' ),
            'bahnlaenge'  => (int) self::feld( 'bahnlaenge', 25 ),
            'erreicht_am' => (string) self::feld( 'erreicht_am' ),
            'wettkampf'   => (string) self::feld( 'wettkampf' ),
            'offiziell'   => (string) self::feld( 'offiziell' ) === '1',
            'bemerkung'   => (string) self::feld( 'bemerkung' ),
        ] );
        if ( ! is_int( $erg ) ) wp_send_json_error( [ 'message' => (string) $erg ], 400 );

        wp_send_json_success( [
            'zeiten'     => LSV07P_Zeiten::liste( $sid ),
            'bestzeiten' => LSV07P_Zeiten::bestzeiten( $sid ),
        ] );
    }

    /** Eine Zeit, die zu einem Schwimmer gehört, den der Anrufer sehen darf. */
    private static function zeit() {
        self::wache();
        $zid = (int) ( $_POST['zeit_id'] ?? 0 );
        $z   = LSV07P_Zeiten::holen( $zid );
        if ( ! $z || ! LSV07P_Schwimmer::darf( (int) $z['schwimmer_id'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Zeit gehört nicht zu deinen Schwimmern.' ], 403 );
        }
        return $z;
    }

    public static function z_aendern() {
        $z = self::zeit();

        $felder = [];
        foreach ( [ 'zeit', 'lage', 'erreicht_am', 'wettkampf', 'bemerkung' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $felder[ $n ] = (string) self::feld( $n );
        }
        foreach ( [ 'strecke_m', 'bahnlaenge' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $felder[ $n ] = (int) self::feld( $n, 0 );
        }
        if ( isset( $_POST['offiziell'] ) ) {
            $felder['offiziell'] = (string) self::feld( 'offiziell' ) === '1';
        }

        $erg = LSV07P_Zeiten::aendern( (int) $z['id'], $felder );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ], 400 );

        $sid = (int) $z['schwimmer_id'];
        wp_send_json_success( [
            'zeiten'     => LSV07P_Zeiten::liste( $sid ),
            'bestzeiten' => LSV07P_Zeiten::bestzeiten( $sid ),
        ] );
    }

    public static function z_weg() {
        $z   = self::zeit();
        $sid = (int) $z['schwimmer_id'];
        LSV07P_Zeiten::loeschen( (int) $z['id'] );
        wp_send_json_success( [
            'zeiten'     => LSV07P_Zeiten::liste( $sid ),
            'bestzeiten' => LSV07P_Zeiten::bestzeiten( $sid ),
        ] );
    }

    public static function z_rangliste() {
        self::wache();
        $team = (int) self::feld( 'team_id', 0 );
        if ( ! LSV07P_Rollen::darf_team( $team ) ) {
            wp_send_json_error( [ 'message' => 'Diese Mannschaft gehört nicht zu deinen.' ], 403 );
        }
        wp_send_json_success( [
            'rangliste' => LSV07P_Zeiten::rangliste( $team,
                (int) self::feld( 'strecke_m', 0 ),
                (string) self::feld( 'lage' ),
                (int) self::feld( 'bahnlaenge', 25 ) ),
        ] );
    }
}
