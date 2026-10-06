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

var geladen = {};
function laden(ziel, erzwingen) {
  if (geladen[ziel] && !erzwingen) return;
  geladen[ziel] = true;
  if (ziel === 'eigene')        { abrLaden(); meineListe(); }
  else if (ziel === 'pruefung') { pruefListe(); }
  else if (ziel === 'kasse')    { kasseListe(); }
  else if (ziel === 'statistik'){ statLaden(); }
  else if (ziel === 'zahlungsdaten') { zdLaden(); }
  else if (ziel === 'verwaltung')    { vKonten(); }
}

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

function dlgAuf(id)  { $('#' + id).addClass('auf'); }
function dlgZu(id)   { $('#' + id).removeClass('auf'); }
$(document).on('click', '[data-zu]', function () { $(this).closest('.a-ov').removeClass('auf'); });
$(document).on('click', '.a-ov', function (e) { if (e.target === this) $(this).removeClass('auf'); });
$(document).on('keydown', function (e) { if (e.key === 'Escape') $('.a-ov.auf').removeClass('auf'); });

/** Rückfrage mit eigenem Dialog statt confirm() — das sieht auf dem Handy
    besser aus und lässt sich beschriften. */
function frage(titel, text, knopfText, dann) {
  $('#d-frage-titel').text(titel);
  $('#d-frage-bd').html(text);
  $('#d-frage-ok').text(knopfText || 'Ja').off('click').on('click', function () {
    dlgZu('d-frage'); dann();
  });
  dlgAuf('d-frage');
}

function statusChip(status, name) {
  var farbe = { entwurf: 'grau', eingereicht: 'gelb', zurueck: 'rot',
                genehmigt: 'gruen', bezahlt: 'blau', offen: 'grau' }[status] || 'grau';
  return '<span class="a-chip a-chip-' + farbe + '">' + esc(name || status) + '</span>';
}

// ════════════════════════════════════════════════════════════════════
//  MEINE ABRECHNUNG
// ════════════════════════════════════════════════════════════════════

var TYP_NAME = { training: 'Training', wettkampf: 'Wettkämpfe', fahrt: 'Fahrtkosten',
                 vorbereitung: 'Vorbereitung', sonstiges: 'Sonstiges' };
var TYP_HILFE = {
  training:     'Trainings, bei denen Sie im internen Bereich als anwesend eingetragen sind.',
  wettkampf:    'Je Abschnitt gibt es eine Pauschale.',
  fahrt:        'Erfasst wird die einfache Strecke.',
  vorbereitung: 'Stunden und Grund — gerechnet wird mit Ihrem Stundensatz.',
  sonstiges:    'Ein Betrag und wofür.'
};

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

