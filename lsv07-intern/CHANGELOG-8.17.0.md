# LSV07 Interner Bereich — 8.17.0

## PDFs am Trainingsplan — im Vollbild nur die Seiten

Ein Trainingsplan kann jetzt PDFs tragen: eingescannte Pläne, Vorlagen des
Verbands, alles, was nicht als Sessions erfasst ist. Im Vollbild werden die
Seiten formatfüllend angezeigt — **ohne jede Werkzeugleiste**.

### Hochladen

Im Editor (Trainingsplan anlegen/bearbeiten) gibt es unter den Sessions den
Abschnitt **PDFs** mit dem Knopf „PDF hinzufügen".

- Bis zu **20 PDFs je Plan**, je maximal **20 MB**.
- Ausschließlich PDF. Geprüft wird an den tatsächlichen Bytes der Datei
  (MIME-Sniffing plus `%PDF-`-Kopf), nicht am Dateinamen — eine umbenannte
  Fremddatei kommt nicht durch.
- **Beim Anlegen** muss man nicht erst speichern: Die Datei wird vorgemerkt
  („wird beim Speichern hochgeladen") und direkt nach dem Speichern
  hochgeladen. Anlegen bleibt ein Arbeitsschritt.
- **Ein Plan aus nur einem Scan ist jetzt möglich.** Bisher verlangte das
  Speichern mindestens eine ausgefüllte Session; hat der Plan eine PDF, darf
  die Session-Liste leer bleiben.

In der Ansicht eines Plans stehen die PDFs unter den Sessions, je mit
**Vollbild** und **Herunterladen**.

### Vollbild: nur die Seiten, keine Knöpfe

In der Vollbild-Übersicht erscheint jede PDF als eigene Karte neben den
Übungen. Ein Tipp darauf öffnet die Seitenansicht.

**Warum nicht einfach einbetten.** Ein `<embed>` oder `<iframe>` überlässt
die Darstellung dem PDF-Betrachter des Browsers — und der bringt seine
eigene Werkzeugleiste mit (Zoom, Drehen, Drucken, Speichern). Der Parameter
`#toolbar=0` blendet sie nur in Chrome und Edge aus; Firefox und Safari
ignorieren ihn. Verlässlich ist das also nicht.

Deshalb zeichnet das Plugin die Seiten **selbst** — mit pdf.js, auf Canvas.
Übrig bleibt genau das, was gewünscht war: die Seitenfolge auf weißem Grund,
sonst nichts.

**Der Rückweg.** Ganz ohne Bedienelemente käme man am Tablet ohne Tastatur
nicht mehr aus der Ansicht heraus. Die eigenen Knöpfe (Zoom, „Zurück zum
Plan", Blättern, Schließen) sind deshalb **im Ruhezustand unsichtbar** und
kommen bei einer Berührung oder Mausbewegung für gut zwei Sekunden zurück —
wie bei einem Videoplayer. Im Ruhezustand ist der Bildschirm frei von
Bedienelementen; Esc funktioniert zusätzlich.

Weiter:

- **Die Größe wirkt auch auf PDFs.** Der +/−-Regler skaliert die Seiten und
  zeichnet sie dabei **neu** — vergrößert wird also scharf, nicht verpixelt.
  Die gespeicherte Einstellung des Geräts gilt wie bisher.
- **Blättern** (‹ ›, Pfeiltasten) wechselt in der Seitenansicht zwischen den
  PDFs des Plans.
- **Ein Plan ohne Übungen** startet im Vollbild direkt in der Seitenansicht;
  dort führt der einzige Knopf gleich wieder hinaus.
- Seiten werden mit der Pixeldichte des Geräts gerastert (bis Faktor 2), auf
  Tablets also scharf, und nacheinander gezeichnet, damit ein langer Plan
  nicht den Speicher sprengt.
- pdf.js liegt **lokal im Plugin** und wird erst geladen, wenn tatsächlich
  eine PDF angezeigt werden soll — kein fremdes CDN, keine externe Anfrage.

### Schutz der Dateien

Die PDFs liegen wie die Wettkampf-Dokumente außerhalb des Web-Zugriffs
(`uploads/lsv07i-private/trainingsplan/…`, per `.htaccess` gesperrt, mit
zufälligem Dateinamen). Der einzige Weg an eine Datei führt über den
Download-Endpunkt, und der prüft dieselbe Sichtbarkeit wie der Plan selbst:

- eigener Plan → sichtbar,
- fremder, **freigegebener** Plan → sichtbar,
- fremder, nicht freigegebener Plan → gesperrt,
- Admin → sieht alles.

Hochladen und Löschen kann nur, wem der Plan gehört (oder Admin). Beim
Löschen eines Plans werden seine PDFs und der Ordner mit entfernt — es
bleiben weder Dateien noch Datensätze zurück.

### Geprüft

- 51 Prüfungen gegen eine echte Datenbank mit den echten SQL-Texten und dem
  echten Dateisystem: Dateiprüfung (auch umbenannte Fremddateien, zu große,
  leere), Speicherort und `.htaccess`, Obergrenze von 20, kein verwaister
  Datensatz nach einem gescheiterten Schreibvorgang, die vier
  Sichtbarkeitsfälle, Abweisung fremder Uploads/Löschungen, Speichern ohne
  Sessions, restloses Aufräumen beim Löschen eines Plans.
- 41 Prüfungen in der echten Oberfläche mit einer echten PDF: gezeichnete
  Seiten statt `<embed>`, kein Bedienelement im Ruhezustand, Rückweg nach
  Berührung, scharfes Neuzeichnen beim Zoomen, Blättern, reiner Scan-Plan,
  Editor-Liste und Vormerken.
- 5 Prüfungen auf dem Telefon (390 px): Seite passt in die Breite, nichts
  läuft seitlich heraus.
- Bestehende Prüfungen unverändert grün: Vollbild-Übungen (58),
  Abrechnungs-Zugang (19), statische Prüfung, Klick-Durchlauf über alle
  fünf Nutzerarten (90 Klicks, 0 Fehler).
