# LSV07 Interner Bereich — 8.16.2

## Bugfix: Abrechnung lud bei manchen nicht, Stammdaten ließen sich nicht speichern

Beide gemeldeten Fehler hatten dieselbe Ursache — und sie erklärt auch,
warum es bei manchen ging und bei manchen nicht.

**Der Trainer-Bereich war für alle Trainer sichtbar, das Backend ließ aber
nur Schwimmen-Leute durch.** Die Sichtbarkeit des Bereichs richtet sich
danach, ob jemand einen Trainer-Datensatz hat oder das Recht „eigene
Abrechnung" besitzt. Die Abrechnungs-Funktionen dahinter prüften dagegen
etwas anderes: „Administrator ODER irgendein Schwimmen-Recht".

Wer also Trainer ist, aber kein Schwimmen-Recht hat — typischerweise
**Triathlon- und Fitness-Trainer** — sah die Abrechnung, bekam aber bei
jedem Aufruf im Hintergrund „Keine Berechtigung":

- Beim Öffnen eines Quartals lud die Abrechnung nicht.
- Die Stammdaten (IBAN, Anschrift, Stundensatz) ließen sich nicht speichern.

Schwimmtrainer und Administratoren waren nicht betroffen — daher der
Eindruck, es funktioniere „bei manchen".

Behoben: Alle Funktionen der **eigenen** Abrechnung prüfen jetzt genau das,
was auch über die Sichtbarkeit entscheidet. Wer den Bereich sieht, kann ihn
auch benutzen.

**Der Schutz bleibt unverändert bestehen:** Jeder ändernde Zugriff prüft
weiterhin einzeln, dass die Abrechnung dem Aufrufer gehört — niemand kommt
an fremde Abrechnungen. Die Funktionen der Verwaltung (genehmigen,
zurückgeben, Gesamtübersicht) bleiben wie bisher dem Schwimmwart, Kassenwart
und Administrator vorbehalten.

## Zweiter Fall: Nutzer ohne Trainer-Datensatz

Wer die Abrechnung allein über das Recht „eigene Abrechnung" nutzt, ohne als
Trainer hinterlegt zu sein, lief zusätzlich in die Meldung „Kein
Trainer-Profil gefunden": Ein fehlendes Profil wurde bisher **nur für
Administratoren** automatisch angelegt.

Behoben: Das Profil wird jetzt für alle angelegt, die den Bereich
berechtigterweise nutzen — dieselbe Logik, die die Sonderabrechnung schon
länger verwendet. Für Nutzer ohne Berechtigung wird weiterhin keines
angelegt.

## Tests

- 19 neue Prüfungen gegen eine echte SQLite-Datenbank mit den echten
  SQL-Texten, über fünf Nutzerarten hinweg (Administrator, Schwimmtrainer,
  Triathlon-Trainer, Nutzer mit reinem Abrechnungs-Recht, Nutzer ohne
  Zugang):
  - Für jede Nutzerart wird geprüft, dass **Sichtbarkeit und Nutzbarkeit
    übereinstimmen** — genau die Lücke, die den Fehler verursacht hat.
  - Der gemeldete Ablauf im Einzelnen: Triathlon-Trainer öffnet ein Quartal
    (lädt jetzt) und speichert seine Stammdaten (gelingt jetzt, die Daten
    stehen anschließend in der Datenbank).
  - Ein Nutzer mit reinem Abrechnungs-Recht bekommt beim ersten Zugriff ein
    Trainer-Profil, und beim zweiten kein weiteres.
  - Ein Nutzer ohne Zugang wird weiterhin abgewiesen — und bekommt auch kein
    Profil untergeschoben.
  - Ein Trainer kann weiterhin **nicht** in einer fremden Abrechnung
    schreiben.
- Gegenprobe: Mit dem alten Stand schlagen 11 dieser Prüfungen fehl, alle
  mit „Keine Berechtigung" — der Test bildet den gemeldeten Fehler also
  tatsächlich ab.
- Statische Prüfung ohne Befund; alle zehn Oberflächen-Varianten rendern
  fehlerfrei; Klick-Durchlauf über alle Bereiche aller fünf Rollen
  (90 Klicks) ohne JavaScript-Fehler.
