# Änderungen in Version 7.52.0

## Trainer-Übersicht nur noch für Administratoren

Die Trainer-Tabs in den Bereichen **Schwimmen**, **Triathlon** und
**Fitness** zeigen die Kontaktdaten aller Trainer (Name, Telefon,
dienstliche und private E-Mail-Adresse). Bisher sahen sie alle internen
Nutzer des jeweiligen Bereichs — beim Schwimmen genügte das Leserecht
für die Mannschaftsliste, bei Triathlon und Fitness zusätzlich die
Wart-Rolle.

Ab sofort gilt: **sichtbar nur für Administratoren.** Alle anderen
brauchen ein eigenes Leserecht, das in der Rechteverwaltung einzeln
vergeben werden kann.

### Neue Rechte

| Recht | Wirkung |
|---|---|
| `schwimmen.trainer.read` | Trainer-Tab im Schwimmbereich |
| `triathlon.trainer.read` | Trainer-Tab im Triathlonbereich |
| `fitness.trainer.read` | Trainer-Tab im Fitnessbereich |

Zu finden in der Rechteverwaltung unter dem jeweiligen Bereich in der
Gruppe **Trainerübersicht**.

Die Rechte sind bewusst in **keiner** der mitgelieferten Vorlagen
enthalten — auch nicht in Schwimmwart, Triathlonwart oder Fitnesswart.
Wer sie braucht, bekommt sie einzeln zugewiesen. Sollen die Warte den
Tab weiterhin sehen, genügt ein Haken pro Person.

### Was sich technisch ändert

- Die Sichtbarkeit läuft über `LSV07I_Access::get_access_map()['tabs']`
  und ist damit nicht mehr an die Bereichsrolle gekoppelt.
- Fehlt das Recht, wird das Panel **gar nicht erst ausgeliefert** —
  es steht dann nicht im HTML.
- Die AJAX-Endpunkte `lsv07i_schwimmen_get_trainer`,
  `lsv07i_tri_get_trainer` und `lsv07i_fit_get_trainer` prüfen dasselbe
  Recht und antworten sonst mit 403. Die Daten sind also auch dann
  geschützt, wenn jemand die Anfrage von Hand stellt.
- Die Trainer-Verwaltung im **Admin-Bereich** ist unverändert; sie war
  schon immer Administratoren vorbehalten.

## Hinweis zum Update

Nach dem Update sehen Nicht-Administratoren den Trainer-Tab nicht mehr,
auch wenn sie ihn vorher hatten. Das ist beabsichtigt. Wer ihn behalten
soll, bekommt das passende Recht in der Rechteverwaltung.

## Datenbank

Keine Schema-Änderungen.
