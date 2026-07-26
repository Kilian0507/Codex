# Konzept: WordPress-Plugin „Mitgliederverwaltung Schwimmverein“

Status: umgesetzt — das Plugin liegt unter [`../schwimmverein-mitgliederverwaltung/`](../schwimmverein-mitgliederverwaltung/)
Zielplattform: WordPress-Plugin (eigenständig, kein Fremd-Plugin als Basis)

## 1. Ausgangslage & Leitprinzip

Der Verein gliedert sich derzeit in **3 Sparten** und erhebt unterschiedliche Beiträge je nach
Sparte, Alter und Status. Zahlungen laufen teils per SEPA-Lastschrift, teils per Überweisung.
Mitglieder sollen sich einloggen, Nachrichten lesen und ihre Stammdaten pflegen können.

**Leitprinzip: Alles ist konfigurierbar.** Die 3 Sparten, die Beitragsarten, die Rollen, die
Stammdatenfelder, die Mitgliedsstatus, die Zahlarten, die Exportlisten — nichts davon wird im
Code festgeschrieben. Das Plugin liefert eine *Mechanik* und leere Stammdatentabellen; der
Verein legt seine konkrete Struktur zur Laufzeit über die Oberfläche selbst an. Eine vierte
Sparte, ein neues Beitragsmodell, ein zusätzliches Stammdatenfeld oder eine neue Rolle sind
Konfigurationsvorgänge — **kein Update des Plugins**.

Konsequenz für die Umsetzung: Das System wird als **metadatengetriebene Anwendung** gebaut.
Feldlisten, Auswahlwerte, Regeln und Berechtigungen liegen in der Datenbank, nicht in
PHP-Konstanten. Die Oberflächen (Formulare, Listen, Filter, Exporte) rendern sich aus diesen
Metadaten.

Bewusst **nicht** Ziel dieses Dokuments: Code. Es ist die fachliche und technische Grundlage,
auf der im nächsten Schritt implementiert werden kann.

## 2. Was ist konfigurierbar, was ist Kern?

Vollständige Variabilität hat Grenzen — ein Minimum an Struktur muss fest bleiben, sonst gibt
es keine Rechnungslogik, keine Rechteprüfung und keine sinnvollen Auswertungen mehr. Die
Trennlinie:

| Bereich | Konfigurierbar zur Laufzeit | Fest im Kern |
|---|---|---|
| Organisationsstruktur | Sparten, Untergruppen/Trainingsgruppen, Hierarchie, beliebig viele Ebenen | Es *gibt* Struktureinheiten, an denen Mitgliedschaften und Beiträge hängen |
| Stammdaten | Alle Felder frei anlegbar: Typ, Pflicht, Validierung, Reihenfolge, Sichtbarkeit, Bearbeitbarkeit je Rolle | Technische Identität: interne ID, Mitgliedsnummer, Anlagedatum |
| Mitgliedsstatus | Statuswerte + erlaubte Übergänge frei definierbar | Ein Mitglied hat genau einen aktuellen Status |
| Beiträge | Beitragsarten, Bedingungen, Beträge, Turnus, Rabatte, Staffeln — als Regelwerk | Forderung = Betrag + Zeitraum + Mitglied + Status |
| Rollen & Rechte | Rollen frei anlegbar, Rechte einzeln zuschaltbar, Sichtbarkeitsbereich wählbar | Der Katalog der *möglichen* Rechte (je Modul registriert) |
| Zahlarten | Frei anlegbar (Lastschrift, Überweisung, Bar, …) inkl. Verhalten | Eine Zahlung ordnet Betrag + Datum + Zahler einer Forderung zu |
| Zahldateien | Format-Profile (pain.008/pain.001, Versionen, Bank-CSV) frei anlegbar | Erzeugter Lauf wird protokolliert und ist unveränderlich |
| Nachrichten | Kategorien, Zielgruppen-Regeln, Vorlagen | Sichtbarkeitsprüfung beim Abruf |
| Exporte | Export-Profile: Entität, Spalten, Filter, Format, Berechtigung | Der Export-Motor selbst |
| Dokumente/Vorlagen | Dokumenttypen, E-Mail-/PDF-Vorlagen mit Platzhaltern | Platzhalter-Auflösung |

Alles in Spalte 2 wird über Konfigurationsseiten gepflegt und ist mandantenspezifisch — das
Plugin wird dadurch auch für andere Vereine wiederverwendbar, nicht nur für diesen einen.

