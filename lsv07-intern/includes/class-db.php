<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_DB {

    // ── Tabellennamen ─────────────────────────────────────────────────────────
    public static function t( $name ) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    // ── Zentrale Tabellen (geteilt mit anderen Plugins) ───────────────────────
    // wp_lsv07_gruppen   – Mannschaften (aus Abrechnungs- & Mannschafts-Plugin)
    // wp_mv_swimmers     – Schwimmer (aus Mannschafts-Plugin)

    // ── Eigene Tabellen ───────────────────────────────────────────────────────
    // lsv07i_trainer          – Trainer-Profil (name, email, wp_user_id)
    // lsv07i_trainer_mannschaft – Zuordnung Trainer <-> Mannschaft
    // lsv07i_training_slots   – Trainingszeiten pro Mannschaft (wochentag, von, bis)
    // lsv07i_anwesenheit      – Anwesenheits-Sessions pro Training
    // lsv07i_anwesenheit_eintraege – Eintraege pro Schwimmer pro Session
    // lsv07i_springer         – Springer-Eintraege (Trainer meldet sich fuer fremdes Training)
    // lsv07i_training_ausfall – Ausgefallene Trainings

    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        $wpdb->suppress_errors( true );

        $tables = [
            // Trainer-Profile
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_trainer (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                name        VARCHAR(200) NOT NULL DEFAULT '',
                email       VARCHAR(200) NOT NULL DEFAULT '',
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (wp_user_id)
            ) $charset",

            // Trainer <-> Mannschaft (Haupt-Trainer)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_trainer_mannschaft (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id   INT UNSIGNED NOT NULL,
                mannschaft_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tm (trainer_id, mannschaft_id)
            ) $charset",

            // Trainingszeiten pro Mannschaft
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_training_slots (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id INT UNSIGNED NOT NULL,
                wochentag     TINYINT UNSIGNED NOT NULL DEFAULT 1,
                zeit_von      TIME NOT NULL DEFAULT '00:00:00',
                zeit_bis      TIME NOT NULL DEFAULT '00:00:00',
                PRIMARY KEY (id),
                KEY idx_mannschaft (mannschaft_id)
            ) $charset",

            // Anwesenheits-Sessions (ein Eintrag = ein konkretes Training an einem Datum)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_anwesenheit (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_id       INT UNSIGNED NOT NULL,
                mannschaft_id INT UNSIGNED NOT NULL,
                training_datum DATE NOT NULL,
                ausgefallen   TINYINT(1) NOT NULL DEFAULT 0,
                kommentar     TEXT NULL,
                erstellt_von  INT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_slot_datum (slot_id, training_datum),
                KEY idx_mannschaft (mannschaft_id)
            ) $charset",

            // Anwesenheits-Eintraege pro Schwimmer
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_anwesenheit_eintraege (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                anwesenheit_id  INT UNSIGNED NOT NULL,
                teilnehmer_typ  VARCHAR(20) NOT NULL DEFAULT 'schwimmer',
                teilnehmer_id   INT UNSIGNED NOT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT 'unbekannt',
                PRIMARY KEY (id),
                UNIQUE KEY uq_anw_teiln (anwesenheit_id, teilnehmer_typ, teilnehmer_id),
                KEY idx_anw (anwesenheit_id)
            ) $charset",

            // Springer-Schichten
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_springer (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_id       INT UNSIGNED NOT NULL,
                training_datum DATE NOT NULL,
                trainer_id    INT UNSIGNED NOT NULL,
                eingetragen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_springer_slot_trainer (slot_id, training_datum, trainer_id),
                KEY idx_slot (slot_id),
                KEY idx_trainer (trainer_id)
            ) $charset",

            // Ausgefallene Trainings
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_training_ausfall (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_id       INT UNSIGNED NOT NULL,
                training_datum DATE NOT NULL,
                grund         VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY uq_ausfall (slot_id, training_datum)
            ) $charset",

            // Sicherstellen dass lsv07_gruppen vorhanden (falls Abrechnungs-Plugin nicht aktiv)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07_gruppen (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(100) NOT NULL DEFAULT '',
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_name (name)
            ) $charset",

            // Sicherstellen dass mv_swimmers vorhanden (falls Mannschafts-Plugin nicht aktiv)
            "CREATE TABLE IF NOT EXISTS {$p}mv_swimmers (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                team_id    INT UNSIGNED NOT NULL,
                last_name  VARCHAR(80) NOT NULL DEFAULT '',
                first_name VARCHAR(80) NOT NULL DEFAULT '',
                birth_date DATE NOT NULL,
                email      VARCHAR(160) NOT NULL DEFAULT '',
                dsv_id     VARCHAR(40) NOT NULL DEFAULT '',
                active     TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                attest_expires DATE DEFAULT NULL,
                notes TEXT,
                PRIMARY KEY (id),
                KEY idx_team (team_id)
            ) $charset",

            // Kontaktpersonen der Schwimmer
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_kontakte (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                swimmer_id BIGINT UNSIGNED NOT NULL,
                name       VARCHAR(160) NOT NULL DEFAULT '',
                telefon    VARCHAR(60)  NOT NULL DEFAULT '',
                email      VARCHAR(160) NOT NULL DEFAULT '',
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_swimmer (swimmer_id)
            ) $charset",

            // Stammdaten fuer Abrechnung (Satz-Konfiguration)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_config (
                cfg_key   VARCHAR(100) NOT NULL,
                cfg_value TEXT NOT NULL,
                PRIMARY KEY (cfg_key)
            ) $charset",

            // Trainerstammdaten fuer Abrechnung (IBAN, Adresse etc.)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_trainer_stammdaten (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id INT UNSIGNED NOT NULL,
                iban       VARCHAR(34) NOT NULL DEFAULT '',
                bic        VARCHAR(11) NOT NULL DEFAULT '',
                strasse    VARCHAR(200) NOT NULL DEFAULT '',
                plz        VARCHAR(10) NOT NULL DEFAULT '',
                ort           VARCHAR(100) NOT NULL DEFAULT '',
                stundensatz   DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                vorbereitung_aktiv TINYINT(1) NOT NULL DEFAULT 0,
                updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_trainer (trainer_id)
            ) $charset",

            // Abrechnungs-Eintraege (flexibel, kein festes Quartal-System)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abrechnung (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id      INT UNSIGNED NOT NULL,
                quartal         CHAR(2) NOT NULL DEFAULT 'Q1',
                jahr            SMALLINT UNSIGNED NOT NULL DEFAULT 2025,
                abr_status      VARCHAR(20) NOT NULL DEFAULT 'entwurf',
                kommentar       TEXT,
                eingereicht_am  DATETIME DEFAULT NULL,
                genehmigt_am    DATETIME DEFAULT NULL,
                rueckgabe_grund TEXT,
                bezahlt         TINYINT(1) NOT NULL DEFAULT 0,
                bezahlt_am      DATETIME DEFAULT NULL,
                erstellt_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_trainer_qj (trainer_id, quartal, jahr)
            ) $charset",

            // Trainingstage in Abrechnung
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abr_trainingstage (
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id    INT UNSIGNED NOT NULL,
                datum            DATE NOT NULL,
                mannschaft_name  VARCHAR(100) NOT NULL DEFAULT '',
                stunden          DECIMAL(4,2) NOT NULL DEFAULT 1.50,
                quelle           VARCHAR(20) NOT NULL DEFAULT 'manuell',
                anwesenheit_id   INT UNSIGNED DEFAULT NULL,
                springer_id      INT UNSIGNED DEFAULT NULL,
                begruendung      TEXT,
                vorbereitung_aktiv TINYINT(1) NOT NULL DEFAULT 0,
                manuell_geloescht TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_abr (abrechnung_id)
            ) $charset",

            // Wettkampf-Eintraege in Abrechnung
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abr_wettkampf (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id   INT UNSIGNED NOT NULL,
                datum           DATE NOT NULL,
                name            VARCHAR(200) NOT NULL DEFAULT '',
                abschnitte      INT UNSIGNED NOT NULL DEFAULT 1,
                pauschale       DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                betrag          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id),
                KEY idx_abr (abrechnung_id)
            ) $charset",

            // Kilometer-Eintraege in Abrechnung
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abr_kilometer (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id   INT UNSIGNED NOT NULL,
                datum           DATE NOT NULL,
                beschreibung    VARCHAR(200) NOT NULL DEFAULT '',
                km              DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                tage            INT UNSIGNED NOT NULL DEFAULT 1,
                satz            DECIMAL(6,4) NOT NULL DEFAULT 0.30,
                betrag          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id),
                KEY idx_abr (abrechnung_id)
            ) $charset",

            // Sonderabrechnung: einfache Tag+Stunden-Eintraege einer eigenen,
            // rechte-gesteuerten Abrechnungsart (bereich='sonder' in
            // lsv07i_abrechnung). Bewusst ohne Mannschaft/Quelle/Vorbereitung -
            // das ist der Unterschied zu lsv07i_abr_trainingstage.
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_abr_sonder (
                id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id      INT UNSIGNED NOT NULL,
                datum              DATE NOT NULL,
                stunden            DECIMAL(4,2) NOT NULL DEFAULT 1.00,
                beschreibung       VARCHAR(200) NOT NULL DEFAULT '',
                manuell_geloescht  TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_abr (abrechnung_id)
            ) $charset",

            // Benutzer-Berechtigungen (überschreibt Rollen)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_permissions (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    BIGINT UNSIGNED NOT NULL,
                allow_schwimmen  TINYINT(1) NOT NULL DEFAULT 1,
                allow_trainer    TINYINT(1) NOT NULL DEFAULT 1,
                allow_verwaltung TINYINT(1) NOT NULL DEFAULT 1,
                allow_admin      TINYINT(1) NOT NULL DEFAULT 1,
                gesetzt_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user (user_id)
            ) $charset",

            // Bestzeiten
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_bestzeiten (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                swimmer_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                mannschaft_id INT UNSIGNED NOT NULL,
                swimmer_name  VARCHAR(200) NOT NULL DEFAULT '',
                strecke       VARCHAR(10)  NOT NULL DEFAULT '',
                zeit_raw      VARCHAR(20)  NOT NULL DEFAULT '',
                zeit_sek      DECIMAL(10,3) NOT NULL DEFAULT 0.000,
                importiert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                importiert_von INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_mann (mannschaft_id),
                KEY idx_swimmer (swimmer_id),
                UNIQUE KEY uq_bz_swimmer (swimmer_id, strecke),
                KEY idx_bz_name (mannschaft_id, swimmer_name(100), strecke)
            ) $charset",

            // Schwimmer-Dateien (Atteste, Bestätigungen — pro Schwimmer global)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_schwimmer_dateien (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                schwimmer_id  BIGINT UNSIGNED NOT NULL,
                dateiname     VARCHAR(255) NOT NULL,
                gespeichert_als VARCHAR(255) NOT NULL,
                mime_type     VARCHAR(100) NOT NULL,
                groesse       INT UNSIGNED NOT NULL DEFAULT 0,
                kommentar     VARCHAR(500) DEFAULT NULL,
                hochgeladen_von INT UNSIGNED NOT NULL DEFAULT 0,
                hochgeladen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_schwimmer (schwimmer_id)
            ) $charset",

            // Eigene Rechte-Templates (User-defined; ergänzen die hartcodierten)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_rechte_templates (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name          VARCHAR(120) NOT NULL,
                beschreibung  VARCHAR(500) DEFAULT NULL,
                rights_json   LONGTEXT NOT NULL,
                erstellt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_am  DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_name (name)
            ) $charset",

            // ── Reflexionsbögen (nur Schwimmen) ───────────────────────────
            // Stammfragen pro Mannschaft. Gelten für alle Bögen dieser Mannschaft.
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_refl_fragen (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id INT UNSIGNED NOT NULL,
                frage         TEXT NOT NULL,
                sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
                aktiv         TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_mann (mannschaft_id)
            ) $charset",

            // Ein Bogen pro Schwimmer pro Saison. Enthält Zugangsschlüssel + Status.
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_refl_bogen (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                saison_id       INT UNSIGNED NOT NULL,
                mannschaft_id   INT UNSIGNED NOT NULL,
                swimmer_id      BIGINT UNSIGNED NOT NULL,
                swimmer_name    VARCHAR(200) NOT NULL DEFAULT '',
                schluessel_hash CHAR(64) NOT NULL DEFAULT '',
                token           VARCHAR(32) NOT NULL,
                status          ENUM('offen','abgegeben') NOT NULL DEFAULT 'offen',
                fragen_snapshot LONGTEXT DEFAULT NULL,
                erstellt_von    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                abgegeben_am    DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bogen (saison_id, swimmer_id),
                UNIQUE KEY uq_token (token),
                KEY idx_schluessel_hash (schluessel_hash),
                KEY idx_mann (mannschaft_id),
                KEY idx_status (status)
            ) $charset",

            // Antworten auf Fragen + Wunschzeiten pro Bogen.
            // typ='frage': frage_id + antwort_text gesetzt.
            // typ='zeit':  strecke + zeit_aktuell + wunschzeit gesetzt.
            // Die Textinhalte (antwort_text, wunschzeit) werden verschlüsselt
            // gespeichert (AES-256-GCM), daher LONGTEXT statt knapper Felder.
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_refl_antwort (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                bogen_id      INT UNSIGNED NOT NULL,
                typ           ENUM('frage','zeit') NOT NULL,
                frage_id      INT UNSIGNED DEFAULT NULL,
                frage_text    TEXT DEFAULT NULL,
                antwort_text  LONGTEXT DEFAULT NULL,
                strecke       VARCHAR(10) DEFAULT NULL,
                zeit_aktuell  VARCHAR(20) DEFAULT NULL,
                wunschzeit    VARCHAR(255) DEFAULT NULL,
                sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_bogen (bogen_id)
            ) $charset",

            // Rate-Limiting für die öffentliche Schlüssel-Eingabe (gegen Brute-Force).
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_refl_throttle (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ip_hash       CHAR(64) NOT NULL,
                versuche      INT UNSIGNED NOT NULL DEFAULT 0,
                gesperrt_bis  DATETIME NULL DEFAULT NULL,
                letzter_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ip (ip_hash)
            ) $charset",


            // Audit-Log: wer hat wann was gemacht
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_log (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
                user_name   VARCHAR(120) NOT NULL DEFAULT '',
                aktion      VARCHAR(80) NOT NULL,
                bereich     VARCHAR(40) NOT NULL DEFAULT '',
                ziel_typ    VARCHAR(40) DEFAULT NULL,
                ziel_id     VARCHAR(80) DEFAULT NULL,
                ziel_name   VARCHAR(200) DEFAULT NULL,
                erfolg      TINYINT(1) NOT NULL DEFAULT 1,
                details     TEXT NULL,
                ip          VARCHAR(45) DEFAULT NULL,
                zeitpunkt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_zeit (zeitpunkt),
                KEY idx_user (user_id),
                KEY idx_aktion (aktion),
                KEY idx_bereich (bereich)
            ) $charset",

            // Saisons (Halbjahre, Quartale o.ä. mit eigenen Trainingszeiten)
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_saisons (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(120) NOT NULL,
                start_datum  DATE NOT NULL,
                ende_datum   DATE NULL DEFAULT NULL,
                aktiv        TINYINT(1) NOT NULL DEFAULT 0,
                erstellt_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_name (name),
                KEY idx_aktiv (aktiv)
            ) $charset",

            // ─── KONVERSATIONS-SYSTEM ─────────────────────────────────────
            // Eine Konversation ist ein Thread (1:1 oder Gruppe oder Verteiler).
            // Die alte lsv07i_nachrichten-Tabelle bleibt vorerst erhalten und
            // wird per Migration in dieses Modell überführt.

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_konv (
                id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                typ               ENUM('direkt','gruppe','verteiler') NOT NULL DEFAULT 'direkt',
                name              VARCHAR(200) DEFAULT NULL,
                mannschaft_id     INT UNSIGNED DEFAULT NULL,
                mannschaft_sparte VARCHAR(20)  DEFAULT NULL,
                auto_typ          VARCHAR(40)  DEFAULT NULL,
                erstellt_von      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                letzte_aktivitaet DATETIME NULL DEFAULT NULL,
                geschlossen       TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_typ (typ),
                KEY idx_letzte_aktivitaet (letzte_aktivitaet),
                KEY idx_mannschaft (mannschaft_id, mannschaft_sparte),
                KEY idx_auto (auto_typ)
            ) $charset",

            // Teilnehmer einer Konversation. teilnehmer_typ unterscheidet:
            //   'user'    – WordPress-User-ID
            //   'kontakt' – Kontaktperson aus lsv07i_kontakte
            //   'email'   – freie E-Mail-Adresse (ad-hoc, ohne DB-Eintrag)
            // teilnehmer_id ist je nach Typ eine User-ID, Kontakt-ID oder 0
            // (bei 'email' ist die Adresse in teilnehmer_email gespeichert).
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_konv_teilnehmer (
                id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                konv_id           INT UNSIGNED NOT NULL,
                teilnehmer_typ    ENUM('user','kontakt','email') NOT NULL,
                teilnehmer_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
                teilnehmer_email  VARCHAR(200) DEFAULT NULL,
                teilnehmer_name   VARCHAR(200) DEFAULT NULL,
                ist_admin         TINYINT(1) NOT NULL DEFAULT 0,
                gelesen_bis       INT UNSIGNED NOT NULL DEFAULT 0,
                stumm             TINYINT(1) NOT NULL DEFAULT 0,
                verlassen_am      DATETIME NULL DEFAULT NULL,
                hinzugefuegt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                hinzugefuegt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_konv (konv_id),
                KEY idx_user (teilnehmer_typ, teilnehmer_id),
                KEY idx_email (teilnehmer_email),
                UNIQUE KEY uq_member (konv_id, teilnehmer_typ, teilnehmer_id, teilnehmer_email)
            ) $charset",

            // Einzelne Nachrichten innerhalb einer Konversation.
            // von_typ unterscheidet User vs. externer Antwortgeber (Kontakt/E-Mail).
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_konv_msg (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                konv_id      INT UNSIGNED NOT NULL,
                von_typ      ENUM('user','kontakt','email','system') NOT NULL DEFAULT 'user',
                von_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
                von_email    VARCHAR(200) DEFAULT NULL,
                von_name     VARCHAR(200) DEFAULT NULL,
                text         LONGTEXT NOT NULL,
                dringend     TINYINT(1) NOT NULL DEFAULT 0,
                bearbeitet_am DATETIME NULL DEFAULT NULL,
                geloescht_am DATETIME NULL DEFAULT NULL,
                geloescht_von BIGINT UNSIGNED DEFAULT NULL,
                erstellt_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_konv_zeit (konv_id, erstellt_am)
            ) $charset",

            // Anhänge zu Nachrichten. Pfad: wp-content/uploads/lsv07i-private/nachrichten/<konv>/<random>.<ext>
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_konv_anhang (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                msg_id          INT UNSIGNED NOT NULL,
                dateiname       VARCHAR(255) NOT NULL,
                gespeichert_als VARCHAR(255) NOT NULL,
                mime_type       VARCHAR(100) NOT NULL,
                groesse         INT UNSIGNED NOT NULL DEFAULT 0,
                token           VARCHAR(64) NOT NULL,
                erstellt_am     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_msg (msg_id),
                UNIQUE KEY uq_token (token)
            ) $charset",

            // Reaktionen auf Nachrichten (Emoji-Reactions).
            // Ein User kann mit demselben Emoji nur einmal reagieren — UNIQUE.
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_konv_reaktion (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                msg_id      INT UNSIGNED NOT NULL,
                user_id     BIGINT UNSIGNED NOT NULL,
                emoji       VARCHAR(8) NOT NULL,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_reaktion (msg_id, user_id, emoji),
                KEY idx_msg (msg_id)
            ) $charset",

            // E-Mail-Versand-Queue. Wird vom Cronjob abgearbeitet (Batch-Versand).
            // Eine Zeile = eine ausstehende E-Mail an einen externen Empfänger.
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_email_queue (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                msg_id        INT UNSIGNED NOT NULL,
                empfaenger    VARCHAR(200) NOT NULL,
                empfaenger_typ ENUM('kontakt','email','user') NOT NULL DEFAULT 'kontakt',
                reply_token   VARCHAR(64) NOT NULL,
                dringend      TINYINT(1) NOT NULL DEFAULT 0,
                status        ENUM('warten','gesendet','fehler','gebuendelt') NOT NULL DEFAULT 'warten',
                fehler_text   TEXT NULL,
                gesendet_am   DATETIME NULL DEFAULT NULL,
                versuche      TINYINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_status (status, dringend),
                KEY idx_empfaenger (empfaenger),
                UNIQUE KEY uq_token (reply_token)
            ) $charset",

            // ═══════════════════════════════════════════════════════════════
            //  TRIATHLON — Gruppen, Sportler, Trainer, Slots, Anwesenheit
            // ═══════════════════════════════════════════════════════════════
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tri_gruppen (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(200) NOT NULL DEFAULT '',
                sort_order  INT NOT NULL DEFAULT 0,
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aktiv (aktiv),
                KEY idx_sort (sort_order)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tri_sportler (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                first_name  VARCHAR(120) NOT NULL DEFAULT '',
                last_name   VARCHAR(120) NOT NULL DEFAULT '',
                birth_date  DATE NULL DEFAULT NULL,
                email       VARCHAR(200) NOT NULL DEFAULT '',
                notes       TEXT NULL,
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aktiv (aktiv),
                KEY idx_name (last_name, first_name)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tri_sportler_gruppe (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                sportler_id INT UNSIGNED NOT NULL,
                gruppe_id   INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sg (sportler_id, gruppe_id),
                KEY idx_gruppe (gruppe_id)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tri_trainer (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                name        VARCHAR(200) NOT NULL DEFAULT '',
                email       VARCHAR(200) NOT NULL DEFAULT '',
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (wp_user_id),
                KEY idx_aktiv (aktiv)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tri_trainer_gruppe (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id INT UNSIGNED NOT NULL,
                gruppe_id  INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tg (trainer_id, gruppe_id),
                KEY idx_gruppe (gruppe_id)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tri_slots (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                gruppe_id  INT UNSIGNED NOT NULL,
                wochentag  TINYINT UNSIGNED NOT NULL DEFAULT 1,
                zeit_von   TIME NOT NULL DEFAULT '00:00:00',
                zeit_bis   TIME NOT NULL DEFAULT '00:00:00',
                PRIMARY KEY (id),
                KEY idx_gruppe (gruppe_id)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tri_anwesenheit (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                gruppe_id      INT UNSIGNED NOT NULL,
                slot_id        INT UNSIGNED NOT NULL,
                training_datum DATE NOT NULL,
                ausgefallen    TINYINT(1) NOT NULL DEFAULT 0,
                ausfall_grund  VARCHAR(255) NULL,
                erstellt_von   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_slot_datum (slot_id, training_datum),
                KEY idx_gruppe (gruppe_id),
                KEY idx_datum (training_datum)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tri_anwesenheit_eintraege (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                anwesenheit_id INT UNSIGNED NOT NULL,
                sportler_id    INT UNSIGNED NOT NULL,
                status         VARCHAR(20) NOT NULL DEFAULT 'unbekannt',
                PRIMARY KEY (id),
                UNIQUE KEY uq_anw_sp (anwesenheit_id, sportler_id),
                KEY idx_anw (anwesenheit_id)
            ) $charset",

            // ═══════════════════════════════════════════════════════════════
            //  TICKETS — vereinsinternes Anliegen-System
            // ═══════════════════════════════════════════════════════════════
            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_tickets (
                id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                nummer               VARCHAR(20) NOT NULL DEFAULT '',
                titel                VARCHAR(255) NOT NULL DEFAULT '',
                beschreibung         LONGTEXT NULL,
                kategorie            VARCHAR(40) NOT NULL DEFAULT 'frage',
                prioritaet           VARCHAR(20) NOT NULL DEFAULT 'normal',
                status               VARCHAR(20) NOT NULL DEFAULT 'offen',
                ersteller_user_id    BIGINT UNSIGNED NOT NULL,
                zugewiesen_an        BIGINT UNSIGNED NULL DEFAULT NULL,
                erstellt_am          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                aktualisiert_am      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                geschlossen_am       DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_nummer (nummer),
                KEY idx_status (status),
                KEY idx_kategorie (kategorie),
                KEY idx_ersteller (ersteller_user_id),
                KEY idx_zugewiesen (zugewiesen_an),
                KEY idx_updated (aktualisiert_am)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_ticket_kommentare (
                id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id            INT UNSIGNED NOT NULL,
                autor_user_id        BIGINT UNSIGNED NOT NULL,
                text                 LONGTEXT NULL,
                ist_system_event     TINYINT(1) NOT NULL DEFAULT 0,
                erstellt_am          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ticket (ticket_id, erstellt_am)
            ) $charset",

            "CREATE TABLE IF NOT EXISTS {$p}lsv07i_ticket_anhang (
                id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id            INT UNSIGNED NOT NULL,
                kommentar_id         INT UNSIGNED NULL DEFAULT NULL,
                hochgeladen_von      BIGINT UNSIGNED NOT NULL,
                dateiname            VARCHAR(255) NOT NULL,
                gespeichert_als      VARCHAR(255) NOT NULL,
                mime_type            VARCHAR(100) NOT NULL,
                groesse              INT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ticket (ticket_id),
                KEY idx_kommentar (kommentar_id)
            ) $charset",
        ];

        foreach ( $tables as $sql ) {
            $result = $wpdb->query( $sql );
            if ( $result === false ) {
                error_log( 'LSV07I DB error: ' . $wpdb->last_error );
            }
        }

        $wpdb->suppress_errors( false );

        // Standard-Konfiguration
        $defaults = [
            'km_satz'                     => '0.30',
            'km_min'                      => '20',
            'wk_pauschale'                => '25.00',
            'mail_schwimmwart'            => get_option( 'admin_email', '' ),
            'anwesenheit_mahnung_stunden' => '48',
            'mail_deaktiviert'            => '0',
            'attest_warnung_tage'         => '30',
            'attest_mail_aktiv'           => '0',
        ];
        foreach ( $defaults as $k => $v ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$p}lsv07i_config (cfg_key, cfg_value) VALUES (%s, %s)",
                $k, $v
            ) );
        }

        self::ensure_indexes();

        update_option( 'lsv07i_db_ver', '3.0.0' );
    }

    /**
     * Legt Performance-Indizes auf haeufig gefilterten Spalten an, sofern
     * sie fehlen. Laeuft bei jedem Versions-Update mit; die SHOW-INDEX-
     * Pruefung macht die Sache idempotent (kein Fehler bei Wiederholung).
     */
    private static function ensure_indexes() {
        global $wpdb;
        $p = $wpdb->prefix;
        $wanted = [
            [ "{$p}lsv07i_abrechnung",  'idx_status',        'abr_status' ],
            [ "{$p}lsv07i_anwesenheit", 'idx_datum',         'training_datum' ],
            [ "{$p}mv_swimmers",        'idx_attest',        'attest_expires' ],
        ];
        foreach ( $wanted as [ $tbl, $idx, $col ] ) {
            // Tabellen-Existenz zuerst prüfen: verhindert einen stillen
            // ALTER-Fehler (und dessen Folgen für nachfolgenden Code in
            // dieser Migrationskette), falls eine Tabelle auf einer
            // konkreten Installation (noch) nicht existiert.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
            if ( ! $exists ) continue;
            $has = $wpdb->get_var( $wpdb->prepare(
                "SHOW INDEX FROM {$tbl} WHERE Key_name = %s", $idx ) );
            if ( ! $has ) {
                $wpdb->query( "ALTER TABLE {$tbl} ADD INDEX {$idx} ({$col})" );
            }
        }
    }

    // ── Konfigurations-Helfer ─────────────────────────────────────────────────
    public static function get_config( $key, $default = '' ) {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT cfg_value FROM {$wpdb->prefix}lsv07i_config WHERE cfg_key = %s LIMIT 1", $key
        ) );
        return ( $val !== null ) ? $val : $default;
    }

    public static function set_config( $key, $value ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}lsv07i_config (cfg_key, cfg_value) VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE cfg_value = VALUES(cfg_value)",
            $key, $value
        ) );
    }

    // ── Gruppen / Mannschaften ────────────────────────────────────────────────
    public static function get_mannschaften() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}lsv07_gruppen ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );
    }

    // ── Schwimmer ─────────────────────────────────────────────────────────────
    public static function get_schwimmer( $mannschaft_id = 0 ) {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $mannschaft_id ) {
            // Alle Schwimmer dieser Mannschaft (via M:N-Tabelle ODER team_id)
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT s.id, s.first_name, s.last_name, s.birth_date, s.team_id,
                        s.attest_expires, s.email, s.notes,
                        g.name AS mannschaft_name
                   FROM {$p}mv_swimmers s
              LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
                  WHERE s.active = 1
                    AND (
                        s.team_id = %d
                        OR EXISTS (
                            SELECT 1 FROM {$p}lsv07i_swimmer_gruppen sg
                             WHERE sg.swimmer_id = s.id AND sg.gruppe_id = %d
                        )
                    )
               ORDER BY s.last_name ASC, s.first_name ASC",
                $mannschaft_id, $mannschaft_id
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results(
                "SELECT s.id, s.first_name, s.last_name, s.birth_date, s.team_id,
                        s.attest_expires, s.email, s.notes,
                        g.name AS mannschaft_name
                   FROM {$p}mv_swimmers s
              LEFT JOIN {$p}lsv07_gruppen g ON g.id = s.team_id
                  WHERE s.active = 1
               ORDER BY s.last_name ASC, s.first_name ASC",
                ARRAY_A
            );
        }

        // Kontakte und Datei-Anzahl in 2 statt 2N Queries laden
        if ( ! empty( $rows ) ) {
            $sw_ids = array_filter( array_map( fn( $r ) => (int) $r['id'], $rows ), fn( $i ) => $i > 0 );
            if ( ! empty( $sw_ids ) ) {
                $ids_in = implode( ',', $sw_ids );
                // Alle Kontakte
                $k_rows = $wpdb->get_results(
                    "SELECT * FROM {$p}lsv07i_kontakte
                      WHERE swimmer_id IN ($ids_in) ORDER BY sort_order ASC", ARRAY_A );
                $k_map = [];
                foreach ( $k_rows as $k ) $k_map[ (int) $k['swimmer_id'] ][] = $k;
                // Datei-Anzahl pro Schwimmer (eine GROUP BY-Query)
                $da_rows = $wpdb->get_results(
                    "SELECT schwimmer_id, COUNT(*) AS anz FROM {$p}lsv07i_schwimmer_dateien
                      WHERE schwimmer_id IN ($ids_in) GROUP BY schwimmer_id", ARRAY_A );
                $da_map = [];
                foreach ( $da_rows as $da ) $da_map[ (int) $da['schwimmer_id'] ] = (int) $da['anz'];

                // Alle Gruppenzuordnungen pro Schwimmer (Mannschaft + zusätzliche Gruppen)
                // Eine einzige Bulk-Query statt N Queries:
                $g_rows = $wpdb->get_results(
                    "SELECT sg.swimmer_id, g.id, g.name
                       FROM {$p}lsv07_gruppen g
                       JOIN (
                           SELECT id AS swimmer_id, team_id AS gid FROM {$p}mv_swimmers
                            WHERE id IN ($ids_in) AND team_id IS NOT NULL
                           UNION
                           SELECT swimmer_id, gruppe_id AS gid
                             FROM {$p}lsv07i_swimmer_gruppen
                            WHERE swimmer_id IN ($ids_in)
                       ) sg ON sg.gid = g.id
                   ORDER BY g.sort_order ASC, g.name ASC", ARRAY_A );
                $g_map = [];
                foreach ( $g_rows as $g ) {
                    $sid = (int) $g['swimmer_id'];
                    $g_map[ $sid ][] = [ 'id' => (int) $g['id'], 'name' => $g['name'] ];
                }

                foreach ( $rows as &$row ) {
                    $sid = (int) $row['id'];
                    $row['kontakte']      = $k_map[ $sid ] ?? [];
                    $row['attest_status'] = self::attest_status( $row['attest_expires'] );
                    $row['datei_anzahl']  = $da_map[ $sid ] ?? 0;
                    $gruppen = $g_map[ $sid ] ?? [];
                    $row['alle_gruppen']       = $gruppen;
                    $row['alle_gruppen_namen'] = array_column( $gruppen, 'name' );
                    $row['alle_gruppen_ids']   = array_column( $gruppen, 'id' );
                }
                unset( $row );
            }
        }
        return $rows;
    }

    public static function attest_status( $date_str, $warn_days = null ) {
        if ( $warn_days === null ) {
            $warn_days = (int) self::get_config( 'attest_warnung_tage', 30 );
        }
        if ( ! $date_str || $date_str === '0000-00-00' ) return 'keines';
        $ts   = strtotime( $date_str );
        $now  = time();
        if ( $ts < $now ) return 'abgelaufen';
        if ( $ts < $now + $warn_days * 86400 ) return 'laeuft_ab';
        return 'gueltig';
    }

    public static function save_kontakte( $swimmer_id, $kontakte ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( $p . 'lsv07i_kontakte', [ 'swimmer_id' => $swimmer_id ], [ '%d' ] );
        foreach ( (array) $kontakte as $i => $k ) {
            $name    = sanitize_text_field( $k['name']    ?? '' );
            $telefon = sanitize_text_field( $k['telefon'] ?? '' );
            $email   = sanitize_email(      $k['email']   ?? '' );
            if ( ! $name && ! $telefon && ! $email ) continue;
            $wpdb->insert( $p . 'lsv07i_kontakte', [
                'swimmer_id' => $swimmer_id,
                'name'       => $name,
                'telefon'    => $telefon,
                'email'      => $email,
                'sort_order' => $i,
            ], [ '%d', '%s', '%s', '%s', '%d' ] );
        }
    }

    // ── Trainer ───────────────────────────────────────────────────────────────
    public static function get_all_trainer() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT t.*, GROUP_CONCAT(tm.mannschaft_id ORDER BY tm.mannschaft_id ASC) AS mannschaft_ids
               FROM {$wpdb->prefix}lsv07i_trainer t
          LEFT JOIN {$wpdb->prefix}lsv07i_trainer_mannschaft tm ON tm.trainer_id = t.id
              WHERE t.aktiv = 1
           GROUP BY t.id
           ORDER BY t.display_name ASC, t.name ASC",
            ARRAY_A
        );
        // display_name aus name ableiten wenn noch leer (Altdaten)
        foreach ( $rows as &$row ) {
            if ( empty( $row['display_name'] ) ) {
                $row['display_name'] = $row['name'];
            }
        }
        unset( $row );
        return $rows ?: [];
    }

    public static function get_trainer_by_user( $user_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}lsv07i_trainer WHERE wp_user_id = %d AND aktiv = 1 LIMIT 1",
            absint( $user_id )
        ), ARRAY_A );
    }

    public static function get_trainer_mannschaften( $trainer_id ) {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT mannschaft_id FROM {$wpdb->prefix}lsv07i_trainer_mannschaft WHERE trainer_id = %d",
            absint( $trainer_id )
        ) );
    }

    // ── Training-Slots ────────────────────────────────────────────────────────
    public static function get_slots( $mannschaft_id = 0, $saison_filter = 'aktiv' ) {
        global $wpdb;
        $clauses = [];
        if ( $mannschaft_id ) {
            $clauses[] = $wpdb->prepare( 's.mannschaft_id = %d', $mannschaft_id );
        }
        // Saison-Tabelle vorhanden?
        $sa_tbl = $wpdb->prefix . 'lsv07i_saisons';
        $has_saison = $wpdb->get_var( "SHOW TABLES LIKE '$sa_tbl'" );

        // Saison-Filter:
        //   'aktiv' (default) → nur aktive Saison + Fallback saison_id=0 (Migrations-/Alt-Slots)
        //   'alle'            → keine Saison-Einschränkung
        //   numerische ID     → genau diese Saison-ID
        if ( $has_saison && $saison_filter !== 'alle' ) {
            if ( $saison_filter === 'aktiv' ) {
                $aktiv_id = (int) $wpdb->get_var( "SELECT id FROM $sa_tbl WHERE aktiv = 1 LIMIT 1" );
                if ( $aktiv_id ) {
                    // Strikt: nur Slots der aktiven Saison.
                    // Slots aus inaktiven Saisons bleiben in der DB (für historische
                    // Anwesenheit/Abrechnung), werden hier aber ausgeblendet.
                    $clauses[] = $wpdb->prepare( 's.saison_id = %d', $aktiv_id );
                }
                // Falls keine aktive Saison existiert: alles anzeigen (Fallback)
            } elseif ( is_numeric( $saison_filter ) ) {
                $clauses[] = $wpdb->prepare( 's.saison_id = %d', (int) $saison_filter );
            }
        }

        $where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';

        $join = $has_saison
            ? "LEFT JOIN $sa_tbl sa ON sa.id = s.saison_id"
            : '';
        $saison_cols = $has_saison ? ', sa.name AS saison_name, sa.aktiv AS saison_aktiv' : '';
        return $wpdb->get_results(
            "SELECT s.*, g.name AS mannschaft_name$saison_cols
               FROM {$wpdb->prefix}lsv07i_training_slots s
          LEFT JOIN {$wpdb->prefix}lsv07_gruppen g ON g.id = s.mannschaft_id
             $join
             $where
            ORDER BY s.mannschaft_id ASC, s.wochentag ASC, s.zeit_von ASC",
            ARRAY_A
        );
    }

    // ── Anwesenheit ───────────────────────────────────────────────────────────
    public static function get_or_create_anwesenheit( $slot_id, $datum ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_anwesenheit WHERE slot_id = %d AND training_datum = %s LIMIT 1",
            $slot_id, $datum
        ), ARRAY_A );
        if ( $existing ) return $existing;

        $slot = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_training_slots WHERE id = %d", $slot_id
        ), ARRAY_A );
        if ( ! $slot ) return null;

        $wpdb->insert( $p . 'lsv07i_anwesenheit', [
            'slot_id'       => $slot_id,
            'mannschaft_id' => $slot['mannschaft_id'],
            'training_datum' => $datum,
            'erstellt_von'  => get_current_user_id(),
        ], [ '%d', '%d', '%s', '%d' ] );

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_anwesenheit WHERE id = %d", $wpdb->insert_id
        ), ARRAY_A );
    }
}
