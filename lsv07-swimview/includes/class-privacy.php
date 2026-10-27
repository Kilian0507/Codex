<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auskunft (Art. 15) und Löschung (Art. 17) über die Bordmittel von
 * WordPress — für SwimView.
 *
 * SwimView ist ein Schaufenster: Fast alles, was es anzeigt, gehört dem
 * internen Bereich und wird von dort nur GELESEN — Schwimmer,
 * Mannschaften, Anwesenheiten, Bestzeiten, Trainingspläne. Über diese
 * Daten gibt der interne Bereich Auskunft, und dort werden sie auch
 * gelöscht. Hier steht nur, was SwimView **selbst** anlegt: wer wann
 * angemeldet war, welchen Zugangscode ein Schwimmer hat, welche Nachricht
 * er bekommen und gelesen hat.
 *
 * Darum ist diese Liste kurz, und das ist Absicht und kein Versäumnis. Ein
 * zweiter Exporter, der dieselben Schwimmerdaten noch einmal ausgibt,
 * würde die Auskunft doppelt lang und nicht vollständiger machen — und
 * eine zweite Löschung dieser Daten würde dem internen Bereich den Boden
 * wegziehen.
 *
 * **Schwimmer haben hier kein WordPress-Konto.** Sie melden sich mit
 * DSV-ID und Geburtsdatum an. Gefunden werden sie deshalb über die
 * E-Mail-Adresse in ihrem Eintrag im internen Bereich. Steht dort keine,
 * ist über diese Adresse auch nichts zuzuordnen — das ist keine Lücke der
 * Auskunft, sondern die Folge davon, dass die Adresse gar nicht erhoben
 * wurde.
 */
class LSV07S_Privacy {

    const SCHLUESSEL = 'lsv07-swimview';
    const NAME       = 'LSV07 SwimView';
    const PRO_SEITE  = 200;

    /** Tabellen von SwimView ohne Personenbezug. */
    const OHNE_PERSON = [
        'lsv07s_freigabe',       // welcher Bereich für welche Mannschaft offen ist
        'lsv07s_plan_freigabe',  // welcher Trainingsplan für welche Mannschaft
    ];

    /** Fremde Tabellen, über die ein anderes Plugin Auskunft gibt. */
    const FREMD = [
        'mv_swimmers', 'lsv07_gruppen', 'lsv07i_config', 'lsv07i_bestzeiten',
        'lsv07i_mb_anwesenheit', 'lsv07i_mb_eintrag', 'lsv07i_mb_tag',
        'lsv07i_refl_antwort', 'lsv07i_refl_bogen', 'lsv07i_saisons',
        'lsv07i_swimmer_gruppen', 'lsv07i_trainer', 'lsv07i_trainer_mannschaft',
        'lsv07i_trainingsplan', 'lsv07i_trainingsplan_session',
        'lsv07i_wettkampf', 'lsv07i_wettkampf_dokument', 'lsv07i_wettkampf_link',
        'lsv07i_wettkampf_mannschaft',
    ];

    /** Spalten, die nie in eine Exportdatei gehören. */
    const GEHEIM = [ 'geheim_hash', 'geheim_vor', 'code_hash', 'ip_hash', 'selektor', 'schluessel' ];

