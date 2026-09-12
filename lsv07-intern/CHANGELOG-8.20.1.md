# LSV07 Interner Bereich — 8.20.1

## Mit dem neuen Recht steht unter Schwimmen nur noch „Mannschaften"

Das in 8.20.0 eingeführte Recht **„Alle Mannschaften einsehen — nur lesen"**
öffnete versehentlich den ganzen Schwimmbereich: Wer es allein bekam, sah
neben Mannschaften auch Anwesenheit, Wettkämpfe, Springer, Bestzeiten,
Reflexion und Trainingsplan.

### Warum

Die Sichtbarkeit der Schwimmen-Tabs hängt an zwei Dingen: dem jeweiligen
Leserecht des Tabs **oder** der Frage „gehört diese Person überhaupt in den
Schwimmbereich?". Letztere wird mit *irgendein* `schwimmen.*`-Recht
beantwortet — und war damit auch für das neue Zusatz-Leserecht erfüllt. Die
per-Tab-Rechte liefen dadurch ins Leere.

### Behoben

Das neue Recht ist jetzt ausdrücklich **tab-gebunden**: Es öffnet den
Mannschaften-Tab und beantwortet die Bereichsfrage **nicht**. Wer es allein
hat, sieht unter Schwimmen genau einen Punkt — Mannschaften — und dahinter
die Mannschaften mit ihren Schwimmern und Profilen.

- An allen anderen Rechten ändert sich **nichts**. Ein Trainer mit seinen
  gewohnten Rechten behält jeden Tab, den er heute sieht; auch das Recht
  „alle Mannschaften einsehen" zusätzlich zu haben, nimmt ihm nichts weg.
- Dass das Recht allein keinen allgemeinen Schwimmen-Zugang mehr verleiht,
  ist beabsichtigt: Es soll Lesen ermöglichen, nicht die Tür zu den
  `intern`-Endpunkten öffnen.
- Technisch steht die Liste solcher Rechte an einer Stelle
  (`LSV07I_Permissions::tab_scoped_rights()`) und ist dort erklärt, damit
  künftige reine Zusatz-Leserechte denselben Weg nehmen können.

### Nebenbei

Beim Öffnen des Schwimmbereichs wurde die **Wettkampfliste immer im
Hintergrund geladen**, auch ohne Wettkampf-Tab. Für einen Nutzer mit reinem
Leserecht hätte das beim Betreten eine Fehlermeldung für Daten ergeben, die
er gar nicht sehen darf. Sie wird jetzt nur noch geladen, wenn es den Tab
für diesen Nutzer gibt.

### Geprüft

- 39 Prüfungen gegen eine echte Datenbank (6 neu): dass mit dem Recht allein
  **nur** `sw_mann` offen ist, der Schwimmbereich selbst aber erreichbar
  bleibt, dass daraus kein allgemeiner Schwimmen-Zugang wird — und die
  Gegenrichtung: ein Trainer mit weiteren Rechten behält seine Tabs und
  bleibt interner Nutzer.
- 29 Prüfungen in der echten Oberfläche (14 neu), mit einer eigenen
  Test-Sicht für genau dieses Recht: im Schwimmbereich steht genau ein
  Navigationsziel, und zwar Mannschaften; zu Anwesenheit, Wettkämpfen,
  Springer, Bestzeiten, Reflexion und Trainingsplan führt kein Weg; die
  Mannschaften samt Schwimmerzeilen und Nur-Lese-Hinweis sind da; und die
  Wettkampfliste wird gar nicht erst angefragt. Dazu die Gegenprobe, dass
  ein normaler Trainer weiterhin alle seine Tabs hat.
- Bestehende Prüfungen unverändert grün: Admin→Trainer (28 + 24),
  Abrechnung (30 + 19 + 23), Trainingsplan (68 + 58 + 41 + 51), statische
  Prüfung, Klick-Durchlauf über alle fünf Nutzerarten (90 Klicks, 0 Fehler).
