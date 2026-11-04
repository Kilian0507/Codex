# Interner Bereich 9.2.0 — das Mannschaftsbuch als Startseite, und jeder Trainer seine Knöpfe

Zwei Dinge aus dem Alltag:

1. Über dem Mannschaftsbuch stand noch eine zweite Menüleiste. Man hatte
   oben die Kacheln der Bereiche, darunter die Kacheln der Gruppen — und
   dann noch eine Reiterleiste. Drei Ebenen für einen Klick.
2. In der Anwesenheit standen sechs Knöpfe je Sportler. Die meisten
   Trainer benutzen drei davon; die anderen drei stehen im Weg, bei
   zwanzig Sportlern zwanzig Mal.

## 1. Das Mannschaftsbuch ohne zweite Menüleiste

Die Gruppe **Mannschaftsbuch** hat keine Reiterleiste mehr. Was früher
als Reiter darüber stand, steht jetzt **im Buch selbst, untereinander als
Kacheln**:

Anwesenheit · Mannschaften · Atteste · Bestzeiten · Reflexion · Akte ·
Trainer — jeweils nur, soweit das Konto sie sehen darf.

Jede Kachel sagt in einer Zeile, was dort zu finden ist. Ein Tippen
öffnet sie, und oben erscheint **„‹ Zurück zum Mannschaftsbuch"**. Im
Buch selbst ist dieser Rückweg weg — dort ist man ja schon.

Drei Regeln, damit das nicht auseinanderläuft:

* **Die Kacheln werden aus den Reitern erzeugt**, die dieses Konto
  sehen darf. Es gibt keine zweite Liste, die man pflegen müsste, und
  keine Kachel zu einem Teil, auf den man kein Recht hat. Ein neuer
  Reiter ist automatisch eine neue Kachel.
* **Das Buch ist keine Kachel in sich selbst.**
* **Die Anwesenheit bleibt als Kachel stehen** — siehe unten.

Technisch hängt das an einer einzigen Kennzeichnung der Gruppe
(`data-kacheln`). Alle anderen Gruppen behalten ihre Reiterleiste — auch
das Mannschaftsbuch in Triathlon und Fitness, denn dort gibt es kein
Buch, in das man Kacheln legen könnte.

### Die Anwesenheit bleibt als Kachel — bewusst

Gewünscht war: „Anwesenheit kann weg, weil das schon drin ist." Im Buch
steht aber nur die Anwesenheit **einer** Trainingszeit an **einem** Tag.
Im Panel Anwesenheit stehen zwei Dinge, die es sonst nirgends gibt:

* die **Wettkampf-Anwesenheit** (wer fährt mit?),
* die **Trainingsausfälle** (Training fällt aus, für alle).

Die Kachel wegzunehmen hiesse, beides unerreichbar zu machen. Darum
steht sie da — mit dem Hinweis *„Wettkampf-Anwesenheit und
Trainingsausfälle. Die Anwesenheit eines Trainings steht oben im Buch."*
Wenn die Kachel trotzdem weg soll, sagt Bescheid; dann wandern diese
zwei Teile vorher ins Buch.

## 2. Jeder Trainer stellt seine Anwesenheits-Knöpfe selbst ein

In der Anwesenheitskarte steht ein Knopf **„Knöpfe…"**. Dahinter wählt
man aus den sechs Zuständen die aus, die man braucht.

**Standard sind drei:** anwesend · abwesend · entschuldigt.
Dazu wählbar: krank · verletzt · beurlaubt.

Vier Regeln:

* **Die Einstellung gehört dem Konto, nicht der Mannschaft.** Zwei
  Trainer am selben Buch können verschiedene Knöpfe haben. Es ist eine
  Ansicht, keine Vereinsregel — niemand kann einem anderen Trainer damit
  etwas wegnehmen.
* **„Anwesend" ist immer dabei** und lässt sich nicht abwählen. Ohne ihn
  wäre die Anwesenheit nicht mehr zu führen.
* **Eine leere oder einzelne Auswahl ergibt den Standard.** Ein
  versehentlich leeres Häkchenfeld lässt niemanden ohne Knöpfe
  zurück.
* **Ein ausgeblendeter Zustand bleibt sichtbar, wo er gesetzt ist.** Wer
  „krank" ausblendet, sieht bei einem Sportler, der als krank eingetragen
  ist, trotzdem „krank" — sonst sähe es aus, als wäre nichts eingetragen,
  und der nächste Klick überschrieb es unbemerkt.

Der Server nimmt weiterhin **alle sechs** Zustände an. Die Einstellung
ist eine Ansicht; sie darf nicht dazu führen, dass ein Eintrag eines
Kollegen nicht mehr gespeichert werden kann. Gespeichert werden nur die
sechs bekannten Namen — was sonst ankommt, wird weggeworfen, und
geschrieben wird nur mit dem Recht am Mannschaftsbuch.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Mannschaftsbuch, Knöpfe (echte Klasse, echtes SQL) | 173 | 8 von 8 |
| Kacheln und Knöpfe (Oberfläche, echter Browser) | 36 | 14 von 14 |
| Navigation (Oberfläche, echter Browser) | 43 | 12 von 12 |
| Versetzen aus dem Buch (Oberfläche) | 17 | 6 von 6 |

Dazu unverändert grün: Navigation am Server (154), Rechte (47 / 20 von
20), Rechte-Oberfläche (31 / 12 von 12), Einlesen (88 / 24 von 24),
Einlesen-Oberfläche (39 / 14 von 14), Versetzen am Server (34 / 12 von
12), Trainingsplan-Vollbild (25 / 7 von 7) und der Rauchtest über 54
Klicks ohne einen JavaScript-Fehler.

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin, nicht in einer Kopie. Bleibt die
Prüfung dabei grün, prüft sie nichts und ist wertlos.
