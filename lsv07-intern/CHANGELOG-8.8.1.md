# LSV07 Interner Bereich — 8.8.1

## Bugfix: "Aus Liste entfernen" bei Nachrichten

Gefunden und behoben: Eine Unterhaltung aus der eigenen Liste zu entfernen
bzw. eine Gruppe zu verlassen verlangte serverseitig fälschlich das Recht
„Nachricht senden" statt nur „Nachrichten lesen". Ein Nutzer mit einem
reinen Lese-Zugang zu Nachrichten (ohne eigenes Senderecht) konnte eine
Unterhaltung dadurch nie aus seiner Liste entfernen — der Klick blieb
wirkungslos. Jetzt reicht das Lese-Recht, wie es für eine reine
Selbst-Entfernung auch sachlich richtig ist.

## Dokumentenupload bei Mannschaften (Schwimmen)

Erneut geprüft — sowohl der Ablauf über die Mannschaftsliste (Schwimmer
antippen → Profil → „+ Datei hochladen") als auch das Backend liefen in
allen Tests weiterhin fehlerfrei durch. Ich konnte den Fehler diesmal
nicht reproduzieren und finde im Code keine weitere Ursache. Um
gezielt weitersuchen zu können, wäre hilfreich:

- Erscheint beim Hochladen eine Fehlermeldung (roter Hinweis), oder
  passiert einfach gar nichts?
- Ist der „+ Datei hochladen"-Knopf im Schwimmer-Profil überhaupt sichtbar?
- Mit welcher Rolle bist du dabei eingeloggt (Admin oder Trainer-Konto)?

## Tests

- 9 neue Backend-Prüfungen für "Aus Liste entfernen" (gegen eine echte
  SQLite-Datenbank): Direkt-Chat verschwindet nur aus der eigenen Liste,
  bleibt bei der Gegenseite erhalten, doppeltes Entfernen liefert einen
  sauberen Fehler statt eines Absturzes, fremde Konversationen lassen
  sich nicht entfernen, und gezielt der Bugfix — ein Nutzer mit nur
  Lese-, ohne Senderecht kann jetzt trotzdem entfernen.
- 11 neue Oberflächen-Prüfungen: Menü öffnen, korrekter Text je nach
  Direkt-Chat/Gruppe, Entfernen-Request, Liste aktualisiert sich korrekt.
- Vollständiger Plugin-Regressionstest und statische Prüfung ohne Befund.
