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
 * veraltete Auskunft ist eine unvollständige. Darum wird für Zeilen, die
 * die Person SELBST beschreiben, **jede Spalte ausgegeben**, beschriftet
 * über ein gemeinsames Wörterbuch und sonst mit dem Spaltennamen.
 * Vollständigkeit geht vor schöner Beschriftung: Art. 15 verlangt die
 * Daten, nicht die Formulierung.
 *
 * **Aber nur die eigenen Daten.** Viele Zeilen hängen an einer Person nur,
 * weil sie dort als Bearbeiter steht: Sie hat eine Bestzeit importiert, ein
 * Attest hochgeladen, jemanden zu einem Gespräch hinzugefügt. Der Inhalt
 * solcher Zeilen gehört einer ANDEREN Person — deren Zeit, deren Attest,
 * deren Adresse. Art. 15 Abs. 4 DSGVO verbietet, mit der Auskunft an den
 * einen die Rechte des anderen zu beeinträchtigen. Darum unterscheidet
 * jede Quelle zwei Arten der Zuordnung:
 *
 *  - `eigen`: Die Zeile beschreibt die Person (ihr Stammsatz, ihre
 *    Anwesenheit, ihre Nachricht). Alle Spalten.
 *  - `neben`: Die Person steht nur als Bearbeiter/Ersteller/Empfänger
 *    darin. Dann nur die Spalten aus `zeigen` — eine Positivliste, damit
 *    ein später hinzugefügtes Feld nicht von selbst in fremde Auskünfte
 *    wandert.
 *
 * Zusätzlich nennt `personen` die Spalten, die eine Person bezeichnen
 * (Konto-Nummer, Name, Adresse). Sie erscheinen nur, wenn sie die
 * anfragende Person meinen; sonst steht dort „(andere Person)". So bleibt
 * sichtbar, DASS jemand anderes beteiligt war, aber nicht WER.
 *
 * Ausgenommen sind immer **Geheimnisse**: Schlüssel, Token und Hashes. Sie
 * sind keine Auskunft über eine Person, sondern Zugangsmittel — in einer
 * Exportdatei wären sie ein Risiko und kein Nutzen.
 *
 * **Eine Person kann hier viele Kennungen haben**: ein WordPress-Konto, eine
 * Zeile in der Personentabelle, einen oder (aus Altdaten) mehrere
 * Trainer-, Schwimmer-, Triathlon- oder Fitness-Einträge, Einträge als
 * Kontaktperson. Alle werden aus der E-Mail-Adresse aufgelöst — und zwar
 * ALLE, nicht nur der erste Treffer, sonst blieben Doppel-Einträge in
 * Auskunft und Löschung unbemerkt liegen.
 */
class LSV07I_Privacy {

    const SCHLUESSEL = 'lsv07-intern';
    const NAME       = 'LSV07 Interner Bereich';
    const PRO_SEITE  = 200;

    /** Platzhalter für eine fremde Person in einer Auskunft. */
    const ANDERE = '(andere Person)';

    /** Ersatzname in anonymisierten Zeilen, die für andere stehen bleiben. */
    const GELOESCHT = 'Gelöschtes Konto';

    /**
     * Tabellen ohne Personenbezug.
     *
     * Eine Prüfung hält diese Liste gegen die angebundenen Tabellen: Was
     * weder hier noch dort steht, fehlt in der Auskunft — und das fiele
     * sonst niemandem auf.
     */
    const OHNE_PERSON = [
        'lsv07_gruppen',                  // Name einer Mannschaft
        'lsv07i_config',                  // Einstellungen des Vereins (darin auch email_imap_pass — nie exportiert)
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

    /**
     * Spalten, die nie in eine Exportdatei gehören.
     *
     * Abgeglichen mit allen CREATE TABLE des Plugins: Token der Bögen, der
     * Anhänge und der Antwort-Mails, der Schlüssel-Hash der Bögen, der
     * IP-Hash der Sperre. `gespeichert_als` ist der zufällige Dateiname auf
     * der Platte — ein Ablageort, keine Angabe über die Person; der echte
     * Dateiname steht in `dateiname`. Das IMAP-Passwort liegt in
     * lsv07i_config und damit ohnehin ausserhalb jeder Auskunft; es steht
     * hier trotzdem, falls es je in eine Personentabelle wandert.
     *
     * Zusätzlich greift das Muster in geheim(): Eine künftige Spalte
     * `irgendwas_token` soll nicht erst auf diese Liste warten müssen.
     */
    const GEHEIM = [
        'token', 'reply_token', 'schluessel_hash', 'code_hash', 'geheim_hash',
        'ip_hash', 'rights_json', 'fragen_snapshot', 'gespeichert_als',
        'email_imap_pass', 'passwort', 'password', 'user_pass',
        'user_activation_key', 'session_tokens',
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

    /** Arten von Kennungen, die als Liste von Nummern geführt werden. */
    const ARTEN = [ 'user', 'person', 'trainer', 'swimmer', 'tri', 'tri_trainer',
                    'fit', 'fit_trainer', 'kontakt' ];

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

    /** Geheimnis? Liste plus Muster, siehe GEHEIM. */
    private static function geheim( $spalte ) {
        if ( in_array( $spalte, self::GEHEIM, true ) ) return true;
        return (bool) preg_match( '/(^|_)(token|hash|pass|passwort|password|secret|geheim)(_|$)/', $spalte );
    }

    // ── Wen meint diese E-Mail? ──────────────────────────────────────────

    /**
     * Alle Kennungen hinter einer Adresse.
     *
     * Jede Art ist eine LISTE von Nummern (leer = gibt es nicht). Früher
     * war es je Tabelle der erste Treffer — ein zweiter Trainer-Eintrag
     * derselben Person (das kommt aus Altdaten vor) fiel dann aus Auskunft
     * und Löschung heraus, ohne dass es jemand merkte. Die Adresse selbst
     * bleibt als `email` erhalten: Manche Tabellen führen nur sie.
     */
    public static function kennungen( $email ) {
        global $wpdb;
        $email = trim( (string) $email );
        $k = array_fill_keys( self::ARTEN, [] );
        $k['email'] = $email;
        if ( $email === '' ) return $k;

        $user = get_user_by( 'email', $email );
        if ( $user ) $k['user'] = [ (int) $user->ID ];

        /* Jede Tabelle nennt ihre Person über das WordPress-Konto oder über
           eine eigene Adresse (beim Trainer auch die private und die
           geschäftliche). Alles wird gesucht — wer sein WordPress-Konto unter
           einer anderen Adresse führt als seinen Eintrag im Verein, ist
           trotzdem dieselbe Person. Das Konto wird dabei nie aus einer
           Vereinstabelle übernommen: Bestätigt hat WordPress nur die Adresse,
           und ein vertippter Eintrag soll nicht das Konto eines anderen
           auskunfts- oder löschbar machen. */
        $suchen = [
            'person'      => [ 'lsv07i_personen',     true,  [ 'email' ] ],
            'trainer'     => [ 'lsv07i_trainer',      true,  [ 'email', 'email_privat', 'email_geschaeft' ] ],
            'swimmer'     => [ 'mv_swimmers',         false, [ 'email' ] ],
            'tri'         => [ 'lsv07i_tri_sportler', false, [ 'email' ] ],
            'tri_trainer' => [ 'lsv07i_tri_trainer',  true,  [ 'email' ] ],
            'fit'         => [ 'lsv07i_fit_sportler', false, [ 'email' ] ],
            'fit_trainer' => [ 'lsv07i_fit_trainer',  true,  [ 'email' ] ],
            'kontakt'     => [ 'lsv07i_kontakte',     false, [ 'email' ] ],
        ];
        foreach ( $suchen as $art => $was ) {
            list( $tabelle, $hat_konto, $adressen ) = $was;
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            $da = self::spalten( $tabelle );
            $wo = [];
            foreach ( $adressen as $sp ) {
                if ( isset( $da[ $sp ] ) ) $wo[] = $wpdb->prepare( "$sp = %s", $email );
            }
            if ( $hat_konto && $k['user'] && isset( $da['wp_user_id'] ) ) {
                $wo[] = 'wp_user_id IN (' . self::liste( $k['user'] ) . ')';
            }
            if ( ! $wo ) continue;
            $k[ $art ] = array_map( 'intval', (array) $wpdb->get_col(
                "SELECT id FROM " . self::t( $tabelle ) . " WHERE " . implode( ' OR ', $wo ) . " ORDER BY id ASC" ) );
        }

        /* Der Personen-Eintrag kennt die Einträge, aus denen er übernommen
           wurde. Steht dort die bestätigte Adresse, gehören auch diese zur
           Person — selbst wenn der alte Eintrag selbst keine Adresse führt. */
        if ( $k['person'] && self::tabelle_da( 'lsv07i_personen' ) ) {
            $da = self::spalten( 'lsv07i_personen' );
            foreach ( [ 'legacy_swimmer_id' => 'swimmer', 'legacy_trainer_id' => 'trainer',
                        'legacy_tri_sportler_id' => 'tri', 'legacy_tri_trainer_id' => 'tri_trainer' ] as $sp => $art ) {
                if ( ! isset( $da[ $sp ] ) ) continue;
                $ids = $wpdb->get_col( "SELECT $sp FROM " . self::t( 'lsv07i_personen' )
                    . " WHERE id IN (" . self::liste( $k['person'] ) . ") AND $sp > 0" );
                $k[ $art ] = array_values( array_unique( array_merge( $k[ $art ], array_map( 'intval', $ids ) ) ) );
            }
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
     * Die Spalten einer Tabelle: Name → [ darf NULL, Typ ].
     *
     * Viele Spalten kommen erst mit einer späteren Version dazu
     * (display_name, email_privat …). Die Löschung setzt nur, was es gibt —
     * ein UPDATE auf eine fehlende Spalte bräche die ganze Anweisung ab, und
     * dann bliebe auch alles andere in dieser Zeile stehen.
     */
    private static function spalten( $name ) {
        global $wpdb;
        static $bekannt = [];
        if ( isset( $bekannt[ $name ] ) ) return $bekannt[ $name ];
        $raus = [];
        if ( self::tabelle_da( $name ) ) {
            foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::t( $name ), ARRAY_A ) as $s ) {
                $raus[ $s['Field'] ] = [ 'null' => ( $s['Null'] === 'YES' ), 'typ' => strtolower( $s['Type'] ) ];
            }
        }
        return $bekannt[ $name ] = $raus;
    }

    /** Nummern für ein IN (…) — immer ganzzahlig, nie leer. */
    private static function liste( $ids ) {
        $ids = array_filter( array_map( 'intval', (array) $ids ) );
        return $ids ? implode( ',', $ids ) : '0';
    }

    /**
     * Hat irgendeine Kennung getroffen?
     *
     * Nicht jede Person hat hier eine Nummer. Eine Kontaktperson —
     * typischerweise ein Elternteil — steht nur mit ihrer Adresse in der
     * Mailwarteschlange oder in einem Gespräch. Fragt sie nach Auskunft,
     * wäre „zu dieser Adresse ist nichts gespeichert" schlicht falsch. Darum
     * werden die Tabellen, die nur eine Adresse führen, ebenfalls befragt.
     */
    private static function jemand( $k ) {
        foreach ( self::ARTEN as $art ) {
            if ( ! empty( $k[ $art ] ) ) return true;
        }
        if ( $k['email'] === '' ) return false;

        global $wpdb;
        foreach ( [ 'lsv07i_email_queue' => 'empfaenger',
                    'lsv07i_konv_msg' => 'von_email',
                    'lsv07i_konv_teilnehmer' => 'teilnehmer_email',
                    'lsv07i_wettkampf_erinnerung' => 'email',
                    'lsv07i_wettkampf_mail_empfaenger' => 'email' ] as $tabelle => $spalte ) {
            if ( ! self::tabelle_da( $tabelle ) ) continue;
            $da = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::t( $tabelle ) . " WHERE $spalte = %s", $k['email'] ) );
            if ( $da ) return true;
        }
        return false;
    }

