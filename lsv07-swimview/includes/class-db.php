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

    /**
     * Wie lange eine Anmeldung gilt, wenn nichts mehr passiert (Sekunden).
     *
     * Zwei Stunden, nicht acht: Das Gerät, auf dem sich ein Kind anmeldet,
     * ist oft nicht seines allein — Tablet am Beckenrand, Rechner zu Hause,
     * Telefon der Eltern. Eine Sitzung, die den ganzen Tag offen bleibt, ist
     * bequem und genau deshalb das Problem.
     */
    const SITZUNG_RUHE  = 2 * 3600;
    /** Spätestens nach dieser Zeit ist jede Anmeldung vorbei (Sekunden). */
    const SITZUNG_MAX   = 7 * 86400;

    /** Wie lange Lesemarken und Prüfspur aufgehoben werden (Tage). */
    const AUFBEWAHRUNG_SPUR = 90;

    public static function install() {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        self::nachruesten( $p );

        foreach ( self::tabellen_sql( $p, $charset ) as $sql ) {
            $wpdb->query( $sql );
        }
    }

    /**
     * Umbauten an schon bestehenden Tabellen.
     *
     * `CREATE TABLE IF NOT EXISTS` ändert eine vorhandene Tabelle nicht —
     * eine Anlage, die schon läuft, bekäme die neue Form sonst nie.
     */
    private static function nachruesten( $p ) {
        global $wpdb;

        /* 1.1.0: Die Sperrtabelle zählt jetzt zweifach (je Adresse UND je
           Konto) und hat dafür einen anderen Schlüssel. Darin stehen nur
           Zähler von Fehlversuchen der letzten Minuten — die dürfen weg. */
        $tbl = $p . 'lsv07s_throttle';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
            $spalten = $wpdb->get_results( "SHOW COLUMNS FROM $tbl" );
            $namen   = wp_list_pluck( $spalten, 'Field' );
            if ( ! in_array( 'art', (array) $namen, true ) ) {
                $wpdb->query( "DROP TABLE $tbl" );
            }
        }

        /* 1.1.0: Das Sitzungsgeheimnis wechselt jetzt. Vorhandene Sitzungen
           bekommen die neuen Spalten; wer gerade angemeldet ist, bleibt es. */
        $sit = $p . 'lsv07s_sitzung';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sit ) ) === $sit ) {
            $namen = wp_list_pluck( $wpdb->get_results( "SHOW COLUMNS FROM $sit" ), 'Field' );
            if ( ! in_array( 'geheim_vor', (array) $namen, true ) ) {
                $wpdb->query( "ALTER TABLE $sit
                    ADD COLUMN geheim_vor CHAR(64) NOT NULL DEFAULT '' AFTER geheim_hash" );
            }
            if ( ! in_array( 'gewechselt_am', (array) $namen, true ) ) {
                $wpdb->query( "ALTER TABLE $sit
                    ADD COLUMN gewechselt_am DATETIME NULL DEFAULT NULL AFTER geheim_vor" );
            }
        }
    }

    // ── Prüfspur ────────────────────────────────────────────────────────

    /**
     * Einen Vorgang festhalten. Die Adresse geht nur als Hash hinein, der
     * Hinweis ist kurz und enthält nie Inhalte — nur, was passiert ist.
     */
    public static function spur( $was, $schwimmer_id = 0, $hinweis = '', $ip_hash = '' ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $tbl = $p . 'lsv07s_spur';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) return;

        $wpdb->insert( $tbl, [
            'was'          => substr( (string) $was, 0, 40 ),
            'schwimmer_id' => (int) $schwimmer_id,
            'user_id'      => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
            'ip_hash'      => (string) $ip_hash,
            'hinweis'      => mb_substr( (string) $hinweis, 0, 200 ),
            'am'           => current_time( 'mysql' ),
        ], [ '%s', '%d', '%d', '%s', '%s', '%s' ] );
    }

    /**
     * Alles wegräumen, was seine Zeit hinter sich hat.
     *
     * Datensparsamkeit heisst nicht nur, wenig zu erheben, sondern auch,
     * es wieder loszuwerden. Läuft einmal am Tag.
     */
    public static function aufraeumen() {
        global $wpdb;
        $p = $wpdb->prefix;

        $letzte = (int) get_option( 'lsv07s_aufraeum_lauf', 0 );
        if ( $letzte > time() - 86400 ) return;
        update_option( 'lsv07s_aufraeum_lauf', time() );

        $grenze = gmdate( 'Y-m-d H:i:s', time() - self::AUFBEWAHRUNG_SPUR * 86400 );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07s_spur WHERE am < %s", $grenze ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07s_gelesen WHERE gelesen_am < %s", $grenze ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}lsv07s_throttle WHERE letzter_am < %s",
            gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) );

        /* Nachrichten: so lange, wie es die Konfiguration sagt. 0 = für
           immer, was die Voreinstellung NICHT ist. */
        $tage = (int) self::config( 'swimview_nachricht_tage', 365 );
        if ( $tage > 0 ) {
            $alt = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07s_nachricht WHERE erstellt_am < %s",
                gmdate( 'Y-m-d H:i:s', time() - $tage * 86400 ) ) );
            foreach ( (array) $alt as $nid ) {
                LSV07S_Dateien::alle_loeschen( (int) $nid );
                $wpdb->delete( $p . 'lsv07s_gelesen',   [ 'nachricht_id' => (int) $nid ], [ '%d' ] );
                $wpdb->delete( $p . 'lsv07s_nachricht', [ 'id' => (int) $nid ], [ '%d' ] );
            }
        }

        LSV07S_Login::aufraeumen();
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
               der Hash. Wer die Tabelle liest, kann sich damit nicht anmelden.

               geheim_vor hält das vorige Geheimnis: Das Geheimnis wechselt
               regelmässig, und ohne diese Spalte verlöre ein Browser, der
               gerade mehrere Abfragen gleichzeitig laufen hat, seine
               Anmeldung mitten im Betrieb. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_sitzung (
                selektor      CHAR(32) NOT NULL,
                geheim_hash   CHAR(64) NOT NULL,
                geheim_vor    CHAR(64) NOT NULL DEFAULT '',
                gewechselt_am DATETIME NULL DEFAULT NULL,
                schwimmer_id  BIGINT UNSIGNED NOT NULL,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                gesehen_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (selektor),
                KEY idx_schwimmer (schwimmer_id),
                KEY idx_gesehen (gesehen_am)
            ) $charset",

            /* Sperre gegen Durchprobieren.
               Gezählt wird ZWEIFACH: je Adresse und je Konto. Nur je Adresse
               zu zählen hilft wenig — wer eine DSV-ID gezielt durchprobieren
               will, wechselt die Adresse. Nur je Konto zu zählen auch nicht,
               denn damit liesse sich jedes Kind absichtlich aussperren.
               Beides zusammen deckt beide Fälle ab.
               Die Adresse steht nur als Hash darin, das Konto ebenso. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_throttle (
                art          VARCHAR(10) NOT NULL DEFAULT 'ip',
                schluessel   CHAR(64) NOT NULL,
                versuche     INT UNSIGNED NOT NULL DEFAULT 0,
                gesperrt_bis DATETIME NULL DEFAULT NULL,
                letzter_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (art, schluessel),
                KEY idx_gesperrt (gesperrt_bis)
            ) $charset",

            /* Der Zugangscode je Kind — der dritte Faktor.
               Gespeichert wird NUR der Hash, und zwar mit dem Verfahren, das
               WordPress auch für Passwörter benutzt. Wer die Tabelle liest,
               hat damit keinen Code. Gezeigt wird der Klartext genau einmal:
               wenn er erzeugt wird. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_code (
                schwimmer_id   BIGINT UNSIGNED NOT NULL,
                code_hash      VARCHAR(255) NOT NULL DEFAULT '',
                gesetzt_von    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                gesetzt_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                letzte_nutzung DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (schwimmer_id)
            ) $charset",

            /* Prüfspur: jede Anmeldung, jeder Fehlversuch, jede Freigabe.
               Ohne Klartext-Adresse — nur ihr Hash, und auch der nur so
               lange, wie die Spur aufgehoben wird. Sie ist dafür da, dass
               ein Missbrauch auffällt, nicht dafür, Kinder zu beobachten. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07s_spur (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                was          VARCHAR(40) NOT NULL DEFAULT '',
                schwimmer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ip_hash      CHAR(64) NOT NULL DEFAULT '',
                hinweis      VARCHAR(200) NOT NULL DEFAULT '',
                am           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_schwimmer (schwimmer_id, am),
                KEY idx_am (am)
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
