# LSV07 Abrechnung

Quartalsabrechnung für Trainerinnen und Trainer. Eigenständiges
WordPress-Plugin, das **neben** dem internen Bereich läuft und dessen Daten
liest.

## Einrichten

1. Ordner `lsv07-abrechnung` nach `wp-content/plugins/` legen und das Plugin
   in WordPress aktivieren. Die Tabellen entstehen dabei von selbst.
2. Eine WordPress-Seite anlegen und dort nur den Shortcode einsetzen:

   ```
   [lsv07_abrechnung]
   ```

   Die Seite zeigt dann weder Theme-Kopf noch -Fuß und füllt das ganze
   Fenster — wie eine eigene Anwendung.
3. Als WordPress-Administrator die Seite öffnen (ein WordPress-Admin gilt
   hier automatisch als Administrator) und unter **Verwaltung → Konten**
   die Personen aufnehmen.

## Rollen

Die Konten sind WordPress-Konten, **die Rollen aber nicht**: Sie werden in
diesem Plugin vergeben und haben mit WordPress-Rollen nichts zu tun. Eine
Person kann mehrere haben.

| Rolle | darf |
|---|---|
| **Trainer** | eigene Abrechnung sehen und bearbeiten, Zahlungsdaten hinterlegen, einreichen, Nachtrag anlegen, eigenen Beleg als PDF, auf Rückfragen antworten, eigene Statistik |
| **Wart** | Abrechnungen **seines Bereichs** im Detail sehen — auch nicht eingereichte —, einzelne Posten beanstanden, Rückfragen stellen, genehmigen, zurückgeben, Statistik aller Trainer |
| **Abteilungsleitung** | Rechnungen **ihrer Abteilung** sehen, sobald sie eingereicht sind, genehmigen und zurückgeben |
| **Kasse** | **nur genehmigte und bezahlte** Abrechnungen und Rechnungen sehen, als PDF ausgeben, per QR-Code überweisen, als bezahlt markieren, Archiv, Statistik |
| **Administrator** | alles, dazu Konten, Rollen, Abteilungen, Sätze, Pauschalen, Saisons und Trainingszeiten |

Ein WordPress-Administrator ist immer auch hier Administrator — sonst käme
nach der Installation niemand an die erste Rolle.

**Zwei Genehmigungseinheiten, und das ist Absicht.** Die
Quartalsabrechnung genehmigt wie bisher der **Wart**, zuständig über die
**Mannschaften** einer Abrechnung. Die *Andere Abrechnung* genehmigt die
**Abteilungsleitung**, zuständig über die **Abteilung einer Person**. Der
Grund ist einfach: Eine Rechnung über neue Startblöcke hat keine
Mannschaft, an der eine Zuständigkeit hängen könnte — eine Abteilung hat
sie trotzdem. Beide Wege laufen nebeneinander; wer bisher mit Warten
arbeitet, muss daran nichts ändern.

## Der Weg einer Abrechnung

```
Entwurf ──einreichen──> Eingereicht ──genehmigen──> Genehmigt ──bezahlt──> Bezahlt
   ^                         │                          │
   └────zurückgeben──────────┘                          │
   └──────── nur Administration: wieder öffnen ─────────┘
```

- Solange eine Abrechnung **offen** ist (Entwurf oder zurückgegeben),
  folgen ihre Beträge den aktuellen Sätzen. Ändert die Administration den
  Stundensatz, rechnet sie sich neu.
- **Ab dem Einreichen steht alles still.** Geprüft und genehmigt wird genau
  das, was eingereicht wurde — eine spätere Satzänderung fasst es nicht an.
- Solange niemand entschieden hat, kann die Trainerin oder der Trainer die
  Abrechnung selbst zurückholen.
- Ohne Kontoinhaber und IBAN lässt sich nicht einreichen.

## Die fünf Bestandteile

| | Erfasst wird | Gerechnet wird |
|---|---|---|
| **Training** | aus dem internen Bereich übernommen oder von Hand | je nach Abrechnungsart des Kontos (siehe unten) |
| **Wettkampf** | Wettkampf und Zahl der Abschnitte | Abschnitte × Betrag je Abschnitt (Vorgabe 30 €) |
| **Fahrtkosten** | einfache Strecke in km, Zahl der Tage | erst über der Mindeststrecke (Vorgabe mehr als 20 km), dann km × 2 (hin und zurück) × Tage × Satz (Vorgabe 0,50 €) |
| **Vorbereitung** | Stunden und Grund | Stunden × Stundensatz |
| **Sonstiges** | Betrag und Grund | der Betrag |

## Die drei Wege, ein Training abzurechnen

Die Abrechnungsart wird **je Konto einzeln** festgelegt
(Verwaltung → Konten):

- **Trainingszeiten** — Die Stunden kommen aus der hinterlegten
  Trainingszeit der Saison, in der das Training lag, mal Stundensatz.
  Welche Zeit das ist, steht unten unter *Woher die Stunden kommen*.
- **Pauschalbeträge** — Ein fester Betrag je Training, abhängig von der
  Mannschaft (Verwaltung → Pauschalen). Die Stunden spielen keine Rolle.
- **Manuelle Stundeneingabe** — Die Person trägt die Stunden selbst ein,
  mal Stundensatz. Für Trainings, die länger gingen als geplant.

## Saisons und Trainingszeiten

Saisons und Trainingszeiten **teilen sich beide Plugins** — es gibt sie nur
einmal. Was hier gepflegt wird, steht auch im internen Bereich und
umgekehrt. Das ist Absicht: Zwei Wahrheiten darüber, wann trainiert wird,
würden früher oder später zu Abrechnungen führen, die nicht zum
Trainingsplan passen.

**Das Enddatum einer Saison schützt die Historie.** Werden Trainingszeiten
geändert, gehören sie in eine **neue** Saison; sonst ändern sich alte
Abrechnungen rückwirkend. Der richtige Ablauf:

