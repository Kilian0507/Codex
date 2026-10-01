# LSV07 Interner Bereich — 8.23.0

## Gesamtprüfung: alle Rollen, Handy, Tablet und PC

Geprüft wurde der gesamte interne Bereich — sechs Nutzerarten (Admin,
Trainer, Triathlon, Fitness, Kassenwart, Nur-Mannschaften) über drei
Gerätegrößen (Handy 390 px, Tablet 820 px, PC 1440 px), jeder sichtbare
Knopf angeklickt: **532 Klicks, 0 JavaScript-Fehler**. Dazu die
Querprüfung aller 236 Server-Endpunkte und 269 Aufrufe aus der Oberfläche.

Drei Dinge waren wirklich kaputt. Sie sind behoben.

### 1. Fehlschläge blieben unsichtbar — besonders fehlende Rechte

**Das Wichtigste an dieser Fassung.**

Eine abgelehnte oder fehlgeschlagene Anfrage landet nicht im Erfolgszweig,
sondern im Fehlerzweig. **112 von 269 Aufrufen hatten gar keinen** — dort
passierte schlicht *nichts*: keine Meldung, kein Hinweis, nichts.

Am schwersten wog das bei **fehlenden Berechtigungen**: Der Server
antwortet darauf mit einem 403 und der Erklärung „Sie haben keine
Berechtigung für diese Aktion." Genau diese Erklärung verschwand
ungesehen. Wer ein Recht nicht hatte, drückte den Knopf und bekam
**nichts** zu sehen — kein Hinweis, warum es nicht geht.

Genauso bei **44 ändernden Aktionen**, darunter:

> Trainer löschen · Mannschaft löschen · Schwimmer löschen ·
> Trainingszeit löschen · Wettkampf löschen · Abrechnung genehmigen ·
> Rechte-Vorlage anwenden · Rechte-Vorlage löschen · Saison löschen ·
> Ticket löschen · Ticket zuweisen · Unterhaltung löschen · Nachricht
> löschen · Reflexionsbogen freischalten/löschen/archivieren ·
> Trainingsplan löschen · je vier Lösch-Aufrufe in Triathlon und Fitness

Man bestätigte „Löschen?", der Eintrag blieb stehen, und es wurde nichts
gesagt. Man konnte nur raten, ob es geklappt hat.

**Jetzt** meldet jede Anfrage einen Fehlschlag von sich aus — mit dem Text,
den der Server mitschickt. Wer eine eigene Behandlung angehängt hat (das
sind 149 Aufrufe), behält sie; es gibt keine doppelten Meldungen. Die acht
Aufrufe, die im Hintergrund im Sekundentakt laufen (Chat, Zähler der
Benachrichtigungen), bleiben bewusst still — sonst käme bei einer Störung
alle paar Sekunden eine Meldung.

### 2. Ticket-Liste war auf dem Handy unbrauchbar

Auf dem Handy zeigt eine Tabellenzeile eingeklappt nur ihre **erste Zelle**;
der Rest klappt auf Tippen auf. In der Ticket-Liste stand dort die
**Nummer** — die Liste las sich als „#7", „#8" und sonst nichts. Antippen
half auch nicht: Bei Tickets öffnet ein Tipp die Detailansicht statt
aufzuklappen.

Jetzt stehen Nummer **und Titel** zusammen in der ersten Zelle:
„#7 Zugang zur Anwesenheit fehlt".

Dasselbe in der Vorschau des Bestzeiten-Imports: Dort stand das
Status-Schildchen vorn, man las „OK" oder „prüfen" und wusste nicht, um wen
es geht. Jetzt steht der **Name** vorn.

### 3. Auf dem Tablet standen Werte ohne jede Beschriftung

Zwischen 601 und 899 px (Tablet hochkant) werden Tabellen zu gestapelten
Karten: links die Beschriftung, rechts der Wert. Die Beschriftung kommt aus
`data-label` — und **32 Zellen in 6 Tabellen hatten keines**. Übrig blieb
eine Spalte rechtsbündiger Werte ohne Erklärung: „Frage", „Offen", „Hoch",
„Sabine Trainerin" — ohne zu sagen, was davon was ist.

Nachgetragen in: Tickets (alle 8 Spalten), Bestzeiten-Import (alle 5),
Abrechnung → Wettkämpfe und → Fahrtkosten in der Verwaltung,
Wettkampf-Anwesenheit sowie die Summenzeilen der Abrechnungen.

### Außerdem

- **Neun Debug-Ausgaben entfernt**, die in der ausgelieferten Fassung in
  die Browser-Konsole schrieben — zwei davon im Rechte-Bereich, samt
  Rechte-Daten.

### Geprüft

- **18 Durchläufe** (6 Nutzerarten × 3 Gerätegrößen), jeder sichtbare Knopf
  gedrückt: **532 Klicks, 0 JavaScript-Fehler, kein Knopf ohne Funktion,
  kein waagerechtes Überlaufen, keine Tippfläche unter 30 px, kein Dialog
  ohne Schließen-Weg.**
- **13 Prüfungen** zur neuen Fehlermeldung gegen einen echten Server, der
  mit 403 bzw. 500 antwortet: die Meldung erscheint, sie trägt den Text des
  Servers, sie ist als Fehler gekennzeichnet; eine eigene Behandlung
  bekommt *keine* zweite Meldung; die Taktgeber bleiben still; bei Erfolg
  wird nichts gemeldet.
- **12 Prüfungen** zu den Tabellen auf Handy und Tablet.
- **Gegenprobe:** Mit dem alten Stand fallen bei der Fehlermeldung **4 von
  13** durch (das Löschen ohne Berechtigung meldet dort: nichts, leerer
  Text) und bei den Tabellen **6 von 12** (die Handy-Zeile zeigt dort nur
  „#7").
- **Querprüfung ohne Befund:** jeder der 269 Aufrufe aus der Oberfläche hat
  einen Endpunkt; **kein Endpunkt ohne Berechtigungsprüfung**; alle 29
  benutzten Berechtigungstore sind definiert (ein Tippfehler dort würde
  alle aussperren); die 22 Tab-Rechte stimmen zwischen Zugriffskarte und
  Oberfläche eins zu eins überein; die vier Admin-Unterbereiche (Protokoll,
  Saison, Atteste, Rechte) akzeptieren serverseitig dieselben Rechte, die
  ihren Reiter öffnen; keine doppelten HTML-Kennungen; kein
  Navigationsziel ohne Panel.
- Bestehende Prüfungen unverändert grün: Archiv (42 + 12 + 44),
  Startseite/Mannschaftsauswahl (42 + 10), Anwesenheit (41),
  Mannschaftsrecht (58 + 38), Admin→Trainer (28 + 24), Abrechnung
  (30 + 19 + 23), Export (37), Trainingsplan (68 + 58 + 41 + 5 + 51),
  statische Prüfung, Klick-Durchlauf (90 Klicks, 0 Fehler).
