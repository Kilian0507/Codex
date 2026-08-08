<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Akte
 * -----------------
 * "Akte": vollständiges Dossier je Person für einen wählbaren Zeitraum —
 * eigenes Recht, zeigt ausschließlich Mitglieder der eigenen Mannschaften.
 * Der PDF-Export (clientseitig über jsPDF, siehe app.js) erzeugt je Person
 * genau eine Seite. Reine Lese-Ansicht — kein serverseitiger Datei-Endpunkt,
 * also keine neue Upload-/Download-Angriffsfläche.
 *
 * Enthaltene Angaben je Person:
 *   - Stammdaten: Name, Geburtsdatum, DSV-ID, Mannschaft(en)
 *   - Attest-Status (gültig/läuft ab/abgelaufen/keines) + Ablaufdatum
 *   - Primäre Kontaktperson (Name, Telefon, E-Mail)
 *   - Anwesenheit je Mannschaft im gewählten Zeitraum
 *   - Wettkampf-Teilnahmen im Zeitraum (aus abgegebenen Meldungen)
 *   - Aktuelle Bestzeiten
 *   - Freitext-Kommentar
 *
 * Datenquellen (bewusst dieselben wie die bestehende Anwesenheit/Bestzeiten-
 * Verwaltung, NICHT die neuere zentrale Personen-Tabelle — diese ist dort
 * noch nicht angebunden, siehe class-personen.php):
 *   - mv_swimmers                     Stammdaten, Attest, Kommentar (notes)
 *   - lsv07i_kontakte                 Kontaktpersonen
 *   - lsv07_gruppen                   Mannschaftsnamen
 *   - lsv07i_anwesenheit(+eintraege)  Anwesenheit, gruppiert nach der
 *                                      Mannschaft der jeweiligen Session
 *                                      (nicht nach der aktuellen "Heimat"-
 *                                      Mannschaft) — so werden auch Schwimmer
 *                                      korrekt abgebildet, die in mehreren
 *                                      Mannschaften mittrainieren.
 *   - lsv07i_meldung(_start)          Wettkampf-Teilnahmen: welche Strecken
 *                                      bei welchem Wettkampf gemeldet wurden,
 *                                      gefiltert auf Wettkämpfe, die in den
 *                                      Zeitraum fallen.
 *   - lsv07i_bestzeiten                Aktuelle Bestzeiten. Die Tabelle
 *                                      speichert kein Erzieltdatum, nur den
 *                                      letzten Importzeitpunkt — daher NICHT
 *                                      zeitraumgefiltert, sondern immer der
 *                                      aktuelle Stand.
 */

