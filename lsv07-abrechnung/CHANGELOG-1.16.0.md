# Abrechnung 1.16.0 — Fehlermeldungen, die etwas sagen; Laden, das man sieht; Bewegung, die etwas bedeutet

## Fehlermeldungen

### Der eigentliche Fehler: Die meisten Meldungen kamen nie an

Der Server hatte schon vorher rund 150 ausformulierte Meldungen. Gezeigt
wurden die wenigsten: Lehnt der Server etwas ab, antwortet er mit Status
200 und „nein" — und fast jeder Aufrufer in der Oberfläche tat bei „nein"
einfach nichts. Der Knopf sprang zurück, der Grund blieb ungelesen.

Dazu kamen vier weitere Lücken:

* **Keine Verbindung** zeigte gar nichts.
* Wer nach einer Anfrage nur den Knopf zurücksetzte, **schaltete damit
  die Fehlermeldung ab**.
* Scheiterte das Laden einer Liste, stand dort **für immer „Wird
  geladen…"**.
* Schrieb ein anderes Plugin eine Notiz vor die Antwort, war sie
  **unlesbar** — und galt als Absage ohne Grund.

Jetzt meldet **eine einzige Stelle** jeden Fehlschlag, für jede Anfrage:

| Was geschieht | Was dasteht |
|---|---|
| Der Server sagt „nein" und nennt den Grund | sein Satz, wörtlich |
| Der Server sagt „nein" ohne Grund | dass er keinen genannt hat |
| Keine Verbindung | „Der Server ist nicht erreichbar. Es wurde nichts gespeichert …" |
| Sitzung abgelaufen | dass man sich neu anmelden muss, und dass nichts gespeichert ist |
| 403 / 404 / 413 / 429 / 500 / 502–504 | je ein eigener Satz mit Ursache und nächstem Schritt |
| Antwort unlesbar | dass nicht feststeht, ob es durchging — nachsehen, bevor man es wiederholt |

Eine Meldung bleibt so lange stehen, wie man zum Lesen braucht (sechs
bis zwanzig Sekunden, je nach Länge). Scheitert das Laden einer Liste,
steht der Grund **an ihrer Stelle**, mit einem Knopf *Seite neu laden*.
Die Abfrage der Mitteilungen im Hintergrund meldet einen Aussetzer
weiterhin nicht.

### Bessere Sätze vom Server

* **„Abrechnung nicht gefunden."** (elfmal), „Posten nicht gefunden.",
  „Saison nicht gefunden." sagen jetzt, warum vermutlich und was zu tun
  ist: *„Diese Abrechnung gibt es nicht mehr, oder sie gehört nicht zu
  Ihrem Bereich. Wahrscheinlich wurde sie inzwischen gelöscht oder
  zurückgezogen — bitte die Liste neu laden."*
* **„Ungültige Anfrage."** heisst jetzt, was es ist: Der
  Sicherheitsschlüssel der Seite ist abgelaufen, weil sie lange offen
  stand.
* **Upload:** „Der Upload ist unterwegs abgebrochen." stand für sieben
  verschiedene Gründe. Jetzt je einer — zu gross (mit der Grenze in MB),
  nur zum Teil angekommen, kein Platz im Zwischenspeicher, vom Server
  abgewiesen.
* **Zu grosse Datei, falsche Meldung:** Ist eine Anfrage grösser als
  `post_max_size`, wirft PHP sie komplett weg — mitsamt dem
  Einmal-Schlüssel. Die Antwort war „Ungültige Anfrage, Seite neu laden",
  und nach dem Neuladen passierte dasselbe. Das Tor erkennt den Fall
  jetzt und sagt, wie gross die Datei war und wie gross sie sein darf.

### Sicherheit: Der Text der Datenbank ging an den Browser

An **zwölf Stellen** wurde `$wpdb->last_error` an die Meldung gehängt.
Darin stehen Tabellen- und Spaltennamen, manchmal der abgelehnte Wert.
Das ist abgestellt: Der Text steht im Fehlerprotokoll des Servers, die
Meldung trägt eine sechsstellige Kennung, unter der er dort zu finden
ist.

Der Beleg einer fremden Abrechnung antwortete mit „gehört zu einem
anderen Konto" (403) — daran liess sich ablesen, welche Kennungen
vergeben sind. Jetzt kommt dieselbe Antwort wie bei einer Abrechnung,
die es nicht gibt (404).

## Laden

* **Platzhalter statt „Wird geladen…"** an allen dreissig Stellen: graue
  Balken mit einem Glanz. Die Worte stehen weiter im Dokument, für
  Vorleseprogramme.
* **Ladebalken:** war zwei Pixel hoch und weiss — auf heller Seite
  unsichtbar. Jetzt drei Pixel, blau mit dunkler Kante. Er erscheint
  erst nach 150 ms (kein Aufblitzen) und bleibt, bis *alle* Anfragen
  fertig sind.
* **Knöpfe** zeigen einen Ring, solange sie arbeiten (53 Stellen über
  einen Helfer). Neu dabei: *Probemail senden* — vorher ohne jedes
  Zeichen, wer zweimal drückte, verschickte zwei.
* **Beleg hochladen** zeigt den echten Fortschritt, danach „Angekommen —
  wird geprüft und verschlüsselt …". Lehnt der Server die Angaben der
  Rechnung ab, wird **nicht mehr trotzdem hochgeladen**.
* **Jahrespaket (ZIP) und Beleg-PDF:** Der Knopf sagt „Paket wird
  gebaut …", bis die Datei kommt. Lehnt der Server ab, steht sein Grund
  in der Anwendung statt auf einer weissen Seite. Der Beleg öffnet kein
  eigenes Fenster mehr (die Pop-up-Sperre verschluckte es).

