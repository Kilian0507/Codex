# LSV07 Interner Bereich — 8.29.0

## Trainerkachel im Mannschaftsbuch

Direkt unter den Filtern — über der Anwesenheit — steht im Dashboard
jetzt die Kachel **Trainer**. Sie listet alle Trainerinnen und Trainer
dieses Trainingstages, jeweils mit einem Knopf **Abrechnen**.

Ein Druck trägt den Trainingstag in die Abrechnung der Person ein, der
Knopf wird grün und heisst dann *Abgerechnet*; ein zweiter Druck nimmt
den Eintrag wieder heraus. Die Kopfzeile zählt mit: „1 von 3
abgerechnet".

**Es ist derselbe Weg wie der Haken „anwesend" bei einem Trainer im
Reiter „Anwesenheit"** — nicht etwas Ähnliches. Dieselbe Zeile in
derselben Tabelle, danach dieselbe Abrechnungs-Synchronisation. Damit
gilt hier unverändert, was dort gilt:

* Die Stunden kommen aus der hinterlegten Trainingszeit (mindestens
  eine halbe Stunde, ohne Zeiten 1,5 Stunden).
* Der Trainingstag landet in der Schwimm-Abrechnung des Quartals, in das
  das Datum fällt; fehlt sie, wird sie als Entwurf angelegt.
* **Springer** werden über ihre eigene Quelle abgerechnet und sind in
  der Liste als solche gekennzeichnet. Sie erscheinen nur an dem Termin,
  für den sie eingetragen sind.
* Ein Eintrag, der in der Abrechnung von Hand gelöscht wurde, wird nicht
  wieder angelegt.
* Wer im Reiter „Anwesenheit" schon auf anwesend steht, ist hier bereits
  als abgerechnet zu sehen — und umgekehrt.

Trainer, die dauerhaft von diesem Termin ausgeschlossen sind, stehen
durchgestrichen in der Liste und haben keinen Knopf. Sie verschwinden
nicht einfach, sonst wundert sich jemand, warum eine Person fehlt.

Eingetragen werden darf nur für die eigenen Mannschaften, mit dem
Schreibrecht des Mannschaftsbuchs.

## Geprüft

* **162 Prüfungen** gegen echtes SQL, davon 28 neue: die Trainerliste
  (nur aktive, Springer nur an ihrem Termin, Reihenfolge), der Eintrag
  in der Anwesenheit, der Trainingstag in der richtigen Quartals-
  abrechnung mit Quelle, Datum und Stunden aus der Trainingszeit, kein
  zweiter Eintrag bei doppeltem Druck, die Springer-Quelle, das
  Herausnehmen (und dass es den Springer nicht mitnimmt), von Hand
  gelöschte Einträge, ausgeschlossene und fremde Trainer sowie das
  Schreibrecht.
* **126 Prüfungen** im Browser: Lage der Kachel unter den Filtern und
  über der Anwesenheit, die Liste mit Springer-Kennzeichnung, der Knopf
  in beiden Zuständen und die Mitzählung in der Kopfzeile.
* **71 Prüfungen** auf schmalen Geräten, darunter neu: Der Knopf bleibt
  in seiner Zeile und ist gross genug zum Treffen.

Dafür führt der Prüfstand jetzt auch MySQLs `DELETE <alias> FROM … JOIN`
aus — die Form, mit der das Plugin Abrechnungseinträge über einen
Verbund löscht. Ohne die Übersetzung wäre genau dieser Weg ungeprüft
geblieben.
