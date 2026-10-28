<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Alle Endpunkte, ein Muster.
 *
 * Im internen Bereich gibt es drei Arten, einen Endpunkt anzumelden, 31
 * Klassen mit eigenen Toren und 257 Aktionen. Hier steht eine Liste:
 * Name, Methode, verlangtes Recht. Das Tor ist immer dasselbe und steht
 * nicht in der Methode — damit kein Endpunkt je ohne Tor dastehen kann,
 * weil jemand eine Zeile vergessen hat.
 *
 * Alle Aktionen heissen lsv07s_*. Der interne Bereich benutzt lsv07i_*;
 * die beiden können sich also nicht in die Quere kommen, auch wenn beide
 * Plugins laufen.
 *
 * Nur angemeldete Aufrufe: `wp_ajax_nopriv_` wird nirgends angemeldet.
 */
class LSV07S_Ajax {

    /**
     * Endpunkt => [ Methode, verlangtes Recht ].
     *
     * Ein leeres Recht heisst „angemeldet, mit Zugang, nicht gesperrt" —
     * mehr verlangt zum Beispiel die eigene Aufgabenliste nicht.
     */
    private static function karte() {
        return [
            'start'       => [ 'start',       '' ],
            'suche'       => [ 'suche',       '' ],
            'mannschaften'=> [ 'mannschaften', '' ],
            'mannschaft'  => [ 'mannschaft',  '' ],
            'anw_tag'     => [ 'anw_tag',     '' ],
            'anw_setzen'  => [ 'anw_setzen',  '' ],
            'anw_ausfall' => [ 'anw_ausfall', '' ],
            'anw_quote'   => [ 'anw_quote',   '' ],
            'protokoll'   => [ 'protokoll',   LSV07S_Rechte::ADMIN_CONFIG_READ ],

            /* Stammdaten. Das Recht steht nicht hier, sondern in der
               Funktion: Es hängt an der Sparte und daran, ob angelegt
               oder geändert wird. Das Tor prüft Anmeldung, Schlüssel und
               Zugang; die Funktion das Recht und die Mannschaft. */
            'mann_speichern'     => [ 'mann_speichern',     '' ],
            'mann_loeschen'      => [ 'mann_loeschen',      '' ],
            'sportler'           => [ 'sportler',           '' ],
            'sportler_speichern' => [ 'sportler_speichern', '' ],
            'sportler_stilllegen'=> [ 'sportler_stilllegen','' ],
            'sportler_loeschen'  => [ 'sportler_loeschen',  '' ],
            'sportler_umziehen'  => [ 'sportler_umziehen',  '' ],
            'trainer_liste'      => [ 'trainer_liste',      '' ],
            'trainer_speichern'  => [ 'trainer_speichern',  '' ],
            'trainer_loeschen'   => [ 'trainer_loeschen',   '' ],
            'slot_speichern'     => [ 'slot_speichern',     '' ],
            'slot_loeschen'      => [ 'slot_loeschen',      '' ],
            'saisons'            => [ 'saisons',            '' ],
            'saison_speichern'   => [ 'saison_speichern',   'admin.saison.manage' ],
            'saison_aktiv'       => [ 'saison_aktiv',       'admin.saison.manage' ],
            'saison_loeschen'    => [ 'saison_loeschen',    'admin.saison.manage' ],

            /* Mannschaftsbuch. Auch hier steht das Recht in der Funktion:
               Es hängt an der Mannschaft, nicht am Endpunkt. */
            'buch_tag'           => [ 'buch_tag',           '' ],
            'buch_doku'          => [ 'buch_doku',          '' ],
            'buch_eintrag'       => [ 'buch_eintrag',       '' ],
            'buch_erledigt'      => [ 'buch_erledigt',      '' ],
            'buch_eintrag_weg'   => [ 'buch_eintrag_weg',   '' ],
            'buch_aufgaben'      => [ 'buch_aufgaben',      '' ],
            'bahnen_speichern'   => [ 'bahnen_speichern',   '' ],
            'bahnen_uebernehmen' => [ 'bahnen_uebernehmen', '' ],
            'springer_eintragen' => [ 'springer_eintragen', '' ],
            'springer_austragen' => [ 'springer_austragen', '' ],
            'buch_trainer'       => [ 'buch_trainer',       '' ],

            /* Atteste und Akte. Beide hängen an eigenen Rechten, weil sie
               Gesundheitsdaten (Art. 9 DSGVO) bzw. eine Zusammenstellung
               über eine Person sind. */
            'attest_liste'       => [ 'attest_liste',       '' ],
            'attest_datum'       => [ 'attest_datum',       '' ],
            'attest_datei'       => [ 'attest_datei',       '' ],
            'attest_datei_zeigen'=> [ 'attest_datei_zeigen','' ],
            'attest_datei_weg'   => [ 'attest_datei_weg',   '' ],
            'attest_erinnern'    => [ 'attest_erinnern',    '' ],
            'attest_faellig'     => [ 'attest_faellig',     '' ],
            'attest_trainer'     => [ 'attest_trainer',     '' ],
            'akte'               => [ 'akte',               LSV07S_Rechte::SCHWIMMEN_AKTE_READ ],

            /* Wettkampf, Meldung, Bestzeiten. Auch hier steht das Recht in
               der Funktion: Lesen, Anlegen, Ändern, Freigeben und
               Anwesenheit-Erfassen sind fünf verschiedene Rechte, und die
               Mannschaftsgrenze gilt dazu. */
            'wk_liste'           => [ 'wk_liste',           '' ],
            'wk'                 => [ 'wk',                 '' ],
            'wk_speichern'       => [ 'wk_speichern',       '' ],
            'wk_loeschen'        => [ 'wk_loeschen',        '' ],
            'wk_freigeben'       => [ 'wk_freigeben',       '' ],
            'wk_dokument'        => [ 'wk_dokument',        '' ],
            'wk_dokument_zeigen' => [ 'wk_dokument_zeigen', '' ],
            'wk_dokument_weg'    => [ 'wk_dokument_weg',    '' ],
            'wk_anw_tag'         => [ 'wk_anw_tag',         '' ],
            'wk_anw_setzen'      => [ 'wk_anw_setzen',      '' ],
            'wk_anw_abschnitte'  => [ 'wk_anw_abschnitte',  '' ],
            'wk_statistik'       => [ 'wk_statistik',       '' ],
            'wk_offene_tage'     => [ 'wk_offene_tage',     '' ],

            'meld_liste'         => [ 'meld_liste',         '' ],
            'meld'               => [ 'meld',               '' ],
            'meld_kopf'          => [ 'meld_kopf',          '' ],
            'meld_starts'        => [ 'meld_starts',        '' ],
            'meld_loeschen'      => [ 'meld_loeschen',      '' ],
            'meld_export'        => [ 'meld_export',        '' ],

            'bz_tafel'           => [ 'bz_tafel',           '' ],
            'bz_vorschau'        => [ 'bz_vorschau',        '' ],
            'bz_uebernehmen'     => [ 'bz_uebernehmen',     '' ],
            'bz_loeschen'        => [ 'bz_loeschen',        '' ],
            'bz_alle_loeschen'   => [ 'bz_alle_loeschen',   '' ],
            'bz_export'          => [ 'bz_export',          '' ],
        ];
    }

