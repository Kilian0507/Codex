# Änderungen in Version 7.54.0

## Bestzeiten-Import: Schwimmer suchen statt auswählen

In der Zuordnungs-Vorschau stand bisher pro Zeile eine Auswahlliste mit
allen Schwimmern des Systems. Bei vielen Schwimmern war das
unübersichtlich. Jetzt steht dort ein **Suchfeld**:

- Tippen filtert die Schwimmer sofort. Gesucht wird über Nachname,
  Vorname, Jahrgang und Mannschaft — auch mit mehreren Wortteilen
  (`müller jo` findet „Müller, Jonas“).
- Auswahl per Maus oder Tastatur (Pfeiltasten, Enter, Esc).
- Das **×** hebt eine Zuordnung wieder auf; die Zeile wird dann beim
  Import übersprungen.
- Freitext ohne Auswahl wird beim Verlassen des Feldes verworfen, der
  zugeordnete Name erscheint wieder — so bleibt nie eine halbe Eingabe
  stehen.

**Auch automatisch erkannte Zeilen** haben jetzt ein Suchfeld. Bisher
war eine als „OK“ erkannte Zeile fest verdrahtet; ein falscher Treffer
ließ sich nicht mehr korrigieren.

## Behobene Fehler

**Vier Auswertungen konnten sich dauerhaft aufhängen.** Lieferte der
Server eine Antwort ohne die erwartete Liste, brach die Verarbeitung mit
einem JavaScript-Fehler ab. Weil dadurch auch das Zurücksetzen des
Knopfes übersprungen wurde, blieb dieser als „…“ deaktiviert stehen und
war bis zum Neuladen der Seite nicht mehr benutzbar. Betroffen waren:

- Anwesenheits-Statistik (`Laden`)
- Wettkampf-Statistik (`Laden`)
- Jahresübersicht des Kassenwarts (`Laden`)
- Springer-Übersicht

Alle vier arbeiten jetzt mit einer leeren Liste weiter und zeigen
„Keine Daten“ an.

## Geprüft

Alle 126 Schaltflächen mit ID wurden in einer gerenderten Vollansicht
automatisiert angeklickt. Danach: keine JavaScript-Fehler und keine
Schaltfläche, die deaktiviert zurückbleibt. Zusätzlich geprüft, dass
keine Handler an Elemente gebunden sind, die es nicht (mehr) gibt oder
die per JavaScript neu erzeugt werden — beides führt zu Knöpfen ohne
Funktion.

## Datenbank

Keine Änderungen.
