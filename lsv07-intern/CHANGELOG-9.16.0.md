# Interner Bereich 9.16.0: Daten der Nachrichtenfunktion gelöscht

Die Nachrichtenfunktion ist seit 9.12.0 entfernt. Ihre gespeicherten Daten
blieben bis zur Entscheidung des Vereins liegen. Der Verein hat
entschieden: **Alles wird gelöscht.**

**Das Löschen ist endgültig.** Wer die Daten noch für eine Sicherung
braucht, muss vor dem Update eine Datenbank- und Dateisicherung machen.

## Was beim Update gelöscht wird

Es läuft einmal automatisch beim ersten Seitenaufruf nach dem Update:

* **Tabellen** der Unterhaltungen, Teilnehmer, Nachrichten, Anhänge,
  Reaktionen, der Mail-Warteschlange und die alte Nachrichtentabelle
  (`lsv07i_konv*`, `lsv07i_email_queue`, `lsv07i_nachrichten`).
* **Anhänge**: der ganze Ordner `lsv07i-private/nachrichten/` samt
  Unterordnern.
* **IMAP-Zugangsdaten** (Server, Benutzer, **Passwort**, Port) und die
  Antwort-Domain aus den Einstellungen.
* der **Online-Status** („zuletzt gesehen“) aller Nutzer.
* zwischengespeicherte „tippt …“-Anzeigen, Antwortzähler und der
  Übernahme-Merker.
* die **Protokolleinträge** der Nachrichtenfunktion.

Es bleibt ein einziger Protokolleintrag „Reste der Nachrichtenfunktion
gelöscht“. Er enthält nur Zahlen, keine Inhalte, Namen oder Adressen.

**Nicht berührt:** Tickets mit ihren Anhängen, Benachrichtigungen (die
Glocke), Kontakte, alle anderen Einstellungen, Protokolleinträge und
Nutzerdaten.

## Sicherheit beim Löschen

* Verknüpfungen (Symlinks) werden nie verfolgt, sondern nur selbst
  entfernt. Ist der Ordner selbst eine Verknüpfung, wird nur sie
  entfernt. Gelöscht wird nur, was wirklich im privaten Ordner liegt.
* Fehlt dem Datenbank-Benutzer das Recht, Tabellen zu löschen, wird
  wenigstens ihr Inhalt gelöscht. Das Protokoll nennt die betroffenen
  Tabellen. Beim nächsten Update wird es erneut versucht, bis alles weg
  ist. Erst dann gilt das Löschen als erledigt.
* Wiederholbar: Was schon weg ist, wird übersprungen.

## Datenschutz-Auskunft

Auskunft und Löschen auf Anfrage fragen die Nachrichten-Tabellen nicht
mehr ab, weil es sie nicht mehr gibt. Die Texte für die
Datenschutzerklärung nennen statt „Nachrichten“ jetzt „Tickets“.

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Löschen gegen echtes WordPress 6.8.3: Update per Seitenaufruf; alle Tabellen, Dateien bis in Unterordner, Einstellungen, Online-Status, Zwischenspeicher und Protokoll; was bleiben muss; Fallen mit Verknüpfungen auf Datei, Ordner und den Ordner selbst; ohne DROP-Recht; kein zweiter Lauf; Neuinstallation | 44 |
| Datenschutz-Auskunft und -Löschung (ohne Nachrichten-Tabellen) | 49 |
| Version 9.12.0, angepasst: Statt „Nachrichten werden nicht gelöscht“ jetzt „gelöscht“ | 24 |

**Gegenprobe:** Jede Regel wurde einmal im echten Plugin gebrochen, und
der Lauf muss rot werden: **21 von 21 rot**, nach dem Zurücksetzen wieder
grün. Ein Bruch („Protokoll nennt Inhalte“) blieb zunächst grün. Der Bruch
war wirkungslos gebaut, und die Prüfung sah nur in ein Feld des Eintrags.
Beides ist geschärft, danach lief die ganze Gegenprobe erneut.

Bisherige Prüfungen grün: Oberfläche 210, Trainingsplan 33 + 91,
Abzeichen 52 + 101, Mannschaftsbuch, Zuständigkeit 59.
