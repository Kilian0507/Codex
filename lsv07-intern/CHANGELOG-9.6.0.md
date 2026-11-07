# Interner Bereich 9.6.0 — die öffentliche Wettkampfseite kann nicht mehr weg sein

Die öffentliche Übersicht der Wettkämpfe hing an **einer** Sache: einer
WordPress-Seite, in die irgendwann einmal `[lsv07_wettkaempfe]` getippt
wurde. Verschwindet diese Seite — gelöscht, umbenannt, beim
Theme-Wechsel verloren, der Kurzbefehl beim Umbau im Editor
herausgefallen —, dann ist die Übersicht weg, und im Plugin stand
nirgends, wo sie war oder wie sie zurückkommt.

Das ist jetzt anders.

## Die Seite hat eine eigene Adresse

Die Übersicht liegt zusätzlich an einer Adresse, die dem **Plugin**
gehört:

```
https://<eure-website>/?lsv07i_wettkaempfe=1
```

Diese Adresse ist da, solange das Plugin aktiv ist. Sie braucht keine
WordPress-Seite, kein Menü und kein Theme. Dieselbe Bauart nutzt der
Reflexionsbogen schon lange — kein neues Muster, sondern das des
Plugins.

**Der Kurzbefehl bleibt unverändert.** `[lsv07_wettkaempfe]` auf einer
Vereinsseite ist weiterhin der normale Weg: dieselbe Liste, im Design
der Website, im Menü. Die eigene Adresse ist der Weg, der nicht
verloren gehen kann.

**Dahinter steht genau ein Renderer.** Beide Wege holen dieselben Daten
aus derselben Methode und malen dieselben Karten. Zwei Wege, eine
Wahrheit — sonst liefen sie auseinander.

## Im internen Bereich steht, wo sie liegt

Unter **Wettkämpfe** steht links die Karte **„Öffentliche Übersicht"**:

* **„Seite öffnen"** — die Adresse, direkt anklickbar.
* Was dort zu sehen ist, und dass vergangene Wettkämpfe nach 10 Tagen
  verschwinden.
* Der Kurzbefehl zum Herauskopieren, falls eine eigene Seite im
  Vereinsmenü gewünscht ist.

Die Adresse steht dabei **nicht** im Template, sondern kommt aus
`LSV07I_Wettkampf_Oeffentlich::url()`. Eine Stelle — sonst zeigt der
Knopf eines Tages woandershin als die Seite liegt.

## Neu auf der Seite: welche Mannschaften teilnehmen

Jede Karte zeigt jetzt unter Zeitraum und Ort **„Mit dabei:"** und
danach die teilnehmenden Mannschaften, in der Reihenfolge, in der sie
im Verein sortiert sind.

**Was dort steht, ist der Name der Mannschaft — und sonst nichts.**
„Wettkampfmannschaft 1" ist eine Gruppe, keine Person. Kein Name, kein
Jahrgang, keine Zeit, keine Anzahl einer einzelnen Person verlässt
diese Seite. Wer in einer Mannschaft schwimmt, ist und bleibt eine
interne Angabe. Hat ein Wettkampf keine Mannschaft eingetragen, steht
dort auch keine leere Zeile.

## Die leere Seite sagt, warum sie leer ist

Vorher stand bei nichts Freigegebenem nur: *„Aktuell sind keine
Wettkämpfe freigegeben."* Das sah aus wie ein Fehler, auch wenn alles
richtig war. Jetzt steht darunter, dass vergangene Wettkämpfe **10 Tage
nach ihrem Ende** von dieser Seite verschwinden — der häufigste Grund
für eine leere Übersicht.

## Datenschutz und Sicherheit

* **Die Freigabe ist das Tor.** Ohne `freigegeben = 1` steht ein
  Wettkampf hier mit keinem einzigen Feld — auch seine Mannschaften
  nicht. Wird die Freigabe zurückgezogen, verschwindet er wieder
  vollständig.
* **Die Seite fragt keine Personen-Tabelle ab.** Nicht Schwimmer, nicht
  Anwesenheit, nicht Abrechnung, nicht Meldungen, nicht Atteste — das
  ist geprüft, nicht nur beabsichtigt (siehe unten).
* **Eigene Sicherheits-Header** an der eigenen Adresse: `CSP` mit
  `default-src 'none'` und **ohne jede Skript-Erlaubnis** (die Seite
  braucht kein Skript), `frame-ancestors 'none'`, `X-Frame-Options:
  DENY`, `nosniff`, `Referrer-Policy: no-referrer`.
* **noindex an der eigenen Adresse** — Absicht. Die Vereinsseite mit
  dem Kurzbefehl ist die Adresse, die in Suchmaschinen stehen soll;
  die eigene Adresse soll nicht als zweite, konkurrierende Fassung
  derselben Liste im Index landen.
* **Reine Lese-Ansicht**: kein Formular, kein AJAX-Aufruf von dieser
  Seite aus. Jede Ausgabe escaped.
* **Die Downloads prüfen selbst**: `dok_download()` prüft serverseitig
  erneut, dass der Wettkampf freigegeben ist. Ein von Hand
  zusammengesetzter Download-Link liefert für nicht freigegebene oder
  gelöschte Wettkämpfe nichts aus.
* **Eine Abfrage je Angabe, nicht je Karte.** Mannschaften, Dokumente
  und Links werden für alle Wettkämpfe in je einer Abfrage geholt.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Öffentliche Wettkampfseite (echte Klasse, echtes SQL, echter Webserver) | 67 | 46 von 46 |

Die Sicherheits-Header sind unter einem **echten Webserver** geprüft,
nicht in der Kommandozeile — dort ist `header()` wirkungslos und jede
Zusicherung darüber wäre eine Behauptung.

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft
sie nichts und ist wertlos. Darunter: die Freigabe ignorieren, die
Mannschaften falsch zuordnen, die Sortierung umdrehen, das Escaping
entfernen, einen Personen-Tisch abfragen, eine Abfrage je Karte
einbauen, jede einzelne Sicherheits-Kopfzeile weglassen, und die
Adresse im Template statt aus `url()` schreiben.
