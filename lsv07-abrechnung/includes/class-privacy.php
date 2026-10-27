<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Auskunft und Löschung über die Bordmittel von WordPress.
 *
 * WordPress bringt unter **Werkzeuge → Personenbezogene Daten exportieren**
 * und **→ löschen** einen fertigen Ablauf mit: Die betroffene Person wird
 * per E-Mail bestätigt, bekommt einen zeitlich begrenzten Link, und jedes
 * Plugin trägt über zwei Filter bei, was es über sie gespeichert hat.
 * Dieses Plugin hängt sich dort ein, statt einen eigenen Weg zu bauen —
 * ein zweiter Ablauf daneben würde beim nächsten Mal vergessen.
 *
 * Zwei Dinge sind an einer Abrechnung besonders:
 *
 * 1. **Sie ist ein Buchungsbeleg.** Was eingereicht, genehmigt oder bezahlt
 *    wurde, darf NICHT gelöscht werden: § 147 AO und § 257 HGB verlangen
 *    zehn Jahre Aufbewahrung, und Art. 17 Abs. 3 lit. b DSGVO nimmt genau
 *    das vom Löschanspruch aus. Solche Vorgänge werden deshalb als
 *    `items_retained` gemeldet, mit Begründung — nicht stillschweigend
 *    übergangen und nicht stillschweigend gelöscht.
 * 2. **Ein Entwurf ist kein Beleg.** Was nie eingereicht wurde (oder
 *    zurückgegeben und nie wieder eingereicht), ist nicht gebucht. Das wird
 *    gelöscht, samt der hochgeladenen Belegdateien.
 *
 * Die Stammdaten — IBAN, BIC, Anschrift, Mailadresse — werden IMMER geleert,
 * auch wenn Abrechnungen bleiben. Sie sind die aktuelle Vorbelegung für
 * künftige Rechnungen, kein Teil der Bücher: Was eine bezahlte Rechnung
 * zum Beleg braucht, steht als Schnappschuss in der Rechnung selbst
 * (`empf_name`, `empf_iban`) und bleibt dort.
 *
 * Der Export nennt die IBAN **vollständig**. Art. 15 verlangt eine Kopie
 * der Daten, und es sind die eigenen; eine gekürzte Auskunft wäre eine
 * unvollständige. Die Datei geht über den Ablauf von WordPress an die
 * Person selbst, nicht an Dritte.
 */
class LSV07A_Privacy {

    const SCHLUESSEL = 'lsv07-abrechnung';
    const NAME       = 'LSV07 Abrechnung';

    /** Zeilen je Seite. WordPress ruft den Rückruf seitenweise auf. */
    const PRO_SEITE = 200;

