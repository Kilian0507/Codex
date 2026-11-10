# Interner Bereich 9.7.0 — kein eigenes Anmeldeformular mehr

Wer nicht angemeldet war, bekam auf der internen Seite ein **eigenes
Anmeldeformular**: eigene weiße Karte, eigene Beschriftungen, eigene
Fehlermeldung. Das ist weg.

Jetzt geht es direkt zur Anmeldung von WordPress.

## Was passiert

1. **Weiterleitung über die Kopfzeilen.** Wer nicht angemeldet ist und
   eine Seite mit `[lsv07_intern]` aufruft, landet auf
   `/wp-login.php` — und nach der Anmeldung wieder genau dort, wo er
   war, samt Abfrage in der Adresse (`?bereich=wk&id=12` geht nicht
   verloren).
2. **Falls das nicht greift**, steht auf der Seite eine Karte mit dem
   Knopf **„Zur Anmeldung"**. Daneben: *„Passiert nichts? Dann bitte auf
   „Zur Anmeldung" klicken."*
3. **Dazwischen** versucht es ein kleines Skript noch einmal selbst.

Drei Wege, absteigend nach Verlässlichkeit. Der Verweis ist bewusst kein
Kleingedrucktes, sondern der Knopf in der Mitte: Wer hier landet, ist
einen Klick vom Ziel entfernt und muss nicht rätseln.

**Warum es überhaupt einen zweiten und dritten Weg braucht:** Eine
Weiterleitung über Kopfzeilen geht nur, solange noch nichts ausgegeben
ist. Schreibt ein anderes Plugin vorher etwas, oder liefert ein
Seiten-Zwischenspeicher die Seite ohne PHP aus, dann kommt die
Weiterleitung nicht zustande. Genau dann greift die Karte.

## Warum das eigene Formular weg ist

Es war eine **zweite Stelle, an der Benutzername und Passwort eingegeben
werden** — mit eigenem Aussehen, eigener Fehlerbehandlung und der
Pflicht, bei jeder Änderung an WordPress mitzuhalten.

Die Anmeldung von WordPress kann all das besser: Sperren nach
Fehlversuchen, Zwei-Faktor-Plugins, „Passwort vergessen", Sprache,
Barrierefreiheit, und jede Sicherheitskorrektur kommt automatisch mit.

Mit dem Formular ist auch der Griff in **fehlgeschlagene Anmeldungen**
weg (`wp_login_failed`). Der hätte ab jetzt geschadet: Er schickte bei
falschem Passwort zurück auf die interne Seite — und dort hätte ohne das
eigene Formular **keine Fehlermeldung** mehr gestanden. Ein stilles
Zurückfallen ohne Hinweis ist schlimmer als die Fehlerseite von
WordPress.

## Wann NICHT weitergeleitet wird

Eine Weiterleitung an der falschen Stelle kostet Daten oder dreht sich im
Kreis. Darum bleibt sie aus:

* **wenn man angemeldet ist** — selbstverständlich, und geprüft;
* **auf Seiten ohne den Kurzbefehl** — die gehen das Plugin nichts an;
* **bei einem abgeschickten Formular** (alles außer `GET`), sonst wären
  die eingegebenen Daten weg;
* **bei AJAX-Aufrufen, Feeds und `robots.txt`**;
* **wenn schon Ausgabe geschrieben ist** — dann übernimmt die Karte;
* **wenn die Anmeldeadresse auf diese Seite selbst zeigt.** Das kann
  passieren, wenn ein Sicherheits-Plugin `wp_login_url()` umbiegt, und
  wäre eine endlose Schleife.

Die Weiterleitung ist **vorübergehend (302)** und wird **nicht
zwischengespeichert** (`nocache_headers()`). Eine gespeicherte
Weiterleitung würde sonst auch angemeldete Besucher hinausschicken.

## Eine Stelle für die Adresse

`LSV07I_Shortcode::anmelde_url()` — die Weiterleitung, das Skript und der
Verweis nehmen dieselbe.

Gebaut wird sie mit **`wp_login_url()`** und nicht mit einem festen
`/wp-login.php`: Verschiebt ein Sicherheits-Plugin die Anmeldeseite — das
kommt häufig vor —, zeigt der Weg weiterhin dorthin, wo die Anmeldung
wirklich liegt. Ohne solches Plugin ist das Ergebnis genau
`/wp-login.php`, wie gewünscht.

## Sicherheit

* Die Adresse steht im Verweis **escaped** (`esc_url`). Das ist kein
  Selbstzweck: Ein Plugin kann über den Filter `login_url` alles
  zurückgeben, und ohne Escaping bräche eine solche Adresse das
  Attribut auf. Genau das ist geprüft, mit einer absichtlich bösartigen
  Anmeldeadresse.
* Das Skript nutzt `location.replace()` und nicht `href`: Die
  Anmeldeseite soll nicht als eigener Schritt im Verlauf liegen, sonst
  landet man mit „Zurück" wieder auf der Karte.
* Für Abgemeldete wird **kein Stylesheet** des internen Bereichs
  geladen. Die Karte trägt ihre wenigen Angaben selbst.

## Geprüft

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Anmeldung des internen Bereichs (echte Klasse) | 50 | 27 von 27 |

Eine Weiche hat einen **eigenen Prozess**: „Steht schon Ausgabe, wird
nicht mehr weitergeleitet." `headers_sent()` lässt sich nicht
nachstellen — in der Kommandozeile wird es erst wahr, wenn wirklich etwas
ausgegeben wurde. Eine Zusicherung darüber im selben Prozess wäre eine
Behauptung.

Gegenprobe heisst: Jede Zusicherung wird einmal absichtlich gebrochen —
in der **echten** Datei im Plugin. Bleibt die Prüfung dabei grün, prüft
sie nichts und ist wertlos. Darunter: die Weiterleitung ganz weglassen,
sie zu spät anmelden, Angemeldete mit weiterleiten, das abgeschickte
Formular mitnehmen, die Schleifensicherung entfernen, 301 statt 302, das
Zwischenspeichern erlauben, die Adresse fest verdrahten, das Ziel nach
der Anmeldung weglassen, den Verweis zu einem `<span>` machen, das
Escaping entfernen und das eigene Formular wieder einbauen.

**Beim ersten Lauf blieben drei Brüche grün**, alle drei wegen Lücken in
der Prüfung, nicht im Plugin: Die Beschriftung des Knopfes wurde im
ganzen Seitentext gesucht und stand auch im Hinweis darunter; das
Escaping wurde mit einem Ziel geprüft, das `wp_login_url()` ohnehin
kodiert (die Gefahr liegt bei der umgebogenen Anmeldeadresse); und das
Stylesheet hängt an `wp_enqueue_scripts`, was die Prüfung nie gerufen
hatte. Alle drei Zusicherungen wurden nachgezogen, kein Bruch gestrichen.
