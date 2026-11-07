# Interner Bereich 9.6.0 — öffentliche Wettkampfseite: teilnehmende Mannschaften

Die öffentliche Übersicht der Wettkämpfe läuft weiterhin **ausschließlich
über den Kurzbefehl**:

```
[lsv07_wettkaempfe]
```

Der steht in einer WordPress-Seite, und damit liegt die Liste unter der
Adresse des Vereins, im Vereinsmenü, im Design der Website. **Eine
eigene Adresse im Plugin gibt es bewusst nicht** — es soll genau eine
Adresse geben, unter der diese Liste steht, und das ist die des
Vereins.

Fehlt die Seite, fehlt der Kurzbefehl darin. Mehr ist nicht kaputt.

## Neu: welche Mannschaften teilnehmen

Jede Karte zeigt jetzt unter Zeitraum und Ort **„Mit dabei:"** und
danach die teilnehmenden Mannschaften, in der Reihenfolge, in der sie
im Verein sortiert sind.

**Was dort steht, ist der Name der Mannschaft — und sonst nichts.**
„Wettkampfmannschaft 1" ist eine Gruppe, keine Person. Kein Name, kein
Jahrgang, keine Zeit, keine Anzahl einer einzelnen Person verlässt
diese Seite. Wer in einer Mannschaft schwimmt, ist und bleibt eine
interne Angabe. Hat ein Wettkampf keine Mannschaft eingetragen, steht
dort auch keine leere Zeile.

## Im internen Bereich steht, woher die Seite kommt

Unter **Wettkämpfe** steht links die Karte **„Öffentliche Übersicht"**:
der Kurzbefehl zum Herauskopieren, was auf der Seite zu sehen ist, und
dass vergangene Wettkämpfe dort 10 Tage nach ihrem Ende verschwinden.

Vorher stand im ganzen Plugin nirgends, wie die öffentliche Seite
entsteht.

## Die leere Seite sagt, warum sie leer ist

Vorher stand bei nichts Freigegebenem nur: *„Aktuell sind keine
Wettkämpfe freigegeben."* Das sah aus wie ein Fehler, auch wenn alles
richtig war. Jetzt steht darunter, dass vergangene Wettkämpfe **10 Tage
nach ihrem Ende** von dieser Seite verschwinden — der häufigste Grund
für eine leere Übersicht.

## Eine Stelle je Regel

* **Der Name des Kurzbefehls** steht in `SHORTCODE` — `init()` meldet ihn
  damit an, und die Erkennung „steht der Kurzbefehl auf dieser Seite?"
  (für das Stylesheet) fragt dieselbe Konstante. Sonst liefe beides
  auseinander, ohne dass sich an einer Ausgabe etwas ändert.
* **Der Stichtag** steht in `TAGE_VERGANGEN` — Abfrage und der Hinweis
  auf der leeren Seite nehmen dieselbe Zahl.
* **Alle Daten** kommen aus `daten()`; `render()` malt nur.

## Datenschutz und Sicherheit

* **Die Freigabe ist das Tor.** Ohne `freigegeben = 1` steht ein
  Wettkampf hier mit keinem einzigen Feld — auch seine Mannschaften
  nicht. Wird die Freigabe zurückgezogen, verschwindet er wieder
  vollständig.
* **Die Seite fragt keine Personen-Tabelle ab.** Nicht Schwimmer, nicht
  Anwesenheit, nicht Abrechnung, nicht Meldungen, nicht Atteste — das
  ist geprüft, nicht nur beabsichtigt.
* **Reine Lese-Ansicht**: kein Formular, kein AJAX-Aufruf von dieser
  Seite aus, kein eigenes Skript, kein Ereignis-Code in Attributen.
  Jede Ausgabe escaped.
* **Die Downloads prüfen selbst**: `dok_download()` prüft serverseitig
  erneut, dass der Wettkampf freigegeben ist. Ein von Hand
  zusammengesetzter Download-Link liefert für nicht freigegebene oder
  gelöschte Wettkämpfe nichts aus.
* **Das Stylesheet lädt nur dort**, wo der Kurzbefehl wirklich auf der
  Seite steht.
* **Eine Abfrage je Angabe, nicht je Karte.** Mannschaften, Dokumente
  und Links werden für alle Wettkämpfe in je einer Abfrage geholt.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Öffentliche Wettkampfseite (echte Klasse, echtes SQL) | 57 | 40 von 40 |

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft
sie nichts und ist wertlos. Darunter: die Freigabe ignorieren, die
Mannschaften falsch zuordnen, die Sortierung umdrehen, das Escaping
entfernen, einen Personen-Tisch abfragen, eine Abfrage je Karte
einbauen, ein Skript in die Seite setzen, heimlich einen zweiten
Kurzbefehl anmelden — und eine eigene Plugin-Adresse wieder einbauen.
