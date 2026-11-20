<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Abrechnung {

    public static function init() {
        $actions = [
            'lsv07i_abr_get_or_create',
            'lsv07i_abr_save_trainingstag',
            'lsv07i_abr_delete_trainingstag',
            'lsv07i_abr_save_wettkampf',
            'lsv07i_abr_delete_wettkampf',
            'lsv07i_abr_save_kilometer',
            'lsv07i_abr_delete_kilometer',
            'lsv07i_abr_einreichen',
            'lsv07i_abr_genehmigen',
            'lsv07i_abr_zurueckgeben',
            'lsv07i_abr_get_alle',
            'lsv07i_abr_save_stammdaten',
            'lsv07i_abr_get_stammdaten',
            'lsv07i_abr_delete_abrechnung',
            'lsv07i_abr_archivieren',
            'lsv07i_abr_zuordnen',
            'lsv07i_abr_trainer_profile',
            'lsv07i_abr_bezahlt',
            'lsv07i_abr_jahresuebersicht',
            'lsv07i_abr_kosten_mannschaft',
            'lsv07i_abr_csv_export',
            'lsv07i_abr_toggle_vorbereitung',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_abr_', '', $action ) ] );
        }
    }

    // ══ REGELN DES ABLAUFS ═════════════════════════════════════════════════
    /*  Die EINE Stelle für „in welchem Stand darf eine Abrechnung noch
        verändert werden?". Nur Entwurf und zurückgegeben. Vorher prüfte
        assert_owns() nur den Besitz — ein Trainer konnte Zeilen einer
        eingereichten oder schon genehmigten Abrechnung ändern, und weil
        die Beträge live nachgerechnet wurden, zahlte der Verein hinterher
        etwas anderes als das, was genehmigt worden war.
        ('zurueckgegeben' schreibt der Code nirgends, der Rückgabe-Stand
        heisst 'zurueck'.) */
    const BEARBEITBAR = [ 'entwurf', 'zurueck' ];

    public static function ist_bearbeitbar( $status ) {
        return in_array( (string) $status, self::BEARBEITBAR, true );
    }

    /**
     * Gibt es die Spalten für festgeschriebene Beträge? Mit $anlegen
     * werden sie nachgerüstet.
     *
     * Festgeschrieben wird beim Einreichen und Genehmigen: der Stundensatz
     * und der Gesamtbetrag. Danach ändert weder ein neuer Stundensatz noch
     * eine nachträglich eingespielte Zeile, was genehmigt und gezahlt wird.
     *
     * Die Spalten werden hier angelegt und nicht im Migrationsweg in
     * lsv07-intern.php, weil diese Datei ihn nicht anfasst. Es sind zwei
     * NULL-Spalten (NULL = noch nicht festgeschrieben, dann gilt der
     * laufende Satz) — das ALTER ist ohne Datenrisiko. Schlägt es fehl,
     * läuft alles weiter wie vorher, nur ohne Festschreiben.
     */
    private static function fest_spalten_da( $anlegen = false ) {
        static $da = null;
        if ( $da === true ) return true;
        if ( $da === false && ! $anlegen ) return false;
        global $wpdb;
        $t   = $wpdb->prefix . 'lsv07i_abrechnung';
        $da  = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM $t LIKE 'satz_fest'" ) );
        if ( ! $da && $anlegen ) {
            $wpdb->query( "ALTER TABLE $t ADD COLUMN satz_fest DECIMAL(6,2) DEFAULT NULL,
                                          ADD COLUMN gesamt_fest DECIMAL(10,2) DEFAULT NULL" );
            $da = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM $t LIKE 'satz_fest'" ) );
            if ( ! $da ) error_log( 'LSV07I abrechnung: Spalten satz_fest/gesamt_fest fehlen: ' . $wpdb->last_error );
        }
        return $da;
    }

    /** Der Stundensatz, der für diese Abrechnung gilt: festgeschrieben vor laufend. */
    private static function satz_wirksam( $abr, $laufend ) {
        $fest = $abr['satz_fest'] ?? null;
        return ( $fest !== null && $fest !== '' ) ? (float) $fest : (float) $laufend;
    }

    /** Der Gesamtbetrag, der gilt: festgeschrieben vor gerechnet. */
    private static function gesamt_wirksam( $abr, $gerechnet ) {
        $fest = $abr['gesamt_fest'] ?? null;
        return ( $fest !== null && $fest !== '' ) ? round( (float) $fest, 2 ) : round( (float) $gerechnet, 2 );
    }

    /** Dieselbe Regel wie satz_wirksam() als SQL — für Summen über viele Abrechnungen (Alias a/ts). */
    private static function satz_sql() {
        return self::fest_spalten_da()
            ? 'COALESCE( a.satz_fest, ts.stundensatz, 0 )'
            : 'COALESCE( ts.stundensatz, 0 )';
    }

    /** Die festgeschriebenen Spalten für ein SELECT mit Alias a. */
    private static function fest_select() {
        return self::fest_spalten_da()
            ? 'a.satz_fest, a.gesamt_fest,'
            : 'NULL AS satz_fest, NULL AS gesamt_fest,';
    }

    /** Stundensatz und Gesamtbetrag in die Abrechnung schreiben (Einreichen/Genehmigen). */
    private static function festschreiben( $abr_id ) {
        if ( ! self::fest_spalten_da( true ) ) return;
        $abr = self::load_abrechnung( $abr_id );
        if ( ! $abr ) return;
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'lsv07i_abrechnung',
            [ 'satz_fest' => (float) $abr['stundensatz'], 'gesamt_fest' => (float) $abr['gesamt'] ],
            [ 'id' => (int) $abr_id ], [ '%f', '%f' ], [ '%d' ] );
    }

    /** Zurückgegeben = wieder Entwurf: dann gilt wieder der laufende Satz. */
    private static function festschreibung_loesen( $abr_id ) {
        if ( ! self::fest_spalten_da() ) return;
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}lsv07i_abrechnung SET satz_fest = NULL, gesamt_fest = NULL WHERE id = %d",
            (int) $abr_id ) );
    }

    /**
     * Gehört die Zeile $id wirklich zur Abrechnung $abr_id?
     *
     * Vorher änderte ein UPDATE nur „WHERE id = …": Wer die eigene
     * Abrechnung angab, aber die Zeilennummer einer fremden, schrieb in
     * die fremde. Jede Zeilen-Änderung bindet deshalb zusätzlich an die
     * Abrechnung, deren Besitz assert_owns() gerade geprüft hat.
     */
    public static function assert_zeile( $tabelle, $id, $abr_id ) {
        $erlaubt = [ 'lsv07i_abr_trainingstage', 'lsv07i_abr_wettkampf', 'lsv07i_abr_kilometer', 'lsv07i_abr_sonder' ];
        if ( ! in_array( $tabelle, $erlaubt, true ) ) wp_send_json_error( [ 'message' => 'Ungültige Tabelle.' ], 400 );
        global $wpdb;
        $da = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}{$tabelle} WHERE id = %d AND abrechnung_id = %d LIMIT 1",
            (int) $id, (int) $abr_id ) );
        if ( ! $da ) wp_send_json_error( [ 'message' => 'Eintrag gehört nicht zu dieser Abrechnung.' ], 403 );
    }

    /** Abrechnung samt Trainer-Konto laden — oder mit 404 abbrechen. */
    private static function abr_mit_trainer( $abr_id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, t.name AS trainer_name, t.email AS trainer_email, t.wp_user_id AS trainer_wp_user_id
               FROM {$p}lsv07i_abrechnung a
          LEFT JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
              WHERE a.id = %d LIMIT 1", (int) $abr_id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ], 404 );
        return $abr;
    }

    /**
     * Vier Augen: Niemand genehmigt oder bezahlt seine EIGENE Abrechnung —
     * auch kein Administrator. Eigen heisst: das Trainer-Profil der
     * Abrechnung hängt am angemeldeten Konto.
     */
    private static function nicht_eigene( $abr ) {
        $uid = get_current_user_id();
        if ( $uid && (int) ( $abr['trainer_wp_user_id'] ?? 0 ) === $uid ) {
            wp_send_json_error( [ 'message' => 'Die eigene Abrechnung muss jemand anderes bearbeiten (Vier-Augen-Prinzip).' ], 403 );
        }
    }

    /**
     * Recht für eine Kassen-Aktion (bezahlt, CSV, Jahresübersicht).
     *
     * Das feine Recht allein würde die Warte aussperren: Ihre Vorlagen
     * enthalten die Kassen-Rechte nicht, sie sehen den Kassenwart-Reiter
     * aber heute über is_finanzwart() (= Wart-Leserecht) und arbeiten
     * dort. Deshalb: feines Recht ODER diese Rolle. Wer nur das
     * Kassen-LESErecht hat, kommt nicht mehr an die Aktion.
     */
    private static function kasse_pruefen( $recht ) {
        if ( LSV07I_Permissions::can_current( $recht ) || LSV07I_Access::is_finanzwart() ) return;
        wp_send_json_error( [ 'message' => 'Für diese Aktion fehlt die Berechtigung.' ], 403 );
    }

    /** Finanzaktionen ins Protokoll — wer hat wann genehmigt, gezahlt, gelöscht. */
    private static function protokoll( $aktion, $abr, $details = '' ) {
        if ( ! class_exists( 'LSV07I_Log' ) ) return;
        LSV07I_Log::write( $aktion, [
            'bereich'   => 'Abrechnung',
            'ziel_typ'  => 'abrechnung',
            'ziel_id'   => (int) $abr['id'],
            'ziel_name' => trim( ( $abr['trainer_name'] ?? '' ) . ' – ' . ( $abr['quartal'] ?? '' ) . ' ' . ( $abr['jahr'] ?? '' ) ),
            'details'   => $details,
        ] );
    }

    // ── Komplette Abrechnung laden ────────────────────────────────────────────
    /**
     * Übernommene Trainingstage einer OFFENEN Abrechnung auf die
     * Trainingszeit nachziehen, die an dem Tag galt.
     *
     * Der Grund: Bis zu dieser Fassung suchte der interne Bereich den Slot
     * einer Anwesenheit über `mannschaft_id + wochentag` ohne die Saison.
     * Nach einem Saisonwechsel gewann dabei meist der alte Slot, und in der
     * Abrechnung standen die alten Stunden — auch Monate später noch. Die
     * Ursache ist behoben (LSV07I_Trainingszeit), aber die Zeilen, die
     * vorher entstanden sind, stehen weiter falsch in der Datenbank.
     *
     * Was nachgezogen wird, und was nicht:
     *
     * * Nur **offene** Abrechnungen (Entwurf oder zurückgegeben).
     *   Eingereichtes, Genehmigtes und Bezahltes wird nicht angefasst —
     *   geprüft und genehmigt wurde genau das, was damals dastand.
     * * Nur **übernommene** Zeilen (`anwesenheit`, `springer`). Von Hand
     *   erfasste bleiben, wie sie sind.
     * * Nur Zeilen, an denen **nicht von Hand korrigiert** wurde. Woran das
     *   zu erkennen ist: `stunden_plan` hält fest, was der Plan vorsah;
     *   weicht `stunden` davon ab, hat jemand bewusst eingegriffen.
     * * Eine Zeile aus der Zeit **vor** dieser Spalte (`stunden_plan` ist
     *   NULL) lässt sich nicht unterscheiden — dort ist nicht zu sehen, ob
     *   korrigiert wurde. Sie wird nachgezogen, denn genau diese Zeilen sind
     *   die gemeldeten falschen. Eine frühere Korrektur von Hand wird dabei
     *   einmalig überschrieben; damit das nicht stillschweigend geschieht,
     *   steht es im Protokoll.
     * * Kommt **keine** Zeit heraus (Slot gelöscht), bleibt die Zeile
     *   stehen. Sie auf 0 zu setzen hiesse, eine vorhandene Angabe durch
     *   keine zu ersetzen.
     */
    private static function stunden_nachziehen( $abr ) {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! self::ist_bearbeitbar( $abr['abr_status'] ?? '' ) ) return;

        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.datum, t.stunden, t.stunden_plan, t.anwesenheit_id, t.springer_id,
                    a.mannschaft_id AS anw_mannschaft, a.slot_id AS anw_slot,
                    s.mannschaft_id AS spr_mannschaft, sp.slot_id AS spr_slot
               FROM {$p}lsv07i_abr_trainingstage t
          LEFT JOIN {$p}lsv07i_anwesenheit a ON a.id = t.anwesenheit_id
          LEFT JOIN {$p}lsv07i_springer sp ON sp.id = t.springer_id
          LEFT JOIN {$p}lsv07i_training_slots s ON s.id = sp.slot_id
              WHERE t.abrechnung_id = %d
                AND t.manuell_geloescht = 0
                AND t.quelle IN ( 'anwesenheit', 'springer' )",
            (int) $abr['id'] ), ARRAY_A );
        if ( ! $zeilen ) return;

        $geaendert = 0;
        $alt_summe = 0.0;
        $neu_summe = 0.0;
        $ohne_marke = 0;

        foreach ( (array) $zeilen as $z ) {
            $hat_marke = $z['stunden_plan'] !== null && $z['stunden_plan'] !== '';
            $stunden   = (float) $z['stunden'];
            $plan_alt  = $hat_marke ? (float) $z['stunden_plan'] : null;

            // Von Hand korrigiert? Dann nur die Marke auffrischen.
            $korrigiert = $hat_marke && abs( $stunden - $plan_alt ) >= 0.005;

            $mannschaft = (int) ( $z['anw_mannschaft'] ?: $z['spr_mannschaft'] ?: 0 );
            $slot       = (int) ( $z['anw_slot'] ?: $z['spr_slot'] ?: 0 );
            $neu = LSV07I_Trainingszeit::stunden( $mannschaft, $z['datum'], $slot );
            if ( $neu <= 0 ) continue;   // keine Zeit gefunden — nichts überschreiben

            if ( $korrigiert ) {
                if ( $plan_alt === null || abs( $plan_alt - $neu ) >= 0.005 ) {
                    $wpdb->update( $p . 'lsv07i_abr_trainingstage',
                        [ 'stunden_plan' => $neu ], [ 'id' => (int) $z['id'] ],
                        [ '%f' ], [ '%d' ] );
                }
                continue;
            }

            if ( abs( $stunden - $neu ) < 0.005 ) {
                if ( ! $hat_marke ) {
                    $wpdb->update( $p . 'lsv07i_abr_trainingstage',
                        [ 'stunden_plan' => $neu ], [ 'id' => (int) $z['id'] ],
                        [ '%f' ], [ '%d' ] );
                }
                continue;
            }

            $wpdb->update( $p . 'lsv07i_abr_trainingstage',
                [ 'stunden' => $neu, 'stunden_plan' => $neu ],
                [ 'id' => (int) $z['id'] ], [ '%f', '%f' ], [ '%d' ] );
            $geaendert++;
            $alt_summe += $stunden;
            $neu_summe += $neu;
            if ( ! $hat_marke ) $ohne_marke++;
        }

        if ( $geaendert && class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'abrechnung.stunden_nachgezogen', [
                'bereich'   => 'Abrechnung',
                'ziel_typ'  => 'abrechnung',
                'ziel_id'   => (int) $abr['id'],
                'ziel_name' => trim( sprintf( '%s %s', $abr['quartal'] ?? '', $abr['jahr'] ?? '' ) ),
                'details'   => sprintf(
                    '%d Trainingstage auf die Trainingszeit des Tages nachgezogen: '
                    . '%.2f statt %.2f Stunden. %d davon aus der Zeit vor der Plan-Spalte — '
                    . 'dort war eine frühere Korrektur von Hand nicht zu erkennen.',
                    $geaendert, $neu_summe, $alt_summe, $ohne_marke ),
            ] );
        }
    }

    public static function load_abrechnung( $id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, t.name AS trainer_name
               FROM {$p}lsv07i_abrechnung a
          LEFT JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
              WHERE a.id = %d LIMIT 1",
            $id
        ), ARRAY_A );
        if ( ! $abr ) return null;

        self::stunden_nachziehen( $abr );

        $abr['trainingstage'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_trainingstage WHERE abrechnung_id = %d AND manuell_geloescht = 0 ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        $abr['wettkampf'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_wettkampf
              WHERE abrechnung_id = %d AND manuell_geloescht = 0
           ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        $abr['kilometer'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_kilometer WHERE abrechnung_id = %d ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        // Sonderabrechnung-Einträge (Tag+Stunden). Für die normalen Sparten
        // (schwimmen/triathlon/fitness) bleibt diese Liste leer und geht mit
        // 0,00 € in die Summe ein — daher unten unbedingt (nicht nur für
        // bereich='sonder') berücksichtigt, ohne dass sich an den anderen
        // Beträgen etwas ändert.
        $abr['sonder'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_sonder WHERE abrechnung_id = %d AND manuell_geloescht = 0 ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        // Gesamt-Berechnung
        // Stundensatz: erst per trainer_id, dann Fallback über ein anderes
        // aktives Profil DESSELBEN Kontos. Vorher war der Fallback das Konto
        // des Betrachters — ein Wart, der eine Abrechnung ohne Stammdaten
        // öffnete, sah sie mit seinem eigenen Satz berechnet.
        $stammdaten = $wpdb->get_row( $wpdb->prepare(
            "SELECT stundensatz
               FROM {$p}lsv07i_trainer_stammdaten WHERE trainer_id = %d LIMIT 1",
            $abr['trainer_id']
        ), ARRAY_A );
        if ( ! $stammdaten ) {
            $stammdaten = $wpdb->get_row( $wpdb->prepare(
                "SELECT ts.stundensatz
                   FROM {$p}lsv07i_trainer_stammdaten ts
                   JOIN {$p}lsv07i_trainer t  ON t.id = ts.trainer_id
                   JOIN {$p}lsv07i_trainer t0 ON t0.id = %d AND t0.wp_user_id > 0 AND t0.wp_user_id = t.wp_user_id
                  WHERE t.aktiv = 1 LIMIT 1",
                $abr['trainer_id']
            ), ARRAY_A );
        }
        $stundensatz  = self::satz_wirksam( $abr, $stammdaten ? (float) $stammdaten['stundensatz'] : 0.0 );

        // Trainings-Stunden = Summe der Original-Stunden aus den Trainingstage-Einträgen
        $training_stunden  = (float) array_sum( array_column( $abr['trainingstage'], 'stunden' ) );
        $training_betrag   = round( $training_stunden * $stundensatz, 2 );

        // Vorbereitungs-Bonus: 0.25h pro Trainingstag, ABER nur für die einzeln
        // mit vorbereitung_aktiv=1 markierten Trainings. Wird separat ausgewiesen.
        $anzahl_trainings        = count( $abr['trainingstage'] );
        $anzahl_mit_vorbereitung = 0;
        foreach ( $abr['trainingstage'] as $t ) {
            if ( ! empty( $t['vorbereitung_aktiv'] ) ) $anzahl_mit_vorbereitung++;
        }
        $vorbereitung_stunden = round( $anzahl_mit_vorbereitung * 0.25, 2 );
        $vorbereitung_betrag  = round( $vorbereitung_stunden * $stundensatz, 2 );

        $wettkampf_betrag  = (float) array_sum( array_column( $abr['wettkampf'], 'betrag' ) );
        $km_betrag         = (float) array_sum( array_column( $abr['kilometer'], 'betrag' ) );
        $sonder_stunden    = (float) array_sum( array_column( $abr['sonder'], 'stunden' ) );
        $sonder_betrag     = round( $sonder_stunden * $stundensatz, 2 );
        $gesamt            = round( $training_betrag + $vorbereitung_betrag + $wettkampf_betrag + $km_betrag + $sonder_betrag, 2 );

        $abr['sonder_stunden']              = round( $sonder_stunden, 2 );
        $abr['sonder_betrag']               = $sonder_betrag;

        $abr['stundensatz']                = $stundensatz;
        $abr['training_stunden']           = round( $training_stunden, 2 );
        $abr['training_betrag']            = $training_betrag;
        $abr['anzahl_trainings']           = $anzahl_trainings;
        $abr['anzahl_mit_vorbereitung']    = $anzahl_mit_vorbereitung;
        $abr['vorbereitung_stunden']       = $vorbereitung_stunden;
        $abr['vorbereitung_betrag']        = $vorbereitung_betrag;
        $abr['wettkampf_betrag']           = round( $wettkampf_betrag, 2 );
        $abr['km_betrag']                  = round( $km_betrag, 2 );
        // Festgeschrieben gilt der festgeschriebene Betrag. Weicht die
        // Rechnung aus den Zeilen davon ab (etwa weil eine Anwesenheit
        // nachträglich eine Zeile ergänzt hat), wird das sichtbar statt
        // stillschweigend bezahlt.
        $abr['gesamt']                     = self::gesamt_wirksam( $abr, $gesamt );
        $abr['gesamt_gerechnet']           = $gesamt;

        return $abr;
    }

    // ── Abrechnung laden / erstellen ──────────────────────────────────────────
    /*
     * Zugang: 'trainer' -- also wer einen Trainer-Datensatz hat ODER das Recht
     * "eigene Abrechnung" besitzt. Frueher stand hier 'intern', das aber
     * "Admin ODER irgendein schwimmen.*-Recht" bedeutet. Der Trainer-Bereich
     * ist jedoch fuer alle Trainer sichtbar (siehe get_access_map()['trainer']).
     * Ein Triathlon- oder Fitness-Trainer ohne Schwimmen-Recht sah die
     * Abrechnung dadurch, bekam aber bei jedem Aufruf "Keine Berechtigung" --
     * die Abrechnung lud nicht und Stammdaten liessen sich nicht speichern.
     * Die Zeilen-Ebene bleibt unabhaengig davon geschuetzt: jeder aendernde
     * Endpunkt prueft ueber assert_owns(), dass die Abrechnung dem Aufrufer
     * gehoert.
     */
    public static function get_or_create() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p = $wpdb->prefix;

        $trainer_id = self::get_or_create_trainer_id();
        if ( ! $trainer_id ) wp_send_json_error( [ 'message' => 'Kein Trainer-Profil gefunden. Bitte im Admin-Bereich ein Trainer-Profil anlegen.' ] );

        $quartal = sanitize_text_field( $_POST['quartal'] ?? 'Q' . ceil( date('n') / 3 ) );
        $jahr    = absint( $_POST['jahr'] ?? date('Y') );

        $bereich = sanitize_text_field( $_POST['bereich'] ?? 'schwimmen' );
        if ( ! in_array( $bereich, [ 'schwimmen', 'triathlon', 'fitness' ], true ) ) $bereich = 'schwimmen';

        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_abrechnung WHERE trainer_id = %d AND bereich = %s AND quartal = %s AND jahr = %d LIMIT 1",
            $trainer_id, $bereich, $quartal, $jahr
        ) );

        if ( ! $id ) {
            $wpdb->insert( $p . 'lsv07i_abrechnung', [
                'trainer_id' => $trainer_id, 'bereich' => $bereich, 'quartal' => $quartal,
                'jahr' => $jahr, 'abr_status' => 'entwurf',
            ], [ '%d', '%s', '%s', '%d', '%s' ] );
            $id = $wpdb->insert_id;
        }

        // Anwesenheiten vorausfüllen — nur solange die Abrechnung offen ist.
        // Sonst wüchse eine eingereichte oder genehmigte Abrechnung beim
        // blossen Öffnen um neu erfasste Tage.
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT abr_status FROM {$p}lsv07i_abrechnung WHERE id = %d", $id ) );
        if ( self::ist_bearbeitbar( $status ) ) {
            self::prefill_trainingstage( $id, $trainer_id, $quartal, $jahr );
            self::prefill_wettkampf( $id, $trainer_id, $quartal, $jahr );
        }

        // Config für Frontend mitschicken
        $abr = self::load_abrechnung( $id );
        $abr['config'] = [
            'km_satz'      => (float) LSV07I_DB::get_config( 'km_satz', 0.30 ),
            'km_min'       => (float) LSV07I_DB::get_config( 'km_min', 20 ),
            'wk_pauschale' => (float) LSV07I_DB::get_config( 'wk_pauschale', 25 ),
        ];
        $abr['mannschaften'] = LSV07I_DB::get_mannschaften();

        wp_send_json_success( $abr );
    }

    private static function prefill_trainingstage( $abr_id, $trainer_id, $quartal, $jahr ) {
        global $wpdb;
        $p           = $wpdb->prefix;
        $quartal_num = (int) substr( $quartal, 1 );
        $monat_von   = ( ( $quartal_num - 1 ) * 3 ) + 1;
        $datum_von   = sprintf( '%d-%02d-01', $jahr, $monat_von );
        $datum_bis   = date( 'Y-m-t', strtotime( sprintf( '%d-%02d-01', $jahr, $monat_von + 2 ) ) );

        /* Die Stunden werden NICHT mehr in SQL aus dem Slot gerechnet.
           `a.slot_id` kann nach einem Saisonwechsel auf die alte Zeile
           zeigen; massgeblich ist die Zeit, die an diesem DATUM galt. Das
           beantwortet LSV07I_Trainingszeit — dieselbe Stelle, die auch der
           Haken „anwesend" und das Mannschaftsbuch fragen. */

        // Anwesenheiten (Trainer als anwesend markiert)
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.training_datum, a.mannschaft_id, a.slot_id, g.name AS mannschaft_name,
                    a.id AS anwesenheit_id
               FROM {$p}lsv07i_anwesenheit a
               JOIN {$p}lsv07i_training_slots s ON s.id = a.slot_id
               JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.mannschaft_id = a.mannschaft_id AND tm.trainer_id = %d
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = a.mannschaft_id
               JOIN {$p}lsv07i_anwesenheit_eintraege ae ON ae.anwesenheit_id = a.id
                    AND ae.teilnehmer_typ = 'trainer' AND ae.teilnehmer_id = %d AND ae.status = 'anwesend'
              WHERE a.training_datum BETWEEN %s AND %s AND a.ausgefallen = 0
                AND NOT EXISTS (SELECT 1 FROM {$p}lsv07i_abr_trainingstage t2
                                 WHERE t2.abrechnung_id = %d AND t2.anwesenheit_id = a.id)",
            $trainer_id, $trainer_id, $datum_von, $datum_bis, $abr_id
        ), ARRAY_A );

        foreach ( $rows as $r ) {
            $stunden = LSV07I_Trainingszeit::stunden(
                (int) $r['mannschaft_id'], $r['training_datum'], (int) $r['slot_id'] );
            $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                'abrechnung_id'   => $abr_id,
                'datum'           => $r['training_datum'],
                'mannschaft_name' => $r['mannschaft_name'],
                'stunden'         => $stunden,
                'stunden_plan'    => $stunden,
                'quelle'          => 'anwesenheit',
                'anwesenheit_id'  => $r['anwesenheit_id'],
            ], [ '%d', '%s', '%s', '%f', '%f', '%s', '%d' ] );
        }

        // Springer-Schichten
        $spr = $wpdb->get_results( $wpdb->prepare(
            "SELECT sp.training_datum, sp.slot_id, s.mannschaft_id, g.name AS mannschaft_name,
                    sp.id AS springer_id
               FROM {$p}lsv07i_springer sp
               JOIN {$p}lsv07i_training_slots s ON s.id = sp.slot_id
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id
              WHERE sp.trainer_id = %d AND sp.training_datum BETWEEN %s AND %s
                AND NOT EXISTS (SELECT 1 FROM {$p}lsv07i_abr_trainingstage t2
                                 WHERE t2.abrechnung_id = %d AND t2.springer_id = sp.id)",
            $trainer_id, $datum_von, $datum_bis, $abr_id
        ), ARRAY_A );

        foreach ( $spr as $r ) {
            $stunden = LSV07I_Trainingszeit::stunden(
                (int) $r['mannschaft_id'], $r['training_datum'], (int) $r['slot_id'] );
            $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                'abrechnung_id'   => $abr_id,
                'datum'           => $r['training_datum'],
                'mannschaft_name' => $r['mannschaft_name'],
                'stunden'         => $stunden,
                'stunden_plan'    => $stunden,
                'quelle'          => 'springer',
                'springer_id'     => $r['springer_id'],
            ], [ '%d', '%s', '%s', '%f', '%f', '%s', '%d' ] );
        }
    }

    /**
     * Wettkampf-Tage, an denen der Trainer anwesend war, in die Abrechnung übernehmen.
     * Respektiert manuell_geloescht-Flag und bereits bestehende Einträge.
     */
    private static function prefill_wettkampf( $abr_id, $trainer_id, $quartal, $jahr ) {
        global $wpdb;
        $p           = $wpdb->prefix;
        $quartal_num = (int) substr( $quartal, 1 );
        $monat_von   = ( ( $quartal_num - 1 ) * 3 ) + 1;
        $datum_von   = sprintf( '%d-%02d-01', $jahr, $monat_von );
        $datum_bis   = date( 'Y-m-t', strtotime( sprintf( '%d-%02d-01', $jahr, $monat_von + 2 ) ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT t.id AS wettkampf_tag_id, t.datum, t.abschnitte_plan,
                    w.name AS wettkampf_name, w.pauschale,
                    a.mannschaft_id,
                    COALESCE(e.abschnitte, 0) AS abschnitte_erfasst
               FROM {$p}lsv07i_wettkampf_anw_eintraege e
               JOIN {$p}lsv07i_wettkampf_anwesenheit a ON a.id = e.wk_anwesenheit_id
               JOIN {$p}lsv07i_wettkampf_tage t        ON t.id = a.wettkampf_tag_id
               JOIN {$p}lsv07i_wettkampf w             ON w.id = t.wettkampf_id
              WHERE e.teilnehmer_typ = 'trainer'
                AND e.teilnehmer_id = %d
                AND e.status = 'anwesend'
                AND t.datum BETWEEN %s AND %s
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}lsv07i_abr_wettkampf aw
                     WHERE aw.abrechnung_id = %d AND aw.wettkampf_tag_id = t.id
                )",
            $trainer_id, $datum_von, $datum_bis, $abr_id
        ), ARRAY_A );

        foreach ( $rows as $r ) {
            $abschnitte = (int) $r['abschnitte_erfasst'];
            if ( ! $abschnitte ) $abschnitte = max( 1, (int) $r['abschnitte_plan'] );
            $pauschale  = (float) $r['pauschale'];
            if ( $pauschale <= 0 ) $pauschale = (float) LSV07I_DB::get_config( 'wk_pauschale', 25 );
            $betrag = round( $abschnitte * $pauschale, 2 );

            $wpdb->insert( $p . 'lsv07i_abr_wettkampf', [
                'abrechnung_id'    => $abr_id,
                'wettkampf_tag_id' => $r['wettkampf_tag_id'],
                'mannschaft_id'    => $r['mannschaft_id'],
                'datum'            => $r['datum'],
                'name'             => $r['wettkampf_name'],
                'abschnitte'       => $abschnitte,
                'pauschale'        => $pauschale,
                'betrag'           => $betrag,
                'quelle'           => 'anwesenheit',
            ], [ '%d', '%d', '%d', '%s', '%s', '%d', '%f', '%f', '%s' ] );
        }
    }

    // ── Trainingstag ──────────────────────────────────────────────────────────
    public static function save_trainingstag() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p               = $wpdb->prefix;
        $abr_id          = absint( $_POST['abrechnung_id'] ?? 0 );
        $id              = absint( $_POST['id'] ?? 0 );
        $datum           = sanitize_text_field( $_POST['datum'] ?? '' );
        $mannschaft_name = sanitize_text_field( $_POST['mannschaft_name'] ?? '' );
        $stunden         = round( (float) ( $_POST['stunden'] ?? 0 ), 2 );
        $begruendung     = sanitize_textarea_field( $_POST['begruendung'] ?? '' );

        if ( ! $abr_id || ! $datum || $stunden <= 0 ) {
            wp_send_json_error( [ 'message' => 'Bitte alle Pflichtfelder ausfüllen.' ] );
        }
        self::assert_owns( $abr_id );

        if ( $id ) {
            self::assert_zeile( 'lsv07i_abr_trainingstage', $id, $abr_id );
            $wpdb->update( $p . 'lsv07i_abr_trainingstage',
                [ 'datum' => $datum, 'mannschaft_name' => $mannschaft_name, 'stunden' => $stunden, 'begruendung' => $begruendung ],
                [ 'id' => $id, 'abrechnung_id' => $abr_id ], [ '%s', '%s', '%f', '%s' ], [ '%d', '%d' ]
            );
        } else {
            if ( ! $begruendung ) wp_send_json_error( [ 'message' => 'Bitte eine Begründung für manuell hinzugefügte Trainings angeben.' ] );
            $wpdb->insert( $p . 'lsv07i_abr_trainingstage',
                [ 'abrechnung_id' => $abr_id, 'datum' => $datum, 'mannschaft_name' => $mannschaft_name,
                  'stunden' => $stunden, 'quelle' => 'manuell', 'begruendung' => $begruendung ],
                [ '%d', '%s', '%s', '%f', '%s', '%s' ]
            );
        }
        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    public static function delete_trainingstag() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT abrechnung_id, quelle FROM {$p}lsv07i_abr_trainingstage WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Eintrag nicht gefunden.' ] );
        self::assert_owns( $row['abrechnung_id'] );

        // Auto-befüllte Einträge (aus Anwesenheit oder Springer) werden nur als gelöscht markiert,
        // damit prefill sie nicht beim nächsten Laden wieder einfügt.
        if ( in_array( $row['quelle'], [ 'anwesenheit', 'springer' ], true ) ) {
            $wpdb->update(
                $p . 'lsv07i_abr_trainingstage',
                [ 'manuell_geloescht' => 1 ],
                [ 'id' => $id, 'abrechnung_id' => (int) $row['abrechnung_id'] ],
                [ '%d' ],
                [ '%d', '%d' ]
            );
        } else {
            // Manuell hinzugefügte Einträge können wirklich gelöscht werden.
            $wpdb->delete( $p . 'lsv07i_abr_trainingstage',
                [ 'id' => $id, 'abrechnung_id' => (int) $row['abrechnung_id'] ], [ '%d', '%d' ] );
        }

        wp_send_json_success( self::load_abrechnung( $row['abrechnung_id'] ) );
    }

    // ── +15min-Vorbereitungs-Checkbox pro Trainingstag toggeln ────────────────
    public static function toggle_vorbereitung() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $on = ! empty( $_POST['on'] ) ? 1 : 0;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT abrechnung_id FROM {$p}lsv07i_abr_trainingstage WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Trainingstag nicht gefunden.' ] );
        self::assert_owns( $row['abrechnung_id'] );

        $wpdb->update(
            $p . 'lsv07i_abr_trainingstage',
            [ 'vorbereitung_aktiv' => $on ],
            [ 'id' => $id, 'abrechnung_id' => (int) $row['abrechnung_id'] ],
            [ '%d' ], [ '%d', '%d' ]
        );
        wp_send_json_success( self::load_abrechnung( $row['abrechnung_id'] ) );
    }

    // ── Wettkampf ─────────────────────────────────────────────────────────────
    public static function save_wettkampf() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $abr_id     = absint( $_POST['abrechnung_id'] ?? 0 );
        $id         = absint( $_POST['id'] ?? 0 );
        $datum      = sanitize_text_field( $_POST['datum'] ?? '' );
        $name       = sanitize_text_field( $_POST['name'] ?? '' );
        $abschnitte = max( 1, absint( $_POST['abschnitte'] ?? 1 ) );
        $wk_tag_id  = absint( $_POST['wettkampf_tag_id'] ?? 0 );
        $mann_id    = absint( $_POST['mannschaft_id'] ?? 0 );

        if ( ! $abr_id || ! $datum ) wp_send_json_error( [ 'message' => 'Pflichtfelder fehlen.' ] );
        self::assert_owns( $abr_id );

        // Wenn ein Wettkampftag angegeben wurde: Pauschale und Name aus dem
        // Wettkampf-Snapshot laden (damit das konsistent bleibt)
        if ( $wk_tag_id ) {
            $wk = $wpdb->get_row( $wpdb->prepare(
                "SELECT w.name AS wettkampf_name, w.pauschale
                   FROM {$p}lsv07i_wettkampf_tage t
                   JOIN {$p}lsv07i_wettkampf w ON w.id = t.wettkampf_id
                  WHERE t.id = %d LIMIT 1",
                $wk_tag_id
            ), ARRAY_A );
            if ( $wk ) {
                if ( ! $name ) $name = $wk['wettkampf_name'];
                $pauschale = (float) $wk['pauschale'];
            }
        }
        if ( ! isset( $pauschale ) || $pauschale <= 0 ) {
            $pauschale = (float) LSV07I_DB::get_config( 'wk_pauschale', 25 );
        }
        $betrag = round( $abschnitte * $pauschale, 2 );

        $data = [ 'datum' => $datum, 'name' => $name, 'abschnitte' => $abschnitte,
                  'pauschale' => $pauschale, 'betrag' => $betrag ];

        if ( $id ) {
            // Bearbeiten (nur diese 5 Felder ändern)
            self::assert_zeile( 'lsv07i_abr_wettkampf', $id, $abr_id );
            $wpdb->update( $p . 'lsv07i_abr_wettkampf', $data, [ 'id' => $id, 'abrechnung_id' => $abr_id ],
                [ '%s', '%s', '%d', '%f', '%f' ], [ '%d', '%d' ] );
        } else {
            $data['abrechnung_id'] = $abr_id;
            // Wenn wettkampf_tag_id gegeben → Quelle 'anwesenheit' (offene Tage)
            // Sonst: reiner manueller Eintrag
            if ( $wk_tag_id ) {
                $data['wettkampf_tag_id'] = $wk_tag_id;
                $data['mannschaft_id']    = $mann_id;
                $data['quelle']           = 'anwesenheit';
            } else {
                $data['quelle']           = 'manuell';
            }
            $wpdb->insert( $p . 'lsv07i_abr_wettkampf', $data );
        }
        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    public static function delete_wettkampf() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_wettkampf WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Nicht gefunden.' ] );
        self::assert_owns( $row['abrechnung_id'] );

        // Automatisch erzeugte Einträge (aus der Wettkampf-Anwesenheit) werden
        // nur als gelöscht markiert, damit Statuswechsel sie nicht erneut anlegen.
        if ( ( $row['quelle'] ?? 'manuell' ) === 'anwesenheit' ) {
            $wpdb->update( $p . 'lsv07i_abr_wettkampf',
                [ 'manuell_geloescht' => 1 ],
                [ 'id' => $id, 'abrechnung_id' => (int) $row['abrechnung_id'] ], [ '%d' ], [ '%d', '%d' ]
            );
        } else {
            $wpdb->delete( $p . 'lsv07i_abr_wettkampf',
                [ 'id' => $id, 'abrechnung_id' => (int) $row['abrechnung_id'] ], [ '%d', '%d' ] );
        }
        wp_send_json_success( self::load_abrechnung( $row['abrechnung_id'] ) );
    }

    // ── Kilometer ─────────────────────────────────────────────────────────────
    public static function save_kilometer() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p            = $wpdb->prefix;
        $abr_id       = absint( $_POST['abrechnung_id'] ?? 0 );
        $id           = absint( $_POST['id'] ?? 0 );
        $datum        = sanitize_text_field( $_POST['datum'] ?? '' );
        $beschreibung = sanitize_text_field( $_POST['beschreibung'] ?? '' );
        $km_einfach   = round( (float) ( $_POST['km'] ?? 0 ), 2 );
        $tage         = max( 1, absint( $_POST['tage'] ?? 1 ) );
        // Eingegeben wird die einfache Strecke. Pro gefahrenem Tag fallen eine
        // Hin- und eine Rückfahrt an, daher: einfache Strecke × 2 × Anzahl Tage.
        $km           = round( $km_einfach * 2 * $tage, 2 );

        if ( ! $abr_id || ! $datum || $km_einfach <= 0 ) wp_send_json_error( [ 'message' => 'Pflichtfelder fehlen.' ] );
        self::assert_owns( $abr_id );

        $km_satz = (float) LSV07I_DB::get_config( 'km_satz', 0.30 );
        $km_min  = (float) LSV07I_DB::get_config( 'km_min', 0 );

        // Mindestkilometer beziehen sich auf die einfache Strecke
        if ( $km_min > 0 && $km_einfach < $km_min ) {
            wp_send_json_error( [ 'message' => "Mindestens {$km_min} km erforderlich (aktuell: {$km_einfach} km)." ] );
        }

        $betrag = round( $km * $km_satz, 2 );
        $data   = [ 'datum' => $datum, 'beschreibung' => $beschreibung,
                    'km' => $km, 'tage' => $tage, 'satz' => $km_satz, 'betrag' => $betrag ];
        $formats = [ '%s', '%s', '%f', '%d', '%f', '%f' ];

        if ( $id ) {
            self::assert_zeile( 'lsv07i_abr_kilometer', $id, $abr_id );
            $wpdb->update( $p . 'lsv07i_abr_kilometer', $data, [ 'id' => $id, 'abrechnung_id' => $abr_id ], $formats, [ '%d', '%d' ] );
        } else {
            $data['abrechnung_id'] = $abr_id;
            $formats[]             = '%d';
            $wpdb->insert( $p . 'lsv07i_abr_kilometer', $data, $formats );
        }
        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    public static function delete_kilometer() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT abrechnung_id FROM {$p}lsv07i_abr_kilometer WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $row ) wp_send_json_error( [ 'message' => 'Nicht gefunden.' ] );
        self::assert_owns( $row['abrechnung_id'] );
        $wpdb->delete( $p . 'lsv07i_abr_kilometer',
            [ 'id' => $id, 'abrechnung_id' => (int) $row['abrechnung_id'] ], [ '%d', '%d' ] );
        wp_send_json_success( self::load_abrechnung( $row['abrechnung_id'] ) );
    }

    // ── Einreichen / Genehmigen / Zurückgeben ─────────────────────────────────
    public static function einreichen() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $abr    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abrechnung WHERE id = %d LIMIT 1", $abr_id
        ), ARRAY_A );

        if ( ! $abr || ( ! LSV07I_Access::is_admin() && (int) $abr['trainer_id'] !== LSV07I_Access::get_trainer_id() ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ] );
        }
        if ( ! self::ist_bearbeitbar( $abr['abr_status'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung kann nicht mehr eingereicht werden.' ] );
        }

        // Erst festschreiben (solange noch offen, mit den nachgezogenen
        // Stunden), dann den Stand wechseln: Der Wart prüft genau diesen Betrag.
        self::festschreiben( $abr_id );
        $wpdb->update( $p . 'lsv07i_abrechnung',
            [ 'abr_status' => 'eingereicht', 'eingereicht_am' => current_time( 'mysql' ) ],
            [ 'id' => $abr_id ], [ '%s', '%s' ], [ '%d' ]
        );

        $mail_sw = LSV07I_DB::get_config( 'mail_schwimmwart', get_option( 'admin_email' ) );
        $trainer = LSV07I_DB::get_trainer_by_user( get_current_user_id() );
        $name    = $trainer ? $trainer['name'] : wp_get_current_user()->display_name;
        LSV07I_Mail::send( $mail_sw,
            "Neue Abrechnung eingereicht – $name",
            "Hallo,\n\nDer Trainer $name hat seine Abrechnung für {$abr['quartal']} {$abr['jahr']} eingereicht.\n\nBitte im internen Bereich unter Verwaltung prüfen.\n\nAutomatische Benachrichtigung.",
            'mail_abr_schwimmwart'
        );

        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    /*
     * Genehmigen: nur mit dem Recht dazu (steht in den Vorlagen aller drei
     * Warte; das Tor 'verwaltung' allein liess auch reine Kassen-Leser
     * genehmigen), nur aus „eingereicht" und nie die eigene Abrechnung.
     */
    public static function genehmigen() {
        LSV07I_Access::check( 'verwaltung' );
        if ( ! LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_GENEHMIGEN ) ) {
            wp_send_json_error( [ 'message' => 'Zum Genehmigen fehlt die Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $vorher = self::abr_mit_trainer( $abr_id );
        self::nicht_eigene( $vorher );
        if ( $vorher['abr_status'] !== 'eingereicht' ) {
            wp_send_json_error( [ 'message' => 'Genehmigt werden kann nur eine eingereichte Abrechnung.' ], 409 );
        }

        self::festschreiben( $abr_id );
        $wpdb->update( $p . 'lsv07i_abrechnung',
            [ 'abr_status' => 'genehmigt', 'genehmigt_am' => current_time( 'mysql' ) ],
            [ 'id' => $abr_id, 'abr_status' => 'eingereicht' ], [ '%s', '%s' ], [ '%d', '%s' ]
        );
        $abr = self::load_abrechnung( $abr_id );
        self::protokoll( 'abrechnung.genehmigen', $abr,
            sprintf( 'Gesamt %.2f €, Stundensatz %.2f €', $abr['gesamt'], $abr['stundensatz'] ) );

        // Mail an Kassenwart
        LSV07I_Mail::abrechnung_genehmigt_kassenwart(
            $abr['trainer_name'] ?? '',
            $abr['quartal'], $abr['jahr'], $abr['gesamt'] ?? 0
        );
        // Mail an Trainer
        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainer WHERE id = %d LIMIT 1", $abr['trainer_id']
        ), ARRAY_A );
        if ( $trainer && $trainer['email'] ) {
            LSV07I_Mail::abrechnung_status_trainer(
                $trainer['email'], $trainer['name'], 'genehmigt',
                $abr['quartal'], $abr['jahr']
            );
        }
        wp_send_json_success( $abr );
    }

    /*
     * Zurückgeben: nur mit dem Recht dazu, nur aus „eingereicht" oder
     * „genehmigt" — und nicht, wenn schon bezahlt ist: Dann gibt es nichts
     * mehr zu korrigieren, was nicht auch die Zahlung beträfe.
     */
    public static function zurueckgeben() {
        LSV07I_Access::check( 'verwaltung' );
        if ( ! LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_ZURUECKGEBEN ) ) {
            wp_send_json_error( [ 'message' => 'Zum Zurückgeben fehlt die Berechtigung.' ], 403 );
        }
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $grund  = sanitize_textarea_field( $_POST['grund'] ?? '' );
        if ( ! $grund ) wp_send_json_error( [ 'message' => 'Bitte einen Rückgabe-Grund angeben.' ] );

        $vorher = self::abr_mit_trainer( $abr_id );
        if ( ! in_array( $vorher['abr_status'], [ 'eingereicht', 'genehmigt' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Zurückgeben lässt sich nur eine eingereichte oder genehmigte Abrechnung.' ], 409 );
        }
        if ( ! empty( $vorher['bezahlt'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist bereits bezahlt und kann nicht zurückgegeben werden.' ], 409 );
        }

        $wpdb->update( $p . 'lsv07i_abrechnung',
            [ 'abr_status' => 'zurueck', 'rueckgabe_grund' => $grund ],
            [ 'id' => $abr_id ], [ '%s', '%s' ], [ '%d' ]
        );
        self::festschreibung_loesen( $abr_id );

        $abr     = self::load_abrechnung( $abr_id );
        self::protokoll( 'abrechnung.zurueckgeben', $abr, 'Aus „' . $vorher['abr_status'] . '": ' . $grund );
        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_trainer WHERE id = %d LIMIT 1", $abr['trainer_id']
        ), ARRAY_A );
        if ( $trainer && $trainer['email'] ) {
            LSV07I_Mail::abrechnung_status_trainer(
                $trainer['email'], $trainer['name'], 'zurueck',
                $abr['quartal'], $abr['jahr'], $grund
            );
        }
        wp_send_json_success( $abr );
    }

    public static function get_alle() {
        LSV07I_Access::check( 'verwaltung' );  // schwimmwart ODER finanzwart
        global $wpdb;
        $p      = $wpdb->prefix;
        $status = sanitize_text_field( $_POST['status'] ?? '' );
        $where  = $status ? $wpdb->prepare( 'WHERE a.abr_status = %s', $status ) : 'WHERE 1=1';

        /* Archiv: Standardmäßig zeigt die Liste NUR die nicht archivierten.
           Mit archiv=1 kommt umgekehrt nur das Archiv. Die Daten bleiben in
           beiden Fällen unangetastet — archivieren blendet aus, mehr nicht.

           Gefiltert wird nur, wenn die Spalte auch wirklich da ist. Sonst
           wäre die Abfrage ungültig und die ganze Verwaltungsliste bliebe
           leer — wegen einer Kleinigkeit, die nur ausblenden soll. Ohne
           Spalte gibt es eben noch kein Archiv: dann steht alles in der
           normalen Liste, und das Archiv ist leer. */
        $archiv = ! empty( $_POST['archiv'] ) ? 1 : 0;
        if ( self::archiv_spalte_da() ) {
            $where .= $archiv
                ? ' AND COALESCE(a.archiviert, 0) = 1'
                : ' AND COALESCE(a.archiviert, 0) = 0';
        } elseif ( $archiv ) {
            wp_send_json_success( [] );
        }

        /* trainer_aktiv wird mitgeliefert, damit die Liste eine Abrechnung
           kennzeichnen kann, die an einem deaktivierten Trainer-Profil hängt.
           Genau so sehen die "doppelten" Abrechnungen eines Quartals aus:
           dieselbe Person, zwei Profile, eines davon stillgelegt — und die
           daran hängende Abrechnung wird vom Trainer nie mehr geladen. */
        $rows = $wpdb->get_results(
            "SELECT a.*, t.name AS trainer_name, t.aktiv AS trainer_aktiv
               FROM {$p}lsv07i_abrechnung a
          LEFT JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
              $where ORDER BY a.eingereicht_am DESC, a.erstellt_am DESC",
            ARRAY_A
        );
        if ( ! $rows ) { wp_send_json_success( [] ); }

        /* Auch die Verwaltungsliste zieht die Stunden nach — sonst stünden
           dort die alten Summen, bis jemand die Abrechnung einmal öffnet,
           und der Wart prüfte nach einer Zahl, die sich beim Anklicken
           ändert. Nur offene Abrechnungen sind betroffen; das sind
           höchstens so viele wie Trainer. */
        foreach ( $rows as $r ) self::stunden_nachziehen( $r );

        /* Performance: Frueher lief hier pro Zeile load_abrechnung() mit je
           5 Einzelqueries (N+1-Problem — bei 100 Abrechnungen 500+ Queries).
           Jetzt werden alle Details fuer ALLE Abrechnungen in 4 Batch-Queries
           geholt und in PHP zugeordnet; die Berechnung ist identisch zu
           load_abrechnung(), nur eben ueber das gesamte Set.               */
        $ids    = array_map( fn( $r ) => (int) $r['id'], $rows );
        $ids_in = implode( ',', $ids );
        $tids   = array_unique( array_map( fn( $r ) => (int) $r['trainer_id'], $rows ) );
        $tids_in = implode( ',', $tids ?: [ 0 ] );

        $group = function( $list ) {
            $out = [];
            foreach ( (array) $list as $r ) { $out[ (int) $r['abrechnung_id'] ][] = $r; }
            return $out;
        };
        $tage = $group( $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_abr_trainingstage
              WHERE abrechnung_id IN ($ids_in) AND manuell_geloescht = 0
           ORDER BY datum ASC", ARRAY_A ) );
        $wk = $group( $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_abr_wettkampf
              WHERE abrechnung_id IN ($ids_in) AND manuell_geloescht = 0
           ORDER BY datum ASC", ARRAY_A ) );
        $km = $group( $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_abr_kilometer
              WHERE abrechnung_id IN ($ids_in) ORDER BY datum ASC", ARRAY_A ) );
        $sonder = $group( $wpdb->get_results(
            "SELECT * FROM {$p}lsv07i_abr_sonder
              WHERE abrechnung_id IN ($ids_in) AND manuell_geloescht = 0
           ORDER BY datum ASC", ARRAY_A ) );

        $satz_rows = $wpdb->get_results(
            "SELECT trainer_id, stundensatz FROM {$p}lsv07i_trainer_stammdaten
              WHERE trainer_id IN ($tids_in)", ARRAY_A );
        $saetze = [];
        foreach ( (array) $satz_rows as $sr ) { $saetze[ (int) $sr['trainer_id'] ] = (float) $sr['stundensatz']; }

        foreach ( $rows as &$row ) {
            $id  = (int) $row['id'];
            $row['trainingstage'] = $tage[ $id ] ?? [];
            $row['wettkampf']     = $wk[ $id ] ?? [];
            $row['kilometer']     = $km[ $id ] ?? [];
            $row['sonder']        = $sonder[ $id ] ?? [];

            $stundensatz      = self::satz_wirksam( $row, $saetze[ (int) $row['trainer_id'] ] ?? 0.0 );
            $training_stunden = (float) array_sum( array_column( $row['trainingstage'], 'stunden' ) );
            $training_betrag  = round( $training_stunden * $stundensatz, 2 );

            $anzahl_mit_vorbereitung = 0;
            foreach ( $row['trainingstage'] as $t ) {
                if ( ! empty( $t['vorbereitung_aktiv'] ) ) $anzahl_mit_vorbereitung++;
            }
            $vorbereitung_stunden = round( $anzahl_mit_vorbereitung * 0.25, 2 );
            $vorbereitung_betrag  = round( $vorbereitung_stunden * $stundensatz, 2 );

            $wettkampf_betrag = (float) array_sum( array_column( $row['wettkampf'], 'betrag' ) );
            $km_betrag        = (float) array_sum( array_column( $row['kilometer'], 'betrag' ) );
            $sonder_stunden   = (float) array_sum( array_column( $row['sonder'], 'stunden' ) );
            $sonder_betrag    = round( $sonder_stunden * $stundensatz, 2 );

            $row['stundensatz']             = $stundensatz;
            $row['training_stunden']        = round( $training_stunden, 2 );
            $row['training_betrag']         = $training_betrag;
            $row['anzahl_trainings']        = count( $row['trainingstage'] );
            $row['anzahl_mit_vorbereitung'] = $anzahl_mit_vorbereitung;
            $row['vorbereitung_stunden']    = $vorbereitung_stunden;
            $row['vorbereitung_betrag']     = $vorbereitung_betrag;
            $row['wettkampf_betrag']        = round( $wettkampf_betrag, 2 );
            $row['km_betrag']               = round( $km_betrag, 2 );
            $row['sonder_stunden']          = round( $sonder_stunden, 2 );
            $row['sonder_betrag']           = $sonder_betrag;
            // Wie load_abrechnung(): festgeschrieben vor gerechnet.
            $row['gesamt_gerechnet']        = round( $training_betrag + $vorbereitung_betrag + $wettkampf_betrag + $km_betrag + $sonder_betrag, 2 );
            $row['gesamt']                  = self::gesamt_wirksam( $row, $row['gesamt_gerechnet'] );
        }
        unset( $row );
        wp_send_json_success( $rows );
    }

    // ── Stammdaten ────────────────────────────────────────────────────────────
    /*
     * Der Stundensatz ist keine Angabe des Trainers, sondern eine Vorgabe
     * des Vereins. Vorher setzte jeder Trainer hier seinen eigenen Satz —
     * und weil die Beträge live gerechnet wurden, wirkte er rückwirkend
     * auf alle Quartale.
     *
     * Jetzt:
     *  • Den Satz eines ANDEREN Trainers setzen Admin, Finanzwart und
     *    Schwimmwart (Parameter trainer_id; dabei ändert sich nur der Satz).
     *  • Den EIGENEN Satz setzt nur ein Administrator — sonst legte ein
     *    Wart, der selbst trainiert, seinen Satz selbst fest.
     *  • Alle anderen speichern ihre Bankdaten; ein mitgeschickter Satz
     *    wird nicht übernommen (die Oberfläche schickt ihn immer mit).
     */
    public static function save_stammdaten() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;
        $p          = $wpdb->prefix;

        $ziel = absint( $_POST['trainer_id'] ?? 0 );
        $eigen = self::get_or_create_trainer_id();
        if ( $ziel && $ziel !== (int) $eigen ) {
            self::stundensatz_fremd_setzen( $ziel );
            return;
        }
        $trainer_id = $eigen;
        if ( ! $trainer_id ) wp_send_json_error( [ 'message' => 'Kein Trainer-Profil gefunden und konnte nicht erstellt werden.' ] );

        $alt_satz = $wpdb->get_var( $wpdb->prepare(
            "SELECT stundensatz FROM {$p}lsv07i_trainer_stammdaten WHERE trainer_id = %d LIMIT 1", $trainer_id ) );
        $alt_satz = $alt_satz === null ? 0.0 : (float) $alt_satz;

        $iban            = sanitize_text_field( $_POST['iban']            ?? '' );
        $bic             = sanitize_text_field( $_POST['bic']             ?? '' );
        $empfaenger_name = sanitize_text_field( $_POST['empfaenger_name'] ?? '' );
        $strasse         = sanitize_text_field( $_POST['strasse']         ?? '' );
        $plz             = sanitize_text_field( $_POST['plz']             ?? '' );
        $ort             = sanitize_text_field( $_POST['ort']             ?? '' );
        $satz_gewuenscht = round( (float) ( $_POST['stundensatz'] ?? 0 ), 2 );
        $darf_satz       = LSV07I_Access::is_admin();
        $stundensatz     = $darf_satz ? $satz_gewuenscht : $alt_satz;
        if ( $stundensatz < 0 ) wp_send_json_error( [ 'message' => 'Der Stundensatz darf nicht negativ sein.' ] );

        $iban_clean = strtoupper( preg_replace( '/\s+/', '', $iban ) );
        if ( $iban_clean && ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban_clean ) ) {
            wp_send_json_error( [ 'message' => 'Bitte eine gültige IBAN eingeben.' ] );
        }
        $bic_clean = strtoupper( preg_replace( '/\s+/', '', $bic ) );
        if ( $bic_clean && ! preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic_clean ) ) {
            wp_send_json_error( [ 'message' => 'Bitte eine gültige BIC eingeben (8 oder 11 Zeichen).' ] );
        }

        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_trainer_stammdaten
                (trainer_id, iban, bic, empfaenger_name, strasse, plz, ort, stundensatz)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %f)
             ON DUPLICATE KEY UPDATE
                iban             = VALUES(iban),
                bic              = VALUES(bic),
                empfaenger_name  = VALUES(empfaenger_name),
                strasse          = VALUES(strasse),
                plz              = VALUES(plz),
                ort              = VALUES(ort),
                stundensatz      = VALUES(stundensatz)",
            $trainer_id, $iban_clean, $bic_clean, $empfaenger_name,
            $strasse, $plz, $ort, $stundensatz
        ) );

        if ( $result === false ) {
            error_log( 'LSV07I abrechnung DB: ' . $wpdb->last_error );
            wp_send_json_error( [ 'message' => 'Datenbankfehler. Bitte erneut versuchen.' ] );
        }
        if ( abs( $stundensatz - $alt_satz ) >= 0.005 ) {
            self::stundensatz_protokoll( $trainer_id, $alt_satz, $stundensatz );
        }

        $antwort = [ 'stundensatz' => $stundensatz ];
        if ( ! $darf_satz && abs( $satz_gewuenscht - $alt_satz ) >= 0.005 ) {
            $antwort['hinweis'] = 'Der Stundensatz wird vom Verein festgelegt und wurde nicht geändert.';
        }
        wp_send_json_success( $antwort );
    }

    /** Stundensatz eines anderen Trainers setzen — nur Admin, Finanzwart, Schwimmwart. */
    private static function stundensatz_fremd_setzen( $trainer_id ) {
        if ( ! ( LSV07I_Access::is_admin() || LSV07I_Access::is_finanzwart() || LSV07I_Access::is_schwimmwart() ) ) {
            wp_send_json_error( [ 'message' => 'Den Stundensatz anderer Trainer dürfen nur Verwaltung und Administration setzen.' ], 403 );
        }
        global $wpdb;
        $p = $wpdb->prefix;
        $da = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}lsv07i_trainer WHERE id = %d", $trainer_id ) );
        if ( ! $da ) wp_send_json_error( [ 'message' => 'Trainer-Profil nicht gefunden.' ], 404 );
        $satz = round( (float) ( $_POST['stundensatz'] ?? -1 ), 2 );
        if ( $satz < 0 ) wp_send_json_error( [ 'message' => 'Bitte einen gültigen Stundensatz angeben.' ] );

        $alt = $wpdb->get_var( $wpdb->prepare(
            "SELECT stundensatz FROM {$p}lsv07i_trainer_stammdaten WHERE trainer_id = %d LIMIT 1", $trainer_id ) );
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_trainer_stammdaten (trainer_id, stundensatz) VALUES (%d, %f)
             ON DUPLICATE KEY UPDATE stundensatz = VALUES(stundensatz)", $trainer_id, $satz ) );
        if ( $ok === false ) {
            error_log( 'LSV07I abrechnung DB: ' . $wpdb->last_error );
            wp_send_json_error( [ 'message' => 'Datenbankfehler. Bitte erneut versuchen.' ] );
        }
        self::stundensatz_protokoll( $trainer_id, $alt === null ? 0.0 : (float) $alt, $satz );
        wp_send_json_success( [ 'stundensatz' => $satz ] );
    }

    private static function stundensatz_protokoll( $trainer_id, $alt, $neu ) {
        if ( ! class_exists( 'LSV07I_Log' ) ) return;
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}lsv07i_trainer WHERE id = %d", $trainer_id ) );
        LSV07I_Log::write( 'abrechnung.stundensatz', [
            'bereich'   => 'Abrechnung',
            'ziel_typ'  => 'trainer',
            'ziel_id'   => (int) $trainer_id,
            'ziel_name' => $name ?: 'Trainer ' . (int) $trainer_id,
            'details'   => sprintf( 'Stundensatz %.2f € → %.2f €', $alt, $neu ),
        ] );
    }

    public static function get_stammdaten() {
        LSV07I_Access::check( 'trainer' );
        global $wpdb;

        // get_or_create stellt sicher dass ein Trainer-Profil existiert (wichtig für Admins)
        $trainer_id = self::get_or_create_trainer_id();

        $config = [
            'km_satz'      => (float) LSV07I_DB::get_config( 'km_satz', 0.30 ),
            'km_min'       => (float) LSV07I_DB::get_config( 'km_min', 0 ),
            'wk_pauschale' => (float) LSV07I_DB::get_config( 'wk_pauschale', 25 ),
        ];

        if ( ! $trainer_id ) {
            wp_send_json_success( [ 'stammdaten' => [], 'config' => $config ] );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07i_trainer_stammdaten WHERE trainer_id = %d LIMIT 1",
            $trainer_id
        ), ARRAY_A );

        wp_send_json_success( [ 'stammdaten' => $row ?: [], 'config' => $config ] );
    }

    // ── Hilfsfunktion: Darf in diese Abrechnung geschrieben werden? ──────────
    /*
     * Die EINE Prüfung vor jedem Schreiben in eine Abrechnung (Zeilen
     * anlegen, ändern, löschen):
     *
     *  1. Besitz: die eigene — oder Administrator. Früher durfte jeder mit
     *     Wart- oder Kassen-LESErecht in alle Abrechnungen schreiben; eine
     *     Oberfläche dafür gibt es nicht, nur den offenen Endpunkt.
     *  2. Stand: nur Entwurf oder zurückgegeben (self::BEARBEITBAR) — für
     *     alle, auch Administratoren. Wer eine eingereichte Abrechnung
     *     korrigieren will, gibt sie zurück; sonst stimmte der
     *     festgeschriebene Betrag nicht mehr mit den Zeilen überein.
     */
    public static function assert_owns( $abr_id ) {
        global $wpdb;
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT trainer_id, abr_status FROM {$wpdb->prefix}lsv07i_abrechnung WHERE id = %d LIMIT 1", $abr_id
        ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ], 404 );
        if ( ! LSV07I_Access::is_admin() && (int) $abr['trainer_id'] !== (int) LSV07I_Access::get_trainer_id() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        if ( ! self::ist_bearbeitbar( $abr['abr_status'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist bereits eingereicht und kann nicht mehr bearbeitet werden.' ], 409 );
        }
    }
    // ── Hilfsfunktion: Trainer-ID ermitteln, ggf. Auto-Profil für Admin ──────
    public static function get_or_create_trainer_id() {
        $trainer_id = LSV07I_Access::get_trainer_id();
        if ( $trainer_id ) return $trainer_id;

        // Noch kein Trainer-Profil: fuer alle anlegen, die ueberhaupt bis
        // hierher kommen durften -- Admins und Nutzer mit dem Recht "eigene
        // Abrechnung". Vorher legte NUR ein Admin eines an; wer die
        // Abrechnung allein ueber dieses Recht sah, lief deshalb dauerhaft in
        // "Kein Trainer-Profil gefunden". Dieselbe Ueberlegung wie bei der
        // Sonderabrechnung (siehe create_trainer_profile_for_current_user()).
        $darf_anlegen = LSV07I_Access::is_admin()
            || ( class_exists( 'LSV07I_Permissions' )
                 && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_EIGEN_READ ) );
        if ( ! $darf_anlegen ) return 0;

        return self::create_trainer_profile_for_current_user();
    }

    /**
     * Legt für den aktuell angemeldeten Nutzer ein Trainer-Profil an, sofern
     * noch keines existiert (Race-Condition-sicher per Nochmals-Prüfung).
     * Extrahiert aus get_or_create_trainer_id(), damit auch andere Aufrufer
     * (z.B. die Sonderabrechnung — deren Nutzer oft KEINE Admins sind und
     * dort bewusst kein Trainer-Profil brauchen, um trainieren zu dürfen)
     * dieselbe Anlage-Logik nutzen können, nachdem SIE selbst die passende
     * Berechtigung bereits geprüft haben.
     */
    public static function create_trainer_profile_for_current_user() {
        global $wpdb;
        $p       = $wpdb->prefix;
        $user    = wp_get_current_user();
        $name    = $user->display_name ?: $user->user_login;
        $email   = $user->user_email;
        $user_id = $user->ID;

        // Nochmals prüfen ob inzwischen eines existiert
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_trainer WHERE wp_user_id = %d AND aktiv = 1
              ORDER BY id ASC LIMIT 1",
            $user_id
        ) );
        if ( $existing ) return (int) $existing;

        /* Hat das Konto ein DEAKTIVIERTES Profil, gibt es KEIN Profil —
           weder ein neues noch das alte wiederbelebt.

           Kein neues: Das war die Ursache doppelter Abrechnungen (zwei
           Profile am selben Konto, je Quartal zwei Abrechnungen, eine davon
           unerreichbar).

           Kein wiederbelebtes: Bis hierher reaktivierte sich ein vom Verein
           stillgelegter Trainer beim nächsten Öffnen der Abrechnung selbst
           und rechnete weiter ab. Deaktivieren ist eine Entscheidung der
           Verwaltung; zurücknehmen kann sie auch nur die Verwaltung. */
        $inaktiv = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_trainer WHERE wp_user_id = %d AND aktiv = 0
              ORDER BY id ASC LIMIT 1",
            $user_id
        ) );
        if ( $inaktiv ) return 0;

        // Anlegen
        $wpdb->insert( $p . 'lsv07i_trainer', [
            'name'       => $name,
            'email'      => $email,
            'wp_user_id' => $user_id,
            'aktiv'      => 1,
        ], [ '%s', '%s', '%d', '%d' ] );

        return $wpdb->insert_id ?: 0;
    }


    // ── Abrechnung löschen (Kassenwart/Admin) ─────────────────────────────────
    public static function delete_abrechnung() {
        LSV07I_Access::check( 'verwaltung' );
        // Löschen ist eine irreversible Aktion und daher ausschließlich
        // Administratoren vorbehalten – auch Kassenwart/Schwimmwart dürfen das nicht.
        if ( ! LSV07I_Access::is_admin_raw() ) {
            wp_send_json_error( [ 'message' => 'Nur Administratoren dürfen Abrechnungen löschen.' ] );
        }
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        if ( ! $abr_id ) wp_send_json_error( [ 'message' => 'Keine Abrechnung angegeben.' ] );
        $abr = self::abr_mit_trainer( $abr_id );
        $vorher = self::load_abrechnung( $abr_id );

        $wpdb->delete( $p . 'lsv07i_abr_trainingstage', [ 'abrechnung_id' => $abr_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_abr_wettkampf',    [ 'abrechnung_id' => $abr_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_abr_kilometer',     [ 'abrechnung_id' => $abr_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_abr_sonder',        [ 'abrechnung_id' => $abr_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_abrechnung',        [ 'id' => $abr_id ],            [ '%d' ] );
        self::protokoll( 'abrechnung.loeschen', $abr, sprintf( 'Stand „%s", %s, Gesamt %.2f €',
            $abr['abr_status'], ! empty( $abr['bezahlt'] ) ? 'bezahlt' : 'nicht bezahlt', $vorher['gesamt'] ?? 0 ) );

        wp_send_json_success();
    }

    /**
     * Gibt es die Archiv-Spalte schon? Angelegt wird sie beim Laden des
     * Plugins (lsv07-intern.php). Schlägt das dort einmal fehl — etwa weil
     * der Datenbankbenutzer die Tabelle nicht ändern darf —, soll die
     * Verwaltung trotzdem benutzbar bleiben statt mit einem SQL-Fehler
     * auszufallen. Einmal je Aufruf nachsehen genügt.
     */
    private static function archiv_spalte_da() {
        static $da = null;
        if ( $da !== null ) return $da;
        global $wpdb;
        $spalten = $wpdb->get_results(
            "SHOW COLUMNS FROM {$wpdb->prefix}lsv07i_abrechnung LIKE 'archiviert'" );
        $da = ! empty( $spalten );
        return $da;
    }

    // ── Abrechnung archivieren / zurückholen (Verwaltung) ─────────────────────
    /*
     * Archivieren blendet eine erledigte Abrechnung nur aus der Liste aus.
     * Es wird nichts gelöscht und nichts gesperrt: Die Abrechnung bleibt mit
     * allen Einträgen bestehen, taucht im Archiv auf, lässt sich dort öffnen
     * wie jede andere — und jederzeit zurückholen. Deshalb genügt dafür die
     * Verwaltungsberechtigung; das Löschen bleibt Administratoren vorbehalten.
     */
    public static function archivieren() {
        LSV07I_Access::check( 'verwaltung' );
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        // Ohne ausdrückliches archiviert=0 wird archiviert.
        $ziel   = isset( $_POST['archiviert'] ) ? ( ! empty( $_POST['archiviert'] ) ? 1 : 0 ) : 1;
        if ( ! $abr_id ) wp_send_json_error( [ 'message' => 'Keine Abrechnung angegeben.' ] );

        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, trainer_id, quartal, jahr, bereich FROM {$p}lsv07i_abrechnung WHERE id = %d LIMIT 1",
            $abr_id
        ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );

        if ( ! self::archiv_spalte_da() ) {
            wp_send_json_error( [ 'message' => 'Das Archiv steht noch nicht bereit — der Datenbank fehlt die Spalte "archiviert". Bitte das Plugin einmal deaktivieren und wieder aktivieren.' ] );
        }

        $geschrieben = $wpdb->update( $p . 'lsv07i_abrechnung', [
            'archiviert'     => $ziel,
            'archiviert_am'  => $ziel ? current_time( 'mysql' ) : null,
            'archiviert_von' => $ziel ? get_current_user_id() : 0,
        ], [ 'id' => $abr_id ], [ '%d', '%s', '%d' ], [ '%d' ] );

        if ( $geschrieben === false ) {
            error_log( 'LSV07I abr archiv: ' . $wpdb->last_error );
            wp_send_json_error( [ 'message' => 'Die Abrechnung konnte nicht archiviert werden (Datenbankfehler).' ] );
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            $name = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$p}lsv07i_trainer WHERE id = %d", $abr['trainer_id'] ) );
            LSV07I_Log::write( $ziel ? 'abrechnung.archivieren' : 'abrechnung.zurueckholen', [
                'bereich'   => 'Abrechnung',
                'ziel_typ'  => 'abrechnung',
                'ziel_id'   => $abr_id,
                'ziel_name' => ( $name ?: 'Trainer ' . (int) $abr['trainer_id'] )
                               . ' – ' . $abr['quartal'] . ' ' . $abr['jahr'],
            ] );
        }

        wp_send_json_success( [
            'archiviert' => $ziel,
            'message'    => $ziel ? 'Abrechnung archiviert.' : 'Abrechnung zurückgeholt.',
        ] );
    }

    // ── Abrechnung zuordnen (Admin) ───────────────────────────────────────────
    /*
     * Haengt eine Abrechnung an ein anderes Trainer-Profil und/oder ein
     * anderes Quartal, Jahr oder eine andere Sparte um.
     *
     * Wozu: Eine Abrechnung wird ueber (trainer_id, bereich, quartal, jahr)
     * gefunden. Stimmt eines davon nicht mit dem ueberein, was der Trainer
     * beim Oeffnen anfragt, bleibt sie unsichtbar -- typischerweise, weil sie
     * an einem inzwischen ersetzten Trainer-Profil haengt. Genau das laesst
     * sich hier geradeziehen, ohne die erfassten Eintraege zu verlieren.
     */
    public static function zuordnen() {
        LSV07I_Access::check( 'verwaltung' );
        // Wie beim Loeschen: Das aendert, wem eine Abrechnung gehoert und wer
        // dafuer Geld bekommt -- ausschliesslich Administratoren.
        if ( ! LSV07I_Access::is_admin_raw() ) {
            wp_send_json_error( [ 'message' => 'Nur Administratoren dürfen Abrechnungen zuordnen.' ] );
        }
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        if ( ! $abr_id ) wp_send_json_error( [ 'message' => 'Keine Abrechnung angegeben.' ] );

        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abrechnung WHERE id = %d LIMIT 1", $abr_id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );

        // Nicht angegebene Felder bleiben, wie sie sind.
        $trainer_id = absint( $_POST['trainer_id'] ?? $abr['trainer_id'] );
        $quartal    = sanitize_text_field( $_POST['quartal'] ?? $abr['quartal'] );
        $jahr       = absint( $_POST['jahr'] ?? $abr['jahr'] );
        $bereich    = sanitize_text_field( $_POST['bereich'] ?? $abr['bereich'] );

        if ( ! in_array( $quartal, [ 'Q1', 'Q2', 'Q3', 'Q4' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Quartal muss Q1, Q2, Q3 oder Q4 sein.' ] );
        }
        if ( $jahr < 2000 || $jahr > 2100 ) {
            wp_send_json_error( [ 'message' => 'Bitte ein Jahr zwischen 2000 und 2100 angeben.' ] );
        }
        if ( ! in_array( $bereich, [ 'schwimmen', 'triathlon', 'fitness', 'sonder' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Unbekannte Sparte.' ] );
        }

        // Auch inaktive Profile sind erlaubt -- man muss eine Abrechnung ja
        // gerade VON einem inaktiven Profil wegholen koennen.
        $trainer = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, display_name, aktiv FROM {$p}lsv07i_trainer WHERE id = %d LIMIT 1",
            $trainer_id
        ), ARRAY_A );
        if ( ! $trainer ) wp_send_json_error( [ 'message' => 'Trainer-Profil nicht gefunden.' ] );

        // Der eindeutige Schluessel der Tabelle wuerde ein Duplikat ohnehin
        // ablehnen -- aber mit einer Datenbankmeldung. Hier lieber vorher
        // pruefen und benennen, welche Abrechnung im Weg steht.
        $konflikt = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, abr_status FROM {$p}lsv07i_abrechnung
              WHERE trainer_id = %d AND bereich = %s AND quartal = %s AND jahr = %d AND id != %d
              LIMIT 1",
            $trainer_id, $bereich, $quartal, $jahr, $abr_id
        ), ARRAY_A );
        if ( $konflikt ) {
            wp_send_json_error( [ 'message' =>
                'Für ' . $trainer['name'] . ' gibt es bereits eine Abrechnung für '
                . $quartal . ' ' . $jahr . ' (' . $bereich . '), Nr. ' . (int) $konflikt['id']
                . '. Bitte diese zuerst löschen oder anders zuordnen.' ] );
        }

        $wpdb->update( $p . 'lsv07i_abrechnung', [
            'trainer_id' => $trainer_id,
            'bereich'    => $bereich,
            'quartal'    => $quartal,
            'jahr'       => $jahr,
        ], [ 'id' => $abr_id ], [ '%d', '%s', '%s', '%d' ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'abrechnung.zuordnen', [
                'bereich'   => 'Abrechnung',
                'ziel_typ'  => 'abrechnung',
                'ziel_id'   => $abr_id,
                'ziel_name' => $trainer['name'] . ' – ' . $quartal . ' ' . $jahr . ' (' . $bereich . ')',
            ] );
        }

        wp_send_json_success( [ 'message' => 'Abrechnung zugeordnet.' ] );
    }

    /**
     * Alle Trainer-Profile für den Zuordnen-Dialog — ausdrücklich INKLUSIVE
     * der deaktivierten, denn genau von denen holt man eine Abrechnung weg.
     * Je Profil steht dabei, wie viele Abrechnungen daran hängen und ob es
     * mit demselben WordPress-Konto ein weiteres Profil gibt.
     */
    public static function trainer_profile() {
        LSV07I_Access::check( 'verwaltung' );
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT t.id, t.name, t.display_name, t.aktiv, t.wp_user_id,
                    ( SELECT COUNT(*) FROM {$p}lsv07i_abrechnung a WHERE a.trainer_id = t.id ) AS abrechnungen,
                    ( SELECT COUNT(*) FROM {$p}lsv07i_trainer t2
                       WHERE t2.wp_user_id = t.wp_user_id AND t.wp_user_id > 0 ) AS profile_am_konto
               FROM {$p}lsv07i_trainer t
           ORDER BY t.aktiv DESC, t.display_name ASC, t.name ASC, t.id ASC",
            ARRAY_A
        );

        wp_send_json_success( $rows ?: [] );
    }

    // ── Als bezahlt markieren (Kassenwart/Admin) ──────────────────────────────
    /*
     * Bezahlt (und zurück): nur eine genehmigte Abrechnung, nur mit dem
     * Kassenrecht (siehe kasse_pruefen) und nie die eigene.
     */
    public static function bezahlt() {
        LSV07I_Access::check( 'verwaltung' );
        self::kasse_pruefen( LSV07I_Permissions::ABRECHNUNG_KASSE_BEZAHLT );
        global $wpdb;
        $p      = $wpdb->prefix;
        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        $status = absint( $_POST['bezahlt'] ?? 1 );
        if ( ! $abr_id ) wp_send_json_error( [ 'message' => 'Keine Abrechnung angegeben.' ] );
        $vorher = self::abr_mit_trainer( $abr_id );
        self::nicht_eigene( $vorher );
        if ( $vorher['abr_status'] !== 'genehmigt' ) {
            wp_send_json_error( [ 'message' => 'Als bezahlt gilt nur eine genehmigte Abrechnung.' ], 409 );
        }
        self::protokoll( $status ? 'abrechnung.bezahlt' : 'abrechnung.bezahlt_zurueck', self::load_abrechnung( $abr_id ),
            $status ? 'Als bezahlt markiert' : 'Bezahlt-Markierung zurückgenommen' );

        if ( $status ) {
            $wpdb->update( $p . 'lsv07i_abrechnung',
                [ 'bezahlt' => 1, 'bezahlt_am' => current_time( 'mysql' ) ],
                [ 'id' => $abr_id ], [ '%d', '%s' ], [ '%d' ]
            );
            // Mail an Trainer
            $abr2 = self::load_abrechnung( $abr_id );
            $trainer2 = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_trainer WHERE id = %d LIMIT 1", $abr2['trainer_id']
            ), ARRAY_A );
            if ( $trainer2 && $trainer2['email'] ) {
                LSV07I_Mail::abrechnung_status_trainer(
                    $trainer2['email'], $trainer2['name'], 'bezahlt',
                    $abr2['quartal'], $abr2['jahr']
                );
            }
        } else {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}lsv07i_abrechnung SET bezahlt=0, bezahlt_am=NULL WHERE id=%d", $abr_id
            ) );
        }

        wp_send_json_success( self::load_abrechnung( $abr_id ) );
    }

    // ── Jahresübersicht für Kassenwart ────────────────────────────────────────
    public static function jahresuebersicht() {
        LSV07I_Access::check( 'verwaltung' );
        self::kasse_pruefen( LSV07I_Permissions::ABRECHNUNG_KASSE_JAHRESUEBERSICHT );
        global $wpdb;
        $fest = self::fest_select();
        $p    = $wpdb->prefix;
        $jahr = absint( $_POST['jahr'] ?? date( 'Y' ) );

        // Alle Trainer mit mindestens einer Abrechnung in diesem Jahr
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                t.id AS trainer_id,
                t.name AS trainer_name,
                a.quartal,
                a.id AS abr_id,
                a.abr_status,
                a.bezahlt,
                a.bezahlt_am,
                a.genehmigt_am,
                ts.stundensatz,
                $fest
                COALESCE(
                    (SELECT SUM(tr.stunden)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS training_stunden,
                COALESCE(
                    (SELECT COUNT(*)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS anzahl_trainings,
                COALESCE(
                    (SELECT SUM(CASE WHEN tr.vorbereitung_aktiv = 1 THEN 1 ELSE 0 END)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS anzahl_mit_vorbereitung,
                COALESCE(
                    (SELECT SUM(wk.betrag)
                       FROM {$p}lsv07i_abr_wettkampf wk
                      WHERE wk.abrechnung_id = a.id AND wk.manuell_geloescht = 0),
                    0
                ) AS wettkampf_betrag,
                COALESCE(
                    (SELECT SUM(km.betrag)
                       FROM {$p}lsv07i_abr_kilometer km
                      WHERE km.abrechnung_id = a.id),
                    0
                ) AS km_betrag,
                COALESCE(
                    (SELECT SUM(so.stunden)
                       FROM {$p}lsv07i_abr_sonder so
                      WHERE so.abrechnung_id = a.id AND so.manuell_geloescht = 0),
                    0
                ) AS sonder_stunden
               FROM {$p}lsv07i_abrechnung a
               JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
          LEFT JOIN {$p}lsv07i_trainer_stammdaten ts ON ts.trainer_id = a.trainer_id
              WHERE a.jahr = %d
           ORDER BY t.name ASC, a.quartal ASC",
            $jahr
        ), ARRAY_A );

        // Zu einer Struktur zusammenfassen: trainer => quartal => daten
        $trainer_map = [];
        foreach ( $rows as $r ) {
            $tid  = $r['trainer_id'];
            $qrtl = $r['quartal'];
            if ( ! isset( $trainer_map[ $tid ] ) ) {
                $trainer_map[ $tid ] = [
                    'trainer_id'   => $tid,
                    'trainer_name' => $r['trainer_name'],
                    'quartale'     => [],
                    'gesamt'       => 0.0,
                    'bezahlt'      => 0.0,
                    'offen'        => 0.0,
                ];
            }
            $satz                = self::satz_wirksam( $r, (float) ( $r['stundensatz'] ?? 0 ) );
            $anzahl_trainings    = (int) $r['anzahl_trainings'];
            $anzahl_mit_vorb     = (int) $r['anzahl_mit_vorbereitung'];
            $training_btg        = round( (float) $r['training_stunden'] * $satz, 2 );
            $vorb_stunden        = round( $anzahl_mit_vorb * 0.25, 2 );
            $vorb_btg            = round( $vorb_stunden * $satz, 2 );
            $sonder_btg          = round( (float) $r['sonder_stunden'] * $satz, 2 );
            $gesamt              = self::gesamt_wirksam( $r,
                $training_btg + $vorb_btg + (float) $r['wettkampf_betrag'] + (float) $r['km_betrag'] + $sonder_btg );

            $neu = [
                'abr_id'                  => $r['abr_id'],
                'abr_status'              => $r['abr_status'],
                'bezahlt'                 => (int) $r['bezahlt'],
                'bezahlt_am'              => $r['bezahlt_am'],
                'genehmigt_am'            => $r['genehmigt_am'],
                'stundensatz'             => $satz,
                'training_stunden'        => round( (float) $r['training_stunden'], 2 ),
                'training_betrag'         => $training_btg,
                'anzahl_mit_vorbereitung' => $anzahl_mit_vorb,
                'vorbereitung_stunden'    => $vorb_stunden,
                'vorbereitung_betrag'     => $vorb_btg,
                'wettkampf_betrag'        => round( (float) $r['wettkampf_betrag'], 2 ),
                'km_betrag'               => round( (float) $r['km_betrag'], 2 ),
                'sonder_betrag'           => $sonder_btg,
                'gesamt'                  => $gesamt,
            ];

            if ( isset( $trainer_map[ $tid ]['quartale'][ $qrtl ] ) ) {
                // Zweiter Datensatz im selben Quartal (z.B. zusaetzlich zur
                // regulaeren Trainings-Abrechnung eine Sonderabrechnung) —
                // Betraege ADDIEREN statt zu ueberschreiben, sonst wuerde die
                // Quartalszelle nur die zuletzt verarbeitete Abrechnung
                // zeigen und Geld "verschwinden" lassen.
                $ex = $trainer_map[ $tid ]['quartale'][ $qrtl ];
                $trainer_map[ $tid ]['quartale'][ $qrtl ] = [
                    'abr_id'                  => $ex['abr_id'],
                    'abr_status'              => ( $ex['abr_status'] === 'eingereicht' || $neu['abr_status'] === 'eingereicht' ) ? 'eingereicht' : $ex['abr_status'],
                    'bezahlt'                 => ( $ex['bezahlt'] && $neu['bezahlt'] ) ? 1 : 0,
                    'bezahlt_am'              => $ex['bezahlt_am'] ?: $neu['bezahlt_am'],
                    'genehmigt_am'            => $ex['genehmigt_am'] ?: $neu['genehmigt_am'],
                    'stundensatz'             => $satz,
                    'training_stunden'        => round( $ex['training_stunden'] + $neu['training_stunden'], 2 ),
                    'training_betrag'         => round( $ex['training_betrag'] + $neu['training_betrag'], 2 ),
                    'anzahl_mit_vorbereitung' => $ex['anzahl_mit_vorbereitung'] + $neu['anzahl_mit_vorbereitung'],
                    'vorbereitung_stunden'    => round( $ex['vorbereitung_stunden'] + $neu['vorbereitung_stunden'], 2 ),
                    'vorbereitung_betrag'     => round( $ex['vorbereitung_betrag'] + $neu['vorbereitung_betrag'], 2 ),
                    'wettkampf_betrag'        => round( $ex['wettkampf_betrag'] + $neu['wettkampf_betrag'], 2 ),
                    'km_betrag'               => round( $ex['km_betrag'] + $neu['km_betrag'], 2 ),
                    'sonder_betrag'           => round( $ex['sonder_betrag'] + $neu['sonder_betrag'], 2 ),
                    'gesamt'                  => round( $ex['gesamt'] + $neu['gesamt'], 2 ),
                ];
            } else {
                $trainer_map[ $tid ]['quartale'][ $qrtl ] = $neu;
            }

            $trainer_map[ $tid ]['gesamt'] += $gesamt;
            if ( $r['bezahlt'] ) {
                $trainer_map[ $tid ]['bezahlt'] += $gesamt;
            } else {
                $trainer_map[ $tid ]['offen'] += $gesamt;
            }
        }

        // Rundung der Jahrestotals
        foreach ( $trainer_map as &$t ) {
            $t['gesamt']  = round( $t['gesamt'], 2 );
            $t['bezahlt'] = round( $t['bezahlt'], 2 );
            $t['offen']   = round( $t['offen'], 2 );
        }
        unset( $t );

        // Verfügbare Jahre für Dropdown
        $jahre = $wpdb->get_col(
            "SELECT DISTINCT jahr FROM {$p}lsv07i_abrechnung ORDER BY jahr DESC LIMIT 10"
        );

        wp_send_json_success( [
            'trainer' => array_values( $trainer_map ),
            'jahre'   => $jahre,
            'jahr'    => $jahr,
        ] );
    }

    /**
     * Kostenauswertung nach Mannschaft (fuer Vorstand/Kassenwart).
     * Summiert Trainings- und Vorbereitungskosten je Mannschaft und Quartal
     * eines Jahres. Basis sind ausschliesslich GENEHMIGTE Abrechnungen —
     * Entwuerfe und offene Einreichungen verfaelschen die Kostensicht.
     * Wettkampf- und Fahrtkosten haben keine Mannschaftszuordnung und werden
     * daher separat als Gesamtsumme ausgewiesen.
     */
    public static function kosten_mannschaft() {
        LSV07I_Access::check( 'verwaltung' );
        // Teil der Jahresübersicht — dasselbe Recht.
        self::kasse_pruefen( LSV07I_Permissions::ABRECHNUNG_KASSE_JAHRESUEBERSICHT );
        global $wpdb;
        $p    = $wpdb->prefix;
        $jahr = absint( $_POST['jahr'] ?? date( 'Y' ) );
        $satz = self::satz_sql();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tr.mannschaft_name,
                    a.quartal,
                    ROUND( SUM( tr.stunden * $satz ), 2 ) AS training,
                    ROUND( SUM( CASE WHEN tr.vorbereitung_aktiv = 1
                                     THEN 0.25 * $satz
                                     ELSE 0 END ), 2 ) AS vorbereitung
               FROM {$p}lsv07i_abr_trainingstage tr
               JOIN {$p}lsv07i_abrechnung a
                 ON a.id = tr.abrechnung_id AND a.jahr = %d AND a.abr_status = 'genehmigt'
          LEFT JOIN {$p}lsv07i_trainer_stammdaten ts ON ts.trainer_id = a.trainer_id
              WHERE tr.manuell_geloescht = 0
           GROUP BY tr.mannschaft_name, a.quartal
           ORDER BY tr.mannschaft_name ASC, a.quartal ASC",
            $jahr
        ), ARRAY_A );

        // Struktur: mannschaft => quartale-Map + Jahressumme
        $map = [];
        foreach ( (array) $rows as $r ) {
            $mn = $r['mannschaft_name'] !== '' ? $r['mannschaft_name'] : '(ohne Mannschaft)';
            if ( ! isset( $map[ $mn ] ) ) {
                $map[ $mn ] = [ 'mannschaft' => $mn, 'quartale' => [], 'gesamt' => 0.0 ];
            }
            $summe = round( (float) $r['training'] + (float) $r['vorbereitung'], 2 );
            $map[ $mn ]['quartale'][ $r['quartal'] ] = $summe;
            $map[ $mn ]['gesamt'] += $summe;
        }
        foreach ( $map as &$m ) { $m['gesamt'] = round( $m['gesamt'], 2 ); }
        unset( $m );

        // Nicht mannschaftsbezogene Kosten (Wettkampf + Fahrt) als Kontext
        $sonstige = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                ROUND( COALESCE( (SELECT SUM(wk.betrag)
                    FROM {$p}lsv07i_abr_wettkampf wk
                    JOIN {$p}lsv07i_abrechnung a2 ON a2.id = wk.abrechnung_id
                   WHERE a2.jahr = %d AND a2.abr_status = 'genehmigt' AND wk.manuell_geloescht = 0 ), 0 ), 2 ) AS wettkampf,
                ROUND( COALESCE( (SELECT SUM(km.betrag)
                    FROM {$p}lsv07i_abr_kilometer km
                    JOIN {$p}lsv07i_abrechnung a3 ON a3.id = km.abrechnung_id
                   WHERE a3.jahr = %d AND a3.abr_status = 'genehmigt' ), 0 ), 2 ) AS kilometer",
            $jahr, $jahr
        ), ARRAY_A );

        wp_send_json_success( [
            'jahr'       => $jahr,
            'mannschaften' => array_values( $map ),
            'sonstige'   => $sonstige ?: [ 'wettkampf' => 0, 'kilometer' => 0 ],
        ] );
    }

    // ── CSV-Export ────────────────────────────────────────────────────────────
    public static function csv_export() {
        LSV07I_Access::check( 'verwaltung' );
        self::kasse_pruefen( LSV07I_Permissions::ABRECHNUNG_KASSE_CSV_EXPORT );
        global $wpdb;
        $p    = $wpdb->prefix;
        $fest = self::fest_select();
        /* Die IBAN braucht nur, wer überweist: Kasse und Administration
           (can() gibt Administratoren immer recht). Alle anderen, die die
           Datei ziehen dürfen — etwa die Warte —, sehen sie maskiert. */
        $iban_voll = LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_KASSE_READ );
        $jahr = absint( $_POST['jahr'] ?? 0 );
        $where = $jahr
            ? $wpdb->prepare( 'AND a.jahr = %d', $jahr )
            : '';

        $rows = $wpdb->get_results(
            "SELECT
                t.name AS trainer_name,
                t.email AS trainer_email,
                a.quartal,
                a.jahr,
                a.abr_status,
                a.bezahlt,
                a.genehmigt_am,
                a.bezahlt_am,
                ts.stundensatz,
                ts.iban,
                $fest
                COALESCE(
                    (SELECT SUM(tr.stunden)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS training_stunden,
                COALESCE(
                    (SELECT SUM(CASE WHEN tr.vorbereitung_aktiv = 1 THEN 1 ELSE 0 END)
                       FROM {$p}lsv07i_abr_trainingstage tr
                      WHERE tr.abrechnung_id = a.id AND tr.manuell_geloescht = 0),
                    0
                ) AS anzahl_mit_vorbereitung,
                COALESCE(
                    (SELECT SUM(wk.betrag)
                       FROM {$p}lsv07i_abr_wettkampf wk
                      WHERE wk.abrechnung_id = a.id AND wk.manuell_geloescht = 0),
                    0
                ) AS wettkampf_betrag,
                COALESCE(
                    (SELECT SUM(km.betrag)
                       FROM {$p}lsv07i_abr_kilometer km
                      WHERE km.abrechnung_id = a.id),
                    0
                ) AS km_betrag,
                COALESCE(
                    (SELECT SUM(so.stunden)
                       FROM {$p}lsv07i_abr_sonder so
                      WHERE so.abrechnung_id = a.id AND so.manuell_geloescht = 0),
                    0
                ) AS sonder_stunden
               FROM {$p}lsv07i_abrechnung a
               JOIN {$p}lsv07i_trainer t ON t.id = a.trainer_id
          LEFT JOIN {$p}lsv07i_trainer_stammdaten ts ON ts.trainer_id = a.trainer_id
              WHERE a.abr_status IN ('genehmigt')
                $where
           ORDER BY t.name ASC, a.jahr ASC, a.quartal ASC",
            ARRAY_A
        );

        // Sichtbar machen statt still zu verschlucken: schlägt die Abfrage
        // fehl (z.B. fehlende Tabelle/Spalte auf dieser Installation), kam
        // vorher eine "erfolgreiche" aber leere CSV-Datei zurück — das sah
        // nach nichts aus, obwohl im Hintergrund ein echter Fehler vorlag.
        if ( $wpdb->last_error ) {
            wp_send_json_error( [ 'message' => LSV07I_Meldung::datenbank( 'Der CSV-Export konnte nicht gelesen werden.' ) ] );
        }
        if ( ! is_array( $rows ) ) $rows = [];

        // CSV bauen
        $csv  = "ï»¿"; // UTF-8 BOM für Excel
        $csv .= "Trainer;E-Mail;IBAN;Quartal;Jahr;Status;Stundensatz;Training Std.;Training €;Vorb. Std.;Vorb. €;Wettkampf €;Fahrtkosten €;Sonderabrechnung €;Gesamt €;Genehmigt am;Bezahlt;Bezahlt am\n";

        $fmt = function( $n ) { return number_format( (float) $n, 2, ',', '' ); };
        /* Jede Zelle läuft durch LSV07I_DB::csv_zelle(): Ein Trainername
           wie „=HYPERLINK(…)" würde in Excel sonst als Formel ausgeführt.
           Danach wird jede Zelle in Anführungszeichen gesetzt, damit ein
           Semikolon oder Zeilenumbruch im Namen die Spalten nicht verschiebt. */
        $zeile = function( array $zellen ) {
            return implode( ';', array_map( function ( $z ) {
                return '"' . str_replace( '"', '""', $z ) . '"';
            }, LSV07I_DB::csv_zeile( $zellen ) ) ) . "\n";
        };

        foreach ( $rows as $r ) {
            $satz             = self::satz_wirksam( $r, (float) ( $r['stundensatz'] ?? 0 ) );
            $anzahl_mit_vorb  = (int) $r['anzahl_mit_vorbereitung'];
            $training         = round( (float) $r['training_stunden'] * $satz, 2 );
            $vorb_stunden     = round( $anzahl_mit_vorb * 0.25, 2 );
            $vorb_betrag      = round( $vorb_stunden * $satz, 2 );
            $sonder_betrag    = round( (float) $r['sonder_stunden'] * $satz, 2 );
            $gesamt           = self::gesamt_wirksam( $r,
                $training + $vorb_betrag + (float) $r['wettkampf_betrag'] + (float) $r['km_betrag'] + $sonder_betrag );

            $csv .= $zeile( [
                (string) $r['trainer_name'],
                (string) ( $r['trainer_email'] ?? '' ),
                $iban_voll ? (string) ( $r['iban'] ?? '' ) : self::iban_maskiert( $r['iban'] ?? '' ),
                $r['quartal'],
                $r['jahr'],
                $r['abr_status'],
                $fmt( $satz ),
                $fmt( $r['training_stunden'] ),
                $fmt( $training ),
                $fmt( $vorb_stunden ),
                $fmt( $vorb_betrag ),
                $fmt( $r['wettkampf_betrag'] ),
                $fmt( $r['km_betrag'] ),
                $fmt( $sonder_betrag ),
                $fmt( $gesamt ),
                $r['genehmigt_am'] ? substr( $r['genehmigt_am'], 0, 10 ) : '',
                $r['bezahlt'] ? 'Ja' : 'Nein',
                $r['bezahlt_am'] ? substr( $r['bezahlt_am'], 0, 10 ) : '',
            ] );
        }

        wp_send_json_success( [ 'csv' => $csv, 'rows' => count( $rows ) ] );
    }

    /** IBAN bis auf die letzten vier Zeichen verdecken: „DE** **** **** **12 34". */
    public static function iban_maskiert( $iban ) {
        $iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
        if ( strlen( $iban ) < 8 ) return $iban === '' ? '' : '****';
        $sicht = substr( $iban, 0, 2 ) . str_repeat( '*', strlen( $iban ) - 6 ) . substr( $iban, -4 );
        return trim( chunk_split( $sicht, 4, ' ' ) );
    }


}