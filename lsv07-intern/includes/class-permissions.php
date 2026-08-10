<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Permissions — Granulares Rechte-System
 *
 * Ersetzt schrittweise das Rollen-basierte System. Jeder User kann beliebige
 * Einzelrechte zugewiesen bekommen. Templates fassen typische Rechte-Sets
 * zusammen (z.B. "Schwimmwart" = alle Schwimm-Rechte + Wart-Genehmigungen).
 *
 * VERWENDUNG:
 *   LSV07I_Permissions::can( $user_id, LSV07I_Permissions::SCHWIMMEN_SCHWIMMER_UPDATE )
 *   LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_GENEHMIGEN )
 *
 * Während der Migrationsphase (Iter 1–4) gibt es einen Fallback auf die
 * alten Rollen, damit niemand plötzlich ohne Zugang dasteht. Sobald
 * Iter 4 abgeschlossen ist, kann der Fallback entfernt werden.
 */
class LSV07I_Permissions {

    // ─── RECHTE-KONSTANTEN ───────────────────────────────────────────────────

    // Schwimmen
    const SCHWIMMEN_MANNSCHAFT_READ        = 'schwimmen.mannschaft.read';
    const SCHWIMMEN_MANNSCHAFT_CREATE      = 'schwimmen.mannschaft.create';
    const SCHWIMMEN_MANNSCHAFT_UPDATE      = 'schwimmen.mannschaft.update';
    const SCHWIMMEN_MANNSCHAFT_DELETE      = 'schwimmen.mannschaft.delete';

    const SCHWIMMEN_SCHWIMMER_READ         = 'schwimmen.schwimmer.read';
    const SCHWIMMEN_SCHWIMMER_CREATE       = 'schwimmen.schwimmer.create';
    const SCHWIMMEN_SCHWIMMER_UPDATE       = 'schwimmen.schwimmer.update';
    const SCHWIMMEN_SCHWIMMER_DELETE       = 'schwimmen.schwimmer.delete';
    const SCHWIMMEN_SCHWIMMER_IMPORT       = 'schwimmen.schwimmer.import';
    const SCHWIMMEN_SCHWIMMER_EXPORT       = 'schwimmen.schwimmer.export';

    const SCHWIMMEN_SLOT_READ              = 'schwimmen.slot.read';
    const SCHWIMMEN_SLOT_CREATE            = 'schwimmen.slot.create';
    const SCHWIMMEN_SLOT_UPDATE            = 'schwimmen.slot.update';
    const SCHWIMMEN_SLOT_DELETE            = 'schwimmen.slot.delete';

    const SCHWIMMEN_ANW_READ               = 'schwimmen.anw.read';
    const SCHWIMMEN_ANW_CREATE             = 'schwimmen.anw.create';
    const SCHWIMMEN_ANW_UPDATE             = 'schwimmen.anw.update';
    const SCHWIMMEN_ANW_DELETE             = 'schwimmen.anw.delete';
    const SCHWIMMEN_ANW_DELETE_ALL         = 'schwimmen.anw.delete_all';
    const SCHWIMMEN_ANW_AUSFALL            = 'schwimmen.anw.ausfall';

    const SCHWIMMEN_WETTKAMPF_READ         = 'schwimmen.wettkampf.read';
    const SCHWIMMEN_WETTKAMPF_CREATE       = 'schwimmen.wettkampf.create';
    const SCHWIMMEN_WETTKAMPF_UPDATE       = 'schwimmen.wettkampf.update';
    const SCHWIMMEN_WETTKAMPF_DELETE       = 'schwimmen.wettkampf.delete';
    const SCHWIMMEN_WETTKAMPF_ANW_ERFASSEN = 'schwimmen.wettkampf.anw_erfassen';
    // Freigabe der Wettkampfdaten (Name/Ort/Zeitraum/Ausschreibung) — erst
    // freigegebene Wettkämpfe erscheinen auf der öffentlichen Übersichtsseite.
    // Bewusst ein eigenes Recht ohne Rollen-Fallback: siehe class-access.php.
    const SCHWIMMEN_WETTKAMPF_APPROVE      = 'schwimmen.wettkampf.approve';

    const SCHWIMMEN_SPRINGER_READ          = 'schwimmen.springer.read';
    const SCHWIMMEN_SPRINGER_EINTRAGEN     = 'schwimmen.springer.eintragen';
    const SCHWIMMEN_SPRINGER_AUSTRAGEN     = 'schwimmen.springer.austragen';

    const SCHWIMMEN_BESTZEIT_READ          = 'schwimmen.bestzeit.read';
    const SCHWIMMEN_BESTZEIT_IMPORT        = 'schwimmen.bestzeit.import';
    const SCHWIMMEN_BESTZEIT_DELETE        = 'schwimmen.bestzeit.delete';
    const SCHWIMMEN_BESTZEIT_DELETE_ALL    = 'schwimmen.bestzeit.delete_all';

    // Wettkampfmeldungen (Meldeliste je Wettkampf und Mannschaft).
    // Bewusst eigene Rechte OHNE Rollen-Fallback: den Bereich sieht nur,
    // wer das Leserecht ausdrücklich zugewiesen bekommen hat.
    const SCHWIMMEN_MELDUNG_READ           = 'schwimmen.meldung.read';
    const SCHWIMMEN_MELDUNG_EDIT           = 'schwimmen.meldung.edit';
    const SCHWIMMEN_MELDUNG_DELETE         = 'schwimmen.meldung.delete';
    const SCHWIMMEN_MELDUNG_EXPORT         = 'schwimmen.meldung.export';

    const SCHWIMMEN_EIGEN_READ             = 'schwimmen.eigen.read';
    const SCHWIMMEN_EIGEN_UPDATE           = 'schwimmen.eigen.update';

    // Reflexion (Trainingsreflexion / Feedbackbögen)
    const SCHWIMMEN_REFLEXION_READ         = 'schwimmen.reflexion.read';

    // Trainingsplan: eigene Trainingspläne aus Sessions zusammenstellen,
    // im System ansehen, als PDF exportieren und für andere Trainer
    // freigeben. Jeder Plan gehört seinem Ersteller — nur der Ersteller
    // (oder Admin) darf ihn bearbeiten/löschen/freigeben.
    const SCHWIMMEN_TRAININGSPLAN_READ     = 'schwimmen.trainingsplan.read';

    // Schwimmer-Dateien (Atteste, Bestätigungen — pro Schwimmer global)
    // Trainerübersicht des Sportbereichs (Kontaktdaten aller Trainer).
    // Bewusst ein eigenes Recht: standardmäßig sieht sie nur der Admin.
    const SCHWIMMEN_TRAINER_READ           = 'schwimmen.trainer.read';

    const SCHWIMMEN_DATEI_READ             = 'schwimmen.datei.read';
    const SCHWIMMEN_DATEI_UPLOAD           = 'schwimmen.datei.upload';
    const SCHWIMMEN_DATEI_DELETE           = 'schwimmen.datei.delete';

    // Akte: Name, Geburtsdatum, Mannschaft, Anwesenheit je Mannschaft,
    // Bestzeiten und Kommentar für einen wählbaren Zeitraum, als PDF
    // exportierbar. Zeigt nur Mitglieder der eigenen Mannschaften.
    // Bewusst ein eigenes Recht ohne Rollen-Fallback: siehe class-access.php.
    const SCHWIMMEN_AKTE_READ              = 'schwimmen.akte.read';

    // Trainer-Bereich "Sportler": eigene Sportler anlegen und deren Daten
    // bearbeiten (nur innerhalb der eigenen Mannschaften). Ein einzelnes
    // Recht für beides — wer anlegen darf, darf auch bearbeiten. Bewusst
    // ohne Rollen-Fallback: siehe class-access.php.
    const SCHWIMMEN_TR_SPORTLER_MANAGE     = 'schwimmen.tr_sportler.manage';

    // Triathlon (analog)
    const TRIATHLON_GRUPPE_READ            = 'triathlon.gruppe.read';
    const TRIATHLON_GRUPPE_CREATE          = 'triathlon.gruppe.create';
    const TRIATHLON_GRUPPE_UPDATE          = 'triathlon.gruppe.update';
    const TRIATHLON_GRUPPE_DELETE          = 'triathlon.gruppe.delete';

