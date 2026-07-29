# Änderungen in Version 7.60.0

## Schwimmen: Navigation als Symbolkacheln

Statt der Reiterleiste steht oben im Schwimmbereich jetzt eine Kachel mit
**Symbolen** — wie der Schnellzugriff auf der Startseite:
Mannschaften · Anwesenheit · Wettkämpfe · Springer · Bestzeiten ·
Reflexion · Trainer.

Der aktive Bereich ist durch eine blau gefüllte Kachel deutlich markiert.
Darunter öffnet sich der jeweilige Inhalt. Auf dem Handy stehen die
Symbole in einem Raster, das sich an die Breite anpasst — kein
seitliches Scrollen mehr durch eine zu lange Reiterleiste.

Es werden weiterhin nur die Bereiche gezeigt, für die eine Berechtigung
vorliegt.

## Mannschaftsverwaltung: auf das Wesentliche reduziert

Die Tabelle mit fünf Spalten (Name, Geburtsdatum, Attest, Dateien,
Kontaktpersonen) ist einer schlanken Liste gewichen. Pro Schwimmer stehen
nur noch:

- **Name**
- **Attest-Ablaufdatum**, davor ein farbiger Punkt:
  grün = gültig, orange = läuft bald ab, rot = abgelaufen,
  grau = kein Attest hinterlegt

Ein Tippen auf die Zeile öffnet wie bisher das vollständige Profil mit
Geburtsdatum, Kontaktpersonen, Dateien, Bestzeiten und
Anwesenheitsquote. Die Zeilen sind 56 Pixel hoch und auch mit der
Tastatur bedienbar (Tab, dann Enter). Im Kartenkopf steht die Zahl der
Schwimmer je Mannschaft.

## Behobene Fehler

Beim Durchklicken aller Reiter sind drei Abstürze aufgefallen, die die
jeweilige Ansicht leer zurückließen:

- **Trainingszeiten (Admin)**: Die Liste stürzte ab, wenn der Server
  keine Zeiten mitschickte.
- **Stammdaten**: Lieferte der Server statt einer Liste ein Objekt,
  brach die Filterung ab.
- **Schwimmer-Profil**: Fehlte ein Block in der Antwort (z. B. die
  Anwesenheits-Werte), blieb das Fenster leer.

Ebenfalls abgesichert: Reflexionsbogen und Ticket-Detail.

### Reiter-Umschaltung robuster

Die Umschaltung suchte die Panels immer im direkten Elternelement der
Reiterleiste. Sobald die Navigation — wie jetzt — in einer Karte steckt,
wäre nichts mehr umgeschaltet worden. Sie sucht nun den passenden
Container selbst und funktioniert dadurch auch bei verschachtelten
Unter-Reitern (Abrechnung, Kassenwart) unverändert.

## Datenbank

Keine Änderungen.
