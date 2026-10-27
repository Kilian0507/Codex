# LSV07 SwimView — 1.3.0

Einblick für Schwimmerinnen und Schwimmer in ihre eigenen Daten aus dem
internen Bereich. Eigenes Plugin, läuft parallel zu `lsv07-intern` und
`lsv07-abrechnung` und liest deren Daten — es führt nichts doppelt.

## Einrichten

1. Plugin aktivieren. Die eigenen Tabellen legt es selbst an.
2. Eine Seite anlegen und den Shortcode `[swimview]` hineinsetzen
   (`[lsv07_swimview]` geht auch). **Diese Seite wird zur Anwendung:** Sie
   wird mit einer eigenen Vorlage ausgeliefert, das Theme steuert kein Markup
   bei — kein Kopf, keine Navigation, keine Seitenleiste, keine Fusszeile.
   Jede andere Seite bleibt unberührt.
3. Als Trainerin oder Trainer anmelden, auf der Seite **Zum Trainerportal**
   drücken und für jede Mannschaft ankreuzen, was die Kinder sehen dürfen.
   **Von sich aus ist nichts sichtbar.**

Die Seite trägt `noindex, nofollow` — sie gehört in keinen Suchindex.

## Anmeldung der Kinder

**DSV-ID, Geburtsdatum und Zugangscode.** Die ersten beiden sind kein
Passwort: Eine DSV-ID steht in jedem Meldeergebnis, ein Geburtsdatum weiss im
Verein fast jeder. Erst der Code macht daraus eine Anmeldung.

Den Code erzeugt die Trainerin oder der Trainer im Portal; er ist **genau
einmal im Klartext zu sehen** und liegt danach nur noch als Hash in der
Datenbank — auch für die Verwaltung. Vier Blöcke zu vier Zeichen, ohne
verwechselbare Zeichen. Weitergeben persönlich oder auf Papier, nicht per Mail
und nicht im Gruppenchat.

Die Verwaltung kann den Code auf „nur wo einer hinterlegt ist" oder „aus"
stellen. **Voreinstellung ist Pflicht** — ein Plugin, das Daten über Kinder
zeigt, wird nicht mit offenen Türen ausgeliefert.

Weiter gilt:

* Sichtbar ist nur, was ausdrücklich freigegeben wurde — und nie etwas über
  ein anderes Kind.
* **Nur über HTTPS.** Ohne verschlüsselte Verbindung ist die Anmeldung
  gesperrt (Ausnahme: `localhost`).
* Gesperrt wird **zweifach**: je Adresse (8 Versuche / 15 Min.) und je Konto
  (12 / 10 Min.). Nur je Adresse zu zählen hilft wenig — Adressen sind billig.
* Es wird nie gesagt, WELCHE der drei Angaben falsch war, und die Meldung
  hängt allein an der vereinsweiten Einstellung, nie am einzelnen Konto.
* Auch für eine unbekannte DSV-ID wird gerechnet — sonst verriete die
  Antwortzeit, welche Nummer es gibt.
* Die Anmeldung läuft nach **2 Stunden** Ruhe ab, spätestens nach **7 Tagen**.
* Wer aus dem Verein ausgetragen ist, kommt auch mit gültigem Cookie nicht
  mehr hinein.

### Das Sitzungs-Cookie

Darin stehen ein Wähler und ein Geheimnis; gespeichert wird vom Geheimnis nur
der Hash. Es ist `HttpOnly`, `Secure`, `SameSite=Strict`, trägt über HTTPS das
Präfix `__Host-` und **endet mit dem Browser** — wie lange eine Sitzung gilt,
entscheidet allein der Server.

Das Geheimnis **wechselt alle 15 Minuten**. Das vorige gilt noch, bis das neue
benutzt wurde (sonst verlören gleichzeitige Abfragen die Anmeldung). Ein
zweimal überholtes Geheimnis beendet die Sitzung und steht in der Prüfspur.
Im Portal gibt es je Kind **„Überall abmelden"**.

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

