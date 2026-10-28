<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Das Schema — für den Tag, an dem der interne Bereich nicht mehr da ist.
 *
 * ERZEUGT, NICHT GESCHRIEBEN. Diese Datei entsteht aus den Quellen von
 * lsv07-intern (scratchpad/schema-bauen.php). Von Hand abschreiben wäre
 * der Anfang des Auseinanderlaufens: Zwei Fassungen desselben Schemas
 * weichen irgendwann ab, und dann legt SwimSys eine Tabelle an, die der
 * interne Bereich anders erwartet.
 *
 * Angewendet wird das hier NUR, wenn der interne Bereich nicht aktiv ist.
 * Solange beide laufen, gehört das Schema ihm — zwei Plugins, die dieselbe
 * Tabelle nachbessern, bessern irgendwann gegeneinander nach, und wer
 * welche Spalte gesetzt hat, ist hinterher nicht zu ermitteln.
 *
 * Eine Prüfung (systest.php) vergleicht diese Liste mit den Quellen des
 * internen Bereichs und meldet, wenn eine Tabelle dort dazukommt.
 */
class LSV07S_Schema {

    /** Tabellenname => Spaltendefinition, wie im internen Bereich. */
    public static function tabellen() {
        return [
            // aus includes/class-db.php
            'lsv07_gruppen' => "
                
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(100) NOT NULL DEFAULT '',
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_name (name)",

            // aus includes/class-db.php
            'lsv07i_abr_kilometer' => "
                
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id   INT UNSIGNED NOT NULL,
                datum           DATE NOT NULL,
                beschreibung    VARCHAR(200) NOT NULL DEFAULT '',
                km              DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                tage            INT UNSIGNED NOT NULL DEFAULT 1,
                satz            DECIMAL(6,4) NOT NULL DEFAULT 0.30,
                betrag          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id),
                KEY idx_abr (abrechnung_id)",

            // aus includes/class-db.php
            'lsv07i_abr_sonder' => "
                
                id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id      INT UNSIGNED NOT NULL,
                datum              DATE NOT NULL,
                stunden            DECIMAL(4,2) NOT NULL DEFAULT 1.00,
                beschreibung       VARCHAR(200) NOT NULL DEFAULT '',
                manuell_geloescht  TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_abr (abrechnung_id)",

            // aus includes/class-db.php
            'lsv07i_abr_trainingstage' => "
                
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id    INT UNSIGNED NOT NULL,
                datum            DATE NOT NULL,
                mannschaft_name  VARCHAR(100) NOT NULL DEFAULT '',
                stunden          DECIMAL(4,2) NOT NULL DEFAULT 1.50,
                stunden_plan     DECIMAL(4,2) DEFAULT NULL,
                quelle           VARCHAR(20) NOT NULL DEFAULT 'manuell',
                anwesenheit_id   INT UNSIGNED DEFAULT NULL,
                springer_id      INT UNSIGNED DEFAULT NULL,
                begruendung      TEXT,
                vorbereitung_aktiv TINYINT(1) NOT NULL DEFAULT 0,
                manuell_geloescht TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_abr (abrechnung_id)",

            // aus includes/class-db.php
            'lsv07i_abr_wettkampf' => "
                
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                abrechnung_id   INT UNSIGNED NOT NULL,
                datum           DATE NOT NULL,
                name            VARCHAR(200) NOT NULL DEFAULT '',
                abschnitte      INT UNSIGNED NOT NULL DEFAULT 1,
                pauschale       DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                betrag          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id),
                KEY idx_abr (abrechnung_id)",

            // aus includes/class-db.php
            'lsv07i_abrechnung' => "
                
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
                UNIQUE KEY uq_trainer_qj (trainer_id, quartal, jahr)",

            // aus includes/class-db.php
            'lsv07i_anwesenheit' => "
                
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
                KEY idx_mannschaft (mannschaft_id)",