1. Der laufenden Saison ein Enddatum geben.
2. Eine neue Saison ab dem Folgetag anlegen.
3. Die Trainingszeiten dort neu hinterlegen.

Überschneidende Zeiträume werden abgelehnt, denn sonst wäre nicht
entscheidbar, welche Zeit für ein Training gilt.

## Trainings in die Abrechnung holen

Zwei Wege, jede Person stellt für sich ein, welcher gilt
(**Meine Einstellungen → Trainings übernehmen**):

- **Auswählen** (Vorgabe) — über *Aus dem Training übernehmen* die
  gewünschten anhaken. Die Wartezeit lässt sich dabei gleich mitsetzen.
- **Von selbst** — beim Öffnen der Abrechnung stehen alle Trainings des
  Quartals schon da.

Bei der automatischen Übernahme gilt:

- Die **Wartezeit bleibt aus**. Ob jemand gewartet hat, kann das System
  nicht wissen; sie wird an der jeweiligen Zeile angehakt.
- Trainings **ohne hinterlegte Trainingszeit** kommen nicht von selbst —
  sie wären 0 € wert. Die Oberfläche sagt, wie viele es waren, damit sie
  nicht stillschweigend fehlen.
- **Entferntes kommt nicht zurück.** Wer ein übernommenes Training
  löscht, hat einen Grund; es wird vermerkt und beim nächsten Öffnen
  nicht erneut geholt. Über *Aus dem Training übernehmen* lässt es sich
  bewusst wieder holen.
- Ab dem Einreichen wird nichts mehr hinzugefügt.

## Wartezeit

Bei jedem Training lässt sich eine Wartezeit zuschalten (Vorgabe 15
Minuten, einstellbar unter Verwaltung → Sätze). Sie wird mit dem
Stundensatz vergütet und kommt in allen drei Abrechnungsarten obendrauf.

Anhaken lässt sie sich an **zwei Stellen**: beim Übernehmen und
nachträglich **direkt an der Zeile in der Abrechnung** — man weiss oft
erst hinterher, ob man gewartet hat. Der Betrag wird dabei auf dem Server
neu gerechnet, nie im Browser; mehrfaches Umschalten führt immer auf
denselben Betrag zurück.

## Woher die Stunden kommen

Maßgeblich ist die Trainingszeit, die **an diesem Tag galt** — nicht die
Slot-Zuordnung der Anwesenheit. Der interne Bereich sucht den Slot beim
Anlegen einer Anwesenheit über Mannschaft und Wochentag **ohne die Saison
zu beachten**; nach einem Saisonwechsel gibt es dieselbe Mannschaft am
selben Wochentag aber zweimal. Findet er nichts, nimmt er irgendeinen Slot
der Mannschaft — auch von einem anderen Wochentag — und sonst legt er
einen Platzhalter 00:00–00:00 an. Die Abrechnung verlässt sich deshalb
nicht darauf, sondern geht der Reihe nach vor:

1. **Aus dem Plan** — der Slot der Mannschaft in der Saison des Datums, am
   Wochentag des Datums. Das ist der Normalfall.
2. **Aus der Anwesenheit** — gibt es an dem Wochentag keine Zeit, gilt der
   Slot, an dem die Anwesenheit hängt. So bleiben verlegte Trainings
   richtig.
3. **Ohne Saisonbezug** — ein Slot am passenden Wochentag, für Anlagen,
   in denen keine Saisons gepflegt sind.
4. **Gar keine** — dann werden keine Stunden erfunden. Der Eintrag ist
   in der Übernahme nicht vorausgewählt und sagt „keine Trainingszeit
   hinterlegt"; die Stunden werden von Hand eingetragen.

Stammen die Stunden nicht aus dem Plan, steht das beim Training in der
Übernahme-Liste. Gibt es am selben Tag zwei Zeiten (zwei Gruppen), gilt
die der Anwesenheit und der Eintrag wird als mehrdeutig markiert.

**Abgesagte Trainings** werden nicht angeboten. Maßgeblich ist die
Ausfall-Liste des internen Bereichs; das Feld `ausgefallen` an der
Anwesenheit allein reicht nicht, weil es nur mitgezogen wird, wenn es die
Anwesenheitszeile zum Zeitpunkt der Absage schon gab — und eine
Springer-Schicht hat oft gar keine.

Einmal übernommen, steht ein Posten still: Stunden, Satz und Uhrzeit sind
am Posten gespeichert. Eine spätere Änderung der Trainingszeit ändert ihn
nicht mehr.

## Was aus dem internen Bereich gelesen wird

Ausschließlich lesend, niemals verändernd (außer Saisons und
Trainingszeiten, die bewusst gemeinsam gepflegt werden):

| Tabelle | wofür |
|---|---|
| `lsv07i_trainer` | welches WordPress-Konto zu welchem Trainer-Profil gehört |
| `lsv07i_anwesenheit`, `…_eintraege` | bei welchen Trainings jemand anwesend war |
| `lsv07i_springer` | Springer-Schichten |
| `lsv07i_training_slots` | Trainingszeiten → Stunden |
| `lsv07i_training_ausfall` | abgesagte Trainings |
| `lsv07i_saisons` | welche Zeit für welchen Zeitraum gilt |
| `lsv07_gruppen` | Mannschaften |
| `lsv07i_wettkampf`, `…_tage` | Wettkämpfe und ihre Tage |
| `lsv07i_wettkampf_anwesenheit`, `…_anw_eintraege` | Vorschlag für die Abschnitte |

Fehlt der interne Bereich oder eine Tabelle, bleibt die Abrechnung
benutzbar: Es lässt sich dann nichts übernehmen, aber alles von Hand
erfassen. Die Oberfläche sagt, was fehlt.

