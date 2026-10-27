<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auskunft (Art. 15) und Löschung (Art. 17) über die Bordmittel von
 * WordPress — für SwimPlan.
 *
 * WordPress bringt unter **Werkzeuge → Personenbezogene Daten exportieren**
 * und **→ löschen** einen fertigen Ablauf mit. Jedes Plugin trägt über
 * zwei Filter bei, was es gespeichert hat.
 *
 * **Schwimmer haben hier kein WordPress-Konto** — das ist der Kern des
 * Plugins und zugleich die Schwierigkeit bei der Auskunft: WordPress
 * kennt nur eine E-Mail-Adresse, SwimPlan kennt Vorname, Nachname,
 * Jahrgang und DSV-ID. Gesucht wird darum auf drei Wegen:
 *
 * 1. Über das **WordPress-Konto** (`lsv07p_person`, `lsv07p_team_trainer`,
 *    Urheberschaft an Plänen) — das trifft Trainerinnen und Verwaltung.
 * 2. Über `aus_konto`, wenn ein Schwimmer aus einem Konto entstanden ist.
 * 3. Über die **DSV-ID**, die der interne Bereich zu dieser Adresse führt.
 *    Nur so bekommt eine Schwimmerin ohne Konto Auskunft über ihre
 *    Trainingspläne und Zeiten. Gibt es den internen Bereich nicht oder
 *    steht dort keine Adresse, ist über die Adresse auch nichts
 *    zuzuordnen — das ist die Folge davon, dass sie nie erhoben wurde,
 *    und keine Lücke, die sich hier schliessen liesse.
 *
 * Aufbewahrungspflichten gibt es hier keine: Ein Trainingsplan ist kein
 * Buchungsbeleg. Gelöscht wird deshalb wirklich — bis auf das, was für
 * ANDERE stehen bleiben muss (ein Plan, den eine Mannschaft noch
 * schwimmt). Dort geht der Name weg und der Inhalt bleibt.
 */
class LSV07P_Privacy {

    const SCHLUESSEL = 'lsv07-swimplan';
    const NAME       = 'LSV07 SwimPlan';
    const PRO_SEITE  = 200;

    /** Tabellen ohne Personenbezug. */
    const OHNE_PERSON = [
        'lsv07p_team',         // Name einer Mannschaft
        'lsv07p_set',          // der Inhalt eines Plans
        'lsv07p_vorlage_set',  // der Inhalt einer Vorlage
    ];

    const GEHEIM = [ 'ip_hash' ];

    const WOERTER = [
        'id' => 'Nummer', 'vorname' => 'Vorname', 'nachname' => 'Nachname',
        'jahrgang' => 'Jahrgang', 'dsv_id' => 'DSV-ID', 'notiz' => 'Notiz',
        'aktiv' => 'Aktiv', 'rolle' => 'Rolle', 'titel' => 'Titel',
        'datum' => 'Datum', 'startzeit' => 'Startzeit',
        'dauer_min' => 'Dauer in Minuten', 'zusatz_min' => 'Zusatzzeit in Minuten',
        'bahnlaenge' => 'Bahnlänge', 'ziel' => 'Trainingsziel',
        'status' => 'Stand', 'notizen' => 'Notizen', 'version' => 'Fassung',
        'beschreibung' => 'Beschreibung', 'kategorie' => 'Kategorie',
        'oeffentlich' => 'Öffentlich', 'strecke_m' => 'Strecke in Metern',
        'lage' => 'Lage', 'hundertstel' => 'Zeit in Hundertsteln',
        'erreicht_am' => 'Erreicht am', 'wettkampf' => 'Wettkampf',
        'offiziell' => 'Offiziell', 'bemerkung' => 'Bemerkung',
        'dateiname' => 'Dateiname', 'mime_type' => 'Typ',
        'groesse' => 'Grösse in Bytes', 'seiten' => 'Seiten',
        'hochgeladen_am' => 'Hochgeladen am', 'angelegt_am' => 'Angelegt am',
        'erstellt_am' => 'Erstellt am', 'geaendert_am' => 'Geändert am',
        'erfasst_am' => 'Erfasst am', 'frei_am' => 'Freigegeben am',
        'start_datum' => 'Im Team seit', 'ende_datum' => 'Im Team bis',
        'zugeteilt_am' => 'Zugeteilt am', 'was' => 'Vorgang',
        'hinweis' => 'Hinweis', 'am' => 'Zeitpunkt', 'abschrift' => 'Fassung',
        'team_id' => 'Mannschaft (Nummer)', 'plan_id' => 'Plan (Nummer)',
        'schwimmer_id' => 'Schwimmer (Nummer)',
    ];