**Dafür gibt es ein Cookie, und das muss dastehen:** `lsv07a_dl`. Ein
Download lässt sich von einem Skript nicht beobachten; die Oberfläche
schickt darum eine Zufallsmarke mit, und der Server legt sie als Cookie
zurück, sobald die Datei kommt. Darin steht nur diese Marke — kein Name,
keine Kennung, kein Recht. Es lebt höchstens eine Minute, wird danach
gelöscht, und der Server liest es nie. Es ist technisch notwendig und
braucht keine Einwilligung; in eine Liste der Cookies der Website gehört
es trotzdem.

## Bewegung

* **Das Zeichen in der Mitte** kommt jetzt auch bei: Rechnung angelegt,
  Beleg hochgeladen, Trainings übernommen (mit Anzahl; bei null keines),
  Nachtrag angelegt, Personenansicht gewechselt.
* Es **nimmt keine Klicks mehr an** — was darunter liegt, bleibt
  bedienbar. Der erste Klick oder Tastendruck irgendwo nimmt es weg und
  kommt trotzdem an.
* **Zeilen:** neue gleiten herein und leuchten auf, geänderte leuchten
  auf, gelöschte gehen sichtbar (an elf Listen und der Belegliste). Beim
  Wechsel von Quartal, Jahr oder Filter leuchtet nichts.
* **Gesamtbetrag** leuchtet auf, wenn er sich ändert. **Zahl am Reiter**
  pulst, wenn sie steigt. **Dialoge** gleiten herein, **Bereiche**
  blenden ein.
* **Weniger Bewegung** (Einstellung im Betriebssystem) schaltet alles
  davon ab — an einer einzigen Stelle, für alles, auch Künftiges.

**Ein Fehler aus 1.15.0, dabei gefunden:** Das Zeichen hing am `<body>`,
seine Farben sind aber nur innerhalb der Anwendung definiert. Der Haken
wurde gezeichnet und war nicht zu sehen. Jetzt hängt es in der
Anwendung; geprüft wird die Strichfarbe.

**Nicht gebaut, wie angekündigt:** kein Wackeln bei Fehlern, kein
Hochzählen von Beträgen, kein Zeichen für gewöhnliches Speichern, nichts,
das aufhält.

**Abweichung vom Vorschlag:** Eine gelöschte Zeile *verblasst und
gleitet zur Seite*, sie *schrumpft* nicht. Eine Tabellenzeile lässt sich
nicht in der Höhe zusammenziehen.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Fehlermeldungen, Laden, Bewegung — echte Vorlage, echtes Skript, echter Browser, eigener Server | 195 | 120 von 120 |
| Meldungen des Servers und Download-Signal (echte Klassen) | 115 | 77 von 77 |
| Schritt-Zeichen (aus 1.15.0, angepasst) | 35 | 21 von 21 |
| Statistik Server / Oberfläche, Mail, Belege (unverändert, erneut gelaufen) | 270 / 113 / 43 / 58 | — |

Der Prüfstand der Oberfläche wird jetzt aus `templates/app.php` gebaut —
aus der Seite, die WordPress ausliefert, nicht aus nachgebautem Markup.
Die Fehlerfälle sind echt: Eine abgerissene Verbindung ist dort eine
abgerissene Verbindung.

Gegenprobe heisst: Jede Regel wird einmal absichtlich gebrochen, in der
echten Datei im Plugin. Bleibt die Prüfung dabei grün, prüft sie nichts.
Beim ersten Lauf blieben drei Brüche grün:

* **404** und **stehenbleibende Fortschrittsanzeige** — die
  Zusicherungen waren zu schwach (die eine fand „404" auch im
  Auffangtext, die andere sah nur den Erfolgsfall). Beide geschärft,
  beide Brüche danach rot.
* **Kleinbuchstaben bei Grössenangaben** — der Bruch selbst war falsch
  gebaut. Berichtigt, danach rot.

Zwei echte Fehler hat erst die Prüfung gefunden: der Platzhalter in
einem verdeckten Bereich (die Prüfliste lädt schon beim Start) blieb
nach einem Fehlschlag ewig stehen, und eine Antwort mit vorangestellter
PHP-Notiz wurde nicht als JSON gelesen.

### Was nicht bewiesen ist

* **Die neuen Sätze an den Endpunkten:** Geprüft sind die Sätze selbst
  und dass im ganzen Plugin kein `last_error` mehr in eine Meldung geht
  (Durchsicht des Quelltexts). Dass ein bestimmter Endpunkt bei einem
  echten Datenbankfehler genau diesen Satz ausliefert, ist nicht einzeln
  durchgespielt.
* **Beleg einer fremden Abrechnung: 404 statt 403** — geändert, aber von
  keiner Prüfung begangen.
* **Zwei Schutzschichten ohne eigenen Beweis:** Die Marke des Downloads
  wird im Browser zusätzlich bereinigt, und das Schritt-Zeichen hat für
  „weniger Bewegung" neben der allgemeinen Regel eine eigene Klasse.
  Jede einzeln zu brechen bleibt grün, weil die andere Schicht hält.
  Beansprucht wird darum keine von beiden allein.
* **Die Wartezeit vor dem Neuladen** beim Wechsel der Personenansicht
  (0,9 s) ist nicht geprüft, nur dass Zeichen und Neuladen kommen.
* **Auf einem echten WordPress** ist nichts davon gelaufen — kein
  Mailserver, kein nginx, kein echtes `post_max_size`.
* **Weiter offen aus 1.15.0:** Die Gegenprobe der Statistik-Oberfläche
  steht bei 25 von 36.
