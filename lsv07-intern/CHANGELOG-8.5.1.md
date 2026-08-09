# LSV07 Interner Bereich — 8.5.1

## Bugfix: Akte fand Schwimmer mit mehreren Mannschaften nicht zuverlässig

Bei Schwimmern, die in mehr als einer Mannschaft trainieren, wurde in der
Akte nur die Heimat-Mannschaft (`team_id`) berücksichtigt. Zwei konkrete
Auswirkungen:

- Filterte man die Akte gezielt auf eine **Zusatz-Mannschaft** eines
  Schwimmers (die, in der er nur mittrainiert, nicht seine Heimat-
  Mannschaft), wurde der Schwimmer dort gar nicht erst gefunden — die
  Personensuche prüfte ausschließlich `team_id`, nicht die
  Zusatz-Zuordnungen.
- Auch ohne Mannschafts-Filter fehlte eine Zusatz-Mannschaft in der Liste,
  wenn im gewählten Zeitraum noch keine Anwesenheit dafür erfasst wurde —
  sie wurde nur über vorhandene Anwesenheitsdaten "entdeckt", nicht über
  die eigentliche Mannschafts-Zuordnung.

Beide Stellen wurden korrigiert: Die Personensuche berücksichtigt jetzt
zusätzlich die Zusatz-Mannschaften-Tabelle, und die Mannschaftsliste je
Person zeigt jede zutreffende Mannschaft (Heimat, formale
Zusatz-Zuordnung, oder auch nur durch erfasste Anwesenheit) — mit 0-Werten,
falls im Zeitraum noch nichts erfasst wurde, statt zu fehlen.

## Tests

- 19 Backend-Prüfungen (neue Testdatei), davon 6 neue gezielt für dieses
  Bugfix-Szenario: Schwimmer mit Heimat- + Zusatz-Mannschaft wird in
  beiden Ansichten (alle Mannschaften / gezielt auf die Zusatz-Mannschaft
  gefiltert) korrekt gefunden, die jeweils andere Mannschaft wird korrekt
  ein- bzw. ausgeblendet.
- Bestehende 43 Akte-/Wettkampf-Prüfungen erneut ausgeführt und weiterhin
  grün — insbesondere der Fall, dass Anwesenheit in einer Mannschaft ohne
  formale Zusatz-Zuordnung weiterhin korrekt angezeigt wird.
