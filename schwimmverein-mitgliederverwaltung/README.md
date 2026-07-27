# Schwimmverein Mitgliederverwaltung (WordPress-Plugin)

Mitgliederverwaltung für Vereine, die **vollständig im Frontend** läuft: Die gesamte
Verwaltung liegt auf einer normalen WordPress-Seite mit dem Shortcode `[svm_app]`. Im
WordPress-Adminbereich steht nur noch ein Verweis dorthin.

Die Fachlichkeit wird zur Laufzeit konfiguriert: Sparten, Stammdatenfelder, Mitgliedsstatus,
Beitragsregeln, Rollen und Rechte, Zahlarten, SEPA-Formate, Nachrichtenkategorien und
Exportvorlagen werden im System selbst angelegt — ohne Code-Anpassung.

Umsetzung des Konzepts in [`../docs/konzept-mitgliederverwaltung-plugin.md`](../docs/konzept-mitgliederverwaltung-plugin.md).

## Installation

1. Ordner `schwimmverein-mitgliederverwaltung/` nach `wp-content/plugins/` kopieren und
   aktivieren. Dabei werden die Tabellen angelegt und eine vollständige Startkonfiguration
   eingerichtet.
2. Eine WordPress-Seite anlegen (z. B. „Vereinsverwaltung“) und dort `[svm_app]` einfügen.
3. Die Seite aufrufen und unter **Einstellungen → Verein** den Vereinsnamen sowie diese Seite
   eintragen.
4. Unter **Zahlungen → Bankdaten des Vereins** IBAN und Gläubiger-ID ergänzen.

Voraussetzungen: WordPress 6.0+, PHP 7.4+. Keine Composer-Abhängigkeiten — SEPA-XML, CSV und
IBAN-Prüfung sind eigenständig implementiert.

## Die Oberfläche

Eine Seite, acht Bereiche. Was jemand sieht, ergibt sich allein aus seinen Rollen — ein
einfaches Mitglied sieht nur „Meine Daten“, die Kasse zusätzlich Beiträge und Zahlungen.

| Bereich | Inhalt |
|---|---|
| Übersicht | Kennzahlen und die nächsten offenen Aufgaben |
| Mitglieder | Liste, Detailansicht mit Reitern, Änderungswünsche |
| Familien | Familienbaum mit Verzweigungen, Personen zuordnen |
| Beiträge | Beitragsarten, Rabatte, Berechnung mit Vorschau, offene Posten |
| Zahlungen | Zahlung eintragen, Journal, SEPA-Lastschrift, Bankdaten, Mahnwesen |
| Nachrichten | Mitteilungen und Kategorien |
| Import & Export | Mitgliederlisten herunterladen und einlesen, Datenschutz, Protokoll |
| Einstellungen | Verein, Felder, Sparten, Status, Rollen, Zahlarten, Nummern, Vorlagen |

Die Oberfläche ist responsiv: Auf schmalen Bildschirmen werden Tabellen zu gestapelten
Blöcken, die Navigation bleibt scrollbar.

## Familien mit Verzweigungen

Eine Familie bündelt mehrere Mitglieder. Über den Elternverweis entsteht ein Baum:
Hauptmitglied → Partner → Kinder, und Kinder können eigene Zweige haben. Daraus ergeben sich
automatisch zwei Kennzahlen für Beitragsregeln:

- **Position in der Familie** — Reihenfolge über alle Personen hinweg
- **Wievieltes Kind** — zählt nur die Kinder, damit „ab dem 3. Kind beitragsfrei“ exakt greift

Familienbezogene Regeln können auf Familienebene wirken, etwa ein Höchstbetrag je Familie,
der anteilig über alle Personen verteilt wird.

## Mehrere Beiträge je Mitglied

Ein Mitglied zahlt so viele Beiträge, wie auf es zutreffen — etwa je Sparte einen. Beiträge
ergeben sich aus dem Regelwerk oder werden im Reiter **Beiträge** eines Mitglieds direkt
zugeordnet, wahlweise mit abweichendem Betrag. Umgekehrt lässt sich ein Beitrag für ein
einzelnes Mitglied ausnehmen, obwohl die Regel greifen würde.

## Import und Export

**Export:** frei definierbare Vorlagen (Inhalt, Spalten inkl. eigener Felder, Format, wer sie
ausführen darf). Mitgliederlisten enthalten auf Wunsch Familie, Rolle in der Familie und die
zugeordneten Beiträge. Formate: CSV, Excel-kompatibles CSV, JSON, XML.

**Import:** CSV in drei Schritten — Datei hochladen, Spalten den eigenen Feldern zuordnen,
Testlauf. Der Testlauf speichert nichts und meldet Fehler zeilenweise. Importiert werden
können auch SEPA-Mandate sowie Familienzugehörigkeit und Rolle; fehlende Familien werden
dabei angelegt.

## Aufbau

```
schwimmverein-mitgliederverwaltung.php   Bootstrap
includes/
  core/       Schema, Installer, Felder, Rechte, IBAN, Audit, Nummernkreise, Router,
              Startkonfiguration, Konfigurationstransfer, Cron
  models/     Mitglieder, Familien, Einheiten, Status, Rollen, Beitragsarten, Regeln,
              Forderungen, Zahlungen, Zahlarten, Mandate, Nachrichten, Änderungsanträge,
              Beitragszuordnung je Mitglied, Vorlagen
  engine/     Regel-Engine, Formelparser, Beitragsrechner, Beitragslauf
  payments/   Bankprofile, SEPA-Generator (pain.008/pain.001/CSV), Zahlläufe, Mahnwesen
  export/     Exportvorlagen, Export-Motor, Import mit Spaltenzuordnung, DSGVO
  frontend/   Anwendung, UI-Bausteine, views/ mit allen Ansichten
  admin/      Einstiegsseite im WordPress-Adminbereich
```

