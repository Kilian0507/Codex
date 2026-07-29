# Änderungen in Version 7.63.0

## Die große Kachel um die Inhalte ist weg

In jedem Bereich lag um die Inhalts-Kacheln noch eine zweite, große
Kachel. Bei „Anwesenheit" zum Beispiel steckten *Anwesenheit erfassen*,
*Vergangene Sessions* und *Anwesenheitsstatistik* zusammen in einem
weiteren Kasten.

Ursache war eine einzige CSS-Regel: `.i-panel` — der Container, in dem
der Inhalt eines Reiters liegt — bekam dieselbe Glas-Optik wie eine
Karte. Das galt für **alle 50 Panels** in allen Bereichen. Panels sind
jetzt reine Container ohne Hintergrund, Rahmen und Schatten. Die
kleinen Kacheln stehen direkt auf dem Seitenhintergrund.

Nebeneffekt an drei Stellen, an denen ein Panel *innerhalb* einer Karte
liegt (Abrechnung → Training / Wettkämpfe / Kilometer): dort gab es
vorher Kachel-in-Kachel-in-Kachel, jetzt liegt der Inhalt flach in der
Detailkarte.

**Personen** (Administration) hatte als einziges Panel Inhalt ohne
eigene Karte — Suchfeld, Filter und Liste hingen frei im Panel. Der
Bereich hat jetzt eine eigene Karte mit der Überschrift „Personen" und
dem Knopf „+ Person anlegen" in der Kopfzeile.

## Nachrichten: alle runden Knöpfe gleich groß

Die Knöpfe im Chat hatten drei verschiedene Maße. Jetzt haben alle
dasselbe Maß wie die Knöpfe auf der Startseite: **54 px Fläche,
30 px Symbol** — auf dem Handy genauso wie am Rechner.

| Knopf | vorher | jetzt |
|---|---|---|
| Neue Unterhaltung (+) | 46 px / 26 px | 54 px / 30 px |
| Zurück zur Liste | 46 px / 28 px | 54 px / 30 px |
| Info zur Unterhaltung | 46 px / 28 px | 54 px / 30 px |
| Drei Punkte in der Liste | 40 px / 20 px | 54 px / 30 px |
| Anhang, Dringend, Senden | 54 px / 30 px | unverändert |

## Drei Punkte sind sichtbar

Der Knopf mit den drei Punkten, über den sich Unterhaltungen aus der
Liste entfernen lassen, hatte die blasse Zweitschriftfarbe und war kaum
zu erkennen. Er hat jetzt die volle Textfarbe — schwarz in der hellen
Darstellung, hell in der dunklen.

## Behobene Fehler

- **Absturz in der Bestzeiten-Rangliste.** Lieferte der Server eine
  Antwort ohne Einträge, brach die Anzeige mit einem JavaScript-Fehler
  ab; der Rest der Seite reagierte danach nicht mehr. Gefunden beim
  Durchklicken aller 1814 Knöpfe.
- **Navigation auf dem Handy war im Dunkelmodus weiß.** Sie bekommt dort
  bewusst keinen Weichzeichner (sonst lässt sich das Menü nicht
  bedienen), war dabei aber fest auf Weiß gestellt — mit heller Schrift
  darauf. Die Ersatzfarbe richtet sich jetzt nach der Darstellung.
- **Der eigene Name in der Kopfleiste war im Dunkelmodus unsichtbar** —
  fest schwarz auf dunkler Leiste, am Rechner wie am Handy.

## Geprüft

- 178 Panel-Prüfungen (5 Rollen × 2 Darstellungen): kein Panel hat noch
  Kachel-Optik, kein Inhalt steht ohne eigene Fläche da
- 112 Kachelwechsel in allen Bereichen: immer das richtige Panel
  sichtbar und die richtige Kachel markiert
- 1814 Knöpfe angeklickt bei 1280 px und 390 px: keine
  JavaScript-Fehler, kein Knopf bleibt deaktiviert, kein waagerechtes
  Scrollen
- Unterhaltungsliste bei 320/360/390/430 px ohne Überlauf
- Statische Prüfung: keine doppelten IDs, `if`/`endif` 57/57, kein
  Navigationsziel ohne Panel, kein Panel ohne Route, keine doppelten
  JavaScript-Funktionen, alle PHP-Dateien fehlerfrei
- Personen-Import: 65 von 65 Prüfungen bestanden

## Datenbank

Keine Schema-Änderungen.
