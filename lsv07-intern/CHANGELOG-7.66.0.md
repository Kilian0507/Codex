# LSV07 Interner Bereich — 7.66.0

## Wettkämpfe: Freigabe, Dokumente, Erinnerungs-Mails, öffentliche Übersicht

Die Grundfunktionen der Kategorie Wettkämpfe bleiben vollständig erhalten. Neu
hinzugekommen ist ein Freigabe-Workflow mit Dokumenten-Upload, Erinnerungs-Mails
und einer öffentlichen Übersichtsseite.

### Pflichtfelder bei Anlage

- **Name**, **Ort**, **Zeitraum** (von/bis) sind jetzt alle drei Pflicht (Ort war
  bisher optional).
- **Ausschreibung als PDF** ist Voraussetzung für die Freigabe (nicht für das
  Anlegen selbst — der Wettkampf kann zuerst angelegt und die PDF danach
  nachgereicht werden).

### Dokumente (PDF-Upload)

Drei Dokumenttypen je Wettkampf: **Ausschreibung**, **Meldeergebnis**, **Protokoll**.

- Nur PDF, maximal 10 MB.
- Der tatsächliche Dateiinhalt wird geprüft (MIME-Sniffing per `finfo` +
  `%PDF-`-Signatur-Check) — eine in `.pdf` umbenannte Textdatei wird zuverlässig
  abgelehnt, unabhängig davon, was der Browser als Dateityp angibt.
- Dateien werden unter zufälligem Namen in einem geschützten Verzeichnis
  außerhalb des direkten Web-Zugriffs abgelegt (`.htaccess`-Sperre), Download
  nur über einen eigenen, geprüften Endpunkt — nie über eine direkte Datei-URL.
- Erneuter Upload desselben Dokumenttyps ersetzt die vorherige Datei (keine
  Dubletten).

### Freigabe (Approve)

- Neues Recht **„Wettkampfdaten freigeben (öffentliche Übersichtsseite)“**
  im Rechte-System. Wie bei den Meldungen zuvor bewusst **nicht** in einer der
  Standard-Vorlagen enthalten — muss gezielt vergeben werden.
- Sobald ein Wettkampf angelegt wird, erhalten alle Personen mit diesem Recht
  automatisch eine E-Mail mit der Bitte um Freigabe.
- Die Freigabe selbst ist erst möglich, wenn die Ausschreibung hochgeladen ist.
- **Sicherheitsverhalten, das über die ursprüngliche Anfrage hinausgeht:**
  Wird ein bereits freigegebener Wettkampf in einem der öffentlich sichtbaren
  Felder (Name, Ort, Zeitraum) bearbeitet, wird die Freigabe automatisch
  zurückgezogen und muss erneut bestätigt werden. Damit kann nicht versehentlich
  falsche oder veränderte Information unter einer alten Freigabe stehen bleiben.
  Falls das nicht gewünscht ist, kann das auf Wunsch angepasst werden.

### Erinnerungs-E-Mails

Je Wettkampf lassen sich beliebige E-Mail-Adressen als Erinnerungsempfänger
hinterlegen (bis zu 20). Automatisch per Cron (täglich, 07:00 Uhr) verschickt:

- **3 Tage vor Wettkampfbeginn:** Erinnerung, das Meldeergebnis hochzuladen.
- **1 Tag nach Wettkampfende:** Erinnerung, das Protokoll hochzuladen.

  > **Bitte prüfen:** In der Anfrage stand „1 Tag danach“ ohne genaue Angabe,
  > wonach. Da eine Erinnerung fürs Protokoll erst nach dem Wettkampf sinnvoll
  > ist, wurde das als „1 Tag nach Wettkampf**ende**“ interpretiert. Falls
  > etwas anderes gemeint war (z. B. 1 Tag nach der ersten Mail, oder 1 Tag
  > nach Wettkampf**beginn**), bitte kurz Bescheid geben — die Cron-Logik lässt
  > sich leicht anpassen.

Jede Erinnerung wird pro Wettkampf nur einmal verschickt (auch nach Cache-
Leerungen oder Server-Neustarts nachvollziehbar, da über ein Datenbankfeld
und nicht über einen Cache-Eintrag gesteuert).

### Öffentliche Übersichtsseite: `[lsv07_wettkaempfe]`

Neuer, eigenständiger Shortcode für eine öffentlich erreichbare Seite
(kein Login nötig) — getrennt vom bestehenden internen `[lsv07_intern]`.

- Zeigt **ausschließlich freigegebene** Wettkämpfe, chronologisch sortiert:
  „Anstehende Wettkämpfe“ (nächster zuerst) und „Vergangene Wettkämpfe“
  (neuester zuerst, dezent abgesetzt).
- Zeigt nur Name, Ort, Zeitraum und — falls vorhanden — einen Download-Link
  zur Ausschreibung. Meldeergebnis und Protokoll bleiben grundsätzlich intern
  und werden auf dieser Seite nicht verlinkt.
- Eigenes, in sich geschlossenes CSS (kein Zugriff auf interne Styles/Skripte),
  responsive, mit Unterstützung für helles/dunkles Farbschema.
- Alle Ausgaben escaped (`esc_html`/`esc_url`).

### Sicherheit — Zusammenfassung

- Serverseitige Rechteprüfung bei jeder Aktion (nicht nur Ausblenden in der
  Oberfläche).
- Echtes MIME-Sniffing statt Vertrauen auf Dateiendung oder Browser-Angabe,
  zusätzlich PDF-Signaturprüfung.
- Zufällige Dateinamen, geschütztes Upload-Verzeichnis, Download nur über
  geprüften Endpunkt mit sicheren Headern (`Content-Disposition`,
  `X-Content-Type-Options: nosniff`).
- Der öffentliche Download-Endpunkt gibt ausschließlich die Ausschreibung
  eines tatsächlich freigegebenen Wettkampfs frei — jede andere Kombination
  (nicht freigegeben, oder Meldeergebnis/Protokoll) verlangt Login, gültigen
  Sicherheits-Token und das passende Recht.
- Automatischer Rückzug der Freigabe bei Änderung öffentlich sichtbarer Daten
  (siehe oben).

### Tests

- 29 Backend-Logik-Prüfungen (CLI, In-Memory-Datenbank-Double).
- 17 echte HTTP-Prüfungen gegen einen laufenden PHP-Server: PDF-Annahme,
  Ablehnung gefälschter/zu großer Dateien, öffentlicher Download nur nach
  Freigabe, interner Download mit Login/Token-Pflicht, Ersetzen und Löschen
  von Dokumenten, Freigabe-Sperre ohne Ausschreibung.
- 36 Oberflächen-Prüfungen (Playwright): Freigabe-Badges in der Liste,
  Pflichtfeld-Kennzeichnung, Dokumenten-Upload-Bereich, Erinnerungs-Chips,
  Freigabe-/Zurückziehen-Ablauf inkl. Rechte-Steuerung.
- Vollständiger Regressionstest über das gesamte Plugin (alle Rollen,
  alle Bildschirmgrößen) ohne Auffälligkeiten.
- Öffentliche Übersichtsseite zusätzlich visuell geprüft (Desktop, Mobil,
  dunkles Farbschema) — keine Layout-Überläufe, keine JavaScript-Fehler.