## 3. Datenmodell

### 3.1 Hybrides Modell: Kern + frei definierbare Felder

Reines EAV („alles ist ein Feld-Wert-Paar“) wäre maximal flexibel, aber langsam und für
Auswertungen unangenehm. Reine feste Spalten wären schnell, aber nicht erweiterbar. Deshalb
**hybrid**:

- **Kernspalten** in echten Tabellen für alles, was das System selbst braucht: ID,
  Mitgliedsnummer, Status-ID, Anlage-/Änderungsdatum, verknüpfter WP-Benutzer,
  Löschmarkierung. Diese Felder sind indiziert und schnell filterbar.
- **Frei definierte Felder** in einer Feldwert-Tabelle, typisiert getrennt gespeichert
  (`value_text`, `value_number`, `value_date`, `value_json`), damit Sortierung und
  Bereichsfilter (z. B. „Geburtsdatum zwischen …“) korrekt funktionieren und indiziert
  werden können.
- Häufig gefilterte Felder können optional als **materialisierte Spalte** gespiegelt werden
  (Performance-Ventil, falls die Mitgliederzahl stark wächst).

Wichtig: Auch scheinbar „selbstverständliche“ Felder wie Vorname, Nachname, Adresse,
Geburtsdatum sind **frei definierte Felder** — sie werden lediglich bei der Erstinstallation
als vorbelegte Standardkonfiguration angelegt und können umbenannt, ergänzt, ausgeblendet
oder gelöscht werden. Damit das System trotzdem weiß, wie ein Mitglied anzusprechen und zu
sortieren ist, bekommen einzelne Felder eine **Systemrolle** (`display_name`, `sort_name`,
`birthdate`, `email`, `iban`) — die Rolle ist fest, das dahinterliegende Feld frei wählbar.

### 3.2 Feldtypen

Beim Anlegen eines Feldes wählbar: Text (ein-/mehrzeilig), Zahl, Betrag, Datum, Ja/Nein,
Einfachauswahl, Mehrfachauswahl, E-Mail, Telefon, URL, IBAN/BIC, Datei/Upload, Verweis auf
ein anderes Mitglied, berechnetes Feld (Formel, z. B. Alter aus Geburtsdatum), Überschrift/
Trenner (rein optisch).

Je Feld konfigurierbar:

- Bezeichnung, Hilfetext, interner Schlüssel
- Pflichtfeld ja/nein, Validierungsregel (Regex, Min/Max, IBAN-Prüfsumme)
- Standardwert, Auswahloptionen (bei Auswahlfeldern, inkl. eigener Reihenfolge)
- Gruppierung in Abschnitte/Reiter, Sortierreihenfolge
- **Sichtbarkeit je Rolle** (sichtbar / verborgen)
- **Bearbeitbarkeit je Rolle** (bearbeitbar / nur lesen / bearbeitbar mit Freigabe)
- Gültigkeitsbereich: gilt für alle Mitglieder oder nur für Mitglieder bestimmter Sparten
- DSGVO-Kennzeichen: sensibel ja/nein, Aufbewahrungsfrist, in DSGVO-Auskunft enthalten
- Sichtbarkeit in Listen/Exporten als Spalte

Das gleiche Feldsystem gilt nicht nur für Mitglieder, sondern auch für **Sparten, Gruppen,
Zahlungen und Beitragsarten** — überall können Zusatzfelder angelegt werden.

### 3.3 Strukturen: Sparten, Gruppen, beliebige Einheiten

Statt einer festen Ebene „Sparte“ gibt es einen generischen **Strukturbaum**:

- Jede Einheit hat: Name, Kurzbezeichnung, Typ, übergeordnete Einheit (optional), Leitung,
  aktiv/inaktiv, eigene Zusatzfelder.
- Einheitstypen sind selbst konfigurierbar (z. B. „Sparte“, „Trainingsgruppe“, „Mannschaft“,
  „Standort“) — der Verein bestimmt Benennung und Tiefe der Hierarchie.
- Ein Mitglied kann **beliebig vielen Einheiten** zugeordnet sein, jede Zuordnung mit
  eigenem Zeitraum (Eintritt/Austritt in die Einheit), eigener Rolle in der Einheit
  (z. B. „Aktiv“, „Trainer“) und eigenem Beitragsbezug.
- Rechte und Nachrichten-Zielgruppen können sich auf jede Ebene beziehen; Vererbung nach
  unten ist einstellbar („Sparten-Leiter sieht alle Untergruppen“ ja/nein).