Alle Formulare laufen über `admin-post.php` in einen zentralen Router, der Nonce und Recht
prüft und anschließend auf die Verwaltungsseite zurückführt.

## Kernkonzepte

**Hybrides Datenmodell.** Kernspalten liegen als echte Spalten in `wp_svm_members`, alle
fachlichen Stammdaten typisiert in `wp_svm_field_values`. Auch Vorname, Adresse und IBAN sind
frei definierte Felder; eine optionale **Systemrolle** sagt dem System, welches Feld für Name,
Alter, E-Mail und SEPA steht.

**Eine Regel-Engine für alles.** Dieselbe Bedingungsmechanik trägt Beitragsbedingungen,
Rabatte, Nachrichten-Zielgruppen und Exportfilter — inklusive Familienbezug.

**Rechte statt Rollen.** Der Rechtekatalog ist fest, die Rollen sind frei. Zu jeder Rolle
gehört ein Geltungsbereich (alle / eigene Sparten / eigene Gruppe / nur eigener Datensatz).
Ergänzend steuert die Feldkonfiguration je Rolle Sehen, Ändern und Freigabepflicht.

**Zwei Zahlwege.** SEPA-Lastschrift erzeugt eine pain.008-Datei mit Laufprotokoll;
Überweisungen werden manuell erfasst, inklusive **wer tatsächlich gezahlt hat**.

## Löschen

Alles, was sich anlegen lässt, lässt sich auch wieder entfernen — Felder, Sparten und deren
Ebenen, Status und Statuswechsel, Rollen, Zahlarten, Nummernkreise, Vorlagen, Beitragsarten,
Rabattregeln, Mahnstufen, Bankprofile, Nachrichten und Kategorien, Exportvorlagen, Mitglieder,
Familien und Familienzugehörigkeiten, Beitragszuordnungen, Mandate, Zahlungen, Forderungen und
ganze Läufe.

Im Finanzbereich gelten dabei Schutzregeln, damit die Kasse prüfbar bleibt. Der Unterschied ist
**stornieren** (der Vorgang bleibt als Beleg stehen) gegenüber **löschen** (er verschwindet):

| Datensatz | Löschbar, solange … | sonst |
|---|---|---|
| Forderung | nichts darauf gezahlt wurde und sie in keinem Zahllauf steckt | stornieren |
| Beitragslauf | keine seiner Forderungen bezahlt ist (löscht sie alle mit) | einzeln stornieren |
| SEPA-Zahllauf | er storniert ist — dann sind die Buchungen zurückgenommen | erst stornieren |
| SEPA-Mandat | damit noch nie eingezogen wurde | widerrufen |
| Zahlung | immer; die zugeordneten Forderungen werden wieder offen | — |
| Mitglied | immer; die Zahlungshistorie bleibt für die Kassenprüfung erhalten | — |

Beim Löschen übergeordneter Datensätze verwaisen die untergeordneten nicht: Untergruppen einer
gelöschten Sparte rücken eine Ebene nach oben, Mitglieder einer aufgelösten Familie bleiben
bestehen, Nachrichten einer gelöschten Kategorie stehen danach ohne Kategorie da. Jede Löschung
landet im Änderungsprotokoll.

## Was bewusst fest bleibt

- Bezahlte Forderungen und ausgeführte Zahlläufe werden **storniert, nicht gelöscht** —
  Voraussetzung für eine prüfbare Kasse.
- Die SEPA-XML-Struktur folgt ISO 20022; konfigurierbar sind Auswahl und Parametrierung.
- Der Katalog der *möglichen* Rechte ist im Code definiert (erweiterbar über
  `svm_permission_catalog`).
- Es muss immer eine Rolle mit dem Recht „Rollen und Rechte verwalten“ bestehen bleiben.

## Erweiterbarkeit

Filter: `svm_permission_catalog`, `svm_field_types`, `svm_rule_subjects`, `svm_rule_operators`,
`svm_export_columns`, `svm_file_formats`, `svm_status_transition_actions`, `svm_family_relations`.
Aktionen: `svm_member_created`, `svm_member_updated`, `svm_member_status_changed`,
`svm_payment_recorded`, `svm_fee_run_committed`, `svm_payment_run_created`,
`svm_change_request_created`.

## Tests

```
bash tests/run.sh
```

Die Prüfungen brauchen weder WordPress noch eine Datenbank:

| Prüfung | Inhalt |
|---|---|
| Syntax | `php -l` über alle Dateien |
| Schema | Tabellendefinitionen gegen die Eigenheiten von `dbDelta()` — insbesondere kein Semikolon in Spaltenvorgaben, sonst zerschneidet dbDelta die Anweisung und legt die Tabelle stillschweigend nicht an |
| Klassen | jeder statische Aufruf lässt sich auf eine vorhandene Methode auflösen |
| Verdrahtung | jede Router-Aktion hat einen Handler mit gültigem Recht, jede Ansicht eine Klasse und einen Navigationspunkt, jede Formularaktion ist registriert, jede benutzte Tabelle im Schema |
| Löschen | zu jeder Anlege-Aktion gibt es eine Löschaktion, die einen Handler hat und in der Oberfläche erreichbar ist |
| IBAN und Formeln | Prüfziffern nach ISO 13616 gegen echte Testwerte, Formelparser inklusive Fehlerfällen |
| SEPA | Struktur, Kontrollsummen und Zeichensatz von pain.008 und pain.001 |
| Beiträge | Zeitraumzerlegung je Turnus und Fälligkeitsregeln |

Nicht geprüft: das Zusammenspiel in einer laufenden WordPress-Installation.
