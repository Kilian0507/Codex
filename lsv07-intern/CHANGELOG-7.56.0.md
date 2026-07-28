# Änderungen in Version 7.56.0

## Bestzeiten-Import: bessere automatische Erkennung

Die Zuordnung der Namen zu den Schwimmern im System lief bisher nur über
eine exakte Übereinstimmung plus eine Doppelnamen-Toleranz. Alles, was
davon abwich, landete als „nicht gefunden" und musste von Hand zugeordnet
werden.

Gesucht wird jetzt in fünf Stufen, von streng nach tolerant. Sobald eine
Stufe Treffer liefert, entscheidet sie — eine exakte Übereinstimmung
gewinnt also immer gegen einen unscharfen Treffer:

1. **exakt** — Name stimmt zeichengenau (Groß-/Kleinschreibung egal)
2. **Schreibweise** — `ü`/`ue`/`u`, `ä`/`ae`/`a`, `ö`/`oe`/`o`, `ß`/`ss`
   gelten als gleich. „Mueller", „Muller" und „Müller" sind derselbe Name.
   Akzente werden ebenfalls angeglichen.
3. **Doppelname** — „Anna" trifft „Anna-Maria", „Karl" trifft „Karl Heinz"
4. **nur ein Namensteil** — steht nur „Weber" in der Datei, wird danach
   gesucht; gibt es mehrere Weber, kommt die Zeile zur Auswahl
5. **Tippfehler** — ein bis zwei abweichende Zeichen, auch vertauschte
   Nachbarbuchstaben („Jonsa" statt „Jonas")

Die Toleranz wächst mit der Namenslänge, damit kurze Namen wie Tim/Tom
nicht fälschlich zusammenfallen. Ein Jahrgang in Klammern hinter dem
Namen wird ausgewertet, auch wenn keine eigene Jahrgangsspalte da ist.

In einer Testreihe mit 29 realistischen Schreibweisen werden jetzt alle
richtig zugeordnet — vorher waren es 13.

## Zuordnungsstand und Bestätigung

Unter der Vorschau steht jetzt eine Statuszeile, die sich bei jeder
Änderung mitzählt:

- wie viele der Zeilen einem Schwimmer zugeordnet sind
- wie viele noch offen sind
- wie viele Zeilen zugeordnet sind, aber keine gültige Zeit enthalten
- **ob ein Schwimmer versehentlich mehrfach zugeordnet wurde** — dann
  würde die zweite Zeile die erste überschreiben

Der Import-Knopf nennt die Zahl der betroffenen Schwimmer („Zeiten für
5 Schwimmer importieren") und ist gesperrt, solange keine einzige gültige
Zeit bereitsteht.

Nach dem Import erscheint anstelle der Vorschau eine **Bestätigung**, die
stehen bleibt: wie viele Zeiten gespeichert wurden, für wie viele
Schwimmer, wie viele bestehende Zeiten dabei ersetzt wurden und wie viele
Zeilen übersprungen wurden. Ein Knopf führt direkt zum nächsten Import.

## Behoben: Import scheiterte trotz vergebenem Recht

Vorschau und Import ließen nur Administratoren und Schwimmwarte durch.
Wer das Einzelrecht **„Bestzeiten importieren"**
(`schwimmen.bestzeit.import`) zugewiesen bekommen hatte, bekam trotzdem
„Keine Berechtigung". Beide Schritte akzeptieren dieses Recht jetzt. Das
Löschen von Bestzeiten bleibt unverändert Administratoren und
Schwimmwarten vorbehalten.

## Hinweis zum Ersetzen

Beim Import werden **alle bisherigen Zeiten der zugeordneten Schwimmer
gelöscht** und durch die neuen ersetzt — pro Schwimmer, nicht pro
Strecke. Die Bestätigung nennt jetzt ausdrücklich, wie viele Zeiten davon
betroffen waren. Zeilen ohne Zuordnung werden übersprungen und rühren
keine bestehenden Daten an.

## Datenbank

Keine Änderungen.
