# LSV07 Interner Bereich — 8.19.0

## Splitscreen im Vollbild, einklappbare Menüleiste, klarer getrennte Sessions

### Splitscreen: zwei Trainingspläne nebeneinander

Im Vollbild gibt es den Knopf **Splitscreen**. Er öffnet eine Auswahl der
verfügbaren Pläne — eigene und von anderen Trainern freigegebene, jeweils mit
Anzahl der Sessions und Ersteller. Der bereits geöffnete Plan steht dort
nicht noch einmal.

Nach der Auswahl stehen **beide Pläne nebeneinander**, jeder in einer eigenen
Spur mit eigener Kopfzeile:

- **Jede Spur wird einzeln bedient.** Eine Übung antippen vergrößert sie nur
  in *dieser* Spur; die andere bleibt, wie sie ist. Jede Spur hat dafür ihre
  eigenen Knöpfe („Alle Übungen", ‹ ›) und ein eigenes ✕.
- **Die Pfeiltasten** wirken auf die zuletzt angetippte Spur — die ist am
  blauen Rahmen erkennbar.
- **Auch PDFs** lassen sich so vergleichen: jede Spur kann ihre eigene PDF
  zeigen. (Die Ansicht ganz ohne Bedienelemente bleibt dem Einzelplan
  vorbehalten — im Splitscreen braucht jede Seite ihre eigene Navigation.)
- **Schließen:** das ✕ einer Spur lässt den anderen Plan als einzelnen
  stehen; „Splitscreen beenden" und Esc führen zurück auf einen Plan.
- **Auf schmalen Geräten** (unter 900 px) stehen die Spuren untereinander
  statt nebeneinander — nebeneinander wäre auf einem Telefon nicht mehr
  lesbar.
- Die Schrift ist im Splitscreen etwas kleiner angesetzt, weil je Spur nur
  die halbe Breite da ist. Der +/−-Regler wirkt weiterhin voll und auf beide
  Spuren zugleich.

Die Plan-Auswahl liegt bewusst **innerhalb** der Vollbild-Ebene: im echten
Vollbild zeichnet der Browser nur deren Teilbaum — ein gewöhnliches Fenster
wäre schlicht unsichtbar geblieben.

### Menüleiste einklappen

Neben dem Schließen-Knopf sitzt jetzt ein **Einklapp-Knopf** (⌃). Er räumt
die Menüleiste (und die Fortschrittsleiste darunter) weg, sodass die Übungen
den ganzen Bildschirm bekommen — am Beckenrand zählt jeder Zentimeter.

- **Ein Doppelklick auf die Fläche holt sie zurück**, wie gewünscht.
- Damit niemand raten muss, bleibt oben ein **schmaler Griff** stehen: ein
  Klick darauf genügt ebenfalls. Auch Esc klappt sie wieder aus.
- Der erste Klick auf eine Übung wird bei eingeklappter Leiste kurz
  zurückgehalten, damit er einen Doppelklick nicht überholt — ein
  Doppelklick öffnet also keine Übung versehentlich. Bei ausgeklappter
  Leiste reagiert alles unverändert sofort.
- Eingeklappt bleibt eingeklappt: In der PDF-Ansicht, wo die Leiste sonst
  bei einer Mausbewegung kurz zurückkommt, bleibt sie jetzt weg — das war
  eine ausdrückliche Entscheidung des Nutzers.

### Sessions im Editor deutlich abgesetzt

Im Trainingsplan-Editor standen die Sessions als weiße Kästen auf weißem
Grund und liefen ineinander. Jetzt hat jede eine **eigene getönte Fläche**,
im Wechsel grau und blau, dazu eine kräftige blaue Kante links und eine
Trennlinie unter der Session-Überschrift. Die Eingabefelder heben sich davon
ab, und die Session, in der gerade getippt wird, tritt zusätzlich hervor.

Die Farben kommen aus den Design-Tokens statt aus fest verdrahteten Werten —
im dunklen Erscheinungsbild stimmt es damit ebenso. (Vorher stand dort ein
festes Hellgrau, das im Dunkeln falsch war.)

### Zwei Fehler nebenbei behoben

- Der neue Griff und die Plan-Auswahl wurden von der Regel `#tp-vollbild > *`
  auf `position:relative` gezwungen. Der Griff saß dadurch links statt mittig
  und schob den Inhalt nach unten, die Auswahl stand im Textfluss statt über
  der Ebene. Beide sind jetzt korrekt positioniert — und durch Prüfungen
  abgesichert.

### Geprüft

- 63 Prüfungen in der echten Oberfläche: Tönung und Wechsel der Sessions
  (hell und dunkel), Einklappen samt Doppelklick, Griff und Esc, dass ein
  Doppelklick keine Übung öffnet, Öffnen und Abbrechen der Plan-Auswahl,
  zwei Spuren nebeneinander mit gleicher Breite und eigenen Titeln, getrennte
  Bedienung je Spur, Pfeiltasten auf der aktiven Spur, Schließen einer Spur,
  Beenden per Knopf und per Esc, sowie das Untereinander auf 900 px.
- Bestehende Prüfungen unverändert grün: Vollbild-Übungen (58),
  Trainingsplan-PDFs (41 + 5 + 51), Abrechnung (30 + 19 + 23), statische
  Prüfung, Klick-Durchlauf über alle fünf Nutzerarten (90 Klicks, 0 Fehler).
