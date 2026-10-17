# LSV07 Interner Bereich — 8.30.0

## Neues Modul: Atteste

Im internen Bereich steht neben dem Mannschaftsbuch jetzt der Reiter
**Atteste**. Darin stehen Mannschaft für Mannschaft alle Sportlerinnen
und Sportler mit dem Tag, an dem ihr Attest abläuft.

### Das Datum ändert man im Feld

Das Ablaufdatum steht nicht hinter einem Dialog, sondern als
Datumsfeld in der Zeile. Man tippt hinein, ändert es, verlässt das Feld
— gespeichert. Das Feld färbt sich dabei mit:

* **rot**, wenn das Attest abgelaufen ist,
* **gelb**, wenn es in den nächsten 30 Tagen abläuft (die Frist steht in
  der Konfiguration),
* **gestrichelt**, wenn gar kein Datum hinterlegt ist.

Der Zustand daneben und die Zählkacheln oben wechseln sofort mit, ohne
dass die Liste neu geladen wird.

**Es ist dieselbe Spalte**, aus der die Akte, die Meldelisten und die
nächtliche Erinnerung schon immer lesen. Hier wird keine zweite Wahrheit
geführt: was man hier einträgt, steht überall, und was dort steht, steht
hier.

### Attest hochladen — das alte wandert ins Archiv

Pro Person lässt sich das Attest als **PDF, JPG oder PNG** (bis 5 MB)
anhängen. Angehängt ist immer nur das aktuelle; lädt man ein neues hoch,
rutscht das bisherige ins **Archiv**. Der Knopf heisst dann folgerichtig
*Ersetzen* statt *Hochladen*.

Das Archiv steht als Zahl in der Zeile („2 ältere") und öffnet sich als
Liste mit Datum, Grösse und der Person, die hochgeladen hat. Jedes
ältere Attest lässt sich öffnen oder löschen. Löscht man das aktuelle,
rückt das jüngste aus dem Archiv nach — es bleibt also keine Person
ohne Attest zurück, solange noch eines da ist.

Die Dateien liegen im geschützten Ablagefach, das schon die Akte
benutzt: ausserhalb des Web-Zugriffs, ausgeliefert nur über einen
Endpunkt, der Anmeldung, Recht und Mannschaft prüft.

### Erinnerung an die Kontaktperson

Bei jedem Attest, das abgelaufen ist, bald abläuft oder fehlt, steht ein
Knopf **Erinnern**. Er schickt die Erinnerungs-Mail an die hinterlegte
Kontaktperson; gibt es keine, an die eigene Adresse der Person. Der
Knopf verrät schon vor dem Druck, an wen es geht, und ist ausgegraut,
wenn gar keine Adresse hinterlegt ist. Bei einem gültigen Attest gibt es
ihn nicht.

Verschickt wird mit **derselben Vorlage** wie im Admin-Reiter „Atteste"
(Betreff und Text mit `{name}`, `{vorname}`, `{nachname}`, `{datum}`) —
eine zweite Vorlage, die niemand pflegt, hilft niemandem. Automatisch
geht weiterhin nichts raus.

### Rechte

Drei neue Rechte in der Rechteverwaltung unter *Schwimmen → Atteste*:
**Atteste sehen**, **Atteste bearbeiten**, **Erinnerung senden**. Jedes
greift einzeln; wer nur lesen darf, sieht die Liste ohne Felder und
Knöpfe. Gezeigt werden immer nur die eigenen Mannschaften.

### Handy

Jede Zeile steht auf schmalen Geräten offen — eine Attestliste, die man
erst Zeile für Zeile antippen muss, prüft niemand durch. Datumsfeld und
Knöpfe nehmen dort die volle Breite.

## Der alte Admin-Reiter „Atteste" bleibt, wie er war

Es gibt ihn weiterhin: Vorlage pflegen und die Ablaufübersicht der
nächsten 30 Tage über alle Mannschaften. Das neue Modul ersetzt ihn
nicht, sondern bringt dieselben Daten dorthin, wo man sie im Training
braucht.

Damit sich beide nicht ins Gehege kommen, trägt das neue Modul im
HTML durchgehend eigene Namen (`attv-…`). Vorher hiessen Liste und
Mail-Feld in beiden gleich — mit der Folge, dass der Admin-Reiter seine
Tabelle in das neue Modul geschrieben und es dabei überschrieben hätte,
und dass ein Klick in sein E-Mail-Feld eine Erinnerung ausgelöst hätte.
Beides ist jetzt geprüft, in beide Richtungen.

## Geprüft

* **75 Prüfungen** gegen echtes SQL: Liste und Gruppierung je
  Mannschaft, die vier Zustände samt Zählung, Datum setzen und leeren
  (auch gegen unsinnige Eingaben), der Zustandswechsel danach, das
  Hochladen mit Typ- und Grössenprüfung, das Umhängen des bisherigen
  Attests ins Archiv, das Nachrücken beim Löschen des aktuellen, der
  Datei-Endpunkt (fremde Mannschaft, fremde Art, fehlendes Recht), die
  Wahl des Empfängers (Kontakt vor eigener Adresse), die Vorlage mit
  ihren Platzhaltern sowie alle drei Rechte einzeln.
  Darunter eine Prüfung, die nachrechnet, dass die Zustandsgrenzen des
  Prüfstands noch mit `LSV07I_DB::attest_status()` übereinstimmen —
  sonst prüfte der Prüfstand irgendwann eine andere Regel als das
  Plugin.
* **40 Prüfungen** im Browser: Kacheln, Spalten, Farben des
  Datumsfelds, das Speichern beim Verlassen samt sofortigem Umfärben,
  das Hochladen (einschliesslich der zu grossen Datei, die gar nicht
  erst losgeschickt wird), das Archiv mit seinen Dialogregeln und die
  Erinnerung samt genanntem Empfänger.
* **33 Prüfungen** zu Namenskollision und schmalen Geräten: dass der
  Admin-Reiter seine Liste in seinem eigenen Kasten rendert und das neue
  Modul dabei unberührt bleibt, dass Klicks in beiden Welten nur dort
  wirken, wo sie hingehören, und dass bei 320, 390 und 768 px nichts
  quer scrollt, nichts aus seinem Kasten ragt und jede Zeile offen
  steht.
* **258 Prüfungen** der Dialogregeln, jetzt einschliesslich des
  Archiv-Dialogs, sowie der Rauchtest über 55 Bedienwege ohne
  JS-Fehler.
