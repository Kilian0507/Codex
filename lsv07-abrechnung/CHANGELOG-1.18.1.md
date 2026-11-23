# Abrechnung 1.18.1 — Notfallkopie nur für die WordPress-Administration

Den Knopf „Notfallkopie anzeigen“ (Verwaltung → Sätze → Datensicherheit)
sehen nur noch WordPress-Administratoren (`manage_options`).
Plugin-Administratoren ohne WordPress-Adminrechte sehen ihn nicht mehr.
Der Server liess die Notfallkopie schon vorher nur für die
WordPress-Administration zu, mit Passwortabfrage, höchstens 5
Fehlversuchen je Viertelstunde und Protokolleintrag.

Geprüft gegen echtes WordPress (WordPress-Admin ja, Admin ohne
`manage_options` nein, Abonnent nein) und im Browser (Knopf da bzw. weg).
Die Gegenprobe wurde für Server und Oberfläche je einmal gebrochen, und der
Lauf wurde rot.
