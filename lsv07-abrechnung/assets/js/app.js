/* global LSV07A, jQuery */
(function ($) {
'use strict';

/* ════════════════════════════════════════════════════════════════════
   LSV07 Abrechnung
   Eine Seite, mehrere Bereiche. Gerechnet wird auf dem Server — hier
   wird nur dargestellt und erfasst.
   ════════════════════════════════════════════════════════════════════ */

var Z = (window.LSV07A && LSV07A.zugang) || { tabs: {}, rollen: [] };
var A = { abr: null, quartal: LSV07A.quartal, jahr: LSV07A.jahr, angebot: [], stDrin: 'eigene' };

// ── Grundlagen ──────────────────────────────────────────────────────

function esc(s) { return $('<span>').text(s === null || s === undefined ? '' : s).html(); }

function eur(n) {
  var w = parseFloat(n) || 0;
  return w.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}
function zahl(n, stellen) {
  var w = parseFloat(n) || 0;
  return w.toLocaleString('de-DE', { minimumFractionDigits: stellen === undefined ? 2 : stellen,
                                     maximumFractionDigits: stellen === undefined ? 2 : stellen });
}
function de(d) {
  if (!d) return '';
  var t = String(d).slice(0, 10).split('-');
  return t.length === 3 ? (t[2] + '.' + t[1] + '.' + t[0]) : d;
}
function deKurz(d) {
  if (!d) return '';
  var t = String(d).slice(0, 10).split('-');
  return t.length === 3 ? (t[2] + '.' + t[1] + '.') : d;
}
function deZeit(d) {
  if (!d) return '';
  var s = String(d);
  return de(s) + (s.length > 10 ? (', ' + s.slice(11, 16)) : '');
}
function heute() {
  var d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
       + '-' + String(d.getDate()).padStart(2, '0');
}

/* ── Ein Knopf, der gerade arbeitet ───────────────────────────────────
   EINE Stelle für „bitte warten": gesperrt, mit Kreisel, und für
   Vorleseprogramme als beschäftigt gekennzeichnet. Vorher stand an
   zwanzig Stellen dieselbe Zeile aus prop() und text() — und nirgends
   bewegte sich etwas, sodass ein langsamer Server aussah wie ein Knopf,
   der nicht reagiert.

   frei() nimmt alles wieder zurück. Mit Text setzt es die Beschriftung,
   ohne lässt es sie stehen. */
$.fn.beschaeftigt = function (text) {
  this.prop('disabled', true).addClass('ist-beschaeftigt').attr('aria-busy', 'true');
  return text === undefined ? this : this.text(text);
};
$.fn.frei = function (text) {
  this.prop('disabled', false).removeClass('ist-beschaeftigt').removeAttr('aria-busy');
  return text === undefined ? this : this.text(text);
};

var _toastZeit;
function toast(text, art) {
  var $t = $('#a-toast');
  $t.removeClass('zeig ist-fehler ist-gut');
  if (art) $t.addClass('ist-' + art);
  $t.text(text);
  requestAnimationFrame(function () { $t.addClass('zeig'); });
  clearTimeout(_toastZeit);
  /* Eine Fehlermeldung bleibt so lange, wie man zum Lesen braucht: Die
     ausführlichen Meldungen sind zwei, drei Sätze lang, und sechs
     Sekunden reichen dafür nicht. Rund 15 Zeichen je Sekunde, höchstens
     20 Sekunden. */
  var dauer = art === 'fehler'
    ? Math.min(20000, Math.max(6000, String(text || '').length * 65))
    : 3500;
  _toastZeit = setTimeout(function () { $t.removeClass('zeig'); }, dauer);
}

/* ── Ladebalken ──────────────────────────────────────────────────────
   Drei Regeln:
   - Er startet SOFORT. Vorher wartete er 150 ms — auf einem schnellen
     Server war fast jede Anfrage vorher fertig, und man sah nie einen.
   - Damit er trotzdem nicht aufblitzt, steht er mindestens 350 ms und
     läuft dann sichtbar auf 100 % durch, bevor er ausblendet. Eine ganze
     Bewegung, kein Zucken.
   - Er zählt mit. Laufen zwei Anfragen, verschwindet er erst, wenn BEIDE
     fertig sind — vorher nahm ihn die erste mit, während die zweite noch
     lief. */
var LADE_MINDESTENS = 350;
var _ladeZeit, _ladeWarte, _ladeOffen = 0, _ladeSeit = 0;
function ladenAn() {
  _ladeOffen++;
  if (_ladeOffen > 1) return;
  clearTimeout(_ladeZeit);
  clearTimeout(_ladeWarte);
  var $b = $('#a-ladebalken');
  /* Läuft gerade noch das Ausblenden der letzten Anfrage, von vorn
     beginnen: Klassen weg, Neuaufbau erzwingen, dann wieder los. */
  $b.removeClass('laeuft fertig');
  void ($b[0] && $b[0].offsetWidth);
  $b.addClass('laeuft');
  _ladeSeit = Date.now();
}
function ladenAus() {
  _ladeOffen = Math.max(0, _ladeOffen - 1);
  if (_ladeOffen > 0) return;
  var rest = Math.max(0, LADE_MINDESTENS - (Date.now() - _ladeSeit));
  clearTimeout(_ladeWarte);
  _ladeWarte = setTimeout(function () {
    if (_ladeOffen > 0) return;             // inzwischen läuft schon die nächste
    var $b = $('#a-ladebalken');
    $b.removeClass('laeuft').addClass('fertig');
    _ladeZeit = setTimeout(function () { $b.removeClass('fertig'); }, 650);
  }, rest);
}

/* Fremde Ausgabe vor der Antwort herausschneiden. Schreibt irgendein
   Plugin eine Warnung in die Ausgabe, steht sie VOR dem JSON und der
   Browser kann die Antwort nicht mehr lesen — sichtbar bliebe nur ein
   nichtssagender Fehler. */
function jsonRetten(text) {
  if (typeof text !== 'string') return null;
  var i = text.indexOf('{"success"');
  if (i < 0) return null;
  var rest = text.slice(i);
  try { JSON.parse(rest); } catch (e) { return null; }
  if (i > 0 && window.console && console.warn) {
    console.warn('LSV07A: Vor der Serverantwort stand fremde Ausgabe — ' + text.slice(0, i));
  }
  return rest;
}

/* ── Fehlertexte ─────────────────────────────────────────────────────
   EINE Stelle, die aus einer fehlgeschlagenen Anfrage einen Satz macht.

   Jede Meldung beantwortet zwei Fragen: WAS ist passiert, und WAS KANN
   ICH TUN. „Ein Fehler ist aufgetreten" beantwortet keine davon — wer
   das liest, weiss nicht, ob er es noch einmal versuchen, die Seite neu
   laden oder jemanden anrufen soll.

   Hat der Server selbst einen Grund genannt, gilt immer der: Er weiss,
   was nicht stimmt. Die Texte hier sind für die Fälle, in denen er gar
   nicht erst zu Wort kam. */
function fehlerText(xhr) {
  var status = xhr ? (xhr.status || 0) : 0;
  var roh = xhr && typeof xhr.responseText === 'string' ? xhr.responseText : '';

  // 1. Der Server hat einen Grund genannt.
  try {
    var r = JSON.parse(jsonRetten(roh) || roh);
    if (r && r.data && r.data.message) return String(r.data.message);
  } catch (e) {}

  // 2. Die Anfrage kam nie an oder nie zurück.
  if (xhr && xhr.statusText === 'timeout') {
    return 'Der Server hat nicht rechtzeitig geantwortet. Ob der Vorgang noch '
         + 'durchgegangen ist, steht nicht fest — bitte die Seite neu laden und '
         + 'nachsehen, bevor Sie es noch einmal versuchen.';
  }
  if (status === 0) {
    return (window.navigator && navigator.onLine === false)
      ? 'Keine Internetverbindung. Es wurde nichts gespeichert — bitte die '
        + 'Verbindung prüfen und es dann noch einmal versuchen.'
      : 'Der Server ist nicht erreichbar. Es wurde nichts gespeichert — bitte '
        + 'die Verbindung prüfen und es in einem Moment noch einmal versuchen.';
  }

  // 3. Der Server hat geantwortet, aber ohne Grund.
  var kurz = roh.replace(/\s+/g, '');
  if (status === 400 && (kurz === '0' || kurz === '-1' || kurz === '')) {
    return 'Sie sind nicht mehr angemeldet, oder die Sitzung ist abgelaufen. '
         + 'Bitte die Seite neu laden und erneut anmelden; nicht gespeicherte '
         + 'Eingaben gehen dabei verloren.';
  }
  if (status === 401 || status === 403) {
    return 'Der Server hat den Vorgang abgewiesen (' + status + '): Die Sitzung ist '
         + 'abgelaufen oder Ihrem Konto fehlt die Berechtigung. Bitte die Seite neu '
         + 'laden; bleibt es dabei, die Administration fragen.';
  }
  if (status === 404) {
    return 'Der Server kennt diese Adresse nicht (404). Bitte die Seite neu laden; '
         + 'bleibt es dabei, ist das Plugin nicht richtig eingerichtet — bitte die '
         + 'Administration verständigen.';
  }
  if (status === 413) {
    return 'Die Datei ist zu gross für diesen Server (413). Bitte eine kleinere '
         + 'Datei wählen — ein Foto in geringerer Auflösung oder ein PDF mit weniger '
         + 'Seiten.';
  }
  if (status === 429) {
    return 'Zu viele Anfragen in kurzer Zeit (429). Bitte einen Moment warten und '
         + 'es dann noch einmal versuchen.';
  }
  if (status === 502 || status === 503 || status === 504) {
    return 'Der Server ist gerade überlastet oder wird gewartet (' + status + '). '
         + 'Es wurde nichts gespeichert — bitte in ein paar Minuten noch einmal '
         + 'versuchen.';
  }
  if (status >= 500) {
    return 'Auf dem Server ist ein Fehler aufgetreten (' + status + '). Das liegt '
         + 'nicht an Ihrer Eingabe. Bitte noch einmal versuchen; bleibt es dabei, '
         + 'die Administration verständigen und die Uhrzeit nennen.';
  }
  if (status >= 200 && status < 300) {
    return 'Der Server hat geantwortet, aber nicht in lesbarer Form. Ob der Vorgang '
         + 'durchgegangen ist, steht nicht fest — bitte die Seite neu laden und '
         + 'nachsehen.';
  }
  return 'Der Vorgang ist fehlgeschlagen' + (status ? ' (' + status + ')' : '')
       + '. Bitte die Seite neu laden und es noch einmal versuchen.';
}

/* Der Grund aus einer Antwort, die ankam, aber „nein" sagt. */
function absageText(r) {
  if (r && r.data && r.data.message) return String(r.data.message);
  return 'Der Server hat den Vorgang abgelehnt, ohne einen Grund zu nennen. '
       + 'Bitte die Eingaben prüfen und es noch einmal versuchen.';
}

/* Verlässt jemand die Seite, bricht der Browser laufende Anfragen ab.
   Das ist kein Fehler und soll nicht als „Server nicht erreichbar"
   aufblitzen, während die neue Seite schon lädt. */
var SEITE_GEHT = false;
$(window).on('beforeunload pagehide', function () { SEITE_GEHT = true; });

/* ── Anfragen ────────────────────────────────────────────────────────
   Jede Anfrage meldet einen Fehlschlag VON SICH AUS, und zwar beide
   Arten:

   - Die Anfrage scheitert (kein Netz, 403, 500 …): fehlerText().
   - Die Anfrage kommt an, aber der Server sagt „nein" und nennt den
     Grund (success: false). Das ist der häufige Fall — eine fehlende
     Angabe, ein Datum ausserhalb des Quartals —, und genau der ging
     vorher unter: Der Server antwortet dabei mit Status 200, und die
     meisten Aufrufer taten bei „nein" einfach nichts. Der Knopf sprang
     zurück, der Grund blieb ungelesen.

   Wer eine genauere Meldung hat, zeigt sie im eigenen .done() oder
   .fail() — sie ersetzt dann die allgemeine. Wer gar keine will (eine
   Abfrage im Hintergrund), ruft mit { stumm: true }. */
function ajax(aktion, daten, optionen) {
  /* „still" ist die ältere Schreibweise desselben Wunsches (der Takt der
     Mitteilungen ruft so): Eine Abfrage, die alle zwei Minuten im
     Hintergrund läuft, soll bei einem Aussetzer nicht jedes Mal eine
     Meldung aufwerfen. */
  var stumm = !!(optionen && (optionen.stumm || optionen.still));
  ladenAn();
  var xhr = $.ajax({
    url: LSV07A.ajax_url,
    type: 'POST',
    data: $.extend({ action: aktion, nonce: LSV07A.nonce }, daten || {}),
    /* Ausdrücklich JSON, egal was der Server im Kopf behauptet: Schreibt
       ein anderes Plugin eine Notiz VOR die Antwort, sind die Kopfzeilen
       schon fort und die Antwort kommt als „text/html" — jQuery reichte
       sie dann als blossen Text weiter, und jeder Aufrufer hielt das für
       eine Absage ohne Grund. */
    dataType: 'json',
    dataFilter: function (d) { return jsonRetten(d) || d; }
  });
  xhr.always(ladenAus);

  /* Die Platzhalter, die auf DIESE Anfrage warten. Das Muster ist überall
     dasselbe: erst den Platzhalter setzen, dann fragen — also gehört
     jeder sichtbare, noch nicht vergebene Platzhalter zur nächsten
     Anfrage. Scheitert sie, darf er nicht ewig weiter „laden": Eine
     Ladeanzeige, hinter der nichts mehr kommt, ist die schlechteste
     aller Auskünfte. */
  /* Welche das sind: jeder noch nicht vergebene Platzhalter, den das
     Skript gesetzt hat — auch in einem gerade verdeckten Bereich (die
     Prüfliste lädt schon beim Start, für die Zahl am Reiter). Die
     Platzhalter aus der Vorlage stehen dagegen von Anfang an in JEDEM
     Bereich; von ihnen gehört nur der sichtbare zu dieser Anfrage. */
  var $platz = stumm ? $() : $('.a-laden').filter(function () {
    return !this._vergeben && (!this._vorlage || $(this).is(':visible'));
  });
  $platz.each(function () { this._vergeben = true; });
  /* Gelingt die Anfrage und der Platzhalter steht trotzdem noch, war er
     nicht ihrer — dann ist er wieder frei für die, die ihn füllt. */
  xhr.done(function (r) {
    if (r && r.success !== false) $platz.each(function () { this._vergeben = false; });
  });
  /* Der Grund steht AUCH im Platzhalter, nicht nur in der Meldung unten:
     Die Meldung geht nach ein paar Sekunden wieder, die leere Stelle
     bleibt — und wer erst dann hinsieht, soll trotzdem lesen können,
     warum dort nichts ist. */
  function platzFehler(grund) {
    $platz.filter(function () { return $.contains(document.documentElement, this); })
      .removeClass('a-laden').addClass('a-lade-fehler').attr('role', 'alert')
      .html('<span class="a-lade-fehler-txt">' + esc(grund) + '</span>'
          + '<button type="button" class="a-btn a-btn-klein a-neu-laden">Seite neu laden</button>');
  }

  // Der Server sagt „nein": den Grund zeigen.
  xhr.done(function (r) {
    if (r && r.success !== false) return;
    var grund = absageText(r);
    platzFehler(grund);
    if (!stumm) toast(grund, 'fehler');
  });

  /* Die Anfrage selbst scheitert. Diese Meldung kommt IMMER (ausser bei
     „stumm") und als ERSTE: Sie ist angemeldet, bevor der Aufrufer sein
     eigenes .fail() anhängen kann. Zeigt der Aufrufer dort selbst eine
     Meldung, ersetzt sie diese hier — es gibt nur eine Meldungszeile.
     Zeigt er keine (er setzt nur einen Knopf zurück), bleibt diese
     stehen. So gibt es weder zwei Meldungen noch gar keine.

     Vorher schwieg diese Stelle, sobald ein Aufrufer .fail() benutzte —
     und vier von ihnen setzten dort nur ein Häkchen zurück. */
  xhr.fail(function (x, status) {
    if (status === 'abort' || SEITE_GEHT) return;
    var grund = fehlerText(x);
    platzFehler(grund);
    if (!stumm) toast(grund, 'fehler');
  });
  return xhr;
}

/* Die Platzhalter, die schon in der Vorlage stehen, als solche merken —
   siehe oben in ajax(). */
$('.a-laden').each(function () { this._vorlage = true; });

/* Die Adresszeile nennt den Bereich — nach dem Neuladen steht man wieder
   dort, wo man war. */
$(document).on('click', '.a-neu-laden', function () { window.location.reload(); });

// ── Navigation ──────────────────────────────────────────────────────

function zeige(ziel) {
  $('.a-nb').removeClass('on').filter('[data-ziel="' + ziel + '"]').addClass('on');
  $('.a-seite').removeClass('on');
  $('#s-' + ziel).addClass('on');
  $('#a-root').removeClass('menue-auf');
  $('#a-menue').attr('aria-expanded', 'false');
  $('#a-nav-schatten').prop('hidden', true);
  $('#a-body').scrollTop(0);
  try { window.location.hash = ziel; } catch (e) {}
  laden(ziel);
}

/* Zwei Bereiche tragen je zwei Unterbereiche. Damit eine Mitteilung
   weiterhin an die richtige Stelle führt („rechnungen", „genehmigung",
   „eigene", „pruefung"), steht hier, wo was liegt. */
var UNTER = {
  eigene:      { nav: 'abrechnung', reiter: 'ab-reiter', attr: 'ab', teil: 'ab-teil-', klasse: 'a-abteil' },
  rechnungen:  { nav: 'abrechnung', reiter: 'ab-reiter', attr: 'ab', teil: 'ab-teil-', klasse: 'a-abteil' },
  pruefung:    { nav: 'pruefen',    reiter: 'pr-reiter', attr: 'pr', teil: 'pr-teil-', klasse: 'a-prteil' },
  genehmigung: { nav: 'pruefen',    reiter: 'pr-reiter', attr: 'pr', teil: 'pr-teil-', klasse: 'a-prteil' }
};

/**
 * Einen Unterbereich öffnen — und den Bereich, in dem er liegt.
 *
 * Hier liegt der EINZIGE Weg dorthin: Der Umschalter ruft dieselbe
 * Funktion wie eine Mitteilung und wie die Adresszeile. Zwei Wege, die
 * dasselbe tun, laufen irgendwann auseinander — und der eine, den keine
 * Prüfung begeht, zuerst.
 */
function zeigeUnter(name) {
  var u = UNTER[name];
  if (!u) { zeige(name); return; }
  if (!$('#' + u.teil + name).length) return;   // diese Rolle hat ihn nicht
  zeige(u.nav);
  $('#' + u.reiter + ' button').removeClass('on')
    .filter('[data-' + u.attr + '="' + name + '"]').addClass('on');
  $('.' + u.klasse).removeClass('on');
  $('#' + u.teil + name).addClass('on');
  $('#a-body').scrollTop(0);
  /* Die Adresszeile nennt den Unterbereich, nicht nur den Bereich: Beim
     Neuladen steht man dann wieder da, wo man war. */
  try { window.location.hash = name; } catch (e) {}
  laden(name);
}

var geladen = {};
function laden(ziel, erzwingen) {
  if (geladen[ziel] && !erzwingen) return;
  geladen[ziel] = true;
  /* Ein zusammengelegter Bereich lädt beide Teile: Der Umschalter soll
     nicht auf eine leere Liste zeigen. */
  if (ziel === 'abrechnung') {
    if ($('#ab-teil-eigene').length)     { abrLaden(); meineListe(); }
    if ($('#ab-teil-rechnungen').length) { rLaden(); }
  }
  else if (ziel === 'pruefen') {
    if ($('#pr-teil-pruefung').length)    { pruefListe(); }
    if ($('#pr-teil-genehmigung').length) { gLaden(); }
  }
  else if (ziel === 'eigene')      { abrLaden(); meineListe(); }
  else if (ziel === 'pruefung')    { pruefListe(); }
  else if (ziel === 'rechnungen')  { rLaden(); }
  else if (ziel === 'genehmigung') { gLaden(); }
  else if (ziel === 'archiv')      { arLaden(0); }
  else if (ziel === 'kasse')    { kasseListe(); }
  else if (ziel === 'statistik'){ statLaden(); }
  else if (ziel === 'zahlungsdaten') { zdLaden(); }
  else if (ziel === 'verwaltung')    { vKonten(); }
}

$(document).on('click', '#ab-reiter button', function () { zeigeUnter($(this).data('ab')); });
$(document).on('click', '#pr-reiter button', function () { zeigeUnter($(this).data('pr')); });

$(document).on('click', '.a-nb', function () { zeige($(this).data('ziel')); });
$('#a-menue').on('click', function () {
  var auf = !$('#a-root').hasClass('menue-auf');
  $('#a-root').toggleClass('menue-auf', auf);
  $(this).attr('aria-expanded', auf ? 'true' : 'false');
  $('#a-nav-schatten').prop('hidden', !auf);
});
$('#a-nav-schatten').on('click', function () {
  $('#a-root').removeClass('menue-auf');
  $('#a-menue').attr('aria-expanded', 'false');
  $(this).prop('hidden', true);
});

// ── Dialoge ─────────────────────────────────────────────────────────

/* Ein Dialog geht nur zu, wenn jemand das ausdrücklich will: über das
   Kreuz, über Abbrechen oder Schließen, oder mit Escape.

   Ein Klick auf die Fläche daneben schliesst ihn NICHT. Das wäre bequem,
   geht aber regelmässig daneben: Wer einen Betrag im Dialog markiert und
   die Maus dabei über den Rand hinauszieht, lässt sie ausserhalb los —
   der Browser meldet den Klick dann auf dem gemeinsamen Elternelement,
   also auf der Fläche. Der Dialog wäre zu und das Eingetippte weg, ohne
   dass jemand etwas angeklickt hätte.

   Jeder Dialog trägt ein Kreuz in der Kopfzeile; es führt also immer ein
   sichtbarer Weg hinaus. Geprüft wird das maschinell. */
/* Welcher Dialog über welchem liegt, richtete sich bisher nach ihrer
   Reihenfolge in der Datei — alle teilen sich dieselbe Ebene. Das ging
   so lange gut, wie nie einer aus einem anderen heraus aufging. Die
   persönlichen Pauschalen öffnen sich aber aus dem Kontodialog, stehen
   in der Datei aber davor: Sie lagen dahinter und waren nicht zu
   bedienen. Darum ein Stapel — wer zuletzt aufging, liegt oben. */
var DLG_STAPEL = [];

function dlgAuf(id) {
  var $d = $('#' + id);
  if (!$d.length || $d.hasClass('auf')) return;
  DLG_STAPEL.push(id);
  $d.css('z-index', 9500 + DLG_STAPEL.length).addClass('auf');
}

function dlgZu(id) {
  $('#' + id).removeClass('auf').css('z-index', '');
  DLG_STAPEL = $.grep(DLG_STAPEL, function (x) { return x !== id; });
}

$(document).on('click', '[data-zu]', function () {
  var $ov = $(this).closest('.a-ov');
  if ($ov.length) dlgZu($ov.attr('id'));
});

$(document).on('keydown', function (e) {
  /* Escape schliesst nur den obersten Dialog, nicht alle auf einmal —
     sonst verschwände mit einer Rückfrage auch die Abrechnung darunter. */
  if (e.key !== 'Escape') return;
  while (DLG_STAPEL.length) {
    var id = DLG_STAPEL[DLG_STAPEL.length - 1];
    if ($('#' + id).hasClass('auf')) { dlgZu(id); return; }
    DLG_STAPEL.pop();   // ist anderweitig zugegangen
  }
  /* Rückfall, falls jemand einen Dialog an dlgAuf vorbei geöffnet hat. */
  var offen = $('.a-ov.auf');
  if (offen.length) dlgZu(offen.last().attr('id'));
});

/** Rückfrage mit eigenem Dialog statt confirm() — das sieht auf dem Handy
    besser aus und lässt sich beschriften. */
/* Der Bestätigungsdialog. `art` sagt, was sein Knopf anrichtet:
   'ok' bestätigt (grün), 'gefahr' nimmt zurück oder löscht (rot),
   'neutral' öffnet oder verschickt nur etwas (blau). Ohne Angabe gilt
   'ok' — denn das ist der häufige Fall, und ein grüner Knopf, der
   etwas löscht, wäre ein Fehler, keine Nachlässigkeit. */
function frage(titel, text, knopfText, dann, art) {
  $('#d-frage-titel').text(titel);
  $('#d-frage-bd').html(text);
  var klasse = { gefahr: 'a-btn-r', neutral: 'a-btn-p' }[art] || 'a-btn-ok';
  $('#d-frage-ok')
    .removeClass('a-btn-ok a-btn-r a-btn-p').addClass(klasse)
    .text(knopfText || 'Ja').off('click').on('click', function () {
      dlgZu('d-frage'); dann();
    });
  dlgAuf('d-frage');
}

/* ── Ein Schritt weiter ──────────────────────────────────────────────
   Ein Vorgang, der eine Station weitergerückt ist, soll das auch zeigen.
   Die Meldung unten sagt es in Worten, geht aber leicht unter, wenn der
   Blick gerade auf der Liste liegt — und die Liste selbst sieht nach dem
   Neuladen einfach anders aus, ohne dass klar wird, WAS sich geändert
   hat. Darum einmal kurz in der Mitte: der erreichte Stand, mit einem
   Haken, der sich zeichnet.

   Nur VORWÄRTS. Zurückziehen, Zurückgeben und das Entfernen eines
   Zahlungsvermerks sind Rückschritte; sie zu feiern wäre Hohn.

   Wer im Betriebssystem weniger Bewegung eingestellt hat, bekommt
   dasselbe Zeichen ohne Bewegung. Die Rückmeldung ist die Botschaft,
   nicht die Bewegung. */
var SCHRITT_UHR = null;

function schrittWeiter(stand, name) {
  if (!name) return;
  clearTimeout(SCHRITT_UHR);
  $('#a-schritt').remove();

  var ruhig = !!(window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  var $f = $('<div id="a-schritt" class="a-schritt-feier' + (ruhig ? ' ist-ruhig' : '') + '">'
    + '<div class="a-schritt-karte" role="status" aria-live="polite">'
    + '<svg class="a-schritt-zeichen" viewBox="0 0 52 52" aria-hidden="true">'
    + '<circle class="a-schritt-kreis" cx="26" cy="26" r="23"/>'
    + '<path class="a-schritt-haken" d="M15 27.5l7.5 7.5L37 20"/>'
    + '</svg>'
    + '<div class="a-schritt-stand">' + esc(name) + '</div>'
    + '</div></div>');

  /* Das Zeichen nimmt keine Klicks an (pointer-events: none im
     Stylesheet): Was darunter liegt, bleibt bedienbar. Wird eine
     Rechnung angelegt, geht dahinter schon ihr Dialog auf — den soll
     niemand erst freiklicken müssen. Weggenommen wird es beim ersten
     Klick oder Tastendruck irgendwo, siehe unten. */
  /* In die Anwendung, nicht an den <body>: Die Farben des Zeichens sind
     Variablen, und die gelten nur innerhalb von #a-root. Am <body>
     hängend hatte der Haken keine Strichfarbe — er wurde gezeichnet und
     war nicht zu sehen. */
  var $ziel = $('#a-root');
  ($ziel.length ? $ziel : $('body')).append($f);
  SCHRITT_UHR = setTimeout(schrittWeg, ruhig ? 1100 : 1800);
}

function schrittWeg() {
  clearTimeout(SCHRITT_UHR);
  var $f = $('#a-schritt');
  if (!$f.length) return;
  $f.addClass('ist-weg');
  setTimeout(function () { $f.remove(); }, 220);
}

/* Der erste Klick und der erste Tastendruck irgendwo nehmen es weg — und
   gehen trotzdem dorthin, wo sie hinsollten. Niemand wartet auf eine
   Animation, und niemand klickt zweimal. Mit Fangphase, damit es auch
   greift, wenn das Ziel selbst die Weitergabe anhält. */
document.addEventListener('pointerdown', function () { schrittWeg(); }, true);
document.addEventListener('keydown', function () { schrittWeg(); }, true);

/* ── Listen, die zeigen, was sich geändert hat ───────────────────────
   Nach jedem Speichern wird die Liste neu geholt und neu gezeichnet.
   Das ist ehrlich — es zeigt, was der Server wirklich hat —, aber es
   sagt nicht, WAS sich geändert hat: Die Liste sieht danach einfach
   anders aus. Darum merkt sich jede Liste ihre Zeilen und vergleicht
   beim nächsten Zeichnen:

     neu        gleitet ein und ist kurz hinterlegt
     geändert   leuchtet kurz auf
     entfernt   bleibt einen Augenblick als Schatten stehen und geht dann

   `kontext` sagt, WORAUF sich die Liste bezieht (Quartal, Jahr, Filter).
   Ändert er sich, wird nicht verglichen: Wer das Quartal wechselt, sieht
   eine andere Liste, keine geänderte — sonst leuchtete alles auf.

   Eine Zeile braucht einen Schlüssel, und der kommt aus dem, was schon
   da ist: ihr eigenes data-id oder das des ersten Knopfs darin. Ohne
   Schlüssel bewegt sich nichts — lieber keine Bewegung als eine falsche. */
var LISTEN = {};

function zeilenVon($el) {
  return $el.find('tbody > tr, .a-zeile, ul.a-dateien > li').not('.ist-fort');
}

function zeilenSchluessel(el) {
  var eigen = el.getAttribute('data-id');
  if (eigen) return 'z' + eigen;
  var innen = el.querySelector('[data-id]');
  return innen ? 'i' + innen.getAttribute('data-id') : null;
}

function listeAbgleichen($el, kontext) {
  var id = $el.attr('id');
  if (!id) return;
  var alt = LISTEN[id] || null;
  var neu = { kontext: String(kontext === undefined ? '' : kontext), zeilen: {}, reihen: [] };
  var $z = zeilenVon($el);
  $z.each(function (i) {
    var k = zeilenSchluessel(this);
    if (k === null) return;
    neu.zeilen[k] = $(this).text();
    neu.reihen.push({ k: k, html: this.outerHTML, i: i });
  });
  LISTEN[id] = neu;
  if (!alt || alt.kontext !== neu.kontext) return;

  $z.each(function () {
    var k = zeilenSchluessel(this);
    if (k === null) return;
    if (!Object.prototype.hasOwnProperty.call(alt.zeilen, k)) $(this).addClass('ist-frisch');
    else if (alt.zeilen[k] !== neu.zeilen[k]) $(this).addClass('ist-anders');
  });

  /* Entferntes steht nicht mehr im neuen Stand. Damit man sieht, WAS weg
     ist, kommt die alte Zeile kurz als Schatten zurück an ihre Stelle —
     ohne Funktion: Knöpfe gesperrt, für Vorleseprogramme unsichtbar. */
  $.each(alt.reihen, function (_, r) {
    if (Object.prototype.hasOwnProperty.call(neu.zeilen, r.k)) return;
    var $jetzt = zeilenVon($el);
    if (!$jetzt.length) return;        // die Liste ist jetzt leer — der Leer-Hinweis sagt es
    var $g = $(r.html).removeClass('ist-frisch ist-anders').addClass('ist-fort')
               .attr('aria-hidden', 'true');
    $g.find('button, a, input, select').prop('disabled', true).attr('tabindex', '-1');
    $g.find('[id]').removeAttr('id');
    if (r.i < $jetzt.length) $g.insertBefore($jetzt.eq(r.i)); else $g.insertAfter($jetzt.last());
    setTimeout(function () { $g.remove(); }, 460);
  });
}

function listeSetzen($el, html, kontext) {
  $el.html(html);
  listeAbgleichen($el, kontext);
}

/* ── Herunterladen mit Rückmeldung ───────────────────────────────────
   Ein Download ist ein gewöhnlicher Verweis und keine Anfrage — also
   gab es dafür weder Ladebalken noch irgendein Zeichen. Das Jahrespaket
   mit allen Belegen baut der Server aber sekundenlang, und so lange sah
   die Seite aus, als sei nichts passiert.

   Jetzt läuft der Download durch einen unsichtbaren Rahmen:
   - Der Knopf zeigt „wird gebaut …", bis die Datei wirklich kommt. Dass
     sie kommt, sagt der Server mit einem Zeichen (Cookie lsv07a_dl, mit
     einer Marke, die hier ausgedacht wird und sonst nichts bedeutet).
   - Lehnt der Server ab, landet seine Fehlerseite im Rahmen statt
     anstelle der Anwendung — und ihr Text erscheint als Meldung. */
function herunterladen(url, $knopf, warteText) {
  if ($knopf.hasClass('ist-beschaeftigt')) return;
  var marke = (Math.random().toString(36).slice(2) + Date.now().toString(36))
                .replace(/[^a-z0-9]/g, '').slice(0, 20);
  var vorher = $knopf.text();
  $knopf.addClass('ist-beschaeftigt').attr('aria-busy', 'true').text(warteText);

  var $rahmen = $('<iframe class="a-dl-rahmen" aria-hidden="true" tabindex="-1"></iframe>');
  var uhr = null, ende = null, erledigt = false;

  function fertig(fehler) {
    if (erledigt) return;
    erledigt = true;
    clearInterval(uhr); clearTimeout(ende);
    $knopf.removeClass('ist-beschaeftigt').removeAttr('aria-busy').text(vorher);
    document.cookie = 'lsv07a_dl=; Max-Age=0; path=/';
    /* Der Rahmen bleibt noch stehen: Entfernt man ihn sofort, bricht
       mancher Browser den gerade beginnenden Download ab. */
    setTimeout(function () { $rahmen.remove(); }, 60000);
    if (fehler) toast(fehler, 'fehler');
  }

  uhr = setInterval(function () {
    if (document.cookie.indexOf('lsv07a_dl=' + marke) >= 0) fertig('');
  }, 250);

  $rahmen.on('load', function () {
    /* Eine Datei löst hier nichts aus. Kommt eine SEITE, hat der Server
       abgelehnt — ihr Text ist der Grund. */
    var text = '';
    try {
      var koerper = $rahmen[0].contentDocument.body || {};
      text = $.trim(koerper.innerText || koerper.textContent || '');
    } catch (e) {}
    if (!text) return;
    /* Das Tor antwortet auch hier mit JSON (abgelaufene Sitzung, kein
       Zugang). Dann ist die Meldung darin der Grund — nicht die Klammern
       drumherum. */
    var j = null;
    try { j = JSON.parse(jsonRetten(text) || text); } catch (e) {}
    if (j && j.data && j.data.message) text = String(j.data.message);
    else if (text === '0' || text === '-1') {
      text = 'Die Anmeldung ist abgelaufen. Bitte die Seite neu laden und erneut anmelden.';
    }
    fertig(text.replace(/\s+/g, ' ').slice(0, 300));
  });

  ende = setTimeout(function () {
    fertig('Der Download hat nach zwei Minuten nicht begonnen. Der Server braucht '
         + 'dafür zu lange oder hat ihn abgebrochen — bitte noch einmal versuchen; '
         + 'bleibt es dabei, die Administration verständigen.');
  }, 120000);

  $('body').append($rahmen);
  $rahmen.attr('src', url + (url.indexOf('?') >= 0 ? '&' : '?') + 'dl=' + marke);
}

/* Die Zahl an einem Reiter. Eine 0 wird AUSGEBLENDET und nicht als „0"
   gezeigt: Eine Null ist keine Nachricht. Fehlt die Angabe ganz, steht dort
   lieber nichts als „undefined". */
var BADGE_ALT = null, GESAMT_ALT = null;
function pruefBadge(n) {
  n = parseInt(n, 10) || 0;
  var $b = $('#a-badge-pruef').text(n).prop('hidden', n === 0);
  /* Steigt die Zahl, pulst sie einmal: Es ist etwas dazugekommen. Beim
     ersten Mal (noch kein alter Wert) und beim Sinken nicht — weniger
     Arbeit ist keine Meldung. Die Klasse wird erst abgenommen und nach
     einem erzwungenen Neuaufbau wieder gesetzt, sonst liefe die Bewegung
     beim zweiten Anstieg nicht noch einmal. */
  if (BADGE_ALT !== null && n > BADGE_ALT) {
    $b.removeClass('ist-puls');
    void ($b[0] && $b[0].offsetWidth);
    $b.addClass('ist-puls');
  }
  BADGE_ALT = n;
}

/* Selbst eingereicht und selbst genehmigt. Keine Fehlermeldung — es ist
   erlaubt —, aber sichtbar: Wer auszahlt, soll es sehen, bevor er zahlt. */
function selbstChip(x) {
  return x && x.selbst_genehmigt
    ? ' <span class="a-chip a-chip-gelb" title="Eingereicht und genehmigt hat dieselbe Person">selbst genehmigt</span>'
    : '';
}

function statusChip(status, name) {
  /* Dieselbe Logik wie bei den Knöpfen: Blau heisst unterwegs, Grün
     erledigt, Rot zurückgenommen, Grau noch nichts. */
  var farbe = { entwurf: 'grau', eingereicht: 'blau', zurueck: 'rot',
                genehmigt: 'gruen-rand', bezahlt: 'gruen', offen: 'grau' }[status] || 'grau';
  return '<span class="a-chip a-chip-' + farbe + '">' + esc(name || status) + '</span>';
}

// ════════════════════════════════════════════════════════════════════
//  MEINE ABRECHNUNG
// ════════════════════════════════════════════════════════════════════

var TYP_NAME = { training: 'Training', wettkampf: 'Wettkämpfe', fahrt: 'Fahrtkosten',
                 vorbereitung: 'Vorbereitung', sonstiges: 'Sonstiges' };

function abrLaden() {
  var q = $('#e-quartal').val() || A.quartal;
  var j = parseInt($('#e-jahr').val(), 10) || A.jahr;
  $('#e-gruppen').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_get', { quartal: q, jahr: j }).done(function (r) {
    if (!r || !r.success) { return; }
    A.abr = r.data;
    abrZeichnen();
  });
}

/* Der Weg vom Entwurf bis zur Auszahlung, als Schritte.

   Vier Stationen, immer alle sichtbar — auch die, die noch kommen. So
   sieht man auf einen Blick, wo es steht UND was noch folgt. Erledigtes
   trägt sein Datum; der aktuelle Schritt ist durch Schrift und Rahmen
   hervorgehoben, nicht durch Farbe.

   "Zurückgegeben" ist kein eigener Schritt, sondern ein Rückfall auf den
   ersten — die Abrechnung ist dann wieder in Arbeit. Der Weg bleibt so
   immer gleich lang und springt nicht hin und her. */
function schritte(d) {
  var stand = d.status;
  // Welche Station ist erreicht? zurueck zählt wie Entwurf.
  var folge = ['entwurf', 'eingereicht', 'genehmigt', 'bezahlt'];
  var jetzt = folge.indexOf(stand === 'zurueck' ? 'entwurf' : stand);
  if (jetzt < 0) jetzt = 0;

  var stationen = [
    { name: stand === 'zurueck' ? 'Überarbeiten' : 'Entwurf',
      wann: '', hinweis: stand === 'zurueck' ? 'zurückgegeben' : '' },
    { name: 'Eingereicht', wann: d.eingereicht_am },
    { name: 'Genehmigt',   wann: d.genehmigt_am },
    { name: 'Bezahlt',     wann: d.bezahlt_am }
  ];

  var h = '';
  $.each(stationen, function (i, st) {
    var zustand = i < jetzt ? 'ist-fertig' : (i === jetzt ? 'ist-jetzt' : 'ist-offen');
    h += '<li class="a-schritt ' + zustand + '">'
       + '<span class="a-schritt-zahl" aria-hidden="true">' + (i < jetzt ? '✓' : (i + 1)) + '</span>'
       + '<span class="a-schritt-txt">'
       + '<span class="a-schritt-name">' + esc(st.name) + '</span>'
       + '<span class="a-schritt-wann">'
       + esc(st.wann ? deZeit(st.wann) : (st.hinweis || (i === jetzt ? 'jetzt' : '')))
       + '</span></span>'
       /* Die Station wird zusätzlich ausgeschrieben angesagt — wer sie
          nicht sieht, hört sonst nur eine Zahl. */
       + '<span class="a-nur-vorlesen">'
       + (i < jetzt ? 'erledigt' : (i === jetzt ? 'aktueller Schritt' : 'steht noch aus'))
       + '</span></li>';
  });
  $('#e-schritte').html(h).prop('hidden', false);
}

function abrZeichnen() {
  var d = A.abr;
  if (!d) return;

  // Hinweise: fehlender interner Bereich, und was die Automatik getan hat
  var hin = '';
  if (d.hinweis_intern) hin += '<div class="a-hinweis ist-warn">' + esc(d.hinweis_intern) + '</div>';
  if (d.auto_neu) {
    hin += '<div class="a-hinweis ist-gut">' + d.auto_neu + ' Training'
         + (d.auto_neu === 1 ? ' wurde' : 's wurden') + ' von selbst übernommen.</div>';
  }
  /* Was die Automatik nicht nehmen konnte, wird gezählt — sonst fehlte
     es still in der Abrechnung und niemand wüsste davon. */
  if (d.auto_ohne_zeit) {
    hin += '<div class="a-hinweis ist-warn">' + d.auto_ohne_zeit + ' Training'
         + (d.auto_ohne_zeit === 1 ? ' ohne' : 's ohne') + ' hinterlegte Trainingszeit.</div>';
  }
  $('#e-hinweis').html(hin);

  schritte(d);

  // ── Statusband
  var $band = $('#e-band').show().prop('hidden', false)
    .removeClass('ist-offen ist-wartet ist-gut ist-schlecht');
  var akt = '';
  if (d.status === 'entwurf') {
    $band.addClass('ist-offen');
    $('#e-band-titel').text('Entwurf');
    $('#e-band-text').text('');
  } else if (d.status === 'zurueck') {
    $band.addClass('ist-schlecht');
    $('#e-band-titel').text('Zurückgegeben');
    $('#e-band-text').text(d.rueckgabe_grund || '');
  } else if (d.status === 'eingereicht') {
    $band.addClass('ist-wartet');
    $('#e-band-titel').text('Eingereicht — wartet auf Prüfung');
    $('#e-band-text').text('Seit ' + deZeit(d.eingereicht_am));
    akt = '<button class="a-btn a-btn-klein a-btn-r" id="e-zurueckziehen">Zurückholen</button>';
  } else if (d.status === 'genehmigt') {
    $band.addClass('ist-gut');
    $('#e-band-titel').text('Genehmigt');
    $('#e-band-text').text('Am ' + deZeit(d.genehmigt_am));
    akt = nachtragKnopf(d);
  } else if (d.status === 'bezahlt') {
    $band.addClass('ist-gut');
    $('#e-band-titel').text('Bezahlt');
    $('#e-band-text').text('Am ' + deZeit(d.bezahlt_am));
    akt = nachtragKnopf(d);
  }
  $('#e-band-akt').html(akt);

  notUebernehmen(d, 'eigene');

  // ── Summenkacheln (Weiter unten; die Schrittanzeige steht in schritte())
  var k = '';
  $.each(['training', 'wettkampf', 'fahrt', 'vorbereitung', 'sonstiges'], function (i, t) {
    k += '<div class="a-kachel"><div class="a-kachel-lbl">' + esc(TYP_NAME[t]) + '</div>'
       + '<div class="a-kachel-wert">' + eur(d.summen[t]) + '</div>'
       + '<div class="a-kachel-sub">' + (d.posten[t] || []).length + ' Posten</div></div>';
  });
  $('#e-summen').html(k);

  // ── Die fünf Bereiche
  var h = '';
  $.each(['training', 'wettkampf', 'fahrt', 'vorbereitung', 'sonstiges'], function (i, t) {
    var liste = d.posten[t] || [];
    h += '<section class="a-gruppe"><div class="a-gruppe-hd">'
       + '<div class="a-gruppe-titel">' + esc(TYP_NAME[t])
       + '<span class="a-gruppe-summe">' + eur(d.summen[t]) + '</span></div>'
       + '<div class="a-gruppe-akt">';
    if (d.offen) {
      if (t === 'training') {
        h += '<button class="a-btn a-btn-klein a-btn-p" id="e-ueb-training">Aus dem Training übernehmen</button>';
      }
      if (t === 'wettkampf') {
        h += '<button class="a-btn a-btn-klein a-btn-p" id="e-ueb-wettkampf">Wettkampf wählen</button>';
      }
      h += '<button class="a-btn a-btn-klein a-btn-p e-neu" data-typ="' + t + '">+ Eintrag</button>';
    }
    h += '</div></div>';

    if (!liste.length) {
      h += '<div class="a-leer">Nichts erfasst.</div>';
    } else {
      h += '<div class="a-zeilen">';
      $.each(liste, function (j, p) { h += postenZeile(p, d.offen, d.abrechnungsart); });
      h += '</div>';
    }
    h += '</section>';
  });
  listeSetzen($('#e-gruppen'), h, 'abr' + d.id);

  // ── Fußzeile
  /* Ändert sich der Gesamtbetrag, leuchtet er einmal auf: Das ist die
     Zahl, um die es geht, und sie steht weit weg von der Zeile, die man
     gerade bearbeitet hat. Nur bei DERSELBEN Abrechnung — beim Wechsel
     des Quartals ist eine andere Zahl keine Änderung. Gezählt wird
     nichts hoch: Ein Geldbetrag, der läuft, ist für einen Moment falsch. */
  var $ges = $('#e-gesamt'), gesNeu = eur(d.gesamt);
  if (GESAMT_ALT && GESAMT_ALT.id === d.id && GESAMT_ALT.text !== gesNeu) {
    $ges.removeClass('ist-anders');
    void ($ges[0] && $ges[0].offsetWidth);
    $ges.addClass('ist-anders');
  }
  GESAMT_ALT = { id: d.id, text: gesNeu };
  $ges.text(gesNeu);
  $('#e-fuss').prop('hidden', false);
  /* Den Beleg gibt es, sobald etwas darauf steht — nicht erst nach dem
     Bezahlen. Wer ihn für die eigenen Unterlagen braucht, soll nicht
     danach fragen müssen. */
  $('#e-pdf').data('id', d.id).toggle(d.gesamt > 0 || (d.posten && d.posten.training.length > 0));
  var $ein = $('#e-einreichen');
  if (d.offen) {
    $ein.show().frei('Zur Genehmigung einreichen');
    if (!d.zahlungsdaten.vollstaendig) {
      $ein.prop('disabled', true).text('Zuerst Zahlungsdaten hinterlegen');
    }
  } else {
    $ein.hide();
  }
}

/* ── Nachtrag und eigener Beleg ───────────────────────────────────────
   Eine abgeschlossene Abrechnung wird nicht mehr angefasst — ein
   vergessener Posten bekommt deshalb eine eigene, zweite Abrechnung für
   dasselbe Quartal. */
function nachtragKnopf(d) {
  return '<button class="a-btn a-btn-klein" id="e-nachtrag" data-id="' + d.id + '">'
       + 'Etwas nachtragen</button>';
}

$(document).on('click', '#e-nachtrag', function () {
  var id = $(this).data('id');
  frage('Etwas nachtragen', '',
    'Nachtrag anlegen', function () {
      ajax('lsv07a_nachtrag', { abrechnung_id: id }).done(function (r) {
        if (r && r.success) {
          toast(r.data.message, 'gut');
          schrittWeiter('nachtrag', 'Nachtrag angelegt');
          abrLaden(); meineListe();
        }
      });
    });
});

/* Der eigene Beleg als PDF. Er wird auf dem Server gebaut — derselbe,
   den die Kasse druckt und den die Mail nach dem Bezahlen mitbringt. */
function belegAdresse(id) {
  return LSV07A.ajax_url + '?action=lsv07a_beleg_pdf&abrechnung_id='
       + encodeURIComponent(id) + '&nonce=' + encodeURIComponent(LSV07A.nonce);
}

$(document).on('click', '.e-pdf', function () {
  var id = $(this).data('id');
  if (!id) return;
  /* Über herunterladen() und nicht mehr über ein eigenes Fenster: Das
     Fenster blieb leer stehen, wurde von Pop-up-Sperren verschluckt, und
     solange der Server das PDF baute, sah man nichts. Jetzt zeigt der
     Knopf, dass gearbeitet wird, und eine Absage des Servers kommt als
     Meldung in der Anwendung an — die Abrechnung bleibt stehen. */
  herunterladen(belegAdresse(id), $(this), 'Beleg wird gebaut …');
});

/* ════════════════════════════════════════════════════════════════════
 *  BEANSTANDUNGEN UND RÜCKFRAGEN AM POSTEN
 *
 *  Beides hängt an der Zeile, nicht an der Abrechnung. NOT hält, was
 *  gerade angezeigt wird: die Fäden je Posten, welche Zeilen beanstandet
 *  sind, und aus welcher Rolle man gerade schaut.
 * ════════════════════════════════════════════════════════════════════ */

var NOT = { notizen: {}, beanstandet: [], modus: 'eigene' };

function notUebernehmen(d, modus) {
  NOT.notizen     = d.notizen || {};
  NOT.beanstandet = d.beanstandet || [];
  if (modus) NOT.modus = modus;
}

var NOT_ART = {
  beanstandung: 'Beanstandet',
  aufgehoben:   'Beanstandung aufgehoben',
  frage:        'Rückfrage',
  antwort:      'Antwort'
};

/* Der Gesprächsfaden an einer Zeile: wer wann was geschrieben hat. */
function notFaden(id) {
  var liste = NOT.notizen[id] || NOT.notizen[String(id)] || [];
  if (!liste.length) return '';
  var h = '<div class="a-faden">';
  $.each(liste, function (i, n) {
    h += '<div class="a-faden-eintrag' + (n.art === 'beanstandung' ? ' ist-beanst' : '') + '">'
       + '<div class="a-faden-kopf">' + esc(NOT_ART[n.art] || n.art)
       + ' · ' + esc(n.wer) + ' · ' + esc(deZeit(n.zeit)) + '</div>'
       + '<div class="a-faden-text">' + esc(n.text) + '</div></div>';
  });
  return h + '</div>';
}

function notBeanstandet(id) {
  for (var i = 0; i < NOT.beanstandet.length; i++) {
    if (parseInt(NOT.beanstandet[i], 10) === parseInt(id, 10)) return true;
  }
  return false;
}

/* Was an einer Zeile zu tun ist — je nachdem, wer schaut. */
function notKnoepfe(p) {
  var hat = (NOT.notizen[p.id] || NOT.notizen[String(p.id)] || []).length > 0;
  var ist = notBeanstandet(p.id);
  if (NOT.modus === 'pruefen') {
    return '<div class="a-z-not">'
      + (ist
         ? '<button class="a-btn a-btn-klein a-btn-ok n-auf" data-id="' + p.id + '">Beanstandung aufheben</button>'
         : '<button class="a-btn a-btn-klein a-btn-r n-beanst" data-id="' + p.id + '">Beanstanden</button>')
      + '<button class="a-btn a-btn-klein a-btn-b n-frage" data-id="' + p.id + '">Rückfrage</button>'
      + '</div>';
  }
  /* Die eigene Abrechnung: antworten darf man immer, wenn jemand etwas
     geschrieben hat — auch wenn der Stand gerade gesperrt ist. Eine
     Antwort ändert ja nichts. */
  if (hat) {
    return '<div class="a-z-not">'
      + '<button class="a-btn a-btn-klein a-btn-b n-antwort" data-id="' + p.id + '">Antworten</button>'
      + '</div>';
  }
  return '';
}

/* ── Die Knöpfe an der Zeile ──────────────────────────────────────── */

var P_OFFEN = 0;   // welche Abrechnung gerade im Prüf-Dialog steht

/* Nach jeder Wortmeldung werden die Zeilen neu gezeichnet. Der Server
   schickt den vollständigen Stand zurück, damit der Browser ihn nicht
   selbst nachhalten muss — und damit zwei offene Fenster nicht
   auseinanderlaufen. */
function notNeuZeichnen(daten) {
  notUebernehmen(daten);
  if (NOT.modus === 'pruefen' && P_OFFEN) {
    ajax('lsv07a_pruef_detail', { abrechnung_id: P_OFFEN }).done(function (r) {
      if (r && r.success) { notUebernehmen(r.data, 'pruefen'); detailAnsicht(r.data, $('#d-detail-ft').html()); }
    });
  } else {
    abrLaden();
  }
}

function notDialog(titel, hilfe, knopf, dann, art) {
  frage(titel,
    '<div class="a-feld"><label for="n-text">' + hilfe + '</label>'
    + '<textarea id="n-text" class="a-ctl" rows="3"></textarea></div>',
    knopf, function () { dann($('#n-text').val() || ''); }, art);
}

$(document).on('click', '.n-beanst', function () {
  var id = $(this).data('id');
  notDialog('Zeile beanstanden',
    'Was stimmt an dieser Zeile nicht?',
    'Beanstanden', function (text) {
      ajax('lsv07a_notiz_beanstanden', { posten_id: id, text: text }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); notNeuZeichnen(r.data); }
      });
    }, 'gefahr');
});

