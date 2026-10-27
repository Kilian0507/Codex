# LSV07 Interner Bereich — 8.32.0

## Behoben: nach dem Saisonwechsel standen die alten Stunden in der Abrechnung

Gemeldet war: Die Trainingszeiten wurden auf die neue Saison umgestellt, in
der Abrechnung kamen trotzdem weiter die alten Stunden an.

### Woran es lag

Nicht an der Abrechnung, sondern eine Stufe davor. Beim Anlegen einer
Anwesenheit suchte der interne Bereich den passenden Trainings-Slot so:

```sql
SELECT id FROM lsv07i_training_slots
 WHERE mannschaft_id = %d AND wochentag = %d
 ORDER BY zeit_von ASC LIMIT 1
```

**Die Saison kommt darin nicht vor.** Nach einem Wechsel gibt es dieselbe
Mannschaft am selben Wochentag aber zweimal — einmal mit der alten Zeit,
einmal mit der neuen. Beginnen beide zur selben Stunde (18:00–19:30 alt,
18:00–20:00 neu), entscheidet die Reihenfolge der Datenbank, und das ist in
aller Regel die kleinere Nummer: **der Slot der alten Saison.**

Die Anwesenheit hing daran. Und alle Stellen, die daraus Stunden rechneten,
rechneten folgerichtig die alten — auch Monate nach der Umstellung.

### Dass es vier Stellen waren, machte es schlimmer

Dieselbe Frage — „wie lang war dieses Training?" — wurde an vier Orten
beantwortet, jeder für sich:

| Wo | Notzahl, wenn nichts gefunden wurde |
|---|---|
| Haken „anwesend" bei einem Trainer | 1,5 Std. |
| Knopf „Abrechnen" im Mannschaftsbuch | 2,0 Std. |
| Übernahme in die Abrechnung | mindestens 0,5 Std. |
| Slot-Suche beim Absagen eines Trainings | — |

Drei verschiedene Notzahlen waren das kleinere Problem. Das grössere: Weil
immer irgendetwas Plausibles dastand, **fiel eine fehlende oder falsche
Trainingszeit nie auf.** Es sah jedes Mal aus wie ein Ergebnis.

### Was jetzt gilt

Es gibt **eine** Stelle, `LSV07I_Trainingszeit`, und alle fragen sie.
Massgeblich ist die Zeit, die an diesem **Datum galt**:

1. Der Slot der Mannschaft in der **Saison des Datums**, am Wochentag des
   Datums — die Trainingszeit laut Plan.
2. Sonst ein Slot **ohne Saisonbezug** (`saison_id = 0`, aus der Zeit vor
   der Saison-Spalte). Von dem weiss man wenigstens, dass er schon galt;
   der einer fremden Saison sagt über diesen Tag gar nichts.
3. Sonst der Slot, an dem die **Anwesenheit** hängt. Ein verlegtes Training
   — Ferien, Hallenschliessung — steht dort richtig, auch wenn der Wochentag
   nicht passt.
4. Sonst irgendeiner am passenden Wochentag.
5. Sonst **gar keine**: 0 Stunden und ein „Trainingszeit fehlt". Keine
   Notzahl mehr. Wer 0,00 sieht, trägt nach — und sieht wenigstens, dass
   etwas nachzutragen ist.

Dieselbe Reihenfolge entscheidet auch, **an welchem Slot eine neue
Anwesenheit hängt**. Sie steht nur an einer Stelle im Code: Stünde sie
zweimal da, hinge die Anwesenheit am einen Slot und die Stunden kämen aus
dem anderen — derselbe Fehler noch einmal, nur schwerer zu finden.

Ein **Platzhalter-Slot** (00:00–00:00), wie ihn der interne Bereich anlegt,
wenn eine Mannschaft gar keine Trainingszeit hat, bekommt jetzt die Saison
des Datums mit. Vorher stand dort `saison_id = 0`, und die nächtliche
Reparatur schlug ihn der **ältesten** Saison zu — wieder eine alte Zeit an
einem neuen Training.

