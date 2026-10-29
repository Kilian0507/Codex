# LSV07 Abrechnung — 1.12.0

## Die Abschnitte beim Wettkampf sind anzugeben

Beim Wettkampf wird mit der Zahl der Abschnitte gerechnet: Abschnitte ×
Betrag je Abschnitt (Vorgabe 30 €). Das Feld gab es schon — aber es war
mit dem **geplanten** Wert des Wettkampftages vorbelegt. Wer den Dialog
durchklickte, rechnete damit den Plan ab, auch wenn er nur bei der Hälfte
der Abschnitte dabei war. Niemand musste etwas angeben, und niemand wurde
gefragt.

Jetzt ist die Zahl eine **Pflichtangabe**. Wie viele Abschnitte jemand
wirklich dabei war, weiss nur er selbst.

### Was sich im Dialog ändert

| Woher die Zahl kommt | Vorher | Jetzt |
|---|---|---|
| Aus der **eigenen Wettkampf-Anwesenheit** (interner Bereich oder SwimSys) | stand im Feld | steht im Feld — sie ist gemessen, nicht geplant. Daneben steht, woher sie kommt und dass Abweichungen einzutragen sind. |
| Nur die **geplanten** Abschnitte des Tages | standen im Feld | Das Feld bleibt **leer**. Der Plan steht daneben, mit einem Knopf „Auf N setzen". |
| Weder das eine noch das andere | 1 | Das Feld bleibt leer. |

Ohne Zahl lässt sich der Posten nicht speichern. Die Meldung steht am
Feld — nicht als Hinweis über dem Dialog, damit zu sehen ist, wo es
fehlt — und sie sagt, was fehlt: „Bitte angeben, bei wie vielen
Abschnitten Sie dabei waren."

Genauso wird es bei den Trainingsstunden gehalten: Dort steht der Plan
daneben und man trägt ein, was wirklich war.

### In der Wettkampf-Auswahl

Die Liste der Wettkampftage zeigt keinen Gesamtbetrag mehr. Solange die
Anzahl nicht angegeben ist, gibt es keinen — der Betrag aus dem Plan wäre
eine Zahl, die später nicht stimmt. Stattdessen steht dort der Satz **je
Abschnitt** und bei einem nur geplanten Tag der Satz „Anzahl bitte selbst
angeben".

### Der Server verlangt es auch

Die Prüfung steht nicht nur im Browser:

- Ohne Zahl oder mit 0 wird der Posten abgewiesen.
- Mehr als **40** Abschnitte an einem Tag werden abgewiesen. Ein
  Tippfehler („400") hätte sonst 12.000 € ergeben. Die Grenze ist mit
  Absicht weiter als die 20, die der interne Bereich und SwimSys bei der
  Wettkampf-Anwesenheit zulassen: Eine zu enge Grenze kostet einen
  Trainer sein Geld, eine zu weite lässt nur einen Tippfehler durch, den
  die Prüfung ohnehin sieht.
- Halbe Abschnitte gibt es nicht. Aus „2,7" werden 2 — nicht 2,7 × 30 €.

### Geprüft

- **117 Prüfungen** (`abrtest.php`), darunter sechs neue für die
  Pflichtangabe, die Obergrenze und den abgeschnittenen halben Abschnitt.
- **23 Prüfungen in einem echten Browser** (`abrwkui.js`): das leere
  Feld, der Plan daneben, der Knopf, die Meldung am Feld, und dass ohne
  Zahl **nichts** an den Server geht.
- **16 Gegenproben** (`abrwkgegenprobe.py`): jede dieser Zusicherungen
  einmal absichtlich gebrochen, um nachzusehen, ob eine Prüfung es
  meldet. Alle sechzehn greifen.
