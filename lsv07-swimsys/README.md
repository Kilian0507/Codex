# LSV07 SwimSys 0.7.0

Der interne Bereich, neu gebaut — **auf denselben Daten**, parallel zum
alten Plugin.

> **Stand: im Bau.** SwimSys kann den internen Bereich heute noch nicht
> ersetzen. Fertig sind Fundament, Anwesenheit, Mannschaftsbuch,
> Stammdatenpflege, Atteste, Akte, Wettkämpfe, Meldungen, Bestzeiten,
> Stundenübersicht, Nachrichten, Anfragen, Reflexionsbögen samt der
> Seite zum Ausfüllen, Trainingspläne, die Schalterseite, Suche,
> Aufgabenliste, Protokoll und die Übernahme des Schemas. Die übrige
> Verwaltung (Konfiguration, Mails, Personen, Import), die Erinnerungen
> per Cron und die Auskunft nach Art. 15/17 DSGVO fehlen noch und stehen
> weiter im internen Bereich. Was fehlt, sagt SwimSys an der jeweiligen
> Stelle offen. Die vollständige Liste steht unten unter „Was noch
> fehlt".

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
3. In SwimSys unter **Verwaltung → Funktionen** einschalten, was gelten
   soll. Angemeldet wird über das WordPress-Konto; schalten darf nur ein
   WordPress-Administrator.

**SwimSys fragt die alte Rechtetabelle nicht mehr.** Was ein Konto sieht
und tun darf, entscheiden Schalter — global und je Konto. Die Tabelle
`lsv07i_user_rechte` bleibt stehen, weil der interne Bereich sie weiter
liest; SwimSys schreibt dort nichts. Ein Knopf auf der Schalterseite
übernimmt die alten Rechte **einmalig** als Vorschlag.

Unter **SwimSys** im WordPress-Menü steht, ob alle Tabellen da sind,
welche Sparten eingerichtet sind, wer gesperrt ist und für welche Konten
noch keine Funktion eingeschaltet ist.

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
der Server prüft denselben Schalter noch einmal. Der Schlüssel eines
Schalters **ist** die Adresse: Was in der Adresszeile
`#schwimmen/anwesenheit` heisst, heisst als Funktion `schwimmen/anwesenheit`.

### Eine Suche über alles

Der interne Bereich hat 12 Suchfelder, jedes in genau einer Liste. SwimSys
hat eine Suche im Kopfband (Tastenkürzel: **`/`**) über Sportler,
Mannschaften, Trainer, Wettkämpfe und Anfragen.

Sie prüft je Quelle den Schalter der zugehörigen Ansicht. Wer Atteste
nicht eingeschaltet hat, findet über die Suche keine. Eine Suche, die
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

Jede Zeile prüft den Schalter der Ansicht, zu der sie führt: Wer die
Atteste nicht eingeschaltet hat, erfährt auch nicht, wie viele ablaufen.

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

In SwimSys gibt es die grobe Bereichsfrage nicht. Jede Ansicht hat ihren
eigenen Schalter; ein Bereich erscheint, wenn mindestens eine seiner
Ansichten eingeschaltet ist. Sind für ein Konto nur „Meine Daten" und
„Bestzeiten" an, sieht es genau zwei Ansichten statt neun.

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

* Sie verlangt ihren **eigenen** Schalter (`schwimmen/akte`). Wer den
  Kader sehen darf, sieht damit nicht die Akte.
* Das **Attest** steht nur darin, wenn für das Konto auch der Schalter
  „Atteste" an ist.
* Das **Öffnen steht im Protokoll**. Eine Zusammenstellung über eine
  Person ist mehr als die Summe ihrer Teile; zur Auskunft nach Art. 15
  gehört, wer sie gesehen hat.

Der Zeitraum steht in der Adresse
(`#schwimmen/akte/11/2026-09-01/2026-10-20`) und ist damit verlinkbar.
Ohne Angabe gilt die laufende Saison — das ist der Zeitraum, über den man
im Elterngespräch redet.

## Wettkämpfe

Ein Wettkampf hat Name, Ort, einen Zeitraum, beteiligte Mannschaften und
je Tag eine Zeile mit der geplanten Abschnittszahl. Je Tag und
Mannschaft gibt es eine Anwesenheitsliste; ein dort als anwesend
eingetragener Trainer bekommt den Betrag (Abschnitte × Pauschale) in
seine Abrechnung des Quartals.

| | SwimSys | interner Bereich |
|---|---|---|
| Abrechnung nachziehen | nur solange sie offen ist; sonst bleibt sie stehen, und es wird gesagt | auch in einer eingereichten oder genehmigten — ihr Betrag ändert sich hinter dem Rücken der Kasse |
| Wettkampftag in der Zukunft | nimmt keine Anwesenheit an | lässt sich für den nächsten Monat abrechnen |
| Eine Liste ansehen | legt nichts an | legt schon beim Lesen eine leere Liste an |
| Freigabe nicht möglich | es steht da, ob die Ausschreibung oder der Haken fehlt | immer derselbe Satz |
| Ausschreibung gelöscht | Freigabe geht mit — ihre Grundlage ist entfallen | bleibt freigegeben |
| Löschen | sagt, was mitgeht, und weist ab, wenn eine nicht mehr offene Abrechnung daran hängt | löscht mit |
| Zeitraum über ein Jahr | wird abgewiesen | legt 365 Tage an |
| Protokoll | jede Änderung | nur Freigabe und Löschen |

