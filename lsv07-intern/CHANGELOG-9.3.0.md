# Interner Bereich 9.3.0 — die Abrechnungen sind wieder da, und der Verein entscheidet, wo abgerechnet wird

Mit 9.0.0 hatte der interne Bereich das Abrechnen abgegeben: Statt der
Bereiche **Trainer** und **Verwaltung** stand dort nur noch eine
Schaltfläche, die ins eigene Abrechnungs-Plugin führte.

Das war eine Entscheidung, die dieses Plugin nicht zu treffen hat.
Darum jetzt beides — und eine Einstellung, die sagt, was gilt.

## 1. Die Abrechnungen sind wieder da

**Trainer** — eigene Abrechnung, Sonderabrechnung, Sportler, Stammdaten.
**Verwaltung** — eingereichte Abrechnungen, Kassenwart mit
Jahresübersicht, CSV und PDF.

Beide Bereiche standen die ganze Zeit im Markup; genommen war ihnen nur
der Platz in der Navigation. Sie sind unverändert zurück, mit allem, was
sie vorher konnten. Nichts daran musste neu gebaut werden, und darum ist
auch nichts daran neu kaputt.

**Das ist der neue Standard.** Eine Installation, in der nie etwas
eingestellt wurde, hat ihre Abrechnungen — auch nach einem Update.

## 2. Admin → Verein → Konfiguration: wo wird abgerechnet?

Dort steht jetzt die Auswahl **„Wo wird abgerechnet?"** mit zwei
Möglichkeiten:

| | |
|---|---|
| **Im internen Bereich** | Die Bereiche Trainer und Verwaltung stehen in der Navigation. |
| **Über einen Link** | Statt der beiden Bereiche steht eine Schaltfläche *Abrechnung*, die ins eigene Abrechnungs-Plugin führt. |

**Das Umschalten greift sofort — ohne Neuladen.** Wer die Einstellung
speichert, sieht im selben Moment, wie sich die Navigation ändert. Das
ist nicht Bequemlichkeit: Eine Einstellung, deren Wirkung man erst nach
einem Neuladen sieht, muss man glauben.

Technisch möglich macht das, dass **beide Wege im Markup stehen** und
der, der nicht gilt, auf `display:none` steht.

## Fünf Regeln, jede an genau einer Stelle

Alles, was mit dem Weg zu tun hat, steht in `LSV07I_Abrechnungsweg`:
welche Wege es gibt, welcher gilt, und welcher Bereich zu welchem Weg
gehört. Die Navigation, die Startseite und die Oberfläche fragen dort —
sie entscheiden nicht selbst.

* **Der Weg ist eine Ansicht, kein Recht.** Wer die Abrechnung nicht
  sehen darf, sieht sie in keinem der beiden Wege. Das Umschalten gibt
  niemandem Zugriff und nimmt niemandem einen; darüber entscheiden
  weiter die Rechte, und jeder Aufruf dahinter prüft sein Recht selbst.
  Das Verstecken einer Schaltfläche ist keine Absicherung und ist auch
  nicht als eine gemeint.
* **Nur die Administration darf umschalten** — mit Nonce, und das
  Umschalten steht im Protokoll.
* **Es gibt keinen dritten Weg.** Was nicht einer der beiden bekannten
  Werte ist, wird nicht gespeichert, und ein von Hand verbogener
  Eintrag in der Datenbank ergibt den Standard. Sonst stünde nach einem
  Tippfehler weder das eine noch das andere da.
* **Die Oberfläche folgt dem Server, nicht ihrer eigenen Auswahl.**
  Zurück kommt der Weg, der wirklich gilt. Hätte sie den geschickten
  Wert genommen, zeigte sie nach einem abgelehnten Wert etwas anderes
  als die Seite nach dem nächsten Laden.
* **Kein Bereich ohne Weg zurück.** Ein gemerkter Ort oder ein alter
  Schnellzugriff kann auf einen Bereich zeigen, den der geltende Weg
  nicht anbietet. Dann die Startseite — eine Ansicht, aus der keine
  Schaltfläche herausführt, ist schlimmer als eine, die man nicht
  wollte.

Dazu: Die **Schnellzugriffe der Startseite** folgen demselben Weg. Eine
Kachel in einen Bereich, den es gerade nicht gibt, öffnete nichts. Die
Auswahl des Nutzers wird dabei nicht gelöscht — wird zurückgeschaltet,
ist seine Kachel wieder da.

Der Verweis nach draussen trägt weiter `noopener` und `noreferrer`: ohne
noreferrer schickt der Browser die Adresse des internen Bereichs mit, und
die nennt Bereich und Panel — also, was dieses Konto gerade tut.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Abrechnungsweg (echte Klassen, echte Endpunkte) | 41 | 10 von 10 |
| Abrechnungsweg (Oberfläche, echter Browser, beide Wege) | 30 | 14 von 14 |
| Navigation im Markup (beide Wege gerendert) | 161 | 11 von 11 |

Unverändert grün: Navigation (Oberfläche) 41, Mannschaftsbuch 173 (8 von
8), Kacheln und Knöpfe 36 (14 von 14), Rechte 47 (20 von 20),
Rechte-Oberfläche 31 (12 von 12), Einlesen 88 (24 von 24),
Einlesen-Oberfläche 39 (14 von 14), Versetzen 34 (12 von 12) und 17 (6
von 6), Trainingsplan-Vollbild 25 (7 von 7), Rauchtest 55 Klicks ohne
einen JavaScript-Fehler.

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft
sie nichts und ist wertlos.
