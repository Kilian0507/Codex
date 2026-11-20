# Interner Bereich 9.9.0 — Sicherheitsprüfung

Der interne Bereich wurde auf alle bekannten Klassen von Sicherheitslücken
geprüft: Zugriff auf fremde Daten (IDOR), fehlende Rechteprüfung, XSS,
CSRF, Datei-Uploads, Formel-Injektion in CSV, Clickjacking, Datenlecks im
Browser, Raten von Schlüsseln und Passwörtern, Datenschutz-Auskunft und
-Löschung. Jede gefundene Lücke ist geschlossen.

**Ehrlich vorweg:** 100 % Sicherheit für immer gibt es nicht — auch nicht
mit dieser Version. Neue Angriffswege, neue WordPress-Versionen, ein
unsicheres anderes Plugin oder ein gestohlenes Passwort bleiben möglich.
Was diese Version leistet: Jede bekannte Lücke ist zu, der Schutz ist
mehrschichtig, und jede Prüfung ist mit einem Test gesichert, der rot
wird, sobald jemand sie später versehentlich entfernt.

## Die wichtigsten Lücken (kritisch)

* **Jeder mit irgendeinem Schwimm-Recht sah alle Kinder.** Schon ein
  Mitglied mit Mitgliedsrechten galt als „intern" und bekam über rund
  zwanzig Endpunkte Schwimmerlisten mit Geburtsdaten, Elternkontakten und
  Attesten aller Mannschaften. Jetzt gibt es **eine** Stelle, die
  entscheidet, wer welche Mannschaft sieht
  (`LSV07I_Access::erlaubte_mannschaften`): Administration und
  Schwimmwart alles, Trainer nur die eigenen Mannschaften, Springer nur
  ihren einen Termin, Mitglieder nichts. Alle Endpunkte fragen dort.
* **Atteste ließen sich über die Schwimmer-Dateien laden** — für jeden
  Schwimmer. Jetzt nur noch eigene Schwimmer, und Atteste gar nicht mehr
  über diesen Weg (auch nicht für Admins; dafür gibt es die
  Attestverwaltung).
* **Triathlon/Fitness gaben alle Sportler an jedes Spartenmitglied.**
  Jetzt nur die eigenen Gruppen. Nebenbei: Tri/Fit-Stunden landeten bisher
  bei einem falschen Trainer (ID-Verwechslung) — behoben.
* **Abrechnungen ließen sich nach dem Einreichen und sogar nach der
  Genehmigung noch ändern**, Zeilen fremder Abrechnungen per Nummer
  bearbeiten, und Warte konnten die eigene Abrechnung genehmigen.
* **Rechteverwalter konnten sich selbst alle Rechte geben.**
* **HTML in Tickets** wurde im Browser ausgeführt.

## Zugriff und Rechte

* **Gesperrte Nutzer** sind sofort draußen: Die Sperrliste wirkt an einer
  zentralen Stelle, und beim Speichern werden ihre Sitzungen beendet.
* Ehemalige Administratoren behalten nicht mehr automatisch volle Rechte.
* Niemand ändert die eigenen Rechte. Volle Rechte und Rechteverwaltung
  vergibt nur ein echter WordPress-Administrator.
* Feine Rechte (Anwesenheit erfassen, Ausfall markieren, Springer
  eintragen, Wettkampf-Anwesenheit, Genehmigen, Zurückgeben) werden jetzt
  wirklich geprüft.
* Fremde und nicht vorhandene Datensätze geben dieselbe Antwort — durch
  Durchzählen der Nummern erfährt man nicht, was es gibt.

## Anwesenheit und Wettkampf

* Sessions, Einträge, Absagen, Statistik und Saisonbericht nur für die
  eigene Mannschaft bzw. den eigenen Springertermin.
* Als „anwesend" lassen sich nur Trainer und Schwimmer eintragen, die zu
  diesem Training gehören — vorher legte das für jede beliebige
  Trainer-Nummer bezahlte Stunden an.
* Die Strichlisten enthalten nur noch Namen — keine Geburtsdaten,
  E-Mails, Telefonnummern, Atteste oder Notizen.
* Wettkämpfe: fremde lassen sich nicht mehr übernehmen; höchstens
  15 Tage, höchstens 10 Abschnitte je Tag, Abschnitte nicht über den Plan.
* Anwesenheit schreibt nur noch in **offene** Abrechnungen.
* Datum wird überall auf einen echten Kalendertag geprüft.

## Abrechnung (intern)

* Ändern nur im Entwurf oder nach Rückgabe. Beim Einreichen und
  Genehmigen werden Stundensatz und Betrag **festgeschrieben**.
* Feste Statusfolge: eingereicht → genehmigt → bezahlt; niemand
  genehmigt oder bezahlt die eigene Abrechnung (Vier-Augen-Prinzip).
* Den Stundensatz legt die Verwaltung fest, nicht der Trainer selbst. Das
  Feld in „Stammdaten" ist für Trainer nur noch Anzeige.
* Springer: nur mit Recht, für einen passenden Wochentag, nicht für
  vergangene Tage (außer Verwaltung).
* IBAN in der CSV nur für Kasse/Administration, sonst maskiert.
* Genehmigen, Zurückgeben, Bezahlt, Löschen und Stundensatz-Änderungen
  stehen im Protokoll.

## Nachrichten, Tickets, Dateien

