# LSV07 Interner Bereich — 8.3.0

## Neu: Trainer → Sportler (anlegen und verschieben)

Neuer Tab „Sportler" im Trainer-Bereich, sichtbar für jeden Trainer (keine
gesonderte Rechtevergabe nötig — analog zu „Abrechnung"/„Stammdaten").

- **Sportler anlegen:** über genau dasselbe Formular, das auch der Admin in
  der Sportlerverwaltung nutzt (Name, Geburtsdatum, Attest, DSV-ID,
  Kontaktpersonen, …) — dieselbe Modal-Komponente, kein Duplikat.
  Unterschied: die Mannschaftsauswahl im Formular zeigt für einen Trainer
  **nur die eigenen Mannschaften** an. Das ist serverseitig erzwungen, nicht
  nur in der Oberfläche ausgeblendet — ein manipulierter Request mit einer
  fremden Mannschafts-ID wird ebenfalls abgelehnt.
- **Sportler verschieben:** ein Sportler aus einer der eigenen Mannschaften
  kann in jede beliebige andere Mannschaft verschoben werden (das Ziel ist
  bewusst nicht eingeschränkt — Übergabe an eine andere Trainerin/einen
  anderen Trainer soll ohne Umweg möglich sein). Verschoben werden darf aber
  nur, wessen **aktuelle** Mannschaft eine eigene ist; sobald ein Sportler
  einmal wegverschoben wurde, kann ihn derselbe Trainer nicht einfach wieder
  zurückholen, ohne selbst der neuen Mannschaft zugeordnet zu sein.
- Admin/Schwimmwart sind von diesen Einschränkungen ausgenommen (wie überall
  im Rechtesystem das Sicherheitsventil).
- Bearbeiten bestehender Sportlerdaten (Name, Attest, Kontakte …) bleibt
  bewusst dem Admin-Bereich vorbehalten — hier geht es nur um Anlegen und
  Verschieben, wie angefragt.

## Nebenbei gefunden und behoben: Admin-„Verschieben" war kaputt

Beim Bau der obigen Funktion aufgefallen: Der bereits bestehende
„Verschieben"-Schnellknopf in der Admin-Sportlerverwaltung hat nie
funktioniert. Er schickte ein `team_id`-Feld, das die speichernde Funktion
serverseitig nie ausgewertet hat (sie liest nur `gruppe_ids[]`). Ergebnis:
Ein Klick auf „Verschieben" hat den Sportler nicht etwa in die gewählte
Mannschaft verschoben, sondern ihn **aus allen Mannschaften entfernt**
(Mannschaft = keine). Behoben — der Knopf sendet jetzt das richtige Feld und
verschiebt tatsächlich dorthin, wo man ihn hinklickt.

## Tests

- 21 neue Backend-Prüfungen: Zugriffsscoping (Trainer sieht/verschiebt nur
  eigene Mannschaft, Admin uneingeschränkt), Anlegen wird auf eigene
  Mannschaften beschränkt, Bearbeiten bestehender Sportler wird abgelehnt,
  Verschieben nur aus eigener Mannschaft heraus, Ziel darf beliebig sein,
  Bestzeiten- und Mannschaftszuordnung werden korrekt mitgezogen — sowie
  ein gezielter Test, der den behobenen Admin-Bug reproduziert und die
  Korrektur bestätigt.
- 13 neue Oberflächen-Prüfungen (Playwright): Liste zeigt nur eigene
  Sportler, Anlegen-Formular zeigt nur eigene Mannschaften zur Auswahl,
  Speichern ruft den Trainer- statt den Admin-Endpunkt auf, Verschieben-
  Dialog bietet alle Mannschaften als Ziel an. Dabei einen echten
  JavaScript-Fehler im ersten Entwurf des Verschieben-Dialogs gefunden und
  behoben (Namensanzeige nach erfolgreichem Verschieben griff auf eine
  Variable aus dem falschen Gültigkeitsbereich zu).
- Alle bisherigen Prüfungen erneut ausgeführt und weiterhin grün, voll-
  ständiger Plugin-Regressionstest (1914 Klicks) und statische Prüfung
  ohne Befund.
