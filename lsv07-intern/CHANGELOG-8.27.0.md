# LSV07 Interner Bereich — 8.27.0

## Verspätung im Mannschaftsbuch

Unter den sechs Zuständen steht in jeder Karte jetzt eine Verspätung.
Solange niemand zu spät war, ist sie nur ein gestricheltes Angebot
(*+ Verspätung*); angetippt öffnet sich in der Karte selbst ein Feld für
die **Minuten** und eines für einen **Grund** — der ist freiwillig.
Eingetragen steht dort zum Beispiel „12 Min zu spät · Bus verpasst",
deutlich hervorgehoben.

Kein Dialog, sondern ein Feld in der Karte: Am Beckenrand wird die
Verspätung meist gleich beim Hereinkommen eingetippt, und ein eigenes
Fenster je Person wäre dafür zu umständlich. Die Eingabetaste übernimmt,
*Abbrechen* lässt den alten Wert stehen, *Entfernen* räumt sie weg.

Ein paar Festlegungen, damit nichts überrascht:

* **Wer zu spät kommt, ist da.** Steht für die Person noch kein Zustand,
  wird beim Eintragen „anwesend" gesetzt — auch in der bisherigen
  Anwesenheit.
* Ein Wechsel des Zustands lässt die Verspätung stehen; wird der Zustand
  dagegen ganz **zurückgenommen**, verschwindet sie mit — für den Tag ist
  dann nichts mehr erfasst.
* Mehr als ein halber Tag (720 Minuten) ist keine Verspätung mehr und
  wird gekappt.

Die Kopfzeile der Anwesenheit zählt mit: „4 von 12 anwesend · 2 zu spät
· 1 offen".

**In der Statistik** gibt es eine eigene Kachel („3 zu spät, zusammen 25
Min"), eine Spalte *Zu spät* je Person (`2× · 17 Min`) und eine Liste
aller Verspätungen des Zeitraums mit Datum, Name, Minuten und Grund. Im
Verlauf einer einzelnen Person steht sie beim jeweiligen Tag.

## Bahnenbelegung steht jetzt senkrecht

Die Bahnen liegen nicht mehr als breite Zeilen untereinander, sondern
stehen **nebeneinander und laufen nach unten** — so, wie man am Becken
davorsteht. Die Namen stehen in der Bahn untereinander.

Bei wenigen Bahnen werden die Spalten breiter, damit ganze Namen
hineinpassen; sind es mehr, als ins Bild passen, **scrollt das Becken
seitlich** statt der ganzen Seite. Auf dem Handy passen zwei Bahnen ins
Bild, die nächste schaut heran.

Ziehen und Antippen funktionieren unverändert, und auch das Mitlaufen am
oberen und unteren Rand bleibt.

**Das PDF ist mitgewandert:** Auch dort stehen die Bahnen nebeneinander,
bis zu sechs pro Block, darunter folgt der nächste Block. Wer nicht
zugeteilt ist, steht weiterhin darunter.

## Nebenbei behoben

**Im PDF sass der zweite Name auf dem Rahmen.** Der Kasten einer Bahn war
genau so hoch wie die Zeilen darin, ohne Luft unter der letzten
Grundlinie. Aufgefallen ist das erst beim Ansehen des fertigen Blattes —
keine inhaltliche Prüfung hatte etwas zu beanstanden. Dafür gibt es jetzt
eine Prüfung der Masse (siehe unten).

## Geprüft

* **119 Prüfungen** gegen echtes SQL, davon 20 neue für die Verspätung:
  Eintragen mit und ohne Grund, Ändern, Zurücknehmen samt Grund, das
  automatische „anwesend", Kappen bei 720 Minuten und 200 Zeichen,
  fremde Personen, Zählung in der Statistik je Person und für die
  Mannschaft sowie im Verlauf.
* **98 Prüfungen** im Browser, darunter der ganze Weg durch die
  Verspätung (Angebot, Eingabe, Eingabetaste, Abbrechen, Entfernen,
  Zustand zurücknehmen) und die senkrechte Anordnung der Bahnen.
* **63 Prüfungen** auf 320 bis 768 Pixeln und mit dem Finger: die
  aufgeklappte Verspätungseingabe läuft nirgends aus dem Bild, die
  Felder sind gross genug, die Bahnen stehen nebeneinander, und wo sie
  nicht ins Bild passen, scrollt das Becken.
* **Neu: eine Prüfung der Masse im fertigen PDF.** Sie liest die
  gezeichneten Kästen und Textgrundlinien aus der Datei und besteht
  darauf, dass unter der letzten Zeile Luft bleibt. Gegengeprobe mit der
  fehlerhaften Fassung: Sie schlägt an. (Beim ersten Anlauf tat sie das
  nicht — jsPDF schreibt Höhen negativ, wodurch die Prüfung alle Kästen
  verwarf und ins Leere lief.)