**Ohne Trainer-Profil im internen Bereich** lassen sich keine Trainings
übernehmen. Unter Verwaltung → Konten steht bei jedem Konto, ob eine
Verknüpfung besteht.

## PDF

Die Kasse erzeugt den Beleg über **PDF** → ein eigenes Fenster mit dem
fertigen Beleg öffnet sich und der Druckdialog erscheint; dort „Als PDF
sichern" wählen. So kommt keine zusätzliche Programmbibliothek ins Spiel
und der Beleg sieht überall gleich aus.

Für den Mailanhang (siehe *Die bezahlte Abrechnung geht an die Person*)
wird dasselbe Dokument auf dem Server als PDF geschrieben — ebenfalls
ohne fremde Programmbibliothek. Benutzt werden die beiden
Standardschriften, die jedes Anzeigeprogramm mitbringt; es wird keine
Schriftdatei eingebettet und nichts nachgeladen. Lange Abrechnungen
laufen auf weitere Seiten, mit wiederholter Tabellenüberschrift.

## Sicherheit

Grundsatz: Es verlässt nichts den Server, und jeder bekommt nur, was er
für seine Aufgabe braucht.

- **Nichts geht nach draußen.** Kein Abruf fremder Server, keine
  Schrift und kein Skript von einem anderen Ort, keine
  Programmbibliothek, die irgendwo nachlädt. Auch das PDF entsteht auf
  dem eigenen Server. Geprüft wird das maschinell bei jedem Testlauf.
- **Mitteilungen liegen zuerst im System** — in der Glocke, nicht im
  Postfach. E-Mail kommt nur dazu, wenn jemand sie einschaltet; ab Werk
  ist der Versand aus, und verschickt wird ausschließlich über den
  Mailversand von WordPress. Die Mailtexte nennen keine Beträge. Die
  einzige Ausnahme ist die bezahlte Abrechnung, die an die Person selbst
  geht — abschaltbar, und mit verkürzter Bankverbindung.
- **Jeder Endpunkt hat ein Tor.** Anmeldung, Einmal-Schlüssel gegen
  fremde Formulare, dann die Rolle. Die Oberfläche steuert nur, was
  sichtbar ist — entschieden wird immer im Endpunkt.
- **Zahlungsdaten nur, wo sie gebraucht werden.** IBAN, BIC und Anschrift
  sehen die Person selbst, die Kasse (sie überweist) und die
  Administration. **Der Wart nicht** — er prüft Stunden und Beträge. Er
  sieht nur, *dass* die Daten vollständig sind, denn ohne sie lässt sich
  nicht auszahlen.
- **Die Kasse sieht Nichtgenehmigtes gar nicht**, nicht einmal, dass es
  existiert.
- **Fremde Abrechnungen bleiben fremd.** Jede Kennung aus dem Browser
  wird gegen das eigene Konto geprüft, nie geglaubt.
- Beträge werden nie aus dem Browser übernommen, sondern immer neu
  gerechnet.

## Rollenansicht

Die Administration kann unter **Verwaltung → Rollenansicht** sehen, wie
die Abrechnung für Trainer, Wart oder Kasse aussieht — welche Bereiche
es gibt und welche Knöpfe darin stehen.

Drei Dinge machen das ungefährlich:

1. **Sie schränkt ein, sie erweitert nie.** Es werden Rechte entfernt.
   Wer sie einschaltet, ist ohnehin Administrator — sie ist also kein Weg
   zu mehr Rechten, sondern zu weniger.
2. **Nur sehen.** Solange sie läuft, sind alle Änderungen gesperrt, auch
   an der eigenen Abrechnung. Sonst stünde im Protokoll der falsche Name.
3. **Sichtbar und befristet.** Ein Band steht über der Seite, und nach
   zwei Stunden endet sie von selbst.

Es wird **nicht in ein fremdes Konto geschlüpft**: Die Person bleibt sie
selbst, es gilt nur eine andere Rolle. Fremde Abrechnungen sind deshalb
genauso geschützt wie sonst.

## Benachrichtigungen

Die Glocke oben rechts zeigt, was es Neues gibt. Anlässe:

| Anlass | wer erfährt es |
|---|---|
| Abrechnung eingereicht | alle Warte |
| genehmigt | die Trainerin bzw. der Trainer und die Kasse |
| zurückgegeben (mit Grund) | die Trainerin bzw. der Trainer |
| als bezahlt vermerkt | die Trainerin bzw. der Trainer |
| wieder geöffnet | die Trainerin bzw. der Trainer |
| Quartal ist abrechenbar | alle Trainer |

Eine Mitteilung sagt immer nur, **dass** etwas geschehen ist und wo es
steht — nie, um wie viel Geld es geht. Wer den Betrag sehen darf, sieht
ihn beim Öffnen; dort greift die Rechteprüfung. Ein Klick führt direkt
zum Vorgang. Wer selbst gehandelt hat, bekommt keine Mitteilung darüber.

## Benachrichtigungen per E-Mail

Zusätzlich zur Glocke lassen sich Mitteilungen per E-Mail verschicken
(**Verwaltung → E-Mail**). Verschickt wird über den Mailversand von
WordPress — das Plugin baut keine eigene Verbindung nach draußen auf.
Kommt nichts an, liegt es am Mailversand der Seite, nicht an der
Abrechnung.

- **Ab Werk ist der Versand aus.** Wohin Post geht, soll jemand bewusst
  einschalten und nicht nach einem Update vorfinden.
- **Jede Art einzeln.** Wer nur über Genehmigungen Post will, stellt den
  Rest ab. Im System erscheint die Mitteilung trotzdem.
- **Eigene Texte** je Art, mit Platzhaltern `{name}`, `{zeitraum}`,
  `{grund}`, `{link}` und `{verein}`. Der Vorgabetext lässt sich
  jederzeit wiederherstellen.
