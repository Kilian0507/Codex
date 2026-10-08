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
| **Kasse** | **nur genehmigte und bezahlte** Abrechnungen sehen, als PDF ausgeben, als bezahlt markieren, Statistik |
| **Administrator** | alles, dazu Konten, Rollen, Sätze, Pauschalen, Saisons und Trainingszeiten |

Ein WordPress-Administrator ist immer auch hier Administrator — sonst käme
nach der Installation niemand an die erste Rolle.

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

## Zur Gestaltung

Die Oberfläche ist an Microsoft 365 und Windows angelehnt: Segoe UI (vom
Gerät, nichts wird nachgeladen), kleine Radien, Haarlinien statt
Schlagschatten, dichte Listen, Befehle über dem Inhalt.

**Es gibt bewusst keine Akzentfarbe.** Zustände unterscheiden sich über
Rahmen, Füllung und Schriftschnitt — und stehen zusätzlich immer
ausgeschrieben da („Eingereicht", „Genehmigt", „Bezahlt"). Das bleibt auch
dann eindeutig, wenn jemand Farben schlecht unterscheidet oder den Beleg
schwarz-weiss ausdruckt.

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
`lsv07a_posten_notiz`, `lsv07a_wart_bereich`

Beim Deaktivieren bleiben sie stehen — es gehen keine Abrechnungen
verloren.
