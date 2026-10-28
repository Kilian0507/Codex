# LSV07 SwimSys 0.3.0

Der interne Bereich, neu gebaut — **auf denselben Daten**, parallel zum
alten Plugin.

> **Stand: im Bau.** SwimSys kann den internen Bereich heute noch nicht
> ersetzen. Fertig sind Fundament, Anwesenheit, Mannschaftsbuch,
> Stammdatenpflege, Atteste, Akte, Suche, Aufgabenliste, Protokoll und
> die Übernahme des Schemas. Wettkämpfe, Meldungen, Bestzeiten,
> Abrechnung, Nachrichten, Anfragen, Reflexion, Trainingsplan und die
> Verwaltung fehlen noch und stehen weiter im internen Bereich. Was
> fehlt, sagt SwimSys an der jeweiligen Stelle offen. Die vollständige
> Liste steht unten unter „Was noch fehlt".

SwimSys legt keine eigenen Tabellen für Vereinsdaten an und schreibt kein
Schema. Es liest und schreibt die Tabellen des internen Bereichs
(`lsv07i_*`, `lsv07_gruppen`, `mv_swimmers`). Beide Plugins können
gleichzeitig laufen: Was in SwimSys erfasst wird, steht im internen
Bereich, und umgekehrt.

## Einrichten

1. Plugin aktivieren. Der interne Bereich (**LSV07 Intern**) muss
   installiert sein oder gewesen sein — von dort kommt das Schema.
2. Eine Seite anlegen und den Shortcode `[swimsys]` einsetzen. Die Seite
   stellt sich im Vollbild dar, ohne Theme-Kopf, Navigation und Fußzeile.
3. Fertig. Rechte werden **nicht** neu vergeben: SwimSys benutzt dieselben
   Rechte wie der interne Bereich (Tabelle `lsv07i_user_rechte`, vergeben
   unter Admin → Rechte).

Unter **SwimSys** im WordPress-Menü steht, ob alle Tabellen da sind,
welche Sparten eingerichtet sind, wer gesperrt ist und welche Konten noch
keine Rechte haben.

## Was anders ist

### Jede Ansicht hat eine Adresse

Der interne Bereich kennt keine: 0 Treffer für `location.hash`,
`pushState` und `popstate`. Deshalb gibt es dort keinen Browser-Zurück,
keinen Link, den man jemandem schicken kann, und kein Lesezeichen; der
gemerkte Ort hält eine Stunde.

In SwimSys steht der Ort in der Adresszeile:

```
#schwimmen/anwesenheit/4/2026-10-20
 Bereich   Teil         Mannschaft  Tag
```

Der Zurück-Knopf arbeitet, ein Link führt jemand anderen an dieselbe
Stelle, und ein Lesezeichen auf „Anwesenheit Jugend A" ist möglich. Eine
von Hand getippte Adresse öffnet nichts, was das Konto nicht sehen darf —
der Server prüft dasselbe Recht noch einmal.

### Eine Suche über alles

Der interne Bereich hat 12 Suchfelder, jedes in genau einer Liste. SwimSys
hat eine Suche im Kopfband (Tastenkürzel: **`/`**) über Sportler,
Mannschaften, Trainer, Wettkämpfe und Anfragen.

Sie prüft je Quelle das Recht, das die zugehörige Ansicht verlangt. Wer
Atteste nicht sehen darf, findet über die Suche keine. Eine Suche, die
alles findet, ist ein Datenleck mit Eingabefeld.

### Die Startseite sagt, was zu tun ist

Statt „letzte fünf Trainings" und „Top 5 Schwimmer" steht dort, was eine
Handlung verlangt:

* eine Abrechnung, die noch nicht eingereicht oder zurückgegeben ist
* Abrechnungen, die auf Prüfung oder Zahlung warten
* Atteste, die abgelaufen sind oder ablaufen
* Trainings der letzten zwei Wochen ohne erfasste Anwesenheit
* offene Aufgaben aus dem Mannschaftsbuch
* offene Anfragen und ungelesene Nachrichten

