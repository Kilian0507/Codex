# LSV07 Interner Bereich — 8.8.3

## Bugfix: Freigabe-Anfrage-Mail kann nach einem Fehlschlag für immer stumm bleiben

Gefunden bei der Untersuchung der gemeldeten fehlenden Freigabe-Erinnerung
bei neu angelegten Wettkämpfen: Wenn ein Wettkampf angelegt wird, verschickt
das Plugin sofort eine Mail an alle mit Freigabe-Recht sowie an die unter
Admin → Mails hinterlegten zusätzlichen Empfänger. Bisher wurde dabei intern
ein „bereits verschickt"-Merker gesetzt, **egal ob der Versand tatsächlich
geklappt hat oder nicht** — schlug er beim allerersten Versuch fehl (z. B.
weil die Benachrichtigung unter Admin → Mails gerade deaktiviert war, der
globale Mail-Schalter aktiv war, oder `wp_mail()` aus einem anderen Grund
scheiterte), blieb dieser Wettkampf **für immer** ohne Freigabe-Mail — auch
nachdem die eigentliche Ursache längst behoben war. Da nirgends eine
Fehlermeldung dazu erschien, war das von außen nicht erkennbar; das dürfte
der Kern dessen sein, was sich als „es kommt keine Mail an" gezeigt hat,
obwohl die Empfänger-Adressen korrekt hinterlegt waren.

Der exakt gleiche Fehler steckte auch in den beiden täglichen
Cron-Erinnerungen (Meldeergebnis 3 Tage vorher, Protokoll 1 Tag danach).

Behoben: Der „verschickt"-Merker wird jetzt nur noch bei einem tatsächlich
erfolgreichen Versand gesetzt.
- **Freigabe-Anfrage:** Schlägt der Versand fehl, holt das Plugin ihn beim
  nächsten Speichern desselben Wettkampfs automatisch nach (z. B. reicht
  eine kleine Korrektur wie ein geänderter Ort und erneutes Speichern).
- **Erinnerungen:** Läuft der tägliche Cron mehrfach am selben Tag, wird ein
  fehlgeschlagener Versuch beim nächsten Lauf desselben Tages erneut
  versucht, statt endgültig verloren zu gehen.

**Bitte kurz prüfen:** Falls bereits ein Wettkampf angelegt wurde, bei dem
die Freigabe-Mail nie ankam, hilft jetzt ein erneutes Speichern (z. B. im
Bearbeiten-Formular einmal auf Speichern klicken) — das löst automatisch
einen neuen Versandversuch aus. Kommt danach immer noch keine Mail an, bitte
zusätzlich unter Admin → Mails den Testmail-Knopf bei „Freigabe-Berechtigte
benachrichtigen wenn ein neuer Wettkampf angelegt wurde" ausprobieren: Meldet
der eine Fehlermeldung, liegt es am E-Mail-Versand des Servers (z. B. fehlendes
SMTP) und nicht mehr am Plugin selbst.

## Tests

- 22 neue Backend-Prüfungen gegen eine echte SQLite-Datenbank mit den
  echten SQL-Texten aus `class-ajax-wettkampf.php` und der echten
  `LSV07I_Mail`-Klasse: Freigabe-Anfrage wird korrekt an admin-konfigurierte
  Zusatz-Empfänger verschickt, globaler Killswitch und Feature-Toggle
  blockieren korrekt, ein echter `wp_mail()`-Fehlschlag setzt das Flag NICHT
  mehr, ein erneutes Speichern holt den Versand automatisch nach sobald er
  wieder funktioniert, und nach einem erfolgreichen Versand gibt es keinen
  doppelten Versand mehr. Ebenso für die beiden Cron-Erinnerungen geprüft
  (Fehlschlag → Flag bleibt 0 → nächster Lauf am selben Tag holt es nach).
- Bestehende Testsuite zu Wettkämpfen (32 Prüfungen) und Mail-Vorlagen
  (49 Prüfungen) erneut ausgeführt und an die korrigierten Erwartungen
  angepasst, weiterhin vollständig grün.