- **Probemail** — auch bei abgeschaltetem Versand, genau dafür ist sie
  da: erst prüfen, dann einschalten.

**Adressen:** Jedes Konto kann eine eigene hinterlegen (Verwaltung →
Konten); ohne sie geht Post an die Adresse des WordPress-Kontos. Für
Warte und Kasse lassen sich zusätzliche Sammeladressen eintragen, etwa
`kasse@verein.de` — sie bekommen zusätzlich zu den Konten mit der Rolle
Post. Jede Mail geht einzeln hinaus; niemand sieht, wer sonst noch
Empfänger ist.

Die Vorgabetexte nennen **keine Beträge und keine Bankverbindung**. Eine
E-Mail liegt im Postfach, oft auf fremden Servern, und lässt sich nicht
zurückholen; wer den Betrag sehen darf, sieht ihn beim Öffnen. Wer es
anders will, ändert die Texte — aber bewusst. Die eine gewollte Ausnahme
steht im nächsten Abschnitt.

## Die bezahlte Abrechnung geht an die Person

Wird eine Abrechnung als bezahlt vermerkt — durch die Kasse oder durch
die Administration über **Stand…** —, bekommt die Person ihre Abrechnung
per Mail: alle Posten, die Zwischensummen und den Gesamtbetrag, im
Mailtext **und** als PDF-Datei im Anhang. Ohne das müsste sie dafür
nachfragen.

Zwei Schalter unter **Verwaltung → E-Mail → Beleg zur bezahlten
Abrechnung**, beide ab Werk an:

- **Die Abrechnung in die Mail schreiben** — der Beleg steht dann unter
  dem Mitteilungstext. Die Mail geht als HTML hinaus und trägt dieselbe
  Fassung als reinen Text mit, für Mailprogramme ohne HTML-Anzeige.
- **Die Abrechnung als PDF-Datei anhängen** — Dateiname z. B.
  `Abrechnung-2026-Q1-Sabine-Mueller.pdf`. Die Datei entsteht erst beim
  Versand und wird danach sofort gelöscht; es bleibt kein Beleg auf dem
  Server liegen.

Ist die Art *Abrechnung bezahlt* abgeschaltet oder der Versand
insgesamt, geht gar nichts — auch kein Beleg.

Das gilt **nur** für diese eine Mitteilung und **nur** an die Adresse
der Person selbst. Jede andere Mail bleibt wie bisher ohne Beträge. Die
Bankverbindung steht auf dem Beleg nur mit Land und den letzten vier
Stellen (`DE** **** **** **** **20 51`) — genug, um die Zahlung dem
eigenen Konto zuzuordnen, zu wenig für alles andere.

Wie das Ergebnis aussieht, zeigt eine **Probemail der Art *Abrechnung
bezahlt***: Sie bringt einen Beispielbeleg mit erfundenen Zahlen mit,
Anhang inbegriffen, und geht auch bei abgeschaltetem Versand hinaus.

## Einzelne Posten beanstanden

Stimmt eine Zeile nicht, muss nicht mehr die ganze Abrechnung zurück.
Der Wart öffnet sie, klickt an der Zeile auf **Beanstanden** und schreibt
dazu, was nicht stimmt. Die Zeile bekommt eine Markierung, die übrigen
bleiben unberührt.

- Solange etwas beanstandet ist, **lässt sich die Abrechnung nicht
  genehmigen**. Entweder die Beanstandung wird aufgehoben, oder die
  Abrechnung geht zurück.
- Beim **Zurückgeben** muss der Wart den Grund nicht noch einmal als
  Fließtext tippen — er wird aus den beanstandeten Zeilen gebildet.
- Die Person sieht die Markierung und den Grund **an der Zeile**, nicht
  in einem Sammeltext, in dem sie suchen müsste.
- Mit dem **erneuten Einreichen** sind die Beanstandungen erledigt: Sie
  bezogen sich auf eine Fassung, die es nicht mehr gibt. Der Wart
  beanstandet dann neu oder genehmigt.

## Rückfrage am Posten

Manchmal genügt eine Frage, und eine Rückgabe wäre zu viel. Wart und
Person schreiben sich dann direkt an der Zeile — **am Stand der
Abrechnung ändert sich dabei nichts**. Es entsteht ein kleiner Faden aus
Frage und Antwort, mit Namen und Zeitpunkt.

Beide Seiten bekommen darüber eine Mitteilung, in der Glocke und, wenn
eingeschaltet, per E-Mail (Arten *Posten beanstandet* und *Rückfrage*).

## Zuständigkeit je Mannschaft

**Verwaltung → Zuständigkeit.** In einem kleinen Verein prüft ein Wart
alles; wird es größer, lässt sich festlegen, wer welche Mannschaften
prüft.

- **Kein Häkchen heißt: alle.** Ein bestehender Verein steht nach einem
  Update nicht plötzlich vor einer leeren Liste — eingeschränkt wird nur,
  wer bewusst eingeschränkt wurde.
- Zugeordnet wird über die **Posten**: Eine Abrechnung gehört in den
  Bereich eines Warts, wenn mindestens ein Trainingsposten darin zu einer
  seiner Mannschaften gehört. Wer zwei Mannschaften trainiert, taucht
  deshalb bei beiden Warten auf.
- Eine Abrechnung **ganz ohne Mannschaftsbezug** — nur Fahrten, nur
  Sonstiges — ist für alle Warte sichtbar. Sonst sähe sie keiner und sie
  bliebe für immer liegen.
- Wer **noch gar nichts erfasst** hat, erscheint bei den Warten, die ihn
  im zurückliegenden Jahr schon geprüft haben. Ohne Posten gibt es keine
  andere Spur, und ohne diese Zeile würde niemand merken, dass jemand
  nichts eingereicht hat.
