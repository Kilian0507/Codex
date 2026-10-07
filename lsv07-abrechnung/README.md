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
| **Trainer** | eigene Abrechnung sehen und bearbeiten, Zahlungsdaten hinterlegen, einreichen, eigene Statistik |
| **Wart** | jede Abrechnung im Detail sehen — auch nicht eingereichte —, genehmigen, zurückgeben, Statistik aller Trainer |
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

## Sicherheit

Grundsatz: Es verlässt nichts den Server, und jeder bekommt nur, was er
für seine Aufgabe braucht.

- **Nichts geht nach draußen.** Kein Abruf fremder Server, keine
  E-Mail, keine Schrift und kein Skript von einem anderen Ort. Die
  Benachrichtigungen liegen deshalb bewusst **im System** statt im
  Postfach: Eine Abrechnung enthält Beträge und Namen, die nicht
  ungefragt über fremde Server gehen sollen. Geprüft wird das maschinell
  bei jedem Testlauf.
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
`lsv07a_nachricht`, `lsv07a_nicht_auto`

Beim Deaktivieren bleiben sie stehen — es gehen keine Abrechnungen
verloren.