    public static function init() {
        foreach ( self::karte() as $aktion => $def ) {
            add_action( 'wp_ajax_lsv07s_' . $aktion, function () use ( $def ) {
                LSV07S_Zugang::tor( $def[1] );
                call_user_func( [ __CLASS__, $def[0] ] );
            } );
        }
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private static function sparte() {
        $s = sanitize_key( $_POST['sparte'] ?? $_GET['sparte'] ?? LSV07S_Sparte::SCHWIMMEN );
        if ( ! LSV07S_Sparte::gibt( $s ) ) {
            LSV07S_Zugang::nein( 'Diese Sparte gibt es nicht.', 400 );
        }
        if ( ! LSV07S_Sparte::da( $s ) ) {
            LSV07S_Zugang::nein( 'Diese Sparte ist in dieser Anlage nicht eingerichtet.', 400 );
        }
        return $s;
    }

    private static function zahl( $name ) {
        return absint( $_POST[ $name ] ?? $_GET[ $name ] ?? 0 );
    }

    private static function text( $name, $laenge = 255 ) {
        $w = (string) ( $_POST[ $name ] ?? $_GET[ $name ] ?? '' );
        return substr( sanitize_text_field( $w ), 0, $laenge );
    }

    /**
     * Ein Feld aus dem Formular — als echtes Feld oder als JSON.
     *
     * Eine Startliste mit 80 Zeilen schickt die Oberfläche als JSON; ein
     * einfaches Kästchenfeld kommt als Feld. Beides hier an einer
     * Stelle, damit nicht jede Funktion ihre eigene Lesart hat: Der
     * interne Bereich liest an sieben Stellen „erst JSON, dann Legacy",
     * jedes Mal ein bisschen anders.
     *
     * Was dabei herauskommt, ist ein Feld aus Feldern oder Zeichenketten
     * — geprüft wird es dort, wo es hingehört.
     */
    private static function feld( $name ) {
        $roh = $_POST[ $name ] ?? $_GET[ $name ] ?? null;
        if ( is_array( $roh ) ) return $roh;
        if ( ! is_string( $roh ) || trim( $roh ) === '' ) return [];
        if ( strlen( $roh ) > 500000 ) return [];
        $d = json_decode( (string) wp_unslash( $roh ), true );
        return is_array( $d ) ? $d : [];
    }

    // ── Die Endpunkte ────────────────────────────────────────────────────

    /** Was die Oberfläche beim Öffnen braucht: Aufgaben und die Karte. */
    public static function start() {
        LSV07S_Zugang::ja( [
            'aufgaben' => LSV07S_Aufgaben::alle(),
            'karte'    => LSV07S_Rechte::karte(),
            'heute'    => LSV07S_Datum::heute(),
            'saison'   => LSV07S_Datum::saison_aktiv(),
        ] );
    }

    public static function suche() {
        LSV07S_Zugang::ja( LSV07S_Suche::suche( self::text( 'wort', 80 ) ) );
    }

    public static function mannschaften() {
        $sparte = self::sparte();
        LSV07S_Zugang::ja( [
            'sparte'       => $sparte,
            'wort_einer'   => LSV07S_Sparte::wort( $sparte, 'einer' ),
            'wort_gruppe'  => LSV07S_Sparte::wort( $sparte, 'gruppe_name' ),
            'mannschaften' => LSV07S_Mannschaft::erlaubte( $sparte ),
        ] );
    }

    public static function mannschaft() {
        $sparte = self::sparte();
        $id     = self::zahl( 'mannschaft_id' );
        $mann   = LSV07S_Mannschaft::holen( $sparte, $id );
        if ( ! $mann ) LSV07S_Zugang::nein( 'Diese Mannschaft gibt es nicht.', 404 );

        /* Sehen darf, wer sie in seiner Liste hat — die Liste entscheidet,
           nicht die Nummer in der Adresse. Sonst wäre jede Mannschaft über
           eine geratene Nummer zu öffnen. */
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( $id, $erlaubt, true ) ) {
            LSV07S_Zugang::nein( 'Für diese Mannschaft fehlt Ihnen die Berechtigung.', 403 );
        }

        LSV07S_Zugang::ja( [
            'mannschaft'   => $mann,
            'sportler'     => LSV07S_Mannschaft::sportler( $sparte, $id ),
            'slots'        => LSV07S_Mannschaft::slots( $sparte, $id ),
            'darf_aendern' => LSV07S_Rechte::darf_mannschaft( $sparte, $id ),
        ] );
    }

