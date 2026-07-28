# Änderungen in Version 7.51.0

## Neu: Personen-Import mit Spalten-Zuordnung

Der CSV-Import für Personen erwartet keine festen Spaltennamen mehr.
Stattdessen läuft er in drei Schritten (Admin → **Personen-Import**):

1. **Datei wählen** — CSV oder Excel (.xlsx/.xls/.ods). Die
   Überschriften dürfen frei benannt sein. Alternativ Inhalt einfügen.
2. **Spalten zuordnen** — eine Zeile pro Spalte der Datei, mit
   Beispielwerten aus den echten Daten und einem Auswahlfeld für das
   Zielfeld. Der Vorschlag stammt aus den Überschriften und lässt sich
   komplett überschreiben. Fehlende Pflichtfelder und doppelt vergebene
   Zielfelder werden sofort gemeldet.
3. **Prüfen & importieren** — Vorschau mit Status je Zeile
   (Neu / Update / Fehler), aufgelösten Mannschaften und WordPress-Konto.
   Fehlerhafte Zeilen werden übersprungen, der Rest wird importiert.

### Zielfelder

Neu zuordenbar sind **Notizen**, **Aktiv-Kennzeichen** und das
**WordPress-Konto** (per Benutzername, E-Mail-Adresse oder ID).

Sparten-Rollen und Mannschaften lassen sich jetzt als eigene Spalte je
Sparte zuordnen (`Rolle Schwimmen`, `Mannschaft Schwimmen`, …). Das
bisherige kombinierte Format (`schwimmen:sportler,triathlon:trainer`
bzw. `schwimmen:A-Mannschaft|fitness:Kraftsport`) funktioniert
weiterhin. Mehrere Spalten dürfen auf dasselbe Feld zeigen, etwa
„Team 1" und „Team 2" auf die Mannschaften.

### Optionen

- **Abgleich** bestehender Personen wahlweise über Vorname + Nachname +
  Geburtsjahr, nur Name, DSV-ID, Mitgliedsnummer oder E-Mail
- **Sparten & Mannschaften** ergänzen oder ersetzen
- **Leere Zellen überspringen**, damit ein Import ohne Wert nichts löscht
- **Nur bestehende Personen aktualisieren**, keine neuen anlegen

## Zusammengeführt: Personen-Verwaltung

Es gab zwei Personen-Oberflächen mit denselben HTML-IDs; ein Klick auf
„Bearbeiten" öffnete beide Dialoge übereinander. Der Personen-Tab ist
jetzt die einzige Oberfläche und kann alles: Stammdaten, Geschlecht,
DSV-ID, Mitgliedsnummer, Notizen, Aktiv-Kennzeichen, Sparten/Rollen und
Mannschaften. Der WordPress-Account ist optional — so sind auch
importierte Personen ohne Konto bearbeitbar. Filter nach Sparte und
Rolle sowie eine Suche über Name, E-Mail, DSV-ID und Mitgliedsnummer.

## Behobene Fehler

- **Rückmeldungen waren unsichtbar.** `toast()` schrieb in ein Element,
  das es im Template nicht gab, und nutzte CSS-Klassen ohne Definition.
  Betraf sämtliche Erfolgs- und Fehlermeldungen im ganzen Plugin.
- **CSV-Import überschrieb beim Aktualisieren zu viel.** Auch Felder,
  die gar nicht in der Datei standen, wurden geleert — WordPress-
  Verknüpfung und Notizen gingen dabei verloren.
- **Fataler Fehler beim Nachrichtenversand.** `LSV07I_Mail::is_enabled()`
  wurde aufgerufen, existierte aber nicht.
- **Stammdaten für die Abrechnung wurden unvollständig gespeichert.**
  BIC, Straße, PLZ und Ort landeten nie in der Datenbank, obwohl die
  Felder im Formular stehen. Der Kontoinhaber hatte gar kein
  Eingabefeld und wurde bei jedem Speichern geleert.
- **PHP 8.4.** Die CSV-Funktionen wurden ohne `$escape`-Argument
  aufgerufen und lösten Deprecation-Meldungen aus, die den
  Datei-Download beschädigen können.
- **Ladebalken war unsichtbar**, weil er eine CSS-Variable nutzte, die
  außerhalb des App-Containers nicht definiert ist.
- **Doppeltes Modal** `#m-trainer-detail` stand zweimal im Template.
- **Trainer-/Schwimmer-Import war nicht erreichbar** — das Panel hatte
  keinen Tab-Button mehr. Jetzt eigener Tab.
- **Toter Handler im Fitness-Bereich**, eine Kopie des Triathlon-Blocks
  mit nicht angepassten IDs.
- **Hinweis zur Personen-Migration** prüfte ein Flag, das nie gesetzt
  wird, und erschien deshalb nie.

## Verbesserungen

- **Umlaute aus Excel-CSV.** Ist eine Datei kein gültiges UTF-8, wird
  sie als Windows-1252 gelesen — dem Standard, in dem Excel unter
  Windows CSV speichert.
- **Robusteres CSV-Parsen.** Felder mit Zeilenumbrüchen in
  Anführungszeichen bleiben zusammen. Die Trennzeichen-Erkennung
  ignoriert Zeichen innerhalb von Anführungszeichen und lässt sich
  manuell übersteuern (Semikolon, Komma, Tabulator, Senkrechtstrich).
- **SheetJS liegt lokal im Plugin** (Version 0.20.3 statt 0.18.5 vom
  CDN) und wird nur noch geladen, wenn tatsächlich eine Excel-Datei
  gelesen oder geschrieben wird — bisher bei jedem Seitenaufruf. Damit
  geht keine Anfrage mehr an einen fremden Server, passend zu den
  bereits lokal eingebundenen Schriften.

## Entfernt

- **Altes Nachrichten-Modul** (`class-ajax-nachrichten.php`, 9
  AJAX-Endpunkte, zugehöriges JavaScript). Ersetzt durch das
  Chat-/Konversations-System. Die Oberfläche war schon weg, der Code
  fragte aber weiterhin im Minutentakt einen Ungelesen-Zähler ab.
- **Altes grobes Rechtesystem** (`class-ajax-permissions.php` und sein
  Panel, das keinen Tab-Button mehr hatte). Rechte laufen ausschließlich
  über die granulare Rechteverwaltung.

Die Tabellen `lsv07i_nachrichten` und `lsv07i_permissions` werden nicht
gelöscht. Sie werden nur nicht mehr angelegt und nicht mehr gelesen; wer
die Altdaten nicht mehr braucht, kann sie manuell entfernen.

## Datenbank

Keine Schema-Änderungen. Bestehende Installationen können das Plugin
einfach überschreiben.