Jede Zeile prüft das Recht der Ansicht, zu der sie führt: Wer die Atteste
nicht sehen darf, erfährt auch nicht, wie viele ablaufen.

### Anwesenheit aus einer Quelle

Im internen Bereich gibt es zwei Anwesenheitswelten. Das Mannschaftsbuch
führt sechs Zustände, Verspätung mit Grund, Vermerke und Aufgaben. Die
ältere Anwesenheit führt drei Zustände — und Akte, Profil, Startseite und
Saisonbericht lesen **nur** die ältere (`mb_anwesenheit` kommt in diesen
vier Dateien nicht ein einziges Mal vor). Alles, was im Buch erfasst wird,
ist außerhalb des Buches unsichtbar.

In SwimSys gilt eine Antwort auf „war diese Person an diesem Tag da":

| Zustand | zählt als |
|---|---|
| Anwesend | war da |
| Abwesend | gefehlt |
| Krank | entschuldigt |
| Entschuldigt | entschuldigt |
| Beurlaubt | entschuldigt |
| Unentschuldigt | gefehlt |

Das Buch ist die Wahrheit. Wo es nichts sagt, wird der Altbestand gelesen
und auf die sechs Zustände hochgerechnet — solche Zeilen sind in der
Oberfläche als **alt** gekennzeichnet, weil aus drei Zuständen „krank",
„beurlaubt" und eine Verspätung nicht zurückzugewinnen sind.

Geschrieben wird in **beide** Tabellen. Nicht aus Bequemlichkeit: Der
interne Bereich läuft weiter, und seine Ansichten blieben sonst leer.

### Eine Fassung für drei Sparten

Triathlon und Fitness sind im internen Bereich Kopien voneinander — 569
gegen 572 Zeilen PHP, nach Umbenennung 13 abweichende Zeilen; im Skript
421 gegen 414 mit 59 Abweichungen. Das hat Folgen, die man im Betrieb
merkt:

* Beiden fehlt, was Schwimmen hat: Statistik, Springer, Attest, Mahnung.
* Beide rechnen dem Trainer **feste 2,0 Stunden** an, Schwimmen die Dauer
  des Slots. Für ein 90-Minuten-Training sind das 30 Minuten zu viel,
  bezahlt, jede Woche.
* `saison_id` wird in `tri_slots` und `fit_slots` **nie geschrieben** —
  die Saisonlogik greift dort gar nicht.

In SwimSys steht jede Sparte als Zeile in einer Tabelle (`LSV07S_Sparte`),
und eine Fassung des Codes arbeitet für alle drei. Die Stunden sind
überall die Dauer des Slots. Eine vierte Sparte wäre eine Zeile, keine
vierte Kopie.

### Welche Trainingszeit an einem Tag gilt

Eine Leiter, von der genauesten Antwort zur gröbsten, für alle Sparten
dieselbe:

1. ein Slot am richtigen Wochentag aus der Saison **dieses Datums**,
2. ein Slot am richtigen Wochentag ohne Saison (Altbestand),
3. nichts — dann gibt es 0 Stunden und keine Schätzung.

Die Saison des Datums, nicht die laufende: Wer im Oktober einen
Trainingstag vom September nachträgt, bekommt die Zeiten von September.

### Absagen für einen Zeitraum

Im internen Bereich geht nur ein Datum, und Ferien sind zwei Wochen — zehn
Klicks, von denen einer vergessen wird. In SwimSys gibt es Von und Bis,
einen Grund, und abgesagt werden nur die Tage, an denen diese Mannschaft
wirklich Training hat.

## Die beiden behobenen Löcher

### Ein gesperrtes Konto ist jetzt gesperrt

Die Sperrliste (`blocked_user_ids` in `lsv07i_config`) wurde im internen
Bereich nur in den `is_*`-Methoden geprüft, nicht in der Rechtefrage
selbst. Weil Administratoren dort einen Freibrief haben und 26 Stellen im
Code zusätzlich direkt `current_user_can('administrator')` fragen, bestand
ein gesperrter Administrator **jede** Prüfung. Ein Eingabefeld für die
Liste gibt es dort nicht; der Schlüssel steht nur in einer Whitelist.

