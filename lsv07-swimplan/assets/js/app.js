/* global LSV07P, jQuery */
(function ($) {
'use strict';

/* ════════════════════════════════════════════════════════════════════
   LSV07 SwimPlan

   Gerechnet und geprüft wird auf dem SERVER — hier wird nur dargestellt
   und erfasst. Jede Zahl, die auf dem Schirm steht, kommt aus einer
   Antwort; nichts davon wird im Browser nachgerechnet. Das ist nicht
   Bequemlichkeit: Würde die Oberfläche die Meter selbst summieren, stünde
   im PDF irgendwann eine andere Zahl als auf dem Schirm.

   Ebenso bei den Rechten: Das Menü wird aus dem gebaut, was der Server als
   Rolle meldet, und kein Endpunkt verlässt sich darauf. Eine ausgeblendete
   Schaltfläche ist keine Berechtigung.
   ════════════════════════════════════════════════════════════════════ */

if (!window.LSV07P) return;

var S = {
  seite:   'start',
  basis:   null,          // Mannschaften, Rolle, Konten
  plan:    null,          // der gerade offene Plan
  vorlage: null,          // die gerade offene Vorlage
  wer:     0,             // Schwimmer in der Leistungsansicht
  buehne:  { offen: false, plaene: [], layout: null, liste: null }
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

function zahl(n) {
  var v = parseInt(n, 10) || 0;
  return v.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

var _toastZeit;
function toast(text, art) {
  var $t = $('#p-toast');
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
  $('#p-ladebalken').removeClass('fertig').addClass('laeuft');
}
function ladenAus() {
  $('#p-ladebalken').removeClass('laeuft').addClass('fertig');
  _ladeZeit = setTimeout(function () { $('#p-ladebalken').removeClass('fertig'); }, 320);
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
    console.warn('LSV07P: Vor der Serverantwort stand fremde Ausgabe — ' + text.slice(0, i));
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
 * auch die fehlende Berechtigung, die der Server mit 403 beantwortet. Wer
 * selbst .fail() anhängt, bekommt die Standardmeldung nicht.
 */
function ajax(aktion, daten) {
  ladenAn();
  var xhr = $.ajax({
    url: LSV07P.ajax_url,
    type: 'POST',
    data: $.extend({ action: aktion, nonce: LSV07P.nonce }, daten || {}),
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

/** Die Adresse einer angehängten Datei. */
function dateiUrl(id) {
  return LSV07P.ajax_url + '?action=lsv07p_datei&datei_id=' + encodeURIComponent(id)
       + '&_wpnonce=' + encodeURIComponent(LSV07P.nonce);
}

/** Die Adresse eines PDF-Berichts. Der Nonce steht mit in der Adresse. */
function pdfUrl(was, zusatz) {
  var p = $.extend({ action: 'lsv07p_pdf', was: was, _wpnonce: LSV07P.nonce }, zusatz || {});
  var teile = [];
  Object.keys(p).forEach(function (k) {
    if (p[k] === undefined || p[k] === null || p[k] === '') return;
    teile.push(encodeURIComponent(k) + '=' + encodeURIComponent(p[k]));
  });
  return LSV07P.ajax_url + '?' + teile.join('&');
}

function heute() {
  var d = new Date();
  return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2)
       + '-' + ('0' + d.getDate()).slice(-2);
}

function plusTage(ymd, n) {
  var t = new Date(ymd + 'T12:00:00');
  t.setDate(t.getDate() + n);
  return t.getFullYear() + '-' + ('0' + (t.getMonth() + 1)).slice(-2)
       + '-' + ('0' + t.getDate()).slice(-2);
}

/** Sekunden als 1:00 — dieselbe Schreibweise wie auf dem Server. */
function mmss(sek) {
  var s = Math.max(0, parseInt(sek, 10) || 0);
  return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
}

/** „1:00" oder „75" → Sekunden. Beides darf eingegeben werden. */
function sekLesen(roh) {
  var s = String(roh === undefined || roh === null ? '' : roh).trim();
  if (s === '') return 0;
  if (s.indexOf(':') < 0) return Math.max(0, parseInt(s, 10) || 0);
  var t = s.split(':');
  return Math.max(0, (parseInt(t[0], 10) || 0) * 60 + (parseInt(t[1], 10) || 0));
}

// ── Dialog ──────────────────────────────────────────────────────────

var _dlgZu = null;

/**
 * Ein Dialog geht nur zu, wenn jemand das ausdrücklich will: über das
 * Kreuz, über Abbrechen oder mit Escape. Ein Klick auf die Fläche daneben
 * schliesst ihn NICHT — wer in einem Formular die Maus über den Rand
 * zieht, lässt sie ausserhalb los, und die Eingaben wären weg.
 */
function dialog(titel, inhalt, knoepfe, beimOeffnen) {
  $('#p-dlg-titel').text(titel);
  $('#p-dlg-bd').html(inhalt);

  var $ft = $('#p-dlg-ft').empty();
  (knoepfe || []).forEach(function (k) {
    var $b = $('<button type="button">').addClass('p-btn ' + (k.klasse || '')).text(k.text);
    $b.on('click', function () {
      if (k.tu && k.tu($('#p-dlg-bd')) === false) return;
      if (!k.offen) dialogZu();
    });
    $ft.append($b);
  });
  if (!knoepfe || !knoepfe.length) {
    $ft.append($('<button type="button" class="p-btn">').text('Schließen')
      .on('click', dialogZu));
  }

  $('#p-ov').addClass('auf');
  _dlgZu = null;
  if (beimOeffnen) beimOeffnen($('#p-dlg-bd'));
  var $erst = $('#p-dlg-bd').find('input,select,textarea').filter(':visible').first();
  if ($erst.length) $erst.trigger('focus');
}

function dialogZu() {
  $('#p-ov').removeClass('auf');
  $('#p-dlg-bd').empty();
  if (_dlgZu) { var f = _dlgZu; _dlgZu = null; f(); }
}

function frage(titel, text, knopf, tu) {
  dialog(titel, '<p>' + esc(text) + '</p>', [
    { text: 'Abbrechen', klasse: 'p-btn-nein' },
    { text: knopf, klasse: 'p-btn-r', tu: function () { tu(); } }
  ]);
}

// ── Menü und Seiten ─────────────────────────────────────────────────

/* Welche Bereiche es gibt, hängt an der Rolle. Gebaut wird das Menü aus
   dieser Liste; was der Server nicht erlaubt, steht nicht darin — und
   wäre es doch darin, käme aus dem Endpunkt trotzdem nichts. */
function bereiche() {
  var b = [];
  if (LSV07P.darf_planen) {
    b.push({ id: 'start',   name: 'Start' });
    b.push({ id: 'plaene',  name: 'Pläne' });
    b.push({ id: 'bibliothek', name: 'Bibliothek' });
    b.push({ id: 'leistung', name: 'Leistungen' });
    b.push({ id: 'teams',   name: 'Mannschaften' });
    if (LSV07P.ist_admin) b.push({ id: 'konten', name: 'Konten' });
  } else {
    b.push({ id: 'start', name: 'Start' });
    b.push({ id: 'mein',  name: 'Mein Training' });
    b.push({ id: 'meine-zeiten', name: 'Meine Zeiten' });
  }
  return b;
}

function menueBauen() {
  var $n = $('#p-nav').empty();
  bereiche().forEach(function (b) {
    $('<button type="button" class="p-nb">')
      .attr('data-seite', b.id).text(b.name)
      .appendTo($n);
  });
  $('#p-wer').text(LSV07P.name + ' · ' + (LSV07P.rolle_name || ''));
}

function zeige(id) {
  if (!bereiche().some(function (b) { return b.id === id; })
      && id !== 'plan') {
    id = 'start';
  }
  S.seite = id;
  $('.p-seite').removeClass('on');
  $('#p-s-' + id).addClass('on');
  $('#p-nav .p-nb').removeClass('on')
    .filter('[data-seite="' + id + '"]').addClass('on');
  $('#p-nav').removeClass('auf');
  $('#p-nav-schatten').prop('hidden', true);
  $('#p-menue').attr('aria-expanded', 'false');
  if (window.scrollY > 0) window.scrollTo(0, 0);

  if (id === 'plaene')     plaeneLaden();
  if (id === 'bibliothek') vorlagenLaden();
  if (id === 'leistung')   leistungAufbauen();
  if (id === 'teams')      teamsLaden();
  if (id === 'konten')     kontenLaden();
  if (id === 'mein')       meinLaden();
  if (id === 'meine-zeiten') meineZeitenLaden();
}

// ── Startseite ──────────────────────────────────────────────────────

function startLaden() {
  $('#p-gruss').text('Hallo ' + (String(LSV07P.name || '').split(' ')[0] || ''));

  if (LSV07P.darf_planen) {
    $('#p-gruss-sub').text('Trainingsplanung für deine Mannschaften.');
    ajax('lsv07p_v_basis').done(function (r) {
      if (!r || !r.success) return;
      S.basis = r.data;
      startKachelnTrainer(r.data);
    });
  } else {
    $('#p-gruss-sub').text('Hier steht, was ansteht — und was du geschwommen bist.');
    ajax('lsv07p_s_basis').done(function (r) {
      if (!r || !r.success) return;
      startKachelnSchwimmer(r.data);
    });
  }
}

/**
 * Eine Kachel: links die Zahl als Marke, rechts Name und Erklärung
 * UNTEREINANDER. Stünden sie nebeneinander, bräche die Erklärung mitten im
 * Satz um („die du / betreust") — das sieht man nur im fertigen Bild.
 * Wo es nichts zu zählen gibt, bleibt die Marke weg; eine Marke mit einem
 * Strich darin sieht aus wie ein Knopf.
 */
function kachel(name, zahl, sub, tu) {
  var $k = $('<button type="button" class="p-kachel">');
  if (zahl !== null && zahl !== undefined && zahl !== '') {
    $k.append($('<span class="p-kachel-zahl">').text(zahl));
  }
  var $t = $('<span class="p-kachel-txt">').appendTo($k);
  $t.append($('<span class="p-kachel-name">').text(name));
  if (sub) $t.append($('<span class="p-kachel-sub">').text(sub));
  if (tu) $k.on('click', tu); else $k.prop('disabled', true);
  return $k;
}

function startKachelnTrainer(d) {
  var $k = $('#p-start-kacheln').empty();
  var teams = d.teams || [];
  var kinder = 0;
  teams.forEach(function (t) { kinder += parseInt(t.mitglieder, 10) || 0; });

  $k.append(kachel('Mannschaften', teams.length, 'die du betreust',
    function () { zeige('teams'); }));
  $k.append(kachel('Schwimmer', kinder, 'in deinen Mannschaften',
    function () { zeige('leistung'); }));
  $k.append(kachel('Pläne', '', 'anlegen und freigeben',
    function () { zeige('plaene'); }));
  $k.append(kachel('Bühne', '', 'Vollbild und Splitscreen', function () { buehneAuf(); }));

  /* Die nächsten Einheiten gleich mit — das ist die Frage, mit der ein
     Trainer die Seite öffnet. */
  var von = plusTage(heute(), -1), bis = plusTage(heute(), 21);
  ajax('lsv07p_p_liste', { von: von, bis: bis }).done(function (r) {
    if (!r || !r.success) return;
    var liste = r.data.plaene || [];
    var $h = $('#p-start-inhalt').empty();
    $h.append($('<h2 class="p-h2">').text('Die nächsten drei Wochen'));
    if (!liste.length) {
      $h.append($('<div class="p-leer">').text(
        'Für die nächsten drei Wochen ist nichts geplant.'));
      return;
    }
    var $e = $('<div class="p-eintraege">').appendTo($h);
    liste.slice(0, 8).forEach(function (p) { $e.append(planZeile(p, true)); });
  });
}

function startKachelnSchwimmer(d) {
  var $k = $('#p-start-kacheln').empty();
  var kommend = d.kommend || [];
  var best = d.bestzeiten || [];

  $k.append(kachel('Training', kommend.length, 'steht an',
    function () { zeige('mein'); }));
  $k.append(kachel('Bestzeiten', best.length, 'auf verschiedenen Strecken',
    function () { zeige('meine-zeiten'); }));
  $k.append(kachel('Mannschaft', (d.teams || []).length,
    (d.teams || []).map(function (t) { return t.name; }).join(', '), null));
  $k.append(kachel('Bühne', '', 'Plan im Vollbild', function () { buehneAuf(); }));

  var $h = $('#p-start-inhalt').empty();
  $h.append($('<h2 class="p-h2">').text('Als Nächstes'));
  if (!kommend.length) {
    $h.append($('<div class="p-leer">').text(
      'Es ist noch kein Training für dich freigegeben.'));
  } else {
    var $e = $('<div class="p-eintraege">').appendTo($h);
    kommend.slice(0, 5).forEach(function (p) { $e.append(meinZeile(p)); });
  }

  if ((d.teams || []).length) {
    var namen = [];
    (d.teams || []).forEach(function (t) {
      (t.trainer || []).forEach(function (n) {
        if (namen.indexOf(n) < 0) namen.push(n);
      });
    });
    if (namen.length) {
      $h.append($('<p class="p-muted">').text(
        'Deine Trainerinnen und Trainer: ' + namen.join(', ')));
    }
  }
}

// ── Pläne ───────────────────────────────────────────────────────────

function statusChip(p) {
  var art = { draft: 'grau', published: 'blau', completed: 'gruen', cancelled: 'rot' };
  return $('<span class="p-chip">').addClass('p-chip-' + (art[p.status] || 'grau'))
    .text(p.status_name);
}

function planZeile(p, knapp) {
  var $e = $('<div class="p-eintrag">');
  var $k = $('<div class="p-eintrag-kopf">').appendTo($e);

  var $l = $('<div>').appendTo($k);
  $l.append($('<h3 class="p-eintrag-titel">').text(p.titel || 'Trainingseinheit'));
  $l.append($('<div class="p-eintrag-datum">').text(
    p.datum_text + (p.startzeit ? (', ' + p.startzeit + ' Uhr') : '')));

  var $r = $('<div>').appendTo($k);
  $r.append(statusChip(p));
  if (parseInt(p.version, 10) > 1) {
    $r.append($('<span class="p-chip p-chip-grau">').text('Fassung ' + p.version));
  }

  var meta = [];
  meta.push(p.meter_text + ' · ' + p.dauer_text);
  meta.push(p.sets + (p.sets === 1 ? ' Set' : ' Sets'));
  if (p.dateien > 0) meta.push(p.dateien === 1 ? 'PDF angehängt' : (p.dateien + ' PDF'));
  meta.push(p.bahnlaenge + '-m-Bahn');
  if ((p.frei_an || []).length) meta.push('für ' + p.frei_an.join(', '));
  else if (p.status === 'published') meta.push('für niemanden freigegeben');
  if (!LSV07P.darf_planen || p.trainer_user !== LSV07P.user_id) {
    meta.push(p.trainer);
  }
  $e.append($('<div class="p-eintrag-meta">').text(meta.join(' · ')));

  var $a = $('<div class="p-eintrag-akt">').appendTo($e);
  $('<button type="button" class="p-btn p-btn-b p-btn-klein">').text('Öffnen')
    .on('click', function () { planOeffnen(p.id); }).appendTo($a);
  $('<button type="button" class="p-btn p-btn-klein">').text('Bühne')
    .on('click', function () { buehneAuf([p.id]); }).appendTo($a);
  $('<a class="p-btn p-btn-klein" target="_blank" rel="noopener">').text('Als PDF')
    .attr('href', pdfUrl('plan', { plan_id: p.id })).appendTo($a);
  if (!knapp) {
    $('<button type="button" class="p-btn p-btn-klein">').text('Kopieren')
      .on('click', function () { planKopieren(p); }).appendTo($a);
  }
  return $e;
}

function plaeneLaden() {
  if (!$('#p-f-status option').length) {
    var $s = $('#p-f-status');
    $s.append($('<option value="">').text('alle Zustände'));
    Object.keys(LSV07P.status || {}).forEach(function (k) {
      $s.append($('<option>').attr('value', k).text(LSV07P.status[k]));
    });
  }
  if (!$('#p-f-von').val()) {
    $('#p-f-von').val(plusTage(heute(), -14));
    $('#p-f-bis').val(plusTage(heute(), 42));
  }

  ajax('lsv07p_p_liste', {
    von: $('#p-f-von').val(), bis: $('#p-f-bis').val(), status: $('#p-f-status').val()
  }).done(function (r) {
    if (!r || !r.success) return;
    var liste = r.data.plaene || [];
    var $e = $('#p-plan-liste').empty();
    var meter = 0;
    liste.forEach(function (p) { meter += parseInt(p.meter, 10) || 0; });
    $('#p-plaene-sub').text(liste.length
      ? (liste.length + (liste.length === 1 ? ' Einheit' : ' Einheiten')
         + ' · ' + zahl(meter) + ' m zusammen')
      : '');
    if (!liste.length) {
      $e.append($('<div class="p-leer">').text(
        'In diesem Zeitraum ist nichts geplant. Lege eine neue Einheit an.'));
      return;
    }
    liste.forEach(function (p) { $e.append(planZeile(p)); });
  });
}

function planNeu() {
  dialog('Neue Trainingseinheit',
    feldHtml('titel', 'Titel', '', 'z. B. Technik Brust')
    + feldHtml('datum', 'Datum', heute(), '', 'date')
    + feldHtml('startzeit', 'Beginn', '18:00', '', 'time')
    + feldHtml('dauer_min', 'Wasserzeit in Minuten', '90', '', 'number')
    + bahnHtml(25)
    + feldHtml('ziel', 'Ziel der Einheit', '', 'z. B. Gleitphase verlängern')
    + '<div class="p-hilfe">Alles Weitere kommt danach: Sets, Freigabe, '
    + 'Veröffentlichen.</div>',
    [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: 'Anlegen', klasse: 'p-btn-p', tu: function ($bd) {
          var d = {
            titel: $bd.find('[name=titel]').val(),
            datum: $bd.find('[name=datum]').val(),
            startzeit: $bd.find('[name=startzeit]').val(),
            dauer_min: $bd.find('[name=dauer_min]').val(),
            bahnlaenge: $bd.find('[name=bahnlaenge]').val(),
            ziel: $bd.find('[name=ziel]').val()
          };
          if (!d.datum) { toast('Ohne Datum geht es nicht.', 'fehler'); return false; }
          ajax('lsv07p_p_speichern', d).done(function (r) {
            if (!r || !r.success) return;
            dialogZu();
            planOeffnen(r.data.plan_id);
          });
          return false;
        } }
    ]);
}

function planKopieren(p) {
  dialog('Einheit kopieren',
    '<p>Kopiert werden Titel, Ziel und alle Sets. Freigabe und Zustand '
    + 'nicht — die Kopie ist ein frischer Entwurf.</p>'
    + feldHtml('datum', 'Datum der Kopie', plusTage(p.datum, 7), '', 'date'),
    [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: 'Kopieren', klasse: 'p-btn-p', tu: function ($bd) {
          ajax('lsv07p_p_kopie', { plan_id: p.id, datum: $bd.find('[name=datum]').val() })
            .done(function (r) {
              if (!r || !r.success) return;
              dialogZu();
              toast('Kopie angelegt.', 'gut');
              planOeffnen(r.data.plan_id);
            });
          return false;
        } }
    ]);
}

function planOeffnen(id) {
  ajax('lsv07p_p_holen', { plan_id: id }).done(function (r) {
    if (!r || !r.success) return;
    S.plan = r.data;
    zeige('plan');
    planZeichnen();
  });
}

function planZeichnen() {
  var p = S.plan;
  if (!p) return;

  $('#p-plan-name').text(p.titel || 'Trainingseinheit');
  $('#p-plan-sub').text(p.datum_text + (p.startzeit ? (', ' + p.startzeit + ' Uhr') : '')
    + ' · ' + p.status_name + ' · Fassung ' + p.version
    + (p.trainer_user === LSV07P.user_id ? '' : (' · ' + p.trainer)));

  var $ctl = $('#p-plan-ctl').empty();
  $('<button type="button" class="p-btn p-btn-klein">').text('Zurück')
    .on('click', function () { zeige('plaene'); }).appendTo($ctl);
  $('<button type="button" class="p-btn p-btn-klein">').text('Bühne')
    .on('click', function () { buehneAuf([p.id]); }).appendTo($ctl);
  $('<a class="p-btn p-btn-klein" target="_blank" rel="noopener">').text('Als PDF')
    .attr('href', pdfUrl('plan', { plan_id: p.id })).appendTo($ctl);

  (p.wege || []).forEach(function (w) {
    var text = { published: 'Veröffentlichen', completed: 'Als durchgeführt',
                 cancelled: 'Absagen', draft: 'Zurück in den Entwurf' }[w] || w;
    var kl = w === 'published' ? 'p-btn-ok' : (w === 'cancelled' ? 'p-btn-r' : '');
    if (!p.darf_aendern) return;
    $('<button type="button" class="p-btn p-btn-klein">').addClass(kl).text(text)
      .on('click', function () { statusSetzen(w); }).appendTo($ctl);
  });

  var $i = $('#p-plan-inhalt').empty();

  /* Kennzahlen zuerst: Umfang, Dauer, Sets, Soll. */
  var s = p.summe;
  var $z = $('<div class="p-kacheln p-kacheln-eng">').appendTo($i);
  $z.append(zahlKachel('Umfang', s.meter_text, s.bahnen + ' Bahnen'));
  $z.append(zahlKachel('Dauer', s.dauer_text,
    s.geschaetzt ? 'teils geschätzt' : 'nach Intervallen'));
  $z.append(zahlKachel('Sets', String(p.sets.length), ''));
  if (s.soll_min > 0) {
    $z.append(zahlKachel('Wasserzeit', s.soll_min + ' min',
      s.abweichung_min === 0 ? 'passt genau'
        : (s.abweichung_min > 0 ? (s.abweichung_min + ' min zu viel')
                                : (-s.abweichung_min + ' min Luft')),
      s.passt ? '' : 'ist-warn'));
  }

  if (p.ziel) {
    $i.append($('<div class="p-notiz">').text('Ziel: ' + p.ziel));
  }
  if (s.soll_min > 0 && !s.passt) {
    $i.append($('<div class="p-notiz p-notiz-warn">').text(
      'Der Inhalt dauert länger als die eingetragene Wasserzeit — '
      + s.abweichung_min + ' Minuten mehr.'));
  }

  /* Der Ablauf. */
  $i.append($('<h2 class="p-h2">').text('Ablauf'));
  var $sets = $('<div class="p-sets">').appendTo($i);
  if (!p.sets.length) {
    $sets.replaceWith($('<div class="p-leer">').text(
      'Noch kein Set. Füge eines hinzu oder nimm eine Vorlage aus der Bibliothek.'));
  } else {
    p.sets.forEach(function (st, i) { $sets.append(setZeile(st, i + 1, p.darf_aendern)); });
  }

  if (p.darf_aendern) {
    var $a = $('<div class="p-eintrag-akt">').appendTo($i);
    $('<button type="button" class="p-btn p-btn-p p-btn-klein">').text('Set hinzufügen')
      .on('click', function () { setDialog(null); }).appendTo($a);
    $('<button type="button" class="p-btn p-btn-b p-btn-klein">').text('Aus der Bibliothek')
      .on('click', bibliothekWaehlen).appendTo($a);
    $('<button type="button" class="p-btn p-btn-klein">').text('Als Vorlage sichern')
      .on('click', alsVorlage).appendTo($a);
  }

  /* Verteilung der Lagen. */
  if ((s.lagen || []).length > 1) {
    $i.append($('<h2 class="p-h2">').text('Verteilung'));
    var $b = $('<div class="p-balken">').appendTo($i);
    s.lagen.forEach(function (l) {
      var $zz = $('<div class="p-balken-zeile">').appendTo($b);
      $zz.append($('<span class="p-balken-name">').text(l.name));
      $zz.append($('<span class="p-balken-bahn">').append(
        $('<span class="p-balken-fuell">').css('width', l.prozent + '%')));
      $zz.append($('<span class="p-balken-wert">').text(zahl(l.meter) + ' m · ' + l.prozent + ' %'));
    });
  }

  /* Kopfdaten zum Ändern. */
  if (p.darf_aendern) {
    var $k = $('<div class="p-karte">').appendTo($i);
    $k.append($('<div class="p-karte-hd">').text('Kopfdaten'));
    var $bd = $('<div class="p-karte-bd">').appendTo($k);
    $bd.html(
      feldHtml('titel', 'Titel', p.titel)
      + feldHtml('datum', 'Datum', p.datum, '', 'date')
      + feldHtml('startzeit', 'Beginn', p.startzeit, '', 'time')
      + feldHtml('dauer_min', 'Wasserzeit in Minuten', p.dauer_min, '', 'number')
      + bahnHtml(p.bahnlaenge)
      + feldHtml('zusatz_min', 'Zuschlag in Minuten', p.zusatz_min, '', 'number')
      + '<div class="p-hilfe">Der Zuschlag ist die Zeit für Erklärungen, '
      + 'Bahnwechsel und organisatorische Pausen. Sie steht am Plan und nicht '
      + 'an den Sets, weil sie sich keinem Set zuordnen lässt.</div>'
      + feldHtml('ziel', 'Ziel der Einheit', p.ziel)
      + '<label class="p-lbl" for="p-notizen">Notizen (nur für Trainer)</label>'
      + '<textarea class="p-ctl" id="p-notizen" name="notizen" rows="3">'
      + esc(p.notizen || '') + '</textarea>'
      + '<div class="p-hilfe">Die Notizen stehen nicht im Plan der Schwimmer '
      + 'und nicht in ihrem PDF.</div>');
    var $ft = $('<div class="p-karte-ft">').appendTo($k);
    $('<button type="button" class="p-btn p-btn-p p-btn-klein">').text('Speichern')
      .on('click', function () { kopfSpeichern($bd); }).appendTo($ft);
    if (p.status === 'draft' || p.status === 'cancelled') {
      $('<button type="button" class="p-btn p-btn-r p-btn-klein">').text('Löschen')
        .on('click', planLoeschen).appendTo($ft);
    }
  }

  /* Der Plan als Datei. Steht bewusst gleich unter dem Ablauf: Bei vielen
     Trainern IST das PDF der Plan, und dann soll es nicht ganz unten
     stehen. */
  var $d = $('<div class="p-karte">').appendTo($i);
  $d.append($('<div class="p-karte-hd">').text('Der Plan als PDF'));
  var $db = $('<div class="p-karte-bd">').appendTo($d);
  dateienZeichnen($db);

  /* Freigabe. */
  var $f = $('<div class="p-karte">').appendTo($i);
  $f.append($('<div class="p-karte-hd">').text('Freigabe'));
  var $fb = $('<div class="p-karte-bd">').appendTo($f);
  freigabeZeichnen($fb);

  /* Versionen. */
  if (parseInt(p.version, 10) > 1 || p.status !== 'draft') {
    var $v = $('<div class="p-karte">').appendTo($i);
    $v.append($('<div class="p-karte-hd">').text('Fassungen'));
    var $vb = $('<div class="p-karte-bd">').appendTo($v);
    $vb.append($('<p class="p-muted">').text(
      'Jede Änderung nach der Veröffentlichung wird mit einer vollständigen '
      + 'Abschrift festgehalten.'));
    ajax('lsv07p_p_versionen', { plan_id: p.id }).done(function (r) {
      if (!r || !r.success) return;
      (r.data.versionen || []).forEach(function (v) {
        var $z2 = $('<div class="p-reihe">');
        $z2.append($('<div class="p-reihe-name">')
          .append($('<strong>').text('Fassung ' + v.version + ' — ' + v.was))
          .append($('<span>').text(v.am + ' · ' + v.wer)));
        $z2.append($('<div class="p-reihe-akt">').append(
          $('<button type="button" class="p-btn p-btn-klein">').text('Ansehen')
            .on('click', function () { versionZeigen(v); })));
        $vb.append($z2);
      });
    });
  }
}

// ── Der Plan als Datei ──────────────────────────────────────────────

/**
 * Die angehängten PDFs im Plan-Editor: Liste, Herunterladen, Entfernen und
 * das Feld zum Hochladen.
 */
function dateienZeichnen($bd) {
  var p = S.plan;
  $bd.empty();

  $bd.append($('<p class="p-muted">').text(
    'Wer seinen Plan schon als PDF hat, hängt ihn hier an, statt ihn Set für '
    + 'Set abzutippen. Die Schwimmer bekommen ihn dann zum Herunterladen — '
    + 'sobald der Plan veröffentlicht und für sie freigegeben ist.'));

  if (!(p.dateien || []).length) {
    $bd.append($('<div class="p-leer">').text('Noch keine Datei angehängt.'));
  } else {
    (p.dateien || []).forEach(function (d) {
      var $z = $('<div class="p-reihe">');
      var teile = [ d.groesse_text ];
      if (d.seiten > 0) teile.push(d.seiten + (d.seiten === 1 ? ' Seite' : ' Seiten'));
      teile.push('von ' + d.von);
      $z.append($('<div class="p-reihe-name">')
        .append($('<strong>').text(d.name))
        .append($('<span>').text(teile.join(' · '))));

      var $a = $('<div class="p-reihe-akt">').appendTo($z);
      $('<a class="p-btn p-btn-klein p-btn-b" target="_blank" rel="noopener">')
        .text('Öffnen').attr('href', dateiUrl(d.id)).appendTo($a);
      if (p.darf_aendern) {
        $('<button type="button" class="p-btn p-btn-klein p-btn-r">').text('Entfernen')
          .on('click', function () {
            frage('Datei entfernen', '„' + d.name + '" wird gelöscht. Die Schwimmer '
              + 'können sie danach nicht mehr herunterladen.', 'Entfernen', function () {
                ajax('lsv07p_p_datei_weg', { plan_id: p.id, datei_id: d.id })
                  .done(function (r) {
                    if (!r || !r.success) return;
                    S.plan = r.data;
                    planZeichnen();
                    toast('Datei entfernt.', 'gut');
                  });
              });
          }).appendTo($a);
      }
      $bd.append($z);
    });
  }

  if (!p.darf_aendern) return;

  var $form = $('<div class="p-datei-neu">').appendTo($bd);
  $form.append($('<label class="p-lbl" for="p-datei-feld">').text('PDF hochladen'));
  var $feld = $('<input type="file" class="p-ctl" id="p-datei-feld" accept="application/pdf,.pdf">')
    .appendTo($form);
  $form.append($('<div class="p-hilfe">').text(
    'Nur PDF, höchstens 15 MB. Ein abfotografierter Zettel ist am Beckenrand '
    + 'nicht zu lesen, und Office-Dateien können Makros tragen — darum nur PDF.'));
  var $knopf = $('<button type="button" class="p-btn p-btn-p p-btn-klein">')
    .text('Hochladen').appendTo($form);

  $knopf.on('click', function () {
    var datei = ($feld[0].files || [])[0];
    if (!datei) { toast('Wähle zuerst eine Datei.', 'fehler'); return; }
    dateiHochladen(p.id, datei, $knopf);
  });
}

/**
 * Hochladen geht nicht über den gewohnten Weg: Eine Datei braucht
 * FormData, und dabei darf jQuery weder den Inhaltstyp setzen noch die
 * Daten umbauen. Darum hier ein eigener Aufruf — mit denselben Meldungen.
 */
function dateiHochladen(planId, datei, $knopf) {
  var daten = new FormData();
  daten.append('action', 'lsv07p_p_datei');
  daten.append('nonce', LSV07P.nonce);
  daten.append('plan_id', planId);
  daten.append('datei', datei);

  $knopf.prop('disabled', true).text('Lädt …');
  ladenAn();

  $.ajax({
    url: LSV07P.ajax_url, type: 'POST', data: daten,
    processData: false, contentType: false,
    dataFilter: function (d) { return jsonRetten(d) || d; }
  }).always(function () {
    ladenAus();
    $knopf.prop('disabled', false).text('Hochladen');
  }).done(function (r) {
    if (!r || !r.success) {
      toast((r && r.data && r.data.message) || 'Das ging nicht.', 'fehler');
      return;
    }
    S.plan = r.data.plan;
    planZeichnen();
    toast('„' + r.data.datei.name + '" ist angehängt.', 'gut');
  }).fail(function (x) {
    toast(fehlerText(x), 'fehler');
  });
}

/** Die Dateien eines Plans zum Herunterladen — für Ansicht und Bühne. */
function dateiKnoepfe(p, $wo) {
  (p.dateien || []).forEach(function (d) {
    $('<a class="p-btn p-btn-klein" target="_blank" rel="noopener">')
      .text(d.seiten > 1 ? ('PDF des Trainers (' + d.seiten + ' Seiten)')
                         : 'PDF des Trainers')
      .attr('href', dateiUrl(d.id)).appendTo($wo);
  });
}

function zahlKachel(lbl, wert, sub, klasse) {
  var $k = $('<div class="p-zahl-kachel">').addClass(klasse || '');
  $k.append($('<span class="p-zahl-lbl">').text(lbl));
  $k.append($('<span class="p-zahl-wert">').text(wert));
  if (sub) $k.append($('<span class="p-kachel-sub">').text(sub));
  return $k;
}

function setZeile(st, nr, darf) {
  var $e = $('<div class="p-set">').attr('data-id', st.id);

  $e.append($('<span class="p-set-nr">').text(nr + '.'));

  var $m = $('<div class="p-set-mitte">').appendTo($e);
  if (st.titel) $m.append($('<span class="p-set-titel">').text(st.titel));
  $m.append($('<span class="p-set-kurz">').text(st.kurz));
  var unten = [];
  if (st.material) unten.push('Material: ' + st.material);
  if (st.hinweis) unten.push(st.hinweis);
  if (st.halbe_bahn) unten.push('Diese Strecke endet nicht am Beckenrand.');
  if (unten.length) $m.append($('<span class="p-set-hinweis">').text(unten.join(' · ')));

  var $z = $('<div class="p-set-zahlen">').appendTo($e);
  $z.append(setZahl(st.wiederholungen + ' ×', 'Wdh.'));
  $z.append(setZahl(st.strecke_m + ' m', 'Strecke'));
  $z.append(setZahl(st.lage_name, 'Lage'));
  $z.append(setZahl(st.intervall_text || '—', 'Intervall'));
  $z.append(setZahl(st.pause_text || '—', 'Pause'));
  $z.append(setZahl(zahl(st.meter) + ' m', 'Umfang', 'ist-meter'));
  $z.append(setZahl(st.dauer_text, 'Dauer', st.geschaetzt ? 'ist-geschaetzt' : ''));

  if (darf) {
    var $a = $('<div class="p-set-akt">').appendTo($e);
    /* Umsortiert wird mit zwei Knöpfen und nicht mit Ziehen. Ziehen ist am
       Rechner schöner, aber auf dem Telefon kämpft es mit dem Scrollen und
       mit einer Tastatur ist es überhaupt nicht zu bedienen. */
    $('<button type="button" class="p-set-griff" aria-label="Nach oben">').text('▲')
      .prop('disabled', nr === 1)
      .on('click', function () { setVerschieben(st.id, -1); }).appendTo($a);
    $('<button type="button" class="p-set-griff" aria-label="Nach unten">').text('▼')
      .prop('disabled', nr === (S.plan ? S.plan.sets.length : 0))
      .on('click', function () { setVerschieben(st.id, 1); }).appendTo($a);
    $('<button type="button" class="p-btn p-btn-klein">').text('Ändern')
      .on('click', function () { setDialog(st); }).appendTo($a);
    $('<button type="button" class="p-btn p-btn-klein p-btn-r">').text('Weg')
      .on('click', function () {
        frage('Set entfernen', 'Soll dieses Set aus dem Plan verschwinden?', 'Entfernen',
          function () {
            ajax('lsv07p_p_set_weg', { set_id: st.id }).done(function (r) {
              if (!r || !r.success) return;
              S.plan = r.data; planZeichnen(); toast('Set entfernt.', 'gut');
            });
          });
      }).appendTo($a);
  }

  if (st.knapp) {
    $e.find('.p-set-hinweis').remove();
    $('<span class="p-set-hinweis">').text(
      'Das Intervall ist kürzer als die geschätzte Schwimmzeit — '
      + 'ein schnelles Set, oder ein Tippfehler.').appendTo($m);
  }
  return $e;
}

function setZahl(wert, lbl, klasse) {
  return $('<span class="p-set-z">').addClass(klasse || '')
    .append($('<b>').text(wert)).append($('<span>').text(lbl));
}

function feldHtml(name, lbl, wert, platzhalter, typ) {
  return '<label class="p-lbl" for="p-f-' + name + '">' + esc(lbl) + '</label>'
    + '<input class="p-ctl" id="p-f-' + name + '" name="' + name + '"'
    + ' type="' + (typ || 'text') + '"'
    + ' value="' + esc(wert === undefined || wert === null ? '' : wert) + '"'
    + (platzhalter ? (' placeholder="' + esc(platzhalter) + '"') : '')
    + ' autocomplete="off">';
}

function bahnHtml(wert) {
  return '<label class="p-lbl" for="p-f-bahnlaenge">Bahnlänge</label>'
    + '<select class="p-ctl" id="p-f-bahnlaenge" name="bahnlaenge">'
    + '<option value="25"' + (parseInt(wert, 10) === 50 ? '' : ' selected') + '>25 m</option>'
    + '<option value="50"' + (parseInt(wert, 10) === 50 ? ' selected' : '') + '>50 m</option>'
    + '</select>';
}

function lagenHtml(wert) {
  var h = '<label class="p-lbl" for="p-f-lage">Lage</label>'
        + '<select class="p-ctl" id="p-f-lage" name="lage">';
  Object.keys(LSV07P.lagen || {}).forEach(function (k) {
    h += '<option value="' + esc(k) + '"' + (k === wert ? ' selected' : '') + '>'
       + esc(LSV07P.lagen[k].name) + '</option>';
  });
  return h + '</select>';
}

function kopfSpeichern($bd) {
  var d = { plan_id: S.plan.id };
  ['titel', 'datum', 'startzeit', 'ziel', 'notizen', 'dauer_min', 'bahnlaenge', 'zusatz_min']
    .forEach(function (n) { d[n] = $bd.find('[name=' + n + ']').val(); });

  ajax('lsv07p_p_speichern', d).done(function (r) {
    if (!r || !r.success) return;
    S.plan = r.data;
    planZeichnen();
    toast('Gespeichert.', 'gut');
  });
}

function planLoeschen() {
  frage('Einheit löschen',
    'Die Einheit und alle ihre Sets verschwinden. Das lässt sich nicht zurücknehmen.',
    'Löschen', function () {
      ajax('lsv07p_p_weg', { plan_id: S.plan.id }).done(function (r) {
        if (!r || !r.success) return;
        toast('Einheit gelöscht.', 'gut');
        zeige('plaene');
      });
    });
}

function statusSetzen(neu) {
  ajax('lsv07p_p_status', { plan_id: S.plan.id, status: neu }).done(function (r) {
    if (!r || !r.success) return;
    S.plan = r.data.plan;
    planZeichnen();
    if (r.data.warnung) toast(r.data.warnung, 'fehler');
    else toast('Zustand geändert.', 'gut');
  });
}

function setDialog(st) {
  var neu = !st;
  st = st || { wiederholungen: 1, strecke_m: 100, lage: 'frei',
               intervall_sek: 0, pause_sek: 0, titel: '', material: '', hinweis: '' };

  dialog(neu ? 'Set hinzufügen' : 'Set ändern',
    feldHtml('titel', 'Titel (darf leer bleiben)', st.titel, 'z. B. Hauptserie')
    + feldHtml('wiederholungen', 'Wiederholungen', st.wiederholungen, '', 'number')
    + feldHtml('strecke_m', 'Meter je Wiederholung', st.strecke_m, '', 'number')
    + lagenHtml(st.lage)
    + feldHtml('intervall_sek', 'Intervall', st.intervall_sek
        ? mmss(st.intervall_sek) : '', '1:40')
    + '<div class="p-hilfe">Das Intervall ist der Abstand <strong>innerhalb</strong> '
    + 'des Sets — „8 × 50 auf 1:00". Bleibt es leer, wird die Dauer geschätzt.</div>'
    + feldHtml('pause_sek', 'Pause nach dem Set', st.pause_sek
        ? mmss(st.pause_sek) : '', '2:00')
    + feldHtml('material', 'Material', st.material, 'Paddles, Brett, Flossen')
    + '<label class="p-lbl" for="p-hinweis">Hinweis</label>'
    + '<textarea class="p-ctl" id="p-hinweis" name="hinweis" rows="2">'
    + esc(st.hinweis || '') + '</textarea>',
    [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: neu ? 'Hinzufügen' : 'Speichern', klasse: 'p-btn-p', tu: function ($bd) {
          var d = {
            titel: $bd.find('[name=titel]').val(),
            wiederholungen: $bd.find('[name=wiederholungen]').val(),
            strecke_m: $bd.find('[name=strecke_m]').val(),
            lage: $bd.find('[name=lage]').val(),
            intervall_sek: sekLesen($bd.find('[name=intervall_sek]').val()),
            pause_sek: sekLesen($bd.find('[name=pause_sek]').val()),
            material: $bd.find('[name=material]').val(),
            hinweis: $bd.find('[name=hinweis]').val()
          };
          if (neu) d.plan_id = S.plan.id; else d.set_id = st.id;
          ajax('lsv07p_p_set', d).done(function (r) {
            if (!r || !r.success) return;
            S.plan = r.data;
            dialogZu();
            planZeichnen();
          });
          return false;
        } }
    ]);
}

function versionZeigen(v) {
  ajax('lsv07p_p_version', { plan_id: S.plan.id, version_id: v.id }).done(function (r) {
    if (!r || !r.success) return;
    var a = r.data.abschrift || {};
    var sets = (a.sets || []);
    var h = '<p class="p-muted">' + esc(r.data.am + ' · ' + r.data.wer + ' · '
          + r.data.was) + '</p>';
    h += '<div class="p-tbl-wrap"><table class="p-tbl"><thead><tr>'
      + '<th></th><th>Set</th><th>Umfang</th><th>Lage</th><th>Intervall</th>'
      + '</tr></thead><tbody>';
    sets.forEach(function (s, i) {
      h += '<tr><td>' + (i + 1) + '.</td><td>' + esc(s.titel || '') + '</td><td>'
        + esc(s.wiederholungen + ' × ' + s.strecke_m + ' m') + '</td><td>'
        + esc((LSV07P.lagen[s.lage] || {}).name || s.lage) + '</td><td>'
        + esc(parseInt(s.intervall_sek, 10) ? mmss(s.intervall_sek) : '—')
        + '</td></tr>';
    });
    if (!sets.length) h += '<tr><td colspan="5">Keine Sets.</td></tr>';
    h += '</tbody></table></div>';
    dialog('Fassung ' + r.data.version, h);
  });
}

// ── Freigabe ────────────────────────────────────────────────────────

function freigabeZeichnen($bd) {
  var p = S.plan;
  $bd.empty();

  if (p.status === 'draft') {
    $bd.append($('<div class="p-notiz">').text(
      'Der Plan ist ein Entwurf. Freigeben kannst du jetzt schon — sichtbar '
      + 'wird er erst mit dem Veröffentlichen.'));
  }

  if (!(p.freigabe || []).length) {
    $bd.append($('<div class="p-leer">').text(
      'Für niemanden freigegeben. Bisher siehst nur du diesen Plan.'));
  } else {
    (p.freigabe || []).forEach(function (f) {
      var $z = $('<div class="p-reihe">');
      $z.append($('<div class="p-reihe-name">')
        .append($('<strong>').text(f.name))
        .append($('<span>').text(f.art === 'team' ? 'ganze Mannschaft' : 'einzeln')));
      if (p.darf_aendern) {
        $z.append($('<div class="p-reihe-akt">').append(
          $('<button type="button" class="p-btn p-btn-klein p-btn-r">').text('Zurücknehmen')
            .on('click', function () {
              ajax('lsv07p_p_frei', { plan_id: p.id, art: 'weg', freigabe_id: f.id })
                .done(function (r) {
                  if (!r || !r.success) return;
                  S.plan.freigabe = r.data.freigabe;
                  freigabeZeichnen($bd);
                });
            })));
      }
      $bd.append($z);
    });
  }

  if (!p.darf_aendern) return;

  var $a = $('<div class="p-eintrag-akt">').appendTo($bd);
  $('<button type="button" class="p-btn p-btn-b p-btn-klein">').text('Mannschaft freigeben')
    .on('click', function () { freigabeTeamDialog($bd); }).appendTo($a);
  $('<button type="button" class="p-btn p-btn-klein">').text('Einzelne Person')
    .on('click', function () { freigabePersonDialog($bd); }).appendTo($a);
}

function freigabeTeamDialog($bd) {
  teamsHolen(function (teams) {
    if (!teams.length) {
      toast('Dir ist keine Mannschaft zugeteilt.', 'fehler');
      return;
    }
    var h = '<label class="p-lbl" for="p-f-team">Mannschaft</label>'
          + '<select class="p-ctl" id="p-f-team" name="team_id">';
    teams.forEach(function (t) {
      h += '<option value="' + t.id + '">' + esc(t.name) + '</option>';
    });
    h += '</select><div class="p-hilfe">Es sehen die, die am Tag des Trainings '
      + 'in der Mannschaft sind. Wer später dazukommt, sieht die Einheit auch; '
      + 'wer vorher gegangen ist, nicht mehr.</div>';

    dialog('Mannschaft freigeben', h, [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: 'Freigeben', klasse: 'p-btn-p', tu: function ($d) {
          ajax('lsv07p_p_frei', { plan_id: S.plan.id, art: 'team',
                                  team_id: $d.find('[name=team_id]').val() })
            .done(function (r) {
              if (!r || !r.success) return;
              S.plan.freigabe = r.data.freigabe;
              dialogZu();
              freigabeZeichnen($bd);
              toast('Freigegeben.', 'gut');
            });
          return false;
        } }
    ]);
  });
}

function freigabePersonDialog($bd) {
  ajax('lsv07p_p_schwimmer').done(function (r) {
    if (!r || !r.success) return;
    var liste = r.data.schwimmer || [];
    if (!liste.length) {
      toast('In deinen Mannschaften ist niemand eingetragen.', 'fehler');
      return;
    }
    var h = '<label class="p-lbl" for="p-f-user">Person</label>'
          + '<select class="p-ctl" id="p-f-user" name="user_id">';
    liste.forEach(function (s) {
      h += '<option value="' + s.user_id + '">' + esc(s.name)
         + (s.teams.length ? esc(' (' + s.teams.join(', ') + ')') : '') + '</option>';
    });
    h += '</select><div class="p-hilfe">Eine Einzelfreigabe gilt unabhängig von '
      + 'der Mannschaft — für einen Sonderplan nach einer Verletzung zum '
      + 'Beispiel.</div>';

    dialog('Einzelne Person freigeben', h, [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: 'Freigeben', klasse: 'p-btn-p', tu: function ($d) {
          ajax('lsv07p_p_frei', { plan_id: S.plan.id, art: 'person',
                                  user_id: $d.find('[name=user_id]').val() })
            .done(function (r2) {
              if (!r2 || !r2.success) return;
              S.plan.freigabe = r2.data.freigabe;
              dialogZu();
              freigabeZeichnen($bd);
              toast('Freigegeben.', 'gut');
            });
          return false;
        } }
    ]);
  });
}

function teamsHolen(dann) {
  if (S.basis && S.basis.teams) { dann(S.basis.teams); return; }
  ajax('lsv07p_v_basis').done(function (r) {
    if (!r || !r.success) return;
    S.basis = r.data;
    dann(r.data.teams || []);
  });
}

// ── Bibliothek ──────────────────────────────────────────────────────

function vorlagenLaden() {
  if (!$('#p-b-kat option').length) {
    var $s = $('#p-b-kat');
    $s.append($('<option value="">').text('alle Kategorien'));
    (LSV07P.kategorien || []).forEach(function (k) {
      $s.append($('<option>').attr('value', k).text(k));
    });
  }

  ajax('lsv07p_b_liste', {
    kategorie: $('#p-b-kat').val(), suche: $('#p-b-suche').val()
  }).done(function (r) {
    if (!r || !r.success) return;
    var $e = $('#p-vorlage-liste').empty();
    var liste = r.data.vorlagen || [];
    if (!liste.length) {
      $e.append($('<div class="p-leer">').text(
        'Noch keine Vorlage. Lege eine an oder sichere einen Plan als Vorlage.'));
      return;
    }
    liste.forEach(function (v) {
      var $z = $('<div class="p-eintrag">');
      var $k = $('<div class="p-eintrag-kopf">').appendTo($z);
      var $l = $('<div>').appendTo($k);
      $l.append($('<h3 class="p-eintrag-titel">').text(v.name));
      if (v.kategorie) $l.append($('<div class="p-eintrag-datum">').text(v.kategorie));
      var $r2 = $('<div>').appendTo($k);
      if (v.oeffentlich) $r2.append($('<span class="p-chip p-chip-gruen">').text('für alle'));
      if (!v.eigen) $r2.append($('<span class="p-chip p-chip-grau">').text(v.ersteller));

      var meta = [v.sets + (v.sets === 1 ? ' Set' : ' Sets'), v.meter_text, v.dauer_text];
      if (v.beschreibung) meta.push(v.beschreibung);
      $z.append($('<div class="p-eintrag-meta">').text(meta.join(' · ')));

      var $a = $('<div class="p-eintrag-akt">').appendTo($z);
      $('<button type="button" class="p-btn p-btn-b p-btn-klein">').text('Öffnen')
        .on('click', function () { vorlageOeffnen(v.id); }).appendTo($a);
      $e.append($z);
    });
  });
}

function vorlageOeffnen(id) {
  ajax('lsv07p_b_holen', { vorlage_id: id }).done(function (r) {
    if (!r || !r.success) return;
    S.vorlage = r.data;
    vorlageDialog();
  });
}

function vorlageDialog() {
  var v = S.vorlage;
  var h = '<p class="p-muted">' + esc(v.meter_text + ' · ' + v.dauer_text
        + ' · von ' + v.ersteller) + '</p>';
  if (v.beschreibung) h += '<p>' + esc(v.beschreibung) + '</p>';
  h += '<div id="p-v-sets"></div>';

  var knoepfe = [{ text: 'Schließen', klasse: 'p-btn-nein' }];
  if (v.darf_aendern) {
    knoepfe.push({ text: 'Set hinzufügen', klasse: '', offen: true,
      tu: function () { vorlageSetDialog(null); } });
    knoepfe.push({ text: 'Eigenschaften', klasse: '', offen: true,
      tu: function () { vorlageKopfDialog(); } });
    knoepfe.push({ text: 'Löschen', klasse: 'p-btn-r', offen: true, tu: function () {
      frage('Vorlage löschen',
        'Die Vorlage verschwindet. Pläne, in die sie eingefügt wurde, bleiben '
        + 'unberührt — deren Sets sind Kopien.', 'Löschen', function () {
          ajax('lsv07p_b_weg', { vorlage_id: v.id }).done(function (r) {
            if (!r || !r.success) return;
            dialogZu(); toast('Vorlage gelöscht.', 'gut'); vorlagenLaden();
          });
        });
    } });
  }

  dialog(v.name, h, knoepfe, function () { vorlageSetsZeichnen(); });
}

function vorlageSetsZeichnen() {
  var v = S.vorlage;
  var $s = $('#p-v-sets').empty();
  if (!(v.sets || []).length) {
    $s.append($('<div class="p-leer">').text('Noch kein Set in dieser Vorlage.'));
    return;
  }
  var $l = $('<div class="p-sets">').appendTo($s);
  v.sets.forEach(function (st, i) {
    var $z = $('<div class="p-set">');
    $z.append($('<span class="p-set-nr">').text((i + 1) + '.'));
    var $m = $('<div class="p-set-mitte">').appendTo($z);
    if (st.titel) $m.append($('<span class="p-set-titel">').text(st.titel));
    $m.append($('<span class="p-set-kurz">').text(st.kurz));
    if (st.hinweis) $m.append($('<span class="p-set-hinweis">').text(st.hinweis));
    if (v.darf_aendern) {
      var $a = $('<div class="p-set-akt">').appendTo($z);
      $('<button type="button" class="p-set-griff" aria-label="Nach oben">').text('▲')
        .prop('disabled', i === 0)
        .on('click', function () { vorlageOrdnen(st.id, -1); }).appendTo($a);
      $('<button type="button" class="p-set-griff" aria-label="Nach unten">').text('▼')
        .prop('disabled', i === v.sets.length - 1)
        .on('click', function () { vorlageOrdnen(st.id, 1); }).appendTo($a);
      $('<button type="button" class="p-btn p-btn-klein">').text('Ändern')
        .on('click', function () { vorlageSetDialog(st); }).appendTo($a);
      $('<button type="button" class="p-btn p-btn-klein p-btn-r">').text('Weg')
        .on('click', function () {
          ajax('lsv07p_b_set_weg', { vorlage_id: v.id, set_id: st.id })
            .done(function (r) {
              if (!r || !r.success) return;
              S.vorlage = r.data; vorlageSetsZeichnen();
            });
        }).appendTo($a);
    }
    $l.append($z);
  });
}

/** Ein Set der Vorlage um eine Stelle verschieben. */
function vorlageOrdnen(setId, richtung) {
  var v = S.vorlage;
  var ids = (v.sets || []).map(function (s) { return s.id; });
  var i = ids.indexOf(setId);
  var j = i + richtung;
  if (i < 0 || j < 0 || j >= ids.length) return;
  ids[i] = ids[j];
  ids[j] = setId;

  ajax('lsv07p_b_ordnen', { vorlage_id: v.id, ids: ids.join(',') })
    .done(function (r) {
      if (!r || !r.success) return;
      S.vorlage = r.data;
      vorlageSetsZeichnen();
    });
}

function vorlageKopfDialog() {
  var v = S.vorlage || { name: '', beschreibung: '', kategorie: '', oeffentlich: false };
  var neu = !v.id;

  var h = feldHtml('name', 'Name', v.name, 'z. B. Technikblock Brust')
    + '<label class="p-lbl" for="p-f-kategorie">Kategorie</label>'
    + '<input class="p-ctl" id="p-f-kategorie" name="kategorie" list="p-kat-liste"'
    + ' value="' + esc(v.kategorie || '') + '" autocomplete="off">'
    + '<datalist id="p-kat-liste">'
    + (LSV07P.kategorien || []).map(function (k) {
        return '<option value="' + esc(k) + '">';
      }).join('')
    + '</datalist>'
    + '<label class="p-lbl" for="p-f-beschreibung">Beschreibung</label>'
    + '<textarea class="p-ctl" id="p-f-beschreibung" name="beschreibung" rows="2">'
    + esc(v.beschreibung || '') + '</textarea>'
    + '<label class="p-schalter-zeile' + (v.oeffentlich ? ' ist-an' : '') + '">'
    + '<input type="checkbox" name="oeffentlich"' + (v.oeffentlich ? ' checked' : '') + '>'
    + '<span class="p-schalter-txt"><span class="p-schalter-name">Für alle Trainer</span>'
    + '<span class="p-schalter-sub">Andere dürfen die Vorlage benutzen, aber nicht '
    + 'ändern.</span></span></label>';

  dialog(neu ? 'Neue Vorlage' : 'Eigenschaften', h, [
    { text: 'Abbrechen', klasse: 'p-btn-nein' },
    { text: neu ? 'Anlegen' : 'Speichern', klasse: 'p-btn-p', tu: function ($bd) {
        var d = {
          name: $bd.find('[name=name]').val(),
          kategorie: $bd.find('[name=kategorie]').val(),
          beschreibung: $bd.find('[name=beschreibung]').val(),
          oeffentlich: $bd.find('[name=oeffentlich]').is(':checked') ? '1' : '0'
        };
        if (!neu) d.vorlage_id = v.id;
        if (!String(d.name).trim()) { toast('Der Name fehlt.', 'fehler'); return false; }
        ajax('lsv07p_b_speichern', d).done(function (r) {
          if (!r || !r.success) return;
          dialogZu();
          if (neu) vorlageOeffnen(r.data.vorlage_id);
          else { S.vorlage = r.data; vorlagenLaden(); vorlageDialog(); }
        });
        return false;
      } }
  ]);
}

function vorlageSetDialog(st) {
  var neu = !st;
  var v = S.vorlage;
  st = st || { wiederholungen: 1, strecke_m: 100, lage: 'frei',
               intervall_sek: 0, pause_sek: 0, titel: '', material: '', hinweis: '' };

  dialog(neu ? 'Set hinzufügen' : 'Set ändern',
    feldHtml('titel', 'Titel (darf leer bleiben)', st.titel)
    + feldHtml('wiederholungen', 'Wiederholungen', st.wiederholungen, '', 'number')
    + feldHtml('strecke_m', 'Meter je Wiederholung', st.strecke_m, '', 'number')
    + lagenHtml(st.lage)
    + feldHtml('intervall_sek', 'Intervall', st.intervall_sek ? mmss(st.intervall_sek) : '', '1:40')
    + feldHtml('pause_sek', 'Pause nach dem Set', st.pause_sek ? mmss(st.pause_sek) : '', '2:00')
    + feldHtml('material', 'Material', st.material)
    + '<label class="p-lbl" for="p-hinweis2">Hinweis</label>'
    + '<textarea class="p-ctl" id="p-hinweis2" name="hinweis" rows="2">'
    + esc(st.hinweis || '') + '</textarea>',
    [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: neu ? 'Hinzufügen' : 'Speichern', klasse: 'p-btn-p', tu: function ($bd) {
          var d = {
            vorlage_id: v.id,
            titel: $bd.find('[name=titel]').val(),
            wiederholungen: $bd.find('[name=wiederholungen]').val(),
            strecke_m: $bd.find('[name=strecke_m]').val(),
            lage: $bd.find('[name=lage]').val(),
            intervall_sek: sekLesen($bd.find('[name=intervall_sek]').val()),
            pause_sek: sekLesen($bd.find('[name=pause_sek]').val()),
            material: $bd.find('[name=material]').val(),
            hinweis: $bd.find('[name=hinweis]').val()
          };
          if (!neu) d.set_id = st.id;
          ajax('lsv07p_b_set', d).done(function (r) {
            if (!r || !r.success) return;
            S.vorlage = r.data;
            dialogZu();
            vorlageDialog();
          });
          return false;
        } }
    ]);
}

/** Aus dem Plan heraus: eine Vorlage auswählen und einfügen. */
function bibliothekWaehlen() {
  ajax('lsv07p_b_liste').done(function (r) {
    if (!r || !r.success) return;
    var liste = r.data.vorlagen || [];
    if (!liste.length) {
      toast('Die Bibliothek ist noch leer.', 'fehler');
      return;
    }
    var h = '<p class="p-muted">Die Sets werden <strong>kopiert</strong>. Änderst du '
          + 'die Vorlage später, bleibt dieser Plan, wie er ist.</p>'
          + '<label class="p-lbl" for="p-f-vorlage">Vorlage</label>'
          + '<select class="p-ctl" id="p-f-vorlage" name="vorlage_id">';
    liste.forEach(function (v) {
      h += '<option value="' + v.id + '">' + esc(v.name + ' — ' + v.sets + ' Sets, '
         + v.meter_text) + '</option>';
    });
    h += '</select>';

    dialog('Aus der Bibliothek einfügen', h, [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: 'Einfügen', klasse: 'p-btn-p', tu: function ($bd) {
          ajax('lsv07p_b_einfuegen', { vorlage_id: $bd.find('[name=vorlage_id]').val(),
                                       plan_id: S.plan.id })
            .done(function (r2) {
              if (!r2 || !r2.success) return;
              S.plan = r2.data.plan;
              dialogZu();
              planZeichnen();
              toast(r2.data.anzahl + ' Sets eingefügt.', 'gut');
            });
          return false;
        } }
    ]);
  });
}