Damit sind die 3 Sparten der *aktuelle Datenbestand*, nicht die Systemgrenze.

### 3.4 Mitgliedsstatus als konfigurierbarer Lebenszyklus

Statuswerte (Interessent, aktiv, passiv, ruhend, Ehrenmitglied, ausgetreten, gekündigt zum …)
sind frei anlegbar. Je Status konfigurierbar:

- Bezeichnung, Farbe/Kennzeichnung in Listen
- „zählt als aktives Mitglied“ ja/nein (für Statistik/Meldungen an Verbände)
- „beitragspflichtig“ ja/nein (steuert die Forderungserzeugung)
- „Login möglich“ ja/nein (steuert den Self-Service-Zugang)
- erlaubte Folgestatus (einfache Zustandsmaschine) und ob beim Wechsel ein Datum/Grund
  erfasst werden muss
- automatische Aktionen beim Wechsel (Nachricht senden, offene Forderungen stornieren,
  Mandat deaktivieren) — aus einer Aktionsliste wählbar

### 3.5 Mitgliedsnummern

Nummernkreis konfigurierbar: Präfix, Jahresanteil, Stellenanzahl, Startwert, ob pro Sparte
eigener Kreis (z. B. `SV-2026-00042` oder schlicht `1042`). Manuelle Vergabe ebenfalls
zulassbar.

## 4. Beitragssystem als Regelwerk

Beiträge sind der variabelste Teil und werden deshalb nicht als Liste fester Beträge, sondern
als **Regelwerk** abgebildet.

### 4.1 Beitragsart

Frei anlegbar, je Beitragsart konfigurierbar:

- Bezeichnung, Buchungskonto/Kostenstelle (optional, für die Buchhaltung)
- Betrag (fest) **oder** Betragsformel (z. B. abhängig von einem Feldwert)
- Turnus: einmalig, monatlich, quartalsweise, halbjährlich, jährlich, frei definierter
  Zyklus; dazu Fälligkeitsregel (z. B. „zum 01.03.“, „15 Tage nach Eintritt“)
- Anteilige Berechnung bei unterjährigem Ein-/Austritt: keine, monatsgenau, taggenau,
  quartalsweise — konfigurierbar
- Gültigkeitszeitraum der Beitragsart (für Beitragserhöhungen: alte Art endet, neue beginnt,
  Historie bleibt nachvollziehbar)
- Priorität/Reihenfolge bei der Auswertung

### 4.2 Bedingungen (wer bekommt diesen Beitrag?)

Je Beitragsart eine Bedingungsgruppe aus frei kombinierbaren Regeln (UND/ODER, Verschachtelung):

- Mitglied ist Einheit X zugeordnet (Sparte/Gruppe)
- Status ist einer von …
- Alter zwischen X und Y (Stichtag konfigurierbar: Jahresbeginn, Geburtstag, Eintrittsdatum)
- beliebiges **frei definiertes Feld** erfüllt eine Bedingung (Gleichheit, Bereich, enthält,
  leer/nicht leer) — dadurch sind auch Fälle abbildbar, die heute noch niemand vorhersieht
  (z. B. „Feld *Ermäßigungsnachweis* = ja“)
- Anzahl der Mitgliedschaften des Mitglieds (für Mehrsparten-Rabatte)
- Position im Familienverbund (z. B. „ab 3. Kind“)

### 4.3 Rabatte, Zuschläge, Deckelungen

Als eigene Regelart mit derselben Bedingungslogik, angewendet auf das Zwischenergebnis:

- Rabatt absolut oder prozentual, Zuschlag absolut oder prozentual
- Höchst-/Mindestbetrag pro Mitglied oder pro Familienverbund (Familien-Deckel)
- Stapelbarkeit und Reihenfolge konfigurierbar (welcher Rabatt greift zuerst, schließen sich
  Rabatte gegenseitig aus)

### 4.4 Berechnung und Nachvollziehbarkeit

Ein **Beitragslauf** (manuell oder per Zeitsteuerung) wertet für jedes beitragspflichtige
Mitglied das Regelwerk aus und erzeugt Forderungen. Entscheidend für die Praxis: Zu jeder
erzeugten Forderung wird gespeichert, **welche Regeln in welcher Reihenfolge gegriffen
haben** („Warum zahlt dieses Mitglied 87,50 €?“). Vor dem Erzeugen gibt es eine
**Vorschau/Simulation** des gesamten Laufs — inklusive Differenzanzeige zum Vorjahr — damit
Fehlkonfigurationen auffallen, bevor Forderungen entstehen oder Geld eingezogen wird.

