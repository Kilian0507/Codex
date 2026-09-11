# LSV07 Interner Bereich — 8.19.1

## Admin → Trainer: WordPress-Konto lässt sich wieder zuordnen

Unter **Admin → Trainer** ließ sich das WordPress-Konto eines Trainers nicht
mehr ändern — das Speichern ging ins Leere.

### Warum

Ein Konto gehört zu genau einem Trainer-Profil. Bisher wurde das Speichern
**schlicht abgelehnt**, sobald irgendein anderes aktives Profil dasselbe
Konto trug — ohne jeden Weg, das geradezuziehen. Hing das Konto an einem
Profil, das aus Sicht der Administration gar nicht mehr gelten sollte (etwa
an einem der früher doppelt angelegten, siehe 8.18.0), war die Zuordnung
damit dauerhaft festgefahren.

Verschärft wurde das durch zwei weitere Punkte:

- **Ein stillgelegtes Profil behielt seinen Konto-Verweis.** Ordnete man das
  Konto einem anderen Trainer zu, blieb der alte Verweis stehen — und holte
  das stillgelegte Profil beim nächsten Öffnen der Abrechnung von selbst
  zurück (seit 8.18.0 wird ein stillgelegtes Profil wiederbelebt statt ein
  zweites anzulegen). Danach blockierte es die Zuordnung erneut.
- **Ein fehlgeschlagenes Schreiben wurde als Erfolg gemeldet.** Der
  Rückgabewert des Datenbank-Updates wurde nicht geprüft. Schlug das
  Schreiben fehl, meldete die Oberfläche trotzdem „Trainer gespeichert",
  während in der Datenbank alles beim Alten blieb.

### Behoben

- **Die ausdrückliche Zuordnung gewinnt.** Wählt die Administration ein
  Konto, wird es dem anderen Profil entzogen, statt das Speichern zu
  verweigern.
  - Hängt es an einem **aktiven** Trainer, wird vorher gefragt: die
    Rückfrage nennt den jetzigen Inhaber mit Namen und sagt, dass dieser die
    Konto-Verknüpfung verliert. Ohne Bestätigung ändert sich nichts.
  - Hängt es an einem **stillgelegten** Profil, wird der Verweis sofort
    gelöst — ein stillgelegtes Profil ist ohnehin nicht sichtbar, und genau
    dieser Verweis war es, der es zurückgeholt hat.
  - Liegen mehrere Alt-Verweise auf demselben Konto (aus den früheren
    Doppelprofilen), werden **alle** gelöst. Danach hängt ein Konto
    garantiert an genau einem Profil.
  - Jeder Entzug steht im Protokoll (`trainer.konto_entzogen`).
- **Ein fehlgeschlagenes Speichern meldet einen Fehler** und nennt ihn als
  Datenbankfehler, statt Erfolg vorzutäuschen. („Nichts verändert" gilt
  weiterhin als Erfolg.)

### Geprüft

- 28 Prüfungen gegen eine echte Datenbank mit den echten SQL-Texten:
  Zuordnen, Wechseln und Entfernen eines Kontos, unverändertes Speichern,
  die Rückfrage samt Inhaber-Name, dass bis zur Bestätigung nichts passiert,
  das Umhängen nach Bestätigung, das stillgelegte Profil, mehrere
  Alt-Verweise auf einem Konto, und der Nachweis, dass ein fehlgeschlagenes
  Schreiben tatsächlich als Fehler herauskommt und nichts verändert.
- **Gegenprobe:** mit dem alten Stand fallen 10 dieser 28 Prüfungen durch —
  darunter „mit Bestätigung lässt sich das Konto umhängen" (vorher
  unmöglich) und „ein fehlgeschlagenes Speichern meldet einen Fehler".
- 24 Prüfungen in der echten Oberfläche: gefüllte Konto-Auswahl, Zuordnen
  eines freien Kontos, die Rückfrage mit Ablehnen (keine zweite Anfrage,
  Fenster bleibt offen, Auswahl bleibt stehen) und mit Bestätigen (zweite
  Anfrage mit Übernahme-Kennzeichen), sowie die Fehlermeldung bei einem
  echten Fehler.
- Bestehende Prüfungen unverändert grün: Abrechnung (30 + 19 + 23),
  Trainingsplan (68 + 58 + 41 + 5 + 51), statische Prüfung, Klick-Durchlauf
  über alle fünf Nutzerarten (90 Klicks, 0 Fehler).