function alsVorlage() {
  dialog('Als Vorlage sichern',
    '<p>Die Sets dieser Einheit werden in eine neue Vorlage kopiert. Die '
    + 'Einheit selbst bleibt unverändert.</p>'
    + feldHtml('name', 'Name der Vorlage', S.plan.titel)
    + feldHtml('kategorie', 'Kategorie', '')
    + '<label class="p-schalter-zeile"><input type="checkbox" name="oeffentlich">'
    + '<span class="p-schalter-txt"><span class="p-schalter-name">Für alle Trainer</span>'
    + '</span></label>',
    [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: 'Sichern', klasse: 'p-btn-p', tu: function ($bd) {
          ajax('lsv07p_b_aus_plan', {
            plan_id: S.plan.id,
            name: $bd.find('[name=name]').val(),
            kategorie: $bd.find('[name=kategorie]').val(),
            oeffentlich: $bd.find('[name=oeffentlich]').is(':checked') ? '1' : '0'
          }).done(function (r) {
            if (!r || !r.success) return;
            dialogZu();
            toast('Als Vorlage gesichert.', 'gut');
          });
          return false;
        } }
    ]);
}

// ── Leistungen (Trainer) ────────────────────────────────────────────

function leistungAufbauen() {
  ajax('lsv07p_p_schwimmer').done(function (r) {
    if (!r || !r.success) return;
    var liste = r.data.schwimmer || [];
    var $s = $('#p-l-wer').empty();
    if (!liste.length) {
      $s.append($('<option value="0">').text('— niemand eingetragen —'));
      $('#p-leistung-inhalt').html(
        '<div class="p-leer">In deinen Mannschaften ist noch niemand eingetragen.</div>');
      return;
    }
    liste.forEach(function (p) {
      $s.append($('<option>').attr('value', p.user_id)
        .text(p.name + (p.teams.length ? (' (' + p.teams.join(', ') + ')') : '')));
    });
    if (!S.wer || !liste.some(function (p) { return p.user_id === S.wer; })) {
      S.wer = liste[0].user_id;
    }
    $s.val(String(S.wer));
    zeitenLaden();
  });
}

