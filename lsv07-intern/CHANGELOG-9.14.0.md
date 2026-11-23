# Interner Bereich 9.14.0 — Mannschaftsbuch neu gegliedert, Abzeichen aus Bildern

## Mannschaftsbuch: Anwesenheit · Verwaltung · Abzeichen

Oben im Mannschaftsbuch stehen jetzt drei Knöpfe, dort, wo vorher
„Dashboard / Bahnen / Statistik“ waren. Jeder Teil zeigt seine Inhalte
**direkt als Kacheln untereinander**, wie bisher das Dashboard. Es gibt
keine Einzelseiten mehr, in die man erst wechseln muss.

| Knopf | Kacheln |
|---|---|
| **Anwesenheit** | Trainer, Anwesenheit, Dokumentation, Vermerke, Aufgaben (der Trainingstag wie bisher) · **Wettkampf-Anwesenheit** · Trainingsausfall · vergangene Sessions (Admin) · Anwesenheitsstatistik mit Saisonbericht |
| **Verwaltung** | Bahnen · Statistik · Mannschaften · Atteste · Bestzeiten · Reflexion · Akte — alle offen untereinander |
| **Abzeichen** | Abzeichen der Mannschaft (Katalog, Vergabe, PDF) |

* **Trainer** gibt es im Mannschaftsbuch nicht mehr (Kachel und Seite
  entfernt, ebenso die Kachel auf der Startseite).
* Jede Kachel erscheint nur mit ihrem Recht, wie vorher der Reiter. Ein
  Knopf erscheint nur, wenn es darunter etwas gibt. Wer zum Beispiel nur
  die Atteste sieht, bekommt nur „Verwaltung“ mit den Attesten.
* Mannschaft und Datum oben gelten für Anwesenheit, Bahnen und Statistik.
  Bei den Abzeichen ist die Zeile ausgeblendet, sie haben ihre eigene
  Auswahl.
* Das Gerät merkt sich den zuletzt gewählten Teil.
* Alte Verweise führen an die richtige Stelle: Startseiten-Kacheln
  (z. B. „Bestzeiten“), ein gemerkter Ort aus einer früheren Version und
  „Zu den Bestzeiten“ im Import öffnen das Mannschaftsbuch im passenden
  Teil und scrollen zur Kachel.

## Abzeichen: einfach Bilder hochladen

Neuer Knopf **„Bilder hochladen“**. Du kannst mehrere Bilder auf einmal
wählen. **Jedes Bild wird ein Abzeichen, sein Name ist der Dateiname ohne
Endung**: „Seepferdchen Gold.png“ wird „Seepferdchen Gold“. Die Bilder
werden nacheinander angelegt. Klappt eines nicht (z. B. kein gültiges
Bild), wird es am Ende genannt, die anderen werden trotzdem angelegt.
Höchstens 50 auf einmal.

Der Server leitet den Namen ebenso aus dem Dateinamen ab, wenn keiner
mitkommt. Er bereinigt ihn (keine Tags, kein Pfad, höchstens 80 Zeichen)
und prüft wie immer Recht und Mannschaft. Bearbeiten, Umbenennen, Bild
tauschen und Löschen gehen weiter über „Bearbeiten“. Ein Abzeichen ohne
Bild lässt sich weiter anlegen („+ Abzeichen ohne Bild“).

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Vorlage mit fünf Rechte-Kombinationen (alles; nur Atteste; nur Mannschaftsbuch; Anwesenheit + Abzeichen; nichts davon) | 26 |
| Browser: drei Teile, Kacheln sichtbar und in Reihenfolge, Laden je Teil, gemerkte Ansicht, Umleitungen, Mehrfach-Upload, Handy | 29 |
| Server: Abzeichen (inkl. Name aus Dateiname, ohne Recht abgelehnt) | 52 |

**Gegenprobe:** 18 von 18 rot, nach dem Zurücksetzen grün.

Bisherige Prüfungen grün: Oberfläche 210, Abzeichen/PDF/Uhr 101,
Trainingsplan 28 + 67, Version 9.12.0 (24 + 31), Datenschutz 58,
Zuständigkeit 59.