    const TRIATHLON_SPORTLER_READ          = 'triathlon.sportler.read';
    const TRIATHLON_SPORTLER_CREATE        = 'triathlon.sportler.create';
    const TRIATHLON_SPORTLER_UPDATE        = 'triathlon.sportler.update';
    const TRIATHLON_SPORTLER_DELETE        = 'triathlon.sportler.delete';
    const TRIATHLON_SPORTLER_IMPORT        = 'triathlon.sportler.import';
    const TRIATHLON_SPORTLER_EXPORT        = 'triathlon.sportler.export';

    const TRIATHLON_SLOT_READ              = 'triathlon.slot.read';
    const TRIATHLON_SLOT_CREATE            = 'triathlon.slot.create';
    const TRIATHLON_SLOT_UPDATE            = 'triathlon.slot.update';
    const TRIATHLON_SLOT_DELETE            = 'triathlon.slot.delete';

    const TRIATHLON_ANW_READ               = 'triathlon.anw.read';
    const TRIATHLON_ANW_CREATE             = 'triathlon.anw.create';
    const TRIATHLON_ANW_UPDATE             = 'triathlon.anw.update';
    const TRIATHLON_ANW_DELETE             = 'triathlon.anw.delete';
    const TRIATHLON_ANW_DELETE_ALL         = 'triathlon.anw.delete_all';
    const TRIATHLON_ANW_AUSFALL            = 'triathlon.anw.ausfall';

    const TRIATHLON_TRAINER_READ           = 'triathlon.trainer.read';

    const TRIATHLON_EIGEN_READ             = 'triathlon.eigen.read';
    const TRIATHLON_EIGEN_UPDATE           = 'triathlon.eigen.update';

    // Fitness (analog)
    const FITNESS_GRUPPE_READ              = 'fitness.gruppe.read';
    const FITNESS_GRUPPE_CREATE            = 'fitness.gruppe.create';
    const FITNESS_GRUPPE_UPDATE            = 'fitness.gruppe.update';
    const FITNESS_GRUPPE_DELETE            = 'fitness.gruppe.delete';

    const FITNESS_SPORTLER_READ            = 'fitness.sportler.read';
    const FITNESS_SPORTLER_CREATE          = 'fitness.sportler.create';
    const FITNESS_SPORTLER_UPDATE          = 'fitness.sportler.update';
    const FITNESS_SPORTLER_DELETE          = 'fitness.sportler.delete';
    const FITNESS_SPORTLER_IMPORT          = 'fitness.sportler.import';
    const FITNESS_SPORTLER_EXPORT          = 'fitness.sportler.export';

    const FITNESS_SLOT_READ                = 'fitness.slot.read';
    const FITNESS_SLOT_CREATE              = 'fitness.slot.create';
    const FITNESS_SLOT_UPDATE              = 'fitness.slot.update';
    const FITNESS_SLOT_DELETE              = 'fitness.slot.delete';

    const FITNESS_ANW_READ                 = 'fitness.anw.read';
    const FITNESS_ANW_CREATE               = 'fitness.anw.create';
    const FITNESS_ANW_UPDATE               = 'fitness.anw.update';
    const FITNESS_ANW_DELETE               = 'fitness.anw.delete';
    const FITNESS_ANW_DELETE_ALL           = 'fitness.anw.delete_all';
    const FITNESS_ANW_AUSFALL              = 'fitness.anw.ausfall';

    const FITNESS_TRAINER_READ             = 'fitness.trainer.read';

    const FITNESS_EIGEN_READ               = 'fitness.eigen.read';
    const FITNESS_EIGEN_UPDATE             = 'fitness.eigen.update';

    // Trainer
    const TRAINER_STAMMDATEN_READ          = 'trainer.stammdaten.read';
    const TRAINER_STAMMDATEN_UPDATE        = 'trainer.stammdaten.update';
    const TRAINER_STAMMDATEN_READ_ALL      = 'trainer.stammdaten.read_all';
    const TRAINER_STAMMDATEN_UPDATE_ALL    = 'trainer.stammdaten.update_all';
    const TRAINER_VERWALTUNG_CREATE        = 'trainer.verwaltung.create';
    const TRAINER_VERWALTUNG_DELETE        = 'trainer.verwaltung.delete';

    // Abrechnung
    const ABRECHNUNG_EIGEN_READ            = 'abrechnung.eigen.read';
    const ABRECHNUNG_EIGEN_CREATE          = 'abrechnung.eigen.create';
    const ABRECHNUNG_EIGEN_UPDATE          = 'abrechnung.eigen.update';
    const ABRECHNUNG_EIGEN_DELETE          = 'abrechnung.eigen.delete';
    const ABRECHNUNG_EIGEN_EINREICHEN      = 'abrechnung.eigen.einreichen';

    const ABRECHNUNG_WART_READ             = 'abrechnung.wart.read';
    const ABRECHNUNG_WART_GENEHMIGEN       = 'abrechnung.wart.genehmigen';
    const ABRECHNUNG_WART_ZURUECKGEBEN     = 'abrechnung.wart.zurueckgeben';

    const ABRECHNUNG_KASSE_READ            = 'abrechnung.kasse.read';
    const ABRECHNUNG_KASSE_BEZAHLT         = 'abrechnung.kasse.bezahlt';
    const ABRECHNUNG_KASSE_CSV_EXPORT      = 'abrechnung.kasse.csv_export';
    const ABRECHNUNG_KASSE_JAHRESUEBERSICHT= 'abrechnung.kasse.jahresuebersicht';

    // Sonderabrechnung: eigener, rechte-gesteuerter Abrechnungstyp unter
    // "Trainer" (Tag + Stunden statt Mannschaftstraining). Bewusst NICHT an
    // die normale Trainer-Rolle gekoppelt — nur wer dieses Recht hat, sieht
    // den Tab ueberhaupt. Genehmigung/Kasse laufen ueber die BESTEHENDEN
    // ABRECHNUNG_WART_*/ABRECHNUNG_KASSE_*-Rechte (gleiche Genehmiger).
    const ABRECHNUNG_SONDER_EIGEN_READ       = 'abrechnung.sonder.eigen.read';
    const ABRECHNUNG_SONDER_EIGEN_CREATE     = 'abrechnung.sonder.eigen.create';
    const ABRECHNUNG_SONDER_EIGEN_UPDATE     = 'abrechnung.sonder.eigen.update';
    const ABRECHNUNG_SONDER_EIGEN_DELETE     = 'abrechnung.sonder.eigen.delete';
    const ABRECHNUNG_SONDER_EIGEN_EINREICHEN = 'abrechnung.sonder.eigen.einreichen';

    // Personenverwaltung
    const PERSONEN_READ                    = 'personen.read';
    const PERSONEN_CREATE                  = 'personen.create';
    const PERSONEN_UPDATE                  = 'personen.update';
    const PERSONEN_DELETE                  = 'personen.delete';
    const PERSONEN_IMPORT                  = 'personen.import';
    const PERSONEN_EXPORT                  = 'personen.export';

    // Nachrichten
    const NACHRICHTEN_READ                 = 'nachrichten.read';
    const NACHRICHTEN_SEND                 = 'nachrichten.send';
    const NACHRICHTEN_SEND_MANNSCHAFT      = 'nachrichten.send_mannschaft';
    const NACHRICHTEN_DELETE               = 'nachrichten.delete';
    const NACHRICHTEN_GRUPPE_CREATE        = 'nachrichten.gruppe_create';
    const NACHRICHTEN_EXTERN               = 'nachrichten.extern_send';
    const NACHRICHTEN_ANHANG_UPLOAD        = 'nachrichten.anhang_upload';
    const NACHRICHTEN_VERTEILER_VERWALTEN  = 'nachrichten.verteiler_verwalten';

    // Tickets
    // tickets.create wird NICHT als Permission verwaltet, weil JEDER eingeloggte Nutzer
    // Tickets anlegen darf. Eigene Tickets sehen und kommentieren ebenfalls.
    const TICKETS_READ_ALL                 = 'tickets.read_all';      // Tickets aller Nutzer sehen
    const TICKETS_COMMENT_ANY              = 'tickets.comment_any';   // Auf fremde Tickets antworten
    const TICKETS_STATUS_CHANGE            = 'tickets.status_change'; // Status setzen (auch fremde)
    const TICKETS_ASSIGN                   = 'tickets.assign';        // Tickets zuweisen
    const TICKETS_DELETE                   = 'tickets.delete';        // Tickets löschen (Admin-Funktion)

