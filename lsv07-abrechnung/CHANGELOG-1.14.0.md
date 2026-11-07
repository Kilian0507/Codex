# Abrechnung 1.14.0 — die Statistik sagt jetzt, was man wissen muss

Bisher stand in der Statistik: Jahressumme, vier Quartale, Summe je
Posten-Art, Summe je Mannschaft, Vorjahresvergleich, Hochrechnung,
Summe je Stand. Das ist eine Buchhaltung, aber kein Überblick.

Was fehlte, war alles, woraus man **etwas tun** kann: wie lange etwas
liegt, was noch nicht eingereicht ist, wo etwas hängt — und der zweite
Geldfluss überhaupt.

## Der zweite Geldfluss war gar nicht drin

Die Statistik rührte nur `lsv07a_abrechnung` und `lsv07a_posten` an. Die
**Rechnungen** mit ihren Belegen, je Abteilung, hatten **keine einzige
Zahl**. Nur Listen.

Jetzt haben sie denselben Umfang: Summe und Anzahl, nach Stand, nach
**Abteilung**, nach **Zweck**, nach Monat, Vorjahresvergleich und eigene
Hochrechnung. Dazu drei Zahlen, die es nur hier gibt:

* **Was wirklich rausgegangen ist** — nach *Auszahlungs*datum, und nur
  was auf „bezahlt" steht. Das ist die Zahl für den Kassenbericht; der
  Rechnungsmonat ist es nicht.
* **An Dritte gegen Erstattung an die einreichende Person** — zwei
  verschiedene Vorgänge, bisher in einer Summe.
* **Rechnungen ohne Beleg**, nach Stand. Eine *genehmigte* Rechnung ohne
  Beleg ist ein Prüfungsproblem: § 147 AO und § 257 HGB verlangen zehn
  Jahre Aufbewahrung des Nachweises. Das steht jetzt auf der Seite.

Dazu Anzahl und Größe der Belege — für die Platte und das Jahrespaket.

## Drei Sichten statt zwei

| Sicht | Wer | Was darin steht |
|---|---|---|
| **Meine** | jedes Konto mit Zugang | nur das eigene Konto |
| **Abteilung** | Abteilungsleitung | genau die Konten, über die sie auch entscheidet |
| **Alle** | Wart, Kasse, Administration | alle Konten |

**Die Abteilungsleitung hatte bisher keine eigene Zahl.** Sie durfte
nur „Meine" — über die Rechnungen ihrer Abteilung entscheidet sie aber
täglich. Jetzt gibt es dafür einen eigenen Endpunkt, und er zeigt
**genau die Konten, die `konten_im_zugriff()` auch zum Genehmigen
freigibt**. Keine Menge mehr, keine weniger.

**Der Wart bekommt keine Rechnungszahlen.** Er prüft Stunden und Beträge
der Quartalsabrechnung; über Rechnungen entscheiden Abteilungsleitung
und Kasse. Statt Nullen steht da, warum es die Zahlen hier nicht gibt.

**Behoben:** Ein Konto mit Zugang **ohne** Trainerrolle lief in eine
Fehlermeldung — es durfte seine eigenen Zahlen nicht sehen, obwohl jedes
Konto mit Zugang Rechnungen einreichen darf. Das Tor fragt jetzt nach
Zugang, nicht nach der Trainerrolle. Mehr Zeilen gibt das nicht heraus:
Der Rahmen filtert weiterhin auf das eigene Konto, und zwar im SQL.

## Vier Kennzahlen — die der eigenen Rolle

Oben auf der Seite stehen vier Zahlen, und zwar die, mit denen diese
Rolle arbeitet.

| Rolle | Die vier Zahlen |
|---|---|
| **Trainer** | Jahr (beide Geldflüsse) · Hochrechnung · noch im Entwurf · wartet auf Auszahlung, mit Alter |
| **Wart** | zu prüfen · ältester offener in Tagen · beanstandete Posten · ohne Abrechnung im schwächsten Quartal |
| **Abteilungsleitung** | Abteilung im Jahr · zu genehmigen · ältester offener · Rechnungen ohne Beleg |
| **Kasse** | zu zahlen · ältester genehmigter · Jahr gegen Vorjahr · Auszahlung dauert |
| **Administration** | Jahr gesamt · Prüfung dauert · Auszahlung dauert · gerade zurückgegeben |

**Jede Kachel nennt ihre Einheit** — Euro, Tage oder Stück. Sonst wird
„12" für Euro gehalten. Und wo eine Zahl **nicht** zu haben ist, steht
statt einer 0 ein Strich und darunter der Grund.

## Wie lange es dauert

Die drei Zeitstempel `eingereicht_am`, `genehmigt_am` und `bezahlt_am`
standen seit Anfang in der Datenbank und wurden nie gerechnet. Jetzt:

