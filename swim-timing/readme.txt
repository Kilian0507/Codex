=== Swim Timing ===
Contributors: swim-timing
Tags: swimming, timing, shortcode
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Frontend-Zeitnahme für Schwimmveranstaltungen (auch Staffeln): ein Shortcode, drei Ansichten.

== Description ==

Der Shortcode `[swim_timing]` zeigt berechtigten Nutzern einen Adminbereich zur Verwaltung von
Startpersonen und deren Zwischenzeiten (inkl. direktem Einfügen von Tabellen aus Excel/Sheets),
allen anderen Besuchern einen öffentlichen Bereich mit den Startzeiten aller Startpersonen sowie
einer persönlichen Abfrage (Vorname, Nachname, Startzeit -> eigene Zwischenzeiten + PDF-Download),
und über einen QR-Code (im Adminbereich als Bild herunterladbar) eine dritte Ansicht, in der auch
nicht angemeldete Helfer vor Ort eine Startperson per Namenssuche auswählen und deren Zeit eintragen
können.

Zeiten: Startzeit ist eine Uhrzeit (Std:Min), Meldezeit/Endzeit/Zwischenzeiten sind Wettkampfzeiten
im Format Min:Sek:Hundertstel.

Staffeln: Startpersonen können einer Staffel (Rot/Gelb) zugeordnet werden. Die Meldezeit bestimmt
die Reihenfolge innerhalb der Staffel; ändert sich die Endzeit einer Startperson, wird die Startzeit
der nächsten Person in derselben Staffel automatisch berechnet.

Konfiguration unter Einstellungen -> Swim Timing: Überschrift (wird überall als Titel verwendet)
und die berechtigte Benutzerrolle für den Adminbereich.

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/` hochladen.
2. Plugin aktivieren.
3. Unter Einstellungen -> Swim Timing die Überschrift und Rolle konfigurieren.
4. Shortcode `[swim_timing]` auf einer Seite einfügen.

== Changelog ==

= 1.1.0 =
* Staffel-Logik (Rot/Gelb): Endzeit einer Startperson berechnet automatisch die Startzeit der nächsten.
* Öffentliche, unangemeldete Zeiterfassung per QR-Code (Namenssuche + Zeiteingabe).
* QR-Code im Adminbereich als PNG herunterladbar.
* Öffentliche Startzeiten-Übersicht.
* Zeiten (Meldezeit/Endzeit/Zwischenzeit) jetzt im Format Min:Sek:Hundertstel.

= 1.0.0 =
* Initiale Version.