## Datenschutz

* **Prüfspur**: Anmeldungen, Fehlversuche, Sperren und Code-Änderungen. Die
  Adresse steht nur als Hash darin, Eingaben gar nicht. Im Portal unter „Was
  zuletzt passiert ist".
* **Fristen laufen von selbst ab** (täglich): Prüfspur und Lesemarken nach 90
  Tagen, Sperrzähler nach einem Tag, abgelaufene Sitzungen, Nachrichten nach
  der eingestellten Aufbewahrung (Voreinstellung ein Jahr).
* **Löschweg je Kind** („Daten löschen"): Code, Sitzungen, Lesemarken,
  Prüfspur, Sperrzähler und die Nachrichten, die nur an dieses Kind gingen,
  samt Dateien. Mannschaftsnachrichten bleiben. Die Daten im internen
  Bereich sind unberührt — die gehören nicht SwimView.
* Seite, Antworten und Downloads gehen mit `no-store` heraus, dazu
  `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `nosniff`,
  `noindex` und eine `Permissions-Policy` ohne Kamera, Mikrofon und Standort.
* Eine **Inhaltsrichtlinie (CSP)** in zwei Stufen; die strenge ist ein
  Schalter, weil sie mit manchen Themes kollidiert.
* **HSTS nur auf ausdrücklichen Wunsch**: Es gilt für die ganze Domain und ist
  nicht zurückzunehmen — bei einem abgelaufenen Zertifikat kommt ein Jahr lang
  niemand mehr auf die Vereinsseite. Diese Entscheidung gehört dem, der den
  Server betreibt.

### Auskunft und Löschung (Art. 15 und 17 DSGVO)

SwimView hängt sich in die Werkzeuge von WordPress ein: **Werkzeuge →
Personenbezogene Daten exportieren** bzw. **→ löschen**, dazu ein
Vorschlagstext unter **Einstellungen → Datenschutz**.

**Die Liste ist kurz, und das ist Absicht.** SwimView ist ein Schaufenster:
Schwimmer, Mannschaften, Anwesenheiten, Bestzeiten und Trainingspläne
gehören dem internen Bereich und werden von dort nur gelesen. Über sie gibt
*der* Auskunft, und dort werden sie auch gelöscht. Ein zweiter Exporter, der
dieselben Daten noch einmal ausgibt, machte die Auskunft doppelt lang und
nicht vollständiger — und eine zweite Löschung risse dem anderen Plugin den
Boden weg.

In der Auskunft stehen deshalb nur SwimViews eigene Daten: erhaltene
Nachrichten samt Anhängen, Lesemarken, angemeldete Geräte, dass ein
Zugangscode gesetzt ist, die gezählten Fehlversuche und die Prüfspur.
**Geheimnisse nicht** — Sitzungs-Hash, Code-Hash, Selektor und IP-Hash
sind Zugangsmittel, keine Auskunft; in einer Exportdatei wären sie ein
Risiko ohne Nutzen.

Schwimmer werden über die **E-Mail-Adresse in ihrem Eintrag im internen
Bereich** gefunden — hier haben sie kein WordPress-Konto. Steht dort keine
Adresse, ist über sie auch nichts zuzuordnen; das ist die Folge davon, dass
sie nie erhoben wurde.

**Der Löschweg ist derselbe wie hinter „Daten löschen" im Trainerportal** —
eine Funktion, zwei Anlässe. Zwei Listen desselben Vorgangs würden
auseinanderlaufen, und der Unterschied fiele niemandem auf: Es bliebe eine
Zeile stehen, die keiner mehr ansieht. Aufzubewahren gibt es hier nichts;
SwimView führt keine Bücher.

## Vollbild: nur die Anwendung

Die SwimView-Seite läuft über `templates/vollbild.php` statt über die Vorlage
des Themes. `get_header()` und `get_footer()` werden nicht aufgerufen — die
Leisten des Themes werden also nicht versteckt, sondern gar nicht erst
erzeugt. Das ist der Unterschied zum vorherigen Weg über eine Liste bekannter
Klassennamen, der bei jedem unbekannten Theme stillschweigend versagte.

`wp_head()` und `wp_footer()` bleiben: Daran hängen die Stilvorlage und das
Skript von SwimView.

Dazu kommen drei Dinge, ohne die trotzdem ein Rahmen übrig bliebe:

* **Die Stilvorlagen des Themes werden nicht geladen.** Kein Theme-Markup
  heisst nicht automatisch kein Theme-CSS — und darauf hatte sich diese
  Beschreibung bis 1.2.0 verlassen. Viele Themes setzen Polster auf `body`,
  Blockthemen schicken über `global-styles` Randabstände und eine
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

**Was dadurch nicht verschwindet:** ein Cookie-Banner oder Chat-Widget, das
sich über `wp_footer` einhängt. Solche Werkzeuge haben in der Regel eine
Einstellung, auf welchen Seiten sie erscheinen — dort die SwimView-Seite
ausnehmen. (`wp_body_open` wird nicht aufgerufen.)

Abschaltbar: `swimview_vollbild = 0` liefert die Seite wieder über das Theme
aus (dann greifen auch der Rücksetzer und das Abräumen nicht);
`swimview_vollbild_rein = 0` behält das Vollbild, lässt aber die Stilvorlagen
des Themes stehen.

## Aufbau

```
lsv07-swimview.php          Plugin-Kopf, Tabellen, init
includes/
  class-db.php              eigene Tabellen
  class-intern.php          die EINZIGE Stelle, die lsv07-intern liest
  class-freigabe.php        welche Mannschaft was sehen darf
  class-code.php            der Zugangscode — erzeugen, pruefen, Stufen
  class-login.php           die drei Faktoren, Sitzung, Sperre, Token
  class-trainer.php         wer im Portal was darf
  class-dateien.php         Anhänge, geschützt abgelegt
  class-daten.php           die Daten selbst — prüft die Freigabe mit
  class-pdf.php             kleiner PDF-Schreiber (ohne Fremdbibliothek)
  class-bericht.php         die Berichte darüber
  class-ajax-schwimmer.php  Endpunkte der Kinder (nopriv + eigenes Token)
  class-ajax-trainer.php    Endpunkte des Portals (WordPress-Nonce)
  class-ajax-download.php   PDFs und Dateien
  class-shortcode.php       Vorlagen-Weiche, Theme-Stile abräumen, Kopfzeilen
templates/app.php           die ganze Oberfläche
templates/vollbild.php      die eigene Seitenvorlage (ohne Theme)
assets/                     CSS, JS, Logo, selbst gehostete Schriften
```

Der einzige schreibende Zugriff auf den internen Bereich ist der
Reflexionsbogen — und der geht in genau dieselben Zeilen, die dessen eigener
Weg füllt.

## Geprüft

* 384 Prüfungen gegen echtes SQL (`svtest.php`)
* 166 im Browser (`svui.js`)
* 178 auf 320, 390 und 768 px (`svhandy.js`)
* 59 zum Vollbildmodus (`svvollbild.js`), geprüft an einem fremden Theme —
  davon 38 Messungen auf 1920, 1280 und 390 px: kein Rand, kein Polster,
  kein Rahmen, volle Breite, auch gegen ein fremdes `!important`
* Messung der fertigen PDFs (`svpdfmasse.py`)

Zu jeder Schutzmaßnahme wurde gegengeprüft, dass die Prüfung den Fehler auch
findet: Sieben davon wurden absichtlich ausgebaut — darunter das Abräumen der
Theme-Stile und der harte Rücksetzer —, und alle sieben wurden gemeldet.
