# Änderungen in Version 7.58.0

## Die Ursache: jede Nachricht meldete einen Fehler

In `LSV07I_Konversation::nachricht_senden()` fehlten die geschweiften
Klammern hinter einer `if`-Abfrage:

```php
if ( ! $msg_id ) do_action(…); error_log(…); return new WP_Error(…);
```

Nur der erste Aufruf gehörte zur Bedingung. Das **`return` lief immer** —
auch wenn die Nachricht einwandfrei gespeichert wurde. Folgen:

- Der Browser bekam bei **jedem** Senden „Fehler" zurück, obwohl die
  Nachricht in der Datenbank stand. Seit 7.57 zeigte er das als
  *nicht gesendet · erneut senden*.
- **Der Anhang wurde nie hochgeladen.** Der Upload startet erst nach
  erfolgreichem Senden — und das galt nie als erfolgreich. Deshalb blieb
  von einer Datei nur der Platzhalter `[Datei]` übrig, und es gab nichts
  zu öffnen.
- Wer erneut sendete, erzeugte eine weitere Nachricht in der Datenbank.

Der Fehler stammt aus dem Ausgangsstand 7.50.0.

### Derselbe Fehler zweimal bei den Saisons

`LSV07I_Saison::save()` hatte die Klammern an zwei Stellen ebenfalls
vergessen. Damit meldete **jedes Anlegen und jedes Bearbeiten einer
Saison** einen Datenbankfehler, obwohl gespeichert wurde. Beide Stellen
sind korrigiert.

Ein Suchlauf über das gesamte Plugin findet keine weitere Stelle dieser
Art.

Alle drei Stellen nennen jetzt zusätzlich die tatsächliche Meldung der
Datenbank, statt nur „Datenbankfehler" zu sagen.

## Was das für vorhandene Daten bedeutet

- Nachrichten mit dem Text **`[Datei]` und ohne Anhang** sind alte
  Versuche — die Datei wurde damals nie übertragen und lässt sich nicht
  nachträglich herstellen. Die Dateien müssen erneut gesendet werden.
- Durch wiederholtes Senden können **doppelte Nachrichten** entstanden
  sein. Sie lassen sich über das Kontextmenü einer Nachricht löschen.

## Weitere Verbesserungen

- **Der Platzhalter `[Datei]` wird nicht mehr angezeigt**, wenn der
  Anhang daneben steht. In der Übersicht der Unterhaltungen steht dafür
  „📎 Datei".
- **Der Verlauf springt ans Ende**, auch wenn Bilder erst nachladen.
  Bisher blieb die neue Nachricht unterhalb des sichtbaren Bereichs.
  Scrollt man selbst nach oben, wird nicht mehr nachgezogen.
- Eine Bubble, die nur einen Anhang enthält, hat keine leere Textzeile
  mehr.

## Datenbank

Keine Änderungen.
