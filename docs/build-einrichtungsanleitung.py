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
                          "In elf Schritten vom leeren System zum ersten Beitragseinzug")
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
        "Das Plugin bringt nach der Aktivierung bereits eine vollständige Startkonfiguration mit: "
        "drei Sparten, Standardfelder, sechs Rollenvorlagen, Beitragsarten und Exportprofile. "
        "Diese Anleitung führt durch die Anpassung an den eigenen Verein. "
        "Alles, was hier eingerichtet wird, lässt sich später jederzeit ändern &ndash; "
        "es ist keine Entscheidung für immer.",
        S["lead"]))

    story += table(
        ["Schritt", "Was passiert", "Aufwand"],
        [
            ["1&ndash;2", "Installation und Grundeinstellungen", "10 Minuten"],
            ["3&ndash;5", "Struktur, Felder und Rollen an den Verein anpassen", "1&ndash;2 Stunden"],
            ["6&ndash;7", "Beiträge und SEPA einrichten", "1 Stunde"],
            ["8&ndash;9", "Mitglieder importieren, erster Beitragslauf", "je nach Bestand"],
            ["10&ndash;11", "Zahlungen erfassen, Mitgliederportal freischalten", "30 Minuten"],
        ],
        [22 * mm, CONTENT_W - 22 * mm - 28 * mm, 28 * mm],
    )

    # --- Schritt 1 ---
    story += step(1, "Plugin installieren", "WordPress-Adminbereich")
    story.append(Paragraph(
        f"Unter {path('Plugins → Installieren → Plugin hochladen')} die ZIP-Datei auswählen und aktivieren. "
        "Alternativ den entpackten Ordner nach " + code("wp-content/plugins/") + " kopieren.",
        S["body"]))
    story.append(Paragraph("Beim Aktivieren legt das Plugin automatisch an:", S["body"]))
    story.append(bullets([
        "die Datenbanktabellen,",
        "drei Sparten (Schwimmen, Wasserball, Breitensport),",
        "16 Stammdatenfelder samt Rechtematrix,",
        "sechs Rollenvorlagen, fünf Mitgliedsstatus und drei Zahlarten,",
        "vier Beitragsarten mit Beispielregeln sowie vier Exportprofile.",
    ]))
    story += note(
        "Gut zu wissen",
        "Die Startkonfiguration wird nur einmal angelegt. Ein späteres Deaktivieren und erneutes "
        "Aktivieren überschreibt Ihre Anpassungen also nicht.")

    # --- Schritt 2 ---
    story += step(2, "Grundeinstellungen setzen", "Verein → Konfiguration → Einstellungen")
    story.append(bullets([
        "<b>Name des Vereins</b> &ndash; erscheint im Verwendungszweck der Lastschriften und in E-Mails.",
        "<b>Geschäftsjahresbeginn</b> &ndash; Format TT-MM, üblicherweise " + code("01-01") + ".",
        "<b>Aufbewahrungsfrist nach Austritt</b> &ndash; Vorgabe 120 Monate wegen der "
        "zehnjährigen Aufbewahrungspflicht für Finanzunterlagen.",
        "<b>Seite des Mitgliederportals</b> &ndash; wird in Schritt 11 gesetzt.",
    ]))

    # --- Schritt 3 ---
    story += step(3, "Struktur anpassen", "Verein → Konfiguration → Struktur")
    story.append(Paragraph(
        "Die drei vorbelegten Sparten umbenennen, löschen oder ergänzen. Untergeordnete Einheiten "
        "wie Trainingsgruppen oder Mannschaften werden über das Feld <b>Übergeordnete Einheit</b> "
        "eingehängt &ndash; die Tiefe ist nicht begrenzt.",
        S["body"]))
    story.append(Paragraph(
        "Notieren Sie sich die <b>ID</b> jeder Einheit aus der Übersichtstabelle. Sie wird in "
        "Schritt 6 für Beitragsbedingungen gebraucht.",
        S["body"]))

    # --- Schritt 4 ---
    story += step(4, "Stammdatenfelder festlegen", "Verein → Konfiguration → Felder")
    story.append(Paragraph(
        "Auch Vorname, Adresse und IBAN sind normale Felder und können umbenannt, ergänzt oder "
        "entfernt werden. Wichtig ist nur die <b>Systemrolle</b>: Sie sagt dem System, welches Feld "
        "es für Anzeigename, Altersberechnung, E-Mail-Versand und SEPA verwenden soll.",
        S["body"]))
    story += table(
        ["Einstellung", "Wirkung"],
        [
            ["Systemrolle", "Verknüpft ein Feld mit einer Systemfunktion, z.&nbsp;B. "
                            + code("birthdate") + " für Altersregeln"],
            ["Sensibel", "Feld ist ohne ausdrückliche Freigabe unsichtbar und wird maskiert "
                         "dargestellt (Bankdaten, Gesundheitsangaben)"],
            ["In Mitgliederliste", "Feld erscheint als Spalte in der Übersicht"],
            ["Prüfmuster", "Optionaler regulärer Ausdruck zur Eingabeprüfung"],
        ],
        [38 * mm, CONTENT_W - 38 * mm],
    )
    story.append(Paragraph(
        "Nach dem Speichern erscheint unter dem Formular die Tabelle <b>Wer darf dieses Feld sehen "
        "und ändern?</b> Dort wird je Rolle festgelegt: sehen, ändern, oder ändern nur mit Freigabe. "
        "Über diesen Hebel pflegen Übungsleiter Kontaktdaten, ohne je Bankdaten zu sehen.",
        S["body"]))

    # --- Schritt 5 ---
    story += step(5, "Rollen vergeben", "Verein → Konfiguration → Rollen & Benutzerzuordnung")
    story.append(Paragraph(
        "Die sechs Rollenvorlagen sind frei änderbar. Entscheidend ist der <b>Geltungsbereich</b>: "
        "Er bestimmt, auf welche Mitglieder sich die Rechte beziehen.",
        S["body"]))
    story += table(
        ["Geltungsbereich", "Bedeutung"],
        [
            ["Alle Mitglieder", "Vereinsweiter Zugriff (Vereinsverwaltung, Kassenwart, Vorstand)"],
            ["Zugeordnete Sparten", "Nur die Sparten, die dem Benutzer zugewiesen sind"],
            ["Eigene Gruppe", "Nur die geleitete Trainingsgruppe"],
            ["Nur eigener Datensatz", "Self-Service im Mitgliederportal"],
        ],
        [38 * mm, CONTENT_W - 38 * mm],
    )
    story.append(Paragraph(
        f"Im Reiter {path('Benutzerzuordnung')} werden WordPress-Benutzern Rollen und Zuständigkeiten "
        "zugewiesen. Deshalb genügt <b>eine</b> Rolle „Sparten-Leiter“ für beliebig viele Sparten: "
        "Die Wirkung ergibt sich aus der Zuordnung der Person.",
        S["body"]))

    # --- Schritt 6 ---
    story += step(6, "Beiträge einrichten", "Verein → Beiträge → Beitragsarten")
    story.append(Paragraph(
        "Vorbelegt sind Grundbeitrag Erwachsene und Jugend, ein Spartenbeitrag Wasserball und eine "
        "Aufnahmegebühr. Beträge und Bedingungen anpassen oder eigene Beitragsarten anlegen.",
        S["body"]))
    story.append(bullets([
        "<b>Betragsermittlung</b> &ndash; fester Betrag, Wert aus einem Feld oder Formel "
        "(z.&nbsp;B. " + code("max(30, {alter} * 1.5)") + ").",
        "<b>Turnus</b> &ndash; jährlich, halbjährlich, quartalsweise, monatlich oder einmalig. "
        "Je Fälligkeit entsteht eine eigene Forderung.",
        "<b>Fälligkeitsregel</b> &ndash; leer für den Periodenbeginn, " + code("01.03.") +
        " für einen festen Termin, " + code("+30d") + " für eine Frist ab Periodenbeginn.",
        "<b>Anteilige Berechnung</b> &ndash; monats- oder taggenau bei unterjährigem Ein- und Austritt.",
        "<b>Gültig von/bis</b> &ndash; bei Beitragserhöhungen die alte Art beenden und eine neue "
        "beginnen, damit die Historie nachvollziehbar bleibt.",
    ]))
    story.append(Paragraph(
        "Im unteren Teil des Formulars legt der Bedingungseditor fest, für wen der Beitrag gilt: "
        "Alter, Sparte, Status, Anzahl Mitgliedschaften, Familienposition oder jedes selbst angelegte "
        f"Feld. Rabatte und Deckelungen werden getrennt davon unter {path('Rabatte & Zuschläge')} "
        "gepflegt &ndash; wahlweise je Beitragsposition, je Mitglied oder je Familienverbund.",
        S["body"]))

    # --- Schritt 7 ---
    story += step(7, "SEPA vorbereiten", "Verein → Zahlungen → Dateiprofile")
    story.append(Paragraph(
        "Im vorbereiteten Profil „SEPA-Lastschrift Hauptkonto“ die fehlenden Angaben ergänzen:",
        S["body"]))
    story.append(bullets([
        "<b>IBAN des Vereinskontos</b> und optional die BIC,",
        "<b>Gläubiger-Identifikationsnummer</b> &ndash; wird von der Deutschen Bundesbank vergeben "
        "und ist für Lastschriften zwingend,",
        "<b>Verwendungszweck</b> mit Platzhaltern wie " + code("{zeitraum}") + " und "
        + code("{mitgliedsnummer}") + ",",
        "<b>Vorlauffristen</b> &ndash; voreingestellt 5 Banktage für Erst- und 2 für Folgelastschriften.",
    ]))
    story += note(
        "Lastschrift statt Überweisung",
        "Für den Beitragseinzug erzeugt das System eine <b>Lastschriftdatei</b> (pain.008) &ndash; "
        "der Verein zieht ein. Eine echte Überweisungsdatei (pain.001) brauchen Sie nur, wenn der "
        "Verein selbst auszahlt, etwa für Aufwandsentschädigungen. Dafür legen Sie ein zweites Profil an.")

    # --- Schritt 8 ---
    story += step(8, "Mitglieder anlegen oder importieren",
                  "Verein → Mitglieder bzw. Auswertungen → Import")
    story.append(Paragraph(
        "Kleine Bestände werden direkt über <b>Neues Mitglied</b> erfasst. Für vorhandene Daten gibt "
        "es den CSV-Import in drei Schritten: Datei hochladen, Spalten den eigenen Feldern zuordnen, "
        "Testlauf. Der Testlauf speichert nichts und zeigt Fehler zeilenweise an &ndash; führen Sie "
        "ihn immer zuerst aus.",
        S["body"]))
    story.append(Paragraph(
        "SEPA-Mandate können beim Import über die Spalten IBAN, Mandatsreferenz und Mandatsdatum "
        "gleich mit angelegt werden. Alternativ werden sie einzeln im Mitgliedsdatensatz erfasst.",
        S["body"]))

    # --- Schritt 9 ---
    story += step(9, "Ersten Beitragslauf simulieren", "Verein → Beiträge → Beitragslauf")
    story.append(Paragraph(
        "Zeitraum wählen und <b>Simulation starten</b>. Die Vorschau zeigt für jede Position, welche "
        "Regeln in welcher Reihenfolge gegriffen haben &ndash; also warum ein Mitglied genau diesen "
        "Betrag zahlt. Erst wenn das Ergebnis stimmt, werden die Forderungen mit "
        "<b>Forderungen jetzt erzeugen</b> festgeschrieben.",
        S["body"]))
    story += note(
        "Vor dem ersten echten Lauf",
        "Prüfen Sie die Summe stichprobenartig gegen die Vorjahreswerte. Ein Konfigurationsfehler "
        "fällt in der Simulation auf &ndash; nach dem Einzug wird er zur Rückbuchung.")

    # --- Schritt 10 ---
    story += step(10, "Zahlungen abwickeln", "Verein → Zahlungen")
    story += table(
        ["Reiter", "Wofür"],
        [
            ["Zahlung erfassen", "Überweisungen und Barzahlungen eintragen. Das Feld "
                                 "<b>Wer hat gezahlt?</b> bleibt leer, wenn das Mitglied selbst "
                                 "gezahlt hat &ndash; sonst den tatsächlichen Zahler eintragen, "
                                 "etwa ein Elternteil."],
            ["Zahlläufe (SEPA)", "Profil wählen, Vorschau prüfen, Lauf erzeugen, Datei herunterladen "
                                 "und im Online-Banking einreichen."],
            ["Zahlungsjournal", "Alle Zahlungen mit Zahler und Zuordnung; hier werden auch "
                                "Rücklastschriften gekennzeichnet."],
            ["Mahnwesen", "Mahnstufen mit Frist, Gebühr und E-Mail-Vorlage. Wer nicht mahnen will, "
                          "legt keine Stufe an."],
        ],
        [34 * mm, CONTENT_W - 34 * mm],
    )
    story.append(Paragraph(
        "Sobald die Bank den Einzug ausgeführt hat, setzen Sie den Lauf auf <b>ausgeführt</b>. "
        "Das System bucht dann automatisch für jede Position eine Zahlung und gleicht die "
        "Forderungen aus. Teilzahlungen und Guthaben werden dabei korrekt fortgeschrieben.",
        S["body"]))

    # --- Schritt 11 ---
    story += step(11, "Mitgliederportal freischalten", "Seiten → Erstellen")
    story.append(Paragraph(
        f"Eine neue Seite anlegen, den Shortcode {code('[svm_portal]')} einfügen und veröffentlichen. "
        f"Anschließend die Seite unter {path('Konfiguration → Einstellungen')} auswählen.",
        S["body"]))
    story.append(Paragraph(
        "Damit ein Mitglied sich anmelden kann, braucht es ein WordPress-Benutzerkonto, das im "
        "Mitgliedsdatensatz unter <b>Portalzugang</b> verknüpft wird, sowie die Rolle „Mitglied“. "
        "Im Portal sieht es Nachrichten, die eigenen Stammdaten und die eigene Beitragshistorie.",
        S["body"]))
    story += note(
        "Freigabepflichtige Felder",
        "In der Standardkonfiguration darf ein Mitglied Kontaktdaten direkt ändern. Änderungen an "
        "Name, Geburtsdatum und IBAN werden dagegen als Antrag gespeichert und erst nach Bestätigung "
        "übernommen &ndash; sichtbar unter <b>Mitglieder → Offene Änderungsanträge</b>. So landet "
        "keine unbemerkt geänderte IBAN im nächsten Einzug.")

    # --- Abschluss ---
    story.append(Spacer(1, 6))
    story.append(CondPageBreak(45 * mm))
    story.append(Paragraph("Schnellübersicht: Wo finde ich was?", S["h2"]))
    story += table(
        ["Aufgabe", "Menüpfad"],
        [
            ["Mitglied anlegen oder suchen", "Verein → Mitglieder"],
            ["Änderungsantrag freigeben", "Verein → Mitglieder → Offene Änderungsanträge"],
            ["Beitrag ändern", "Verein → Beiträge → Beitragsarten"],
            ["Rabatt oder Deckelung", "Verein → Beiträge → Rabatte &amp; Zuschläge"],
            ["Forderungen erzeugen", "Verein → Beiträge → Beitragslauf"],
            ["Überweisung eintragen", "Verein → Zahlungen → Zahlung erfassen"],
            ["SEPA-Datei erzeugen", "Verein → Zahlungen → Zahlläufe"],
            ["Bankdaten des Vereins", "Verein → Zahlungen → Dateiprofile"],
            ["Feld anlegen oder Rechte ändern", "Verein → Konfiguration → Felder"],
            ["Rolle vergeben", "Verein → Konfiguration → Benutzerzuordnung"],
            ["Liste exportieren", "Verein → Auswertungen → Exporte"],
            ["Wer hat was geändert?", "Verein → Auswertungen → Änderungsprotokoll"],
        ],
        [62 * mm, CONTENT_W - 62 * mm],
    )

    story.append(Paragraph("Checkliste vor dem Echtbetrieb", S["h2"]))
    story.append(bullets([
        "Vereinsname und Gläubiger-ID eingetragen, IBAN des Vereinskontos geprüft.",
        "Beitragsarten simuliert und die Summe gegen das Vorjahr geprüft.",
        "Feldrechte kontrolliert &ndash; insbesondere, wer Bankdaten sehen darf.",
        "Mindestens eine Rolle mit dem Recht „Rollen und Rechte verwalten“ ist vergeben.",
        "Testmitglied angelegt, Portal-Anmeldung und Änderungsantrag ausprobiert.",
        f"Konfiguration einmal über {path('Konfiguration → Einstellungen')} exportiert und gesichert.",
    ]))

    story.append(CondPageBreak(32 * mm))
    story.append(Paragraph("Was bewusst nicht änderbar ist", S["h2"]))
    story.append(Paragraph(
        "Forderungen und Zahlläufe werden <b>storniert, nicht gelöscht</b> &ndash; das ist keine "
        "fehlende Flexibilität, sondern Voraussetzung für eine prüfbare Kasse. Ebenso folgt die "
        "Struktur der SEPA-Dateien dem ISO-Standard; frei ist die Auswahl und Parametrierung des "
        "Profils, nicht der Aufbau der Datei selbst.",
        S["body"]))

    doc.build(story)


if __name__ == "__main__":
    import sys
    build(sys.argv[1] if len(sys.argv) > 1 else "anleitung.pdf")
    print("PDF erstellt.")
