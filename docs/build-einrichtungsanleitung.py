#!/usr/bin/env python3
"""Erzeugt die Einrichtungsanleitung als PDF."""

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, Frame, PageTemplate, Paragraph, Spacer,
    Table, TableStyle, ListFlowable, ListItem, NextPageTemplate, CondPageBreak,
)

FONT_DIR = "/usr/share/fonts/truetype/dejavu/"
pdfmetrics.registerFont(TTFont("DJ", FONT_DIR + "DejaVuSans.ttf"))
pdfmetrics.registerFont(TTFont("DJ-B", FONT_DIR + "DejaVuSans-Bold.ttf"))
pdfmetrics.registerFont(TTFont("DJ-M", FONT_DIR + "DejaVuSansMono.ttf"))
# DejaVu Sans liefert keine kursive Schnitte mit - Kursiv faellt auf den Normalschnitt zurueck.
pdfmetrics.registerFontFamily("DJ", normal="DJ", bold="DJ-B", italic="DJ", boldItalic="DJ-B")

ACCENT = colors.HexColor("#1d5c8f")
ACCENT_LIGHT = colors.HexColor("#eaf2f8")
INK = colors.HexColor("#1f2933")
MUTED = colors.HexColor("#5c6773")
RULE = colors.HexColor("#d7dde3")
WARN_BG = colors.HexColor("#fdf4e3")
WARN_BORDER = colors.HexColor("#d99b28")

PAGE_W, PAGE_H = A4
MARGIN = 20 * mm
CONTENT_W = PAGE_W - 2 * MARGIN

styles = getSampleStyleSheet()

S = {
    "title": ParagraphStyle(
        "title", parent=styles["Title"], fontName="DJ-B", fontSize=23, leading=28,
        textColor=colors.white, alignment=TA_LEFT, spaceAfter=0,
    ),
    "subtitle": ParagraphStyle(
        "subtitle", fontName="DJ", fontSize=10.5, leading=15,
        textColor=colors.HexColor("#c8dcec"), alignment=TA_LEFT,
    ),
    "body": ParagraphStyle(
        "body", fontName="DJ", fontSize=9.6, leading=14.0, textColor=INK, spaceAfter=5,
    ),
    "lead": ParagraphStyle(
        "lead", fontName="DJ", fontSize=10.2, leading=15.0, textColor=MUTED, spaceAfter=9,
    ),
    "step": ParagraphStyle(
        "step", fontName="DJ-B", fontSize=12.4, leading=15, textColor=ACCENT,
    ),
    "steplead": ParagraphStyle(
        "steplead", fontName="DJ", fontSize=8.4, leading=11, textColor=MUTED,
    ),
    "h2": ParagraphStyle(
        "h2", fontName="DJ-B", fontSize=12.5, leading=16, textColor=INK,
        spaceBefore=3, spaceAfter=6,
    ),
    "li": ParagraphStyle(
        "li", fontName="DJ", fontSize=9.6, leading=13.6, textColor=INK, spaceAfter=2,
    ),
    "note": ParagraphStyle(
        "note", fontName="DJ", fontSize=9.1, leading=13.4, textColor=colors.HexColor("#6b4c12"),
    ),
    "notehead": ParagraphStyle(
        "notehead", fontName="DJ-B", fontSize=9.1, leading=13.4, textColor=colors.HexColor("#8a5a00"),
    ),
    "tblhead": ParagraphStyle(
        "tblhead", fontName="DJ-B", fontSize=8.8, leading=12, textColor=colors.white,
    ),
    "tbl": ParagraphStyle(
        "tbl", fontName="DJ", fontSize=8.8, leading=12.4, textColor=INK,
    ),
    "badge": ParagraphStyle(
        "badge", fontName="DJ-B", fontSize=12, leading=13,
        textColor=colors.white, alignment=TA_CENTER,
    ),
    "foot": ParagraphStyle(
        "foot", fontName="DJ", fontSize=7.8, leading=10, textColor=MUTED,
    ),
}


def path(text):
    """Menüpfad hervorheben."""
    return f'<font name="DJ-B" color="#1d5c8f">{text}</font>'


def code(text):
    """Technische Bezeichner."""
    return f'<font name="DJ-M" size="8.8" color="#8a3a3a">{text}</font>'