* **Einreichen → Genehmigung → Geld**, je Stufe und als ganzer Weg.
* **Als Median, nicht als Mittelwert.** Ein einziger Altfall, der ein
  Jahr lag, würde den Mittelwert so verziehen, dass er über den
  Normalfall nichts mehr sagt.
* **Was gerade liegt**, je Stufe: Anzahl, Betrag, und wie alt der
  älteste ist — mit Datum. Diese Zahl gilt bewusst **über alle Jahre**:
  Der eingereichte Vorgang von vorletztem Jahr ist genau das Problem,
  das man sehen will. Die Liegezeiten dagegen werden am Jahr der
  Einreichung gemessen, sonst vermischen sich alte und neue Praxis.

**Gemessen wird je Stufe, nicht je Person, die entscheidet.** Eine
Kennzahl über die Arbeitsgeschwindigkeit eines namentlich genannten
Kontos ist Leistungsüberwachung. Das ist keine Lücke, sondern eine
Entscheidung, und sie steht so auf der Seite.

## Was liegengeblieben ist

* **Im Entwurf** — Geld, das erfasst, aber nicht eingereicht ist. In
  beiden Geldflüssen. Das hat vorher niemand gesehen.
* **Wer für ein Quartal nichts hat** — Grundmenge sind die **aktiven
  Konten mit Trainerrolle**; ein Konto ohne diese Rolle hat keine
  Quartalsabrechnung und fehlt daher auch nicht. Namen stehen nur in den
  Sichten *Abteilung* und *Alle*.
* **Noch nicht übernommen** — Trainings und Wettkampftage aus dem
  internen Bereich, die in keiner Abrechnung angekommen sind, und
  daneben die bewusst ausgeschlossenen.
  Diese Zahl gibt es **nur in der Sicht *Meine***: Für den ganzen Verein
  müsste der interne Bereich je Trainer einzeln befragt werden — das
  wären Dutzende Abfragen für eine Zahl. Das steht auf der Seite, statt
  die Zahl weglassen und niemandem erklären, warum sie fehlt.

## Mengen statt nur Geld

Geld allein erklärt nichts. Dieselben Vorgänge in Einheiten:

* **Abschnitte** bei Wettkämpfen (die Angabe aus 1.11.0 wurde nirgends
  summiert).
* **Kilometer** — einfache Strecke × Tage × Hin und Rück, so wie
  eingestellt; die Einstellung steht dabei.
* **Fahrten unter der Mindeststrecke**, die 0 € ergaben. Das erklärt
  Rückfragen, bevor sie kommen.
* **Trainings mit Wartezeit** — Anzahl und Betrag der Posten.
  Der Zuschlag selbst steht nirgends getrennt in der Datenbank; eine
  Summe des Zuschlags zu behaupten wäre schlimmer als keine, und darum
  steht genau das auf der Seite.
* **€ je Stunde je Mannschaft** — macht Pauschale und Zeiten
  vergleichbar. Gerechnet nur aus Training und Vorbereitung: Eine Fahrt
  hat keine Stunden und würde den Wert verfälschen.

## Neue Achsen

Alles war vorhanden, nur nicht als Auswertung:

* **Abteilung** bei der Quartalsabrechnung — über die Mannschaft des
  Postens. Fahrten, Wettkämpfe und Sonstiges tragen keine Mannschaft und
  stehen darum unter *Ohne Abteilung*, statt zu fehlen.
* **Monat** und **Wochentag**. Der Wochentag nur für Training und
  Vorbereitung — nur die tragen einen, und das steht dabei.
* **Abrechnungsart** (Pauschale gegen Zeiten) und **Herkunft**
  (automatisch übernommen gegen von Hand erfasst). Letzteres sagt, ob
  die Automatik trägt.

## Qualität des Erfassens

Beanstandete Posten (Anzahl, Betrag, nach Art), **Plan gegen Ist** aus
`menge_plan` — Stunden tatsächlich gegen Stunden laut Trainingsplan,
und wie viele Posten abweichen — und **gerade zurückgegeben**, mit
Anteil.

„Gerade zurückgegeben" ist der **Stand von heute**, keine Quote über das
Jahr. `rueckgabe_grund` wird beim erneuten Einreichen geleert, und aus
dem Protokoll wird hier nichts gerechnet. Eine Quote „wie oft kam es
vor" liesse sich also nur erfinden; sie steht deshalb nicht da, und der
Grund auch.

## Eine Stelle je Kennzahl

Alles Rechnen liegt jetzt in **`LSV07A_Statistik`** (neu). Die
AJAX-Klasse ist nur noch Tor und Zusammenstellung.

* **`rahmen( $sicht, $uid )`** beantwortet „wer darf was sehen" — und
  gibt `null` zurück, wenn ein Konto diese Sicht nicht haben darf.
  Bewusst `null` statt stillschweigend auf die engere Sicht
  zurückzufallen: Eine Zahl, die etwas anderes bedeutet als der Reiter
  sagt, ist schlimmer als eine Absage.
