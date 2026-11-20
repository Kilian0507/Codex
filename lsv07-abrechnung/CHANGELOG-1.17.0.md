# Abrechnung 1.17.0 — Sicherheitsprüfung

Das Abrechnungsplugin wurde auf alle bekannten Klassen von
Sicherheitslücken geprüft: Zugriff auf fremde Vorgänge, fehlende
Rechte- und Statusprüfung, Selbstgenehmigung, Wettläufe, Verschlüsselung
von Belegen und Bankdaten, Datenlecks in Statistik und Personenansicht,
XSS, Formel-Injektion, Clickjacking und Datenschutz-Auskunft.

**Ehrlich vorweg:** 100 % Sicherheit für immer gibt es nicht. Diese
Version schließt jede gefundene Lücke, schützt in mehreren Schichten, und
jede Prüfung ist mit einem Test gesichert, der rot wird, wenn sie später
versehentlich entfällt.

## Vier-Augen-Prinzip

* **Niemand entscheidet über den eigenen Vorgang** — auch die
  Administration nicht: Genehmigen, Zurückgeben, Beanstanden, Bezahlen,
  Zahlungsvermerk entfernen, Wieder öffnen, Stand setzen; bei Rechnungen
  Genehmigen, Zurückgeben, Bezahlen. Vorher konnte ein Wart die eigene
  Abrechnung genehmigen, und eine Leitung die eigene Rechnung.
* **Zahlungsvermerk entfernen nur mit Begründung.** Das Protokoll hält
  Betrag, ursprüngliches Zahlungsdatum und zahlendes Konto fest, die
  Person bekommt eine Mitteilung. Der Dialog fragt jetzt nach dem Grund.
* Statuswechsel sind **atomar**: Klicken zwei Personen gleichzeitig auf
  „bezahlt", entsteht nur ein Vermerk und nur eine Mail.

## Wer was sieht

* **Statistik:** Ein Wart mit Bereich sieht nur seinen Bereich, die Kasse
  nur Genehmigtes und Bezahltes, die Leitung keine Entwürfe.
* **Beleg-PDF:** Die Kasse bekommt es nur für genehmigte und bezahlte
  Abrechnungen.
* An den Browser gehen nur noch die Einstellungen, die die Oberfläche
  braucht — nicht mehr die ganze Konfiguration.
* Fremde und nicht vorhandene Vorgänge geben dieselbe Antwort.
* **Datenschutz-Auskunft** nur mit eigenen Daten.

## Löschen

* Bezahltes wird nie gelöscht.
* Eigene Vorgänge nur nach der Regel für die Person (Entwurf,
  zurückgegeben). Ein Wart ohne Bereich löscht nichts; Wart und Leitung
  löschen keine fremden Entwürfe.
* Einen einzelnen falschen Beleg aus einer bezahlten Rechnung können
  Administration, Leitung und Kasse weiterhin entfernen (Datenschutzfall)
  — mit Begründung, Protokoll und Mitteilung.
* Bei der Datenschutz-Löschung wird das Protokoll anonymisiert statt
  gelöscht.

## Belege und Bankdaten

* **Belege werden nie im Klartext gespeichert.** Schlägt die
  Verschlüsselung fehl, wird der Upload abgelehnt — vorher lag der Beleg
  dann unverschlüsselt auf der Platte.
* **IBANs** werden verschlüsselt gespeichert. Bestehende werden beim
  nächsten Speichern umgestellt; gelesen werden beide Formen.
* **Schlüssel:** Ein Schlüsselring mit Kennung im Chiffrat. Der Schlüssel
  kann in `wp-config.php` stehen (`LSV07A_SCHLUESSEL`) statt in der
  Datenbank; der bisherige bleibt zum Lesen erhalten. **Vorher machte das
  Setzen der Konstante alle alten Belege unlesbar — das ist behoben.**
  Für einen Schlüsselwechsel gibt es `LSV07A_SCHLUESSEL_ALT`.
* Ein schwacher Schlüssel wird nur noch zum Lesen benutzt. Der Schlüssel
  in der Datenbank wird nur angelegt, nie überschrieben.
* Ablage außerhalb des Webroots möglich (`LSV07A_BELEG_DIR`); alte Belege
  bleiben lesbar.
