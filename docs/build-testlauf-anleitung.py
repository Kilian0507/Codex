#!/usr/bin/env python3
"""Erzeugt die Kurzanleitung für den Testlauf als PDF."""

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, Frame, KeepTogether, PageTemplate, Paragraph, Spacer,
    Table, TableStyle, ListFlowable, ListItem, NextPageTemplate, CondPageBreak,
)

FONT_DIR = "/usr/share/fonts/truetype/dejavu/"
pdfmetrics.registerFont(TTFont("DJ", FONT_DIR + "DejaVuSans.ttf"))
pdfmetrics.registerFont(TTFont("DJ-B", FONT_DIR + "DejaVuSans-Bold.ttf"))
pdfmetrics.registerFont(TTFont("DJ-M", FONT_DIR + "DejaVuSansMono.ttf"))
# DejaVu Sans liefert keine kursive Schnitte mit - Kursiv faellt auf den Normalschnitt zurueck.
pdfmetrics.registerFontFamily("DJ", normal="DJ", bold="DJ-B", italic="DJ", boldItalic="DJ-B")

ACCENT = colors.HexColor("#1d5c8f")
INK = colors.HexColor("#1f2933")
MUTED = colors.HexColor("#5c6773")
RULE = colors.HexColor("#d7dde3")
WARN_BG = colors.HexColor("#fdf4e3")
WARN_BORDER = colors.HexColor("#d99b28")
OK_BG = colors.HexColor("#eef6ee")
OK_BORDER = colors.HexColor("#4f8a4f")

PAGE_W, PAGE_H = A4
MARGIN = 20 * mm
CONTENT_W = PAGE_W - 2 * MARGIN

styles = getSampleStyleSheet()

S = {
    "body": ParagraphStyle(
        "body", fontName="DJ", fontSize=9.6, leading=13.4, textColor=INK, spaceAfter=4,
    ),
    "lead": ParagraphStyle(
        "lead", fontName="DJ", fontSize=10.2, leading=14.4, textColor=MUTED, spaceAfter=7,
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
        "li", fontName="DJ", fontSize=9.6, leading=13.0, textColor=INK, spaceAfter=2,
    ),
    "note": ParagraphStyle(
        "note", fontName="DJ", fontSize=9.1, leading=13.4, textColor=colors.HexColor("#6b4c12"),
    ),
    "notehead": ParagraphStyle(
        "notehead", fontName="DJ-B", fontSize=9.1, leading=13.4, textColor=colors.HexColor("#8a5a00"),
    ),
    "okhead": ParagraphStyle(
        "okhead", fontName="DJ-B", fontSize=9.1, leading=13.4, textColor=colors.HexColor("#2f5c2f"),
    ),
    "ok": ParagraphStyle(
        "ok", fontName="DJ", fontSize=9.1, leading=13.4, textColor=colors.HexColor("#2f5c2f"),
    ),
    "tblhead": ParagraphStyle(
        "tblhead", fontName="DJ-B", fontSize=8.8, leading=12, textColor=colors.white,
    ),
    "tbl": ParagraphStyle(
        "tbl", fontName="DJ", fontSize=8.8, leading=12.4, textColor=INK,
    ),
    "key": ParagraphStyle(
        "key", fontName="DJ", fontSize=8.8, leading=12.4, textColor=MUTED,
    ),
    "badge": ParagraphStyle(
        "badge", fontName="DJ-B", fontSize=12, leading=13,
        textColor=colors.white, alignment=TA_CENTER,
    ),
}


def path(text):
    """Menüpfad hervorheben."""
    return f'<font name="DJ-B" color="#1d5c8f">{text}</font>'


def code(text):
    """Technische Bezeichner."""
    return f'<font name="DJ-M" size="8.6" color="#8a3a3a">{text}</font>'


def step(number, title, subtitle="", keep=None):
    """Nummerierte Abschnittsüberschrift mit farbigem Badge.

    ``keep`` nimmt die Flowables auf, die zwingend unter der Überschrift stehen
    müssen — sonst bleibt die Überschrift allein am Seitenfuß zurück.
    """
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

    return [Spacer(1, 5), KeepTogether([row, Spacer(1, 5)] + list(keep or []))]


