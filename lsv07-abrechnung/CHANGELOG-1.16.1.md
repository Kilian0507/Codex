# Abrechnung 1.16.1 — der Ladebalken ist zu sehen

Der Ladebalken aus 1.16.0 war da, aber kaum zu sehen:

* Er erschien erst **nach 150 ms**. Auf einem schnellen Server war fast
  jede Anfrage vorher fertig, und man sah nie einen. Jetzt startet er
  **sofort**.
* Damit er trotzdem nicht aufblitzt, steht er **mindestens 350 ms** und
  läuft dann auf 100 % durch, bevor er ausblendet.
* Ein **mittleres Blau auf dunkelblauer Kopfleiste**, drei Pixel hoch —
  darauf kaum zu erkennen. Jetzt fast Weiss mit hellblauem Leuchten.
* Er wuchs **langsam** (nach einer halben Sekunde ein Viertel der
  Breite). Jetzt steht er nach einer halben Sekunde über die halbe
  Breite.
* Er nimmt keinen Klick an.

Unverändert: Er bleibt, bis alle laufenden Anfragen fertig sind. Wer
„weniger Bewegung" eingestellt hat, sieht ihn ohne Gleiten. Die
Platzhalter in den Listen erscheinen weiterhin erst nach 150 ms.

## Geprüft

Der Prüfstand setzt jetzt die Klasse `lsv07a-fullscreen` an den
`<body>`, wie WordPress es auf der echten Seite tut. Neu geprüft wird,
was man **sieht**: Der Balken liegt oben zuoberst, ist mindestens 3 px
hoch, voll deckend, hebt sich von der Kopfleiste mit mindestens 3:1 ab,
steht nach einer halben Sekunde über die halbe Breite, steht auch bei
einer schnellen Anfrage mindestens 350 ms und ist danach fort.

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Oberfläche, im Vollbild wie auf der echten Seite | 201 | 125 von 125 |

Die Gegenprobe ist **vollständig neu gelaufen**, weil der Prüfstand jetzt
in einer anderen Umgebung (Vollbild) läuft. Elf Brüche betreffen den
Balken — darunter „liegt unter der Kopfleiste", „wartet wieder 150 ms",
„ohne Mindestzeit", „in der Farbe der Kopfleiste", „läuft zu langsam
an". Alle rot.

**Weiter offen aus 1.16.0:** Eine Absage beim Speichern kann eine
unbeteiligte, noch ladende Liste als „nicht geladen" markieren (im
internen Bereich mit 9.8.0 behoben). Hier nicht geändert, weil nicht
beauftragt.

**Nicht bewiesen:** Auf einem echten WordPress mit Ihrem Theme ist es
nicht gelaufen.
