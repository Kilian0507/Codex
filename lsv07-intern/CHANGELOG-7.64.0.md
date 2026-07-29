# Änderungen in Version 7.64.0

## Neu: Wettkampfmeldungen

Unter **Schwimmen → Meldungen** lässt sich pro Wettkampf und Mannschaft
eine Meldeliste erstellen. Der Bereich läuft in drei Schritten, und es
ist immer nur einer davon sichtbar — auf dem Handy bleibt die Seite so
schmal und übersichtlich.

### Schritt 1 — Wettkampf und Mannschaft

Wettkampf aus der Liste wählen (alle Wettkämpfe der letzten zwei Jahre
und alles Kommende), dazu die Mannschaft sowie die Anzahl der
**Abschnitte** und **Wettkampfnummern**. Sind einem Wettkampf bereits
Mannschaften zugeordnet, zeigt die Auswahl nur diese.

Abschnitte und Wettkampfnummern begrenzen später die Auswahlfelder in
der Tabelle: bei 2 Abschnitten gibt es genau die Werte 1 und 2.

### Schritt 2 — Wer schwimmt wie oft

Alle Schwimmer der Mannschaft mit ihrem Jahrgang, jeweils mit einem
Zähler (− / Zahl / +). 0 heißt: nicht gemeldet. Oben zeigt ein Feld
laufend die Gesamtzahl der Starts und wie viele Schwimmer gemeldet
sind. Ein Suchfeld filtert lange Mannschaftslisten; die eingetragenen
Zahlen überstehen das Filtern.

### Schritt 3 — Die Meldetabelle

Überschrift ist **Wettkampfname – Mannschaftsname**, darunter die
Tabelle:

| Schwimmer/in | Jahrgang | Abschnitt | WettkampfNr. | Strecke | Meldezeit |
|---|---|---|---|---|---|

Wer dreimal schwimmt, steht dreimal drin — Name und Jahrgang sind schon
ausgefüllt. Der Trainer trägt Abschnitt und Wettkampfnummer ein und
wählt die Strecke aus derselben Liste, die auch die Bestzeiten
verwenden. Die Meldezeit ist freiwillig (Format `1:02,45` oder `32,10`;
ein Punkt wird automatisch zum Komma).

Auf dem Handy wird jede Zeile zu einer kleinen Karte mit beschrifteten
Feldern — anders als die übrigen Tabellen klappt sie **nicht** zu, weil
hier etwas eingetragen werden muss.

Ein Sprung zurück zu Schritt 2 wirft nichts weg: bereits ausgefüllte
Zeilen bleiben erhalten.

### Excel-Export

„Excel-Export" erzeugt eine `.xlsx` mit dem Titel in Zeile 1 (über alle
sechs Spalten verbunden), einer Leerzeile und darunter der Tabelle. Die
Strecke steht im Klartext („50m Freistil"), nicht als Kürzel. Das
Tabellenblatt heißt wie die Mannschaft, die Datei
`meldung_<Wettkampf>_<Mannschaft>.xlsx`.

### Speichern

Meldelisten werden gespeichert und lassen sich jederzeit wieder öffnen
und weiterbearbeiten. Die Übersicht zeigt Wettkampf, Mannschaft, Datum
und die Anzahl der Starts.

Name und Jahrgang werden beim Speichern aus dem System übernommen, nicht
aus dem Browser. Eine abgegebene Meldung bleibt damit korrekt, auch wenn
der Schwimmer später die Mannschaft wechselt oder gelöscht wird.

## Rechte

Vier neue Rechte in der Rechteverwaltung unter **Schwimmen →
Wettkampfmeldungen**:

| Recht | Bedeutung |
|---|---|
| Meldungen-Tab sehen und Meldelisten öffnen | Ohne dieses Recht ist der Tab unsichtbar |
| Meldeliste anlegen und bearbeiten | Schritte 1–3 und Speichern |
| Meldeliste löschen | Löschen-Knopf in der Übersicht |
| Meldeliste als Excel exportieren | Export-Knopf |

Diese Rechte haben **keinen** Rollen-Fallback: auch wer die volle
Schwimmen-Rolle hat, sieht den Bereich erst, wenn das Leserecht
ausdrücklich vergeben wurde. Administratoren dürfen immer.

Ein Trainer sieht und bearbeitet nur Meldungen seiner eigenen
Mannschaften. Schwimmwart und Administrator sehen alle.

## Geprüft

- 55 Backend-Prüfungen: Anlegen, Aktualisieren statt Doppelanlage,
  Grenzwerte für Abschnitte und Wettkampfnummern, Jahrgangsermittlung,
  Rechteprüfung je Endpunkt, Mannschaftsgrenze für Trainer, Löschen
  samt Starts
- Eingabeprüfung: Schwimmer fremder Mannschaften werden übersprungen,
  zu große Abschnitte und Wettkampfnummern auf 0 gesetzt, unbekannte
  Strecken und unlesbare Meldezeiten verworfen, mehr als 500 Starts
  abgelehnt
- 90 Oberflächen-Prüfungen bei 1280 px und 390 px: alle drei Schritte
  durchgeklickt, Excel-Datei heruntergeladen und ihr Inhalt Zelle für
  Zelle nachgelesen
- Gesamtdurchlauf: 114 Kachelwechsel, 1832 Knöpfe, keine
  JavaScript-Fehler, kein waagerechtes Scrollen
- Statische Prüfung ohne Befund, Personen-Import weiterhin 65 von 65

## Datenbank

Zwei neue Tabellen: `lsv07i_meldung` (Kopf) und `lsv07i_meldung_start`
(eine Zeile je Start). Sie werden beim Update automatisch angelegt.
Bestehende Daten bleiben unberührt.
