# Interner Bereich 9.14.1 — Wettkampf-Anwesenheit wie die normale Anwesenheit

## Mannschaftsbuch → Anwesenheit

**Entfernt:** die Kachel „Anwesenheit erfassen“ (mit Training/Wettkampf-
Umschalter und „Ausgefallen“), „Vergangene Sessions“ und die
„Anwesenheitsstatistik“ samt Saisonbericht-Knopf.

**Wettkampf-Anwesenheit wie die normale:** Oben **Mannschaft und Tag**
wählen, mehr nicht. Hat die Mannschaft an diesem Tag einen Wettkampf,
erscheint unter den Kacheln des Trainingstags die Kachel mit dem Namen des
Wettkampfs und der Liste: Trainer mit Abschnitten, Schwimmer mit
anwesend / abwesend / entschuldigt. Gespeichert wird bei jedem Tipp. An
Tagen ohne Wettkampf bleibt die Kachel weg. Hat die Mannschaft an einem
Tag zwei Wettkämpfe, stehen oben in der Kachel zwei Knöpfe zum Umschalten.

* Nur ansehen legt nichts an. Die Wettkampf-Session entsteht erst mit
  dem ersten Eintrag, und auch bei mehreren schnellen Tipps nur einmal.
  So zählt die Statistik keine Wettkampftage, an denen niemand etwas
  erfasst hat.
* Wechselt der Tag oder die Mannschaft, verschwindet der alte Wettkampf
  sofort, auch wenn der neue Tag nicht geladen werden kann. Es lässt sich
  also nichts aus Versehen beim falschen Tag eintragen.
* Der Server liefert nur Wettkämpfe der eigenen Mannschaften und nur
  solche, an denen die Mannschaft teilnimmt.
* Die Kachel erscheint mit dem Recht für das Mannschaftsbuch plus
  Wettkämpfe bzw. Anwesenheit. Ohne das Mannschaftsbuch-Recht gibt es den
  Knopf „Anwesenheit“ nicht mehr, weil Mannschaft und Tag aus dem Buch
  kommen.
* Die Startseiten-Kachel „Anwesenheit“ öffnet das Mannschaftsbuch.

## Aufgeräumt

Der Code der entfernten Kacheln (Laden, Ausfall, Wettkampf-Auswahl,
Sessions-Liste, Statistik, Saisonbericht-Knopf) ist entfernt. Die
Oberflächen-Prüfung hatte zu Recht gemeldet, dass er auf Felder zeigte,
die es nicht mehr gibt.

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Server: Wettkampf an Tag + Mannschaft, fremde Mannschaft, ungültiges Datum, ansehen ohne Anlegen, Anlegen nur einmal | 12 |
| Vorlage mit sechs Rechte-Kombinationen | 27 |
| Browser: Kachel erscheint/verschwindet mit dem Tag, ansehen ohne Anlegen, zwei schnelle Tipps → einmal anlegen, zwei Wettkämpfe am Tag, Tag lädt nicht | 39 |

**Gegenprobe:** 27 von 27 rot. Drei Prüfungen waren zunächst zu schwach
und wurden geschärft (zwei schnelle Tipps; Tag lädt nicht; Reihenfolge
der Antworten). Danach lief die ganze Gegenprobe erneut.

Bisherige Prüfungen grün: Oberfläche 210, Abzeichen/PDF/Uhr 101,
Trainingsplan, Version 9.12.0 (24 + 31), Zuständigkeit 59, Wettkampf 57.