## 5. Rollen und Rechte

### 5.1 Frei anlegbare Rollen

Es gibt **keine** fest programmierten Rollen. Jedes Modul registriert beim Laden seine
möglichen **Rechte** (z. B. `Mitglieder anzeigen`, `Mitglieder bearbeiten`,
`Bankdaten anzeigen`, `Zahlung erfassen`, `Zahldatei erzeugen`, `Beitragsart verwalten`,
`Nachricht verfassen`, `Rollen verwalten`, `Export ausführen`). Diese Rechte erscheinen in
einer Matrix, in der der Verein beliebig viele eigene Rollen anlegt und Häkchen setzt.

Ausgeliefert wird lediglich ein **Satz Vorlagen** (Vereinsverwaltung, Kassenwart,
Sparten-Leiter, Übungsleiter, Vorstand, Mitglied), der beim Einrichten übernommen werden
*kann* — jede dieser Rollen ist danach frei änderbar, umbenennbar und löschbar. Einzige
Ausnahme: eine Rolle mit dem Recht `Rollen verwalten` muss immer existieren, damit sich der
Verein nicht selbst aussperrt.

### 5.2 Sichtbarkeitsbereich (Scope)

Zu jedem Recht gehört ein konfigurierbarer Geltungsbereich, sonst bräuchte man für jede
Sparte eine eigene Rolle:

- **alle** — vereinsweit
- **eigene Einheit(en)** — nur die Sparten/Gruppen, denen die Person zugeordnet ist,
  optional inklusive Untereinheiten
- **eigene Gruppe** — nur die Trainingsgruppe, die die Person leitet
- **nur eigener Datensatz** — Self-Service

Dadurch ist „Sparten-Leiter“ eine einzige Rolle, deren Wirkung sich pro Person aus der
Zuordnung ergibt. Eine Person kann mehrere Rollen haben; die Rechte addieren sich, der
engste Scope gewinnt bei Konflikten nicht automatisch — die Auflösung ist explizit
dokumentiert (Rechte vereinigen sich, Scopes vereinigen sich).

### 5.3 Feldbezogene Rechte

Ergänzend zu den Modulrechten wirkt die in 3.2 beschriebene Feldkonfiguration: Auch wer
Mitglieder bearbeiten darf, sieht die IBAN nur, wenn das Feld für seine Rolle sichtbar
geschaltet ist. Das ist der Hebel, mit dem Trainer Kontaktdaten pflegen können, ohne je
Bankdaten zu sehen.

### 5.4 Mitglieder-Self-Service

Die Mitgliedsrolle ist technisch eine Rolle wie jede andere, nur mit Scope „nur eigener
Datensatz“. Konfigurierbar ist damit auch, wie viel Self-Service der Verein zulässt:

- welche Felder das Mitglied selbst ändern darf (direkt, gar nicht, oder **mit Freigabe**)
- ob Familienangehörige mitverwaltet werden dürfen (Eltern für Kinder)
- welche Nachrichtenkategorien sichtbar sind
- ob Beitrags-/Zahlungshistorie und Belege einsehbar sind
- ob Austritt/Sparten-Wechsel online beantragt werden kann (erzeugt Antrag zur Freigabe)

Der **Freigabe-Workflow** ist pro Feld einstellbar: Änderungen an unkritischen Feldern
(Telefon) greifen sofort, Änderungen an kritischen (IBAN, Name, Geburtsdatum) werden als
Antrag gespeichert und erst nach Bestätigung durch eine berechtigte Person übernommen —
mit Benachrichtigung an beide Seiten und Eintrag im Änderungsprotokoll.

## 6. Zahlungsverkehr

### 6.1 Konfigurierbare Zahlarten

Zahlarten sind Stammdaten, keine Programmkonstanten. Je Zahlart konfigurierbar:

- Bezeichnung (SEPA-Lastschrift, Überweisung, Barzahlung, Kartenzahlung, Verrechnung …)
- **Verhalten**: erzeugt Zahldatei / wird manuell erfasst / wird importiert
- benötigt ein Mandat ja/nein
- benötigt Bankverbindung ja/nein
- Standard für neue Mitglieder ja/nein
- welche Zahlarten das Mitglied im Self-Service selbst wählen darf

