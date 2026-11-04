# Interner Bereich 9.5.0 — ein Bereich „Abrechnung" statt „Trainer" und „Verwaltung"

Seit 9.3.0 standen zwei Schaltflächen in der Navigation, die beide
dasselbe meinten:

* **Trainer** — eigene Abrechnung, Sonderabrechnung, Sportler, Stammdaten
* **Verwaltung** — eingereichte Abrechnungen, Kassenwart

Zwei Namen für eine Sache. Wer beides durfte, musste sich merken, welcher
Knopf welchen Teil der Abrechnung öffnet.

Jetzt gibt es **einen** Bereich: **Abrechnung**. Darin eine Reiterleiste
mit allem, was vorher auf zwei Bereiche verteilt war:

**Meine Abrechnung · Sonderabrechnung · Sportler · Stammdaten ·
Eingereichte Abrechnungen · Kassenwart**

Welche Reiter jemand sieht, entscheiden weiter die Rechte — unverändert.
Wer nur seine eigene Abrechnung einreicht, sieht vier; wer nur die Kasse
führt, genau einen. Die Schaltfläche *Abrechnung* steht, sobald eines
davon da ist.

**Die Panels selbst sind unverändert.** Verschoben wurde nichts in ihrem
Inneren; sie stehen nur nicht mehr in zwei Bereichen, sondern in einem.
Darum ist an der Abrechnung selbst auch nichts neu kaputt.

## Der Weg nach draussen ist jetzt ein Teil davon

Die Einstellung aus 9.3.0 bleibt: Unter **Admin → Verein →
Konfiguration** steht weiter, wo abgerechnet wird. Nur ist der Verweis
auf das eigene Abrechnungs-Plugin kein eigener Bereich mehr, sondern ein
**Panel in der Abrechnung**:

| Weg | Was in der Abrechnung steht |
|---|---|
| **Im internen Bereich** | die Reiterleiste mit den sechs Reitern |
| **Über einen Link** | genau der Verweis — keine Reiterleiste |

Die Schaltfläche heisst in beiden Wegen *Abrechnung*. Der Weg ändert den
**Inhalt**, nicht den Namen. Das Umschalten greift weiter sofort, ohne
Neuladen.

## Vier Regeln, jede an genau einer Stelle

* **Eine Liste für die Reiterleiste.** Welche Reiter es gibt, in welcher
  Reihenfolge, mit welchem Symbol und an welchem Recht — das steht in
  einer Tabelle im Template, und die Leiste wird daraus gezeichnet. Zwei
  Listen (eine fürs Zeichnen, eine für die Reihenfolge) liefen beim
  ersten neuen Reiter auseinander.
* **Aktiver Reiter und offenes Panel kommen aus derselben Angabe.** Die
  Oberfläche richtet eine Reiterreihe nur dann ein, wenn kein Reiter
  aktiv ist. Stünde ein Reiter auf „an", dessen Panel zu ist, bliebe der
  Bereich leer — geprüft ist das auch für ein Konto, das von der
  Abrechnung nur den Kassenwart sehen darf.
* **Eine Stelle blendet nach dem Weg ein und aus.** `data-abr` sagt, zu
  welchem Weg ein Teil gehört; eine Funktion wertet es aus, und sie läuft
  auch beim Öffnen des Bereichs — sonst stünde nach einem Öffnen wieder
  ein Reiterpanel offen, zu dem es keine Leiste gibt.
* **Ein gemerkter Ort von früher landet in der Abrechnung.** Wer vor dem
  Update in „Trainer" oder „Verwaltung" war, bekommt nach dem Update die
  Abrechnung — keine leere Seite.

Dazu:

* Die **eingereichten Abrechnungen** werden erst geholt, wenn ihr Reiter
  aufgeht. Vorher hing das am Bereich; jetzt lädt niemand mehr die Liste
  eines Warts, bloss weil er seine eigene Abrechnung öffnet. Die eigenen
  **Stammdaten** kommen weiter beim Öffnen des Bereichs — in ihnen
  stehen Stundensatz und Pauschalen, mit denen die offene Abrechnung
  rechnet.
* Die **Schnellzugriffe** der Startseite stehen jetzt unter einer
  Überschrift *Abrechnung* statt unter zwei. Ihre IDs sind unverändert:
  Wer sich eine Kachel gewählt hatte, hat sie weiter.
* Der Verweis nach aussen trägt weiter `noopener` und `noreferrer`.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Navigation im Markup (beide Wege, drei Rechtelagen) | 169 | 14 von 14 |
| Abrechnung (Oberfläche, echter Browser, beide Wege) | 38 | 14 von 14 |
| Abrechnungsweg (echte Klassen, echte Endpunkte) | 42 | 10 von 10 |

Unverändert grün: Navigation (Oberfläche) 42, Mannschaftsbuch 173 (8 von
8), Kacheln und Knöpfe 36 (14 von 14), Benachrichtigungen 33 (12 von 12)
und 32 (14 von 14), Schnellzugriffe 22 (12 von 12) und 18 (7 von 7),
Rechte 47 (20 von 20), Rechte-Oberfläche 31 (12 von 12), Einlesen 88 (24
von 24), Einlesen-Oberfläche 39 (14 von 14), Versetzen 34 (12 von 12) und
17 (6 von 6), Trainingsplan-Vollbild 25 (7 von 7).

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft
sie nichts und ist wertlos.
