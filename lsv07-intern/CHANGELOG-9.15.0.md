# Interner Bereich 9.15.0: Startsignal mit Runden, Badeuhr im Vollbild

## Startsignal: Runden und versetzte Starts

Das Startsignal im Trainingsplan-Vollbild kann jetzt mehrere Runden mit
versetzten Starts geben, zum Beispiel wenn die Schwimmer nacheinander
im Abstand von 5 Sekunden losschwimmen.

Ablauf: Ein **langer Pfiff** heisst „bereitmachen“. Nach der Pause kommt
**je Start ein kurzer Pfiff**. Bei mehreren Runden beginnt jede Runde
im eingestellten Rundenabstand. Der Abstand zählt von Rundenstart zu
Rundenstart.

| Zahl | Bereich | Vorgabe |
|---|---|---|
| Pause bis zum Start | 2 bis 60 s | 5 s |
| Runden | 1 bis 50 | 1 |
| Starts je Runde | 1 bis 20 | 1 |
| Abstand der Starts | 1 bis 600 s | 5 s |
| Abstand der Runden | 0:01 bis 99:59 | 1:00 |

* **Im Editor** stellt man die Zahlen je Session ein. Die Felder
  erscheinen, sobald „Startsignal“ angehakt ist. Der Rundenabstand wird
  als m:ss eingegeben, z. B. 1:45.
* **Im Vollbild** lassen sie sich mit − und + für den Moment ändern.
  Das gilt sofort, wird aber nicht gespeichert. Beim nächsten Öffnen
  gelten wieder die Zahlen aus dem Editor. Während das Startsignal läuft,
  sind die Regler gesperrt.
* **Anzeige** beim Ablauf: „Bereitmachen … 3“, dann z. B. „Runde 2/4 ·
  Start 3/4“ und „Nächster Start in 0:04“.
* Eine Runde muss vorbei sein, bevor die nächste beginnt. Der
  Rundenabstand ist darum mindestens so lang wie alle Starts einer Runde.
  Ist er kürzer, wird er angehoben: im Vollbild von selbst, im Editor mit
  Hinweis am Feld, auf dem Server beim Speichern.
* Die Pfiffe werden im Takt des Audiosystems geplant, immer für die
  nächsten 10 Sekunden. So kommen sie pünktlich, auch wenn die Anzeige
  ruckelt. „Abbrechen“ und Schliessen stoppen auch die schon geplanten
  Pfiffe.

## Stoppuhr getrennt vom Startpfiff

Der Startpfiff startet die Stoppuhr **nicht mehr**. Die Stoppuhr hat
ihren eigenen Start- und Stopp-Knopf.

Die bisher auf dem Gerät gemerkte Pause des Startsignals entfällt. Die
Pause steht jetzt mit in der Session.

## Badeuhr oben rechts

Im Vollbild schwebt oben rechts, unter der Leiste, eine **Badeuhr**
(Pace Clock):

* 60-Sekunden-Zifferblatt mit **vier Zeigern in vier Farben** (rot,
  blau, grün, gelb), je 15 Sekunden versetzt.
* Sie läuft **synchron zur Uhr des Geräts**. Zur vollen Minute steht der
  rote Zeiger auf der 60.
* **Antippen** schaltet die Grösse um (klein, mittel, gross).
* Der Knopf **„Badeuhr“** in der Leiste blendet sie aus und ein.
* Grösse und Ein/Aus merkt sich das Gerät. Es wird nur
  `{an, groesse}` gespeichert, keine personenbezogenen Daten.
* Beim Schliessen des Vollbilds hält sie an.

## Sicherheit

* Der Server nimmt nur ganze Zahlen in den Grenzen an. Alles andere fällt
  weg, dann gilt die Vorgabe. Die Zahlen werden nur gespeichert, wenn das
  Startsignal angehakt ist.
* Keine neuen Endpunkte, keine neuen Rechte, keine neuen Daten über
  Personen.

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Server gegen echtes WordPress 6.8.3 (Bereinigung, Grenzen, Rundenabstand, ohne Startsignal, Vorlage) | 33 |
| Browser mit gesteuerter Zeit (Editor, Pfiffzeiten auf die Zehntelsekunde, Anzeigen, Regler und Grenzen, Stoppuhr getrennt, Abbrechen, Schliessen, Badeuhr: Lage, Zeiger, Farben, Gleichlauf, Grösse, Ein/Aus, Anhalten) | 91 |

**Gegenprobe:** Jede Regel wurde einmal im echten Plugin gebrochen, und
der Lauf muss rot werden: **66 von 66 rot**, nach dem Zurücksetzen wieder grün. Die alten Prüfungen zur gemerkten Pause und zur Stoppuhr am Pfiff sind durch die neuen ersetzt.

Bisherige Prüfungen grün: Oberfläche 210, Abzeichen 52 + 101,
Mannschaftsbuch 27 + 39 + 12, Version 9.12.0 24 + 31.
