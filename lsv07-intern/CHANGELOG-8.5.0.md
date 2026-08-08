# LSV07 Interner Bereich — 8.5.0

## Akte neu gestaltet: eine PDF-Seite pro Sportler, deutlich mehr Inhalt

Die bisherige Akte reihte alle Personen in einem gemeinsamen PDF aneinander
und brach die Seite nur bei Platzmangel um — dadurch konnten sich Personen
über zwei Seiten hinweg teilen. Der PDF-Export erzeugt jetzt **pro Sportler
genau eine eigene Seite** (erzwungener Seitenumbruch vor jeder Person außer
der ersten), mit fester Fuß-Reserve, sodass eine Seite nie überläuft. Bei
ungewöhnlich vielen Einträgen (z. B. sehr viele Wettkämpfe in einer Saison)
werden Listen kompakt gehalten und mit „… und N weitere" ergänzt, statt die
Seite zu sprengen.

Inhaltlich wurde die Akte um das erweitert, was für ein Sportler-Dossier
sinnvoll erschien:

- **Attest-Status** (Gültig/Läuft ab/Abgelaufen/Kein Attest) inkl.
  Ablaufdatum — bisher fehlte diese Information komplett.
- **DSV-ID** in den Stammdaten.
- **Primäre Kontaktperson** (Name, Telefon, E-Mail) — die erste hinterlegte
  Kontaktperson.
- **Wettkampf-Teilnahmen im gewählten Zeitraum**, zusammengeführt aus den
  abgegebenen Meldungen: je Wettkampf Name, Ort, Datum und die gemeldeten
  Strecken (inkl. Meldezeit). Das verbindet die bisher getrennten Bereiche
  Meldungen/Wettkämpfe erstmals mit der Akte.

Unverändert: Anwesenheit je Mannschaft im Zeitraum, aktuelle Bestzeiten,
Freitext-Kommentar sowie das bestehende Zugriffsscoping (nur eigene
Mannschaften, eigenes Recht ohne Rollen-Fallback).

Die Bildschirm-Ansicht (vor dem PDF-Export) zeigt dieselben neuen Angaben
zusätzlich zur bisherigen Anwesenheits-/Bestzeiten-Übersicht an.

## Tests

- 13 neue Backend-Prüfungen für die neuen Akte-Inhalte: Attest-Status in
  allen vier Zuständen, DSV-ID-Durchreichung, primäre Kontaktperson
  (mehrere Kontakte vorhanden → nur die erste wird geliefert, keine
  Kontaktperson → `null`), Wettkampf-Teilnahmen inklusive
  Zeitraum-Filterung (ein Wettkampf außerhalb des Zeitraums wird korrekt
  ausgeschlossen) und Mehrfach-Strecken pro Meldung.
- Bestehende 43 Akte-/Wettkampf-Prüfungen aus 8.1.0 erneut ausgeführt und
  weiterhin grün (Zugriffsscoping, Anwesenheit je Session-Mannschaft,
  Bestzeiten, Zeitraum-Validierung, öffentliche Wettkampf-Seite).
