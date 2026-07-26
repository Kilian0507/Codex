# Mitgliederverwaltung Schwimmverein (WordPress-Plugin)

WordPress-Plugin zur Mitgliederverwaltung eines Schwimmvereins: Sparten, Beitragslogik,
Rollen-/Rechtekonzept, SEPA-Zahlungsverkehr und durchgängige Export-/Importfunktionen.

Leitprinzip: **alles ist zur Laufzeit konfigurierbar.** Sparten, Stammdatenfelder,
Mitgliedsstatus, Beitragsarten, Rollen und Rechte, Zahlarten, Zahldatei-Formate,
Nachrichtenkategorien und Exportlisten werden im System selbst angelegt und geändert —
ohne Code-Anpassung oder Plugin-Update.

## Inhalt

| Pfad | Inhalt |
|---|---|
| [`schwimmverein-mitgliederverwaltung/`](schwimmverein-mitgliederverwaltung/) | Das Plugin — Installation und Aufbau siehe dessen [README](schwimmverein-mitgliederverwaltung/README.md) |
| [`docs/konzept-mitgliederverwaltung-plugin.md`](docs/konzept-mitgliederverwaltung-plugin.md) | Fachliches und technisches Konzept (Datenmodell, Rollen, Beitragsregeln, SEPA, DSGVO) |

## Schnellstart

Ordner `schwimmverein-mitgliederverwaltung/` nach `wp-content/plugins/` kopieren und in
WordPress aktivieren. Beim Aktivieren werden die Tabellen angelegt und eine vollständige
Startkonfiguration eingerichtet, die anschließend frei änderbar ist.