- Gesperrt wird **im Endpunkt**, nicht in der Oberfläche: Detail,
  Genehmigen, Zurückgeben und Beanstanden weisen eine fremde Mannschaft
  ab, auch wenn jemand die Nummer von Hand einträgt.
- Die Administration sieht weiterhin alles.

## Auffälligkeiten

Der Wart bekommt über der Abrechnung ein Band mit dem, was auffällt.
**Blockiert wird nichts** — es gibt gute Gründe für zwei Trainings an
einem Tag und für ein teures Quartal. Entschieden wird vom Menschen.

Gesucht wird nach vier Mustern:

| Muster | Wann |
|---|---|
| **Doppelt erfasst** | Derselbe Tag, dieselbe Art, dieselbe Bezeichnung mehr als einmal |
| **Falscher Zeitraum** | Ein Datum, das nicht in das abgerechnete Quartal gehört |
| **Großer Einzelposten** | Eine Zeile macht über 40 % der Abrechnung aus und liegt über 100 € |
| **Mehr als sonst** | Das Quartal liegt über dem Anderthalbfachen des bisherigen Schnitts und mindestens 50 € darüber |

Jeder Hinweis nennt die Zahlen — „auffällig" allein hilft niemandem.
Für den Vergleich mit dem Schnitt braucht es **mindestens zwei
abgeschlossene Quartale** derselben Person; vorher wird geschwiegen statt
geraten. In der Prüfliste zeigt ein kleines Zeichen, wo ein zweiter Blick
lohnt.

## Nachtragsabrechnung

Ein vergessener Posten nach der Auszahlung lässt sich nicht nachtragen,
ohne eine bezahlte Abrechnung wieder aufzureißen — und das würde eine
Buchung ändern, die längst im Kontoauszug steht.

Stattdessen gibt es über **Etwas nachtragen** eine *zweite* Abrechnung
für dasselbe Quartal. Sie beginnt **leer**, verweist auf das Original und
geht den gewohnten Weg: einreichen, prüfen, auszahlen.

- Das Original bleibt unverändert bezahlt.
- Die automatische Übernahme schüttet in einen Nachtrag **nichts** —
  sonst stünde das ganze Quartal doppelt darin.
- Je Original gibt es einen Nachtrag. Fehlt danach noch etwas, hängt der
  nächste an diesem.
- Beide stehen unter „Frühere Abrechnungen"; gearbeitet wird immer am
  Ende der Kette.

## Mehr in der Statistik

- **Vorjahresvergleich** — die Jahressumme neben der des Vorjahres, mit
  Unterschied in Euro und Prozent, und zusätzlich Quartal gegen
  Vorjahresquartal. Dieselbe Jahreszeit vergleicht sich ehrlicher als das
  Quartal davor.
- **Hochrechnung** — was das Jahr voraussichtlich kostet. Gerechnet wird
  nur mit **voll vergangenen** Quartalen; das laufende ist halb leer und
  würde die Zahl nach unten ziehen. Ein abgeschlossenes Jahr wird nicht
  hochgerechnet, und es steht dabei, warum.
- **Nach Mannschaft** — was jede Mannschaft gekostet hat. Fahrten,
  Wettkämpfe und Sonstiges tragen keine Mannschaft und stehen deshalb in
  einer eigenen Zeile *ohne Mannschaft*, statt stillschweigend zu fehlen.
- **Als Tabelle herunterladen** — eine CSV für Excel und LibreOffice,
  fertig für den Jahresbericht. Sie entsteht im Browser aus den Zahlen,
  die ohnehin schon da sind; es geht nichts zusätzlich nach draußen.

## Der eigene Beleg im Portal

Unten in der eigenen Abrechnung steht **Als PDF** — dasselbe Dokument,
das die Kasse druckt und das die Mail nach dem Bezahlen mitbringt, jetzt
jederzeit selbst abrufbar. Es wird auf dem Server gebaut und als Datei
ausgeliefert.

Der Weg dorthin geht durch dasselbe Tor wie jeder andere Endpunkt
(Anmeldung, Einmal-Schlüssel, Zugang). Fremde Abrechnungen bekommen nur
Kasse und Administration — der **Wart nicht**, denn auf dem Beleg steht
die Bankverbindung.

## Jeden Schritt zurücknehmen

Die Administration kann jede Abrechnung auf jeden Stand setzen, auch
rückwärts: über **Stand…** in der Prüfung und in der Kasse.

Ein Rückschritt räumt auf, was zu den späteren Ständen gehört. Wer von
*bezahlt* auf *genehmigt* geht, bei dem verschwindet der
Zahlungsvermerk — sonst stünde in der Abrechnung, sie sei genehmigt und
zugleich bezahlt, und niemand wüsste, was gilt. Geht es vorwärts, werden
fehlende Zeitpunkte nachgetragen. Die betroffene Person wird
benachrichtigt; es ist ihr Geld.

## Pauschalen für einzelne Personen

Neben den Pauschalen der Mannschaft lassen sich welche für eine einzelne
Person hinterlegen (Verwaltung → Konten → Konto bearbeiten →
*Pauschalen dieser Person*). **Sie stehen über denen der Mannschaft.**

Je genauer ein Eintrag passt, desto eher gilt er:

1. Person + diese Mannschaft + dieser Wochentag
2. Person + diese Mannschaft + jeder Tag
3. Person + jede Mannschaft + dieser Wochentag
4. Person + jede Mannschaft + jeder Tag
5. Mannschaft + dieser Wochentag
6. Mannschaft + jeder Tag

So lässt sich von „für Sabine immer 33 €" bis „für Sabine dienstags bei
der Jugend 55 €" alles abbilden. Ein entfernter Eintrag fällt auf die
nächste Stufe zurück.

## Der Stand einer Abrechnung

