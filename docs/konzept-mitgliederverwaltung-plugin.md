# Konzept: WordPress-Plugin „Mitgliederverwaltung Schwimmverein“

Status: Planungsdokument (kein Code) — Entscheidungsgrundlage vor Umsetzungsbeginn
Zielplattform: WordPress-Plugin (eigenständig, kein Fremd-Plugin als Basis)

## 1. Ausgangslage & Ziele

Der Verein besteht aus **3 Sparten** (z. B. Schwimmen, Wasserball, Freizeit-/Breitensport —
Namen frei konfigurierbar, siehe 3.1). Mitglieder zahlen unterschiedliche **Beiträge** je nach
Sparte, Alter und Status. Das Plugin soll:

1. Mitglieder- und Stammdatenverwaltung für alle 3 Sparten in einem System bündeln.
2. Unterschiedliche Beitragsarten/-höhen abbilden und automatisch Forderungen erzeugen.
3. Zahlungsverkehr abbilden: SEPA-Lastschrift-Einzug **und** manuelle Überweisung mit
   Erfassung, wer bezahlt hat.
4. Ein differenziertes Rollen-/Rechtekonzept bieten — inkl. einer Mitglieder-Rolle, die
   Nachrichten einsehen und eigene Stammdaten pflegen kann, aber sonst nichts sieht.
5. Sämtliche Datenbestände exportierbar machen (Mitgliederlisten, Beitragslisten,
   Zahlungsübersichten, SEPA-XML, DSGVO-Auskunft).

Bewusst **nicht** Ziel dieses Dokuments: Code. Es ist die fachliche und technische Grundlage,
auf der im nächsten Schritt implementiert werden kann.

## 2. Funktionsüberblick

| Modul | Kernfunktion |
|---|---|
| Sparten & Mitgliedschaften | 3 Sparten, Mitglied kann in mehreren Sparten aktiv sein |
| Stammdatenverwaltung | Adresse, Kontakt, Geburtsdatum, Bankverbindung, Angehörige/Familie |
| Beitragsverwaltung | Beitragsarten je Sparte/Alter/Status, automatische Forderungserzeugung |
| Zahlungsverkehr | SEPA-Lastschrift-Export (XML), manuelle Überweisung + Zahlungserfassung |
| Rollen & Rechte | Vereinsadmin, Kassenwart, Sparten-Leiter, Übungsleiter, Vorstand, Mitglied |
| Mitglieder-Self-Service | Eigene Stammdaten ändern, Nachrichten/Mitteilungen lesen, Beitrags-/Zahlungshistorie einsehen |
| Kommunikation | Rollen-/sparten-gesteuerte Mitteilungen (Pinnwand + optional E-Mail) |
| Export | CSV/Excel/PDF für alle Listen, SEPA-XML, DSGVO-Einzelauskunft |
| Protokollierung | Audit-Log für alle Änderungen an Finanz- und Stammdaten |

## 3. Fachliches Datenmodell

### 3.1 Sparten (Departments)

- Stammdaten: Name, Kurzbezeichnung, Sparten-Leiter (Verweis auf Nutzer), aktiv/inaktiv.
- 3 Sparten sind der Ausgangszustand, die Anzahl soll aber **konfigurierbar** bleiben
  (keine Hart-Codierung auf „genau 3“), damit der Verein später erweitern kann.
- Ein Mitglied kann **mehreren Sparten** zugeordnet sein (z. B. Schwimmen + Wasserball),
  jede Zuordnung hat einen eigenen Beitrag.

### 3.2 Mitglieder (Members)

Kern-Stammdaten je Mitglied:

- Name, Geburtsdatum, Geschlecht, Adresse, Telefon, E-Mail
- Mitgliedsnummer (fortlaufend, sprechend z. B. `SV-2026-00042`)
- Status: aktiv, passiv, ruhend, Ehrenmitglied, ausgetreten (mit Austrittsdatum)
- Familienverbund (für Familienbeiträge/-rabatte): Verknüpfung zu einem „Familienkonto“
- Erziehungsberechtigte/Kontaktperson bei Minderjährigen
- Bankverbindung (IBAN/BIC) + SEPA-Mandat (siehe 5.1) — getrennt von den übrigen
  Stammdaten gespeichert und mit engeren Zugriffsrechten versehen
