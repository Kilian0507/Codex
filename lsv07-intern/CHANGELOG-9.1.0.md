# Interner Bereich 9.1.0 — alles einlesen

Bisher hatte jede Datenart ihren eigenen Import oder gar keinen: einen
für Trainer, einen für Schwimmer, einen Assistenten für Personendaten —
und für Mannschaften, Trainingszeiten, Gruppen, Kontaktpersonen, Atteste
oder Saisons überhaupt keinen. Wer einen Verein in dieses System bringt,
tippt sonst Wochen lang.

Jetzt gibt es **Admin → Import → „Daten einlesen"**: einen Weg für alle
Datenarten.

## Was eingelesen werden kann

| Bereich | Datenarten |
|---|---|
| Personen | Personendaten (mit Sparten und Rollen) |
| Schwimmen | Mannschaften, Sportler, Trainer, Trainingszeiten, Kontaktpersonen, Atteste |
| Verein | Saisons |
| Triathlon | Gruppen, Sportler, Trainer, Trainingszeiten |
| Fitness | Gruppen, Sportler, Trainer, Trainingszeiten |

Dazu bleiben zwei Wege, wo sie hingehören: die **Bestzeiten** werden
dort eingelesen, wo sie stehen (Schwimmen → Bestzeiten, mit der
Namenserkennung, die der Verein kennt), und der **Personen-Assistent**
bleibt daneben, weil er zusätzlich WordPress-Konten verknüpft und Vor-
und Nachnamen aus einer Spalte lesen kann.

## Vier Schritte

1. **Datenart wählen.** Danach stehen der Hinweis zur Art, die Liste
   ihrer Spalten und eine Musterdatei da.
2. **Datei lesen.** CSV oder Excel, oder eine Tabelle aus Excel
   eingefügt. Trennzeichen und Kodierung werden erkannt — aus „Müller"
   wird kein „MÃ¼ller".
3. **Spalten zuordnen.** Vorbelegt aus der Kopfzeile. Jede Spalte lässt
   sich auch weglassen, und ein Feld wird nicht zweimal vergeben.
4. **Vorschau, dann einlesen.** Die Vorschau zeigt je Zeile, was
   passieren würde: neu, geändert (mit altem und neuem Wert),
   unverändert oder Fehler mit Zeilennummer und Grund.

## Drei Regeln, die nur an einer Stelle stehen

* **Die Vorschau ist das Schreiben — mit abgeschaltetem Schreiben.** Es
  gibt keinen zweiten Rechenweg. Jedes Schreiben läuft durch drei
  Methoden, die im Probelauf nichts tun. Geprüft ist das mit einem
  Fingerabdruck der ganzen Datenbank vor und nach der Vorschau, für
  **jede** Datenart.
* **Eine fehlende Spalte löscht nichts.** Wer eine Liste ohne
  E-Mail-Spalte einliest, will keine E-Mail-Adressen löschen. Auch eine
  leere Zelle löscht nicht: leer heisst „keine Angabe", nicht „nichts".
  Löschen geht über die Oberfläche.
* **Eine fehlerhafte Zeile hält den Rest nicht auf.** Sie wird
  übersprungen und mit Zeilennummer und Grund gemeldet.

Dazu:

* **Ein Datum wird nicht geraten.** Deutsche und ISO-Schreibweise,
  zweistellige Jahre, Schrägstriche — alles andere ist ein Fehler in der
  Zeile. `strtotime` ist absichtlich nicht der letzte Rückfall: Es macht
  aus „tomorrow" ein Datum, das in keiner Datei stand.
* **Ein Wochentag wird nicht am Anfangsbuchstaben erkannt.** „Dings"
  wäre sonst ein Dienstag.
* **Jede Datenart hat ihr eigenes Recht.** Ein einzelnes „darf
  importieren" wäre ein Generalschlüssel: Wer Trainingszeiten einlesen
  darf, bekäme damit die Personendaten des Vereins. Was man nicht
  einlesen darf, steht nicht in der Auswahl.
* **Eine eingelesene Saison wird nicht aktiv.** Zwei aktive Saisons
  gleichzeitig kann die Anwesenheit nicht auflösen.
* **Gruppen werden ergänzt, nicht weggenommen.** Auch eine kürzere
  Gruppenliste nimmt keine Mannschaft weg.
* **Jedes Einlesen steht im Protokoll**, mit Datenart und Zahlen.
* Höchstens 5000 Zeilen auf einmal.

## Was nicht eingelesen wird — und warum

* **Wettkämpfe.** Ihr Speichern setzt Regeln durch, die es nur einmal
  gibt: mindestens eine Mannschaft, ein Eintrag je Wettkampftag mit
  Abschnittszahl, und der Rückzug einer bereits erteilten Freigabe, wenn
  sich öffentlich sichtbare Daten ändern. Ein Import wäre eine zweite
  Fassung dieser Regeln — und zwei Fassungen laufen auseinander.
* **Anwesenheit.** Das sind keine Stammdaten, sondern das, was im
  Mannschaftsbuch entsteht. Ein Import müsste Datum, Trainingszeit und
  Saison zusammenbringen und sechs Zustände treffen; falsch eingelesen
  schreibt er Trainingsgeschichte um, die niemand mehr prüfen kann.

Wenn das trotzdem gebraucht wird, sagt bitte Bescheid — dann wird für
die Wettkämpfe erst die Schreibstelle geteilt, statt sie zu kopieren.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Einlesen (echte Klasse, echtes SQL) | 88 | 24 von 24 |
| Einlesen (Oberfläche, echter Browser) | 39 | 14 von 14 |

Darunter: die Musterdatei **jeder** Datenart läuft fehlerfrei durch
ihren eigenen Import. Passt sie nicht, ist die Vorlage falsch — und das
merkt sonst erst der, der sie benutzt.

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen.
Bleibt die Prüfung dabei grün, prüft sie nichts und ist wertlos.
