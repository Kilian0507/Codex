# Änderungen in Version 7.57.0

## Nachrichten neu aufgebaut — mobil zuerst

Der gesamte Nachrichten-Bereich ist neu gestaltet. Grundlage ist jetzt
die Handy-Ansicht; ab 900 Pixel Breite wird auf zwei Spalten
umgeschaltet.

- **Liste**: Rundes Bild je Unterhaltung, Name, Vorschautext, Uhrzeit und
  Zähler. Ungelesene Unterhaltungen sind fett hervorgehoben. Jede Zeile
  ist 56 Pixel hoch und damit bequem zu treffen.
- **Verlauf**: Höhen über `dvh` statt `vh` — die Eingabezeile
  verschwindet nicht mehr hinter den Browserleisten des Handys. Unten
  wird der Bereich der Home-Leiste (`safe-area-inset`) berücksichtigt.
- **Eingabezeile**: runde Knöpfe mit 42 Pixeln für Anhang, Dringend und
  Senden. Das Textfeld nutzt 16 Pixel Schrift — kleinere Werte lassen
  iOS beim Antippen hineinzoomen.
- **Dringend** ist jetzt ein Schalter mit Blitz-Symbol statt einer
  Ankreuzbox, die auf dem Handy kaum zu treffen war.

## Dateien im Chat

**Bilder werden direkt in der Nachricht angezeigt.** Ein Antippen öffnet
sie in einer Vollbild-Ansicht auf derselben Seite — kein neuer Tab, der
auf dem Handy blockiert werden oder ins Leere führen kann. Von dort führt
ein Knopf weiterhin zum Öffnen im neuen Tab.

**Andere Dateien** stehen als deutliche Zeile mit Symbol, Dateiname und
Größe in der Nachricht, 48 Pixel hoch.

**Wenn eine Datei nicht ausgeliefert werden kann**, erscheint jetzt eine
verständliche Seite („Die Datei liegt nicht mehr auf dem Server", „Du
bist kein Teilnehmer dieser Unterhaltung", „Der Link ist beschädigt")
statt eines nackten Fehlertexts im leeren Tab. Lässt sich ein Bild nicht
laden, wird es in der Nachricht durch eine anklickbare Dateizeile
ersetzt.

### Behoben

- **Dateiname im Download-Kopf war falsch kodiert.** Es stand eine
  prozentkodierte Fassung *in* den Anführungszeichen; Browser zeigten
  daher wörtlich `Trainingsplan%20W%C3%B6chentlich.pdf` an. Jetzt nach
  RFC 6266 mit `filename*=UTF-8''`.
- **Fehlender MIME-Typ** wird aus der Dateiendung abgeleitet. Ein leeres
  `Content-Type` führte dazu, dass der Browser gar nichts anzeigte.
- Ausgabepuffer werden vor dem Ausliefern vollständig geleert, damit
  keine fremde Ausgabe die Datei beschädigt.

## Nachrichten senden

Der Versand fühlt sich jetzt sofort an: Eingabefeld leert sich beim
Antippen von „Senden", die Nachricht erscheint direkt im Verlauf und
zeigt darunter ihren Zustand — *wird gesendet…* → *gesendet*. Auf die
Antwort des Servers wird nicht mehr gewartet, und der komplette
Nachrichten-Neuabruf nach jedem Senden entfällt.

Schlägt der Versand fehl, bleibt die Nachricht mit dem Hinweis *nicht
gesendet* und einem Knopf **erneut senden** stehen — der Text (und ein
angehängte Datei) geht nicht mehr verloren.

## Dunkle Darstellung im Chat

Die Bubbles fremder Nachrichten waren fest auf Weiß gesetzt, während die
Schrift im Dunkelmodus hell wurde — der Text war praktisch unsichtbar.
Jetzt laufen sie über dieselben Variablen wie der Rest. Ebenfalls
angepasst: Verlauf-Hintergrund, Suchfeld, Eingabefeld, runde Knöpfe und
die Bild-Platzhalter.

## Datenbank

Keine Änderungen.
