# LSV07 Interner Bereich — 8.9.3

## Öffentliche Wettkampf-Seite ist jetzt immer hell

Die öffentliche Übersichtsseite (`[lsv07_wettkaempfe]`) hat sich bisher an den
Dunkelmodus des Geräts angepasst — auf einem Handy mit aktiviertem Dark Mode
wurde sie dunkel dargestellt. Da es sich um die Außendarstellung des Vereins
handelt, soll sie auf jedem Gerät gleich aussehen.

Behoben: Die Seite wird jetzt immer im hellen Design angezeigt, unabhängig
von der Geräteeinstellung. Konkret:

- Die Dunkelmodus-Umschaltung wurde aus dem Stylesheet entfernt.
- `color-scheme: light` verhindert zusätzlich, dass das Handy im Dunkelmodus
  von sich aus Farben oder Bedienelemente umdreht.
- Die Seite bringt jetzt ihren eigenen hellen Hintergrund mit. Damit bleibt
  der Text auch dann lesbar, wenn das umgebende WordPress-Theme selbst in den
  Dunkelmodus wechselt — vorher hätte dort dunkler Text auf dunklem Grund
  gestanden.

Der interne Bereich ist davon nicht betroffen: Dort bleibt die
Design-Umschaltung wie gehabt bestehen.

## Tests

- 10 neue Prüfungen im echten Browser mit einem simulierten Handy
  (390 × 844) — einmal mit Gerät im Dunkelmodus, einmal im hellen Modus.
  Geprüft wurden jeweils Seitenhintergrund, Kartenhintergrund, Grundtext-
  und Überschriftenfarbe sowie die festgelegte `color-scheme`-Einstellung.
  Beide Modi liefern identisch das helle Design.
- Statische Prüfung ohne neuen Befund.
