# LSV07 Interner Bereich — 8.7.0

## Neu: Benachrichtigungen

Auf der Startseite steht oben rechts jetzt eine Glocke statt der
Nachrichten-Verknüpfung. Sie zeigt eine rote Zahl mit der Anzahl noch
ungelesener Benachrichtigungen und öffnet beim Antippen die Liste. Jede
Benachrichtigung lässt sich einzeln über „Als gelesen markieren"
bestätigen.

Der Zugang zu Nachrichten (Chat) bleibt unverändert erhalten — dafür gibt
es weiterhin den eigenen Reiter „Nachrichten" oben in der Navigation,
inklusive seines eigenen Zählers für ungelesene Nachrichten. Die Glocke
auf der Startseite war bisher nur eine Abkürzung dorthin und wird jetzt
für die neue, eigenständige Funktion genutzt.

### Erstellen (Admin)

Neuer Admin-Bereich „Benachrichtigungen" (unter Verwaltung, neben
„Mails"): Titel und Text eingeben, senden — erscheint sofort für alle
Nutzer. Zeilenumbrüche und Absätze im Text bleiben erhalten.

### Lesebestätigung

Der Admin sieht zu jeder Benachrichtigung, wer sie bereits gelesen hat
(Name + Zeitpunkt), über einen aufklappbaren Knopf direkt in der Liste.
Benachrichtigungen lassen sich dort auch wieder löschen.

## Bestehende Funktion geprüft: Dokumentenupload bei Mannschaftsverwaltung

Zur gemeldeten Störung beim Datei-Upload in der Mannschaftsverwaltung:
Der Ablauf (Mannschaftsverwaltung → Schwimmer antippen → Profil öffnet
sich → „+ Datei hochladen") wurde vollständig geprüft — einmal die
Oberfläche über einen echten Browser-Testlauf (Klicken, Datei auswählen,
Hochladen-Knopf), einmal das Backend über einen echten HTTP-Upload
gegen einen laufenden PHP-Server (inklusive echter Dateiverschiebung,
MIME-Prüfung und Download). Beides funktionierte fehlerfrei.

Gefunden wurde dabei allerdings eine verwaiste zweite Upload-Ansicht
(„Dateien"-Lese-Modal), die im Code zwar vollständig vorhanden ist, aber
von nirgendwo mehr aus der Oberfläche erreichbar war — vermutlich ein
Rest aus einer früheren Version der Mannschaftsverwaltung. Sie wurde
entfernt, um Verwirrung beim Weiterentwickeln zu vermeiden.

Falls der Upload weiterhin nicht funktioniert, wäre hilfreich zu wissen:
welche Fehlermeldung genau erscheint (falls eine erscheint), und mit
welcher Rolle (Admin/Trainer/…) das passiert — damit lässt sich die
Ursache gezielt eingrenzen.

## Tests

- 23 neue Backend-Prüfungen für Benachrichtigungen (gegen eine echte
  SQLite-Datenbank mit den echten SQL-Texten): Rechte-Gate für Nutzer- und
  Admin-Endpunkte getrennt, Pflichtfelder, Sichtbarkeit und
  ungelesen-Zähler pro Nutzer unabhängig voneinander, Als-gelesen-Markieren
  ist idempotent (kein Duplikat bei erneutem Klick), Absätze im Text
  bleiben erhalten, Lesebestätigung zeigt die richtigen Namen, Löschen
  entfernt auch die zugehörigen Lesebestätigungen.
- 23 neue Oberflächen-Prüfungen: Glocke statt Nachrichten-Knopf, Badge
  erscheint/verschwindet korrekt, Ansicht, Als-gelesen-Markieren,
  Admin-Erstellen mit Absätzen, Lesebestätigung aufklappen, Löschen.
- Bestehende 209 Backend- und 85 Oberflächen-Prüfungen aus früheren
  Versionen erneut ausgeführt und weiterhin grün; vollständiger
  Plugin-Regressionstest (1962 Klicks) und statische Prüfung ohne Befund.
