<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auskunft (Art. 15) und Löschung (Art. 17) über die Bordmittel von
 * WordPress — für den internen Bereich.
 *
 * WordPress bringt unter **Werkzeuge → Personenbezogene Daten exportieren**
 * und **→ löschen** einen fertigen Ablauf mit, samt Bestätigung per E-Mail
 * und zeitlich begrenztem Download. Jedes Plugin trägt über zwei Filter
 * bei, was es gespeichert hat. Dieses hängt sich dort ein.
 *
 * **Warum es hier anders aussieht als in der Abrechnung.** Der interne
 * Bereich führt über siebzig eigene Tabellen. Jede Spalte von Hand zu
 * beschriften wäre eine Liste, die beim nächsten Feld veraltet — und eine
 * veraltete Auskunft ist eine unvollständige. Darum wird hier **jede Spalte
 * einer angebundenen Tabelle ausgegeben**, beschriftet über ein
 * gemeinsames Wörterbuch und sonst mit dem Spaltennamen. Vollständigkeit
 * geht vor schöner Beschriftung: Art. 15 verlangt die Daten, nicht die
 * Formulierung.
 *
 * Ausgenommen sind einzig **Geheimnisse**: Schlüssel, Token und Hashes. Sie
 * sind keine Auskunft über eine Person, sondern Zugangsmittel — in einer
 * Exportdatei wären sie ein Risiko und kein Nutzen.
 *
 * **Eine Person kann hier viele Kennungen haben**: ein WordPress-Konto, eine
 * Zeile in der Personentabelle, einen Trainer-Eintrag, einen Schwimmer-,
 * Triathlon- oder Fitness-Eintrag. Alle werden aus der E-Mail-Adresse
 * aufgelöst, sonst käme auf dieselbe Adresse je nach Tabelle eine andere
 * Antwort.
 */
class LSV07I_Privacy {

    const SCHLUESSEL = 'lsv07-intern';
    const NAME       = 'LSV07 Interner Bereich';
    const PRO_SEITE  = 200;

    /**
     * Tabellen ohne Personenbezug.
     *
     * Eine Prüfung hält diese Liste gegen die angebundenen Tabellen: Was
     * weder hier noch dort steht, fehlt in der Auskunft — und das fiele
     * sonst niemandem auf.
     */
    const OHNE_PERSON = [
        'lsv07_gruppen',                  // Name einer Mannschaft
        'lsv07i_config',                  // Einstellungen des Vereins
        'lsv07i_training_slots',          // Wochentag und Uhrzeit
        'lsv07i_training_ausfall',        // ein abgesagtes Training
        'lsv07i_tri_gruppen',
        'lsv07i_tri_slots',
        'lsv07i_fit_gruppen',
        'lsv07i_fit_slots',
        'lsv07i_wettkampf_mannschaft',    // welche Mannschaft bei welchem Wettkampf
        'lsv07i_wettkampf_tage',          // Tage und geplante Abschnitte
        'lsv07i_trainingsplan_session',   // der Inhalt eines Plans
        'lsv07i_refl_throttle',           // nur ein IP-Hash, zu niemandem auflösbar
    ];

    /** Spalten, die nie in eine Exportdatei gehören. */
    const GEHEIM = [
        'token', 'reply_token', 'schluessel_hash', 'code_hash', 'geheim_hash',
        'ip_hash', 'rights_json', 'fragen_snapshot',
    ];