function abrZeichnen() {
  var d = A.abr;
  if (!d) return;

  $('#e-untertitel').text(
    d.art_name + ' · Stundensatz ' + eur(d.stundensatz)
    + (d.abrechnungsart === 'pauschale' ? ' (gilt für Vorbereitung und Wartezeit)' : ''));

  // Hinweis, wenn der interne Bereich fehlt
  $('#e-hinweis').html(d.hinweis_intern
    ? '<div class="a-hinweis ist-warn">' + esc(d.hinweis_intern) + '</div>' : '');

  // ── Statusband
  var $band = $('#e-band').show().prop('hidden', false)
    .removeClass('ist-offen ist-wartet ist-gut ist-schlecht');
  var akt = '';
  if (d.status === 'entwurf') {
    $band.addClass('ist-offen');
    $('#e-band-titel').text('Entwurf');
    $('#e-band-text').text('Sie können Posten erfassen. Eingereicht wird unten.');
  } else if (d.status === 'zurueck') {
    $band.addClass('ist-schlecht');
    $('#e-band-titel').text('Zurückgegeben');
    $('#e-band-text').text(d.rueckgabe_grund || 'Bitte überarbeiten und erneut einreichen.');
  } else if (d.status === 'eingereicht') {
    $band.addClass('ist-wartet');
    $('#e-band-titel').text('Eingereicht — wartet auf Prüfung');
    $('#e-band-text').text('Seit ' + deZeit(d.eingereicht_am) + '. Solange niemand entschieden hat, können Sie sie zurückholen.');
    akt = '<button class="a-btn a-btn-klein" id="e-zurueckziehen">Zurückholen</button>';
  } else if (d.status === 'genehmigt') {
    $band.addClass('ist-gut');
    $('#e-band-titel').text('Genehmigt');
    $('#e-band-text').text('Am ' + deZeit(d.genehmigt_am) + '. Die Kasse zahlt sie aus.');
  } else if (d.status === 'bezahlt') {
    $band.addClass('ist-gut');
    $('#e-band-titel').text('Bezahlt');
    $('#e-band-text').text('Am ' + deZeit(d.bezahlt_am) + ' überwiesen.');
  }
  $('#e-band-akt').html(akt);

  // ── Summenkacheln
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
      h += '<button class="a-btn a-btn-klein e-neu" data-typ="' + t + '">+ Eintrag</button>';
    }
    h += '</div></div>';

    if (!liste.length) {
      h += '<div class="a-leer">' + esc(TYP_HILFE[t]) + '</div>';
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

function postenZeile(p, offen, art) {
  var detail = [];
  if (p.typ === 'training') {
    if (art === 'pauschale') {
      detail.push('Pauschale ' + eur(p.satz));
    } else {
      detail.push(zahl(p.menge) + ' Std × ' + eur(p.satz));
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

  var akt = '';
  if (offen) {
    akt = '<div class="a-z-akt">'
        + '<button class="a-ikon e-bearb" data-id="' + p.id + '" title="Bearbeiten" aria-label="Bearbeiten">'
        + '<svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>'
        + '<button class="a-ikon ist-weg e-weg" data-id="' + p.id + '" title="Entfernen" aria-label="Entfernen">'
        + '<svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg></button>'
        + '</div>';
  }

  return '<div class="a-zeile" data-id="' + p.id + '">'
       + '<div class="a-z-datum">' + esc(de(p.datum)) + '</div>'
       + '<div class="a-z-text"><div class="a-z-name">' + esc(p.bezeichnung) + '</div>'
       + (detail.length ? '<div class="a-z-detail">' + esc(detail.join(' · ')) + '</div>' : '')
       + '</div>'
       + '<div class="a-z-betrag">' + eur(p.betrag) + '</div>'
       + akt + '</div>';
}

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
    '<p>Danach lässt sich nichts mehr ändern, bis der Wart entschieden hat.</p>'
    + '<div class="a-feld" style="margin-top:12px"><label for="e-kommentar">Anmerkung für den Wart '
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
    if (art === 'pauschale') {
      h += '<div class="a-hinweis">Ihre Abrechnung läuft über <strong>Pauschalbeträge</strong>. '
         + 'Der Betrag richtet sich nach der Mannschaft; Stunden werden nicht gerechnet. '
         + 'Von Hand erfasste Trainings ohne Mannschaftsbezug ergeben 0,00 € — '
         + 'nutzen Sie dafür besser „Aus dem Training übernehmen".</div>';
    } else {
      h += '<div class="a-feld"><label for="f-menge">Stunden</label>'
         + '<input type="number" id="f-menge" class="a-ctl" step="0.25" min="0" inputmode="decimal" '
         + 'value="' + esc(d.menge !== undefined ? d.menge : '1.5') + '">'
         + '<div class="a-feld-hilfe">Gerechnet mit ' + eur(A.abr ? A.abr.stundensatz : 0) + ' je Stunde.</div></div>';
    }
    h += '<div class="a-schalter-zeile"><input type="checkbox" id="f-wartezeit"'
       + (d.wartezeit ? ' checked' : '') + '>'
       + '<label for="f-wartezeit">Wartezeit von ' + (cfg.wartezeit_min || 15) + ' Minuten hinzurechnen</label></div>';

  } else if (typ === 'wettkampf') {
    h += '<div class="a-feld"><label for="f-bez">Wettkampf</label>'
       + '<input type="text" id="f-bez" class="a-ctl" value="' + esc(d.bezeichnung || '') + '"></div>'
       + '<div class="a-feld"><label for="f-menge">Abschnitte</label>'
       + '<input type="number" id="f-menge" class="a-ctl" step="1" min="1" inputmode="numeric" '
       + 'value="' + esc(d.menge !== undefined ? parseInt(d.menge, 10) : 1) + '">'
       + '<div class="a-feld-hilfe">' + eur(cfg.wk_satz || 30) + ' je Abschnitt.</div></div>';

  } else if (typ === 'fahrt') {
    h += '<div class="a-feld"><label for="f-bez">Wohin ging die Fahrt?</label>'
       + '<input type="text" id="f-bez" class="a-ctl" value="' + esc(d.bezeichnung || '') + '"></div>'
       + '<div class="a-zwei">'
       + '<div class="a-feld"><label for="f-menge">Einfache Strecke (km)</label>'
       + '<input type="number" id="f-menge" class="a-ctl" step="0.1" min="0" inputmode="decimal" '
       + 'value="' + esc(d.menge !== undefined ? d.menge : '') + '"></div>'
       + '<div class="a-feld"><label for="f-tage">Tage</label>'
       + '<input type="number" id="f-tage" class="a-ctl" step="1" min="1" inputmode="numeric" '
       + 'value="' + esc(d.tage || 1) + '"></div></div>'
       + '<div class="a-hinweis">Abgerechnet wird ab einer einfachen Strecke von mehr als '
       + esc(cfg.km_mindest || 20) + ' km mit ' + eur(cfg.km_satz || 0.5) + ' je Kilometer'
       + (String(cfg.km_hin_rueck) === '1' ? ', Hin- und Rückfahrt' : ', einfache Strecke') + '.</div>';

  } else if (typ === 'vorbereitung') {
    h += '<div class="a-feld"><label for="f-bez">Wofür?</label>'
       + '<input type="text" id="f-bez" class="a-ctl" placeholder="z. B. Trainingsplanung" '
       + 'value="' + esc(d.bezeichnung || '') + '"></div>'
       + '<div class="a-feld"><label for="f-menge">Stunden</label>'
       + '<input type="number" id="f-menge" class="a-ctl" step="0.25" min="0" inputmode="decimal" '
       + 'value="' + esc(d.menge !== undefined ? d.menge : '1') + '">'
       + '<div class="a-feld-hilfe">Gerechnet mit ' + eur(A.abr ? A.abr.stundensatz : 0) + ' je Stunde.</div></div>';

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

function postenDialog(typ, p) {
  $('#d-posten-titel').text((p ? 'Posten bearbeiten' : 'Neuer Posten') + ' · ' + TYP_NAME[typ]);
  $('#d-posten-bd').html(postenFormular(typ, p));
  $('#d-posten-ok').off('click').on('click', function () {
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
  frage('Posten entfernen', '<p>Soll dieser Posten aus der Abrechnung entfernt werden?</p>',
    'Entfernen', function () {
      ajax('lsv07a_posten_loeschen', { abrechnung_id: A.abr.id, id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); abrLaden(); }
      });
    });
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
      h += '<div class="a-leer">Für dieses Quartal gibt es keine weiteren Trainings, '
         + 'bei denen Sie als anwesend eingetragen sind.</div>';
      $('#d-ueb-bd').html(h);
      $('#d-ueb-ok').hide();
      return;
    }
    h += '<div class="a-hinweis">Abgerechnet nach <strong>' + esc(r.data.art_name) + '</strong>'
       + (r.data.abrechnungsart === 'pauschale' ? '' : ' mit ' + eur(r.data.stundensatz) + ' je Stunde') + '.</div>'
       + '<div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">'
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
    h += '<div class="a-hinweis">' + eur(r.data.satz) + ' je Abschnitt. '
       + 'Wählen Sie den Tag, danach lässt sich die Zahl der Abschnitte anpassen.</div>';
    $.each(liste, function (i, w) {
      var vorschlag = w.abschnitte_eigen || w.abschnitte_plan || 1;
      h += '<label class="a-wahl wk-wahl" data-datum="' + esc(w.datum) + '" '
         + 'data-name="' + esc(w.name) + '" data-abschnitte="' + vorschlag + '">'
         + '<span class="a-wahl-txt"><span class="a-wahl-name">' + esc(de(w.datum)) + ' · ' + esc(w.name) + '</span>'
         + '<span class="a-wahl-sub">' + (w.ort ? esc(w.ort) + ' · ' : '')
         + vorschlag + ' Abschnitt' + (vorschlag === 1 ? '' : 'e')
         + (w.war_dabei ? ' (aus Ihrer Wettkampf-Anwesenheit)' : ' (geplant)') + '</span></span>'
         + '<span class="a-chip a-chip-blau">' + eur(vorschlag * r.data.satz) + '</span></label>';
    });
    $('#d-ueb-bd').html(h);
  });
});

