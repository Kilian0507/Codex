# Interner Bereich 9.18.0: „Wir starten“ in der Meldung

Unter **Meldung → Wettkampfliste → „Bearbeiten“** gibt es die Spalte
**„Wir starten“**. Mit einem Häkchen je Wettkampf wählt man, an welchen
Starts die Mannschaft teilnimmt.

* **Je Mannschaft.** Die Auswahl gilt nur für diese Meldung
  (Wettkampf × Mannschaft). Jugend A kann andere Wettkämpfe wählen als
  Jugend B. Die gemeinsame Wettkampfliste bleibt vollständig.
* **Anfangs ist alles angehakt.** Man nimmt heraus, was die Mannschaft
  nicht schwimmt. Bestehende Meldungen sehen aus wie bisher. Kommen später
  Wettkämpfe zur Liste dazu, sind sie von selbst angehakt.
* **Nicht angehakte Wettkämpfe verschwinden aus dem Raster.** Der
  Schalter **„Alle Wettkämpfe zeigen“** holt sie zurück und nennt, wie
  viele ausgeblendet sind.
* **Schon gemeldete Starts bleiben immer sichtbar.** Ihre Spalte ist dann
  als „nicht angehakt“ gekennzeichnet. Die Auswahl ist eine Ansicht und
  löscht keinen Start.
* Über der Liste steht, in wie vielen Wettkämpfen die Mannschaft startet
  („1 von 4“).
* Dazu gibt es **„Alle anhaken“** und **„Keinen“**.
* Wer melden darf, die Liste selbst aber nicht pflegen darf, bekommt den
  Knopf **„Wettkämpfe wählen“**. Dann ist nur „Wir starten“ bedienbar,
  die Liste ist gesperrt.

**Server:**

* Gespeichert werden nur die abgewählten Nummern, und nur solche, die in
  der Liste stehen. Sie werden vereinheitlicht („3A“ = „3a“) und
  ausdrücklich als Text gespeichert.
* Es prüft Recht und Mannschaft. Lesen allein reicht nicht.
* Unlesbares und zu Langes wird abgelehnt.
* Jede Änderung steht im Protokoll.

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Server gegen echtes WordPress 6.8.3: Anfangszustand, Speichern und Lesen, je Mannschaft, Rechte, unbekannte Nummern, Vereinheitlichung, Zahlen als Text, Grenzen, Starts bleiben gültig, Protokoll | 16 |
| Browser: Spalte „Wir starten“, Häkchen über Hinzufügen und Entfernen hinweg, erst Liste dann Auswahl gespeichert, Ausblenden, gemeldete Starts sichtbar, Kennzeichen, Zähler, „Alle Wettkämpfe zeigen“, Alle/Keinen, Abbrechen, nur auswählen ohne Listenrecht, nur lesen | 29 |

Der Test fand einen echten Fehler: Nummern wie „2“ wurden als Zahl
gespeichert und beim Lesen verworfen. Die Abwahl wäre verloren gegangen.
Das ist behoben.

**Gegenprobe:** Jede Regel wurde einmal im echten Plugin gebrochen, und
der Lauf muss rot werden: **22 von 22 rot**, nach dem Zurücksetzen wieder
grün. Ein Bruch („Auswahl nach der Liste vergessen“) wurde zunächst nur
durch einen Absturz des Tests rot, nicht durch die benannte Prüfung. Der
Test ist robust gemacht, danach lief die ganze Gegenprobe erneut. Weil
sich das Raster geändert hat, lief auch die Gegenprobe der Meldung erneut:
**52 von 52 rot**.

Bisherige Prüfungen grün: Meldung 40 + 47, DSV7 51 + 33 + 31,
Trainingsplan 37 + 98, Oberfläche 210, Abzeichen, Mannschaftsbuch,
9.12.0, 9.16.0, Zuständigkeit 59, Meldungstexte 79.
