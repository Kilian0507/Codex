# LSV07 SwimPlan — 1.0.0

Trainingsplanung und Leistungsverwaltung für Schwimmvereine. Trainer bauen
Trainingseinheiten aus Sets, geben sie für Mannschaften oder einzelne
Schwimmer frei und erfassen Zeiten; Schwimmer sehen ihren Plan und ihre
Bestleistungen. Eigenes Plugin, unabhängig von `lsv07-intern`,
`lsv07-abrechnung` und `lsv07-swimview` — es liest keine Tabelle eines
anderen Plugins und führt alles selbst.

**Zugang ausschliesslich für angemeldete WordPress-Konten.**

## Einrichten

1. Plugin aktivieren. Die eigenen Tabellen legt es selbst an.
2. Eine Seite anlegen und den Shortcode `[swimplan]` hineinsetzen
   (`[lsv07_swimplan]` geht auch). **Diese Seite wird zur Anwendung:** Sie
   wird mit einer eigenen Vorlage ausgeliefert, das Theme steuert kein Markup
   bei — kein Kopf, keine Navigation, keine Seitenleiste, keine Fusszeile.
   Jede andere Seite bleibt unberührt.
3. Das erste Verwaltungskonto von Hand eintragen (siehe unten), danach geht
   alles über die Oberfläche: Konten, Mannschaften, Trainer.

Die Seite trägt `noindex, nofollow` — sie gehört in keinen Suchindex.

## Wer hineinkommt

SwimPlan führt **keine eigene Anmeldung**. Passwörter, Sitzungen,
Zurücksetzen und, wenn der Verein mag, Zwei-Faktor sind damit Sache von
WordPress. Was SwimPlan führt, ist die **Rolle** eines Kontos:

| Rolle | darf |
|---|---|
| `admin` — Verwaltung | Konten, Rollen, Mannschaften, Trainerzuteilung, Einstellungen, alle Pläne |
| `coach` — Trainer | die eigenen Mannschaften und Pläne, Mitgliedschaften seiner Mannschaften, Zeiten seiner Schwimmer |
| `swimmer` — Schwimmer | die eigenen freigegebenen Pläne und die eigenen Zeiten — lesend |

**Ein Konto ohne Eintrag kommt nicht hinein.** Auch der Administrator der
WordPress-Seite nicht, solange er sich nicht selbst einträgt. Es wäre
bequemer gewesen, `manage_options` als Verwaltungsrolle zu lesen — dann
hätte aber jeder, der aus irgendeinem Grund WordPress-Administrator ist,
Zugriff auf die Trainingsdaten von Kindern. Wer sie sehen soll, wird benannt.

Das erste Verwaltungskonto trägt man einmalig in der Datenbank ein:

```sql
INSERT INTO wp_lsv07p_person (wp_user_id, rolle, aktiv)
VALUES (<Konto-Nummer>, 'admin', 1);
```

Wer den Zugang verliert, wird **abgeschaltet und nicht gelöscht**: Pläne,
Mitgliedschaften und Zeiten bleiben erhalten, und ein gelöschter Eintrag
liesse sie verwaisen. Ein abgeschaltetes Konto kommt beim nächsten Aufruf
nicht mehr hinein.

## Die Trainingseinheit

Ein Plan besteht aus Sets. Ein Set ist, was ein Trainer aufschreibt:
**8 × 50 m Rücken auf 1:00**, dazu Material und ein Hinweis. Daraus rechnet
SwimPlan die Zahlen, die er sonst im Kopf rechnet.

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

* **Ein Plan ohne Sets lässt sich nicht veröffentlichen.** Ein leerer Plan
  bei den Schwimmern ist schlimmer als kein Plan.
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
| Trainer | die eigenen und die für eine seiner Mannschaften freigegebenen | nur die eigenen |
| Schwimmer | nur die für ihn freigegebenen, und nur veröffentlichte | nichts |

Wer den Plan eines Kollegen weiterverwenden will, **kopiert** ihn; dann hat
er einen eigenen und ändert nicht den, auf den sich eine andere Mannschaft
schon eingerichtet hat.

Drei Festlegungen zur Freigabe:

1. **Veröffentlicht heisst noch nicht sichtbar.** Sichtbar ist ein Plan für
   den, der in der Freigabe steht. Ein Plan ohne Freigabe sieht niemand
   ausser seinem Trainer — die Oberfläche sagt das beim Veröffentlichen.
2. **Eine Freigabe geht an eine Mannschaft ODER an eine Person**, nie an
   beides in einer Zeile.
3. **Die Mannschaftsfreigabe gilt zum Datum des Plans.** Wer im Juni die
   Mannschaft verlassen hat, sieht die Pläne bis Juni weiter und die von Juli
   nicht mehr. Wer heute aufgenommen wird, sieht den Plan von nächster Woche.
   Die Prüfung fragt also nicht „ist Mitglied", sondern „war Mitglied an
   diesem Tag".

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
* **Erfassen dürfen Trainer und Verwaltung.** Ein Schwimmer sieht seine
  Zeiten und ändert sie nicht — auch nicht die eigenen. Sonst wäre eine
  Bestzeit kein Ergebnis mehr, sondern eine Behauptung.
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
* **PDF** — der Plan oder alle Pläne der Bühne in einer Datei.

