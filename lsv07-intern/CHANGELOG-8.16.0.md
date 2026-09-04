# LSV07 Interner Bereich — 8.16.0

## Vollbild neu gestaltet: wie ein gedrucktes Wettkampf-Programm

Die Vollbild-Ansicht war funktional, sah aber nach schlichten Kästen aus.
Sie ist jetzt durchgestaltet — als redaktionelles Programmheft in Schwarz
auf Weiß, mit einer einzigen Akzentfarbe (Beckenblau).

**Die Vereinsschrift wird endlich verwendet.** Die Vollbild-Ebene liegt
technisch außerhalb des internen Bereichs und hatte die lokal eingebundene
Vereinsschrift (Inter Tight) deshalb gar nicht bekommen — angezeigt wurde
eine beliebige Systemschrift. Jetzt ist sie ausdrücklich gesetzt. (Weiterhin
ohne externe Schriftdienste, die lokale Einbindung bleibt unangetastet.)

Im Einzelnen:

- **Haarlinien statt Kästen.** Die Übungen stehen als Zeilen untereinander,
  getrennt durch feine Linien — ruhiger und auf Distanz klarer als
  gerahmte Boxen.
- **Zweistellige Ziffern** (01, 02, 03) in einer eigenen Spalte, wie in
  einer Startliste. Beim Überfahren färbt sich die Ziffer blau und links
  fährt eine blaue Marke ein.
- **Größerer Kontrast in der Schrift:** sehr große, eng gesetzte
  Überschriften gegen kleine, weit gesperrte Versalien-Label.
- **Die Ausrüstung** ist kein grauer Chip mehr, sondern ein blau
  unterstrichenes Versalien-Label.
- **Einzelansicht als Plakat:** oben ein Marken-Label „ÜBUNG 02 VON 03" über
  einer kräftigen Linie, darunter die Übung in maximaler Größe — und die
  Übungsnummer als große, ganz helle Bildmarke am rechten Rand.
- **Kopfleiste** mit Vorzeile „TRAININGSPLAN" über dem Plantitel; die
  Knöpfe sind kantig gesetzt und invertieren beim Überfahren.
- **Sanfter Auftritt:** die Übungen blenden beim Öffnen leicht versetzt
  ein. Wer im System reduzierte Bewegung eingestellt hat, bekommt das
  ohne Animation.

### Neu: Fortschrittsleiste zum Springen

Unter der Kopfleiste sitzt jetzt eine Leiste mit einer Marke je Übung. Sie
zeigt auf einen Blick, wo man im Plan steht — **und man kann direkt auf eine
Marke tippen, um zu dieser Übung zu springen**, ohne sich durchzublättern.
Bei Plänen mit nur einer Übung bleibt sie ausgeblendet.

Größen-Regler, Blättern, Tastatursteuerung und das Merken der Schriftgröße
funktionieren unverändert.

## Tests

- 58 Prüfungen im echten Browser (großer Bildschirm und Tablet hochkant),
  darunter 6 neue zur Fortschrittsleiste: eine Marke je Übung, genau eine
  ist markiert, die markierte entspricht der angezeigten Übung, und ein
  Klick auf eine Marke springt tatsächlich zu dieser Übung.
- Alle bisherigen Prüfungen weiterhin grün: Farben (heller Grund, dunkle
  Schrift in beiden Ansichten), Schriftgrößen, Größen-Regler samt Sperren an
  den Enden, Speichern der Einstellung über ein Neuladen hinweg, Blättern
  mit Umlauf, Tastatur, Öffnen und Schließen.
- Statische Prüfung ohne Befund; alle zehn Oberflächen-Varianten rendern
  fehlerfrei; Klick-Durchlauf über alle Bereiche aller fünf Rollen
  (90 Klicks) und die 23 Prüfungen zum Meldungs-PDF ohne Befund.