    // ── Die Quellen ──────────────────────────────────────────────────────

    /**
     * Jede Tabelle mit Personenbezug.
     *
     * - `eigen` / `neben`: Teilbedingungen (siehe Kopf der Klasse). Jede
     *   nennt eine Spalte, die Art der Kennung und — wo eine Tabelle mehrere
     *   Arten in einer Spalte führt — die dazugehörige Typspalte und den Typ.
     * - `zeigen`: Spalten, die in einer `neben`-Zeile erscheinen dürfen
     *   (`id` immer). Was hier fehlt, bleibt in fremden Zeilen weg.
     * - `personen`: Spalte → Bedingungen, unter denen sie die anfragende
     *   Person meint. Trifft keine, steht „(andere Person)" darin; `null`
     *   heisst: nie ausgeben. Kurzform: 'spalte' => 'user'.
     */
    private static function quellen() {
        $teilnehmer = [ [ 'teilnehmer_id', 'swimmer', 'teilnehmer_typ', 'schwimmer' ],
                        [ 'teilnehmer_id', 'trainer', 'teilnehmer_typ', 'trainer' ],
                        [ 'teilnehmer_id', 'user',    'teilnehmer_typ', 'user' ] ];
        $konv_ich   = [ [ 'teilnehmer_id', 'user',    'teilnehmer_typ', 'user' ],
                        [ 'teilnehmer_id', 'kontakt', 'teilnehmer_typ', 'kontakt' ],
                        [ 'teilnehmer_email', 'email' ] ];
        $msg_ich    = [ [ 'von_id', 'user',    'von_typ', 'user' ],
                        [ 'von_id', 'kontakt', 'von_typ', 'kontakt' ],
                        [ 'von_email', 'email' ] ];
        /* Ein Protokolleintrag bezieht sich oft auf eine andere Person
           („Schwimmer X bearbeitet"). Name, Nummer und Einzelheiten des Ziels
           erscheinen nur, wenn das Ziel keine Person ist oder die anfragende
           Person selbst. */
        $ziel       = [ [ 'ziel_typ', 'ausser', [ 'schwimmer', 'trainer', 'user', 'person', 'akte', 'datei' ] ],
                        [ 'ziel_id', 'swimmer', 'ziel_typ', 'schwimmer' ],
                        [ 'ziel_id', 'trainer', 'ziel_typ', 'trainer' ],
                        [ 'ziel_id', 'user',    'ziel_typ', 'user' ],
                        [ 'ziel_id', 'person',  'ziel_typ', 'person' ] ];

        $q = function ( $tabelle, $titel, $eigen, $neben = [], $zeigen = [], $personen = [] ) {
            return compact( 'tabelle', 'titel', 'eigen', 'neben', 'zeigen', 'personen' );
        };

        return [
            // ── Stammdaten ──────────────────────────────────────────────
            $q( 'lsv07i_personen',      'Personenverzeichnis',            [ [ 'id', 'person' ] ] ),
            $q( 'lsv07i_personen_sparten_rolle', 'Rollen je Sparte',      [ [ 'person_id', 'person' ] ] ),
            $q( 'lsv07i_personen_mannschaft', 'Zuordnung zu Mannschaften', [ [ 'person_id', 'person' ] ] ),
            $q( 'lsv07i_personen_migration_log', 'Übernahme ins Personenverzeichnis',
                                                                         [ [ 'person_id', 'person' ] ] ),
            $q( 'mv_swimmers',          'Schwimmerdaten',                 [ [ 'id', 'swimmer' ] ] ),
            /* Wer Schwimmer ist, bekommt seine Kontaktpersonen mit: Sie sind
               Teil seines Stammblatts und wurden für ihn angegeben. Wer
               Kontaktperson ist, bekommt seinen Eintrag — die Nummer des
               Kindes dahinter aber nur als „andere Person". */
            $q( 'lsv07i_kontakte',      'Kontaktpersonen',                [ [ 'swimmer_id', 'swimmer' ], [ 'email', 'email' ] ],
                [], [], [ 'swimmer_id' => 'swimmer' ] ),
            $q( 'lsv07i_swimmer_gruppen', 'Zuordnung zu Mannschaften',    [ [ 'swimmer_id', 'swimmer' ] ] ),
            $q( 'lsv07i_trainer',       'Trainerprofil',                  [ [ 'id', 'trainer' ] ] ),
            $q( 'lsv07i_trainer_mannschaft', 'Betreute Mannschaften',     [ [ 'trainer_id', 'trainer' ] ] ),
            $q( 'lsv07i_user_rechte',   'Zugewiesene Rechte',             [ [ 'user_id', 'user' ] ],
                [ [ 'granted_by', 'user' ] ], [ 'recht', 'granted_at' ],
                [ 'user_id' => 'user', 'granted_by' => 'user' ] ),
            $q( 'lsv07i_rechte_templates', 'Angelegte Rechtevorlagen',    [ [ 'erstellt_von', 'user' ] ] ),

            // ── Abrechnung (Aufbewahrungspflicht) ───────────────────────
            $q( 'lsv07i_trainer_stammdaten', 'Abrechnung: Stammdaten',    [ [ 'trainer_id', 'trainer' ] ] ),
            $q( 'lsv07i_abrechnung',    'Abrechnung: Quartalsabrechnungen', [ [ 'trainer_id', 'trainer' ] ],
                [], [], [ 'archiviert_von' => 'user' ] ),
            $q( 'lsv07i_abr_trainingstage', 'Abrechnung: Trainingstage',  [ [ 'abrechnung_id', 'abrechnung' ] ] ),
            $q( 'lsv07i_abr_wettkampf', 'Abrechnung: Wettkämpfe',         [ [ 'abrechnung_id', 'abrechnung' ] ] ),
            $q( 'lsv07i_abr_kilometer', 'Abrechnung: Fahrten',            [ [ 'abrechnung_id', 'abrechnung' ] ] ),
            $q( 'lsv07i_abr_sonder',    'Abrechnung: Sonderposten',       [ [ 'abrechnung_id', 'abrechnung' ] ] ),

            // ── Training und Anwesenheit ────────────────────────────────
            /* Die Sitzung selbst nennt keine Schwimmer — wer anwesend war,
               steht in den Einträgen, und davon gehen nur die eigenen mit. */
            $q( 'lsv07i_anwesenheit',   'Erfasste Anwesenheiten',         [ [ 'erstellt_von', 'user' ] ] ),
            $q( 'lsv07i_anwesenheit_eintraege', 'Eigene Anwesenheit',     $teilnehmer ),
            $q( 'lsv07i_anwesenheit_ausschluss', 'Von der Abrechnung ausgenommen',
                [ [ 'trainer_id', 'trainer' ] ], [ [ 'ausgeschlossen_von', 'user' ] ], [ 'slot_id' ],
                [ 'trainer_id' => 'trainer', 'ausgeschlossen_von' => 'user' ] ),
            $q( 'lsv07i_springer',      'Als Springer eingetragen',       [ [ 'trainer_id', 'trainer' ] ] ),
            $q( 'lsv07i_bestzeiten',    'Bestzeiten',                     [ [ 'swimmer_id', 'swimmer' ] ],
                [ [ 'importiert_von', 'user' ] ], [ 'mannschaft_id', 'importiert_am' ],
                [ 'swimmer_id' => 'swimmer', 'swimmer_name' => [ [ 'swimmer_id', 'swimmer' ] ],
                  'importiert_von' => 'user' ] ),
            /* Ein Attest ist ein Gesundheitsdatum (Art. 9). Wer es für einen
               anderen hochgeladen hat, erfährt nur, dass und wann. */
            $q( 'lsv07i_schwimmer_dateien', 'Hinterlegte Dateien',        [ [ 'schwimmer_id', 'swimmer' ] ],
                [ [ 'hochgeladen_von', 'user' ] ], [ 'hochgeladen_am' ],
                [ 'schwimmer_id' => 'swimmer', 'hochgeladen_von' => 'user' ] ),

            // ── Mannschaftsbuch ─────────────────────────────────────────
            /* Dokumentation und Bahnenbelegung eines Tages nennen die
               Schwimmer der Mannschaft. Wer den Tag angelegt hat, bekommt
               den Tag, nicht die Namen darin. */
            $q( 'lsv07i_mb_tag',        'Mannschaftsbuch: angelegte Tage', [],
                [ [ 'angelegt_von', 'user' ] ],
                [ 'mannschaft_id', 'datum', 'anwesenheit_id', 'angelegt_am', 'geaendert_am' ],
                [ 'angelegt_von' => 'user' ] ),
            $q( 'lsv07i_mb_eintrag',    'Mannschaftsbuch: Einträge',      [ [ 'schwimmer_id', 'swimmer' ] ],
                [ [ 'angelegt_von', 'user' ] ],
                [ 'tag_id', 'mannschaft_id', 'art', 'kategorie', 'erledigt', 'angelegt_am' ],
                [ 'schwimmer_id' => 'swimmer', 'angelegt_von' => 'user' ] ),
            $q( 'lsv07i_mb_anwesenheit', 'Mannschaftsbuch: Anwesenheit',  [ [ 'schwimmer_id', 'swimmer' ] ],
                [ [ 'geaendert_von', 'user' ] ], [ 'tag_id', 'geaendert_am' ],
                [ 'schwimmer_id' => 'swimmer', 'geaendert_von' => 'user' ] ),

            // ── Wettkämpfe und Meldungen ────────────────────────────────
            $q( 'lsv07i_wettkampf',     'Wettkämpfe (angelegt oder freigegeben)',
                [ [ 'angelegt_von', 'user' ], [ 'freigegeben_von', 'user' ] ], [], [],
                [ 'angelegt_von' => 'user', 'freigegeben_von' => 'user' ] ),
            $q( 'lsv07i_wettkampf_anwesenheit', 'Wettkampf: erfasste Anwesenheit',
                [ [ 'erstellt_von', 'user' ] ] ),
            $q( 'lsv07i_wettkampf_anw_eintraege', 'Wettkampf: eigene Anwesenheit', $teilnehmer ),
            $q( 'lsv07i_wettkampf_dokument', 'Wettkampf: hochgeladene Dateien',
                [ [ 'hochgeladen_von', 'user' ] ] ),
            $q( 'lsv07i_wettkampf_link', 'Wettkampf: hinzugefügte Verweise',
                [ [ 'hinzugefuegt_von', 'user' ] ] ),
            $q( 'lsv07i_wettkampf_erinnerung', 'Wettkampf: Erinnerungsadressen',
                [ [ 'email', 'email' ] ], [ [ 'hinzugefuegt_von', 'user' ] ],
                [ 'wettkampf_id', 'hinzugefuegt_am' ],
                [ 'email' => 'email', 'hinzugefuegt_von' => 'user' ] ),
            $q( 'lsv07i_wettkampf_mail_empfaenger', 'Wettkampf: Mailempfänger',
                [ [ 'email', 'email' ] ], [ [ 'hinzugefuegt_von', 'user' ] ],
                [ 'freigabe_anfrage', 'erinnerung_meldeergebnis', 'erinnerung_protokoll', 'hinzugefuegt_am' ],
                [ 'email' => 'email', 'hinzugefuegt_von' => 'user' ] ),
            $q( 'lsv07i_meldung',       'Meldungen',                      [ [ 'angelegt_von', 'user' ] ] ),
            $q( 'lsv07i_meldung_start', 'Gemeldete Starts',               [ [ 'schwimmer_id', 'swimmer' ] ] ),
            $q( 'lsv07i_abzeichen_vergabe', 'Erhaltene Abzeichen',        [ [ 'swimmer_id', 'swimmer' ] ],
                [ [ 'vergeben_von', 'user' ] ] ),
            $q( 'lsv07i_abzeichen',     'Angelegte Abzeichen',            [ [ 'erstellt_von', 'user' ] ] ),

            // ── Trainingspläne ──────────────────────────────────────────
            $q( 'lsv07i_trainingsplan', 'Trainingspläne',                 [ [ 'ersteller_id', 'user' ] ] ),
            $q( 'lsv07i_trainingsplan_vorlage', 'Vorlagen für Trainingspläne',
                [ [ 'ersteller_id', 'user' ] ] ),
            $q( 'lsv07i_tp_datei',      'Trainingsplan: hochgeladene Dateien',
                [ [ 'hochgeladen_von', 'user' ] ] ),

            // ── Reflexion ───────────────────────────────────────────────
            $q( 'lsv07i_refl_fragen',   'Angelegte Reflexionsfragen',     [ [ 'erstellt_von', 'user' ] ] ),
            $q( 'lsv07i_refl_bogen',    'Reflexionsbögen',                [ [ 'swimmer_id', 'swimmer' ] ],
                [ [ 'erstellt_von', 'user' ] ],
                [ 'saison_id', 'mannschaft_id', 'status', 'erstellt_am', 'abgegeben_am', 'archiviert_am' ],
                [ 'swimmer_id' => 'swimmer', 'swimmer_name' => [ [ 'swimmer_id', 'swimmer' ] ],
                  'erstellt_von' => 'user' ] ),
            /* Nur die Antworten aus den EIGENEN Bögen. Wer Bögen für eine
               Mannschaft angelegt hat, bekäme sonst die Antworten aller
               Schwimmer darin. */
            $q( 'lsv07i_refl_antwort',  'Antworten in Reflexionsbögen',   [ [ 'bogen_id', 'bogen' ] ] ),

            // ── Nachrichten, Tickets, Protokoll ─────────────────────────
            $q( 'lsv07i_konv',          'Angelegte Unterhaltungen',       [ [ 'erstellt_von', 'user' ] ] ),
            /* Die eigene Teilnahme ja, die Teilnehmerliste nein: Wen jemand
               zu einem Gespräch hinzugefügt hat, erscheint als andere Person. */
            $q( 'lsv07i_konv_teilnehmer', 'Teilnahme an Unterhaltungen',  $konv_ich,
                [ [ 'hinzugefuegt_von', 'user' ] ], [ 'konv_id', 'hinzugefuegt_am' ],
                [ 'teilnehmer_id' => array_slice( $konv_ich, 0, 2 ),
                  'teilnehmer_email' => 'email',
                  'teilnehmer_name' => $konv_ich,
                  'hinzugefuegt_von' => 'user' ] ),
            /* Nur die eigenen Nachrichten. Was andere im selben Gespräch
               geschrieben haben, ist deren Text. */
            $q( 'lsv07i_konv_msg',      'Geschriebene Nachrichten',       $msg_ich,
                [ [ 'geloescht_von', 'user' ] ], [ 'konv_id', 'geloescht_am', 'erstellt_am' ],
                [ 'von_id' => array_slice( $msg_ich, 0, 2 ), 'von_email' => 'email',
                  'von_name' => $msg_ich, 'geloescht_von' => 'user' ] ),
            $q( 'lsv07i_konv_anhang',   'Anhänge an eigenen Nachrichten', [ [ 'msg_id', 'msg' ] ] ),
            $q( 'lsv07i_konv_reaktion', 'Reaktionen auf Nachrichten',     [ [ 'user_id', 'user' ] ] ),
            $q( 'lsv07i_email_queue',   'Versandte Benachrichtigungen',   [ [ 'empfaenger', 'email' ] ] ),
            /* Die alte Nachrichtentabelle von vor dem Umstieg auf
               Unterhaltungen. Sie bleibt nach der Übernahme stehen — und
               damit bleibt auch ihr Inhalt auskunftspflichtig. Wie oben: der
               eigene Text ja, der Text des Absenders an einen selbst nein. */
            $q( 'lsv07i_nachrichten',   'Nachrichten (alte Fassung)',     [ [ 'von_user_id', 'user' ] ],
                [ [ 'an_user_id', 'user' ] ], [ 'gelesen', 'gelesen_am', 'erstellt_am' ],
                [ 'von_user_id' => 'user', 'an_user_id' => 'user' ] ),
            $q( 'lsv07i_benachrichtigung', 'Verfasste Aushänge',          [ [ 'ersteller_id', 'user' ] ] ),
            $q( 'lsv07i_benachrichtigung_gelesen', 'Gelesene Aushänge',   [ [ 'user_id', 'user' ] ] ),
            /* Ein zugewiesenes Ticket hat ein anderer geschrieben: Nummer,
               Stand und Zeiten ja, Titel und Beschreibung nicht. */
            $q( 'lsv07i_tickets',       'Tickets',                        [ [ 'ersteller_user_id', 'user' ] ],
                [ [ 'zugewiesen_an', 'user' ] ],
                [ 'nummer', 'kategorie', 'prioritaet', 'status', 'erstellt_am', 'aktualisiert_am', 'geschlossen_am' ],
                [ 'ersteller_user_id' => 'user', 'zugewiesen_an' => 'user' ] ),
            $q( 'lsv07i_ticket_kommentare', 'Kommentare an Tickets',      [ [ 'autor_user_id', 'user' ] ] ),
            $q( 'lsv07i_ticket_anhang', 'Anhänge an Tickets',             [ [ 'hochgeladen_von', 'user' ] ] ),
            $q( 'lsv07i_log',           'Protokoll',                      [ [ 'user_id', 'user' ] ], [], [],
                [ 'ziel_id' => $ziel, 'ziel_name' => $ziel, 'details' => $ziel ] ),
            $q( 'lsv07i_saisons',       'Angelegte Saisons',              [ [ 'erstellt_von', 'user' ] ] ),

            // ── Triathlon ───────────────────────────────────────────────
            $q( 'lsv07i_tri_sportler',  'Triathlon: Sportlerdaten',       [ [ 'id', 'tri' ] ] ),
            $q( 'lsv07i_tri_sportler_gruppe', 'Triathlon: Gruppen',       [ [ 'sportler_id', 'tri' ] ] ),
            $q( 'lsv07i_tri_trainer',   'Triathlon: Trainerprofil',       [ [ 'id', 'tri_trainer' ] ] ),
            $q( 'lsv07i_tri_trainer_gruppe', 'Triathlon: betreute Gruppen', [ [ 'trainer_id', 'tri_trainer' ] ] ),
            $q( 'lsv07i_tri_anwesenheit', 'Triathlon: erfasste Anwesenheit', [ [ 'erstellt_von', 'user' ] ] ),
            $q( 'lsv07i_tri_anwesenheit_eintraege', 'Triathlon: eigene Anwesenheit',
                [ [ 'sportler_id', 'tri' ] ] ),

            // ── Fitness ─────────────────────────────────────────────────
            $q( 'lsv07i_fit_sportler',  'Fitness: Sportlerdaten',         [ [ 'id', 'fit' ] ] ),
            $q( 'lsv07i_fit_sportler_gruppe', 'Fitness: Gruppen',         [ [ 'sportler_id', 'fit' ] ] ),
            $q( 'lsv07i_fit_trainer',   'Fitness: Trainerprofil',         [ [ 'id', 'fit_trainer' ] ] ),
            $q( 'lsv07i_fit_trainer_gruppe', 'Fitness: betreute Gruppen', [ [ 'trainer_id', 'fit_trainer' ] ] ),
            $q( 'lsv07i_fit_anwesenheit', 'Fitness: erfasste Anwesenheit', [ [ 'erstellt_von', 'user' ] ] ),
            $q( 'lsv07i_fit_anwesenheit_eintraege', 'Fitness: eigene Anwesenheit',
                [ [ 'sportler_id', 'fit' ] ] ),
        ];
    }