class LSV07I_Ajax_Akte {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_akte_mannschaften', [ __CLASS__, 'mannschaften' ] );
        add_action( 'wp_ajax_lsv07i_akte_daten',         [ __CLASS__, 'daten' ] );
    }

    /**
     * Mannschaften, die der aktuelle Nutzer einsehen darf: Admin/Schwimmwart
     * sehen alle Schwimmen-Mannschaften, alle anderen nur ihre eigenen
     * (über lsv07i_trainer_mannschaft — dieselbe Quelle, die auch den
     * Wettkampf-Zugriff eines Trainers bestimmt).
     */
    private static function erlaubte_mannschaft_ids() {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart() ) {
            return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$p}lsv07_gruppen" ) );
        }
        $trainer_id = LSV07I_Access::get_trainer_id();
        if ( ! $trainer_id ) return [];
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT mannschaft_id FROM {$p}lsv07i_trainer_mannschaft WHERE trainer_id = %d",
            $trainer_id
        ) ) );
    }

    /** Mannschaften für die Auswahl-Dropdown (nur die eigenen). */
    public static function mannschaften() {
        LSV07I_Access::check( 'sw_akte_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $ids = self::erlaubte_mannschaft_ids();
        if ( empty( $ids ) ) {
            wp_send_json_success( [ 'mannschaften' => [] ] );
        }
        $in   = implode( ',', $ids );
        $rows = $wpdb->get_results( "SELECT id, name FROM {$p}lsv07_gruppen WHERE id IN ($in) ORDER BY name ASC", ARRAY_A );
        wp_send_json_success( [ 'mannschaften' => $rows ?: [] ] );
    }

    /**
     * Hauptabfrage: alle Personen der (eigenen, ggf. auf eine einzelne
     * Mannschaft gefilterten) Mannschaften samt Anwesenheit im Zeitraum,
     * Bestzeiten und Kommentar.
     */
    public static function daten() {
        LSV07I_Access::check( 'sw_akte_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $von = sanitize_text_field( $_POST['von'] ?? '' );
        $bis = sanitize_text_field( $_POST['bis'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $von ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bis ) ) {
            wp_send_json_error( [ 'message' => 'Bitte einen gültigen Zeitraum (von/bis) angeben.' ] );
        }
        if ( $von > $bis ) {
            wp_send_json_error( [ 'message' => '"Von" darf nicht nach "Bis" liegen.' ] );
        }

        $erlaubt = self::erlaubte_mannschaft_ids();
        if ( empty( $erlaubt ) ) {
            wp_send_json_success( [ 'personen' => [], 'von' => $von, 'bis' => $bis ] );
        }

        // Client darf eine einzelne Mannschaft anfragen, aber niemals eine
        // außerhalb des eigenen Scopes — serverseitig geschnitten, nicht
        // dem Client vertraut.
        $angefragt = absint( $_POST['mannschaft_id'] ?? 0 );
        $scope = $angefragt ? array_values( array_intersect( $erlaubt, [ $angefragt ] ) ) : $erlaubt;
        if ( empty( $scope ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung für diese Mannschaft.' ] );
        }
        $scope_in = implode( ',', $scope );

        $swimmers = $wpdb->get_results(
            "SELECT id, first_name, last_name, birth_date, team_id, dsv_id, attest_expires, notes
               FROM {$p}mv_swimmers
              WHERE active = 1 AND team_id IN ($scope_in)
           ORDER BY last_name ASC, first_name ASC",
            ARRAY_A
        );
        if ( empty( $swimmers ) ) {
            wp_send_json_success( [ 'personen' => [], 'von' => $von, 'bis' => $bis ] );
        }
        $swimmer_ids = array_map( fn( $s ) => (int) $s['id'], $swimmers );
        $sw_in       = implode( ',', $swimmer_ids );

        $mann_rows = $wpdb->get_results( "SELECT id, name FROM {$p}lsv07_gruppen WHERE id IN ($scope_in)", ARRAY_A );
        $mann_name_by_id = [];
        foreach ( $mann_rows as $m ) $mann_name_by_id[ (int) $m['id'] ] = $m['name'];

        // Anwesenheit: pro Schwimmer × Session-Mannschaft × Status zählen,
        // begrenzt auf den gewählten Zeitraum UND den erlaubten Scope.
        $anw_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.teilnehmer_id AS swimmer_id, a.mannschaft_id, e.status, COUNT(*) AS anzahl
               FROM {$p}lsv07i_anwesenheit_eintraege e
               JOIN {$p}lsv07i_anwesenheit a ON a.id = e.anwesenheit_id
              WHERE e.teilnehmer_typ = 'schwimmer' AND a.ausgefallen = 0
                AND a.training_datum BETWEEN %s AND %s
                AND a.mannschaft_id IN ($scope_in)
                AND e.teilnehmer_id IN ($sw_in)
           GROUP BY e.teilnehmer_id, a.mannschaft_id, e.status",
            $von, $bis
        ), ARRAY_A );

        // Sessions je Mannschaft im Zeitraum (Nenner für die Quote).
        $sessions_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT mannschaft_id, COUNT(*) AS anzahl
               FROM {$p}lsv07i_anwesenheit
              WHERE ausgefallen = 0 AND training_datum BETWEEN %s AND %s
                AND mannschaft_id IN ($scope_in)
           GROUP BY mannschaft_id",
            $von, $bis
        ), ARRAY_A );
        $sessions_by_mann = [];
        foreach ( $sessions_rows as $r ) $sessions_by_mann[ (int) $r['mannschaft_id'] ] = (int) $r['anzahl'];

        // swimmer_id => mannschaft_id => [anwesend/abwesend/entschuldigt]
        $anw_by_swimmer = [];
        foreach ( $anw_rows as $r ) {
            $sid = (int) $r['swimmer_id'];
            $mid = (int) $r['mannschaft_id'];
            if ( ! isset( $anw_by_swimmer[ $sid ][ $mid ] ) ) {
                $anw_by_swimmer[ $sid ][ $mid ] = [ 'anwesend' => 0, 'abwesend' => 0, 'entschuldigt' => 0 ];
            }
            $anw_by_swimmer[ $sid ][ $mid ][ $r['status'] ] = (int) $r['anzahl'];
        }

        // Bestzeiten: aktueller Stand, siehe Klassendoc oben.
        $bz_rows = $wpdb->get_results(
            "SELECT swimmer_id, strecke, zeit_raw
               FROM {$p}lsv07i_bestzeiten
              WHERE swimmer_id IN ($sw_in)
           ORDER BY strecke ASC",
            ARRAY_A
        );
        $bz_by_swimmer = [];
        foreach ( $bz_rows as $r ) {
            $bz_by_swimmer[ (int) $r['swimmer_id'] ][] = [
                'strecke'  => $r['strecke'],
                'zeit_raw' => $r['zeit_raw'],
            ];
        }

        // Primäre Kontaktperson: erste je Schwimmer nach sort_order.
        $kt_rows = $wpdb->get_results(
            "SELECT swimmer_id, name, telefon, email
               FROM {$p}lsv07i_kontakte
              WHERE swimmer_id IN ($sw_in)
           ORDER BY swimmer_id ASC, sort_order ASC",
            ARRAY_A
        );
        $kontakt_by_swimmer = [];
        foreach ( $kt_rows as $r ) {
            $sid = (int) $r['swimmer_id'];
            if ( isset( $kontakt_by_swimmer[ $sid ] ) ) continue; // nur die primäre (erste)
            if ( ! $r['name'] && ! $r['telefon'] && ! $r['email'] ) continue;
            $kontakt_by_swimmer[ $sid ] = [
                'name'    => $r['name'],
                'telefon' => $r['telefon'],
                'email'   => $r['email'],
            ];
        }

        // Wettkampf-Teilnahmen aus abgegebenen Meldungen, gefiltert auf
        // Wettkämpfe, deren Datumsbereich den gewählten Zeitraum überschneidet.
        $wk_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ms.schwimmer_id, w.id AS wettkampf_id, w.name AS wettkampf_name, w.ort,
                    w.datum_von, w.datum_bis, ms.strecke, ms.meldezeit
               FROM {$p}lsv07i_meldung_start ms
               JOIN {$p}lsv07i_meldung m ON m.id = ms.meldung_id
               JOIN {$p}lsv07i_wettkampf w ON w.id = m.wettkampf_id
              WHERE ms.schwimmer_id IN ($sw_in)
                AND w.datum_von <= %s AND w.datum_bis >= %s
           ORDER BY w.datum_von ASC, ms.sortierung ASC",
            $bis, $von
        ), ARRAY_A );
        $wk_by_swimmer = [];
        foreach ( $wk_rows as $r ) {
            $sid = (int) $r['schwimmer_id'];
            $wid = (int) $r['wettkampf_id'];
            if ( ! isset( $wk_by_swimmer[ $sid ][ $wid ] ) ) {
                $wk_by_swimmer[ $sid ][ $wid ] = [
                    'name'      => $r['wettkampf_name'],
                    'ort'       => $r['ort'],
                    'datum_von' => $r['datum_von'],
                    'datum_bis' => $r['datum_bis'],
                    'strecken'  => [],
                ];
            }
            $wk_by_swimmer[ $sid ][ $wid ]['strecken'][] = [
                'strecke'   => $r['strecke'],
                'meldezeit' => $r['meldezeit'],
            ];
        }

        $personen = [];
        foreach ( $swimmers as $s ) {
            $sid      = (int) $s['id'];
            $heim_mid = (int) $s['team_id'];

            // Mannschaften mit Anwesenheitsdaten im Zeitraum, plus immer die
            // Heimat-Mannschaft (auch bei 0 Einträgen, damit sie sichtbar bleibt).
            $mann_ids = array_keys( $anw_by_swimmer[ $sid ] ?? [] );
            if ( ! in_array( $heim_mid, $mann_ids, true ) ) $mann_ids[] = $heim_mid;

            $mannschaften = [];
            foreach ( $mann_ids as $mid ) {
                if ( ! isset( $mann_name_by_id[ $mid ] ) ) continue; // außerhalb Scope
                $c = $anw_by_swimmer[ $sid ][ $mid ] ?? [ 'anwesend' => 0, 'abwesend' => 0, 'entschuldigt' => 0 ];
                $mannschaften[] = [
                    'mannschaft_id'   => $mid,
                    'name'            => $mann_name_by_id[ $mid ],
                    'anwesend'        => $c['anwesend'],
                    'abwesend'        => $c['abwesend'],
                    'entschuldigt'    => $c['entschuldigt'],
                    'sessions_gesamt' => $sessions_by_mann[ $mid ] ?? 0,
                ];
            }

            $personen[] = [
                'id'             => $sid,
                'name'           => trim( $s['first_name'] . ' ' . $s['last_name'] ),
                'geburtsdatum'   => $s['birth_date'],
                'dsv_id'         => $s['dsv_id'] ?: '',
                'attest_expires' => $s['attest_expires'] ?: '',
                'attest_status'  => LSV07I_DB::attest_status( $s['attest_expires'] ),
                'kontakt'        => $kontakt_by_swimmer[ $sid ] ?? null,
                'mannschaften'   => $mannschaften,
                'wettkaempfe'    => array_values( $wk_by_swimmer[ $sid ] ?? [] ),
                'bestzeiten'     => $bz_by_swimmer[ $sid ] ?? [],
                'kommentar'      => $s['notes'] ?: '',
            ];
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'akte.ansehen', [
                'bereich'   => 'Schwimmen',
                'ziel_typ'  => 'akte',
                'ziel_id'   => $angefragt ?: 0,
                'ziel_name' => 'Zeitraum ' . $von . ' – ' . $bis . ', ' . count( $personen ) . ' Personen',
            ] );
        }

        wp_send_json_success( [ 'personen' => $personen, 'von' => $von, 'bis' => $bis ] );
    }
}