$(document).on('click', '.n-auf', function () {
  var id = $(this).data('id');
  ajax('lsv07a_notiz_aufheben', { posten_id: id }).done(function (r) {
    if (r && r.success) { toast(r.data.message, 'gut'); notNeuZeichnen(r.data); }
  });
});

$(document).on('click', '.n-frage, .n-antwort', function () {
  var id = $(this).data('id');
  var frage_ = $(this).hasClass('n-frage');
  notDialog(frage_ ? 'Rückfrage zu dieser Zeile' : 'Antwort',
    frage_ ? 'Was möchten Sie wissen?' : 'Antwort',
    'Abschicken', function (text) {
      ajax('lsv07a_notiz_anlegen', { posten_id: id, text: text }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); notNeuZeichnen(r.data); }
      });
    }, 'neutral');
});

/* Weicht ein übernommenes Training von dem ab, was der Plan vorsah?
   Gibt null zurück, wenn es keinen Plan gibt (von Hand erfasst) oder die
   Stunden unverändert sind. Die Wartezeit steckt in `menge` mit drin —
   darum wird sie herausgerechnet, bevor verglichen wird. Sonst stünde an
   jeder Zeile mit Wartezeit „abweichend", obwohl niemand etwas geändert
   hat. */
function planAbweichung(p) {
  if (p.menge_plan === null || p.menge_plan === undefined || p.menge_plan === '') return null;
  var plan = parseFloat(p.menge_plan);
  var ist  = parseFloat(p.menge);
  if (!isFinite(plan) || !isFinite(ist)) return null;
  var zuschlag = p.wartezeit ? ((parseInt((LSV07A.config || {}).wartezeit_min, 10) || 0) / 60) : 0;
  var ohne = ist - zuschlag;
  if (Math.abs(ohne - plan) < 0.005) return null;
  return { plan: plan, ist: ohne, weniger: ohne < plan };
}

function postenZeile(p, offen, art) {
  var detail = [];
  var ab = null;
  if (p.typ === 'training') {
    if (art === 'pauschale') {
      detail.push('Pauschale ' + eur(p.satz));
    } else {
      detail.push(zahl(p.menge) + ' Std × ' + eur(p.satz));
      ab = planAbweichung(p);
      if (ab) {
        detail.push((ab.weniger ? 'statt ' : 'statt ') + zahl(ab.plan) + ' Std laut Plan');
      }
    }
    if (p.wartezeit) detail.push('mit Wartezeit');
    if (p.notiz) detail.push(p.notiz);
  } else if (p.typ === 'wettkampf') {
    detail.push(p.menge + ' Abschnitt' + (p.menge === 1 ? '' : 'e') + ' × ' + eur(p.satz));
  } else if (p.typ === 'fahrt') {
    detail.push(zahl(p.menge, 1) + ' km einfach × ' + eur(p.satz));
    if (p.tage > 1) detail.push(p.tage + ' Tage');
    if (parseFloat(p.betrag) === 0) detail.push('unter der Mindeststrecke');
  } else if (p.typ === 'vorbereitung') {
    detail.push(zahl(p.menge) + ' Std × ' + eur(p.satz));
    if (p.notiz) detail.push(p.notiz);
  } else if (p.notiz) {
    detail.push(p.notiz);
  }

  /* Die Wartezeit lässt sich direkt an der Zeile anhaken — man weiss oft
     erst hinterher, ob man gewartet hat. Nur bei Trainings und nur,
     solange die Abrechnung offen ist. */
  var wart = '';
  if (offen && p.typ === 'training') {
    wart = '<label class="a-z-wart" title="Wartezeit hinzurechnen">'
         + '<input type="checkbox" class="e-wart" data-id="' + p.id + '"'
         + (p.wartezeit ? ' checked' : '') + '>'
         + '<span>Wartezeit</span></label>';
  }

  var akt = '';
  if (offen) {
    akt = '<div class="a-z-akt">'
        + '<button class="a-ikon e-bearb" data-id="' + p.id + '" title="Bearbeiten" aria-label="Bearbeiten">'
        + '<svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>'
        + '<button class="a-ikon ist-weg e-weg" data-id="' + p.id + '" title="Entfernen" aria-label="Entfernen">'
        + '<svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg></button>'
        + '</div>';
  }

  var ist   = notBeanstandet(p.id);
  var faden = notFaden(p.id);
  var knopf = notKnoepfe(p);

  return '<div class="a-zeile' + (ist ? ' ist-beanstandet' : '') + '" data-id="' + p.id + '">'
       + '<div class="a-z-datum">' + esc(de(p.datum)) + '</div>'
       + '<div class="a-z-text"><div class="a-z-name">' + esc(p.bezeichnung)
       + (ist ? ' <span class="a-chip a-chip-warn">beanstandet</span>' : '')
       /* Vom Plan abweichend: kein Vorwurf, nur ein Vermerk. Wer früher
          gegangen ist, hat ihn selbst gesetzt — und der Wart muss nicht
          raten, warum eine Stunde fehlt. */
       + (ab ? ' <span class="a-chip a-chip-gelb">' + (ab.weniger ? 'früher gegangen' : 'länger geblieben')
             + '</span>' : '')
       + '</div>'
       + (detail.length ? '<div class="a-z-detail">' + esc(detail.join(' · ')) + '</div>' : '')
       + faden + knopf
       + '</div>'
       + '<div class="a-z-betrag">' + eur(p.betrag) + '</div>'
       + wart + akt + '</div>';
}

/* ── Auffälligkeiten ──────────────────────────────────────────────────
   Ein Band über der Abrechnung. Es blockiert nichts — es sagt nur, wo
   ein zweiter Blick lohnt. */
function hinweisBand(liste) {
  if (!liste || !liste.length) return '';
  /* Bewusst in der neutralen Farbe: Rot ist hier fuer das reserviert,
     was wirklich zurueckgenommen wurde. Ein Hinweis, der sich selbst
     als "kein Fehler" bezeichnet, darf nicht wie einer aussehen. */
  var h = '<div class="a-hinweis">'
        + '<strong>' + liste.length + (liste.length === 1 ? ' Auffälligkeit' : ' Auffälligkeiten')
        + '</strong><ul class="a-hinw-liste">';
  $.each(liste, function (i, x) {
    h += '<li>' + esc(x.text) + '</li>';
  });
  return h + '</ul></div>';
}

/* Umschalten rechnet den Posten auf dem Server neu — die Beträge kommen
   nie aus dem Browser. Bis die Antwort da ist, bleibt das Häkchen
   gesperrt, damit nicht zwei Anfragen übereinander laufen. */
$(document).on('change', '.e-wart', function () {
  var $b = $(this), id = $b.data('id'), an = $b.is(':checked') ? 1 : 0;
  $b.prop('disabled', true);
  ajax('lsv07a_posten_wartezeit',
       { abrechnung_id: A.abr.id, posten_id: id, wartezeit: an })
    .done(function (r) {
      if (r && r.success) { toast(r.data.message, 'gut'); abrLaden(); }
      else $b.prop('checked', !an);
    })
    .fail(function () { $b.prop('checked', !an); })
    .always(function () { $b.prop('disabled', false); });
});

$(document).on('change', '#e-quartal, #e-jahr', function () { abrLaden(); });

$(document).on('click', '#e-zurueckziehen', function () {
  var $b = $(this).prop('disabled', true);
  ajax('lsv07a_zurueckziehen', { abrechnung_id: A.abr.id }).done(function (r) {
    if (r && r.success) { toast(r.data.message, 'gut'); abrLaden(); meineListe(); }
    else $b.prop('disabled', false);
  }).fail(function (x) { toast(fehlerText(x), 'fehler'); $b.prop('disabled', false); });
});

$(document).on('click', '#e-einreichen', function () {
  frage('Abrechnung einreichen',
    '<div class="a-feld"><label for="e-kommentar">Anmerkung für den Wart '
    + '<span class="a-opt">optional</span></label>'
    + '<textarea id="e-kommentar" class="a-ctl" rows="3"></textarea></div>'
    + '<p style="margin:10px 0 0;font-weight:600">Gesamt: ' + eur(A.abr.gesamt) + '</p>',
    'Einreichen', function () {
      ajax('lsv07a_einreichen', { abrechnung_id: A.abr.id, kommentar: $('#e-kommentar').val() || '' })
        .done(function (r) {
          if (r && r.success) {
            toast(r.data.message, 'gut');
            schrittWeiter('eingereicht', 'Eingereicht');
            abrLaden(); meineListe();
          }
        });
    });
});

// ── Posten anlegen und ändern ───────────────────────────────────────

function postenFormular(typ, p) {
  var d = p || {};
  var art = A.abr ? A.abr.abrechnungsart : 'zeiten';
  var cfg = LSV07A.config || {};
  var h = '<div class="a-feld"><label for="f-datum">Datum</label>'
        + '<input type="date" id="f-datum" class="a-ctl" value="' + esc(d.datum || heute()) + '"></div>';

  if (typ === 'training') {
    h += '<div class="a-feld"><label for="f-bez">Mannschaft oder Bezeichnung</label>'
       + '<input type="text" id="f-bez" class="a-ctl" value="' + esc(d.bezeichnung || '') + '"></div>';
    if (art !== 'pauschale') {
      var plan = (d.menge_plan === null || d.menge_plan === undefined || d.menge_plan === '')
                 ? null : parseFloat(d.menge_plan);
      h += '<div class="a-feld"><label for="f-menge">Stunden</label>'
         + '<input type="number" id="f-menge" class="a-ctl" step="0.25" min="0" inputmode="decimal" '
         + 'value="' + esc(d.menge !== undefined ? d.menge : '1.5') + '"'
         + (plan !== null ? ' data-plan="' + plan + '"' : '') + '>';
      if (plan !== null) {
        /* Wer früher gegangen ist, trägt die wirklichen Stunden ein. Was
           der Plan vorsah, steht daneben — und ein Knopf führt zurück,
           falls man sich vertippt hat. */
        h += '<div class="a-z-detail">Laut Trainingsplan <strong>' + zahl(plan) + ' Std</strong>. '
           + 'Früher gegangen oder länger geblieben? Tragen Sie hier ein, was wirklich war — '
           + 'die Abweichung wird an der Zeile vermerkt. '
           + '<button type="button" class="a-btn a-btn-klein" id="f-plan-zurueck">'
           + 'Auf ' + zahl(plan) + ' Std zurücksetzen</button></div>';
      }
      h += '</div>';
    }
    h += '<div class="a-schalter-zeile"><input type="checkbox" id="f-wartezeit"'
       + (d.wartezeit ? ' checked' : '') + '>'
       + '<label for="f-wartezeit">Wartezeit von ' + (cfg.wartezeit_min || 15) + ' Minuten hinzurechnen</label></div>';

  } else if (typ === 'wettkampf') {
    /* Die Abschnitte sind eine Pflichtangabe: Wie viele man wirklich da
       war, weiss nur der Trainer. Der geplante Wert des Tages wird NICHT
       eingesetzt — wer durchklickte, rechnete sonst den Plan ab, auch
       wenn er nur die Hälfte da war. Er steht daneben, mit einem Knopf,
       der ihn übernimmt. Eine Zahl aus der eigenen Wettkampf-Anwesenheit
       ist dagegen ein gemessener Wert und darf im Feld stehen. */
    var wkPlan = (d.menge_plan === null || d.menge_plan === undefined || d.menge_plan === '')
                 ? null : parseInt(d.menge_plan, 10);
    var wkWert = (d.menge === null || d.menge === undefined || d.menge === '')
                 ? '' : parseInt(d.menge, 10);
    h += '<div class="a-feld"><label for="f-bez">Wettkampf</label>'
       + '<input type="text" id="f-bez" class="a-ctl" value="' + esc(d.bezeichnung || '') + '"></div>'
       + '<div class="a-feld"><label for="f-menge">Abschnitte</label>'
       + '<input type="number" id="f-menge" class="a-ctl" step="1" min="1" max="40" '
       + 'inputmode="numeric" required placeholder="Anzahl" '
       + 'value="' + esc(wkWert) + '"'
       + (wkPlan !== null ? ' data-plan="' + wkPlan + '"' : '') + '>';
    if (d.war_dabei) {
      h += '<div class="a-z-detail">Aus Ihrer Wettkampf-Anwesenheit übernommen. '
         + 'Waren Sie bei mehr oder weniger Abschnitten dabei, tragen Sie hier ein, '
         + 'was wirklich war.</div>';
    } else if (wkPlan !== null) {
      h += '<div class="a-z-detail">Für diesen Tag sind <strong>' + wkPlan + ' Abschnitt'
         + (wkPlan === 1 ? '' : 'e') + '</strong> geplant. Tragen Sie ein, bei wie vielen '
         + 'Sie wirklich dabei waren — der Plan wird nicht abgerechnet. '
         + '<button type="button" class="a-btn a-btn-klein" id="f-plan-wk">'
         + 'Auf ' + wkPlan + ' setzen</button></div>';
    }
    h += '<div class="a-hinweis ist-fehler" id="f-menge-fehler" hidden></div></div>';

  } else if (typ === 'fahrt') {
    h += '<div class="a-feld"><label for="f-bez">Wohin ging die Fahrt?</label>'
       + '<input type="text" id="f-bez" class="a-ctl" value="' + esc(d.bezeichnung || '') + '"></div>'
       + '<div class="a-zwei">'
       + '<div class="a-feld"><label for="f-menge">Einfache Strecke (km)</label>'
       + '<input type="number" id="f-menge" class="a-ctl" step="0.1" min="0" inputmode="decimal" '
       + 'value="' + esc(d.menge !== undefined ? d.menge : '') + '"></div>'
       + '<div class="a-feld"><label for="f-tage">Tage</label>'
       + '<input type="number" id="f-tage" class="a-ctl" step="1" min="1" inputmode="numeric" '
       + 'value="' + esc(d.tage || 1) + '"></div></div>';

  } else if (typ === 'vorbereitung') {
    h += '<div class="a-feld"><label for="f-bez">Wofür?</label>'
       + '<input type="text" id="f-bez" class="a-ctl" placeholder="z. B. Trainingsplanung" '
       + 'value="' + esc(d.bezeichnung || '') + '"></div>'
       + '<div class="a-feld"><label for="f-menge">Stunden</label>'
       + '<input type="number" id="f-menge" class="a-ctl" step="0.25" min="0" inputmode="decimal" '
       + 'value="' + esc(d.menge !== undefined ? d.menge : '1') + '"></div>';

  } else {
    h += '<div class="a-feld"><label for="f-bez">Wofür?</label>'
       + '<input type="text" id="f-bez" class="a-ctl" value="' + esc(d.bezeichnung || '') + '"></div>'
       + '<div class="a-feld"><label for="f-betrag">Betrag (€)</label>'
       + '<input type="number" id="f-betrag" class="a-ctl" step="0.01" min="0" inputmode="decimal" '
       + 'value="' + esc(d.betrag !== undefined ? d.betrag : '') + '"></div>';
  }

  h += '<div class="a-feld"><label for="f-notiz">Anmerkung <span class="a-opt">optional</span></label>'
     + '<textarea id="f-notiz" class="a-ctl" rows="2">' + esc(d.notiz || '') + '</textarea></div>';
  return h;
}