    /** Die Tabellen, die angebunden sind — für die Abdeckungsprüfung. */
    public static function angebunden() {
        return array_values( array_unique( array_column( self::quellen(), 'tabelle' ) ) );
    }

    /**
     * Die WHERE-Bedingung zu einer Liste von Teilbedingungen. Leer = nichts.
     *
     * Drei Arten von Kennung brauchen eine Unterabfrage, weil die Tabelle
     * die Person nicht selbst führt: Abrechnungsposten hängen an einer
     * Abrechnung, Reflexionsantworten an einem Bogen, Anhänge an einer
     * Nachricht. Sie werden hier aufgelöst statt in jeder Zeile.
     *
     * Dieselbe Funktion bedient Auskunft UND Löschung — so kann die Löschung
     * nicht stillschweigend eine Zeile übergehen, die die Auskunft zeigt.
     */
    private static function bedingung( $wo, $k ) {
        global $wpdb;
        $teile = [];
        foreach ( $wo as $t ) {
            $spalte = $t[0];
            $art    = $t[1];

            if ( $art === 'email' ) {
                if ( $k['email'] === '' ) continue;
                $teil = $wpdb->prepare( "$spalte = %s", $k['email'] );
            } elseif ( $art === 'abrechnung' ) {
                if ( ! $k['trainer'] ) continue;
                $teil = sprintf( '%s IN ( SELECT id FROM %s WHERE trainer_id IN (%s) )',
                    $spalte, self::t( 'lsv07i_abrechnung' ), self::liste( $k['trainer'] ) );
            } elseif ( $art === 'bogen' ) {
                if ( ! $k['swimmer'] ) continue;
                $teil = sprintf( '%s IN ( SELECT id FROM %s WHERE swimmer_id IN (%s) )',
                    $spalte, self::t( 'lsv07i_refl_bogen' ), self::liste( $k['swimmer'] ) );
            } elseif ( $art === 'msg' ) {
                $msg = self::bedingung( [ [ 'von_id', 'user',    'von_typ', 'user' ],
                                          [ 'von_id', 'kontakt', 'von_typ', 'kontakt' ],
                                          [ 'von_email', 'email' ] ], $k );
                if ( $msg === '' ) continue;
                $teil = sprintf( '%s IN ( SELECT id FROM %s WHERE %s )',
                    $spalte, self::t( 'lsv07i_konv_msg' ), $msg );
            } elseif ( in_array( $art, self::ARTEN, true ) ) {
                if ( empty( $k[ $art ] ) ) continue;
                $teil = sprintf( '%s IN (%s)', $spalte, self::liste( $k[ $art ] ) );
            } else {
                continue;   // 'ausser' u. ä. gibt es nur für die Zeilenprüfung
            }

            /* Manche Tabellen führen mehrere Arten in einer Spalte — dann
               zählt die Nummer nur zusammen mit dem passenden Typ. */
            if ( isset( $t[2], $t[3] ) ) {
                $typen = array_map( function ( $v ) use ( $wpdb ) { return $wpdb->prepare( '%s', $v ); },
                                    (array) $t[3] );
                $teil = sprintf( '( %s IN (%s) AND %s )', $t[2], implode( ',', $typen ), $teil );
            }
            $teile[] = $teil;
        }
        return $teile ? '( ' . implode( ' OR ', $teile ) . ' )' : '';
    }