In SwimSys gilt die Sperre für jedes Konto und als Erstes.

Offen gesagt: Gegen jemanden mit WordPress-Administratorrechten ist das
keine Sicherheitsgrenze — wer das Backend hat, kann das Plugin abschalten.
Die Sperre ist eine Vereinsregel im Programm, kein Schutz gegen den
Serveradministrator.

### Ein Mitgliedsrecht öffnet keinen Trainerbereich mehr

Die Frage „gehört diese Person in den Bereich Schwimmen?" wurde im
internen Bereich mit „hat sie irgendein `schwimmen.*`-Recht?" beantwortet
— und öffnete damit **alle** Kacheln des Bereichs. Ein Sportler mit vier
Leserechten sah deshalb dieselben neun Kacheln wie sein Trainer:
Anwesenheit aller, Mannschaftsbuch, Atteste, Akte.

In SwimSys gibt es die grobe Bereichsfrage nicht. Jede Ansicht nennt ihr
eigenes Recht; ein Bereich erscheint, wenn mindestens eine seiner
Ansichten sichtbar ist. Mit `schwimmen.eigen.read` und
`schwimmen.bestzeit.read` sieht ein Sportler genau zwei Ansichten statt
neun.

## Stammdaten pflegen

Mannschaften und Gruppen, Sportler mit Kontaktpersonen und
Mehrfachzuordnung, Trainer mit Zuordnung, Trainingszeiten mit Saison und
die Saisons selbst — anlegen, ändern, umziehen, stilllegen, löschen. Eine
Fassung für alle drei Sparten; im internen Bereich lässt sich ein
Schwimmer an **sechs** verschiedenen Stellen anlegen oder ändern, und jede
schreibt etwas anderes.

**Grundsatz: Was Spuren hat, wird stillgelegt und nicht gelöscht.**

| | |
|---|---|
| Mannschaft mit Kader | bleibt — bitte zuerst umziehen oder stilllegen |
| Mannschaft mit erfassten Trainings | bleibt — umbenennen, wenn sie nicht mehr gebraucht wird |
| Person mit Anwesenheit, Buch oder Meldungen | wird stillgelegt; gelöscht zeigten ihre Einträge auf niemanden mehr |
| Trainer mit Abrechnungen | bleibt — zehn Jahre Aufbewahrung (§ 147 AO) |
| Trainingszeit | lässt sich löschen; die erfassten Trainings bleiben, und es wird gesagt, wie viele |
| Saison mit Trainingszeiten | bleibt |

Zwei Dinge weist SwimSys ab, die der interne Bereich zulässt:
**überschneidende Trainingszeiten** derselben Mannschaft am selben
Wochentag (sie machen jede Stundenrechnung falsch) und **überlappende
Saisons** (dann hätte „welche Saison gilt an diesem Datum" zwei Antworten,
und die Sortierung entscheidet).

Das Geburtsdatum ist bei Schwimmen Pflicht — `mv_swimmers.birth_date` ist
`NOT NULL`, und bei Meldungen zählt der Jahrgang. Im Triathlon und in
Fitness bleibt es freiwillig.

## Atteste

Ein ärztliches Attest ist ein Gesundheitsdatum (Art. 9 DSGVO). Die Liste
steht unter **Schwimmen → Atteste** und zeigt den ganzen Kader, nicht nur
die Auffälligen: Wer **kein** Attest hinterlegt hat, fällt sonst aus jeder
Liste heraus.

