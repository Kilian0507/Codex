<?php
/**
 * Plugin Name: LSV07 Interner Bereich
 * Description: Interner Bereich fuer den LSV07 Schwimmverein.
 * Version:     8.1.0
 * Author:      LSV07
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LSV07I_VERSION',  '8.1.0' );
define( 'LSV07I_DIR',      plugin_dir_path( __FILE__ ) );
define( 'LSV07I_URL',      plugin_dir_url( __FILE__ ) );

/*
 * Polyfills für die mbstring-Funktionen. Auf Servern ohne die mbstring-
 * Extension existieren mb_strtolower() & Co. nicht, was zu Fatal Errors
 * führt (z.B. beim CSV-Import → leere Antwort, Loader hängt endlos).
 * Diese Fallbacks stellen einfache ASCII-/UTF-8-Ersatzfunktionen bereit.
 */
if ( ! function_exists( 'mb_strtolower' ) ) {
    function mb_strtolower( $s, $enc = null ) { return strtolower( (string) $s ); }
}
if ( ! function_exists( 'mb_strtoupper' ) ) {
    function mb_strtoupper( $s, $enc = null ) { return strtoupper( (string) $s ); }
}
if ( ! function_exists( 'mb_strlen' ) ) {
    function mb_strlen( $s, $enc = null ) { return strlen( (string) $s ); }
}
if ( ! function_exists( 'mb_substr' ) ) {
    function mb_substr( $s, $start, $length = null, $enc = null ) {
        return $length === null ? substr( (string) $s, $start ) : substr( (string) $s, $start, $length );
    }
}
if ( ! function_exists( 'mb_convert_encoding' ) ) {
    function mb_convert_encoding( $s, $to = null, $from = null ) { return (string) $s; }
}

define( 'LSV07I_ROLE_INTERN',           'intern' );
define( 'LSV07I_ROLE_FINANZWART',       'finanzwart' );
define( 'LSV07I_ROLE_SCHWIMMWART',      'schwimmwart' );
define( 'LSV07I_ROLE_TRIATHLONWART',    'triathlonwart' );
define( 'LSV07I_ROLE_TRI_INTERN',       'tri_intern' );
define( 'LSV07I_ROLE_FITNESSWART',      'fitnesswart' );
define( 'LSV07I_ROLE_FIT_INTERN',       'fit_intern' );

require_once LSV07I_DIR . 'includes/class-db.php';
require_once LSV07I_DIR . 'includes/class-roles.php';
require_once LSV07I_DIR . 'includes/class-permissions.php';
require_once LSV07I_DIR . 'includes/class-access.php';
require_once LSV07I_DIR . 'includes/class-mail.php';
require_once LSV07I_DIR . 'includes/class-ajax-admin.php';
require_once LSV07I_DIR . 'includes/class-ajax-schwimmen.php';
require_once LSV07I_DIR . 'includes/class-ajax-anwesenheit.php';
require_once LSV07I_DIR . 'includes/class-wettkampf-dateien.php';
require_once LSV07I_DIR . 'includes/class-ajax-wettkampf.php';
require_once LSV07I_DIR . 'includes/class-wettkampf-oeffentlich.php';
require_once LSV07I_DIR . 'includes/class-ajax-meldung.php';
require_once LSV07I_DIR . 'includes/class-ajax-akte.php';
require_once LSV07I_DIR . 'includes/class-ajax-home.php';
require_once LSV07I_DIR . 'includes/class-ajax-profil.php';
require_once LSV07I_DIR . 'includes/class-ajax-tickets.php';
require_once LSV07I_DIR . 'includes/class-ajax-atteste.php';
require_once LSV07I_DIR . 'includes/class-personen.php';
require_once LSV07I_DIR . 'includes/class-ajax-personen-admin.php';
require_once LSV07I_DIR . 'includes/class-ajax-springer.php';
require_once LSV07I_DIR . 'includes/class-ajax-bestzeiten.php';
require_once LSV07I_DIR . 'includes/class-ajax-rechte.php';
require_once LSV07I_DIR . 'includes/class-schwimmer-dateien.php';
require_once LSV07I_DIR . 'includes/class-ajax-schwimmer-dateien.php';
require_once LSV07I_DIR . 'includes/class-log.php';
require_once LSV07I_DIR . 'includes/class-ajax-log.php';
require_once LSV07I_DIR . 'includes/class-ajax-stammdaten.php';
require_once LSV07I_DIR . 'includes/class-saison.php';
require_once LSV07I_DIR . 'includes/class-ajax-saison.php';
require_once LSV07I_DIR . 'includes/class-konversation.php';
require_once LSV07I_DIR . 'includes/class-ajax-konversation.php';
require_once LSV07I_DIR . 'includes/class-reflexion.php';
require_once LSV07I_DIR . 'includes/class-ajax-reflexion.php';
require_once LSV07I_DIR . 'includes/class-reflexion-public.php';
require_once LSV07I_DIR . 'includes/class-email-versand.php';
require_once LSV07I_DIR . 'includes/class-magic-link.php';
require_once LSV07I_DIR . 'includes/class-imap-reader.php';
require_once LSV07I_DIR . 'includes/class-ajax-abrechnung.php';
require_once LSV07I_DIR . 'includes/class-ajax-sonderabrechnung.php';
require_once LSV07I_DIR . 'includes/class-ajax-triathlon.php';
require_once LSV07I_DIR . 'includes/class-ajax-fitness.php';
require_once LSV07I_DIR . 'includes/class-ajax-import.php';
require_once LSV07I_DIR . 'includes/class-shortcode.php';
require_once LSV07I_DIR . 'includes/class-cron.php';
require_once LSV07I_DIR . 'includes/class-privacy.php';

register_activation_hook( __FILE__, [ 'LSV07I_DB',          'install'      ] );
register_activation_hook( __FILE__, [ 'LSV07I_Roles',       'create_roles' ] );
register_activation_hook( __FILE__, [ 'LSV07I_Permissions', 'install'      ] );

/**
 * Admin-Notice falls die Personen-Migration Fehler hatte.
 * Wird nur dem Admin angezeigt, nicht jedem User.
 */
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! get_option( 'lsv07i_personen_migration_done' ) ) return;
    global $wpdb;
    $p = $wpdb->prefix;
    // Existiert die Log-Tabelle überhaupt? (Sicherheit gegen frühe Aufrufe)
    $tab = $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_personen_migration_log'" );
    if ( ! $tab ) return;
    $fehler = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$p}lsv07i_personen_migration_log WHERE aktion = 'fehler'"
    );
    if ( $fehler > 0 ) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>LSV07 Intern:</strong> '
            . 'Die Personen-Migration meldete <strong>' . $fehler . ' Fehler</strong>. '
            . 'Bitte in <code>' . esc_html( $p ) . 'lsv07i_personen_migration_log</code> nachsehen.</p></div>';
    }
} );
register_deactivation_hook( __FILE__, [ 'LSV07I_Cron', 'unschedule'  ] );

