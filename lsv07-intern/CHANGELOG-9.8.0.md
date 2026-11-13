# Interner Bereich 9.8.0 — Meldungen, die sagen, wo es fehlt; Laden, das man sieht; Bewegung, die etwas bedeutet

Dasselbe, was die Abrechnung mit 1.16.0 bekommen hat — hier für den
internen Bereich, und dazu das, worum es ausdrücklich ging: Fehlt eine
Angabe, steht das jetzt **am Feld**.

## Eine Angabe fehlt

Der interne Bereich prüfte Eingaben schon vorher an vielen Stellen. Aber
die Meldung stand nur oben am Rand, vier Sekunden lang — und in einem
Dialog mit zwölf Feldern musste man suchen, welches gemeint war.

Jetzt, an **64 Stellen**:

* Das Feld bekommt einen **roten Rahmen**, der **Satz steht darunter**.
* Das erste betroffene Feld wird **angesprungen** und in Sicht gerollt.
* Von mehreren Feldern werden nur die **leeren** gekennzeichnet („Name,
  Ort, Datum von und bis sind Pflicht" färbt nicht alle vier, wenn nur
  das Datum fehlt). Sind alle gefüllt, aber falsch — zwei Passwörter,
  die nicht übereinstimmen —, sind genau die gemeint, um die es geht.
* Beim Tippen oder Auswählen **geht die Kennzeichnung sofort**. Beim
  nächsten Versuch und beim Schliessen des Dialogs ebenfalls.
* Die Meldung oben kommt weiterhin dazu — sie ist auch dann zu sehen,
  wenn das Feld gerade verdeckt ist.
* Für Vorleseprogramme: `aria-invalid`, der Satz ist mit dem Feld
  verknüpft und wird angesagt.

Kein Wackeln. Ein Feld, das zappelt, liest sich nicht besser.

Betroffen sind unter anderem: Mannschaft, Schwimmer, Trainer,
Trainingszeit, Wettkampf, Anwesenheit (Mannschaft und Datum),
Wettkampf-Anwesenheit, Springer-Zeitraum, Abrechnung (Training,
Wettkampf, Kilometer, Stundensatz, Rückgabegrund), Meldung, Bestzeiten,
Mannschaftsbuch, Trainingsplan, Saison, Rechte-Vorlage, Tickets,
Benachrichtigungen, Unterhaltungen, Passwort.

## Fehlermeldungen

### Was vorher unterging

* **68 Anfragen** zeigten nichts, wenn der Server „nein" sagte. Der
  Knopf sprang zurück, eine Liste blieb leer, der Grund blieb ungelesen.
* **Keine Verbindung** wurde nie gemeldet — ausdrücklich nicht.
* Wer nach einer Anfrage nur den Knopf zurücksetzte (`.always`),
  **schaltete damit die Fehlermeldung ab**. Das waren die meisten
  Speichern-Knöpfe.
* Scheiterte das Laden, stand dort **für immer „Wird geladen…"** — auch
  hinter den anderen Reitern desselben Bereichs und auch beim nächsten
  Besuch, denn geladen wird nur einmal.
* Eine Fehlermeldung blieb **vier Sekunden**, egal wie lang.

Jetzt meldet **eine Stelle** jeden Fehlschlag. Sie wartet ab, ob der
Aufrufer selbst etwas zeigt — eine Meldung, einen roten Hinweis in der
Seite, eine Rückfrage — und meldet sich nur, wenn er es nicht tut. So
gibt es weder zwei Meldungen noch gar keine.

| Was geschieht | Was dasteht |
|---|---|
| Der Server sagt „nein" und nennt den Grund | sein Satz, wörtlich |
| Keine Verbindung | „Der Server ist nicht erreichbar. Es wurde nichts gespeichert …" |
| Sitzung abgelaufen | dass man sich neu anmelden muss |
| 403 / 404 / 413 / 429 / 500 / 502–504 | je ein eigener Satz mit Ursache und nächstem Schritt |
| Antwort unlesbar | dass nicht feststeht, ob es durchging |

Eine Fehlermeldung bleibt fünf bis zwanzig Sekunden, je nach Länge.
Scheitert das Laden, steht der Grund **an der Stelle der Liste**, mit
einem Knopf *Seite neu laden*.