function zeitenLaden() {
  if (!S.wer) return;
  ajax('lsv07p_z_liste', { user_id: S.wer }).done(function (r) {
    if (!r || !r.success) return;
    zeitenZeichnen(r.data, true);
  });
}

function zeitenZeichnen(d, darf) {
  var $i = $(darf ? '#p-leistung-inhalt' : '#p-meine-zeiten').empty();

  $i.append($('<h2 class="p-h2">').text('Bestleistungen'));
  $i.append($('<p class="p-muted">').text(
    'Ermittelt aus den erfassten Zeiten — je Strecke, Lage und Bahnlänge die '
    + 'schnellste. Es gibt keine gespeicherte Bestzeit, die veralten könnte.'));

  var best = d.bestzeiten || [];
  if (!best.length) {
    $i.append($('<div class="p-leer">').text('Noch keine Zeit erfasst.'));
  } else {
    var $w = $('<div class="p-tbl-wrap">').appendTo($i);
    var $t = $('<table class="p-tbl">').appendTo($w);
    $t.append('<thead><tr><th>Strecke</th><th>Lage</th><th>Bahn</th>'
      + '<th class="p-nr">Zeit</th><th>Datum</th><th>Anlass</th><th></th></tr></thead>');
    var $b = $('<tbody>').appendTo($t);
    best.forEach(function (z) {
      var $r = $('<tr>').appendTo($b);
      $r.append($('<td>').text(z.strecke_m + ' m'));
      $r.append($('<td>').text(z.lage_name));
      $r.append($('<td>').text(z.bahn_text));
      $r.append($('<td class="p-nr">').append($('<span class="p-zeit">').text(z.zeit)));
      $r.append($('<td>').text(de(z.datum)));
      $r.append($('<td>').text(z.wettkampf || (z.offiziell ? '' : 'Training')));
      var $a = $('<td>').appendTo($r);
      $('<button type="button" class="p-btn p-btn-klein">').text('Verlauf')
        .on('click', function () { verlaufZeigen(z, darf); }).appendTo($a);
    });
  }

  $i.append($('<h2 class="p-h2">').text('Alle Zeiten'));
  var alle = d.zeiten || [];
  if (!alle.length) {
    $i.append($('<div class="p-leer">').text('Noch keine Zeit erfasst.'));
  } else {
    var $w2 = $('<div class="p-tbl-wrap">').appendTo($i);
    var $t2 = $('<table class="p-tbl">').appendTo($w2);
    $t2.append('<thead><tr><th>Datum</th><th>Strecke</th><th class="p-nr">Zeit</th>'
      + '<th>Bahn</th><th>Anlass</th><th></th></tr></thead>');
    var $b2 = $('<tbody>').appendTo($t2);
    alle.forEach(function (z) {
      var $r = $('<tr>').appendTo($b2);
      $r.append($('<td>').text(de(z.datum)));
      $r.append($('<td>').text(z.strecke_text));
      var $zt = $('<td class="p-nr">').appendTo($r);
      $zt.append($('<span class="p-zeit">').text(z.zeit));
      if (z.bestzeit) $zt.append(' ').append($('<span class="p-chip p-chip-gruen">').text('best'));
      $r.append($('<td>').text(z.bahn_text));
      $r.append($('<td>').text(z.wettkampf || (z.offiziell ? '' : 'Training')));
      var $a2 = $('<td>').appendTo($r);
      if (darf) {
        $('<button type="button" class="p-btn p-btn-klein">').text('Ändern')
          .on('click', function () { zeitDialog(z); }).appendTo($a2);
        $('<button type="button" class="p-btn p-btn-klein p-btn-r">').text('Weg')
          .on('click', function () {
            frage('Zeit löschen', 'Soll diese Zeit verschwinden? Die Bestzeit wird '
              + 'danach neu ermittelt.', 'Löschen', function () {
                ajax('lsv07p_z_weg', { zeit_id: z.id }).done(function (r) {
                  if (!r || !r.success) return;
                  zeitenZeichnen(r.data, true);
                  toast('Zeit gelöscht.', 'gut');
                });
              });
          }).appendTo($a2);
      }
    });
  }

  if (darf) {
    var $a3 = $('<div class="p-eintrag-akt">').appendTo($i);
    $('<a class="p-btn p-btn-klein" target="_blank" rel="noopener">').text('Bestzeiten als PDF')
      .attr('href', pdfUrl('bestzeiten', { user_id: S.wer })).appendTo($a3);
  }
}