add_action( 'plugins_loaded', function () {

    if ( get_option( 'lsv07i_db_ver' ) !== LSV07I_VERSION ) {
        LSV07I_DB::install();
        LSV07I_Permissions::install();
        global $wpdb;
        $p = $wpdb->prefix;

        // UNIQUE KEY auf wp_user_id entfernen
        $idx = $wpdb->get_row( "SHOW INDEX FROM {$p}lsv07i_trainer WHERE Key_name = 'uq_user'" );
        if ( $idx ) {
            $wpdb->query( "ALTER TABLE {$p}lsv07i_trainer DROP INDEX uq_user" );
            $wpdb->query( "ALTER TABLE {$p}lsv07i_trainer ADD INDEX idx_user (wp_user_id)" );
        }

        // stundensatz-Spalte nachrüsten
        $col = $wpdb->get_results( "SHOW COLUMNS FROM {$p}lsv07i_trainer_stammdaten LIKE 'stundensatz'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE {$p}lsv07i_trainer_stammdaten ADD COLUMN stundensatz DECIMAL(6,2) NOT NULL DEFAULT 0.00" );
        }

        // Wettkampf-Tabelle
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abr_wettkampf (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            abrechnung_id INT UNSIGNED NOT NULL,
            datum         DATE NOT NULL,
            name          VARCHAR(200) NOT NULL DEFAULT '',
            abschnitte    INT UNSIGNED NOT NULL DEFAULT 1,
            pauschale     DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            betrag        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id), KEY idx_abr (abrechnung_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        // Kilometer-Tabelle
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abr_kilometer (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            abrechnung_id INT UNSIGNED NOT NULL,
            datum         DATE NOT NULL,
            beschreibung  VARCHAR(200) NOT NULL DEFAULT '',
            km            DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            tage          INT UNSIGNED NOT NULL DEFAULT 1,
            satz          DECIMAL(6,4) NOT NULL DEFAULT 0.30,
            betrag        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id), KEY idx_abr (abrechnung_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        // Spalte 'tage' nachrüsten (für bestehende Installationen)
        $col_tage = $wpdb->get_results( "SHOW COLUMNS FROM {$p}lsv07i_abr_kilometer LIKE 'tage'" );
        if ( empty( $col_tage ) ) {
            $wpdb->query( "ALTER TABLE {$p}lsv07i_abr_kilometer ADD COLUMN tage INT UNSIGNED NOT NULL DEFAULT 1 AFTER km" );
        }

        // Kontakte-Tabelle
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_kontakte (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            swimmer_id BIGINT UNSIGNED NOT NULL,
            name       VARCHAR(160) NOT NULL DEFAULT '',
            telefon    VARCHAR(60)  NOT NULL DEFAULT '',
            email      VARCHAR(160) NOT NULL DEFAULT '',
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id), KEY idx_swimmer (swimmer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        // attest_expires in mv_swimmers
        $col2 = $wpdb->get_results( "SHOW COLUMNS FROM {$p}mv_swimmers LIKE 'attest_expires'" );
        if ( empty( $col2 ) ) {
            $wpdb->query( "ALTER TABLE {$p}mv_swimmers ADD COLUMN attest_expires DATE DEFAULT NULL" );
        }

        // Config-Defaults
        foreach ( [ 'attest_warnung_tage' => '30', 'attest_mail_aktiv' => '0' ] as $k => $v ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$p}lsv07i_config (cfg_key, cfg_value) VALUES (%s, %s)", $k, $v
            ) );
        }

        // Bestzeiten-Tabelle nachrüsten
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}lsv07i_bestzeiten (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mannschaft_id INT UNSIGNED NOT NULL,
            swimmer_name  VARCHAR(200) NOT NULL DEFAULT '',
            strecke       VARCHAR(10)  NOT NULL DEFAULT '',
            zeit_raw      VARCHAR(20)  NOT NULL DEFAULT '',
            zeit_sek      DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            importiert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            importiert_von INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_mann (mannschaft_id),
            UNIQUE KEY uq_bz (mannschaft_id, swimmer_name(100), strecke)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

        update_option( 'lsv07i_db_ver', LSV07I_VERSION );

        // Granulares Rechte-System: bei jedem Update einmalig die WP-Rollen aller
        // User auf das Template-Set abbilden. Idempotent — User mit bereits
        // expliziten Rechten werden übersprungen, neu hinzugekommene User mit
        // LSV07-Rollen werden migriert.
        LSV07I_Permissions::migrate_from_legacy();

    // ─────────────────────────────────────────────────────────────────────
    // Spalten-Nachrüstungen — laufen NUR bei Versionswechsel (nicht bei jedem
    // Request!). Früher lief dieser Block bei jedem Seitenaufruf und erzeugte
    // ~127 DB-Abfragen pro Request → massive Verlangsamung der ganzen Website.
    // Jetzt im Versions-Check eingeschlossen: nur einmal pro Plugin-Update.
    // ─────────────────────────────────────────────────────────────────────
    if ( ! isset( $wpdb ) ) global $wpdb;
    $p2 = $wpdb->prefix;

    // manuell_geloescht-Spalte (Soft-Delete für auto-befüllte Trainingstage)
    $col_mg = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_abr_trainingstage LIKE 'manuell_geloescht'" );
    if ( empty( $col_mg ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abr_trainingstage ADD COLUMN manuell_geloescht TINYINT(1) NOT NULL DEFAULT 0" );
    }

    // kommentar-Spalte für Anwesenheits-Sessions (allgemeiner Kommentar zum Training)
    $col_kom = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_anwesenheit LIKE 'kommentar'" );
    if ( empty( $col_kom ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_anwesenheit ADD COLUMN kommentar TEXT NULL AFTER ausgefallen" );
    }

    // bearbeitet_am-Spalte für Konversations-Nachrichten (Rechtsklick → Bearbeiten)
    $konv_msg_tbl = $p2 . 'lsv07i_konv_msg';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$konv_msg_tbl'" ) ) {
        $col_be = $wpdb->get_results( "SHOW COLUMNS FROM $konv_msg_tbl LIKE 'bearbeitet_am'" );
        if ( empty( $col_be ) ) {
            $wpdb->query( "ALTER TABLE $konv_msg_tbl ADD COLUMN bearbeitet_am DATETIME NULL DEFAULT NULL AFTER dringend" );
        }
    }

    // ist_admin-Spalte für Konversations-Teilnehmer (Gruppen-Admin-System)
    $konv_t_tbl = $p2 . 'lsv07i_konv_teilnehmer';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$konv_t_tbl'" ) ) {
        $col_ad = $wpdb->get_results( "SHOW COLUMNS FROM $konv_t_tbl LIKE 'ist_admin'" );
        if ( empty( $col_ad ) ) {
            $wpdb->query( "ALTER TABLE $konv_t_tbl ADD COLUMN ist_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER teilnehmer_name" );
            // Ersteller jeder Konversation automatisch zum Admin machen
            $wpdb->query(
                "UPDATE $konv_t_tbl t
              INNER JOIN {$p2}lsv07i_konv k
                      ON k.id = t.konv_id
                     AND k.erstellt_von = t.teilnehmer_id
                     AND t.teilnehmer_typ = 'user'
                     SET t.ist_admin = 1"
            );
        }
    }

    // vorbereitung_aktiv-Spalte für Trainer-Stammdaten (+15min-Vorbereitungszeit)
    $stamm_tbl = $p2 . 'lsv07i_trainer_stammdaten';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$stamm_tbl'" ) ) {
        $col_vb = $wpdb->get_results( "SHOW COLUMNS FROM $stamm_tbl LIKE 'vorbereitung_aktiv'" );
        if ( empty( $col_vb ) ) {
            $wpdb->query( "ALTER TABLE $stamm_tbl ADD COLUMN vorbereitung_aktiv TINYINT(1) NOT NULL DEFAULT 0 AFTER stundensatz" );
        }
    }

    // vorbereitung_aktiv-Spalte für einzelne Trainingstage (pro Training individuell)
    $abr_tag_tbl = $p2 . 'lsv07i_abr_trainingstage';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$abr_tag_tbl'" ) ) {
        $col_vbt = $wpdb->get_results( "SHOW COLUMNS FROM $abr_tag_tbl LIKE 'vorbereitung_aktiv'" );
        if ( empty( $col_vbt ) ) {
            $wpdb->query( "ALTER TABLE $abr_tag_tbl ADD COLUMN vorbereitung_aktiv TINYINT(1) NOT NULL DEFAULT 0 AFTER begruendung" );
            // Falls in Stammdaten bisher die globale Vorbereitung aktiv war:
            // alle bisherigen Trainingstage übernehmen den Wert (sanfter Übergang)
            $wpdb->query(
                "UPDATE $abr_tag_tbl t
              INNER JOIN {$p2}lsv07i_abrechnung a ON a.id = t.abrechnung_id
              INNER JOIN $stamm_tbl s ON s.trainer_id = a.trainer_id
                     SET t.vorbereitung_aktiv = 1
                   WHERE s.vorbereitung_aktiv = 1"
            );
        }
    }

    // ─── Reflexionsbögen v2: Klartext-Schlüssel → Hash + Rate-Limit-Tabelle ───
    // Bestehende Bögen mit Klartext-Schlüssel werden auf schluessel_hash migriert.
    $refl_bogen_tbl = $p2 . 'lsv07i_refl_bogen';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$refl_bogen_tbl'" ) ) {
        // Neue Spalte schluessel_hash hinzufügen, falls nicht vorhanden
        $col_sh = $wpdb->get_results( "SHOW COLUMNS FROM $refl_bogen_tbl LIKE 'schluessel_hash'" );
        if ( empty( $col_sh ) ) {
            $wpdb->query( "ALTER TABLE $refl_bogen_tbl ADD COLUMN schluessel_hash CHAR(64) NOT NULL DEFAULT '' AFTER swimmer_name" );
            $wpdb->query( "ALTER TABLE $refl_bogen_tbl ADD KEY idx_schluessel_hash (schluessel_hash)" );
        }
        // Alte Klartext-Schlüssel in Hashes umrechnen, dann Spalte leeren
        $col_alt = $wpdb->get_results( "SHOW COLUMNS FROM $refl_bogen_tbl LIKE 'zugangsschluessel'" );
        if ( ! empty( $col_alt ) && class_exists( 'LSV07I_Reflexion' ) ) {
            $alt_rows = $wpdb->get_results( "SELECT id, zugangsschluessel FROM $refl_bogen_tbl WHERE zugangsschluessel <> '' AND schluessel_hash = ''", ARRAY_A );
            foreach ( (array) $alt_rows as $ar ) {
                $hash = LSV07I_Reflexion::schluessel_hash( $ar['zugangsschluessel'] );
                $wpdb->update( $refl_bogen_tbl, [ 'schluessel_hash' => $hash ], [ 'id' => (int) $ar['id'] ], [ '%s' ], [ '%d' ] );
            }
            // Klartext-Spalte entfernen (Schlüssel ab jetzt nur noch als Hash)
            $idx = $wpdb->get_results( "SHOW INDEX FROM $refl_bogen_tbl WHERE Key_name = 'uq_schluessel'" );
            if ( ! empty( $idx ) ) $wpdb->query( "ALTER TABLE $refl_bogen_tbl DROP INDEX uq_schluessel" );
            $wpdb->query( "ALTER TABLE $refl_bogen_tbl DROP COLUMN zugangsschluessel" );
        }
        // ─── Archiv-Funktion: archiviert_am-Spalte + Status-ENUM erweitern ───
        $col_arch = $wpdb->get_results( "SHOW COLUMNS FROM $refl_bogen_tbl LIKE 'archiviert_am'" );
        if ( empty( $col_arch ) ) {
            $wpdb->query( "ALTER TABLE $refl_bogen_tbl ADD COLUMN archiviert_am DATETIME NULL DEFAULT NULL AFTER abgegeben_am" );
        }
        // ENUM um 'archiviert' erweitern (idempotent — MySQL akzeptiert gleiches MODIFY erneut)
        $wpdb->query( "ALTER TABLE $refl_bogen_tbl MODIFY status ENUM('offen','abgegeben','archiviert') NOT NULL DEFAULT 'offen'" );
    }
    // Antwort-Spalten auf größere Typen (verschlüsselte Inhalte brauchen mehr Platz)
    $refl_antw_tbl = $p2 . 'lsv07i_refl_antwort';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$refl_antw_tbl'" ) ) {
        $wpdb->query( "ALTER TABLE $refl_antw_tbl MODIFY antwort_text LONGTEXT DEFAULT NULL" );
        $wpdb->query( "ALTER TABLE $refl_antw_tbl MODIFY wunschzeit VARCHAR(255) DEFAULT NULL" );
    }

    // ─── Bestzeiten: swimmer_id-Spalte ergänzen + aus swimmer_name + mannschaft_id backfillen ───
    $bz_tbl = $p2 . 'lsv07i_bestzeiten';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$bz_tbl'" ) ) {
        $col_swid = $wpdb->get_results( "SHOW COLUMNS FROM $bz_tbl LIKE 'swimmer_id'" );
        if ( empty( $col_swid ) ) {
            $wpdb->query( "ALTER TABLE $bz_tbl ADD COLUMN swimmer_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id" );
            $wpdb->query( "ALTER TABLE $bz_tbl ADD KEY idx_swimmer (swimmer_id)" );

            // Backfill: für jede vorhandene Bestzeile versuchen, einen passenden
            // Schwimmer in mv_swimmers (gleiche Mannschaft) zu finden.
            // Wir nutzen die match_schwimmer-Logik aus class-ajax-bestzeiten.
            if ( class_exists( 'LSV07I_Ajax_Bestzeiten' ) ) {
                // Mannschaften gruppieren, um Schwimmer pro Mannschaft nur einmal zu laden
                $manns = $wpdb->get_col( "SELECT DISTINCT mannschaft_id FROM $bz_tbl WHERE swimmer_id = 0" );
                foreach ( (array) $manns as $mid ) {
                    $mid = (int) $mid;
                    if ( ! $mid ) continue;
                    $sws = $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, first_name, last_name, birth_date FROM {$p2}mv_swimmers WHERE team_id = %d",
                        $mid
                    ), ARRAY_A );
                    if ( empty( $sws ) ) continue;
                    // Distinct swimmer_names dieser Mannschaft
                    $names = $wpdb->get_col( $wpdb->prepare(
                        "SELECT DISTINCT swimmer_name FROM $bz_tbl WHERE mannschaft_id = %d AND swimmer_id = 0",
                        $mid
                    ) );
                    foreach ( (array) $names as $name ) {
                        // Jahrgang aus dem Namen ziehen
                        $jg = 0;
                        if ( preg_match( '/\((\d{4})\)/', (string) $name, $mm ) ) $jg = (int) $mm[1];
                        $match = LSV07I_Ajax_Bestzeiten::match_schwimmer( $name, $jg, $sws );
                        // Wir akzeptieren beim Backfill exact + fuzzy (Ambiguität bleibt 0)
                        if ( $match && ! empty( $match['id'] ) ) {
                            $wpdb->update( $bz_tbl,
                                [ 'swimmer_id' => (int) $match['id'] ],
                                [ 'mannschaft_id' => $mid, 'swimmer_name' => $name ],
                                [ '%d' ], [ '%d', '%s' ]
                            );
                        }
                    }
                }
            }

            // Alten Unique-Index ersetzen (war auf mannschaft_id+name+strecke, jetzt swimmer_id+strecke)
            // Nur entfernen wenn keine Zeilen ohne swimmer_id mehr existieren, sonst
            // würden DOUBLE-Einträge je Schwimmer entstehen.
            $offen = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $bz_tbl WHERE swimmer_id = 0" );
            if ( $offen === 0 ) {
                // Alten Index droppen (kann fehlschlagen wenn Name anders heißt — egal)
                $wpdb->query( "ALTER TABLE $bz_tbl DROP INDEX uq_bz" );
                // Neuen UNIQUE-Index anlegen
                $wpdb->query( "ALTER TABLE $bz_tbl ADD UNIQUE KEY uq_bz_swimmer (swimmer_id, strecke)" );
                // Sekundär-Index auf alten Spalten zum schnellen Lookup beibehalten
                $idx_name = $wpdb->get_results( "SHOW INDEX FROM $bz_tbl WHERE Key_name = 'idx_bz_name'" );
                if ( empty( $idx_name ) ) {
                    $wpdb->query( "ALTER TABLE $bz_tbl ADD KEY idx_bz_name (mannschaft_id, swimmer_name(100), strecke)" );
                }
            }
            // Falls es Zeilen ohne match gibt, bleiben sie mit swimmer_id=0 stehen —
            // Admin sieht sie weiter, Trainer können sie über das neue Match-UI
            // beim nächsten Import korrigieren.
        }
    }
    // Slot-Tabellen aller drei Sparten bekommen eine saison_id-Spalte. Existierende
    // Slots werden auf die Migrations-Saison "Bestehende Trainingszeiten" gemappt.
    $slot_tables = [ 'lsv07i_training_slots', 'lsv07i_tri_slots', 'lsv07i_fit_slots' ];
    $needs_migration = false;
    foreach ( $slot_tables as $st ) {
        $full = $p2 . $st;
        // Existiert die Tabelle überhaupt? (Triathlon/Fitness können fehlen)
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '$full'" );
        if ( ! $exists ) continue;

        $col_sid = $wpdb->get_results( "SHOW COLUMNS FROM $full LIKE 'saison_id'" );
        if ( empty( $col_sid ) ) {
            $wpdb->query( "ALTER TABLE $full ADD COLUMN saison_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id" );
            $wpdb->query( "ALTER TABLE $full ADD KEY idx_saison (saison_id)" );
            $needs_migration = true;
        }
    }

    // Migrations-Saison anlegen falls noch keine Saison existiert
    $saison_tbl = $p2 . 'lsv07i_saisons';
    $saison_exists = $wpdb->get_var( "SHOW TABLES LIKE '$saison_tbl'" );
    if ( $saison_exists ) {
        $any = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $saison_tbl" );
        if ( $any === 0 ) {
            $wpdb->insert( $saison_tbl, [
                'name'        => 'Bestehende Trainingszeiten',
                'start_datum' => '2000-01-01',
                'ende_datum'  => null,
                'aktiv'       => 1,
            ], [ '%s', '%s', '%s', '%d' ] );
            $migration_sid = (int) $wpdb->insert_id;
            // Alle existierenden Slots auf die Migrations-Saison setzen
            foreach ( $slot_tables as $st ) {
                $full = $p2 . $st;
                if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$full'" ) ) continue;
                $wpdb->query( $wpdb->prepare(
                    "UPDATE $full SET saison_id = %d WHERE saison_id = 0", $migration_sid ) );
            }
        }

        // Reparatur: Slots mit saison_id=0 obwohl bereits Saisons existieren.
        // Diese können entstehen wenn die Slot-Save-Logik den saison_id-Parameter
        // mal vergessen hat. Wir ordnen sie der ältesten Saison zu.
        if ( ! get_option( 'lsv07i_orphan_slots_fixed' ) ) {
            $oldest_sid = (int) $wpdb->get_var(
                "SELECT id FROM $saison_tbl ORDER BY start_datum ASC, id ASC LIMIT 1"
            );
            if ( $oldest_sid ) {
                foreach ( $slot_tables as $st ) {
                    $full = $p2 . $st;
                    if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$full'" ) ) continue;
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE $full SET saison_id = %d WHERE saison_id = 0", $oldest_sid ) );
                }
            }
            update_option( 'lsv07i_orphan_slots_fixed', 1 );
        }
    }

    // Schwimmer-Mehrfach-Mannschaft (M:N) – Schwimmer kann in mehreren Gruppen sein
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_swimmer_gruppen (
        swimmer_id  BIGINT UNSIGNED NOT NULL,
        gruppe_id   INT UNSIGNED NOT NULL,
        PRIMARY KEY (swimmer_id, gruppe_id),
        KEY idx_gruppe (gruppe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Bestehende team_id-Zuordnungen in die neue Tabelle migrieren (einmalig)
    $already = $wpdb->get_var( "SELECT COUNT(*) FROM {$p2}lsv07i_swimmer_gruppen" );
    if ( $already == 0 ) {
        $wpdb->query(
            "INSERT IGNORE INTO {$p2}lsv07i_swimmer_gruppen (swimmer_id, gruppe_id)
             SELECT id, team_id FROM {$p2}mv_swimmers WHERE team_id > 0 AND active = 1"
        );
    }

    // Nachrichten-Tabelle
    $col_nm = $wpdb->get_results( "SHOW TABLES LIKE '{$p2}lsv07i_nachrichten'" );
    if ( empty( $col_nm ) ) {
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_nachrichten (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            von_user_id BIGINT UNSIGNED NOT NULL,
            an_user_id  BIGINT UNSIGNED NOT NULL,
            betreff     VARCHAR(200) NOT NULL DEFAULT '',
            nachricht   TEXT NOT NULL,
            gelesen     TINYINT(1) NOT NULL DEFAULT 0,
            gelesen_am  DATETIME DEFAULT NULL,
            erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_an (an_user_id),
            KEY idx_von (von_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    }

    // ── Migration alte Nachrichten → Konversations-Modell ─────────────────
    // Läuft einmalig wenn die neue Konv-Tabelle leer und die alte gefüllt ist.
    $konv_tbl  = $p2 . 'lsv07i_konv';
    $alt_tbl   = $p2 . 'lsv07i_nachrichten';
    $migrated  = (bool) get_option( 'lsv07i_konv_migrated', false );
    if ( ! $migrated
         && $wpdb->get_var( "SHOW TABLES LIKE '$konv_tbl'" )
         && $wpdb->get_var( "SHOW TABLES LIKE '$alt_tbl'" ) ) {
        $alt_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $alt_tbl" );
        if ( $alt_count > 0 ) {
            // Eindeutige User-Paare bestimmen — pro Paar entsteht eine Direkt-Konv
            $paare = $wpdb->get_results(
                "SELECT LEAST(von_user_id, an_user_id) AS u1,
                        GREATEST(von_user_id, an_user_id) AS u2
                   FROM $alt_tbl
                  GROUP BY u1, u2",
                ARRAY_A
            );
            foreach ( (array) $paare as $paar ) {
                $u1 = (int) $paar['u1'];
                $u2 = (int) $paar['u2'];
                if ( $u1 === 0 || $u2 === 0 || $u1 === $u2 ) continue;

                // Konversation anlegen
                $wpdb->insert( $konv_tbl, [
                    'typ'              => 'direkt',
                    'erstellt_von'     => $u1,
                    'erstellt_am'      => current_time( 'mysql' ),
                    'letzte_aktivitaet'=> current_time( 'mysql' ),
                ], [ '%s', '%d', '%s', '%s' ] );
                $konv_id = (int) $wpdb->insert_id;
                if ( ! $konv_id ) continue;

                // Beide Teilnehmer anlegen
                foreach ( [ $u1, $u2 ] as $uid ) {
                    $wpdb->insert( $p2 . 'lsv07i_konv_teilnehmer', [
                        'konv_id'        => $konv_id,
                        'teilnehmer_typ' => 'user',
                        'teilnehmer_id'  => $uid,
                    ], [ '%d', '%s', '%d' ] );
                }

                // Alle Nachrichten dieses Paares in die neue Tabelle umkopieren
                $msgs = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM $alt_tbl
                      WHERE (von_user_id = %d AND an_user_id = %d)
                         OR (von_user_id = %d AND an_user_id = %d)
                      ORDER BY erstellt_am ASC, id ASC",
                    $u1, $u2, $u2, $u1
                ), ARRAY_A );
                $last_id = 0;
                foreach ( (array) $msgs as $m ) {
                    // Betreff + Text werden zusammengefügt — beim alten Modell
                    // war der Betreff teils relevant, im neuen gibt's keinen mehr
                    $text = '';
                    if ( ! empty( $m['betreff'] ) ) {
                        $text .= '[' . $m['betreff'] . "]\n";
                    }
                    $text .= $m['nachricht'];
                    $wpdb->insert( $p2 . 'lsv07i_konv_msg', [
                        'konv_id'     => $konv_id,
                        'von_typ'     => 'user',
                        'von_id'      => (int) $m['von_user_id'],
                        'text'        => $text,
                        'erstellt_am' => $m['erstellt_am'],
                    ], [ '%d', '%s', '%d', '%s', '%s' ] );
                    $last_id = (int) $wpdb->insert_id;
                }
                if ( $last_id ) {
                    // Letzte Aktivität setzen
                    $wpdb->update( $konv_tbl,
                        [ 'letzte_aktivitaet' => $m['erstellt_am'] ?? current_time( 'mysql' ) ],
                        [ 'id' => $konv_id ], [ '%s' ], [ '%d' ]
                    );
                }
            }
        }
        update_option( 'lsv07i_konv_migrated', 1 );
    }

    // bezahlt-Flag und bezahlt_am für Kassenwart
    $col_bz = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_abrechnung LIKE 'bezahlt'" );
    if ( empty( $col_bz ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abrechnung ADD COLUMN bezahlt TINYINT(1) NOT NULL DEFAULT 0" );
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abrechnung ADD COLUMN bezahlt_am DATETIME DEFAULT NULL" );
    }

    // ── Triathlon-Tabellen sicherstellen ─────────────────────────────────────
    // Triathlon-Anwesenheit (parallel zu Schwimm-Anwesenheit)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_tri_anwesenheit (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        gruppe_id       INT UNSIGNED NOT NULL,
        training_datum  DATE NOT NULL,
        ausgefallen     TINYINT(1) NOT NULL DEFAULT 0,
        ausfall_grund   VARCHAR(255) DEFAULT NULL,
        erstellt_von    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        erstellt_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_gruppe_datum (gruppe_id, training_datum),
        KEY idx_datum (training_datum)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_tri_anwesenheit_eintraege (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        anwesenheit_id  INT UNSIGNED NOT NULL,
        sportler_id     INT UNSIGNED NOT NULL,
        status          VARCHAR(20) NOT NULL DEFAULT 'anwesend',
        PRIMARY KEY (id),
        UNIQUE KEY uq_anw_sportler (anwesenheit_id, sportler_id),
        KEY idx_sportler (sportler_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_tri_gruppen (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(100) NOT NULL,
        sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_tri_sportler (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name  VARCHAR(80) NOT NULL DEFAULT '',
        last_name   VARCHAR(80) NOT NULL DEFAULT '',
        birth_date  DATE DEFAULT NULL,
        email       VARCHAR(150) NOT NULL DEFAULT '',
        notes       TEXT,
        aktiv       TINYINT(1) NOT NULL DEFAULT 1,
        erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Triathlon-Trainingszeiten (analog zu lsv07i_training_slots)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_tri_slots (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        gruppe_id  INT UNSIGNED NOT NULL,
        wochentag  TINYINT UNSIGNED NOT NULL DEFAULT 1,
        zeit_von   TIME NOT NULL DEFAULT '00:00:00',
        zeit_bis   TIME NOT NULL DEFAULT '00:00:00',
        PRIMARY KEY (id),
        KEY idx_gruppe (gruppe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Triathlon-Anwesenheit auf slot_id umstellen (slot_id Spalte nachrüsten)
    $col_tri_slot = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_tri_anwesenheit LIKE 'slot_id'" );
    if ( empty( $col_tri_slot ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_tri_anwesenheit ADD COLUMN slot_id INT UNSIGNED DEFAULT NULL AFTER gruppe_id" );
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_tri_anwesenheit ADD UNIQUE KEY uq_slot_datum (slot_id, training_datum)" );
    }

    // M:N — Sportler kann in mehreren Gruppen sein
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_tri_sportler_gruppe (
        sportler_id INT UNSIGNED NOT NULL,
        gruppe_id   INT UNSIGNED NOT NULL,
        PRIMARY KEY (sportler_id, gruppe_id),
        KEY idx_gruppe (gruppe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_tri_trainer (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(100) NOT NULL,
        email       VARCHAR(150) NOT NULL DEFAULT '',
        wp_user_id  BIGINT UNSIGNED DEFAULT NULL,
        aktiv       TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY idx_user (wp_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_tri_trainer_gruppe (
        trainer_id  INT UNSIGNED NOT NULL,
        gruppe_id   INT UNSIGNED NOT NULL,
        PRIMARY KEY (trainer_id, gruppe_id),
        KEY idx_gruppe (gruppe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // ─────────────────────────────────────────────────────────────────────────
    //  6.7.0 — Fitness-Bereich (analog zu Triathlon)
    // ─────────────────────────────────────────────────────────────────────────

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_fit_anwesenheit (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        gruppe_id       INT UNSIGNED NOT NULL,
        slot_id         INT UNSIGNED DEFAULT NULL,
        training_datum  DATE NOT NULL,
        ausgefallen     TINYINT(1) NOT NULL DEFAULT 0,
        ausfall_grund   VARCHAR(255) DEFAULT NULL,
        erstellt_von    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        erstellt_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_slot_datum (slot_id, training_datum),
        KEY idx_datum (training_datum),
        KEY idx_gruppe (gruppe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_fit_anwesenheit_eintraege (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        anwesenheit_id  INT UNSIGNED NOT NULL,
        sportler_id     INT UNSIGNED NOT NULL,
        status          VARCHAR(20) NOT NULL DEFAULT 'abwesend',
        PRIMARY KEY (id),
        UNIQUE KEY uq_anw_sportler (anwesenheit_id, sportler_id),
        KEY idx_sportler (sportler_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_fit_gruppen (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(100) NOT NULL,
        sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        aktiv       TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_fit_slots (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        gruppe_id  INT UNSIGNED NOT NULL,
        wochentag  TINYINT UNSIGNED NOT NULL DEFAULT 1,
        zeit_von   TIME NOT NULL DEFAULT '00:00:00',
        zeit_bis   TIME NOT NULL DEFAULT '00:00:00',
        PRIMARY KEY (id),
        KEY idx_gruppe (gruppe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_fit_trainer (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name        VARCHAR(100) NOT NULL,
        email       VARCHAR(150) NOT NULL DEFAULT '',
        wp_user_id  BIGINT UNSIGNED DEFAULT NULL,
        aktiv       TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        KEY idx_user (wp_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_fit_trainer_gruppe (
        trainer_id  INT UNSIGNED NOT NULL,
        gruppe_id   INT UNSIGNED NOT NULL,
        PRIMARY KEY (trainer_id, gruppe_id),
        KEY idx_gruppe (gruppe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_fit_sportler (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name  VARCHAR(80) NOT NULL DEFAULT '',
        last_name   VARCHAR(80) NOT NULL DEFAULT '',
        birth_date  DATE DEFAULT NULL,
        email       VARCHAR(150) NOT NULL DEFAULT '',
        notes       TEXT,
        aktiv       TINYINT(1) NOT NULL DEFAULT 1,
        erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_fit_sportler_gruppe (
        sportler_id INT UNSIGNED NOT NULL,
        gruppe_id   INT UNSIGNED NOT NULL,
        PRIMARY KEY (sportler_id, gruppe_id),
        KEY idx_gruppe (gruppe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // bereich-Spalte für Abrechnung (schwimmen / triathlon)
    $col_abr_ber = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_abrechnung LIKE 'bereich'" );
    if ( empty( $col_abr_ber ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abrechnung ADD COLUMN bereich VARCHAR(20) NOT NULL DEFAULT 'schwimmen' AFTER trainer_id" );
        // Unique Key anpassen (trainer_id + quartal + jahr + bereich)
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abrechnung DROP INDEX uq_trainer_qj" );
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abrechnung ADD UNIQUE KEY uq_trainer_bereich_qj (trainer_id, bereich, quartal, jahr)" );
    }

    // Tri-Trainer-ID via WP-User-ID: Hilfstabelle für schnelles Lookup
    // (kein eigener Schritt nötig – wp_user_id ist bereits in lsv07i_tri_trainer)

    // ── Trainer: neue Felder nachrüsten ──────────────────────────────────────
    $trainer_cols = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_trainer" );
    $trainer_col_names = wp_list_pluck( $trainer_cols, 'Field' );
    $trainer_new = [
        'vorname'          => "VARCHAR(80) NOT NULL DEFAULT ''",
        'geburtsdatum'     => "DATE DEFAULT NULL",
        'telefon'          => "VARCHAR(60) NOT NULL DEFAULT ''",
        'email_privat'     => "VARCHAR(150) NOT NULL DEFAULT ''",
        'email_geschaeft'  => "VARCHAR(150) NOT NULL DEFAULT ''",
        'trainervertrag'   => "TINYINT(1) NOT NULL DEFAULT 0",
        'fuehrungszeugnis' => "DATE DEFAULT NULL",
        'verhaltenskodex'  => "TINYINT(1) NOT NULL DEFAULT 0",
        'datenschutz'      => "TINYINT(1) NOT NULL DEFAULT 0",
        'rettungsschwimmer'=> "DATE DEFAULT NULL",
        'trainerlizenz'    => "TINYINT(1) NOT NULL DEFAULT 0",
        'lizenznummer'     => "VARCHAR(60) NOT NULL DEFAULT ''",
        'lizenz_gueltig'   => "DATE DEFAULT NULL",
        'display_name'     => "VARCHAR(200) NOT NULL DEFAULT ''",
    ];
    foreach ( $trainer_new as $col => $def ) {
        if ( ! in_array( $col, $trainer_col_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$p2}lsv07i_trainer ADD COLUMN $col $def" );
        }
    }
    // Bestehende display_name berechnen wo leer (aus name-Feld)
    $wpdb->query( "UPDATE {$p2}lsv07i_trainer SET display_name = name WHERE display_name = '' AND name != ''" );

    // Trainer-Ausschluss aus Anwesenheit
    // Trainer-Ausschluss gilt pro Slot (alle Trainings dieses Wochentags der Mannschaft)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_anwesenheit_ausschluss (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slot_id            INT UNSIGNED NOT NULL,
        trainer_id         INT UNSIGNED NOT NULL,
        ausgeschlossen_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_slot_trainer (slot_id, trainer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
    // Alte Tabelle mit anwesenheit_id migrieren falls vorhanden
    $col_slot = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_anwesenheit_ausschluss LIKE 'slot_id'" );
    if ( empty( $col_slot ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_anwesenheit_ausschluss DROP INDEX uq_anw_trainer" );
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_anwesenheit_ausschluss CHANGE anwesenheit_id slot_id INT UNSIGNED NOT NULL" );
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_anwesenheit_ausschluss ADD UNIQUE KEY uq_slot_trainer (slot_id, trainer_id)" );
        $wpdb->query( "TRUNCATE TABLE {$p2}lsv07i_anwesenheit_ausschluss" );
    }

    // ── Schwimmer: neue Felder nachrüsten ─────────────────────────────────────
    $sw_cols = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}mv_swimmers" );
    $sw_col_names = wp_list_pluck( $sw_cols, 'Field' );
    $sw_new = [
        'datenschutz'  => "TINYINT(1) NOT NULL DEFAULT 0",
        'dsv_id'       => "VARCHAR(40) NOT NULL DEFAULT ''",
        'display_name' => "VARCHAR(200) NOT NULL DEFAULT ''",
    ];
    foreach ( $sw_new as $col => $def ) {
        if ( ! in_array( $col, $sw_col_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$p2}mv_swimmers ADD COLUMN $col $def" );
        }
    }
    // Bestehende display_name berechnen wo leer
    $wpdb->query( "UPDATE {$p2}mv_swimmers SET display_name = CONCAT(last_name, ', ', first_name, ' (', YEAR(birth_date), ')') WHERE display_name = '' AND last_name != ''" );

    // empfaenger_name in trainer_stammdaten
    $col_emp = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_trainer_stammdaten LIKE 'empfaenger_name'" );
    if ( empty( $col_emp ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_trainer_stammdaten ADD COLUMN empfaenger_name VARCHAR(200) NOT NULL DEFAULT '' AFTER stundensatz" );
    }

    // Migration 5.3.0 → 5.3.1 (legacy, falls jemand genau auf 5.3.0 war)
    // (legacy 5.3.0 migration removed - handled by version check above)

    // ─────────────────────────────────────────────────────────────────────────
    //  6.5.0 — Wettkampf-Anwesenheit
    // ─────────────────────────────────────────────────────────────────────────

    // Stammdaten eines Wettkampfs (Name, Datumsbereich, Pauschale-Snapshot)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_wettkampf (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name          VARCHAR(200) NOT NULL DEFAULT '',
        ort           VARCHAR(200) NOT NULL DEFAULT '',
        datum_von     DATE NOT NULL,
        datum_bis     DATE NOT NULL,
        pauschale     DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        angelegt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        angelegt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_datum (datum_von, datum_bis)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Teilnehmende Mannschaften (M:N)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_wettkampf_mannschaft (
        wettkampf_id   INT UNSIGNED NOT NULL,
        mannschaft_id  INT UNSIGNED NOT NULL,
        PRIMARY KEY (wettkampf_id, mannschaft_id),
        KEY idx_mannschaft (mannschaft_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Ein Eintrag pro Wettkampftag (mit geplanter Abschnittszahl für diesen Tag)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_wettkampf_tage (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        wettkampf_id    INT UNSIGNED NOT NULL,
        datum           DATE NOT NULL,
        abschnitte_plan TINYINT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wk_datum (wettkampf_id, datum),
        KEY idx_wk (wettkampf_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Anwesenheits-Session pro Wettkampftag × Mannschaft
    // (analog zu lsv07i_anwesenheit, aber für Wettkämpfe)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_wettkampf_anwesenheit (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        wettkampf_tag_id  INT UNSIGNED NOT NULL,
        mannschaft_id     INT UNSIGNED NOT NULL,
        erstellt_von      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        erstellt_am       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tag_mann (wettkampf_tag_id, mannschaft_id),
        KEY idx_mann (mannschaft_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Einträge pro Teilnehmer pro Wettkampftag×Mannschaft
    // Für Trainer: zusätzlich abschnitte (wieviele Abschnitte er an dem Tag begleitet hat)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_wettkampf_anw_eintraege (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
        wk_anwesenheit_id  INT UNSIGNED NOT NULL,
        teilnehmer_typ     VARCHAR(20) NOT NULL DEFAULT 'schwimmer',
        teilnehmer_id      INT UNSIGNED NOT NULL,
        status             VARCHAR(20) NOT NULL DEFAULT 'abwesend',
        abschnitte         TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_anw_teiln (wk_anwesenheit_id, teilnehmer_typ, teilnehmer_id),
        KEY idx_anw (wk_anwesenheit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Erweiterungen an lsv07i_abr_wettkampf: Verknüpfung zu Wettkampftag und Mannschaft,
    // Quelle (manuell/anwesenheit) für bidirektionale Sync-Löschung
    $abrwk_cols = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_abr_wettkampf" );
    $abrwk_names = wp_list_pluck( $abrwk_cols, 'Field' );
    if ( ! in_array( 'wettkampf_tag_id', $abrwk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abr_wettkampf ADD COLUMN wettkampf_tag_id INT UNSIGNED DEFAULT NULL AFTER abrechnung_id" );
    }
    if ( ! in_array( 'mannschaft_id', $abrwk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abr_wettkampf ADD COLUMN mannschaft_id INT UNSIGNED DEFAULT NULL AFTER wettkampf_tag_id" );
    }
    if ( ! in_array( 'quelle', $abrwk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abr_wettkampf ADD COLUMN quelle VARCHAR(20) NOT NULL DEFAULT 'manuell' AFTER betrag" );
    }
    if ( ! in_array( 'manuell_geloescht', $abrwk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_abr_wettkampf ADD COLUMN manuell_geloescht TINYINT(1) NOT NULL DEFAULT 0" );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  6.6.0 — Zentrale Personen-Verwaltung (Iteration 1)
    //
    //  Neue Tabellen für eine vereinheitlichte Verwaltung von Personen
    //  (Sportler + Trainer) über alle Sparten (Schwimmen, Triathlon, Fitness).
    //  Bestehende Tabellen bleiben unverändert in Betrieb — diese neuen
    //  Tabellen sind zunächst eine zusätzliche Schicht. Spätere Iterationen
    //  werden die UIs schrittweise auf die neuen Tabellen umstellen.
    // ─────────────────────────────────────────────────────────────────────────

    // Zentrale Personen-Tabelle
    // Eine Zeile pro reale Person. Über M:N-Tabellen werden Sparten,
    // Rollen und Mannschaften zugewiesen.
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_personen (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        vorname         VARCHAR(100) NOT NULL DEFAULT '',
        nachname        VARCHAR(100) NOT NULL DEFAULT '',
        geburtsdatum    DATE DEFAULT NULL,
        geschlecht      CHAR(1) DEFAULT NULL,
        email           VARCHAR(180) NOT NULL DEFAULT '',
        telefon         VARCHAR(60) NOT NULL DEFAULT '',
        dsv_id          VARCHAR(40) NOT NULL DEFAULT '',
        mitgliedsnummer VARCHAR(40) NOT NULL DEFAULT '',
        wp_user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        notes           TEXT,
        aktiv           TINYINT(1) NOT NULL DEFAULT 1,
        legacy_swimmer_id    BIGINT UNSIGNED DEFAULT NULL,
        legacy_trainer_id    INT UNSIGNED DEFAULT NULL,
        legacy_tri_sportler_id INT UNSIGNED DEFAULT NULL,
        legacy_tri_trainer_id  INT UNSIGNED DEFAULT NULL,
        erstellt_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        geaendert_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_name (nachname, vorname),
        KEY idx_user (wp_user_id),
        KEY idx_legacy_sw (legacy_swimmer_id),
        KEY idx_legacy_tr (legacy_trainer_id),
        KEY idx_legacy_tri_sp (legacy_tri_sportler_id),
        KEY idx_legacy_tri_tr (legacy_tri_trainer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Personen × Sparte × Rolle
    // Eine Person kann pro Sparte sowohl Sportler als auch Trainer sein.
    // Sparten: schwimmen, triathlon, fitness
    // Rollen:  sportler, trainer
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_personen_sparten_rolle (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id   INT UNSIGNED NOT NULL,
        sparte      VARCHAR(20) NOT NULL,
        rolle       VARCHAR(20) NOT NULL,
        aktiv       TINYINT(1) NOT NULL DEFAULT 1,
        erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_person_sparte_rolle (person_id, sparte, rolle),
        KEY idx_sparte_rolle (sparte, rolle)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Personen × Mannschaft (M:N, mannschaft_id verweist auf alte
    // Mannschaftstabellen — Schwimmen: lsv07_gruppen, Triathlon:
    // lsv07i_tri_gruppen, Fitness: kommt mit Iteration 3)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_personen_mannschaft (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id     INT UNSIGNED NOT NULL,
        sparte        VARCHAR(20) NOT NULL,
        mannschaft_id INT UNSIGNED NOT NULL,
        rolle         VARCHAR(20) NOT NULL DEFAULT 'sportler',
        erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_person_sparte_mann_rolle (person_id, sparte, mannschaft_id, rolle),
        KEY idx_sparte_mann (sparte, mannschaft_id),
        KEY idx_person (person_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // ─────────────────────────────────────────────────────────────────────────
    //  7.64.0 — Wettkampfmeldungen
    // ─────────────────────────────────────────────────────────────────────────

    // Kopf einer Meldeliste: ein Wettkampf × eine Mannschaft. Abschnitte und
    // Wettkampfnummern begrenzen die Auswahl in der Tabelle.
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_meldung (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        wettkampf_id     INT UNSIGNED NOT NULL,
        mannschaft_id    INT UNSIGNED NOT NULL,
        abschnitte       TINYINT UNSIGNED NOT NULL DEFAULT 1,
        wettkampfnummern SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        angelegt_von     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        angelegt_am      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        geaendert_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wk_mann (wettkampf_id, mannschaft_id),
        KEY idx_mannschaft (mannschaft_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Eine Zeile pro Start. Ein Schwimmer, der dreimal schwimmt, hat drei
    // Zeilen. Name und Jahrgang liegen als Kopie mit, damit eine abgegebene
    // Meldung auch dann noch stimmt, wenn der Schwimmer später umzieht.
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_meldung_start (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        meldung_id      INT UNSIGNED NOT NULL,
        schwimmer_id    INT UNSIGNED NOT NULL DEFAULT 0,
        schwimmer_name  VARCHAR(200) NOT NULL DEFAULT '',
        jahrgang        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        abschnitt       TINYINT UNSIGNED NOT NULL DEFAULT 0,
        wettkampf_nr    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        strecke         VARCHAR(10) NOT NULL DEFAULT '',
        meldezeit       VARCHAR(12) NOT NULL DEFAULT '',
        attest_bis      DATE DEFAULT NULL,
        sortierung      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_meldung (meldung_id, sortierung)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // 7.65.0 — Attest-Ablaufdatum je Meldezeile. Wird beim Anlegen der Zeile
    // aus den Stammdaten vorbelegt, ist danach aber nur für diese Meldung
    // gültig; die Stammdaten des Schwimmers bleiben unberührt.
    $meld_att = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_meldung_start LIKE 'attest_bis'" );
    if ( empty( $meld_att ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_meldung_start
                       ADD COLUMN attest_bis DATE DEFAULT NULL AFTER meldezeit" );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  7.66.0 — Wettkampf-Freigabe, Dokumente, Erinnerungs-Mails
    // ─────────────────────────────────────────────────────────────────────────

    // Freigabe-Status + Versendet-Flags gegen Doppelversand der automatischen
    // Mails (Freigabe-Anfrage, Erinnerung Meldeergebnis, Erinnerung Protokoll).
    $wk_cols  = $wpdb->get_results( "SHOW COLUMNS FROM {$p2}lsv07i_wettkampf" );
    $wk_names = wp_list_pluck( $wk_cols, 'Field' );
    if ( ! in_array( 'freigegeben', $wk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_wettkampf ADD COLUMN freigegeben TINYINT(1) NOT NULL DEFAULT 0 AFTER pauschale" );
    }
    if ( ! in_array( 'freigegeben_von', $wk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_wettkampf ADD COLUMN freigegeben_von BIGINT UNSIGNED DEFAULT NULL AFTER freigegeben" );
    }
    if ( ! in_array( 'freigegeben_am', $wk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_wettkampf ADD COLUMN freigegeben_am DATETIME DEFAULT NULL AFTER freigegeben_von" );
    }
    if ( ! in_array( 'mail_approve_gesendet', $wk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_wettkampf ADD COLUMN mail_approve_gesendet TINYINT(1) NOT NULL DEFAULT 0" );
    }
    if ( ! in_array( 'mail_meldeergebnis_gesendet', $wk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_wettkampf ADD COLUMN mail_meldeergebnis_gesendet TINYINT(1) NOT NULL DEFAULT 0" );
    }
    if ( ! in_array( 'mail_protokoll_gesendet', $wk_names, true ) ) {
        $wpdb->query( "ALTER TABLE {$p2}lsv07i_wettkampf ADD COLUMN mail_protokoll_gesendet TINYINT(1) NOT NULL DEFAULT 0" );
    }

    // Dokumente je Wettkampf: Ausschreibung (Pflicht vor Freigabe), Meldeergebnis,
    // Protokoll. Ein aktuelles Dokument je Typ — erneutes Hochladen ersetzt es.
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_wettkampf_dokument (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
        wettkampf_id     INT UNSIGNED NOT NULL,
        typ              VARCHAR(20) NOT NULL,
        dateiname        VARCHAR(255) NOT NULL DEFAULT '',
        gespeichert_als  VARCHAR(64) NOT NULL DEFAULT '',
        mime_type        VARCHAR(100) NOT NULL DEFAULT '',
        groesse          INT UNSIGNED NOT NULL DEFAULT 0,
        hochgeladen_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        hochgeladen_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wk_typ (wettkampf_id, typ)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Erinnerungs-E-Mail-Adressen je Wettkampf (unabhängig von Benutzerkonten)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_wettkampf_erinnerung (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        wettkampf_id  INT UNSIGNED NOT NULL,
        email         VARCHAR(200) NOT NULL,
        hinzugefuegt_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
        hinzugefuegt_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wk_email (wettkampf_id, email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // ─────────────────────────────────────────────────────────────────────────
    //  8.1.0 — Wettkampf: zusätzliche Mail-Empfänger (Admin-Verwaltung)
    // ─────────────────────────────────────────────────────────────────────────
    // Feste, admin-verwaltete Adressen, die zusätzlich zu den ohnehin
    // automatisch ermittelten Empfängern (Freigabe-Berechtigte bzw. die pro
    // Wettkampf hinterlegten Erinnerungsadressen) bestimmte Wettkampf-Mails
    // erhalten sollen — je Adresse einzeln je Mailtyp an-/abschaltbar.
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_wettkampf_mail_empfaenger (
        id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
        email                     VARCHAR(200) NOT NULL,
        freigabe_anfrage          TINYINT(1) NOT NULL DEFAULT 0,
        erinnerung_meldeergebnis  TINYINT(1) NOT NULL DEFAULT 0,
        erinnerung_protokoll      TINYINT(1) NOT NULL DEFAULT 0,
        hinzugefuegt_von          BIGINT UNSIGNED NOT NULL DEFAULT 0,
        hinzugefuegt_am           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    // Migrations-Audit: protokolliert was beim ersten Migrationslauf passiert.
    // Wird genau einmal beschrieben (beim Übergang von 6.5.x auf 6.6.0).
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p2}lsv07i_personen_migration_log (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        zeitpunkt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        quelle      VARCHAR(40) NOT NULL,
        quelle_id   VARCHAR(40) NOT NULL,
        person_id   INT UNSIGNED DEFAULT NULL,
        aktion      VARCHAR(40) NOT NULL,
        notiz       TEXT,
        PRIMARY KEY (id),
        KEY idx_quelle (quelle, quelle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );

    } // Ende Versions-Check — Spalten-Nachrüstungen laufen nur bei Versionswechsel

    // Personen-Migration: eigener Flag-Schutz, läuft unabhängig vom Versions-Check
    // einmalig durch (idempotent durch UNIQUE-Keys + Flag).
    if ( get_option( 'lsv07i_personen_migration_done' ) !== '6.6.0' ) {
        require_once LSV07I_DIR . 'includes/class-personen-migration.php';
        LSV07I_Personen_Migration::run();
        update_option( 'lsv07i_personen_migration_done', '6.6.0' );
    }

    LSV07I_Roles::init();
    LSV07I_Ajax_Admin::init();
    LSV07I_Ajax_Schwimmen::init();
    LSV07I_Ajax_Anwesenheit::init();
    LSV07I_Ajax_Wettkampf::init();
    LSV07I_Wettkampf_Oeffentlich::init();
    LSV07I_Ajax_Meldung::init();
    LSV07I_Ajax_Akte::init();
    LSV07I_Ajax_Home::init();
    LSV07I_Ajax_Profil::init();
    LSV07I_Ajax_Tickets::init();
    LSV07I_Ajax_Atteste::init();
    LSV07I_Ajax_Springer::init();
    LSV07I_Ajax_Bestzeiten::init();
    LSV07I_Ajax_Rechte::init();
    LSV07I_Ajax_Schwimmer_Dateien::init();
    LSV07I_Ajax_Log::init();
    LSV07I_Ajax_Stammdaten::init();
    LSV07I_Ajax_Saison::init();
    LSV07I_Ajax_Konversation::init();
    LSV07I_Ajax_Reflexion::init();
    LSV07I_Reflexion_Public::init();
    LSV07I_Email_Versand::init();
    LSV07I_Magic_Link::init();
    LSV07I_Imap_Reader::init();
    LSV07I_Ajax_Abrechnung::init();
    LSV07I_Ajax_Sonderabrechnung::init();
    LSV07I_Ajax_Triathlon::init();
    LSV07I_Ajax_Fitness::init();
    LSV07I_Ajax_Personen_Admin::init();
    LSV07I_Ajax_Import::init();
    LSV07I_Shortcode::init();
    LSV07I_Cron::init();
    LSV07I_Privacy::init();
} );
