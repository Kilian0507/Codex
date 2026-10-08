<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Eigene Tabellen des Abrechnungs-Plugins.
 *
 * Alles, was die Abrechnung SELBST hervorbringt, steht hier (Präfix
 * lsv07a_). Die Quelldaten — wer wann im Training war, welche Wettkämpfe
 * es gab, welche Mannschaften und Saisons existieren — gehören dem
 * internen Bereich und werden von dort nur GELESEN (siehe class-intern.php).
 * So laufen beide Plugins nebeneinander, ohne sich in die Quere zu kommen.
 */
class LSV07A_DB {

    /** Die drei Wege, eine Trainingseinheit abzurechnen. */
    const ARTEN = [ 'zeiten', 'pauschale', 'manuell' ];

    /** Die Rollen dieses Systems — bewusst eigene, keine WordPress-Rollen. */
    const ROLLEN = [ 'trainer', 'wart', 'kasse', 'admin' ];

    /** Die fünf Bestandteile einer Abrechnung. */
    const POSTEN_TYPEN = [ 'training', 'wettkampf', 'fahrt', 'vorbereitung', 'sonstiges' ];

    /** Der Weg einer Abrechnung vom Entwurf bis zur Auszahlung. */
    const STATUS = [ 'entwurf', 'eingereicht', 'zurueck', 'genehmigt', 'bezahlt' ];

    public static function install() {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $tabellen = [

            /* Ein Konto in diesem System. Angelegt wird es vom Administrator,
               der auch Stundensatz und Abrechnungsart festlegt. Die
               Zahlungsdaten pflegt die Person selbst. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_person (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id     BIGINT UNSIGNED NOT NULL,
                stundensatz    DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                abrechnungsart VARCHAR(12) NOT NULL DEFAULT 'zeiten',
                iban           VARCHAR(34)  NOT NULL DEFAULT '',
                bic            VARCHAR(11)  NOT NULL DEFAULT '',
                kontoinhaber   VARCHAR(200) NOT NULL DEFAULT '',
                strasse        VARCHAR(200) NOT NULL DEFAULT '',
                plz            VARCHAR(10)  NOT NULL DEFAULT '',
                ort            VARCHAR(100) NOT NULL DEFAULT '',
                aktiv          TINYINT(1) NOT NULL DEFAULT 1,
                /* Sollen Trainings beim Öffnen von selbst in die Abrechnung
                   wandern, oder wählt die Person sie wie bisher aus?
                   Vorgabe ist das Auswählen: Was von allein geschieht,
                   sollte man vorher eingeschaltet haben. */
                auto_training  TINYINT(1) NOT NULL DEFAULT 0,
                /* Wohin Mitteilungen per E-Mail gehen. Leer heisst: an die
                   Adresse des WordPress-Kontos. */
                mail           VARCHAR(190) NOT NULL DEFAULT '',
                notiz          TEXT,
                erstellt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user (wp_user_id)
            ) $charset",

            /* Rollen. Eine Person kann mehrere haben (z.B. Trainer UND Wart),
               deshalb eine Zeile je Rolle statt einer Spalte. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_rolle (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id  BIGINT UNSIGNED NOT NULL,
                rolle       VARCHAR(12) NOT NULL,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_rolle (wp_user_id, rolle),
                KEY idx_rolle (rolle)
            ) $charset",

            /* Eine Abrechnung je Person und Quartal.
               stundensatz und abrechnungsart sind SCHNAPPSCHÜSSE: Ändert der
               Administrator später etwas, bleibt eine eingereichte oder
               genehmigte Abrechnung so, wie sie geprüft wurde. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_abrechnung (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id      BIGINT UNSIGNED NOT NULL,
                quartal         CHAR(2) NOT NULL DEFAULT 'Q1',
                jahr            SMALLINT UNSIGNED NOT NULL,
                status          VARCHAR(12) NOT NULL DEFAULT 'entwurf',
                stundensatz     DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                abrechnungsart  VARCHAR(12) NOT NULL DEFAULT 'zeiten',
                kommentar       TEXT,
                rueckgabe_grund TEXT,
                eingereicht_am  DATETIME DEFAULT NULL,
                genehmigt_am    DATETIME DEFAULT NULL,
                genehmigt_von   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                bezahlt_am      DATETIME DEFAULT NULL,
                bezahlt_von     BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                /* Ein Nachtrag zu einer bereits bezahlten Abrechnung: Hier
                   steht die Nummer der Abrechnung, auf die er folgt, bei
                   einer gewöhnlichen Abrechnung 0. Der eindeutige
                   Schlüssel unten geht deshalb über vier Spalten — sonst
                   gäbe es je Quartal nur eine einzige Abrechnung und ein
                   vergessener Posten liesse sich nie nachreichen. */
                nachtrag_zu     INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_quartal_nachtrag (wp_user_id, quartal, jahr, nachtrag_zu),
                KEY idx_status (status)
            ) $charset",

