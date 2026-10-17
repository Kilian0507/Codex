# LSV07 SwimView — 1.0.0

Einblick für Schwimmerinnen und Schwimmer in ihre eigenen Daten aus dem
internen Bereich. Eigenes Plugin, läuft parallel zu `lsv07-intern` und
`lsv07-abrechnung` und liest deren Daten — es führt nichts doppelt.

## Einrichten

1. Plugin aktivieren. Die eigenen Tabellen legt es selbst an.
2. Eine Seite anlegen und den Shortcode `[swimview]` hineinsetzen
   (`[lsv07_swimview]` geht auch). Die Seite wird zur Anwendung: Theme-Kopf,
   Navigation und Fusszeile verschwinden.
3. Als Trainerin oder Trainer anmelden, auf der Seite **Zum Trainerportal**
   drücken und für jede Mannschaft ankreuzen, was die Kinder sehen dürfen.
   **Von sich aus ist nichts sichtbar.**

Die Seite trägt `noindex, nofollow` — sie gehört in keinen Suchindex.

## Anmeldung der Kinder

DSV-ID und Geburtsdatum. Beides ist kein Passwort: Eine DSV-ID steht in
jedem Meldeergebnis, ein Geburtsdatum weiss im Verein fast jeder. Darum:

* Sichtbar ist nur, was ausdrücklich freigegeben wurde — und nie etwas über
  ein anderes Kind.
* Nach acht Fehlversuchen ist die Adresse eine Viertelstunde gesperrt.
* Die Rückmeldung verrät nie, WELCHE der beiden Angaben falsch war.
* Die Anmeldung läuft nach acht Stunden Ruhe ab, spätestens nach 30 Tagen.
* Wer aus dem Verein ausgetragen ist, kommt auch mit gültigem Cookie nicht
  mehr hinein.

Im Cookie stehen ein Wähler und ein Geheimnis; gespeichert wird vom
Geheimnis nur der Hash.

## Was die Kinder sehen können

Jeder Bereich einzeln freizugeben, je Mannschaft:

| Bereich | Quelle im internen Bereich |
|---|---|
| Anwesenheiten | Mannschaftsbuch, mit Verspätung und Grund |
| Vermerke der Mannschaft | Mannschaftsbuch, **nur** `schwimmer_id = 0` |
| Aufgaben | Mannschaftsbuch, eigene und die der Mannschaft |
| Dokumentation | Mannschaftsbuch |
| Wettkämpfe | nur `freigegeben = 1`, bis 10 Tage nach dem letzten Tag |
| Bestzeiten | `lsv07i_bestzeiten` |
| Reflexionsbogen | `lsv07i_refl_bogen` der laufenden Saison, zum Ausfüllen |
| Trainingsplan | nur Pläne, die für die Mannschaft freigegeben sind |
| Nachrichten | von SwimView selbst, samt Dateien |

Zu jedem Bereich gibt es den Knopf **Als PDF**. Die Berichte entstehen auf
dem Server und durchlaufen dieselbe Freigabeprüfung wie die Ansicht.

## Trainerportal

Über das WordPress-Konto. Wer im internen Bereich als Trainer geführt wird,
verwaltet seine Mannschaften; Verwaltung und Schwimmwart alle.

* **Häkchen je Mannschaft.** Gespeichert wird je Mannschaft, nicht je
  Trainer — zwei Trainer derselben Mannschaft ändern dieselbe Zeile. Es gibt
  nichts abzugleichen, weil es nur eine Einstellung gibt.
* **Trainingspläne einzeln freigeben.** Nur die eigenen. Das `freigegeben`
  des internen Bereichs heisst „andere Trainer dürfen den Plan sehen" und
  gilt hier ausdrücklich nicht als Freigabe an Kinder.
* **Nachrichten** an die ganze Mannschaft oder an einzelne Kinder, mit
  Dateien (PDF, JPG, PNG, WEBP bis 10 MB). An der Nachricht steht, wie viele
  sie gelesen haben.

## Aufbau

```
lsv07-swimview.php          Plugin-Kopf, Tabellen, init
includes/
  class-db.php              eigene Tabellen
  class-intern.php          die EINZIGE Stelle, die lsv07-intern liest
  class-freigabe.php        welche Mannschaft was sehen darf
  class-login.php           DSV-ID + Geburtsdatum, Sitzung, Sperre, Token
  class-trainer.php         wer im Portal was darf
  class-dateien.php         Anhänge, geschützt abgelegt
  class-daten.php           die Daten selbst — prüft die Freigabe mit
  class-pdf.php             kleiner PDF-Schreiber (ohne Fremdbibliothek)
  class-bericht.php         die Berichte darüber
  class-ajax-schwimmer.php  Endpunkte der Kinder (nopriv + eigenes Token)
  class-ajax-trainer.php    Endpunkte des Portals (WordPress-Nonce)
  class-ajax-download.php   PDFs und Dateien
  class-shortcode.php       Vollbild, Assets, Startdaten
templates/app.php           die ganze Oberfläche
assets/                     CSS, JS, Logo, selbst gehostete Schriften
```

Der einzige schreibende Zugriff auf den internen Bereich ist der
Reflexionsbogen — und der geht in genau dieselben Zeilen, die dessen eigener
Weg füllt.

## Geprüft

* 256 Prüfungen gegen echtes SQL (`svtest.php`)
* 137 im Browser (`svui.js`)
* 157 auf 320, 390 und 768 px (`svhandy.js`)
* Messung der fertigen PDFs (`svpdfmasse.py`)
