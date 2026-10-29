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

var _toastZeit;
function toast(text, art) {
  var $t = $('#a-toast');
  $t.removeClass('zeig ist-fehler ist-gut');
  if (art) $t.addClass('ist-' + art);
  $t.text(text);
  requestAnimationFrame(function () { $t.addClass('zeig'); });
  clearTimeout(_toastZeit);
  _toastZeit = setTimeout(function () { $t.removeClass('zeig'); }, art === 'fehler' ? 6000 : 3500);
}

var _ladeZeit;
function ladenAn()  { clearTimeout(_ladeZeit); $('#a-ladebalken').removeClass('fertig').addClass('laeuft'); }
function ladenAus() {
  $('#a-ladebalken').removeClass('laeuft').addClass('fertig');
  _ladeZeit = setTimeout(function () { $('#a-ladebalken').removeClass('fertig'); }, 320);
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

function fehlerText(xhr) {
  if (xhr && xhr.statusText === 'timeout') {
    return 'Der Server hat nicht rechtzeitig geantwortet. Bitte noch einmal versuchen.';
  }
  var roh = xhr && xhr.responseText;
  try {
    var r = JSON.parse(jsonRetten(roh) || roh);
    if (r && r.data && r.data.message) return r.data.message;
  } catch (e) {}
  return 'Ein Fehler ist aufgetreten.';
}

/* Jede Anfrage meldet einen Fehlschlag von sich aus — auch die fehlende
   Berechtigung, die der Server mit 403 beantwortet. Wer selbst .fail()
   anhängt, bekommt die Standardmeldung nicht. */
function ajax(aktion, daten) {
  ladenAn();
  var xhr = $.ajax({
    url: LSV07A.ajax_url,
    type: 'POST',
    data: $.extend({ action: aktion, nonce: LSV07A.nonce }, daten || {}),
    dataFilter: function (d) { return jsonRetten(d) || d; }
  }).always(ladenAus);

  var eigene = false;
  var urFail = xhr.fail, urAlways = xhr.always, urThen = xhr.then;
  xhr.fail = function () { eigene = true; return urFail.apply(xhr, arguments); };
  xhr.always = function () { eigene = true; return urAlways.apply(xhr, arguments); };
  xhr.then = function (a, b) { if (b) eigene = true; return urThen.apply(xhr, arguments); };
  urFail.call(xhr, function (x, status) {
    if (eigene) return;
    if (status === 'abort' || (x && x.status === 0)) return;
    toast(fehlerText(x), 'fehler');
  });
  return xhr;
}

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

/* Die Zahl an einem Reiter. Eine 0 wird AUSGEBLENDET und nicht als „0"
   gezeigt: Eine Null ist keine Nachricht. Fehlt die Angabe ganz, steht dort
   lieber nichts als „undefined". */
function pruefBadge(n) {
  n = parseInt(n, 10) || 0;
  $('#a-badge-pruef').text(n).prop('hidden', n === 0);
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
  $('#e-gruppen').html(h);

  // ── Fußzeile
  $('#e-gesamt').text(eur(d.gesamt));
  $('#e-fuss').prop('hidden', false);
  /* Den Beleg gibt es, sobald etwas darauf steht — nicht erst nach dem
     Bezahlen. Wer ihn für die eigenen Unterlagen braucht, soll nicht
     danach fragen müssen. */
  $('#e-pdf').data('id', d.id).toggle(d.gesamt > 0 || (d.posten && d.posten.training.length > 0));
  var $ein = $('#e-einreichen');
  if (d.offen) {
    $ein.show().prop('disabled', false).text('Zur Genehmigung einreichen');
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
        if (r && r.success) { toast(r.data.message, 'gut'); abrLaden(); meineListe(); }
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
  /* In einem eigenen Fenster, nicht über window.location: Ein Download
     lässt das aufrufende Fenster stehen, eine Fehlermeldung des Servers
     landet daneben statt anstelle der Abrechnung. Mit window.location
     wäre die Anwendung im Fehlerfall einfach weg. */
  var f = window.open(belegAdresse(id), '_blank');
  if (!f) toast('Der Browser hat das Fenster für den Beleg blockiert. '
              + 'Bitte Pop-ups für diese Seite erlauben.', 'fehler');
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
          if (r && r.success) { toast(r.data.message, 'gut'); abrLaden(); meineListe(); }
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
    var $b = $(this).prop('disabled', true).text('Speichert…');
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
      } else { $b.prop('disabled', false).text('Speichern'); }
    }).fail(function (x) {
      toast(fehlerText(x), 'fehler');
      $b.prop('disabled', false).text('Speichern');
    });
  }).prop('disabled', false).text('Speichern');
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
  var $b = $(this).prop('disabled', true).text('Übernimmt…');
  ajax('lsv07a_training_uebernehmen', { abrechnung_id: A.abr.id, auswahl: JSON.stringify(auswahl) })
    .done(function (r) {
      if (r && r.success) { dlgZu('d-uebernehmen'); toast(r.data.message, 'gut'); abrLaden(); }
      else $b.prop('disabled', false).text('Übernehmen');
    }).fail(function (x) {
      toast(fehlerText(x), 'fehler'); $b.prop('disabled', false).text('Übernehmen');
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
    $('#p-liste').html(h + '</tbody></table></div>');
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
  $('#d-weg-ok').data('weg', o).prop('disabled', false).text('Endgültig löschen');
  dlgAuf('d-weg');
  $('#d-weg-grund').trigger('focus');
}

/* Ab wann gewarnt wird — dieselbe Grenze wie in LSV07A_Loeschung::warnung().
   Sie steht hier ein zweites Mal, weil der Text vor dem Klick dastehen muss
   und die Oberfläche dafür nicht erst fragen kann. Der Server entscheidet
   trotzdem allein; dies ist nur die Ansage. */
function wegWarnung(status) {
  if (status === 'entwurf') return '';
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
  $b.prop('disabled', true).text('Löscht…');
  var daten = { grund: grund };
  daten[o.feld] = o.id;
  ajax(o.aktion, daten).done(function (r) {
    if (r && r.success) {
      toast(r.data.message, 'gut');
      dlgZu('d-weg');
      $.each(o.zu || [], function (i, d) { dlgZu(d); });
      if (typeof o.dann === 'function') o.dann();
    }
  }).always(function () { $b.prop('disabled', false).text('Endgültig löschen'); });
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
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-detail'); pruefListe(); }
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
    $('#k-liste').html(h + '</tbody></table></div>');
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
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-detail'); kasseListe(); }
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
// ════════════════════════════════════════════════════════════════════

function statLaden() {
  var sichtAlle = Z.rollen.indexOf('wart') >= 0 || Z.rollen.indexOf('kasse') >= 0 || Z.ist_admin;
  var nurTrainer = Z.tabs.eigene && !sichtAlle;
  $('#st-umschalter').prop('hidden', !(sichtAlle && Z.tabs.eigene));
  if (!Z.tabs.eigene) A.stDrin = 'alle';
  if (nurTrainer) A.stDrin = 'eigene';

  $('#st-inhalt').html('<div class="a-laden">Wird geladen…</div>');
  var jahr = $('#st-jahr').val();

  if (A.stDrin === 'eigene') {
    ajax('lsv07a_stat_eigene', { jahr: jahr }).done(function (r) {
      if (r && r.success) statEigene(r.data);
    });
  } else {
    ajax('lsv07a_stat_alle', { jahr: jahr }).done(function (r) {
      if (r && r.success) statAlle(r.data);
    });
  }
}

$(document).on('change', '#st-jahr', function () { statLaden(); });
$(document).on('click', '#st-umschalter button', function () {
  $('#st-umschalter button').removeClass('on');
  $(this).addClass('on');
  A.stDrin = $(this).data('st');
  statLaden();
});

function balken(werte, namen) {
  var max = 0;
  $.each(werte, function (k, v) { if (v > max) max = v; });
  if (max <= 0) return '<div class="a-leer">Für dieses Jahr gibt es noch keine Beträge.</div>';
  var h = '<div class="a-karte"><div class="a-karte-bd">';
  $.each(werte, function (k, v) {
    var breite = Math.max(1, Math.round(v / max * 100));
    h += '<div class="a-balken">'
       + '<div class="a-balken-kopf"><span>' + esc(namen[k] || k) + '</span>'
       + '<strong>' + eur(v) + '</strong></div>'
       + '<div class="a-balken-spur">'
       + '<div class="a-balken-wert" style="width:' + breite + '%"></div>'
       + '</div></div>';
  });
  return h + '</div></div>';
}


/* ── Vorjahresvergleich, Hochrechnung, Mannschaften, Export ──────────── */

/* Ein Vergleich sagt nur dann etwas, wenn er die Richtung nennt. Darum
   nicht bloss zwei Zahlen nebeneinander, sondern auch der Unterschied. */
function vergleichKachel(jetzt, vorjahr) {
  if (!vorjahr) return '';
  var diff = jetzt - vorjahr.gesamt;
  var sub;
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
  var h = '<div class="a-karte"><div class="a-karte-hd"><h2>Quartal gegen Vorjahresquartal</h2></div>'
        + '<div class="a-tbl-wrap" style="border:0;border-radius:0"><table class="a-tbl"><thead><tr>'
        + '<th>Quartal</th><th class="a-zahl">' + vorjahr.jahr + '</th>'
        + '<th class="a-zahl">heute</th><th class="a-zahl">Unterschied</th></tr></thead><tbody>';
  $.each(['Q1', 'Q2', 'Q3', 'Q4'], function (i, q) {
    var alt = parseFloat(vorjahr.quartale[q] || 0);
    var neu = parseFloat(jetzt[q] || 0);
    var d = neu - alt;
    h += '<tr><td data-label="Quartal">' + q + '</td>'
       + '<td data-label="Vorjahr" class="a-zahl">' + eur(alt) + '</td>'
       + '<td data-label="Jetzt" class="a-zahl">' + eur(neu) + '</td>'
       + '<td data-label="Unterschied" class="a-zahl">'
       + (d === 0 ? '–' : (d > 0 ? '+' : '−') + eur(Math.abs(d))) + '</td></tr>';
  });
  return h + '</tbody></table></div></div>';
}

function mannschaftsTabelle(liste) {
  if (!liste || !liste.length) return '';
  var h = '<div class="a-karte"><div class="a-karte-hd"><h2>Nach Mannschaft</h2></div>'
        + '<div class="a-tbl-wrap" style="border:0;border-radius:0"><table class="a-tbl"><thead><tr>'
        + '<th>Mannschaft</th><th class="a-zahl">Posten</th><th class="a-zahl">Stunden</th>'
        + '<th class="a-zahl">Betrag</th></tr></thead><tbody>';
  $.each(liste, function (i, m) {
    h += '<tr><td data-label="Mannschaft">' + esc(m.name) + '</td>'
       + '<td data-label="Posten" class="a-zahl">' + m.anzahl + '</td>'
       + '<td data-label="Stunden" class="a-zahl">' + (m.stunden ? zahl(m.stunden, 1) : '–') + '</td>'
       + '<td data-label="Betrag" class="a-zahl"><strong>' + eur(m.betrag) + '</strong></td></tr>';
  });
  return h + '</tbody></table></div></div>';
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

function csvZahl(v) {
  return '"' + Number(v || 0).toFixed(2).replace('.', ',') + '"';
}

function csvLaden(name, zeilen) {
  var text = zeilen.map(function (z) { return z.map(csvFeld).join(';'); }).join('\r\n');
  var blob = new Blob(['\ufeff' + text], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

var ST_DATEN = null;

$(document).on('click', '#st-export', function () {
  var d = ST_DATEN;
  if (!d) return;
  var z = [];
  if (d.personen) {
    z.push(['Statistik', 'Jahr ' + d.jahr]);
    z.push([]);
    z.push(['Person', 'Training', 'Wettkämpfe', 'Fahrtkosten', 'Vorbereitung', 'Sonstiges',
            'Stunden', 'Gesamt']);
    $.each(d.personen, function (i, p) {
      z.push([p.name, p.nach_typ.training, p.nach_typ.wettkampf, p.nach_typ.fahrt,
              p.nach_typ.vorbereitung, p.nach_typ.sonstiges, p.stunden, p.gesamt]);
    });
    z.push(['Gesamt', d.nach_typ.training, d.nach_typ.wettkampf, d.nach_typ.fahrt,
            d.nach_typ.vorbereitung, d.nach_typ.sonstiges, '', d.gesamt]);
  } else {
    z.push(['Statistik', d.name + ', Jahr ' + d.jahr]);
    z.push([]);
    z.push(['Quartal', 'Status', 'Training', 'Wettkämpfe', 'Fahrtkosten', 'Vorbereitung',
            'Sonstiges', 'Gesamt']);
    $.each(d.quartale, function (i, q) {
      z.push([q.quartal, q.status_name, q.nach_typ.training, q.nach_typ.wettkampf,
              q.nach_typ.fahrt, q.nach_typ.vorbereitung, q.nach_typ.sonstiges, q.gesamt]);
    });
    z.push(['Gesamt', '', d.nach_typ.training, d.nach_typ.wettkampf, d.nach_typ.fahrt,
            d.nach_typ.vorbereitung, d.nach_typ.sonstiges, d.gesamt]);
  }
  if (d.nach_mannschaft && d.nach_mannschaft.length) {
    z.push([]);
    z.push(['Nach Mannschaft', 'Posten', 'Stunden', 'Betrag']);
    $.each(d.nach_mannschaft, function (i, m) {
      z.push([m.name, m.anzahl, m.stunden, m.betrag]);
    });
  }
  if (d.vorjahr) {
    z.push([]);
    z.push(['Vorjahr ' + d.vorjahr.jahr, d.vorjahr.gesamt]);
  }
  /* Zahlen mit Komma, damit Excel sie als Zahlen erkennt. */
  var fertig = z.map(function (zeile) {
    return zeile.map(function (w, i) {
      return (typeof w === 'number') ? Number(w).toFixed(2).replace('.', ',') : w;
    });
  });
  csvLaden('Abrechnung-Statistik-' + d.jahr + '.csv', fertig);
  toast('Tabelle heruntergeladen.', 'gut');
});

function exportKnopf() {
  return '<div style="margin-top:14px"><button class="a-btn a-btn-b" id="st-export">'
       + 'Als Tabelle herunterladen (CSV)</button></div>';
}

function statEigene(d) {
  ST_DATEN = d;
  var jeQuartal = {};
  $.each(d.quartale, function (i, q) { jeQuartal[q.quartal] = q.gesamt; });

  var h = '<div class="a-kacheln">'
    + '<div class="a-kachel ist-gesamt"><div class="a-kachel-lbl">Jahr ' + d.jahr + '</div>'
    + '<div class="a-kachel-wert">' + eur(d.gesamt) + '</div></div>'
    + '<div class="a-kachel"><div class="a-kachel-lbl">Stunden</div>'
    + '<div class="a-kachel-wert">' + zahl(d.stunden, 1) + '</div>'
    + '<div class="a-kachel-sub">Training und Vorbereitung</div></div>'
    + vergleichKachel(d.gesamt, d.vorjahr)
    + hochrechnungKachel(d.hochrechnung)
    + '</div>';

  h += '<div class="a-karte"><div class="a-karte-hd"><h2>Quartale</h2></div>'
     + '<div class="a-tbl-wrap" style="border:0;border-radius:0"><table class="a-tbl"><thead><tr>'
     + '<th>Quartal</th><th>Status</th><th class="a-zahl">Training</th><th class="a-zahl">Wettkämpfe</th>'
     + '<th class="a-zahl">Fahrt</th><th class="a-zahl">Vorb.</th><th class="a-zahl">Sonst.</th>'
     + '<th class="a-zahl">Gesamt</th></tr></thead><tbody>';
  $.each(d.quartale, function (i, q) {
    h += '<tr><td data-label="Quartal">' + esc(q.quartal) + '</td>'
       + '<td data-label="Status">' + statusChip(q.status, q.status_name) + '</td>'
       + '<td data-label="Training" class="a-zahl">' + eur(q.nach_typ.training) + '</td>'
       + '<td data-label="Wettkämpfe" class="a-zahl">' + eur(q.nach_typ.wettkampf) + '</td>'
       + '<td data-label="Fahrt" class="a-zahl">' + eur(q.nach_typ.fahrt) + '</td>'
       + '<td data-label="Vorbereitung" class="a-zahl">' + eur(q.nach_typ.vorbereitung) + '</td>'
       + '<td data-label="Sonstiges" class="a-zahl">' + eur(q.nach_typ.sonstiges) + '</td>'
       + '<td data-label="Gesamt" class="a-zahl"><strong>' + eur(q.gesamt) + '</strong></td></tr>';
  });
  h += '</tbody></table></div></div>';
  h += quartalsVergleich(jeQuartal, d.vorjahr);
  h += mannschaftsTabelle(d.nach_mannschaft);
  h += '<h2 class="a-h2">Wofür</h2>' + balken(d.nach_typ, d.typ_namen);
  h += exportKnopf();
  $('#st-inhalt').html(h);
}

function statAlle(d) {
  ST_DATEN = d;
  var h = '<div class="a-kacheln">'
    + '<div class="a-kachel ist-gesamt"><div class="a-kachel-lbl">Jahr ' + d.jahr + '</div>'
    + '<div class="a-kachel-wert">' + eur(d.gesamt) + '</div></div>'
    + '<div class="a-kachel"><div class="a-kachel-lbl">Personen</div>'
    + '<div class="a-kachel-wert">' + d.personen.length + '</div></div>'
    + '<div class="a-kachel"><div class="a-kachel-lbl">Noch nicht gezahlt</div>'
    + '<div class="a-kachel-wert">' + eur((d.nach_status.genehmigt || 0)) + '</div></div>'
    + vergleichKachel(d.gesamt, d.vorjahr)
    + hochrechnungKachel(d.hochrechnung)
    + '</div>';

  if (!d.personen.length) {
    $('#st-inhalt').html(h + '<div class="a-leer">Für dieses Jahr gibt es noch keine Abrechnungen.</div>'
      + quartalsVergleich(d.quartale || {}, d.vorjahr));
    return;
  }

  h += '<div class="a-karte"><div class="a-karte-hd"><h2>Nach Person</h2></div>'
     + '<div class="a-tbl-wrap" style="border:0;border-radius:0"><table class="a-tbl"><thead><tr>'
     + '<th>Name</th><th class="a-zahl">Training</th><th class="a-zahl">Wettkämpfe</th>'
     + '<th class="a-zahl">Fahrt</th><th class="a-zahl">Vorb.</th><th class="a-zahl">Sonst.</th>'
     + '<th class="a-zahl">Stunden</th><th class="a-zahl">Gesamt</th></tr></thead><tbody>';
  $.each(d.personen, function (i, p) {
    h += '<tr><td data-label="Name">' + esc(p.name) + '</td>'
       + '<td data-label="Training" class="a-zahl">' + eur(p.nach_typ.training) + '</td>'
       + '<td data-label="Wettkämpfe" class="a-zahl">' + eur(p.nach_typ.wettkampf) + '</td>'
       + '<td data-label="Fahrt" class="a-zahl">' + eur(p.nach_typ.fahrt) + '</td>'
       + '<td data-label="Vorbereitung" class="a-zahl">' + eur(p.nach_typ.vorbereitung) + '</td>'
       + '<td data-label="Sonstiges" class="a-zahl">' + eur(p.nach_typ.sonstiges) + '</td>'
       + '<td data-label="Stunden" class="a-zahl">' + zahl(p.stunden, 1) + '</td>'
       + '<td data-label="Gesamt" class="a-zahl"><strong>' + eur(p.gesamt) + '</strong></td></tr>';
  });
  h += '</tbody></table></div></div>';
  h += quartalsVergleich(d.quartale || {}, d.vorjahr);
  h += mannschaftsTabelle(d.nach_mannschaft);
  h += '<h2 class="a-h2">Wofür</h2>' + balken(d.nach_typ, d.typ_namen);
  h += '<h2 class="a-h2">Nach Stand</h2>' + balken(d.nach_status, d.status_namen);
  h += exportKnopf();
  $('#st-inhalt').html(h);
}

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
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_einstellung_save', { auto_training: $('#z-auto').is(':checked') ? 1 : 0 })
    .done(function (r) { if (r && r.success) toast(r.data.message, 'gut'); })
    .always(function () { $b.prop('disabled', false).text('Speichern'); });
});

$('#z-speichern').on('click', function () {
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_zahlungsdaten_save', {
    kontoinhaber: $('#z-inhaber').val(), iban: $('#z-iban').val(), bic: $('#z-bic').val(),
    strasse: $('#z-strasse').val(), plz: $('#z-plz').val(), ort: $('#z-ort').val()
  }).done(function (r) {
    if (r && r.success) { toast(r.data.message, 'gut'); geladen.eigene = false; }
  }).always(function () { $b.prop('disabled', false).text('Speichern'); });
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
    /* Der Tresor zuerst: Solange die Belege verschlüsselt liegen, hängt
       ihr Schutz nicht an der Server-Konfiguration. Deshalb steht der
       Serverhinweis darunter und nicht darüber. */
    var t = r.data.tresor;
    if (t && !t.aktiv) {
      h += '<div class="a-hinweis ist-fehler"><strong>Belege liegen unverschlüsselt.</strong> '
         + esc(t.hinweis) + '</div>';
    } else if (t) {
      h += '<div class="a-hinweis"><strong>Belege sind verschlüsselt</strong> ('
         + esc(t.name) + '). Der Schlüssel liegt in ' + esc(t.schluessel_aus) + '. '
         + (t.schluessel_aus === 'Datenbank'
            ? 'Eine Sicherung der Datenbank trägt ihn mit — die Belege lassen sich nach '
              + 'einer Wiederherstellung also öffnen.'
            : 'Sichern Sie wp-config.php mit: Ohne diese Datei sind die Belege auch aus '
              + 'einer Datenbanksicherung nicht mehr zu öffnen.')
         + '</div>';
    }
    /* Liest dieser Webserver die .htaccess nicht, sind die Belegdateien
       abrufbar — verschlüsselt zwar, aber abrufbar. */
    if (r.data.hinweis_server) {
      h += '<div class="a-hinweis ist-warn"><strong>Belegordner prüfen.</strong> '
         + esc(r.data.hinweis_server)
         + (t && t.aktiv
            ? ' Die Dateien sind verschlüsselt, ein Zugriff darauf ergibt also nur Rauschen '
              + '— der Ordner sollte trotzdem zu sein.'
            : '')
         + '</div>';
    }
    h += '<div class="a-karte"><div class="a-karte-hd"><h2>Konten</h2>'
       + '<button class="a-btn a-btn-klein a-btn-p" id="v-konto-neu">+ Konto aufnehmen</button></div>'
       + '<div class="a-karte-bd" style="padding-bottom:0"><div class="a-hinweis">'
       + 'Hier steht alles, was ein einzelnes Konto betrifft: Rollen, Abteilung, Satz, '
       + 'Abrechnungsart, Mitteilungsadresse, persönliche Pauschalen — und bei einem Wart '
       + 'seine Zuständigkeit für die Quartalsprüfung.</div></div>';
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
    $('#v-konten').html(h);
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
  $('#d-posten-ok').off('click').on('click', kontoSpeichern).prop('disabled', false).text('Speichern');
  dlgAuf('d-posten');
});

$(document).on('click', '.v-konto-bearb', function () {
  var uid = parseInt($(this).data('uid'), 10);
  var p = null;
  $.each(A.konten || [], function (i, k) { if (k.wp_user_id === uid) p = k; });
  if (!p) return;
  $('#d-posten-titel').text('Konto bearbeiten');
  $('#d-posten-bd').html(kontoDialog(p));
  $('#d-posten-ok').off('click').on('click', kontoSpeichern).prop('disabled', false).text('Speichern');
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
  var $b = $(this).prop('disabled', true).text('Speichert…');
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
      $b.prop('disabled', false).text('Speichern');
    }
  }).fail(function (x) {
    toast(fehlerText(x), 'fehler'); $b.prop('disabled', false).text('Speichern');
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
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_adm_config_speichern', {
    wk_satz: $('#c-wk').val(), km_satz: $('#c-km').val(), km_mindest: $('#c-kmmin').val(),
    km_hin_rueck: $('#c-hinrueck').is(':checked') ? 1 : 0,
    wartezeit_min: $('#c-wartezeit').val(), verein: $('#c-verein').val(),
    leiter_iban: $('#c-leiteriban').val()
  }).done(function (r) {
    if (r && r.success) { toast(r.data.message, 'gut'); LSV07A.config = r.data.config; geladen.eigene = false; }
  }).always(function () { $b.prop('disabled', false).text('Speichern'); });
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
    $('#v-pauschalen').html(h);
  });
}

