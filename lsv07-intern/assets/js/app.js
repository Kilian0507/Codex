/* global LSV07I, jQuery */
(function($){'use strict';

const S={mann:[],slots:[],trainer:[],schwimmer:[],abr:null,adminDone:false};
const WD=['','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'];

// ── Ladebalken ────────────────────────────────────────────────────
var _loaderTimer=null;
function startLoader(){
  clearTimeout(_loaderTimer);
  $('#lsv07i-loader').removeClass('done').addClass('loading');
}
function stopLoader(){
  $('#lsv07i-loader').removeClass('loading').addClass('done');
  _loaderTimer=setTimeout(function(){$('#lsv07i-loader').removeClass('done');},500);
}

function ajax(action,data){
  startLoader();
  return $.ajax({
    url:LSV07I.ajax_url,
    type:'POST',
    data:{action:action,nonce:LSV07I.nonce,...(data||{})},
    traditional:true
  }).always(stopLoader);
}


let _tt;
function toast(msg,type){
  type=type||'ok';
  var $t=$('#lsv07i-toast');
  $t.removeClass('t-ok t-err t-info show').addClass('t-'+type).html(msg);
  setTimeout(function(){$t.addClass('show');},10);
  clearTimeout(_tt);_tt=setTimeout(function(){$t.removeClass('show');},4000);
}
function errMsg(xhr){
  try{var r=JSON.parse(xhr.responseText);if(r&&r.data&&r.data.message)return r.data.message;}catch(e){}
  return 'Ein Fehler ist aufgetreten.';
}
function de(s){if(!s)return'';var p=s.split('-');return p[2]+'.'+p[1]+'.'+p[0];}
// Liefert das heutige Datum in lokaler Zeitzone als YYYY-MM-DD.
// WICHTIG: new Date().toISOString().slice(0,10) ist FALSCH — das konvertiert
// zuerst in UTC und liefert abends/nachts den Vortag.
function heuteLokal(){
  var d=new Date();
  var y=d.getFullYear();
  var m=String(d.getMonth()+1).padStart(2,'0');
  var dd=String(d.getDate()).padStart(2,'0');
  return y+'-'+m+'-'+dd;
}
function esc(s){return $('<span>').text(s||'').html();}
/* Bereich-Kennzeichnung für Verwaltung/Kassenwart-Listen: 'schwimmen' ist
   der weitaus häufigste Fall und bleibt daher unmarkiert (kein visuelles
   Rauschen); alle anderen Bereiche bekommen ein kleines Label, damit z.B.
   eine Sonderabrechnung nicht mit der reguslären Trainingsabrechnung
   desselben Trainers im selben Quartal verwechselt wird. */
function bereichLabel(b){
  var m={triathlon:'Triathlon',fitness:'Fitness',sonder:'Sonderabrechnung'};
  return m[b]?' '+bdg('b',m[b]):'';
}
function bdg(c,l){return'<span class="i-bdg i-bdg-'+c+'">'+l+'</span>';}
function sBdg(s){var m={entwurf:['b','Entwurf'],eingereicht:['a','Eingereicht'],genehmigt:['g','Genehmigt'],zurueck:['r','Zurückgegeben']};var cl=m[s]||['b',s];return bdg(cl[0],cl[1]);}
function fillSel($s,items,vk,lk,empty){
  var cur=$s.val();
  $s.find('option:not(:first-child)').remove();
  if(empty)$s.find('option:first-child').text(empty);
  $.each(items,function(i,item){$s.append('<option value="'+item[vk]+'">'+esc(item[lk])+'</option>');});
  if(cur)$s.val(cur);
}

/* ══ MODAL ══════════════════════════════════════════════════════ */
function openModal(id){$('#'+id).addClass('open');setTimeout(function(){lsvApplyFormDrafts();},60);lsvSaveModalState();}
/* Modal schließen. Standardmäßig werden dabei auch die gemerkten
   Formular-Entwürfe ALLER Felder in diesem Modal gelöscht — das ist
   nach erfolgreichem Absenden korrekt (Daten sind gespeichert, ein
   späteres "Wiederherstellen" würde nur veraltete Werte zurückbringen).
   Beim reinen Abbrechen/Schließen OHNE Speichern (Abbrechen-Button,
   Klick auf den Hintergrund, Escape-Taste) wird keepDraft=true übergeben,
   damit noch nicht abgesendete Eingaben beim nächsten Öffnen erhalten
   bleiben, wie bisher. */
function closeModal(id,keepDraft){
  $('#'+id).removeClass('open show');
  lsvSaveModalState();
  if(!keepDraft) lsvClearModalFieldDrafts(id);
}
// Alle Formular-Entwürfe löschen, die zu Feldern INNERHALB eines
// bestimmten Modals gehören (per id-Attribut identifiziert).
function lsvClearModalFieldDrafts(modalId){
  var $modal=$('#'+modalId);
  if(!$modal.length) return;
  var ids=[];
  $modal.find('input[id], textarea[id], select[id]').each(function(){ ids.push(this.id); });
  if(ids.length) lsvClearFormDraft(ids);
}

$(document).on('click','[data-close]',function(){closeModal($(this).data('close'),true);});
$(document).on('click','.i-ov',function(e){if(e.target===this){$(this).removeClass('open');lsvSaveModalState();}});
$(document).on('keydown',function(e){if(e.key==='Escape'){$('.i-ov.open').removeClass('open');lsvSaveModalState();}});

/* ── Handy: Tabellenzeilen aufklappen ───────────────────────────────
   Auf schmalen Bildschirmen zeigen Tabellenzeilen (z.B. einzelne
   Schwimmer) nur ihre erste Zelle (Name/Hauptinfo); ein Tipp darauf
   klappt die übrigen Angaben auf/zu (CSS siehe @media 600px).
   Tipps auf Bedienelemente in der Zeile (Buttons, Links) togglen
   NICHT, sondern öffnen nur. Dialoge sind ausgenommen.
   (Das frühere Einklappen der großen Hauptkarten wurde auf Wunsch
   wieder entfernt — die Karten sind immer offen, nur ihre Zeilen
   klappen.)                                                            */
function lsvIsPhone(){
  return window.matchMedia && window.matchMedia('(max-width:600px)').matches;
}
$(document).on('click','#lsv07i-root .i-tbl tbody td:first-child',function(e){
  if(!lsvIsPhone())return;
  var $tr=$(this).closest('tr');
  if($tr.closest('.i-ov').length)return;
  if($(e.target).closest('button,a,input,select,textarea,label,.i-btn').length){
    $tr.addClass('mob-open');
    return;
  }
  $tr.toggleClass('mob-open');
});

/* ══ NAVIGATION (Hauptbereiche) ════════════════════════════════ */
$(document).on('click','.i-nb, #lsv07i-logo',function(){
  var sec=$(this).data('sec');
  if(!sec)return;
  $('.i-nb').removeClass('on');
  $('.i-nb[data-sec="'+sec+'"]').addClass('on');
  // Alle Sections verstecken, gewählte zeigen
  $('.i-sec').css('display','none');
  $('#i-sec-'+sec).css('display','block');
  // Letzten Ort merken (für Wiederherstellung nach Seiten-Neuladen)
  lsvSaveLocation(sec, null);
  // Lazy-Load
  if(sec==='home'){homeLaden();homeMsgBadge();}
  if(sec==='s')loadSchwimmen();
  if(sec==='a'&&!S.adminDone)loadAdmin();
  if(sec==='v'){if($('#vw-laden').length)ladeVwAbr($('#vw-filter').val());}
  if(sec==='t')loadStammdaten();
  if(sec==='tk')loadTickets();
  if(sec==='tri'&&!S_tri.done)loadTriathlon();
  if(sec==='fit'&&!S_fit.done)loadFitness();
});

/* ══ LETZTEN ORT MERKEN (localStorage, 1 Stunde gültig) ═══════════════
   Speichert Bereich + optional aktiven Tab, damit man nach einem
   versehentlichen Neuladen nicht auf der Startseite landet, sondern dort
   weitermacht, wo man war. Nach 1 Stunde verfällt der gespeicherte Ort. */
var LSV_LOC_KEY='lsv07i_last_location';
var LSV_LOC_TTL=60*60*1000; // 1 Stunde in Millisekunden

function lsvSaveLocation(sec, panel){
  // Während der Start-/Wiederherstellungsphase nichts speichern: die
  // automatischen Tab-Klicks der Initialisierung (lsvInitTabs) und der
  // Orts-Wiederherstellung würden sonst den vom Nutzer gemerkten Ort
  // überschreiben, BEVOR er wiederhergestellt wurde. (Das war der Grund,
  // warum man nach dem Neuladen immer an derselben Stelle landete.)
  if(window.__lsvBooting) return;
  try{
    var prev={};
    try{ prev=JSON.parse(localStorage.getItem(LSV_LOC_KEY))||{}; }catch(e){}
    // Beim reinen Bereichswechsel den gemerkten Tab zurücksetzen, außer der
    // Bereich bleibt derselbe (dann Tab beibehalten).
    var panelToStore = panel;
    if(panel===null){ panelToStore = (prev.sec===sec) ? (prev.panel||null) : null; }
    localStorage.setItem(LSV_LOC_KEY, JSON.stringify({ sec:sec, panel:panelToStore, ts:Date.now() }));
  }catch(e){ /* localStorage nicht verfügbar (privater Modus o.ä.) – kein Problem */ }
}

function lsvReadLocation(){
  try{
    var raw=localStorage.getItem(LSV_LOC_KEY);
    if(!raw)return null;
    var loc=JSON.parse(raw);
    if(!loc||!loc.sec||!loc.ts)return null;
    if(Date.now()-loc.ts > LSV_LOC_TTL){ localStorage.removeItem(LSV_LOC_KEY); return null; }
    return loc;
  }catch(e){ return null; }
}

/* ══ FORMULAR-AUTOSAVE (localStorage, 3 Minuten gültig) ═══════════════
   Speichert vom Nutzer eingegebene Werte, damit sie nach einem
   versehentlichen Neuladen noch da sind. Nach 3 Minuten Inaktivität
   verfallen die Entwürfe.

   Wichtige Design-Entscheidungen zur Sicherheit:
   - Es werden NUR echte Nutzereingaben gespeichert ('input'/'change').
     Programmatisches Befüllen per .val() (z.B. beim Öffnen eines
     Bearbeiten-Modals) löst diese Events nicht aus und wird daher NICHT
     als Entwurf gespeichert.
   - Wiederhergestellt wird ein Wert nur, wenn das Feld leer ist bzw. noch
     seinen ursprünglichen Standardwert hat — so werden weder geladene
     Bearbeiten-Daten noch berechnete Vorgaben (Datum, Jahr) überschrieben.
   - Such-, Filter- und als data-no-save markierte Felder werden ignoriert. */
var LSV_FORM_KEY='lsv07i_form_drafts';
var LSV_FORM_TTL=3*60*1000; // 3 Minuten

// Feld-IDs, die NICHT gespeichert werden (Filter/Suche/Auswahl-Steuerungen)
var LSV_FORM_SKIP=/^(.*-(suche|search|filter|sel|f-|von|bis|monat|jahr|datum)$|.*-(suche|filter)-.*|pers-suche|kw-jahr-sel|vw-filter)/;

function lsvFormFieldEligible(el){
  if(!el || !el.id) return false;
  var tag=el.tagName;
  if(tag!=='INPUT' && tag!=='TEXTAREA' && tag!=='SELECT') return false;
  if(tag==='INPUT'){
    var ty=(el.type||'text').toLowerCase();
    if(ty==='password'||ty==='hidden'||ty==='file'||ty==='checkbox'||ty==='radio'||ty==='search') return false;
    // Datums-/Zeit-/Monatsfelder haben meist sinnvolle Vorgaben → auslassen
    if(ty==='date'||ty==='month'||ty==='time'||ty==='week') return false;
  }
  if(el.getAttribute && el.getAttribute('data-no-save')!==null && el.getAttribute('data-no-save')!==undefined && el.hasAttribute('data-no-save')) return false;
  if(LSV_FORM_SKIP.test(el.id)) return false;
  return true;
}

function lsvSaveFormDraft(el){
  if(!lsvFormFieldEligible(el)) return;
  try{
    var store={};
    try{ store=JSON.parse(localStorage.getItem(LSV_FORM_KEY))||{}; }catch(e){}
    if(!store.fields) store={ fields:{}, ts:Date.now() };
    store.fields[el.id]=el.value;
    store.ts=Date.now();
    localStorage.setItem(LSV_FORM_KEY, JSON.stringify(store));
  }catch(e){}
}

function lsvReadFormDrafts(){
  try{
    var raw=localStorage.getItem(LSV_FORM_KEY);
    if(!raw)return null;
    var store=JSON.parse(raw);
    if(!store||!store.fields||!store.ts)return null;
    if(Date.now()-store.ts > LSV_FORM_TTL){ localStorage.removeItem(LSV_FORM_KEY); return null; }
    return store.fields;
  }catch(e){ return null; }
}

// Einen einzelnen Wert nur setzen, wenn das Feld aktuell "leer/unberührt" ist,
// damit geladene Daten oder Standardwerte nicht überschrieben werden.
function lsvRestoreField(id, val){
  var $f=$('#'+id.replace(/([:.\[\],])/g,'\\$1'));
  if(!$f.length || val==null || val==='') return;
  var cur=$f.val();
  if(cur===''||cur==null){ $f.val(val); }
}

// Alle gespeicherten Entwürfe auf aktuell leere Felder anwenden.
function lsvApplyFormDrafts(){
  var f=lsvReadFormDrafts();
  if(!f) return;
  Object.keys(f).forEach(function(id){ lsvRestoreField(id, f[id]); });
}

// Nutzereingaben global mitschneiden (delegiert, erfasst auch später
// eingefügte Felder). Kein Speichern bei programmatischem .val().
$(document).on('input change', 'input, textarea, select', function(){
  lsvSaveFormDraft(this);
});

// Entwürfe einzelner Felder löschen (nach erfolgreichem Absenden), damit
// bereits gespeicherte Eingaben nicht erneut auftauchen. Ohne Argument
// werden alle Entwürfe verworfen.
function lsvClearFormDraft(ids){
  try{
    if(!ids){ localStorage.removeItem(LSV_FORM_KEY); return; }
    var store=JSON.parse(localStorage.getItem(LSV_FORM_KEY)||'null');
    if(!store||!store.fields) return;
    (Array.isArray(ids)?ids:[ids]).forEach(function(id){ delete store.fields[id]; });
    localStorage.setItem(LSV_FORM_KEY, JSON.stringify(store));
  }catch(e){}
}

/* ══ GEÖFFNETES FORMULAR-FENSTER MERKEN (localStorage, 3 Minuten) ═════
   Damit ein offenes Eingabe-Fenster (Modal) nach einem Neuladen wieder
   erscheint. Es werden nur Fenster gemerkt, die tatsächlich Eingabefelder
   enthalten – reine Detail-/Bestätigungs-Dialoge sollen nicht wieder
   aufpoppen. Nutzt denselben 3-Minuten-Ablauf wie die Formular-Entwürfe. */
var LSV_MODAL_KEY='lsv07i_open_modal';

// Enthält ein Modal echte Eingabefelder (nicht nur Buttons/Text)?
function lsvModalHasInputs($m){
  var has=false;
  $m.find('input, textarea, select').each(function(){
    if(lsvFormFieldEligible(this)){ has=true; return false; }
  });
  return has;
}

function lsvSaveModalState(){
  try{
    // Das oberste offene Modal mit Eingabefeldern merken.
    var id=null;
    $('.i-ov.open').each(function(){
      if(this.id && lsvModalHasInputs($(this))) id=this.id;
    });
    if(id){
      localStorage.setItem(LSV_MODAL_KEY, JSON.stringify({ id:id, ts:Date.now() }));
    }else{
      localStorage.removeItem(LSV_MODAL_KEY);
    }
  }catch(e){}
}

function lsvReadOpenModal(){
  try{
    var raw=localStorage.getItem(LSV_MODAL_KEY);
    if(!raw)return null;
    var m=JSON.parse(raw);
    if(!m||!m.id||!m.ts)return null;
    if(Date.now()-m.ts > LSV_FORM_TTL){ localStorage.removeItem(LSV_MODAL_KEY); return null; }
    return m;
  }catch(e){ return null; }
}

/* ══ STARTSEITE ═════════════════════════════════════════════════════
   Profilzeile, Trainings-Statistik, Top-5-Liste, Schnellzugriffe und
   die drei Info-Kacheln. Alle Daten kommen aus class-ajax-home.php. */

function homeLaden(){
  homeTrainings();
  homeTop5();
  homeUebersicht();
  loadHomeWidgets();
}

/* ── Letzte 5 Trainings ─────────────────────────────────────────── */
function homeTrainings(auswahl){
  var $karte=$('#home-karte-training');
  if(!$karte.length)return;
  var daten=auswahl?{auswahl:auswahl}:{};
  ajax('lsv07i_home_team_stats',daten).done(function(r){
    if(!r||!r.success)return;
    var g=r.data.gruppen||[];
    if(!g.length){$karte.hide();return;}
    $karte.show();

    // Umschalter nur zeigen, wenn es überhaupt etwas zu wählen gibt
    var $sel=$('#home-gruppe-sel');
    if(g.length>1){
      var mehrereSparten=false,erste=g[0].sparte;
      $.each(g,function(i,x){ if(x.sparte!==erste) mehrereSparten=true; });
      var opt='';
      $.each(g,function(i,x){
        var name=mehrereSparten?(homeSparteName(x.sparte)+': '+x.name):x.name;
        opt+='<option value="'+esc(x.wert)+'">'+esc(name)+'</option>';
      });
      $sel.html(opt).val(r.data.auswahl).show();
    } else {
      $sel.hide();
    }

    var tr=r.data.trainings||[];
    if(!tr.length){
      $('#home-training-inhalt').html('<div class="i-trbalken-leer">Für diese Gruppe wurden noch keine Trainings erfasst.</div>');
      return;
    }
    var h='<div class="i-trbalken">';
    $.each(tr,function(i,t){
      var hoehe=t.ausgefallen?100:Math.max(t.quote,3);
      var wert=t.ausgefallen?'Ausfall':(t.gesamt?t.anwesend+'/'+t.gesamt:'–');
      h+='<div class="i-trbalken-spalte'+(t.ausgefallen?' ausfall':'')+'">'
        +'<div class="i-trbalken-saeule" title="'+esc(de(t.training_datum)+' · '+wert)+'">'
        +'<div class="i-trbalken-wert">'+esc(wert)+'</div>'
        +'<div class="i-trbalken-fuell" style="height:'+hoehe+'%"></div>'
        +'</div>'
        +'<div class="i-trbalken-datum">'+esc(deKurz(t.training_datum))+'</div>'
        +'</div>';
    });
    // Fehlende Termine als leere Spalten, damit das Raster nicht springt
    for(var i=tr.length;i<5;i++){
      h+='<div class="i-trbalken-spalte"><div class="i-trbalken-saeule"></div>'
        +'<div class="i-trbalken-datum">–</div></div>';
    }
    h+='</div>';
    $('#home-training-inhalt').html(h);
  }).fail(function(){ $karte.hide(); });
}

function homeSparteName(s){
  return {schwimmen:'Schwimmen',triathlon:'Triathlon',fitness:'Fitness'}[s]||s;
}

// Kurzes Datum für die Balkenbeschriftung: 14.03.
function deKurz(d){
  if(!d)return '';
  var t=String(d).split('-');
  return t.length===3?(t[2]+'.'+t[1]+'.'):d;
}

$(document).on('change','#home-gruppe-sel',function(){
  homeTrainings($(this).val());
});

/* ── Top 5 Schwimmer ────────────────────────────────────────────── */
function homeTop5(){
  var $karte=$('#home-karte-top');
  if(!$karte.length)return;
  ajax('lsv07i_home_top_swimmers').done(function(r){
    if(!r||!r.success){$karte.hide();return;}
    var liste=r.data.swimmer||[];
    if(!liste.length){$karte.hide();return;}
    $karte.show();
    var h='<div class="i-toplist">';
    $.each(liste,function(i,s){
      var sub=[];
      if(s.mannschaft_name)sub.push(s.mannschaft_name);
      sub.push(s.erste_plaetze+'× Platz 1');
      h+='<div class="i-toprang">'
        +'<span class="i-toprang-nr">'+(i+1)+'</span>'
        +'<div class="i-toprang-txt">'
        +'<div class="i-toprang-name">'+esc(s.name)+'</div>'
        +'<div class="i-toprang-sub">'+esc(sub.join(' · '))+'</div>'
        +'</div>'
        +'<span class="i-toprang-punkte">'+s.punkte+' Pkt</span>'
        +'</div>';
    });
    h+='</div>';
    $('#home-top-inhalt').html(h);
  }).fail(function(){ $karte.hide(); });
}

/* ── Die drei Info-Kacheln ──────────────────────────────────────── */
function homeUebersicht(){
  ajax('lsv07i_home_uebersicht').done(function(r){
    if(!r||!r.success)return;
    var d=r.data;

    // Abrechnung
    var a=d.abrechnung||{};
    $('#home-abr-quartal').text((a.quartal||'')+' '+(a.jahr||''));
    if(a.vorhanden){
      $('#home-abr-betrag').html(eur(a.summe));
      var st={entwurf:['Entwurf','#64748b'],eingereicht:['Eingereicht','#d97706'],
              genehmigt:['Genehmigt','#16a34a'],zurueck:['Zurückgegeben','#b91c1c']}[a.status];
      $('#home-abr-status').html(st
        ?'<span style="color:'+st[1]+'">'+st[0]+'</span>'
        :'<span class="i-muted">gemischter Status</span>');
    } else {
      $('#home-abr-betrag').text('–');
      $('#home-abr-status').html('<span class="i-muted">noch nicht angelegt</span>');
      $('#home-info-abr').toggle(!!LSV07I.is_trainer);
    }

    // Nächster Wettkampf
    var w=d.naechster_wettkampf;
    if(w){
      $('#home-wk-name').text(w.name);
      var zeitraum=w.datum_von===w.datum_bis?de(w.datum_von):de(w.datum_von)+' – '+de(w.datum_bis);
      $('#home-wk-datum').text(w.ort?zeitraum+' · '+w.ort:zeitraum);
    } else {
      $('#home-wk-name').html('<span class="i-muted">keiner geplant</span>');
      $('#home-wk-datum').html('&nbsp;');
    }

    // Nächstes Training
    var t=d.naechstes_training;
    if(t){
      $('#home-tr-datum').text(homeTagLabel(t.datum)+', '+t.zeit_von+' Uhr');
      var sub=[t.gruppe];
      if(t.zeit_bis)sub.push('bis '+t.zeit_bis+' Uhr');
      $('#home-tr-sub').text(sub.filter(Boolean).join(' · '));
    } else {
      $('#home-tr-datum').html('<span class="i-muted">kein Termin hinterlegt</span>');
      $('#home-tr-sub').html('&nbsp;');
    }
  });
}

// "heute" / "morgen" / "Mo, 17.03." — je nach Abstand zum aktuellen Tag
function homeTagLabel(datum){
  if(!datum)return '';
  var teile=String(datum).split('-');
  if(teile.length!==3)return datum;
  var d=new Date(+teile[0],+teile[1]-1,+teile[2]);
  var heute=new Date(); heute.setHours(0,0,0,0);
  var tage=Math.round((d-heute)/86400000);
  if(tage===0)return 'heute';
  if(tage===1)return 'morgen';
  var wt=['So','Mo','Di','Mi','Do','Fr','Sa'][d.getDay()];
  return wt+', '+deKurz(datum);
}

/* ── Schnellzugriffe (App-Icons) ────────────────────────────────── */
var HomeW={available:[],active:[]};

function loadHomeWidgets(){
  ajax('lsv07i_home_widgets_get').done(function(r){
    if(!r||!r.success)return;
    HomeW.available=r.data.available||[];
    HomeW.active=r.data.active||[];
    renderHomeWidgets();
  });
}

function renderHomeWidgets(){
  var $grid=$('#home-widgets-grid');
  if(!$grid.length)return;
  if(!HomeW.active.length){
    $grid.html('<div class="i-muted" style="grid-column:1/-1;padding:6px 0">'
      +'Noch keine Schnellzugriffe gewählt — über das Zahnrad oben unter „Widgets“ auswählen.</div>');
    return;
  }
  var byId={}; $.each(HomeW.available,function(i,w){byId[w.id]=w;});
  var h='';
  $.each(HomeW.active,function(i,id){
    var w=byId[id]; if(!w)return;
    var tabAttr=w.tab?(' data-tab="'+esc(w.tab)+'"'):'';
    h+='<a class="i-appicon i-appicon-'+esc(w.bereich)+' i-home-tile" tabindex="0"'
      +' data-jump="'+esc(w.jump)+'"'+tabAttr+' title="'+esc(w.label)+'">'
      +'<span class="i-appicon-kachel">'+homeWidgetIcon(w.bereich,w.id)+'</span>'
      +'<span class="i-appicon-name">'+esc(w.kurz||w.label)+'</span>'
      +'</a>';
  });
  $grid.html(h);
}

function homeWidgetIcon(bereich, id){
  // Jeder Schnellzugriff bekommt sein eigenes Symbol; die Bereichsfarbe
  // kommt über die CSS-Klasse dazu. Ohne passenden Treffer greift das
  // allgemeine Symbol des Bereichs.
  function sv(inhalt){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
      +' stroke-linecap="round" stroke-linejoin="round">'+inhalt+'</svg>';
  }
  var gruppe   = sv('<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>');
  var haken    = sv('<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>');
  var pokal    = sv('<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M6 3h12v6a6 6 0 0 1-12 0z"/><path d="M12 15v4M8 21h8"/>');
  var stoppuhr = sv('<circle cx="12" cy="13" r="8"/><path d="M12 13V9M9 2h6"/>');
  var tausch    = sv('<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>');
  var blatt    = sv('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>');
  var geld     = sv('<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>');
  var brief    = sv('<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>');
  var ausweis  = sv('<rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="9" cy="11" r="2.5"/><path d="M5 17c.7-1.6 2.2-2.5 4-2.5s3.3.9 4 2.5"/><line x1="15.5" y1="10" x2="19" y2="10"/><line x1="15.5" y1="13.5" x2="19" y2="13.5"/>');
  var rad      = sv('<circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="m6 17 4-9 8 9"/><path d="m10 8 4-3"/>');
  var hantel   = sv('<path d="M6 4v16M18 4v16M4 8h4M4 12h4M4 16h4M16 8h4M16 12h4M16 16h4M8 12h8"/>');
  var welle    = sv('<path d="M2 16c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2"/><path d="M2 20c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2"/><circle cx="17" cy="7" r="3"/>');

  var jeWidget = {
    's.mann':gruppe, 's.anw':haken, 's.wk':pokal, 's.bz':stoppuhr,
    's.spr':tausch,  's.refl':blatt,
    'tri.mann':gruppe, 'tri.anw':haken,
    'fit.mann':gruppe, 'fit.anw':haken,
    'abr':geld, 'v.pers':ausweis, 'n.inbox':brief
  };
  if(id && jeWidget[id]) return jeWidget[id];
  return {s:welle, tri:rad, fit:hantel, t:geld, v:ausweis, n:brief}[bereich] || welle;
}

// Klick (und Enter) auf Kachel/App-Icon: Bereich wechseln, ggf. Tab öffnen
$(document).on('click','.i-home-tile',function(){
  var sec=$(this).data('jump');
  var tab=$(this).data('tab');
  if(!sec)return;
  $('.i-nb[data-sec="'+sec+'"]').first().trigger('click');
  if(tab){
    setTimeout(function(){ $('.i-tab[data-panel="'+tab+'"]').trigger('click'); },100);
  }
});
$(document).on('keydown','.i-appicon',function(e){
  if(e.which===13||e.which===32){ e.preventDefault(); $(this).trigger('click'); }
});

/* ── Profilzeile: Nachrichten-Knopf und ungelesene Anzahl ───────── */
$(document).on('click','#home-btn-nachrichten',function(){
  $('.i-nb[data-sec="n"]').first().trigger('click');
});

function homeMsgBadge(){
  var $b=$('#home-msg-badge');
  if(!$b.length)return;
  ajax('lsv07i_konv_unread').done(function(r){
    if(!r||!r.success)return;
    var c=parseInt((r.data&&r.data.count)||0,10);
    if(c>0)$b.text(c>99?'99+':c).show(); else $b.hide();
  });
}

/* ══ EINSTELLUNGEN ══════════════════════════════════════════════════ */

$(document).on('click','#home-btn-einstellungen',function(){ einstOeffnen(); });

function einstOeffnen(seite){
  ajax('lsv07i_profil_get').done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Konnte nicht laden.','err');return;}
    var d=r.data;
    $('input[name="lsv-theme"]').prop('checked',false)
      .filter('[value="'+(d.theme||'hell')+'"]').prop('checked',true);
    $('#einst-email').val(d.email||'');
    $('#einst-telefon').val(d.telefon||'');
    einstBildZeigen(d.bild_url,d.initialen);
    $('#einst-pw-alt,#einst-pw-neu,#einst-pw-neu2').val('');
    einstWidgetsFuellen();
    einstSeite(seite||'einst-darstellung');
    openModal('m-einst');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
}

function einstSeite(id){
  $('.i-einst-tab').removeClass('on').filter('[data-ziel="'+id+'"]').addClass('on');
  $('.i-einst-seite').hide();
  $('#'+id).show();
}
$(document).on('click','.i-einst-tab',function(){ einstSeite($(this).data('ziel')); });

/* ── Darstellung ────────────────────────────────────────────────── */
$(document).on('change','input[name="lsv-theme"]',function(){
  var wert=$(this).val();
  // Sofort anwenden, damit die Wirkung direkt sichtbar ist
  $('#lsv07i-root').attr('data-theme',wert);
  ajax('lsv07i_profil_theme',{theme:wert}).done(function(r){
    if(r&&r.success)toast('Darstellung gespeichert.');
    else toast((r&&r.data&&r.data.message)||'Speichern fehlgeschlagen.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

/* ── Passwort ───────────────────────────────────────────────────── */
$(document).on('click','#einst-pw-save',function(){
  var alt=$('#einst-pw-alt').val(),neu=$('#einst-pw-neu').val(),neu2=$('#einst-pw-neu2').val();
  if(!alt||!neu||!neu2){toast('Bitte alle Felder ausfüllen.','err');return;}
  if(neu!==neu2){toast('Die neuen Passwörter stimmen nicht überein.','err');return;}
  if(neu.length<10){toast('Das neue Passwort muss mindestens 10 Zeichen haben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Wird geändert…');
  ajax('lsv07i_profil_passwort',{alt:alt,neu:neu,neu2:neu2}).done(function(r){
    if(r&&r.success){
      toast('Passwort geändert.');
      $('#einst-pw-alt,#einst-pw-neu,#einst-pw-neu2').val('');
    } else toast((r&&r.data&&r.data.message)||'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');})
    .always(function(){$b.prop('disabled',false).text('Passwort ändern');});
});

/* ── Kontaktdaten ───────────────────────────────────────────────── */
$(document).on('click','#einst-kontakt-save',function(){
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_profil_kontakt',{
    email:$('#einst-email').val(),
    telefon:$('#einst-telefon').val()
  }).done(function(r){
    if(r&&r.success)toast('Kontaktdaten gespeichert.');
    else toast((r&&r.data&&r.data.message)||'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');})
    .always(function(){$b.prop('disabled',false).text('Speichern');});
});

/* ── Profilbild ─────────────────────────────────────────────────── */
function einstBildZeigen(url,initialen){
  var inhalt=url
    ?'<img src="'+esc(url)+'" alt="">'
    :'<span class="i-avatar-init">'+esc(initialen||'?')+'</span>';
  $('#einst-bild-vorschau').html(inhalt);
  $('#home-avatar').html(inhalt);
  $('#einst-bild-weg').toggle(!!url);
  // Chat mitziehen, damit die eigenen Nachrichten das neue Bild zeigen
  if(typeof Chat!=='undefined'){
    Chat.meinBild=url||'';
    if(initialen)Chat.meineInitialen=initialen;
    if(Chat.current&&typeof chatLoadMessages==='function')chatLoadMessages(true);
  }
}

$(document).on('click','#einst-bild-waehlen',function(){ $('#einst-bild-datei').click(); });

$(document).on('change','#einst-bild-datei',function(){
  var datei=this.files&&this.files[0];
  if(!datei)return;
  if(datei.size>3*1024*1024){toast('Das Bild ist zu groß (maximal 3 MB).','err');this.value='';return;}
  var fd=new FormData();
  fd.append('action','lsv07i_profil_bild');
  fd.append('nonce',LSV07I.nonce);
  fd.append('datei',datei);
  var $b=$('#einst-bild-waehlen').prop('disabled',true).text('Wird hochgeladen…');
  var $eingabe=$(this);
  $.ajax({
    url:LSV07I.ajax_url,type:'POST',data:fd,
    processData:false,contentType:false,
    // dataType erzwingen: sonst wertet jQuery eine Antwort mit falschem
    // Content-Type als Text, r.success ist undefiniert und der Upload
    // gilt als gescheitert, obwohl der Server ihn gespeichert hat.
    dataType:'json'
  }).done(function(r){
    if(r&&r.success){
      einstBildZeigen(r.data.bild_url,'');
      toast('Profilbild gespeichert.');
    } else toast((r&&r.data&&r.data.message)||'Upload fehlgeschlagen.','err');
  }).fail(function(xhr){toast('Profilbild-Upload: '+errMsg(xhr),'err');})
    .always(function(){
      $b.prop('disabled',false).text('Bild auswählen');
      $eingabe.val('');
    });
});

$(document).on('click','#einst-bild-weg',function(){
  if(!confirm('Profilbild wirklich entfernen?'))return;
  ajax('lsv07i_profil_bild_weg').done(function(r){
    if(r&&r.success){
      // Initialen wieder anzeigen — kommen frisch vom Server
      ajax('lsv07i_profil_get').done(function(r2){
        einstBildZeigen('',(r2&&r2.data&&r2.data.initialen)||'?');
      });
      toast('Profilbild entfernt.');
    } else toast((r&&r.data&&r.data.message)||'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

/* ── Widgets im Dialog ──────────────────────────────────────────── */
function einstWidgetsFuellen(){
  function bauen(){
    var bereichLabels={
      s:'Schwimmen', tri:'Triathlon', fit:'Fitness',
      t:'Trainer & Abrechnung', v:'Verwaltung', n:'Nachrichten'
    };
    var grouped={};
    $.each(HomeW.available,function(i,w){ (grouped[w.bereich]=grouped[w.bereich]||[]).push(w); });
    var aktivSet={}; $.each(HomeW.active,function(i,id){aktivSet[id]=true;});
    var h='';
    $.each(['s','tri','fit','t','v','n'],function(i,b){
      if(!grouped[b])return;
      h+='<div class="i-widget-cfg-group"><div class="i-widget-cfg-grp-ttl">'+esc(bereichLabels[b]||b)+'</div>';
      $.each(grouped[b],function(j,w){
        h+='<label class="i-widget-cfg-row">'
          +'<input type="checkbox" value="'+esc(w.id)+'"'+(aktivSet[w.id]?' checked':'')+'>'
          +'<div><div class="lbl-t">'+esc(w.label)+'</div>'
          +'<div class="lbl-s">'+esc(w.sub||'')+'</div></div>'
          +'</label>';
      });
      h+='</div>';
    });
    if(!h)h='<div class="i-muted" style="padding:16px 0">Es sind keine Schnellzugriffe verfügbar, weil dein Konto auf keinen Bereich Zugriff hat.</div>';
    $('#einst-widgets-body').html(h);
  }
  if(HomeW.available.length){ bauen(); return; }
  ajax('lsv07i_home_widgets_get').done(function(r){
    if(r&&r.success){
      HomeW.available=r.data.available||[];
      HomeW.active=r.data.active||[];
    }
    bauen();
  }).fail(bauen);
}

$(document).on('click','#einst-widgets-save',function(){
  var ids=$('#einst-widgets-body input[type=checkbox]:checked').map(function(){return $(this).val();}).get();
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_home_widgets_save',{ids:JSON.stringify(ids)}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Speichern fehlgeschlagen.','err');return;}
    HomeW.active=r.data.active||[];
    renderHomeWidgets();
    toast('Schnellzugriffe gespeichert.');
  }).fail(function(xhr){toast(errMsg(xhr),'err');})
    .always(function(){$b.prop('disabled',false).text('Auswahl speichern');});
});

// Beim Seitenstart: wenn home die Default-Section ist, Stats direkt laden
$(function(){
  /* Boot-Phase: den gemerkten Ort SOFORT lesen (bevor irgendein
     automatischer Klick ihn überschreiben kann) und das Speichern
     bis zum Abschluss der Wiederherstellung sperren.                    */
  window.__lsvBooting = true;
  var _lsvBootLoc = lsvReadLocation();

  /* ── Ersten sichtbaren Tab jeder Tab-Gruppe aktivieren ──────────────
     Da Tabs jetzt einzeln per Leserecht ein-/ausgeblendet werden, ist
     nicht mehr garantiert, dass der erste (fest als "on" markierte) Tab
     existiert. Wir aktivieren daher pro Gruppe den ersten vorhandenen Tab
     und verstecken die zugehörigen Panels sauber.                        */
  $('.i-tabs').each(function(){
    var $grp=$(this);
    if($grp.find('.i-tab.on').length) return;      // schon ein aktiver Tab
    var $first=$grp.find('.i-tab').first();
    if(!$first.length){
      // Gar kein Reiter sichtbar → zugehörige Panels ausblenden
      $grp.parent().children('.i-panel').css('display','none');
      return;
    }
    // Panels der Gruppe verstecken, dann ersten Reiter aktivieren
    lsvPanelContainer($grp, $first.data('panel')).children('.i-panel').css('display','none');
    $first.trigger('click');
  });

  if($('#i-sec-home').is(':visible')){
    homeLaden();
    homeMsgBadge();
  }

  /* ── Fullscreen-Modus: html-Klasse setzen ───────────────────────
     Die body-Klasse wird vom Plugin gesetzt. Wir spiegeln sie auf
     <html>, damit auch html-Selektoren greifen (manche Themes setzen
     Höhen/Margins auf html).                                              */
  if($('body').hasClass('lsv07i-fullscreen')){
    document.documentElement.classList.add('lsv07i-fullscreen');
  }

  /* ── Letzten Ort wiederherstellen (falls < 1 Stunde alt) ────────────
     Nur wenn der Bereich für diesen Nutzer existiert (Nav-Button vorhanden),
     sonst bleibt die Startseite. Der Tab wird nach kurzer Verzögerung
     angeklickt, damit die Section (und ihr Inhalt) zuerst da ist.        */
  var loc=_lsvBootLoc;
  if(loc && loc.sec && loc.sec!=='home'){
    var $navBtn=$('.i-nb[data-sec="'+loc.sec+'"]').first();
    if($navBtn.length){
      $navBtn.trigger('click');
      if(loc.panel){
        setTimeout(function(){
          var $tab=$('.i-tab[data-panel="'+loc.panel+'"]').first();
          if($tab.length) $tab.trigger('click');
        },150);
      }
    }
  }
  /* Boot-Phase beenden: ab jetzt speichern Klicks des Nutzers den Ort
     wieder normal. 800ms decken auch den verzögerten Tab-Klick (150ms)
     sicher ab.                                                          */
  setTimeout(function(){ window.__lsvBooting = false; }, 800);

  /* ── Formular-Entwürfe wiederherstellen (falls < 3 Minuten alt) ──────
     Für aktuell sichtbare Felder. Modal-Felder werden zusätzlich beim
     Öffnen des jeweiligen Modals wiederhergestellt (siehe openModal).
     Läuft nach der Orts-Wiederherstellung, damit auch Felder im gerade
     eingeblendeten Bereich erfasst werden.                                */
  setTimeout(function(){ lsvApplyFormDrafts(); }, 250);

  /* ── Geöffnetes Formular-Fenster wiederherstellen (falls < 3 Min) ───
     War beim Verlassen ein Eingabe-Modal offen, wird es wieder geöffnet
     und mit den gespeicherten Entwürfen befüllt.                         */
  setTimeout(function(){
    var m=lsvReadOpenModal();
    if(m && m.id){
      var $m=$('#'+m.id);
      if($m.length){
        $m.addClass('open');
        lsvApplyFormDrafts();
      }
    }
  }, 350);
});


/* ══ TABS (Panels innerhalb Sections) ══════════════════════════ */

/* Findet den Container, dessen direkte Kinder die Panels einer Reiter-
   Gruppe sind. Nötig, weil Reiter mal direkt neben den Panels stehen,
   mal in einer Karte verpackt sind und mal als Unter-Reiter innerhalb
   eines Panels. */
function lsvPanelContainer($tabs, panelId){
  var $c=$tabs.parent();
  for(var i=0;i<6 && $c.length;i++){
    if($c.children('#'+panelId).length) return $c;
    if($c.is('body')) break;
    $c=$c.parent();
  }
  // Nichts gefunden: auf den Bereich zurückfallen
  var $sec=$tabs.closest('.i-sec');
  return $sec.length?$sec:$tabs.parent();
}

$(document).on('click','.i-tab',function(){
  var panelId=$(this).data('panel');
  var $tabs=$(this).closest('.i-tabs');
  $tabs.find('.i-tab').removeClass('on');
  $(this).addClass('on');
  // Container = der nächste Vorfahre, der das Ziel-Panel direkt enthält.
  // Fest $tabs.parent() zu nehmen bricht, sobald die Navigation in einer
  // Karte steckt oder Unter-Reiter verschachtelt sind.
  var $container=lsvPanelContainer($tabs,panelId);
  $container.children('.i-panel').css('display','none');
  $('#'+panelId).css('display','block');
  // Aktiven Tab merken (zusammen mit dem umgebenden Bereich)
  var $sec=$(this).closest('.i-sec');
  if($sec.length){
    var secId=($sec.attr('id')||'').replace('i-sec-','');
    if(secId) lsvSaveLocation(secId, panelId);
  }
  // Lazy-load für Triathlon-Admin-Tabs
  if(panelId==='i-p-adm-tri-gr'){renderTriGruppen();}
  if(panelId==='i-p-adm-tri-sp'){loadTriAdmSportler();}
  if(panelId==='i-p-adm-tri-tr'){renderTriTrainer();}
  if(panelId==='i-p-adm-tri-sl'){renderTriSlots();}
  // Zusammengeführte Admin-Tabs (Trainer/Mannschaften/Sportler/Zeiten mit Dropdown)
  if(panelId==='i-p-adm-atteste'){loadAtteste();}
  if(panelId==='i-p-adm-trainer'){showAdmSportPanel('trainer');}
  if(panelId==='i-p-adm-personen-tab'){loadAdmPersonen();}
  if(panelId==='i-p-adm-mannschaften'){showAdmSportPanel('mannschaften');}
  if(panelId==='i-p-adm-sportler'){showAdmSportPanel('sportler');}
  if(panelId==='i-p-adm-zeiten'){showAdmSportPanel('zeiten');}
  // Triathlonwart-Tab
  // Schwimmen: Trainer-Tab (Schwimmwart/Admin)
  if(panelId==='i-p-sw-trainer'){loadSwTrainer();}
  // Triathlon: Trainer-Übersicht (Triathlonwart/Admin)
  if(panelId==='i-p-tri-trainer-ub'){loadTriTrainerUb();}
  // Fitness: Trainer-Übersicht (Fitnesswart/Admin)
  if(panelId==='i-p-fit-trainer-ub'){loadFitTrainerUb();}
  // Verwaltung: Trainer-Tab
  if(panelId==='i-p-vw-trainer'){loadVwTrainer();}
  // Schwimmen: Wettkämpfe direkt laden
  if(panelId==='i-p-wk'){$('#wk-laden').trigger('click');}
  // Schwimmen: Wettkampfmeldungen
  if(panelId==='i-p-meld'){meldOeffnen();}
  // Schwimmen: Akte (Mannschafts-Filter einmalig vorladen)
  if(panelId==='i-p-akte'){loadAkteMannschaften();}
  // Trainer: eigene Sportler
  if(panelId==='i-p-tr-sportler'){loadTrSportler();}
  if(panelId==='i-p-adm-stammdaten'){loadAdmStammdaten();}
  if(panelId==='i-p-adm-log'){$('#log-laden').trigger('click');}
  if(panelId==='i-p-adm-saison'){loadSaisons();}
  if(panelId==='i-p-adm-sl'){loadAdmSl();}
  // Admin: Rechte-Verwaltung
  if(panelId==='i-p-adm-rechte'){loadRechte();}
});

/* ══ ADMIN: Zusammengeführte Sportart-Panels ════════════════════
   Die Tabs Trainer/Mannschaften/Sportler/Zeiten zeigen je ein Dropdown
   (Schwimmen/Triathlon/Fitness) und blenden das passende bestehende
   Detail-Panel ein. So bleibt die erprobte Lade-/Speicher-Logik der
   einzelnen Sportarten unverändert.                                  */
var ADM_PANEL_MAP={
  trainer:      {s:'i-p-adm-tr',  tri:'i-p-adm-tri-tr', fit:'i-p-adm-fit-tr'},
  mannschaften: {s:'i-p-adm-mn',  tri:'i-p-adm-tri-gr', fit:'i-p-adm-fit-gr'},
  sportler:     {s:'i-p-adm-sw',  tri:'i-p-adm-tri-sp', fit:'i-p-adm-fit-sp'},
  zeiten:       {s:'i-p-adm-sl',  tri:'i-p-adm-tri-sl', fit:'i-p-adm-fit-sl'}
};
// Merkt sich die zuletzt gewählte Sportart pro Gruppe
var ADM_SPORT_STATE={trainer:'s',mannschaften:'s',sportler:'s',zeiten:'s'};

function showAdmSportPanel(group){
  var sportart=ADM_SPORT_STATE[group]||'s';
  // Dropdown synchronisieren
  $('.i-sportart-sel[data-group="'+group+'"]').val(sportart);
  // Alle Detail-Panels dieser Gruppe ausblenden
  var map=ADM_PANEL_MAP[group];
  $.each(map,function(k,pid){$('#'+pid).css('display','none');});
  // Gewähltes Panel direkt hinter den Container-Div verschieben und zeigen
  var targetId=map[sportart];
  var $container=$('#i-p-adm-'+group);
  var $panel=$('#'+targetId);
  $panel.insertAfter($container.find('.i-sportfilter')).css('display','block');
  // passende Lade-Funktion triggern
  admLoadPanel(group,sportart);
}

function admLoadPanel(group,sportart){
  // Sicherstellen, dass die Sportart-Daten geladen sind (sonst nachladen)
  if(sportart==='tri' && (!S_tri.done)){ loadTriathlon(); }
  if(sportart==='fit' && (!S_fit.done)){ loadFitness(); }
  if(group==='trainer'){
    if(sportart==='s'){ renderAdmTr(); }
    else if(sportart==='tri'){ renderTriTrainer(); }
    else if(sportart==='fit'){ renderFitTrainer(); }
  } else if(group==='mannschaften'){
    if(sportart==='s'){ renderAdmMn(); }
    else if(sportart==='tri'){ renderTriGruppen(); }
    else if(sportart==='fit'){ renderFitGruppen(); }
  } else if(group==='sportler'){
    if(sportart==='s'){ renderAdmSw(''); }
    else if(sportart==='tri'){ loadTriAdmSportler(); }
    else if(sportart==='fit'){ loadFitAdmSportler(); }
  } else if(group==='zeiten'){
    if(sportart==='s'){ loadAdmSl(); }
    else if(sportart==='tri'){ renderTriSlots(); }
    else if(sportart==='fit'){ renderFitSlots(); }
  }
}

// Dropdown-Wechsel
$(document).on('change','.i-sportart-sel',function(){
  var group=$(this).data('group');
  ADM_SPORT_STATE[group]=$(this).val();
  showAdmSportPanel(group);
});

/* ══ ADMIN: PERSONEN ═════════════════════════════════════════════
   Eine Oberfläche für alle Einträge der zentralen Personen-Tabelle:
   Stammdaten, optionaler WordPress-Account, Sparten/Rollen und
   Mannschafts-Zuordnungen. */
var S_persAdm={items:[],wpUsers:[],mannschaften:null};

function loadPersMannschaften(cb){
  if(S_persAdm.mannschaften){cb&&cb(S_persAdm.mannschaften);return;}
  ajax('lsv07i_pers_get_mannschaften').done(function(r){
    if(r.success){S_persAdm.mannschaften=r.data;cb&&cb(r.data);}
    else cb&&cb({});
  }).fail(function(){cb&&cb({});});
}

function loadAdmPersonen(){
  skel($('#pers-liste'),'rows',5);
  ajax('lsv07i_pers_personen_list').done(function(r){
    if(!r||!r.success){$('#pers-liste').html('<div class="i-notice i-notice-r">'+esc((r&&r.data&&r.data.message)||'Fehler.')+'</div>');return;}
    S_persAdm.items=(r.data&&r.data.rows)||[];
    renderAdmPersonen();
  }).fail(function(xhr){$('#pers-liste').html('<div class="i-notice i-notice-r">'+esc(errMsg(xhr))+'</div>');});
}

function renderAdmPersonen(){
  var q=($('#pers-suche').val()||'').toLowerCase();
  var fSparte=$('#pers-f-sparte').val()||'';
  var fRolle=$('#pers-f-rolle').val()||'';
  var items=S_persAdm.items.filter(function(p){
    if(q){
      var hay=(p.vorname+' '+p.nachname+' '+(p.email||'')+' '+(p.dsv_id||'')
              +' '+(p.mitgliedsnummer||'')+' '+(p.wp_user_name||'')).toLowerCase();
      if(hay.indexOf(q)<0)return false;
    }
    if(fSparte||fRolle){
      var treffer=(p.sparten_rollen||[]).some(function(sr){
        return (!fSparte||sr.sparte===fSparte)&&(!fRolle||sr.rolle===fRolle);
      });
      if(!treffer)return false;
    }
    return true;
  });
  if(!items.length){
    $('#pers-liste').html('<span class="i-muted">Keine Personen gefunden.</span>');
    return;
  }
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr>'
    +'<th>Name</th><th>Geburtsdatum</th><th>Sparten / Rollen</th>'
    +'<th>Kontakt</th><th>WP-Account</th><th></th></tr></thead><tbody>';
  $.each(items,function(i,p){
    var sr=$.map(p.sparten_rollen||[],function(x){
      return '<span class="i-bdg i-bdg-b">'+esc(x.sparte+': '+x.rolle)+'</span>';
    }).join(' ');
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(p.nachname+', '+p.vorname)+'</strong>'
        +(parseInt(p.aktiv,10)===0?' <span class="i-bdg i-bdg-r">inaktiv</span>':'')+'</td>'
      +'<td data-label="Geburtsdatum">'+(p.geburtsdatum?de(p.geburtsdatum):'<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="Sparten / Rollen">'+(sr||'<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="Kontakt" style="font-size:12px">'+(esc(p.email||'')||'<span class="i-muted">–</span>')
        +(p.telefon?'<br>'+esc(p.telefon):'')+'</td>'
      +'<td data-label="WP-Account">'+(p.wp_user_id>0
        ? esc(p.wp_user_name||'')+(p.wp_user_login?' <span class="i-muted" style="font-size:11px">('+esc(p.wp_user_login)+')</span>':'')
        : '<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="" style="white-space:nowrap">'
        +'<button class="i-btn i-btn-g i-btn-sm pers-edit" data-id="'+p.id+'">Bearbeiten</button> '
        +'<button class="i-btn i-btn-r i-btn-sm pers-del" data-id="'+p.id+'" data-name="'+esc(p.vorname+' '+p.nachname)+'">Löschen</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#pers-liste').html(h);
  tableCollapse($('#pers-liste'),15);
}

$(document).on('input','#pers-suche',renderAdmPersonen);
$(document).on('change','#pers-f-sparte,#pers-f-rolle',renderAdmPersonen);

// WP-User-Dropdown füllen (lädt einmalig, danach gecacht)
function fillPersWpUsers(selectedId,cb){
  function build(){
    var $s=$('#mpers-wpuser');
    $s.find('option:not(:first-child)').remove();
    $.each(S_persAdm.wpUsers,function(i,u){
      // Belegte Accounts (außer dem aktuell gewählten) ausgrauen
      var belegt=u.belegt_von>0 && String(u.id)!==String(selectedId);
      var label=u.name+' ('+u.login+')'+(belegt?' – bereits zugeordnet':'');
      $s.append('<option value="'+u.id+'"'+(belegt?' disabled':'')+'>'+esc(label)+'</option>');
    });
    $s.val(selectedId?String(selectedId):'');
    if(cb)cb();
  }
  if(S_persAdm.wpUsers.length){build();return;}
  ajax('lsv07i_pers_wp_users').done(function(r){
    if(r.success){S_persAdm.wpUsers=(r.data&&r.data.users)||[];}
    build();
  }).fail(build);
}

// Mannschafts-Checkboxen für die aktuell angehakten Sparten aufbauen
function renderPersMannCb(vorbelegt){
  var alleSparten=S_persAdm.mannschaften||{};
  var aktiveSparten={};
  $('.mpers-sr:checked').each(function(){aktiveSparten[$(this).data('sparte')]=true;});
  if(!Object.keys(aktiveSparten).length){
    $('#mpers-mann').html('<span class="i-muted">Erst oben eine Sparte anhaken, dann erscheinen hier die Mannschaften.</span>');
    return;
  }
  var h='';
  ['schwimmen','triathlon','fitness'].forEach(function(sp){
    if(!aktiveSparten[sp])return;
    var liste=alleSparten[sp]||[];
    h+='<div class="mpers-mann-gruppe"><strong>'+esc(sp)+'</strong>';
    if(!liste.length){
      h+=' <span class="i-muted">keine Mannschaften vorhanden</span></div>';
      return;
    }
    h+='<div class="mpers-mann-chips">';
    liste.forEach(function(m){
      var an=vorbelegt.some(function(v){
        return v.sparte===sp && parseInt(v.mannschaft_id,10)===parseInt(m.id,10);
      });
      h+='<label class="mpers-chip"><input type="checkbox" class="mpers-mann-cb" data-sparte="'+sp+'" value="'+m.id+'"'
        +(an?' checked':'')+'> '+esc(m.name)+'</label>';
    });
    h+='</div></div>';
  });
  $('#mpers-mann').html(h);
}

// Sparte an-/abgehakt → Mannschaftsauswahl neu aufbauen, Auswahl behalten
$(document).on('change','.mpers-sr',function(){
  var aktuell=[];
  $('#mpers-mann .mpers-mann-cb:checked').each(function(){
    aktuell.push({sparte:$(this).data('sparte'),mannschaft_id:parseInt($(this).val(),10)});
  });
  loadPersMannschaften(function(){renderPersMannCb(aktuell);});
});

function mpersFormLeeren(){
  $('#mpers-id,#mpers-vorname,#mpers-nachname,#mpers-geburtsdatum,#mpers-email,'
    +'#mpers-telefon,#mpers-dsv,#mpers-mnr,#mpers-notizen').val('');
  $('#mpers-geschlecht').val('');
  $('#mpers-aktiv').prop('checked',true);
  $('.mpers-sr').prop('checked',false);
  $('#mpers-mann').html('<span class="i-muted">Erst oben eine Sparte anhaken, dann erscheinen hier die Mannschaften.</span>');
}

$(document).on('click','#pers-add',function(){
  mpersFormLeeren();
  $('#m-pers-adm-ttl').text('Person anlegen');
  fillPersWpUsers('',null);
  loadPersMannschaften();
  openModal('m-pers-adm');
});

$(document).on('click','.pers-edit',function(){
  var id=$(this).data('id');
  mpersFormLeeren();
  $('#m-pers-adm-ttl').text('Person bearbeiten');
  openModal('m-pers-adm');
  // Vollständigen Datensatz inkl. Zuordnungen nachladen
  ajax('lsv07i_pers_get',{id:id}).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)||'Person nicht gefunden.','err');closeModal('m-pers-adm');return;}
    var p=r.data;
    $('#mpers-id').val(p.id);
    $('#mpers-vorname').val(p.vorname);
    $('#mpers-nachname').val(p.nachname);
    $('#mpers-geburtsdatum').val(p.geburtsdatum||'');
    $('#mpers-geschlecht').val(p.geschlecht||'');
    $('#mpers-email').val(p.email||'');
    $('#mpers-telefon').val(p.telefon||'');
    $('#mpers-dsv').val(p.dsv_id||'');
    $('#mpers-mnr').val(p.mitgliedsnummer||'');
    $('#mpers-notizen').val(p.notes||'');
    $('#mpers-aktiv').prop('checked',parseInt(p.aktiv,10)!==0);
    $.each(p.sparten_rollen||[],function(i,sr){
      $('.mpers-sr[data-sparte="'+sr.sparte+'"][data-rolle="'+sr.rolle+'"]').prop('checked',true);
    });
    fillPersWpUsers(p.wp_user_id,null);
    loadPersMannschaften(function(){renderPersMannCb(p.mannschaften||[]);});
  }).fail(function(xhr){toast(errMsg(xhr),'err');closeModal('m-pers-adm');});
});

$(document).on('click','#mpers-save',function(){
  var vorname=$('#mpers-vorname').val().trim();
  var nachname=$('#mpers-nachname').val().trim();
  if(!vorname||!nachname){toast('Vor- und Nachname sind Pflicht.','err');return;}

  var sr=[];
  $('.mpers-sr:checked').each(function(){
    sr.push({sparte:$(this).data('sparte'),rolle:$(this).data('rolle')});
  });
  var mn=[];
  $('#mpers-mann .mpers-mann-cb:checked').each(function(){
    var sp=$(this).data('sparte');
    // Trainer in der Sparte → auch Trainer in der Mannschaft
    var rolle=$('.mpers-sr[data-sparte="'+sp+'"][data-rolle="trainer"]').is(':checked')?'trainer':'sportler';
    mn.push({sparte:sp,mannschaft_id:parseInt($(this).val(),10),rolle:rolle});
  });

  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_pers_save',{
    id:$('#mpers-id').val(),
    vorname:vorname,
    nachname:nachname,
    geburtsdatum:$('#mpers-geburtsdatum').val(),
    geschlecht:$('#mpers-geschlecht').val(),
    email:$('#mpers-email').val(),
    telefon:$('#mpers-telefon').val(),
    dsv_id:$('#mpers-dsv').val(),
    mitgliedsnummer:$('#mpers-mnr').val(),
    notes:$('#mpers-notizen').val(),
    aktiv:$('#mpers-aktiv').is(':checked')?1:0,
    wp_user_id:$('#mpers-wpuser').val()||0,
    sparten_rollen_json:JSON.stringify(sr),
    mannschaften_json:JSON.stringify(mn)
  }).done(function(r){
    if(r.success){
      closeModal('m-pers-adm');
      lsvClearFormDraft(['mpers-vorname','mpers-nachname','mpers-email','mpers-telefon']);
      toast('Person gespeichert.');
      S_persAdm.wpUsers=[];
      loadAdmPersonen();
    } else toast((r.data&&r.data.message)||'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');})
    .always(function(){$b.prop('disabled',false).text('Speichern');});
});

$(document).on('click','.pers-del',function(){
  var id=$(this).data('id');var name=$(this).data('name');
  if(!confirm('Person "'+name+'" wirklich löschen?\n\nEin verknüpfter WordPress-Account bleibt bestehen, nur die Personen-Verknüpfung wird entfernt.'))return;
  ajax('lsv07i_pers_delete',{id:id}).done(function(r){
    if(r.success){toast('Person gelöscht.');S_persAdm.wpUsers=[];loadAdmPersonen();}
    else toast((r.data&&r.data.message)||'Löschen fehlgeschlagen.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});


/* ══ SCHWIMMEN ══════════════════════════════════════════════════ */
function loadSchwimmen(){
  ajax('lsv07i_schwimmen_get_data').done(function(r){
    if(!r.success)return;
    S.mann=r.data.mannschaften||[];
    S.slots=r.data.slots||[];
    renderMann('');
    fillSel($('#mv-filter'),S.mann,'id','name','Alle Mannschaften');
    fillSel($('#anw-fmann'),S.mann,'id','name','Alle');
    fillSel($('#stat-mann'),S.mann,'id','name','Alle Mannschaften');
    fillSel($('#spr-mann-filter'),S.mann,'id','name','Alle Mannschaften');
    fillSel($('#wk-f-mann'),S.mann,'id','name','Alle');
    fillSel($('#wkstat-mann'),S.mann,'id','name','Alle Mannschaften');
    fillSel($('#refl-mann'),S.mann,'id','name','Mannschaft wählen…');
    fillAnwSlots();
    loadBzMannschaften();
    // Wettkampfliste direkt laden (auch wenn der Tab noch nicht aktiv ist)
    $('#wk-laden').trigger('click');
  });
}

// Mannschaft
function renderMann(filter){
  var liste=filter?$.grep(S.mann,function(m){return String(m.id)===String(filter);}):S.mann;
  if(!liste.length){$('#mv-liste').html('<div class="i-spin">Keine Mannschaften gefunden.</div>');return;}
  var h='';
  $.each(liste,function(i,m){
    h+='<div class="i-card sw-mann-karte">'
      +'<div class="i-card-hd sw-mann-hd"><span>'+esc(m.name)+'</span>'
      +'<span class="sw-mann-anzahl" id="sw-anz-'+m.id+'">…</span></div>'
      +'<div class="i-card-bd" id="sw-m-'+m.id+'"></div></div>';
  });
  $('#mv-liste').html(h);
  $.each(liste,function(i,m){ skel($('#sw-m-'+m.id),'rows',3); });

  $.each(liste,function(i,m){
    ajax('lsv07i_schwimmen_get_schwimmer',{mannschaft_id:m.id}).done(function(r){
      if(!r||!r.success){$('#sw-m-'+m.id).html('<div class="i-muted">Konnte nicht geladen werden.</div>');return;}
      var sw=r.data||[];
      $('#sw-anz-'+m.id).text(sw.length===1?'1 Schwimmer':sw.length+' Schwimmer');
      if(!sw.length){$('#sw-m-'+m.id).html('<div class="i-muted">Noch keine Schwimmer zugeordnet.</div>');return;}

      // Bewusst schlank: Name und Attest-Ablauf. Alles Weitere steht im
      // Profil, das sich mit einem Tippen auf die Zeile öffnet.
      var t='<div class="sw-liste">';
      $.each(sw,function(j,s){
        t+='<div class="sw-zeile" data-sid="'+s.id+'" role="button" tabindex="0"'
          +' title="Profil von '+esc(s.first_name+' '+s.last_name)+' öffnen">'
          +'<span class="sw-zeile-name">'+esc(s.last_name+', '+s.first_name)+'</span>'
          +swAttest(s)
          +'<svg class="sw-zeile-pfeil" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
          +' stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
          +'<polyline points="9 18 15 12 9 6"/></svg>'
          +'</div>';
      });
      t+='</div>';
      $('#sw-m-'+m.id).html(t);
    }).fail(function(){
      $('#sw-m-'+m.id).html('<div class="i-muted">Konnte nicht geladen werden.</div>');
    });
  });
}

/* Attest-Ablauf als Punkt + Datum. Der Punkt zeigt den Zustand auf einen
   Blick, ohne dass eine Beschriftung Platz kostet — wichtig auf dem Handy. */
function swAttest(s){
  if(!s.attest_expires){
    return '<span class="sw-zeile-attest sw-attest-fehlt" title="Kein Attest hinterlegt">'
      +'<span class="sw-attest-punkt"></span><span class="sw-attest-datum">kein Attest</span></span>';
  }
  var klasse=s.attest_status==='abgelaufen'?'sw-attest-abgelaufen'
            :s.attest_status==='laeuft_ab' ?'sw-attest-laeuft'
            :'sw-attest-gueltig';
  var titel =s.attest_status==='abgelaufen'?'Attest abgelaufen'
            :s.attest_status==='laeuft_ab' ?'Attest läuft bald ab'
            :'Attest gültig';
  return '<span class="sw-zeile-attest '+klasse+'" title="'+titel+' bis '+de(s.attest_expires)+'">'
    +'<span class="sw-attest-punkt"></span>'
    +'<span class="sw-attest-datum">'+de(s.attest_expires)+'</span></span>';
}

// Zeile öffnet das Schwimmer-Profil (Maus und Tastatur)
$(document).on('keydown','.sw-zeile',function(e){
  if(e.which===13||e.which===32){ e.preventDefault(); $(this).trigger('click'); }
});
$('#mv-filter').on('change',function(){renderMann($(this).val());});

// Anwesenheit-Mannschafts-Dropdown füllen
function fillAnwSlots(){
  var $s=$('#anw-mann');
  if(!$s.length)return;
  $s.find('option:not(:first-child)').remove();
  // Für jeden Trainer nur die Mannschaften, für die er zuständig ist.
  // Admin/Schwimmwart bekommen alle. Wir nutzen S.slots als Quelle
  // (enthält für den aktuellen User die relevanten Mannschaften).
  var seen={};
  $.each(S.slots||[],function(i,s){
    if(seen[s.mannschaft_id])return;
    seen[s.mannschaft_id]=1;
    var label=esc(s.mannschaft_name)+(s.ist_springer_slot?' (Springer)':'');
    $s.append('<option value="'+s.mannschaft_id+'"'+(s.ist_springer_slot?' style="color:#5b94ff"':'')+'>'+label+'</option>');
  });
}

var curSid=null;

$('#anw-laden').on('click',function(){
  var mann=$('#anw-mann').val(),dat=$('#anw-datum').val();
  if(!mann||!dat){toast('Bitte Mannschaft und Datum wählen.','err');return;}
  var $b=$(this).prop('disabled',true).text('Laden…');
  ajax('lsv07i_anw_save_session',{mannschaft_id:mann,datum:dat}).done(function(r){
    if(!r.success){toast(r.data.message,'err');return;}
    curSid=r.data.session_id;ladeSession(curSid);
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Laden');});
});

$('#anw-ausfall').on('click',function(){
  var mann=$('#anw-mann').val(),dat=$('#anw-datum').val();
  if(!mann||!dat){toast('Bitte Mannschaft und Datum wählen.','err');return;}
  var g=prompt('Grund (optional):')||'';
  ajax('lsv07i_anw_mark_ausfall',{mannschaft_id:mann,datum:dat,ausgefallen:1,grund:g}).done(function(r){
    if(r.success){
      toast('Training als ausgefallen markiert.');
      if(curSid)ladeSession(curSid);
    }else toast(r.data.message,'err');
  });
});

// Admin: Alle Sessions endgültig löschen
$('#anw-alle-loeschen').on('click',function(){
  var mann=$('#anw-fmann').val();
  var monat=$('#anw-fmonat').val();
  var info=monat?(mann?'für die gewählte Mannschaft im Monat '+monat:'im Monat '+monat):( mann?'für die gewählte Mannschaft':' ALLE Trainings (aller Mannschaften!)');
  if(!confirm('Wirklich alle Sessions (inkl. heute) '+info+' endgültig löschen?\n\nDieser Vorgang kann nicht rückgängig gemacht werden!'))return;
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_anw_delete_alle_sessions',{mannschaft_id:mann,monat:monat}).done(function(r){
    if(r.success){toast(r.data&&r.data.count?r.data.count+' Sessions gelöscht.':'Gelöscht.');$('#anw-suchen').trigger('click');}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Alle Sessions löschen');});
});

// Ausfall rückgängig machen (Button erscheint im Formular wenn Session ausgefallen ist)
$(document).on('click','#anw-ausfall-rueckgaengig',function(){
  var slot=$(this).data('slot'),datum=$(this).data('datum');
  if(!confirm('Ausfall-Markierung für '+de(datum)+' wirklich aufheben?'))return;
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_anw_mark_ausfall',{slot_id:slot,datum:datum,ausgefallen:0}).done(function(r){
    if(r.success){
      toast('Ausfall-Markierung aufgehoben.');
      if(curSid)ladeSession(curSid);
    }else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Rückgängig');});
});

function ladeSession(id){
  skel($('#anw-eintraege'),'rows',6);
  $('#anw-form').css('display','block');
  // Statistik-Karte nach oben schieben (nach dem Form)
  $('#anw-stat-card').insertAfter('#anw-form');
  ajax('lsv07i_anw_get_session',{id:id}).done(function(r){
    if(!r.success){toast(r.data.message,'err');return;}
    renderAnwForm(r.data);
  });
}

function renderAnwForm(data){
  var session=data.session,schwimmer=data.schwimmer,trainer=data.trainer,eintraege=data.eintraege;
  // Kommentar zum Training in das Textfeld setzen (kann leer sein)
  $('#anw-kommentar').val(session.kommentar||'');
  var sm={};
  $.each(eintraege,function(i,e){sm[e.teilnehmer_typ+'_'+e.teilnehmer_id]=e.status;});
  if(session.ausgefallen==1){
    var grund=session.ausfall_grund?'<div style="font-size:12px;color:#b45309;margin-top:4px">Grund: '+esc(session.ausfall_grund)+'</div>':'';
    $('#anw-eintraege').html(
      '<div class="i-notice i-notice-y" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">'
      +'<div><strong>Training ist als ausgefallen markiert.</strong>'+grund+'</div>'
      +'<button id="anw-ausfall-rueckgaengig" class="i-btn i-btn-g i-btn-sm" data-slot="'+session.slot_id+'" data-datum="'+session.training_datum+'" style="flex-shrink:0">Rückgängig</button>'
      +'</div>'
    );
    $('#anw-save').hide();return;
  }
  var h='';
  if(trainer.length){
    h+='<div style="font-size:11px;font-weight:700;color:#2a5fd0;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">Trainer</div>';
    $.each(trainer,function(i,t){
      if(t.ausgeschlossen){
        h+=anwRowAusgeschlossen(t,curSid);
      }else{
        h+=anwRow('trainer',t.id,t.name,sm['trainer_'+t.id]||'abwesend',true,t.is_springer,curSid);
      }
    });
  }
  if(schwimmer.length){
    h+='<div style="font-size:11px;font-weight:700;color:#2a5fd0;text-transform:uppercase;letter-spacing:.4px;margin-top:12px;margin-bottom:8px">Schwimmer</div>';
    h+='<div class="anw-grid">';
    $.each(schwimmer,function(i,s){h+=anwTile('schwimmer',s.id,s.last_name+', '+s.first_name,sm['schwimmer_'+s.id]||'abwesend');});
    h+='</div>';
  }
  if(!h)h='<div class="i-spin">Keine Teilnehmer.</div>';
  $('#anw-eintraege').html(h);$('#anw-save').show();
  anwSaveStatus('saved');
}

// ── Anwesenheits-Status-Styling ──────────────────────────────────
// Status → {icon, Farben}
var ANW_STIL={
  anwesend:  {icon:'✓', bg:'#d1fae5', border:'#059669', text:'#065f46'},
  abwesend:  {icon:'✗', bg:'#fee2e2', border:'#b91c1c', text:'#b91c1c'},
  entschuldigt:{icon:'E',bg:'#fef3c7', border:'#d97706', text:'#b45309'}
};

// Trainer-Zeile (kompakt)
function anwRow(typ,id,name,status,isTr,isSpr,anwId){
  var cur=status||'abwesend';
  var spr=isSpr?' '+bdg('b','Springer'):'';
  var tc=isTr?' i-an-tr':'';
  var isAdmin=LSV07I.access&&(LSV07I.access.is_admin_raw||LSV07I.access.is_schwimmwart);
  function btn(s){
    var st=ANW_STIL[s];
    var aktiv=cur===s;
    return'<button class="i-ab-v2'+(aktiv?' aktiv':'')+'" data-typ="'+typ+'" data-id="'+id+'" data-st="'+s+'" '
      +'style="flex:1;min-width:36px;padding:6px 2px;border:2px solid '+(aktiv?st.border:'#cbd5e1')+';background:'+(aktiv?st.bg:'#f8fafc')+';border-radius:6px;cursor:pointer;font-size:18px;color:'+(aktiv?st.text:'#5b6072')+';font-weight:'+(aktiv?'700':'400')+';transition:all .12s;touch-action:manipulation" title="'+s+'">'+st.icon+'</button>';
  }
  var exclBtn=isAdmin&&anwId
    ?'<button class="i-btn i-btn-r i-btn-sm anw-trainer-excl" data-tid="'+id+'" data-anwid="'+anwId+'" style="font-size:11px;padding:3px 8px;margin-left:8px;flex-shrink:0" title="Trainer aus dieser Session ausschließen">Ausschl.</button>'
    :'';
  return'<div class="i-ar"><div class="i-an'+tc+'">'+esc(name)+spr+exclBtn+'</div><div class="i-abs">'+btn('anwesend')+btn('abwesend')+btn('entschuldigt')+'</div></div>';
}

// Ausgeschlossener Trainer — zeigt Wiedereinfügen-Button
function anwRowAusgeschlossen(t,anwId){
  var isAdmin=LSV07I.access&&(LSV07I.access.is_admin_raw||LSV07I.access.is_schwimmwart);
  var inclBtn=isAdmin&&anwId
    ?'<button class="i-btn i-btn-g i-btn-sm anw-trainer-incl" data-tid="'+t.id+'" data-anwid="'+anwId+'" style="font-size:11px;padding:3px 8px;flex-shrink:0">Einschließen</button>'
    :'';
  return'<div class="i-ar" style="opacity:.5"><div class="i-an i-an-tr" style="text-decoration:line-through">'+esc(t.name)+' <span style="font-size:11px;color:#b91c1c">ausgeschlossen</span>'+inclBtn+'</div><div class="i-abs"></div></div>';
}

// Schwimmer-Kachel (groß)
function anwTile(typ,id,name,status){
  var cur=status||'abwesend';
  function btn(s){
    var st=ANW_STIL[s];
    var aktiv=cur===s;
    return'<button class="i-ab-v2'+(aktiv?' aktiv':'')+'" data-typ="'+typ+'" data-id="'+id+'" data-st="'+s+'" '      +'style="flex:1;padding:8px 4px;border:2px solid '+(aktiv?st.border:'#cbd5e1')+';background:'+(aktiv?st.bg:'#f8fafc')+';border-radius:7px;cursor:pointer;font-size:22px;color:'+(aktiv?st.text:'#5b6072')+';font-weight:'+(aktiv?'700':'400')+';transition:all .12s;touch-action:manipulation" title="'+s+'">'+st.icon+'</button>';
  }
  return'<div class="anw-tile" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.15);border-radius:10px;padding:10px">'
    +'<div style="font-size:12px;font-weight:600;color:#1c1d2e;margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="'+esc(name)+'">'+esc(name)+'</div>'
    +'<div style="display:flex;gap:5px">'+btn('anwesend')+btn('abwesend')+btn('entschuldigt')+'</div>'
    +'</div>';
}


// Trainer aus Session ausschließen
$(document).on('click','.anw-trainer-excl',function(e){
  e.stopPropagation();
  var tid=$(this).data('tid'),anwId=$(this).data('anwid');
  var trainerName=$(this).closest('.i-an').clone().children('button').remove().end().text().trim();
  var msg='Trainer "'+trainerName+'" dauerhaft aus ALLEN Trainings dieses Slots ausschließen?\n\n(Gilt für jeden '+WD[0]+'-Termin dieser Mannschaft)\n\nRükgängig: Beim nächsten Training auf "Einschließen" klicken.';
  if(!confirm(msg))return;
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_anw_trainer_ausschliessen',{anwesenheit_id:anwId,trainer_id:tid}).done(function(r){
    if(r.success){
      var info=r.data&&r.data.slot_info?' für '+r.data.slot_info:'';
      toast('Trainer ausgeschlossen'+info+'.');
      if(curSid)ladeSession(curSid);
    }else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Ausschl.');});
});

// Trainer wieder einschließen
$(document).on('click','.anw-trainer-incl',function(e){
  e.stopPropagation();
  var tid=$(this).data('tid'),anwId=$(this).data('anwid');
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_anw_trainer_einschliessen',{anwesenheit_id:anwId,trainer_id:tid}).done(function(r){
    if(r.success){toast('Trainer wieder eingeschlossen.');if(curSid)ladeSession(curSid);}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Einschließen');});
});

// Anwesenheits-Button-Klick: nur einer aktiv, nochmal klicken = abwählen (dann Standard anwesend)
$(document).on('click','.i-ab-v2',function(){
  var $btn=$(this);
  var $gruppe=$btn.closest('.i-abs,.anw-tile').find('.i-ab-v2');
  var wasAktiv=$btn.hasClass('aktiv');

  // Alle deaktivieren
  $gruppe.each(function(){
    var s=$(this).data('st');
    var st=ANW_STIL[s];
    $(this).removeClass('aktiv').css({
      'border-color':'#cbd5e1',
      'background':'#f8fafc',
      'color':'#5b6072',
      'font-weight':'400'
    });
  });

  // Wenn war aktiv → auf "abwesend" zurücksetzen (Standard)
  var ziel=wasAktiv?'abwesend':$btn.data('st');
  var $ziel=$gruppe.filter('[data-st="'+ziel+'"]');
  var st=ANW_STIL[ziel];
  $ziel.addClass('aktiv').css({
    'border-color':st.border,
    'background':st.bg,
    'color':st.text,
    'font-weight':'700'
  });

  // Auto-Save: direkt einzelnen Eintrag persistieren
  if(curSid){
    anwSaveStatus('saving');
    ajax('lsv07i_anw_save_eintrag',{
      anwesenheit_id:curSid,
      typ:$ziel.data('typ'),
      teilnehmer_id:$ziel.data('id'),
      status:ziel
    }).done(function(r){
      if(r&&r.success){anwSaveStatus('saved');}
      else{anwSaveStatus('error',r&&r.data&&r.data.message);}
    }).fail(function(xhr){
      anwSaveStatus('error',errMsg(xhr));
    });
  }
});

// Status-Indikator-Helper für Anwesenheit
var anwSaveStatusTimer;
function anwSaveStatus(state, msg){
  var $s=$('#anw-save-status');
  if(!$s.length)return;
  var color='#94a3b8', text='Noch nichts geändert';
  if(state==='dirty'){color='#f59e0b'; text='Ungespeichert…';}
  else if(state==='saving'){color='#5b94ff'; text='Speichert…';}
  else if(state==='saved'){color='#10b981'; text='✓ Gespeichert';}
  else if(state==='error'){color='#ef4444'; text='✗ Fehler'+(msg?': '+msg:'');}
  $s.html('<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:'+color+';vertical-align:middle"></span> <span class="txt">'+esc(text)+'</span>');
  // Nach 2 Sek den "✓ Gespeichert"-State auf neutral zurücksetzen
  if(state==='saved'){
    clearTimeout(anwSaveStatusTimer);
    anwSaveStatusTimer=setTimeout(function(){
      $s.html('<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;vertical-align:middle"></span> <span class="txt">Alles gespeichert</span>');
    },2000);
  }
}

// Auto-Save Kommentar (debounced)
var anwKommentarTimer;
$(document).on('input','#anw-kommentar',function(){
  clearTimeout(anwKommentarTimer);
  anwSaveStatus('dirty');
  var mann=$('#anw-mann').val(), dat=$('#anw-datum').val();
  var komm=($(this).val()||'').trim();
  if(!mann||!dat)return;
  anwKommentarTimer=setTimeout(function(){
    anwSaveStatus('saving');
    ajax('lsv07i_anw_save_session',{mannschaft_id:mann,datum:dat,kommentar:komm}).done(function(r){
      if(r&&r.success){anwSaveStatus('saved');}
      else{anwSaveStatus('error',r&&r.data&&r.data.message);}
    }).fail(function(xhr){anwSaveStatus('error',errMsg(xhr));});
  },600);
});

$('#anw-save').on('click',function(){
  // Erkennen ob Wettkampf- oder Training-Modus
  var isWk = curWkAnwId && !curSid;
  if(!curSid && !curWkAnwId)return;
  var $b=$(this).prop('disabled',true).text('Speichert…');
  anwSaveStatus('saving');
  var reqs=[];

  if(isWk){
    // Wettkampf-Modus: alle Status-Buttons mit aktivem Font-Weight finden
    $('#anw-eintraege .i-abs, #anw-eintraege .anw-tile').each(function(){
      var $aktiv=$(this).find('.i-ab-wk').filter(function(){
        return $(this).css('font-weight')==='700';
      });
      if(!$aktiv.length)return;
      reqs.push(ajax('lsv07i_wk_anw_save_eintrag',{
        wk_anwesenheit_id:curWkAnwId,
        typ:$aktiv.data('typ'),
        teilnehmer_id:$aktiv.data('id'),
        status:$aktiv.data('st')
      }));
    });
    // Trainer-Abschnitte mitspeichern
    $('.wk-tr-abs').each(function(){
      reqs.push(ajax('lsv07i_wk_anw_save_abschnitte',{
        wk_anwesenheit_id:curWkAnwId,
        trainer_id:$(this).data('tid'),
        abschnitte:parseInt($(this).val(),10)||0
      }));
    });
  } else {
    // Training-Modus: alle aktiven .i-ab-v2-Buttons finden
    $('#anw-eintraege .i-abs, #anw-eintraege .anw-tile').each(function(){
      var $aktiv=$(this).find('.i-ab-v2.aktiv');
      if(!$aktiv.length)return;
      reqs.push(ajax('lsv07i_anw_save_eintrag',{anwesenheit_id:curSid,typ:$aktiv.data('typ'),teilnehmer_id:$aktiv.data('id'),status:$aktiv.data('st')}));
    });
    // Kommentar zum Training mitspeichern
    var mann=$('#anw-mann').val(),dat=$('#anw-datum').val();
    var kommentar=($('#anw-kommentar').val()||'').trim();
    if(mann&&dat){
      reqs.push(ajax('lsv07i_anw_save_session',{mannschaft_id:mann,datum:dat,kommentar:kommentar}));
    }
  }

  if(!reqs.length){
    toast('Keine Einträge gefunden.','err');
    $b.prop('disabled',false).text('Jetzt speichern');
    anwSaveStatus('saved');
    return;
  }
  $.when.apply($,reqs).done(function(){
    toast('Anwesenheit gespeichert.');
    anwSaveStatus('saved');
  }).fail(function(xhr){
    toast(errMsg(xhr),'err');
    anwSaveStatus('error',errMsg(xhr));
  }).always(function(){$b.prop('disabled',false).text('Jetzt speichern');});
});;

$('#anw-suchen').on('click',function(){
  skel($('#anw-sessions'),'rows',4);
  ajax('lsv07i_anw_get_sessions',{mannschaft_id:$('#anw-fmann').val(),monat:$('#anw-fmonat').val()}).done(function(r){
    if(!r.success)return;
    if(!r.data.length){$('#anw-sessions').html('<div class="i-spin">Keine Sessions.</div>');return;}
    var h='';
    var isAdmin=LSV07I.access&&LSV07I.access.is_admin;
    var today=heuteLokal();
    $.each(r.data,function(i,s){
      var af=s.ausgefallen==1?' '+bdg('r','Ausgefallen'):'';
      var isPast=s.training_datum<today;
      var delBtn=isAdmin?'<button class="i-btn i-btn-r i-btn-sm anw-del-session" data-id="'+s.id+'" style="font-size:11px;margin-top:4px">Löschen</button>':'';
      // Kommentar zum Training (falls gesetzt)
      var komHtml='';
      if(s.kommentar&&String(s.kommentar).trim().length){
        var kom=String(s.kommentar).trim();
        var kurz=kom.length>140?kom.slice(0,140)+'…':kom;
        komHtml='<div class="anw-kom" style="margin-top:6px;padding:6px 10px;background:var(--c-blue-soft);border-left:3px solid var(--c-blue);border-radius:6px;font-size:12px;color:var(--c-text-2);white-space:pre-wrap" data-full="'+esc(kom)+'">'
              +'<strong style="font-size:11px;text-transform:uppercase;color:var(--c-blue);letter-spacing:.04em">Kommentar</strong><br>'
              +esc(kurz);
        if(kom.length>140){
          komHtml+=' <a href="#" class="anw-kom-more" style="font-size:11px">Mehr</a>';
        }
        komHtml+='</div>';
      }
      h+='<div class="i-si" data-id="'+s.id+'">'
        +'<div style="font-weight:600;font-size:13px">'+de(s.training_datum)+' – '+esc(s.mannschaft_name)+af+'</div>'
        +'<div style="font-size:12px;color:#6b6e85">'+String(s.zeit_von||'').slice(0,5)+'-'+String(s.zeit_bis||'').slice(0,5)+'</div>'
        +komHtml
        +delBtn+'</div>';
    });
    $('#anw-sessions').html(h);
  });
});

// Kommentar in der Statistik aufklappen
$(document).on('click','.anw-kom-more',function(e){
  e.preventDefault();e.stopPropagation();
  var $box=$(this).closest('.anw-kom');
  var full=$box.data('full')||'';
  $box.html('<strong style="font-size:11px;text-transform:uppercase;color:var(--c-blue);letter-spacing:.04em">Kommentar</strong><br>'+esc(full));
});
$(document).on('click','.i-si',function(e){
  if($(e.target).closest('button').length)return;
  curSid=$(this).data('id');ladeSession(curSid);$('#anw-form')[0].scrollIntoView({behavior:'smooth'});
});
$(document).on('click','.anw-del-session',function(e){
  e.stopPropagation();
  var id=$(this).data('id');
  var datum=$(this).closest('.i-si').find('div:first-child').text().split('–')[0].trim();
  if(!confirm('Session vom '+datum+' endgültig löschen?\n\nAlle Anwesenheitseinträge werden unwiderruflich gelöscht.'))return;
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_anw_delete_session',{id:id}).done(function(r){
    if(r.success){
      toast('Session gelöscht.');
      if(curSid==id){curSid=null;$('#anw-form').css('display','none');}
      $('#anw-suchen').trigger('click');
    }else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Löschen');});
});

$('#stat-laden').on('click',function(){
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_anw_get_statistik',{mannschaft_id:$('#stat-mann').val(),von:$('#stat-von').val(),bis:$('#stat-bis').val()}).done(function(r){
    if(!r.success)return;
    var sg=r.data.sessions_gesamt,sw=r.data.schwimmer||[],koms=r.data.kommentare||[];
    if(!sw.length){$('#stat-content').html('<span class="i-muted">Keine Daten.</span>');return;}
    var h='<span class="i-muted">Trainings gesamt (im Filter): <strong>'+sg+'</strong></span>';
    // Kommentare zu den Trainings prominent anzeigen, falls vorhanden
    if(koms.length){
      h+='<div style="margin:14px 0 10px">'
        +'<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">'
        +'<span style="font-size:11px;font-weight:700;color:var(--c-blue);text-transform:uppercase;letter-spacing:.06em">'
        +'💬 Kommentare zu den Trainings ('+koms.length+')</span>'
        +'<button type="button" class="i-btn i-btn-sm" id="stat-kom-toggle" style="font-size:11px;padding:2px 8px">Ausblenden</button>'
        +'</div>'
        +'<div id="stat-kom-list" style="display:flex;flex-direction:column;gap:6px;max-height:320px;overflow-y:auto;padding-right:4px">';
      $.each(koms,function(i,k){
        var zeit=k.zeit_von?String(k.zeit_von).slice(0,5)+'–'+String(k.zeit_bis||'').slice(0,5):'';
        h+='<div style="padding:10px 14px;background:var(--c-blue-soft);border-left:4px solid var(--c-blue);border-radius:8px">'
          +'<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:4px">'
          +'<strong style="color:var(--c-text);font-size:13px">'+esc(k.mannschaft_name||'')+'</strong>'
          +'<span style="font-size:12px;color:var(--c-text-3)">'+de(k.training_datum)+(zeit?' · '+zeit:'')+'</span>'
          +'</div>'
          +'<div style="font-size:13px;color:var(--c-text-2);white-space:pre-wrap;line-height:1.5">'+esc(k.kommentar)+'</div>'
          +'</div>';
      });
      h+='</div></div>';
    }
    h+='<div class="i-twrap" style="margin-top:8px"><table class="i-tbl"><thead><tr><th>Name</th><th>Anwesend</th><th>Abwesend</th><th>Entschuldigt</th><th>Quote</th><th></th></tr></thead><tbody>';
    $.each(sw,function(i,s){
      var eigene=s.gesamt||0;
      var q=eigene>0?Math.round(s.anwesend/eigene*100):0;
      var qc=q>=75?'g':q>=50?'a':'r';
      h+='<tr><td data-label="Name">'+esc(s.name)+'</td><td data-label="Anwesend">'+bdg('g',s.anwesend)+'</td><td data-label="Abwesend">'+bdg('r',s.abwesend)+'</td><td data-label="Entschuldigt">'+bdg('a',s.entschuldigt)+'</td><td data-label="Quote">'+bdg(qc,q+'%')+' <span style="font-size:11px;color:#6b6e85">von '+eigene+'</span></td><td data-label=""><div class="i-bw"><div class="i-bv" style="width:'+q+'%"></div></div></td></tr>';
    });
    h+='</tbody></table></div>';$('#stat-content').html(h);
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Laden');});
});

// Kommentar-Liste in Statistik aus-/einblenden
$(document).on('click','#stat-kom-toggle',function(){
  var $list=$('#stat-kom-list');
  if($list.is(':visible')){$list.slideUp(150);$(this).text('Einblenden');}
  else{$list.slideDown(150);$(this).text('Ausblenden');}
});

// ═══════════════════════════════════════════════════════════════════════
//  WETTKAMPF-MODUL
// ═══════════════════════════════════════════════════════════════════════

// ── Typ-Switch in der Anwesenheit (Training ⇄ Wettkampf) ───────────────
var anwTyp='training';
$(document).on('click','.anw-typ-btn',function(){
  var typ=$(this).data('typ');
  anwTyp=typ;
  $('.anw-typ-btn').removeClass('aktiv').removeClass('i-btn-p').addClass('i-btn-g');
  $(this).addClass('aktiv').removeClass('i-btn-g').addClass('i-btn-p');
  if(typ==='training'){
    $('#anw-typ-training').show();$('#anw-typ-wettkampf').hide();
  }else{
    $('#anw-typ-training').hide();$('#anw-typ-wettkampf').show();
    wkLadeDropdown();
  }
  // Bestehende Form ausblenden, Statistik bleibt
  $('#anw-form').hide();curSid=null;curWkAnwId=null;
});

// ── Wettkampf-Anwesenheit: Dropdown befüllen ───────────────────────────
function wkLadeDropdown(){
  var $s=$('#wkanw-wk');
  $s.html('<option value="">Wettkampf wählen…</option>');
  ajax('lsv07i_wk_list',{jahr:new Date().getFullYear(),nur_eigene:1}).done(function(r){
    if(!r.success)return;
    $.each(r.data,function(i,w){
      $s.append('<option value="'+w.id+'">'+esc(w.name)+' ('+de(w.datum_von)+(w.datum_bis!==w.datum_von?' – '+de(w.datum_bis):'')+')</option>');
    });
  });
}
$('#wkanw-wk').on('change',function(){
  var wid=$(this).val();
  $('#wkanw-mann').html('<option value="">Mannschaft wählen…</option>');
  $('#wkanw-tag').html('<option value="">Tag wählen…</option>');
  if(!wid)return;
  ajax('lsv07i_wk_get',{id:wid}).done(function(r){
    if(!r.success)return;
    $.each(r.data.mannschaften,function(i,m){
      $('#wkanw-mann').append('<option value="'+m.id+'">'+esc(m.name)+'</option>');
    });
    $.each(r.data.tage,function(i,t){
      $('#wkanw-tag').append('<option value="'+t.id+'">'+de(t.datum)+' ('+t.abschnitte_plan+' Abschn.)</option>');
    });
  });
});

var curWkAnwId=null;
$('#wkanw-laden').on('click',function(){
  var tag=$('#wkanw-tag').val(),mann=$('#wkanw-mann').val();
  if(!tag||!mann){toast('Bitte Wettkampftag und Mannschaft wählen.','err');return;}
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_wk_anw_get',{wettkampf_tag_id:tag,mannschaft_id:mann}).done(function(r){
    if(!r.success){toast(r.data.message,'err');return;}
    curWkAnwId=r.data.anwesenheit.id;
    renderWkAnwForm(r.data);
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Laden');});
});

function renderWkAnwForm(d){
  var anw=d.anwesenheit,tag=d.tag,mann=d.mannschaft,trainer=d.trainer,schwimmer=d.schwimmer,eintraege=d.eintraege;
  var sm={},abM={};
  $.each(eintraege,function(i,e){
    sm[e.teilnehmer_typ+'_'+e.teilnehmer_id]=e.status;
    if(e.teilnehmer_typ==='trainer')abM[e.teilnehmer_id]=parseInt(e.abschnitte,10)||0;
  });
  var plan=parseInt(tag.abschnitte_plan,10)||1;
  var titel='<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">'
    +'<div><strong>'+esc(tag.wettkampf_name)+'</strong> – '+esc(mann.name)+'</div>'
    +'<div style="font-size:12px;color:#6b6e85">'+de(tag.datum)+' · '+plan+' Abschnitte geplant · '+parseFloat(tag.pauschale).toFixed(2)+' €/Abschnitt</div>'
    +'</div>';
  $('#anw-form-titel').html(titel);
  var h='';
  if(trainer.length){
    h+='<div style="font-size:11px;font-weight:700;color:#2a5fd0;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">Trainer</div>';
    $.each(trainer,function(i,t){
      h+=wkAnwTrainerRow(t,sm['trainer_'+t.id]||'abwesend',abM[t.id]||plan,plan);
    });
  }
  if(schwimmer.length){
    h+='<div style="font-size:11px;font-weight:700;color:#2a5fd0;text-transform:uppercase;letter-spacing:.4px;margin-top:12px;margin-bottom:8px">Schwimmer</div>';
    h+='<div class="anw-grid">';
    $.each(schwimmer,function(i,s){h+=wkAnwTile(s.id,s.last_name+', '+s.first_name,sm['schwimmer_'+s.id]||'abwesend');});
    h+='</div>';
  }
  if(!h)h='<div class="i-spin">Keine Teilnehmer.</div>';
  $('#anw-eintraege').html(h);
  $('#anw-form').css('display','block');
  $('#anw-stat-card').insertAfter('#anw-form');
  // Save-Button bleibt sichtbar — Auto-Save speichert direkt, der Button dient
  // als manuelle Bestätigung "alles aktuell gespeichert".
  $('#anw-save').show();
  anwSaveStatus('saved');
}

function wkAnwTrainerRow(t,status,abschnitte,plan){
  var cur=status||'abwesend';
  function btn(s){
    var st=ANW_STIL[s];
    var aktiv=cur===s;
    return'<button class="i-ab-wk" data-wk="1" data-typ="trainer" data-id="'+t.id+'" data-st="'+s+'" '
      +'style="flex:1;min-width:36px;padding:6px 2px;border:2px solid '+(aktiv?st.border:'#cbd5e1')+';background:'+(aktiv?st.bg:'#f8fafc')+';border-radius:6px;cursor:pointer;font-size:18px;color:'+(aktiv?st.text:'#5b6072')+';font-weight:'+(aktiv?'700':'400')+';touch-action:manipulation" title="'+s+'">'+st.icon+'</button>';
  }
  var absCtrl='<input type="number" class="i-ctl wk-tr-abs" data-tid="'+t.id+'" min="0" step="1" value="'+abschnitte+'" style="width:60px;padding:4px;font-size:12px" title="Begleitete Abschnitte">';
  return'<div class="i-ar" style="align-items:center">'
    +'<div class="i-an i-an-tr">'+esc(t.display_name||t.name)+' <span style="font-size:11px;color:#6b6e85">Abschn.: '+absCtrl+'</span></div>'
    +'<div class="i-abs">'+btn('anwesend')+btn('abwesend')+btn('entschuldigt')+'</div>'
    +'</div>';
}

function wkAnwTile(id,name,status){
  var cur=status||'abwesend';
  function btn(s){
    var st=ANW_STIL[s];
    var aktiv=cur===s;
    return'<button class="i-ab-wk" data-wk="1" data-typ="schwimmer" data-id="'+id+'" data-st="'+s+'" '
      +'style="flex:1;padding:8px 4px;border:2px solid '+(aktiv?st.border:'#cbd5e1')+';background:'+(aktiv?st.bg:'#f8fafc')+';border-radius:7px;cursor:pointer;font-size:22px;color:'+(aktiv?st.text:'#5b6072')+';font-weight:'+(aktiv?'700':'400')+';touch-action:manipulation" title="'+s+'">'+st.icon+'</button>';
  }
  return'<div class="anw-tile" style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.15);border-radius:10px;padding:10px">'
    +'<div style="font-size:12px;font-weight:600;color:#1c1d2e;margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="'+esc(name)+'">'+esc(name)+'</div>'
    +'<div style="display:flex;gap:5px">'+btn('anwesend')+btn('abwesend')+btn('entschuldigt')+'</div>'
    +'</div>';
}

// Wettkampf-Status-Button klicken (direktes Speichern, nicht gesammelt)
$(document).on('click','.i-ab-wk',function(){
  if(!curWkAnwId)return;
  var $btn=$(this);
  var $gruppe=$btn.closest('.i-abs,.anw-tile').find('.i-ab-wk');
  var wasAktiv=$btn.css('font-weight')==='700';
  // Alle zurücksetzen
  $gruppe.each(function(){
    $(this).css({'border-color':'#cbd5e1','background':'#f8fafc','color':'#5b6072','font-weight':'400'});
  });
  var ziel=wasAktiv?'abwesend':$btn.data('st');
  var $ziel=$gruppe.filter('[data-st="'+ziel+'"]');
  var st=ANW_STIL[ziel];
  $ziel.css({'border-color':st.border,'background':st.bg,'color':st.text,'font-weight':'700'});
  // Direkt speichern mit Status-Anzeige
  anwSaveStatus('saving');
  ajax('lsv07i_wk_anw_save_eintrag',{
    wk_anwesenheit_id:curWkAnwId,
    typ:$btn.data('typ'),
    teilnehmer_id:$btn.data('id'),
    status:ziel
  }).done(function(r){
    if(!r.success){
      toast(r.data&&r.data.message||'Fehler.','err');
      anwSaveStatus('error',r.data&&r.data.message);
      return;
    }
    anwSaveStatus('saved');
  }).fail(function(xhr){
    toast(errMsg(xhr),'err');
    anwSaveStatus('error',errMsg(xhr));
  });
});

// Abschnitte-Änderung speichern (debounced) — mit Status
var wkAbsTimer;
$(document).on('input','.wk-tr-abs',function(){
  var tid=$(this).data('tid');
  var val=parseInt($(this).val(),10)||0;
  clearTimeout(wkAbsTimer);
  anwSaveStatus('dirty');
  wkAbsTimer=setTimeout(function(){
    if(!curWkAnwId)return;
    anwSaveStatus('saving');
    ajax('lsv07i_wk_anw_save_abschnitte',{
      wk_anwesenheit_id:curWkAnwId,trainer_id:tid,abschnitte:val
    }).done(function(r){
      if(r&&r.success)anwSaveStatus('saved');
      else anwSaveStatus('error',r&&r.data&&r.data.message);
    }).fail(function(xhr){anwSaveStatus('error',errMsg(xhr));});
  },500);
});

// ── Wettkampf-Verwaltung (Reiter "Wettkämpfe") ──────────────────────────
$('#wk-laden').on('click',function(){
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_wk_list',{mannschaft_id:$('#wk-f-mann').val(),jahr:$('#wk-f-jahr').val()}).done(function(r){
    if(!r.success)return;
    console.log('[Wettkampf List]',r.data);
    renderWkListe(r.data);
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Laden');});
});

function renderWkListe(rows){
  if(!rows||!rows.length){$('#wk-liste').html('<div class="i-spin">Keine Wettkämpfe.</div>');return;}
  var isAdmin=LSV07I.access&&(LSV07I.access.is_admin_raw||LSV07I.access.is_schwimmwart);
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Von</th><th>Bis</th><th>Mannschaften</th><th>Tage</th><th>Freigabe</th><th></th></tr></thead><tbody>';
  $.each(rows,function(i,w){
    var manns=$.map(w.mannschaften||[],function(m){return esc(m.name);}).join(', ');
    var tage=(w.tage||[]).length;
    var gesAbschn=0;$.each(w.tage||[],function(j,t){gesAbschn+=parseInt(t.abschnitte_plan,10)||0;});
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(w.name)+'</strong>'+(w.ort?'<div style="font-size:11px;color:#6b6e85">'+esc(w.ort)+'</div>':'')+'</td>'
      +'<td data-label="Von">'+de(w.datum_von)+'</td>'
      +'<td data-label="Bis">'+de(w.datum_bis)+'</td>'
      +'<td data-label="Mannschaften">'+manns+'</td>'
      +'<td data-label="Tage">'+tage+' Tag(e), '+gesAbschn+' Abschn.</td>'
      +'<td data-label="Freigabe">'+wkFreigabeBadge(w)+'</td>'
      +'<td><button class="i-btn i-btn-g i-btn-sm wk-stamm-edit" data-id="'+w.id+'">Bearbeiten</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#wk-liste').html(h);
}

function wkFreigabeBadge(w){
  if(parseInt(w.freigegeben,10)===1) return '<span class="i-bdg i-bdg-g">Freigegeben</span>';
  var hatAusschreibung=($.inArray('ausschreibung',w.dokumente||[])>=0);
  return hatAusschreibung
    ? '<span class="i-bdg i-bdg-a">Wartet auf Freigabe</span>'
    : '<span class="i-bdg i-bdg-r">Ausschreibung fehlt</span>';
}

$(document).on('click','#wk-neu',function(){wkOpenEdit(0);});
$(document).on('click','.wk-stamm-edit',function(){wkOpenEdit($(this).data('id'));});

// Zustand des gerade geöffneten Wettkampfs im Bearbeiten-Dialog:
// Dokumente/Erinnerungsadressen/Freigabe — nur relevant, wenn eine ID existiert.
var WK={id:0,dokumente:{},erinnerungen:[],freigegeben:false};

function wkOpenEdit(id){
  // Mannschafts-Checkboxen aufbauen
  var manns=S.mann||[];
  var h='';
  $.each(manns,function(i,m){
    h+='<label style="display:flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid rgba(255,255,255,0.15);border-radius:16px;background:rgba(255,255,255,0.06);cursor:pointer"><input type="checkbox" class="mwke-mcb" value="'+m.id+'">'+esc(m.name)+'</label>';
  });
  $('#mwke-mann').html(h);
  if(id){
    $('#mwke-ttl').text('Wettkampf bearbeiten');
    $('#mwke-del').show();
    ajax('lsv07i_wk_get',{id:id}).done(function(r){
      if(!r.success){toast(r.data.message,'err');return;}
      var w=r.data;
      $('#mwke-id').val(w.id);
      $('#mwke-name').val(w.name);
      $('#mwke-ort').val(w.ort);
      $('#mwke-von').val(w.datum_von);
      $('#mwke-bis').val(w.datum_bis);
      $('.mwke-mcb').each(function(){
        if(w.mannschaft_ids.indexOf(parseInt($(this).val(),10))>=0)$(this).prop('checked',true);
      });
      wkRenderTage(w.tage);
      wkErweiterungenZeigen(w);
      openModal('m-wkedit');
    });
  }else{
    $('#mwke-ttl').text('Wettkampf anlegen');
    $('#mwke-del').hide();
    $('#mwke-id,#mwke-name,#mwke-ort').val('');
    var heute=heuteLokal();
    $('#mwke-von,#mwke-bis').val(heute);
    // Bei Neuanlage direkt den ersten Tag generieren
    wkRenderTage([{datum:heute,abschnitte_plan:1}]);
    WK={id:0,dokumente:{},erinnerungen:[],freigegeben:false};
    $('#mwke-erw').hide();
    openModal('m-wkedit');
  }
}

// Dokumente, Erinnerungsadressen und Freigabestatus für einen geladenen
// Wettkampf anzeigen. Wird auch direkt nach dem ersten Speichern eines
// neuen Wettkampfs aufgerufen (dann mit leeren Listen).
function wkErweiterungenZeigen(w){
  WK={
    id:parseInt(w.id,10),
    dokumente:{},
    erinnerungen:(w.erinnerungen||[]).slice(),
    freigegeben:!!parseInt(w.freigegeben,10)
  };
  $.each(w.dokumente||[],function(i,d){WK.dokumente[d.typ]=d;});
  $('#mwke-erw').show();
  wkDokZeileRendern($('#mwke-dok-ausschreibung'));
  wkDokZeileRendern($('#mwke-dok-meldeergebnis'));
  wkDokZeileRendern($('#mwke-dok-protokoll'));
  wkErinnChipsRendern();

  var kannFreigeben=!!(window.LSV07I&&LSV07I.access&&LSV07I.access.can_wk_approve);
  $('#mwke-freigabe-block').toggle(kannFreigeben);
  if(kannFreigeben) wkFreigabeStatusRendern(w);
}

// ── Dokumente (Ausschreibung/Meldeergebnis/Protokoll) ─────────────────────
function wkDokZeileRendern($el){
  var typ=$el.data('typ'), label=$el.data('label');
  var doc=WK.dokumente[typ];
  var h='<div class="mwke-dok-lbl">'+esc(label)+'</div>';
  if(doc){
    var url=LSV07I.ajax_url+'?action=lsv07i_wk_dok_download&id='+doc.id+'&nonce='+encodeURIComponent(LSV07I.nonce);
    h+='<div class="mwke-dok-zeile">'
      +'<a href="'+esc(url)+'" target="_blank" rel="noopener" class="mwke-dok-datei">'+esc(doc.dateiname)+'</a>'
      +'<label class="i-btn i-btn-g i-btn-sm mwke-dok-ersetzen">Ersetzen<input type="file" accept="application/pdf" class="mwke-dok-input" data-typ="'+typ+'" hidden></label>'
      +'<button type="button" class="i-btn i-btn-r i-btn-sm mwke-dok-del" data-id="'+doc.id+'" data-typ="'+typ+'">Löschen</button>'
      +'</div>';
  }else{
    h+='<div class="mwke-dok-zeile">'
      +'<label class="i-btn i-btn-p i-btn-sm mwke-dok-upload">Hochladen<input type="file" accept="application/pdf" class="mwke-dok-input" data-typ="'+typ+'" hidden></label>'
      +(typ==='ausschreibung'?'<span class="mwke-dok-hinweis">Pflicht vor der Freigabe</span>':'')
      +'</div>';
  }
  $el.html(h);
}

$(document).on('change','.mwke-dok-input',function(){
  var datei=this.files&&this.files[0];
  if(!datei)return;
  var typ=$(this).data('typ');
  if(!WK.id){toast('Bitte zuerst speichern.','err');this.value='';return;}
  if(datei.type!=='application/pdf'){toast('Nur PDF-Dateien sind erlaubt.','err');this.value='';return;}
  if(datei.size>10*1024*1024){toast('Datei ist zu groß (maximal 10 MB).','err');this.value='';return;}
  var fd=new FormData();
  fd.append('action','lsv07i_wk_dok_upload');
  fd.append('nonce',LSV07I.nonce);
  fd.append('wettkampf_id',WK.id);
  fd.append('typ',typ);
  fd.append('datei',datei);
  var $zeile=$(this).closest('.mwke-dok');
  $zeile.css('opacity',.6);
  $.ajax({url:LSV07I.ajax_url,type:'POST',data:fd,processData:false,contentType:false,dataType:'json'})
    .done(function(r){
      if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Upload fehlgeschlagen.','err');return;}
      WK.dokumente[typ]=r.data.dokument;
      wkDokZeileRendern($zeile);
      toast(r.data.message||'Datei hochgeladen.');
    })
    .fail(function(xhr){toast(errMsg(xhr),'err');})
    .always(function(){$zeile.css('opacity','');});
});

$(document).on('click','.mwke-dok-del',function(){
  var id=$(this).data('id'), typ=$(this).data('typ');
  if(!confirm('Dokument wirklich löschen?'))return;
  var $zeile=$(this).closest('.mwke-dok');
  ajax('lsv07i_wk_dok_delete',{id:id}).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)||'Fehler.','err');return;}
    delete WK.dokumente[typ];
    wkDokZeileRendern($zeile);
    toast('Dokument gelöscht.');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

// ── Erinnerungs-E-Mail-Adressen ────────────────────────────────────────────
function wkErinnChipsRendern(){
  var h='';
  $.each(WK.erinnerungen,function(i,e){
    h+='<span class="mwke-chip">'+esc(e)+'<button type="button" class="mwke-chip-x" data-email="'+esc(e)+'" aria-label="Entfernen">&#10005;</button></span>';
  });
  $('#mwke-erinn-chips').html(h||'<span class="i-muted" style="font-size:12px">Noch keine Erinnerungsadressen.</span>');
}

function wkErinnSpeichern(){
  if(!WK.id)return;
  ajax('lsv07i_wk_erinnerung_save',{wettkampf_id:WK.id,emails:JSON.stringify(WK.erinnerungen)}).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)||'Fehler.','err');return;}
    WK.erinnerungen=r.data.emails||[];
    wkErinnChipsRendern();
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
}

function wkErinnHinzufuegen(){
  var $f=$('#mwke-erinn-eingabe');
  var email=$f.val().trim();
  if(!email)return;
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){toast('Bitte eine gültige E-Mail-Adresse eingeben.','err');return;}
  if(WK.erinnerungen.indexOf(email)<0)WK.erinnerungen.push(email);
  $f.val('');
  wkErinnSpeichern();
}
$('#mwke-erinn-add').on('click',wkErinnHinzufuegen);
$('#mwke-erinn-eingabe').on('keydown',function(e){
  if(e.which===13||e.key===','){e.preventDefault();wkErinnHinzufuegen();}
});
$(document).on('click','.mwke-chip-x',function(){
  var email=$(this).data('email');
  WK.erinnerungen=$.grep(WK.erinnerungen,function(e){return e!==email;});
  wkErinnSpeichern();
});

// ── Freigabe ────────────────────────────────────────────────────────────
function wkFreigabeStatusRendern(w){
  var $s=$('#mwke-freigabe-status');
  if(WK.freigegeben){
    $s.removeClass('i-notice-a i-notice-r').addClass('i-notice-g')
      .html('✓ Freigegeben'+(w.freigegeben_am?' am '+de(w.freigegeben_am.slice(0,10)):'')+(w.freigegeben_von_name?' von '+esc(w.freigegeben_von_name):'')+'. Sichtbar auf der öffentlichen Übersichtsseite.');
    $('#mwke-freigeben').hide();
    $('#mwke-unapprove').show();
  }else{
    var fehlt=!WK.dokumente.ausschreibung;
    $s.removeClass('i-notice-g').addClass(fehlt?'i-notice-r':'i-notice-a')
      .text(fehlt?'Wartet auf Freigabe — die Ausschreibung fehlt noch.':'Wartet auf Freigabe.');
    $('#mwke-freigeben').show().prop('disabled',fehlt);
    $('#mwke-unapprove').hide();
  }
}
$('#mwke-freigeben').on('click',function(){
  if(!WK.id)return;
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_wk_approve',{id:WK.id}).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)||'Fehler.','err');return;}
    WK.freigegeben=true;
    toast(r.data.message||'Freigegeben.');
    wkFreigabeStatusRendern({freigegeben_am:heuteLokal(),freigegeben_von_name:LSV07I.user_name});
    $('#wk-laden').trigger('click');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Jetzt freigeben');});
});
$('#mwke-unapprove').on('click',function(){
  if(!WK.id)return;
  if(!confirm('Freigabe wirklich zurückziehen?\n\nDer Wettkampf verschwindet dann von der öffentlichen Übersichtsseite.'))return;
  ajax('lsv07i_wk_unapprove',{id:WK.id}).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)||'Fehler.','err');return;}
    WK.freigegeben=false;
    toast(r.data.message||'Freigabe zurückgezogen.');
    wkFreigabeStatusRendern({});
    $('#wk-laden').trigger('click');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

function wkRenderTage(tage){
  var h='';
  $.each(tage||[],function(i,t){
    h+='<div class="mwke-tagrow" style="display:flex;gap:8px;align-items:center;margin-bottom:4px">'
      +'<input type="date" class="i-ctl mwke-tag-d" value="'+t.datum+'" style="width:150px">'
      +'<input type="number" class="i-ctl mwke-tag-a" value="'+t.abschnitte_plan+'" min="1" max="10" style="width:90px" title="Abschnitte">'
      +'<span class="i-muted" style="font-size:11px">Abschn.</span>'
      +'<button type="button" class="i-btn i-btn-r i-btn-sm mwke-tag-del" style="font-size:10px">&times;</button>'
      +'</div>';
  });
  $('#mwke-tage').html(h);
}
$(document).on('click','.mwke-tag-del',function(){$(this).closest('.mwke-tagrow').remove();});

// Hilfsfunktion: String-basierte Datums-Iteration ohne Zeitzonen-Fallen.
// JavaScript's toISOString() arbeitet in UTC, was bei lokalen Mitternacht-
// Zeitstempeln Daten um einen Tag verschiebt (in MEZ/MESZ negativ).
// Deshalb rein mit YYYY-MM-DD-Strings rechnen.
function wkIsoAddDay(iso){
  // iso = 'YYYY-MM-DD' → gibt den Folgetag als YYYY-MM-DD zurück
  var p=iso.split('-');
  var d=new Date(Date.UTC(parseInt(p[0],10),parseInt(p[1],10)-1,parseInt(p[2],10)));
  d.setUTCDate(d.getUTCDate()+1);
  var y=d.getUTCFullYear(),
      m=String(d.getUTCMonth()+1).padStart(2,'0'),
      dd=String(d.getUTCDate()).padStart(2,'0');
  return y+'-'+m+'-'+dd;
}

// Beim Datum-von/bis-Ändern Tage automatisch generieren.
// Bestehende Tage im Bereich behalten, fehlende ergänzen, Tage außerhalb entfernen.
$(document).on('change','#mwke-von, #mwke-bis',function(){
  var von=$('#mwke-von').val(),bis=$('#mwke-bis').val();
  if(!von||!bis)return;
  if(von>bis){
    // Falls bis < von: bis auf von setzen, damit der User sinnvoll weiterarbeiten kann
    $('#mwke-bis').val(von);
    bis=von;
  }
  // Bestehende Tage einsammeln (Datum → Abschnitte-Wert)
  var bestehende={};
  $('.mwke-tagrow').each(function(){
    var d=$(this).find('.mwke-tag-d').val();
    var a=parseInt($(this).find('.mwke-tag-a').val(),10)||1;
    if(d)bestehende[d]=a;
  });
  // Neue Liste: jeder Tag im Bereich; bestehende Abschnittswerte übernehmen
  var tageArr=[];
  var cur=von;
  // Sicherheitsgrenze gegen versehentliche 10-Jahres-Bereiche
  var maxSchritte=62;
  while(cur<=bis && maxSchritte-- > 0){
    tageArr.push({datum:cur,abschnitte_plan:bestehende[cur]||1});
    cur=wkIsoAddDay(cur);
  }
  wkRenderTage(tageArr);
});

$('#mwke-save').on('click',function(){
  var id=$('#mwke-id').val();
  var name=$('#mwke-name').val().trim();
  var ort=$('#mwke-ort').val().trim();
  var von=$('#mwke-von').val();
  var bis=$('#mwke-bis').val();
  if(!name||!ort||!von||!bis){toast('Name, Ort, Datum von und bis sind Pflicht.','err');return;}
  if(von>bis){toast('"Datum von" muss vor oder gleich "Datum bis" liegen.','err');return;}
  var mann=[];$('.mwke-mcb:checked').each(function(){mann.push(parseInt($(this).val(),10));});
  if(!mann.length){toast('Mindestens eine Mannschaft auswählen.','err');return;}

  // Wichtig: Tage-Liste IMMER aus dem Datumsbereich neu generieren,
  // um sicherzustellen dass der erste/letzte Tag nicht durch verpasste
  // change-Events fehlt. Bestehende Abschnittswerte behalten.
  var bestehende={};
  $('.mwke-tagrow').each(function(){
    var d=$(this).find('.mwke-tag-d').val();
    var a=parseInt($(this).find('.mwke-tag-a').val(),10)||1;
    if(d)bestehende[d]=a;
  });
  var tage=[], cur=von, maxSchritte=62;
  while(cur<=bis && maxSchritte-- > 0){
    tage.push({datum:cur,abschnitte_plan:bestehende[cur]||1});
    cur=wkIsoAddDay(cur);
  }

  // Debug-Log in der Konsole (F12), damit Probleme nachvollziehbar sind
  console.log('[Wettkampf Save]',{von:von,bis:bis,tage_anzahl:tage.length,tage:tage,mann:mann});

  var warNeu=!id;
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_wk_save',{
    id:id,name:name,ort:ort,
    datum_von:von,datum_bis:bis,
    mannschaft_ids_json:JSON.stringify(mann),
    tage_json:JSON.stringify(tage)
  }).done(function(r){
    console.log('[Wettkampf Save Response]',r);
    if(!r.success){toast(r.data.message,'err');return;}
    if(warNeu){
      // Neu angelegt: Dialog bleibt offen, damit direkt die Ausschreibung
      // hochgeladen werden kann — die verlangt eine bestehende ID.
      $('#mwke-id').val(r.data.id);
      $('#mwke-ttl').text('Wettkampf bearbeiten');
      $('#mwke-del').show();
      wkErweiterungenZeigen({id:r.data.id,dokumente:[],erinnerungen:[],freigegeben:0});
      toast('Wettkampf angelegt. Bitte jetzt die Ausschreibung als PDF hochladen.');
    }else{
      toast('Wettkampf gespeichert ('+tage.length+' Tag'+(tage.length!==1?'e':'')+').'
        +(r.data.freigabe_zurueckgezogen?' Die Freigabe wurde zurückgezogen, da sich die Daten geändert haben.':''));
      closeModal('m-wkedit');
    }
    $('#wk-laden').trigger('click');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Speichern');});
});

$('#mwke-del').on('click',function(){
  var id=$('#mwke-id').val();
  if(!id)return;
  if(!confirm('Wettkampf wirklich löschen?\n\nAlle Anwesenheiten und automatisch erzeugte Abrechnungs-Einträge werden mit entfernt.'))return;
  ajax('lsv07i_wk_delete',{id:id}).done(function(r){
    if(!r.success){toast(r.data.message,'err');return;}
    toast('Wettkampf gelöscht.');
    closeModal('m-wkedit');
    $('#wk-laden').trigger('click');
  });
});

// ── Wettkampf-Statistik ────────────────────────────────────────────────
$('#wkstat-laden').on('click',function(){
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_wk_statistik',{mannschaft_id:$('#wkstat-mann').val(),von:$('#wkstat-von').val(),bis:$('#wkstat-bis').val()}).done(function(r){
    if(!r.success)return;
    var g=r.data.wk_tage_gesamt,sw=r.data.schwimmer||[];
    if(!sw.length){$('#wkstat-content').html('<span class="i-muted">Keine Daten.</span>');return;}
    var h='<span class="i-muted">Wettkampftage gesamt (im Filter): <strong>'+g+'</strong></span>'
      +'<div class="i-twrap" style="margin-top:8px"><table class="i-tbl"><thead><tr><th>Name</th><th>Anw.</th><th>Abw.</th><th>Ents.</th><th>Quote</th></tr></thead><tbody>';
    $.each(sw,function(i,s){
      var eig=s.gesamt||0;
      var q=eig>0?Math.round(s.anwesend/eig*100):0;
      var qc=q>=75?'g':q>=50?'a':'r';
      h+='<tr><td data-label="Name">'+esc(s.name)+'</td>'
        +'<td data-label="Anw.">'+bdg('g',s.anwesend)+'</td>'
        +'<td data-label="Abw.">'+bdg('r',s.abwesend)+'</td>'
        +'<td data-label="Ents.">'+bdg('a',s.entschuldigt)+'</td>'
        +'<td data-label="Quote">'+bdg(qc,q+'%')+' <span style="font-size:11px;color:#6b6e85">von '+eig+'</span></td></tr>';
    });
    h+='</tbody></table></div>';
    $('#wkstat-content').html(h);
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Laden');});
});

// ── Offene Wettkampftage in der Abrechnung nachtragen ──────────────────
$(document).on('click','#tr-wkoffen-jetzt',function(){
  $('#tr-add-wk-offen').trigger('click');
});
$(document).on('click','#tr-add-wk-offen',function(){
  var abrId=window._curAbrId||$('#mwk-abr-id').val();
  if(!abrId){toast('Keine Abrechnung aktiv.','err');return;}
  skel($('#mwko-liste'),'rows',4);
  openModal('m-wkoffen');
  ajax('lsv07i_wk_offene_tage',{abrechnung_id:abrId}).done(function(r){
    if(!r.success){toast(r.data.message,'err');return;}
    var rows=r.data;
    if(!rows.length){$('#mwko-liste').html('<div class="i-muted">Keine offenen Wettkampftage gefunden.</div>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Datum</th><th>Wettkampf</th><th>Mannschaft</th><th>Abschn.</th><th>Status</th><th></th></tr></thead><tbody>';
    $.each(rows,function(i,t){
      var statusBdg=t.eigener_status==='anwesend'?bdg('g','anwesend'):bdg('b','nicht erfasst');
      h+='<tr>'
        +'<td data-label="Datum">'+de(t.datum)+'</td>'
        +'<td data-label="Wettkampf">'+esc(t.wettkampf_name)+'</td>'
        +'<td data-label="Mannschaft">'+esc(t.mannschaft_name||'')+'</td>'
        +'<td><input type="number" class="i-ctl wko-abs" min="1" max="10" value="'+t.abschnitte_plan+'" style="width:60px;padding:4px"></td>'
        +'<td>'+statusBdg+'</td>'
        +'<td><button class="i-btn i-btn-p i-btn-sm wko-add" data-abr="'+abrId+'" data-datum="'+t.datum+'" data-name="'+esc(t.wettkampf_name)+'" data-tagid="'+t.wettkampf_tag_id+'" data-mannid="'+t.mannschaft_id+'">Hinzufügen</button></td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#mwko-liste').html(h);
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

$(document).on('click','.wko-add',function(){
  var $r=$(this).closest('tr');
  var abs=parseInt($r.find('.wko-abs').val(),10)||1;
  var $b=$(this).prop('disabled',true).text('…');
  // Nutzt den bestehenden save_wettkampf-Endpoint mit Wettkampftag-Bezug
  ajax('lsv07i_abr_save_wettkampf',{
    abrechnung_id:$(this).data('abr'),
    datum:$(this).data('datum'),
    name:$(this).data('name'),
    abschnitte:abs,
    wettkampf_tag_id:$(this).data('tagid'),
    mannschaft_id:$(this).data('mannid')
  }).done(function(r){
    if(!r.success){toast(r.data&&r.data.message||'Fehler.','err');return;}
    toast('Wettkampftag hinzugefügt.');
    $r.fadeOut(200,function(){$(this).remove();});
    // Abrechnung neu laden falls offen
    if($('#tr-laden').length)$('#tr-laden').trigger('click');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Hinzufügen');});
});

// Wettkampf-Mannschaft-Filter und Statistik-Dropdowns mit S.mann füllen
/* (entfernt in v7.37.0: wkFillFilterDropdowns war ungenutzt — die
   Wettkampf-Filter-Dropdowns befüllt loadSchwimmen() via fillSel.) */
// Wird vom Schwimmen-Loader automatisch mit-befüllt (siehe loadSchwimmen).

$('#spr-laden').on('click',function(){
  var von=$('#spr-von').val(),bis=$('#spr-bis').val();
  if(!von||!bis){toast('Bitte Zeitraum angeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('…');
  skel($('#spr-grid'),'rows',3);
  ajax('lsv07i_springer_get_plan',{von:von,bis:bis}).done(function(r){
    if(r.success){r.data.mannschaft_filter=$('#spr-mann-filter').val();renderSpr(r.data);}
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Anzeigen');});
});
// Mannschaftsfilter live anwenden ohne neu laden
$('#spr-mann-filter').on('change',function(){
  if(window._sprLastData){window._sprLastData.mannschaft_filter=$(this).val();renderSpr(window._sprLastData);}
});

function renderSpr(d){
  window._sprLastData=d;
  var slots=d.slots||[],springer=d.springer||[],eigen=d.eigene_mannschaften||[],cm=d.count_map||{},af=d.ausfall_set||{},tid=d.trainer_id,von=d.von,bis=d.bis;
  var mannFilter=d.mannschaft_filter||'';
  var list=[];
  var vd=new Date(von),bd=new Date(bis);
  for(var cur=new Date(vd);cur<=bd;cur.setDate(cur.getDate()+1)){
    var iso=cur.toISOString().slice(0,10);
    var wt=cur.getDay()||7;
    $.each(slots,function(i,s){
      if(parseInt(s.wochentag)!==wt)return;
      if(mannFilter&&String(s.mannschaft_id)!==String(mannFilter))return;  // Mannschaftsfilter
      var key=s.id+'_'+iso;
      var isEigen=$.inArray(String(s.mannschaft_id),eigen)>=0;
      var isAuf=!!af[key];
      var anz=cm[key]||0;
      var isEin=false;$.each(springer,function(j,sp){if(sp.slot_id==s.id&&sp.training_datum===iso&&sp.trainer_id==tid)isEin=true;});
      var alle=$.grep(springer,function(sp){return sp.slot_id==s.id&&sp.training_datum===iso;});
      list.push({s:s,iso:iso,key:key,isEigen:isEigen,isAuf:isAuf,anz:anz,isEin:isEin,alle:alle});
    });
  }
  if(!list.length){$('#spr-grid').html('<span class="i-muted">Keine Trainings im Zeitraum.</span>');return;}
  var h='<div class="i-sg">';
  $.each(list,function(i,t){
    var sb='';
    if(t.isAuf)sb=bdg('r','Ausgefallen');
    else if(t.isEigen)sb=bdg('b','Eigene Mannschaft');
    else if(t.anz>=2)sb=bdg('a','Belegt 2/2');
    var sn=t.alle.length?$.map(t.alle,function(sp){return esc(sp.trainer_name);}).join(', '):'–';
    var btn='';
    if(!t.isEigen&&!t.isAuf){
      if(t.isEin)btn='<button class="i-btn i-btn-g i-btn-sm spr-aus" data-slot="'+t.s.id+'" data-dat="'+t.iso+'">Austragen</button>';
      else if(t.anz<2)btn='<button class="i-btn i-btn-p i-btn-sm spr-ein" data-slot="'+t.s.id+'" data-dat="'+t.iso+'">Als Springer eintragen</button>';
    }
    h+='<div class="i-sc'+(t.isAuf?' aus':'')+'"><div class="i-sc-t">'+esc(t.s.mannschaft_name)+' '+sb+'</div><div class="i-sc-m">'+WD[t.s.wochentag]+', '+de(t.iso)+' | '+String(t.s.zeit_von||'').slice(0,5)+'-'+String(t.s.zeit_bis||'').slice(0,5)+'</div><div class="i-sc-s">Springer ('+t.anz+'/2): <strong>'+sn+'</strong></div>'+btn+'</div>';
  });
  h+='</div>';$('#spr-grid').html(h);
}
$(document).on('click','.spr-ein',function(){
  var $b=$(this).prop('disabled',true);
  ajax('lsv07i_springer_eintragen',{slot_id:$(this).data('slot'),datum:$(this).data('dat')}).done(function(r){
    if(r.success){toast('Als Springer eingetragen.');$('#spr-laden').trigger('click');}else toast(r.data.message,'err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false);});
});
$(document).on('click','.spr-aus',function(){
  if(!confirm('Springer-Schicht austragen?'))return;
  var $b=$(this).prop('disabled',true);
  ajax('lsv07i_springer_austragen',{slot_id:$(this).data('slot'),datum:$(this).data('dat')}).done(function(r){
    if(r.success){toast('Ausgetragen.');$('#spr-laden').trigger('click');}else toast(r.data.message,'err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false);});
});

/* ══ VERWALTUNG ════════════════════════════════════════════════ */
function ladeVwAbr(status){
  status=status||'';
  skel($('#vw-liste'),'rows',5);
  ajax('lsv07i_abr_get_alle',{status:status}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message?r.data.message:'Fehler beim Laden.','err');$('#vw-liste').html('<span class="i-muted">Fehler beim Laden.</span>');return;}
    if(!r.data.length){$('#vw-liste').html('<span class="i-muted">Keine Abrechnungen.</span>');return;}
    var isAdmin=LSV07I.access&&LSV07I.access.is_admin;
    var isAdminRaw=LSV07I.access&&LSV07I.access.is_admin_raw;
    var h='';
    $.each(r.data,function(i,a){
      var rg=a.rueckgabe_grund?'<div class="i-notice i-notice-r" style="margin-bottom:8px"><strong>Rückgabe-Grund:</strong> '+esc(a.rueckgabe_grund)+'</div>':'';
      // Trainingstage
      var tbl='';
      if(a.trainingstage&&a.trainingstage.length){
        tbl='<div style="margin-top:10px;font-size:11px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px">Trainingstage</div>';
        tbl+='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Datum</th><th>Mannschaft</th><th>Std.</th><th>Quelle</th><th>Betrag</th></tr></thead><tbody>';
        var g=0,satz=parseFloat(a.stundensatz||0);
        $.each(a.trainingstage,function(j,t){g+=parseFloat(t.stunden);tbl+='<tr><td data-label="Datum">'+de(t.datum)+'</td><td data-label="Mannschaft">'+esc(t.mannschaft_name)+'</td><td data-label="Std.">'+parseFloat(t.stunden).toFixed(2)+'</td><td data-label="Quelle">'+bdg('b',t.quelle)+'</td><td data-label="Betrag">'+eur(parseFloat(t.stunden)*satz)+'</td></tr>';});
        tbl+='<tr style="font-weight:700;background:rgba(255,255,255,0.08)"><td colspan="2">Gesamt Training</td><td>'+g.toFixed(2)+' Std.</td><td></td><td>'+eur(a.training_betrag)+'</td></tr>';
        if(a.anzahl_mit_vorbereitung>0){
          tbl+='<tr style="background:rgba(91,148,255,0.08)"><td colspan="2">+15 Min &times; '+a.anzahl_mit_vorbereitung+'</td><td>'+parseFloat(a.vorbereitung_stunden||0).toFixed(2)+' Std.</td><td></td><td>'+eur(a.vorbereitung_betrag)+'</td></tr>';
        }
        tbl+='</tbody></table></div>';
      }
      // Wettkämpfe
      if(a.wettkampf&&a.wettkampf.length){
        tbl+='<div style="margin-top:10px;font-size:11px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px">Wettkämpfe</div>';
        tbl+='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Datum</th><th>Wettkampf</th><th>Abschnitte</th><th>Betrag</th></tr></thead><tbody>';
        $.each(a.wettkampf,function(j,w){tbl+='<tr><td>'+de(w.datum)+'</td><td>'+esc(w.name||'Wettkampf')+'</td><td>'+w.abschnitte+'</td><td>'+eur(w.betrag)+'</td></tr>';});
        tbl+='<tr style="font-weight:700;background:rgba(255,255,255,0.08)"><td colspan="3">Gesamt Wettkämpfe</td><td>'+eur(a.wettkampf_betrag)+'</td></tr></tbody></table></div>';
      }
      // Sonderabrechnung (Tag + Stunden, kein Mannschaftsbezug)
      if(a.sonder&&a.sonder.length){
        tbl+='<div style="margin-top:10px;font-size:11px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px">Sonderabrechnung</div>';
        tbl+='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Datum</th><th>Beschreibung</th><th>Std.</th><th>Betrag</th></tr></thead><tbody>';
        $.each(a.sonder,function(j,t){tbl+='<tr><td data-label="Datum">'+de(t.datum)+'</td><td data-label="Beschreibung">'+esc(t.beschreibung||'')+'</td><td data-label="Std.">'+parseFloat(t.stunden).toFixed(2)+'</td><td data-label="Betrag">'+eur(parseFloat(t.stunden)*parseFloat(a.stundensatz||0))+'</td></tr>';});
        tbl+='<tr style="font-weight:700;background:rgba(255,255,255,0.08)"><td colspan="2">Gesamt Sonderabrechnung</td><td>'+parseFloat(a.sonder_stunden||0).toFixed(2)+' Std.</td><td>'+eur(a.sonder_betrag)+'</td></tr></tbody></table></div>';
      }
      // Kilometer
      if(a.kilometer&&a.kilometer.length){
        tbl+='<div style="margin-top:10px;font-size:11px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px">Fahrtkosten</div>';
        tbl+='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Datum</th><th>Beschreibung</th><th>km</th><th>Betrag</th></tr></thead><tbody>';
        $.each(a.kilometer,function(j,k){var tg=Math.max(1,parseInt(k.tage)||1);var tginfo=tg>1?' <span style="color:#7c7f94;font-size:11px">('+tg+' Tage)</span>':'';tbl+='<tr><td>'+de(k.datum)+'</td><td>'+esc(k.beschreibung||'Fahrt')+'</td><td>'+parseFloat(k.km).toFixed(1)+tginfo+'</td><td>'+eur(k.betrag)+'</td></tr>';});
        tbl+='<tr style="font-weight:700;background:rgba(255,255,255,0.08)"><td colspan="3">Gesamt Fahrtkosten</td><td>'+eur(a.km_betrag)+'</td></tr></tbody></table></div>';
      }
      // Gesamtbetrag
      var vwVorbSpan = (a.anzahl_mit_vorbereitung > 0)
        ? '<span style="color:#6b6e85">+15 Min &times; '+a.anzahl_mit_vorbereitung+': <strong>'+eur(a.vorbereitung_betrag)+'</strong></span>'
        : '';
      var vwSonderSpan = (a.sonder_betrag>0)
        ? '<span style="color:#6b6e85">Sonderabrechnung: <strong>'+eur(a.sonder_betrag)+'</strong></span>'
        : '';
      var summe='<div style="margin-top:10px;padding:8px 12px;background:rgba(91,148,255,0.12);border-radius:6px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;font-size:12px">'
        +'<span style="color:#6b6e85">Stundensatz: <strong>'+eur(a.stundensatz)+'/Std.</strong></span>'
        +'<span style="color:#6b6e85">Training: <strong>'+eur(a.training_betrag)+'</strong></span>'
        +vwVorbSpan
        +'<span style="color:#6b6e85">Wettkämpfe: <strong>'+eur(a.wettkampf_betrag)+'</strong></span>'
        +'<span style="color:#6b6e85">Fahrtkosten: <strong>'+eur(a.km_betrag)+'</strong></span>'
        +vwSonderSpan
        +'<span style="margin-left:auto;font-size:14px;font-weight:700;color:#5b94ff">Gesamt: '+eur(a.gesamt)+'</span>'
        +'</div>';
      // Aktionsbuttons: Schwimmwart/Admin sehen je nach Status unterschiedliche Aktionen
      var acts='';
      if(a.abr_status==='eingereicht'){
        acts='<button class="i-btn i-btn-ok i-btn-sm vw-ok" data-id="'+a.id+'">Genehmigen</button> <button class="i-btn i-btn-r i-btn-sm vw-rg" data-id="'+a.id+'">Zurückgeben</button>';
      } else if(isAdmin&&(a.abr_status==='genehmigt'||a.abr_status==='entwurf')){
        // Admin kann jede Abrechnung zurücksetzen
        acts='<button class="i-btn i-btn-r i-btn-sm vw-rg" data-id="'+a.id+'">Zurückgeben</button>';
      }
      // Nur echte Administratoren dürfen Abrechnungen löschen – in jedem Status
      // (Entwurf, eingereicht, zurückgegeben, genehmigt).
      if(isAdminRaw){
        acts+=' <button class="i-btn i-btn-r i-btn-sm vw-del" data-id="'+a.id+'" data-name="'+esc(a.trainer_name+' – '+a.quartal+' '+a.jahr)+'">Löschen</button>';
      }
      var body=rg+(tbl?summe+tbl:'<span class="i-muted">Keine Einträge.</span>');
      h+='<div class="i-vi"><div class="i-vh kw-vh" data-aid="'+a.id+'"><div><div style="font-weight:600;font-size:13px">'+esc(a.trainer_name)+' – '+a.quartal+' '+a.jahr+' '+sBdg(a.abr_status)+bereichLabel(a.bereich)+'</div>'+(a.eingereicht_am?'<div style="font-size:12px;color:#6b6e85">Eingereicht: '+de(a.eingereicht_am.slice(0,10))+'</div>':'')+'</div><div style="display:flex;gap:6px">'+acts+'</div></div><div class="i-vb" id="vb-'+a.id+'">'+body+'</div></div>';
    });
    $('#vw-liste').html(h);
  }).fail(function(xhr){toast(errMsg(xhr),'err');$('#vw-liste').html('<span class="i-muted">Fehler beim Laden.</span>');});
}
$('#vw-laden').on('click',function(){ladeVwAbr($('#vw-filter').val());});

// Admin löscht eine noch nicht eingereichte (Entwurf-)Abrechnung
$(document).on('click','.vw-del',function(e){
  e.stopPropagation();
  var id=$(this).data('id');var name=$(this).data('name');
  if(!confirm('Abrechnung "'+name+'" wirklich unwiderruflich löschen?\n\nAlle Trainingstage, Wettkämpfe und Fahrtkosten dieser Abrechnung werden mitgelöscht.'))return;
  var $b=$(this).prop('disabled',true).text('Löscht…');
  ajax('lsv07i_abr_delete_abrechnung',{abrechnung_id:id}).done(function(r){
    if(r.success){toast('Abrechnung gelöscht.');ladeVwAbr($('#vw-filter').val());}
    else{toast((r.data&&r.data.message)||'Löschen fehlgeschlagen.','err');$b.prop('disabled',false).text('Löschen');}
  }).fail(function(xhr){toast(errMsg(xhr),'err');$b.prop('disabled',false).text('Löschen');});
});

// ── Schwimmen: Trainer-Tab (Schwimmwart/Admin) ────────────────────
function loadSwTrainer(){
  if($('#sw-trainer-liste').find('table').length)return; // already loaded
  ajax('lsv07i_schwimmen_get_trainer').done(function(r){
    if(!r.success){$('#sw-trainer-liste').html('<span class="i-muted">Fehler beim Laden.</span>');return;}
    var trainer=r.data.trainer||[];
    // Mannschaften aus der Antwort nehmen (Fallback auf bereits geladene S.mann)
    var mannListe=r.data.mannschaften||S.mann||[];
    if(!trainer.length){$('#sw-trainer-liste').html('<span class="i-muted">Keine Trainer.</span>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Telefon</th><th>E-Mail</th><th>Mannschaft(en)</th></tr></thead><tbody>';
    $.each(trainer,function(i,t){
      var ids=t.mannschaft_ids?String(t.mannschaft_ids).split(',').map(function(x){return x.trim();}):[];
      var mn=ids.map(function(id){var m=null;$.each(mannListe,function(j,mm){if(String(mm.id)===id)m=mm;});return m?esc(m.name):'';}).filter(Boolean).join(', ')||'–';
      var dn=t.display_name||t.name;
      h+='<tr>'
        +'<td data-label="Name"><strong>'+esc(dn)+'</strong></td>'
        +'<td data-label="Telefon">'+esc(t.telefon||'–')+'</td>'
        +'<td data-label="E-Mail">'+(t.email_privat||t.email?esc(t.email_privat||t.email):'–')+'</td>'
        +'<td data-label="Mannschaften">'+mn+'</td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#sw-trainer-liste').html(h).data('trainer',trainer);
  }).fail(function(xhr){$('#sw-trainer-liste').html('<span class="i-muted">Fehler beim Laden.</span>');});
}

// ── Triathlon: Trainer-Übersicht (Triathlonwart) ──────────────────
function loadTriTrainerUb(){
  if($('#tri-trainer-ub-liste').find('table').length)return;
  ajax('lsv07i_tri_get_trainer').done(function(r){
    if(!r.success||!r.data.length){$('#tri-trainer-ub-liste').html('<span class="i-muted">Keine Trainer.</span>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>E-Mail</th><th>Gruppen</th></tr></thead><tbody>';
    $.each(r.data,function(i,t){
      var gids=t.gruppe_ids?t.gruppe_ids.split(','):[];
      var gNamen=gids.map(function(gid){var g=S_tri.gruppen.find(function(x){return String(x.id)===gid;});return g?esc(g.name):'?';}).join(', ')||'–';
      h+='<tr>'
        +'<td data-label="Name"><strong>'+esc(t.name)+'</strong></td>'
        +'<td data-label="E-Mail">'+(t.email?'<a href="mailto:'+esc(t.email)+'" style="color:#5b94ff">'+esc(t.email)+'</a>':'–')+'</td>'
        +'<td data-label="Gruppen">'+gNamen+'</td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#tri-trainer-ub-liste').html(h);
  });
}

// ── Fitness: Trainer-Übersicht (Fitnesswart) ──────────────────
function loadFitTrainerUb(){
  if($('#fit-trainer-ub-liste').find('table').length)return;
  ajax('lsv07i_fit_get_trainer').done(function(r){
    if(!r.success||!r.data.length){$('#fit-trainer-ub-liste').html('<span class="i-muted">Keine Trainer.</span>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>E-Mail</th><th>Gruppen</th></tr></thead><tbody>';
    $.each(r.data,function(i,t){
      var gids=t.gruppe_ids?t.gruppe_ids.split(','):[];
      var gNamen=gids.map(function(gid){var g=S_fit.gruppen.find(function(x){return String(x.id)===gid;});return g?esc(g.name):'?';}).join(', ')||'–';
      h+='<tr>'
        +'<td data-label="Name"><strong>'+esc(t.name)+'</strong></td>'
        +'<td data-label="E-Mail">'+(t.email?'<a href="mailto:'+esc(t.email)+'" style="color:#5b94ff">'+esc(t.email)+'</a>':'–')+'</td>'
        +'<td data-label="Gruppen">'+gNamen+'</td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#fit-trainer-ub-liste').html(h);
  });
}

// ── Schwimmwart: Trainer-Übersicht ────────────────────────────────
$(document).on('click','[data-panel="i-p-vw-trainer"]',function(){
  if($('#vw-trainer-liste').find('.i-spin').length)loadVwTrainer();
});
function loadVwTrainer(){
  skel($('#vw-trainer-liste'),'rows',4);
  ajax('lsv07i_admin_get_data').done(function(r){
    if(!r.success){$('#vw-trainer-liste').html('<span class="i-muted">Fehler.</span>');return;}
    var trainer=r.data.trainer||[];
    if(!trainer.length){$('#vw-trainer-liste').html('<span class="i-muted">Keine Trainer.</span>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Telefon</th><th>E-Mail</th><th>Mannschaft(en)</th></tr></thead><tbody>';
    $.each(trainer,function(i,t){
      var ids=t.mannschaft_ids?String(t.mannschaft_ids).split(',').map(function(x){return x.trim();}):[],
          mn=ids.map(function(id){var m=null;$.each(S.mann,function(j,mm){if(String(mm.id)===id)m=mm;});return m?esc(m.name):'';}).filter(Boolean).join(', ')||'–';
      var dn=t.display_name||t.name;
      h+='<tr style="cursor:pointer" class="vw-tr-row" data-id="'+t.id+'">'
        +'<td data-label="Name"><strong>'+esc(dn)+'</strong></td>'
        +'<td data-label="Telefon">'+esc(t.telefon||'–')+'</td>'
        +'<td data-label="E-Mail">'+(t.email_privat||t.email?esc(t.email_privat||t.email):'–')+'</td>'
        +'<td data-label="Mannschaften">'+mn+'</td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#vw-trainer-liste').html(h);
    // Store for popup
    $('#vw-trainer-liste').data('trainer',trainer);
  });
}

$(document).on('click','.vw-tr-row',function(){
  var id=$(this).data('id');
  var trainer=$('#vw-trainer-liste').data('trainer')||[];
  var t=trainer.find(function(x){return x.id==id;});
  if(!t)return;
  showTrainerDetailPopup(t);
});

function showTrainerDetailPopup(t){
  function jaNein(v){return parseInt(v)?'<span style="color:#22c55e;font-weight:600">✓ Ja</span>':'<span style="color:#7c7f94">✗ Nein</span>';}
  function datFmt(v){return v&&v!=='0000-00-00'?de(v):'<span class="i-muted">–</span>';}
  var ids=t.mannschaft_ids?String(t.mannschaft_ids).split(',').map(function(x){return x.trim();}):[];
  var mn=ids.map(function(id){var m=null;$.each(S.mann,function(j,mm){if(String(mm.id)===id)m=mm;});return m?esc(m.name):'';}).filter(Boolean).join(', ')||'–';
  var h='<div style="padding:14px">'
    +'<h3 style="margin:0 0 14px;font-size:16px;color:#1c1d2e">'+esc(t.display_name||t.name)+'</h3>'
    +'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;font-size:13px">'
    +'<div><span class="i-muted">Telefon</span><br>'+esc(t.telefon||'–')+'</div>'
    +'<div><span class="i-muted">Geburtsdatum</span><br>'+datFmt(t.geburtsdatum)+'</div>'
    +'<div><span class="i-muted">E-Mail Privat</span><br>'+(t.email_privat?'<a href="mailto:'+esc(t.email_privat)+'" style="color:#5b94ff">'+esc(t.email_privat)+'</a>':'–')+'</div>'
    +'<div><span class="i-muted">E-Mail Geschäftl.</span><br>'+(t.email_geschaeft?'<a href="mailto:'+esc(t.email_geschaeft)+'" style="color:#5b94ff">'+esc(t.email_geschaeft)+'</a>':'–')+'</div>'
    +'<div><span class="i-muted">Mannschaften</span><br>'+mn+'</div>'
    +'<div>&nbsp;</div>'
    +'</div>'
    +'<div style="margin-top:12px;padding-top:12px;border-top:1px solid #e2ecf5;display:grid;grid-template-columns:1fr 1fr;gap:6px 20px;font-size:13px">'
    +'<div><span class="i-muted">Trainervertrag</span><br>'+jaNein(t.trainervertrag)+'</div>'
    +'<div><span class="i-muted">Trainerlizenz</span><br>'+jaNein(t.trainerlizenz)+(t.lizenznummer?' <span class="i-muted">('+esc(t.lizenznummer)+')</span>':'')+'</div>'
    +'<div><span class="i-muted">Führungszeugnis bis</span><br>'+datFmt(t.fuehrungszeugnis)+'</div>'
    +'<div><span class="i-muted">Lizenz gültig bis</span><br>'+datFmt(t.lizenz_gueltig)+'</div>'
    +'<div><span class="i-muted">Rettungsschwimmer bis</span><br>'+datFmt(t.rettungsschwimmer)+'</div>'
    +'<div><span class="i-muted">Verhaltenskodex</span><br>'+jaNein(t.verhaltenskodex)+'</div>'
    +'<div><span class="i-muted">Datenschutz unterz.</span><br>'+jaNein(t.datenschutz)+'</div>'
    +'</div>'
    +'</div>';
  $('#m-trainer-detail-ttl').text(esc(t.display_name||t.name));
  $('#m-trainer-detail-bd').html(h);
  openModal('m-trainer-detail');
}
$(document).on('click','.i-vh',function(e){
  if($(e.target).is('button')||$(e.target).closest('button').length)return;
  var aid=$(this).data('aid');
  $('#vb-'+aid).toggleClass('open');
});
$(document).on('click','.vw-ok',function(e){
  e.stopPropagation();if(!confirm('Genehmigen?'))return;
  var id=$(this).data('id');
  ajax('lsv07i_abr_genehmigen',{abrechnung_id:id}).done(function(r){if(r.success){toast('Genehmigt.');ladeVwAbr($('#vw-filter').val());}else toast(r.data.message,'err');});
});
$(document).on('click','.vw-rg',function(e){
  e.stopPropagation();$('#rg-id').val($(this).data('id'));$('#rg-grund').val('');openModal('m-rg');
});
$('#rg-send').on('click',function(){
  var g=$('#rg-grund').val().trim();if(!g){toast('Bitte Grund angeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Sendet…');
  ajax('lsv07i_abr_zurueckgeben',{abrechnung_id:$('#rg-id').val(),grund:g}).done(function(r){
    if(r.success){closeModal('m-rg');toast('Zurückgegeben.');ladeVwAbr($('#vw-filter').val());}else toast(r.data.message,'err');
  }).always(function(){$b.prop('disabled',false).text('Zurückgeben');});
});
var S_kwData = [];  // gecachte KW-Daten

$('#kw-laden').on('click', function () {
  var isAdmin=LSV07I.access&&LSV07I.access.is_admin_raw;
  var $btn = $(this).prop('disabled', true).text('Laden…');
  skel($('#kw-liste'),'rows',5);
  ajax('lsv07i_abr_get_alle', {status: 'genehmigt'}).done(function (r) {
    if (!r.success) { toast(r.data && r.data.message ? r.data.message : 'Fehler beim Laden.', 'err'); $('#kw-liste').html('<span class="i-muted">Fehler beim Laden.</span>'); return; }
    S_kwData = r.data || [];
    if (!S_kwData.length) { $('#kw-liste').html('<span class="i-muted">Keine genehmigten Abrechnungen.</span>'); return; }
    var h = '';
    $.each(S_kwData, function (i, a) {
      var tage = a.trainingstage || [], wk = a.wettkampf || [], km = a.kilometer || [];
      var tTbl = '', wkTbl = '', kmTbl = '', sonderTbl = '';
      if (tage.length) {
        tTbl = '<div class="kw-section-lbl">Trainingstage</div><table class="i-tbl"><thead><tr><th>Datum</th><th>Mannschaft</th><th>Stunden</th><th>Betrag</th></tr></thead><tbody>';
        $.each(tage, function (j, t) { tTbl += '<tr><td data-label="Datum">' + de(t.datum) + '</td><td data-label="Mannschaft">' + esc(t.mannschaft_name) + '</td><td data-label="Stunden">' + parseFloat(t.stunden).toFixed(2) + '</td><td data-label="Betrag">' + eur(parseFloat(t.stunden) * (parseFloat(a.stundensatz) || 0)) + '</td></tr>'; });
        tTbl += '<tr style="font-weight:700;background:rgba(255,255,255,0.08)"><td colspan="2">Gesamt Training</td><td>' + parseFloat(a.training_stunden || 0).toFixed(2) + ' Std.</td><td>' + eur(a.training_betrag) + '</td></tr>';
        if (a.anzahl_mit_vorbereitung > 0) {
          tTbl += '<tr style="background:rgba(91,148,255,0.08)"><td colspan="2">+15 Min &times; ' + a.anzahl_mit_vorbereitung + '</td><td>' + parseFloat(a.vorbereitung_stunden || 0).toFixed(2) + ' Std.</td><td>' + eur(a.vorbereitung_betrag) + '</td></tr>';
        }
        tTbl += '</tbody></table>';
      }
      if (wk.length) {
        wkTbl = '<div class="kw-section-lbl">Wettkämpfe</div><table class="i-tbl"><thead><tr><th>Datum</th><th>Wettkampf</th><th>Abschnitte</th><th>Betrag</th></tr></thead><tbody>';
        $.each(wk, function (j, w) { wkTbl += '<tr><td data-label="Datum">' + de(w.datum) + '</td><td data-label="Wettkampf">' + esc(w.name || 'Wettkampf') + '</td><td data-label="Abschn.">' + w.abschnitte + '</td><td data-label="Betrag">' + eur(w.betrag) + '</td></tr>'; });
        wkTbl += '<tr style="font-weight:700;background:rgba(255,255,255,0.08)"><td colspan="3">Gesamt Wettkämpfe</td><td>' + eur(a.wettkampf_betrag) + '</td></tr></tbody></table>';
      }
      if (km.length) {
        kmTbl = '<div class="kw-section-lbl">Fahrtkosten</div><table class="i-tbl"><thead><tr><th>Datum</th><th>Beschreibung</th><th>km</th><th>Betrag</th></tr></thead><tbody>';
        $.each(km, function (j, k) { kmTbl += '<tr><td data-label="Datum">' + de(k.datum) + '</td><td data-label="Beschreibung">' + esc(k.beschreibung || 'Fahrt') + '</td><td data-label="km">' + parseFloat(k.km).toFixed(1) + '</td><td data-label="Betrag">' + eur(k.betrag) + '</td></tr>'; });
        kmTbl += '<tr style="font-weight:700;background:rgba(255,255,255,0.08)"><td colspan="3">Gesamt Fahrtkosten</td><td>' + eur(a.km_betrag) + '</td></tr></tbody></table>';
      }
      var sonder = a.sonder || [];
      if (sonder.length) {
        sonderTbl = '<div class="kw-section-lbl">Sonderabrechnung</div><table class="i-tbl"><thead><tr><th>Datum</th><th>Beschreibung</th><th>Std.</th><th>Betrag</th></tr></thead><tbody>';
        $.each(sonder, function (j, t) { sonderTbl += '<tr><td data-label="Datum">' + de(t.datum) + '</td><td data-label="Beschreibung">' + esc(t.beschreibung || '') + '</td><td data-label="Std.">' + parseFloat(t.stunden).toFixed(2) + '</td><td data-label="Betrag">' + eur(parseFloat(t.stunden) * (parseFloat(a.stundensatz) || 0)) + '</td></tr>'; });
        sonderTbl += '<tr style="font-weight:700;background:rgba(255,255,255,0.08)"><td colspan="3">Gesamt Sonderabrechnung</td><td>' + eur(a.sonder_betrag) + '</td></tr></tbody></table>';
      }
      var vorbZeile = (a.anzahl_mit_vorbereitung > 0)
        ? '<span>+15 Min × ' + a.anzahl_mit_vorbereitung + ': <strong>' + eur(a.vorbereitung_betrag) + '</strong></span>'
        : '';
      var sonderZeile = (a.sonder_betrag > 0)
        ? '<span>Sonderabrechnung: <strong>' + eur(a.sonder_betrag) + '</strong></span>'
        : '';
      var sumBox = '<div class="kw-summe-box">'
        + '<span>Stundensatz: <strong>' + eur(a.stundensatz) + '/Std.</strong></span>'
        + '<span>Training: <strong>' + eur(a.training_betrag) + '</strong></span>'
        + vorbZeile
        + '<span>Wettkämpfe: <strong>' + eur(a.wettkampf_betrag) + '</strong></span>'
        + '<span>Fahrtkosten: <strong>' + eur(a.km_betrag) + '</strong></span>'
        + sonderZeile
        + '<span class="kw-gesamt">Gesamt: ' + eur(a.gesamt) + '</span>'
        + '</div>';
      h += '<div class="i-vi kw-vi" style="margin-bottom:10px">'
        + '<div class="i-vh kw-vh" data-kwid="' + a.id + '">'
          + '<div>'
            + '<div style="font-weight:600;font-size:13px">' + esc(a.trainer_name) + ' – ' + a.quartal + ' ' + a.jahr + (a.bezahlt=='1'?' '+bdg('g','Bezahlt'):'') + bereichLabel(a.bereich) + '</div>'
            + (a.genehmigt_am ? '<div style="font-size:12px;color:#6b6e85">Genehmigt: ' + de(a.genehmigt_am.slice(0, 10)) + (a.bezahlt_am?' | Bezahlt: '+de(a.bezahlt_am.slice(0,10)):'') + '</div>' : '')
          + '</div>'
          + '<div style="display:flex;gap:6px">'
            + (a.bezahlt=='1'
                ? '<button class="i-btn i-btn-sm i-btn-g kw-unbezahlt-btn" data-kwid="' + a.id + '">Bezahlt ↩</button>'
                : '<button class="i-btn i-btn-sm i-btn-ok kw-bezahlt-btn" data-kwid="' + a.id + '">Als bezahlt markieren</button>')
            + '<button class="i-btn i-btn-sm i-btn-g kw-pdf-btn" data-kwid="' + a.id + '">PDF</button>'
            + (isAdmin ? '<button class="i-btn i-btn-sm i-btn-r kw-del-btn" data-kwid="' + a.id + '">Löschen</button>' : '')
          + '</div>'
        + '</div>'
        + '<div class="i-vb" id="kw-body-' + a.id + '">'
          + sumBox + tTbl + wkTbl + kmTbl + sonderTbl
          + (!tage.length && !wk.length && !km.length ? '<span class="i-muted">Keine Einträge.</span>' : '')
        + '</div>'
        + '</div>';
    });
    $('#kw-liste').html(h);
  }).fail(function (xhr) { toast(errMsg(xhr), 'err'); $('#kw-liste').html('<span class="i-muted">Fehler beim Laden.</span>'); })
    .always(function () { $btn.prop('disabled', false).text('Laden'); });
});

// KW Akkordeon – eigene Klasse, kein Konflikt mit VW
$(document).on('click', '.kw-vh', function (e) {
  if ($(e.target).is('button') || $(e.target).closest('button').length) return;
  var id = $(this).data('kwid');
  $('#kw-body-' + id).toggleClass('open');
});

// PDF aus gecachten Daten – kein extra AJAX nötig
$(document).on('click', '.kw-pdf-btn', function (e) {
  e.stopPropagation();
  var id = $(this).data('kwid');
  var a = null;
  $.each(S_kwData, function (i, x) { if (x.id == id) { a = x; return false; } });
  if (!a) { toast('Daten nicht gefunden. Bitte neu laden.', 'err'); return; }
  kwGeneratePdf(a);
});

// Bezahlt markieren
$(document).on('click', '.kw-bezahlt-btn', function (e) {
  e.stopPropagation();
  var id = $(this).data('kwid');
  if (!confirm('Abrechnung als bezahlt markieren?')) return;
  var $b = $(this).prop('disabled', true);
  ajax('lsv07i_abr_bezahlt', {abrechnung_id: id, bezahlt: 1}).done(function (r) {
    if (r.success) {
      // Aktualisiere den gecachten Eintrag und rendere neu
      $.each(S_kwData, function (i, x) { if (x.id == id) { S_kwData[i] = r.data; return false; } });
      $('#kw-laden').trigger('click');
      toast('Als bezahlt markiert.');
    } else { toast(r.data && r.data.message ? r.data.message : 'Fehler.', 'err'); }
  }).fail(function (xhr) { toast(errMsg(xhr), 'err'); }).always(function () { $b.prop('disabled', false); });
});

// Bezahlt zurücksetzen
$(document).on('click', '.kw-unbezahlt-btn', function (e) {
  e.stopPropagation();
  var id = $(this).data('kwid');
  if (!confirm('Bezahlt-Markierung entfernen?')) return;
  var $b = $(this).prop('disabled', true);
  ajax('lsv07i_abr_bezahlt', {abrechnung_id: id, bezahlt: 0}).done(function (r) {
    if (r.success) {
      $.each(S_kwData, function (i, x) { if (x.id == id) { S_kwData[i] = r.data; return false; } });
      $('#kw-laden').trigger('click');
      toast('Bezahlt-Markierung entfernt.');
    } else { toast(r.data && r.data.message ? r.data.message : 'Fehler.', 'err'); }
  }).fail(function (xhr) { toast(errMsg(xhr), 'err'); }).always(function () { $b.prop('disabled', false); });
});

// Abrechnung löschen
$(document).on('click', '.kw-del-btn', function (e) {
  e.stopPropagation();
  var id = $(this).data('kwid');
  var name = $(this).closest('.kw-vi').find('.kw-vh > div > div:first-child').text();
  if (!confirm('Abrechnung "' + name.trim() + '" wirklich endgültig löschen?\n\nDiese Aktion kann nicht rückgängig gemacht werden!')) return;
  var $b = $(this).prop('disabled', true);
  ajax('lsv07i_abr_delete_abrechnung', {abrechnung_id: id}).done(function (r) {
    if (r.success) {
      S_kwData = S_kwData.filter(function (x) { return x.id != id; });
      $('#kw-laden').trigger('click');
      toast('Abrechnung gelöscht.');
    } else { toast(r.data && r.data.message ? r.data.message : 'Fehler.', 'err'); }
  }).fail(function (xhr) { toast(errMsg(xhr), 'err'); }).always(function () { $b.prop('disabled', false); });
});

/* Gemeinsame Formatierer für jsPDF-Ausgaben. Bewusst getrennt vom UI-eur():
   jsPDF-Standardfonts (Latin-1) stellen das Euro-Zeichen nicht dar, daher
   'EUR' statt '€'. Von kwGeneratePdf UND kwGenerateJahrPdf genutzt.       */
function pdfEur(n) { return parseFloat(n || 0).toFixed(2).replace('.', ',') + ' EUR'; }
function pdfDe(s) { if (!s) return ''; var p = s.split('-'); return p[2] + '.' + p[1] + '.' + p[0]; }

function kwGeneratePdf(a) {
  var fmtEur = pdfEur, fmtDe = pdfDe;
  if (!window.jspdf || !window.jspdf.jsPDF) { toast('PDF-Bibliothek nicht geladen. Bitte Seite neu laden.', 'err'); return; }
  var doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' });
  var pageW = doc.internal.pageSize.getWidth();
  var mL = 15, mR = 15, y = 18;
  var colBlue = [30, 58, 95], colGray = [90, 90, 90], colLine = [200, 200, 200], colHead = [232, 238, 249];

  function checkPage(need) {
    if (y + (need || 8) > doc.internal.pageSize.getHeight() - 15) { doc.addPage(); y = 18; }
  }
  // Titel
  doc.setFont('helvetica', 'bold'); doc.setFontSize(17); doc.setTextColor.apply(doc, colBlue);
  doc.text('Abrechnung ' + a.quartal + ' ' + a.jahr, mL, y); y += 7;
  // Untertitel
  doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.setTextColor.apply(doc, colGray);
  var sub = 'Trainer: ' + a.trainer_name;
  if (a.genehmigt_am) sub += '   |   Genehmigt am: ' + fmtDe(a.genehmigt_am.slice(0, 10));
  doc.text(sub, mL, y); y += 5;
  if (a.bezahlt == '1' && a.bezahlt_am) {
    doc.setTextColor(21, 128, 61); doc.text('Bezahlt am: ' + fmtDe(a.bezahlt_am.slice(0, 10)), mL, y); y += 5;
  }
  y += 3;
  // Zusammenfassungs-Box: Items dynamisch in 2 Spalten, damit die Box mit
  // wächst, wenn eine +15-Min-Vorbereitungszeile hinzukommt.
  var items = [
    'Stundensatz: ' + fmtEur(a.stundensatz) + '/Std.',
    'Training: ' + fmtEur(a.training_betrag)
  ];
  if (a.anzahl_mit_vorbereitung > 0) {
    items.push('+15 Min x ' + a.anzahl_mit_vorbereitung + ': ' + fmtEur(a.vorbereitung_betrag));
  }
  items.push('Wettkaempfe: ' + fmtEur(a.wettkampf_betrag));
  items.push('Fahrtkosten: ' + fmtEur(a.km_betrag));
  var rows2 = Math.ceil(items.length / 2);
  var boxH = rows2 * 6 + 6;
  doc.setFillColor(238, 243, 252); doc.roundedRect(mL, y, pageW - mL - mR, boxH, 2, 2, 'F');
  doc.setFontSize(9); doc.setTextColor.apply(doc, colGray); doc.setFont('helvetica', 'normal');
  items.forEach(function (txt, i) {
    var col = i % 2, row = Math.floor(i / 2);
    doc.text(txt, mL + 4 + col * 56, y + 6 + row * 6);
  });
  doc.setFont('helvetica', 'bold'); doc.setFontSize(12); doc.setTextColor.apply(doc, colBlue);
  doc.text('Gesamt: ' + fmtEur(a.gesamt), pageW - mR - 4, y + boxH / 2 + 1.5, { align: 'right' });
  y += boxH + 6;

  // Tabellen-Helfer
  function table(titel, cols, widths, rows, sumRow) {
    checkPage(20);
    doc.setFont('helvetica', 'bold'); doc.setFontSize(10); doc.setTextColor.apply(doc, colBlue);
    doc.text(titel, mL, y); y += 2;
    doc.setDrawColor.apply(doc, colLine); doc.line(mL, y, pageW - mR, y); y += 4;
    // Kopfzeile
    var x = mL;
    doc.setFillColor.apply(doc, colHead); doc.rect(mL, y - 3.5, pageW - mL - mR, 6, 'F');
    doc.setFontSize(8.5); doc.setTextColor(40, 40, 40); doc.setFont('helvetica', 'bold');
    cols.forEach(function (c, i) {
      var alignRight = (i === cols.length - 1);
      doc.text(c, alignRight ? x + widths[i] - 2 : x + 1, y, { align: alignRight ? 'right' : 'left' });
      x += widths[i];
    });
    y += 4;
    // Datenzeilen
    doc.setFont('helvetica', 'normal'); doc.setTextColor(30, 30, 30);
    rows.forEach(function (r) {
      checkPage(6);
      x = mL;
      r.forEach(function (cell, i) {
        var alignRight = (i === r.length - 1);
        doc.text(String(cell), alignRight ? x + widths[i] - 2 : x + 1, y, { align: alignRight ? 'right' : 'left' });
        x += widths[i];
      });
      doc.setDrawColor(235, 235, 235); doc.line(mL, y + 1.5, pageW - mR, y + 1.5);
      y += 5;
    });
    // Summenzeile(n) — sumRow kann eine einzelne Zeile oder ein Array
    // mehrerer Zeilen sein (z.B. Gesamt Training + separate +15-Min-Zeile).
    if (sumRow) {
      var sumRows = Array.isArray(sumRow[0]) ? sumRow : [sumRow];
      sumRows.forEach(function (sr) {
        checkPage(6); x = mL;
        doc.setFillColor(243, 244, 246); doc.rect(mL, y - 3.5, pageW - mL - mR, 6, 'F');
        doc.setFont('helvetica', 'bold'); doc.setTextColor(30, 30, 30);
        sr.forEach(function (cell, i) {
          if (cell === null) return;
          var alignRight = (i === sr.length - 1);
          doc.text(String(cell), alignRight ? x + widths[i] - 2 : x + 1, y, { align: alignRight ? 'right' : 'left' });
          x += widths[i];
        });
        y += 6;
      });
    }
    y += 4;
  }

  var tage = a.trainingstage || [], wk = a.wettkampf || [], km = a.kilometer || [];
  var innerW = pageW - mL - mR;
  if (tage.length) {
    var rows = tage.map(function (t) {
      return [fmtDe(t.datum), t.mannschaft_name || '', parseFloat(t.stunden).toFixed(2), fmtEur(parseFloat(t.stunden) * (parseFloat(a.stundensatz) || 0))];
    });
    var trainSumRows = [['Gesamt Training', '', parseFloat(a.training_stunden || 0).toFixed(2) + ' Std.', fmtEur(a.training_betrag)]];
    if (a.anzahl_mit_vorbereitung > 0) {
      trainSumRows.push(['+15 Min x ' + a.anzahl_mit_vorbereitung, '', parseFloat(a.vorbereitung_stunden || 0).toFixed(2) + ' Std.', fmtEur(a.vorbereitung_betrag)]);
    }
    table('Trainingstage', ['Datum', 'Mannschaft', 'Stunden', 'Betrag'],
      [innerW * 0.22, innerW * 0.46, innerW * 0.14, innerW * 0.18], rows, trainSumRows);
  }
  if (wk.length) {
    var rows2 = wk.map(function (w) { return [fmtDe(w.datum), w.name || 'Wettkampf', String(w.abschnitte), fmtEur(w.betrag)]; });
    table('Wettkaempfe', ['Datum', 'Wettkampf', 'Abschnitte', 'Betrag'],
      [innerW * 0.22, innerW * 0.46, innerW * 0.14, innerW * 0.18], rows2,
      ['Gesamt Wettkaempfe', '', '', fmtEur(a.wettkampf_betrag)]);
  }
  if (km.length) {
    var rows3 = km.map(function (k) { return [fmtDe(k.datum), k.beschreibung || 'Fahrt', parseFloat(k.km).toFixed(1), fmtEur(k.betrag)]; });
    table('Fahrtkosten', ['Datum', 'Beschreibung', 'km', 'Betrag'],
      [innerW * 0.22, innerW * 0.46, innerW * 0.14, innerW * 0.18], rows3,
      ['Gesamt Fahrtkosten', '', '', fmtEur(a.km_betrag)]);
  }

  var fname = 'Abrechnung_' + a.quartal + '_' + a.jahr + '_' + (a.trainer_name || '').replace(/[^a-zA-Z0-9]+/g, '_') + '.pdf';
  doc.save(fname);
}


/* ══ TRAINER ════════════════════════════════════════════════════ */
function initQ(){var q=Math.ceil((new Date().getMonth()+1)/3);$('#tr-quartal').val('Q'+q);$('#tr-sonder-quartal').val('Q'+q);}

var S_config={km_satz:0.30,km_min:0,wk_pauschale:25};

$('#tr-laden').on('click',function(){
  var $b=$(this).prop('disabled',true).text('Laden…');
  ajax('lsv07i_abr_get_or_create',{quartal:$('#tr-quartal').val(),jahr:$('#tr-jahr').val()}).done(function(r){
    if(!r.success){toast(r.data.message,'err');return;}
    S.abr=r.data;
    if(r.data.config)S_config=r.data.config;
    if(r.data.mannschaften)S.mann=r.data.mannschaften;
    renderTrAbr(r.data);
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Laden / Erstellen');});
});

function eur(n){return parseFloat(n||0).toFixed(2).replace('.',',')+'&nbsp;€';}

function renderTrAbr(abr){
  // Aktiven Sub-Tab merken um ihn nach dem Rendern wiederherzustellen
  var activePanel=$('#i-p-tr-training').parent().find('>.i-panel:visible').attr('id')||'i-p-tr-training';
  var ed=abr.abr_status==='entwurf'||abr.abr_status==='zurueck';
  $('#tr-titel').text('Abrechnung '+abr.quartal+' '+abr.jahr);
  var sb='<div class="i-sb s-'+abr.abr_status+'"><strong>Status:</strong>&nbsp;'+sBdg(abr.abr_status);
  if(abr.abr_status==='zurueck'&&abr.rueckgabe_grund)sb+=' &nbsp;–&nbsp; <span style="color:#b91c1c">'+esc(abr.rueckgabe_grund)+'</span>';
  sb+='</div>';

  // Gesamtübersicht
  var satz=parseFloat(abr.stundensatz||0);
  var vorbBetrag=parseFloat(abr.vorbereitung_betrag||0);
  var vorbStunden=parseFloat(abr.vorbereitung_stunden||0);
  var anzMitVorb=parseInt(abr.anzahl_mit_vorbereitung||0,10);

  sb+='<div class="i-abr-summe">'
    +'<div class="i-abr-summe-item"><span class="i-abr-summe-label">Stundensatz</span><span class="i-abr-summe-val">'+eur(satz)+'/Std.</span></div>'
    +'<div class="i-abr-summe-item"><span class="i-abr-summe-label">Training ('+parseFloat(abr.training_stunden||0).toFixed(2).replace('.',',')+' Std.)</span><span class="i-abr-summe-val">'+eur(abr.training_betrag)+'</span></div>';
  // Vorbereitung-Zeile nur wenn mindestens 1 Training markiert ist
  if(anzMitVorb>0){
    sb+='<div class="i-abr-summe-item"><span class="i-abr-summe-label">+15 Min × '+anzMitVorb+' ('+vorbStunden.toFixed(2).replace('.',',')+' Std.)</span><span class="i-abr-summe-val">'+eur(vorbBetrag)+'</span></div>';
  }
  sb+='<div class="i-abr-summe-item"><span class="i-abr-summe-label">Wettkämpfe</span><span class="i-abr-summe-val">'+eur(abr.wettkampf_betrag)+'</span></div>'
    +'<div class="i-abr-summe-item"><span class="i-abr-summe-label">Kilometergeld</span><span class="i-abr-summe-val">'+eur(abr.km_betrag)+'</span></div>'
    +'<div class="i-abr-summe-item"><span class="i-abr-summe-label">Gesamt</span><span class="i-abr-summe-val total">'+eur(abr.gesamt)+'</span></div>'
    +'</div>';

  $('#tr-statusbar').html(sb);
  $('#tr-aktionen').html(ed?'<button class="i-btn i-btn-p" id="tr-einreichen">Einreichen</button>':'');
  $('#tr-add-tag').css('display',ed?'inline-flex':'none');
  $('#tr-add-wk').css('display',ed?'inline-flex':'none');
  $('#tr-add-wk-offen').css('display',ed?'inline-flex':'none');
  $('#tr-add-km').css('display',ed?'inline-flex':'none');
  $('#tr-detail-card').css('display','block');
  window._curAbrId=abr.id;

  // Proaktiver Hinweis auf evtl. fehlende Wettkampftage: Der automatische
  // Vorschlag beim Laden füllt nur EXPLIZIT als "anwesend" markierte Tage
  // (siehe prefill_wettkampf im Backend — bewusst so, damit nicht ungefragt
  // eine Anwesenheit unterstellt wird, die niemand bestätigt hat). Tage, bei
  // denen der Trainer nur der zuständigen Mannschaft zugeordnet ist, OHNE
  // dass jemand seine Anwesenheit eingetragen hat, werden NICHT automatisch
  // übernommen — genau das führte bisher dazu, dass Wettkämpfe "manchmal"
  // fehlten, weil das Nachtragen-Werkzeug (Button unten) leicht übersehen
  // wurde. Hier wird still im Hintergrund geprüft, ob es offene Tage gibt,
  // und falls ja, ein auffälliger Hinweis mit direktem Link dorthin gezeigt.
  $('#tr-wkoffen-hinweis').remove();
  if(ed && abr.id){
    ajax('lsv07i_wk_offene_tage',{abrechnung_id:abr.id}).done(function(r){
      if(r.success && r.data && r.data.length){
        var n=r.data.length;
        var hinweis='<div id="tr-wkoffen-hinweis" class="i-notice i-notice-a" style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">'
          +'<span>⚠ '+n+' Wettkampftag'+(n>1?'e':'')+' evtl. noch nicht in dieser Abrechnung erfasst.</span>'
          +'<button class="i-btn i-btn-p i-btn-sm" id="tr-wkoffen-jetzt">Jetzt prüfen</button>'
          +'</div>';
        $('#tr-statusbar').append(hinweis);
      }
    });
  }

  // Trainingstage als Karten — mit Vorbereitungs-Checkbox pro Eintrag
  var tage=abr.trainingstage||[];
  if(!tage.length){
    $('#tr-tage').html('<div class="abr-empty">Noch keine Trainingstage. Wurden Anwesenheiten für dieses Quartal eingetragen, werden sie hier automatisch übernommen.</div>');
  } else {
    var h='';
    $.each(tage.slice().sort(function(a,b){return a.datum.localeCompare(b.datum);}),function(i,t){
      var std=parseFloat(t.stunden).toFixed(2).replace('.',',');
      var origBetrag=parseFloat(t.stunden)*satz;
      var vorbAktiv=parseInt(t.vorbereitung_aktiv||0,10)===1;
      var bonusBetrag=vorbAktiv?(0.25*satz):0;
      var zeilenSumme=origBetrag+bonusBetrag;
      var acts=ed?'<button class="i-btn i-btn-g i-btn-sm tr-edit" data-id="'+t.id+'" title="Bearbeiten">✏️</button> <button class="i-btn i-btn-r i-btn-sm tr-del" data-id="'+t.id+'" title="Löschen">🗑</button>':'';
      var quelle=t.quelle==='anwesenheit'?'<span class="abr-bdg abr-bdg-anw">aus Anwesenheit</span>'
                :t.quelle==='springer'?'<span class="abr-bdg abr-bdg-spr">Springer</span>'
                :'<span class="abr-bdg abr-bdg-man">manuell</span>';
      var begrHint=t.begruendung?' <span class="abr-bdg abr-bdg-info" title="'+esc(t.begruendung)+'">Begr.</span>':'';
      // Checkbox-Toggle nur im Bearbeitungs-Modus (eingereicht/genehmigt = Lock)
      var vorbCtrl;
      if(ed){
        vorbCtrl='<label class="abr-vorb-toggle'+(vorbAktiv?' on':'')+'">'
                +'<input type="checkbox" class="tr-vorb" data-id="'+t.id+'"'+(vorbAktiv?' checked':'')+'>'
                +'<span class="abr-vorb-text">+15 Min</span>'
                +'</label>';
      } else {
        vorbCtrl=vorbAktiv?'<span class="abr-vorb-static on">+15 Min</span>':'';
      }
      h+='<div class="abr-card abr-card-training'+(vorbAktiv?' has-bonus':'')+'">'
        +'<div class="abr-card-head">'
        +'<div class="abr-card-date">'+de(t.datum)+'</div>'
        +'<div class="abr-card-title">'+esc(t.mannschaft_name||'Training')+'</div>'
        +'<div class="abr-card-actions">'+acts+'</div>'
        +'</div>'
        +'<div class="abr-card-body">'
        +'<div class="abr-card-tags">'+quelle+begrHint+'</div>'
        +'<div class="abr-card-time">'
        +'<span class="abr-time-base">'+std+' Std.</span>';
      if(vorbAktiv){
        h+='<span class="abr-time-bonus">+ 0,25 Std. Vorbereitung</span>';
      }
      h+='</div>'
        +'<div class="abr-card-amount">'
        +(satz>0?'<span class="abr-amount-base">'+eur(origBetrag)+'</span>':'')
        +(vorbAktiv&&satz>0?'<span class="abr-amount-bonus">+ '+eur(bonusBetrag)+'</span>':'')
        +(satz>0&&vorbAktiv?'<span class="abr-amount-total">= '+eur(zeilenSumme)+'</span>':'')
        +'</div>'
        +'<div class="abr-card-toggle">'+vorbCtrl+'</div>'
        +'</div>'
        +'</div>';
    });
    $('#tr-tage').html(h);
  }

  // Wettkämpfe als Karten
  var wk=abr.wettkampf||[];
  if(!wk.length){
    $('#tr-wk-liste').html('<div class="abr-empty">Noch keine Wettkämpfe eingetragen.</div>');
  } else {
    var h2='';
    $.each(wk.slice().sort(function(a,b){return a.datum.localeCompare(b.datum);}),function(i,w){
      var acts=ed?'<button class="i-btn i-btn-g i-btn-sm wk-edit" data-id="'+w.id+'" title="Bearbeiten">✏️</button> <button class="i-btn i-btn-r i-btn-sm wk-del" data-id="'+w.id+'" title="Löschen">🗑</button>':'';
      var n=parseInt(w.abschnitte||1,10);
      h2+='<div class="abr-card abr-card-wk">'
        +'<div class="abr-card-head">'
        +'<div class="abr-card-date">'+de(w.datum)+'</div>'
        +'<div class="abr-card-title">'+esc(w.name||'Wettkampf')+'</div>'
        +'<div class="abr-card-actions">'+acts+'</div>'
        +'</div>'
        +'<div class="abr-card-body">'
        +'<div class="abr-card-detail">'+n+' Abschnitt'+(n!==1?'e':'')+' × '+eur(w.pauschale)+'</div>'
        +'<div class="abr-card-amount"><span class="abr-amount-total">'+eur(w.betrag)+'</span></div>'
        +'</div>'
        +'</div>';
    });
    $('#tr-wk-liste').html(h2);
  }

  // Kilometer als Karten
  var km=abr.kilometer||[];
  var km_min=S_config.km_min||0;
  if(km_min>0){$('#tr-km-info').html('Mindestens <strong>'+km_min+' km</strong> erforderlich. Satz: <strong>'+S_config.km_satz+' €/km</strong>.').css('display','block');}
  else{$('#tr-km-info').hide().html('');}
  if(!km.length){
    $('#tr-km-liste').html('<div class="abr-empty">Noch keine Fahrtkosten eingetragen.</div>');
  } else {
    var h3='';
    $.each(km.slice().sort(function(a,b){return a.datum.localeCompare(b.datum);}),function(i,k){
      var acts=ed?'<button class="i-btn i-btn-g i-btn-sm km-edit" data-id="'+k.id+'" title="Bearbeiten">✏️</button> <button class="i-btn i-btn-r i-btn-sm km-del" data-id="'+k.id+'" title="Löschen">🗑</button>':'';
      h3+='<div class="abr-card abr-card-km">'
        +'<div class="abr-card-head">'
        +'<div class="abr-card-date">'+de(k.datum)+'</div>'
        +'<div class="abr-card-title">'+esc(k.beschreibung||'Fahrt')+'</div>'
        +'<div class="abr-card-actions">'+acts+'</div>'
        +'</div>'
        +'<div class="abr-card-body">'
        +'<div class="abr-card-detail">'+parseFloat(k.km).toFixed(1).replace('.',',')+' km × '+parseFloat(k.satz).toFixed(4).replace('.',',')+' €/km</div>'
        +'<div class="abr-card-amount"><span class="abr-amount-total">'+eur(k.betrag)+'</span></div>'
        +'</div>'
        +'</div>';
    });
    $('#tr-km-liste').html(h3);
  }

  // Zusammenfassung
  var anzMitVorb=parseInt(abr.anzahl_mit_vorbereitung||0,10);
  $('#tr-summary').html(
    '<div class="abr-sum">'
    +'<div class="abr-sum-row"><span>Trainings:</span><strong>'+tage.length+' <span class="abr-sum-sub">('+parseFloat(abr.training_stunden||0).toFixed(2).replace('.',',')+' Std.)</span></strong></div>'
    +(anzMitVorb>0
       ?'<div class="abr-sum-row abr-sum-row-bonus"><span>+15 Min × '+anzMitVorb+' Trainings:</span><strong>'+parseFloat(abr.vorbereitung_stunden||0).toFixed(2).replace('.',',')+' Std.</strong></div>'
       :'')
    +'<div class="abr-sum-row"><span>Wettkämpfe:</span><strong>'+wk.length+'</strong></div>'
    +'<div class="abr-sum-row"><span>Fahrtkosten:</span><strong>'+km.length+'</strong></div>'
    +'<div class="abr-sum-row abr-sum-total"><span>Gesamt:</span><strong>'+eur(abr.gesamt)+'</strong></div>'
    +'</div>'
  );
  $('#tr-summary-card').css('display','block');
  // Aktiven Sub-Tab wiederherstellen
  if(activePanel&&activePanel!=='i-p-tr-training'){
    var $panel=$('#'+activePanel);
    if($panel.length){
      $panel.siblings('.i-panel').css('display','none');
      $panel.css('display','block');
      // Entsprechenden Tab-Button aktivieren
      $panel.closest('.i-card-bd').find('.i-tabs .i-tab').each(function(){
        if($(this).data('panel')===activePanel){$(this).addClass('on');}else{$(this).removeClass('on');}
      });
    }
  }
}

$(document).on('click','#tr-einreichen',function(){
  if(!confirm('Abrechnung einreichen? Danach keine Bearbeitung mehr möglich.'))return;
  var $b=$(this).prop('disabled',true);
  ajax('lsv07i_abr_einreichen',{abrechnung_id:S.abr.id}).done(function(r){if(r.success){S.abr=r.data;renderTrAbr(r.data);toast('Abrechnung eingereicht.');}else toast(r.data.message,'err');}).always(function(){$b.prop('disabled',false);});
});

// ── Trainingstage ────────────────────────────────────────────────
// Vorbereitungs-Checkbox pro Training toggeln (Auto-Save)
$(document).on('change','.tr-vorb',function(){
  var $cb=$(this);
  var id=$cb.data('id');
  var on=$cb.is(':checked')?1:0;
  $cb.prop('disabled',true);
  // Optimistisches UI-Update vor Server-Antwort
  $cb.closest('.abr-vorb-toggle').toggleClass('on', on===1);
  ajax('lsv07i_abr_toggle_vorbereitung',{id:id, on:on}).done(function(r){
    if(r.success){
      S.abr=r.data;
      renderTrAbr(r.data);
    } else {
      toast(r.data&&r.data.message||'Fehler.','err');
      $cb.prop('checked',!on).closest('.abr-vorb-toggle').toggleClass('on',!on);
    }
  }).fail(function(){
    toast('Speichern fehlgeschlagen.','err');
    $cb.prop('checked',!on).closest('.abr-vorb-toggle').toggleClass('on',!on);
  }).always(function(){$cb.prop('disabled',false);});
});

$(document).on('click','.tr-del',function(){
  if(!confirm('Eintrag löschen?'))return;
  var $b=$(this).prop('disabled',true);
  ajax('lsv07i_abr_delete_trainingstag',{id:$(this).data('id')}).done(function(r){if(r.success){S.abr=r.data;renderTrAbr(r.data);toast('Gelöscht.');}else toast(r.data.message,'err');}).always(function(){$b.prop('disabled',false);});
});
$(document).on('click','.tr-edit',function(){
  var tid=$(this).data('id');var t=null;
  $.each(S.abr&&S.abr.trainingstage||[],function(i,tt){if(tt.id==tid)t=tt;});
  if(!t)return;
  $('#mat-id').val(t.id);$('#mat-abr-id').val(S.abr.id);$('#mat-datum').val(t.datum);$('#mat-std').val(t.stunden);$('#mat-begr').val(t.begruendung||'');
  fillSel($('#mat-mann'),S.mann,'name','name','Wählen…');$('#mat-mann').val(t.mannschaft_name);
  $('#mat-ttl').text('Training bearbeiten');
  // Begründung ist beim Bearbeiten optional
  $('#mat-begr-lbl').html('Begründung <span style="color:#6b6e85;font-weight:400;font-size:11px">(optional)</span>');
  openModal('m-tag');
});
$('#tr-add-tag').on('click',function(){
  if(!S.abr){toast('Bitte erst Abrechnung laden.','err');return;}
  $('#mat-id').val('');$('#mat-datum').val('');$('#mat-begr').val('');$('#mat-std').val('1.5');$('#mat-abr-id').val(S.abr.id);
  fillSel($('#mat-mann'),S.mann,'name','name','Wählen…');
  $('#mat-ttl').text('Training manuell hinzufügen');
  $('#mat-begr-lbl').html('Begründung * <span style="color:#6b6e85;font-weight:400;font-size:11px">(Pflicht für manuelle Einträge)</span>');
  openModal('m-tag');
});
$('#mat-save').on('click',function(){
  var datum=$('#mat-datum').val(),std=$('#mat-std').val(),begr=$('#mat-begr').val().trim();
  var isNew=!$('#mat-id').val();
  if(!datum||!std||parseFloat(std)<=0){toast('Bitte Datum und Stunden angeben.','err');return;}
  if(isNew&&!begr){toast('Bitte eine Begründung für manuell hinzugefügte Trainings angeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_abr_save_trainingstag',{abrechnung_id:$('#mat-abr-id').val(),id:$('#mat-id').val(),datum:datum,mannschaft_name:$('#mat-mann').val(),stunden:std,begruendung:begr}).done(function(r){
    if(r.success){S.abr=r.data;renderTrAbr(r.data);closeModal('m-tag');toast('Gespeichert.');}else toast(r.data.message,'err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});

// ── Wettkämpfe ───────────────────────────────────────────────────
$('#tr-add-wk').on('click',function(){
  if(!S.abr)return;
  $('#mwk-id,#mwk-name').val('');$('#mwk-datum').val('');$('#mwk-abschnitte').val('1');
  $('#mwk-abr-id').val(S.abr.id);updateWkBerechnung();$('#m-wk-ttl').text('Wettkampf hinzufügen');openModal('m-wk');
});
$(document).on('click','.wk-edit',function(){
  var wid=$(this).data('id');var w=null;
  $.each(S.abr&&S.abr.wettkampf||[],function(i,ww){if(ww.id==wid)w=ww;});
  if(!w)return;
  $('#mwk-id').val(w.id);$('#mwk-abr-id').val(S.abr.id);$('#mwk-datum').val(w.datum);$('#mwk-name').val(w.name||'');$('#mwk-abschnitte').val(w.abschnitte);
  updateWkBerechnung();$('#m-wk-ttl').text('Wettkampf bearbeiten');openModal('m-wk');
});
$(document).on('click','.wk-del',function(){
  if(!confirm('Wettkampf löschen?'))return;
  var $b=$(this).prop('disabled',true);
  ajax('lsv07i_abr_delete_wettkampf',{id:$(this).data('id')}).done(function(r){if(r.success){S.abr=r.data;renderTrAbr(r.data);toast('Gelöscht.');}else toast(r.data.message,'err');}).always(function(){$b.prop('disabled',false);});
});
function updateWkBerechnung(){
  var abschnitte=parseInt($('#mwk-abschnitte').val()||1);
  var pauschale=S_config.wk_pauschale||25;
  var betrag=abschnitte*pauschale;
  $('#mwk-berechnung').html(abschnitte+' Abschnitt'+(abschnitte!=1?'e':'')+' × '+eur(pauschale)+' = <strong>'+eur(betrag)+'</strong>').css('display','block');
}
$('#mwk-abschnitte').on('input',updateWkBerechnung);
$('#mwk-save').on('click',function(){
  var datum=$('#mwk-datum').val(),abschn=$('#mwk-abschnitte').val();
  if(!datum||!abschn){toast('Datum und Abschnitte angeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_abr_save_wettkampf',{abrechnung_id:$('#mwk-abr-id').val(),id:$('#mwk-id').val(),datum:datum,name:$('#mwk-name').val(),abschnitte:abschn}).done(function(r){
    if(r.success){S.abr=r.data;renderTrAbr(r.data);closeModal('m-wk');toast('Wettkampf gespeichert.');}else toast(r.data.message,'err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});

// ── Kilometergeld ────────────────────────────────────────────────
$('#tr-add-km').on('click',function(){
  if(!S.abr)return;
  $('#mkm-id,#mkm-beschr').val('');$('#mkm-datum').val('');$('#mkm-km').val('');$('#mkm-tage').val('1');
  $('#mkm-abr-id').val(S.abr.id);$('#mkm-berechnung').hide();$('#m-km-ttl').text('Fahrtkosten hinzufügen');openModal('m-km');
});
$(document).on('click','.km-edit',function(){
  var kid=$(this).data('id');var k=null;
  $.each(S.abr&&S.abr.kilometer||[],function(i,kk){if(kk.id==kid)k=kk;});
  if(!k)return;
  $('#mkm-id').val(k.id);$('#mkm-abr-id').val(S.abr.id);$('#mkm-datum').val(k.datum);$('#mkm-beschr').val(k.beschreibung||'');
  // Gespeichert ist die Gesamtstrecke (einfach × 2 × Tage). Im Feld zeigen wir
  // wieder die einfache Strecke und die Anzahl Tage, da der Nutzer diese eingibt.
  var tage=Math.max(1,parseInt(k.tage)||1);
  $('#mkm-tage').val(tage);
  $('#mkm-km').val(Math.round((parseFloat(k.km)/(2*tage))*100)/100);
  updateKmBerechnung();$('#m-km-ttl').text('Fahrtkosten bearbeiten');openModal('m-km');
});
$(document).on('click','.km-del',function(){
  if(!confirm('Fahrtkosten-Eintrag löschen?'))return;
  var $b=$(this).prop('disabled',true);
  ajax('lsv07i_abr_delete_kilometer',{id:$(this).data('id')}).done(function(r){if(r.success){S.abr=r.data;renderTrAbr(r.data);toast('Gelöscht.');}else toast(r.data.message,'err');}).always(function(){$b.prop('disabled',false);});
});
function updateKmBerechnung(){
  var kmEinfach=parseFloat($('#mkm-km').val()||0);
  var tage=Math.max(1,parseInt($('#mkm-tage').val())||1);
  var satz=S_config.km_satz||0.30;
  var min=S_config.km_min||0;
  if(kmEinfach<=0){$('#mkm-berechnung').hide();return;}
  var fahrten=2*tage;
  var kmGesamt=kmEinfach*fahrten;
  var betrag=kmGesamt*satz;
  var txt=kmEinfach.toFixed(1)+' km × '+fahrten+' Fahrten ('+tage+(tage===1?' Tag':' Tage')+' × Hin/Rück) = '+kmGesamt.toFixed(1)+' km × '+satz+' €/km = <strong>'+eur(betrag)+'</strong>';
  if(min>0&&kmEinfach<min) txt+=' <span style="color:#b91c1c">(Minimum '+min+' km nicht erreicht – wird abgelehnt)</span>';
  $('#mkm-berechnung').html(txt).css('display','block');
}
$('#mkm-km').on('input',updateKmBerechnung);
$('#mkm-tage').on('input',updateKmBerechnung);
$('#mkm-save').on('click',function(){
  var datum=$('#mkm-datum').val(),km=$('#mkm-km').val(),tage=$('#mkm-tage').val();
  if(!datum||!km||parseFloat(km)<=0){toast('Datum und km angeben.','err');return;}
  if(!tage||parseInt(tage)<1){toast('Bitte mindestens 1 Tag angeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_abr_save_kilometer',{abrechnung_id:$('#mkm-abr-id').val(),id:$('#mkm-id').val(),datum:datum,beschreibung:$('#mkm-beschr').val(),km:km,tage:tage}).done(function(r){
    if(r.success){S.abr=r.data;renderTrAbr(r.data);closeModal('m-km');toast('Fahrtkosten gespeichert.');}else toast(r.data.message,'err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});

// ── Stammdaten ───────────────────────────────────────────────────
function loadStammdaten(){
  // Admin legt per get_or_create automatisch ein Trainer-Profil an — immer laden
  ajax('lsv07i_abr_get_stammdaten').done(function(r){
    if(!r.success)return;
    var d=r.data.stammdaten||{};
    var cfg=r.data.config||{};
    var satz=parseFloat(d.stundensatz||0);
    $('#sd-satz').val(satz>0?satz:'');
    $('#sd-iban').val(d.iban||'');$('#sd-bic').val(d.bic||'');
    $('#sd-empfaenger').val(d.empfaenger_name||'');
    $('#sd-strasse').val(d.strasse||'');$('#sd-plz').val(d.plz||'');$('#sd-ort').val(d.ort||'');
    if(cfg.km_satz)S_config.km_satz=cfg.km_satz;
    if(cfg.km_min!==undefined)S_config.km_min=cfg.km_min;
    if(cfg.wk_pauschale)S_config.wk_pauschale=cfg.wk_pauschale;
    // Stundensatz sofort in laufende Abrechnung einpflegen
    if(S.abr&&satz>0){
      S.abr.stundensatz=satz;
      abrRecalc(S.abr);
      renderTrAbr(S.abr);
    }
  });
}

// Zentrale Berechnung — spiegelt das Backend (load_abrechnung) wider.
// Vorbereitungs-Bonus = 0.25h × Anzahl Trainings MIT vorbereitung_aktiv=1.
function abrRecalc(a){
  if(!a)return;
  var satz=parseFloat(a.stundensatz||0);
  var tage=a.trainingstage||[];
  var anzahlMitVorb=0;
  $.each(tage,function(i,t){
    if(parseInt(t.vorbereitung_aktiv||0,10)===1)anzahlMitVorb++;
  });
  var stunden=parseFloat(a.training_stunden||0);
  a.anzahl_trainings=tage.length;
  a.anzahl_mit_vorbereitung=anzahlMitVorb;
  a.training_betrag=Math.round(stunden*satz*100)/100;
  a.vorbereitung_stunden=Math.round(anzahlMitVorb*0.25*100)/100;
  a.vorbereitung_betrag=Math.round(a.vorbereitung_stunden*satz*100)/100;
  a.gesamt=Math.round(
    (a.training_betrag + a.vorbereitung_betrag
     + parseFloat(a.wettkampf_betrag||0)
     + parseFloat(a.km_betrag||0)) * 100
  )/100;
}

$('#sd-save').on('click',function(){
  var satz=$('#sd-satz').val();
  if(!satz||parseFloat(satz)<=0){toast('Bitte einen gültigen Stundensatz eingeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_abr_save_stammdaten',{
    stundensatz:satz,
    iban:$('#sd-iban').val(),
    bic:$('#sd-bic').val(),
    empfaenger_name:$('#sd-empfaenger').val(),
    strasse:$('#sd-strasse').val(),
    plz:$('#sd-plz').val(),
    ort:$('#sd-ort').val()
  }).done(function(r){
    if(r.success){
      toast('Stammdaten gespeichert.');
      if(S.abr){
        S.abr.stundensatz=parseFloat(satz);
        abrRecalc(S.abr);
        renderTrAbr(S.abr);
      }
    }else toast(r.data.message,'err');
  }).always(function(){$b.prop('disabled',false).text('Stammdaten speichern');});
});

/* ══ ADMIN ══════════════════════════════════════════════════════ */
function loadAdmin(){
  S.adminDone=true;
  ajax('lsv07i_admin_get_data').done(function(r){
    if(!r.success)return;
    S.trainer=r.data.trainer||[];S.mann=r.data.mannschaften||[];S.slots=r.data.slots||[];S.schwimmer=r.data.schwimmer||[];
    renderAdmTr();renderAdmMn();renderAdmSl();renderAdmSw();
    // Triathlon- und Fitness-Daten für Admin-Tabs laden
    if(!S_tri.done)loadTriathlon();
    if(!S_fit.done)loadFitness();
    fillSel($('#adm-sw-filt'),S.mann,'id','name','Alle Mannschaften');
  fillSel($('#import-sw-mann'),S.mann,'id','name','Keine / Manuell zuweisen');
  });
  ajax('lsv07i_admin_get_wp_users').done(function(r){
    if(!r.success)return;var $s=$('#mt-user');$s.find('option:not(:first-child)').remove();
    $.each(r.data,function(i,u){$s.append('<option value="'+u.id+'">'+esc(u.name+' ('+u.email+')')+'</option>');});
  });
  ajax('lsv07i_admin_get_config').done(function(r){
    if(!r.success)return;var c=r.data;
    $('#cfg-km').val(c.km_satz||'');$('#cfg-km-min').val(c.km_min||'');$('#cfg-wk').val(c.wk_pauschale||'');$('#cfg-mail').val(c.mail_schwimmwart||'');$('#cfg-mahn').val(c.anwesenheit_mahnung_stunden||'');
    $('#cfg-attest-tage').val(c.attest_warnung_tage||'21');
    $('#cfg-mail_deaktiviert').prop('checked',c.mail_deaktiviert==='1');
    // Mail-Toggles setzen (default: aktiviert)
    $('.mail-toggle').each(function(){var key=$(this).data('key');$(this).prop('checked',(c[key]||'1')==='1');});
  });
  // Rechte-Verwaltung sofort vorladen, damit beim Tab-Wechsel keine Wartezeit ist
  if(typeof loadRechte==='function')loadRechte();
  loadWkMailEmpfaenger();
}

/* ══ WETTKÄMPFE: zusätzliche Mail-Empfänger (Admin) ══════════════ */
function loadWkMailEmpfaenger(){
  ajax('lsv07i_wk_mail_empf_liste').done(function(r){
    if(r.success)renderWkMailEmpfaenger(r.data.empfaenger||[]);
  });
}
function renderWkMailEmpfaenger(list){
  if(!list.length){$('#wk-mail-empf-liste').html('<span class="i-muted" style="font-size:12px">Noch keine zusätzlichen Empfänger hinterlegt.</span>');return;}
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>E-Mail</th><th>Freigabe-Anfrage</th><th>Meldeergebnis</th><th>Protokoll</th><th></th></tr></thead><tbody>';
  $.each(list,function(i,e){
    h+='<tr data-id="'+e.id+'">'
      +'<td data-label="E-Mail">'+esc(e.email)+'</td>'
      +'<td data-label="Freigabe-Anfrage" style="text-align:center"><input type="checkbox" class="wk-mail-empf-toggle" data-id="'+e.id+'" data-feld="freigabe_anfrage"'+(parseInt(e.freigabe_anfrage,10)===1?' checked':'')+'></td>'
      +'<td data-label="Meldeergebnis" style="text-align:center"><input type="checkbox" class="wk-mail-empf-toggle" data-id="'+e.id+'" data-feld="erinnerung_meldeergebnis"'+(parseInt(e.erinnerung_meldeergebnis,10)===1?' checked':'')+'></td>'
      +'<td data-label="Protokoll" style="text-align:center"><input type="checkbox" class="wk-mail-empf-toggle" data-id="'+e.id+'" data-feld="erinnerung_protokoll"'+(parseInt(e.erinnerung_protokoll,10)===1?' checked':'')+'></td>'
      +'<td data-label=""><button type="button" class="i-btn i-btn-r i-btn-sm wk-mail-empf-del" data-id="'+e.id+'">Löschen</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#wk-mail-empf-liste').html(h);
}
$(document).on('change','.wk-mail-empf-toggle',function(){
  var $row=$(this).closest('tr');
  var email=$row.find('td').first().text();
  ajax('lsv07i_wk_mail_empf_save',{
    email:email,
    freigabe_anfrage:$row.find('[data-feld="freigabe_anfrage"]').is(':checked')?1:0,
    erinnerung_meldeergebnis:$row.find('[data-feld="erinnerung_meldeergebnis"]').is(':checked')?1:0,
    erinnerung_protokoll:$row.find('[data-feld="erinnerung_protokoll"]').is(':checked')?1:0
  }).done(function(r){
    if(r.success)toast('Gespeichert.');else toast(r.data&&r.data.message||'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});
$(document).on('click','.wk-mail-empf-del',function(){
  if(!confirm('Diesen Empfänger wirklich löschen?'))return;
  var id=$(this).data('id');
  ajax('lsv07i_wk_mail_empf_delete',{id:id}).done(function(r){
    if(r.success){toast('Gelöscht.');loadWkMailEmpfaenger();}
    else toast(r.data&&r.data.message||'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});
$('#wk-mail-empf-add').on('click',function(){
  var email=$('#wk-mail-empf-email').val();
  if(!email||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){toast('Bitte eine gültige E-Mail-Adresse angeben.','err');return;}
  var $b=$(this).prop('disabled',true);
  ajax('lsv07i_wk_mail_empf_save',{
    email:email,
    freigabe_anfrage:$('#wk-mail-empf-freigabe').is(':checked')?1:0,
    erinnerung_meldeergebnis:$('#wk-mail-empf-meld').is(':checked')?1:0,
    erinnerung_protokoll:$('#wk-mail-empf-prot').is(':checked')?1:0
  }).done(function(r){
    if(r.success){
      toast('Gespeichert.');
      $('#wk-mail-empf-email').val('');
      $('#wk-mail-empf-freigabe,#wk-mail-empf-meld,#wk-mail-empf-prot').prop('checked',false);
      loadWkMailEmpfaenger();
    }else toast(r.data&&r.data.message||'Fehler.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false);});
});

// Admin Trainer
function renderAdmTr(){
  if(!S.trainer.length){$('#adm-tr-liste').html('<span class="i-muted">Keine Trainer.</span>');return;}
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Telefon</th><th>E-Mail</th><th>Mannschaften</th><th></th></tr></thead><tbody>';
  $.each(S.trainer,function(i,t){
    var ids=t.mannschaft_ids ? String(t.mannschaft_ids).split(',').map(function(x){return x.trim();}) : [];
    var mn=ids.map(function(id){
      var m=null;$.each(S.mann,function(j,mm){if(String(mm.id)===id)m=mm;});
      return m?esc(m.name):'';
    }).filter(Boolean).join(', ')||'–';
    var dn=t.display_name||t.name;
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(dn)+'</strong></td>'
      +'<td data-label="Telefon">'+esc(t.telefon||'–')+'</td>'
      +'<td data-label="E-Mail">'+(t.email_privat||t.email?'<a href="mailto:'+(t.email_privat||t.email)+'" style="color:#5b94ff">'+esc(t.email_privat||t.email)+'</a>':'–')+'</td>'
      +'<td data-label="Mannschaften">'+mn+'</td>'
      +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm at-ed" data-id="'+t.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm at-dl" data-id="'+t.id+'">Löschen</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';$('#adm-tr-liste').html(h);
}
function openTrainerModal(t){
  if(t){
    $('#mt-id').val(t.id);
    $('#mt-nachname').val(t.name?t.name.split(' ').slice(-1)[0]:''); // Fallback Altdaten
    // Wenn vorname vorhanden, nutze direkte Felder
    if(t.vorname!==undefined){
      // Neuformat: vorname ist eigenes Feld
      // name-Feld enthält "Vorname Nachname" (compat), aber wir müssen nachname aus display_name holen
      var dn=t.display_name||'';
      var match=dn.match(/^(.+?),\s*(.+?)\s*\(/);
      $('#mt-nachname').val(match?match[1]:t.name);
      $('#mt-vorname').val(t.vorname||'');
    }
    $('#mt-geburt').val(t.geburtsdatum&&t.geburtsdatum!=='0000-00-00'?t.geburtsdatum:'');
    $('#mt-telefon').val(t.telefon||'');
    $('#mt-email-privat').val(t.email_privat||t.email||'');
    $('#mt-email-geschaeft').val(t.email_geschaeft||'');
    $('#mt-fuehrungszeugnis').val(t.fuehrungszeugnis&&t.fuehrungszeugnis!=='0000-00-00'?t.fuehrungszeugnis:'');
    $('#mt-rettungsschwimmer').val(t.rettungsschwimmer&&t.rettungsschwimmer!=='0000-00-00'?t.rettungsschwimmer:'');
    $('#mt-lizenznummer').val(t.lizenznummer||'');
    $('#mt-lizenz-gueltig').val(t.lizenz_gueltig&&t.lizenz_gueltig!=='0000-00-00'?t.lizenz_gueltig:'');
    $('#mt-trainervertrag').prop('checked',!!parseInt(t.trainervertrag));
    $('#mt-trainerlizenz').prop('checked',!!parseInt(t.trainerlizenz));
    $('#mt-verhaltenskodex').prop('checked',!!parseInt(t.verhaltenskodex));
    $('#mt-datenschutz').prop('checked',!!parseInt(t.datenschutz));
    $('#mt-user').val(t.wp_user_id||'0');
    var sel=t.mannschaft_ids?String(t.mannschaft_ids).split(',').map(function(x){return x.trim();}):[];
    buildMannCbx(sel);
    $('#m-trainer-ttl').text('Trainer bearbeiten');
  } else {
    $('#mt-id').val('');
    $('#mt-nachname,#mt-vorname,#mt-geburt,#mt-telefon,#mt-email-privat,#mt-email-geschaeft').val('');
    $('#mt-fuehrungszeugnis,#mt-rettungsschwimmer,#mt-lizenznummer,#mt-lizenz-gueltig').val('');
    $('#mt-trainervertrag,#mt-trainerlizenz,#mt-verhaltenskodex,#mt-datenschutz').prop('checked',false);
    $('#mt-user').val('0');
    buildMannCbx([]);
    $('#m-trainer-ttl').text('Neuen Trainer anlegen');
  }
  openModal('m-trainer');
}

$('#adm-tr-neu').on('click',function(){openTrainerModal(null);});
$(document).on('click','.at-ed',function(){
  var tid=$(this).data('id'),t=null;
  $.each(S.trainer,function(i,tt){if(tt.id==tid)t=tt;});
  if(t)openTrainerModal(t);
});

function buildMannCbx(sel){
  if(!S.mann.length){$('#mt-mann').html('<span class="i-muted">Keine Mannschaften vorhanden.</span>');return;}
  var h='';
  $.each(S.mann,function(i,m){
    var checked=$.inArray(String(m.id),sel)>=0;
    h+='<label class="i-cbl" style="min-width:140px"><input type="checkbox" value="'+m.id+'"'+(checked?' checked':'')+'>'+esc(m.name)+'</label>';
  });
  $('#mt-mann').html(h);
}

$('#mt-save').on('click',function(){
  var nachname=$('#mt-nachname').val().trim(),vorname=$('#mt-vorname').val().trim(),geburt=$('#mt-geburt').val();
  if(!nachname||!vorname||!geburt){toast('Nachname, Vorname und Geburtsdatum sind Pflicht.','err');return;}
  var mn=[];
  $('#mt-mann input[type="checkbox"]:checked').each(function(){mn.push($(this).val());});
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_admin_save_trainer',{
    id:$('#mt-id').val(),
    nachname:nachname, vorname:vorname, geburtsdatum:geburt,
    telefon:$('#mt-telefon').val(),
    email_privat:$('#mt-email-privat').val(),
    email_geschaeft:$('#mt-email-geschaeft').val(),
    fuehrungszeugnis:$('#mt-fuehrungszeugnis').val(),
    rettungsschwimmer:$('#mt-rettungsschwimmer').val(),
    lizenznummer:$('#mt-lizenznummer').val(),
    lizenz_gueltig:$('#mt-lizenz-gueltig').val(),
    trainervertrag:$('#mt-trainervertrag').is(':checked')?1:0,
    trainerlizenz:$('#mt-trainerlizenz').is(':checked')?1:0,
    verhaltenskodex:$('#mt-verhaltenskodex').is(':checked')?1:0,
    datenschutz:$('#mt-datenschutz').is(':checked')?1:0,
    wp_user_id:$('#mt-user').val(),
    'mannschaften[]':mn
  }).done(function(r){
    if(r.success){
      S.trainer=r.data.trainer;
      renderAdmTr();
      closeModal('m-trainer');
      toast('Trainer gespeichert.');
    }else{
      toast(r.data.message,'err');
    }
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.at-dl',function(){
  if(!confirm('Trainer deaktivieren?'))return;
  ajax('lsv07i_admin_delete_trainer',{id:$(this).data('id')}).done(function(r){if(r.success){S.trainer=r.data.trainer;renderAdmTr();toast('Deaktiviert.');}else toast(r.data.message,'err');});
});

// Admin Mannschaft
function renderAdmMn(){
  if(!S.mann.length){$('#adm-mn-liste').html('<span class="i-muted">Keine Mannschaften.</span>');return;}
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Reihenfolge</th><th></th></tr></thead><tbody>';
  $.each(S.mann,function(i,m){h+='<tr><td data-label="Name">'+esc(m.name)+'</td><td data-label="Reihenfolge">'+m.sort_order+'</td><td data-label=""><button class="i-btn i-btn-g i-btn-sm am-ed" data-id="'+m.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm am-dl" data-id="'+m.id+'">Löschen</button></td></tr>';});
  h+='</tbody></table></div>';$('#adm-mn-liste').html(h);
}
$('#adm-mn-neu').on('click',function(){$('#mm-id,#mm-name').val('');$('#mm-sort').val('0');$('#m-mann-ttl').text('Neue Mannschaft');openModal('m-mann');});
$(document).on('click','.am-ed',function(){var m=null;$.each(S.mann,function(i,mm){if(mm.id==$(this).data('id'))m=mm;}.bind(this));if(!m)return;$('#mm-id').val(m.id);$('#mm-name').val(m.name);$('#mm-sort').val(m.sort_order);$('#m-mann-ttl').text('Mannschaft bearbeiten');openModal('m-mann');});
$('#mm-save').on('click',function(){
  var name=$('#mm-name').val().trim();if(!name){toast('Name eingeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_admin_save_mannschaft',{id:$('#mm-id').val(),name:name,sort_order:$('#mm-sort').val()}).done(function(r){if(r.success){S.mann=r.data.mannschaften;renderAdmMn();closeModal('m-mann');toast('Gespeichert.');}else toast(r.data.message,'err');}).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.am-dl',function(){
  if(!confirm('Mannschaft löschen?'))return;
  ajax('lsv07i_admin_delete_mannschaft',{id:$(this).data('id')}).done(function(r){if(r.success){S.mann=r.data.mannschaften;renderAdmMn();toast('Gelöscht.');}else toast(r.data.message,'err');});
});

// Admin Schwimmer
// ── Attest-Badge ────────────────────────────────────────────────
function attestBdg(status){
  if(status==='abgelaufen') return bdg('r','Abgelaufen');
  if(status==='laeuft_ab')  return bdg('a','Läuft ab');
  if(status==='gueltig')    return bdg('g','Gültig');
  return '';
}

function renderAdmSw(filter){
  filter=filter||'';
  var suche=($('#adm-sw-suche').val()||'').trim().toLowerCase();
  var sw=filter?$.grep(S.schwimmer,function(s){
    // Filter berücksichtigt Mehrfach-Mannschaft
    if(String(s.team_id)===String(filter))return true;
    if(s.alle_gruppen_ids&&$.inArray(String(filter),s.alle_gruppen_ids.map(String))>=0)return true;
    return false;
  }):S.schwimmer;
  // Textsuche zusätzlich anwenden, falls Suchfeld gefüllt
  if(suche){
    sw=$.grep(sw,function(s){
      var fn=(s.first_name||'').toLowerCase();
      var ln=(s.last_name||'').toLowerCase();
      var em=(s.email||'').toLowerCase();
      var ds=(s.dsv_id||'').toLowerCase();
      // Voll-Name in beiden Reihenfolgen, damit "Anna Müller" und "Müller Anna" matcht
      var voll1=fn+' '+ln, voll2=ln+' '+fn, vollKomma=ln+', '+fn;
      return fn.indexOf(suche)>=0 || ln.indexOf(suche)>=0
          || em.indexOf(suche)>=0 || ds.indexOf(suche)>=0
          || voll1.indexOf(suche)>=0 || voll2.indexOf(suche)>=0
          || vollKomma.indexOf(suche)>=0;
    });
  }
  // Trefferzahl anzeigen
  var ges=S.schwimmer.length;
  if(suche||filter){
    $('#adm-sw-count').text(sw.length+' / '+ges);
  }else{
    $('#adm-sw-count').text(ges);
  }
  if(!sw.length){
    $('#adm-sw-liste').html('<span class="i-muted">'+(suche||filter?'Kein Treffer.':'Keine Schwimmer.')+'</span>');
    return;
  }
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Geburt</th><th>Mannschaft(en)</th><th>Attest</th><th>DSV-ID</th><th></th></tr></thead><tbody>';
  $.each(sw,function(i,s){
    var dn=s.display_name||esc(s.last_name+', '+s.first_name);
    var attestInfo=s.attest_expires&&s.attest_expires!=='0000-00-00'?de(s.attest_expires)+' '+attestBdg(s.attest_status):'<span class="i-muted">–</span>';
    var gruppenAnz=s.alle_gruppen_namen&&s.alle_gruppen_namen.length?s.alle_gruppen_namen.map(esc).join('<br>'):esc(s.mannschaft_name||'');
    var ds=parseInt(s.datenschutz)?'<span style="color:#22c55e;font-size:11px">✓ DS</span>':'';
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(dn)+'</strong> '+ds+'</td>'
      +'<td data-label="Geburt">'+de(s.birth_date)+'</td>'
      +'<td data-label="Mannschaft" style="font-size:12px">'+gruppenAnz+'</td>'
      +'<td data-label="Attest">'+attestInfo+'</td>'
      +'<td data-label="DSV-ID">'+esc(s.dsv_id||'–')+'</td>'
      +'<td data-label="">'
      +'<button class="i-btn i-btn-g i-btn-sm asw-ed" data-id="'+s.id+'">Bearb.</button> '
      +'<button class="i-btn i-btn-p i-btn-sm asw-mv" data-id="'+s.id+'">Verschieben</button> '
      +'<button class="i-btn i-btn-r i-btn-sm asw-dl" data-id="'+s.id+'">Löschen</button>'
      +'</td></tr>';
  });
  h+='</tbody></table></div>';$('#adm-sw-liste').html(h);
  // Lange Listen einklappen — über 15 Zeilen wird "Mehr anzeigen" angeboten
  tableCollapse($('#adm-sw-liste'),15);
}

$('#adm-sw-filt').on('change',function(){renderAdmSw($(this).val());});

// Live-Suche mit kleiner Verzögerung, damit nicht bei jedem Tastenanschlag
// die ganze Liste neu gerendert wird (smoother bei vielen Schwimmern)
var _admSwSucheT=null;
$('#adm-sw-suche').on('input',function(){
  clearTimeout(_admSwSucheT);
  _admSwSucheT=setTimeout(function(){renderAdmSw($('#adm-sw-filt').val());},150);
});
// Escape leert das Suchfeld
$('#adm-sw-suche').on('keyup',function(e){
  if(e.key==='Escape'){$(this).val('');renderAdmSw($('#adm-sw-filt').val());}
});

// ── Excel-Export: aktuell sichtbare Schwimmer als .xlsx herunterladen ──
$('#adm-sw-export').on('click',function(){
  // Gleiche Filter-/Such-Logik wie in renderAdmSw, damit nur die sichtbaren
  // Schwimmer exportiert werden.
  var filter=($('#adm-sw-filt').val()||'');
  var suche=($('#adm-sw-suche').val()||'').trim().toLowerCase();
  var sw=filter?$.grep(S.schwimmer,function(s){
    if(String(s.team_id)===String(filter))return true;
    if(s.alle_gruppen_ids&&$.inArray(String(filter),s.alle_gruppen_ids.map(String))>=0)return true;
    return false;
  }):S.schwimmer.slice();
  if(suche){
    sw=$.grep(sw,function(s){
      var fn=(s.first_name||'').toLowerCase();
      var ln=(s.last_name||'').toLowerCase();
      var em=(s.email||'').toLowerCase();
      var ds=(s.dsv_id||'').toLowerCase();
      var voll1=fn+' '+ln, voll2=ln+' '+fn, vollKomma=ln+', '+fn;
      return fn.indexOf(suche)>=0 || ln.indexOf(suche)>=0
          || em.indexOf(suche)>=0 || ds.indexOf(suche)>=0
          || voll1.indexOf(suche)>=0 || voll2.indexOf(suche)>=0
          || vollKomma.indexOf(suche)>=0;
    });
  }
  if(!sw.length){toast('Keine Schwimmer zum Exportieren.','err');return;}

  // Alphabetisch nach Nachname, Vorname sortieren
  sw.sort(function(a,b){
    var an=(a.last_name||'')+' '+(a.first_name||'');
    var bn=(b.last_name||'')+' '+(b.first_name||'');
    return an.localeCompare(bn,'de');
  });

  // Daten für SheetJS: ein Header + eine Zeile pro Schwimmer mit "Vorname Nachname"
  var aoa=[['Name']];
  $.each(sw,function(i,s){
    var name=((s.first_name||'')+' '+(s.last_name||'')).trim();
    aoa.push([name]);
  });

  // SheetJS asynchron laden, falls noch nicht da
  function doExport(){
    if(typeof XLSX==='undefined'){
      toast('Excel-Bibliothek konnte nicht geladen werden.','err');
      return;
    }
    var ws=XLSX.utils.aoa_to_sheet(aoa);
    // Spaltenbreite: Name etwas großzügig
    ws['!cols']=[{wch:35}];
    var wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Schwimmer');
    // Dateiname mit Datum
    var d=new Date();
    var datum=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
    var teil='alle';
    if(filter){
      // Mannschaftsname aus Dropdown ablesen
      teil=$('#adm-sw-filt option:selected').text().replace(/[^a-zA-Z0-9äöüÄÖÜß-]/g,'_').slice(0,30);
    }
    XLSX.writeFile(wb,'schwimmer_'+teil+'_'+datum+'.xlsx');
    toast(sw.length+' Schwimmer exportiert.');
  }

  var $b=$(this).prop('disabled',true).text('…');
  ladeXLSX(function(ok){
    if(ok)doExport();
    else toast('Excel-Bibliothek konnte nicht geladen werden. Bitte Internet-Verbindung prüfen.','err');
    $b.prop('disabled',false).text('Excel-Export');
  });
});


// Mannschafts-Checkboxen für Schwimmer-Modal. mannschaftListe optional —
// Standard ist S.mann (Admin, alle Mannschaften); im Trainer-Kontext wird
// die eigene, bereits serverseitig eingeschränkte Liste übergeben.
function fillSwGruppenCbs(selectedIds,mannschaftListe){
  selectedIds=(selectedIds||[]).map(String);
  mannschaftListe=mannschaftListe||S.mann;
  var h='';
  if(!mannschaftListe||!mannschaftListe.length){h='<span class="i-muted" style="font-size:12px">Keine Mannschaften vorhanden.</span>';}
  else{$.each(mannschaftListe,function(i,m){
    var checked=selectedIds.indexOf(String(m.id))>=0;
    h+='<label style="display:flex;align-items:center;gap:8px;padding:4px 2px;cursor:pointer;font-size:13px">'
      +'<input type="checkbox" class="sw-gruppe-cb" value="'+m.id+'"'+(checked?' checked':'')
      +' style="width:14px;height:14px;accent-color:#5b94ff"> '+esc(m.name)+'</label>';
  });}
  $('#msw-gruppen-cb').html(h);
}

// Merkt sich, ob das Schwimmer-Modal gerade für den Admin-Bereich oder für
// den neuen Trainer-Bereich (eigene Sportler anlegen) geöffnet wurde — steuert
// beim Speichern, welcher Endpunkt aufgerufen wird und welche Mannschaften
// zur Auswahl stehen.
var SW_MODAL_KONTEXT='admin';

function openSwModal(s,kontext){
  SW_MODAL_KONTEXT=kontext||'admin';
  var istTrainer=SW_MODAL_KONTEXT==='trainer';
  if(s){
    $('#msw-id').val(s.id);
    $('#msw-last').val(s.last_name);
    $('#msw-first').val(s.first_name);
    $('#msw-birth').val(s.birth_date&&s.birth_date!=='0000-00-00'?s.birth_date:'');
    $('#msw-attest').val(s.attest_expires&&s.attest_expires!=='0000-00-00'?s.attest_expires:'');
    $('#msw-dsv').val(s.dsv_id||'');
    $('#msw-datenschutz').prop('checked',!!parseInt(s.datenschutz));
    $('#msw-notes').val(s.notes||'');
    var selIds=s.alle_gruppen_ids&&s.alle_gruppen_ids.length?s.alle_gruppen_ids:[s.team_id];
    // Im Trainer-Kontext zeigt die Checkbox-Liste nur die eigenen
    // Mannschaften — evtl. zusätzliche, fremde Zuordnungen des Sportlers
    // bleiben beim Speichern erhalten (siehe class-ajax-trainer-sportler.php),
    // werden hier aber nicht angezeigt (nicht die Sache dieses Trainers).
    fillSwGruppenCbs(selIds,istTrainer?(TR_SW.mannschaften||[]):null);
    $('#m-sw-ttl').text('Schwimmer bearbeiten');
    renderKontakteListe(s.kontakte||[]);
    if(istTrainer){
      // Dateien (Atteste) bleiben dem Admin-Bereich vorbehalten.
      $('#msw-dateien-block').hide();
    }else{
      $('#msw-dateien-block').show();
      $('#msw-datei-form').hide();
      $('#msw-datei-input').val('');
      $('#msw-datei-kommentar').val('');
      loadSwDateien(s.id);
    }
  } else {
    $('#msw-id,#msw-last,#msw-first,#msw-birth,#msw-attest,#msw-dsv,#msw-notes').val('');
    $('#msw-datenschutz').prop('checked',false);
    fillSwGruppenCbs([],SW_MODAL_KONTEXT==='trainer'?(TR_SW.mannschaften||[]):null);
    $('#m-sw-ttl').text('Neuer Schwimmer');
    renderKontakteListe([]);
    // Datei-Sektion verstecken (gibt's erst nach erstem Speichern)
    $('#msw-dateien-block').hide();
  }
  openModal('m-sw');
}

$('#adm-sw-neu').on('click',function(){openSwModal(null,'admin');});
$(document).on('click','.asw-ed',function(){
  var sid=$(this).data('id');var s=null;
  $.each(S.schwimmer,function(i,ss){if(ss.id==sid)s=ss;});
  if(s)openSwModal(s);
});

$(document).on('click','.asw-mv',function(){
  var sid=$(this).data('id');var s=null;
  $.each(S.schwimmer,function(i,ss){if(ss.id==sid)s=ss;});
  if(!s)return;
  // Mannschaftsliste in ein kleines Inline-Dropdown rendern
  var opts='';
  $.each(S.mann,function(i,m){
    opts+='<option value="'+m.id+'"'+(m.id==s.team_id?' selected':'')+'>'+esc(m.name)+'</option>';
  });
  var html='<div style="padding:16px">'
    +'<div style="font-weight:600;margin-bottom:10px">'+esc(s.last_name+', '+s.first_name)+' verschieben nach:</div>'
    +'<select id="mv-ziel" class="i-ctl" style="width:100%;margin-bottom:12px">'+opts+'</select>'
    +'<div style="display:flex;gap:8px;justify-content:flex-end">'
    +'<button class="i-btn i-btn-r" id="mv-abbruch">Abbrechen</button>'
    +'<button class="i-btn i-btn-p" id="mv-ok" data-id="'+s.id+'">Verschieben</button>'
    +'</div></div>';
  // Zeige als kleines Overlay direkt neben dem Schwimmer
  $('#mv-popup').remove();
  $('body').append('<div id="mv-popup" class="i-ov open" style="z-index:9999"><div class="i-modal" style="max-width:380px">'+html+'</div></div>');
});

$(document).on('click','#mv-abbruch',function(){$('#mv-popup').remove();});
$(document).on('click','.i-ov#mv-popup',function(e){if(e.target===this)$('#mv-popup').remove();});

$(document).on('click','#mv-ok',function(){
  var sid=$(this).data('id');
  var ziel=$('#mv-ziel').val();
  if(!ziel){toast('Bitte Mannschaft wählen.','err');return;}
  // Schwimmer-Daten aus State holen
  var s=null;$.each(S.schwimmer,function(i,ss){if(ss.id==sid)s=ss;});
  if(!s)return;
  var $b=$(this).prop('disabled',true).text('…');
  // Speichern mit neuer Mannschaft — alle anderen Daten bleiben gleich.
  // gruppe_ids[] (nicht team_id!) ist das Feld, das save_schwimmer()
  // tatsächlich auswertet — ersetzt alle bisherigen Mannschaften durch
  // die eine Zielmannschaft (= "verschieben", nicht "zusätzlich zuordnen").
  ajax('lsv07i_admin_save_schwimmer',{
    id:s.id,
    last_name:s.last_name,
    first_name:s.first_name,
    birth_date:s.birth_date,
    'gruppe_ids[]':[ziel],
    attest_expires:s.attest_expires||'',
    notes:s.notes||'',
    kontakte_json:JSON.stringify(s.kontakte||[])
  }).done(function(r){
    if(r.success){
      S.schwimmer=r.data.schwimmer;
      renderAdmSw($('#adm-sw-filt').val());
      $('#mv-popup').remove();
      var zielName='';
      $.each(S.mann,function(i,m){if(m.id==ziel)zielName=m.name;});
      toast(esc(s.last_name+', '+s.first_name)+' → '+esc(zielName));
    }else{
      toast(r.data.message,'err');
    }
  }).always(function(){$b.prop('disabled',false).text('Verschieben');});
});

// Kontakte-Liste im Modal rendern
function renderKontakteListe(kontakte){
  var h='';
  $.each(kontakte,function(i,k){h+=kontaktZeile(i,k);});
  $('#msw-kontakte-liste').html(h);
  if(!h) $('#msw-kontakte-liste').html('<span class="i-muted">Noch keine Kontaktperson eingetragen.</span>');
}

function kontaktZeile(idx,k){
  k=k||{name:'',telefon:'',email:''};
  return '<div class="i-kontakt-row" style="display:flex;gap:6px;align-items:flex-end;margin-bottom:8px;flex-wrap:wrap">'
    +'<div style="flex:2;min-width:100px"><label class="i-lbl" style="margin-top:0">Name</label><input type="text" class="i-ctl kt-name" value="'+esc(k.name||'')+'" placeholder="Vor- und Nachname"></div>'
    +'<div style="flex:1;min-width:90px"><label class="i-lbl" style="margin-top:0">Telefon</label><input type="tel" class="i-ctl kt-tel" value="'+esc(k.telefon||'')+'" placeholder="0151 …"></div>'
    +'<div style="flex:2;min-width:120px"><label class="i-lbl" style="margin-top:0">E-Mail</label><input type="email" class="i-ctl kt-mail" value="'+esc(k.email||'')+'" placeholder="mail@beispiel.de"></div>'
    +'<button type="button" class="i-btn i-btn-r i-btn-sm kt-del" style="flex-shrink:0;margin-bottom:1px">&#10005;</button>'
    +'</div>';
}

$(document).on('click','#msw-add-kontakt',function(){
  var cur=$('#msw-kontakte-liste .i-kontakt-row').length;
  if(cur>=5){toast('Maximal 5 Kontaktpersonen möglich.','err');return;}
  // Leeren Hinweis entfernen
  $('#msw-kontakte-liste .i-muted').remove();
  $('#msw-kontakte-liste').append(kontaktZeile(cur));
});

$(document).on('click','.kt-del',function(){
  $(this).closest('.i-kontakt-row').remove();
  if(!$('#msw-kontakte-liste .i-kontakt-row').length){
    $('#msw-kontakte-liste').html('<span class="i-muted">Noch keine Kontaktperson eingetragen.</span>');
  }
});

$('#msw-save').on('click',function(){
  var last=$('#msw-last').val().trim(),first=$('#msw-first').val().trim();
  var birth=$('#msw-birth').val();
  var gids=[];$('.sw-gruppe-cb:checked').each(function(){gids.push($(this).val());});
  if(!last||!first||!birth){toast('Nachname, Vorname und Geburtsdatum sind Pflicht.','err');return;}
  if(!gids.length){toast('Bitte mindestens eine Mannschaft wählen.','err');return;}

  var kontakte=[];
  $('#msw-kontakte-liste .i-kontakt-row').each(function(){
    kontakte.push({
      name:$(this).find('.kt-name').val().trim(),
      telefon:$(this).find('.kt-tel').val().trim(),
      email:$(this).find('.kt-mail').val().trim(),
    });
  });

  var $b=$(this).prop('disabled',true).text('Speichern\u2026');
  var daten={
    id:$('#msw-id').val(),
    last_name:last, first_name:first, birth_date:birth,
    'gruppe_ids[]':gids,
    attest_expires:$('#msw-attest').val(),
    dsv_id:$('#msw-dsv').val(),
    datenschutz:$('#msw-datenschutz').is(':checked')?1:0,
    notes:$('#msw-notes').val(),
    kontakte_json:JSON.stringify(kontakte),
  };
  if(SW_MODAL_KONTEXT==='trainer'){
    ajax('lsv07i_tr_sportler_save',daten).done(function(r){
      if(r.success){TR_SW.sportler=r.data.sportler;renderTrSportler();closeModal('m-sw');toast('Sportler angelegt.');}
      else toast(r.data.message,'err');
    }).always(function(){$b.prop('disabled',false).text('Speichern');});
    return;
  }
  ajax('lsv07i_admin_save_schwimmer',daten).done(function(r){
    if(r.success){S.schwimmer=r.data.schwimmer;renderAdmSw($('#adm-sw-filt').val());closeModal('m-sw');toast('Gespeichert.');}
    else toast(r.data.message,'err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});

$(document).on('click','.asw-dl',function(){
  if(!confirm('Schwimmer deaktivieren?'))return;
  ajax('lsv07i_admin_delete_schwimmer',{id:$(this).data('id')}).done(function(r){
    if(r.success){S.schwimmer=r.data.schwimmer;renderAdmSw($('#adm-sw-filt').val());toast('Deaktiviert.');}
  });
});

/* ══ TRAINER: eigene Sportler anlegen und verschieben ═══════════════
   Nutzt dasselbe Schwimmer-Formular (#m-sw) wie der Admin-Bereich (siehe
   openSwModal/SW_MODAL_KONTEXT oben), aber über eigene Endpunkte, die
   serverseitig auf die Mannschaften des Trainers beschränkt sind. Verschoben
   werden darf nur aus der eigenen Mannschaft heraus — das Ziel darf jede
   beliebige Mannschaft sein. */
var TR_SW={sportler:[],mannschaften:[],alle_mannschaften:[]};

function loadTrSportler(){
  skel($('#tr-sw-liste'),'block');
  ajax('lsv07i_tr_sportler_liste').done(function(r){
    if(!r.success){$('#tr-sw-liste').html('<span class="i-muted">'+esc(r.data&&r.data.message||'Fehler.')+'</span>');return;}
    TR_SW.sportler=r.data.sportler||[];
    TR_SW.mannschaften=r.data.mannschaften||[];
    TR_SW.alle_mannschaften=r.data.alle_mannschaften||[];
    renderTrSportler();
  }).fail(function(xhr){$('#tr-sw-liste').html('<span class="i-muted">'+esc(errMsg(xhr))+'</span>');});
}

function renderTrSportler(){
  if(!TR_SW.mannschaften.length){
    $('#tr-sw-liste').html('<div class="i-hint">Dir ist keine Mannschaft zugeordnet — bitte beim Verein melden.</div>');
    return;
  }
  if(!TR_SW.sportler.length){
    $('#tr-sw-liste').html('<div class="i-hint">Noch keine Sportler in deinen Mannschaften.</div>');
    return;
  }
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Mannschaft</th><th></th></tr></thead><tbody>';
  $.each(TR_SW.sportler,function(i,s){
    h+='<tr><td data-label="Name">'+esc(s.last_name+', '+s.first_name)+'</td>'
      +'<td data-label="Mannschaft">'+esc(s.mannschaft_name||'–')+'</td>'
      +'<td data-label="" style="display:flex;gap:6px;flex-wrap:wrap">'
      +'<button type="button" class="i-btn i-btn-g i-btn-sm trsw-ed" data-id="'+s.id+'">Bearbeiten</button>'
      +'<button type="button" class="i-btn i-btn-g i-btn-sm trsw-mv" data-id="'+s.id+'">Verschieben</button></td></tr>';
  });
  h+='</tbody></table></div>';
  $('#tr-sw-liste').html(h);
}

$('#tr-sw-neu').on('click',function(){
  if(!TR_SW.mannschaften.length){toast('Dir ist keine Mannschaft zugeordnet.','err');return;}
  openSwModal(null,'trainer');
});

$(document).on('click','.trsw-ed',function(){
  var sid=$(this).data('id');var s=null;
  $.each(TR_SW.sportler,function(i,ss){if(ss.id==sid)s=ss;});
  if(s)openSwModal(s,'trainer');
});

$(document).on('click','.trsw-mv',function(){
  var sid=$(this).data('id');var s=null;
  $.each(TR_SW.sportler,function(i,ss){if(ss.id==sid)s=ss;});
  if(!s)return;
  var opts='';
  $.each(TR_SW.alle_mannschaften,function(i,m){
    opts+='<option value="'+m.id+'"'+(m.id==s.team_id?' selected':'')+'>'+esc(m.name)+'</option>';
  });
  var html='<div style="padding:16px">'
    +'<div style="font-weight:600;margin-bottom:10px">'+esc(s.last_name+', '+s.first_name)+' verschieben nach:</div>'
    +'<select id="trmv-ziel" class="i-ctl" style="width:100%;margin-bottom:12px">'+opts+'</select>'
    +'<div style="display:flex;gap:8px;justify-content:flex-end">'
    +'<button class="i-btn i-btn-r" id="trmv-abbruch">Abbrechen</button>'
    +'<button class="i-btn i-btn-p" id="trmv-ok" data-id="'+s.id+'">Verschieben</button>'
    +'</div></div>';
  $('#trmv-popup').remove();
  $('body').append('<div id="trmv-popup" class="i-ov open" style="z-index:9999"><div class="i-modal" style="max-width:380px">'+html+'</div></div>');
});
$(document).on('click','#trmv-abbruch',function(){$('#trmv-popup').remove();});
$(document).on('click','.i-ov#trmv-popup',function(e){if(e.target===this)$('#trmv-popup').remove();});
$(document).on('click','#trmv-ok',function(){
  var sid=$(this).data('id');
  var ziel=$('#trmv-ziel').val();
  if(!ziel){toast('Bitte Mannschaft wählen.','err');return;}
  // Name vor dem Request merken — nach erfolgreichem Verschieben ist der
  // Sportler ggf. nicht mehr in TR_SW.sportler (fremde Zielmannschaft).
  var s=null;$.each(TR_SW.sportler,function(i,ss){if(ss.id==sid)s=ss;});
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_tr_sportler_verschieben',{id:sid,ziel_mannschaft_id:ziel}).done(function(r){
    if(r.success){
      TR_SW.sportler=r.data.sportler;
      renderTrSportler();
      $('#trmv-popup').remove();
      var zielName='';$.each(TR_SW.alle_mannschaften,function(i,m){if(m.id==ziel)zielName=m.name;});
      var name=s?(s.last_name+', '+s.first_name):'Sportler';
      toast(esc(name)+' → '+esc(zielName));
    }else{
      toast(r.data.message,'err');
    }
  }).always(function(){$b.prop('disabled',false).text('Verschieben');});
});

// Admin Slots
function renderAdmSl(){
  if(!S.slots||!S.slots.length){$('#adm-sl-liste').html('<span class="i-muted">Keine Trainingszeiten in der gewählten Saison.</span>');return;}
  // Wenn Filter "alle" gewählt → Saison-Spalte mit anzeigen
  var showSaisonCol=$('#adm-sl-saison-filter').val()==='alle';
  var head='<th>Mannschaft</th><th>Tag</th><th>Von</th><th>Bis</th>'
         +(showSaisonCol?'<th>Saison</th>':'')+'<th></th>';
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr>'+head+'</tr></thead><tbody>';
  $.each(S.slots,function(i,s){
    var saisonCell='';
    if(showSaisonCol){
      var nm=s.saison_name||'<em style="color:#8b8ea0">–</em>';
      var ak=parseInt(s.saison_aktiv,10)===1?' <span class="i-bdg i-bdg-g" style="font-size:10px">aktiv</span>':'';
      saisonCell='<td data-label="Saison">'+nm+ak+'</td>';
    }
    h+='<tr><td data-label="Mannschaft">'+esc(s.mannschaft_name)+'</td>'
      +'<td data-label="Tag">'+WD[s.wochentag]+'</td>'
      +'<td data-label="Von">'+String(s.zeit_von||'').slice(0,5)+'</td>'
      +'<td data-label="Bis">'+String(s.zeit_bis||'').slice(0,5)+'</td>'
      +saisonCell
      +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm asl-ed" data-id="'+s.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm asl-dl" data-id="'+s.id+'">Löschen</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';$('#adm-sl-liste').html(h);
}

// Slots mit aktuellem Filter neu laden
function loadAdmSl(){
  var filter=$('#adm-sl-saison-filter').val()||'aktiv';
  skel($('#adm-sl-liste'),'rows',3);
  ajax('lsv07i_admin_get_slots',{saison:filter}).done(function(r){
    if(r.success){S.slots=r.data.slots||[];renderAdmSl();}
  });
}

// Filter-Wechsel triggert Neu-Laden
$(document).on('change','#adm-sl-saison-filter',loadAdmSl);

$('#adm-sl-neu').on('click',function(){$('#msl-id,#msl-von,#msl-bis').val('');fillSel($('#msl-mann'),S.mann,'id','name','Wählen…');$('#msl-wt').val('1');$('#m-slot-ttl').text('Neue Trainingszeit');openModal('m-slot');});
$(document).on('click','.asl-ed',function(){var s=null;$.each(S.slots,function(i,ss){if(ss.id==$(this).data('id'))s=ss;}.bind(this));if(!s)return;fillSel($('#msl-mann'),S.mann,'id','name','Wählen…');$('#msl-id').val(s.id);$('#msl-mann').val(s.mannschaft_id);$('#msl-wt').val(s.wochentag);$('#msl-von').val(String(s.zeit_von||'').slice(0,5));$('#msl-bis').val(String(s.zeit_bis||'').slice(0,5));$('#m-slot-ttl').text('Trainingszeit bearbeiten');openModal('m-slot');});
$('#msl-save').on('click',function(){
  var mn=$('#msl-mann').val(),von=$('#msl-von').val(),bis=$('#msl-bis').val();
  if(!mn||!von||!bis){toast('Alle Felder ausfüllen.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_admin_save_slot',{id:$('#msl-id').val(),mannschaft_id:mn,wochentag:$('#msl-wt').val(),zeit_von:von,zeit_bis:bis}).done(function(r){if(r.success){closeModal('m-slot');toast('Gespeichert.');loadAdmSl();}else toast(r.data.message,'err');}).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.asl-dl',function(){
  if(!confirm('Trainingszeit löschen?'))return;
  ajax('lsv07i_admin_delete_slot',{id:$(this).data('id')}).done(function(r){if(r.success){toast('Gelöscht.');loadAdmSl();}});
});

// Admin Config
$('#mail-toggle-save').on('click',function(){
  var $b=$(this).prop('disabled',true).text('Speichern…');
  var data={};
  $('.mail-toggle').each(function(){data[$(this).data('key')]=$(this).is(':checked')?'1':'0';});
  ajax('lsv07i_admin_save_config',data).done(function(r){
    if(r.success)toast('Benachrichtigungen gespeichert.');
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Benachrichtigungen speichern');});
});

$('#cfg-save').on('click',function(){
  var $b=$(this).prop('disabled',true).text('Speichern…');
  var cfgData={km_satz:$('#cfg-km').val(),km_min:$('#cfg-km-min').val(),wk_pauschale:$('#cfg-wk').val(),mail_schwimmwart:$('#cfg-mail').val(),anwesenheit_mahnung_stunden:$('#cfg-mahn').val(),attest_warnung_tage:$('#cfg-attest-tage').val()};
  cfgData.mail_deaktiviert=$('#cfg-mail_deaktiviert').is(':checked')?'1':'0';
  ajax('lsv07i_admin_save_config',cfgData).done(function(r){if(r.success)toast('Einstellungen gespeichert.');else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');}).always(function(){$b.prop('disabled',false).text('Einstellungen speichern');});
});

/* ══ TESTMAIL je Benachrichtigung ═══════════════════════════════
   Verschickt die tatsächliche Mail-Vorlage mit Beispieldaten an eine
   frei eingegebene Adresse — unabhängig vom Haken, damit man auch eine
   gerade deaktivierte Benachrichtigung vorab ansehen kann. */
$(document).on('click','.mailtg-test',function(){
  var email=$('#mailtg-test-email').val();
  if(!email||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){toast('Bitte oben eine gültige Test-E-Mail-Adresse eingeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Sende…');
  var key=$b.data('key');
  ajax('lsv07i_admin_mail_test',{to:email,toggle_key:key}).done(function(r){
    if(r.success)toast('Testmail an '+email+' gesendet.');
    else toast(r.data&&r.data.message||'Versand fehlgeschlagen.','err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Testmail');});
});


/* ══ MOBILE NAVIGATION ══════════════════════════════════════════ */
/* Auf Mobile (<900px) wird die Topbar-Nav (#lsv07i-nav-btns) als
   Drawer von links eingeblendet. Der Hamburger-Button öffnet/schließt.
   Klick auf einen Nav-Button schließt den Drawer wieder.            */
(function(){
  var $root=$('#lsv07i-root');
  var $overlay=$('#lsv07i-drawer-overlay');

  function openDrawer(){$root.addClass('drawer-open');}
  function closeDrawer(){$root.removeClass('drawer-open');}

  // Hamburger-Button toggelt
  $('#lsv07i-hamburger').on('click',function(e){
    e.preventDefault();e.stopPropagation();
    $root.hasClass('drawer-open')?closeDrawer():openDrawer();
  });

  // Overlay-Klick schließt
  $overlay.on('click',closeDrawer);

  // Klick auf Sidebar-Button schließt den Drawer (auf Mobile)
  $(document).on('click','.i-nb',function(){
    if($(window).width()<900)closeDrawer();
  });

  // Bei Resize auf Desktop schließen
  $(window).on('resize',function(){
    if($(window).width()>=900)closeDrawer();
  });
})();

/* ══ SKELETON-LOADING ═══════════════════════════════════════════
   Verwendung: skel($('#irgendwas'),'rows',5)  → 5 Zeilen-Skeletons
                skel($('#card-bd'),'block')     → blockiges Skeleton
                skel($('#x'),'lines',3)         → 3 Text-Zeilen-Skeletons
   Ersatz für die klassische "Wird geladen…"-Meldung.                          */
function skel(target,kind,count){
  var $t=$(target);if(!$t.length)return;
  count=count||3;
  var html='';
  if(kind==='rows'){
    for(var i=0;i<count;i++){
      html+='<div class="i-skel-row">'
          +'<span class="i-skel i-skel-circle"></span>'
          +'<div style="flex:1">'
          +'<span class="i-skel i-skel-line" style="width:'+(50+Math.random()*30)+'%"></span>'
          +'<span class="i-skel i-skel-line-sm" style="width:'+(30+Math.random()*30)+'%;margin-bottom:0"></span>'
          +'</div></div>';
    }
  }else if(kind==='block'){
    html='<div class="i-skel i-skel-block"></div>';
  }else{
    // 'lines' (default)
    for(var j=0;j<count;j++){
      var w=j===count-1?(40+Math.random()*30):(70+Math.random()*25);
      html+='<span class="i-skel i-skel-line" style="width:'+w+'%"></span>';
    }
  }
  $t.html(html);
}

/* ══ READ-MORE-TOGGLE ════════════════════════════════════════════
   Erkennt automatisch alle Elemente mit Klasse .i-readmore-auto und
   blendet einen "Mehr lesen / Weniger anzeigen"-Button ein, falls der
   Inhalt länger als ~5 Zeilen ist. Verwendung im Markup:
   <div class="i-readmore-auto">…langer Text…</div>                              */
$(function(){
  $('.i-readmore-auto').each(function(){
    var $el=$(this);
    // Nur auf Inhalte anwenden, die wirklich überlang sind
    if($el[0].scrollHeight<=$el[0].clientHeight+10)return;
    $el.addClass('i-readmore');
    var $btn=$('<button class="i-readmore-toggle" type="button">Mehr lesen</button>');
    $btn.on('click',function(){
      var open=$el.hasClass('expanded');
      $el.toggleClass('expanded');
      $(this).text(open?'Mehr lesen':'Weniger anzeigen');
    });
    $el.after($btn);
  });
});

/* ══ ACCORDION ═══════════════════════════════════════════════════
   Generischer Click-Handler für alle .i-acc-hd-Buttons.
   Standard: zugeklappt. Klick öffnet/schließt nur diesen Eintrag.   */
$(document).on('click','.i-acc-hd',function(){
  $(this).closest('.i-acc').toggleClass('open');
});

/* ══ TABELLEN-COLLAPSE ═══════════════════════════════════════════
   Versteckt Tabellenzeilen über einer bestimmten Anzahl und fügt
   einen "Mehr anzeigen"-Button ein.

   Verwendung im JS nach dem Render einer Tabelle:
     tableCollapse($('#meine-liste'),10);   // ab Zeile 11 versteckt

   Wird nichts gemacht, wenn weniger Zeilen als das Limit da sind.        */
function tableCollapse(target,limit){
  var $t=$(target);
  if(!$t.length)return;
  var $table=$t.find('table.i-tbl').first();
  if(!$table.length){$table=$t.is('table.i-tbl')?$t:null;}
  if(!$table||!$table.length)return;
  limit=limit||10;
  var $rows=$table.find('tbody tr').not('.i-tbl-more-row');
  var total=$rows.length;
  if(total<=limit)return;
  // Bisherige Mehr-Buttons in dieser Tabelle entfernen
  $table.find('tr.i-tbl-more-row').remove();
  $rows.removeClass('i-tbl-hidden');
  // Spaltenanzahl bestimmen
  var cols=$table.find('thead th').length;
  if(!cols)cols=$rows.first().find('td').length||1;
  // Zeilen ab Index limit verstecken
  $rows.slice(limit).addClass('i-tbl-hidden');
  // Mehr-Button-Zeile einfügen
  var hidden=total-limit;
  var $more=$('<tr class="i-tbl-more-row"><td colspan="'+cols+'">'
    +'<button class="i-tbl-more-btn" type="button">'
    +'Mehr anzeigen ('+hidden+' weitere)</button></td></tr>');
  $more.insertAfter($rows.eq(limit-1));
  $more.find('.i-tbl-more-btn').on('click',function(){
    var hide=!$rows.eq(limit).hasClass('i-tbl-hidden');
    if(hide){
      $rows.slice(limit).addClass('i-tbl-hidden');
      $(this).text('Mehr anzeigen ('+hidden+' weitere)');
    }else{
      $rows.slice(limit).removeClass('i-tbl-hidden');
      $(this).text('Weniger anzeigen');
    }
  });
}

/* ══ INIT ═══════════════════════════════════════════════════════ */

/* ══ BESTZEITEN ═════════════════════════════════════════════════ */
function loadBzMannschaften(){
  // Der Import läuft mannschaftsübergreifend — nur noch der Filter-Selector
  // in der Anzeige wird befüllt.
  fillSel($('#bz-mann-filter'),S.mann,'id','name','Alle Mannschaften');
  fillSel($('#bz-exp-mann'),S.mann,'id','name','– wählen –');
}

// ─── Bestzeiten-Export ─────────────────────────────────────────────────
// Scope-Umschaltung: passende Auswahlfelder ein-/ausblenden
$('#bz-exp-scope').on('change',function(){
  var scope=$(this).val();
  $('#bz-exp-mann-wrap').toggle(scope==='mannschaft');
  $('#bz-exp-sw-wrap').toggle(scope==='schwimmer');
  // Schwimmerliste erst bei Bedarf laden
  if(scope==='schwimmer' && $('#bz-exp-sw option').length<=1){
    $('#bz-exp-sw').html('<option value="">Lädt…</option>');
    ajax('lsv07i_bz_swimmers').done(function(r){
      if(r.success && r.data.length){
        fillSel($('#bz-exp-sw'),r.data,'id','name','– wählen –');
      } else {
        $('#bz-exp-sw').html('<option value="">Keine Schwimmer mit Bestzeiten</option>');
      }
    }).fail(function(){$('#bz-exp-sw').html('<option value="">Fehler beim Laden</option>');});
  }
});

$('#bz-exp-start').on('click',function(){
  var scope=$('#bz-exp-scope').val();
  var format=$('#bz-exp-format').val();
  var params={format:format,scope:scope};
  if(scope==='mannschaft'){
    var mid=$('#bz-exp-mann').val();
    if(!mid){toast('Bitte eine Mannschaft wählen.','err');return;}
    params.mannschaft_id=mid;
  } else if(scope==='schwimmer'){
    var sid=$('#bz-exp-sw').val();
    if(!sid){toast('Bitte einen Schwimmer wählen.','err');return;}
    params.swimmer_id=sid;
  }
  // Fenster SYNCHRON im Klick-Kontext öffnen, damit der Browser es nicht als
  // Popup blockiert. Inhalt wird nach dem AJAX-Callback hineingeschrieben.
  var pdfWin=null;
  if(format==='pdf'){
    pdfWin=window.open('','_blank');
    if(pdfWin){pdfWin.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bestzeiten werden geladen…</title></head><body style="font-family:Arial;padding:40px;color:#444">Bestzeiten werden geladen…</body></html>');}
  }
  var $b=$(this).prop('disabled',true).text('Exportiert…');
  $('#bz-exp-status').html('');
  ajax('lsv07i_bz_export',params).done(function(r){
    if(!r.success){
      if(pdfWin)pdfWin.close();
      $('#bz-exp-status').html('<div class="i-notice i-notice-r">'+esc((r.data&&r.data.message)||'Export fehlgeschlagen.')+'</div>');
      return;
    }
    if(format==='csv'){
      // CSV als Datei-Download
      var blob=new Blob([r.data.csv],{type:'text/csv;charset=utf-8;'});
      var url=URL.createObjectURL(blob);
      var a=document.createElement('a');
      a.href=url;a.download=r.data.dateiname||'bestzeiten.csv';
      document.body.appendChild(a);a.click();document.body.removeChild(a);URL.revokeObjectURL(url);
      $('#bz-exp-status').html('<div class="i-notice i-notice-g">'+r.data.anzahl+' Schwimmer als CSV exportiert.</div>');
    } else {
      // PDF: HTML ins bereits geöffnete Fenster schreiben
      if(pdfWin){
        pdfWin.document.open();pdfWin.document.write(r.data.html);pdfWin.document.close();
        $('#bz-exp-status').html('<div class="i-notice i-notice-g">PDF-Ansicht geöffnet. Im Druckdialog "Als PDF speichern" wählen.</div>');
      } else {
        // Fallback: Popup war blockiert → HTML als Datei herunterladen
        var hblob=new Blob([r.data.html],{type:'text/html;charset=utf-8;'});
        var hurl=URL.createObjectURL(hblob);
        var ha=document.createElement('a');
        ha.href=hurl;ha.download=(r.data.titel?r.data.titel.replace(/[^a-zA-Z0-9]+/g,'_'):'bestzeiten')+'.html';
        document.body.appendChild(ha);ha.click();document.body.removeChild(ha);URL.revokeObjectURL(hurl);
        $('#bz-exp-status').html('<div class="i-notice i-notice-b">Die Bestzeiten wurden als HTML-Datei heruntergeladen. Datei öffnen und über den Browser drucken / als PDF speichern.</div>');
      }
    }
  }).fail(function(xhr){
    if(pdfWin)pdfWin.close();
    $('#bz-exp-status').html('<div class="i-notice i-notice-r">'+esc(errMsg(xhr))+'</div>');
  }).always(function(){$b.prop('disabled',false).text('Exportieren');});
});

// ─── Bestzeiten Copy/Paste-Import ──────────────────────────────────────
$('#bz-paste-clear').on('click',function(){
  $('#bz-paste').val('');
  $('#bz-paste-vorschau-box').hide();
  $('#bz-paste-vorschau-content').empty();
  _bzPasteRows=null;
});

var _bzPasteRows=null;

$('#bz-paste-vorschau').on('click',function(){
  var text=$('#bz-paste').val();
  if(!text||!text.trim()){toast('Bitte Tabelle aus Excel einfügen.','err');return;}
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_bz_paste_preview',{text:text}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message||'Fehler.','err');return;}
    _bzPasteRows=r.data.rows;
    renderBzPasteVorschau(r.data);
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){
    $b.prop('disabled',false).text('Vorschau prüfen');
  });
});

// Speichert die Schwimmerliste der aktuellen Mannschaft (für manuelle Zuordnung)
var _bzMannSchwimmer = [];


/* ── Zuordnungsstand der Vorschau ───────────────────────────────────
   Zählt, wie viele Zeilen einem Schwimmer zugeordnet sind, und schaltet
   den Import erst frei, wenn mindestens eine Zeile mit Zeiten bereit ist.
   Läuft nach jeder Änderung an einem Suchfeld. */
function bzStandAktualisieren(){
  var $stand=$('#bz-stand');
  if(!$stand.length||!_bzPasteRows)return;

  var zugeordnet=0, offen=0, zeitenBereit=0, ohneZeiten=0;
  var gesehen={};
  var doppelt=0;
  $.each(_bzPasteRows,function(i,r){
    var gueltige=0;
    $.each(r.zeiten||[],function(j,z){ if(z.gueltig)gueltige++; });
    if(r.matched&&r.matched.id){
      zugeordnet++;
      if(gueltige>0)zeitenBereit+=gueltige; else ohneZeiten++;
      // Derselbe Schwimmer zweimal zugeordnet → die zweite Zeile würde die
      // erste überschreiben. Das muss auffallen, bevor importiert wird.
      var k=String(r.matched.id);
      if(gesehen[k])doppelt++; else gesehen[k]=1;
    } else {
      offen++;
    }
  });

  var teile=[];
  teile.push('<span class="bz-stand-ok">'+zugeordnet+' von '+_bzPasteRows.length+' zugeordnet</span>');
  if(offen>0)      teile.push('<span class="bz-stand-offen">'+offen+' offen</span>');
  if(ohneZeiten>0) teile.push('<span class="bz-stand-warn">'+ohneZeiten+' ohne gültige Zeit</span>');
  if(doppelt>0)    teile.push('<span class="bz-stand-warn">'+doppelt+' doppelt zugeordnet</span>');
  teile.push('<span class="i-muted">'+zeitenBereit+' Zeiten bereit</span>');

  var hinweis='';
  if(offen>0){
    hinweis='<div class="bz-stand-hinweis">Nicht zugeordnete Zeilen werden übersprungen. '
           +'Tippe im Feld „Im System“ den Namen, um sie zuzuordnen.</div>';
  }
  if(doppelt>0){
    hinweis+='<div class="bz-stand-hinweis bz-stand-hinweis-warn">'
           +'Achtung: Ein Schwimmer ist mehrfach zugeordnet — es zählt nur die letzte Zeile.</div>';
  }
  $stand.html('<div class="bz-stand-zeile">'+teile.join('')+'</div>'+hinweis);

  var $btn=$('#bz-paste-uebernehmen');
  $btn.prop('disabled', zeitenBereit===0)
      .text(zeitenBereit===0
        ? 'Keine Zeiten zum Importieren'
        : ('Zeiten für '+(zugeordnet-ohneZeiten)+' Schwimmer importieren'));
}

function renderBzPasteVorschau(d){
  var rows=d.rows||[];
  _bzMannSchwimmer = d.schwimmer || [];
  var ok=0,fuzzy=0,ambig=0,unbekannt=0,zeitenGes=0;
  $.each(rows,function(i,r){
    if(r.status==='ok')ok++;
    else if(r.status==='fuzzy')fuzzy++;
    else if(r.status==='ambiguous')ambig++;
    else unbekannt++;
    $.each(r.zeiten||[],function(){zeitenGes++;});
  });
  if(!rows.length){
    $('#bz-paste-vorschau-content').html('<div class="i-notice i-notice-a">Keine Datenzeilen erkannt. Header-Zeile mit "Name" und Strecken (50F, 100F, …) prüfen.</div>');
    $('#bz-paste-vorschau-box').show();
    return;
  }
  // Zusammenfassung
  var summary=[];
  summary.push('<strong>'+rows.length+' Schwimmer erkannt</strong>');
  summary.push('<span style="color:#22c55e">'+ok+' eindeutig</span>');
  if(fuzzy>0)  summary.push('<span style="color:#b45309">'+fuzzy+' bitte prüfen</span>');
  if(ambig>0)  summary.push('<span style="color:#9333ea">'+ambig+' bitte zuordnen</span>');
  if(unbekannt>0)summary.push('<span style="color:#b91c1c">'+unbekannt+' nicht gefunden</span>');
  summary.push(zeitenGes+' Zeiten');
  var h='<div style="margin-bottom:8px">'+summary.join(' · ')+'</div>';

  // Bei fuzzy/ambiguous: Hinweis-Banner
  if(fuzzy>0||ambig>0){
    h+='<div class="i-notice i-notice-a" style="margin-bottom:10px">'
      +'<strong>Bitte prüfe die markierten Zeilen</strong> — gelb = Doppelname-Übereinstimmung (z.B. Name kürzer als in den Stammdaten), violett = mehrere passende Schwimmer. '
      +'Tippe im Feld „Im System\u201c den Namen, um den richtigen Schwimmer zu suchen.'
      +'</div>';
  }

  h+='<div class="i-twrap" style="max-height:460px;overflow:auto"><table class="i-tbl" style="font-size:12px"><thead><tr>'
    +'<th>Status</th><th>Name (Eingabe)</th><th>Jhg.</th><th>Im System</th><th>Zeiten</th>'
    +'</tr></thead><tbody>';
  $.each(rows,function(i,r){
    var bgColor='';
    var statusBdg, matchedCell;
    // Jede Zeile bekommt ein Suchfeld — auch die automatisch erkannten,
    // damit eine falsche Zuordnung direkt korrigiert werden kann.
    var vorbelegt = r.matched && r.matched.id ? r.matched : null;
    if(r.status==='ok'){
      statusBdg=bdg('g','OK');
    } else if(r.status==='fuzzy'){
      bgColor='background:rgba(217,119,6,0.15)';
      statusBdg='<span class="i-bdg" style="background:rgba(217,119,6,0.20);color:#b45309">⚠ prüfen</span>';
    } else if(r.status==='ambiguous'){
      bgColor='background:rgba(165,90,255,0.08)';
      statusBdg='<span class="i-bdg" style="background:rgba(165,90,255,0.22);color:#7c3aed">zuordnen</span>';
      vorbelegt=null;
    } else {
      bgColor='background:rgba(220,38,38,0.15)';
      statusBdg='<span class="i-bdg" style="background:rgba(220,38,38,0.18);color:#b91c1c">nicht gefunden</span>';
      vorbelegt=null;
    }
    matchedCell=bzSuchFeld(i, vorbelegt);
    if(r.hinweis) matchedCell+='<div class="i-muted" style="font-size:10px;margin-top:2px">'+esc(r.hinweis)+'</div>';
    var zlist=$.map(r.zeiten||[],function(z){
      var col=z.gueltig?'#16a34a':'#b91c1c';
      return '<span style="color:'+col+';margin-right:6px">'+esc(z.strecke)+': '+esc(z.raw)+'</span>';
    }).join('');
    h+='<tr style="'+bgColor+'">'
      +'<td>'+statusBdg+'</td>'
      +'<td>'+esc(r.name_raw)+'</td>'
      +'<td>'+(r.jahrgang||'<span class="i-muted">–</span>')+'</td>'
      +'<td>'+matchedCell+'</td>'
      +'<td style="font-family:ui-monospace,monospace">'+zlist+'</td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  // Fußleiste: zeigt den Zuordnungsstand und schaltet den Import frei
  h+='<div class="bz-fuss">'
    +'<div id="bz-stand" class="bz-stand"></div>'
    +'<div class="bz-fuss-aktion">'
    +'<button id="bz-paste-uebernehmen" class="i-btn i-btn-p">Zeiten importieren</button>'
    +'<span class="i-muted" style="font-size:11px">Bestehende Zeiten der zugeordneten Schwimmer werden dabei ersetzt.</span>'
    +'</div>'
    +'</div>';
  $('#bz-paste-vorschau-content').html(h);
  $('#bz-paste-vorschau-box').show();
  bzStandAktualisieren();
}

/* ── Schwimmer-Suchfeld für die Bestzeiten-Zuordnung ────────────────
   Statt einer langen Auswahlliste tippt man den Namen und bekommt die
   passenden Schwimmer aus dem System vorgeschlagen. Die Liste wird
   fixiert positioniert, damit sie nicht vom scrollenden Tabellen-
   container abgeschnitten wird. */

function bzSuchFeld(idx, sw){
  var wert = sw ? bzSchwimmerLabel(sw) : '';
  return '<div class="bz-such" data-row="'+idx+'" data-sw-id="'+(sw?sw.id:'')+'">'
    +'<input type="text" class="i-ctl bz-such-feld" autocomplete="off" data-no-save'
    +' placeholder="Schwimmer suchen…" value="'+esc(wert)+'">'
    +'<button type="button" class="bz-such-x" title="Zuordnung entfernen" aria-label="Zuordnung entfernen"'
    + (sw?'':' style="display:none"')+'>&#10005;</button>'
    +'<div class="bz-such-liste" role="listbox"></div>'
    +'</div>';
}

function bzSchwimmerLabel(sw){
  var l = sw.last_name+', '+sw.first_name;
  if(sw.jahrgang) l += ' ('+sw.jahrgang+')';
  return l;
}

// Treffer suchen: alle Wortteile müssen irgendwo vorkommen
function bzSuchen(text){
  var q = (text||'').toLowerCase().trim();
  var teile = q ? q.split(/\s+/) : [];
  var out = [];
  $.each(_bzMannSchwimmer, function(i, sw){
    var heu = (sw.last_name+' '+sw.first_name+' '+(sw.jahrgang||'')+' '+(sw.mannschaft_name||'')).toLowerCase();
    var passt = true;
    for(var k=0;k<teile.length;k++){ if(heu.indexOf(teile[k])<0){passt=false;break;} }
    if(passt) out.push(sw);
    return out.length < 60;   // lange Listen abschneiden
  });
  return out;
}

function bzListeZeigen($wrap){
  var $feld = $wrap.find('.bz-such-feld');
  var $liste = $wrap.find('.bz-such-liste');
  var treffer = bzSuchen($feld.data('tippt') ? $feld.val() : '');
  if(!treffer.length){
    $liste.html('<div class="bz-such-leer">Kein Schwimmer gefunden</div>');
  } else {
    var h='';
    $.each(treffer, function(i, sw){
      h += '<div class="bz-such-eintrag" role="option" data-id="'+sw.id+'">'
        +'<span class="bz-such-name">'+esc(bzSchwimmerLabel(sw))+'</span>'
        +(sw.mannschaft_name?'<span class="bz-such-mann">'+esc(sw.mannschaft_name)+'</span>':'')
        +'</div>';
    });
    $liste.html(h);
  }
  // Fixiert positionieren, damit der Tabellen-Container nicht abschneidet
  var r = $feld[0].getBoundingClientRect();
  var maxH = 240;
  var platzUnten = window.innerHeight - r.bottom;
  var nachOben = platzUnten < 140 && r.top > platzUnten;
  $liste.css({
    left: r.left+'px',
    width: Math.max(r.width, 220)+'px',
    maxHeight: Math.min(maxH, nachOben ? r.top-8 : platzUnten-8)+'px',
    top: nachOben ? '' : (r.bottom+2)+'px',
    bottom: nachOben ? (window.innerHeight-r.top+2)+'px' : ''
  }).addClass('offen');
}

function bzListeSchliessen(){
  $('.bz-such-liste.offen').removeClass('offen');
  $('.bz-such-eintrag.aktiv').removeClass('aktiv');
}

// Zuordnung setzen bzw. entfernen
function bzZuordnen($wrap, sw){
  var idx = parseInt($wrap.data('row'), 10);
  $wrap.attr('data-sw-id', sw ? sw.id : '');
  $wrap.find('.bz-such-feld').val(sw ? bzSchwimmerLabel(sw) : '').data('tippt', false);
  $wrap.find('.bz-such-x').toggle(!!sw);
  if(!_bzPasteRows || !_bzPasteRows[idx]) return;
  if(sw){
    _bzPasteRows[idx].matched = {
      id: sw.id, first_name: sw.first_name, last_name: sw.last_name, jahrgang: sw.jahrgang
    };
    if(_bzPasteRows[idx].status !== 'ok') _bzPasteRows[idx].status = 'manual';
  } else {
    _bzPasteRows[idx].matched = null;
    _bzPasteRows[idx].status = 'skip';
  }
  bzStandAktualisieren();
}

$(document).on('focus', '.bz-such-feld', function(){
  var $f = $(this);
  $f.data('tippt', false);
  $f.select();
  bzListeZeigen($f.closest('.bz-such'));
});

$(document).on('input', '.bz-such-feld', function(){
  var $f = $(this);
  $f.data('tippt', true);
  // Beim Tippen gilt die alte Zuordnung als aufgehoben, bis neu gewählt wird
  bzListeZeigen($f.closest('.bz-such'));
});

$(document).on('keydown', '.bz-such-feld', function(e){
  var $wrap = $(this).closest('.bz-such');
  var $liste = $wrap.find('.bz-such-liste');
  if(e.which === 27){ bzListeSchliessen(); $(this).blur(); return; }
  if(!$liste.hasClass('offen') && (e.which === 38 || e.which === 40)){
    bzListeZeigen($wrap); return;
  }
  var $eintraege = $liste.find('.bz-such-eintrag');
  if(!$eintraege.length) return;
  var i = $eintraege.index($eintraege.filter('.aktiv'));
  if(e.which === 40){                       // Pfeil runter
    e.preventDefault();
    i = (i + 1) % $eintraege.length;
  } else if(e.which === 38){                // Pfeil hoch
    e.preventDefault();
    i = (i <= 0 ? $eintraege.length : i) - 1;
  } else if(e.which === 13){                // Enter
    e.preventDefault();
    var $ziel = i >= 0 ? $eintraege.eq(i) : $eintraege.eq(0);
    $ziel.trigger('mousedown');
    return;
  } else return;
  $eintraege.removeClass('aktiv').eq(i).addClass('aktiv');
  var el = $eintraege.get(i);
  if(el && el.scrollIntoView) el.scrollIntoView({block:'nearest'});
});

// mousedown statt click: feuert vor blur, sonst schließt die Liste zu früh
$(document).on('mousedown', '.bz-such-eintrag', function(e){
  e.preventDefault();
  var id = parseInt($(this).data('id'), 10);
  var $wrap = $(this).closest('.bz-such');
  var sw = null;
  $.each(_bzMannSchwimmer, function(i, s){ if(parseInt(s.id,10) === id){ sw = s; return false; } });
  if(sw) bzZuordnen($wrap, sw);
  bzListeSchliessen();
  $wrap.find('.bz-such-feld').blur();
});

$(document).on('click', '.bz-such-x', function(){
  bzZuordnen($(this).closest('.bz-such'), null);
  bzListeSchliessen();
});

$(document).on('blur', '.bz-such-feld', function(){
  var $wrap = $(this).closest('.bz-such');
  // Kurz warten, damit ein Klick auf einen Eintrag noch ankommt
  setTimeout(function(){
    bzListeSchliessen();
    // Freitext ohne Auswahl verwerfen und den zugeordneten Namen wiederherstellen
    var $f = $wrap.find('.bz-such-feld');
    if(!$f.data('tippt')) return;
    var id = parseInt($wrap.attr('data-sw-id'), 10) || 0;
    var sw = null;
    if(id) $.each(_bzMannSchwimmer, function(i, s){ if(parseInt(s.id,10) === id){ sw = s; return false; } });
    $f.val(sw ? bzSchwimmerLabel(sw) : '').data('tippt', false);
  }, 120);
});

// Beim Scrollen die Liste schließen — sie ist fixiert positioniert und
// würde sonst an ihrer alten Stelle stehen bleiben. Scroll-Ereignisse
// steigen nicht auf, deshalb in der Capture-Phase am Dokument lauschen
// (jQuery .on() kann das nicht, daher addEventListener).
document.addEventListener('scroll', function(){
  if(document.querySelector('.bz-such-liste.offen')) bzListeSchliessen();
}, true);

$(document).on('click','#bz-paste-uebernehmen',function(){
  if(!_bzPasteRows||!_bzPasteRows.length){toast('Keine Vorschau-Daten.','err');return;}
  var commitRows=[];
  $.each(_bzPasteRows,function(i,r){
    if(!r.matched||!r.matched.id)return;
    var zeiten={};
    $.each(r.zeiten||[],function(j,z){
      if(z.gueltig)zeiten[z.strecke]=z.raw;
    });
    commitRows.push({
      schwimmer_id:r.matched.id,
      name:r.matched.last_name+', '+r.matched.first_name,
      zeiten:zeiten
    });
  });
  if(!commitRows.length){toast('Keine zu speichernden Zeilen — bitte mindestens einem Schwimmer einen Eintrag zuweisen.','err');return;}
  var $b=$(this).prop('disabled',true).text('Wird gespeichert…');
  ajax('lsv07i_bz_paste_commit',{
    rows_json:JSON.stringify(commitRows)
  }).done(function(r){
    if(!r||!r.success){
      toast((r&&r.data&&r.data.message)||'Import fehlgeschlagen.','err');
      $b.prop('disabled',false);
      bzStandAktualisieren();
      return;
    }
    var d=r.data||{};
    toast(d.message||'Zeiten gespeichert.');
    // Deutlich sichtbare Bestätigung anstelle der Vorschau — ein Toast
    // verschwindet, die Bestätigung bleibt stehen.
    $('#bz-paste-vorschau-content').html(
      '<div class="bz-erfolg">'
      +'<div class="bz-erfolg-ico">✓</div>'
      +'<div class="bz-erfolg-txt">'
      +'<div class="bz-erfolg-ttl">Import abgeschlossen</div>'
      +'<div class="bz-erfolg-sub">'+esc(d.message||'')+'</div>'
      +'</div>'
      +'<button id="bz-erfolg-weiter" class="i-btn i-btn-g">Weitere Zeiten importieren</button>'
      +'</div>');
    $('#bz-paste').val('');
    _bzPasteRows=null;
    // Rangliste neu laden, damit die neuen Zeiten sofort dort stehen
    $('#bz-laden').trigger('click');
  }).fail(function(xhr){
    toast(errMsg(xhr),'err');
    $b.prop('disabled',false);
    bzStandAktualisieren();
  });
});

// „Weitere Zeiten importieren" — Vorschau schließen, Eingabefeld fokussieren
$(document).on('click','#bz-erfolg-weiter',function(){
  $('#bz-paste-vorschau-box').hide();
  $('#bz-paste-vorschau-content').empty();
  $('#bz-paste').val('').focus();
});

$('#bz-laden').on('click',function(){
  var mann=$('#bz-mann-filter').val();
  var strecke=$('#bz-strecke-filter').val();
  skel($('#bz-content'),'block');
  // Lösch-Button immer sichtbar — Beschriftung passt sich an
  if(mann){
    var name=$('#bz-mann-filter option:selected').text();
    $('#bz-loeschen').show().text('Alle löschen ('+name+')').data('mann',mann);
  }else{
    $('#bz-loeschen').show().text('Alle Bestzeiten löschen').data('mann','');
  }
  ajax('lsv07i_bz_get',{mannschaft_id:mann,strecke:strecke}).done(function(r){
    if(!r.success)return;
    renderBestzeiten(r.data.eintraege,r.data.strecken,strecke);
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

$('#bz-loeschen').on('click',function(){
  var mann=$(this).data('mann');
  var bestaetigung;
  if(mann){
    var name=$('#bz-mann-filter option:selected').text();
    bestaetigung='ALLE Bestzeiten für "'+name+'" wirklich löschen?\n\nDieser Vorgang kann nicht rückgängig gemacht werden.';
  }else{
    bestaetigung='ALLE Bestzeiten ALLER Mannschaften wirklich löschen?\n\nDieser Vorgang kann nicht rückgängig gemacht werden!';
  }
  if(!confirm(bestaetigung))return;
  ajax('lsv07i_bz_loeschen',{mannschaft_id:mann||0,alle:mann?0:1}).done(function(r){
    if(r.success){
      toast('Bestzeiten gelöscht'+(r.data.deleted?' ('+r.data.deleted+' Einträge)':'')+'.');
      $('#bz-laden').trigger('click');
    }else toast(r.data.message,'err');
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

// Einzelne Bestzeit löschen via X-Button in der Liste
$(document).on('click','.bz-row-del',function(e){
  e.preventDefault();
  var $btn=$(this);
  var mann=$btn.data('mann'), name=$btn.data('name'), strecke=$btn.data('strecke');
  if(!confirm('Bestzeit von '+name+' ('+strecke+') löschen?'))return;
  $btn.prop('disabled',true).html('…');
  ajax('lsv07i_bz_loeschen_einzeln',{mannschaft_id:mann,swimmer_name:name,strecke:strecke}).done(function(r){
    if(r.success){
      toast('Eintrag gelöscht.');
      $('#bz-laden').trigger('click');
    }else{
      toast(r.data&&r.data.message||'Fehler.','err');
      $btn.prop('disabled',false).html('×');
    }
  }).fail(function(xhr){
    toast(errMsg(xhr),'err');
    $btn.prop('disabled',false).html('×');
  });
});

function renderBestzeiten(eintraege,strecken,filterStrecke){
  // Eine Antwort ohne Einträge oder Streckennamen darf die Seite nicht abstürzen lassen
  eintraege=eintraege||[];strecken=strecken||{};
  if(!eintraege.length){$('#bz-content').html('<span class="i-muted">Keine Bestzeiten vorhanden. Noch keine Bestzeiten vorhanden. Importiere oben Zeiten aus Excel per Copy \u0026 Paste.</span>');return;}
  // Berechtigung zum Einzel-Löschen prüfen
  var canDel=LSV07I.access&&(LSV07I.access.is_admin_raw||LSV07I.access.is_schwimmwart);
  var grouped={};
  $.each(eintraege,function(i,e){if(!grouped[e.strecke])grouped[e.strecke]=[];grouped[e.strecke].push(e);});
  var ord=['50F','100F','200F','400F','800F','1500F','50B','100B','200B','50R','100R','200R','50S','100S','200S','100L','200L','400L'];
  var h='';
  $.each(ord,function(i,sk){
    if(!grouped[sk])return;
    if(filterStrecke&&filterStrecke!==sk)return;
    var label=strecken[sk]||sk;
    var rows=grouped[sk];
    var mannName=rows[0].mannschaft_name||'';
    h+='<div style="margin-bottom:22px">';
    h+='<div style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">'+esc(label)+' &mdash; '+esc(mannName)+'</div>';
    h+='<div class="i-twrap"><table class="i-tbl"><thead><tr><th style="width:44px">Platz</th><th>Name</th><th>Bestzeit</th><th>Stand</th>'+(canDel?'<th style="width:36px"></th>':'')+'</tr></thead><tbody>';
    $.each(rows,function(j,r){
      var bg=r.platz===1?'background:rgba(217,119,6,0.20)':r.platz===2?'background:rgba(255,255,255,0.06)':r.platz===3?'background:rgba(217,119,6,0.15)':'';
      var medal=r.platz===1?'🥇 ':r.platz===2?'🥈 ':r.platz===3?'🥉 ':'';
      var delBtn=canDel?'<td style="text-align:center"><button class="bz-row-del" title="Diesen Eintrag löschen" data-mann="'+r.mannschaft_id+'" data-name="'+esc(r.swimmer_name).replace(/"/g,'&quot;')+'" data-strecke="'+esc(r.strecke)+'" style="background:transparent;border:1px solid #b91c1c;color:#b91c1c;width:24px;height:24px;border-radius:4px;cursor:pointer;font-size:14px;line-height:1;padding:0">×</button></td>':'';
      h+='<tr style="'+bg+'"><td data-label="Platz" style="font-weight:700">'+r.platz+'.</td><td data-label="Name">'+medal+esc(r.swimmer_name)+'</td>'
        +'<td data-label="Bestzeit" style="font-weight:700;font-family:monospace;color:#5b94ff;letter-spacing:.5px">'+esc(r.zeit_raw)+'</td>'
        +'<td data-label="Stand" style="font-size:12px;color:#6b6e85">'+(r.importiert_am?r.importiert_am.slice(0,10):'')+'</td>'
        +delBtn+'</tr>';
    });
    h+='</tbody></table></div></div>';
  });
  $('#bz-content').html(h);
}

$(document).ready(function(){
  initQ();
  var a=LSV07I.access||{};
  if(a.schwimmen)loadSchwimmen();
  if(a.admin)loadAdmin();
  if(a.trainer)loadStammdaten();
  if(a.triathlon&&!a.schwimmen&&!a.admin&&!a.trainer)loadTriathlon();
});

/* ══ WETTKAMPFMELDUNGEN ═════════════════════════════════════════════
   Drei Schritte: Kopf (Wettkampf, Mannschaft, Umfang) → Schwimmer mit
   Anzahl Starts → Meldetabelle. Es ist immer nur ein Schritt sichtbar,
   damit die Seite auf dem Handy schmal und übersichtlich bleibt.       */

var Meld = {
  basis:   null,   // Wettkämpfe, Mannschaften, Strecken, Rechte
  kopf:    null,   // aktuell bearbeitete Meldung
  schwimmer: [],   // Schwimmer der gewählten Mannschaft
  anzahl:  {},     // schwimmer_id → wie oft er startet
  zeilen:  []      // Tabellenzeilen in Schritt 3
};

function meldSchritt(welcher){
  $('#meld-karte-liste,#meld-karte-kopf,#meld-karte-sw,#meld-karte-tab').hide();
  if(welcher==='liste') $('#meld-karte-liste').show();
  if(welcher==='kopf')  $('#meld-karte-kopf').show();
  if(welcher==='sw')    $('#meld-karte-sw').show();
  if(welcher==='tab')   $('#meld-karte-tab').show();
}

// Beim Öffnen des Tabs: erst die Auswahllisten samt Rechten holen, dann
// die Meldungen. Andersherum wüsste die Liste noch nicht, ob der Nutzer
// löschen darf, und der Knopf dazu fehlte.
function meldOeffnen(){
  meldSchritt('liste');
  if(Meld.basis){ meldListeLaden(); return; }
  skel($('#meld-liste'),'rows',3);
  ajax('lsv07i_meld_basis').done(function(r){
    if(!r||!r.success) return;
    Meld.basis=r.data||{};
    var wk=Meld.basis.wettkaempfe||[];
    var h='<option value="">Wettkampf wählen…</option>';
    $.each(wk,function(i,w){
      var zeit=w.datum_von?' ('+de(w.datum_von)+')':'';
      h+='<option value="'+w.id+'">'+esc(w.name)+esc(zeit)+'</option>';
    });
    $('#meld-wk').html(h);
    meldMannschaftenFuellen(0);
    if(!Meld.basis.darf_edit) $('#meld-neu').hide();
  }).fail(function(xhr){ toast(errMsg(xhr),'err'); })
    .always(function(){ meldListeLaden(); });
}

// Mannschaftsauswahl: sind dem Wettkampf Mannschaften zugeordnet, werden
// nur diese gezeigt — sonst alle, die der Nutzer melden darf.
function meldMannschaftenFuellen(wettkampfId){
  var alle=(Meld.basis&&Meld.basis.mannschaften)||[];
  var erlaubt=null;
  if(wettkampfId){
    var wk=$.grep((Meld.basis&&Meld.basis.wettkaempfe)||[],function(w){
      return parseInt(w.id,10)===parseInt(wettkampfId,10);
    })[0];
    if(wk&&wk.mannschaften&&wk.mannschaften.length) erlaubt=wk.mannschaften.map(Number);
  }
  var vorher=$('#meld-mann').val();
  var h='<option value="">Mannschaft wählen…</option>';
  $.each(alle,function(i,m){
    if(erlaubt&&$.inArray(Number(m.id),erlaubt)<0) return;
    h+='<option value="'+m.id+'">'+esc(m.name)+'</option>';
  });
  $('#meld-mann').html(h);
  if(vorher) $('#meld-mann').val(vorher);
}

function meldListeLaden(){
  skel($('#meld-liste'),'rows',3);
  ajax('lsv07i_meld_list').done(function(r){
    if(!r||!r.success){
      $('#meld-liste').html('<div class="i-notice i-notice-r">'+esc((r&&r.data&&r.data.message)||'Fehler.')+'</div>');
      return;
    }
    meldListeRendern(r.data||[]);
  }).fail(function(xhr){
    $('#meld-liste').html('<div class="i-notice i-notice-r">'+esc(errMsg(xhr))+'</div>');
  });
}

function meldListeRendern(rows){
  rows=rows||[];
  if(!rows.length){
    $('#meld-liste').html('<span class="i-muted">Noch keine Meldung angelegt. '
      +'Oben auf „+ Neue Meldung" tippen.</span>');
    return;
  }
  var darfLoeschen=!!(Meld.basis&&Meld.basis.darf_delete);
  var h='<div class="meld-liste">';
  $.each(rows,function(i,m){
    h+='<div class="meld-eintrag" data-id="'+m.id+'">'
      +'<div class="meld-eintrag-txt">'
      +'<div class="meld-eintrag-name">'+esc(m.wettkampf_name||'Wettkampf')+'</div>'
      +'<div class="meld-eintrag-sub">'+esc(m.mannschaft_name||'')
      +(m.datum_von?' · '+de(m.datum_von):'')
      +' · '+(parseInt(m.starts,10)||0)+' Starts</div>'
      +'</div>'
      +'<div class="meld-eintrag-akt">'
      +'<button type="button" class="i-btn i-btn-g i-btn-sm meld-oeffnen" data-id="'+m.id+'">Öffnen</button>'
      +(darfLoeschen?'<button type="button" class="i-btn i-btn-r i-btn-sm meld-del" data-id="'+m.id+'"'
        +' data-name="'+esc((m.wettkampf_name||'')+' — '+(m.mannschaft_name||''))+'">Löschen</button>':'')
      +'</div></div>';
  });
  $('#meld-liste').html(h+'</div>');
}

/* ── Schritt 1 ──────────────────────────────────────────────────── */

$('#meld-neu').on('click',function(){
  Meld.kopf=null; Meld.anzahl={}; Meld.zeilen=[];
  $('#meld-wk').val(''); $('#meld-mann').val('');
  $('#meld-abschnitte').val(2); $('#meld-nummern').val(20);
  meldMannschaftenFuellen(0);
  meldSchritt('kopf');
});

$('#meld-kopf-zurueck').on('click',function(){ meldSchritt('liste'); });

$('#meld-wk').on('change',function(){ meldMannschaftenFuellen($(this).val()); });

$('#meld-kopf-weiter').on('click',function(){
  var wk=parseInt($('#meld-wk').val(),10)||0;
  var mann=parseInt($('#meld-mann').val(),10)||0;
  if(!wk){ toast('Bitte einen Wettkampf wählen.','err'); return; }
  if(!mann){ toast('Bitte eine Mannschaft wählen.','err'); return; }
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_meld_save_kopf',{
    wettkampf_id:wk, mannschaft_id:mann,
    abschnitte:parseInt($('#meld-abschnitte').val(),10)||1,
    wettkampfnummern:parseInt($('#meld-nummern').val(),10)||1
  }).done(function(r){
    if(!r||!r.success){ toast((r&&r.data&&r.data.message)||'Fehler.','err'); return; }
    Meld.kopf=(r.data&&r.data.kopf)||null;
    if(!Meld.kopf){ toast('Die Meldung wurde gespeichert, kam aber unvollständig zurück. Bitte neu laden.','err'); return; }
    meldSchwimmerLaden(r.data.neu!==false);
  }).fail(function(xhr){ toast(errMsg(xhr),'err'); })
    .always(function(){ $b.prop('disabled',false).text('Weiter zu den Schwimmern'); });
});

/* ── Schritt 2 ──────────────────────────────────────────────────── */

// neu = frisch angelegt; dann startet jeder bei 0. Bei einer bestehenden
// Meldung werden die bisherigen Starts als Anzahl übernommen.
function meldSchwimmerLaden(neu){
  if(!Meld.kopf) return;
  meldSchritt('sw');
  $('#meld-sw-titel').text(Meld.kopf.mannschaft_name||'Schwimmer');
  $('#meld-sw-suche').val('');
  skel($('#meld-sw-liste'),'rows',6);
  var mann=parseInt(Meld.kopf.mannschaft_id,10)||0;

  ajax('lsv07i_meld_schwimmer',{mannschaft_id:mann}).done(function(r){
    if(!r||!r.success){
      $('#meld-sw-liste').html('<div class="i-notice i-notice-r">'+esc((r&&r.data&&r.data.message)||'Fehler.')+'</div>');
      return;
    }
    Meld.schwimmer=r.data||[];
    if(neu){ Meld.anzahl={}; meldSwListe(); return; }
    // Bestehende Meldung: Startzahlen und Zeilen übernehmen
    ajax('lsv07i_meld_get',{id:Meld.kopf.id}).done(function(g){
      Meld.anzahl={};
      Meld.zeilen=[];
      if(g&&g.success){
        $.each(g.data.starts||[],function(i,s){
          var sid=parseInt(s.schwimmer_id,10)||0;
          Meld.anzahl[sid]=(Meld.anzahl[sid]||0)+1;
          Meld.zeilen.push({
            schwimmer_id:sid, name:s.schwimmer_name, jahrgang:s.jahrgang,
            abschnitt:s.abschnitt, wettkampf_nr:s.wettkampf_nr,
            strecke:s.strecke, meldezeit:s.meldezeit,
            attest_bis:s.attest_bis||''
          });
        });
      }
      meldSwListe();
    }).fail(function(){ meldSwListe(); });
  }).fail(function(xhr){
    $('#meld-sw-liste').html('<div class="i-notice i-notice-r">'+esc(errMsg(xhr))+'</div>');
  });
}

function meldSwListe(){
  var such=($('#meld-sw-suche').val()||'').toLowerCase().trim();
  var liste=Meld.schwimmer||[];
  var h='';
  $.each(liste,function(i,s){
    if(such && (s.name||'').toLowerCase().indexOf(such)<0) return;
    var n=parseInt(Meld.anzahl[s.id],10)||0;
    h+='<div class="meld-sw'+(n>0?' gewaehlt':'')+'" data-id="'+s.id+'">'
      +'<div class="meld-sw-txt">'
      +'<div class="meld-sw-name">'+esc(s.name)+'</div>'
      +'<div class="meld-sw-jg">'+(s.jahrgang?'Jahrgang '+s.jahrgang:'Jahrgang unbekannt')+'</div>'
      +'</div>'
      +'<div class="meld-zaehler">'
      +'<button type="button" class="meld-minus" aria-label="Weniger Starts">−</button>'
      +'<input type="number" class="i-ctl meld-anz" min="0" max="20" value="'+n+'"'
      +' aria-label="Starts für '+esc(s.name)+'" data-no-save>'
      +'<button type="button" class="meld-plus" aria-label="Mehr Starts">+</button>'
      +'</div></div>';
  });
  if(!h) h='<span class="i-muted">'+(such?'Kein Schwimmer gefunden.':'Diese Mannschaft hat keine Schwimmer.')+'</span>';
  $('#meld-sw-liste').html(h);
  meldSummeAktualisieren();
}

function meldSummeAktualisieren(){
  var summe=0, koepfe=0;
  $.each(Meld.anzahl,function(k,v){
    var n=parseInt(v,10)||0;
    if(n>0){ summe+=n; koepfe++; }
  });
  $('#meld-sw-summe').text(summe+(summe===1?' Start':' Starts')
    +(koepfe?' · '+koepfe+(koepfe===1?' Schwimmer':' Schwimmer'):''));
}

function meldAnzahlSetzen($zeile,neu){
  var id=parseInt($zeile.data('id'),10)||0;
  neu=Math.max(0,Math.min(20,parseInt(neu,10)||0));
  Meld.anzahl[id]=neu;
  $zeile.find('.meld-anz').val(neu);
  $zeile.toggleClass('gewaehlt',neu>0);
  meldSummeAktualisieren();
}

$(document).on('click','.meld-plus',function(){
  var $z=$(this).closest('.meld-sw');
  meldAnzahlSetzen($z,(parseInt($z.find('.meld-anz').val(),10)||0)+1);
});
$(document).on('click','.meld-minus',function(){
  var $z=$(this).closest('.meld-sw');
  meldAnzahlSetzen($z,(parseInt($z.find('.meld-anz').val(),10)||0)-1);
});
$(document).on('input','.meld-anz',function(){
  meldAnzahlSetzen($(this).closest('.meld-sw'),$(this).val());
});
$('#meld-sw-suche').on('input',function(){ meldSwListe(); });

$('#meld-sw-zurueck').on('click',function(){ meldSchritt('kopf'); });

$('#meld-sw-weiter').on('click',function(){
  var summe=0;
  $.each(Meld.anzahl,function(k,v){ summe+=parseInt(v,10)||0; });
  if(!summe){ toast('Bitte für mindestens einen Schwimmer eine Anzahl angeben.','err'); return; }
  meldZeilenBauen();
  meldTabelle();
});

/* ── Schritt 3 ──────────────────────────────────────────────────── */

// Zeilen aus der Anzahl je Schwimmer erzeugen. Bereits ausgefüllte Zeilen
// desselben Schwimmers bleiben erhalten, damit ein Zurück-Sprung nichts
// wegwirft.
function meldZeilenBauen(){
  var alt={};
  $.each(Meld.zeilen||[],function(i,z){
    (alt[z.schwimmer_id]=alt[z.schwimmer_id]||[]).push(z);
  });
  var neu=[];
  $.each(Meld.schwimmer||[],function(i,s){
    var n=parseInt(Meld.anzahl[s.id],10)||0;
    for(var k=0;k<n;k++){
      var vorhanden=alt[s.id]&&alt[s.id][k];
      neu.push(vorhanden||{
        schwimmer_id:s.id, name:s.name, jahrgang:s.jahrgang,
        abschnitt:'', wettkampf_nr:'', strecke:'', meldezeit:'',
        // Vorbelegung aus den Stammdaten — ab hier nur noch für diese Meldung
        attest_bis:s.attest_bis||''
      });
    }
  });
  Meld.zeilen=neu;
}

function meldTitel(){
  if(!Meld.kopf) return 'Meldeliste';
  return (Meld.kopf.wettkampf_name||'Wettkampf')+' – '+(Meld.kopf.mannschaft_name||'Mannschaft');
}

function meldTabelle(){
  meldSchritt('tab');
  $('#meld-tab-titel').text(meldTitel());
  if(!(Meld.basis&&Meld.basis.darf_edit)) $('#meld-tab-speichern').hide();
  if(!(Meld.basis&&Meld.basis.darf_export)) $('#meld-tab-excel').hide();

  var abschnitte=parseInt(Meld.kopf&&Meld.kopf.abschnitte,10)||1;
  var nummern=parseInt(Meld.kopf&&Meld.kopf.wettkampfnummern,10)||1;
  var strecken=(Meld.basis&&Meld.basis.strecken)||{};

  var stichtag=meldStichtag();
  var h='<div class="i-twrap"><table class="i-tbl meld-tab"><thead><tr>'
    +'<th>Schwimmer/in</th><th>Jahrgang</th><th>Attest bis</th><th>Abschnitt</th>'
    +'<th>WettkampfNr.</th><th>Strecke</th><th>Meldezeit</th></tr></thead><tbody>';
  $.each(Meld.zeilen,function(i,z){
    h+='<tr data-i="'+i+'">'
      +'<td data-label="Schwimmer/in"><strong>'+esc(z.name)+'</strong></td>'
      +'<td data-label="Jahrgang">'+(z.jahrgang?esc(z.jahrgang):'<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="Attest bis">'+meldAttestFeld(z.attest_bis,stichtag)+'</td>'
      +'<td data-label="Abschnitt">'+meldZahlFeld('abschnitt',z.abschnitt,abschnitte)+'</td>'
      +'<td data-label="WettkampfNr.">'+meldZahlFeld('wettkampf_nr',z.wettkampf_nr,nummern)+'</td>'
      +'<td data-label="Strecke">'+meldStreckeFeld(z.strecke,strecken)+'</td>'
      +'<td data-label="Meldezeit"><input type="text" class="i-ctl meld-f" data-f="meldezeit"'
      +' value="'+esc(z.meldezeit||'')+'" placeholder="1:02,45" inputmode="decimal" data-no-save></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  h+='<div class="i-lbl-hint" style="margin-top:10px">Meldezeit ist freiwillig. '
    +'Format Minuten:Sekunden,Hundertstel — zum Beispiel 1:02,45 oder 32,10.<br>'
    +'„Attest bis" kommt aus den Stammdaten und gilt geändert nur für diese '
    +'Meldung — die Stammdaten des Schwimmers bleiben unberührt.</div>';
  $('#meld-tab-inhalt').html(h);
}

// Stichtag für die Attest-Prüfung: der erste Wettkampftag, sonst heute
function meldStichtag(){
  var d=(Meld.kopf&&Meld.kopf.datum_von)||'';
  if(/^\d{4}-\d{2}-\d{2}$/.test(d)) return d;
  var h=new Date();
  return h.getFullYear()+'-'+String(h.getMonth()+1).padStart(2,'0')+'-'+String(h.getDate()).padStart(2,'0');
}

// Datumsfeld für das Attest. Fehlt es oder läuft es vor dem Wettkampf ab,
// wird das Feld farbig markiert — genau dafür steht die Spalte hier.
function meldAttestFeld(wert,stichtag){
  wert=wert||'';
  return '<input type="date" class="i-ctl meld-f meld-attest'+meldAttestKlasse(wert,stichtag)+'"'
    +' data-f="attest_bis" value="'+esc(wert)+'"'
    +' title="'+esc(meldAttestHinweis(wert,stichtag))+'"'
    +' aria-label="Attest gültig bis" data-no-save>';
}

function meldAttestKlasse(wert,stichtag){
  if(!wert) return ' fehlt';
  return wert<stichtag?' abgelaufen':'';
}

function meldAttestHinweis(wert,stichtag){
  if(!wert) return 'Kein Attest hinterlegt';
  if(wert<stichtag) return 'Attest ist zum Wettkampf abgelaufen';
  return 'Attest gültig bis '+de(wert);
}

function meldZahlFeld(feld,wert,max){
  var h='<select class="i-ctl meld-f" data-f="'+feld+'"><option value="">–</option>';
  for(var i=1;i<=max;i++){
    h+='<option value="'+i+'"'+(parseInt(wert,10)===i?' selected':'')+'>'+i+'</option>';
  }
  return h+'</select>';
}

function meldStreckeFeld(wert,strecken){
  var h='<select class="i-ctl meld-f" data-f="strecke"><option value="">Strecke wählen…</option>';
  $.each(strecken,function(kuerzel,name){
    h+='<option value="'+esc(kuerzel)+'"'+(wert===kuerzel?' selected':'')+'>'+esc(name)+'</option>';
  });
  return h+'</select>';
}

$(document).on('change input','.meld-f',function(){
  var $tr=$(this).closest('tr');
  var i=parseInt($tr.data('i'),10);
  if(isNaN(i)||!Meld.zeilen[i]) return;
  var feld=$(this).data('f'), wert=$(this).val();
  Meld.zeilen[i][feld]=wert;
  if(feld==='attest_bis'){
    var st=meldStichtag();
    $(this).removeClass('fehlt abgelaufen')
           .addClass(meldAttestKlasse(wert,st).trim())
           .attr('title',meldAttestHinweis(wert,st));
  }
});

$('#meld-tab-zurueck').on('click',function(){ meldSchritt('sw'); });

$('#meld-tab-speichern').on('click',function(){
  if(!Meld.kopf) return;
  var $b=$(this).prop('disabled',true).text('Speichern…');
  ajax('lsv07i_meld_save_starts',{
    meldung_id:Meld.kopf.id,
    starts:JSON.stringify($.map(Meld.zeilen,function(z){
      return {
        schwimmer_id:z.schwimmer_id, abschnitt:z.abschnitt||0,
        wettkampf_nr:z.wettkampf_nr||0, strecke:z.strecke||'',
        meldezeit:z.meldezeit||'', attest_bis:z.attest_bis||''
      };
    }))
  }).done(function(r){
    if(!r||!r.success){ toast((r&&r.data&&r.data.message)||'Fehler.','err'); return; }
    toast((r.data&&r.data.message)||'Meldung gespeichert.');
    meldListeLaden();
  }).fail(function(xhr){ toast(errMsg(xhr),'err'); })
    .always(function(){ $b.prop('disabled',false).text('Speichern'); });
});

/* ── Liste: Öffnen und Löschen ──────────────────────────────────── */

$(document).on('click','.meld-oeffnen',function(){
  var id=parseInt($(this).data('id'),10)||0;
  if(!id) return;
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_meld_get',{id:id}).done(function(r){
    if(!r||!r.success){ toast((r&&r.data&&r.data.message)||'Fehler.','err'); return; }
    Meld.kopf=(r.data&&r.data.kopf)||null;
    if(!Meld.kopf){ toast('Diese Meldung konnte nicht geöffnet werden.','err'); return; }
    $('#meld-wk').val(Meld.kopf.wettkampf_id);
    meldMannschaftenFuellen(Meld.kopf.wettkampf_id);
    $('#meld-mann').val(Meld.kopf.mannschaft_id);
    $('#meld-abschnitte').val(Meld.kopf.abschnitte);
    $('#meld-nummern').val(Meld.kopf.wettkampfnummern);
    meldSchwimmerLaden(false);
  }).fail(function(xhr){ toast(errMsg(xhr),'err'); })
    .always(function(){ $b.prop('disabled',false).text('Öffnen'); });
});

$(document).on('click','.meld-del',function(){
  var id=parseInt($(this).data('id'),10)||0;
  var name=$(this).data('name')||'';
  if(!id) return;
  if(!confirm('Meldung „'+name+'" wirklich löschen?\n\nAlle Starts gehen verloren.')) return;
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_meld_delete',{id:id}).done(function(r){
    if(!r||!r.success){ toast((r&&r.data&&r.data.message)||'Fehler.','err'); $b.prop('disabled',false).text('Löschen'); return; }
    toast((r.data&&r.data.message)||'Meldung gelöscht.');
    if(Meld.kopf&&parseInt(Meld.kopf.id,10)===id) Meld.kopf=null;
    meldListeLaden();
  }).fail(function(xhr){ toast(errMsg(xhr),'err'); $b.prop('disabled',false).text('Löschen'); });
});

/* ── Excel-Export ───────────────────────────────────────────────── */

$('#meld-tab-excel').on('click',function(){
  if(!Meld.zeilen.length){ toast('Die Tabelle ist leer.','err'); return; }
  var strecken=(Meld.basis&&Meld.basis.strecken)||{};
  var titel=meldTitel();

  // Kopfzeile mit dem Titel, dann eine Leerzeile, dann die Tabelle
  var aoa=[[titel],[],
    ['Schwimmer/in','Jahrgang','Attest bis','Abschnitt','WettkampfNr.','Strecke','Meldezeit']];
  $.each(Meld.zeilen,function(i,z){
    aoa.push([
      z.name||'',
      z.jahrgang?Number(z.jahrgang):'',
      z.attest_bis?de(z.attest_bis):'',
      z.abschnitt?Number(z.abschnitt):'',
      z.wettkampf_nr?Number(z.wettkampf_nr):'',
      z.strecke?(strecken[z.strecke]||z.strecke):'',
      z.meldezeit||''
    ]);
  });

  var $b=$(this).prop('disabled',true).text('…');
  ladeXLSX(function(ok){
    $b.prop('disabled',false).text('Excel-Export');
    if(!ok||typeof XLSX==='undefined'){
      toast('Excel-Bibliothek konnte nicht geladen werden.','err');
      return;
    }
    var ws=XLSX.utils.aoa_to_sheet(aoa);
    ws['!cols']=[{wch:28},{wch:10},{wch:12},{wch:11},{wch:14},{wch:22},{wch:12}];
    // Titel über alle sieben Spalten zusammenfassen
    ws['!merges']=[{s:{r:0,c:0},e:{r:0,c:6}}];
    var wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,meldBlattName());
    XLSX.writeFile(wb,meldDateiName());
    toast('Meldeliste exportiert ('+Meld.zeilen.length+' Starts).');
  });
});

// Excel erlaubt in Blattnamen kein : \ / ? * [ ] und höchstens 31 Zeichen
function meldBlattName(){
  var n=(Meld.kopf&&Meld.kopf.mannschaft_name)||'Meldung';
  return n.replace(/[:\\\/\?\*\[\]]/g,' ').slice(0,31)||'Meldung';
}

function meldDateiName(){
  var teil=function(s){ return (s||'').replace(/[^a-zA-Z0-9äöüÄÖÜß-]+/g,'_').slice(0,40); };
  return 'meldung_'+teil(Meld.kopf&&Meld.kopf.wettkampf_name)
    +'_'+teil(Meld.kopf&&Meld.kopf.mannschaft_name)+'.xlsx';
}

/* ══ JAHRESÜBERSICHT KASSENWART ═════════════════════════════════ */
var S_jahrData=null;

$('#kw-jahr-laden').on('click',function(){
  var $b=$(this).prop('disabled',true).text('Laden…');
  var jahr=$('#kw-jahr-sel').val();
  skel($('#kw-jahr-content'),'block');
  ajax('lsv07i_abr_jahresuebersicht',{jahr:jahr}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message?r.data.message:'Fehler.','err');return;}
    S_jahrData=r.data;
    // Jahr-Dropdown aktualisieren
    var $sel=$('#kw-jahr-sel');
    $sel.find('option').remove();
    $.each(r.data.jahre,function(i,j){$sel.append('<option value="'+j+'"'+(j==r.data.jahr?' selected':'')+'>'+j+'</option>');});
    renderJahresuebersicht(r.data);
    // Kostenauswertung nach Mannschaft (nur genehmigte Abrechnungen) anhängen
    ajax('lsv07i_abr_kosten_mannschaft',{jahr:r.data.jahr}).done(function(kr){
      if(kr.success) renderKostenMannschaft(kr.data);
    });
    $('#kw-csv-export').css('display','inline-flex');
    $('#kw-jahr-pdf').css('display','inline-flex');
  }).fail(function(xhr){toast(errMsg(xhr),'err');$('#kw-jahr-content').html('<span class="i-muted">Fehler beim Laden.</span>');})
    .always(function(){$b.prop('disabled',false).text('Laden');});
});

function renderJahresuebersicht(d){
  var trainer=d.trainer||[],jahr=d.jahr;
  if(!trainer.length){$('#kw-jahr-content').html('<span class="i-muted">Keine Abrechnungen für '+jahr+'.</span>');return;}
  var quartale=['Q1','Q2','Q3','Q4'];
  var gesamtJahr=0,bezahltJahr=0,offenJahr=0;

  var h='<div style="overflow-x:auto"><table class="i-tbl" style="min-width:700px">';
  h+='<thead><tr>'
    +'<th style="text-align:left">Trainer</th>'
    +'<th style="text-align:right">Q1</th>'
    +'<th style="text-align:right">Q2</th>'
    +'<th style="text-align:right">Q3</th>'
    +'<th style="text-align:right">Q4</th>'
    +'<th style="text-align:right">Gesamt</th>'
    +'<th style="text-align:center" title="Übungsleiterfreibetrag 3.000 € pro Jahr">Freibetrag</th>'
    +'<th style="text-align:center">Bezahlt</th>'
    +'<th style="text-align:right">Offen</th>'
    +'</tr></thead><tbody>';

  $.each(trainer,function(i,t){
    gesamtJahr+=t.gesamt;bezahltJahr+=t.bezahlt;offenJahr+=t.offen;
    h+='<tr>';
    h+='<td data-label="Trainer" style="font-weight:500">'+esc(t.trainer_name)+'</td>';
    $.each(quartale,function(j,q){
      var qd=t.quartale[q];
      if(!qd){h+='<td data-label="'+q+'" style="text-align:right;color:#8b8ea0">–</td>';return;}
      var statusColor=qd.abr_status==='genehmigt'?'#15803d':qd.abr_status==='eingereicht'?'#b45309':'#5b6072';
      var bezIcon=qd.bezahlt?'<span style="color:#15803d;margin-left:4px">✓</span>':'';
      h+='<td data-label="'+q+'" style="text-align:right">'
        +'<span style="font-size:13px;color:'+statusColor+'">'+eur(qd.gesamt)+'</span>'+bezIcon
        +'</td>';
    });
    h+='<td data-label="Gesamt" style="text-align:right;font-weight:700">'+eur(t.gesamt)+'</td>';
    // Übungsleiterfreibetrag-Ampel: 3.000 €/Jahr (§ 3 Nr. 26 EStG).
    // Grün < 80 %, Gelb ab 80 %, Rot ab 100 %. Liegen noch nicht alle vier
    // Quartale vor, wird zusätzlich aufs Jahr hochgerechnet (Pfeil ↗),
    // damit der Kassenwart früh gegensteuern kann.
    (function(){
      var FREI=3000, pct=t.gesamt/FREI*100;
      var nQ=0;$.each(['Q1','Q2','Q3','Q4'],function(_,q){if(t.quartale[q])nQ++;});
      var hoch=(nQ>0&&nQ<4)?t.gesamt/nQ*4:null;
      var cls=pct>=100?'r':pct>=80?'a':'g', txt=Math.round(pct)+'%';
      var tip='Jahressumme '+eur(t.gesamt)+' von 3.000,00 € Freibetrag';
      if(hoch!==null){
        tip+=' | Hochrechnung ('+nQ+' Quartal'+(nQ>1?'e':'')+'): '+eur(hoch);
        if(hoch>=FREI && pct<100){cls=(cls==='g')?'a':cls;txt+=' ↗';}
      }
      h+='<td data-label="Freibetrag" style="text-align:center"><span title="'+tip+'">'+bdg(cls,txt)+'</span></td>';
    })();
    h+='<td data-label="Bezahlt" style="text-align:center">'+eur(t.bezahlt)+'</td>';
    h+='<td data-label="Offen" style="text-align:right;color:'+(t.offen>0?'#b91c1c':'#5b6072')+'">'+eur(t.offen)+'</td>';
    h+='</tr>';
  });

  // Summenzeile
  h+='<tr style="font-weight:700;background:rgba(91,148,255,0.12);border-top:2px solid rgba(255,255,255,0.15)">';
  h+='<td data-label="">Gesamt '+jahr+'</td>';
  h+='<td data-label="Q1" colspan="1"></td><td data-label="Q2" colspan="1"></td><td data-label="Q3" colspan="1"></td><td data-label="Q4" colspan="1"></td>';
  h+='<td data-label="Gesamt" style="text-align:right">'+eur(gesamtJahr)+'</td>';
  h+='<td data-label="Freibetrag"></td>';
  h+='<td data-label="Bezahlt" style="text-align:center">'+eur(bezahltJahr)+'</td>';
  h+='<td data-label="Offen" style="text-align:right;color:'+(offenJahr>0?'#b91c1c':'#5b6072')+'">'+eur(offenJahr)+'</td>';
  h+='</tr>';
  h+='</tbody></table></div>';

  // Zusammenfassung oben
  var summary='<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px">'
    +'<div style="background:rgba(91,148,255,0.12);border-radius:8px;padding:10px 16px;min-width:140px">'
    +'<div style="font-size:11px;color:#6b6e85;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Auszahlungen gesamt</div>'
    +'<div style="font-size:20px;font-weight:700;color:#5b94ff">'+eur(gesamtJahr)+'</div></div>'
    +'<div style="background:rgba(22,163,74,0.15);border-radius:8px;padding:10px 16px;min-width:140px">'
    +'<div style="font-size:11px;color:#6b6e85;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Bereits bezahlt</div>'
    +'<div style="font-size:20px;font-weight:700;color:#15803d">'+eur(bezahltJahr)+'</div></div>'
    +'<div style="background:rgba(217,119,6,0.15);border-radius:8px;padding:10px 16px;min-width:140px">'
    +'<div style="font-size:11px;color:#6b6e85;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Noch offen</div>'
    +'<div style="font-size:20px;font-weight:700;color:'+(offenJahr>0?'#b91c1c':'#5b6072')+'">'+eur(offenJahr)+'</div></div>'
    +'</div>';

  $('#kw-jahr-content').html(summary+h);
}

// Jahresübersicht als echtes PDF herunterladen (Querformat wegen vieler Spalten)
function kwGenerateJahrPdf(d) {
  var fmtEur = pdfEur;
  if (!window.jspdf || !window.jspdf.jsPDF) { toast('PDF-Bibliothek nicht geladen. Bitte Seite neu laden.', 'err'); return; }
  var trainer = d.trainer || [], jahr = d.jahr;
  var doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'landscape' });
  var pageW = doc.internal.pageSize.getWidth(), pageH = doc.internal.pageSize.getHeight();
  var mL = 12, mR = 12, y = 16;
  var colBlue = [30, 58, 95], colHead = [232, 238, 249];
  var quartale = ['Q1', 'Q2', 'Q3', 'Q4'];

  // Titel
  doc.setFont('helvetica', 'bold'); doc.setFontSize(16); doc.setTextColor.apply(doc, colBlue);
  doc.text('Jahresuebersicht Abrechnungen ' + jahr, mL, y); y += 8;

  // Summen berechnen
  var gesamtJahr = 0, bezahltJahr = 0, offenJahr = 0;
  trainer.forEach(function (t) { gesamtJahr += t.gesamt; bezahltJahr += t.bezahlt; offenJahr += t.offen; });

  // Zusammenfassungs-Boxen
  doc.setFontSize(9);
  function box(x, w, label, val, rgb) {
    doc.setFillColor(rgb[0], rgb[1], rgb[2]); doc.roundedRect(x, y, w, 15, 2, 2, 'F');
    doc.setTextColor(90, 90, 90); doc.setFont('helvetica', 'normal'); doc.setFontSize(8);
    doc.text(label, x + 4, y + 5);
    doc.setTextColor.apply(doc, colBlue); doc.setFont('helvetica', 'bold'); doc.setFontSize(12);
    doc.text(val, x + 4, y + 12);
  }
  var bw = (pageW - mL - mR - 8) / 3;
  box(mL, bw, 'Auszahlungen gesamt', fmtEur(gesamtJahr), [238, 243, 252]);
  box(mL + bw + 4, bw, 'Bereits bezahlt', fmtEur(bezahltJahr), [233, 245, 236]);
  box(mL + 2 * (bw + 4), bw, 'Noch offen', fmtEur(offenJahr), [250, 240, 230]);
  y += 22;

  // Tabelle
  var cols = ['Trainer', 'Q1', 'Q2', 'Q3', 'Q4', 'Gesamt', 'Bezahlt', 'Offen'];
  var innerW = pageW - mL - mR;
  var wTr = innerW * 0.24, wQ = innerW * 0.10, wRest = innerW * 0.12;
  var widths = [wTr, wQ, wQ, wQ, wQ, wRest, wRest, wRest];

  function headerRow() {
    var x = mL;
    doc.setFillColor.apply(doc, colHead); doc.rect(mL, y - 4, innerW, 7, 'F');
    doc.setFont('helvetica', 'bold'); doc.setFontSize(9); doc.setTextColor(40, 40, 40);
    cols.forEach(function (c, i) {
      var alignR = (i >= 1);
      doc.text(c, alignR ? x + widths[i] - 2 : x + 2, y, { align: alignR ? 'right' : 'left' });
      x += widths[i];
    });
    y += 6;
  }
  headerRow();

  doc.setFont('helvetica', 'normal'); doc.setTextColor(30, 30, 30);
  trainer.forEach(function (t) {
    if (y > pageH - 18) { doc.addPage(); y = 16; headerRow(); doc.setFont('helvetica', 'normal'); doc.setTextColor(30, 30, 30); }
    var x = mL;
    doc.setFontSize(9);
    doc.text(String(t.trainer_name || ''), x + 2, y); x += widths[0];
    quartale.forEach(function (q, qi) {
      var qd = t.quartale[q];
      doc.text(qd ? fmtEur(qd.gesamt) : '-', x + widths[qi + 1] - 2, y, { align: 'right' });
      x += widths[qi + 1];
    });
    doc.setFont('helvetica', 'bold');
    doc.text(fmtEur(t.gesamt), x + widths[5] - 2, y, { align: 'right' }); x += widths[5];
    doc.setFont('helvetica', 'normal');
    doc.text(fmtEur(t.bezahlt), x + widths[6] - 2, y, { align: 'right' }); x += widths[6];
    doc.text(fmtEur(t.offen), x + widths[7] - 2, y, { align: 'right' });
    doc.setDrawColor(235, 235, 235); doc.line(mL, y + 1.5, pageW - mR, y + 1.5);
    y += 6;
  });

  // Summenzeile
  if (y > pageH - 18) { doc.addPage(); y = 16; }
  var x2 = mL;
  doc.setFillColor(238, 243, 252); doc.rect(mL, y - 4, innerW, 7, 'F');
  doc.setFont('helvetica', 'bold'); doc.setTextColor.apply(doc, colBlue);
  doc.text('Gesamt ' + jahr, x2 + 2, y); x2 += widths[0] + widths[1] + widths[2] + widths[3] + widths[4];
  doc.text(fmtEur(gesamtJahr), x2 + widths[5] - 2, y, { align: 'right' }); x2 += widths[5];
  doc.text(fmtEur(bezahltJahr), x2 + widths[6] - 2, y, { align: 'right' }); x2 += widths[6];
  doc.text(fmtEur(offenJahr), x2 + widths[7] - 2, y, { align: 'right' });

  doc.save('Jahresuebersicht_' + jahr + '.pdf');
}

$('#kw-jahr-pdf').on('click', function () {
  if (!S_jahrData) { toast('Bitte zuerst eine Jahresübersicht laden.', 'err'); return; }
  kwGenerateJahrPdf(S_jahrData);
});

$('#kw-csv-export').on('click',function(){
  var $b=$(this).prop('disabled',true).text('Exportiert…');
  var jahr=$('#kw-jahr-sel').val();
  ajax('lsv07i_abr_csv_export',{jahr:jahr}).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)?r.data.message:'CSV-Export fehlgeschlagen.','err');return;}
    // CSV als Datei-Download auslösen
    var blob=new Blob([r.data.csv],{type:'text/csv;charset=utf-8;'});
    var url=URL.createObjectURL(blob);
    var a=document.createElement('a');
    a.href=url;a.download='abrechnungen_'+jahr+'.csv';
    document.body.appendChild(a);a.click();
    document.body.removeChild(a);URL.revokeObjectURL(url);
    toast(r.data.rows+' Abrechnungen exportiert.');
  }).fail(function(xhr){toast(errMsg(xhr),'err');})
    .always(function(){$b.prop('disabled',false).text('CSV exportieren');});
});

/* ══ SCHWIMMER-PROFIL MODAL ═════════════════════════════════ */
$(document).on('click','.sw-row, .sw-zeile',function(){
  var sid=$(this).data('sid');
  if(!sid)return;
  $('#m-sw-profil-ttl').text('Wird geladen…');
  skel($('#m-sw-profil-bd'),'lines',6);
  $('#m-sw-profil').addClass('open');
  ajax('lsv07i_schwimmen_get_profil',{swimmer_id:sid}).done(function(r){
    if(!r.success){$('#m-sw-profil-bd').html('<div class="i-notice i-notice-r">'+esc(r.data&&r.data.message?r.data.message:'Fehler')+'</div>');return;}
    renderSwProfil(r.data);
  }).fail(function(xhr){$('#m-sw-profil-bd').html('<div class="i-notice i-notice-r">'+esc(errMsg(xhr))+'</div>');});
});

// Zwischenspeicher für die Bestzeiten des aktuell geöffneten Profils
var SW_PROFIL_BZ='';
// Button im Profil öffnet das Bestzeiten-Popup
$(document).on('click','#prof-bz-btn',function(){
  var ttl=$('#m-sw-profil-ttl').text();
  $('#m-sw-bz-ttl').text('Bestzeiten – '+ttl);
  $('#m-sw-bz-bd').html(SW_PROFIL_BZ||'<span class="i-muted">Keine Bestzeiten.</span>');
  openModal('m-sw-bz');
});

function renderSwProfil(d){
  // Gegen unvollständige Antworten absichern: fehlt ein Block, brach die
  // Anzeige vorher mit einem Fehler ab und das Fenster blieb leer.
  d = d || {};
  var s=d.swimmer||{}, anw=d.anwesenheit||{}, bz=d.bestzeiten||[];
  if(!s.id&&!s.last_name){
    $('#m-sw-profil-bd').html('<div class="i-notice i-notice-r">Profil konnte nicht geladen werden.</div>');
    return;
  }
  var name=esc((s.last_name||'')+', '+(s.first_name||''));
  $('#m-sw-profil-ttl').text(s.first_name+' '+s.last_name);

  // Attest
  var attColor=s.attest_status==='abgelaufen'?'#b91c1c':s.attest_status==='laeuft_ab'?'#d97706':'#16a34a';
  var attLabel=s.attest_status==='abgelaufen'?'Abgelaufen':s.attest_status==='laeuft_ab'?'Läuft bald ab':s.attest_status==='gueltig'?'Gültig':'Kein Attest';
  var attHtml=s.attest_expires&&s.attest_expires!=='0000-00-00'
    ?'<span style="color:'+attColor+';font-weight:600">'+de(s.attest_expires)+'</span> <span style="font-size:11px;color:'+attColor+'">'+attLabel+'</span>'
    :'<span style="color:#7c7f94">Kein Attest hinterlegt</span>';

  // Anwesenheitsquote
  var gesamt=anw.sessions_mann||0;
  var quote=gesamt>0?Math.round(anw.anwesend/gesamt*100):0;
  var qColor=quote>=75?'#16a34a':quote>=50?'#d97706':'#b91c1c';

  // Letzte Trainings (max 8)
  var letzteH='';
  if(anw.letzte&&anw.letzte.length){
    $.each(anw.letzte,function(i,t){
      var icon,color;
      if(t.ausgefallen==1){icon='✕';color='#5b6072';}
      else if(t.status==='anwesend'){icon='✓';color='#16a34a';}
      else if(t.status==='entschuldigt'){icon='E';color='#d97706';}
      else if(t.status==='abwesend'){icon='✗';color='#b91c1c';}
      else{icon='?';color='#5b6072';}
      letzteH+='<span title="'+de(t.training_datum)+(t.ausgefallen==1?' (Ausgefallen)':'')+'" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:'+color+'22;color:'+color+';font-size:13px;font-weight:700;border:1.5px solid '+color+'40">'+icon+'</span>';
    });
  }else{letzteH='<span style="color:#7c7f94;font-size:12px">Keine Daten</span>';}

  // Bestzeiten — werden in einem separaten Popup angezeigt
  var bzPopupH='';
  var bzCount = (bz&&bz.length)?bz.length:0;
  if(bz&&bz.length){
    var STRECKEN_LABELS={
      '50F':'50m Freistil','100F':'100m Freistil','200F':'200m Freistil','400F':'400m Freistil','800F':'800m Freistil','1500F':'1500m Freistil',
      '50B':'50m Brust','100B':'100m Brust','200B':'200m Brust',
      '50R':'50m Rücken','100R':'100m Rücken','200R':'200m Rücken',
      '50S':'50m Schmetterling','100S':'100m Schmetterling','200S':'200m Schmetterling',
      '100L':'100m Lagen','200L':'200m Lagen','400L':'400m Lagen'
    };
    bzPopupH='<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">';
    $.each(bz,function(i,b){
      bzPopupH+='<div style="background:rgba(91,148,255,0.12);border-radius:6px;padding:8px 10px">'
        +'<div style="font-size:11px;color:#6b6e85">'+esc(STRECKEN_LABELS[b.strecke]||b.strecke)+'</div>'
        +'<div style="font-size:15px;font-weight:700;font-family:monospace;color:#5b94ff;letter-spacing:.5px">'+esc(b.zeit_raw)+'</div>'
        +'<div style="font-size:10px;color:#7c7f94">'+de(b.importiert_am?b.importiert_am.slice(0,10):'')+'</div>'
        +'</div>';
    });
    bzPopupH+='</div>';
  }else{bzPopupH='<span style="color:#6b6e85;font-size:13px">Keine Bestzeiten erfasst.</span>';}
  // Inhalt fürs Popup zwischenspeichern, Button öffnet es
  SW_PROFIL_BZ=bzPopupH;
  var bzH = bzCount>0
    ? '<button id="prof-bz-btn" class="i-btn i-btn-g" style="width:100%">Bestzeiten anzeigen ('+bzCount+')</button>'
    : '<span style="color:#7c7f94;font-size:12px">Keine Bestzeiten erfasst</span>';

  // Kontaktpersonen
  var ktH='';
  if(s.kontakte&&s.kontakte.length){
    $.each(s.kontakte,function(i,k){
      ktH+='<div style="padding:8px 0;border-bottom:1px solid #f0f4f8">'
        +'<div style="font-weight:500;font-size:13px">'+esc(k.name)+'</div>'
        +'<div style="font-size:12px;margin-top:2px;display:flex;flex-wrap:wrap;gap:8px">';
      if(k.telefon)ktH+='<a href="tel:'+esc(k.telefon)+'" style="color:#5b94ff;display:flex;align-items:center;gap:3px">📞 '+esc(k.telefon)+'</a>';
      if(k.email)ktH+='<a href="mailto:'+esc(k.email)+'" style="color:#5b94ff;display:flex;align-items:center;gap:3px">✉ '+esc(k.email)+'</a>';
      ktH+='</div></div>';
    });
  }else{ktH='<span style="color:#7c7f94;font-size:12px">Keine Kontaktpersonen</span>';}

  var sek=function(lbl,val){return '<div style="margin-bottom:16px">'
    +'<div style="font-size:11px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">'+lbl+'</div>'
    +val+'</div>';};

  // Alle Mannschaften des Schwimmers
  var gruppenStr=s.alle_gruppen_namen&&s.alle_gruppen_namen.length?s.alle_gruppen_namen.map(esc).join(' / '):esc(s.mannschaft_name||'–');
  var html='<div style="padding:16px 18px">'
    // Avatar + Name
    +'<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #e5edf5">'
    +'<div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#5b94ff,#3584e4);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#1c1d2e;flex-shrink:0">'+esc(s.first_name.charAt(0))+esc(s.last_name.charAt(0))+'</div>'
    +'<div><div style="font-size:18px;font-weight:700;color:#1c1d2e">'+esc(s.first_name+' '+s.last_name)+'</div>'
    +'<div style="font-size:13px;color:#6b6e85">'+gruppenStr+(s.birth_date&&s.birth_date!=='0000-00-00'?' · '+de(s.birth_date):'')+'</div>'
    +(s.dsv_id?'<div style="font-size:11px;color:#6b6e85">DSV-ID: '+esc(s.dsv_id)+'</div>':'')
    +(s.email?'<a href="mailto:'+esc(s.email)+'" style="font-size:12px;color:#5b94ff">'+esc(s.email)+'</a>':'')
    +(parseInt(s.datenschutz)?'<div style="font-size:11px;color:#22c55e;margin-top:2px">✓ Datenschutz unterzeichnet</div>':'<div style="font-size:11px;color:#b91c1c;margin-top:2px">✗ Datenschutz fehlt</div>')
    +'</div></div>'

    // Attest
    +sek('Attest', attHtml)

    // Anwesenheit
    +sek('Anwesenheit', '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">'
      +'<div style="text-align:center"><div style="font-size:26px;font-weight:800;color:'+qColor+'">'+quote+'%</div>'
      +'<div style="font-size:11px;color:#6b6e85">von '+gesamt+' Trainings</div></div>'
      +'<div style="font-size:12px;color:#6b6e85;line-height:1.8">'
      +'<span style="color:#22c55e;font-weight:600">'+anw.anwesend+'✓</span> anwesend&nbsp;&nbsp;'
      +'<span style="color:#b91c1c;font-weight:600">'+anw.abwesend+'✗</span> abwesend&nbsp;&nbsp;'
      +'<span style="color:#d97706;font-weight:600">'+anw.entschuldigt+'E</span> entschuldigt'
      +'</div></div>'
      +'<div style="margin-top:10px"><div style="font-size:11px;color:#7c7f94;margin-bottom:6px">Letzte Trainings (neueste zuerst)</div>'
      +'<div style="display:flex;gap:4px;flex-wrap:wrap">'+letzteH+'</div></div>'
    )

    // Bestzeiten
    +sek('Bestzeiten', bzH)

    // Dateien — werden asynchron nach dem Render geladen
    +sek('Dateien', '<div id="prof-dateien"><span style="color:#7c7f94;font-size:12px">Lade…</span></div>')

    // Kontakte
    +sek('Kontaktpersonen', ktH)

    // Notizen
    +(s.notes?sek('Notizen','<div style="font-size:13px;color:#6b6e85;line-height:1.6;white-space:pre-wrap">'+esc(s.notes)+'</div>'):'')

    // Bearbeiten-Button (für Trainer / Schwimmwart / Admin)
    +'<div style="padding:12px 0 4px;border-top:1px solid #e5edf5;margin-top:12px"><button id="prof-edit-btn" class="i-btn i-btn-p" data-sid="'+s.id+'" style="width:100%">Daten bearbeiten</button></div>'

    +'</div>';

  $('#m-sw-profil-bd').html(html);

  // Dateien des Schwimmers asynchron nachladen
  loadProfDateien(s.id);
}

// Profil-Datei-Sektion neu rendern (eigene Funktion, weil sie nach
// Upload/Delete erneut aufgerufen werden muss).
function loadProfDateien(sid){
  var $box=$('#prof-dateien');
  if(!$box.length)return;
  $box.html('<span style="color:#7c7f94;font-size:12px">Lade…</span>');
  ajax('lsv07i_sw_datei_list',{schwimmer_id:sid}).done(function(r){
    if(!r||!r.success){
      var msg=(r&&r.data&&r.data.message)?r.data.message:'Keine Berechtigung oder Fehler.';
      $box.html('<span style="color:#7c7f94;font-size:12px">'+esc(msg)+'</span>');
      return;
    }
    // Backend liefert jetzt {files, can}
    var files=(r.data&&r.data.files)?r.data.files:(Array.isArray(r.data)?r.data:[]);
    var can=(r.data&&r.data.can)?r.data.can:{upload:false,delete:false};

    var h='';

    // Upload-Form (nur wenn User Upload-Recht hat)
    if(can.upload){
      h+='<div style="margin-bottom:10px">'
        +'<button type="button" class="i-btn i-btn-g i-btn-sm prof-datei-add" data-sid="'+sid+'">+ Datei hochladen</button>'
        +'<div class="prof-datei-form" data-sid="'+sid+'" style="display:none;margin-top:8px;padding:10px;background:rgba(91,148,255,0.12);border-radius:8px">'
        +'<input type="file" class="prof-datei-input i-ctl" accept=".pdf,.jpg,.jpeg,.png">'
        +'<input type="text" class="prof-datei-kom i-ctl" placeholder="Kommentar (optional, z.B. \'Attest 2025/2026\')" maxlength="500" style="margin-top:6px">'
        +'<div style="display:flex;gap:6px;justify-content:flex-end;margin-top:8px">'
        +'<button type="button" class="i-btn i-btn-r i-btn-sm prof-datei-cancel">Abbrechen</button>'
        +'<button type="button" class="i-btn i-btn-p i-btn-sm prof-datei-upload" data-sid="'+sid+'">Hochladen</button>'
        +'</div>'
        +'<div class="i-lbl-hint" style="margin-top:6px">PDF, JPG, PNG · max. 5 MB</div>'
        +'</div></div>';
    }

    if(!files.length){
      h+='<span style="color:#7c7f94;font-size:12px">Keine Dateien hochgeladen</span>';
      $box.html(h);
      return;
    }

    h+='<div style="display:flex;flex-direction:column;gap:6px">';
    $.each(files,function(i,f){
      var size=Math.round(f.groesse/1024);
      var sizeStr=size>1024?(Math.round(size/1024*10)/10)+' MB':size+' kB';
      var dat=f.hochgeladen_am?de(String(f.hochgeladen_am).slice(0,10)):'';
      var icon=f.mime_type==='application/pdf'?'📄':'🖼';
      var dlUrl=LSV07I.ajax_url+'?action=lsv07i_sw_datei_download&id='+f.id+'&nonce='+encodeURIComponent(LSV07I.nonce);
      var kom=f.kommentar?'<div style="font-size:11.5px;color:#6b6e85;margin-top:2px">'+esc(f.kommentar)+'</div>':'';
      var delBtn=can['delete']
        ?'<button type="button" class="i-btn i-btn-r i-btn-sm prof-datei-del" data-id="'+f.id+'" data-sid="'+sid+'" title="Löschen" style="flex-shrink:0">✕</button>'
        :'';
      h+='<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:rgba(91,148,255,0.12);border-radius:8px">'
        +'<span style="font-size:18px;flex-shrink:0">'+icon+'</span>'
        +'<div style="flex:1;min-width:0">'
        +'<a href="'+dlUrl+'" target="_blank" style="font-size:13px;font-weight:600;color:#5b94ff;word-break:break-all">'+esc(f.dateiname)+'</a>'
        +'<div style="font-size:11px;color:#6b6e85;margin-top:1px">'+sizeStr+' · '+esc(dat)+' · '+esc(f.hochgeladen_von_name||'?')+'</div>'
        +kom
        +'</div>'
        +delBtn
        +'</div>';
    });
    h+='</div>';
    $box.html(h);
  }).fail(function(){
    $box.html('<span style="color:#7c7f94;font-size:12px">Dateien konnten nicht geladen werden.</span>');
  });
}

// ─── Profil-Datei: Upload-Form öffnen / schließen ─────────────────────
$(document).on('click','.prof-datei-add',function(){
  $(this).next('.prof-datei-form').show();
  $(this).hide();
});
$(document).on('click','.prof-datei-cancel',function(){
  var $form=$(this).closest('.prof-datei-form');
  $form.find('.prof-datei-input').val('');
  $form.find('.prof-datei-kom').val('');
  $form.hide();
  $form.prev('.prof-datei-add').show();
});

// Upload aus Profil
$(document).on('click','.prof-datei-upload',function(){
  var $btn=$(this);
  var sid=$btn.data('sid');
  var $form=$btn.closest('.prof-datei-form');
  var fileInput=$form.find('.prof-datei-input')[0];
  if(!fileInput.files||!fileInput.files.length){toast('Bitte eine Datei wählen.','err');return;}
  var file=fileInput.files[0];
  if(file.size>5*1024*1024){toast('Datei zu groß (max. 5 MB).','err');return;}

  var fd=new FormData();
  fd.append('action','lsv07i_sw_datei_upload');
  fd.append('nonce',LSV07I.nonce);
  fd.append('schwimmer_id',sid);
  fd.append('datei',file);
  fd.append('kommentar',$form.find('.prof-datei-kom').val()||'');

  $btn.prop('disabled',true).text('Lädt hoch…');
  startLoader();
  $.ajax({
    url:LSV07I.ajax_url,
    type:'POST',
    data:fd,
    processData:false,
    contentType:false,
    // Siehe Profilbild-Upload: ohne dataType gilt eine Antwort mit
    // unerwartetem Content-Type als Misserfolg, obwohl sie geklappt hat.
    dataType:'json'
  }).done(function(r){
    if(!r||r==='0'){toast('Endpunkt nicht erreichbar.','err');return;}
    if(!r.success){
      toast((r.data&&r.data.message)||'Upload fehlgeschlagen.','err');
      return;
    }
    toast(r.data.message||'Hochgeladen.');
    loadProfDateien(sid);
  }).fail(function(xhr){
    toast('Upload-Fehler: '+errMsg(xhr),'err');
  }).always(function(){
    $btn.prop('disabled',false).text('Hochladen');
    stopLoader();
  });
});

// Löschen aus Profil
$(document).on('click','.prof-datei-del',function(){
  var id=$(this).data('id'),sid=$(this).data('sid');
  if(!confirm('Diese Datei wirklich löschen?'))return;
  ajax('lsv07i_sw_datei_delete',{id:id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast(r.data.message||'Gelöscht.');
    loadProfDateien(sid);
  });
});

// ── Schwimmer-Daten bearbeiten (Trainer/Schwimmwart/Admin) ────────
// Das aktuelle Profil-Objekt cachen für den Edit-Modus
var _currentProfilSwimmer=null;

$(document).on('click','#prof-edit-btn',function(e){
  e.preventDefault();
  e.stopPropagation();
  var sid=$(this).data('sid');
  ajax('lsv07i_schwimmen_get_profil',{swimmer_id:sid}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message?r.data.message:'Fehler beim Laden.','err');return;}
    _currentProfilSwimmer=r.data.swimmer;
    var s=r.data.swimmer||{};
    var kontakte=s.kontakte||[];
    var ktH='';
    for(var i=0;i<3;i++){
      var k=kontakte[i]||{name:'',telefon:'',email:''};
      ktH+='<div class="prof-kontakt-row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:6px">'
        +'<input type="text" class="i-ctl prof-kt-name" placeholder="Name" value="'+esc(k.name||'')+'" style="font-size:12px">'
        +'<input type="text" class="i-ctl prof-kt-tel" placeholder="Telefon" value="'+esc(k.telefon||'')+'" style="font-size:12px">'
        +'<input type="email" class="i-ctl prof-kt-mail" placeholder="E-Mail" value="'+esc(k.email||'')+'" style="font-size:12px">'
        +'</div>';
    }
    var html='<div style="padding:16px 18px">'
      +'<h3 style="margin:0 0 12px;font-size:15px;color:#1c1d2e">'+esc(s.first_name+' '+s.last_name)+' bearbeiten</h3>'
      +'<div style="font-size:12px;color:#6b6e85;margin-bottom:12px">Name, Geburtsdatum und Mannschaft k\u00f6nnen nur von Admins ge\u00e4ndert werden.</div>'
      +'<input type="hidden" id="prof-edit-id" value="'+s.id+'">'
      +'<label class="i-lbl">Attest g\u00fcltig bis</label>'
      +'<input type="date" id="prof-edit-attest" class="i-ctl" value="'+(s.attest_expires&&s.attest_expires!=='0000-00-00'?s.attest_expires:'')+'">'
      +'<label class="i-lbl">DSV-ID</label>'
      +'<input type="text" id="prof-edit-dsv" class="i-ctl" value="'+esc(s.dsv_id||'')+'">'
      +'<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-top:10px">'
      +'<input type="checkbox" id="prof-edit-ds" '+(parseInt(s.datenschutz)?'checked':'')+' style="width:15px;height:15px;accent-color:#5b94ff"> Datenschutz unterzeichnet</label>'
      +'<label class="i-lbl" style="margin-top:10px">Kontaktpersonen (max. 3)</label>'
      +ktH
      +'<label class="i-lbl">Notizen</label>'
      +'<textarea id="prof-edit-notes" class="i-ctl" rows="3" style="resize:vertical">'+esc(s.notes||'')+'</textarea>'
      +'<div style="display:flex;gap:8px;margin-top:14px">'
      +'<button id="prof-edit-abbr" class="i-btn i-btn-r" style="flex:1">Abbrechen</button>'
      +'<button id="prof-edit-save" class="i-btn i-btn-p" style="flex:1">Speichern</button>'
      +'</div></div>';
    $('#m-sw-profil-bd').html(html);
    $('#m-sw-profil-ttl').text('Bearbeiten: '+s.first_name+' '+s.last_name);
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

// Abbrechen → Profil neu laden
$(document).on('click','#prof-edit-abbr',function(){
  var sid=$('#prof-edit-id').val();
  if(!sid)return;
  skel($('#m-sw-profil-bd'),'lines',6);
  ajax('lsv07i_schwimmen_get_profil',{swimmer_id:sid}).done(function(r){
    if(r.success)renderSwProfil(r.data);
  });
});

// Speichern
$(document).on('click','#prof-edit-save',function(){
  var sid=$('#prof-edit-id').val();
  var kontakte=[];
  $('.prof-kontakt-row').each(function(){
    var name=$(this).find('.prof-kt-name').val().trim();
    var tel=$(this).find('.prof-kt-tel').val().trim();
    var mail=$(this).find('.prof-kt-mail').val().trim();
    if(name||tel||mail)kontakte.push({name:name,telefon:tel,email:mail});
  });
  var $b=$(this).prop('disabled',true).text('Speichern\u2026');
  ajax('lsv07i_schwimmen_update_schwimmer',{
    id:sid,
    attest_expires:$('#prof-edit-attest').val(),
    dsv_id:$('#prof-edit-dsv').val(),
    datenschutz:$('#prof-edit-ds').is(':checked')?1:0,
    notes:$('#prof-edit-notes').val(),
    kontakte_json:JSON.stringify(kontakte)
  }).done(function(r){
    if(r.success){
      toast(r.data&&r.data.message?r.data.message:'Gespeichert.');
      // Profil neu laden
      ajax('lsv07i_schwimmen_get_profil',{swimmer_id:sid}).done(function(r2){
        if(r2.success)renderSwProfil(r2.data);
      });
      // Mannschaftsliste neu rendern
      if(typeof renderMann==='function')renderMann($('#mv-filter').val());
    }else{
      toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
    }
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Speichern');});
});

/* ══ IMPORT (Excel/CSV) ══════════════════════════════════════════ */

// Beispiel-CSV Download — direkte Links mit Nonce-URL
$('#dl-sample-trainer').attr('href', LSV07I.sample_trainer_url||'#').attr('target','_blank').attr('download','');
$('#dl-sample-schwimmer').attr('href', LSV07I.sample_schwimmer_url||'#').attr('target','_blank').attr('download','');

// Trainer Import
$('#import-tr-btn').on('click',function(){
  var file=$('#import-tr-file')[0].files[0];
  if(!file){toast('Bitte eine Datei wählen.','err');return;}
  var $b=$(this).prop('disabled',true).text('Importiere\u2026');
  readFileAsCsv(file,function(csv){
    ajax('lsv07i_import_trainer',{csv_data:csv}).done(function(r){
      if(r.success){
        var msg='<div class="i-notice" style="background:rgba(22,163,74,0.15);border-left:4px solid #16a34a;padding:10px 14px;border-radius:4px"><strong>'+r.data.imported+' Trainer importiert.</strong>';
        if(r.data.errors&&r.data.errors.length)msg+='<ul style="margin:6px 0 0;font-size:12px">'+r.data.errors.map(function(e){return'<li>'+esc(e)+'</li>';}).join('')+'</ul>';
        msg+='</div>';
        $('#import-tr-result').html(msg);
        S.trainer=r.data.trainer||S.trainer;
        renderAdmTr();
      }else{
        $('#import-tr-result').html('<div class="i-notice i-notice-r">'+esc(r.data&&r.data.message?r.data.message:'Fehler')+'</div>');
      }
    }).always(function(){$b.prop('disabled',false).text('Trainer importieren');});
  });
});

// Schwimmer Import
$(document).on('click','#import-sw-btn',function(){
  var file=$('#import-sw-file')[0].files[0];
  if(!file){toast('Bitte eine Datei wählen.','err');return;}
  var $b=$(this).prop('disabled',true).text('Importiere\u2026');
  readFileAsCsv(file,function(csv){
    ajax('lsv07i_import_schwimmer',{csv_data:csv,mannschaft_id:$('#import-sw-mann').val()}).done(function(r){
      if(r.success){
        var msg='<div class="i-notice" style="background:rgba(22,163,74,0.15);border-left:4px solid #16a34a;padding:10px 14px;border-radius:4px"><strong>'+r.data.imported+' Schwimmer importiert.</strong>';
        if(r.data.errors&&r.data.errors.length)msg+='<ul style="margin:6px 0 0;font-size:12px">'+r.data.errors.map(function(e){return'<li>'+esc(e)+'</li>';}).join('')+'</ul>';
        msg+='</div>';
        $('#import-sw-result').html(msg);
        S.schwimmer=r.data.schwimmer||S.schwimmer;
        renderAdmSw($('#adm-sw-filt').val());
      }else{
        $('#import-sw-result').html('<div class="i-notice i-notice-r">'+esc(r.data&&r.data.message?r.data.message:'Fehler')+'</div>');
      }
    }).always(function(){$b.prop('disabled',false).text('Schwimmer importieren');});
  });
});

/* SheetJS erst laden, wenn wirklich eine Excel-Datei im Spiel ist —
   nicht bei jedem Seitenaufruf. Die Bibliothek liegt lokal im Plugin
   (assets/js/vendor), es geht also keine Anfrage an einen fremden
   Server raus. cb(true) bei Erfolg, cb(false) sonst. */
var _xlsxLaden=null;
function ladeXLSX(cb){
  if(typeof XLSX!=='undefined'){cb(true);return;}
  if(_xlsxLaden){_xlsxLaden.push(cb);return;}
  _xlsxLaden=[cb];
  var fertig=function(ok){
    var q=_xlsxLaden;_xlsxLaden=null;
    q.forEach(function(f){f(ok);});
  };
  var sc=document.createElement('script');
  sc.src=LSV07I.xlsx_url;
  sc.onload=function(){fertig(typeof XLSX!=='undefined');};
  sc.onerror=function(){fertig(false);};
  document.head.appendChild(sc);
}

/* Rohbytes in Text wandeln. Excel speichert CSV unter Windows meist als
   Windows-1252 — als UTF-8 gelesen werden daraus zerstörte Umlaute. Wir
   probieren daher UTF-8 streng und fallen sonst auf Windows-1252 zurück. */
function decodeCsvBytes(buffer){
  var bytes=new Uint8Array(buffer);
  if(typeof TextDecoder==='undefined')return String.fromCharCode.apply(null,bytes);
  try{
    return new TextDecoder('utf-8',{fatal:true}).decode(bytes);
  }catch(e){
    try{return new TextDecoder('windows-1252').decode(bytes);}
    catch(e2){return new TextDecoder('utf-8').decode(bytes);}
  }
}

// Datei als CSV-Text lesen (CSV direkt, Excel via SheetJS)
function readFileAsCsv(file,cb){
  var name=(file.name||'').toLowerCase();
  var reader=new FileReader();
  if(/\.(xlsx|xlsm|xlsb|xls|ods)$/.test(name)){
    reader.onload=function(e){
      var buf=e.target.result;
      ladeXLSX(function(ok){
        if(!ok){toast('Excel-Bibliothek konnte nicht geladen werden. Bitte die Datei als CSV speichern.','err');return;}
        try{
          var wb=XLSX.read(new Uint8Array(buf),{type:'array'});
          cb(XLSX.utils.sheet_to_csv(wb.Sheets[wb.SheetNames[0]],{FS:';'}));
        }catch(err){
          toast('Excel-Datei konnte nicht gelesen werden. Bitte als CSV speichern.','err');
        }
      });
    };
    reader.readAsArrayBuffer(file);
  }else{
    reader.onload=function(e){cb(decodeCsvBytes(e.target.result));};
    reader.readAsArrayBuffer(file);
  }
}

/* ══ TRIATHLON ═══════════════════════════════════════════════════ */
var S_tri={gruppen:[],slots:[],done:false};

function loadTriathlon(){
  if(S_tri.done)return;
  ajax('lsv07i_tri_get_data').done(function(r){
    if(!r||!r.success){
      // Bei Fehler 'done' NICHT setzen — so kann der Nutzer es erneut versuchen
      // (z. B. nach DB-Migration oder Berechtigungs-Fix).
      var msg=(r&&r.data&&r.data.message)||'Triathlon-Daten konnten nicht geladen werden.';
      $('#tri-mann-liste').html('<div class="i-empty" style="padding:20px;color:#b91c1c">⚠ '+esc(msg)+'<br><button class="i-btn i-btn-sm i-btn-g" style="margin-top:10px" onclick="loadTriathlon()">Erneut versuchen</button></div>');
      return;
    }
    S_tri.done=true;
    S_tri.gruppen=r.data.gruppen||[];
    S_tri.slots=r.data.slots||[];
    // Gruppen-Dropdowns befüllen
    fillSel($('#tri-mann-filter'),S_tri.gruppen,'id','name','Alle Gruppen');
    fillSel($('#tri-sess-gruppe'),S_tri.gruppen,'id','name','Alle Gruppen');
    // Admin: Gruppen-Filter in Admin-Bereich
    fillSel($('#tri-adm-gruppe-filter'),S_tri.gruppen,'id','name','Alle Gruppen');
    // Anwesenheits-Slot-Dropdown befüllen
    fillTriAnwSlots();
    renderTriMannschaften('');
    // Admin-Listen
    if(LSV07I.access&&LSV07I.access.is_admin_raw){
      renderTriGruppen();
      renderTriTrainer();
      renderTriSlots();
    }
  });
}

function fillTriAnwSlots(){
  var $s=$('#tri-anw-slot');$s.find('option:not(:first-child)').remove();
  $.each(S_tri.slots,function(i,s){
    $s.append('<option value="'+s.id+'">'+WD[s.wochentag]+' '+String(s.zeit_von||'').slice(0,5)+'-'+String(s.zeit_bis||'').slice(0,5)+' – '+esc(s.gruppe_name)+'</option>');
  });
}

// ── Mannschaftsübersicht ──────────────────────────────────────────
$('#tri-mann-filter').on('change',function(){
  renderTriMannschaften($(this).val());
});

function renderTriMannschaften(filterGruppeId){
  skel($('#tri-mann-liste'),'rows',3);
  ajax('lsv07i_tri_get_sportler',{gruppe_id:filterGruppeId}).done(function(r){
    if(!r.success){$('#tri-mann-liste').html('<span class="i-muted">Fehler.</span>');return;}
    var sportler=r.data;
    if(!sportler.length){$('#tri-mann-liste').html('<span class="i-muted">Keine Sportler gefunden.</span>');return;}
    // Gruppieren nach Gruppe
    var gruppen={};
    $.each(sportler,function(i,s){
      var gruppenNamen=s.gruppen_namen&&s.gruppen_namen.length?s.gruppen_namen:['Ohne Gruppe'];
      $.each(gruppenNamen,function(j,gn){
        if(!gruppen[gn])gruppen[gn]=[];
        if(filterGruppeId||j===0)gruppen[gn].push(s); // nur erste Gruppe wenn kein Filter
      });
    });
    var h='';
    $.each(gruppen,function(gname,members){
      h+='<div style="margin-bottom:16px">'
        +'<div style="font-size:11px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">'+esc(gname)+'</div>'
        +'<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Geburtsdatum</th><th>E-Mail</th></tr></thead><tbody>';
      $.each(members,function(i,s){
        h+='<tr>'
          +'<td data-label="Name"><strong>'+esc(s.last_name+', '+s.first_name)+'</strong></td>'
          +'<td data-label="Geburt">'+(s.birth_date&&s.birth_date!=='0000-00-00'?de(s.birth_date):'–')+'</td>'
          +'<td data-label="E-Mail">'+(s.email?'<a href="mailto:'+esc(s.email)+'" style="color:#5b94ff">'+esc(s.email)+'</a>':'–')+'</td>'
          +'</tr>';
      });
      h+='</tbody></table></div></div>';
    });
    $('#tri-mann-liste').html(h);
  });
}

// ── Anwesenheit Triathlon ─────────────────────────────────────────
var triCurAnwId=null;

$('#tri-anw-laden').on('click',function(){
  var slot=$('#tri-anw-slot').val(),datum=$('#tri-anw-datum').val();
  if(!slot||!datum){toast('Bitte Trainingszeit und Datum wählen.','err');return;}
  var $b=$(this).prop('disabled',true).text('Laden…');
  $('#tri-anw-form-card').css('display','none');
  ajax('lsv07i_tri_anw_get_or_create',{slot_id:slot,datum:datum}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message?r.data.message:'Fehler.','err');return;}
    triCurAnwId=r.data.session.id;
    renderTriAnwForm(r.data);
    $('#tri-anw-form-card').css('display','block');
    document.getElementById('tri-anw-form-card').scrollIntoView({behavior:'smooth'});
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Laden');});
});

function renderTriAnwForm(data){
  var session=data.session,sportler=data.sportler,eintraege=data.eintraege;
  var sm={};
  $.each(eintraege,function(i,e){sm[e.sportler_id]=e.status;});

  $('#tri-anw-titel').text(esc(session.gruppe_name)+' – '+de(session.training_datum));

  if(session.ausgefallen==1){
    var grund=session.ausfall_grund
      ?'<div style="font-size:12px;color:#b45309;margin-top:4px">Grund: '+esc(session.ausfall_grund)+'</div>':'';
    $('#tri-anw-eintraege').html(
      '<div class="i-notice i-notice-y" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">'
      +'<div><strong>Training ausgefallen.</strong>'+grund+'</div>'
      +'<button id="tri-anw-ausfall-rueck" class="i-btn i-btn-g i-btn-sm" data-gruppe="'+session.gruppe_id+'" data-datum="'+session.training_datum+'">Rückgängig</button>'
      +'</div>'
    );
    $('#tri-anw-save').hide();
    return;
  }

  if(!sportler.length){
    $('#tri-anw-eintraege').html('<span class="i-muted">Keine Sportler in dieser Gruppe.</span>');
    $('#tri-anw-save').hide();
    return;
  }

  var h='<div class="anw-grid">';
  $.each(sportler,function(i,s){
    h+=anwTile('tri_sportler',s.id,s.last_name+', '+s.first_name,sm[s.id]||'abwesend');
  });
  h+='</div>';
  $('#tri-anw-eintraege').html(h);
  $('#tri-anw-save').show();
}

$('#tri-anw-save').on('click',function(){
  if(!triCurAnwId)return;
  var $b=$(this).prop('disabled',true).text('Speichern…');
  var reqs=[];
  $('#tri-anw-eintraege .anw-tile').each(function(){
    var $aktiv=$(this).find('.i-ab-v2.aktiv');
    if(!$aktiv.length)return;
    reqs.push(ajax('lsv07i_tri_anw_save_eintrag',{anwesenheit_id:triCurAnwId,sportler_id:$aktiv.data('id'),status:$aktiv.data('st')}));
  });
  if(!reqs.length){toast('Keine Einträge.','err');$b.prop('disabled',false).text('Alle speichern');return;}
  $.when.apply($,reqs).done(function(){
    toast('Anwesenheit gespeichert.');
    // Trainer-Abrechnung automatisch befüllen (falls Nutzer Tri-Trainer ist)
    ajax('lsv07i_tri_anw_trainer_abr',{anwesenheit_id:triCurAnwId});
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Alle speichern');});
});;

$('#tri-anw-ausfall').on('click',function(){
  var slot=$('#tri-anw-slot').val(),datum=$('#tri-anw-datum').val();
  if(!slot||!datum){toast('Bitte Trainingszeit und Datum wählen.','err');return;}
  var g=prompt('Grund (optional):')||'';
  ajax('lsv07i_tri_anw_mark_ausfall',{slot_id:slot,datum:datum,ausgefallen:1,grund:g}).done(function(r){
    if(r.success){toast('Ausgefallen markiert.');$('#tri-anw-laden').trigger('click');}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

$(document).on('click','#tri-anw-ausfall-rueck',function(){
  var slot=$(this).data('slot'),datum=$(this).data('datum');
  if(!confirm('Ausfall-Markierung aufheben?'))return;
  ajax('lsv07i_tri_anw_mark_ausfall',{slot_id:slot,datum:datum,ausgefallen:0}).done(function(r){
    if(r.success){toast('Aufgehoben.');$('#tri-anw-laden').trigger('click');}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

// Vergangene Sessions
$('#tri-sess-suchen').on('click',function(){
  skel($('#tri-sess-liste'),'rows',4);
  ajax('lsv07i_tri_anw_get_sessions',{gruppe_id:$('#tri-sess-gruppe').val(),monat:$('#tri-sess-monat').val()}).done(function(r){
    if(!r.success||!r.data.length){$('#tri-sess-liste').html('<span class="i-muted">Keine Sessions.</span>');return;}
    var h='';
    $.each(r.data,function(i,s){
      var af=s.ausgefallen==1?' '+bdg('r','Ausgefallen'):'';
      h+='<div class="i-si tri-sess-row" data-gruppe="'+s.gruppe_id+'" data-datum="'+s.training_datum+'" style="cursor:pointer">'
        +'<div style="font-weight:600;font-size:13px">'+de(s.training_datum)+' – '+esc(s.gruppe_name)+af+'</div>'
        +'</div>';
    });
    $('#tri-sess-liste').html(h);
  });
});

$(document).on('click','.tri-sess-row',function(){
  var gruppe=$(this).data('gruppe'),datum=$(this).data('datum');
  // Tab wechseln und Session laden
  $('[data-panel="i-p-tri-anw"]').trigger('click');
  $('#tri-anw-slot').val(gruppe);$('#tri-anw-datum').val(datum); // gruppe=slot_id here
  $('#tri-anw-laden').trigger('click');
});

// ── Admin: Tri-Gruppen ────────────────────────────────────────────
function renderTriGruppen(){
  if(!S_tri.gruppen.length){$('#tri-gruppen-liste').html('<span class="i-muted">Keine Gruppen.</span>');return;}
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th style="text-align:center">Sportler</th><th>Reihenfolge</th><th></th></tr></thead><tbody>';
  $.each(S_tri.gruppen,function(i,g){
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(g.name)+'</strong></td>'
      +'<td data-label="Sportler" style="text-align:center">'+bdg('b',g.sportler_anzahl||0)+'</td>'
      +'<td data-label="Reihenfolge">'+g.sort_order+'</td>'
      +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm tri-g-ed" data-id="'+g.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm tri-g-dl" data-id="'+g.id+'">Löschen</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#tri-gruppen-liste').html(h);
}

$('#tri-gruppe-neu').on('click',function(){
  $('#tri-g-id').val('');$('#tri-g-name').val('');$('#tri-g-order').val('0');
  $('#m-tri-gruppe-ttl').text('Neue Gruppe');$('#m-tri-gruppe').addClass('open');
});
$(document).on('click','.tri-g-ed',function(){
  var id=$(this).data('id'),g=S_tri.gruppen.find(function(x){return x.id==id;});
  if(!g)return;
  $('#tri-g-id').val(g.id);$('#tri-g-name').val(g.name);$('#tri-g-order').val(g.sort_order);
  $('#m-tri-gruppe-ttl').text('Gruppe bearbeiten');$('#m-tri-gruppe').addClass('open');
});
$('#tri-g-save').on('click',function(){
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_tri_save_gruppe',{id:$('#tri-g-id').val(),name:$('#tri-g-name').val(),sort_order:$('#tri-g-order').val()}).done(function(r){
    if(r.success){toast('Gruppe gespeichert.');$('#m-tri-gruppe').removeClass('open');S_tri.done=false;S.adminDone=false;loadTriathlon();loadAdmin();}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.tri-g-dl',function(){
  if(!confirm('Gruppe löschen? Sportlerzuordnungen werden entfernt.'))return;
  ajax('lsv07i_tri_delete_gruppe',{id:$(this).data('id')}).done(function(r){
    if(r.success){toast('Gruppe gelöscht.');S_tri.done=false;S.adminDone=false;loadTriathlon();loadAdmin();}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

// ── Admin: Tri-Sportler ───────────────────────────────────────────
function triGruppenCbs(containerId,selectedIds){
  selectedIds=selectedIds||[];
  var h='';
  if(!S_tri.gruppen.length){h='<span class="i-muted" style="font-size:12px">Keine Gruppen.</span>';}
  else{$.each(S_tri.gruppen,function(i,g){
    var checked=selectedIds.map(String).indexOf(String(g.id))>=0;
    h+='<label style="display:flex;align-items:center;gap:8px;padding:4px 2px;cursor:pointer;font-size:13px">'
      +'<input type="checkbox" class="tri-g-cb" value="'+g.id+'"'+(checked?' checked':'')
      +' style="width:14px;height:14px;accent-color:#5b94ff"> '+esc(g.name)+'</label>';
  });}
  $('#'+containerId).html(h);
}

function loadTriAdmSportler(){
  var gid=$('#tri-adm-gruppe-filter').val();
  ajax('lsv07i_tri_get_sportler',{gruppe_id:gid}).done(function(r){
    if(!r.success){$('#tri-adm-sportler-liste').html('<span class="i-muted">Fehler.</span>');return;}
    var sportler=r.data;
    if(!sportler.length){$('#tri-adm-sportler-liste').html('<span class="i-muted">Keine Sportler.</span>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Geburt</th><th>Gruppen</th><th>E-Mail</th><th></th></tr></thead><tbody>';
    $.each(sportler,function(i,s){
      var gruppen=s.gruppen_namen&&s.gruppen_namen.length?s.gruppen_namen.map(function(g){return'<span class="i-bdg i-bdg-b">'+esc(g)+'</span>';}).join(' '):'–';
      h+='<tr>'
        +'<td data-label="Name"><strong>'+esc(s.last_name+', '+s.first_name)+'</strong></td>'
        +'<td data-label="Geburt">'+(s.birth_date&&s.birth_date!=='0000-00-00'?de(s.birth_date):'–')+'</td>'
        +'<td data-label="Gruppen">'+gruppen+'</td>'
        +'<td data-label="E-Mail">'+(s.email?'<a href="mailto:'+esc(s.email)+'" style="color:#5b94ff">'+esc(s.email)+'</a>':'–')+'</td>'
        +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm tri-s-ed" data-id="'+s.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm tri-s-dl" data-id="'+s.id+'">Löschen</button></td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#tri-adm-sportler-liste').html(h);
  });
}

$('#tri-adm-gruppe-filter,#tri-adm-sportler-laden').on('change click',function(){loadTriAdmSportler();});
$('#tri-sportler-neu').on('click',function(){
  $('#tri-s-id').val('');$('#tri-s-first,#tri-s-last,#tri-s-birth,#tri-s-email,#tri-s-notes').val('');
  triGruppenCbs('tri-s-gruppen-cb',[]);
  $('#m-tri-sportler-ttl').text('Neuer Sportler');$('#m-tri-sportler').addClass('open');
});
$(document).on('click','.tri-s-ed',function(){
  var id=$(this).data('id');
  ajax('lsv07i_tri_get_sportler',{gruppe_id:''}).done(function(r){
    if(!r.success)return;
    var s=r.data.find(function(x){return x.id==id;});
    if(!s)return;
    $('#tri-s-id').val(s.id);$('#tri-s-first').val(s.first_name);$('#tri-s-last').val(s.last_name);
    $('#tri-s-birth').val(s.birth_date&&s.birth_date!=='0000-00-00'?s.birth_date:'');
    $('#tri-s-email').val(s.email);$('#tri-s-notes').val(s.notes||'');
    triGruppenCbs('tri-s-gruppen-cb',s.gruppe_ids||[]);
    $('#m-tri-sportler-ttl').text('Sportler bearbeiten');$('#m-tri-sportler').addClass('open');
  });
});
$('#tri-s-save').on('click',function(){
  var gids=[];$('#tri-s-gruppen-cb .tri-g-cb:checked').each(function(){gids.push($(this).val());});
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_tri_save_sportler',{id:$('#tri-s-id').val(),first_name:$('#tri-s-first').val(),last_name:$('#tri-s-last').val(),birth_date:$('#tri-s-birth').val(),email:$('#tri-s-email').val(),notes:$('#tri-s-notes').val(),'gruppe_ids[]':gids}).done(function(r){
    if(r.success){toast('Sportler gespeichert.');$('#m-tri-sportler').removeClass('open');loadTriAdmSportler();if(S_tri.done){S_tri.done=false;loadTriathlon();}}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.tri-s-dl',function(){
  if(!confirm('Sportler wirklich löschen?'))return;
  ajax('lsv07i_tri_delete_sportler',{id:$(this).data('id')}).done(function(r){
    if(r.success){toast('Gelöscht.');loadTriAdmSportler();if(S_tri.done){S_tri.done=false;loadTriathlon();}}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

// ── Admin: Tri-Trainer ────────────────────────────────────────────
function renderTriTrainer(){
  ajax('lsv07i_tri_get_trainer').done(function(r){
    if(!r.success||!r.data.length){$('#tri-trainer-liste').html('<span class="i-muted">Keine Trainer.</span>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>E-Mail</th><th>Gruppen</th><th></th></tr></thead><tbody>';
    $.each(r.data,function(i,t){
      var gids=t.gruppe_ids?t.gruppe_ids.split(','):[];
      var gNamen=gids.map(function(gid){var g=S_tri.gruppen.find(function(x){return x.id==gid||String(x.id)===gid;});return g?esc(g.name):'?';}).join(', ')||'–';
      h+='<tr>'
        +'<td data-label="Name"><strong>'+esc(t.name)+'</strong></td>'
        +'<td data-label="E-Mail">'+(t.email?'<a href="mailto:'+esc(t.email)+'" style="color:#5b94ff">'+esc(t.email)+'</a>':'–')+'</td>'
        +'<td data-label="Gruppen">'+gNamen+'</td>'
        +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm tri-t-ed" data-id="'+t.id+'" data-obj="'+encodeURIComponent(JSON.stringify(t))+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm tri-t-dl" data-id="'+t.id+'">Löschen</button></td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#tri-trainer-liste').html(h);
  });
}

$('#tri-trainer-neu').on('click',function(){
  $('#tri-t-id').val('');$('#tri-t-name,#tri-t-email').val('');$('#tri-t-user').val('');
  triGruppenCbs('tri-t-gruppen-cb',[]);
  $('#m-tri-trainer-ttl').text('Neuer Trainer');$('#m-tri-trainer').addClass('open');
  loadTriTrainerUsers();
});
$(document).on('click','.tri-t-ed',function(){
  var t=JSON.parse(decodeURIComponent($(this).data('obj')));
  $('#tri-t-id').val(t.id);$('#tri-t-name').val(t.name);$('#tri-t-email').val(t.email);
  $('#tri-t-user').val(t.wp_user_id||'');
  var gids=t.gruppe_ids?t.gruppe_ids.split(','):[];
  triGruppenCbs('tri-t-gruppen-cb',gids);
  $('#m-tri-trainer-ttl').text('Trainer bearbeiten');$('#m-tri-trainer').addClass('open');
  loadTriTrainerUsers();
});
function loadTriTrainerUsers(){
  if($('#tri-t-user option').length>1)return;
  ajax('lsv07i_admin_get_wp_users').done(function(r){
    if(!r.success)return;
    var $s=$('#tri-t-user');$s.find('option:not(:first-child)').remove();
    $.each(r.data,function(i,u){$s.append('<option value="'+u.id+'">'+esc(u.name+' ('+u.email+')')+'</option>');});
  });
}
$('#tri-t-save').on('click',function(){
  var gids=[];$('#tri-t-gruppen-cb .tri-g-cb:checked').each(function(){gids.push($(this).val());});
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_tri_save_trainer',{id:$('#tri-t-id').val(),name:$('#tri-t-name').val(),email:$('#tri-t-email').val(),wp_user_id:$('#tri-t-user').val(),'gruppe_ids[]':gids}).done(function(r){
    if(r.success){toast('Trainer gespeichert.');$('#m-tri-trainer').removeClass('open');renderTriTrainer();}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.tri-t-dl',function(){
  if(!confirm('Trainer löschen?'))return;
  ajax('lsv07i_tri_delete_trainer',{id:$(this).data('id')}).done(function(r){
    if(r.success){toast('Gelöscht.');renderTriTrainer();}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

// ── Admin: Tri-Trainingszeiten ────────────────────────────────────
function renderTriSlots(){
  var h='';
  if(!S_tri.slots||!S_tri.slots.length){$('#tri-sl-liste').html('<span class="i-muted">Keine Trainingszeiten.</span>');return;}
  h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Gruppe</th><th>Wochentag</th><th>Von</th><th>Bis</th><th></th></tr></thead><tbody>';
  $.each(S_tri.slots,function(i,s){
    h+='<tr>'
      +'<td data-label="Gruppe"><strong>'+esc(s.gruppe_name)+'</strong></td>'
      +'<td data-label="Tag">'+WD[s.wochentag]+'</td>'
      +'<td data-label="Von">'+String(s.zeit_von||'').slice(0,5)+'</td>'
      +'<td data-label="Bis">'+String(s.zeit_bis||'').slice(0,5)+'</td>'
      +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm tri-sl-ed" data-id="'+s.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm tri-sl-dl" data-id="'+s.id+'">Löschen</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#tri-sl-liste').html(h);
}

$('#tri-slot-neu').on('click',function(){
  $('#tri-sl-id').val('');$('#tri-sl-von,#tri-sl-bis').val('');$('#tri-sl-wt').val('1');
  fillSel($('#tri-sl-gruppe'),S_tri.gruppen,'id','name','Gruppe wählen\u2026');
  $('#m-tri-slot-ttl').text('Neue Trainingszeit');$('#m-tri-slot').addClass('open');
});

$(document).on('click','.tri-sl-ed',function(){
  var id=$(this).data('id'),s=S_tri.slots.find(function(x){return x.id==id;});
  if(!s)return;
  $('#tri-sl-id').val(s.id);$('#tri-sl-wt').val(s.wochentag);
  $('#tri-sl-von').val(String(s.zeit_von||'').slice(0,5));
  $('#tri-sl-bis').val(String(s.zeit_bis||'').slice(0,5));
  fillSel($('#tri-sl-gruppe'),S_tri.gruppen,'id','name','Gruppe wählen\u2026');
  $('#tri-sl-gruppe').val(s.gruppe_id);
  $('#m-tri-slot-ttl').text('Trainingszeit bearbeiten');$('#m-tri-slot').addClass('open');
});

$('#tri-sl-save').on('click',function(){
  var gruppe=$('#tri-sl-gruppe').val();
  if(!gruppe){toast('Bitte Gruppe wählen.','err');return;}
  var $b=$(this).prop('disabled',true).text('\u2026');
  ajax('lsv07i_tri_save_slot',{id:$('#tri-sl-id').val(),gruppe_id:gruppe,wochentag:$('#tri-sl-wt').val(),zeit_von:$('#tri-sl-von').val(),zeit_bis:$('#tri-sl-bis').val()}).done(function(r){
    if(r.success){
      S_tri.slots=r.data.slots||[];
      toast('Trainingszeit gespeichert.');$('#m-tri-slot').removeClass('open');
      renderTriSlots();fillTriAnwSlots();
    }else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});

$(document).on('click','.tri-sl-dl',function(){
  if(!confirm('Trainingszeit löschen?'))return;
  ajax('lsv07i_tri_delete_slot',{id:$(this).data('id')}).done(function(r){
    if(r.success){
      S_tri.slots=r.data.slots||[];
      toast('Gel\u00f6scht.');renderTriSlots();fillTriAnwSlots();
    }else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});



/* ══ FITNESS ═══════════════════════════════════════════════════ */
var S_fit={gruppen:[],slots:[],done:false};

function loadFitness(){
  if(S_fit.done)return;
  S_fit.done=true;
  ajax('lsv07i_fit_get_data').done(function(r){
    if(!r.success)return;
    S_fit.gruppen=r.data.gruppen||[];
    S_fit.slots=r.data.slots||[];
    // Gruppen-Dropdowns befüllen
    fillSel($('#fit-mann-filter'),S_fit.gruppen,'id','name','Alle Gruppen');
    fillSel($('#fit-sess-gruppe'),S_fit.gruppen,'id','name','Alle Gruppen');
    // Admin: Gruppen-Filter in Admin-Bereich
    fillSel($('#fit-adm-gruppe-filter'),S_fit.gruppen,'id','name','Alle Gruppen');
    // Anwesenheits-Slot-Dropdown befüllen
    fillFitAnwSlots();
    renderFitMannschaften('');
    // Admin-Listen
    if(LSV07I.access&&LSV07I.access.is_admin_raw){
      renderFitGruppen();
      renderFitTrainer();
      renderFitSlots();
    }
  });
}

function fillFitAnwSlots(){
  var $s=$('#fit-anw-slot');$s.find('option:not(:first-child)').remove();
  $.each(S_fit.slots,function(i,s){
    $s.append('<option value="'+s.id+'">'+WD[s.wochentag]+' '+String(s.zeit_von||'').slice(0,5)+'-'+String(s.zeit_bis||'').slice(0,5)+' – '+esc(s.gruppe_name)+'</option>');
  });
}

// ── Mannschaftsübersicht ──────────────────────────────────────────
$('#fit-mann-filter').on('change',function(){
  renderFitMannschaften($(this).val());
});

function renderFitMannschaften(filterGruppeId){
  skel($('#fit-mann-liste'),'rows',3);
  ajax('lsv07i_fit_get_sportler',{gruppe_id:filterGruppeId}).done(function(r){
    if(!r.success){$('#fit-mann-liste').html('<span class="i-muted">Fehler.</span>');return;}
    var sportler=r.data;
    if(!sportler.length){$('#fit-mann-liste').html('<span class="i-muted">Keine Sportler gefunden.</span>');return;}
    // Gruppieren nach Gruppe
    var gruppen={};
    $.each(sportler,function(i,s){
      var gruppenNamen=s.gruppen_namen&&s.gruppen_namen.length?s.gruppen_namen:['Ohne Gruppe'];
      $.each(gruppenNamen,function(j,gn){
        if(!gruppen[gn])gruppen[gn]=[];
        if(filterGruppeId||j===0)gruppen[gn].push(s); // nur erste Gruppe wenn kein Filter
      });
    });
    var h='';
    $.each(gruppen,function(gname,members){
      h+='<div style="margin-bottom:16px">'
        +'<div style="font-size:11px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">'+esc(gname)+'</div>'
        +'<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Geburtsdatum</th><th>E-Mail</th></tr></thead><tbody>';
      $.each(members,function(i,s){
        h+='<tr>'
          +'<td data-label="Name"><strong>'+esc(s.last_name+', '+s.first_name)+'</strong></td>'
          +'<td data-label="Geburt">'+(s.birth_date&&s.birth_date!=='0000-00-00'?de(s.birth_date):'–')+'</td>'
          +'<td data-label="E-Mail">'+(s.email?'<a href="mailto:'+esc(s.email)+'" style="color:#5b94ff">'+esc(s.email)+'</a>':'–')+'</td>'
          +'</tr>';
      });
      h+='</tbody></table></div></div>';
    });
    $('#fit-mann-liste').html(h);
  });
}

// ── Anwesenheit Fitness ─────────────────────────────────────────
var fitCurAnwId=null;

$('#fit-anw-laden').on('click',function(){
  var slot=$('#fit-anw-slot').val(),datum=$('#fit-anw-datum').val();
  if(!slot||!datum){toast('Bitte Trainingszeit und Datum wählen.','err');return;}
  var $b=$(this).prop('disabled',true).text('Laden…');
  $('#fit-anw-form-card').css('display','none');
  ajax('lsv07i_fit_anw_get_or_create',{slot_id:slot,datum:datum}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message?r.data.message:'Fehler.','err');return;}
    fitCurAnwId=r.data.session.id;
    renderFitAnwForm(r.data);
    $('#fit-anw-form-card').css('display','block');
    document.getElementById('fit-anw-form-card').scrollIntoView({behavior:'smooth'});
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Laden');});
});

function renderFitAnwForm(data){
  var session=data.session,sportler=data.sportler,eintraege=data.eintraege;
  var sm={};
  $.each(eintraege,function(i,e){sm[e.sportler_id]=e.status;});

  $('#fit-anw-titel').text(esc(session.gruppe_name)+' – '+de(session.training_datum));

  if(session.ausgefallen==1){
    var grund=session.ausfall_grund
      ?'<div style="font-size:12px;color:#b45309;margin-top:4px">Grund: '+esc(session.ausfall_grund)+'</div>':'';
    $('#fit-anw-eintraege').html(
      '<div class="i-notice i-notice-y" style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">'
      +'<div><strong>Training ausgefallen.</strong>'+grund+'</div>'
      +'<button id="fit-anw-ausfall-rueck" class="i-btn i-btn-g i-btn-sm" data-gruppe="'+session.gruppe_id+'" data-datum="'+session.training_datum+'">Rückgängig</button>'
      +'</div>'
    );
    $('#fit-anw-save').hide();
    return;
  }

  if(!sportler.length){
    $('#fit-anw-eintraege').html('<span class="i-muted">Keine Sportler in dieser Gruppe.</span>');
    $('#fit-anw-save').hide();
    return;
  }

  var h='<div class="anw-grid">';
  $.each(sportler,function(i,s){
    h+=anwTile('fit_sportler',s.id,s.last_name+', '+s.first_name,sm[s.id]||'abwesend');
  });
  h+='</div>';
  $('#fit-anw-eintraege').html(h);
  $('#fit-anw-save').show();
}

$('#fit-anw-save').on('click',function(){
  if(!fitCurAnwId)return;
  var $b=$(this).prop('disabled',true).text('Speichern…');
  var reqs=[];
  $('#fit-anw-eintraege .anw-tile').each(function(){
    var $aktiv=$(this).find('.i-ab-v2.aktiv');
    if(!$aktiv.length)return;
    reqs.push(ajax('lsv07i_fit_anw_save_eintrag',{anwesenheit_id:fitCurAnwId,sportler_id:$aktiv.data('id'),status:$aktiv.data('st')}));
  });
  if(!reqs.length){toast('Keine Einträge.','err');$b.prop('disabled',false).text('Alle speichern');return;}
  $.when.apply($,reqs).done(function(){
    toast('Anwesenheit gespeichert.');
    // Trainer-Abrechnung automatisch befüllen (falls Nutzer Tri-Trainer ist)
    ajax('lsv07i_fit_anw_trainer_abr',{anwesenheit_id:fitCurAnwId});
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){$b.prop('disabled',false).text('Alle speichern');});
});;

$('#fit-anw-ausfall').on('click',function(){
  var slot=$('#fit-anw-slot').val(),datum=$('#fit-anw-datum').val();
  if(!slot||!datum){toast('Bitte Trainingszeit und Datum wählen.','err');return;}
  var g=prompt('Grund (optional):')||'';
  ajax('lsv07i_fit_anw_mark_ausfall',{slot_id:slot,datum:datum,ausgefallen:1,grund:g}).done(function(r){
    if(r.success){toast('Ausgefallen markiert.');$('#fit-anw-laden').trigger('click');}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

$(document).on('click','#fit-anw-ausfall-rueck',function(){
  var slot=$(this).data('slot'),datum=$(this).data('datum');
  if(!confirm('Ausfall-Markierung aufheben?'))return;
  ajax('lsv07i_fit_anw_mark_ausfall',{slot_id:slot,datum:datum,ausgefallen:0}).done(function(r){
    if(r.success){toast('Aufgehoben.');$('#fit-anw-laden').trigger('click');}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

// Vergangene Sessions
$('#fit-sess-suchen').on('click',function(){
  skel($('#fit-sess-liste'),'rows',4);
  ajax('lsv07i_fit_anw_get_sessions',{gruppe_id:$('#fit-sess-gruppe').val(),monat:$('#fit-sess-monat').val()}).done(function(r){
    if(!r.success||!r.data.length){$('#fit-sess-liste').html('<span class="i-muted">Keine Sessions.</span>');return;}
    var h='';
    $.each(r.data,function(i,s){
      var af=s.ausgefallen==1?' '+bdg('r','Ausgefallen'):'';
      h+='<div class="i-si fit-sess-row" data-gruppe="'+s.gruppe_id+'" data-datum="'+s.training_datum+'" style="cursor:pointer">'
        +'<div style="font-weight:600;font-size:13px">'+de(s.training_datum)+' – '+esc(s.gruppe_name)+af+'</div>'
        +'</div>';
    });
    $('#fit-sess-liste').html(h);
  });
});

$(document).on('click','.fit-sess-row',function(){
  var gruppe=$(this).data('gruppe'),datum=$(this).data('datum');
  // Tab wechseln und Session laden
  $('[data-panel="i-p-fit-anw"]').trigger('click');
  $('#fit-anw-slot').val(gruppe);$('#fit-anw-datum').val(datum); // gruppe=slot_id here
  $('#fit-anw-laden').trigger('click');
});

// ── Admin: Tri-Gruppen ────────────────────────────────────────────
function renderFitGruppen(){
  if(!S_fit.gruppen.length){$('#fit-gruppen-liste').html('<span class="i-muted">Keine Gruppen.</span>');return;}
  var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th style="text-align:center">Sportler</th><th>Reihenfolge</th><th></th></tr></thead><tbody>';
  $.each(S_fit.gruppen,function(i,g){
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(g.name)+'</strong></td>'
      +'<td data-label="Sportler" style="text-align:center">'+bdg('b',g.sportler_anzahl||0)+'</td>'
      +'<td data-label="Reihenfolge">'+g.sort_order+'</td>'
      +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm fit-g-ed" data-id="'+g.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm fit-g-dl" data-id="'+g.id+'">Löschen</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#fit-gruppen-liste').html(h);
}

$('#fit-gruppe-neu').on('click',function(){
  $('#fit-g-id').val('');$('#fit-g-name').val('');$('#fit-g-order').val('0');
  $('#m-fit-gruppe-ttl').text('Neue Gruppe');$('#m-fit-gruppe').addClass('open');
});
$(document).on('click','.fit-g-ed',function(){
  var id=$(this).data('id'),g=S_fit.gruppen.find(function(x){return x.id==id;});
  if(!g)return;
  $('#fit-g-id').val(g.id);$('#fit-g-name').val(g.name);$('#fit-g-order').val(g.sort_order);
  $('#m-fit-gruppe-ttl').text('Gruppe bearbeiten');$('#m-fit-gruppe').addClass('open');
});
$('#fit-g-save').on('click',function(){
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_fit_save_gruppe',{id:$('#fit-g-id').val(),name:$('#fit-g-name').val(),sort_order:$('#fit-g-order').val()}).done(function(r){
    if(r.success){toast('Gruppe gespeichert.');$('#m-fit-gruppe').removeClass('open');S_fit.done=false;S.adminDone=false;loadFitness();loadAdmin();}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.fit-g-dl',function(){
  if(!confirm('Gruppe löschen? Sportlerzuordnungen werden entfernt.'))return;
  ajax('lsv07i_fit_delete_gruppe',{id:$(this).data('id')}).done(function(r){
    if(r.success){toast('Gruppe gelöscht.');S_fit.done=false;S.adminDone=false;loadFitness();loadAdmin();}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

// ── Admin: Tri-Sportler ───────────────────────────────────────────
function fitGruppenCbs(containerId,selectedIds){
  selectedIds=selectedIds||[];
  var h='';
  if(!S_fit.gruppen.length){h='<span class="i-muted" style="font-size:12px">Keine Gruppen.</span>';}
  else{$.each(S_fit.gruppen,function(i,g){
    var checked=selectedIds.map(String).indexOf(String(g.id))>=0;
    h+='<label style="display:flex;align-items:center;gap:8px;padding:4px 2px;cursor:pointer;font-size:13px">'
      +'<input type="checkbox" class="fit-g-cb" value="'+g.id+'"'+(checked?' checked':'')
      +' style="width:14px;height:14px;accent-color:#5b94ff"> '+esc(g.name)+'</label>';
  });}
  $('#'+containerId).html(h);
}

function loadFitAdmSportler(){
  var gid=$('#fit-adm-gruppe-filter').val();
  ajax('lsv07i_fit_get_sportler',{gruppe_id:gid}).done(function(r){
    if(!r.success){$('#fit-adm-sportler-liste').html('<span class="i-muted">Fehler.</span>');return;}
    var sportler=r.data;
    if(!sportler.length){$('#fit-adm-sportler-liste').html('<span class="i-muted">Keine Sportler.</span>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>Geburt</th><th>Gruppen</th><th>E-Mail</th><th></th></tr></thead><tbody>';
    $.each(sportler,function(i,s){
      var gruppen=s.gruppen_namen&&s.gruppen_namen.length?s.gruppen_namen.map(function(g){return'<span class="i-bdg i-bdg-b">'+esc(g)+'</span>';}).join(' '):'–';
      h+='<tr>'
        +'<td data-label="Name"><strong>'+esc(s.last_name+', '+s.first_name)+'</strong></td>'
        +'<td data-label="Geburt">'+(s.birth_date&&s.birth_date!=='0000-00-00'?de(s.birth_date):'–')+'</td>'
        +'<td data-label="Gruppen">'+gruppen+'</td>'
        +'<td data-label="E-Mail">'+(s.email?'<a href="mailto:'+esc(s.email)+'" style="color:#5b94ff">'+esc(s.email)+'</a>':'–')+'</td>'
        +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm fit-s-ed" data-id="'+s.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm fit-s-dl" data-id="'+s.id+'">Löschen</button></td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#fit-adm-sportler-liste').html(h);
  });
}

$('#fit-adm-gruppe-filter,#fit-adm-sportler-laden').on('change click',function(){loadFitAdmSportler();});
$('#fit-sportler-neu').on('click',function(){
  $('#fit-s-id').val('');$('#fit-s-first,#fit-s-last,#fit-s-birth,#fit-s-email,#fit-s-notes').val('');
  fitGruppenCbs('fit-s-gruppen-cb',[]);
  $('#m-fit-sportler-ttl').text('Neuer Sportler');$('#m-fit-sportler').addClass('open');
});
$(document).on('click','.fit-s-ed',function(){
  var id=$(this).data('id');
  ajax('lsv07i_fit_get_sportler',{gruppe_id:''}).done(function(r){
    if(!r.success)return;
    var s=r.data.find(function(x){return x.id==id;});
    if(!s)return;
    $('#fit-s-id').val(s.id);$('#fit-s-first').val(s.first_name);$('#fit-s-last').val(s.last_name);
    $('#fit-s-birth').val(s.birth_date&&s.birth_date!=='0000-00-00'?s.birth_date:'');
    $('#fit-s-email').val(s.email);$('#fit-s-notes').val(s.notes||'');
    fitGruppenCbs('fit-s-gruppen-cb',s.gruppe_ids||[]);
    $('#m-fit-sportler-ttl').text('Sportler bearbeiten');$('#m-fit-sportler').addClass('open');
  });
});
$('#fit-s-save').on('click',function(){
  var gids=[];$('#fit-s-gruppen-cb .fit-g-cb:checked').each(function(){gids.push($(this).val());});
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_fit_save_sportler',{id:$('#fit-s-id').val(),first_name:$('#fit-s-first').val(),last_name:$('#fit-s-last').val(),birth_date:$('#fit-s-birth').val(),email:$('#fit-s-email').val(),notes:$('#fit-s-notes').val(),'gruppe_ids[]':gids}).done(function(r){
    if(r.success){toast('Sportler gespeichert.');$('#m-fit-sportler').removeClass('open');loadFitAdmSportler();if(S_fit.done){S_fit.done=false;loadFitness();}}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.fit-s-dl',function(){
  if(!confirm('Sportler wirklich löschen?'))return;
  ajax('lsv07i_fit_delete_sportler',{id:$(this).data('id')}).done(function(r){
    if(r.success){toast('Gelöscht.');loadFitAdmSportler();if(S_fit.done){S_fit.done=false;loadFitness();}}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

// ── Admin: Tri-Trainer ────────────────────────────────────────────
function renderFitTrainer(){
  ajax('lsv07i_fit_get_trainer').done(function(r){
    if(!r.success||!r.data.length){$('#fit-trainer-liste').html('<span class="i-muted">Keine Trainer.</span>');return;}
    var h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Name</th><th>E-Mail</th><th>Gruppen</th><th></th></tr></thead><tbody>';
    $.each(r.data,function(i,t){
      var gids=t.gruppe_ids?t.gruppe_ids.split(','):[];
      var gNamen=gids.map(function(gid){var g=S_fit.gruppen.find(function(x){return x.id==gid||String(x.id)===gid;});return g?esc(g.name):'?';}).join(', ')||'–';
      h+='<tr>'
        +'<td data-label="Name"><strong>'+esc(t.name)+'</strong></td>'
        +'<td data-label="E-Mail">'+(t.email?'<a href="mailto:'+esc(t.email)+'" style="color:#5b94ff">'+esc(t.email)+'</a>':'–')+'</td>'
        +'<td data-label="Gruppen">'+gNamen+'</td>'
        +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm fit-t-ed" data-id="'+t.id+'" data-obj="'+encodeURIComponent(JSON.stringify(t))+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm fit-t-dl" data-id="'+t.id+'">Löschen</button></td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#fit-trainer-liste').html(h);
  });
}

$('#fit-trainer-neu').on('click',function(){
  $('#fit-t-id').val('');$('#fit-t-name,#fit-t-email').val('');$('#fit-t-user').val('');
  fitGruppenCbs('fit-t-gruppen-cb',[]);
  $('#m-fit-trainer-ttl').text('Neuer Trainer');$('#m-fit-trainer').addClass('open');
  loadFitTrainerUsers();
});
$(document).on('click','.fit-t-ed',function(){
  var t=JSON.parse(decodeURIComponent($(this).data('obj')));
  $('#fit-t-id').val(t.id);$('#fit-t-name').val(t.name);$('#fit-t-email').val(t.email);
  $('#fit-t-user').val(t.wp_user_id||'');
  var gids=t.gruppe_ids?t.gruppe_ids.split(','):[];
  fitGruppenCbs('fit-t-gruppen-cb',gids);
  $('#m-fit-trainer-ttl').text('Trainer bearbeiten');$('#m-fit-trainer').addClass('open');
  loadFitTrainerUsers();
});
function loadFitTrainerUsers(){
  if($('#fit-t-user option').length>1)return;
  ajax('lsv07i_admin_get_wp_users').done(function(r){
    if(!r.success)return;
    var $s=$('#fit-t-user');$s.find('option:not(:first-child)').remove();
    $.each(r.data,function(i,u){$s.append('<option value="'+u.id+'">'+esc(u.name+' ('+u.email+')')+'</option>');});
  });
}
$('#fit-t-save').on('click',function(){
  var gids=[];$('#fit-t-gruppen-cb .fit-g-cb:checked').each(function(){gids.push($(this).val());});
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_fit_save_trainer',{id:$('#fit-t-id').val(),name:$('#fit-t-name').val(),email:$('#fit-t-email').val(),wp_user_id:$('#fit-t-user').val(),'gruppe_ids[]':gids}).done(function(r){
    if(r.success){toast('Trainer gespeichert.');$('#m-fit-trainer').removeClass('open');renderFitTrainer();}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});
$(document).on('click','.fit-t-dl',function(){
  if(!confirm('Trainer löschen?'))return;
  ajax('lsv07i_fit_delete_trainer',{id:$(this).data('id')}).done(function(r){
    if(r.success){toast('Gelöscht.');renderFitTrainer();}
    else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});

// ── Admin: Tri-Trainingszeiten ────────────────────────────────────
function renderFitSlots(){
  var h='';
  if(!S_fit.slots||!S_fit.slots.length){$('#fit-sl-liste').html('<span class="i-muted">Keine Trainingszeiten.</span>');return;}
  h='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Gruppe</th><th>Wochentag</th><th>Von</th><th>Bis</th><th></th></tr></thead><tbody>';
  $.each(S_fit.slots,function(i,s){
    h+='<tr>'
      +'<td data-label="Gruppe"><strong>'+esc(s.gruppe_name)+'</strong></td>'
      +'<td data-label="Tag">'+WD[s.wochentag]+'</td>'
      +'<td data-label="Von">'+String(s.zeit_von||'').slice(0,5)+'</td>'
      +'<td data-label="Bis">'+String(s.zeit_bis||'').slice(0,5)+'</td>'
      +'<td data-label=""><button class="i-btn i-btn-g i-btn-sm fit-sl-ed" data-id="'+s.id+'">Bearb.</button> <button class="i-btn i-btn-r i-btn-sm fit-sl-dl" data-id="'+s.id+'">Löschen</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#fit-sl-liste').html(h);
}

$('#fit-slot-neu').on('click',function(){
  $('#fit-sl-id').val('');$('#fit-sl-von,#fit-sl-bis').val('');$('#fit-sl-wt').val('1');
  fillSel($('#fit-sl-gruppe'),S_fit.gruppen,'id','name','Gruppe wählen\u2026');
  $('#m-fit-slot-ttl').text('Neue Trainingszeit');$('#m-fit-slot').addClass('open');
});

$(document).on('click','.fit-sl-ed',function(){
  var id=$(this).data('id'),s=S_fit.slots.find(function(x){return x.id==id;});
  if(!s)return;
  $('#fit-sl-id').val(s.id);$('#fit-sl-wt').val(s.wochentag);
  $('#fit-sl-von').val(String(s.zeit_von||'').slice(0,5));
  $('#fit-sl-bis').val(String(s.zeit_bis||'').slice(0,5));
  fillSel($('#fit-sl-gruppe'),S_fit.gruppen,'id','name','Gruppe wählen\u2026');
  $('#fit-sl-gruppe').val(s.gruppe_id);
  $('#m-fit-slot-ttl').text('Trainingszeit bearbeiten');$('#m-fit-slot').addClass('open');
});

$('#fit-sl-save').on('click',function(){
  var gruppe=$('#fit-sl-gruppe').val();
  if(!gruppe){toast('Bitte Gruppe wählen.','err');return;}
  var $b=$(this).prop('disabled',true).text('\u2026');
  ajax('lsv07i_fit_save_slot',{id:$('#fit-sl-id').val(),gruppe_id:gruppe,wochentag:$('#fit-sl-wt').val(),zeit_von:$('#fit-sl-von').val(),zeit_bis:$('#fit-sl-bis').val()}).done(function(r){
    if(r.success){
      S_fit.slots=r.data.slots||[];
      toast('Trainingszeit gespeichert.');$('#m-fit-slot').removeClass('open');
      renderFitSlots();fillFitAnwSlots();
    }else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});

$(document).on('click','.fit-sl-dl',function(){
  if(!confirm('Trainingszeit löschen?'))return;
  ajax('lsv07i_fit_delete_slot',{id:$(this).data('id')}).done(function(r){
    if(r.success){
      S_fit.slots=r.data.slots||[];
      toast('Gel\u00f6scht.');renderFitSlots();fillFitAnwSlots();
    }else toast(r.data&&r.data.message?r.data.message:'Fehler.','err');
  });
});


/* ── CSV-Import: Datei → Spalten zuordnen → Vorschau → Import ──────
   S_pcsv hält den Zustand über die drei Schritte hinweg. Der CSV-Text
   bleibt im Browser und wird bei jedem Schritt mitgeschickt, damit der
   Server nichts zwischenspeichern muss. */
var S_pcsv={csv:'',dateiname:'',delimiter:';',spalten:[],felder:{},mapping:[],zeilen:0,rows:[]};

function pcsvSchritt(n){
  $('#pcsv-s1,#pcsv-s2,#pcsv-s3').hide();
  $('#pcsv-s'+n).show();
  $('.pcsv-dot').each(function(){
    var i=parseInt($(this).data('step'),10);
    $(this).toggleClass('on',i===n).toggleClass('done',i<n);
  });
}

function pcsvReset(){
  S_pcsv={csv:'',dateiname:'',delimiter:';',spalten:[],felder:{},mapping:[],zeilen:0,rows:[]};
  $('#pers-csv-file').val('');
  $('#pers-csv-text').val('');
  $('#pcsv-map').empty();
  $('#pcsv-vorschau-box').empty();
  pcsvSchritt(1);
}

// Schritt 1 → 2: Inhalt an den Server geben und Spalten erkennen lassen
function pcsvAnalysieren(csv,delimiter){
  if(!csv||!csv.trim()){toast('Die Datei enthält keine Daten.','err');return;}
  S_pcsv.csv=csv;
  var daten={csv:csv};
  if(delimiter)daten.delimiter=delimiter;
  ajax('lsv07i_pers_csv_analyse',daten).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)||'Datei konnte nicht gelesen werden.','err');return;}
    S_pcsv.delimiter=r.data.delimiter;
    S_pcsv.spalten=r.data.spalten||[];
    S_pcsv.felder=r.data.felder||{};
    S_pcsv.mapping=r.data.mapping||[];
    S_pcsv.zeilen=r.data.zeilen||0;
    renderPcsvMapping();
    pcsvSchritt(2);
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
}

// Dropdown mit allen Zielfeldern, nach Gruppen sortiert
function pcsvFeldSelect(index,gewaehlt){
  var gruppen={},reihenfolge=[];
  $.each(S_pcsv.felder,function(key,f){
    if(!gruppen[f.gruppe]){gruppen[f.gruppe]=[];reihenfolge.push(f.gruppe);}
    gruppen[f.gruppe].push({key:key,label:f.label,mehrfach:!!f.mehrfach});
  });
  var h='<select class="i-ctl pcsv-feld" data-index="'+index+'">'
       +'<option value=""'+(gewaehlt?'':' selected')+'>– nicht importieren –</option>';
  reihenfolge.forEach(function(g){
    h+='<optgroup label="'+esc(g)+'">';
    gruppen[g].forEach(function(f){
      h+='<option value="'+esc(f.key)+'"'+(f.key===gewaehlt?' selected':'')+'>'+esc(f.label)+'</option>';
    });
    h+='</optgroup>';
  });
  return h+'</select>';
}

function renderPcsvMapping(){
  var h='<div class="i-notice" style="margin-bottom:12px">'
    +'<strong>'+S_pcsv.spalten.length+' Spalten</strong> und <strong>'+S_pcsv.zeilen+' Datenzeilen</strong> erkannt'
    +(S_pcsv.dateiname?' in <em>'+esc(S_pcsv.dateiname)+'</em>':'')
    +'. Bitte prüfe die Zuordnung — der Vorschlag stammt aus den Spaltenüberschriften.</div>';

  h+='<div class="i-twrap"><table class="i-tbl pcsv-tbl"><thead><tr>'
    +'<th style="width:26%">Spalte in der Datei</th>'
    +'<th style="width:34%">Beispielwerte</th>'
    +'<th style="width:40%">wird importiert als</th>'
    +'</tr></thead><tbody>';
  S_pcsv.spalten.forEach(function(sp){
    var bsp=sp.beispiele.length
      ? sp.beispiele.map(function(b){return '<code class="pcsv-bsp">'+esc(b.length>40?b.slice(0,40)+'…':b)+'</code>';}).join(' ')
      : '<span class="i-muted">(leer)</span>';
    h+='<tr>'
      +'<td data-label="Spalte"><strong>'+esc(sp.name)+'</strong></td>'
      +'<td data-label="Beispiele">'+bsp+'</td>'
      +'<td data-label="Zielfeld">'+pcsvFeldSelect(sp.index,S_pcsv.mapping[sp.index]||'')+'</td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#pcsv-map').html(h);
  pcsvMappingPruefen();
}

// Pflichtfelder und Doppelbelegungen direkt im Formular rückmelden
function pcsvMappingPruefen(){
  var gewaehlt={},doppelt={};
  $('.pcsv-feld').each(function(){
    var v=$(this).val();
    if(!v)return;
    var mehrfach=S_pcsv.felder[v]&&S_pcsv.felder[v].mehrfach;
    if(gewaehlt[v]&&!mehrfach)doppelt[v]=true;
    gewaehlt[v]=true;
  });
  $('.pcsv-feld').each(function(){
    var v=$(this).val();
    $(this).toggleClass('pcsv-doppelt',!!(v&&doppelt[v]));
  });

  var meldungen=[];
  var hatName=gewaehlt['name_voll'];
  if(!gewaehlt['vorname']&&!hatName)meldungen.push('Es fehlt eine Spalte für den <strong>Vornamen</strong>.');
  if(!gewaehlt['nachname']&&!hatName)meldungen.push('Es fehlt eine Spalte für den <strong>Nachnamen</strong>.');
  $.each(doppelt,function(k){
    meldungen.push('Das Feld <strong>'+esc(S_pcsv.felder[k].label)+'</strong> ist mehrfach zugeordnet.');
  });

  if(meldungen.length){
    $('#pcsv-map-hinweis').html('<div class="i-notice i-notice-r">'+meldungen.join('<br>')+'</div>').show();
    $('#pcsv-vorschau').prop('disabled',true);
  }else{
    $('#pcsv-map-hinweis').hide().empty();
    $('#pcsv-vorschau').prop('disabled',false);
  }
}

function pcsvMappingLesen(){
  var map=[];
  for(var i=0;i<S_pcsv.spalten.length;i++)map[i]='';
  $('.pcsv-feld').each(function(){
    map[parseInt($(this).data('index'),10)]=$(this).val()||'';
  });
  S_pcsv.mapping=map;
  return map;
}

function pcsvOptionen(){
  return {
    abgleich:$('#pcsv-abgleich').val(),
    leere_ueberspringen:$('#pcsv-leere').is(':checked')?'1':'0',
    zuordnung_modus:$('#pcsv-zuordnung').val(),
    nur_update:$('#pcsv-nur-update').is(':checked')?'1':''
  };
}

function pcsvRequest(phase){
  return $.extend({
    phase:phase,
    csv:S_pcsv.csv,
    delimiter:S_pcsv.delimiter,
    mapping:JSON.stringify(S_pcsv.mapping)
  },pcsvOptionen());
}

// ── Schritt 1: Datei oder eingefügter Text ──────────────────────────
$(document).on('change','#pers-csv-file',function(){
  var f=this.files[0];
  if(!f)return;
  S_pcsv.dateiname=f.name;
  readFileAsCsv(f,function(csv){pcsvAnalysieren(csv,'');});
});

$(document).on('click','#pcsv-text-weiter',function(){
  S_pcsv.dateiname='';
  pcsvAnalysieren($('#pers-csv-text').val(),'');
});

$(document).on('click','#pcsv-abbrechen',function(){pcsvReset();});

// ── Schritt 2: Zuordnung ────────────────────────────────────────────
$(document).on('change','.pcsv-feld',pcsvMappingPruefen);

$(document).on('change','#pcsv-delim',function(){
  // Anderes Trennzeichen → Datei neu zerlegen, Zuordnung neu vorschlagen
  pcsvAnalysieren(S_pcsv.csv,$(this).val());
});

$(document).on('click','#pcsv-zurueck-1',function(){pcsvSchritt(1);});

$(document).on('click','#pcsv-vorschau',function(){
  pcsvMappingLesen();
  var $b=$(this).prop('disabled',true).text('…');
  ajax('lsv07i_pers_csv_import',pcsvRequest('preview')).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)||'Vorschau fehlgeschlagen.','err');return;}
    S_pcsv.rows=r.data.rows||[];
    renderPcsvVorschau();
    pcsvSchritt(3);
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){
    $b.prop('disabled',false).text('Vorschau anzeigen');
  });
});

// ── Schritt 3: Vorschau + Import ────────────────────────────────────
function renderPcsvVorschau(){
  var rows=S_pcsv.rows;
  if(!rows.length){
    $('#pcsv-vorschau-box').html('<div class="i-notice i-notice-a">Keine Datenzeilen erkannt.</div>');
    $('#pcsv-commit').hide();
    return;
  }
  var ok=0,fehler=0,neu=0,update=0;
  $.each(rows,function(i,r){
    if(r._fehler){fehler++;}
    else{ok++;if(r._match_id)update++;else neu++;}
  });

  var h='<div class="i-notice" style="margin-bottom:10px">'
    +'<strong>'+rows.length+' Zeilen</strong> · '
    +'<span style="color:#16a34a">'+neu+' neu</span> · '
    +'<span style="color:#d97706">'+update+' Aktualisierung'+(update===1?'':'en')+'</span>'
    +(fehler>0?' · <span style="color:#dc2626">'+fehler+' mit Fehlern (werden übersprungen)</span>':'')
    +'</div>';

  h+='<div class="i-twrap" style="max-height:420px;overflow:auto"><table class="i-tbl" style="font-size:12px"><thead><tr>'
    +'<th>Zeile</th><th>Status</th><th>Name</th><th>Geburtsdatum</th><th>Kontakt</th>'
    +'<th>Sparten / Rollen</th><th>Mannschaften</th><th>WP-Konto</th><th>Abgleich</th>'
    +'</tr></thead><tbody>';
  $.each(rows,function(i,r){
    var stat=r._fehler?bdg('r','Fehler'):(r._match_id?bdg('a','Update'):bdg('g','Neu'));
    var sr=$.map(r.sparten_rollen||[],function(x){
      return '<span class="i-bdg i-bdg-b">'+esc(x.sparte+': '+x.rolle)+'</span>';
    }).join(' ');
    var mn=$.map(r.mannschaften||[],function(m){
      if(m.fehler)return '<span style="color:#dc2626">'+esc(m.name)+' ⚠</span>';
      return '<span class="i-bdg i-bdg-b">'+esc(m.name)+'</span>';
    }).join(' ');
    var kontakt=[r.email,r.telefon].filter(Boolean).map(esc).join('<br>');
    h+='<tr class="'+(r._fehler?'pcsv-r-fehler':(r._match_id?'pcsv-r-update':''))+'">'
      +'<td data-label="Zeile">'+r._nr+'</td>'
      +'<td data-label="Status">'+stat+'</td>'
      +'<td data-label="Name"><strong>'+esc((r.vorname+' '+r.nachname).trim()||'—')+'</strong>'
        +(r._fehler?'<div style="color:#dc2626;font-size:10.5px;margin-top:2px">'+esc(r._fehler)+'</div>':'')+'</td>'
      +'<td data-label="Geburtsdatum">'+(r.geburtsdatum?de(r.geburtsdatum):'<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="Kontakt">'+(kontakt||'<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="Sparten">'+(sr||'<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="Mannschaften">'+(mn||'<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="WP-Konto">'+(r.wp_user_name?esc(r.wp_user_name):'<span class="i-muted">–</span>')+'</td>'
      +'<td data-label="Abgleich">'+(r._match_str?esc(r._match_str):'<span class="i-muted">neu</span>')+'</td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#pcsv-vorschau-box').html(h);

  if(ok>0){
    $('#pcsv-commit').show().text(ok+' Zeile'+(ok===1?'':'n')+' importieren').prop('disabled',false);
  }else{
    $('#pcsv-commit').hide();
  }
}

$(document).on('click','#pcsv-zurueck-2',function(){pcsvSchritt(2);});

$(document).on('click','#pcsv-commit',function(){
  var $b=$(this).prop('disabled',true).text('Importiere…');
  ajax('lsv07i_pers_csv_import',pcsvRequest('commit')).done(function(r){
    if(!r.success){toast((r.data&&r.data.message)||'Import fehlgeschlagen.','err');return;}
    toast(r.data.message);
    var h='<div class="i-notice"><strong>'+esc(r.data.message)+'</strong>';
    if(r.data.fehler&&r.data.fehler.length){
      h+='<ul style="margin:8px 0 0;padding-left:18px;font-size:12px">'
        +r.data.fehler.map(function(f){return '<li>'+esc(f)+'</li>';}).join('')+'</ul>';
    }
    h+='</div><button id="pcsv-neu" class="i-btn i-btn-g" style="margin-top:12px">Weitere Datei importieren</button>';
    $('#pcsv-vorschau-box').html(h);
    $('#pcsv-commit,#pcsv-zurueck-2').hide();
    // Personen-Tab neu laden, damit die frisch importierten Zeilen erscheinen
    if(typeof loadAdmPersonen==='function')loadAdmPersonen();
  }).fail(function(xhr){toast(errMsg(xhr),'err');}).always(function(){
    $b.prop('disabled',false);
  });
});

$(document).on('click','#pcsv-neu',function(){
  $('#pcsv-commit,#pcsv-zurueck-2').show();
  pcsvReset();
});

/* ══ RECHTE-VERWALTUNG (granular) ════════════════════════════════
   Iter 2: Admin-UI für die User × Recht-Matrix.
   Endpunkte: lsv07i_rechte_userlist/user_get/user_save/apply_tpl/meta */
var S_rechte={meta:null,suchTimer:null};

function loadRechte(){
  console.log('[LSV07i] loadRechte() aufgerufen');
  // User-Liste IMMER sofort laden — die Daten sind unabhängig von den Metadaten.
  loadRechteUserliste();
  // Metadaten parallel im Hintergrund holen, falls noch nicht da.
  // Werden für Bearbeiten-Modal und Template-Liste gebraucht.
  if(!S_rechte.meta){
    ajax('lsv07i_rechte_meta').done(function(r){
      console.log('[LSV07i] meta:',r);
      if(r&&r.success){
        S_rechte.meta=r.data;
        renderRechteTemplateListe();
      }
    });
  } else {
    renderRechteTemplateListe();
  }
}

function loadRechteUserliste(){
  console.log('[LSV07i] loadRechteUserliste() startet AJAX...');
  skel($('#rechte-liste'),'rows',6);
  var search=($('#rechte-suche').val()||'').trim();
  ajax('lsv07i_rechte_userlist',{search:search}).done(function(r){
    console.log('[LSV07i] userlist Antwort:',r);
    // WordPress liefert "0" wenn die Action nicht registriert ist
    if(r==='0'||r===0||!r){
      $('#rechte-liste').html('<div class="i-notice i-notice-r"><strong>Endpunkt nicht erreichbar.</strong> Plugin neu aktivieren oder Browser-Cache leeren (Strg+Shift+R).</div>');
      return;
    }
    if(typeof r!=='object'){
      $('#rechte-liste').html('<div class="i-notice i-notice-r"><strong>Unerwartete Antwort:</strong> <code>'+esc(String(r).slice(0,200))+'</code></div>');
      return;
    }
    if(!r.success){
      var msg=r.data&&r.data.message?r.data.message:'unbekannter Fehler';
      $('#rechte-liste').html('<div class="i-notice i-notice-r">Fehler beim Laden: '+esc(msg)+'</div>');
      return;
    }
    renderRechteUserliste(r.data);
  }).fail(function(xhr){
    var details=xhr.status+' '+(xhr.statusText||'');
    var hint='';
    // WP-spezifischer Hinweis: 400 + "0" bedeutet fast immer "Action nicht registriert"
    if(xhr.status===400 && (xhr.responseText==='0' || xhr.responseText===0)){
      hint='<br><br><strong>Mögliche Ursachen:</strong>'
        +'<ul style="margin:6px 0 0 18px;padding:0">'
        +'<li>Plugin wurde nach dem Update noch nicht neu aktiviert (Admin → Plugins → LSV07 Intern deaktivieren und wieder aktivieren).</li>'
        +'<li>Browser-Cache ist alt. Bitte Strg+Shift+R drücken.</li>'
        +'<li>Ein anderes Plugin oder ein Fatal Error verhindert die Registrierung der Endpunkte. Bitte in das PHP-Error-Log schauen (wp-content/debug.log).</li>'
        +'</ul>';
    }
    if(xhr.responseText)details+=' — '+xhr.responseText.slice(0,200);
    $('#rechte-liste').html('<div class="i-notice i-notice-r">AJAX-Fehler: '+esc(details)+hint+'</div>');
  });
}

function renderRechteUserliste(users){
  if(!users||!users.length){
    $('#rechte-liste').html('<span class="i-muted">Keine Benutzer gefunden.</span>');
    return;
  }
  var h='<div class="i-twrap"><table class="i-tbl">'
       +'<thead><tr><th>Name</th><th>E-Mail</th><th>WP-Rolle</th>'
       +'<th style="text-align:right">Rechte</th><th></th></tr></thead><tbody>';
  $.each(users,function(i,u){
    var rolle=(u.roles&&u.roles.length)?u.roles[0]:'<span class="i-muted">–</span>';
    var n=u.count;
    var bdg=n>0
      ?'<span class="i-bdg i-bdg-b">'+n+'</span>'
      :'<span class="i-bdg" style="background:rgba(255,255,255,0.10);color:#6b6e85">0 (Legacy)</span>';
    h+='<tr><td data-label="Name"><strong>'+esc(u.name)+'</strong></td>'
      +'<td data-label="E-Mail">'+esc(u.email||'')+'</td>'
      +'<td data-label="WP-Rolle"><code style="font-size:11px">'+esc(rolle)+'</code></td>'
      +'<td data-label="Rechte" style="text-align:right">'+bdg+'</td>'
      +'<td style="text-align:right"><button class="i-btn i-btn-sm rechte-edit" data-uid="'+u.id+'">Bearbeiten</button></td></tr>';
  });
  h+='</tbody></table></div>';
  $('#rechte-liste').html(h);
  tableCollapse($('#rechte-liste'),20);
}

// Suche mit Debounce
$(document).on('input','#rechte-suche',function(){
  clearTimeout(S_rechte.suchTimer);
  S_rechte.suchTimer=setTimeout(loadRechteUserliste,250);
});

// Bearbeiten-Klick
$(document).on('click','.rechte-edit',function(){
  var uid=$(this).data('uid');
  openRechteModal(uid);
});

function openRechteModal(uid){
  $('#rechte-uid').val(uid);
  $('#rechte-userinfo').text('Lade…');
  $('#rechte-matrix').html('<div class="i-muted" style="padding:12px;text-align:center">Lade Rechte…</div>');
  $('#rechte-tpl').empty().append('<option value="">— Kein Template —</option>');
  openModal('m-rechte');

  // Beide Calls parallel: Meta (falls noch nicht da) + User-Rechte
  var pMeta=S_rechte.meta
    ?$.Deferred().resolve({success:true,data:S_rechte.meta}).promise()
    :ajax('lsv07i_rechte_meta');
  var pUser=ajax('lsv07i_rechte_user_get',{user_id:uid});

  $.when(pMeta,pUser).done(function(rMeta,rUser){
    // jQuery .when liefert Deferred-Args als [data, status, jqXHR]
    var meta=$.isArray(rMeta)?rMeta[0]:rMeta;
    var user=$.isArray(rUser)?rUser[0]:rUser;
    if(meta&&meta.success&&meta.data){S_rechte.meta=meta.data;}
    if(!user||!user.success){toast('Fehler beim Laden der Rechte.','err');return;}

    // Templates ins Dropdown
    var $sel=$('#rechte-tpl').empty();
    $sel.append('<option value="">— Kein Template —</option>');
    if(S_rechte.meta&&S_rechte.meta.templates){
      $.each(S_rechte.meta.templates,function(id,tpl){
        $sel.append('<option value="'+esc(id)+'">'+esc(tpl.name)+' ('+tpl.rights.length+' Rechte)</option>');
      });
    }

    var u=user.data;
    var name=esc(u.name)+' &lt;'+esc(u.email||'')+'&gt;';
    var rolle=(u.roles&&u.roles.length)?u.roles.join(', '):'keine';
    var hint=u.has_explicit
      ?'<strong>'+u.rights.length+'</strong> explizite Rechte zugewiesen'
      :'<em>Keine expliziten Rechte — es gilt das alte Rollensystem (WP-Rolle: '+esc(rolle)+').</em>';
    $('#rechte-userinfo').html(name+'<br>'+hint);
    renderRechteMatrix(u.rights);
  }).fail(function(xhr){
    toast('Fehler: '+errMsg(xhr),'err');
  });
}

function renderRechteMatrix(activeRights){
  if(!S_rechte.meta||!S_rechte.meta.bereiche){
    $('#rechte-matrix').html('<div class="i-notice i-notice-r">Metadaten nicht geladen.</div>');
    return;
  }
  var active={};
  if(Array.isArray(activeRights)){
    $.each(activeRights,function(i,r){active[r]=true;});
  }

  var h='';
  $.each(S_rechte.meta.bereiche,function(bereich,gruppen){
    var totalCount=0,activeCount=0;
    $.each(gruppen,function(g,rs){$.each(rs,function(c){totalCount++;if(active[c])activeCount++;});});
    var bid='rb-'+esc(bereich.toLowerCase().replace(/[^a-z0-9]/g,''));
    h+='<div class="i-acc'+(activeCount>0?' open':'')+'" style="margin-bottom:6px;background:rgba(255,255,255,0.06)">'
      +'<button class="i-acc-hd" type="button">'
      +'<span class="i-acc-ttl">'+esc(bereich)+'</span>'
      +'<span class="i-acc-meta" id="'+bid+'-cnt">'+activeCount+' / '+totalCount+'</span>'
      +'<span class="i-acc-arr">▾</span></button>'
      +'<div class="i-acc-bd">';
    // "Alle in diesem Bereich"-Toggle
    h+='<div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--c-line-soft)">'
      +'<label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer;color:var(--c-blue);font-weight:600">'
      +'<input type="checkbox" class="rechte-all" data-bereich="'+esc(bereich)+'"'
      +(activeCount===totalCount?' checked':'')+'> '
      +'Alle Rechte in „'+esc(bereich)+'"</label></div>';
    $.each(gruppen,function(gruppe,rechte){
      h+='<div style="margin-bottom:10px">'
        +'<div style="font-size:11.5px;font-weight:600;color:var(--c-text-3);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">'+esc(gruppe)+'</div>'
        +'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:4px 12px">';
      $.each(rechte,function(code,label){
        h+='<label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 6px;border-radius:6px;cursor:pointer">'
          +'<input type="checkbox" class="rechte-cb" data-code="'+esc(code)+'" data-bereich="'+esc(bereich)+'"'+(active[code]?' checked':'')+'> '
          +'<span style="flex:1">'+esc(label)+'</span>'
          +'<code style="font-size:10px;color:var(--c-text-3);font-weight:400">'+esc(code)+'</code></label>';
      });
      h+='</div></div>';
    });
    h+='</div></div>';
  });
  $('#rechte-matrix').html(h);
}

// "Alle in Bereich"-Toggle
$(document).on('change','.rechte-all',function(){
  var $cb=$(this);
  var bereich=$cb.data('bereich');
  var on=$cb.prop('checked');
  $('#rechte-matrix .rechte-cb[data-bereich="'+bereich+'"]').prop('checked',on);
  updateRechteCounts();
});

// Einzel-Toggle aktualisiert Counter
$(document).on('change','.rechte-cb',function(){
  updateRechteCounts();
});

function updateRechteCounts(){
  if(!S_rechte.meta)return;
  $.each(S_rechte.meta.bereiche,function(bereich){
    var bid='rb-'+bereich.toLowerCase().replace(/[^a-z0-9]/g,'');
    var $items=$('#rechte-matrix .rechte-cb[data-bereich="'+bereich+'"]');
    var total=$items.length;
    var on=$items.filter(':checked').length;
    $('#'+bid+'-cnt').text(on+' / '+total);
    // "Alle"-Checkbox synchron halten
    var $all=$('#rechte-matrix .rechte-all[data-bereich="'+bereich+'"]');
    $all.prop('checked',on===total&&total>0);
    $all.prop('indeterminate',on>0&&on<total);
  });
}

// Template anwenden
$(document).on('click','#rechte-tpl-apply',function(){
  var tpl=$('#rechte-tpl').val();
  if(!tpl){toast('Bitte ein Template wählen.','err');return;}
  var uid=$('#rechte-uid').val();
  if(!confirm('Template "'+$('#rechte-tpl option:selected').text()+'" anwenden?\n\nAlle bisherigen Rechte werden überschrieben.'))return;
  ajax('lsv07i_rechte_apply_tpl',{user_id:uid,template:tpl}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message?r.data.message:'Fehler.','err');return;}
    toast(r.data.message);
    renderRechteMatrix(r.data.rights);
  });
});

// Speichern
$(document).on('click','#rechte-save',function(){
  var uid=$('#rechte-uid').val();
  var rights=[];
  $('#rechte-matrix .rechte-cb:checked').each(function(){
    rights.push($(this).data('code'));
  });
  var $b=$(this).prop('disabled',true).text('Speichert…');
  ajax('lsv07i_rechte_user_save',{user_id:uid,rights:JSON.stringify(rights)}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message?r.data.message:'Fehler.','err');return;}
    toast(r.data.message);
    closeModal('m-rechte');
    loadRechteUserliste();
  }).always(function(){
    $b.prop('disabled',false).text('Speichern');
  });
});

// ─── TEMPLATE-VERWALTUNG ──────────────────────────────────────────────
// Liste der Templates rendern (built-in + custom)
function renderRechteTemplateListe(){
  var $box=$('#rechte-tpl-liste');
  if(!$box.length)return;
  if(!S_rechte.meta||!S_rechte.meta.templates){
    $box.html('<span class="i-muted">Lade…</span>');
    return;
  }
  var tpls=S_rechte.meta.templates;
  var built=[],custom=[];
  $.each(tpls,function(id,t){
    if(t.built_in){built.push({id:id,t:t});}
    else{custom.push({id:id,t:t});}
  });

  var h='<div class="i-twrap"><table class="i-tbl">'
    +'<thead><tr><th>Name</th><th>Typ</th><th style="text-align:right">Rechte</th><th></th></tr></thead><tbody>';

  $.each(built,function(i,o){
    // System-Templates können gelöscht (= ausgeblendet) werden, aber nicht bearbeitet.
    // Ausnahme: admin_full ist geschützt, sonst kommt niemand mehr ins System.
    var delBtn=o.id==='admin_full'
      ?''
      :'<button class="i-btn i-btn-sm i-btn-r rechte-tpl-del" data-tid="'+esc(o.id)+'" data-name="'+esc(o.t.name)+'" data-builtin="1">Löschen</button>';
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(o.t.name)+'</strong>'
      +(o.t.beschreibung?'<div style="font-size:11px;color:var(--c-text-3)">'+esc(o.t.beschreibung)+'</div>':'')
      +'</td>'
      +'<td data-label="Typ"><span class="i-bdg" style="background:rgba(255,255,255,0.10);color:#6b6e85">System</span></td>'
      +'<td data-label="Rechte" style="text-align:right">'+o.t.rights.length+'</td>'
      +'<td style="text-align:right;white-space:nowrap">'+delBtn+'</td>'
      +'</tr>';
  });
  $.each(custom,function(i,o){
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(o.t.name)+'</strong>'
      +(o.t.beschreibung?'<div style="font-size:11px;color:var(--c-text-3)">'+esc(o.t.beschreibung)+'</div>':'')
      +'</td>'
      +'<td data-label="Typ"><span class="i-bdg i-bdg-b">Eigen</span></td>'
      +'<td data-label="Rechte" style="text-align:right">'+o.t.rights.length+'</td>'
      +'<td style="text-align:right;white-space:nowrap">'
      +'<button class="i-btn i-btn-sm rechte-tpl-edit" data-tid="'+esc(o.id)+'">Bearbeiten</button> '
      +'<button class="i-btn i-btn-sm i-btn-r rechte-tpl-del" data-tid="'+esc(o.id)+'" data-name="'+esc(o.t.name)+'">Löschen</button>'
      +'</td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $box.html(h);
}

// "+ Neues Template" öffnet das Modal im Anlege-Modus
$(document).on('click','#rechte-tpl-neu',function(){
  $('#rechte-tpl-id').val('');
  $('#rechte-tpl-name').val('');
  $('#rechte-tpl-desc').val('');
  $('#m-rechte-tpl-ttl').text('Neues Template anlegen');
  if(!S_rechte.meta||!S_rechte.meta.bereiche){
    $('#rechte-tpl-matrix').html('<div class="i-muted" style="padding:12px">Metadaten nicht geladen — bitte einen Moment warten und erneut versuchen.</div>');
  } else {
    renderTplMatrix([]);
  }
  openModal('m-rechte-tpl');
});

// "Bearbeiten" eines Custom-Templates
$(document).on('click','.rechte-tpl-edit',function(){
  var tid=$(this).data('tid');
  if(!S_rechte.meta||!S_rechte.meta.templates||!S_rechte.meta.templates[tid]){
    toast('Template nicht gefunden.','err');return;
  }
  var t=S_rechte.meta.templates[tid];
  // Custom-Templates haben db_id; aus tid 'custom_<n>' extrahieren als Fallback
  var dbId=t.db_id||(tid.indexOf('custom_')===0?parseInt(tid.substring(7),10):0);
  $('#rechte-tpl-id').val(dbId||'');
  $('#rechte-tpl-name').val(t.name||'');
  $('#rechte-tpl-desc').val(t.beschreibung||'');
  $('#m-rechte-tpl-ttl').text('Template bearbeiten');
  renderTplMatrix(t.rights||[]);
  openModal('m-rechte-tpl');
});

// "Löschen"
$(document).on('click','.rechte-tpl-del',function(){
  var tid=$(this).data('tid'),name=$(this).data('name');
  var isBuiltin=String($(this).data('builtin')||'')==='1';
  var msg=isBuiltin
    ? 'System-Template "'+name+'" wirklich entfernen?\n\nDas Template wird aus der UI ausgeblendet. Es kann später vom Entwickler wieder aktiviert werden.\n\nBenutzer, die das Template bereits angewendet haben, behalten ihre Rechte.'
    : 'Template "'+name+'" wirklich löschen?\n\nBenutzer, die das Template angewendet haben, behalten ihre aktuell gesetzten Rechte. Nur das Template selbst verschwindet.';
  if(!confirm(msg))return;
  ajax('lsv07i_rechte_tpl_delete',{template:tid}).done(function(r){
    if(!r||!r.success){
      toast((r&&r.data&&r.data.message)||'Fehler.','err');return;
    }
    toast(r.data.message||'Gelöscht.');
    // Meta neu laden, dann Liste rerendern
    S_rechte.meta=null;
    ajax('lsv07i_rechte_meta').done(function(r2){
      if(r2&&r2.success){S_rechte.meta=r2.data;renderRechteTemplateListe();}
    });
  });
});

// Matrix für Template-Modal rendern. Selbe Logik wie für User, aber ohne Bereich-Counter-Sync
function renderTplMatrix(activeRights){
  if(!S_rechte.meta||!S_rechte.meta.bereiche){
    $('#rechte-tpl-matrix').html('<div class="i-notice i-notice-r">Metadaten nicht geladen.</div>');
    return;
  }
  var active={};
  if(Array.isArray(activeRights)){
    $.each(activeRights,function(i,r){active[r]=true;});
  }
  var h='';
  $.each(S_rechte.meta.bereiche,function(bereich,gruppen){
    var totalCount=0,activeCount=0;
    $.each(gruppen,function(g,rs){$.each(rs,function(c){totalCount++;if(active[c])activeCount++;});});
    h+='<div class="i-acc'+(activeCount>0?' open':'')+'" style="margin-bottom:6px;background:rgba(255,255,255,0.06)">'
      +'<button class="i-acc-hd" type="button">'
      +'<span class="i-acc-ttl">'+esc(bereich)+'</span>'
      +'<span class="i-acc-meta">'+activeCount+' / '+totalCount+'</span>'
      +'<span class="i-acc-arr">▾</span></button>'
      +'<div class="i-acc-bd">'
      +'<div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid var(--c-line-soft)">'
      +'<label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer;color:var(--c-blue);font-weight:600">'
      +'<input type="checkbox" class="rechte-tpl-all" data-bereich="'+esc(bereich)+'"'
      +(activeCount===totalCount?' checked':'')+'> '
      +'Alle Rechte in „'+esc(bereich)+'"</label></div>';
    $.each(gruppen,function(gruppe,rechte){
      h+='<div style="margin-bottom:10px">'
        +'<div style="font-size:11.5px;font-weight:600;color:var(--c-text-3);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px">'+esc(gruppe)+'</div>'
        +'<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:4px 12px">';
      $.each(rechte,function(code,label){
        h+='<label style="display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 6px;border-radius:6px;cursor:pointer">'
          +'<input type="checkbox" class="rechte-tpl-cb" data-code="'+esc(code)+'" data-bereich="'+esc(bereich)+'"'+(active[code]?' checked':'')+'> '
          +'<span style="flex:1">'+esc(label)+'</span></label>';
      });
      h+='</div></div>';
    });
    h+='</div></div>';
  });
  $('#rechte-tpl-matrix').html(h);
}

// "Alle in Bereich"-Toggle für Template
$(document).on('change','.rechte-tpl-all',function(){
  var b=$(this).data('bereich');
  var on=$(this).prop('checked');
  $('#rechte-tpl-matrix .rechte-tpl-cb[data-bereich="'+b+'"]').prop('checked',on);
});

// Speichern
$(document).on('click','#rechte-tpl-save',function(){
  var id=parseInt($('#rechte-tpl-id').val()||'0',10);
  var name=($('#rechte-tpl-name').val()||'').trim();
  var desc=($('#rechte-tpl-desc').val()||'').trim();
  if(!name){toast('Bitte einen Namen eingeben.','err');return;}
  var rights=[];
  $('#rechte-tpl-matrix .rechte-tpl-cb:checked').each(function(){rights.push($(this).data('code'));});
  if(!rights.length){
    if(!confirm('Dieses Template hat keine Rechte. Trotzdem speichern?'))return;
  }
  console.log('[LSV07i] Template speichern:', {id:id, name:name, rechteAnzahl:rights.length});
  var $b=$(this).prop('disabled',true).text('Speichert…');
  ajax('lsv07i_rechte_tpl_save',{
    id:id,name:name,beschreibung:desc,rights:JSON.stringify(rights)
  }).done(function(r){
    console.log('[LSV07i] tpl_save Antwort:', r);
    // WordPress liefert "0" wenn Action nicht registriert
    if(r==='0'||r===0||!r){
      toast('Endpunkt nicht erreichbar. Plugin neu aktivieren oder Cache leeren.','err');
      return;
    }
    if(typeof r!=='object'){
      toast('Unerwartete Antwort: '+String(r).slice(0,100),'err');
      return;
    }
    if(!r.success){
      var msg=(r.data&&r.data.message)?r.data.message:'Speichern fehlgeschlagen.';
      toast(msg,'err');
      return;
    }
    toast(r.data.message||'Gespeichert.');
    closeModal('m-rechte-tpl');
    // Meta neu laden, dann Liste rerendern
    S_rechte.meta=null;
    ajax('lsv07i_rechte_meta').done(function(r2){
      if(r2&&r2.success){S_rechte.meta=r2.data;renderRechteTemplateListe();}
    });
  }).fail(function(xhr){
    console.error('[LSV07i] tpl_save FAIL:', xhr.status, xhr.responseText);
    toast('Server-Fehler ('+xhr.status+'): '+(xhr.responseText||xhr.statusText||'?').slice(0,200),'err');
  }).always(function(){
    $b.prop('disabled',false).text('Speichern');
  });
});

/* ══ SCHWIMMER-DATEIEN ═════════════════════════════════════════════
   Upload, Liste, Download und Löschen für Dateien pro Schwimmer.
   Berechtigung wird serverseitig geprüft (schwimmen.datei.*).        */

function loadSwDateien(sid){
  var $list=$('#msw-datei-liste');
  $list.html('<span class="i-muted" style="font-size:12px">Lade…</span>');
  ajax('lsv07i_sw_datei_list',{schwimmer_id:sid}).done(function(r){
    if(!r||r==='0'||r===0){
      $list.html('<div class="i-notice i-notice-r" style="font-size:12px">Endpunkt nicht erreichbar.</div>');return;
    }
    if(!r.success){
      var msg=r.data&&r.data.message?r.data.message:'Fehler.';
      $list.html('<div class="i-notice i-notice-r" style="font-size:12px">'+esc(msg)+'</div>');return;
    }
    // Neues Format: {files, can}. Fallback auf altes Format.
    var files=(r.data&&r.data.files)?r.data.files:(Array.isArray(r.data)?r.data:[]);
    renderSwDateien(files);
  }).fail(function(xhr){
    $list.html('<div class="i-notice i-notice-r" style="font-size:12px">AJAX-Fehler: '+esc(errMsg(xhr))+'</div>');
  });
}

function renderSwDateien(files){
  if(!files.length){
    $('#msw-datei-liste').html('<span class="i-muted" style="font-size:12px">Noch keine Dateien.</span>');
    return;
  }
  var h='<div style="display:flex;flex-direction:column;gap:6px">';
  $.each(files,function(i,f){
    var size=Math.round(f.groesse/1024);
    var sizeStr=size>1024?(Math.round(size/1024*10)/10)+' MB':size+' kB';
    var dat=f.hochgeladen_am?de(String(f.hochgeladen_am).slice(0,10)):'';
    var icon=f.mime_type==='application/pdf'?'📄':'🖼';
    var dlUrl=LSV07I.ajax_url+'?action=lsv07i_sw_datei_download&id='+f.id+'&nonce='+encodeURIComponent(LSV07I.nonce);
    var kom=f.kommentar?'<div style="font-size:11.5px;color:var(--c-text-3);margin-top:2px">'+esc(f.kommentar)+'</div>':'';
    h+='<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;background:rgba(255,255,255,0.06);border:1px solid var(--c-line);border-radius:8px">'
      +'<span style="font-size:18px;flex-shrink:0">'+icon+'</span>'
      +'<div style="flex:1;min-width:0">'
      +'<a href="'+dlUrl+'" target="_blank" style="font-size:13px;font-weight:600;color:var(--c-blue);word-break:break-all">'+esc(f.dateiname)+'</a>'
      +'<div style="font-size:11px;color:var(--c-text-3);margin-top:1px">'+sizeStr+' · '+esc(dat)+' · '+esc(f.hochgeladen_von_name||'?')+'</div>'
      +kom
      +'</div>'
      +'<button type="button" class="i-btn i-btn-r i-btn-sm msw-datei-del" data-id="'+f.id+'" title="Löschen">✕</button>'
      +'</div>';
  });
  h+='</div>';
  $('#msw-datei-liste').html(h);
}

// Upload-Form öffnen
$(document).on('click','#msw-datei-add',function(){
  $('#msw-datei-form').show();
  $('#msw-datei-input').val('');
  $('#msw-datei-kommentar').val('');
});
$(document).on('click','#msw-datei-cancel',function(){
  $('#msw-datei-form').hide();
});

// Upload durchführen
$(document).on('click','#msw-datei-upload',function(){
  var sid=$('#msw-id').val();
  if(!sid){toast('Schwimmer muss erst gespeichert werden.','err');return;}
  var fileInput=document.getElementById('msw-datei-input');
  if(!fileInput.files||!fileInput.files.length){toast('Bitte eine Datei wählen.','err');return;}
  var file=fileInput.files[0];
  if(file.size>5*1024*1024){toast('Datei zu groß (max. 5 MB).','err');return;}

  var fd=new FormData();
  fd.append('action','lsv07i_sw_datei_upload');
  fd.append('nonce',LSV07I.nonce);
  fd.append('schwimmer_id',sid);
  fd.append('datei',file);
  fd.append('kommentar',$('#msw-datei-kommentar').val()||'');

  var $btn=$(this).prop('disabled',true).text('Lädt hoch…');
  startLoader();
  $.ajax({
    url:LSV07I.ajax_url,
    type:'POST',
    data:fd,
    processData:false,
    contentType:false
  }).done(function(r){
    if(!r||r==='0'){toast('Endpunkt nicht erreichbar.','err');return;}
    if(!r.success){
      var msg=r.data&&r.data.message?r.data.message:'Upload fehlgeschlagen.';
      toast(msg,'err');return;
    }
    toast(r.data.message||'Hochgeladen.');
    $('#msw-datei-form').hide();
    loadSwDateien(sid);
  }).fail(function(xhr){
    toast('Upload-Fehler: '+errMsg(xhr),'err');
  }).always(function(){
    $btn.prop('disabled',false).text('Hochladen');
    stopLoader();
  });
});

// Datei löschen
$(document).on('click','.msw-datei-del',function(){
  var id=$(this).data('id');
  if(!confirm('Diese Datei wirklich löschen?'))return;
  var sid=$('#msw-id').val();
  ajax('lsv07i_sw_datei_delete',{id:id}).done(function(r){
    if(!r.success){toast(r.data&&r.data.message?r.data.message:'Fehler.','err');return;}
    toast(r.data.message||'Gelöscht.');
    loadSwDateien(sid);
  });
});

// Lese-Modal für Schwimmer-Dateien (Aufruf aus der Mannschaftsliste).
// Trainer mit Upload-/Lösch-Recht sehen die entsprechenden UI-Elemente.
var _swfModal={sid:null,name:''};

$(document).on('click','.sw-files-btn',function(e){
  e.stopPropagation();
  _swfModal.sid=$(this).data('sid');
  _swfModal.name=$(this).data('name');
  $('#m-sw-files-name').text('Dateien — '+_swfModal.name);
  openModal('m-sw-files');
  loadSwfModalDateien();
});

function loadSwfModalDateien(){
  var sid=_swfModal.sid;
  if(!sid)return;
  $('#m-sw-files-list').html('<span class="i-muted">Lade…</span>');
  ajax('lsv07i_sw_datei_list',{schwimmer_id:sid}).done(function(r){
    if(!r||!r.success){
      $('#m-sw-files-list').html('<div class="i-notice i-notice-r" style="font-size:12px">'+esc((r&&r.data&&r.data.message)||'Fehler beim Laden.')+'</div>');
      return;
    }
    var files=(r.data&&r.data.files)?r.data.files:(Array.isArray(r.data)?r.data:[]);
    var can=(r.data&&r.data.can)?r.data.can:{upload:false,delete:false};

    var h='';

    // Upload-Form (nur wenn User Upload-Recht hat)
    if(can.upload){
      h+='<div style="margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--c-line)">'
        +'<button type="button" id="swf-add" class="i-btn i-btn-g i-btn-sm">+ Datei hochladen</button>'
        +'<div id="swf-form" style="display:none;margin-top:8px;padding:10px;background:var(--c-blue-soft);border-radius:8px">'
        +'<input type="file" id="swf-input" accept=".pdf,.jpg,.jpeg,.png" class="i-ctl">'
        +'<input type="text" id="swf-kom" placeholder="Kommentar (optional)" maxlength="500" class="i-ctl" style="margin-top:6px">'
        +'<div style="display:flex;gap:6px;justify-content:flex-end;margin-top:8px">'
        +'<button type="button" id="swf-cancel" class="i-btn i-btn-r i-btn-sm">Abbrechen</button>'
        +'<button type="button" id="swf-upload" class="i-btn i-btn-p i-btn-sm">Hochladen</button>'
        +'</div>'
        +'<div class="i-lbl-hint" style="margin-top:6px">PDF, JPG, PNG · max. 5 MB</div>'
        +'</div></div>';
    }

    if(!files.length){
      h+='<span class="i-muted" style="font-size:13px">Keine Dateien hochgeladen.</span>';
      $('#m-sw-files-list').html(h);
      return;
    }

    h+='<div style="display:flex;flex-direction:column;gap:6px">';
    $.each(files,function(i,f){
      var size=Math.round(f.groesse/1024);
      var sizeStr=size>1024?(Math.round(size/1024*10)/10)+' MB':size+' kB';
      var dat=f.hochgeladen_am?de(String(f.hochgeladen_am).slice(0,10)):'';
      var icon=f.mime_type==='application/pdf'?'📄':'🖼';
      var dlUrl=LSV07I.ajax_url+'?action=lsv07i_sw_datei_download&id='+f.id+'&nonce='+encodeURIComponent(LSV07I.nonce);
      var kom=f.kommentar?'<div style="font-size:11.5px;color:var(--c-text-3);margin-top:2px">'+esc(f.kommentar)+'</div>':'';
      var delBtn=can['delete']
        ?'<button type="button" class="i-btn i-btn-r i-btn-sm swf-del" data-id="'+f.id+'" title="Löschen" style="flex-shrink:0">✕</button>'
        :'';
      h+='<div style="display:flex;align-items:flex-start;gap:8px;padding:10px;background:rgba(255,255,255,0.06);border:1px solid var(--c-line);border-radius:8px">'
        +'<span style="font-size:22px;flex-shrink:0">'+icon+'</span>'
        +'<div style="flex:1;min-width:0">'
        +'<a href="'+dlUrl+'" target="_blank" style="font-size:13px;font-weight:600;color:var(--c-blue);word-break:break-all">'+esc(f.dateiname)+'</a>'
        +'<div style="font-size:11px;color:var(--c-text-3);margin-top:1px">'+sizeStr+' · '+esc(dat)+' · '+esc(f.hochgeladen_von_name||'?')+'</div>'
        +kom
        +'</div>'
        +delBtn
        +'</div>';
    });
    h+='</div>';
    $('#m-sw-files-list').html(h);
  }).fail(function(xhr){
    $('#m-sw-files-list').html('<div class="i-notice i-notice-r" style="font-size:12px">AJAX-Fehler: '+esc(errMsg(xhr))+'</div>');
  });
}

// Upload-Form ein-/ausblenden im Lese-Modal
$(document).on('click','#swf-add',function(){$('#swf-form').show();$(this).hide();});
$(document).on('click','#swf-cancel',function(){
  $('#swf-input').val('');$('#swf-kom').val('');
  $('#swf-form').hide();$('#swf-add').show();
});

// Upload aus Lese-Modal
$(document).on('click','#swf-upload',function(){
  var sid=_swfModal.sid;
  if(!sid)return;
  var fileInput=document.getElementById('swf-input');
  if(!fileInput.files||!fileInput.files.length){toast('Bitte eine Datei wählen.','err');return;}
  var file=fileInput.files[0];
  if(file.size>5*1024*1024){toast('Datei zu groß (max. 5 MB).','err');return;}

  var fd=new FormData();
  fd.append('action','lsv07i_sw_datei_upload');
  fd.append('nonce',LSV07I.nonce);
  fd.append('schwimmer_id',sid);
  fd.append('datei',file);
  fd.append('kommentar',$('#swf-kom').val()||'');

  var $btn=$(this).prop('disabled',true).text('Lädt hoch…');
  startLoader();
  $.ajax({
    url:LSV07I.ajax_url,type:'POST',data:fd,
    processData:false,contentType:false
  }).done(function(r){
    if(!r||r==='0'){toast('Endpunkt nicht erreichbar.','err');return;}
    if(!r.success){toast((r.data&&r.data.message)||'Upload fehlgeschlagen.','err');return;}
    toast(r.data.message||'Hochgeladen.');
    loadSwfModalDateien();
    // Mannschaftsliste auch neu laden, damit der Counter aktualisiert wird
    if(typeof renderMann==='function')renderMann($('#mv-filter').val()||'');
  }).fail(function(xhr){
    toast('Upload-Fehler: '+errMsg(xhr),'err');
  }).always(function(){
    $btn.prop('disabled',false).text('Hochladen');
    stopLoader();
  });
});

// Löschen aus Lese-Modal
$(document).on('click','.swf-del',function(){
  var id=$(this).data('id');
  if(!confirm('Diese Datei wirklich löschen?'))return;
  ajax('lsv07i_sw_datei_delete',{id:id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast(r.data.message||'Gelöscht.');
    loadSwfModalDateien();
    if(typeof renderMann==='function')renderMann($('#mv-filter').val()||'');
  });
});

/* ══ STAMMDATEN-ÜBERSICHT ════════════════════════════════════════ */
var S_sd={items:null,suchTimer:null};

function loadAdmStammdaten(){
  $('#sd-liste').html('<span class="i-muted">Lade…</span>');
  ajax('lsv07i_stammdaten_list').done(function(r){
    if(!r||!r.success){
      $('#sd-liste').html('<div class="i-notice i-notice-r">'+esc((r&&r.data&&r.data.message)||'Fehler.')+'</div>');
      return;
    }
    // Der Endpunkt liefert eine Liste; kommt etwas anderes an, hier
    // auffangen statt später beim Filtern abzustürzen.
    var liste=r.data;
    if(liste && !Array.isArray(liste)) liste=liste.rows||liste.items||[];
    S_sd.items=Array.isArray(liste)?liste:[];
    renderStammdaten();
  }).fail(function(xhr){
    $('#sd-liste').html('<div class="i-notice i-notice-r">AJAX-Fehler: '+esc(errMsg(xhr))+'</div>');
  });
}

function renderStammdaten(){
  if(!Array.isArray(S_sd.items))return;
  var sparte=$('#sd-sparte').val(),typ=$('#sd-typ').val(),
      such=($('#sd-suche').val()||'').toLowerCase().trim();
  var items=S_sd.items.filter(function(i){
    if(sparte&&i.sparte!==sparte)return false;
    if(typ&&i.typ!==typ)return false;
    if(such&&i.name.toLowerCase().indexOf(such)<0&&(i.detail||'').toLowerCase().indexOf(such)<0)return false;
    return true;
  });
  if(!items.length){
    $('#sd-liste').html('<span class="i-muted">Keine Einträge gefunden.</span>');
    return;
  }
  var sparteLabel={schwimmen:'Schwimmen',triathlon:'Triathlon',fitness:'Fitness'};
  var typLabel={trainer:'Trainer',sportler:'Sportler',mannschaft:'Mannschaft'};
  var sparteBdg={schwimmen:'b',triathlon:'g',fitness:'a'};
  var h='<div style="margin-bottom:10px;font-size:12px;color:var(--c-text-3)">'+items.length+' von '+S_sd.items.length+' Einträgen</div>'
    +'<div class="i-twrap"><table class="i-tbl">'
    +'<thead><tr><th>Name</th><th>Sparte</th><th>Typ</th><th>Detail</th><th></th></tr></thead><tbody>';
  $.each(items,function(i,it){
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(it.name)+'</strong></td>'
      +'<td data-label="Sparte">'+bdg(sparteBdg[it.sparte]||'b',sparteLabel[it.sparte]||it.sparte)+'</td>'
      +'<td data-label="Typ">'+esc(typLabel[it.typ]||it.typ)+'</td>'
      +'<td data-label="Detail">'+esc(it.detail||'')+'</td>'
      +'<td style="text-align:right"><button class="i-btn i-btn-sm sd-edit" data-panel="'+esc(it.panel)+'" data-id="'+it.id+'">Öffnen →</button></td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#sd-liste').html(h);
}

// Filter-Events
$(document).on('change','#sd-sparte, #sd-typ',renderStammdaten);
$(document).on('input','#sd-suche',function(){
  clearTimeout(S_sd.suchTimer);
  S_sd.suchTimer=setTimeout(renderStammdaten,150);
});

// "Öffnen" wechselt zum passenden Detail-Tab
$(document).on('click','.sd-edit',function(){
  var panel=$(this).data('panel');
  // Tab aktivieren
  $('#i-sec-a .i-tab').removeClass('on');
  $('#i-sec-a .i-tab[data-panel="'+panel+'"]').addClass('on');
  $('#i-sec-a .i-panel').hide();
  $('#'+panel).show();
});

/* ══ LOG-ANSICHT ═════════════════════════════════════════════════ */
$('#log-laden').on('click',function(){
  var $b=$(this).prop('disabled',true).text('Lädt…');
  skel($('#log-liste'),'rows',8);
  ajax('lsv07i_log_query',{
    search:$('#log-search').val()||'',
    bereich:$('#log-bereich').val()||'',
    von:$('#log-von').val()||'',
    bis:$('#log-bis').val()||'',
    limit:100
  }).done(function(r){
    if(!r||!r.success){
      $('#log-liste').html('<div class="i-notice i-notice-r">'+esc((r&&r.data&&r.data.message)||'Fehler.')+'</div>');
      return;
    }
    var rows=r.data.rows||[],total=r.data.total||0;
    if(!rows.length){
      $('#log-liste').html('<span class="i-muted">Keine Einträge mit diesen Filtern.</span>');
      return;
    }
    var h='<div style="margin-bottom:10px;font-size:12px;color:var(--c-text-3)">'+rows.length+' von '+total+' Einträgen (max. 100 pro Abfrage)</div>'
      +'<div class="i-twrap"><table class="i-tbl">'
      +'<thead><tr><th>Zeit</th><th>Benutzer</th><th>Aktion</th><th>Bereich</th><th>Ziel</th><th>Details</th></tr></thead><tbody>';
    $.each(rows,function(i,e){
      var zeit=e.zeitpunkt?String(e.zeitpunkt).replace('T',' ').slice(0,19):'';
      var aktBdg=e.erfolg==1?'g':'r';
      var ziel=e.ziel_name||e.ziel_id||'';
      h+='<tr>'
        +'<td data-label="Zeit" style="font-size:11.5px;color:var(--c-text-3);white-space:nowrap">'+esc(zeit)+'</td>'
        +'<td data-label="Benutzer"><strong>'+esc(e.user_name||'(System)')+'</strong></td>'
        +'<td data-label="Aktion">'+bdg(aktBdg,esc(e.aktion))+'</td>'
        +'<td data-label="Bereich">'+esc(e.bereich||'')+'</td>'
        +'<td data-label="Ziel">'+esc(ziel)+'</td>'
        +'<td data-label="Details" style="font-size:12px;color:var(--c-text-2)">'+esc(e.details||'')+'</td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $('#log-liste').html(h);
  }).fail(function(xhr){
    $('#log-liste').html('<div class="i-notice i-notice-r">AJAX-Fehler: '+esc(errMsg(xhr))+'</div>');
  }).always(function(){
    $b.prop('disabled',false).text('Aktualisieren');
  });
});

/* ══ SAISON-VERWALTUNG ═══════════════════════════════════════════ */
var S_saison={items:null,aktivId:0};

function loadSaisons(){
  $('#saison-liste').html('<span class="i-muted">Lade…</span>');
  ajax('lsv07i_saison_list').done(function(r){
    if(!r||!r.success){
      $('#saison-liste').html('<div class="i-notice i-notice-r">'+esc((r&&r.data&&r.data.message)||'Fehler.')+'</div>');
      return;
    }
    S_saison.items=r.data.saisons||[];
    S_saison.aktivId=parseInt(r.data.aktiv_id||0,10);
    renderSaisons();
  }).fail(function(xhr){
    $('#saison-liste').html('<div class="i-notice i-notice-r">AJAX: '+esc(errMsg(xhr))+'</div>');
  });
}

function renderSaisons(){
  if(!S_saison.items)return;
  if(!S_saison.items.length){
    $('#saison-liste').html('<span class="i-muted">Noch keine Saison angelegt. Klicke oben auf „+ Neue Saison".</span>');
    return;
  }
  var h='<div class="i-twrap"><table class="i-tbl">'
    +'<thead><tr><th>Name</th><th>Zeitraum</th><th>Status</th><th></th></tr></thead><tbody>';
  $.each(S_saison.items,function(i,s){
    var aktiv=parseInt(s.aktiv,10)===1;
    var ende=s.ende_datum?de(s.ende_datum):'<span style="color:var(--c-text-3)">offen</span>';
    var statusBdg=aktiv
      ?'<span class="i-bdg i-bdg-g">● Aktiv</span>'
      :'<span class="i-bdg" style="background:rgba(255,255,255,0.10);color:#6b6e85">Inaktiv</span>';
    h+='<tr>'
      +'<td data-label="Name"><strong>'+esc(s.name)+'</strong></td>'
      +'<td data-label="Zeitraum">'+de(s.start_datum)+' – '+ende+'</td>'
      +'<td data-label="Status">'+statusBdg+'</td>'
      +'<td style="text-align:right;white-space:nowrap">'
      +(aktiv
        ?''
        :'<button class="i-btn i-btn-sm i-btn-p saison-akt" data-id="'+s.id+'" data-name="'+esc(s.name)+'">Aktivieren</button> ')
      +'<button class="i-btn i-btn-sm saison-edit" data-id="'+s.id+'">Bearbeiten</button> '
      +(aktiv
        ?''
        :'<button class="i-btn i-btn-sm i-btn-r saison-del" data-id="'+s.id+'" data-name="'+esc(s.name)+'">Löschen</button>')
      +'</td>'
      +'</tr>';
  });
  h+='</tbody></table></div>';
  $('#saison-liste').html(h);
}

// "+ Neue Saison" — Modal im Anlege-Modus öffnen
$(document).on('click','#saison-neu',function(){
  $('#saison-id').val('');
  $('#saison-name').val('');
  $('#saison-start').val('');
  $('#saison-ende').val('');
  $('#m-saison-ttl').text('Neue Saison anlegen');
  openModal('m-saison');
});

// Bearbeiten
$(document).on('click','.saison-edit',function(){
  var id=$(this).data('id');
  var s=null;
  $.each(S_saison.items||[],function(i,it){if(parseInt(it.id,10)===parseInt(id,10))s=it;});
  if(!s){toast('Saison nicht gefunden.','err');return;}
  $('#saison-id').val(s.id);
  $('#saison-name').val(s.name);
  $('#saison-start').val(s.start_datum);
  $('#saison-ende').val(s.ende_datum||'');
  $('#m-saison-ttl').text('Saison bearbeiten');
  openModal('m-saison');
});

// Speichern (anlegen oder aktualisieren)
$(document).on('click','#saison-save',function(){
  var id=parseInt($('#saison-id').val()||'0',10);
  var name=($('#saison-name').val()||'').trim();
  var start=$('#saison-start').val();
  var ende=$('#saison-ende').val();
  if(!name){toast('Bitte einen Namen eingeben.','err');return;}
  if(!start){toast('Bitte ein Start-Datum eingeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichert…');
  ajax('lsv07i_saison_save',{id:id,name:name,start_datum:start,ende_datum:ende||''}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast(r.data.message||'Gespeichert.');
    closeModal('m-saison');
    loadSaisons();
  }).fail(function(xhr){toast('Server-Fehler: '+errMsg(xhr),'err');})
    .always(function(){$b.prop('disabled',false).text('Speichern');});
});

// Aktivieren
$(document).on('click','.saison-akt',function(){
  var id=$(this).data('id'),name=$(this).data('name');
  if(!confirm('Saison „'+name+'" jetzt aktivieren?\n\nDie bisher aktive Saison wird inaktiv gesetzt. Alle neu angelegten Trainingszeiten werden ab sofort dieser Saison zugeordnet.\n\nAlte Trainingszeiten bleiben erhalten — die historischen Anwesenheits- und Abrechnungsdaten greifen weiterhin darauf zu.'))return;
  ajax('lsv07i_saison_activate',{id:id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast(r.data.message||'Aktiviert.');
    loadSaisons();
  });
});

// Löschen
$(document).on('click','.saison-del',function(){
  var id=$(this).data('id'),name=$(this).data('name');
  if(!confirm('Saison „'+name+'" wirklich löschen?\n\nDie zugehörigen Trainingszeiten bleiben in der DB, werden aber als „Unbekannte Saison" angezeigt. Die Anwesenheit/Abrechnung ist davon NICHT betroffen.'))return;
  ajax('lsv07i_saison_delete',{id:id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast(r.data.message||'Gelöscht.');
    loadSaisons();
  });
});

/* ══ CHAT (WhatsApp-Style) ═══════════════════════════════════════
   Konversations-Liste links, aktiver Chat rechts, mobile einspaltig.
   Polling alle 30 Sek für Neue Nachrichten und Ungelesen-Zähler.        */
var Chat={
  konvs:[],            // alle Konversationen des Users
  current:null,        // aktive konv_id
  meinUserId:0,
  pollTimer:null,
  msgPollTimer:null,
  lastMsgId:0,         // letzte gesehene Nachricht-ID im aktiven Chat
  newType:'direkt',    // im "Neuer Chat"-Modal aktiver Typ
  newTeilnehmer:[],    // gewählte Teilnehmer im Modal
  attachFile:null,     // hochgeladene Datei für nächste Nachricht (Iter folgend)
  userSearchTimer:null,
  // ── Push-Benachrichtigungen ────────────────────────────────────
  knownLastMsgIds:{},  // konv_id => letzte beobachtete msg-id (für "neu seit letztem Poll")
  notifInited:false,   // erster Poll setzt Baseline ohne Popups
  // ── Sofortversand ──────────────────────────────────────────────
  vorlaeufigZaehler:0, // fortlaufende ID für noch nicht bestätigte Nachrichten
  fehlgeschlagen:{},   // vorläufige ID => Daten, um erneut senden zu können
  folgtUnten:true,     // Verlauf bleibt am Ende, bis der Nutzer hochscrollt
  meinBild:null,       // eigenes Profilbild (für die eigenen Bubbles)
  meineInitialen:'',
};

// ─── Liste laden ──────────────────────────────────────────────────
function chatLoadList(){
  ajax('lsv07i_konv_list').done(function(r){
    if(!r||!r.success){return;}
    Chat.konvs=r.data.konversationen||[];
    Chat.meinUserId=parseInt(r.data.user_id||0,10);
    chatDetectNewMessages();
    chatRenderList();
  });
}

// Prüft pro Konv ob neue Nachrichten reingekommen sind seit letztem Poll
// und löst Popup-Benachrichtigungen aus. Beim allerersten Poll nur Baseline
// setzen, kein Popup (sonst kommt's beim Seitenladen sofort).
function chatDetectNewMessages(){
  var trigger=[];
  $.each(Chat.konvs||[],function(i,k){
    var konvId=parseInt(k.id,10);
    var lastId=k.letzte_msg?parseInt(k.letzte_msg.id||0,10):0;
    var seen=Chat.knownLastMsgIds[konvId]||0;
    if(Chat.notifInited
       && lastId>seen
       && k.letzte_msg
       && !(k.letzte_msg.von_typ==='user' && parseInt(k.letzte_msg.von_id,10)===Chat.meinUserId)
       && konvId!==Chat.current){
      trigger.push(k);
    }
    Chat.knownLastMsgIds[konvId]=lastId;
  });
  Chat.notifInited=true;
  $.each(trigger,function(i,k){chatNotify(k);});
}

// Zeigt ein Popup oben im Shortcode-Container an
function chatNotify(k){
  // Anker für das Popup: der äußere Shortcode-Container.
  var $cont=$('#lsv07i-root');
  if(!$cont.length)$cont=$('body');
  // Notification-Container (lazy anlegen)
  var $box=$('#chat-notif-box');
  if(!$box.length){
    $box=$('<div id="chat-notif-box"></div>');
    if($cont.css('position')==='static')$cont.css('position','relative');
    $cont.prepend($box);
  }
  var titel=chatKonvName(k);
  var von=k.letzte_msg&&k.letzte_msg.von_name?k.letzte_msg.von_name:'';
  var text=k.letzte_msg&&k.letzte_msg.text_preview?k.letzte_msg.text_preview:'';
  var preview=von?(von+': '+text):text;
  var html='<div class="chat-notif" data-konv="'+k.id+'">'
    +'<div class="chat-notif-title">💬 '+esc(titel)+'</div>'
    +'<div class="chat-notif-body">'+esc(preview)+'</div>'
    +'<button class="chat-notif-x" aria-label="Schließen">&times;</button>'
    +'</div>';
  var $n=$(html).appendTo($box);
  // Slide-in animieren (von -100% nach 0)
  setTimeout(function(){$n.addClass('show');},10);
  // Auto-Hide nach 5 Sek
  var timer=setTimeout(function(){chatNotifClose($n);},5000);
  $n.data('autoTimer',timer);
}

function chatNotifClose($n){
  clearTimeout($n.data('autoTimer'));
  $n.removeClass('show').addClass('hide');
  setTimeout(function(){$n.remove();},300);
}

// Klick auf Popup öffnet die Konversation
$(document).on('click','.chat-notif',function(e){
  if($(e.target).hasClass('chat-notif-x'))return;
  var kid=parseInt($(this).data('konv'),10);
  chatNotifClose($(this));
  // Section-Wechsel falls nicht im Nachrichten-Bereich
  if(!$('#i-sec-n').is(':visible')){
    $('#nav-nachrichten').click();
    setTimeout(function(){chatOpen(kid);},150);
  } else {
    chatOpen(kid);
  }
});

$(document).on('click','.chat-notif-x',function(e){
  e.stopPropagation();
  chatNotifClose($(this).closest('.chat-notif'));
});

function chatRenderList(){
  var $list=$('#chat-list');
  if(!Chat.konvs.length){
    $list.html('<div class="chat-empty-inhalt" style="padding:30px 20px">'
      +'<p>Noch keine Unterhaltungen.<br>Oben rechts auf <strong>+</strong> tippen, um eine zu starten.</p></div>');
    return;
  }
  var such=($('#chat-search').val()||'').toLowerCase().trim();
  var h='';
  $.each(Chat.konvs,function(i,k){
    var name=chatKonvName(k);
    var roh=k.letzte_msg?(k.letzte_msg.text_preview||''):'';
    var preview=k.letzte_msg?(roh.trim()==='[Datei]'?'📎 Datei':roh):'(noch keine Nachricht)';
    var von=k.letzte_msg&&k.letzte_msg.von_name?esc(k.letzte_msg.von_name)+': ':'';
    if(such&&name.toLowerCase().indexOf(such)<0&&preview.toLowerCase().indexOf(such)<0)return;
    var zeit=k.letzte_aktivitaet?chatFormatZeit(k.letzte_aktivitaet):'';
    var ungel=parseInt(k.ungelesen||0,10);
    var typBdg=k.typ==='gruppe'?'<span class="chat-item-typ gruppe">Gruppe</span>'
              :k.typ==='verteiler'?'<span class="chat-item-typ verteiler">Verteiler</span>'
              :'';
    var klassen='chat-item'
      +(Chat.current===parseInt(k.id,10)?' active':'')
      +(ungel>0?' ungelesen':'');
    h+='<div class="'+klassen+'" data-id="'+k.id+'">'
      +'<div class="chat-item-avatar">'+chatKonvAvatar(k,name)+'</div>'
      +'<div class="chat-item-mitte">'
      +'<div class="chat-item-row">'
      +'<div class="chat-item-name">'+typBdg+esc(name)+'</div>'
      +'<div class="chat-item-time">'+esc(zeit)+'</div>'
      +'</div>'
      +'<div class="chat-item-row">'
      +'<div class="chat-item-preview">'+von+esc(preview)+'</div>'
      +(ungel>0?'<span class="chat-item-unread">'+(ungel>99?'99+':ungel)+'</span>':'')
      +'</div>'
      +'</div>'
      +'<button type="button" class="chat-item-mehr" data-id="'+k.id+'"'
      +' aria-label="Weitere Aktionen" title="Weitere Aktionen">'
      +'<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8"/>'
      +'<circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>'
      +'</button>'
      +'</div>';
  });
  $list.html(h||'<div class="chat-empty-inhalt" style="padding:30px 20px"><p>Keine Treffer.</p></div>');
}


/* ── Menü einer Unterhaltung in der Liste ────────────────────────
   Öffnen, Info oder aus der Liste entfernen. Bei Gruppen heißt der
   letzte Punkt je nach Rolle "verlassen" oder "löschen". */
Chat.menueKonv=null;

$(document).on('click','.chat-item-mehr',function(e){
  e.stopPropagation();
  var kid=parseInt($(this).data('id'),10);
  var k=null;
  $.each(Chat.konvs||[],function(i,x){ if(parseInt(x.id,10)===kid){k=x;return false;} });
  if(!k)return;
  Chat.menueKonv=k;

  var istDirekt=k.typ==='direkt';
  var binAdmin=parseInt(k.mein_admin||0,10)===1;
  var $eintrag=$('#chat-item-menu .chat-item-menu-eintrag[data-aktion="entfernen"]');
  var txt = istDirekt ? 'Aus Liste entfernen'
          : (binAdmin ? 'Gruppe löschen' : 'Gruppe verlassen');
  $eintrag.find('.chat-item-menu-txt').text(txt);

  var $menu=$('#chat-item-menu');
  var r=this.getBoundingClientRect();
  $menu.addClass('offen');
  var breite=$menu.outerWidth(), hoehe=$menu.outerHeight();
  var links=Math.min(r.right-breite, window.innerWidth-breite-8);
  var oben =r.bottom+6;
  if(oben+hoehe>window.innerHeight-8) oben=Math.max(8, r.top-hoehe-6);
  $menu.css({left:Math.max(8,links)+'px', top:oben+'px'});
});

function chatItemMenuZu(){ $('#chat-item-menu').removeClass('offen'); Chat.menueKonv=null; }
$(document).on('click',function(e){
  if(!$(e.target).closest('#chat-item-menu, .chat-item-mehr').length) chatItemMenuZu();
});
$(document).on('keydown',function(e){ if(e.which===27)chatItemMenuZu(); });

$(document).on('click','.chat-item-menu-eintrag',function(){
  var k=Chat.menueKonv;
  var aktion=$(this).data('aktion');
  chatItemMenuZu();
  if(!k)return;
  var kid=parseInt(k.id,10);

  if(aktion==='oeffnen'){ chatOpen(kid); return; }
  if(aktion==='info'){ chatOpen(kid); setTimeout(function(){ $('#chat-info').trigger('click'); },350); return; }

  // Entfernen / verlassen / löschen
  var istDirekt=k.typ==='direkt';
  var binAdmin=parseInt(k.mein_admin||0,10)===1;
  var name=chatKonvName(k);
  var frage, action;
  if(istDirekt){
    frage='Unterhaltung mit „'+name+'“ aus deiner Liste entfernen?\n\n'
         +'Der Verlauf bleibt bei der anderen Person erhalten. Schreibt sie dir erneut, '
         +'erscheint die Unterhaltung wieder.';
    action='lsv07i_konv_leave';
  } else if(binAdmin){
    frage='Gruppe „'+name+'“ endgültig löschen?\n\n'
         +'Alle Nachrichten und Dateien dieser Gruppe werden für alle Teilnehmer entfernt. '
         +'Das lässt sich nicht rückgängig machen.';
    action='lsv07i_konv_delete_konv';
  } else {
    frage='Gruppe „'+name+'“ verlassen?\n\nDu bekommst keine neuen Nachrichten mehr aus dieser Gruppe.';
    action='lsv07i_konv_leave';
  }
  if(!confirm(frage))return;

  ajax(action,{konv_id:kid}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Aktion fehlgeschlagen.','err');return;}
    toast((r.data&&r.data.message)||'Erledigt.');
    if(Chat.current===kid){
      Chat.current=null;
      $('#chat-view').hide();
      $('#chat-empty').show();
      $('#chat-wrap').removeClass('show-right');
    }
    chatLoadList();
    chatUpdateUnreadBadge();
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});

// Bild bzw. Initialen für eine Unterhaltung in der Liste
function chatKonvAvatar(k,name){
  if(k.typ==='direkt'&&k.partner_bild){
    return '<img src="'+esc(k.partner_bild)+'" alt="">';
  }
  if(k.typ==='gruppe'||k.typ==='verteiler'){
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:21px;height:21px"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
  }
  var t=(name||'?').trim().split(/\s+/);
  var init=t.length>1?(t[0].charAt(0)+t[1].charAt(0)):(name||'?').slice(0,2);
  return esc(init.toUpperCase());
}

// Anzeige-Namen für eine Konversation bestimmen
function chatKonvName(k){
  if(k.name)return k.name;
  if(k.typ==='direkt'){
    // Anderer Teilnehmer
    var other=null;
    $.each(k.teilnehmer||[],function(i,t){
      if(t.teilnehmer_typ==='user'&&parseInt(t.teilnehmer_id,10)!==Chat.meinUserId){
        other=t.teilnehmer_name||('User '+t.teilnehmer_id);
      }
    });
    return other||'Direkt-Chat';
  }
  return '('+k.typ+')';
}

// Zeit-Formatierung: heute = HH:mm, sonst Datum
function chatFormatZeit(dt){
  if(!dt)return '';
  var d=new Date(String(dt).replace(' ','T'));
  if(isNaN(d.getTime()))return '';
  var heute=new Date();
  var ds=d.toDateString()===heute.toDateString();
  if(ds){
    return ('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2);
  }
  return ('0'+d.getDate()).slice(-2)+'.'+('0'+(d.getMonth()+1)).slice(-2);
}

// ─── Konversation öffnen ──────────────────────────────────────────
$(document).on('click','.chat-item',function(){
  var id=parseInt($(this).data('id'),10);
  chatOpen(id);
});

function chatOpen(id){
  Chat.current=id;
  Chat.lastMsgId=0;
  $('.chat-item').removeClass('active').filter('[data-id="'+id+'"]').addClass('active');
  $('#chat-empty').hide();
  $('#chat-view').show();
  $('#chat-wrap').addClass('show-right');

  // Titel & Meta setzen
  var k=null;
  $.each(Chat.konvs,function(i,c){if(parseInt(c.id,10)===id)k=c;});
  if(k){
    $('#chat-view-title').text(chatKonvName(k));
    var anz=(k.teilnehmer||[]).length;
    var meta=k.typ==='direkt'?'Direkt-Chat':(anz+' Teilnehmer');
    // Bei Direkt-Chats: Online-Punkt des anderen User in der Meta-Zeile
    if(k.typ==='direkt'){
      var otherUid=null;
      $.each(k.teilnehmer||[],function(i,t){
        if(t.teilnehmer_typ==='user'&&parseInt(t.teilnehmer_id,10)!==Chat.meinUserId){
          otherUid=parseInt(t.teilnehmer_id,10);
        }
      });
      if(otherUid){
        meta='<span class="online-dot" data-uid="'+otherUid+'"></span>'+meta;
        $('#chat-view-meta').html(meta);
        chatUpdateOnlineDots();
      } else {
        $('#chat-view-meta').text(meta);
      }
    } else {
      $('#chat-view-meta').text(meta);
    }
  }
  $('#chat-messages').html('<div style="text-align:center;color:#7c7f94;font-size:12px;padding:20px">Lade…</div>');
  chatLoadMessages(true);
  chatLoadReadStatus();
  chatHeartbeat();
  chatStartMsgPolling();
  chatTypingStartPolling();
}

// Zurück (mobile)
$(document).on('click','#chat-back',function(){
  $('#chat-wrap').removeClass('show-right');
  Chat.current=null;
  chatStopMsgPolling();
  chatTypingStopPolling();
});

// ─── Nachrichten laden ────────────────────────────────────────────
function chatLoadMessages(scrollEnd){
  if(!Chat.current)return;
  ajax('lsv07i_konv_messages',{konv_id:Chat.current,since:Chat.lastMsgId}).done(function(r){
    if(!r||!r.success)return;
    var msgs=r.data.messages||[];
    if(!msgs.length){
      if(Chat.lastMsgId===0){
        $('#chat-messages').html('<div style="text-align:center;color:#7c7f94;font-size:12px;padding:20px">Noch keine Nachrichten in diesem Chat.</div>');
      }
      return;
    }
    var $box=$('#chat-messages');
    if(Chat.lastMsgId===0){$box.empty();}
    var $bottom=$();
    $.each(msgs,function(i,m){
      // Falls diese Nachricht schon optimistisch angezeigt wurde (eigener
      // Versand, siehe chatSend), das vorläufige Element ERSETZEN statt
      // ein zweites Mal anzuhängen — verhindert doppelte Bubbles, sobald
      // der reguläre Nachlade-Zyklus dieselbe Nachricht (jetzt evtl. mit
      // Anhang/Reaktionen) vom Server bestätigt.
      var $vorhanden=$box.find('[data-id="'+m.id+'"]');
      if($vorhanden.length){
        $vorhanden.replaceWith(chatRenderMsg(m));
      } else {
        $box.append(chatRenderMsg(m));
      }
      Chat.lastMsgId=Math.max(Chat.lastMsgId,parseInt(m.id,10));
    });
    // Aufeinanderfolgende Nachrichten derselben Person: Profilbild nur
    // beim ersten Beitrag zeigen, danach nur den Platz freihalten.
    chatAvatareAusduennen();
    if(scrollEnd)chatNachUnten();

    // Read-Receipts für die neuen Nachrichten setzen (nutzt bereits geladene
    // Chat.readers — keine extra Server-Anfrage)
    chatUpdateReadReceipts();

    // Als gelesen markieren
    ajax('lsv07i_konv_mark_read',{konv_id:Chat.current,msg_id:Chat.lastMsgId});
  });
}

// Read-Status aller Teilnehmer der aktuellen Konv laden + Anzeige aktualisieren.
// Wird parallel zum Nachrichten-Polling aufgerufen.
function chatLoadReadStatus(){
  if(!Chat.current)return;
  ajax('lsv07i_konv_read_status',{konv_id:Chat.current}).done(function(r){
    if(!r||!r.success)return;
    Chat.readers=r.data.readers||[];
    chatUpdateReadReceipts();
  });
}

// Aktualisiert die "✓✓ gelesen von ..."-Anzeige unter eigenen Nachrichten.
function chatUpdateReadReceipts(){
  var uid=Chat.meinUserId;
  $('#chat-messages .chat-msg.from-me').each(function(){
    var $msg=$(this);
    var mid=parseInt($msg.data('id'),10);
    if(!mid)return;
    // Alle Teilnehmer (außer mir) die diese Msg-ID schon gelesen haben
    var leser=[];
    $.each(Chat.readers||[],function(i,r){
      if(r.typ==='user'&&r.id===uid)return; // mich selbst überspringen
      if(r.gelesen_bis>=mid)leser.push(r);
    });
    var $hint=$msg.find('.chat-msg-read');
    if(!leser.length){
      $hint.remove();
      return;
    }
    // Render: "✓✓ gelesen" oder "✓✓ Name, Name, …"
    var anz=leser.length;
    var totalOther=0;
    $.each(Chat.readers||[],function(i,r){if(!(r.typ==='user'&&r.id===uid))totalOther++;});
    var label;
    if(anz===totalOther && anz>0){
      label='alle gelesen';
    } else if(anz<=2){
      label=$.map(leser,function(l){return l.name;}).join(', ');
    } else {
      label=anz+' gelesen';
    }
    var html='<span class="check">✓✓</span><span class="names">'+esc(label)+'</span>';
    if($hint.length){
      $hint.html(html).data('count',anz);
    } else {
      $msg.append('<div class="chat-msg-read" data-mid="'+mid+'">'+html+'</div>');
    }
  });
}

// Klick auf "Read-Receipt" → Details aufklappen (alle Leser mit Status)
$(document).on('click','.chat-msg-read',function(e){
  e.stopPropagation();
  var $r=$(this);
  var $msg=$r.closest('.chat-msg');
  var mid=parseInt($msg.data('id'),10);
  var $det=$msg.find('.chat-msg-read-detail');
  if($det.length){$det.remove();return;}

  var uid=Chat.meinUserId;
  var gelesen=[], offen=[];
  $.each(Chat.readers||[],function(i,r){
    if(r.typ==='user'&&r.id===uid)return;
    if(r.gelesen_bis>=mid)gelesen.push(r);
    else offen.push(r);
  });
  var h='';
  if(gelesen.length){
    h+='<div class="group"><strong>✓✓ Gelesen:</strong> '
      +$.map(gelesen,function(l){return esc(l.name);}).join(', ')+'</div>';
  }
  if(offen.length){
    h+='<div class="group"><strong>✓ Zugestellt:</strong> '
      +$.map(offen,function(l){return esc(l.name);}).join(', ')+'</div>';
  }
  $msg.append('<div class="chat-msg-read-detail">'+h+'</div>');
});


// Profilbild oder Initialen für eine Chat-Nachricht. Bei eigenen Nachrichten
// greifen wir auf die im Einstellungs-Dialog gepflegten Werte zurück, damit
// ein frisch hochgeladenes Bild sofort erscheint.

// ─── Anhänge: URL, Typ-Erkennung, Größe, Vollbild ─────────────────
function chatAnhangUrl(token){
  return LSV07I.ajax_url+'?action=lsv07i_konv_anhang_dl&token='+encodeURIComponent(token||'');
}
function chatIstBild(a){
  if(a.mime_type && a.mime_type.indexOf('image/')===0) return true;
  return /\.(jpe?g|png|webp|gif)$/i.test(a.dateiname||'');
}
function chatDateiIcon(a){
  var pdf=(a.mime_type==='application/pdf')||/\.pdf$/i.test(a.dateiname||'');
  return pdf
    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
}
function chatGroesse(bytes){
  var b=parseInt(bytes,10)||0;
  if(b<1024)return b+' B';
  if(b<1024*1024)return Math.round(b/1024)+' kB';
  return (Math.round(b/1024/1024*10)/10).toString().replace('.',',')+' MB';
}

// Vollbild-Ansicht für Bilder — bleibt auf derselben Seite
$(document).on('click','.chat-bild',function(){
  var url=$(this).data('url'), name=$(this).data('name');
  $('#chat-lightbox').remove();
  var $lb=$('<div id="chat-lightbox" class="chat-lightbox">'
    +'<div class="chat-lightbox-kopf">'
    +'<span class="chat-lightbox-name"></span>'
    +'<a class="chat-lightbox-dl" target="_blank" rel="noopener" title="In neuem Tab öffnen">↗</a>'
    +'<button type="button" class="chat-lightbox-zu" aria-label="Schließen">&#10005;</button>'
    +'</div>'
    +'<div class="chat-lightbox-bild"><img alt=""></div>'
    +'</div>');
  $lb.find('.chat-lightbox-name').text(name||'');
  $lb.find('.chat-lightbox-dl').attr('href',url);
  $lb.find('img').attr('src',url);
  $('body').append($lb);
  $lb.on('click',function(e){
    if(e.target===this||$(e.target).hasClass('chat-lightbox-zu')||$(e.target).closest('.chat-lightbox-zu').length){
      $lb.remove();
    }
  });
});
$(document).on('keydown',function(e){ if(e.which===27)$('#chat-lightbox').remove(); });

// Bild im Chat lässt sich nicht laden → verständlicher Hinweis statt
// eines kaputten Bildsymbols. Das error-Ereignis von <img> steigt NICHT
// auf, deshalb in der Capture-Phase am Dokument lauschen (jQuery-Delegation
// greift hier nicht).
document.addEventListener('error', function(e){
  var el=e.target;
  if(!el||el.tagName!=='IMG')return;
  var knopf=el.closest&&el.closest('.chat-bild');
  if(knopf){
    var ersatz=document.createElement('a');
    ersatz.className='chat-msg-anhang';
    ersatz.href=knopf.getAttribute('data-url')||'#';
    ersatz.target='_blank'; ersatz.rel='noopener';
    ersatz.innerHTML='<span class="chat-anhang-ico">'
      +'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
      +'<circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="13"/>'
      +'<circle cx="12" cy="16.5" r=".6" fill="currentColor"/></svg></span>'
      +'<span class="chat-anhang-txt">'
      +'<span class="chat-anhang-name">'+(knopf.getAttribute('data-name')||'Bild')+'</span>'
      +'<span class="chat-anhang-meta">Vorschau nicht möglich — hier öffnen</span></span>';
    knopf.replaceWith(ersatz);
    return;
  }
  // Profilbild kaputt → auf Initialen zurückfallen
  var av=el.closest&&el.closest('.chat-msg-avatar, .i-avatar, .chat-item-avatar');
  if(av){ av.innerHTML='<span class="i-avatar-init">?</span>'; }
}, true);

function chatAvatar(m){
  var bild = m.von_bild || '';
  var init = m.von_initialen || '';
  var fromMe = m.von_typ==='user' && parseInt(m.von_id,10)===Chat.meinUserId;
  if(fromMe){
    if(Chat.meinBild!==undefined && Chat.meinBild!==null) bild = Chat.meinBild;
    if(Chat.meineInitialen) init = Chat.meineInitialen;
  }
  if(bild) return '<img src="'+esc(bild)+'" alt="">';
  if(!init){
    var n=(m.von_name||'?').trim().split(/\s+/);
    init = n.length>1 ? (n[0].charAt(0)+n[1].charAt(0)) : (m.von_name||'?').slice(0,2);
  }
  return '<span class="i-avatar-init">'+esc(init.toUpperCase())+'</span>';
}


// Blendet das Profilbild bei direkt aufeinanderfolgenden Nachrichten
// derselben Person aus (der Platz bleibt erhalten, damit nichts springt).

/* Ans Ende scrollen — und dort bleiben, während Bilder nachladen.
   Bilder haben beim Einfügen noch keine Höhe; ohne Nachziehen bleibt der
   Verlauf oberhalb der neuen Nachricht stehen. Scrollt der Nutzer selbst
   nach oben, wird nicht mehr nachgezogen. */
function chatNachUnten(){
  var box=document.getElementById('chat-messages');
  if(!box)return;
  Chat.folgtUnten=true;
  box.scrollTop=box.scrollHeight;
  var bilder=box.querySelectorAll('img');
  for(var i=0;i<bilder.length;i++){
    if(bilder[i].complete)continue;
    bilder[i].addEventListener('load',function(){
      if(Chat.folgtUnten)box.scrollTop=box.scrollHeight;
    },{once:true});
    bilder[i].addEventListener('error',function(){
      if(Chat.folgtUnten)box.scrollTop=box.scrollHeight;
    },{once:true});
  }
}

// Scrollt der Nutzer selbst nach oben, hört das Nachziehen auf
$(document).on('scroll','#chat-messages',function(){
  var b=this;
  Chat.folgtUnten = (b.scrollHeight-b.scrollTop-b.clientHeight) < 60;
});

function chatAvatareAusduennen(){
  var vorher=null;
  $('#chat-messages .chat-msg').each(function(){
    var $m=$(this);
    if($m.hasClass('from-system')){ vorher=null; return; }
    var wer=$m.attr('data-mine')+'|'+$m.find('.chat-msg-avatar').text();
    $m.toggleClass('gleiche-person', wer===vorher);
    vorher=wer;
  });
}

function chatRenderMsg(m){
  var fromMe=m.von_typ==='user'&&parseInt(m.von_id,10)===Chat.meinUserId;
  var fromSystem=m.von_typ==='system';
  var isDeleted=!!m.geloescht_am;
  var isEdited=!!m.bearbeitet_am;
  var cls=fromSystem?'from-system':(fromMe?'from-me':'from-them');
  if(parseInt(m.dringend,10))cls+=' dringend';
  if(isDeleted)cls+=' deleted';
  var name=m.von_name||(m.von_typ==='email'?m.von_email:'?');
  var zeit=m.erstellt_am?chatFormatZeit(m.erstellt_am):'jetzt';
  var meta=fromMe?zeit:(esc(name)+' · '+esc(zeit));
  if(m.von_typ!=='user'&&m.von_typ!=='system'&&!fromMe){
    meta=esc(name)+' (extern) · '+esc(zeit);
  }
  if(isEdited&&!isDeleted){
    meta+=' <span class="chat-msg-edited">(bearbeitet)</span>';
  }
  // Textinhalt. Der Platzhalter "[Datei]" wird nur gesendet, weil eine
  // Nachricht nicht leer sein darf — steht der Anhang daneben, ist er
  // überflüssig und wird nicht angezeigt.
  var hatAnhang=!!(m.anhaenge&&m.anhaenge.length);
  var text=String(m.text||'');
  if(hatAnhang&&text.trim()==='[Datei]')text='';
  var bubbleContent;
  if(isDeleted){
    bubbleContent='<em>Diese Nachricht wurde gelöscht.</em>';
  } else {
    bubbleContent=esc(text).replace(/\n/g,'<br>');
  }
  // Anhänge nur wenn nicht gelöscht.
  // Bilder werden direkt in der Nachricht gezeigt und öffnen sich beim
  // Antippen in einer Vollbild-Ansicht auf derselben Seite. Das umgeht
  // neue Tabs, die auf dem Handy oft blockiert werden oder ins Leere
  // führen. Andere Dateien bekommen eine deutliche Dateizeile.
  var anh='';
  if(!isDeleted&&m.anhaenge&&m.anhaenge.length){
    $.each(m.anhaenge,function(i,a){
      var url=chatAnhangUrl(a.token);
      if(chatIstBild(a)){
        anh+='<button type="button" class="chat-bild" data-url="'+esc(url)+'"'
          +' data-name="'+esc(a.dateiname)+'">'
          +'<img src="'+esc(url)+'" alt="'+esc(a.dateiname)+'" loading="lazy">'
          +'</button>';
      } else {
        anh+='<a class="chat-msg-anhang" href="'+url+'" target="_blank" rel="noopener"'
          +' data-name="'+esc(a.dateiname)+'">'
          +'<span class="chat-anhang-ico">'+chatDateiIcon(a)+'</span>'
          +'<span class="chat-anhang-txt">'
          +'<span class="chat-anhang-name">'+esc(a.dateiname)+'</span>'
          +'<span class="chat-anhang-meta">'+chatGroesse(a.groesse)+'</span>'
          +'</span></a>';
      }
    });
  }
  // Reaktionen
  var rk='';
  if(m.reaktionen&&m.reaktionen.length){
    rk='<div class="chat-msg-reaktionen">';
    $.each(m.reaktionen,function(i,r){
      var mine=r.has_mine?' mine':'';
      rk+='<span class="chat-msg-reakt'+mine+'" data-msg-id="'+m.id+'" data-em="'+esc(r.emoji)+'">'
         +'<span class="em">'+esc(r.emoji)+'</span><span class="cnt">'+parseInt(r.count,10)+'</span></span>';
    });
    rk+='</div>';
  }
  // Profilbild des Absenders (eigene und fremde). Systemmeldungen haben keins.
  var avatar='';
  if(!fromSystem){
    avatar='<div class="chat-msg-avatar">'+chatAvatar(m)+'</div>';
  }
  // Zustandszeile für eigene Nachrichten: unterwegs / gesendet / Fehler
  var status='';
  if(fromMe&&!isDeleted){
    status='<div class="chat-msg-status">'+(m.unterwegs?'wird gesendet…':'')+'</div>';
  }

  // Daten-Attribute für Kontextmenü
  return '<div class="chat-msg '+cls+'" data-id="'+m.id+'" data-mine="'+(fromMe?1:0)+'" data-deleted="'+(isDeleted?1:0)+'">'
    +avatar
    +'<div class="chat-msg-inhalt">'
    +'<div class="chat-msg-meta">'+meta+'</div>'
    +'<div class="chat-msg-bubble'+(bubbleContent===''?' nur-anhang':'')+'">'+bubbleContent+anh+'</div>'
    +rk
    +status
    +'</div>'
    +'</div>';
}

// ─── Polling: alle 30 Sek Liste + offene Konv aktualisieren ───────
// Im selben Tick wird der Heartbeat geschickt (User als online markieren +
// Online-Status der aktuellen Konv-Teilnehmer abholen).
function chatStartPolling(){
  chatStopPolling();
  chatHeartbeat(); // sofort einmal beim Start
  Chat.pollTimer=setInterval(function(){
    chatLoadList();
    chatUpdateUnreadBadge();
    chatHeartbeat();
  },30000);
}
function chatStopPolling(){if(Chat.pollTimer){clearInterval(Chat.pollTimer);Chat.pollTimer=null;}}
function chatStartMsgPolling(){
  chatStopMsgPolling();
  Chat.msgPollTimer=setInterval(function(){
    if(Chat.current){
      chatLoadMessages(false);
      chatLoadReadStatus();
    }
  },10000); // schneller wenn Chat offen
}
function chatStopMsgPolling(){if(Chat.msgPollTimer){clearInterval(Chat.msgPollTimer);Chat.msgPollTimer=null;}}

// Online-Status der Teilnehmer der aktuellen Konversation
Chat.onlineMap={};   // {user_id: bool}
Chat.readers=[];     // [{typ,id,name,gelesen_bis}, ...]

function chatHeartbeat(){
  // Wir holen Online-Status nur für die Teilnehmer der aktuell offenen Konv
  // (oder leere Liste, dann markiert der Server nur uns als aktiv).
  var ids=[];
  if(Chat.current){
    var k=null;
    $.each(Chat.konvs,function(i,c){if(parseInt(c.id,10)===Chat.current)k=c;});
    if(k && k.teilnehmer){
      $.each(k.teilnehmer,function(i,t){
        if(t.teilnehmer_typ==='user')ids.push(parseInt(t.teilnehmer_id,10));
      });
    }
  }
  ajax('lsv07i_konv_heartbeat',{user_ids:ids.join(',')}).done(function(r){
    if(!r||!r.success)return;
    Chat.onlineMap=r.data.online||{};
    chatUpdateOnlineDots();
  });
}

function chatUpdateOnlineDots(){
  // Aktualisiere alle data-uid-Online-Punkte
  $('.online-dot[data-uid]').each(function(){
    var uid=$(this).data('uid');
    var on=!!Chat.onlineMap[uid];
    $(this).toggleClass('online',on).attr('title',on?'Online':'Offline');
  });
}

// Topbar-Zähler aktualisieren
function chatUpdateUnreadBadge(){
  ajax('lsv07i_konv_unread').done(function(r){
    if(!r||!r.success)return;
    var c=parseInt(r.data.count||0,10);
    var $b=$('#nav-nachrichten-badge');
    if(c>0){
      if(!$b.length){
        $('#nav-nachrichten').append('<span id="nav-nachrichten-badge" style="display:inline-block;margin-left:4px;background:#ef4444;color:#1c1d2e;border-radius:9px;padding:0 6px;font-size:11px;font-weight:600">'+c+'</span>');
      } else { $b.text(c).show(); }
    } else if($b.length){ $b.hide(); }
  });
}

// ─── Anhang: Click auf 📎 öffnet versteckten File-Input ───────────
$(document).on('click','#chat-attach',function(){
  $('#chat-file').trigger('click');
});

// Datei gewählt → Info-Zeile anzeigen, Datei in Chat.attachFile parken
$(document).on('change','#chat-file',function(){
  var f=this.files&&this.files[0];
  if(!f){Chat.attachFile=null;$('#chat-attach-info').hide();return;}
  if(f.size>5*1024*1024){
    toast('Datei zu groß — höchstens 5 MB.','err');
    this.value='';Chat.attachFile=null;$('#chat-attach-info').hide();
    return;
  }
  if(!/\.(pdf|jpe?g|png|webp)$/i.test(f.name)){
    toast('Erlaubt sind PDF, JPG, PNG und WebP.','err');
    this.value='';Chat.attachFile=null;$('#chat-attach-info').hide();
    return;
  }
  Chat.attachFile=f;
  var istBild=/^image\//.test(f.type)||/\.(jpe?g|png|webp)$/i.test(f.name);
  var vorschau=istBild
    ? '<img class="chat-attach-vorschau" alt="">'
    : '<span class="chat-anhang-ico">'+chatDateiIcon({mime_type:f.type,dateiname:f.name})+'</span>';
  $('#chat-attach-info')
    .html(vorschau
      +'<span class="chat-anhang-txt"><span class="chat-anhang-name">'+esc(f.name)+'</span>'
      +'<span class="chat-anhang-meta">'+chatGroesse(f.size)+' · wird mit der nächsten Nachricht gesendet</span></span>'
      +'<button type="button" id="chat-attach-cancel" class="chat-attach-weg" aria-label="Anhang entfernen">&#10005;</button>')
    .show();
  if(istBild&&window.URL&&URL.createObjectURL){
    var u=URL.createObjectURL(f);
    $('#chat-attach-info .chat-attach-vorschau').attr('src',u).one('load',function(){URL.revokeObjectURL(u);});
  }
});


// "entfernen" im Anhang-Hinweis
$(document).on('click','#chat-attach-cancel',function(e){
  e.preventDefault();
  Chat.attachFile=null;
  $('#chat-file').val('');
  $('#chat-attach-info').hide();
});

// ─── Dringend-Schalter ────────────────────────────────────────────
$(document).on('click','#chat-dringend-btn',function(){
  var an=$('#chat-dringend').prop('checked');
  $('#chat-dringend').prop('checked',!an);
  $(this).attr('aria-pressed',!an?'true':'false');
});

// ─── Nachricht senden ─────────────────────────────────────────────
$(document).on('click','#chat-send',function(){chatSend();});
$(document).on('keydown','#chat-textarea',function(e){
  if(e.key==='Enter'&&!e.shiftKey){
    e.preventDefault();
    chatSend();
  }
});

function chatSend(){
  if(!Chat.current)return;
  var text=($('#chat-textarea').val()||'').trim();
  var hasFile=!!Chat.attachFile;
  if(!text&&!hasFile)return;
  if(!text&&hasFile)text='[Datei]';

  var dringend=$('#chat-dringend').is(':checked');
  var datei=Chat.attachFile;

  // Eingabefeld SOFORT leeren und die Nachricht sofort anzeigen — noch
  // bevor der Server geantwortet hat. Das ist der spürbare Unterschied:
  // Tippen, Absenden, weitertippen, ohne auf die Antwort zu warten.
  var vorlaeufigId='vor-'+(++Chat.vorlaeufigZaehler);
  $('#chat-textarea').val('').css('height','auto').focus();
  $('#chat-dringend').prop('checked',false);
  $('#chat-dringend-btn').attr('aria-pressed','false');
  Chat.attachFile=null;
  $('#chat-file').val('');
  $('#chat-attach-info').hide();
  Chat.lastTypingSent=Date.now();

  var $box=$('#chat-messages');
  if(Chat.lastMsgId===0)$box.empty();
  $box.append(chatRenderMsg({
    id:vorlaeufigId, von_typ:'user', von_id:Chat.meinUserId, text:text,
    erstellt_am:null, dringend:dringend?1:0, geloescht_am:null, bearbeitet_am:null,
    von_bild:Chat.meinBild||'', von_initialen:Chat.meineInitialen||'',
    anhaenge:[], reaktionen:[], unterwegs:true
  }));
  chatAvatareAusduennen();
  chatNachUnten();

  function fehlschlag(meldung){
    $('#chat-messages [data-id="'+vorlaeufigId+'"]')
      .addClass('fehlgeschlagen')
      .find('.chat-msg-status').html('nicht gesendet · '
        +'<button type="button" class="chat-erneut">erneut senden</button>');
    Chat.fehlgeschlagen[vorlaeufigId]={text:text,dringend:dringend,datei:datei};
    if(meldung)toast(meldung,'err');
  }

  ajax('lsv07i_konv_send',{konv_id:Chat.current,text:text,dringend:dringend?1:0})
    .done(function(r){
      if(!r||!r.success){ fehlschlag((r&&r.data&&r.data.message)||'Senden fehlgeschlagen.'); return; }
      var msgId=parseInt(r.data.msg_id||0,10);
      var $vor=$('#chat-messages [data-id="'+vorlaeufigId+'"]');
      // Vorläufige Bubble auf die echte ID umschreiben und als zugestellt
      // markieren — kein Neuladen nötig, das spart den zweiten Rundlauf.
      $vor.attr('data-id',msgId).find('.chat-msg-status').text('gesendet');

      if(datei&&msgId){
        $vor.find('.chat-msg-status').text('Datei wird übertragen…');
        Chat.attachFile=datei;
        chatUploadAttach(msgId,function(erfolg){
          Chat.attachFile=null;
          if(erfolg){
            // Nur DIESE Nachricht nachladen, damit der Anhang erscheint
            chatLoadMessages(true);
          } else {
            $vor.find('.chat-msg-status').text('Datei nicht übertragen');
          }
        });
      }
      // Liste im Hintergrund auffrischen (Vorschautext/Reihenfolge)
      chatLoadList();
    })
    .fail(function(){ fehlschlag('Keine Verbindung — Nachricht nicht gesendet.'); });
}

// Erneut senden nach einem Fehlschlag
$(document).on('click','.chat-erneut',function(){
  var $msg=$(this).closest('.chat-msg');
  var id=$msg.attr('data-id');
  var daten=Chat.fehlgeschlagen[id];
  if(!daten)return;
  delete Chat.fehlgeschlagen[id];
  $msg.remove();
  $('#chat-textarea').val(daten.text);
  $('#chat-dringend').prop('checked',!!daten.dringend);
  Chat.attachFile=daten.datei||null;
  chatSend();
});

// Lädt den geparkten Anhang per FormData hoch
function chatUploadAttach(msgId,onDone){
  if(!Chat.attachFile){onDone&&onDone(true);return;}
  if(!Chat.current||!msgId){
    toast('Anhang konnte nicht zugeordnet werden (Unterhaltung nicht bekannt).','err');
    onDone&&onDone(false);return;
  }
  var fd=new FormData();
  fd.append('action','lsv07i_konv_anhang_upload');
  fd.append('nonce',LSV07I.nonce);
  fd.append('konv_id',Chat.current);
  fd.append('msg_id',msgId);
  fd.append('datei',Chat.attachFile);

  $.ajax({
    url:LSV07I.ajax_url,
    type:'POST',
    data:fd,
    processData:false,
    contentType:false
  }).done(function(r){
    if(!r||!r.success){
      var msg=(r&&r.data&&r.data.message)||'Anhang-Upload fehlgeschlagen.';
      toast(msg,'err');
      // Die Nachricht steht mit dem Platzhalter "[Datei]" bereits im Chat,
      // OHNE angehängte Datei — das sah bisher aus wie ein funktionsloser
      // Text ohne jeden Hinweis, was schiefging. Text nachträglich korrigieren,
      // damit der Fehler auch nach dem Verschwinden des Toasts sichtbar bleibt.
      ajax('lsv07i_konv_msg_edit',{msg_id:msgId,text:'⚠ Datei-Upload fehlgeschlagen: '+msg});
      onDone&&onDone(false);
      return;
    }
    onDone&&onDone(true);
  }).fail(function(xhr){
    toast('Datei nicht übertragen: '+errMsg(xhr),'err');
    ajax('lsv07i_konv_msg_edit',{msg_id:msgId,text:'⚠ Datei-Upload fehlgeschlagen.'});
    onDone&&onDone(false);
  });
}

// Auto-resize Textarea
$(document).on('input','#chat-textarea',function(){
  this.style.height='auto';
  this.style.height=Math.min(this.scrollHeight,120)+'px';
  // Typing-Indikator: an Server senden, frühestens alle 3 Sek
  chatTypingMaybeSend();
});

// ─── Tippen-Indikator ─────────────────────────────────────────────
Chat.lastTypingSent=0;        // Timestamp letzter typing_set-Call
Chat.typingGetTimer=null;     // Polling-Timer für typing_get
Chat.typingActive=[];         // [{id,name}, …] aktuell tippende

// Throttled: nur alle 3 Sek wirklich senden. Bei jedem Tastendruck wird
// die Methode aufgerufen, sie schickt aber nur wenn der letzte Call >= 3s
// her ist und die Konversation offen ist.
function chatTypingMaybeSend(){
  if(!Chat.current)return;
  var now=Date.now();
  if(now-Chat.lastTypingSent<3000)return;
  Chat.lastTypingSent=now;
  ajax('lsv07i_konv_typing_set',{konv_id:Chat.current});
}

// Holt die Liste der gerade tippenden Teilnehmer (außer mir) und rendert.
function chatTypingGet(){
  if(!Chat.current){chatTypingRender([]);return;}
  ajax('lsv07i_konv_typing_get',{konv_id:Chat.current}).done(function(r){
    if(!r||!r.success)return;
    Chat.typingActive=r.data.tippen||[];
    chatTypingRender(Chat.typingActive);
  });
}

function chatTypingRender(list){
  var $box=$('#chat-typing');
  var $txt=$box.find('.chat-typing-text');
  if(!list||!list.length){
    $box.removeClass('active');
    return;
  }
  var names=$.map(list,function(p){return esc(p.name);});
  var label;
  if(names.length===1)      label=names[0]+' tippt …';
  else if(names.length===2) label=names.join(' und ')+' tippen …';
  else                       label=names.length+' Personen tippen …';
  $txt.html(label);
  $box.addClass('active');
}

function chatTypingStartPolling(){
  chatTypingStopPolling();
  // Alle 4 Sek prüfen — passt zum TTL-Fenster von 10s gut
  Chat.typingGetTimer=setInterval(chatTypingGet,4000);
}
function chatTypingStopPolling(){
  if(Chat.typingGetTimer){clearInterval(Chat.typingGetTimer);Chat.typingGetTimer=null;}
  chatTypingRender([]);
}

// ─── Suche in Liste ───────────────────────────────────────────────
$(document).on('input','#chat-search',chatRenderList);

// ─── Neuer-Chat-Modal ─────────────────────────────────────────────
$(document).on('click','#chat-new',function(){
  Chat.newType='direkt';
  Chat.newTeilnehmer=[];
  $('.chat-new-typ').removeClass('on');
  $('.chat-new-typ[data-typ="direkt"]').addClass('on');
  chatShowNewBlock();
  $('#chat-new-user-search,#chat-new-gruppe-name,#chat-new-gruppe-search,#chat-new-extern-email').val('');
  $('#chat-new-user-results,#chat-new-gruppe-search-results,#chat-new-teilnehmer-liste').empty();
  // Mannschaften laden für Tab "Mannschaft"
  ajax('lsv07i_konv_mannschaften').done(function(r){
    if(!r||!r.success)return;
    var $s=$('#chat-new-mannschaft-sel').empty().append('<option value="">Mannschaft wählen…</option>');
    $.each(r.data.mannschaften||[],function(i,m){
      $s.append('<option value="'+m.id+'">'+esc(m.name)+'</option>');
    });
  });
  openModal('m-chat-new');
});

// Typ-Wechsel im Modal
$(document).on('click','.chat-new-typ',function(){
  Chat.newType=$(this).data('typ');
  $('.chat-new-typ').removeClass('on');
  $(this).addClass('on');
  chatShowNewBlock();
});
function chatShowNewBlock(){
  $('#chat-new-direkt-block,#chat-new-gruppe-block,#chat-new-mannschaft-block').hide();
  if(Chat.newType==='direkt')$('#chat-new-direkt-block').show();
  else if(Chat.newType==='mannschaft')$('#chat-new-mannschaft-block').show();
  else $('#chat-new-gruppe-block').show();
}

// User-Suche (1:1)
$(document).on('input','#chat-new-user-search',function(){
  clearTimeout(Chat.userSearchTimer);
  var q=$(this).val();
  Chat.userSearchTimer=setTimeout(function(){chatSearchUsers(q,'#chat-new-user-results',true);},200);
});
$(document).on('input','#chat-new-gruppe-search',function(){
  clearTimeout(Chat.userSearchTimer);
  var q=$(this).val();
  Chat.userSearchTimer=setTimeout(function(){chatSearchUsers(q,'#chat-new-gruppe-search-results',false);},200);
});
function chatSearchUsers(q,target,direkt){
  if(!q||q.length<2){$(target).empty();return;}
  ajax('lsv07i_konv_search_users',{q:q}).done(function(r){
    if(!r||!r.success)return;
    var users=r.data.users||[];
    if(!users.length){
      $(target).html('<div style="font-size:12px;color:#7c7f94;padding:6px 10px">Keine Treffer.</div>');
      return;
    }
    var h='';
    $.each(users,function(i,u){
      h+='<div class="chat-search-result" data-id="'+u.id+'" data-name="'+esc(u.name)+'">'
        +'<span>'+esc(u.name)+' <small style="color:#7c7f94">'+esc(u.email||'')+'</small></span>'
        +'<span class="add">'+(direkt?'Chat starten':'+ Hinzufügen')+'</span>'
        +'</div>';
    });
    $(target).html(h);
  });
}

// Direkt-Chat: Klick auf User startet sofort
$(document).on('click','#chat-new-user-results .chat-search-result',function(){
  var id=$(this).data('id'),name=$(this).data('name');
  ajax('lsv07i_konv_create',{
    typ:'direkt',
    teilnehmer:JSON.stringify([{typ:'user',id:id,name:name}])
  }).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    closeModal('m-chat-new');
    chatLoadList();
    setTimeout(function(){chatOpen(parseInt(r.data.konv_id,10));},200);
  });
});

// Gruppe/Verteiler: Teilnehmer sammeln
$(document).on('click','#chat-new-gruppe-search-results .chat-search-result',function(){
  var id=$(this).data('id'),name=$(this).data('name');
  if(Chat.newTeilnehmer.some(function(t){return t.typ==='user'&&t.id===id;}))return;
  Chat.newTeilnehmer.push({typ:'user',id:id,name:name});
  chatRenderNewTeilnehmer();
  $('#chat-new-gruppe-search').val('').focus();
  $('#chat-new-gruppe-search-results').empty();
});

// Externe E-Mail hinzufügen
$(document).on('click','#chat-new-extern-add',function(){
  var em=($('#chat-new-extern-email').val()||'').trim();
  if(!em||em.indexOf('@')<0){toast('Gültige E-Mail-Adresse eingeben.','err');return;}
  if(Chat.newTeilnehmer.some(function(t){return t.typ==='email'&&t.email===em;}))return;
  Chat.newTeilnehmer.push({typ:'email',email:em,name:em});
  chatRenderNewTeilnehmer();
  $('#chat-new-extern-email').val('');
});

function chatRenderNewTeilnehmer(){
  var h='';
  $.each(Chat.newTeilnehmer,function(i,t){
    var ext=t.typ==='email'?' <small style="color:#6b6e85">(extern)</small>':'';
    h+='<span class="chat-teilnehmer-tag">'+esc(t.name)+ext
      +'<span class="x" data-i="'+i+'" title="Entfernen">×</span></span>';
  });
  $('#chat-new-teilnehmer-liste').html(h||'<span style="font-size:12px;color:#7c7f94">Niemand ausgewählt</span>');
}
$(document).on('click','#chat-new-teilnehmer-liste .x',function(){
  Chat.newTeilnehmer.splice(parseInt($(this).data('i'),10),1);
  chatRenderNewTeilnehmer();
});

// "Chat erstellen"-Button
$(document).on('click','#chat-new-create',function(){
  if(Chat.newType==='direkt'){
    // wird über Klick auf User-Result direkt erstellt
    toast('Wähle einen User aus den Suchergebnissen.','err');return;
  }
  if(Chat.newType==='mannschaft'){
    var mid=parseInt($('#chat-new-mannschaft-sel').val(),10);
    if(!mid){toast('Bitte eine Mannschaft wählen.','err');return;}
    var mname=$('#chat-new-mannschaft-sel option:selected').text();
    // Vereinfacht: einen Verteiler mit Name anlegen, ohne Teilnehmer.
    // Auto-Befüllung mit Trainern/Kontakten ist Backend-Aufgabe für später.
    var $b=$(this).prop('disabled',true).text('Erstellt…');
    ajax('lsv07i_konv_create',{
      typ:'verteiler',
      name:'Mannschaft '+mname,
      teilnehmer:JSON.stringify([])
    }).done(function(r){
      if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
      closeModal('m-chat-new');
      chatLoadList();
      setTimeout(function(){chatOpen(parseInt(r.data.konv_id,10));},200);
    }).always(function(){$b.prop('disabled',false).text('Chat erstellen');});
    return;
  }
  // Gruppe oder Verteiler
  if(!Chat.newTeilnehmer.length){
    toast('Mindestens einen Teilnehmer hinzufügen.','err');return;
  }
  var name=($('#chat-new-gruppe-name').val()||'').trim();
  if(!name){toast('Bitte einen Namen für die Gruppe.','err');return;}
  var $b=$(this).prop('disabled',true).text('Erstellt…');
  ajax('lsv07i_konv_create',{
    typ:Chat.newType,
    name:name,
    teilnehmer:JSON.stringify(Chat.newTeilnehmer)
  }).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    closeModal('m-chat-new');
    chatLoadList();
    setTimeout(function(){chatOpen(parseInt(r.data.konv_id,10));},200);
  }).always(function(){$b.prop('disabled',false).text('Chat erstellen');});
});

// ─── Info-Modal ───────────────────────────────────────────────────
// ─── Info-Modal mit Verwaltungs-Funktionen ────────────────────────
Chat.infoSearchTimer=null;
Chat.infoKonv=null; // aktuell im Modal angezeigte Konversation

$(document).on('click','#chat-info',function(){
  if(!Chat.current)return;
  var k=null;
  $.each(Chat.konvs,function(i,c){if(parseInt(c.id,10)===Chat.current)k=c;});
  if(!k)return;
  Chat.infoKonv=k;
  $('#m-chat-info-title').text(chatKonvName(k));

  var meinAdmin=parseInt(k.mein_admin||0,10)===1;
  var isDirekt=k.typ==='direkt';

  // Gruppenname-Bearbeiten nur für Admins von Gruppen/Verteilern
  if(meinAdmin && !isDirekt){
    $('#chat-info-edit-name').show();
    $('#chat-info-name-input').val(k.name||'');
  } else {
    $('#chat-info-edit-name').hide();
  }

  // Teilnehmer-Liste rendern
  chatInfoRenderTeilnehmer(k, meinAdmin);

  // Teilnehmer hinzufügen + Gefahrenzone
  if(meinAdmin && !isDirekt){
    $('#chat-info-add-block').show();
    $('#chat-info-danger').show();
    $('#chat-info-leave-only').hide();
    $('#chat-info-add-search,#chat-info-add-email').val('');
    $('#chat-info-add-results').empty();
  } else if(!isDirekt){
    $('#chat-info-add-block').hide();
    $('#chat-info-danger').hide();
    $('#chat-info-leave-only').show();
  } else {
    $('#chat-info-add-block').hide();
    $('#chat-info-danger').hide();
    $('#chat-info-leave-only').hide();
  }

  openModal('m-chat-info');
});

function chatInfoRenderTeilnehmer(k, meinAdmin){
  var uid=Chat.meinUserId;
  var teil=k.teilnehmer||[];
  var h='<strong style="font-size:12px;color:#6b6e85;text-transform:uppercase;letter-spacing:.04em">Teilnehmer ('+teil.length+')</strong><div style="margin-top:6px">';
  $.each(teil,function(i,t){
    var nm=t.teilnehmer_name||t.teilnehmer_email||('ID '+t.teilnehmer_id);
    var typ=t.teilnehmer_typ==='user'?'Intern'
           :t.teilnehmer_typ==='kontakt'?'Kontaktperson'
           :'Externe E-Mail';
    var istAdmin=parseInt(t.ist_admin||0,10)===1;
    var isSelf=t.teilnehmer_typ==='user'&&parseInt(t.teilnehmer_id,10)===uid;
    var adminBdg=istAdmin?'<span style="display:inline-block;background:rgba(91,148,255,0.20);color:#2a5fd0;font-size:10px;padding:1px 5px;border-radius:3px;margin-left:4px;font-weight:600">ADMIN</span>':'';
    var aktionen='';
    // Admin-Buttons nur für Admins, nicht bei externen und nicht für sich selbst (außer Demote)
    if(meinAdmin && !isSelf && t.teilnehmer_typ==='user'){
      aktionen+=istAdmin
        ? '<button class="i-btn i-btn-sm chat-info-demote" data-id="'+t.id+'" title="Admin-Status entziehen">−Admin</button> '
        : '<button class="i-btn i-btn-sm chat-info-promote" data-id="'+t.id+'" title="Zum Admin machen">+Admin</button> ';
    }
    if(meinAdmin && !isSelf){
      aktionen+='<button class="i-btn i-btn-r i-btn-sm chat-info-remove" data-id="'+t.id+'" data-name="'+esc(nm)+'" title="Aus Gruppe entfernen">×</button>';
    }
    if(meinAdmin && isSelf && istAdmin){
      // Self-demote nur wenn nicht letzter Admin
      aktionen+='<button class="i-btn i-btn-sm chat-info-demote" data-id="'+t.id+'" title="Eigenen Admin-Status abgeben">−Admin</button>';
    }
    h+='<div style="padding:8px 10px;background:rgba(255,255,255,0.05);border-radius:6px;margin-bottom:4px;display:flex;justify-content:space-between;align-items:center;gap:8px">'
      +'<div style="flex:1;min-width:0">'
      +(t.teilnehmer_typ==='user'?'<span class="online-dot" data-uid="'+t.teilnehmer_id+'"></span>':'')
      +'<strong>'+esc(nm)+'</strong>'+adminBdg+(isSelf?' <small style="color:#6b6e85">(Sie)</small>':'')+'<br>'
      +'<small style="color:#6b6e85">'+typ+'</small></div>'
      +(aktionen?'<div style="flex-shrink:0;display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end">'+aktionen+'</div>':'')
      +'</div>';
  });
  h+='</div>';
  $('#chat-info-teilnehmer').html(h);
  chatUpdateOnlineDots();
}

// Gruppenname speichern
$(document).on('click','#chat-info-name-save',function(){
  if(!Chat.infoKonv)return;
  var name=($('#chat-info-name-input').val()||'').trim();
  if(!name){toast('Name darf nicht leer sein.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichert…');
  ajax('lsv07i_konv_update_meta',{konv_id:Chat.infoKonv.id, name:name}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast('Gespeichert.');
    Chat.infoKonv.name=name;
    $('#m-chat-info-title').text(name);
    chatLoadList();
  }).always(function(){$b.prop('disabled',false).text('Speichern');});
});

// Admin promoten/demoten
$(document).on('click','.chat-info-promote, .chat-info-demote',function(){
  if(!Chat.infoKonv)return;
  var $btn=$(this);
  var on=$btn.hasClass('chat-info-promote');
  var tid=$btn.data('id');
  $btn.prop('disabled',true);
  ajax('lsv07i_konv_set_admin',{konv_id:Chat.infoKonv.id, teilnehmer_id:tid, on:on?1:0}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');$btn.prop('disabled',false);return;}
    // Lokales State-Update: Flag im Teilnehmer-Array umsetzen + neu rendern
    $.each(Chat.infoKonv.teilnehmer||[],function(i,t){
      if(parseInt(t.id,10)===parseInt(tid,10))t.ist_admin=on?1:0;
    });
    chatInfoRenderTeilnehmer(Chat.infoKonv, parseInt(Chat.infoKonv.mein_admin||0,10)===1);
    chatLoadList();
  });
});

// Teilnehmer entfernen
$(document).on('click','.chat-info-remove',function(){
  if(!Chat.infoKonv)return;
  var $btn=$(this);
  var tid=$btn.data('id');
  var nm=$btn.data('name');
  if(!confirm('Teilnehmer „'+nm+'" aus der Gruppe entfernen?'))return;
  $btn.prop('disabled',true);
  ajax('lsv07i_konv_remove_teil',{konv_id:Chat.infoKonv.id, teilnehmer_id:tid}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');$btn.prop('disabled',false);return;}
    // Aus Liste entfernen + neu rendern
    Chat.infoKonv.teilnehmer=$.grep(Chat.infoKonv.teilnehmer||[],function(t){
      return parseInt(t.id,10)!==parseInt(tid,10);
    });
    chatInfoRenderTeilnehmer(Chat.infoKonv, parseInt(Chat.infoKonv.mein_admin||0,10)===1);
    chatLoadList();
  });
});

// User-Suche im Info-Modal (für Hinzufügen)
$(document).on('input','#chat-info-add-search',function(){
  clearTimeout(Chat.infoSearchTimer);
  var q=$(this).val();
  Chat.infoSearchTimer=setTimeout(function(){
    if(!q||q.length<2){$('#chat-info-add-results').empty();return;}
    ajax('lsv07i_konv_search_users',{q:q}).done(function(r){
      if(!r||!r.success)return;
      var users=r.data.users||[];
      if(!users.length){
        $('#chat-info-add-results').html('<div style="font-size:12px;color:#7c7f94;padding:6px 10px">Keine Treffer.</div>');
        return;
      }
      var h='';
      $.each(users,function(i,u){
        h+='<div class="chat-search-result chat-info-add-user" data-id="'+u.id+'" data-name="'+esc(u.name)+'">'
          +'<span>'+esc(u.name)+' <small style="color:#7c7f94">'+esc(u.email||'')+'</small></span>'
          +'<span class="add">+ Hinzufügen</span></div>';
      });
      $('#chat-info-add-results').html(h);
    });
  },200);
});

// Hinzufügen: User auswählen
$(document).on('click','.chat-info-add-user',function(){
  if(!Chat.infoKonv)return;
  var id=$(this).data('id'), name=$(this).data('name');
  ajax('lsv07i_konv_add_teilnehmer',{
    konv_id:Chat.infoKonv.id, typ:'user', id:id, name:name
  }).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast(name+' hinzugefügt.');
    $('#chat-info-add-search').val('');
    $('#chat-info-add-results').empty();
    chatLoadList();
    // Modal neu öffnen für Refresh — einfach Klick simulieren nach kurzer Pause
    setTimeout(function(){$('#chat-info').click();},400);
  });
});

// Hinzufügen: externe E-Mail
$(document).on('click','#chat-info-add-email-btn',function(){
  if(!Chat.infoKonv)return;
  var em=($('#chat-info-add-email').val()||'').trim();
  if(!em||em.indexOf('@')<0){toast('Gültige E-Mail eingeben.','err');return;}
  ajax('lsv07i_konv_add_teilnehmer',{
    konv_id:Chat.infoKonv.id, typ:'email', email:em, name:em
  }).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast(em+' hinzugefügt.');
    $('#chat-info-add-email').val('');
    chatLoadList();
    setTimeout(function(){$('#chat-info').click();},400);
  });
});

// Gruppe verlassen (zwei Buttons — Admin-Variante + Normal-Variante)
$(document).on('click','#chat-info-leave, #chat-info-leave2',function(){
  if(!Chat.infoKonv)return;
  if(!confirm('Gruppe wirklich verlassen?'))return;
  ajax('lsv07i_konv_leave',{konv_id:Chat.infoKonv.id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast('Gruppe verlassen.');
    closeModal('m-chat-info');
    Chat.current=null;
    $('#chat-view').hide();
    $('#chat-empty').show();
    $('#chat-wrap').removeClass('show-right');
    chatLoadList();
  });
});

// Gruppe löschen (nur Admin)
$(document).on('click','#chat-info-delete',function(){
  if(!Chat.infoKonv)return;
  if(!confirm('Diese Gruppe wirklich KOMPLETT löschen?\n\nAlle Nachrichten und Anhänge gehen verloren. Diese Aktion kann nicht rückgängig gemacht werden.'))return;
  if(!confirm('Wirklich sicher? Letzte Warnung.'))return;
  ajax('lsv07i_konv_delete_konv',{konv_id:Chat.infoKonv.id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast('Gruppe gelöscht.');
    closeModal('m-chat-info');
    Chat.current=null;
    $('#chat-view').hide();
    $('#chat-empty').show();
    $('#chat-wrap').removeClass('show-right');
    chatLoadList();
  });
});

// Section-Wechsel: bei Klick auf Nachrichten-Topbar starten
$(document).on('click','#nav-nachrichten',function(){
  chatLoadList();
  chatUpdateUnreadBadge();
  chatStartPolling();
});

// Beim ersten Laden des UIs: Badge sofort holen und das eigene Profilbild
// merken, damit die eigenen Chat-Nachrichten es sofort zeigen.
$(function(){
  if(typeof LSV07I!=='undefined'&&LSV07I.nonce){
    chatUpdateUnreadBadge();
    chatStartPolling();
    chatEigenesBildLaden();
  }
});

function chatEigenesBildLaden(){
  ajax('lsv07i_profil_get').done(function(r){
    if(!r||!r.success)return;
    Chat.meinBild=r.data.bild_url||'';
    Chat.meineInitialen=r.data.initialen||'';
  });
}

// ─── Kontextmenü für Chat-Nachrichten ─────────────────────────────
// Rechtsklick (Desktop) oder Long-Press (Mobile) auf eine Nachricht
// öffnet das Menü mit Emojis und (bei eigenen / als Admin) weiteren Aktionen.
Chat.ctxMsgId=null;
Chat.ctxMine=false;
Chat.ctxDeleted=false;
Chat.lpTimer=null;
Chat.isAdmin=false; // wird beim Laden gesetzt

// Erkennt ob aktueller User Admin ist — wird einmalig vom Server geholt.
// Vereinfacht: wir nutzen window.LSV07I.is_admin falls vom Backend gesetzt.
// Fallback: über bestehende Vereinsbereich-Sichtbarkeit raten.
function chatDetectAdmin(){
  // Wenn der "Admin"-Hauptnavigationspunkt im DOM existiert, gehen wir davon
  // aus dass der User Admin-Rechte hat. Das stimmt im LSV07-Plugin-Layout.
  Chat.isAdmin=$('.i-nb[data-sec="a"]').length>0;
}

function chatShowCtx(e,msgId,mine,deleted){
  Chat.ctxMsgId=msgId;
  Chat.ctxMine=!!mine;
  Chat.ctxDeleted=!!deleted;
  var $m=$('#chat-ctx');
  // Aktionen ein-/ausblenden je nach Rolle
  $m.find('[data-action="edit"]').toggle(!!mine && !deleted);
  // Löschen: eigene immer; fremde nur als Admin (beides nur wenn nicht schon gelöscht)
  $m.find('[data-action="delete"]').toggle((mine || Chat.isAdmin) && !deleted);
  // Emojis: immer sichtbar (außer bei gelöschten)
  $m.find('#chat-ctx-emoji').toggle(!deleted);

  // Position berechnen — innerhalb des Viewports halten
  var w=$m.outerWidth()||180, h=$m.outerHeight()||200;
  var x=Math.min(e.clientX, window.innerWidth - w - 8);
  var y=Math.min(e.clientY, window.innerHeight - h - 8);
  $m.css({left:x+'px', top:y+'px'}).addClass('show');
}

function chatHideCtx(){
  $('#chat-ctx').removeClass('show');
  Chat.ctxMsgId=null;
}

// Rechtsklick auf Nachricht
$(document).on('contextmenu','.chat-msg',function(e){
  e.preventDefault();
  var $m=$(this);
  if($m.attr('data-deleted')==='1')return;
  chatShowCtx(e, parseInt($m.data('id'),10), $m.attr('data-mine')==='1', false);
});

// Long-Press auf Mobile (touchstart > 500ms)
$(document).on('touchstart','.chat-msg',function(e){
  var $m=$(this);
  if($m.attr('data-deleted')==='1')return;
  var touch=e.originalEvent.touches[0];
  Chat.lpTimer=setTimeout(function(){
    chatShowCtx({clientX:touch.clientX, clientY:touch.clientY},
                parseInt($m.data('id'),10), $m.attr('data-mine')==='1', false);
  }, 500);
});
$(document).on('touchend touchmove','.chat-msg',function(){
  if(Chat.lpTimer){clearTimeout(Chat.lpTimer); Chat.lpTimer=null;}
});

// Klick außerhalb → schließen
$(document).on('click',function(e){
  if(!$(e.target).closest('#chat-ctx').length){
    chatHideCtx();
  }
});
$(document).on('keydown',function(e){
  if(e.key==='Escape')chatHideCtx();
});

// Klick auf Emoji → Reaktion toggeln
$(document).on('click','#chat-ctx-emoji .em',function(){
  var emoji=$(this).data('em');
  var mid=Chat.ctxMsgId;
  chatHideCtx();
  if(!mid)return;
  ajax('lsv07i_konv_msg_reaktion',{msg_id:mid, emoji:emoji}).done(function(r){
    if(!r||!r.success){
      toast((r&&r.data&&r.data.message)||'Reaktion fehlgeschlagen.','err');
      return;
    }
    // Komplett neu laden, damit Reaktionen aktuell sind
    Chat.lastMsgId=0;
    chatLoadMessages(false);
  });
});

// Klick auf existierende Reaktion → Toggle (entfernen wenn meine, sonst hinzufügen)
$(document).on('click','.chat-msg-reakt',function(){
  var mid=parseInt($(this).data('msg-id'),10);
  var em=$(this).data('em');
  ajax('lsv07i_konv_msg_reaktion',{msg_id:mid, emoji:em}).done(function(r){
    if(!r||!r.success)return;
    Chat.lastMsgId=0;
    chatLoadMessages(false);
  });
});

// Bearbeiten
$(document).on('click','#chat-ctx [data-action="edit"]',function(){
  var mid=Chat.ctxMsgId;
  chatHideCtx();
  if(!mid)return;
  var $msg=$('#chat-messages .chat-msg[data-id="'+mid+'"]');
  if(!$msg.length)return;
  var $bubble=$msg.find('.chat-msg-bubble');
  // Aktueller Text aus dem DOM holen — wir nehmen den text-Knoten, da HTML
  // im Bubble bereits gerendert ist. Einfacher: vom Server neu holen wäre robust,
  // aber wir nutzen DOM-Text als Bearbeitungsbasis.
  var aktText=$bubble.text().replace(/^Diese Nachricht wurde gelöscht\.$/,'');
  var editHtml='<div class="chat-msg-edit">'
    +'<textarea>'+esc(aktText)+'</textarea>'
    +'<div class="chat-msg-edit-actions">'
    +'<button class="i-btn i-btn-r chat-edit-cancel">Abbrechen</button>'
    +'<button class="i-btn i-btn-p chat-edit-save" data-msg="'+mid+'">Speichern</button>'
    +'</div></div>';
  $bubble.hide().after(editHtml);
  $msg.find('.chat-msg-edit textarea').focus();
});

$(document).on('click','.chat-edit-cancel',function(){
  var $e=$(this).closest('.chat-msg-edit');
  $e.prev('.chat-msg-bubble').show();
  $e.remove();
});

$(document).on('click','.chat-edit-save',function(){
  var $btn=$(this);
  var mid=$btn.data('msg');
  var $e=$btn.closest('.chat-msg-edit');
  var text=$e.find('textarea').val();
  if(!text.trim()){toast('Nachricht ist leer.','err');return;}
  $btn.prop('disabled',true).text('Speichert…');
  ajax('lsv07i_konv_msg_edit',{msg_id:mid, text:text}).done(function(r){
    if(!r||!r.success){
      toast((r&&r.data&&r.data.message)||'Bearbeiten fehlgeschlagen.','err');
      $btn.prop('disabled',false).text('Speichern');
      return;
    }
    Chat.lastMsgId=0;
    chatLoadMessages(false);
  }).fail(function(){
    $btn.prop('disabled',false).text('Speichern');
  });
});

// Löschen
$(document).on('click','#chat-ctx [data-action="delete"]',function(){
  var mid=Chat.ctxMsgId;
  chatHideCtx();
  if(!mid)return;
  if(!confirm('Diese Nachricht wirklich löschen?'))return;
  ajax('lsv07i_konv_msg_delete',{msg_id:mid}).done(function(r){
    if(!r||!r.success){
      toast((r&&r.data&&r.data.message)||'Löschen fehlgeschlagen.','err');
      return;
    }
    Chat.lastMsgId=0;
    chatLoadMessages(false);
    chatLoadList();
  });
});

// Beim Laden Admin-Status erkennen
$(function(){ chatDetectAdmin(); });

// ═══════════════════════════════════════════════════════════════════
// REFLEXIONSBÖGEN (Trainer-Dashboard)
// ═══════════════════════════════════════════════════════════════════
var Refl={
  mann:0,          // aktuell gewählte Mannschaft-ID
  saisonId:0,
  fragen:[],       // aktuelle Stammfragen
  uebersicht:[],   // Bögen der Mannschaft
  publicLink:'',   // gemeinsamer öffentlicher Link
};

// Mannschaft gewählt → laden
$(document).on('change','#refl-mann',function(){
  Refl.mann=parseInt($(this).val(),10)||0;
  if($('#refl-sub-auswertung').is(':visible')){loadReflAuswertung();}
  if(!Refl.mann){
    $('#refl-subnav,#refl-sub-uebersicht,#refl-sub-fragen,#refl-sub-auswertung').hide();
    $('#refl-empty').show();
    $('#refl-saison-info').text('');
    return;
  }
  $('#refl-empty').hide();
  $('#refl-subnav').css('display','flex');
  reflShowSub('uebersicht');
  reflLadeUebersicht();
  reflLadeFragen();
});

// Sub-Tabs
$(document).on('click','.refl-subtab',function(){
  reflShowSub($(this).data('rsub'));
  if($(this).data('rsub')==='auswertung'){loadReflAuswertung();}
});
function reflShowSub(name){
  $('.refl-subtab').removeClass('on');
  $('.refl-subtab[data-rsub="'+name+'"]').addClass('on');
  $('.refl-sub').hide();
  $('#refl-sub-'+name).show();
}

// ── Übersicht laden ────────────────────────────────────────────────
function reflLadeUebersicht(){
  if(!Refl.mann)return;
  skel($('#refl-liste'),'rows',5);
  ajax('lsv07i_refl_uebersicht',{mannschaft_id:Refl.mann}).done(function(r){
    if(!r||!r.success){$('#refl-liste').html('<span class="i-muted">'+((r&&r.data&&r.data.message)||'Fehler.')+'</span>');return;}
    Refl.uebersicht=r.data.uebersicht||[];
    Refl.publicLink=r.data.public_link||Refl.publicLink||"";
    Refl.saisonId=r.data.saison_id||0;
    if(!Refl.saisonId){
      $('#refl-saison-info').html('<span style="color:#b91c1c">Keine aktive Saison gesetzt. Bögen können erst erstellt werden, wenn eine Saison aktiv ist.</span>');
    } else {
      $('#refl-saison-info').text('Aktive Saison – ein Bogen pro Schwimmer.');
    }
    reflRenderUebersicht();
  });
}

function reflRenderUebersicht(){
  var list=Refl.uebersicht;
  // Aktive (offen/abgegeben) vs. archivierte Bögen trennen
  var aktiv=[], archiv=[];
  $.each(list,function(i,b){
    if(b.status==='archiviert')archiv.push(b); else aktiv.push(b);
  });
  var abgegeben=0;
  $.each(aktiv,function(i,b){if(b.status==='abgegeben')abgegeben++;});
  $('#refl-stat').text(aktiv.length
    ?(aktiv.length+' aktive Bögen · '+abgegeben+' abgegeben · '+(aktiv.length-abgegeben)+' offen'
      +(archiv.length?(' · '+archiv.length+' archiviert'):''))
    :(archiv.length?(archiv.length+' archiviert'):'Noch keine Bögen erstellt.'));

  if(!list.length){
    $('#refl-liste').html('<div class="abr-empty">Noch keine Bögen für diese Saison. Lege zuerst Stammfragen an und klicke dann auf „Bögen für Saison erstellen".</div>');
    return;
  }

  var h='';
  if(aktiv.length){
    h+='<div style="margin-bottom:10px"><button id="refl-alle-links" class="i-btn i-btn-g i-btn-sm">Alle Zugangsschlüssel &amp; Links anzeigen</button></div>';
    $.each(aktiv,function(i,b){ h+=reflRowHtml(b,false); });
  } else {
    h+='<div class="abr-empty">Keine aktiven Bögen. '+(archiv.length?'Archivierte Bögen siehe unten.':'')+'</div>';
  }

  // Archiv-Bereich (ausklappbar)
  if(archiv.length){
    h+='<div class="refl-archiv">'
      +'<button class="refl-archiv-toggle" id="refl-archiv-toggle">'
      +'<span class="refl-archiv-caret">▶</span> Archiv ('+archiv.length+')</button>'
      +'<div class="refl-archiv-body" id="refl-archiv-body" style="display:none">';
    $.each(archiv,function(i,b){ h+=reflRowHtml(b,true); });
    h+='</div></div>';
  }
  $('#refl-liste').html(h);
}

// Erzeugt eine Bogen-Zeile. archiviert=true blendet andere Aktionen ein.
function reflRowHtml(b,archiviert){
  var statusBdg;
  if(b.status==='archiviert')statusBdg='<span class="refl-status refl-status-arch">Archiviert</span>';
  else if(b.status==='abgegeben')statusBdg='<span class="refl-status refl-status-done">✓ Abgegeben</span>';
  else statusBdg='<span class="refl-status refl-status-open">Offen</span>';

  var datum='';
  if(b.status==='archiviert'&&b.archiviert_am)datum=' · archiviert '+reflDate(b.archiviert_am);
  else if(b.abgegeben_am)datum=' · '+reflDate(b.abgegeben_am);

  var acts='';
  if(archiviert){
    // Archivierte Bögen: ansehen (falls abgegeben), zurückholen, löschen
    if(b.abgegeben_am){
      acts+='<button class="i-btn i-btn-g i-btn-sm refl-ansehen" data-id="'+b.id+'">Ansehen</button> ';
    }
    acts+='<button class="i-btn i-btn-sm refl-unarch" data-id="'+b.id+'" title="Aus dem Archiv zurückholen">Zurückholen</button> '
        +'<button class="i-btn i-btn-r i-btn-sm refl-del" data-id="'+b.id+'" data-name="'+esc(b.swimmer_name)+'">Löschen</button>';
  } else {
    if(b.status==='abgegeben'){
      acts+='<button class="i-btn i-btn-g i-btn-sm refl-ansehen" data-id="'+b.id+'">Ansehen</button> '
          +'<button class="i-btn i-btn-sm refl-frei" data-id="'+b.id+'" title="Wieder für Korrekturen öffnen">Freischalten</button> ';
    }
    acts+='<button class="i-btn i-btn-sm refl-arch" data-id="'+b.id+'" title="Archivieren">Archivieren</button> '
        +'<button class="i-btn i-btn-r i-btn-sm refl-del" data-id="'+b.id+'" data-name="'+esc(b.swimmer_name)+'">Löschen</button>';
  }
  return '<div class="refl-row">'
    +'<div class="refl-row-main">'
    +'<div class="refl-row-name">'+esc(b.swimmer_name)+'</div>'
    +'<div class="refl-row-meta">'+statusBdg+datum+'</div>'
    +'</div>'
    +'<div class="refl-row-acts">'+acts+'</div>'
    +'</div>';
}

function reflDate(s){
  if(!s)return '';
  var d=new Date(s.replace(' ','T'));
  if(isNaN(d))return s;
  return ('0'+d.getDate()).slice(-2)+'.'+('0'+(d.getMonth()+1)).slice(-2)+'.'+d.getFullYear();
}

// ── Bögen erstellen ────────────────────────────────────────────────
$(document).on('click','#refl-erstellen',function(){
  if(!Refl.mann)return;
  if(!Refl.saisonId){toast('Keine aktive Saison gesetzt.','err');return;}
  if(!Refl.fragen.length){
    toast('Bitte zuerst Stammfragen anlegen.','err');
    reflShowSub('fragen');
    return;
  }
  if(!confirm('Für alle Schwimmer dieser Mannschaft einen Bogen erstellen?\n\nSchwimmer, die in dieser Saison schon einen Bogen haben, werden übersprungen.\n\nWICHTIG: Die Zugangsschlüssel werden NUR EINMAL angezeigt und können danach aus Sicherheitsgründen nicht erneut abgerufen werden.'))return;
  var $b=$(this).prop('disabled',true).text('Erstellt…');
  ajax('lsv07i_refl_boegen_erstellen',{mannschaft_id:Refl.mann}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    Refl.uebersicht=r.data.uebersicht||[];
    Refl.publicLink=r.data.public_link||Refl.publicLink||"";
    reflRenderUebersicht();
    var msg=r.data.erstellt+' Bögen erstellt';
    if(r.data.uebersprungen>0)msg+=', '+r.data.uebersprungen+' bereits vorhanden';
    toast(msg+'.');
    // Einmalige Schlüssel-Anzeige (Klartext kommt nur jetzt vom Server)
    if(r.data.erstellt>0 && r.data.schluessel && r.data.schluessel.length){
      reflZeigeNeueSchluessel(r.data.schluessel, r.data.ohne_geburtsdatum||[]);
    }
  }).always(function(){$b.prop('disabled',false).text('Bögen für Saison erstellen');});
});

// ── Einmalige Anzeige der frisch erzeugten Schlüssel ───────────────
function reflZeigeNeueSchluessel(schluessel,ohneGeb){
  var link=Refl.publicLink||'';
  var h='<div class="refl-error" style="background:rgba(217,119,6,0.20);border-color:#b45309;color:#b45309">'
       +'<strong>Wichtig:</strong> Diese Zugangsschlüssel werden <u>nur jetzt</u> angezeigt. '
       +'Notiere oder verteile sie sofort — sie können danach nicht mehr abgerufen werden. '
       +'Verlorene Schlüssel müssen neu erstellt werden.</div>';
  // Warnung: Schwimmer ohne Geburtsdatum können sich nicht einloggen
  if(ohneGeb && ohneGeb.length){
    h+='<div class="refl-error" style="margin-top:8px">'
      +'<strong>Achtung:</strong> Für folgende Schwimmer ist <u>kein Geburtsdatum</u> hinterlegt. '
      +'Sie können sich erst einloggen, wenn das Geburtsdatum in den Stammdaten ergänzt wurde: '
      +'<strong>'+ohneGeb.map(function(n){return esc(n);}).join(', ')+'</strong>.</div>';
  }
  h+='<p class="i-lbl-hint">Es gibt <strong>einen gemeinsamen Link</strong> für alle Schwimmer. '
    +'Jeder gibt dort seinen persönlichen Schlüssel <strong>und sein Geburtsdatum</strong> ein. '
    +'Der Link enthält keinen Namen.</p>';
  h+='<div class="refl-link-card" style="border-color:#5b94ff;background:rgba(91,148,255,0.08)">'
    +'<div class="refl-link-name">Gemeinsamer Link (für alle)</div>'
    +'<div class="refl-link-url"><input type="text" readonly value="'+esc(link)+'" onclick="this.select()"></div>'
    +'<button class="i-btn i-btn-p i-btn-sm refl-copy-link" data-link="'+esc(link)+'">Link kopieren</button>'
    +'</div>';
  h+='<div style="margin:14px 0 4px;display:flex;justify-content:space-between;align-items:center">'
    +'<strong style="font-size:13px;color:#6b6e85;text-transform:uppercase;letter-spacing:.04em">Schlüssel pro Schwimmer</strong>'
    +'<button class="i-btn i-btn-g i-btn-sm refl-copy-alle" id="refl-copy-alle">Alle kopieren</button>'
    +'</div>';
  var alleText='Reflexionsbogen-Link: '+link+'\n(Beim Öffnen Schlüssel UND Geburtsdatum eingeben)\n\n';
  $.each(schluessel,function(i,s){
    var gebWarn=s.kein_geburtsdatum?' <span class="refl-key-warn" title="Kein Geburtsdatum hinterlegt — Login nicht möglich">⚠ kein Geb.-Datum</span>':'';
    h+='<div class="refl-key-row">'
      +'<span class="refl-key-name">'+esc(s.swimmer_name)+gebWarn+'</span>'
      +'<span class="refl-key-code">'+esc(s.schluessel)+'</span>'
      +'<button class="i-btn i-btn-g i-btn-sm refl-copy-key" data-name="'+esc(s.swimmer_name)+'" data-key="'+esc(s.schluessel)+'" data-link="'+esc(link)+'">Kopieren</button>'
      +'</div>';
    alleText+=s.swimmer_name+': '+s.schluessel+'\n';
  });
  $('#m-refl-links-body').html(h);
  $('#refl-copy-alle').data('text',alleText);
  openModal('m-refl-links');
}

$(document).on('click','#refl-copy-alle',function(){
  reflCopy($(this).data('text'),'Alle Schlüssel kopiert.');
});

// ── Später: nur noch der gemeinsame Link (Schlüssel sind weg) ──────
$(document).on('click','#refl-alle-links',reflZeigeLinks);
function reflZeigeLinks(){
  var link=Refl.publicLink||'';
  var h='<p class="i-lbl-hint">Es gibt <strong>einen gemeinsamen Link</strong> für alle Schwimmer. '
       +'Jeder Schwimmer gibt auf der Seite seinen persönlichen Zugangsschlüssel ein.</p>';
  h+='<div class="refl-link-card" style="border-color:#5b94ff;background:rgba(91,148,255,0.08)">'
    +'<div class="refl-link-name">Gemeinsamer Link (für alle)</div>'
    +'<div class="refl-link-url"><input type="text" readonly value="'+esc(link)+'" onclick="this.select()"></div>'
    +'<button class="i-btn i-btn-p i-btn-sm refl-copy-link" data-link="'+esc(link)+'">Link kopieren</button>'
    +'</div>';
  h+='<div class="refl-error" style="margin-top:14px;background:rgba(255,255,255,0.06);border-color:#e2e8f0;color:#6b6e85">'
    +'Die persönlichen Zugangsschlüssel wurden aus Sicherheitsgründen nur einmal direkt nach dem Erstellen angezeigt '
    +'und sind nicht erneut abrufbar. Hat ein Schwimmer seinen Schlüssel verloren, lösche seinen Bogen und erstelle ihn neu.</div>';
  $('#m-refl-links-body').html(h);
  openModal('m-refl-links');
}

// Nur den gemeinsamen Link kopieren
$(document).on('click','.refl-copy-link',function(){
  reflCopy($(this).data('link'),'Link kopiert.');
});

// Schlüssel + Link für einen Schwimmer kopieren
$(document).on('click','.refl-copy-key',function(){
  var link=$(this).data('link'), key=$(this).data('key');
  var txt='Reflexionsbogen: '+link+'\nDein Zugangsschlüssel: '+key;
  reflCopy(txt,'Link + Schlüssel kopiert.');
});

function reflCopy(txt,okMsg){
  if(navigator.clipboard&&navigator.clipboard.writeText){
    navigator.clipboard.writeText(txt).then(function(){toast(okMsg);});
  } else {
    var ta=document.createElement('textarea');ta.value=txt;document.body.appendChild(ta);ta.select();
    try{document.execCommand('copy');toast(okMsg);}catch(e){toast('Kopieren nicht möglich.','err');}
    document.body.removeChild(ta);
  }
}

// ── Bogen ansehen ──────────────────────────────────────────────────
$(document).on('click','.refl-ansehen',function(){
  var id=$(this).data('id');
  ajax('lsv07i_refl_bogen_ansehen',{bogen_id:id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    var b=r.data.bogen||{}, antw=r.data.antworten||[];
    $('#m-refl-bogen-title').text('Bogen: '+(b.swimmer_name||''));
    var h='';
    var fragen=antw.filter(function(a){return a.typ==='frage';});
    var zeiten=antw.filter(function(a){return a.typ==='zeit';});
    if(fragen.length){
      h+='<h4 style="margin:0 0 10px">Fragen</h4>';
      $.each(fragen,function(i,a){
        h+='<div class="refl-view-q"><div class="refl-view-q-frage">'+esc(a.frage_text||'')+'</div>'
          +'<div class="refl-view-q-antwort">'+(a.antwort_text?esc(a.antwort_text).replace(/\n/g,'<br>'):'<span class="i-muted">— keine Antwort —</span>')+'</div></div>';
      });
    }
    if(zeiten.length){
      h+='<h4 style="margin:18px 0 10px">Bestzeiten &amp; Wunschzeiten</h4>';
      h+='<div class="refl-view-times"><div class="refl-view-times-head"><span>Strecke</span><span>Aktuell</span><span>Wunsch</span></div>';
      var sn=(window.LSV07I&&LSV07I.strecken)||{};
      $.each(zeiten,function(i,a){
        h+='<div class="refl-view-times-row"><span>'+esc(sn[a.strecke]||a.strecke)+'</span>'
          +'<span>'+esc(a.zeit_aktuell||'–')+'</span>'
          +'<span class="refl-wunsch-val">'+(a.wunschzeit?esc(a.wunschzeit):'<span class="i-muted">–</span>')+'</span></div>';
      });
      h+='</div>';
    }
    if(!fragen.length&&!zeiten.length)h='<p class="i-muted">Keine Antworten erfasst.</p>';
    $('#m-refl-bogen-body').html(h);
    openModal('m-refl-bogen');
  });
});

// ── Freischalten ───────────────────────────────────────────────────
$(document).on('click','.refl-frei',function(){
  var id=$(this).data('id');
  if(!confirm('Bogen wieder freischalten? Der Schwimmer kann dann erneut bearbeiten und absenden.'))return;
  ajax('lsv07i_refl_freischalten',{bogen_id:id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast('Freigeschaltet.');
    reflLadeUebersicht();
  });
});

// ── Löschen ────────────────────────────────────────────────────────
$(document).on('click','.refl-del',function(){
  var id=$(this).data('id'), name=$(this).data('name');
  if(!confirm('Bogen von „'+name+'" löschen? Alle Antworten gehen verloren.'))return;
  ajax('lsv07i_refl_bogen_loeschen',{bogen_id:id}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast('Gelöscht.');
    reflLadeUebersicht();
  });
});

// ── Archivieren ────────────────────────────────────────────────────
$(document).on('click','.refl-arch',function(){
  var id=$(this).data('id');
  ajax('lsv07i_refl_bogen_archivieren',{bogen_id:id,on:1}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast('Archiviert.');
    reflLadeUebersicht();
  });
});

// ── Aus Archiv zurückholen ─────────────────────────────────────────
$(document).on('click','.refl-unarch',function(){
  var id=$(this).data('id');
  ajax('lsv07i_refl_bogen_archivieren',{bogen_id:id,on:0}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    toast('Zurückgeholt.');
    reflLadeUebersicht();
  });
});

// ── Archiv-Bereich auf-/zuklappen ──────────────────────────────────
$(document).on('click','#refl-archiv-toggle',function(){
  var $body=$('#refl-archiv-body');
  var $caret=$(this).find('.refl-archiv-caret');
  if($body.is(':visible')){$body.slideUp(150);$caret.text('▶');}
  else{$body.slideDown(150);$caret.text('▼');}
});

// ── Stammfragen ────────────────────────────────────────────────────
function reflLadeFragen(){
  if(!Refl.mann)return;
  ajax('lsv07i_refl_fragen_get',{mannschaft_id:Refl.mann}).done(function(r){
    if(!r||!r.success)return;
    Refl.fragen=r.data.fragen||[];
    reflRenderFragen();
  });
}

function reflRenderFragen(){
  var h='';
  if(!Refl.fragen.length){
    h='<div class="abr-empty" style="margin-bottom:8px">Noch keine Fragen. Füge mit dem Button unten welche hinzu.</div>';
  } else {
    $.each(Refl.fragen,function(i,f){
      h+='<div class="refl-frage-item">'
        +'<span class="refl-frage-num">'+(i+1)+'.</span>'
        +'<input type="text" class="i-ctl refl-frage-input" value="'+esc(f.frage||'')+'" placeholder="Frage eingeben…">'
        +'<button class="i-btn i-btn-r i-btn-sm refl-frage-del" title="Entfernen">×</button>'
        +'</div>';
    });
  }
  $('#refl-fragen-liste').html(h);
}

$(document).on('click','#refl-frage-add',function(){
  Refl.fragen.push({frage:'',id:0});
  reflRenderFragen();
  // Fokus auf das neue Feld
  $('#refl-fragen-liste .refl-frage-input').last().focus();
});

$(document).on('click','.refl-frage-del',function(){
  var idx=$(this).closest('.refl-frage-item').index();
  // aktuelle Inputs einlesen bevor wir neu rendern
  reflSammleFragen();
  Refl.fragen.splice(idx,1);
  reflRenderFragen();
});

function reflSammleFragen(){
  var arr=[];
  $('#refl-fragen-liste .refl-frage-input').each(function(){
    arr.push({frage:$(this).val(),id:0});
  });
  Refl.fragen=arr;
}

$(document).on('click','#refl-fragen-save',function(){
  reflSammleFragen();
  var clean=Refl.fragen.filter(function(f){return (f.frage||'').trim()!=='';});
  if(!clean.length){toast('Bitte mindestens eine Frage eingeben.','err');return;}
  var $b=$(this).prop('disabled',true).text('Speichert…');
  ajax('lsv07i_refl_fragen_save',{mannschaft_id:Refl.mann,fragen:JSON.stringify(clean)}).done(function(r){
    if(!r||!r.success){toast((r&&r.data&&r.data.message)||'Fehler.','err');return;}
    Refl.fragen=r.data.fragen||[];
    reflRenderFragen();
    toast('Fragen gespeichert.');
  }).always(function(){$b.prop('disabled',false).text('Fragen speichern');});
});

/* ══ TICKETS ════════════════════════════════════════════════════════
   Eigenständiger Bereich. Eingeloggte Nutzer sehen standardmäßig nur
   ihre eigenen Tickets; Bearbeiter (TICKETS_READ_ALL) sehen alle.
   ════════════════════════════════════════════════════════════════════ */
var TK = {
  rows: [],
  sieht_alle: false,
  current: null,    // aktuell geöffnetes Ticket-Detail
  pendingAnhaenge: [],   // beim Erstellen: noch nicht gebundene Anhang-IDs
  pendingDetail:   [],   // beim Antworten: noch nicht gebundene Anhang-IDs
};

function tkFmtDT(s){
  if(!s)return '';
  // MySQL-Format "YYYY-MM-DD HH:MM:SS" → "DD.MM.YYYY, HH:MM"
  var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  if(!m)return s;
  return m[3]+'.'+m[2]+'.'+m[1]+', '+m[4]+':'+m[5];
}

function tkStatusBdg(status, label){
  var farben = {
    'offen':       'background:rgba(91,148,255,0.20);color:#2a5fd0',
    'in_arbeit':   'background:rgba(217,119,6,0.20);color:#b45309',
    'wartet':      'background:rgba(165,90,255,0.22);color:#7c3aed',
    'erledigt':    'background:rgba(22,163,74,0.22);color:#15803d',
    'geschlossen': 'background:rgba(255,255,255,0.12);color:#3d4060',
  };
  return '<span class="i-bdg" style="'+(farben[status]||'background:rgba(255,255,255,0.1)')+'">'+esc(label||status)+'</span>';
}

function tkPrioBdg(p, label){
  var farben = {
    'niedrig':  'background:rgba(255,255,255,0.06);color:#6b6e85',
    'normal':   'background:rgba(91,148,255,0.20);color:#2a5fd0',
    'hoch':     'background:rgba(217,119,6,0.22);color:#b45309',
    'dringend': 'background:rgba(220,38,38,0.22);color:#b91c1c',
  };
  return '<span class="i-bdg" style="'+(farben[p]||'background:rgba(255,255,255,0.1)')+'">'+esc(label||p)+'</span>';
}

function loadTickets(){
  var status = $('#tk-status-filter').val() || '';
  $('#tk-liste').html('<div class="i-spin">Wird geladen…</div>');
  ajax('lsv07i_tickets_list',{status:status}).done(function(r){
    if(!r||!r.success){
      $('#tk-liste').html('<div class="i-notice i-notice-r">'+esc((r&&r.data&&r.data.message)||'Fehler.')+'</div>');
      return;
    }
    TK.rows = r.data.tickets || [];
    TK.sieht_alle = !!r.data.sieht_alle;
    renderTickets();
  }).fail(function(xhr){
    $('#tk-liste').html('<div class="i-notice i-notice-r">Verbindungsfehler.</div>');
  });
}

function renderTickets(){
  var info = TK.sieht_alle
    ? 'Du siehst alle Tickets (Bearbeiter-Rechte).'
    : 'Du siehst hier nur Tickets, die du selbst erstellt hast.';
  $('#tk-info').text(info);

  if(!TK.rows.length){
    $('#tk-liste').html('<div class="i-muted" style="padding:20px;text-align:center">Keine Tickets vorhanden. Erstelle dein erstes Ticket mit „+ Neues Ticket".</div>');
    return;
  }

  var h = '<div class="i-twrap"><table class="i-tbl tk-tbl">'
        + '<thead><tr><th>Nr.</th><th>Titel</th><th>Kategorie</th><th>Status</th><th>Priorität</th>'
        + (TK.sieht_alle ? '<th>Ersteller</th>' : '')
        + '<th>Letzte Änderung</th><th></th></tr></thead><tbody>';

  $.each(TK.rows, function(i, t){
    h += '<tr class="tk-row" data-id="'+t.id+'" style="cursor:pointer">'
      + '<td style="font-family:ui-monospace,monospace;white-space:nowrap">#'+esc(t.nummer)+'</td>'
      + '<td><strong>'+esc(t.titel)+'</strong>'
      +   (t.anzahl_kommentare > 0 ? ' <span class="i-muted" style="font-size:11px">💬 '+t.anzahl_kommentare+'</span>' : '')
      + '</td>'
      + '<td>'+esc(t.kategorie_label)+'</td>'
      + '<td>'+tkStatusBdg(t.status, t.status_label)+'</td>'
      + '<td>'+tkPrioBdg(t.prioritaet, t.prioritaet_label)+'</td>'
      + (TK.sieht_alle ? '<td>'+esc(t.ersteller_name)+'</td>' : '')
      + '<td style="white-space:nowrap;font-size:11px">'+tkFmtDT(t.aktualisiert_am)+'</td>'
      + '<td><button class="i-btn i-btn-sm i-btn-g">Öffnen</button></td>'
      + '</tr>';
  });
  h += '</tbody></table></div>';
  $('#tk-liste').html(h);
}

// Filter ändert
$(document).on('change','#tk-status-filter',function(){loadTickets();});

// Klick auf Ticket-Zeile → Detail öffnen
$(document).on('click','.tk-row',function(){
  var id = $(this).data('id');
  openTicketDetail(id);
});

// ── Neues Ticket ──────────────────────────────────────────────────
$(document).on('click','#tk-neu-btn',function(){
  $('#tk-neu-titel').val('');
  $('#tk-neu-kategorie').val('frage');
  $('#tk-neu-prioritaet').val('normal');
  $('#tk-neu-text').val('');
  $('#tk-neu-anhaenge').empty();
  TK.pendingAnhaenge = [];
  openModal('m-tk-neu');
});

// Anhang hochladen — beim Erstellen
$(document).on('change','#tk-neu-file',function(){
  var files = this.files;
  if(!files || !files.length) return;
  for(var i=0; i<files.length; i++) tkUploadDatei(files[i], 'neu');
  $(this).val('');
});

// Anhang hochladen — im Detail
$(document).on('change','#tk-detail-file',function(){
  var files = this.files;
  if(!files || !files.length) return;
  for(var i=0; i<files.length; i++) tkUploadDatei(files[i], 'detail');
  $(this).val('');
});

function tkUploadDatei(file, ziel){
  var fd = new FormData();
  fd.append('action','lsv07i_tickets_upload');
  fd.append('nonce', LSV07I.nonce);
  fd.append('datei', file);
  var $target = (ziel==='neu') ? $('#tk-neu-anhaenge') : $('#tk-detail-anhaenge-pending');
  var placeholderId = 'tk-up-'+Math.random().toString(36).slice(2,9);
  $target.append('<div id="'+placeholderId+'" class="tk-anhang-pending"><span class="i-muted">📎 '+esc(file.name)+' wird hochgeladen…</span></div>');
  $.ajax({url:LSV07I.ajax_url, type:'POST', data:fd, processData:false, contentType:false}).done(function(r){
    if(!r || !r.success){
      toast((r && r.data && r.data.message)||'Upload fehlgeschlagen.','err');
      $('#'+placeholderId).remove();
      return;
    }
    var a = r.data;
    if(ziel==='neu') TK.pendingAnhaenge.push(a.id);
    else             TK.pendingDetail.push(a.id);
    $('#'+placeholderId).html(
      '<span class="tk-anhang-chip">'
      +(a.ist_bild?'🖼️':'📄')+' '+esc(a.dateiname)+' '
      +'<a href="#" class="tk-anhang-remove" data-id="'+a.id+'" data-ziel="'+ziel+'" style="color:#b91c1c;text-decoration:none">×</a>'
      +'</span>'
    );
  }).fail(function(){
    toast('Upload fehlgeschlagen.','err');
    $('#'+placeholderId).remove();
  });
}

$(document).on('click','.tk-anhang-remove',function(e){
  e.preventDefault();
  var id = parseInt($(this).data('id'),10);
  var ziel = $(this).data('ziel');
  if(ziel==='neu')    TK.pendingAnhaenge = TK.pendingAnhaenge.filter(function(x){return x!==id;});
  else                TK.pendingDetail   = TK.pendingDetail.filter(function(x){return x!==id;});
  $(this).closest('.tk-anhang-pending').remove();
});

// Ticket erstellen
$(document).on('click','#tk-neu-save',function(){
  var titel = $('#tk-neu-titel').val().trim();
  if(!titel){ toast('Titel ist Pflicht.','err'); return; }
  var data = {
    titel: titel,
    kategorie: $('#tk-neu-kategorie').val(),
    prioritaet: $('#tk-neu-prioritaet').val(),
    beschreibung: $('#tk-neu-text').val(),
  };
  if(TK.pendingAnhaenge.length){
    $.each(TK.pendingAnhaenge, function(i,id){ data['anhang_ids['+i+']']=id; });
  }
  var $b = $(this).prop('disabled',true).text('…');
  ajax('lsv07i_tickets_create', data).done(function(r){
    if(!r.success){ toast(r.data&&r.data.message||'Fehler.','err'); return; }
    toast('Ticket #'+r.data.nummer+' erstellt.');
    closeModal('m-tk-neu');
    TK.pendingAnhaenge = [];
    loadTickets();
    // direkt das neue Ticket öffnen
    openTicketDetail(r.data.id);
  }).fail(function(xhr){ toast(errMsg(xhr),'err'); })
    .always(function(){ $b.prop('disabled',false).text('Ticket erstellen'); });
});

// ── Ticket-Detail öffnen ──────────────────────────────────────────
function openTicketDetail(id){
  TK.pendingDetail = [];
  $('#tk-detail-anhaenge-pending').empty();
  $('#tk-detail-text').val('');
  $('#tk-detail-meta').html('<div class="i-spin">Wird geladen…</div>');
  $('#tk-detail-verlauf').empty();
  $('#m-tk-detail-title').text('Ticket');
  $('#tk-detail-loeschen').hide();
  openModal('m-tk-detail');

  ajax('lsv07i_tickets_get',{id:id}).done(function(r){
    if(!r.success){
      $('#tk-detail-meta').html('<div class="i-notice i-notice-r">'+esc(r.data.message||'Fehler.')+'</div>');
      return;
    }
    TK.current = r.data;
    renderTicketDetail();
  });
}

function renderTicketDetail(){
  var d = TK.current;
  if(!d) return;
  var t = d.ticket, r = d.rechte || {};
  if(!t){ toast('Ticket konnte nicht geladen werden.','err'); return; }
  $('#m-tk-detail-title').text('#'+t.nummer+' — '+t.titel);

  // Meta-Block (Status, Kategorie, Priorität, Ersteller, Zuweisung)
  var stati = d.stati || {};
  var statusSelect = r.kann_status
    ? ('<select id="tk-detail-status-sel" class="i-ctl i-ctl-sm" style="width:auto">'
        + Object.keys(stati).map(function(k){
            return '<option value="'+k+'"'+(t.status===k?' selected':'')+'>'+esc(stati[k])+'</option>';
          }).join('')
       +'</select>')
    : tkStatusBdg(t.status, t.status_label);

  var zuweisenBlock = '';
  if(r.kann_zuweisen){
    var opts = '<option value="0">— niemand —</option>';
    (d.users_for_assign||[]).forEach(function(u){
      opts += '<option value="'+u.id+'"'+(t.zugewiesen_an===u.id?' selected':'')+'>'+esc(u.label)+'</option>';
    });
    zuweisenBlock = '<div><strong>Zugewiesen an:</strong> <select id="tk-detail-zuweisen-sel" class="i-ctl i-ctl-sm" style="width:auto">'+opts+'</select></div>';
  } else if(t.zugewiesen_an_name){
    zuweisenBlock = '<div><strong>Zugewiesen an:</strong> '+esc(t.zugewiesen_an_name)+'</div>';
  }

  $('#tk-detail-meta').html(
    '<div class="tk-meta-grid">'
    + '<div><strong>Status:</strong> '+statusSelect+'</div>'
    + '<div><strong>Kategorie:</strong> '+esc(t.kategorie_label)+'</div>'
    + '<div><strong>Priorität:</strong> '+tkPrioBdg(t.prioritaet, t.prioritaet_label)+'</div>'
    + '<div><strong>Erstellt von:</strong> '+esc(t.ersteller_name)+' am '+tkFmtDT(t.erstellt_am)+'</div>'
    + zuweisenBlock
    + '</div>'
  );

  // Verlauf rendern
  renderTicketVerlauf(d.kommentare || []);

  // Eingabe-Bereich nur wenn berechtigt
  if(r.kann_kommentar){
    $('#tk-detail-input-wrap').show();
  } else {
    $('#tk-detail-input-wrap').hide();
  }

  // Lösch-Button
  if(r.kann_loeschen){
    $('#tk-detail-loeschen').show().data('id', t.id);
  } else {
    $('#tk-detail-loeschen').hide();
  }
}

function renderTicketVerlauf(komms){
  if(!komms.length){
    $('#tk-detail-verlauf').html('<div class="i-muted" style="padding:10px;text-align:center">Keine Nachrichten.</div>');
    return;
  }
  var h = '';
  komms.forEach(function(k){
    if(k.ist_system_event){
      h += '<div class="tk-event"><span class="tk-event-text">'+esc(k.text)+'</span> · <span class="tk-event-meta">'+esc(k.autor_name)+', '+tkFmtDT(k.erstellt_am)+'</span></div>';
      return;
    }
    var anhaengeHtml = '';
    (k.anhaenge||[]).forEach(function(a){
      if(a.ist_bild){
        anhaengeHtml += '<div class="tk-anh-bild"><a href="'+a.dl_url+'" target="_blank"><img src="'+a.dl_url+'" alt="'+esc(a.dateiname)+'" loading="lazy"></a></div>';
      } else {
        anhaengeHtml += '<div class="tk-anh-datei"><a href="'+a.dl_url+'" target="_blank">📄 '+esc(a.dateiname)+'</a> <span class="i-muted">('+tkFmtBytes(a.groesse)+')</span></div>';
      }
    });
    h += '<div class="tk-msg">'
      + '<div class="tk-msg-hd"><strong>'+esc(k.autor_name)+'</strong> <span class="i-muted">'+tkFmtDT(k.erstellt_am)+'</span></div>'
      + '<div class="tk-msg-bd">'+(k.text||'').replace(/\n/g,'<br>')+'</div>'
      + (anhaengeHtml ? '<div class="tk-msg-anhaenge">'+anhaengeHtml+'</div>' : '')
      + '</div>';
  });
  $('#tk-detail-verlauf').html(h);
  // Auto-scroll ans Ende
  var el = document.getElementById('tk-detail-verlauf');
  if(el) el.scrollTop = el.scrollHeight;
}

function tkFmtBytes(b){
  if(!b) return '0 B';
  if(b<1024) return b+' B';
  if(b<1024*1024) return (b/1024).toFixed(1)+' KB';
  return (b/1024/1024).toFixed(1)+' MB';
}

// Status ändern
$(document).on('change','#tk-detail-status-sel',function(){
  if(!TK.current) return;
  var status = $(this).val();
  ajax('lsv07i_tickets_status',{ticket_id:TK.current.ticket.id, status:status}).done(function(r){
    if(!r.success){ toast(r.data&&r.data.message||'Fehler.','err'); return; }
    toast('Status geändert.');
    openTicketDetail(TK.current.ticket.id);  // neu laden, damit System-Event erscheint
    loadTickets();
  });
});

// Zuweisung ändern
$(document).on('change','#tk-detail-zuweisen-sel',function(){
  if(!TK.current) return;
  var uid = parseInt($(this).val(),10)||0;
  ajax('lsv07i_tickets_assign',{ticket_id:TK.current.ticket.id, zugewiesen_an:uid}).done(function(r){
    if(!r.success){ toast(r.data&&r.data.message||'Fehler.','err'); return; }
    toast('Zuweisung geändert.');
    openTicketDetail(TK.current.ticket.id);
    loadTickets();
  });
});

// Kommentar senden
$(document).on('click','#tk-detail-senden',function(){
  if(!TK.current) return;
  var text = $('#tk-detail-text').val().trim();
  if(!text && !TK.pendingDetail.length){
    toast('Bitte Text oder Anhang.','err'); return;
  }
  var data = { ticket_id: TK.current.ticket.id, text: $('#tk-detail-text').val() };
  if(TK.pendingDetail.length){
    $.each(TK.pendingDetail, function(i,id){ data['anhang_ids['+i+']']=id; });
  }
  var $b = $(this).prop('disabled',true).text('…');
  ajax('lsv07i_tickets_comment', data).done(function(r){
    if(!r.success){ toast(r.data&&r.data.message||'Fehler.','err'); return; }
    $('#tk-detail-text').val('');
    $('#tk-detail-anhaenge-pending').empty();
    TK.pendingDetail = [];
    openTicketDetail(TK.current.ticket.id);
    loadTickets();
  }).fail(function(xhr){ toast(errMsg(xhr),'err'); })
    .always(function(){ $b.prop('disabled',false).text('Senden'); });
});

// Strg+Enter im Textfeld sendet
$(document).on('keydown','#tk-detail-text',function(e){
  if((e.ctrlKey||e.metaKey) && e.key==='Enter'){
    $('#tk-detail-senden').trigger('click');
  }
});

// Ticket löschen
$(document).on('click','#tk-detail-loeschen',function(){
  if(!TK.current) return;
  if(!confirm('Dieses Ticket wirklich löschen? Verlauf und Anhänge gehen unwiderruflich verloren.')) return;
  ajax('lsv07i_tickets_delete',{ticket_id:TK.current.ticket.id}).done(function(r){
    if(!r.success){ toast(r.data&&r.data.message||'Fehler.','err'); return; }
    toast('Ticket gelöscht.');
    closeModal('m-tk-detail');
    TK.current = null;
    loadTickets();
  });
});

/* ══ KOSTEN NACH MANNSCHAFT (Jahresübersicht-Zusatz) ═══════════════ */
function renderKostenMannschaft(d){
  var ms=d.mannschaften||[];
  var h='<div class="i-card" id="kw-kosten-mann" style="margin-top:16px"><div class="i-card-hd">Kosten nach Mannschaft '+d.jahr+' <span class="i-hint">(Training + Vorbereitung, nur genehmigte Abrechnungen)</span></div><div class="i-card-bd">';
  if(!ms.length){
    h+='<span class="i-muted">Keine genehmigten Abrechnungen für '+d.jahr+'.</span>';
  }else{
    var qs=['Q1','Q2','Q3','Q4'],sum=0;
    h+='<div style="overflow-x:auto"><table class="i-tbl" style="min-width:560px"><thead><tr><th style="text-align:left">Mannschaft</th>';
    $.each(qs,function(_,q){h+='<th style="text-align:right">'+q+'</th>';});
    h+='<th style="text-align:right">Gesamt</th></tr></thead><tbody>';
    $.each(ms,function(_,m){
      sum+=m.gesamt;
      h+='<tr><td data-label="Mannschaft" style="font-weight:500">'+esc(m.mannschaft)+'</td>';
      $.each(qs,function(_,q){
        var v=m.quartale[q];
        h+='<td data-label="'+q+'" style="text-align:right">'+(v?eur(v):'<span style="color:#8b8ea0">–</span>')+'</td>';
      });
      h+='<td data-label="Gesamt" style="text-align:right;font-weight:700">'+eur(m.gesamt)+'</td></tr>';
    });
    h+='<tr style="font-weight:700;background:rgba(91,148,255,0.12)"><td data-label="">Summe</td><td colspan="4"></td><td data-label="Gesamt" style="text-align:right">'+eur(sum)+'</td></tr>';
    h+='</tbody></table></div>';
    var so=d.sonstige||{};
    h+='<div class="i-hint" style="margin-top:10px">Nicht mannschaftsbezogen (genehmigt): Wettkampf-Pauschalen '+eur(so.wettkampf||0)+' · Fahrtkosten '+eur(so.kilometer||0)+'</div>';
  }
  h+='</div></div>';
  $('#kw-kosten-mann').remove();
  $('#kw-jahr-content').append(h);
}

/* ══ ATTEST-VERWALTUNG (Admin-Tab) ══════════════════════════════════ */
var S_attTplLoaded=false;
function loadAtteste(){
  if(!S_attTplLoaded){
    ajax('lsv07i_attest_template_get').done(function(r){
      if(r.success){$('#att-betreff').val(r.data.betreff);$('#att-text').val(r.data.text);S_attTplLoaded=true;}
    });
  }
  var $l=$('#att-liste');skel($l,'block');
  ajax('lsv07i_attest_liste').done(function(r){
    if(!r.success){$l.html('<div class="i-lbl-hint">Fehler beim Laden.</div>');return;}
    var rows=r.data||[];
    if(!rows.length){$l.html('<div class="i-notice i-notice-g">Aktuell läuft kein Attest in den nächsten 30 Tagen ab. 🎉</div>');return;}
    var h='<div class="att-wrap"><table class="i-tbl att-tbl"><thead><tr><th style="text-align:left">Name</th><th style="text-align:left">Mannschaft</th><th>Ablauf</th><th style="text-align:left">E-Mail (anpassbar)</th><th></th></tr></thead><tbody>';
    $.each(rows,function(_,s){
      var tage=parseInt(s.tage,10);
      var ablauf=tage<0?bdg('r','abgelaufen ('+de(s.attest_expires)+')'):tage===0?bdg('r','läuft heute ab'):bdg(tage<=7?'r':'a','in '+tage+' Tag'+(tage>1?'en':'')+' ('+de(s.attest_expires)+')');
      h+='<tr>'
        +'<td data-label="Name" style="font-weight:500">'+esc(s.first_name+' '+s.last_name)+'</td>'
        +'<td data-label="Mannschaft">'+esc(s.mannschaft||'–')+'</td>'
        +'<td data-label="Ablauf" style="text-align:center">'+ablauf+'</td>'
        +'<td data-label="E-Mail"><input type="email" class="i-ctl i-ctl-sm att-mail" data-id="'+s.id+'" value="'+esc(s.email||'')+'" placeholder="keine E-Mail hinterlegt"'+(parseInt(s.email_von_kontakt,10)===1?' title="Von Kontaktperson übernommen'+(s.kontakt_name?': '+esc(s.kontakt_name):'')+' – kann geändert werden"':'')+' data-no-save></td>'
        +'<td data-label="Aktion"><button class="i-btn i-btn-p i-btn-sm att-send" data-id="'+s.id+'"'+(s.email?'':' disabled title="Erst E-Mail-Adresse eintragen"')+'>Mail senden</button></td>'
        +'</tr>';
    });
    h+='</tbody></table></div>';
    $l.html(h);
  }).fail(function(xhr){$l.html('<span class="i-muted">'+esc(errMsg(xhr))+'</span>');});
}
$('#att-tpl-save').on('click',function(){
  var $b=$(this).prop('disabled',true);
  ajax('lsv07i_attest_template_save',{betreff:$('#att-betreff').val(),text:$('#att-text').val()})
    .done(function(r){r.success?toast('Vorlage gespeichert.'):toast(r.data&&r.data.message||'Fehler.','err');})
    .fail(function(xhr){toast(errMsg(xhr),'err');})
    .always(function(){$b.prop('disabled',false);});
});
// E-Mail direkt in der Liste anpassen (speichert beim Verlassen des Felds)
$(document).on('change','.att-mail',function(){
  var $f=$(this),id=$f.data('id'),mail=$f.val().trim();
  ajax('lsv07i_attest_email_update',{swimmer_id:id,email:mail}).done(function(r){
    if(r.success){
      toast('E-Mail aktualisiert.');
      $f.closest('tr').find('.att-send').prop('disabled',!mail).attr('title',mail?'':'Erst E-Mail-Adresse eintragen');
    }else{toast(r.data&&r.data.message||'Fehler.','err');}
  }).fail(function(xhr){toast(errMsg(xhr),'err');});
});
$(document).on('click','.att-send',function(){
  var $b=$(this),id=$b.data('id');
  $b.prop('disabled',true).text('Sende…');
  ajax('lsv07i_attest_mail_send',{swimmer_id:id}).done(function(r){
    if(r.success){toast(r.data.message||'Mail gesendet.');$b.text('Gesendet ✓');setTimeout(function(){$b.prop('disabled',false).text('Mail senden');},2500);}
    else{toast(r.data&&r.data.message||'Fehler.','err');$b.prop('disabled',false).text('Mail senden');}
  }).fail(function(xhr){toast(errMsg(xhr),'err');$b.prop('disabled',false).text('Mail senden');});
});

/* ══ SAISONBERICHT (PDF): Anwesenheit + Bestzeiten einer Mannschaft ═ */
$('#stat-saison-pdf').on('click',function(){
  var mid=$('#stat-mann').val();
  if(!mid){toast('Bitte zuerst eine Mannschaft wählen (nicht "Alle").','err');return;}
  var $b=$(this).prop('disabled',true).text('Erstelle…');
  ajax('lsv07i_anw_saison_bericht',{mannschaft_id:mid,von:$('#stat-von').val(),bis:$('#stat-bis').val()})
    .done(function(r){
      if(!r.success){toast(r.data&&r.data.message||'Fehler.','err');return;}
      saisonberichtPdf(r.data);
    })
    .fail(function(xhr){toast(errMsg(xhr),'err');})
    .always(function(){$b.prop('disabled',false).text('Saisonbericht (PDF)');});
});
function saisonberichtPdf(d){
  if(!window.jspdf||!window.jspdf.jsPDF){toast('PDF-Bibliothek nicht geladen. Bitte Seite neu laden.','err');return;}
  var doc=new window.jspdf.jsPDF({unit:'mm',format:'a4'});
  var W=210,M=14,y=18;
  function head(txt,sub){
    doc.setFillColor(30,58,95);doc.rect(0,0,W,26,'F');
    doc.setTextColor(255,255,255);doc.setFontSize(15);doc.setFont(undefined,'bold');
    doc.text(txt,M,11);
    doc.setFontSize(9);doc.setFont(undefined,'normal');
    doc.text(sub,M,18);
    doc.setTextColor(30,30,30);y=34;
  }
  function pageBreak(need){
    if(y+need>283){doc.addPage();y=16;}
  }
  var zeitraum=(d.von?pdfDe(d.von):'Beginn')+' – '+(d.bis?pdfDe(d.bis):'heute');
  head('Saisonbericht: '+d.mannschaft,'Zeitraum '+zeitraum+' · Trainings im Zeitraum: '+d.sessions_gesamt+' · erstellt am '+pdfDe(new Date().toISOString().slice(0,10)));

  // Abschnitt 1: Anwesenheit
  doc.setFontSize(12);doc.setFont(undefined,'bold');doc.text('Anwesenheit',M,y);y+=6;
  doc.setFontSize(9);doc.setFont(undefined,'normal');
  var cols=[M,86,112,140,166];
  doc.setFont(undefined,'bold');
  doc.text('Name',cols[0],y);doc.text('Anwesend',cols[1],y,{align:'right'});doc.text('Entschuldigt',cols[2],y,{align:'right'});doc.text('Abwesend',cols[3],y,{align:'right'});doc.text('Quote',cols[4],y,{align:'right'});
  doc.setFont(undefined,'normal');y+=2;doc.setDrawColor(160);doc.line(M,y,W-M,y);y+=5;
  $.each(d.schwimmer,function(_,s){
    pageBreak(6);
    var a=parseInt(s.anwesend,10)||0,e=parseInt(s.entschuldigt,10)||0,ab=parseInt(s.abwesend,10)||0;
    var ges=a+e+ab,q=ges>0?Math.round(a/ges*100):0;
    doc.text(String(s.name||'').slice(0,42),cols[0],y);
    doc.text(String(a),cols[1],y,{align:'right'});
    doc.text(String(e),cols[2],y,{align:'right'});
    doc.text(String(ab),cols[3],y,{align:'right'});
    doc.text(q+' %',cols[4],y,{align:'right'});
    y+=5.4;
  });
  if(!d.schwimmer.length){doc.text('Keine Anwesenheitsdaten im Zeitraum.',M,y);y+=6;}

  // Abschnitt 2: Bestzeiten
  y+=6;pageBreak(20);
  doc.setFontSize(12);doc.setFont(undefined,'bold');doc.text('Bestzeiten',M,y);y+=6;
  doc.setFontSize(9);doc.setFont(undefined,'normal');
  if(!d.bz_enthalten){
    doc.text('(Keine Berechtigung für Bestzeiten – Abschnitt ausgelassen.)',M,y);y+=6;
  }else if(!d.bestzeiten.length){
    doc.text('Keine Bestzeiten für diese Mannschaft erfasst.',M,y);y+=6;
  }else{
    var b1=M,b2=110,b3=168;
    doc.setFont(undefined,'bold');
    doc.text('Schwimmer',b1,y);doc.text('Strecke',b2,y);doc.text('Zeit',b3,y,{align:'right'});
    doc.setFont(undefined,'normal');y+=2;doc.line(M,y,W-M,y);y+=5;
    $.each(d.bestzeiten,function(_,b){
      pageBreak(6);
      doc.text(String(b.swimmer_name||'').slice(0,48),b1,y);
      doc.text(String(b.strecke||''),b2,y);
      doc.text(String(b.zeit_raw||''),b3,y,{align:'right'});
      y+=5.4;
    });
  }
  doc.setFontSize(8);doc.setTextColor(120);
  doc.text('LSV07 Intern · Saisonbericht',M,290);
  doc.save('Saisonbericht_'+d.mannschaft.replace(/[^A-Za-z0-9äöüÄÖÜß_-]+/g,'_')+'.pdf');
  toast('Saisonbericht erstellt.');
}

/* ══ AKTE: Name, Geburtsdatum, Mannschaft, Anwesenheit, Bestzeiten,
   Kommentar für einen wählbaren Zeitraum — nur eigene Mannschaften ═ */
var AKTE={personen:[],von:'',bis:'',mannLoaded:false};

function loadAkteMannschaften(){
  if(AKTE.mannLoaded)return;
  ajax('lsv07i_akte_mannschaften').done(function(r){
    if(!r.success)return;
    AKTE.mannLoaded=true;
    fillSel($('#akte-f-mann'),r.data.mannschaften||[],'id','name','Alle meine Mannschaften');
  });
}

$('#akte-laden').on('click',function(){
  var von=$('#akte-f-von').val(),bis=$('#akte-f-bis').val();
  if(!von||!bis){toast('Bitte Zeitraum (von/bis) angeben.','err');return;}
  if(von>bis){toast('"Von" darf nicht nach "Bis" liegen.','err');return;}
  var $b=$(this).prop('disabled',true).text('Lädt…');
  $('#akte-pdf').prop('disabled',true);
  skel($('#akte-liste'),'block');
  ajax('lsv07i_akte_daten',{mannschaft_id:$('#akte-f-mann').val(),von:von,bis:bis}).done(function(r){
    if(!r.success){$('#akte-liste').html('<span class="i-muted">'+esc(r.data&&r.data.message||'Fehler.')+'</span>');return;}
    AKTE.personen=r.data.personen||[];AKTE.von=r.data.von;AKTE.bis=r.data.bis;
    renderAkteListe(AKTE.personen);
    $('#akte-pdf').prop('disabled',!AKTE.personen.length);
  }).fail(function(xhr){$('#akte-liste').html('<span class="i-muted">'+esc(errMsg(xhr))+'</span>');})
    .always(function(){$b.prop('disabled',false).text('Anzeigen');});
});

function renderAkteListe(personen){
  if(!personen.length){$('#akte-liste').html('<div class="i-hint">Keine Personen im gewählten Zeitraum/Mannschaft gefunden.</div>');return;}
  var h='';
  $.each(personen,function(_,p){
    h+='<div class="i-card" style="margin-bottom:12px">'
      +'<div class="i-card-hd">'+esc(p.name)+'<span class="i-muted" style="font-size:12px;font-weight:400">'+(p.geburtsdatum?' · geb. '+pdfDe(p.geburtsdatum):'')+(p.dsv_id?' · DSV-ID '+esc(p.dsv_id):'')+'</span></div>'
      +'<div class="i-card-bd">';
    h+='<div style="display:flex;flex-wrap:wrap;gap:6px 18px;align-items:center;margin-bottom:10px;font-size:13px">';
    h+='<span>Attest: '+(p.attest_expires?attestBdg(p.attest_status)+' <span class="i-muted">(bis '+pdfDe(p.attest_expires)+')</span>':'<span class="i-muted">kein Attest hinterlegt</span>')+'</span>';
    if(p.kontakt){
      h+='<span>Kontakt: <strong>'+esc(p.kontakt.name)+'</strong>'+(p.kontakt.telefon?' · '+esc(p.kontakt.telefon):'')+(p.kontakt.email?' · '+esc(p.kontakt.email):'')+'</span>';
    }
    h+='</div>';
    if(p.mannschaften&&p.mannschaften.length){
      h+='<div class="i-twrap"><table class="i-tbl"><thead><tr><th>Mannschaft</th><th>Anwesend</th><th>Entschuldigt</th><th>Abwesend</th><th>Quote</th></tr></thead><tbody>';
      $.each(p.mannschaften,function(_,m){
        var a=parseInt(m.anwesend,10)||0,e=parseInt(m.entschuldigt,10)||0,ab=parseInt(m.abwesend,10)||0;
        var ges=a+e+ab,q=ges>0?Math.round(a/ges*100):0;
        h+='<tr><td data-label="Mannschaft">'+esc(m.name)+'</td><td data-label="Anwesend">'+a+'</td><td data-label="Entschuldigt">'+e+'</td><td data-label="Abwesend">'+ab+'</td><td data-label="Quote">'+q+' %</td></tr>';
      });
      h+='</tbody></table></div>';
    }else{
      h+='<div class="i-muted" style="font-size:12px">Keine Anwesenheitsdaten im Zeitraum.</div>';
    }
    if(p.wettkaempfe&&p.wettkaempfe.length){
      h+='<div class="i-muted" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 6px">Wettkampf-Teilnahmen</div>';
      $.each(p.wettkaempfe,function(_,w){
        var strecken=$.map(w.strecken||[],function(s){return s.strecke+(s.meldezeit?' ('+s.meldezeit+')':'');}).join(', ');
        h+='<div style="font-size:13px;margin-bottom:4px"><strong>'+esc(w.name)+'</strong> <span class="i-muted">'+pdfDe(w.datum_von)+(w.datum_bis!==w.datum_von?' – '+pdfDe(w.datum_bis):'')+(w.ort?' · '+esc(w.ort):'')+'</span><br><span class="i-muted">'+esc(strecken)+'</span></div>';
      });
    }
    if(p.bestzeiten&&p.bestzeiten.length){
      h+='<div class="i-muted" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 6px">Bestzeiten</div>'
        +'<div style="display:flex;flex-wrap:wrap;gap:6px 16px">';
      $.each(p.bestzeiten,function(_,b){
        h+='<span style="font-size:13px"><strong>'+esc(b.strecke)+'</strong> '+esc(b.zeit_raw)+'</span>';
      });
      h+='</div>';
    }
    if(p.kommentar){
      h+='<div class="i-muted" style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin:12px 0 4px">Kommentar</div>'
        +'<div style="font-size:13px;white-space:pre-wrap;line-height:1.5">'+esc(p.kommentar)+'</div>';
    }
    h+='</div></div>';
  });
  $('#akte-liste').html(h);
}

$('#akte-pdf').on('click',function(){
  if(!window.jspdf||!window.jspdf.jsPDF){toast('PDF-Bibliothek nicht geladen. Bitte Seite neu laden.','err');return;}
  if(!AKTE.personen.length){toast('Keine Daten zum Exportieren.','err');return;}
  var doc=new window.jspdf.jsPDF({unit:'mm',format:'a4'});
  var W=210,M=14,GRENZE=278; // GRENZE: unterhalb davon beginnt der Fuß-Bereich der Seite
  var zeitraum=pdfDe(AKTE.von)+' – '+pdfDe(AKTE.bis);

  function alterJahre(geb){
    if(!geb)return null;
    var t=new Date(geb+'T00:00:00'),h=new Date();
    if(isNaN(t.getTime()))return null;
    var a=h.getFullYear()-t.getFullYear();
    if(h.getMonth()<t.getMonth()||(h.getMonth()===t.getMonth()&&h.getDate()<t.getDate()))a--;
    return a;
  }
  function attestFarbe(status){
    return status==='abgelaufen'?[185,28,28]:status==='laeuft_ab'?[217,119,6]:status==='gueltig'?[22,163,74]:[120,120,120];
  }
  function attestText(status){
    return status==='abgelaufen'?'Abgelaufen':status==='laeuft_ab'?'Läuft bald ab':status==='gueltig'?'Gültig':'Kein Attest hinterlegt';
  }
  function feld(txt,x,y){doc.setFont(undefined,'bold');doc.setFontSize(7.5);doc.setTextColor(115);doc.text(txt.toUpperCase(),x,y);doc.setTextColor(25,25,25);}
  function titel(txt,y){doc.setFont(undefined,'bold');doc.setFontSize(10.5);doc.setTextColor(30,58,95);doc.text(txt,M,y);doc.setTextColor(25,25,25);doc.setFont(undefined,'normal');}
  // Begrenzt eine Zeilenliste auf das, was bis GRENZE-reserve noch passt;
  // hängt bei Kürzung eine Hinweiszeile an, statt die Seite zu sprengen.
  function kuerzen(zeilen,y,zeilenhoehe,reserve,einheit){
    var maxZeilen=Math.max(1,Math.floor((GRENZE-reserve-y)/zeilenhoehe));
    if(zeilen.length<=maxZeilen)return zeilen;
    var rest=zeilen.length-(maxZeilen-1);
    return zeilen.slice(0,Math.max(0,maxZeilen-1)).concat(['… und '+rest+' weitere '+einheit]);
  }

  $.each(AKTE.personen,function(idx,p){
    if(idx>0)doc.addPage();
    var y;

    // Kopfbanner
    doc.setFillColor(30,58,95);doc.rect(0,0,W,26,'F');
    doc.setTextColor(255,255,255);doc.setFontSize(16);doc.setFont(undefined,'bold');
    doc.text(String(p.name||''),M,12);
    doc.setFontSize(9);doc.setFont(undefined,'normal');
    doc.text('Akte · Zeitraum '+zeitraum+' · erstellt am '+pdfDe(heuteLokal()),M,19);
    doc.setTextColor(25,25,25);

    // Stammdaten-Box
    var mannNamen=$.map(p.mannschaften||[],function(m){return m.name;}).join(', ')||'–';
    var mannZeilen=doc.splitTextToSize(mannNamen,W-2*M-8);
    if(mannZeilen.length>2)mannZeilen=[mannZeilen[0],mannZeilen[1].replace(/\s*\S*$/,'')+' …'];
    var boxH=22+(mannZeilen.length>1?4.5:0);
    doc.setDrawColor(210);doc.setFillColor(247,248,250);
    doc.roundedRect(M,32,W-2*M,boxH,1.5,1.5,'FD');
    var c1=M+4,c2=M+(W-2*M)/2+2;
    var alt=alterJahre(p.geburtsdatum);
    feld('Geburtsdatum',c1,38);doc.setFontSize(10);doc.text(p.geburtsdatum?pdfDe(p.geburtsdatum)+(alt!==null?' ('+alt+' Jahre)':''):'–',c1,43.5);
    feld('DSV-ID',c2,38);doc.setFontSize(10);doc.text(p.dsv_id||'–',c2,43.5);
    feld('Mannschaft(en)',c1,48.5);doc.setFontSize(10);doc.text(mannZeilen,c1,54);
    var attRgb=attestFarbe(p.attest_status);
    feld('Attest',c2,48.5);doc.setFontSize(10);doc.setTextColor(attRgb[0],attRgb[1],attRgb[2]);
    doc.text(attestText(p.attest_status)+(p.attest_expires?' (bis '+pdfDe(p.attest_expires)+')':''),c2,54);
    doc.setTextColor(25,25,25);
    y=32+boxH+6;

    // Kontaktperson
    titel('Kontaktperson',y);y+=5.5;
    doc.setFontSize(9.5);
    if(p.kontakt){
      var kTeile=[p.kontakt.name];
      if(p.kontakt.telefon)kTeile.push('Tel. '+p.kontakt.telefon);
      if(p.kontakt.email)kTeile.push(p.kontakt.email);
      doc.text(kTeile.join('  ·  '),M,y);
    }else{
      doc.setTextColor(140);doc.text('Keine Kontaktperson hinterlegt.',M,y);doc.setTextColor(25,25,25);
    }
    y+=9;

    // Anwesenheit je Mannschaft
    titel('Anwesenheit im Zeitraum',y);y+=5.5;
    doc.setFontSize(9);
    if(p.mannschaften&&p.mannschaften.length){
      var cols=[M,95,124,152,178];
      doc.setFont(undefined,'bold');
      doc.text('Mannschaft',cols[0],y);doc.text('Anw.',cols[1],y,{align:'right'});doc.text('Entsch.',cols[2],y,{align:'right'});doc.text('Abw.',cols[3],y,{align:'right'});doc.text('Quote',cols[4],y,{align:'right'});
      doc.setFont(undefined,'normal');y+=1.5;doc.setDrawColor(200);doc.line(M,y,W-M,y);y+=4.5;
      var mannschaftenGekuerzt=p.mannschaften;
      var maxZeilen=Math.max(1,Math.floor((GRENZE-100-y)/4.2)); // Reserve für Wettkämpfe/Bestzeiten/Kommentar darunter
      if(mannschaftenGekuerzt.length>maxZeilen)mannschaftenGekuerzt=mannschaftenGekuerzt.slice(0,maxZeilen);
      $.each(mannschaftenGekuerzt,function(_,m){
        var a=parseInt(m.anwesend,10)||0,e=parseInt(m.entschuldigt,10)||0,ab=parseInt(m.abwesend,10)||0;
        var ges=a+e+ab,q=ges>0?Math.round(a/ges*100):0;
        doc.text(String(m.name||'').slice(0,44),cols[0],y);
        doc.text(String(a),cols[1],y,{align:'right'});
        doc.text(String(e),cols[2],y,{align:'right'});
        doc.text(String(ab),cols[3],y,{align:'right'});
        doc.text(q+' %',cols[4],y,{align:'right'});
        y+=4.2;
      });
      if(mannschaftenGekuerzt.length<p.mannschaften.length){
        doc.setTextColor(140);doc.text('… und '+(p.mannschaften.length-mannschaftenGekuerzt.length)+' weitere Mannschaft(en)',cols[0],y);doc.setTextColor(25,25,25);y+=4.2;
      }
    }else{
      doc.setTextColor(140);doc.text('Keine Anwesenheitsdaten im Zeitraum.',M,y);doc.setTextColor(25,25,25);y+=4.2;
    }
    y+=5;

    // Wettkampf-Teilnahmen
    titel('Wettkampf-Teilnahmen im Zeitraum',y);y+=5.5;
    doc.setFontSize(9);
    if(p.wettkaempfe&&p.wettkaempfe.length){
      var wkZeilen=[];
      $.each(p.wettkaempfe,function(_,w){
        var strecken=$.map(w.strecken||[],function(s){return s.strecke+(s.meldezeit?' ('+s.meldezeit+')':'');}).join(', ');
        var kopf=w.name+'  ·  '+pdfDe(w.datum_von)+(w.datum_bis!==w.datum_von?' – '+pdfDe(w.datum_bis):'')+(w.ort?'  ·  '+w.ort:'');
        wkZeilen.push({kopf:kopf,strecken:strecken||'–'});
      });
      var reserveDarunter=55; // Platz für Bestzeiten + Kommentar
      var maxWk=Math.max(0,Math.floor((GRENZE-reserveDarunter-y)/8.4));
      var wkGekuerzt=wkZeilen.slice(0,maxWk);
      $.each(wkGekuerzt,function(_,w){
        doc.setFont(undefined,'bold');doc.text(w.kopf,M,y);doc.setFont(undefined,'normal');y+=4.2;
        doc.setTextColor(90);doc.text(doc.splitTextToSize(w.strecken,W-2*M)[0]||'',M,y);doc.setTextColor(25,25,25);y+=4.2;
      });
      if(wkGekuerzt.length<wkZeilen.length){
        doc.setTextColor(140);doc.text('… und '+(wkZeilen.length-wkGekuerzt.length)+' weitere Wettkämpfe',M,y);doc.setTextColor(25,25,25);y+=4.2;
      }
    }else{
      doc.setTextColor(140);doc.text('Keine Wettkampf-Teilnahmen im Zeitraum.',M,y);doc.setTextColor(25,25,25);y+=4.2;
    }
    y+=5;

    // Bestzeiten
    titel('Bestzeiten',y);y+=5.5;
    doc.setFontSize(9);
    if(p.bestzeiten&&p.bestzeiten.length){
      var bzZeilen=$.map(p.bestzeiten,function(b){return b.strecke+' '+b.zeit_raw;});
      var bzWrapped=doc.splitTextToSize(bzZeilen.join('   ·   '),W-2*M);
      bzWrapped=kuerzen(bzWrapped,y,4.4,18,'Zeilen');
      doc.text(bzWrapped,M,y);
      y+=bzWrapped.length*4.4+3;
    }else{
      doc.setTextColor(140);doc.text('Keine Bestzeiten hinterlegt.',M,y);doc.setTextColor(25,25,25);y+=4.4+3;
    }

    // Kommentar
    titel('Kommentar',y);y+=5.5;
    doc.setFontSize(9);
    if(p.kommentar){
      var kwrapped=doc.splitTextToSize(String(p.kommentar),W-2*M);
      kwrapped=kuerzen(kwrapped,y,4.4,10,'Zeilen');
      doc.text(kwrapped,M,y);
    }else{
      doc.setTextColor(140);doc.text('–',M,y);doc.setTextColor(25,25,25);
    }

    // Fuß
    doc.setFontSize(8);doc.setTextColor(120);
    doc.text('LSV07 Intern · Akte',M,291);
    doc.text('Seite '+(idx+1)+' von '+AKTE.personen.length,W-M,291,{align:'right'});
    doc.setTextColor(25,25,25);
  });

  doc.save('Akte_'+AKTE.von+'_'+AKTE.bis+'.pdf');
  toast('Akte als PDF erstellt: '+AKTE.personen.length+' Seite(n), eine je Sportler.');
});

/* ══ REFLEXIONS-AUSWERTUNG (Antworten pro Frage gebündelt) ══════════ */
function loadReflAuswertung(){
  var mann=$('#refl-mann').val();
  var $c=$('#refl-ausw-inhalt');
  if(!mann){$c.html('<div class="i-hint">Bitte zuerst oben eine Mannschaft wählen.</div>');$('#refl-ausw-stat').text('');return;}
  skel($c,'block');
  ajax('lsv07i_refl_auswertung',{mannschaft_id:mann}).done(function(r){
    if(!r.success){$c.html('<span class="i-muted">'+esc(r.data&&r.data.message||'Fehler.')+'</span>');return;}
    var d=r.data;
    $('#refl-ausw-stat').text(d.abgegeben+' von '+d.gesamt+' Bögen abgegeben');
    if(!d.fragen.length){$c.html('<div class="i-hint">Noch keine abgegebenen Bögen in dieser Saison.</div>');return;}
    var h='';
    $.each(d.fragen,function(_,f){
      h+='<div class="i-card" style="margin-bottom:12px"><div class="i-card-hd" style="font-size:14px">'+esc(f.frage)+'</div><div class="i-card-bd" style="display:flex;flex-direction:column;gap:8px">';
      $.each(f.antworten,function(_,a){
        h+='<div style="padding:8px 12px;background:rgba(91,148,255,0.08);border-left:3px solid var(--c-blue);border-radius:6px">'
          +'<div style="font-size:11px;color:#6b6e85;margin-bottom:2px">'+esc(a.name)+'</div>'
          +'<div style="font-size:13px;white-space:pre-wrap;line-height:1.5">'+esc(a.text||'(keine Antwort)')+'</div>'
          +'</div>';
      });
      h+='</div></div>';
    });
    $c.html(h);
  }).fail(function(xhr){$c.html('<span class="i-muted">'+esc(errMsg(xhr))+'</span>');});
}

/* ══ SONDERABRECHNUNG (unter Trainer, eigenes Recht) ═══════════════
   Technisch ganz normale lsv07i_abrechnung mit bereich='sonder' — daher
   wird fürs Einreichen bewusst der BESTEHENDE lsv07i_abr_einreichen-
   Endpunkt wiederverwendet (gleiche Genehmiger, gleiche Kassenwart-
   Liste wie bei "Meine Abrechnung"). Nur Laden/Erstellen und die
   Tag+Stunden-Einträge haben eigene, einfachere Endpunkte.            */
var S_sonderAbr = null;

$('#tr-sonder-laden').on('click', function () {
  var $b = $(this).prop('disabled', true).text('Laden…');
  ajax('lsv07i_sonder_get_or_create', { quartal: $('#tr-sonder-quartal').val(), jahr: $('#tr-sonder-jahr').val() })
    .done(function (r) {
      if (!r.success) { toast(r.data && r.data.message ? r.data.message : 'Fehler.', 'err'); return; }
      S_sonderAbr = r.data;
      renderTrSonder(r.data);
    })
    .fail(function (xhr) { toast(errMsg(xhr), 'err'); })
    .always(function () { $b.prop('disabled', false).text('Laden / Erstellen'); });
});

function renderTrSonder(abr) {
  var ed = abr.abr_status === 'entwurf' || abr.abr_status === 'zurueck';
  $('#tr-sonder-titel').text('Sonderabrechnung ' + abr.quartal + ' ' + abr.jahr);

  var sb = '<div class="i-sb s-' + abr.abr_status + '"><strong>Status:</strong>&nbsp;' + sBdg(abr.abr_status);
  if (abr.abr_status === 'zurueck' && abr.rueckgabe_grund) sb += ' &nbsp;–&nbsp; <span style="color:#b91c1c">' + esc(abr.rueckgabe_grund) + '</span>';
  sb += '</div>';

  var satz = parseFloat(abr.stundensatz || 0);
  sb += '<div class="i-abr-summe">'
    + '<div class="i-abr-summe-item"><span class="i-abr-summe-label">Stundensatz</span><span class="i-abr-summe-val">' + eur(satz) + '/Std.</span></div>'
    + '<div class="i-abr-summe-item"><span class="i-abr-summe-label">Stunden (' + parseFloat(abr.sonder_stunden || 0).toFixed(2).replace('.', ',') + ' Std.)</span><span class="i-abr-summe-val">' + eur(abr.sonder_betrag) + '</span></div>'
    + '<div class="i-abr-summe-item"><span class="i-abr-summe-label">Gesamt</span><span class="i-abr-summe-val total">' + eur(abr.gesamt) + '</span></div>'
    + '</div>';

  $('#tr-sonder-statusbar').html(sb);
  $('#tr-sonder-aktionen').html(ed ? '<button class="i-btn i-btn-p" id="tr-sonder-einreichen">Einreichen</button>' : '');
  $('#tr-sonder-add').css('display', ed ? 'inline-flex' : 'none');
  $('#tr-sonder-detail-card').css('display', 'block');

  var eintraege = abr.sonder || [];
  if (!eintraege.length) {
    $('#tr-sonder-liste').html('<div class="abr-empty">Noch keine Einträge. Über den Button unten einen Tag mit Stunden hinzufügen.</div>');
    return;
  }
  var h = '';
  $.each(eintraege.slice().sort(function (a, b) { return a.datum.localeCompare(b.datum); }), function (i, t) {
    var std = parseFloat(t.stunden).toFixed(2).replace('.', ',');
    var betrag = parseFloat(t.stunden) * satz;
    var acts = ed
      ? '<button class="i-btn i-btn-g i-btn-sm ts-edit" data-id="' + t.id + '" title="Bearbeiten">✏️</button> <button class="i-btn i-btn-r i-btn-sm ts-del" data-id="' + t.id + '" title="Löschen">🗑</button>'
      : '';
    h += '<div class="i-vi" style="margin-bottom:8px"><div class="i-vh" style="cursor:default"><div>'
      + '<div style="font-weight:600;font-size:13px">' + de(t.datum) + ' &middot; ' + std + ' Std. &middot; ' + eur(betrag) + '</div>'
      + (t.beschreibung ? '<div style="font-size:12px;color:#6b6e85">' + esc(t.beschreibung) + '</div>' : '')
      + '</div><div style="display:flex;gap:6px">' + acts + '</div></div></div>';
  });
  $('#tr-sonder-liste').html(h);
}

$('#tr-sonder-add').on('click', function () {
  if (!S_sonderAbr) { toast('Bitte erst Abrechnung laden.', 'err'); return; }
  $('#mts-id').val(''); $('#mts-datum').val(''); $('#mts-stunden').val('1'); $('#mts-beschreibung').val('');
  $('#mts-abr-id').val(S_sonderAbr.id);
  $('#mts-ttl').text('Eintrag hinzufügen');
  openModal('m-tr-sonder');
});

$(document).on('click', '.ts-edit', function () {
  var tid = $(this).data('id'), t = null;
  $.each((S_sonderAbr && S_sonderAbr.sonder) || [], function (i, e) { if (e.id == tid) t = e; });
  if (!t) return;
  $('#mts-id').val(t.id); $('#mts-abr-id').val(S_sonderAbr.id);
  $('#mts-datum').val(t.datum); $('#mts-stunden').val(t.stunden); $('#mts-beschreibung').val(t.beschreibung || '');
  $('#mts-ttl').text('Eintrag bearbeiten');
  openModal('m-tr-sonder');
});

$(document).on('click', '.ts-del', function () {
  if (!confirm('Eintrag löschen?')) return;
  var $b = $(this).prop('disabled', true);
  ajax('lsv07i_sonder_delete_eintrag', { id: $(this).data('id') }).done(function (r) {
    if (r.success) { S_sonderAbr = r.data; renderTrSonder(r.data); toast('Gelöscht.'); }
    else toast(r.data.message, 'err');
  }).fail(function (xhr) { toast(errMsg(xhr), 'err'); }).always(function () { $b.prop('disabled', false); });
});

$('#mts-save').on('click', function () {
  var datum = $('#mts-datum').val(), stunden = $('#mts-stunden').val();
  if (!datum || !stunden || parseFloat(stunden) <= 0) { toast('Bitte Datum und Stunden angeben.', 'err'); return; }
  var $b = $(this).prop('disabled', true).text('Speichern…');
  ajax('lsv07i_sonder_save_eintrag', {
    abrechnung_id: $('#mts-abr-id').val(), id: $('#mts-id').val(),
    datum: datum, stunden: stunden, beschreibung: $('#mts-beschreibung').val().trim(),
  }).done(function (r) {
    if (r.success) { S_sonderAbr = r.data; renderTrSonder(r.data); closeModal('m-tr-sonder'); toast('Gespeichert.'); }
    else toast(r.data.message, 'err');
  }).always(function () { $b.prop('disabled', false).text('Speichern'); });
});

// Einreichen: bewusst der BESTEHENDE Endpunkt der regulären Abrechnung —
// Status-Übergänge sind bereich-unabhängig (siehe Kommentar oben).
$(document).on('click', '#tr-sonder-einreichen', function () {
  if (!S_sonderAbr) return;
  if (!confirm('Sonderabrechnung ' + S_sonderAbr.quartal + ' ' + S_sonderAbr.jahr + ' jetzt einreichen? Danach sind keine Änderungen mehr möglich, bis sie genehmigt oder zurückgegeben wird.')) return;
  var $b = $(this).prop('disabled', true).text('Wird eingereicht…');
  ajax('lsv07i_abr_einreichen', { abrechnung_id: S_sonderAbr.id }).done(function (r) {
    if (r.success) { S_sonderAbr = r.data; renderTrSonder(r.data); toast('Eingereicht.'); }
    else { toast(r.data.message, 'err'); $b.prop('disabled', false).text('Einreichen'); }
  }).fail(function (xhr) { toast(errMsg(xhr), 'err'); $b.prop('disabled', false).text('Einreichen'); });
});

})(jQuery);

// Erneuter Aufruf hier am Ende des Footers: erfasst auch Theme-Elemente,
// die im HTML NACH unserem App-Container folgen (z.B. Theme-Footer/Sidebar),
// welche beim frühen Aufruf im <head>-nahen Bereich noch nicht existierten.
if (window.lsv07iIsolate) window.lsv07iIsolate();