            /* Eine Zeile je Position. Alle fünf Arten teilen sich die
               Tabelle: menge × satz = betrag deckt Stunden, Abschnitte und
               Kilometer gleichermaßen ab; bei "sonstiges" steht der Betrag
               direkt. Das hält Summen und Statistiken auf eine Abfrage.

               ref_typ/ref_id merken sich die Herkunft im internen Bereich
               (z.B. anwesenheit:1234). Der eindeutige Schlüssel darüber
               verhindert, dass dasselbe Training zweimal in einer
               Abrechnung landet. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_posten (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id INT UNSIGNED NOT NULL,
                typ           VARCHAR(12) NOT NULL,
                datum         DATE NOT NULL,
                bezeichnung   VARCHAR(200) NOT NULL DEFAULT '',
                menge         DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                satz          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                betrag        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                wartezeit     TINYINT(1) NOT NULL DEFAULT 0,
                tage          INT UNSIGNED NOT NULL DEFAULT 1,
                /* Nur bei Trainings belegt: Von der Mannschaft hängt der
                   Pauschalbetrag ab, deshalb muss sie am Posten haften —
                   auch wenn sie später umbenannt wird. */
                mannschaft_id INT UNSIGNED NOT NULL DEFAULT 0,
                notiz         TEXT,
                /* Der Wart hat genau diese Zeile beanstandet. Die übrigen
                   bleiben unberührt — darum sitzt das Merkmal am Posten
                   und nicht an der Abrechnung. */
                beanstandet   TINYINT(1) NOT NULL DEFAULT 0,
                quelle        VARCHAR(12) NOT NULL DEFAULT 'manuell',
                /* NULL und nicht '' bzw. 0: Der eindeutige Schlüssel unten
                   darf nur ÜBERNOMMENE Posten gegen Doppelung sichern. Bei
                   leeren Werten wäre jeder von Hand erfasste Posten
                   derselbe Schlüssel — der zweite ließe sich nicht mehr
                   anlegen. NULL gilt in einem eindeutigen Schlüssel als
                   jeweils eigener Wert, genau das ist hier gewollt. */
                ref_typ       VARCHAR(24) DEFAULT NULL,
                ref_id        INT UNSIGNED DEFAULT NULL,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_abr_typ (abrechnung_id, typ),
                UNIQUE KEY uq_herkunft (abrechnung_id, ref_typ, ref_id)
            ) $charset",

            /* Pauschalbetrag je Mannschaft — gilt für Konten mit der
               Abrechnungsart "pauschale". mannschaft_id verweist auf
               lsv07_gruppen des internen Bereichs. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_pauschale (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id INT UNSIGNED NOT NULL,
                wochentag     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                betrag        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                /* Je Mannschaft UND Wochentag ein Betrag. wochentag 0
                   gilt an allen Tagen und ist der Rueckfall, wenn fuer den
                   konkreten Tag nichts hinterlegt ist. */
                UNIQUE KEY uq_mannschaft_tag (mannschaft_id, wochentag)
            ) $charset",

            /* Beanstandungen und Rückfragen zu einem einzelnen Posten.
               Eine Zeile je Wortmeldung, in der Reihenfolge, in der sie
               gefallen sind — so entsteht ein kleiner Gesprächsfaden am
               Posten, ohne dass sich der Stand der Abrechnung ändert.

               art:
                 beanstandung — der Wart hält die Zeile für falsch
                 aufgehoben   — er nimmt die Beanstandung zurück
                 frage        — Rückfrage des Warts, ohne Beanstandung
                 antwort      — die Person antwortet */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_posten_notiz (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id INT UNSIGNED NOT NULL,
                posten_id     INT UNSIGNED NOT NULL,
                wp_user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                art           VARCHAR(14) NOT NULL DEFAULT 'frage',
                text          TEXT,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_posten (posten_id, id),
                KEY idx_abr (abrechnung_id)
            ) $charset",

            /* Für welche Mannschaften ein Wart zuständig ist.
               KEINE Zeile heisst: für alle. Das ist Absicht — ein
               bestehender Verein mit einem einzigen Wart soll nach einem
               Update nicht plötzlich vor einer leeren Liste stehen. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_wart_bereich (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id    BIGINT UNSIGNED NOT NULL,
                mannschaft_id INT UNSIGNED NOT NULL,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wart_mannschaft (wp_user_id, mannschaft_id),
                KEY idx_wart (wp_user_id)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_config (
                cfg_key   VARCHAR(100) NOT NULL,
                cfg_value TEXT NOT NULL,
                PRIMARY KEY (cfg_key)
            ) $charset",

            /* Wer hat wann was entschieden. Genehmigen, Zurückgeben und
               Bezahltsetzen sind Geldentscheidungen — die gehören
               nachvollziehbar protokolliert. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_log (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                aktion      VARCHAR(60) NOT NULL DEFAULT '',
                ziel_typ    VARCHAR(30) NOT NULL DEFAULT '',
                ziel_id     INT UNSIGNED NOT NULL DEFAULT 0,
                details     TEXT,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ziel (ziel_typ, ziel_id),
                KEY idx_zeit (erstellt_am)
            ) $charset",

            /* Pauschalbetraege fuer eine einzelne Person. Sie stehen UEBER
               denen der Mannschaft: Wer hier einen Eintrag hat, bekommt
               ihn, egal was fuer die Mannschaft gilt. mannschaft_id 0
               heisst "fuer jede Mannschaft", wochentag 0 "an jedem Tag" —
               so laesst sich von "immer 30 EUR" bis "dienstags bei der
               Jugend 45 EUR" alles hinterlegen. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_person_pauschale (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id    BIGINT UNSIGNED NOT NULL,
                mannschaft_id INT UNSIGNED NOT NULL DEFAULT 0,
                wochentag     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                betrag        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_person_m_tag (wp_user_id, mannschaft_id, wochentag)
            ) $charset",

            /* Was die automatische Übernahme NICHT wieder holen soll.
               Wer ein automatisch übernommenes Training entfernt, hat einen
               Grund dafür. Ohne diese Liste stünde es beim nächsten Öffnen
               wieder da und liesse sich nie loswerden. Über die Auswahl von
               Hand kommt es weiterhin zurück — dort entscheidet die Person
               ja jedes Mal neu. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_nicht_auto (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id INT UNSIGNED NOT NULL,
                ref_typ       VARCHAR(24) NOT NULL DEFAULT '',
                ref_id        INT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_aus (abrechnung_id, ref_typ, ref_id)
            ) $charset",

            /* Benachrichtigungen. Bewusst IM System und nicht per E-Mail:
               Eine Abrechnung enthält Beträge und Namen; die gehören nicht
               ungefragt in ein fremdes Postfach. Wer etwas wissen muss,
               sieht es beim nächsten Öffnen.

               `schluessel` verhindert Dubletten: dieselbe Mitteilung zum
               selben Vorgang entsteht nur einmal, auch wenn der Auslöser
               mehrfach feuert. */
            "CREATE TABLE IF NOT EXISTS {$p}lsv07a_nachricht (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id  BIGINT UNSIGNED NOT NULL,
                art         VARCHAR(40) NOT NULL DEFAULT '',
                titel       VARCHAR(160) NOT NULL DEFAULT '',
                text        VARCHAR(400) NOT NULL DEFAULT '',
                ziel_bereich VARCHAR(30) NOT NULL DEFAULT '',
                ziel_id     INT UNSIGNED NOT NULL DEFAULT 0,
                schluessel  VARCHAR(120) DEFAULT NULL,
                gelesen_am  DATETIME NULL DEFAULT NULL,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_schluessel (wp_user_id, schluessel),
                KEY idx_offen (wp_user_id, gelesen_am),
                KEY idx_zeit (erstellt_am)
            ) $charset",
        ];

        $wpdb->suppress_errors( true );
        foreach ( $tabellen as $sql ) $wpdb->query( $sql );
        $wpdb->suppress_errors( false );

        self::spalten_nachruesten();

        self::vorgaben_setzen();
    }

    /**
     * Spalten, die erst später dazugekommen sind.
     *
     * `CREATE TABLE IF NOT EXISTS` fasst eine bestehende Tabelle nicht an —
     * ohne dies bekäme eine laufende Anlage neue Felder nie, und das Plugin
     * liefe auf einen Datenbankfehler. Geprüft wird einzeln: Was schon da
     * ist, bleibt unberührt, und es gehen keine Daten verloren.
     */
    private static function spalten_nachruesten() {
        global $wpdb;
        $neu = [
            'lsv07a_person' => [
                'auto_training' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER aktiv",
                'mail'          => "VARCHAR(190) NOT NULL DEFAULT '' AFTER auto_training",
            ],
            'lsv07a_pauschale' => [
                'wochentag' => "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER mannschaft_id",
            ],
            'lsv07a_posten' => [
                'beanstandet' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER notiz",
            ],
            'lsv07a_abrechnung' => [
                'nachtrag_zu' => "INT UNSIGNED NOT NULL DEFAULT 0 AFTER bezahlt_von",
            ],
        ];
        foreach ( $neu as $tabelle => $spalten ) {
            $voll = $wpdb->prefix . $tabelle;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $voll ) ) !== $voll ) continue;
            foreach ( $spalten as $name => $art ) {
                $da = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $voll LIKE %s", $name ) );
                if ( $da !== null && $da !== '' ) continue;
                $wpdb->suppress_errors( true );
                $wpdb->query( "ALTER TABLE $voll ADD COLUMN $name $art" );
                $wpdb->suppress_errors( false );
            }
        }

        /* Der eindeutige Schlüssel der Pauschalen ging früher nur über die
           Mannschaft. Mit den Wochentagen muss er über beides gehen, sonst
           liesse sich je Mannschaft weiterhin nur ein Betrag speichern.
           Bestehende Beträge bleiben erhalten: Sie stehen auf wochentag = 0
           und gelten damit weiter an allen Tagen. */
        $pt = $wpdb->prefix . 'lsv07a_pauschale';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pt ) ) === $pt ) {
            $alt = $wpdb->get_results( "SHOW INDEX FROM $pt WHERE Key_name = 'uq_mannschaft'" );
            if ( $alt ) {
                $wpdb->suppress_errors( true );
                $wpdb->query( "ALTER TABLE $pt DROP INDEX uq_mannschaft" );
                $wpdb->query( "ALTER TABLE $pt ADD UNIQUE KEY uq_mannschaft_tag (mannschaft_id, wochentag)" );
                $wpdb->suppress_errors( false );
            }
        }

        /* Derselbe Fall bei den Abrechnungen: Der Schlüssel ging früher
           über Person, Quartal und Jahr. Ein Nachtrag ist aber eine
           zweite Abrechnung für dasselbe Quartal — er braucht die vierte
           Spalte, sonst weist die Datenbank ihn ab. Bestehende
           Abrechnungen stehen auf nachtrag_zu = 0 und bleiben eindeutig. */
        $at = $wpdb->prefix . 'lsv07a_abrechnung';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $at ) ) === $at ) {
            $alt = $wpdb->get_results( "SHOW INDEX FROM $at WHERE Key_name = 'uq_user_quartal'" );
            if ( $alt ) {
                $wpdb->suppress_errors( true );
                $wpdb->query( "ALTER TABLE $at DROP INDEX uq_user_quartal" );
                $wpdb->query( "ALTER TABLE $at ADD UNIQUE KEY uq_user_quartal_nachtrag
                               (wp_user_id, quartal, jahr, nachtrag_zu)" );
                $wpdb->suppress_errors( false );
            }
        }
    }

    /** Die Sätze, mit denen gerechnet wird — alle vom Administrator änderbar. */
    public static function vorgaben() {
        return [
            'wk_satz'        => '30.00',   // Euro je Wettkampfabschnitt
            'km_satz'        => '0.50',    // Euro je Kilometer
            'km_mindest'     => '20',      // ab dieser einfachen Strecke (km) abrechenbar
            'km_hin_rueck'   => '1',       // Hin- und Rückfahrt zählen (einfache Strecke × 2)
            'wartezeit_min'  => '15',      // Minuten Wartezeit je Training, zuschaltbar
            'verein'         => '',        // Kopfzeile auf dem PDF

            /* E-Mail. Aus Vorsicht ist der Versand ab Werk AUS: Eine
               Abrechnung enthält Namen und Beträge, und wohin eine Mail
               geht, entscheidet sich hier — das soll jemand bewusst
               einschalten, nicht nach einem Update vorfinden. */
            'mail_an'            => '0',
            'mail_absender_name' => '',
            'mail_absender'      => '',    // leer: WordPress entscheidet
            'mail_wart_extra'    => '',    // zusätzliche Adressen, Komma getrennt
            'mail_kasse_extra'   => '',
            'mail_link'          => '',    // Adresse der Abrechnungsseite

            /* Der Beleg zur bezahlten Abrechnung. Anders als der Versand
               selbst ab Werk AN: Wer den Mailversand einschaltet, soll
               nicht noch einmal suchen müssen, warum die Abrechnung nicht
               mitkommt. Sie geht ausschliesslich an die eigene Adresse
               der Person. */
            'mail_beleg'         => '1',   // Beleg im Mailtext
            'mail_beleg_pdf'     => '1',   // Beleg zusätzlich als PDF-Anhang
        ];
    }

    private static function vorgaben_setzen() {
        foreach ( self::vorgaben() as $k => $v ) {
            if ( self::config( $k, null ) === null ) self::config_set( $k, $v );
        }
    }

    public static function config( $key, $default = '' ) {
        global $wpdb;
        $wert = $wpdb->get_var( $wpdb->prepare(
            "SELECT cfg_value FROM {$wpdb->prefix}lsv07a_config WHERE cfg_key = %s", $key ) );
        return $wert === null ? $default : $wert;
    }

    public static function config_set( $key, $wert ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}lsv07a_config (cfg_key, cfg_value) VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)", $key, (string) $wert ) );
    }

    /** Alle Sätze auf einmal, mit Vorgaben aufgefüllt. */
    public static function config_alle() {
        global $wpdb;
        $zeilen = $wpdb->get_results( "SELECT cfg_key, cfg_value FROM {$wpdb->prefix}lsv07a_config", ARRAY_A );
        $out = self::vorgaben();
        foreach ( (array) $zeilen as $z ) $out[ $z['cfg_key'] ] = $z['cfg_value'];
        return $out;
    }
}
