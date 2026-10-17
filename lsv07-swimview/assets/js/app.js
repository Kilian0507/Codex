/* global LSV07S, jQuery */
(function ($) {
'use strict';

/* ════════════════════════════════════════════════════════════════════
   LSV07 SwimView

   Eine Seite, drei Zustände: Anmeldung, Schwimmeransicht, Trainerportal.
   Gerechnet und geprüft wird auf dem Server — hier wird nur dargestellt
   und erfasst. Was hier nicht ankommt, gibt es auch nicht: Die Oberfläche
   baut ihr Menü aus dem, was der Server als freigegeben meldet, und filtert
   nichts selbst heraus.
   ════════════════════════════════════════════════════════════════════ */

if (!window.LSV07S) return;

var S = {
  start:   LSV07S.start || null,
  seite:   'start',
  geladen: {},
  portal:  { mannschaft: 0, basis: null, stand: null }
};

// ── Grundlagen ──────────────────────────────────────────────────────

function esc(s) {
  return $('<span>').text(s === null || s === undefined ? '' : s).html();
}

function de(d) {
  if (!d) return '';
  var t = String(d).slice(0, 10).split('-');
  return t.length === 3 ? (t[2] + '.' + t[1] + '.' + t[0]) : String(d);
}

function deZeit(d) {
  if (!d) return '';
  var s = String(d);
  return de(s) + (s.length > 10 ? (', ' + s.slice(11, 16) + ' Uhr') : '');
}

function groesse(bytes) {
  var b = parseInt(bytes, 10) || 0;
  if (b >= 1048576) return (Math.round(b / 104857.6) / 10) + ' MB';
  return Math.max(1, Math.round(b / 1024)) + ' kB';
}

var _toastZeit;
function toast(text, art) {
  var $t = $('#s-toast');
  $t.removeClass('zeig ist-fehler ist-gut');
  if (art) $t.addClass('ist-' + art);
  $t.text(text);
  requestAnimationFrame(function () { $t.addClass('zeig'); });
  clearTimeout(_toastZeit);
  _toastZeit = setTimeout(function () { $t.removeClass('zeig'); }, art === 'fehler' ? 6000 : 3500);
}

var _ladeZeit;
function ladenAn() {
  clearTimeout(_ladeZeit);
  $('#s-ladebalken').removeClass('fertig').addClass('laeuft');
}
function ladenAus() {
  $('#s-ladebalken').removeClass('laeuft').addClass('fertig');
  _ladeZeit = setTimeout(function () { $('#s-ladebalken').removeClass('fertig'); }, 320);
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
    console.warn('LSV07S: Vor der Serverantwort stand fremde Ausgabe — ' + text.slice(0, i));
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
  return 'Etwas ist schiefgegangen. Bitte versuch es noch einmal.';
}

/**
 * Eine Anfrage an den Server. Sie meldet einen Fehlschlag von sich aus —
 * auch die fehlende Freigabe, die der Server mit 403 beantwortet. Wer
 * selbst .fail() anhängt, bekommt die Standardmeldung nicht.
 *
 * Zwei Antworten behandelt sie zusätzlich selbst, weil sie überall
 * vorkommen können: „nicht mehr angemeldet" führt zurück zur Anmeldung,
 * „Sitzung abgelaufen" bittet ums Neuladen.
 */
function ajax(aktion, daten) {
  ladenAn();
  var xhr = $.ajax({
    url: LSV07S.ajax_url,
    type: 'POST',
    data: $.extend({ action: aktion, token: LSV07S.token }, daten || {}),
    dataFilter: function (d) { return jsonRetten(d) || d; }
  }).always(ladenAus);

  var eigene = false;
  var urFail = xhr.fail, urAlways = xhr.always, urThen = xhr.then;
  xhr.fail = function () { eigene = true; return urFail.apply(xhr, arguments); };
  xhr.always = function () { eigene = true; return urAlways.apply(xhr, arguments); };
  xhr.then = function (a, b) { if (b) eigene = true; return urThen.apply(xhr, arguments); };

  urFail.call(xhr, function (x, status) {
    var r = null;
    try { r = JSON.parse(jsonRetten(x && x.responseText) || (x && x.responseText)); } catch (e) {}
    if (r && r.data && r.data.abgemeldet) { zurueckZurAnmeldung(r.data.message); return; }
    if (eigene) return;
    if (status === 'abort' || (x && x.status === 0)) return;
    toast(fehlerText(x), 'fehler');
  });
  return xhr;
}

/** Eine Anfrage mit Dateien (Trainerportal, WordPress-Nonce). */
function ajaxT(aktion, daten, formdaten) {
  ladenAn();
  var opt = {
    url: LSV07S.ajax_url,
    type: 'POST',
    dataFilter: function (d) { return jsonRetten(d) || d; }
  };
  if (formdaten) {
    formdaten.append('action', aktion);
    formdaten.append('nonce', LSV07S.nonce);
    opt.data = formdaten;
    opt.processData = false;
    opt.contentType = false;
    opt.dataType = 'json';
  } else {
    opt.data = $.extend({ action: aktion, nonce: LSV07S.nonce }, daten || {});
  }
  var xhr = $.ajax(opt).always(ladenAus);

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

/** Die Adresse eines Downloads. Der Token steht darin, nicht im Rumpf. */
function dlUrl(aktion, params) {
  var u = LSV07S.ajax_url + '?action=' + encodeURIComponent(aktion)
        + '&token=' + encodeURIComponent(LSV07S.token);
  $.each(params || {}, function (k, v) {
    if (v === undefined || v === null || v === '') return;
    u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
  });
  return u;
}

// ── Sinnbilder ──────────────────────────────────────────────────────
/* Je Bereich eines, in derselben Strichstärke. Sie stehen hier und nicht
   im HTML, weil das Menü aus den freigegebenen Bereichen entsteht. */
var IKONEN = {
  haken: '<path d="M20 6 9 17l-5-5"/>',
  notiz: '<path d="M4 4h12l4 4v12H4z"/><path d="M14 4v5h5"/><path d="M8 13h8M8 17h5"/>',
  liste: '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
  buch:  '<path d="M4 5a2 2 0 0 1 2-2h13v18H6a2 2 0 0 1-2-2z"/><path d="M8 7h7M8 11h7"/>',
  pokal: '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 5H5v2a3 3 0 0 0 3 3M16 5h3v2a3 3 0 0 1-3 3"/><path d="M10 13v3h4v-3M8 20h8"/>',
  uhr:   '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2M9 2h6"/>',
  stift: '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="M14 6l4 4"/>',
  plan:  '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/><path d="M7 13h4M7 17h7"/>',
  brief: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  heim:  '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/>'
};

function ikon(name) {
  var pfad = IKONEN[name] || IKONEN.notiz;
  return '<svg viewBox="0 0 24 24" aria-hidden="true">' + pfad + '</svg>';
}

// ── Navigation ──────────────────────────────────────────────────────

function navBauen() {
  var $nav = $('#s-nav').empty();

  $nav.append('<button type="button" class="s-nb on" data-ziel="start">'
    + ikon('heim') + '<span>Start</span></button>');

  $.each((S.start && S.start.bereiche) || [], function (i, b) {
    var zahl = '';
    if (b.key === 'aufgaben' && S.start.aufgaben_offen > 0) {
      zahl = '<span class="s-badge" data-zahl="aufgaben">' + S.start.aufgaben_offen + '</span>';
    }
    if (b.key === 'nachrichten' && S.start.ungelesen > 0) {
      zahl = '<span class="s-badge" data-zahl="nachrichten">' + S.start.ungelesen + '</span>';
    }
    $nav.append('<button type="button" class="s-nb" data-ziel="' + esc(b.key) + '">'
      + ikon(b.icon) + '<span>' + esc(b.name) + '</span>' + zahl + '</button>');
  });
}

function zeige(ziel) {
  if (ziel !== 'start' && !bereichFrei(ziel)) {
    toast('Dieser Bereich ist für deine Mannschaft nicht freigegeben.', 'fehler');
    ziel = 'start';
  }
  S.seite = ziel;
  $('.s-nb').removeClass('on').filter('[data-ziel="' + ziel + '"]').addClass('on');
  $('.s-seite').removeClass('on');
  $('#s-s-' + ziel).addClass('on');
  menueZu();
  try { window.scrollTo(0, 0); } catch (e) {}
  try { window.location.hash = ziel; } catch (e) {}
  laden(ziel);
}

function bereichFrei(key) {
  var frei = false;
  $.each((S.start && S.start.bereiche) || [], function (i, b) {
    if (b.key === key) { frei = true; return false; }
  });
  return frei;
}

function laden(ziel, erzwingen) {
  if (ziel === 'start') { startBauen(); return; }
  if (S.geladen[ziel] && !erzwingen) return;
  S.geladen[ziel] = true;
  if      (ziel === 'anwesenheit')   anwLaden();
  else if (ziel === 'vermerke')      verLaden();
  else if (ziel === 'aufgaben')      aufLaden();
  else if (ziel === 'dokumentation') dokLaden();
  else if (ziel === 'wettkaempfe')   wkLaden();
  else if (ziel === 'bestzeiten')    bzLaden();
  else if (ziel === 'reflexion')     reflLaden();
  else if (ziel === 'trainingsplan') tpLaden();
  else if (ziel === 'nachrichten')   naLaden();
}

function menueZu() {
  $('#s-root').removeClass('menue-auf');
  $('#s-menue').attr('aria-expanded', 'false');
  $('#s-nav-schatten').prop('hidden', true);
}

$(document).on('click', '.s-nb', function () { zeige($(this).data('ziel')); });
$(document).on('click', '#s-heim', function () { zeige('start'); });
$(document).on('click', '#s-menue', function () {
  var auf = !$('#s-root').hasClass('menue-auf');
  $('#s-root').toggleClass('menue-auf', auf);
  $(this).attr('aria-expanded', auf ? 'true' : 'false');
  $('#s-nav-schatten').prop('hidden', !auf);
});
$(document).on('click', '#s-nav-schatten', menueZu);

/* Escape schliesst das Menü — auf einem Telefon ohne Tastatur hilft das
   nicht, auf einem Rechner schon. */
$(document).on('keydown', function (e) {
  if (e.key === 'Escape') menueZu();
});

// ── Anmeldung ───────────────────────────────────────────────────────

$(document).on('submit', '#s-anmeldung', function (e) {
  e.preventDefault();
  var $f = $('#s-tor-fehler').prop('hidden', true).text('');
  var dsv = $.trim($('#s-dsv').val() || '');
  var geb = $.trim($('#s-geb').val() || '');
  var $codeFeld = $('#s-code');
  var code = $.trim($codeFeld.val() || '');

  if (!dsv || !geb) {
    $f.text('Bitte trag beides ein: DSV-ID und Geburtsdatum.').prop('hidden', false);
    return;
  }
  /* Ein leeres Codefeld gar nicht erst abschicken: Das kostet sonst einen
     Fehlversuch, und nach acht davon ist eine Viertelstunde Pause. */
  if ($codeFeld.length && !code) {
    $f.text('Bitte trag auch deinen Zugangscode ein. Den bekommst du von deinem Trainer.')
      .prop('hidden', false);
    return;
  }

  var $b = $('#s-anmelden').prop('disabled', true).text('Einen Moment…');
  ajax('lsv07s_login', { dsv: dsv, datum: geb, code: code })
    .done(function (r) {
      if (!r || !r.success) {
        $f.text((r && r.data && r.data.message) || 'Das hat nicht geklappt.').prop('hidden', false);
        return;
      }
      S.start = r.data;
      anmeldungFertig();
    })
    .fail(function (xhr) {
      $f.text(fehlerText(xhr)).prop('hidden', false);
    })
    .always(function () {
      $b.prop('disabled', false).text('Anmelden');
    });
});

function anmeldungFertig() {
  /* Die Eingaben nicht im Formular stehen lassen: Auf einem geteilten Gerät
     stünde der Zugangscode sonst noch im Feld, wenn jemand abmeldet und der
     Nächste zurückblättert. */
  $('#s-dsv, #s-geb, #s-code').val('');
  $('#s-root').removeClass('s-ist-draussen').addClass('s-ist-drin');
  $('#s-tor').prop('hidden', true);
  $('#s-portal').prop('hidden', true);
  $('#s-app').prop('hidden', false);
  S.geladen = {};
  navBauen();
  zeige('start');
}

function zurueckZurAnmeldung(hinweis) {
  S.start = null;
  S.geladen = {};
  $('#s-root').removeClass('s-ist-drin menue-auf').addClass('s-ist-draussen');
  $('#s-app').prop('hidden', true);
  $('#s-portal').prop('hidden', true);
  $('#s-tor').prop('hidden', false);
  if (hinweis) $('#s-tor-fehler').text(hinweis).prop('hidden', false);
  try { window.scrollTo(0, 0); } catch (e) {}
}

$(document).on('click', '#s-abmelden', function () {
  var $b = $(this).prop('disabled', true);
  ajax('lsv07s_logout').always(function () {
    $b.prop('disabled', false);
    zurueckZurAnmeldung('Du bist abgemeldet.');
  });
});

// ── Startseite ──────────────────────────────────────────────────────

function startBauen() {
  if (!S.start) return;

  var name = S.start.vorname || '';
  $('#s-gruss-titel').text(name ? ('Hallo ' + name + '!') : 'Hallo!');

  var teile = [];
  if (S.start.mannschaften && S.start.mannschaften.length) {
    teile.push(S.start.mannschaften.join(' · '));
  }
  if (S.start.jahrgang) teile.push('Jahrgang ' + S.start.jahrgang);
  $('#s-gruss-sub').text(teile.join(' · '));

  $('#s-wer').text([ S.start.vorname, S.start.nachname ].join(' ').trim());

  var $k = $('#s-start-kacheln').empty();
  var bereiche = S.start.bereiche || [];

  $('#s-start-leer').prop('hidden', bereiche.length > 0);

  $.each(bereiche, function (i, b) {
    var zahl = '';
    if (b.key === 'aufgaben' && S.start.aufgaben_offen > 0) {
      zahl = '<span class="s-kachel-zahl">' + S.start.aufgaben_offen + '</span>';
    }
    if (b.key === 'nachrichten' && S.start.ungelesen > 0) {
      zahl = '<span class="s-kachel-zahl">' + S.start.ungelesen + '</span>';
    }
    $k.append(
      '<button type="button" class="s-kachel" data-ziel="' + esc(b.key) + '">'
      + '<span class="s-kachel-ikon">' + ikon(b.icon) + '</span>'
      + '<span class="s-kachel-txt">'
      + '<span class="s-kachel-name">' + esc(b.name) + '</span>'
      + '<span class="s-kachel-sub">' + esc(b.text) + '</span>'
      + '</span>' + zahl + '</button>'
    );
  });
}

$(document).on('click', '.s-kachel', function () { zeige($(this).data('ziel')); });

/** Die Zahl an Menü und Kachel nachziehen, ohne alles neu zu bauen. */
function zahlSetzen(key, wert) {
  if (key === 'aufgaben')   S.start.aufgaben_offen = wert;
  if (key === 'nachrichten') S.start.ungelesen = wert;
  var $b = $('.s-nb[data-ziel="' + key + '"] .s-badge');
  if (wert > 0) {
    if ($b.length) $b.text(wert);
    else $('.s-nb[data-ziel="' + key + '"]').append('<span class="s-badge" data-zahl="' + key + '">' + wert + '</span>');
  } else {
    $b.remove();
  }
  if (S.seite === 'start') startBauen();
}

// ── PDF-Knöpfe ──────────────────────────────────────────────────────

$(document).on('click', '.s-pdf', function () {
  var bereich = $(this).data('bereich');
  var params = { bereich: bereich };
  if (bereich === 'anwesenheit')   { params.von = $('#s-anw-von').val(); params.bis = $('#s-anw-bis').val(); }
  if (bereich === 'vermerke')      { params.von = $('#s-ver-von').val(); params.bis = $('#s-ver-bis').val(); }
  if (bereich === 'aufgaben')      { params.von = $('#s-auf-von').val(); params.bis = $('#s-auf-bis').val(); }
  if (bereich === 'dokumentation') { params.von = $('#s-dok-von').val(); params.bis = $('#s-dok-bis').val(); }
  var plan = $(this).data('plan');
  if (plan) params.plan_id = plan;
  window.location.href = dlUrl('lsv07s_pdf', params);
});

$(document).on('click', '[data-neu]', function () {
  laden($(this).data('neu'), true);
});

function ladeHinweis($ziel) {
  $ziel.html('<div class="s-laden">Wird geladen…</div>');
}

function leer(text) {
  return '<div class="s-leer">' + esc(text) + '</div>';
}

// ── Anwesenheiten ───────────────────────────────────────────────────

function anwLaden() {
  var $l = $('#s-anw-liste');
  ladeHinweis($l);
  ajax('lsv07s_anwesenheit', { von: $('#s-anw-von').val(), bis: $('#s-anw-bis').val() })
    .done(function (r) {
      if (!r || !r.success) return;
      var d = r.data || {};
      if (d.von && !$('#s-anw-von').val()) $('#s-anw-von').val(d.von);
      if (d.bis && !$('#s-anw-bis').val()) $('#s-anw-bis').val(d.bis);

      anwZahlen(d);

      if (!d.tage || !d.tage.length) {
        $l.html(leer('In diesem Zeitraum ist noch nichts eingetragen.'));
        return;
      }

      var h = '<div class="s-tbl-wrap"><table class="s-tbl"><thead><tr>'
            + '<th>Datum</th><th>Mannschaft</th><th>Zustand</th><th>Zu spät</th>'
            + '</tr></thead><tbody>';
      $.each(d.tage, function (i, t) {
        var spaet = t.verspaetung > 0
          ? (t.verspaetung + ' Min.' + (t.grund ? ' – ' + esc(t.grund) : ''))
          : '<span class="s-muted">pünktlich</span>';
        h += '<tr>'
          + '<td data-label="Datum">' + de(t.datum) + '</td>'
          + '<td data-label="Mannschaft">' + esc(t.mannschaft) + '</td>'
          + '<td data-label="Zustand">' + anwChip(t.status, t.status_name) + '</td>'
          + '<td data-label="Zu spät">' + spaet + '</td>'
          + '</tr>';
      });
      h += '</tbody></table></div>';
      $l.html(h);
    });
}

function anwChip(status, name) {
  var art = 'grau';
  if (status === 'anwesend') art = 'gruen';
  else if (status === 'unentschuldigt' || status === 'abwesend') art = 'rot';
  else if (status === 'krank' || status === 'entschuldigt' || status === 'beurlaubt') art = 'gelb';
  return '<span class="s-chip s-chip-' + art + '">' + esc(name || status) + '</span>';
}

function anwZahlen(d) {
  var $z = $('#s-anw-zahlen').empty();
  var zahl = d.zahl || {};
  var gesamt = 0;
  $.each(zahl, function (k, v) { gesamt += parseInt(v, 10) || 0; });
  if (!gesamt) { $z.prop('hidden', true); return; }
  $z.prop('hidden', false);

  $z.append('<div class="s-zahl-kachel ist-gesamt">'
    + '<div class="s-zahl-lbl">Termine</div>'
    + '<div class="s-zahl-wert">' + gesamt + '</div></div>');

  $.each(LSV07S.zustaende || {}, function (k, name) {
    var v = parseInt(zahl[k], 10) || 0;
    if (!v) return;
    $z.append('<div class="s-zahl-kachel">'
      + '<div class="s-zahl-lbl">' + esc(name) + '</div>'
      + '<div class="s-zahl-wert">' + v + '</div></div>');
  });

  if (d.quote !== null && d.quote !== undefined) {
    $z.append('<div class="s-zahl-kachel">'
      + '<div class="s-zahl-lbl">Anwesend</div>'
      + '<div class="s-zahl-wert">' + d.quote + ' %</div></div>');
  }
}

// ── Vermerke ────────────────────────────────────────────────────────

function verLaden() {
  var $l = $('#s-ver-liste');
  ladeHinweis($l);
  ajax('lsv07s_vermerke', { von: $('#s-ver-von').val(), bis: $('#s-ver-bis').val() })
    .done(function (r) {
      if (!r || !r.success) return;
      var d = r.data || {};
      if (d.von && !$('#s-ver-von').val()) $('#s-ver-von').val(d.von);
      if (d.bis && !$('#s-ver-bis').val()) $('#s-ver-bis').val(d.bis);

      if (!d.eintraege || !d.eintraege.length) {
        $l.html(leer('Für diesen Zeitraum gibt es keine Vermerke.'));
        return;
      }
      var h = '<div class="s-eintraege">';
      $.each(d.eintraege, function (i, e) {
        h += '<div class="s-eintrag">'
          + '<div class="s-eintrag-kopf">'
          + '<span class="s-eintrag-datum">' + de(e.datum) + '</span>'
          + '<span class="s-chip s-chip-blau">' + esc(e.art) + '</span>'
          + (e.mannschaft ? '<span class="s-eintrag-meta">' + esc(e.mannschaft) + '</span>' : '')
          + '</div>'
          + '<div class="s-eintrag-text">' + esc(e.text) + '</div>'
          + '</div>';
      });
      h += '</div>';
      $l.html(h);
    });
}

// ── Aufgaben ────────────────────────────────────────────────────────

function aufLaden() {
  var $l = $('#s-auf-liste');
  ladeHinweis($l);
  ajax('lsv07s_aufgaben', { von: $('#s-auf-von').val(), bis: $('#s-auf-bis').val() })
    .done(function (r) {
      if (!r || !r.success) return;
      var d = r.data || {};
      if (d.von && !$('#s-auf-von').val()) $('#s-auf-von').val(d.von);
      if (d.bis && !$('#s-auf-bis').val()) $('#s-auf-bis').val(d.bis);

      zahlSetzen('aufgaben', parseInt(d.offen, 10) || 0);

      if (!d.eintraege || !d.eintraege.length) {
        $l.html(leer('Für diesen Zeitraum gibt es keine Aufgaben.'));
        return;
      }
      var h = '<div class="s-eintraege">';
      $.each(d.eintraege, function (i, e) {
        h += '<div class="s-eintrag' + (e.erledigt ? ' ist-erledigt' : '') + '">'
          + '<div class="s-eintrag-kopf">'
          + '<span class="s-eintrag-datum">' + de(e.datum) + '</span>'
          + '<span class="s-chip s-chip-' + (e.erledigt ? 'gruen' : 'gelb') + '">'
          + (e.erledigt ? 'erledigt' : 'offen') + '</span>'
          + '<span class="s-chip s-chip-grau">'
          + (e.fuer_alle ? 'ganze Mannschaft' : 'nur du') + '</span>'
          + '<span class="s-eintrag-meta">' + esc(e.art) + '</span>'
          + '</div>'
          + '<div class="s-eintrag-text">' + esc(e.text) + '</div>'
          + '</div>';
      });
      h += '</div>';
      $l.html(h);
    });
}

// ── Dokumentation ───────────────────────────────────────────────────

function dokLaden() {
  var $l = $('#s-dok-liste');
  ladeHinweis($l);
  ajax('lsv07s_doku', { von: $('#s-dok-von').val(), bis: $('#s-dok-bis').val() })
    .done(function (r) {
      if (!r || !r.success) return;
      var d = r.data || {};
      if (d.von && !$('#s-dok-von').val()) $('#s-dok-von').val(d.von);
      if (d.bis && !$('#s-dok-bis').val()) $('#s-dok-bis').val(d.bis);

      if (!d.tage || !d.tage.length) {
        $l.html(leer('Für diesen Zeitraum ist nichts dokumentiert.'));
        return;
      }
      var h = '<div class="s-eintraege">';
      $.each(d.tage, function (i, t) {
        h += '<div class="s-eintrag">'
          + '<div class="s-eintrag-kopf">'
          + '<span class="s-eintrag-datum">' + de(t.datum) + '</span>'
          + (t.mannschaft ? '<span class="s-eintrag-meta">' + esc(t.mannschaft) + '</span>' : '')
          + '</div>'
          + '<div class="s-eintrag-text">' + esc(t.text) + '</div>'
          + '</div>';
      });
      h += '</div>';
      $l.html(h);
    });
}

// ── Wettkämpfe ──────────────────────────────────────────────────────

function wkLaden() {
  var $l = $('#s-wk-liste');
  ladeHinweis($l);
  ajax('lsv07s_wettkaempfe').done(function (r) {
    if (!r || !r.success) return;
    var liste = (r.data && r.data.wettkaempfe) || [];
    if (!liste.length) {
      $l.html(leer('Zurzeit steht kein Wettkampf an.'));
      return;
    }
    var h = '';
    $.each(liste, function (i, w) {
      var klasse = w.laeuft ? ' ist-laeuft' : (w.vorbei ? ' ist-vorbei' : '');
      var wann = w.von === w.bis ? de(w.von) : (de(w.von) + ' bis ' + de(w.bis));
      var stand = w.laeuft ? 'läuft gerade' : (w.vorbei ? 'vorbei' : '');

      h += '<div class="s-wk' + klasse + '">'
        + '<div class="s-wk-kopf"><div>'
        + '<h2 class="s-wk-name">' + esc(w.name) + '</h2>'
        + '<div class="s-wk-wann">' + wann + (w.ort ? ' · ' + esc(w.ort) : '') + '</div>'
        + '</div>'
        + (stand ? '<span class="s-chip s-chip-' + (w.laeuft ? 'gruen' : 'grau') + '">'
                   + stand + '</span>' : '')
        + '</div>';

      var knoepfe = [];
      $.each(w.dokumente || [], function (j, d) {
        knoepfe.push('<a class="s-btn s-btn-b s-btn-klein" href="'
          + esc(dlUrl('lsv07s_wk_datei', { id: d.id })) + '">'
          + esc(d.name) + '</a>');
      });
      $.each(w.links || [], function (j, l) {
        knoepfe.push('<a class="s-btn s-btn-klein" href="' + esc(l.url)
          + '" target="_blank" rel="noopener noreferrer">' + esc(l.titel) + '</a>');
      });

      h += knoepfe.length
        ? '<div class="s-wk-akt">' + knoepfe.join('') + '</div>'
        : '<div class="s-wk-leer">Unterlagen gibt es noch nicht.</div>';
      h += '</div>';
    });
    $l.html(h);
  });
}

// ── Bestzeiten ──────────────────────────────────────────────────────

function bzLaden() {
  var $l = $('#s-bz-liste');
  ladeHinweis($l);
  ajax('lsv07s_bestzeiten').done(function (r) {
    if (!r || !r.success) return;
    var zeiten = (r.data && r.data.zeiten) || [];
    if (!zeiten.length) {
      $l.html(leer('Für dich sind noch keine Bestzeiten eingetragen.'));
      return;
    }
    var h = '<div class="s-tbl-wrap"><table class="s-tbl"><thead><tr>'
          + '<th>Strecke</th><th class="s-zahl">Zeit</th><th class="s-zahl">Stand</th>'
          + '</tr></thead><tbody>';
    $.each(zeiten, function (i, z) {
      h += '<tr>'
        + '<td data-label="Strecke">' + esc(z.name || z.strecke) + '</td>'
        + '<td data-label="Zeit" class="s-zahl">' + esc(z.zeit) + '</td>'
        + '<td data-label="Stand" class="s-zahl">' + de(z.stand) + '</td>'
        + '</tr>';
    });
    h += '</tbody></table></div>';
    $l.html(h);
  });
}

// ── Reflexionsbogen ─────────────────────────────────────────────────

function reflLaden() {
  var $l = $('#s-refl-inhalt');
  ladeHinweis($l);
  ajax('lsv07s_reflexion').done(function (r) {
    if (!r || !r.success) return;
    var d = r.data || {};

    if (!d.bogen) {
      $('#s-refl-sub').text('');
      $l.html(leer('Für dich gibt es in dieser Saison keinen Reflexionsbogen. '
        + 'Dein Trainer legt ihn an, wenn es so weit ist.'));
      return;
    }

    var zu = d.status === 'abgegeben';
    $('#s-refl-sub').text(zu
      ? ('Abgegeben am ' + de(d.abgegeben_am) + ' — du kannst ihn noch lesen.')
      : 'Nimm dir Zeit. Du kannst zwischendurch speichern und später weitermachen.');

    var h = '';
    if (zu) {
      h += '<div class="s-notiz s-notiz-gut">Dein Bogen ist abgegeben. '
        + 'Ändern kannst du ihn nicht mehr — dein Trainer hat ihn jetzt.</div>';
    } else if (d.bogen.saison) {
      h += '<div class="s-notiz">Reflexionsbogen für ' + esc(d.bogen.saison) + '.</div>';
    }

    $.each(d.fragen || [], function (i, f) {
      h += '<div class="s-frage">'
        + '<p class="s-frage-text">' + esc(f.frage) + '</p>'
        + '<textarea class="s-ctl s-refl-frage" data-id="' + f.id + '" rows="4"'
        + (zu ? ' readonly' : '') + ' maxlength="5000"'
        + ' placeholder="' + (zu ? '' : 'Deine Antwort') + '">'
        + esc(f.antwort) + '</textarea>'
        + '</div>';
    });

    if ((d.zeiten || []).length) {
      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Deine Zeiten und Ziele</h2></div>'
        + '<div class="s-karte-bd"><div class="s-tbl-wrap"><table class="s-tbl"><thead><tr>'
        + '<th>Strecke</th><th class="s-zahl">Bestzeit</th><th>Wunschzeit</th>'
        + '</tr></thead><tbody>';
      $.each(d.zeiten, function (i, z) {
        h += '<tr>'
          + '<td data-label="Strecke">' + esc(z.name || z.strecke) + '</td>'
          + '<td data-label="Bestzeit" class="s-zahl">' + esc(z.zeit) + '</td>'
          + '<td data-label="Wunschzeit">'
          + '<input type="text" class="s-ctl s-refl-wunsch" data-strecke="' + esc(z.strecke) + '"'
          + ' value="' + esc(z.wunschzeit) + '" maxlength="255"'
          + (zu ? ' readonly' : '') + ' placeholder="z. B. 0:31,5"></td>'
          + '</tr>';
      });
      h += '</tbody></table></div></div></div>';
    }

    if (!zu) {
      h += '<div class="s-refl-ft">'
        + '<span class="s-muted">Abgeben kannst du nur einmal.</span>'
        + '<button type="button" class="s-btn s-btn-b" id="s-refl-speichern">Zwischenspeichern</button>'
        + '<button type="button" class="s-btn s-btn-ok" id="s-refl-abgeben">Abgeben</button>'
        + '</div>';
    }

    $l.html(h);
  });
}

function reflSammeln() {
  var frage = {}, wunsch = {};
  $('.s-refl-frage').each(function () {
    frage[$(this).data('id')] = $(this).val() || '';
  });
  $('.s-refl-wunsch').each(function () {
    wunsch[$(this).data('strecke')] = $(this).val() || '';
  });
  return { frage: frage, wunsch: wunsch };
}

$(document).on('click', '#s-refl-speichern', function () {
  var $b = $(this).prop('disabled', true).text('Speichere…');
  var d = reflSammeln();
  ajax('lsv07s_refl_save', { frage: d.frage, wunsch: d.wunsch, abgeben: 0 })
    .done(function (r) {
      if (r && r.success) toast(r.data.message || 'Gespeichert.');
    })
    .always(function () { $b.prop('disabled', false).text('Zwischenspeichern'); });
});

$(document).on('click', '#s-refl-abgeben', function () {
  /* Abgeben ist endgültig — einmal nachfragen, bevor es weg ist. */
  if (!window.confirm('Abgeben? Danach kannst du nichts mehr ändern.')) return;
  var $b = $(this).prop('disabled', true).text('Gebe ab…');
  var d = reflSammeln();
  ajax('lsv07s_refl_save', { frage: d.frage, wunsch: d.wunsch, abgeben: 1 })
    .done(function (r) {
      if (r && r.success) {
        toast(r.data.message || 'Abgegeben.');
        reflLaden();
      }
    })
    .always(function () { $b.prop('disabled', false).text('Abgeben'); });
});

// ── Trainingsplan ───────────────────────────────────────────────────

function tpLaden() {
  var $l = $('#s-tp-liste');
  ladeHinweis($l);
  ajax('lsv07s_plaene').done(function (r) {
    if (!r || !r.success) return;
    var plaene = (r.data && r.data.plaene) || [];
    if (!plaene.length) {
      $l.html(leer('Für dich ist zurzeit kein Trainingsplan freigegeben.'));
      return;
    }
    var h = '';
    $.each(plaene, function (i, p) {
      h += '<div class="s-karte" data-plan="' + p.id + '">'
        + '<div class="s-karte-hd"><h2>' + esc(p.titel) + '</h2>'
        + '<span class="s-muted">' + (p.von ? esc(p.von) + ' · ' : '')
        + 'Stand ' + de(p.stand) + '</span></div>'
        + '<div class="s-karte-bd">'
        + '<div class="s-plan-kopf">'
        + '<button type="button" class="s-btn s-btn-b s-btn-klein s-tp-auf" data-plan="' + p.id + '">'
        + 'Plan ansehen</button>'
        + '<button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="trainingsplan"'
        + ' data-plan="' + p.id + '">Als PDF</button>'
        + '</div>'
        + '<div class="s-tp-inhalt" data-plan="' + p.id + '"></div>'
        + '</div></div>';
    });
    $l.html(h);
  });
}

$(document).on('click', '.s-tp-auf', function () {
  var id = $(this).data('plan');
  var $ziel = $('.s-tp-inhalt[data-plan="' + id + '"]');
  var $b = $(this);

  if ($ziel.data('offen')) {
    $ziel.empty().data('offen', false);
    $b.text('Plan ansehen');
    return;
  }

  ladeHinweis($ziel);
  $b.prop('disabled', true);
  ajax('lsv07s_plan', { plan_id: id })
    .done(function (r) {
      if (!r || !r.success) { $ziel.empty(); return; }
      var p = r.data.plan || {};
      var e = p.einheiten || [];
      if (!e.length) {
        $ziel.html(leer('In diesem Plan steht noch nichts.'));
      } else {
        var h = '<div class="s-tbl-wrap"><table class="s-tbl"><thead><tr>'
              + '<th>Anzahl</th><th>Strecke</th><th>Was</th><th>Ausrüstung</th>'
              + '</tr></thead><tbody>';
        $.each(e, function (j, u) {
          var was = esc(u.beschreibung);
          if (u.kommentar) was += '<br><span class="s-muted">' + esc(u.kommentar) + '</span>';
          h += '<tr>'
            + '<td data-label="Anzahl">' + esc(u.anzahl) + '</td>'
            + '<td data-label="Strecke">' + esc(u.strecke) + '</td>'
            + '<td data-label="Was">' + was + '</td>'
            + '<td data-label="Ausrüstung">' + esc(u.ausruestung) + '</td>'
            + '</tr>';
        });
        h += '</tbody></table></div>';
        $ziel.html(h);
      }
      $ziel.data('offen', true);
      $b.text('Zuklappen');
    })
    .always(function () { $b.prop('disabled', false); });
});

// ── Nachrichten ─────────────────────────────────────────────────────

function naLaden() {
  var $l = $('#s-na-liste');
  ladeHinweis($l);
  ajax('lsv07s_nachrichten').done(function (r) {
    if (!r || !r.success) return;
    var liste = (r.data && r.data.nachrichten) || [];
    zahlSetzen('nachrichten', parseInt(r.data.ungelesen, 10) || 0);

    if (!liste.length) {
      $l.html(leer('Es liegt keine Nachricht für dich vor.'));
      return;
    }
    var h = '<div class="s-eintraege">';
    $.each(liste, function (i, n) {
      h += '<div class="s-eintrag' + (n.gelesen ? '' : ' ist-neu') + '" data-nachricht="' + n.id + '">'
        + '<p class="s-eintrag-titel">' + esc(n.betreff) + '</p>'
        + '<div class="s-eintrag-kopf">'
        + '<span class="s-eintrag-meta">' + deZeit(n.am) + '</span>'
        + '<span class="s-eintrag-meta">von ' + esc(n.von || 'Trainerteam') + '</span>'
        + '<span class="s-chip s-chip-' + (n.nur_fuer_mich ? 'blau' : 'grau') + '">'
        + (n.nur_fuer_mich ? 'nur für dich' : 'ganze Mannschaft') + '</span>'
        + '</div>'
        + (n.text ? '<div class="s-eintrag-text">' + esc(n.text) + '</div>' : '');

      var akt = [];
      $.each(n.dateien || [], function (j, f) {
        akt.push('<a class="s-btn s-btn-b s-btn-klein" href="'
          + esc(dlUrl('lsv07s_anhang', { id: f.id })) + '">'
          + esc(f.name) + ' · ' + groesse(f.groesse) + '</a>');
      });
      if (!n.gelesen) {
        akt.push('<button type="button" class="s-btn s-btn-klein s-na-gelesen" data-id="'
          + n.id + '">Gelesen</button>');
      }
      if (akt.length) h += '<div class="s-eintrag-akt">' + akt.join('') + '</div>';
      h += '</div>';
    });
    h += '</div>';
    $l.html(h);
  });
}

$(document).on('click', '.s-na-gelesen', function () {
  var id = $(this).data('id');
  var $b = $(this).prop('disabled', true);
  ajax('lsv07s_gelesen', { id: id })
    .done(function (r) {
      if (!r || !r.success) return;
      $('.s-eintrag[data-nachricht="' + id + '"]').removeClass('ist-neu');
      $b.remove();
      zahlSetzen('nachrichten', parseInt(r.data.ungelesen, 10) || 0);
    })
    .always(function () { $b.prop('disabled', false); });
});

// ── Dialoge ─────────────────────────────────────────────────────────

/* Ein Dialog geht nur über das Kreuz, über Schließen oder mit Escape zu —
   nicht durch einen Klick auf die Fläche daneben. Wer einen Zugangscode
   markiert und die Maus dabei über den Rand zieht, lässt sie ausserhalb
   los; der Browser meldet den Klick dann auf der Fläche, und der Code wäre
   weg, bevor er notiert ist. */
function dlgAuf(id) { $('#' + id).addClass('auf'); }
function dlgZu(id)  { $('#' + id).removeClass('auf'); }

$(document).on('click', '[data-zu]', function () {
  var $ov = $(this).closest('.s-ov');
  if ($ov.length) dlgZu($ov.attr('id'));
});
$(document).on('keydown', function (e) {
  if (e.key !== 'Escape') return;
  var offen = $('.s-ov.auf');
  if (offen.length) dlgZu(offen.last().attr('id'));
});

/* ════════════════════════════════════════════════════════════════════
   TRAINERPORTAL
   ════════════════════════════════════════════════════════════════════ */

function portalAuf() {
  $('#s-tor').prop('hidden', true);
  $('#s-app').prop('hidden', true);
  $('#s-portal').prop('hidden', false);
  $('#s-root').removeClass('s-ist-draussen').addClass('s-ist-drin');
  try { window.scrollTo(0, 0); } catch (e) {}
  if (!S.portal.basis) portalBasis();
}

function portalZu() {
  $('#s-portal').prop('hidden', true);
  if (S.start) {
    $('#s-app').prop('hidden', false);
    $('#s-root').addClass('s-ist-drin').removeClass('s-ist-draussen');
  } else {
    $('#s-tor').prop('hidden', false);
    $('#s-root').removeClass('s-ist-drin').addClass('s-ist-draussen');
  }
  try { window.scrollTo(0, 0); } catch (e) {}
}

$(document).on('click', '#s-zum-portal', portalAuf);
$(document).on('click', '#s-portal-zurueck', portalZu);
$(document).on('click', '#s-portal-heim', function () {
  if (S.portal.mannschaft) portalMannschaft(S.portal.mannschaft);
});

function portalBasis() {
  ajaxT('lsv07s_t_basis').done(function (r) {
    if (!r || !r.success) return;
    S.portal.basis = r.data;

    if (r.data.hinweis) {
      $('#s-p-hinweis').text(r.data.hinweis).prop('hidden', false);
    }
    $('#s-p-datei-hilfe').text('Höchstens ' + (r.data.max_mb || 10)
      + ' MB je Datei. Mehrere auf einmal sind möglich.');

    var $m = $('#s-p-mann').empty();
    if (!r.data.mannschaften.length) {
      $m.append('<option value="0">Keine Mannschaft</option>');
      $('#s-pbody .s-karte').hide();
      $('#s-p-hinweis')
        .text('Dir ist noch keine Mannschaft zugeordnet. Das trägt die Verwaltung im internen Bereich ein.')
        .prop('hidden', false);
      return;
    }
    $.each(r.data.mannschaften, function (i, m) {
      $m.append('<option value="' + m.id + '">' + esc(m.name) + '</option>');
    });

    /* Die Sicherheitseinstellungen gelten für den ganzen Verein — darum
       sieht die Karte nur, wer sie auch ändern darf. */
    $('#s-p-sicher-karte').prop('hidden', !r.data.ist_leitung);
    if (r.data.ist_leitung) portalSicherheit(r.data.sicherheit);

    if (r.data.https === false) {
      $('#s-p-hinweis')
        .html('<strong>Diese Seite läuft gerade ohne HTTPS.</strong> Die Anmeldung '
            + 'der Kinder ist deshalb gesperrt — ihre Daten gingen sonst offen '
            + 'durchs Netz. Bitte beim Hoster ein Zertifikat einrichten lassen.')
        .removeClass('s-notiz-warn').addClass('s-notiz-fehler')
        .prop('hidden', false);
    }

    /* Die Pläne-Karte nur zeigen, wenn es überhaupt Pläne gibt — eine
       leere Karte mit Überschrift sagt nichts. */
    $('#s-p-plan-karte').toggle(!!(r.data.plaene || []).length);

    portalMannschaft(parseInt($m.val(), 10));
  });
}

$(document).on('change', '#s-p-mann', function () {
  portalMannschaft(parseInt($(this).val(), 10));
});

function portalMannschaft(mid) {
  if (!mid) return;
  S.portal.mannschaft = mid;
  $('#s-p-bereiche').html('<div class="s-laden">Wird geladen…</div>');
  $('#s-p-plaene').empty();
  $('#s-p-nachrichten').html('<div class="s-laden">Wird geladen…</div>');

  ajaxT('lsv07s_t_mannschaft', { mannschaft_id: mid }).done(function (r) {
    if (!r || !r.success) return;
    S.portal.stand = r.data;
    portalBereiche(r.data.freigaben);
    portalPlaene(r.data.plaene_frei);
    portalEmpfaenger(r.data.schwimmer);
    portalCodes(r.data.schwimmer, r.data.codes);
    portalNachrichten(r.data.nachrichten);
    $('#s-p-spur').empty();
  });
}

// ── Sicherheitseinstellungen ────────────────────────────────────────

function portalSicherheit(s) {
  if (!s) return;
  S.portal.sicherheit = s;
  $('#s-p-modus').val(s.modus);
  $('#s-p-tage').val(String(s.nachricht_tage));

  var $i = $('#s-p-sicher-info');
  $i.removeClass('s-notiz-warn s-notiz-fehler s-notiz-gut');
  if (s.modus === 'aus') {
    $i.addClass('s-notiz-fehler').html(
      '<strong>Ohne Zugangscode.</strong> Wer die DSV-ID und das Geburtsdatum '
      + 'eines Kindes kennt, kommt an alles, was freigegeben ist. Beides steht '
      + 'in Meldeergebnissen und ist im Verein weithin bekannt.');
  } else if (s.modus === 'optional') {
    $i.addClass('s-notiz-warn').html(
      '<strong>Teilweise geschützt.</strong> Kinder ohne hinterlegten Code '
      + 'kommen allein mit DSV-ID und Geburtsdatum hinein.');
  } else {
    $i.addClass('s-notiz-gut').html(
      '<strong>Mit Zugangscode.</strong> Ohne Code kommt niemand hinein.');
  }
  $i.append('<div style="margin-top:8px;font-size:13px">Sitzungen enden nach '
    + s.ruhe_stunden + ' Stunden ohne Nutzung, spätestens nach ' + s.max_tage
    + ' Tagen. Anmeldungen und Fehlversuche werden ' + s.spur_tage
    + ' Tage aufgehoben, danach gelöscht.</div>');
}

$(document).on('change', '#s-p-modus', function () {
  var m = $(this).val();
  if (m === 'aus' && !window.confirm(
      'Ohne Zugangscode reichen DSV-ID und Geburtsdatum — beides ist halb '
      + 'öffentlich. Wirklich abschalten?')) {
    $(this).val(S.portal.sicherheit ? S.portal.sicherheit.modus : 'pflicht');
    return;
  }
  var $s = $(this).prop('disabled', true);
  ajaxT('lsv07s_t_sicherheit', { modus: m })
    .done(function (r) {
      if (!r || !r.success) return;
      portalSicherheit(r.data);
      toast('Gespeichert.');
      /* Der Modus entscheidet, ob die Kinder ein Codefeld sehen — die
         Anmeldeseite wird serverseitig gebaut und muss neu geladen werden. */
      portalMannschaft(S.portal.mannschaft);
    })
    .always(function () { $s.prop('disabled', false); });
});

$(document).on('change', '#s-p-tage', function () {
  var $s = $(this).prop('disabled', true);
  ajaxT('lsv07s_t_sicherheit', { nachricht_tage: $(this).val() })
    .done(function (r) { if (r && r.success) { portalSicherheit(r.data); toast('Gespeichert.'); } })
    .always(function () { $s.prop('disabled', false); });
});

// ── Zugangscodes ────────────────────────────────────────────────────

function portalCodes(schwimmer, codes) {
  var $z = $('#s-p-codes').empty();
  codes = codes || {};
  if (!schwimmer || !schwimmer.length) {
    $z.html(leer('In dieser Mannschaft ist niemand eingetragen.'));
    $('#s-p-code-stand').text('');
    return;
  }

  var mit = 0;
  $.each(schwimmer, function (i, s) {
    var c = codes[String(s.id)] || codes[s.id];
    var hat = !!(c && c.hat);
    if (hat) mit++;
    var sub = hat
      ? ('Code seit ' + de(c.gesetzt_am)
         + (c.letzte_nutzung ? ' · zuletzt benutzt ' + deZeit(c.letzte_nutzung)
                             : ' · noch nie benutzt'))
      : 'kein Code hinterlegt';

    $z.append(
      '<div class="s-code-zeile' + (hat ? '' : ' ist-ohne') + '" data-id="' + s.id + '">'
      + '<span class="s-code-name"><strong>' + esc(s.name) + '</strong>'
      + '<span>' + esc(sub) + '</span></span>'
      + '<span class="s-code-akt">'
      + '<button type="button" class="s-btn s-btn-klein s-btn-b s-p-code" data-id="' + s.id + '"'
      + ' data-name="' + esc(s.name) + '">' + (hat ? 'Neuer Code' : 'Code erzeugen') + '</button>'
      + (hat ? '<button type="button" class="s-btn s-btn-klein s-p-code-weg" data-id="' + s.id
               + '">Entziehen</button>' : '')
      + '<button type="button" class="s-btn s-btn-klein s-p-code-ab" data-id="' + s.id
      + '" title="Beendet alle Sitzungen dieser Person — etwa nach einem Geräteverlust">'
      + 'Überall abmelden</button>'
      + '<button type="button" class="s-btn s-btn-klein s-p-vergessen" data-id="' + s.id
      + '" data-name="' + esc(s.name) + '"'
      + ' title="Löscht Code, Sitzungen, Lesemarken und Einzelnachrichten dieser Person">'
      + 'Daten löschen</button>'
      + '</span></div>'
    );
  });
  $('#s-p-code-stand').text(mit + ' von ' + schwimmer.length + ' haben einen Code');
}

$(document).on('click', '.s-p-code', function () {
  var $b = $(this);
  var name = $b.data('name');
  if ($b.text().indexOf('Neuer') === 0
      && !window.confirm('Ein neuer Code macht den alten ungültig und meldet '
        + name + ' überall ab. Fortfahren?')) return;

  $b.prop('disabled', true);
  ajaxT('lsv07s_t_code', { mannschaft_id: S.portal.mannschaft, schwimmer_id: $b.data('id') })
    .done(function (r) {
      if (!r || !r.success) return;
      $('#s-d-code-fuer').text('Für ' + name + ':');
      $('#s-d-code-wert').text(r.data.code);
      $('#s-d-code-hinweis').html('<strong>Jetzt notieren.</strong> ' + esc(r.data.hinweis));
      dlgAuf('s-d-code');
      portalCodes(S.portal.stand.schwimmer, r.data.codes);
    })
    .always(function () { $b.prop('disabled', false); });
});

$(document).on('click', '#s-d-code-kopie', function () {
  var text = $('#s-d-code-wert').text();
  var fertig = function (gut) {
    toast(gut ? 'Kopiert.' : 'Kopieren ging nicht — bitte von Hand abschreiben.',
      gut ? undefined : 'fehler');
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(function () { fertig(true); },
                                            function () { fertig(false); });
  } else {
    fertig(false);
  }
});

$(document).on('click', '.s-p-code-weg', function () {
  if (!window.confirm('Code entziehen? Solange ein Code Pflicht ist, kommt '
      + 'diese Person damit nicht mehr hinein.')) return;
  var $b = $(this).prop('disabled', true);
  ajaxT('lsv07s_t_code_weg', { mannschaft_id: S.portal.mannschaft, schwimmer_id: $b.data('id') })
    .done(function (r) {
      if (!r || !r.success) return;
      portalCodes(S.portal.stand.schwimmer, r.data.codes);
      toast(r.data.message);
    })
    .always(function () { $b.prop('disabled', false); });
});

$(document).on('click', '.s-p-code-ab', function () {
  var $b = $(this).prop('disabled', true);
  ajaxT('lsv07s_t_abmelden', { mannschaft_id: S.portal.mannschaft, schwimmer_id: $b.data('id') })
    .done(function (r) { if (r && r.success) toast(r.data.message); })
    .always(function () { $b.prop('disabled', false); });
});

$(document).on('click', '.s-p-vergessen', function () {
  var $b = $(this);
  if (!window.confirm('Alles löschen, was SwimView über ' + $b.data('name')
      + ' führt? Das sind Zugangscode, Sitzungen, Lesemarken und die '
      + 'Nachrichten, die nur an diese Person gingen. Die Daten im internen '
      + 'Bereich bleiben unberührt. Das lässt sich nicht rückgängig machen.')) return;
  $b.prop('disabled', true);
  ajaxT('lsv07s_t_vergessen', { mannschaft_id: S.portal.mannschaft, schwimmer_id: $b.data('id') })
    .done(function (r) {
      if (!r || !r.success) return;
      portalCodes(S.portal.stand.schwimmer, r.data.codes);
      portalNachrichten(r.data.nachrichten);
      $('#s-p-spur').empty();
      toast(r.data.message);
    })
    .always(function () { $b.prop('disabled', false); });
});

// ── Prüfspur ────────────────────────────────────────────────────────

$(document).on('click', '#s-p-spur-laden', function () {
  var $z = $('#s-p-spur').html('<div class="s-laden">Wird geladen…</div>');
  ajaxT('lsv07s_t_spur', { mannschaft_id: S.portal.mannschaft }).done(function (r) {
    if (!r || !r.success) return;
    var liste = r.data.eintraege || [];
    if (!liste.length) {
      $z.html(leer('Hier ist noch nichts passiert.'));
      return;
    }
    var h = '<div class="s-tbl-wrap"><table class="s-tbl"><thead><tr>'
          + '<th>Wann</th><th>Wer</th><th>Was</th></tr></thead><tbody>';
    $.each(liste, function (i, e) {
      h += '<tr>'
        + '<td data-label="Wann">' + deZeit(e.am) + '</td>'
        + '<td data-label="Wer">' + esc(e.name) + '</td>'
        + '<td data-label="Was">' + esc(e.was) + '</td>'
        + '</tr>';
    });
    h += '</tbody></table></div>';
    $z.html(h);
  });
});

function portalBereiche(freigaben) {
  var $z = $('#s-p-bereiche').empty();
  var an = 0;
  $.each((S.portal.basis && S.portal.basis.bereiche) || [], function (i, b) {
    var ein = !!freigaben[b.key];
    if (ein) an++;
    $z.append(
      '<label class="s-schalter-zeile' + (ein ? ' ist-an' : '') + '">'
      + '<input type="checkbox" class="s-p-frei" data-bereich="' + esc(b.key) + '"'
      + (ein ? ' checked' : '') + '>'
      + '<span class="s-schalter-txt">'
      + '<span class="s-schalter-name">' + esc(b.name) + '</span>'
      + '<span class="s-schalter-sub">' + esc(b.text) + '</span>'
      + '</span></label>'
    );
  });
  var gesamt = ((S.portal.basis && S.portal.basis.bereiche) || []).length;
  $('#s-p-stand').text(an + ' von ' + gesamt + ' freigegeben');
}

$(document).on('change', '.s-p-frei', function () {
  var $c = $(this);
  var bereich = $c.data('bereich');
  var aktiv = $c.is(':checked') ? 1 : 0;
  $c.closest('.s-schalter-zeile').toggleClass('ist-an', !!aktiv);
  $c.prop('disabled', true);

  ajaxT('lsv07s_t_freigabe', {
    mannschaft_id: S.portal.mannschaft, bereich: bereich, aktiv: aktiv
  })
    .done(function (r) {
      if (!r || !r.success) { $c.prop('checked', !aktiv); return; }
      S.portal.stand.freigaben = r.data.freigaben;
      portalBereiche(r.data.freigaben);
      toast(r.data.message);
    })
    .fail(function (xhr) {
      /* Zurückdrehen: Das Häkchen darf nicht gesetzt aussehen, wenn der
         Server es nicht übernommen hat. */
      $c.prop('checked', !aktiv);
      $c.closest('.s-schalter-zeile').toggleClass('ist-an', !aktiv);
      toast(fehlerText(xhr), 'fehler');
    })
    .always(function () { $c.prop('disabled', false); });
});

function portalPlaene(frei) {
  var $z = $('#s-p-plaene').empty();
  var plaene = (S.portal.basis && S.portal.basis.plaene) || [];
  if (!plaene.length) {
    $('#s-p-plan-karte').hide();
    return;
  }
  $('#s-p-plan-karte').show();
  var drin = {};
  $.each(frei || [], function (i, id) { drin[parseInt(id, 10)] = true; });

  $.each(plaene, function (i, p) {
    var ein = !!drin[parseInt(p.id, 10)];
    $z.append(
      '<label class="s-schalter-zeile' + (ein ? ' ist-an' : '') + '">'
      + '<input type="checkbox" class="s-p-plan" data-plan="' + p.id + '"'
      + (ein ? ' checked' : '') + '>'
      + '<span class="s-schalter-txt">'
      + '<span class="s-schalter-name">' + esc(p.titel) + '</span>'
      + '<span class="s-schalter-sub">' + (p.von ? esc(p.von) + ' · ' : '')
      + 'Stand ' + de(p.stand) + '</span>'
      + '</span></label>'
    );
  });
}

$(document).on('change', '.s-p-plan', function () {
  var $c = $(this);
  var plan = $c.data('plan');
  var aktiv = $c.is(':checked') ? 1 : 0;
  $c.closest('.s-schalter-zeile').toggleClass('ist-an', !!aktiv);
  $c.prop('disabled', true);

  ajaxT('lsv07s_t_plan', {
    mannschaft_id: S.portal.mannschaft, plan_id: plan, aktiv: aktiv
  })
    .done(function (r) {
      if (!r || !r.success) { $c.prop('checked', !aktiv); return; }
      toast(r.data.message);
    })
    .fail(function (xhr) {
      $c.prop('checked', !aktiv);
      $c.closest('.s-schalter-zeile').toggleClass('ist-an', !aktiv);
      toast(fehlerText(xhr), 'fehler');
    })
    .always(function () { $c.prop('disabled', false); });
});

function portalEmpfaenger(schwimmer) {
  var $a = $('#s-p-an').empty();
  $a.append('<option value="0">Die ganze Mannschaft</option>');
  $.each(schwimmer || [], function (i, s) {
    $a.append('<option value="' + s.id + '">' + esc(s.name)
      + (s.jahrgang ? ' (' + esc(s.jahrgang) + ')' : '') + '</option>');
  });
}

function portalNachrichten(liste) {
  var $z = $('#s-p-nachrichten');
  if (!liste || !liste.length) {
    $z.html(leer('Hier ist noch nichts verschickt worden.'));
    return;
  }
  var h = '<div class="s-eintraege">';
  $.each(liste, function (i, n) {
    h += '<div class="s-eintrag">'
      + '<p class="s-eintrag-titel">' + esc(n.betreff) + '</p>'
      + '<div class="s-eintrag-kopf">'
      + '<span class="s-eintrag-meta">' + deZeit(n.am) + '</span>'
      + '<span class="s-chip s-chip-' + (n.einzeln ? 'blau' : 'grau') + '">An '
      + esc(n.an) + '</span>'
      + '<span class="s-eintrag-meta">' + n.gelesen + '× gelesen</span>'
      + '</div>'
      + (n.text ? '<div class="s-eintrag-text">' + esc(n.text) + '</div>' : '');

    var akt = [];
    $.each(n.dateien || [], function (j, f) {
      akt.push('<span class="s-chip s-chip-grau">' + esc(f.name)
        + ' · ' + groesse(f.groesse) + '</span>');
      akt.push('<button type="button" class="s-btn s-btn-klein s-p-datei-weg" data-id="'
        + f.id + '">Datei entfernen</button>');
    });
    akt.push('<button type="button" class="s-btn s-btn-klein s-p-weg" data-id="'
      + n.id + '">Nachricht löschen</button>');
    h += '<div class="s-eintrag-akt">' + akt.join('') + '</div></div>';
  });
  h += '</div>';
  $z.html(h);
}

$(document).on('submit', '#s-p-form', function (e) {
  e.preventDefault();
  var betreff = $.trim($('#s-p-betreff').val() || '');
  if (!betreff) { toast('Bitte gib einen Betreff an.', 'fehler'); return; }

  var fd = new FormData();
  fd.append('mannschaft_id', S.portal.mannschaft);
  fd.append('schwimmer_id', $('#s-p-an').val() || 0);
  fd.append('betreff', betreff);
  fd.append('text', $('#s-p-text').val() || '');

  var dateien = ($('#s-p-datei')[0] || {}).files || [];
  var grenze = ((S.portal.basis && S.portal.basis.max_mb) || 10) * 1024 * 1024;
  for (var i = 0; i < dateien.length; i++) {
    if (dateien[i].size > grenze) {
      toast('„' + dateien[i].name + '" ist zu groß (höchstens '
        + ((S.portal.basis && S.portal.basis.max_mb) || 10) + ' MB).', 'fehler');
      return;
    }
    fd.append('datei[]', dateien[i]);
  }

  var $b = $('#s-p-senden').prop('disabled', true).text('Schicke ab…');
  ajaxT('lsv07s_t_senden', null, fd)
    .done(function (r) {
      if (!r || !r.success) return;
      $('#s-p-betreff').val('');
      $('#s-p-text').val('');
      $('#s-p-datei').val('');
      portalNachrichten(r.data.nachrichten);
      toast(r.data.message);
      $.each(r.data.warnungen || [], function (i, w) { toast(w, 'fehler'); });
    })
    .always(function () { $b.prop('disabled', false).text('Abschicken'); });
});

$(document).on('click', '.s-p-weg', function () {
  if (!window.confirm('Diese Nachricht wirklich löschen? Die Kinder sehen sie dann nicht mehr.')) return;
  var $b = $(this).prop('disabled', true);
  ajaxT('lsv07s_t_loeschen', { mannschaft_id: S.portal.mannschaft, id: $b.data('id') })
    .done(function (r) {
      if (!r || !r.success) return;
      portalNachrichten(r.data.nachrichten);
      toast(r.data.message);
    })
    .always(function () { $b.prop('disabled', false); });
});

$(document).on('click', '.s-p-datei-weg', function () {
  if (!window.confirm('Diese Datei wirklich entfernen?')) return;
  var $b = $(this).prop('disabled', true);
  ajaxT('lsv07s_t_datei_weg', { mannschaft_id: S.portal.mannschaft, id: $b.data('id') })
    .done(function (r) {
      if (!r || !r.success) return;
      portalNachrichten(r.data.nachrichten);
      toast(r.data.message);
    })
    .always(function () { $b.prop('disabled', false); });
});

// ── Start ───────────────────────────────────────────────────────────

$(function () {
  if (LSV07S.gesperrt) {
    $('#s-tor-fehler')
      .text('Es wurde zu oft etwas Falsches eingegeben. Bitte warte ein paar Minuten.')
      .prop('hidden', false);
  }

  if (S.start) {
    navBauen();
    /* Ein Anker in der Adresse führt direkt in den Bereich — praktisch,
       wenn jemand sich den Link zu seinen Bestzeiten merkt. */
    var ziel = (window.location.hash || '').replace('#', '');
    zeige(ziel && (ziel === 'start' || bereichFrei(ziel)) ? ziel : 'start');
  }

  /* Ein angemeldeter Trainer wird NICHT automatisch ins Portal geschoben,
     obwohl das naheläge. Die Seite ist in erster Linie die der Kinder, und
     auf einem geteilten Gerät — Tablet am Beckenrand, Rechner zu Hause —
     ist oft der Erwachsene bei WordPress angemeldet und das Kind will sich
     anmelden. Wer zuerst die Anmeldung sieht, kommt mit einem Klick ins
     Portal; wer zuerst im Portal landet, sucht erst einmal den Weg zurück. */
});

})(jQuery);