### Die bereits falschen Zeilen

Die Ursache ist behoben, aber was vorher entstanden ist, steht weiter falsch
in der Datenbank. **Offene Abrechnungen ziehen die Stunden beim Öffnen
nach** — und die Verwaltungsliste tut es ebenso, damit der Wart nicht nach
einer Zahl prüft, die sich beim Anklicken ändert.

Angefasst wird dabei nur, was angefasst werden darf:

* **Nur offene Abrechnungen** (Entwurf, zurückgegeben). Eingereichtes,
  Genehmigtes und Bezahltes bleibt, wie es ist — geprüft und genehmigt wurde
  genau das, was damals dastand.
* **Nur übernommene Zeilen** (`anwesenheit`, `springer`). Von Hand erfasste
  Tage bleiben unberührt.
* **Nur ohne Korrektur von Hand.** Dafür gibt es die neue Spalte
  `stunden_plan`: Sie hält fest, was der Plan vorsah. Weicht `stunden` davon
  ab, hat jemand bewusst eingegriffen, und dann bleibt seine Zahl stehen —
  nur der Plan-Wert daneben wird aufgefrischt, damit die Abweichung
  weiterhin stimmt.
* **Kommt keine Zeit heraus** (Slot inzwischen gelöscht), bleibt die Zeile
  stehen. Eine vorhandene Angabe durch 0 zu ersetzen wäre schlechter als sie
  zu lassen.

**Ein Punkt, der offen gesagt gehört:** Zeilen aus der Zeit vor
`stunden_plan` tragen keine Marke. Dort ist nicht zu erkennen, ob jemand von
Hand korrigiert hat. Sie werden nachgezogen — denn genau diese Zeilen sind
die gemeldeten falschen. Eine frühere Korrektur von Hand wird dabei
**einmalig überschrieben**. Damit das nicht stillschweigend geschieht, steht
jedes Nachziehen im Protokoll: wie viele Tage, von welcher Summe auf welche,
und wie viele davon ohne Marke waren.

### In der Oberfläche

Weicht die Zahl eines Trainingstags von der Trainingszeit des Tages ab,
steht **„2,00 laut Plan"** daneben. Vorher war nach einer Korrektur nicht
mehr zu sehen, dass überhaupt korrigiert wurde — und der Wart prüft genau
das.

### Nebenbei behoben

Die Slot-Suche beim **Absagen** eines Trainings war eine eigene, dritte
Kopie derselben Abfrage. Sie konnte auf den Slot der einen Saison zeigen,
während die Anwesenheit am Slot der anderen hing — dann wurde das abgesagte
Training nicht wiedererkannt und trotzdem abgerechnet. Auch sie geht jetzt
durch dieselbe Stelle.

Die gemerkten Trainingszeiten werden verworfen, sobald ein Slot gespeichert
oder gelöscht oder eine Saison angelegt, geändert oder aktiviert wird. Sonst
rechnete ein Vorgang in derselben Anfrage noch mit der Zeit von vorher.

## Geprüft

57 neue Prüfungen (`saisontest.php`) mit zwei Saisons, gleichem Beginn am
selben Wochentag, Anwesenheiten davor und danach, verlegten Trainings,
Anlagen ohne Saisons, zwei Gruppen nacheinander und dem Weg über den
Endpunkt. Die bestehenden 396 weiterhin grün.

Jede Zusicherung wurde **gegengeprüft**: absichtlich gebrochen, und
nachgesehen, ob die Prüfung es meldet. Dabei fielen drei Löcher in der
Prüfung selbst auf — eine Zusicherung, die auch nach dem Bruch grün blieb,
weil ein zweiter Weg zufällig dasselbe Ergebnis lieferte. Der gemeldete
Fehler war monatelang unbemerkt geblieben, weil die Abrechnung plausibel
aussah; eine Prüfung, die das nicht anzeigt, hätte ihn genauso wenig
gefunden.