/* Den allgemeinen Betrag speichern (Wochentag 0). Ein leeres Feld
   entfernt ihn — dann ist für diese Mannschaft nichts hinterlegt. */
$(document).on('click', '.pa-save', function () {
  var mid = $(this).data('mid');
  var wert = $('.pa-wert[data-mid="' + mid + '"]').val();
  var $b = $(this).prop('disabled', true).text('…');
  ajax('lsv07a_adm_pauschale_speichern', { mannschaft_id: mid, wochentag: 0, betrag: wert })
    .done(function (r) { if (r && r.success) { toast(r.data.message, 'gut'); vPauschalen(); } })
    .always(function () { $b.prop('disabled', false).text('Speichern'); });
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
  var $b = $(this).prop('disabled', true).text('Speichert…');
  var felder = $('#d-tage-bd input[data-tag]').toArray();
  /* Nacheinander statt alle auf einmal: So ist am Ende sicher alles
     gespeichert, und ein Fehler bleibt einem einzelnen Tag zuzuordnen. */
  (function weiter(i) {
    if (i >= felder.length) {
      $b.prop('disabled', false).text('Speichern');
      dlgZu('d-tage'); toast('Wochentage gespeichert.', 'gut'); vPauschalen();
      return;
    }
    var f = $(felder[i]);
    ajax('lsv07a_adm_pauschale_speichern',
         { mannschaft_id: mid, wochentag: f.data('tag'), betrag: f.val() })
      .done(function () { weiter(i + 1); })
      .fail(function () { $b.prop('disabled', false).text('Speichern'); });
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
    $('#v-saisons').html(h + '</div>');
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
    var $b = $(this).prop('disabled', true).text('Speichert…');
    ajax('lsv07a_adm_saison_speichern', {
      id: $('#sa-id').val(), name: $('#sa-name').val(),
      start_datum: $('#sa-von').val(), ende_datum: $('#sa-bis').val()
    }).done(function (r) {
      if (r && r.success) { dlgZu('d-posten'); toast(r.data.message, 'gut'); vSaisons(); }
      else $b.prop('disabled', false).text('Speichern');
    }).fail(function (x) { toast(fehlerText(x), 'fehler'); $b.prop('disabled', false).text('Speichern'); });
  }).prop('disabled', false).text('Speichern');
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
    var $b = $(this).prop('disabled', true).text('Speichert…');
    ajax('lsv07a_adm_slot_speichern', {
      id: $('#zt-id').val(), saison_id: $('#zt-s').val(), mannschaft_id: $('#zt-m').val(),
      wochentag: $('#zt-t').val(), zeit_von: $('#zt-von').val(), zeit_bis: $('#zt-bis').val()
    }).done(function (r) {
      if (r && r.success) { dlgZu('d-posten'); toast(r.data.message, 'gut'); vZeiten($('#zt-saison').val()); }
      else $b.prop('disabled', false).text('Speichern');
    }).fail(function (x) { toast(fehlerText(x), 'fehler'); $b.prop('disabled', false).text('Speichern'); });
  }).prop('disabled', false).text('Speichern');
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

    var h = '<div class="a-hinweis">Eine Mitteilung der Administration erscheint bei den '
          + 'Empfängern unter <strong>Mitteilungen</strong> (Glocke oben rechts). Sie geht '
          + 'nur an Konten mit einer Rolle — wer keine hat, kann die Abrechnung nicht öffnen. '
          + 'Per E-Mail geht sie nicht: Was hier steht, bleibt im System.</div>'
          + '<div class="a-karte a-schmal">'
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
      $b.prop('disabled', true).text('Sendet…');
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
      }).always(function () { $b.prop('disabled', false).text('Mitteilung senden'); });
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
    /* Neu laden statt nachzeichnen: Es ändert sich, welche Bereiche es
       überhaupt gibt — da ist ein sauberer Neuaufbau ehrlicher als der
       Versuch, die halbe Oberfläche umzubauen. */
    setTimeout(function () { window.location.reload(); }, 600);
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

    var h = '<div class="a-hinweis">Sehen Sie die Abrechnung so, wie eine bestimmte '
          + '<strong>Person</strong> sie sieht — mit ihren Rollen, ihrer Abteilung und '
          + 'ihren Abrechnungen. Es wird dabei <strong>nicht in ihrem Namen gehandelt</strong>: '
          + 'Solange die Ansicht läuft, ist jeder Schreibvorgang gesperrt, und im Protokoll '
          + 'steht nie ihr Name. Nach zwei Stunden endet sie von selbst. '
          + 'Dass Sie hineingesehen haben, wird protokolliert.</div>';

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
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_adm_mail_speichern', mailSammeln())
    .done(function (r) { if (r && r.success) { toast(r.data.message, 'gut'); vMail(); } })
    .always(function () { $b.prop('disabled', false).text('Speichern'); });
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
      ajax('lsv07a_adm_mail_probe', { an: $('#pm-an').val() || '', art: $('#pm-art').val() || 'genehmigt' })
        .done(function (r) { if (r && r.success) toast(r.data.message, 'gut'); });
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
  var $b = $(this).prop('disabled', true).text('Setzt…');
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
  }).always(function () { $b.prop('disabled', false).text('Stand setzen'); });
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
  $('#r-liste').html(h + '</tbody></table></div>');
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
    h += '<li><a href="' + esc(dlUrl('datei', { datei_id: d.id })) + '">' + esc(d.name) + '</a>'
       + ' <span class="a-z-detail">' + esc(d.groesse_text) + '</span>'
       + (x.darf_aendern
          ? ' <button class="a-btn a-btn-klein a-btn-r rf-weg" data-id="' + d.id + '">Entfernen</button>'
          : '')
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
      toast('Die Rechnung liess sich nicht anlegen.', 'fehler');
      return;
    }
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
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_r_speichern', $.extend({ rechnung_id: R_AKT }, rFelder())).done(function (r) {
    if (r && r.success && r.data && r.data.rechnung) {
      toast('Gespeichert.', 'gut');
      rDialog(r.data.rechnung);
      rLaden();
    }
  }).always(function () { $b.prop('disabled', false).text('Speichern'); });
});

