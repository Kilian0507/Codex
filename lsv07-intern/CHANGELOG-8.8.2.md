# LSV07 Interner Bereich — 8.8.2

## Dokumentenupload: hängende Anfragen enden jetzt sichtbar

Konkrete Rückmeldung zum gemeldeten Fehler: Der Hochladen-Knopf wird beim
Klick grau (deaktiviert), es erscheint keine Fehlermeldung, und danach
passiert nichts mehr. Das ist ein anderes Bild als die bisherigen
Tests zeigten — es deutet darauf hin, dass die Anfrage an den Server
irgendwo unbeantwortet hängen bleibt (z. B. durch eine Firewall, ein
Sicherheits-Plugin oder einen Server-Timeout), statt regulär mit Erfolg
oder Fehler zurückzukommen. Bisher hatte keine der Datei-Upload-Anfragen
im Plugin ein Zeitlimit gesetzt — blieb eine Antwort aus, blieb der
Knopf für immer deaktiviert, ganz ohne Hinweis, exakt wie beschrieben.

Behoben: Alle Datei-Upload-Anfragen im Plugin (Profilbild, Schwimmer-Datei
im Profil und im Admin-Bearbeiten-Fenster, Wettkampf-Dokument,
Chat-Anhang, Ticket-Anhang) haben jetzt ein Zeitlimit von 30 Sekunden.
Bleibt die Antwort so lange aus, bricht die Anfrage sauber ab, der Knopf
wird wieder aktiv, und es erscheint eine klare Meldung: „Zeitüberschreitung
— der Server hat nicht rechtzeitig geantwortet. Möglicherweise blockiert
eine Firewall oder ein Sicherheits-Plugin die Anfrage."

**Wichtig zur Einordnung:** Das behebt eine blockierte/unbeantwortete
Anfrage nicht ursächlich — es sorgt nur dafür, dass sie nach 30 Sekunden
sichtbar fehlschlägt statt für immer stumm hängen zu bleiben. Erscheint
beim nächsten Versuch diese neue Zeitüberschreitungs-Meldung, ist das ein
wertvoller nächster Hinweis: Es bestätigt, dass die Anfrage den Server
gar nicht (rechtzeitig) erreicht bzw. beantwortet — das würde eher auf
eine Firewall/ein Sicherheits-Plugin/Hosting-Einstellung hindeuten als auf
einen Fehler im Plugin selbst. Erscheint stattdessen eine andere Meldung
oder weiterhin gar keine, bitte kurz Bescheid geben.

## Tests

- Neuer End-to-End-Test (echter Browser, echte jQuery-Zeitüberschreitung):
  Eine hängenbleibende Upload-Anfrage wird nach Ablauf des Zeitlimits
  sauber abgebrochen, der Knopf reaktiviert sich, und die neue klare
  Meldung erscheint — mit einer testweise verkürzten Zeitspanne, um die
  echte jQuery-Timeout-Logik in Sekunden statt 30 Sekunden zu prüfen.
- Vollständiger Plugin-Regressionstest (5 Rollen × 2 Design-Varianten)
  und statische Prüfung ohne neuen Befund.
- Bestehende Tests zu "Aus Liste entfernen" bei Nachrichten (8.8.1)
  erneut gegen den aktualisierten Stand geprüft, weiterhin fehlerfrei.