- Verknüpfter WordPress-Benutzer-Account (1:1), für Login/Self-Service
- Freitextfeld/Dokumente: ärztliches Attest, Einverständniserklärungen (Foto/Video), sofern
  der Verein das nutzt — DSGVO-relevant, siehe 8.

### 3.3 Beitragsarten (Fee Types)

Beiträge sind **nicht** pauschal, sondern kombinierbar:

- Beitragsart je **Sparte** (z. B. Schwimmen Erwachsene, Schwimmen Jugend, Wasserball, …)
- Staffelung nach Alter (Kind/Jugend/Erwachsen/Senior) und Status (aktiv/passiv/Ehrenmitglied
  = 0 €)
- Familienrabatt (z. B. ab 3. Kind beitragsfrei) — als Regel auf Familienverbund-Ebene
- Zahlweise/Turnus: jährlich, halbjährlich, quartalsweise, monatlich
- Zusatzbeiträge: Aufnahmegebühr (einmalig), Trikot-/Materialpauschale, Wettkampfpauschale
- Gültigkeitszeitraum je Beitragsart (für Beitragsänderungen zum Geschäftsjahreswechsel)

Aus Mitgliedschaft + Beitragsart erzeugt das System pro Fälligkeit eine **Forderung**
(offener Posten je Mitglied, Zeitraum, Betrag, Status offen/teilbezahlt/bezahlt/storniert).
Das ist die zentrale Verknüpfung zwischen Mitgliederverwaltung und Zahlungsverkehr.

## 4. Rollen- und Rechtekonzept

Die WordPress-eigenen Rollen (Administrator, Redakteur, …) werden **nicht** für die Vereins­
logik wiederverwendet, sondern es werden eigene Capabilities registriert, damit ein
„technischer“ Server-Administrator nicht automatisch Finanzdaten sehen muss und umgekehrt.

| Rolle | Sieht/Darf |
|---|---|
| **Vereinsverwaltung (Admin)** | Alles: Mitglieder aller Sparten, Beiträge, Zahlungen, SEPA-Export, Rollen vergeben, Nachrichten an alle |
| **Kassenwart/Finanzen** | Beitragsarten, Forderungen, Zahlungen (SEPA + manuell), SEPA-XML-Export, Finanz-Exporte; keine Rechteverwaltung |
| **Sparten-Leiter** | Nur Mitglieder der eigenen Sparte(n) (Stammdaten lesen/pflegen), Nachrichten an eigene Sparte, keine Finanzdaten anderer Sparten |
| **Übungsleiter/Trainer** | Kontaktdaten & Anwesenheitsliste der eigenen Gruppe, keine Bankdaten, keine Finanzansicht |
| **Vorstand** | Lesender Zugriff auf alle Berichte/Exporte (Mitglieder, Finanzen als Summen), keine Bearbeitung |
| **Mitglied (Self-Service)** | Eigene Stammdaten einsehen/ändern (mit optional Freigabe-Workflow für sensible Felder), eigene Beitrags-/Zahlungshistorie, Nachrichten/Mitteilungen lesen — sonst nichts |

Technisch: eigene Capabilities (`svm_view_members`, `svm_edit_members_own_department`,
`svm_manage_fees`, `svm_manage_payments`, `svm_export_sepa`, `svm_manage_own_profile`, …) statt
grober Rollen, plus **Row-Level-Filter** über ein Nutzer-Meta-Feld `svm_department_id`, damit
z. B. „Sparten-Leiter“ nur eine Rolle ist, aber pro Person auf ihre Sparte eingeschränkt wird.

Änderungen an sensiblen Stammdaten durch das Mitglied selbst (IBAN, Name, Geburtsdatum)
sollten optional einen **Freigabe-Workflow** durchlaufen (Änderung wird vorgeschlagen, ein
Admin/Kassenwart bestätigt), damit z. B. nicht unbemerkt eine falsche IBAN für den nächsten
SEPA-Lauf hinterlegt wird. Unkritische Felder (Telefon, E-Mail, Adresse) können direkt
übernommen werden.