            // aus lsv07-intern.php
            'lsv07i_anwesenheit_ausschluss' => "
                
                id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_id            INT UNSIGNED NOT NULL,
                trainer_id         INT UNSIGNED NOT NULL,
                ausgeschlossen_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_slot_trainer (slot_id, trainer_id)",

            // aus includes/class-db.php
            'lsv07i_anwesenheit_eintraege' => "
                
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                anwesenheit_id  INT UNSIGNED NOT NULL,
                teilnehmer_typ  VARCHAR(20) NOT NULL DEFAULT 'schwimmer',
                teilnehmer_id   INT UNSIGNED NOT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT 'unbekannt',
                PRIMARY KEY (id),
                UNIQUE KEY uq_anw_teiln (anwesenheit_id, teilnehmer_typ, teilnehmer_id),
                KEY idx_anw (anwesenheit_id)",

            // aus includes/class-ajax-benachrichtigung.php
            'lsv07i_benachrichtigung' => "
                
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                titel          VARCHAR(200) NOT NULL DEFAULT '',
                text           TEXT,
                ersteller_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ersteller_name VARCHAR(160) NOT NULL DEFAULT '',
                erstellt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)",

            // aus includes/class-ajax-benachrichtigung.php
            'lsv07i_benachrichtigung_gelesen' => "
                
                id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                benachrichtigung_id   INT UNSIGNED NOT NULL,
                user_id               BIGINT UNSIGNED NOT NULL,
                user_name             VARCHAR(160) NOT NULL DEFAULT '',
                gelesen_am            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ben_user (benachrichtigung_id, user_id),
                KEY idx_ben (benachrichtigung_id)",

            // aus includes/class-db.php
            'lsv07i_bestzeiten' => "
                
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
                KEY idx_bz_name (mannschaft_id, swimmer_name(100), strecke)",

            // aus includes/class-db.php
            'lsv07i_config' => "
                
                cfg_key   VARCHAR(100) NOT NULL,
                cfg_value TEXT NOT NULL,
                PRIMARY KEY (cfg_key)",

            // aus includes/class-db.php
            'lsv07i_email_queue' => "
                
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
                UNIQUE KEY uq_token (reply_token)",

            // aus lsv07-intern.php
            'lsv07i_fit_anwesenheit' => "
                
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
                KEY idx_gruppe (gruppe_id)",

            // aus lsv07-intern.php
            'lsv07i_fit_anwesenheit_eintraege' => "
                
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                anwesenheit_id  INT UNSIGNED NOT NULL,
                sportler_id     INT UNSIGNED NOT NULL,
                status          VARCHAR(20) NOT NULL DEFAULT 'abwesend',
                PRIMARY KEY (id),
                UNIQUE KEY uq_anw_sportler (anwesenheit_id, sportler_id),
                KEY idx_sportler (sportler_id)",

            // aus lsv07-intern.php
            'lsv07i_fit_gruppen' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(100) NOT NULL,
                sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id)",

            // aus lsv07-intern.php
            'lsv07i_fit_slots' => "
                
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                gruppe_id  INT UNSIGNED NOT NULL,
                wochentag  TINYINT UNSIGNED NOT NULL DEFAULT 1,
                zeit_von   TIME NOT NULL DEFAULT '00:00:00',
                zeit_bis   TIME NOT NULL DEFAULT '00:00:00',
                PRIMARY KEY (id),
                KEY idx_gruppe (gruppe_id)",

            // aus lsv07-intern.php
            'lsv07i_fit_sportler' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                first_name  VARCHAR(80) NOT NULL DEFAULT '',
                last_name   VARCHAR(80) NOT NULL DEFAULT '',
                birth_date  DATE DEFAULT NULL,
                email       VARCHAR(150) NOT NULL DEFAULT '',
                notes       TEXT,
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)",

            // aus lsv07-intern.php
            'lsv07i_fit_sportler_gruppe' => "
                
                sportler_id INT UNSIGNED NOT NULL,
                gruppe_id   INT UNSIGNED NOT NULL,
                PRIMARY KEY (sportler_id, gruppe_id),
                KEY idx_gruppe (gruppe_id)",

            // aus lsv07-intern.php
            'lsv07i_fit_trainer' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(100) NOT NULL,
                email       VARCHAR(150) NOT NULL DEFAULT '',
                wp_user_id  BIGINT UNSIGNED DEFAULT NULL,
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                KEY idx_user (wp_user_id)",

            // aus lsv07-intern.php
            'lsv07i_fit_trainer_gruppe' => "
                
                trainer_id  INT UNSIGNED NOT NULL,
                gruppe_id   INT UNSIGNED NOT NULL,
                PRIMARY KEY (trainer_id, gruppe_id),
                KEY idx_gruppe (gruppe_id)",

            // aus includes/class-db.php
            'lsv07i_kontakte' => "
                
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                swimmer_id BIGINT UNSIGNED NOT NULL,
                name       VARCHAR(160) NOT NULL DEFAULT '',
                telefon    VARCHAR(60)  NOT NULL DEFAULT '',
                email      VARCHAR(160) NOT NULL DEFAULT '',
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_swimmer (swimmer_id)",

            // aus includes/class-db.php
            'lsv07i_konv' => "
                
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
                KEY idx_auto (auto_typ)",

            // aus includes/class-db.php
            'lsv07i_konv_anhang' => "
                
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
                UNIQUE KEY uq_token (token)",

            // aus includes/class-db.php
            'lsv07i_konv_msg' => "
                
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
                KEY idx_konv_zeit (konv_id, erstellt_am)",

            // aus includes/class-db.php
            'lsv07i_konv_reaktion' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                msg_id      INT UNSIGNED NOT NULL,
                user_id     BIGINT UNSIGNED NOT NULL,
                emoji       VARCHAR(8) NOT NULL,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_reaktion (msg_id, user_id, emoji),
                KEY idx_msg (msg_id)",

            // aus includes/class-db.php
            'lsv07i_konv_teilnehmer' => "
                
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
                UNIQUE KEY uq_member (konv_id, teilnehmer_typ, teilnehmer_id, teilnehmer_email)",

            // aus includes/class-db.php
            'lsv07i_log' => "
                
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
                KEY idx_bereich (bereich)",

            // aus includes/class-ajax-mannschaftsbuch.php
            'lsv07i_mb_anwesenheit' => "
                
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tag_id       INT UNSIGNED NOT NULL,
                schwimmer_id INT UNSIGNED NOT NULL,
                status       VARCHAR(20) NOT NULL DEFAULT 'anwesend',
                verspaetung  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                verspaetung_grund VARCHAR(200) NOT NULL DEFAULT '',
                geaendert_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                geaendert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tag_schwimmer (tag_id, schwimmer_id),
                KEY idx_schwimmer (schwimmer_id)",

            // aus includes/class-ajax-mannschaftsbuch.php
            'lsv07i_mb_eintrag' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tag_id        INT UNSIGNED NOT NULL,
                mannschaft_id INT UNSIGNED NOT NULL,
                schwimmer_id  INT UNSIGNED NOT NULL DEFAULT 0,
                art           VARCHAR(10) NOT NULL DEFAULT 'vermerk',
                kategorie     VARCHAR(20) NOT NULL DEFAULT 'sonstiges',
                text          TEXT NULL,
                erledigt      TINYINT(1) NOT NULL DEFAULT 0,
                angelegt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                angelegt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tag (tag_id),
                KEY idx_mann (mannschaft_id, schwimmer_id)",

            // aus includes/class-ajax-mannschaftsbuch.php
            'lsv07i_mb_tag' => "
                
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id  INT UNSIGNED NOT NULL,
                datum          DATE NOT NULL,
                anwesenheit_id INT UNSIGNED NOT NULL DEFAULT 0,
                dokumentation  TEXT NULL,
                bahnen         LONGTEXT NULL,
                angelegt_von   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                angelegt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_mann_datum (mannschaft_id, datum),
                KEY idx_datum (datum)",

            // aus lsv07-intern.php
            'lsv07i_meldung' => "
                
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
                KEY idx_mannschaft (mannschaft_id)",

            // aus lsv07-intern.php
            'lsv07i_meldung_start' => "
                
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                meldung_id      INT UNSIGNED NOT NULL,
                schwimmer_id    INT UNSIGNED NOT NULL DEFAULT 0,
                ist_staffel     TINYINT(1) NOT NULL DEFAULT 0,
                schwimmer_name  VARCHAR(200) NOT NULL DEFAULT '',
                jahrgang        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                abschnitt       VARCHAR(20) NOT NULL DEFAULT '',
                wettkampf_nr    VARCHAR(20) NOT NULL DEFAULT '',
                strecke         VARCHAR(60) NOT NULL DEFAULT '',
                meldezeit       VARCHAR(12) NOT NULL DEFAULT '',
                attest_bis      DATE DEFAULT NULL,
                sortierung      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_meldung (meldung_id, sortierung)",

            // aus lsv07-intern.php
            'lsv07i_nachrichten' => "
                
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
                KEY idx_von (von_user_id)",

            // aus lsv07-intern.php
            'lsv07i_personen' => "
                
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
                KEY idx_legacy_tri_tr (legacy_tri_trainer_id)",

            // aus lsv07-intern.php
            'lsv07i_personen_mannschaft' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                person_id     INT UNSIGNED NOT NULL,
                sparte        VARCHAR(20) NOT NULL,
                mannschaft_id INT UNSIGNED NOT NULL,
                rolle         VARCHAR(20) NOT NULL DEFAULT 'sportler',
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_person_sparte_mann_rolle (person_id, sparte, mannschaft_id, rolle),
                KEY idx_sparte_mann (sparte, mannschaft_id),
                KEY idx_person (person_id)",

            // aus lsv07-intern.php
            'lsv07i_personen_migration_log' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                zeitpunkt   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                quelle      VARCHAR(40) NOT NULL,
                quelle_id   VARCHAR(40) NOT NULL,
                person_id   INT UNSIGNED DEFAULT NULL,
                aktion      VARCHAR(40) NOT NULL,
                notiz       TEXT,
                PRIMARY KEY (id),
                KEY idx_quelle (quelle, quelle_id)",

            // aus lsv07-intern.php
            'lsv07i_personen_sparten_rolle' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                person_id   INT UNSIGNED NOT NULL,
                sparte      VARCHAR(20) NOT NULL,
                rolle       VARCHAR(20) NOT NULL,
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_person_sparte_rolle (person_id, sparte, rolle),
                KEY idx_sparte_rolle (sparte, rolle)",

            // aus includes/class-db.php
            'lsv07i_rechte_templates' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name          VARCHAR(120) NOT NULL,
                beschreibung  VARCHAR(500) DEFAULT NULL,
                rights_json   LONGTEXT NOT NULL,
                erstellt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_am  DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_name (name)",

            // aus includes/class-db.php
            'lsv07i_refl_antwort' => "
                
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
                KEY idx_bogen (bogen_id)",

            // aus includes/class-db.php
            'lsv07i_refl_bogen' => "
                
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
                KEY idx_status (status)",

            // aus includes/class-db.php
            'lsv07i_refl_fragen' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id INT UNSIGNED NOT NULL,
                frage         TEXT NOT NULL,
                sort_order    INT UNSIGNED NOT NULL DEFAULT 0,
                aktiv         TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_mann (mannschaft_id)",

            // aus includes/class-db.php
            'lsv07i_refl_throttle' => "
                
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ip_hash       CHAR(64) NOT NULL,
                versuche      INT UNSIGNED NOT NULL DEFAULT 0,
                gesperrt_bis  DATETIME NULL DEFAULT NULL,
                letzter_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ip (ip_hash)",

            // aus includes/class-db.php
            'lsv07i_saisons' => "
                
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name         VARCHAR(120) NOT NULL,
                start_datum  DATE NOT NULL,
                ende_datum   DATE NULL DEFAULT NULL,
                aktiv        TINYINT(1) NOT NULL DEFAULT 0,
                erstellt_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_name (name),
                KEY idx_aktiv (aktiv)",

            // aus includes/class-db.php
            'lsv07i_schwimmer_dateien' => "
                
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
                KEY idx_schwimmer (schwimmer_id)",

            // aus includes/class-db.php
            'lsv07i_springer' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_id       INT UNSIGNED NOT NULL,
                training_datum DATE NOT NULL,
                trainer_id    INT UNSIGNED NOT NULL,
                eingetragen_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_springer_slot_trainer (slot_id, training_datum, trainer_id),
                KEY idx_slot (slot_id),
                KEY idx_trainer (trainer_id)",

            // aus lsv07-intern.php
            'lsv07i_swimmer_gruppen' => "
                
                swimmer_id  BIGINT UNSIGNED NOT NULL,
                gruppe_id   INT UNSIGNED NOT NULL,
                PRIMARY KEY (swimmer_id, gruppe_id),
                KEY idx_gruppe (gruppe_id)",

            // aus includes/class-db.php
            'lsv07i_ticket_anhang' => "
                
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
                KEY idx_kommentar (kommentar_id)",

            // aus includes/class-db.php
            'lsv07i_ticket_kommentare' => "
                
                id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id            INT UNSIGNED NOT NULL,
                autor_user_id        BIGINT UNSIGNED NOT NULL,
                text                 LONGTEXT NULL,
                ist_system_event     TINYINT(1) NOT NULL DEFAULT 0,
                erstellt_am          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ticket (ticket_id, erstellt_am)",

            // aus includes/class-db.php
            'lsv07i_tickets' => "
                
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
                KEY idx_updated (aktualisiert_am)",

            // aus includes/class-tp-dateien.php
            'lsv07i_tp_datei' => "
                
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                plan_id          INT UNSIGNED NOT NULL,
                dateiname        VARCHAR(255) NOT NULL DEFAULT '',
                gespeichert_als  VARCHAR(100) NOT NULL DEFAULT '',
                groesse          INT UNSIGNED NOT NULL DEFAULT 0,
                sortierung       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                hochgeladen_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hochgeladen_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_plan (plan_id, sortierung)",

            // aus includes/class-db.php
            'lsv07i_trainer' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                name        VARCHAR(200) NOT NULL DEFAULT '',
                email       VARCHAR(200) NOT NULL DEFAULT '',
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (wp_user_id)",

            // aus includes/class-db.php
            'lsv07i_trainer_mannschaft' => "
                
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id   INT UNSIGNED NOT NULL,
                mannschaft_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tm (trainer_id, mannschaft_id)",

            // aus includes/class-db.php
            'lsv07i_trainer_stammdaten' => "
                
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
                UNIQUE KEY uq_trainer (trainer_id)",

            // aus includes/class-db.php
            'lsv07i_training_ausfall' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slot_id       INT UNSIGNED NOT NULL,
                training_datum DATE NOT NULL,
                grund         VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY uq_ausfall (slot_id, training_datum)",

            // aus includes/class-db.php
            'lsv07i_training_slots' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                mannschaft_id INT UNSIGNED NOT NULL,
                wochentag     TINYINT UNSIGNED NOT NULL DEFAULT 1,
                zeit_von      TIME NOT NULL DEFAULT '00:00:00',
                zeit_bis      TIME NOT NULL DEFAULT '00:00:00',
                PRIMARY KEY (id),
                KEY idx_mannschaft (mannschaft_id)",

            // aus includes/class-ajax-trainingsplan.php
            'lsv07i_trainingsplan' => "
                
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                titel          VARCHAR(200) NOT NULL DEFAULT '',
                ersteller_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ersteller_name VARCHAR(160) NOT NULL DEFAULT '',
                freigegeben    TINYINT(1) NOT NULL DEFAULT 0,
                erstellt_am    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ersteller (ersteller_id),
                KEY idx_freigegeben (freigegeben)",

            // aus includes/class-ajax-trainingsplan.php
            'lsv07i_trainingsplan_session' => "
                
                id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                trainingsplan_id  INT UNSIGNED NOT NULL,
                sortierung        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                anzahl            VARCHAR(20) NOT NULL DEFAULT '',
                strecke           VARCHAR(60) NOT NULL DEFAULT '',
                beschreibung      TEXT,
                ausruestung       VARCHAR(200) NOT NULL DEFAULT '',
                kommentar         TEXT,
                PRIMARY KEY (id),
                KEY idx_plan (trainingsplan_id, sortierung)",

            // aus includes/class-db.php
            'lsv07i_tri_anwesenheit' => "
                
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
                KEY idx_datum (training_datum)",

            // aus includes/class-db.php
            'lsv07i_tri_anwesenheit_eintraege' => "
                
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                anwesenheit_id INT UNSIGNED NOT NULL,
                sportler_id    INT UNSIGNED NOT NULL,
                status         VARCHAR(20) NOT NULL DEFAULT 'unbekannt',
                PRIMARY KEY (id),
                UNIQUE KEY uq_anw_sp (anwesenheit_id, sportler_id),
                KEY idx_anw (anwesenheit_id)",

            // aus includes/class-db.php
            'lsv07i_tri_gruppen' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(200) NOT NULL DEFAULT '',
                sort_order  INT NOT NULL DEFAULT 0,
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_aktiv (aktiv),
                KEY idx_sort (sort_order)",

            // aus includes/class-db.php
            'lsv07i_tri_slots' => "
                
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                gruppe_id  INT UNSIGNED NOT NULL,
                wochentag  TINYINT UNSIGNED NOT NULL DEFAULT 1,
                zeit_von   TIME NOT NULL DEFAULT '00:00:00',
                zeit_bis   TIME NOT NULL DEFAULT '00:00:00',
                PRIMARY KEY (id),
                KEY idx_gruppe (gruppe_id)",

            // aus includes/class-db.php
            'lsv07i_tri_sportler' => "
                
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
                KEY idx_name (last_name, first_name)",

            // aus includes/class-db.php
            'lsv07i_tri_sportler_gruppe' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                sportler_id INT UNSIGNED NOT NULL,
                gruppe_id   INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_sg (sportler_id, gruppe_id),
                KEY idx_gruppe (gruppe_id)",

            // aus includes/class-db.php
            'lsv07i_tri_trainer' => "
                
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wp_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                name        VARCHAR(200) NOT NULL DEFAULT '',
                email       VARCHAR(200) NOT NULL DEFAULT '',
                aktiv       TINYINT(1) NOT NULL DEFAULT 1,
                erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user (wp_user_id),
                KEY idx_aktiv (aktiv)",

            // aus includes/class-db.php
            'lsv07i_tri_trainer_gruppe' => "
                
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                trainer_id INT UNSIGNED NOT NULL,
                gruppe_id  INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tg (trainer_id, gruppe_id),
                KEY idx_gruppe (gruppe_id)",

            // aus includes/class-permissions.php
            'lsv07i_user_rechte' => "
                
                user_id    BIGINT UNSIGNED NOT NULL,
                recht      VARCHAR(64)     NOT NULL,
                granted_at DATETIME        DEFAULT CURRENT_TIMESTAMP,
                granted_by BIGINT UNSIGNED NULL,
                PRIMARY KEY (user_id, recht),
                KEY idx_recht (recht)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name          VARCHAR(200) NOT NULL DEFAULT '',
                ort           VARCHAR(200) NOT NULL DEFAULT '',
                datum_von     DATE NOT NULL,
                datum_bis     DATE NOT NULL,
                pauschale     DECIMAL(6,2) NOT NULL DEFAULT 0.00,
                angelegt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                angelegt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_datum (datum_von, datum_bis)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf_anw_eintraege' => "
                
                id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wk_anwesenheit_id  INT UNSIGNED NOT NULL,
                teilnehmer_typ     VARCHAR(20) NOT NULL DEFAULT 'schwimmer',
                teilnehmer_id      INT UNSIGNED NOT NULL,
                status             VARCHAR(20) NOT NULL DEFAULT 'abwesend',
                abschnitte         TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_anw_teiln (wk_anwesenheit_id, teilnehmer_typ, teilnehmer_id),
                KEY idx_anw (wk_anwesenheit_id)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf_anwesenheit' => "
                
                id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wettkampf_tag_id  INT UNSIGNED NOT NULL,
                mannschaft_id     INT UNSIGNED NOT NULL,
                erstellt_von      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tag_mann (wettkampf_tag_id, mannschaft_id),
                KEY idx_mann (mannschaft_id)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf_dokument' => "
                
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
                UNIQUE KEY uq_wk_typ (wettkampf_id, typ)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf_erinnerung' => "
                
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wettkampf_id  INT UNSIGNED NOT NULL,
                email         VARCHAR(200) NOT NULL,
                hinzugefuegt_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hinzugefuegt_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wk_email (wettkampf_id, email)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf_link' => "
                
                id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wettkampf_id     INT UNSIGNED NOT NULL,
                titel            VARCHAR(60) NOT NULL DEFAULT '',
                url              VARCHAR(500) NOT NULL DEFAULT '',
                sortierung       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                hinzugefuegt_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hinzugefuegt_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_wettkampf (wettkampf_id, sortierung)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf_mail_empfaenger' => "
                
                id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email                     VARCHAR(200) NOT NULL,
                freigabe_anfrage          TINYINT(1) NOT NULL DEFAULT 0,
                erinnerung_meldeergebnis  TINYINT(1) NOT NULL DEFAULT 0,
                erinnerung_protokoll      TINYINT(1) NOT NULL DEFAULT 0,
                hinzugefuegt_von          BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hinzugefuegt_am           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_email (email)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf_mannschaft' => "
                
                wettkampf_id   INT UNSIGNED NOT NULL,
                mannschaft_id  INT UNSIGNED NOT NULL,
                PRIMARY KEY (wettkampf_id, mannschaft_id),
                KEY idx_mannschaft (mannschaft_id)",

            // aus lsv07-intern.php
            'lsv07i_wettkampf_tage' => "
                
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                wettkampf_id    INT UNSIGNED NOT NULL,
                datum           DATE NOT NULL,
                abschnitte_plan TINYINT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY uq_wk_datum (wettkampf_id, datum),
                KEY idx_wk (wettkampf_id)",

            // aus includes/class-db.php
            'mv_swimmers' => "
                
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
                KEY idx_team (team_id)",

        ];
    }

    /** Spalten, die im internen Bereich nachträglich dazukamen. */
    public static function nachtrag() {
        return [
            [ 'lsv07i_training_slots', 'saison_id', 'INT UNSIGNED NOT NULL DEFAULT 0' ],
            [ 'lsv07i_tri_slots', 'saison_id', 'INT UNSIGNED NOT NULL DEFAULT 0' ],
            [ 'lsv07i_fit_slots', 'saison_id', 'INT UNSIGNED NOT NULL DEFAULT 0' ],
            [ 'mv_swimmers', 'attest_expires', 'DATE DEFAULT NULL' ],
        ];
    }
}
