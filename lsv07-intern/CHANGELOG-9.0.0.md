# Interner Bereich 9.0.0

Die Fassung aus „Interner Bereich V9.0.0": neue Struktur der
Navigation, ein Rechtesystem, das beim Modul beginnt, Versetzen im
Mannschaftsbuch und ein Trainingsplan, der am Beckenrand stehen bleibt.

## Navigation in zwei Ebenen

Pro Bereich steht jetzt oben eine Reihe von **Gruppenkacheln**, darunter
nur die Reiter der gewählten Gruppe. Vorher standen bis zu zwölf Kacheln
nebeneinander, und man musste wissen, welche zu welcher Arbeit gehört.

* **Schwimmen** — Mannschaftsbuch (Mannschaftsbuch, Anwesenheit,
  Mannschaften, Atteste, Bestzeiten, Reflexion, Akte, Trainer),
  Wettkämpfe (Wettkämpfe, Meldungen), Springer, Trainingsplan.
* **Triathlon** und **Fitness** — je eine Gruppe Mannschaftsbuch.
* **Admin** — Import, Rechte, Verein, Personen, Benachrichtigungen, Log.
  Unter Import stehen Personendaten und Sportler; der
  Bestzeiten-Import bleibt dort, wo die Bestzeiten stehen, und Import
  führt mit einem Knopf dorthin. Ihn zu kopieren hiesse, zwei Stellen
  für eine Sache zu haben; ihn zu verschieben hiesse, ihn dem
  Schwimmwart wegzunehmen, dem der Admin-Bereich nicht offensteht.
  Weitere Importe („alle Daten") gibt es bisher nicht — was dort
  einzulesen wäre, steht im Dokument nicht, und erfunden wird es
  nicht.
* **Kommunikation** — Nachrichten und Tickets bleiben zwei Bereiche,
  stehen aber unter einer Schaltfläche und wechseln über Kacheln.
* **Abrechnung** — ein Verweis auf das Abrechnungsplugin
  (https://lsv07.info/lsv-abrechnung), mit `noopener` und `noreferrer`:
  die Adresse des internen Bereichs nennt Bereich und Panel und hat
  draussen nichts zu suchen.

Eine Gruppe mit nur einem Reiter zeigt keine Reiterleiste — die Kachel
oben sagt schon, wo man ist.

Die alten Abrechnungsansichten stehen weiter im Markup, aber nicht mehr
in der Navigation. Sie zu löschen wäre ein Schnitt, der sich nicht
zurücknehmen lässt.

### Zwei Fehler, die dabei herauskamen

* Die Liste, die entscheidet, ob ein Bereich überhaupt sichtbar ist, war
  von Hand gepflegt und unvollständig. Ein Konto, dem man NUR den
  Trainingsplan freigab, bekam keinen Schwimmbereich und sah seinen
  Reiter nie. Jetzt zählt der Präfix des Rechts; ein neuer Reiter
  braucht keine zweite Eintragung.
* Beim Start wurde in JEDER Reiterreihe der Seite der erste Reiter
  angeklickt — auch in Bereichen, die zu waren. Ein Reiterklick holt die
  Daten seines Panels, also wurden Daten aus Bereichen geholt, die
  niemand geöffnet hatte. Ein Bereich richtet sich jetzt erst beim
  Öffnen ein. Wer Triathlon nie aufmacht, bekommt keine
  Triathlon-Daten.

## Rechte beginnen beim Modul

Die Rechtevergabe hat eine neue erste Ebene: **je Modul nichts, lesen
oder bearbeiten.** Darunter bleiben die feineren Rechte einzeln
vergebbar — alles löschen, importieren, exportieren, verschicken,
freigeben, genehmigen. Wer „bearbeiten" hat, hat damit nicht
automatisch das Recht, alle Bestzeiten einer Mannschaft zu löschen oder
eine Erinnerung an die Eltern zu schicken.

* „bearbeiten" enthält „lesen": Eine Oberfläche, in der man schreibt,
  ohne zu sehen, worauf, ist keine.
* „nichts" heisst nichts. Ein feines Recht in einem abgeschalteten
  Modul wird nicht vergeben — und die Ansicht nimmt es sichtbar mit,
  nicht erst beim Speichern.
* Eine über Jahre gewachsene Rechtelage wird nicht stillschweigend
  geradegezogen: Passen die gesetzten Einzelrechte nicht genau zu einer
  Stufe, steht das Modul als **abweichend** da.
* Daneben steht weiter die vollständige Liste aller 152 Einzelrechte.
  Beim Umschalten zwischen den Ansichten wird umgerechnet — auf dem
  Server, damit die Regel an einer Stelle steht.

**Vorlagen** (vorher „Templates") lassen sich wieder anlegen, und zwar
direkt aus einer eingestellten Rechtelage: „Als Vorlage speichern"
macht aus den Rechten eines Kontos die Vorlage „alle Trainer", ohne sie
zweimal zusammenzuklicken.

Gespeichert werden weiter die einzelnen Rechte. Die Stufe ist eine
Ansicht auf dieselben Daten — an den Einzelrechten hängen über hundert
Prüfungen im Plugin, und jede davon umzuschreiben hiesse, hundert
Gelegenheiten für ein Loch zu schaffen.

## Mannschaftsbuch: versetzen auch in fremde Mannschaften

Im Profil eines Sportlers steht ein Knopf **„In andere Mannschaft
versetzen"**.

* **Woher?** Nur aus einer Mannschaft, die man betreut — dieselbe
  Prüfung wie beim Ändern seiner Daten.
* **Wohin?** In jede Mannschaft des Vereins. Das ist die Änderung: Man
  muss die Zielmannschaft nicht betreuen. Vorher musste für jede
  Umgruppierung jemand mit mehr Rechten herhalten.

Ein Versetzen ändert, wer die Daten dieser Person sehen darf. Darum
steht der Hinweis vorher im Dialog, wird das Versetzen mit beiden
Mannschaften im Namen protokolliert, und das Profil schliesst sich,
wenn das eigene Konto den Sportler danach nicht mehr sieht. Die Liste
der Mannschaften gibt nur Nummer und Name heraus, und nur an jemanden,
der diesen Sportler auch versetzen darf.

## Trainingsplan im Vollbild

* Eine angetippte Übung lässt sich **scrollen**. Die Einzelansicht stand
  mittig, und eine zentrierte Karte, die höher ist als ihr Kasten, ragt
  oben und unten heraus — über den Anfang eines Kastens hinaus kann man
  nicht scrollen, der Anfang des Textes war unerreichbar. Dazu wurde
  langer Text abgeschnitten.
* Geschlossen wird **nur noch über das ✕**. Drei Wege taten es vorher
  von selbst: das Verlassen des echten Vollbilds (Wischgeste, F11),
  Escape am Ende der Kette, und „Zurück zum Plan" bei einem Plan ohne
  Übungen. Escape führt jetzt nur noch stufenweise zurück.
* Die Wischbewegung bleibt im Plan und wandert nicht an die Seite
  darunter — am Tablet ist das die Bewegung, mit der der Browser sein
  Vollbild verlässt.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Navigation (gerendertes Markup, drei Rechtelagen) | 154 | 9 von 9 |
| Navigation (Oberfläche, echter Browser) | 42 | 12 von 12 |
| Rechtesystem (echte Klassen, echtes SQL) | 47 | 20 von 20 |
| Rechte-Oberfläche (echter Browser) | 31 | 12 von 12 |
| Versetzen (echte Endpunkte, echtes SQL) | 34 | 12 von 12 |
| Versetzen (Oberfläche) | 17 | 6 von 6 |
| Trainingsplan im Vollbild (Oberfläche) | 25 | 7 von 7 |

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen.
Bleibt die Prüfung dabei grün, prüft sie nichts und ist wertlos.