Die Zahlart wird pro Mitglied gesetzt und kann pro Beitragsart/Forderung überschrieben
werden — ein Mitglied kann also den Jahresbeitrag einziehen lassen und die Wettkampf­
pauschale überweisen.

### 6.2 Zahldatei-Profile (SEPA)

Statt eines fest eingebauten Formats gibt es **Dateiprofile**, die der Verein anlegt:

- Formatvorlage: `pain.008.001.02` (Lastschrift, in Deutschland gängig),
  `pain.008.001.08` (neuere Version), `pain.001.001.03` / `pain.001.001.09`
  (Überweisung/Auszahlung durch den Verein), zusätzlich frei definierbare **CSV-Profile**
  für Banken, die eigene Importformate verlangen (Spalten frei zuordenbar)
- Gläubiger-ID, Vereins-IBAN/BIC, Kontobezeichnung — mehrere Bankkonten möglich, z. B. je
  Sparte ein eigenes Konto
- Vorlauffristen je Sequenztyp (Erst-/Folge-/Einmallastschrift) konfigurierbar
- Verwendungszweck als **Vorlage mit Platzhaltern**
  (z. B. `Beitrag {zeitraum} {mitgliedsnummer} {name}`)
- Sammler- vs. Einzelbuchung, Batch-Booking-Flag

**Begriffsklärung:** Umgangssprachlich heißt das oft „SEPA-Überweisungsdatei“; für den
Beitragseinzug ist es technisch eine **Lastschrift** (pain.008) — der Verein zieht ein.
Eine echte Überweisungsdatei (pain.001) braucht der Verein, wenn er selbst auszahlt
(Aufwandsentschädigungen, Rückerstattungen). Beide Richtungen sind über dasselbe
Profilsystem abgedeckt, der Verein entscheidet per Konfiguration, welche er nutzt.

### 6.3 Mandatsverwaltung

Je Mitglied beliebig viele SEPA-Mandate (Historie bleibt erhalten): Mandatsreferenz
(Nummernkreis konfigurierbar), IBAN/BIC, Unterschriftsdatum, abweichender Kontoinhaber,
Sequenztyp, Status (aktiv/widerrufen/abgelaufen). Verfallsregel (36 Monate ohne Nutzung)
als konfigurierbare Prüfung mit Warnhinweis vor dem Lauf.

### 6.4 Erzeugung eines Zahllaufs

Auswahl der offenen Forderungen über Filter (Zeitraum, Sparte, Beitragsart, Zahlart) →
Vorschau mit Summen und Warnungen (fehlendes Mandat, ungültige IBAN, Frist zu kurz) →
Erzeugung der Datei → **Protokollierung des Laufs**, sodass dieselbe Forderung nicht
versehentlich ein zweites Mal eingezogen wird. Ein Lauf kann als „eingereicht“,
„ausgeführt“ oder „storniert“ markiert werden; einzelne Positionen können als
**Rücklastschrift** gekennzeichnet werden (mit konfigurierbarer Rückläufergebühr, die
automatisch als neue Forderung entsteht).

### 6.5 Manuelle Zahlungserfassung

Für Überweisungen und alle nicht-automatischen Zahlarten:

- Eine berechtigte Person erfasst den Zahlungseingang und ordnet ihn einer oder mehreren
  offenen Forderungen zu (Splitting möglich).
- Erfasst wird insbesondere, **wer überwiesen hat**: Zahlername (kann vom Mitglied
  abweichen — z. B. Elternteil zahlt für Kind oder ein Sponsor übernimmt den Beitrag),
  Betrag, Wertstellungsdatum, Verwendungszweck/Referenz, erfassende Person, Notiz.
- Teilzahlungen sind vorgesehen: die Forderung bleibt mit Restbetrag offen.
- Überzahlungen können als Guthaben stehen bleiben und mit der nächsten Forderung
  verrechnet werden (konfigurierbar).
- Optional: **CSV-Import des Kontoauszugs** mit frei konfigurierbarem Spalten-Mapping und
  Zuordnungsvorschlägen anhand Verwendungszweck/Mitgliedsnummer/Betrag — die manuelle
  Erfassung bleibt aber immer möglich und ist der Normalweg für kleine Vereine.

Jede Forderung zeigt am Ende lückenlos: Zahlart, Status, wer wann wie viel gezahlt hat,
welcher Zahllauf beteiligt war — auditierbar für die Kassenprüfung.