Über der Abrechnung stehen die vier Stationen — **Entwurf → Eingereicht
→ Genehmigt → Bezahlt** — immer alle, auch die, die noch kommen.
Erledigte tragen ein Häkchen und ihr Datum, die aktuelle ist durch
Schriftschnitt und Rahmen hervorgehoben. Wurde eine Abrechnung
zurückgegeben, steht der erste Schritt wieder auf „Überarbeiten"; der Weg
bleibt gleich lang und springt nicht hin und her.

## Abteilungen

Ein Verein ab einer gewissen Größe ist gegliedert: Schwimmen, Wasserball,
Leichtathletik, Geschäftsstelle. Unter **Verwaltung → Abteilungen** wird
diese Gliederung hinterlegt. Sie ist die **Genehmigungseinheit** der
*Anderen Abrechnung*.

* **Jedes Konto gehört zu einer Abteilung.** Ihre Leitung genehmigt, was
  aus der Abteilung kommt.
* **Eine Abteilung muss keine Mannschaften haben.** Die Geschäftsstelle
  rechnet ab, trainiert aber nicht. Deshalb steht die Zuordnung in einer
  eigenen Tabelle und nicht in einer Spalte: *keine Zeile* ist ein
  gültiger Zustand und kein fehlender Wert.
* **Eine Mannschaft gehört zu höchstens einer Abteilung.** Sonst hätte die
  Frage „wer entscheidet" zwei Antworten. Wird eine Mannschaft anderswo
  gesetzt, wandert sie dorthin — und das wird **gemeldet**, damit niemand
  sucht, warum sie verschwunden ist.
* **Mehrere Leitungen je Abteilung** sind möglich (Leitung und
  Stellvertretung); wer zwei Abteilungen leitet, steht in beiden.

**Der interne Bereich bleibt unberührt.** Er kennt keine Abteilungen, und
es wird dort nichts angelegt oder geändert. Seine Mannschaften
(`lsv07_gruppen`) werden nur *gelesen*; hier steht lediglich, welche
Nummer zu welcher Abteilung gehört. Wird dieses Plugin entfernt, ist der
interne Bereich genau so, wie er vorher war.

Nach dem Update steht **jedes Konto auf „keine Abteilung"** — das ist
sichtbar und nicht versteckt. Weil hundert Zuordnungen von Hand eine
Stunde Klickarbeit wären, gibt es **Vorschläge**: abgeleitet aus den
Mannschaften, in denen ein Konto bisher abgerechnet hat. Vorgeschlagen
wird nur; zugeordnet wird mit einem eigenen Klick. Wer über Geld
entscheidet, soll nicht aus einer Statistik folgen.

Eine Abteilung, an der noch Konten oder Rechnungen hängen, lässt sich
**nicht löschen** — sie würden ohne Zuständigen zurückbleiben. Sie lässt
sich stattdessen **abschalten**: Dann laufen keine neuen Rechnungen mehr
darauf, aber ihr Name bleibt lesbar, und alte Rechnungen zeigen ihn
weiter.

## Andere Abrechnung

Nicht jede Auszahlung ist eine Quartalsabrechnung. Wer Material gekauft
hat, eine Hallenmiete auslegt oder die Rechnung eines Lieferanten
weiterreicht, legt sie unter **Andere Abrechnung** ab. Jedes Konto mit
Zugang darf das — auch eines ohne Trainerrolle: Material kauft auch, wer
nicht am Beckenrand steht.

### Der Weg

```
Entwurf  →  Eingereicht  →  Genehmigt  →  Bezahlt
   ↑            │   ↑           │
   └── zurück ──┘   └─ zurück ──┘        (Abteilungsleitung)
```

1. **Betreff, Betrag, Rechnungsdatum, Verwendungszweck.** Der Betrag darf
   deutsch geschrieben werden: `1.234,56`, `1234.56`, `12,50` — und
   `1.000` sind tausend Euro, nicht einer.
2. **Wohin überwiesen wird.** Vorbelegt sind die eigenen Zahlungsdaten aus
   *Meine Einstellungen*. Wer eine fremde Rechnung weiterreicht, hakt
   *„an mich selbst"* ab und trägt Empfänger, IBAN und BIC ein. Die IBAN
   wird mit der **Prüfziffer nach ISO 7064** geprüft — ein Zahlendreher
   fällt hier auf und nicht erst zwei Tage später bei der Bank.
3. **Der Beleg**, als Foto oder PDF. Mehrere sind erlaubt: Eine Rechnung
   kommt schon mal als zwei Fotos.
4. **Einreichen.** Was noch fehlt, steht vorher da und nicht erst hinter
   dem Knopf — dieselbe Prüfung vor und hinter dem Knopf, damit beide
   nicht auseinanderlaufen.
5. **Die Abteilungsleitung entscheidet.** Zurückgeben geht nur **mit
   Begründung**; der Dialog bleibt stehen, bis etwas dasteht.
6. **Die Kasse überweist** und vermerkt *bezahlt*. Danach wandert die
   Rechnung ins Archiv.

Solange niemand entschieden hat, lässt sich eine eingereichte Rechnung
**zurückholen**. Danach nicht mehr.

**Niemand genehmigt die eigene Rechnung** — auch eine Abteilungsleitung
nicht, die sie selbst eingereicht hat. Das ist die eine Regel, die eine
Genehmigung überhaupt zu etwas macht.

### Der QR-Code für die Überweisung

Nach der Genehmigung zeigt die Kasse zu jeder Rechnung einen
**Girocode nach EPC069-12** — den QR-Code, den jede deutsche Banking-App
liest. Gescannt, und Empfänger, IBAN, Betrag und Verwendungszweck stehen
fertig im Formular. Abgetippt wird nichts, also vertippt sich auch
niemand.

Im Verwendungszweck steht die **Rechnungsnummer dieses Systems**
(`RG-0042`). Das ist kein Beiwerk: Auf dem Kontoauszug muss sich eine
Buchung einer Rechnung zuordnen lassen, sonst sucht die Kasse im nächsten
Jahr.

