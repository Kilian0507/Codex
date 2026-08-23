# LSV07 Interner Bereich — 8.9.1

## Gefunden: warum bei genau deinem Ablauf nie eine Freigabe-Mail kam

Dein beschriebener Ablauf — **Wettkampf anlegen → Ausschreibung hochladen →
nochmal speichern** — hat den eigentlichen Fehler sichtbar gemacht. Er lag
nicht am Mailversand, sondern am **Zeitpunkt**:

Die „bitte freigeben"-Mail wurde bisher **sofort beim Anlegen** verschickt.
Zu diesem Zeitpunkt ist aber noch keine Ausschreibung hochgeladen — und die
Freigabe selbst verlangt zwingend eine Ausschreibung („Vor der Freigabe muss
die Ausschreibung als PDF hochgeladen werden"). Die Mail forderte also zu
etwas auf, das in diesem Moment gar nicht möglich war.

Schlimmer: Mit diesem verfrühten Versand wurde der interne „schon
verschickt"-Merker verbraucht. Wenn du danach — wie beschrieben — die
Ausschreibung hochgeladen und erneut gespeichert hast, also genau in dem
Moment, in dem die Freigabe endlich möglich wurde, ging **keine Mail mehr
raus**. Der Merker stand ja bereits auf „erledigt".

**Behoben:** Die Freigabe-Anfrage wird jetzt genau dann verschickt, wenn der
Wettkampf tatsächlich freigegeben werden kann — also **unmittelbar nach dem
Hochladen der Ausschreibung**. Konkret:

- Beim reinen Anlegen geht noch keine Mail raus. Stattdessen erscheint der
  Hinweis: „Die Freigabe-Anfrage geht raus, sobald die Ausschreibung
  hochgeladen ist."
- Sobald die Ausschreibung hochgeladen ist, wird die Mail sofort verschickt
  und direkt danach bestätigt: „Freigabe-Anfrage an *N* Empfänger
  verschickt."
- Wurde beim Anlegen bereits eine Ausschreibung nachgereicht und der Versand
  war aus einem anderen Grund nicht möglich, holt ihn auch jedes weitere
  Speichern automatisch nach, bis er einmal wirklich geklappt hat.
- Das Hochladen von Meldeergebnis oder Protokoll löst keine Freigabe-Anfrage
  aus — nur die Ausschreibung.

Damit passt der Versand jetzt zu deinem Ablauf: Die Mail kommt in dem
Moment, in dem der Wettkampf für die Freigabe-Berechtigten wirklich bereit
ist — und sie enthält dann auch eine Ausschreibung, die man prüfen kann.

## Tests

- 30 Backend-Prüfungen gegen eine echte SQLite-Datenbank, darunter dein
  Ablauf Schritt für Schritt: beim Anlegen noch keine Mail und Merker bleibt
  unverbraucht, nach dem Ausschreibungs-Upload genau eine Mail an die
  richtigen Empfänger, danach kein Doppelversand mehr, und Meldeergebnis-
  bzw. Protokoll-Uploads lösen keine Freigabe-Anfrage aus.
- 11 Oberflächen-Prüfungen im echten Browser, inklusive deines kompletten
  Ablaufs: nach dem Anlegen erscheint die Ankündigung, nach dem
  Ausschreibungs-Upload die Bestätigung mit Empfängerzahl.
- Bestehende Wettkampf-Suite (34), Cron-Erinnerungen (4) und
  Mail-Vorlagen (49) an das korrigierte Verhalten angepasst und vollständig
  grün; vollständiger Plugin-Regressionstest und statische Prüfung ohne
  neuen Befund.