/* Vor dem Hochladen wird gespeichert. Sonst wäre nach dem Upload das
   Getippte weg: Der Beleg kommt frisch vom Server zurück, und der
   Dialog wird dabei neu gezeichnet. */
$(document).on('change', '#rf-datei', function () {
  $('#rf-hoch').prop('disabled', !this.files || !this.files.length);
});

$(document).on('click', '#rf-hoch', function () {
  var datei = ($('#rf-datei')[0].files || [])[0];
  if (!datei) { toast('Bitte zuerst eine Datei wählen.', 'fehler'); return; }
  var $b = $(this).prop('disabled', true).text('Lädt hoch…');
  var id = R_AKT;

  ajax('lsv07a_r_speichern', $.extend({ rechnung_id: id }, rFelder())).done(function () {
    var fd = new FormData();
    fd.append('action', 'lsv07a_r_beleg');
    fd.append('nonce', LSV07A.nonce);
    fd.append('rechnung_id', id);
    fd.append('datei', datei);

    ladenAn();
    $.ajax({ url: LSV07A.ajax_url, type: 'POST', data: fd,
             processData: false, contentType: false })
      .always(ladenAus)
      .done(function (r) {
        if (r && r.success && r.data && r.data.rechnung) {
          toast('Beleg angehängt.', 'gut'); rDialog(r.data.rechnung); rLaden();
        } else {
          toast((r && r.data && r.data.message) || 'Der Beleg kam nicht an.', 'fehler');
        }
      })
      .fail(function (x) { toast(fehlerText(x), 'fehler'); })
      .always(function () { $b.prop('disabled', false).text('Beleg hochladen'); });
  }).fail(function () { $b.prop('disabled', false).text('Beleg hochladen'); });
});