$(document).on('click', '.wk-wahl', function () {
  var d = $(this);
  dlgZu('d-uebernehmen');
  postenDialog('wettkampf', {
    datum: d.data('datum'), bezeichnung: d.data('name'),
    menge: parseInt(d.data('abschnitte'), 10) || 1
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
    $('#a-badge-pruef').text(r.data.offen).prop('hidden', r.data.offen === 0);
    if (!z.length) {
      $('#p-liste').html('<div class="a-leer">Für diese Auswahl gibt es nichts.</div>');
      return;
    }
    var h = '<div class="a-tbl-wrap"><table class="a-tbl"><thead><tr>'
          + '<th>Trainer</th><th>Status</th><th>Posten</th><th class="a-zahl">Gesamt</th>'
          + '<th>Eingereicht</th><th></th></tr></thead><tbody>';
    $.each(z, function (i, a) {
      h += '<tr><td data-label="Trainer">' + esc(a.name) + '</td>'
         + '<td data-label="Status">' + statusChip(a.status, a.status_name) + '</td>'
         + '<td data-label="Posten">' + a.posten + '</td>'
         + '<td data-label="Gesamt" class="a-zahl">' + (a.id ? eur(a.gesamt) : '–') + '</td>'
         + '<td data-label="Eingereicht">' + esc(a.eingereicht_am ? deZeit(a.eingereicht_am) : '–') + '</td>'
         + '<td class="a-td-akt">'
         + (a.id ? '<button class="a-btn a-btn-klein p-detail" data-id="' + a.id + '">Ansehen</button>' : '')
         + (a.status === 'eingereicht'
            ? '<button class="a-btn a-btn-klein a-btn-ok p-ok" data-id="' + a.id + '">Genehmigen</button>'
            + '<button class="a-btn a-btn-klein a-btn-r p-zurueck" data-id="' + a.id + '">Zurückgeben</button>' : '')
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
     + ' · ' + esc(d.art_name) + ' · Stundensatz ' + eur(d.stundensatz) + '</div>';
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
  $('#d-detail-ft').html(fuss || '<button class="a-btn" data-zu>Schließen</button>');
  dlgAuf('d-detail');
}

$(document).on('click', '.p-detail', function () {
  var id = $(this).data('id');
  ajax('lsv07a_pruef_detail', { abrechnung_id: id }).done(function (r) {
    if (!r || !r.success) return;
    var f = '<button class="a-btn" data-zu>Schließen</button>';
    if (r.data.status === 'eingereicht') {
      f = '<button class="a-btn a-btn-r p-zurueck" data-id="' + id + '">Zurückgeben</button>'
        + '<button class="a-btn a-btn-ok p-ok" data-id="' + id + '">Genehmigen</button>';
    }
    detailAnsicht(r.data, f);
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
    + '<textarea id="p-grund" class="a-ctl" rows="3" '
    + 'placeholder="Diese Begründung sieht die Trainerin oder der Trainer."></textarea></div>',
    'Zurückgeben', function () {
      var grund = $('#p-grund').val() || '';
      ajax('lsv07a_pruef_zurueckgeben', { abrechnung_id: id, grund: grund }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-detail'); pruefListe(); }
      });
    });
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
      $('#k-liste').html('<div class="a-leer">Hier ist nichts — genehmigte Abrechnungen erscheinen '
        + 'automatisch, sobald der Wart sie freigegeben hat.</div>');
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
         + '<button class="a-btn a-btn-klein k-detail" data-id="' + a.id + '">Ansehen</button>'
         + '<button class="a-btn a-btn-klein k-pdf" data-id="' + a.id + '">PDF</button>'
         + (a.status === 'genehmigt'
            ? '<button class="a-btn a-btn-klein a-btn-ok k-bezahlt" data-id="' + a.id + '">Bezahlt</button>'
            : '<button class="a-btn a-btn-klein k-storno" data-id="' + a.id + '">Zurücknehmen</button>')
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
    var f = '<button class="a-btn k-pdf" data-id="' + id + '">Als PDF</button>'
          + (r.data.status === 'genehmigt'
             ? '<button class="a-btn a-btn-ok k-bezahlt" data-id="' + id + '">Als bezahlt markieren</button>'
             : '<button class="a-btn" data-zu>Schließen</button>');
    detailAnsicht(r.data, f);
  });
});

$(document).on('click', '.k-bezahlt', function () {
  var id = $(this).data('id');
  frage('Als bezahlt markieren', '<p>Damit gilt die Abrechnung als überwiesen.</p>',
    'Als bezahlt markieren', function () {
      ajax('lsv07a_kasse_bezahlt', { abrechnung_id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); dlgZu('d-detail'); kasseListe(); }
      });
    });
});

$(document).on('click', '.k-storno', function () {
  var id = $(this).data('id');
  frage('Zahlungsvermerk entfernen', '<p>Die Abrechnung steht danach wieder auf „genehmigt".</p>',
    'Entfernen', function () {
      ajax('lsv07a_kasse_storno', { abrechnung_id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); kasseListe(); }
      });
    });
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
    $('#st-sub').text('Ihre Zahlen des Jahres.');
    ajax('lsv07a_stat_eigene', { jahr: jahr }).done(function (r) {
      if (r && r.success) statEigene(r.data);
    });
  } else {
    $('#st-sub').text('Alle Trainerinnen und Trainer.');
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

function statEigene(d) {
  var h = '<div class="a-kacheln">'
    + '<div class="a-kachel ist-gesamt"><div class="a-kachel-lbl">Jahr ' + d.jahr + '</div>'
    + '<div class="a-kachel-wert">' + eur(d.gesamt) + '</div></div>'
    + '<div class="a-kachel"><div class="a-kachel-lbl">Stunden</div>'
    + '<div class="a-kachel-wert">' + zahl(d.stunden, 1) + '</div>'
    + '<div class="a-kachel-sub">Training und Vorbereitung</div></div></div>';

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
  h += '<h2 class="a-h2">Wofür</h2>' + balken(d.nach_typ, d.typ_namen);
  $('#st-inhalt').html(h);
}

function statAlle(d) {
  var h = '<div class="a-kacheln">'
    + '<div class="a-kachel ist-gesamt"><div class="a-kachel-lbl">Jahr ' + d.jahr + '</div>'
    + '<div class="a-kachel-wert">' + eur(d.gesamt) + '</div></div>'
    + '<div class="a-kachel"><div class="a-kachel-lbl">Personen</div>'
    + '<div class="a-kachel-wert">' + d.personen.length + '</div></div>'
    + '<div class="a-kachel"><div class="a-kachel-lbl">Noch nicht gezahlt</div>'
    + '<div class="a-kachel-wert">' + eur((d.nach_status.genehmigt || 0)) + '</div></div></div>';

  if (!d.personen.length) {
    $('#st-inhalt').html(h + '<div class="a-leer">Für dieses Jahr gibt es noch keine Abrechnungen.</div>');
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
  h += '<h2 class="a-h2">Wofür</h2>' + balken(d.nach_typ, d.typ_namen);
  h += '<h2 class="a-h2">Nach Stand</h2>' + balken(d.nach_status, d.status_namen);
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
    $('#z-konditionen').html('Ihre Abrechnung läuft über <strong>' + esc(d.art_name)
      + '</strong> mit einem Stundensatz von <strong>' + eur(d.stundensatz)
      + '</strong>. Beides legt die Administration fest.');
  });
}

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
  else if (v === 'saetze')     vSaetze();
  else if (v === 'pauschalen') vPauschalen();
  else if (v === 'saisons')    vSaisons();
  else if (v === 'zeiten')     vZeiten();
  else if (v === 'protokoll')  vProtokoll();
});