function zeitDialog(z) {
  var neu = !z;
  z = z || { strecke_m: 50, lage: 'frei', bahnlaenge: 25, zeit: '',
             datum: heute(), wettkampf: '', offiziell: false, bemerkung: '' };

  var h = '<label class="p-lbl" for="p-f-strecke">Strecke</label>'
        + '<select class="p-ctl" id="p-f-strecke" name="strecke_m">';
  (LSV07P.strecken || []).forEach(function (m) {
    h += '<option value="' + m + '"' + (parseInt(z.strecke_m, 10) === m ? ' selected' : '')
       + '>' + m + ' m</option>';
  });
  h += '</select>';

  h += '<label class="p-lbl" for="p-f-lage2">Lage</label>'
     + '<select class="p-ctl" id="p-f-lage2" name="lage">';
  Object.keys(LSV07P.lagen || {}).forEach(function (k) {
    if (!LSV07P.lagen[k].zeit) return;
    h += '<option value="' + esc(k) + '"' + (k === z.lage ? ' selected' : '') + '>'
       + esc(LSV07P.lagen[k].name) + '</option>';
  });
  h += '</select>';

  h += bahnHtml(z.bahnlaenge)
     + feldHtml('zeit', 'Zeit', z.zeit, '1:02,35')
     + '<div class="p-hilfe">Komma oder Punkt, beides geht. Unter einer Minute '
     + 'genügt „28,42".</div>'
     + feldHtml('erreicht_am', 'Datum', z.datum, '', 'date')
     + feldHtml('wettkampf', 'Anlass', z.wettkampf, 'z. B. Kreismeisterschaft')
     + '<label class="p-schalter-zeile' + (z.offiziell ? ' ist-an' : '') + '">'
     + '<input type="checkbox" name="offiziell"' + (z.offiziell ? ' checked' : '') + '>'
     + '<span class="p-schalter-txt"><span class="p-schalter-name">Offizielle Zeit</span>'
     + '<span class="p-schalter-sub">Im Wettkampf geschwommen und protokolliert — '
     + 'nicht im Training.</span></span></label>'
     + feldHtml('bemerkung', 'Bemerkung', z.bemerkung);

  dialog(neu ? 'Zeit erfassen' : 'Zeit ändern', h, [
    { text: 'Abbrechen', klasse: 'p-btn-nein' },
    { text: neu ? 'Erfassen' : 'Speichern', klasse: 'p-btn-p', tu: function ($bd) {
        var d = {
          user_id: S.wer,
          strecke_m: $bd.find('[name=strecke_m]').val(),
          lage: $bd.find('[name=lage]').val(),
          bahnlaenge: $bd.find('[name=bahnlaenge]').val(),
          zeit: $bd.find('[name=zeit]').val(),
          erreicht_am: $bd.find('[name=erreicht_am]').val(),
          wettkampf: $bd.find('[name=wettkampf]').val(),
          offiziell: $bd.find('[name=offiziell]').is(':checked') ? '1' : '0',
          bemerkung: $bd.find('[name=bemerkung]').val()
        };
        if (!neu) d.zeit_id = z.id;
        ajax(neu ? 'lsv07p_z_neu' : 'lsv07p_z_aendern', d).done(function (r) {
          if (!r || !r.success) return;
          dialogZu();
          zeitenZeichnen(r.data, true);
          toast(neu ? 'Zeit erfasst.' : 'Gespeichert.', 'gut');
        });
        return false;
      } }
  ]);
}