| | SwimSys | interner Bereich |
|---|---|---|
| Am Ablauftag | gilt noch | schon abgelaufen |
| Vorwarnzeit | aus der Einstellung `attest_warnung_tage`, 0 wird zu 30 | fest |
| „Zuletzt erinnert" | steht in der Liste | nur im Protokoll |
| Zweite Erinnerung | erst nach 14 Tagen | jederzeit erneut |
| Ohne Kontaktadresse | der Knopf erscheint nicht, und wer es doch versucht, bekommt den Grund | stilles Nichts |
| Fristen der Trainer | stehen darunter: Führungszeugnis, Rettungsschwimmabzeichen, Lizenz | die Felder gibt es, niemand sieht sie ablaufen |
| Abruf einer Datei | steht im Protokoll | nicht |

In derselben Tabelle `lsv07i_schwimmer_dateien` liegen auch die
allgemeinen Dateien eines Sportlers, und die verlangen im internen
Bereich ein **anderes** Recht (`schwimmen.datei.read`). SwimSys filtert
deshalb auf das Kennzeichen `art = 'attest'`: Ohne diesen Filter öffnete
`attest.read` auch sie — eine Hintertür in einen Bereich, für den das
Konto kein Recht hat. Angehängt gilt höchstens **ein** Attest je Person;
beim Hochladen wandern die älteren ins Archiv und stehen in der Liste als
Archiv gekennzeichnet. Täte SwimSys das nicht, zeigten beide Plugins
verschiedene Atteste derselben Person.

Beim Hochladen entscheidet der **Inhalt**, nicht die Endung: Ein als
`attest.pdf` benanntes PHP-Skript wird abgewiesen. Der gespeicherte Name
wird neu gebildet, damit aus dem Originalnamen kein Pfad und kein Punkt in
den Ordner gelangt; erlaubt sind PDF, JPG und PNG bis 10 MB.

Der Ordner liegt in den Uploads und trägt eine `.htaccess`, eine stille
`index.php` und — weil nginx keine `.htaccess` liest — eine Datei
`NGINX-LESEN.txt` mit der Regel, die in die Serverkonfiguration gehört.
Ohne diesen Hinweis hält man den Ordner für geschützt, weil eine
`.htaccess` darin liegt, und sie tut nichts. Ausgegeben wird eine Datei
nur über das Tor von SwimSys, mit `nosniff` und als Anhang.

**Verschlüsselt wird hier nicht**, und das ist eine Abwägung, kein
Versehen: Es ist derselbe Ordner, aus dem der interne Bereich dieselben
Dateien unverschlüsselt ausliefert. Eine Verschlüsselung würde dort jede
Datei unlesbar machen, solange beide Plugins laufen. Sobald der interne
Bereich abgeschaltet ist, kann die Ablage auf den Tresor der Abrechnung
umgestellt werden; bis dahin schützt der gesperrte Ordner.

## Die Akte

Alles zu einer Person an einer Stelle: Mannschaften, Atteststand,
Anwesenheit mit Verlauf über die Monate, die Einträge aus dem
Mannschaftsbuch, Wettkampfmeldungen, Bestzeiten, Reflexionsbögen,
Kontaktpersonen, Vermerk. Im internen Bereich steht dasselbe über sieben
Reiter verteilt, und niemand sieht den Zusammenhang.

Drei Dinge gehören zu dieser Ansicht dazu:

* Sie verlangt ihr **eigenes** Recht (`schwimmen.akte.read`). Wer den
  Kader sehen darf, sieht damit nicht die Akte.
* Das **Attest** steht nur darin, wenn das Konto es auch in der
  Attestliste sehen dürfte.
* Das **Öffnen steht im Protokoll**. Eine Zusammenstellung über eine
  Person ist mehr als die Summe ihrer Teile; zur Auskunft nach Art. 15
  gehört, wer sie gesehen hat.

Der Zeitraum steht in der Adresse
(`#schwimmen/akte/11/2026-09-01/2026-10-20`) und ist damit verlinkbar.
Ohne Angabe gilt die laufende Saison — das ist der Zeitraum, über den man
im Elterngespräch redet.

## Den internen Bereich ablösen

SwimSys kann das Schema selbst anlegen, sobald der interne Bereich nicht
mehr aktiv ist — vorher **nicht**: Zwei Plugins, die dieselbe Tabelle
anlegen und nachbessern, bessern irgendwann gegeneinander nach, und wer
welche Spalte gesetzt hat, ist hinterher nicht zu ermitteln.

