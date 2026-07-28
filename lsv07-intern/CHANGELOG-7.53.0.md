# Änderungen in Version 7.53.0

## Neue Startseite

Die Startseite ist neu aufgebaut und folgt jetzt dieser Reihenfolge:

**1. Profilzeile.** Links das Profilbild (ohne hochgeladenes Bild die
Initialen), daneben „Willkommen zurück" und der Vorname. Rechts zwei
runde Schaltflächen: Nachrichten (mit Zähler für Ungelesenes) und
Einstellungen.

**2. Letzte 5 Trainings.** Ein Balken je Termin mit anwesend/gesamt und
Datum. Ausgefallene Trainings sind schraffiert dargestellt. Wer mehrere
Gruppen betreut, schaltet oben rechts um; die Wahl wird gemerkt. Der
Umschalter umfasst Schwimmen, Triathlon und Fitness — bei mehreren
Sparten steht die Sparte vor dem Gruppennamen.

**3. Top 5 Schwimmer.** Rangliste aus den Bestzeiten. Da Bestzeiten je
Strecke vorliegen, wird pro Mannschaft und Strecke gewertet: Platz 1
zählt 3 Punkte, Platz 2 zwei, Platz 3 einen. Bei Punktgleichstand
entscheidet die Zahl der ersten Plätze. Die Kachel erscheint nur mit
Zugang zum Schwimmbereich und nur, wenn Bestzeiten vorhanden sind.

**4. Schnellzugriff.** Die Widgets sind jetzt reine Symbole im Stil von
App-Icons — jeder Schnellzugriff hat sein eigenes Zeichen, die Farbe
zeigt den Bereich.

**5. Drei Info-Kacheln.** Abrechnungsbetrag des laufenden Quartals
(über alle Sparten summiert, mit Status), nächster Wettkampf mit Datum
und Ort, nächstes Training mit „heute"/„morgen"/Wochentag und Uhrzeit.

## Neu: Einstellungen

Das Zahnrad in der Profilzeile öffnet einen Dialog mit fünf Bereichen.
Alle Einstellungen gelten nur für das eigene Konto.

- **Darstellung** — Hell, Dunkel oder Automatisch. „Automatisch" folgt
  der Einstellung des Geräts. Die Wahl wirkt sofort und wird beim
  nächsten Laden serverseitig gesetzt, damit nichts in der falschen
  Farbe aufblitzt.
- **Passwort ändern** — mit Prüfung des aktuellen Passworts. Die
  laufende Sitzung bleibt angemeldet.
- **Kontaktdaten** — E-Mail und Telefon. Die Telefonnummer landet im
  verknüpften Personen-Datensatz, sofern einer besteht.
- **Profilbild hochladen** — JPG, PNG oder WebP bis 3 MB. Das Bild wird
  mittig quadratisch zugeschnitten und auf 256 × 256 verkleinert.
- **Widgets anpassen** — wie bisher, jetzt an dieser Stelle.

### Dunkle Darstellung

Der Dunkelmodus setzt dieselben CSS-Variablen neu, die das helle Thema
definiert — alle Komponenten laufen dadurch mit, ohne eigene Regeln.
Zusätzlich abgedunkelt werden Eingabefelder, Tabellenköpfe und die
Kalendersymbole der Browser. Die Umstellung wirkt nur im internen
Bereich, nicht auf dem übrigen WordPress-Theme.

### Profilbilder

Die Dateien liegen unter `uploads/lsv07i-private/profilbilder` und sind
per `.htaccess` gegen Direktzugriff gesperrt; ausgeliefert werden sie
über einen eigenen Endpunkt, der eine Anmeldung voraussetzt. Der Bildtyp
wird am Inhalt geprüft, nicht am Dateinamen — eine als `.php` benannte
Datei kann so nicht als Bild durchgereicht werden.

## Behobener Fehler

**Startseite fehlte ohne Schwimm-Zugang.** Der gesamte Startseiten-Block
lag versehentlich innerhalb der Bedingung für den Schwimmbereich. Wer
nur für Triathlon oder Fitness freigeschaltet war — oder als Kassenwart
nur für die Verwaltung — bekam beim Klick auf „Start" eine leere Seite.
Auch der Hinweis „Sie haben aktuell keine Berechtigung für Schwimmen,
Triathlon oder Fitness" konnte deshalb nie erscheinen.

## Entfernt

Die alten Startseiten-Blöcke je Sparte (feste Kacheln plus vier
Quick-Stats) sind durch die neuen Kacheln ersetzt. Damit entfällt der
Endpunkt `lsv07i_home_stats` samt seiner drei Hilfsmethoden.

## Neue Endpunkte

| Endpunkt | Zweck |
|---|---|
| `lsv07i_home_team_stats` | letzte 5 Trainings einer Gruppe |
| `lsv07i_home_top_swimmers` | Top 5 nach Bestzeiten |
| `lsv07i_home_uebersicht` | Abrechnung, Wettkampf, Training |
| `lsv07i_profil_get` | Daten für den Einstellungs-Dialog |
| `lsv07i_profil_theme` | Darstellung merken |
| `lsv07i_profil_passwort` | Passwort ändern |
| `lsv07i_profil_kontakt` | E-Mail und Telefon ändern |
| `lsv07i_profil_bild` / `_bild_weg` | Profilbild setzen/entfernen |
| `lsv07i_profil_avatar` | Profilbild ausliefern |

## Datenbank

Keine Schema-Änderungen. Die neuen Einstellungen liegen in
`usermeta` (`lsv07i_theme`, `lsv07i_profilbild`, `lsv07i_home_gruppe`).