function verlaufZeigen(z, darf) {
  var d = { strecke_m: z.strecke_m, lage: z.lage, bahnlaenge: z.bahnlaenge };
  if (darf) d.user_id = S.wer;

  ajax(darf ? 'lsv07p_z_liste' : 'lsv07p_s_verlauf',
       darf ? { user_id: S.wer } : d).done(function (r) {
    if (!r || !r.success) return;

    /* Beim Trainer kommt die Liste aller Zeiten zurück; der Verlauf einer
       Strecke wird daraus gefiltert. Beim Schwimmer liefert der Server den
       Verlauf schon fertig. */
    var liste;
    if (darf) {
      liste = (r.data.zeiten || []).filter(function (x) {
        return x.strecke_m === z.strecke_m && x.lage === z.lage
            && x.bahnlaenge === z.bahnlaenge;
      }).slice().reverse();
      var best = 0;
      liste.forEach(function (x) {
        x.neue_bestzeit = (best === 0 || x.hundertstel < best);
        x.verbesserung_text = (best > 0 && x.hundertstel < best)
          ? ('−' + zeitText(best - x.hundertstel)) : '';
        if (best === 0 || x.hundertstel < best) best = x.hundertstel;
      });
      liste.reverse();
    } else {
      liste = (r.data.verlauf || []).slice().reverse();
    }

    var h = '<div class="p-tbl-wrap"><table class="p-tbl"><thead><tr>'
      + '<th>Datum</th><th class="p-nr">Zeit</th><th class="p-nr">Gewinn</th>'
      + '<th>Anlass</th></tr></thead><tbody>';
    liste.forEach(function (x) {
      h += '<tr><td>' + esc(de(x.datum)) + '</td>'
        + '<td class="p-nr"><span class="p-zeit">' + esc(x.zeit) + '</span>'
        + (x.neue_bestzeit ? ' <span class="p-chip p-chip-gruen">best</span>' : '') + '</td>'
        + '<td class="p-nr"><span class="p-zeit-neu">' + esc(x.verbesserung_text || '')
        + '</span></td>'
        + '<td>' + esc(x.wettkampf || (x.offiziell ? '' : 'Training')) + '</td></tr>';
    });
    if (!liste.length) h += '<tr><td colspan="4">Nur eine Zeit auf dieser Strecke.</td></tr>';
    h += '</tbody></table></div>';

    var url = pdfUrl('verlauf', $.extend({}, d));
    h += '<p><a class="p-btn p-btn-klein" target="_blank" rel="noopener" href="'
      + esc(url) + '">Als PDF</a></p>';

    dialog(z.strecke_m + ' m ' + z.lage_name + ' (' + z.bahn_text + '-Bahn)', h);
  });
}