$(document).on('click', '.rf-weg', function () {
  var did = $(this).data('id');
  frage('Beleg entfernen', 'Die Datei wird dabei gelöscht.', 'Entfernen', function () {
    ajax('lsv07a_r_beleg_weg', { datei_id: did }).done(function (r) {
      if (r && r.success && r.data && r.data.rechnung) { rDialog(r.data.rechnung); rLaden(); }
    });
  }, 'gefahr');
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
    $('#g-liste').html(h + '</tbody></table></div>');
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
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-rechnung'); gLaden(); }
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
  $('#d-rzur-ok').data('id', id).prop('disabled', false).text('Zurückgeben');
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
  $b.prop('disabled', true).text('Gibt zurück…');
  ajax('lsv07a_r_zurueck', { rechnung_id: $b.data('id'), grund: grund }).done(function (r) {
    if (r && r.success) {
      toast(r.data.message, 'gut');
      dlgZu('d-rzurueck'); dlgZu('d-rechnung');
      gLaden();
    }
  }).always(function () { $b.prop('disabled', false).text('Zurückgeben'); });
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
    $('#kr-liste').html(h + '</tbody></table></div>');
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
      toast('Der QR-Code liess sich nicht erzeugen.', 'fehler');
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
      + '</dl>'
      + '<div class="a-hinweis">Mit der Banking-App scannen — Empfänger, IBAN, Betrag '
      + 'und Verwendungszweck stehen dann fertig im Formular. '
      + 'Prüfen Sie die Angaben in der App, bevor Sie freigeben.</div>');
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
         + '<a class="a-btn a-btn-klein a-btn-p" href="'
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
  $('#ar-liste').html(h + '</table></div>');
}

