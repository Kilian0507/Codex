# Interner Bereich 9.13.1 — Abzeichen: Name am Häkchen auf Handy und Tablet

**Fehler:** Beim Zuordnen der Abzeichen stand auf schmalen Bildschirmen
(Handy, kleines Tablet) nur das Häkchen da, ohne den Namen des
Abzeichens.

**Ursache:** Unter 900 Pixel Breite werden im internen Bereich alle
Tabellen zu Karten, und der Tabellenkopf wird ausgeblendet. Der Name des
Abzeichens stand nur im Tabellenkopf.

**Behoben:** Name und Bild des Abzeichens stehen jetzt auch in der Zelle
selbst. Auf dem Desktop bleiben sie im Tabellenkopf und erscheinen nicht
doppelt. Auf Handy und Tablet steht je Zeile links das Abzeichen und
rechts das Häkchen. Die ganze Zeile ist antippbar. Auf dem Handy klappt
wie überall die Karte eines Schwimmers mit einem Tipp auf den Namen auf.

## Geprüft

Im Browser bei 1300, 700 und 390 Pixel Breite. Geprüft wird, was man
sieht: Für jedes Häkchen steht der richtige Name genau einmal sichtbar
daneben bzw. darüber. Ein Tipp auf den Namen vergibt das Abzeichen.
Browser-Prüfung 101 Zusicherungen, Server 45.

**Gegenprobe:** 37 von 37 rot, darunter neu „Name fehlt in der Zelle“,
„Name auf dem Handy ausgeblendet“ und „Name auf dem Desktop doppelt“.