/**
 * Die Rangliste einer Mannschaft auf einer Strecke. Gezählt werden die
 * aktuellen Mitglieder — eine Rangliste mit Ehemaligen sagt nichts über die
 * Mannschaft von heute.
 */
function ranglisteDialog() {
  teamsHolen(function (teams) {
    if (!teams.length) {
      toast('Dir ist keine Mannschaft zugeteilt.', 'fehler');
      return;
    }
    var h = '<label class="p-lbl" for="p-r-team">Mannschaft</label>'
          + '<select class="p-ctl" id="p-r-team" name="team_id">';
    teams.forEach(function (t) {
      h += '<option value="' + t.id + '">' + esc(t.name) + '</option>';
    });
    h += '</select>';

    h += '<label class="p-lbl" for="p-r-strecke">Strecke</label>'
       + '<select class="p-ctl" id="p-r-strecke" name="strecke_m">';
    (LSV07P.strecken || []).forEach(function (m) {
      h += '<option value="' + m + '"' + (m === 50 ? ' selected' : '') + '>' + m + ' m</option>';
    });
    h += '</select>';

    h += '<label class="p-lbl" for="p-r-lage">Lage</label>'
       + '<select class="p-ctl" id="p-r-lage" name="lage">';
    Object.keys(LSV07P.lagen || {}).forEach(function (k) {
      if (!LSV07P.lagen[k].zeit) return;
      h += '<option value="' + esc(k) + '">' + esc(LSV07P.lagen[k].name) + '</option>';
    });
    h += '</select>' + bahnHtml(25) + '<div id="p-r-aus"></div>';

    dialog('Rangliste', h, [
      { text: 'Schließen', klasse: 'p-btn-nein' },
      { text: 'Anzeigen', klasse: 'p-btn-p', offen: true, tu: function ($bd) {
          var d = {
            team_id: $bd.find('[name=team_id]').val(),
            strecke_m: $bd.find('[name=strecke_m]').val(),
            lage: $bd.find('[name=lage]').val(),
            bahnlaenge: $bd.find('[name=bahnlaenge]').val()
          };
          ajax('lsv07p_z_rangliste', d).done(function (r) {
            if (!r || !r.success) return;
            var liste = r.data.rangliste || [];
            var $aus = $('#p-r-aus').empty();
            if (!liste.length) {
              $aus.append($('<div class="p-leer">').text(
                'Auf dieser Strecke hat in dieser Mannschaft noch niemand eine Zeit.'));
              return;
            }
            var $w = $('<div class="p-tbl-wrap">').appendTo($aus);
            var $t = $('<table class="p-tbl">').appendTo($w);
            $t.append('<thead><tr><th></th><th>Name</th><th class="p-nr">Zeit</th>'
              + '<th class="p-nr">Abstand</th><th>Datum</th></tr></thead>');
            var $b = $('<tbody>').appendTo($t);
            liste.forEach(function (z) {
              var $r2 = $('<tr>').appendTo($b);
              $r2.append($('<td>').append($('<span class="p-platz">')
                .addClass(z.platz === 1 ? 'ist-eins' : '').text(z.platz)));
              $r2.append($('<td>').text(z.name));
              $r2.append($('<td class="p-nr">').append($('<span class="p-zeit">').text(z.zeit)));
              $r2.append($('<td class="p-nr">').text(z.abstand || ''));
              $r2.append($('<td>').text(de(z.datum)));
            });
            $aus.append($('<p>').append(
              $('<a class="p-btn p-btn-klein" target="_blank" rel="noopener">')
                .text('Als PDF').attr('href', pdfUrl('rangliste', d))));
          });
          return false;
        } }
    ]);
  });
}

/** Hundertstel als Text — nur für den Gewinn im Verlauf des Trainers. */
function zeitText(h) {
  var v = Math.max(0, parseInt(h, 10) || 0);
  var m = Math.floor(v / 6000), s = Math.floor((v % 6000) / 100), c = v % 100;
  var ende = ('0' + c).slice(-2);
  return m > 0 ? (m + ':' + ('0' + s).slice(-2) + ',' + ende) : (s + ',' + ende);
}

// ── Mannschaften ────────────────────────────────────────────────────

function teamsLaden() {
  ajax('lsv07p_v_basis').done(function (r) {
    if (!r || !r.success) return;
    S.basis = r.data;

    var $ctl = $('#p-teams-ctl').empty();
    if (r.data.ist_admin) {
      $('<button type="button" class="p-btn p-btn-p p-btn-klein">').text('Neue Mannschaft')
        .on('click', function () { teamDialog(null); }).appendTo($ctl);
    }
    $('#p-teams-sub').text(r.data.ist_admin
      ? 'Alle Mannschaften des Vereins.'
      : 'Die Mannschaften, die du betreust.');

    var $e = $('#p-team-liste').empty();
    var teams = r.data.teams || [];
    if (!teams.length) {
      $e.append($('<div class="p-leer">').text(r.data.ist_admin
        ? 'Noch keine Mannschaft angelegt.'
        : 'Dir ist noch keine Mannschaft zugeteilt. Das macht die Verwaltung.'));
      return;
    }
    teams.forEach(function (t) {
      var $z = $('<div class="p-eintrag">');
      var $k = $('<div class="p-eintrag-kopf">').appendTo($z);
      var $l = $('<div>').appendTo($k);
      $l.append($('<h3 class="p-eintrag-titel">').text(t.name));
      $l.append($('<div class="p-eintrag-datum">').text(
        t.mitglieder + (t.mitglieder === 1 ? ' Schwimmer' : ' Schwimmer')));
      if (!t.aktiv) {
        $('<div>').appendTo($k).append($('<span class="p-chip p-chip-grau">').text('ruht'));
      }

      var meta = [];
      meta.push((t.trainer || []).length
        ? ('Trainer: ' + t.trainer.map(function (x) { return x.name; }).join(', '))
        : 'Kein Trainer zugeteilt');
      if (t.beschreibung) meta.push(t.beschreibung);
      $z.append($('<div class="p-eintrag-meta">').text(meta.join(' · ')));

      var $a = $('<div class="p-eintrag-akt">').appendTo($z);
      $('<button type="button" class="p-btn p-btn-b p-btn-klein">').text('Öffnen')
        .on('click', function () { teamOeffnen(t.id); }).appendTo($a);
      $('<a class="p-btn p-btn-klein" target="_blank" rel="noopener">').text('Als PDF')
        .attr('href', pdfUrl('mannschaft', { team_id: t.id })).appendTo($a);
      if (r.data.ist_admin) {
        $('<button type="button" class="p-btn p-btn-klein">').text('Umbenennen')
          .on('click', function () { teamDialog(t); }).appendTo($a);
      }
      $e.append($z);
    });
  });
}

function teamDialog(t) {
  var neu = !t;
  t = t || { name: '', beschreibung: '', sortierung: 0, aktiv: true };

  dialog(neu ? 'Neue Mannschaft' : 'Mannschaft ändern',
    feldHtml('name', 'Name', t.name, 'z. B. C-Mannschaft')
    + '<label class="p-lbl" for="p-f-beschr">Beschreibung</label>'
    + '<textarea class="p-ctl" id="p-f-beschr" name="beschreibung" rows="2">'
    + esc(t.beschreibung || '') + '</textarea>'
    + feldHtml('sortierung', 'Reihenfolge', t.sortierung, '', 'number')
    + '<label class="p-schalter-zeile' + (t.aktiv ? ' ist-an' : '') + '">'
    + '<input type="checkbox" name="aktiv"' + (t.aktiv ? ' checked' : '') + '>'
    + '<span class="p-schalter-txt"><span class="p-schalter-name">Aktiv</span>'
    + '<span class="p-schalter-sub">Eine ruhende Mannschaft bleibt mit ihrer '
    + 'Geschichte stehen, taucht aber nicht mehr in den Listen auf.</span></span></label>',
    [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: neu ? 'Anlegen' : 'Speichern', klasse: 'p-btn-p', tu: function ($bd) {
          var d = {
            name: $bd.find('[name=name]').val(),
            beschreibung: $bd.find('[name=beschreibung]').val(),
            sortierung: $bd.find('[name=sortierung]').val(),
            aktiv: $bd.find('[name=aktiv]').is(':checked') ? 1 : 0
          };
          if (!neu) d.id = t.id;
          if (!String(d.name).trim()) { toast('Der Name fehlt.', 'fehler'); return false; }
          ajax('lsv07p_v_team', d).done(function (r) {
            if (!r || !r.success) return;
            dialogZu();
            S.basis = null;
            teamsLaden();
            toast('Gespeichert.', 'gut');
          });
          return false;
        } }
    ]);
}

function teamOeffnen(id) {
  ajax('lsv07p_v_team_daten', { team_id: id }).done(function (r) {
    if (!r || !r.success) return;
    teamDetail(r.data);
  });
}

function teamDetail(t) {
  dialog(t.name, '<div id="p-td"></div>', [{ text: 'Schließen', klasse: 'p-btn-nein' }],
    function () {
      var $d = $('#p-td').empty();

      $d.append($('<h3 class="p-h3">').text('Betreuung'));
      if (!(t.trainer || []).length) {
        $d.append($('<div class="p-leer">').text('Kein Trainer zugeteilt.'));
      } else {
        t.trainer.forEach(function (x) {
          var $z = $('<div class="p-reihe">');
          $z.append($('<div class="p-reihe-name">').append($('<strong>').text(x.name)));
          if (t.darf_trainer) {
            $z.append($('<div class="p-reihe-akt">').append(
              $('<button type="button" class="p-btn p-btn-klein p-btn-r">').text('Entziehen')
                .on('click', function () {
                  ajax('lsv07p_v_trainer', { team_id: t.id, user_id: x.user_id, weg: '1' })
                    .done(function (r) {
                      if (!r || !r.success) return;
                      t.trainer = r.data.trainer; teamDetail(t);
                    });
                })));
          }
          $d.append($z);
        });
      }
      if (t.darf_trainer) {
        $d.append($('<div class="p-eintrag-akt">').append(
          $('<button type="button" class="p-btn p-btn-b p-btn-klein">').text('Trainer zuteilen')
            .on('click', function () { trainerZuteilen(t); })));
      }

      $d.append($('<h3 class="p-h3">').text('Schwimmerinnen und Schwimmer'));
      var laufend = (t.mitglieder || []).filter(function (m) { return m.laeuft; });
      var frueher = (t.mitglieder || []).filter(function (m) { return !m.laeuft; });

      if (!laufend.length) {
        $d.append($('<div class="p-leer">').text('Noch niemand aufgenommen.'));
      }
      laufend.forEach(function (m) {
        var $z = $('<div class="p-reihe">');
        if (!m.aktiv) $z.addClass('ist-ohne');
        $z.append($('<div class="p-reihe-name">')
          .append($('<strong>').text(m.name))
          .append($('<span>').text('dabei seit ' + de(m.von)
            + (m.aktiv ? '' : ' · Zugang ruht'))));
        $z.append($('<div class="p-reihe-akt">').append(
          $('<button type="button" class="p-btn p-btn-klein p-btn-r">').text('Beenden')
            .on('click', function () {
              frage('Mitgliedschaft beenden',
                m.name + ' verlässt die Mannschaft. Trainings- und Leistungsdaten '
                + 'bleiben erhalten; Pläne bis heute bleiben sichtbar.',
                'Beenden', function () {
                  ajax('lsv07p_v_mitglied_aus', { team_id: t.id, mitglied_id: m.id })
                    .done(function (r) {
                      if (!r || !r.success) return;
                      t.mitglieder = r.data.mitglieder; teamDetail(t);
                    });
                });
            })));
        $d.append($z);
      });

      $d.append($('<div class="p-eintrag-akt">').append(
        $('<button type="button" class="p-btn p-btn-b p-btn-klein">').text('Aufnehmen')
          .on('click', function () { mitgliedAufnehmen(t); })));

      if (frueher.length) {
        $d.append($('<h3 class="p-h3">').text('Ehemalige'));
        frueher.forEach(function (m) {
          $d.append($('<div class="p-reihe">').append(
            $('<div class="p-reihe-name">')
              .append($('<strong>').text(m.name))
              .append($('<span>').text(de(m.von) + ' bis ' + de(m.bis)))));
        });
      }
    });
}

function trainerZuteilen(t) {
  ajax('lsv07p_v_basis').done(function (r) {
    if (!r || !r.success) return;
    var kandidaten = (r.data.personen || []).filter(function (p) {
      return p.aktiv && (p.rolle === 'coach' || p.rolle === 'admin');
    });
    if (!kandidaten.length) {
      toast('Es ist noch niemand als Trainer eingetragen.', 'fehler');
      return;
    }
    var h = '<label class="p-lbl" for="p-f-tr">Konto</label>'
          + '<select class="p-ctl" id="p-f-tr" name="user_id">';
    kandidaten.forEach(function (p) {
      h += '<option value="' + p.user_id + '">' + esc(p.name + ' — ' + p.rolle_name)
         + '</option>';
    });
    h += '</select><div class="p-hilfe">Trainer einer Mannschaft kann nur werden, wer '
      + 'in SwimPlan als Trainer oder Verwaltung eingetragen ist.</div>';

    dialog('Trainer zuteilen', h, [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: 'Zuteilen', klasse: 'p-btn-p', tu: function ($bd) {
          ajax('lsv07p_v_trainer', { team_id: t.id, user_id: $bd.find('[name=user_id]').val() })
            .done(function (r2) {
              if (!r2 || !r2.success) return;
              t.trainer = r2.data.trainer;
              dialogZu();
              teamDetail(t);
            });
          return false;
        } }
    ]);
  });
}