def step(number, title, subtitle=""):
    """Nummerierte Abschnittsüberschrift mit farbigem Badge."""
    badge = Table(
        [[Paragraph(str(number), S["badge"])]],
        colWidths=[10 * mm], rowHeights=[9 * mm],
    )
    badge.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), ACCENT),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("ALIGN", (0, 0), (-1, -1), "CENTER"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 1.6),
    ]))

    inner = [Paragraph(title, S["step"])]
    if subtitle:
        inner.append(Paragraph(subtitle, S["steplead"]))

    row = Table([[badge, inner]], colWidths=[13 * mm, CONTENT_W - 13 * mm])
    row.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 0),
        ("RIGHTPADDING", (0, 0), (-1, -1), 0),
        ("TOPPADDING", (0, 0), (-1, -1), 0),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
        ("LINEBELOW", (0, 0), (-1, -1), 0.6, RULE),
    ]))

    return [Spacer(1, 5), CondPageBreak(42 * mm), row, Spacer(1, 5)]


def bullets(items):
    """Aufzählung."""
    return ListFlowable(
        [ListItem(Paragraph(t, S["li"]), leftIndent=10) for t in items],
        bulletType="bullet", bulletChar="•", bulletFontName="DJ",
        bulletFontSize=8, leftIndent=11, bulletOffsetY=-1, spaceAfter=4,
    )


def note(head, text):
    """Hinweiskasten."""
    inner = [Paragraph(head, S["notehead"]), Paragraph(text, S["note"])]
    box = Table([[inner]], colWidths=[CONTENT_W])
    box.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), WARN_BG),
        ("LINEBEFORE", (0, 0), (0, -1), 2.4, WARN_BORDER),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    return [Spacer(1, 2), box, Spacer(1, 6)]


def table(header, rows, widths):
    """Einfache Tabelle im Akzentstil."""
    data = [[Paragraph(c, S["tblhead"]) for c in header]]
    data += [[Paragraph(c, S["tbl"]) for c in r] for r in rows]

    t = Table(data, colWidths=widths, repeatRows=1)
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), ACCENT),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f6f8fa")]),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING", (0, 0), (-1, -1), 4.2),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4.2),
        ("LINEBELOW", (0, 1), (-1, -1), 0.4, RULE),
        ("GRID", (0, 0), (-1, 0), 0, colors.white),
    ]))
    return [t, Spacer(1, 7)]


def header_band(canvas, doc):
    """Kopfband auf Seite 1, schlanke Kopfzeile auf Folgeseiten."""
    canvas.saveState()

    if doc.page == 1:
        canvas.setFillColor(ACCENT)
        canvas.rect(0, PAGE_H - 47 * mm, PAGE_W, 47 * mm, stroke=0, fill=1)

        canvas.setFillColor(colors.white)
        canvas.setFont("DJ-B", 23)
        canvas.drawString(MARGIN, PAGE_H - 26 * mm, "Einrichtungsanleitung")

        canvas.setFillColor(colors.HexColor("#bcd6ea"))
        canvas.setFont("DJ", 10.5)
        canvas.drawString(MARGIN, PAGE_H - 34 * mm,
                          "Mitgliederverwaltung Schwimmverein \u2013 WordPress-Plugin")
        canvas.drawString(MARGIN, PAGE_H - 39.5 * mm,
                          "In zwölf Schritten zur laufenden Verwaltung")
    else:
        canvas.setFillColor(MUTED)
        canvas.setFont("DJ", 7.6)
        canvas.drawString(MARGIN, PAGE_H - 12 * mm, "Einrichtungsanleitung · Mitgliederverwaltung Schwimmverein")
        canvas.setStrokeColor(RULE)
        canvas.setLineWidth(0.5)
        canvas.line(MARGIN, PAGE_H - 14 * mm, PAGE_W - MARGIN, PAGE_H - 14 * mm)

    canvas.setFillColor(MUTED)
    canvas.setFont("DJ", 7.6)
    canvas.drawRightString(PAGE_W - MARGIN, 12 * mm, f"Seite {doc.page}")
    canvas.drawString(MARGIN, 12 * mm, "WordPress-Plugin · Version 0.1.0")

    canvas.restoreState()