### 6.6 Mahnwesen

Mahnstufen frei definierbar: Bezeichnung, Frist nach Fälligkeit, Mahngebühr, zugehörige
E-Mail-/PDF-Vorlage, ob automatisch oder manuell ausgelöst. Ein Verein, der nicht mahnen
will, legt einfach keine Stufe an.

## 7. Nachrichten und Kommunikation

- Nachricht mit Titel, Text, Gültigkeitszeitraum, Anhängen, Kategorie
- **Kategorien frei anlegbar** (z. B. Vereinsnachrichten, Trainingsausfall, Wettkämpfe) mit
  eigener Sichtbarkeitsvoreinstellung und Kennzeichnung „wichtig“
- **Zielgruppe als Regel**, nicht als feste Liste: alle / bestimmte Einheiten / bestimmte
  Rollen / Mitglieder, die eine Feldbedingung erfüllen (dieselbe Bedingungsmechanik wie bei
  den Beiträgen, z. B. „alle mit offener Forderung“ oder „alle Trainer der Sparte X“)
- Ausspielung: im Self-Service-Bereich und optional per E-Mail (Vorlagen mit Platzhaltern,
  Versand im Hintergrund über Zeitsteuerung, damit große Verteiler den Seitenaufruf nicht
  blockieren)
- Lesebestätigung/Pflichtkenntnisnahme optional pro Nachricht

## 8. Export und Import

### 8.1 Export-Profile

Statt fester Exportlisten definiert der Verein **Export-Profile**:

- Datenbasis (Mitglieder, Mitgliedschaften, Forderungen, Zahlungen, Mandate, Nachrichten)
- Spalten frei wählbar — **inklusive aller selbst angelegten Felder** und berechneter Werte
  (Alter, Summe offener Forderungen, Mitgliedsdauer)
- Filter (dieselbe Bedingungsmechanik), Sortierung, Gruppierung, Summenzeilen
- Format: CSV (Trennzeichen/Zeichensatz konfigurierbar), Excel, PDF, JSON
- Berechtigung: welche Rollen dürfen dieses Profil ausführen — wichtig, damit ein Profil
  mit Bankdaten nicht für Trainer sichtbar ist
- Profil speicherbar, wiederverwendbar, per Zeitsteuerung automatisch versendbar

Zusätzlich immer verfügbar: Ad-hoc-Export der gerade angezeigten, gefilterten Liste.

Fachlich vorbelegte Beispielprofile bei der Installation: Mitgliederliste, Beitragsübersicht,
Zahlungsjournal, Kassenbericht, Verbandsmeldung, DSGVO-Einzelauskunft.

### 8.2 Import

Damit der Bestand überhaupt ins System kommt und variabel bleibt: CSV-/Excel-Import mit
**frei konfigurierbarem Spalten-Mapping** auf die eigenen Felder, Vorschau, Validierung,
Duplikaterkennung (nach Mitgliedsnummer oder Feldkombination), Protokoll und
Rückgängig-Funktion für den letzten Import.

### 8.3 Konfiguration selbst exportieren

Die gesamte Konfiguration (Felder, Rollen, Beitragsregeln, Profile) ist als Datei
exportier- und importierbar. Nutzen: Umzug von Test- auf Produktivsystem, Sicherung vor
größeren Umstellungen, Weitergabe an einen anderen Verein als Startvorlage.

## 9. Datenschutz (DSGVO)

Besonders sensibel: Bankdaten, Geburtsdaten Minderjähriger, ggf. Gesundheitsangaben
(Atteste). Da Felder frei anlegbar sind, muss der Datenschutz **am Feld** hängen:

- Kennzeichen „sensibel“ und „in DSGVO-Auskunft enthalten“ je Feld
- Aufbewahrungsfrist je Feld bzw. je Datenkategorie; Finanzunterlagen i. d. R. 10 Jahre
  (§ 147 AO), sonstige Stammdaten kürzer
- **Lösch-/Anonymisierungslauf**: findet Datensätze, deren Fristen abgelaufen sind, und
  anonymisiert feldweise nach Konfiguration — statt pauschalem Löschen, damit die
  Finanzhistorie in Summen erhalten bleibt
- Auskunftsersuchen als Export-Profil (alle Daten zu einem Mitglied)
- Einwilligungen (Foto/Video, Newsletter) als eigene, konfigurierbare Datenkategorie mit
  Zeitstempel und Widerrufsmöglichkeit im Self-Service
