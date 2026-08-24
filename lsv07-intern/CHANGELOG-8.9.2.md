# LSV07 Interner Bereich — 8.9.2

## Gefunden: „der Wettkampf ist danach nicht da"

Der Wettkampf **wurde** die ganze Zeit korrekt gespeichert — er war nur nicht
zu sehen. Grund: Die Wettkampfliste hat einen Jahresfilter, der fest auf dem
**laufenden Jahr** steht. Legst du jetzt (August 2026) einen Wettkampf für die
Saison im Frühjahr **2027** an, wird er gespeichert, taucht aber in der Liste
nicht auf, weil dort noch 2026 eingestellt ist. Das sieht exakt so aus, als
hätte das Anlegen nicht funktioniert.

Behoben: Nach dem Speichern zieht die Liste den Jahresfilter automatisch auf
das Jahr des Wettkampfs nach. Der neu angelegte Wettkampf steht danach
garantiert sichtbar in der Liste.

*(Falls dir Wettkämpfe „fehlen": Der Jahresfilter über der Liste war schon
immer die Ursache — einfach das gewünschte Jahr eintragen und auf „Laden".
Deine bisher angelegten Wettkämpfe sind alle noch da.)*

## Ausschreibung: kein zweiter Schritt mehr

Bisher konnte die Ausschreibung erst hochgeladen werden, **nachdem** einmal
gespeichert wurde — das Anlegen zerfiel dadurch in zwei Schritte, und
zwischendrin war unklar, ob überhaupt etwas passiert ist.

Jetzt ist das Feld für die Ausschreibung **direkt beim Anlegen** da. Du
wählst die PDF gleich mit aus; sie wird als „wird beim Speichern
hochgeladen" vorgemerkt und unmittelbar nach dem Speichern automatisch
übertragen. Ein Klick auf Speichern erledigt beides.

Damit geht auch die Freigabe-Mail im selben Arbeitsschritt raus — die
Rückmeldung sagt danach direkt, was passiert ist, z. B. „Wettkampf angelegt,
Ausschreibung hochgeladen. Freigabe-Anfrage an 2 Empfänger verschickt."

Meldeergebnis und Protokoll bleiben beim Anlegen ausgeblendet und erscheinen
wie gehabt erst beim Bearbeiten — die ergeben vorher ohnehin keinen Sinn.

## Zur weiterhin fehlenden Freigabe-Mail

Da der Wettkampf bisher nach dem Anlegen nicht auffindbar war, kam der
Ablauf vermutlich nie sauber bis zum Ausschreibungs-Upload — und damit auch
nicht bis zum Mailversand. Mit den beiden Korrekturen oben läuft das jetzt in
einem Zug durch.

Achte beim nächsten Anlegen bitte auf die Rückmeldung unten am Bildschirm:

- „… Freigabe-Anfrage an *N* Empfänger verschickt." → die Mail ist raus.
- „… es ist niemand als Empfänger hinterlegt …" → unter Admin → Mails bei
  „Wettkämpfe: zusätzliche Empfänger" eine Adresse mit Haken bei
  „Freigabe-Anfrage" eintragen.
- „… konnte nicht verschickt werden …" → der Server hat die Mail abgelehnt;
  dann unter Admin → Mails den Testmail-Knopf benutzen, das grenzt es auf
  den SMTP-Versand ein.

Sag mir einfach, welche der Meldungen erscheint — damit lässt sich der Rest
gezielt eingrenzen.

## Tests

- 8 neue Backend-Prüfungen gegen eine echte SQLite-Datenbank mit den echten
  SQL-Abfragen: ein Wettkampf im Folgejahr wird nachweislich gespeichert,
  fehlt aber in der Liste solange der Filter auf dem laufenden Jahr steht,
  und erscheint sofort beim passenden Jahr — genau der gemeldete Effekt.
- 12 neue Oberflächen-Prüfungen im echten Browser: Jahresfilter wird nach dem
  Speichern nachgezogen und der Wettkampf steht in der Liste; die
  Ausschreibung ist beim Anlegen sofort wählbar, wird korrekt als vorgemerkt
  angezeigt, erst beim Speichern übertragen, und die Rückmeldung bestätigt
  Anlegen, Upload und Mailversand in einem.
- Vollständiger Plugin-Regressionstest und statische Prüfung ohne neuen Befund.
