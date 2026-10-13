# LSV07 Interner Bereich — 8.26.0

## Neu: Mannschaftsbuch

Im Schwimmbereich steht ein neuer Reiter **Mannschaftsbuch** — das
Arbeitsheft einer Mannschaft. Ein Trainingstag, eine Seite.

Oben stehen Mannschaft und Datum, darunter drei Ansichten:
**Dashboard**, **Bahnen** und **Statistik**. Was täglich gebraucht wird,
liegt im Dashboard; Bahnenbelegung und Statistik haben eigene Ansichten,
weil beides Platz braucht und in der täglichen Erfassung nur im Weg
stünde.

Gespeichert wird sofort — jeder Zustand, jeder Eintrag, jede verschobene
Person. Ein Trainingsbuch, das erst auf einen Speichern-Knopf wartet,
verliert am Beckenrand zu leicht etwas. Nur die Dokumentation hat einen
eigenen Knopf, weil man darin schreibt und nicht tippt.

## Anwesenheit mit sechs Zuständen

Je Sportlerin und Sportler gibt es **Anwesend, Abwesend, Krank,
Entschuldigt, Beurlaubt** und **Unentschuldigt**. Ein Klick setzt den
Zustand, ein zweiter auf denselben nimmt ihn zurück. Jeder Zustand hat
seine Farbe, die Kopfzeile zählt mit: „4 von 12 anwesend · 3 offen".

**Die bisherige Anwesenheit bleibt, wie sie ist.** Beide arbeiten auf
demselben Trainingstag:

* Wird ein Tag geöffnet, für den im Buch noch nichts steht, kommen die
  Zustände aus dem Reiter „Anwesenheit" — gestrichelt umrandet und mit
  dem Vermerk **Altbestand**. Sie gelten, bis jemand sie im Buch
  bestätigt oder ändert.
* Wird im Buch ein Zustand gesetzt, wandert er zusätzlich in die alte
  Anwesenheit, heruntergebrochen auf deren drei Zustände: krank,
  entschuldigt und beurlaubt gelten dort als *entschuldigt*, abwesend
  und unentschuldigt als *abwesend*. Ohne das stünde im Reiter
  „Anwesenheit", in der Akte und im Saisonbericht niemand anwesend, nur
  weil der Tag im Buch geführt wurde.
* Wird ein Zustand zurückgenommen, verschwindet er auf beiden Seiten.

Der Kommentar, der im Reiter „Anwesenheit" zum Training steht, wird im
Buch angezeigt — zum Lesen, damit nichts übersehen wird.

## Bahnenbelegung mit Ziehen

Eigene Ansicht: Wer noch nicht zugeteilt ist, wartet oben am
Beckenrand, darunter stehen die Bahnen. Namen werden **am Griff
gezogen** und auf einer Bahn losgelassen; die Bahn darunter hebt sich
hervor. Am oberen und unteren Rand läuft die Seite mit, sonst liesse
sich auf dem Handy nicht zu einer Bahn ziehen, die gerade nicht im Bild
ist.

Ziehen ist nicht der einzige Weg: **Antippen geht auch.** Erst den
Namen antippen, dann die Bahn — der Hinweis über dem Becken sagt
jeweils, was als Nächstes kommt. Nur der Griff fängt die Berührung ab,
der Name selbst nicht; sonst liesse sich die Seite mit dem Finger nicht
mehr scrollen.

Bahnen lassen sich hinzufügen und wegnehmen (bis zwölf), die Belegung
des letzten Trainings lässt sich übernehmen, und das Ganze geht als
**PDF** aufs Blatt — mit Bahnen, Anzahl und, wer nicht zugeteilt ist,
auch denen.

## Vermerke

Für die **ganze Mannschaft** (Trainingsziele und Ähnliches) oder für
**eine einzelne Person** („sass draussen, Bauchweh"). Jeder Vermerk hat
eine Art — Trainingsziel, Hinweis oder Sonstiges — und erscheint in der
Statistik.

## Aufgaben

Je Person, mit fester Art: **Sachen nicht mitgebracht**, **Auf der Bank
gesessen** oder **Sonstiger Vermerk**. Aufgaben lassen sich abhaken und
wieder öffnen; die Statistik zählt die offenen.

## Dokumentation

Ein Freitext je Training: was gemacht wurde, worauf nächstes Mal
einzugehen ist, und was sonst noch wichtig war. In der Statistik steht
die Dokumentation aller Trainingstage des Zeitraums untereinander.

## Statistik

Für einen Zeitraum, wahlweise für die **ganze Mannschaft** oder eine
**einzelne Person**:

* je Zustand eine Kachel, dazu erfasste Teilnahmen, Anwesenheitsquote
  und offene Aufgaben
* eine Zeile je Person mit allen sechs Zuständen, Terminen, Quote sowie
  der Zahl ihrer Vermerke und Aufgaben
* bei einer einzelnen Person zusätzlich der Verlauf Tag für Tag
* alle Vermerke, Aufgaben und die Dokumentation des Zeitraums

Was aus der bisherigen Anwesenheit stammt, wird mitgezählt und eigens
ausgewiesen — oben in der Zeile und im Verlauf.

## Rechte

Zwei neue Rechte in der Rechteverwaltung unter *Schwimmen →
Mannschaftsbuch*: **sehen** und **führen**. Beide greifen wie bei der
Anwesenheit auch über die Bereichsrolle, das Buch ist also ohne
Rechte-Runde benutzbar. Unabhängig davon gilt immer: geschrieben wird
nur für die eigenen Mannschaften, Admin und Schwimmwart für alle. Wie im
Reiter „Anwesenheit" bleiben vergangene Saisons Administratoren
vorbehalten.

## Geprüft

* **95 Prüfungen** gegen echtes SQL: die sechs Zustände, Altbestand in
  beide Richtungen, das Rückschreiben und Aufräumen in der alten
  Anwesenheit, Vermerke, Aufgaben, Dokumentation, Bahnenbelegung
  (doppelte Namen, fremde Personen, Höchstzahl, Vorlage), Statistik für
  Mannschaft und Einzelperson, Rechte, fremde Mannschaften und die
  Saisongrenze.
* **69 Prüfungen** im Browser: der ganze Weg durch alle drei Ansichten,
  Ziehen mit dem Zeiger samt Schattenbild und Zielmarkierung, Antippen
  als Ausweg, Bahnen mehr und weniger, Vorlage übernehmen und eine echt
  heruntergeladene PDF-Datei.
* **43 Prüfungen** auf 320, 360, 414 und 768 Pixeln Breite sowie mit dem
  Finger auf einem Telefon: kein seitlicher Überlauf in allen drei
  Ansichten, Zustandstasten gross genug und nirgends abgeschnitten,
  Ziehen mit dem Finger bis auf die Bahn.
