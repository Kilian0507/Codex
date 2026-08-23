<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Wettkampf
 * ---------------------
 * Verwaltung von Wettkämpfen und der Wettkampf-Anwesenheit.
 *
 * Ein Wettkampf (lsv07i_wettkampf) hat Name, Datumsbereich (von/bis),
 * teilnehmende Mannschaften (M:N) und pro Tag eine Zeile in
 * lsv07i_wettkampf_tage mit der geplanten Abschnittszahl.
 *
 * Pro Tag × Mannschaft wird eine Anwesenheits-Session erstellt
 * (lsv07i_wettkampf_anwesenheit). Darin sind Schwimmer und Trainer
 * als Einträge enthalten (lsv07i_wettkampf_anw_eintraege).
 * Standardmäßig steht jeder auf "abwesend".
 *
 * Sobald ein Trainer für einen Wettkampftag als "anwesend" markiert wird,
 * schreibt das System automatisch einen Eintrag in lsv07i_abr_wettkampf
 * (quelle = 'anwesenheit'). Wird er wieder auf abwesend/entschuldigt gesetzt,
 * wird der automatische Eintrag wieder entfernt.
 * Für Trainer kann pro Tag zusätzlich die Anzahl der begleiteten Abschnitte
 * erfasst werden (Default = geplante Abschnitte des Tages).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Wettkampf {

    public static function init() {
        $map = [
            // Wettkampf-Verwaltung
            'lsv07i_wk_list'              => 'list_all',
            'lsv07i_wk_get'               => 'get',
            'lsv07i_wk_save'              => 'save',
            'lsv07i_wk_delete'            => 'delete',
            // Wettkampf-Anwesenheit
            'lsv07i_wk_anw_get'           => 'anw_get',
            'lsv07i_wk_anw_save_eintrag'  => 'anw_save_eintrag',
            'lsv07i_wk_anw_save_abschnitte' => 'anw_save_abschnitte',
            // Statistik
            'lsv07i_wk_statistik'         => 'statistik',
            // Für "manuell hinzufügen" in der Abrechnung
            'lsv07i_wk_offene_tage'       => 'offene_tage',
            // Dokumente (Ausschreibung/Meldeergebnis/Protokoll)
            'lsv07i_wk_dok_upload'        => 'dok_upload',
            'lsv07i_wk_dok_delete'        => 'dok_delete',
            // Freigabe
            'lsv07i_wk_approve'           => 'approve',
            'lsv07i_wk_unapprove'         => 'unapprove',
            // Admin: feste Mail-Empfänger je Mailtyp (Admin → Mails).
            // Die früheren Erinnerungsadressen je Wettkampf gibt es nicht mehr —
            // Empfänger werden ausschließlich zentral im Admin-Bereich gepflegt.
            'lsv07i_wk_mail_empf_liste'   => 'mail_empfaenger_liste',
            'lsv07i_wk_mail_empf_save'    => 'mail_empfaenger_save',
            'lsv07i_wk_mail_empf_delete'  => 'mail_empfaenger_delete',
        ];
        foreach ( $map as $action => $method ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $method ] );
        }
        // Dokument-Download: alle Dokumente eines freigegebenen Wettkampfs
        // (Ausschreibung, Meldeergebnis, Protokoll) sind öffentlich (siehe
        // dok_download()) — daher zusätzlich als nopriv-Hook registriert.
        // Solange der Wettkampf NICHT freigegeben ist, verlangt die Methode
        // weiterhin Login + Leserecht, für jeden Dokumenttyp.
        add_action( 'wp_ajax_lsv07i_wk_dok_download',        [ __CLASS__, 'dok_download' ] );
        add_action( 'wp_ajax_nopriv_lsv07i_wk_dok_download',  [ __CLASS__, 'dok_download' ] );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  WETTKAMPF-VERWALTUNG
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Liste aller Wettkämpfe (optional gefiltert nach Mannschaft des Trainers,
     * Zeitraum, Jahr). Liefert Name, Datumsbereich, teilnehmende Mannschaften.
     */
    public static function list_all() {
        LSV07I_Access::check( 'sw_wk_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $jahr          = absint( $_POST['jahr'] ?? 0 );
        $nur_eigene    = ! empty( $_POST['nur_eigene'] );

        $where   = [ '1=1' ];
        if ( $jahr ) {
            $where[] = $wpdb->prepare( '(YEAR(w.datum_von) = %d OR YEAR(w.datum_bis) = %d)', $jahr, $jahr );
        }

        // Mannschaft-Filter über JOIN
        $join_mann = '';
        if ( $mannschaft_id ) {
            $join_mann = $wpdb->prepare(
                "JOIN {$p}lsv07i_wettkampf_mannschaft wm ON wm.wettkampf_id = w.id AND wm.mannschaft_id = %d",
                $mannschaft_id
            );
        } elseif ( $nur_eigene && ! LSV07I_Access::is_admin() && ! LSV07I_Access::is_schwimmwart() ) {
            // Trainer sieht nur Wettkämpfe seiner Mannschaften
            $trainer_id = LSV07I_Access::get_trainer_id();
            if ( $trainer_id ) {
                $join_mann = $wpdb->prepare(
                    "JOIN {$p}lsv07i_wettkampf_mannschaft wm ON wm.wettkampf_id = w.id
                     JOIN {$p}lsv07i_trainer_mannschaft tm
                          ON tm.mannschaft_id = wm.mannschaft_id AND tm.trainer_id = %d",
                    $trainer_id
                );
            }
        }

        $rows = $wpdb->get_results(
            "SELECT DISTINCT w.*
               FROM {$p}lsv07i_wettkampf w
                    $join_mann
              WHERE " . implode( ' AND ', $where ) . "
           ORDER BY w.datum_von DESC, w.id DESC",
            ARRAY_A
        );

        // Mannschaften und Tage je Wettkampf in EINEM Schwung laden (N+1 vermeiden)
        if ( ! empty( $rows ) ) {
            $wk_ids = array_map( fn( $r ) => (int) $r['id'], $rows );
            // Filter: nur positive Integer (Defense-in-Depth gegen SQL-Injection)
            $wk_ids = array_filter( $wk_ids, fn( $id ) => $id > 0 );
            if ( empty( $wk_ids ) ) { wp_send_json_success( $rows ); return; }
            $ids_in = implode( ',', $wk_ids );

            // Alle Mannschaften-Zuordnungen
            $mann_map = [];
            $mann_rows = $wpdb->get_results(
                "SELECT wm.wettkampf_id, g.id, g.name
                   FROM {$p}lsv07i_wettkampf_mannschaft wm
                   JOIN {$p}lsv07_gruppen g ON g.id = wm.mannschaft_id
                  WHERE wm.wettkampf_id IN ($ids_in)
               ORDER BY g.sort_order ASC, g.name ASC", ARRAY_A );
            foreach ( $mann_rows as $mr ) {
                $mann_map[ (int) $mr['wettkampf_id'] ][] = [ 'id' => $mr['id'], 'name' => $mr['name'] ];
            }

            // Alle Tage
            $tage_map = [];
            $tage_rows = $wpdb->get_results(
                "SELECT * FROM {$p}lsv07i_wettkampf_tage
                  WHERE wettkampf_id IN ($ids_in) ORDER BY datum ASC", ARRAY_A );
            foreach ( $tage_rows as $tr ) {
                $tage_map[ (int) $tr['wettkampf_id'] ][] = $tr;
            }

            // Vorhandene Dokumenttypen je Wettkampf (für den Status "Ausschreibung fehlt")
            $dok_map = [];
            $dok_rows = $wpdb->get_results(
                "SELECT wettkampf_id, typ FROM {$p}lsv07i_wettkampf_dokument
                  WHERE wettkampf_id IN ($ids_in)", ARRAY_A );
            foreach ( $dok_rows as $dr ) {
                $dok_map[ (int) $dr['wettkampf_id'] ][] = $dr['typ'];
            }

            foreach ( $rows as &$r ) {
                $r['mannschaften'] = $mann_map[ (int) $r['id'] ] ?? [];
                $r['tage']         = $tage_map[ (int) $r['id'] ] ?? [];
                $r['dokumente']    = $dok_map[ (int) $r['id'] ] ?? [];
            }
            unset( $r );
        }

        wp_send_json_success( $rows );
    }

    /**
     * Einen Wettkampf komplett laden (inkl. Mannschaften + Tage).
     */
    public static function get() {
        LSV07I_Access::check( 'sw_wk_read' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'ID fehlt.' ] );

        $wk = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $wk ) wp_send_json_error( [ 'message' => 'Wettkampf nicht gefunden.' ] );

        $wk['mannschaften'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.id, g.name
               FROM {$p}lsv07i_wettkampf_mannschaft wm
               JOIN {$p}lsv07_gruppen g ON g.id = wm.mannschaft_id
              WHERE wm.wettkampf_id = %d
           ORDER BY g.sort_order ASC, g.name ASC",
            $id
        ), ARRAY_A );
        $wk['mannschaft_ids'] = array_map( 'intval', array_column( $wk['mannschaften'], 'id' ) );

        $wk['tage'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf_tage WHERE wettkampf_id = %d ORDER BY datum ASC",
            $id
        ), ARRAY_A );

        $dokumente = LSV07I_Wettkampf_Dateien::list_for( $id );
        foreach ( $dokumente as &$d ) unset( $d['path'] );
        unset( $d );
        $wk['dokumente'] = $dokumente;

        $wk['darf_approve'] = LSV07I_Access::is_admin()
            || ( class_exists( 'LSV07I_Permissions' )
                 && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_WETTKAMPF_APPROVE ) );

        if ( ! empty( $wk['freigegeben_von'] ) ) {
            $u = get_user_by( 'id', (int) $wk['freigegeben_von'] );
            $wk['freigegeben_von_name'] = $u ? ( $u->display_name ?: $u->user_login ) : '';
        }

        wp_send_json_success( $wk );
    }

    /**
     * Wettkampf anlegen oder aktualisieren.
     * Akzeptiert entweder die Felder mannschaft_ids_json/tage_json (bevorzugt,
     * umgeht die traditional-Serialisierung von jQuery) oder die Legacy-
     * Arrays mannschaft_ids[] und tage[].
     */
    public static function save() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $id        = absint( $_POST['id'] ?? 0 );
        $name      = sanitize_text_field( $_POST['name'] ?? '' );
        $ort       = sanitize_text_field( $_POST['ort'] ?? '' );
        $datum_von = sanitize_text_field( $_POST['datum_von'] ?? '' );
        $datum_bis = sanitize_text_field( $_POST['datum_bis'] ?? '' );

        // Mannschaften: erst JSON-Parameter, dann Legacy-Array
        $mann_ids = [];
        if ( ! empty( $_POST['mannschaft_ids_json'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['mannschaft_ids_json'] ), true );
            if ( is_array( $decoded ) ) $mann_ids = $decoded;
        } elseif ( isset( $_POST['mannschaft_ids'] ) ) {
            $mann_ids = (array) $_POST['mannschaft_ids'];
        }
        $mann_ids = array_values( array_unique( array_filter( array_map( 'absint', $mann_ids ) ) ) );

        // Tage: erst JSON-Parameter, dann Legacy-Array
        $tage_in = [];
        if ( ! empty( $_POST['tage_json'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['tage_json'] ), true );
            if ( is_array( $decoded ) ) $tage_in = $decoded;
        } elseif ( isset( $_POST['tage'] ) ) {
            $tage_in = (array) $_POST['tage'];
        }

        if ( $name === '' || $ort === '' || ! $datum_von || ! $datum_bis ) {
            wp_send_json_error( [ 'message' => 'Name, Ort, Datum von und Datum bis sind Pflichtfelder.' ] );
        }
        if ( strtotime( $datum_von ) > strtotime( $datum_bis ) ) {
            wp_send_json_error( [ 'message' => '"Datum von" muss vor "Datum bis" liegen.' ] );
        }
        if ( empty( $mann_ids ) ) {
            wp_send_json_error( [ 'message' => 'Bitte mindestens eine Mannschaft auswählen.' ] );
        }

        // Nur Admin/Schwimmwart dürfen Wettkämpfe anlegen/bearbeiten.
        // Trainer sonst nur, wenn er Trainer einer der beteiligten Mannschaften ist.
        if ( ! LSV07I_Access::is_admin() && ! LSV07I_Access::is_schwimmwart() ) {
            $trainer_id = LSV07I_Access::get_trainer_id();
            if ( ! $trainer_id ) wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
            $mann_in    = implode( ',', array_map( 'absint', $mann_ids ) );
            $ist_trainer = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}lsv07i_trainer_mannschaft
                  WHERE trainer_id = %d AND mannschaft_id IN ($mann_in)",
                $trainer_id
            ) );
            if ( ! $ist_trainer ) {
                wp_send_json_error( [ 'message' => 'Sie sind kein Trainer der gewählten Mannschaften.' ], 403 );
            }
        }

        // Aktuelle Wettkampfpauschale als Snapshot speichern
        $pauschale = (float) LSV07I_DB::get_config( 'wk_pauschale', 25 );

        // Vor dem Schreiben: bestehenden Datensatz laden, um zu erkennen, ob
        // sich öffentlich sichtbare Felder eines BEREITS freigegebenen
        // Wettkampfs ändern. In dem Fall wird die Freigabe zurückgezogen —
        // eine Änderung an bereits veröffentlichten Daten braucht eine neue
        // Prüfung durch den Freigebenden, statt still auf der öffentlichen
        // Seite durchzuschlagen.
        $vorher = $id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf WHERE id = %d LIMIT 1", $id
        ), ARRAY_A ) : null;
        $freigabe_zurueckgezogen = false;

        // Hauptdatensatz schreiben
        $data = [
            'name'      => $name,
            'ort'       => $ort,
            'datum_von' => $datum_von,
            'datum_bis' => $datum_bis,
            'pauschale' => $pauschale,
        ];
        $format = [ '%s', '%s', '%s', '%s', '%f' ];

        if ( $vorher && (int) $vorher['freigegeben'] === 1
             && ( $vorher['name'] !== $name || $vorher['ort'] !== $ort
                  || $vorher['datum_von'] !== $datum_von || $vorher['datum_bis'] !== $datum_bis ) ) {
            $data['freigegeben']      = 0;
            $data['freigegeben_von']  = null;
            $data['freigegeben_am']   = null;
            $format[] = '%d'; $format[] = '%d'; $format[] = '%s';
            $freigabe_zurueckgezogen = true;
        }

        $ist_neu = ! $id;
        if ( $id ) {
            $wpdb->update( $p . 'lsv07i_wettkampf', $data, [ 'id' => $id ], $format, [ '%d' ] );
        } else {
            $data['angelegt_von'] = get_current_user_id();
            $format[] = '%d';
            $wpdb->insert( $p . 'lsv07i_wettkampf', $data, $format );
            $id = $wpdb->insert_id;
        }
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Speichern fehlgeschlagen.' ] );

        // Mannschaften synchronisieren
        $wpdb->delete( $p . 'lsv07i_wettkampf_mannschaft', [ 'wettkampf_id' => $id ], [ '%d' ] );
        foreach ( $mann_ids as $mid ) {
            $wpdb->insert( $p . 'lsv07i_wettkampf_mannschaft',
                [ 'wettkampf_id' => $id, 'mannschaft_id' => $mid ],
                [ '%d', '%d' ] );
        }

        // Tage synchronisieren.
        // Wenn der Client keine Tage liefert → automatisch aus Datumsbereich generieren.
        $tage_map = [];
        if ( ! empty( $tage_in ) ) {
            foreach ( $tage_in as $t ) {
                $d = sanitize_text_field( $t['datum'] ?? '' );
                $a = max( 1, absint( $t['abschnitte_plan'] ?? 1 ) );
                if ( $d ) $tage_map[ $d ] = $a;
            }
        }
        if ( empty( $tage_map ) ) {
            // Standard: jeder Tag im Bereich mit 1 Abschnitt
            $ts = strtotime( $datum_von );
            $te = strtotime( $datum_bis );
            while ( $ts <= $te ) {
                $tage_map[ date( 'Y-m-d', $ts ) ] = 1;
                $ts = strtotime( '+1 day', $ts );
            }
        }

        // Bestehende Tage laden, um nicht unnötig zu löschen
        // (wichtig: Tage mit Anwesenheits-Eintragungen sollen nicht
        //  verloren gehen, wenn Abschnittszahl sich ändert)
        $bestehend = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, datum, abschnitte_plan FROM {$p}lsv07i_wettkampf_tage
              WHERE wettkampf_id = %d", $id
        ), ARRAY_A );
        $bestehend_map = [];
        foreach ( $bestehend as $b ) $bestehend_map[ $b['datum'] ] = $b;

        // Neue Tage anlegen oder aktualisieren
        foreach ( $tage_map as $datum => $abschnitte ) {
            if ( isset( $bestehend_map[ $datum ] ) ) {
                if ( (int) $bestehend_map[ $datum ]['abschnitte_plan'] !== (int) $abschnitte ) {
                    $wpdb->update( $p . 'lsv07i_wettkampf_tage',
                        [ 'abschnitte_plan' => $abschnitte ],
                        [ 'id' => $bestehend_map[ $datum ]['id'] ],
                        [ '%d' ], [ '%d' ]
                    );
                }
            } else {
                $wpdb->insert( $p . 'lsv07i_wettkampf_tage', [
                    'wettkampf_id'    => $id,
                    'datum'           => $datum,
                    'abschnitte_plan' => $abschnitte,
                ], [ '%d', '%s', '%d' ] );
            }
        }

        // Tage löschen, die nicht mehr im Datumsbereich sind
        foreach ( $bestehend_map as $datum => $b ) {
            if ( ! isset( $tage_map[ $datum ] ) ) {
                // Vor dem Löschen: evtl. existierende Anwesenheiten + Abrechnungs-Einträge aufräumen
                self::remove_tag_kaskade( (int) $b['id'] );
            }
        }

        // Alle mit Freigabe-Recht benachrichtigen. Der Versand hängt bewusst
        // NICHT allein am Anlegen, sondern daran, dass der Wettkampf auch
        // wirklich freigegeben werden KANN — dafür muss die Ausschreibung
        // vorliegen (siehe approve()). Siehe mail_freigabe_anfrage().
        $mail = self::mail_freigabe_anfrage( $id );

        wp_send_json_success( [
            'id'                       => $id,
            'freigabe_zurueckgezogen'  => $freigabe_zurueckgezogen,
            // Ergebnis des Freigabe-Mail-Versands, damit die Oberfläche einen
            // Fehlschlag anzeigen kann statt ihn stillschweigend zu schlucken.
            'freigabe_mail'            => $mail,
        ] );
    }

    /**
     * Hilfsfunktion: Einen Wettkampftag kaskadiert löschen.
     * Entfernt alle Anwesenheiten zu diesem Tag und die dazugehörigen
     * automatisch erzeugten Abrechnungs-Einträge.
     */
    private static function remove_tag_kaskade( $tag_id ) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Alle Anwesenheits-Sessions zu diesem Tag (eine je Mannschaft)
        $anw_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_wettkampf_anwesenheit WHERE wettkampf_tag_id = %d",
            $tag_id
        ) );

        // Automatisch erzeugte Abrechnungs-Einträge für diesen Tag entfernen
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07i_abr_wettkampf
              WHERE wettkampf_tag_id = %d AND quelle = 'anwesenheit'",
            $tag_id
        ) );

        if ( ! empty( $anw_ids ) ) {
            $in = implode( ',', array_map( 'absint', $anw_ids ) );
            $wpdb->query( "DELETE FROM {$p}lsv07i_wettkampf_anw_eintraege WHERE wk_anwesenheit_id IN ($in)" );
            $wpdb->query( "DELETE FROM {$p}lsv07i_wettkampf_anwesenheit WHERE id IN ($in)" );
        }

        $wpdb->delete( $p . 'lsv07i_wettkampf_tage', [ 'id' => $tag_id ], [ '%d' ] );
    }

    /**
     * Wettkampf komplett löschen (nur Admin/Schwimmwart).
     * Löscht auch alle Anwesenheiten und alle automatisch erzeugten
     * Abrechnungs-Einträge zu diesem Wettkampf.
     */
    public static function delete() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'ID fehlt.' ] );

        if ( ! LSV07I_Access::is_admin() && ! LSV07I_Access::is_schwimmwart() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        // Alle Tage kaskadiert löschen (Anwesenheiten + Abrechnungs-Einträge)
        $tag_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_wettkampf_tage WHERE wettkampf_id = %d", $id
        ) );
        foreach ( $tag_ids as $tid ) {
            self::remove_tag_kaskade( (int) $tid );
        }

        $wpdb->delete( $p . 'lsv07i_wettkampf_mannschaft', [ 'wettkampf_id' => $id ], [ '%d' ] );
        LSV07I_Wettkampf_Dateien::delete_all_for( $id );
        $wpdb->delete( $p . 'lsv07i_wettkampf_erinnerung',  [ 'wettkampf_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_wettkampf',            [ 'id'            => $id ], [ '%d' ] );

        wp_send_json_success();
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  WETTKAMPF-FREIGABE, DOKUMENTE, ERINNERUNGS-MAILS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Prüft, ob der eingeloggte Nutzer für diesen Wettkampf Dokumente hoch-
     * oder herunterladen darf: Admin/Schwimmwart immer, sonst Trainer einer
     * beteiligten Mannschaft. Bricht mit 403 ab, wenn nicht.
     */
    private static function check_wettkampf_zugriff( $wettkampf_id ) {
        if ( LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart() ) return;
        global $wpdb;
        $p = $wpdb->prefix;
        $trainer_id = LSV07I_Access::get_trainer_id();
        $ok = $trainer_id && $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}lsv07i_wettkampf_mannschaft wm
             JOIN {$p}lsv07i_trainer_mannschaft tm ON tm.mannschaft_id = wm.mannschaft_id
              WHERE wm.wettkampf_id = %d AND tm.trainer_id = %d LIMIT 1",
            $wettkampf_id, $trainer_id
        ) );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'Keine Berechtigung für diesen Wettkampf.' ], 403 );
    }

    /**
     * Dokument hochladen (Ausschreibung/Meldeergebnis/Protokoll). PDF-Prüfung
     * inkl. echtem MIME-Sniffing übernimmt LSV07I_Wettkampf_Dateien.
     */
    public static function dok_upload() {
        LSV07I_Access::check( 'intern' );
        $wettkampf_id = absint( $_POST['wettkampf_id'] ?? 0 );
        $typ          = sanitize_key( $_POST['typ'] ?? '' );
        if ( ! $wettkampf_id ) wp_send_json_error( [ 'message' => 'Wettkampf fehlt.' ] );
        if ( ! in_array( $typ, LSV07I_Wettkampf_Dateien::TYPEN, true ) ) {
            wp_send_json_error( [ 'message' => 'Unbekannter Dokumenttyp.' ] );
        }
        self::check_wettkampf_zugriff( $wettkampf_id );

        if ( empty( $_FILES['datei'] ) ) wp_send_json_error( [ 'message' => 'Keine Datei übermittelt.' ] );

        $result = LSV07I_Wettkampf_Dateien::store_upload( $wettkampf_id, $typ, $_FILES['datei'] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        unset( $result['path'] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'wettkampf.dokument_upload', [
                'bereich'   => 'Schwimmen',
                'ziel_typ'  => 'wettkampf',
                'ziel_id'   => $wettkampf_id,
                'ziel_name' => self::typ_label( $typ ),
            ] );
        }

        // Mit der Ausschreibung ist der Wettkampf erst freigebbar (approve()
        // verlangt sie). Genau jetzt — und nicht schon beim bloßen Anlegen —
        // ist die "bitte freigeben"-Mail sinnvoll, deshalb wird sie hier
        // angestoßen. Idempotent über mail_approve_gesendet.
        $mail = null;
        if ( $typ === 'ausschreibung' ) {
            $mail = self::mail_freigabe_anfrage( $wettkampf_id );
        }

        wp_send_json_success( [
            'dokument'      => $result,
            'message'       => self::typ_label( $typ ) . ' hochgeladen.',
            'freigabe_mail' => $mail,
        ] );
    }

    /** Dokument löschen (nicht die Pflicht-Ausschreibung eines freigegebenen Wettkampfs). */
    public static function dok_delete() {
        LSV07I_Access::check( 'intern' );
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'ID fehlt.' ] );

        $doc = LSV07I_Wettkampf_Dateien::get( $id );
        if ( ! $doc ) wp_send_json_error( [ 'message' => 'Dokument nicht gefunden.' ] );
        self::check_wettkampf_zugriff( $doc['wettkampf_id'] );

        LSV07I_Wettkampf_Dateien::delete( $id );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'wettkampf.dokument_delete', [
                'bereich'   => 'Schwimmen',
                'ziel_typ'  => 'wettkampf',
                'ziel_id'   => $doc['wettkampf_id'],
                'ziel_name' => self::typ_label( $doc['typ'] ),
            ] );
        }
        wp_send_json_success( [ 'message' => 'Dokument gelöscht.' ] );
    }

    /**
     * Dokument-Download. Zwei Zugriffswege:
     *  - Jedes Dokument (Ausschreibung, Meldeergebnis, Protokoll) eines
     *    FREIGEGEBENEN Wettkampfs: öffentlich, kein Login nötig — das ist
     *    der ganze Sinn der öffentlichen Übersichtsseite.
     *  - Alles andere (Wettkampf noch nicht freigegeben): Login + gültiger
     *    Nonce + sw_wk_read-Berechtigung.
     * Die Datei wird gestreamt, kein Direktzugriff aufs Dateisystem.
     */
    public static function dok_download() {
        $id  = absint( $_REQUEST['id'] ?? 0 );
        $doc = $id ? LSV07I_Wettkampf_Dateien::get( $id ) : null;
        if ( ! $doc || ! file_exists( $doc['path'] ) ) {
            status_header( 404 );
            echo 'Dokument nicht gefunden.';
            exit;
        }

        global $wpdb;
        $frei = $wpdb->get_var( $wpdb->prepare(
            "SELECT freigegeben FROM {$wpdb->prefix}lsv07i_wettkampf WHERE id = %d",
            $doc['wettkampf_id']
        ) );
        $oeffentlich = (int) $frei === 1;

        if ( ! $oeffentlich ) {
            if ( ! is_user_logged_in() ) { status_header( 403 ); echo 'Bitte anmelden.'; exit; }
            $nonce = $_REQUEST['nonce'] ?? '';
            if ( ! wp_verify_nonce( $nonce, 'lsv07i_nonce' ) ) { status_header( 403 ); echo 'Ungültige Anfrage.'; exit; }
            $perm = class_exists( 'LSV07I_Permissions' );
            $ok = LSV07I_Access::is_intern()
                || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_WETTKAMPF_READ ) );
            if ( ! $ok ) { status_header( 403 ); echo 'Keine Berechtigung.'; exit; }
        }

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Length: ' . filesize( $doc['path'] ) );
        // Ascii-Fallback + RFC-6266-kodierter Original-Dateiname (Umlaute etc.)
        $ascii = preg_replace( '/[^\x20-\x7E]/', '_', $doc['dateiname'] );
        header( 'Content-Disposition: attachment; filename="' . $ascii . '"'
              . "; filename*=UTF-8''" . rawurlencode( $doc['dateiname'] ) );
        header( 'X-Content-Type-Options: nosniff' );

        if ( ob_get_level() ) ob_end_clean();
        readfile( $doc['path'] );
        exit;
    }

    private static function typ_label( $typ ) {
        $labels = [
            'ausschreibung' => 'Ausschreibung',
            'meldeergebnis' => 'Meldeergebnis',
            'protokoll'     => 'Protokoll',
        ];
        return $labels[ $typ ] ?? $typ;
    }

    /**
     * Wettkampf freigeben. Erfordert eine hochgeladene Ausschreibung — ohne
     * die kann die öffentliche Seite nichts Sinnvolles anzeigen.
     */
    public static function approve() {
        LSV07I_Access::check( 'sw_wk_approve' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'ID fehlt.' ] );

        $wk = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}lsv07i_wettkampf WHERE id = %d", $id ), ARRAY_A );
        if ( ! $wk ) wp_send_json_error( [ 'message' => 'Wettkampf nicht gefunden.' ] );

        $ausschreibung = LSV07I_Wettkampf_Dateien::get_by_typ( $id, 'ausschreibung' );
        if ( ! $ausschreibung ) {
            wp_send_json_error( [ 'message' => 'Vor der Freigabe muss die Ausschreibung als PDF hochgeladen werden.' ] );
        }

        $wpdb->update( $p . 'lsv07i_wettkampf', [
            'freigegeben'     => 1,
            'freigegeben_von' => get_current_user_id(),
            'freigegeben_am'  => current_time( 'mysql' ),
        ], [ 'id' => $id ], [ '%d', '%d', '%s' ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'wettkampf.approve', [
                'bereich'   => 'Schwimmen',
                'ziel_typ'  => 'wettkampf',
                'ziel_id'   => $id,
                'ziel_name' => $wk['name'],
            ] );
        }

        wp_send_json_success( [ 'message' => 'Wettkampf freigegeben — jetzt auf der öffentlichen Seite sichtbar.' ] );
    }

    /** Freigabe zurückziehen (z. B. wenn ein Fehler auffällt). */
    public static function unapprove() {
        LSV07I_Access::check( 'sw_wk_approve' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'ID fehlt.' ] );

        $wk = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$p}lsv07i_wettkampf WHERE id = %d", $id ), ARRAY_A );
        if ( ! $wk ) wp_send_json_error( [ 'message' => 'Wettkampf nicht gefunden.' ] );

        $wpdb->update( $p . 'lsv07i_wettkampf', [
            'freigegeben'     => 0,
            'freigegeben_von' => null,
            'freigegeben_am'  => null,
        ], [ 'id' => $id ], [ '%d', '%d', '%s' ], [ '%d' ] );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'wettkampf.unapprove', [
                'bereich'   => 'Schwimmen',
                'ziel_typ'  => 'wettkampf',
                'ziel_id'   => $id,
                'ziel_name' => $wk['name'],
            ] );
        }

        wp_send_json_success( [ 'message' => 'Freigabe zurückgezogen.' ] );
    }

    /**
     * Sendet die "bitte freigeben"-Mail an alle Benutzer mit dem Freigabe-Recht
     * plus die unter Admin → Mails hinterlegten Zusatz-Adressen. Idempotent
     * über mail_approve_gesendet.
     *
     * Liefert einen Status zurück, den save() an die Oberfläche durchreicht:
     * Ein fehlgeschlagener oder gar nicht erst versuchter Versand war bisher
     * vollständig unsichtbar — kein Hinweis, keine Fehlermeldung, nichts.
     * Genau das machte "es kommt keine Mail an" praktisch undiagnostizierbar.
     *
     * @return array{status:string,anzahl:int}
     *         status: 'gesendet' | 'fehlgeschlagen' | 'keine_empfaenger' | 'deaktiviert'
     */
    private static function mail_freigabe_anfrage( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! class_exists( 'LSV07I_Permissions' ) || ! class_exists( 'LSV07I_Mail' ) ) {
            return [ 'status' => 'fehlgeschlagen', 'anzahl' => 0 ];
        }

        $wk = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );
        if ( ! $wk ) return [ 'status' => 'fehlgeschlagen', 'anzahl' => 0 ];

        // Schon erfolgreich verschickt → nichts weiter tun (kein Doppelversand).
        if ( (int) ( $wk['mail_approve_gesendet'] ?? 0 ) === 1 ) {
            return [ 'status' => 'bereits_gesendet', 'anzahl' => 0 ];
        }

        // Ohne Ausschreibung lehnt approve() die Freigabe ab. Eine Mail
        // "bitte freigeben" wäre zu diesem Zeitpunkt also nicht nur nutzlos,
        // sondern würde das Versendet-Flag verbrauchen — genau deshalb kam
        // beim Ablauf "anlegen → Ausschreibung hochladen → nochmal speichern"
        // nie eine brauchbare Mail an. Jetzt wird gewartet, bis der Wettkampf
        // tatsächlich freigegeben werden kann; dok_upload() stößt den Versand
        // unmittelbar nach dem Hochladen der Ausschreibung an.
        if ( ! LSV07I_Wettkampf_Dateien::get_by_typ( $id, 'ausschreibung' ) ) {
            return [ 'status' => 'warte_auf_ausschreibung', 'anzahl' => 0 ];
        }

        // Ist der Versand überhaupt eingeschaltet? Sonst würde ein leeres
        // Ergebnis fälschlich wie ein Empfänger-Problem aussehen.
        if ( ! LSV07I_Mail::is_enabled( 'mail_wk_approve_anfrage' ) ) {
            return [ 'status' => 'deaktiviert', 'anzahl' => 0 ];
        }

        $name      = $wk['name'];
        $ort       = $wk['ort'];
        $datum_von = $wk['datum_von'];
        $datum_bis = $wk['datum_bis'];

        $users = LSV07I_Permissions::users_mit_recht( LSV07I_Permissions::SCHWIMMEN_WETTKAMPF_APPROVE );
        $emails = array_filter( array_map( fn( $u ) => $u->user_email, $users ) );
        $emails = array_merge( $emails, self::admin_mail_empfaenger( 'freigabe_anfrage' ) );
        $emails = array_values( array_unique( array_map( 'strtolower', array_filter( $emails ) ) ) );
        if ( empty( $emails ) ) {
            return [ 'status' => 'keine_empfaenger', 'anzahl' => 0 ];
        }

        $gesendet = LSV07I_Mail::wettkampf_freigabe_anfrage(
            $emails, $name, $ort, self::zeitraum_de( $datum_von, $datum_bis )
        );
        // Flag nur bei tatsächlich erfolgreichem Versand setzen — sonst bleibt
        // es auf 0 und der nächste save() dieses Wettkampfs versucht es erneut.
        if ( $gesendet ) {
            $wpdb->update( $p . 'lsv07i_wettkampf', [ 'mail_approve_gesendet' => 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        }
        return [
            'status' => $gesendet ? 'gesendet' : 'fehlgeschlagen',
            'anzahl' => count( $emails ),
        ];
    }

    /**
     * Zusätzliche, von einem Admin fest hinterlegte Mail-Adressen für einen
     * Wettkampf-Mailtyp (unabhängig vom Rechte-System bzw. den pro Wettkampf
     * hinterlegten Erinnerungsadressen). $spalte ist immer ein Literal aus
     * dem Code, nie Nutzereingabe — daher als Spaltenname unbedenklich.
     */
    private static function admin_mail_empfaenger( $spalte ) {
        static $erlaubt = [ 'freigabe_anfrage', 'erinnerung_meldeergebnis', 'erinnerung_protokoll' ];
        if ( ! in_array( $spalte, $erlaubt, true ) ) return [];
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_col(
            "SELECT email FROM {$p}lsv07i_wettkampf_mail_empfaenger WHERE $spalte = 1"
        );
    }

    /**
     * Admin-Verwaltung der zusätzlichen Mail-Empfänger. Bewusst nur per
     * 'admin' zugänglich (kein granulares Recht) — der Nutzer hat diesen
     * Bereich ausdrücklich als admin-only beschrieben.
     */
    public static function mail_empfaenger_liste() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT id, email, freigabe_anfrage, erinnerung_meldeergebnis, erinnerung_protokoll
               FROM {$p}lsv07i_wettkampf_mail_empfaenger
           ORDER BY email ASC",
            ARRAY_A
        );
        wp_send_json_success( [ 'empfaenger' => $rows ?: [] ] );
    }

    public static function mail_empfaenger_save() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p = $wpdb->prefix;

        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! $email || ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Bitte eine gültige E-Mail-Adresse angeben.' ] );
        }
        $daten = [
            'email'                    => $email,
            'freigabe_anfrage'         => ! empty( $_POST['freigabe_anfrage'] ) ? 1 : 0,
            'erinnerung_meldeergebnis' => ! empty( $_POST['erinnerung_meldeergebnis'] ) ? 1 : 0,
            'erinnerung_protokoll'     => ! empty( $_POST['erinnerung_protokoll'] ) ? 1 : 0,
        ];

        $vorhanden = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_wettkampf_mail_empfaenger WHERE email = %s", $email
        ) );
        if ( $vorhanden ) {
            $wpdb->update( $p . 'lsv07i_wettkampf_mail_empfaenger', $daten, [ 'id' => $vorhanden ],
                [ '%s', '%d', '%d', '%d' ], [ '%d' ] );
        } else {
            $daten['hinzugefuegt_von'] = get_current_user_id();
            $wpdb->insert( $p . 'lsv07i_wettkampf_mail_empfaenger', $daten,
                [ '%s', '%d', '%d', '%d', '%d' ] );
        }

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'wettkampf.mail_empfaenger.save', [
                'bereich'   => 'Wettkämpfe',
                'ziel_typ'  => 'mail_empfaenger',
                'ziel_name' => $email,
            ] );
        }
        wp_send_json_success( [ 'message' => 'Gespeichert.' ] );
    }

    public static function mail_empfaenger_delete() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'ID fehlt.' ] );
        $wpdb->delete( $p . 'lsv07i_wettkampf_mail_empfaenger', [ 'id' => $id ], [ '%d' ] );
        wp_send_json_success( [ 'message' => 'Gelöscht.' ] );
    }

    /** "10.10.2026" oder bei Mehrtägern "10.10.2026 – 11.10.2026". */
    private static function zeitraum_de( $datum_von, $datum_bis ) {
        $von = date_i18n( 'd.m.Y', strtotime( $datum_von ) );
        $bis = date_i18n( 'd.m.Y', strtotime( $datum_bis ) );
        return $von === $bis ? $von : ( $von . ' – ' . $bis );
    }

    /**
     * Täglicher Cron-Durchlauf (siehe LSV07I_Cron): verschickt die beiden
     * Erinnerungs-Mails an die unter Admin → Mails hinterlegten Adressen.
     *   - genau 3 Tage vor Wettkampfbeginn  → "Meldeergebnis hochladen"
     *   - genau 1 Tag nach Wettkampfende    → "Protokoll hochladen"
     * Beide sind über eigene Versendet-Flags gegen Doppelversand geschützt,
     * auch wenn der Cron mehrfach am selben Tag anläuft.
     */
    public static function cron_erinnerungen() {
        if ( class_exists( 'LSV07I_DB' ) && (string) LSV07I_DB::get_config( 'mail_deaktiviert', '0' ) === '1' ) {
            return;
        }
        global $wpdb;
        $p = $wpdb->prefix;

        $heute_plus3 = date( 'Y-m-d', strtotime( '+3 days' ) );
        $meldeergebnis_faellig = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf
              WHERE datum_von = %s AND mail_meldeergebnis_gesendet = 0",
            $heute_plus3
        ), ARRAY_A );
        foreach ( $meldeergebnis_faellig as $wk ) {
            $emails = self::admin_mail_empfaenger( 'erinnerung_meldeergebnis' );
            $emails = array_values( array_unique( array_map( 'strtolower', array_filter( $emails ) ) ) );
            $gesendet = false;
            if ( ! empty( $emails ) ) {
                $gesendet = LSV07I_Mail::wettkampf_erinnerung_meldeergebnis(
                    $emails, $wk['name'], $wk['ort'], self::zeitraum_de( $wk['datum_von'], $wk['datum_bis'] )
                );
            }
            // Flag nur bei tatsächlichem Versand setzen — schlägt er fehl
            // (Toggle aus, wp_mail()-Fehler), verhindert das keinen zweiten
            // Versuch, falls der Cron am selben Tag noch einmal anläuft.
            if ( $gesendet ) {
                $wpdb->update( $p . 'lsv07i_wettkampf', [ 'mail_meldeergebnis_gesendet' => 1 ],
                    [ 'id' => $wk['id'] ], [ '%d' ], [ '%d' ] );
            }
        }

        $heute_minus1 = date( 'Y-m-d', strtotime( '-1 day' ) );
        $protokoll_faellig = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf
              WHERE datum_bis = %s AND mail_protokoll_gesendet = 0",
            $heute_minus1
        ), ARRAY_A );
        foreach ( $protokoll_faellig as $wk ) {
            $emails = self::admin_mail_empfaenger( 'erinnerung_protokoll' );
            $emails = array_values( array_unique( array_map( 'strtolower', array_filter( $emails ) ) ) );
            $gesendet = false;
            if ( ! empty( $emails ) ) {
                $gesendet = LSV07I_Mail::wettkampf_erinnerung_protokoll(
                    $emails, $wk['name'], $wk['ort'], self::zeitraum_de( $wk['datum_von'], $wk['datum_bis'] )
                );
            }
            if ( $gesendet ) {
                $wpdb->update( $p . 'lsv07i_wettkampf', [ 'mail_protokoll_gesendet' => 1 ],
                    [ 'id' => $wk['id'] ], [ '%d' ], [ '%d' ] );
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  WETTKAMPF-ANWESENHEIT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Lädt (bzw. erzeugt beim ersten Aufruf) die Anwesenheits-Session
     * für einen bestimmten Wettkampftag + Mannschaft.
     * Liefert die Einträge, Schwimmerliste und Trainerliste.
     */
    public static function anw_get() {
        LSV07I_Access::check( 'sw_wk_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $tag_id = absint( $_POST['wettkampf_tag_id'] ?? 0 );
        $mann_id = absint( $_POST['mannschaft_id'] ?? 0 );
        if ( ! $tag_id || ! $mann_id ) {
            wp_send_json_error( [ 'message' => 'Wettkampftag und Mannschaft erforderlich.' ] );
        }

        // Prüfen dass die Mannschaft dem Wettkampf angehört
        $tag = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*, w.name AS wettkampf_name, w.pauschale
               FROM {$p}lsv07i_wettkampf_tage t
               JOIN {$p}lsv07i_wettkampf w ON w.id = t.wettkampf_id
              WHERE t.id = %d LIMIT 1",
            $tag_id
        ), ARRAY_A );
        if ( ! $tag ) wp_send_json_error( [ 'message' => 'Wettkampftag nicht gefunden.' ] );

        $wm_ok = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_wettkampf_mannschaft
              WHERE wettkampf_id = %d AND mannschaft_id = %d",
            $tag['wettkampf_id'], $mann_id
        ) );
        if ( ! $wm_ok ) wp_send_json_error( [ 'message' => 'Diese Mannschaft ist an dem Wettkampf nicht beteiligt.' ] );

        // Session holen oder anlegen
        $anw = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf_anwesenheit
              WHERE wettkampf_tag_id = %d AND mannschaft_id = %d LIMIT 1",
            $tag_id, $mann_id
        ), ARRAY_A );
        if ( ! $anw ) {
            $wpdb->insert( $p . 'lsv07i_wettkampf_anwesenheit', [
                'wettkampf_tag_id' => $tag_id,
                'mannschaft_id'    => $mann_id,
                'erstellt_von'     => get_current_user_id(),
            ], [ '%d', '%d', '%d' ] );
            $anw = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_wettkampf_anwesenheit WHERE id = %d",
                $wpdb->insert_id
            ), ARRAY_A );
        }

        $eintraege = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf_anw_eintraege WHERE wk_anwesenheit_id = %d",
            $anw['id']
        ), ARRAY_A );

        $schwimmer = LSV07I_DB::get_schwimmer( $mann_id );

        // Trainer der Mannschaft laden
        $trainer_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT trainer_id FROM {$p}lsv07i_trainer_mannschaft WHERE mannschaft_id = %d",
            $mann_id
        ) );
        $trainer = [];
        if ( ! empty( $trainer_ids ) ) {
            $in = implode( ',', array_map( 'absint', $trainer_ids ) );
            $trainer = $wpdb->get_results(
                "SELECT * FROM {$p}lsv07i_trainer WHERE id IN ($in) AND aktiv = 1
              ORDER BY display_name ASC, name ASC", ARRAY_A
            );
        }

        $mannschaft = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07_gruppen WHERE id = %d", $mann_id
        ), ARRAY_A );

        wp_send_json_success( [
            'anwesenheit' => $anw,
            'tag'         => $tag,
            'mannschaft'  => $mannschaft,
            'eintraege'   => $eintraege,
            'schwimmer'   => $schwimmer,
            'trainer'     => $trainer,
        ] );
    }

    /**
     * Einen einzelnen Anwesenheits-Eintrag speichern (Status ändern).
     * Bei Trainern wird zusätzlich die Abrechnung synchronisiert:
     *   anwesend  → Eintrag in lsv07i_abr_wettkampf anlegen/aktualisieren
     *   abwesend/entschuldigt → automatischer Eintrag wird entfernt
     */
    public static function anw_save_eintrag() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $anw_id  = absint( $_POST['wk_anwesenheit_id'] ?? 0 );
        $typ     = sanitize_text_field( $_POST['typ'] ?? 'schwimmer' );
        $tid     = absint( $_POST['teilnehmer_id'] ?? 0 );
        $status  = sanitize_text_field( $_POST['status'] ?? 'abwesend' );

        if ( ! $anw_id || ! $tid ) {
            wp_send_json_error( [ 'message' => 'Unvollständige Daten.' ] );
        }
        if ( ! in_array( $status, [ 'anwesend', 'abwesend', 'entschuldigt' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiger Status.' ] );
        }
        if ( ! in_array( $typ, [ 'schwimmer', 'trainer' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiger Teilnehmertyp.' ] );
        }

        // Session laden
        $anw = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf_anwesenheit WHERE id = %d LIMIT 1", $anw_id
        ), ARRAY_A );
        if ( ! $anw ) wp_send_json_error( [ 'message' => 'Session nicht gefunden.' ] );

        // Berechtigung: nur Trainer dieser Mannschaft oder Admin/Schwimmwart
        if ( ! LSV07I_Access::is_admin() && ! LSV07I_Access::is_schwimmwart() ) {
            $trainer_id = LSV07I_Access::get_trainer_id();
            $ok = $trainer_id && $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$p}lsv07i_trainer_mannschaft
                  WHERE trainer_id = %d AND mannschaft_id = %d LIMIT 1",
                $trainer_id, $anw['mannschaft_id']
            ) );
            if ( ! $ok ) {
                wp_send_json_error( [ 'message' => 'Keine Berechtigung für diese Mannschaft.' ], 403 );
            }
        }

        // Upsert
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_wettkampf_anw_eintraege
                 (wk_anwesenheit_id, teilnehmer_typ, teilnehmer_id, status)
             VALUES (%d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            $anw_id, $typ, $tid, $status
        ) );

        // Bidirektionale Abrechnungs-Sync für Trainer
        if ( $typ === 'trainer' ) {
            if ( $status === 'anwesend' ) {
                self::sync_abrechnung_anwesend( $anw, $tid );
            } else {
                self::sync_abrechnung_entfernen( $anw, $tid );
            }
        }

        wp_send_json_success();
    }

    /**
     * Speichert die Anzahl der von einem Trainer begleiteten Abschnitte
     * für einen Wettkampftag. Der Abrechnungs-Eintrag wird entsprechend
     * aktualisiert (Betrag = abschnitte × pauschale).
     */
    public static function anw_save_abschnitte() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $anw_id     = absint( $_POST['wk_anwesenheit_id'] ?? 0 );
        $trainer_id = absint( $_POST['trainer_id'] ?? 0 );
        $abschnitte = max( 0, absint( $_POST['abschnitte'] ?? 0 ) );
        if ( ! $anw_id || ! $trainer_id ) {
            wp_send_json_error( [ 'message' => 'Unvollständige Daten.' ] );
        }

        // Session laden und Berechtigung prüfen
        $anw = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_wettkampf_anwesenheit WHERE id = %d LIMIT 1", $anw_id
        ), ARRAY_A );
        if ( ! $anw ) wp_send_json_error( [ 'message' => 'Session nicht gefunden.' ] );
        if ( ! LSV07I_Access::is_admin() && ! LSV07I_Access::is_schwimmwart() ) {
            $my_tid = LSV07I_Access::get_trainer_id();
            $ok = $my_tid && $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$p}lsv07i_trainer_mannschaft
                  WHERE trainer_id = %d AND mannschaft_id = %d LIMIT 1",
                $my_tid, $anw['mannschaft_id']
            ) );
            if ( ! $ok ) wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        // Update des Eintrags (abschnitte)
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}lsv07i_wettkampf_anw_eintraege
                SET abschnitte = %d
              WHERE wk_anwesenheit_id = %d
                AND teilnehmer_typ = 'trainer'
                AND teilnehmer_id = %d",
            $abschnitte, $anw_id, $trainer_id
        ) );

        // Wenn Trainer aktuell anwesend → Abrechnungs-Eintrag aktualisieren
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$p}lsv07i_wettkampf_anw_eintraege
              WHERE wk_anwesenheit_id = %d
                AND teilnehmer_typ = 'trainer' AND teilnehmer_id = %d LIMIT 1",
            $anw_id, $trainer_id
        ) );
        if ( $status === 'anwesend' ) {
            self::sync_abrechnung_anwesend( $anw, $trainer_id );
        }

        wp_send_json_success();
    }

    /**
     * Legt einen Abrechnungs-Eintrag für den Trainer am gegebenen Wettkampftag an,
     * oder aktualisiert ihn (bei Abschnitts-Änderung).
     * Quelle = 'anwesenheit' — diese Einträge können bei Statuswechsel
     * wieder automatisch entfernt werden.
     */
    private static function sync_abrechnung_anwesend( $anw, $trainer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Tag + Wettkampf laden
        $tag = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*, w.name AS wettkampf_name, w.pauschale
               FROM {$p}lsv07i_wettkampf_tage t
               JOIN {$p}lsv07i_wettkampf w ON w.id = t.wettkampf_id
              WHERE t.id = %d LIMIT 1",
            $anw['wettkampf_tag_id']
        ), ARRAY_A );
        if ( ! $tag ) return;

        // Abschnitte für diesen Trainer: erfasst im Eintrag, Fallback = Plan
        $abschnitte = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT abschnitte FROM {$p}lsv07i_wettkampf_anw_eintraege
              WHERE wk_anwesenheit_id = %d
                AND teilnehmer_typ = 'trainer' AND teilnehmer_id = %d LIMIT 1",
            $anw['id'], $trainer_id
        ) );
        if ( ! $abschnitte ) $abschnitte = (int) $tag['abschnitte_plan'];
        $abschnitte = max( 1, $abschnitte );

        // Pauschale: Snapshot aus dem Wettkampf, Fallback aus Config
        $pauschale = (float) ( $tag['pauschale'] ?? 0 );
        if ( $pauschale <= 0 ) $pauschale = (float) LSV07I_DB::get_config( 'wk_pauschale', 25 );
        $betrag = round( $abschnitte * $pauschale, 2 );

        // Passendes Quartal/Jahr ermitteln und Abrechnung holen/anlegen
        $ts      = strtotime( $tag['datum'] );
        $monat   = (int) date( 'n', $ts );
        $jahr    = (int) date( 'Y', $ts );
        $quartal = 'Q' . ceil( $monat / 3 );

        $abr_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_abrechnung
              WHERE trainer_id = %d AND bereich = 'schwimmen' AND quartal = %s AND jahr = %d LIMIT 1",
            $trainer_id, $quartal, $jahr
        ) );
        if ( ! $abr_id ) {
            $wpdb->insert( $p . 'lsv07i_abrechnung', [
                'trainer_id' => $trainer_id,
                'bereich'    => 'schwimmen',
                'quartal'    => $quartal,
                'jahr'       => $jahr,
                'abr_status' => 'entwurf',
            ], [ '%d', '%s', '%s', '%d', '%s' ] );
            $abr_id = $wpdb->insert_id;
        }
        if ( ! $abr_id ) return;

        // Existierenden Auto-Eintrag zu diesem Tag suchen
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abr_wettkampf
              WHERE wettkampf_tag_id = %d AND abrechnung_id = %d AND quelle = 'anwesenheit'
              LIMIT 1",
            $anw['wettkampf_tag_id'], $abr_id
        ), ARRAY_A );

        $daten = [
            'datum'      => $tag['datum'],
            'name'       => $tag['wettkampf_name'],
            'abschnitte' => $abschnitte,
            'pauschale'  => $pauschale,
            'betrag'     => $betrag,
        ];
        if ( $existing ) {
            // Manuell gelöschten Eintrag nicht überschreiben
            if ( ! empty( $existing['manuell_geloescht'] ) ) return;
            $wpdb->update( $p . 'lsv07i_abr_wettkampf', $daten,
                [ 'id' => $existing['id'] ],
                [ '%s', '%s', '%d', '%f', '%f' ], [ '%d' ] );
        } else {
            $daten['abrechnung_id']    = $abr_id;
            $daten['wettkampf_tag_id'] = $anw['wettkampf_tag_id'];
            $daten['mannschaft_id']    = $anw['mannschaft_id'];
            $daten['quelle']           = 'anwesenheit';
            $wpdb->insert( $p . 'lsv07i_abr_wettkampf', $daten,
                [ '%s', '%s', '%d', '%f', '%f', '%d', '%d', '%d', '%s' ] );
        }
    }

    /**
     * Entfernt den automatisch erzeugten Abrechnungs-Eintrag für
     * einen Trainer an einem Wettkampftag, wenn er nicht mehr anwesend ist.
     * Manuelle Einträge und solche mit manuell_geloescht=1 bleiben unberührt.
     */
    private static function sync_abrechnung_entfernen( $anw, $trainer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Alle Abrechnungen dieses Trainers, die einen Auto-Eintrag für den Tag haben
        $wpdb->query( $wpdb->prepare(
            "DELETE aw FROM {$p}lsv07i_abr_wettkampf aw
             JOIN {$p}lsv07i_abrechnung a ON a.id = aw.abrechnung_id
             WHERE aw.wettkampf_tag_id = %d
               AND aw.quelle = 'anwesenheit'
               AND a.trainer_id = %d",
            $anw['wettkampf_tag_id'], $trainer_id
        ) );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  STATISTIK
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Wettkampf-Statistik: je Schwimmer Anwesend/Abwesend/Entschuldigt-Zählung
     * über alle Wettkampftage (optional gefiltert nach Mannschaft und Zeitraum).
     */
    public static function statistik() {
        LSV07I_Access::check( 'sw_wk_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $mann_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $von     = sanitize_text_field( $_POST['von'] ?? '' );
        $bis     = sanitize_text_field( $_POST['bis'] ?? '' );

        $w = [ '1=1' ];
        if ( $mann_id ) $w[] = $wpdb->prepare( 'a.mannschaft_id = %d', $mann_id );
        if ( $von )     $w[] = $wpdb->prepare( 't.datum >= %s', $von );
        if ( $bis )     $w[] = $wpdb->prepare( 't.datum <= %s', $bis );
        $wh = implode( ' AND ', $w );

        // Gesamtzahl Wettkampftage (ohne Schwimmerbezug) im Filter
        $wk_tage_gesamt = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT t.id)
               FROM {$p}lsv07i_wettkampf_anwesenheit a
               JOIN {$p}lsv07i_wettkampf_tage t ON t.id = a.wettkampf_tag_id
              WHERE $wh"
        );

        // Schwimmer-Statistik
        $rows = $wpdb->get_results(
            "SELECT e.teilnehmer_id AS id, e.status, COUNT(*) AS anzahl,
                    CONCAT(sw.last_name, ', ', sw.first_name) AS name,
                    a.mannschaft_id
               FROM {$p}lsv07i_wettkampf_anw_eintraege e
               JOIN {$p}lsv07i_wettkampf_anwesenheit a ON a.id = e.wk_anwesenheit_id
               JOIN {$p}lsv07i_wettkampf_tage t        ON t.id = a.wettkampf_tag_id
          LEFT JOIN {$p}mv_swimmers sw                 ON sw.id = e.teilnehmer_id
              WHERE e.teilnehmer_typ = 'schwimmer' AND $wh
           GROUP BY e.teilnehmer_id, e.status, a.mannschaft_id
           ORDER BY name ASC",
            ARRAY_A
        );

        // Nenner je Schwimmer = Wettkampftage seiner Mannschaft(en) im Filter
        $tage_per_mann = [];
        $by = [];
        foreach ( $rows as $r ) {
            $sid = (int) $r['id'];
            $mid = (int) $r['mannschaft_id'];
            if ( ! isset( $by[ $sid ] ) ) {
                if ( ! isset( $tage_per_mann[ $mid ] ) ) {
                    $wh_m = $wh;
                    // mannschaft_id schon im WHERE falls Filter aktiv; zusätzlich einschränken
                    $tage_per_mann[ $mid ] = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(DISTINCT t.id)
                           FROM {$p}lsv07i_wettkampf_anwesenheit a
                           JOIN {$p}lsv07i_wettkampf_tage t ON t.id = a.wettkampf_tag_id
                          WHERE a.mannschaft_id = %d
                            " . ( $von ? $wpdb->prepare( 'AND t.datum >= %s', $von ) : '' ) . "
                            " . ( $bis ? $wpdb->prepare( 'AND t.datum <= %s', $bis ) : '' ),
                        $mid
                    ) );
                }
                $by[ $sid ] = [
                    'id'           => $sid,
                    'name'         => $r['name'],
                    'anwesend'     => 0,
                    'abwesend'     => 0,
                    'entschuldigt' => 0,
                    'gesamt'       => $tage_per_mann[ $mid ],
                ];
            }
            $by[ $sid ][ $r['status'] ] = (int) $r['anzahl'];
        }

        wp_send_json_success( [
            'wk_tage_gesamt' => $wk_tage_gesamt,
            'schwimmer'      => array_values( $by ),
        ] );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  OFFENE TAGE (für "manuell hinzufügen" in der Abrechnung)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Liefert alle Wettkampftage im gegebenen Zeitraum (Quartal),
     * an denen der Trainer anwesend war ODER Trainer der beteiligten Mannschaft ist,
     * aber noch KEINEN Abrechnungseintrag hat.
     * So kann der Trainer fehlende Tage nachträglich hinzufügen.
     */
    public static function offene_tage() {
        LSV07I_Access::check( 'sw_wk_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $abr_id = absint( $_POST['abrechnung_id'] ?? 0 );
        if ( ! $abr_id ) wp_send_json_error( [ 'message' => 'Abrechnung fehlt.' ] );

        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_abrechnung WHERE id = %d LIMIT 1", $abr_id
        ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );

        // Berechtigung
        if ( ! LSV07I_Access::is_admin()
             && ! LSV07I_Access::is_schwimmwart()
             && ! LSV07I_Access::is_finanzwart() ) {
            if ( (int) $abr['trainer_id'] !== (int) LSV07I_Access::get_trainer_id() ) {
                wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
            }
        }

        $trainer_id  = (int) $abr['trainer_id'];
        $quartal_num = (int) substr( $abr['quartal'], 1 );
        $jahr        = (int) $abr['jahr'];
        $monat_von   = ( ( $quartal_num - 1 ) * 3 ) + 1;
        $datum_von   = sprintf( '%d-%02d-01', $jahr, $monat_von );
        $datum_bis   = date( 'Y-m-t', strtotime( sprintf( '%d-%02d-01', $jahr, $monat_von + 2 ) ) );

        // Wettkampftage im Zeitraum, an denen der Trainer...
        //   (a) in einer Mannschaft ist die am Wettkampf teilnimmt, ODER
        //   (b) als anwesend markiert wurde
        // und für den noch KEIN Abrechnungs-Eintrag existiert.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT t.id AS wettkampf_tag_id, t.datum, t.abschnitte_plan,
                    w.id AS wettkampf_id, w.name AS wettkampf_name, w.pauschale,
                    a.mannschaft_id, g.name AS mannschaft_name,
                    COALESCE(e.status, 'abwesend') AS eigener_status
               FROM {$p}lsv07i_wettkampf_tage t
               JOIN {$p}lsv07i_wettkampf w ON w.id = t.wettkampf_id
               JOIN {$p}lsv07i_wettkampf_anwesenheit a ON a.wettkampf_tag_id = t.id
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = a.mannschaft_id
          LEFT JOIN {$p}lsv07i_wettkampf_anw_eintraege e
                    ON e.wk_anwesenheit_id = a.id
                       AND e.teilnehmer_typ = 'trainer' AND e.teilnehmer_id = %d
              WHERE t.datum BETWEEN %s AND %s
                AND (
                    EXISTS ( SELECT 1 FROM {$p}lsv07i_trainer_mannschaft tm
                              WHERE tm.trainer_id = %d AND tm.mannschaft_id = a.mannschaft_id )
                    OR e.status = 'anwesend'
                )
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}lsv07i_abr_wettkampf aw
                     WHERE aw.abrechnung_id = %d AND aw.wettkampf_tag_id = t.id
                )
           ORDER BY t.datum ASC",
            $trainer_id, $datum_von, $datum_bis, $trainer_id, $abr_id
        ), ARRAY_A );

        wp_send_json_success( $rows );
    }
}
