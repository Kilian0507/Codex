# LSV07 Interner Bereich — 8.6.0

## Neu: Trainingsplan (Schwimmen)

Neue Kategorie unter Schwimmen. Ein Trainingsplan besteht aus einem Titel
und einer geordneten Liste von **Sessions**, aus denen sich der Plan
zusammensetzt. Jede Session hat:

- **Anzahl** (z. B. „4x")
- **Strecke** (z. B. „100m Freistil")
- **Beschreibung**
- **Ausrüstung** (z. B. „Pull-Buoy")
- **Kommentar**

Sessions lassen sich beliebig hinzufügen, entfernen und per Pfeil-Knöpfen
nach oben/unten verschieben — die Reihenfolge im Editor ist die
Reihenfolge im fertigen Plan. Bei Beschreibung und Kommentar bleiben
**Zeilenumbrüche und Absätze erhalten** — sowohl in der Ansicht im System
(Absatz für Absatz wie eingegeben) als auch im PDF-Export.

### Anzeigen und exportieren

Jeder Plan lässt sich direkt im System ansehen oder mit einem Klick als
PDF herunterladen (clientseitig erzeugt, wie schon bei der Akte).

### Freigeben

Ein Trainingsplan gehört zunächst nur seinem Ersteller und erscheint für
alle anderen nirgends. Über „Freigeben" wird er für alle anderen Trainer
im Bereich **„Freigegeben"** sichtbar (mit Ersteller-Namen) — dort können
andere ihn ansehen und als PDF exportieren, aber nicht bearbeiten oder
löschen. „Zurückziehen" macht die Freigabe rückgängig.

### Rechte

Neuer Tab „Trainingsplan" unter Schwimmen, wie die meisten anderen
Schwimmen-Tabs standardmäßig für alle Schwimmen-Trainer sichtbar
(Recht „Trainingsplan-Tab sehen", auch einzeln vergebbar über die
Rechteverwaltung → Schwimmen → Trainingsplan). Bearbeiten, Freigeben und
Löschen sind serverseitig immer auf den jeweiligen Ersteller beschränkt
(Admin kann zusätzlich alle Pläne verwalten).

## Tests

- 34 Backend-Prüfungen (neue Testdatei, gegen eine echte SQLite-Datenbank
  mit den tatsächlichen SQL-Texten ausgeführt statt gegen eine
  Regex-Fake-Datenbank): Rechte-Gate mit und ohne Rollen-Fallback,
  Pflichtfelder, leere Sessions werden verworfen, Sichtbarkeit „Meine
  Pläne" vs. „Freigegeben" in beide Richtungen, Eigentümer-Schutz bei
  Bearbeiten/Freigeben/Löschen (inkl. Admin-Ausnahme), vollständiges
  Ersetzen der Sessions beim Bearbeiten, und dass mehrzeilige
  Beschreibungen inkl. Absätzen unverändert gespeichert und geladen
  werden.
- 30 Oberflächen-Prüfungen: Liste/Sub-Tabs, Anlegen mit mehreren Sessions
  inkl. Nachnummerierung, Mindest-eine-Session-Schutz, Bearbeiten mit
  Vorbefüllung, Freigeben/Badge, Ansicht mit erhaltenen Absätzen
  (inkl. Prüfung, dass die Darstellung tatsächlich `white-space:pre-wrap`
  nutzt), sauberer Hinweis statt Absturz bei fehlender PDF-Bibliothek,
  Löschen.
- Vollständiger Plugin-Regressionstest (1942 Klicks) und statische
  Prüfung ohne Befund; alle bisherigen Backend- und
  Oberflächen-Testdateien erneut ausgeführt und weiterhin grün.