var ROLLEN_NAME = { trainer: 'Trainer', wart: 'Wart', kasse: 'Kasse', admin: 'Administrator' };

function vKonten() {
  $('#v-konten').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_konten').done(function (r) {
    if (!r || !r.success) return;
    var k = r.data.konten || [];
    var h = '';
    if (r.data.hinweis_intern) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis_intern) + '</div>';
    h += '<div class="a-karte"><div class="a-karte-hd"><h2>Konten</h2>'
       + '<button class="a-btn a-btn-klein a-btn-p" id="v-konto-neu">+ Konto aufnehmen</button></div>';
    if (!k.length) {
      h += '<div class="a-karte-bd"><div class="a-leer">Noch kein Konto aufgenommen. '
         + 'Nehmen Sie zuerst sich selbst und die Trainerinnen und Trainer auf.</div></div>';
    } else {
      h += '<div class="a-tbl-wrap" style="border:0;border-radius:0"><table class="a-tbl"><thead><tr>'
         + '<th>Name</th><th>Rollen</th><th class="a-zahl">Stundensatz</th><th>Abrechnungsart</th>'
         + '<th>Trainer-Profil</th><th></th></tr></thead><tbody>';
      $.each(k, function (i, p) {
        var rollen = $.map(p.rollen, function (x) {
          return '<span class="a-chip a-chip-' + (x === 'admin' ? 'rot' : x === 'wart' ? 'gelb'
                 : x === 'kasse' ? 'gruen' : 'blau') + '">' + esc(ROLLEN_NAME[x] || x) + '</span>';
        }).join(' ');
        h += '<tr><td data-label="Name">' + esc(p.name)
           + (p.existiert ? '' : ' <span class="a-chip a-chip-rot">Konto gelöscht</span>') + '</td>'
           + '<td data-label="Rollen">' + rollen + '</td>'
           + '<td data-label="Stundensatz" class="a-zahl">' + eur(p.stundensatz) + '</td>'
           + '<td data-label="Abrechnungsart">' + esc(p.art_name) + '</td>'
           + '<td data-label="Trainer-Profil">' + (p.trainer_id
               ? '<span class="a-chip a-chip-gruen">verknüpft</span>'
               : '<span class="a-chip a-chip-gelb">keines</span>') + '</td>'
           + '<td class="a-td-akt"><button class="a-btn a-btn-klein v-konto-bearb" '
           + 'data-uid="' + p.wp_user_id + '">Bearbeiten</button></td></tr>';
      });
      h += '</tbody></table></div>';
    }
    h += '</div>';
    h += '<div class="a-hinweis">Ohne verknüpftes Trainer-Profil im internen Bereich lassen sich '
       + 'keine Trainings übernehmen — von Hand erfasste Posten gehen trotzdem.</div>';
    $('#v-konten').html(h);
    A.konten = k;
  });
}

