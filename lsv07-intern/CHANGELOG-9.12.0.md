# Interner Bereich 9.12.0 — ohne Nachrichtenfunktion, Tri/Fit-Korrekturen

## Nachrichtenfunktion entfernt

Die komplette Nachrichtenfunktion ist aus dem internen Bereich entfernt:

* Chat (1:1, Gruppen, Verteiler, Mannschafts-Verteiler), mit Anhängen,
  Reaktionen, „tippt …“ und Online-Status,
* E-Mail-Versand von Chat-Nachrichten an Externe (Eltern, freie Adressen),
* Abruf von Antworten per IMAP,
* Antwort-Links für Externe (Magic Link). Alte Links aus früheren Mails
  führen ins Leere.

Im Einzelnen:

* Die Schaltfläche „Kommunikation“ heisst jetzt **„Tickets“** und führt
  direkt dorthin.
* Die Kachel „Posteingang“ auf der Startseite ist weg.
* Die Nachrichten-Rechte sind aus der Rechteverwaltung entfernt. Schon
  vergebene werden beim Update gelöscht.
* Die Hintergrund-Aufgaben für E-Mail-Versand und IMAP werden beim Update
  abgemeldet.
* Neue Installationen legen keine Chat-Tabellen mehr an.
* Wer zuletzt in den Nachrichten war, landet beim nächsten Öffnen bei den
  Tickets.

**Tickets** und **Benachrichtigungen** (die Glocke) bleiben, wie sie sind.

**Gespeicherte Nachrichten werden nicht ungefragt gelöscht.** Sie liegen
weiter in der Datenbank, die Anhänge verschlüsselt im privaten Ordner. Die
Datenschutz-Auskunft und das Löschen auf Anfrage erfassen sie weiterhin.
Ob sie endgültig gelöscht werden sollen, entscheidet der Verein.

## Triathlon und Fitness: zwei alte Fehler behoben

* **„Rückgängig“ beim Trainingsausfall** hat nie funktioniert. Der Knopf
  schickte keine Trainingszeit mit, weil er nur die Gruppe kannte. Jetzt
  hebt er den Ausfall der richtigen Trainingszeit auf.
* **Klick auf eine vergangene Session** setzte die Gruppen-ID als
  Trainingszeit ein. Geladen wurde so eine falsche oder gar keine Session.
  Jetzt wird die Trainingszeit der Session genommen. Gibt es sie nicht mehr
  oder ist sie einem nicht zugeordnet, steht ein Hinweis da, statt dass
  etwas Falsches lädt.
* Dabei gefunden und behoben: Derselbe Klick löste zusätzlich das Laden
  einer **Schwimm**-Session ohne Kennung aus. Der Klick auf Schwimm-Sessions
  gilt jetzt nur noch für die Schwimm-Liste.

## Notfallkopie nur für die WordPress-Administration

Den Knopf „Notfallkopie anzeigen“ sehen nur noch WordPress-Administratoren
(`manage_options`). Der Server liess die Notfallkopie schon vorher nur für
sie zu. Jetzt sehen andere den Knopf auch gar nicht erst. Gleiches gilt in
der Abrechnung (1.18.1).

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Server gegen echtes WordPress 6.8.3 (keine Chat-Endpunkte und keine Antwortseite, alte Aufgaben abgemeldet, Rechte entfernt, Neuinstallation ohne Chat-Tabellen, Startseite, Notfallkopie in beiden Plugins) | 24 |
| Browser (Navigation, Tickets, gemerkter Ort, Notfallkopie in beiden Plugins, Tri/Fit „Rückgängig“ und vergangene Sessions, Schwimm-Sessions) | 31 |

**Gegenprobe:** Jede Regel wurde einmal im echten Plugin gebrochen, und
der Lauf muss rot werden: **17 von 17 rot**, nach dem Zurücksetzen wieder
grün.

Bisherige Prüfungen grün: Oberfläche intern 210 (an die neue Navigation
angepasst), Oberfläche Abrechnung 201, Trainingsplan 28 + 67,
verschlüsselte Dateien, Abrechnungs-Tresor, Zuständigkeit 59, Statistik
270, Meldungen 116, Mails 43, Belege 58, Anmeldung 52, Wettkampf 57,
interne Meldungen 79, Datenschutz 58, Tri/Fit 90, Abrechnung intern 44,
Dateien 16. Die 16 Chat-Fälle der Datei-Prüfung entfallen.
