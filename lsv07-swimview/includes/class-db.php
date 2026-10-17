<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die eigenen Tabellen von SwimView.
 *
 * SwimView führt bewusst nur das, was es selbst erfindet: welche Daten eine
 * Mannschaft sehen darf, welche Trainingspläne freigegeben sind, die
 * Nachrichten und Dateien der Trainer, die angemeldeten Sitzungen und die
 * Sperre gegen Durchprobieren.
 *
 * Alles Übrige — Anwesenheit, Vermerke, Aufgaben, Dokumentation,
 * Wettkämpfe, Bestzeiten, Reflexion, Trainingspläne — bleibt im internen
 * Bereich und wird von dort NUR GELESEN (siehe LSV07S_Intern). Eine zweite
 * Kopie derselben Daten wäre spätestens beim ersten Widerspruch ein
 * Problem, das niemand auflösen kann.
 */
class LSV07S_DB {

    /** Wie lange eine Anmeldung gilt, wenn nichts mehr passiert (Sekunden). */
    const SITZUNG_RUHE  = 8 * 3600;
    /** Spätestens nach dieser Zeit ist jede Anmeldung vorbei (Sekunden). */
    const SITZUNG_MAX   = 30 * 86400;

    public static function install() {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( self::tabellen_sql( $p, $charset ) as $sql ) {
            $wpdb->query( $sql );
        }
    }

    /**
     * Die CREATE-Texte an einer Stelle, damit Aktivierung, Selbstheilung und
     * Prüfstand denselben Aufbau benutzen und nicht auseinanderlaufen.
     */
    public static function tabellen_sql( $p, $charset = null ) {
        if ( $charset === null ) {
            $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        return [

            /* Freigaben je Mannschaft. Der Schlüssel ist die Mannschaft, NICHT
               der Trainer — damit sehen zwei Trainer derselben Mannschaft
               zwangsläufig dieselbe Einstellung, und es gibt nichts zu
               synchronisieren, was auseinanderlaufen könnte. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_freigabe (
                mannschaft_id INT UNSIGNED NOT NULL,
                bereich       VARCHAR(30) NOT NULL,
                aktiv         TINYINT(1) NOT NULL DEFAULT 0,
                geaendert_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                geaendert_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (mannschaft_id, bereich)
            ) $charset",

            /* Welche Trainingspläne eine Mannschaft sehen darf. Der interne
               Bereich kennt bei einem Plan nur „freigegeben" im Sinne von
               „andere Trainer dürfen ihn sehen" — das ist etwas anderes und
               darf nicht stillschweigend als Freigabe an Kinder gelten. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_plan_freigabe (
                mannschaft_id INT UNSIGNED NOT NULL,
                plan_id       INT UNSIGNED NOT NULL,
                freigegeben_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                freigegeben_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (mannschaft_id, plan_id),
                KEY idx_plan (plan_id)
            ) $charset",

            /* Nachrichten der Trainer. schwimmer_id = 0 heisst: an die ganze
               Mannschaft. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_nachricht (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id INT UNSIGNED NOT NULL,
                schwimmer_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                betreff       VARCHAR(160) NOT NULL DEFAULT '',
                text          TEXT NULL,
                von_user      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                von_name      VARCHAR(160) NOT NULL DEFAULT '',
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_mann (mannschaft_id, erstellt_am),
                KEY idx_schwimmer (schwimmer_id)
            ) $charset",

            /* Dateien an einer Nachricht. Sie liegen ausserhalb des
               Web-Zugriffs und werden nur über den Download-Endpunkt
               ausgeliefert, der die Anmeldung prüft. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_nachricht_datei (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                nachricht_id    INT UNSIGNED NOT NULL,
                dateiname       VARCHAR(255) NOT NULL DEFAULT '',
                gespeichert_als VARCHAR(100) NOT NULL DEFAULT '',
                mime_type       VARCHAR(100) NOT NULL DEFAULT '',
                groesse         INT UNSIGNED NOT NULL DEFAULT 0,
                hochgeladen_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_nachricht (nachricht_id)
            ) $charset",

            /* Wer hat welche Nachricht gelesen. Steht im Trainerportal an der
               Nachricht — sonst weiss niemand, ob die Absage angekommen ist. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_gelesen (
                nachricht_id INT UNSIGNED NOT NULL,
                schwimmer_id BIGINT UNSIGNED NOT NULL,
                gelesen_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (nachricht_id, schwimmer_id)
            ) $charset",

            /* Angemeldete Schwimmer. Im Cookie steht nur der Wähler (selektor)
               und ein Geheimnis; gespeichert wird vom Geheimnis ausschliesslich
               der Hash. Wer die Tabelle liest, kann sich damit nicht anmelden. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_sitzung (
                selektor      CHAR(32) NOT NULL,
                geheim_hash   CHAR(64) NOT NULL,
                schwimmer_id  BIGINT UNSIGNED NOT NULL,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                gesehen_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (selektor),
                KEY idx_schwimmer (schwimmer_id),
                KEY idx_gesehen (gesehen_am)
            ) $charset",

            /* Sperre gegen Durchprobieren. Die IP steht nur als Hash darin. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_throttle (
                ip_hash      CHAR(64) NOT NULL,
                versuche     INT UNSIGNED NOT NULL DEFAULT 0,
                gesperrt_bis DATETIME NULL DEFAULT NULL,
                letzter_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (ip_hash),
                KEY idx_gesperrt (gesperrt_bis)
            ) $charset",
        ];
    }

    // ── Konfiguration ───────────────────────────────────────────────────

    /**
     * SwimView benutzt die Konfigurationstabelle des internen Bereichs, damit
     * nicht zwei Tabellen dasselbe tun. Fehlt sie, gilt der Standardwert.
     */
    public static function config( $schluessel, $standard = '' ) {
        global $wpdb;
        if ( ! LSV07S_Intern::da( 'lsv07i_config' ) ) return $standard;
        $wert = $wpdb->get_var( $wpdb->prepare(
            "SELECT cfg_value FROM {$wpdb->prefix}lsv07i_config WHERE cfg_key = %s",
            $schluessel
        ) );
        return ( $wert === null || $wert === '' ) ? $standard : $wert;
    }

    public static function config_setzen( $schluessel, $wert ) {
        global $wpdb;
        if ( ! LSV07S_Intern::da( 'lsv07i_config' ) ) return false;
        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$p}lsv07i_config (cfg_key, cfg_value) VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)",
            $schluessel, (string) $wert
        ) );
        return true;
    }
}