* Downloads: nie zwischengespeichert, immer als Datei, bereinigter
  Dateiname, im Browser abgeschottet. Jeder Abruf eines fremden Belegs
  steht im Protokoll.

## Personenansicht

Wer als Administration „als Person ansehen" nutzt, verändert nichts mehr:
keine neu angelegte Abrechnung, kein Neurechnen, keine
„gelesen"-Markierung, keine Mitteilung und keine Mail.

Nebenbei gefunden: Die Mail „Quartal fällig" ging bei **jedem** Abholen
der Mitteilungen hinaus. Jetzt einmal je Quartal.

## Eingaben

* Mannschaft eines Trainingspostens muss dem Antragsteller zugeordnet
  sein.
* Obergrenzen: je Posten 24 Stunden, 2000 km, 31 Tage, 5000 € bei
  „Sonstiges"; je Abrechnung 400 Posten; Rechnung bis 50.000 € und
  20 Belege; Textlängen begrenzt.
* Konto speichern: erst alles prüfen, dann schreiben. Eine unbekannte
  Mannschaft wird nie zu „alle".
* CSV-Exporte entschärfen Formeln (`=HYPERLINK(…)` wird zu Text).

## Browser

* Die Seite wird nicht zwischengespeichert und lässt sich nicht in
  fremde Seiten einbetten (Clickjacking).
* Texte werden überall vollständig maskiert, auch Anführungszeichen.

## Datenbank

* `lsv07a_person.iban` und `lsv07a_rechnung.empf_iban` werden auf 255
  Zeichen verbreitert (nur verbreitert, für die verschlüsselte Form).

## Bitte einrichten (Betreiber)

1. In `wp-config.php`:
   `define( 'LSV07A_SCHLUESSEL', '…' );` — erzeugt mit
   `openssl rand -base64 32`. **Ab dann gehört die Konstante zwingend in
   jede Sicherung**, sonst sind neue Belege und IBANs nach einem
   Serverumzug verloren.
2. Empfohlen: `define( 'LSV07A_BELEG_DIR', '/pfad/ausserhalb/webroot' );`
   (für PHP beschreibbar).
3. **nginx:** Solange Belege unter `uploads` liegen:
   `location ~* /uploads/lsv07a-private/ { deny all; return 404; }` —
   die mitgelieferte `.htaccess` wirkt dort nicht.

## Was Sie merken

* Die eigene Abrechnung oder Rechnung genehmigt und bezahlt immer eine
  andere Person.
* Die Kasse sieht in der Statistik nur Genehmigtes und Bezahltes.
* „Zahlungsvermerk entfernen" fragt nach dem Grund.

## Geprüft

Gegen **echtes WordPress 6.8.3 mit MariaDB**; jede Prüfung wurde in der
Gegenprobe einmal im echten Plugin entfernt — der Test muss rot werden.

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Sicherheit (Vier-Augen, Statistik, Löschen, Grenzen, Storno, …) | 51 | 37 von 37 rot |
| Personenansicht ohne Seiteneffekte | 19 | (in den 37) |
| Tresor-Rundlauf (6 Prozesse: ohne/mit Konstante, Alt-Schlüssel, schwacher Schlüssel, Ablage) | 43 | (in den 37) |
| Statistik | 270 | 90 von 90 rot |
| Meldungen und Downloads | 116 | 78 von 78 rot |
| Belege | 58 | — |
| Mails | 43 | — |
| Oberfläche (Bewegung, Statistik, Schritte) | 201 / 113 / 35 | — |

Die Statistik-Prüfung hat neue Sollwerte, nachgerechnet aus den
Testdaten. Kasse: genehmigt + bezahlt = 588,50 € (vorher 773,50 €).
Leitung: ohne den Entwurf über 40 €.

**Nicht automatisch geprüft:** der neue Begründungsdialog beim
Zahlungsvermerk. Dass der Server ohne Grund ablehnt, ist geprüft.

## Bewusst nicht geändert

* Bestehende Klartext-IBANs werden nicht in einem Rutsch umgeschlüsselt,
  sondern beim nächsten Speichern — so kann eine Migration nichts
  verlieren.
* Ist die IBAN-Spalte noch zu schmal (Datenbank nicht aktualisiert),
  wird wie bisher im Klartext gespeichert statt Daten zu verlieren.
