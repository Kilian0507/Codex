# Schwimmverein Mitgliederverwaltung (WordPress-Plugin)

Mitgliederverwaltung für Vereine, bei der **die gesamte Fachlichkeit zur Laufzeit
konfiguriert wird**: Sparten, Stammdatenfelder, Mitgliedsstatus, Beitragsregeln, Rollen und
Rechte, Zahlarten, SEPA-Dateiformate, Nachrichtenkategorien und Exportlisten werden im
System selbst angelegt — ohne Code-Anpassung und ohne Plugin-Update.

Umsetzung des Konzepts in [`../docs/konzept-mitgliederverwaltung-plugin.md`](../docs/konzept-mitgliederverwaltung-plugin.md).

## Installation

1. Ordner `schwimmverein-mitgliederverwaltung/` nach `wp-content/plugins/` kopieren.
2. Plugin in WordPress aktivieren — dabei werden die Tabellen angelegt und eine vollständige
   Startkonfiguration eingerichtet (3 Sparten, Standardfelder, 6 Rollenvorlagen, Beitragsarten,
   Zahlarten, Exportprofile).
3. Unter **Verein → Konfiguration → Einstellungen** den Vereinsnamen setzen.
4. Für das Mitgliederportal eine Seite anlegen und dort den Shortcode `[svm_portal]` einfügen.
5. Für den SEPA-Einzug unter **Zahlungen → Dateiprofile** IBAN und Gläubiger-ID eintragen.

Voraussetzungen: WordPress 6.0+, PHP 7.4+. Keine Composer-Abhängigkeiten — SEPA-XML, CSV und
IBAN-Prüfung sind eigenständig implementiert.

## Aufbau

```
schwimmverein-mitgliederverwaltung.php   Bootstrap
includes/
  core/       Schema, Installer, Felder, Feldtypen, Rechte, IBAN, Audit, Nummernkreise,
              Startkonfiguration, Konfigurationstransfer, Cron
  models/     Mitglieder, Einheiten, Status, Rollen, Beitragsarten, Regeln, Forderungen,
              Zahlungen, Zahlarten, Mandate, Nachrichten, Änderungsanträge, Vorlagen
  engine/     Regel-Engine, Formelparser, Beitragsrechner, Beitragslauf
  payments/   Dateiprofile, SEPA-Generator (pain.008/pain.001/CSV), Zahlläufe, Mahnwesen
  export/     Export-Profile, Export-Motor, Import mit Spalten-Mapping, DSGVO
  admin/      Menü, Router, Formular-Renderer, Bedingungseditor, Seiten
  frontend/   Mitgliederportal (Shortcode)
```

## Kernkonzepte

**Hybrides Datenmodell.** Kernspalten (ID, Mitgliedsnummer, Status, Daten) liegen als echte
Spalten in `wp_svm_members`; alle fachlichen Stammdaten liegen typisiert in
`wp_svm_field_values`. Auch Vorname, Adresse und IBAN sind frei definierte Felder — sie
werden lediglich als Startkonfiguration angelegt. Damit das System weiß, welches Feld wofür
steht, trägt ein Feld optional eine **Systemrolle** (`birthdate`, `email`, `iban` …).

**Eine Regel-Engine für alles.** Dieselbe Bedingungsmechanik (Feldwert, Status, Sparte, Alter,
Anzahl Mitgliedschaften, Familienposition, offene Forderungen) trägt Beitragsbedingungen,
Rabatte, Nachrichten-Zielgruppen und Exportfilter.

**Rechte statt Rollen.** Der Rechtekatalog ist fest, die Rollen sind frei. Zu jeder Rolle
gehört ein Geltungsbereich (alle / eigene Sparten / eigene Gruppe / nur eigener Datensatz),
deshalb genügt *eine* Rolle „Sparten-Leiter“ für beliebig viele Sparten. Ergänzend steuert die
Feldkonfiguration je Rolle Sehen, Ändern und Freigabepflicht — so pflegen Trainer
Kontaktdaten, ohne je Bankdaten zu sehen.

**Zwei Zahlwege.** SEPA-Lastschrift erzeugt eine pain.008-Datei mit Laufprotokoll (keine
Forderung wird doppelt eingezogen); Überweisungen werden manuell erfasst, inklusive
**wer tatsächlich gezahlt hat** — der Zahler kann vom Mitglied abweichen. Teilzahlungen,
Guthaben und Rücklastschriften sind abgebildet.

## Was bewusst fest bleibt

- Forderungen und Zahlläufe werden **storniert, nicht gelöscht** — Voraussetzung für eine
  prüfbare Kasse.
- Die SEPA-XML-Struktur folgt ISO 20022; konfigurierbar sind Auswahl und Parametrierung des
  Profils, nicht der Aufbau der Datei.
- Der Katalog der *möglichen* Rechte ist im Code definiert (erweiterbar über den Filter
  `svm_permission_catalog`).

## Erweiterbarkeit für Entwickler

Filter: `svm_permission_catalog`, `svm_field_types`, `svm_rule_subjects`, `svm_rule_operators`,
`svm_export_columns`, `svm_file_formats`, `svm_status_transition_actions`.
Aktionen: `svm_member_created`, `svm_member_updated`, `svm_member_status_changed`,
`svm_payment_recorded`, `svm_fee_run_committed`, `svm_payment_run_created`,
`svm_change_request_created`.

## Stand der Umsetzung

Umgesetzt: Konfigurationskern (Felder, Feldrechte, Rollen, Struktur, Status, Nummernkreise),
Mitgliederverwaltung, Regel-Engine und Formelparser, Beitragsarten mit Simulation und
Berechnungsprotokoll, Forderungen, manuelle Zahlungserfassung mit Zuordnung, Mandate,
SEPA-Erzeugung (pain.008, pain.001, freies CSV) mit Zahllaufprotokoll und Rücklastschriften,
Mahnstufen, Nachrichten mit Regel-Zielgruppen, Export-Profile (CSV/Excel-CSV/JSON/XML),
CSV-Import mit Spalten-Mapping, DSGVO-Auskunft und Anonymisierung, Änderungsprotokoll,
Freigabe-Workflow, Mitgliederportal, Konfigurationsexport.

Noch offen: echte XLSX- und PDF-Ausgabe (derzeit Excel-kompatibles CSV), Kontoauszug-Import
zur automatischen Zahlungszuordnung, Mandantenfähigkeit.

## Tests

Die WordPress-unabhängige Kernlogik wurde gegen echte Testwerte geprüft: IBAN-Prüfziffern
(ISO 13616, Modulo 97), Formelparser inklusive Fehlerfällen, SEPA-XML-Struktur (pain.008 und
pain.001, Transliteration von Umlauten, getrennte Blöcke je Sequenztyp, Kontrollsummen) sowie
Zeitraumzerlegung und Fälligkeitsregeln der Beitragsberechnung.
