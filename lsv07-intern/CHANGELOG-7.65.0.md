# Änderungen in Version 7.65.0

## Meldetabelle: neue Spalte „Attest bis"

Die Meldeliste hat eine zusätzliche Spalte mit dem Ablaufdatum des
ärztlichen Attests. Sie steht direkt hinter dem Jahrgang, weil sie zur
Person gehört:

| Schwimmer/in | Jahrgang | **Attest bis** | Abschnitt | WettkampfNr. | Strecke | Meldezeit |
|---|---|---|---|---|---|---|

Das Datum kommt aus den Stammdaten des Schwimmers und ist **nur für
diese eine Meldung** änderbar. Wer es hier korrigiert, ändert nichts an
den Stammdaten — dort bleibt alles, wie es war. Umgekehrt gilt: eine
gespeicherte Meldung behält ihr Datum auch dann, wenn später ein neues
Attest in den Stammdaten eingetragen wird.

### Auffällige Atteste werden markiert

Genau dafür steht die Spalte in der Meldeliste, deshalb fällt beides
sofort ins Auge:

- **Rot** — das Attest läuft vor dem ersten Wettkampftag ab
- **Orange** — für den Schwimmer ist gar kein Attest hinterlegt

Die Markierung zieht beim Tippen mit: sobald ein gültiges Datum
eingetragen ist, verschwindet sie. Ein Mouseover nennt den Grund
(„Attest ist zum Wettkampf abgelaufen", „Kein Attest hinterlegt",
„Attest gültig bis …").

Gibt es zum Wettkampf kein Datum, gilt der heutige Tag als Stichtag.

### Excel-Export

Die Spalte ist im Export enthalten, als deutsches Datum (`30.06.2027`).
Der Titel wird jetzt über alle sieben Spalten verbunden.

### Auf dem Handy

Wie die übrigen Felder der Meldetabelle: eine beschriftete Zeile in der
Karte des jeweiligen Starts, mit derselben Farbmarkierung.

## Hinweis zur Datumsanzeige

Das Feld ist ein normales Datumsfeld, wie die 28 anderen im Plugin auch
(Anwesenheit, Wettkämpfe, Statistik-Zeiträume). Wie ein solches Feld
aussieht, bestimmt der Browser anhand seiner Spracheinstellung — auf
einem deutschen Browser also `30.06.2027`. Gespeichert und exportiert
wird unabhängig davon immer dasselbe Datum.

## Datenbank

Eine neue Spalte `attest_bis` (DATE) in `lsv07i_meldung_start`. Sie wird
beim Update automatisch ergänzt; bestehende Meldungen bleiben erhalten
und bekommen das Feld leer. Beim nächsten Öffnen und Speichern greift
für Zeilen ohne Angabe automatisch das Datum aus den Stammdaten.

## Geprüft

- 65 Backend-Prüfungen (10 davon neu für die Attest-Spalte): Vorbelegung
  aus den Stammdaten, `0000-00-00` zählt als „kein Attest", abweichendes
  Datum wird übernommen, geleertes Feld bleibt leer, deutsches Format,
  unmögliche Daten (`2026-02-30`) und Unsinn werden verworfen,
  Stammdaten bleiben nachweislich unangetastet
- 112 Oberflächen-Prüfungen bei 1280 px und 390 px (22 davon neu):
  Spalte in jeder Zeile, Vorbelegung, rote und orange Markierung samt
  Hinweistext, Markierung folgt der Korrektur, Wert wird mitgeschickt,
  Excel-Inhalt Zelle für Zelle nachgelesen
- Gesamtdurchlauf unverändert grün: 114 Kachelwechsel, 1832 Knöpfe,
  keine JavaScript-Fehler, kein waagerechtes Scrollen
- Statische Prüfung ohne Befund
