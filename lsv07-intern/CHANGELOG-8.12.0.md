# LSV07 Interner Bereich — 8.12.0

## Ausschreibung ist abwählbar, wenn es keine gibt

Bisher war die Ausschreibung als PDF zwingend nötig, bevor ein Wettkampf
freigegeben werden konnte. Zu manchen Wettkämpfen gibt es aber schlicht
keine — dann ließ sich der Wettkampf gar nicht freigeben.

Im Bearbeiten-Fenster steht jetzt direkt unter der Ausschreibungs-Zeile der
Haken **„Zu diesem Wettkampf gibt es keine Ausschreibung"**. Ist er gesetzt:

- Die Ausschreibung ist **keine Pflicht** mehr, die Freigabe geht ohne sie.
- Der Hinweis an der Zeile wechselt von „Pflicht vor der Freigabe" auf
  „nicht erforderlich".
- Der Freigabe-Knopf ist sofort benutzbar statt gesperrt.
- In der Wettkampfliste steht „Wartet auf Freigabe" statt
  „Ausschreibung fehlt".
- Die **Freigabe-Anfrage-Mail** geht direkt raus — der Wettkampf ist ja ab
  sofort freigebbar. Setzt du den Haken schon beim Anlegen, ist damit alles
  in einem Schritt erledigt.

Der Haken wirkt sofort, ohne Zwischenspeichern: Sobald du ihn setzt oder
entfernst, ändern sich Hinweis und Freigabe-Knopf direkt mit.

**Sicherheitsnetz:** Wird der Haken bei einem bereits freigegebenen
Wettkampf wieder entfernt, obwohl gar keine Ausschreibung hochgeladen ist,
wird die Freigabe automatisch zurückgezogen — sonst bliebe ein Wettkampf
öffentlich sichtbar, der die Voraussetzung dafür nicht mehr erfüllt. Liegt
dagegen eine Ausschreibung vor, bleibt die Freigabe unangetastet.

## Hinweis zur Aktualisierung

Der Wettkampf-Tabelle kommt ein Feld für den Haken dazu. Das passiert
automatisch beim Update — und zur Sicherheit auch dann, wenn ein Wettkampf
zum ersten Mal gespeichert wird. Bestehende Wettkämpfe verhalten sich
unverändert: Der Haken ist bei ihnen nicht gesetzt, die Ausschreibung bleibt
dort also weiterhin Pflicht.

## Tests

- 16 neue Backend-Prüfungen gegen eine echte SQLite-Datenbank mit den echten
  SQL-Texten: Das neue Feld wird bei Bedarf selbst nachgetragen; ohne Haken
  bleibt die Freigabe wie bisher gesperrt und die Mail wartet; mit Haken
  gelingt die Freigabe sofort und die Freigabe-Anfrage geht raus; das
  Entfernen des Hakens ohne vorhandene Ausschreibung zieht eine bestehende
  Freigabe zurück, mit vorhandener Ausschreibung dagegen nicht; und ein
  gleich mit Haken angelegter Wettkampf ist sofort freigebbar.
- 14 neue Oberflächen-Prüfungen im echten Browser: Haken vorhanden und
  anfangs leer, Hinweis wechselt zwischen „Pflicht vor der Freigabe" und
  „nicht erforderlich", Freigabe-Knopf sperrt und entsperrt sich passend,
  der Haken wird beim Speichern übertragen und beim erneuten Öffnen wieder
  gesetzt, und die Kennzeichnung in der Wettkampfliste passt sich an.
- Bestehende Suiten weiterhin vollständig grün: Wettkämpfe (28), Links (17),
  Meldungen (65), Staffeln (19 + 20), öffentliche Seite hell (10);
  vollständiger Plugin-Regressionstest und statische Prüfung ohne neuen
  Befund.