Die Pauschale wird beim Anlegen festgeschrieben: Sie steht später in
jeder Abrechnungszeile dieses Wettkampfs, und eine Erhöhung im nächsten
Jahr darf einen alten Wettkampf nicht nachträglich teurer machen.

Die Dokumente (Ausschreibung, Meldeergebnis, Protokoll) liegen im
Nachbarordner der Atteste und sind genauso gesperrt; auch hier
entscheidet der Inhalt, nicht die Endung, und erlaubt ist nur PDF. Bei
einem **freigegebenen** Wettkampf sind sie öffentlich — das ist der
Zweck der Freigabe; vorher braucht es Anmeldung und Leserecht.

## Meldungen

Je Wettkampf und Mannschaft eine Meldeliste: ein Kopf mit Abschnitten
und Wettkampfnummern, darunter je Start eine Zeile. Name und Jahrgang
liegen in der Startzeile als Kopie — eine abgegebene Meldung bleibt
richtig, auch wenn der Schwimmer später die Mannschaft wechselt.

Drei Dinge sind anders:

* **Die Meldung sagt, was ihr fehlt.** Starts ohne Meldezeit stehen
  oben, und wer **am Wettkampftag** kein gültiges Attest hat, ebenso —
  geprüft auf den Wettkampftag, nicht auf heute. Im internen Bereich
  fällt beides erst am Beckenrand auf.
* **Die Meldezeit kommt aus der Bestzeit**, auf Knopfdruck. Dort wird sie
  abgetippt, obwohl sie im Nachbarreiter steht.
* **Melden kann nur eine beteiligte Mannschaft.** Fehlt sie beim
  Wettkampf, sagt SwimSys das, statt eine Meldeliste anzulegen, die dort
  niemand erwartet.

Einen Stand „abgegeben" gibt es **nicht**, und das ist Absicht: Dafür
bräuchte `lsv07i_meldung` eine Spalte, und SwimSys ändert das Schema des
internen Bereichs nicht, solange der läuft. An dem Tag, an dem SwimSys
das Schema übernimmt, kann sie dazukommen.

## Bestzeiten

Je Schwimmer und Strecke eine Zeit, erfasst über einen eingefügten Block
aus einer Tabelle — erst eine Vorschau, dann das Übernehmen.

| | SwimSys | interner Bereich |
|---|---|---|
| Vorschau | nennt neben jeder Zeit die bisherige und ob die neue schneller oder langsamer ist | zeigt erst nach dem Übernehmen, wie viele ersetzt wurden |
| Unsichere Zuordnung | bleibt leer und muss gewählt werden | wird bis zur Stufe „Tippfehler" geraten |
| Übernehmen | ersetzt nur die Strecken, die im Block vorkommen | löscht **alle** Zeiten der Person, auch die anderen siebzehn Strecken |
| Protokoll | jede Änderung | gar nichts |

Eine **Verlaufsliste** je Strecke (Datum, Wettkampf, Bahnlänge) gibt es
nicht: `lsv07i_bestzeiten` hat einen eindeutigen Schlüssel über
Schwimmer und Strecke und keine Spalte für das Datum. Ein Verlauf
bräuchte eine Änderung am Schema des internen Bereichs — und die nimmt
SwimSys sich nicht, solange der läuft.

## Meine Stunden — und warum SwimSys nicht abrechnet

Die Abrechnung gibt es schon zweimal: im internen Bereich
(`lsv07i_abrechnung`, 31 Endpunkte) und im Plugin
**Übungsleiterabrechnung** (`lsv07a_*`), das sie ersetzt. Dieses Plugin
leitet seine Posten direkt aus `lsv07i_anwesenheit`, `lsv07i_springer`
und `lsv07i_wettkampf_anw_eintraege` ab — also aus derselben Quelle, die
SwimSys führt.

Eine dritte Fassung wäre genau der Fehler, gegen den SwimSys gebaut ist.
Deshalb zeigt SwimSys nur, **was gezählt wird**:

| | SwimSys | Übungsleiterabrechnung |
|---|---|---|
| Trainings, Stunden, Wettkampfabschnitte | ja | ja, als Posten |
| Stundensatz, Pauschalen, Beträge | **nein** | ja |
| Einreichen, Genehmigen, Bezahlen, Belege | **nein** | ja |

Der Nutzen bleibt: Ein Trainer sieht **vor** dem Quartalsende, was in
seiner Abrechnung stehen wird — und ob ein Training fehlt. Im internen
Bereich sieht er das erst, nachdem die Abrechnung erzeugt wurde. Unter
**Stunden → Stunden aller Trainer** steht dasselbe für Prüfung und
Kasse, damit ein fehlendes Training auffällt, bevor die Abrechnung
daraus entsteht.

