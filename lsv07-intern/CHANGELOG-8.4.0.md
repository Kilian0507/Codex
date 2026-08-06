# LSV07 Interner Bereich — 8.4.0

## Trainer → Sportler: jetzt über das Rechtesystem + Bearbeiten möglich

Zwei Änderungen am in 8.3.0 eingeführten Trainer-Bereich "Sportler":

### 1. Zugriff jetzt über ein eigenes Recht

Bisher sah jeder Trainer den Tab automatisch. Jetzt ist er nur noch sichtbar
mit dem neuen Recht **„Im Trainer-Bereich eigene Sportler anlegen,
bearbeiten und in andere Mannschaften verschieben"** (Rechteverwaltung →
Schwimmen → Trainer-Bereich: eigene Sportler). Wie bei Freigabe/Meldungen/
Akte bewusst **ohne Rollen-Fallback** — allein Trainer zu sein reicht nicht
mehr, das Recht muss gezielt vergeben werden. Admin sieht den Bereich wie
gewohnt immer.

### 2. Bearbeiten hinzugefügt

Wer Sportler anlegen darf, kann jetzt auch deren Daten bearbeiten — nicht
nur anlegen und verschieben wie bisher. Neuer "Bearbeiten"-Knopf je Sportler
in der Liste, öffnet dasselbe Formular mit den vorhandenen Daten
vorausgefüllt (Name, Geburtsdatum, Attest, DSV-ID, Notizen,
Kontaktpersonen).

Eine Feinheit dabei: Trainiert ein Sportler zusätzlich in einer Mannschaft
mit, die nicht dem bearbeitenden Trainer gehört, zeigt das Formular
weiterhin nur die eigenen Mannschaften zur Auswahl — die fremde Zuordnung
bleibt beim Speichern unangetastet erhalten, statt versehentlich gelöscht zu
werden. Das ist bewusst anders als beim „Verschieben", das nach wie vor
alle bisherigen Mannschaften vollständig durch die Zielmannschaft ersetzt.

Weiterhin nicht enthalten: Löschen/Deaktivieren von Sportlern sowie die
Attest-Dateiverwaltung — beides bleibt dem Admin-Bereich vorbehalten.

## Tests

- 35 Backend-Prüfungen (vorher 21): neu hinzugekommen sind die Rechte-Gate-
  Prüfung (Trainer mit Mannschaft, aber ohne Recht, bleibt ausgesperrt) und
  die Bearbeiten-Funktion inklusive der Prüfung, dass eine fremde
  Mannschafts-Zuordnung beim Speichern erhalten bleibt, während Bearbeiten
  eines fremden Sportlers weiterhin abgelehnt wird.
- 23 Oberflächen-Prüfungen (vorher 13): neu ist der komplette
  Bearbeiten-Ablauf (Formular vorausgefüllt, nur eigene Mannschaften
  angezeigt, Dateien-Bereich bleibt verborgen, Request geht an den
  richtigen Endpunkt).
- Alle bisherigen Prüfungen erneut ausgeführt und weiterhin grün,
  vollständiger Plugin-Regressionstest (1898 Klicks) und statische Prüfung
  ohne Befund.
