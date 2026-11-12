# Abrechnung 1.15.0 — weniger Text, mehrere Mailadressen, und ein Zeichen beim Weiterkommen

## Die Beschreibungen und Hinweise sind weg

Die Oberfläche erklärte sich an vielen Stellen selbst: ein Absatz unter
jeder Überschrift, ein Kasten über jeder Karte, eine Erläuterung neben
jeder Zahl. Zusammen war das mehr Fließtext als Inhalt.

Entfernt wurden **22 solcher Stellen**, darunter:

* die Seitenbeschreibungen unter „Rechnungen/Belege einreichen" und
  „Rechnungsarchiv";
* die beiden Kästen in der Verwaltung zum **Belegtresor** („Belege sind
  verschlüsselt …") und zum **Belegordner** („Dieser Server ist ein
  nginx …");
* sämtliche Erklärtexte der Statistik — Median, Wochentag, Abteilung,
  Rechnungsdatum gegen Auszahlungsdatum, Aufbewahrungspflicht, „gerade
  zurückgegeben", Grundmenge des Rückstaus, „nur in der Sicht Meine";
* die Erklärungen zu Abteilungen, eigenen Mannschaften, Mitteilungen,
  Rollenansicht und QR-Code.

**Geblieben ist, was den Zustand eines Vorgangs meldet** statt ihn zu
erklären: Auffälligkeiten, Beanstandungen, Rückgabegründe, „Ihrem Konto
ist keine Abteilung zugeordnet", „Diese Rechnung ist eingereicht und
lässt sich nicht mehr ändern" — und die Warnungen in den
Bestätigungsdialogen vor dem Löschen. Die stehen vor einer
unwiderruflichen Handlung und sind keine Beschreibung.

**Was damit nicht mehr angezeigt wird, und das muss dastehen:** Das
Plugin sagt nicht mehr von selbst, ob der Belegordner über den
Webserver erreichbar ist oder ob die Verschlüsselung aus ist. Beides
wird weiterhin geprüft und steht in der Antwort des Servers; nur die
Anzeige ist fort. Auf nginx bleibt der Ordner also ohne die Regel in der
Server-Konfiguration offen, und niemand wird mehr daran erinnert.

## Mehrere Mailadressen je Konto

Ein Konto hatte genau eine Adresse. Jetzt dürfen es mehrere sein,
getrennt mit **Komma, Semikolon oder Leerzeichen** — wer eine Liste
einfügt, soll nicht erst das richtige Zeichen suchen müssen.

* **Jede** Adresse bekommt die Mitteilung, einzeln verschickt.
* Dieselbe Adresse an zwei Konten oder zusätzlich als feste Adresse
  einer Rolle wird **einmal** angeschrieben, nicht zweimal.
* Beim Speichern wird jede Adresse geprüft, und die Meldung sagt,
  **welche** nicht stimmt. Bei fünf Adressen hilft „ungültig" allein
  niemandem.
* Steht nichts Brauchbares im Feld, geht die Post weiterhin an die
  Adresse des WordPress-Kontos.

Das Feld ist jetzt ein Textfeld und kein E-Mail-Feld: Das Browserfeld
für E-Mail lässt genau eine Adresse zu und weist eine Liste als ungültig
ab. Geprüft wird ohnehin auf dem Server.

Die Spalte `mail` wird von 190 auf 500 Zeichen verbreitert. In 190
passen zwei, vielleicht drei Adressen — danach schneidet die Datenbank
still ab, und die letzte ist halb da. Bestehende Anlagen werden beim
Update umgestellt; gekürzt wird nie.

## Ein Wart bekommt nur Post aus seinem Bereich

Bisher ging jede eingereichte Abrechnung an **alle** Warte. Ein Wart,
der nur für die Jugend zuständig ist, bekam auch Post über die Masters —
und konnte den Vorgang nicht einmal öffnen.

Jetzt gehen Mail **und** Mitteilung im System nur an die Warte, die
**genau diese Abrechnung auch prüfen dürfen**. Gefragt wird dabei
`LSV07A_Zustaendig::darf_pruefen()` — dieselbe Frage wie beim Öffnen,
dieselbe Antwort. Zwei Antworten auf eine Frage wären eine zu viel.

Unverändert bleibt:

* **Ein Wart ohne Eintrag ist für alles zuständig** und bekommt
  weiterhin alles. Eingeschränkt wird nur, wer bewusst eingeschränkt
  wurde.
* **Eine Abrechnung ohne Mannschaftsbezug** — nur Fahrten, nur
  Sonstiges — geht an alle Warte. Sonst sähe sie keiner und bliebe
  liegen.
* **Die festen Zusatzadressen einer Rolle** (`warte@verein.de`)
  bekommen weiterhin alles. Sie hängen an keiner Mannschaft.
* **Die Kasse** ist nicht nach Mannschaften eingeschränkt und wird es
  auch nicht.

Dasselbe gilt für die Antwort auf eine Rückfrage.

## Ein Zeichen, wenn ein Vorgang weiterkommt

Wird ein Vorgang eine Station weitergerückt, erscheint kurz in der Mitte
ein Haken, der sich zeichnet, und darunter der erreichte Stand:
**Eingereicht**, **Genehmigt**, **Bezahlt**.

An sechs Stellen: Abrechnung einreichen, Abrechnung genehmigen,
Abrechnung als bezahlt vermerken, Rechnung einreichen, Rechnung
genehmigen, Rechnung als bezahlt vermerken.

* **Nur vorwärts.** Zurückziehen, Zurückgeben und das Entfernen eines
  Zahlungsvermerks sind Rückschritte; sie zu feiern wäre Hohn.
* **Kein dunkler Vorhang** — der Vorgang dahinter bleibt sichtbar, es
  ist ja seine Veränderung, die gezeigt wird.
* **Weg mit einem Klick**, mit **Escape**, oder nach knapp zwei
  Sekunden von selbst. Niemand soll auf eine Animation warten müssen.
* **Zwei Schritte kurz nacheinander ergeben ein Zeichen**, nicht zwei.
* **Wer im Betriebssystem weniger Bewegung eingestellt hat**, bekommt
  dasselbe Zeichen fertig gezeichnet und ohne Bewegung. Die Rückmeldung
  ist die Botschaft, nicht die Bewegung.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Mailadressen und Zuständigkeit der Warte (echte Klassen, echtes SQL) | 43 | 18 von 18 |
| Schritt-Animation (echtes app.js im Browser) | 35 | 21 von 21 |
| Statistik, Server | 270 | 90 von 90 |
| Statistik, Oberfläche | 113 | **25 von 36 — offen** |

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft
sie nichts und ist wertlos. Darunter: jedem Wart alles schicken, die
Zuständigkeit für die falsche Abrechnung prüfen, nur die erste Adresse
eines Kontos nehmen, dieselbe Adresse zweimal anschreiben, die
Animation bei einem Rückschritt zeigen, sie nicht mehr verschwinden
lassen, „weniger Bewegung" ignorieren und einen dunklen Vorhang
darüberlegen.

Drei Brüche blieben beim ersten Lauf grün: Zwei trafen den falschen
Knopf im Prüfstand (`.p-weg` ist Löschen, nicht Zurückgeben), einer
scheiterte daran, dass zwischen zwei Klicks mehr Zeit verging als das
Zeichen steht — die zweite Prüfung löst beide Schritte jetzt in einem
Zug aus. Zwei Erwartungen in der Mailprüfung waren falsch (der
Konfigurationsschlüssel heisst `mail_art_<art>`, und über die eigene
Handlung gibt es nie eine Mitteilung); sie wurden berichtigt, kein
Bruch gestrichen.

**Offen:** Die Gegenprobe der Statistik-Oberfläche steht bei 25 von 36.
Elf Brüche bleiben grün, weil die Zusicherungen den gesuchten Text im
ganzen Seitentext suchen statt in der Kachel oder Überschrift, um die es
geht. Das sind Lücken in der Prüfung, keine Fehler im Plugin — aber
solange sie offen sind, ist für diese elf Regeln nicht bewiesen, dass
sie geprüft werden.
