<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Personen_Migration
 * --------------------------
 * Einmaliger Migrations-Code für den Übergang auf die zentrale
 * Personen-Tabelle (Iteration 1 / Version 6.6.0).
 *
 * Kopiert Daten aus:
 *   - mv_swimmers              → Personen (Sportler in Schwimmen)
 *   - lsv07i_trainer           → Personen (Trainer in Schwimmen)
 *   - lsv07i_tri_sportler      → Personen (Sportler in Triathlon)
 *   - lsv07i_tri_trainer       → Personen (Trainer in Triathlon)
 *
 * Dabei:
 *   - Werden Datensätze deduppliziert über Vorname+Nachname+Geburtsjahr
 *     (oder über die WP-User-ID bei Trainern). Bei Treffer wird die
 *     bestehende Person um Sparte/Rolle erweitert.
 *   - Wird die alte Mannschafts-Zuordnung übernommen.
 *   - Werden alle Aktionen im Audit-Log protokolliert.
 *
 * Die alten Tabellen werden NICHT gelöscht oder verändert. Sie bleiben
 * weiter im Betrieb, bis die UIs in späteren Iterationen umgestellt sind.
 *
 * Idempotenz:
 *   - Geschützt durch das Option-Flag 'lsv07i_personen_migration_done'.
 *   - Selbst wenn die Methode mehrfach aufgerufen würde, sind die
 *     UNIQUE-Keys auf legacy-IDs / sparten-rolle der Schutz.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Personen_Migration {

    public static function run() {
        global $wpdb;
        $p = $wpdb->prefix;

        // Maximale Sicherheit: in einer Transaktion-ähnlichen Schleife arbeiten.
        // MySQL InnoDB → wir nutzen aber kein explizites START TRANSACTION,
        // weil wir lieber Schritt-für-Schritt fortschritten loggen wollen
        // (bei Abbruch ist der Stand transparent rekonstruierbar).

        self::log( 'system', '0', null, 'start', 'Migration 6.6.0 begonnen' );

        $stats = [
            'mv_swimmers'        => self::migrate_schwimmer( $p ),
            'lsv07i_trainer'     => self::migrate_schwimm_trainer( $p ),
            'lsv07i_tri_sportler'=> self::migrate_tri_sportler( $p ),
            'lsv07i_tri_trainer' => self::migrate_tri_trainer( $p ),
        ];

        self::log( 'system', '0', null, 'finished',
            'Migration abgeschlossen. ' . wp_json_encode( $stats )
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  EINZELQUELLEN-MIGRATION
    // ─────────────────────────────────────────────────────────────────────────

    private static function migrate_schwimmer( $p ) {
        global $wpdb;
        $cnt = [ 'gesehen' => 0, 'neu' => 0, 'erweitert' => 0, 'fehler' => 0 ];

        $rows = $wpdb->get_results(
            "SELECT id, team_id, last_name, first_name, birth_date, email,
                    dsv_id, active, notes
               FROM {$p}mv_swimmers"
        );
        if ( empty( $rows ) ) return $cnt;

        foreach ( $rows as $r ) {
            $cnt['gesehen']++;
            try {
                $person_id = self::find_or_create_person( [
                    'vorname'      => $r->first_name,
                    'nachname'     => $r->last_name,
                    'geburtsdatum' => $r->birth_date,
                    'email'        => $r->email,
                    'dsv_id'       => $r->dsv_id,
                    'notes'        => $r->notes,
                    'aktiv'        => (int) $r->active,
                    'legacy_swimmer_id' => $r->id,
                ], $treffer );
                if ( ! $person_id ) { $cnt['fehler']++; continue; }
                $cnt[ $treffer ? 'erweitert' : 'neu' ]++;

                // Sparte+Rolle: Schwimmen / Sportler
                self::ensure_sparten_rolle( $person_id, 'schwimmen', 'sportler' );

                // Mannschaft: aus team_id
                if ( $r->team_id ) {
                    self::ensure_mannschaft( $person_id, 'schwimmen', (int) $r->team_id, 'sportler' );
                }

                self::log( 'mv_swimmers', $r->id, $person_id,
                    $treffer ? 'erweitert' : 'neu',
                    "Schwimmer {$r->first_name} {$r->last_name}"
                );
            } catch ( \Exception $e ) {
                $cnt['fehler']++;
                self::log( 'mv_swimmers', $r->id, null, 'fehler', $e->getMessage() );
            }
        }
        return $cnt;
    }

    private static function migrate_schwimm_trainer( $p ) {
        global $wpdb;
        $cnt = [ 'gesehen' => 0, 'neu' => 0, 'erweitert' => 0, 'fehler' => 0 ];

        // Trainer-Tabelle hat nur 'name' (ein Feld) — keine Geburtsdaten.
        // Wir splitten den Namen vorsichtig in Vor-/Nachname.
        $rows = $wpdb->get_results(
            "SELECT id, wp_user_id, name, email, aktiv FROM {$p}lsv07i_trainer"
        );
        if ( empty( $rows ) ) return $cnt;

        foreach ( $rows as $r ) {
            $cnt['gesehen']++;
            try {
                list( $vor, $nach ) = self::split_name( $r->name );
                $person_id = self::find_or_create_person( [
                    'vorname'           => $vor,
                    'nachname'          => $nach,
                    'email'             => $r->email,
                    'wp_user_id'        => (int) $r->wp_user_id,
                    'aktiv'             => (int) $r->aktiv,
                    'legacy_trainer_id' => $r->id,
                ], $treffer );
                if ( ! $person_id ) { $cnt['fehler']++; continue; }
                $cnt[ $treffer ? 'erweitert' : 'neu' ]++;

                self::ensure_sparten_rolle( $person_id, 'schwimmen', 'trainer' );

                // Trainer ↔ Mannschaft
                $tm_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT mannschaft_id FROM {$p}lsv07i_trainer_mannschaft
                      WHERE trainer_id = %d", $r->id
                ) );
                foreach ( $tm_rows as $tm ) {
                    self::ensure_mannschaft( $person_id, 'schwimmen', (int) $tm->mannschaft_id, 'trainer' );
                }

                self::log( 'lsv07i_trainer', $r->id, $person_id,
                    $treffer ? 'erweitert' : 'neu', "Trainer {$r->name}"
                );
            } catch ( \Exception $e ) {
                $cnt['fehler']++;
                self::log( 'lsv07i_trainer', $r->id, null, 'fehler', $e->getMessage() );
            }
        }
        return $cnt;
    }

    private static function migrate_tri_sportler( $p ) {
        global $wpdb;
        $cnt = [ 'gesehen' => 0, 'neu' => 0, 'erweitert' => 0, 'fehler' => 0 ];

        // Tabelle existiert nur wenn Triathlon-Modul je migriert wurde
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_tri_sportler'" );
        if ( ! $exists ) return $cnt;

        $rows = $wpdb->get_results(
            "SELECT id, first_name, last_name, birth_date, email, notes, aktiv
               FROM {$p}lsv07i_tri_sportler"
        );
        if ( empty( $rows ) ) return $cnt;

        foreach ( $rows as $r ) {
            $cnt['gesehen']++;
            try {
                $person_id = self::find_or_create_person( [
                    'vorname'      => $r->first_name,
                    'nachname'     => $r->last_name,
                    'geburtsdatum' => $r->birth_date,
                    'email'        => $r->email,
                    'notes'        => $r->notes,
                    'aktiv'        => (int) $r->aktiv,
                    'legacy_tri_sportler_id' => $r->id,
                ], $treffer );
                if ( ! $person_id ) { $cnt['fehler']++; continue; }
                $cnt[ $treffer ? 'erweitert' : 'neu' ]++;

                self::ensure_sparten_rolle( $person_id, 'triathlon', 'sportler' );

                // Mannschaften (Gruppen)
                $g_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT gruppe_id FROM {$p}lsv07i_tri_sportler_gruppe
                      WHERE sportler_id = %d", $r->id
                ) );
                foreach ( $g_rows as $g ) {
                    self::ensure_mannschaft( $person_id, 'triathlon', (int) $g->gruppe_id, 'sportler' );
                }

                self::log( 'lsv07i_tri_sportler', $r->id, $person_id,
                    $treffer ? 'erweitert' : 'neu', "Tri-Sportler {$r->first_name} {$r->last_name}"
                );
            } catch ( \Exception $e ) {
                $cnt['fehler']++;
                self::log( 'lsv07i_tri_sportler', $r->id, null, 'fehler', $e->getMessage() );
            }
        }
        return $cnt;
    }

    private static function migrate_tri_trainer( $p ) {
        global $wpdb;
        $cnt = [ 'gesehen' => 0, 'neu' => 0, 'erweitert' => 0, 'fehler' => 0 ];

        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_tri_trainer'" );
        if ( ! $exists ) return $cnt;

        $rows = $wpdb->get_results(
            "SELECT id, wp_user_id, name, email, aktiv FROM {$p}lsv07i_tri_trainer"
        );
        if ( empty( $rows ) ) return $cnt;

        foreach ( $rows as $r ) {
            $cnt['gesehen']++;
            try {
                list( $vor, $nach ) = self::split_name( $r->name );
                $person_id = self::find_or_create_person( [
                    'vorname'              => $vor,
                    'nachname'             => $nach,
                    'email'                => $r->email,
                    'wp_user_id'           => (int) ( $r->wp_user_id ?? 0 ),
                    'aktiv'                => (int) $r->aktiv,
                    'legacy_tri_trainer_id'=> $r->id,
                ], $treffer );
                if ( ! $person_id ) { $cnt['fehler']++; continue; }
                $cnt[ $treffer ? 'erweitert' : 'neu' ]++;

                self::ensure_sparten_rolle( $person_id, 'triathlon', 'trainer' );

                // Trainer ↔ Gruppe
                $tg_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT gruppe_id FROM {$p}lsv07i_tri_trainer_gruppe
                      WHERE trainer_id = %d", $r->id
                ) );
                foreach ( $tg_rows as $tg ) {
                    self::ensure_mannschaft( $person_id, 'triathlon', (int) $tg->gruppe_id, 'trainer' );
                }

                self::log( 'lsv07i_tri_trainer', $r->id, $person_id,
                    $treffer ? 'erweitert' : 'neu', "Tri-Trainer {$r->name}"
                );
            } catch ( \Exception $e ) {
                $cnt['fehler']++;
                self::log( 'lsv07i_tri_trainer', $r->id, null, 'fehler', $e->getMessage() );
            }
        }
        return $cnt;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  KERN-LOGIK: Person finden oder anlegen
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sucht nach einer existierenden Person, oder legt sie neu an.
     *
     * Match-Strategie (in dieser Reihenfolge):
     *   1. Über entsprechende legacy_*_id (für mehrfaches Durchlaufen)
     *   2. Über wp_user_id (falls > 0)
     *   3. Über Vorname + Nachname + Geburtsjahr (case-insensitiv)
     *   4. Über Vorname + Nachname (wenn kein Geburtsjahr da ist)
     *
     * Wenn ein Treffer gefunden wird, werden fehlende Felder ergänzt
     * (z.B. wenn beim Trainer kein Geburtsdatum war, aber jetzt der
     *  passende Schwimmer hinzukommt mit Geburtsdatum).
     *
     * @param array $data            Person-Daten
     * @param bool  $treffer         out: true = bestehende Person, false = neu
     * @return int  Person-ID oder 0 bei Fehler
     */
    private static function find_or_create_person( $data, &$treffer = false ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $treffer = false;
        $vor   = trim( (string) ( $data['vorname']  ?? '' ) );
        $nach  = trim( (string) ( $data['nachname'] ?? '' ) );
        $gebd  = trim( (string) ( $data['geburtsdatum'] ?? '' ) );
        $wpuid = (int) ( $data['wp_user_id'] ?? 0 );

        if ( $vor === '' && $nach === '' ) return 0;

        $found = null;

        // 1. Legacy-ID (idempotent)
        foreach ( [ 'legacy_swimmer_id', 'legacy_trainer_id',
                   'legacy_tri_sportler_id', 'legacy_tri_trainer_id' ] as $col ) {
            if ( ! empty( $data[ $col ] ) ) {
                $found = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$p}lsv07i_personen WHERE $col = %d LIMIT 1",
                    (int) $data[ $col ]
                ) );
                if ( $found ) break;
            }
        }

        // 2. WP-User-ID
        if ( ! $found && $wpuid > 0 ) {
            $found = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_personen WHERE wp_user_id = %d LIMIT 1",
                $wpuid
            ) );
        }

        // 3. Name + Geburtsjahr
        if ( ! $found && $gebd ) {
            $jahr = (int) substr( $gebd, 0, 4 );
            $found = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_personen
                  WHERE LOWER(vorname) = LOWER(%s)
                    AND LOWER(nachname) = LOWER(%s)
                    AND YEAR(geburtsdatum) = %d
                  LIMIT 1",
                $vor, $nach, $jahr
            ) );
        }

        // 4. Nur Name (ohne Geburtsjahr — vor allem für Trainer)
        if ( ! $found && $vor !== '' && $nach !== '' ) {
            $found = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}lsv07i_personen
                  WHERE LOWER(vorname) = LOWER(%s)
                    AND LOWER(nachname) = LOWER(%s)
                  LIMIT 1",
                $vor, $nach
            ) );
        }

        if ( $found ) {
            // Bestehende Person um fehlende Felder ergänzen
            $treffer = true;
            $update = [];
            if ( ! $found->geburtsdatum && $gebd )       $update['geburtsdatum'] = $gebd;
            if ( ! $found->email && ! empty( $data['email'] ) )
                                                          $update['email'] = $data['email'];
            if ( ! $found->dsv_id && ! empty( $data['dsv_id'] ) )
                                                          $update['dsv_id'] = $data['dsv_id'];
            if ( ! $found->wp_user_id && $wpuid > 0 )    $update['wp_user_id'] = $wpuid;
            // Legacy-ID-Spalte ergänzen, sofern noch nicht gesetzt
            foreach ( [ 'legacy_swimmer_id','legacy_trainer_id',
                        'legacy_tri_sportler_id','legacy_tri_trainer_id' ] as $col ) {
                if ( ! empty( $data[ $col ] ) && empty( $found->$col ) ) {
                    $update[ $col ] = (int) $data[ $col ];
                }
            }
            if ( ! empty( $update ) ) {
                $wpdb->update( $p . 'lsv07i_personen', $update,
                    [ 'id' => $found->id ] );
            }
            return (int) $found->id;
        }

        // Neu anlegen
        $insert = [
            'vorname'         => $vor,
            'nachname'        => $nach,
            'geburtsdatum'    => $gebd ?: null,
            'geschlecht'      => $data['geschlecht'] ?? null,
            'email'           => $data['email'] ?? '',
            'telefon'         => $data['telefon'] ?? '',
            'dsv_id'          => $data['dsv_id'] ?? '',
            'mitgliedsnummer' => $data['mitgliedsnummer'] ?? '',
            'wp_user_id'      => $wpuid,
            'notes'           => $data['notes'] ?? null,
            'aktiv'           => isset( $data['aktiv'] ) ? (int) $data['aktiv'] : 1,
        ];
        foreach ( [ 'legacy_swimmer_id','legacy_trainer_id',
                    'legacy_tri_sportler_id','legacy_tri_trainer_id' ] as $col ) {
            if ( ! empty( $data[ $col ] ) ) $insert[ $col ] = (int) $data[ $col ];
        }
        $ok = $wpdb->insert( $p . 'lsv07i_personen', $insert );
        if ( $ok === false ) return 0;
        return (int) $wpdb->insert_id;
    }

    private static function ensure_sparten_rolle( $person_id, $sparte, $rolle ) {
        global $wpdb;
        $p = $wpdb->prefix;
        // INSERT ... ON DUPLICATE: aktiv-Flag wird auf 1 gehalten
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_personen_sparten_rolle (person_id, sparte, rolle, aktiv)
             VALUES (%d, %s, %s, 1)
             ON DUPLICATE KEY UPDATE aktiv = 1",
            $person_id, $sparte, $rolle
        ) );
    }

    private static function ensure_mannschaft( $person_id, $sparte, $mannschaft_id, $rolle ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$p}lsv07i_personen_mannschaft
                (person_id, sparte, mannschaft_id, rolle)
             VALUES (%d, %s, %d, %s)",
            $person_id, $sparte, $mannschaft_id, $rolle
        ) );
    }

    /**
     * Splittet einen freitextlichen Namen in Vor- und Nachname.
     * Heuristiken:
     *   - "Nachname, Vorname"  → komma-basiert
     *   - "Vorname Nachname"   → letztes Wort = Nachname
     *   - "Mehrteilige Vornamen" werden als Vorname zusammengefasst
     */
    private static function split_name( $name ) {
        $name = trim( (string) $name );
        if ( $name === '' ) return [ '', '' ];

        if ( strpos( $name, ',' ) !== false ) {
            list( $nach, $vor ) = array_map( 'trim', explode( ',', $name, 2 ) );
            return [ $vor, $nach ];
        }
        $parts = preg_split( '/\s+/', $name );
        if ( count( $parts ) === 1 ) return [ '', $parts[0] ];
        $nach = array_pop( $parts );
        $vor  = implode( ' ', $parts );
        return [ $vor, $nach ];
    }

    private static function log( $quelle, $quelle_id, $person_id, $aktion, $notiz ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( $p . 'lsv07i_personen_migration_log', [
            'quelle'    => substr( (string) $quelle, 0, 40 ),
            'quelle_id' => substr( (string) $quelle_id, 0, 40 ),
            'person_id' => $person_id ? (int) $person_id : null,
            'aktion'    => substr( (string) $aktion, 0, 40 ),
            'notiz'     => $notiz,
        ] );
    }
}
