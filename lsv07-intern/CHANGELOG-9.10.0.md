# Interner Bereich 9.10.0 — alle Dateien verschlüsselt

Bis 9.9.0 lagen alle Dateien des internen Bereichs **im Klartext** unter
`wp-content/uploads/lsv07i-private/`: Atteste, Schwimmer-Dateien,
Trainingspläne, Wettkampf-Dokumente, Ticket- und Chat-Anhänge und
Profilbilder. Geschützt waren sie nur durch die Sperre des Webservers. Wer
an die Dateien kam, etwa über ein Backup oder einen falsch eingestellten
Server, konnte sie lesen.

Jetzt liegen sie **verschlüsselt** (AES-256-GCM). Den Schlüssel legt das
Plugin **selbst** in eine Datei **eine Ebene über dem Webordner**, wie
seit Abrechnung 1.18.0:

```
/…/verein/                       ← kein Weg aus dem Internet
├── lsv07-schluessel/
│   ├── abrechnung.php           ← Schlüssel der Abrechnung
│   └── intern.php               ← Schlüssel des internen Bereichs
└── httpdocs/                    ← Webordner mit WordPress
```

Der Schlüssel liegt nicht in der Datenbank und steckt in **keinem
WordPress-Backup** (BackWPup sichert nur den WordPress-Ordner). Ein
gestohlenes Backup enthält nur noch Rauschen.

**Zu tun ist nichts**, außer einmal die Notfallkopie zu hinterlegen.

## Was genau passiert

* **Neue Dateien** werden beim Hochladen verschlüsselt. Eine
  unverschlüsselte Fassung landet nie im Ordner, auch nicht kurz.
  Profilbilder werden im Speicher erzeugt und verschlüsselt abgelegt.
* **Alte Dateien** verschlüsselt das Plugin im Hintergrund. Es geht dazu
  den ganzen privaten Ordner durch, damit jede Datei erfasst wird, gleich
  aus welchem Bereich. Die Schutzdateien (`.htaccess` usw.) bleiben, wie
  sie sind. Jede Datei wird vor dem Ersetzen mit dem neuen Schlüssel allein
  geöffnet und verglichen und dann über eine Nachbardatei ersetzt.
* Danach wird geprüft, ob **jede** Datei verschlüsselt und lesbar ist.
  Erst dann wird ein etwaiger älterer Schlüssel aus der Datenbank entfernt
  (vorher als Lese-Schlüssel in die Schlüsseldatei übernommen).
* Bis eine alte Datei verschlüsselt ist, lässt sie sich ganz normal öffnen.
* **Downloads** funktionieren wie bisher: Das Plugin entschlüsselt beim
  Ausliefern, nur für Berechtigte.
* Jede Datei ist an ihren Namen gebunden. Eine verschlüsselte Datei lässt
  sich nicht unter fremdem Namen unterschieben.
* Lässt der Hoster die Schlüsseldatei erst später zu, werden alle Dateien
  automatisch noch einmal mit dem neuen Schlüssel verschlüsselt.

## Neu: Admin → Verein → Konfiguration → „Datensicherheit"

* wo der Schlüssel liegt,
* wie weit das Verschlüsseln der alten Dateien ist (mit „Jetzt
  weitermachen"),
* Warnungen (z. B. Schlüsseldatei fehlt),
* **Notfallkopie:** nur für die WordPress-Administration, nur nach
  Eingabe des eigenen Passworts (höchstens 5 Fehlversuche je
  Viertelstunde), jeder Abruf steht im Protokoll.

## Wichtig: die Notfallkopie

Die Schlüsseldatei ist absichtlich in keinem WordPress-Backup. Geht sie
verloren (Serverumzug ohne den Ordner `lsv07-schluessel`), öffnen sich
die Dateien **nur noch mit der Notfallkopie**. Einmal anzeigen, ausdrucken
und verschlossen beim Vorstand hinterlegen — zusammen mit der des
Abrechnungs-Plugins. Wiederherstellen: die Zeilen in die `wp-config.php`
eintragen.

Fehlt die Schlüsseldatei, wird **nicht** still ein neuer Schlüssel
erzeugt. Neue Dateien werden dann abgelehnt, bis die Notfallkopie
eingetragen ist. Ein unverschlüsselter Rückfall findet nicht statt.

Erlaubt der Hoster keine Datei über dem Webordner, wird trotzdem
verschlüsselt, dann mit einem Schlüssel in der Datenbank. Die Verwaltung
sagt es.

## Geprüft

Gegen echtes WordPress 6.8.3 mit MariaDB, jeder Schritt in einem eigenen
PHP-Prozess wie im Betrieb. Die Downloads laufen über die **echten**
Endpunkte (Schwimmer-Datei, Attest, Profilbild, öffentliches
Wettkampf-Dokument) und werden Byte für Byte verglichen.

Durchgespielt: Altbestand im Klartext und mit Datenbank-Schlüssel →
Umzug in die Schlüsseldatei → neuer Upload → neues Profilbild → alles
verschlüsseln (kein Klartext mehr auf der Platte, Schutzdateien
unverändert) → neuer Prozess → Cron ohne Webserver → Notfallkopie →
Datei fehlt (Upload abgelehnt) → Wiederherstellen aus der Notfallkopie →
Hoster erlaubt es nicht → Hoster erlaubt es später (alles wird neu
verschlüsselt) → eine unverschlüsselte Datei taucht zwischen Verschlüsseln
und Prüfen auf (das Plugin hält an und nennt sie, löscht nichts; nach dem
Neustart ist auch sie verschlüsselt).

Jede Regel wurde in der Gegenprobe einmal im echten Plugin gebrochen —
der Lauf muss rot werden. Drei Prüfungen waren dabei zunächst zu schwach
und wurden geschärft: das Profilbild (Vergleichswert kam aus dem
gebrochenen Download), die Notfallkopie (der Fall „Administrator ohne
manage_options" fehlte) und die Schlussprüfung (der Fall „späte
Klartext-Datei" fehlte).

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Verschlüsselte Dateien (13 Läufe) | 124 | 16 von 16 rot |
| Anzeige „Datensicherheit" | 8 | — |
| Oberfläche (Browser) | 210 | — |
| Bisherige Prüfungen (Zuständigkeit, Dateien/Chat, Tri/Fit, Abrechnung, Datenschutz, Meldungen, Wettkampf, Anmeldung, Mails) | 514 | unverändert grün |

**Grenze:** Wer den ganzen Server übernimmt und dort eigene Programme
ausführen kann, liest auch die Schlüsseldatei.

**Alte Backups:** Backups von **vor** dem Verschlüsseln enthalten die
Dateien noch im Klartext. Nach dem Verschlüsseln ein frisches Backup
anlegen und die alten löschen.
