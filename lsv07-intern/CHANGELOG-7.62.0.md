# Änderungen in Version 7.62.0

## Symbol-Navigation in allen Bereichen

Was in 7.60 im Schwimmbereich begonnen hat, gilt jetzt überall: Statt
einer Reiterleiste steht oben in jedem Bereich ein Raster aus
**Symbolkacheln**. Der aktive Bereich ist blau gefüllt, darunter öffnet
sich der Inhalt.

| Bereich | Kacheln |
|---|---|
| **Schwimmen** | Mannschaften · Anwesenheit · Wettkämpfe · Springer · Bestzeiten · Reflexion · Trainer |
| **Triathlon** | Gruppen · Anwesenheit · Trainer |
| **Fitness** | Gruppen · Anwesenheit · Trainer |
| **Trainer** | Abrechnung · Sonderabrechnung · Stammdaten |
| **Verwaltung** | Abrechnungen · Kassenwart |
| **Admin** | Stammdaten · Personen-Import · Sportler-Import · Rechte · Saisons · Log · Atteste · Konfiguration — darunter unter der Überschrift *Verwaltung*: Trainer · Personen · Mannschaften · Sportler · Zeiten · Mails |

Im Admin-Bereich trennt eine Zwischenüberschrift die beiden Gruppen —
vorher stand dort ein senkrechter Strich in einer langen Reiterzeile,
die auf dem Handy seitlich weggescrollt werden musste.

Die **Unter-Reiter** innerhalb einzelner Ansichten (Abrechnung:
Trainingstage/Wettkämpfe/Kilometergeld, Kassenwart: Liste/Jahresübersicht)
bleiben bewusst klassische Reiter — sie gehören zum Inhalt, nicht zur
Bereichsnavigation.

### Wie es umgesetzt ist

Die Symbole liegen zentral in einer Hilfsfunktion im Template
(`lsv07i_navsvg`), die Kacheln erzeugt `lsv07i_navicon`. Dadurch ist die
Bildsprache überall gleich und eine Änderung wirkt an einer Stelle. Die
Kacheln behalten intern die Reiter-Eigenschaften, sodass die
Panel-Umschaltung, das Merken der zuletzt besuchten Stelle und die
Rechteprüfung unverändert greifen — es werden weiterhin nur die Bereiche
gezeigt, für die eine Berechtigung vorliegt.

## Datenbank

Keine Änderungen.
