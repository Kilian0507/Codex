# LSV07 Interner Bereich — 8.13.1

## PDF-Export der Meldeliste: Tabellenlinien

Das PDF hatte zwar Spalten, aber keine sichtbaren Trennlinien — auf Papier
war dadurch nicht immer klar, welcher Wert zu welcher Spalte gehört.

Die Tabelle hat jetzt ein vollständiges Liniengitter wie eine Excel-Tabelle:
Rahmen um jede Zelle, also senkrechte Linien zwischen den Spalten und
waagerechte zwischen den Zeilen, dazu ein Außenrahmen. Die Kopfzeile bleibt
zusätzlich hellblau hinterlegt und hebt sich damit weiterhin ab.

Bei längeren Meldelisten, die über mehrere Seiten gehen, werden die Linien
auf jeder Seite mitgezeichnet und die Kopfzeile wie bisher wiederholt.

## Tests

- Die 23 bestehenden Prüfungen zum PDF-Export laufen unverändert durch
  (Inhalt, Spalten, kein Attest im PDF, Dateiname).
- 11 zusätzliche Prüfungen mit einer langen Meldeliste (60 Starts): Das PDF
  umfasst mehrere Seiten, die Kopfzeile erscheint auf jeder davon, und auch
  mehrseitig taucht keine Attest-Angabe auf.
- Sichtprüfung der erzeugten Datei: Gitterlinien um alle Zellen, hinterlegte
  Kopfzeile, Spalten sauber getrennt.
- Statische Prüfung ohne Befund; Klick-Durchlauf über alle Bereiche aller
  fünf Rollen (90 Klicks) ohne JavaScript-Fehler.