$(document).on('click', '#f-plan-zurueck, #f-plan-wk', function () {
  var plan = $('#f-menge').data('plan');
  if (plan !== undefined) $('#f-menge').val(plan).trigger('change');
  $('#f-menge-fehler').prop('hidden', true);
});

function postenDialog(typ, p) {
  $('#d-posten-titel').text((p ? 'Posten bearbeiten' : 'Neuer Posten') + ' · ' + TYP_NAME[typ]);
  $('#d-posten-bd').html(postenFormular(typ, p));
  $('#d-posten-ok').off('click').on('click', function () {
    /* Die Abschnitte sind Pflicht. Der Server weist eine 0 ebenfalls ab
       (class-ajax-abrechnung.php) — hier steht die Meldung am Feld und
       nicht als Toast über dem Dialog, damit man sieht, wo es fehlt. */
    if (typ === 'wettkampf') {
      var ab = parseInt($('#f-menge').val(), 10);
      if (!isFinite(ab) || ab < 1) {
        $('#f-menge-fehler').text('Bitte angeben, bei wie vielen Abschnitten Sie dabei waren.')
          .prop('hidden', false);
        $('#f-menge').focus();
        return;
      }
    }
    var $b = $(this).beschaeftigt('Speichert…');
    var daten = {
      abrechnung_id: A.abr.id,
      id: p ? p.id : 0,
      typ: typ,
      datum: $('#f-datum').val(),
      bezeichnung: $('#f-bez').val() || '',
      menge: $('#f-menge').val() || 0,
      betrag: $('#f-betrag').val() || 0,
      tage: $('#f-tage').val() || 1,
      wartezeit: $('#f-wartezeit').is(':checked') ? 1 : 0,
      mannschaft_id: p ? (p.mannschaft_id || 0) : 0,
      notiz: $('#f-notiz').val() || ''
    };
    ajax('lsv07a_posten_speichern', daten).done(function (r) {
      if (r && r.success) {
        dlgZu('d-posten');
        if (r.data.warnung) toast(r.data.warnung, 'fehler'); else toast('Gespeichert.', 'gut');
        abrLaden();
      } else { $b.frei('Speichern'); }
    }).fail(function (x) {
      toast(fehlerText(x), 'fehler');
      $b.frei('Speichern');
    });
  }).frei('Speichern');
  dlgAuf('d-posten');
  setTimeout(function () { $('#d-posten-bd').find('input,textarea').not('[type=date]').first().focus(); }, 60);
}

$(document).on('click', '.e-neu', function () { postenDialog($(this).data('typ'), null); });

$(document).on('click', '.e-bearb', function () {
  var id = parseInt($(this).data('id'), 10);
  var gefunden = null, typ = null;
  $.each(A.abr.posten, function (t, liste) {
    $.each(liste, function (i, p) { if (p.id === id) { gefunden = p; typ = t; } });
  });
  if (!gefunden) { toast('Posten nicht gefunden.', 'fehler'); return; }
  postenDialog(typ, gefunden);
});

$(document).on('click', '.e-weg', function () {
  var id = parseInt($(this).data('id'), 10);
  frage('Posten entfernen', '',
    'Entfernen', function () {
      ajax('lsv07a_posten_loeschen', { abrechnung_id: A.abr.id, id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); abrLaden(); }
      });
    }, 'gefahr');
});

// ── Trainings übernehmen ────────────────────────────────────────────

$(document).on('click', '#e-ueb-training', function () {
  $('#d-ueb-titel').text('Trainings übernehmen');
  $('#d-ueb-bd').html('<div class="a-laden">Wird geladen…</div>');
  $('#d-ueb-ok').show().text('Übernehmen').prop('disabled', true);
  dlgAuf('d-uebernehmen');

  ajax('lsv07a_training_angebot', { abrechnung_id: A.abr.id }).done(function (r) {
    if (!r || !r.success) return;
    A.angebot = r.data.trainings || [];
    var h = '';
    if (r.data.hinweis) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis) + '</div>';
    if (!A.angebot.length) {
      h += '<div class="a-leer">Keine weiteren Trainings.</div>';
      $('#d-ueb-bd').html(h);
      $('#d-ueb-ok').hide();
      return;
    }
    h += '<div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
       + '<button class="a-btn a-btn-klein" id="ueb-alle">Alle auswählen</button>'
       + '<button class="a-btn a-btn-klein" id="ueb-keine">Auswahl aufheben</button></div>';

    var pauschal = r.data.abrechnungsart === 'pauschale';
    $.each(A.angebot, function (i, t) {
      var wert;
      if (pauschal) {
        wert = t.pauschale === null ? 'keine Pauschale hinterlegt' : eur(t.pauschale);
      } else if (t.zeit_fehlt) {
        wert = 'keine Trainingszeit hinterlegt';
      } else {
        wert = zahl(t.stunden) + ' Std';
      }

      /* Woher die Stunden stammen, steht dabei. Kommen sie nicht aus dem
         Trainingsplan der Saison, ist das ein Grund hinzusehen — genau da
         entstanden bisher zu kurze und zu lange Trainings. */
      var woher = '';
      if (!pauschal && !t.zeit_fehlt) {
        if (t.zeit_quelle === 'anwesenheit') woher = 'Zeit aus der Anwesenheit, nicht aus dem Plan';
        else if (t.zeit_quelle === 'wochentag') woher = 'Zeit ohne Saisonbezug';
        if (t.zeit_mehrdeutig) woher = (woher ? woher + ' · ' : '') + 'mehrere Zeiten an diesem Tag';
      }

      // Ohne Trainingszeit wäre der Posten 0 € — nicht vorauswählen.
      var vorgewaehlt = pauschal || !t.zeit_fehlt;

      h += '<label class="a-wahl">'
         + '<input type="checkbox" class="ueb-box" data-i="' + i + '"' + (vorgewaehlt ? ' checked' : '') + '>'
         + '<span class="a-wahl-txt"><span class="a-wahl-name">' + esc(de(t.datum)) + ' · '
         + esc(t.mannschaft_name || 'Training') + '</span>'
         + '<span class="a-wahl-sub">' + esc(wert)
         + (t.zeit_von ? ' · ' + esc(t.zeit_von.slice(0, 5) + '–' + t.zeit_bis.slice(0, 5)) : '')
         + (t.herkunft === 'springer' ? ' · Springer' : '') + '</span>'
         + (woher ? '<span class="a-wahl-sub a-wahl-pruefen">' + esc(woher) + '</span>' : '')
         + '</span>'
         + '<span class="a-wahl-wart"><input type="checkbox" class="ueb-wart" data-i="' + i + '">Wartezeit</span>'
         + '</label>';
    });
    $('#d-ueb-bd').html(h);
    $('#d-ueb-ok').prop('disabled', false);
  });
});

$(document).on('click', '#ueb-alle',  function () { $('.ueb-box').prop('checked', true); });
$(document).on('click', '#ueb-keine', function () { $('.ueb-box').prop('checked', false); });
/* Das Häkchen „Wartezeit" sitzt in derselben Beschriftung wie die
   Auswahl — ohne das Anhalten würde ein Klick darauf auch die Auswahl
   umschalten. */
$(document).on('click', '.a-wahl-wart', function (e) { e.stopPropagation(); });

$('#d-ueb-ok').on('click', function () {
  var auswahl = [];
  $('.ueb-box:checked').each(function () {
    var i = parseInt($(this).data('i'), 10);
    var t = A.angebot[i];
    if (!t) return;
    auswahl.push({ ref_typ: t.ref_typ, ref_id: t.ref_id,
                   wartezeit: $('.ueb-wart[data-i="' + i + '"]').is(':checked') ? 1 : 0 });
  });
  if (!auswahl.length) { toast('Bitte mindestens ein Training auswählen.', 'fehler'); return; }
  var $b = $(this).beschaeftigt('Übernimmt…');
  ajax('lsv07a_training_uebernehmen', { abrechnung_id: A.abr.id, auswahl: JSON.stringify(auswahl) })
    .done(function (r) {
      if (r && r.success) {
        dlgZu('d-uebernehmen'); toast(r.data.message, 'gut');
        /* Mit der ANZAHL im Zeichen — „übernommen" allein sagt nicht, ob
           alle angekommen sind. Wurde gar nichts übernommen (alles schon
           enthalten), gibt es nichts zu zeigen. */
        var n = parseInt(r.data.angelegt, 10) || 0;
        if (n > 0) schrittWeiter('uebernommen', n + (n === 1 ? ' Training übernommen' : ' Trainings übernommen'));
        abrLaden();
      }
      else $b.frei('Übernehmen');
    }).fail(function (x) {
      toast(fehlerText(x), 'fehler'); $b.frei('Übernehmen');
    });
});

// ── Wettkampf wählen ────────────────────────────────────────────────

$(document).on('click', '#e-ueb-wettkampf', function () {
  $('#d-ueb-titel').text('Wettkampf wählen');
  $('#d-ueb-bd').html('<div class="a-laden">Wird geladen…</div>');
  $('#d-ueb-ok').hide();
  dlgAuf('d-uebernehmen');

  ajax('lsv07a_wettkampf_angebot', { abrechnung_id: A.abr.id }).done(function (r) {
    if (!r || !r.success) return;
    var liste = r.data.wettkaempfe || [];
    var h = '';
    if (r.data.hinweis) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis) + '</div>';
    if (!liste.length) {
      h += '<div class="a-leer">In diesem Quartal sind keine Wettkampftage hinterlegt.</div>';
      $('#d-ueb-bd').html(h);
      return;
    }
    $.each(liste, function (i, w) {
      /* Gemessen und geplant sind zwei verschiedene Dinge. Nur die Zahl
         aus der eigenen Wettkampf-Anwesenheit geht als Wert mit; der
         Plan geht als Plan mit und wird im Dialog nur angeboten. Der
         Betrag steht hier nicht mehr: Solange die Anzahl nicht
         angegeben ist, gibt es keinen. */
      var eigen = parseInt(w.abschnitte_eigen, 10) || 0;
      var plan  = parseInt(w.abschnitte_plan, 10) || 0;
      h += '<label class="a-wahl wk-wahl" data-datum="' + esc(w.datum) + '" '
         + 'data-name="' + esc(w.name) + '"'
         + (w.war_dabei && eigen > 0 ? ' data-abschnitte="' + eigen + '"' : '')
         + (plan > 0 ? ' data-plan="' + plan + '"' : '')
         + (w.war_dabei ? ' data-dabei="1"' : '') + '>'
         + '<span class="a-wahl-txt"><span class="a-wahl-name">' + esc(de(w.datum)) + ' · ' + esc(w.name) + '</span>'
         + '<span class="a-wahl-sub">' + (w.ort ? esc(w.ort) + ' · ' : '')
         + (w.war_dabei && eigen > 0
             ? eigen + ' Abschnitt' + (eigen === 1 ? '' : 'e')
               + ' aus Ihrer Wettkampf-Anwesenheit'
             : (plan > 0 ? plan + ' Abschnitt' + (plan === 1 ? '' : 'e') + ' geplant — '
                           + 'Anzahl bitte selbst angeben'
                         : 'Anzahl bitte selbst angeben'))
         + '</span></span>'
         + '<span class="a-chip a-chip-grau">' + eur(r.data.satz) + ' je Abschnitt</span></label>';
    });
    $('#d-ueb-bd').html(h);
  });
});

$(document).on('click', '.wk-wahl', function () {
  var d = $(this);
  dlgZu('d-uebernehmen');
  postenDialog('wettkampf', {
    datum: d.data('datum'), bezeichnung: d.data('name'),
    menge: parseInt(d.data('abschnitte'), 10) || '',
    menge_plan: d.data('plan') === undefined ? null : parseInt(d.data('plan'), 10),
    war_dabei: d.data('dabei') === 1 || d.data('dabei') === '1'
  });
});

// ── Frühere Abrechnungen ────────────────────────────────────────────

function meineListe() {
  ajax('lsv07a_meine_liste').done(function (r) {
    if (!r || !r.success) return;
    var z = r.data || [];
    if (!z.length) { $('#e-liste').html('<div class="a-leer">Noch keine Abrechnungen.</div>'); return; }
    var h = '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
          + '<th>Quartal</th><th>Status</th><th>Posten</th><th class="a-zahl">Gesamt</th><th></th>'
          + '</tr></thead><tbody>';
    $.each(z, function (i, a) {
      h += '<tr><td data-label="Quartal">' + esc(a.quartal + ' ' + a.jahr) + '</td>'
         + '<td data-label="Status">' + statusChip(a.status, a.status_name) + '</td>'
         + '<td data-label="Posten">' + a.posten + '</td>'
         + '<td data-label="Gesamt" class="a-zahl">' + eur(a.gesamt) + '</td>'
         + '<td class="a-td-akt"><button class="a-btn a-btn-klein e-oeffnen" '
         + 'data-q="' + esc(a.quartal) + '" data-j="' + a.jahr + '">Öffnen</button></td></tr>';
    });
    $('#e-liste').html(h + '</tbody></table></div>');
  });
}

$(document).on('click', '.e-oeffnen', function () {
  $('#e-quartal').val($(this).data('q'));
  $('#e-jahr').val($(this).data('j'));
  abrLaden();
  $('.a-verlauf').removeAttr('open');
  $('#a-body').scrollTop(0);
});

// ════════════════════════════════════════════════════════════════════
//  PRÜFUNG (WART)
// ════════════════════════════════════════════════════════════════════

function pruefListe() {
  $('#p-liste').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_pruef_liste', {
    quartal: $('#p-quartal').val(), jahr: $('#p-jahr').val(), status: $('#p-status').val()
  }).done(function (r) {
    if (!r || !r.success) return;
    var z = r.data.zeilen || [];
    pruefBadge(r.data.offen);
    if (!z.length) {
      $('#p-liste').html('<div class="a-leer">Für diese Auswahl gibt es nichts.</div>');
      return;
    }
    /* Wer nur fuer bestimmte Mannschaften zustaendig ist, soll das auch
       sehen — sonst wundert er sich, wo die anderen geblieben sind. */
    var h = '';
    if (r.data.beschraenkt) {
      h += '<div class="a-hinweis">Zuständig: <strong>' + esc(r.data.bereich) + '</strong></div>';
    }
    h += '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
          + '<th>Trainer</th><th>Status</th><th>Posten</th><th class="a-zahl">Gesamt</th>'
          + '<th>Eingereicht</th><th></th></tr></thead><tbody>';
    $.each(z, function (i, a) {
      var merk = '';
      if (a.beanstandet) merk += ' <span class="a-chip a-chip-warn">' + a.beanstandet + '× beanstandet</span>';
      else if (a.hinweise) merk += ' <span class="a-chip a-chip-grau">' + a.hinweise
                                 + (a.hinweise === 1 ? ' Hinweis' : ' Hinweise') + '</span>';
      if (a.nachtrag_zu) merk += ' <span class="a-chip a-chip-grau">Nachtrag</span>';
      h += '<tr><td data-label="Trainer">' + esc(a.name) + merk + '</td>'
         + '<td data-label="Status">' + statusChip(a.status, a.status_name) + '</td>'
         + '<td data-label="Posten">' + a.posten + '</td>'
         + '<td data-label="Gesamt" class="a-zahl">' + (a.id ? eur(a.gesamt) : '–') + '</td>'
         + '<td data-label="Eingereicht">' + esc(a.eingereicht_am ? deZeit(a.eingereicht_am) : '–') + '</td>'
         + '<td class="a-td-akt">'
         + (a.id ? '<button class="a-btn a-btn-klein a-btn-b p-detail" data-id="' + a.id + '">Ansehen</button>' : '')
         + (a.status === 'eingereicht'
            ? '<button class="a-btn a-btn-klein a-btn-ok p-ok" data-id="' + a.id + '">Genehmigen</button>'
            + '<button class="a-btn a-btn-klein a-btn-r p-zurueck" data-id="' + a.id + '">Zurückgeben</button>' : '')
         /* Die Administration kann jeden Schritt zurücknehmen — auch den,
            den sie selbst nicht gemacht hat. */
         + (a.id && Z.ist_admin_echt
            ? '<button class="a-btn a-btn-klein a-btn-b a-stand-setzen" data-id="' + a.id + '"'
              + ' data-status="' + esc(a.status) + '">Stand…</button>' : '')
         + '</td></tr>';
    });
    listeSetzen($('#p-liste'), h + '</tbody></table></div>',
      [$('#p-quartal').val(), $('#p-jahr').val(), $('#p-status').val()].join('|'));
  });
}

$(document).on('change', '#p-quartal, #p-jahr, #p-status', function () { pruefListe(); });

function detailAnsicht(d, fuss) {
  $('#d-detail-titel').text(d.name + ' · ' + d.quartal + ' ' + d.jahr);
  var h = '<div class="a-kacheln">';
  $.each(['training', 'wettkampf', 'fahrt', 'vorbereitung', 'sonstiges'], function (i, t) {
    h += '<div class="a-kachel"><div class="a-kachel-lbl">' + esc(TYP_NAME[t]) + '</div>'
       + '<div class="a-kachel-wert">' + eur(d.summen[t]) + '</div></div>';
  });
  h += '<div class="a-kachel ist-gesamt"><div class="a-kachel-lbl">Gesamt</div>'
     + '<div class="a-kachel-wert">' + eur(d.gesamt) + '</div></div></div>';

  h += '<div class="a-hinweis">' + statusChip(d.status, d.status_name)
     + ' · ' + esc(d.art_name) + ' · Stundensatz ' + eur(d.stundensatz)
     + (d.nachtrag_zu ? ' · <strong>Nachtrag</strong>' : '') + '</div>';
  h += hinweisBand(d.hinweise);
  if (d.beanstandet && d.beanstandet.length) {
    h += '<div class="a-hinweis ist-warn"><strong>' + d.beanstandet.length
       + (d.beanstandet.length === 1 ? ' Zeile beanstandet' : ' Zeilen beanstandet')
       + '</strong></div>';
  }
  if (d.kommentar) h += '<div class="a-hinweis"><strong>Anmerkung:</strong> ' + esc(d.kommentar) + '</div>';
  if (d.rueckgabe_grund) h += '<div class="a-hinweis ist-warn"><strong>Zuletzt zurückgegeben:</strong> '
     + esc(d.rueckgabe_grund) + '</div>';

  var leer = true;
  $.each(['training', 'wettkampf', 'fahrt', 'vorbereitung', 'sonstiges'], function (i, t) {
    var liste = d.posten[t] || [];
    if (!liste.length) return;
    leer = false;
    h += '<div class="a-gruppe"><div class="a-gruppe-hd"><div class="a-gruppe-titel">'
       + esc(TYP_NAME[t]) + '<span class="a-gruppe-summe">' + eur(d.summen[t]) + '</span></div></div>'
       + '<div class="a-zeilen">';
    $.each(liste, function (j, p) { h += postenZeile(p, false, d.abrechnungsart); });
    h += '</div></div>';
  });
  if (leer) h += '<div class="a-leer">Diese Abrechnung enthält noch keine Posten.</div>';

  if (d.zahlungsdaten && d.zahlungsdaten.iban) {
    h += '<div class="a-hinweis"><strong>Zahlungsdaten:</strong> '
       + esc(d.zahlungsdaten.kontoinhaber) + ' · ' + esc(d.zahlungsdaten.iban)
       + (d.zahlungsdaten.bic ? ' · ' + esc(d.zahlungsdaten.bic) : '') + '</div>';
  }

  $('#d-detail-bd').html(h);
  /* Seit ein Klick daneben nichts mehr schliesst, braucht jede Fusszeile
     einen eigenen Weg hinaus — das Kreuz oben ist klein, und wer die
     Abrechnung nur ansehen wollte, soll nicht danach suchen. */
  var ft = fuss || '';
  if (ft.indexOf('data-zu') === -1) {
    ft = '<button class="a-btn" data-zu>Schließen</button>' + ft;
  }
  $('#d-detail-ft').html(ft);
  dlgAuf('d-detail');
}

$(document).on('click', '.p-detail', function () {
  var id = $(this).data('id');
  ajax('lsv07a_pruef_detail', { abrechnung_id: id }).done(function (r) {
    if (!r || !r.success) return;
    notUebernehmen(r.data, 'pruefen');
    P_OFFEN = id;
    var f = '<button class="a-btn" data-zu>Schließen</button>';
    if (r.data.status === 'eingereicht') {
      f = '<button class="a-btn a-btn-r p-zurueck" data-id="' + id + '">Zurückgeben</button>'
        + '<button class="a-btn a-btn-ok p-ok" data-id="' + id + '">Genehmigen</button>';
    }
    /* Löschen steht für jeden Stand bereit — auch für einen Entwurf, der
       nie eingereicht wurde, und für eine bezahlte Abrechnung. Was erlaubt
       ist, entscheidet der Server; der Knopf fragt nur. */
    f = '<button class="a-btn a-btn-r p-weg" data-id="' + id
      + '" data-status="' + esc(r.data.status || '')
      + '" data-wer="' + esc(r.data.name || '')
      + '" data-zeit="' + esc(zeitraumText(r.data)) + '">Löschen</button>' + f;
    detailAnsicht(r.data, f);
  });
});

/* ── Löschen ────────────────────────────────────────────────────────
   EIN Weg für alle vier Fälle: Quartalsabrechnung und Rechnung, aus
   Prüfung, Kasse und Verwaltung. Was sich unterscheidet, sind drei Dinge —
   welcher Endpunkt, welcher Parameter, was danach neu geladen wird. Die
   steckt der Aufrufer hinein; alles andere steht hier. Vier Kopien dieses
   Dialogs liefen auseinander, und bei einer Löschung merkt man das daran,
   dass etwas weg ist, das nicht weg sein sollte. */
/* „1. Quartal 2026" aus einer Abrechnung. Steht in der Löschabfrage und im
   Protokoll, damit hinterher klar ist, was weg ist. */
function zeitraumText(a) {
  if (!a) return '';
  var q = { Q1: '1. Quartal', Q2: '2. Quartal', Q3: '3. Quartal', Q4: '4. Quartal' };
  return (q[a.quartal] || a.quartal || '') + ' ' + (a.jahr || '');
}

function wegFragen(o) {
  $('#d-weg-titel').text(o.titel || 'Löschen');
  $('#d-weg-was').html(o.was || '');
  /* Ab „eingereicht" ist der Vorgang ein Buchungsbeleg. Das steht vor dem
     Klick da und nicht danach. */
  if (o.warnung) {
    $('#d-weg-warnung').text(o.warnung).prop('hidden', false);
  } else {
    $('#d-weg-warnung').text('').prop('hidden', true);
  }
  $('#d-weg-grund').val('');
  $('#d-weg-ok').data('weg', o).frei('Endgültig löschen');
  dlgAuf('d-weg');
  $('#d-weg-grund').trigger('focus');
}

/* Ab wann gewarnt wird — dieselbe Grenze wie in LSV07A_Loeschung::warnung().
   Sie steht hier ein zweites Mal, weil der Text vor dem Klick dastehen muss
   und die Oberfläche dafür nicht erst fragen kann. Der Server entscheidet
   trotzdem allein; dies ist nur die Ansage. */
function wegWarnung(status, was) {
  if (status === 'entwurf') return '';
  if (was === 'beleg') {
    return 'Diese Rechnung wurde bereits eingereicht. Der Beleg ist damit der '
         + 'Nachweis zu einer gebuchten Zahlung; § 147 AO und § 257 HGB verlangen '
         + 'zehn Jahre Aufbewahrung. Die Rechnung bleibt stehen — ihr Nachweis ist '
         + 'danach weg, auch aus dem Jahrespaket. Das Löschen wird protokolliert '
         + 'und der betroffenen Person mitgeteilt.';
  }
  return 'Dieser Vorgang wurde bereits eingereicht und ist damit ein '
       + 'Buchungsbeleg. § 147 AO und § 257 HGB verlangen zehn Jahre '
       + 'Aufbewahrung. Das Löschen wird protokolliert und der betroffenen '
       + 'Person mitgeteilt.';
}

$(document).on('click', '#d-weg-ok', function () {
  var $b = $(this);
  var o = $b.data('weg') || {};
  var grund = $('#d-weg-grund').val() || '';
  if (!grund.replace(/\s+/g, '')) {
    toast('Bitte schreiben Sie dazu, warum gelöscht wird.', 'fehler');
    $('#d-weg-grund').trigger('focus');
    return;   // der Dialog bleibt stehen, das Getippte auch
  }
  $b.beschaeftigt('Löscht…');
  var daten = { grund: grund };
  daten[o.feld] = o.id;
  ajax(o.aktion, daten).done(function (r) {
    if (r && r.success) {
      toast(r.data.message, 'gut');
      dlgZu('d-weg');
      $.each(o.zu || [], function (i, d) { dlgZu(d); });
      /* Die Antwort wird mitgegeben: Wer danach nur eine Liste neu lädt,
         ignoriert sie; wer den offenen Dialog neu zeichnen will, braucht
         den neuen Stand und soll ihn nicht ein zweites Mal holen. */
      if (typeof o.dann === 'function') o.dann(r);
    }
  }).always(function () { $b.frei('Endgültig löschen'); });
});

$(document).on('click', '.p-weg', function () {
  var $k = $(this);
  wegFragen({
    titel: 'Abrechnung löschen',
    was: 'Gelöscht wird die Quartalsabrechnung von <strong>'
       + esc($k.data('wer') || '') + '</strong> für ' + esc($k.data('zeit') || '')
       + ' samt allen Posten und Notizen.',
    warnung: wegWarnung(String($k.data('status') || '')),
    aktion: 'lsv07a_pruef_loeschen', feld: 'abrechnung_id', id: $k.data('id'),
    zu: ['d-detail'], dann: pruefListe
  });
});

$(document).on('click', '.k-weg', function () {
  var $k = $(this);
  wegFragen({
    titel: 'Abrechnung löschen',
    was: 'Gelöscht wird die Quartalsabrechnung von <strong>'
       + esc($k.data('wer') || '') + '</strong> für ' + esc($k.data('zeit') || '')
       + ' samt allen Posten und Notizen.',
    warnung: wegWarnung(String($k.data('status') || '')),
    aktion: 'lsv07a_kasse_loeschen', feld: 'abrechnung_id', id: $k.data('id'),
    zu: ['d-detail'], dann: kasseListe
  });
});

$(document).on('click', '.p-ok', function () {
  var id = $(this).data('id');
  frage('Abrechnung genehmigen',
    '<p>Danach kann die Kasse sie auszahlen. Ändern lässt sie sich dann nicht mehr.</p>',
    'Genehmigen', function () {
      ajax('lsv07a_pruef_genehmigen', { abrechnung_id: id }).done(function (r) {
        if (r && r.success) {
          toast(r.data.message, 'gut');
          schrittWeiter('genehmigt', 'Genehmigt');
          dlgZu('d-detail'); pruefListe();
        }
      });
    });
});

$(document).on('click', '.p-zurueck', function () {
  var id = $(this).data('id');
  frage('Abrechnung zurückgeben',
    '<div class="a-feld"><label for="p-grund">Was soll geändert werden?</label>'
    + '<textarea id="p-grund" class="a-ctl" rows="3"></textarea></div>',
    'Zurückgeben', function () {
      var grund = $('#p-grund').val() || '';
      ajax('lsv07a_pruef_zurueckgeben', { abrechnung_id: id, grund: grund }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-detail'); pruefListe(); }
      });
    }, 'gefahr');
});

// ════════════════════════════════════════════════════════════════════
//  KASSE
// ════════════════════════════════════════════════════════════════════

function kasseListe() {
  $('#k-liste').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_kasse_liste', {
    jahr: $('#k-jahr').val(), quartal: $('#k-quartal').val(), status: $('#k-status').val()
  }).done(function (r) {
    if (!r || !r.success) return;
    $('#k-summen').html(
      '<div class="a-kachel"><div class="a-kachel-lbl">Offen</div>'
      + '<div class="a-kachel-wert">' + eur(r.data.summe_offen) + '</div>'
      + '<div class="a-kachel-sub">genehmigt, noch nicht gezahlt</div></div>'
      + '<div class="a-kachel"><div class="a-kachel-lbl">Bezahlt</div>'
      + '<div class="a-kachel-wert">' + eur(r.data.summe_bezahlt) + '</div></div>'
      + '<div class="a-kachel ist-gesamt"><div class="a-kachel-lbl">Zusammen</div>'
      + '<div class="a-kachel-wert">' + eur(r.data.summe_offen + r.data.summe_bezahlt) + '</div></div>');

    var z = r.data.zeilen || [];
    if (!z.length) {
      $('#k-liste').html('<div class="a-leer">Nichts offen.</div>');
      return;
    }
    var h = '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
          + '<th>Trainer</th><th>Quartal</th><th>Status</th><th class="a-zahl">Betrag</th>'
          + '<th>Zahlungsdaten</th><th></th></tr></thead><tbody>';
    $.each(z, function (i, a) {
      var konto = a.iban ? (esc(a.kontoinhaber || '—') + '<br><span class="a-z-detail">' + esc(a.iban) + '</span>')
                         : '<span class="a-chip a-chip-rot">fehlen</span>';
      h += '<tr><td data-label="Trainer">' + esc(a.name) + '</td>'
         + '<td data-label="Quartal">' + esc(a.quartal + ' ' + a.jahr) + '</td>'
         + '<td data-label="Status">' + statusChip(a.status, a.status_name) + '</td>'
         + '<td data-label="Betrag" class="a-zahl">' + eur(a.gesamt) + '</td>'
         + '<td data-label="Zahlungsdaten">' + konto + '</td>'
         + '<td class="a-td-akt">'
         + '<button class="a-btn a-btn-klein a-btn-b k-detail" data-id="' + a.id + '">Ansehen</button>'
         + '<button class="a-btn a-btn-klein a-btn-b k-pdf" data-id="' + a.id + '">PDF</button>'
         + (a.status === 'genehmigt'
            ? '<button class="a-btn a-btn-klein a-btn-ok k-bezahlt" data-id="' + a.id + '">Bezahlt</button>'
            : '<button class="a-btn a-btn-klein a-btn-r k-storno" data-id="' + a.id + '">Zurücknehmen</button>')
         + (Z.ist_admin_echt
            ? '<button class="a-btn a-btn-klein a-btn-b a-stand-setzen" data-id="' + a.id + '"'
              + ' data-status="' + esc(a.status) + '">Stand…</button>' : '')
         + '</td></tr>';
    });
    listeSetzen($('#k-liste'), h + '</tbody></table></div>',
      [$('#k-jahr').val(), $('#k-quartal').val(), $('#k-status').val()].join('|'));
  });
}

$(document).on('change', '#k-jahr, #k-quartal, #k-status', function () { kasseListe(); });

$(document).on('click', '.k-detail', function () {
  var id = $(this).data('id');
  ajax('lsv07a_kasse_detail', { abrechnung_id: id }).done(function (r) {
    if (!r || !r.success) return;
    notUebernehmen({ notizen: {}, beanstandet: [] }, 'lesen');
    var f = '<button class="a-btn a-btn-r k-weg" data-id="' + id
          + '" data-status="' + esc(r.data.status || '')
          + '" data-wer="' + esc(r.data.name || '')
          + '" data-zeit="' + esc(zeitraumText(r.data)) + '">Löschen</button>'
          + '<button class="a-btn a-btn-b k-pdf" data-id="' + id + '">Als PDF</button>'
          + (r.data.status === 'genehmigt'
             ? '<button class="a-btn a-btn-ok k-bezahlt" data-id="' + id + '">Als bezahlt markieren</button>'
             : '<button class="a-btn" data-zu>Schließen</button>');
    detailAnsicht(r.data, f);
  });
});

$(document).on('click', '.k-bezahlt', function () {
  var id = $(this).data('id');
  frage('Als bezahlt markieren', '',
    'Als bezahlt markieren', function () {
      ajax('lsv07a_kasse_bezahlt', { abrechnung_id: id }).done(function (r) {
        if (r && r.success) {
          toast(r.data.message, 'gut');
          schrittWeiter('bezahlt', 'Bezahlt');
          dlgZu('d-detail'); kasseListe();
        }
      });
    });
});

$(document).on('click', '.k-storno', function () {
  var id = $(this).data('id');
  frage('Zahlungsvermerk entfernen', '',
    'Entfernen', function () {
      ajax('lsv07a_kasse_storno', { abrechnung_id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); kasseListe(); }
      });
    }, 'gefahr');
});

/* PDF: Es wird ein eigenes Fenster mit sauberem Beleg aufgebaut und der
   Druckdialog geöffnet — dort lässt sich „Als PDF sichern" wählen. So
   braucht es keine zusätzliche Programmbibliothek, und der Beleg sieht
   auf jedem Gerät gleich aus. */
$(document).on('click', '.k-pdf', function () {
  var id = $(this).data('id');
  ajax('lsv07a_kasse_detail', { abrechnung_id: id }).done(function (r) {
    if (!r || !r.success) return;
    belegDrucken(r.data);
  });
});

