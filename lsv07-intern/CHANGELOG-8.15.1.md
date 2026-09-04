# LSV07 Interner Bereich — 8.15.1

## Vollbild: weißer Hintergrund, schwarze Schrift

Die Vollbild-Ansicht des Trainingsplans war dunkel gehalten. Auf Wunsch ist
sie jetzt umgekehrt: **weißer Hintergrund, schwarze Schrift** — maximaler
Kontrast.

Angepasst wurden alle Bestandteile, damit nichts blass oder blendend wirkt:

- Grundfläche weiß, Grundschrift schwarz.
- Übungskarten weiß mit klarem grauem Rahmen und blauem Balken links; die
  Übungsnummer bleibt blau als Orientierungspunkt.
- Überschrift und Beschreibung der Übung in Schwarz.
- Die Knöpfe oben (Größe, Blättern, „Alle Übungen") hell mit dunkler Schrift
  und kräftigem Rahmen, der Schließen-Knopf weiterhin rot.
- Ausrüstungs-Chip und Kommentar in dunklen, gut lesbaren Tönen statt der
  bisherigen hellen Grautöne.
- In der Einzelansicht bleibt die Karte durchgehend weiß — der Hover-Effekt
  aus der Übersicht ist dort abgeschaltet, weil es nichts anzuklicken gibt.

Größen, Regler, Blättern und alles Weitere bleiben unverändert.

## Tests

- 54 Prüfungen im echten Browser, darunter 7 neue rein zu den Farben:
  Hintergrund und Übungskarten sind nachweislich hell, Grundschrift, Titel,
  Übungsüberschrift und Beschreibung nachweislich dunkel — gemessen an den
  tatsächlich berechneten Farbwerten, in Übersicht wie Einzelansicht.
- Alle bisherigen Prüfungen zu Größe, Regler, Speichern der Einstellung,
  Blättern und Tastatur weiterhin grün.
- Statische Prüfung ohne Befund; Klick-Durchlauf über alle Bereiche aller
  fünf Rollen (90 Klicks) ohne JavaScript-Fehler.
