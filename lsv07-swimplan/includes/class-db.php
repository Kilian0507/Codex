<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Tabellen von SwimPlan.
 *
 * Aufgebaut wie im Konzept beschrieben: Personen, Mannschaften und ihre
 * Zuordnungen, Trainingseinheiten mit ihren Sets, die Freigaben, die
 * Übungsbibliothek und die Zeiten. Dazu zwei Tabellen, die im Konzept nur
 * als Anforderung stehen: die Versionen eines veröffentlichten Plans und
 * die Prüfspur.
 *
 * Zwei Festlegungen, die sich durch alles ziehen:
 *
 *  - Eine Person ist immer ein WordPress-Konto. SwimPlan führt keine
 *    eigenen Passwörter, keine eigenen Sitzungen und keine eigene
 *    Anmeldung. Was es führt, ist die ROLLE dieses Kontos.
 *  - Zeiten stehen als ganze Hundertstelsekunden darin. 01:02,35 ist
 *    6235. Mit Kommazahlen käme früher oder später eine Zeit heraus, die
 *    um ein Hundertstel danebenliegt.
 */
class LSV07P_DB {

    /** Wie lange die Prüfspur aufgehoben wird (Tage). */
    const SPUR_TAGE = 180;

    /** Wie viele Versionen eines Plans aufgehoben werden. */
    const VERSIONEN_MAX = 20;

    public static function install() {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( self::tabellen_sql( $p ) as $sql ) {
            $wpdb->query( $sql );
        }
    }

