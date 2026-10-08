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

  $('#e-untertitel').text(
    d.art_name + ' · Stundensatz ' + eur(d.stundensatz)
    + (d.abrechnungsart === 'pauschale' ? ' (gilt für Vorbereitung und Wartezeit)' : ''));

  // Hinweise: fehlender interner Bereich, und was die Automatik getan hat
  var hin = '';
  if (d.hinweis_intern) hin += '<div class="a-hinweis ist-warn">' + esc(d.hinweis_intern) + '</div>';
  if (d.auto_neu) {
    hin += '<div class="a-hinweis ist-gut">' + d.auto_neu + ' Training'
         + (d.auto_neu === 1 ? ' wurde' : 's wurden') + ' von selbst übernommen.'
         + ' Die Wartezeit haken Sie an der Zeile an.</div>';
  }
  /* Was die Automatik nicht nehmen konnte, wird gesagt — sonst fehlte es
     still in der Abrechnung und niemand wüsste warum. */
  if (d.auto_ohne_zeit) {
    hin += '<div class="a-hinweis ist-warn">' + d.auto_ohne_zeit + ' Training'
         + (d.auto_ohne_zeit === 1 ? ' hat' : 's haben') + ' keine hinterlegte Trainingszeit'
         + ' und wurde' + (d.auto_ohne_zeit === 1 ? '' : 'n') + ' deshalb nicht von selbst übernommen.'
         + ' Über <strong>Aus dem Training übernehmen</strong> lässt sich das von Hand nachholen.</div>';
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
    akt = nachtragKnopf(d);
  } else if (d.status === 'bezahlt') {
    $band.addClass('ist-gut');
    $('#e-band-titel').text('Bezahlt');
    $('#e-band-text').text('Am ' + deZeit(d.bezahlt_am) + ' überwiesen.');
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
  frage('Etwas nachtragen',
    '<p>Die bezahlte Abrechnung bleibt, wie sie ist — eine Buchung, die schon im '
    + 'Kontoauszug steht, wird nicht nachträglich verändert.</p>'
    + '<p>Stattdessen entsteht eine <strong>zweite Abrechnung für dasselbe Quartal</strong>. '
    + 'Sie beginnt leer, und Sie tragen nur ein, was gefehlt hat. Danach geht sie den '
    + 'gewohnten Weg: einreichen, prüfen, auszahlen.</p>',
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
         ? '<button class="a-btn a-btn-klein n-auf" data-id="' + p.id + '">Beanstandung aufheben</button>'
         : '<button class="a-btn a-btn-klein n-beanst" data-id="' + p.id + '">Beanstanden</button>')
      + '<button class="a-btn a-btn-klein n-frage" data-id="' + p.id + '">Rückfrage</button>'
      + '</div>';
  }
  /* Die eigene Abrechnung: antworten darf man immer, wenn jemand etwas
     geschrieben hat — auch wenn der Stand gerade gesperrt ist. Eine
     Antwort ändert ja nichts. */
  if (hat) {
    return '<div class="a-z-not">'
      + '<button class="a-btn a-btn-klein n-antwort" data-id="' + p.id + '">Antworten</button>'
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

function notDialog(titel, hilfe, knopf, dann) {
  frage(titel,
    '<div class="a-feld"><label for="n-text">' + hilfe + '</label>'
    + '<textarea id="n-text" class="a-ctl" rows="3"></textarea></div>',
    knopf, function () { dann($('#n-text').val() || ''); });
}

$(document).on('click', '.n-beanst', function () {
  var id = $(this).data('id');
  notDialog('Zeile beanstanden',
    'Was stimmt an dieser Zeile nicht? Die Person sieht den Text an der Zeile.',
    'Beanstanden', function (text) {
      ajax('lsv07a_notiz_beanstanden', { posten_id: id, text: text }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); notNeuZeichnen(r.data); }
      });
    });
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
    frage_ ? 'Was möchten Sie wissen? Am Stand der Abrechnung ändert das nichts.'
           : 'Ihre Antwort geht an den Wart.',
    'Abschicken', function (text) {
      ajax('lsv07a_notiz_anlegen', { posten_id: id, text: text }).done(function (r) {
        if (r && r.success) { toast(r.data.message, 'gut'); notNeuZeichnen(r.data); }
      });
    });
});

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
       + (ist ? ' <span class="a-chip a-chip-warn">beanstandet</span>' : '') + '</div>'
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
  var warn = 0;
  $.each(liste, function (i, x) { if (x.stufe === 'warnung') warn++; });
  var h = '<div class="a-hinweis' + (warn ? ' ist-warn' : '') + '">'
        + '<strong>' + liste.length + (liste.length === 1 ? ' Auffälligkeit' : ' Auffälligkeiten')
        + '</strong><ul class="a-hinw-liste">';
  $.each(liste, function (i, x) {
    h += '<li>' + esc(x.text) + '</li>';
  });
  return h + '</ul><div class="a-feld-hilfe">Das sind Hinweise, keine Fehler. '
       + 'Entschieden wird von Ihnen.</div></div>';
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
    /* Wer nur fuer bestimmte Mannschaften zustaendig ist, soll das auch
       sehen — sonst wundert er sich, wo die anderen geblieben sind. */
    var h = '';
    if (r.data.beschraenkt) {
      h += '<div class="a-hinweis">Sie pruefen <strong>' + esc(r.data.bereich) + '</strong>. '
         + 'Abrechnungen anderer Mannschaften erscheinen hier nicht.</div>';
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
         + (a.id ? '<button class="a-btn a-btn-klein p-detail" data-id="' + a.id + '">Ansehen</button>' : '')
         + (a.status === 'eingereicht'
            ? '<button class="a-btn a-btn-klein a-btn-ok p-ok" data-id="' + a.id + '">Genehmigen</button>'
            + '<button class="a-btn a-btn-klein a-btn-r p-zurueck" data-id="' + a.id + '">Zurückgeben</button>' : '')
         /* Die Administration kann jeden Schritt zurücknehmen — auch den,
            den sie selbst nicht gemacht hat. */
         + (a.id && Z.ist_admin_echt
            ? '<button class="a-btn a-btn-klein a-stand-setzen" data-id="' + a.id + '"'
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
       + (d.beanstandet.length === 1 ? ' Zeile ist beanstandet' : ' Zeilen sind beanstandet')
       + '.</strong> Solange das so ist, lässt sich die Abrechnung nicht genehmigen — '
       + 'heben Sie die Beanstandung auf oder geben Sie zurück.</div>';
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
  $('#d-detail-ft').html(fuss || '<button class="a-btn" data-zu>Schließen</button>');
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
         + (Z.ist_admin_echt
            ? '<button class="a-btn a-btn-klein a-stand-setzen" data-id="' + a.id + '"'
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
  return '<div style="margin-top:14px"><button class="a-btn" id="st-export">'
       + 'Als Tabelle herunterladen (CSV)</button>'
       + '<div class="a-feld-hilfe">Öffnet sich in Excel und LibreOffice — für den Jahresbericht.</div></div>';
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
  else if (v === 'saetze')     vSaetze();
  else if (v === 'pauschalen') vPauschalen();
  else if (v === 'saisons')    vSaisons();
  else if (v === 'zeiten')     vZeiten();
  else if (v === 'mail')       vMail();
  else if (v === 'bereiche')   vBereiche();
  else if (v === 'protokoll')  vProtokoll();
});

/* ════════════════════════════════════════════════════════════════════
 *  ZUSTÄNDIGKEIT — wer prüft welche Mannschaften
 * ════════════════════════════════════════════════════════════════════ */

var BER = { warte: [], mannschaften: [] };

function vBereiche() {
  $('#v-bereiche').html('<div class="a-laden">Wird geladen…</div>');
  ajax('lsv07a_adm_bereiche').done(function (r) {
    if (!r || !r.success) return;
    BER.warte = r.data.warte || [];
    BER.mannschaften = r.data.mannschaften || [];

    var h = '<div class="a-hinweis">In einem kleinen Verein prüft ein Wart alles. '
          + 'Wird es größer, lässt sich hier festlegen, wer welche Mannschaften prüft. '
          + '<strong>Kein Häkchen heißt: alle.</strong> Wer eingeschränkt ist, sieht die '
          + 'übrigen Abrechnungen gar nicht — weder in der Liste noch im Detail.</div>';
    if (r.data.hinweis_intern) {
      h += '<div class="a-hinweis ist-warn">' + esc(r.data.hinweis_intern) + '</div>';
    }
    if (!BER.mannschaften.length) {
      h += '<div class="a-leer">Es sind keine Mannschaften hinterlegt. '
         + 'Sie kommen aus dem internen Bereich.</div>';
      $('#v-bereiche').html(h);
      return;
    }
    if (!BER.warte.length) {
      h += '<div class="a-leer">Kein Konto hat die Rolle Wart.</div>';
      $('#v-bereiche').html(h);
      return;
    }

    $.each(BER.warte, function (i, w) {
      var alle = !w.bereiche.length;
      h += '<div class="a-karte a-schmal ber-karte" data-id="' + w.wp_user_id + '">'
         + '<div class="a-karte-hd"><h2>' + esc(w.name) + '</h2></div>'
         + '<div class="a-karte-bd">';
      if (w.ist_admin) {
        h += '<div class="a-hinweis">Dieses Konto ist Administrator und sieht ohnehin alles. '
           + 'Die Auswahl wirkt nur auf die Rolle Wart.</div>';
      }
      h += '<div class="a-schalter-zeile"><input type="checkbox" class="ber-alle" '
         + 'id="ber-alle-' + i + '"' + (alle ? ' checked' : '') + '>'
         + '<label for="ber-alle-' + i + '">Für alle Mannschaften zuständig</label></div>'
         + '<div class="ber-wahl" style="margin-top:12px"' + (alle ? ' hidden' : '') + '>';
      $.each(BER.mannschaften, function (j, m) {
        var an = w.bereiche.indexOf(m.id) !== -1;
        h += '<div class="a-schalter-zeile"><input type="checkbox" class="ber-m" '
           + 'data-m="' + m.id + '" id="ber-' + i + '-' + m.id + '"' + (an ? ' checked' : '') + '>'
           + '<label for="ber-' + i + '-' + m.id + '">' + esc(m.name) + '</label></div>';
      });
      h += '</div></div><div class="a-karte-ft">'
         + '<button class="a-btn a-btn-p ber-save">Speichern</button></div></div>';
    });
    $('#v-bereiche').html(h);
  });
}

/* „Für alle" und die einzelne Auswahl schließen einander aus — darum
   blendet das eine das andere aus, statt beides nebeneinander zu
   zeigen und den Widerspruch dem Nutzer zu überlassen. */
$(document).on('change', '.ber-alle', function () {
  $(this).closest('.ber-karte').find('.ber-wahl').prop('hidden', $(this).is(':checked'));
});

$(document).on('click', '.ber-save', function () {
  var $k = $(this).closest('.ber-karte');
  var alle = $k.find('.ber-alle').is(':checked');
  var ids = [];
  if (!alle) {
    $k.find('.ber-m:checked').each(function () { ids.push(parseInt($(this).data('m'), 10)); });
    if (!ids.length) {
      toast('Ohne Häkchen wäre niemand zuständig. Entweder „für alle" oder mindestens eine Mannschaft.', 'fehler');
      return;
    }
  }
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_adm_bereiche_speichern',
       { wp_user_id: $k.data('id'), mannschaften: JSON.stringify(ids) })
    .done(function (r) { if (r && r.success) { toast(r.data.message, 'gut'); vBereiche(); } })
    .always(function () { $b.prop('disabled', false).text('Speichern'); });
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
           + '<td data-label="Abrechnungsart">' + esc(p.art_name)
           + (p.auto_training ? ' <span class="a-chip a-chip-grau">automatisch</span>' : '') + '</td>'
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
     + '<strong>Manuelle Stundeneingabe:</strong> die Person trägt die Stunden selbst ein.</div>'
     + '<div class="a-feld"><label for="kf-mail">E-Mail für Mitteilungen <span class="a-opt">optional</span></label>'
     + '<input type="email" id="kf-mail" class="a-ctl" autocomplete="off" '
     + 'placeholder="' + esc(daten.email || 'Adresse des WordPress-Kontos') + '" '
     + 'value="' + esc(daten.mail || '') + '"></div>'
     + '<div class="a-feld-hilfe" style="margin-top:-8px">Leer lassen: Post geht an die Adresse des '
     + 'WordPress-Kontos' + (daten.mail_wirkt ? ' (' + esc(daten.mail_wirkt) + ')' : '') + '.</div>';
  if (daten.wp_user_id) {
    h += '<div class="a-feld" style="margin-top:12px"><label>Persönliche Pauschalen</label>'
       + '<button class="a-btn pp-oeffnen" data-uid="' + daten.wp_user_id + '">Pauschalen dieser Person…</button>'
       + '<div class="a-feld-hilfe">Sie stehen über den Pauschalen der Mannschaft.</div></div>';
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

function kontoSpeichern() {
  var uid = parseInt($('#kf-uid').val(), 10) || 0;
  if (!uid) { toast('Bitte zuerst ein WordPress-Konto auswählen.', 'fehler'); return; }
  var rollen = [];
  $('.kf-rolle:checked').each(function () { rollen.push($(this).val()); });
  var $b = $(this).prop('disabled', true).text('Speichert…');
  ajax('lsv07a_adm_konto_speichern', {
    wp_user_id: uid, rollen: JSON.stringify(rollen),
    mail: $('#kf-mail').val() || '',
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
    h += '<div class="a-hinweis">Diese Beträge gelten für Konten mit der Abrechnungsart '
       + '<strong>Pauschalbeträge</strong>: je Training der Mannschaft gibt es genau diesen Betrag. '
       + 'Gilt montags etwas anderes als dienstags, lässt sich das über '
       + '<strong>Wochentage</strong> hinterlegen — der allgemeine Betrag greift dann nur an '
       + 'den Tagen, für die nichts Eigenes eingetragen ist.</div>';
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
           + '<button class="a-btn a-btn-klein pa-tage" data-mid="' + m.mannschaft_id + '">Wochentage</button>'
           + '<button class="a-btn a-btn-klein pa-save" data-mid="' + m.mannschaft_id + '">Speichern</button>'
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
  var h = '<div class="a-hinweis">Leer lassen heisst: Es gilt der allgemeine Betrag'
        + (allg === null ? ' — der ist hier aber nicht hinterlegt.' : ' von ' + eur(allg) + '.')
        + ' Eine <strong>0</strong> heisst: An diesem Tag gibt es ausdrücklich nichts.</div>';
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
       + '<span class="a-nachr-titel">' + esc(n.titel) + '</span>'
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
  if (bereich && $('.a-nb[data-ziel="' + bereich + '"]').length) {
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
 *  ROLLENANSICHT
 * ════════════════════════════════════════════════════════════════════ */

function ansichtBand() {
  var a = (Z.ansicht || {});
  if (!a.aktiv) { $('#a-ansicht-band').prop('hidden', true); return; }
  $('#a-ansicht-rolle').text(a.name || a.rolle);
  $('#a-ansicht-band').prop('hidden', false);
}

function ansichtSetzen(rolle) {
  ajax('lsv07a_sys_ansicht_setzen', { rolle: rolle }).done(function (r) {
    if (!r || !r.success) return;
    toast(r.data.message, 'gut');
    /* Neu laden statt nachzeichnen: Es ändert sich, welche Bereiche es
       überhaupt gibt — da ist ein sauberer Neuaufbau ehrlicher als der
       Versuch, die halbe Oberfläche umzubauen. */
    setTimeout(function () { window.location.reload(); }, 600);
  });
}

$('#a-ansicht-ende').on('click', function () { ansichtSetzen(''); });
// Der Reiter zeigt beim Öffnen, was gerade gilt
$(document).on('click', '#v-reiter button[data-v="ansicht"]', function () {
  $('#va-rolle').val((Z.ansicht && Z.ansicht.rolle) || '');
});
$(document).on('click', '#va-start', function () { ansichtSetzen($('#va-rolle').val() || ''); });

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

    var h = '<div class="a-hinweis">Mitteilungen gibt es immer im System — die Glocke oben. '
          + 'Zusätzlich lassen sie sich per E-Mail verschicken. Verschickt wird über den '
          + 'Mailversand von WordPress; kommt nichts an, liegt es dort und nicht an der Abrechnung.</div>';

    h += '<div class="a-karte a-schmal"><div class="a-karte-hd"><h2>Versand</h2></div><div class="a-karte-bd">'
       + '<div class="a-schalter-zeile"><input type="checkbox" id="ml-an"' + (d.an ? ' checked' : '') + '>'
       + '<label for="ml-an">Mitteilungen auch per E-Mail verschicken</label></div>'
       + '<div class="a-zwei" style="margin-top:12px">'
       + '<div class="a-feld"><label for="ml-abs-name">Absendername <span class="a-opt">optional</span></label>'
       + '<input type="text" id="ml-abs-name" class="a-ctl" value="' + esc(d.absender_name) + '"></div>'
       + '<div class="a-feld"><label for="ml-abs">Absenderadresse <span class="a-opt">optional</span></label>'
       + '<input type="email" id="ml-abs" class="a-ctl" value="' + esc(d.absender) + '"></div></div>'
       + '<div class="a-feld-hilfe" style="margin-top:-8px;margin-bottom:12px">Leer lassen: WordPress '
       + 'entscheidet. Viele Mailserver nehmen nur Adressen der eigenen Domain an.</div>'
       + '<div class="a-feld"><label for="ml-link">Adresse der Abrechnungsseite</label>'
       + '<input type="url" id="ml-link" class="a-ctl" placeholder="https://…" value="' + esc(d.link) + '"></div>'
       + '<div class="a-feld-hilfe" style="margin-top:-8px;margin-bottom:12px">Steht in den Mails '
       + 'als <code>{link}</code>, damit man von dort direkt hinkommt.</div>'
       + '<div class="a-zwei">'
       + '<div class="a-feld"><label for="ml-wart">Zusätzlich an (Warte)</label>'
       + '<input type="text" id="ml-wart" class="a-ctl" placeholder="wart@verein.de" value="' + esc(d.wart_extra) + '"></div>'
       + '<div class="a-feld"><label for="ml-kasse">Zusätzlich an (Kasse)</label>'
       + '<input type="text" id="ml-kasse" class="a-ctl" placeholder="kasse@verein.de" value="' + esc(d.kasse_extra) + '"></div>'
       + '</div>'
       + '<div class="a-feld-hilfe" style="margin-top:-8px">Mehrere Adressen durch Komma trennen. '
       + 'Diese bekommen zusätzlich zu den Konten mit der Rolle Post.</div>'
       + '</div><div class="a-karte-ft">'
       + '<button class="a-btn" id="ml-probe">Probemail senden</button>'
       + '<button class="a-btn a-btn-p" id="ml-save">Speichern</button>'
       + '</div></div>';

    h += '<div class="a-karte a-schmal"><div class="a-karte-hd"><h2>Beleg zur bezahlten Abrechnung</h2></div>'
       + '<div class="a-karte-bd">'
       + '<div class="a-schalter-zeile"><input type="checkbox" id="ml-beleg"' + (d.beleg ? ' checked' : '') + '>'
       + '<label for="ml-beleg">Die Abrechnung in die Mail schreiben</label></div>'
       + '<div class="a-schalter-zeile" style="margin-top:8px">'
       + '<input type="checkbox" id="ml-beleg-pdf"' + (d.beleg_pdf ? ' checked' : '') + '>'
       + '<label for="ml-beleg-pdf">Die Abrechnung als PDF-Datei anhängen</label></div>'
       + '<div class="a-feld-hilfe" style="margin-top:12px">Betrifft nur die Mitteilung '
       + '<em>Abrechnung bezahlt</em>. Die Person bekommt dann ihre eigene Abrechnung mit allen '
       + 'Posten — an ihre eigene Adresse und an keine andere. Die Bankverbindung steht auf dem '
       + 'Beleg nur mit den letzten vier Stellen, weil eine Mail im Postfach liegen bleibt. '
       + 'Wie das aussieht, zeigt eine Probemail der Art <em>Abrechnung bezahlt</em>.</div>'
       + '</div><div class="a-karte-ft">'
       + '<button class="a-btn a-btn-p" id="ml-save3">Speichern</button>'
       + '</div></div>';

    h += '<h2 class="a-h2">Welche Mitteilungen per E-Mail</h2>'
       + '<div class="a-hinweis">Platzhalter im Text: '
       + $.map(d.platzhalter || {}, function (was, zeichen) {
           return '<code>' + esc(zeichen) + '</code> ' + esc(was);
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
    h += '<div style="margin-top:14px"><button class="a-btn a-btn-p" id="ml-save2">Speichern</button></div>';
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
    + '</select></div>'
    + '<div class="a-feld-hilfe">Die Probe geht auch, wenn der Versand noch aus ist.</div>',
    'Senden', function () {
      ajax('lsv07a_adm_mail_probe', { an: $('#pm-an').val() || '', art: $('#pm-art').val() || 'genehmigt' })
        .done(function (r) { if (r && r.success) toast(r.data.message, 'gut'); });
    });
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
  var h = '<div class="a-hinweis">Diese Beträge gelten <strong>statt</strong> der Pauschalen der '
        + 'Mannschaft. Je genauer ein Eintrag passt, desto eher gilt er: erst Mannschaft und '
        + 'Wochentag, dann die Mannschaft, dann der Wochentag, dann der allgemeine Betrag.</div>';

  if (!PP.eintraege.length) {
    h += '<div class="a-leer">Für diese Person ist nichts hinterlegt — es gelten die Pauschalen der Mannschaft.</div>';
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
     + '<button class="a-btn a-btn-p" id="pp-add">Hinzufügen</button>';
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
    '<div class="a-hinweis">Hier lässt sich jeder Schritt zurücknehmen. Ein Rückschritt räumt auch '
    + 'auf, was zu den späteren Ständen gehört — wer von <strong>bezahlt</strong> auf '
    + '<strong>genehmigt</strong> geht, bei dem verschwindet der Zahlungsvermerk. '
    + 'Die betroffene Person wird benachrichtigt.</div>'
    + '<div class="a-feld"><label for="st-ziel">Neuer Stand</label><select id="st-ziel" class="a-ctl">'
    + $.map(STAENDE, function (s) {
        return '<option value="' + s.wert + '"' + (s.wert === jetzt ? ' disabled' : '') + '>'
             + esc(s.name) + (s.wert === jetzt ? ' (aktuell)' : '') + '</option>';
      }).join('')
    + '</select></div>'
    + '<div class="a-feld"><label for="st-grund">Begründung <span class="a-opt">optional</span></label>'
    + '<textarea id="st-grund" class="a-ctl" rows="3" '
    + 'placeholder="Steht im Protokoll und bei einer Rückgabe auch in der Abrechnung."></textarea></div>');
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
      if ($('#s-pruefung').hasClass('on')) pruefListe();
      else if ($('#s-kasse').hasClass('on')) kasseListe();
    }
  }).always(function () { $b.prop('disabled', false).text('Stand setzen'); });
});

})(jQuery);
