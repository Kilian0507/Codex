# Abrechnung 1.18.0 — Schlüssel über dem Webordner

Belege und IBANs liegen seit 1.17.0 verschlüsselt. Der Schlüssel dazu
stand bisher in der **Datenbank** — und damit auch in jedem
Datenbank-Backup. Wer ein Backup (z. B. von BackWPup) in die Hände bekam,
hatte Daten **und** Schlüssel.

Jetzt legt das Plugin den Schlüssel **selbst** in eine Datei **eine Ebene
über dem Webordner**:

```
/…/verein/                       ← kein Weg aus dem Internet
├── lsv07-schluessel/
│   └── abrechnung.php           ← der Schlüssel
└── httpdocs/                    ← Webordner mit WordPress
```

Dort hat sie keine Adresse im Netz, und sie steckt in **keinem
WordPress-Backup** — BackWPup und ähnliche sichern nur den
WordPress-Ordner. Ein gestohlenes Backup oder eine gestohlene Datenbank
öffnet damit keinen Beleg und keine IBAN mehr.

**Zu tun ist nichts.** Beim ersten normalen Seitenaufruf legt das Plugin
die Datei an.

## Was genau passiert

1. **Anlegen:** zufälliger 32-Byte-Schlüssel, Datei nur für den eigenen
   Benutzer lesbar (Rechte 600), beginnt mit `<?php exit; ?>` (selbst wenn
   sie je abgerufen würde: leere Seite), dazu eine sperrende `.htaccess` im
   Ordner. Benutzt wird der Schlüssel erst, nachdem die Datei wieder
   eingelesen wurde. Mehrere gleichzeitige Seitenaufrufe legen **eine**
   Datei an, nicht mehrere.
2. **Umschlüsseln:** Alte Belege und IBANs werden im Hintergrund auf den
   neuen Schlüssel umgestellt — auch Belege und IBANs aus der Zeit vor der
   Verschlüsselung. Jeder neue Inhalt wird vor dem Schreiben mit dem neuen
   Schlüssel allein geöffnet und verglichen; Dateien werden über eine
   Nachbardatei ersetzt, eine IBAN nur, wenn sie inzwischen niemand
   geändert hat.
3. **Prüfen:** Lässt sich **alles** mit dem neuen Schlüssel allein öffnen?
4. **Erst dann** wird der alte Schlüssel aus der Datenbank gelöscht — und
   vorher als Lese-Schlüssel in die Schlüsseldatei übernommen. Selbst wenn
   etwas übersehen worden wäre, geht nichts verloren.

Lässt sich ein Inhalt nicht öffnen, hält das Umschlüsseln an, der alte
Schlüssel bleibt in der Datenbank, und die Verwaltung nennt den Beleg.

## Neu in der Verwaltung: „Datensicherheit"

Unter **Verwaltung → Sätze**:

* wo der Schlüssel liegt (Schlüsseldatei / wp-config.php / Datenbank),
* wie weit das Umschlüsseln ist (mit Knopf „Jetzt weitermachen"),
* Warnungen, z. B. wenn der Server nginx ist oder die Datei fehlt,
* **Notfallkopie:** nur für die WordPress-Administration, nur nach
  Eingabe des eigenen Passworts (höchstens 5 Fehlversuche je
  Viertelstunde), jeder Abruf steht im Protokoll.

## Wichtig: die Notfallkopie

Die Schlüsseldatei ist absichtlich in **keinem** WordPress-Backup. Geht
sie verloren (Serverumzug ohne sie, Neuinstallation), öffnen sich Belege
und IBANs **nur noch mit der Notfallkopie**.

**Einmal** in der Verwaltung „Notfallkopie anzeigen", ausdrucken oder
abschreiben und **verschlossen beim Vorstand** hinterlegen. Nicht per
Mail verschicken, nicht auf einem Rechner speichern.

Wiederherstellen: die zwei Zeilen der Notfallkopie in die `wp-config.php`
eintragen — dann öffnet sich wieder alles.

Bei einem **Serverumzug** den Ordner `lsv07-schluessel` mitnehmen. Die
Backups des Hosters sichern meist den ganzen Kundenordner und damit auch
diese Datei.

## Wenn der Hoster das nicht erlaubt

Darf PHP über dem Webordner nicht schreiben, bleibt alles wie bisher
(Schlüssel in der Datenbank), und die Verwaltung sagt es. Es wird nicht
bei jedem Seitenaufruf neu versucht, sondern höchstens einmal pro Stunde.

## Wenn die Datei fehlt

Fehlt die Schlüsseldatei später, wird **nicht** still ein neuer Schlüssel
erzeugt — neue Belege lägen sonst unter einem anderen Schlüssel als die
alten. Das Plugin nimmt dann keine neuen Belege an, bis die Notfallkopie
eingetragen oder die Datei zurückgespielt ist, und die Verwaltung sagt
genau das.

## Geprüft

Gegen echtes WordPress 6.8.3 mit MariaDB, jeder Schritt in einem eigenen
PHP-Prozess wie im Betrieb: alter Stand mit Datenbank-Schlüssel →
Umzug in die Datei → Umschlüsseln → neuer Prozess → Cron ohne Webserver →
Notfallkopie → Datei fehlt → Wiederherstellen aus der Notfallkopie →
unlesbarer Beleg hält das Umschlüsseln an → Hoster erlaubt es nicht →
sechs Prozesse legen im selben Augenblick die Datei an (drei Runden).

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Schlüsseldatei und Umschlüsseln (14 Läufe + 3 Wettlauf-Runden) | 133 | 14 von 15 rot |
| Anzeige „Datensicherheit" (Maskierung, Knöpfe, kein Schlüssel in der Übersicht) | 10 | — |
| Bisherige Prüfungen (Tresor, Sicherheit, Personenansicht, Belege, Statistik, Meldungen, Mails) | 600 | unverändert grün |
| Oberfläche (Bewegung, Statistik, Schritte) | 349 | unverändert grün |

Jede Regel wurde in der Gegenprobe einmal im echten Plugin gebrochen —
der Lauf muss rot werden. **Nicht nachweisbar:** dass die Datei exklusiv
angelegt wird (`fopen 'x'`). Ersetzt man das durch normales Schreiben,
bleibt der Test grün — die Lücke zwischen „gibt es noch nicht" und
„anlegen" ist so kurz, dass sechs gleichzeitige Prozesse sie in drei
Runden nie trafen. Das exklusive Anlegen garantiert das Betriebssystem.

Dabei ausserdem gefunden: Der bisherige Tresor-Prüfstand meldete „grün",
auch wenn ein Lauf gar nicht startete (Datenbank aus). Er verlangt jetzt
von jedem Lauf die Abschlusszeile.

Dabei gefunden und behoben: Ein Aufruf ohne Webserver (Cron,
Kommandozeile) merkte sich „geht hier nicht" für eine Stunde und
verzögerte so das Anlegen beim nächsten normalen Seitenaufruf.

**Grenze:** Wer den ganzen Server übernimmt und dort eigene Programme
ausführen kann, liest auch diese Datei. Dagegen hilft nur ein Schlüssel,
der gar nicht auf dem Server liegt — dann könnte das Plugin die Belege
aber auch nicht mehr anzeigen.

**Hinweis zu alten Backups:** Backups von **vor** dem Umschlüsseln
enthalten noch den alten Schlüssel samt alten Daten. Sie verschwinden mit
der Zeit (Aufbewahrung in BackWPup); wer sicher gehen will, löscht sie nach
dem Umschlüsseln und legt ein frisches an.