    // Admin
    const ADMIN_CONFIG_READ                = 'admin.config.read';
    const ADMIN_CONFIG_UPDATE              = 'admin.config.update';
    const ADMIN_PERMISSIONS_READ           = 'admin.permissions.read';
    const ADMIN_PERMISSIONS_UPDATE         = 'admin.permissions.update';
    const ADMIN_WP_USERS_READ              = 'admin.wp_users.read';
    const ADMIN_WP_USERS_ASSIGN            = 'admin.wp_users.assign';
    const ADMIN_MIGRATION_RUN              = 'admin.migration.run';
    const ADMIN_ATTEST_MAIL                = 'admin.attest.mail';
    const ADMIN_SAISON_READ                = 'admin.saison.read';
    const ADMIN_SAISON_MANAGE              = 'admin.saison.manage';
    const ADMIN_LOG_READ                   = 'admin.log.read';

    // ─── BEREICHS-DEFINITIONEN für die Admin-UI ──────────────────────────────

    /**
     * Liste aller Rechte gruppiert nach Bereich, mit Beschreibungen für die UI.
     * In Iter 2 wird das von der Admin-UI ausgelesen, um die Checkbox-Matrix zu rendern.
     */
    public static function all_rights() {
        return [
            'Schwimmen' => [
                'Mannschaften' => [
                    self::SCHWIMMEN_MANNSCHAFT_READ   => 'Mannschaftsliste sehen',
                    self::SCHWIMMEN_MANNSCHAFT_CREATE => 'Neue Mannschaft anlegen',
                    self::SCHWIMMEN_MANNSCHAFT_UPDATE => 'Mannschaft bearbeiten',
                    self::SCHWIMMEN_MANNSCHAFT_DELETE => 'Mannschaft löschen',
                ],
                'Schwimmer' => [
                    self::SCHWIMMEN_SCHWIMMER_READ   => 'Schwimmer-Liste sehen',
                    self::SCHWIMMEN_SCHWIMMER_CREATE => 'Schwimmer anlegen',
                    self::SCHWIMMEN_SCHWIMMER_UPDATE => 'Schwimmer-Daten ändern',
                    self::SCHWIMMEN_SCHWIMMER_DELETE => 'Schwimmer löschen',
                    self::SCHWIMMEN_SCHWIMMER_IMPORT => 'Schwimmer per CSV importieren',
                    self::SCHWIMMEN_SCHWIMMER_EXPORT => 'Schwimmer als Excel exportieren',
                ],
                'Trainerübersicht' => [
                    self::SCHWIMMEN_TRAINER_READ => 'Trainer-Tab im Schwimmbereich sehen (Kontaktdaten aller Trainer)',
                ],
                'Trainings-Slots' => [
                    self::SCHWIMMEN_SLOT_READ   => 'Slots sehen',
                    self::SCHWIMMEN_SLOT_CREATE => 'Slot anlegen',
                    self::SCHWIMMEN_SLOT_UPDATE => 'Slot bearbeiten',
                    self::SCHWIMMEN_SLOT_DELETE => 'Slot löschen',
                ],
                'Anwesenheit' => [
                    self::SCHWIMMEN_ANW_READ       => 'Sessions sehen',
                    self::SCHWIMMEN_ANW_CREATE     => 'Anwesenheit erfassen',
                    self::SCHWIMMEN_ANW_UPDATE     => 'Eintrag bearbeiten',
                    self::SCHWIMMEN_ANW_DELETE     => 'Session löschen',
                    self::SCHWIMMEN_ANW_DELETE_ALL => 'Alle Sessions löschen (gefährlich)',
                    self::SCHWIMMEN_ANW_AUSFALL    => 'Training als Ausfall markieren',
                ],
                'Wettkämpfe' => [
                    self::SCHWIMMEN_WETTKAMPF_READ         => 'Wettkämpfe sehen',
                    self::SCHWIMMEN_WETTKAMPF_CREATE       => 'Wettkampf anlegen',
                    self::SCHWIMMEN_WETTKAMPF_UPDATE       => 'Wettkampf bearbeiten',
                    self::SCHWIMMEN_WETTKAMPF_DELETE       => 'Wettkampf löschen',
                    self::SCHWIMMEN_WETTKAMPF_ANW_ERFASSEN => 'Wettkampf-Anwesenheit erfassen',
                    self::SCHWIMMEN_WETTKAMPF_APPROVE      => 'Wettkampfdaten freigeben (öffentliche Übersichtsseite)',
                ],
                'Springer' => [
                    self::SCHWIMMEN_SPRINGER_READ      => 'Springer-Plan sehen',
                    self::SCHWIMMEN_SPRINGER_EINTRAGEN => 'Sich als Springer eintragen',
                    self::SCHWIMMEN_SPRINGER_AUSTRAGEN => 'Austragen',
                ],
                'Bestzeiten' => [
                    self::SCHWIMMEN_BESTZEIT_READ       => 'Bestzeiten sehen',
                    self::SCHWIMMEN_BESTZEIT_IMPORT     => 'Bestzeiten importieren',
                    self::SCHWIMMEN_BESTZEIT_DELETE     => 'Einzelne Bestzeit löschen',
                    self::SCHWIMMEN_BESTZEIT_DELETE_ALL => 'Alle Bestzeiten einer Mannschaft löschen',
                ],
                'Wettkampfmeldungen' => [
                    self::SCHWIMMEN_MELDUNG_READ   => 'Meldungen-Tab sehen und Meldelisten öffnen',
                    self::SCHWIMMEN_MELDUNG_EDIT   => 'Meldeliste anlegen und bearbeiten',
                    self::SCHWIMMEN_MELDUNG_DELETE => 'Meldeliste löschen',
                    self::SCHWIMMEN_MELDUNG_EXPORT => 'Meldeliste als Excel exportieren',
                ],
                'Reflexion' => [
                    self::SCHWIMMEN_REFLEXION_READ => 'Reflexionsbögen sehen',
                ],
                'Trainingsplan' => [
                    self::SCHWIMMEN_TRAININGSPLAN_READ => 'Trainingsplan-Tab sehen (eigene Pläne erstellen, freigegebene Pläne anderer Trainer einsehen)',
                ],
                'Eigene Daten' => [
                    self::SCHWIMMEN_EIGEN_READ   => 'Eigene Daten/Anwesenheit sehen',
                    self::SCHWIMMEN_EIGEN_UPDATE => 'Eigenes Profil bearbeiten',
                ],
                'Schwimmer-Dateien (Atteste etc.)' => [
                    self::SCHWIMMEN_DATEI_READ   => 'Dateien sehen und herunterladen',
                    self::SCHWIMMEN_DATEI_UPLOAD => 'Dateien hochladen',
                    self::SCHWIMMEN_DATEI_DELETE => 'Dateien löschen',
                ],
                'Akte' => [
                    self::SCHWIMMEN_AKTE_READ => 'Schwimmer-Akte einsehen (Name, Geburtsdatum, Mannschaft, Anwesenheit, Bestzeiten, Kommentar) und als PDF exportieren',
                ],
                'Trainer-Bereich: eigene Sportler' => [
                    self::SCHWIMMEN_TR_SPORTLER_MANAGE => 'Im Trainer-Bereich eigene Sportler anlegen, bearbeiten und in andere Mannschaften verschieben',
                ],
            ],
            'Triathlon' => [
                'Gruppen' => [
                    self::TRIATHLON_GRUPPE_READ   => 'Gruppen sehen',
                    self::TRIATHLON_GRUPPE_CREATE => 'Gruppe anlegen',
                    self::TRIATHLON_GRUPPE_UPDATE => 'Gruppe bearbeiten',
                    self::TRIATHLON_GRUPPE_DELETE => 'Gruppe löschen',
                ],
                'Sportler' => [
                    self::TRIATHLON_SPORTLER_READ   => 'Sportler-Liste sehen',
                    self::TRIATHLON_SPORTLER_CREATE => 'Sportler anlegen',
                    self::TRIATHLON_SPORTLER_UPDATE => 'Sportler bearbeiten',
                    self::TRIATHLON_SPORTLER_DELETE => 'Sportler löschen',
                    self::TRIATHLON_SPORTLER_IMPORT => 'Sportler importieren',
                    self::TRIATHLON_SPORTLER_EXPORT => 'Sportler exportieren',
                ],
                'Trainerübersicht' => [
                    self::TRIATHLON_TRAINER_READ => 'Trainer-Tab im Triathlonbereich sehen (Kontaktdaten aller Trainer)',
                ],
                'Slots' => [
                    self::TRIATHLON_SLOT_READ   => 'Slots sehen',
                    self::TRIATHLON_SLOT_CREATE => 'Slot anlegen',
                    self::TRIATHLON_SLOT_UPDATE => 'Slot bearbeiten',
                    self::TRIATHLON_SLOT_DELETE => 'Slot löschen',
                ],
                'Anwesenheit' => [
                    self::TRIATHLON_ANW_READ       => 'Sessions sehen',
                    self::TRIATHLON_ANW_CREATE     => 'Anwesenheit erfassen',
                    self::TRIATHLON_ANW_UPDATE     => 'Eintrag bearbeiten',
                    self::TRIATHLON_ANW_DELETE     => 'Session löschen',
                    self::TRIATHLON_ANW_DELETE_ALL => 'Alle Sessions löschen (gefährlich)',
                    self::TRIATHLON_ANW_AUSFALL    => 'Training als Ausfall markieren',
                ],
                'Eigene Daten' => [
                    self::TRIATHLON_EIGEN_READ   => 'Eigene Daten sehen',
                    self::TRIATHLON_EIGEN_UPDATE => 'Eigenes Profil bearbeiten',
                ],
            ],
            'Fitness' => [
                'Gruppen' => [
                    self::FITNESS_GRUPPE_READ   => 'Gruppen sehen',
                    self::FITNESS_GRUPPE_CREATE => 'Gruppe anlegen',
                    self::FITNESS_GRUPPE_UPDATE => 'Gruppe bearbeiten',
                    self::FITNESS_GRUPPE_DELETE => 'Gruppe löschen',
                ],
                'Sportler' => [
                    self::FITNESS_SPORTLER_READ   => 'Sportler-Liste sehen',
                    self::FITNESS_SPORTLER_CREATE => 'Sportler anlegen',
                    self::FITNESS_SPORTLER_UPDATE => 'Sportler bearbeiten',
                    self::FITNESS_SPORTLER_DELETE => 'Sportler löschen',
                    self::FITNESS_SPORTLER_IMPORT => 'Sportler importieren',
                    self::FITNESS_SPORTLER_EXPORT => 'Sportler exportieren',
                ],
                'Trainerübersicht' => [
                    self::FITNESS_TRAINER_READ => 'Trainer-Tab im Fitnessbereich sehen (Kontaktdaten aller Trainer)',
                ],
                'Slots' => [
                    self::FITNESS_SLOT_READ   => 'Slots sehen',
                    self::FITNESS_SLOT_CREATE => 'Slot anlegen',
                    self::FITNESS_SLOT_UPDATE => 'Slot bearbeiten',
                    self::FITNESS_SLOT_DELETE => 'Slot löschen',
                ],
                'Anwesenheit' => [
                    self::FITNESS_ANW_READ       => 'Sessions sehen',
                    self::FITNESS_ANW_CREATE     => 'Anwesenheit erfassen',
                    self::FITNESS_ANW_UPDATE     => 'Eintrag bearbeiten',
                    self::FITNESS_ANW_DELETE     => 'Session löschen',
                    self::FITNESS_ANW_DELETE_ALL => 'Alle Sessions löschen (gefährlich)',
                    self::FITNESS_ANW_AUSFALL    => 'Training als Ausfall markieren',
                ],
                'Eigene Daten' => [
                    self::FITNESS_EIGEN_READ   => 'Eigene Daten sehen',
                    self::FITNESS_EIGEN_UPDATE => 'Eigenes Profil bearbeiten',
                ],
            ],
            'Trainer' => [
                'Stammdaten' => [
                    self::TRAINER_STAMMDATEN_READ       => 'Eigene Stammdaten sehen',
                    self::TRAINER_STAMMDATEN_UPDATE     => 'Eigene Stammdaten ändern',
                    self::TRAINER_STAMMDATEN_READ_ALL   => 'Alle Trainer-Stammdaten sehen',
                    self::TRAINER_STAMMDATEN_UPDATE_ALL => 'Andere Trainer bearbeiten',
                ],
                'Verwaltung' => [
                    self::TRAINER_VERWALTUNG_CREATE => 'Trainer anlegen',
                    self::TRAINER_VERWALTUNG_DELETE => 'Trainer deaktivieren',
                ],
            ],
            'Abrechnung' => [
                'Eigene Abrechnung' => [
                    self::ABRECHNUNG_EIGEN_READ       => 'Eigene Abrechnung sehen',
                    self::ABRECHNUNG_EIGEN_CREATE     => 'Quartalsabrechnung erstellen',
                    self::ABRECHNUNG_EIGEN_UPDATE     => 'Eigene Abrechnung bearbeiten',
                    self::ABRECHNUNG_EIGEN_DELETE     => 'Eigene Abrechnung löschen',
                    self::ABRECHNUNG_EIGEN_EINREICHEN => 'Beim Wart einreichen',
                ],
                'Sonderabrechnung (Tag + Stunden, unter Trainer)' => [
                    self::ABRECHNUNG_SONDER_EIGEN_READ       => 'Sonderabrechnung sehen (schaltet den Tab frei)',
                    self::ABRECHNUNG_SONDER_EIGEN_CREATE     => 'Einträge anlegen',
                    self::ABRECHNUNG_SONDER_EIGEN_UPDATE     => 'Einträge bearbeiten',
                    self::ABRECHNUNG_SONDER_EIGEN_DELETE     => 'Einträge löschen',
                    self::ABRECHNUNG_SONDER_EIGEN_EINREICHEN => 'Beim Wart einreichen',
                ],
                'Wart (genehmigt)' => [
                    self::ABRECHNUNG_WART_READ         => 'Eingereichte Abrechnungen sehen',
                    self::ABRECHNUNG_WART_GENEHMIGEN   => 'Genehmigen oder ablehnen',
                    self::ABRECHNUNG_WART_ZURUECKGEBEN => 'Mit Begründung zurückgeben',
                ],
                'Kassenwart' => [
                    self::ABRECHNUNG_KASSE_READ             => 'Genehmigte Abrechnungen sehen',
                    self::ABRECHNUNG_KASSE_BEZAHLT          => 'Als bezahlt markieren',
                    self::ABRECHNUNG_KASSE_CSV_EXPORT       => 'CSV für Buchhaltung',
                    self::ABRECHNUNG_KASSE_JAHRESUEBERSICHT => 'Jahresübersicht ansehen',
                ],
            ],
            'Personenverwaltung' => [
                'Personen' => [
                    self::PERSONEN_READ   => 'Personen-Liste sehen',
                    self::PERSONEN_CREATE => 'Person anlegen',
                    self::PERSONEN_UPDATE => 'Person bearbeiten',
                    self::PERSONEN_DELETE => 'Person löschen',
                    self::PERSONEN_IMPORT => 'Personen per CSV importieren',
                    self::PERSONEN_EXPORT => 'Personen exportieren',
                ],
            ],
            'Nachrichten' => [
                'Nachrichten' => [
                    self::NACHRICHTEN_READ            => 'Posteingang sehen',
                    self::NACHRICHTEN_SEND            => 'Einzelne Nachricht senden',
                    self::NACHRICHTEN_SEND_MANNSCHAFT => 'An ganze Mannschaft senden',
                    self::NACHRICHTEN_DELETE          => 'Eigene Nachrichten löschen',
                    self::NACHRICHTEN_GRUPPE_CREATE   => 'Gruppen-Chats erstellen',
                    self::NACHRICHTEN_EXTERN          => 'An Externe (Eltern) senden',
                    self::NACHRICHTEN_ANHANG_UPLOAD   => 'Dateien anhängen',
                    self::NACHRICHTEN_VERTEILER_VERWALTEN => 'Verteilerlisten verwalten',
                ],
            ],
            'Tickets' => [
                'Tickets bearbeiten' => [
                    self::TICKETS_READ_ALL      => 'Tickets aller Nutzer sehen',
                    self::TICKETS_COMMENT_ANY   => 'Auf fremde Tickets antworten',
                    self::TICKETS_STATUS_CHANGE => 'Status fremder Tickets ändern',
                    self::TICKETS_ASSIGN        => 'Tickets zuweisen',
                    self::TICKETS_DELETE        => 'Tickets löschen',
                ],
            ],
            'Admin' => [
                'Konfiguration' => [
                    self::ADMIN_CONFIG_READ   => 'Plugin-Konfiguration sehen',
                    self::ADMIN_CONFIG_UPDATE => 'Plugin-Konfiguration ändern',
                ],
                'Rechte-Verwaltung' => [
                    self::ADMIN_PERMISSIONS_READ   => 'Sehen wer welche Rechte hat',
                    self::ADMIN_PERMISSIONS_UPDATE => 'Rechte zuweisen/entziehen',
                ],
                'WP-User-Zuordnung' => [
                    self::ADMIN_WP_USERS_READ   => 'WordPress-Benutzer sehen',
                    self::ADMIN_WP_USERS_ASSIGN => 'WP-User zuordnen',
                ],
                'System' => [
                    self::ADMIN_MIGRATION_RUN => 'Personen-Migration ausführen',
                    self::ADMIN_ATTEST_MAIL   => 'Atteste-Massenmail',
                ],
                'Saison-Verwaltung' => [
                    self::ADMIN_SAISON_READ   => 'Saisons sehen',
                    self::ADMIN_SAISON_MANAGE => 'Saisons anlegen, aktivieren und löschen',
                ],
                'Aktivitätsprotokoll' => [
                    self::ADMIN_LOG_READ => 'Aktivitätsprotokoll (Log) einsehen',
                ],
            ],
        ];
    }