## 5. Zahlungsverkehr

Zwei parallele, gleichberechtigte Zahlwege pro Mitglied/Forderung:

### 5.1 SEPA-Lastschrift (automatischer Einzug)

Das Vereins-übliche Verfahren: der Verein zieht die Beiträge selbst per **SEPA-
Basislastschrift** von den Mitgliedskonten ein (technisch pain.008.001.02-XML). Dafür
nötig:

- Gläubiger-ID des Vereins (einmalig hinterlegt)
- Je Mitglied: SEPA-Mandat (IBAN, BIC optional, Mandatsreferenz, Datum der Unterschrift,
  Sequenztyp FRST/RCUR/OOFF/FNAL)
- Export erzeugt eine **pain.008.001.02-XML-Datei** für alle offenen Forderungen mit
  gültigem Mandat, mit korrekten Vorlauffristen (Erst-Einzug 5, Folge-Einzug 2 Banktage)
- Nach Erzeugung wird der Lauf protokolliert (welche Forderungen enthalten waren), damit
  keine Forderung doppelt eingezogen wird; Rückläufer/Rücklastschriften können danach manuell
  als „fehlgeschlagen“ markiert werden (Forderung wird wieder offen)

> **Begriffsklärung:** Umgangssprachlich wird das oft „SEPA-Überweisungsdatei“ genannt, es
> handelt sich aber technisch um eine **Lastschrift** (der Verein zieht ein, statt dass das
> Mitglied überweist). Optional lässt sich zusätzlich ein **pain.001-Export** (echte SEPA-
> Überweisung) ergänzen, falls der Verein selbst Zahlungen auslösen muss (z. B.
> Übungsleiter-Aufwandsentschädigung, Rückerstattungen) — als spätere Ausbaustufe.

### 5.2 Manuelle Überweisung

Für Mitglieder ohne SEPA-Mandat (oder wenn der Verein grundsätzlich auf Überweisung statt
Einzug setzt):

- Mitglied überweist selbstständig auf das Vereinskonto (Verwendungszweck z. B.
  Mitgliedsnummer)
- Ein Berechtigter (Kassenwart/Admin) trägt den Zahlungseingang manuell ein und ordnet ihn
  einer offenen Forderung zu
- Dabei wird **erfasst, wer überwiesen hat** (Zahlername, ggf. abweichend vom Mitglied —
  z. B. Elternteil zahlt für Kind), Betrag, Datum, Referenz/Verwendungszweck, optionale Notiz
- Bei Teilzahlungen: Forderung bleibt „teilbezahlt“, Restbetrag weiter offen
- Optionaler CSV-Import des Bankkontoauszugs zur schnelleren Zuordnung (spätere Ausbaustufe)

Jede Forderung zeigt am Ende: Zahlweg (Lastschrift/Überweisung), Status, wer gezahlt hat,
wann, mit welchem Beleg-/Buchungstext — vollständig nachvollziehbar für die
Kassenprüfung.

## 6. Kommunikation/Nachrichten

- Einfaches Mitteilungsmodul (Titel, Text, Gültigkeitszeitraum, Anhang optional)
- Sichtbarkeit steuerbar: an alle, an eine Sparte, an eine Rolle (z. B. nur Übungsleiter)
- Mitglieder sehen im Self-Service-Bereich nur die für sie freigegebenen Mitteilungen
- Optionaler E-Mail-Versand bei Veröffentlichung (WP-Cron-Batch, um Massenversand nicht
  blockierend im Request auszulösen)

## 7. Exportfunktionen

Durchgängiges Prinzip: **jede Liste im Adminbereich ist exportierbar.**