function kontoDialog(p) {
  var daten = p || { wp_user_id: 0, rollen: [], stundensatz: 0, abrechnungsart: 'zeiten', aktiv: 1 };
  var h = '';
  if (!p) {
    h += '<div class="a-feld"><label for="kf-suche">WordPress-Konto suchen</label>'
       + '<input type="text" id="kf-suche" class="a-ctl" placeholder="Name oder E-Mail">'
       + '<div class="a-feld-hilfe">Die Konten kommen aus WordPress; die Rolle vergeben Sie hier.</div></div>'
       + '<div id="kf-treffer"></div>'
       + '<input type="hidden" id="kf-uid" value="0">';
  } else {
    h += '<input type="hidden" id="kf-uid" value="' + p.wp_user_id + '">'
       + '<div class="a-hinweis">' + esc(p.name) + (p.email ? ' · ' + esc(p.email) : '') + '</div>';
  }
  h += '<div class="a-feld"><label>Rollen</label>';
  $.each(['trainer', 'wart', 'kasse', 'admin'], function (i, rolle) {
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
     + '<div class="a-feld-hilfe" style="margin-top:-8px;margin-bottom:12px">'
     + '<strong>Trainingszeiten:</strong> Stunden aus der hinterlegten Trainingszeit × Stundensatz. '
     + '<strong>Pauschalbeträge:</strong> fester Betrag je Mannschaft. '
     + '<strong>Manuelle Stundeneingabe:</strong> die Person trägt die Stunden selbst ein.</div>';
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

function kontoSpeichern() {
  var uid = parseInt($('#kf-uid').val(), 10) || 0;
  if (!uid) { toast('Bitte zuerst ein WordPress-Konto auswählen.', 'fehler'); return; }
  var rollen = [];
  $('.kf-rolle:checked').each(function () { rollen.push($(this).val()); });
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_adm_konto_speichern', {
    wp_user_id: uid, rollen: JSON.stringify(rollen),
    stundensatz: $('#kf-satz').val(), abrechnungsart: $('#kf-art').val(), aktiv: 1
  }).done(function (r) {
    if (r && r.success) { dlgZu('d-posten'); toast(r.data.message, 'gut'); vKonten(); }
    else $b.prop('disabled', false).text('Speichern');
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
      + '<div class="a-feld-hilfe">Zuschaltbar je Training; gerechnet mit dem Stundensatz der Person.</div></div>'
      + '<div class="a-feld"><label for="c-verein">Verein (Kopfzeile auf dem Beleg)</label>'
      + '<input type="text" id="c-verein" class="a-ctl" value="' + esc(c.verein || '') + '"></div>'
      + '</div><div class="a-karte-ft"><button class="a-btn a-btn-p" id="c-speichern">Speichern</button></div></div>'
      + '<div class="a-hinweis">Änderungen wirken sofort auf alle Abrechnungen, die noch nicht '
      + 'eingereicht sind. Eingereichtes und Genehmigtes bleibt, wie es geprüft wurde.</div>');
  });
}

