# LSV07 Interner Bereich — 7.66.1

## Öffentliche Wettkampf-Seite: alle Dokumente statt nur Ausschreibung

Nachträgliche Erweiterung von 7.66.0 auf ausdrücklichen Wunsch: Auf der
öffentlichen Übersichtsseite (`[lsv07_wettkaempfe]`) werden jetzt **alle drei**
hochgeladenen Dokumenttypen verlinkt, sobald der Wettkampf freigegeben ist —
nicht mehr nur die Ausschreibung:

- **Ausschreibung**
- **Meldeergebnis**
- **Protokoll**

Jedes Dokument erscheint nur, wenn es tatsächlich hochgeladen wurde. Der
Download-Endpunkt prüft weiterhin serverseitig, dass der zugehörige Wettkampf
wirklich freigegeben ist — ohne Freigabe bleibt jeder Dokumenttyp wie bisher
hinter Login + Sicherheits-Token + Leserecht gesperrt.

> **Zur Kenntnisnahme:** Meldeergebnis und Protokoll können je nach Verband
> personenbezogene Daten der Teilnehmenden enthalten (Namen, ggf. von
> Minderjährigen). Das wurde vor der Umsetzung ausdrücklich rückgefragt —
> die Entscheidung fiel bewusst auf „voll öffentlich, wie die Ausschreibung“.
> Falls das später doch eingeschränkt werden soll (z. B. Meldeergebnis/
> Protokoll nur mit Login abrufbar, aber als „vorhanden“ sichtbar), lässt
> sich das gezielt in `dok_download()` in `includes/class-ajax-wettkampf.php`
> anpassen.

### Geänderte Dateien

- `includes/class-ajax-wettkampf.php` — `dok_download()`: die Freigabe-Prüfung
  gilt jetzt für jeden Dokumenttyp, nicht mehr nur für `ausschreibung`.
- `includes/class-wettkampf-oeffentlich.php` — lädt und verlinkt alle
  Dokumenttypen je Wettkampf statt nur die Ausschreibung.
- `assets/css/wettkaempfe-oeffentlich.css` — neuer Container für mehrere
  Download-Knöpfe je Karte.
- `templates/main.php` — Hinweistext im Bearbeiten-Dialog angepasst.

### Tests

- HTTP-Sicherheitstests erweitert auf 18 Prüfungen: Meldeergebnis eines
  freigegebenen Wettkampfs ist jetzt ohne Login erreichbar; Dokumente eines
  NICHT freigegebenen Wettkampfs bleiben für alle drei Typen weiterhin
  gesperrt (403 ohne Login, 403 mit ungültigem Token, 200 mit Login + gültigem
  Token).
- Alle bisherigen Prüfungen erneut ausgeführt und weiterhin grün: 29
  Backend-Logik-Prüfungen, 36 Oberflächen-Prüfungen, vollständiger
  Plugin-Regressionstest (1862 Klicks, keine Befunde), statische Prüfung ohne
  Befund.
- Öffentliche Seite erneut visuell geprüft (Desktop + Mobil): mehrere
  Download-Knöpfe je Karte stapeln sich sauber, kein Layout-Überlauf.
