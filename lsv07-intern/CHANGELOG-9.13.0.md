# Interner Bereich 9.13.0 — Abzeichen, Uhr im Vollbild, Logo auf allen PDFs

## Abzeichen

Neu unter **Schwimmen → Mannschaftsbuch → Abzeichen**.

* **Recht „Abzeichen verwalten“** (Rechteverwaltung → Schwimmen →
  Abzeichen). Wer es hat, legt Abzeichen für **seine** Mannschaft(en) an,
  ändert und löscht sie und vergibt sie an die Schwimmer dieser
  Mannschaft(en). Der Admin darf das überall.
* **Katalog je Mannschaft.** Jede Mannschaft hat ihre eigenen Abzeichen.
  Ein Abzeichen hat eine **Bezeichnung** und optional ein **Bild**.
* **Vergabe** in einer Tabelle: Schwimmer × Abzeichen, ein Häkchen je
  Abzeichen. Ein Schwimmer kann beliebig viele Abzeichen haben. Das
  Vergabedatum wird gespeichert und steht am Häkchen.
* **Sehen** dürfen alle Trainer der Mannschaft, Schwimmwart und Admin, nur
  lesend. Trainer anderer Mannschaften sehen nichts. Den Reiter bekommt
  nur, wer verwalten darf oder eine Mannschaft sieht, die schon Abzeichen
  hat. Alle anderen sehen keinen leeren Reiter.
* **PDF-Übersicht** („Übersicht als PDF“), mit Seitenlogo und
  Abzeichen-Bildern:
  * **Je Schwimmer:** welche Abzeichen er hat,
  * **Je Abzeichen:** wer es hat, mit Anzahl.
* Zu den Schwimmern einer Mannschaft zählen die aktiven Schwimmer der
  Stamm-Mannschaft und die, die ihr zusätzlich zugeordnet sind.

**Sicherheit und Datenschutz:**

* Bilder: nur JPG, PNG, WebP oder GIF, höchstens 3 MB und 4000 Pixel
  Kantenlänge. Jedes Bild wird neu gezeichnet: auf höchstens 256 Pixel
  verkleinert, als PNG, ohne Metadaten. Eingeschleuste Inhalte (z. B. SVG
  mit Skript oder Code mit Bild-Endung) werden abgewiesen.
* Die Bilder liegen **verschlüsselt** im privaten Ordner. Ausgeliefert
  werden sie nur nach derselben Leseprüfung.
* Jede Aktion prüft der Server: Recht **und** eigene Mannschaft, ebenso
  dass der Schwimmer zur Mannschaft gehört und aktiv ist. Eine fremde
  Mannschaft lässt sich auch über eine mitgeschickte Kennung nicht
  erreichen.
* Bezeichnungen werden bereinigt und überall maskiert angezeigt.
* Anlegen, Ändern, Löschen, Vergeben und Entziehen stehen im Protokoll.
* Datenschutz-Auskunft und Löschen auf Anfrage erfassen die Abzeichen
  eines Schwimmers.

## Vollbild: Uhrzeit in der Leiste

Oben in der Leiste des Trainingsplan-Vollbilds steht die **aktuelle
Uhrzeit mit Sekunden** (Uhr des Geräts). Sie hat feste Ziffernbreite und
ist auf die volle Sekunde ausgerichtet. Beim Schliessen hält sie an.

## Logo auf allen PDFs

Alle PDFs des internen Bereichs haben jetzt den Kopf wie der
Trainingsplan: **oben links das WordPress-Seitenlogo**, daneben Titel und
Untertitel in dunkler Schrift, darunter eine feine Linie.

| PDF | Änderung |
|---|---|
| Abrechnung (Quartal) | Logo, Titel daneben |
| Jahresübersicht Abrechnungen | Logo, Titel daneben |
| Meldeliste | Logo, Titel daneben |
| Bahnenbelegung | Logo, Titel daneben |
| Saisonbericht | Logo, **farbiger Kopf entfernt** |
| Akte | Logo auf jeder Seite, **farbiger Kopf entfernt** |
| Trainingsplan | unverändert (Vorbild) |
| Abzeichen | neu, mit Logo |
| Bestzeiten-Druckseite | Logo oben links neben dem Titel |

Ist kein Seitenlogo gesetzt oder lädt es nicht, bleibt die Ecke leer, und
das PDF entsteht trotzdem. Das Logo wird einmal geladen und für jedes PDF
wiederverwendet.

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Server gegen echtes WordPress 6.8.3 (Rechte, Zuständigkeit, Anlegen/Ändern/Löschen, Bilder, Vergabe, Auslieferung, Datenschutz, Bestzeiten-Logo) | 45 |
| Browser (Uhr mit gesteuerter Zeit, Abzeichen-Ansicht mit und ohne Recht, Vergabe, Dialog, Abzeichen-PDF, Kopf aller PDFs mit und ohne Logo) | 93 |

**Gegenprobe:** Jede Regel wurde einmal im echten Plugin gebrochen, und
der Lauf muss rot werden: **34 von 34 rot**, nach dem Zurücksetzen wieder
grün. Die Prüfung auf den farbigen Kopfbalken war zunächst zu schwach.
jsPDF schreibt die Masse mit vielen Nachkommastellen, die Prüfung suchte
gerundete. Sie ist geschärft, danach lief die ganze Gegenprobe erneut.

Bisherige Prüfungen grün: Oberfläche 210, Trainingsplan 28 + 67,
Version 9.12.0 (24 + 31), Datenschutz 58, Zuständigkeit 59,
verschlüsselte Dateien.