$(document).on('click', '#c-speichern', function () {
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_adm_config_speichern', {
    wk_satz: $('#c-wk').val(), km_satz: $('#c-km').val(), km_mindest: $('#c-kmmin').val(),
    km_hin_rueck: $('#c-hinrueck').is(':checked') ? 1 : 0,
    wartezeit_min: $('#c-wartezeit').val(), verein: $('#c-verein').val()
  }).done(function (r) {
    if (r && r.success) { toast(r.data.message, 'gut'); LSV07A.config = r.data.config; geladen.eigene = false; }
  }).always(function () { $b.prop('disabled', false).text('Speichern'); });
});

function vPauschalen() {
  $('#v-pauschalen').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_pauschalen').done(function (r) {
    if (!r || !r.success) return;
    var p = r.data.pauschalen || [];
    var h = '';
    if (r.data.hinweis_intern) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis_intern) + '</div>';
    h += '<div class="a-hinweis">Diese Beträge gelten für Konten mit der Abrechnungsart '
       + '<strong>Pauschalbeträge</strong>: je Training der Mannschaft gibt es genau diesen Betrag.</div>';
    if (!p.length) {
      h += '<div class="a-leer">Es sind keine Mannschaften vorhanden.</div>';
    } else {
      h += '<div class="a-karte"><div class="a-tbl-wrap" style="border:0"><table class="a-tbl"><thead><tr>'
         + '<th>Mannschaft</th><th class="a-zahl">Betrag je Training</th><th></th></tr></thead><tbody>';
      $.each(p, function (i, m) {
        h += '<tr><td data-label="Mannschaft">' + esc(m.name) + '</td>'
           + '<td data-label="Betrag" class="a-zahl"><input type="number" class="a-ctl pa-wert" '
           + 'style="width:110px;text-align:right" step="0.5" min="0" data-mid="' + m.mannschaft_id + '" '
           + 'value="' + esc(m.betrag) + '"></td>'
           + '<td class="a-td-akt"><button class="a-btn a-btn-klein pa-save" data-mid="' + m.mannschaft_id
           + '">Speichern</button></td></tr>';
      });
      h += '</tbody></table></div></div>';
    }
    $('#v-pauschalen').html(h);
  });
}

