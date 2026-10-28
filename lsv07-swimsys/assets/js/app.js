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
        var feld = document.getElementById('s-att-f');
        if (!feld || !feld.files || !feld.files.length) {
          toast('Bitte eine Datei wählen.', 'fehl');
          return;
        }
        /* Eine Datei geht nicht als Feld: FormData, und jQuery darf weder
           umwandeln noch einen Inhaltstyp setzen. */
        var fd = new FormData();
        fd.append('action', 'lsv07s_attest_datei');
        fd.append('nonce', Z.nonce);
        fd.append('person_id', id);
        fd.append('kommentar', $('#s-att-k').val() || '');
        fd.append('datei', feld.files[0]);
        $.ajax({ url: Z.ajax_url, type: 'POST', data: fd,
                 processData: false, contentType: false, dataType: 'json' })
          .done(function (r) {
            if (r && r.success) {
              dialogZu();
              toast((r.data && r.data.message) || 'Abgelegt.', 'ok');
              zeige();
            } else {
              toast((r && r.data && r.data.message) || 'Das hat nicht funktioniert.', 'fehl');
            }
          })
          .fail(function (xhr) {
            toast((xhr && xhr.responseJSON && xhr.responseJSON.data
                   && xhr.responseJSON.data.message) || 'Keine Verbindung zum Server.', 'fehl');
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
        var feld = document.getElementById('s-wk-f');
        if (!feld || !feld.files || !feld.files.length) {
          toast('Bitte eine Datei wählen.', 'fehl');
          return;
        }
        var fd = new FormData();
        fd.append('action', 'lsv07s_wk_dokument');
        fd.append('nonce', Z.nonce);
        fd.append('wettkampf_id', wk);
        fd.append('typ', typ);
        fd.append('datei', feld.files[0]);
        $.ajax({ url: Z.ajax_url, type: 'POST', data: fd,
                 processData: false, contentType: false, dataType: 'json' })
          .done(function (r) {
            if (r && r.success) {
              dialogZu();
              toast((r.data && r.data.message) || 'Abgelegt.', 'ok');
              zeige();
            } else {
              toast((r && r.data && r.data.message) || 'Das hat nicht funktioniert.', 'fehl');
            }
          })
          .fail(function (xhr) {
            toast((xhr && xhr.responseJSON && xhr.responseJSON.data
                   && xhr.responseJSON.data.message) || 'Keine Verbindung zum Server.', 'fehl');
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
  function textFenster(titel, text) {
    dialog({
      titel: titel,
      inhalt: '<textarea class="s-ctl" id="s-text-aus" rows="14" readonly'
            + ' style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px">' + esc(text) + '</textarea>'
            + '<p class="s-sub">Tabulatorgetrennt — das lässt sich in jede Tabelle '
            + 'einfügen.</p>',
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

  // ── Zuordnung Ansicht → Funktion ──────────────────────────────────────

  var ANSICHTEN = {
    'start/aufgaben':       zeigeAufgaben,
    'verwaltung/protokoll': zeigeProtokoll,
    '*/mannschaft':         zeigeMannschaften,
    '*/gruppe':             zeigeMannschaften,
    '*/anwesenheit':        zeigeAnwesenheit,
    'schwimmen/atteste':    zeigeAtteste,
    'schwimmen/akte':       zeigeAkte,
    'schwimmen/wettkampf':  zeigeWettkampf,
    'schwimmen/meldung':    zeigeMeldung,
    'schwimmen/bestzeit':   zeigeBestzeit
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
