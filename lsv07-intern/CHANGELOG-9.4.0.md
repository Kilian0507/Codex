# Interner Bereich 9.4.0 — Benachrichtigungen ploppen auf, und jeder legt sich die Kacheln, die er braucht

## 1. Benachrichtigungen ploppen beim Start auf

Eine Benachrichtigung der Administration stand bisher nur hinter der
Glocke auf der Startseite. Wer nicht auf den kleinen roten Punkt achtete,
hat sie nie gelesen — und bei einer Mitteilung, die alle angeht, ist das
der ganze Zweck verfehlt.

Jetzt **ploppt sie beim Start auf**: in einem Fenster, mit Titel, Text,
Absender und Datum. Darunter je Benachrichtigung ein Knopf **„Als gelesen
markieren"**, bei mehreren zusätzlich **„Alle als gelesen markieren"**.

Vier Regeln:

* **Es ploppt nur das Ungelesene auf.** Was gelesen ist, steht nicht mehr
  im Weg.
* **Gelesen heisst nicht weg.** An der Glocke steht die Benachrichtigung
  weiter — mit dem Tag, an dem sie gelesen wurde.
* **„Später" schliesst nur das Fenster.** Gelesen ist dadurch nichts,
  also ploppt es beim nächsten Start wieder auf. Sonst hätte man eine
  Mitteilung mit einem Klick für immer weggewischt, ohne sie gelesen zu
  haben.
* **Gelesen gilt je Konto.** Sonst hätte der erste, der sich anmeldet,
  für den ganzen Verein gelesen.

Dazu, weil es sonst auseinanderläuft:

* **Es ploppt in jedem Bereich auf**, nicht nur auf der Startseite — wer
  direkt im Mannschaftsbuch landet, bekommt es genauso.
* **Zähler und Fenster kommen aus einer Antwort.** Zwei Anfragen wären
  zwei Zeitpunkte; dann stünde womöglich eine 1 an der Glocke, zu der
  kein Fenster gehört.
* **„Alle" ist nichts anderes als „jede einzelne"** und geht durch
  dieselbe Schreibstelle. Zwei Schreibwege würden auseinanderlaufen: Der
  eine füllte eines Tages die Lesebestätigung der Administration und der
  andere nicht.
* **Was „ungelesen" heisst, steht an genau einer Stelle** — daran hängen
  der Zähler, das Fenster und das „alles als gelesen".

## 2. Schnellzugriff: eine Kachel für jedes Modul, auf das man ein Recht hat

Unter *Schnellzugriff* auf der Startseite standen **13** Kacheln zur
Wahl — bei über **40** Ansichten. Wer die Atteste betreute, den
Trainingsplan pflegte, das Log lesen durfte oder die Saisons verwaltete,
konnte sich dafür keine Kachel legen, obwohl er das Recht dazu hatte. Das
war keine Entscheidung, sondern eine Liste, die nicht mitgewachsen ist.

Jetzt gibt es **für jede Ansicht eine Kachel** — und jeder bekommt genau
die zur Wahl gestellt, auf die er ein **Lese- oder Bearbeitenrecht** hat:

| Bereich | Neu dazu |
|---|---|
| Schwimmen | Mannschaftsbuch, Atteste, Akte, Trainerübersicht, Meldungen, Trainingsplan |
| Triathlon / Fitness | je Trainer |
| Trainer | Sonderabrechnung, Meine Sportler, Stammdaten |
| Verwaltung | Eingereichte Abrechnungen, Kassenwart |
| Kommunikation | Tickets |
| Administration | Daten einlesen, Personen-Assistent, Bestzeiten einlesen, Rechte, Saisons, Mannschaften, Trainingszeiten, Stammdaten, Konfiguration, Personen, Trainer, Sportler, Atteste, Benachrichtigungen, Mails, Log |

Jede Kachel trägt ihr eigenes Symbol und einen Satz dazu, was dort zu
finden ist.

**Die Kachel hängt am Recht ihres Reiters — am selben Schlüssel.** Sie
rechnet nicht selbst nach, wer was darf; die Frage beantwortet die
Zugriffskarte, aus der auch die Reiter kommen. Damit kann beides nicht
auseinanderlaufen: Ein Reiter, den jemand sehen kann, hat seine Kachel,
und eine Kachel ohne sichtbaren Reiter gibt es nicht. Ein
Bearbeitenrecht schliesst das Leserecht ein, darum genügt der eine
Schlüssel für beides.

Dazu:

* **Keine Kachel, die nichts öffnet.** Geprüft ist, dass jedes Ziel ein
  Panel hat, das es wirklich gibt — und dass jeder Reiter der Vorlage
  eine Kachel hat. Fehlt eine, meldet die Prüfung sie beim Namen.
* **Eine Kachel öffnet ihr Ziel auch dann**, wenn ihr Bereich keine
  eigene Schaltfläche hat (Tickets) oder ihr Panel in einer Gruppe
  steckt, die beim Start zu war (Log).
* **Das Symbol kommt aus dem Katalog**, nicht aus einer Liste in der
  Oberfläche — sonst trügen alle Admin-Kacheln dasselbe Zeichen.
* **Das Recht geht nicht in den Browser.** Es ist eine Angabe über die
  Rechteverwaltung und hat dort nichts zu suchen.
* **Gespeichert wird nur, was angeboten wurde.** Eine zugeschickte ID,
  die dieses Konto nicht wählen darf, wird verworfen.
* Der alte Schnellzugriff **„Personenverwaltung"** entfällt: Sein Ziel
  (`i-p-pers`) gibt es seit 9.0.0 nicht mehr — die Personen stehen unter
  Admin → Personen, und dafür gibt es jetzt eine eigene Kachel. Wer ihn
  gewählt hatte, verliert nichts weiter: Er wird nicht mehr angeboten
  und darum nicht mehr gezeichnet.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Benachrichtigungen (echte Klasse, echtes SQL) | 33 | 12 von 12 |
| Benachrichtigungs-Popup (Oberfläche, echter Browser) | 32 | 14 von 14 |
| Katalog der Schnellzugriffe (echter Katalog gegen echte Vorlage) | 22 | 12 von 12 |
| Schnellzugriffe (Oberfläche, echter Browser) | 18 | 7 von 7 |

Unverändert grün: Abrechnungsweg 41 (10 von 10) und 30 (14 von 14),
Navigation im Markup 161 (11 von 11), Navigation (Oberfläche) 41,
Mannschaftsbuch 173 (8 von 8), Kacheln und Knöpfe 36 (14 von 14), Rechte
47 (20 von 20), Rechte-Oberfläche 31 (12 von 12), Einlesen 88 (24 von
24), Einlesen-Oberfläche 39 (14 von 14), Versetzen 34 (12 von 12) und 17
(6 von 6), Trainingsplan-Vollbild 25 (7 von 7).

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft
sie nichts und ist wertlos.