Die Schemadatei (`includes/class-schema.php`, 78 Tabellen) ist aus den
Quellen des internen Bereichs **erzeugt**, nicht abgeschrieben. Eine
Prüfung liest diese Quellen bei jedem Lauf und meldet, wenn dort eine
Tabelle dazukommt — sonst liefe die erzeugte Fassung irgendwann hinterher.

Der Weg, wenn es soweit ist:

1. SwimSys aufrufen und prüfen, dass jeder Bereich Daten zeigt.
2. Im WordPress-Menü unter **SwimSys** nachsehen, welche Konten noch keine
   Rechte haben — diese Konten sehen nach dem Umschalten nichts.
3. Den Shortcode auf der Seite durch `[swimsys]` ersetzen, die alte Seite
   noch nicht löschen.
4. Eine Woche beide Plugins parallel laufen lassen.
5. Den internen Bereich **deaktivieren, nicht löschen**. SwimSys legt ab
   dem nächsten Aufruf das Schema selbst nach.
6. Nach einer weiteren Woche ohne Beanstandung löschen. Die Tabellen
   bleiben stehen — sie gehören dann SwimSys.

## Was noch fehlt

Gebaut sind: Aufgabenliste, Mannschaften und Kader, Anwesenheit mit
Auswertung (alle drei Sparten), Mannschaftsbuch (Vermerke, Aufgaben,
Doku, Bahnen, Springer, Trainerstunden), Stammdatenpflege, Atteste und
Akte, Suche, Protokoll, Schemaübernahme.

Noch nicht gebaut:

| fehlt | steht weiter im internen Bereich |
|---|---|
| Wettkämpfe, Meldungen, Bestzeiten | ja |
| Abrechnung der Trainerstunden | ja |
| Nachrichten und Anfragen | ja |
| Reflexion und Trainingsplan | ja |
| Verwaltung (Rechte, Konfiguration, Mails, Personen, Import) | ja |
| Erinnerungen per Cron | ja |
| Auskunft und Löschung nach Art. 15/17 DSGVO | ja |

Der interne Bereich hat 267 angemeldete Endpunkte, SwimSys hat 45. Beide
Plugins arbeiten auf denselben Daten, es geht nichts verloren — und
SwimSys sagt an jeder fehlenden Stelle offen, dass der Teil noch fehlt,
statt leer zu bleiben.

## Rechte, die SwimSys abfragt

Dieselben Zeichenketten wie im internen Bereich.

| Ansicht | Recht |
|---|---|
| Mannschaften | `schwimmen.mannschaft.read` oder `…read_all` |
| Anwesenheit | `schwimmen.anw.read` oder `mannschaftsbuch.read` |
| Anwesenheit ändern | `schwimmen.anw.update` oder `mannschaftsbuch.edit` |
| Training absagen | `schwimmen.anw.ausfall` oder `mannschaftsbuch.edit` |
| Atteste | `attest.read` oder `attest.edit` |
| Attest ändern, Datei ablegen | `attest.edit` |
| Erinnerung senden | `attest.edit` oder `attest.mail` |
| Akte | `schwimmen.akte.read` |
| Meine Daten | `schwimmen.eigen.read` |
| Triathlon / Fitness | `triathlon.*` / `fitness.*` mit denselben Endungen |
| Abrechnung | `abrechnung.eigen.read`, `…wart.read`, `…kasse.read` |
| Verwaltung | `admin.config.read` |

Wer über die eigenen Mannschaften hinaussieht: `schwimmen.mannschaft.create`
(klassische Schwimmwart-Aufgabe) oder `schwimmen.mannschaft.read_all` —
oder Administration.

**Kein Rückfall auf alte WordPress-Rollen.** Der interne Bereich behandelt
Konten ohne vergebene Rechte nach ihrer alten Rolle. SwimSys rät nicht:
Wem nichts vergeben ist, dem wird gesagt, dass nichts vergeben ist. Welche
Konten das betrifft, steht im WordPress-Menü unter SwimSys.

