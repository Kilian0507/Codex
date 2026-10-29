/* SwimSys — die Oberfläche.
 *
 * Zwei Dinge sind hier anders als im internen Bereich, und beide sind der
 * Grund für dieses Plugin.
 *
 * 1. Jede Ansicht hat eine Adresse. Der interne Bereich kennt keine:
 *    0 Treffer für location.hash, pushState und popstate. Darum gibt es
 *    dort kein Zurück, keinen verschickbaren Link und kein Lesezeichen,
 *    und der gemerkte Ort hält eine Stunde. Hier steht der Ort in der
 *    Adresszeile — #bereich/teil/auswahl —, der Browser-Zurück arbeitet,
 *    und ein Link führt jemand anderen an dieselbe Stelle.
 *
 * 2. Eine Ansicht entsteht aus Daten, nicht aus einer Kopie. Es gibt eine
 *    Tabellenfunktion, einen Dialog, eine Fehlermeldung und einen Weg,
 *    nach einer Änderung neu zu laden. Im internen Bereich gibt es 51
 *    Tabellen, 41 Dialoge, 64 native confirm() und 25 Stellen, die einen
 *    Ladeknopf per Klick simulieren.
 */
(function ($) {
  'use strict';

  var Z = window.LSV07S || {};
  var $buehne, $nav, $reiter;

  // ── Werkzeug ──────────────────────────────────────────────────────────

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* Ein Weg zum Server, eine Fehlermeldung. Wer `still` setzt, bekommt den
     Fehler selbst und zeigt ihn dort, wo er hingehört. */
  /**
   * Eine Datei hochladen — an einer Stelle.
   *
   * Eine Datei geht nicht als Feld: FormData, und jQuery darf weder
   * umwandeln noch einen Inhaltstyp setzen. Das stand vorher vier Mal
   * fast gleich da, und das „fast" war der Fehler: Dreimal hiess ein
   * Serverausfall „Keine Verbindung zum Server", beim Trainingsplan
   * „Fehlgeschlagen". Jetzt eine Fassung, dieselben Sätze wie in ajax().
   *
   * @param {string}   feld_id  Kennung des Dateifeldes.
   * @param {string}   aktion   Endpunkt ohne `lsv07s_`.
   * @param {Object}   felder   Weitere Felder.
   * @param {Function} fertig   Bekommt die Antwortdaten.
   * @param {string}   bitte    Satz, wenn keine Datei gewählt ist.
   */
  function hochladen(feld_id, aktion, felder, fertig, bitte) {
    var feld = document.getElementById(feld_id);
    if (!feld || !feld.files || !feld.files.length) {
      toast(bitte || 'Bitte eine Datei wählen.', 'fehl');
      return;
    }
    var fd = new FormData();
    fd.append('action', 'lsv07s_' + aktion);
    fd.append('nonce', Z.nonce);
    $.each(felder || {}, function (k, v) { fd.append(k, v); });
    fd.append('datei', feld.files[0]);
    $.ajax({ url: Z.ajax_url, type: 'POST', data: fd,
             processData: false, contentType: false, dataType: 'json' })
      .done(function (r) {
        if (r && r.success) { fertig(r.data || {}); return; }
        toast((r && r.data && r.data.message) || 'Das hat nicht funktioniert.', 'fehl');
      })
      .fail(function (xhr) {
        toast((xhr && xhr.responseJSON && xhr.responseJSON.data
               && xhr.responseJSON.data.message) || 'Keine Verbindung zum Server.',
              'fehl');
      });
  }

  function ajax(aktion, daten, still) {
    var d = $.extend({ action: 'lsv07s_' + aktion, nonce: Z.nonce }, daten || {});
    return $.ajax({ url: Z.ajax_url, type: 'POST', dataType: 'json', data: d })
      .then(function (r) {
        if (r && r.success) return r.data;
        var m = (r && r.data && r.data.message) || 'Das hat nicht funktioniert.';
        if (!still) toast(m, 'fehl');
        return $.Deferred().reject(m).promise();
      }, function (xhr) {
        var m = (xhr && xhr.responseJSON && xhr.responseJSON.data
                 && xhr.responseJSON.data.message) || 'Keine Verbindung zum Server.';
        if (!still) toast(m, 'fehl');
        return $.Deferred().reject(m).promise();
      });
  }

  var toastZeit = null;
  function toast(text, art) {
    var $t = $('#s-toast');
    $t.text(text)
      .removeClass('s-toast-fehl s-toast-ok')
      .addClass(art === 'fehl' ? 's-toast-fehl' : (art === 'ok' ? 's-toast-ok' : ''))
      .addClass('da');
    clearTimeout(toastZeit);
    toastZeit = setTimeout(function () { $t.removeClass('da'); }, 4000);
  }

  function dialog(o) {
    $('#s-dialog-titel').text(o.titel || '');
    $('#s-dialog-inhalt').html(o.inhalt || '');
    var f = '';
    (o.knoepfe || []).forEach(function (k) {
      f += '<button class="s-btn ' + (k.klasse || '') + '" data-rolle="' + esc(k.rolle || '') + '">'
         + esc(k.text) + '</button>';
    });
    $('#s-dialog-fuss').html(f);
    $('#s-dialog').addClass('auf');
    var $erstes = $('#s-dialog-inhalt').find('input,select,textarea').first();
    if ($erstes.length) $erstes.trigger('focus');
    dialog.wenn = o.wenn || function () {};
  }
  dialog.wenn = function () {};

  function dialogZu() { $('#s-dialog').removeClass('auf'); }

  $(document).on('click', '#s-dialog [data-zu]', dialogZu);
  $(document).on('click', '#s-dialog', function (e) { if (e.target === this) dialogZu(); });
  $(document).on('click', '#s-dialog-fuss button', function () {
    dialog.wenn($(this).data('rolle'), $(this));
  });

  /* Eine Tabelle, überall dieselbe — mit sortierbaren Spaltenköpfen.
     spalten: [{ kopf, feld, art ('text'|'zahl'|'roh'), label }] */
  function tabelle(id, spalten, zeilen) {
    var h = '<div class="s-twrap"><table class="s-tbl"'
          + (id ? ' id="' + esc(id) + '"' : '') + '><thead><tr>';
    spalten.forEach(function (sp, i) {
      h += '<th class="' + (sp.art === 'roh' ? '' : 's-sort') + '" data-sp="' + i + '">'
         + esc(sp.kopf) + '</th>';
    });
    h += '</tr></thead><tbody>';
    zeilen.forEach(function (z) {
      h += '<tr' + (z.__id ? ' data-id="' + esc(z.__id) + '"' : '') + '>';
      spalten.forEach(function (sp) {
        var wert = z[sp.feld];
        h += '<td data-label="' + esc(sp.label || sp.kopf) + '"'
           + (sp.art === 'zahl' ? ' class="s-zahl"' : '')
           + '>' + (sp.art === 'roh' ? (wert || '') : esc(wert)) + '</td>';
      });
      h += '</tr>';
    });
    return h + '</tbody></table></div>';
  }

  /* Sortiert wird im Browser: Die Mengen hier sind Mannschaften und Kader,
     nicht Protokolle über Jahre. Wo es grösser wird, sortiert der Server. */
  $(document).on('click', '.s-tbl th.s-sort', function () {
    var $th = $(this), $tbl = $th.closest('table'), i = $th.data('sp');
    var ab = $th.hasClass('auf');
    $tbl.find('th').removeClass('auf ab');
    $th.addClass(ab ? 'ab' : 'auf');
    var zeilen = $tbl.find('tbody tr').get();
    zeilen.sort(function (a, b) {
      var x = $(a).children().eq(i).text().trim();
      var y = $(b).children().eq(i).text().trim();
      var zx = parseFloat(x.replace(',', '.')), zy = parseFloat(y.replace(',', '.'));
      var r = (!isNaN(zx) && !isNaN(zy)) ? (zx - zy) : x.localeCompare(y, 'de');
      return ab ? -r : r;
    });
    $tbl.children('tbody').append(zeilen);
  });

  function de(datum) {
    if (!datum || String(datum).indexOf('0000') === 0) return '–';
    var t = String(datum).slice(0, 10).split('-');
    return t.length === 3 ? t[2] + '.' + t[1] + '.' + t[0] : '–';
  }

  function heute() { return Z.heute || new Date().toISOString().slice(0, 10); }

  function tageDazu(datum, n) {
    var d = new Date(datum + 'T12:00:00');
    d.setDate(d.getDate() + n);
    return d.toISOString().slice(0, 10);
  }

  function kopf(titel, unter, rechts) {
    return '<div class="s-kopf"><div><h1>' + esc(titel) + '</h1>'
         + (unter ? '<p class="s-sub">' + esc(unter) + '</p>' : '')
         + '</div>' + (rechts ? '<div class="s-kopf-ctl">' + rechts + '</div>' : '') + '</div>';
  }

  // ── Die Adresse ───────────────────────────────────────────────────────

  /* Der Ort als Liste: #schwimmen/anwesenheit/4/2026-10-20 →
     ['schwimmen','anwesenheit','4','2026-10-20'] */
  function ort() {
    var h = String(window.location.hash || '').replace(/^#/, '');
    var teile = h.split('/').filter(function (t) { return t !== ''; });
    if (!teile.length) return ['start', 'aufgaben'];
    if (teile.length === 1) {
      var b = Z.karte && Z.karte[teile[0]];
      teile.push(b && b.teile && b.teile.length ? b.teile[0].teil : '');
    }
    return teile;
  }

  function geheZu() {
    var neu = '#' + Array.prototype.slice.call(arguments).filter(function (t) {
      return t !== undefined && t !== null && t !== '';
    }).join('/');
    if (window.location.hash === neu) zeige();   // gleicher Ort: neu laden
    else window.location.hash = neu;             // sonst löst hashchange aus
  }

  $(window).on('hashchange', function () { zeige(); });

  // ── Navigation ────────────────────────────────────────────────────────

  function navBauen() {
    var h = '';
    Object.keys(Z.karte || {}).forEach(function (bereich) {
      h += '<button class="s-nb" data-bereich="' + esc(bereich) + '">'
         + esc(Z.karte[bereich].name) + '</button>';
    });
    $nav.html(h);
    $('#s-wer').text(Z.wer || '');
  }

  function reiterBauen(bereich, teil) {
    var b = Z.karte[bereich];
    var h = '';
    (b ? b.teile || [] : []).forEach(function (t) {
      h += '<button data-bereich="' + esc(bereich) + '" data-teil="' + esc(t.teil) + '"'
         + (t.teil === teil ? ' class="on"' : '') + '>' + esc(t.name) + '</button>';
    });
    /* Eine Reiterleiste mit einem einzigen Reiter sagt nichts — dann steht
       nur die Überschrift der Ansicht da. */
    $reiter.html(h).toggle((b && (b.teile || []).length > 1));
    $nav.find('.s-nb').removeClass('on')
      .filter('[data-bereich="' + bereich + '"]').addClass('on');
  }

  $(document).on('click', '.s-nb', function () {
    var bereich = $(this).data('bereich');
    $nav.removeClass('auf');
    $('#s-menue').attr('aria-expanded', 'false');
    var b = Z.karte[bereich];
    geheZu(bereich, b && b.teile && b.teile.length ? b.teile[0].teil : '');
  });

  $(document).on('click', '#s-reiter button', function () {
    geheZu($(this).data('bereich'), $(this).data('teil'));
  });

  $(document).on('click', '#s-menue', function () {
    var auf = $nav.toggleClass('auf').hasClass('auf');
    $(this).attr('aria-expanded', auf ? 'true' : 'false');
  });

  // ── Die Bühne ─────────────────────────────────────────────────────────

  function zeige() {
    var o = ort(), bereich = o[0], teil = o[1] || '';
    reiterBauen(bereich, teil);

    /* Sichtbar ist nur, was in der Karte steht. Eine von Hand getippte
       Adresse öffnet nichts, was das Konto nicht sehen darf — und der
       Server prüft dasselbe Recht noch einmal. */
    var b = Z.karte[bereich];
    var erlaubt = b && (b.teile || []).some(function (t) { return t.teil === teil; });
    if (!erlaubt) {
      $buehne.html(kopf('Nicht verfügbar',
        'Diese Ansicht gibt es für Ihr Konto nicht. Wählen Sie oben einen Bereich.'));
      return;
    }

    var f = ANSICHTEN[bereich + '/' + teil] || ANSICHTEN['*/' + teil];
    if (!f) {
      $buehne.html(kopf(teilName(bereich, teil))
        + '<div class="s-hinweis"><strong>Noch nicht in SwimSys gebaut.</strong> '
        + 'Dieser Teil steht im internen Bereich weiter zur Verfügung — beide Plugins '
        + 'arbeiten auf denselben Daten, es geht nichts verloren.</div>');
      return;
    }
    f(o, bereich, teil);
  }

  function teilName(bereich, teil) {
    var b = Z.karte[bereich];
    var t = b && (b.teile || []).filter(function (x) { return x.teil === teil; })[0];
    return t ? t.name : teil;
  }

  function laedt() { $buehne.html('<div class="s-laedt">Wird geladen …</div>'); }

  // ── Ansicht: Aufgaben ─────────────────────────────────────────────────

  function zeigeAufgaben() {
    laedt();
    ajax('start', {}).done(function (d) {
      var h = kopf('Meine Aufgaben',
        de(d.heute) + (d.saison ? ' · Saison ' + d.saison.name : ''));

      if (!d.aufgaben.length) {
        h += '<div class="s-nichts">Nichts zu tun. Alles erfasst, nichts offen.</div>';
      } else {
        h += '<div class="s-aufg">';
        d.aufgaben.forEach(function (a) {
          h += '<button class="s-aufg-k ist-' + esc(a.dringend) + '" data-ziel="' + esc(a.ziel) + '">'
             + '<span class="s-aufg-zahl">' + esc(a.zahl) + '</span>'
             + '<span class="s-aufg-txt"><span class="s-aufg-titel">' + esc(a.titel) + '</span>'
             + '<span class="s-aufg-text">' + esc(a.text) + '</span></span></button>';
        });
        h += '</div>';
      }
      $buehne.html(h);
    }).fail(function (m) {
      $buehne.html(kopf('Meine Aufgaben') + '<div class="s-hinweis s-hinweis-fehl">' + esc(m) + '</div>');
    });
  }

  $(document).on('click', '.s-aufg-k[data-ziel]', function () {
    window.location.hash = $(this).data('ziel');
  });

  // ── Ansicht: Mannschaften ─────────────────────────────────────────────

  function zeigeMannschaften(o, bereich) {
    var sparte = bereich;
    var gewaehlt = parseInt(o[2], 10) || 0;
    laedt();
    ajax('mannschaften', { sparte: sparte }).done(function (d) {
      var h = kopf(teilName(bereich, o[1]));
      if (!d.mannschaften.length) {
        $buehne.html(h + '<div class="s-hinweis">Ihnen ist keine '
          + esc(d.wort_gruppe) + ' zugeordnet.</div>');
        return;
      }
      h += '<div class="s-btn-reihe">';
      d.mannschaften.forEach(function (m) {
        h += '<button class="s-btn ' + (m.id === gewaehlt ? 's-btn-p' : 's-btn-b') + ' s-mann"'
           + ' data-id="' + m.id + '">' + esc(m.name) + '</button>';
      });
      h += '</div><div id="s-mann-inhalt"></div>';
      $buehne.html(h);
      if (gewaehlt) mannschaftLaden(sparte, gewaehlt, parseInt(o[3], 10) || 0);
    });
  }

  function mannschaftLaden(sparte, id, person_id) {
    var $z = $('#s-mann-inhalt');
    $z.html('<div class="s-laedt">Wird geladen …</div>');
    ajax('mannschaft', { sparte: sparte, mannschaft_id: id }).done(function (d) {
      var h = '<div class="s-karte"><div class="s-karte-hd"><h2>'
            + esc(d.mannschaft.name) + '</h2>'
            + '<span class="s-chip s-chip-grau">' + d.sportler.length + ' '
            + esc(sparte === 'schwimmen' ? 'Schwimmer' : 'Sportler') + '</span></div>'
            + '<div class="s-karte-bd">';

      if (d.slots.length) {
        h += '<div>' + d.slots.map(function (s) {
          return '<span class="s-chip s-chip-blau" style="margin:0 6px 6px 0">'
               + esc(s.tag_name + ' ' + s.spanne) + '</span>';
        }).join('') + '</div>';
      } else {
        h += '<div class="s-hinweis">Für diese Mannschaft ist in der laufenden Saison '
           + 'keine Trainingszeit eingetragen.</div>';
      }
      h += '</div><div class="s-karte-ft">'
         + '<button class="s-btn s-btn-b" data-geh="' + esc(sparte) + '/anwesenheit/' + id
         + '/auswertung">Auswertung</button>'
         + '<button class="s-btn s-btn-p" data-geh="' + esc(sparte) + '/anwesenheit/' + id
         + '">Anwesenheit</button></div></div>';

      var zurAkte = darfTeil(sparte, 'akte');
      var zeilen = d.sportler.map(function (s) {
        return {
          __id: s.id,
          name: s.name,
          jahrgang: s.jahrgang || '–',
          attest: s.attest_bis ? de(s.attest_bis) : '–',
          dsv: s.dsv_id || '–',
          tun: zurAkte
            ? '<button class="s-btn s-btn-b" data-geh="' + esc(sparte) + '/akte/' + s.id
              + '">Akte</button>'
            : ''
        };
      });
      var spalten = [
        { kopf: 'Name', feld: 'name' },
        { kopf: 'Jahrgang', feld: 'jahrgang', art: 'zahl' },
        { kopf: 'Attest bis', feld: 'attest' },
        { kopf: 'DSV-ID', feld: 'dsv' }
      ];
      if (zurAkte) spalten.push({ kopf: '', feld: 'tun', art: 'roh', label: 'Aktionen' });
      h += tabelle('s-kader', spalten, zeilen);
      $z.html(h);

      if (person_id) {
        var $tr = $('#s-kader tr[data-id="' + person_id + '"]');
        if ($tr.length) {
          $tr.addClass('ist-treffer');
          $tr[0].scrollIntoView({ block: 'center' });
        }
      }
    });
  }

  $(document).on('click', '.s-mann', function () {
    var o = ort();
    geheZu(o[0], o[1], $(this).data('id'));
  });

  $(document).on('click', '[data-geh]', function () {
    window.location.hash = '#' + $(this).data('geh');
  });

  // ── Ansicht: Anwesenheit ──────────────────────────────────────────────

  function zeigeAnwesenheit(o, bereich) {
    var sparte = bereich;
    var mann = parseInt(o[2], 10) || 0;
    var drittes = o[3] || '';
    if (drittes === 'auswertung') { zeigeAuswertung(sparte, mann); return; }
    var datum = /^\d{4}-\d{2}-\d{2}$/.test(drittes) ? drittes : heute();

    laedt();
    ajax('mannschaften', { sparte: sparte }).done(function (d) {
      if (!mann && d.mannschaften.length) mann = d.mannschaften[0].id;
      if (!d.mannschaften.length) {
        $buehne.html(kopf('Anwesenheit') + '<div class="s-hinweis">Ihnen ist keine '
          + esc(d.wort_gruppe) + ' zugeordnet.</div>');
        return;
      }
      var h = kopf('Anwesenheit', 'Sechs Zustände, Verspätung mit Grund. '
        + 'Gespeichert wird sofort.');
      h += '<div class="s-btn-reihe">';
      d.mannschaften.forEach(function (m) {
        h += '<button class="s-btn ' + (m.id === mann ? 's-btn-p' : 's-btn-b') + ' s-anw-mann"'
           + ' data-id="' + m.id + '">' + esc(m.name) + '</button>';
      });
      h += '</div>';
      h += '<div class="s-btn-reihe">'
         + '<button class="s-btn s-tag-zurueck">← Tag zurück</button>'
         + '<input type="date" class="s-ctl" id="s-anw-datum" value="' + esc(datum) + '">'
         + '<button class="s-btn s-tag-vor">Tag vor →</button>'
         + '<button class="s-btn s-heute">Heute</button>'
         + '<button class="s-btn s-btn-nein s-anw-aus">Training absagen …</button>'
         + '<button class="s-btn s-btn-b" data-geh="' + esc(sparte) + '/anwesenheit/'
         + mann + '/auswertung">Auswertung</button>'
         + '</div><div id="s-anw-inhalt"></div>';
      $buehne.html(h);
      anwLaden(sparte, mann, datum);
    });
  }

  function anwLaden(sparte, mann, datum) {
    var $z = $('#s-anw-inhalt');
    $z.html('<div class="s-laedt">Wird geladen …</div>');
    ajax('anw_tag', { sparte: sparte, mannschaft_id: mann, datum: datum }, true)
      .done(function (t) {
        var h = '<h2 class="s-h2">' + esc(t.mannschaft.name) + ' · ' + esc(t.wochentag)
              + ', ' + esc(t.datum_de) + '</h2>';

        if (!t.slot) {
          h += '<div class="s-hinweis">An diesem Wochentag hat diese Mannschaft kein '
             + 'Training — jedenfalls nicht in der Saison dieses Datums.</div>';
          $z.html(h);
          return;
        }
        h += '<div class="s-btn-reihe">'
           + '<span class="s-chip s-chip-blau">' + esc(t.slot.spanne) + '</span>'
           + '<span class="s-chip s-chip-grau">'
           + esc(String(t.slot.stunden).replace('.', ',')) + ' Stunden</span></div>';

        if (t.ausgefallen) {
          h += '<div class="s-hinweis s-hinweis-warn"><strong>Ausgefallen.</strong> '
             + esc(t.ausfall_grund || 'Ohne Grund vermerkt.')
             + ' <button class="s-btn s-btn-klein s-anw-an" style="margin-left:8px">'
             + 'Absage zurücknehmen</button></div>';
        }

        if (t.teilnehmer.some(function (p) { return p.aus_altbestand; })) {
          h += '<div class="s-hinweis">Einzelne Zustände stammen aus der älteren Erfassung '
             + 'des internen Bereichs. Dort gab es drei Zustände statt sechs — „krank", '
             + '„beurlaubt" und eine Verspätung lassen sich daraus nicht zurückgewinnen.</div>';
        }
        if (!t.darf_aendern) {
          h += '<div class="s-hinweis">Sie können diesen Tag ansehen, aber nicht ändern.</div>';
        }

        h += '<div class="s-twrap"><table class="s-tbl"><thead><tr>'
           + '<th>Name</th><th>Zustand</th><th>Verspätung</th></tr></thead><tbody>';
        t.teilnehmer.forEach(function (p) {
          h += '<tr data-person="' + p.id + '">';
          h += '<td data-label="Name">' + esc(p.name)
             + (p.aus_altbestand ? ' <span class="s-chip s-chip-alt">alt</span>' : '') + '</td>';
          h += '<td data-label="Zustand"><span class="s-zust">';
          Object.keys(t.zustaende).forEach(function (k) {
            h += '<button data-zust="' + esc(k) + '"'
               + (p.status === k ? ' class="on"' : '')
               + (t.darf_aendern ? '' : ' disabled')
               + '>' + esc(t.zustaende[k]) + '</button>';
          });
          h += '</span></td>';
          h += '<td data-label="Verspätung">'
             + (p.status === 'anwesend'
                ? '<button class="s-btn s-btn-klein s-spaet"' + (t.darf_aendern ? '' : ' disabled') + '>'
                  + (p.verspaetung ? esc(p.verspaetung) + ' Min.' : '+ Verspätung') + '</button>'
                  + (p.verspaetung_grund
                     ? ' <span class="s-chip s-chip-grau">' + esc(p.verspaetung_grund) + '</span>' : '')
                : '–')
             + '</td></tr>';
        });
        h += '</tbody></table></div>';

        /* Der Tag bleibt am Behälter hängen: Die Dialoge des Buchs
           brauchen den Kader und die Einträge, und ein zweiter Abruf
           dafür wäre dieselbe Antwort ein zweites Mal. */
        $z.data('tag', t).html(h);
        buchLaden(sparte, mann, datum, t);
      })
      .fail(function (m) {
        $z.html('<div class="s-hinweis s-hinweis-fehl">' + esc(m) + '</div>');
      });
  }

  // ── Mannschaftsbuch: Doku, Einträge, Bahnen, Springer, Trainer ────────

  /* Das Buch steht UNTER der Anwesenheit desselben Tages, nicht in einem
     eigenen Bereich: Es ist derselbe Trainingstag. Im internen Bereich
     sind das zwei Orte mit zwei Datumsfeldern, die man getrennt
     einstellen muss. */
  function buchLaden(sparte, mann, datum, tag) {
    if (!tag.slot) return;
    var $z = $('#s-anw-inhalt');
    if (!$z.length) return;
    $z.append('<div id="s-buch"><div class="s-laedt">Wird geladen …</div></div>');
    ajax('buch_tag', { sparte: sparte, mannschaft_id: mann, datum: datum }, true)
      .done(function (b) { buchZeichnen(b, tag); })
      .fail(function () { $('#s-buch').remove(); });
  }

  function buchZeichnen(b, tag) {
    var darf = tag.darf_aendern;
    var h = '';

    // Trainer des Tages
    if (b.trainer.length) {
      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Trainer an diesem Tag</h2></div>'
         + '<div class="s-karte-bd">';
      b.trainer.forEach(function (t) {
        h += '<div class="s-btn-reihe" style="margin-bottom:6px">'
           + '<strong>' + esc(t.name) + '</strong>'
           + (t.springer ? ' <span class="s-chip s-chip-blau">Springer</span>' : '')
           + (t.zustaendig ? '' : ' <span class="s-chip s-chip-grau">nicht zuständig</span>')
           + '<span class="s-zust" style="margin-left:auto">'
           + '<button data-zust="anwesend"' + (t.war_da ? ' class="on"' : '')
           + (darf ? '' : ' disabled') + ' data-trainer="' + t.id + '" data-da="1">Da</button>'
           + '<button data-zust="abwesend"'
           + (t.erfasst && !t.war_da ? ' class="on"' : '')
           + (darf ? '' : ' disabled') + ' data-trainer="' + t.id + '" data-da="0">Nicht da</button>'
           + '</span></div>';
      });
      h += '</div>';
      if (darf) {
        h += '<div class="s-karte-ft">'
           + '<button class="s-btn s-btn-b s-spr-neu">+ Springer eintragen</button></div>';
      }
      h += '</div>';
    }

    // Springer
    if (b.springer.length) {
      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Springer</h2></div>'
         + '<div class="s-karte-bd">';
      b.springer.forEach(function (s) {
        h += '<div class="s-btn-reihe" style="margin-bottom:4px"><span>' + esc(s.name) + '</span>'
           + '<span class="s-sub" style="margin-left:8px">eingetragen ' + esc(s.am) + '</span>'
           + (darf ? '<button class="s-btn s-btn-klein s-btn-nein s-spr-weg" style="margin-left:auto"'
                     + ' data-id="' + s.id + '">Austragen</button>' : '')
           + '</div>';
      });
      h += '</div></div>';
    }

    // Vermerke und Aufgaben
    h += '<div class="s-karte"><div class="s-karte-hd"><h2>Vermerke und Aufgaben</h2>'
       + (darf ? '<button class="s-btn s-btn-klein s-btn-b s-eintrag-neu">+ Neu</button>' : '')
       + '</div><div class="s-karte-bd">';
    var alle = tag.vermerke.concat(tag.aufgaben);
    if (!alle.length) {
      h += '<span class="s-sub">Nichts vermerkt.</span>';
    } else {
      alle.forEach(function (e) {
        var ist_aufgabe = tag.aufgaben.indexOf(e) >= 0;
        h += '<div class="s-btn-reihe" style="margin-bottom:6px">'
           + '<span>'
           + (ist_aufgabe
              ? '<span class="s-chip ' + (e.erledigt ? 's-chip-gruen-rand' : 's-chip-gelb')
                + '">' + (e.erledigt ? 'erledigt' : 'offen') + '</span> '
              : '<span class="s-chip s-chip-grau">Vermerk</span> ')
           + esc(e.text)
           + ' <span class="s-sub">' + esc(e.von) + '</span></span>';
        if (darf) {
          h += '<span style="margin-left:auto;display:flex;gap:6px">';
          if (ist_aufgabe) {
            h += '<button class="s-btn s-btn-klein s-aufg-haken" data-id="' + e.id + '"'
               + ' data-erledigt="' + (e.erledigt ? 0 : 1) + '">'
               + (e.erledigt ? 'Wieder offen' : 'Erledigt') + '</button>';
          }
          h += '<button class="s-btn s-btn-klein s-eintrag-aend" data-id="' + e.id + '"'
             + ' data-art="' + (ist_aufgabe ? 'aufgabe' : 'vermerk') + '"'
             + ' data-kat="' + esc(e.kategorie) + '"'
             + ' data-person="' + e.person_id + '"'
             + ' data-text="' + esc(e.text) + '">Ändern</button>'
             + '<button class="s-btn s-btn-klein s-btn-nein s-eintrag-weg" data-id="'
             + e.id + '">Löschen</button></span>';
        }
        h += '</div>';
      });
    }
    h += '</div></div>';

    // Dokumentation
    h += '<div class="s-karte"><div class="s-karte-hd"><h2>Was trainiert wurde</h2></div>'
       + '<div class="s-karte-bd">'
       + '<textarea class="s-ctl" id="s-doku" rows="4"' + (darf ? '' : ' disabled') + '>'
       + esc(b.doku) + '</textarea></div>'
       + (darf ? '<div class="s-karte-ft"><button class="s-btn s-btn-ok s-doku-save">'
                 + 'Dokumentation speichern</button></div>' : '')
       + '</div>';

    // Bahnenbelegung
    h += '<div class="s-karte"><div class="s-karte-hd"><h2>Bahnen</h2>'
       + '<span class="s-chip s-chip-grau">' + b.bahnen.length + '</span></div>'
       + '<div class="s-karte-bd" id="s-bahnen">';
    if (!b.bahnen.length) {
      h += '<span class="s-sub">Keine Belegung eingetragen.</span>';
    } else {
      b.bahnen.forEach(function (bahn) {
        var namen = (bahn.personen || []).map(function (p) {
          var tn = tag.teilnehmer.filter(function (x) { return x.id === p; })[0];
          return tn ? tn.name : ('Nr. ' + p);
        });
        h += '<div style="margin-bottom:6px"><strong>'
           + esc(bahn.name || ('Bahn ' + bahn.nr)) + '</strong>'
           + (bahn.hinweis ? ' <span class="s-chip s-chip-blau">' + esc(bahn.hinweis)
                             + '</span>' : '')
           + '<br><span class="s-sub">' + esc(namen.join(', ') || 'niemand') + '</span></div>';
      });
    }
    h += '</div>';
    if (darf) {
      h += '<div class="s-karte-ft">'
         + '<button class="s-btn s-btn-b s-bahnen-vom">Vom letzten Mal übernehmen</button>'
         + '<button class="s-btn s-btn-b s-bahnen-aend">Bahnen bearbeiten …</button></div>';
    }
    h += '</div>';

    $('#s-buch').html(h).data('buch', b);
  }

  $(document).on('click', '.s-doku-save', function () {
    var a = anwOrt();
    var $b = $(this).prop('disabled', true);
    ajax('buch_doku', { sparte: a.sparte, mannschaft_id: a.mann, datum: a.datum,
                        text: $('#s-doku').val() })
      .done(function (d) { toast(d.message, 'ok'); })
      .always(function () { $b.prop('disabled', false); });
  });

  $(document).on('click', '.s-zust button[data-trainer]', function () {
    var $b = $(this);
    if ($b.prop('disabled')) return;
    var a = anwOrt();
    ajax('buch_trainer', { sparte: a.sparte, mannschaft_id: a.mann, datum: a.datum,
                           trainer_id: $b.data('trainer'), da: $b.data('da') })
      .done(function () { anwLaden(a.sparte, a.mann, a.datum); });
  });

  /* Ein Dialog für Vermerk und Aufgabe, anlegen wie ändern. */
  function eintragDialog(o) {
    var a = anwOrt();
    var tag = ($('#s-anw-inhalt').data('tag')) || {};
    var leute = (tag.teilnehmer || []).map(function (p) {
      return '<option value="' + p.id + '"' + (p.id === o.person ? ' selected' : '') + '>'
           + esc(p.name) + '</option>';
    }).join('');
    var b = $('#s-buch').data('buch') || { arten: {}, kategorien: {} };
    var arten = Object.keys(b.arten).map(function (k) {
      return '<option value="' + k + '"' + (k === o.art ? ' selected' : '') + '>'
           + esc(b.arten[k]) + '</option>';
    }).join('');
    var kats = Object.keys(b.kategorien).map(function (k) {
      return '<option value="' + k + '"' + (k === o.kat ? ' selected' : '') + '>'
           + esc(b.kategorien[k]) + '</option>';
    }).join('');

    dialog({
      titel: o.id ? 'Eintrag ändern' : 'Vermerk oder Aufgabe',
      inhalt: '<div class="s-feld-reihe">'
            + '<div class="s-feld"><label for="s-e-art">Art</label>'
            + '<select class="s-ctl" id="s-e-art">' + arten + '</select></div>'
            + '<div class="s-feld"><label for="s-e-kat">Kategorie</label>'
            + '<select class="s-ctl" id="s-e-kat">' + kats + '</select></div></div>'
            + '<div class="s-feld"><label for="s-e-person">Für wen</label>'
            + '<select class="s-ctl" id="s-e-person"><option value="0">'
            + 'die ganze Mannschaft</option>' + leute + '</select>'
            + '<span class="s-sub">Eine Aufgabe braucht eine Person — '
            + 'sonst erledigt sie niemand.</span></div>'
            + '<div class="s-feld"><label for="s-e-text">Text</label>'
            + '<textarea class="s-ctl" id="s-e-text" rows="3">' + esc(o.text || '')
            + '</textarea></div>',
      knoepfe: [
        { text: 'Abbrechen', rolle: 'zu' },
        { text: 'Speichern', rolle: 'ok', klasse: 's-btn-ok' }
      ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        var text = $('#s-e-text').val();
        if (!String(text).replace(/\s+/g, '')) {
          toast('Bitte schreiben Sie etwas dazu.', 'fehler');
          $('#s-e-text').trigger('focus');
          return;   // der Dialog bleibt stehen, das Getippte auch
        }
        ajax('buch_eintrag', {
          sparte: a.sparte, mannschaft_id: a.mann, datum: a.datum, id: o.id || 0,
          art: $('#s-e-art').val(), kategorie: $('#s-e-kat').val(),
          person_id: $('#s-e-person').val(), text: text
        }).done(function () {
          dialogZu();
          anwLaden(a.sparte, a.mann, a.datum);
        });
      }
    });
  }

  $(document).on('click', '.s-eintrag-neu', function () {
    eintragDialog({ id: 0, art: 'vermerk', kat: 'sonstiges', person: 0, text: '' });
  });

  $(document).on('click', '.s-eintrag-aend', function () {
    var $b = $(this);
    eintragDialog({ id: $b.data('id'), art: $b.data('art'), kat: $b.data('kat'),
                    person: parseInt($b.data('person'), 10) || 0, text: $b.data('text') });
  });

  $(document).on('click', '.s-aufg-haken', function () {
    var a = anwOrt();
    ajax('buch_erledigt', { sparte: a.sparte, id: $(this).data('id'),
                            erledigt: $(this).data('erledigt') })
      .done(function (d) { toast(d.message, 'ok'); anwLaden(a.sparte, a.mann, a.datum); });
  });

  $(document).on('click', '.s-eintrag-weg', function () {
    var a = anwOrt();
    var id = $(this).data('id');
    dialog({
      titel: 'Eintrag löschen',
      inhalt: '<p>Der Eintrag wird gelöscht. Was darin stand, steht danach nur noch '
            + 'im Protokoll.</p>',
      knoepfe: [ { text: 'Abbrechen', rolle: 'zu' },
                 { text: 'Löschen', rolle: 'ok', klasse: 's-btn-r' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('buch_eintrag_weg', { sparte: a.sparte, id: id }).done(function (d) {
          dialogZu();
          toast(d.message, 'ok');
          anwLaden(a.sparte, a.mann, a.datum);
        });
      }
    });
  });

  $(document).on('click', '.s-bahnen-vom', function () {
    var a = anwOrt();
    ajax('bahnen_uebernehmen', { sparte: a.sparte, mannschaft_id: a.mann, datum: a.datum })
      .done(function (d) { toast(d.message, 'ok'); anwLaden(a.sparte, a.mann, a.datum); });
  });

  /* Bahnen bearbeiten: je Bahn eine Zeile mit Namen, Hinweis und den
     Personen zum Ankreuzen. Kein Ziehen — das braucht am Becken niemand
     und auf dem Handy funktioniert es ohnehin schlecht. */
  $(document).on('click', '.s-bahnen-aend', function () {
    var a = anwOrt();
    var tag = $('#s-anw-inhalt').data('tag') || { teilnehmer: [] };
    var b = $('#s-buch').data('buch') || { bahnen: [] };
    var bahnen = b.bahnen.length ? b.bahnen : [ { nr: 1, name: '', hinweis: '', personen: [] } ];

    function zeile(bahn, i) {
      var leute = tag.teilnehmer.map(function (p) {
        var an = (bahn.personen || []).indexOf(p.id) >= 0;
        return '<label style="display:inline-flex;gap:4px;align-items:center;margin:0 8px 4px 0">'
             + '<input type="checkbox" data-bahn="' + i + '" value="' + p.id + '"'
             + (an ? ' checked' : '') + '> ' + esc(p.name) + '</label>';
      }).join('');
      return '<div class="s-hinweis" data-zeile="' + i + '">'
           + '<div class="s-feld-reihe">'
           + '<div class="s-feld"><label>Bahn</label>'
           + '<input type="text" class="s-ctl s-b-name" data-bahn="' + i + '" value="'
           + esc(bahn.name || ('Bahn ' + (i + 1))) + '"></div>'
           + '<div class="s-feld"><label>Hinweis</label>'
           + '<input type="text" class="s-ctl s-b-hinweis" data-bahn="' + i + '" value="'
           + esc(bahn.hinweis || '') + '"></div></div>'
           + '<div>' + leute + '</div></div>';
    }

    dialog({
      titel: 'Bahnen bearbeiten',
      inhalt: '<div id="s-b-liste">'
            + bahnen.map(zeile).join('')
            + '</div><button class="s-btn s-btn-b s-b-plus">+ Bahn</button>',
      knoepfe: [ { text: 'Abbrechen', rolle: 'zu' },
                 { text: 'Speichern', rolle: 'ok', klasse: 's-btn-ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        var daten = [];
        $('#s-b-liste [data-zeile]').each(function () {
          var i = $(this).data('zeile');
          var personen = [];
          $('#s-b-liste input[type=checkbox][data-bahn="' + i + '"]:checked').each(function () {
            personen.push(parseInt($(this).val(), 10));
          });
          daten.push({
            name: $('.s-b-name[data-bahn="' + i + '"]').val(),
            hinweis: $('.s-b-hinweis[data-bahn="' + i + '"]').val(),
            personen: personen
          });
        });
        ajax('bahnen_speichern', { sparte: a.sparte, mannschaft_id: a.mann,
                                   datum: a.datum, bahnen: daten })
          .done(function (d) {
            dialogZu();
            toast(d.message, 'ok');
            anwLaden(a.sparte, a.mann, a.datum);
          });
      }
    });

    /* Eine weitere Bahn anhängen, ohne den Dialog neu zu bauen. */
    $(document).off('click.bplus').on('click.bplus', '.s-b-plus', function () {
      var i = $('#s-b-liste [data-zeile]').length;
      $('#s-b-liste').append(zeile({ nr: i + 1, name: '', hinweis: '', personen: [] }, i));
    });
  });

  $(document).on('click', '.s-spr-neu', function () {
    var a = anwOrt();
    ajax('trainer_liste', { sparte: a.sparte }, true).done(function (d) {
      var tag = $('#s-anw-inhalt').data('tag') || {};
      var moeglich = d.trainer.filter(function (t) {
        return t.aktiv && t.mannschaften.indexOf(a.mann) < 0;
      });
      if (!moeglich.length) {
        toast('Es gibt keinen Trainer, der hier einspringen könnte.', 'fehler');
        return;
      }
      dialog({
        titel: 'Springer eintragen',
        inhalt: '<p>Ein Springer übernimmt dieses Training, obwohl die Mannschaft '
              + 'nicht seine ist.</p>'
              + '<div class="s-feld"><label for="s-spr-wer">Wer springt ein</label>'
              + '<select class="s-ctl" id="s-spr-wer">'
              + moeglich.map(function (t) {
                  return '<option value="' + t.id + '">' + esc(t.name) + '</option>';
                }).join('')
              + '</select></div>',
        knoepfe: [ { text: 'Abbrechen', rolle: 'zu' },
                   { text: 'Eintragen', rolle: 'ok', klasse: 's-btn-ok' } ],
        wenn: function (rolle) {
          if (rolle !== 'ok') { dialogZu(); return; }
          ajax('springer_eintragen', { sparte: a.sparte, mannschaft_id: a.mann,
                                       datum: a.datum, trainer_id: $('#s-spr-wer').val() })
            .done(function (d2) {
              dialogZu();
              toast(d2.message, 'ok');
              anwLaden(a.sparte, a.mann, a.datum);
            });
        }
      });
    });
  });

  $(document).on('click', '.s-spr-weg', function () {
    var a = anwOrt();
    ajax('springer_austragen', { sparte: a.sparte, id: $(this).data('id') })
      .done(function (d) { toast(d.message, 'ok'); anwLaden(a.sparte, a.mann, a.datum); });
  });

  function anwOrt() {
    var o = ort();
    return {
      sparte: o[0],
      mann: parseInt($('.s-anw-mann.s-btn-p').data('id'), 10) || parseInt(o[2], 10) || 0,
      datum: $('#s-anw-datum').val() || heute()
    };
  }

  $(document).on('click', '.s-anw-mann', function () {
    var a = anwOrt();
    geheZu(a.sparte, 'anwesenheit', $(this).data('id'), a.datum);
  });

  $(document).on('change', '#s-anw-datum', function () {
    var a = anwOrt();
    geheZu(a.sparte, 'anwesenheit', a.mann, $(this).val());
  });

  $(document).on('click', '.s-tag-zurueck, .s-tag-vor, .s-heute', function () {
    var a = anwOrt();
    var neu = $(this).hasClass('s-heute') ? heute()
            : tageDazu(a.datum, $(this).hasClass('s-tag-vor') ? 1 : -1);
    geheZu(a.sparte, 'anwesenheit', a.mann, neu);
  });

  $(document).on('click', '.s-zust button', function () {
    var $b = $(this);
    if ($b.prop('disabled') || $b.hasClass('on')) return;
    var a = anwOrt();
    var person = $b.closest('tr').data('person');
    var $reihe = $b.closest('.s-zust');
    $reihe.find('button').prop('disabled', true);
    ajax('anw_setzen', {
      sparte: a.sparte, mannschaft_id: a.mann, datum: a.datum,
      person_id: person, status: $b.data('zust')
    }).done(function () {
      anwLaden(a.sparte, a.mann, a.datum);
    }).always(function () {
      $reihe.find('button').prop('disabled', false);
    });
  });

  /* Verspätung: Minuten und freiwillig ein Grund. Im Dialog und nicht in
     einem prompt() — das nimmt keine zweite Eingabe und lässt sich nicht
     beschriften. */
  $(document).on('click', '.s-spaet', function () {
    var a = anwOrt();
    var $tr = $(this).closest('tr');
    var person = $tr.data('person');
    var name = $tr.children().eq(0).text().trim();
    dialog({
      titel: 'Verspätung',
      inhalt: '<p>' + esc(name) + ' kam zu spät.</p>'
            + '<div class="s-feld"><label for="s-spaet-min">Minuten</label>'
            + '<input type="number" class="s-ctl" id="s-spaet-min" min="0" max="600" value="10"></div>'
            + '<div class="s-feld"><label for="s-spaet-grund">Grund (freiwillig)</label>'
            + '<input type="text" class="s-ctl" id="s-spaet-grund" maxlength="200" '
            + 'placeholder="z. B. Bus verpasst"></div>',
      knoepfe: [
        { text: 'Abbrechen', rolle: 'zu' },
        { text: 'Speichern', rolle: 'ok', klasse: 's-btn-ok' }
      ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('anw_setzen', {
          sparte: a.sparte, mannschaft_id: a.mann, datum: a.datum,
          person_id: person, status: 'anwesend',
          verspaetung: $('#s-spaet-min').val(),
          verspaetung_grund: $('#s-spaet-grund').val()
        }).done(function () {
          dialogZu();
          anwLaden(a.sparte, a.mann, a.datum);
        });
      }
    });
  });

  /* Absagen für einen Zeitraum — im internen Bereich geht nur ein Tag,
     und Ferien sind zwei Wochen. */
  $(document).on('click', '.s-anw-aus', function () {
    var a = anwOrt();
    dialog({
      titel: 'Training absagen',
      inhalt: '<p>Abgesagt werden alle Trainings dieser Mannschaft im Zeitraum. '
            + 'Tage ohne Training werden übersprungen.</p>'
            + '<div class="s-feld-reihe">'
            + '<div class="s-feld"><label for="s-aus-von">Von</label>'
            + '<input type="date" class="s-ctl" id="s-aus-von" value="' + esc(a.datum) + '"></div>'
            + '<div class="s-feld"><label for="s-aus-bis">Bis</label>'
            + '<input type="date" class="s-ctl" id="s-aus-bis" value="' + esc(a.datum) + '"></div>'
            + '</div>'
            + '<div class="s-feld"><label for="s-aus-grund">Grund</label>'
            + '<input type="text" class="s-ctl" id="s-aus-grund" maxlength="255" '
            + 'placeholder="z. B. Hallenschließung"></div>',
      knoepfe: [
        { text: 'Abbrechen', rolle: 'zu' },
        { text: 'Absagen', rolle: 'ok', klasse: 's-btn-r' }
      ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        var grund = $('#s-aus-grund').val();
        if (!String(grund).replace(/\s+/g, '')) {
          toast('Bitte geben Sie einen Grund an.', 'fehl');
          $('#s-aus-grund').trigger('focus');
          return;   // der Dialog bleibt stehen, das Getippte auch
        }
        ajax('anw_ausfall', {
          sparte: a.sparte, mannschaft_id: a.mann,
          von: $('#s-aus-von').val(), bis: $('#s-aus-bis').val(),
          grund: grund, an: 1
        }).done(function (d) {
          dialogZu();
          toast(d.message, 'ok');
          anwLaden(a.sparte, a.mann, a.datum);
        });
      }
    });
  });

  $(document).on('click', '.s-anw-an', function () {
    var a = anwOrt();
    ajax('anw_ausfall', {
      sparte: a.sparte, mannschaft_id: a.mann, von: a.datum, bis: a.datum, an: 0
    }).done(function (d) {
      toast(d.message, 'ok');
      anwLaden(a.sparte, a.mann, a.datum);
    });
  });

  // ── Ansicht: Auswertung ───────────────────────────────────────────────

  function zeigeAuswertung(sparte, mann) {
    laedt();
    ajax('anw_quote', { sparte: sparte, mannschaft_id: mann }).done(function (d) {
      var h = kopf('Auswertung', de(d.von) + ' bis ' + de(d.bis),
        '<button class="s-btn s-btn-b" data-geh="' + esc(sparte) + '/anwesenheit/' + mann
        + '">← Zur Anwesenheit</button>');

      if (!d.zeilen.length) {
        $buehne.html(h + '<div class="s-hinweis">Für diesen Zeitraum ist nichts erfasst.</div>');
        return;
      }
      var zeilen = d.zeilen.map(function (z) {
        var verlauf = z.monate.map(function (m) {
          return '<span class="s-chip s-chip-grau" style="margin:0 4px 4px 0">'
               + esc(m.monat.slice(5) + '. ' + String(m.quote).replace('.', ',') + ' %')
               + '</span>';
        }).join('');
        return {
          name: z.name,
          quote: String(z.quote).replace('.', ',') + ' %',
          anwesend: z.anwesend + ' / ' + z.gesamt,
          entschuldigt: z.entschuldigt,
          unentschuldigt: z.unentschuldigt,
          spaet: z.verspaetungen ? z.verspaetungen + '× (' + z.minuten_spaet + ' Min.)' : '–',
          verlauf: verlauf || '–'
        };
      });
      h += tabelle('s-quote', [
        { kopf: 'Name', feld: 'name' },
        { kopf: 'Quote', feld: 'quote', art: 'zahl' },
        { kopf: 'Anwesend', feld: 'anwesend' },
        { kopf: 'Entschuldigt', feld: 'entschuldigt', art: 'zahl' },
        { kopf: 'Unentschuldigt', feld: 'unentschuldigt', art: 'zahl' },
        { kopf: 'Zu spät', feld: 'spaet' },
        { kopf: 'Verlauf je Monat', feld: 'verlauf', art: 'roh' }
      ], zeilen);
      h += '<div class="s-hinweis">Ausgefallene Trainings zählen nicht gegen die Quote. '
         + 'Tage, an denen niemand erfasst wurde, auch nicht.</div>';
      $buehne.html(h);
    });
  }

  // ── Ansicht: Protokoll ────────────────────────────────────────────────

  function zeigeProtokoll() {
    laedt();
    ajax('protokoll', { anzahl: 100 }).done(function (d) {
      var h = kopf('Protokoll', 'Die letzten 100 Einträge. Einträge aus SwimSys sind im '
        + 'Bereich mit „swimsys" gekennzeichnet; das Protokoll selbst ist dasselbe wie im '
        + 'internen Bereich.');
      var zeilen = d.zeilen.map(function (z) {
        return {
          zeit: String(z.zeitpunkt || '').slice(0, 16).replace('T', ' '),
          wer: z.user_name || ('Konto ' + z.user_id),
          aktion: z.aktion,
          bereich: z.bereich,
          ziel: z.ziel_name || z.ziel_id || '–',
          details: z.details || ''
        };
      });
      h += tabelle('s-log', [
        { kopf: 'Zeitpunkt', feld: 'zeit' },
        { kopf: 'Wer', feld: 'wer' },
        { kopf: 'Was', feld: 'aktion' },
        { kopf: 'Bereich', feld: 'bereich' },
        { kopf: 'Ziel', feld: 'ziel' },
        { kopf: 'Einzelheiten', feld: 'details' }
      ], zeilen);
      $buehne.html(h);
    });
  }

  // ── Ansicht: Atteste ──────────────────────────────────────────────────

  /* Darf dieses Konto diesen Teil überhaupt sehen? Die Karte kommt vom
     Server; sie entscheidet, ob ein Knopf dorthin erscheint. Der Server
     prüft dasselbe Recht noch einmal. */
  function darfTeil(bereich, teil) {
    var b = Z.karte && Z.karte[bereich];
    return !!(b && (b.teile || []).some(function (t) { return t.teil === teil; }));
  }

  var STAND_CHIP = {
    gueltig: 's-chip-gruen-rand', laeuft_ab: 's-chip-gelb',
    abgelaufen: 's-chip-rot', kein: 's-chip-grau'
  };

  function zeigeAtteste(o, bereich) {
    var gewaehlt = parseInt(o[2], 10) || 0;
    laedt();
    ajax('attest_liste', { mannschaft_id: gewaehlt }).done(function (d) {
      var h = kopf('Atteste',
        'Warnung ' + d.warnung_tage + ' Tage vor Ablauf · ärztliche Atteste sind '
        + 'Gesundheitsdaten: Jeder Abruf einer Datei steht im Protokoll.');

      if (!d.mannschaften.length) {
        $buehne.html(h + '<div class="s-hinweis">Ihnen ist keine Mannschaft zugeordnet.</div>');
        return;
      }
      h += '<div class="s-btn-reihe">';
      d.mannschaften.forEach(function (m) {
        h += '<button class="s-btn ' + (m.id === gewaehlt ? 's-btn-p' : 's-btn-b') + ' s-mann"'
           + ' data-id="' + m.id + '">' + esc(m.name) + '</button>';
      });
      h += '</div>';

      if (!gewaehlt) {
        h += '<div class="s-nichts">Wählen Sie eine Mannschaft.</div>';
        $buehne.html(h + '<div id="s-att-trainer"></div>');
        trainerFristen();
        return;
      }

      var zeilen = d.zeilen.map(function (z) {
        var knoepfe = '';
        (z.dateien || []).forEach(function (f) {
          /* Angehängt ist höchstens eines; die älteren stehen daneben und
             sagen, dass sie im Archiv liegen. Ohne diesen Zusatz sehen
             drei Dateien aus wie drei gültige Atteste. */
          knoepfe += '<a class="s-btn s-btn-b s-att-datei" href="' + esc(Z.ajax_url)
                   + '?action=lsv07s_attest_datei_zeigen&id=' + f.id
                   + '&nonce=' + esc(Z.nonce) + '">' + esc(f.name)
                   + (f.aktuell === false ? ' <small>(Archiv)</small>' : '') + '</a>';
          if (d.darf_aendern) {
            knoepfe += '<button class="s-btn s-btn-r s-att-weg" data-id="' + f.id
                     + '" title="Datei löschen">×</button>';
          }
        });
        if (!knoepfe) knoepfe = '<span class="s-leer">–</span>';
        var tun = '';
        if (d.darf_aendern) {
          tun = '<button class="s-btn s-btn-b s-att-datum" data-id="' + z.id
              + '" data-bis="' + esc(z.bis || '') + '" data-name="' + esc(z.name)
              + '">Datum</button>'
              + '<button class="s-btn s-btn-b s-att-hoch" data-id="' + z.id
              + '" data-name="' + esc(z.name) + '">Datei</button>'
              + (z.kontakt_mail
                  ? '<button class="s-btn s-btn-p s-att-mail" data-id="' + z.id
                    + '" data-name="' + esc(z.name) + '">Erinnern</button>'
                  : '');
        }
        if (darfTeil(bereich, 'akte')) {
          tun += '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/akte/'
               + z.id + '">Akte</button>';
        }
        return {
          __id: z.id,
          name: z.name,
          jahrgang: z.jahrgang || '–',
          bis: z.bis_de,
          stand: '<span class="s-chip ' + (STAND_CHIP[z.stand] || 's-chip-grau') + '">'
               + esc(z.stand_name) + '</span>'
               + (z.tage !== null && z.stand === 'laeuft_ab'
                   ? ' <small>in ' + esc(z.tage) + ' Tagen</small>' : ''),
          kontakt: z.kontakt || '–',
          erinnert: z.erinnert || '–',
          dateien: knoepfe,
          tun: tun || '<span class="s-leer">–</span>'
        };
      });

      h += tabelle('s-att', [
        { kopf: 'Name', feld: 'name' },
        { kopf: 'Jahrgang', feld: 'jahrgang', art: 'zahl' },
        { kopf: 'Gültig bis', feld: 'bis' },
        { kopf: 'Stand', feld: 'stand', art: 'roh' },
        { kopf: 'Kontakt', feld: 'kontakt' },
        { kopf: 'Zuletzt erinnert', feld: 'erinnert' },
        { kopf: 'Dateien', feld: 'dateien', art: 'roh' },
        { kopf: '', feld: 'tun', art: 'roh', label: 'Aktionen' }
      ], zeilen);

      $buehne.html(h + '<div id="s-att-trainer"></div>');
      trainerFristen();
    });
  }

  /* Die Fristen der Trainer: Führungszeugnis, Rettungsschwimmabzeichen,
     Lizenz. Im internen Bereich stehen die Felder in der Datenbank, aber
     niemand sieht, wenn eines abläuft. */
  function trainerFristen() {
    ajax('attest_trainer', {}, true).done(function (d) {
      if (!d.trainer || !d.trainer.length) return;
      var offen = d.trainer.filter(function (t) {
        return (t.fristen || []).some(function (f) {
          return f.stand === 'abgelaufen' || f.stand === 'laeuft_ab';
        });
      });
      var h = '<div class="s-karte"><div class="s-karte-hd"><h2>Fristen der Trainer</h2>'
            + '<span class="s-chip ' + (offen.length ? 's-chip-rot' : 's-chip-gruen') + '">'
            + (offen.length ? offen.length + ' offen' : 'alles gültig') + '</span></div>'
            + '<div class="s-karte-bd">';
      var zeilen = d.trainer.map(function (t) {
        var z = { __id: t.id, name: t.name };
        (t.fristen || []).forEach(function (f, i) {
          z['f' + i] = '<span class="s-chip ' + (STAND_CHIP[f.stand] || 's-chip-grau') + '">'
                     + esc(f.bis_de) + '</span>';
        });
        return z;
      });
      var spalten = [ { kopf: 'Trainer', feld: 'name' } ];
      (d.trainer[0].fristen || []).forEach(function (f, i) {
        spalten.push({ kopf: f.was, feld: 'f' + i, art: 'roh' });
      });
      h += tabelle('s-att-tr', spalten, zeilen) + '</div></div>';
      $('#s-att-trainer').html(h);
    });
  }

  $(document).on('click', '.s-att-datum', function () {
    var id = $(this).data('id');
    dialog({
      titel: 'Attest: ' + $(this).data('name'),
      inhalt: '<div class="s-feld"><label>Gültig bis</label>'
            + '<input type="date" id="s-att-bis" class="s-ctl" value="' + esc($(this).data('bis')) + '"></div>'
            + '<p class="s-sub">Leer lassen heisst: kein Attest hinterlegt. Das Attest gilt '
            + 'am Ablauftag noch.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Speichern', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('attest_datum', { person_id: id, bis: $('#s-att-bis').val() })
          .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
      }
    });
  });

  $(document).on('click', '.s-att-hoch', function () {
    var id = $(this).data('id');
    dialog({
      titel: 'Attest hochladen: ' + $(this).data('name'),
      inhalt: '<div class="s-feld"><label>Datei</label>'
            + '<input type="file" class="s-ctl" id="s-att-f" accept=".pdf,.jpg,.jpeg,.png"></div>'
            + '<div class="s-feld"><label>Vermerk (freiwillig)</label>'
            + '<input type="text" class="s-ctl" id="s-att-k" maxlength="200"></div>'
            + '<p class="s-sub">PDF, JPG oder PNG, höchstens 10 MB. Geprüft wird der Inhalt, '
            + 'nicht die Endung. Die Datei liegt in einem gesperrten Ordner; ausgegeben wird '
            + 'sie nur über SwimSys, und jeder Abruf steht im Protokoll.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Hochladen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        hochladen('s-att-f', 'attest_datei',
          { person_id: id, kommentar: $('#s-att-k').val() || '' },
          function (a) {
            dialogZu();
            toast(a.message || 'Abgelegt.', 'ok');
            zeige();
          });
      }
    });
  });

  $(document).on('click', '.s-att-weg', function () {
    var id = $(this).data('id');
    dialog({
      titel: 'Datei löschen',
      inhalt: '<p>Die Datei wird aus dem Ordner und aus der Liste entfernt. '
            + 'Das lässt sich nicht zurücknehmen.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Löschen', klasse: 's-btn-r', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('attest_datei_weg', { id: id })
          .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
      }
    });
  });

  $(document).on('click', '.s-att-mail', function () {
    var id = $(this).data('id');
    dialog({
      titel: 'Erinnerung senden',
      inhalt: '<p>An die erste Kontaktperson von <strong>' + esc($(this).data('name'))
            + '</strong> geht eine Erinnerung. Betreff und Text stehen in den '
            + 'Einstellungen — es soll nicht zwei verschiedene Erinnerungen desselben '
            + 'Vereins geben.</p>'
            + '<p class="s-sub">In den nächsten 14 Tagen wird dieselbe Person nicht '
            + 'erneut erinnert.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Senden', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('attest_erinnern', { person_id: id })
          .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
      }
    });
  });

  // ── Ansicht: Akte ─────────────────────────────────────────────────────

  /* Alles zu einer Person an einer Stelle. Im internen Bereich steht
     dasselbe über sieben Reiter verteilt, und niemand sieht den
     Zusammenhang. Das ist auch der Grund, warum das Öffnen im Protokoll
     steht: Eine Zusammenstellung über eine Person ist mehr als die Summe
     ihrer Teile. */
  function zeigeAkte(o, bereich) {
    var person = parseInt(o[2], 10) || 0;
    if (!person) {
      $buehne.html(kopf('Akte', 'Alles zu einer Person an einer Stelle')
        + '<div class="s-hinweis">Wählen Sie eine Person — über die Suche oben, '
        + 'über die Mannschaftsliste oder über die Attestliste.</div>');
      return;
    }
    laedt();
    ajax('akte', { sparte: bereich, person_id: person, von: o[3] || '', bis: o[4] || '' })
      .done(function (a) { akteZeichnen(a, bereich); })
      .fail(function (m) {
        $buehne.html(kopf('Akte') + '<div class="s-hinweis">' + esc(m) + '</div>');
      });
  }

  function akteZeichnen(a, bereich) {
    var p = a.person;
    var h = kopf(p.name,
      (p.jahrgang ? 'Jahrgang ' + p.jahrgang + ' · ' : '')
      + (p.dsv_id ? 'DSV-ID ' + p.dsv_id + ' · ' : '')
      + (p.aktiv ? 'aktiv' : 'stillgelegt')
      + ' · Zeitraum ' + de(a.von) + ' – ' + de(a.bis),
      '<button class="s-btn s-btn-b s-akte-zeit">Zeitraum</button>');

    h += '<div class="s-chips">';
    (a.mannschaften || []).forEach(function (m) {
      h += '<span class="s-chip s-chip-blau">' + esc(m.name) + '</span>';
    });
    if (a.attest) {
      h += '<span class="s-chip ' + (STAND_CHIP[a.attest.stand] || 's-chip-grau') + '">'
         + 'Attest ' + esc(a.attest.stand_name)
         + (a.attest.bis_de && a.attest.bis_de !== '–' ? ' bis ' + esc(a.attest.bis_de) : '')
         + '</span>';
    }
    h += '</div>';

    /* Anwesenheit */
    h += '<div class="s-karte"><div class="s-karte-hd"><h2>Anwesenheit</h2></div>'
       + '<div class="s-karte-bd">';
    if (!(a.anwesenheit || []).length) {
      h += '<div class="s-nichts">Im Zeitraum ist kein Training erfasst.</div>';
    } else {
      h += tabelle('s-akte-anw', [
        { kopf: 'Mannschaft', feld: 'mannschaft' },
        { kopf: 'Anwesend', feld: 'anwesend', art: 'zahl' },
        { kopf: 'Entschuldigt', feld: 'entschuldigt', art: 'zahl' },
        { kopf: 'Unentschuldigt', feld: 'unentschuldigt', art: 'zahl' },
        { kopf: 'Verspätungen', feld: 'versp', art: 'zahl' },
        { kopf: 'Quote', feld: 'quote', art: 'roh' }
      ], a.anwesenheit.map(function (z) {
        return {
          mannschaft: z.mannschaft,
          anwesend: z.anwesend, entschuldigt: z.entschuldigt,
          unentschuldigt: z.unentschuldigt,
          versp: z.verspaetungen + (z.minuten_spaet ? ' (' + z.minuten_spaet + ' min)' : ''),
          quote: '<strong>' + esc(z.quote) + ' %</strong> <small>von ' + esc(z.gesamt)
               + '</small>'
        };
      }));
      /* Der Verlauf über die Monate: Eine Quote von 80 % sagt nichts
         darüber, ob es besser oder schlechter wird. */
      a.anwesenheit.forEach(function (z) {
        if (!(z.monate || []).length) return;
        h += '<div class="s-trend">';
        z.monate.forEach(function (m) {
          h += '<span class="s-trend-b" title="' + esc(m.monat + ': ' + m.quote + ' % von '
             + m.gesamt) + '"><i style="height:' + Math.max(2, Math.round(m.quote * 0.4))
             + 'px"></i><small>' + esc(String(m.monat).slice(5)) + '</small></span>';
        });
        h += '</div>';
      });
    }
    h += '</div></div>';

    h += akteBlock('Einträge aus dem Mannschaftsbuch', a.buch, [
      { kopf: 'Datum', feld: 'datum_de' },
      { kopf: 'Art', feld: 'art_name' },
      { kopf: 'Text', feld: 'text' },
      { kopf: 'Stand', feld: 'stand' }
    ], function (z) {
      return { datum_de: z.datum_de, art_name: z.art_name, text: z.text,
               stand: z.art === 'aufgabe' ? (z.erledigt ? 'erledigt' : 'offen') : '–' };
    }, 'Im Zeitraum steht nichts im Mannschaftsbuch.');

    h += akteBlock('Wettkämpfe', a.wettkampf, [
      { kopf: 'Datum', feld: 'datum_de' },
      { kopf: 'Wettkampf', feld: 'wettkampf' },
      { kopf: 'Ort', feld: 'ort' },
      { kopf: 'Strecke', feld: 'strecke' },
      { kopf: 'Meldezeit', feld: 'meldezeit' }
    ], null, 'Im Zeitraum keine Meldung.');

    h += akteBlock('Bestzeiten', a.bestzeiten, [
      { kopf: 'Strecke', feld: 'strecke' },
      { kopf: 'Zeit', feld: 'zeit' }
    ], null, 'Keine Bestzeiten erfasst.');

    h += akteBlock('Reflexionsbögen', a.reflexion, [
      { kopf: 'Stand', feld: 'status' },
      { kopf: 'Abgegeben', feld: 'am' }
    ], null, 'Kein Bogen vorhanden.');

    if ((a.kontakte || []).length) {
      h += akteBlock('Kontaktpersonen', a.kontakte, [
        { kopf: 'Name', feld: 'name' },
        { kopf: 'E-Mail', feld: 'email' },
        { kopf: 'Telefon', feld: 'telefon' }
      ], null, '');
    }

    if (a.notiz) {
      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Vermerk</h2></div>'
         + '<div class="s-karte-bd"><p>' + esc(a.notiz) + '</p></div></div>';
    }

    h += '<div class="s-btn-reihe">'
       + '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/mannschaft'
       + ((a.mannschaften || []).length ? '/' + a.mannschaften[0].id + '/' + p.id : '')
       + '">Zur Mannschaft</button></div>';

    $buehne.html(h);
    $buehne.data('akte', { person: p.id, von: a.von, bis: a.bis, bereich: bereich });
  }

  function akteBlock(titel, zeilen, spalten, umbau, leer) {
    var h = '<div class="s-karte"><div class="s-karte-hd"><h2>' + esc(titel) + '</h2>'
          + '<span class="s-chip s-chip-grau">' + ((zeilen || []).length) + '</span></div>'
          + '<div class="s-karte-bd">';
    if (!(zeilen || []).length) {
      h += '<div class="s-nichts">' + esc(leer || 'Nichts vorhanden.') + '</div>';
    } else {
      h += tabelle('', spalten, (zeilen || []).map(umbau || function (z) { return z; }));
    }
    return h + '</div></div>';
  }

  $(document).on('click', '.s-akte-zeit', function () {
    var st = $buehne.data('akte') || {};
    dialog({
      titel: 'Zeitraum',
      inhalt: '<div class="s-feld"><label>Von</label>'
            + '<input type="date" class="s-ctl" id="s-akte-von" value="' + esc(st.von || '') + '"></div>'
            + '<div class="s-feld"><label>Bis</label>'
            + '<input type="date" class="s-ctl" id="s-akte-bis" value="' + esc(st.bis || '') + '"></div>'
            + '<p class="s-sub">Ohne Angabe gilt die laufende Saison.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Anzeigen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        dialogZu();
        geheZu(st.bereich, 'akte', st.person,
          $('#s-akte-von').val() || '', $('#s-akte-bis').val() || '');
      }
    });
  });

  // ── Ansicht: Wettkämpfe ───────────────────────────────────────────────

  function zeigeWettkampf(o, bereich) {
    var id = parseInt(o[2], 10) || 0;
    if (id) { wettkampfLaden(bereich, id, o[3] || '', parseInt(o[4], 10) || 0); return; }

    /* Ohne Jahr alle: Die Liste bleibt kurz, weil nur Wettkämpfe der
       eigenen Mannschaften darin stehen. */
    laedt();
    ajax('wk_liste', { jahr: 0 }).done(function (d) {
      var h = kopf('Wettkämpfe', 'Freigegeben heißt: auf der öffentlichen Seite sichtbar.',
        d.darf_anlegen ? '<button class="s-btn s-btn-p s-wk-neu">Neuer Wettkampf</button>' : '');

      if (!d.wettkaempfe.length) {
        h += '<div class="s-nichts">Für Ihre Mannschaften ist kein Wettkampf eingetragen.</div>';
        $buehne.html(h).data('wk', d);
        return;
      }

      h += tabelle('s-wk', [
        { kopf: 'Datum', feld: 'datum' },
        { kopf: 'Wettkampf', feld: 'name' },
        { kopf: 'Ort', feld: 'ort' },
        { kopf: 'Mannschaften', feld: 'mann' },
        { kopf: 'Tage', feld: 'tage', art: 'zahl' },
        { kopf: 'Freigabe', feld: 'frei', art: 'roh' },
        { kopf: '', feld: 'tun', art: 'roh', label: 'Aktionen' }
      ], d.wettkaempfe.map(function (w) {
        var frei = w.freigegeben
          ? '<span class="s-chip s-chip-gruen-rand">Freigegeben</span>'
          : (w.freigabe_moeglich
              ? '<span class="s-chip s-chip-gelb">Noch nicht freigegeben</span>'
              : '<span class="s-chip s-chip-grau">Ausschreibung fehlt</span>');
        var tun = '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/wettkampf/'
                + w.id + '">Öffnen</button>';
        return {
          __id: w.id,
          datum: w.spanne_de,
          name: w.name,
          ort: w.ort,
          mann: w.mannschaften.map(function (m) { return m.name; }).join(', '),
          tage: w.tage.length,
          frei: frei,
          tun: tun
        };
      }));
      $buehne.html(h).data('wk', d);
    });
  }

  function wettkampfLaden(bereich, id, was, mann) {
    laedt();
    ajax('wk', { id: id }).done(function (w) {
      if (was === 'statistik') { wkStatistik(bereich, w, mann); return; }
      if (was === 'anwesenheit') { wkAnwesenheit(bereich, w, mann, parseInt(ort()[5], 10) || 0); return; }

      var h = kopf(w.name, w.ort + ' · ' + w.spanne_de,
        '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/wettkampf">Zurück</button>');

      h += '<div class="s-chips">';
      w.mannschaften.forEach(function (m) {
        h += '<span class="s-chip s-chip-blau">' + esc(m.name) + '</span>';
      });
      h += (w.freigegeben
        ? '<span class="s-chip s-chip-gruen-rand">Freigegeben'
          + (w.freigegeben_von ? ' von ' + esc(w.freigegeben_von) : '') + '</span>'
        : '<span class="s-chip s-chip-gelb">Nicht freigegeben</span>');
      if (w.ohne_ausschreibung) {
        h += '<span class="s-chip s-chip-grau">Ohne Ausschreibung</span>';
      }
      h += '</div>';

      if (!w.freigegeben && w.freigabe_grund !== true && typeof w.freigabe_grund === 'string') {
        h += '<div class="s-hinweis">' + esc(w.freigabe_grund) + '</div>';
      }

      /* Dokumente */
      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Dokumente</h2></div>'
         + '<div class="s-karte-bd"><div class="s-btn-reihe">';
      var DOK = { ausschreibung: 'Ausschreibung', meldeergebnis: 'Meldeergebnis',
                  protokoll: 'Protokoll' };
      Object.keys(DOK).forEach(function (typ) {
        var d = w.dokumente[typ];
        if (d) {
          h += '<a class="s-btn s-btn-b" href="' + esc(Z.ajax_url)
             + '?action=lsv07s_wk_dokument_zeigen&id=' + d.id + '&nonce=' + esc(Z.nonce)
             + '">' + esc(DOK[typ]) + '</a>'
             + '<button class="s-btn s-btn-r s-wk-dok-weg" data-id="' + d.id
             + '" title="' + esc(DOK[typ]) + ' löschen">×</button>';
        } else {
          h += '<button class="s-btn s-btn-b s-wk-dok" data-typ="' + esc(typ)
             + '" data-wk="' + w.id + '">' + esc(DOK[typ]) + ' hochladen</button>';
        }
      });
      h += '</div></div></div>';

      /* Tage mit dem Weg in die Anwesenheit */
      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Tage</h2>'
         + '<span class="s-chip s-chip-grau">' + w.tage.length + '</span></div>'
         + '<div class="s-karte-bd">';
      h += tabelle('s-wk-tage', [
        { kopf: 'Datum', feld: 'datum' },
        { kopf: 'Tag', feld: 'tag' },
        { kopf: 'Abschnitte', feld: 'abschnitte', art: 'zahl' },
        { kopf: 'Anwesenheit', feld: 'tun', art: 'roh' }
      ], w.tage.map(function (t) {
        var tun = w.mannschaften.map(function (m) {
          return '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/wettkampf/'
               + w.id + '/anwesenheit/' + m.id + '/' + t.id + '">' + esc(m.name) + '</button>';
        }).join(' ');
        return { datum: t.datum_de, tag: t.wochentag,
                 abschnitte: t.abschnitte_plan, tun: tun };
      }));
      h += '</div></div>';

      if ((w.links || []).length) {
        h += '<div class="s-karte"><div class="s-karte-hd"><h2>Verweise</h2></div>'
           + '<div class="s-karte-bd"><div class="s-btn-reihe">';
        w.links.forEach(function (l) {
          h += '<a class="s-btn s-btn-b" href="' + esc(l.url) + '" target="_blank"'
             + ' rel="noopener noreferrer">' + esc(l.titel) + '</a>';
        });
        h += '</div></div></div>';
      }

      h += '<div class="s-btn-reihe">'
         + '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/wettkampf/' + w.id
         + '/statistik">Statistik</button>'
         + '<button class="s-btn s-btn-b s-wk-aendern">Ändern</button>'
         + (w.freigegeben
             ? '<button class="s-btn s-btn-r s-wk-frei" data-an="0">Freigabe zurückziehen</button>'
             : '<button class="s-btn s-btn-ok s-wk-frei" data-an="1">Freigeben</button>')
         + '<button class="s-btn s-btn-r s-wk-weg">Löschen</button></div>';

      $buehne.html(h).data('wkd', w);
    }).fail(function (m) {
      $buehne.html(kopf('Wettkampf') + '<div class="s-hinweis">' + esc(m) + '</div>');
    });
  }

  function wkDialog(w) {
    var d = w || { mannschaften: [], tage: [], name: '', ort: '',
                   datum_von: heute(), datum_bis: heute(), ohne_ausschreibung: false };
    var liste = ($buehne.data('wk') || {}).mannschaften
             || (($buehne.data('wkd') || {}).mannschaften || []);
    var gewaehlt = (d.mannschaften || []).map(function (m) { return m.id; });

    var h = '<div class="s-feld"><label>Name</label>'
          + '<input type="text" class="s-ctl" id="s-wk-name" maxlength="200" value="' + esc(d.name) + '"></div>'
          + '<div class="s-feld"><label>Ort</label>'
          + '<input type="text" class="s-ctl" id="s-wk-ort" maxlength="200" value="' + esc(d.ort) + '"></div>'
          + '<div class="s-feld"><label>Von</label>'
          + '<input type="date" class="s-ctl" id="s-wk-von" value="' + esc(d.datum_von) + '"></div>'
          + '<div class="s-feld"><label>Bis</label>'
          + '<input type="date" class="s-ctl" id="s-wk-bis" value="' + esc(d.datum_bis) + '"></div>'
          + '<div class="s-feld"><label>Mannschaften</label><div id="s-wk-mann">';
    liste.forEach(function (m) {
      h += '<label class="s-haken"><input type="checkbox" value="' + m.id + '"'
         + (gewaehlt.indexOf(m.id) >= 0 ? ' checked' : '') + '> ' + esc(m.name) + '</label>';
    });
    h += '</div></div>';
    if ((d.tage || []).length) {
      h += '<div class="s-feld"><label>Abschnitte je Tag</label>'
         + '<div id="s-wk-tage-f">';
      d.tage.forEach(function (t) {
        h += '<label class="s-haken"><span style="min-width:120px;display:inline-block">'
           + esc(t.datum_de) + '</span>'
           + '<input type="number" min="1" max="20" data-datum="' + esc(t.datum) + '" value="'
           + esc(t.abschnitte_plan) + '" style="width:70px"></label>';
      });
      h += '</div></div>';
    }
    h += '<label class="s-haken"><input type="checkbox" id="s-wk-ohne"'
       + (d.ohne_ausschreibung ? ' checked' : '')
       + '> Zu diesem Wettkampf gibt es keine Ausschreibung</label>'
       + '<p class="s-sub">Freigegeben werden kann nur, was eine Ausschreibung hat — oder '
       + 'wo dieser Haken sagt, dass es keine gibt. Ändert sich Name, Ort oder Zeitraum '
       + 'eines freigegebenen Wettkampfs, wird die Freigabe zurückgezogen.</p>';

    dialog({
      titel: d.id ? 'Wettkampf ändern' : 'Neuer Wettkampf',
      inhalt: h,
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Speichern', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        var mann = [];
        $('#s-wk-mann input:checked').each(function () {
          mann.push(parseInt($(this).val(), 10));
        });
        var tage = [];
        $('#s-wk-tage-f input').each(function () {
          tage.push({ datum: $(this).data('datum'),
                      abschnitte_plan: parseInt($(this).val(), 10) || 1 });
        });
        ajax('wk_speichern', {
          id: d.id || 0,
          name: $('#s-wk-name').val(), ort: $('#s-wk-ort').val(),
          datum_von: $('#s-wk-von').val(), datum_bis: $('#s-wk-bis').val(),
          mannschaften: mann,
          tage: JSON.stringify(tage),
          ohne_ausschreibung: $('#s-wk-ohne').is(':checked') ? 1 : 0
        }).done(function (r) {
          dialogZu();
          toast(r.message, 'ok');
          if (d.id) zeige();
          else geheZu(ort()[0], 'wettkampf', r.id);
        });
      }
    });
  }

  $(document).on('click', '.s-wk-neu', function () { wkDialog(null); });
  $(document).on('click', '.s-wk-aendern', function () { wkDialog($buehne.data('wkd')); });

  $(document).on('click', '.s-wk-frei', function () {
    var an = String($(this).data('an')) === '1';
    var w = $buehne.data('wkd') || {};
    ajax('wk_freigeben', { id: w.id, an: an ? 1 : 0 })
      .done(function (r) { toast(r.message, 'ok'); zeige(); });
  });

  $(document).on('click', '.s-wk-weg', function () {
    var w = $buehne.data('wkd') || {};
    dialog({
      titel: 'Wettkampf löschen',
      inhalt: '<p>Mit dem Wettkampf gehen seine Tage, die erfasste Anwesenheit und die '
            + 'Meldungen dazu. Steht er in einer Abrechnung, die nicht mehr offen ist, '
            + 'wird das Löschen abgewiesen — eine eingereichte Abrechnung ist ein '
            + 'Buchungsbeleg.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Löschen', klasse: 's-btn-r', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('wk_loeschen', { id: w.id }).done(function (r) {
          dialogZu();
          toast(r.message, 'ok');
          geheZu(ort()[0], 'wettkampf');
        });
      }
    });
  });

  $(document).on('click', '.s-wk-dok', function () {
    var typ = $(this).data('typ'), wk = $(this).data('wk');
    dialog({
      titel: 'Dokument hochladen',
      inhalt: '<div class="s-feld"><label>PDF</label>'
            + '<input type="file" class="s-ctl" id="s-wk-f" accept=".pdf"></div>'
            + '<p class="s-sub">Nur PDF, höchstens 10 MB. Geprüft wird der Inhalt, nicht '
            + 'die Endung. Je Art genau ein Dokument — ein zweites ersetzt das erste. '
            + 'Bei einem freigegebenen Wettkampf sind die Dokumente öffentlich.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Hochladen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        hochladen('s-wk-f', 'wk_dokument', { wettkampf_id: wk, typ: typ },
          function (a) {
            dialogZu();
            toast(a.message || 'Abgelegt.', 'ok');
            zeige();
          });
      }
    });
  });

  $(document).on('click', '.s-wk-dok-weg', function () {
    var id = $(this).data('id');
    ajax('wk_dokument_weg', { id: id })
      .done(function (r) { toast(r.message || 'Gelöscht.', 'ok'); zeige(); });
  });

  /* Anwesenheit an einem Wettkampftag */
  function wkAnwesenheit(bereich, w, mann, tag) {
    laedt();
    ajax('wk_anw_tag', { tag_id: tag, mannschaft_id: mann }).done(function (t) {
      var h = kopf(t.tag.wettkampf + ' · ' + t.tag.datum_de,
        t.tag.ort + ' · ' + (t.mannschaft ? t.mannschaft.name : '')
        + ' · ' + t.tag.abschnitte_plan + ' Abschnitt(e) geplant',
        '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/wettkampf/'
        + t.tag.wettkampf_id + '">Zurück</button>');

      if (!t.tag.vorbei) {
        h += '<div class="s-hinweis">Dieser Wettkampftag liegt in der Zukunft — '
           + 'Anwesenheit lässt sich erst danach erfassen.</div>';
      } else if (!t.darf_aendern) {
        h += '<div class="s-hinweis">Sie können diesen Tag ansehen, aber nicht ändern.</div>';
      }

      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Schwimmer</h2></div>'
         + '<div class="s-karte-bd"><div class="s-twrap"><table class="s-tbl">'
         + '<thead><tr><th>Name</th><th>Zustand</th></tr></thead><tbody>';
      t.sportler.forEach(function (p) {
        h += '<tr data-person="' + p.id + '"><td data-label="Name">' + esc(p.name) + '</td>'
           + '<td data-label="Zustand"><span class="s-zust">';
        Object.keys(t.zustaende).forEach(function (k) {
          h += '<button class="s-wkz' + (p.status === k ? ' on' : '') + '"'
             + ' data-typ="schwimmer" data-id="' + p.id + '" data-zust="' + esc(k) + '"'
             + (t.darf_aendern ? '' : ' disabled') + '>' + esc(t.zustaende[k]) + '</button>';
        });
        h += '</span></td></tr>';
      });
      h += '</tbody></table></div></div></div>';

      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Trainer</h2>'
         + '<span class="s-chip s-chip-grau">' + t.tag.pauschale.toFixed(2).replace('.', ',')
         + ' € je Abschnitt</span></div>'
         + '<div class="s-karte-bd"><div class="s-twrap"><table class="s-tbl">'
         + '<thead><tr><th>Name</th><th>Zustand</th><th>Abschnitte</th><th>Betrag</th>'
         + '</tr></thead><tbody>';
      t.trainer.forEach(function (tr) {
        h += '<tr><td data-label="Name">' + esc(tr.name) + '</td>'
           + '<td data-label="Zustand"><span class="s-zust">';
        Object.keys(t.zustaende).forEach(function (k) {
          h += '<button class="s-wkz' + (tr.status === k ? ' on' : '') + '"'
             + ' data-typ="trainer" data-id="' + tr.id + '" data-zust="' + esc(k) + '"'
             + (t.darf_aendern ? '' : ' disabled') + '>' + esc(t.zustaende[k]) + '</button>';
        });
        h += '</span></td>'
           + '<td data-label="Abschnitte">'
           + (tr.status === 'anwesend'
               ? '<button class="s-btn s-btn-klein s-wk-absch" data-id="' + tr.id
                 + '" data-wert="' + tr.abschnitte + '"'
                 + (t.darf_aendern ? '' : ' disabled') + '>' + tr.abschnitte + '</button>'
               : '–')
           + '</td><td data-label="Betrag" class="s-zahl">'
           + (tr.status === 'anwesend'
               ? esc(tr.betrag.toFixed(2).replace('.', ',')) + ' €' : '–')
           + '</td></tr>';
      });
      h += '</tbody></table></div>'
         + '<p class="s-sub">Ein anwesender Trainer bekommt den Betrag in seiner '
         + 'Abrechnung des Quartals — aber nur, solange sie offen ist. Ist sie '
         + 'eingereicht oder genehmigt, bleibt sie unverändert, und es wird gesagt.</p>'
         + '</div></div>';

      $buehne.html(h).data('wkanw', { tag: tag, mann: mann });
    }).fail(function (m) {
      $buehne.html(kopf('Wettkampf') + '<div class="s-hinweis">' + esc(m) + '</div>');
    });
  }

  $(document).on('click', '.s-wkz', function () {
    var st = $buehne.data('wkanw') || {};
    var $b = $(this);
    ajax('wk_anw_setzen', { tag_id: st.tag, mannschaft_id: st.mann,
      typ: $b.data('typ'), teilnehmer_id: $b.data('id'), status: $b.data('zust') })
      .done(function (r) { toast(r.message, 'ok'); zeige(); });
  });

  $(document).on('click', '.s-wk-absch', function () {
    var st = $buehne.data('wkanw') || {};
    var id = $(this).data('id'), wert = $(this).data('wert');
    dialog({
      titel: 'Begleitete Abschnitte',
      inhalt: '<div class="s-feld"><label>Abschnitte</label>'
            + '<input type="number" class="s-ctl" id="s-wk-a" min="1" max="20" value="' + esc(wert)
            + '"></div>'
            + '<p class="s-sub">Voreingestellt ist die geplante Abschnittszahl des Tages. '
            + 'Der Betrag in der Abrechnung zieht nach.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Speichern', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('wk_anw_abschnitte', { tag_id: st.tag, mannschaft_id: st.mann,
          trainer_id: id, abschnitte: $('#s-wk-a').val() })
          .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
      }
    });
  });

  function wkStatistik(bereich, w, mann) {
    var gewaehlt = mann || (w.mannschaften[0] || {}).id || 0;
    laedt();
    ajax('wk_statistik', { mannschaft_id: gewaehlt }).done(function (d) {
      var h = kopf('Wettkampf-Statistik: ' + w.name,
        'Zeitraum ' + de(d.von) + ' – ' + de(d.bis),
        '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/wettkampf/' + w.id
        + '">Zurück</button>');
      h += '<div class="s-btn-reihe">';
      w.mannschaften.forEach(function (m) {
        h += '<button class="s-btn ' + (m.id === gewaehlt ? 's-btn-p' : 's-btn-b')
           + '" data-geh="' + esc(bereich) + '/wettkampf/' + w.id + '/statistik/'
           + m.id + '">' + esc(m.name) + '</button>';
      });
      h += '</div>';
      if (!(d.zeilen || []).length) {
        h += '<div class="s-nichts">Im Zeitraum ist kein Wettkampftag erfasst.</div>';
      } else {
        h += tabelle('s-wk-st', [
          { kopf: 'Name', feld: 'name' },
          { kopf: 'Anwesend', feld: 'anwesend', art: 'zahl' },
          { kopf: 'Abwesend', feld: 'abwesend', art: 'zahl' },
          { kopf: 'Entschuldigt', feld: 'entschuldigt', art: 'zahl' },
          { kopf: 'Quote', feld: 'quote', art: 'roh' }
        ], d.zeilen.map(function (z) {
          return { name: z.name, anwesend: z.anwesend, abwesend: z.abwesend,
                   entschuldigt: z.entschuldigt,
                   quote: '<strong>' + z.quote + ' %</strong> <small>von ' + z.gesamt
                        + '</small>' };
        }));
      }
      $buehne.html(h);
    });
  }

  // ── Ansicht: Meldungen ────────────────────────────────────────────────

  function zeigeMeldung(o, bereich) {
    var id = parseInt(o[2], 10) || 0;
    if (id) { meldungLaden(bereich, id); return; }
    laedt();
    ajax('meld_liste', {}).done(function (d) {
      var h = kopf('Meldungen', 'Je Wettkampf und Mannschaft eine Meldeliste.',
        d.darf_aendern
          ? '<button class="s-btn s-btn-p s-meld-neu">Neue Meldung</button>' : '');
      if (!d.meldungen.length) {
        h += '<div class="s-nichts">Es ist keine Meldung angelegt.</div>';
      } else {
        h += tabelle('s-meld', [
          { kopf: 'Datum', feld: 'datum' },
          { kopf: 'Wettkampf', feld: 'wettkampf' },
          { kopf: 'Mannschaft', feld: 'mannschaft' },
          { kopf: 'Starts', feld: 'starts', art: 'zahl' },
          { kopf: 'Geändert', feld: 'geaendert' },
          { kopf: '', feld: 'tun', art: 'roh', label: 'Aktionen' }
        ], d.meldungen.map(function (m) {
          return { __id: m.id, datum: m.datum_de, wettkampf: m.wettkampf,
                   mannschaft: m.mannschaft, starts: m.starts,
                   geaendert: m.geaendert || '–',
                   tun: '<button class="s-btn s-btn-b" data-geh="' + esc(bereich)
                      + '/meldung/' + m.id + '">Öffnen</button>' };
        }));
      }
      $buehne.html(h).data('meldliste', d);
    });
  }

  $(document).on('click', '.s-meld-neu', function () {
    var d = $buehne.data('meldliste') || { wettkaempfe: [], mannschaften: [] };
    var h = '<div class="s-feld"><label>Wettkampf</label>'
          + '<select class="s-ctl" id="s-meld-wk">'
          + d.wettkaempfe.map(function (w) {
              return '<option value="' + w.id + '">' + esc(w.spanne_de + ' · ' + w.name)
                   + '</option>';
            }).join('')
          + '</select></div>'
          + '<div class="s-feld"><label>Mannschaft</label>'
          + '<select class="s-ctl" id="s-meld-mann">'
          + d.mannschaften.map(function (m) {
              return '<option value="' + m.id + '">' + esc(m.name) + '</option>';
            }).join('')
          + '</select></div>'
          + '<div class="s-feld"><label>Abschnitte</label>'
          + '<input type="number" class="s-ctl" id="s-meld-ab" min="1" max="60" value="2"></div>'
          + '<div class="s-feld"><label>Wettkampfnummern</label>'
          + '<input type="number" class="s-ctl" id="s-meld-nr" min="1" max="999" value="30"></div>'
          + '<p class="s-sub">Melden kann nur eine Mannschaft, die am Wettkampf beteiligt '
          + 'ist. Fehlt sie dort, tragen Sie sie zuerst beim Wettkampf ein.</p>';
    dialog({
      titel: 'Neue Meldung',
      inhalt: h,
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Anlegen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('meld_kopf', { wettkampf_id: $('#s-meld-wk').val(),
          mannschaft_id: $('#s-meld-mann').val(),
          abschnitte: $('#s-meld-ab').val(), nummern: $('#s-meld-nr').val() })
          .done(function (r) {
            dialogZu();
            toast(r.message, 'ok');
            geheZu(ort()[0], 'meldung', r.id);
          });
      }
    });
  });

  function meldungLaden(bereich, id) {
    laedt();
    ajax('meld', { id: id }).done(function (m) {
      var h = kopf('Meldung: ' + m.wettkampf,
        m.ort + ' · ' + m.datum_de + ' · '
        + (m.mannschaft ? m.mannschaft.name : '') + ' · '
        + m.abschnitte + ' Abschnitte, ' + m.nummern + ' Wettkampfnummern',
        '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/meldung">Zurück</button>');

      (m.offen || []).forEach(function (p) {
        h += '<div class="s-hinweis s-hinweis-warn">' + esc(p.text) + '</div>';
      });

      h += '<div class="s-twrap"><table class="s-tbl" id="s-meld-t"><thead><tr>'
         + '<th>Abschnitt</th><th>WK-Nr</th><th>Strecke</th><th>Name</th>'
         + '<th>Meldezeit</th><th></th></tr></thead><tbody></tbody></table></div>';
      h += '<div class="s-btn-reihe">'
         + (m.darf_aendern
             ? '<button class="s-btn s-btn-b s-meld-plus">Start hinzufügen</button>'
               + '<button class="s-btn s-btn-b s-meld-staffel">Staffel hinzufügen</button>'
               + '<button class="s-btn s-btn-p s-meld-save">Startliste speichern</button>'
             : '')
         + '<button class="s-btn s-btn-b s-meld-export">Als Text ausgeben</button>'
         + (m.darf_aendern
             ? '<button class="s-btn s-btn-r s-meld-weg">Meldung löschen</button>' : '')
         + '</div>';

      $buehne.html(h).data('meld', m);
      meldZeichnen();
    }).fail(function (msg) {
      $buehne.html(kopf('Meldung') + '<div class="s-hinweis">' + esc(msg) + '</div>');
    });
  }

  /* Die Startliste als Formular: Eine Meldung wird selten einmal
     getippt und nie wieder angesehen — sie wird mehrmals geändert. */
  function meldZeichnen() {
    var m = $buehne.data('meld') || {};
    var kader = m.kader || [];
    var auswahl = kader.map(function (k) {
      return '<option value="' + k.id + '">' + esc(k.name) + '</option>';
    }).join('');
    var strecken = Object.keys(m.strecken || {}).map(function (k) {
      return '<option value="' + esc(k) + '">' + esc(m.strecken[k]) + '</option>';
    }).join('');

    var h = '';
    (m.starts || []).forEach(function (s, i) {
      h += meldZeile(s, i, auswahl, strecken, m.darf_aendern, kader);
    });
    $('#s-meld-t tbody').html(h);
    /* Die Werte der Auswahlfelder erst JETZT: Vor dem Einfügen in den
       Baum gibt es die <option> noch nicht, und der Browser nimmt die
       erste — die Zeile zeigte dann eine andere Strecke und eine andere
       Person als die gespeicherte. Deshalb steht das Setzen hier, am
       Ende des Zeichnens, und nicht an jeder Aufrufstelle. */
    meldWerte();
  }

  function meldZeile(s, i, auswahl, strecken, darf, kader) {
    var aus = darf ? '' : ' disabled';
    var h = '<tr data-i="' + i + '" data-staffel="' + (s.ist_staffel ? '1' : '0') + '">';
    h += '<td data-label="Abschnitt"><input type="text" class="s-ctl s-m-ab" maxlength="20" value="'
       + esc(s.abschnitt) + '"' + aus + ' style="width:70px"></td>';
    h += '<td data-label="WK-Nr"><input type="text" class="s-ctl s-m-nr" maxlength="20" value="'
       + esc(s.wettkampf_nr) + '"' + aus + ' style="width:70px"></td>';
    if (s.ist_staffel) {
      h += '<td data-label="Strecke"><input type="text" class="s-ctl s-m-str-frei" maxlength="60"'
         + ' value="' + esc(s.strecke) + '"' + aus + '></td>';
      h += '<td data-label="Name"><span class="s-chip s-chip-blau">Staffel</span></td>';
      h += '<td data-label="Meldezeit"><span class="s-leer">–</span></td>';
    } else {
      h += '<td data-label="Strecke"><select class="s-ctl s-m-str"' + aus + '>'
         + '<option value="">–</option>' + strecken + '</select></td>';
      h += '<td data-label="Name"><select class="s-ctl s-m-id"' + aus + '>' + auswahl
         + '</select></td>';
      h += '<td data-label="Meldezeit"><input type="text" class="s-ctl s-m-zeit" maxlength="12"'
         + ' placeholder="1:02,45" value="' + esc(s.meldezeit) + '"' + aus
         + ' style="width:90px">'
         + (darf ? ' <button class="s-btn s-btn-klein s-m-bz" title="Bestzeit übernehmen">'
                   + 'Bestzeit</button>' : '')
         + '</td>';
    }
    h += '<td data-label="">' + (darf
       ? '<button class="s-btn s-btn-r s-m-weg" title="Zeile entfernen">×</button>' : '')
       + '</td></tr>';
    return h;
  }

  /* Die Auswahlfelder stehen erst nach dem Einfügen im Baum — Wert
     danach setzen, sonst wählt der Browser den ersten Eintrag. */
  $(document).on('click', '.s-meld-plus', function () {
    var m = $buehne.data('meld') || {};
    m.starts = (m.starts || []).concat([ { abschnitt: '', wettkampf_nr: '', strecke: '',
      schwimmer_id: (m.kader[0] || {}).id || 0, name: '', meldezeit: '',
      ist_staffel: false } ]);
    $buehne.data('meld', m);
    meldZeichnen();
  });

  $(document).on('click', '.s-meld-staffel', function () {
    var m = $buehne.data('meld') || {};
    m.starts = (m.starts || []).concat([ { abschnitt: '', wettkampf_nr: '', strecke: '',
      schwimmer_id: 0, name: '', meldezeit: '', ist_staffel: true } ]);
    $buehne.data('meld', m);
    meldZeichnen();
  });

  $(document).on('click', '.s-m-weg', function () {
    var i = parseInt($(this).closest('tr').data('i'), 10);
    var m = $buehne.data('meld') || {};
    meldLesen();
    m = $buehne.data('meld');
    m.starts.splice(i, 1);
    $buehne.data('meld', m);
    meldZeichnen();
  });

  /* Bestzeit übernehmen: Die Zeit steht im Nachbarreiter — abtippen ist
     die Stelle, an der sich die Ziffern vertauschen. */
  $(document).on('click', '.s-m-bz', function () {
    var $tr = $(this).closest('tr');
    var m = $buehne.data('meld') || {};
    var id = parseInt($tr.find('.s-m-id').val(), 10);
    var strecke = String($tr.find('.s-m-str').val() || '');
    var person = (m.kader || []).filter(function (k) { return k.id === id; })[0];
    if (!person || !strecke) { toast('Bitte Person und Strecke wählen.', 'fehl'); return; }
    var zeit = (person.bestzeiten || {})[strecke];
    if (!zeit) {
      toast('Für ' + person.name + ' ist auf dieser Strecke keine Bestzeit erfasst.', 'fehl');
      return;
    }
    $tr.find('.s-m-zeit').val(zeit);
    toast('Bestzeit übernommen: ' + zeit, 'ok');
  });

  function meldWerte() {
    var m = $buehne.data('meld') || {};
    (m.starts || []).forEach(function (s, i) {
      var $tr = $('#s-meld-t tr[data-i="' + i + '"]');
      if (!s.ist_staffel) {
        $tr.find('.s-m-str').val(s.strecke || '');
        $tr.find('.s-m-id').val(s.schwimmer_id || '');
      }
    });
  }

  function meldLesen() {
    var m = $buehne.data('meld') || {};
    var starts = [];
    $('#s-meld-t tbody tr').each(function () {
      var $tr = $(this);
      var staffel = String($tr.data('staffel')) === '1';
      starts.push(staffel
        ? { ist_staffel: true, abschnitt: $tr.find('.s-m-ab').val(),
            wettkampf_nr: $tr.find('.s-m-nr').val(),
            strecke: $tr.find('.s-m-str-frei').val() }
        : { ist_staffel: false, abschnitt: $tr.find('.s-m-ab').val(),
            wettkampf_nr: $tr.find('.s-m-nr').val(),
            strecke: $tr.find('.s-m-str').val(),
            schwimmer_id: parseInt($tr.find('.s-m-id').val(), 10) || 0,
            meldezeit: $tr.find('.s-m-zeit').val() });
    });
    m.starts = starts.map(function (s) {
      return $.extend({ name: '', jahrgang: 0 }, s);
    });
    $buehne.data('meld', m);
    return starts;
  }

  $(document).on('click', '.s-meld-save', function () {
    var m = $buehne.data('meld') || {};
    var starts = meldLesen();
    ajax('meld_starts', { meldung_id: m.id, starts: JSON.stringify(starts) })
      .done(function (r) { toast(r.message, 'ok'); zeige(); });
  });

  $(document).on('click', '.s-meld-weg', function () {
    var m = $buehne.data('meld') || {};
    dialog({
      titel: 'Meldung löschen',
      inhalt: '<p>Die Meldung und ihre Startliste werden entfernt.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Löschen', klasse: 's-btn-r', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('meld_loeschen', { id: m.id }).done(function (r) {
          dialogZu();
          toast(r.message, 'ok');
          geheZu(ort()[0], 'meldung');
        });
      }
    });
  });

  $(document).on('click', '.s-meld-export', function () {
    var m = $buehne.data('meld') || {};
    ajax('meld_export', { id: m.id }).done(function (d) { textFenster(d.name, d.text); });
  });

  /* Ein Textblock zum Herauskopieren. Kein Dateidownload: Der Block
     wandert meistens in eine Tabelle oder eine Mail. */
  function textFenster(titel, text, hinweis) {
    dialog({
      titel: titel,
      inhalt: '<textarea class="s-ctl" id="s-text-aus" rows="14" readonly'
            + ' style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px">' + esc(text) + '</textarea>'
            + '<p class="s-sub">' + esc(hinweis || 'Tabulatorgetrennt — das lässt '
            + 'sich in jede Tabelle einfügen.') + '</p>',
      knoepfe: [ { text: 'Schließen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Markieren', klasse: 's-btn-p', rolle: 'alles' } ],
      wenn: function (rolle) {
        if (rolle === 'alles') {
          var f = document.getElementById('s-text-aus');
          if (f) { f.focus(); f.select(); }
          return;
        }
        dialogZu();
      }
    });
  }

  // ── Ansicht: Bestzeiten ───────────────────────────────────────────────

  function zeigeBestzeit(o, bereich) {
    var gewaehlt = parseInt(o[2], 10) || 0;
    laedt();
    ajax('mannschaften', { sparte: bereich }).done(function (d) {
      if (!gewaehlt && d.mannschaften.length) gewaehlt = d.mannschaften[0].id;
      if (!d.mannschaften.length) {
        $buehne.html(kopf('Bestzeiten')
          + '<div class="s-hinweis">Ihnen ist keine Mannschaft zugeordnet.</div>');
        return;
      }
      var h = kopf('Bestzeiten',
        'Je Schwimmer und Strecke eine Zeit. Erfasst wird über einen eingefügten Block.');
      h += '<div class="s-btn-reihe">';
      d.mannschaften.forEach(function (m) {
        h += '<button class="s-btn ' + (m.id === gewaehlt ? 's-btn-p' : 's-btn-b')
           + ' s-mann" data-id="' + m.id + '">' + esc(m.name) + '</button>';
      });
      h += '</div><div id="s-bz-inhalt"></div>';
      $buehne.html(h);
      bzLaden(gewaehlt);
    });
  }

  function bzLaden(mann) {
    var $z = $('#s-bz-inhalt');
    $z.html('<div class="s-laedt">Wird geladen …</div>');
    ajax('bz_tafel', { mannschaft_id: mann }, true).done(function (d) {
      var strecken = Object.keys(d.strecken);
      var spalten = [ { kopf: 'Name', feld: 'name' },
                      { kopf: 'Jahrgang', feld: 'jahrgang', art: 'zahl' } ];
      strecken.forEach(function (k) {
        spalten.push({ kopf: k, feld: 'z_' + k, art: 'roh', label: d.strecken[k] });
      });
      var zeilen = d.zeilen.map(function (z) {
        var r = { __id: z.id, name: z.name, jahrgang: z.jahrgang || '–' };
        strecken.forEach(function (k) {
          var zeit = z.zeiten[k];
          r['z_' + k] = zeit
            ? esc(zeit) + (d.darf_loeschen
                ? ' <button class="s-btn s-btn-klein s-bz-weg" data-person="' + z.id
                  + '" data-strecke="' + esc(k) + '" title="Zeit löschen">×</button>' : '')
            : '<span class="s-leer">–</span>';
        });
        return r;
      });

      var h = '<div class="s-btn-reihe">'
            + (d.darf_importieren
                ? '<button class="s-btn s-btn-p s-bz-einfuegen" data-mann="' + mann
                  + '">Zeiten einfügen</button>' : '')
            + '<button class="s-btn s-btn-b s-bz-export" data-mann="' + mann
            + '">Als Text ausgeben</button>'
            + '</div>';
      h += tabelle('s-bz', spalten, zeilen);
      $z.html(h).data('bz', d);
    }).fail(function (m) {
      $z.html('<div class="s-hinweis">' + esc(m) + '</div>');
    });
  }

  $(document).on('click', '.s-bz-export', function () {
    ajax('bz_export', { mannschaft_id: $(this).data('mann') })
      .done(function (d) { textFenster(d.name, d.text); });
  });

  $(document).on('click', '.s-bz-weg', function () {
    var person = $(this).data('person'), strecke = $(this).data('strecke');
    ajax('bz_loeschen', { person_id: person, strecke: strecke })
      .done(function (r) { toast(r.message || 'Gelöscht.', 'ok'); zeige(); });
  });

  $(document).on('click', '.s-bz-einfuegen', function () {
    var mann = $(this).data('mann');
    dialog({
      titel: 'Zeiten einfügen',
      inhalt: '<p class="s-sub">Den Block aus der Tabelle hier einfügen. Die erste Spalte '
            + 'der Kopfzeile muss „Name" heißen, danach je Spalte eine Strecke — etwa '
            + '„100 Freistil" oder „100F".</p>'
            + '<textarea class="s-ctl" id="s-bz-text" rows="10"'
            + ' style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px">'
            + '</textarea>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Vorschau', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('bz_vorschau', { mannschaft_id: mann, text: $('#s-bz-text').val() })
          .done(function (d) { bzVorschau(mann, d); });
      }
    });
  });

  /* Die Vorschau zeigt, was das Übernehmen täte — mit der bisherigen
     Zeit daneben. Eine langsamere Zeit ist meistens ein Vertipper. */
  function bzVorschau(mann, d) {
    var h = (d.hinweis ? '<div class="s-hinweis s-hinweis-warn">' + esc(d.hinweis)
             + '</div>' : '');
    h += '<div class="s-twrap"><table class="s-tbl" id="s-bzv"><thead><tr>'
       + '<th>Aus dem Block</th><th>Person</th><th>Zeiten</th></tr></thead><tbody>';
    d.zeilen.forEach(function (z, i) {
      h += '<tr data-i="' + i + '">';
      h += '<td data-label="Aus dem Block">' + esc(z.name)
         + (z.jahrgang ? ' <small>(' + z.jahrgang + ')</small>' : '') + '</td>';
      h += '<td data-label="Person"><select class="s-ctl s-bzv-id">'
         + '<option value="0">– nicht übernehmen –</option>'
         + d.kader.map(function (k) {
             return '<option value="' + k.id + '"'
                  + (k.id === z.schwimmer_id ? ' selected' : '') + '>' + esc(k.name)
                  + '</option>';
           }).join('')
         + '</select>'
         + (z.schwimmer_id && !z.sicher
             ? ' <span class="s-chip s-chip-gelb">bitte prüfen</span>' : '')
         + (!z.schwimmer_id
             ? ' <span class="s-chip s-chip-rot">nicht zugeordnet</span>' : '')
         + '</td>';
      h += '<td data-label="Zeiten">' + z.zeiten.map(function (t) {
        return '<span class="s-chip ' + (t.langsamer ? 's-chip-gelb'
                 : (t.schneller ? 's-chip-gruen-rand' : 's-chip-grau')) + '">'
             + esc(t.strecke) + ' ' + esc(t.zeit)
             + (t.bisher ? ' <small>bisher ' + esc(t.bisher) + '</small>' : '')
             + (t.langsamer ? ' <small>langsamer</small>' : '')
             + '</span>';
      }).join(' ') + '</td></tr>';
    });
    h += '</tbody></table></div>';

    dialog({
      titel: 'Vorschau',
      inhalt: h,
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Übernehmen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        var zeilen = [];
        $('#s-bzv tbody tr').each(function () {
          var i = parseInt($(this).data('i'), 10);
          var id = parseInt($(this).find('.s-bzv-id').val(), 10) || 0;
          if (!id) return;
          var zeiten = {};
          (d.zeilen[i].zeiten || []).forEach(function (t) { zeiten[t.strecke] = t.zeit; });
          zeilen.push({ schwimmer_id: id, zeiten: zeiten });
        });
        if (!zeilen.length) { toast('Es ist keine Zeile zugeordnet.', 'fehl'); return; }
        ajax('bz_uebernehmen', { mannschaft_id: mann,
          zeilen: JSON.stringify(zeilen) })
          .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
      }
    });
  }

  // ── Ansicht: Meine Stunden ────────────────────────────────────────────

  /* SwimSys rechnet nicht ab. Hier steht, was aus der Anwesenheit folgt
     — Stunden und Abschnitte, ohne Satz und ohne Betrag. Die Rechnung
     entsteht in der Übungsleiterabrechnung aus denselben Daten. */
  function zeigeStunden(o, bereich) {
    var von = /^\d{4}-\d{2}-\d{2}$/.test(o[2] || '') ? o[2] : '';
    var bis = /^\d{4}-\d{2}-\d{2}$/.test(o[3] || '') ? o[3] : '';
    laedt();
    ajax('stunden', { sparte: 'schwimmen', von: von, bis: bis }).done(function (d) {
      var h = kopf(d.eigene ? 'Meine Stunden' : 'Stunden: ' + d.name,
        d.quartal + ' · ' + de(d.von) + ' – ' + de(d.bis),
        '<button class="s-btn s-btn-b s-std-zeit">Zeitraum</button>');

      h += '<div class="s-aufg">'
         + stundenKachel(d.summe.trainings, 'Trainings',
             d.summe.stunden.toString().replace('.', ',') + ' Stunden')
         + stundenKachel(d.summe.wettkampf_tage, 'Wettkampftage',
             d.summe.abschnitte + ' Abschnitte')
         + (d.summe.ohne_zeit
             ? stundenKachel(d.summe.ohne_zeit, 'Ohne Trainingszeit',
                 'Für diese Tage ist keine Zeit hinterlegt — die Stunden sind 0.', 'hoch')
             : '')
         + '</div>';

      h += '<div class="s-hinweis">' + esc(d.hinweis) + '</div>';

      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Trainings</h2>'
         + '<span class="s-chip s-chip-grau">' + d.trainings.length + '</span></div>'
         + '<div class="s-karte-bd">';
      if (!d.trainings.length) {
        h += '<div class="s-nichts">Im Zeitraum ist kein Training erfasst, '
           + 'bei dem Sie als anwesend stehen.</div>';
      } else {
        h += tabelle('s-std', [
          { kopf: 'Datum', feld: 'datum' },
          { kopf: 'Mannschaft', feld: 'mannschaft' },
          { kopf: 'Stunden', feld: 'stunden', art: 'zahl' },
          { kopf: 'Woher', feld: 'herkunft' },
          { kopf: 'Hinweis', feld: 'hinweis' }
        ], d.trainings.map(function (t) {
          return { datum: t.datum_de, mannschaft: t.mannschaft,
                   stunden: String(t.stunden).replace('.', ','),
                   herkunft: t.herkunft, hinweis: t.hinweis || '–' };
        }));
      }
      h += '</div></div>';

      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Wettkampftage</h2>'
         + '<span class="s-chip s-chip-grau">' + d.wettkaempfe.length + '</span></div>'
         + '<div class="s-karte-bd">';
      if (!d.wettkaempfe.length) {
        h += '<div class="s-nichts">Im Zeitraum ist kein Wettkampftag erfasst.</div>';
      } else {
        h += tabelle('s-std-wk', [
          { kopf: 'Datum', feld: 'datum' },
          { kopf: 'Wettkampf', feld: 'name' },
          { kopf: 'Abschnitte', feld: 'abschnitte', art: 'zahl' }
        ], d.wettkaempfe.map(function (w) {
          return { datum: w.datum_de, name: w.name, abschnitte: w.abschnitte };
        }));
      }
      h += '</div></div>';

      $buehne.html(h).data('std', { von: d.von, bis: d.bis, bereich: bereich });
    }).fail(function (m) {
      $buehne.html(kopf('Meine Stunden') + '<div class="s-hinweis">' + esc(m) + '</div>');
    });
  }

  function stundenKachel(zahl, titel, text, dringend) {
    return '<div class="s-aufg-k ist-' + esc(dringend || 'normal') + '" role="group">'
         + '<span class="s-aufg-zahl">' + esc(zahl) + '</span>'
         + '<span class="s-aufg-txt"><span class="s-aufg-titel">' + esc(titel) + '</span>'
         + '<span class="s-aufg-text">' + esc(text) + '</span></span></div>';
  }

  $(document).on('click', '.s-std-zeit', function () {
    var st = $buehne.data('std') || {};
    dialog({
      titel: 'Zeitraum',
      inhalt: '<div class="s-feld"><label>Von</label>'
            + '<input type="date" class="s-ctl" id="s-std-von" value="'
            + esc(st.von || '') + '"></div>'
            + '<div class="s-feld"><label>Bis</label>'
            + '<input type="date" class="s-ctl" id="s-std-bis" value="'
            + esc(st.bis || '') + '"></div>'
            + '<p class="s-sub">Ohne Angabe das laufende Quartal.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Anzeigen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        dialogZu();
        geheZu(st.bereich, 'stunden', $('#s-std-von').val() || '',
          $('#s-std-bis').val() || '');
      }
    });
  });

  function zeigeStundenUebersicht(o, bereich) {
    var quartal = /^Q[1-4]$/.test(o[2] || '') ? o[2] : '';
    var jahr = parseInt(o[3], 10) || 0;
    laedt();
    ajax('stunden_uebersicht', { sparte: 'schwimmen', quartal: quartal, jahr: jahr })
      .done(function (d) {
        var h = kopf('Stunden aller Trainer', d.name + ' · ' + de(d.von) + ' – ' + de(d.bis));
        h += '<div class="s-btn-reihe">';
        [ 'Q1', 'Q2', 'Q3', 'Q4' ].forEach(function (q) {
          h += '<button class="s-btn ' + (q === d.quartal ? 's-btn-p' : 's-btn-b')
             + '" data-geh="' + esc(bereich) + '/uebersicht/' + q + '/' + d.jahr + '">'
             + q + '</button>';
        });
        h += '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/uebersicht/'
           + d.quartal + '/' + (d.jahr - 1) + '">' + (d.jahr - 1) + '</button>'
           + '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/uebersicht/'
           + d.quartal + '/' + (d.jahr + 1) + '">' + (d.jahr + 1) + '</button></div>';

        h += '<div class="s-hinweis">Gezählt wird, was in der Anwesenheit steht — ohne '
           + 'Satz und ohne Betrag. So fällt ein fehlendes Training auf, bevor die '
           + 'Abrechnung daraus entsteht.</div>';

        h += tabelle('s-std-u', [
          { kopf: 'Trainer', feld: 'name' },
          { kopf: 'Trainings', feld: 'trainings', art: 'zahl' },
          { kopf: 'Stunden', feld: 'stunden', art: 'zahl' },
          { kopf: 'Wettkampftage', feld: 'wettkampf_tage', art: 'zahl' },
          { kopf: 'Abschnitte', feld: 'abschnitte', art: 'zahl' },
          { kopf: 'Ohne Zeit', feld: 'ohne', art: 'roh' }
        ], d.zeilen.map(function (z) {
          return { name: z.name, trainings: z.trainings,
                   stunden: String(z.stunden).replace('.', ','),
                   wettkampf_tage: z.wettkampf_tage, abschnitte: z.abschnitte,
                   ohne: z.ohne_zeit
                     ? '<span class="s-chip s-chip-gelb">' + z.ohne_zeit + '</span>'
                     : '<span class="s-leer">–</span>' };
        }));
        $buehne.html(h);
      });
  }

  // ── Ansicht: Nachrichten ──────────────────────────────────────────────

  function zeigeNachrichten(o, bereich) {
    var offen = parseInt(o[2], 10) || 0;
    laedt();
    ajax('konv_liste', {}).done(function (d) {
      var h = kopf('Nachrichten',
        d.ungelesen ? d.ungelesen + ' ungelesen' : 'Nichts Ungelesenes',
        d.darf_senden
          ? '<button class="s-btn s-btn-p s-k-neu">Neue Unterhaltung</button>' : '');

      if (!d.konversationen.length) {
        h += '<div class="s-nichts">Es gibt noch keine Unterhaltung.</div>';
        $buehne.html(h).data('kliste', d);
        return;
      }

      h += '<div class="s-k-lay"><div class="s-k-liste">';
      d.konversationen.forEach(function (k) {
        h += '<button class="s-k-e' + (k.id === offen ? ' on' : '') + '" data-id="' + k.id + '">'
           + '<span class="s-k-kopf"><span class="s-k-name">' + esc(k.name) + '</span>'
           + (k.ungelesen
               ? '<span class="s-chip ' + (k.stumm ? 's-chip-grau' : 's-chip-rot') + '">'
                 + k.ungelesen + '</span>' : '')
           + (k.stumm ? '<span class="s-chip s-chip-grau">stumm</span>' : '')
           + '</span>'
           + '<span class="s-k-letzte">'
           + (k.letzte ? esc(k.letzte.von + ': ' + k.letzte.text) : 'Noch keine Nachricht')
           + '</span>'
           + '<span class="s-k-zeit">' + esc(k.aktiv_am || '') + '</span></button>';
      });
      h += '</div><div class="s-k-inhalt" id="s-k-inhalt">'
         + '<div class="s-nichts">Wählen Sie links eine Unterhaltung.</div>'
         + '</div></div>';
      $buehne.html(h).data('kliste', d);
      if (offen) konvLaden(offen);
    });
  }

  $(document).on('click', '.s-k-e', function () {
    var o = ort();
    geheZu(o[0], o[1], $(this).data('id'));
  });

  function konvLaden(id) {
    var $z = $('#s-k-inhalt');
    $z.html('<div class="s-laedt">Wird geladen …</div>');
    ajax('konv', { id: id }, true).done(function (k) {
      var h = '<div class="s-k-titel"><h2>' + esc(k.name) + '</h2>'
            + '<div class="s-k-ctl">'
            + (k.nur_lesen
                ? '<span class="s-chip s-chip-gelb">Mitgelesen als Administration</span>' : '')
            + '<button class="s-btn s-btn-b s-k-stumm" data-an="' + (k.stumm ? '0' : '1')
            + '">' + (k.stumm ? 'Wieder laut' : 'Stumm') + '</button>'
            + (k.ist_admin && k.typ !== 'direkt'
                ? '<button class="s-btn s-btn-b s-k-ren">Umbenennen</button>'
                  + '<button class="s-btn s-btn-b s-k-teil">Teilnehmer</button>' : '')
            + (k.typ !== 'direkt'
                ? '<button class="s-btn s-btn-r s-k-weg">Verlassen</button>' : '')
            + '</div></div>';

      h += '<div class="s-chips">';
      k.teilnehmer.forEach(function (t) {
        h += '<span class="s-chip ' + (t.ist_admin ? 's-chip-blau' : 's-chip-grau') + '">'
           + esc(t.name) + (t.ist_admin ? ' ·  verwaltet' : '') + '</span>';
      });
      h += '</div>';

      h += '<div class="s-k-msgs" id="s-k-msgs">';
      if (!k.nachrichten.length) {
        h += '<div class="s-nichts">Noch keine Nachricht.</div>';
      }
      k.nachrichten.forEach(function (m) {
        if (m.system) {
          h += '<div class="s-k-sys">' + esc(m.text) + ' <small>' + esc(m.am)
             + '</small></div>';
          return;
        }
        h += '<div class="s-k-m' + (m.eigene ? ' eigen' : '')
           + (m.geloescht ? ' weg' : '') + '" data-msg="' + m.id + '">'
           + '<div class="s-k-m-kopf">' + esc(m.von) + ' · ' + esc(m.am)
           + (m.bearbeitet ? ' · bearbeitet' : '')
           + (m.dringend ? ' <span class="s-chip s-chip-rot">dringend</span>' : '')
           + '</div>';
        h += m.geloescht
          ? '<div class="s-k-m-text"><em>Diese Nachricht wurde gelöscht.</em></div>'
          : '<div class="s-k-m-text">' + esc(m.text).replace(/\n/g, '<br>') + '</div>';
        (m.anhaenge || []).forEach(function (a) {
          h += '<a class="s-btn s-btn-b s-k-anh" href="' + esc(Z.ajax_url)
             + '?action=lsv07s_konv_anhang_zeigen&id=' + a.id + '&nonce=' + esc(Z.nonce)
             + '">' + esc(a.name) + '</a>';
        });
        if (m.darf_aendern) {
          h += '<div class="s-k-m-ctl">'
             + '<button class="s-btn s-btn-klein s-k-maend">Ändern</button>'
             + '<button class="s-btn s-btn-klein s-k-manh">Anhang</button>'
             + '<button class="s-btn s-btn-klein s-k-mweg">Löschen</button></div>';
        }
        h += '</div>';
      });
      h += '</div>';

      if (k.darf_senden) {
        h += '<div class="s-k-feld">'
           + '<textarea class="s-ctl" id="s-k-text" rows="3" '
           + 'placeholder="Nachricht … (Strg+Enter sendet)"></textarea>'
           + '<div class="s-btn-reihe">'
           + '<label class="s-haken"><input type="checkbox" id="s-k-dringend"> dringend</label>'
           + '<button class="s-btn s-btn-p s-k-send">Senden</button></div></div>';
      } else if (!k.nur_lesen) {
        h += '<div class="s-hinweis">Sie können hier mitlesen, aber nicht schreiben.</div>';
      }

      $z.html(h).data('konv', k);
      var $m = $('#s-k-msgs');
      if ($m.length) $m.scrollTop($m[0].scrollHeight);

      /* Gelesen bis zur letzten: Erst JETZT, nachdem sie wirklich auf
         dem Schirm steht. */
      var letzte = k.nachrichten.length ? k.nachrichten[k.nachrichten.length - 1].id : 0;
      if (letzte && letzte > k.gelesen_bis && !k.nur_lesen) {
        ajax('konv_gelesen', { id: k.id, msg_id: letzte }, true);
      }
    }).fail(function (m) {
      $z.html('<div class="s-hinweis">' + esc(m) + '</div>');
    });
  }

  function konvJetzt() { return ($('#s-k-inhalt').data('konv') || {}); }

  $(document).on('click', '.s-k-send', function () {
    var k = konvJetzt();
    var text = $('#s-k-text').val();
    if (!String(text).trim()) { toast('Bitte etwas schreiben.', 'fehl'); return; }
    ajax('konv_senden', { id: k.id, text: text,
      dringend: $('#s-k-dringend').is(':checked') ? 1 : 0 })
      .done(function () { $('#s-k-text').val(''); konvLaden(k.id); });
  });

  $(document).on('keydown', '#s-k-text', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      $('.s-k-send').trigger('click');
    }
  });

  $(document).on('click', '.s-k-stumm', function () {
    var k = konvJetzt();
    var an = String($(this).data('an')) === '1';
    ajax('konv_stumm', { id: k.id, an: an ? 1 : 0 })
      .done(function (r) { toast(r.message, 'ok'); zeige(); });
  });

  $(document).on('click', '.s-k-ren', function () {
    var k = konvJetzt();
    dialog({
      titel: 'Unterhaltung umbenennen',
      inhalt: '<div class="s-feld"><label>Name</label>'
            + '<input type="text" class="s-ctl" id="s-k-name" maxlength="200" value="'
            + esc(k.name) + '"></div>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Speichern', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('konv_umbenennen', { id: k.id, name: $('#s-k-name').val() })
          .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
      }
    });
  });

  $(document).on('click', '.s-k-weg', function () {
    var k = konvJetzt();
    dialog({
      titel: 'Unterhaltung verlassen',
      inhalt: '<p>Sie lesen danach nicht mehr mit. Die Unterhaltung bleibt für die '
            + 'anderen stehen, und dass Sie gegangen sind, steht darin.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Verlassen', klasse: 's-btn-r', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('konv_teil_weg', { id: k.id })
          .done(function (r) {
            dialogZu();
            toast(r.message, 'ok');
            geheZu(ort()[0], 'nachrichten');
          });
      }
    });
  });

  $(document).on('click', '.s-k-teil', function () {
    var k = konvJetzt();
    ajax('konten', {}).done(function (d) {
      var drin = (k.teilnehmer || []).map(function (t) { return t.user_id; });
      var h = '<div class="s-feld"><label>Dabei</label><div>';
      (k.teilnehmer || []).forEach(function (t) {
        h += '<div class="s-haken"><span>' + esc(t.name) + '</span>'
           + (k.ist_admin && !t.ist_admin
               ? '<button class="s-btn s-btn-klein s-btn-r s-t-weg" data-id="'
                 + t.user_id + '" style="margin-left:auto">entfernen</button>' : '')
           + '</div>';
      });
      h += '</div></div>';
      var frei = d.konten.filter(function (x) { return drin.indexOf(x.id) < 0; });
      h += '<div class="s-feld"><label for="s-t-neu">Hinzufügen</label>'
         + '<select class="s-ctl" id="s-t-neu">'
         + '<option value="0">– wählen –</option>'
         + frei.map(function (x) {
             return '<option value="' + x.id + '">' + esc(x.name) + '</option>';
           }).join('')
         + '</select></div>'
         + '<p class="s-sub">In der Auswahl stehen nur Konten mit Zugang — ein Konto '
         + 'ohne Rechte bekäme die Nachricht nie zu sehen.</p>';
      dialog({
        titel: 'Teilnehmer',
        inhalt: h,
        knoepfe: [ { text: 'Schließen', klasse: 's-btn-b', rolle: 'zu' },
                   { text: 'Hinzufügen', klasse: 's-btn-p', rolle: 'ok' } ],
        wenn: function (rolle) {
          if (rolle !== 'ok') { dialogZu(); return; }
          var neu = parseInt($('#s-t-neu').val(), 10) || 0;
          if (!neu) { toast('Bitte eine Person wählen.', 'fehl'); return; }
          ajax('konv_teil_hinzu', { id: k.id, user_id: neu })
            .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
        }
      });
    });
  });

  $(document).on('click', '.s-t-weg', function () {
    var k = konvJetzt();
    ajax('konv_teil_weg', { id: k.id, user_id: $(this).data('id') })
      .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
  });

  $(document).on('click', '.s-k-maend', function () {
    var k = konvJetzt();
    var id = parseInt($(this).closest('.s-k-m').data('msg'), 10);
    var alt = (k.nachrichten || []).filter(function (m) { return m.id === id; })[0];
    dialog({
      titel: 'Nachricht ändern',
      inhalt: '<div class="s-feld"><label>Text</label>'
            + '<textarea class="s-ctl" id="s-k-mtext" rows="4">'
            + esc(alt ? alt.text : '') + '</textarea></div>'
            + '<p class="s-sub">Nach ' + esc(k.frist_stunden) + ' Stunden lässt sich eine '
            + 'Nachricht nicht mehr ändern. Sie wird als bearbeitet gekennzeichnet.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Speichern', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('msg_aendern', { msg_id: id, text: $('#s-k-mtext').val() })
          .done(function (r) { dialogZu(); toast(r.message, 'ok'); konvLaden(k.id); });
      }
    });
  });

  $(document).on('click', '.s-k-mweg', function () {
    var k = konvJetzt();
    var id = parseInt($(this).closest('.s-k-m').data('msg'), 10);
    dialog({
      titel: 'Nachricht löschen',
      inhalt: '<p>Der Text geht, die Stelle bleibt als Lücke sichtbar — sonst weiss '
            + 'später niemand mehr, worauf eine Antwort sich bezog.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Löschen', klasse: 's-btn-r', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('msg_loeschen', { msg_id: id })
          .done(function (r) { dialogZu(); toast(r.message, 'ok'); konvLaden(k.id); });
      }
    });
  });

  $(document).on('click', '.s-k-manh', function () {
    var k = konvJetzt();
    var id = parseInt($(this).closest('.s-k-m').data('msg'), 10);
    dialog({
      titel: 'Datei anhängen',
      inhalt: '<div class="s-feld"><label>Datei</label>'
            + '<input type="file" class="s-ctl" id="s-a-f" accept=".pdf,.jpg,.jpeg,.png">'
            + '</div>'
            + '<p class="s-sub">PDF, JPG oder PNG, höchstens 10 MB. Geprüft wird der '
            + 'Inhalt. Der Anhang ist nur für die Teilnehmer dieser Unterhaltung '
            + 'abrufbar.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Anhängen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        hochladen('s-a-f', 'konv_anhang', { msg_id: id }, function (a) {
          dialogZu();
          toast(a.message || 'Angehängt.', 'ok');
          konvLaden(k.id);
        });
      }
    });
  });

  $(document).on('click', '.s-k-neu', function () {
    ajax('konten', {}).done(function (d) {
      var mann = (($buehne.data('kliste') || {}).mannschaften) || [];
      var h = '<div class="s-feld"><label for="s-n-typ">Art</label>'
            + '<select class="s-ctl" id="s-n-typ">'
            + '<option value="direkt">Mit einer Person</option>'
            + '<option value="gruppe">Gruppe</option>'
            + '</select></div>'
            + '<div class="s-feld"><label>Wer</label><div id="s-n-wer">'
            + d.konten.map(function (x) {
                return '<label class="s-haken"><input type="checkbox" value="' + x.id
                     + '"> ' + esc(x.name) + '</label>';
              }).join('')
            + '</div></div>'
            + '<div class="s-feld"><label for="s-n-name">Name (nur für eine Gruppe)</label>'
            + '<input type="text" class="s-ctl" id="s-n-name" maxlength="200"></div>'
            + '<p class="s-sub">Eine Unterhaltung mit derselben Person gibt es nur '
            + 'einmal — ein zweiter Anlauf öffnet die vorhandene.</p>';
      dialog({
        titel: 'Neue Unterhaltung',
        inhalt: h,
        knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                   { text: 'Anlegen', klasse: 's-btn-p', rolle: 'ok' } ],
        wenn: function (rolle) {
          if (rolle !== 'ok') { dialogZu(); return; }
          var wer = [];
          $('#s-n-wer input:checked').each(function () {
            wer.push(parseInt($(this).val(), 10));
          });
          if (!wer.length) { toast('Bitte mindestens eine Person wählen.', 'fehl'); return; }
          ajax('konv_anlegen', { typ: $('#s-n-typ').val(), konten: wer,
            name: $('#s-n-name').val() })
            .done(function (r) {
              dialogZu();
              toast(r.message, 'ok');
              geheZu(ort()[0], 'nachrichten', r.id);
            });
        }
      });
    });
  });

  // ── Ansicht: Anfragen ─────────────────────────────────────────────────

  var ANF_CHIP = { offen: 's-chip-gelb', in_arbeit: 's-chip-blau',
                   wartet: 's-chip-grau', erledigt: 's-chip-gruen-rand',
                   geschlossen: 's-chip-grau' };

  function zeigeAnfragen(o, bereich) {
    var id = parseInt(o[2], 10) || 0;
    if (id) { anfrageLaden(bereich, id); return; }
    laedt();
    ajax('anfragen', { stand: o[2] === 'alle' ? '' : 'offen_alle' }).done(function (d) {
      var h = kopf('Anfragen',
        d.sieht_alle ? 'Alle Anfragen des Vereins' : 'Ihre Anfragen',
        '<button class="s-btn s-btn-p s-anf-neu">Neue Anfrage</button>');

      h += '<div class="s-btn-reihe">'
         + '<button class="s-btn ' + (o[2] === 'alle' ? 's-btn-b' : 's-btn-p')
         + '" data-geh="' + esc(bereich) + '/anfragen">Offene</button>'
         + '<button class="s-btn ' + (o[2] === 'alle' ? 's-btn-p' : 's-btn-b')
         + '" data-geh="' + esc(bereich) + '/anfragen/alle">Alle</button></div>';

      if (!d.anfragen.length) {
        h += '<div class="s-nichts">Keine Anfrage.</div>';
      } else {
        h += tabelle('s-anf', [
          { kopf: 'Nummer', feld: 'nummer' },
          { kopf: 'Titel', feld: 'titel' },
          { kopf: 'Kategorie', feld: 'kategorie' },
          { kopf: 'Dringlichkeit', feld: 'dringend' },
          { kopf: 'Stand', feld: 'stand', art: 'roh' },
          { kopf: 'Von', feld: 'von' },
          { kopf: 'Zuständig', feld: 'zustaendig' },
          { kopf: '', feld: 'tun', art: 'roh', label: 'Aktionen' }
        ], d.anfragen.map(function (a) {
          return { __id: a.id, nummer: a.nummer, titel: a.titel,
                   kategorie: a.kategorie_name, dringend: a.dringend_name,
                   stand: '<span class="s-chip ' + (ANF_CHIP[a.stand] || 's-chip-grau')
                        + '">' + esc(a.stand_name) + '</span>',
                   von: a.von, zustaendig: a.zustaendig || '–',
                   tun: '<button class="s-btn s-btn-b" data-geh="' + esc(bereich)
                      + '/anfragen/' + a.id + '">Öffnen</button>' };
        }));
      }
      $buehne.html(h).data('anfl', d);
    });
  }

  function anfrageLaden(bereich, id) {
    laedt();
    ajax('anfrage', { id: id }).done(function (a) {
      var h = kopf(a.nummer + ' · ' + a.titel,
        a.kategorie_name + ' · ' + a.dringend_name + ' · von ' + a.von + ' am ' + a.am,
        '<button class="s-btn s-btn-b" data-geh="' + esc(bereich) + '/anfragen">Zurück</button>');

      h += '<div class="s-chips">'
         + '<span class="s-chip ' + (ANF_CHIP[a.stand] || 's-chip-grau') + '">'
         + esc(a.stand_name) + '</span>'
         + (a.zustaendig
             ? '<span class="s-chip s-chip-blau">Zuständig: ' + esc(a.zustaendig)
               + '</span>' : '')
         + '</div>';

      if (a.beschreibung) {
        h += '<div class="s-karte"><div class="s-karte-bd"><p>'
           + esc(a.beschreibung).replace(/\n/g, '<br>') + '</p></div></div>';
      }

      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Verlauf</h2></div>'
         + '<div class="s-karte-bd">';
      if (!a.verlauf.length) {
        h += '<div class="s-nichts">Noch kein Eintrag.</div>';
      }
      a.verlauf.forEach(function (v) {
        h += v.system
          ? '<div class="s-k-sys">' + esc(v.von + ': ' + v.text) + ' <small>'
            + esc(v.am) + '</small></div>'
          : '<div class="s-k-m' + (v.eigene ? ' eigen' : '') + '">'
            + '<div class="s-k-m-kopf">' + esc(v.von) + ' · ' + esc(v.am) + '</div>'
            + '<div class="s-k-m-text">' + esc(v.text).replace(/\n/g, '<br>')
            + '</div></div>';
      });
      h += '<div class="s-feld" style="margin-top:12px">'
         + '<label for="s-anf-k">Kommentar</label>'
         + '<textarea class="s-ctl" id="s-anf-k" rows="3"></textarea></div>'
         + '<button class="s-btn s-btn-p s-anf-komm">Kommentar schreiben</button>'
         + '</div></div>';

      h += '<div class="s-btn-reihe">'
         + (a.darf_aendern
             ? '<button class="s-btn s-btn-b s-anf-aend">Anfrage ändern</button>' : '')
         + (a.darf_bearbeiten
             ? '<button class="s-btn s-btn-b s-anf-stand">Stand setzen</button>'
               + '<button class="s-btn s-btn-b s-anf-zust">Zuständig</button>' : '')
         + (a.darf_zurueckziehen
             ? '<button class="s-btn s-btn-r s-anf-weg">'
               + (a.eigene && !a.darf_bearbeiten ? 'Zurückziehen' : 'Löschen')
               + '</button>' : '')
         + '</div>';

      $buehne.html(h).data('anf', a);
    }).fail(function (m) {
      $buehne.html(kopf('Anfrage') + '<div class="s-hinweis">' + esc(m) + '</div>');
    });
  }

  $(document).on('click', '.s-anf-komm', function () {
    var a = $buehne.data('anf') || {};
    var text = $('#s-anf-k').val();
    if (!String(text).trim()) { toast('Bitte etwas schreiben.', 'fehl'); return; }
    ajax('anfrage_kommentar', { id: a.id, text: text })
      .done(function (r) { toast(r.message, 'ok'); zeige(); });
  });

  function anfrageDialog(a, liste) {
    var kat = liste.kategorien || {};
    var dr = liste.dringlichkeit || {};
    var h = '<div class="s-feld"><label for="s-anf-t">Titel</label>'
          + '<input type="text" class="s-ctl" id="s-anf-t" maxlength="255" value="'
          + esc(a ? a.titel : '') + '"></div>'
          + '<div class="s-feld-reihe">'
          + '<div class="s-feld"><label for="s-anf-kat">Kategorie</label>'
          + '<select class="s-ctl" id="s-anf-kat">'
          + Object.keys(kat).map(function (k) {
              return '<option value="' + esc(k) + '">' + esc(kat[k]) + '</option>';
            }).join('')
          + '</select></div>'
          + '<div class="s-feld"><label for="s-anf-dr">Dringlichkeit</label>'
          + '<select class="s-ctl" id="s-anf-dr">'
          + Object.keys(dr).map(function (k) {
              return '<option value="' + esc(k) + '">' + esc(dr[k]) + '</option>';
            }).join('')
          + '</select></div></div>'
          + '<div class="s-feld"><label for="s-anf-b">Beschreibung</label>'
          + '<textarea class="s-ctl" id="s-anf-b" rows="5">'
          + esc(a ? a.beschreibung : '') + '</textarea></div>'
          + '<p class="s-sub">Der Titel braucht mindestens acht Zeichen — „Hilfe" '
          + 'allein sagt niemandem, worum es geht.</p>';
    dialog({
      titel: a ? 'Anfrage ändern' : 'Neue Anfrage',
      inhalt: h,
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: a ? 'Speichern' : 'Absenden', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        var daten = { titel: $('#s-anf-t').val(), beschreibung: $('#s-anf-b').val(),
                      kategorie: $('#s-anf-kat').val(), dringend: $('#s-anf-dr').val() };
        if (a) daten.id = a.id;
        ajax(a ? 'anfrage_aendern' : 'anfrage_stellen', daten).done(function (r) {
          dialogZu();
          toast(r.message, 'ok');
          if (a) zeige();
          else geheZu(ort()[0], 'anfragen', r.id);
        });
      }
    });
    if (a) {
      $('#s-anf-kat').val(a.kategorie);
      $('#s-anf-dr').val(a.dringend);
    }
  }

  $(document).on('click', '.s-anf-neu', function () {
    anfrageDialog(null, $buehne.data('anfl') || {});
  });

  $(document).on('click', '.s-anf-aend', function () {
    var a = $buehne.data('anf') || {};
    ajax('anfragen', {}).done(function (d) { anfrageDialog(a, d); });
  });

  $(document).on('click', '.s-anf-stand', function () {
    var a = $buehne.data('anf') || {};
    ajax('anfragen', {}).done(function (d) {
      var st = d.staende || {};
      dialog({
        titel: 'Stand setzen',
        inhalt: '<div class="s-feld"><label for="s-anf-s">Stand</label>'
              + '<select class="s-ctl" id="s-anf-s">'
              + Object.keys(st).map(function (k) {
                  return '<option value="' + esc(k) + '">' + esc(st[k]) + '</option>';
                }).join('')
              + '</select></div>'
              + '<p class="s-sub">„Wartet auf Rückmeldung" springt zurück in Arbeit, '
              + 'sobald die fragende Person antwortet.</p>',
        knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                   { text: 'Setzen', klasse: 's-btn-p', rolle: 'ok' } ],
        wenn: function (rolle) {
          if (rolle !== 'ok') { dialogZu(); return; }
          ajax('anfrage_stand', { id: a.id, stand: $('#s-anf-s').val() })
            .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
        }
      });
      $('#s-anf-s').val(a.stand);
    });
  });

  $(document).on('click', '.s-anf-zust', function () {
    var a = $buehne.data('anf') || {};
    ajax('konten', {}).done(function (d) {
      dialog({
        titel: 'Zuständig',
        inhalt: '<div class="s-feld"><label for="s-anf-z">Person</label>'
              + '<select class="s-ctl" id="s-anf-z"><option value="0">– niemand –</option>'
              + d.konten.map(function (x) {
                  return '<option value="' + x.id + '">' + esc(x.name) + '</option>';
                }).join('')
              + '</select></div>'
              + '<p class="s-sub">Wem eine Anfrage zugewiesen ist, sieht sie in seiner '
              + 'Liste — auch ohne das Recht auf alle Anfragen.</p>',
        knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                   { text: 'Speichern', klasse: 's-btn-p', rolle: 'ok' } ],
        wenn: function (rolle) {
          if (rolle !== 'ok') { dialogZu(); return; }
          ajax('anfrage_zustaendig', { id: a.id, user_id: $('#s-anf-z').val() })
            .done(function (r) { dialogZu(); toast(r.message, 'ok'); zeige(); });
        }
      });
      if (a.zustaendig_id) $('#s-anf-z').val(a.zustaendig_id);
    });
  });

  $(document).on('click', '.s-anf-weg', function () {
    var a = $buehne.data('anf') || {};
    var eigen = a.eigene && !a.darf_bearbeiten;
    dialog({
      titel: eigen ? 'Anfrage zurückziehen' : 'Anfrage löschen',
      inhalt: '<p>Die Anfrage und ihr Verlauf werden entfernt.'
            + (eigen ? ' Das geht nur, solange niemand daran gearbeitet hat.' : '')
            + '</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: eigen ? 'Zurückziehen' : 'Löschen', klasse: 's-btn-r',
                   rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('anfrage_loeschen', { id: a.id }).done(function (r) {
          dialogZu();
          toast(r.message, 'ok');
          geheZu(ort()[0], 'anfragen');
        });
      }
    });
  });

  // ── Suche ─────────────────────────────────────────────────────────────

  var suchZeit = null;
  $(document).on('input', '#s-suche-feld', function () {
    var wort = $(this).val();
    clearTimeout(suchZeit);
    if (String(wort).trim().length < 2) { $('#s-suche-aus').prop('hidden', true).empty(); return; }
    /* 250 ms: lange genug, dass Tippen keine zehn Abfragen auslöst, kurz
       genug, dass es sofort wirkt. */
    suchZeit = setTimeout(function () { suchen(wort); }, 250);
  });

  function suchen(wort) {
    ajax('suche', { wort: wort }, true).done(function (d) {
      var $aus = $('#s-suche-aus');
      if (d.hinweis) {
        $aus.html('<div class="s-treffer-gruppe">' + esc(d.hinweis) + '</div>')
            .prop('hidden', false);
        return;
      }
      if (!d.gruppen.length) {
        $aus.html('<div class="s-treffer-gruppe">Nichts gefunden zu „'
          + esc(d.wort) + '"</div>').prop('hidden', false);
        return;
      }
      var h = '';
      d.gruppen.forEach(function (g) {
        h += '<div class="s-treffer-gruppe">' + esc(g.name) + '</div>';
        g.treffer.forEach(function (t) {
          h += '<button class="s-treffer" data-ziel="' + esc(t.ziel) + '">'
             + esc(t.name) + '<small>' + esc(t.wo) + '</small></button>';
        });
      });
      $aus.html(h).prop('hidden', false);
    });
  }

  $(document).on('click', '.s-treffer', function () {
    $('#s-suche-aus').prop('hidden', true);
    $('#s-suche-feld').val('');
    window.location.hash = $(this).data('ziel');
  });

  $(document).on('click', function (e) {
    if (!$(e.target).closest('.s-suche').length) $('#s-suche-aus').prop('hidden', true);
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape') {
      if ($('#s-dialog').hasClass('auf')) dialogZu();
      else if (!$('#s-suche-aus').prop('hidden')) $('#s-suche-aus').prop('hidden', true);
      else if ($nav.hasClass('auf')) {
        $nav.removeClass('auf');
        $('#s-menue').attr('aria-expanded', 'false');
      }
    }
    /* Schrägstrich springt in die Suche — wie in den Werkzeugen, die Leute
       ohnehin benutzen. Nicht, wenn man gerade in ein Feld tippt. */
    if (e.key === '/' && !$(e.target).is('input, textarea, select')) {
      e.preventDefault();
      $('#s-suche-feld').trigger('focus');
    }
  });

  // ── Ansicht: Reflexionsbögen ──────────────────────────────────────────

  var REFL_CHIP = { offen: 's-chip-gelb', abgegeben: 's-chip-gruen-rand',
                    archiviert: 's-chip-grau' };

  function zeigeReflexion(o, bereich) {
    var gewaehlt = parseInt(o[2], 10) || 0;
    laedt();
    ajax('mannschaften', { sparte: bereich }).done(function (d) {
      if (!d.mannschaften.length) {
        $buehne.html(kopf('Reflexionsbögen')
          + '<div class="s-hinweis">Ihnen ist keine Mannschaft zugeordnet.</div>');
        return;
      }
      if (!gewaehlt) gewaehlt = d.mannschaften[0].id;
      var h = kopf('Reflexionsbögen',
        'Je Saison und Schwimmer ein Bogen. Die Antworten liegen verschlüsselt '
        + 'in der Datenbank.');
      h += '<div class="s-btn-reihe">';
      d.mannschaften.forEach(function (m) {
        h += '<button class="s-btn ' + (m.id === gewaehlt ? 's-btn-p' : 's-btn-b')
           + ' s-refl-mann" data-id="' + m.id + '">' + esc(m.name) + '</button>';
      });
      h += '</div><div id="s-refl-inhalt"></div>';
      $buehne.html(h);
      reflLaden(gewaehlt, false);
    });
  }

  function reflLaden(mann, archiv) {
    var $z = $('#s-refl-inhalt');
    $z.html('<div class="s-laedt">Wird geladen …</div>');
    ajax('refl_uebersicht', { mannschaft_id: mann, archiv: archiv ? 1 : '' }, true)
      .done(function (d) {
        $z.data('refl', { mann: mann, archiv: archiv, darf: d.darf_aendern,
                          fragen: d.fragen || [], boegen: d.boegen || [] });
        $z.html(reflZeichnen(d, mann, archiv));
      })
      .fail(function (m) { $z.html('<div class="s-hinweis s-hinweis-warn">'
        + esc(m) + '</div>'); });
  }

  function reflZeichnen(d, mann, archiv) {
    var darf = d.darf_aendern;
    var h = '';
    if (!d.saison) {
      h += '<div class="s-hinweis s-hinweis-warn">Es ist keine Saison aktiv. Ein '
         + 'Bogen gehört zu einer Saison — bitte zuerst eine aktivieren.</div>';
    }

    /* Die Stammfragen. Eine weggelassene Frage wird stillgelegt, nicht
       gelöscht: Abgegebene Bögen zeigen darauf. */
    h += '<div class="s-karte"><div class="s-karte-hd"><h2>Fragen der Mannschaft</h2>'
       + '<span class="s-chip s-chip-grau">' + d.fragen.length + '</span></div>'
       + '<div class="s-karte-bd">';
    if (!d.fragen.length && !darf) {
      h += '<div class="s-nichts">Für diese Mannschaft sind keine Fragen '
         + 'angelegt.</div>';
    } else {
      h += '<div id="s-refl-fragen">';
      d.fragen.forEach(function (f) { h += reflFrageZeile(f, darf); });
      h += '</div>';
      if (darf) {
        h += '<div class="s-btn-reihe">'
           + '<button class="s-btn s-btn-b s-refl-plus">Frage hinzufügen</button>'
           + '<button class="s-btn s-btn-p s-refl-fsave">Fragen speichern</button>'
           + '</div>'
           + '<p class="s-sub">Eine Frage, die Sie herausnehmen, wird stillgelegt '
           + 'und nicht gelöscht — abgegebene Bögen zeigen darauf.</p>';
      }
    }
    h += '</div></div>';

    /* Die Bögen. */
    var rechts = '';
    if (darf && !archiv) {
      rechts += '<button class="s-btn s-btn-p s-refl-neu">Bögen anlegen</button>';
    }
    rechts += '<button class="s-btn s-btn-b s-refl-aus">Auswertung</button>';
    if (d.im_archiv || archiv) {
      rechts += '<button class="s-btn s-btn-b s-refl-archiv">'
             + (archiv ? 'Zurück zur Übersicht' : 'Archiv (' + d.im_archiv + ')')
             + '</button>';
    }
    h += '<div class="s-karte"><div class="s-karte-hd">'
       + '<h2>' + (archiv ? 'Archiv' : 'Bögen') + '</h2>'
       + '<div class="s-refl-hd-ctl">'
       + '<span class="s-chip ' + (d.offen ? 's-chip-gelb' : 's-chip-grau') + '">'
       + (archiv ? d.boegen.length + ' archiviert' : d.offen + ' offen') + '</span>'
       + rechts + '</div></div><div class="s-karte-bd">';
    if (!d.boegen.length) {
      h += '<div class="s-nichts">' + (archiv
         ? 'Im Archiv liegt nichts.'
         : 'Für diese Saison ist noch kein Bogen angelegt.') + '</div>';
    } else {
      h += tabelle('s-refl-t', [
        { kopf: 'Name', feld: 'name' },
        { kopf: 'Zustand', feld: 'stand', art: 'roh' },
        { kopf: 'Abgegeben', feld: 'am' },
        { kopf: '', feld: 'ctl', art: 'roh', label: 'Aktion' }
      ], d.boegen.map(function (b) {
        var c = '';
        if (b.status !== 'offen') {
          c += '<button class="s-btn s-btn-klein s-refl-sehen" data-id="' + b.id
             + '">Ansehen</button> ';
        }
        if (darf) {
          if (b.status === 'offen') {
            c += '<button class="s-btn s-btn-klein s-refl-key" data-id="' + b.id
               + '">Neuer Schlüssel</button> ';
          } else {
            c += '<button class="s-btn s-btn-klein s-refl-frei" data-id="' + b.id
               + '">Freischalten</button> ';
          }
          c += '<button class="s-btn s-btn-klein s-refl-arch" data-id="' + b.id
             + '" data-an="' + (b.status === 'archiviert' ? '0' : '1') + '">'
             + (b.status === 'archiviert' ? 'Zurückholen' : 'Archivieren')
             + '</button> ';
          c += '<button class="s-btn s-btn-klein s-btn-r s-refl-weg" data-id="' + b.id
             + '">Löschen</button>';
        }
        return {
          __id: b.id, name: b.name,
          stand: '<span class="s-chip ' + (REFL_CHIP[b.status] || 's-chip-grau')
               + '">' + esc(b.status_name) + '</span>',
          am: b.am || '–', ctl: c
        };
      }));
    }
    h += '</div></div>';
    return h;
  }

  function reflFrageZeile(f, darf) {
    if (!darf) {
      return '<div class="s-refl-f-lesen">' + esc(f.frage) + '</div>';
    }
    return '<div class="s-refl-f-zeile">'
         + '<input type="text" class="s-ctl s-refl-f" maxlength="500" '
         + 'data-id="' + (f.id || 0) + '" value="' + esc(f.frage || '') + '">'
         + '<button class="s-btn s-btn-r s-refl-f-weg" title="Frage herausnehmen">×'
         + '</button></div>';
  }

  function reflJetzt() { return $('#s-refl-inhalt').data('refl') || {}; }

  $(document).on('click', '.s-refl-mann', function () {
    window.location.hash = '#schwimmen/reflexion/' + $(this).data('id');
  });

  $(document).on('click', '.s-refl-archiv', function () {
    var r = reflJetzt();
    reflLaden(r.mann, !r.archiv);
  });

  $(document).on('click', '.s-refl-plus', function () {
    $('#s-refl-fragen').append(reflFrageZeile({ id: 0, frage: '' }, true));
    $('#s-refl-fragen .s-refl-f').last().trigger('focus');
  });

  $(document).on('click', '.s-refl-f-weg', function () {
    $(this).closest('.s-refl-f-zeile').remove();
  });

  $(document).on('click', '.s-refl-fsave', function () {
    var r = reflJetzt();
    var liste = [];
    $('#s-refl-fragen .s-refl-f').each(function () {
      var t = String($(this).val() || '').trim();
      if (t === '') return;
      liste.push({ id: parseInt($(this).data('id'), 10) || 0, frage: t });
    });
    ajax('refl_fragen', { mannschaft_id: r.mann, fragen: JSON.stringify(liste) })
      .done(function (a) { toast(a.message, 'ok'); reflLaden(r.mann, r.archiv); });
  });

  $(document).on('click', '.s-refl-neu', function () {
    var r = reflJetzt();
    dialog({
      titel: 'Bögen anlegen',
      inhalt: '<p>Für jeden Schwimmer der Mannschaft entsteht ein Bogen dieser '
            + 'Saison. Die Zugangsschlüssel stehen danach <strong>genau '
            + 'einmal</strong> auf dem Bildschirm — danach liegt in der Datenbank '
            + 'nur noch ihr Hash.</p>'
            + '<p class="s-sub">Wer seinen Schlüssel verliert, bekommt mit '
            + '„Neuer Schlüssel" einen neuen.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Anlegen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('refl_boegen', { mannschaft_id: r.mann }).done(function (a) {
          dialogZu();
          toast(a.message, 'ok');
          if (a.schluessel && a.schluessel.length) {
            var text = a.schluessel.map(function (s) {
              return s.name + '\t' + s.schluessel;
            }).join('\n');
            textFenster('Die Zugangsschlüssel — nur jetzt', text,
              'Diese Liste gibt es nur jetzt: In der Datenbank steht nur der Hash. '
              + 'Bitte an die Schwimmer weitergeben.');
          }
          reflLaden(r.mann, false);
        });
      }
    });
  });

  $(document).on('click', '.s-refl-key', function () {
    var r = reflJetzt(), id = $(this).data('id');
    ajax('refl_schluessel', { bogen_id: id }).done(function (a) {
      textFenster('Neuer Zugangsschlüssel', a.schluessel,
        'Der alte gilt nicht mehr. Dieser steht nur jetzt hier.');
      reflLaden(r.mann, r.archiv);
    });
  });

  $(document).on('click', '.s-refl-sehen', function () {
    var id = $(this).data('id');
    ajax('refl_bogen', { bogen_id: id }).done(function (d) {
      var h = '<p class="s-sub">' + esc(d.name)
            + (d.am ? ' · abgegeben ' + esc(d.am) : '') + '</p>';
      if (!d.antworten.length) {
        h += '<div class="s-nichts">Dieser Bogen ist leer.</div>';
      }
      d.antworten.forEach(function (a) {
        if (a.typ === 'zeit') {
          h += '<div class="s-refl-a"><div class="s-refl-a-f">'
             + esc(a.strecke) + '</div><div class="s-refl-a-t">'
             + 'jetzt ' + esc(a.zeit || '–') + ' · Wunsch ' + esc(a.wunsch || '–')
             + '</div></div>';
        } else {
          h += '<div class="s-refl-a"><div class="s-refl-a-f">' + esc(a.frage)
             + '</div><div class="s-refl-a-t">'
             + esc(a.text).replace(/\n/g, '<br>') + '</div></div>';
        }
      });
      dialog({
        titel: 'Bogen von ' + d.name,
        inhalt: h,
        knoepfe: [ { text: 'Schließen', klasse: 's-btn-b', rolle: 'zu' } ],
        wenn: function () { dialogZu(); }
      });
    });
  });

  $(document).on('click', '.s-refl-frei', function () {
    var r = reflJetzt(), id = $(this).data('id');
    dialog({
      titel: 'Bogen freischalten',
      inhalt: '<p>Der Bogen wird wieder offen. Die Antworten bleiben stehen — der '
            + 'Schwimmer kann sie mit demselben Schlüssel richtigstellen.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Freischalten', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('refl_freischalten', { bogen_id: id }).done(function (a) {
          dialogZu(); toast(a.message, 'ok'); reflLaden(r.mann, r.archiv);
        });
      }
    });
  });

  $(document).on('click', '.s-refl-arch', function () {
    var r = reflJetzt(), id = $(this).data('id');
    var an = String($(this).data('an')) === '1';
    ajax('refl_archiv', { bogen_id: id, an: an ? 1 : '' }).done(function (a) {
      toast(a.message, 'ok');
      reflLaden(r.mann, r.archiv);
    });
  });

  $(document).on('click', '.s-refl-weg', function () {
    var r = reflJetzt(), id = $(this).data('id');
    dialog({
      titel: 'Bogen löschen',
      inhalt: '<p>Der Bogen geht mit allen Antworten. Das ist nicht rückholbar.</p>'
            + '<p class="s-sub">Soll er nur aus der Übersicht verschwinden, '
            + 'archivieren Sie ihn — dann bleibt er lesbar.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Löschen', klasse: 's-btn-r', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('refl_loeschen', { bogen_id: id }).done(function (a) {
          dialogZu(); toast(a.message, 'ok'); reflLaden(r.mann, r.archiv);
        });
      }
    });
  });

  $(document).on('click', '.s-refl-aus', function () {
    var r = reflJetzt();
    ajax('refl_auswertung', { mannschaft_id: r.mann }).done(function (d) {
      var h = '<p class="s-sub">' + d.abgegeben + ' von ' + d.gesamt
            + ' Bögen abgegeben</p>';
      if (!d.fragen.length) {
        h += '<div class="s-nichts">Noch keine Antworten.</div>';
      }
      d.fragen.forEach(function (f) {
        h += '<div class="s-refl-a"><div class="s-refl-a-f">' + esc(f.frage)
           + '</div>';
        f.antworten.forEach(function (a) {
          h += '<div class="s-refl-a-t"><strong>' + esc(a.name) + ':</strong> '
             + esc(a.text).replace(/\n/g, '<br>') + '</div>';
        });
        h += '</div>';
      });
      dialog({
        titel: 'Auswertung',
        inhalt: h,
        knoepfe: [ { text: 'Schließen', klasse: 's-btn-b', rolle: 'zu' } ],
        wenn: function () { dialogZu(); }
      });
    });
  });

  // ── Ansicht: Trainingspläne ───────────────────────────────────────────

  function zeigeTrainingsplan(o) {
    var id = parseInt(o[2], 10) || 0;
    if (id) return tpPlan(id);
    laedt();
    ajax('tp_liste', {}).done(function (d) {
      var h = kopf('Trainingspläne',
        'Ein Plan gehört dem, der ihn geschrieben hat. Freigeben heisst lesen, '
        + 'nicht ändern.',
        '<button class="s-btn s-btn-p s-tp-neu">Neuer Plan</button>'
        + ' <button class="s-btn s-btn-b s-tpv">Vorlagen</button>');

      h += tpListe('Meine Pläne', d.eigene, true);
      h += tpListe('Von anderen freigegeben', d.freigegeben, false);
      $buehne.html(h);
    });
  }

  function tpListe(titel, zeilen, eigen) {
    var h = '<div class="s-karte"><div class="s-karte-hd"><h2>' + esc(titel) + '</h2>'
          + '<span class="s-chip s-chip-grau">' + zeilen.length + '</span></div>'
          + '<div class="s-karte-bd">';
    if (!zeilen.length) {
      h += '<div class="s-nichts">' + (eigen
         ? 'Sie haben noch keinen Plan geschrieben.'
         : 'Kein anderer Trainer hat einen Plan freigegeben.') + '</div>';
    } else {
      h += tabelle('', [
        { kopf: 'Titel', feld: 'titel' },
        { kopf: eigen ? 'Freigegeben' : 'Von', feld: 'wer', art: 'roh' },
        { kopf: 'Sessions', feld: 'sessions', art: 'zahl' },
        { kopf: 'PDFs', feld: 'dateien', art: 'zahl' },
        { kopf: 'Geändert', feld: 'geaendert' },
        { kopf: '', feld: 'ctl', art: 'roh', label: 'Aktion' }
      ], zeilen.map(function (p) {
        return {
          __id: p.id, titel: p.titel,
          wer: eigen
            ? '<span class="s-chip ' + (p.freigegeben ? 's-chip-gruen-rand' : 's-chip-grau')
              + '">' + (p.freigegeben ? 'Freigegeben' : 'Nur ich') + '</span>'
            : esc(p.ersteller),
          sessions: p.sessions, dateien: p.dateien, geaendert: p.geaendert || '–',
          ctl: '<button class="s-btn s-btn-klein s-tp-auf" data-id="' + p.id
             + '">Öffnen</button>'
        };
      }));
    }
    return h + '</div></div>';
  }

  function tpPlan(id) {
    laedt();
    ajax('tp', { id: id }).done(function (d) {
      var darf = d.darf_aendern;
      var rechts = '<a class="s-btn s-btn-b" href="#schwimmen/trainingsplan">Zurück</a>';
      if (darf) {
        rechts += ' <button class="s-btn s-btn-b s-tp-frei" data-an="'
               + (d.freigegeben ? '0' : '1') + '">'
               + (d.freigegeben ? 'Freigabe zurückziehen' : 'Freigeben') + '</button>'
               + ' <button class="s-btn s-btn-r s-tp-weg">Löschen</button>';
      }
      var h = kopf(d.titel,
        (darf ? 'Ihr Plan' : 'Von ' + d.ersteller)
        + (d.geaendert ? ' · geändert ' + d.geaendert : '')
        + (d.freigegeben ? ' · freigegeben' : ''), rechts);

      if (!darf) {
        h += '<div class="s-hinweis">Dieser Plan ist freigegeben: Sie können ihn '
           + 'lesen. Ändern kann ihn nur, wer ihn geschrieben hat.</div>';
      }

      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Sessions</h2>'
         + '<span class="s-chip s-chip-grau">' + d.sessions.length + '</span></div>'
         + '<div class="s-karte-bd">';
      if (darf) {
        h += '<div class="s-feld"><label for="s-tp-titel">Titel</label>'
           + '<input type="text" class="s-ctl" id="s-tp-titel" maxlength="200" value="'
           + esc(d.titel) + '"></div>';
      }
      h += '<div id="s-tp-s">';
      d.sessions.forEach(function (s) { h += tpSession(s, darf); });
      h += '</div>';
      if (darf) {
        h += '<div class="s-btn-reihe">'
           + '<button class="s-btn s-btn-b s-tp-plus">Session hinzufügen</button>'
           + '<button class="s-btn s-btn-b s-tp-aus-vorlage">Aus Vorlage</button>'
           + '<button class="s-btn s-btn-p s-tp-save">Speichern</button></div>';
      } else if (!d.sessions.length) {
        h += '<div class="s-nichts">Dieser Plan steht nur als PDF da.</div>';
      }
      h += '</div></div>';

      h += '<div class="s-karte"><div class="s-karte-hd"><h2>PDFs</h2>'
         + '<span class="s-chip s-chip-grau">' + d.dateien.length + '</span></div>'
         + '<div class="s-karte-bd">';
      if (!d.dateien.length) {
        h += '<div class="s-nichts">Keine PDF am Plan.</div>';
      } else {
        d.dateien.forEach(function (f) {
          h += '<div class="s-btn-reihe" style="margin-bottom:4px">'
             + '<a class="s-btn s-btn-b" href="' + esc(Z.ajax_url)
             + '?action=lsv07s_tp_datei_zeigen&id=' + f.id + '&nonce=' + esc(Z.nonce)
             + '">' + esc(f.name) + '</a>'
             + '<span class="s-sub">' + Math.round(f.groesse / 1024) + ' kB · '
             + esc(f.am) + '</span>'
             + (darf ? '<button class="s-btn s-btn-klein s-btn-r s-tp-datei-weg" '
                     + 'data-id="' + f.id + '">×</button>' : '')
             + '</div>';
        });
      }
      if (darf) {
        h += '<div class="s-btn-reihe">'
           + '<input type="file" id="s-tp-datei" accept="application/pdf" '
           + 'class="s-ctl">'
           + '<button class="s-btn s-btn-b s-tp-datei-neu">Hochladen</button></div>'
           + '<p class="s-sub">PDF, bis 20 MB, bis 20 je Plan. Ein eingescannter '
           + 'Plan ist ein Plan — er braucht keine Sessions.</p>';
      }
      h += '</div></div>';

      $buehne.html(h).data('tp', { id: id, darf: darf });
    }).fail(function (m) {
      $buehne.html(kopf('Trainingsplan')
        + '<div class="s-hinweis s-hinweis-warn">' + esc(m) + '</div>'
        + '<a class="s-btn s-btn-b" href="#schwimmen/trainingsplan">Zurück</a>');
    });
  }

  function tpSession(s, darf) {
    if (!darf) {
      return '<div class="s-tp-lesen">'
           + '<div class="s-tp-kopf">' + esc(s.anzahl) + ' ' + esc(s.strecke)
           + (s.ausruestung ? ' <span class="s-chip s-chip-grau">'
                              + esc(s.ausruestung) + '</span>' : '')
           + '</div>'
           + (s.beschreibung ? '<div class="s-tp-text">'
               + esc(s.beschreibung).replace(/\n/g, '<br>') + '</div>' : '')
           + (s.kommentar ? '<div class="s-sub">' + esc(s.kommentar) + '</div>' : '')
           + '</div>';
    }
    return '<div class="s-tp-zeile" data-id="' + (s.id || 0) + '">'
      + '<div class="s-tp-gitter">'
      + '<input type="text" class="s-ctl s-tp-anzahl" placeholder="Anzahl" '
      + 'maxlength="20" value="' + esc(s.anzahl || '') + '">'
      + '<input type="text" class="s-ctl s-tp-strecke" placeholder="Strecke" '
      + 'maxlength="60" value="' + esc(s.strecke || '') + '">'
      + '<input type="text" class="s-ctl s-tp-geraet" placeholder="Ausrüstung" '
      + 'maxlength="200" value="' + esc(s.ausruestung || '') + '">'
      + '<button class="s-btn s-btn-r s-tp-zeile-weg" title="Session entfernen">×'
      + '</button></div>'
      + '<textarea class="s-ctl s-tp-text" rows="2" placeholder="Beschreibung">'
      + esc(s.beschreibung || '') + '</textarea>'
      + '<input type="text" class="s-ctl s-tp-komm" placeholder="Kommentar" '
      + 'maxlength="200" value="' + esc(s.kommentar || '') + '">'
      + '</div>';
  }

  function tpJetzt() { return $buehne.data('tp') || {}; }

  function tpSammeln() {
    var liste = [];
    $('#s-tp-s .s-tp-zeile').each(function () {
      var $z = $(this);
      liste.push({
        id: parseInt($z.data('id'), 10) || 0,
        anzahl: $z.find('.s-tp-anzahl').val(),
        strecke: $z.find('.s-tp-strecke').val(),
        ausruestung: $z.find('.s-tp-geraet').val(),
        beschreibung: $z.find('.s-tp-text').val(),
        kommentar: $z.find('.s-tp-komm').val()
      });
    });
    return liste;
  }

  $(document).on('click', '.s-tp-auf', function () {
    window.location.hash = '#schwimmen/trainingsplan/' + $(this).data('id');
  });

  $(document).on('click', '.s-tp-plus', function () {
    $('#s-tp-s').append(tpSession({ id: 0 }, true));
    $('#s-tp-s .s-tp-zeile').last().find('.s-tp-anzahl').trigger('focus');
  });

  $(document).on('click', '.s-tp-zeile-weg', function () {
    $(this).closest('.s-tp-zeile').remove();
  });

  $(document).on('click', '.s-tp-neu', function () {
    dialog({
      titel: 'Neuer Trainingsplan',
      inhalt: '<div class="s-feld"><label for="s-tp-ntitel">Titel</label>'
            + '<input type="text" class="s-ctl" id="s-tp-ntitel" maxlength="200"></div>'
            + '<p class="s-sub">Danach legen Sie die Sessions an oder laden eine '
            + 'PDF hoch. Ein Titel allein ist kein Trainingsplan.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Weiter', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('tp_speichern', { id: 0, titel: $('#s-tp-ntitel').val(),
          sessions: JSON.stringify([ { anzahl: '', strecke: '' } ]), pdf_folgt: 1 })
          .done(function (a) {
            dialogZu();
            window.location.hash = '#schwimmen/trainingsplan/' + a.id;
          });
      }
    });
  });

  $(document).on('click', '.s-tp-save', function () {
    var t = tpJetzt();
    ajax('tp_speichern', { id: t.id, titel: $('#s-tp-titel').val(),
      sessions: JSON.stringify(tpSammeln()) })
      .done(function (a) { toast(a.message, 'ok'); tpPlan(t.id); });
  });

  $(document).on('click', '.s-tp-frei', function () {
    var t = tpJetzt(), an = String($(this).data('an')) === '1';
    ajax('tp_freigeben', { id: t.id, an: an ? 1 : '' }).done(function (a) {
      toast(a.message, 'ok');
      tpPlan(t.id);
    });
  });

  $(document).on('click', '.s-tp-weg', function () {
    var t = tpJetzt();
    dialog({
      titel: 'Trainingsplan löschen',
      inhalt: '<p>Der Plan geht mit allen Sessions und PDFs. Das ist nicht '
            + 'rückholbar.</p>'
            + '<p class="s-sub">Soll ihn nur niemand mehr sehen, ziehen Sie die '
            + 'Freigabe zurück.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Löschen', klasse: 's-btn-r', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('tp_loeschen', { id: t.id }).done(function (a) {
          dialogZu();
          toast(a.message, 'ok');
          window.location.hash = '#schwimmen/trainingsplan';
        });
      }
    });
  });

  $(document).on('click', '.s-tp-datei-neu', function () {
    var t = tpJetzt();
    hochladen('s-tp-datei', 'tp_datei', { plan_id: t.id }, function (a) {
      toast(a.message || 'Abgelegt.', 'ok');
      tpPlan(t.id);
    }, 'Bitte eine PDF wählen.');
  });

  $(document).on('click', '.s-tp-datei-weg', function () {
    var t = tpJetzt(), id = $(this).data('id');
    ajax('tp_datei_weg', { id: id }).done(function (a) {
      toast(a.message, 'ok');
      tpPlan(t.id);
    });
  });

  /* Vorlagen: einzelne Sessions, die man wiederverwendet. */
  $(document).on('click', '.s-tpv', function () {
    ajax('tpv_liste', {}).done(function (d) {
      var h = '';
      [ [ 'Meine Vorlagen', d.eigene, true ],
        [ 'Von anderen freigegeben', d.freigegeben, false ] ].forEach(function (g) {
        h += '<h3 class="s-sub">' + esc(g[0]) + '</h3>';
        if (!g[1].length) {
          h += '<div class="s-nichts">Nichts da.</div>';
          return;
        }
        g[1].forEach(function (v) {
          h += '<div class="s-tp-lesen" data-v="' + v.id + '">'
             + '<div class="s-tp-kopf">' + esc(v.anzahl) + ' ' + esc(v.strecke)
             + (g[2] ? '' : ' <span class="s-sub">· ' + esc(v.ersteller) + '</span>')
             + '</div>'
             + (v.beschreibung ? '<div class="s-tp-text">' + esc(v.beschreibung)
                 + '</div>' : '')
             + '<div class="s-btn-reihe">'
             + (g[2] ? '<button class="s-btn s-btn-klein s-tpv-frei" data-id="' + v.id
                       + '" data-an="' + (v.freigegeben ? '0' : '1') + '">'
                       + (v.freigegeben ? 'Zurückziehen' : 'Freigeben') + '</button>'
                       + '<button class="s-btn s-btn-klein s-btn-r s-tpv-weg" '
                       + 'data-id="' + v.id + '">Löschen</button>' : '')
             + '</div></div>';
        });
      });
      dialog({
        titel: 'Vorlagen',
        inhalt: h,
        knoepfe: [ { text: 'Schließen', klasse: 's-btn-b', rolle: 'zu' } ],
        wenn: function () { dialogZu(); }
      });
    });
  });

  $(document).on('click', '.s-tpv-frei', function () {
    var id = $(this).data('id'), an = String($(this).data('an')) === '1';
    ajax('tpv_freigeben', { id: id, an: an ? 1 : '' }).done(function (a) {
      toast(a.message, 'ok');
      dialogZu();
    });
  });

  $(document).on('click', '.s-tpv-weg', function () {
    var id = $(this).data('id');
    ajax('tpv_loeschen', { id: id }).done(function (a) {
      toast(a.message, 'ok');
      dialogZu();
    });
  });

  /* Aus einer Vorlage eine Session machen: Die Vorlage bleibt, wie sie
     ist — sie wird nicht verschoben, sondern abgeschrieben. */
  $(document).on('click', '.s-tp-aus-vorlage', function () {
    ajax('tpv_liste', {}).done(function (d) {
      var alle = (d.eigene || []).concat(d.freigegeben || []);
      var h = alle.length ? '' : '<div class="s-nichts">Keine Vorlage da.</div>';
      alle.forEach(function (v) {
        h += '<button class="s-btn s-btn-b s-tp-nimm" data-v=\''
           + esc(JSON.stringify(v)) + '\'>' + esc(v.anzahl) + ' ' + esc(v.strecke)
           + '</button> ';
      });
      dialog({
        titel: 'Aus Vorlage',
        inhalt: h,
        knoepfe: [ { text: 'Schließen', klasse: 's-btn-b', rolle: 'zu' } ],
        wenn: function () { dialogZu(); }
      });
    });
  });

  $(document).on('click', '.s-tp-nimm', function () {
    var v = {};
    try { v = JSON.parse($(this).attr('data-v')); } catch (e) { v = {}; }
    v.id = 0;
    $('#s-tp-s').append(tpSession(v, true));
    dialogZu();
    toast('Session eingefügt. Noch nicht gespeichert.', 'ok');
  });

  // ── Ansicht: Funktionen schalten ──────────────────────────────────────

  function zeigeSchalter() {
    laedt();
    ajax('schalter', {}).done(function (d) {
      var h = kopf('Funktionen',
        'Was an ist und was aus. Global gilt für alle; je Konto steht, was eine '
        + 'Person sieht und tun darf.');

      h += '<div class="s-hinweis">' + esc(d.anmeldung) + ' Schalten darf nur ein '
         + 'WordPress-Administrator. Für Administratoren ist alles an, was global '
         + 'an ist — sonst könnte sich die Verwaltung aussperren.</div>';

      /* Global: was hier aus ist, ist für alle aus. */
      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Global</h2>'
         + '<span class="s-chip s-chip-grau">'
         + d.katalog.filter(function (f) { return !f.global_an; }).length
         + ' aus</span></div><div class="s-karte-bd">';
      var bereich = '';
      d.katalog.forEach(function (f) {
        if (f.bereich !== bereich) {
          bereich = f.bereich;
          h += '<div class="s-treffer-gruppe">' + esc(bereich) + '</div>';
        }
        h += '<label class="s-haken s-sch-zeile">'
           + '<input type="checkbox" class="s-sch-g" data-f="' + esc(f.funktion) + '"'
           + (f.global_an ? ' checked' : '') + '>'
           + '<span class="s-sch-name">' + esc(f.name)
           + (f.art === 'zusatz' ? ' <span class="s-chip s-chip-grau">Reichweite</span>'
                                 : '')
           + '<small>' + esc(f.was) + '</small></span></label>';
      });
      h += '</div></div>';

      /* Je Konto. */
      h += '<div class="s-karte"><div class="s-karte-hd"><h2>Konten</h2>'
         + '<span class="s-chip s-chip-grau">' + d.konten.length + '</span>'
         + '<button class="s-btn s-btn-klein s-btn-b s-sch-uebernahme">Aus alten '
         + 'Rechten übernehmen</button></div><div class="s-karte-bd">';
      if (!d.konten.length) {
        h += '<div class="s-nichts">Keine Konten gefunden.</div>';
      } else {
        h += tabelle('s-sch-k', [
          { kopf: 'Konto', feld: 'name' },
          { kopf: 'Funktionen an', feld: 'zahl', art: 'zahl' },
          { kopf: 'Zustand', feld: 'stand', art: 'roh' },
          { kopf: '', feld: 'ctl', art: 'roh', label: 'Aktion' }
        ], d.konten.map(function (k) {
          return {
            __id: k.id, name: k.name, zahl: k.admin ? 'alle' : k.zahl,
            stand: k.gesperrt
              ? '<span class="s-chip s-chip-rot">Gesperrt</span>'
              : (k.admin ? '<span class="s-chip s-chip-blau">Administrator</span>'
                         : (k.zahl ? '<span class="s-chip s-chip-gruen-rand">Zugang</span>'
                                   : '<span class="s-chip s-chip-grau">kein Zugang</span>')),
            ctl: k.admin
              ? '<span class="s-sub">alles an</span>'
              : '<button class="s-btn s-btn-klein s-sch-konto" data-id="' + k.id
                + '">Schalter</button>'
          };
        }));
      }
      h += '</div></div>';

      $buehne.html(h).data('sch', d);
    }).fail(function (m) {
      $buehne.html(kopf('Funktionen')
        + '<div class="s-hinweis s-hinweis-warn">' + esc(m) + '</div>');
    });
  }

  $(document).on('change', '.s-sch-g', function () {
    var $f = $(this), an = $f.prop('checked');
    ajax('schalter_global', { funktion: $f.data('f'), an: an ? 1 : '' })
      .done(function (a) { toast(a.message, 'ok'); })
      .fail(function (m) { toast(m, 'fehl'); $f.prop('checked', !an); });
  });

  $(document).on('click', '.s-sch-konto', function () {
    var d = $buehne.data('sch') || {};
    var id = parseInt($(this).data('id'), 10);
    var konto = (d.konten || []).filter(function (k) { return k.id === id; })[0];
    if (!konto) return;
    var an = konto.an || [];
    var h = '<p class="s-sub">' + esc(konto.name) + ' — angemeldet wird über das '
          + 'WordPress-Konto. Was hier aus ist, sieht die Person nicht.</p>';
    var bereich = '';
    (d.katalog || []).forEach(function (f) {
      if (f.bereich !== bereich) {
        bereich = f.bereich;
        h += '<div class="s-treffer-gruppe">' + esc(bereich) + '</div>';
      }
      h += '<label class="s-haken s-sch-zeile">'
         + '<input type="checkbox" class="s-sch-k-f" data-f="' + esc(f.funktion) + '"'
         + (an.indexOf(f.funktion) >= 0 ? ' checked' : '')
         + (f.global_an ? '' : ' disabled') + '>'
         + '<span class="s-sch-name">' + esc(f.name)
         + (f.global_an ? '' : ' <span class="s-chip s-chip-grau">global aus</span>')
         + '<small>' + esc(f.was) + '</small></span></label>';
    });
    dialog({
      titel: 'Funktionen für ' + konto.name,
      inhalt: h,
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Alles aus', klasse: 's-btn-r', rolle: 'leer' },
                 { text: 'Speichern', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle === 'zu') { dialogZu(); return; }
        var liste = [];
        if (rolle === 'ok') {
          $('.s-sch-k-f').each(function () {
            if ($(this).prop('checked')) liste.push($(this).data('f'));
          });
        }
        ajax('schalter_konto', { user_id: id, an: JSON.stringify(liste) })
          .done(function (a) {
            dialogZu();
            toast(a.message, 'ok');
            zeigeSchalter();
          });
      }
    });
  });

  $(document).on('click', '.s-sch-uebernahme', function () {
    dialog({
      titel: 'Aus den alten Rechten übernehmen',
      inhalt: '<p>Vor den Schaltern entschieden Rechte, und der interne Bereich '
            + 'entscheidet weiter so. Hier wird einmal nachgelesen, wer welche '
            + 'Rechte hat, und die Funktionen eingeschaltet, die diese Rechte '
            + 'geöffnet hätten.</p>'
            + '<p class="s-sub">Es wird nichts weggenommen: Was schon an ist, '
            + 'bleibt an. Die alten Rechte waren gröber als die Schalter — bitte '
            + 'danach durchsehen.</p>',
      knoepfe: [ { text: 'Abbrechen', klasse: 's-btn-b', rolle: 'zu' },
                 { text: 'Übernehmen', klasse: 's-btn-p', rolle: 'ok' } ],
      wenn: function (rolle) {
        if (rolle !== 'ok') { dialogZu(); return; }
        ajax('schalter_uebernahme', {}).done(function (a) {
          dialogZu();
          toast(a.message, 'ok');
          zeigeSchalter();
        });
      }
    });
  });

  // ── Zuordnung Ansicht → Funktion ──────────────────────────────────────

  var ANSICHTEN = {
    'start/aufgaben':       zeigeAufgaben,
    'verwaltung/protokoll': zeigeProtokoll,
    'verwaltung/schalter':  zeigeSchalter,
    '*/mannschaft':         zeigeMannschaften,
    '*/gruppe':             zeigeMannschaften,
    '*/anwesenheit':        zeigeAnwesenheit,
    'schwimmen/atteste':    zeigeAtteste,
    'schwimmen/akte':       zeigeAkte,
    'schwimmen/wettkampf':  zeigeWettkampf,
    'schwimmen/meldung':    zeigeMeldung,
    'schwimmen/bestzeit':   zeigeBestzeit,
    'schwimmen/reflexion':  zeigeReflexion,
    'schwimmen/trainingsplan': zeigeTrainingsplan,
    'abrechnung/stunden':   zeigeStunden,
    'abrechnung/uebersicht': zeigeStundenUebersicht,
    'reden/nachrichten':    zeigeNachrichten,
    'reden/anfragen':       zeigeAnfragen
  };

  // ── Start ─────────────────────────────────────────────────────────────

  $(function () {
    $buehne = $('#s-buehne');
    $nav    = $('#s-nav');
    $reiter = $('#s-reiter');
    if (!$buehne.length) return;
    navBauen();
    zeige();
  });

})(jQuery);