    const WOERTER = [
        'id' => 'Nummer', 'betreff' => 'Betreff', 'text' => 'Text',
        'von_name' => 'Von', 'erstellt_am' => 'Erhalten am',
        'gelesen_am' => 'Gelesen am', 'dateiname' => 'Dateiname',
        'mime_type' => 'Typ', 'groesse' => 'Grösse in Bytes',
        'hochgeladen_am' => 'Hochgeladen am', 'gesetzt_am' => 'Gesetzt am',
        'letzte_nutzung' => 'Zuletzt benutzt', 'gesehen_am' => 'Zuletzt gesehen',
        'gewechselt_am' => 'Erneuert am', 'was' => 'Vorgang',
        'hinweis' => 'Hinweis', 'am' => 'Zeitpunkt', 'art' => 'Art',
        'versuche' => 'Fehlversuche', 'gesperrt_bis' => 'Gesperrt bis',
        'letzter_am' => 'Letzter Versuch', 'mannschaft_id' => 'Mannschaft (Nummer)',
        'nachricht_id' => 'Nachricht (Nummer)',
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

    /**
     * Die Kennungen hinter einer Adresse: der Schwimmer und das Konto.
     *
     * Der Schwimmer kommt aus dem internen Bereich — SwimView führt keine
     * eigene Schwimmerliste, und eine zweite wäre die erste Stelle, an der
     * beide auseinanderlaufen.
     */
    public static function kennungen( $email ) {
        global $wpdb;
        $email = trim( (string) $email );
        $k = [ 'schwimmer' => 0, 'user' => 0, 'dsv' => '' ];
        if ( $email === '' ) return $k;

        $user = get_user_by( 'email', $email );
        $k['user'] = $user ? (int) $user->ID : 0;

        if ( self::tabelle_da( 'mv_swimmers' ) ) {
            $zeile = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, dsv_id FROM " . self::t( 'mv_swimmers' ) . "
                  WHERE email = %s ORDER BY id ASC LIMIT 1", $email ), ARRAY_A );
            if ( $zeile ) {
                $k['schwimmer'] = (int) $zeile['id'];
                $k['dsv']       = (string) ( $zeile['dsv_id'] ?? '' );
            }
        }
        return $k;
    }

    private static function jemand( $k ) {
        return $k['schwimmer'] > 0 || $k['user'] > 0;
    }

    // ── Die Quellen ──────────────────────────────────────────────────────

    /** Tabelle, Überschrift, Bedingungsteile [ Spalte, Art ]. */
    private static function quellen() {
        return [
            [ 'lsv07s_nachricht',       'Erhaltene Nachrichten',
              [ [ 'schwimmer_id', 'schwimmer' ], [ 'von_user', 'user' ] ] ],
            [ 'lsv07s_nachricht_datei', 'Anhänge erhaltener Nachrichten',
              [ [ 'nachricht_id', 'nachricht' ] ] ],
            [ 'lsv07s_gelesen',         'Gelesene Nachrichten',
              [ [ 'schwimmer_id', 'schwimmer' ] ] ],
            [ 'lsv07s_sitzung',         'Angemeldete Geräte',
              [ [ 'schwimmer_id', 'schwimmer' ] ] ],
            [ 'lsv07s_code',            'Zugangscode',
              [ [ 'schwimmer_id', 'schwimmer' ], [ 'gesetzt_von', 'user' ] ] ],
            [ 'lsv07s_throttle',        'Fehlversuche bei der Anmeldung',
              [ [ 'schluessel', 'dsv_hash' ] ] ],
            [ 'lsv07s_spur',            'Protokoll',
              [ [ 'schwimmer_id', 'schwimmer' ], [ 'user_id', 'user' ] ] ],
        ];
    }

    public static function angebunden() {
        return array_values( array_unique( array_column( self::quellen(), 0 ) ) );
    }

    /** Der Sperrschlüssel dieser Person — derselbe, den die Anmeldung rechnet. */
    private static function dsv_hash( $k ) {
        if ( $k['dsv'] === '' ) return '';
        if ( ! class_exists( 'LSV07S_Login' ) || ! method_exists( 'LSV07S_Login', 'konto_hash' ) ) {
            return '';
        }
        return (string) LSV07S_Login::konto_hash( LSV07S_Login::dsv_norm( $k['dsv'] ) );
    }

    private static function bedingung( $wo, $k ) {
        $teile = [];
        foreach ( $wo as $t ) {
            list( $spalte, $art ) = $t;
            if ( $art === 'nachricht' ) {
                if ( ! $k['schwimmer'] ) continue;
                $teile[] = sprintf( '%s IN ( SELECT id FROM %s WHERE schwimmer_id = %d )',
                    $spalte, self::t( 'lsv07s_nachricht' ), $k['schwimmer'] );
            } elseif ( $art === 'dsv_hash' ) {
                $h = self::dsv_hash( $k );
                if ( $h === '' ) continue;
                $teile[] = sprintf( "( art = 'konto' AND %s = '%s' )", $spalte, esc_sql( $h ) );
            } else {
                if ( empty( $k[ $art ] ) ) continue;
                $teile[] = sprintf( '%s = %d', $spalte, (int) $k[ $art ] );
            }
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

    /**
     * Hier gibt es nichts aufzubewahren.
     *
     * SwimView führt keine Bücher: Anmeldungen, Zugangscodes und
     * Lesemarken haben keinen steuerlichen Wert und werden gelöscht. Nur
     * eine Nachricht AN eine Mannschaft bleibt — sie gehört allen darin,
     * nicht einem; gelöscht wird dort nur die Lesemarke der Person.
     */
    public static function loeschen( $email, $seite = 1 ) {
        $leer = [ 'items_removed' => false, 'items_retained' => false,
                  'messages' => [], 'done' => true ];
        $k = self::kennungen( $email );
        if ( ! self::jemand( $k ) ) return $leer;
        if ( (int) $seite > 1 ) return $leer;

        global $wpdb;
        $weg = 0; $meldungen = [];

        if ( $k['schwimmer'] ) {
            $weg += self::schwimmer_vergessen( $k['schwimmer'], $k['dsv'] );
            $meldungen[] = 'Persönliche Nachrichten wurden gelöscht, ihre Anhänge auch vom '
                         . 'Dateisystem. Nachrichten an die ganze Mannschaft bleiben — sie '
                         . 'gehören allen darin.';
            $meldungen[] = 'Angemeldete Geräte, Zugangscode, Lesemarken, Protokoll und die '
                         . 'gezählten Fehlversuche wurden gelöscht. Damit endet auch jede '
                         . 'laufende Anmeldung.';
        }

        /* Trainerseite: Was eine Person freigegeben oder geschrieben hat,
           bleibt für die Mannschaft stehen — ohne ihren Namen. */
        if ( $k['user'] ) {
            $anonym = [
                [ 'lsv07s_nachricht',    "von_user = 0, von_name = 'Gelöschtes Konto'", 'von_user = %d' ],
                [ 'lsv07s_freigabe',     'geaendert_von = 0',    'geaendert_von = %d' ],
                [ 'lsv07s_plan_freigabe', 'freigegeben_von = 0', 'freigegeben_von = %d' ],
                [ 'lsv07s_code',         'gesetzt_von = 0',      'gesetzt_von = %d' ],
                [ 'lsv07s_spur',         'user_id = 0',          'user_id = %d' ],
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
                $meldungen[] = 'Nachrichten und Freigaben bleiben für die Mannschaft erhalten; '
                             . 'der Personenbezug wurde entfernt.';
            }
        }

        $meldungen[] = 'Schwimmerdaten, Anwesenheiten, Bestzeiten und Trainingspläne gehören '
                     . 'dem internen Bereich; dort werden sie gelöscht, nicht hier.';

        return [
            'items_removed'  => $weg > 0,
            'items_retained' => false,
            'messages'       => $meldungen,
            'done'           => true,
        ];
    }

    /**
     * Alles löschen, was SwimView über einen Schwimmer führt.
     *
     * Der EINZIGE Löschweg. Es gibt zwei Anlässe — der Knopf „Daten
     * löschen" im Trainerportal und die Löschung nach Art. 17 über die
     * Werkzeuge von WordPress —, aber nur eine Stelle, die es tut. Zwei
     * Listen desselben Vorgangs laufen auseinander, und der Schaden fiele
     * niemandem auf: Es bliebe eine Zeile stehen, die keiner mehr ansieht.
     *
     * Nachrichten an die ganze Mannschaft tragen keine Schwimmernummer und
     * bleiben; gelöscht wird dort nur die Lesemarke dieser Person.
     *
     * @param int    $schwimmer_id
     * @param string $dsv_id  Für die Sperrzähler. Leer = aus dem internen
     *                        Bereich nachsehen.
     * @return int Wie viele Zeilen betroffen waren.
     */
    public static function schwimmer_vergessen( $schwimmer_id, $dsv_id = '' ) {
        global $wpdb;
        $schwimmer_id = (int) $schwimmer_id;
        if ( $schwimmer_id <= 0 ) return 0;
        $weg = 0;

        if ( self::tabelle_da( 'lsv07s_nachricht' ) ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM " . self::t( 'lsv07s_nachricht' ) . "
                  WHERE schwimmer_id = %d", $schwimmer_id ) );
            foreach ( $ids as $id ) {
                self::anhaenge_weg( (int) $id );
                $wpdb->delete( self::t( 'lsv07s_gelesen' ),
                    [ 'nachricht_id' => (int) $id ], [ '%d' ] );
                $wpdb->delete( self::t( 'lsv07s_nachricht' ), [ 'id' => (int) $id ], [ '%d' ] );
                $weg++;
            }
        }

        foreach ( [ 'lsv07s_gelesen', 'lsv07s_sitzung', 'lsv07s_code', 'lsv07s_spur' ] as $tabelle ) {
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM " . self::t( $tabelle ) . " WHERE schwimmer_id = %d",
                $schwimmer_id ) );
            if ( $n ) $weg += (int) $n;
        }

        /* Die Sperrzeilen zur DSV-ID: Fehlversuche sind Verhaltensdaten. */
        if ( $dsv_id === '' && class_exists( 'LSV07S_Intern' ) ) {
            $s = LSV07S_Intern::schwimmer( $schwimmer_id );
            $dsv_id = (string) ( $s['dsv_id'] ?? '' );
        }
        $h = self::dsv_hash( [ 'dsv' => $dsv_id ] );
        if ( $h !== '' && self::tabelle_da( 'lsv07s_throttle' ) ) {
            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM " . self::t( 'lsv07s_throttle' ) . "
                  WHERE art = 'konto' AND schluessel = %s", $h ) );
            if ( $n ) $weg += (int) $n;
        }
        return $weg;
    }

    /** Die Anhänge einer Nachricht samt Dateien. */
    private static function anhaenge_weg( $nachricht_id ) {
        global $wpdb;
        if ( ! self::tabelle_da( 'lsv07s_nachricht_datei' ) ) return;
        if ( class_exists( 'LSV07S_Dateien' ) && method_exists( 'LSV07S_Dateien', 'loeschen' ) ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM " . self::t( 'lsv07s_nachricht_datei' ) . "
                  WHERE nachricht_id = %d", (int) $nachricht_id ) );
            foreach ( $ids as $id ) LSV07S_Dateien::loeschen( (int) $id );
            return;
        }
        $wpdb->delete( self::t( 'lsv07s_nachricht_datei' ),
            [ 'nachricht_id' => (int) $nachricht_id ], [ '%d' ] );
    }

    // ── Vorschlag für die Datenschutzerklärung ───────────────────────────

    public static function erklaerung_vorschlagen() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) return;