## Protokoll

Jede Änderung steht in `lsv07i_log` — demselben Protokoll wie im internen
Bereich, im Bereich mit `swimsys` gekennzeichnet. Die IP-Adresse wird
gekürzt gespeichert (bei IPv4 ohne das letzte Viertel, bei IPv6 ohne die
zweite Hälfte): genug, um einen Zugriff aus dem Vereinsnetz von einem aus
dem Ausland zu unterscheiden, zu wenig, um ein Gerät zu verfolgen.

Im internen Bereich fehlt der Protokolleintrag ganz in sieben Bereichen
(Mannschaftsbuch, Bestzeiten, Triathlon, Fitness, Schwimmen,
Personenverwaltung, Import).

Bei Gesundheitsdaten steht zusätzlich das **Lesen** darin, nicht nur das
Ändern: `attest.angesehen` für jeden Abruf einer Attestdatei und
`akte.geoeffnet` für jede geöffnete Akte. Bei der Erinnerungsmail steht
**nicht** die Adresse der Kontaktperson im Protokoll — dass erinnert
wurde, ist der Zweck; die Adresse wäre eine zweite Kopie, die niemand
braucht.

## Zur Gestaltung

Dieselbe Formensprache wie Abrechnung, interner Bereich, SwimPlan und
SwimView: Segoe UI mit Inter Tight als Rückfall (selbst gehostet, keine
Schriftdienste Dritter), 14 px Grundgröße, kleine Radien, Haarlinien statt
Schlagschatten, dichtes Raster, dunkelblaues Kopfband mit den Bereichen
als Pivot-Reiter und einer zweiten Reiterleiste darunter.

Die Farbe sagt, was ein Knopf tut: **grün** es geht voran, **rot** es geht
zurück, **blau** es öffnet nur. Zustände werden nie über Farbe allein
unterschieden — sie stehen immer zusätzlich ausgeschrieben da.

Auf dem Handy klappen die Bereiche als Liste auf, und Tabellen werden zu
Zeilenblöcken mit Spaltenbeschriftung; sechs Spalten sind auf 360 px nicht
zu lesen.

## Geprüft

* **494 Prüfungen** gegen das **echte Schema** des internen Bereichs: Die
  `CREATE TABLE`-Anweisungen **und die 58 Spalten, die er später per
  `ALTER TABLE` nachzieht**, werden aus seinen Quelldateien gelesen, nicht
  abgeschrieben. SwimSys arbeitet auf fremden Tabellen — eine Prüfung auf
  einem abgeschriebenen Schema würde genau das nicht prüfen.
  (189 `systest.php`, 95 `sysstammtest.php`, 28 `syserbetest.php`,
  76 `sysbuchtest.php`, 106 `sysattesttest.php`)
* **196 Oberflächenprüfungen** (`sysui.js`) in einem echten Browser: die
  Adresse je Ansicht, Zurück und Vorwärts, Navigation, Dialog, die sechs
  Zustände, Suche, Sortieren, dazu Lesbarkeit (Kontrast über 4,5:1),
  Überlauf und Trefferhöhen auf 1280 px und 360 px.
* **Jede Zusicherung gegengeprüft**: absichtlich gebrochen, um
  nachzusehen, ob eine Prüfung es meldet — 78 Fälle gegen den Code
  (`sysgegenprobe.py`, gegen alle fünf Prüfdateien) und 44 gegen die
  Oberfläche (`sysuigegenprobe.py`).

