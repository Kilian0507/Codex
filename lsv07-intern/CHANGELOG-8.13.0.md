# LSV07 Interner Bereich — 8.13.0

## Attest-Daten sind beim Öffnen einer Meldung immer aktuell

Bisher stand in einer gespeicherten Meldung das Attest-Datum so drin, wie es
beim Anlegen der Meldung galt. Wurde ein Attest danach erneuert, zeigte die
Meldung beim erneuten Öffnen weiterhin den alten Stand — teils rot als
„abgelaufen" markiert, obwohl längst ein neues vorlag.

Jetzt werden die Attest-Daten **bei jedem Öffnen frisch aus den Stammdaten
geladen**. Ein zwischenzeitlich erneuertes oder abgelaufenes Attest steht
damit sofort richtig in der Tabelle, inklusive der farbigen Markierung.

Unverändert bleibt: Innerhalb der Meldung lässt sich das Datum weiterhin
einzeln anpassen (das gilt dann nur für diese eine Meldung, die Stammdaten
bleiben unberührt). Name und Jahrgang bleiben wie bisher die Kopie aus der
Meldung — die sollen auch dann noch stimmen, wenn jemand später die
Mannschaft wechselt.

## Neu: Meldeliste als PDF exportieren

Neben „Excel-Export" steht jetzt **„PDF-Export"**. Das PDF hat denselben
Aufbau und dieselbe Darstellung wie die Excel-Liste — Titelzeile mit
Wettkampf und Mannschaft, darunter die Tabelle in derselben Spaltenfolge und
mit denselben Spaltenbreiten.

**Ein Unterschied ist beabsichtigt:** Die Spalte **„Attest bis" fehlt im
PDF**. Die Meldeliste geht typischerweise nach außen an den Ausrichter —
Gesundheitsdaten der Schwimmerinnen und Schwimmer haben dort nichts zu
suchen. Im Excel-Export (der intern zum Arbeiten dient) bleibt die Spalte
erhalten.

Staffeln erscheinen im PDF genauso wie im Excel-Export: mit „Staffel" in der
Namensspalte sowie Abschnitt, Wettkampfnummer und der eingetippten Strecke.
Längere Listen werden automatisch auf mehrere Seiten umgebrochen, die
Kopfzeile wird dabei auf jeder Seite wiederholt.

## Tests

- 23 neue Prüfungen im echten Browser, die den ganzen Ablauf durchspielen:
  - Eine gespeicherte Meldung mit altem Attest (2020) wird geöffnet, während
    die Stammdaten ein erneuertes Attest (2028) führen — die Tabelle zeigt
    den neuen Stand und markiert das Feld nicht mehr als abgelaufen.
  - Der PDF-Export wird wirklich ausgelöst, die heruntergeladene Datei wird
    gespeichert und im Inhalt geprüft: echtes PDF, Titel, alle sechs
    Spaltenüberschriften, Schwimmername, Staffelzeile und Meldezeit sind
    enthalten — und weder das Wort „Attest" noch ein Attest-Datum kommen
    darin vor.
  - Dateiname endet auf `.pdf` und nennt Wettkampf und Mannschaft.
- Statische Prüfung (doppelte IDs, doppelte JS-Funktionen, Navigationsziele)
  ohne Befund; alle zehn Oberflächen-Varianten (5 Rollen × hell/dunkel)
  rendern fehlerfrei; Klick-Durchlauf über alle Bereiche aller fünf Rollen
  (90 Klicks) ohne einen einzigen JavaScript-Fehler.