function belegDrucken(d) {
  var zeilen = '';
  $.each(['training', 'wettkampf', 'fahrt', 'vorbereitung', 'sonstiges'], function (i, t) {
    var liste = d.posten[t] || [];
    if (!liste.length) return;
    zeilen += '<tr class="gruppe"><td colspan="4">' + esc(TYP_NAME[t]) + '</td></tr>';
    $.each(liste, function (j, p) {
      var mitte = '';
      if (t === 'wettkampf') mitte = p.menge + ' × ' + eur(p.satz);
      else if (t === 'fahrt') mitte = zahl(p.menge, 1) + ' km × ' + eur(p.satz) + (p.tage > 1 ? ' × ' + p.tage + ' Tage' : '');
      else if (t === 'sonstiges') mitte = '';
      else mitte = zahl(p.menge) + ' Std × ' + eur(p.satz);
      zeilen += '<tr><td>' + esc(de(p.datum)) + '</td><td>' + esc(p.bezeichnung)
             + (p.notiz ? '<br><span class="klein">' + esc(p.notiz) + '</span>' : '') + '</td>'
             + '<td>' + esc(mitte) + '</td><td class="r">' + eur(p.betrag) + '</td></tr>';
    });
    zeilen += '<tr class="summe"><td colspan="3">Summe ' + esc(TYP_NAME[t]) + '</td>'
           + '<td class="r">' + eur(d.summen[t]) + '</td></tr>';
  });

  var zd = d.zahlungsdaten || {};
  var html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
    + '<title>Abrechnung ' + esc(d.name + ' ' + d.quartal + ' ' + d.jahr) + '</title><style>'
    // Grautöne wie in der Oberfläche, keine Akzentfarbe. Flächen sind nur
    // Beiwerk: Trennlinien tragen die Gliederung, damit der Beleg auch
    // dann lesbar bleibt, wenn der Druckdialog Hintergründe weglässt.
    + 'body{font-family:"Segoe UI",system-ui,-apple-system,sans-serif;color:#201f1e;'
    + 'margin:26mm 18mm;font-size:11pt}'
    + 'h1{font-size:17pt;margin:0 0 2mm;font-weight:600}'
    + '.kopf{display:flex;justify-content:space-between;'
    + 'align-items:flex-start;border-bottom:2px solid #201f1e;padding-bottom:4mm;margin-bottom:6mm}'
    + '.meta{font-size:10pt;color:#605e5c;text-align:right}'
    + 'table{width:100%;border-collapse:collapse;margin-top:3mm}'
    + 'th{text-align:left;font-size:9pt;text-transform:uppercase;letter-spacing:.4px;'
    + 'color:#605e5c;font-weight:600;border-bottom:1px solid #8a8886;padding:2mm 1mm}'
    + 'td{padding:1.8mm 1mm;border-bottom:1px solid #edebe9;vertical-align:top}'
    + '.r{text-align:right;white-space:nowrap}'
    + 'tr.gruppe td{font-weight:600;padding-top:4mm;border-bottom:1px solid #8a8886}'
    + 'tr.summe td{font-weight:600;background:#f3f2f1;border-bottom:1px solid #c8c6c4}'
    + '.klein{font-size:9pt;color:#605e5c}'
    + '.gesamt{margin-top:6mm;display:flex;justify-content:space-between;align-items:baseline;'
    + 'border-top:2px solid #201f1e;padding-top:3mm;font-size:14pt;font-weight:700}'
    + '.block{margin-top:8mm;font-size:10pt;color:#605e5c;line-height:1.6}'
    + '@media print{body{margin:16mm 14mm}'
    + 'tr.summe td{background:transparent}}'
    + '</style></head><body>'
    + '<div class="kopf"><div><h1>Abrechnung</h1>'
    + '<div>' + esc(d.name) + '</div></div>'
    + '<div class="meta">' + (d.verein ? esc(d.verein) + '<br>' : '')
    + esc(d.quartal + ' ' + d.jahr) + '<br>' + esc(d.status_name)
    + (d.genehmigt_am ? '<br>Genehmigt am ' + esc(de(d.genehmigt_am)) : '') + '</div></div>'
    + '<table><thead><tr><th>Datum</th><th>Bezeichnung</th><th>Berechnung</th><th class="r">Betrag</th>'
    + '</tr></thead><tbody>' + zeilen + '</tbody></table>'
    + '<div class="gesamt"><span>Gesamtbetrag</span><span>' + eur(d.gesamt) + '</span></div>'
    + '<div class="block"><strong>Zahlungsdaten</strong><br>'
    + esc(zd.kontoinhaber || '—') + '<br>' + esc(zd.iban || '—')
    + (zd.bic ? ' · ' + esc(zd.bic) : '')
    + (zd.strasse ? '<br>' + esc(zd.strasse) : '')
    + (zd.plz || zd.ort ? '<br>' + esc((zd.plz || '') + ' ' + (zd.ort || '')) : '')
    + '</div>'
    + '<div class="block">Abgerechnet nach ' + esc(d.art_name)
    + ' mit einem Stundensatz von ' + eur(d.stundensatz) + '.</div>'
    + '</body></html>';

  var w = window.open('', '_blank');
  if (!w) {
    toast('Der Browser hat das Fenster für den Beleg blockiert. Bitte Pop-ups für diese Seite erlauben.', 'fehler');
    return;
  }
  w.document.open();
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(function () { try { w.print(); } catch (e) {} }, 350);
}

// ════════════════════════════════════════════════════════════════════
//  STATISTIK
//
//  Drei Sichten, EIN Zeichner: statZeichnen(). Die drei Endpunkte
//  antworten in derselben Form, darum braucht es hier keine drei
//  Fassungen, die auseinanderlaufen können.
//
//  Welche Sichten ein Konto haben darf, steht in statSichten() — an
//  einer Stelle. Maßgeblich bleibt der Server: Er prüft die Rolle
//  erneut und weist mit 403 ab. Diese Liste entscheidet nur, welcher
//  Knopf erscheint, damit niemand auf einen Knopf drückt, der nichts
//  tut.
// ════════════════════════════════════════════════════════════════════

var ST_DATEN = null;

function statSichten() {
  var s = [];
  if (Z.tabs.statistik) s.push({ id: 'eigene', text: 'Meine' });
  if (Z.rollen.indexOf('leiter') >= 0 || Z.ist_admin) s.push({ id: 'abteilung', text: 'Abteilung' });
  if (Z.rollen.indexOf('wart') >= 0 || Z.rollen.indexOf('kasse') >= 0 || Z.ist_admin) {
    s.push({ id: 'alle', text: 'Alle' });
  }
  return s;
}

function statSchalter(aktiv) {
  var s = statSichten();
  var $u = $('#st-umschalter');
  if (s.length < 2) { $u.prop('hidden', true).empty(); return; }
  var h = '';
  $.each(s, function (i, v) {
    h += '<button' + (v.id === aktiv ? ' class="on"' : '') + ' data-st="' + v.id + '">'
       + esc(v.text) + '</button>';
  });
  $u.prop('hidden', false).html(h);
}

function statLaden() {
  var erlaubt = statSichten();
  if (!erlaubt.length) { $('#st-inhalt').html('<div class="a-leer">Keine Statistik für dieses Konto.</div>'); return; }

  var ids = $.map(erlaubt, function (v) { return v.id; });
  if (ids.indexOf(A.stDrin) < 0) A.stDrin = ids[0];
  statSchalter(A.stDrin);

  $('#st-inhalt').html('<div class="a-laden">Wird geladen…</div>');
  var ziel = { eigene: 'lsv07a_stat_eigene', abteilung: 'lsv07a_stat_abteilung',
               alle: 'lsv07a_stat_alle' }[A.stDrin];
  ajax(ziel, { jahr: $('#st-jahr').val() }).done(function (r) {
    if (r && r.success) statZeichnen(r.data);
    else $('#st-inhalt').html('<div class="a-leer">'
      + esc((r && r.data && r.data.message) || 'Die Zahlen konnten nicht geladen werden.') + '</div>');
  });
}

$(document).on('change', '#st-jahr', function () { statLaden(); });
$(document).on('click', '#st-umschalter button', function () {
  A.stDrin = $(this).data('st');
  statLaden();
});

/* ── Bausteine ──────────────────────────────────────────────────────── */

/** Eine Zahl mit ihrer Einheit. null heisst "gibt es nicht". */
function stWert(k) {
  if (k.wert === null || k.wert === undefined) return '–';
  if (k.einheit === 'eur') return eur(k.wert);
  if (k.einheit === 'tage') return zahl(k.wert, 0) + (Math.abs(k.wert) === 1 ? ' Tag' : ' Tage');
  return zahl(k.wert, 0);
}

function stKacheln(liste, ersteBetont) {
  if (!liste || !liste.length) return '';
  var h = '<div class="a-kacheln">';
  $.each(liste, function (i, k) {
    h += '<div class="a-kachel' + (i === 0 && ersteBetont !== false ? ' ist-gesamt' : '') + '">'
       + '<div class="a-kachel-lbl">' + esc(k.lbl) + '</div>'
       + '<div class="a-kachel-wert">' + stWert(k) + '</div>'
       + (k.sub ? '<div class="a-kachel-sub">' + esc(k.sub) + '</div>' : '')
       + '</div>';
  });
  return h + '</div>';
}

/** Ein aufklappbarer Block. Was selten gebraucht wird, liegt zu. */
function stBlock(titel, inhalt, offen) {
  if (!inhalt) return '';
  return '<details class="a-faltung"' + (offen ? ' open' : '') + '>'
       + '<summary>' + esc(titel) + '</summary><div class="a-faltung-bd">'
       + inhalt + '</div></details>';
}

function stKarte(titel, inhalt) {
  if (!inhalt) return '';
  return '<div class="a-karte"><div class="a-karte-hd"><h2>' + esc(titel) + '</h2></div>'
       + '<div class="a-tbl-wrap" style="border:0;border-radius:0">' + inhalt + '</div></div>';
}

/** Balken mit freier Formatierung — Euro, Stunden, Stück. */
function stBalken(werte, namen, fmt, leerText) {
  fmt = fmt || eur;
  var max = 0, irgendwas = false;
  $.each(werte, function (k, v) { var z = parseFloat(v) || 0; if (z > max) max = z; if (z) irgendwas = true; });
  if (!irgendwas) return '<div class="a-leer">' + esc(leerText || 'Dafür gibt es in diesem Jahr keine Zahlen.') + '</div>';
  var h = '<div class="a-karte"><div class="a-karte-bd">';
  $.each(werte, function (k, v) {
    var z = parseFloat(v) || 0;
    var breite = max > 0 ? Math.max(1, Math.round(z / max * 100)) : 1;
    h += '<div class="a-balken">'
       + '<div class="a-balken-kopf"><span>' + esc((namen && namen[k]) || k) + '</span>'
       + '<strong>' + fmt(z) + '</strong></div>'
       + '<div class="a-balken-spur"><div class="a-balken-wert" style="width:' + breite + '%"></div></div>'
       + '</div>';
  });
  return h + '</div></div>';
}

function stTabelle(kopf, zeilen) {
  if (!zeilen || !zeilen.length) return '';
  var h = '<table class="a-tbl"><thead><tr>';
  $.each(kopf, function (i, k) {
    h += '<th' + (k.zahl ? ' class="a-zahl"' : '') + '>' + esc(k.text) + '</th>';
  });
  h += '</tr></thead><tbody>';
  $.each(zeilen, function (i, z) {
    h += '<tr>';
    $.each(z, function (j, feld) {
      h += '<td data-label="' + esc(kopf[j] ? kopf[j].text : '') + '"'
         + (kopf[j] && kopf[j].zahl ? ' class="a-zahl"' : '') + '>' + feld + '</td>';
    });
    h += '</tr>';
  });
  return h + '</tbody></table>';
}

/* Ein Vergleich sagt nur dann etwas, wenn er die Richtung nennt. */
function vergleichKachel(jetzt, vorjahr) {
  if (!vorjahr) return '';
  var diff = jetzt - vorjahr.gesamt, sub;
  if (vorjahr.gesamt <= 0) {
    sub = 'Im Vorjahr wurde nichts abgerechnet.';
  } else {
    var proz = Math.round(Math.abs(diff) / vorjahr.gesamt * 100);
    sub = (diff >= 0 ? '+' : '−') + eur(Math.abs(diff)) + ' (' + proz + ' %) '
        + (diff >= 0 ? 'mehr' : 'weniger') + ' als ' + vorjahr.jahr;
  }
  return '<div class="a-kachel"><div class="a-kachel-lbl">Vorjahr ' + vorjahr.jahr + '</div>'
       + '<div class="a-kachel-wert">' + eur(vorjahr.gesamt) + '</div>'
       + '<div class="a-kachel-sub">' + esc(sub) + '</div></div>';
}

function hochrechnungKachel(h) {
  if (!h) return '';
  if (!h.moeglich) {
    return '<div class="a-kachel"><div class="a-kachel-lbl">Hochrechnung</div>'
         + '<div class="a-kachel-wert">–</div>'
         + '<div class="a-kachel-sub">' + esc(h.grund) + '</div></div>';
  }
  return '<div class="a-kachel"><div class="a-kachel-lbl">Erwartet fürs Jahr</div>'
       + '<div class="a-kachel-wert">' + eur(h.erwartet) + '</div>'
       + '<div class="a-kachel-sub">' + esc('Aus ' + h.quartale
         + (h.quartale === 1 ? ' vollen Quartal' : ' vollen Quartalen') + ' mit '
         + eurRoh(h.bisher)) + '</div></div>';
}

function eurRoh(v) { return eur(v).replace(/<[^>]+>/g, ''); }

/* Quartal gegen Vorjahresquartal — dieselbe Jahreszeit, dieselben
   Bedingungen. Das ist aussagekräftiger als der Vergleich mit dem
   Quartal davor. */
function quartalsVergleich(jetzt, vorjahr) {
  if (!vorjahr || !vorjahr.quartale) return '';
  var zeilen = [];
  $.each(['Q1', 'Q2', 'Q3', 'Q4'], function (i, q) {
    var alt = parseFloat(vorjahr.quartale[q] || 0), neu = parseFloat(jetzt[q] || 0), d = neu - alt;
    zeilen.push([q, eur(alt), eur(neu),
      d === 0 ? '–' : (d > 0 ? '+' : '−') + eur(Math.abs(d))]);
  });
  return stKarte('Quartal gegen Vorjahresquartal', stTabelle(
    [{ text: 'Quartal' }, { text: String(vorjahr.jahr), zahl: true },
     { text: 'heute', zahl: true }, { text: 'Unterschied', zahl: true }], zeilen));
}

/* ── Der eine Zeichner ──────────────────────────────────────────────── */

function statZeichnen(d) {
  ST_DATEN = d;
  var N = d.namen || {};
  var h = stKacheln(d.kennzahlen);

  h += statAbrechnungsteil(d, N);
  h += statRechnungsteil(d, N);
  h += statZeitteil(d);
  h += statStauteil(d);

  h += '<div style="margin-top:14px"><button class="a-btn a-btn-b" id="st-export">'
     + 'Als Tabelle herunterladen (CSV)</button></div>';
  $('#st-inhalt').html(h);
}

/* ── Quartalsabrechnung ─────────────────────────────────────────────── */

function statAbrechnungsteil(d, N) {
  var a = d.abrechnung;
  var h = '<h2 class="a-h2">Quartalsabrechnung</h2>';

  h += stKacheln([
    { lbl: 'Jahr ' + a.jahr, einheit: 'eur', wert: a.gesamt,
      sub: a.anzahl + ' Abrechnung(en)'
         + (a.nachtraege ? ', davon ' + a.nachtraege + ' als Nachtrag' : '') },
    { lbl: 'Stunden', einheit: 'stueck', wert: Math.round(a.stunden),
      sub: 'Training und Vorbereitung' }
  ]);

  // Quartale
  var zeilen = [];
  $.each(a.quartale, function (i, q) {
    zeilen.push([esc(q.quartal), statusChip(q.status, q.status_name),
      eur(q.nach_typ.training), eur(q.nach_typ.wettkampf), eur(q.nach_typ.fahrt),
      eur(q.nach_typ.vorbereitung), eur(q.nach_typ.sonstiges),
      '<strong>' + eur(q.gesamt) + '</strong>']);
  });
  h += stKarte('Quartale', stTabelle([{ text: 'Quartal' }, { text: 'Status' },
    { text: 'Training', zahl: true }, { text: 'Wettkämpfe', zahl: true },
    { text: 'Fahrt', zahl: true }, { text: 'Vorb.', zahl: true },
    { text: 'Sonst.', zahl: true }, { text: 'Gesamt', zahl: true }], zeilen));

  h += '<div class="a-kacheln">' + vergleichKachel(a.gesamt, a.vorjahr)
     + hochrechnungKachel(a.hochrechnung) + '</div>';

  // ── Mengen: Geld allein erklärt nichts ──────────────────────────────
  var m = a.mengen;
  h += stBlock('Mengen — Stunden, Abschnitte, Kilometer', stKacheln([
    { lbl: 'Stunden', einheit: 'stueck', wert: Math.round(m.stunden), sub: 'Training und Vorbereitung' },
    { lbl: 'Abschnitte', einheit: 'stueck', wert: Math.round(m.abschnitte),
      sub: m.wettkaempfe + ' Wettkampfposten' },
    { lbl: 'Kilometer', einheit: 'stueck', wert: Math.round(m.km),
      sub: m.km_hin_rueck ? 'Hin und Rück gezählt' : 'nur einfache Strecke' },
    { lbl: 'Fahrten ohne Erstattung', einheit: 'stueck', wert: m.fahrten_ohne_geld,
      sub: 'von ' + m.fahrten + ', unter der Mindeststrecke' },
    { lbl: 'Trainings mit Wartezeit', einheit: 'stueck', wert: m.wartezeit_posten,
      sub: eurRoh(m.wartezeit_betrag) }
  ], false), true);

  h += stBlock('Wofür das Geld ausgegeben wurde',
    stBalken(a.nach_typ, N.typ) + stBalken(a.nach_status, N.status), true);

  h += stBlock('Nach Monat', stBalken(a.nach_monat, N.monat));

  // Wochentag: nur Stunden-Posten tragen einen
  var wz = [];
  $.each(a.nach_wochentag, function (t, w) {
    if (!w.anzahl) return;
    wz.push([esc(w.name), zahl(w.stunden, 1), w.anzahl, '<strong>' + eur(w.betrag) + '</strong>']);
  });
  h += stBlock('Nach Wochentag', wz.length
    ? stKarte('Nach Wochentag', stTabelle([{ text: 'Tag' }, { text: 'Stunden', zahl: true },
        { text: 'Posten', zahl: true }, { text: 'Betrag', zahl: true }], wz))
    : '');

  // Mannschaft
  var mz = [];
  $.each(a.nach_mannschaft, function (i, x) {
    mz.push([esc(x.name), x.anzahl, x.stunden ? zahl(x.stunden, 1) : '–',
      x.eur_std === null ? '–' : eur(x.eur_std), '<strong>' + eur(x.betrag) + '</strong>']);
  });
  h += stBlock('Nach Mannschaft', stKarte('Nach Mannschaft', stTabelle(
    [{ text: 'Mannschaft' }, { text: 'Posten', zahl: true }, { text: 'Stunden', zahl: true },
     { text: '€ je Stunde', zahl: true }, { text: 'Betrag', zahl: true }], mz)), true);

  // Abteilung
  var az = [];
  $.each(a.nach_abteilung, function (i, x) {
    az.push([esc(x.name), x.anzahl, '<strong>' + eur(x.betrag) + '</strong>']);
  });
  h += stBlock('Nach Abteilung', stKarte('Nach Abteilung', stTabelle(
    [{ text: 'Abteilung' }, { text: 'Posten', zahl: true }, { text: 'Betrag', zahl: true }], az)));

  // Art und Quelle
  var artW = {}, artN = {};
  $.each(a.nach_art, function (k, v) { artW[k] = v.betrag; artN[k] = (N.art && N.art[k]) || k; });
  var qW = {}, qN = { intern: 'Automatisch übernommen', manuell: 'Von Hand erfasst' };
  $.each(a.nach_quelle, function (k, v) { qW[k] = v.betrag; if (!qN[k]) qN[k] = k; });
  h += stBlock('Abrechnungsart und Herkunft',
    stBalken(artW, artN, eur, 'Keine Beträge in diesem Jahr.')
    + stBalken(qW, qN, eur, 'Keine Beträge in diesem Jahr.'));

  // Qualität
  var p = a.plan_ist;
  var qual = stKacheln([
    { lbl: 'Beanstandete Posten', einheit: 'stueck', wert: a.beanstandet.anzahl,
      sub: eurRoh(a.beanstandet.betrag) },
    { lbl: 'Gerade zurückgegeben', einheit: 'stueck', wert: a.zurueck.anzahl,
      sub: a.zurueck.anteil === null ? 'keine Abrechnungen'
         : a.zurueck.anteil + ' % der Abrechnungen des Jahres' },
    { lbl: 'Posten mit Plan', einheit: 'stueck', wert: p.anzahl,
      sub: p.abweichend + ' weichen ab' },
    { lbl: 'Stunden gegen Plan', einheit: 'stueck', wert: Math.round(p.differenz),
      sub: p.anzahl ? zahl(p.ist, 1) + ' statt ' + zahl(p.plan, 1) + ' Std laut Plan'
         : 'kein Posten kam aus dem Trainingsplan' }
  ], false);
  h += stBlock('Qualität — Beanstandungen, Rückgaben, Plan gegen Ist', qual, true);

  // Personen (nicht in der eigenen Sicht — da ist es nur man selbst)
  if (d.sicht !== 'eigene' && a.personen.length) {
    var pz = [];
    $.each(a.personen, function (i, x) {
      pz.push([esc(x.name), eur(x.nach_typ.training), eur(x.nach_typ.wettkampf),
        eur(x.nach_typ.fahrt), eur(x.nach_typ.vorbereitung), eur(x.nach_typ.sonstiges),
        zahl(x.stunden, 1), '<strong>' + eur(x.gesamt) + '</strong>']);
    });
    h += stBlock('Nach Person (' + a.personen.length + ')', stKarte('Nach Person', stTabelle(
      [{ text: 'Name' }, { text: 'Training', zahl: true }, { text: 'Wettkämpfe', zahl: true },
       { text: 'Fahrt', zahl: true }, { text: 'Vorb.', zahl: true }, { text: 'Sonst.', zahl: true },
       { text: 'Stunden', zahl: true }, { text: 'Gesamt', zahl: true }], pz)), true);
  }

  h += quartalsVergleich(a.je_quartal, a.vorjahr);
  return h;
}

/* ── Rechnungen ─────────────────────────────────────────────────────── */

function statRechnungsteil(d, N) {
  var r = d.rechnung;
  if (!r) return '';
  var h = '<h2 class="a-h2">Rechnungen</h2>';

  h += stKacheln([
    { lbl: 'Jahr ' + r.jahr, einheit: 'eur', wert: r.gesamt, sub: r.anzahl + ' Rechnung(en)' },
    { lbl: 'Ohne Beleg', einheit: 'stueck', wert: r.ohne_beleg.anzahl,
      sub: eurRoh(r.ohne_beleg.betrag) },
    { lbl: 'An Dritte', einheit: 'eur', wert: r.empfaenger.dritte.betrag,
      sub: r.empfaenger.dritte.anzahl + ' Rechnung(en); der Rest ist Erstattung an die einreichende Person' },
    { lbl: 'Belege', einheit: 'stueck', wert: r.belege.anzahl,
      sub: statBytes(r.belege.bytes) + ' auf der Platte' }
  ]);

  h += '<div class="a-kacheln">' + vergleichKachel(r.gesamt, r.vorjahr)
     + hochrechnungKachel(r.hochrechnung) + '</div>';

  h += stBlock('Nach Stand', stBalken(r.nach_status, N.status), true);

  var az = [];
  $.each(r.nach_abteilung, function (i, x) {
    az.push([esc(x.name), x.anzahl, '<strong>' + eur(x.betrag) + '</strong>']);
  });
  h += stBlock('Nach Abteilung', stKarte('Nach Abteilung', stTabelle(
    [{ text: 'Abteilung' }, { text: 'Anzahl', zahl: true }, { text: 'Betrag', zahl: true }], az)), true);

  var zz = [];
  $.each(r.nach_zweck, function (i, x) {
    zz.push([esc(x.zweck), x.anzahl, '<strong>' + eur(x.betrag) + '</strong>']);
  });
  h += stBlock('Nach Zweck', stKarte('Nach Zweck', stTabelle(
    [{ text: 'Zweck' }, { text: 'Anzahl', zahl: true }, { text: 'Betrag', zahl: true }], zz)));

  h += stBlock('Nach Monat (Rechnungsdatum)', stBalken(r.nach_monat, N.monat));

  h += stBlock('Ausgezahlt (nach Auszahlungsdatum)', stBalken(r.bezahlt_monat, N.monat, eur,
      'In diesem Jahr wurde noch keine Rechnung ausgezahlt.'));

  // Ohne Beleg nach Stand — eine genehmigte Rechnung ohne Beleg ist ein Problem
  var oz = [];
  $.each(r.ohne_beleg.nach_status, function (k, v) {
    if (!v) return;
    oz.push([statusChip(k, (N.status && N.status[k]) || k), v]);
  });
  h += stBlock('Rechnungen ohne Beleg', oz.length
    ? stKarte('Ohne Beleg', stTabelle([{ text: 'Stand' }, { text: 'Anzahl', zahl: true }], oz))
    : '<div class="a-leer">Jede Rechnung hat mindestens einen Beleg.</div>', true);

  if (d.sicht !== 'eigene' && r.personen.length) {
    var pz = [];
    $.each(r.personen, function (i, x) {
      pz.push([esc(x.name), x.anzahl, '<strong>' + eur(x.betrag) + '</strong>']);
    });
    h += stBlock('Nach Person (' + r.personen.length + ')', stKarte('Nach Person', stTabelle(
      [{ text: 'Name' }, { text: 'Anzahl', zahl: true }, { text: 'Betrag', zahl: true }], pz)));
  }
  return h;
}

function statBytes(b) {
  b = parseInt(b, 10) || 0;
  if (b < 1024) return b + ' B';
  if (b < 1024 * 1024) return zahl(b / 1024, 0) + ' kB';
  return zahl(b / 1024 / 1024, 1) + ' MB';
}

/* ── Zeit ───────────────────────────────────────────────────────────── */

function statZeitteil(d) {
  var h = '<h2 class="a-h2">Wie lange es dauert</h2>';
  h += statLaufzeit('Quartalsabrechnung', d.durchlauf.abrechnung);
  if (d.durchlauf.rechnung) h += statLaufzeit('Rechnungen', d.durchlauf.rechnung);
  return h;
}

function statLaufzeit(titel, w) {
  var h = stKacheln([
    { lbl: 'Einreichen bis Genehmigung', einheit: 'tage', wert: w.pruefung.median,
      sub: w.pruefung.anzahl + ' Vorgang/Vorgänge' },
    { lbl: 'Genehmigung bis Geld', einheit: 'tage', wert: w.zahlung.median,
      sub: w.zahlung.anzahl + ' Vorgang/Vorgänge' },
    { lbl: 'Einreichen bis Geld', einheit: 'tage', wert: w.gesamt.median,
      sub: 'der ganze Weg' }
  ], false);

  var zeilen = [];
  var lbl = { eingereicht: 'Wartet auf Prüfung', genehmigt: 'Wartet auf Geld',
              zurueck: 'Zurückgegeben, liegt bei der Person', entwurf: 'Noch im Entwurf' };
  $.each(['eingereicht', 'genehmigt', 'zurueck', 'entwurf'], function (i, k) {
    var o = w.offen[k];
    if (!o || !o.anzahl) return;
    zeilen.push([esc(lbl[k]), o.anzahl, eur(o.betrag),
      o.aeltester_tage === null ? '–' : zahl(o.aeltester_tage, 0) + (o.aeltester_tage === 1 ? ' Tag' : ' Tage'),
      o.aeltester_am ? de(o.aeltester_am) : '–']);
  });
  h += zeilen.length
    ? stKarte(titel + ' — was gerade liegt', stTabelle([{ text: 'Stufe' },
        { text: 'Anzahl', zahl: true }, { text: 'Betrag', zahl: true },
        { text: 'Ältester', zahl: true }, { text: 'seit', zahl: true }], zeilen))
    : '<div class="a-leer">Bei ' + esc(titel) + ' liegt gerade nichts.</div>';
  return stBlock(titel, h, true);
}

/* ── Rückstau ───────────────────────────────────────────────────────── */

function statStauteil(d) {
  var s = d.rueckstau;
  var h = '<h2 class="a-h2">Was liegengeblieben ist</h2>';

  h += stKacheln([
    { lbl: 'Im Entwurf (Abrechnung)', einheit: 'eur', wert: s.entwuerfe.betrag,
      sub: s.entwuerfe.anzahl + ' Abrechnung(en), erfasst aber nicht eingereicht' },
    s.rechnung_entwuerfe
      ? { lbl: 'Im Entwurf (Rechnungen)', einheit: 'eur', wert: s.rechnung_entwuerfe.betrag,
          sub: s.rechnung_entwuerfe.anzahl + ' Rechnung(en)' }
      : { lbl: 'Im Entwurf (Rechnungen)', einheit: 'eur', wert: null,
          sub: 'für diese Rolle nicht sichtbar' },
    { lbl: 'Trainerkonten', einheit: 'stueck', wert: s.soll, sub: 'Grundmenge der nächsten Tabelle' },
    { lbl: 'Ausgeschlossene Übernahmen', einheit: 'stueck', wert: s.ausgeschlossen,
      sub: 'bewusst aus einer Abrechnung entfernt' }
  ]);

  var zeilen = [];
  $.each(s.fehlt, function (i, f) {
    zeilen.push([esc(f.quartal), f.anzahl,
      f.personen.length
        ? esc($.map(f.personen, function (p) { return p.name; }).join(', '))
        : (f.anzahl ? '<span class="a-leer" style="padding:0">Namen stehen nur in den Sichten '
            + 'Abteilung und Alle</span>' : '–')]);
  });
  h += stBlock('Wer für ein Quartal nichts hat', stKarte('Ohne Abrechnung', stTabelle(
      [{ text: 'Quartal' }, { text: 'Anzahl', zahl: true }, { text: 'Wer' }], zeilen)), true);

  // Noch nicht übernommen — nur je Person
  var nu = d.nicht_uebernommen;
  if (nu) {
    h += stBlock('Noch nicht übernommen', nu.moeglich
      ? stKacheln([
          { lbl: 'Trainings', einheit: 'stueck', wert: nu.trainings,
            sub: 'stehen im internen Bereich, aber in keiner Abrechnung' },
          { lbl: 'Wettkampftage', einheit: 'stueck', wert: nu.wettkampftage,
            sub: 'dasselbe für Wettkämpfe' },
          { lbl: 'Bewusst ausgeschlossen', einheit: 'stueck', wert: nu.ausgeschlossen,
            sub: 'einmal entfernt, holt die Automatik nicht zurück' }
        ], false)
      : '<div class="a-leer">' + esc(nu.grund) + '</div>', true);
  }
  return h;
}

/* ── Export ───────────────────────────────────────────────────────────
   Die Tabelle entsteht im Browser aus den Zahlen, die ohnehin schon da
   sind — dafür braucht es keinen weiteren Weg nach draussen. Semikolon
   und das Byte am Anfang sind für Excel: Ohne beides landet alles in
   einer Spalte und Umlaute werden zu Kauderwelsch. */