### Bessere Sätze vom Server

* **„Ungültige Anfrage."** stand an fünfzehn Stellen für drei
  verschiedene Dinge. Jetzt sagt eine Stelle, welches: *abgemeldet*, *die
  Datei war zu gross* (PHP wirft die Anfrage dann komplett weg, mitsamt
  dem Schlüssel — neu laden hilft dabei nicht), oder *der
  Sicherheitsschlüssel der Seite ist abgelaufen, weil sie lange offen
  stand*.
* **„Upload-Fehler (Code 1)."** an fünf Stellen. Jetzt: zu gross (mit der
  Grenze in MB), nur zum Teil angekommen, kein Platz im
  Zwischenspeicher, vom Server abgewiesen.
* Die Ticketliste sagte bei jedem Fehlschlag „Verbindungsfehler" — auch
  bei fehlender Berechtigung. Jetzt steht der Grund da.

### Sicherheit

**Meldungen gingen als HTML in die Seite.** Auch die des Servers, und in
denen stehen Namen, Mailadressen und Dateitypen — Angaben, die jemand
eingegeben hat. Ein Saisonname mit einem Skript darin wäre ausgeführt
worden. Die Meldung zeigt jetzt Text, kein Markup.

**Der Text der Datenbank ging an den Browser** — an zwölf Stellen (Meldung,
Mannschaftsbuch, Attest, Anhang, Saison, Person, CSV-Export). Darin
stehen Tabellen- und Spaltennamen, manchmal der abgelehnte Wert. Er
steht jetzt im Fehlerprotokoll des Servers und im eigenen Protokoll des
Plugins; die Meldung trägt eine sechsstellige Kennung, unter der er dort
zu finden ist.

Unverändert, weil nur für die Administration: Die Startseite nennt bei
unvollständigen Trainingszahlen weiterhin den Befund, und die
Rechteverwaltung bei einem Serverfehler die Stelle im Code.

## Laden

* **Platzhalter statt „Wird geladen…"** an 57 Stellen: graue Balken mit
  einem Glanz. Die Worte stehen weiter im Dokument, für
  Vorleseprogramme. „Keine Sessions." und ähnliche Auskünfte bleiben,
  was sie sind.
* **Ladebalken:** erst nach 150 ms (kein Aufblitzen), und er bleibt, bis
  *alle* Anfragen fertig sind — vorher nahm ihn die erste Antwort weg.
* **Knöpfe** zeigen einen Ring, solange sie arbeiten (227 Stellen über
  einen Helfer). Vorher standen an den meisten nur drei Punkte.
* **Datei hochladen** zeigt für alle acht Uploads den echten
  Fortschritt, danach „Angekommen — wird geprüft …". Schreibt ein
  anderes Plugin eine Notiz vor die Antwort, gilt der Upload nicht mehr
  als gescheitert.

## Bewegung

* **Das Zeichen in der Mitte** (Haken und Stand), wenn ein Vorgang eine
  Station weiterrückt: Abrechnung eingereicht, genehmigt, bezahlt;
  Sonderabrechnung eingereicht; Wettkampf freigegeben; Trainingsplan
  und Vorlage freigegeben; Reflexionsbogen freigeschaltet; als Springer
  eingetragen; Ticket erstellt; Daten eingelesen. Nur vorwärts — nicht
  beim Zurückgeben, nicht beim Zurückziehen einer Freigabe. Es nimmt
  keine Klicks an; der erste Klick oder Tastendruck nimmt es weg und
  kommt trotzdem an.
* **Zeilen:** neue gleiten herein und leuchten auf, geänderte leuchten
  auf, gelöschte gehen sichtbar. Das gilt für **jede Liste**, ohne dass
  sie dafür angefasst werden musste — ein Beobachter sieht zu.
* **Zahl an der Glocke** pulst, wenn sie steigt. **Bereiche und Reiter**
  blenden ein.
* **Weniger Bewegung** (Einstellung im Betriebssystem) schaltet alles
  davon ab — an einer einzigen Stelle am Ende von `glass.css`, für
  alles, auch Künftiges. Die drei verstreuten Regeln von vorher sind
  dorthin gezogen.

### Wann eine Zeile aufleuchtet — und wann nicht

Der Beobachter ist vorsichtig. Lieber keine Bewegung als eine falsche:

