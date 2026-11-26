# Interner Bereich 9.17.0: DSV7-Ausschreibung, Meldung im Raster, Badeuhr je Session

## DSV7-Ausschreibung einlesen

Im Wettkampf-Editor gibt es das Feld **„DSV7-Ausschreibung“**. Es liest
eine Datei im DSV-Standard „Format 7“ (Wettkampfdefinitionsliste, Stand
31.08.2022), wie sie Ausrichter zur Ausschreibung anbieten.

1. **Datei wählen.** Der Server liest sie und zeigt eine **Vorschau**:
   je Angabe „jetzt“ und „aus der Datei“ nebeneinander, mit einem Haken
   zum Übernehmen.
   * Name, Ort,
   * Tage mit der Zahl der Abschnitte je Tag,
   * Meldeschluss (neues Feld im Editor, auch ohne DSV7 nutzbar),
   * die Wettkampfliste der Meldung.

   Bei einem neuen Wettkampf ist alles angehakt. Bei einem bestehenden ist
   nur angehakt, was leer ist oder schon übereinstimmt.
   **Nichts wird ungefragt überschrieben.** Eine vorhandene
   Wettkampfliste wird nur ersetzt, wenn man es anhakt. Fallen Tage weg,
   steht ein Warnhinweis da.
2. **Übernehmen** füllt nur das Formular. Gespeichert wird wie immer mit
   „Speichern“. Danach übernimmt der Server die Wettkampfliste aus
   derselben Datei. Er liest sie dafür neu und verlässt sich nicht auf
   die Vorschau aus dem Browser.

Aus der Datei kommen in die **Wettkampfliste**:

* Nummer und Abschnitt,
* die Strecke, als Kürzel, wo es eins gibt (z. B. 50F), sonst als
  Klartext wie „4x50 Lagen“ oder „100m Brust Beine“,
* Staffel ja/nein, Geschlecht (w, m, mixed),
* die **Wertungen** (Jahrgänge) und **Pflichtzeiten**.

Finals und Zwischenläufe kommen nicht in die Liste, für sie meldet man
nicht.

**Nichts wird geraten.** Der Leser hält sich genau an den Standard:

* Eine Zeile, die davon abweicht, wird nicht „irgendwie“ übernommen,
  sondern steht als **Hinweis** in der Vorschau.
* Sind die Wertungen eines Wettkampfs unklar (auch Altersklassen statt
  Jahrgängen), prüft die Meldung dort keine Jahrgänge, statt falsch zu
  sperren oder freizugeben. Dasselbe gilt für Pflichtzeiten.
* Abgelehnt mit Grund werden: falsche Listenart, anderes Format als 7,
  fehlendes DATEIENDE, Binärdateien, Dateien über 1 MB und Abschnitte
  über mehr als 14 Tage.
* Ist eine Datei nicht in UTF-8 gespeichert, wird sie als
  Windows-Zeichensatz gelesen, mit Hinweis.
* **Die Datei wird nicht gespeichert.** Adressen, Bankverbindung und
  Meldegelder werden nicht gelesen.

### Prüfungen beim Melden (mit DSV7)

* **Wertungen:** Wer in keine Wertung des Wettkampfs passt, kann nicht
  angekreuzt werden. Die Zelle nennt die Wertungen, z. B.
  „2012–2013, 2014 und jünger“. Der Server prüft dasselbe.
* **Pflichtzeit:** Ist die Meldezeit langsamer als die Pflichtzeit für
  den Jahrgang, ist die Zelle orange und nennt die Pflichtzeit. Fehlt die
  Meldezeit, steht die Pflichtzeit als Hinweis da. Das ist eine Warnung,
  keine Sperre, weil der Nachweis auch anders erbracht werden kann.
* **Meldeschluss:** Ist er vorbei, steht über dem Raster ein deutlicher
  Hinweis. Sonst steht der Meldeschluss als Angabe da.
* **DSV-ID:** Fehlt sie, steht „ohne DSV-ID“ am Schwimmer.
  Ausgeliefert wird nur ja/nein, nicht die Nummer.