function csvFeld(v) {
  var t = String(v === null || v === undefined ? '' : v);
  return '"' + t.replace(/"/g, '""') + '"';
}

function csvLaden(name, zeilen) {
  var text = zeilen.map(function (z) { return z.map(csvFeld).join(';'); }).join('\r\n');
  var blob = new Blob(['﻿' + text], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

$(document).on('click', '#st-export', function () {
  var d = ST_DATEN;
  if (!d) return;
  var a = d.abrechnung, r = d.rechnung, N = d.namen || {};
  var z = [];
  z.push(['Statistik', d.beschriftung + ', Jahr ' + d.jahr]);
  z.push([]);

  z.push(['Kennzahlen']);
  $.each(d.kennzahlen, function (i, k) {
    z.push([k.lbl, k.wert === null ? '' : k.wert, k.einheit, k.sub]);
  });

  z.push([]); z.push(['Quartalsabrechnung — Quartale']);
  z.push(['Quartal', 'Stand', 'Training', 'Wettkämpfe', 'Fahrt', 'Vorbereitung', 'Sonstiges', 'Gesamt']);
  $.each(a.quartale, function (i, q) {
    z.push([q.quartal, q.status_name, q.nach_typ.training, q.nach_typ.wettkampf, q.nach_typ.fahrt,
            q.nach_typ.vorbereitung, q.nach_typ.sonstiges, q.gesamt]);
  });

  z.push([]); z.push(['Nach Monat']);
  $.each(a.nach_monat, function (m, v) { z.push([(N.monat && N.monat[m]) || m, v]); });

  z.push([]); z.push(['Nach Wochentag', 'Stunden', 'Posten', 'Betrag']);
  $.each(a.nach_wochentag, function (t, w) { if (w.anzahl) z.push([w.name, w.stunden, w.anzahl, w.betrag]); });

  z.push([]); z.push(['Nach Mannschaft', 'Posten', 'Stunden', 'Euro je Stunde', 'Betrag']);
  $.each(a.nach_mannschaft, function (i, m) {
    z.push([m.name, m.anzahl, m.stunden, m.eur_std === null ? '' : m.eur_std, m.betrag]);
  });

  z.push([]); z.push(['Nach Abteilung', 'Posten', 'Betrag']);
  $.each(a.nach_abteilung, function (i, x) { z.push([x.name, x.anzahl, x.betrag]); });

  z.push([]); z.push(['Mengen']);
  z.push(['Stunden', a.mengen.stunden]);
  z.push(['Abschnitte', a.mengen.abschnitte]);
  z.push(['Kilometer', a.mengen.km]);
  z.push(['Fahrten', a.mengen.fahrten]);
  z.push(['Fahrten ohne Erstattung', a.mengen.fahrten_ohne_geld]);
  z.push(['Trainings mit Wartezeit', a.mengen.wartezeit_posten, a.mengen.wartezeit_betrag]);

  z.push([]); z.push(['Qualität']);
  z.push(['Beanstandete Posten', a.beanstandet.anzahl, a.beanstandet.betrag]);
  z.push(['Gerade zurückgegeben', a.zurueck.anzahl, a.zurueck.anteil === null ? '' : a.zurueck.anteil + ' %']);
  z.push(['Posten mit Plan', a.plan_ist.anzahl, 'davon abweichend', a.plan_ist.abweichend]);
  z.push(['Stunden Ist', a.plan_ist.ist, 'Stunden Plan', a.plan_ist.plan]);

  if (a.personen.length) {
    z.push([]); z.push(['Nach Person', 'Training', 'Wettkämpfe', 'Fahrt', 'Vorbereitung',
                        'Sonstiges', 'Stunden', 'Gesamt']);
    $.each(a.personen, function (i, p) {
      z.push([p.name, p.nach_typ.training, p.nach_typ.wettkampf, p.nach_typ.fahrt,
              p.nach_typ.vorbereitung, p.nach_typ.sonstiges, p.stunden, p.gesamt]);
    });
  }

  z.push([]); z.push(['Vorjahr ' + a.vorjahr.jahr, a.vorjahr.gesamt]);

  if (r) {
    z.push([]); z.push(['Rechnungen', 'Jahr ' + r.jahr, r.gesamt, r.anzahl + ' Stück']);
    z.push(['Nach Stand']);
    $.each(r.nach_status, function (k, v) { z.push([(N.status && N.status[k]) || k, v]); });
    z.push([]); z.push(['Rechnungen nach Abteilung', 'Anzahl', 'Betrag']);
    $.each(r.nach_abteilung, function (i, x) { z.push([x.name, x.anzahl, x.betrag]); });
    z.push([]); z.push(['Rechnungen nach Zweck', 'Anzahl', 'Betrag']);
    $.each(r.nach_zweck, function (i, x) { z.push([x.zweck, x.anzahl, x.betrag]); });
    z.push([]); z.push(['Rechnungen nach Monat (Rechnungsdatum)']);
    $.each(r.nach_monat, function (m, v) { z.push([(N.monat && N.monat[m]) || m, v]); });
    z.push([]); z.push(['Ausgezahlt nach Monat']);
    $.each(r.bezahlt_monat, function (m, v) { z.push([(N.monat && N.monat[m]) || m, v]); });
    z.push([]);
    z.push(['An Dritte', r.empfaenger.dritte.anzahl, r.empfaenger.dritte.betrag]);
    z.push(['Erstattung an die einreichende Person', r.empfaenger.eigen.anzahl, r.empfaenger.eigen.betrag]);
    z.push(['Rechnungen ohne Beleg', r.ohne_beleg.anzahl, r.ohne_beleg.betrag]);
    z.push(['Belege', r.belege.anzahl, r.belege.bytes + ' Bytes']);
    z.push(['Vorjahr ' + r.vorjahr.jahr, r.vorjahr.gesamt]);
  }

  z.push([]); z.push(['Laufzeiten (Median, Tage)']);
  z.push(['Abrechnung: Einreichen bis Genehmigung', d.durchlauf.abrechnung.pruefung.median]);
  z.push(['Abrechnung: Genehmigung bis Geld', d.durchlauf.abrechnung.zahlung.median]);
  if (d.durchlauf.rechnung) {
    z.push(['Rechnung: Einreichen bis Genehmigung', d.durchlauf.rechnung.pruefung.median]);
    z.push(['Rechnung: Genehmigung bis Geld', d.durchlauf.rechnung.zahlung.median]);
  }
  z.push([]); z.push(['Was gerade liegt', 'Anzahl', 'Betrag', 'Ältester (Tage)', 'seit']);
  $.each(d.durchlauf.abrechnung.offen, function (k, o) {
    z.push(['Abrechnung ' + k, o.anzahl, o.betrag, o.aeltester_tage === null ? '' : o.aeltester_tage,
            o.aeltester_am || '']);
  });
  if (d.durchlauf.rechnung) {
    $.each(d.durchlauf.rechnung.offen, function (k, o) {
      z.push(['Rechnung ' + k, o.anzahl, o.betrag, o.aeltester_tage === null ? '' : o.aeltester_tage,
              o.aeltester_am || '']);
    });
  }

  z.push([]); z.push(['Rückstau']);
  z.push(['Im Entwurf (Abrechnung)', d.rueckstau.entwuerfe.anzahl, d.rueckstau.entwuerfe.betrag]);
  if (d.rueckstau.rechnung_entwuerfe) {
    z.push(['Im Entwurf (Rechnungen)', d.rueckstau.rechnung_entwuerfe.anzahl,
            d.rueckstau.rechnung_entwuerfe.betrag]);
  }
  z.push(['Trainerkonten', d.rueckstau.soll]);
  z.push(['Ausgeschlossene Übernahmen', d.rueckstau.ausgeschlossen]);
  z.push([]); z.push(['Ohne Abrechnung', 'Anzahl', 'Wer']);
  $.each(d.rueckstau.fehlt, function (i, f) {
    z.push([f.quartal, f.anzahl, $.map(f.personen, function (p) { return p.name; }).join(', ')]);
  });

  /* Zahlen mit Komma, damit Excel sie als Zahlen erkennt. */
  var fertig = z.map(function (zeile) {
    return zeile.map(function (w) {
      return (typeof w === 'number') ? Number(w).toFixed(2).replace('.', ',') : w;
    });
  });
  csvLaden('Abrechnung-Statistik-' + d.jahr + '-' + d.sicht + '.csv', fertig);
  toast('Tabelle heruntergeladen.', 'gut');
});

// ════════════════════════════════════════════════════════════════════
//  ZAHLUNGSDATEN
// ════════════════════════════════════════════════════════════════════

function zdLaden() {
  ajax('lsv07a_zahlungsdaten_get').done(function (r) {
    if (!r || !r.success) return;
    var d = r.data;
    $('#z-inhaber').val(d.kontoinhaber); $('#z-iban').val(d.iban); $('#z-bic').val(d.bic);
    $('#z-strasse').val(d.strasse); $('#z-plz').val(d.plz); $('#z-ort').val(d.ort);
    $('#z-auto').prop('checked', !!d.auto_training);
    $('#z-konditionen').html('Ihre Abrechnung läuft über <strong>' + esc(d.art_name)
      + '</strong> mit einem Stundensatz von <strong>' + eur(d.stundensatz)
      + '</strong>. Beides legt die Administration fest.');
  });
}

$(document).on('click', '#z-auto-save', function () {
  var $b = $(this).beschaeftigt('Speichert…');
  ajax('lsv07a_einstellung_save', { auto_training: $('#z-auto').is(':checked') ? 1 : 0 })
    .done(function (r) { if (r && r.success) toast(r.data.message, 'gut'); })
    .always(function () { $b.frei('Speichern'); });
});

$('#z-speichern').on('click', function () {
  var $b = $(this).beschaeftigt('Speichert…');
  ajax('lsv07a_zahlungsdaten_save', {
    kontoinhaber: $('#z-inhaber').val(), iban: $('#z-iban').val(), bic: $('#z-bic').val(),
    strasse: $('#z-strasse').val(), plz: $('#z-plz').val(), ort: $('#z-ort').val()
  }).done(function (r) {
    if (r && r.success) { toast(r.data.message, 'gut'); geladen.eigene = false; }
  }).always(function () { $b.frei('Speichern'); });
});

// ════════════════════════════════════════════════════════════════════
//  VERWALTUNG
// ════════════════════════════════════════════════════════════════════

$(document).on('click', '#v-reiter button', function () {
  var v = $(this).data('v');
  $('#v-reiter button').removeClass('on');
  $(this).addClass('on');
  $('.a-vteil').removeClass('on');
  $('#v-' + v).addClass('on');
  if (v === 'konten')          vKonten();
  else if (v === 'abteilungen') vAbteilungen();
  else if (v === 'saetze')     vSaetze();
  else if (v === 'pauschalen') vPauschalen();
  else if (v === 'saisons')    vSaisons();
  else if (v === 'zeiten')     vZeiten();
  else if (v === 'mail')       vMail();
  else if (v === 'ansicht')    vAnsicht();
  else if (v === 'mitteilung') vMitteilung();
  else if (v === 'protokoll')  vProtokoll();
});

/* ════════════════════════════════════════════════════════════════════
 *  ZUSTÄNDIGKEIT — wer prüft welche Mannschaften
 * ════════════════════════════════════════════════════════════════════ */

/* Der Reiter „Zuständigkeit" ist entfallen: Über die Abteilungsstruktur
   mit ihren Leitungen wird heute entschieden, wer genehmigt. Die
   Zuständigkeit der Warte für die QUARTALSPRÜFUNG gibt es im Datenmodell
   weiter — damit laufende Prüfungen nicht abreissen —, gepflegt wird sie
   aber im Kontoformular unter Kontoverwaltung. */

var ROLLEN_NAME = { trainer: 'Trainer', wart: 'Wart', leiter: 'Abteilungsleitung',
                    kasse: 'Kasse', admin: 'Administrator' };

function vKonten() {
  $('#v-konten').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_konten').done(function (r) {
    if (!r || !r.success) return;
    var k = r.data.konten || [];
    var h = '';
    if (r.data.hinweis_intern) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis_intern) + '</div>';
    h += '<div class="a-karte"><div class="a-karte-hd"><h2>Konten</h2>'
       + '<button class="a-btn a-btn-klein a-btn-p" id="v-konto-neu">+ Konto aufnehmen</button></div>'
    if (!k.length) {
      h += '<div class="a-karte-bd"><div class="a-leer">Noch kein Konto aufgenommen.</div></div>';
    } else {
      h += '<div class="a-tbl-wrap" style="border:0;border-radius:0"><table class="a-tbl"><thead><tr>'
         + '<th>Name</th><th>Rollen</th><th>Abteilung</th><th class="a-zahl">Stundensatz</th>'
         + '<th>Abrechnungsart</th><th>Trainer-Profil</th><th></th></tr></thead><tbody>';
      $.each(k, function (i, p) {
        var rollen = $.map(p.rollen, function (x) {
          return '<span class="a-chip a-chip-' + (x === 'admin' ? 'rot' : x === 'wart' ? 'gelb'
                 : x === 'kasse' ? 'gruen' : x === 'leiter' ? 'lila' : 'blau')
                 + '">' + esc(ROLLEN_NAME[x] || x) + '</span>';
        }).join(' ');
        h += '<tr><td data-label="Name">' + esc(p.name)
           + (p.existiert ? '' : ' <span class="a-chip a-chip-rot">Konto gelöscht</span>') + '</td>'
           + '<td data-label="Rollen">' + rollen + '</td>'
           + '<td data-label="Abteilung">' + (p.abteilung
               ? esc(p.abteilung)
               : '<span class="a-chip a-chip-rot">keine</span>') + '</td>'
           + '<td data-label="Stundensatz" class="a-zahl">' + eur(p.stundensatz) + '</td>'
           + '<td data-label="Abrechnungsart">' + esc(p.art_name)
           + (p.auto_training ? ' <span class="a-chip a-chip-grau">automatisch</span>' : '') + '</td>'
           + '<td data-label="Trainer-Profil">' + (p.trainer_id
               ? '<span class="a-chip a-chip-gruen">verknüpft</span>'
               : '<span class="a-chip a-chip-grau">keines</span>') + '</td>'
           + '<td class="a-td-akt"><button class="a-btn a-btn-klein v-konto-bearb" '
           + 'data-uid="' + p.wp_user_id + '">Bearbeiten</button></td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</div>';
    listeSetzen($('#v-konten'), h, 'konten');
    A.konten = k;
    A.abteilungen = r.data.abteilungen || [];
    A.mannschaften = r.data.mannschaften || [];
  });
}

function kontoDialog(p) {
  var daten = p || { wp_user_id: 0, rollen: [], stundensatz: 0, abrechnungsart: 'zeiten', aktiv: 1 };
  var h = '';
  if (!p) {
    h += '<div class="a-feld"><label for="kf-suche">WordPress-Konto suchen</label>'
       + '<input type="text" id="kf-suche" class="a-ctl" placeholder="Name oder E-Mail">'
       + '<div id="kf-treffer"></div>'
       + '<input type="hidden" id="kf-uid" value="0">';
  } else {
    h += '<input type="hidden" id="kf-uid" value="' + p.wp_user_id + '">'
       + '<div class="a-hinweis">' + esc(p.name) + (p.email ? ' · ' + esc(p.email) : '') + '</div>';
  }
  h += '<div class="a-feld"><label>Rollen</label>';
  $.each(['trainer', 'wart', 'leiter', 'kasse', 'admin'], function (i, rolle) {
    h += '<div class="a-schalter-zeile"><input type="checkbox" class="kf-rolle" id="kf-r-' + rolle + '" '
       + 'value="' + rolle + '"' + (daten.rollen.indexOf(rolle) >= 0 ? ' checked' : '') + '>'
       + '<label for="kf-r-' + rolle + '">' + ROLLEN_NAME[rolle] + '</label></div>';
  });
  h += '</div>'
     + '<div class="a-zwei">'
     + '<div class="a-feld"><label for="kf-satz">Stundensatz (€)</label>'
     + '<input type="number" id="kf-satz" class="a-ctl" step="0.5" min="0" inputmode="decimal" '
     + 'value="' + esc(daten.stundensatz) + '"></div>'
     + '<div class="a-feld"><label for="kf-art">Abrechnung des Trainings</label>'
     + '<select id="kf-art" class="a-ctl">';
  $.each(LSV07A.arten, function (i, a) {
    h += '<option value="' + esc(a.wert) + '"' + (daten.abrechnungsart === a.wert ? ' selected' : '') + '>'
       + esc(a.name) + '</option>';
  });
  h += '</select></div></div>'
     + '<div class="a-feld"><label for="kf-mail">E-Mail für Mitteilungen <span class="a-opt">optional</span></label>'
     + '<input type="email" id="kf-mail" class="a-ctl" autocomplete="off" '
     + 'placeholder="' + esc(daten.email || 'Adresse des WordPress-Kontos') + '" '
     + 'value="' + esc(daten.mail || '') + '"></div>';

  /* Die Abteilung gehört zum Konto — sie entscheidet, wer dessen
     Rechnungen genehmigt. */
  h += '<div class="a-feld"><label for="kf-abteilung">Abteilung</label>'
     + '<select id="kf-abteilung" class="a-ctl">'
     + '<option value="0">— keine —</option>'
     + $.map(A.abteilungen || [], function (a) {
         return '<option value="' + a.id + '"'
              + (a.id === daten.abteilung_id ? ' selected' : '') + '>' + esc(a.name) + '</option>';
       }).join('')
     + '</select>'
     + '<div class="a-z-detail">Ihre Leitung genehmigt die Rechnungen dieses Kontos. '
     + 'Ohne Abteilung lässt sich keine einreichen.</div></div>';

  /* Die Zuständigkeit für die Quartalsprüfung — nur bei einem Wart, und
     nur dort, wo sie etwas bewirkt. Einen eigenen Reiter dafür gibt es
     nicht mehr: Die Abteilungsstruktur ist der Weg, über den heute
     entschieden wird. */
  var istWart = daten.rollen.indexOf('wart') >= 0;
  h += '<div id="kf-bereiche-block"' + (istWart ? '' : ' hidden') + '>'
     + '<h3 class="a-h3">Zuständigkeit für die Quartalsprüfung</h3>'
     + '<div class="a-z-detail" style="margin-bottom:8px">Keine Mannschaft angekreuzt heisst '
     + '<strong>alle</strong>. Für die einzelnen Rechnungen gilt das nicht — dort entscheidet '
     + 'die Abteilungsleitung.</div>';
  if (!(A.mannschaften || []).length) {
    h += '<div class="a-leer">Der interne Bereich meldet keine Mannschaften.</div>';
  } else {
    var drin = {};
    $.each(daten.bereiche || [], function (i, b) { drin[parseInt(b.id || b, 10)] = 1; });
    h += '<div class="a-kaestchen">';
    $.each(A.mannschaften, function (i, m) {
      h += '<div class="a-schalter-zeile"><input type="checkbox" class="kf-bereich" '
         + 'id="kf-b-' + m.id + '" value="' + m.id + '"' + (drin[m.id] ? ' checked' : '') + '>'
         + '<label for="kf-b-' + m.id + '">' + esc(m.name) + '</label></div>';
    });
    h += '</div>';
  }
  h += '</div>';
  if (daten.wp_user_id) {
    h += '<div class="a-feld" style="margin-top:12px"><label>Persönliche Pauschalen</label>'
       + '<button class="a-btn a-btn-b pp-oeffnen" data-uid="' + daten.wp_user_id + '">Pauschalen dieser Person…</button>'
       + '</div>';
  }
  return h;
}

$(document).on('click', '#v-konto-neu', function () {
  $('#d-posten-titel').text('Konto aufnehmen');
  $('#d-posten-bd').html(kontoDialog(null));
  $('#d-posten-ok').off('click').on('click', kontoSpeichern).frei('Speichern');
  dlgAuf('d-posten');
});

$(document).on('click', '.v-konto-bearb', function () {
  var uid = parseInt($(this).data('uid'), 10);
  var p = null;
  $.each(A.konten || [], function (i, k) { if (k.wp_user_id === uid) p = k; });
  if (!p) return;
  $('#d-posten-titel').text('Konto bearbeiten');
  $('#d-posten-bd').html(kontoDialog(p));
  $('#d-posten-ok').off('click').on('click', kontoSpeichern).frei('Speichern');
  dlgAuf('d-posten');
});

var _sucheZeit;
$(document).on('input', '#kf-suche', function () {
  var q = $(this).val();
  clearTimeout(_sucheZeit);
  _sucheZeit = setTimeout(function () {
    ajax('lsv07a_adm_wp_konten', { suche: q }).done(function (r) {
      if (!r || !r.success) return;
      var h = '';
      $.each(r.data, function (i, u) {
        h += '<label class="a-wahl kf-treffer-zeile" data-uid="' + u.wp_user_id + '">'
           + '<span class="a-wahl-txt"><span class="a-wahl-name">' + esc(u.name) + '</span>'
           + '<span class="a-wahl-sub">' + esc(u.email)
           + (u.rollen.length ? ' · schon aufgenommen' : '') + '</span></span></label>';
      });
      $('#kf-treffer').html(h || '<div class="a-leer">Nichts gefunden.</div>');
    });
  }, 260);
});

$(document).on('click', '.kf-treffer-zeile', function () {
  $('.kf-treffer-zeile').removeClass('ist-gewaehlt');
  $(this).addClass('ist-gewaehlt');
  $('#kf-uid').val($(this).data('uid'));
});

/* Der Zuständigkeitsblock erscheint und verschwindet mit der Rolle. */
$(document).on('change', '#kf-r-wart', function () {
  $('#kf-bereiche-block').prop('hidden', !$(this).is(':checked'));
});

function kontoSpeichern() {
  var uid = parseInt($('#kf-uid').val(), 10) || 0;
  if (!uid) { toast('Bitte zuerst ein WordPress-Konto auswählen.', 'fehler'); return; }
  var rollen = [];
  $('.kf-rolle:checked').each(function () { rollen.push($(this).val()); });
  var $b = $(this).beschaeftigt('Speichert…');
  var bereiche = [];
  $('.kf-bereich:checked').each(function () { bereiche.push(parseInt($(this).val(), 10)); });

  ajax('lsv07a_adm_konto_speichern', {
    wp_user_id: uid, rollen: JSON.stringify(rollen),
    mail: $('#kf-mail').val() || '',
    stundensatz: $('#kf-satz').val(), abrechnungsart: $('#kf-art').val(), aktiv: 1,
    abteilung_id: $('#kf-abteilung').val() || 0,
    bereiche: JSON.stringify(bereiche)
  }).done(function (r) {
    if (r && r.success) {
      dlgZu('d-posten');
      toast(r.data.hinweis ? (r.data.message + ' ' + r.data.hinweis) : r.data.message,
            r.data.hinweis ? 'fehler' : 'gut');
      vKonten();
    } else {
      $b.frei('Speichern');
    }
  }).fail(function (x) {
    toast(fehlerText(x), 'fehler'); $b.frei('Speichern');
  });
}

function vSaetze() {
  ajax('lsv07a_adm_uebersicht').done(function (r) {
    if (!r || !r.success) return;
    var c = r.data.config;
    LSV07A.config = c;
    $('#v-saetze').html(
      '<div class="a-karte a-schmal"><div class="a-karte-hd"><h2>Sätze</h2></div><div class="a-karte-bd">'
      + '<div class="a-feld"><label for="c-wk">Wettkampf: Betrag je Abschnitt (€)</label>'
      + '<input type="number" id="c-wk" class="a-ctl" step="0.5" min="0" value="' + esc(c.wk_satz) + '"></div>'
      + '<div class="a-zwei">'
      + '<div class="a-feld"><label for="c-km">Fahrtkosten je km (€)</label>'
      + '<input type="number" id="c-km" class="a-ctl" step="0.05" min="0" value="' + esc(c.km_satz) + '"></div>'
      + '<div class="a-feld"><label for="c-kmmin">Erst ab (km, einfach)</label>'
      + '<input type="number" id="c-kmmin" class="a-ctl" step="1" min="0" value="' + esc(c.km_mindest) + '"></div>'
      + '</div>'
      + '<div class="a-schalter-zeile"><input type="checkbox" id="c-hinrueck"'
      + (String(c.km_hin_rueck) === '1' ? ' checked' : '') + '>'
      + '<label for="c-hinrueck">Hin- und Rückfahrt rechnen (einfache Strecke × 2)</label></div>'
      + '<div class="a-feld"><label for="c-wartezeit">Wartezeit je Training (Minuten)</label>'
      + '<input type="number" id="c-wartezeit" class="a-ctl" step="5" min="0" max="240" value="'
      + esc(c.wartezeit_min) + '">'
      + '<div class="a-feld"><label for="c-verein">Verein (Kopfzeile auf dem Beleg)</label>'
      + '<input type="text" id="c-verein" class="a-ctl" value="' + esc(c.verein || '') + '"></div>'
      + '<h3 class="a-h3">Bankverbindung beim Genehmigen</h3>'
      + '<div class="a-feld"><label for="c-leiteriban">Was die Abteilungsleitung von der IBAN sieht</label>'
      + '<select id="c-leiteriban" class="a-ctl">'
      + $.map([['kurz', 'Verkürzt — Land und die letzten vier Stellen'],
               ['voll', 'Vollständig'],
               ['keine', 'Gar nicht — nur Empfänger und Betrag']], function (o) {
          return '<option value="' + o[0] + '"'
               + ((c.leiter_iban || 'kurz') === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
        }).join('')
      + '</select>'
      + '<div class="a-z-detail">Die Kasse sieht die IBAN immer vollständig — sie überweist. '
      + 'Die eigene sieht jeder selbst. Der Wart kommt an Rechnungen gar nicht heran.</div></div>'
      + '</div><div class="a-karte-ft"><button class="a-btn a-btn-ok" id="c-speichern">Speichern</button></div></div>'
      );
  });
}

$(document).on('click', '#c-speichern', function () {
  var $b = $(this).beschaeftigt('Speichert…');
  ajax('lsv07a_adm_config_speichern', {
    wk_satz: $('#c-wk').val(), km_satz: $('#c-km').val(), km_mindest: $('#c-kmmin').val(),
    km_hin_rueck: $('#c-hinrueck').is(':checked') ? 1 : 0,
    wartezeit_min: $('#c-wartezeit').val(), verein: $('#c-verein').val(),
    leiter_iban: $('#c-leiteriban').val()
  }).done(function (r) {
    if (r && r.success) { toast(r.data.message, 'gut'); LSV07A.config = r.data.config; geladen.eigene = false; }
  }).always(function () { $b.frei('Speichern'); });
});

var TAG_NAMEN = ['Alle Tage', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag',
                 'Freitag', 'Samstag', 'Sonntag'];
var PA = { liste: [] };

function vPauschalen() {
  $('#v-pauschalen').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_pauschalen').done(function (r) {
    if (!r || !r.success) return;
    PA.liste = r.data.pauschalen || [];
    var h = '';
    if (r.data.hinweis_intern) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis_intern) + '</div>';
    if (!PA.liste.length) {
      h += '<div class="a-leer">Es sind keine Mannschaften vorhanden.</div>';
    } else {
      h += '<div class="a-karte"><div class="a-tbl-wrap" style="border:0"><table class="a-tbl"><thead><tr>'
         + '<th>Mannschaft</th><th class="a-zahl">Betrag je Training</th>'
         + '<th>Wochentage</th><th></th></tr></thead><tbody>';
      $.each(PA.liste, function (i, m) {
        h += '<tr><td data-label="Mannschaft">' + esc(m.name) + '</td>'
           + '<td data-label="Betrag" class="a-zahl"><input type="number" class="a-ctl pa-wert" '
           + 'style="width:110px;text-align:right" step="0.5" min="0" data-mid="' + m.mannschaft_id + '" '
           + 'placeholder="—" value="' + (m.betrag === null ? '' : esc(m.betrag)) + '"></td>'
           + '<td data-label="Wochentage">'
           + (m.abweichend
               ? '<span class="a-chip a-chip-blau">' + m.abweichend + ' abweichend</span>'
               : '<span class="a-chip a-chip-grau">alle gleich</span>')
           + '</td>'
           + '<td class="a-td-akt">'
           + '<button class="a-btn a-btn-klein a-btn-b pa-tage" data-mid="' + m.mannschaft_id + '">Wochentage</button>'
           + '<button class="a-btn a-btn-klein a-btn-ok pa-save" data-mid="' + m.mannschaft_id + '">Speichern</button>'
           + '</td></tr>';
      });
      h += '</tbody></table></div></div>';
    }
    listeSetzen($('#v-pauschalen'), h, 'pauschalen');
  });
}

/* Den allgemeinen Betrag speichern (Wochentag 0). Ein leeres Feld
   entfernt ihn — dann ist für diese Mannschaft nichts hinterlegt. */
$(document).on('click', '.pa-save', function () {
  var mid = $(this).data('mid');
  var wert = $('.pa-wert[data-mid="' + mid + '"]').val();
  var $b = $(this).beschaeftigt('…');
  ajax('lsv07a_adm_pauschale_speichern', { mannschaft_id: mid, wochentag: 0, betrag: wert })
    .done(function (r) { if (r && r.success) { toast(r.data.message, 'gut'); vPauschalen(); } })
    .always(function () { $b.frei('Speichern'); });
});

/* Die sieben Wochentage in einem Dialog: Ein leeres Feld heisst "es gilt
   der allgemeine Betrag", eine 0 heisst ausdrücklich "an diesem Tag
   nichts". Der Unterschied steht dabei. */
$(document).on('click', '.pa-tage', function () {
  var mid = $(this).data('mid');
  var m = null;
  $.each(PA.liste, function (i, x) { if (x.mannschaft_id === mid) m = x; });
  if (!m) return;

  var allg = m.betrag === null ? null : parseFloat(m.betrag);
  var h = '';
  for (var t = 1; t <= 7; t++) {
    var w = m.tage && m.tage[t] !== null && m.tage[t] !== undefined ? m.tage[t] : '';
    h += '<div class="a-feld a-pa-tag"><label for="pa-t' + t + '">' + esc(TAG_NAMEN[t]) + '</label>'
       + '<input type="number" class="a-ctl" id="pa-t' + t + '" data-tag="' + t + '" '
       + 'step="0.5" min="0" placeholder="' + (allg === null ? '—' : zahl(allg, 2)) + '" '
       + 'value="' + esc(w) + '"></div>';
  }
  $('#d-tage-titel').text('Wochentage — ' + m.name);
  $('#d-tage-bd').html(h);
  $('#d-tage-ok').data('mid', mid);
  dlgAuf('d-tage');
});

$('#d-tage-ok').on('click', function () {
  var mid = $(this).data('mid');
  var $b = $(this).beschaeftigt('Speichert…');
  var felder = $('#d-tage-bd input[data-tag]').toArray();
  /* Nacheinander statt alle auf einmal: So ist am Ende sicher alles
     gespeichert, und ein Fehler bleibt einem einzelnen Tag zuzuordnen. */
  (function weiter(i) {
    if (i >= felder.length) {
      $b.frei('Speichern');
      dlgZu('d-tage'); toast('Wochentage gespeichert.', 'gut'); vPauschalen();
      return;
    }
    var f = $(felder[i]);
    ajax('lsv07a_adm_pauschale_speichern',
         { mannschaft_id: mid, wochentag: f.data('tag'), betrag: f.val() })
      .done(function () { weiter(i + 1); })
      .fail(function () { $b.frei('Speichern'); });
  })(0);
});

function vSaisons() {
  $('#v-saisons').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_saisons').done(function (r) {
    if (!r || !r.success) return;
    var s = r.data.saisons || [];
    var h = '';
    if (r.data.hinweis) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis) + '</div>';
    h += '<div class="a-karte"><div class="a-karte-hd"><h2>Saisons</h2>'
       + '<button class="a-btn a-btn-klein a-btn-p" id="sa-neu">+ Saison</button></div>';
    if (!s.length) {
      h += '<div class="a-karte-bd"><div class="a-leer">Noch keine Saison angelegt.</div></div>';
    } else {
      h += '<div class="a-tbl-wrap" style="border:0;border-radius:0"><table class="a-tbl"><thead><tr>'
         + '<th>Name</th><th>Von</th><th>Bis</th><th>Stand</th><th></th></tr></thead><tbody>';
      $.each(s, function (i, x) {
        h += '<tr><td data-label="Name">' + esc(x.name) + '</td>'
           + '<td data-label="Von">' + esc(de(x.start_datum)) + '</td>'
           + '<td data-label="Bis">' + esc(x.ende_datum ? de(x.ende_datum) : 'offen') + '</td>'
           + '<td data-label="Stand">' + (parseInt(x.aktiv, 10) === 1
               ? '<span class="a-chip a-chip-gruen">aktiv</span>' : '<span class="a-chip a-chip-grau">—</span>') + '</td>'
           + '<td class="a-td-akt">'
           + '<button class="a-btn a-btn-klein a-btn-b sa-bearb" data-s=\'' + esc(JSON.stringify(x)) + '\'>Bearbeiten</button>'
           + (parseInt(x.aktiv, 10) === 1 ? ''
              : '<button class="a-btn a-btn-klein a-btn-ok sa-aktiv" data-id="' + x.id + '">Aktivieren</button>')
           + '<button class="a-btn a-btn-klein a-btn-r sa-weg" data-id="' + x.id + '">Löschen</button>'
           + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    listeSetzen($('#v-saisons'), h + '</div>', 'saisons');
  });
}

function saisonDialog(s) {
  var d = s || { id: 0, name: '', start_datum: '', ende_datum: '' };
  $('#d-posten-titel').text(s ? 'Saison bearbeiten' : 'Neue Saison');
  $('#d-posten-bd').html(
    '<div class="a-feld"><label for="sa-name">Name</label>'
    + '<input type="text" id="sa-name" class="a-ctl" placeholder="z. B. Saison 2026/27" value="' + esc(d.name) + '"></div>'
    + '<div class="a-zwei">'
    + '<div class="a-feld"><label for="sa-von">Anfang</label>'
    + '<input type="date" id="sa-von" class="a-ctl" value="' + esc(d.start_datum || '') + '"></div>'
    + '<div class="a-feld"><label for="sa-bis">Ende <span class="a-opt">leer = offen</span></label>'
    + '<input type="date" id="sa-bis" class="a-ctl" value="' + esc(d.ende_datum || '') + '"></div></div>'
    + '<input type="hidden" id="sa-id" value="' + d.id + '">'
    );
  $('#d-posten-ok').off('click').on('click', function () {
    var $b = $(this).beschaeftigt('Speichert…');
    ajax('lsv07a_adm_saison_speichern', {
      id: $('#sa-id').val(), name: $('#sa-name').val(),
      start_datum: $('#sa-von').val(), ende_datum: $('#sa-bis').val()
    }).done(function (r) {
      if (r && r.success) { dlgZu('d-posten'); toast(r.data.message, 'gut'); vSaisons(); }
      else $b.frei('Speichern');
    }).fail(function (x) { toast(fehlerText(x), 'fehler'); $b.frei('Speichern'); });
  }).frei('Speichern');
  dlgAuf('d-posten');
}

$(document).on('click', '#sa-neu', function () { saisonDialog(null); });
$(document).on('click', '.sa-bearb', function () {
  var s;
  try { s = JSON.parse($(this).attr('data-s')); } catch (e) { return; }
  saisonDialog(s);
});
$(document).on('click', '.sa-aktiv', function () {
  var id = $(this).data('id');
  ajax('lsv07a_adm_saison_aktivieren', { id: id }).done(function (r) {
    if (r && r.success) { toast(r.data.message, 'gut'); vSaisons(); }
  });
});
$(document).on('click', '.sa-weg', function () {
  var id = $(this).data('id');
  frage('Saison löschen', '',
    'Löschen', function () {
      ajax('lsv07a_adm_saison_loeschen', { id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); vSaisons(); }
      });
    }, 'gefahr');
});

var WT = ['', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

function vZeiten(saisonId) {
  $('#v-zeiten').html('<div class="a-laden">Wird geladen…</div>');
  var daten = {};
  if (saisonId) daten.saison_id = saisonId;
  ajax('lsv07a_adm_slots', daten).done(function (r) {
    if (!r || !r.success) return;
    A.slotDaten = r.data;
    var s = r.data.slots || [];
    var h = '';
    if (r.data.hinweis) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis) + '</div>';
    h += '<div class="a-karte"><div class="a-karte-hd"><h2>Trainingszeiten</h2>'
       + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
       + '<select id="zt-saison" class="a-ctl"><option value="">Alle Saisons</option>';
    $.each(r.data.saisons || [], function (i, x) {
      h += '<option value="' + x.id + '"' + (String(saisonId) === String(x.id) ? ' selected' : '') + '>'
         + esc(x.name) + (parseInt(x.aktiv, 10) === 1 ? ' (aktiv)' : '') + '</option>';
    });
    h += '</select><button class="a-btn a-btn-klein a-btn-p" id="zt-neu">+ Zeit</button></div></div>';
    if (!s.length) {
      h += '<div class="a-karte-bd"><div class="a-leer">Für diese Auswahl gibt es keine Trainingszeiten.</div></div>';
    } else {
      h += '<div class="a-tbl-wrap" style="border:0;border-radius:0"><table class="a-tbl"><thead><tr>'
         + '<th>Mannschaft</th><th>Tag</th><th>Von</th><th>Bis</th><th class="a-zahl">Stunden</th>'
         + '<th></th></tr></thead><tbody>';
      $.each(s, function (i, x) {
        var std = stundenAus(x.zeit_von, x.zeit_bis);
        h += '<tr><td data-label="Mannschaft">' + esc(x.mannschaft_name || ('Mannschaft ' + x.mannschaft_id)) + '</td>'
           + '<td data-label="Tag">' + esc(WT[parseInt(x.wochentag, 10)] || '?') + '</td>'
           + '<td data-label="Von">' + esc(String(x.zeit_von).slice(0, 5)) + '</td>'
           + '<td data-label="Bis">' + esc(String(x.zeit_bis).slice(0, 5)) + '</td>'
           + '<td data-label="Stunden" class="a-zahl">' + zahl(std) + '</td>'
           + '<td class="a-td-akt">'
           + '<button class="a-btn a-btn-klein a-btn-b zt-bearb" data-s=\'' + esc(JSON.stringify(x)) + '\'>Bearbeiten</button>'
           + '<button class="a-btn a-btn-klein a-btn-r zt-weg" data-id="' + x.id + '">Löschen</button></td></tr>';
      });
      h += '</tbody></table></div>';
    }
    $('#v-zeiten').html(h + '</div>');
  });
}

