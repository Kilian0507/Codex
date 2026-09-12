# LSV07 Interner Bereich — 8.20.0

## Neues Recht: „Alle Mannschaften einsehen — nur lesen, kein Bearbeiten"

In der Rechteverwaltung steht unter **Schwimmen → Mannschaften** ein neues
Recht zur Verfügung:

> **Alle Mannschaften einsehen — nur lesen, kein Bearbeiten**

Bisher sah ein Trainer im Mannschaften-Tab ausschließlich die Mannschaften,
für die er eingetragen ist. Wer einen Überblick über den ganzen Verein
brauchte, musste Schwimmwart oder Administrator werden — und bekam damit
automatisch auch alle Bearbeitungsrechte. Genau diese Lücke schließt das neue
Recht.

### Was es freischaltet

- **Alle Mannschaften** im Mannschaften-Tab, samt der Schwimmer darin und
  ihrer Attest-Zustände.
- Die **Schwimmer-Profile** aller Mannschaften (Anwesenheitsquote, letzte
  Trainings, Bestzeiten, Kontaktpersonen, Notizen) — zum Ansehen.
- Der Mannschaften-Tab wird sichtbar, auch wenn sonst kein anderes
  Schwimmen-Leserecht vergeben ist.

### Was es ausdrücklich nicht freischaltet

- **Kein Bearbeiten von Schwimmerdaten.** Wer das Recht hat, kann Attest,
  DSV-ID, Datenschutz-Haken, Notizen und Kontaktpersonen weiterhin nur bei
  den Mannschaften ändern, für die er selbst als Trainer eingetragen ist.
- **Kein Anlegen, Ändern oder Löschen von Mannschaften** — dafür gibt es
  weiterhin die eigenen Rechte daneben.
- **Keine Anwesenheitserfassung für fremde Mannschaften.** Die Trainings-Slots
  bleiben bewusst auf die eigenen Mannschaften beschränkt: über sie läuft die
  Anwesenheit, und die ist Bearbeiten, nicht Lesen. In der Anwesenheits-Auswahl
  erscheinen daher weiterhin nur die eigenen Mannschaften.

### In der Oberfläche sichtbar

- Über der Mannschaftsliste steht ein Hinweis: „Du siehst hier **alle
  Mannschaften** — nur zum Ansehen. Ändern lassen sich Daten nur in den
  Mannschaften, für die du als Trainer eingetragen bist." Administratoren und
  Schwimmwarte bekommen ihn nicht, für sie ändert sich nichts.
- Im Schwimmer-Profil erscheint der Knopf **„Daten bearbeiten" nur noch dann,
  wenn das Speichern danach auch wirklich durchgeht.** Sonst steht dort ein
  klarer Hinweis auf die Nur-Lese-Ansicht.

  Das behebt nebenbei einen alten Ärger: Bisher bekam **jeder** Trainer den
  Knopf zu sehen, auch bei Schwimmern fremder Mannschaften — und lief dann
  beim Speichern in „Du bist nicht Trainer dieser Mannschaft". Die Prüfung
  beim Speichern und die Anzeige des Knopfes stammen jetzt aus **einer**
  gemeinsamen Stelle und können nicht mehr auseinanderlaufen.

### Geprüft

- 33 Prüfungen gegen eine echte Datenbank mit den echten SQL-Texten: das
  Recht steht mit passender Beschriftung in der Rechteverwaltung und passt in
  die Datenbankspalte; wer alle Mannschaften sieht (Trainer ohne Recht nein,
  mit Recht ja, Admin und Schwimmwart ja); die Mannschaftsliste mit und ohne
  das Recht; dass die Trainings-Slots dabei eng bleiben; dass der Tab auch
  ohne Trainer-Profil sichtbar wird, ohne Admin- oder Schwimmwart-Status zu
  verleihen; und in aller Ausführlichkeit, dass Bearbeiten verwehrt bleibt —
  inklusive des Nachweises, dass `update_schwimmer` tatsächlich abweist und
  nichts geschrieben wird, während die eigene Mannschaft weiter speicherbar
  bleibt.
- 15 Prüfungen in der echten Oberfläche: Hinweis über der Liste (vorhanden,
  an der richtigen Stelle, mit dem richtigen Text), kein Hinweis ohne das
  Recht, keiner für Administratoren, Bearbeiten-Knopf bei eigener Mannschaft,
  Nur-Lese-Hinweis bei fremder — bei weiterhin sichtbaren Daten.
- Bestehende Prüfungen unverändert grün: Admin→Trainer (28 + 24),
  Abrechnung (30 + 19 + 23), Trainingsplan (68 + 58 + 41 + 51), statische
  Prüfung, Klick-Durchlauf über alle fünf Nutzerarten (90 Klicks, 0 Fehler).
