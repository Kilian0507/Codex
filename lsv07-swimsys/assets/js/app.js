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
    var h = '<div class="s-twrap"><table class="s-tbl" id="' + esc(id) + '"><thead><tr>';
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

      var zeilen = d.sportler.map(function (s) {
        return {
          __id: s.id,
          name: s.name,
          jahrgang: s.jahrgang || '–',
          attest: s.attest_bis ? de(s.attest_bis) : '–',
          dsv: s.dsv_id || '–'
        };
      });
      h += tabelle('s-kader', [
        { kopf: 'Name', feld: 'name' },
        { kopf: 'Jahrgang', feld: 'jahrgang', art: 'zahl' },
        { kopf: 'Attest bis', feld: 'attest' },
        { kopf: 'DSV-ID', feld: 'dsv' }
      ], zeilen);
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
    '*/anwesenheit':        zeigeAnwesenheit
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
