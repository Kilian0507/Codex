# LSV07 Interner Bereich — 8.11.0

## Neu: Links statt Dokument — mit selbst vergebenem Knopf-Namen

Nicht jede Unterlage liegt als PDF vor. Vieles steht nur im Netz: die
Ausschreibung im Verbandsportal, Livetiming, der Ergebnisdienst. Dafür gibt
es beim Wettkampf jetzt einen eigenen Bereich **„Links"**.

Je Link trägst du zwei Dinge ein:

- **Name des Knopfes** — frei wählbar, z. B. „Ausschreibung im Portal",
  „Livetiming" oder „Ergebnisse (DSV)"
- **Adresse** — die Internetadresse dahinter

Auf der öffentlichen Wettkampfseite erscheint dann genau dieser Name als
Knopf — **direkt neben Ausschreibung, Meldeergebnis und Protokoll**, in
derselben Optik. Damit auf einen Blick klar ist, was passiert, tragen
Link-Knöpfe ein Verweis-Symbol (Pfeil aus dem Kasten) statt des
Download-Pfeils der PDF-Knöpfe.

Die Links lassen sich **schon beim Anlegen** eintragen — genau wie die
Ausschreibung seit 8.9.2 — und werden zusammen mit dem Wettkampf
gespeichert. Über „+ Link hinzufügen" kommen weitere dazu, über „✕" fliegt
einer wieder raus. Bis zu 10 Links je Wettkampf.

### Kleinigkeiten, die dabei mitgedacht sind

- Eine Adresse ohne `https://` davor wird automatisch ergänzt — „www.…"
  reicht also.
- Es werden ausschließlich normale Web-Adressen akzeptiert (http/https).
  Alles andere wird verworfen, damit über einen öffentlichen Knopf nichts
  Unerwünschtes ausgeführt werden kann.
- Lässt du den Namen leer, steht schlicht „Link" auf dem Knopf, statt dass
  er namenlos bleibt.
- Wird ein Wettkampf gelöscht, verschwinden seine Links mit.

## Hinweis zur Aktualisierung

Für die Links kommt eine neue Tabelle dazu. Sie wird beim Update automatisch
angelegt — und zur Sicherheit auch dann, wenn der Bereich zum ersten Mal
benutzt wird. Bestehende Wettkämpfe bleiben unverändert.

## Tests

- 17 neue Backend-Prüfungen gegen eine echte SQLite-Datenbank mit den echten
  SQL-Texten: Tabelle wird bei Bedarf selbst angelegt, Links werden mit Name,
  Adresse und Reihenfolge gespeichert und wieder ausgelesen, eine Adresse
  ohne Schema bekommt `https://`, `javascript:`- und `data:`-Adressen werden
  verworfen, HTML im Namen wird entfernt, ein leerer Name wird zu „Link", zu
  lange Namen werden gekürzt, höchstens 10 Links je Wettkampf, eine leere
  Liste entfernt alle, ein Speichern ohne Link-Angabe lässt bestehende Links
  unangetastet, und das Löschen eines Wettkampfs räumt sie mit weg.
- 19 neue Oberflächen-Prüfungen im echten Browser: auf der öffentlichen Seite
  stehen Link- und PDF-Knöpfe zusammen in einer Reihe, der selbst vergebene
  Name steht auf dem Knopf, der Link führt zur richtigen Adresse, öffnet in
  einem neuen Tab und trägt das Verweis-Symbol; im Dialog ist der
  Link-Bereich schon beim Anlegen da, Zeilen lassen sich hinzufügen,
  ausfüllen, mitspeichern und wieder entfernen, und ein gespeicherter Link
  erscheint beim erneuten Öffnen wieder.
- Bestehende Wettkampf-Suite (28), Meldungs-Suite (65), Staffel-Suite (19+20)
  und die Hell-Darstellung der öffentlichen Seite (10) weiterhin vollständig
  grün; vollständiger Plugin-Regressionstest und statische Prüfung ohne
  neuen Befund.

### Nebenbei bereinigt

Zwei ältere Testdateien prüften noch Verhalten, das mit 8.9.0 bewusst
entfallen ist (Erinnerungsadressen je Wettkampf statt zentral unter
Admin → Mails). Sie sind auf den aktuellen Stand gebracht. Am Plugin selbst
ändert das nichts — die Erinnerungen gehen weiterhin ausschließlich an die
zentral hinterlegten Adressen.
