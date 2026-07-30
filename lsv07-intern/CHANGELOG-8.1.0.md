# LSV07 Interner Bereich — 8.1.0

## Neu: Akte

Neuer, eigenständiger Bereich unter Schwimmen — **nur sichtbar mit dem neuen
Recht** „Schwimmer-Akte einsehen" (Rechteverwaltung → Schwimmen → Akte).
Wie bei Freigabe/Meldungen bewusst **ohne Rollen-Fallback**: das Recht muss
gezielt vergeben werden, auch Trainer sehen den Bereich nicht automatisch.

- Zeitraum (von/bis) und optional eine einzelne Mannschaft wählen.
- Zeigt je Person: Name, Geburtsdatum, Mannschaft(en), Anwesenheit **je
  Mannschaft** im gewählten Zeitraum (Anwesend/Entschuldigt/Abwesend/Quote),
  aktuelle Bestzeiten und einen optionalen Kommentar.
- Trainiert jemand in mehreren Mannschaften mit, wird die Anwesenheit korrekt
  getrennt je Mannschaft ausgewiesen (nicht vermischt).
- **Nur die eigenen Mannschaften**: Trainer sehen ausschließlich Mitglieder
  der Mannschaften, denen sie zugeordnet sind. Admin/Schwimmwart sehen alle.
  Diese Einschränkung wird serverseitig erzwungen, nicht nur in der
  Oberfläche versteckt — eine Anfrage für eine fremde Mannschaft wird
  serverseitig abgelehnt, unabhängig davon, was die Oberfläche anzeigt.
- Als PDF herunterladbar (ein Dokument mit allen angezeigten Personen),
  läuft komplett im Browser (wie beim bestehenden Saisonbericht) — kein
  neuer Datei-Endpunkt auf dem Server nötig.

**Bitte beachten:** Die Bestzeiten-Tabelle speichert kein Datum, an dem eine
Zeit erzielt wurde — nur den Zeitpunkt des letzten Imports. Die Bestzeiten in
der Akte sind deshalb immer der **aktuelle Stand**, unabhängig vom gewählten
Zeitraum. Nur die Anwesenheit wird tatsächlich auf den Zeitraum gefiltert.

## Wettkämpfe: vergangene Wettkämpfe verschwinden nach 10 Tagen

Auf der öffentlichen Übersichtsseite (`[lsv07_wettkaempfe]`) werden
abgelaufene Wettkämpfe jetzt automatisch ausgeblendet, sobald ihr Ende
**mehr als 10 Tage** zurückliegt (der 10. Tag selbst zählt noch dazu).
Betrifft nur die öffentliche Seite — im internen Bereich bleiben alle
Wettkämpfe wie gehabt unbefristet einsehbar.

## Wettkämpfe: zusätzliche Mail-Empfänger (Admin)

Neuer, ausschließlich für Administratoren sichtbarer Bereich unter
Admin → Mails → „Wettkämpfe: zusätzliche Empfänger": feste E-Mail-Adressen
hinterlegen und je Adresse einzeln festlegen, welche der drei automatischen
Wettkampf-Mails sie zusätzlich erhalten soll:

- Freigabe-Anfrage (bei Neuanlage eines Wettkampfs)
- Meldeergebnis-Erinnerung (3 Tage vor Beginn)
- Protokoll-Erinnerung (1 Tag nach Ende)

Diese Adressen kommen **zusätzlich** zu den ohnehin automatisch ermittelten
Empfängern hinzu (Freigabe-Berechtigte laut Rechte-System bzw. die je
Wettkampf hinterlegten Erinnerungsadressen) — sie ersetzen nichts, sondern
stellen sicher, dass z. B. der Vorstand oder ein Archiv-Postfach jede
Freigabe-Anfrage mitbekommt, ohne dass das bei jedem Wettkampf einzeln
eingetragen werden muss. Eine hinterlegte globale Adresse sorgt außerdem
dafür, dass eine Erinnerungs-Mail auch dann verschickt wird, wenn für einen
einzelnen Wettkampf noch keine Erinnerungsadressen eingetragen wurden.

## Tests

- 43 neue Backend-Logik-Prüfungen (CLI, In-Memory-Datenbank-Double): u. a.
  Zugriffsscoping der Akte (Trainer sieht nur eigene Mannschaft, Admin alle,
  fremde Mannschaft wird serverseitig abgelehnt), korrekte Zuordnung der
  Anwesenheit zur tatsächlichen Session-Mannschaft bei Mehrfach-Mannschaft,
  Zeitraum-Validierung, Mail-Empfänger-CRUD (inkl. Upsert per E-Mail-Adresse)
  und der Merge zusätzlicher Adressen in Freigabe-Anfrage und Erinnerungs-
  Cron, sowie die 10-Tage-Grenze der öffentlichen Übersichtsseite
  (Grenzwert genau geprüft: Tag 10 sichtbar, Tag 11 nicht mehr).
- 16 neue Oberflächen-Prüfungen (Playwright): Akte-Panel (Mannschafts-Filter,
  Zeitraum-Validierung, Ergebnisdarstellung, PDF-Knopf-Aktivierung), Admin-
  Mail-Empfänger-Verwaltung (Anzeigen, Hinzufügen, Löschen).
- Alle bisherigen Prüfungen erneut ausgeführt und weiterhin grün: 29
  Backend-Prüfungen und 36 Oberflächen-Prüfungen der Wettkampf-Erweiterung,
  18 HTTP-Sicherheitsprüfungen, vollständiger Plugin-Regressionstest
  (1870 Klicks) und statische Prüfung ohne Befund.
