# LSV07 Interner Bereich — 8.21.0

## Excel-Liste mit Kontaktdaten, und die Anwesenheit rechnet wieder richtig

### Excel-Export mit allen Kontaktdaten

Der Knopf **Excel-Export** unter Admin → Sportler → Schwimmen lieferte bisher
nur eine Spalte mit den Namen. Jetzt kommt die vollständige Liste:

| Nachname | Vorname | Geburtsdatum | Mannschaft(en) | E-Mail | DSV-ID | Attest bis | Notizen | Kontakt 1 Name / Telefon / E-Mail | Kontakt 2 … |

- **Die Kontaktpersonen stehen mit drin** — Name, Telefon und E-Mail, je eine
  Dreiergruppe von Spalten. Wie viele Spalten es gibt, richtet sich danach,
  wie viele Kontaktpersonen tatsächlich vorkommen (höchstens sechs).
- Datumsangaben deutsch; Platzhalter aus Altbeständen (`0000-00-00`) bleiben
  leer statt als „00.00.0000" zu erscheinen.
- Mehrfach zugeordnete Schwimmer führen alle ihre Mannschaften auf.
- Suche und Mannschaftsfilter wirken wie bisher: Es wird exportiert, was man
  gerade sieht — ohne Filter also alle.
- Spaltenbreiten gesetzt, Kopfzeile fixiert.

### Anwesenheit: keine Quoten über 100 % mehr

Die Quote konnte über 100 % steigen — im Test der alten Fassung **250 %**.

**Warum:** Der Zähler zählte alle Anwesenheits-Einträge eines Schwimmers im
gewählten Zeitraum, der Nenner aber nur die Trainings seiner **heutigen**
Mannschaft. Wer die Mannschaft gewechselt hatte oder in zwei Mannschaften
mitschwimmt, brachte Einträge mit, die im Nenner fehlten. Ohne
Mannschaftsfilter zählte der Zähler sogar über alle Mannschaften, der Nenner
über eine einzige.

**Jetzt** ist der Nenner: **die Trainings, für die dieser Schwimmer erfasst
wurde** (anwesend + abwesend + entschuldigt). Das kann den Zähler nie
unterschreiten — die Quote bleibt zwangsläufig bei oder unter 100 %.

Damit stimmen auch die beiden anderen Punkte:

- **Mannschaftswechsel:** Die Einträge wandern mit. Filtert man auf die alte
  Mannschaft, zählen die Trainings von dort; filtert man auf die neue, die
  von dort. Trainings einer Mannschaft aus der Zeit, in der jemand gar nicht
  dabei war, zählen ihm nicht mehr an.
- **Saisonwechsel:** Ohne ausdrücklichen Zeitraum zählt die **laufende
  Saison**. Die Zahlen beginnen mit jeder Saison neu; mit einem eigenen
  Zeitraum sieht man weiterhin, was man will.

Die Oberfläche schreibt „von N erfassten Trainings" dazu, damit die
Bezugsgröße sichtbar bleibt.

### Schwimmer-Profil: letzte Trainings stimmen

Im Profil (Mannschaften → Schwimmer anklicken) standen die letzten Trainings
der **heutigen** Mannschaft — auch solche, für die der Schwimmer gar keinen
Eintrag hatte. Die standen dann ohne Status da und sahen aus wie Fehler;
Trainings aus der früheren Mannschaft fehlten ganz.

Jetzt sind es die Termine, für die er wirklich erfasst wurde — mit dem
richtigen Status und über Mannschaftswechsel hinweg. Zu jedem Termin steht
die Mannschaft dabei. Ausgefallene Trainings erscheinen weiterhin als Ausfall,
zählen aber nicht in die Quote.

### Startseite: der Trainings-Block verschwindet nicht mehr

Wählte man eine Mannschaft, deren Abruf scheiterte, wurde die **ganze Karte
ausgeblendet** — mitsamt der Auswahlliste, über die man es erneut hätte
versuchen können. Sie kam bis zum Neuladen der Seite nicht mehr zurück.

Jetzt bleibt die Karte stehen und sagt, was los ist. Die Auswahl bleibt
bedienbar, und ein Wechsel zurück zeigt wieder Daten. Hat eine Mannschaft
schlicht keine Trainings, steht das wie bisher als Hinweis da.

### Geprüft

- 41 Prüfungen gegen eine echte Datenbank mit den echten SQL-Texten, an
  einem Fall mit Mannschaftswechsel mitten in der Saison, einem Schwimmer in
  zwei Mannschaften, einer Vorsaison und einem ausgefallenen Training:
  Nenner nie kleiner als der Zähler, Quote ≤ 100 %, die Zahlen pro Status,
  Saisongrenze mit und ohne eigenen Zeitraum, beide Mannschaftsfilter, das
  Profil samt letzter Trainings mit Status und Mannschaft — und dass keine
  PHP-Meldung der JSON-Antwort vorausläuft.
- **Gegenprobe:** Mit dem alten Stand fallen **18 der 41** Prüfungen durch,
  darunter „ihre Quote bleibt bei oder unter 100 % — **250 %**".
- 37 Prüfungen in der echten Oberfläche: Die Excel-Datei wird tatsächlich
  heruntergeladen und wieder eingelesen — Kopfzeile, eine Zeile je
  Schwimmer, beide Kontaktpersonen mit Telefon und E-Mail, leere Felder bei
  fehlenden Daten, Spaltenbreiten. Dazu der Startseiten-Block in drei Fällen
  (ohne Trainings, abgelehnte Anfrage, gescheiterte Anfrage): Karte bleibt
  sichtbar, Auswahl bedienbar, Erklärung sichtbar, Zurückwechseln zeigt
  wieder Daten.
- Bestehende Prüfungen unverändert grün: Mannschaftsrecht (58 + 38),
  Admin→Trainer (28 + 24), Abrechnung (30 + 19 + 23), Trainingsplan
  (68 + 58 + 41 + 5 + 51), statische Prüfung, Klick-Durchlauf über alle fünf
  Nutzerarten (90 Klicks, 0 Fehler).
