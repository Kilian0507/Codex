# LSV07 Abrechnung

Quartalsabrechnung für Trainerinnen und Trainer. Eigenständiges
WordPress-Plugin, das **neben** dem internen Bereich läuft und dessen Daten
liest.

## Einrichten

1. Ordner `lsv07-abrechnung` nach `wp-content/plugins/` legen und das Plugin
   in WordPress aktivieren. Die Tabellen entstehen dabei von selbst.
2. Eine WordPress-Seite anlegen und dort nur den Shortcode einsetzen:

   ```
   [lsv07_abrechnung]
   ```

   Die Seite zeigt dann weder Theme-Kopf noch -Fuß und füllt das ganze
   Fenster — wie eine eigene Anwendung.
3. Als WordPress-Administrator die Seite öffnen (ein WordPress-Admin gilt
   hier automatisch als Administrator) und unter **Verwaltung → Konten**
   die Personen aufnehmen.

## Rollen

Die Konten sind WordPress-Konten, **die Rollen aber nicht**: Sie werden in
diesem Plugin vergeben und haben mit WordPress-Rollen nichts zu tun. Eine
Person kann mehrere haben.

| Rolle | darf |
|---|---|
| **Trainer** | eigene Abrechnung sehen und bearbeiten, Zahlungsdaten hinterlegen, einreichen, eigene Statistik |
| **Wart** | jede Abrechnung im Detail sehen — auch nicht eingereichte —, genehmigen, zurückgeben, Statistik aller Trainer |
| **Kasse** | **nur genehmigte und bezahlte** Abrechnungen sehen, als PDF ausgeben, als bezahlt markieren, Statistik |
| **Administrator** | alles, dazu Konten, Rollen, Sätze, Pauschalen, Saisons und Trainingszeiten |

Ein WordPress-Administrator ist immer auch hier Administrator — sonst käme
nach der Installation niemand an die erste Rolle.

## Der Weg einer Abrechnung

```
Entwurf ──einreichen──> Eingereicht ──genehmigen──> Genehmigt ──bezahlt──> Bezahlt
   ^                         │                          │
   └────zurückgeben──────────┘                          │
   └──────── nur Administration: wieder öffnen ─────────┘
```

- Solange eine Abrechnung **offen** ist (Entwurf oder zurückgegeben),
  folgen ihre Beträge den aktuellen Sätzen. Ändert die Administration den
  Stundensatz, rechnet sie sich neu.
- **Ab dem Einreichen steht alles still.** Geprüft und genehmigt wird genau
  das, was eingereicht wurde — eine spätere Satzänderung fasst es nicht an.
- Solange niemand entschieden hat, kann die Trainerin oder der Trainer die
  Abrechnung selbst zurückholen.
- Ohne Kontoinhaber und IBAN lässt sich nicht einreichen.

## Die fünf Bestandteile

| | Erfasst wird | Gerechnet wird |
|---|---|---|
| **Training** | aus dem internen Bereich übernommen oder von Hand | je nach Abrechnungsart des Kontos (siehe unten) |
| **Wettkampf** | Wettkampf und Zahl der Abschnitte | Abschnitte × Betrag je Abschnitt (Vorgabe 30 €) |
| **Fahrtkosten** | einfache Strecke in km, Zahl der Tage | erst über der Mindeststrecke (Vorgabe mehr als 20 km), dann km × 2 (hin und zurück) × Tage × Satz (Vorgabe 0,50 €) |
| **Vorbereitung** | Stunden und Grund | Stunden × Stundensatz |
| **Sonstiges** | Betrag und Grund | der Betrag |

Bei jedem Training lässt sich eine **Wartezeit** zuschalten (Vorgabe 15
Minuten). Sie wird mit dem Stundensatz vergütet und kommt in allen drei
Abrechnungsarten obendrauf.

## Die drei Wege, ein Training abzurechnen

Die Abrechnungsart wird **je Konto einzeln** festgelegt
(Verwaltung → Konten):

- **Trainingszeiten** — Die Stunden kommen aus der hinterlegten
  Trainingszeit der Saison, in der das Training lag, mal Stundensatz.
- **Pauschalbeträge** — Ein fester Betrag je Training, abhängig von der
  Mannschaft (Verwaltung → Pauschalen). Die Stunden spielen keine Rolle.
- **Manuelle Stundeneingabe** — Die Person trägt die Stunden selbst ein,
  mal Stundensatz. Für Trainings, die länger gingen als geplant.

## Saisons und Trainingszeiten

Saisons und Trainingszeiten **teilen sich beide Plugins** — es gibt sie nur
einmal. Was hier gepflegt wird, steht auch im internen Bereich und
umgekehrt. Das ist Absicht: Zwei Wahrheiten darüber, wann trainiert wird,
würden früher oder später zu Abrechnungen führen, die nicht zum
Trainingsplan passen.

**Das Enddatum einer Saison schützt die Historie.** Werden Trainingszeiten
geändert, gehören sie in eine **neue** Saison; sonst ändern sich alte
Abrechnungen rückwirkend. Der richtige Ablauf:

1. Der laufenden Saison ein Enddatum geben.
2. Eine neue Saison ab dem Folgetag anlegen.
3. Die Trainingszeiten dort neu hinterlegen.

Überschneidende Zeiträume werden abgelehnt, denn sonst wäre nicht
entscheidbar, welche Zeit für ein Training gilt.

## Was aus dem internen Bereich gelesen wird

Ausschließlich lesend, niemals verändernd (außer Saisons und
Trainingszeiten, die bewusst gemeinsam gepflegt werden):

| Tabelle | wofür |
|---|---|
| `lsv07i_trainer` | welches WordPress-Konto zu welchem Trainer-Profil gehört |
| `lsv07i_anwesenheit`, `…_eintraege` | bei welchen Trainings jemand anwesend war |
| `lsv07i_springer` | Springer-Schichten |
| `lsv07i_training_slots` | Trainingszeiten → Stunden |
| `lsv07i_saisons` | welche Zeit für welchen Zeitraum gilt |
| `lsv07_gruppen` | Mannschaften |
| `lsv07i_wettkampf`, `…_tage` | Wettkämpfe und ihre Tage |
| `lsv07i_wettkampf_anwesenheit`, `…_anw_eintraege` | Vorschlag für die Abschnitte |

Fehlt der interne Bereich oder eine Tabelle, bleibt die Abrechnung
benutzbar: Es lässt sich dann nichts übernehmen, aber alles von Hand
erfassen. Die Oberfläche sagt, was fehlt.

**Ohne Trainer-Profil im internen Bereich** lassen sich keine Trainings
übernehmen. Unter Verwaltung → Konten steht bei jedem Konto, ob eine
Verknüpfung besteht.

## PDF

Die Kasse erzeugt den Beleg über **PDF** → ein eigenes Fenster mit dem
fertigen Beleg öffnet sich und der Druckdialog erscheint; dort „Als PDF
sichern" wählen. So kommt keine zusätzliche Programmbibliothek ins Spiel
und der Beleg sieht überall gleich aus.

## Eigene Tabellen

`lsv07a_person`, `lsv07a_rolle`, `lsv07a_abrechnung`, `lsv07a_posten`,
`lsv07a_pauschale`, `lsv07a_config`, `lsv07a_log`

Beim Deaktivieren bleiben sie stehen — es gehen keine Abrechnungen
verloren.