def build(filename):
    doc = BaseDocTemplate(
        filename, pagesize=A4,
        leftMargin=MARGIN, rightMargin=MARGIN,
        topMargin=MARGIN, bottomMargin=18 * mm,
        title="Einrichtungsanleitung – Mitgliederverwaltung Schwimmverein",
        author="Mitgliederverwaltung Schwimmverein",
        subject="Schritt-für-Schritt-Anleitung zur Ersteinrichtung",
    )

    first = Frame(MARGIN, 18 * mm, CONTENT_W, PAGE_H - 47 * mm - 18 * mm - 6 * mm, id="first")
    later = Frame(MARGIN, 18 * mm, CONTENT_W, PAGE_H - MARGIN - 18 * mm - 4 * mm, id="later")

    doc.addPageTemplates([
        PageTemplate(id="first", frames=[first], onPage=header_band),
        PageTemplate(id="later", frames=[later], onPage=header_band),
    ])

    story = [NextPageTemplate("later")]

    # Titel und Untertitel werden in header_band() direkt auf das Band gezeichnet.

    story.append(Paragraph(
        "Die gesamte Verwaltung läuft auf einer normalen Seite Ihrer Website &ndash; es gibt "
        "keinen getrennten Administrationsbereich mehr. Nach der Aktivierung bringt das Plugin "
        "bereits eine vollständige Startkonfiguration mit; diese Anleitung führt durch die "
        "Anpassung an Ihren Verein. Alles lässt sich später jederzeit ändern.",
        S["lead"]))

    story += table(
        ["Schritt", "Was passiert", "Aufwand"],
        [
            ["1&ndash;2", "Plugin installieren und die Verwaltungsseite anlegen", "15 Minuten"],
            ["3&ndash;5", "Sparten, Felder und Rollen anpassen", "1&ndash;2 Stunden"],
            ["6&ndash;8", "Beiträge, Familien und Bankdaten einrichten", "1 Stunde"],
            ["9&ndash;10", "Mitglieder einlesen, Beiträge berechnen", "je nach Bestand"],
            ["11&ndash;12", "Zahlungen abwickeln, Mitgliederzugang freischalten", "30 Minuten"],
        ],
        [22 * mm, CONTENT_W - 22 * mm - 28 * mm, 28 * mm],
    )

    # --- Schritt 1 ---
    story += step(1, "Plugin installieren", "WordPress → Plugins")
    story.append(Paragraph(
        f"Unter {path('Plugins → Installieren → Plugin hochladen')} die ZIP-Datei auswählen und "
        "aktivieren. Dabei legt das Plugin an: die Datenbanktabellen, drei Sparten, 16 "
        "Stammdatenfelder samt Rechten, sechs Rollenvorlagen, fünf Mitgliedsstatus, drei "
        "Zahlarten, vier Beitragsarten und vier Exportvorlagen.",
        S["body"]))
    story += note(
        "Gut zu wissen",
        "Die Startkonfiguration wird nur einmal angelegt. Ein späteres Deaktivieren und erneutes "
        "Aktivieren überschreibt Ihre Anpassungen nicht.")

    # --- Schritt 2 ---
    story += step(2, "Verwaltungsseite anlegen", "WordPress → Seiten")
    story.append(Paragraph(
        "Legen Sie eine neue Seite an, zum Beispiel &bdquo;Vereinsverwaltung&ldquo;, und fügen "
        "Sie dort diesen Shortcode ein:",
        S["body"]))
    story.append(Paragraph(code("[svm_app]"), S["body"]))
    story.append(Paragraph(
        "Seite veröffentlichen und aufrufen &ndash; dort läuft ab jetzt alles. Öffnen Sie "
        f"anschließend {path('Einstellungen → Verein')} und tragen Sie den Vereinsnamen sowie "
        "diese Seite ein. Der Eintrag der Seite ist wichtig, damit Formulare nach dem Speichern "
        "korrekt zurückführen.",
        S["body"]))
    story.append(Paragraph(
        "Was jemand auf der Seite sieht, ergibt sich allein aus seinen Rollen: Ein einfaches "
        "Mitglied sieht nur &bdquo;Meine Daten&ldquo;, die Kasse zusätzlich Beiträge und "
        "Zahlungen, die Vereinsverwaltung alles.",
        S["body"]))

    # --- Schritt 3 ---
    story += step(3, "Sparten anpassen", "Einstellungen → Sparten")
    story.append(Paragraph(
        "Die drei vorbelegten Sparten umbenennen, löschen oder ergänzen. Untergruppen wie "
        "Trainingsgruppen oder Mannschaften hängen Sie über das Feld <b>Gehört zu</b> ein &ndash; "
        "die Tiefe ist nicht begrenzt. Notieren Sie sich die <b>Nummer</b> jeder Sparte aus der "
        "Tabelle; sie wird in Schritt 6 für Beitragsbedingungen gebraucht.",
        S["body"]))

    # --- Schritt 4 ---
    story += step(4, "Stammdatenfelder festlegen", "Einstellungen → Felder")
    story.append(Paragraph(
        "Auch Vorname, Adresse und IBAN sind normale Felder und können umbenannt, ergänzt oder "
        "entfernt werden. Wichtig ist die <b>Systemrolle</b>: Sie sagt dem System, welches Feld "
        "für Anzeigename, Altersberechnung, E-Mail-Versand und SEPA steht.",
        S["body"]))
    story += table(
        ["Einstellung", "Wirkung"],
        [
            ["Systemrolle", "Verknüpft ein Feld mit einer Systemfunktion, z.&nbsp;B. "
                            + code("birthdate") + " für Altersregeln"],
            ["Sensibel", "Feld ist ohne ausdrückliche Freigabe unsichtbar und wird maskiert "
                         "angezeigt (Bankdaten, Gesundheitsangaben)"],
            ["In Mitgliederliste", "Feld erscheint als Spalte in der Übersicht"],
        ],
        [38 * mm, CONTENT_W - 38 * mm],
    )
    story.append(Paragraph(
        "Unter dem Formular steht die Tabelle <b>Wer darf dieses Feld sehen und ändern?</b> Dort "
        "wird je Rolle festgelegt: sehen, ändern, oder ändern nur mit Freigabe. So pflegen "
        "Übungsleiter Kontaktdaten, ohne je Bankdaten zu sehen.",
        S["body"]))

    # --- Schritt 5 ---
    story += step(5, "Rollen vergeben", "Einstellungen → Rollen und → Wer darf was")
    story.append(Paragraph(
        "Die sechs Rollenvorlagen sind frei änderbar. Entscheidend ist das Feld <b>Gilt für</b>: "
        "Es bestimmt, auf welche Mitglieder sich die Rechte beziehen.",
        S["body"]))
    story += table(
        ["Gilt für", "Bedeutung"],
        [
            ["Alle Mitglieder", "Vereinsweiter Zugriff (Vereinsverwaltung, Kassenwart, Vorstand)"],
            ["Zugeordnete Sparten", "Nur die Sparten, die der Person zugewiesen sind"],
            ["Eigene Gruppe", "Nur die geleitete Trainingsgruppe"],
            ["Nur eigener Datensatz", "Zugang zu &bdquo;Meine Daten&ldquo;"],
        ],
        [38 * mm, CONTENT_W - 38 * mm],
    )
    story.append(Paragraph(
        f"Unter {path('Einstellungen → Wer darf was')} weisen Sie Personen ihre Rollen und "
        "Sparten zu. Deshalb genügt <b>eine</b> Rolle &bdquo;Sparten-Leiter&ldquo; für beliebig "
        "viele Sparten &ndash; die Wirkung ergibt sich aus der Zuordnung.",
        S["body"]))

    # --- Schritt 6 ---
    story += step(6, "Beiträge einrichten", "Beiträge → Beitragsarten")
    story.append(Paragraph(
        "Vorbelegt sind Grundbeitrag Erwachsene und Jugend, ein Spartenbeitrag und eine "
        "Aufnahmegebühr. Beträge und Bedingungen anpassen oder eigene anlegen.",
        S["body"]))
    story.append(bullets([
        "<b>Betrag</b> &ndash; fester Wert, Wert aus einem Feld oder Formel, z.&nbsp;B. "
        + code("max(30, {alter} * 1.5)") + ".",
        "<b>Turnus</b> &ndash; jährlich bis monatlich. Je Fälligkeit entsteht eine eigene Forderung.",
        "<b>Fällig</b> &ndash; leer für den Periodenbeginn, " + code("01.03.") +
        " für einen festen Termin, " + code("+30d") + " für eine Frist.",
        "<b>Gültig ab/bis</b> &ndash; bei Beitragserhöhungen die alte Art beenden und eine neue "
        "beginnen, damit die Historie nachvollziehbar bleibt.",
    ]))
    story.append(Paragraph(
        "Darunter legt der Bedingungseditor fest, für wen der Beitrag gilt: Alter, Sparte, "
        "Status, Familienrolle oder jedes selbst angelegte Feld. Rabatte und Höchstbeträge "
        f"pflegen Sie getrennt unter {path('Beiträge → Rabatte')}.",
        S["body"]))
    story += note(
        "Ein Mitglied, mehrere Beiträge",
        "Ein Mitglied zahlt so viele Beiträge, wie auf es zutreffen &ndash; etwa je Sparte einen. "
        "Zusätzliche Beiträge ordnen Sie direkt beim Mitglied im Reiter <b>Beiträge</b> zu, "
        "wahlweise mit abweichendem Betrag. Umgekehrt lässt sich dort ein Beitrag für eine "
        "einzelne Person ausnehmen, obwohl die Regel greifen würde.")

    # --- Schritt 7 ---
    story += step(7, "Familien anlegen", "Familien")
    story.append(Paragraph(
        "Eine Familie bündelt mehrere Mitglieder. Legen Sie sie entweder unter "
        f"{path('Familien')} an oder direkt beim Mitglied im Reiter <b>Familie</b>.",
        S["body"]))
    story.append(Paragraph(
        "Über das Feld <b>Zugeordnet zu</b> entstehen Verzweigungen: Kinder hängen unter einem "
        "Elternteil, und ein Kind kann selbst wieder einen Zweig haben. Aus dem Baum berechnet "
        "das System zwei Werte, auf die Beitragsregeln zugreifen können:",
        S["body"]))
    story.append(bullets([
        "<b>Position in der Familie</b> &ndash; Reihenfolge über alle Personen hinweg.",
        "<b>Wievieltes Kind</b> &ndash; zählt nur die Kinder, damit eine Regel wie "
        "&bdquo;ab dem 3. Kind beitragsfrei&ldquo; genau das trifft.",
    ]))
    story.append(Paragraph(
        "Ein Höchstbetrag je Familie ist als Beispielregel bereits angelegt, aber zunächst "
        "abgeschaltet. Er verteilt den Nachlass anteilig über alle Personen der Familie.",
        S["body"]))

    # --- Schritt 8 ---
    story += step(8, "Bankdaten hinterlegen", "Zahlungen → Bankdaten des Vereins")
    story.append(Paragraph(
        "Im vorbereiteten Profil die fehlenden Angaben ergänzen:",
        S["body"]))
    story.append(bullets([
        "<b>IBAN des Vereinskontos</b> und optional die BIC,",
        "<b>Gläubiger-Identifikationsnummer</b> &ndash; wird von der Deutschen Bundesbank "
        "vergeben und ist für Lastschriften zwingend,",
        "<b>Verwendungszweck</b> mit Platzhaltern wie " + code("{zeitraum}") + " und "
        + code("{mitgliedsnummer}") + ",",
        "<b>Vorlauffristen</b> &ndash; voreingestellt 5 Banktage für Erst-, 2 für Folgelastschriften.",
    ]))
    story += note(
        "Lastschrift statt Überweisung",
        "Für den Beitragseinzug erzeugt das System eine <b>Lastschriftdatei</b> (pain.008) &ndash; "
        "der Verein zieht ein. Eine echte Überweisungsdatei (pain.001) brauchen Sie nur, wenn der "
        "Verein selbst auszahlt. Dafür legen Sie ein zweites Profil an.")

    # --- Schritt 9 ---
    story += step(9, "Mitglieder einlesen", "Import & Export → Importieren")
    story.append(Paragraph(
        "Kleine Bestände erfassen Sie direkt über <b>Neues Mitglied</b>. Für vorhandene Listen "
        "gibt es den CSV-Import in drei Schritten:",
        S["body"]))
    story.append(bullets([
        "<b>Datei hochladen</b> &ndash; eine CSV mit Kopfzeile; die Spaltennamen sind frei.",
        "<b>Spalten zuordnen</b> &ndash; das System schlägt passende Ziele vor. Nicht benötigte "
        "Spalten bleiben auf &bdquo;nicht importieren&ldquo;.",
        "<b>Testlauf</b> &ndash; speichert nichts und meldet Fehler zeilenweise. Führen Sie ihn "
        "immer zuerst aus.",
    ]))
    story.append(Paragraph(
        "Mit importiert werden können auch SEPA-Mandate (IBAN, Mandatsreferenz, Datum) sowie "
        "Familie und Rolle in der Familie &ndash; noch nicht vorhandene Familien legt das System "
        "dabei an.",
        S["body"]))
    story.append(Paragraph(
        f"Umgekehrt liefert {path('Import & Export → Exportieren')} fertige Vorlagen für "
        "Mitgliederliste, Beitragsübersicht, Zahlungsjournal und Mandate. Vorlagen lassen sich "
        "frei zusammenstellen: Inhalt, Spalten (auch eigene Felder, Familie und zugeordnete "
        "Beiträge), Format sowie die Rollen, die sie herunterladen dürfen.",
        S["body"]))

    # --- Schritt 10 ---
    story += step(10, "Beiträge berechnen", "Beiträge → Beiträge berechnen")
    story.append(Paragraph(
        "Zeitraum wählen und die Vorschau erstellen. Sie zeigt für jede Position, welche Regeln "
        "in welcher Reihenfolge gegriffen haben &ndash; also warum ein Mitglied genau diesen "
        "Betrag zahlt. Erst wenn das Ergebnis stimmt, erzeugen Sie die Forderungen verbindlich.",
        S["body"]))
    story += note(
        "Vor dem ersten echten Lauf",
        "Prüfen Sie die Summe stichprobenartig gegen die Vorjahreswerte. Ein Konfigurationsfehler "
        "fällt in der Vorschau auf &ndash; nach dem Einzug wird er zur Rückbuchung.")

    # --- Schritt 11 ---
    story += step(11, "Zahlungen abwickeln", "Zahlungen")
    story += table(
        ["Bereich", "Wofür"],
        [
            ["Zahlung eintragen", "Überweisungen und Barzahlungen. Das Feld <b>Name des "
                                  "Zahlers</b> bleibt leer, wenn das Mitglied selbst gezahlt hat "
                                  "&ndash; sonst den tatsächlichen Zahler eintragen, etwa ein "
                                  "Elternteil."],
            ["Lastschrift", "Bankprofil wählen, Vorschau prüfen, Datei erzeugen und im "
                            "Online-Banking einreichen."],
            ["Zahlungen", "Journal aller Eingänge; hier werden auch Rücklastschriften "
                          "gekennzeichnet."],
            ["Mahnwesen", "Stufen mit Frist, Gebühr und E-Mail-Vorlage. Wer nicht mahnen will, "
                          "legt keine Stufe an."],
        ],
        [36 * mm, CONTENT_W - 36 * mm],
    )
    story.append(Paragraph(
        "Sobald die Bank den Einzug ausgeführt hat, setzen Sie den Lauf auf <b>ausgeführt</b>. "
        "Das System bucht dann automatisch für jede Position eine Zahlung und gleicht die "
        "Forderungen aus.",
        S["body"]))

    # --- Schritt 12 ---
    story += step(12, "Mitgliederzugang freischalten", "Mitglieder → Mitglied öffnen")
    story.append(Paragraph(
        "Damit sich ein Mitglied anmelden kann, braucht es ein WordPress-Benutzerkonto. Dieses "
        "verknüpfen Sie im Mitgliedsdatensatz unter <b>Zugang zur Verwaltung</b>; die Rolle "
        "&bdquo;Mitglied&ldquo; vergeben Sie unter "
        f"{path('Einstellungen → Wer darf was')}.",
        S["body"]))
    story.append(Paragraph(
        "Das Mitglied sieht dann dieselbe Seite &ndash; aber nur den Bereich "
        "&bdquo;Meine Daten&ldquo; mit Nachrichten, den eigenen Stammdaten und der eigenen "
        "Beitragshistorie.",
        S["body"]))
    story += note(
        "Freigabepflichtige Felder",
        "In der Standardkonfiguration darf ein Mitglied Kontaktdaten direkt ändern. Änderungen an "
        "Name, Geburtsdatum und IBAN werden dagegen als Wunsch gespeichert und erst nach "
        "Bestätigung übernommen &ndash; sichtbar unter <b>Mitglieder → Änderungswünsche</b>. "
        "So landet keine unbemerkt geänderte IBAN im nächsten Einzug.")

    # --- Abschluss ---
    story.append(CondPageBreak(38 * mm))
    story.append(Paragraph("Löschen: stornieren oder wirklich entfernen?", S["h2"]))
    story.append(Paragraph(
        "Alles, was Sie anlegen können, können Sie auch wieder löschen. Im Finanzbereich gilt "
        "dabei eine Schutzregel, damit die Kasse prüfbar bleibt:",
        S["body"]))
    story += table(
        ["Was", "Löschbar, solange …", "sonst"],
        [
            ["Forderung", "nichts darauf gezahlt wurde und sie in keinem Zahllauf steckt",
             "stornieren"],
            ["Beitragslauf", "keine seiner Forderungen bezahlt ist &ndash; löscht sie alle mit",
             "einzeln stornieren"],
            ["SEPA-Zahllauf", "er storniert ist", "erst stornieren"],
            ["SEPA-Mandat", "damit noch nie eingezogen wurde", "widerrufen"],
            ["Zahlung", "immer &ndash; die Forderungen werden wieder offen", "&ndash;"],
            ["Mitglied", "immer &ndash; die Zahlungshistorie bleibt erhalten", "&ndash;"],
        ],
        [32 * mm, CONTENT_W - 32 * mm - 30 * mm, 30 * mm],
    )
    story.append(Paragraph(
        "Übergeordnetes zu löschen lässt nichts verwaisen: Untergruppen einer gelöschten Sparte "
        "rücken eine Ebene nach oben, Mitglieder einer aufgelösten Familie bleiben bestehen. "
        "Jede Löschung steht im Änderungsprotokoll.",
        S["body"]))

    story.append(CondPageBreak(30 * mm))
    story.append(Paragraph("Was bewusst nicht änderbar ist", S["h2"]))
    story.append(Paragraph(
        "Die Struktur der SEPA-Dateien folgt dem ISO-Standard; frei ist die Auswahl und "
        "Parametrierung des Profils, nicht der Aufbau der Datei selbst. Und es muss immer eine "
        "Rolle mit dem Recht &bdquo;Rollen und Rechte verwalten&ldquo; bestehen bleiben, damit "
        "sich der Verein nicht selbst aussperrt.",
        S["body"]))


    story.append(CondPageBreak(50 * mm))
    story.append(Paragraph("Checkliste vor dem Echtbetrieb", S["h2"]))
    story.append(bullets([
        "Seite mit dem Shortcode angelegt und in den Einstellungen eingetragen.",
        "Vereinsname, IBAN und Gläubiger-ID hinterlegt.",
        "Beiträge in der Vorschau geprüft und die Summe gegen das Vorjahr verglichen.",
        "Feldrechte kontrolliert &ndash; insbesondere, wer Bankdaten sehen darf.",
        "Mindestens eine Rolle mit dem Recht &bdquo;Rollen und Rechte verwalten&ldquo; vergeben.",
        "Testmitglied angelegt, Anmeldung und Änderungswunsch ausprobiert.",
        "Konfiguration einmal exportiert und gesichert.",
    ]))

    story.append(CondPageBreak(105 * mm))
    story.append(Paragraph("Schnellübersicht: Wo finde ich was?", S["h2"]))
    story += table(
        ["Aufgabe", "Wo"],
        [
            ["Mitglied anlegen oder suchen", "Mitglieder"],
            ["Änderungswunsch freigeben", "Mitglieder → Änderungswünsche"],
            ["Familie und Verzweigungen pflegen", "Familien"],
            ["Weiteren Beitrag zuordnen", "Mitglieder → Mitglied → Beiträge"],
            ["Beitrag ändern", "Beiträge → Beitragsarten"],
            ["Rabatt oder Höchstbetrag", "Beiträge → Rabatte"],
            ["Forderungen erzeugen", "Beiträge → Beiträge berechnen"],
            ["Überweisung eintragen", "Zahlungen → Zahlung eintragen"],
            ["Lastschriftdatei erzeugen", "Zahlungen → Lastschrift"],
            ["Bankdaten des Vereins", "Zahlungen → Bankdaten des Vereins"],
            ["Mitglieder exportieren oder einlesen", "Import &amp; Export"],
            ["Feld anlegen oder Rechte ändern", "Einstellungen → Felder"],
            ["Rolle vergeben", "Einstellungen → Wer darf was"],
            ["Wer hat was geändert?", "Import &amp; Export → Protokoll"],
        ],
        [70 * mm, CONTENT_W - 70 * mm],
    )

    doc.build(story)


if __name__ == "__main__":
    import sys
    build(sys.argv[1] if len(sys.argv) > 1 else "anleitung.pdf")
    print("PDF erstellt.")
