# LSV07 SwimPlan — 1.4.0

Trainingsplanung und Leistungsverwaltung für Schwimmvereine. Trainer bauen
Trainingseinheiten aus Sets — bis hin zu **einer eigenen Übung je
Wiederholung** — **oder hängen ihren Plan als PDF an**, ordnen sie
Mannschaften oder einzelnen Schwimmern zu und erfassen Zeiten. Stammdaten,
Mitgliederlisten, Zeiten und Sets lassen sich **je Kategorie aus einer CSV
einlesen**, mit Musterdatei und Vorschau. Eigenes
Plugin, unabhängig von `lsv07-intern`, `lsv07-abrechnung` und
`lsv07-swimview` — es liest keine Tabelle eines anderen Plugins und führt
alles selbst.

**Zugang ausschliesslich für angemeldete WordPress-Konten — und das sind
Trainer und Verwaltung. Schwimmer haben kein Konto.**

## Einrichten

1. Plugin aktivieren. Die eigenen Tabellen legt es selbst an.
2. Eine Seite anlegen und den Shortcode `[swimplan]` hineinsetzen
   (`[lsv07_swimplan]` geht auch). **Diese Seite wird zur Anwendung:** Sie
   wird mit einer eigenen Vorlage ausgeliefert, das Theme steuert kein Markup
   bei — kein Kopf, keine Navigation, keine Seitenleiste, keine Fusszeile.
   Jede andere Seite bleibt unberührt.
3. Die Seite aufrufen. **Mehr ist nicht einzurichten:** Wer die
   WordPress-Seite verwalten darf, ist hier Verwaltung und kann sofort
   Trainer eintragen, Mannschaften anlegen und Schwimmer erfassen.

Die Seite trägt `noindex, nofollow` — sie gehört in keinen Suchindex.

## Wer hineinkommt

SwimPlan führt **keine eigene Anmeldung**. Passwörter, Sitzungen,
Zurücksetzen und, wenn der Verein mag, Zwei-Faktor sind damit Sache von
WordPress. Was SwimPlan führt, ist die **Rolle** eines Kontos — und es gibt
genau zwei:

| Rolle | darf |
|---|---|
| `admin` — Verwaltung | Konten, Rollen, Schwimmer, Mannschaften, Trainerzuteilung, Einstellungen, alle Pläne |
| `coach` — Trainer | die eigenen Mannschaften und Pläne, Mitgliedschaften seiner Mannschaften, Schwimmer und Zeiten seiner Mannschaften |

### Schwimmer haben kein Konto

> **Ein Schwimmer ist ein Datensatz, kein Zugang.**

Name, Jahrgang, freiwillig die DSV-ID, eine Notiz — mehr steht nicht an
ihm, und mehr braucht er nicht. Er hat **kein WordPress-Konto, kein
Passwort und keinen Weg in SwimPlan hinein**. Angelegt wird er in einem
Feld mit einem Namen darin, von der Verwaltung oder von einem Trainer; ein
Nachname allein genügt.

Das ist eine bewusste Entscheidung und kostet etwas: Kinder können ihren
Plan nicht selbst abrufen. Dafür spricht:

* **Jedes Konto ist eine Angriffsfläche.** Hundert Kinderkonten sind
  hundert Passwörter, die in Familien geteilt, aufgeschrieben und
  wiederverwendet werden — und jedes davon führt zu den Trainingsdaten
  einer Mannschaft.
* **Ein Konto für ein Kind braucht eine E-Mail-Adresse.** Damit wären
  Kontaktdaten von Minderjährigen im System, die SwimPlan für seine
  Aufgabe nicht braucht.
* **Der Plan erreicht die Kinder ohnehin**: als **Aushang** (dasselbe PDF
  ohne die Notizen des Trainers, ein Knopf am Plan), auf Papier, oder über
  die **Bühne** am Beckenrand, die der Trainer aufklappt. Das ist der Weg,
  den Vereine ohnehin gehen.

Wer einen Schwimmer nicht mehr führen will, **trägt ihn aus statt ihn zu
löschen**: Seine Zeiten und seine Mannschaftsgeschichte bleiben stehen, er
fällt nur aus den Listen. Gelöscht wird nur, woran nichts hängt — und das
darf nur die Verwaltung.

**Wer WordPress verwalten darf, verwaltet auch SwimPlan.** Ein Konto mit dem
Recht `manage_options` ist hier Verwaltung — ohne Eintrag, ohne Einrichtung,
ab dem ersten Aufruf. Der Eintrag entsteht dabei von selbst, damit das Konto
auch in der Liste und in der Prüfspur steht; in der Oberfläche trägt es den
Vermerk **„über WordPress"** und lässt sich dort weder umstellen noch
abschalten.

