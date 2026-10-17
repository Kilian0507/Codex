# LSV07 SwimView — 1.1.0

Diese Fassung macht eines: Sie nimmt Datenschutz und Sicherheit so ernst,
wie es bei Daten über Kinder nötig ist. Funktional kommt nichts Neues dazu
außer dem, was dafür gebraucht wird.

## Der Zugangscode — und warum es ihn geben muss

In 1.0.0 reichten DSV-ID und Geburtsdatum. Beides ist **halb öffentlich**:
Die DSV-ID steht in jedem Meldeergebnis und jedem Protokoll, das Geburtsdatum
weiß im Verein fast jeder. Zwei solche Angaben sind keine Anmeldung, sondern
eine Zuordnung. Das stand schon in der Auslieferung von 1.0.0 als Hinweis —
jetzt ist es behoben.

Neu gibt es **je Kind einen Zugangscode**: vier Blöcke zu vier Zeichen aus
einem Alphabet ohne verwechselbare Zeichen (kein O/0, kein I/1/L, kein S/5).
Das sind rund 10²³ Möglichkeiten.

* Erzeugt wird er im Trainerportal, **einmal im Klartext sichtbar** — danach
  nie wieder, auch nicht für die Verwaltung.
* Gespeichert wird nur sein Hash, mit demselben Verfahren, das WordPress für
  Passwörter benutzt.
* Ein neuer Code macht den alten ungültig und meldet die Person überall ab.
* **Voreinstellung: Pflicht.** Ein Plugin, das Daten über Kinder zeigt, wird
  nicht mit offenen Türen ausgeliefert. Die Verwaltung kann auf „nur wo einer
  hinterlegt ist" oder „aus" stellen; beim Abschalten gibt es eine Rückfrage
  und einen roten Hinweis, was das bedeutet.

Beim Abtippen sind Groß- und Kleinschreibung, Bindestriche und Leerzeichen
egal. Verwechselbare Zeichen werden **nicht** stillschweigend „gerade
gezogen" — das lag nahe, machte aber aus einem Vertipper einen anderen
falschen Code. Dass solche Zeichen im Alphabet gar nicht vorkommen, ist die
Vorsorge.

## Anmeldung

* **Nur über HTTPS.** Ohne verschlüsselte Verbindung ist die Anmeldung
  gesperrt, und es wird auch keine Sitzung angelegt. Sonst gingen DSV-ID,
  Geburtsdatum und Code offen durchs Netz, und das Sitzungs-Cookie wäre für
  jeden im selben WLAN mitlesbar. Ausnahme: `localhost` für die Entwicklung.
* **Sperre jetzt zweifach** — je Adresse (8 Versuche, 15 Minuten) und **je
  Konto** (12 Versuche, 10 Minuten). Nur je Adresse zu zählen half wenig:
  Adressen sind billig. Die Sperre je Konto ist kürzer und großzügiger, damit
  ein Scherzbold niemanden einen Tag lang aussperren kann.
* **Alle drei Angaben werden immer geprüft**, auch wenn schon die erste nicht
  stimmt, und auch für eine unbekannte DSV-ID wird gerechnet. Sonst verriete
  die Antwortzeit, welche Nummer zum Verein gehört.
* Die Fehlermeldung hängt **allein an der vereinsweiten Einstellung**, nie
  daran, ob für dieses Konto ein Code besteht. Das war im Entwurf anders und
  hätte in der Stufe „optional" verraten, ob es eine DSV-ID überhaupt gibt —
  gefunden von einer Prüfung, die genau das vergleicht.

## Sitzungen

* **2 Stunden Ruhe statt 8, höchstens 7 Tage statt 30.** Das Gerät ist oft
  nicht das eigene: Tablet am Beckenrand, Rechner zu Hause, Telefon der
  Eltern.
* Das Cookie **endet mit dem Browser** — kein Verfallsdatum mehr auf der
  Platte. Wie lange eine Sitzung gilt, entscheidet allein der Server.
* **`SameSite=Strict`** statt Lax und über HTTPS das Präfix **`__Host-`**.
  Letzteres nimmt der Browser nur an, wenn das Cookie `Secure` ist, auf `/`
  liegt und keine Domain trägt — damit kann keine Nebendomain eine fremde
  Sitzung unterschieben.
* **Das Sitzungsgeheimnis wechselt** alle 15 Minuten. Das vorige gilt noch,
  bis das neue benutzt wurde — ohne diese Frist verlöre ein Browser, der
  mehrere Abfragen gleichzeitig laufen hat, seine Anmeldung mitten im
  Betrieb. Ein zweimal überholtes Geheimnis beendet die Sitzung und steht in
  der Prüfspur.
* Neu im Portal: **„Überall abmelden"** je Kind — für den Fall, dass ein
  Gerät abhandenkommt.