* Die Liste muss **im Wesentlichen dieselbe** sein. Ist mehr als die
  Hälfte neu oder weg, ist es eine andere Liste — nichts leuchtet.
* Kam die neue Liste durch einen **Filter** zustande (getippt, Auswahl
  umgestellt), leuchtet nichts. Erst nach dem nächsten Klick auf einen
  Knopf wieder.
* Eine andere **Uhrzeit** allein lässt eine Zeile nicht aufleuchten.
* Eine Zeile braucht eine **Kennung**. Ohne bewegt sich nichts.
* Liegen **mehrere Tabellen** im selben Bereich, wird nicht verglichen.
* Der **Chat** ist ausgenommen.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Oberfläche — echte Vorlage, echtes Skript, echter Browser, eigener Server | 201 | 135 von 135 |
| Meldungen des Servers und Einmal-Schlüssel (echte Klassen) | 79 | 56 von 56 |
| Anmeldung, öffentliche Wettkampfseite (unverändert, erneut gelaufen) | 50 / 57 | — |

Der Prüfstand wird aus `templates/main.php` gebaut — aus der Seite, die
WordPress ausliefert, mit allen Bereichen freigeschaltet. Die
Fehlerfälle sind echt: Eine abgerissene Verbindung ist dort eine
abgerissene Verbindung. Angesehen in heller und dunkler Darstellung.

Gegenprobe heisst: Jede Regel wird einmal absichtlich gebrochen, in der
echten Datei im Plugin. Bleibt die Prüfung dabei grün, prüft sie nichts.

Vier Fehler hat erst die Prüfung gefunden, alle behoben:

* Listen hinter den **anderen Reitern** eines Bereichs luden nach einem
  Fehlschlag ewig weiter.
* Eine **Absage beim Speichern** färbte eine unbeteiligte Liste rot.
* Ein **altes rotes Feld** blieb stehen, wenn beim nächsten Versuch ein
  anderes gemeint war.
* Der Beobachter der Zeilen **überhörte echte Änderungen**, wenn der
  Server schnell antwortete — dann galt beim nächsten Mal Altes als neu.

Ein Bruch der Server-Gegenprobe blieb beim ersten Lauf grün (das eigene
Fehlerprotokoll des Plugins): Die Zusicherung fehlte. Ergänzt, danach
rot.

### Was nicht bewiesen ist

* **59 der 64 Feld-Meldungen** sind nicht einzeln durchgespielt.
  Durchgespielt sind fünf (Mannschaft, drei Fälle beim Passwort, Ticket);
  für alle 64 ist geprüft, dass es das benannte Feld gibt. Dass die
  Meldung an jeder Stelle das **richtige** Feld nennt, ist bei den
  übrigen aus dem Quelltext abgeleitet und von Hand durchgesehen, nicht
  im Browser ausgelöst.
* **Der Beobachter der Zeilen** ist an zwei Listen geprüft (Mannschaften,
  Tickets). Er gilt für alle; dass er an jeder anderen das Richtige tut,
  ist nicht einzeln bewiesen. Er ist so gebaut, dass er im Zweifel
  nichts tut.
* **Das Zeichen** ist an einer Stelle durchgespielt (Ticket erstellt).
  Die zehn anderen Aufrufe sind dieselbe eine Zeile an anderer Stelle,
  aber nicht einzeln ausgelöst.
* **Alle acht Uploads** laufen über dieselbe eine Stelle; durchgespielt
  sind zwei (Profilbild, Ticket-Anhang).
* **Die neuen Sätze an den Endpunkten:** Geprüft sind die Sätze selbst
  und, durch Durchsicht des Quelltexts, dass kein Datenbanktext mehr in
  eine Meldung geht und jede Schlüsselprüfung über die eine Stelle
  antwortet. Ein echter Datenbankfehler an einem bestimmten Endpunkt ist
  nicht nachgestellt.
* **Zwei Schutzschichten ohne eigenen Beweis:** die Anzeige des Uploads
  schon vor dem ersten Fortschritt, und die Ausnahme „Eingaben im Dialog
  zählen nicht als Filter". Beide einzeln zu brechen bleibt grün, weil
  eine andere Stelle dasselbe auffängt.
* **Auf einem echten WordPress** ist nichts davon gelaufen.