* **Die leere Personenliste heisst „nichts", nicht „alles".** Eine
  Abteilungsleitung ohne Abteilung sieht keine fremden Zahlen. Im SQL
  wird daraus `1 = 0` und nicht `IN ()` — letzteres bricht in MySQL ab.
* **Die Posten eines Jahres werden EINMAL geholt** und in einem
  Durchlauf nach Quartal, Monat, Wochentag, Typ, Mannschaft, Abteilung,
  Quelle und Art aufgeteilt. Kein N+1, auch nicht bei zwanzig
  Auswertungen auf einer Seite.
* **Keine datenbankeigenen Datumsfunktionen** (`MONTH`, `WEEKDAY`,
  `DATEDIFF`). Damit läuft im Prüfstand gegen SQLite dasselbe SQL wie in
  der Anlage gegen MySQL — geprüft wird also das echte SQL.
* **Tage werden in Kalendertagen gezählt**, nicht in
  24-Stunden-Schritten: Sonst stünde etwas, das gestern um 23 Uhr
  eingereicht wurde, heute früh mit „0 Tage" da.

## Oberfläche

Drei Knöpfe statt zwei, und nur die erlaubten. Die Seite trägt viele
Auswertungen — was zur Rolle gehört, liegt offen, der Rest liegt in
aufklappbaren Blöcken, und der Titel sagt, was drin ist. Die Seite nennt
oben, **was sie zeigt** („alle Konten", „Abteilung: Schwimmen", „nur das
eigene Konto"). Der CSV-Export enthält alles von oben.

## Was die Statistik nicht tut

Das ist Teil der Arbeit, nicht ein Rest davon:

* **Keine Rangliste fremder Konten für Trainer.**
* **Keine Liegezeit je Genehmiger.**
* **Nichts aus `lsv07a_log`.** Das Protokoll ist Nachweis. Wer es
  auswertet, fängt an es zu pflegen, und dann ist es kein Nachweis mehr.
* **Keine Bankverbindung, keine Anschrift**, keine Zahl über einzelne
  Sportler. Geprüft: Keine Abfrage der Statistik berührt eine
  Personen-, Schwimmer- oder Protokoll-Tabelle.
* **Kein Soll-Ist gegen ein Budget.** Es gibt keinen gepflegten
  Budget-Wert in `lsv07a_config`; eine Ampel ohne Grundlage wäre
  erfunden. Dafür bräuchte es erst ein Feld je Abteilung und Jahr.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Statistik, Server (echte Klassen, echtes SQL) | 266 | 88 von 88 |
| Statistik, Oberfläche (echtes app.js im Browser) | 116 | läuft noch |

**Die Gegenprobe der Oberfläche ist beim Erstellen dieses Eintrags noch
nicht durch.** Der erste Lauf brach nach dem zweiten Bruch ab — nicht
wegen des Plugins, sondern wegen eines Fehlers im Prüfstand selbst: Ein
vorzeitiges Verlassen eines Abschnitts übersprang das Schliessen des
Browsers, und damit endete der Lauf nie und druckte keine einzige
Zusicherung. Genau die Art Fehler, gegen die jeder Aufruf hier in einem
Helfer steckt. Der Prüfstand ist repariert (jeder Abschnitt läuft in
einem eigenen Rahmen, Bericht und Schliessen stehen in einem `finally`);
der vollständige Lauf wird nachgetragen.

Die Oberfläche wird mit den **echten Antworten der echten
PHP-Endpunkte** gefahren: Der Prüfstand erzeugt sie und verlinkt die
echte `app.js` und `style.css`, er kopiert sie nicht.

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft
sie nichts und ist wertlos. Auf der Serverseite darunter: der Sicht
aller für jeden, die Abteilungssicht ohne Abteilungsfilter, dem Wart
Rechnungszahlen geben, Mittelwert statt Median, die offenen Stufen auf
ein Jahr einschränken, eine Abfrage je Person einbauen, Belege in die
Summe doppeln, `empf_eigen` verwechseln, und jede Kopfzeile des Tors
einzeln weglassen.

**Beim ersten Lauf blieben zwölf Brüche grün** — acht davon, weil die
Prüfung die Regel gar nicht abfragte (die Anzahl der Abrechnungen je
Quartal, das laufende Quartal in der Hochrechnung, € je Stunde aus
stundenlosen Posten, „rausgegangen" ohne Bezahlt-Status, der
Abteilungs-Endpunkt selbst, und weitere). Diese Zusicherungen wurden
nachgezogen, nicht die Brüche gestrichen. Zwei Erwartungen in der
Prüfung waren falsch gerechnet und wurden korrigiert.
