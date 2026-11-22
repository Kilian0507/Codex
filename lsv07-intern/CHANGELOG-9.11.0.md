# Interner Bereich 9.11.0 — Trainingsplan: Videos, Timer, Startpfiff, neues PDF

## Je Session: Videos und Werkzeuge

Im Trainingsplan-Editor hat jede Session zwei neue Felder:

* **Videos:** Links, einer pro Zeile (YouTube, Vimeo oder eine
  Videodatei, höchstens 5). Sie hängen an der Session.
* **Im Vollbild anzeigen:** **Countdown** (mit Dauer, z. B. `3:00`, `90s`
  oder `3` für drei Minuten), **Stoppuhr**, **Startsignal**.

Beides wird auch in Session-Vorlagen mitgespeichert.

## Vollbild

In der Übersicht zeigt jede Karte einen Hinweis, z. B. „Countdown 3:00 ·
Startsignal · 2 Videos“. Tippt man die Übung an, erscheinen in der
Einzelansicht:

* **Videos, direkt eingebettet.** YouTube läuft über
  `youtube-nocookie.com`, das erst beim Abspielen Cookies setzt, Vimeo mit
  „Do not track“, eigene Videodateien im Browser-Player. Andere Links
  erscheinen als Link. Die Adresse des Players wird aus der erkannten
  Video-Kennung neu gebaut, nie aus dem eingegebenen Link. Fremder Inhalt
  kommt so nicht in den Rahmen. Die Video-Seite sieht als Herkunft nur die
  Domain, nicht die Adresse der Seite.
* **Countdown:** Start, Pause, Zurück. Am Ende ertönen drei kurze
  Signaltöne, und die Zeit blinkt rot.
* **Stoppuhr:** Start, Stopp, Zurück. Sie zeigt Zehntelsekunden.
* **Startsignal:** ein Knopf. Sofort kommt ein **langer Pfiff** zum
  Bereitmachen, nach einer Pause automatisch der **kurze Pfiff** zum
  Start. Die Pause lässt sich mit − und + einstellen (2–30 s, Standard
  5 s). Sie wird je Gerät gemerkt. Hat die Session auch eine Stoppuhr,
  startet sie genau mit dem kurzen Pfiff. „Abbrechen“ stoppt beide Pfiffe.

Die Pfiffe erzeugt der Browser selbst (Trillerpfeife nachgebaut, keine
Tondatei, nichts wird geladen). Beide Pfiffe werden beim Drücken im
Audio-Takt eingeplant. Der kurze kommt dadurch pünktlich, auch wenn das
Gerät die Anzeige drosselt. Ein laufender Countdown bleibt beim Blättern
erhalten. Beim Schliessen des Vollbilds wird alles angehalten.

## PDF

* Der farbige Kopf ist weg.
* **Oben links steht das Seitenlogo** aus WordPress (Design → Customizer →
  Website-Informationen → Logo). Es ist 14 mm hoch, im richtigen
  Seitenverhältnis und höchstens 5 cm breit. Titel und Untertitel stehen
  in dunkler Schrift daneben. Ist kein Logo gesetzt oder lädt es nicht,
  bleibt die Ecke leer, und das PDF entsteht trotzdem.
* **Videos** stehen je Session als **anklickbarer Link** im PDF. Timer und
  Startsignal gehören ins Vollbild, nicht aufs Papier.

Auch die Ansicht „Trainingsplan ansehen“ zeigt die Video-Links und die
eingestellten Werkzeuge.

## Datenschutz und Sicherheit

* Gespeichert werden nur `http(s)`-Links. `javascript:`, `data:` und
  Ähnliches fällt weg, ebenso Zugangsdaten in der Adresse
  (`https://name:passwort@…`) und Doppelte. Es sind höchstens 5 je Session.
* Der Server ruft die Links **nie** selbst ab und fragt auch kein DNS.
* Werkzeuge: nur Countdown (1 s bis 99:59), Stoppuhr, Startsignal.
  Alles andere wird verworfen, beim Speichern **und** beim Ausliefern.
  Auch nachträglich veränderte Datenbankeinträge kommen bereinigt heraus.
* Videos lädt der Browser erst, wenn die Einzelansicht offen ist
  (`loading="lazy"`), nicht schon in der Übersicht.
* Die neuen Spalten werden in bestehenden Tabellen automatisch ergänzt.

## Geprüft

| Prüfung | Zusicherungen |
|---|---|
| Server gegen echtes WordPress 6.8.3 (Speichern, Laden, Vorlagen, Bereinigung, Altbestand, Rechte, Seitenlogo) | 28 |
| Browser (echte Vorlage, echtes app.js: Editor, Ansicht, Vollbild, Pfiffe mit Zeitmessung, Countdown, Stoppuhr, PDF-Inhalt) | 67 |

**Gegenprobe:** Jede Regel wurde einmal im echten Plugin gebrochen, und
der Lauf muss rot werden: **38 von 38 rot**, nach dem Zurücksetzen
wieder grün. Dabei waren zunächst vier Prüfungen zu schwach und wurden
geschärft:

* Kam das Seitenlogo wirklich im Browser an? Geprüft wurde nur die
  Funktion, nicht die Übergabe.
* Hielt die Untergrenze der Startpause bei jedem Klick? Ein Endwert
  reichte nicht.
* Wurde die Zeitangabe richtig gelesen?
* Wurden die Spalten im Bestand nachgerüstet? Die Meldung war in der
  Ausgabe abgeschnitten.

Bisherige Prüfungen unverändert grün: Oberfläche 210, verschlüsselte
Dateien 124, Zuständigkeit 59.