function stundenAus(von, bis) {
  function min(t) { var p = String(t).split(':'); return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0); }
  var d = min(bis) - min(von);
  return d > 0 ? Math.round(d / 60 * 100) / 100 : 0;
}

$(document).on('change', '#zt-saison', function () { vZeiten($(this).val()); });

function slotDialog(s) {
  var d = s || { id: 0, saison_id: '', mannschaft_id: '', wochentag: 1, zeit_von: '18:00', zeit_bis: '19:30' };
  var q = A.slotDaten || { saisons: [], mannschaften: [] };
  var h = '<div class="a-feld"><label for="zt-s">Saison</label><select id="zt-s" class="a-ctl">'
        + '<option value="">— bitte wählen —</option>';
  $.each(q.saisons, function (i, x) {
    h += '<option value="' + x.id + '"' + (String(d.saison_id) === String(x.id) ? ' selected' : '') + '>'
       + esc(x.name) + (parseInt(x.aktiv, 10) === 1 ? ' (aktiv)' : '') + '</option>';
  });
  h += '</select></div>'
     + '<div class="a-feld"><label for="zt-m">Mannschaft</label><select id="zt-m" class="a-ctl">'
     + '<option value="">— bitte wählen —</option>';
  $.each(q.mannschaften, function (i, x) {
    h += '<option value="' + x.id + '"' + (String(d.mannschaft_id) === String(x.id) ? ' selected' : '') + '>'
       + esc(x.name) + '</option>';
  });
  h += '</select></div>'
     + '<div class="a-feld"><label for="zt-t">Wochentag</label><select id="zt-t" class="a-ctl">';
  for (var i = 1; i <= 7; i++) {
    h += '<option value="' + i + '"' + (parseInt(d.wochentag, 10) === i ? ' selected' : '') + '>' + WT[i] + '</option>';
  }
  h += '</select></div><div class="a-zwei">'
     + '<div class="a-feld"><label for="zt-von">Von</label>'
     + '<input type="time" id="zt-von" class="a-ctl" value="' + esc(String(d.zeit_von).slice(0, 5)) + '"></div>'
     + '<div class="a-feld"><label for="zt-bis">Bis</label>'
     + '<input type="time" id="zt-bis" class="a-ctl" value="' + esc(String(d.zeit_bis).slice(0, 5)) + '"></div></div>'
     + '<input type="hidden" id="zt-id" value="' + d.id + '">'
     ;

  $('#d-posten-titel').text(s ? 'Trainingszeit bearbeiten' : 'Neue Trainingszeit');
  $('#d-posten-bd').html(h);
  $('#d-posten-ok').off('click').on('click', function () {
    var $b = $(this).beschaeftigt('Speichert…');
    ajax('lsv07a_adm_slot_speichern', {
      id: $('#zt-id').val(), saison_id: $('#zt-s').val(), mannschaft_id: $('#zt-m').val(),
      wochentag: $('#zt-t').val(), zeit_von: $('#zt-von').val(), zeit_bis: $('#zt-bis').val()
    }).done(function (r) {
      if (r && r.success) { dlgZu('d-posten'); toast(r.data.message, 'gut'); vZeiten($('#zt-saison').val()); }
      else $b.frei('Speichern');
    }).fail(function (x) { toast(fehlerText(x), 'fehler'); $b.frei('Speichern'); });
  }).frei('Speichern');
  dlgAuf('d-posten');
}

$(document).on('click', '#zt-neu', function () { slotDialog(null); });
$(document).on('click', '.zt-bearb', function () {
  var s;
  try { s = JSON.parse($(this).attr('data-s')); } catch (e) { return; }
  slotDialog(s);
});
$(document).on('click', '.zt-weg', function () {
  var id = $(this).data('id');
  var weg = function (trotzdem) {
    ajax('lsv07a_adm_slot_loeschen', { id: id, trotzdem: trotzdem ? 1 : 0 }).done(function (r) {
      if (r && r.success) { toast(r.data.message, 'gut'); vZeiten($('#zt-saison').val()); }
      else if (r && r.data && r.data.code === 'hat_anwesenheit') {
        frage('Trotzdem löschen?', '<p>' + esc(r.data.message) + '</p>', 'Trotzdem löschen',
          function () { weg(true); }, 'gefahr');
      }
    }).fail(function (x) {
      var r = null;
      try { r = JSON.parse(jsonRetten(x.responseText) || x.responseText); } catch (e) {}
      if (r && r.data && r.data.code === 'hat_anwesenheit') {
        frage('Trotzdem löschen?', '<p>' + esc(r.data.message) + '</p>', 'Trotzdem löschen',
          function () { weg(true); }, 'gefahr');
      } else { toast(fehlerText(x), 'fehler'); }
    });
  };
  frage('Trainingszeit löschen', '', 'Löschen',
    function () { weg(false); }, 'gefahr');
});

function vProtokoll() {
  $('#v-protokoll').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_protokoll').done(function (r) {
    if (!r || !r.success) return;
    var z = r.data || [];
    if (!z.length) { $('#v-protokoll').html('<div class="a-leer">Noch nichts protokolliert.</div>'); return; }
    var h = '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
          + '<th>Wann</th><th>Wer</th><th>Was</th><th>Einzelheiten</th></tr></thead><tbody>';
    $.each(z, function (i, l) {
      h += '<tr><td data-label="Wann">' + esc(deZeit(l.erstellt_am)) + '</td>'
         + '<td data-label="Wer">' + esc(l.wer) + '</td>'
         + '<td data-label="Was">' + esc(l.aktion) + '</td>'
         + '<td data-label="Einzelheiten">' + esc(l.details || '') + '</td></tr>';
    });
    $('#v-protokoll').html(h + '</tbody></table></div>');
  });
}

// ════════════════════════════════════════════════════════════════════
//  Start
// ════════════════════════════════════════════════════════════════════

$(function () {
  $('#e-quartal').val(LSV07A.quartal);
  $('#p-quartal').val(LSV07A.quartal);

  // Beim Laden den Bereich aus der Adresszeile öffnen, sonst den ersten
  var start = (window.location.hash || '').replace('#', '');
  /* Eine ältere Adresse wie #rechnungen zeigt weiterhin dorthin — sie
     liegt nur nicht mehr in einem eigenen Bereich. */
  if (UNTER[start] && $('#' + UNTER[start].teil + start).length) {
    zeigeUnter(start);
    start = UNTER[start].nav;
  } else {
    if (!start || !$('#s-' + start).length) {
      start = $('.a-nb').first().data('ziel');
    }
    if (start) zeige(start);
  }

  // Der Wart sieht gleich, wie viel offen ist — auch ohne den Bereich zu öffnen
  if (Z.tabs.pruefung && start !== 'pruefen') {
    ajax('lsv07a_pruef_liste', { quartal: LSV07A.quartal, jahr: LSV07A.jahr })
      .done(function (r) {
        if (r && r.success) pruefBadge(r.data.offen);
      });
  }
  /* Dasselbe für die Abteilungsleitung: Eine Rechnung, die auf eine
     Entscheidung wartet, soll man sehen, ohne danach zu suchen. */
  if (Z.tabs.genehmigung && start !== 'pruefen') {
    ajax('lsv07a_r_pruefung', { status: 'eingereicht' })
      .done(function (r) { if (r && r.success) gBadge(r.data.offen); });
  }
});

/* ════════════════════════════════════════════════════════════════════
 *  MITTEILUNGEN
 *
 *  Bewusst im System statt per E-Mail: Eine Abrechnung enthält Beträge
 *  und Namen; die gehören nicht ungefragt in ein fremdes Postfach. Eine
 *  Mitteilung sagt nur, DASS etwas geschehen ist und wo es steht — der
 *  Betrag steht erst beim Öffnen, wo die Rechteprüfung greift.
 * ════════════════════════════════════════════════════════════════════ */

var N = { liste: [], offen: 0 };

function nachrLaden(imTakt) {
  /* Im Takt still: Ein abgebrochener Hintergrundabruf ist kein Fehler,
     den jemand als Meldung sehen müsste. */
  ajax('lsv07a_sys_nachrichten', {}, imTakt ? { still: true } : null).done(function (r) {
    if (!r || !r.success) return;
    N.liste = r.data.liste || [];
    N.offen = r.data.offen || 0;
    nachrZahl();
    if ($('#d-nachrichten').hasClass('auf')) nachrZeichnen();
  });
}

function nachrZahl() {
  var $z = $('#a-glocke-zahl');
  if (N.offen > 0) $z.text(N.offen > 99 ? '99+' : N.offen).prop('hidden', false);
  else $z.prop('hidden', true);
  $('#a-glocke').attr('aria-label',
    N.offen > 0 ? 'Mitteilungen, ' + N.offen + ' ungelesen' : 'Mitteilungen');
}

function nachrZeichnen() {
  if (!N.liste.length) {
    $('#d-nachr-bd').html('<div class="a-leer">Es liegt nichts an.</div>');
    $('#d-nachr-alle').prop('disabled', true);
    return;
  }
  var h = '';
  $.each(N.liste, function (i, n) {
    h += '<button class="a-nachr' + (n.gelesen ? '' : ' ist-neu') + '"'
       + ' data-id="' + n.id + '" data-bereich="' + esc(n.bereich) + '">'
       + '<span class="a-nachr-titel">' + esc(n.titel)
       /* Eine Mitteilung der Administration trägt, von wem sie kommt —
          sonst sucht jemand nach der Rechnung, zu der sie gehört. */
       + (n.art === 'admin'
          ? ' <span class="a-chip a-chip-blau">Administration</span>' : '')
       + '</span>'
       + (n.text ? '<span class="a-nachr-text">' + esc(n.text) + '</span>' : '')
       + '<span class="a-nachr-zeit">' + esc(deZeit(n.zeit)) + '</span>'
       + (n.gelesen ? '' : '<span class="a-nur-vorlesen">ungelesen</span>')
       + '</button>';
  });
  $('#d-nachr-bd').html(h);
  $('#d-nachr-alle').prop('disabled', N.offen === 0);
}

$('#a-glocke').on('click', function () {
  nachrZeichnen();
  dlgAuf('d-nachrichten');
  nachrLaden();
});

/* Eine Mitteilung anklicken: als gelesen vermerken und dorthin gehen,
   wo der Vorgang steht. Das ist der eigentliche Nutzen — sonst müsste
   man sich den Weg selbst suchen. */
$(document).on('click', '.a-nachr', function () {
  var id = $(this).data('id'), bereich = String($(this).data('bereich') || '');
  ajax('lsv07a_sys_nachricht_gelesen', { id: id }).done(function (r) {
    if (r && r.success) { N.offen = r.data.offen; nachrZahl(); }
  });
  $(this).removeClass('ist-neu');
  if (UNTER[bereich]) {
    dlgZu('d-nachrichten');
    zeigeUnter(bereich);
  } else if (bereich && $('.a-nb[data-ziel="' + bereich + '"]').length) {
    dlgZu('d-nachrichten');
    $('.a-nb[data-ziel="' + bereich + '"]').trigger('click');
  }
});

$('#d-nachr-alle').on('click', function () {
  ajax('lsv07a_sys_nachricht_gelesen', { id: 0 }).done(function (r) {
    if (!r || !r.success) return;
    N.offen = r.data.offen;
    $.each(N.liste, function (i, n) { n.gelesen = true; });
    nachrZahl(); nachrZeichnen();
  });
});

/* ════════════════════════════════════════════════════════════════════
 *  VERWALTUNG → MITTEILUNG
 *
 *  Die Administration schreibt selbst etwas — an alle Konten oder an
 *  ausgewählte. Es erscheint bei den Empfängern unter Mitteilungen.
 * ════════════════════════════════════════════════════════════════════ */

function vMitteilung() {
  $('#v-mitteilung').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_konten').done(function (r) {
    if (!r || !r.success) return;
    var k = r.data.konten || [];
    A.konten = k;

    var h = '<div class="a-karte a-schmal">'
          + '<div class="a-karte-hd"><h2>Mitteilung schreiben</h2></div>'
          + '<div class="a-karte-bd">'
          + '<div class="a-feld"><label for="mt-titel">Betreff</label>'
          + '<input type="text" id="mt-titel" class="a-ctl" maxlength="160" '
          + 'placeholder="z. B. Abrechnung bis 15. April einreichen"></div>'
          + '<div class="a-feld"><label for="mt-text">Text</label>'
          + '<textarea id="mt-text" class="a-ctl" rows="5" maxlength="400" '
          + 'placeholder="Höchstens 400 Zeichen."></textarea>'
          + '<div class="a-z-detail" id="mt-zaehler">0 von 400 Zeichen</div></div>'
          + '<div class="a-schalter-zeile"><input type="checkbox" id="mt-alle" checked>'
          + '<label for="mt-alle">An alle Konten mit einer Rolle</label></div>'
          + '<div id="mt-auswahl" hidden>';
    if (!k.length) {
      h += '<div class="a-leer">Noch kein Konto aufgenommen.</div>';
    } else {
      h += '<div class="a-kaestchen">';
      $.each(k, function (i, p) {
        h += '<div class="a-schalter-zeile"><input type="checkbox" class="mt-uid" '
           + 'id="mt-u-' + p.wp_user_id + '" value="' + p.wp_user_id + '">'
           + '<label for="mt-u-' + p.wp_user_id + '">' + esc(p.name)
           + ' <span class="a-z-detail">' + esc((p.rollen || []).join(', ')) + '</span>'
           + '</label></div>';
      });
      h += '</div>';
    }
    h += '</div></div>'
       + '<div class="a-karte-ft">'
       + '<button class="a-btn a-btn-ok" id="mt-senden">Mitteilung senden</button>'
       + '</div></div>';
    $('#v-mitteilung').html(h);
  });
}

$(document).on('change', '#mt-alle', function () {
  $('#mt-auswahl').prop('hidden', $(this).is(':checked'));
});

$(document).on('input', '#mt-text', function () {
  $('#mt-zaehler').text(($(this).val() || '').length + ' von 400 Zeichen');
});

$(document).on('click', '#mt-senden', function () {
  var titel = $('#mt-titel').val() || '';
  var text  = $('#mt-text').val() || '';
  if (!titel.replace(/\s+/g, '')) { toast('Bitte einen Betreff angeben.', 'fehler'); return; }
  if (!text.replace(/\s+/g, ''))  { toast('Bitte einen Text angeben.', 'fehler'); return; }

  var alle = $('#mt-alle').is(':checked');
  var uids = [];
  $('.mt-uid:checked').each(function () { uids.push(parseInt($(this).val(), 10)); });
  if (!alle && !uids.length) {
    toast('Bitte Konten auswählen — oder „an alle" ankreuzen.', 'fehler');
    return;
  }

  /* Eine Rundmitteilung lässt sich nicht zurücknehmen. Darum eine
     Rückfrage, die den Empfängerkreis ausschreibt. */
  var $b = $(this);
  frage('Mitteilung senden',
    alle
      ? 'Sie geht an <strong>alle ' + (A.konten || []).length + ' Konten</strong> mit einer '
        + 'Rolle. Zurücknehmen lässt sie sich nicht.'
      : 'Sie geht an <strong>' + uids.length + ' ausgewählte Konten</strong>. '
        + 'Zurücknehmen lässt sie sich nicht.',
    'Senden', function () {
      $b.beschaeftigt('Sendet…');
      ajax('lsv07a_adm_mitteilung_senden', {
        titel: titel, text: text,
        an_alle: alle ? '1' : '0',
        uids: JSON.stringify(uids)
      }).done(function (r) {
        if (r && r.success) {
          toast(r.data.hinweis ? (r.data.message + ' ' + r.data.hinweis) : r.data.message, 'gut');
          $('#mt-titel').val(''); $('#mt-text').val('');
          $('#mt-zaehler').text('0 von 400 Zeichen');
          $('.mt-uid').prop('checked', false);
          nachrLaden();
        }
      }).always(function () { $b.frei('Mitteilung senden'); });
    }, 'neutral');
});

/* ════════════════════════════════════════════════════════════════════
 *  PERSONENANSICHT
 * ════════════════════════════════════════════════════════════════════ */

function ansichtBand() {
  var a = (Z.ansicht || {});
  if (!a.aktiv) { $('#a-ansicht-band').prop('hidden', true); return; }
  $('#a-ansicht-rolle').text(a.name || ('Konto ' + a.person));
  $('#a-ansicht-band').prop('hidden', false);
}

function ansichtSetzen(person) {
  ajax('lsv07a_sys_ansicht_setzen', { person: person || 0 }).done(function (r) {
    if (!r || !r.success) return;
    toast(r.data.message, 'gut');
    /* Danach lädt die Seite neu und ALLES ist anders — andere Bereiche,
       andere Zahlen. Ohne Ankündigung kam der Neuaufbau aus dem Nichts.
       Darum erst das Zeichen, dann der Wechsel. */
    schrittWeiter('ansicht', person ? 'Ansicht wechselt' : 'Ansicht beendet');
    /* Neu laden statt nachzeichnen: Es ändert sich, welche Bereiche es
       überhaupt gibt — da ist ein sauberer Neuaufbau ehrlicher als der
       Versuch, die halbe Oberfläche umzubauen. */
    setTimeout(function () { window.location.reload(); }, 900);
  });
}

$('#a-ansicht-ende').on('click', function () { ansichtSetzen(0); });

/* ── Verwaltung → Personenansicht ─────────────────────────────────── */

function vAnsicht() {
  $('#v-ansicht').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_sys_ansicht_konten').done(function (r) {
    if (!r || !r.success) return;
    var k = r.data.konten || [];
    var a = r.data.ansicht || {};

    var h = '';

    if (a.aktiv) {
      h += '<div class="a-hinweis ist-warn"><strong>Läuft gerade:</strong> Sie sehen die '
         + 'Sicht von ' + esc(a.name) + '. '
         + '<button class="a-btn a-btn-klein a-btn-nein va-ende">Ansicht beenden</button></div>';
    }

    h += '<div class="a-karte"><div class="a-karte-hd"><h2>In eine Person sehen</h2></div>';
    if (!k.length) {
      h += '<div class="a-karte-bd"><div class="a-leer">Es gibt kein anderes Konto mit '
         + 'einer Rolle.</div></div>';
    } else {
      h += '<div class="a-tbl-wrap" style="border:0;border-radius:0">'
         + '<table class="a-tbl"><thead><tr><th>Konto</th><th>Rollen</th>'
         + '<th>Abteilung</th><th></th></tr></thead><tbody>';
      $.each(k, function (i, p) {
        var rollen = $.map(p.rollen, function (x) {
          return '<span class="a-chip a-chip-' + (x === 'admin' ? 'rot' : x === 'wart' ? 'gelb'
                 : x === 'kasse' ? 'gruen' : x === 'leiter' ? 'lila' : 'blau') + '">'
                 + esc(ROLLEN_NAME[x] || x) + '</span>';
        }).join(' ');
        h += '<tr><td data-label="Konto">' + esc(p.name)
           + (p.existiert ? '' : ' <span class="a-chip a-chip-rot">Konto gelöscht</span>') + '</td>'
           + '<td data-label="Rollen">' + rollen + '</td>'
           + '<td data-label="Abteilung">' + (p.abteilung ? esc(p.abteilung)
               : '<span class="a-z-detail">keine</span>') + '</td>'
           + '<td class="a-td-akt">'
           + (a.aktiv && a.person === p.wp_user_id
              ? '<span class="a-chip a-chip-blau">wird angesehen</span>'
              : '<button class="a-btn a-btn-klein a-btn-b va-start" data-uid="'
                + p.wp_user_id + '">Ansehen</button>')
           + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    $('#v-ansicht').html(h + '</div>');
  });
}

$(document).on('click', '.va-start', function () {
  var uid = parseInt($(this).data('uid'), 10);
  var name = $(this).closest('tr').find('td').first().text().trim();
  frage('In diese Person sehen',
    'Sie sehen danach genau das, was ' + esc(name) + ' sieht. Gehandelt wird nicht in '
    + 'diesem Namen: Änderungen sind gesperrt, bis Sie die Ansicht beenden. '
    + 'Dass Sie hineingesehen haben, wird protokolliert.',
    'Ansehen', function () { ansichtSetzen(uid); }, 'neutral');
});

$(document).on('click', '.va-ende', function () { ansichtSetzen(0); });

// Beim Start: Band zeigen, Mitteilungen holen, dann im ruhigen Takt.
$(function () {
  ansichtBand();
  if (Z.rollen && Z.rollen.length) {
    nachrLaden();
    setInterval(function () { nachrLaden(true); }, 120000);
  }
});

/* ════════════════════════════════════════════════════════════════════
 *  E-MAIL
 *
 *  Verschickt wird über den Mailversand von WordPress. Ab Werk ist das
 *  AUS — wohin Post geht, soll jemand bewusst einschalten.
 * ════════════════════════════════════════════════════════════════════ */

var ML = { arten: [] };

function vMail() {
  $('#v-mail').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_mail').done(function (r) {
    if (!r || !r.success) return;
    var d = r.data;
    ML.arten = d.arten || [];

    var h = '<div class="a-karte a-schmal"><div class="a-karte-hd"><h2>Versand</h2></div>'
          + '<div class="a-karte-bd">'
       + '<div class="a-schalter-zeile"><input type="checkbox" id="ml-an"' + (d.an ? ' checked' : '') + '>'
       + '<label for="ml-an">Mitteilungen auch per E-Mail verschicken</label></div>'
       + '<div class="a-zwei" style="margin-top:12px">'
       + '<div class="a-feld"><label for="ml-abs-name">Absendername <span class="a-opt">optional</span></label>'
       + '<input type="text" id="ml-abs-name" class="a-ctl" value="' + esc(d.absender_name) + '"></div>'
       + '<div class="a-feld"><label for="ml-abs">Absenderadresse <span class="a-opt">optional</span></label>'
       + '<input type="email" id="ml-abs" class="a-ctl" value="' + esc(d.absender) + '"></div></div>'
       + '<div class="a-feld"><label for="ml-link">Adresse der Abrechnungsseite</label>'
       + '<input type="url" id="ml-link" class="a-ctl" placeholder="https://…" value="' + esc(d.link) + '"></div>'
       + '<div class="a-zwei">'
       + '<div class="a-feld"><label for="ml-wart">Zusätzlich an (Warte)</label>'
       + '<input type="text" id="ml-wart" class="a-ctl" placeholder="wart@verein.de, …" value="' + esc(d.wart_extra) + '"></div>'
       + '<div class="a-feld"><label for="ml-kasse">Zusätzlich an (Kasse)</label>'
       + '<input type="text" id="ml-kasse" class="a-ctl" placeholder="kasse@verein.de, …" value="' + esc(d.kasse_extra) + '"></div>'
       + '</div>'
       + '</div><div class="a-karte-ft">'
       + '<button class="a-btn a-btn-p" id="ml-probe">Probemail senden</button>'
       + '<button class="a-btn a-btn-ok" id="ml-save">Speichern</button>'
       + '</div></div>';

    h += '<div class="a-karte a-schmal"><div class="a-karte-hd"><h2>Beleg zur bezahlten Abrechnung</h2></div>'
       + '<div class="a-karte-bd">'
       + '<div class="a-schalter-zeile"><input type="checkbox" id="ml-beleg"' + (d.beleg ? ' checked' : '') + '>'
       + '<label for="ml-beleg">Die Abrechnung in die Mail schreiben</label></div>'
       + '<div class="a-schalter-zeile" style="margin-top:8px">'
       + '<input type="checkbox" id="ml-beleg-pdf"' + (d.beleg_pdf ? ' checked' : '') + '>'
       + '<label for="ml-beleg-pdf">Die Abrechnung als PDF-Datei anhängen</label></div>'
       + '</div><div class="a-karte-ft">'
       + '<button class="a-btn a-btn-ok" id="ml-save3">Speichern</button>'
       + '</div></div>';

    /* Die Platzhalter bleiben stehen: Sie sind kein erklärender Text,
       sondern das Verzeichnis dessen, was sich in die Vorlagen schreiben
       lässt. Ohne sie liessen sich die Texte nicht anpassen. */
    h += '<h2 class="a-h2">Welche Mitteilungen per E-Mail</h2>'
       + '<div class="a-hinweis">'
       + $.map(d.platzhalter || {}, function (was, zeichen) {
           return '<code>' + esc(zeichen) + '</code>';
         }).join(' · ')
       + '</div>';

    $.each(ML.arten, function (i, a) {
      h += '<details class="a-verlauf ml-art" data-art="' + esc(a.art) + '"'
         + (a.an ? '' : '') + '>'
         + '<summary>' + esc(a.name)
         + (a.an ? '' : ' <span class="a-chip a-chip-grau" style="margin-left:8px">aus</span>')
         + '</summary><div>'
         + '<div class="a-schalter-zeile"><input type="checkbox" class="ml-art-an" id="ml-an-' + i + '"'
         + (a.an ? ' checked' : '') + '><label for="ml-an-' + i + '">Per E-Mail verschicken</label></div>'
         + '<div class="a-feld" style="margin-top:10px"><label for="ml-b-' + i + '">Betreff</label>'
         + '<input type="text" class="a-ctl ml-art-betreff" id="ml-b-' + i + '" value="' + esc(a.betreff) + '"></div>'
         + '<div class="a-feld"><label for="ml-t-' + i + '">Text</label>'
         + '<textarea class="a-ctl ml-art-text" id="ml-t-' + i + '" rows="7">' + esc(a.text) + '</textarea></div>'
         + '<button class="a-btn a-btn-klein ml-vorgabe" data-i="' + i + '">Vorgabetext wiederherstellen</button>'
         + '</div></details>';
    });
    h += '<div style="margin-top:14px"><button class="a-btn a-btn-ok" id="ml-save2">Speichern</button></div>';
    $('#v-mail').html(h);
  });
}

function mailSammeln() {
  var arten = [];
  $('.ml-art').each(function () {
    var $a = $(this);
    arten.push({
      art: $a.data('art'),
      an: $a.find('.ml-art-an').is(':checked') ? 1 : 0,
      betreff: $a.find('.ml-art-betreff').val() || '',
      text: $a.find('.ml-art-text').val() || ''
    });
  });
  return {
    an: $('#ml-an').is(':checked') ? 1 : 0,
    beleg: $('#ml-beleg').is(':checked') ? 1 : 0,
    beleg_pdf: $('#ml-beleg-pdf').is(':checked') ? 1 : 0,
    absender_name: $('#ml-abs-name').val() || '',
    absender: $('#ml-abs').val() || '',
    link: $('#ml-link').val() || '',
    wart_extra: $('#ml-wart').val() || '',
    kasse_extra: $('#ml-kasse').val() || '',
    arten: JSON.stringify(arten)
  };
}

$(document).on('click', '#ml-save, #ml-save2, #ml-save3', function () {
  var $b = $(this).beschaeftigt('Speichert…');
  ajax('lsv07a_adm_mail_speichern', mailSammeln())
    .done(function (r) { if (r && r.success) { toast(r.data.message, 'gut'); vMail(); } })
    .always(function () { $b.frei('Speichern'); });
});

$(document).on('click', '.ml-vorgabe', function () {
  var i = parseInt($(this).data('i'), 10), a = ML.arten[i];
  if (!a) return;
  $('#ml-b-' + i).val(a.vorgabe_betreff);
  $('#ml-t-' + i).val(a.vorgabe_text);
  toast('Vorgabetext eingesetzt. Zum Übernehmen noch speichern.', 'gut');
});

/* Die Probe geht AUCH bei abgeschaltetem Versand — genau dafür ist sie
   da: erst prüfen, dann einschalten. */
$(document).on('click', '#ml-probe', function () {
  var $b = $(this);
  frage('Probemail senden',
    '<div class="a-feld"><label for="pm-an">An welche Adresse?</label>'
    + '<input type="email" id="pm-an" class="a-ctl" placeholder="Ihre eigene, wenn leer"></div>'
    + '<div class="a-feld"><label for="pm-art">Welche Mitteilung</label>'
    + '<select id="pm-art" class="a-ctl">'
    + $.map(ML.arten, function (a) { return '<option value="' + esc(a.art) + '">' + esc(a.name) + '</option>'; }).join('')
    + '</select></div>',
    'Senden', function () {
      /* Ein Mailversand dauert — je nach Mailserver mehrere Sekunden.
         Ohne Zeichen am Knopf sah das aus wie „nichts passiert", und
         wer dann noch einmal drückte, verschickte zwei. */
      $b.beschaeftigt('Sendet …');
      ajax('lsv07a_adm_mail_probe', { an: $('#pm-an').val() || '', art: $('#pm-art').val() || 'genehmigt' })
        .done(function (r) { if (r && r.success) toast(r.data.message, 'gut'); })
        .always(function () { $b.frei('Probemail senden'); });
    }, 'neutral');
});

/* ════════════════════════════════════════════════════════════════════
 *  PAUSCHALEN EINER PERSON — sie stehen über denen der Mannschaft
 * ════════════════════════════════════════════════════════════════════ */

var PP = { uid: 0, mannschaften: [], eintraege: [] };

$(document).on('click', '.pp-oeffnen', function () {
  var uid = parseInt($(this).data('uid'), 10);
  ajax('lsv07a_adm_pp_liste', { wp_user_id: uid }).done(function (r) {
    if (!r || !r.success) return;
    PP.uid = r.data.wp_user_id;
    PP.mannschaften = r.data.mannschaften || [];
    PP.eintraege = r.data.eintraege || [];
    $('#d-pp-titel').text('Persönliche Pauschalen — ' + r.data.name);
    ppZeichnen();
    dlgAuf('d-pp');
  });
});

function ppMannschaftName(id) {
  if (!id) return 'Jede Mannschaft';
  var n = 'Mannschaft ' + id;
  $.each(PP.mannschaften, function (i, m) { if (m.id === id) n = m.name; });
  return n;
}

function ppZeichnen() {
  var h = '';

  if (!PP.eintraege.length) {
    h += '<div class="a-leer">Nichts hinterlegt.</div>';
  } else {
    h += '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
       + '<th>Mannschaft</th><th>Wochentag</th><th class="a-zahl">Betrag</th><th></th>'
       + '</tr></thead><tbody>';
    $.each(PP.eintraege, function (i, e) {
      h += '<tr><td data-label="Mannschaft">' + esc(ppMannschaftName(e.mannschaft_id)) + '</td>'
         + '<td data-label="Wochentag">' + esc(TAG_NAMEN[e.wochentag] || '—') + '</td>'
         + '<td data-label="Betrag" class="a-zahl">' + eur(e.betrag) + '</td>'
         + '<td class="a-td-akt"><button class="a-btn a-btn-klein a-btn-r pp-weg" '
         + 'data-m="' + e.mannschaft_id + '" data-t="' + e.wochentag + '">Entfernen</button></td></tr>';
    });
    h += '</tbody></table></div>';
  }

  h += '<h2 class="a-h2">Eintrag hinzufügen</h2>'
     + '<div class="a-zwei"><div class="a-feld"><label for="pp-m">Mannschaft</label>'
     + '<select id="pp-m" class="a-ctl"><option value="0">Jede Mannschaft</option>'
     + $.map(PP.mannschaften, function (m) {
         return '<option value="' + m.id + '">' + esc(m.name) + '</option>';
       }).join('')
     + '</select></div>'
     + '<div class="a-feld"><label for="pp-t">Wochentag</label><select id="pp-t" class="a-ctl">'
     + $.map(TAG_NAMEN, function (n, i) {
         return '<option value="' + i + '">' + esc(i === 0 ? 'Jeder Tag' : n) + '</option>';
       }).join('')
     + '</select></div></div>'
     + '<div class="a-feld"><label for="pp-b">Betrag je Training (€)</label>'
     + '<input type="number" id="pp-b" class="a-ctl" step="0.5" min="0" inputmode="decimal"></div>'
     + '<button class="a-btn a-btn-ok" id="pp-add">Hinzufügen</button>';
  $('#d-pp-bd').html(h);
}

function ppSpeichern(m, t, betrag, danach) {
  ajax('lsv07a_adm_pp_speichern',
       { wp_user_id: PP.uid, mannschaft_id: m, wochentag: t, betrag: betrag })
    .done(function (r) {
      if (!r || !r.success) return;
      toast(r.data.message, 'gut');
      ajax('lsv07a_adm_pp_liste', { wp_user_id: PP.uid }).done(function (x) {
        if (x && x.success) { PP.eintraege = x.data.eintraege || []; ppZeichnen(); }
        if (danach) danach();
      });
    });
}

$(document).on('click', '#pp-add', function () {
  var b = $('#pp-b').val();
  if (b === '') { toast('Bitte einen Betrag eintragen.', 'fehler'); return; }
  ppSpeichern(parseInt($('#pp-m').val(), 10) || 0, parseInt($('#pp-t').val(), 10) || 0, b);
});

$(document).on('click', '.pp-weg', function () {
  ppSpeichern($(this).data('m'), $(this).data('t'), '');
});

/* ════════════════════════════════════════════════════════════════════
 *  STAND EINER ABRECHNUNG SETZEN — die Administration kann jeden
 *  Schritt zurücknehmen, von bezahlt bis zurück zum Entwurf.
 * ════════════════════════════════════════════════════════════════════ */

var STAENDE = [
  { wert: 'entwurf',     name: 'Entwurf — wieder in Arbeit' },
  { wert: 'zurueck',     name: 'Zurückgegeben — mit Begründung' },
  { wert: 'eingereicht', name: 'Eingereicht — wartet auf Prüfung' },
  { wert: 'genehmigt',   name: 'Genehmigt — bei der Kasse' },
  { wert: 'bezahlt',     name: 'Bezahlt' }
];