$(document).on('click', '.pa-save', function () {
  var mid = $(this).data('mid');
  var wert = $('.pa-wert[data-mid="' + mid + '"]').val();
  var $b = $(this).prop('disabled', true).text('…');
  ajax('lsv07a_adm_pauschale_speichern', { mannschaft_id: mid, betrag: wert }).done(function (r) {
    if (r && r.success) toast(r.data.message, 'gut');
  }).always(function () { $b.prop('disabled', false).text('Speichern'); });
});

function vSaisons() {
  $('#v-saisons').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_saisons').done(function (r) {
    if (!r || !r.success) return;
    var s = r.data.saisons || [];
    var h = '';
    if (r.data.hinweis) h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis) + '</div>';
    h += '<div class="a-hinweis">Saisons und Trainingszeiten teilen sich Abrechnung und interner '
       + 'Bereich — es gibt sie nur einmal. <strong>Das Enddatum schützt die Historie:</strong> '
       + 'Neue Trainingszeiten gehören in eine neue Saison, sonst ändern sich alte Abrechnungen rückwirkend.</div>'
       + '<div class="a-karte"><div class="a-karte-hd"><h2>Saisons</h2>'
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
           + '<button class="a-btn a-btn-klein sa-bearb" data-s=\'' + esc(JSON.stringify(x)) + '\'>Bearbeiten</button>'
           + (parseInt(x.aktiv, 10) === 1 ? ''
              : '<button class="a-btn a-btn-klein sa-aktiv" data-id="' + x.id + '">Aktivieren</button>')
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
    + '<div class="a-hinweis">Solange kein Ende gesetzt ist, gelten die Trainingszeiten dieser Saison '
    + 'unbegrenzt weiter. Für neue Zeiten: diese Saison beenden und eine neue anlegen.</div>');
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
  frage('Saison löschen', '<p>Das geht nur, solange keine Trainingszeiten daran hängen.</p>',
    'Löschen', function () {
      ajax('lsv07a_adm_saison_loeschen', { id: id }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); vSaisons(); }
      });
    });
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
    h += '<div class="a-hinweis">Aus diesen Zeiten kommen die Stunden für die Abrechnungsart '
       + '<strong>Trainingszeiten</strong>. Sie gehören zugleich dem internen Bereich.</div>';
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
           + '<button class="a-btn a-btn-klein zt-bearb" data-s=\'' + esc(JSON.stringify(x)) + '\'>Bearbeiten</button>'
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
     + (s ? '<div class="a-hinweis ist-warn">Diese Zeit gilt rückwirkend für alle Trainings '
          + 'der gewählten Saison. Sollen ab jetzt andere Zeiten gelten, legen Sie besser eine '
          + 'neue Saison an — sonst ändern sich noch offene Abrechnungen vergangener Monate.</div>' : '');

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
          function () { weg(true); });
      }
    }).fail(function (x) {
      var r = null;
      try { r = JSON.parse(jsonRetten(x.responseText) || x.responseText); } catch (e) {}
      if (r && r.data && r.data.code === 'hat_anwesenheit') {
        frage('Trotzdem löschen?', '<p>' + esc(r.data.message) + '</p>', 'Trotzdem löschen',
          function () { weg(true); });
      } else { toast(fehlerText(x), 'fehler'); }
    });
  };
  frage('Trainingszeit löschen', '<p>Soll diese Trainingszeit entfernt werden?</p>', 'Löschen',
    function () { weg(false); });
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
  if (!start || !$('#s-' + start).length) {
    start = $('.a-nb').first().data('ziel');
  }
  if (start) zeige(start);

  // Der Wart sieht gleich, wie viel offen ist — auch ohne den Bereich zu öffnen
  if (Z.tabs.pruefung && start !== 'pruefung') {
    ajax('lsv07a_pruef_liste', { quartal: LSV07A.quartal, jahr: LSV07A.jahr })
      .done(function (r) {
        if (r && r.success) $('#a-badge-pruef').text(r.data.offen).prop('hidden', r.data.offen === 0);
      });
  }
});

})(jQuery);
