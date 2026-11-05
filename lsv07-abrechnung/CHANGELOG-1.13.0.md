# Abrechnung 1.13.0 — einen einzelnen Beleg löschen, auch aus dem Archiv

Bisher liess sich ein Beleg nur entfernen, solange seine Rechnung noch ein
**Entwurf** war. Danach stand da: *„Diese Rechnung ist eingereicht — ihr
Beleg bleibt, wie er ist."* Wer einen Beleg am falschen Vorgang hatte,
einen versehentlich mitgeladenen Kontoauszug loswerden musste oder eine
Datei mit Daten, die dort nichts zu suchen haben, hatte zwei
Möglichkeiten: die **ganze Rechnung** löschen — oder den Weg über die
Datenbank, der nichts protokolliert und niemandem hilft.

Jetzt gibt es den Schnitt, der dazwischen fehlte.

## Der Knopf

In der Ansicht einer Rechnung — aus **Prüfung**, **Kasse** und **Archiv**
dieselbe — steht bei jedem Beleg **„Endgültig löschen"**.

**Die Rechnung bleibt stehen.** Weg ist nur ihr Nachweis, und zwar auch
aus dem Jahrespaket, das sich danach ohne diese Datei packt.

Solange die Rechnung noch bearbeitet wird, bleibt der kurze Weg, der schon
da war: **„Entfernen"**, ohne Begründung. Der Entwurf war nie eingereicht,
es gibt nichts zu verantworten. Je Beleg steht genau einer der beiden
Knöpfe da — welcher, sagt der Server.

## Wer darf

| | Quartalsabrechnung | Rechnung | **einzelner Beleg** |
|---|---|---|---|
| Administration | immer | immer | **immer** |
| Wart | im eigenen Bereich | — | **—** |
| Abteilungsleitung | — | in der eigenen Abteilung | **in der eigenen Abteilung** |
| Kasse | ab *genehmigt* | ab *genehmigt* | **ab *genehmigt*** |
| die Person selbst | *Entwurf* | *Entwurf*, *zurückgegeben* | ***Entwurf*, *zurückgegeben*** |

**Der Beleg folgt der Rechnung — dieselbe Spalte, dieselbe Methode.** Ein
Beleg ist der Nachweis zu genau dieser Buchung; wer die Buchung löschen
darf, darf auch ihren Nachweis löschen, und wer sie nicht löschen darf,
auch den Nachweis nicht. Zwei Antworten auf eine Frage wären eine zu viel,
und bei einer Löschung merkt man den Unterschied daran, dass etwas weg
ist, das nicht weg sein sollte.

## Unwiderruflich — und was das heisst

**Die Datei wird vor dem Löschen überschrieben.** `unlink()` allein
entfernt nur den Verzeichniseintrag; die Blöcke bleiben stehen, bis das
Dateisystem sie wiederverwendet. Wer sie ausliest, hat den Tresor-Inhalt —
und der Schlüssel bleibt im System, weil die anderen Belege ihn brauchen.
Überschrieben wird in Stücken von 64 kB, damit ein 12-MB-Beleg nicht als
ein Block im Speicher liegt.

Danach ist der Eintrag in der Datenbank weg — damit ist auch der
Download-Endpunkt zu, denn er findet die Datei über diesen Eintrag.

**Das ist jetzt die einzige Stelle, an der ein Beleg die Platte verlässt**
(`LSV07A_Rechnung::datei_vernichten()`): aus dem Entwurf, aus dem Archiv
und beim Löschen der ganzen Rechnung. Vorher stand an drei Stellen ein
`unlink()`.

**Was es nicht leistet, und das muss dastehen:** Auf einem Dateisystem mit
Schattenkopien (btrfs, ZFS, Snapshots) und auf SSDs mit
Verschleissausgleich landet das Überschreiben womöglich an einer anderen
Stelle als das Original, und in einer **Sicherung liegt die Datei weiter**.
„Unwiderruflich" heisst: aus dem Plugin und von der laufenden Platte, ohne
Papierkorb und ohne Weg zurück. Wer die Datei auch aus den Sicherungen
haben will, muss die Sicherungen anfassen; das kann kein Plugin.

## Vier Regeln, dieselben wie beim Löschen einer Rechnung

* **Begründung ist Pflicht** — ausser, jemand nimmt einen Beleg aus seinem
  eigenen Entwurf. Auch Leerzeichen sind keine Begründung.
* **Vorher wird protokolliert**: Dateiname, Grösse, Rechnung, wessen sie
  ist, ihr Stand, wie viele Belege danach übrig sind, und der Grund. Erst
  protokollieren, dann löschen — danach ist nicht mehr zu ermitteln, was
  dort lag. Der Eintrag hängt an der **Rechnung**, nicht an der Datei: die
  gibt es nicht mehr.
* **Die betroffene Person bekommt eine Mitteilung**, mit dem **Dateinamen**
  und dem Grund. „Ein Beleg wurde gelöscht" nützt nichts, wenn man drei
  hochgeladen hat. Und sie sagt, dass die Rechnung bestehen bleibt.
* **Der Dialog warnt vor dem Klick**, sobald die Rechnung über *Entwurf*
  hinaus ist: § 147 AO und § 257 HGB verlangen zehn Jahre Aufbewahrung.
  Dazu, dass die Rechnung stehen bleibt, dass der Nachweis auch aus dem
  Jahrespaket verschwindet, und dass protokolliert und mitgeteilt wird.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Beleg löschen (echte Klassen, echtes SQL, echte Platte) | 58 | 15 von 15 |

Darunter der Nachweis, dass wirklich **überschrieben** wird: Die Prüfung
legt einen zweiten harten Verweis auf dieselbe Datei an. Nach dem Löschen
zeigt er auf denselben Inhalt — und der ist Rauschen, gleich lang wie
vorher, ohne den Klartext darin. Ein `unlink()` allein würde diese
Zusicherung reissen.

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen — in
der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft sie
nichts und ist wertlos.
