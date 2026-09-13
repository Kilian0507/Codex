# LSV07 Interner Bereich — 8.20.2

## Kein Trainer-Bereich mehr, und alle Mannschaften laden zuverlässig

### Der Trainer-Bereich verschwindet

Der Bereich **Trainer** (eigene Abrechnung, Sonderabrechnung, Stammdaten)
erschien bisher, sobald die Person **irgendwo in der Trainerliste stand** —
unabhängig davon, ob sie abrechnen darf. Wer nur alle Mannschaften einsehen
sollte, bekam dadurch einen Abrechnungs-Bereich, der ihn nichts angeht.

Er hängt jetzt am **Recht „Eigene Abrechnung sehen"** statt am bloßen
Vorhandensein eines Trainer-Datensatzes. Das entspricht der Linie des
Plugins, nach der Sichtbarkeiten aus Rechten folgen.

- **Für echte Trainer ändert sich nichts:** Das Recht steckt in der
  Trainer-Vorlage, jeder eingerichtete Trainer hat es.
- **Altbestände sind abgesichert:** Wer noch gar keine Rechte eingerichtet
  hat, für den zählt weiterhin der Trainer-Datensatz — sonst verlöre er
  seinen Bereich.
- **Gegenprobe:** Mit dem alten Stand erschien der Trainer-Bereich für
  genau diese Person; mit dem neuen nicht mehr, während ein normaler
  Trainer ihn behält.

### Alle Mannschaften laden

Die Mannschaftsübersicht holte die Schwimmer **je Mannschaft in einer
eigenen Anfrage** nach. Bei ein, zwei eigenen Mannschaften fiel das nicht
auf — wer alle Mannschaften sieht, löste damit ein Dutzend gleichzeitiger
Anfragen aus. Bleibt davon auf einem kleinen Server eine hängen, laden eben
„nicht alle Mannschaften".

Jetzt kommen die Schwimmer **aller Mannschaften in einer einzigen Anfrage**
(neuer Endpunkt `lsv07i_schwimmen_get_schwimmer_alle`). Das ist deutlich
schneller und kann nicht mehr teilweise scheitern. Schwimmer, die in mehreren
Mannschaften stehen, erscheinen weiterhin in jeder davon.

**Zur Einordnung:** Die Auswahl der Mannschaften selbst war schon vorher
richtig — im Test kommen mit dem Leserecht beide Mannschaften an, auch bei
einer Person mit eigenem Trainer-Datensatz. Wenn bei dir weiterhin
Mannschaften fehlen, ist der nächste Blick wert, ob bei dem Konto wirklich
**„Alle Mannschaften einsehen"** angehakt ist (nicht nur
„Mannschaftsliste sehen") — am Hinweis über der Liste ist das zu erkennen:
Er erscheint nur, wenn das Recht wirklich greift.

### Geprüft

- 53 Prüfungen gegen eine echte Datenbank (14 neu). Darunter genau die
  gemeldete Lage — eine Person **mit** Trainer-Datensatz und **nur** dem
  Leserecht: beide Mannschaften kommen an, die Antwort ist als Vollsicht
  gekennzeichnet, nur der Mannschaften-Tab ist offen, und die Schwimmer der
  fremden Mannschaft laden ebenfalls. Dazu die neue Sammelanfrage: beide
  Mannschaften mit ihren Schwimmern und Attest-Status, ein Schwimmer in zwei
  Mannschaften in beiden, leere Anfrage und unbekannte Mannschaft sauber
  behandelt.
- 34 Prüfungen in der echten Oberfläche (5 neu), mit einer Test-Sicht, die
  jetzt der gemeldeten Person entspricht (steht in der Trainerliste, hat nur
  das Leserecht): kein Trainer-Bereich in der Hauptnavigation, Schwimmbereich
  vorhanden, je Datenabruf genau eine Sammelanfrage, keine Einzelanfrage je
  Mannschaft — und die Gegenprobe, dass ein normaler Trainer seine Tabs und
  seinen Trainer-Bereich behält.
- Bestehende Prüfungen unverändert grün: Admin→Trainer (28 + 24),
  Abrechnung (30 + 19 + 23), Trainingsplan (68 + 58 + 41 + 51), statische
  Prüfung, Klick-Durchlauf über alle fünf Nutzerarten (90 Klicks, 0 Fehler).