    /**
     * Die CREATE-Texte an einer Stelle, damit Aktivierung, Selbstheilung
     * und Prüfstand denselben Aufbau benutzen.
     */
    public static function tabellen_sql( $p, $charset = null ) {
        if ( $charset === null ) {
            $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        return [

            /* Wer SwimPlan benutzen darf und als was.
               Ein WordPress-Konto ohne Eintrag hier kommt nicht hinein —
               auch kein Administrator der Seite, solange er sich nicht
               selbst einträgt. Das ist Absicht: Wer Trainingsdaten von
               Kindern sehen soll, wird benannt und nicht abgeleitet. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_person (
                wp_user_id   BIGINT UNSIGNED NOT NULL,
                rolle        VARCHAR(10) NOT NULL DEFAULT 'swimmer',
                aktiv        TINYINT(1) NOT NULL DEFAULT 1,
                notiz        VARCHAR(200) NOT NULL DEFAULT '',
                angelegt_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                angelegt_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (wp_user_id),
                KEY idx_rolle (rolle, aktiv)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_team (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(150) NOT NULL DEFAULT '',
                beschreibung TEXT NULL,
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                sortierung  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                angelegt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aktiv (aktiv, sortierung)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_team_trainer (
                team_id     INT UNSIGNED NOT NULL,
                wp_user_id  BIGINT UNSIGNED NOT NULL,
                zugeteilt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (team_id, wp_user_id),
                KEY idx_user (wp_user_id)
            ) $charset",

            /* Mitgliedschaft mit Start und Ende: Wer die Mannschaft
               verlässt, verliert seine Historie nicht. `ende_datum` leer
               heisst: läuft. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_team_mitglied (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                team_id     INT UNSIGNED NOT NULL,
                wp_user_id  BIGINT UNSIGNED NOT NULL,
                start_datum DATE NOT NULL,
                ende_datum  DATE NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_team (team_id, ende_datum),
                KEY idx_user (wp_user_id, ende_datum)
            ) $charset",

            /* Eine Trainingseinheit. `version` zählt hoch, sobald nach der
               Veröffentlichung etwas geändert wird. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_plan (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_user  BIGINT UNSIGNED NOT NULL,
                titel         VARCHAR(200) NOT NULL DEFAULT '',
                datum         DATE NOT NULL,
                startzeit     TIME NULL DEFAULT NULL,
                dauer_min     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                bahnlaenge    SMALLINT UNSIGNED NOT NULL DEFAULT 25,
                ziel          VARCHAR(150) NOT NULL DEFAULT '',
                status        VARCHAR(12) NOT NULL DEFAULT 'draft',
                notizen       TEXT NULL,
                version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                zusatz_min    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_trainer (trainer_user, datum),
                KEY idx_datum (datum, status)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_set (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id       INT UNSIGNED NOT NULL,
                position      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                titel         VARCHAR(200) NOT NULL DEFAULT '',
                wiederholungen SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                strecke_m     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                lage          VARCHAR(30) NOT NULL DEFAULT 'frei',
                intervall_sek SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                pause_sek     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                material      VARCHAR(200) NOT NULL DEFAULT '',
                hinweis       TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_plan (plan_id, position)
            ) $charset",

            /* Freigabe: entweder an eine Mannschaft ODER an eine Person.
               Beides zugleich wäre nicht eindeutig; die Prüfung dazu steht
               in LSV07P_Plan. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_freigabe (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id       INT UNSIGNED NOT NULL,
                team_id       INT UNSIGNED NOT NULL DEFAULT 0,
                wp_user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                frei_am       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                frei_von      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ziel (plan_id, team_id, wp_user_id),
                KEY idx_team (team_id),
                KEY idx_user (wp_user_id)
            ) $charset",

            /* Jede Änderung an einem veröffentlichten Plan wird hier
               festgehalten — als vollständige Abschrift, damit sie auch
               dann noch lesbar ist, wenn der Plan längst weiterentwickelt
               wurde. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_version (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id     INT UNSIGNED NOT NULL,
                version     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                abschrift   LONGTEXT NULL,
                was         VARCHAR(120) NOT NULL DEFAULT '',
                von_user    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                am          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_plan (plan_id, version)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_vorlage (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                erstellt_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                name         VARCHAR(200) NOT NULL DEFAULT '',
                beschreibung TEXT NULL,
                kategorie    VARCHAR(60) NOT NULL DEFAULT '',
                oeffentlich  TINYINT(1) NOT NULL DEFAULT 0,
                erstellt_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ersteller (erstellt_von),
                KEY idx_oeffentlich (oeffentlich)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_vorlage_set (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                vorlage_id    INT UNSIGNED NOT NULL,
                position      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                titel         VARCHAR(200) NOT NULL DEFAULT '',
                wiederholungen SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                strecke_m     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                lage          VARCHAR(30) NOT NULL DEFAULT 'frei',
                intervall_sek SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                pause_sek     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                material      VARCHAR(200) NOT NULL DEFAULT '',
                hinweis       TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_vorlage (vorlage_id, position)
            ) $charset",

            /* Zeiten. Bestleistungen stehen NICHT darin: Sie werden aus
               diesen Zeilen ermittelt (siehe LSV07P_Zeiten). Eine
               gepflegte Bestzeit liesse sich von einer älteren Zeit
               überschreiben, eine ermittelte nicht. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_zeit (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id    BIGINT UNSIGNED NOT NULL,
                strecke_m     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                lage          VARCHAR(30) NOT NULL DEFAULT 'frei',
                bahnlaenge    SMALLINT UNSIGNED NOT NULL DEFAULT 25,
                hundertstel   INT UNSIGNED NOT NULL DEFAULT 0,
                erreicht_am   DATE NOT NULL,
                wettkampf     VARCHAR(200) NOT NULL DEFAULT '',
                offiziell     TINYINT(1) NOT NULL DEFAULT 0,
                bemerkung     VARCHAR(300) NOT NULL DEFAULT '',
                erfasst_von   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erfasst_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_person (wp_user_id, strecke_m, lage, bahnlaenge),
                KEY idx_datum (erreicht_am)
            ) $charset",

            /* Ein Plan als Datei. Viele Trainer haben ihre Pläne längst als
               PDF — die sollen sie anhängen können, statt sie abzutippen.
               Die Datei selbst liegt ausserhalb des Webverzeichnisses
               erreichbar nur über den Download-Endpunkt; hier steht nur,
               dass es sie gibt (siehe LSV07P_Dateien). */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_datei (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id         INT UNSIGNED NOT NULL,
                dateiname       VARCHAR(200) NOT NULL DEFAULT '',
                gespeichert_als VARCHAR(80) NOT NULL DEFAULT '',
                mime_type       VARCHAR(80) NOT NULL DEFAULT '',
                groesse         INT UNSIGNED NOT NULL DEFAULT 0,
                seiten          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                hochgeladen_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hochgeladen_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_plan (plan_id, id)
            ) $charset",

            /* Prüfspur über alles, was Rechte oder Sichtbarkeit ändert.
               Ohne Klartext-Adresse. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07p_spur (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                was        VARCHAR(40) NOT NULL DEFAULT '',
                user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ziel_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hinweis    VARCHAR(250) NOT NULL DEFAULT '',
                ip_hash    CHAR(64) NOT NULL DEFAULT '',
                am         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_am (am),
                KEY idx_was (was, am)
            ) $charset",
        ];
    }

    // ── Prüfspur ────────────────────────────────────────────────────────

    /**
     * Einen Vorgang festhalten. Protokolliert wird, was Rechte oder
     * Sichtbarkeit verändert — nicht jeder Mausklick. Die Adresse geht nur
     * als Hash hinein; aus ihm ist ohne das WordPress-Salz nichts
     * zurückzurechnen.
     */
    public static function spur( $was, $ziel_id = 0, $hinweis = '' ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $tbl = $p . 'lsv07p_spur';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) return;

        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $wpdb->insert( $tbl, [
            'was'     => substr( (string) $was, 0, 40 ),
            'user_id' => (int) get_current_user_id(),
            'ziel_id' => (int) $ziel_id,
            'hinweis' => mb_substr( (string) $hinweis, 0, 250 ),
            'ip_hash' => $ip === '' ? '' : hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) . '|lsv07p' ),
            'am'      => current_time( 'mysql' ),
        ], [ '%s', '%d', '%d', '%s', '%s', '%s' ] );
    }

