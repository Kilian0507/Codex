# LSV07 Interner Bereich — 8.28.0

## Standardmannschaft

Wer mehrere Mannschaften betreut, musste bisher jedes Mal erst
auswählen, um welche es geht. Im Mannschaftsbuch steht neben der
Beschriftung *Mannschaft* nun ein kleiner Schalter:

* **☆ Als Standard** — legt die gerade gewählte Mannschaft fest.
* **★ Standard** — sie ist festgelegt; ein zweiter Druck hebt das wieder
  auf.

Beim nächsten Öffnen ist sie vorgewählt und der Trainingstag wird
sofort geladen. Der Schalter erscheint nur, wenn es überhaupt etwas zu
wählen gibt — bei einer einzigen Mannschaft ist die Frage keine.

**Die Festlegung gilt auch im Reiter „Anwesenheit".** Es ist dieselbe
Frage und dieselbe Person; dort wird die Mannschaft ebenfalls
vorgewählt. Geladen wird nach wie vor erst auf Knopfdruck — vorgewählt
heisst nicht geöffnet.

Gespeichert wird die Festlegung bei der Person, nicht bei der
Mannschaft. Jede Trainerin und jeder Trainer hat also eine eigene.

**Sie altert nicht still vor sich hin:** Bei jedem Lesen wird geprüft,
ob die Mannschaft noch zu der Person gehört. Wer eine Mannschaft abgibt,
bekommt sie nicht weiter vorgesetzt, sondern wieder die freie Auswahl.

## Geprüft

* **134 Prüfungen** gegen echtes SQL, davon 15 neue: festlegen, wechseln,
  aufheben, eine Mannschaft, die es nicht gibt, eine inzwischen fremde
  Mannschaft (weder vorgesetzt noch festlegbar) und das Leserecht.
* **112 Prüfungen** im Browser: der Schalter in allen drei Zuständen, das
  Mitwandern beim Wechsel der Mannschaft, die Vorauswahl beim nächsten
  Öffnen und die Vorauswahl im Reiter „Anwesenheit".