Den Code gibt es **erst ab genehmigt** — auch nicht als Bild. Ein Girocode
ist eine fertige Überweisung; solange niemand zugestimmt hat, soll es sie
nicht geben.

Erzeugt wird er im Plugin selbst, ohne Programmbibliothek und ohne
Netzzugriff: Reed-Solomon, alle acht Masken, Versionen 1 bis 13. Geprüft
ist er gegen die **Dekodierseite** der Norm (alle Syndrome null) und
zusätzlich mit einem **fremden Lesegerät** (OpenCV) aus dem gerenderten
Bild — sowohl aus der Anzeige im Browser als auch aus dem gedruckten PDF.

### Das Rechnungsblatt

Zu jeder Rechnung gibt es **eine PDF-Seite** mit allem, was die
Überweisung ausmacht: Empfänger, IBAN, Betrag, Verwendungszweck, die
Belege und der **Weg der Rechnung** — wer wann eingereicht, genehmigt und
bezahlt hat. Der Girocode ist mit aufgedruckt: Wer das Blatt ausdruckt,
kann es scannen; wer es ablegt, hat Buchung und Prüfspur auf einem Blatt.

### Das Archiv

Unter **Archiv** steht alles Bezahlte, nach Jahren. Je Jahr gibt es Anzahl
und Summe, die Liste der einzelnen Rechnungen — und einen Knopf
**ZIP herunterladen**:

```
Rechnungen-2026/Uebersicht.csv
Rechnungen-2026/Uebersicht.pdf
Rechnungen-2026/RG-0042_Startbloecke/Rechnungsblatt.pdf
Rechnungen-2026/RG-0042_Startbloecke/Beleg-1_rechnung.pdf
Rechnungen-2026/RG-0042_Startbloecke/Beleg-2_IMG_0001.jpg
```

Die Übersicht gibt es zweimal: als **CSV** für die Buchhaltung (Semikolon,
BOM, Komma als Dezimalzeichen — Excel öffnet sie in deutscher Einstellung
auf Anhieb richtig) und als **PDF** für den Ordner.

**Die Belege werden nicht umgewandelt**, sondern genau so eingepackt, wie
sie hochgeladen wurden. Ein umgerechnetes Foto ist kein Originalbeleg
mehr, und genau darauf kommt es bei einer Prüfung an. Der hochgeladene
Name bleibt, bekommt aber eine Nummer davor: Zwei Fotos heissen schon mal
beide `IMG_0001.jpg`.

Das ZIP wird selbst geschrieben (Methode 0, ohne Komprimierung). Grund:
Die PHP-Erweiterung `ZipArchive` ist auf vielen Vereins-Hostern nicht
aktiv, und dann stünde die Kassenprüfung vor einem Knopf, der nichts tut.
Komprimieren bringt bei PDFs und Fotos ohnehin wenige Prozent und kostet
Rechenzeit, die ein geteilter Server nicht hat.

### Wo die Belege liegen

**Ausserhalb des Webverzeichnisses**, unter
`uploads/lsv07a-private/rechnung/<nr>/`. Eine Rechnung trägt Namen,
Anschriften und Beträge; der einzige Weg an die Datei führt über den
Download-Endpunkt, und der stellt dieselbe Frage wie die Ansicht.

* Der Ordner wird gesperrt, sobald er entsteht: `.htaccess` mit
  `Deny from all`, eine leere `index.html` gegen Verzeichnislisten und ein
  `README-NGINX.txt` für den, der nginx betreibt — **nginx liest keine
  .htaccess**, das muss in die Server-Konfiguration.
* Auf der Platte heisst jede Datei nach **16 Zufallsbytes**. Wer eine
  Rechnungsnummer errät, errät damit keinen Pfad.
* Geprüft wird an den **tatsächlichen Bytes** (`finfo` und zusätzlich die
  Kennung am Dateianfang), nicht an dem, was der Browser behauptet. Eine
  `rechnung.pdf`, in der ein Skript steht, ist kein PDF.
* Erlaubt sind PDF, JPEG, PNG und WebP, bis **12 MB** je Datei.
* Ausgeliefert wird als **Download** und nicht `inline`: Ein PDF, das der
  Browser im eigenen Fenster öffnet, läuft in der Herkunft der Seite; als
  Anhang kann es das nicht.

### Wer was sieht

| | Entwurf | Eingereicht | Genehmigt | Bezahlt |
|---|---|---|---|---|
| **die Person selbst** | ✓ | ✓ | ✓ | ✓ |
| **Abteilungsleitung** (eigene Abteilung) | – | ✓ | ✓ | ✓ |
| **Abteilungsleitung** (andere Abteilung) | – | – | – | – |
| **Kasse** | – | – | ✓ | ✓ |
| **Wart** | – | – | – | – |
| **Administration** | ✓ | ✓ | ✓ | ✓ |

Ein Entwurf geht niemanden etwas an, solange niemand ihn eingereicht hat.
Der Wart prüft Stunden und Beträge der Quartalsabrechnung — fremde
Rechnungen und Bankverbindungen gehen ihn nichts an, und was nicht
gebraucht wird, wird auch nicht herausgegeben.

Zur Genehmigung zählt die **Abteilung der Rechnung**, nicht die heutige
Abteilung der Person: Wer die Abteilung wechselt, nimmt eingereichte
Rechnungen nicht mit — sonst verschwänden sie beim bisherigen Leiter aus
der Liste, ohne dass jemand etwas davon merkt.

### Die Empfängerdaten sind ein Schnappschuss

Sie stehen **in der Rechnungszeile** und nicht als Verweis auf die
Stammdaten. Sonst zeigte eine vor zwei Jahren bezahlte Rechnung im Archiv
die heutige Bankverbindung, und die Kasse könnte nicht mehr belegen, wohin
damals überwiesen wurde. Beim Anlegen werden sie aus den Stammdaten
vorbelegt; fest werden sie mit dem Einreichen.

