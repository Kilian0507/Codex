# LSV07 Interner Bereich — 8.22.1

## „Letzte 5 Trainings: Ein Fehler ist aufgetreten" — und keine Mannschaft
## in der Auswahl

Gemeldet als Administrator. Drei Ursachen kamen zusammen; alle drei sind
behoben, und was danach noch schiefgehen kann, sagt jetzt, was es ist.

### 1. Eine Meldung vor der Antwort machte die ganze Antwort unlesbar

Schreibt irgendein Plugin, ein Theme oder PHP selbst einen Hinweis, eine
Warnung oder auch nur eine Leerzeile in die Ausgabe, landet das **vor** der
Antwort des Servers im selben Datenstrom. Der Browser kann die Antwort dann
nicht mehr lesen — obwohl die Daten vollständig mitgeliefert wurden. Sichtbar
blieb davon nur: **„Ein Fehler ist aufgetreten."** Und weil die Antwort nicht
ankam, blieb auch die Mannschaftsauswahl leer.

Jetzt wird die Antwort herausgeschnitten und ganz normal verwendet. Das
Vorgeschriebene landet in der Browser-Konsole, damit es auffindbar bleibt
statt zu verschwinden. **Das gilt für den ganzen internen Bereich**, nicht
nur für diese eine Kachel — es war dieselbe Ursache, die schon mehrfach zu
„lädt ewig" oder „passiert nichts" geführt hat.

### 2. Eine fehlende Sortierspalte ließ die Mannschaftsliste leer

Die Mannschaftstabelle gehört nicht diesem Plugin. Die Kachel sortierte fest
nach `sort_order`. Fehlt diese Spalte, ist die Abfrage ungültig — und es kam
**keine einzige Mannschaft** zurück. In der Prüfung des alten Stands: vier
Mannschaften vorhanden, **null** in der Auswahl.

Jetzt wird ersatzweise nach Namen sortiert. Lieber eine andere Reihenfolge
als gar keine Mannschaft.

### 3. Ein Fehler in den Zahlen riss die Auswahl mit

Die Kachel holte erst die Trainingszahlen und schickte die Mannschaftsliste
nur zusammen mit ihnen. Scheiterte irgendetwas daran, kam beides nicht — also
auch keine Mannschaft, auf die man hätte umschalten können.

Jetzt wird die Mannschaftsliste zuerst geholt und **immer** mitgeschickt.
Scheitern die Zahlen, stehen die Balken leer da, die Auswahl bleibt bedienbar.

### Und wenn doch etwas fehlt, steht da jetzt was

- Vor jeder Abfrage wird geprüft, ob es die Tabelle überhaupt gibt. Fehlt
  sie, wird nicht mehr blind gefragt — denn genau diese Fehlermeldung der
  Datenbank ist es, die sich bei eingeschaltetem `WP_DEBUG` vor die Antwort
  schiebt.
- Was fehlte, steht in der Kachel: **für Administratoren mit Tabellen- bzw.
  Spaltennamen**, für alle anderen als verständlicher Hinweis. Zusätzlich
  geht es ins Fehlerprotokoll von WordPress.
- Ein unerwarteter Fehler ergibt eine lesbare Meldung statt einer
  abgebrochenen Antwort.

### Geprüft

- **42 Prüfungen** gegen eine echte Datenbank mit den echten SQL-Texten:
  Administrator, Trainer mit genau einer Mannschaft, das Leserecht für alle
  Mannschaften, Merken und Umschalten, zwei Sparten — und die neuen Fälle:
  **fehlende Sortierspalte** (Mannschaften kommen trotzdem, nach Namen
  sortiert), **fehlende Tabelle einer anderen Sparte** (die eigenen
  Mannschaften bleiben auswählbar, der Grund nennt die Tabelle), **fehlende
  Anwesenheitstabellen** (Auswahl bleibt, nur die Zahlen fehlen), und dass
  nur Administratoren die technischen Einzelheiten zu sehen bekommen.
- **10 Prüfungen in der echten Oberfläche**, gegen einen echten Server, der
  eine PHP-Warnung vor die Antwort schreibt: Die Kachel zeigt Balken statt
  „Ein Fehler ist aufgetreten.", die Mannschaftsauswahl ist gefüllt,
  Umschalten geht weiter, die fremde Ausgabe steht in der Browser-Konsole.
  Eine saubere Antwort wird unverändert durchgereicht.
- **Gegenprobe:** Mit dem alten Stand fallen **11 der 42** Datenbank-Prüfungen
  durch — darunter „alle vier Mannschaften stehen zur Auswahl — **0**". In
  der Oberfläche steht dort wörtlich **„Ein Fehler ist aufgetreten."** und
  die Auswahl ist **leer**: genau das gemeldete Bild.
- Bestehende Prüfungen unverändert grün: Archiv (42 + 12 + 44), Anwesenheit
  (41), Mannschaftsrecht (58 + 38), Admin→Trainer (28 + 24), Abrechnung
  (30 + 19 + 23), Export und Startseite (37), Trainingsplan
  (68 + 58 + 41 + 5 + 51), statische Prüfung, Klick-Durchlauf über alle fünf
  Nutzerarten (90 Klicks, 0 Fehler).
