# LSV07 Interner Bereich — 8.25.0

## Meldung hochladen

Auf dem Meldungen-Tab steht neben *+ Neue Meldung* jetzt **Meldung
hochladen**. Erwartet wird die Datei, die der Excel-Export erzeugt —
derselbe Aufbau, nur in der anderen Richtung. Damit lässt sich eine
Meldeliste in Excel vorbereiten oder von jemand anderem übernehmen,
statt sie im Portal Zeile für Zeile nachzutippen.

Der Ablauf:

1. Wettkampf und Mannschaft wählen — in der Datei stehen nur Namen, und
   die Namen aus der Datei werden im Kader der gewählten Mannschaft
   gesucht.
2. Excel- oder CSV-Datei wählen. Gelesen wird sie **im Browser**; auf
   den Server geht nur die fertige Tabelle, nie die Datei selbst.
3. Die Vorschau zeigt jede Zeile mit ihrer Zeilennummer aus der Datei
   und was aus ihr wird.
4. *Übernehmen* füllt die Meldetabelle. **Gespeichert wird dort wie
   immer von Hand** — ein Import kann eine abgegebene Meldung also nicht
   ersetzen, ohne dass jemand hingesehen hat.

**Die Namen werden geduldig gesucht.** Verwendet wird derselbe Abgleich
wie beim Bestzeiten-Import: Er verzeiht fehlende Umlaute, vertauschte
Vor- und Nachnamen, „Nachname, Vorname", Doppelnamen und Tippfehler. Wo
er etwas zurechtgerückt hat, steht der Grund in der Vorschau.

Wer nicht gefunden wird, wird **benannt statt verschluckt**: Die Zeile
steht mit Zeilennummer und Namen in der Vorschau und wird nicht
übernommen. Dasselbe gilt, wenn mehrere Personen auf einen Namen passen.

Eine Zeile mit „Staffel" in der Namensspalte wird als Staffel
übernommen, eine Zeile ganz ohne Namen ebenfalls — ein Start ohne Namen
liesse sich niemandem zuordnen.

Die Kopfzeile wird in den ersten 15 Zeilen gesucht, der Titel über der
Tabelle stört also nicht. Spaltennamen müssen nicht wortgleich sein:
„Schwimmer", „Name", „JG", „WK-Nr." und ähnliche Schreibweisen werden
erkannt.

Hochladen darf, wer die Meldung auch bearbeiten darf
(`schwimmen.meldung.edit`) — ein eigenes Recht gibt es dafür nicht, denn
ein Import ist nichts anderes als schnelles Ausfüllen.

## Abschnitt, Wettkampfnummer und Strecke sind Freitext

Alle drei waren Auswahllisten. Damit gab das Portal vor, ein Abschnitt
sei eine Zahl von 1 bis n und eine Strecke stehe in der Bestzeitenliste.
Ausschreibungen halten sich daran nicht: „1a", „12/13", „4x50 Freistil
mixed" oder eine Sonderwertung gab es schlicht nicht einzugeben.

Jetzt sind es **Textfelder mit Vorschlagsliste**. Die Liste schlägt vor
— die Abschnitte und Nummern aus dem Kopf der Meldung, die bekannten
Strecken im Klartext —, aber getippt werden kann alles. Was nicht in der
Liste steht, geht trotzdem.

Bei den Strecken bleibt im Hintergrund das Kürzel erhalten, solange es
eine bekannte Strecke ist: Wer „50m Freistil" tippt oder aus der Liste
wählt, bei dem wird `50F` gespeichert, und Auswertungen greifen weiter.
Alles andere wird wortwörtlich abgelegt. Auch eine zurückgespielte
Exportdatei findet so zum Kürzel zurück.

Die Staffelzeile hat damit dieselben Felder wie jede andere Zeile.

**Was sich an den Daten ändert:** Die beiden Zahlenspalten werden beim
Update zu Textspalten. Vorhandene Zahlen wandern unverändert mit. Die
alte 0 bedeutete „nichts gewählt" und wird zum leeren Feld — sonst
stünde in jeder bisher leeren Zelle plötzlich eine 0.

## Nebenbei behoben

**Umlaute in einer CSV-Datei zerfielen.** Eine CSV ohne Bytefolgenmarke
hielt die Excel-Bibliothek für Windows-1252. Aus „Müller" wurde
„MÃ¼ller", und der Name fand im Kader niemanden mehr. Textdateien werden
jetzt selbst entschlüsselt: erst UTF-8, und nur wenn das nicht aufgeht,
die alte Windows-Tabelle.

**Der Excel-Export schrieb bei Freitext `NaN`.** Abschnitt und Nummer
wurden ungeprüft in Zahlen umgewandelt. Reine Zahlen liegen weiterhin
als Zahl in der Datei und lassen sich sortieren, alles andere als Text.

## Geprüft

* **58 Prüfungen** gegen echtes SQL: Freitext in allen drei Feldern,
  Kürzung auf die Spaltenbreite, die Umstellung der Spalten samt der
  alten 0, Zuordnung der Namen beim Import, Attest-Daten aus der Datei,
  Grenzen und Rechte.
* **47 Prüfungen** im Browser, darunter der ganze Weg Export → Datei →
  Import → Übernehmen → Speichern mit einer echten Excel-Datei, sowie
  CSV in UTF-8 mit und ohne Bytefolgenmarke und in Windows-1252.
* **20 Prüfungen** auf 320, 360, 414 und 768 Pixeln Breite: weder die
  Meldetabelle noch der Dialog laufen seitlich aus dem Bild.
* **252 Prüfungen** der Dialoge: Der neue Dialog hält sich an die Regeln
  aus 8.24.0 — Klick daneben und Markieren über den Rand schliessen ihn
  nicht.
