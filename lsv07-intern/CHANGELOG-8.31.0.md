# LSV07 Interner Bereich — 8.31.0

## Auskunft und Löschung über die Werkzeuge von WordPress

WordPress bringt unter **Werkzeuge → Personenbezogene Daten exportieren**
und **→ löschen** einen fertigen Ablauf mit: Die betroffene Person
bestätigt ihre Anfrage per E-Mail, bekommt einen zeitlich begrenzten
Download, und jedes Plugin trägt über zwei Filter bei, was es über sie
gespeichert hat. Der interne Bereich war dort bisher nur zur Hälfte
angemeldet — Trainerprofil, Stammdaten, Chat, Tickets, Protokoll und
Rechte. **Alles andere fehlte**, und das war der grössere Teil: die
Schwimmerdaten selbst, Kontaktpersonen, Anwesenheiten, Bestzeiten,
Reflexionsbögen, Meldungen, Triathlon und Fitness.

Jetzt sind **alle 78 Tabellen** angebunden.

### Warum das nicht von Hand gepflegt wird

Jede Spalte einzeln zu beschriften wäre eine Liste, die beim nächsten
neuen Feld veraltet — und eine veraltete Auskunft ist eine unvollständige.
Darum wird **jede Spalte einer angebundenen Tabelle ausgegeben**,
beschriftet über ein gemeinsames Wörterbuch und sonst mit dem
Spaltennamen. Vollständigkeit geht vor schöner Beschriftung: Art. 15
verlangt die Daten, nicht die Formulierung.

Ausgenommen sind einzig **Geheimnisse** — Schlüssel, Token und Hashes. Sie
sind keine Auskunft über eine Person, sondern Zugangsmittel; in einer
Exportdatei wären sie ein Risiko ohne Nutzen.

### Die Abdeckung wird geprüft, nicht geglaubt

Eine Prüfung hält alle Tabellen des Plugins gegen zwei Listen: die
angebundenen und die ausdrücklich als „ohne Personenbezug" benannten. Was
in keiner von beiden steht, lässt die Prüfung durchfallen. Ohne das bliebe
eine neue Tabelle still aus der Auskunft heraus, und niemandem fiele es
auf — Auskunft und Löschung ruft höchstens einmal im Jahr jemand auf.

Gesucht wird dabei nach dem **Präfix-Gebrauch**, nicht nach
`CREATE TABLE`: Manche Tabelle entsteht über eine Variable, ihr Name
stünde dann nirgends im Text. Abgefragt wird sie aber ausgeschrieben.

### Eine Person, viele Kennungen

Im internen Bereich kann dieselbe Person ein WordPress-Konto, eine Zeile
im Personenverzeichnis, einen Trainer-Eintrag und einen Schwimmer-,
Triathlon- oder Fitness-Eintrag haben. Alle werden aus der einen
E-Mail-Adresse aufgelöst, die WordPress mitgibt — sonst käme auf dieselbe
Adresse je nach Tabelle eine andere Antwort.

**Auch wer gar keine Nummer hat, bekommt Auskunft.** Eine Kontaktperson —
typischerweise ein Elternteil — steht nur mit ihrer Adresse in den
Kontakten und in der Mailwarteschlange. „Zu dieser Adresse ist nichts
gespeichert" wäre dort schlicht falsch.

### Was gelöscht wird, was anonymisiert wird, was bleibt

| | |
|---|---|
| **Gelöscht** | Kontaktpersonen, hinterlegte Dateien (auch von der Platte), Bestzeiten, gemeldete Starts, eigene Anwesenheitszeilen, Reflexionsbögen samt Antworten, Mannschaftszuordnungen, Rechte, Protokoll, Mailwarteschlange, Sperrzähler |
| **Anonymisiert** | Nachrichten, erfasste Anwesenheiten, Trainingspläne, Vorlagen, Tickets, Saisons — der Inhalt bleibt für die übrigen Beteiligten, der Name geht |
| **Geleert** | Name, Geburtsdatum, Kontaktdaten und Notizen der Stammdaten; Bankverbindung und Anschrift der Abrechnungs-Stammdaten |
| **Aufbewahrt** | Abrechnungen samt Trainingstagen, Wettkämpfen, Fahrten und Sonderposten |

**Die Abrechnungen bleiben mit Personenbezug.** Sie sind Buchungsbelege:
§ 147 AO und § 257 HGB verlangen zehn Jahre Aufbewahrung, Art. 17 Abs. 3
lit. b DSGVO nimmt genau das vom Löschanspruch aus. WordPress bekommt das
als `items_retained` gemeldet, mit Begründung — nicht stillschweigend
übergangen und nicht stillschweigend gelöscht.

**Anonymisiert statt gelöscht** ist überall dort die Antwort, wo ein
Eintrag für ANDERE sinnvoll bleiben muss. Eine Nachricht aus einer
Unterhaltung zu löschen hiesse, dort den halben Gesprächsverlauf zu
entfernen; eine erfasste Anwesenheit zu löschen hiesse, die Liste eines
ganzen Trainingstags zu zerreissen. Also geht der Name weg und der Inhalt
bleibt.

Die **Stammdatenzeile selbst bleibt stehen**, ohne Namen, ohne
Geburtsdatum, ohne Adresse und ohne Notiz. Ganz gelöscht würde sie
Anwesenheitslisten und Statistiken früherer Jahre auseinanderfallen
lassen.

### Dazu

* Unter **Einstellungen → Datenschutz** steht jetzt ein Vorschlagstext für
  die Datenschutzerklärung. Verantworten muss sie der Verein; raten, was
  dieses Plugin speichert, soll er nicht müssen. Der Text benennt
  ausdrücklich die **Atteste als Gesundheitsdaten nach Art. 9 DSGVO** —
  das ist der heikelste Punkt im ganzen System.
* Die **alte Nachrichtentabelle** von vor dem Umstieg auf Unterhaltungen
  bleibt nach der Übernahme stehen — und ist damit auch
  auskunftspflichtig. Sie war bisher nirgends berücksichtigt.

## Geprüft

101 neue Prüfungen (`dsinttest.php`), die bestehenden 295 weiterhin grün.
Jede Zusicherung wurde **gegengeprüft**: absichtlich gebrochen, und
nachgesehen, ob die Prüfung es meldet. Eine Datenschutzprüfung, die auch
dann grün bleibt, wenn man die Auskunft unvollständig oder die Löschung
wirkungslos macht, prüft nichts.