function mitgliedAufnehmen(t) {
  if (!(t.frei || []).length) {
    toast('Es ist kein freier Schwimmer eingetragen. Die Verwaltung trägt Konten '
      + 'unter „Konten" ein.', 'fehler');
    return;
  }
  var h = '<label class="p-lbl" for="p-f-mg">Schwimmerin oder Schwimmer</label>'
        + '<select class="p-ctl" id="p-f-mg" name="user_id">';
  t.frei.forEach(function (p) {
    h += '<option value="' + p.user_id + '">' + esc(p.name) + '</option>';
  });
  h += '</select>' + feldHtml('start', 'Dabei seit', heute(), '', 'date');

  dialog('In die Mannschaft aufnehmen', h, [
    { text: 'Abbrechen', klasse: 'p-btn-nein' },
    { text: 'Aufnehmen', klasse: 'p-btn-p', tu: function ($bd) {
        ajax('lsv07p_v_mitglied', { team_id: t.id,
                                    user_id: $bd.find('[name=user_id]').val(),
                                    start: $bd.find('[name=start]').val() })
          .done(function (r) {
            if (!r || !r.success) return;
            dialogZu();
            teamOeffnen(t.id);
            toast('Aufgenommen.', 'gut');
          });
        return false;
      } }
  ]);
}

// ── Konten (Verwaltung) ─────────────────────────────────────────────

function kontenLaden() {
  ajax('lsv07p_v_basis').done(function (r) {
    if (!r || !r.success) return;
    S.basis = r.data;
    kontenZeichnen(r.data.personen || []);
    if (r.data.config) {
      $('#p-cfg-richtwert').val(r.data.config.richtwert_text);
    }
  });
  ajax('lsv07p_v_spur').done(function (r) {
    if (!r || !r.success) return;
    var $s = $('#p-spur').empty();
    var liste = r.data.spur || [];
    if (!liste.length) {
      $s.append($('<div class="p-leer">').text('Noch nichts protokolliert.'));
      return;
    }
    liste.forEach(function (e) {
      $s.append($('<div class="p-reihe">').append(
        $('<div class="p-reihe-name">')
          .append($('<strong>').text(e.was + (e.hinweis ? (' — ' + e.hinweis) : '')))
          .append($('<span>').text(e.am + ' · ' + e.wer))));
    });
  });
}

function kontenZeichnen(liste) {
  var $k = $('#p-konten-liste').empty();
  if (!liste.length) {
    $k.append($('<div class="p-leer">').text(
      'Noch kein Konto eingetragen. Suche oben nach einem WordPress-Konto.'));
    return;
  }
  liste.forEach(function (p) {
    var $z = $('<div class="p-reihe">');
    if (!p.aktiv) $z.addClass('ist-ohne');
    var unten = [ p.rolle_name ];
    if (!p.aktiv) unten.push('abgeschaltet');
    /* Woher die Rolle kommt, gehört dazu: Sonst sucht jemand lange nach dem
       Eintrag, den er nicht ändern kann. */
    if (p.wp) unten.push('über WordPress');
    if (p.email) unten.push(p.email);
    $z.append($('<div class="p-reihe-name">')
      .append($('<strong>').text(p.name))
      .append($('<span>').text(unten.join(' · '))));

    var $a = $('<div class="p-reihe-akt">').appendTo($z);
    if (p.wp) {
      /* Ein WordPress-Administrator ist hier Verwaltung, solange er es in
         WordPress ist. Hier gibt es daran nichts zu drehen. */
      $a.append($('<span class="p-chip p-chip-blau">').text('Administrator der Seite'));
      $k.append($z);
      return;
    }
    $('<button type="button" class="p-btn p-btn-klein">').text('Rolle')
      .on('click', function () { rolleDialog(p); }).appendTo($a);
    if (p.aktiv) {
      $('<button type="button" class="p-btn p-btn-klein p-btn-r">').text('Abschalten')
        .on('click', function () {
          frage('Zugang abschalten',
            p.name + ' kommt dann nicht mehr hinein. Pläne, Mitgliedschaften und '
            + 'Zeiten bleiben erhalten.', 'Abschalten', function () {
              ajax('lsv07p_v_person_aus', { user_id: p.user_id }).done(function (r) {
                if (!r || !r.success) return;
                kontenZeichnen(r.data.personen || []);
                toast('Abgeschaltet.', 'gut');
              });
            });
        }).appendTo($a);
    } else {
      $('<button type="button" class="p-btn p-btn-ok p-btn-klein">').text('Einschalten')
        .on('click', function () {
          ajax('lsv07p_v_person_an', { user_id: p.user_id }).done(function (r) {
            if (!r || !r.success) return;
            kontenZeichnen(r.data.personen || []);
            toast('Eingeschaltet.', 'gut');
          });
        }).appendTo($a);
    }
    $k.append($z);
  });
}

function rolleDialog(p) {
  var h = '<p>' + esc(p.name) + '</p>'
        + '<label class="p-lbl" for="p-f-rolle">Rolle</label>'
        + '<select class="p-ctl" id="p-f-rolle" name="rolle">';
  var rollen = (S.basis && S.basis.rollen) || {};
  Object.keys(rollen).forEach(function (k) {
    h += '<option value="' + esc(k) + '"' + (k === p.rolle ? ' selected' : '') + '>'
       + esc(rollen[k]) + '</option>';
  });
  h += '</select>' + feldHtml('notiz', 'Notiz', p.notiz)
     + '<div class="p-hilfe">Verwaltung darf alles. Trainer dürfen ihre eigenen '
     + 'Mannschaften und Pläne. Schwimmer sehen nur, was für sie freigegeben ist.</div>';

  dialog('Rolle setzen', h, [
    { text: 'Abbrechen', klasse: 'p-btn-nein' },
    { text: 'Speichern', klasse: 'p-btn-p', tu: function ($bd) {
        ajax('lsv07p_v_person', { user_id: p.user_id,
                                  rolle: $bd.find('[name=rolle]').val(),
                                  notiz: $bd.find('[name=notiz]').val() })
          .done(function (r) {
            if (!r || !r.success) return;
            dialogZu();
            kontenZeichnen(r.data.personen || []);
            toast('Rolle gesetzt. Sie gilt beim nächsten Aufruf.', 'gut');
          });
        return false;
      } }
  ]);
}

var _sucheZeit;
function kandidatenSuchen() {
  clearTimeout(_sucheZeit);
  var q = $('#p-k-suche').val();
  _sucheZeit = setTimeout(function () {
    if (String(q).trim().length < 2) { $('#p-kandidaten').empty(); return; }
    ajax('lsv07p_v_kandidaten', { suche: q }).done(function (r) {
      if (!r || !r.success) return;
      var $e = $('#p-kandidaten').empty();
      var liste = r.data.treffer || [];
      if (!liste.length) {
        $e.append($('<div class="p-leer">').text('Kein Konto gefunden.'));
        return;
      }
      liste.forEach(function (p) {
        var $z = $('<div class="p-reihe">');
        var sub = [ p.login, p.email ];
        if (p.wp) sub.push('Administrator der Seite — schon drin');
        else if (p.vorhanden) sub.push('schon eingetragen');
        $z.append($('<div class="p-reihe-name">')
          .append($('<strong>').text(p.name))
          .append($('<span>').text(sub.join(' · '))));
        if (!p.vorhanden) {
          $z.append($('<div class="p-reihe-akt">').append(
            $('<button type="button" class="p-btn p-btn-p p-btn-klein">').text('Eintragen')
              .on('click', function () {
                rolleDialog({ user_id: p.user_id, name: p.name, rolle: 'swimmer', notiz: '' });
              })));
        }
        $e.append($z);
      });
    });
  }, 320);
}

// ── Mein Training (Schwimmer) ───────────────────────────────────────

function meinZeile(p) {
  var $e = $('<div class="p-eintrag">');
  var $k = $('<div class="p-eintrag-kopf">').appendTo($e);
  var $l = $('<div>').appendTo($k);
  $l.append($('<h3 class="p-eintrag-titel">').text(p.titel || 'Training'));
  $l.append($('<div class="p-eintrag-datum">').text(
    p.datum_text + (p.startzeit ? (', ' + p.startzeit + ' Uhr') : '')));
  if (p.status === 'cancelled') {
    $('<div>').appendTo($k).append($('<span class="p-chip p-chip-rot">').text('abgesagt'));
  }

  var meta = [p.meter_text, p.dauer_text, p.bahnlaenge + '-m-Bahn'];
  if (p.dateien > 0) meta.push('mit PDF');
  if (p.ziel) meta.push(p.ziel);
  $e.append($('<div class="p-eintrag-meta">').text(meta.join(' · ')));

  var $a = $('<div class="p-eintrag-akt">').appendTo($e);
  $('<button type="button" class="p-btn p-btn-b p-btn-klein">').text('Ansehen')
    .on('click', function () { planAnsehen(p.id); }).appendTo($a);
  $('<button type="button" class="p-btn p-btn-klein">').text('Bühne')
    .on('click', function () { buehneAuf([p.id]); }).appendTo($a);
  $('<a class="p-btn p-btn-klein" target="_blank" rel="noopener">').text('Als PDF')
    .attr('href', pdfUrl('plan', { plan_id: p.id })).appendTo($a);
  return $e;
}

/**
 * Einen Plan lesen, ohne die Bühne. Das ist der schnelle Weg auf dem
 * Telefon: antippen, lesen, zurück. Die Bühne ist für den Beckenrand.
 */
function planAnsehen(id) {
  ajax('lsv07p_s_plan', { plan_id: id }).done(function (r) {
    if (!r || !r.success) return;
    var p = r.data;

    var h = '<p class="p-muted">' + esc(p.datum_text
          + (p.startzeit ? (', ' + p.startzeit + ' Uhr') : '')
          + ' · ' + p.summe.meter_text + ' · ' + p.summe.dauer_text
          + ' · ' + p.bahnlaenge + '-m-Bahn') + '</p>';
    if (p.ziel) h += '<p><strong>Ziel:</strong> ' + esc(p.ziel) + '</p>';

    h += '<div class="p-sets">';
    p.sets.forEach(function (st, i) {
      h += '<div class="p-set"><span class="p-set-nr">' + (i + 1) + '.</span>'
        + '<div class="p-set-mitte">'
        + (st.titel ? ('<span class="p-set-titel">' + esc(st.titel) + '</span>') : '')
        + '<span class="p-set-kurz">' + esc(st.kurz) + '</span>'
        + (st.material || st.hinweis
            ? ('<span class="p-set-hinweis">'
               + esc([st.material ? ('Material: ' + st.material) : '', st.hinweis]
                     .filter(Boolean).join(' · ')) + '</span>')
            : '')
        + '</div>'
        + '<div class="p-set-zahlen"><span class="p-set-z ist-meter"><b>'
        + esc(zahl(st.meter) + ' m') + '</b><span>Umfang</span></span></div>'
        + '</div>';
    });
    h += '</div>';
    if (!p.sets.length && !(p.dateien || []).length) {
      h += '<div class="p-leer">In diesem Plan steht kein Set.</div>';
    }
    h += '<div class="p-eintrag-akt" id="p-ansehen-dateien"></div>';

    dialog(p.titel || 'Training', h, [
      { text: 'Schließen', klasse: 'p-btn-nein' },
      { text: 'Auf die Bühne', klasse: 'p-btn-b', tu: function () { buehneAuf([id]); } }
    ], function () {
      /* Hat der Trainer ein PDF angehängt, steht es hier zum Herunterladen —
         bei vielen ist es DER Plan und nicht nur eine Beilage. */
      dateiKnoepfe(p, $('#p-ansehen-dateien'));
    });
  });
}

function meinLaden() {
  ajax('lsv07p_s_plaene', { von: plusTage(heute(), -60), bis: plusTage(heute(), 60) })
    .done(function (r) {
      if (!r || !r.success) return;
      var liste = r.data.plaene || [];
      var $e = $('#p-mein-liste').empty();
      $('#p-mein-sub').text(liste.length
        ? (liste.length + ' Einheiten sind für dich freigegeben')
        : '');
      if (!liste.length) {
        $e.append($('<div class="p-leer">').text(
          'Es ist noch kein Training für dich freigegeben. Das macht deine '
          + 'Trainerin oder dein Trainer.'));
        return;
      }
      liste.forEach(function (p) { $e.append(meinZeile(p)); });
    });
}

function meineZeitenLaden() {
  ajax('lsv07p_s_zeiten').done(function (r) {
    if (!r || !r.success) return;
    zeitenZeichnen(r.data, false);
    $('#p-meine-zeiten').prepend($('<div class="p-notiz">').text(r.data.hinweis || ''));
  });
}

/** Ein Set um eine Stelle verschieben. Gezählt wird auf dem Server neu. */
function setVerschieben(setId, richtung) {
  var ids = (S.plan.sets || []).map(function (s) { return s.id; });
  var i = ids.indexOf(setId);
  var j = i + richtung;
  if (i < 0 || j < 0 || j >= ids.length) return;
  ids[i] = ids[j];
  ids[j] = setId;

  ajax('lsv07p_p_ordnen', { plan_id: S.plan.id, ids: ids.join(',') })
    .done(function (r) {
      if (!r || !r.success) return;
      S.plan = r.data;
      planZeichnen();
    });
}

// ── Die Bühne ═══════════════════════════════════════════════════════
//
// Vollbild für einen Plan, Splitscreen für mehrere, Größe frei
// einstellbar. Die Einstellung liegt am Konto und nicht im Browser: Wer am
// Beckenrand das Vereinstablet benutzt, findet seine Ansicht wieder, und
// wer sich abmeldet, lässt sie dort nicht zurück.

function layout() {
  if (!S.buehne.layout) {
    S.buehne.layout = $.extend({ spalten: 1, groesse: 100, richtung: 'spalten',
                                 kompakt: false, vollbild: false, plaene: [] },
                               LSV07P.layout || {});
  }
  return S.buehne.layout;
}

var _layoutZeit;
function layoutSichern() {
  clearTimeout(_layoutZeit);
  _layoutZeit = setTimeout(function () {
    ajax('lsv07p_s_layout', { layout: JSON.stringify(layout()) });
  }, 800);
}

