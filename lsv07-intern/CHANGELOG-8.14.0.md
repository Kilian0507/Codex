# LSV07 Interner Bereich — 8.14.0

## Neu: Trainingsplan im Vollbild fürs Becken

In der Trainingsplan-Ansicht gibt es jetzt den Knopf **„Vollbild"**. Er
öffnet den Plan bildschirmfüllend auf einer eigenen Seite — gedacht für das
Tablet oder den Monitor am Beckenrand.

**Bewusst dunkel gehalten:** heller Text auf dunklem Grund bleibt in einer
hellen, gefliesten Halle aus mehreren Metern Entfernung am besten lesbar und
blendet nicht.

**Eine Übung allein groß anzeigen:** Ein Tipp auf eine Übung stellt nur
diese dar — dann füllt sie den ganzen Bildschirm und die Schrift wird noch
einmal deutlich größer. Die anderen Übungen sind in dem Moment ausgeblendet.

Dazu:

- **„Alle Übungen"** bringt die vollständige Liste zurück.
- **‹ und ›** blättern durch die Übungen, ohne zwischendurch zur Übersicht zu
  müssen (am Ende geht es wieder bei der ersten los).
- Über die Tastatur geht das ebenso: **Pfeil links/rechts** zum Blättern,
  **Esc** zurück zur Übersicht bzw. zum Schließen.
- Der Hinweis oben zeigt, bei welcher Übung man gerade ist („Übung 2 von 3").
- Der **Kommentar** einer Übung erscheint nur in der Einzelansicht — in der
  Übersicht würde er nur Platz kosten, am Becken ist er hilfreich.

Alle Schriftgrößen wachsen mit der Bildschirmgröße mit: Auf einem großen
Monitor wird die Übung entsprechend größer dargestellt als auf einem Tablet,
ohne dass etwas seitlich aus dem Bild läuft. Wo der Browser es zulässt, wird
zusätzlich der echte Vollbildmodus angefordert.

## Tests

- 31 neue Prüfungen im echten Browser, auf einem großen Bildschirm
  (1600 × 900) und zusätzlich auf einem Tablet hochkant (768 × 1024):
  - Vollbild öffnet sich über den Knopf, füllt tatsächlich den ganzen
    Bildschirm und zeigt Titel sowie alle Übungen.
  - Ein Klick auf eine Übung zeigt genau diese allein; die Schrift ist dabei
    messbar größer als in der Übersicht und mit mindestens 90 px auch aus
    einiger Entfernung lesbar (auf dem Tablet mindestens 45 px).
  - Blättern über ‹ und › inklusive Umlauf, Tastatursteuerung, „Alle
    Übungen", Schließen und das Zurücksetzen des Seiten-Scrollens.
  - Kommentare erscheinen nur in der Einzelansicht, nichts läuft seitlich
    aus dem Bild, keine JavaScript-Fehler.
- Statische Prüfung ohne Befund; alle zehn Oberflächen-Varianten rendern
  fehlerfrei; Klick-Durchlauf über alle Bereiche aller fünf Rollen
  (90 Klicks) sowie die 23 Prüfungen zum Meldungs-PDF weiterhin grün.