| Export | Format | Inhalt |
|---|---|---|
| Mitgliederliste | CSV, Excel (xlsx) | frei wählbare Spalten, filterbar nach Sparte/Status |
| Beitragsübersicht | CSV, Excel, PDF | offene/bezahlte Forderungen je Zeitraum |
| Zahlungsjournal | CSV, Excel | alle Zahlungen mit Zahlweg, Zahler, Datum, Zuordnung |
| SEPA-Lastschrift | XML (pain.008.001.02) | für den nächsten Bankeinzug |
| SEPA-Überweisung (optional) | XML (pain.001.001.03) | Auszahlungen des Vereins |
| Kassenbericht | PDF | Zusammenfassung für Mitgliederversammlung |
| DSGVO-Auskunft | PDF/CSV | alle gespeicherten Daten zu genau einem Mitglied |

Technisch: PhpSpreadsheet (Excel), Dompdf/mPDF (PDF), eine dedizierte SEPA-XML-Bibliothek
(z. B. `digitick/sepa-xml`) — alles per Composer eingebunden und im Plugin vendored/gebaut,
da WordPress selbst keinen Composer-Autoload mitbringt.

## 8. Datenschutz (DSGVO)

Besonders sensibel: Bankdaten (IBAN), Geburtsdatum Minderjähriger, ggf. Gesundheitsdaten
(ärztliche Atteste). Notwendig:

- Verarbeitungsverzeichnis-taugliche Datenfelder (keine „versteckten“ Zusatzfelder ohne Zweck)
- Zugriffsbeschränkung auf Bankdaten strikt auf Kassenwart/Admin (nicht Sparten-Leiter/Trainer)
- Aufbewahrungsfristen: Finanzunterlagen i. d. R. 10 Jahre (§ 147 AO), Stammdaten Austritt +
  Frist, danach Löschkonzept/Anonymisierung
- Auskunfts- und Löschrecht: Export „DSGVO-Auskunft“ (s. o.) sowie eine Lösch-/Anonymisierungs-
  Funktion für ausgetretene Mitglieder nach Fristablauf
- Audit-Log für Zugriffe/Änderungen an Finanz- und Gesundheitsdaten

## 9. Technische Architektur (WordPress-Plugin)

### 9.1 Plugin-Struktur (Vorschlag)

```
schwimmverein-mitgliederverwaltung/
├── schwimmverein-mitgliederverwaltung.php   # Plugin-Bootstrap, Header, Aktivierungs-Hooks
├── includes/
│   ├── class-activator.php                  # dbDelta-Tabellenerstellung, Rollen anlegen
│   ├── class-roles-capabilities.php
│   ├── models/                              # Datenzugriff je Entität
│   ├── services/                            # Beitragslogik, SEPA-Generator, Export-Service
│   └── rest/                                # REST-API-Endpunkte für Self-Service/Frontend
├── admin/                                    # Adminoberfläche (WP_List_Table je Liste, Menüs)
├── public/                                   # Shortcode/Block für Mitglieder-Self-Service
├── assets/                                   # CSS/JS
├── vendor/                                    # Composer-Abhängigkeiten (SEPA-XML, PhpSpreadsheet, Dompdf)
└── languages/                                 # i18n (de_DE als Hauptsprache)
```

### 9.2 Datenhaltung

Eigene Tabellen statt Custom Post Types (Post-Meta ist für relationale, finanzkritische
Daten mit vielen Filtern/Reports ungeeignet). Kern-Tabellen (Präfix `wp_svm_`):

`departments`, `members`, `member_department` (n:m + Beitrag), `family_groups`,
`fee_types`, `invoices` (Forderungen), `payments`, `sepa_mandates`, `sepa_export_runs`,
`messages`, `message_visibility`, `audit_log`.

Anlage über `dbDelta()` im Aktivierungshook, Versionierung der Tabellen für spätere
Plugin-Updates (Migrationsroutine).

### 9.3 Adminoberfläche & Self-Service

- Adminbereich: eigene Menüpunkte je Modul, Listen als `WP_List_Table` (Sortierung,
  Filter, Bulk-Export)
- Mitglieder-Self-Service: Shortcode/Gutenberg-Block, eingebettet auf einer normalen
  WordPress-Seite, rendert je nach eingeloggtem Nutzer nur eigene Daten; Formular-Handling
  über REST-API-Endpunkte mit Nonce- und Capability-Prüfung