$(document).on('click', '.ar-jahr', function () { arLaden(parseInt($(this).data('jahr'), 10)); });

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
    h += '<div class="a-hinweis">Die Abteilung ist die <strong>Genehmigungseinheit</strong>: '
       + 'Jedes Konto gehört zu einer, und ihre Leitung genehmigt die Rechnungen, '
       + 'die daraus kommen. Der interne Bereich bleibt davon unberührt — seine '
       + 'Mannschaften werden nur gelesen.</div>';

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
       + '<div class="a-karte-bd" style="padding-bottom:0"><div class="a-hinweis">'
       + 'Mannschaften, die es <strong>nur in der Abrechnung</strong> gibt — Vorstand, '
       + 'Geschäftsstelle, eine Projektgruppe. Der interne Bereich bekommt davon nichts: '
       + 'Dort wird nichts angelegt und nichts geändert. Zum Abrechnen von Trainings und '
       + 'Wettkämpfen dienen sie nicht, nur zur Gliederung.</div></div>';
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
    $('#v-abteilungen').html(h);
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
    var h = '<div class="a-hinweis">Abgeleitet aus den Mannschaften, in denen diese Konten '
          + 'bisher abgerechnet haben. <strong>Vorgeschlagen wird nur</strong> — zugeordnet '
          + 'wird mit dem Knopf darunter.</div>'
          + '<ul class="a-dateien">'
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
  var $b = $(this).prop('disabled', true).text('Speichert…');
  var felder = {
    name: $('#abf-name').val() || '',
    kurz: $('#abf-kurz').val() || '',
    sortierung: parseInt($('#abf-sort').val(), 10) || 0,
    aktiv: $('#abf-aktiv').is(':checked') ? '1' : '0'
  };
  var fertig = function () { $b.prop('disabled', false).text('Speichern'); };

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
  return '<div class="a-hinweis">Diese Mannschaft entsteht <strong>nur in der '
       + 'Abrechnung</strong>. Im internen Bereich wird dafür nichts angelegt.</div>'
       + '<div class="a-feld"><label for="abmf-name">Name</label>'
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
  var $b = $(this).prop('disabled', true).text('Speichert…');
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
  }).always(function () { $b.prop('disabled', false).text('Speichern'); });
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