    /** Beschriftungen für die Spalten, die überall gleich heissen. */
    const WOERTER = [
        'id' => 'Nummer', 'name' => 'Name', 'titel' => 'Titel', 'text' => 'Text',
        'email' => 'E-Mail', 'telefon' => 'Telefon', 'notes' => 'Notiz',
        'notiz' => 'Notiz', 'kommentar' => 'Kommentar', 'status' => 'Stand',
        'datum' => 'Datum', 'training_datum' => 'Trainingstag',
        'erstellt_am' => 'Erstellt am', 'geaendert_am' => 'Geändert am',
        'angelegt_am' => 'Angelegt am', 'hochgeladen_am' => 'Hochgeladen am',
        'gelesen_am' => 'Gelesen am', 'abgegeben_am' => 'Abgegeben am',
        'eingereicht_am' => 'Eingereicht am', 'genehmigt_am' => 'Genehmigt am',
        'bezahlt_am' => 'Bezahlt am', 'erreicht_am' => 'Erreicht am',
        'vorname' => 'Vorname', 'nachname' => 'Nachname',
        'first_name' => 'Vorname', 'last_name' => 'Nachname',
        'birth_date' => 'Geburtsdatum', 'geburtsdatum' => 'Geburtsdatum',
        'geschlecht' => 'Geschlecht', 'dsv_id' => 'DSV-ID',
        'mitgliedsnummer' => 'Mitgliedsnummer', 'jahrgang' => 'Jahrgang',
        'iban' => 'IBAN', 'bic' => 'BIC', 'strasse' => 'Strasse',
        'plz' => 'PLZ', 'ort' => 'Ort', 'stundensatz' => 'Stundensatz',
        'betrag' => 'Betrag', 'stunden' => 'Stunden', 'km' => 'Kilometer',
        'quartal' => 'Quartal', 'jahr' => 'Jahr', 'satz' => 'Satz',
        'abr_status' => 'Stand der Abrechnung', 'bezahlt' => 'Bezahlt',
        'rueckgabe_grund' => 'Grund der Rückgabe', 'begruendung' => 'Begründung',
        'strecke' => 'Strecke', 'zeit_raw' => 'Zeit', 'meldezeit' => 'Meldezeit',
        'attest_bis' => 'Attest gültig bis', 'attest_expires' => 'Attest gültig bis',
        'aktiv' => 'Aktiv', 'rolle' => 'Rolle', 'recht' => 'Recht',
        'sparte' => 'Sparte', 'art' => 'Art', 'kategorie' => 'Kategorie',
        'prioritaet' => 'Priorität', 'nummer' => 'Nummer',
        'beschreibung' => 'Beschreibung', 'dateiname' => 'Dateiname',
        'mime_type' => 'Typ', 'groesse' => 'Grösse in Bytes',
        'swimmer_name' => 'Name', 'teilnehmer_name' => 'Name',
        'von_name' => 'Von', 'ersteller_name' => 'Erstellt von',
        'user_name' => 'Name', 'aktion' => 'Vorgang', 'bereich' => 'Bereich',
        'ziel_typ' => 'Bezug', 'ziel_name' => 'Bezug', 'details' => 'Einzelheiten',
        'ip' => 'IP (gekürzt)', 'zeitpunkt' => 'Zeitpunkt',
        'verspaetung' => 'Verspätung in Minuten',
        'verspaetung_grund' => 'Grund der Verspätung',
        'dokumentation' => 'Dokumentation', 'bahnen' => 'Bahnenbelegung',
        'antwort_text' => 'Antwort', 'frage_text' => 'Frage', 'frage' => 'Frage',
        'emoji' => 'Reaktion', 'dringend' => 'Dringend',
        'abschnitte' => 'Abschnitte', 'pauschale' => 'Pauschale',
        'erfolg' => 'Erfolgreich', 'url' => 'Adresse', 'ort_' => 'Ort',
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

    // ── Wen meint diese E-Mail? ──────────────────────────────────────────

    /**
     * Alle Kennungen hinter einer Adresse.
     *
     * 0 heisst „gibt es nicht". Die Adresse selbst bleibt als `email`
     * erhalten: Manche Tabellen führen nur sie und keine Nummer.
     */
    public static function kennungen( $email ) {
        global $wpdb;
        $email = trim( (string) $email );
        $k = [ 'user' => 0, 'person' => 0, 'trainer' => 0, 'swimmer' => 0,
               'tri' => 0, 'tri_trainer' => 0, 'fit' => 0, 'fit_trainer' => 0,
               'email' => $email ];
        if ( $email === '' ) return $k;

        $user = get_user_by( 'email', $email );
        $k['user'] = $user ? (int) $user->ID : 0;

        /* Jede Tabelle nennt ihre Person entweder über das WordPress-Konto
           oder über die eigene Adresse. Beides wird gesucht — wer sein
           WordPress-Konto unter einer anderen Adresse führt als seinen
           Eintrag im Verein, ist trotzdem dieselbe Person. */
        $suchen = [
            'person'      => [ 'lsv07i_personen',    true  ],
            'trainer'     => [ 'lsv07i_trainer',     true  ],
            'swimmer'     => [ 'mv_swimmers',        false ],
            'tri'         => [ 'lsv07i_tri_sportler', false ],
            'tri_trainer' => [ 'lsv07i_tri_trainer', true  ],
            'fit'         => [ 'lsv07i_fit_sportler', false ],
            'fit_trainer' => [ 'lsv07i_fit_trainer', true  ],
        ];
        foreach ( $suchen as $schluessel => $was ) {
            list( $tabelle, $hat_konto ) = $was;
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            $wo = 'email = %s';
            $args = [ $email ];
            if ( $hat_konto && $k['user'] ) {
                $wo = '( email = %s OR wp_user_id = %d )';
                $args[] = $k['user'];
            }
            $k[ $schluessel ] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . self::t( $tabelle ) . " WHERE $wo ORDER BY id ASC LIMIT 1",
                $args ) );
        }
        return $k;
    }

    /** Gibt es diese Tabelle? Ein halb eingerichteter Verein soll nicht brechen. */
    private static function tabelle_da( $name ) {
        global $wpdb;
        static $bekannt = [];
        if ( isset( $bekannt[ $name ] ) ) return $bekannt[ $name ];
        $voll = self::t( $name );
        $da = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $voll ) ) === $voll;
        return $bekannt[ $name ] = $da;
    }

    /**
     * Hat irgendeine Kennung getroffen?
     *
     * Nicht jede Person hat hier eine Nummer. Eine Kontaktperson —
     * typischerweise ein Elternteil — steht nur mit ihrer Adresse in
     * `lsv07i_kontakte` und in der Mailwarteschlange. Fragt sie nach
     * Auskunft, wäre „zu dieser Adresse ist nichts gespeichert" schlicht
     * falsch. Darum werden die Tabellen, die nur eine Adresse führen,
     * ebenfalls befragt.
     */
    private static function jemand( $k ) {
        foreach ( $k as $schluessel => $wert ) {
            if ( $schluessel !== 'email' && (int) $wert > 0 ) return true;
        }
        if ( $k['email'] === '' ) return false;

        global $wpdb;
        foreach ( [ 'lsv07i_kontakte' => 'email',
                    'lsv07i_email_queue' => 'empfaenger',
                    'lsv07i_konv_msg' => 'von_email',
                    'lsv07i_konv_teilnehmer' => 'teilnehmer_email',
                    'lsv07i_wettkampf_erinnerung' => 'email',
                    'lsv07i_wettkampf_mail_empfaenger' => 'email' ] as $tabelle => $spalte ) {
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            $da = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::t( $tabelle ) . " WHERE $spalte = %s LIMIT 1",
                $k['email'] ) );
            if ( $da ) return true;
        }
        return false;
    }

    // ── Die Quellen ──────────────────────────────────────────────────────

    /**
     * Jede Tabelle mit Personenbezug, mit der Bedingung, die „ich" meint.
     *
     * `wo` ist eine Liste von Teilbedingungen. Jede nennt eine Spalte, die
     * Art der Kennung und — wo eine Tabelle mehrere Arten in einer Spalte
     * führt — die dazugehörige Typspalte. Leere Kennungen fallen heraus;
     * bleibt nichts übrig, wird die Tabelle gar nicht erst abgefragt.
     */
    private static function quellen() {
        return [
            // ── Stammdaten ──────────────────────────────────────────────
            [ 'lsv07i_personen',      'Personenverzeichnis',            [ [ 'id', 'person' ] ] ],
            [ 'lsv07i_personen_sparten_rolle', 'Rollen je Sparte',      [ [ 'person_id', 'person' ] ] ],
            [ 'lsv07i_personen_mannschaft', 'Zuordnung zu Mannschaften', [ [ 'person_id', 'person' ] ] ],
            [ 'lsv07i_personen_migration_log', 'Übernahme ins Personenverzeichnis',
                                                                        [ [ 'person_id', 'person' ] ] ],
            [ 'mv_swimmers',          'Schwimmerdaten',                 [ [ 'id', 'swimmer' ] ] ],
            [ 'lsv07i_kontakte',      'Kontaktpersonen',                [ [ 'swimmer_id', 'swimmer' ],
                                                                          [ 'email', 'email' ] ] ],
            [ 'lsv07i_swimmer_gruppen', 'Zuordnung zu Mannschaften',    [ [ 'swimmer_id', 'swimmer' ] ] ],
            [ 'lsv07i_trainer',       'Trainerprofil',                  [ [ 'id', 'trainer' ] ] ],
            [ 'lsv07i_trainer_mannschaft', 'Betreute Mannschaften',     [ [ 'trainer_id', 'trainer' ] ] ],
            [ 'lsv07i_user_rechte',   'Zugewiesene Rechte',             [ [ 'user_id', 'user' ],
                                                                          [ 'granted_by', 'user' ] ] ],
            [ 'lsv07i_rechte_templates', 'Angelegte Rechtevorlagen',    [ [ 'erstellt_von', 'user' ] ] ],

            // ── Abrechnung (Aufbewahrungspflicht) ───────────────────────
            [ 'lsv07i_trainer_stammdaten', 'Abrechnung: Stammdaten',    [ [ 'trainer_id', 'trainer' ] ] ],
            [ 'lsv07i_abrechnung',    'Abrechnung: Quartalsabrechnungen', [ [ 'trainer_id', 'trainer' ] ] ],
            [ 'lsv07i_abr_trainingstage', 'Abrechnung: Trainingstage',  [ [ 'abrechnung_id', 'abrechnung' ] ] ],
            [ 'lsv07i_abr_wettkampf', 'Abrechnung: Wettkämpfe',         [ [ 'abrechnung_id', 'abrechnung' ] ] ],
            [ 'lsv07i_abr_kilometer', 'Abrechnung: Fahrten',            [ [ 'abrechnung_id', 'abrechnung' ] ] ],
            [ 'lsv07i_abr_sonder',    'Abrechnung: Sonderposten',       [ [ 'abrechnung_id', 'abrechnung' ] ] ],

            // ── Training und Anwesenheit ────────────────────────────────
            [ 'lsv07i_anwesenheit',   'Erfasste Anwesenheiten',         [ [ 'erstellt_von', 'user' ] ] ],
            [ 'lsv07i_anwesenheit_eintraege', 'Eigene Anwesenheit',
                [ [ 'teilnehmer_id', 'swimmer', 'teilnehmer_typ', 'schwimmer' ],
                  [ 'teilnehmer_id', 'trainer', 'teilnehmer_typ', 'trainer' ],
                  [ 'teilnehmer_id', 'user',    'teilnehmer_typ', 'user' ] ] ],
            [ 'lsv07i_anwesenheit_ausschluss', 'Von der Abrechnung ausgenommen',
                                                                        [ [ 'trainer_id', 'trainer' ],
                                                                          [ 'ausgeschlossen_von', 'user' ] ] ],
            [ 'lsv07i_springer',      'Als Springer eingetragen',       [ [ 'trainer_id', 'trainer' ] ] ],
            [ 'lsv07i_bestzeiten',    'Bestzeiten',                     [ [ 'swimmer_id', 'swimmer' ],
                                                                          [ 'importiert_von', 'user' ] ] ],
            [ 'lsv07i_schwimmer_dateien', 'Hinterlegte Dateien',        [ [ 'schwimmer_id', 'swimmer' ],
                                                                          [ 'hochgeladen_von', 'user' ] ] ],

            // ── Mannschaftsbuch ─────────────────────────────────────────
            [ 'lsv07i_mb_tag',        'Mannschaftsbuch: Tage',          [ [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07i_mb_eintrag',    'Mannschaftsbuch: Einträge',      [ [ 'schwimmer_id', 'swimmer' ],
                                                                          [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07i_mb_anwesenheit', 'Mannschaftsbuch: Anwesenheit',  [ [ 'schwimmer_id', 'swimmer' ],
                                                                          [ 'geaendert_von', 'user' ] ] ],

            // ── Wettkämpfe und Meldungen ────────────────────────────────
            [ 'lsv07i_wettkampf',     'Angelegte Wettkämpfe',           [ [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_anwesenheit', 'Wettkampf: erfasste Anwesenheit',
                                                                        [ [ 'erstellt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_anw_eintraege', 'Wettkampf: eigene Anwesenheit',
                [ [ 'teilnehmer_id', 'swimmer', 'teilnehmer_typ', 'schwimmer' ],
                  [ 'teilnehmer_id', 'trainer', 'teilnehmer_typ', 'trainer' ],
                  [ 'teilnehmer_id', 'user',    'teilnehmer_typ', 'user' ] ] ],
            [ 'lsv07i_wettkampf_dokument', 'Wettkampf: hochgeladene Dateien',
                                                                        [ [ 'hochgeladen_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_link', 'Wettkampf: hinzugefügte Verweise',
                                                                        [ [ 'hinzugefuegt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_erinnerung', 'Wettkampf: Erinnerung an diese Adresse',
                                                                        [ [ 'email', 'email' ],
                                                                          [ 'hinzugefuegt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_mail_empfaenger', 'Wettkampf: Mailempfänger',
                                                                        [ [ 'email', 'email' ],
                                                                          [ 'hinzugefuegt_von', 'user' ] ] ],
            [ 'lsv07i_meldung',       'Meldungen',                      [ [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07i_meldung_start', 'Gemeldete Starts',               [ [ 'schwimmer_id', 'swimmer' ] ] ],

            // ── Trainingspläne ──────────────────────────────────────────
            [ 'lsv07i_trainingsplan', 'Trainingspläne',                 [ [ 'ersteller_id', 'user' ] ] ],
            [ 'lsv07i_trainingsplan_vorlage', 'Vorlagen für Trainingspläne',
                                                                        [ [ 'ersteller_id', 'user' ] ] ],
            [ 'lsv07i_tp_datei',      'Trainingsplan: hochgeladene Dateien',
                                                                        [ [ 'hochgeladen_von', 'user' ] ] ],

            // ── Reflexion ───────────────────────────────────────────────
            [ 'lsv07i_refl_fragen',   'Angelegte Reflexionsfragen',     [ [ 'erstellt_von', 'user' ] ] ],
            [ 'lsv07i_refl_bogen',    'Reflexionsbögen',                [ [ 'swimmer_id', 'swimmer' ],
                                                                          [ 'erstellt_von', 'user' ] ] ],
            [ 'lsv07i_refl_antwort',  'Antworten in Reflexionsbögen',   [ [ 'bogen_id', 'bogen' ] ] ],

            // ── Nachrichten, Tickets, Protokoll ─────────────────────────
            [ 'lsv07i_konv',          'Angelegte Unterhaltungen',       [ [ 'erstellt_von', 'user' ] ] ],
            [ 'lsv07i_konv_teilnehmer', 'Teilnahme an Unterhaltungen',  [ [ 'teilnehmer_id', 'user',
                                                                            'teilnehmer_typ', 'user' ],
                                                                          [ 'teilnehmer_email', 'email' ],
                                                                          [ 'hinzugefuegt_von', 'user' ] ] ],
            [ 'lsv07i_konv_msg',      'Geschriebene Nachrichten',       [ [ 'von_id', 'user', 'von_typ', 'user' ],
                                                                          [ 'von_email', 'email' ],
                                                                          [ 'geloescht_von', 'user' ] ] ],
            [ 'lsv07i_konv_anhang',   'Anhänge an eigenen Nachrichten', [ [ 'msg_id', 'msg' ] ] ],
            [ 'lsv07i_konv_reaktion', 'Reaktionen auf Nachrichten',     [ [ 'user_id', 'user' ] ] ],
            [ 'lsv07i_email_queue',   'Versandte Benachrichtigungen',   [ [ 'empfaenger', 'email' ] ] ],
            /* Die alte Nachrichtentabelle von vor dem Umstieg auf
               Unterhaltungen. Sie bleibt nach der Übernahme stehen — und
               damit bleibt auch ihr Inhalt auskunftspflichtig. */
            [ 'lsv07i_nachrichten',   'Nachrichten (alte Fassung)',     [ [ 'von_user_id', 'user' ],
                                                                          [ 'an_user_id', 'user' ] ] ],
            [ 'lsv07i_benachrichtigung', 'Verfasste Aushänge',          [ [ 'ersteller_id', 'user' ] ] ],
            [ 'lsv07i_benachrichtigung_gelesen', 'Gelesene Aushänge',   [ [ 'user_id', 'user' ] ] ],
            [ 'lsv07i_tickets',       'Tickets',                        [ [ 'ersteller_user_id', 'user' ],
                                                                          [ 'zugewiesen_an', 'user' ] ] ],
            [ 'lsv07i_ticket_kommentare', 'Kommentare an Tickets',      [ [ 'autor_user_id', 'user' ] ] ],
            [ 'lsv07i_ticket_anhang', 'Anhänge an Tickets',             [ [ 'hochgeladen_von', 'user' ] ] ],
            [ 'lsv07i_log',           'Protokoll',                      [ [ 'user_id', 'user' ] ] ],
            [ 'lsv07i_saisons',       'Angelegte Saisons',              [ [ 'erstellt_von', 'user' ] ] ],

            // ── Triathlon ───────────────────────────────────────────────
            [ 'lsv07i_tri_sportler',  'Triathlon: Sportlerdaten',       [ [ 'id', 'tri' ] ] ],
            [ 'lsv07i_tri_sportler_gruppe', 'Triathlon: Gruppen',       [ [ 'sportler_id', 'tri' ] ] ],
            [ 'lsv07i_tri_trainer',   'Triathlon: Trainerprofil',       [ [ 'id', 'tri_trainer' ] ] ],
            [ 'lsv07i_tri_trainer_gruppe', 'Triathlon: betreute Gruppen', [ [ 'trainer_id', 'tri_trainer' ] ] ],
            [ 'lsv07i_tri_anwesenheit', 'Triathlon: erfasste Anwesenheit', [ [ 'erstellt_von', 'user' ] ] ],
            [ 'lsv07i_tri_anwesenheit_eintraege', 'Triathlon: eigene Anwesenheit',
                                                                        [ [ 'sportler_id', 'tri' ] ] ],

            // ── Fitness ─────────────────────────────────────────────────
            [ 'lsv07i_fit_sportler',  'Fitness: Sportlerdaten',         [ [ 'id', 'fit' ] ] ],
            [ 'lsv07i_fit_sportler_gruppe', 'Fitness: Gruppen',         [ [ 'sportler_id', 'fit' ] ] ],
            [ 'lsv07i_fit_trainer',   'Fitness: Trainerprofil',         [ [ 'id', 'fit_trainer' ] ] ],
            [ 'lsv07i_fit_trainer_gruppe', 'Fitness: betreute Gruppen', [ [ 'trainer_id', 'fit_trainer' ] ] ],
            [ 'lsv07i_fit_anwesenheit', 'Fitness: erfasste Anwesenheit', [ [ 'erstellt_von', 'user' ] ] ],
            [ 'lsv07i_fit_anwesenheit_eintraege', 'Fitness: eigene Anwesenheit',
                                                                        [ [ 'sportler_id', 'fit' ] ] ],
        ];
    }

    /** Die Tabellen, die angebunden sind — für die Abdeckungsprüfung. */
    public static function angebunden() {
        return array_values( array_unique( array_column( self::quellen(), 0 ) ) );
    }

    /**
     * Die WHERE-Bedingung einer Quelle, fertig gefüllt. Leer = nichts zu holen.
     *
     * Drei Arten von Kennung brauchen eine Unterabfrage, weil die Tabelle
     * die Person nicht selbst führt: Abrechnungsposten hängen an einer
     * Abrechnung, Reflexionsantworten an einem Bogen, Anhänge an einer
     * Nachricht. Sie werden hier aufgelöst statt in jeder Zeile.
     */
    private static function bedingung( $wo, $k ) {
        $teile = [];
        foreach ( $wo as $t ) {
            $spalte = $t[0];
            $art    = $t[1];

            if ( $art === 'email' ) {
                if ( $k['email'] === '' ) continue;
                $teil = sprintf( "%s = '%s'", $spalte, esc_sql( $k['email'] ) );
            } elseif ( $art === 'abrechnung' ) {
                if ( ! $k['trainer'] ) continue;
                $teil = sprintf( '%s IN ( SELECT id FROM %s WHERE trainer_id = %d )',
                    $spalte, self::t( 'lsv07i_abrechnung' ), $k['trainer'] );
            } elseif ( $art === 'bogen' ) {
                if ( ! $k['swimmer'] && ! $k['user'] ) continue;
                $teil = sprintf( '%s IN ( SELECT id FROM %s WHERE swimmer_id = %d OR erstellt_von = %d )',
                    $spalte, self::t( 'lsv07i_refl_bogen' ), $k['swimmer'], $k['user'] );
            } elseif ( $art === 'msg' ) {
                if ( ! $k['user'] && $k['email'] === '' ) continue;
                $teil = sprintf(
                    "%s IN ( SELECT id FROM %s WHERE ( von_typ = 'user' AND von_id = %d ) OR von_email = '%s' )",
                    $spalte, self::t( 'lsv07i_konv_msg' ), $k['user'], esc_sql( $k['email'] ) );
            } else {
                if ( empty( $k[ $art ] ) ) continue;
                $teil = sprintf( '%s = %d', $spalte, (int) $k[ $art ] );
            }

            /* Manche Tabellen führen mehrere Arten in einer Spalte — dann
               zählt die Nummer nur zusammen mit dem passenden Typ. */
            if ( isset( $t[2], $t[3] ) ) {
                $teil = sprintf( "( %s = '%s' AND %s )", $t[2], esc_sql( $t[3] ), $teil );
            }
            $teile[] = $teil;
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

        $schritt = $plan[ $seite - 1 ];
        return [
            'data' => self::seite_holen( $k, $schritt['quelle'], $schritt['offset'] ),
            'done' => $seite >= count( $plan ),
        ];
    }

    private static function plan( $k ) {
        global $wpdb;
        $plan = [];
        foreach ( self::quellen() as $i => $q ) {
            list( $tabelle, , $wo ) = $q;
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            $bed = self::bedingung( $wo, $k );
            if ( $bed === '' ) continue;
            $anzahl = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::t( $tabelle ) . " WHERE $bed" );
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
     * Was gelöscht wird, was anonymisiert wird, was bleibt.
     *
     * Die Abrechnungen sind Buchungsbelege und bleiben (§ 147 AO, § 257 HGB,
     * Art. 17 Abs. 3 lit. b DSGVO). Alles andere geht — mit einer Ausnahme:
     * Wo ein Eintrag für ANDERE sinnvoll bleiben muss (eine Nachricht in
     * einer Unterhaltung, eine erfasste Anwesenheit, ein Trainingsplan),
     * wird der Personenbezug entfernt statt der Inhalt gelöscht. Sonst
     * fehlte in der Unterhaltung der halbe Gesprächsverlauf.
     */
    public static function loeschen( $email, $seite = 1 ) {
        $leer = [ 'items_removed' => false, 'items_retained' => false,
                  'messages' => [], 'done' => true ];
        $k = self::kennungen( $email );
        if ( ! self::jemand( $k ) ) return $leer;
        if ( (int) $seite > 1 ) return $leer;

        global $wpdb;
        $weg = 0; $bleibt = 0; $meldungen = [];

        /* 1. Was ganz weg kann: beschreibt nur die Arbeit mit dem System. */
        $raus = [
            [ 'lsv07i_log',                      'user_id',        'user' ],
            [ 'lsv07i_user_rechte',              'user_id',        'user' ],
            [ 'lsv07i_konv_reaktion',            'user_id',        'user' ],
            [ 'lsv07i_benachrichtigung_gelesen', 'user_id',        'user' ],
            [ 'lsv07i_email_queue',              'empfaenger',     'email' ],
            [ 'lsv07i_wettkampf_erinnerung',     'email',          'email' ],
            [ 'lsv07i_wettkampf_mail_empfaenger','email',          'email' ],
            [ 'lsv07i_kontakte',                 'swimmer_id',     'swimmer' ],
            [ 'lsv07i_swimmer_gruppen',          'swimmer_id',     'swimmer' ],
            [ 'lsv07i_bestzeiten',               'swimmer_id',     'swimmer' ],
            [ 'lsv07i_meldung_start',            'schwimmer_id',   'swimmer' ],
            [ 'lsv07i_mb_anwesenheit',           'schwimmer_id',   'swimmer' ],
            [ 'lsv07i_mb_eintrag',               'schwimmer_id',   'swimmer' ],
            [ 'lsv07i_trainer_mannschaft',       'trainer_id',     'trainer' ],
            [ 'lsv07i_springer',                 'trainer_id',     'trainer' ],
            [ 'lsv07i_anwesenheit_ausschluss',   'trainer_id',     'trainer' ],
            [ 'lsv07i_personen_sparten_rolle',   'person_id',      'person' ],
            [ 'lsv07i_personen_mannschaft',      'person_id',      'person' ],
            [ 'lsv07i_personen_migration_log',   'person_id',      'person' ],
            [ 'lsv07i_tri_sportler_gruppe',      'sportler_id',    'tri' ],
            [ 'lsv07i_tri_anwesenheit_eintraege','sportler_id',    'tri' ],
            [ 'lsv07i_tri_trainer_gruppe',       'trainer_id',     'tri_trainer' ],
            [ 'lsv07i_fit_sportler_gruppe',      'sportler_id',    'fit' ],
            [ 'lsv07i_fit_anwesenheit_eintraege','sportler_id',    'fit' ],
            [ 'lsv07i_fit_trainer_gruppe',       'trainer_id',     'fit_trainer' ],
        ];
        foreach ( $raus as $r ) {
            list( $tabelle, $spalte, $art ) = $r;
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            if ( $art === 'email' ) {
                if ( $k['email'] === '' ) continue;
                $n = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM " . self::t( $tabelle ) . " WHERE $spalte = %s", $k['email'] ) );
            } else {
                if ( empty( $k[ $art ] ) ) continue;
                $n = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM " . self::t( $tabelle ) . " WHERE $spalte = %d", (int) $k[ $art ] ) );
            }
            if ( $n ) $weg += (int) $n;
        }

        /* Eigene Anwesenheit — in drei Tabellen, je nach Sparte. */
        foreach ( [ 'lsv07i_anwesenheit_eintraege', 'lsv07i_wettkampf_anw_eintraege' ] as $tabelle ) {
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            foreach ( [ 'schwimmer' => 'swimmer', 'trainer' => 'trainer', 'user' => 'user' ] as $typ => $art ) {
                if ( empty( $k[ $art ] ) ) continue;
                $n = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM " . self::t( $tabelle ) . "
                      WHERE teilnehmer_typ = %s AND teilnehmer_id = %d", $typ, (int) $k[ $art ] ) );
                if ( $n ) $weg += (int) $n;
            }
        }

        /* Reflexionsbögen samt Antworten: Sie gehören der Person allein. */
        if ( $k['swimmer'] && self::tabelle_da( 'lsv07i_refl_bogen' ) ) {
            $boegen = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM " . self::t( 'lsv07i_refl_bogen' ) . " WHERE swimmer_id = %d",
                $k['swimmer'] ) );
            foreach ( $boegen as $b ) {
                $wpdb->delete( self::t( 'lsv07i_refl_antwort' ), [ 'bogen_id' => (int) $b ], [ '%d' ] );
                $wpdb->delete( self::t( 'lsv07i_refl_bogen' ),   [ 'id' => (int) $b ], [ '%d' ] );
                $weg++;
            }
        }

        /* Hinterlegte Dateien: erst von der Platte, dann aus der Tabelle. */
        if ( $k['swimmer'] && self::tabelle_da( 'lsv07i_schwimmer_dateien' ) ) {
            $n = self::dateien_weg( $k['swimmer'] );
            if ( $n ) {
                $weg += $n;
                $meldungen[] = 'Hinterlegte Dateien (Atteste und Ähnliches) wurden gelöscht, '
                             . 'auch vom Dateisystem.';
            }
        }

        /* 2. Was für andere stehen bleiben muss: Name raus, Inhalt bleibt. */
        $anonym = [
            [ 'lsv07i_konv_msg',  "von_name = 'Gelöschtes Konto', von_email = NULL, von_id = 0",
              "von_typ = 'user' AND von_id = %d", 'user' ],
            [ 'lsv07i_konv_teilnehmer',
              "teilnehmer_name = 'Gelöschtes Konto', teilnehmer_email = NULL",
              "teilnehmer_typ = 'user' AND teilnehmer_id = %d", 'user' ],
            [ 'lsv07i_anwesenheit',          'erstellt_von = 0',    'erstellt_von = %d',    'user' ],
            [ 'lsv07i_tri_anwesenheit',      'erstellt_von = 0',    'erstellt_von = %d',    'user' ],
            [ 'lsv07i_fit_anwesenheit',      'erstellt_von = 0',    'erstellt_von = %d',    'user' ],
            [ 'lsv07i_wettkampf_anwesenheit','erstellt_von = 0',    'erstellt_von = %d',    'user' ],
            [ 'lsv07i_mb_tag',               'angelegt_von = 0',    'angelegt_von = %d',    'user' ],
            [ 'lsv07i_meldung',              'angelegt_von = 0',    'angelegt_von = %d',    'user' ],
            [ 'lsv07i_wettkampf',            'angelegt_von = 0',    'angelegt_von = %d',    'user' ],
            [ 'lsv07i_wettkampf_link',       'hinzugefuegt_von = 0','hinzugefuegt_von = %d','user' ],
            [ 'lsv07i_wettkampf_dokument',   'hochgeladen_von = 0', 'hochgeladen_von = %d', 'user' ],
            [ 'lsv07i_tp_datei',             'hochgeladen_von = 0', 'hochgeladen_von = %d', 'user' ],
            [ 'lsv07i_ticket_anhang',        'hochgeladen_von = 0', 'hochgeladen_von = %d', 'user' ],
            [ 'lsv07i_saisons',              'erstellt_von = 0',    'erstellt_von = %d',    'user' ],
            [ 'lsv07i_refl_fragen',          'erstellt_von = 0',    'erstellt_von = %d',    'user' ],
            [ 'lsv07i_rechte_templates',     'erstellt_von = 0',    'erstellt_von = %d',    'user' ],
            [ 'lsv07i_konv',                 'erstellt_von = 0',    'erstellt_von = %d',    'user' ],
            [ 'lsv07i_trainingsplan',
              "ersteller_id = 0, ersteller_name = 'Gelöschtes Konto'", 'ersteller_id = %d', 'user' ],
            [ 'lsv07i_trainingsplan_vorlage',
              "ersteller_id = 0, ersteller_name = 'Gelöschtes Konto'", 'ersteller_id = %d', 'user' ],
            [ 'lsv07i_benachrichtigung',
              "ersteller_id = 0, ersteller_name = 'Gelöschtes Konto'", 'ersteller_id = %d', 'user' ],
            [ 'lsv07i_tickets',
              'ersteller_user_id = 0', 'ersteller_user_id = %d', 'user' ],
            [ 'lsv07i_tickets', 'zugewiesen_an = NULL', 'zugewiesen_an = %d', 'user' ],
            [ 'lsv07i_ticket_kommentare', 'autor_user_id = 0', 'autor_user_id = %d', 'user' ],
            [ 'lsv07i_nachrichten', 'von_user_id = 0', 'von_user_id = %d', 'user' ],
            [ 'lsv07i_nachrichten', 'an_user_id = 0',  'an_user_id = %d',  'user' ],
            [ 'lsv07i_user_rechte', 'granted_by = NULL', 'granted_by = %d', 'user' ],
            [ 'lsv07i_anwesenheit_ausschluss', 'ausgeschlossen_von = 0',
              'ausgeschlossen_von = %d', 'user' ],
            [ 'lsv07i_bestzeiten', 'importiert_von = 0', 'importiert_von = %d', 'user' ],
            [ 'lsv07i_refl_bogen', 'erstellt_von = 0', 'erstellt_von = %d', 'user' ],
            [ 'lsv07i_mb_eintrag', 'angelegt_von = 0', 'angelegt_von = %d', 'user' ],
            [ 'lsv07i_mb_anwesenheit', 'geaendert_von = 0', 'geaendert_von = %d', 'user' ],
        ];
        $anonymisiert = 0;
        foreach ( $anonym as $a ) {
            list( $tabelle, $setzen, $wo, $art ) = $a;
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            if ( empty( $k[ $art ] ) ) continue;
            $n = $wpdb->query( $wpdb->prepare(
                "UPDATE " . self::t( $tabelle ) . " SET $setzen WHERE $wo", (int) $k[ $art ] ) );
            if ( $n ) $anonymisiert += (int) $n;
        }
        if ( $anonymisiert ) {
            $weg += $anonymisiert;
            $meldungen[] = 'Nachrichten, erfasste Anwesenheiten, Trainingspläne und Ähnliches '
                         . 'bleiben für die übrigen Beteiligten erhalten; der Personenbezug '
                         . 'wurde entfernt.';
        }

        /* 3. Die Abrechnungen: Buchungsbelege, sie bleiben. */
        if ( $k['trainer'] && self::tabelle_da( 'lsv07i_abrechnung' ) ) {
            $anzahl = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::t( 'lsv07i_abrechnung' ) . "
                  WHERE trainer_id = %d", $k['trainer'] ) );
            if ( $anzahl ) {
                $bleibt += $anzahl;
                $meldungen[] = sprintf(
                    '%d Abrechnungen bleiben erhalten. Sie sind Buchungsbelege: § 147 AO und '
                    . '§ 257 HGB verlangen zehn Jahre Aufbewahrung, Art. 17 Abs. 3 lit. b DSGVO '
                    . 'nimmt das vom Löschanspruch aus. Nach Ablauf der Frist sind sie zu '
                    . 'löschen.', $anzahl );
            }
            /* Die Bankverbindung gehört nicht zum Beleg — was eine bezahlte
               Abrechnung ausweist, steht in ihr selbst. */
            if ( self::tabelle_da( 'lsv07i_trainer_stammdaten' ) ) {
                $n = $wpdb->query( $wpdb->prepare(
                    "UPDATE " . self::t( 'lsv07i_trainer_stammdaten' ) . "
                        SET iban = '', bic = '', strasse = '', plz = '', ort = ''
                      WHERE trainer_id = %d", $k['trainer'] ) );
                if ( $n ) {
                    $weg += (int) $n;
                    $meldungen[] = 'Bankverbindung und Anschrift der Abrechnungs-Stammdaten '
                                 . 'wurden geleert.';
                }
            }
        }

        /* 4. Die Stammdaten selbst. Der Eintrag bleibt stehen, wo er noch
              gebraucht wird — aber ohne Namen und ohne Adresse. */
        $stamm = [
            [ 'lsv07i_trainer',      'trainer',
              "name = 'Gelöschtes Konto', email = '', wp_user_id = 0, aktiv = 0" ],
            [ 'lsv07i_tri_trainer',  'tri_trainer',
              "name = 'Gelöschtes Konto', email = '', wp_user_id = 0, aktiv = 0" ],
            [ 'lsv07i_fit_trainer',  'fit_trainer',
              "name = 'Gelöschtes Konto', email = '', wp_user_id = 0, aktiv = 0" ],
            [ 'lsv07i_tri_sportler', 'tri',
              "first_name = 'Gelöscht', last_name = '', email = '', birth_date = NULL, notes = '', aktiv = 0" ],
            [ 'lsv07i_fit_sportler', 'fit',
              "first_name = 'Gelöscht', last_name = '', email = '', birth_date = NULL, notes = '', aktiv = 0" ],
            [ 'mv_swimmers',         'swimmer',
              "first_name = 'Gelöscht', last_name = '', email = '', birth_date = NULL, "
              . "dsv_id = NULL, notes = '', attest_expires = NULL, active = 0" ],
            [ 'lsv07i_personen',     'person',
              "vorname = 'Gelöscht', nachname = '', email = '', telefon = '', "
              . "geburtsdatum = NULL, dsv_id = '', mitgliedsnummer = '', notes = '', "
              . "wp_user_id = 0, aktiv = 0" ],
        ];
        foreach ( $stamm as $s ) {
            list( $tabelle, $art, $setzen ) = $s;
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            if ( empty( $k[ $art ] ) ) continue;
            $n = $wpdb->query( $wpdb->prepare(
                "UPDATE " . self::t( $tabelle ) . " SET $setzen WHERE id = %d", (int) $k[ $art ] ) );
            if ( $n ) $weg += (int) $n;
        }
        $meldungen[] = 'Name, Geburtsdatum, Kontaktdaten und Notizen wurden aus den '
                     . 'Stammdaten entfernt. Die Zeile selbst bleibt ohne Personenbezug '
                     . 'stehen, damit Anwesenheitslisten und Statistiken früherer Jahre '
                     . 'nicht auseinanderfallen.';

        return [
            'items_removed'  => $weg > 0,
            'items_retained' => $bleibt > 0,
            'messages'       => $meldungen,
            'done'           => true,
        ];
    }

    /**
     * Die hinterlegten Dateien eines Schwimmers löschen, Platte inklusive.
     *
     * Gelöscht wird über `LSV07I_Schwimmer_Dateien::delete()` und nicht mit
     * einem eigenen Pfad: Dort steht schon, wo eine Datei liegt und wie ihr
     * Name zu behandeln ist. Ein zweiter Löschweg daneben liefe beim
     * nächsten Umbau des Ordners ins Leere — und niemand merkte es, denn
     * die Zeile wäre ja weg.
     */
    private static function dateien_weg( $schwimmer_id ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'lsv07i_schwimmer_dateien' ) . "
              WHERE schwimmer_id = %d", (int) $schwimmer_id ) );
        $n = 0;
        foreach ( $ids as $id ) {
            if ( class_exists( 'LSV07I_Schwimmer_Dateien' ) ) {
                if ( LSV07I_Schwimmer_Dateien::delete( (int) $id ) ) $n++;
                continue;
            }
            /* Ohne die Klasse bleibt nur die Zeile — besser als nichts, und
               es wird gesagt, dass die Datei stehen bleiben kann. */
            $wpdb->delete( self::t( 'lsv07i_schwimmer_dateien' ), [ 'id' => (int) $id ], [ '%d' ] );
            $n++;
        }
        return $n;
    }

    // ── Vorschlag für die Datenschutzerklärung ───────────────────────────

    public static function erklaerung_vorschlagen() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) return;

        $t  = '<h2>Interner Bereich des Vereins</h2>';
        $t .= '<p>Für den Trainings- und Wettkampfbetrieb werden gespeichert: Name, '
            . 'Geburtsdatum, DSV-ID, Mannschaftszugehörigkeit, Anwesenheiten, Bestzeiten, '
            . 'Wettkampfmeldungen sowie Kontaktdaten der Erziehungsberechtigten '
            . '(Name, Telefon, E-Mail).</p>';
        $t .= '<p>Hinterlegte Dateien — insbesondere ärztliche Atteste — liegen ausserhalb '
            . 'des Webverzeichnisses und sind nur über eine Rechteprüfung erreichbar. '
            . 'Es handelt sich dabei um Gesundheitsdaten im Sinne des Art. 9 DSGVO; sie '
            . 'werden ausschliesslich zum Nachweis der Sporttauglichkeit verarbeitet.</p>';
        $t .= '<p>Für Trainerinnen und Trainer kommen Bankverbindung und Anschrift hinzu, '
            . 'soweit sie abrechnen.</p>';
        $t .= '<p>Nachrichten und Aushänge im internen Bereich werden gespeichert; jeder '
            . 'Vorgang wird protokolliert (Konto, Aktion, Zeitpunkt, gekürzte IP-Adresse).</p>';
        $t .= '<p><strong>Aufbewahrung:</strong> Abrechnungen sind Buchungsbelege und werden '
            . 'zehn Jahre aufbewahrt (§ 147 AO, § 257 HGB). Trainings- und Wettkampfdaten '
            . 'werden auf Wunsch gelöscht; bei zurückliegenden Anwesenheiten und Statistiken '
            . 'wird der Personenbezug entfernt, damit die Listen nicht auseinanderfallen.</p>';
        $t .= '<p>Auskunft und Löschung laufen über die Werkzeuge von WordPress unter '
            . '„Personenbezogene Daten exportieren" bzw. „löschen".</p>';

        wp_add_privacy_policy_content( self::NAME, wp_kses_post( $t ) );
    }
}