    /** Was seine Zeit hinter sich hat, geht weg. Läuft einmal am Tag. */
    public static function aufraeumen() {
        global $wpdb;
        $p = $wpdb->prefix;

        $letzte = (int) get_option( 'lsv07p_aufraeum_lauf', 0 );
        if ( $letzte > time() - 86400 ) return;
        update_option( 'lsv07p_aufraeum_lauf', time() );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07p_spur WHERE am < %s",
            gmdate( 'Y-m-d H:i:s', time() - self::SPUR_TAGE * 86400 ) ) );

        /* Dateien ohne Plan. Das sollte es nicht geben — wenn doch, liegen
           sonst für immer PDFs mit Namen von Kindern auf der Platte. */
        if ( class_exists( 'LSV07P_Dateien' ) ) {
            LSV07P_Dateien::verwaiste_aufraeumen();
        }

        /* Von jedem Plan bleiben die letzten Versionen. Ältere braucht
           niemand mehr — der aktuelle Stand steht ohnehin im Plan selbst. */
        $plaene = $wpdb->get_col( "SELECT DISTINCT plan_id FROM {$p}lsv07p_version" );
        foreach ( (array) $plaene as $pid ) {
            $behalten = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07p_version WHERE plan_id = %d
              ORDER BY version DESC, id DESC LIMIT %d", (int) $pid, self::VERSIONEN_MAX ) );
            if ( ! $behalten ) continue;
            $platz = implode( ',', array_fill( 0, count( $behalten ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}lsv07p_version WHERE plan_id = %d AND id NOT IN ($platz)",
                ...array_merge( [ (int) $pid ], array_map( 'intval', $behalten ) ) ) );
        }
    }

    // ── Konfiguration ───────────────────────────────────────────────────

    public static function config( $schluessel, $standard = '' ) {
        $wert = get_option( 'lsv07p_' . $schluessel, null );
        return ( $wert === null || $wert === '' ) ? $standard : $wert;
    }

    public static function config_setzen( $schluessel, $wert ) {
        update_option( 'lsv07p_' . $schluessel, (string) $wert );
        return true;
    }
}
