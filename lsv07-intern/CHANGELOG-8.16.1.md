# LSV07 Interner Bereich — 8.16.1

## Vollbild: moderneres Erscheinungsbild

Die vorige Fassung war an ein gedrucktes Programmheft angelehnt — harte
Linien, scharfe Kanten, gesperrte Versalien. Das wirkte eher wie Papier als
wie eine aktuelle Anwendung. Die Ansicht folgt jetzt der Formensprache des
übrigen Plugins (helles Glas-Theme) und wirkt dadurch zeitgemäßer und
zugleich vertrauter:

- **Weiche Karten statt harter Linien:** Jede Übung sitzt auf einer weißen
  Karte mit großzügigem Eckradius, feinem Rand und ruhigem Schatten. Beim
  Überfahren hebt sich die Karte leicht an.
- **Nummer als Plakette:** Die Übungsnummer steht in einem abgerundeten,
  blau hinterlegten Feld statt als nackte Ziffer — beim Überfahren wird sie
  vollflächig blau.
- **Ausrüstung als weiche Pille** in Blau statt als unterstrichenes
  Versalien-Label.
- **Bedienelemente abgerundet:** Der Größen-Regler ist ein
  zusammenhängendes Element mit − / Wert / +, „Alle Übungen" ist blau
  hervorgehoben, alle Knöpfe heben sich beim Überfahren sanft an.
- **Fortschrittsleiste** mit abgerundeten Marken, die beim Überfahren
  aufwachsen.
- **Ruhiger Farbverlauf** im Hintergrund: ein sehr zarter blauer Schimmer in
  zwei Ecken, damit die Fläche nicht tot wirkt — der Hintergrund bleibt
  weiß.
- **Zurückhaltendere Beschriftungen:** normale Schreibweise statt gesperrter
  Großbuchstaben.
- **Einzelansicht** als eine große, ruhige Karte: oben die blaue Plakette
  mit „von 03 Übungen", darunter die Übung in maximaler Größe.
- Der Auftritt beim Öffnen ist etwas weicher (leichtes Aufziehen statt
  reinem Hochschieben), bei systemseitig reduzierter Bewegung weiterhin
  ohne Animation.

Funktion, Größen-Regler, Blättern, Springen über die Fortschrittsleiste und
das Merken der Schriftgröße bleiben unverändert.

## Tests

- Alle 58 Prüfungen im echten Browser (großer Bildschirm und Tablet
  hochkant) weiterhin grün — Farben, Schriftgrößen, Regler samt Sperren,
  Speichern über ein Neuladen hinweg, Blättern, Tastatur,
  Fortschrittsleiste, Öffnen und Schließen.
- Statische Prüfung ohne Befund; alle zehn Oberflächen-Varianten rendern
  fehlerfrei; Klick-Durchlauf über alle Bereiche aller fünf Rollen
  (90 Klicks) ohne JavaScript-Fehler.
