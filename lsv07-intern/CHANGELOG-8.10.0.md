# LSV07 Interner Bereich — 8.10.0

## Neu: Staffeln melden

In der Meldeliste (Schwimmen → Meldungen, Schritt 3) gibt es jetzt unter der
Tabelle den Knopf **„+ Staffel melden"**. Damit kommt eine Staffelzeile in die
Meldung, die nur die drei Angaben verlangt, die eine Staffel ausmachen:

- **Abschnitt** — Auswahlliste wie bei den Einzelstarts
- **Wettkampfnummer** — Auswahlliste wie bei den Einzelstarts
- **Strecke** — ein **freies Eingabefeld**, weil Staffeln je nach
  Ausschreibung sehr unterschiedlich heißen (z. B. „4x50 Freistil" oder
  „4 x 100 Lagen mixed"). Hier gilt bewusst keine feste Auswahlliste.

Schwimmer/in, Jahrgang, Attest und Meldezeit entfallen bei einer Staffel —
diese Felder sind in der Zeile mit „–" gekennzeichnet. Die Staffelzeile ist
in der Tabelle leicht hinterlegt, damit sie sich auf einen Blick von den
Einzelstarts unterscheidet. Über „Entfernen" am Zeilenende lässt sie sich
wieder löschen.

Staffeln hängen an keinem Schwimmer. Sie zählen deshalb nicht in die
Startzahlen der Schwimmerauswahl (Schritt 2) hinein und bleiben erhalten,
wenn die Schwimmerauswahl nachträglich geändert wird.

**Excel-Export:** Staffeln erscheinen als eigene Zeile mit „Staffel" in der
Namensspalte, den beiden Nummern und der eingetippten Streckenbezeichnung im
Klartext.

### Technischer Hinweis zur Aktualisierung

Die Startliste bekommt eine neue Spalte (`ist_staffel`), und das Feld für die
Strecke wird von 10 auf 60 Zeichen verbreitert, damit auch längere
Staffelbezeichnungen hineinpassen. Beides passiert automatisch beim ersten
Aufruf des Meldungen-Bereichs — bestehende Meldungen bleiben unverändert
erhalten und gelten weiterhin als Einzelstarts.

## Tests

- 19 neue Backend-Prüfungen gegen eine echte SQLite-Datenbank mit den echten
  SQL-Texten: Die Umstellung des alten Tabellenschemas läuft sauber durch,
  eine Staffel wird korrekt ohne Schwimmer, Name, Meldezeit und Attest
  gespeichert, die frei eingetippte Strecke landet im Klartext in der
  Datenbank (während sie bei einem Einzelstart weiterhin gegen die
  Streckenliste geprüft und sonst verworfen wird), HTML wird entfernt,
  überlange Bezeichnungen werden gekürzt, Abschnitt und Wettkampfnummer
  werden wie bei Einzelstarts gegen die Obergrenzen der Meldung geprüft, und
  ein daneben stehender Einzelstart bleibt unverändert korrekt.
- 20 neue Oberflächen-Prüfungen im echten Browser: Knopf vorhanden, Zeile
  wird angelegt und als Staffel gekennzeichnet, Strecke ist ein freies
  Eingabefeld statt einer Auswahlliste, Jahrgang/Attest/Meldezeit fehlen
  korrekt, die Eingaben werden richtig an den Server übermittelt, der
  Einzelstart daneben bleibt erhalten, und das Entfernen funktioniert.
- Bestehende Meldungs-Testsuite (65 Prüfungen) weiterhin vollständig grün;
  vollständiger Plugin-Regressionstest und statische Prüfung ohne neuen Befund.