* Direkt-Chat nur zwischen genau zwei Personen mit Konto. Anhänge nur an
  eigene Nachrichten und nur für Teilnehmer zum Laden — der öffentliche
  Download ohne Anmeldung ist entfernt.
* Nutzersuche ohne E-Mail-Adressen; Online-Status nur für
  Gesprächspartner; Teilnehmernamen setzt der Server.
* Dateityp wird am Inhalt erkannt (eine als Bild getarnte HTML-Datei wird
  abgelehnt); Downloads kommen immer als Datei, nie im Browser-Tab.
* Antworten per Mail und über den Antwort-Link: nur noch, solange die
  Person im Gespräch ist, vom richtigen Absender, höchstens 10 am Tag.
* Benachrichtigungen prüfen den Sicherheitsschlüssel (Nonce).

## Browser

* Die Seite wird nicht mehr zwischengespeichert und lässt sich nicht in
  fremde Seiten einbetten (Schutz vor Clickjacking).
* Texte werden überall vollständig maskiert, auch Anführungszeichen.
* Formular-Entwürfe speichern **keine** Bankdaten, Passwörter oder
  eingefügte Listen mehr, nur noch bis zum Schließen des Tabs und getrennt
  je Nutzer.
* pdf.js: bekannte Lücke CVE-2024-4367 abgeschaltet.
* CSV-Exporte entschärfen Formeln (`=HYPERLINK(…)` wird zu Text).

## Konto und Reflexion

* E-Mail-Adresse ändern nur mit aktuellem Passwort (neues Feld in den
  Einstellungen); höchstens 5 Fehlversuche je Viertelstunde.
* Reflexionsbogen: Die Fehlermeldung verrät nicht mehr, ob ein Schlüssel
  existiert; nach 5 Fehlversuchen ist der Bogen eine Stunde gesperrt.
* Das Protokoll nimmt die echte Adresse, nicht die vom Absender
  behauptete (`X-Forwarded-For`).

## Datenschutz (Auskunft und Löschung)

* Die Auskunft enthält keine Daten anderer Personen mehr.
* Die Löschung erfasst alle Einträge einer Person, auch Dateien auf der
  Platte. Das Protokoll wird anonymisiert statt gelöscht. Abrechnungen
  bleiben wegen der steuerlichen Aufbewahrungspflicht (10 Jahre) und
  werden als „aufbewahrt" gemeldet.

## Was Sie merken

* Trainer sehen nur noch ihre eigenen Mannschaften (vorher oft alle).
* Wer nur Mitgliedsrechte hat, kommt nicht mehr in den internen Bereich.
* Den eigenen Stundensatz kann ein Trainer nicht mehr selbst eintragen.
* Eine eingereichte Abrechnung lässt sich nicht mehr ändern — dafür wird
  sie zurückgegeben.
* Direkt-Chats mit externen Adressen gehen nicht mehr (dafür Gruppen).

## Geprüft

Alle Prüfungen laufen gegen **echtes WordPress 6.8.3 mit MariaDB** und
rufen die echten Endpunkte mit verschiedenen Rollen auf (Admin,
Schwimmwart, Trainer A, Trainer B, Springer, Mitglied, Gesperrt,
Trainer ohne Mannschaft). Jede Prüfung wurde in der Gegenprobe einmal im
echten Plugin entfernt — der Test muss dann rot werden.

| Bereich | Zusicherungen | Gegenprobe |
|---|---|---|
| Zuständigkeit Schwimmen, Anwesenheit, Wettkampf | 59 | 29 von 29 rot |
| Triathlon, Fitness, Trainer-Sportler | 90 | alle rot |
| Dateien, Atteste, Chat, Tickets | 32 | 15 von 15 rot |
| Abrechnung, Rechte, Springer, Import | 44 | 31 von 31 rot |
| Datenschutz-Auskunft und -Löschung | 58 | alle rot |
| Oberfläche (Browser) | 210 | — |

## Nicht durch das Plugin lösbar — bitte auf dem Server erledigen

* **Dateien außerhalb des Webroots bzw. Zugriff sperren:** Atteste und
  Anhänge liegen im Upload-Ordner. Unter Apache schützt die
  mitgelieferte `.htaccess`; **unter nginx wirkt sie nicht** — dort
  braucht es eine `deny`-Regel für die Plugin-Ordner.
* Hinter einem Proxy/CDN die echte Besucheradresse im Server setzen
  (z. B. nginx `real_ip`), nicht im Plugin.
* Starke Passwörter und Zwei-Faktor-Anmeldung für alle mit Verwaltungs-
  rechten (WordPress-Plugin), regelmäßige Updates von WordPress, PHP und
  allen Plugins, Backups.

## Bewusst nicht geändert (Entscheidung offen)

* **Verteiler:** Externe E-Mail-Adressen sind für alle Teilnehmer
  sichtbar, Antworten Externer sehen alle.
* **Bestzeiten-Import:** Die Zuordnungsliste nennt alle Schwimmer
  (Name, Jahrgang, Mannschaft), weil Meldeergebnisse mannschafts-
  übergreifend sind.
* Der Sicherheitsschlüssel (Nonce) steht in Download-Adressen; er gelangt
  dank `Referrer-Policy` nicht mehr an fremde Seiten.
* Zwei ältere Fehler in Tri/Fit (Knopf „Rückgängig" beim Ausfall, falsche
  Nummer beim Klick auf eine vergangene Session) sind nicht Teil dieser
  Prüfung.
