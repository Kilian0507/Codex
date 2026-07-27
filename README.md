# Mitgliederverwaltung Schwimmverein (WordPress-Plugin)

WordPress-Plugin zur Mitgliederverwaltung eines Schwimmvereins: Sparten, Familien,
Beitragslogik, Rollen-/Rechtekonzept, SEPA-Zahlungsverkehr und durchgängige
Export-/Importfunktionen.

Zwei Leitgedanken:

- **Die Verwaltung läuft im Frontend.** Alles liegt auf einer normalen WordPress-Seite mit
  dem Shortcode `[svm_app]`; im Adminbereich steht nur noch ein Verweis dorthin.
- **Alles ist zur Laufzeit konfigurierbar.** Sparten, Stammdatenfelder, Mitgliedsstatus,
  Beitragsarten, Rollen und Rechte, Zahlarten, Zahldatei-Formate, Nachrichtenkategorien und
  Exportvorlagen werden im System selbst angelegt — ohne Code-Anpassung.

## Inhalt

| Pfad | Inhalt |
|---|---|
| [`schwimmverein-mitgliederverwaltung/`](schwimmverein-mitgliederverwaltung/) | Das Plugin — Installation und Aufbau siehe dessen [README](schwimmverein-mitgliederverwaltung/README.md) |
| [`docs/konzept-mitgliederverwaltung-plugin.md`](docs/konzept-mitgliederverwaltung-plugin.md) | Fachliches und technisches Konzept (Datenmodell, Rollen, Beitragsregeln, SEPA, DSGVO) |
| [`docs/einrichtungsanleitung.pdf`](docs/einrichtungsanleitung.pdf) | Ersteinrichtung in zwölf Schritten |
| [`docs/testlauf-kurzanleitung.pdf`](docs/testlauf-kurzanleitung.pdf) | Probedurchlauf: Beitragsart anlegen, berechnen, SEPA-Datei erzeugen |
| [`docs/testdaten-mitglieder.csv`](docs/testdaten-mitglieder.csv) | Fünf Testmitglieder und ein Testbankprofil |

## Schnellstart

1. Ordner `schwimmverein-mitgliederverwaltung/` nach `wp-content/plugins/` kopieren und
   aktivieren — dabei entstehen die Tabellen und eine vollständige Startkonfiguration.
2. Eine WordPress-Seite anlegen und dort den Shortcode `[svm_app]` einfügen.
3. Die Seite aufrufen — die gesamte Verwaltung läuft dort.

Eine bebilderte Schritt-für-Schritt-Anleitung liegt als
[PDF](docs/einrichtungsanleitung.pdf) bei. Wer das System zuerst gefahrlos ausprobieren
möchte, folgt der [Kurzanleitung für den Testlauf](docs/testlauf-kurzanleitung.pdf) — sie
führt mit den beiliegenden Testdaten von der Beitragsart bis zur fertigen SEPA-Datei und
wieder zurück.