- **Änderungsprotokoll** über alle Änderungen an Stamm-, Finanz- und Konfigurationsdaten:
  wer, wann, alter Wert, neuer Wert. Auch Zugriffe auf als sensibel markierte Felder werden
  protokolliert.

## 10. Technische Architektur

### 10.1 Plugin-Struktur (Vorschlag)

```
schwimmverein-mitgliederverwaltung/
├── schwimmverein-mitgliederverwaltung.php   # Bootstrap, Plugin-Header, Aktivierung
├── includes/
│   ├── core/            # Registry für Felder, Rechte, Feldtypen, Aktionen
│   ├── models/          # Datenzugriff je Entität
│   ├── engine/          # Regel-Engine (Bedingungen), Beitragsrechner, Zustandsmaschine
│   ├── payments/        # Zahlarten, Zahldatei-Profile, SEPA-Generator, Mandate
│   ├── export/          # Export-Motor, Formatschreiber (CSV/XLSX/PDF/XML), Import
│   ├── rest/            # REST-Endpunkte für Self-Service und Admin-UI
│   └── migrations/      # versionierte Schema-Migrationen
├── admin/               # Adminoberfläche, generische Listen- und Formular-Renderer
├── public/              # Block/Shortcode für das Mitgliederportal
├── assets/
├── vendor/              # Composer-Abhängigkeiten (SEPA-XML, PhpSpreadsheet, PDF)
└── languages/           # i18n, de_DE als Hauptsprache
```

Zentral sind die generischen Renderer: **ein** Formular-Renderer, der aus der Feldkonfiguration
ein Formular baut, und **ein** Listen-Renderer, der aus Spaltenkonfiguration und Filterregeln
eine Tabelle baut. Nur so bleibt der Wartungsaufwand beherrschbar, wenn Felder frei
anlegbar sind.

### 10.2 Datenhaltung

Eigene Tabellen statt Custom Post Types — Post-Meta ist für relationale, finanzkritische Daten
mit vielen Filtern ungeeignet. Tabellen (Präfix `wp_svm_`), grob:

Konfiguration: `field_defs`, `field_options`, `entity_types`, `unit_types`, `statuses`,
`status_transitions`, `roles`, `role_permissions`, `payment_methods`, `file_profiles`,
`export_profiles`, `message_categories`, `templates`, `number_ranges`, `rules`,
`rule_conditions`.

Bewegungsdaten: `units` (Sparten/Gruppen), `members`, `member_units`, `field_values`,
`family_groups`, `fee_types`, `invoices`, `invoice_lines`, `payments`,
`payment_allocations`, `mandates`, `payment_runs`, `payment_run_items`, `messages`,
`message_targets`, `change_requests`, `audit_log`.

Anlage und Änderung über versionierte Migrationen (nicht nur `dbDelta` im Aktivierungshook),
damit spätere Plugin-Updates das Schema kontrolliert fortschreiben.

### 10.3 Erweiterbarkeit für Entwickler

Zusätzlich zur Konfiguration über die Oberfläche: WordPress-übliche Hooks (`do_action`/
`apply_filters`) an allen relevanten Stellen (vor/nach Beitragsberechnung, vor Dateierzeugung,
bei Statuswechsel, bei Exportspalten) sowie eine Registrierungs-API für eigene Feldtypen,
Bedingungsoperatoren, Exportformate und Aktionen. Damit lässt sich auch das ergänzen, was
über reine Konfiguration nicht abbildbar ist — ohne das Plugin zu forken.

### 10.4 Zeitgesteuerte Aufgaben

WP-Cron (bzw. echter Server-Cron bei größeren Beständen) für: Beitragsläufe,
Mahnstufenprüfung, geplante Nachrichten/E-Mail-Versand, Löschfristenprüfung, geplante
Exporte.

### 10.5 Sicherheit

- Jede Aktion prüft Rechte serverseitig gegen die Rechte-Registry (`current_user_can` mit
  eigenen Capabilities), zusätzlich Scope-Prüfung auf Datensatzebene — auch in REST-Aufrufen
- Feldsichtbarkeit wird serverseitig angewandt, nicht nur im Frontend ausgeblendet
- Nonces auf allen Formularen und REST-/AJAX-Aufrufen, konsequent vorbereitete Statements
- IBAN in Listen nur maskiert; vollständige Anzeige nur mit Recht und mit Protokolleintrag
- Zugriff auf Zahldatei-Erzeugung und Konfigurationsexport eng begrenzt
- Vier-Augen-Option für kritische Vorgänge (Beitragslauf, Zahllauf) konfigurierbar