    public static function anw_tag() {
        $sparte = self::sparte();
        $id     = self::zahl( 'mannschaft_id' );
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Sparte::recht( $sparte, 'anw.read' ),
                                            LSV07S_Rechte::MANNSCHAFTSBUCH_READ ] ) ) {
            LSV07S_Zugang::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( $id, $erlaubt, true ) ) {
            LSV07S_Zugang::nein( 'Für diese Mannschaft fehlt Ihnen die Berechtigung.', 403 );
        }
        $tag = LSV07S_Anwesenheit::tag( $sparte, $id, self::text( 'datum', 10 ) );
        if ( ! $tag ) LSV07S_Zugang::nein( 'Diesen Trainingstag gibt es nicht.', 404 );
        $tag['zustaende'] = LSV07S_Anwesenheit::ZUSTAENDE;
        LSV07S_Zugang::ja( $tag );
    }

    public static function anw_setzen() {
        $sparte = self::sparte();
        $erg = LSV07S_Anwesenheit::setzen(
            $sparte,
            self::zahl( 'mannschaft_id' ),
            self::text( 'datum', 10 ),
            self::zahl( 'person_id' ),
            sanitize_key( $_POST['status'] ?? '' ),
            self::zahl( 'verspaetung' ),
            self::text( 'verspaetung_grund', 200 )
        );
        if ( $erg !== true ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( [ 'message' => 'Gespeichert.' ] );
    }

    public static function anw_ausfall() {
        $sparte = self::sparte();
        $erg = LSV07S_Anwesenheit::ausfall(
            $sparte,
            self::zahl( 'mannschaft_id' ),
            self::text( 'von', 10 ),
            self::text( 'bis', 10 ),
            self::text( 'grund', 255 ),
            ! empty( $_POST['an'] )
        );
        if ( $erg !== true ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( [ 'message' => ! empty( $_POST['an'] )
            ? 'Das Training ist als ausgefallen vermerkt.'
            : 'Die Absage ist zurückgenommen.' ] );
    }

    public static function anw_quote() {
        $sparte = self::sparte();
        $id     = self::zahl( 'mannschaft_id' );
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Sparte::recht( $sparte, 'anw.read' ),
                                            LSV07S_Rechte::MANNSCHAFTSBUCH_READ ] ) ) {
            LSV07S_Zugang::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( $id, $erlaubt, true ) ) {
            LSV07S_Zugang::nein( 'Für diese Mannschaft fehlt Ihnen die Berechtigung.', 403 );
        }
        $von = self::text( 'von', 10 );
        $bis = self::text( 'bis', 10 );
        if ( $von === '' || $bis === '' ) {
            $s   = LSV07S_Datum::saison_aktiv();
            $von = $s ? $s['start_datum'] : date( 'Y-01-01', strtotime( LSV07S_Datum::heute() ) );
            $bis = LSV07S_Datum::heute();
        }
        LSV07S_Zugang::ja( [
            'von'   => $von,
            'bis'   => $bis,
            'zeilen'=> LSV07S_Anwesenheit::quote( $sparte, $id, $von, $bis ),
        ] );
    }

    public static function protokoll() {
        LSV07S_Zugang::ja( [
            'zeilen' => LSV07S_Log::letzte( self::zahl( 'anzahl' ) ?: 100, self::zahl( 'offset' ) ),
        ] );
    }

    // ── Stammdaten ───────────────────────────────────────────────────────

    /**
     * Eine Antwort für alles, was die Stammdatenklasse liefert.
     *
     * Jede dieser Funktionen gibt entweder eine Nummer, `true`, ein Feld —
     * oder den Satz, der dem Nutzer gesagt wird. Hier steht die eine
     * Stelle, die daraus eine Antwort macht; sonst entstünde pro Endpunkt
     * eine eigene Auslegung von „ist das ein Fehler".
     */
    private static function erg( $erg, $erfolg = 'Gespeichert.' ) {
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        $daten = [ 'message' => $erfolg ];
        if ( is_int( $erg ) ) $daten['id'] = $erg;
        if ( is_array( $erg ) ) $daten = array_merge( $daten, $erg );
        LSV07S_Zugang::ja( $daten );
    }

    public static function mann_speichern() {
        $sparte = self::sparte();
        self::erg( LSV07S_Stamm::mannschaft_speichern(
            $sparte, self::zahl( 'id' ), $_POST['name'] ?? '', self::zahl( 'sortierung' ) ) );
    }

    public static function mann_loeschen() {
        $sparte = self::sparte();
        self::erg( LSV07S_Stamm::mannschaft_loeschen( $sparte, self::zahl( 'id' ) ),
            'Gelöscht.' );
    }

    /** Eine Person mit allem, was zum Bearbeiten gebraucht wird. */
    public static function sportler() {
        global $wpdb;
        $sparte = self::sparte();
        $id     = self::zahl( 'id' );
        $t      = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        $zeile  = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE id = %d LIMIT 1", $id ), ARRAY_A );
        if ( ! $zeile ) LSV07S_Zugang::nein( 'Diese Person gibt es nicht.', 404 );
        if ( ! LSV07S_Stamm::sportler_in_reichweite( $sparte, $id ) ) {
            LSV07S_Zugang::nein( 'Diese Person gehört nicht zu Ihrem Bereich.', 403 );
        }
        $gb = LSV07S_Sparte::feld( $sparte, 'geburt' );
        LSV07S_Zugang::ja( [
            'id'           => $id,
            'vorname'      => $zeile[ LSV07S_Sparte::feld( $sparte, 'vorname' ) ] ?? '',
            'nachname'     => $zeile[ LSV07S_Sparte::feld( $sparte, 'nachname' ) ] ?? '',
            'geburtsdatum' => $zeile[ $gb ] ?? null,
            'jahrgang'     => LSV07S_Person::jahrgang( $zeile[ $gb ] ?? null ),
            'email'        => $zeile['email'] ?? '',
            'notizen'      => $zeile['notes'] ?? '',
            'dsv_id'       => $zeile['dsv_id'] ?? '',
            'attest_bis'   => $zeile['attest_expires'] ?? null,
            'aktiv'        => (int) ( $zeile[ LSV07S_Sparte::feld( $sparte, 'aktiv' ) ] ?? 0 ) === 1,
            'mannschaften' => LSV07S_Stamm::zuordnung( $sparte, $id ),
            'kontakte'     => LSV07S_Sparte::feld( $sparte, 'hat_attest' )
                                ? LSV07S_Stamm::kontakte( $id ) : [],
            'spuren'       => LSV07S_Stamm::spuren( $sparte, $id ),
        ] );
    }

    public static function sportler_speichern() {
        $sparte = self::sparte();
        /* Felder einzeln entgegennehmen und nicht $_POST durchreichen: So
           kann niemand eine Spalte setzen, die hier nicht vorgesehen ist. */
        $daten = [
            'vorname'      => $_POST['vorname'] ?? '',
            'nachname'     => $_POST['nachname'] ?? '',
            'geburtsdatum' => $_POST['geburtsdatum'] ?? '',
            'email'        => $_POST['email'] ?? '',
            'notizen'      => $_POST['notizen'] ?? '',
            'dsv_id'       => $_POST['dsv_id'] ?? '',
            'attest_bis'   => $_POST['attest_bis'] ?? '',
            'mannschaften' => (array) ( $_POST['mannschaften'] ?? [] ),
        ];
        if ( isset( $_POST['aktiv'] ) )    $daten['aktiv']    = ! empty( $_POST['aktiv'] );
        if ( isset( $_POST['kontakte'] ) ) $daten['kontakte'] = (array) $_POST['kontakte'];
        self::erg( LSV07S_Stamm::sportler_speichern( $sparte, self::zahl( 'id' ), $daten ) );
    }

    public static function sportler_stilllegen() {
        $sparte = self::sparte();
        $aktiv  = ! empty( $_POST['aktiv'] );
        self::erg( LSV07S_Stamm::sportler_stilllegen( $sparte, self::zahl( 'id' ), $aktiv ),
            $aktiv ? 'Wieder im Kader.' : 'Stillgelegt.' );
    }

    public static function sportler_loeschen() {
        $sparte = self::sparte();
        self::erg( LSV07S_Stamm::sportler_loeschen( $sparte, self::zahl( 'id' ) ), 'Gelöscht.' );
    }

    public static function sportler_umziehen() {
        $sparte = self::sparte();
        self::erg( LSV07S_Stamm::sportler_umziehen( $sparte, self::zahl( 'id' ),
            self::zahl( 'von' ), self::zahl( 'nach' ) ), 'Umgezogen.' );
    }

    public static function trainer_liste() {
        $sparte = self::sparte();
        if ( ! LSV07S_Rechte::darf( LSV07S_Sparte::recht( $sparte, 'trainer.read' ) ) ) {
            LSV07S_Zugang::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
        LSV07S_Zugang::ja( [
            'trainer'      => LSV07S_Stamm::trainer_liste( $sparte ),
            'mannschaften' => LSV07S_Mannschaft::erlaubte( $sparte ),
            'darf_aendern' => LSV07S_Rechte::darf( LSV07S_Sparte::recht_gruppe( $sparte, 'update' ) ),
        ] );
    }

    public static function trainer_speichern() {
        $sparte = self::sparte();
        self::erg( LSV07S_Stamm::trainer_speichern( $sparte, self::zahl( 'id' ), [
            'name'         => $_POST['name'] ?? '',
            'email'        => $_POST['email'] ?? '',
            'wp_user_id'   => self::zahl( 'wp_user_id' ),
            'aktiv'        => isset( $_POST['aktiv'] ) ? ! empty( $_POST['aktiv'] ) : null,
            'mannschaften' => (array) ( $_POST['mannschaften'] ?? [] ),
        ] ) );
    }

    public static function trainer_loeschen() {
        $sparte = self::sparte();
        self::erg( LSV07S_Stamm::trainer_loeschen( $sparte, self::zahl( 'id' ) ), 'Gelöscht.' );
    }

    public static function slot_speichern() {
        $sparte = self::sparte();
        $saison = isset( $_POST['saison_id'] ) ? self::zahl( 'saison_id' ) : null;
        self::erg( LSV07S_Stamm::slot_speichern( $sparte, self::zahl( 'id' ),
            self::zahl( 'mannschaft_id' ), self::zahl( 'wochentag' ),
            self::text( 'von', 8 ), self::text( 'bis', 8 ), $saison ) );
    }

    public static function slot_loeschen() {
        $sparte = self::sparte();
        $erg = LSV07S_Stamm::slot_loeschen( $sparte, self::zahl( 'id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( [
            'message' => 'Die Trainingszeit ist gelöscht.'
                . ( ! empty( $erg['erfasst'] )
                    ? ' Die ' . (int) $erg['erfasst'] . ' erfassten Trainings bleiben erhalten.'
                    : '' ),
        ] );
    }

    public static function saisons() {
        LSV07S_Zugang::ja( [
            'saisons'      => LSV07S_Stamm::saisons(),
            'darf_aendern' => LSV07S_Rechte::darf( 'admin.saison.manage' ),
        ] );
    }

    public static function saison_speichern() {
        self::erg( LSV07S_Stamm::saison_speichern( self::zahl( 'id' ),
            $_POST['name'] ?? '', self::text( 'von', 10 ), self::text( 'bis', 10 ) ) );
    }

    public static function saison_aktiv() {
        self::erg( LSV07S_Stamm::saison_aktiv_setzen( self::zahl( 'id' ) ),
            'Das ist jetzt die laufende Saison.' );
    }

    public static function saison_loeschen() {
        self::erg( LSV07S_Stamm::saison_loeschen( self::zahl( 'id' ) ), 'Gelöscht.' );
    }

    // ── Mannschaftsbuch ──────────────────────────────────────────────────

    /** Alles zu einem Trainingstag, was nicht der Zustand einer Person ist. */
    public static function buch_tag() {
        $sparte = self::sparte();
        $mann   = self::zahl( 'mannschaft_id' );
        $datum  = self::text( 'datum', 10 );
        $tag    = LSV07S_Anwesenheit::tag( $sparte, $mann, $datum );
        if ( ! $tag ) LSV07S_Zugang::nein( 'Diesen Trainingstag gibt es nicht.', 404 );
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Rechte::MANNSCHAFTSBUCH_READ,
                LSV07S_Rechte::MANNSCHAFTSBUCH_EDIT,
                LSV07S_Sparte::recht( $sparte, 'anw.read' ) ] ) ) {
            LSV07S_Zugang::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
        $erlaubt = array_column( LSV07S_Mannschaft::erlaubte( $sparte ), 'id' );
        if ( ! in_array( $mann, $erlaubt, true ) ) {
            LSV07S_Zugang::nein( 'Für diese Mannschaft fehlt Ihnen die Berechtigung.', 403 );
        }
        LSV07S_Zugang::ja( [
            'tag'        => $tag,
            'doku'       => LSV07S_Buch::doku( $sparte, $mann, $datum ),
            'bahnen'     => LSV07S_Buch::bahnen( $sparte, $mann, $datum ),
            'springer'   => LSV07S_Buch::springer( $sparte, $mann, $datum ),
            'trainer'    => LSV07S_Buch::trainer_tag( $sparte, $mann, $datum ),
            'arten'      => LSV07S_Buch::ARTEN,
            'kategorien' => LSV07S_Buch::KATEGORIEN,
        ] );
    }

    public static function buch_doku() {
        $sparte = self::sparte();
        self::erg( LSV07S_Buch::doku_speichern( $sparte, self::zahl( 'mannschaft_id' ),
            self::text( 'datum', 10 ), $_POST['text'] ?? '' ) );
    }

    public static function buch_eintrag() {
        $sparte = self::sparte();
        self::erg( LSV07S_Buch::eintrag_speichern( $sparte, self::zahl( 'mannschaft_id' ),
            self::text( 'datum', 10 ), self::zahl( 'id' ), [
                'art'       => $_POST['art'] ?? 'vermerk',
                'kategorie' => $_POST['kategorie'] ?? 'sonstiges',
                'text'      => $_POST['text'] ?? '',
                'person_id' => self::zahl( 'person_id' ),
            ] ) );
    }

    public static function buch_erledigt() {
        $sparte = self::sparte();
        $ja = ! empty( $_POST['erledigt'] );
        self::erg( LSV07S_Buch::eintrag_erledigt( $sparte, self::zahl( 'id' ), $ja ),
            $ja ? 'Erledigt.' : 'Wieder offen.' );
    }

    public static function buch_eintrag_weg() {
        $sparte = self::sparte();
        self::erg( LSV07S_Buch::eintrag_loeschen( $sparte, self::zahl( 'id' ) ), 'Gelöscht.' );
    }

    public static function buch_aufgaben() {
        $sparte = self::sparte();
        LSV07S_Zugang::ja( [
            'aufgaben' => LSV07S_Buch::offene_aufgaben( $sparte, self::zahl( 'mannschaft_id' ) ),
        ] );
    }

    public static function bahnen_speichern() {
        $sparte = self::sparte();
        $erg = LSV07S_Buch::bahnen_speichern( $sparte, self::zahl( 'mannschaft_id' ),
            self::text( 'datum', 10 ), (array) ( $_POST['bahnen'] ?? [] ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( [ 'message' => 'Gespeichert.', 'bahnen' => $erg ] );
    }

    public static function bahnen_uebernehmen() {
        $sparte = self::sparte();
        $erg = LSV07S_Buch::bahnen_uebernehmen( $sparte, self::zahl( 'mannschaft_id' ),
            self::text( 'datum', 10 ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( [
            'message' => 'Übernommen vom ' . LSV07S_Datum::de( $erg['vom'] ) . '.',
            'bahnen'  => $erg['bahnen'],
        ] );
    }

    public static function springer_eintragen() {
        $sparte = self::sparte();
        self::erg( LSV07S_Buch::springer_eintragen( $sparte, self::zahl( 'mannschaft_id' ),
            self::text( 'datum', 10 ), self::zahl( 'trainer_id' ) ), 'Eingetragen.' );
    }

    public static function springer_austragen() {
        $sparte = self::sparte();
        self::erg( LSV07S_Buch::springer_austragen( $sparte, self::zahl( 'id' ) ),
            'Ausgetragen.' );
    }

    public static function buch_trainer() {
        $sparte = self::sparte();
        self::erg( LSV07S_Buch::trainer_setzen( $sparte, self::zahl( 'mannschaft_id' ),
            self::text( 'datum', 10 ), self::zahl( 'trainer_id' ),
            ! empty( $_POST['da'] ) ), 'Gespeichert.' );
    }

    // ── Atteste ──────────────────────────────────────────────────────────

    public static function attest_liste() {
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Rechte::ATTEST_READ,
                                            LSV07S_Rechte::ATTEST_EDIT ] ) ) {
            LSV07S_Zugang::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
        $mann = self::zahl( 'mannschaft_id' );
        LSV07S_Zugang::ja( [
            'mannschaften' => LSV07S_Mannschaft::erlaubte( LSV07S_Sparte::SCHWIMMEN ),
            'mannschaft_id'=> $mann,
            'zeilen'       => $mann ? LSV07S_Attest::liste( $mann ) : [],
            'warnung_tage' => LSV07S_Attest::warnung_tage(),
            'darf_aendern' => LSV07S_Rechte::darf( LSV07S_Rechte::ATTEST_EDIT ),
        ] );
    }

    public static function attest_datum() {
        self::erg( LSV07S_Attest::datum_setzen( self::zahl( 'person_id' ),
            self::text( 'bis', 10 ) ) );
    }

    /** Eine Datei kommt als Upload, nicht als Feld. */
    public static function attest_datei() {
        $datei = $_FILES['datei'] ?? null;
        if ( ! is_array( $datei ) ) {
            LSV07S_Zugang::nein( 'Es ist keine Datei angekommen.', 400 );
        }
        self::erg( LSV07S_Attest::datei_ablegen( self::zahl( 'person_id' ), $datei,
            $_POST['kommentar'] ?? '' ), 'Die Datei ist abgelegt.' );
    }

    /**
     * Eine Attestdatei ausgeben.
     *
     * Der einzige Endpunkt, der keine JSON-Antwort schickt: Hier geht die
     * Datei selbst heraus. Der Ordner ist für den Browser gesperrt, also
     * führt der einzige Weg zu einem Attest durch dieses Tor — und
     * `datei_pfad()` schreibt jeden Abruf ins Protokoll.
     *
     * `nosniff` und `attachment` sind kein Beiwerk: Ein PDF, das der
     * Browser als HTML deutet, wäre im Kontext der Vereinsseite
     * ausführbar. Zwischenspeichern darf es niemand — es ist ein
     * Gesundheitsdatum.
     */
    public static function attest_datei_zeigen() {
        $erg = LSV07S_Attest::datei_pfad( self::zahl( 'id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );

        nocache_headers();
        header( 'Content-Type: ' . $erg['typ'] );
        header( 'Content-Length: ' . (string) filesize( $erg['pfad'] ) );
        header( 'Content-Disposition: attachment; filename="'
              . rawurlencode( $erg['name'] ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Security-Policy: default-src \'none\'; sandbox' );
        while ( ob_get_level() > 0 ) ob_end_clean();
        readfile( $erg['pfad'] );
        exit;
    }

    public static function attest_datei_weg() {
        self::erg( LSV07S_Attest::datei_loeschen( self::zahl( 'id' ) ), 'Gelöscht.' );
    }

    public static function attest_erinnern() {
        self::erg( LSV07S_Attest::erinnern( self::zahl( 'person_id' ) ),
            'Die Erinnerung ist verschickt.' );
    }

    public static function attest_faellig() {
        if ( ! LSV07S_Rechte::darf_eines( [ LSV07S_Rechte::ATTEST_READ,
                                            LSV07S_Rechte::ATTEST_EDIT ] ) ) {
            LSV07S_Zugang::nein( 'Sie haben keine Berechtigung für diese Aktion.', 403 );
        }
        LSV07S_Zugang::ja( [ 'faellig' => LSV07S_Attest::faellig( self::zahl( 'mannschaft_id' ) ) ] );
    }

    public static function attest_trainer() {
        LSV07S_Zugang::ja( [ 'trainer' => LSV07S_Attest::trainer_fristen() ] );
    }

    // ── Akte ─────────────────────────────────────────────────────────────

    public static function akte() {
        $sparte = self::sparte();
        $erg = LSV07S_Akte::akte( $sparte, self::zahl( 'person_id' ),
            self::text( 'von', 10 ), self::text( 'bis', 10 ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( $erg );
    }

    // ── Wettkampf ────────────────────────────────────────────────────────

    public static function wk_liste() {
        LSV07S_Zugang::ja( [
            'wettkaempfe' => LSV07S_Wettkampf::liste( self::zahl( 'jahr' ),
                                                      self::zahl( 'mannschaft_id' ) ),
            'mannschaften' => LSV07S_Mannschaft::erlaubte( LSV07S_Sparte::SCHWIMMEN ),
            'jahr' => self::zahl( 'jahr' ) ?: (int) LSV07S_Datum::jahr(),
            'darf_anlegen' => LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_WETTKAMPF_CREATE ),
            'darf_freigeben' => LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_WETTKAMPF_APPROVE ),
            'dokumente' => LSV07S_Wettkampf::DOKUMENTE,
        ] );
    }

    public static function wk() {
        $erg = LSV07S_Wettkampf::holen( self::zahl( 'id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( $erg );
    }

    public static function wk_speichern() {
        $tage = self::feld( 'tage' );
        self::erg( LSV07S_Wettkampf::speichern( self::zahl( 'id' ), [
            'name' => $_POST['name'] ?? '',
            'ort'  => $_POST['ort'] ?? '',
            'datum_von' => self::text( 'datum_von', 10 ),
            'datum_bis' => self::text( 'datum_bis', 10 ),
            'mannschaften' => (array) ( $_POST['mannschaften'] ?? [] ),
            'tage' => $tage,
            'ohne_ausschreibung' => ! empty( $_POST['ohne_ausschreibung'] ),
        ] + ( isset( $_POST['links'] ) ? [ 'links' => self::feld( 'links' ) ] : [] ) ) );
    }

    public static function wk_loeschen() {
        self::erg( LSV07S_Wettkampf::loeschen( self::zahl( 'id' ) ), 'Gelöscht.' );
    }

    public static function wk_freigeben() {
        self::erg( LSV07S_Wettkampf::freigeben( self::zahl( 'id' ),
            ! empty( $_POST['an'] ) ) );
    }

    public static function wk_dokument() {
        $datei = $_FILES['datei'] ?? null;
        if ( ! is_array( $datei ) ) {
            LSV07S_Zugang::nein( 'Es ist keine Datei angekommen.', 400 );
        }
        self::erg( LSV07S_Wettkampf::dokument_ablegen( self::zahl( 'wettkampf_id' ),
            self::text( 'typ', 20 ), $datei ) );
    }

    /**
     * Ein Wettkampfdokument ausgeben.
     *
     * Wie bei den Attesten keine JSON-Antwort, sondern die Datei. Ein
     * freigegebener Wettkampf ist öffentlich — das Tor lässt deshalb hier
     * auch Konten ohne Leserecht durch, und `dokument_pfad()`
     * entscheidet. Angemeldet sein muss man trotzdem: `wp_ajax_nopriv`
     * wird in SwimSys nirgends angemeldet.
     */
    public static function wk_dokument_zeigen() {
        $erg = LSV07S_Wettkampf::dokument_pfad( self::zahl( 'id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );

        nocache_headers();
        header( 'Content-Type: ' . $erg['typ'] );
        header( 'Content-Length: ' . (string) filesize( $erg['pfad'] ) );
        header( 'Content-Disposition: attachment; filename="'
              . rawurlencode( $erg['name'] ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Security-Policy: default-src \'none\'; sandbox' );
        while ( ob_get_level() > 0 ) ob_end_clean();
        readfile( $erg['pfad'] );
        exit;
    }

    public static function wk_dokument_weg() {
        self::erg( LSV07S_Wettkampf::dokument_loeschen( self::zahl( 'id' ) ), 'Gelöscht.' );
    }

    public static function wk_anw_tag() {
        $erg = LSV07S_Wettkampf::anw_tag( self::zahl( 'tag_id' ),
            self::zahl( 'mannschaft_id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( $erg );
    }

    public static function wk_anw_setzen() {
        self::erg( LSV07S_Wettkampf::anw_setzen( self::zahl( 'tag_id' ),
            self::zahl( 'mannschaft_id' ), self::text( 'typ', 20 ),
            self::zahl( 'teilnehmer_id' ), self::text( 'status', 20 ) ) );
    }

    public static function wk_anw_abschnitte() {
        self::erg( LSV07S_Wettkampf::anw_abschnitte( self::zahl( 'tag_id' ),
            self::zahl( 'mannschaft_id' ), self::zahl( 'trainer_id' ),
            self::zahl( 'abschnitte' ) ) );
    }

    public static function wk_statistik() {
        LSV07S_Zugang::ja( LSV07S_Wettkampf::statistik( self::zahl( 'mannschaft_id' ),
            self::text( 'von', 10 ), self::text( 'bis', 10 ) ) );
    }

    public static function wk_offene_tage() {
        LSV07S_Zugang::ja( [ 'tage' => LSV07S_Wettkampf::offene_tage(
            self::zahl( 'trainer_id' ) ) ] );
    }

    // ── Meldung ──────────────────────────────────────────────────────────

    public static function meld_liste() {
        LSV07S_Zugang::ja( [
            'meldungen' => LSV07S_Meldung::liste( self::zahl( 'wettkampf_id' ) ),
            'wettkaempfe' => LSV07S_Wettkampf::liste( self::zahl( 'jahr' ) ),
            'mannschaften' => LSV07S_Mannschaft::erlaubte( LSV07S_Sparte::SCHWIMMEN ),
            'darf_aendern' => LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_MELDUNG_EDIT ),
        ] );
    }

    public static function meld() {
        $erg = LSV07S_Meldung::holen( self::zahl( 'id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( $erg );
    }

    public static function meld_kopf() {
        self::erg( LSV07S_Meldung::kopf_speichern( self::zahl( 'wettkampf_id' ),
            self::zahl( 'mannschaft_id' ), self::zahl( 'abschnitte' ),
            self::zahl( 'nummern' ) ) );
    }

    public static function meld_starts() {
        self::erg( LSV07S_Meldung::starts_setzen( self::zahl( 'meldung_id' ),
            self::feld( 'starts' ) ) );
    }

    public static function meld_loeschen() {
        self::erg( LSV07S_Meldung::loeschen( self::zahl( 'id' ) ), 'Gelöscht.' );
    }

    public static function meld_export() {
        $erg = LSV07S_Meldung::export( self::zahl( 'id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( $erg );
    }

    // ── Bestzeiten ───────────────────────────────────────────────────────

    public static function bz_tafel() {
        $erg = LSV07S_Bestzeit::tafel( self::zahl( 'mannschaft_id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( $erg );
    }

    public static function bz_vorschau() {
        /* Der eingefügte Block ist Text, nicht ein Feld: Er darf
           Tabulatoren und Zeilenumbrüche behalten, deshalb KEIN
           sanitize_text_field — das würde ihn zu einer Zeile machen.
           Gelesen wird er Zeile für Zeile, und was dabei herauskommt,
           geht durch die Prüfungen in LSV07S_Bestzeit. */
        $text = (string) wp_unslash( $_POST['text'] ?? '' );
        $erg = LSV07S_Bestzeit::vorschau( $text, self::zahl( 'mannschaft_id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 400 );
        LSV07S_Zugang::ja( $erg );
    }

    public static function bz_uebernehmen() {
        self::erg( LSV07S_Bestzeit::uebernehmen( self::zahl( 'mannschaft_id' ),
            self::feld( 'zeilen' ) ) );
    }

    public static function bz_loeschen() {
        self::erg( LSV07S_Bestzeit::loeschen( self::zahl( 'person_id' ),
            self::text( 'strecke', 10 ) ), 'Gelöscht.' );
    }

    public static function bz_alle_loeschen() {
        self::erg( LSV07S_Bestzeit::alle_loeschen( self::zahl( 'mannschaft_id' ) ),
            'Gelöscht.' );
    }

    public static function bz_export() {
        $erg = LSV07S_Bestzeit::export( self::zahl( 'mannschaft_id' ) );
        if ( is_string( $erg ) ) LSV07S_Zugang::nein( $erg, 403 );
        LSV07S_Zugang::ja( $erg );
    }
}