$(document).on('click', '.a-stand-setzen', function () {
  var id = $(this).data('id'), jetzt = String($(this).data('status') || '');
  $('#d-stand-bd').html(
    '<div class="a-feld"><label for="st-ziel">Neuer Stand</label><select id="st-ziel" class="a-ctl">'
    + $.map(STAENDE, function (s) {
        return '<option value="' + s.wert + '"' + (s.wert === jetzt ? ' disabled' : '') + '>'
             + esc(s.name) + (s.wert === jetzt ? ' (aktuell)' : '') + '</option>';
      }).join('')
    + '</select></div>'
    + '<div class="a-feld"><label for="st-grund">Begründung <span class="a-opt">optional</span></label>'
    + '<textarea id="st-grund" class="a-ctl" rows="3"></textarea></div>');
  $('#d-stand-ok').data('id', id);
  dlgAuf('d-stand');
});

$('#d-stand-ok').on('click', function () {
  var $b = $(this).beschaeftigt('Setzt…');
  ajax('lsv07a_adm_status_setzen', {
    abrechnung_id: $(this).data('id'),
    status: $('#st-ziel').val(),
    grund: $('#st-grund').val() || ''
  }).done(function (r) {
    if (r && r.success) {
      toast(r.data.message, 'gut');
      dlgZu('d-stand');
      if ($('#pr-teil-pruefung').hasClass('on')) pruefListe();
      else if ($('#s-kasse').hasClass('on')) kasseListe();
    }
  }).always(function () { $b.frei('Stand setzen'); });
});


/* ════════════════════════════════════════════════════════════════════
 *  ANDERE ABRECHNUNG — einzelne Rechnungen mit Beleg
 *
 *  Der Weg: anlegen → Beleg hochladen → einreichen → die
 *  Abteilungsleitung genehmigt → die Kasse überweist per QR-Code →
 *  Archiv. Dieselben Zustände wie bei der Quartalsabrechnung, damit
 *  niemand zwei Systeme im Kopf behalten muss.
 * ════════════════════════════════════════════════════════════════════ */

var R = { rahmen: null, liste: [], offen: 0, jahre: [] };

/** Nummer einer Rechnung, wie sie überall erscheint: RG-0042. */
function rNummer(id) {
  var s = String(parseInt(id, 10) || 0);
  while (s.length < 4) s = '0' + s;
  return 'RG-' + s;
}

/** Die Adresse eines Downloads. Der Einmal-Schlüssel wandert mit. */
function dlUrl(aktion, args) {
  var u = LSV07A.ajax_url + (LSV07A.ajax_url.indexOf('?') >= 0 ? '&' : '?')
        + 'action=lsv07a_r_' + aktion + '&nonce=' + encodeURIComponent(LSV07A.nonce);
  $.each(args || {}, function (k, v) { u += '&' + k + '=' + encodeURIComponent(v); });
  return u;
}

/* Die Jahresliste in ein Auswahlfeld. Gezeigt wird immer auch das
   laufende Jahr — sonst fehlte es im ersten Januar, in dem noch keine
   Rechnung liegt, und man könnte keine anlegen. */
function rJahreFuellen($sel, jahre, gewaehlt) {
  var hat = {}, out = [];
  $.each(jahre || [], function (i, j) { var n = parseInt(j, 10); if (n) { hat[n] = 1; out.push(n); } });
  if (!hat[LSV07A.jahr]) out.push(LSV07A.jahr);
  out.sort(function (a, b) { return b - a; });
  var alt = gewaehlt || parseInt($sel.val(), 10) || LSV07A.jahr;
  $sel.html('<option value="0">Alle Jahre</option>' + $.map(out, function (j) {
    return '<option value="' + j + '"' + (j === alt ? ' selected' : '') + '>' + j + '</option>';
  }).join(''));
  if ($sel.val() === null) $sel.val(String(alt));
}

// ── Die eigene Sicht ────────────────────────────────────────────────

function rLaden() {
  $('#r-liste').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_r_meine', { jahr: parseInt($('#r-jahr').val(), 10) || 0 }).done(function (r) {
    if (!r || !r.success) return;
    R.rahmen = r.data.rahmen;
    R.liste  = r.data.rechnungen || [];

    var jahre = {};
    $.each(R.liste, function (i, x) { if (x.jahr) jahre[x.jahr] = 1; });
    if (!$('#r-jahr option').length) rJahreFuellen($('#r-jahr'), $.map(jahre, function (v, k) { return k; }));

    rHinweis();
    rSummen();
    rZeichnen();
  });
}

/* Was fehlt, steht oben und nicht erst hinter dem Einreichen-Knopf:
   Ohne Zahlungsdaten und ohne Abteilung kommt keine Rechnung durch,
   und das soll man vorher wissen. */
function rHinweis() {
  var h = '';
  if (R.rahmen && !R.rahmen.abteilung_id) {
    h += '<div class="a-hinweis ist-warn"><strong>Ihrem Konto ist keine Abteilung zugeordnet.</strong> '
       + 'Ohne Abteilung gibt es niemanden, der eine Rechnung genehmigen kann. '
       + 'Die Administration erledigt das unter Verwaltung → Abteilungen.</div>';
  }
  if (R.rahmen && !R.rahmen.stammdaten.vollstaendig) {
    h += '<div class="a-hinweis ist-warn"><strong>Ihre Zahlungsdaten sind unvollständig.</strong> '
       + 'Für eine Überweisung brauchen wir Kontoinhaber und eine gültige IBAN — '
       + 'nachzutragen unter „Meine Einstellungen“.</div>';
  }
  $('#r-hinweis').html(h);
}

function rSummen() {
  var offen = 0, unterwegs = 0, bezahlt = 0;
  $.each(R.liste, function (i, x) {
    if (x.status === 'entwurf' || x.status === 'zurueck') offen += x.betrag;
    else if (x.status === 'bezahlt') bezahlt += x.betrag;
    else unterwegs += x.betrag;
  });
  if (!R.liste.length) { $('#r-summen').empty(); return; }
  $('#r-summen').html(
    '<div class="a-kachel"><div class="a-kachel-lbl">In Arbeit</div>'
    + '<div class="a-kachel-wert">' + eur(offen) + '</div>'
    + '<div class="a-kachel-sub">Entwurf oder zurückgegeben</div></div>'
    + '<div class="a-kachel"><div class="a-kachel-lbl">Unterwegs</div>'
    + '<div class="a-kachel-wert">' + eur(unterwegs) + '</div>'
    + '<div class="a-kachel-sub">eingereicht oder genehmigt</div></div>'
    + '<div class="a-kachel ist-gesamt"><div class="a-kachel-lbl">Bezahlt</div>'
    + '<div class="a-kachel-wert">' + eur(bezahlt) + '</div></div>');
}

function rZeichnen() {
  if (!R.liste.length) {
    $('#r-liste').html('<div class="a-leer">Noch keine Rechnung. '
      + 'Mit „Neue Rechnung“ geht es los.</div>');
    return;
  }
  var h = '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
        + '<th>Nummer</th><th>Betreff</th><th>Datum</th><th>Stand</th>'
        + '<th class="a-zahl">Betrag</th><th>Beleg</th><th></th></tr></thead><tbody>';
  $.each(R.liste, function (i, x) {
    h += '<tr><td data-label="Nummer">' + rNummer(x.id) + '</td>'
       + '<td data-label="Betreff">' + esc(x.titel || '(ohne Betreff)')
       + (x.rueckgabe_grund
          ? '<br><span class="a-z-detail">Rückgabe: ' + esc(x.rueckgabe_grund) + '</span>' : '')
       + '</td>'
       + '<td data-label="Datum">' + esc(de(x.rechnungsdatum)) + '</td>'
       + '<td data-label="Stand">' + statusChip(x.status, x.status_name) + '</td>'
       + '<td data-label="Betrag" class="a-zahl">' + eur(x.betrag) + '</td>'
       + '<td data-label="Beleg">' + (x.dateien.length
            ? '<span class="a-chip a-chip-gruen">' + x.dateien.length + '</span>'
            : '<span class="a-chip a-chip-rot">fehlt</span>') + '</td>'
       + '<td class="a-td-akt">'
       + '<button class="a-btn a-btn-klein a-btn-b r-oeffnen" data-id="' + x.id + '">'
       + (x.darf_aendern ? 'Bearbeiten' : 'Ansehen') + '</button>'
       + '<a class="a-btn a-btn-klein a-btn-b" target="_blank" rel="noopener"'
       + ' href="' + esc(dlUrl('blatt', { rechnung_id: x.id })) + '">Blatt</a>'
       + (x.status === 'eingereicht'
          ? '<button class="a-btn a-btn-klein a-btn-r r-zurueckholen" data-id="' + x.id + '">Zurückholen</button>'
          : '')
       + '</td></tr>';
  });
  listeSetzen($('#r-liste'), h + '</tbody></table></div>', 'r' + ($('#r-jahr').val() || ''));
}

$(document).on('change', '#r-jahr', function () { rLaden(); });

// ── Das Formular ────────────────────────────────────────────────────

/* Die Rechnung, die gerade im Dialog steht. Als Nummer und nicht als
   Objekt: Nach jedem Speichern kommt sie frisch vom Server, und eine
   gemerkte Kopie wäre nach dem ersten Upload veraltet. */
var R_AKT = 0;

function rFormular(x) {
  var lesen = !x.darf_aendern;
  var sd = (R.rahmen && R.rahmen.stammdaten) || { kontoinhaber: '', iban: '', bic: '' };
  var h = '';

  if (x.rueckgabe_grund) {
    h += '<div class="a-hinweis ist-warn"><strong>Zurückgegeben.</strong> '
       + esc(x.rueckgabe_grund) + '</div>';
  }
  if (lesen) {
    h += '<div class="a-hinweis">Diese Rechnung ist eingereicht und lässt sich nicht '
       + 'mehr ändern. Stand: ' + esc(x.status_name) + '.</div>';
  }

  var ro = lesen ? ' disabled' : '';
  h += '<div class="a-feld"><label for="rf-titel">Betreff</label>'
     + '<input type="text" id="rf-titel" class="a-ctl" maxlength="200"' + ro
     + ' placeholder="z. B. Startblöcke, Hallenmiete März" value="' + esc(x.titel) + '"></div>'
     + '<div class="a-zwei">'
     + '<div class="a-feld"><label for="rf-betrag">Betrag (€)</label>'
     + '<input type="text" id="rf-betrag" class="a-ctl" inputmode="decimal"' + ro
     + ' placeholder="0,00" value="' + (x.betrag ? zahl(x.betrag, 2) : '') + '"></div>'
     + '<div class="a-feld"><label for="rf-datum">Rechnungsdatum</label>'
     + '<input type="date" id="rf-datum" class="a-ctl"' + ro
     + ' value="' + esc(x.rechnungsdatum) + '"></div>'
     + '</div>'
     + '<div class="a-feld"><label for="rf-zweck">Verwendungszweck <span class="a-opt">optional</span></label>'
     + '<input type="text" id="rf-zweck" class="a-ctl" maxlength="140"' + ro
     + ' value="' + esc(x.zweck) + '">'
     + '<div class="a-z-detail">Die Nummer ' + rNummer(x.id) + ' steht ohnehin davor — '
     + 'so lässt sich die Buchung auf dem Kontoauszug wiederfinden.</div></div>';

  // Empfänger
  h += '<h3 class="a-h3">Wohin wird überwiesen?</h3>'
     + '<div class="a-schalter-zeile"><input type="checkbox" id="rf-eigen"'
     + (x.empf_eigen ? ' checked' : '') + ro + '>'
     + '<label for="rf-eigen">An mich selbst — meine Zahlungsdaten aus den Stammdaten</label></div>'
     + '<div id="rf-eigen-an"' + (x.empf_eigen ? '' : ' hidden') + '>'
     + '<div class="a-hinweis">' + esc(sd.kontoinhaber || '(kein Kontoinhaber hinterlegt)')
     + '<br><span class="a-z-detail">' + esc(ibanLesbar(sd.iban) || 'keine IBAN hinterlegt')
     + '</span></div></div>'
     + '<div id="rf-fremd"' + (x.empf_eigen ? ' hidden' : '') + '>'
     + '<div class="a-feld"><label for="rf-ename">Empfänger</label>'
     + '<input type="text" id="rf-ename" class="a-ctl" maxlength="200"' + ro
     + ' value="' + esc(x.empf_eigen ? '' : x.empf_name) + '"></div>'
     + '<div class="a-zwei">'
     + '<div class="a-feld"><label for="rf-eiban">IBAN</label>'
     + '<input type="text" id="rf-eiban" class="a-ctl" spellcheck="false" autocomplete="off"' + ro
     + ' value="' + esc(x.empf_eigen ? '' : x.empf_iban) + '"></div>'
     + '<div class="a-feld"><label for="rf-ebic">BIC <span class="a-opt">optional</span></label>'
     + '<input type="text" id="rf-ebic" class="a-ctl" spellcheck="false" autocomplete="off"' + ro
     + ' value="' + esc(x.empf_eigen ? '' : x.empf_bic) + '"></div>'
     + '</div></div>';

  // Belege
  h += '<h3 class="a-h3">Beleg</h3>';
  if (!x.id) {
    h += '<div class="a-hinweis">Der Beleg lässt sich anhängen, sobald die Rechnung '
       + 'angelegt ist.</div>';
  } else {
    h += '<div id="rf-dateien">' + rDateien(x) + '</div>';
    if (!lesen) {
      h += '<div class="a-feld" style="margin-top:10px">'
         + '<input type="file" id="rf-datei" class="a-ctl" '
         + 'accept="application/pdf,image/jpeg,image/png,image/webp">'
         + '<div class="a-z-detail">Ein PDF oder ein Foto (JPEG, PNG, WebP), bis 12 MB. '
         + 'Mehrere Belege sind erlaubt — eine Rechnung kommt schon mal als zwei Fotos.</div>'
         + '</div>'
         + '<button class="a-btn a-btn-p" id="rf-hoch" disabled>Beleg hochladen</button>';
    }
  }

  if (!lesen && x.id) {
    h += '<div class="a-hinweis" id="rf-fehlt" style="margin-top:14px"'
       + (x.vollstaendig ? ' hidden' : '') + '>' + esc(x.fehlt) + '</div>';
  }
  return h;
}

function rDateien(x) {
  if (!x.dateien.length) {
    return '<div class="a-leer">Noch kein Beleg angehängt. Ohne Beleg lässt sich die '
         + 'Rechnung nicht einreichen.</div>';
  }
  var h = '<ul class="a-dateien">';
  $.each(x.dateien, function (i, d) {
    /* Genau EIN Knopf je Beleg. Solange die Rechnung bearbeitet wird, ist
       es der kurze Weg ohne Begründung — sie war nie eingereicht. Danach
       der endgültige mit Begründung, Protokoll und Mitteilung. Wer ihn
       bekommt, sagt der Server (`darf_beleg_weg`); die Oberfläche rechnet
       das nicht selbst aus. */
    var knopf = '';
    if (x.darf_aendern) {
      knopf = ' <button class="a-btn a-btn-klein a-btn-r rf-weg" data-id="' + d.id
            + '">Entfernen</button>';
    } else if (x.darf_beleg_weg) {
      knopf = ' <button class="a-btn a-btn-klein a-btn-r rf-weg-endg" data-id="' + d.id
            + '" data-name="' + esc(d.name) + '"'
            + ' data-status="' + esc(x.status || '')
            + '" data-wer="' + esc(x.name || '')
            + '" data-titel="' + esc(x.titel || '') + '">Endgültig löschen</button>';
    }
    h += '<li><a href="' + esc(dlUrl('datei', { datei_id: d.id })) + '">' + esc(d.name) + '</a>'
       + ' <span class="a-z-detail">' + esc(d.groesse_text) + '</span>'
       + knopf
       + '</li>';
  });
  return h + '</ul>';
}

/** IBAN in Vierergruppen — so steht sie auf jedem Formular. */
function ibanLesbar(iban) {
  var roh = String(iban || '');
  /* Eine gekürzte IBAN („DE02 … 2051") bleibt, wie sie ist. Durch die
     Vierergruppierung geschickt, würde daraus „DE02 …20 51" — die
     Auslassung rutschte in eine Gruppe und das Gekürzte sähe aus wie ein
     Tippfehler. */
  if (roh.indexOf('…') >= 0) return roh;
  var i = roh.replace(/\s+/g, '').toUpperCase();
  return i.replace(/(.{4})/g, '$1 ').trim();
}

function rDialog(x) {
  R_AKT = x.id;
  $('#d-rech-titel').text(x.id ? ('Rechnung ' + rNummer(x.id)) : 'Neue Rechnung');
  $('#d-rech-bd').html(rFormular(x));
  /* Die Belegliste entsteht mit dem ganzen Formular und nicht einzeln —
     verglichen wird sie trotzdem, damit ein gerade angehängter Beleg
     sichtbar DAZUKOMMT und ein entfernter sichtbar geht. */
  listeAbgleichen($('#rf-dateien'), 'r' + (x.id || 0));

  var f = '';
  if (x.darf_aendern) {
    f += '<button class="a-btn a-btn-r" id="rf-loeschen">Löschen</button>'
       + '<button class="a-btn a-btn-nein" data-zu>Schließen</button>'
       + '<button class="a-btn a-btn-b" id="rf-speichern">Speichern</button>'
       + '<button class="a-btn a-btn-ok" id="rf-einreichen"'
       + (x.vollstaendig ? '' : ' disabled') + '>Einreichen</button>';
  } else {
    f += '<a class="a-btn a-btn-b" target="_blank" rel="noopener" href="'
       + esc(dlUrl('blatt', { rechnung_id: x.id })) + '">Blatt als PDF</a>'
       + '<button class="a-btn" data-zu>Schließen</button>';
  }
  $('#d-rech-ft').html(f);
  dlgAuf('d-rechnung');
}

/** Die Felder aus dem Formular. */
function rFelder() {
  var eigen = $('#rf-eigen').is(':checked');
  var d = {
    titel: $('#rf-titel').val() || '',
    betrag: $('#rf-betrag').val() || '',
    rechnungsdatum: $('#rf-datum').val() || '',
    zweck: $('#rf-zweck').val() || '',
    empf_eigen: eigen ? '1' : '0'
  };
  if (!eigen) {
    d.empf_name = $('#rf-ename').val() || '';
    d.empf_iban = $('#rf-eiban').val() || '';
    d.empf_bic  = $('#rf-ebic').val() || '';
  }
  return d;
}

$(document).on('click', '#r-neu', function () {
  ajax('lsv07a_r_neu', rNeuVorbelegt()).done(function (r) {
    /* Ohne Rechnung im Rückgabewert gibt es nichts zu öffnen. Das sollte
       nicht vorkommen — aber ein Dialog, der auf einer leeren Antwort
       aufgeht, zeigt eine Rechnung ohne Nummer und lässt sich nicht
       speichern. Dann lieber nichts öffnen und es sagen. */
    if (!r || !r.success || !r.data || !r.data.rechnung) {
      toast((r && r.data && r.data.message)
        || 'Die Rechnung liess sich nicht anlegen: Der Server hat keine Rechnung '
         + 'zurückgegeben. Bitte die Seite neu laden und es noch einmal versuchen.', 'fehler');
      return;
    }
    schrittWeiter('rechnung', 'Rechnung angelegt');
    rDialog(r.data.rechnung);
    rLaden();
  });
});

/* Eine neue Rechnung entsteht leer, aber mit heutigem Datum und den
   eigenen Zahlungsdaten — das ist der häufige Fall. */
function rNeuVorbelegt() {
  return { rechnungsdatum: heute(), empf_eigen: '1' };
}

$(document).on('click', '.r-oeffnen', function () {
  var id = parseInt($(this).data('id'), 10);
  var x = null;
  $.each(R.liste, function (i, y) { if (y.id === id) x = y; });
  if (x) rDialog(x);
});

$(document).on('change', '#rf-eigen', function () {
  var eigen = $(this).is(':checked');
  $('#rf-eigen-an').prop('hidden', !eigen);
  $('#rf-fremd').prop('hidden', eigen);
});

$(document).on('click', '#rf-speichern', function () {
  var $b = $(this).beschaeftigt('Speichert…');
  ajax('lsv07a_r_speichern', $.extend({ rechnung_id: R_AKT }, rFelder())).done(function (r) {
    if (r && r.success && r.data && r.data.rechnung) {
      toast('Gespeichert.', 'gut');
      rDialog(r.data.rechnung);
      rLaden();
    }
  }).always(function () { $b.frei('Speichern'); });
});

/* Vor dem Hochladen wird gespeichert. Sonst wäre nach dem Upload das
   Getippte weg: Der Beleg kommt frisch vom Server zurück, und der
   Dialog wird dabei neu gezeichnet. */
$(document).on('change', '#rf-datei', function () {
  $('#rf-hoch').prop('disabled', !this.files || !this.files.length);
});

/* Der Fortschritt eines Uploads — der einzige Ort, an dem echte Prozent
   möglich sind. Ein Foto vom Handy hat schnell zehn Megabyte; ohne diese
   Anzeige steht dort eine halbe Minute lang nur „Lädt hoch…".

   anteil: 0…1 während der Übertragung, 1 heisst „angekommen, der Server
   prüft und verschlüsselt noch", null räumt die Anzeige weg. */
function belegFortschritt(anteil) {
  var $f = $('#rf-fortschritt');
  if (anteil === null || anteil === undefined) { $f.remove(); return; }
  if (!$f.length) {
    $f = $('<div id="rf-fortschritt" class="a-fortschritt" role="progressbar" '
         + 'aria-valuemin="0" aria-valuemax="100">'
         + '<div class="a-fortschritt-spur"><div class="a-fortschritt-wert"></div></div>'
         + '<div class="a-fortschritt-txt"></div></div>');
    $('#rf-hoch').after($f);
  }
  var proz = Math.max(0, Math.min(100, Math.round(anteil * 100)));
  $f.attr('aria-valuenow', proz);
  $f.find('.a-fortschritt-wert').css('width', proz + '%');
  $f.find('.a-fortschritt-txt').text(proz >= 100
    ? 'Angekommen — wird geprüft und verschlüsselt …'
    : proz + ' % übertragen');
}

$(document).on('click', '#rf-hoch', function () {
  var datei = ($('#rf-datei')[0].files || [])[0];
  if (!datei) { toast('Bitte zuerst eine Datei wählen.', 'fehler'); return; }
  var $b = $(this).beschaeftigt('Lädt hoch…');
  var id = R_AKT;
  function zurueck() { $b.frei('Beleg hochladen'); belegFortschritt(null); }

  ajax('lsv07a_r_speichern', $.extend({ rechnung_id: id }, rFelder())).done(function (r0) {
    /* Hat das Speichern „nein" gesagt, wird NICHT hochgeladen. Vorher
       lief der Upload trotzdem los — der Beleg hing dann an einer
       Rechnung, deren Angaben der Server gerade abgelehnt hatte. Den
       Grund hat ajax() schon gezeigt. */
    if (!r0 || !r0.success) { zurueck(); return; }

    var fd = new FormData();
    fd.append('action', 'lsv07a_r_beleg');
    fd.append('nonce', LSV07A.nonce);
    fd.append('rechnung_id', id);
    fd.append('datei', datei);

    belegFortschritt(0);
    ladenAn();
    $.ajax({ url: LSV07A.ajax_url, type: 'POST', data: fd,
             processData: false, contentType: false,
             dataType: 'json',
             dataFilter: function (d) { return jsonRetten(d) || d; },
             xhr: function () {
               var x = $.ajaxSettings.xhr();
               if (x.upload) {
                 x.upload.addEventListener('progress', function (e) {
                   if (e.lengthComputable && e.total > 0) belegFortschritt(e.loaded / e.total);
                 });
               }
               return x;
             } })
      .always(ladenAus)
      .done(function (r) {
        if (r && r.success && r.data && r.data.rechnung) {
          toast('Beleg angehängt.', 'gut');
          schrittWeiter('beleg', 'Beleg hochgeladen');
          rDialog(r.data.rechnung); rLaden();
        } else {
          toast((r && r.data && r.data.message)
            || 'Der Beleg kam beim Server an, wurde aber nicht angenommen — ohne '
             + 'Angabe eines Grundes. Bitte prüfen: PDF, JPG, PNG oder WebP, und '
             + 'nicht zu gross.', 'fehler');
        }
      })
      .fail(function (x) { toast(fehlerText(x), 'fehler'); })
      .always(zurueck);
  }).fail(function (x) { toast(fehlerText(x), 'fehler'); zurueck(); });
});

$(document).on('click', '.rf-weg', function () {
  var did = $(this).data('id');
  frage('Beleg entfernen', 'Die Datei wird dabei gelöscht.', 'Entfernen', function () {
    ajax('lsv07a_r_beleg_weg', { datei_id: did }).done(function (r) {
      if (r && r.success && r.data && r.data.rechnung) { rDialog(r.data.rechnung); rLaden(); }
    });
  }, 'gefahr');
});

/* Einen Beleg endgültig löschen — auch aus einer bezahlten oder
   archivierten Rechnung. Derselbe Dialog wie beim Löschen einer Rechnung:
   Pflichtbegründung, Warnung ab „eingereicht", und der Server entscheidet,
   ob es erlaubt ist. */
$(document).on('click', '.rf-weg-endg', function () {
  var $k = $(this);
  wegFragen({
    titel: 'Beleg endgültig löschen',
    was: 'Gelöscht wird der Beleg <strong>„' + esc($k.data('name') || '')
       + '"</strong> aus der Rechnung „' + esc($k.data('titel') || '')
       + '" von ' + esc($k.data('wer') || '')
       + '. Die Rechnung selbst bleibt stehen. Die Datei wird überschrieben und '
       + 'von der Platte genommen — sie ist danach nicht mehr herzustellen.',
    warnung: wegWarnung(String($k.data('status') || ''), 'beleg'),
    aktion: 'lsv07a_r_beleg_loeschen', feld: 'datei_id', id: $k.data('id'),
    dann: function (r) {
      /* Der Dialog bleibt offen und zeichnet sich ohne den Beleg neu —
         man löscht oft zwei von drei. Daneben die Liste, aus der man
         gekommen ist; welche das ist, muss hier niemand wissen. */
      if (r && r.data && r.data.rechnung) {
        $('#d-rech-bd').html(rAnsicht(r.data.rechnung));
      }
      if (typeof gLaden === 'function' && $('#pr-teil-genehmigung').length) gLaden();
      if (typeof krLaden === 'function' && $('#k-teil-rechnungen').length) krLaden();
      if (typeof arLaden === 'function' && $('#s-archiv').length) arLaden();
      if (typeof rLaden === 'function' && $('#s-rechnungen').length) rLaden();
    }
  });
});

$(document).on('click', '#rf-einreichen', function () {
  var id = R_AKT;
  var $b = $(this);
  ajax('lsv07a_r_speichern', $.extend({ rechnung_id: id }, rFelder())).done(function () {
    frage('Zur Genehmigung einreichen',
      'Danach entscheidet die Abteilungsleitung. Ändern lässt sich die Rechnung '
      + 'dann nicht mehr — zurückholen schon, solange niemand entschieden hat.',
      'Einreichen', function () {
        $b.prop('disabled', true);
        ajax('lsv07a_r_einreichen', { rechnung_id: id }).done(function (r) {
          if (r && r.success) {
            toast(r.data.message, 'gut');
            schrittWeiter('eingereicht', 'Eingereicht');
            dlgZu('d-rechnung');
            rLaden();
          }
        }).always(function () { $b.prop('disabled', false); });
      });
  });
});

$(document).on('click', '.r-zurueckholen', function () {
  var id = $(this).data('id');
  frage('Rechnung zurückholen',
    'Sie geht zurück in den Entwurf und lässt sich wieder ändern.',
    'Zurückholen', function () {
      ajax('lsv07a_r_zurueckholen', { rechnung_id: id }).done(function (r) {
        if (r && r.success) { toast('Zurückgeholt.', 'gut'); rLaden(); }
      });
    }, 'neutral');
});

$(document).on('click', '#rf-loeschen', function () {
  var id = R_AKT;
  frage('Rechnung löschen', 'Der Entwurf und seine Belege werden gelöscht.',
    'Löschen', function () {
      ajax('lsv07a_r_weg', { rechnung_id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-rechnung'); rLaden(); }
      });
    }, 'gefahr');
});

// ── Die Abteilungsleitung entscheidet ───────────────────────────────

var G = { liste: [] };

function gLaden() {
  $('#g-liste').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_r_pruefung', { status: $('#g-status').val() }).done(function (r) {
    if (!r || !r.success) return;
    G.liste = r.data.rechnungen || [];
    $('#g-abteilung').text('Ihre Abteilung: ' + r.data.abteilung);
    gBadge(r.data.offen);

    if (!G.liste.length) {
      $('#g-liste').html('<div class="a-leer">Nichts zu entscheiden.</div>');
      return;
    }
    var h = '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
          + '<th>Nummer</th><th>Betreff</th><th>Eingereicht von</th><th>Abteilung</th>'
          + '<th>Stand</th><th class="a-zahl">Betrag</th><th></th></tr></thead><tbody>';
    $.each(G.liste, function (i, x) {
      h += '<tr><td data-label="Nummer">' + rNummer(x.id) + '</td>'
         + '<td data-label="Betreff">' + esc(x.titel || '(ohne Betreff)') + '</td>'
         + '<td data-label="Eingereicht von">' + esc(x.name) + '</td>'
         + '<td data-label="Abteilung">' + esc(x.abteilung || '—') + '</td>'
         + '<td data-label="Stand">' + statusChip(x.status, x.status_name) + selbstChip(x) + '</td>'
         + '<td data-label="Betrag" class="a-zahl">' + eur(x.betrag) + '</td>'
         + '<td class="a-td-akt">'
         + '<button class="a-btn a-btn-klein a-btn-b g-ansehen" data-id="' + x.id + '">Ansehen</button>'
         + (x.status === 'eingereicht'
            ? '<button class="a-btn a-btn-klein a-btn-ok g-ja" data-id="' + x.id + '">Genehmigen</button>'
            : '')
         + (x.status === 'eingereicht' || x.status === 'genehmigt'
            ? '<button class="a-btn a-btn-klein a-btn-r g-nein" data-id="' + x.id + '">Zurückgeben</button>'
            : '')
         + '</td></tr>';
    });
    listeSetzen($('#g-liste'), h + '</tbody></table></div>', 'g' + ($('#g-status').val() || ''));
  });
}

function gBadge(n) {
  n = parseInt(n, 10) || 0;
  $('#a-badge-genehm').text(n).prop('hidden', n === 0);
}

$(document).on('change', '#g-status', function () { gLaden(); });

/* Angesehen wird im Rechnungsdialog, aber ohne Formular: Wer entscheidet,
   soll die Rechnung lesen und nicht in ihr tippen können. */
$(document).on('click', '.g-ansehen', function () {
  var id = parseInt($(this).data('id'), 10);
  var x = null;
  $.each(G.liste, function (i, y) { if (y.id === id) x = y; });
  if (!x) return;
  $('#d-rech-titel').text('Rechnung ' + rNummer(x.id));
  $('#d-rech-bd').html(rAnsicht(x));
  $('#d-rech-ft').html(
    '<a class="a-btn a-btn-b" target="_blank" rel="noopener" href="'
    + esc(dlUrl('blatt', { rechnung_id: x.id })) + '">Blatt als PDF</a>'
    + rWegKnopf(x)
    + (x.status === 'eingereicht'
       ? '<button class="a-btn a-btn-r g-nein" data-id="' + x.id + '">Zurückgeben</button>'
         + '<button class="a-btn a-btn-ok g-ja" data-id="' + x.id + '">Genehmigen</button>'
       : '<button class="a-btn" data-zu>Schließen</button>'));
  dlgAuf('d-rechnung');
});

/** Eine Rechnung zum Lesen — für Genehmigung, Kasse und Archiv. */
function rAnsicht(x) {
  var zeilen = [
    ['Betreff', x.titel || '(ohne Betreff)'],
    ['Betrag', eurRoh(x.betrag)],
    ['Rechnungsdatum', de(x.rechnungsdatum)],
    ['Eingereicht von', x.name],
    ['Abteilung', x.abteilung || 'keine zugeordnet'],
    ['Empfänger', x.empf_name],
    ['IBAN', ibanLesbar(x.empf_iban)],
    ['BIC', x.empf_bic || '—'],
    ['Verwendungszweck', rNummer(x.id) + (x.zweck ? ' ' + x.zweck : '')],
    ['Stand', x.status_name],
    ['Eingereicht am', deZeit(x.eingereicht_am) || 'offen'],
    ['Genehmigt am', deZeit(x.genehmigt_am) || 'offen'],
    ['Bezahlt am', deZeit(x.bezahlt_am) || 'offen']
  ];
  var h = '<dl class="a-dl">';
  $.each(zeilen, function (i, p) {
    h += '<dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd>';
  });
  h += '</dl>';
  if (x.selbst_genehmigt) {
    h += '<div class="a-hinweis ist-warn"><strong>Selbst genehmigt.</strong> '
       + 'Eingereicht und genehmigt hat dieselbe Person. Erlaubt — und hier vermerkt, '
       + 'damit es bei der Kassenprüfung nicht gesucht werden muss.</div>';
  }
  if (x.rueckgabe_grund) {
    h += '<div class="a-hinweis ist-warn"><strong>Zurückgegeben.</strong> '
       + esc(x.rueckgabe_grund) + '</div>';
  }
  /* `darf_aendern: false` — in dieser Ansicht wird nichts bearbeitet. Das
     endgültige Löschen eines Belegs bleibt aber möglich, und zwar genau
     hier: Diese Ansicht ist der Weg aus Prüfung, Kasse UND Archiv. */
  h += '<h3 class="a-h3">Belege</h3>' + rDateien($.extend({}, x, { darf_aendern: false }));
  return h;
}

/* Der Löschknopf einer Rechnung. Derselbe in Prüfung und Kasse — was
   erlaubt ist, entscheidet der Server. */
function rWegKnopf(x) {
  return '<button class="a-btn a-btn-r r-weg" data-id="' + x.id
       + '" data-status="' + esc(x.status || '')
       + '" data-titel="' + esc(x.titel || '')
       + '" data-wer="' + esc(x.name || '') + '">Löschen</button>';
}