Dabei fielen Dinge auf, die ohne Gegenprobe durchgegangen wären: Der
Ausfallgrund stand bei Schwimmen in einer anderen Tabelle und wurde nicht
gelesen. Zwei eigene Fassungen von „darf ich Sportler sehen" liefen
auseinander — der Wart fand über die Suche keinen einzigen Schwimmer,
obwohl die Liste ihm alle zeigt. Drei Zeilen prüften etwas, das an anderer
Stelle schon entschieden war, und verdeckten damit die Regel; sie sind
weg. Und `font:inherit` auf Knöpfe setzt auch das Schriftgewicht zurück —
weil die Regel eine ID vorne hat, schlägt sie jedes `font-weight` einer
Klasse. **In der Abrechnung ist genau das der Fall**: dort rendern
`.a-btn` und `.a-btn-p` mit 400 statt 500 und 600.

Beim Bau der Stammdatenpflege kamen vier weitere dazu, einer davon ernst:

* **`darf_mannschaft()` prüfte immer die Schwimmmannschaften.** Die
  Nummern der drei Sparten kommen aus verschiedenen Tabellen
  (`lsv07_gruppen`, `lsv07i_tri_gruppen`, `lsv07i_fit_gruppen`) und können
  sich überschneiden: Die Zuständigkeit für Schwimmmannschaft 3 öffnete
  damit Triathlongruppe 3. Die Sparte gehört jetzt zur Frage, an jeder
  Aufrufstelle.
* **Die E-Mail-Prüfung konnte nie zuschlagen.** `sanitize_email()` macht
  aus Unsinn eine leere Zeichenkette, und geprüft wurde danach. Wer eine
  kaputte Adresse eintippte, bekam keinen Hinweis — das Feld war still
  leer, und die Erinnerungsmail ging später an niemanden. Jetzt wird vor
  dem Säubern geprüft.
* `mv_swimmers.birth_date` ist `NOT NULL`: Ohne Geburtsdatum kam ein
  Datenbankfehler statt eines Satzes.
* Ein Speichern setzte „aktiv" nebenbei auf 1 — eine stillgelegte Person
  wird im internen Bereich durch eine Namenskorrektur wieder aktiv.

Bei Attesten und Akte kamen vier dazu, zwei davon ernst:

* **Das Schema kannte 20 von 58 nachgezogenen Spalten nicht.** Der interne
  Bereich legt seine Tabellen mit `CREATE TABLE` an und fügt danach per
  `ALTER TABLE ADD COLUMN` weitere Spalten hinzu — 43 Stellen, dazu zwei
  Schleifen über Feldlisten. Wer nur die `CREATE TABLE`-Anweisungen liest,
  hält eine Tabelle für vollständig, die es nicht ist: `lsv07i_trainer`
  hat dort keine der drei Fristspalten, und `trainer_fristen()` lief
  deshalb ins Leere, ohne Fehler. Gelesen werden jetzt alle vier
  Schreibweisen, an **einer** Stelle (`alter_laden()`), und derselbe Code
  speist den Prüfstand und die erzeugte Schemadatei. Die Prüfung verlangt
  für **jede** nachgezogene Spalte, dass sie im Schema steht.
* **`lsv07i_schwimmer_dateien` fehlte in der Tabellenliste.** Ein
  unbekannter Schlüssel liefert dort einen leeren Namen — die Abfrage
  ginge auf die Tabelle `wp_`, und die Oberfläche zeigte „keine Dateien"
  statt eines Fehlers. Gemeldet wurde es nur über `_doing_it_wrong`, das
  der Prüfstand bloss ausgab. Jetzt zählt jede solche Meldung als
  Fehlschlag.
* **Die Gegenprobe lief nur gegen eine Prüfdatei.** Ein Eingriff in
  `class-buch.php` oder `class-attest.php` wäre unbemerkt geblieben — die
  Gegenprobe hätte „geprüft" gemeldet, wo niemand hinsieht. Sie läuft
  jetzt gegen alle fünf.
* **Die Akte prüfte ihr Recht, aber niemand sah hin.** Das Konto in der
  Prüfung hatte überhaupt keine Rechte und scheiterte schon am Zugang;
  die Zusicherung „ohne Aktenrecht keine Akte" wäre auch grün geblieben,
  wenn die Abfrage ganz entfällt. Dafür gibt es jetzt ein Konto mit
  Kaderzugriff und ohne Aktenrecht.