## 11. Grenzen der Konfigurierbarkeit — bewusste Entscheidungen

Damit die Erwartung realistisch bleibt:

1. **Performance**: Frei definierte Felder sind langsamer als feste Spalten. Bis in den
   niedrigen fünfstelligen Mitgliederbereich unkritisch; darüber hinaus greift die
   Spiegelung häufig gefilterter Felder (3.1).
2. **Komplexität der Ersteinrichtung**: Ein völlig leeres System ist für den Verein
   unbrauchbar. Deshalb wird ein **Einrichtungsassistent** mit fachlich sinnvoller
   Standardkonfiguration (Sparten, Standardfelder, Rollenvorlagen, gängige Beitragsarten)
   ausgeliefert, die anschließend frei änderbar ist.
3. **Regel-Engine statt Programmierung**: Die Bedingungslogik deckt sehr viel ab, aber nicht
   jede denkbare Sonderformel. Für echte Ausnahmen gibt es den Hook-Weg (10.3) und die
   Möglichkeit, eine Forderung manuell anzulegen oder zu korrigieren.
4. **Buchhaltungsgrundsätze bleiben fest**: Erzeugte Forderungen und Zahlläufe sind nicht
   frei löschbar, sondern werden storniert. Das ist keine fehlende Flexibilität, sondern
   Voraussetzung für eine prüfbare Kasse.
5. **Rechtliche Formate sind vorgegeben**: SEPA-XML-Strukturen folgen den Standards; frei ist
   die Auswahl und Parametrierung, nicht die Struktur selbst.

## 12. Offene Punkte (vor Umsetzungsbeginn zu klären)

1. Startkonfiguration: welche Sparten, Felder, Beitragsarten und Rollen sollen im
   Einrichtungsassistenten vorbelegt sein?
2. Größenordnung: Wie viele Mitglieder aktuell und perspektivisch? (entscheidet über
   Spaltenspiegelung und Cron-Strategie)
3. Ein Vereinskonto oder mehrere (je Sparte)?
4. Wird pain.001 (Auszahlungen durch den Verein) wirklich benötigt, oder zunächst nur
   pain.008?
5. Freigabe-Workflow bei Stammdatenänderungen: für welche Felder standardmäßig aktiv?
6. Mahnwesen gewünscht — automatisch oder manuell?
7. Zielsystem: PHP-Version, Hosting, bestehende WordPress-Installation, gibt es Altdaten zum
   Import (aus welchem System, welches Format)?
8. Soll das Plugin mehrere Vereine/Mandanten in einer Installation können, oder eine
   Installation pro Verein?

## 13. Phasenplan

1. **Phase 1 — Fundament:** Konfigurations-Kern (Feldsystem, Rechte-Registry, Rollen,
   Strukturbaum, Statuslebenszyklus), Mitgliederverwaltung mit generischen Formularen und
   Listen, CSV-Import, CSV-Export, Einrichtungsassistent.
2. **Phase 2 — Beiträge & Zahlungen:** Beitragsarten mit Regel-Engine, Beitragslauf mit
   Simulation, Forderungen, Zahlarten, manuelle Zahlungserfassung inkl. Erfassung des
   Zahlenden, Zahlungsjournal.
3. **Phase 3 — SEPA & Portal:** Mandatsverwaltung, Zahldatei-Profile und pain.008-Erzeugung
   mit Laufprotokoll und Rücklastschriften, Mitglieder-Self-Service-Portal, Nachrichtenmodul
   mit Kategorien und Regel-Zielgruppen.
4. **Phase 4 — Ausbau:** Export-Profile mit Excel/PDF, Freigabe-Workflow, Mahnwesen,
   DSGVO-Löschkonzept und Auskunft, Änderungsprotokoll-Auswertung, Konfigurations-Export.
5. **Phase 5 — optional:** pain.001-Auszahlungen, Kontoauszug-Import mit
   Zuordnungsvorschlägen, geplante Exporte/Berichte, Mandantenfähigkeit.

---

Nächster Schritt: Rückmeldung zu Abschnitt 12 („Offene Punkte“) — insbesondere zur
gewünschten Startkonfiguration —, danach kann Phase 1 umgesetzt werden.