    /**
     * Tabellen ohne Personenbezug.
     *
     * Die Liste steht hier, damit eine Prüfung sie gegen die angebundenen
     * Tabellen halten kann: Kommt später eine Tabelle dazu und steht sie
     * weder hier noch bei den Quellen, fällt das auf. Ohne diese Liste
     * würde eine neue Tabelle einfach nie exportiert, und niemand merkte es.
     */
    const OHNE_PERSON = [
        'lsv07a_abteilung',             // Name einer Abteilung
        'lsv07a_abteilung_mannschaft',  // welche Mannschaft zu welcher Abteilung
        'lsv07a_mannschaft',            // eigene Mannschaften
        'lsv07a_pauschale',             // Pauschale je Mannschaft und Wochentag
        'lsv07a_config',                // Sätze, Fristen, Mailschalter
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

    // ── Wen meint diese E-Mail? ──────────────────────────────────────────

    /**
     * Die Kontonummer hinter einer E-Mail-Adresse. 0 = niemand.
     *
     * Gesucht wird an zwei Stellen: beim WordPress-Konto und bei der
     * Benachrichtigungsadresse, die ein Konto hier hinterlegen kann. Wer
     * eine abweichende Adresse eingetragen hat, soll darüber auch Auskunft
     * bekommen — sonst käme auf genau die Adresse, an die das Plugin
     * schreibt, die Antwort „zu dieser Adresse ist nichts gespeichert".
     */
    public static function konto( $email ) {
        $email = trim( (string) $email );
        if ( $email === '' ) return 0;

        $user = get_user_by( 'email', $email );
        if ( $user ) return (int) $user->ID;

        global $wpdb;
        $uid = $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM " . self::t( 'lsv07a_person' ) . "
              WHERE mail = %s AND wp_user_id > 0 LIMIT 1", $email ) );
        return (int) $uid;
    }

    private static function t( $name ) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    // ── Die Quellen ──────────────────────────────────────────────────────

    /**
     * Was es über eine Person geben kann, in der Reihenfolge des Exports.
     *
     * `wo` wird immer mit genau einer Kontonummer gefüllt. `felder` bildet
     * Spalte auf Überschrift ab; was nicht daraufsteht, wandert nicht in den
     * Export — eine interne Hilfsspalte ist keine Auskunft.
     */
    private static function quellen() {
        return [
            [
                'gruppe'  => 'lsv07a_stammdaten',
                'titel'   => 'Abrechnung: Stammdaten',
                'tabelle' => 'lsv07a_person',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [
                    'kontoinhaber'   => 'Kontoinhaber',
                    'iban'           => 'IBAN',
                    'bic'            => 'BIC',
                    'strasse'        => 'Strasse',
                    'plz'            => 'PLZ',
                    'ort'            => 'Ort',
                    'mail'           => 'E-Mail für Benachrichtigungen',
                    'stundensatz'    => 'Stundensatz',
                    'abrechnungsart' => 'Abrechnungsart',
                    'abteilung_id'   => 'Abteilung (Nummer)',
                    'notiz'          => 'Notiz der Verwaltung',
                    'erstellt_am'    => 'Angelegt am',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_rollen',
                'titel'   => 'Abrechnung: Rollen',
                'tabelle' => 'lsv07a_rolle',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [ 'rolle' => 'Rolle', 'erstellt_am' => 'Vergeben am' ],
            ],
            [
                'gruppe'  => 'lsv07a_leitung',
                'titel'   => 'Abrechnung: geleitete Abteilungen',
                'tabelle' => 'lsv07a_abteilung_leiter',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [ 'abteilung_id' => 'Abteilung (Nummer)', 'erstellt_am' => 'Seit' ],
            ],
            [
                'gruppe'  => 'lsv07a_wart',
                'titel'   => 'Abrechnung: geprüfte Mannschaften',
                'tabelle' => 'lsv07a_wart_bereich',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [ 'mannschaft_id' => 'Mannschaft (Nummer)', 'erstellt_am' => 'Seit' ],
            ],
            [
                'gruppe'  => 'lsv07a_pers_pauschale',
                'titel'   => 'Abrechnung: persönliche Pauschalen',
                'tabelle' => 'lsv07a_person_pauschale',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [
                    'mannschaft_id' => 'Mannschaft (Nummer)',
                    'wochentag'     => 'Wochentag',
                    'betrag'        => 'Betrag',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_abrechnung',
                'titel'   => 'Abrechnung: Quartalsabrechnungen',
                'tabelle' => 'lsv07a_abrechnung',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [
                    'jahr'            => 'Jahr',
                    'quartal'         => 'Quartal',
                    'status'          => 'Stand',
                    'stundensatz'     => 'Stundensatz',
                    'abrechnungsart'  => 'Abrechnungsart',
                    'kommentar'       => 'Kommentar',
                    'rueckgabe_grund' => 'Grund der Rückgabe',
                    'eingereicht_am'  => 'Eingereicht am',
                    'genehmigt_am'    => 'Genehmigt am',
                    'bezahlt_am'      => 'Bezahlt am',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_posten',
                'titel'   => 'Abrechnung: einzelne Posten',
                'tabelle' => 'lsv07a_posten',
                'wo'      => 'abrechnung_id IN ( SELECT id FROM %%abrechnung%% WHERE wp_user_id = %d )',
                'felder'  => [
                    'typ'         => 'Art',
                    'datum'       => 'Datum',
                    'bezeichnung' => 'Bezeichnung',
                    'menge'       => 'Menge',
                    'menge_plan'  => 'Menge laut Plan',
                    'satz'        => 'Satz',
                    'betrag'      => 'Betrag',
                    'wartezeit'   => 'Wartezeit',
                    'notiz'       => 'Notiz',
                    'quelle'      => 'Herkunft',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_nicht_auto',
                'titel'   => 'Abrechnung: entfernte Trainings',
                'tabelle' => 'lsv07a_nicht_auto',
                'wo'      => 'abrechnung_id IN ( SELECT id FROM %%abrechnung%% WHERE wp_user_id = %d )',
                'felder'  => [
                    'ref_typ'     => 'Bezug',
                    'ref_id'      => 'Nummer',
                    'erstellt_am' => 'Entfernt am',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_notizen',
                'titel'   => 'Abrechnung: eigene Notizen und Beanstandungen',
                'tabelle' => 'lsv07a_posten_notiz',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [
                    'art'         => 'Art',
                    'text'        => 'Text',
                    'erstellt_am' => 'Geschrieben am',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_rechnung',
                'titel'   => 'Abrechnung: eigene Rechnungen',
                'tabelle' => 'lsv07a_rechnung',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [
                    'titel'           => 'Betreff',
                    'zweck'           => 'Verwendungszweck',
                    'betrag'          => 'Betrag',
                    'rechnungsdatum'  => 'Rechnungsdatum',
                    'status'          => 'Stand',
                    'empf_name'       => 'Empfänger',
                    'empf_iban'       => 'IBAN des Empfängers',
                    'empf_bic'        => 'BIC des Empfängers',
                    'kommentar'       => 'Kommentar',
                    'rueckgabe_grund' => 'Grund der Rückgabe',
                    'eingereicht_am'  => 'Eingereicht am',
                    'genehmigt_am'    => 'Genehmigt am',
                    'bezahlt_am'      => 'Bezahlt am',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_belege',
                'titel'   => 'Abrechnung: hochgeladene Belege',
                'tabelle' => 'lsv07a_rechnung_datei',
                'wo'      => 'rechnung_id IN ( SELECT id FROM %%rechnung%% WHERE wp_user_id = %d )',
                'felder'  => [
                    'dateiname'       => 'Dateiname',
                    'mime_type'       => 'Typ',
                    'groesse'         => 'Grösse in Bytes',
                    'hochgeladen_am'  => 'Hochgeladen am',
                ],
                /* Nur das Verzeichnis, nicht der Inhalt: Die Belege selbst
                   liegen verschlüsselt und sind über die Abrechnung
                   herunterzuladen. Ein Foto in eine Textdatei zu schreiben
                   hilft niemandem. */
                'anmerkung' => 'Die Datei selbst ist in der Abrechnung unter '
                             . '„Rechnungen/Belege einreichen" herunterzuladen.',
            ],
            [
                'gruppe'  => 'lsv07a_entschieden',
                'titel'   => 'Abrechnung: von dieser Person entschieden',
                'tabelle' => 'lsv07a_rechnung',
                'wo'      => '( genehmigt_von = %d OR bezahlt_von = %d )',
                'felder'  => [
                    'titel'        => 'Betreff der Rechnung',
                    'status'       => 'Stand',
                    'genehmigt_am' => 'Genehmigt am',
                    'bezahlt_am'   => 'Bezahlt am',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_entschieden_abr',
                'titel'   => 'Abrechnung: von dieser Person entschiedene Quartalsabrechnungen',
                'tabelle' => 'lsv07a_abrechnung',
                'wo'      => '( genehmigt_von = %d OR bezahlt_von = %d )',
                'felder'  => [
                    'jahr'         => 'Jahr',
                    'quartal'      => 'Quartal',
                    'status'       => 'Stand',
                    'genehmigt_am' => 'Genehmigt am',
                    'bezahlt_am'   => 'Bezahlt am',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_mitteilungen',
                'titel'   => 'Abrechnung: Mitteilungen',
                'tabelle' => 'lsv07a_nachricht',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [
                    'art'         => 'Art',
                    'titel'       => 'Betreff',
                    'text'        => 'Text',
                    'gelesen_am'  => 'Gelesen am',
                    'erstellt_am' => 'Erhalten am',
                ],
            ],
            [
                'gruppe'  => 'lsv07a_protokoll',
                'titel'   => 'Abrechnung: Protokoll',
                'tabelle' => 'lsv07a_log',
                'wo'      => 'wp_user_id = %d',
                'felder'  => [
                    'aktion'      => 'Vorgang',
                    'ziel_typ'    => 'Bezug',
                    'ziel_id'     => 'Nummer',
                    'details'     => 'Einzelheiten',
                    'erstellt_am' => 'Zeitpunkt',
                ],
            ],
        ];
    }

    /** Die Platzhalter für Unterabfragen auflösen. */
    private static function wo( $muster ) {
        return str_replace(
            [ '%%abrechnung%%', '%%rechnung%%' ],
            [ self::t( 'lsv07a_abrechnung' ), self::t( 'lsv07a_rechnung' ) ],
            $muster );
    }

    /** Wie oft %d in der Bedingung steht — so oft wird die Nummer gebunden. */
    private static function bindung( $wo, $uid ) {
        return array_fill( 0, max( 1, substr_count( $wo, '%d' ) ), (int) $uid );
    }

    // ── Auskunft ─────────────────────────────────────────────────────────

    /**
     * Der Export, seitenweise.
     *
     * Der Seitenplan wird aus den Zeilenzahlen gerechnet, nicht gemerkt:
     * WordPress gibt nur die Seitenzahl mit, und ein Plan, der sich aus der
     * E-Mail allein ergibt, braucht keinen Zwischenspeicher, der irgendwann
     * nicht mehr zur Anfrage passt.
     */
    public static function exportieren( $email, $seite = 1 ) {
        $uid = self::konto( $email );
        if ( ! $uid ) return [ 'data' => [], 'done' => true ];

        $plan = self::plan( $uid );
        if ( ! $plan ) return [ 'data' => [], 'done' => true ];

        $seite = max( 1, (int) $seite );
        if ( $seite > count( $plan ) ) return [ 'data' => [], 'done' => true ];

        $schritt = $plan[ $seite - 1 ];
        return [
            'data' => self::seite_holen( $uid, $schritt['quelle'], $schritt['offset'] ),
            'done' => $seite >= count( $plan ),
        ];
    }

    /** Je Eintrag: welche Quelle, ab welcher Zeile. */
    private static function plan( $uid ) {
        global $wpdb;
        $plan = [];
        foreach ( self::quellen() as $i => $q ) {
            $wo = self::wo( $q['wo'] );
            $anzahl = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::t( $q['tabelle'] ) . " WHERE $wo",
                self::bindung( $wo, $uid ) ) );
            for ( $off = 0; $off < $anzahl; $off += self::PRO_SEITE ) {
                $plan[] = [ 'quelle' => $i, 'offset' => $off ];
            }
        }
        return $plan;
    }

    private static function seite_holen( $uid, $nr, $offset ) {
        global $wpdb;
        $q  = self::quellen()[ $nr ];
        $wo = self::wo( $q['wo'] );

        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( $q['tabelle'] ) . " WHERE $wo
              ORDER BY id ASC LIMIT %d OFFSET %d",
            array_merge( self::bindung( $wo, $uid ), [ self::PRO_SEITE, (int) $offset ] )
        ), ARRAY_A );

        $raus = [];
        foreach ( (array) $zeilen as $z ) {
            $daten = [];
            foreach ( $q['felder'] as $spalte => $name ) {
                if ( ! array_key_exists( $spalte, $z ) ) continue;
                $wert = $z[ $spalte ];
                if ( $wert === null || $wert === '' ) continue;   // Leeres sagt nichts
                $daten[] = [ 'name' => $name, 'value' => wp_strip_all_tags( (string) $wert ) ];
            }
            if ( ! $daten ) continue;
            if ( ! empty( $q['anmerkung'] ) ) {
                $daten[] = [ 'name' => 'Hinweis', 'value' => $q['anmerkung'] ];
            }
            $raus[] = [
                'group_id'    => $q['gruppe'],
                'group_label' => $q['titel'],
                'item_id'     => $q['gruppe'] . '-' . (int) $z['id'],
                'data'        => $daten,
            ];
        }
        return $raus;
    }

    // ── Löschung ─────────────────────────────────────────────────────────

    /**
     * Löschen, soweit erlaubt — und sagen, was bleibt und warum.
     *
     * Es wird in einem Durchgang erledigt (`done` ist immer true): Die
     * Mengen sind klein, und eine halb gelöschte Person über mehrere
     * Seiten hinweg wäre schlimmer als ein etwas längerer Aufruf.
     */
    public static function loeschen( $email, $seite = 1 ) {
        $leer = [ 'items_removed' => false, 'items_retained' => false,
                  'messages' => [], 'done' => true ];
        $uid = self::konto( $email );
        if ( ! $uid ) return $leer;
        if ( (int) $seite > 1 ) return $leer;

        global $wpdb;
        $weg = 0; $bleibt = 0; $meldungen = [];

        // 1. Entwürfe und Zurückgegebenes: nie gebucht, also weg.
        $entwuerfe = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'lsv07a_abrechnung' ) . "
              WHERE wp_user_id = %d AND status IN ( 'entwurf', 'zurueck' )", $uid ) );
        foreach ( $entwuerfe as $aid ) {
            $aid = (int) $aid;
            $wpdb->delete( self::t( 'lsv07a_posten' ),     [ 'abrechnung_id' => $aid ], [ '%d' ] );
            $wpdb->delete( self::t( 'lsv07a_nicht_auto' ), [ 'abrechnung_id' => $aid ], [ '%d' ] );
            $wpdb->delete( self::t( 'lsv07a_posten_notiz' ), [ 'abrechnung_id' => $aid ], [ '%d' ] );
            $wpdb->delete( self::t( 'lsv07a_abrechnung' ), [ 'id' => $aid ], [ '%d' ] );
            $weg++;
        }

        $r_entwuerfe = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'lsv07a_rechnung' ) . "
              WHERE wp_user_id = %d AND status IN ( 'entwurf', 'zurueck' )", $uid ) );
        foreach ( $r_entwuerfe as $rid ) {
            LSV07A_Rechnung::dateien_weg( (int) $rid );
            $wpdb->delete( self::t( 'lsv07a_rechnung' ), [ 'id' => (int) $rid ], [ '%d' ] );
            $weg++;
        }
        if ( $entwuerfe || $r_entwuerfe ) {
            $meldungen[] = 'Nicht eingereichte und zurückgegebene Abrechnungen und Rechnungen '
                         . 'wurden gelöscht, die zugehörigen Belegdateien ebenfalls.';
        }

        // 2. Was gebucht ist, bleibt — und wird benannt.
        $gebucht = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t( 'lsv07a_abrechnung' ) . "
              WHERE wp_user_id = %d", $uid ) );
        $gebucht += (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t( 'lsv07a_rechnung' ) . "
              WHERE wp_user_id = %d", $uid ) );
        if ( $gebucht ) {
            $bleibt += $gebucht;
            $meldungen[] = sprintf(
                '%d eingereichte, genehmigte oder bezahlte Abrechnungen bzw. Rechnungen bleiben '
                . 'mit Personenbezug erhalten. Sie sind Buchungsbelege: § 147 AO und § 257 HGB '
                . 'verlangen zehn Jahre Aufbewahrung, Art. 17 Abs. 3 lit. b DSGVO nimmt das vom '
                . 'Löschanspruch aus. Nach Ablauf der Frist sind sie zu löschen.', $gebucht );
        }

        // 3. Alles, was nur die Arbeit mit dem System beschreibt: weg.
        foreach ( [
            'lsv07a_nachricht'         => 'wp_user_id',
            'lsv07a_log'               => 'wp_user_id',
            'lsv07a_rolle'             => 'wp_user_id',
            'lsv07a_wart_bereich'      => 'wp_user_id',
            'lsv07a_person_pauschale'  => 'wp_user_id',
            'lsv07a_abteilung_leiter'  => 'wp_user_id',
        ] as $tabelle => $spalte ) {
            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM " . self::t( $tabelle ) . " WHERE $spalte = %d", $uid ) );
            if ( $n ) $weg += (int) $n;
        }

        /* Notizen an aufbewahrten Abrechnungen: Der Text gehört zur Prüfung
           des Belegs und bleibt, der Name dahinter nicht. */
        $n = $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::t( 'lsv07a_posten_notiz' ) . "
                SET wp_user_id = 0 WHERE wp_user_id = %d", $uid ) );
        if ( $n ) {
            $weg += (int) $n;
            $meldungen[] = 'Notizen und Beanstandungen bleiben als Teil der Belegprüfung '
                         . 'erhalten, der Personenbezug wurde entfernt.';
        }

        // 4. Die Stammdaten: Bankverbindung und Anschrift immer leeren.
        $person = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'lsv07a_person' ) . "
              WHERE wp_user_id = %d LIMIT 1", $uid ), ARRAY_A );
        if ( $person ) {
            if ( $gebucht ) {
                $wpdb->update( self::t( 'lsv07a_person' ), [
                    'kontoinhaber' => '', 'iban' => '', 'bic' => '',
                    'strasse' => '', 'plz' => '', 'ort' => '',
                    'mail' => '', 'notiz' => '', 'aktiv' => 0,
                ], [ 'wp_user_id' => $uid ],
                   [ '%s','%s','%s','%s','%s','%s','%s','%s','%d' ], [ '%d' ] );
                $meldungen[] = 'Bankverbindung, Anschrift und Mailadresse der Stammdaten wurden '
                             . 'geleert. Was eine bezahlte Rechnung als Beleg braucht, steht als '
                             . 'Schnappschuss in der Rechnung selbst und bleibt dort.';
            } else {
                $wpdb->delete( self::t( 'lsv07a_person' ), [ 'wp_user_id' => $uid ], [ '%d' ] );
            }
            $weg++;
        }

        return [
            'items_removed'  => $weg > 0,
            'items_retained' => $bleibt > 0,
            'messages'       => $meldungen,
            'done'           => true,
        ];
    }

    // ── Vorschlag für die Datenschutzerklärung ───────────────────────────

    /**
     * WordPress sammelt unter Einstellungen → Datenschutz Textbausteine der
     * Plugins ein. Der Verein muss die Erklärung selbst verantworten — aber
     * er soll nicht raten müssen, was dieses Plugin speichert.
     */
    public static function erklaerung_vorschlagen() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) return;

        $t  = '<h2>Abrechnung für Trainerinnen und Trainer</h2>';
        $t .= '<p>Für die Abrechnung von Trainings, Wettkämpfen, Fahrtkosten und ausgelegten '
            . 'Rechnungen werden gespeichert: Name, Anschrift, Bankverbindung (IBAN, BIC), '
            . 'E-Mail-Adresse, die erfassten Posten mit Datum und Betrag sowie hochgeladene '
            . 'Belege (Fotos oder PDF).</p>';
        $t .= '<p>Belege werden verschlüsselt auf dem Server dieser Website abgelegt. Es werden '
            . 'keine Daten an Dritte übermittelt; insbesondere entsteht der QR-Code für die '
            . 'Überweisung auf diesem Server und nicht bei einem fremden Dienst.</p>';
        $t .= '<p>Jeder Zugriff auf eine Abrechnung und jede Entscheidung darüber wird '
            . 'protokolliert (Konto, Vorgang, Zeitpunkt).</p>';
        $t .= '<p><strong>Aufbewahrung:</strong> Eingereichte, genehmigte und bezahlte '
            . 'Abrechnungen sind Buchungsbelege und werden zehn Jahre aufbewahrt '
            . '(§ 147 AO, § 257 HGB). Entwürfe und zurückgegebene Abrechnungen werden auf '
            . 'Wunsch sofort gelöscht.</p>';
        $t .= '<p>Auskunft und Löschung laufen über die Werkzeuge von WordPress unter '
            . '„Personenbezogene Daten exportieren" bzw. „löschen".</p>';

        wp_add_privacy_policy_content( self::NAME, wp_kses_post( $t ) );
    }
}
