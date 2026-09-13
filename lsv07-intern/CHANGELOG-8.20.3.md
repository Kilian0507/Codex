# LSV07 Interner Bereich — 8.20.3

## Gefunden: die Mannschaften blieben auf „Wird geladen…" stehen

Der Fehler saß in `get_data()` — dem Aufruf, mit dem der Schwimmbereich seine
Mannschaften holt — und trifft **genau eine Sorte Nutzer**: Wer einen
Trainer-Datensatz hat, aber **keiner Mannschaft zugeordnet** ist. Also
jemanden, der nur alle Mannschaften einsehen soll.

### Was passiert ist

Die Abfrage nach Springer-Schichten benutzt die Liste der eigenen
Mannschaften (`$in`). Diese Liste wurde aber **nur im Zweig „hat eigene
Mannschaften" gefüllt**, während die Abfrage in jedem Fall lief. Ohne eigene
Mannschaft stand dort eine undefinierte Variable:

- PHP meldet „Undefined variable $in",
- und das SQL wird zu `NOT IN ()`, was MySQL als Syntaxfehler zurückweist.

Gibt die Seite PHP-Meldungen aus, steht diese Meldung **vor** der
JSON-Antwort. Damit ist die Antwort für die Oberfläche unlesbar — und weil
der Abruf **keine Fehlerbehandlung** hatte, brach er stillschweigend ab.
Auf dem Bildschirm blieb „Wird geladen…" stehen, ohne jeden Hinweis. Genau
das gemeldete Verhalten.

### Behoben

- **`$in` wird vor der Verzweigung gesetzt** — ohne eigene Mannschaften auf
  `0`. Das trifft keine Mannschaft und lässt damit alle als „fremd" gelten,
  was für die Springer-Abfrage genau richtig ist. Keine Warnung, gültiges
  SQL. Eine Springer-Schicht in einer fremden Mannschaft wird weiterhin
  gefunden, auch ohne eigene Mannschaft.
- Das Zusammenführen der Slots ist gegen eine fehlgeschlagene Abfrage
  abgesichert, damit dort nicht dieselbe Falle entsteht.
- **Fehler werden sichtbar.** Schlägt das Laden fehl, steht jetzt die
  Meldung in der Mannschaftsliste — statt endlos „Wird geladen…". Das gilt
  für beide Fälle: unlesbare Antwort und abgelehnte Berechtigung.

### Geprüft

- 58 Prüfungen gegen eine echte Datenbank (5 neu), darunter der neue
  Abschnitt „Trainer-Datensatz **ohne** zugeordnete Mannschaft": `get_data`
  antwortet sauber, liefert trotzdem alle Mannschaften, die Slot-Liste ist
  eine Liste, eine Springer-Schicht wird gefunden — **und es entsteht keine
  PHP-Meldung**, die der JSON-Antwort vorausliefe. Der Test sammelt
  PHP-Warnungen jetzt ein und wertet sie als Fehler.
- **Gegenprobe:** Mit dem alten Stand schlägt genau diese Prüfung fehl, mit
  der Meldung „Undefined variable $in (class-ajax-schwimmen.php:64)".
- 38 Prüfungen in der echten Oberfläche (4 neu): Bei unlesbarer Antwort und
  bei abgelehnter Berechtigung steht nicht mehr „Wird geladen…", sondern
  eine verständliche Meldung.
- Bestehende Prüfungen unverändert grün: Admin→Trainer (28 + 24),
  Abrechnung (30 + 19 + 23), Trainingsplan (68 + 58 + 41 + 51), statische
  Prüfung, Klick-Durchlauf über alle fünf Nutzerarten (90 Klicks, 0 Fehler).