* Zusätzlich zur doppelten Token-Einreichung wird `Sec-Fetch-Site` geprüft.

## Wie die Seite ausgeliefert wird

* **`no-store`** auf Seite, Antworten und Downloads: Nichts landet in einem
  Zwischenspeicher. Sonst stünde die Seite nach dem Abmelden noch im
  Zurück-Knopf des geteilten Tablets.
* `X-Frame-Options: DENY` und `frame-ancestors 'none'`, `Referrer-Policy:
  no-referrer`, `nosniff`, `noindex, nofollow, noarchive`,
  `Permissions-Policy` ohne Kamera, Mikrofon und Standort.
* Eine **Inhaltsrichtlinie (CSP)** in zwei Stufen: Die milde sagt nur, was
  diese Seite sicher nicht braucht, und kann kein Theme zerstören — sie ist
  die Voreinstellung. Die strenge sperrt zusätzlich fremde Skriptquellen aus
  und ist ein Schalter, weil sie mit manchen Themes kollidiert.
* Heruntergeladene Dateien bekommen zusätzlich `sandbox` und eine CSP, die
  nichts erlaubt — falls doch einmal etwas im Browser geöffnet wird.
* **HSTS bewusst nur auf Wunsch.** Es wäre naheliegend gewesen, es einfach zu
  setzen. Es gilt aber für die ganze Domain und ist nicht zurückzunehmen:
  Läuft ein Zertifikat ab — das häufigste Missgeschick überhaupt —, kommt ein
  Jahr lang niemand mehr auf die Vereinsseite, auch nicht mit Wegklicken.
  Diese Entscheidung gehört dem, der den Server betreibt.

## Datensparsamkeit

* **Prüfspur**: Anmeldungen, Fehlversuche, Sperren, Code-Änderungen. Die
  Adresse steht **nur als Hash** darin, Eingaben gar nicht. Im Portal unter
  „Was zuletzt passiert ist" — damit ein Missbrauch auffällt und damit ein
  Trainer sieht, ob der Code angekommen ist. Der Adress-Hash geht dabei nicht
  an den Browser.
* **Fristen, und sie laufen von selbst ab.** Einmal täglich werden gelöscht:
  Prüfspur und Lesemarken nach 90 Tagen, Sperrzähler nach einem Tag,
  abgelaufene Sitzungen, und Nachrichten nach der eingestellten Aufbewahrung
  (Voreinstellung ein Jahr, einstellbar bis „unbegrenzt" — was nicht
  empfohlen und als solches gekennzeichnet ist).
* **Löschweg je Kind** („Daten löschen"): Code, Sitzungen, Lesemarken,
  Prüfspur und die Nachrichten, die nur an dieses Kind gingen, samt Dateien.
  Mannschaftsnachrichten bleiben, die gehören allen. Was im internen Bereich
  steht, bleibt unangetastet — das gehört nicht SwimView.
* Nach der Anmeldung werden DSV-ID, Geburtsdatum und Code **aus dem Formular
  entfernt**, damit sie auf einem geteilten Gerät nicht stehen bleiben.

## Nebenbei behoben

* Die Auswahlfelder der neuen Sicherheitskarte liefen auf dem Telefon aus der
  Karte heraus und zogen die ganze Seite in die Breite — ein `<select>` ist
  so breit wie sein längster Eintrag. Felder in einer Karte füllen jetzt die
  Breite.

## Geprüft

* **351 Prüfungen** gegen echtes SQL (vorher 256). Neu darunter: die
  Voreinstellung „Pflicht" (geprüft, bevor ein Test sie verstellt), Form und
  Hash des Codes, dass er im Klartext nicht in der Datenbank steht, dass auch
  bei unbekanntem Konto gerechnet wird, dass die Fehlermeldung in allen drei
  Stufen wortgleich bleibt, die Sperre je Konto von wechselnden Adressen aus,
  die HTTPS-Pflicht, der Geheimnis-Wechsel samt Übergangsfrist und dem
  Beenden bei einem überholten Geheimnis, die Prüfspur ohne Klartext-Adresse,
  die Fristen und der Löschweg.
* **166 im Browser** (vorher 137): das Codefeld, der einmalige Code-Dialog
  (der sich durch einen Klick daneben nicht schließen lässt — sonst wäre der
  Code weg, bevor er notiert ist), die Rückfrage vor einem neuen Code, die
  geleerten Felder nach der Anmeldung und die Prüfspur.
* **178 auf 320, 390 und 768 px** (vorher 157), darunter der Code-Dialog im
  engsten Fall.
* Zu jedem dieser Punkte wurde gegengeprüft, dass die Prüfung den Fehler auch
  findet: Fünf Schutzmaßnahmen wurden absichtlich ausgebaut, und alle fünf
  wurden gemeldet.
