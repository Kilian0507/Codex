# Änderungen in Version 6.5.0 (Bugfix-Runde 2)

## Neu: Wettkampf-Anwesenheit

Wettkämpfe können über das Anwesenheits-Modul erfasst werden.
Anwesende Trainer bekommen automatisch einen Eintrag in der Quartalsabrechnung.

### Funktionen
- **Wettkämpfe anlegen** (Name, Ort, Datum von/bis, teilnehmende Mannschaften,
  geplante Abschnitte pro Tag)
- **Anwesenheit pro Wettkampftag** erfassen — Default: alle abwesend
- **Abschnitte-Feld pro Trainer** (wie viele Abschnitte er an dem Tag begleitet hat)
- **Automatische Abrechnung**: Abschnitte × Pauschale → Eintrag in der Abrechnung
- **Bidirektional**: Trainer abwesend → Eintrag wieder weg
- **Manuelles Nachtragen**: Button „+ Wettkampftag aus Anwesenheit"
  in der Abrechnung listet alle offenen Tage auf
- **Statistik**: eigene Anwesenheits-Quote für Wettkämpfe

## Geändert: Trainings-Anwesenheit

- **UI vereinfacht**: Nur noch **Mannschaft + Datum** wählen (vorher: Slot+Datum).
  Das Backend findet den passenden Slot anhand des Wochentags automatisch.
- **Schwimmer stehen standardmäßig auf abwesend** (vorher: anwesend)
- **Bidirektionale Abrechnungs-Sync**: wird ein Trainer nachträglich auf
  abwesend gesetzt, werden seine automatisch erzeugten Trainingstage
  (inkl. Springer-Einsätze) wieder aus der Abrechnung entfernt

## Bugfixes gegenüber der ersten 6.5.0-Version

- **CSS-Klassen-Konflikt behoben**: `wk-edit`/`wk-del` wurden sowohl für
  Abrechnungs-Einträge als auch für die Wettkampf-Verwaltung verwendet.
  Das führte dazu, dass ein Klick auf „Bearbeiten" in der Abrechnung
  zusätzlich den Wettkampf-Stammdaten-Editor öffnete. Jetzt getrennt.
- **Sync ohne `bereich`-Filter**: Springer-Stunden konnten in eine
  Triathlon-Abrechnung geraten, wenn der Trainer eine hatte. Jetzt
  explizit auf `bereich = 'schwimmen'` gefiltert.
- **Reguläre Trainer bekamen keinen automatischen Eintrag** beim
  Anwesend-Klick, nur Springer. Neue Sync-Methoden decken beide Fälle ab.
- **„Wettkampftag aus Anwesenheit" nachtragen** verknüpfte den Eintrag
  nicht mit dem Wettkampftag → Tag blieb in der Offene-Liste und wurde
  beim nächsten Abrechnungs-Laden erneut angelegt. Jetzt verknüpft.
- **Berechtigungsprüfung** für `wk_anw_save_eintrag` und
  `wk_anw_save_abschnitte` ergänzt: fremde Mannschaften können nicht
  mehr manipuliert werden.
- **Pauschale-Snapshot** beim Nachtragen: Betrag wird aus dem
  Wettkampf-Snapshot, nicht aus der aktuellen Config gerechnet.

## Datenbank

Neue Tabellen:
- `lsv07i_wettkampf`
- `lsv07i_wettkampf_mannschaft`
- `lsv07i_wettkampf_tage`
- `lsv07i_wettkampf_anwesenheit`
- `lsv07i_wettkampf_anw_eintraege`

Erweiterte Tabelle `lsv07i_abr_wettkampf`:
- `wettkampf_tag_id`, `mannschaft_id`, `quelle`, `manuell_geloescht`

Alle Migrationen sind idempotent.