Das hat einen Preis, und der soll hier stehen: Jeder, der aus irgendeinem
Grund WordPress-Administrator ist, sieht damit die Trainingsdaten von
Kindern. Dafür spricht, dass er ohnehin an sie herankäme — wer Plugins
bearbeiten darf, kann sich jedes Recht selbst geben. **Wer hier nicht
hineinsehen soll, darf also kein WordPress-Administrator sein.**

**Jedes andere Konto kommt nur mit Eintrag hinein.** Trainer werden benannt
und nicht abgeleitet; ein angemeldetes Konto ohne Eintrag sieht nur den
Hinweis, dass es noch nicht freigeschaltet ist.

Wer den Zugang verliert, wird **abgeschaltet und nicht gelöscht**: Pläne,
Mitgliedschaften und Zeiten bleiben erhalten, und ein gelöschter Eintrag
liesse sie verwaisen. Ein abgeschaltetes Konto kommt beim nächsten Aufruf
nicht mehr hinein.

## Die Trainingseinheit

Ein Plan besteht aus Sets. Ein Set ist, was ein Trainer aufschreibt:
**8 × 50 m Rücken auf 1:00**, dazu Material und ein Hinweis. Daraus rechnet
SwimPlan die Zahlen, die er sonst im Kopf rechnet.

### Eine Übung je Wiederholung

Manche Sets sind nicht viermal dasselbe, sondern vier verschiedene Übungen
in einem Block. Dafür gibt es die **Beschreibung** am Set: eine Zeile je
Wiederholung.

```
Fäuste
Einarmig rechts
Einarmig links
Ganze Lage
```

Die Zeilen werden durchnummeriert — eine eigene Nummer braucht es nicht,
und eine getippte wird abgeschnitten, damit nicht „1. 1. Fäuste"
dasteht. Sie erscheinen im Plan, auf der Bühne, im Fokus und im PDF, jede
auf einer eigenen Zeile. Stehen mehr oder weniger Zeilen da als
Wiederholungen, sagt die Oberfläche das — meist ist eine Zeile vergessen
worden. Vier Sets anzulegen wäre der alte Weg und am Beckenrand vier
Zeilen Papier.

Eine Festlegung zieht sich durch alles, und sie steht auch in der
Oberfläche:

> **Intervall ist der Abstand INNERHALB des Sets, Pause ist die Pause NACH
> dem Set.**
>
> Setdauer = Wiederholungen × Zyklus + Pause

„8 × 50 m auf 1:00, danach 2 Minuten Pause" sind also 8 × 60 + 120 = 600
Sekunden. Steht kein Intervall da, tritt ein **Richtwert** an seine Stelle
(Voreinstellung 1:50 auf 100 m Freistil, je Lage anders gewichtet,
einstellbar unter Konten). Solche Sets sind im Plan und im PDF mit `*`
gekennzeichnet: Wer es genau wissen will, trägt Intervalle ein — dann
rechnet SwimPlan nicht mehr, sondern zählt.

Dazu kommt der **Zuschlag** am Plan: Zeit für Erklärungen, Bahnwechsel und
organisatorische Pausen. Sie steht am Plan und nicht an den Sets, weil sie
sich keinem Set zuordnen lässt.

### Zustände

```
draft ──► published ──► completed
  │           │
  └──────► cancelled ──► draft
```

* **Ein leerer Plan lässt sich nicht veröffentlichen** — es braucht
  mindestens ein Set oder ein angehängtes PDF. Ein leerer Plan bei den
  Schwimmern ist schlimmer als kein Plan.
* **Gelöscht wird nur ein Entwurf oder eine Absage.** Ein veröffentlichter
  Plan wird abgesagt oder abgeschlossen — die Schwimmer haben ihn gesehen,
  und was sie gesehen haben, verschwindet nicht rückwirkend.
