# LSV07 Interner Bereich — 8.8.0

## Neu: Trainingsplan-Vorlagen

Im Trainingsplan-Editor lässt sich jede Session jetzt als Vorlage
speichern und später in jedem beliebigen Plan wiederverwenden — spart
das erneute Eintippen häufig genutzter Sessions (z. B. ein
Standard-Einschwimmen oder eine Sprint-Serie).

- Bei jeder Session im Editor gibt es jetzt den Knopf **„Als Vorlage
  speichern"** — übernimmt Anzahl, Strecke, Beschreibung, Ausrüstung und
  Kommentar genau so, wie sie gerade eingetragen sind.
- Über **„Vorlage einfügen"** (oben im Editor, neben „+ Session
  hinzufügen") öffnet sich eine Liste aller eigenen und freigegebenen
  Vorlagen — ein Klick fügt sie als neue Session ins aktuelle
  Formular ein, direkt weiter bearbeitbar.
- Vorlagen lassen sich wie Trainingspläne selbst **freigeben**: eigene
  Vorlagen sind zunächst privat, per Knopf werden sie für alle anderen
  Trainer sichtbar (im selben Vorlagen-Dialog, unter „Freigegeben", mit
  Namen des Erstellers). Löschen ist jederzeit möglich für die eigenen
  Vorlagen.
- Absätze in Beschreibung und Kommentar bleiben beim Speichern und
  Wiedereinfügen erhalten.

## Tests

- 17 neue Backend-Prüfungen (gegen eine echte SQLite-Datenbank mit den
  echten SQL-Texten): Rechte-Gate, leere Sessions werden abgelehnt,
  Sichtbarkeit eigene/freigegeben in beide Richtungen, Eigentümer-Schutz
  bei Freigeben/Löschen (inkl. Admin-Ausnahme), Absätze bleiben erhalten.
- 16 neue Oberflächen-Prüfungen: Als-Vorlage-speichern aus einer
  bestehenden Session, Vorlagen-Liste (eigene + freigegeben), Einfügen
  überträgt alle Felder inkl. Absätze, Freigeben-Knopf, Löschen.
- Bestehende 209 Backend- und 108 Oberflächen-Prüfungen aus früheren
  Versionen erneut ausgeführt und weiterhin grün; vollständiger
  Plugin-Regressionstest (1960 Klicks) und statische Prüfung ohne Befund.