    /** Meint eine Teilbedingung die Person — geprüft an einer gelesenen Zeile? */
    private static function trifft( $z, $t, $k ) {
        $spalte = $t[0];
        $art    = $t[1];
        $wert   = isset( $z[ $spalte ] ) ? (string) $z[ $spalte ] : '';

        if ( $art === 'ausser' ) {
            return ! in_array( $wert, (array) $t[2], true );
        }
        if ( isset( $t[2], $t[3] ) ) {
            if ( ! in_array( (string) ( $z[ $t[2] ] ?? '' ), (array) $t[3], true ) ) return false;
        }
        if ( $art === 'email' ) {
            return $k['email'] !== '' && strcasecmp( trim( $wert ), $k['email'] ) === 0;
        }
        if ( ! in_array( $art, self::ARTEN, true ) ) return false;
        return (int) $wert > 0 && in_array( (int) $wert, $k[ $art ], true );
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

    /** eigen ODER neben, als eine WHERE-Bedingung. */
    private static function alle( $q, $k ) {
        $teile = array_filter( [ self::bedingung( $q['eigen'], $k ), self::bedingung( $q['neben'], $k ) ] );
        return $teile ? '( ' . implode( ' OR ', $teile ) . ' )' : '';
    }

    private static function plan( $k ) {
        global $wpdb;
        $plan = [];
        foreach ( self::quellen() as $i => $q ) {
            if ( ! self::tabelle_da( $q['tabelle'] ) ) continue;
            $bed = self::alle( $q, $k );
            if ( $bed === '' ) continue;
            $anzahl = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::t( $q['tabelle'] ) . " WHERE $bed" );
            for ( $off = 0; $off < $anzahl; $off += self::PRO_SEITE ) {
                $plan[] = [ 'quelle' => $i, 'offset' => $off ];
            }
        }
        return $plan;
    }