## Zur Gestaltung

Weiss und Blau, runde Ecken, weiche Tiefe statt harter Kanten. Blau ist
die Leitfarbe: Kopfzeile, aktive Reiter, Schrittanzeige, Balken.

**Die Oberfläche erklärt sich nicht selbst.** Es gibt keine
Einleitungstexte, keine Hilfesätze unter den Feldern, keine Untertitel
unter den Überschriften. Was auf dem Bildschirm steht, sind
Beschriftungen, Zahlen, Zustände und Rückmeldungen — sonst nichts.
Fehlermeldungen behalten ihren Grund, denn der sagt, was zu tun ist.
Was das System kann und warum es so gebaut ist, steht hier in dieser
Datei und nicht in der Anwendung.

**Die Farbe eines Knopfes sagt, was er tut.** Drei Bedeutungen, und jede
hat genau eine Farbe — wer die Beschriftung nicht liest, soll trotzdem
nicht versehentlich etwas zurücknehmen.

| Farbe | Bedeutung | Beispiele |
|---|---|---|
| **Grün** | es geht voran | Speichern, Genehmigen, Übernehmen, Einreichen, Als bezahlt markieren |
| **Rot** | es geht zurück | Abbrechen, Löschen, Zurückgeben, Beanstanden, Zurücknehmen |
| **Blau** | es öffnet nur | Ansehen, Bearbeiten, + Eintrag, Wochentage, Rückfrage, Probemail |

Blau gibt es zweimal: **gefüllt** für die Haupthandlung einer Ansicht,
als **Umriss** für alles Weitere. Sonst stünden auf einer Seite zehn
gleich laute Knöpfe und keiner führte. Rot ebenso: gefüllt für die
zurücknehmende Handlung selbst, als Umriss für *Abbrechen* — es steht
neben dem Knopf, der die Sache erledigt, und soll ihn nicht
überschreien.

Dieselbe Logik tragen die Statusmarken: Blau heisst unterwegs
(*Eingereicht*), Grün erledigt (*Genehmigt* als Umriss, *Bezahlt*
gefüllt), Rot zurückgenommen, Grau noch nichts.

**Farbe allein entscheidet nie.** Jeder Zustand steht zusätzlich
ausgeschrieben da, eine beanstandete Zeile trägt neben der roten Fläche
einen Balken und das Wort „beanstandet". Das bleibt eindeutig, wenn
jemand Farben schlecht unterscheidet. Alle Farben halten über 5:1
Kontrast zu ihrer Schrift — geprüft wird das maschinell über 230
Zustände bei jedem Testlauf, zusammen mit einer zweiten Prüfung, die
885 Knöpfe daraufhin durchgeht, ob ihre Farbe zur Beschriftung passt.

Die Belege — das PDF und die Fassung in der Mail — bleiben
**schwarz-weiss**. Sie sind Dokumente für die Buchhaltung und sollen
auch dann vollständig lesbar sein, wenn ein Druckdialog Flächen
weglässt.

**Ein Dialog geht nur zu, wenn jemand das ausdrücklich will:** über das
Kreuz in der Kopfzeile, über *Abbrechen* oder *Schließen*, oder mit
Escape. Ein Klick auf die Fläche daneben schliesst ihn **nicht**. Das
wäre bequem, geht aber regelmässig daneben: Wer einen Betrag im Dialog
markiert und die Maus dabei über den Rand hinauszieht, lässt sie
ausserhalb los — der Browser meldet den Klick dann auf der Fläche, und
das Eingetippte wäre weg, ohne dass jemand etwas angeklickt hätte.
Jeder Dialog trägt mindestens zwei sichtbare Wege hinaus; Escape
schliesst nur den obersten. Öffnet ein Dialog einen weiteren, legt er
sich darüber — nicht dahinter.

Gescrollt wird das **Dokument** — auf jedem Gerät, mit Maus wie mit
Finger. Einen eigenen Scroll-Bereich innerhalb eines fixierten Containers
gibt es bewusst nicht: Diese Konstruktion versagte erst auf dem Telefon
und dann am Rechner. Die Kopfleiste klebt stattdessen oben; das sieht aus
wie eine Anwendung und benutzt die Mechanik, die jeder Browser seit jeher
beherrscht.

Unter 1080 px wandern die Bereiche hinter den Menüknopf — mit
ausgeschriebenen Namen. Sechs unbeschriftete Sinnbilder nebeneinander
wären geraten, nicht gelesen.

Schriften liegen im Plugin (`assets/fonts`). Es wird kein Schriftdienst
Dritter eingebunden.

## Eigene Tabellen

`lsv07a_person`, `lsv07a_rolle`, `lsv07a_abrechnung`, `lsv07a_posten`,
`lsv07a_pauschale`, `lsv07a_config`, `lsv07a_log`,
`lsv07a_nachricht`, `lsv07a_nicht_auto`, `lsv07a_person_pauschale`,
`lsv07a_posten_notiz`, `lsv07a_wart_bereich`,
`lsv07a_abteilung`, `lsv07a_abteilung_mannschaft`,
`lsv07a_abteilung_leiter`, `lsv07a_rechnung`, `lsv07a_rechnung_datei`

Beim Deaktivieren bleiben sie stehen — es gehen keine Abrechnungen
verloren.

Spalten, die später dazugekommen sind, werden bei jedem Versionswechsel
**nachgerüstet** (`SHOW COLUMNS`, dann `ALTER TABLE`). `CREATE TABLE IF NOT
EXISTS` fasst eine bestehende Tabelle nicht an — ohne das bekäme eine
laufende Anlage neue Felder nie und liefe auf einen Datenbankfehler.
`abteilung_id` in `lsv07a_person` kommt auf diesem Weg.
