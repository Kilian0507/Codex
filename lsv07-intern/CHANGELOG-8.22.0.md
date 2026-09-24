# LSV07 Interner Bereich — 8.22.0

## Abrechnungen archivieren, und die Mannschaftsauswahl auf der Startseite

### Archiv für Abrechnungen (Verwaltung)

In der Abrechnungsliste unter **Verwaltung** steht jetzt neben dem
Statusfilter eine zweite Auswahl: **Aktuelle** oder **Archiv**. An jeder
Abrechnung gibt es dazu den Knopf **Archivieren**.

- **Archivieren blendet nur aus.** Es wird nichts gelöscht und nichts
  gesperrt: Die Abrechnung bleibt mit allen Trainingstagen, Wettkämpfen,
  Fahrtkosten und Summen bestehen. Sie verschwindet allein aus der normalen
  Liste.
- Unter **Archiv** steht sie wie gewohnt da — aufklappen, lesen, genehmigen,
  zurückgeben, zuordnen, alles wie vorher. Die Kopfzeile trägt zusätzlich
  die Kennzeichnung **Archiviert**, die Überschrift der Karte wechselt auf
  „Archivierte Abrechnungen“.
- Aus **Archivieren** wird dort **Zurückholen**. Ein Klick, und sie steht
  wieder in der normalen Liste.
- **Die Trainer merken nichts davon.** Wer seine Abrechnung öffnet, bekommt
  weiterhin genau dieselbe — auch wenn sie archiviert ist. Es entsteht keine
  zweite.
- Der Wechsel zwischen den beiden Ansichten lädt sofort neu; man muss nicht
  erst auf „Laden“ drücken. Der Statusfilter wirkt in beiden Ansichten.
- Ist das Archiv leer, steht das auch da.
- Archivieren darf die **Verwaltung** — es blendet ja nur aus. Löschen
  bleibt wie bisher Administratoren vorbehalten. Beides wird protokolliert.

### Startseite: die Mannschaft ließ sich nicht auswählen

Auf der Startseite, in der Kachel **Letzte 5 Trainings**, war die
Mannschaftsauswahl oft gar nicht da. Zwei Ursachen, beide behoben:

- **Bei genau einer Mannschaft** wurde die Auswahlliste bewusst versteckt —
  „da gibt es ja nichts zu wählen“. Kam später eine zweite dazu, blieb sie
  trotzdem weg, bis man die Seite neu lud. Jetzt ist die Liste immer da,
  sobald es überhaupt eine Mannschaft gibt.
- **Wer das Recht „alle Mannschaftsdaten sehen“ hat** (neu in 8.20.0), bekam
  hier gar keine Mannschaft zu sehen: Die Kachel fragte nur nach Administrator,
  Schwimmwart oder eigenem Trainer-Profil. Ohne eines davon war die Liste leer
  — und dann verschwand die ganze Kachel. Jetzt zählt dasselbe Recht wie
  überall sonst: Er sieht alle Mannschaften zur Auswahl.

Dazu eine Kleinigkeit: Nannte der Server eine gemerkte Mannschaft, die es
nicht mehr gibt, blieb die Auswahl leer statt auf eine vorhandene zu
springen. Auch das ist behoben.

### Wenn der Datenbank die neue Spalte fehlt

Die Archivspalten werden beim Laden des Plugins angelegt. Sollte das einmal
scheitern — etwa weil der Datenbankbenutzer die Tabelle nicht ändern darf —,
fällt die Verwaltung deshalb nicht aus: Die Liste zeigt dann einfach alles,
das Archiv ist leer, und wer trotzdem archivieren will, bekommt gesagt, was
fehlt und was hilft. Eine ungültige Abfrage hätte sonst die ganze Liste leer
gelassen, ohne einen Hinweis darauf, warum.

### Geprüft

- **42 Prüfungen** gegen eine echte Datenbank mit den echten SQL-Texten:
  archivieren und zurückholen, die normale Liste ohne und das Archiv mit den
  archivierten, Zusammenspiel mit dem Statusfilter, dass Zeile, Trainingstage,
  Fahrtkosten und Summe unverändert bleiben, dass die Trainerin weiterhin
  genau ihre Abrechnung öffnet, das Protokoll, die Berechtigung und die
  Fehlbedienung.
- **12 Prüfungen** mit einer Datenbank **ohne** die neuen Spalten: Die Liste
  lädt, der Statusfilter wirkt, das Archiv ist leer statt kaputt, und das
  Archivieren erklärt, was fehlt.
- **26 Prüfungen** zur Mannschaftsauswahl: Administrator, Trainer mit einer
  einzigen Mannschaft, das neue Leserecht mit und ohne Trainer-Profil,
  Merken und Umschalten, Mannschaft ohne Trainings, unmögliche Auswahl,
  zwei Sparten nebeneinander — und dass keine PHP-Meldung der Antwort
  vorausläuft.
- **44 Prüfungen in der echten Oberfläche**: die Umschaltung Aktuelle/Archiv,
  der Archivieren-Knopf an jeder Zeile, der ganze Weg archivieren →
  umschalten → aufklappen → zurückholen, dass der Knopf die Zeile nicht
  aufklappt, dass ein Serverfehler gemeldet wird und der Knopf bedienbar
  bleibt; dazu die Startseite mit einer, mit drei Mannschaften über zwei
  Sparten und mit einer unbekannten gemerkten Auswahl.
- **Gegenprobe:** Mit dem alten Stand fallen **18 der 42** Datenbank-Prüfungen
  durch, **3 der 26** zur Mannschaftsauswahl, und in der Oberfläche **8** —
  die Archivbedienung fehlt dort schlicht ganz.
- Bestehende Prüfungen unverändert grün: Anwesenheit (41), Mannschaftsrecht
  (58 + 38), Admin→Trainer (28 + 24), Abrechnung (30 + 19 + 23), Export und
  Startseite (37), Trainingsplan (68 + 58 + 41 + 5 + 51), statische Prüfung,
  Klick-Durchlauf über alle fünf Nutzerarten (90 Klicks, 0 Fehler).