    private static function seite_holen( $k, $nr, $offset ) {
        global $wpdb;
        $q   = self::quellen()[ $nr ];
        $bed = self::alle( $q, $k );
        if ( $bed === '' ) return [];
        $eigen = self::bedingung( $q['eigen'], $k );
        if ( $eigen === '' ) $eigen = '0';

        /* Feste Reihenfolge, sonst liefert LIMIT/OFFSET über mehrere Seiten
           womöglich eine Zeile doppelt und eine andere gar nicht. */
        $ordnung = isset( self::spalten( $q['tabelle'] )['id'] ) ? 'id' : '1, 2';
        $zeilen = $wpdb->get_results(
            "SELECT *, ( CASE WHEN $eigen THEN 1 ELSE 0 END ) AS lsv07i_eigen
               FROM " . self::t( $q['tabelle'] ) . "
              WHERE $bed ORDER BY $ordnung
              LIMIT " . (int) self::PRO_SEITE . " OFFSET " . (int) $offset, ARRAY_A );

        $personen = [];
        foreach ( $q['personen'] as $sp => $b ) {
            $personen[ $sp ] = is_string( $b ) ? [ [ $sp, $b ] ] : $b;
        }
        $zeigen = array_merge( [ 'id' ], $q['zeigen'] );

        $raus = [];
        $lfd = (int) $offset;
        foreach ( (array) $zeilen as $z ) {
            $lfd++;
            $ist_eigen = (int) $z['lsv07i_eigen'] === 1;
            unset( $z['lsv07i_eigen'] );
            $daten = [];
            foreach ( $z as $spalte => $wert ) {
                if ( self::geheim( $spalte ) ) continue;
                if ( array_key_exists( $spalte, $personen ) ) {
                    if ( $personen[ $spalte ] === null ) continue;
                    if ( $wert === null || $wert === '' || (string) $wert === '0' ) continue;
                    $treffer = false;
                    foreach ( $personen[ $spalte ] as $b ) {
                        if ( self::trifft( $z, $b, $k ) ) { $treffer = true; break; }
                    }
                    if ( ! $treffer ) $wert = self::ANDERE;
                } elseif ( ! $ist_eigen && ! in_array( $spalte, $zeigen, true ) ) {
                    continue;   // fremde Zeile: nur die Positivliste
                }
                if ( $wert === null || $wert === '' ) continue;
                /* Reflexionsantworten liegen verschlüsselt in der Datenbank.
                   Der Geheimtext wäre keine Auskunft. */
                if ( $q['tabelle'] === 'lsv07i_refl_antwort' && in_array( $spalte, [ 'antwort_text', 'wunschzeit' ], true )
                     && class_exists( 'LSV07I_Reflexion' ) ) {
                    $wert = LSV07I_Reflexion::decrypt( $wert );
                    if ( $wert === '' ) continue;
                }
                $daten[] = [
                    'name'  => self::WOERTER[ $spalte ] ?? $spalte,
                    'value' => wp_strip_all_tags( (string) $wert ),
                ];
            }
            if ( ! $daten ) continue;
            $raus[] = [
                'group_id'    => $q['tabelle'],
                'group_label' => self::NAME . ': ' . $q['titel'],
                'item_id'     => $q['tabelle'] . '-' . ( isset( $z['id'] ) ? (int) $z['id'] : $lfd ),
                'data'        => $daten,
            ];
        }
        return $raus;
    }

    // ── Löschung ─────────────────────────────────────────────────────────

    /** DELETE mit einer fertigen Bedingung. */
    private static function weg( $tabelle, $bed ) {
        global $wpdb;
        if ( $bed === '' || ! self::tabelle_da( $tabelle ) ) return 0;
        $n = $wpdb->query( "DELETE FROM " . self::t( $tabelle ) . " WHERE $bed" );
        return $n ? (int) $n : 0;
    }

    /**
     * UPDATE mit einer fertigen Bedingung — nur auf Spalten, die es gibt.
     *
     * `null` für eine Spalte, die kein NULL kennt, wird zum leeren Wert
     * ihres Typs: Ein abgelehntes UPDATE liesse sonst die ganze Zeile
     * unverändert stehen.
     */
    private static function setzen( $tabelle, array $werte, $bed ) {
        global $wpdb;
        if ( $bed === '' || ! self::tabelle_da( $tabelle ) ) return 0;
        $da = self::spalten( $tabelle );
        $teile = [];
        foreach ( $werte as $spalte => $wert ) {
            if ( ! isset( $da[ $spalte ] ) ) continue;
            if ( $wert === null && ! $da[ $spalte ]['null'] ) {
                $typ  = $da[ $spalte ]['typ'];
                $wert = ( strpos( $typ, 'date' ) === 0 ) ? '0000-00-00'
                      : ( preg_match( '/int|decimal/', $typ ) ? 0 : '' );
            }
            if ( $wert === null )    $teile[] = "$spalte = NULL";
            elseif ( is_int( $wert ) ) $teile[] = $wpdb->prepare( "$spalte = %d", $wert );
            else                       $teile[] = $wpdb->prepare( "$spalte = %s", $wert );
        }
        if ( ! $teile ) return 0;
        $n = $wpdb->query( "UPDATE " . self::t( $tabelle ) . " SET " . implode( ', ', $teile ) . " WHERE $bed" );
        return $n ? (int) $n : 0;
    }

    /** Wurzel der geschützten Uploads, ohne Ordner anzulegen. */
    private static function privat_dir() {
        $up = wp_upload_dir( null, false );
        if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) return '';
        return trailingslashit( $up['basedir'] ) . 'lsv07i-private/';
    }