    /**
     * Liefert eine flache Liste aller Recht-Codes (für Validierung).
     */
    public static function all_codes() {
        $out = [];
        foreach ( self::all_rights() as $bereich => $gruppen ) {
            foreach ( $gruppen as $gruppe => $rechte ) {
                foreach ( $rechte as $code => $label ) {
                    $out[] = $code;
                }
            }
        }
        return $out;
    }

    // ─── ROLLEN-TEMPLATES ────────────────────────────────────────────────────

    /**
     * Liefert alle Templates: hartcodierte (built-in) plus benutzerdefinierte
     * aus der DB. Built-in-Templates haben das Flag 'built_in' = true und
     * können nicht gelöscht oder bearbeitet werden.
     */
    public static function all_templates() {
        $tpls = self::built_in_templates();

        // Vom User "gelöschte" System-Templates ausblenden.
        // Sie bleiben in der PHP-Klasse erhalten, werden aber aus der UI gefiltert.
        $hidden = self::get_hidden_built_in_ids();
        if ( $hidden ) {
            foreach ( $hidden as $hid ) {
                if ( isset( $tpls[ $hid ] ) ) {
                    unset( $tpls[ $hid ] );
                }
            }
        }

        // Custom-Templates aus der DB nachladen
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_rechte_templates';
        // Existiert die Tabelle? (Sandbox-Tests / frische Installation)
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" );
        if ( $exists ) {
            $rows = $wpdb->get_results( "SELECT id, name, beschreibung, rights_json FROM $tbl ORDER BY name ASC", ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $r ) {
                    $rights = json_decode( $r['rights_json'], true );
                    if ( ! is_array( $rights ) ) $rights = [];
                    // Nur gültige Codes durchlassen
                    $rights = array_values( array_intersect( $rights, self::all_codes() ) );
                    // ID-Präfix damit Custom-Templates eindeutig adressierbar sind
                    $tid = 'custom_' . (int) $r['id'];
                    $tpls[ $tid ] = [
                        'name'         => $r['name'],
                        'beschreibung' => $r['beschreibung'],
                        'rights'       => $rights,
                        'built_in'     => false,
                        'db_id'        => (int) $r['id'],
                    ];
                }
            }
        }
        return $tpls;
    }

    /**
     * IDs der System-Templates, die der User ausgeblendet ("gelöscht") hat.
     * Persistiert in einer WordPress-Option als Array von Strings.
     */
    public static function get_hidden_built_in_ids() {
        $opt = get_option( 'lsv07i_geloeschte_system_templates', [] );
        return is_array( $opt ) ? array_values( $opt ) : [];
    }

    /**
     * Markiert ein System-Template als gelöscht.
     * Es bleibt in der PHP-Klasse, wird aber aus der UI gefiltert.
     */
    public static function hide_built_in( $template_id ) {
        $built = self::built_in_templates();
        if ( ! isset( $built[ $template_id ] ) ) return false;
        $hidden = self::get_hidden_built_in_ids();
        if ( ! in_array( $template_id, $hidden, true ) ) {
            $hidden[] = $template_id;
            update_option( 'lsv07i_geloeschte_system_templates', $hidden );
        }
        return true;
    }

    /**
     * Macht ein gelöschtes System-Template wieder sichtbar.
     */
    public static function unhide_built_in( $template_id ) {
        $hidden  = self::get_hidden_built_in_ids();
        $hidden  = array_values( array_diff( $hidden, [ $template_id ] ) );
        update_option( 'lsv07i_geloeschte_system_templates', $hidden );
        return true;
    }

    /**
     * Hartcodierte Templates. Diese sind immer verfügbar und können nicht
     * gelöscht oder geändert werden — sie dienen u.a. als Migrations-Basis
     * für die alten WordPress-Rollen.
     */
    public static function built_in_templates() {
        $built_in = [
            'admin_full' => [
                'name' => 'Admin (volle Rechte)',
                'rights' => self::all_codes(),
            ],
            'schwimmwart' => [
                'name' => 'Schwimmwart',
                'rights' => self::merge( self::trainer_schwimmen_rights(), [
                    self::SCHWIMMEN_MANNSCHAFT_CREATE, self::SCHWIMMEN_MANNSCHAFT_UPDATE, self::SCHWIMMEN_MANNSCHAFT_DELETE,
                    self::SCHWIMMEN_SCHWIMMER_CREATE, self::SCHWIMMEN_SCHWIMMER_UPDATE, self::SCHWIMMEN_SCHWIMMER_DELETE,
                    self::SCHWIMMEN_SCHWIMMER_IMPORT, self::SCHWIMMEN_SCHWIMMER_EXPORT,
                    self::SCHWIMMEN_SLOT_READ, self::SCHWIMMEN_SLOT_CREATE, self::SCHWIMMEN_SLOT_UPDATE, self::SCHWIMMEN_SLOT_DELETE,
                    self::SCHWIMMEN_WETTKAMPF_CREATE, self::SCHWIMMEN_WETTKAMPF_UPDATE, self::SCHWIMMEN_WETTKAMPF_DELETE,
                    self::SCHWIMMEN_BESTZEIT_IMPORT, self::SCHWIMMEN_BESTZEIT_DELETE, self::SCHWIMMEN_BESTZEIT_DELETE_ALL,
                    self::SCHWIMMEN_ANW_DELETE, self::SCHWIMMEN_ANW_DELETE_ALL,
                    self::TRAINER_STAMMDATEN_READ_ALL, self::TRAINER_STAMMDATEN_UPDATE_ALL,
                    self::TRAINER_VERWALTUNG_CREATE, self::TRAINER_VERWALTUNG_DELETE,
                    self::ABRECHNUNG_WART_READ, self::ABRECHNUNG_WART_GENEHMIGEN, self::ABRECHNUNG_WART_ZURUECKGEBEN,
                    self::PERSONEN_READ, self::PERSONEN_CREATE, self::PERSONEN_UPDATE,
                    self::SCHWIMMEN_DATEI_READ, self::SCHWIMMEN_DATEI_UPLOAD, self::SCHWIMMEN_DATEI_DELETE,
                ]),
            ],
            'triathlonwart' => [
                'name' => 'Triathlonwart',
                'rights' => self::merge( self::trainer_triathlon_rights(), [
                    self::TRIATHLON_GRUPPE_CREATE, self::TRIATHLON_GRUPPE_UPDATE, self::TRIATHLON_GRUPPE_DELETE,
                    self::TRIATHLON_SPORTLER_CREATE, self::TRIATHLON_SPORTLER_UPDATE, self::TRIATHLON_SPORTLER_DELETE,
                    self::TRIATHLON_SPORTLER_IMPORT, self::TRIATHLON_SPORTLER_EXPORT,
                    self::TRIATHLON_SLOT_CREATE, self::TRIATHLON_SLOT_UPDATE, self::TRIATHLON_SLOT_DELETE,
                    self::TRIATHLON_ANW_DELETE, self::TRIATHLON_ANW_DELETE_ALL,
                    self::TRAINER_STAMMDATEN_READ_ALL, self::TRAINER_STAMMDATEN_UPDATE_ALL,
                    self::TRAINER_VERWALTUNG_CREATE, self::TRAINER_VERWALTUNG_DELETE,
                    self::ABRECHNUNG_WART_READ, self::ABRECHNUNG_WART_GENEHMIGEN, self::ABRECHNUNG_WART_ZURUECKGEBEN,
                    self::PERSONEN_READ, self::PERSONEN_CREATE, self::PERSONEN_UPDATE,
                ]),
            ],
            'fitnesswart' => [
                'name' => 'Fitnesswart',
                'rights' => self::merge( self::trainer_fitness_rights(), [
                    self::FITNESS_GRUPPE_CREATE, self::FITNESS_GRUPPE_UPDATE, self::FITNESS_GRUPPE_DELETE,
                    self::FITNESS_SPORTLER_CREATE, self::FITNESS_SPORTLER_UPDATE, self::FITNESS_SPORTLER_DELETE,
                    self::FITNESS_SPORTLER_IMPORT, self::FITNESS_SPORTLER_EXPORT,
                    self::FITNESS_SLOT_CREATE, self::FITNESS_SLOT_UPDATE, self::FITNESS_SLOT_DELETE,
                    self::FITNESS_ANW_DELETE, self::FITNESS_ANW_DELETE_ALL,
                    self::TRAINER_STAMMDATEN_READ_ALL, self::TRAINER_STAMMDATEN_UPDATE_ALL,
                    self::TRAINER_VERWALTUNG_CREATE, self::TRAINER_VERWALTUNG_DELETE,
                    self::ABRECHNUNG_WART_READ, self::ABRECHNUNG_WART_GENEHMIGEN, self::ABRECHNUNG_WART_ZURUECKGEBEN,
                    self::PERSONEN_READ, self::PERSONEN_CREATE, self::PERSONEN_UPDATE,
                ]),
            ],
            'kassenwart' => [
                'name' => 'Kassenwart / Finanzwart',
                'rights' => [
                    self::ABRECHNUNG_KASSE_READ, self::ABRECHNUNG_KASSE_BEZAHLT,
                    self::ABRECHNUNG_KASSE_CSV_EXPORT, self::ABRECHNUNG_KASSE_JAHRESUEBERSICHT,
                    self::NACHRICHTEN_READ, self::NACHRICHTEN_SEND, self::NACHRICHTEN_ANHANG_UPLOAD,
                ],
            ],
            'trainer_schwimmen' => [
                'name' => 'Trainer Schwimmen',
                'rights' => self::trainer_schwimmen_rights(),
            ],
            'trainer_triathlon' => [
                'name' => 'Trainer Triathlon',
                'rights' => self::trainer_triathlon_rights(),
            ],
            'trainer_fitness' => [
                'name' => 'Trainer Fitness',
                'rights' => self::trainer_fitness_rights(),
            ],
            'mitglied_schwimmen' => [
                'name' => 'Schwimmer (intern)',
                'rights' => [
                    self::SCHWIMMEN_EIGEN_READ, self::SCHWIMMEN_EIGEN_UPDATE,
                    self::SCHWIMMEN_BESTZEIT_READ, self::SCHWIMMEN_SPRINGER_READ,
                    self::NACHRICHTEN_READ, self::NACHRICHTEN_SEND, self::NACHRICHTEN_ANHANG_UPLOAD,
                ],
            ],
            'mitglied_triathlon' => [
                'name' => 'Triathlet (intern)',
                'rights' => [
                    self::TRIATHLON_EIGEN_READ, self::TRIATHLON_EIGEN_UPDATE,
                    self::NACHRICHTEN_READ, self::NACHRICHTEN_SEND, self::NACHRICHTEN_ANHANG_UPLOAD,
                ],
            ],
            'mitglied_fitness' => [
                'name' => 'Fitness-Mitglied (intern)',
                'rights' => [
                    self::FITNESS_EIGEN_READ, self::FITNESS_EIGEN_UPDATE,
                    self::NACHRICHTEN_READ, self::NACHRICHTEN_SEND, self::NACHRICHTEN_ANHANG_UPLOAD,
                ],
            ],
        ];
        // Flag setzen, damit die UI built-in von custom unterscheiden kann
        foreach ( $built_in as $id => &$tpl ) {
            $tpl['built_in'] = true;
        }
        unset( $tpl );
        return $built_in;
    }

    private static function trainer_schwimmen_rights() {
        return [
            self::SCHWIMMEN_MANNSCHAFT_READ,
            self::SCHWIMMEN_SCHWIMMER_READ, self::SCHWIMMEN_SCHWIMMER_UPDATE,
            self::SCHWIMMEN_SLOT_READ,
            self::SCHWIMMEN_ANW_READ, self::SCHWIMMEN_ANW_CREATE, self::SCHWIMMEN_ANW_UPDATE, self::SCHWIMMEN_ANW_AUSFALL,
            self::SCHWIMMEN_WETTKAMPF_READ, self::SCHWIMMEN_WETTKAMPF_ANW_ERFASSEN,
            self::SCHWIMMEN_SPRINGER_READ, self::SCHWIMMEN_SPRINGER_EINTRAGEN, self::SCHWIMMEN_SPRINGER_AUSTRAGEN,
            self::SCHWIMMEN_BESTZEIT_READ,
            self::SCHWIMMEN_DATEI_READ, self::SCHWIMMEN_DATEI_UPLOAD,
            self::TRAINER_STAMMDATEN_READ, self::TRAINER_STAMMDATEN_UPDATE,
            self::ABRECHNUNG_EIGEN_READ, self::ABRECHNUNG_EIGEN_CREATE, self::ABRECHNUNG_EIGEN_UPDATE,
            self::ABRECHNUNG_EIGEN_DELETE, self::ABRECHNUNG_EIGEN_EINREICHEN,
            self::NACHRICHTEN_READ, self::NACHRICHTEN_SEND, self::NACHRICHTEN_SEND_MANNSCHAFT, self::NACHRICHTEN_ANHANG_UPLOAD,
        ];
    }

    private static function trainer_triathlon_rights() {
        return [
            self::TRIATHLON_GRUPPE_READ,
            self::TRIATHLON_SPORTLER_READ, self::TRIATHLON_SPORTLER_UPDATE,
            self::TRIATHLON_SLOT_READ,
            self::TRIATHLON_ANW_READ, self::TRIATHLON_ANW_CREATE, self::TRIATHLON_ANW_UPDATE, self::TRIATHLON_ANW_AUSFALL,
            self::TRAINER_STAMMDATEN_READ, self::TRAINER_STAMMDATEN_UPDATE,
            self::ABRECHNUNG_EIGEN_READ, self::ABRECHNUNG_EIGEN_CREATE, self::ABRECHNUNG_EIGEN_UPDATE,
            self::ABRECHNUNG_EIGEN_DELETE, self::ABRECHNUNG_EIGEN_EINREICHEN,
            self::NACHRICHTEN_READ, self::NACHRICHTEN_SEND, self::NACHRICHTEN_SEND_MANNSCHAFT, self::NACHRICHTEN_ANHANG_UPLOAD,
        ];
    }

    private static function trainer_fitness_rights() {
        return [
            self::FITNESS_GRUPPE_READ,
            self::FITNESS_SPORTLER_READ, self::FITNESS_SPORTLER_UPDATE,
            self::FITNESS_SLOT_READ,
            self::FITNESS_ANW_READ, self::FITNESS_ANW_CREATE, self::FITNESS_ANW_UPDATE, self::FITNESS_ANW_AUSFALL,
            self::TRAINER_STAMMDATEN_READ, self::TRAINER_STAMMDATEN_UPDATE,
            self::ABRECHNUNG_EIGEN_READ, self::ABRECHNUNG_EIGEN_CREATE, self::ABRECHNUNG_EIGEN_UPDATE,
            self::ABRECHNUNG_EIGEN_DELETE, self::ABRECHNUNG_EIGEN_EINREICHEN,
            self::NACHRICHTEN_READ, self::NACHRICHTEN_SEND, self::NACHRICHTEN_SEND_MANNSCHAFT, self::NACHRICHTEN_ANHANG_UPLOAD,
        ];
    }

    private static function merge( ...$arrays ) {
        return array_values( array_unique( array_merge( ...$arrays ) ) );
    }

    // ─── DB-FUNKTIONEN ───────────────────────────────────────────────────────

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'lsv07i_user_rechte';
    }

    /** Einmalig beim Plugin-Aktivieren / DB-Update aufgerufen. */
    public static function install() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $tbl     = self::table();
        $sql = "CREATE TABLE $tbl (
            user_id    BIGINT UNSIGNED NOT NULL,
            recht      VARCHAR(64)     NOT NULL,
            granted_at DATETIME        DEFAULT CURRENT_TIMESTAMP,
            granted_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (user_id, recht),
            KEY idx_recht (recht)
        ) $charset";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        self::backfill_anhang_upload_recht();
    }

    /**
     * Migration (v7.46.0): NACHRICHTEN_ANHANG_UPLOAD wurde bisher in KEINER
     * Rollen-Vorlage mitvergeben, obwohl NACHRICHTEN_SEND es immer war —
     * dadurch konnte niemand tatsaechlich Dateien im Chat versenden, auch
     * wenn die Nachricht selbst (mit Platzhaltertext) durchging. Wer bereits
     * NACHRICHTEN_SEND hat, bekommt das fehlende Anhang-Recht hier einmalig
     * automatisch nachgetragen — INSERT IGNORE macht das gefahrlos wiederholbar.
     */
    private static function backfill_anhang_upload_recht() {
        global $wpdb;
        $tbl = self::table();
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $tbl (user_id, recht, granted_at, granted_by)
             SELECT user_id, %s, NOW(), NULL
               FROM $tbl
              WHERE recht = %s",
            self::NACHRICHTEN_ANHANG_UPLOAD,
            self::NACHRICHTEN_SEND
        ) );
    }

    // ─── KERN-FUNKTIONEN ─────────────────────────────────────────────────────

    /**
     * Hauptprüfung: hat User $user_id das Recht $recht?
     *
     * Während der Migrationsphase greift ein Fallback: wenn die DB-Tabelle
     * leer für diesen User ist (= noch nie migriert), wird auf die alten
     * Rollen-Checks zurückgegriffen, damit niemand plötzlich ausgesperrt ist.
     * Sobald Iter 4 abgeschlossen ist, wird der Fallback entfernt.
     */
    public static function can( $user_id, $recht ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) return false;

        // WordPress-Admin hat IMMER alle Rechte (Sicherheitsventil gegen Selbst-Aussperrung).
        // Ohne diesen Bypass kann sich ein Admin ohne admin.permissions.update nicht mehr
        // ins Rechte-Tab loggen, um sich Rechte zu vergeben.
        if ( user_can( $user_id, 'administrator' ) ) return true;

        $rights = self::cached_rights( $user_id );
        if ( $rights['has_explicit'] ) {
            return in_array( $recht, $rights['rights'], true );
        }

        // Migration-Fallback: User noch nicht migriert → alte Rollen-Logik
        return self::legacy_can( $user_id, $recht );
    }

    public static function can_current( $recht ) {
        return self::can( get_current_user_id(), $recht );
    }

    /**
     * Alle Benutzer, die ein bestimmtes Recht haben (WordPress-Administratoren
     * plus alle mit dem Recht explizit ausgestatteten Benutzer). Für Rechte,
     * die — wie SCHWIMMEN_WETTKAMPF_APPROVE — bewusst in keinem Standard-
     * Template stecken, ist das vollständig; ein Legacy-Rollen-Fallback über
     * Templates wird hier NICHT mitgeprüft, weil das nur für Rechte relevant
     * wäre, die in einem Template stehen.
     *
     * @return WP_User[]
     */
    public static function users_mit_recht( $recht ) {
        $ids = [];
        foreach ( get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] ) as $uid ) {
            $ids[ (int) $uid ] = true;
        }
        global $wpdb;
        $tbl  = self::table();
        $expl = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM $tbl WHERE recht = %s", $recht ) );
        foreach ( (array) $expl as $uid ) $ids[ (int) $uid ] = true;

        $users = [];
        foreach ( array_keys( $ids ) as $uid ) {
            $u = get_user_by( 'id', $uid );
            if ( $u ) $users[] = $u;
        }
        return $users;
    }

    /**
     * Prüft, ob der User MINDESTENS EIN Recht mit dem angegebenen Präfix hat.
     * Bsp.: has_any_in_bereich( 5, 'schwimmen.' ) → true, wenn er irgendein
     * schwimmen.*-Recht hat. Dient der Access-Klasse für is_intern/is_tri_intern etc.
     */
    public static function has_any_in_bereich( $user_id, $prefix ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) return false;
        if ( user_can( $user_id, 'administrator' ) ) return true;

        $rights = self::cached_rights( $user_id );
        if ( $rights['has_explicit'] ) {
            foreach ( $rights['rights'] as $r ) {
                if ( strpos( $r, $prefix ) === 0 ) return true;
            }
            return false;
        }

        // Migration-Fallback: lade Template-Rechte aus der WP-Rolle und prüfe Präfix
        $u = get_user_by( 'id', $user_id );
        if ( ! $u ) return false;
        $roles = (array) $u->roles;
        $role_to_template = self::legacy_role_map();
        $tpls = self::all_templates();
        foreach ( $roles as $role ) {
            $tid = $role_to_template[ $role ] ?? null;
            if ( $tid && isset( $tpls[ $tid ] ) ) {
                foreach ( $tpls[ $tid ]['rights'] as $r ) {
                    if ( strpos( $r, $prefix ) === 0 ) return true;
                }
            }
        }
        return false;
    }

    /**
     * Zentraler Cache-Zugriff. Ein static-Array pro Request.
     * Wird von set_user_rights/grant/revoke gezielt invalidiert.
     */
    private static $rights_cache = [];

    private static function cached_rights( $user_id ) {
        if ( ! isset( self::$rights_cache[ $user_id ] ) ) {
            self::$rights_cache[ $user_id ] = self::load_user_rights( $user_id );
        }
        return self::$rights_cache[ $user_id ];
    }

    /** Cache für einen User invalidieren (z. B. nach grant/revoke/set_user_rights). */
    public static function reset_cache( $user_id = null ) {
        if ( $user_id === null ) {
            self::$rights_cache = [];
        } else {
            unset( self::$rights_cache[ (int) $user_id ] );
        }
    }

    /** Wird vom legacy_can und has_any_in_bereich verwendet. */
    private static function legacy_role_map() {
        return [
            'lsv07_admin'         => 'admin_full',
            'lsv07_schwimmwart'   => 'schwimmwart',
            'lsv07_triathlonwart' => 'triathlonwart',
            'lsv07_fitnesswart'   => 'fitnesswart',
            'lsv07_kassenwart'    => 'kassenwart',
            'lsv07_finanzwart'    => 'kassenwart',
            'lsv07_trainer'       => 'trainer_schwimmen',
            'lsv07_intern'        => 'mitglied_schwimmen',
            'lsv07_tri_intern'    => 'mitglied_triathlon',
            'lsv07_fit_intern'    => 'mitglied_fitness',
        ];
    }

    /**
     * Lädt alle Rechte eines Users aus der DB. Liefert ein Array
     * ['has_explicit' => bool, 'rights' => [...]] zurück.
     *
     * has_explicit ist true wenn entweder Rechte gesetzt sind ODER der User
     * über die UI bewusst auf 0 Rechte gesetzt wurde (Usermeta-Flag). Ohne
     * dieses Flag würde das System auf den legacy_can-Fallback zurückfallen,
     * was den Admin-Wunsch "keine Rechte" konterkarieren würde.
     */
    private static function load_user_rights( $user_id ) {
        global $wpdb;
        $tbl = self::table();
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT recht FROM $tbl WHERE user_id=%d", $user_id ) );
        $rows = is_array( $rows ) ? $rows : [];
        $explicit_flag = get_user_meta( $user_id, 'lsv07i_rechte_explicit', true );
        return [
            'has_explicit' => count( $rows ) > 0 || $explicit_flag === '1',
            'rights'       => $rows,
        ];
    }

    /**
     * User ein einzelnes Recht zuweisen.
     */
    public static function grant( $user_id, $recht, $granted_by = null ) {
        global $wpdb;
        if ( ! in_array( $recht, self::all_codes(), true ) ) return false;
        $wpdb->replace(
            self::table(),
            [
                'user_id'    => (int) $user_id,
                'recht'      => $recht,
                'granted_at' => current_time( 'mysql' ),
                'granted_by' => $granted_by ? (int) $granted_by : null,
            ],
            [ '%d', '%s', '%s', '%d' ]
        );
        self::reset_cache( $user_id );
        return true;
    }

    /**
     * User ein einzelnes Recht entziehen.
     */
    public static function revoke( $user_id, $recht ) {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'user_id' => (int) $user_id, 'recht' => $recht ], [ '%d', '%s' ] );
        self::reset_cache( $user_id );
        return true;
    }

    /**
     * Setzt die Rechte eines Users auf eine genaue Liste — alles andere wird gelöscht.
     */
    public static function set_user_rights( $user_id, array $rechte, $granted_by = null ) {
        global $wpdb;
        $valid = self::all_codes();
        $rechte = array_values( array_unique( array_intersect( $rechte, $valid ) ) );
        $tbl = self::table();
        $wpdb->delete( $tbl, [ 'user_id' => (int) $user_id ], [ '%d' ] );
        foreach ( $rechte as $r ) {
            self::grant( $user_id, $r, $granted_by );
        }
        // Markiere User als "bewusst gesetzt" — auch wenn Liste leer ist.
        // Sonst würde load_user_rights zum legacy_can-Fallback zurückkehren
        // und die alten WP-Rollen wieder greifen lassen.
        update_user_meta( (int) $user_id, 'lsv07i_rechte_explicit', '1' );
        self::reset_cache( $user_id );
        return true;
    }

    /**
     * Wendet ein Template auf einen User an. Bestehende Rechte werden ersetzt.
     */
    public static function apply_template( $user_id, $template_id, $granted_by = null ) {
        $tpls = self::all_templates();
        if ( ! isset( $tpls[ $template_id ] ) ) return false;
        return self::set_user_rights( $user_id, $tpls[ $template_id ]['rights'], $granted_by );
    }

    // ─── CUSTOM-TEMPLATES (vom User anlegbar) ────────────────────────────────

    /**
     * Custom-Template erstellen oder aktualisieren.
     * @param int|null $id  null = neu anlegen, sonst ID des bestehenden
     * @param string $name
     * @param string $beschreibung
     * @param array  $rights  Liste valider Recht-Codes
     * @return int|WP_Error  ID bei Erfolg
     */
    public static function save_custom_template( $id, $name, $beschreibung, array $rights ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_rechte_templates';

        // Tabelle anlegen falls noch nicht da (passiert bei frischen Updates,
        // wenn der dbDelta-Hook noch nicht durchgelaufen ist).
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" );
        if ( ! $exists ) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query( "CREATE TABLE IF NOT EXISTS $tbl (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name          VARCHAR(120) NOT NULL,
                beschreibung  VARCHAR(500) DEFAULT NULL,
                rights_json   LONGTEXT NOT NULL,
                erstellt_von  BIGINT UNSIGNED NOT NULL DEFAULT 0,
                erstellt_am   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_am  DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_name (name)
            ) $charset" );
        }

        $name = trim( wp_strip_all_tags( $name ) );
        if ( $name === '' ) {
            return new WP_Error( 'invalid_name', 'Name darf nicht leer sein.' );
        }
        if ( mb_strlen( $name ) > 120 ) {
            $name = mb_substr( $name, 0, 120 );
        }
        $beschreibung = trim( wp_strip_all_tags( (string) $beschreibung ) );
        if ( mb_strlen( $beschreibung ) > 500 ) {
            $beschreibung = mb_substr( $beschreibung, 0, 500 );
        }

        // Nur gültige Codes durchlassen
        $valid  = self::all_codes();
        $rights = array_values( array_unique( array_intersect( $rights, $valid ) ) );

        // Name-Konflikt mit hartcodierten Templates verhindern
        $built_in = self::built_in_templates();
        foreach ( $built_in as $bid => $bt ) {
            if ( strcasecmp( $bt['name'], $name ) === 0 ) {
                return new WP_Error( 'name_conflict', 'Dieser Name ist von einem System-Template belegt.' );
            }
        }

        $now = current_time( 'mysql' );
        $data = [
            'name'         => $name,
            'beschreibung' => $beschreibung !== '' ? $beschreibung : null,
            'rights_json'  => wp_json_encode( $rights ),
            'geaendert_am' => $now,
        ];
        $fmt = [ '%s', '%s', '%s', '%s' ];

        if ( $id ) {
            // Update
            $result = $wpdb->update( $tbl, $data, [ 'id' => (int) $id ], $fmt, [ '%d' ] );
            if ( $result === false ) {
                error_log( 'LSV07I permissions DB: ' . $wpdb->last_error );
                return new WP_Error( 'db_error', 'Speichern fehlgeschlagen.' );
            }
            return (int) $id;
        }

        // Insert
        $data['erstellt_von'] = get_current_user_id();
        $data['erstellt_am']  = $now;
        $fmt[] = '%d';
        $fmt[] = '%s';

        $result = $wpdb->insert( $tbl, $data, $fmt );
        if ( $result === false ) {
            error_log( 'LSV07I permissions DB: ' . $wpdb->last_error );
                return new WP_Error( 'db_error', 'Speichern fehlgeschlagen.' );
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Custom-Template löschen. Built-in-Templates können nicht gelöscht werden.
     */
    public static function delete_custom_template( $id ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_rechte_templates';
        $result = $wpdb->delete( $tbl, [ 'id' => (int) $id ], [ '%d' ] );
        return $result !== false;
    }

    // ─── LEGACY-FALLBACK ─────────────────────────────────────────────────────

    /**
     * Rückwärtskompatibilität: bildet die alten WordPress-Rollen auf die
     * neuen Rechte ab. Wird nur aufgerufen, wenn ein User noch keine
     * expliziten Rechte in der DB hat.
     *
     * Sobald die Migration für alle User durchgelaufen ist, wird diese
     * Funktion irrelevant. Sie bleibt aber als Notnagel bestehen.
     */
    private static function legacy_can( $user_id, $recht ) {
        // Admins dürfen alles
        if ( user_can( $user_id, 'administrator' ) ) return true;

        $u = get_user_by( 'id', $user_id );
        if ( ! $u ) return false;
        $roles = (array) $u->roles;

        $role_to_template = self::legacy_role_map();
        $tpls = self::all_templates();
        foreach ( $roles as $role ) {
            if ( isset( $role_to_template[ $role ] ) ) {
                $tid = $role_to_template[ $role ];
                if ( isset( $tpls[ $tid ] ) && in_array( $recht, $tpls[ $tid ]['rights'], true ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    // ─── MIGRATION VOM ALTEN SYSTEM ─────────────────────────────────────────

    /**
     * Migriert alle WP-User mit LSV07-Rollen einmalig auf das neue System.
     * Wird einmalig vom Admin angestoßen oder beim Plugin-Update.
     * Idempotent — kann mehrfach laufen.
     *
     * Strategie: für jeden User mit einer LSV07-Rolle, der noch keine
     * expliziten Rechte hat, das passende Template einsetzen.
     */
    public static function migrate_from_legacy() {
        global $wpdb;
        $tbl = self::table();

        // Alle WP-User holen (in größeren Installationen müsste man paginieren)
        $users = get_users( [ 'fields' => [ 'ID' ] ] );

        $role_to_template = [
            'administrator'       => 'admin_full',
            'lsv07_admin'         => 'admin_full',
            'lsv07_schwimmwart'   => 'schwimmwart',
            'lsv07_triathlonwart' => 'triathlonwart',
            'lsv07_fitnesswart'   => 'fitnesswart',
            'lsv07_kassenwart'    => 'kassenwart',
            'lsv07_finanzwart'    => 'kassenwart',
            'lsv07_trainer'       => 'trainer_schwimmen',
            'lsv07_intern'        => 'mitglied_schwimmen',
            'lsv07_tri_intern'    => 'mitglied_triathlon',
            'lsv07_fit_intern'    => 'mitglied_fitness',
        ];

        $migrated = 0;
        foreach ( $users as $u ) {
            $uid = (int) $u->ID;

            // Hat der User schon explizite Rechte? Dann skip.
            $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tbl WHERE user_id=%d", $uid ) );
            if ( $existing > 0 ) continue;

            $user = get_user_by( 'id', $uid );
            if ( ! $user ) continue;
            $roles = (array) $user->roles;

            // Kein passendes Template? Nichts zu migrieren.
            $rechte = [];
            $tpls = self::all_templates();
            foreach ( $roles as $role ) {
                if ( isset( $role_to_template[ $role ] ) ) {
                    $tid = $role_to_template[ $role ];
                    if ( isset( $tpls[ $tid ] ) ) {
                        $rechte = array_merge( $rechte, $tpls[ $tid ]['rights'] );
                    }
                }
            }
            if ( ! $rechte ) continue;

            self::set_user_rights( $uid, $rechte, 0 );
            // set_user_rights setzt das Flag bereits, aber zur Sicherheit nochmal:
            update_user_meta( $uid, 'lsv07i_rechte_explicit', '1' );
            $migrated++;
        }

        update_option( 'lsv07i_permissions_migrated', current_time( 'mysql' ) );
        return $migrated;
    }
}
