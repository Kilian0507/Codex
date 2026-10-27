# LSV07 Abrechnung — 1.11.0

## Löschen in Prüfung und Kasse

Ein Rückschritt hilft nicht gegen jeden Fehler. Eine doppelt erfasste
Abrechnung, ein Testeintrag aus der Einführungswoche, eine Rechnung am
falschen Konto — das muss weg und nicht auf einen früheren Stand. Darum
steht in **Prüfung** und **Kasse** bei jedem Vorgang ein **Löschen**, für
Quartalsabrechnungen wie für Rechnungen.

### Wer darf

| | Quartalsabrechnung | Rechnung |
|---|---|---|
| Administration | immer | immer |
| Wart | im eigenen Zuständigkeitsbereich | — |
| Abteilungsleitung | — | in der eigenen Abteilung |
| Kasse | ab *genehmigt* | ab *genehmigt* |
| die Person selbst | *Entwurf* | *Entwurf* und *zurückgegeben* |

Die Kasse bekommt erst ab *genehmigt* überhaupt etwas zu sehen; davor gibt
es für sie nichts zu löschen, weil es für sie nichts gibt. Wer die eigene
**eingereichte** Rechnung loswerden will, holt sie erst zurück — dann ist
sie wieder ein Entwurf.

Die Antwort auf „wer darf was" steht in **einer** Klasse
(`LSV07A_Loeschung`), und Prüfung, Kasse und Verwaltung fragen sie alle.
Auch der Knopf, mit dem jemand seinen eigenen Entwurf wegwirft, geht jetzt
dort durch. Zwei Listen von „wer darf" laufen auseinander, und bei einer
Löschung merkt man das daran, dass etwas weg ist, das nicht weg sein
sollte.

### Was dabei passiert

* **Eine Begründung ist Pflicht** — außer, jemand wirft seinen eigenen
  Entwurf weg. Der war nie eingereicht, es gibt nichts zu verantworten,
  und eine Pflichtabfrage wäre nur im Weg.
* **Vorher wird protokolliert**, was gelöscht wird: Konto, Zeitraum oder
  Betreff, Betrag, Stand und der Grund. Die Zeile verschwindet, die Spur
  bleibt. Erst protokollieren, dann löschen — danach ist nicht mehr zu
  ermitteln, was dort stand.
* **Die betroffene Person bekommt eine Mitteilung**, mit dem Grund. Wem
  etwas gelöscht wird, der soll es nicht daran merken, dass es fehlt.
* **Alles Zugehörige geht mit**: Posten, Notizen und Beanstandungen einer
  Abrechnung; bei einer Rechnung die Belege, auch von der Platte.
* **Der Dialog bleibt stehen**, solange keine Begründung dasteht, und das
  Getippte bleibt darin. Beim nächsten Mal ist das Feld wieder leer — ein
  alter Grund, der an einem neuen Vorgang klebt, wäre schlimmer als keiner.

### Was das mit der Aufbewahrungspflicht macht

Das gehört offen gesagt: Eine eingereichte, genehmigte oder bezahlte
Abrechnung ist ein Buchungsbeleg. **§ 147 AO und § 257 HGB verlangen zehn
Jahre Aufbewahrung**, und wer sie löscht, verstößt dagegen.

Das Plugin macht es trotzdem möglich. Es gibt echte Gründe dafür, und ohne
den Knopf bliebe nur der Weg über die Datenbank — der hilft niemandem und
protokolliert nichts. Die Verantwortung liegt beim Verein, und damit sie
wahrgenommen werden kann, **warnt der Dialog**, sobald der Vorgang über
*Entwurf* hinaus ist: mit den Paragraphen, und mit dem Hinweis, dass
protokolliert und mitgeteilt wird. Ein Entwurf ist kein Beleg — den löscht
man ohne Umstände.

## Die beiden Bereiche heißen jetzt anders

| vorher | jetzt |
|---|---|
| Meine Abrechnung | **Übungsleiterabrechnung** |
| Andere Abrechnung | **Rechnungen/Belege einreichen** |

Geändert in den Umschaltern, den Überschriften, der Plugin-Beschreibung,
dem Hinweis im Datenschutz-Vorschlagstext und im Handbuch. Auf dem
Rechnungsblatt steht statt „Andere Abrechnung" jetzt „Rechnung/Beleg" —
das ist eine Überschrift für ein einzelnes Dokument und keine
Bereichsbezeichnung.

## Nebenbei behoben: die Zuständigkeit antwortete für die falsche Person

`LSV07A_Zustaendig::unbeschraenkt( $uid )` fragte „ist das ein
Administrator?" **ohne die Nummer weiterzugeben** — geprüft wurde also die
gerade angemeldete Person, nicht die gefragte. Rief ein Administrator die
Frage für jemand anderen, kam „sieht alles" zurück, weil der *Fragende*
Administrator ist.

Betroffen war damit jede Stelle, die die Zuständigkeit eines fremden Kontos
anzeigt oder prüft — die Personenansicht und die Verwaltungslisten. Der
Fehler fiel erst auf, weil eine neue Prüfung die Grenzen des Warts beim
Löschen durchging und dabei das Falsche herausbekam.

## Geprüft

90 neue Prüfungen (`wegtest.php`) für jede Befugnis und jede Grenze, für
Protokoll, Mitteilung, Belegdateien und die Endpunkte; dazu 42 neue
Oberflächenprüfungen für die Namen, den Löschdialog, die Warnung und das
Pflichtfeld. Die bestehenden 1143 weiterhin grün.

Jede Zusicherung wurde **gegengeprüft** — absichtlich gebrochen, um
nachzusehen, ob eine Prüfung es meldet. Dabei fielen vier Lücken auf:

* Die Begründungspflicht war nur für Abrechnungen geprüft, nicht für
  Rechnungen.
* Das Tor vor dem Prüfungs-Endpunkt liess sich entfernen, ohne dass es
  auffiel.
* „Wer selbst löscht, bekommt keine Post darüber" stand **zweimal** im
  Code — die zweite Fassung liess sich ersatzlos streichen, ohne dass eine
  Prüfung es merkte. Sie war wirkungslos und ist jetzt weg; die Regel steht
  nur noch in `LSV07A_Nachricht::an()`.
* In der Oberfläche liess sich „bei einem Entwurf wird nicht gewarnt"
  entfernen, ohne dass es auffiel: In der geprüften Liste stand gar kein
  Entwurf. Jetzt steht einer darin, und die Prüfung öffnet den Dialog auch
  für ihn.

Alle 24 Zusicherungen am Code und alle 15 an der Oberfläche greifen jetzt
wirklich.
