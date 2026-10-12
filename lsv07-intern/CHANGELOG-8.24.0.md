# LSV07 Interner Bereich — 8.24.0

## Dialoge schliessen nur noch auf Ansage

Ein Klick auf die Fläche neben einem Dialog schliesst ihn nicht mehr.

Das war bequem, ging aber regelmässig daneben: Wer etwas im Dialog
markiert und die Maus dabei über den Rand hinauszieht, lässt sie
ausserhalb los. Der Browser meldet den Klick dann auf dem gemeinsamen
Elternelement — also auf der Fläche. Der Dialog war zu und das
Eingetippte weg, ohne dass jemand etwas angeklickt hätte.

**Es bleiben drei Wege hinaus:** das Kreuz in der Kopfzeile, *Abbrechen*
bzw. *Schliessen*, und Escape. Escape bleibt bewusst — es ist die
Tastaturfassung des Kreuzes und eine bewusste Handlung, kein Versehen.

Geprüft wurde, dass **jeder der 39 Dialoge** mindestens einen sichtbaren
Weg hinaus hat. Keiner sperrt jemanden ein.

## Escape schliesst nur noch den obersten Dialog

Bisher schloss Escape *alle* offenen Dialoge auf einmal. Wer in einer
Rückfrage auf Escape drückte, verlor damit auch das Fenster darunter.
Jetzt geht nur der oberste zu; ein zweites Escape schliesst den
nächsten.

## Dialoge über Dialogen liegen jetzt oben

Welcher Dialog über welchem liegt, richtete sich nach seiner Stelle in
der Vorlage — alle teilen sich dieselbe Ebene. Öffnet einer einen
zweiten, der in der Datei davor steht, lag der zweite **dahinter** und
war nicht zu bedienen. Erreichbar war er nur über den Klick auf die
Fläche daneben, also genau über den Weg, der jetzt wegfällt.

Dialoge bekommen ihre Ebene nun beim Öffnen zugeteilt: Wer zuletzt
aufging, liegt oben.

Dafür laufen jetzt **alle** Dialoge über dieselben beiden Funktionen.
Der Fitness- und der Triathlonbereich öffneten ihre Fenster bisher
direkt über die CSS-Klasse und bekamen deshalb keine Ebene zugeteilt —
25 solcher Stellen sind umgestellt, ebenso das Wiederherstellen eines
Fensters nach dem Neuladen. Am Verhalten der gemerkten Formular-
Entwürfe ändert sich dabei nichts.

## Was bewusst bleibt

Die **Navigationsschublade** auf dem Handy und die **Bildansicht im
Chat** schliessen weiterhin durch Tippen daneben. Beide enthalten keine
Eingaben, es geht dort nichts verloren, und bei einem vergrösserten
Bild ist das Tippen daneben die übliche Geste.

## Geprüft

Eine neue Prüfung geht **alle 39 Dialoge** einzeln durch und hält fest:

- Klick auf die Fläche daneben schliesst nicht
- Markieren über den Rand hinaus schliesst nicht (als echte
  Mausbewegung: drücken im Dialog, ziehen, loslassen ausserhalb)
- es gibt einen sichtbaren Weg hinaus, und dieser schliesst
- Escape schliesst

Dazu die Stapelung über zwei echte Bedienwege und die beiden von Hand
erzeugten Verschiebe-Fenster: **246 Zusagen, keine verletzt.**

Dazu eine Rauchprobe über die Oberfläche — jeder sichtbare Knopf
gedrückt, **keine JavaScript-Fehler**.
