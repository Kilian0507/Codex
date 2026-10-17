# LSV07 SwimView — 1.2.0

## Vollbild, und zwar verlässlich

SwimView sollte von Anfang an die ganze Seite füllen. Gelöst war das aber
über eine **Liste bekannter Klassennamen** im CSS: `#masthead`,
`.site-header`, `#colophon`, `.main-navigation` und zwei Dutzend weitere
wurden ausgeblendet.

Das funktioniert für die Themes, die auf der Liste stehen — und **versagt
stillschweigend bei jedem anderen**. „Stillschweigend versagen" heisst hier:
Die Vereinsnavigation, die Titelleiste, die Fusszeile und das Cookie-Banner
stehen mitten in der Anwendung.

Ab 1.2.0 wird die Seite mit einer **eigenen Seitenvorlage** ausgeliefert
(`templates/vollbild.php`). Das Theme steuert keine einzige Zeile Markup mehr
bei: `get_header()` und `get_footer()` werden gar nicht erst aufgerufen. Was
nicht ausgegeben wird, muss man auch nicht verstecken.

Übrig bleibt im Dokument:

* die Anwendung selbst,
* was `wp_head()` und `wp_footer()` beisteuern — Stilvorlagen und Skripte.

Beides ist notwendig: Daran hängen die Stilvorlage und das Skript von
SwimView. Die Stilvorlagen des Themes kommen damit mit, schaden aber nicht,
weil es kein Theme-Markup gibt, auf das sie wirken könnten, und weil die
eigenen Regeln auf `body.lsv07s-vollbild` liegen und damit gewinnen.

### Was das in der Praxis heisst

* Kein Theme-Kopf, keine Navigation, keine Seitenleiste, keine Fusszeile,
  keine Brotkrumen, keine Titelleiste — unabhängig davon, welches Theme
  installiert ist.
* Die Anwendung beginnt bei `y=0` und füllt Breite und Höhe des Fensters.
* Die WordPress-Adminleiste bleibt aus, wie bisher.
* Das Dokument bekommt einen Titel, auch wenn das Theme keinen beisteuert
  (klassische Themes ohne `title-tag`-Unterstützung).
* Die Vollbild-Klasse steht jetzt auch am `<html>` — daran hängen die Regeln
  für Rand und Höhe, die vorher ins Leere liefen.

### Grenzen, und was davon bleibt

Ein **Cookie-Banner oder Chat-Widget, das sich über `wp_footer` einhängt**,
erscheint weiterhin: Diesen Haken braucht SwimView für sein eigenes Skript,
und fremde Ausgabe daraus herauszufiltern hiesse wieder raten. Wer so etwas
einsetzt, schliesst die SwimView-Seite am besten in dessen Einstellungen aus.
Auf einer Seite, die nur technisch notwendige Cookies setzt, ist ein Banner
ohnehin nicht nötig.

`wp_body_open` wird **nicht** aufgerufen — dort hängen sich üblicherweise
Banner und Zählpixel ein.

### Abschaltbar

Wer die Anwendung doch im Rahmen seines Themes haben will, setzt
`swimview_vollbild` auf `0`; dann greift wieder das Ausblenden per CSS. Ein
Rückfall, kein gleichwertiger Weg — mit den bekannten Grenzen.

Auf jeder anderen Seite der Vereinsseite ändert sich **nichts**: Die Vorlage
greift nur dort, wo der Shortcode steht. Das ist die wichtigere Hälfte, und
sie ist eigens geprüft — ein Plugin, das die Vorlage der ganzen Seite
übernimmt, hat auf dem Rest nichts verloren.

### Rückfall für Seitenbaukästen

Manche Baukästen führen ihren Inhalt an `the_content()` vorbei. Käme die
Anwendung dadurch nicht durch, stünde die Seite leer da — in dem Fall gibt
die Vorlage sie selbst aus.

## Geprüft

* **376 Prüfungen** gegen echtes SQL (vorher 351). Neu: die Vorlagen-Weiche
  (greift auf der SwimView-Seite, lässt jede andere in Ruhe, findet einen
  Shortcode auch aus einem Seitenbaukasten, ist abschaltbar) und die Ausgabe
  der Vorlage selbst — dass `get_header()`/`get_footer()` nicht laufen, dass
  kein Theme-Markup im Dokument steht und dass der Rückfall greift.
* **21 Prüfungen im Browser** eigens für den Vollbildmodus
  (`svvollbild.js`). Geprüft wird an einem **fremden Theme, dessen
  Klassennamen absichtlich nicht auf der CSS-Liste stehen** — denn genau so
  sieht die Wirklichkeit aus. Der Prüfstand weist zuerst nach, dass das
  Ausblenden per Liste dort versagt (die Navigation ist sichtbar und schiebt
  die Anwendung 97 px nach unten), und dann, dass über die eigene Vorlage
  kein einziges Theme-Element mehr im Dokument steht. Dazu auf 320 und
  390 px.
* 166 im Browser und 178 auf schmalen Geräten wie bisher.
