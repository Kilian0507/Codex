# LSV07 Interner Bereich — 8.15.0

## Vollbild: alles größer, dazu ein Größen-Regler

### Auch die Übung selbst ist jetzt groß

Bisher stach im Vollbild vor allem die Kopfzeile (z. B. „8x50 · Brust")
heraus, während die eigentliche Übungsbeschreibung darunter deutlich
kleiner blieb. Genau die muss aber vom Beckenrand aus lesbar sein.

Die Beschreibung ist jetzt fast so groß wie die Kopfzeile und zusätzlich
kräftiger gesetzt — in der Einzelansicht auf einem großen Bildschirm rund
doppelt so groß wie vorher. Ausrüstung und Kommentar sind ebenfalls
mitgewachsen. Das gilt auch für die Übersicht mit allen Übungen.

### Größe selbst einstellen — und sie bleibt

Oben im Vollbild sitzt jetzt ein Regler: **− 100 % +**.

- Jeder Klick auf **+** oder **−** geht eine Stufe hoch bzw. runter
  (70 % bis 250 %, in acht Stufen).
- Der aktuelle Wert steht dazwischen, damit klar ist, wo man gerade steht.
- An den Enden sperrt der jeweilige Knopf, statt wirkungslos zu bleiben.
- Über die Tastatur geht es ebenso: **+** und **−**.
- Die Einstellung wirkt sofort auf alles — Kopfzeile, Beschreibung,
  Ausrüstung und Kommentar.

**Die gewählte Größe wird gemerkt.** Beim nächsten Öffnen des Vollbilds ist
sie wieder da, auch nach einem Neuladen der Seite oder am nächsten Tag.
Gespeichert wird sie im Browser des jeweiligen Geräts — das Tablet am Becken
behält also seine Einstellung, unabhängig davon, wer sich anmeldet, und ohne
die Einstellung anderer Geräte zu verändern.

## Tests

- 47 Prüfungen im echten Browser (großer Bildschirm 1600 × 900 und Tablet
  hochkant), darunter 16 neue rund um Größe und Regler:
  - Die Beschreibung ist in der Übersicht mindestens 40 px und in der
    Einzelansicht mindestens 60 px groß und wächst zwischen beiden Ansichten
    messbar mit.
  - **+** vergrößert nachweislich, **−** verkleinert wieder, und die
    Prozentanzeige zieht korrekt mit.
  - Beim Vergrößern wächst auch die Beschreibung mit, nicht nur die
    Kopfzeile.
  - An der kleinsten Stufe ist **−** gesperrt und **+** weiterhin benutzbar,
    an der größten umgekehrt.
  - Die Einstellung landet im Browserspeicher, ist beim erneuten Öffnen des
    Vollbilds wieder gesetzt und überlebt auch ein komplettes Neuladen der
    Seite.
- Statische Prüfung ohne Befund; alle zehn Oberflächen-Varianten rendern
  fehlerfrei; Klick-Durchlauf über alle Bereiche aller fünf Rollen
  (90 Klicks) und die 23 Prüfungen zum Meldungs-PDF weiterhin grün.