* Wird ein Eintrag der Liste später von Hand geändert (Jahrgänge,
  Geschlecht, Strecke), gilt die Hand-Angabe. Die Wertungen aus der
  Datei fallen für diesen Eintrag weg.

## Meldung: ein Raster statt drei Schritten

Die Meldung hat jetzt **zwei Schritte statt drei**:

1. **Wettkampf und Mannschaft** wählen. Die Felder „Abschnitte“ und
   „Wettkampfnummern“ entfallen.
2. **Raster:** Zeilen sind die Schwimmer der Mannschaft, Spalten die
   Wettkämpfe. Ein Häkchen meldet einen Start, darunter steht das Feld
   für die Meldezeit. Abschnitt und Strecke füllen sich von selbst.
   Staffeln stehen in einer eigenen Zeile als Anzahl je Staffel-Wettkampf.

### Wettkampfliste je Wettkampf

Die Spalten kommen aus der **Wettkampfliste**. Sie gilt für alle
Mannschaften dieses Wettkampfs, man legt sie also nur einmal an. Je
Wettkampf stehen darin:

* Nummer, Abschnitt, Strecke,
* Staffel ja/nein,
* Geschlecht: alle, weiblich, männlich, mixed,
* Jahrgänge (von–bis, leer heisst alle).

Gepflegt wird sie direkt in der Meldung („Wettkampfliste anlegen“ bzw.
„Bearbeiten“). Das darf, wer für eine dem Wettkampf zugeordnete Mannschaft
melden darf. Ist keine Mannschaft zugeordnet, darf es jeder mit
Melderecht. Bei „+ Wettkampf“ wird die nächste Nummer vorgeschlagen.

### Prüfungen gegen Fehler

* **Geschlecht:** Ein Junge kann in einem Mädchen-Wettkampf nicht
  angekreuzt werden und umgekehrt. Die Zelle zeigt „–“ mit dem Grund.
  Ist das Geschlecht in der Personenverwaltung nicht hinterlegt, ist die
  Zelle erlaubt, aber orange markiert.
* **Jahrgang:** Wer ausserhalb der Jahrgänge liegt, kann nicht
  angekreuzt werden. Die Grenzen zählen mit, „2012“ ist also bei
  „2012 und älter“ erlaubt.
* **Doppelt** derselbe Schwimmer im selben Wettkampf geht nicht.
* **Meldezeit** im falschen Format wird markiert, und die Meldung wird
  nicht gespeichert. Bisher fiel sie still weg.
* **Attest** steht je Schwimmer einmal in der Zeile. Ist es abgelaufen
  oder fehlt es, ist das Feld markiert.
* Dieselben Regeln prüft der **Server** beim Speichern. Abschnitt und
  Strecke nimmt er aus der Wettkampfliste, nicht aus dem Browser.

### Bestehende Meldungen

Sie bleiben, wie sie sind, und öffnen sich im Raster:

* Starts, deren Nummer nicht in der Liste steht, bekommen eine **eigene
  Spalte** („nicht in der Liste“) und gehen nicht verloren.
* Ist die Liste noch leer, schlägt „Wettkampfliste anlegen“ die Nummern
  der Meldung vor.
* Was nicht zur Liste passt, steht als **Hinweis** über dem Raster,
  bevor gespeichert wird:
  * abweichender Abschnitt oder abweichende Strecke: Beim Speichern gilt
    die Liste,
  * doppelte Starts: werden zusammengeführt,
  * Staffel in einer Einzel-Nummer oder umgekehrt: muss entfernt werden,
  * Schwimmer, die nicht mehr in der Mannschaft sind: ihre Starts
    entfallen.

Filter nach Abschnitt und Suche nach Namen. Auf dem Handy scrollt das
Raster waagrecht, die Namen bleiben stehen. Excel, PDF und „Meldung
hochladen“ funktionieren wie bisher. Gespeichert und exportiert wird in
Kader- und Listenreihenfolge, die Staffeln am Ende.

## Trainingsplan: Badeuhr je Session abschaltbar