* **Jede Änderung nach der Veröffentlichung** zählt die Fassung hoch und wird
  als vollständige Abschrift festgehalten (nachzulesen unter „Fassungen").
  Ein Plan, der sich unter den Schwimmern stillschweigend ändert, ist
  schlimmer als ein falscher Plan. Der Wechsel auf „durchgeführt" oder
  „abgesagt" wird festgehalten, zählt aber nicht hoch — am Inhalt hat sich
  nichts geändert.

### Wer welchen Plan sieht

| | sehen | ändern |
|---|---|---|
| Verwaltung | alle | alle |
| Trainer | die eigenen und die einer seiner Mannschaften zugeordneten | nur die eigenen |

Wer den Plan eines Kollegen weiterverwenden will, **kopiert** ihn; dann hat
er einen eigenen und ändert nicht den, auf den sich eine andere Mannschaft
schon eingerichtet hat.

### Für wen die Einheit ist

Die **Zuordnung** sagt, für wen ein Plan gedacht ist: für eine Mannschaft
oder für einzelne Schwimmer. Sie ist seit 1.3.0 keine Freigabe mehr — es
gibt niemanden, für den etwas freizugeben wäre. Was sie leistet, ist die
**Teilnehmerliste**: die Namen, die auf dem Aushang zum Abhaken stehen und
die im Plan unter „Für wen" erscheinen.

Drei Festlegungen:

1. **Ein Plan ohne Zuordnung** steht ohne Teilnehmer da — die Oberfläche
   sagt das in der Liste und beim Veröffentlichen.
2. **Eine Zuordnung geht an eine Mannschaft ODER an einen Schwimmer**, nie
   an beides in einer Zeile.
3. **Die Mannschaftszuordnung gilt zum Datum des Plans.** Wer im Juni die
   Mannschaft verlassen hat, steht auf den Plänen bis Juni und auf denen von
   Juli nicht mehr. Wer heute aufgenommen wird, steht auf dem Plan von
   nächster Woche. Die Prüfung fragt also nicht „ist Mitglied", sondern „war
   Mitglied an diesem Tag".

## Der Plan als PDF

Viele Trainer haben ihre Pläne längst als PDF — aus Word, aus einer Vorlage,
vom Verband. Die hängt man an die Einheit, statt sie Set für Set abzutippen:
**Der Plan als PDF** im Plan, Datei wählen, hochladen. Eine Einheit kann
Sets haben, ein PDF, oder beides — das PDF als Original und die Sets für die
Meter- und Dauerrechnung.

Die Datei steht im Plan, auf der Bühne und im Aushang — sie **ist** bei
vielen Trainern der Plan und wird darum nicht beim Trainer zurückgehalten.

* **Nur PDF, höchstens 15 MB.** Ein Bild wäre bequem, aber ein
  abfotografierter Zettel ist am Beckenrand nicht zu lesen; Office-Dateien
  können Makros tragen. Geprüft wird an den tatsächlichen Bytes (finfo *und*
  die PDF-Kennung am Dateianfang), nicht an dem, was der Browser behauptet.
* **Die Datei liegt ausserhalb der Reichweite des Webservers**, unter
  `uploads/lsv07p-private/plan/<Nummer>/`, mit `.htaccess`-Sperre und einem
  Hinweis für nginx daneben. Auf der Platte trägt sie einen Zufallsnamen —
  wer eine Plannummer errät, errät damit keinen Pfad.
* **Der einzige Weg an die Datei ist der Download-Endpunkt**, und der stellt
  dieselbe Frage wie die Ansicht: Darf diese Person diesen Plan sehen? Die
  Regel „Mitglied zum Datum des Plans" gilt also bis an die Datei heran.
* Eine angehängte Datei zählt die **Fassung** hoch wie jede andere Änderung.
  Beim **Kopieren** einer Einheit kommt sie mit — als echte Kopie, damit das
  Löschen der einen der anderen nicht den Plan wegnimmt. Beim **Löschen**
  einer Einheit gehen ihre Dateien mit; was danach doch noch verwaist liegen
  bliebe, räumt die tägliche Pflege weg.

## Übungsbibliothek

Eine Vorlage ist eine Folge von Sets mit einem Namen — „Technikblock Brust",
„Hauptserie 8 × 100". Einmal angelegt, in jeden Plan einfügbar.

> **Beim Einfügen werden die Sets KOPIERT.**

Es gibt keine Verbindung zwischen dem Set im Plan und dem in der Vorlage. Wer
die Vorlage später ändert, ändert damit keinen bereits erstellten oder
veröffentlichten Plan. Mit einem Verweis statt einer Kopie hätte sich der
Plan, den die Schwimmer gestern gelesen haben, heute still verändert. Der
Preis: Eine verbesserte Vorlage verbessert keinen alten Plan. Das ist der
richtige Preis.

Eine Vorlage **für alle** ist zum Benutzen freigegeben, nicht zum
Umschreiben — ändern darf sie nur, wer sie angelegt hat.

**Ein einzelnes Set** geht denselben Weg: An jedem Set im Plan steht
„In die Bibliothek". Das ist der häufigere Fall als der ganze Plan — eine
Serie, die sich bewährt hat, soll wiederkommen, nicht das Training
drumherum. Auch hier wird kopiert.

## Zeiten und Bestleistungen

* **Eine Zeit ist eine ganze Zahl in Hundertstelsekunden.** 01:02,35 steht
  als `6235` in der Datenbank. Mit Kommazahlen käme irgendwann eine Zeit
  heraus, die um ein Hundertstel danebenliegt — und die ist bei einer
  Qualifikation eine falsche Zeit.
* **Bestleistungen werden ERMITTELT, nicht gepflegt.** Es gibt keine Spalte
  „Bestzeit". Die Bestzeit ist das Minimum über die erfassten Zeiten, jedes
  Mal frisch, getrennt nach Strecke, Lage **und Bahnlänge**. Eine gepflegte
  Bestzeit liesse sich von einer nachgetragenen älteren Zeit überschreiben,
  von einem gelöschten Eintrag verwaisen und von einem Tippfehler für immer
  verderben. Eine ermittelte nicht: Korrigiert man die Zeit, stimmt die
  Bestzeit im selben Moment wieder.
* **Zeiten hängen am Schwimmer, nicht an einem Konto.** Erfassen und ändern
  dürfen Trainer (für ihre Mannschaften) und die Verwaltung. Eine Bestzeit
  ist damit ein Ergebnis und keine Behauptung.
* Eingegeben werden darf `28,42`, `28.42`, `1:02,35` oder `28`. Sortiert wird
  nach Lage und dann nach Strecke — nicht alphabetisch; dort stünde 100 m vor
  50 m und 1500 m ganz oben.

Dazu: **Verlauf** je Strecke (mit dem Gewinn gegenüber der bis dahin besten
Zeit) und **Rangliste** innerhalb der Mannschaft (gleiche Zeit, gleicher
Platz; nur aktuelle Mitglieder).

## Die Bühne

Der Plan am Beckenrand, auf dem Tablet, im Stehen, mit nassen Händen:

* **Vollbild** — die Bühne liegt über der Anwendung; der Knopf „Vollbild"
  schaltet zusätzlich das echte Vollbild des Browsers ein.
* **Splitscreen** — bis zu **sechs** Pläne nebeneinander, in ein bis vier
  Spalten. Mehr Spalten als Pläne wären leere Spalten; gezeigt wird darum
  höchstens so viel, wie da ist, während die Einstellung selbst stehen
  bleibt.
* **Größe frei einstellbar** — 60 bis 220 Prozent, über Regler oder die
  Knöpfe − und +. Alles auf der Bühne rechnet in `em`, darum wachsen mit der
  Schrift auch die Abstände mit.
* **Knapp** — blendet Titel, Hinweise und Material aus; dann passt der ganze
  Plan auf einen Blick, auch bei doppelter Schrift.
* **Ein Set im Fokus** — ein Tipp auf ein Set, und nur dieses bleibt
  stehen, deutlich grösser als auf der Bühne darunter. Mit `◀` und `▶`
  oder den Pfeiltasten geht es weiter, ohne zwischendurch in die Übersicht
  zurück. Escape schliesst erst den Fokus und dann die Bühne — nicht beides
  mit einem Druck. Jedes Set ist dafür ein Knopf und damit auch mit der
  Tastatur erreichbar.
* **PDF** — der Plan oder alle Pläne der Bühne in einer Datei.

Die Einstellung liegt **am WordPress-Konto**, nicht im Browser: Wer das
Vereinstablet benutzt, findet seine Ansicht wieder, und wer sich abmeldet,
lässt sie dort nicht zurück.

**Auf dem Telefon** (bis 760 px) stehen die Pläne untereinander — zwei
Spalten auf 390 px sind zwei unlesbare Spalten. Die Spaltenwahl ist dort
ausgeblendet, weil sie nichts bewirken würde; die Einstellung bleibt
erhalten und gilt wieder am Tablet.

## PDF

Zu jeder Ansicht einen Bericht: Plan (für den Trainer und als **Aushang**),
mehrere Pläne in einer Datei, Bestzeiten, Verlauf, Mannschaft, Rangliste.

* **Der Aushang** ist derselbe Plan ohne die Notizen des Trainers und ohne
  die Liste der Zuordnungen — dafür mit den Namen der Teilnehmer und einem
  **Kästchen zum Abhaken** daneben, in zwei Spalten, damit auch eine grosse
  Mannschaft auf eine Seite passt. Er ist der Weg, auf dem der Plan zu
  Kindern kommt, die keinen Zugang haben.
* Die Berichte entstehen **auf dem Server** und durchlaufen dieselbe
  Rechteprüfung wie die Ansicht. Ein PDF kann nie mehr enthalten als der
  Bildschirm.
* Dateiname nach dem Beispiel aus dem Konzept:
  `C-Mannschaft_2026-10-19.pdf`.
* Geschrieben wird ohne Fremdbibliothek, mit den beiden Standardschriften,
  die jedes Anzeigeprogramm mitbringt. Die Dateien bleiben winzig, und es
  geht nichts an eine fremde Schriftquelle hinaus.

## Vollbild: nur die Anwendung

Die Seite mit dem Shortcode wird über `templates/vollbild.php` ausgeliefert
statt über die Vorlage des Themes. `get_header()` und `get_footer()` werden
nicht aufgerufen — Kopfleiste, Navigation, Seitenleiste und Fusszeile des
Themes werden also nicht versteckt, sondern gar nicht erst erzeugt.

Dazu kommen drei Dinge, ohne die trotzdem ein Rahmen übrig bliebe:

* **Die Stilvorlagen des Themes werden nicht geladen.** Kein Theme-Markup
  heisst nicht automatisch kein Theme-CSS: Viele Themes setzen Polster auf
  `body`, Blockthemen schicken über `global-styles` Randabstände und eine
  Inhaltsbreite mit. Entfernt wird gezielt, was aus dem Theme-Verzeichnis
  kommt, dazu die Blockstile des Kerns und das eigene CSS aus dem
  Customizer. **Die Stilvorlagen anderer Plugins bleiben**, damit ein
  Cookie-Hinweis nicht unformatiert im Raum steht.
* **Ein harter Rücksetzer im Kopf der Seite** setzt Rand, Polster, Rahmen
  und Inhaltsbreite von `html` und `body` auf null — mit `!important` und
  ohne Klasse, weil das Polster oft am `<html>` sitzt und weil ein Plugin
  seine Stilvorlage auch erst nach unserer dazwischenschieben kann.
* **Die Anwendung füllt das Fenster**, von Kante zu Kante. Die dunkle
  Kopfleiste geht über die ganze Breite; ihr Inhalt richtet sich an
  derselben Spalte aus wie die Seite darunter (höchstens 1360 px, mittig).
  Linksbündig blieb auf einem breiten Schirm rechts eine leere Fläche
  stehen, und die sieht aus wie ein vergessener Rand.

Abschaltbar: `lsv07p_vollbild = 0` liefert die Seite wieder über das Theme
aus (dann greifen auch der Rücksetzer und das Abräumen nicht);
`lsv07p_vollbild_rein = 0` behält das Vollbild, lässt aber die Stilvorlagen
des Themes stehen.

**Was dadurch nicht verschwindet:** ein Cookie-Banner oder Chat-Widget, das
sich über `wp_footer` einhängt. Solche Werkzeuge haben in der Regel eine
Einstellung, auf welchen Seiten sie erscheinen — dort die SwimPlan-Seite
ausnehmen. (`wp_body_open` wird nicht aufgerufen.)

## CSV-Import — in jeder Kategorie für sich

Zu Saisonbeginn kommt alles auf einmal: die Mannschaftslisten aus einer
Tabelle, die Zeiten aus dem Meldeprogramm, die Sets aus dem Plan des
Vorjahres. Dafür gibt es in **jeder Kategorie dort, wo sie hingehört**,
einen CSV-Import — und keinen einen grossen Import für alles. Eine Datei,
in der Schwimmer, Mannschaften und Zeiten durcheinanderstehen, lässt sich
weder sinnvoll prüfen noch sinnvoll melden.

| Kategorie | wo | Spalten |
|---|---|---|
| Schwimmer | Schwimmer → CSV | Vorname; Nachname; Jahrgang; DSV-ID; Notiz |
| Mannschaften | Mannschaften → CSV (Verwaltung) | Name; Beschreibung; Sortierung |
| Mitglieder | Mannschaft öffnen → Liste aus CSV | Vorname; Nachname; Jahrgang; DSV-ID; Von; Bis |
| Zeiten | Leistungen → CSV | Vorname; Nachname; DSV-ID; Strecke; Lage; Bahnlänge; Zeit; Datum; Wettkampf; Offiziell |
| Sets | Plan → Sets aus CSV | Titel; Wiederholungen; Strecke; Lage; Intervall; Pause; Material; Hinweis; Beschreibung |
| Sets einer Vorlage | Vorlage öffnen → Sets aus CSV | dieselben |

**Zu jeder Kategorie gibt es eine Musterdatei** — ein Knopf im Dialog, immer
da. Darin stehen die Spalten in der erwarteten Reihenfolge und zwei bis drei
Beispielzeilen, die zeigen, wie ein Wert aussieht. Sie wird mit Semikolon,
CRLF und einem BOM geschrieben: Das ist das Format, das ein Doppelklick
unter Windows richtig öffnet, und eine Musterdatei, die beim Öffnen
zerfällt, ist keine Hilfe. Jede Musterdatei geht durch den eigenen Import,
ohne dass eine Zeile als Fehler zurückkommt — das wird geprüft.

Was beim Lesen nicht vorausgesetzt, sondern erkannt wird:

* **Trennzeichen**: Semikolon, Komma oder Tabulator. Gezählt wird in der
  Kopfzeile und ausserhalb von Anführungszeichen — sonst entschiede ein
  Komma in „Musterkind, Mia" über die ganze Datei.
* **Zeichensatz**: UTF-8, oder, wenn das nicht aufgeht, Windows-1252. Das
  ist der Fall „aus Excel gespeichert, ohne nachzudenken"; ohne diese
  Umschrift stünde dort „MÃ¼ller".
* **Spaltennamen**: Gross- und Kleinschreibung, Bindestriche, Leerzeichen
  und Umlaute sind egal. „DSV-ID", „dsv id" und „DSV_Id" sind dieselbe
  Spalte, „Rücken", „Ruecken" und „Rucken" dieselbe Lage.
* **Lagen** auch in Kurzform und auf Englisch: F, Kraul, Freestyle, R, Back,
  B, Breast, S, Butterfly, L, Medley, IM.
* **Daten** deutsch oder ISO, **Zeiten** als `31,40`, `31.40` oder `1:28,10`,
  **Intervalle** als `1:40` oder `100`, **Ja/Nein** als ja, j, x, 1, yes.

Zwei Festlegungen:

1. **Erst prüfen, dann übernehmen.** Der Dialog zeigt zuerst eine Vorschau:
   je Zeile, was passieren würde und warum. Beides läuft durch dieselbe
   Schleife im Server — zwei getrennte Wege würden auseinanderlaufen, und
   dann zeigte die Vorschau etwas anderes, als danach passiert. Eine
   Vorschau schreibt nichts, nicht einmal in die Prüfspur.
2. **Eine fehlerhafte Zeile hält den Rest nicht auf.** Sie wird gemeldet und
   übersprungen. Alles-oder-nichts hiesse bei 200 Zeilen und einem
   Tippfehler: nichts.

Wiedererkannt wird in drei Stufen: **DSV-ID**, sonst **Name und Jahrgang**,
sonst **der Name allein, wenn er eindeutig ist**. Gibt es den Namen zweimal
und steht kein Jahrgang in der Datei, wird das gemeldet statt geraten — „Max
Müller" gibt es in einem Verein zweimal, und dann überschriebe der Import den
falschen. Dieselbe Datei zweimal hochzuladen legt niemanden doppelt an und
verdoppelt keine Zeit.

Die Rechte sind dieselben wie überall: Mannschaften legt die Verwaltung an,
in eine fremde Mannschaft importiert niemand, Zeiten nur für die eigenen
Schwimmer, Sets nur in den eigenen Plan. Der Import ist kein zweiter,
schwächerer Weg an die Daten, sondern dieselben Funktionen in einer
Schleife.

## Datenschutz und Sicherheit

* **Jeder Endpunkt prüft serverseitig**, nicht über ausgeblendete
  Schaltflächen. Wer eine fremde Plan- oder Schwimmernummer von Hand aufruft,
  bekommt 403 und keine Daten — auch über den Download-Weg nicht, auch nicht,
  wenn er die Nummer mit erlaubten zusammen in einem Aufruf schickt.
* **Eine einzige Frage entscheidet über jeden Schwimmer:**
  `LSV07P_Schwimmer::darf()` — ist er in einer Mannschaft dieses Trainers?
  Sie steht vor jeder Liste, jeder Zeit und jedem PDF, das seinen Namen
  trägt. Eine zweite Stelle, die dieselbe Frage anders beantwortet, gäbe es
  sonst früher oder später.
* Fehlt die Berechtigung, lautet die Antwort gleich, ob es die Nummer gibt
  oder nicht. Sonst liesse sich durch Probieren herausfinden, welche
  Plannummern es gibt.
* **Prüfspur** über alles, was Rechte oder Sichtbarkeit ändert: Rollen,
  Schwimmer, Mannschaften, Mitgliedschaften, Zuordnungen, Zeiten,
  Einstellungen. Die
  Adresse steht nur als Hash darin. Einsehbar unter Konten, Aufbewahrung
  **180 Tage**, danach läuft sie täglich ab. Von jedem Plan bleiben die
  letzten **20** Fassungen.
* Seite und Downloads gehen mit `no-store` heraus, dazu `X-Frame-Options:
  DENY`, `Referrer-Policy: no-referrer`, `nosniff`, `noindex` und eine
  `Permissions-Policy` ohne Kamera, Mikrofon und Standort.
* Eine **Inhaltsrichtlinie (CSP)** in zwei Stufen (`lsv07p_csp_streng = 1`
  für die strenge); die strenge sperrt fremde Skriptquellen aus und kann mit
  manchen Themes kollidieren — darum ein Schalter und kein stiller Zwang.
* **HSTS nur auf ausdrücklichen Wunsch** (`lsv07p_hsts = 1`): Es gilt für die
  ganze Domain und ist nicht zurückzunehmen — bei einem abgelaufenen
  Zertifikat kommt ein Jahr lang niemand mehr auf die Vereinsseite. Diese
  Entscheidung gehört dem, der den Server betreibt.
* **Angehängte PDFs liegen im gesperrten Ordner** und kommen nur über den
  Download-Endpunkt heraus; sie gehen mit `Content-Disposition: attachment`
  und `sandbox` hinaus, damit nichts davon im Browser ausgeführt wird.
* **Eine hochgeladene CSV wird am Inhalt geprüft, nicht an der Endung.** Ein
  Nullbyte darin heisst: keine Textdatei, abgelehnt. Grenzen: 2 MB und 2000
  Zeilen; darüber ist es kein Import mehr.
* Die Schriften liegen **im Plugin**, nicht bei einem Schriftdienst.

## Aufbau

```
lsv07-swimplan.php          Plugin-Kopf, Tabellen, init, tägliches Aufräumen
includes/
  class-db.php              die vierzehn Tabellen, Umzug, Prüfspur, Fristen
  class-rollen.php          wer ist wer — zwei Rollen, nichts abgeleitet
  class-schwimmer.php       Schwimmer ohne Konto — und darf(), die eine Frage
  class-mannschaft.php      Mannschaften, Trainer, Mitgliedschaft mit Datum
  class-rechnung.php        Lagen, Meter und Dauer — die eine Rechnung
  class-plan.php            Einheit, Sets, Zustände, Zuordnung, Fassungen
  class-vorlage.php         Bibliothek — Kopie statt Verweis
  class-zeiten.php          Zeiten in Hundertsteln, Bestzeit ermittelt
  class-dateien.php         der Plan als PDF, geschützt abgelegt
  class-pdf.php             kleiner PDF-Schreiber (ohne Fremdbibliothek)
  class-bericht.php         die Berichte darüber, samt Aushang
  class-csv.php             CSV lesen und schreiben — Trenner, Umlaute, BOM
  class-import.php          der Import je Kategorie, erst trocken
  class-ajax-verwaltung.php Konten, Rollen, Schwimmer, Mannschaften
  class-ajax-plan.php       Pläne, Sets, Zuordnung, Bibliothek, Zeiten
  class-ajax-buehne.php     die Bühne: Vollbild, Splitscreen, Größe
  class-ajax-download.php   die PDFs und die Musterdateien
  class-ajax-import.php     prüfen und übernehmen
  class-shortcode.php       Vorlagen-Weiche, Assets, Kopfzeilen, Startdaten
templates/app.php           die ganze Oberfläche
templates/vollbild.php      die eigene Seitenvorlage (ohne Theme)
assets/                     CSS, JS, Zeichen, selbst gehostete Schriften
```

## Verhältnis zum Konzept

Das technische Konzept nennt als Stack Laravel und MariaDB. Gebaut ist
SwimPlan als **WordPress-Plugin** — aus einem Grund: Die Schwesterplugins des
Vereins laufen dort, die Konten liegen dort, und ein zweiter Unterbau neben
WordPress hiesse zweite Anmeldung, zweites Rechtesystem und zweiter
Serverbetrieb. Alles Fachliche des Konzepts ist übernommen: die
Tabellen und ihre Beziehungen, die Zuordnung Trainer–Mannschaft, die
Mitgliedschaft mit Start und Ende, die Kopie beim Einfügen aus der
Bibliothek, Zeiten als Hundertstel, die ermittelte Bestleistung, die
Zustände mit Versionierung, die Meter- und Dauerrechnung und der
PDF-Dateiname.

Eine Festlegung des Konzepts ist bewusst **nicht** übernommen: Dort ist der
Schwimmer eine der drei Rollen und hat damit einen Zugang. Hier ist er ein
Datensatz ohne Konto (siehe oben). Der fachliche Inhalt bleibt derselbe —
Name, Jahrgang, DSV-ID, Mannschaft, Zeiten —, nur der Weg in das System
fehlt, und das ist der Teil, der Risiko trägt statt Nutzen.

Was im Konzept ausdrücklich ausserhalb des MVP steht, ist auch hier nicht
enthalten: Benachrichtigungen, App, Videoanalyse, Wettkampfverwaltung.

## Umzug

Beim Aktualisieren läuft alles Nötige von selbst (`LSV07P_DB::umziehen()`),
und es ist mehrfach ausführbar, ohne etwas zu verdoppeln. `CREATE TABLE IF
NOT EXISTS` rührt eine bestehende Tabelle nicht an — neue Spalten kommen
darum eigens dazu, bei jedem Start:

* **nach 1.4.0:** die Spalte `beschreibung` an `lsv07p_set` und
  `lsv07p_vorlage_set` (die Übung je Wiederholung). Bestehende Sets bekommen
  sie leer und bleiben sonst unberührt.

### Von 1.2.0: Schwimmer waren Konten

Bis 1.2.0 war ein Schwimmer ein WordPress-Konto:

1. Mitgliedschaften, Zeiten und Zuordnungen bekommen die Spalte
   `schwimmer_id`. Die alte `wp_user_id` **bleibt stehen** — sie stört
   nicht, und solange sie da ist, lässt sich der Umzug nachvollziehen.
2. Zu jedem Konto, das als Schwimmer gemeint war, entsteht ein Datensatz.
   Der Name kommt aus dem Anzeigenamen von WordPress (der letzte Teil wird
   zum Nachnamen), die alte Kontonummer bleibt in `aus_konto` stehen.
3. Die Personenzeilen mit der Rolle `swimmer` werden **gelöscht**: Sie
   führen zu keinem Zugang mehr, und eine Zeile, die niemandem etwas
   erlaubt, wäre nur eine Namensliste in der Verwaltung.

**Die WordPress-Konten selbst rührt SwimPlan nicht an.** Wer sie nicht mehr
braucht, löscht sie in WordPress — das ist eine Entscheidung des Vereins und
keine, die ein Plugin treffen sollte.

Nachzusehen und zu prüfen: Namen und Jahrgänge der übernommenen Schwimmer
unter **Schwimmer**. Der Jahrgang stand in 1.2.0 nirgends und ist darum
leer; er lässt sich dort nachtragen.

## Geprüft

* **793 Prüfungen gegen echtes SQL** (`sptest.php`) — mit den echten
  CREATE-Texten des Plugins, nicht mit einer Abschrift
* **211 zum CSV-Import** (`spcsv.php`) — mit den Dateien, die wirklich
  ankommen: deutsches Excel (Semikolon, Windows-1252, BOM), englisches
  (Komma), Vereinssoftware (Tabulator), Kommas in Namen, umbrochene Felder.
  Dazu: jede Musterdatei geht durch ihren eigenen Import, und die Vorschau
  stimmt mit dem überein, was danach passiert
* **60 Prüfungen zum Umzug** (`spumzug.php`) — gegen die echten CREATE-Texte
  der Fassung 1.2.0, aus dem Git-Stand entnommen: keine Zeile geht verloren,
  jede hängt danach am richtigen Schwimmer, und ein zweiter Lauf ändert
  nichts
* **309 im Browser** (`spui.js`), samt dem Fokus auf der Bühne, dem Bleiben
  in der Bearbeitung und dem Kontrast jedes Knopfes
* **150 auf 320, 390 und 768 px** (`sphandy.js`), samt Bühne, Fokus und dem
  Feld zum Hochladen
* **68 Messungen zum Vollbild** (`spvollbild.js`) auf 1920, 1280 und 390 px:
  kein Rand, kein Polster, kein Rahmen, volle Breite — auch dann, wenn ein
  fremdes `!important` dazwischenfunkt
* **Messung der fertigen PDFs** (`svpdfmasse.py`): keine Trennlinie läuft
  durch Text

Zu jeder Schutzmaßnahme wurde gegengeprüft, dass die Prüfung den Fehler auch
findet. **Neunundzwanzig** Vorkehrungen wurden dafür absichtlich ausgebaut — die
Zuordnung im Plan, die Mitgliedschaft zum Plandatum, die Abschaltung eines
Kontos, die Ermittlung der Bestzeit, die Rechteprüfung im PDF-Bericht und
die im Datei-Download, die Typprüfung der hochgeladenen Datei, das
Mitlöschen der Dateien, die Regel zum WordPress-Administrator, das Abräumen
der Theme-Stile, der harte Rücksetzer, seine Bindung an den
Vollbildschalter, die scrollbare Tabelle, der Verzicht auf Splitscreen am
Telefon, die gestapelte Kennzahl, die eine Frage
`LSV07P_Schwimmer::darf()`, das Löschen der Schwimmerkonten beim Umzug, das
Nachtragen der Nummern beim Umzug, die Zurückhaltung der Zuordnungsliste im
Aushang, das gezeichnete Kästchen, der ganze Block der Oberfläche, die
Rechteprüfung beim Set in die Bibliothek, der Umbruch im PDF, das Nachrüsten
der Spalte, die trockene Vorschau des Imports, das Melden eines mehrdeutigen
Namens, die Erkennung von Windows-1252, das Erkennen doppelter Zeiten und
der Kontrast des Knopfes „Abbrechen" — und alle neunundzwanzig wurden
gemeldet.

### Drei davon haben echte Fehler gefunden

* **Ein ganzer Block der Oberfläche war verloren gegangen** (`planZeile`,
  `plaeneLaden`, `planZeichnen`) — syntaktisch einwandfrei, von
  `node --check` nicht zu finden, und alle anderen Prüfungen blieben grün.
  Seitdem prüft `sptest.php`, dass **jede Funktion, die die Oberfläche
  aufruft, auch definiert ist**.
* **Die Kästchen im Aushang standen als Fragezeichen im PDF** — das Zeichen
  `□` gibt es in Helvetica mit cp1252 nicht. Zu sehen war das nur im
  gerenderten PDF; seitdem prüft die Suite den fertigen Inhaltsstrom auf
  ersetzte Zeichen.
* **„Abbrechen" war in jedem Dialog unsichtbar** — weiss auf weiss, seit der
  ersten Fassung. Die Regel war für die dunkle Kopfleiste gedacht und galt
  überall. Zu sehen war das in einem Abbild; seitdem misst `spui.js` den
  Kontrast **jedes** Knopfes gegen den Grund, auf dem er wirklich steht.