$(document).on('click', '.r-weg', function () {
  var $k = $(this);
  wegFragen({
    titel: 'Rechnung löschen',
    was: 'Gelöscht wird die Rechnung <strong>„' + esc($k.data('titel') || '')
       + '"</strong> von ' + esc($k.data('wer') || '')
       + ' samt allen hochgeladenen Belegen.',
    warnung: wegWarnung(String($k.data('status') || '')),
    aktion: 'lsv07a_r_loeschen', feld: 'rechnung_id', id: $k.data('id'),
    zu: ['d-rechnung', 'd-giro'],
    dann: function () {
      /* Beide Listen neu laden: Der Knopf steht in Prüfung UND Kasse, und
         welche gerade offen ist, muss hier niemand wissen. */
      if (typeof gLaden === 'function' && $('#pr-teil-genehmigung').length) gLaden();
      if (typeof krLaden === 'function' && $('#k-teil-rechnungen').length) krLaden();
    }
  });
});

$(document).on('click', '.g-ja', function () {
  var id = parseInt($(this).data('id'), 10);
  var x = null;
  $.each(G.liste, function (i, y) { if (y.id === id) x = y; });
  /* Die eigene Rechnung darf genehmigt werden. Gefragt wird trotzdem
     anders — damit niemand versehentlich über sich selbst entscheidet. */
  var eigen = x && Z.user_id && x.wp_user_id === Z.user_id;
  frage(eigen ? 'Die eigene Rechnung genehmigen' : 'Rechnung genehmigen',
    eigen
      ? 'Sie haben diese Rechnung selbst eingereicht. Das Genehmigen ist erlaubt, '
        + 'wird aber als Selbstgenehmigung vermerkt: in der Liste, im Protokoll, auf dem '
        + 'Rechnungsblatt und als Mitteilung an Kasse und Administration.'
      : 'Danach sieht die Kasse die Rechnung und kann sie überweisen.',
    'Genehmigen', function () {
      ajax('lsv07a_r_genehmigen', { rechnung_id: id }).done(function (r) {
        if (r && r.success) {
          toast(r.data.message, 'gut');
          schrittWeiter('genehmigt', 'Genehmigt');
          dlgZu('d-rechnung'); gLaden();
        }
      });
    });
});

/* Zurückgeben hat einen EIGENEN Dialog und nicht die Rückfrage mit einem
   Textfeld darin: Die Rückfrage schliesst sich, sobald ihr Knopf gedrückt
   ist. Fehlte dann die Begründung, wäre das Getippte weg und der Dialog
   verschwunden — man müsste von vorn anfangen. */
$(document).on('click', '.g-nein', function () {
  var id = parseInt($(this).data('id'), 10);
  var x = null;
  $.each(G.liste, function (i, y) { if (y.id === id) x = y; });
  $('#d-rzur-was').text(x
    ? (rNummer(id) + ' — ' + (x.titel || 'ohne Betreff') + ', ' + eurRoh(x.betrag))
    : rNummer(id));
  $('#d-rzur-grund').val('');
  $('#d-rzur-ok').data('id', id).frei('Zurückgeben');
  dlgAuf('d-rzurueck');
  $('#d-rzur-grund').trigger('focus');
});

$(document).on('click', '#d-rzur-ok', function () {
  var $b = $(this);
  var grund = $('#d-rzur-grund').val() || '';
  if (!grund.replace(/\s+/g, '')) {
    toast('Bitte schreiben Sie dazu, was zu ändern ist.', 'fehler');
    $('#d-rzur-grund').trigger('focus');
    return;   // der Dialog bleibt stehen, das Getippte auch
  }
  $b.beschaeftigt('Gibt zurück…');
  ajax('lsv07a_r_zurueck', { rechnung_id: $b.data('id'), grund: grund }).done(function (r) {
    if (r && r.success) {
      toast(r.data.message, 'gut');
      dlgZu('d-rzurueck'); dlgZu('d-rechnung');
      gLaden();
    }
  }).always(function () { $b.frei('Zurückgeben'); });
});

// ── Die Kasse überweist ─────────────────────────────────────────────

var KR = { liste: [] };

function krLaden() {
  $('#kr-liste').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_r_kasse', {
    status: $('#kr-status').val(), jahr: parseInt($('#kr-jahr').val(), 10) || 0
  }).done(function (r) {
    if (!r || !r.success) return;
    KR.liste = r.data.rechnungen || [];

    if (!$('#kr-jahr option').length) {
      var jahre = {};
      $.each(KR.liste, function (i, x) { if (x.jahr) jahre[x.jahr] = 1; });
      rJahreFuellen($('#kr-jahr'), $.map(jahre, function (v, k) { return k; }));
    }

    $('#kr-summen').html(
      '<div class="a-kachel"><div class="a-kachel-lbl">Zu überweisen</div>'
      + '<div class="a-kachel-wert">' + eur(r.data.summe_offen) + '</div></div>'
      + '<div class="a-kachel"><div class="a-kachel-lbl">Bezahlt</div>'
      + '<div class="a-kachel-wert">' + eur(r.data.summe_bezahlt) + '</div></div>');

    if (!KR.liste.length) {
      $('#kr-liste').html('<div class="a-leer">Nichts offen.</div>');
      return;
    }
    var h = '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
          + '<th>Nummer</th><th>Betreff</th><th>Empfänger</th><th>Stand</th>'
          + '<th class="a-zahl">Betrag</th><th></th></tr></thead><tbody>';
    $.each(KR.liste, function (i, x) {
      h += '<tr><td data-label="Nummer">' + rNummer(x.id) + '</td>'
         + '<td data-label="Betreff">' + esc(x.titel || '(ohne Betreff)')
         + '<br><span class="a-z-detail">' + esc(x.name)
         + (x.abteilung ? ' · ' + esc(x.abteilung) : '') + '</span></td>'
         + '<td data-label="Empfänger">' + esc(x.empf_name)
         + '<br><span class="a-z-detail">' + esc(ibanLesbar(x.empf_iban)) + '</span></td>'
         + '<td data-label="Stand">' + statusChip(x.status, x.status_name) + selbstChip(x) + '</td>'
         + '<td data-label="Betrag" class="a-zahl">' + eur(x.betrag) + '</td>'
         + '<td class="a-td-akt">'
         + '<button class="a-btn a-btn-klein a-btn-b kr-ansehen" data-id="' + x.id + '">Ansehen</button>'
         + '<button class="a-btn a-btn-klein a-btn-p kr-giro" data-id="' + x.id + '">QR-Code</button>'
         + (x.status === 'genehmigt'
            ? '<button class="a-btn a-btn-klein a-btn-ok kr-bezahlt" data-id="' + x.id + '">Bezahlt</button>'
            : '')
         + '</td></tr>';
    });
    listeSetzen($('#kr-liste'), h + '</tbody></table></div>',
      [$('#kr-status').val(), $('#kr-jahr').val()].join('|'));
  });
}

$(document).on('change', '#kr-jahr, #kr-status', function () { krLaden(); });

$(document).on('click', '#k-reiter button', function () {
  var k = $(this).data('k');
  $('#k-reiter button').removeClass('on');
  $(this).addClass('on');
  $('.a-kteil').removeClass('on');
  $('#k-teil-' + k).addClass('on');
  if (k === 'rechnungen') krLaden(); else kasseListe();
});

$(document).on('click', '.kr-ansehen', function () {
  var id = parseInt($(this).data('id'), 10);
  var x = null;
  $.each(KR.liste.concat(AR.liste || []), function (i, y) { if (y.id === id) x = y; });
  if (!x) return;
  $('#d-rech-titel').text('Rechnung ' + rNummer(x.id));
  $('#d-rech-bd').html(rAnsicht(x));
  $('#d-rech-ft').html(
    '<a class="a-btn a-btn-b" target="_blank" rel="noopener" href="'
    + esc(dlUrl('blatt', { rechnung_id: x.id })) + '">Blatt als PDF</a>'
    + rWegKnopf(x)
    + '<button class="a-btn a-btn-p kr-giro" data-id="' + x.id + '">QR-Code</button>'
    + '<button class="a-btn" data-zu>Schließen</button>');
  dlgAuf('d-rechnung');
});

/* Der QR-Code kommt FERTIG vom Server. Ihn im Browser zu rechnen wäre
   eine weitere Programmbibliothek — und eine zweite Stelle, an der
   sich ein Fehler in einer Überweisung einschleichen kann. */
$(document).on('click', '.kr-giro', function () {
  var id = $(this).data('id');
  ajax('lsv07a_r_giro', { rechnung_id: id }).done(function (r) {
    if (!r || !r.success || !r.data || !r.data.rechnung) {
      toast((r && r.data && r.data.message)
        || 'Der QR-Code liess sich nicht erzeugen: Der Server hat keine Rechnung '
         + 'zurückgegeben. Bitte die Liste neu laden und es noch einmal versuchen.', 'fehler');
      return;
    }
    var x = r.data.rechnung;
    /* Das SVG wird als Markup eingesetzt und darum vorher angesehen. Es
       entsteht auf dem Server ausschliesslich aus Zahlen — Rechteckpfade,
       kein Nutzertext —, also kann darin nichts stecken. Geprüft wird es
       trotzdem: Die Annahme steht in einer anderen Datei, und eine
       Annahme, auf die man Markup stützt, prüft man. */
    var svg = String(r.data.svg || '');
    if (svg.slice(0, 4) !== '<svg' || /<\s*(script|foreignObject|iframe|image|use)\b/i.test(svg)
        || /\son[a-z]+\s*=/i.test(svg)) {
      toast('Der QR-Code kam in unerwarteter Form an und wird nicht angezeigt.', 'fehler');
      return;
    }

    $('#d-giro-titel').text('Überweisung ' + rNummer(x.id));
    $('#d-giro-bd').html(
      '<div class="a-qr">' + svg + '</div>'
      + '<dl class="a-dl">'
      + '<dt>Empfänger</dt><dd>' + esc(x.empf_name) + '</dd>'
      + '<dt>IBAN</dt><dd>' + esc(ibanLesbar(x.empf_iban)) + '</dd>'
      + (x.empf_bic ? '<dt>BIC</dt><dd>' + esc(x.empf_bic) + '</dd>' : '')
      + '<dt>Betrag</dt><dd><strong>' + esc(eurRoh(x.betrag)) + '</strong></dd>'
      + '<dt>Verwendungszweck</dt><dd>' + esc(r.data.zweck) + '</dd>'
      + '</dl>');
    $('#d-giro-ft').html(
      (x.status === 'genehmigt'
       ? '<button class="a-btn a-btn-ok kr-bezahlt" data-id="' + x.id + '">Überwiesen</button>'
       : '<span class="a-z-detail">Bereits als bezahlt vermerkt.</span>')
      + '<button class="a-btn" data-zu>Schließen</button>');
    dlgAuf('d-giro');
  });
});

$(document).on('click', '.kr-bezahlt', function () {
  var id = $(this).data('id');
  frage('Als bezahlt vermerken',
    'Damit wandert die Rechnung ins Archiv. Überwiesen wird in der Banking-App — '
    + 'dieser Vermerk hält nur fest, dass es erledigt ist.',
    'Als bezahlt vermerken', function () {
      ajax('lsv07a_r_bezahlt', { rechnung_id: id }).done(function (r) {
        if (r && r.success) {
          toast(r.data.message, 'gut');
          schrittWeiter('bezahlt', 'Bezahlt');
          dlgZu('d-giro'); dlgZu('d-rechnung');
          krLaden();
        }
      });
    });
});

// ── Das Archiv ──────────────────────────────────────────────────────

var AR = { jahre: [], jahr: 0, liste: [] };

function arLaden(jahr) {
  if (jahr === undefined) jahr = AR.jahr;
  ajax('lsv07a_r_archiv', { jahr: jahr || 0 }).done(function (r) {
    if (!r || !r.success) return;
    AR.jahre = r.data.jahre || [];
    AR.jahr  = r.data.jahr || 0;
    AR.liste = r.data.rechnungen || [];

    if (!AR.jahre.length) {
      $('#ar-jahre').html('<div class="a-leer">Noch ist keine Rechnung bezahlt — '
        + 'das Archiv füllt sich von selbst.</div>');
      $('#ar-liste').empty();
      return;
    }

    var h = '<div class="a-jahre">';
    $.each(AR.jahre, function (i, j) {
      h += '<div class="a-jahr' + (j.jahr === AR.jahr ? ' on' : '') + '">'
         + '<div class="a-jahr-kopf"><strong>' + j.jahr + '</strong>'
         + '<span class="a-z-detail">' + j.anzahl + ' Rechnung' + (j.anzahl === 1 ? '' : 'en')
         + ' · ' + esc(eurRoh(j.summe)) + '</span></div>'
         + '<div class="a-jahr-akt">'
         + '<button class="a-btn a-btn-klein a-btn-b ar-jahr" data-jahr="' + j.jahr + '">'
         + (j.jahr === AR.jahr ? 'Angezeigt' : 'Anzeigen') + '</button>'
         + '<a class="a-btn a-btn-klein a-btn-p ar-zip" href="'
         + esc(dlUrl('jahr', { jahr: j.jahr })) + '">ZIP herunterladen</a>'
         + '</div></div>';
    });
    $('#ar-jahre').html(h + '</div>');

    if (!AR.jahr) {
      $('#ar-liste').html('<div class="a-leer">Ein Jahr anzeigen, um die einzelnen '
        + 'Rechnungen zu sehen.</div>');
      return;
    }
    arZeichnen();
  });
}

function arZeichnen() {
  if (!AR.liste.length) {
    $('#ar-liste').html('<div class="a-leer">In ' + AR.jahr + ' ist nichts bezahlt.</div>');
    return;
  }
  var summe = 0;
  $.each(AR.liste, function (i, x) { summe += x.betrag; });

  var h = '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
        + '<th>Nummer</th><th>Betreff</th><th>Empfänger</th><th>Bezahlt am</th>'
        + '<th class="a-zahl">Betrag</th><th></th></tr></thead><tbody>';
  $.each(AR.liste, function (i, x) {
    h += '<tr><td data-label="Nummer">' + rNummer(x.id) + '</td>'
       + '<td data-label="Betreff">' + esc(x.titel || '(ohne Betreff)')
       + '<br><span class="a-z-detail">' + esc(x.name)
       + (x.abteilung ? ' · ' + esc(x.abteilung) : '') + '</span></td>'
       + '<td data-label="Empfänger">' + esc(x.empf_name) + '</td>'
       + '<td data-label="Bezahlt am">' + esc(deZeit(x.bezahlt_am)) + '</td>'
       + '<td data-label="Betrag" class="a-zahl">' + eur(x.betrag) + '</td>'
       + '<td class="a-td-akt">'
       + '<button class="a-btn a-btn-klein a-btn-b kr-ansehen" data-id="' + x.id + '">Ansehen</button>'
       + '<a class="a-btn a-btn-klein a-btn-b" target="_blank" rel="noopener" href="'
       + esc(dlUrl('blatt', { rechnung_id: x.id })) + '">Blatt</a>'
       + '</td></tr>';
  });
  h += '</tbody><tfoot><tr><th colspan="4">Summe ' + AR.jahr + '</th>'
     + '<th class="a-zahl">' + eur(summe) + '</th><th></th></tr></tfoot>';
  listeSetzen($('#ar-liste'), h + '</table></div>', 'ar' + AR.jahr);
}

$(document).on('click', '.ar-jahr', function () { arLaden(parseInt($(this).data('jahr'), 10)); });

/* Das Jahrespaket: Der Verweis bleibt ein Verweis (er funktioniert auch
   ohne Skripte und mit „Ziel speichern unter"), der Klick läuft aber
   durch herunterladen() — mit Rückmeldung, solange der Server packt. */
$(document).on('click', '.ar-zip', function (e) {
  e.preventDefault();
  herunterladen($(this).attr('href'), $(this), 'Paket wird gebaut …');
});

/* ════════════════════════════════════════════════════════════════════
 *  VERWALTUNG → ABTEILUNGEN
 *
 *  Wer hier eine Leitung setzt, entscheidet, wer über Geld entscheidet.
 *  Darum steht neben jeder Abteilung, wie viele Konten an ihr hängen,
 *  und ganz oben, wie viele noch gar keiner zugeordnet sind.
 * ════════════════════════════════════════════════════════════════════ */

var AB = { liste: [], mannschaften: [], eigene: [], konten: [], frei: [] };

function vAbteilungen() {
  $('#v-abteilungen').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_abt_liste').done(function (r) {
    if (!r || !r.success) return;
    AB.liste        = r.data.abteilungen || [];
    AB.mannschaften = r.data.mannschaften || [];
    AB.eigene       = r.data.eigene || [];
    AB.konten       = r.data.konten || [];
    AB.frei         = r.data.frei || [];

    var h = '';
    if (r.data.hinweis_intern) {
      h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis_intern) + '</div>';
    }
    if (r.data.ohne_abteilung) {
      var eins = r.data.ohne_abteilung === 1;
      h += '<div class="a-hinweis ist-warn"><strong>' + r.data.ohne_abteilung
         + (eins ? ' Konto hat' : ' Konten haben') + ' noch keine Abteilung.</strong> '
         + (eins ? 'Von ihm' : 'Von ihnen') + ' kann niemand eine Rechnung '
         + 'einreichen, weil es keinen Zuständigen gäbe. '
         + '<button class="a-btn a-btn-klein a-btn-p" id="ab-vorschlaege">Vorschläge zeigen</button>'
         + '</div>';
    }
    if (AB.frei.length) {
      h += '<div class="a-hinweis">Noch keiner Abteilung zugeordnet: '
         + $.map(AB.frei, function (m) {
             return esc(m.name) + (m.eigen ? ' <span class="a-z-detail">(eigene)</span>' : '');
           }).join(', ')
         + '</div>';
    }

    h += '<div class="a-karte"><div class="a-karte-hd"><h2>Abteilungen</h2>'
       + '<button class="a-btn a-btn-klein a-btn-p" id="ab-neu">+ Abteilung anlegen</button></div>';
    if (!AB.liste.length) {
      h += '<div class="a-karte-bd"><div class="a-leer">Noch keine Abteilung. '
         + 'Ein Verein ohne Gliederung legt eine einzige an und ordnet alle Konten '
         + 'ihr zu — dann genehmigt deren Leitung alles.</div></div>';
    } else {
      h += '<div class="a-tbl-wrap" style="border:0;border-radius:0">'
         + '<table class="a-tbl"><thead><tr><th>Abteilung</th><th>Leitung</th>'
         + '<th>Mannschaften</th><th class="a-zahl">Konten</th><th></th></tr></thead><tbody>';
      $.each(AB.liste, function (i, a) {
        var leitung = a.leiter.length
          ? $.map(a.leiter, function (l) {
              return '<span class="a-chip a-chip-' + (l.hat_rolle ? 'gruen' : 'rot') + '">'
                   + esc(l.name) + '</span>';
            }).join(' ')
          : '<span class="a-chip a-chip-rot">keine</span>';
        var mann = a.mannschaften.length
          ? $.map(a.mannschaften, function (m) {
              return esc(m.name)
                   + (m.eigen ? ' <span class="a-chip a-chip-lila">eigene</span>' : '')
                   + (m.da ? '' : ' <span class="a-chip a-chip-rot">fehlt</span>');
            }).join(', ')
          : '<span class="a-z-detail">keine — zulässig</span>';
        h += '<tr><td data-label="Abteilung"><strong>' + esc(a.name) + '</strong>'
           + (a.kurz ? ' <span class="a-z-detail">' + esc(a.kurz) + '</span>' : '')
           + (a.aktiv ? '' : ' <span class="a-chip a-chip-grau">abgeschaltet</span>') + '</td>'
           + '<td data-label="Leitung">' + leitung + '</td>'
           + '<td data-label="Mannschaften">' + mann + '</td>'
           + '<td data-label="Konten" class="a-zahl">' + a.konten + '</td>'
           + '<td class="a-td-akt">'
           + '<button class="a-btn a-btn-klein ab-bearb" data-id="' + a.id + '">Bearbeiten</button>'
           + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</div>';

    // Eigene Mannschaften
    h += '<div class="a-karte"><div class="a-karte-hd"><h2>Eigene Mannschaften</h2>'
       + '<button class="a-btn a-btn-klein a-btn-p" id="abm-neu">+ Mannschaft anlegen</button></div>'
    if (!AB.eigene.length) {
      h += '<div class="a-karte-bd"><div class="a-leer">Noch keine eigene Mannschaft. '
         + 'Die Mannschaften des internen Bereichs stehen ohnehin zur Verfügung.</div></div>';
    } else {
      h += '<div class="a-tbl-wrap" style="border:0;border-radius:0">'
         + '<table class="a-tbl"><thead><tr><th>Mannschaft</th><th>Notiz</th>'
         + '<th>Abteilung</th><th></th></tr></thead><tbody>';
      $.each(AB.eigene, function (i, m) {
        var abt = null;
        $.each(AB.liste, function (j, a) { if (a.id === m.abteilung_id) abt = a; });
        h += '<tr><td data-label="Mannschaft"><strong>' + esc(m.name) + '</strong>'
           + (m.aktiv ? '' : ' <span class="a-chip a-chip-grau">abgeschaltet</span>') + '</td>'
           + '<td data-label="Notiz">' + (m.notiz ? esc(m.notiz) : '<span class="a-z-detail">—</span>') + '</td>'
           + '<td data-label="Abteilung">' + (abt ? esc(abt.name)
               : '<span class="a-z-detail">keiner zugeordnet</span>') + '</td>'
           + '<td class="a-td-akt">'
           + '<button class="a-btn a-btn-klein abm-bearb" data-id="' + m.id + '">Bearbeiten</button>'
           + '<button class="a-btn a-btn-klein a-btn-r abm-weg" data-id="' + m.id + '">Löschen</button>'
           + '</td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</div>';

    /* „Konten zuordnen" stand hier einmal als eigene Karte. Sie ist in
       die Kontoverwaltung gewandert: Alles, was ein einzelnes Konto
       betrifft, soll an EINER Stelle stehen und nicht an zweien. */
    listeSetzen($('#v-abteilungen'), h, 'abteilungen');
  });
}

$(document).on('click', '#ab-vorschlaege', function () {
  ajax('lsv07a_abt_vorschlaege').done(function (r) {
    if (!r || !r.success) return;
    var v = r.data.vorschlaege || [];
    if (!v.length) {
      toast('Aus den bisherigen Abrechnungen lässt sich nichts ableiten.', 'fehler');
      return;
    }
    var h = '<ul class="a-dateien">'
          + $.map(v, function (x) {
              return '<li>' + esc(x.name) + ' → <strong>' + esc(x.abteilung) + '</strong></li>';
            }).join('')
          + '</ul>';
    frage('Vorschläge übernehmen', h,
      'Alle ' + v.length + ' übernehmen', function () {
        var offen = v.length;
        $.each(v, function (i, x) {
          ajax('lsv07a_abt_konto', { wp_user_id: x.wp_user_id, abteilung_id: x.abteilung_id })
            .always(function () { if (--offen === 0) { toast('Zugeordnet.', 'gut'); vAbteilungen(); } });
        });
      }, 'neutral');
  });
});

function abFormular(a) {
  var d = a || { id: 0, name: '', kurz: '', beschreibung: '', sortierung: 0,
                 aktiv: true, mannschaften: [], leiter: [] };
  var drin = {};
  $.each(d.mannschaften, function (i, m) { drin[m.wert || ('intern:' + m.id)] = 1; });
  var leiter = {};
  $.each(d.leiter, function (i, l) { leiter[l.wp_user_id] = 1; });

  var h = '<div class="a-feld"><label for="abf-name">Name</label>'
        + '<input type="text" id="abf-name" class="a-ctl" maxlength="120" '
        + 'placeholder="z. B. Schwimmen" value="' + esc(d.name) + '"></div>'
        + '<div class="a-zwei">'
        + '<div class="a-feld"><label for="abf-kurz">Kürzel <span class="a-opt">optional</span></label>'
        + '<input type="text" id="abf-kurz" class="a-ctl" maxlength="20" value="' + esc(d.kurz) + '"></div>'
        + '<div class="a-feld"><label for="abf-sort">Reihenfolge</label>'
        + '<input type="number" id="abf-sort" class="a-ctl" min="0" max="9999" '
        + 'value="' + esc(d.sortierung) + '"></div>'
        + '</div>'
        + '<div class="a-schalter-zeile"><input type="checkbox" id="abf-aktiv"'
        + (d.aktiv ? ' checked' : '') + '>'
        + '<label for="abf-aktiv">Aktiv — neue Rechnungen dürfen auf diese Abteilung laufen</label></div>';

  if (d.id) {
    h += '<h3 class="a-h3">Leitung</h3>'
       + '<div class="a-z-detail" style="margin-bottom:8px">Wer hier steht, genehmigt die '
       + 'Rechnungen dieser Abteilung — und braucht zusätzlich die Rolle '
       + '„Abteilungsleitung“ unter Konten &amp; Rollen.</div>';
    if (!AB.konten.length) {
      h += '<div class="a-leer">Noch kein Konto aufgenommen.</div>';
    } else {
      h += '<div class="a-kaestchen">';
      $.each(AB.konten, function (i, k) {
        h += '<div class="a-schalter-zeile"><input type="checkbox" class="abf-leiter" '
           + 'id="abf-l-' + k.wp_user_id + '" value="' + k.wp_user_id + '"'
           + (leiter[k.wp_user_id] ? ' checked' : '') + '>'
           + '<label for="abf-l-' + k.wp_user_id + '">' + esc(k.name)
           + (k.rollen && k.rollen.indexOf('leiter') >= 0
              ? '' : ' <span class="a-z-detail">(ohne Rolle Abteilungsleitung)</span>')
           + '</label></div>';
      });
      h += '</div>';
    }

    h += '<h3 class="a-h3">Mannschaften</h3>'
       + '<div class="a-z-detail" style="margin-bottom:8px">Eine Abteilung <strong>muss</strong> '
       + 'keine Mannschaften haben — die Geschäftsstelle rechnet ab, trainiert aber nicht. '
       + 'Eine Mannschaft gehört zu höchstens einer Abteilung; wird sie hier gesetzt, '
       + 'wandert sie aus der anderen heraus.</div>';
    if (!AB.mannschaften.length) {
      h += '<div class="a-leer">Der interne Bereich meldet keine Mannschaften.</div>';
    } else {
      h += '<div class="a-kaestchen">';
      $.each(AB.mannschaften, function (i, m) {
        var kennung = 'abf-m-' + m.wert.replace(':', '-');
        h += '<div class="a-schalter-zeile"><input type="checkbox" class="abf-mann" '
           + 'id="' + kennung + '" value="' + esc(m.wert) + '"'
           + (drin[m.wert] ? ' checked' : '') + '>'
           + '<label for="' + kennung + '">' + esc(m.name)
           + (m.eigen ? ' <span class="a-chip a-chip-lila">eigene</span>' : '')
           + (m.eigen && !m.aktiv ? ' <span class="a-chip a-chip-grau">abgeschaltet</span>' : '')
           + '</label></div>';
      });
      h += '</div>';
    }
  } else {
    h += '<div class="a-hinweis">Leitung und Mannschaften lassen sich setzen, sobald die '
       + 'Abteilung angelegt ist.</div>';
  }
  return h;
}

var AB_AKT = 0;

function abDialog(a) {
  AB_AKT = a ? a.id : 0;
  $('#d-abt-titel').text(a ? ('Abteilung ' + a.name) : 'Abteilung anlegen');
  $('#d-abt-bd').html(abFormular(a));
  $('#d-abt-ft').html(
    (a ? '<button class="a-btn a-btn-r" id="abf-weg">Löschen</button>' : '')
    + '<button class="a-btn a-btn-nein" data-zu>Abbrechen</button>'
    + '<button class="a-btn a-btn-ok" id="abf-ok">Speichern</button>');
  dlgAuf('d-abteilung');
}

$(document).on('click', '#ab-neu', function () { abDialog(null); });

$(document).on('click', '.ab-bearb', function () {
  var id = parseInt($(this).data('id'), 10);
  var a = null;
  $.each(AB.liste, function (i, x) { if (x.id === id) a = x; });
  if (a) abDialog(a);
});

$(document).on('click', '#abf-ok', function () {
  var $b = $(this).beschaeftigt('Speichert…');
  var felder = {
    name: $('#abf-name').val() || '',
    kurz: $('#abf-kurz').val() || '',
    sortierung: parseInt($('#abf-sort').val(), 10) || 0,
    aktiv: $('#abf-aktiv').is(':checked') ? '1' : '0'
  };
  var fertig = function () { $b.frei('Speichern'); };

  if (!AB_AKT) {
    ajax('lsv07a_abt_neu', felder).done(function (r) {
      if (!r || !r.success) return;
      toast('Abteilung angelegt.', 'gut');
      vAbteilungen();
      /* Direkt weiter: Leitung und Mannschaften sind erst jetzt
         einstellbar, und genau dafür ist die Abteilung angelegt worden. */
      var neu = null;
      $.each(r.data.abteilungen || [], function (i, x) {
        if (x.id === r.data.abteilung_id) neu = x;
      });
      AB.liste = r.data.abteilungen || [];
      if (neu) abDialog(neu);
    }).always(fertig);
    return;
  }

  var mann = $.map($('.abf-mann:checked'), function (e) { return $(e).val(); });
  var leit = $.map($('.abf-leiter:checked'), function (e) { return $(e).val(); });

  $.extend(felder, { abteilung_id: AB_AKT });
  ajax('lsv07a_abt_speichern', felder).done(function (r) {
    if (!r || !r.success) { fertig(); return; }
    ajax('lsv07a_abt_mannschaften', { abteilung_id: AB_AKT, mannschaften: mann.join(',') })
      .done(function (rm) {
        if (rm && rm.success && rm.data.hinweis) toast(rm.data.hinweis, 'fehler');
        ajax('lsv07a_abt_leitung', { abteilung_id: AB_AKT, uids: leit.join(',') })
          .done(function (rl) {
            if (rl && rl.success && rl.data.hinweis) toast(rl.data.hinweis, 'fehler');
            else toast('Gespeichert.', 'gut');
            dlgZu('d-abteilung');
            vAbteilungen();
          }).always(fertig);
      }).fail(fertig);
  }).fail(fertig);
});

/* ── Eigene Mannschaften ──────────────────────────────────────────── */

function abmFormular(m) {
  var d = m || { id: 0, name: '', notiz: '', aktiv: true };
  return '<div class="a-feld"><label for="abmf-name">Name</label>'
       + '<input type="text" id="abmf-name" class="a-ctl" maxlength="120" '
       + 'placeholder="z. B. Vorstand, Geschäftsstelle" value="' + esc(d.name) + '"></div>'
       + '<div class="a-feld"><label for="abmf-notiz">Notiz <span class="a-opt">optional</span></label>'
       + '<input type="text" id="abmf-notiz" class="a-ctl" maxlength="200" '
       + 'value="' + esc(d.notiz) + '"></div>'
       + '<div class="a-schalter-zeile"><input type="checkbox" id="abmf-aktiv"'
       + (d.aktiv ? ' checked' : '') + '>'
       + '<label for="abmf-aktiv">Aktiv — lässt sich einer Abteilung zuordnen</label></div>';
}

var ABM_AKT = 0;

function abmDialog(m) {
  ABM_AKT = m ? m.id : 0;
  $('#d-abt-titel').text(m ? ('Mannschaft ' + m.name) : 'Eigene Mannschaft anlegen');
  $('#d-abt-bd').html(abmFormular(m));
  $('#d-abt-ft').html(
    (m ? '<button class="a-btn a-btn-r" id="abmf-weg">Löschen</button>' : '')
    + '<button class="a-btn a-btn-nein" data-zu>Abbrechen</button>'
    + '<button class="a-btn a-btn-ok" id="abmf-ok">Speichern</button>');
  dlgAuf('d-abteilung');
}

$(document).on('click', '#abm-neu', function () { abmDialog(null); });

$(document).on('click', '.abm-bearb', function () {
  var id = parseInt($(this).data('id'), 10);
  var m = null;
  $.each(AB.eigene, function (i, x) { if (x.id === id) m = x; });
  if (m) abmDialog(m);
});

$(document).on('click', '#abmf-ok', function () {
  var $b = $(this).beschaeftigt('Speichert…');
  var felder = {
    name: $('#abmf-name').val() || '',
    notiz: $('#abmf-notiz').val() || '',
    aktiv: $('#abmf-aktiv').is(':checked') ? '1' : '0'
  };
  var aktion = ABM_AKT ? 'lsv07a_abt_m_speichern' : 'lsv07a_abt_m_neu';
  if (ABM_AKT) felder.mannschaft_id = ABM_AKT;
  ajax(aktion, felder).done(function (r) {
    if (!r || !r.success) return;
    /* Gleicher Name wie im internen Bereich: gewarnt, nicht verboten. */
    if (r.data.hinweis) toast(r.data.hinweis, 'fehler');
    else toast('Gespeichert.', 'gut');
    dlgZu('d-abteilung');
    vAbteilungen();
  }).always(function () { $b.frei('Speichern'); });
});

$(document).on('click', '#abmf-weg, .abm-weg', function () {
  var id = parseInt($(this).data('id'), 10) || ABM_AKT;
  frage('Mannschaft löschen',
    'Hängt sie noch an einer Abteilung, wird nicht gelöscht — dann lässt sie sich '
    + 'abschalten und die Zuordnung bleibt erhalten. Im internen Bereich ändert sich '
    + 'ohnehin nichts.',
    'Löschen', function () {
      ajax('lsv07a_abt_m_weg', { mannschaft_id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-abteilung'); vAbteilungen(); }
      });
    }, 'gefahr');
});

$(document).on('click', '#abf-weg', function () {
  var id = AB_AKT;
  frage('Abteilung löschen',
    'Hängen noch Konten oder Rechnungen daran, wird nicht gelöscht — '
    + 'dann lässt sie sich abschalten und alles bleibt erhalten.',
    'Löschen', function () {
      ajax('lsv07a_abt_weg', { abteilung_id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-abteilung'); vAbteilungen(); }
      });
    }, 'gefahr');
});

})(jQuery);
