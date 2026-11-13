# Interner Bereich 9.8.1 — der Ladebalken ist zu sehen

Mit 9.8.0 war der Ladebalken auf der echten Seite **nie zu sehen**. Zwei
Gründe, beide behoben:

## Er lag unter der Anwendung

Auf einer Seite mit `[lsv07_intern]` läuft der interne Bereich immer im
Vollbild. Dann ist die Anwendung eine feste Ebene über der ganzen Seite
(`z-index: 99998`) — und der Ladebalken lag mit `z-index: 9999` darunter.
Er lief, aber hinter der Kopfleiste.

Jetzt liegt er darüber (`z-index: 100002`) und nimmt keinen Klick an.

**Warum die Prüfung das nicht fand:** Der Prüfstand baute die Seite aus
der echten Vorlage, aber ohne die Klasse `lsv07i-fullscreen`, die
WordPress an den `<body>` hängt. Ohne sie gibt es die Ebene nicht, und
der Balken lag oben. Der Prüfstand setzt die Klasse jetzt — und die
Prüfung fragt nicht mehr nur, ob der Balken „läuft", sondern ob er an
der Stelle **zuoberst liegt**, wo man ihn sehen soll. Mit dem alten Wert
wird sie rot.

## Er war zu blass und kam zu spät

* Ein **blauer Verlauf**, der an den Rändern ins Durchsichtige lief, auf
  heller Kopfleiste kaum zu erkennen. Jetzt ein **voller Balken** mit
  Leuchten, der von links wächst.
* Er erschien erst **nach 150 ms**. Auf einem schnellen Server war fast
  jede Anfrage vorher fertig. Jetzt startet er **sofort**.
* Damit er trotzdem nicht aufblitzt, steht er **mindestens 350 ms** und
  läuft dann auf 100 % durch, bevor er ausblendet.
* Er läuft **schnell an**: Nach einer halben Sekunde steht er über die
  halbe Breite.

Unverändert: Er bleibt, bis alle laufenden Anfragen fertig sind. Wer
„weniger Bewegung" eingestellt hat, sieht ihn ohne Gleiten.

Die Platzhalter in den Listen erscheinen weiterhin erst nach 150 ms —
ein grauer Block, der für einen Augenblick aufblitzt und sofort durch
die Liste ersetzt wird, wäre unruhiger als gar keiner.

## Geprüft

Neu geprüft wird, was man **sieht**: Der Balken liegt oben zuoberst,
ist mindestens 3 px hoch, voll deckend, hebt sich vom Untergrund mit
mindestens 3:1 ab, steht nach einer halben Sekunde über die halbe
Breite, steht auch bei einer schnellen Anfrage mindestens 350 ms und ist
danach fort.

| Prüfung | Zusicherungen | Gegenprobe |
|---|---|---|
| Oberfläche, jetzt im Vollbild wie auf der echten Seite | 210 | 139 von 139 |

Die Gegenprobe ist **vollständig neu gelaufen**, nicht nur für den
Balken: Der Prüfstand läuft jetzt in einer anderen Umgebung (Vollbild),
und es sollte belegt sein, dass alle übrigen Zusicherungen dort noch
greifen. Elf Brüche betreffen den Balken — darunter „liegt unter der
Anwendung", „wartet wieder 150 ms", „ohne Mindestzeit", „blass", „läuft
zu langsam an". Alle rot.

**Nicht bewiesen:** Auf einem echten WordPress mit Ihrem Theme ist es
nicht gelaufen. Legt ein Theme oder ein anderes Plugin eine eigene Leiste
mit noch höherem `z-index` oben über die Seite, kann der Balken auch
jetzt darunter liegen.