    /** Eine Datei in einem Unterordner löschen. Nur der reine Dateiname zählt. */
    private static function datei_weg( $unterordner, $name ) {
        $basis = self::privat_dir();
        $name  = basename( (string) $name );
        if ( $basis === '' || $name === '' || $name === '.' || $name === '..' ) return false;
        $pfad = $basis . trim( $unterordner, '/' ) . '/' . $name;
        return is_file( $pfad ) && @unlink( $pfad );
    }

    /**
     * Was gelöscht wird, was anonymisiert wird, was bleibt.
     *
     * Die Abrechnungen sind Buchungsbelege und bleiben (§ 147 AO, § 257 HGB,
     * Art. 17 Abs. 3 lit. b DSGVO) — samt dem Namen am Trainereintrag, denn
     * ohne ihn wiese der Beleg niemanden als Empfänger aus. Alles andere
     * geht. Wo ein Eintrag für ANDERE sinnvoll bleiben muss (eine Nachricht
     * in einer Unterhaltung, eine erfasste Anwesenheit, ein Trainingsplan),
     * wird der Personenbezug entfernt statt der Inhalt gelöscht. Sonst
     * fehlte in der Unterhaltung der halbe Gesprächsverlauf.
     *
     * Alles geschieht in Seite 1 und meldet „fertig". Das ist Absicht: Nach
     * dem ersten Durchlauf ist die Adresse aus den Stammdaten entfernt, eine
     * zweite Seite fände die Person gar nicht mehr. Die Anweisungen arbeiten
     * mengenweise (alle Zeilen aller Kennungen auf einmal), nur die Dateien
     * werden einzeln von der Platte genommen.
     */
    public static function loeschen( $email, $seite = 1 ) {
        $leer = [ 'items_removed' => false, 'items_retained' => false,
                  'messages' => [], 'done' => true ];
        $k = self::kennungen( $email );
        if ( ! self::jemand( $k ) ) return $leer;
        if ( (int) $seite > 1 ) return $leer;

        global $wpdb;
        $weg = 0; $bleibt = 0; $meldungen = [];
        $b = function ( $wo ) use ( $k ) { return self::bedingung( $wo, $k ); };

        /* 0. Dateien zuerst — danach wären die Zeilen mit den Pfaden fort. */
        $dateien = 0;
        foreach ( $k['swimmer'] as $sid ) {
            if ( self::tabelle_da( 'lsv07i_schwimmer_dateien' ) ) $dateien += self::dateien_weg( $sid );
        }
        $dateien += self::anhaenge_weg( $k );
        $dateien += self::ticket_anhaenge_weg( $k );
        $dateien += self::profilbild_weg( $k );
        if ( $dateien ) {
            $weg += $dateien;
            $meldungen[] = sprintf( '%d hinterlegte Dateien (Atteste, Anhänge an eigenen Nachrichten '
                . 'und Tickets, Profilbild) wurden gelöscht, auch vom Dateisystem.', $dateien );
        }

        /* 1. Was ganz weg kann: beschreibt nur die Person selbst oder ihre
              Arbeit mit dem System. Die Reflexionsantworten vor den Bögen —
              ihre Bedingung fragt die Bögen ab. */
        $raus = [
            [ 'lsv07i_user_rechte',              [ [ 'user_id', 'user' ] ] ],
            [ 'lsv07i_konv_reaktion',            [ [ 'user_id', 'user' ] ] ],
            [ 'lsv07i_benachrichtigung_gelesen', [ [ 'user_id', 'user' ] ] ],
            [ 'lsv07i_email_queue',              [ [ 'empfaenger', 'email' ] ] ],
            [ 'lsv07i_wettkampf_erinnerung',     [ [ 'email', 'email' ] ] ],
            [ 'lsv07i_wettkampf_mail_empfaenger',[ [ 'email', 'email' ] ] ],
            /* Auch als Kontaktperson eines fremden Kindes: Die Adresse ist
               die der anfragenden Person. */
            [ 'lsv07i_kontakte',                 [ [ 'swimmer_id', 'swimmer' ], [ 'email', 'email' ] ] ],
            [ 'lsv07i_swimmer_gruppen',          [ [ 'swimmer_id', 'swimmer' ] ] ],
            [ 'lsv07i_bestzeiten',               [ [ 'swimmer_id', 'swimmer' ] ] ],
            [ 'lsv07i_meldung_start',            [ [ 'schwimmer_id', 'swimmer' ] ] ],
            [ 'lsv07i_abzeichen_vergabe',        [ [ 'swimmer_id', 'swimmer' ] ] ],
            [ 'lsv07i_mb_anwesenheit',           [ [ 'schwimmer_id', 'swimmer' ] ] ],
            [ 'lsv07i_mb_eintrag',               [ [ 'schwimmer_id', 'swimmer' ] ] ],
            [ 'lsv07i_refl_antwort',             [ [ 'bogen_id', 'bogen' ] ] ],
            [ 'lsv07i_refl_bogen',               [ [ 'swimmer_id', 'swimmer' ] ] ],
            [ 'lsv07i_trainer_mannschaft',       [ [ 'trainer_id', 'trainer' ] ] ],
            [ 'lsv07i_springer',                 [ [ 'trainer_id', 'trainer' ] ] ],
            [ 'lsv07i_anwesenheit_ausschluss',   [ [ 'trainer_id', 'trainer' ] ] ],
            [ 'lsv07i_anwesenheit_eintraege',    self::quellen_wo( 'lsv07i_anwesenheit_eintraege' ) ],
            [ 'lsv07i_wettkampf_anw_eintraege',  self::quellen_wo( 'lsv07i_wettkampf_anw_eintraege' ) ],
            [ 'lsv07i_personen_sparten_rolle',   [ [ 'person_id', 'person' ] ] ],
            [ 'lsv07i_personen_mannschaft',      [ [ 'person_id', 'person' ] ] ],
            [ 'lsv07i_personen_migration_log',   [ [ 'person_id', 'person' ] ] ],
            [ 'lsv07i_tri_sportler_gruppe',      [ [ 'sportler_id', 'tri' ] ] ],
            [ 'lsv07i_tri_anwesenheit_eintraege',[ [ 'sportler_id', 'tri' ] ] ],
            [ 'lsv07i_tri_trainer_gruppe',       [ [ 'trainer_id', 'tri_trainer' ] ] ],
            [ 'lsv07i_fit_sportler_gruppe',      [ [ 'sportler_id', 'fit' ] ] ],
            [ 'lsv07i_fit_anwesenheit_eintraege',[ [ 'sportler_id', 'fit' ] ] ],
            [ 'lsv07i_fit_trainer_gruppe',       [ [ 'trainer_id', 'fit_trainer' ] ] ],
        ];
        foreach ( $raus as $r ) {
            $weg += self::weg( $r[0], $b( $r[1] ) );
        }

        /* 2. Was für andere stehen bleiben muss: Name raus, Inhalt bleibt.
              Das Protokoll wird nicht gelöscht, sondern anonymisiert: Es ist
              der Nachweis, was im System geschah (Art. 32 DSGVO), und die
              Vorgänge selbst betreffen meist andere Personen. Es läuft nach
              180 Tagen ohnehin ab (LSV07I_Log::cleanup). */
        $u = [ [ 'erstellt_von', 'user' ] ];
        $anonym = [
            [ 'lsv07i_log', [ 'user_id' => 0, 'user_name' => '', 'ip' => null ], [ [ 'user_id', 'user' ] ] ],
            [ 'lsv07i_log', [ 'ziel_name' => 'Gelöschte Person', 'details' => null ],
              [ [ 'ziel_id', 'swimmer', 'ziel_typ', 'schwimmer' ], [ 'ziel_id', 'trainer', 'ziel_typ', 'trainer' ],
                [ 'ziel_id', 'user', 'ziel_typ', 'user' ], [ 'ziel_id', 'person', 'ziel_typ', 'person' ] ] ],
            [ 'lsv07i_konv_msg', [ 'von_name' => self::GELOESCHT, 'von_email' => null, 'von_id' => 0 ],
              self::quellen_wo( 'lsv07i_konv_msg' ) ],
            [ 'lsv07i_konv_msg', [ 'geloescht_von' => null ], [ [ 'geloescht_von', 'user' ] ] ],
            [ 'lsv07i_konv_teilnehmer', [ 'teilnehmer_name' => self::GELOESCHT, 'teilnehmer_email' => null ],
              self::quellen_wo( 'lsv07i_konv_teilnehmer' ) ],
            [ 'lsv07i_konv_teilnehmer', [ 'hinzugefuegt_von' => 0 ], [ [ 'hinzugefuegt_von', 'user' ] ] ],
            [ 'lsv07i_anwesenheit',           [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_tri_anwesenheit',       [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_fit_anwesenheit',       [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_wettkampf_anwesenheit', [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_saisons',               [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_refl_fragen',           [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_refl_bogen',            [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_abzeichen',             [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_abzeichen_vergabe',     [ 'vergeben_von' => 0 ], [ [ 'vergeben_von', 'user' ] ] ],
            [ 'lsv07i_rechte_templates',      [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_konv',                  [ 'erstellt_von' => 0 ], $u ],
            [ 'lsv07i_mb_tag',      [ 'angelegt_von' => 0 ], [ [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07i_mb_eintrag',  [ 'angelegt_von' => 0 ], [ [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07i_mb_anwesenheit', [ 'geaendert_von' => 0 ], [ [ 'geaendert_von', 'user' ] ] ],
            [ 'lsv07i_meldung',     [ 'angelegt_von' => 0 ], [ [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf',   [ 'angelegt_von' => 0 ], [ [ 'angelegt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf',   [ 'freigegeben_von' => null ], [ [ 'freigegeben_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_link',        [ 'hinzugefuegt_von' => 0 ], [ [ 'hinzugefuegt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_erinnerung',  [ 'hinzugefuegt_von' => 0 ], [ [ 'hinzugefuegt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_mail_empfaenger', [ 'hinzugefuegt_von' => 0 ], [ [ 'hinzugefuegt_von', 'user' ] ] ],
            [ 'lsv07i_wettkampf_dokument',    [ 'hochgeladen_von' => 0 ], [ [ 'hochgeladen_von', 'user' ] ] ],
            [ 'lsv07i_tp_datei',              [ 'hochgeladen_von' => 0 ], [ [ 'hochgeladen_von', 'user' ] ] ],
            [ 'lsv07i_schwimmer_dateien',     [ 'hochgeladen_von' => 0 ], [ [ 'hochgeladen_von', 'user' ] ] ],
            [ 'lsv07i_trainingsplan',
              [ 'ersteller_id' => 0, 'ersteller_name' => self::GELOESCHT ], [ [ 'ersteller_id', 'user' ] ] ],
            [ 'lsv07i_trainingsplan_vorlage',
              [ 'ersteller_id' => 0, 'ersteller_name' => self::GELOESCHT ], [ [ 'ersteller_id', 'user' ] ] ],
            [ 'lsv07i_benachrichtigung',
              [ 'ersteller_id' => 0, 'ersteller_name' => self::GELOESCHT ], [ [ 'ersteller_id', 'user' ] ] ],
            [ 'lsv07i_tickets', [ 'ersteller_user_id' => 0 ], [ [ 'ersteller_user_id', 'user' ] ] ],
            [ 'lsv07i_tickets', [ 'zugewiesen_an' => null ],  [ [ 'zugewiesen_an', 'user' ] ] ],
            [ 'lsv07i_ticket_kommentare', [ 'autor_user_id' => 0 ], [ [ 'autor_user_id', 'user' ] ] ],
            [ 'lsv07i_nachrichten', [ 'von_user_id' => 0 ], [ [ 'von_user_id', 'user' ] ] ],
            [ 'lsv07i_nachrichten', [ 'an_user_id' => 0 ],  [ [ 'an_user_id', 'user' ] ] ],
            [ 'lsv07i_user_rechte', [ 'granted_by' => null ], [ [ 'granted_by', 'user' ] ] ],
            [ 'lsv07i_anwesenheit_ausschluss', [ 'ausgeschlossen_von' => 0 ], [ [ 'ausgeschlossen_von', 'user' ] ] ],
            [ 'lsv07i_bestzeiten', [ 'importiert_von' => 0 ], [ [ 'importiert_von', 'user' ] ] ],
        ];
        $anonymisiert = 0;
        foreach ( $anonym as $a ) {
            $anonymisiert += self::setzen( $a[0], $a[1], $b( $a[2] ) );
        }
        if ( $anonymisiert ) {
            $weg += $anonymisiert;
            $meldungen[] = 'Nachrichten, erfasste Anwesenheiten, Trainingspläne, Protokolleinträge und '
                         . 'Ähnliches bleiben für die übrigen Beteiligten erhalten; der Personenbezug '
                         . '(Name, Adresse, Konto, IP-Adresse) wurde entfernt.';
        }

        /* 3. Die Abrechnungen: Buchungsbelege, sie bleiben — mit allem, was
              zum Beleg gehört (Posten, Empfängername). */
        $mit_belegen = [];
        if ( $k['trainer'] && self::tabelle_da( 'lsv07i_abrechnung' ) ) {
            $zeilen = $wpdb->get_results(
                "SELECT trainer_id, COUNT(*) AS n FROM " . self::t( 'lsv07i_abrechnung' ) . "
                  WHERE trainer_id IN (" . self::liste( $k['trainer'] ) . ") GROUP BY trainer_id", ARRAY_A );
            $anzahl = 0;
            foreach ( (array) $zeilen as $z ) {
                $mit_belegen[] = (int) $z['trainer_id'];
                $anzahl += (int) $z['n'];
            }
            if ( $anzahl ) {
                $bleibt += $anzahl;
                $meldungen[] = sprintf(
                    '%d Abrechnungen bleiben samt ihren Posten und dem Namen des Empfängers erhalten. '
                    . 'Sie sind Buchungsbelege: § 147 AO und § 257 HGB verlangen zehn Jahre '
                    . 'Aufbewahrung, Art. 17 Abs. 3 lit. b DSGVO nimmt das vom Löschanspruch aus. '
                    . 'Nach Ablauf der Frist sind sie zu löschen.', $anzahl );
            }
        }
        /* Die Bankverbindung und die Anschrift gehören nicht zum Beleg — was
           eine bezahlte Abrechnung ausweist, steht in ihr selbst. Ohne Belege
           braucht es die Stammdaten gar nicht mehr. */
        if ( $mit_belegen ) {
            $weg += self::setzen( 'lsv07i_trainer_stammdaten',
                [ 'iban' => '', 'bic' => '', 'strasse' => '', 'plz' => '', 'ort' => '' ],
                'trainer_id IN (' . self::liste( $mit_belegen ) . ')' );
        }
        $ohne_belege = array_values( array_diff( $k['trainer'], $mit_belegen ) );
        if ( $ohne_belege ) {
            $weg += self::weg( 'lsv07i_trainer_stammdaten', 'trainer_id IN (' . self::liste( $ohne_belege ) . ')' );
        }

        /* 4. Die Stammdaten selbst. Der Eintrag bleibt stehen, wo er noch
              gebraucht wird — aber ohne Namen, ohne Adresse, ohne Konto.
              Beim Trainer mit Belegen bleibt der Name (siehe oben); die
              Adresse geht trotzdem: Wer nach der Löschung erneut fragt, wird
              nicht mehr gefunden — Art. 11 DSGVO verlangt nicht, eine Kennung
              nur dafür aufzubewahren. */
        $kontakt_weg = [ 'email' => '', 'email_privat' => '', 'email_geschaeft' => '', 'telefon' => '',
                         'geburtsdatum' => null, 'lizenznummer' => '', 'lizenz_gueltig' => null,
                         'fuehrungszeugnis' => null, 'rettungsschwimmer' => null,
                         'wp_user_id' => 0, 'aktiv' => 0 ];
        $name_weg    = [ 'name' => self::GELOESCHT, 'display_name' => self::GELOESCHT, 'vorname' => '' ];
        if ( $mit_belegen ) {
            $weg += self::setzen( 'lsv07i_trainer', $kontakt_weg, 'id IN (' . self::liste( $mit_belegen ) . ')' );
            $meldungen[] = 'Am Trainereintrag bleibt der Name stehen, weil die aufbewahrten Abrechnungen '
                         . 'ihn als Empfänger ausweisen; Adressen, Telefon, Geburtsdatum und Konto wurden entfernt.';
        }
        if ( $ohne_belege ) {
            $weg += self::setzen( 'lsv07i_trainer', $name_weg + $kontakt_weg,
                'id IN (' . self::liste( $ohne_belege ) . ')' );
        }

        $sportler_weg = [ 'first_name' => 'Gelöscht', 'last_name' => '', 'display_name' => 'Gelöscht',
                          'email' => '', 'birth_date' => null, 'dsv_id' => '', 'notes' => '',
                          'attest_expires' => null, 'aktiv' => 0, 'active' => 0 ];
        $stamm = [
            [ 'lsv07i_tri_trainer',  'tri_trainer', [ 'name' => self::GELOESCHT, 'email' => '', 'wp_user_id' => 0, 'aktiv' => 0 ] ],
            [ 'lsv07i_fit_trainer',  'fit_trainer', [ 'name' => self::GELOESCHT, 'email' => '', 'wp_user_id' => 0, 'aktiv' => 0 ] ],
            [ 'lsv07i_tri_sportler', 'tri',     $sportler_weg ],
            [ 'lsv07i_fit_sportler', 'fit',     $sportler_weg ],
            [ 'mv_swimmers',         'swimmer', $sportler_weg ],
            [ 'lsv07i_personen',     'person',
              [ 'vorname' => 'Gelöscht', 'nachname' => '', 'email' => '', 'telefon' => '',
                'geburtsdatum' => null, 'geschlecht' => null, 'dsv_id' => '', 'mitgliedsnummer' => '',
                'notes' => '', 'wp_user_id' => 0, 'aktiv' => 0 ] ],
        ];
        foreach ( $stamm as $s ) {
            $weg += self::setzen( $s[0], $s[2], $b( [ [ 'id', $s[1] ] ] ) );
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

    /** Die eigen-Bedingungen einer Quelle — damit Auskunft und Löschung dieselben sind. */
    private static function quellen_wo( $tabelle ) {
        foreach ( self::quellen() as $q ) {
            if ( $q['tabelle'] === $tabelle ) return $q['eigen'];
        }
        return [];
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

    /**
     * Anhänge an den eigenen Nachrichten: Datei und Zeile.
     *
     * Der Text der Nachricht bleibt (anonymisiert) für die anderen im
     * Gespräch stehen; ein Anhang aber ist oft ein Foto oder Dokument der
     * Person selbst und hat ohne Absender keinen Wert für den Verlauf.
     * Ablage wie in LSV07I_Konversation::delete_konv():
     * lsv07i-private/nachrichten/<konv_id>/<gespeichert_als>.
     */
    private static function anhaenge_weg( $k ) {
        global $wpdb;
        if ( ! self::tabelle_da( 'lsv07i_konv_anhang' ) || ! self::tabelle_da( 'lsv07i_konv_msg' ) ) return 0;
        $bed = self::bedingung( [ [ 'a.msg_id', 'msg' ] ], $k );
        if ( $bed === '' ) return 0;
        $zeilen = $wpdb->get_results(
            "SELECT a.id, a.gespeichert_als, m.konv_id
               FROM " . self::t( 'lsv07i_konv_anhang' ) . " a
               JOIN " . self::t( 'lsv07i_konv_msg' ) . " m ON m.id = a.msg_id
              WHERE $bed", ARRAY_A );
        $n = 0;
        foreach ( (array) $zeilen as $z ) {
            self::datei_weg( 'nachrichten/' . (int) $z['konv_id'], $z['gespeichert_als'] );
            $wpdb->delete( self::t( 'lsv07i_konv_anhang' ), [ 'id' => (int) $z['id'] ], [ '%d' ] );
            $n++;
        }
        return $n;
    }

    /** Selbst hochgeladene Ticket-Anhänge (Ablage lsv07i-private/tickets/, flach). */
    private static function ticket_anhaenge_weg( $k ) {
        global $wpdb;
        if ( ! $k['user'] || ! self::tabelle_da( 'lsv07i_ticket_anhang' ) ) return 0;
        $zeilen = $wpdb->get_results(
            "SELECT id, gespeichert_als FROM " . self::t( 'lsv07i_ticket_anhang' ) . "
              WHERE hochgeladen_von IN (" . self::liste( $k['user'] ) . ")", ARRAY_A );
        $n = 0;
        foreach ( (array) $zeilen as $z ) {
            self::datei_weg( 'tickets', $z['gespeichert_als'] );
            $wpdb->delete( self::t( 'lsv07i_ticket_anhang' ), [ 'id' => (int) $z['id'] ], [ '%d' ] );
            $n++;
        }
        return $n;
    }

    /**
     * Das Profilbild (Datei + Verweis) und die Einstellungen des Kontos im
     * internen Bereich. `lsv07i_rechte_explicit` bleibt: Fehlt der Vermerk,
     * vergäbe die nächste Rechte-Migration die eben entzogenen Rechte aus
     * der WordPress-Rolle neu.
     */
    private static function profilbild_weg( $k ) {
        $n = 0;
        foreach ( $k['user'] as $uid ) {
            $datei = get_user_meta( $uid, 'lsv07i_profilbild', true );
            if ( $datei && self::datei_weg( 'profilbilder', $datei ) ) $n++;
            foreach ( [ 'lsv07i_profilbild', 'lsv07i_profilbild_ver', 'lsv07i_last_seen',
                        'lsv07i_home_widgets', 'lsv07i_home_gruppe', 'lsv07i_theme',
                        'lsv07i_mb_zustaende' ] as $meta ) {
                delete_user_meta( $uid, $meta );
            }
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
            . 'werden auf Wunsch gelöscht; bei zurückliegenden Anwesenheiten, Nachrichten '
            . 'und Protokolleinträgen wird der Personenbezug entfernt, damit die Listen '
            . 'nicht auseinanderfallen.</p>';
        $t .= '<p>Auskunft und Löschung laufen über die Werkzeuge von WordPress unter '
            . '„Personenbezogene Daten exportieren" bzw. „löschen".</p>';

        wp_add_privacy_policy_content( self::NAME, wp_kses_post( $t ) );
    }
}