        $t  = '<h2>SwimView (Ansicht für Schwimmerinnen und Schwimmer)</h2>';
        $t .= '<p>SwimView zeigt Daten aus dem internen Bereich an: Trainingszeiten, '
            . 'Anwesenheiten, Bestzeiten, Trainingspläne und Nachrichten der '
            . 'Trainerinnen und Trainer. Diese Daten werden dort gespeichert, nicht hier.</p>';
        $t .= '<p>SwimView selbst speichert: die Anmeldung am Gerät (ein Cookie mit einem '
            . 'zufälligen Wert, dessen Gegenstück nur gehasht in der Datenbank liegt), den '
            . 'gehashten Zugangscode, welche Nachricht wann gelesen wurde sowie ein Protokoll '
            . 'der Anmeldungen. Fehlversuche werden gezählt, um Erraten zu erschweren; '
            . 'IP-Adresse und DSV-ID stehen dabei nur gehasht in der Datenbank.</p>';
        $t .= '<p>Die Anmeldung erfolgt mit DSV-ID und Geburtsdatum, nicht mit einem '
            . 'WordPress-Konto. Es werden keine Daten an Dritte übermittelt.</p>';
        $t .= '<p>Auskunft und Löschung laufen über die Werkzeuge von WordPress unter '
            . '„Personenbezogene Daten exportieren" bzw. „löschen".</p>';

        wp_add_privacy_policy_content( self::NAME, wp_kses_post( $t ) );
    }
}