- Kein eigenes Frontend-Framework nötig für Phase 1 (Server-seitig gerendert), React/Vue nur
  falls später ein interaktiveres Dashboard gewünscht wird

### 9.4 Wiederkehrende Aufgaben

WP-Cron für: automatische Forderungserzeugung zum Fälligkeitsdatum, Erinnerungs-E-Mails bei
offenen Forderungen, Massenversand von Nachrichten.

### 9.5 Sicherheit

- Jede Aktion mit `current_user_can()`-Prüfung auf die neuen Capabilities
- Nonces auf allen Formularen/AJAX-/REST-Aufrufen
- IBAN nur maskiert in Listen anzeigen (volle IBAN nur bei expliziter Detailansicht mit
  Berechtigung + Audit-Log-Eintrag)
- Kein Klartext-Export von Bankdaten außer im SEPA-XML selbst (Zugriff auf Export-Funktion
  eng begrenzt)

## 10. Rollen-Rechte-Matrix (Kurzreferenz)

| Funktion | Admin | Kassenwart | Sparten-Leiter | Übungsleiter | Vorstand | Mitglied |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| Mitglieder aller Sparten sehen | ✅ | ✅ | ❌ | ❌ | ✅ (lesend) | ❌ |
| Mitglieder eigener Sparte pflegen | ✅ | ➖ | ✅ | ❌ | ❌ | ❌ |
| Bankdaten/SEPA-Mandate sehen | ✅ | ✅ | ❌ | ❌ | ❌ | eigene |
| Beitragsarten verwalten | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Zahlungen erfassen (manuell) | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| SEPA-XML exportieren | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Berichte/Exporte lesen | ✅ | ✅ | eigene Sparte | eigene Gruppe | ✅ | eigene Daten |
| Nachrichten verfassen | ✅ | ➖ | eigene Sparte | ➖ | ➖ | ❌ |
| Nachrichten lesen | ✅ | ✅ | ✅ | ✅ | ✅ | für sie freigegebene |
| Eigene Stammdaten ändern | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Rollen vergeben | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

## 11. Offene Punkte (Entscheidungen für die Umsetzungsphase)

1. Endgültige Namen/Anzahl der Sparten und deren Beitragsstruktur (Beträge, Staffeln)
2. Primärer Zahlweg: SEPA-Lastschrift als Standard mit Überweisung als Ausnahme, oder
   gleichrangig zur Wahl des Mitglieds?
3. Wird ein pain.001-Überweisungsexport (Vereins-Auszahlungen) tatsächlich benötigt, oder
   reicht die Lastschriftseite?
4. Soll es einen Freigabe-Workflow für Stammdatenänderungen durch Mitglieder geben, oder
   direkte Übernahme?
5. Hosting-Umgebung/PHP-Version des Zielsystems (relevant für Composer-Abhängigkeiten)
6. Gewünschter Umfang der Mahnfunktion (automatisch vs. rein manuell)

## 12. Vorschlag Phasenplan

1. **Phase 1 (MVP):** Sparten, Mitglieder, Beitragsarten, Forderungserzeugung, Rollen/Rechte,
   manuelle Zahlungserfassung, CSV-Export
2. **Phase 2:** SEPA-Mandatsverwaltung + pain.008-Export, Excel/PDF-Export, Mitglieder-Self-
   Service-Portal (Shortcode), Nachrichtenmodul
3. **Phase 3:** Freigabe-Workflow für Stammdatenänderungen, Mahnwesen, DSGVO-Auskunfts-
   Export/Löschkonzept, Audit-Log-Auswertung
4. **Phase 4 (optional):** pain.001-Auszahlungsexport, Kontoauszug-Import zur automatischen
   Zahlungszuordnung, E-Mail-Automationen

---

Nächster Schritt: Rückmeldung zu Abschnitt 11 („Offene Punkte“), danach kann auf Basis
dieses Konzepts die Implementierung (Phase 1) begonnen werden.