def bullets(items):
    """Aufzählung."""
    return ListFlowable(
        [ListItem(Paragraph(t, S["li"]), leftIndent=10) for t in items],
        bulletType="bullet", bulletChar="•", bulletFontName="DJ",
        bulletFontSize=8, leftIndent=11, bulletOffsetY=-1, spaceAfter=4,
    )


def box(head, text, bg, border, headstyle, textstyle):
    """Farbig abgesetzter Kasten."""
    inner = [Paragraph(head, headstyle), Paragraph(text, textstyle)]
    t = Table([[inner]], colWidths=[CONTENT_W])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), bg),
        ("LINEBEFORE", (0, 0), (0, -1), 2.4, border),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]))
    return [Spacer(1, 2), t, Spacer(1, 5)]


def note(head, text):
    """Hinweiskasten."""
    return box(head, text, WARN_BG, WARN_BORDER, S["notehead"], S["note"])


def expect(text):
    """Erwartetes Ergebnis."""
    return box("Das muss dastehen", text, OK_BG, OK_BORDER, S["okhead"], S["ok"])


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
        ("TOPPADDING", (0, 0), (-1, -1), 3.4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3.4),
        ("LINEBELOW", (0, 1), (-1, -1), 0.4, RULE),
        ("GRID", (0, 0), (-1, 0), 0, colors.white),
    ]))
    return [t, Spacer(1, 5)]