    public static function init() {
        add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'exporter_anmelden' ] );
        add_filter( 'wp_privacy_personal_data_erasers',   [ __CLASS__, 'loescher_anmelden' ] );
        add_action( 'admin_init', [ __CLASS__, 'erklaerung_vorschlagen' ] );
    }

    public static function exporter_anmelden( $liste ) {
        $liste[ self::SCHLUESSEL ] = [
            'exporter_friendly_name' => self::NAME,
            'callback'               => [ __CLASS__, 'exportieren' ],
        ];
        return $liste;
    }

    public static function loescher_anmelden( $liste ) {
        $liste[ self::SCHLUESSEL ] = [
            'eraser_friendly_name' => self::NAME,
            'callback'             => [ __CLASS__, 'loeschen' ],
        ];
        return $liste;
    }

    private static function t( $name ) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private static function tabelle_da( $name ) {
        global $wpdb;
        static $bekannt = [];
        if ( isset( $bekannt[ $name ] ) ) return $bekannt[ $name ];
        $voll = self::t( $name );
        return $bekannt[ $name ] =
            (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $voll ) ) === $voll;
    }

    // ── Wen meint diese E-Mail? ──────────────────────────────────────────

    public static function kennungen( $email ) {
        global $wpdb;
        $email = trim( (string) $email );
        $k = [ 'user' => 0, 'schwimmer' => 0 ];
        if ( $email === '' ) return $k;

        $user = get_user_by( 'email', $email );
        $k['user'] = $user ? (int) $user->ID : 0;

        if ( ! self::tabelle_da( 'lsv07p_schwimmer' ) ) return $k;

        /* Weg 2: aus einem Konto entstanden. */
        if ( $k['user'] ) {
            $k['schwimmer'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . self::t( 'lsv07p_schwimmer' ) . "
                  WHERE aus_konto = %d ORDER BY id ASC LIMIT 1", $k['user'] ) );
        }

        /* Weg 3: über die DSV-ID aus dem internen Bereich. Die Brücke wird
           nur betreten, wenn es ihn gibt — SwimPlan läuft auch allein. */
        if ( ! $k['schwimmer'] ) {
            $dsv = self::dsv_aus_intern( $email );
            if ( $dsv !== '' ) {
                $k['schwimmer'] = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM " . self::t( 'lsv07p_schwimmer' ) . "
                      WHERE dsv_id = %s ORDER BY id ASC LIMIT 1", $dsv ) );
            }
        }
        return $k;
    }

    /** Die DSV-ID, die der interne Bereich zu dieser Adresse führt. */
    private static function dsv_aus_intern( $email ) {
        global $wpdb;
        foreach ( [ 'mv_swimmers' => 'dsv_id', 'lsv07i_personen' => 'dsv_id' ] as $tabelle => $spalte ) {
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            $dsv = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT $spalte FROM " . self::t( $tabelle ) . "
                  WHERE email = %s AND $spalte <> '' ORDER BY id ASC LIMIT 1", $email ) );
            if ( $dsv !== '' ) return $dsv;
        }
        return '';
    }

    private static function jemand( $k ) {
        return $k['user'] > 0 || $k['schwimmer'] > 0;
    }

    // ── Die Quellen ──────────────────────────────────────────────────────

    private static function quellen() {
        return [
            [ 'lsv07p_person',        'Zugang und Rolle',
              [ [ 'wp_user_id', 'user' ], [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07p_schwimmer',     'Schwimmerdaten',
              [ [ 'id', 'schwimmer' ], [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07p_team_trainer',  'Betreute Mannschaften',
              [ [ 'wp_user_id', 'user' ] ] ],
            [ 'lsv07p_team_mitglied', 'Zugehörigkeit zu Mannschaften',
              [ [ 'schwimmer_id', 'schwimmer' ] ] ],
            [ 'lsv07p_plan',          'Erstellte Trainingspläne',
              [ [ 'trainer_user', 'user' ] ] ],
            [ 'lsv07p_version',       'Änderungen an Trainingsplänen',
              [ [ 'von_user', 'user' ] ] ],
            [ 'lsv07p_freigabe',      'Für diese Person freigegebene Pläne',
              [ [ 'schwimmer_id', 'schwimmer' ], [ 'frei_von', 'user' ] ] ],
            [ 'lsv07p_vorlage',       'Angelegte Vorlagen',
              [ [ 'erstellt_von', 'user' ] ] ],
            [ 'lsv07p_zeit',          'Erfasste Zeiten',
              [ [ 'schwimmer_id', 'schwimmer' ], [ 'erfasst_von', 'user' ] ] ],
            [ 'lsv07p_datei',         'Hochgeladene Dateien',
              [ [ 'hochgeladen_von', 'user' ] ] ],
            [ 'lsv07p_spur',          'Protokoll',
              [ [ 'user_id', 'user' ] ] ],
        ];
    }

    public static function angebunden() {
        return array_values( array_unique( array_column( self::quellen(), 0 ) ) );
    }

    private static function bedingung( $wo, $k ) {
        $teile = [];
        foreach ( $wo as $t ) {
            list( $spalte, $art ) = $t;
            if ( empty( $k[ $art ] ) ) continue;
            $teile[] = sprintf( '%s = %d', $spalte, (int) $k[ $art ] );
        }
        return $teile ? '( ' . implode( ' OR ', $teile ) . ' )' : '';
    }

    // ── Auskunft ─────────────────────────────────────────────────────────

    public static function exportieren( $email, $seite = 1 ) {
        $k = self::kennungen( $email );
        if ( ! self::jemand( $k ) ) return [ 'data' => [], 'done' => true ];

        $plan = self::plan( $k );
        if ( ! $plan ) return [ 'data' => [], 'done' => true ];

        $seite = max( 1, (int) $seite );
        if ( $seite > count( $plan ) ) return [ 'data' => [], 'done' => true ];

        $s = $plan[ $seite - 1 ];
        return [
            'data' => self::seite_holen( $k, $s['quelle'], $s['offset'] ),
            'done' => $seite >= count( $plan ),
        ];
    }

    private static function plan( $k ) {
        global $wpdb;
        $plan = [];
        foreach ( self::quellen() as $i => $q ) {
            if ( ! self::tabelle_da( $q[0] ) ) continue;
            $bed = self::bedingung( $q[2], $k );
            if ( $bed === '' ) continue;
            $anzahl = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::t( $q[0] ) . " WHERE $bed" );
            for ( $off = 0; $off < $anzahl; $off += self::PRO_SEITE ) {
                $plan[] = [ 'quelle' => $i, 'offset' => $off ];
            }
        }
        return $plan;
    }

    private static function seite_holen( $k, $nr, $offset ) {
        global $wpdb;
        list( $tabelle, $titel, $wo ) = self::quellen()[ $nr ];
        $bed = self::bedingung( $wo, $k );
        if ( $bed === '' ) return [];

        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( $tabelle ) . " WHERE $bed LIMIT %d OFFSET %d",
            self::PRO_SEITE, (int) $offset ), ARRAY_A );

        $raus = [];
        $lfd = (int) $offset;
        foreach ( (array) $zeilen as $z ) {
            $lfd++;
            $daten = [];
            foreach ( $z as $spalte => $wert ) {
                if ( in_array( $spalte, self::GEHEIM, true ) ) continue;
                if ( $wert === null || $wert === '' ) continue;
                $daten[] = [
                    'name'  => self::WOERTER[ $spalte ] ?? $spalte,
                    'value' => wp_strip_all_tags( (string) $wert ),
                ];
            }
            if ( ! $daten ) continue;
            $raus[] = [
                'group_id'    => $tabelle,
                'group_label' => self::NAME . ': ' . $titel,
                'item_id'     => $tabelle . '-' . ( isset( $z['id'] ) ? (int) $z['id'] : $lfd ),
                'data'        => $daten,
            ];
        }
        return $raus;
    }

    // ── Löschung ─────────────────────────────────────────────────────────

    public static function loeschen( $email, $seite = 1 ) {
        $leer = [ 'items_removed' => false, 'items_retained' => false,
                  'messages' => [], 'done' => true ];
        $k = self::kennungen( $email );
        if ( ! self::jemand( $k ) ) return $leer;
        if ( (int) $seite > 1 ) return $leer;

        global $wpdb;
        $weg = 0; $meldungen = [];

        /* 1. Der Schwimmer: Zeiten, Teamzugehörigkeit, Freigaben, der
              Eintrag selbst. Nichts davon muss aufbewahrt werden. */
        if ( $k['schwimmer'] ) {
            foreach ( [ 'lsv07p_zeit'          => 'schwimmer_id',
                        'lsv07p_team_mitglied' => 'schwimmer_id',
                        'lsv07p_freigabe'      => 'schwimmer_id' ] as $tabelle => $spalte ) {
                if ( ! self::tabelle_da( $tabelle ) ) continue;
                $n = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM " . self::t( $tabelle ) . " WHERE $spalte = %d",
                    $k['schwimmer'] ) );
                if ( $n ) $weg += (int) $n;
            }
            if ( self::tabelle_da( 'lsv07p_schwimmer' ) ) {
                $wpdb->delete( self::t( 'lsv07p_schwimmer' ),
                    [ 'id' => $k['schwimmer'] ], [ '%d' ] );
                $weg++;
            }
            $meldungen[] = 'Schwimmereintrag, erfasste Zeiten, Mannschaftszugehörigkeit und '
                         . 'persönliche Planfreigaben wurden gelöscht.';
        }

        /* 2. Das Konto: Zugang und Spur weg, Urheberschaft anonym. */
        if ( $k['user'] ) {
            foreach ( [ 'lsv07p_person'       => 'wp_user_id',
                        'lsv07p_team_trainer' => 'wp_user_id',
                        'lsv07p_spur'         => 'user_id' ] as $tabelle => $spalte ) {
                if ( ! self::tabelle_da( $tabelle ) ) continue;
                $n = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM " . self::t( $tabelle ) . " WHERE $spalte = %d", $k['user'] ) );
                if ( $n ) $weg += (int) $n;
            }

            $anonym = [
                [ 'lsv07p_plan',      'trainer_user = 0',    'trainer_user = %d' ],
                [ 'lsv07p_version',   'von_user = 0',        'von_user = %d' ],
                [ 'lsv07p_vorlage',   'erstellt_von = 0',    'erstellt_von = %d' ],
                [ 'lsv07p_datei',     'hochgeladen_von = 0', 'hochgeladen_von = %d' ],
                [ 'lsv07p_zeit',      'erfasst_von = 0',     'erfasst_von = %d' ],
                [ 'lsv07p_freigabe',  'frei_von = 0',        'frei_von = %d' ],
                [ 'lsv07p_schwimmer', 'angelegt_von = 0',    'angelegt_von = %d' ],
            ];
            $n_anonym = 0;
            foreach ( $anonym as $a ) {
                if ( ! self::tabelle_da( $a[0] ) ) continue;
                $n = $wpdb->query( $wpdb->prepare(
                    "UPDATE " . self::t( $a[0] ) . " SET {$a[1]} WHERE {$a[2]}", $k['user'] ) );
                if ( $n ) $n_anonym += (int) $n;
            }
            if ( $n_anonym ) {
                $weg += $n_anonym;
                $meldungen[] = 'Trainingspläne, Vorlagen und hochgeladene Dateien bleiben für '
                             . 'die Mannschaften erhalten; der Personenbezug wurde entfernt. '
                             . 'Ein Plan, den eine Mannschaft noch schwimmt, soll nicht '
                             . 'verschwinden, weil jemand den Verein verlässt.';
            }
            $meldungen[] = 'Der Zugang zu SwimPlan wurde entfernt.';
        }

        return [
            'items_removed'  => $weg > 0,
            'items_retained' => false,
            'messages'       => $meldungen,
            'done'           => true,
        ];
    }

    // ── Vorschlag für die Datenschutzerklärung ───────────────────────────

    public static function erklaerung_vorschlagen() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) return;

        $t  = '<h2>SwimPlan (Trainingsplanung)</h2>';
        $t .= '<p>Für die Trainingsplanung werden gespeichert: Vorname, Nachname, Jahrgang und '
            . 'DSV-ID der Schwimmerinnen und Schwimmer, ihre Zugehörigkeit zu Mannschaften, '
            . 'die für sie freigegebenen Trainingspläne sowie erfasste Zeiten und '
            . 'Bestleistungen.</p>';
        $t .= '<p>Schwimmerinnen und Schwimmer haben keinen eigenen Zugang zu diesem Plugin; '
            . 'erfasst werden ihre Daten von den Trainerinnen und Trainern. Für diese werden '
            . 'zusätzlich das WordPress-Konto, die Rolle und ein Protokoll der Vorgänge '
            . 'gespeichert (Konto, Vorgang, Zeitpunkt, gehashte IP-Adresse).</p>';
        $t .= '<p>Es werden keine Daten an Dritte übermittelt; hochgeladene Trainingspläne '
            . 'liegen ausserhalb des Webverzeichnisses.</p>';
        $t .= '<p><strong>Aufbewahrung:</strong> Es bestehen keine gesetzlichen '
            . 'Aufbewahrungspflichten. Auf Wunsch werden Schwimmerdaten, Zeiten und '
            . 'Freigaben gelöscht. Trainingspläne bleiben ohne Personenbezug erhalten, '
            . 'solange eine Mannschaft sie nutzt.</p>';
        $t .= '<p>Auskunft und Löschung laufen über die Werkzeuge von WordPress unter '
            . '„Personenbezogene Daten exportieren" bzw. „löschen".</p>';

        wp_add_privacy_policy_content( self::NAME, wp_kses_post( $t ) );
    }
}