Die Einstellung liegt **am WordPress-Konto**, nicht im Browser: Wer das
Vereinstablet benutzt, findet seine Ansicht wieder, und wer sich abmeldet,
lässt sie dort nicht zurück.

**Auf dem Telefon** (bis 760 px) stehen die Pläne untereinander — zwei
Spalten auf 390 px sind zwei unlesbare Spalten. Die Spaltenwahl ist dort
ausgeblendet, weil sie nichts bewirken würde; die Einstellung bleibt
erhalten und gilt wieder am Tablet.

## PDF

Zu jeder Ansicht einen Bericht: Plan (für Trainer und für Schwimmer),
mehrere Pläne in einer Datei, Bestzeiten, Verlauf, Mannschaft, Rangliste.

* Die Berichte entstehen **auf dem Server** und durchlaufen dieselbe
  Rechteprüfung wie die Ansicht. Ein PDF kann nie mehr enthalten als der
  Bildschirm — das PDF eines Kindes enthält die Notizen des Trainers nicht.
* Dateiname nach dem Beispiel aus dem Konzept:
  `C-Mannschaft_2026-10-19.pdf`.
* Geschrieben wird ohne Fremdbibliothek, mit den beiden Standardschriften,
  die jedes Anzeigeprogramm mitbringt. Die Dateien bleiben winzig, und es
  geht nichts an eine fremde Schriftquelle hinaus.

## Datenschutz und Sicherheit

* **Jeder Endpunkt prüft serverseitig**, nicht über ausgeblendete
  Schaltflächen. Ein Schwimmer, der eine fremde Plan- oder Profilnummer von
  Hand aufruft, bekommt 403 und keine Daten — auch über den Download-Weg
  nicht, auch nicht, wenn er die Nummer mit erlaubten zusammen in einem
  Aufruf schickt.
* Fehlt die Berechtigung, lautet die Antwort gleich, ob es die Nummer gibt
  oder nicht. Sonst liesse sich durch Probieren herausfinden, welche
  Plannummern es gibt.
* **Prüfspur** über alles, was Rechte oder Sichtbarkeit ändert: Rollen,
  Mannschaften, Mitgliedschaften, Freigaben, Zeiten, Einstellungen. Die
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
* Die Schriften liegen **im Plugin**, nicht bei einem Schriftdienst.

## Aufbau

```
lsv07-swimplan.php          Plugin-Kopf, Tabellen, init, tägliches Aufräumen
includes/
  class-db.php              die zwölf Tabellen, Prüfspur, Fristen
  class-rollen.php          wer ist wer — und darf_person(), die eine Frage
  class-mannschaft.php      Mannschaften, Trainer, Mitgliedschaft mit Datum
  class-rechnung.php        Lagen, Meter und Dauer — die eine Rechnung
  class-plan.php            Einheit, Sets, Zustände, Freigabe, Fassungen
  class-vorlage.php         Bibliothek — Kopie statt Verweis
  class-zeiten.php          Zeiten in Hundertsteln, Bestzeit ermittelt
  class-pdf.php             kleiner PDF-Schreiber (ohne Fremdbibliothek)
  class-bericht.php         die Berichte darüber
  class-ajax-verwaltung.php Konten, Rollen, Mannschaften
  class-ajax-plan.php       Pläne, Sets, Bibliothek, Zeiten
  class-ajax-portal.php     Schwimmerportal und die Bühne
  class-ajax-download.php   die PDFs
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
Serverbetrieb. Alles Fachliche des Konzepts ist übernommen: die drei Rollen,
die Tabellen und ihre Beziehungen, die Zuordnung Trainer–Mannschaft, die
Mitgliedschaft mit Start und Ende, die Kopie beim Einfügen aus der
Bibliothek, Zeiten als Hundertstel, die ermittelte Bestleistung, die
Zustände mit Versionierung, die Meter- und Dauerrechnung und der
PDF-Dateiname.

Was im Konzept ausdrücklich ausserhalb des MVP steht, ist auch hier nicht
enthalten: Benachrichtigungen, App, Videoanalyse, Wettkampfverwaltung.

## Geprüft

* **616 Prüfungen gegen echtes SQL** (`sptest.php`) — mit den echten
  CREATE-Texten des Plugins, nicht mit einer Abschrift
* **152 im Browser** (`spui.js`)
* **128 auf 320, 390 und 768 px** (`sphandy.js`), samt Bühne
* **Messung der fertigen PDFs** (`svpdfmasse.py`): keine Trennlinie läuft
  durch Text

Zu jeder Schutzmaßnahme wurde gegengeprüft, dass die Prüfung den Fehler auch
findet. Acht Vorkehrungen wurden dafür absichtlich ausgebaut — die Freigabe
im Plan, die Mitgliedschaft zum Plandatum, die Abschaltung eines Kontos, die
Ermittlung der Bestzeit, die Freigabeprüfung im PDF-Download, die scrollbare
Tabelle, der Verzicht auf Splitscreen am Telefon und die gestapelte
Kennzahl — und alle acht wurden gemeldet.
