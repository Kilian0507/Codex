# LSV07 Interner Bereich — 8.18.0

## Doppelte Abrechnungen für dasselbe Quartal — Ursache behoben, Reparatur eingebaut

Unter Verwaltung tauchten manchmal **zwei Abrechnungen desselben Trainers für
dasselbe Quartal** auf. Löschte man die neuere, wurde beim nächsten Öffnen
sofort wieder eine neue angelegt — und die ältere war nie mehr zu erreichen.

### Was tatsächlich passiert ist

Eine Abrechnung wird über vier Werte gefunden: **Trainer-Profil, Sparte,
Quartal, Jahr**. Diese Kombination ist in der Datenbank eindeutig — ein echtes
Duplikat kann es also gar nicht geben. Waren trotzdem zwei da, hingen sie an
**zwei verschiedenen Trainer-Profilen derselben Person**.

Wie diese zweiten Profile entstanden sind:

1. **Ein „gelöschter" Trainer wird nicht gelöscht, sondern stillgelegt**
   (`aktiv = 0`). Das WordPress-Konto und das Recht auf die eigene Abrechnung
   bleiben davon unberührt. Öffnete die Person danach ihre Abrechnung, fand
   das System kein aktives Profil — und legte **ein neues an**. Ab da hatte
   sie zwei Profile, und jede alte Abrechnung hing am stillgelegten.
   Das Löschen der neuen half nicht: der nächste Aufruf legte die nächste an.

2. **Zwei aktive Profile an einem Konto** wurden ohne feste Sortierung
   ausgelesen (`LIMIT 1` ohne `ORDER BY`). Welches gewann, entschied die
   Datenbank — und das kann sich nach einem Tabellenumbau ändern. Der Trainer
   arbeitete dann mal am einen, mal am anderen Profil. Daher „manchmal".

### Behoben

- **Es wird kein zweites Profil mehr angelegt.** Existiert zu dem Konto ein
  stillgelegtes Profil, wird **dieses** wieder in Betrieb genommen. Damit
  bleiben Abrechnungen, Stammdaten und Stundensatz beisammen.
  Das betrifft nur Personen, die das Recht „eigene Abrechnung" besitzen —
  soll jemand nicht mehr abrechnen, ist dieses Recht der richtige Hebel.
  Jede Wiederinbetriebnahme steht im Protokoll (`trainer.reaktiviert`).
- **Die Zuordnung ist wieder eindeutig.** Gibt es doch zwei aktive Profile,
  gewinnt immer dasselbe — das ältere, an dem die bisherigen Abrechnungen
  hängen — und zwar bei jedem Aufruf.

### Neu: Abrechnungen zuordnen

Bereits entstandene Doppel lassen sich jetzt geradeziehen, statt sie zu
löschen. Unter **Verwaltung → Eingereichte Abrechnungen** hat jede Abrechnung
den Knopf **Zuordnen** (nur für Administratoren, wie beim Löschen).

Im Dialog lassen sich **Trainer-Profil, Sparte, Quartal und Jahr** ändern.
Die Abrechnung wandert mitsamt allen Trainingstagen, Wettkämpfen,
Fahrtkosten und Sonderabrechnungs-Einträgen — es geht nichts verloren.

- Die Profilliste enthält ausdrücklich auch **stillgelegte** Profile — von
  denen holt man eine Abrechnung ja gerade weg. Jedes Profil ist beschriftet
  mit „deaktiviert", „mehrere Profile am Konto" und der Anzahl seiner
  Abrechnungen, damit klar ist, welches gemeint ist.
- **Ist die Zielkombination schon belegt**, wird nichts überschrieben.
  Stattdessen nennt die Meldung die Abrechnung, die im Weg steht (mit
  Nummer), damit man sie zuerst löschen oder anders zuordnen kann.
- Unmögliche Werte (Quartal außerhalb Q1–Q4, Jahr außerhalb 2000–2100,
  unbekanntes Profil oder unbekannte Abrechnung) werden abgewiesen.

### Neu: das Problem ist in der Liste zu sehen

Hängt eine Abrechnung an einem stillgelegten Trainer-Profil, trägt sie in der
Verwaltungsliste das Kennzeichen **„Profil inaktiv"** und einen Hinweis, dass
der Trainer sie nicht mehr öffnen kann und wie es zu beheben ist. Damit sind
die beiden Zeilen eines Quartals auf den ersten Blick unterscheidbar.

### Geprüft

- 30 Prüfungen gegen eine echte Datenbank mit den echten SQL-Texten. Der
  Fehler wurde zuerst **nachgestellt** — mit dem alten Stand entstanden nach
  dem Stilllegen des Profils prompt ein zweites Profil und eine zweite
  Abrechnung für Q1/2026, und die erfassten Trainingstage waren aus Sicht der
  Trainerin verschwunden. Mit der Behebung: ein Profil, eine Abrechnung, alle
  Einträge da. Dazu die Zuordnung in allen Varianten (Trainer, Quartal, Jahr,
  Sparte), die Abweisung ohne Administratorrechte, unmögliche Werte, der
  benannte Zusammenstoß und der Nachweis, dass eine abgelehnte Zuordnung
  nichts verändert.
- 23 Prüfungen in der echten Oberfläche: Kennzeichnung der verwaisten
  Abrechnung, Vorbelegung des Dialogs, Beschriftung der Profile, Verhalten
  bei einem Zusammenstoß (Dialog bleibt offen, Servermeldung sichtbar),
  vollständige Übertragung aller vier Felder, Neuladen der Liste.
- Bestehende Prüfungen unverändert grün: Abrechnungs-Zugang (19),
  Trainingsplan-PDFs (51 + 41 + 5), Vollbild-Übungen (58), statische Prüfung,
  Klick-Durchlauf über alle fünf Nutzerarten (90 Klicks, 0 Fehler).