function buehneAuf(ids) {
  var l = layout();
  if (ids && ids.length) l.plaene = ids.slice(0, LSV07P.buehne_max || 6);

  S.buehne.offen = true;
  $('#p-buehne').prop('hidden', false);
  $('body').addClass('p-buehne-an');
  buehneLeisteSetzen();
  buehneLaden();
  if (l.vollbild) vollbildAn(true);
}

function buehneZu() {
  S.buehne.offen = false;
  $('#p-buehne').prop('hidden', true);
  $('body').removeClass('p-buehne-an');
  vollbildAn(false);
  layoutSichern();
}

/** Die Leiste auf den Stand der Einstellung bringen. */
function buehneLeisteSetzen() {
  var l = layout();
  $('.p-bb-s').attr('aria-pressed', 'false')
    .filter('[data-spalten="' + l.spalten + '"]').attr('aria-pressed', 'true');
  $('#p-bb-groesse').val(l.groesse);
  $('#p-bb-wert').text(l.groesse + ' %');
  $('#p-bb-kompakt').attr('aria-pressed', l.kompakt ? 'true' : 'false');
  $('#p-bb-vollbild').attr('aria-pressed', l.vollbild ? 'true' : 'false');

  /* Mehr Spalten als Pläne wären leere Spalten. Eingestellt bleibt, was
     eingestellt ist — gezeigt wird höchstens so viel, wie da ist. */
  var wieviele = Math.max(1, Math.min(l.spalten, (l.plaene || []).length || 1));
  $('#p-buehne-flaeche')
    .css({ '--p-spalten': wieviele, '--p-skala': (l.groesse / 100) })
    .attr('data-spalten-wunsch', l.spalten)
    .toggleClass('ist-knapp', !!l.kompakt);
}

function buehneLaden() {
  var l = layout();
  if (!l.plaene.length) {
    $('#p-buehne-flaeche').empty();
    $('#p-buehne-leer').prop('hidden', false);
    return;
  }
  $('#p-buehne-leer').prop('hidden', true);

  ajax('lsv07p_s_buehne', { ids: l.plaene.join(',') }).done(function (r) {
    if (!r || !r.success) return;
    var plaene = r.data.plaene || [];
    S.buehne.plaene = plaene;

    /* Nur das behalten, was tatsächlich zurückkam — sonst fragt die Bühne
       beim nächsten Öffnen wieder nach einem Plan, den es nicht mehr gibt
       oder nicht mehr geben darf. */
    l.plaene = plaene.map(function (p) { return p.id; });

    var $f = $('#p-buehne-flaeche').empty();
    plaene.forEach(function (p) { $f.append(buehnePlan(p)); });

    if (r.data.verwehrt > 0) {
      toast(r.data.verwehrt === 1
        ? 'Ein Plan ist nicht (mehr) für dich freigegeben.'
        : (r.data.verwehrt + ' Pläne sind nicht für dich freigegeben.'), 'fehler');
    }
    if (!plaene.length) {
      $('#p-buehne-leer').prop('hidden', false);
    }
    layoutSichern();
  });
}

function buehnePlan(p) {
  var $k = $('<div class="p-bp">');

  var $h = $('<div class="p-bp-hd">').appendTo($k);
  $h.append($('<b>').text(p.titel || 'Training'));
  $h.append($('<span>').text(p.datum_text
    + (p.startzeit ? (', ' + p.startzeit + ' Uhr') : '')));

  var $z = $('<div class="p-bp-zahlen">').appendTo($k);
  $z.append($('<span>').append($('<b>').text(p.summe.meter_text)).append(' Umfang'));
  $z.append($('<span>').append($('<b>').text(p.summe.dauer_text)).append(' Dauer'));
  $z.append($('<span>').append($('<b>').text(p.bahnlaenge + ' m')).append(' Bahn'));
  $z.append($('<span>').append($('<b>').text(String(p.sets.length))).append(' Sets'));

  if (p.ziel) $k.append($('<div class="p-bp-ziel">').text(p.ziel));

  p.sets.forEach(function (st, i) {
    var $s = $('<div class="p-bp-set">').appendTo($k);
    $s.append($('<span class="p-bp-nr">').text((i + 1) + '.'));
    $s.append($('<span class="p-bp-umfang">').text(
      (st.wiederholungen > 1 ? (st.wiederholungen + ' × ') : '') + st.strecke_m + ' m'));
    $s.append($('<span class="p-bp-lage">').text(st.lage_name));
    if (st.intervall_text) {
      $s.append($('<span class="p-bp-takt">').text('auf ' + st.intervall_text));
    } else if (st.pause_text) {
      $s.append($('<span class="p-bp-takt">').text(st.pause_text + ' Pause'));
    }
    if (st.titel) $s.append($('<span class="p-bp-titel">').text(st.titel));
    var unten = [];
    if (st.material) unten.push('Material: ' + st.material);
    if (st.hinweis) unten.push(st.hinweis);
    if (unten.length) $s.append($('<span class="p-bp-hinweis">').text(unten.join(' · ')));
  });

  if (!p.sets.length) {
    $k.append($('<div class="p-bp-ziel">').text((p.dateien || []).length
      ? 'Dieser Plan liegt als PDF vor — unten zum Öffnen.'
      : 'In diesem Plan steht noch kein Set.'));
  }

  var $zu = $('<div class="p-bp-zu">').appendTo($k);
  $zu.append($('<span>').text(p.status_name + ' · Fassung ' + p.version));
  $zu.append($('<span>').text(p.trainer));
  (p.dateien || []).forEach(function (d) {
    $zu.append($('<a target="_blank" rel="noopener">').text('PDF: ' + d.name)
      .attr('href', dateiUrl(d.id)));
  });
  return $k;
}

/** Welche Pläne auf die Bühne dürfen — die Auswahl. */
function buehneWaehlen() {
  var trainer = !!LSV07P.darf_planen;
  var aktion = trainer ? 'lsv07p_p_liste' : 'lsv07p_s_plaene';
  var d = { von: plusTage(heute(), -30), bis: plusTage(heute(), 60) };

  ajax(aktion, d).done(function (r) {
    if (!r || !r.success) return;
    var liste = r.data.plaene || [];
    if (!liste.length) {
      toast('Es gibt keinen Plan, den du auf die Bühne legen könntest.', 'fehler');
      return;
    }
    var l = layout();
    var max = LSV07P.buehne_max || 6;

    var h = '<p class="p-muted">Höchstens ' + max + ' gleichzeitig. Auf einem '
          + 'schmalen Gerät stehen sie untereinander — zwei Spalten auf einem '
          + 'Telefon sind zwei unlesbare Spalten.</p><div class="p-schalter">';
    liste.forEach(function (p) {
      var an = l.plaene.indexOf(p.id) >= 0;
      h += '<label class="p-schalter-zeile' + (an ? ' ist-an' : '') + '">'
        + '<input type="checkbox" value="' + p.id + '"' + (an ? ' checked' : '') + '>'
        + '<span class="p-schalter-txt">'
        + '<span class="p-schalter-name">' + esc(p.datum_text) + '</span>'
        + '<span class="p-schalter-sub">' + esc((p.titel || 'Training') + ' · '
            + p.meter_text + ' · ' + p.status_name) + '</span>'
        + '</span></label>';
    });
    h += '</div>';

    dialog('Pläne für die Bühne', h, [
      { text: 'Abbrechen', klasse: 'p-btn-nein' },
      { text: 'Übernehmen', klasse: 'p-btn-p', tu: function ($bd) {
          var ids = [];
          $bd.find('input[type=checkbox]:checked').each(function () {
            ids.push(parseInt($(this).val(), 10));
          });
          if (ids.length > max) {
            toast('Höchstens ' + max + ' Pläne gleichzeitig.', 'fehler');
            return false;
          }
          l.plaene = ids;
          /* Bei mehreren Plänen von Hand auf eine Spalte zu stehen ist fast
             nie gemeint — darum beim ersten Mal mitdenken, aber nie
             zurückdrehen, was jemand selbst eingestellt hat. */
          if (ids.length > 1 && l.spalten === 1) l.spalten = Math.min(ids.length, 2);
          dialogZu();
          buehneLeisteSetzen();
          buehneLaden();
          return false;
        } }
    ]);
  });
}

function groesseSetzen(wert) {
  var l = layout();
  l.groesse = Math.max(60, Math.min(220, parseInt(wert, 10) || 100));
  buehneLeisteSetzen();
  layoutSichern();
}

/**
 * Das echte Vollbild des Browsers. Es braucht eine Geste — darum hängt es
 * am Knopf und wird nicht beim Öffnen von sich aus verlangt. Beim nächsten
 * Mal steht es in der Einstellung und wird mitgenommen, soweit der Browser
 * es zulässt.
 */
function vollbildAn(an) {
  var el = document.getElementById('p-buehne');
  if (!el) return;
  try {
    if (an) {
      if (!document.fullscreenElement && el.requestFullscreen) {
        var pr = el.requestFullscreen();
        if (pr && pr.catch) pr.catch(function () {});
      } else if (!document.webkitFullscreenElement && el.webkitRequestFullscreen) {
        el.webkitRequestFullscreen();
      }
    } else if (document.fullscreenElement && document.exitFullscreen) {
      var pe = document.exitFullscreen();
      if (pe && pe.catch) pe.catch(function () {});
    } else if (document.webkitFullscreenElement && document.webkitExitFullscreen) {
      document.webkitExitFullscreen();
    }
  } catch (e) { /* Vollbild ist ein Komfort, kein Zweck. */ }
}

// ── Einhängen ───────────────────────────────────────────────────────

$(function () {
  if (!LSV07P.zugang) return;      // das Tor steht serverseitig da

  menueBauen();
  zeige('start');
  startLaden();

  $('#p-nav').on('click', '.p-nb', function () {
    zeige($(this).attr('data-seite'));
  });
  $('#p-heim').on('click', function () { zeige('start'); });

  $('#p-menue').on('click', function () {
    var auf = !$('#p-nav').hasClass('auf');
    $('#p-nav').toggleClass('auf', auf);
    $('#p-nav-schatten').prop('hidden', !auf);
    $(this).attr('aria-expanded', auf ? 'true' : 'false');
  });
  $('#p-nav-schatten').on('click', function () {
    $('#p-nav').removeClass('auf');
    $(this).prop('hidden', true);
    $('#p-menue').attr('aria-expanded', 'false');
  });

  $('#p-abmelden').on('click', function () {
    window.location.href = LSV07P.abmelden;
  });

  $('#p-dlg-zu').on('click', dialogZu);
  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if ($('#p-ov').hasClass('auf')) { dialogZu(); return; }
    if (S.buehne.offen) buehneZu();
  });

  /* Pläne */
  $('#p-f-los').on('click', plaeneLaden);
  $('#p-plan-neu').on('click', planNeu);
  $('#p-plan-zurueck').on('click', function () { zeige('plaene'); });

  /* Bibliothek */
  $('#p-b-los').on('click', vorlagenLaden);
  $('#p-b-suche').on('keydown', function (e) { if (e.key === 'Enter') vorlagenLaden(); });
  $('#p-vorlage-neu').on('click', function () { S.vorlage = null; vorlageKopfDialog(); });

  /* Leistungen */
  $('#p-l-wer').on('change', function () {
    S.wer = parseInt($(this).val(), 10) || 0;
    zeitenLaden();
  });
  $('#p-rangliste').on('click', ranglisteDialog);
  $('#p-zeit-neu').on('click', function () {
    if (!S.wer) { toast('Wähle zuerst eine Schwimmerin oder einen Schwimmer.', 'fehler'); return; }
    zeitDialog(null);
  });

  /* Konten */
  $('#p-k-suche').on('input', kandidatenSuchen);
  $('#p-cfg-speichern').on('click', function () {
    ajax('lsv07p_v_config', { richtwert_100: $('#p-cfg-richtwert').val() })
      .done(function (r) {
        if (!r || !r.success) return;
        $('#p-cfg-richtwert').val(r.data.richtwert_text);
        toast('Richtwert gespeichert.', 'gut');
      });
  });

  /* Eigene Zeiten */
  $('#p-best-pdf').on('click', function () {
    window.open(pdfUrl('bestzeiten', {}), '_blank', 'noopener');
  });

  /* Bühne */
  $('#p-buehne-auf, #p-buehne-auf-2').on('click', function () { buehneAuf(); });
  $('#p-bb-zu').on('click', buehneZu);
  $('#p-bb-waehlen, #p-bb-waehlen-2').on('click', buehneWaehlen);
  $('.p-bb-s').on('click', function () {
    layout().spalten = parseInt($(this).attr('data-spalten'), 10) || 1;
    buehneLeisteSetzen();
    layoutSichern();
  });
  $('#p-bb-groesse').on('input change', function () { groesseSetzen($(this).val()); });
  $('#p-bb-kleiner').on('click', function () { groesseSetzen(layout().groesse - 10); });
  $('#p-bb-groesser').on('click', function () { groesseSetzen(layout().groesse + 10); });
  $('#p-bb-kompakt').on('click', function () {
    layout().kompakt = !layout().kompakt;
    buehneLeisteSetzen();
    layoutSichern();
  });
  $('#p-bb-vollbild').on('click', function () {
    var l = layout();
    l.vollbild = !l.vollbild;
    vollbildAn(l.vollbild);
    buehneLeisteSetzen();
    layoutSichern();
  });
  $('#p-bb-pdf').on('click', function () {
    var ids = layout().plaene;
    if (!ids.length) { toast('Es liegt kein Plan auf der Bühne.', 'fehler'); return; }
    window.open(ids.length === 1
      ? pdfUrl('plan', { plan_id: ids[0] })
      : pdfUrl('plaene', { ids: ids.join(',') }), '_blank', 'noopener');
  });

  /* Verlässt der Browser das Vollbild von sich aus — über Escape oder über
     seine eigene Leiste —, soll der Knopf das nicht weiter behaupten. */
  $(document).on('fullscreenchange webkitfullscreenchange', function () {
    var drin = !!(document.fullscreenElement || document.webkitFullscreenElement);
    layout().vollbild = drin;
    buehneLeisteSetzen();
  });

  /* Häkchenzeilen: die ganze Zeile färben, wenn das Häkchen umgeht. */
  $(document).on('change', '.p-schalter-zeile input[type=checkbox]', function () {
    $(this).closest('.p-schalter-zeile').toggleClass('ist-an', $(this).is(':checked'));
  });
});

})(jQuery);