def form(pairs):
    """Formularblock: Feldbezeichnung links, einzutragender Wert rechts."""
    data = [[Paragraph(k, S["key"]), Paragraph(v, S["tbl"])] for k, v in pairs]

    t = Table(data, colWidths=[52 * mm, CONTENT_W - 52 * mm])
    t.setStyle(TableStyle([
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (0, -1), 8),
        ("LEFTPADDING", (1, 0), (1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("TOPPADDING", (0, 0), (-1, -1), 2.6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 2.6),
        ("LINEBEFORE", (0, 0), (0, -1), 2.0, RULE),
        ("LINEBELOW", (0, 0), (-1, -2), 0.4, colors.HexColor("#eceff2")),
    ]))
    # Ein Formularblock gehört zusammen — er darf nicht über zwei Seiten reißen.
    return [KeepTogether(t), Spacer(1, 5)]


def header_band(canvas, doc):
    """Kopfband auf Seite 1, schlanke Kopfzeile auf Folgeseiten."""
    canvas.saveState()

    if doc.page == 1:
        canvas.setFillColor(ACCENT)
        canvas.rect(0, PAGE_H - 44 * mm, PAGE_W, 44 * mm, stroke=0, fill=1)

        canvas.setFillColor(colors.white)
        canvas.setFont("DJ-B", 23)
        canvas.drawString(MARGIN, PAGE_H - 25 * mm, "Testlauf: Beitrag und Lastschrift")

        canvas.setFillColor(colors.HexColor("#bcd6ea"))
        canvas.setFont("DJ", 10.5)
        canvas.drawString(MARGIN, PAGE_H - 32.5 * mm,
                          "Mitgliederverwaltung Schwimmverein – WordPress-Plugin")
        canvas.drawString(MARGIN, PAGE_H - 38 * mm,
                          "Was anzulegen und einzustellen ist – in acht Schritten")
    else:
        canvas.setFillColor(MUTED)
        canvas.setFont("DJ", 7.6)
        canvas.drawString(MARGIN, PAGE_H - 12 * mm,
                          "Testlauf: Beitrag und Lastschrift · Mitgliederverwaltung Schwimmverein")
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
        title="Testlauf: Beitrag und Lastschrift – Mitgliederverwaltung Schwimmverein",
        author="Mitgliederverwaltung Schwimmverein",
        subject="Kurzanleitung für einen vollständigen Probedurchlauf",
    )

    first = Frame(MARGIN, 18 * mm, CONTENT_W, PAGE_H - 44 * mm - 18 * mm - 6 * mm, id="first")
    later = Frame(MARGIN, 18 * mm, CONTENT_W, PAGE_H - MARGIN - 18 * mm - 4 * mm, id="later")

    doc.addPageTemplates([
        PageTemplate(id="first", frames=[first], onPage=header_band),
        PageTemplate(id="later", frames=[later], onPage=header_band),
    ])

    story = [NextPageTemplate("later")]

    story.append(Paragraph(
        "Ein vollständiger Probedurchlauf mit den fünf Testmitgliedern: jedes zahlt einen "
        "Jahresbeitrag von 60&nbsp;&euro;, daraus entsteht eine SEPA-Lastschriftdatei. "
        "Die Schritte bauen aufeinander auf &ndash; halten Sie die Reihenfolge ein.",
        S["lead"]))

    story += table(
        ["Testmitglied", "Zahlart", "Beitrag", "in der SEPA-Datei"],
        [
            ["Katrin Brandner", "SEPA-Lastschrift", "60,00 &euro;", "ja"],
            ["Jonas Brandner", "SEPA-Lastschrift", "60,00 &euro;", "ja"],
            ["Mia Brandner", "SEPA-Lastschrift", "60,00 &euro;", "ja"],
            ["Emil Brandner", "SEPA-Lastschrift", "60,00 &euro;", "ja"],
            ["Hendrik Vo&szlig;kamp", "&Uuml;berweisung", "60,00 &euro;", "<b>nein</b>"],
        ],
        [46 * mm, 38 * mm, 24 * mm, CONTENT_W - 108 * mm],
    )

    story.append(Paragraph(
        "Ergebnis am Ende: <b>Beitragslauf 5 Forderungen &uuml;ber 300,00&nbsp;&euro;</b>, "
        "<b>Lastschriftdatei 4 Buchungen &uuml;ber 240,00&nbsp;&euro;</b>. Hendrik zahlt per "
        "&Uuml;berweisung und geh&ouml;rt deshalb nie in eine Lastschriftdatei.",
        S["body"]))

    # --- Schritt 1 ---
    story += step(1, "Feld freiräumen", "Beiträge → Beitragsarten und → Rabatte", [
        Paragraph(
            "Das Plugin bringt eine Startkonfiguration mit, die sonst mitrechnet. Bei diesen vier "
            "Beitragsarten jeweils den Haken <b>Beitrag wird berechnet</b> entfernen und speichern "
            "&ndash; nicht l&ouml;schen, Sie brauchen sie sp&auml;ter wieder:",
            S["body"]),
    ])
    story.append(bullets([
        "Grundbeitrag Erwachsene (120&nbsp;&euro;) &middot; Grundbeitrag Jugend (72&nbsp;&euro;)",
        "Spartenbeitrag Wasserball (60&nbsp;&euro;) &middot; Aufnahmegeb&uuml;hr (25&nbsp;&euro;)",
    ]))
    story.append(Paragraph(
        f"Danach unter {path('Beiträge → Rabatte')} die Regel "
        "&bdquo;Beitragsfrei ab dem 3.&nbsp;Kind&ldquo; &ouml;ffnen und den Haken "
        "<b>Regel anwenden</b> entfernen.",
        S["body"]))
    story += note(
        "Warum die Regel weg muss",
        "Emil ist das dritte Kind der Familie Brandner. Bleibt die Regel aktiv, bekommt er "
        "100&nbsp;% Nachlass, steht mit 0,00&nbsp;&euro; in der Vorschau und fehlt in der "
        "Lastschriftdatei &ndash; die Probe geht dann nicht auf.")

    # --- Schritt 2 ---
    story += step(2, "Beitragsart anlegen", "Beiträge → Neue Beitragsart", form([
        ("Bezeichnung", "Jahresbeitrag Test"),
        ("Betrag", code("60")),
        ("Betragsermittlung", "Fester Betrag"),
        ("Betragsfeld", "&mdash; kein Feld &mdash;"),
        ("Turnus", "j&auml;hrlich"),
        ("F&auml;llig", code("01.03.")),
        ("Bei unterj&auml;hrigem Eintritt", "keine (voller Betrag)"),
        ("G&uuml;ltig ab / G&uuml;ltig bis", "beide leer lassen"),
        ("Beitrag wird berechnet", "angehakt"),
        ("F&uuml;r wen gilt dieser Beitrag?", "<b>keine</b> Bedingung hinzuf&uuml;gen"),
    ]))
    story.append(Paragraph(
        "Ohne Bedingung zahlen den Beitrag alle beitragspflichtigen Mitglieder. In der "
        "&Uuml;bersicht steht dann in der Spalte <b>Gilt f&uuml;r</b> der Eintrag "
        "&bdquo;alle Mitglieder&ldquo;.",
        S["body"]))

    # --- Schritt 3 ---
    story += step(3, "Mitglieder einstellen", "Mitglieder → Mitglied öffnen", [
        Paragraph(
            "Im Reiter <b>Stammdaten</b> die Zahlart setzen, im Reiter <b>Bankverbindung</b> das "
            "Mandat erfassen:",
            S["body"]),
    ])
    story += table(
        ["Mitglied", "Zahlart", "Mandat", "Kontoinhaber im Mandat"],
        [
            ["Katrin", "SEPA-Lastschrift", "ja", "leer lassen"],
            ["Jonas", "SEPA-Lastschrift", "ja", "Katrin Brandner"],
            ["Mia", "SEPA-Lastschrift", "ja", "Katrin Brandner"],
            ["Emil", "SEPA-Lastschrift", "ja", "Katrin Brandner"],
            ["Hendrik", "&Uuml;berweisung", "<b>nein</b>", "&ndash;"],
        ],
        [26 * mm, 38 * mm, 20 * mm, CONTENT_W - 84 * mm],
    )
    story.append(Paragraph("In jedem der vier Mandate:", S["body"]))
    story += form([
        ("IBAN", code("DE28888888881000100200")),
        ("BIC", code("TESTDEFFXXX")),
        ("Unterschrieben am", "beliebiges Datum in der Vergangenheit"),
        ("Art", "FRST &mdash; Erstlastschrift"),
    ])
    story.append(Paragraph(
        "Ein fehlendes Mandat bricht sp&auml;ter nichts ab &ndash; das Mitglied wird nur mit einer "
        "Warnung &uuml;bersprungen und seine Forderung bleibt offen.",
        S["body"]))

    # --- Schritt 4 ---
    story += step(4, "Bankprofil anlegen", "Zahlungen → Bankdaten des Vereins", form([
        ("Bezeichnung", "Vereinskonto"),
        ("Verfahren", "SEPA-Lastschrift (pain.008.001.02)"),
        ("Name des Vereins", "Ihr Vereinsname"),
        ("IBAN des Vereinskontos", code("DE26888888884711000815")),
        ("BIC", code("TESTDEFFXXX")),
        ("Gl&auml;ubiger-ID", code("DE32ZZZ00000123456")),
        ("Verwendungszweck", code("Beitrag {zeitraum} {mitgliedsnummer}")),
        ("Vorlauf Erst- / Folgelastschrift", code("5") + " / " + code("2") + " Banktage"),
        ("Profil verwenden", "angehakt"),
    ]))
    story += expect(
        "In der Liste oben muss das Profil den Status <b>einsatzbereit</b> tragen. Steht dort "
        "<b>unvollst&auml;ndig</b>, fehlt die IBAN oder die Gl&auml;ubiger-ID.")

    # --- Schritt 5 ---
    story += step(5, "Beiträge berechnen", "Beiträge → Beiträge berechnen", [
        Paragraph(
            f"Zeitraum {code('01.01.2026')} bis {code('31.12.2026')}, Sparte &bdquo;Alle "
            "Sparten&ldquo;, dann <b>Vorschau</b>. Diese Ansicht rechnet nur &ndash; sie speichert "
            "nichts und l&auml;sst sich beliebig oft wiederholen.",
            S["body"]),
    ])
    story += expect(
        "Mitglieder <b>5</b> &nbsp;&middot;&nbsp; Neue Forderungen <b>5</b> &nbsp;&middot;&nbsp; "
        "Gesamtbetrag <b>300,00&nbsp;&euro;</b>")
    story.append(Paragraph(
        "Klappen Sie in der Tabelle bei einem Mitglied <b>Berechnung</b> auf. Dort m&uuml;ssen "
        "genau zwei Zeilen stehen: &bdquo;Beitragsart ohne Einschr&auml;nkung&ldquo; und "
        "&bdquo;Grundbetrag Jahresbeitrag Test: 60,00&nbsp;&euro;&ldquo;. Steht bei Emil noch ein "
        "Rabatthinweis, ist Schritt&nbsp;1 nicht vollst&auml;ndig erledigt.",
        S["body"]))
    story.append(Paragraph(
        "Stimmt das Ergebnis: Bezeichnung <b>Test 2026</b> eintragen und "
        "<b>Forderungen verbindlich erzeugen</b>. Unter "
        f"{path('Beiträge → Offene Posten')} stehen danach f&uuml;nf Forderungen &agrave; "
        "60,00&nbsp;&euro;, f&auml;llig am 01.03.2026.",
        S["body"]))

    # --- Schritt 6 ---
    story += step(6, "Lastschriftdatei erzeugen", "Zahlungen → Lastschrift", form([
        ("Bankprofil", "Vereinskonto"),
        ("Modus", "Jahreseinzug &mdash; ganzes Jahr"),
        ("Jahr", code("2026")),
        ("B&uuml;ndelung", "eine Buchung je Mitglied"),
    ]))
    story += expect(
        "Mitglieder <b>4</b> &nbsp;&middot;&nbsp; Buchungen in der Datei <b>4</b> &nbsp;&middot;&nbsp; "
        "Summe <b>240,00&nbsp;&euro;</b> &nbsp;&middot;&nbsp; Fr&uuml;hester Einzug: vorbelegt")
    story.append(Paragraph(
        "Hendrik taucht nicht auf &ndash; seine Zahlart erzeugt keine Datei. In der Tabelle stehen "
        "vier Zeilen mit maskierter IBAN, Mandatsreferenz und dem Zusatz <b>FRST</b>.",
        S["body"]))
    story.append(Paragraph(
        "Dann Bezeichnung <b>Jahreseinzug 2026</b> eintragen, das vorgeschlagene Einzugsdatum "
        "&uuml;bernehmen und <b>Lastschriftdatei erzeugen</b>. Unter <b>Bisherige Einz&uuml;ge</b> "
        "l&auml;dt <b>Datei laden</b> die fertige XML herunter.",
        S["body"]))
    story.append(Paragraph("Zur Kontrolle in der Datei:", S["body"]))
    story.append(bullets([
        code("&lt;NbOfTxs&gt;4&lt;/NbOfTxs&gt;") + " und " + code("&lt;CtrlSum&gt;240.00&lt;/CtrlSum&gt;"),
        "vier Bl&ouml;cke " + code("&lt;DrctDbtTxInf&gt;") + " mit je "
        + code("60.00") + " Euro",
        code("&lt;SeqTp&gt;FRST&lt;/SeqTp&gt;") + " und Ihre Gl&auml;ubiger-ID unter "
        + code("&lt;CdtrSchmeId&gt;"),
        "bei den Kindern " + code("&lt;Dbtr&gt;&lt;Nm&gt;Katrin Brandner")
        + " &ndash; der Kontoinhaber, nicht das Kind",
    ]))

    # --- Schritt 7 ---
    story += step(7, "Nach dem Einzug", "Zahlungen", [
        bullets([
            "Datei im Online-Banking hochladen, dann den Lauf auf "
            "<b>bei der Bank eingereicht</b> setzen.",
            "Sobald die Bank ausgef&uuml;hrt hat, auf <b>ausgef&uuml;hrt (Zahlungen gebucht)</b> "
            "setzen &ndash; das System bucht die vier Zahlungen automatisch und gleicht die "
            "Forderungen aus.",
            "Hendriks 60&nbsp;&euro; erfassen Sie von Hand unter "
            + path("Zahlungen → Zahlung eintragen") + ".",
        ]),
    ])

    # --- Schritt 8 ---
    story += step(8, "Testdaten wieder entfernen", "rückwärts, in dieser Reihenfolge")
    story.append(bullets([
        "Zahllauf auf <b>storniert</b> setzen &ndash; danach erscheint der Knopf <b>L&ouml;schen</b>.",
        "Beitragslauf unter <b>Bisherige Berechnungen</b> mit <b>Zur&uuml;cknehmen</b> aufheben; "
        "das l&ouml;scht alle f&uuml;nf Forderungen mit.",
        "Beitragsart &bdquo;Jahresbeitrag Test&ldquo; l&ouml;schen.",
        "Die vier Standard-Beitragsarten und die Regel &bdquo;Beitragsfrei ab dem 3.&nbsp;Kind&ldquo; "
        "wieder aktivieren.",
    ]))
    story.append(Paragraph(
        "Zur&uuml;cknehmen geht nur, solange auf keine Forderung des Laufs gezahlt wurde &ndash; "
        "sonst zuerst die gebuchten Zahlungen im Journal l&ouml;schen.",
        S["body"]))

    # --- Abschluss ---
    closing = [Paragraph("Wenn etwas fehlt", S["h2"])]
    closing += table(
        ["Symptom", "Ursache"],
        [
            ["Vorschau zeigt mehr als 300,00&nbsp;&euro;",
             "Eine der vier Standard-Beitragsarten ist noch aktiv (Schritt&nbsp;1)."],
            ["Ein Mitglied steht mit 0,00&nbsp;&euro; da",
             "Die Regel &bdquo;Beitragsfrei ab dem 3.&nbsp;Kind&ldquo; ist noch aktiv."],
            ["Vorschau bleibt leer",
             "Keine Beitragsart im Zeitraum g&uuml;ltig, oder alle Forderungen bestehen schon."],
            ["Lastschrift meldet &bdquo;kein Bankprofil&ldquo;",
             "Bankprofil fehlt oder ist nicht auf <b>Profil verwenden</b> gesetzt."],
            ["Mitglied fehlt im Einzug",
             "Kein aktives Mandat, ung&uuml;ltige IBAN, fehlendes Unterschriftsdatum &ndash; "
             "oder die Zahlart ist nicht SEPA-Lastschrift."],
            ["Zweiter Lauf zieht nichts ein",
             "Richtig so: Forderungen aus einem bestehenden Zahllauf werden nicht doppelt "
             "eingezogen."],
        ],
        [58 * mm, CONTENT_W - 58 * mm],
    )

    closing.append(Paragraph("Vom Test zum Echtbetrieb", S["h2"]))
    closing.append(Paragraph(
        "Der Ablauf bleibt derselbe &ndash; nur die Daten wechseln. Was Sie ersetzen m&uuml;ssen:",
        S["body"]))
    closing.append(bullets([
        "<b>Bankprofil</b> &ndash; echte Vereins-IBAN und die Gl&auml;ubiger-Identifikationsnummer, "
        "die die Deutsche Bundesbank Ihrem Verein zugeteilt hat.",
        "<b>Beitragsarten</b> &ndash; die vier Standardarten wieder aktivieren und auf Ihre "
        "tats&auml;chlichen Beitr&auml;ge und Bedingungen anpassen.",
        "<b>Mandate</b> &ndash; jedes Mandat braucht eine unterschriebene Vorlage des Mitglieds. "
        "Das Unterschriftsdatum im System muss dem Papier entsprechen.",
        "<b>Sequenztyp</b> &ndash; beim ersten echten Einzug steht FRST, danach schaltet das System "
        "das Mandat selbst&auml;ndig auf RCUR um.",
    ]))
    closing += note(
        "Vor dem ersten echten Lauf",
        "Vergleichen Sie die Summe der Vorschau stichprobenartig mit den Vorjahreswerten. Ein "
        "Konfigurationsfehler f&auml;llt in der Vorschau auf &ndash; nach dem Einzug wird er zur "
        "R&uuml;ckbuchung.")

    # Die Fehlertabelle bleibt zusammen — halb umgebrochen nützt sie niemandem.
    story.append(KeepTogether(closing))

    doc.build(story)


if __name__ == "__main__":
    import sys
    build(sys.argv[1] if len(sys.argv) > 1 else "testlauf.pdf")
    print("PDF erstellt.")
