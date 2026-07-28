# Änderungen in Version 7.55.0

## Behoben: Startseite lud erst nach einem Bereichswechsel

Beim Umbau in 7.53 ist versehentlich der komplette Start-Block aus
`app.js` mitentfernt worden. Damit fehlten beim Laden der Seite:

- das **Befüllen der Startseite** (deshalb blieb sie leer, bis man in
  einen anderen Bereich und wieder zurück wechselte),
- das **Aktivieren des ersten sichtbaren Reiters** je Reitergruppe —
  ohne das blieb in Bereichen, in denen der fest vorbelegte Reiter für
  den Nutzer nicht sichtbar ist, gar kein Inhalt stehen,
- die **Wiederherstellung der zuletzt besuchten Stelle** nach einem
  Neuladen,
- die **Wiederherstellung angefangener Formulare** und eines offenen
  Eingabefensters,
- das Setzen der Vollbild-Klasse auf `<html>`.

Der Block ist wiederhergestellt und auf die neue Startseite umgestellt.

## Startseite

- **Schnellzugriff steht jetzt ganz oben**, direkt unter der
  Profilzeile.
- Der **„Anpassen"-Knopf ist entfallen** — die Auswahl läuft über das
  Zahnrad unter „Widgets".
- Die Symbole tragen jetzt **kurze Beschriftungen** („Mannschaften"
  statt „Mannschaftsverwaltung"), damit nichts mehr mitten im Wort
  umbricht.
- Die beiden **runden Schaltflächen sind größer** (54 statt 44 Pixel,
  Symbole 26 statt 20) und heben sich mit einem leichten Schatten
  deutlicher ab. Der Zähler für ungelesene Nachrichten ist mitgewachsen.

## Nachrichten

- **Profilbilder im Chat.** Jede Nachricht zeigt das Bild des
  Absenders, eigene wie fremde. Ohne hinterlegtes Bild erscheinen die
  Initialen. Bei mehreren Nachrichten hintereinander von derselben
  Person wird das Bild nur einmal gezeigt. Ein frisch hochgeladenes
  Profilbild erscheint sofort, ohne Neuladen.
- **Anhänge sind deutlich besser erkennbar.** Sie stehen jetzt als
  eigene Zeile mit Rahmen und Pfeil-Symbol in der Nachricht. In den
  eigenen (blauen) Nachrichten waren sie zuvor dunkelblau auf blau und
  damit kaum zu sehen — die Schrift ist dort jetzt weiß.
- **Lesbarkeit der eigenen Nachrichten.** Dunkelblauer Text auf blauem
  Grund erreichte nur 4,4:1 Kontrast. Der Grund ist jetzt dunkler und
  die Schrift weiß (7,2:1).

## Datei-Uploads

Profilbild- und Chat-Anhang-Upload fordern die Antwort jetzt
ausdrücklich als JSON an. Ohne diese Angabe wertet der Browser eine
Antwort mit unerwartetem Kopf als Text; der Upload galt dann als
gescheitert, obwohl der Server die Datei gespeichert hatte. Fehler des
Servers werden außerdem im Klartext angezeigt statt als allgemeines
„Upload fehlgeschlagen".

Zusätzlich wird beim Chat-Anhang geprüft, ob die Konversation bekannt
ist, bevor hochgeladen wird — sonst kam beim Server eine Anfrage ohne
Zuordnung an.

## Datenbank

Keine Änderungen.