Gezählt wird streng: Ein Training, bei dem der Trainer nicht als
anwesend steht, zählt nicht. Ein abgesagtes zählt nicht — auch dann
nicht, wenn die Absage **nur** in `lsv07i_training_ausfall` steht, so wie
der interne Bereich sie schreibt. Eine Springer-Schicht zählt, und wer am
selben Tag beides hat, zählt einmal. Fehlt die Trainingszeit, stehen 0
Stunden und der Grund dabei — geschätzt wird nicht.

Die **Aufgabenliste** fragt das System, das wirklich abrechnet: Ist die
Tabelle der Übungsleiterabrechnung da, gilt deren Stand; sonst der des
internen Bereichs. Ohne diese Weiche fragte die Liste eine Tabelle, in
die niemand mehr schreibt.

Was dem Abrechnungsplugin gegenüber dem internen Bereich noch fehlt und
dorthin gehört, nicht hierher: **Kosten je Mannschaft** (Jahr × Quartal)
und die **Jahresübersicht**.

## Nachrichten

Drei Arten von Unterhaltung: mit einer Person, als Gruppe, oder als
Verteiler an eine Mannschaft (dann sind ihre Trainer dabei).

| | SwimSys | interner Bereich |
|---|---|---|
| Stummgeschaltet | zählt nicht in die Zahl oben | schaltet nur die Mail ab und erinnert weiter |
| Gelöschte Nachricht | bleibt als Lücke sichtbar, ohne Text | verschwindet — in einer Unterhaltung mit Lücken weiß niemand mehr, worauf eine Antwort sich bezog |
| Ändern und Löschen | nur die eigene, nur innerhalb von 24 Stunden | ohne Frist |
| Zweite Unterhaltung mit derselben Person | gibt es nicht; der zweite Anlauf öffnet die vorhandene | entsteht, und die Antwort landet in der falschen |
| Anhang abrufen | nur Teilnehmer, angemeldet | wer den Verweis mit Token hat, auch ohne Anmeldung |
| Konto ohne Zugang | kommt nicht in die Auswahl | steht darin und bekommt die Nachricht nie zu sehen |
| Mitlesen als Administration | erlaubt, steht aber im Protokoll | unbemerkt |
| Protokoll | jede Änderung | keine |

Geht der letzte Verwalter einer Gruppe, wird der älteste verbleibende
Teilnehmer einer — sonst ließe sich die Gruppe nie wieder ändern.