Im Session-Editor gibt es unter „Im Vollbild anzeigen“ den Haken
**„Badeuhr“**. Er ist angehakt, wie bisher. Ohne Haken erscheinen im
Vollbild bei dieser Übung weder die Badeuhr noch ihr Knopf. In der
Übersicht ist sie da, solange eine Übung sie hat. Die Einstellung des
Geräts (ein/aus, Grösse) bleibt davon unberührt.

## Dabei behoben

Das Bild eines Abzeichens antwortete ohne gültige Sitzung mit einer
eigenen Fehlerantwort statt über die zentrale Stelle. Eine alte Prüfung
hatte das gemeldet. Es antwortet jetzt wie der Ticket-Anhang (403).

## Datenschutz

Die Wettkampfliste speichert, wer sie zuletzt geändert hat. Die
Datenschutz-Auskunft nennt das, und Löschen auf Anfrage entfernt den
Bearbeiter. Wird der Wettkampf gelöscht, verschwindet seine Liste mit.

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Server gegen echtes WordPress 6.8.3: Wettkampfliste (Rechte, jede Zeilenprüfung, Grenzen, Markup), Kopf, Starts (Liste gilt, Geschlecht, Jahrgang, doppelt, Staffel/Einzel, fremde Schwimmer), Datenschutz, Löschen, Protokoll | 40 |
| Browser: neue Meldung mit leerer Liste, Editor, Sperren und Warnungen, Meldezeit, Staffeln, Attest, Speichern, Reihenfolge, PDF, Filter, Suche, ältere Meldung mit allen Hinweisen, Vorschlag der Liste, nur lesen, Import, Handy | 47 |
| DSV7-Leser ohne WordPress: Beispiel der Spezifikation mit ihren Tippfehlern, saubere Datei, jede Ablehnung, Windows-Zeichensatz, Grenzen | 51 |
| DSV7 gegen echtes WordPress: Vorschau, Übernahme, Rechte, Wertungen beim Melden, Hand-Nachbearbeitung, manipulierte Daten, Meldeschluss, DSV-ID | 33 |
| DSV7 im Browser (Vorschau vom echten Leser): neuer und bestehender Wettkampf, Haken, Speichern, Fehlerdatei, Markup, Raster mit Wertungen, Pflichtzeit, Meldeschluss, DSV-ID | 31 |
| Trainingsplan: Server und Browser | 37 + 98 |

**Gegenprobe:** Jede Regel wurde einmal im echten Plugin gebrochen, und
der Lauf muss rot werden:

| Teil | Brüche rot |
|---|---|
| DSV7 (Leser, Server, Oberfläche) | 55 von 55 |
| Meldung im Raster, Wettkampfliste | 52 von 52 |
| Trainingsplan samt Badeuhr je Session | 77 von 77 |

Nach dem Zurücksetzen war jeweils alles wieder grün.

Ehrlich vermerkt, was dabei auffiel:

* In der Meldung blieben zuerst zwei Brüche grün, weil die Tests zu
  schwach waren: die Reihenfolge beim Speichern (das Ankreuzen ergab
  zufällig schon die richtige) und das Querscrollen auf dem Handy (die
  Seite schnitt ab, statt zu scrollen). Beide Tests sind geschärft, danach
  lief die ganze Gegenprobe erneut.
* In der DSV7-Gegenprobe waren zwei Brüche falsch gebaut: einer erzeugte
  nur einen Syntaxfehler, einer nannte den falschen Test als Erwartung.
  Beide sind korrigiert und einzeln nachgelaufen, beide wurden rot.
* Der Test fand einen echten Fehler: Dieselbe DSV7-Datei ein zweites Mal
  zu wählen, tat nichts. Das ist behoben.
* Der versteckte Knopf „Badeuhr“ wird jetzt auch per CSS sicher
  ausgeblendet. Die Prüfung schaut auf die tatsächliche Anzeige.

Bisherige Prüfungen grün: Oberfläche 210, Abzeichen, Mannschaftsbuch,
9.12.0, 9.16.0 (Löschen + Datenschutz), Zuständigkeit 59,
Meldungstexte 79.
