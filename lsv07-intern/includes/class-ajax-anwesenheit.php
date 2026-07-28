<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Anwesenheit {

    public static function init() {
        $actions = [
            'lsv07i_anw_get_sessions',
            'lsv07i_anw_get_session',
            'lsv07i_anw_save_session',
            'lsv07i_anw_save_eintrag',
            'lsv07i_anw_mark_ausfall',
            'lsv07i_anw_get_statistik',
            'lsv07i_anw_saison_bericht',
            'lsv07i_anw_delete_session',
            'lsv07i_anw_delete_alle_sessions',
            'lsv07i_anw_auto_trainer_abr',
            'lsv07i_anw_trainer_ausschliessen',
            'lsv07i_anw_trainer_einschliessen',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_anw_', '', $action ) ] );
        }
    }

    /**
     * Datumsgrenze für vergangene Saisons: nur Admins duerfen Anwesenheits-
     * Daten VOR Beginn der aktuell aktiven Saison einsehen. Alle anderen
     * (auch Trainer/Schwimmwart mit sw_anw_read) werden auf den Start der
     * aktiven Saison begrenzt — unabhaengig davon, welches Datum sie in
     * einer Anfrage mitschicken. Gibt das fruehstmoegliche erlaubte Datum
     * zurueck, oder null wenn keine Einschraenkung gilt (Admin, oder keine
     * Saison konfiguriert — dann bleibt das bisherige Verhalten erhalten,
     * um Installationen ohne Saison-Verwaltung nicht zu blockieren).
     */
    private static function fruehestes_erlaubtes_datum() {
        if ( LSV07I_Access::is_admin() ) return null;
        if ( ! class_exists( 'LSV07I_Saison' ) ) return null;
        $aktiv = LSV07I_Saison::active();
        if ( ! $aktiv || empty( $aktiv['start_datum'] ) ) return null;
        return $aktiv['start_datum'];
    }

    /**
     * Liefert alle Sessions (Anwesenheits-Termine) fuer die Mannschaft des Trainers
     */
    public static function get_sessions() {
        LSV07I_Access::check( 'sw_anw_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $monat         = sanitize_text_field( $_POST['monat'] ?? date( 'Y-m' ) );

        $where_mann = $mannschaft_id ? $wpdb->prepare( 'AND a.mannschaft_id = %d', $mannschaft_id ) : '';
        $where_monat = $monat
            ? $wpdb->prepare( 'AND DATE_FORMAT(a.training_datum, "%%Y-%%m") = %s', $monat )
            : '';

        // Vergangene Saisons: nur Admins duerfen weiter zurueckblaettern als
        // den Beginn der aktiven Saison — unabhaengig vom angefragten Monat.
        $min_datum = self::fruehestes_erlaubtes_datum();
        $where_saison = $min_datum ? $wpdb->prepare( 'AND a.training_datum >= %s', $min_datum ) : '';

        $sessions = $wpdb->get_results(
            "SELECT a.*, g.name AS mannschaft_name, s.wochentag, s.zeit_von, s.zeit_bis
               FROM {$p}lsv07i_anwesenheit a
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = a.mannschaft_id
          LEFT JOIN {$p}lsv07i_training_slots s ON s.id = a.slot_id
              WHERE 1=1 $where_mann $where_monat $where_saison
           ORDER BY a.training_datum DESC",
            ARRAY_A
        );

        wp_send_json_success( $sessions );
    }

    /**
     * Liefert eine Session inkl. aller Eintraege (Schwimmer + Trainer)
     */
    public static function get_session() {
        LSV07I_Access::check( 'sw_anw_read' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, g.name AS mannschaft_name, s.wochentag, s.zeit_von, s.zeit_bis,
                    af.grund AS ausfall_grund
               FROM {$p}lsv07i_anwesenheit a
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = a.mannschaft_id
          LEFT JOIN {$p}lsv07i_training_slots s ON s.id = a.slot_id
          LEFT JOIN {$p}lsv07i_training_ausfall af ON af.slot_id = a.slot_id AND af.training_datum = a.training_datum
              WHERE a.id = %d LIMIT 1",
            $id
        ), ARRAY_A );

        if ( ! $session ) wp_send_json_error( [ 'message' => 'Session nicht gefunden.' ] );

        // Vergangene Saisons: Nicht-Admins duerfen auch per direktem
        // Aufruf keine Session vor Beginn der aktiven Saison einsehen.
        $min_datum = self::fruehestes_erlaubtes_datum();
        if ( $min_datum && $session['training_datum'] < $min_datum ) {
            wp_send_json_error( [ 'message' => 'Diese Session gehört zu einer vergangenen Saison und ist nur für Admins einsehbar.' ] );
        }

        $eintraege = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_anwesenheit_eintraege WHERE anwesenheit_id = %d",
            $id
        ), ARRAY_A );

        // Alle Schwimmer der Mannschaft laden
        $schwimmer = LSV07I_DB::get_schwimmer( $session['mannschaft_id'] );

        // Trainer der Mannschaft
        $trainer_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT trainer_id FROM {$p}lsv07i_trainer_mannschaft WHERE mannschaft_id = %d",
            $session['mannschaft_id']
        ) );

        $trainer = [];
        if ( ! empty( $trainer_ids ) ) {
            $in      = implode( ',', array_map( 'absint', $trainer_ids ) );
            $trainer = $wpdb->get_results(
                "SELECT * FROM {$p}lsv07i_trainer WHERE id IN ($in) AND aktiv = 1",
                ARRAY_A
            );
        }

        // Springer fuer diese Session hinzufuegen
        $springer_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT sp.*, t.name AS trainer_name
               FROM {$p}lsv07i_springer sp
          LEFT JOIN {$p}lsv07i_trainer  t ON t.id = sp.trainer_id
              WHERE sp.slot_id = %d AND sp.training_datum = %s",
            $session['slot_id'], $session['training_datum']
        ), ARRAY_A );

        // Springer auch als Trainer hinzufuegen (falls noch nicht drin)
        $trainer_ids_set = array_map( function( $t ) { return (int) $t['id']; }, $trainer );
        foreach ( $springer_rows as $sp ) {
            if ( ! in_array( (int) $sp['trainer_id'], $trainer_ids_set, true ) ) {
                $t_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$p}lsv07i_trainer WHERE id = %d", $sp['trainer_id']
                ), ARRAY_A );
                if ( $t_row ) {
                    $t_row['is_springer'] = true;
                    $trainer[] = $t_row;
                }
            }
        }

        // Ausgeschlossene Trainer für diesen Slot laden (gilt für alle Sessions dieses Slots)
        $ausgeschlossen_ids = [];
        if ( ! empty( $session['slot_id'] ) ) {
            $ausgeschlossen_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT trainer_id FROM {$p}lsv07i_anwesenheit_ausschluss WHERE slot_id = %d",
                $session['slot_id']
            ) );
        }

        // Trainer als ausgeschlossen markieren (nicht entfernen, damit man sie wieder einschließen kann)
        foreach ( $trainer as &$t ) {
            $t['ausgeschlossen'] = in_array( (string) $t['id'], $ausgeschlossen_ids, true ) ||
                                   in_array( (int)    $t['id'], array_map( 'intval', $ausgeschlossen_ids ), true );
        }
        unset( $t );

        wp_send_json_success( [
            'session'           => $session,
            'eintraege'         => $eintraege,
            'schwimmer'         => $schwimmer,
            'trainer'           => $trainer,
            'ausgeschlossen_ids'=> array_map( 'intval', $ausgeschlossen_ids ),
        ] );
    }

    /**
     * Erstellt eine neue Anwesenheits-Session.
     * Akzeptiert entweder slot_id ODER mannschaft_id (+ Datum).
     * Bei mannschaft_id wird der passende Slot für den Wochentag gesucht;
     * gibt es mehrere, wird der erste genommen.
     */
    public static function save_session() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $slot_id = absint( $_POST['slot_id'] ?? 0 );
        $mann_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum   = sanitize_text_field( $_POST['datum'] ?? '' );

        if ( ! $datum || ( ! $slot_id && ! $mann_id ) ) {
            wp_send_json_error( [ 'message' => 'Bitte Mannschaft und Datum angeben.' ] );
        }

        // Wenn keine Slot-ID angegeben ist: passenden Slot für den Wochentag suchen.
        // PHP: 'N' = ISO-Wochentag 1 (Mo) … 7 (So) — passt zur DB-Spalte wochentag
        if ( ! $slot_id && $mann_id ) {
            $wochentag = (int) date( 'N', strtotime( $datum ) );
            $slot_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_training_slots
                  WHERE mannschaft_id = %d AND wochentag = %d
               ORDER BY zeit_von ASC LIMIT 1",
                $mann_id, $wochentag
            ) );

            // Kein Slot für diesen Wochentag? Dann nimm irgendeinen Slot
            // dieser Mannschaft — so kann auch an einem abweichenden Tag
            // (Ferien, Verlegung) Anwesenheit erfasst werden.
            if ( ! $slot_id ) {
                $slot_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}lsv07i_training_slots
                      WHERE mannschaft_id = %d ORDER BY id ASC LIMIT 1",
                    $mann_id
                ) );
            }

            // Immer noch nichts? Anlegen eines Platzhalter-Slots (00:00-00:00).
            // Stunden in der Abrechnung werden dann auf 1.5h Fallback gesetzt.
            if ( ! $slot_id ) {
                $wpdb->insert( $p . 'lsv07i_training_slots', [
                    'mannschaft_id' => $mann_id,
                    'wochentag'     => $wochentag,
                    'zeit_von'      => '00:00:00',
                    'zeit_bis'      => '00:00:00',
                ], [ '%d', '%d', '%s', '%s' ] );
                $slot_id = (int) $wpdb->insert_id;
            }
        }

        if ( ! $slot_id ) {
            wp_send_json_error( [ 'message' => 'Keine Trainingszeit für diese Mannschaft gefunden.' ] );
        }

        // Berechtigung: nur Trainer der Mannschaft oder Admin/Schwimmwart
        if ( ! LSV07I_Access::is_admin() && ! LSV07I_Access::is_schwimmwart() ) {
            $trainer_id = LSV07I_Access::get_trainer_id();
            if ( $trainer_id ) {
                $slot = $wpdb->get_row( $wpdb->prepare(
                    "SELECT s.mannschaft_id FROM {$p}lsv07i_training_slots s WHERE s.id = %d", $slot_id
                ), ARRAY_A );
                if ( $slot ) {
                    $zugeordnet = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$p}lsv07i_trainer_mannschaft WHERE trainer_id = %d AND mannschaft_id = %d LIMIT 1",
                        $trainer_id, $slot['mannschaft_id']
                    ) );
                    $ist_springer = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$p}lsv07i_springer WHERE slot_id = %d AND training_datum = %s AND trainer_id = %d LIMIT 1",
                        $slot_id, $datum, $trainer_id
                    ) );
                    if ( ! $zugeordnet && ! $ist_springer ) {
                        wp_send_json_error( [ 'message' => 'Sie sind kein Trainer für diese Mannschaft.' ] );
                    }
                }
            }
        }

        $session = LSV07I_DB::get_or_create_anwesenheit( $slot_id, $datum );
        if ( ! $session ) wp_send_json_error( [ 'message' => 'Anwesenheit konnte nicht erstellt werden.' ] );

        // Kommentar zum Training (optional, wird nur gesetzt wenn übermittelt)
        if ( isset( $_POST['kommentar'] ) ) {
            $kommentar = sanitize_textarea_field( wp_unslash( $_POST['kommentar'] ) );
            // Maximal 5000 Zeichen — ein Kommentar zum Training, kein Roman
            if ( mb_strlen( $kommentar ) > 5000 ) {
                $kommentar = mb_substr( $kommentar, 0, 5000 );
            }
            $wpdb->update(
                $p . 'lsv07i_anwesenheit',
                [ 'kommentar' => $kommentar !== '' ? $kommentar : null ],
                [ 'id' => (int) $session['id'] ],
                [ '%s' ],
                [ '%d' ]
            );
            $session['kommentar'] = $kommentar;
        }

        LSV07I_Log::write( 'anwesenheit.session.save', [
            'bereich'   => 'Anwesenheit',
            'ziel_typ'  => 'session',
            'ziel_id'   => $session['id'],
            'details'   => 'Mannschaft-ID: ' . $mannschaft_id . ', Datum: ' . $datum,
        ] );
        wp_send_json_success( [
            'session_id' => $session['id'],
            'kommentar'  => isset( $session['kommentar'] ) ? $session['kommentar'] : null,
        ] );
    }

    /**
     * Speichert einen einzelnen Anwesenheits-Eintrag (anwesend / abwesend / entschuldigt)
     */
    public static function save_eintrag() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $anwesenheit_id = absint( $_POST['anwesenheit_id'] ?? 0 );
        $typ            = sanitize_text_field( $_POST['typ'] ?? 'schwimmer' );  // schwimmer | trainer
        $teilnehmer_id  = absint( $_POST['teilnehmer_id'] ?? 0 );
        $status         = sanitize_text_field( $_POST['status'] ?? 'abwesend' ); // anwesend | abwesend | entschuldigt

        if ( ! $anwesenheit_id || ! $teilnehmer_id ) {
            wp_send_json_error( [ 'message' => 'Unvollstaendige Daten.' ] );
        }

        $erlaubte_status = [ 'anwesend', 'abwesend', 'entschuldigt' ];
        if ( ! in_array( $status, $erlaubte_status, true ) ) {
            wp_send_json_error( [ 'message' => 'Ungueltiger Status.' ] );
        }

        // Upsert
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_anwesenheit_eintraege
                (anwesenheit_id, teilnehmer_typ, teilnehmer_id, status)
             VALUES (%d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE status = VALUES(status)",
            $anwesenheit_id, $typ, $teilnehmer_id, $status
        ) );

        // Abrechnungs-Synchronisation für Trainer
        if ( $typ === 'trainer' ) {
            $session = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_anwesenheit WHERE id = %d LIMIT 1",
                $anwesenheit_id
            ), ARRAY_A );

            if ( $session ) {
                if ( $status === 'anwesend' ) {
                    self::sync_abrechnung_trainingstag_anlegen(
                        $session, (int) $teilnehmer_id
                    );
                } else {
                    self::sync_abrechnung_trainingstag_entfernen(
                        $session, (int) $teilnehmer_id
                    );
                }
            }
        }

        wp_send_json_success();
    }

    /**
     * Legt für einen anwesenden Trainer einen Trainingstag in seiner
     * (ggf. neu zu erstellenden) schwimmen-Abrechnung des passenden
     * Quartals an. Unterscheidet zwischen regulärem Trainer der Mannschaft
     * (Quelle 'anwesenheit') und Springer (Quelle 'springer').
     *
     * Wenn bereits ein passender Eintrag mit manuell_geloescht=1 existiert,
     * wird nichts getan — der User hat ihn bewusst entfernt.
     */
    private static function sync_abrechnung_trainingstag_anlegen( $session, $trainer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Slot-Infos laden (Mannschaft, Zeiten für Stunden)
        $slot = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, g.name AS mannschaft_name
               FROM {$p}lsv07i_training_slots s
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id
              WHERE s.id = %d LIMIT 1",
            $session['slot_id']
        ), ARRAY_A );
        if ( ! $slot ) return;

        // Stunden aus Slot-Zeiten berechnen (min. 0.5)
        $stunden = 1.5;
        if ( ! empty( $slot['zeit_von'] ) && ! empty( $slot['zeit_bis'] ) ) {
            $ts_von = strtotime( '2000-01-01 ' . $slot['zeit_von'] );
            $ts_bis = strtotime( '2000-01-01 ' . $slot['zeit_bis'] );
            if ( $ts_bis > $ts_von ) {
                $stunden = round( ( $ts_bis - $ts_von ) / 3600, 2 );
            }
        }
        $stunden = max( 0.5, (float) $stunden );

        // Ist der Trainer regulärer Trainer der Mannschaft oder Springer?
        $ist_regulaer = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}lsv07i_trainer_mannschaft
              WHERE trainer_id = %d AND mannschaft_id = %d LIMIT 1",
            $trainer_id, $session['mannschaft_id']
        ) );
        $springer_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_springer
              WHERE slot_id = %d AND training_datum = %s AND trainer_id = %d LIMIT 1",
            $session['slot_id'], $session['training_datum'], $trainer_id
        ) );

        if ( ! $ist_regulaer && ! $springer_id ) return; // Fremder Trainer — ignorieren

        // Abrechnung (schwimmen) holen oder anlegen
        $ts      = strtotime( $session['training_datum'] );
        $monat   = (int) date( 'n', $ts );
        $jahr    = (int) date( 'Y', $ts );
        $quartal = 'Q' . ceil( $monat / 3 );

        $abr_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_abrechnung
              WHERE trainer_id = %d AND bereich = 'schwimmen'
                AND quartal = %s AND jahr = %d LIMIT 1",
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
            $abr_id = (int) $wpdb->insert_id;
        }
        if ( ! $abr_id ) return;

        // Passenden Eintrag suchen (inkl. manuell_geloescht um Doppler zu vermeiden)
        if ( $ist_regulaer ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_abr_trainingstage
                  WHERE abrechnung_id = %d AND anwesenheit_id = %d AND quelle = 'anwesenheit'
                  LIMIT 1",
                $abr_id, $session['id']
            ), ARRAY_A );

            if ( $existing ) {
                if ( ! empty( $existing['manuell_geloescht'] ) ) return;
                // existiert schon, nichts tun (Stunden bewusst nicht überschreiben,
                // falls User sie manuell angepasst hat)
                return;
            }

            $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                'abrechnung_id'    => $abr_id,
                'datum'            => $session['training_datum'],
                'mannschaft_name'  => $slot['mannschaft_name'] ?: '',
                'stunden'          => $stunden,
                'quelle'           => 'anwesenheit',
                'anwesenheit_id'   => $session['id'],
                'manuell_geloescht'=> 0,
            ], [ '%d', '%s', '%s', '%f', '%s', '%d', '%d' ] );
        } else {
            // Springer
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_abr_trainingstage
                  WHERE abrechnung_id = %d AND springer_id = %d AND quelle = 'springer'
                  LIMIT 1",
                $abr_id, $springer_id
            ), ARRAY_A );

            if ( $existing ) {
                if ( ! empty( $existing['manuell_geloescht'] ) ) return;
                return;
            }

            $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                'abrechnung_id'    => $abr_id,
                'datum'            => $session['training_datum'],
                'mannschaft_name'  => $slot['mannschaft_name'] ?: '',
                'stunden'          => $stunden,
                'quelle'           => 'springer',
                'springer_id'      => $springer_id,
                'begruendung'      => 'Springer-Einsatz (automatisch)',
                'manuell_geloescht'=> 0,
            ], [ '%d', '%s', '%s', '%f', '%s', '%d', '%s', '%d' ] );
        }
    }

    /**
     * Entfernt den automatisch erzeugten Trainingstag eines Trainers
     * wieder aus seiner Abrechnung — sowohl für reguläre als auch für
     * Springer-Einträge. Manuelle Einträge bleiben unberührt.
     */
    private static function sync_abrechnung_trainingstag_entfernen( $session, $trainer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;

        // Regulärer Eintrag (über anwesenheit_id)
        $wpdb->query( $wpdb->prepare(
            "DELETE ts FROM {$p}lsv07i_abr_trainingstage ts
             JOIN {$p}lsv07i_abrechnung a ON a.id = ts.abrechnung_id
             WHERE ts.anwesenheit_id = %d
               AND a.trainer_id = %d
               AND a.bereich = 'schwimmen'
               AND ts.quelle = 'anwesenheit'",
            $session['id'], $trainer_id
        ) );

        // Springer-Eintrag (über springer_id für dieses Datum/Slot)
        $wpdb->query( $wpdb->prepare(
            "DELETE ts FROM {$p}lsv07i_abr_trainingstage ts
             JOIN {$p}lsv07i_abrechnung a ON a.id = ts.abrechnung_id
             WHERE a.trainer_id = %d
               AND a.bereich = 'schwimmen'
               AND ts.quelle = 'springer'
               AND ts.springer_id IN (
                 SELECT sp.id FROM {$p}lsv07i_springer sp
                  WHERE sp.slot_id = %d
                    AND sp.training_datum = %s
                    AND sp.trainer_id = %d
               )",
            $trainer_id, $session['slot_id'],
            $session['training_datum'], $trainer_id
        ) );
    }

    /**
     * Markiert ein Training als ausgefallen.
     * Akzeptiert entweder slot_id ODER mannschaft_id (+ Datum).
     */
    public static function mark_ausfall() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p = $wpdb->prefix;

        $slot_id   = absint( $_POST['slot_id'] ?? 0 );
        $mann_id   = absint( $_POST['mannschaft_id'] ?? 0 );
        $datum     = sanitize_text_field( $_POST['datum'] ?? '' );
        $ausgefallen = absint( $_POST['ausgefallen'] ?? 1 );
        $grund     = sanitize_text_field( $_POST['grund'] ?? '' );

        if ( ! $datum || ( ! $slot_id && ! $mann_id ) ) {
            wp_send_json_error( [ 'message' => 'Fehlende Angaben.' ] );
        }

        // Wenn nur Mannschaft+Datum angegeben: Slot aus Wochentag ableiten
        if ( ! $slot_id && $mann_id ) {
            $wochentag = (int) date( 'N', strtotime( $datum ) );
            $slot_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_training_slots
                  WHERE mannschaft_id = %d AND wochentag = %d
               ORDER BY zeit_von ASC LIMIT 1",
                $mann_id, $wochentag
            ) );
            if ( ! $slot_id ) {
                $slot_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}lsv07i_training_slots
                      WHERE mannschaft_id = %d ORDER BY id ASC LIMIT 1",
                    $mann_id
                ) );
            }
        }
        if ( ! $slot_id ) {
            wp_send_json_error( [ 'message' => 'Kein Slot gefunden.' ] );
        }

        if ( $ausgefallen ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$p}lsv07i_training_ausfall (slot_id, training_datum, grund) VALUES (%d, %s, %s)
                 ON DUPLICATE KEY UPDATE grund = VALUES(grund)",
                $slot_id, $datum, $grund
            ) );
        } else {
            $wpdb->delete( $p . 'lsv07i_training_ausfall',
                [ 'slot_id' => $slot_id, 'training_datum' => $datum ],
                [ '%d', '%s' ]
            );
        }

        // Anwesenheits-Session aktualisieren falls vorhanden
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}lsv07i_anwesenheit SET ausgefallen = %d WHERE slot_id = %d AND training_datum = %s",
            $ausgefallen, $slot_id, $datum
        ) );

        wp_send_json_success();
    }

    /**
     * Statistiken: Anwesenheitsquote pro Schwimmer oder pro Mannschaft
     */
    public static function get_statistik() {
        LSV07I_Access::check( 'sw_anw_read' );
        global $wpdb;
        $p = $wpdb->prefix;

        $mannschaft_id  = absint( $_POST['mannschaft_id'] ?? 0 );
        $von            = sanitize_text_field( $_POST['von'] ?? '' );
        $bis            = sanitize_text_field( $_POST['bis'] ?? '' );

        // Vergangene Saisons: fuer Nicht-Admins den angefragten Start
        // auf den Beginn der aktiven Saison anheben, falls noetig.
        $min_datum = self::fruehestes_erlaubtes_datum();
        if ( $min_datum && ( ! $von || $von < $min_datum ) ) $von = $min_datum;

        $where_mann = $mannschaft_id
            ? $wpdb->prepare( 'AND a.mannschaft_id = %d', $mannschaft_id )
            : '';
        $where_von  = $von ? $wpdb->prepare( 'AND a.training_datum >= %s', $von ) : '';
        $where_bis  = $bis ? $wpdb->prepare( 'AND a.training_datum <= %s', $bis ) : '';

        // Gesamtzahl Sessions (für Anzeige, ohne Bezug zu einem Schwimmer)
        $sessions_gesamt = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}lsv07i_anwesenheit a
              WHERE a.ausgefallen = 0 $where_mann $where_von $where_bis"
        );

        // Anwesenheit pro Schwimmer
        $schwimmer_stats = $wpdb->get_results(
            "SELECT e.teilnehmer_id, e.status, COUNT(*) AS anzahl,
                    CONCAT(sw.last_name, ', ', sw.first_name) AS name,
                    sw.team_id AS mannschaft_id
               FROM {$p}lsv07i_anwesenheit_eintraege e
               JOIN {$p}lsv07i_anwesenheit a ON a.id = e.anwesenheit_id
          LEFT JOIN {$p}mv_swimmers sw ON sw.id = e.teilnehmer_id
              WHERE e.teilnehmer_typ = 'schwimmer' AND a.ausgefallen = 0
                    $where_mann $where_von $where_bis
           GROUP BY e.teilnehmer_id, e.status, sw.team_id
           ORDER BY name ASC",
            ARRAY_A
        );

        // Pro Schwimmer die Sessions NUR seiner eigenen Mannschaft zählen
        // (nicht alle Sessions im Filter, sondern nur die seiner Mannschaft)
        $sessions_per_mann = [];

        // Gruppieren nach Schwimmer-ID
        $by_swimmer = [];
        foreach ( $schwimmer_stats as $row ) {
            $sid = $row['teilnehmer_id'];
            $mid = (int) $row['mannschaft_id'];

            if ( ! isset( $by_swimmer[ $sid ] ) ) {
                // Anzahl Sessions der eigenen Mannschaft im gewählten Zeitraum ermitteln
                if ( ! isset( $sessions_per_mann[ $mid ] ) ) {
                    $sessions_per_mann[ $mid ] = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$p}lsv07i_anwesenheit a
                          WHERE a.ausgefallen = 0
                            AND a.mannschaft_id = %d
                            $where_von $where_bis",
                        $mid
                    ) );
                }

                $by_swimmer[ $sid ] = [
                    'id'           => $sid,
                    'name'         => $row['name'],
                    'anwesend'     => 0,
                    'abwesend'     => 0,
                    'entschuldigt' => 0,
                    // Nenner = Sessions der eigenen Mannschaft, nicht aller Mannschaften
                    'gesamt'       => $sessions_per_mann[ $mid ],
                ];
            }
            $by_swimmer[ $sid ][ $row['status'] ] = (int) $row['anzahl'];
        }

        // Sessions mit Kommentar im gewählten Zeitraum für die Statistik-Anzeige
        $kommentare = $wpdb->get_results(
            "SELECT a.id, a.training_datum, a.kommentar, g.name AS mannschaft_name,
                    s.zeit_von, s.zeit_bis
               FROM {$p}lsv07i_anwesenheit a
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = a.mannschaft_id
          LEFT JOIN {$p}lsv07i_training_slots s ON s.id = a.slot_id
              WHERE a.kommentar IS NOT NULL AND a.kommentar <> ''
                    $where_mann $where_von $where_bis
           ORDER BY a.training_datum DESC",
            ARRAY_A
        );

        wp_send_json_success( [
            'sessions_gesamt' => $sessions_gesamt,
            'schwimmer'       => array_values( $by_swimmer ),
            'kommentare'      => $kommentare,
        ] );
    }

    /**
     * Datenbasis fuer den Saisonbericht (PDF) einer Mannschaft:
     * Anwesenheits-Zusammenfassung pro Schwimmer + Bestzeiten.
     * Bestzeiten werden nur mitgeliefert, wenn der Nutzer sie auch im
     * Bestzeiten-Tab sehen duerfte (Tab-Rechte bleiben gewahrt).
     */
    public static function saison_bericht() {
        LSV07I_Access::check( 'sw_anw_read' );
        global $wpdb;
        $p   = $wpdb->prefix;
        $mid = absint( $_POST['mannschaft_id'] ?? 0 );
        $von = sanitize_text_field( $_POST['von'] ?? '' );
        $bis = sanitize_text_field( $_POST['bis'] ?? '' );
        if ( ! $mid ) wp_send_json_error( [ 'message' => 'Mannschaft fehlt.' ] );

        // Vergangene Saisons: fuer Nicht-Admins den angefragten Start
        // auf den Beginn der aktiven Saison anheben, falls noetig.
        $min_datum = self::fruehestes_erlaubtes_datum();
        if ( $min_datum && ( ! $von || $von < $min_datum ) ) $von = $min_datum;

        $where_von = $von ? $wpdb->prepare( 'AND a.training_datum >= %s', $von ) : '';
        $where_bis = $bis ? $wpdb->prepare( 'AND a.training_datum <= %s', $bis ) : '';

        $mannschaft = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$p}lsv07_gruppen WHERE id = %d", $mid ) );

        $sessions_gesamt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}lsv07i_anwesenheit a
              WHERE a.ausgefallen = 0 AND a.mannschaft_id = %d $where_von $where_bis",
            $mid ) );

        $schwimmer = $wpdb->get_results( $wpdb->prepare(
            "SELECT CONCAT( sw.last_name, ', ', sw.first_name ) AS name,
                    SUM( e.status = 'anwesend' )     AS anwesend,
                    SUM( e.status = 'entschuldigt' ) AS entschuldigt,
                    SUM( e.status = 'abwesend' )     AS abwesend
               FROM {$p}lsv07i_anwesenheit_eintraege e
               JOIN {$p}lsv07i_anwesenheit a ON a.id = e.anwesenheit_id
          LEFT JOIN {$p}mv_swimmers sw ON sw.id = e.teilnehmer_id
              WHERE e.teilnehmer_typ = 'schwimmer'
                AND a.ausgefallen = 0 AND a.mannschaft_id = %d
                    $where_von $where_bis
           GROUP BY e.teilnehmer_id
           ORDER BY name ASC",
            $mid ), ARRAY_A );

        // Bestzeiten nur, wenn der Nutzer den Bestzeiten-Tab sehen darf
        $darf_bz = LSV07I_Access::is_intern()
            || ( class_exists( 'LSV07I_Permissions' )
              && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_BESTZEIT_READ ) );
        $bestzeiten = [];
        if ( $darf_bz ) {
            $bestzeiten = $wpdb->get_results( $wpdb->prepare(
                "SELECT swimmer_name, strecke, zeit_raw
                   FROM {$p}lsv07i_bestzeiten
                  WHERE mannschaft_id = %d
               ORDER BY swimmer_name ASC, strecke ASC",
                $mid ), ARRAY_A );
        }

        wp_send_json_success( [
            'mannschaft'      => $mannschaft ?: ( 'Mannschaft #' . $mid ),
            'von'             => $von,
            'bis'             => $bis,
            'sessions_gesamt' => $sessions_gesamt,
            'schwimmer'       => $schwimmer ?: [],
            'bestzeiten'      => $bestzeiten ?: [],
            'bz_enthalten'    => $darf_bz ? 1 : 0,
        ] );
    }

    /**
     * Session endgültig löschen (nur Admin, nur vergangene Termine)
     */
    public static function delete_session() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Keine Session angegeben.' ] );

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_anwesenheit WHERE id = %d LIMIT 1", $id
        ), ARRAY_A );

        if ( ! $session ) wp_send_json_error( [ 'message' => 'Session nicht gefunden.' ] );

        // Einträge löschen, dann Session
        $wpdb->delete( $p . 'lsv07i_anwesenheit_eintraege', [ 'anwesenheit_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_anwesenheit',          [ 'id'             => $id ], [ '%d' ] );

        LSV07I_Log::write( 'anwesenheit.session.delete', [
            'bereich'  => 'Anwesenheit',
            'ziel_typ' => 'session',
            'ziel_id'  => $id,
        ] );
        wp_send_json_success( [ 'message' => 'Session gelöscht.' ] );
    }


    public static function trainer_ausschliessen() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $anw_id     = absint( $_POST['anwesenheit_id'] ?? 0 );
        $trainer_id = absint( $_POST['trainer_id']     ?? 0 );
        if ( ! $anw_id || ! $trainer_id ) wp_send_json_error( [ 'message' => 'Fehlende Angaben.' ] );

        // Slot der Session ermitteln
        $slot_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT slot_id FROM {$p}lsv07i_anwesenheit WHERE id = %d LIMIT 1", $anw_id
        ) );
        if ( ! $slot_id ) wp_send_json_error( [ 'message' => 'Kein Slot gefunden.' ] );

        $slot = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.wochentag, g.name AS mann_name FROM {$p}lsv07i_training_slots s
             LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.mannschaft_id WHERE s.id = %d", $slot_id
        ), ARRAY_A );
        $wochentage = [ 1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',5=>'Freitag',6=>'Samstag',7=>'Sonntag' ];
        $info = $slot ? ( $wochentage[ $slot['wochentag'] ] ?? '' ) . ' – ' . ( $slot['mann_name'] ?? '' ) : '';

        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$p}lsv07i_anwesenheit_ausschluss (slot_id, trainer_id, ausgeschlossen_von)
             VALUES (%d, %d, %d)",
            $slot_id, $trainer_id, get_current_user_id()
        ) );
        wp_send_json_success( [ 'slot_info' => $info ] );
    }

    public static function trainer_einschliessen() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p          = $wpdb->prefix;
        $anw_id     = absint( $_POST['anwesenheit_id'] ?? 0 );
        $trainer_id = absint( $_POST['trainer_id']     ?? 0 );
        if ( ! $anw_id || ! $trainer_id ) wp_send_json_error( [ 'message' => 'Fehlende Angaben.' ] );

        $slot_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT slot_id FROM {$p}lsv07i_anwesenheit WHERE id = %d LIMIT 1", $anw_id
        ) );

        $wpdb->delete( $p . 'lsv07i_anwesenheit_ausschluss',
            [ 'slot_id' => $slot_id ?: 0, 'trainer_id' => $trainer_id ],
            [ '%d', '%d' ]
        );
        wp_send_json_success();
    }

    /**
     * Automatisch Trainingstag für anwesende Trainer in die Abrechnung schreiben.
     * Wird nach dem Speichern der Anwesenheit aufgerufen.
     */
    public static function auto_trainer_abr() {
        LSV07I_Access::check( 'intern' );
        global $wpdb;
        $p              = $wpdb->prefix;
        $anwesenheit_id = absint( $_POST['anwesenheit_id'] ?? 0 );
        if ( ! $anwesenheit_id ) wp_send_json_success( [ 'skipped' => true ] );

        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, g.name AS mannschaft_name
               FROM {$p}lsv07i_anwesenheit a
          LEFT JOIN {$p}lsv07_gruppen g ON g.id = a.mannschaft_id
              WHERE a.id = %d LIMIT 1",
            $anwesenheit_id
        ), ARRAY_A );

        if ( ! $session ) wp_send_json_success( [ 'skipped' => true ] );

        // Alle Trainer die als anwesend markiert wurden (ausgeschlossene ignorieren)
        $anwesende_trainer = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.teilnehmer_id AS trainer_id
               FROM {$p}lsv07i_anwesenheit_eintraege e
              WHERE e.anwesenheit_id = %d AND e.teilnehmer_typ = 'trainer' AND e.status = 'anwesend'
                AND e.teilnehmer_id NOT IN (
                    SELECT trainer_id FROM {$p}lsv07i_anwesenheit_ausschluss WHERE slot_id = %d
                )",
            $anwesenheit_id, $session['slot_id'] ?? 0
        ), ARRAY_A );

        if ( empty( $anwesende_trainer ) ) wp_send_json_success( [ 'count' => 0 ] );

        $monat   = (int) date( 'm', strtotime( $session['training_datum'] ) );
        $jahr    = (int) date( 'Y', strtotime( $session['training_datum'] ) );
        $quartal = 'Q' . ceil( $monat / 3 );

        // Stunden aus Slot berechnen (zeit_von → zeit_bis)
        $slot = $session['slot_id'] ? $wpdb->get_row( $wpdb->prepare(
            "SELECT zeit_von, zeit_bis FROM {$p}lsv07i_training_slots WHERE id = %d LIMIT 1",
            $session['slot_id']
        ), ARRAY_A ) : null;

        $stunden = 2.0; // Standard
        if ( $slot && $slot['zeit_von'] && $slot['zeit_bis'] ) {
            $ts_von = strtotime( '2000-01-01 ' . $slot['zeit_von'] );
            $ts_bis = strtotime( '2000-01-01 ' . $slot['zeit_bis'] );
            if ( $ts_bis > $ts_von ) $stunden = round( ( $ts_bis - $ts_von ) / 3600, 2 );
        }

        $count = 0;
        foreach ( $anwesende_trainer as $row ) {
            $trainer_id = (int) $row['trainer_id'];

            // Abrechnung holen oder erstellen
            $abr = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_abrechnung
                  WHERE trainer_id = %d AND bereich = 'schwimmen' AND quartal = %s AND jahr = %d LIMIT 1",
                $trainer_id, $quartal, $jahr
            ), ARRAY_A );

            if ( ! $abr ) {
                $wpdb->insert( $p . 'lsv07i_abrechnung', [
                    'trainer_id' => $trainer_id, 'bereich' => 'schwimmen',
                    'quartal' => $quartal, 'jahr' => $jahr, 'abr_status' => 'entwurf',
                ], [ '%d', '%s', '%s', '%d', '%s' ] );
                $abr_id = $wpdb->insert_id;
            } else {
                $abr_id = $abr['id'];
            }

            // Nur eintragen wenn noch nicht vorhanden (Anwesenheits-Quelle)
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_abr_trainingstage
                  WHERE abrechnung_id = %d AND datum = %s AND anwesenheit_id = %d LIMIT 1",
                $abr_id, $session['training_datum'], $anwesenheit_id
            ) );

            if ( ! $exists ) {
                $wpdb->insert( $p . 'lsv07i_abr_trainingstage', [
                    'abrechnung_id'    => $abr_id,
                    'datum'            => $session['training_datum'],
                    'mannschaft_name'  => $session['mannschaft_name'] ?: '',
                    'stunden'          => $stunden,
                    'quelle'           => 'anwesenheit',
                    'anwesenheit_id'   => $anwesenheit_id,
                    'manuell_geloescht'=> 0,
                ], [ '%d', '%s', '%s', '%f', '%s', '%d', '%d' ] );
                $count++;
            }
        }

        wp_send_json_success( [ 'count' => $count ] );
    }

    /**
     * Alle vergangenen Sessions löschen (nur Admin)
     * Optional gefiltert nach Mannschaft und/oder Monat
     */
    public static function delete_alle_sessions() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p             = $wpdb->prefix;
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $monat         = sanitize_text_field( $_POST['monat'] ?? '' ); // Format: YYYY-MM

        $where = "WHERE a.training_datum <= CURDATE()";

        if ( $mannschaft_id ) {
            // Mannschaft-Filter: sessions direkt mit slot verknüpfen
            $slot_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_training_slots WHERE mannschaft_id = %d", $mannschaft_id
            ) );
            if ( ! empty( $slot_ids ) ) {
                $in_slots = implode( ',', array_map( 'absint', $slot_ids ) );
                $where .= " AND a.slot_id IN ($in_slots)";
            } else {
                // Fallback: direkte mannschaft_id Spalte (Altdaten)
                $where .= $wpdb->prepare( ' AND a.mannschaft_id = %d', $mannschaft_id );
            }
        }
        if ( $monat && preg_match( '/^\d{4}-\d{2}$/', $monat ) ) {
            $von = $monat . '-01';
            $bis = date( 'Y-m-t', strtotime( $von ) );
            $where .= $wpdb->prepare( ' AND a.training_datum >= %s AND a.training_datum <= %s', $von, $bis );
        }

        // IDs der zu löschenden Sessions holen
        $ids = $wpdb->get_col( "SELECT id FROM {$p}lsv07i_anwesenheit a $where" );

        if ( empty( $ids ) ) {
            wp_send_json_success( [ 'count' => 0 ] );
        }

        $in = implode( ',', array_map( 'absint', $ids ) );

        // Einträge löschen
        $wpdb->query( "DELETE FROM {$p}lsv07i_anwesenheit_eintraege WHERE anwesenheit_id IN ($in)" );
        // Sessions löschen
        $wpdb->query( "DELETE FROM {$p}lsv07i_anwesenheit WHERE id IN ($in)" );

        wp_send_json_success( [ 'count' => count( $ids ) ] );
    }
}