Nicht gebaut und im internen Bereich geblieben: Reaktionen („Daumen
hoch"), „schreibt gerade", der Online-Punkt und die Zustellung an
externe Mailadressen. Das sind Bequemlichkeiten, die ohne eigenen
Serverkanal nur über ständiges Nachfragen gehen.

## Anfragen

Fragen, Ideen, Fehler, Organisatorisches — jede mit Nummer, Kategorie,
Dringlichkeit und Stand. Stellen darf jeder mit Zugang: Wer nicht fragen
kann, schreibt eine Mail, und die steht nirgends.

| | SwimSys | interner Bereich |
|---|---|---|
| Anfrage ändern | solange sie offen ist, durch die fragende Person; danach durch die Bearbeitung | gar nicht — ein Tippfehler im Titel bleibt stehen |
| Zurückziehen | die fragende Person, solange niemand daran arbeitet | nur wer alle Anfragen sieht |
| Verlauf | Kommentare **und** jede Änderung an Titel, Kategorie, Dringlichkeit, Stand und Zuständigkeit | nur Stand und Zuständigkeit |
| Rückmeldung auf „wartet" | bringt die Anfrage zurück in Arbeit | sie wartet weiter, obwohl die Antwort da ist |
| Zugewiesene Anfrage | sieht der Zuständige, auch ohne den Schalter „Alle Anfragen bearbeiten" | nur mit dem Recht auf alle |
| Titel | mindestens acht Zeichen | beliebig, „Hilfe" geht |
| Protokoll | jede Änderung | keine |

## Reflexionsbögen

Je Mannschaft ein Satz Fragen, je Saison und Schwimmer ein Bogen mit einem
Zugangsschlüssel. Was ein Kind sich vornimmt, steht **verschlüsselt** in
der Datenbank: AES-256-GCM, der Schlüssel abgeleitet aus
`wp_salt('secure_auth')` — der steht in `wp-config.php`, nicht in der
Datenbank. Ein Datenbankabzug allein gibt die Antworten nicht her.

Drei Dinge sind ein **Protokoll** mit dem internen Bereich, keine Regel
von SwimSys: die Form des Schlüssel-Hashes (HMAC-SHA256), die
Verschlüsselung und ihr Format (`v1:` + base64(iv|tag|cipher)). Beide
Plugins lesen dieselben Zeilen; weicht eine Fassung ab, sind die
Antworten der anderen unlesbar. Eine Prüfung ruft deshalb die **echte**
Fassung des internen Bereichs auf und vergleicht: derselbe Schlüssel
ergibt denselben Hash, und was das eine verschlüsselt, liest das andere.

| | SwimSys | interner Bereich |
|---|---|---|
| Frage herausnehmen | wird **stillgelegt** — abgegebene Bögen zeigen mit `frage_id` darauf | alle Fragen werden gelöscht und neu geschrieben, mit neuen Nummern |
| Schlüssel verloren | ein neuer lässt sich ausgeben, der alte gilt nicht mehr | es gibt ihn einmal; wer ihn verliert, kommt an seinen Bogen nicht mehr heran |
| Ohne Geburtsdatum | der Bogen entsteht, und es wird gesagt, wer sich nicht anmelden kann | der Bogen steht still da, und niemand weiss, warum der Schlüssel nicht geht |
| Auswertung | die Antworten werden **entschlüsselt** | dort steht `v1:` und Base64 statt einer Antwort |
| Verwalten | ein Schalter für die ganze Ansicht (`schwimmen/reflexion`) — wer sie anhat, darf auch freischalten, archivieren und löschen | wer überhaupt hereinkommt, darf jeden Bogen löschen |
| Wer liest mit | jedes Öffnen eines Bogens und jede Auswertung stehen im Protokoll | nur das Erzeugen |

Der Klartext eines Schlüssels steht **genau einmal** auf dem Bildschirm,
beim Erzeugen; danach liegt in der Datenbank nur sein Hash.

### Die Seite, auf der der Schwimmer ausfüllt

`<irgendeine Seite>?swimsys_reflexion=1` — der Link ist für **alle**
gleich und trägt nichts Personenbezogenes. Hereingelassen wird nur, wer
den Schlüssel **und** das Geburtsdatum hat. Niemand muss sich anmelden,
also ist alles, was eine Anmeldung sonst leistet, hier von Hand gebaut:

* Falscher Schlüssel und falsches Geburtsdatum geben **denselben Satz** —
  sonst rät man erst Schlüssel und dann Geburtsdaten.
* Ein eigenes **CSRF-Token**, als Cookie und im Formular.
* Die Daten kommen **erst nach der Anmeldung**: Die erste Seite ist nur
  das Formular, Fragen und Bestzeiten holt die Seite in einem zweiten
  Aufruf, der das Cookie braucht.
* **CSP** mit `default-src 'none'` und einer Nonce für das eine Skript.
* Das Cookie ist über den Schlüssel-Hash **signiert**: Eine andere Nummer
  darin führt nicht an einen anderen Bogen, und ein neu ausgegebener
  Schlüssel entwertet es.

| | SwimSys | interner Bereich |
|---|---|---|
| Sperre nach 10 Fehlversuchen | wird länger: 15 Minuten, eine Stunde, vier Stunden; der Zähler läuft über die Sperren hinweg weiter | immer 15 Minuten, und der Zähler wird danach auf 0 gestellt — wer wartet, hat dauerhaft zehn Versuche je Viertelstunde |
| Vergessen | nach 24 Stunden Ruhe zählt es von vorn, nach 15 Minuten nur, solange keine Sperre fiel | nach 15 Minuten Ruhe immer |
| Eine Sperre | steht im Protokoll, mit gekürzter Adresse | steht nirgends — niemand erfährt, dass jemand Schlüssel durchprobiert hat |
| Gerechnet wird | mit **einer** Uhr (`LSV07S_Datum::jetzt()`) | `current_time('mysql')` zum Schreiben, `gmdate()`/`time()` zum Rechnen |

Von der Adresse des Besuchers steht nur ein HMAC in der Tabelle, nie die
Adresse selbst.

## Trainingspläne

Ein Plan hat einen Titel und eine geordnete Liste von Sessions (Anzahl,
Strecke, Beschreibung, Ausrüstung, Kommentar). Dazu kann er PDFs tragen:
Ein eingescannter Plan ist ein Plan und braucht keine Sessions.

**Ein Schalter, nicht zwei.** Lesen und Schreiben lassen sich nicht
trennen — ein Plan ist das Arbeitsmaterial des Trainers selbst. Fremde
Pläne ändert niemand; das hängt am Eigentum, nicht am Schalter.

| | SwimSys | interner Bereich |
|---|---|---|
| Speichern | das Protokoll unterscheidet Anlegen und Ändern | dort steht immer `trainingsplan.update`, auch beim Anlegen: Die Nummer wird vor dem Protokoll gesetzt, und damit ist die Bedingung immer wahr |
| Sessions | eine mitgekommene Session behält ihre Nummer | alle werden gelöscht und neu geschrieben |
| Freigabe zurückziehen | steht im Protokoll | steht nicht darin |
| Tabellen | gehören dem Schema an **einer** Stelle | jeder Aufruf legt sie an, falls sie fehlen |
| PDF | wird am Inhalt geprüft, nicht an der Endung | ebenso (`finfo`) |
| Vorlagen | einzelne Sessions, eigene und freigegebene; eine Vorlage wird abgeschrieben, nicht verschoben | ebenso |

Die PDFs liegen im **selben** gesperrten Ordner wie im internen Bereich
(`uploads/lsv07i-private/trainingsplan/<plan>/`) — solange beide laufen,
geben beide dieselben Dateien aus, jedes mit seiner eigenen Prüfung: der
interne Bereich mit seinem Recht, SwimSys mit seinem Schalter.

## Den internen Bereich ablösen

SwimSys kann das Schema selbst anlegen, sobald der interne Bereich nicht
mehr aktiv ist — vorher **nicht**: Zwei Plugins, die dieselbe Tabelle
anlegen und nachbessern, bessern irgendwann gegeneinander nach, und wer
welche Spalte gesetzt hat, ist hinterher nicht zu ermitteln.

Die Schemadatei (`includes/class-schema.php`, 79 Tabellen) ist aus den
Quellen des internen Bereichs **erzeugt**, nicht abgeschrieben. Eine
Prüfung liest diese Quellen bei jedem Lauf und meldet, wenn dort eine
Tabelle dazukommt — sonst liefe die erzeugte Fassung irgendwann hinterher.

Der Weg, wenn es soweit ist:

1. SwimSys aufrufen und prüfen, dass jeder Bereich Daten zeigt.
2. Unter **Verwaltung → Funktionen** einschalten, was gelten soll — oder
   die alten Rechte einmalig übernehmen und danach durchsehen. Im
   WordPress-Menü unter **SwimSys** steht, für welche Konten noch keine
   Funktion eingeschaltet ist; diese Konten sehen nach dem Umschalten
   nichts.
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
Akte, Wettkämpfe mit Freigabe, Dokumenten und Anwesenheit, Meldungen,
Bestzeiten, Meine Stunden, Nachrichten, Anfragen, Reflexionsbögen samt
der Seite zum Ausfüllen, Trainingspläne mit Vorlagen und PDFs, die
Schalterseite (Funktionen global und je Konto), Suche, Protokoll,
Schemaübernahme.

Noch nicht gebaut:

| fehlt | steht weiter im internen Bereich |
|---|---|
| Verwaltung: Konfiguration, Mails, Personen, Import | ja |
| Erinnerungen per Cron | ja |
| Auskunft und Löschung nach Art. 15/17 DSGVO | ja |

Die **Rechteverwaltung** fehlt nicht: Sie ist in SwimSys durch die
Schalterseite ersetzt. Der interne Bereich behält seine eigene, solange
er läuft.

Der interne Bereich hat 267 angemeldete Endpunkte, SwimSys hat 120. Beide
Plugins arbeiten auf denselben Daten, es geht nichts verloren — und
SwimSys sagt an jeder fehlenden Stelle offen, dass der Teil noch fehlt,
statt leer zu bleiben.

## Funktionen schalten

**Es gibt keine Rechte mehr.** Jede Ansicht ist eine Funktion, und jede
Funktion hat einen Schalter. Der Schlüssel einer Funktion ist dieselbe
Zeichenkette wie ihre Adresse: `schwimmen/anwesenheit` steht in der
Adresszeile und im Schalter.

Die Entscheidung läuft in dieser Reihenfolge, einmal, in
`LSV07S_Schalter::an()`:

1. angemeldet (über das WordPress-Konto),
2. nicht gesperrt (`blocked_user_ids` — schlägt alles, auch die
   Administration),
3. die Funktion steht im Katalog (ein Tippfehler ist **aus**, nicht an),
4. sie ist global an,
5. WordPress-Administrator? dann an,
6. sonst: der Schalter dieses Kontos.

Schritt 5 ist das Ventil gegen das Aussperren: Die Verwaltung muss die
Schalterseite erreichen können. Schritt 2 steht davor, damit ein
gesperrter Administrator gesperrt bleibt.

### Zwei Tabellen, zwei Standardwerte

| Tabelle | was darin steht | was fehlende Zeilen bedeuten |
|---|---|---|
| `lsv07s_schalter` | **global**, nur die ausgeschalteten | eine neue Funktion ist für alle **an** |
| `lsv07s_konto_schalter` | **je Konto**, nur die eingeschalteten | ein neues Konto sieht **nichts** |

So herum, weil beide Fehler in die sichere Richtung fallen: Eine Funktion,
die dazukommt, verschwindet nicht unbemerkt für alle — und ein Konto, das
dazukommt, sieht nicht unbemerkt alles. Diese zwei Tabellen sind die
**einzigen**, die SwimSys selbst anlegt; Vereinsdaten stehen weiter in den
Tabellen des internen Bereichs.

### Der Katalog

Pro eingerichteter Sparte (Schwimmen, Triathlon, Fitness):

| Funktion | was sie öffnet |
|---|---|
| `start/aufgaben` | Die Startseite mit dem, was offen ist. |
| `<sparte>/mannschaft` (bzw. `/gruppe`) | Liste, Kader und Stammdaten |
| `<sparte>/anwesenheit` | Der Tag einer Mannschaft: Anwesenheit, Absage, Buch, Bahnen, Springer |
| `<sparte>/trainer` | Trainerliste mit Fristen |
| `<sparte>/eigene` | Meine Daten |
| `<sparte>/alle` | **Reichweite**: ohne diesen Schalter nur die eigenen Mannschaften |

Nur im Schwimmen: `schwimmen/atteste` (Gesundheitsdaten),
`schwimmen/akte`, `schwimmen/wettkampf`, `schwimmen/meldung`,
`schwimmen/bestzeit`, `schwimmen/springer`, `schwimmen/trainingsplan`,
`schwimmen/reflexion`.

Dazu: `abrechnung/stunden` (meine Stunden), `abrechnung/uebersicht`
(Stunden aller Trainer), `reden/nachrichten`, `reden/anfragen`,
`reden/anfragen_alle` (**Reichweite**), `verwaltung/einstellungen`,
`verwaltung/protokoll`. Die Schalterseite selbst
(`verwaltung/schalter`) steht nur Administratoren offen und lässt sich
nicht abschalten.

### Was das kostet — offen gesagt

**Lesen und Schreiben sind nicht mehr zu trennen.** Wer Wettkämpfe anhat,
darf sie auch anlegen, ändern, freigeben und löschen. Das war die
ausdrückliche Entscheidung „ein Schalter je Ansicht": 20 Häkchen sind zu
verstehen und zu setzen, 60 nicht. Wer feiner trennen will, braucht
wieder Rechte.

Drei Grenzen bleiben trotzdem bestehen, weil sie keine Schalterfragen
sind:

* **Die Mannschaft.** Ohne `<sparte>/alle` arbeitet jemand nur in den
  Mannschaften, für die er als Trainer eingetragen ist.
* **Das Eigentum.** Eine fremde Nachricht, einen fremden Trainingsplan
  und eine fremde Abrechnung ändert niemand, egal welcher Schalter an
  ist.
* **Was keiner Mannschaft gehört.** Eine Mannschaft anlegen oder löschen,
  eine Person löschen, einen Trainer zuordnen oder entfernen — das
  verlangt die Reichweite `<sparte>/alle`. Sonst legt ein Trainer mit
  einer Mannschaft Mannschaften an, für die er nicht zuständig ist.

### Die Übernahme aus den alten Rechten

Ein Knopf auf der Schalterseite liest `lsv07i_user_rechte` einmalig und
schaltet daraus je Konto die passenden Funktionen an. Er **nimmt nichts
weg**: Was von Hand gesetzt wurde, bleibt. Danach ist durchzusehen — die
alten Rechte waren gröber als die Schalter, und ein Leserecht wird dabei
zu einer Ansicht, in der man auch schreiben darf. Jede Übernahme steht im
Protokoll.

**Kein Rückfall auf alte WordPress-Rollen.** Der interne Bereich behandelt
Konten ohne vergebene Rechte nach ihrer alten Rolle. SwimSys rät nicht:
Wofür nichts eingeschaltet ist, dem wird gesagt, dass nichts
eingeschaltet ist. Welche Konten das betrifft, steht im WordPress-Menü
unter SwimSys.

**Ein Wortlaut für die Absage.** „Diese Funktion ist für Sie nicht
eingeschaltet." — immer derselbe Satz, ob die Funktion global aus ist
oder nur für dieses Konto. Welcher von beiden es ist, sagt die Oberfläche
nicht; wer es wissen muss, sieht es auf der Schalterseite. Der interne
Bereich hat für „keine Berechtigung" 16 verschiedene Wortlaute.

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

* **1279 Prüfungen** gegen das **echte Schema** des internen Bereichs: Die
  `CREATE TABLE`-Anweisungen **und die 58 Spalten, die er später per
  `ALTER TABLE` nachzieht**, werden aus seinen Quelldateien gelesen, nicht
  abgeschrieben. SwimSys arbeitet auf fremden Tabellen — eine Prüfung auf
  einem abgeschriebenen Schema würde genau das nicht prüfen.
  (191 `systest.php`, 95 `sysstammtest.php`, 35 `syserbetest.php`,
  78 `sysbuchtest.php`, 106 `sysattesttest.php`, 196 `syswktest.php`,
  67 `sysstdtest.php`, 143 `sysredentest.php`, 94 `sysrefltest.php`,
  78 `sysreflpubtest.php`, 75 `systptest.php`, 121 `sysschaltertest.php`)
* **498 Oberflächenprüfungen** (`sysui.js`) in einem echten Browser: die
  Adresse je Ansicht, Zurück und Vorwärts, Navigation, Dialog, die sechs
  Zustände, Suche, Sortieren, die Schalterseite, dazu Lesbarkeit
  (Kontrast über 4,5:1), Überlauf und Trefferhöhen auf 1280 px und
  360 px.
* **33 Prüfungen der öffentlichen Reflexionsseite** (`sysreflpubui.js`):
  dass dort kein Name und keine Frage steht, bevor jemand angemeldet ist,
  dass der Fehler **beide** Faktoren in einem Satz nennt, und dass im
  Bogen keine Chiffre steht.
* **Jede Zusicherung gegengeprüft**: absichtlich gebrochen, um
  nachzusehen, ob eine Prüfung es meldet — 240 Fälle gegen den Code
  (`sysgegenprobe.py`, gegen alle zwölf Prüfdateien) und 92 gegen die
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

Bei Wettkämpfen, Meldungen und Bestzeiten kamen fünf dazu:

* **Die Startliste zeigte die falsche Strecke und die falsche Person.**
  Die Auswahlfelder bekommen ihren Wert erst, wenn sie im Baum stehen —
  davor gibt es die Einträge noch nicht, und der Browser nimmt den
  ersten. Beim ersten Öffnen einer Meldung wurde das Setzen gar nicht
  aufgerufen: Jede Zeile zeigte „50m Freistil" und den ersten Schwimmer,
  und ein Speichern hätte genau das geschrieben. Das Setzen steht jetzt
  am Ende des Zeichnens, an einer Stelle.
* **Vier Zusicherungen waren nicht geprüft**, obwohl eine Prüfung danebenstand:
  die von Hand entfernte Abrechnungszeile, das Protokoll beim Anlegen und
  Ändern eines Wettkampfs, das Protokoll beim Import von Bestzeiten — und
  „melden kann nur eine beteiligte Mannschaft", wo die Mannschaftsgrenze
  den Fall schon vorher abfing. Dafür braucht es ein Konto, das alle
  Mannschaften sehen darf.
* **Ein Rückschritt im Prüfstand selbst:** In einer gewöhnlichen
  Zeichenkette ist `\b` ein Steuerzeichen. Ein Eingriff der Gegenprobe,
  dessen Suchtext ein `\b` enthielt, fand seine Stelle nie — und die
  Gegenprobe meldete „Eingriff nicht möglich" statt „niemand merkt es".
  Der Fall sucht jetzt eine Zeile ohne Backslash.

Bei Stunden, Nachrichten und Anfragen kamen vier dazu, zwei davon ernst:

* **Eine Tabelle fehlte im Schema, und die Prüfung konnte es nicht
  merken.** In der Liste der Quelldateien stand
  `includes/class-tp-vorlage.php`; die Datei heisst
  `includes/class-ajax-tp-vorlage.php`. Übersprungen wurde sie
  stillschweigend (`is_file`), und damit fehlte
  `lsv07i_trainingsplan_vorlage` in der erzeugten Schemadatei — am Tag
  ohne internen Bereich hätte SwimSys die Tabelle nicht angelegt.
  Auffallen konnte es nicht: Der Prüfstand las dieselbe lückenhafte
  Liste. Jetzt bricht eine fehlende Datei ab, und eine Prüfung verlangt,
  dass **jede** Quelldatei mit `CREATE TABLE` in der Liste steht.
* **Zwei Ansichten teilten eine Klasse am Knopf.** `.s-m-weg` war in der
  Meldung „Zeile entfernen" und im Chat „Nachricht löschen". Da die
  Behandlung am Dokument hängt, fing der Chat-Handler die Klicks der
  Meldung mit: Ein Klick auf „×" in der Startliste öffnete den Dialog
  „Nachricht löschen". Gefunden hat es der Zufall, dass die Meldung
  **nach** dem Chat geprüft wird. Die Klassen des Chats heissen jetzt
  `s-k-m…`, und eine Prüfung verlangt, dass keine Klasse an zwei
  Klick-Behandlungen hängt.
* **Fünf Zusicherungen bei Nachrichten waren nicht geprüft**, obwohl eine
  Prüfung danebenstand: Verteiler an eine Mannschaft, Senden ohne
  Teilnahme, Lesestand durch eigene Nachricht, „der Lesestand geht nur
  vorwärts" und das Recht für Anhänge. Jedes Mal fing eine **andere**
  Hürde den Fall vorher ab — ein Konto ohne Senderecht, eine fremde
  Mannschaft, eine fremde Nachricht, oder ein „älterer" Stand, der in
  Wahrheit derselbe war (die erste Nachricht hat die Nummer 1). Jetzt
  steht für jede ein Konto bereit, dem genau das eine fehlt.
* **Ein Häkchen ist 16 px hoch** und fiel durch die Prüfung auf
  Trefferhöhe. Gemessen wird jetzt die Beschriftung, in der es steht —
  denn die schaltet es mit; und eine zweite Prüfung verlangt, dass jedes
  Häkchen wirklich in seiner Beschriftung steht.

Bei der Seite zum Ausfüllen und den Trainingsplänen kamen zwei dazu,
beide im eigenen Code:

* **Die Sperre nach Fehlversuchen griff nie, sobald die Uhren
  auseinanderliefen.** Geschrieben wurde `LSV07S_Datum::jetzt()`,
  gerechnet mit `time()`. Steht die Uhr des Plugins woanders — im
  Prüfstand steht sie fest —, dann war der letzte Versuch immer „länger
  als eine Viertelstunde her", der Zähler sprang bei jedem Versuch auf 0,
  und zehn Fehlversuche kamen nie zusammen. Jetzt rechnet und schreibt
  eine Uhr. (Im internen Bereich stehen beide ebenfalls nebeneinander:
  `current_time('mysql')` zum Schreiben, `gmdate()`/`time()` zum
  Rechnen.)
* **Ein Datum, das es nicht gibt, war ein Datum.** `mv_swimmers.birth_date`
  ist `NOT NULL`; eine Zeile ohne Geburtsdatum steht dort als
  `0000-00-00`. Daraus machte die Normalisierung `00000000` — und wer
  `00.00.0000` eintippte, kam in den Bogen eines Schwimmers, für den kein
  Datum hinterlegt ist. Der zweite Faktor war dort keiner. Jetzt prüft
  `checkdate()` beide Seiten, und ein unmögliches Datum ist leer.
* **Der Prüfstand prüfte einen Wert, den es nie gibt.** WordPress legt in
  `$_POST` Backslashes vor Anführungszeichen und Backslashes; der Code
  nimmt sie mit `wp_unslash` wieder weg. Der Prüfstand rief die Endpunkte
  ohne dieses Vorspiel — und damit frass `wp_unslash` in einer
  JSON-Zeichenkette das `\n` einer Zeile. Dass die Startliste der
  Meldung und die Sessions eines Plans mehrzeilige Felder heil
  durchbringen, liess sich so gar nicht prüfen. `ruf()` legt die
  Schrägstriche jetzt an, wie WordPress es tut.

Bei den Reflexionsbögen kam einer dazu, und er wäre teuer geworden:

* **Eine Spalte war enger, als der interne Bereich sie hält.** Dort wird
  `lsv07i_refl_bogen.status` nachträglich **geweitet** (`ALTER TABLE …
  MODIFY`), von `ENUM('offen','abgegeben')` auf eine Fassung mit
  `archiviert`. Gelesen wurden bisher nur `CREATE TABLE` und `ADD
  COLUMN` — die erzeugte Schemadatei legte die Spalte also eng an, und am
  Tag ohne internen Bereich hätte MySQL jedes Archivieren stillschweigend
  verworfen. Die sechs Typänderungen werden jetzt mitgelesen
  (`modify_laden()`), stehen in der Definition **und** in `weiten()` für
  eine Tabelle, die eine ältere Fassung eng angelegt hat. Eine Prüfung
  verlangt beides.

Beim Umbau auf die Schalter kamen drei dazu, einer davon ernst:

* **Drei Endpunkte waren für niemanden offen.** Saison speichern, aktiv
  setzen und löschen verlangten nach dem Umbau noch das alte Rechtewort
  `admin.saison.manage`. Weil eine Funktion, die nicht im Katalog steht,
  **aus** ist — und das ist die richtige Richtung —, waren diese drei für
  jedes Konto geschlossen, auch für die Administration, die sonst alles
  darf. Keine der 1200 Prüfungen sah hin: Sie prüften die Funktionen
  dahinter, nicht die Karte davor. Jetzt läuft eine Prüfung einmal über
  die ganze Endpunktkarte und verlangt für jeden Eintrag eine Funktion aus
  dem Katalog, und keine mit einem Punkt im Namen.
* **Vier fast gleiche Datei-Uploads.** Attest, Wettkampfdokument,
  Nachrichtenanhang und Trainingsplan-PDF bauten jeder sein eigenes
  `FormData` — und das „fast" war der Fehler: Dreimal hiess ein
  Serverausfall „Keine Verbindung zum Server", beim Trainingsplan
  „Fehlgeschlagen". Jetzt eine Fassung (`hochladen()`), dieselben Sätze
  wie überall.
* **Zwei Gegenproben konnten nie zuschlagen.** Ihr Suchtext enthielt
  `\n`; in einer gewöhnlichen Zeichenkette ist das ein Zeilenumbruch, und
  die Stelle wurde nie gefunden. Eine dritte fand ihre Stelle dreimal —
  eben die drei gleichen Uploads. Alle drei meldeten „Eingriff nicht
  möglich" statt „niemand merkt es", und die Zusicherung lief als
  ungeprüft mit.
* **Vier Oberflächenprüfungen prüften nichts.** Die Gegenprobe hat sie
  einzeln aufgedeckt, und jede war anders hohl:
  * „ein gesperrtes Konto ist gekennzeichnet" suchte das Wort „Gesperrt"
    im Seitentext — und das Prüfkonto **hiess** „Gerd Gesperrt". Die
    Zusicherung blieb grün, als die rote Marke ganz verschwand. Geprüft
    wird jetzt das Element in der Zeile dieses Kontos, Klasse und
    Beschriftung.
  * „der Hinweis der Vorschau steht oben" prüfte nur die Tabellenzeile
    darunter. Jetzt: der Warnkasten, sein Wortlaut und seine Stellung
    **vor** der Tabelle — ein Hinweis unter dreissig Zeilen ist keiner.
  * „der Umbruch in einer fremden Beschreibung bleibt" konnte nichts
    prüfen, weil die Beschreibung im Prüfstand gar keinen Umbruch hatte.
  * „eine Vorlage wird zur Session" zählte nur die neue Zeile. Jetzt
    auch, dass sie **keine** Nummer trägt: Mit der Nummer der Vorlage
    schriebe das Speichern die Vorlage um.

**Lesen und Schreiben sind nicht mehr getrennt.** Das ist keine Lücke, die
eine Prüfung findet, sondern die Entscheidung „ein Schalter je Ansicht".
Rund dreissig Prüfungen der Art „wer nur lesen darf, schreibt nichts"
prüften danach nichts mehr und sind umgeschrieben: Sie schalten die
Ansicht jetzt ab und verlangen, dass dann auch das Lesen aufhört.
