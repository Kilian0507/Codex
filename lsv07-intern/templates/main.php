<?php
if(!defined('ABSPATH'))exit;
// Sichtbarkeit der Hauptbereiche: Rolle ODER explizite Lese-Permission.
// So sehen Nutzer mit zugewiesenen Rechten den Bereich auch dann, wenn
// ihre WP-Rolle ihn nicht von Haus aus freischaltet.
$_lsvPerm = class_exists('LSV07I_Permissions');
$cS=LSV07I_Access::is_intern()
    || ( $_lsvPerm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_MANNSCHAFT_READ ) );
$cTRI=LSV07I_Access::is_tri_intern()
    || ( $_lsvPerm && LSV07I_Permissions::can_current( LSV07I_Permissions::TRIATHLON_GRUPPE_READ ) );
$cTW=LSV07I_Access::is_triathlonwart();
$cFIT=LSV07I_Access::is_fit_intern()
    || ( $_lsvPerm && LSV07I_Permissions::can_current( LSV07I_Permissions::FITNESS_GRUPPE_READ ) );
$cFW=LSV07I_Access::is_fitnesswart();
// Schwimmwart-Sicht (Abrechnungen genehmigen): Rolle ODER Permission
$cSW=LSV07I_Access::is_schwimmwart()
    || ( $_lsvPerm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_READ ) );
// Finanzwart-/Kassen-Sicht: Rolle ODER Permission
$cKW=LSV07I_Access::is_finanzwart()
    || ( $_lsvPerm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_KASSE_READ ) );
// Verwaltungs-Bereich sichtbar, sobald eine der beiden Sichten freigeschaltet ist
$cV=$cSW||$cKW;
$cT=LSV07I_Access::is_trainer()
    || ( $_lsvPerm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_EIGEN_READ ) );
$cA=LSV07I_Access::is_admin();
$first='home';

// Pro-Tab-Sichtbarkeit aus der zentralen Access-Map (Rolle ODER Leserecht).
// So lässt sich jeder Tab einzeln in der Rechteverwaltung freischalten.
$_lsvTabs = LSV07I_Access::get_access_map()['tabs'] ?? [];
$T = function($k) use ($_lsvTabs){ return !empty($_lsvTabs[$k]); };
// Schwimmbereich sichtbar, wenn mindestens einer seiner Tabs sichtbar ist.
$cS = $cS || $T('sw_mann')||$T('sw_anw')||$T('sw_wk')||$T('sw_spr')||$T('sw_bz')||$T('sw_refl');
$cTRI = $cTRI || $T('tri_mann')||$T('tri_anw');
$cFIT = $cFIT || $T('fit_mann')||$T('fit_anw');
$cT = $cT || $T('tr_sonder');
$cA = $cA || $T('adm_log') || $T('adm_saison') || $T('adm_atteste') || $T('adm_rechte');
// Darstellung (hell/dunkel/auto) serverseitig ermitteln, damit beim Laden
// nichts in der falschen Farbe aufblitzt.
$_lsv_theme = class_exists('LSV07I_Ajax_Profil')
    ? LSV07I_Ajax_Profil::theme_von( get_current_user_id() )
    : 'hell';
/**
 * Symbol für eine Navigationskachel. Alle Zeichen liegen hier zentral,
 * damit die Bereiche dieselbe Bildsprache verwenden.
 */
if ( ! function_exists( 'lsv07i_navsvg' ) ) {
function lsv07i_navsvg( $name ) {
    $p = [
        'gruppe'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'haken'    => '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'pokal'    => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M6 3h12v6a6 6 0 0 1-12 0z"/><path d="M12 15v4M8 21h8"/>',
        'tausch'   => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'stoppuhr' => '<circle cx="12" cy="13" r="8"/><path d="M12 13V9M9 2h6"/>',
        'blatt'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
        'person'   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'geld'     => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'kasse'    => '<rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20"/><circle cx="17" cy="15" r="1.4"/>',
        'stern'    => '<polygon points="12 2 15.1 8.6 22 9.6 17 14.5 18.2 21.5 12 18.2 5.8 21.5 7 14.5 2 9.6 8.9 8.6 12 2"/>',
        'ausweis'  => '<rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="9" cy="11" r="2.5"/><path d="M5 17c.7-1.6 2.2-2.5 4-2.5s3.3.9 4 2.5"/><line x1="15.5" y1="10" x2="19" y2="10"/><line x1="15.5" y1="13.5" x2="19" y2="13.5"/>',
        'import'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'schild'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        'kalender' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'log'      => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3.5" cy="6" r="1.2" fill="currentColor"/><circle cx="3.5" cy="12" r="1.2" fill="currentColor"/><circle cx="3.5" cy="18" r="1.2" fill="currentColor"/>',
        'attest'   => '<path d="M9 2h6v3h4v17H5V5h4z"/><line x1="12" y1="10" x2="12" y2="16"/><line x1="9" y1="13" x2="15" y2="13"/>',
        'zahnrad'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'schwimmer'=> '<path d="M2 16c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2"/><path d="M2 20c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2"/><circle cx="17" cy="7" r="3"/>',
        'uhr'      => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 14"/>',
        'mail'     => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
        'tabelle'  => '<rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="20"/>',
    ];
    $inhalt = $p[ $name ] ?? $p['gruppe'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
         . ' stroke-linecap="round" stroke-linejoin="round">' . $inhalt . '</svg>';
}
}

/** Eine Navigationskachel (verhält sich wie ein Reiter). */
if ( ! function_exists( 'lsv07i_navicon' ) ) {
function lsv07i_navicon( $panel, $label, $icon, $aktiv = false ) {
    echo '<button class="i-tab i-navicon' . ( $aktiv ? ' on' : '' ) . '"'
       . ' data-panel="' . esc_attr( $panel ) . '">'
       . '<span class="i-navicon-kachel">' . lsv07i_navsvg( $icon ) . '</span>'
       . '<span class="i-navicon-name">' . esc_html( $label ) . '</span>'
       . '</button>';
}
}

if(!function_exists('lsv07i_isec')){function lsv07i_isec($k,$f){return 'style="display:'.($k===$f?'block':'none').'"';}}
?>
<div id="lsv07i-loader"></div>
<div id="lsv07i-toast" role="status" aria-live="polite"></div>
<script>
/* Browser-Leisten einfärben: Safari (iOS 15+) und Chrome färben ihre
   eigene Oberfläche (Adressleiste unten, Statusbereich oben) nach dem
   <meta name="theme-color">. Ohne diese Angabe bleiben die Leisten weiß
   und wirken wie ein heller Rand ober-/unterhalb der dunklen App.
   Vorhandene theme-color-Metas des Themes (auch Varianten mit media=
   dark/light) werden auf die App-Farbe umgestellt; fehlt eines, wird es
   erzeugt. Läuft bewusst ganz früh, damit nichts weiß aufblitzt.        */
(function(){
  /* Die gewählte Darstellung kommt aus PHP — dieses Skript läuft, bevor
     #lsv07i-root im DOM steht, ein getElementById käme also zu früh. */
  var wahl = <?php echo wp_json_encode( $_lsv_theme ); ?>;
  var dunkel = wahl === 'dunkel'
    || (wahl === 'auto' && window.matchMedia
        && window.matchMedia('(prefers-color-scheme: dark)').matches);
  var farbe = dunkel ? '#12141c' : '#eef3fb'; /* = --g-bg der jeweiligen Darstellung */
  try{
    var metas = document.querySelectorAll('meta[name="theme-color"]');
    if (metas.length) {
      for (var i = 0; i < metas.length; i++) metas[i].setAttribute('content', farbe);
    } else {
      var m = document.createElement('meta');
      m.setAttribute('name', 'theme-color');
      m.setAttribute('content', farbe);
      (document.head || document.documentElement).appendChild(m);
    }
    /* color-scheme "light" erzwingen: steuert, ob Safari seine EIGENEN
       Flaechen (Leisten, Canvas) hell oder dunkel malt — hier immer hell,
       auch wenn das Geraet im dunklen System-Modus laeuft.              */
    var cs = document.querySelectorAll('meta[name="color-scheme"]');
    if (cs.length) {
      for (var j = 0; j < cs.length; j++) cs[j].setAttribute('content', 'light');
    } else {
      var c = document.createElement('meta');
      c.setAttribute('name', 'color-scheme');
      c.setAttribute('content', 'light');
      (document.head || document.documentElement).appendChild(c);
    }
    document.documentElement.style.colorScheme = 'light';
    /* viewport-fit=cover erzwingen: das Theme koennte sein viewport-Meta
       NACH unserem serverseitigen ausgeben (dann gilt seins, ohne cover,
       und die Seite reicht nicht hinter die Leisten). Eine Laufzeit-
       Aenderung am content wird von iOS-Safari neu ausgewertet.         */
    var vp = document.querySelectorAll('meta[name="viewport"]');
    var soll = 'width=device-width, initial-scale=1, viewport-fit=cover';
    if (vp.length) {
      for (var k = 0; k < vp.length; k++) vp[k].setAttribute('content', soll);
    } else {
      var v = document.createElement('meta');
      v.setAttribute('name', 'viewport');
      v.setAttribute('content', soll);
      (document.head || document.documentElement).appendChild(v);
    }
  }catch(e){}
})();

/* Theme-agnostische Isolierung: Statt geratener CSS-Selektoren für Header/
   Footer/Sidebar des jeweiligen WordPress-Themes (die je nach Theme anders
   heißen und daher nicht immer greifen — sichtbar z.B. an durchscheinendem
   Theme-Header unterhalb der App), blenden wir strukturell alles aus, was
   NICHT auf dem Pfad von <body> zu #lsv07i-root liegt. Läuft früh (bevor
   der Rest der Seite geparst ist) um Aufblitzen zu vermeiden, und wird in
   app.js am Ende des Footers erneut ausgeführt, um auch nachträglich im
   HTML folgende Elemente (z.B. Theme-Footer) zu erfassen.               */
window.lsv07iIsolate = function () {
  var root = document.getElementById('lsv07i-root');
  if (!root || !document.body.classList.contains('lsv07i-fullscreen')) return;
  // <html> spiegelt die fullscreen-Klasse von <body>, damit CSS-Regeln wie
  // overscroll-behavior (gegen iOS-Rubber-Band-Bounce) sofort greifen und
  // nicht erst auf $(document).ready in app.js warten müssen.
  document.documentElement.classList.add('lsv07i-fullscreen');
  function keep(node) {
    return node.id === 'lsv07i-loader' || node.id === 'wpadminbar'
      || node.tagName === 'SCRIPT' || node.tagName === 'STYLE' || node.tagName === 'NOSCRIPT' || node.tagName === 'LINK';
  }
  var el = root;
  while (el && el.parentElement && el.parentElement !== document.body) {
    var parent = el.parentElement;
    for (var i = 0; i < parent.children.length; i++) {
      var sib = parent.children[i];
      if (sib !== el && !keep(sib)) sib.style.setProperty('display', 'none', 'important');
    }
    // Wrapper selbst darf keine eigene Höhe/Breite/Padding einschränken
    parent.style.setProperty('max-width', 'none', 'important');
    parent.style.setProperty('width', '100%', 'important');
    parent.style.setProperty('margin', '0', 'important');
    parent.style.setProperty('padding', '0', 'important');
    el = parent;
  }
  if (el && el.parentElement === document.body) {
    var kids = document.body.children;
    for (var j = 0; j < kids.length; j++) {
      var k = kids[j];
      if (k === el || keep(k)) continue;
      k.style.setProperty('display', 'none', 'important');
    }
  }
};
window.lsv07iIsolate();
</script>
<div id="lsv07i-root" data-theme="<?php echo esc_attr($_lsv_theme);?>">

<!-- NAV -->
<div id="lsv07i-nav">
 <div id="lsv07i-nav-inner">
  <?php
  // WordPress-Custom-Logo (Theme-Customizer) — wenn gesetzt, anzeigen
  $_lsv_logo_id  = function_exists('get_theme_mod') ? get_theme_mod('custom_logo') : 0;
  $_lsv_logo_src = '';
  if ( $_lsv_logo_id ) {
      $img = wp_get_attachment_image_src( $_lsv_logo_id, 'medium' );
      if ( $img && ! empty( $img[0] ) ) $_lsv_logo_src = $img[0];
  }
  ?>
  <a id="lsv07i-extlogo" href="https://lsv07.info" target="_self" rel="noopener"
     title="Zur Vereinsseite lsv07.info"
     <?php echo $_lsv_logo_src ? '' : 'style="display:none"'; ?>>
   <?php if ( $_lsv_logo_src ): ?>
     <img src="<?php echo esc_url( $_lsv_logo_src ); ?>" alt="LSV07" />
   <?php endif; ?>
  </a>
  <div id="lsv07i-logo" data-sec="home" style="cursor:pointer"><span>LSV07 Intern</span></div>

  <!-- Desktop-Buttons -->
  <div id="lsv07i-nav-btns">
   <button class="i-nb<?php echo $first==='home'?' on':''?>" data-sec="home">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12 12 3l9 9"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg></span>
     <span>Start</span>
   </button>
   <?php if($cS):?><button class="i-nb<?php echo $first==='s'?' on':''?>" data-sec="s">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 16c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2"/><path d="M2 20c2 0 2-2 4-2s2 2 4 2 2-2 4-2 2 2 4 2 2-2 4-2"/><circle cx="17" cy="7" r="3"/></svg></span>
     <span>Schwimmen</span>
   </button><?php endif;?>
   <?php if($cTRI):?><button class="i-nb<?php echo $first==='tri'?' on':''?>" data-sec="tri">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="m6 17 4-9 8 9"/><path d="m10 8 4-3"/></svg></span>
     <span>Triathlon</span>
   </button><?php endif;?>
   <?php if($cFIT):?><button class="i-nb<?php echo $first==='fit'?' on':''?>" data-sec="fit">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4v16M18 4v16M4 8h4M4 12h4M4 16h4M16 8h4M16 12h4M16 16h4M8 12h8"/></svg></span>
     <span>Fitness</span>
   </button><?php endif;?>
   <?php if($cT):?><button class="i-nb<?php echo $first==='t'?' on':''?>" data-sec="t">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
     <span>Trainer</span>
   </button><?php endif;?>
   <?php if(LSV07I_Access::has_any_access()):?><button class="i-nb" data-sec="tk" id="nav-tickets">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H4v4a2 2 0 0 1 0 4v4h16v-4a2 2 0 0 1 0-4z"/><path d="M9 8v8M15 8v8"/></svg></span>
     <span>Tickets</span>
   </button><?php endif;?>
   <?php if(LSV07I_Access::has_any_access()):?><button class="i-nb" data-sec="n" id="nav-nachrichten">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>
     <span>Nachrichten</span>
   </button><?php endif;?>
   <?php if($cV):?><button class="i-nb<?php echo $first==='v'?' on':''?>" data-sec="v">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2h-4"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/></svg></span>
     <span>Verwaltung</span>
   </button><?php endif;?>
   <?php if($cA):?><button class="i-nb<?php echo $first==='a'?' on':''?>" data-sec="a">
     <span class="i-nb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
     <span>Admin</span>
   </button><?php endif;?>
  </div>

  <!-- Desktop: Name + Abmelden -->
  <div id="lsv07i-nav-right">
   <span id="lsv07i-navuser"><?php echo esc_html(wp_get_current_user()->display_name);?></span>
   <a href="<?php echo esc_url(wp_logout_url(get_permalink()));?>" id="lsv07i-logout">Abmelden</a>
  </div>

  <!-- Mobile: Hamburger-Button -->
  <button id="lsv07i-hamburger" aria-label="Menü öffnen">
   <span></span><span></span><span></span>
  </button>
 </div>
</div>

<!-- Mobile: Drawer-Overlay (öffnet die Sidebar von links) -->
<div id="lsv07i-drawer-overlay"></div>


<!-- BODY -->
<div id="lsv07i-body">

<!-- ═══════════════════════════════════════════════════════════════════════
     STARTSEITE / HOME
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="i-sec-home" class="i-sec" <?php echo lsv07i_isec('home',$first);?>>
 <div class="i-sec-hd" style="display:none"><h2>Startseite</h2></div>

 <?php
 $_cu        = wp_get_current_user();
 $_bild_url  = class_exists('LSV07I_Ajax_Profil') ? LSV07I_Ajax_Profil::bild_url( $_cu->ID ) : '';
 $_initialen = class_exists('LSV07I_Ajax_Profil') ? LSV07I_Ajax_Profil::initialen( $_cu )    : '?';
 $_vorname   = $_cu->first_name ?: ( $_cu->display_name ?: $_cu->user_login );
 ?>

 <!-- ── Profilzeile ─────────────────────────────────────────────────── -->
 <div class="i-home-kopf">
  <div class="i-avatar i-avatar-gross" id="home-avatar">
   <?php if ( $_bild_url ): ?>
    <img src="<?php echo esc_url( $_bild_url ); ?>" alt="">
   <?php else: ?>
    <span class="i-avatar-init"><?php echo esc_html( $_initialen ); ?></span>
   <?php endif; ?>
  </div>
  <div class="i-home-kopf-text">
   <div class="i-home-kopf-gruss">Willkommen zurück</div>
   <div class="i-home-kopf-name" id="home-kopf-name"><?php echo esc_html( $_vorname ); ?></div>
  </div>
  <div class="i-home-kopf-aktionen">
   <button type="button" class="i-rundbtn" id="home-btn-nachrichten" aria-label="Nachrichten" title="Nachrichten">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <span class="i-rundbtn-badge" id="home-msg-badge" style="display:none">0</span>
   </button>
   <button type="button" class="i-rundbtn" id="home-btn-einstellungen" aria-label="Einstellungen" title="Einstellungen">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
   </button>
  </div>
 </div>

 <!-- ── Schnellzugriff als App-Icons ────────────────────────────────── -->
 <div class="i-card i-home-karte">
  <div class="i-card-hd">Schnellzugriff</div>
  <div class="i-card-bd">
   <div id="home-widgets-grid" class="i-appgrid">
    <div class="i-muted" style="grid-column:1/-1;padding:6px 0">Noch keine Schnellzugriffe gewählt — über das Zahnrad oben unter „Widgets“ auswählen.</div>
   </div>
  </div>
 </div>

 <!-- ── Trainings-Statistik der letzten 5 Termine ───────────────────── -->
 <div class="i-card i-home-karte" id="home-karte-training" style="display:none">
  <div class="i-card-hd">
   Letzte 5 Trainings
   <select id="home-gruppe-sel" class="i-ctl" style="width:auto"></select>
  </div>
  <div class="i-card-bd" id="home-training-inhalt"><div class="i-spin">Wird geladen…</div></div>
 </div>

 <!-- ── Top 5 Schwimmer ─────────────────────────────────────────────── -->
 <?php if($cS):?>
 <div class="i-card i-home-karte" id="home-karte-top" style="display:none">
  <div class="i-card-hd">Top 5 Schwimmer
   <span class="i-muted" style="font-size:11px;font-weight:400">nach Bestzeiten</span>
  </div>
  <div class="i-card-bd" id="home-top-inhalt"><div class="i-spin">Wird geladen…</div></div>
 </div>
 <?php endif;?>

 <!-- ── Drei Info-Kacheln ───────────────────────────────────────────── -->
 <div class="i-home-info">
  <div class="i-card i-home-info-karte" id="home-info-abr">
   <div class="i-home-info-ico">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
   </div>
   <div class="i-home-info-txt">
    <div class="i-home-info-lbl">Abrechnung <span id="home-abr-quartal">–</span></div>
    <div class="i-home-info-wert" id="home-abr-betrag">…</div>
    <div class="i-home-info-sub" id="home-abr-status">&nbsp;</div>
   </div>
  </div>
  <div class="i-card i-home-info-karte" id="home-info-wk">
   <div class="i-home-info-ico">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M6 3h12v6a6 6 0 0 1-12 0z"/><path d="M12 15v4M8 21h8"/></svg>
   </div>
   <div class="i-home-info-txt">
    <div class="i-home-info-lbl">Nächster Wettkampf</div>
    <div class="i-home-info-wert" id="home-wk-name">…</div>
    <div class="i-home-info-sub" id="home-wk-datum">&nbsp;</div>
   </div>
  </div>
  <div class="i-card i-home-info-karte" id="home-info-tr">
   <div class="i-home-info-ico">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
   </div>
   <div class="i-home-info-txt">
    <div class="i-home-info-lbl">Nächstes Training</div>
    <div class="i-home-info-wert" id="home-tr-datum">…</div>
    <div class="i-home-info-sub" id="home-tr-sub">&nbsp;</div>
   </div>
  </div>
 </div>

 <?php if(!$cS && !$cTRI && !$cFIT):?>
 <div class="i-card"><div class="i-card-bd">
  <p>Willkommen im internen Bereich! Sie haben aktuell keine Berechtigung für Schwimmen, Triathlon oder Fitness. Wählen Sie oben einen Bereich aus, für den Sie freigeschaltet sind.</p>
 </div></div>
 <?php endif;?>

</div><!-- /i-sec-home -->

<?php /* ══ SCHWIMMEN ══ */ if($cS):?>

<div id="i-sec-s" class="i-sec" <?php echo lsv07i_isec('s',$first);?>>
 <div class="i-sec-hd" style="display:none"><h2>Schwimmen</h2></div>

 <!-- Navigation als Symbolkacheln, wie der Schnellzugriff auf der Startseite -->
 <div class="i-tabs i-navgrid">
  <?php if($T('sw_mann')):    lsv07i_navicon('i-p-mann',       'Mannschaften','gruppe');   endif;?>
  <?php if($T('sw_anw')):     lsv07i_navicon('i-p-anw',        'Anwesenheit', 'haken');    endif;?>
  <?php if($T('sw_wk')):      lsv07i_navicon('i-p-wk',         'Wettkämpfe',  'pokal');    endif;?>
  <?php if($T('sw_spr')):     lsv07i_navicon('i-p-spr',        'Springer',    'tausch');   endif;?>
  <?php if($T('sw_bz')):      lsv07i_navicon('i-p-bz',         'Bestzeiten',  'stoppuhr'); endif;?>
  <?php if($T('sw_meld')):    lsv07i_navicon('i-p-meld',       'Meldungen',   'tabelle');  endif;?>
  <?php if($T('sw_refl')):    lsv07i_navicon('i-p-refl',       'Reflexion',   'blatt');    endif;?>
  <?php if($T('sw_trainer')): lsv07i_navicon('i-p-sw-trainer', 'Trainer',     'person');   endif;?>
 </div>

 <!-- Mannschaft -->
 <div id="i-p-mann" class="i-panel" style="display:block">
  <div class="i-card">
   <div class="i-card-hd">Mannschaftsverwaltung <select id="mv-filter" class="i-ctl" style="width:auto"><option value="">Alle Mannschaften</option></select>
   </div>
   <div class="i-card-bd" id="mv-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>

 <!-- Anwesenheit -->
 <div id="i-p-anw" class="i-panel" style="display:none">
  <div class="i-2col">
   <div class="i-side">
    <div class="i-card">
     <div class="i-card-hd">Anwesenheit erfassen</div>
     <div class="i-card-bd">
      <label class="i-lbl">Art</label>
      <div id="anw-typ-switch" style="display:flex;gap:6px;margin-bottom:8px">
       <button type="button" class="i-btn i-btn-p anw-typ-btn aktiv" data-typ="training" style="flex:1">Training</button>
       <button type="button" class="i-btn i-btn-g anw-typ-btn" data-typ="wettkampf" style="flex:1">Wettkampf</button>
      </div>
      <div id="anw-typ-training">
       <label class="i-lbl">Mannschaft</label>
       <select id="anw-mann" class="i-ctl"><option value="">Mannschaft wählen…</option></select>
       <label class="i-lbl">Datum</label>
       <input type="date" id="anw-datum" class="i-ctl" value="<?php echo date('Y-m-d');?>">
       <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <button id="anw-laden" class="i-btn i-btn-p">Laden</button>
        <button id="anw-ausfall" class="i-btn i-btn-g">Ausgefallen</button>
       </div>
       <div class="i-muted" style="font-size:11px;margin-top:6px">Pro Mannschaft und Tag gibt es ein Training. Das Backend wählt den passenden Trainings-Slot automatisch.</div>
      </div>
      <div id="anw-typ-wettkampf" style="display:none">
       <label class="i-lbl">Wettkampf</label>
       <select id="wkanw-wk" class="i-ctl"><option value="">Wettkampf wählen…</option></select>
       <label class="i-lbl">Mannschaft</label>
       <select id="wkanw-mann" class="i-ctl"><option value="">Mannschaft wählen…</option></select>
       <label class="i-lbl">Wettkampftag</label>
       <select id="wkanw-tag" class="i-ctl"><option value="">Tag wählen…</option></select>
       <div style="display:flex;gap:8px;margin-top:12px">
        <button id="wkanw-laden" class="i-btn i-btn-p" style="flex:1">Laden</button>
       </div>
       <div class="i-muted" style="font-size:11px;margin-top:8px">Keine Wettkämpfe? Im Reiter „Wettkämpfe" anlegen.</div>
      </div>
     </div>
    </div>
    <?php if(LSV07I_Access::is_admin()):?>
    <div class="i-card" style="margin-top:12px">
     <div class="i-card-hd">Vergangene Sessions</div>
     <div class="i-card-bd">
      <label class="i-lbl">Mannschaft</label>
      <select id="anw-fmann" class="i-ctl"><option value="">Alle</option></select>
      <label class="i-lbl">Monat</label>
      <input type="month" id="anw-fmonat" class="i-ctl" value="<?php echo date('Y-m');?>">
      <button id="anw-suchen" class="i-btn i-btn-g" style="width:100%;margin-top:10px">Suchen</button>
      <button id="anw-alle-loeschen" class="i-btn i-btn-r" style="width:100%;margin-top:8px">Alle Sessions löschen</button>
      <div id="anw-sessions" style="margin-top:8px"></div>
     </div>
    </div>
    <?php endif;?>
    </div>
   <div class="i-main">
    <div class="i-card" id="anw-form" style="display:none">
     <div class="i-card-hd" id="anw-form-titel">Anwesenheit</div>
     <div class="i-card-bd">
      <div id="anw-eintraege"></div>
      <div style="margin-top:14px">
       <label class="i-lbl" for="anw-kommentar">Kommentar zum Training (optional)</label>
       <textarea id="anw-kommentar" class="i-ctl" rows="3"
        placeholder="z.B. Schwerpunkt heute, Auffälligkeiten, Ausfälle …"
        maxlength="5000"></textarea>
       <div class="i-lbl-hint">Sichtbar in der Statistik. Gilt für das gesamte Training, nicht für einzelne Schwimmer.</div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-top:12px">
       <span id="anw-save-status" style="flex-shrink:0;font-size:12px;color:#6b6e85" title="Status der automatischen Speicherung">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#5b6072;vertical-align:middle"></span>
        <span class="txt">Noch nichts geändert</span>
       </span>
       <button id="anw-save" class="i-btn i-btn-p" style="flex:1">Jetzt speichern</button>
      </div>
     </div>
    </div>
    <div class="i-card" style="margin-top:0" id="anw-stat-card">
     <div class="i-card-hd">Anwesenheitsstatistik
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
       <select id="stat-mann" class="i-ctl" style="width:auto"><option value="">Alle Mannschaften</option></select>
       <input type="date" id="stat-von" class="i-ctl" style="width:138px" value="<?php echo date('Y-m-01');?>">
       <input type="date" id="stat-bis" class="i-ctl" style="width:138px" value="<?php echo date('Y-m-d');?>">
       <button id="stat-laden" class="i-btn i-btn-g">Laden</button>
       <button id="stat-saison-pdf" class="i-btn i-btn-g" title="Anwesenheit + Bestzeiten der gewählten Mannschaft als PDF">Saisonbericht (PDF)</button>
      </div>
     </div>
     <div class="i-card-bd" id="stat-content"><span class="i-muted">Zeitraum wählen und auf "Laden" klicken.</span></div>
    </div>
   </div>
  </div>
 </div>

 <!-- Wettkämpfe -->
 <div id="i-p-wk" class="i-panel" style="display:none">
  <div class="i-2col">
   <div class="i-side">
    <div class="i-card">
     <div class="i-card-hd">Neuer Wettkampf</div>
     <div class="i-card-bd">
      <button id="wk-neu" class="i-btn i-btn-p" style="width:100%">+ Wettkampf anlegen</button>
      <div class="i-muted" style="font-size:11px;margin-top:8px">Wettkämpfe mit Datumsbereich, teilnehmenden Mannschaften und Abschnitten pro Tag anlegen. Anschließend kann im Reiter „Anwesenheit" pro Tag die Anwesenheit erfasst werden.</div>
     </div>
    </div>
    <div class="i-card" style="margin-top:12px">
     <div class="i-card-hd">Filter</div>
     <div class="i-card-bd">
      <label class="i-lbl">Mannschaft</label>
      <select id="wk-f-mann" class="i-ctl"><option value="">Alle</option></select>
      <label class="i-lbl">Jahr</label>
      <input type="number" id="wk-f-jahr" class="i-ctl" min="2020" max="2099" value="<?php echo date('Y');?>">
      <button id="wk-laden" class="i-btn i-btn-g" style="width:100%;margin-top:10px">Laden</button>
     </div>
    </div>
    <div class="i-card" style="margin-top:12px">
     <div class="i-card-hd">Statistik
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
       <select id="wkstat-mann" class="i-ctl" style="width:auto"><option value="">Alle Mannschaften</option></select>
       <input type="date" id="wkstat-von" class="i-ctl" style="width:138px" value="<?php echo date('Y-01-01');?>">
       <input type="date" id="wkstat-bis" class="i-ctl" style="width:138px" value="<?php echo date('Y-12-31');?>">
       <button id="wkstat-laden" class="i-btn i-btn-g">Laden</button>
      </div>
     </div>
     <div class="i-card-bd" id="wkstat-content"><span class="i-muted">Zeitraum wählen und auf "Laden" klicken.</span></div>
    </div>
   </div>
   <div class="i-main">
    <div class="i-card">
     <div class="i-card-hd">Wettkämpfe</div>
     <div class="i-card-bd" id="wk-liste"><div class="i-spin">Auf „Laden" klicken um Wettkämpfe anzuzeigen.</div></div>
    </div>
   </div>
  </div>
 </div>

 <!-- Springer -->
 <div id="i-p-spr" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Springerschichten
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <input type="date" id="spr-von" class="i-ctl" style="width:142px" value="<?php echo date('Y-m-d');?>">
     <input type="date" id="spr-bis" class="i-ctl" style="width:142px" value="<?php echo date('Y-m-d',strtotime('+28 days'));?>">
     <select id="spr-mann-filter" class="i-ctl" style="width:auto"><option value="">Alle Mannschaften</option></select>
     <button id="spr-laden" class="i-btn i-btn-g">Anzeigen</button>
    </div>
   </div>
   <div class="i-card-bd">
    <span class="i-muted">Sie können sich als Springer für Trainings anderer Mannschaften eintragen (max. 2 pro Training).</span>
    <div id="spr-grid" style="margin-top:10px"><span class="i-muted">Zeitraum wählen und auf "Anzeigen" klicken.</span></div>
   </div>
  </div>
 </div>

 <!-- Bestzeiten -->
 <div id="i-p-bz" class="i-panel" style="display:none">
  <?php if($cA||$cSW):?>
  <div class="i-card">
   <div class="i-card-hd">Bestzeiten importieren</div>
   <div class="i-card-bd">
    <div style="margin-bottom:14px">
     <label class="i-lbl" style="margin-top:0">Tabelle aus Excel einfügen (Strg+V)</label>
     <textarea id="bz-paste" class="i-ctl" rows="6" placeholder="Tabelle aus Excel kopieren (Strg+C) und hier einfügen (Strg+V).&#10;Erste Zeile muss 'Name' und die Strecken (50F, 100F, …) enthalten.&#10;Schwimmer-Format: 'Vorname Nachname (JJJJ)' oder 'Nachname, Vorname (JJJJ)'." style="font-family:ui-monospace,monospace;font-size:12px;white-space:pre"></textarea>
     <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
      <button id="bz-paste-vorschau" class="i-btn i-btn-p">Vorschau prüfen</button>
      <button id="bz-paste-clear" class="i-btn i-btn-g i-btn-sm">Leeren</button>
     </div>
     <div class="i-muted" style="font-size:11px;margin-top:6px">Der Schwimmer wird mannschaftsübergreifend zugeordnet — seine aktuelle Mannschaft wird automatisch übernommen. <strong>Bestehende Zeiten dieses Schwimmers werden komplett ersetzt.</strong></div>
    </div>
    <div id="bz-paste-vorschau-box" style="display:none">
     <div id="bz-paste-vorschau-content"></div>
    </div>
    <div id="bz-upload-status" style="margin-top:8px"></div>
   </div>
  </div>
  <?php endif;?>

  <div class="i-card">
   <div class="i-card-hd">Bestzeiten-Rangliste
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
     <select id="bz-mann-filter" class="i-ctl" style="width:auto"><option value="">Alle Mannschaften</option></select>
     <select id="bz-strecke-filter" class="i-ctl" style="width:auto">
      <option value="">Alle Strecken</option>
      <option value="50F">50m Freistil</option><option value="100F">100m Freistil</option>
      <option value="200F">200m Freistil</option><option value="400F">400m Freistil</option>
      <option value="800F">800m Freistil</option><option value="1500F">1500m Freistil</option>
      <option value="50B">50m Brust</option><option value="100B">100m Brust</option>
      <option value="200B">200m Brust</option><option value="50R">50m Rücken</option>
      <option value="100R">100m Rücken</option><option value="200R">200m Rücken</option>
      <option value="50S">50m Schmetterling</option><option value="100S">100m Schmetterling</option>
      <option value="200S">200m Schmetterling</option>
      <option value="100L">100m Lagen</option><option value="200L">200m Lagen</option>
      <option value="400L">400m Lagen</option>
     </select>
     <button id="bz-laden" class="i-btn i-btn-g">Laden</button>
     <?php if($cA||$cSW):?><button id="bz-loeschen" class="i-btn i-btn-r i-btn-sm" style="display:none">Alle löschen</button><?php endif;?>
    </div>
   </div>
   <div class="i-card-bd" id="bz-content">
    <span class="i-muted">Mannschaft und/oder Strecke wählen, dann "Laden" klicken.</span>
   </div>
  </div>

  <div class="i-card">
   <div class="i-card-hd">Bestzeiten exportieren</div>
   <div class="i-card-bd">
    <div class="i-notice i-notice-b" style="margin-bottom:14px">Exportiert eine tabellarische Übersicht: eine Zeile pro Schwimmer, Strecken als Spalten.</div>
    <label class="i-lbl">Umfang</label>
    <select id="bz-exp-scope" class="i-ctl" style="margin-bottom:12px">
     <option value="alle">Alle Schwimmer</option>
     <option value="mannschaft">Einzelne Mannschaft</option>
     <option value="schwimmer">Einzelner Schwimmer</option>
    </select>
    <div id="bz-exp-mann-wrap" style="display:none;margin-bottom:12px">
     <label class="i-lbl">Mannschaft</label>
     <select id="bz-exp-mann" class="i-ctl"><option value="">– wählen –</option></select>
    </div>
    <div id="bz-exp-sw-wrap" style="display:none;margin-bottom:12px">
     <label class="i-lbl">Schwimmer</label>
     <select id="bz-exp-sw" class="i-ctl"><option value="">– wählen –</option></select>
    </div>
    <label class="i-lbl">Format</label>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
     <select id="bz-exp-format" class="i-ctl" style="width:auto">
      <option value="pdf">PDF (zum Drucken/Speichern)</option>
      <option value="csv">CSV (für Excel)</option>
     </select>
     <button id="bz-exp-start" class="i-btn i-btn-p">Exportieren</button>
    </div>
    <div id="bz-exp-status" style="margin-top:10px"></div>
   </div>
  </div>
 </div>

 <!-- ═══ WETTKAMPFMELDUNGEN ═════════════════════════════════════════
      Drei Schritte in einem Panel: Kopf → Schwimmer → Tabelle.
      Immer nur ein Schritt sichtbar, damit es auf dem Handy nicht
      unübersichtlich wird.                                            -->
 <?php if($T('sw_meld')):?>
 <div id="i-p-meld" class="i-panel" style="display:none">

  <!-- Bestehende Meldelisten -->
  <div class="i-card" id="meld-karte-liste">
   <div class="i-card-hd">Meldungen
    <button id="meld-neu" class="i-btn i-btn-p">+ Neue Meldung</button>
   </div>
   <div class="i-card-bd" id="meld-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>

  <!-- Schritt 1: Wettkampf, Mannschaft, Umfang -->
  <div class="i-card" id="meld-karte-kopf" style="display:none">
   <div class="i-card-hd">
    <span><span class="meld-schritt">Schritt 1 von 3</span> Wettkampf und Mannschaft</span>
   </div>
   <div class="i-card-bd">
    <label class="i-lbl" for="meld-wk">Wettkampf</label>
    <select id="meld-wk" class="i-ctl"><option value="">Wettkampf wählen…</option></select>
    <label class="i-lbl" for="meld-mann">Mannschaft</label>
    <select id="meld-mann" class="i-ctl"><option value="">Mannschaft wählen…</option></select>
    <div class="meld-zwei">
     <div>
      <label class="i-lbl" for="meld-abschnitte">Abschnitte</label>
      <input type="number" id="meld-abschnitte" class="i-ctl" min="1" max="60" value="2">
     </div>
     <div>
      <label class="i-lbl" for="meld-nummern">Wettkampfnummern</label>
      <input type="number" id="meld-nummern" class="i-ctl" min="1" max="999" value="20">
     </div>
    </div>
    <div class="i-lbl-hint">Beides begrenzt später die Auswahl in der Tabelle.</div>
    <div class="meld-knopfzeile">
     <button id="meld-kopf-zurueck" class="i-btn i-btn-g">Abbrechen</button>
     <button id="meld-kopf-weiter" class="i-btn i-btn-p">Weiter zu den Schwimmern</button>
    </div>
   </div>
  </div>

  <!-- Schritt 2: Wer schwimmt wie oft -->
  <div class="i-card" id="meld-karte-sw" style="display:none">
   <div class="i-card-hd">
    <span><span class="meld-schritt">Schritt 2 von 3</span> <span id="meld-sw-titel">Schwimmer</span></span>
    <span class="i-bdg i-bdg-b" id="meld-sw-summe">0 Starts</span>
   </div>
   <div class="i-card-bd">
    <input type="text" id="meld-sw-suche" class="i-ctl" placeholder="Schwimmer suchen…" data-no-save>
    <div class="i-lbl-hint" style="margin-bottom:10px">Für jeden Schwimmer angeben, wie oft er startet. 0 heißt: nicht gemeldet.</div>
    <div id="meld-sw-liste"><div class="i-spin">Wird geladen…</div></div>
    <div class="meld-knopfzeile">
     <button id="meld-sw-zurueck" class="i-btn i-btn-g">Zurück</button>
     <button id="meld-sw-weiter" class="i-btn i-btn-p">Tabelle erstellen</button>
    </div>
   </div>
  </div>

  <!-- Schritt 3: Meldetabelle -->
  <div class="i-card" id="meld-karte-tab" style="display:none">
   <div class="i-card-hd">
    <span id="meld-tab-titel">Meldeliste</span>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
     <button id="meld-tab-zurueck" class="i-btn i-btn-g">Zurück</button>
     <button id="meld-tab-speichern" class="i-btn i-btn-p">Speichern</button>
     <button id="meld-tab-excel" class="i-btn i-btn-g">Excel-Export</button>
    </div>
   </div>
   <div class="i-card-bd">
    <div id="meld-tab-inhalt"></div>
   </div>
  </div>
 </div>
 <?php endif;?>

 <!-- ═══ REFLEXIONSBÖGEN (nur Schwimmen) ═══════════════════════════ -->
 <div id="i-p-refl" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Reflexionsbögen
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <select id="refl-mann" class="i-ctl" style="width:auto">
      <option value="">Mannschaft wählen…</option>
     </select>
    </div>
   </div>
   <div class="i-card-bd">
    <div id="refl-saison-info" class="i-lbl-hint" style="margin-bottom:12px"></div>

    <!-- Sub-Navigation -->
    <div class="refl-subnav" id="refl-subnav" style="display:none">
     <button class="refl-subtab on" data-rsub="uebersicht">Übersicht</button>
     <button class="refl-subtab" data-rsub="auswertung">Auswertung</button>
     <button class="refl-subtab" data-rsub="fragen">Stammfragen</button>
    </div>

    <!-- Übersicht -->
    <div class="refl-sub" id="refl-sub-uebersicht" style="display:none">
     <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin:14px 0">
      <div id="refl-stat" class="i-muted" style="font-size:13px"></div>
      <button id="refl-erstellen" class="i-btn i-btn-p">Bögen für Saison erstellen</button>
     </div>
     <div id="refl-liste"><div class="i-spin">Wird geladen…</div></div>
    </div>

    <!-- Auswertung: Antworten aller abgegebenen Bögen, gebündelt pro Frage -->
    <div class="refl-sub" id="refl-sub-auswertung" style="display:none">
     <div id="refl-ausw-stat" class="i-muted" style="font-size:13px;margin:14px 0"></div>
     <div id="refl-ausw-inhalt"><div class="i-hint">Mannschaft wählen, dann wird die Auswertung geladen.</div></div>
    </div>

    <!-- Stammfragen -->
    <div class="refl-sub" id="refl-sub-fragen" style="display:none">
     <p class="i-lbl-hint" style="margin:14px 0">Diese Fragen gelten für alle Reflexionsbögen dieser Mannschaft. Beim Erstellen der Bögen wird der aktuelle Stand gespeichert.</p>
     <div id="refl-fragen-liste"></div>
     <button id="refl-frage-add" class="i-btn i-btn-g i-btn-sm" style="margin-top:8px">+ Frage hinzufügen</button>
     <div style="margin-top:14px">
      <button id="refl-fragen-save" class="i-btn i-btn-p">Fragen speichern</button>
     </div>
    </div>

    <div id="refl-empty" class="i-muted" style="padding:8px 0">Bitte oben eine Mannschaft wählen.</div>
   </div>
  </div>
 </div>

 <?php if($T('sw_trainer')):?>
 <div id="i-p-sw-trainer" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Trainer-Übersicht</div>
   <div class="i-card-bd" id="sw-trainer-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <?php endif;?>
</div>
<?php endif;?>

<?php /* ══ VERWALTUNG ══ */ if($cV):?>
<div id="i-sec-v" class="i-sec" <?php echo lsv07i_isec('v',$first);?>>
 <div class="i-sec-hd" style="display:none"><h2>Verwaltung</h2></div>
 <?php
 $vw_tabs=($cSW?1:0)+($cKW?1:0);
 if($vw_tabs>1):?>
 <div class="i-tabs i-navgrid">
  <?php if($cSW): lsv07i_navicon('i-p-vw-sw','Abrechnungen','geld',true);  endif;?>
  <?php if($cKW): lsv07i_navicon('i-p-vw-kw','Kassenwart',  'kasse',!$cSW); endif;?>
 </div>
 <?php endif;?>
 <?php if($cSW):?>
 <div id="i-p-vw-sw" class="i-panel" style="display:block">
  <div class="i-card">
   <div class="i-card-hd">Eingereichte Abrechnungen
    <div style="display:flex;gap:6px;align-items:center">
     <select id="vw-filter" class="i-ctl" style="width:auto">
      <option value="">Alle Status</option>
      <option value="eingereicht">Eingereicht</option>
      <option value="genehmigt">Genehmigt</option>
      <option value="zurueck">Zurückgegeben</option>
      <option value="entwurf">Entwurf</option>
     </select>
     <button id="vw-laden" class="i-btn i-btn-g">Laden</button>
    </div>
   </div>
   <div class="i-card-bd" id="vw-liste"><span class="i-muted">Bitte auf "Laden" klicken.</span></div>
  </div>
 </div>
 <?php endif;?>
 <?php if($cKW):?>
 <div id="i-p-vw-kw" class="i-panel" style="display:<?php echo(!$cSW)?'block':'none';?>">
  <div class="i-tabs">
   <button class="i-tab on" data-panel="i-p-kw-liste">Genehmigte Abrechnungen</button>
   <button class="i-tab" data-panel="i-p-kw-jahr">Jahresübersicht</button>
  </div>
  <div id="i-p-kw-liste" class="i-panel" style="display:block">
   <div class="i-card">
    <div class="i-card-hd">Genehmigte Abrechnungen<button id="kw-laden" class="i-btn i-btn-g">Laden</button></div>
    <div class="i-card-bd" id="kw-liste"><span class="i-muted">Bitte auf "Laden" klicken.</span></div>
   </div>
  </div>
  <div id="i-p-kw-jahr" class="i-panel" style="display:none">
   <div class="i-card">
    <div class="i-card-hd">Jahresübersicht Abrechnungen
    <div style="display:flex;gap:6px;align-items:center">
      <select id="kw-jahr-sel" class="i-ctl" style="width:auto"><option value="<?php echo date('Y');?>"><?php echo date('Y');?></option></select>
      <button id="kw-jahr-laden" class="i-btn i-btn-g">Laden</button>
      <button id="kw-csv-export" class="i-btn i-btn-p" style="display:none">CSV exportieren</button>
      <button id="kw-jahr-pdf" class="i-btn i-btn-g" style="display:none">PDF exportieren</button>
     </div>
    </div>
    <div class="i-card-bd" id="kw-jahr-content"><span class="i-muted">Bitte auf "Laden" klicken.</span></div>
   </div>
  </div>
 </div>
 <?php endif;?>
 <?php if($cSW):?>
 <div id="i-p-vw-trainer" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Trainer-Übersicht</div>
   <div class="i-card-bd" id="vw-trainer-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <?php endif;?>
</div><!-- /i-sec-v -->
<?php endif;?>

<?php /* ══ TRAINER ══ */ if($cT):?>
<div id="i-sec-t" class="i-sec" <?php echo lsv07i_isec('t',$first);?>>
 <div class="i-sec-hd" style="display:none"><h2>Trainer</h2></div>
 <div class="i-tabs i-navgrid">
  <?php lsv07i_navicon('i-p-tr-abr','Abrechnung','geld',true);?>
  <?php if($T('tr_sonder')): lsv07i_navicon('i-p-tr-sonder','Sonderabrechnung','stern'); endif;?>
  <?php lsv07i_navicon('i-p-tr-sd','Stammdaten','ausweis');?>
 </div>

 <div id="i-p-tr-abr" class="i-panel" style="display:block">
  <div class="i-2col">
   <div class="i-side">
    <div class="i-card">
     <div class="i-card-hd">Quartal wählen</div>
     <div class="i-card-bd">
      <label class="i-lbl">Quartal</label>
      <select id="tr-quartal" class="i-ctl">
       <option value="Q1">Q1 (Jan – Mrz)</option>
       <option value="Q2">Q2 (Apr – Jun)</option>
       <option value="Q3">Q3 (Jul – Sep)</option>
       <option value="Q4">Q4 (Okt – Dez)</option>
      </select>
      <label class="i-lbl">Jahr</label>
      <input type="number" id="tr-jahr" class="i-ctl" value="<?php echo date('Y');?>" min="2020" max="2035">
      <button id="tr-laden" class="i-btn i-btn-p" style="width:100%;margin-top:12px">Laden / Erstellen</button>
     </div>
    </div>
    <div id="tr-summary-card" class="i-card" style="display:none;margin-top:12px">
     <div class="i-card-hd">Zusammenfassung</div>
     <div class="i-card-bd" id="tr-summary"></div>
    </div>
   </div>
   <div class="i-main">
    <div id="tr-detail-card" class="i-card" style="display:none">
     <div class="i-card-hd"><span id="tr-titel">Abrechnung</span><div id="tr-aktionen"></div></div>
     <div class="i-card-bd">
      <div id="tr-statusbar"></div>
      <!-- Subtabs -->
      <div class="i-tabs" style="margin-bottom:14px">
       <button class="i-tab on" data-panel="i-p-tr-training">Trainingstage</button>
       <button class="i-tab" data-panel="i-p-tr-wk">Wettkämpfe</button>
       <button class="i-tab" data-panel="i-p-tr-km">Kilometergeld</button>
      </div>
      <div id="i-p-tr-training" class="i-panel" style="display:block">
       <div id="tr-tage"></div>
       <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <button id="tr-add-tag" class="i-btn i-btn-g" style="display:none">+ Training manuell hinzufügen</button>
       </div>
      </div>
      <div id="i-p-tr-wk" class="i-panel" style="display:none">
       <div id="tr-wk-liste"></div>
       <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <button id="tr-add-wk" class="i-btn i-btn-g" style="display:none">+ Wettkampf manuell</button>
        <button id="tr-add-wk-offen" class="i-btn i-btn-p" style="display:none">+ Wettkampftag aus Anwesenheit</button>
       </div>
      </div>
      <div id="i-p-tr-km" class="i-panel" style="display:none">
       <div class="i-notice i-notice-b" style="margin-bottom:12px">Bitte nur einfache Fahrt abgeben, die Rückfahrt wird automatisch dazugerechnet.</div>
       <div id="tr-km-info" class="i-notice i-notice-y" style="display:none"></div>
       <div id="tr-km-liste"></div>
       <button id="tr-add-km" class="i-btn i-btn-g" style="margin-top:10px;display:none">+ Fahrtkosten hinzufügen</button>
      </div>
     </div>
    </div>
   </div>
  </div>
 </div>

 <?php if($T('tr_sonder')):?>
 <!-- Sonderabrechnung: Tag + Stunden statt Mannschaftstraining. Nutzt
      dieselbe Quartals-Logik wie "Meine Abrechnung", aber mit eigenen
      DOM-IDs (tr-sonder-*) und einer einzigen, einfachen Eintragsliste. -->
 <div id="i-p-tr-sonder" class="i-panel" style="display:none">
  <div class="i-2col">
   <div class="i-side">
    <div class="i-card">
     <div class="i-card-hd">Quartal wählen</div>
     <div class="i-card-bd">
      <label class="i-lbl">Quartal</label>
      <select id="tr-sonder-quartal" class="i-ctl">
       <option value="Q1">Q1 (Jan – Mrz)</option>
       <option value="Q2">Q2 (Apr – Jun)</option>
       <option value="Q3">Q3 (Jul – Sep)</option>
       <option value="Q4">Q4 (Okt – Dez)</option>
      </select>
      <label class="i-lbl">Jahr</label>
      <input type="number" id="tr-sonder-jahr" class="i-ctl" value="<?php echo date('Y');?>" min="2020" max="2035">
      <button id="tr-sonder-laden" class="i-btn i-btn-p" style="width:100%;margin-top:12px">Laden / Erstellen</button>
     </div>
    </div>
   </div>
   <div class="i-main">
    <div id="tr-sonder-detail-card" class="i-card" style="display:none">
     <div class="i-card-hd"><span id="tr-sonder-titel">Sonderabrechnung</span><div id="tr-sonder-aktionen"></div></div>
     <div class="i-card-bd">
      <div id="tr-sonder-statusbar"></div>
      <div class="i-lbl-hint" style="margin:10px 0">Ein Eintrag pro Tag: Datum auswählen und Stunden angeben.</div>
      <div id="tr-sonder-liste"></div>
      <button id="tr-sonder-add" class="i-btn i-btn-g" style="margin-top:10px;display:none">+ Eintrag hinzufügen</button>
     </div>
    </div>
   </div>
  </div>
 </div>
 <?php endif;?>

 <div id="i-p-tr-sd" class="i-panel" style="display:none">
  <div class="i-card" style="max-width:500px">
   <div class="i-card-hd">Stammdaten für Abrechnung</div>
   <div class="i-card-bd">
    <span class="i-muted">Diese Daten werden für die Abrechnung benötigt.</span>
    <label class="i-lbl">Stundensatz (EUR/Std.) *</label><input type="number" id="sd-satz" class="i-ctl" step="0.01" min="0" placeholder="z.B. 12.00">
    <label class="i-lbl">Kontoinhaber</label><input type="text" id="sd-empfaenger" class="i-ctl" placeholder="Name wie auf dem Konto" maxlength="200">
    <label class="i-lbl">IBAN</label><input type="text" id="sd-iban" class="i-ctl" placeholder="DE00 0000 0000 0000 0000 00" maxlength="34">
    <label class="i-lbl">BIC</label><input type="text" id="sd-bic" class="i-ctl" maxlength="11">
    <label class="i-lbl">Straße und Hausnummer</label><input type="text" id="sd-strasse" class="i-ctl">
    <div class="i-row" style="margin-top:0">
     <div class="i-f"><label class="i-lbl">PLZ</label><input type="text" id="sd-plz" class="i-ctl" maxlength="10"></div>
     <div class="i-f" style="flex:2"><label class="i-lbl">Ort</label><input type="text" id="sd-ort" class="i-ctl"></div>
    </div>
    <button id="sd-save" class="i-btn i-btn-p" style="margin-top:14px">Stammdaten speichern</button>
   </div>
  </div>
 </div>
</div>
<?php endif;?>

<?php /* ══ ADMIN ══ */ if($cA):?>
<div id="i-sec-a" class="i-sec" <?php echo lsv07i_isec('a',$first);?>>
 <div class="i-sec-hd" style="display:none"><h2>Administration</h2></div>
 <div class="i-tabs i-navgrid">
  <?php $_adm = LSV07I_Access::is_admin(); ?>
  <?php if($_adm):            lsv07i_navicon('i-p-adm-stammdaten',   'Stammdaten',  'ausweis', true); endif;?>
  <?php if($_adm):            lsv07i_navicon('i-p-adm-personen-csv', 'Personen-Import','import');     endif;?>
  <?php if($_adm):            lsv07i_navicon('i-p-adm-import',       'Sportler-Import','import');     endif;?>
  <?php if($T('adm_rechte')): lsv07i_navicon('i-p-adm-rechte',       'Rechte',      'schild');        endif;?>
  <?php if($T('adm_saison')): lsv07i_navicon('i-p-adm-saison',       'Saisons',     'kalender');      endif;?>
  <?php if($T('adm_log')):    lsv07i_navicon('i-p-adm-log',          'Log',         'log');           endif;?>
  <?php if($T('adm_atteste')):lsv07i_navicon('i-p-adm-atteste',      'Atteste',     'attest');        endif;?>
  <?php if($_adm):            lsv07i_navicon('i-p-adm-cfg',          'Konfiguration','zahnrad');      endif;?>
  <?php if($_adm): ?>
  <div class="i-navgrid-trenner">Verwaltung</div>
  <?php lsv07i_navicon('i-p-adm-trainer',      'Trainer',     'person');?>
  <?php lsv07i_navicon('i-p-adm-personen-tab', 'Personen',    'gruppe');?>
  <?php lsv07i_navicon('i-p-adm-mannschaften', 'Mannschaften','schwimmer');?>
  <?php lsv07i_navicon('i-p-adm-sportler',     'Sportler',    'ausweis');?>
  <?php lsv07i_navicon('i-p-adm-zeiten',       'Zeiten',      'uhr');?>
  <?php lsv07i_navicon('i-p-adm-mail',         'Mails',       'mail');?>
  <?php endif;?>
 </div>

 <!-- ═══ ZUSAMMENGEFÜHRTE VERWALTUNG (mit Sportart-Dropdown) ══════ -->
 <!-- Container Trainer -->
 <div id="i-p-adm-trainer" class="i-panel" style="display:none">
  <div class="i-sportfilter">
   <label class="i-lbl">Sportart</label>
   <select class="i-ctl i-sportart-sel" data-group="trainer" style="width:auto">
    <option value="s">Schwimmen</option>
    <option value="tri">Triathlon</option>
    <option value="fit">Fitness</option>
   </select>
  </div>
 </div>
 <!-- Container Personen (zentrale Personen-Tabelle) -->
 <div id="i-p-adm-personen-tab" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Personen
    <button id="pers-add" class="i-btn i-btn-p">+ Person anlegen</button>
   </div>
   <div class="i-card-bd">
    <div class="i-notice i-notice-b" style="margin-bottom:14px">Zentrale Personenliste über alle Sparten. Hier lassen sich Stammdaten, Sparten/Rollen und Mannschaften pflegen. Ein WordPress-Account ist optional — wird einer verknüpft, erhält die Person darüber in der Rechteverwaltung ihre Rechte.</div>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
     <input type="text" id="pers-suche" class="i-ctl" placeholder="Suchen…" style="width:auto;flex:1;min-width:160px">
     <select id="pers-f-sparte" class="i-ctl" style="width:auto">
      <option value="">Alle Sparten</option>
      <option value="schwimmen">Schwimmen</option>
      <option value="triathlon">Triathlon</option>
      <option value="fitness">Fitness</option>
     </select>
     <select id="pers-f-rolle" class="i-ctl" style="width:auto">
      <option value="">Alle Rollen</option>
      <option value="sportler">Sportler</option>
      <option value="trainer">Trainer</option>
     </select>
    </div>
    <div id="pers-liste"><span class="i-muted">Lädt…</span></div>
   </div>
  </div>
 </div>
 <!-- Container Mannschaften/Gruppen -->
 <div id="i-p-adm-mannschaften" class="i-panel" style="display:none">
  <div class="i-sportfilter">
   <label class="i-lbl">Sportart</label>
   <select class="i-ctl i-sportart-sel" data-group="mannschaften" style="width:auto">
    <option value="s">Schwimmen</option>
    <option value="tri">Triathlon</option>
    <option value="fit">Fitness</option>
   </select>
  </div>
 </div>
 <!-- Container Sportler -->
 <div id="i-p-adm-sportler" class="i-panel" style="display:none">
  <div class="i-sportfilter">
   <label class="i-lbl">Sportart</label>
   <select class="i-ctl i-sportart-sel" data-group="sportler" style="width:auto">
    <option value="s">Schwimmen</option>
    <option value="tri">Triathlon</option>
    <option value="fit">Fitness</option>
   </select>
  </div>
 </div>
 <!-- Container Zeiten -->
 <div id="i-p-adm-zeiten" class="i-panel" style="display:none">
  <div class="i-sportfilter">
   <label class="i-lbl">Sportart</label>
   <select class="i-ctl i-sportart-sel" data-group="zeiten" style="width:auto">
    <option value="s">Schwimmen</option>
    <option value="tri">Triathlon</option>
    <option value="fit">Fitness</option>
   </select>
  </div>
 </div>

 <!-- ═══ STAMMDATEN (Sparten-übergreifend) ═══════════════════════ -->
 <div id="i-p-adm-stammdaten" class="i-panel" style="display:block">
  <div class="i-card">
   <div class="i-card-hd">Stammdaten (alle Sparten)
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <select id="sd-sparte" class="i-ctl" style="width:auto">
      <option value="">Alle Sparten</option>
      <option value="schwimmen">Schwimmen</option>
      <option value="triathlon">Triathlon</option>
      <option value="fitness">Fitness</option>
     </select>
     <select id="sd-typ" class="i-ctl" style="width:auto">
      <option value="">Alle Typen</option>
      <option value="trainer">Trainer</option>
      <option value="sportler">Sportler</option>
      <option value="mannschaft">Mannschaft/Gruppe</option>
     </select>
     <input type="text" id="sd-suche" class="i-ctl" placeholder="Suche…" style="width:180px">
    </div>
   </div>
   <div class="i-card-bd">
    <div class="i-notice" style="margin-bottom:14px;font-size:13px">
     <strong>Übersicht:</strong> Hier siehst du alle Trainer, Sportler und Mannschaften/Gruppen aus allen Sparten.
     Filter oben nutzen, um die Liste einzugrenzen. Klick auf „Bearbeiten" öffnet den jeweiligen Detail-Tab.
    </div>
    <div id="sd-liste"><span class="i-muted">Wird geladen…</span></div>
   </div>
  </div>
 </div>

 <!-- ═══ LOG ════════════════════════════════════════════════════════ -->
 <div id="i-p-adm-log" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Aktionsprotokoll
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <input type="text" id="log-search" class="i-ctl" placeholder="Suche…" style="width:160px">
     <select id="log-bereich" class="i-ctl" style="width:auto">
      <option value="">Alle Bereiche</option>
      <option value="Schwimmen">Schwimmen</option>
      <option value="Triathlon">Triathlon</option>
      <option value="Fitness">Fitness</option>
      <option value="Anwesenheit">Anwesenheit</option>
      <option value="Dateien">Dateien</option>
      <option value="Rechte">Rechte</option>
     </select>
     <input type="date" id="log-von" class="i-ctl" style="width:auto">
     <input type="date" id="log-bis" class="i-ctl" style="width:auto">
     <button id="log-laden" class="i-btn i-btn-p">Aktualisieren</button>
    </div>
   </div>
   <div class="i-card-bd">
    <div class="i-notice" style="margin-bottom:14px;font-size:13px">
     <strong>Protokolliert:</strong> Schreibzugriffe (Anlegen, Ändern, Löschen), Datei-Uploads und Rechte-Vergaben.
     Lesezugriffe werden nicht geloggt.
    </div>
    <div id="log-liste"><span class="i-muted">Klicke auf „Aktualisieren" um Einträge zu laden.</span></div>
   </div>
  </div>
 </div>

 <!-- ═══ SAISONS ════════════════════════════════════════════════════ -->
 <div id="i-p-adm-saison" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Saisons
    <div>
     <button id="saison-neu" class="i-btn i-btn-p i-btn-sm">+ Neue Saison</button>
    </div>
   </div>
   <div class="i-card-bd">
    <div class="i-notice" style="margin-bottom:14px;font-size:13px">
     <strong>So funktionieren Saisons:</strong>
     Jede Saison hat eigene Trainingszeiten. Nur eine Saison ist gleichzeitig aktiv;
     neue Trainingszeiten werden automatisch der aktiven Saison zugeordnet.
     <strong>Alte Saisons und ihre Slots bleiben erhalten</strong> — Anwesenheit
     und Abrechnung greifen weiterhin auf die historischen Daten zu.
     Beim Wechsel werden nur die neuen Trainingszeiten verwendet.
    </div>
    <div id="saison-liste"><span class="i-muted">Lade…</span></div>
   </div>
  </div>
 </div>

 <!-- ═══ CSV-IMPORT ══════════════════════════════════════════════ -->
 <div id="i-p-adm-personen-csv" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Personen importieren
    <a href="<?php echo esc_url( admin_url( 'admin-ajax.php' ) . '?action=lsv07i_pers_csv_template&nonce=' . wp_create_nonce( 'lsv07i_nonce' ) );?>" class="i-btn i-btn-g i-btn-sm">Beispieldatei herunterladen</a>
   </div>
   <div class="i-card-bd">

    <ol class="pcsv-steps">
     <li class="pcsv-dot on"   data-step="1"><span>1</span> Datei wählen</li>
     <li class="pcsv-dot"      data-step="2"><span>2</span> Spalten zuordnen</li>
     <li class="pcsv-dot"      data-step="3"><span>3</span> Prüfen &amp; importieren</li>
    </ol>

    <!-- ── Schritt 1: Datei ───────────────────────────────────── -->
    <div id="pcsv-s1">
     <p>Lade eine Datei mit den Personendaten hoch — <strong>CSV</strong> oder <strong>Excel</strong>.
        Die Spaltenüberschriften darfst du frei benennen: im nächsten Schritt ordnest du jede Spalte
        selbst dem passenden Feld zu.</p>
     <label class="i-lbl" for="pers-csv-file">Datei auswählen</label>
     <input type="file" id="pers-csv-file" accept=".csv,.txt,.tsv,.xlsx,.xlsm,.xls,.ods">
     <details style="margin-top:16px">
      <summary style="cursor:pointer;font-size:13px;color:#6b6e85">Stattdessen Inhalt einfügen</summary>
      <textarea id="pers-csv-text" class="i-ctl" rows="8" style="font-family:ui-monospace,monospace;font-size:12px;margin-top:8px" placeholder="Vorname;Nachname;Geburtsdatum;…"></textarea>
      <button id="pcsv-text-weiter" class="i-btn i-btn-p" style="margin-top:8px">Weiter</button>
     </details>
    </div>

    <!-- ── Schritt 2: Zuordnung ───────────────────────────────── -->
    <div id="pcsv-s2" style="display:none">
     <div id="pcsv-map"></div>
     <div id="pcsv-map-hinweis" style="display:none;margin-top:10px"></div>

     <div class="pcsv-opt">
      <div class="i-f">
       <label class="i-lbl" for="pcsv-abgleich">Bestehende Personen erkennen an</label>
       <select id="pcsv-abgleich" class="i-ctl">
        <option value="name_jahr">Vorname + Nachname + Geburtsjahr</option>
        <option value="name">Vorname + Nachname</option>
        <option value="dsv_id">DSV-ID</option>
        <option value="mitgliedsnummer">Mitgliedsnummer</option>
        <option value="email">E-Mail-Adresse</option>
       </select>
      </div>
      <div class="i-f">
       <label class="i-lbl" for="pcsv-zuordnung">Sparten &amp; Mannschaften</label>
       <select id="pcsv-zuordnung" class="i-ctl">
        <option value="ergaenzen">ergänzen (Bestehendes bleibt)</option>
        <option value="ersetzen">ersetzen (durch die Datei überschreiben)</option>
       </select>
      </div>
      <div class="i-f">
       <label class="i-lbl" for="pcsv-delim">Trennzeichen</label>
       <select id="pcsv-delim" class="i-ctl">
        <option value=";">Semikolon ;</option>
        <option value=",">Komma ,</option>
        <option value="&#9;">Tabulator</option>
        <option value="|">Senkrechter Strich |</option>
       </select>
      </div>
     </div>

     <label class="pcsv-check"><input type="checkbox" id="pcsv-leere" checked>
      Leere Zellen überspringen — vorhandene Werte werden dann nicht gelöscht</label>
     <label class="pcsv-check"><input type="checkbox" id="pcsv-nur-update">
      Nur bestehende Personen aktualisieren, keine neuen anlegen</label>

     <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
      <button id="pcsv-vorschau" class="i-btn i-btn-p">Vorschau anzeigen</button>
      <button id="pcsv-zurueck-1" class="i-btn i-btn-g">Zurück</button>
      <button id="pcsv-abbrechen" class="i-btn i-btn-g i-btn-sm">Abbrechen</button>
     </div>
    </div>

    <!-- ── Schritt 3: Vorschau ────────────────────────────────── -->
    <div id="pcsv-s3" style="display:none">
     <div id="pcsv-vorschau-box"></div>
     <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
      <button id="pcsv-commit" class="i-btn i-btn-p">Importieren</button>
      <button id="pcsv-zurueck-2" class="i-btn i-btn-g">Zurück zur Zuordnung</button>
     </div>
    </div>

   </div>
  </div>
 </div>

 <div id="i-p-adm-tr" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Trainer<button id="adm-tr-neu" class="i-btn i-btn-p">Neuer Trainer</button></div>
   <div class="i-card-bd" id="adm-tr-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <div id="i-p-adm-mn" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Mannschaften<button id="adm-mn-neu" class="i-btn i-btn-p">Neue Mannschaft</button></div>
   <div class="i-card-bd" id="adm-mn-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <div id="i-p-adm-sw" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Schwimmer
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <input type="text" id="adm-sw-suche" class="i-ctl" placeholder="Suchen (Name, DSV-ID, Email…)" style="width:240px">
     <select id="adm-sw-filt" class="i-ctl" style="width:auto"><option value="">Alle Mannschaften</option></select>
     <span id="adm-sw-count" class="i-muted" style="font-size:12px"></span>
     <button id="adm-sw-export" class="i-btn i-btn-g" title="Sichtbare Schwimmer als Excel-Datei herunterladen">Excel-Export</button>
     <button id="adm-sw-neu" class="i-btn i-btn-p">Neuer Schwimmer</button>
    </div>
   </div>
   <div class="i-card-bd" id="adm-sw-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <div id="i-p-adm-sl" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Trainingszeiten
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <select id="adm-sl-saison-filter" class="i-ctl" style="width:auto">
      <option value="aktiv">Aktive Saison</option>
      <option value="alle">Alle Saisons</option>
     </select>
     <button id="adm-sl-neu" class="i-btn i-btn-p">Neue Trainingszeit</button>
    </div>
   </div>
   <div class="i-card-bd" id="adm-sl-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <!-- Triathlon Admin-Panels -->
 <div id="i-p-adm-tri-gr" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Triathlon-Gruppen<button id="tri-gruppe-neu" class="i-btn i-btn-p">+ Gruppe</button></div>
   <div class="i-card-bd" id="tri-gruppen-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <div id="i-p-adm-tri-sp" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Triathlon-Sportler
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <select id="tri-adm-gruppe-filter" class="i-ctl" style="width:auto"><option value="">Alle Gruppen</option></select>
     <button id="tri-adm-sportler-laden" class="i-btn i-btn-g">Laden</button>
     <button id="tri-sportler-neu" class="i-btn i-btn-p">+ Sportler</button>
    </div>
   </div>
   <div class="i-card-bd" id="tri-adm-sportler-liste"><span class="i-muted">Laden klicken.</span></div>
  </div>
 </div>
 <div id="i-p-adm-tri-tr" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Triathlon-Trainer<button id="tri-trainer-neu" class="i-btn i-btn-p">+ Trainer</button></div>
   <div class="i-card-bd" id="tri-trainer-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>

 <div id="i-p-adm-tri-sl" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Triathlon-Trainingszeiten<button id="tri-slot-neu" class="i-btn i-btn-p">+ Trainingszeit</button></div>
   <div class="i-card-bd" id="tri-sl-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>

 <!-- ═══ FITNESS-ADMIN-PANELS (nach Triathlon-Vorbild) ═══════════ -->
 <div id="i-p-adm-fit-gr" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Fitness-Gruppen<button id="fit-gruppe-neu" class="i-btn i-btn-p">+ Gruppe</button></div>
   <div class="i-card-bd" id="fit-gruppen-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <div id="i-p-adm-fit-sp" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Fitness-Sportler
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <select id="fit-adm-gruppe-filter" class="i-ctl" style="width:auto"><option value="">Alle Gruppen</option></select>
     <button id="fit-adm-sportler-laden" class="i-btn i-btn-g">Laden</button>
     <button id="fit-sportler-neu" class="i-btn i-btn-p">+ Sportler</button>
    </div>
   </div>
   <div class="i-card-bd" id="fit-adm-sportler-liste"><span class="i-muted">Laden klicken.</span></div>
  </div>
 </div>
 <div id="i-p-adm-fit-tr" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Fitness-Trainer<button id="fit-trainer-neu" class="i-btn i-btn-p">+ Trainer</button></div>
   <div class="i-card-bd" id="fit-trainer-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <div id="i-p-adm-fit-sl" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Fitness-Trainingszeiten<button id="fit-slot-neu" class="i-btn i-btn-p">+ Trainingszeit</button></div>
   <div class="i-card-bd" id="fit-sl-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>

 <div id="i-p-adm-import" class="i-panel" style="display:none">
  <div class="i-2col">
   <div class="i-side">
    <div class="i-card">
     <div class="i-card-hd">Trainer importieren</div>
     <div class="i-card-bd">
      <a id="dl-sample-trainer" href="#" class="i-btn i-btn-g" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:12px;text-decoration:none">⬇ Beispieldatei herunterladen</a>
      <p class="i-muted" style="font-size:12px;margin-bottom:12px">Datei mit eigenen Daten befüllen, dann als CSV oder Excel hochladen. Pflichtfelder: Nachname, Vorname, Geburtsdatum.</p>
      <label class="i-lbl">Datei (CSV oder Excel)</label>
      <input type="file" id="import-tr-file" class="i-ctl" accept=".csv,.xlsx,.xls">
      <button id="import-tr-btn" class="i-btn i-btn-p" style="width:100%;margin-top:10px">Trainer importieren</button>
      <div id="import-tr-result" style="margin-top:10px"></div>
     </div>
    </div>
   </div>
   <div class="i-main">
    <div class="i-card">
     <div class="i-card-hd">Schwimmer importieren</div>
     <div class="i-card-bd">
      <a id="dl-sample-schwimmer" href="#" class="i-btn i-btn-g" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:12px;text-decoration:none">⬇ Beispieldatei herunterladen</a>
      <p class="i-muted" style="font-size:12px;margin-bottom:12px">Datei mit eigenen Daten befüllen, dann als CSV oder Excel hochladen. Pflichtfelder: Nachname, Vorname, Geburtsdatum.</p>
      <label class="i-lbl">Standard-Mannschaft (optional)</label>
      <select id="import-sw-mann" class="i-ctl"><option value="">Keine / Manuell zuweisen</option></select>
      <label class="i-lbl">Datei (CSV oder Excel)</label>
      <input type="file" id="import-sw-file" class="i-ctl" accept=".csv,.xlsx,.xls">
      <button id="import-sw-btn" class="i-btn i-btn-p" style="width:100%;margin-top:10px">Schwimmer importieren</button>
      <div id="import-sw-result" style="margin-top:10px"></div>
     </div>
    </div>
   </div>
  </div>
 </div>

 <!-- Atteste: Ablauf-Übersicht + manueller Mail-Versand -->
 <div id="i-p-adm-atteste" class="i-panel" style="display:none">
  <div class="i-card" style="max-width:640px">
   <div class="i-card-hd">Erinnerungs-Vorlage</div>
   <div class="i-card-bd">
    <div class="i-lbl-hint" style="margin-bottom:12px">Platzhalter: <code>{name}</code> (voller Name), <code>{vorname}</code>, <code>{nachname}</code>, <code>{datum}</code> (Ablaufdatum). Die Mail wird erst beim Klick auf „Mail senden" verschickt — es gibt keinen automatischen Versand.</div>
    <label class="i-lbl" for="att-betreff">Betreff</label>
    <input type="text" id="att-betreff" class="i-ctl" placeholder="Betreff der Erinnerungs-Mail">
    <label class="i-lbl" for="att-text">Nachrichtentext</label>
    <textarea id="att-text" class="i-ctl" rows="7" placeholder="Text der Erinnerungs-Mail"></textarea>
    <div style="margin-top:14px">
     <button class="i-btn i-btn-p" id="att-tpl-save">Vorlage speichern</button>
    </div>
   </div>
  </div>
  <div class="i-card">
   <div class="i-card-hd">Ablaufende &amp; abgelaufene Atteste <span class="i-lbl-hint" style="font-weight:400">(nächste 30 Tage)</span></div>
   <div class="i-card-bd">
    <div id="att-liste"><div class="i-lbl-hint">Wird geladen…</div></div>
   </div>
  </div>
 </div>

 <div id="i-p-adm-cfg" class="i-panel" style="display:none">
  <div class="i-card" style="max-width:540px">
   <div class="i-card-hd">Systemeinstellungen</div>
   <div class="i-card-bd">
    <label class="i-lbl">Kilometerpauschale (EUR/km)</label><input type="number" id="cfg-km" class="i-ctl" step="0.01">
    <label class="i-lbl">Mindestkilometer</label><input type="number" id="cfg-km-min" class="i-ctl">
    <label class="i-lbl">Wettkampfpauschale (EUR/Abschnitt)</label><input type="number" id="cfg-wk" class="i-ctl" step="0.01">
    <label class="i-lbl">E-Mail Schwimmwart</label><input type="email" id="cfg-mail" class="i-ctl">
    <label class="i-lbl">Anwesenheits-Mahnung nach (Stunden)</label><input type="number" id="cfg-mahn" class="i-ctl" min="1">

    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e2ecf5">
     <span style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:10px">Attest-Verwaltung</span>
     <label class="i-lbl">Vorwarnung Attest-Ablauf (Tage)</label>
     <input type="number" id="cfg-attest-tage" class="i-ctl" min="1" max="365" placeholder="21">
    </div>

    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e2ecf5">
     <span style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px">E-Mail-Benachrichtigungen</span>
     <span class="i-muted" style="font-size:12px;display:block;margin-bottom:12px">E-Mails werden über den in WordPress konfigurierten SMTP-Server versendet.</span>

     <?php
     $mail_toggles = [
       'mail_deaktiviert'        => ['Alle Mails komplett deaktivieren', 'Überschreibt alle anderen Einstellungen.', 'r'],
       'mail_attest_trainer'     => ['Attest-Ablauf → Trainer der Mannschaft', '3 Wochen vor Ablauf an alle Trainer der betroffenen Mannschaft.', ''],
       'mail_attest_kontakt'     => ['Attest-Ablauf → Kontaktperson', '3 Wochen vor Ablauf an die hinterlegte Kontaktperson.', ''],
       'mail_anwesenheit_trainer'=> ['Anwesenheit fehlt → Trainer', 'Wenn eine Trainingseinheit nicht erfasst wurde (außer bei Ausfall).', ''],
       'mail_springer_eintrag'   => ['Springer eingetragen → Trainer', 'Wenn sich ein Springer für ein Training einträgt.', ''],
       'mail_springer_austrag'   => ['Springer ausgetragen → Trainer', 'Wenn sich ein Springer wieder austrägt.', ''],
       'mail_bestzeiten_trainer' => ['Neue Bestzeiten → Trainer', 'Wenn neue Bestzeiten für eine Mannschaft hochgeladen werden.', ''],
       'mail_abr_schwimmwart'    => ['Abrechnung eingereicht → Schwimmwart', 'Wenn ein Trainer seine Abrechnung einreicht.', ''],
       'mail_abr_kassenwart'     => ['Abrechnung genehmigt → Kassenwart', 'Wenn eine Abrechnung genehmigt wurde und zur Auszahlung bereit ist.', ''],
       'mail_abr_trainer'        => ['Abrechnungsstatus → Trainer', 'Wenn eine Abrechnung genehmigt, zurückgegeben oder überwiesen wurde.', ''],
     ];
     foreach($mail_toggles as $id => $info):
       $color = $info[2]==='r' ? '#dc2626' : '#5b94ff';
     ?>
     <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid #f0f4f8;cursor:pointer">
      <input type="checkbox" id="cfg-<?php echo $id;?>" style="width:17px;height:17px;accent-color:<?php echo $color;?>;flex-shrink:0;margin-top:2px">
      <span>
       <span style="display:block;font-size:13px;font-weight:500;color:#1c1d2e"><?php echo $info[0];?></span>
       <span style="display:block;font-size:11px;color:#6b6e85;margin-top:1px"><?php echo $info[1];?></span>
      </span>
     </label>
     <?php endforeach;?>
    </div>

    <button id="cfg-save" class="i-btn i-btn-p" style="margin-top:16px">Einstellungen speichern</button>
   </div>
  </div>
 </div>

 <!-- Benachrichtigungen (Mail-Toggles) -->
 <div id="i-p-adm-mail" class="i-panel" style="display:none">
  <div class="i-card" style="max-width:560px">
   <div class="i-card-hd">E-Mail-Benachrichtigungen</div>
   <div class="i-card-bd">
    <div class="i-notice" style="background:rgba(91,148,255,0.15);border-left:3px solid #5b94ff;padding:10px 14px;margin-bottom:14px;border-radius:4px;font-size:13px">
     Mails werden über den in WordPress konfigurierten SMTP-Server versendet (z.B. WP Mail SMTP Plugin).
     Der globale Schalter unter Einstellungen deaktiviert alle Mails auf einmal.
    </div>
    <div style="margin-bottom:16px">
     <div style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Attest-Ablauf</div>
     <?php foreach([
       ['mail_attest_trainer', 'Trainer aller Mannschaft-Mitglieder benachrichtigen (3 Wochen vor Ablauf)'],
       ['mail_attest_kontakt', 'Kontaktperson des Schwimmers benachrichtigen (3 Wochen vor Ablauf)'],
     ] as [$key, $label]):?>
     <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;font-size:13px;line-height:1.4">
      <input type="checkbox" class="mail-toggle" data-key="<?php echo $key?>" style="width:17px;height:17px;accent-color:#5b94ff;flex-shrink:0;margin-top:2px">
      <?php echo esc_html($label)?>
     </label>
     <?php endforeach;?>
    </div>
    <div style="margin-bottom:16px;padding-top:12px;border-top:1px solid #e2ecf5">
     <div style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Trainingsanwesenheit</div>
     <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;font-size:13px;line-height:1.4">
      <input type="checkbox" class="mail-toggle" data-key="mail_anwesenheit_trainer" style="width:17px;height:17px;accent-color:#5b94ff;flex-shrink:0;margin-top:2px">
      Trainer benachrichtigen wenn Anwesenheit nicht erfasst wurde (außer Training abgesagt)
     </label>
    </div>
    <div style="margin-bottom:16px;padding-top:12px;border-top:1px solid #e2ecf5">
     <div style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Springer</div>
     <?php foreach([
       ['mail_springer_eintrag', 'Trainer benachrichtigen wenn sich ein Springer einträgt'],
       ['mail_springer_austrag', 'Trainer benachrichtigen wenn sich ein Springer austrägt'],
     ] as [$key, $label]):?>
     <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;font-size:13px;line-height:1.4">
      <input type="checkbox" class="mail-toggle" data-key="<?php echo $key?>" style="width:17px;height:17px;accent-color:#5b94ff;flex-shrink:0;margin-top:2px">
      <?php echo esc_html($label)?>
     </label>
     <?php endforeach;?>
    </div>
    <div style="margin-bottom:16px;padding-top:12px;border-top:1px solid #e2ecf5">
     <div style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Bestzeiten</div>
     <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;font-size:13px;line-height:1.4">
      <input type="checkbox" class="mail-toggle" data-key="mail_bestzeiten_trainer" style="width:17px;height:17px;accent-color:#5b94ff;flex-shrink:0;margin-top:2px">
      Trainer benachrichtigen wenn neue Bestzeiten für ihre Mannschaft hochgeladen wurden
     </label>
    </div>
    <div style="margin-bottom:16px;padding-top:12px;border-top:1px solid #e2ecf5">
     <div style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Abrechnung</div>
     <?php foreach([
       ['mail_abr_schwimmwart', 'Schwimmwart benachrichtigen wenn eine Abrechnung eingereicht wurde'],
       ['mail_abr_kassenwart',  'Kassenwart benachrichtigen wenn eine Abrechnung genehmigt wurde'],
       ['mail_abr_trainer',     'Trainer benachrichtigen wenn Abrechnung genehmigt, zurückgegeben oder überwiesen wurde'],
     ] as [$key, $label]):?>
     <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;font-size:13px;line-height:1.4">
      <input type="checkbox" class="mail-toggle" data-key="<?php echo $key?>" style="width:17px;height:17px;accent-color:#5b94ff;flex-shrink:0;margin-top:2px">
      <?php echo esc_html($label)?>
     </label>
     <?php endforeach;?>
    </div>
    <button id="mail-toggle-save" class="i-btn i-btn-p">Benachrichtigungen speichern</button>
   </div>
  </div>
 </div>

 <!-- ═══ RECHTE-VERWALTUNG (granular, neues System) ════════════════ -->
 <div id="i-p-adm-rechte" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Rechte-Verwaltung
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
     <input type="text" id="rechte-suche" class="i-ctl" placeholder="Name oder E-Mail suchen…" style="width:240px">
    </div>
   </div>
   <div class="i-card-bd">
    <div class="i-notice" style="margin-bottom:14px">
     <strong>Hinweis:</strong> Hier können einzelnen Benutzern feingranulare Rechte für jeden Bereich des Plugins zugewiesen werden.
     Klicke auf einen Benutzer, um seine Rechte zu bearbeiten oder ein Rollen-Template anzuwenden.
     Solange ein Benutzer noch keine expliziten Rechte hat (Spalte „Rechte" ist 0), gelten weiterhin die alten WordPress-Rollen.
    </div>
    <div id="rechte-liste"><span class="i-muted">Wird geladen…</span></div>
   </div>
  </div>

  <div class="i-card" style="margin-top:14px">
   <div class="i-card-hd">Eigene Templates
    <div>
     <button id="rechte-tpl-neu" class="i-btn i-btn-p i-btn-sm">+ Neues Template</button>
    </div>
   </div>
   <div class="i-card-bd">
    <div class="i-notice i-notice-a" style="margin-bottom:10px;font-size:12.5px">
     System-Templates (Schwimmwart, Trainer-Schwimmen etc.) sind fest hinterlegt und können nicht bearbeitet oder gelöscht werden.
     Du kannst aber eigene Templates anlegen, um häufige Rechte-Sets schnell zuzuweisen.
    </div>
    <div id="rechte-tpl-liste"><span class="i-muted">Wird geladen…</span></div>
   </div>
  </div>
 </div>

</div>
<?php endif;?>

<!-- Rechte: Bearbeiten-Modal -->
<div id="m-rechte" class="i-ov"><div class="i-modal" style="max-width:780px">
 <div class="i-mhd"><span id="m-rechte-ttl">Rechte bearbeiten</span><button class="i-mx" data-close="m-rechte">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="rechte-uid">
  <div id="rechte-userinfo" style="margin-bottom:14px;font-size:13px;color:var(--c-text-3)"></div>

  <label class="i-lbl">Rollen-Template anwenden</label>
  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
   <select id="rechte-tpl" class="i-ctl" style="width:auto;flex:1;min-width:200px"></select>
   <button id="rechte-tpl-apply" class="i-btn">Template übernehmen</button>
  </div>

  <div class="i-notice i-notice-a" style="font-size:12px">
   <strong>Achtung:</strong> Beim Anwenden eines Templates werden alle bisherigen Rechte überschrieben.
   Du kannst danach einzelne Rechte feinjustieren.
  </div>

  <label class="i-lbl" style="margin-top:18px">Einzelne Rechte</label>
  <div id="rechte-matrix" style="max-height:380px;overflow-y:auto;border:1px solid var(--c-line);border-radius:var(--r-sm);padding:8px;background:var(--c-dark-soft)">
   <div class="i-muted" style="padding:12px;text-align:center">Lade…</div>
  </div>
 </div>
 <div class="i-mft">
  <button class="i-btn" data-close="m-rechte">Abbrechen</button>
  <button id="rechte-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Template: Anlegen / Bearbeiten -->
<div id="m-rechte-tpl" class="i-ov"><div class="i-modal" style="max-width:780px">
 <div class="i-mhd"><span id="m-rechte-tpl-ttl">Template</span><button class="i-mx" data-close="m-rechte-tpl">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="rechte-tpl-id">
  <label class="i-lbl">Name *</label>
  <input type="text" id="rechte-tpl-name" class="i-ctl" maxlength="120" placeholder="z.B. Trainer Anfänger">
  <label class="i-lbl">Beschreibung (optional)</label>
  <input type="text" id="rechte-tpl-desc" class="i-ctl" maxlength="500" placeholder="z.B. Trainer mit eingeschränkten Rechten">

  <label class="i-lbl" style="margin-top:14px">Rechte</label>
  <div id="rechte-tpl-matrix" style="max-height:380px;overflow-y:auto;border:1px solid var(--c-line);border-radius:var(--r-sm);padding:8px;background:var(--c-dark-soft)">
   <div class="i-muted" style="padding:12px;text-align:center">Lade…</div>
  </div>
 </div>
 <div class="i-mft">
  <button class="i-btn" data-close="m-rechte-tpl">Abbrechen</button>
  <button id="rechte-tpl-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Saison: Anlegen/Bearbeiten -->
<div id="m-saison" class="i-ov"><div class="i-modal" style="max-width:480px">
 <div class="i-mhd"><span id="m-saison-ttl">Saison</span><button class="i-mx" data-close="m-saison">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="saison-id">
  <label class="i-lbl">Name *</label>
  <input type="text" id="saison-name" class="i-ctl" maxlength="120" placeholder="z.B. „Sommer 2026"">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px">
   <div>
    <label class="i-lbl">Start *</label>
    <input type="date" id="saison-start" class="i-ctl">
   </div>
   <div>
    <label class="i-lbl">Ende (optional)</label>
    <input type="date" id="saison-ende" class="i-ctl">
   </div>
  </div>
  <div class="i-lbl-hint" style="margin-top:8px">
   Das Ende-Datum ist optional. Es dient nur der Übersicht — die tatsächlich genutzten
   Trainingszeiten werden über das Aktivieren-Flag gesteuert.
  </div>
 </div>
 <div class="i-mft">
  <button class="i-btn" data-close="m-saison">Abbrechen</button>
  <button id="saison-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Personen: Bearbeiten-Modal -->
<?php /* ══ TRIATHLON ══ */ if($cTRI):?>
<div id="i-sec-tri" class="i-sec" <?php echo lsv07i_isec('tri',$first);?>>
 <div class="i-sec-hd" style="display:none"><h2>Triathlon</h2></div>
 <div class="i-tabs i-navgrid">
  <?php if($T('tri_mann')):    lsv07i_navicon('i-p-tri-mann',       'Gruppen',    'gruppe'); endif;?>
  <?php if($T('tri_anw')):     lsv07i_navicon('i-p-tri-anw',        'Anwesenheit','haken');  endif;?>
  <?php if($T('tri_trainer')): lsv07i_navicon('i-p-tri-trainer-ub', 'Trainer',    'person'); endif;?>
 </div>

 <!-- Mannschaftsübersicht -->
 <div id="i-p-tri-mann" class="i-panel" style="display:block">
  <div class="i-card">
   <div class="i-card-hd">Gruppen &amp; Sportler
    <select id="tri-mann-filter" class="i-ctl" style="width:auto"><option value="">Alle Gruppen</option></select>
   </div>
   <div class="i-card-bd" id="tri-mann-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>

 <!-- Anwesenheit erfassen -->
 <div id="i-p-tri-anw" class="i-panel" style="display:none">
  <div class="i-2col">
   <div class="i-side">
    <div class="i-card">
     <div class="i-card-hd">Anwesenheit erfassen</div>
     <div class="i-card-bd">
      <label class="i-lbl">Trainingszeit</label>
      <select id="tri-anw-slot" class="i-ctl"><option value="">Trainingszeit wählen…</option></select>
      <label class="i-lbl">Datum</label>
      <input type="date" id="tri-anw-datum" class="i-ctl" value="<?php echo date('Y-m-d');?>">
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
       <button id="tri-anw-laden" class="i-btn i-btn-p">Laden</button>
       <button id="tri-anw-ausfall" class="i-btn i-btn-g">Ausgefallen</button>
      </div>
     </div>
    </div>
    <div class="i-card" style="margin-top:12px">
     <div class="i-card-hd">Vergangene Sessions</div>
     <div class="i-card-bd">
      <label class="i-lbl">Gruppe</label>
      <select id="tri-sess-gruppe" class="i-ctl"><option value="">Alle Gruppen</option></select>
      <label class="i-lbl">Monat</label>
      <input type="month" id="tri-sess-monat" class="i-ctl" value="<?php echo date('Y-m');?>"<?php
        if ( ! LSV07I_Access::is_admin() && class_exists('LSV07I_Saison') ) {
          $aktive_saison = LSV07I_Saison::active();
          if ( $aktive_saison && ! empty( $aktive_saison['start_datum'] ) ) {
            echo ' min="' . esc_attr( substr( $aktive_saison['start_datum'], 0, 7 ) ) . '" title="Vergangene Saisons sind nur für Admins einsehbar."';
          }
        }
      ?>>
      <button id="tri-sess-suchen" class="i-btn i-btn-g" style="width:100%;margin-top:10px">Suchen</button>
      <div id="tri-sess-liste" style="margin-top:8px"></div>
     </div>
    </div>
   </div>
   <div class="i-main">
    <div class="i-card" id="tri-anw-form-card" style="display:none">
     <div class="i-card-hd" id="tri-anw-titel">Anwesenheit</div>
     <div class="i-card-bd">
      <div id="tri-anw-eintraege"></div>
      <button id="tri-anw-save" class="i-btn i-btn-p" style="width:100%;margin-top:12px">Alle speichern</button>
     </div>
    </div>
   </div>
  </div>
 </div>
 <!-- Trainer-Übersicht (Triathlonwart/Admin) -->
 <?php if($T('tri_trainer')):?>
 <div id="i-p-tri-trainer-ub" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Triathlon-Trainer</div>
   <div class="i-card-bd" id="tri-trainer-ub-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <?php endif;?>
</div>
<?php endif;?>

<?php /* ══ FITNESS ══ */ if($cFIT):?>
<div id="i-sec-fit" class="i-sec" <?php echo lsv07i_isec('fit',$first);?>>
 <div class="i-sec-hd" style="display:none"><h2>Fitness</h2></div>
 <div class="i-tabs i-navgrid">
  <?php if($T('fit_mann')):    lsv07i_navicon('i-p-fit-mann',       'Gruppen',    'gruppe'); endif;?>
  <?php if($T('fit_anw')):     lsv07i_navicon('i-p-fit-anw',        'Anwesenheit','haken');  endif;?>
  <?php if($T('fit_trainer')): lsv07i_navicon('i-p-fit-trainer-ub', 'Trainer',    'person'); endif;?>
 </div>

 <!-- Mannschaftsübersicht -->
 <div id="i-p-fit-mann" class="i-panel" style="display:block">
  <div class="i-card">
   <div class="i-card-hd">Gruppen &amp; Sportler
    <select id="fit-mann-filter" class="i-ctl" style="width:auto"><option value="">Alle Gruppen</option></select>
   </div>
   <div class="i-card-bd" id="fit-mann-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>

 <!-- Anwesenheit erfassen -->
 <div id="i-p-fit-anw" class="i-panel" style="display:none">
  <div class="i-2col">
   <div class="i-side">
    <div class="i-card">
     <div class="i-card-hd">Anwesenheit erfassen</div>
     <div class="i-card-bd">
      <label class="i-lbl">Trainingszeit</label>
      <select id="fit-anw-slot" class="i-ctl"><option value="">Trainingszeit wählen…</option></select>
      <label class="i-lbl">Datum</label>
      <input type="date" id="fit-anw-datum" class="i-ctl" value="<?php echo date('Y-m-d');?>">
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
       <button id="fit-anw-laden" class="i-btn i-btn-p">Laden</button>
       <button id="fit-anw-ausfall" class="i-btn i-btn-g">Ausgefallen</button>
      </div>
     </div>
    </div>
    <div class="i-card" style="margin-top:12px">
     <div class="i-card-hd">Vergangene Sessions</div>
     <div class="i-card-bd">
      <label class="i-lbl">Gruppe</label>
      <select id="fit-sess-gruppe" class="i-ctl"><option value="">Alle Gruppen</option></select>
      <label class="i-lbl">Monat</label>
      <input type="month" id="fit-sess-monat" class="i-ctl" value="<?php echo date('Y-m');?>"<?php
        if ( ! LSV07I_Access::is_admin() && class_exists('LSV07I_Saison') ) {
          $aktive_saison = LSV07I_Saison::active();
          if ( $aktive_saison && ! empty( $aktive_saison['start_datum'] ) ) {
            echo ' min="' . esc_attr( substr( $aktive_saison['start_datum'], 0, 7 ) ) . '" title="Vergangene Saisons sind nur für Admins einsehbar."';
          }
        }
      ?>>
      <button id="fit-sess-suchen" class="i-btn i-btn-g" style="width:100%;margin-top:10px">Suchen</button>
      <div id="fit-sess-liste" style="margin-top:8px"></div>
     </div>
    </div>
   </div>
   <div class="i-main">
    <div class="i-card" id="fit-anw-form-card" style="display:none">
     <div class="i-card-hd" id="fit-anw-titel">Anwesenheit</div>
     <div class="i-card-bd">
      <div id="fit-anw-eintraege"></div>
      <button id="fit-anw-save" class="i-btn i-btn-p" style="width:100%;margin-top:12px">Alle speichern</button>
     </div>
    </div>
   </div>
  </div>
 </div>
 <!-- Trainer-Übersicht (Fitnesswart/Admin) -->
 <?php if($T('fit_trainer')):?>
 <div id="i-p-fit-trainer-ub" class="i-panel" style="display:none">
  <div class="i-card">
   <div class="i-card-hd">Fitness-Trainer</div>
   <div class="i-card-bd" id="fit-trainer-ub-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>
 <?php endif;?>
</div>
<?php endif;?>

<!-- Fitness: Gruppe-Modal -->
<div id="m-fit-gruppe" class="i-ov"><div class="i-modal" style="max-width:400px">
 <div class="i-mhd"><span id="m-fit-gruppe-ttl">Gruppe</span><button class="i-mx" data-close="m-fit-gruppe">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="fit-g-id">
  <label class="i-lbl">Name *</label><input type="text" id="fit-g-name" class="i-ctl">
  <label class="i-lbl">Reihenfolge</label><input type="number" id="fit-g-order" class="i-ctl" value="0" min="0">
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-fit-gruppe">Abbrechen</button>
  <button id="fit-g-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Fitness: Sportler-Modal -->
<div id="m-fit-sportler" class="i-ov"><div class="i-modal" style="max-width:500px">
 <div class="i-mhd"><span id="m-fit-sportler-ttl">Sportler</span><button class="i-mx" data-close="m-fit-sportler">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="fit-s-id">
  <label class="i-lbl">Vorname</label><input type="text" id="fit-s-first" class="i-ctl">
  <label class="i-lbl">Nachname *</label><input type="text" id="fit-s-last" class="i-ctl">
  <label class="i-lbl">Geburtsdatum</label><input type="date" id="fit-s-birth" class="i-ctl">
  <label class="i-lbl">E-Mail</label><input type="email" id="fit-s-email" class="i-ctl">
  <label class="i-lbl">Gruppen (Mehrfachauswahl)</label>
  <div id="fit-s-gruppen-cb" style="border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:8px;max-height:150px;overflow-y:auto;background:rgba(255,255,255,0.05)"></div>
  <label class="i-lbl">Notizen</label><textarea id="fit-s-notes" class="i-ctl" rows="2"></textarea>
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-fit-sportler">Abbrechen</button>
  <button id="fit-s-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Fitness: Trainer-Modal -->
<div id="m-fit-trainer" class="i-ov"><div class="i-modal" style="max-width:480px">
 <div class="i-mhd"><span id="m-fit-trainer-ttl">Trainer</span><button class="i-mx" data-close="m-fit-trainer">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="fit-t-id">
  <label class="i-lbl">Name *</label><input type="text" id="fit-t-name" class="i-ctl">
  <label class="i-lbl">E-Mail</label><input type="email" id="fit-t-email" class="i-ctl">
  <label class="i-lbl">WordPress-Konto</label>
  <select id="fit-t-user" class="i-ctl"><option value="">Kein Konto</option></select>
  <label class="i-lbl">Gruppen</label>
  <div id="fit-t-gruppen-cb" style="border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:8px;max-height:120px;overflow-y:auto;background:rgba(255,255,255,0.05)"></div>
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-fit-trainer">Abbrechen</button>
  <button id="fit-t-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Trainer-Detail-Popup (Schwimmwart) -->
<div id="m-trainer-detail" class="i-ov"><div class="i-modal" style="max-width:560px">
 <div class="i-mhd"><span id="m-trainer-detail-ttl">Trainer-Details</span><button class="i-mx" data-close="m-trainer-detail">&#10005;</button></div>
 <div class="i-mbd" id="m-trainer-detail-bd" style="padding:0"></div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-trainer-detail">Schließen</button></div>
</div></div>

<!-- Fitness: Slot-Modal -->
<div id="m-fit-slot" class="i-ov"><div class="i-modal" style="max-width:400px">
 <div class="i-mhd"><span id="m-fit-slot-ttl">Trainingszeit</span><button class="i-mx" data-close="m-fit-slot">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="fit-sl-id">
  <label class="i-lbl">Gruppe *</label>
  <select id="fit-sl-gruppe" class="i-ctl"><option value="">Gruppe wählen…</option></select>
  <label class="i-lbl">Wochentag</label>
  <select id="fit-sl-wt" class="i-ctl">
   <option value="1">Montag</option><option value="2">Dienstag</option>
   <option value="3">Mittwoch</option><option value="4">Donnerstag</option>
   <option value="5">Freitag</option><option value="6">Samstag</option><option value="7">Sonntag</option>
  </select>
  <label class="i-lbl">Von</label><input type="time" id="fit-sl-von" class="i-ctl">
  <label class="i-lbl">Bis</label><input type="time" id="fit-sl-bis" class="i-ctl">
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-fit-slot">Abbrechen</button>
  <button id="fit-sl-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>


<!-- Triathlon: Gruppe-Modal -->
<div id="m-tri-gruppe" class="i-ov"><div class="i-modal" style="max-width:400px">
 <div class="i-mhd"><span id="m-tri-gruppe-ttl">Gruppe</span><button class="i-mx" data-close="m-tri-gruppe">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="tri-g-id">
  <label class="i-lbl">Name *</label><input type="text" id="tri-g-name" class="i-ctl">
  <label class="i-lbl">Reihenfolge</label><input type="number" id="tri-g-order" class="i-ctl" value="0" min="0">
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-tri-gruppe">Abbrechen</button>
  <button id="tri-g-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Triathlon: Sportler-Modal -->
<div id="m-tri-sportler" class="i-ov"><div class="i-modal" style="max-width:500px">
 <div class="i-mhd"><span id="m-tri-sportler-ttl">Sportler</span><button class="i-mx" data-close="m-tri-sportler">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="tri-s-id">
  <label class="i-lbl">Vorname</label><input type="text" id="tri-s-first" class="i-ctl">
  <label class="i-lbl">Nachname *</label><input type="text" id="tri-s-last" class="i-ctl">
  <label class="i-lbl">Geburtsdatum</label><input type="date" id="tri-s-birth" class="i-ctl">
  <label class="i-lbl">E-Mail</label><input type="email" id="tri-s-email" class="i-ctl">
  <label class="i-lbl">Gruppen (Mehrfachauswahl)</label>
  <div id="tri-s-gruppen-cb" style="border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:8px;max-height:150px;overflow-y:auto;background:rgba(255,255,255,0.05)"></div>
  <label class="i-lbl">Notizen</label><textarea id="tri-s-notes" class="i-ctl" rows="2"></textarea>
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-tri-sportler">Abbrechen</button>
  <button id="tri-s-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Triathlon: Trainer-Modal -->
<div id="m-tri-trainer" class="i-ov"><div class="i-modal" style="max-width:480px">
 <div class="i-mhd"><span id="m-tri-trainer-ttl">Trainer</span><button class="i-mx" data-close="m-tri-trainer">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="tri-t-id">
  <label class="i-lbl">Name *</label><input type="text" id="tri-t-name" class="i-ctl">
  <label class="i-lbl">E-Mail</label><input type="email" id="tri-t-email" class="i-ctl">
  <label class="i-lbl">WordPress-Konto</label>
  <select id="tri-t-user" class="i-ctl"><option value="">Kein Konto</option></select>
  <label class="i-lbl">Gruppen</label>
  <div id="tri-t-gruppen-cb" style="border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:8px;max-height:120px;overflow-y:auto;background:rgba(255,255,255,0.05)"></div>
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-tri-trainer">Abbrechen</button>
  <button id="tri-t-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Trainer-Detail-Popup (Schwimmwart) -->
<!-- Triathlon: Slot-Modal -->
<div id="m-tri-slot" class="i-ov"><div class="i-modal" style="max-width:400px">
 <div class="i-mhd"><span id="m-tri-slot-ttl">Trainingszeit</span><button class="i-mx" data-close="m-tri-slot">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="tri-sl-id">
  <label class="i-lbl">Gruppe *</label>
  <select id="tri-sl-gruppe" class="i-ctl"><option value="">Gruppe wählen…</option></select>
  <label class="i-lbl">Wochentag</label>
  <select id="tri-sl-wt" class="i-ctl">
   <option value="1">Montag</option><option value="2">Dienstag</option>
   <option value="3">Mittwoch</option><option value="4">Donnerstag</option>
   <option value="5">Freitag</option><option value="6">Samstag</option><option value="7">Sonntag</option>
  </select>
  <label class="i-lbl">Von</label><input type="time" id="tri-sl-von" class="i-ctl">
  <label class="i-lbl">Bis</label><input type="time" id="tri-sl-bis" class="i-ctl">
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-tri-slot">Abbrechen</button>
  <button id="tri-sl-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<?php /* ══ TICKETS ══ */ if(LSV07I_Access::has_any_access()):?>
<div id="i-sec-tk" class="i-sec" style="display:none">
 <div class="i-sec-hd" style="display:none"><h2>Tickets</h2></div>

 <div class="i-card" style="margin-bottom:12px">
  <div class="i-card-hd" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
   <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span style="font-weight:600">Tickets</span>
    <select id="tk-status-filter" class="i-ctl" style="width:auto">
     <option value="">Alle Status</option>
     <option value="offen">Offen</option>
     <option value="in_arbeit">In Arbeit</option>
     <option value="wartet">Wartet auf Rückmeldung</option>
     <option value="erledigt">Erledigt</option>
     <option value="geschlossen">Geschlossen</option>
    </select>
   </div>
   <button id="tk-neu-btn" class="i-btn i-btn-p">+ Neues Ticket</button>
  </div>
  <div class="i-card-bd">
   <div id="tk-info" class="i-muted" style="font-size:12px;margin-bottom:8px"></div>
   <div id="tk-liste"><div class="i-spin">Wird geladen…</div></div>
  </div>
 </div>

</div><!-- /i-sec-tk -->

<!-- Modal: Neues Ticket erstellen -->
<div id="m-tk-neu" class="i-ov"><div class="i-modal" style="max-width:640px">
 <div class="i-mhd"><span>Neues Ticket</span><button class="i-mx" data-close="m-tk-neu">&#10005;</button></div>
 <div class="i-mbd">
  <label class="i-lbl">Titel <span style="color:#b91c1c">*</span></label>
  <input type="text" id="tk-neu-titel" class="i-ctl" maxlength="200" placeholder="Kurze Zusammenfassung">

  <div style="display:flex;gap:10px;margin-top:10px">
   <div style="flex:1">
    <label class="i-lbl">Kategorie</label>
    <select id="tk-neu-kategorie" class="i-ctl">
     <option value="frage">Frage / Hilfe</option>
     <option value="verbesserung">Verbesserungsvorschlag / Idee</option>
     <option value="bug">Bug / Fehler im Plugin</option>
     <option value="organisation">Organisatorisches</option>
     <option value="schwimmer">Schwimmer hinzufügen oder verschieben</option>
     <option value="datenaenderung">Daten ändern</option>
    </select>
   </div>
   <div style="flex:1">
    <label class="i-lbl">Priorität</label>
    <select id="tk-neu-prioritaet" class="i-ctl">
     <option value="niedrig">Niedrig</option>
     <option value="normal" selected>Normal</option>
     <option value="hoch">Hoch</option>
     <option value="dringend">Dringend</option>
    </select>
   </div>
  </div>

  <label class="i-lbl" style="margin-top:10px">Beschreibung</label>
  <textarea id="tk-neu-text" class="i-ctl" rows="6" placeholder="Was ist passiert? Was ist dein Anliegen?"></textarea>

  <div style="margin-top:10px">
   <label class="i-btn i-btn-g i-btn-sm" style="cursor:pointer;margin:0">
    + Datei oder Bild anhängen
    <input type="file" id="tk-neu-file" multiple style="display:none">
   </label>
   <div id="tk-neu-anhaenge" style="margin-top:8px"></div>
  </div>
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-tk-neu">Abbrechen</button>
  <button id="tk-neu-save" class="i-btn i-btn-p">Ticket erstellen</button>
 </div>
</div></div>

<!-- Modal: Ticket-Detail mit Chat-Verlauf -->
<div id="m-tk-detail" class="i-ov"><div class="i-modal" style="max-width:780px">
 <div class="i-mhd">
  <span id="m-tk-detail-title">Ticket</span>
  <button class="i-mx" data-close="m-tk-detail">&#10005;</button>
 </div>
 <div class="i-mbd" style="display:flex;flex-direction:column;gap:12px;max-height:70vh">
  <!-- Meta-Block: Kategorie, Status, Priorität, Zuweisung -->
  <div id="tk-detail-meta" class="tk-meta"></div>

  <!-- Chat-Verlauf -->
  <div id="tk-detail-verlauf" class="tk-verlauf"></div>

  <!-- Eingabe -->
  <div id="tk-detail-input-wrap">
   <div id="tk-detail-anhaenge-pending" style="margin-bottom:6px"></div>
   <div style="display:flex;gap:6px;align-items:flex-end">
    <textarea id="tk-detail-text" class="i-ctl" rows="2" placeholder="Antwort schreiben…" style="flex:1;resize:vertical;min-height:42px"></textarea>
    <label class="i-btn i-btn-g i-btn-sm" style="cursor:pointer;margin:0" title="Datei anhängen">
     📎
     <input type="file" id="tk-detail-file" multiple style="display:none">
    </label>
    <button id="tk-detail-senden" class="i-btn i-btn-p">Senden</button>
   </div>
  </div>
 </div>
 <div class="i-mft">
  <button id="tk-detail-loeschen" class="i-btn i-btn-r i-btn-sm" style="display:none">Ticket löschen</button>
  <button class="i-btn i-btn-r" data-close="m-tk-detail">Schließen</button>
 </div>
</div></div>
<?php endif;?>

<?php /* ══ NACHRICHTEN ══ */ if(LSV07I_Access::has_any_access()):?>
<div id="i-sec-n" class="i-sec" style="display:none">
 <div class="i-sec-hd" style="display:none"><h2>Nachrichten</h2></div>
 <div id="chat-wrap" class="chat-wrap">

  <!-- ── Liste der Unterhaltungen ─────────────────────────────────── -->
  <div id="chat-left" class="chat-col chat-col-left">
   <div class="chat-left-hd">
    <div class="chat-left-titel">
     <strong>Nachrichten</strong>
     <button id="chat-new" class="chat-neu-btn" title="Neue Unterhaltung" aria-label="Neue Unterhaltung">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
     </button>
    </div>
    <div class="chat-suche-wrap">
     <svg class="chat-suche-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
     <input type="text" id="chat-search" class="chat-suche" placeholder="Name oder Nachricht" aria-label="Unterhaltungen durchsuchen" data-no-save>
    </div>
   </div>
   <div id="chat-list" class="chat-list">
    <div class="i-spin" style="padding:24px;text-align:center">Wird geladen…</div>
   </div>
  </div>

  <!-- ── Aktive Unterhaltung ──────────────────────────────────────── -->
  <div id="chat-right" class="chat-col chat-col-right">
   <div id="chat-empty" class="chat-empty">
    <div class="chat-empty-inhalt">
     <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
     <p>Wähle links eine Unterhaltung<br>oder starte eine neue.</p>
    </div>
   </div>

   <div id="chat-view" class="chat-view" style="display:none">
    <div class="chat-view-hd">
     <button class="chat-back" id="chat-back" aria-label="Zurück zur Liste">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
     </button>
     <div class="chat-view-txt">
      <div id="chat-view-title" class="chat-view-titel">Unterhaltung</div>
      <div id="chat-view-meta" class="chat-view-meta"></div>
     </div>
     <button id="chat-info" class="chat-kopf-btn" aria-label="Info zur Unterhaltung">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
     </button>
    </div>

    <div id="chat-messages" class="chat-messages"></div>

    <div id="chat-typing" class="chat-typing">
     <span class="chat-typing-dots"><span></span><span></span><span></span></span>
     <span class="chat-typing-text">tippt …</span>
    </div>

    <div id="chat-attach-info" class="chat-attach-info" style="display:none"></div>

    <div class="chat-input">
     <input type="file" id="chat-file" hidden accept=".pdf,.jpg,.jpeg,.png,.webp">
     <button id="chat-attach" class="chat-rund-btn" aria-label="Datei anhängen" title="Datei anhängen">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
     </button>
     <div class="chat-eingabe-feld">
      <textarea id="chat-textarea" placeholder="Nachricht schreiben…" rows="1" data-no-save></textarea>
     </div>
     <button id="chat-dringend-btn" class="chat-rund-btn chat-dringend-btn" aria-pressed="false"
             title="Dringend — löst sofort eine E-Mail aus" aria-label="Als dringend markieren">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
     </button>
     <input type="checkbox" id="chat-dringend" hidden>
     <button id="chat-send" class="chat-rund-btn chat-send-btn" aria-label="Senden" title="Senden">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
     </button>
    </div>
   </div>
  </div>
 </div>

 <!-- ── Modal: Neuer Chat / Neue Gruppe ─────────────────────────── -->


 <!-- ── Menü für eine Unterhaltung in der Liste ─────────────────── -->
 <div id="chat-item-menu" class="chat-item-menu">
  <button type="button" class="chat-item-menu-eintrag" data-aktion="oeffnen">
   <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
   <span>Öffnen</span>
  </button>
  <button type="button" class="chat-item-menu-eintrag" data-aktion="info">
   <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><circle cx="12" cy="8" r=".6" fill="currentColor"/></svg>
   <span>Teilnehmer &amp; Info</span>
  </button>
  <button type="button" class="chat-item-menu-eintrag gefahr" data-aktion="entfernen">
   <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6V4h6v2"/><path d="M4 6h16"/><path d="M18 6l-1 14H7L6 6"/></svg>
   <span class="chat-item-menu-txt">Aus Liste entfernen</span>
  </button>
 </div>

 <!-- ── Kontextmenü für Chat-Nachrichten (Rechtsklick / Long-Press) ── -->
 <div id="chat-ctx" class="chat-ctx-menu">
  <div class="chat-ctx-emoji" id="chat-ctx-emoji">
   <span class="em" data-em="👍">👍</span>
   <span class="em" data-em="❤️">❤️</span>
   <span class="em" data-em="😂">😂</span>
   <span class="em" data-em="😮">😮</span>
   <span class="em" data-em="😢">😢</span>
   <span class="em" data-em="🙏">🙏</span>
   <span class="em" data-em="🎉">🎉</span>
   <span class="em" data-em="✅">✅</span>
  </div>
  <div class="ctx-item" data-action="edit"><span>✏️</span> Bearbeiten</div>
  <div class="ctx-item danger" data-action="delete"><span>🗑</span> Löschen</div>
 </div>

</div><!-- /i-sec-n -->

<!-- ── Modals: Chat (global, damit sie auch außerhalb von #i-sec-n sichtbar sind) ── -->
 <div id="m-chat-new" class="i-ov"><div class="i-modal" style="max-width:560px">
  <div class="i-mhd"><span>Neuer Chat</span><button class="i-mx" data-close="m-chat-new">&#10005;</button></div>
  <div class="i-mbd">
   <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <button class="i-btn chat-new-typ on" data-typ="direkt" style="flex:1">1:1</button>
    <button class="i-btn chat-new-typ" data-typ="gruppe" style="flex:1">Gruppe</button>
    <button class="i-btn chat-new-typ" data-typ="verteiler" style="flex:1">Verteiler</button>
    <button class="i-btn chat-new-typ" data-typ="mannschaft" style="flex:1">Mannschaft</button>
   </div>

   <div id="chat-new-direkt-block">
    <label class="i-lbl">User suchen</label>
    <input type="text" id="chat-new-user-search" class="i-ctl" placeholder="Name, E-Mail…">
    <div id="chat-new-user-results" style="margin-top:6px"></div>
   </div>

   <div id="chat-new-gruppe-block" style="display:none">
    <label class="i-lbl">Name der Gruppe</label>
    <input type="text" id="chat-new-gruppe-name" class="i-ctl" maxlength="200" placeholder="z.B. „Trainer-Crew"">
    <label class="i-lbl">Teilnehmer hinzufügen</label>
    <input type="text" id="chat-new-gruppe-search" class="i-ctl" placeholder="User suchen…">
    <div id="chat-new-gruppe-search-results" style="margin-top:6px"></div>
    <label class="i-lbl">Externe Empfänger (E-Mail, optional)</label>
    <input type="email" id="chat-new-extern-email" class="i-ctl" placeholder="eltern@beispiel.de">
    <button type="button" id="chat-new-extern-add" class="i-btn i-btn-sm" style="margin-top:6px">+ Hinzufügen</button>
    <div style="margin-top:12px">
     <strong style="font-size:12px;color:#6b6e85;text-transform:uppercase;letter-spacing:.04em">Ausgewählt:</strong>
     <div id="chat-new-teilnehmer-liste" style="margin-top:4px;display:flex;flex-wrap:wrap;gap:4px;min-height:30px"></div>
    </div>
   </div>

   <div id="chat-new-mannschaft-block" style="display:none">
    <label class="i-lbl">Mannschaft</label>
    <select id="chat-new-mannschaft-sel" class="i-ctl"><option value="">Mannschaft wählen…</option></select>
    <div class="i-lbl-hint" style="margin-top:4px">
     Es wird ein Verteiler mit allen Trainern und Kontaktpersonen dieser Mannschaft angelegt.
     Externe Kontakte bekommen die Nachrichten per E-Mail.
    </div>
   </div>
  </div>
  <div class="i-mft">
   <button class="i-btn" data-close="m-chat-new">Abbrechen</button>
   <button id="chat-new-create" class="i-btn i-btn-p">Chat erstellen</button>
  </div>
 </div></div>

 <div id="m-chat-info" class="i-ov"><div class="i-modal" style="max-width:540px">
  <div class="i-mhd">
   <span id="m-chat-info-title">Chat-Info</span>
   <button class="i-mx" data-close="m-chat-info">&#10005;</button>
  </div>
  <div class="i-mbd">

   <!-- Gruppenname bearbeiten (nur Admins) -->
   <div id="chat-info-edit-name" style="display:none;margin-bottom:14px">
    <label class="i-lbl">Gruppenname</label>
    <div style="display:flex;gap:6px">
     <input type="text" id="chat-info-name-input" class="i-ctl" maxlength="200" style="flex:1">
     <button id="chat-info-name-save" class="i-btn i-btn-p">Speichern</button>
    </div>
   </div>

   <!-- Teilnehmer-Liste -->
   <div id="chat-info-teilnehmer"></div>

   <!-- Teilnehmer hinzufügen (nur Admins) -->
   <div id="chat-info-add-block" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--c-line)">
    <strong style="font-size:12px;color:#6b6e85;text-transform:uppercase;letter-spacing:.04em">Teilnehmer hinzufügen</strong>
    <input type="text" id="chat-info-add-search" class="i-ctl" placeholder="User suchen…" style="margin-top:6px">
    <div id="chat-info-add-results" style="margin-top:6px"></div>
    <div style="display:flex;gap:6px;margin-top:8px">
     <input type="email" id="chat-info-add-email" class="i-ctl" placeholder="Externe E-Mail" style="flex:1">
     <button id="chat-info-add-email-btn" class="i-btn i-btn-sm">+ Hinzufügen</button>
    </div>
   </div>

   <!-- Gefahrenzone (nur Admins, nicht bei Direkt-Chats) -->
   <div id="chat-info-danger" style="display:none;margin-top:14px;padding:10px 12px;background:rgba(220,38,38,0.15);border:1px solid #b91c1c;border-radius:8px">
    <strong style="font-size:12px;color:#b91c1c;display:block;margin-bottom:6px">Gefahrenzone</strong>
    <button id="chat-info-leave" class="i-btn i-btn-sm" style="margin-right:6px">Gruppe verlassen</button>
    <button id="chat-info-delete" class="i-btn i-btn-r i-btn-sm">Gruppe löschen</button>
   </div>

   <!-- "Verlassen" für Nicht-Admins (Direktchats ausgenommen) -->
   <div id="chat-info-leave-only" style="display:none;margin-top:14px">
    <button id="chat-info-leave2" class="i-btn i-btn-sm">Gruppe verlassen</button>
   </div>
  </div>
  <div class="i-mft">
   <button class="i-btn" data-close="m-chat-info">Schließen</button>
  </div>
 </div></div>

<?php endif;?>

</div><!-- /body -->

<!-- ══ MODALS ══ -->
<div id="m-trainer" class="i-ov"><div class="i-modal" style="max-width:640px">
 <div class="i-mhd"><span id="m-trainer-ttl">Trainer</span><button class="i-mx" data-close="m-trainer">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="mt-id">
  <div class="i-row">
   <div class="i-f"><label class="i-lbl">Nachname *</label><input type="text" id="mt-nachname" class="i-ctl"></div>
   <div class="i-f"><label class="i-lbl">Vorname *</label><input type="text" id="mt-vorname" class="i-ctl"></div>
  </div>
  <div class="i-row">
   <div class="i-f"><label class="i-lbl">Geburtsdatum *</label><input type="date" id="mt-geburt" class="i-ctl"></div>
   <div class="i-f"><label class="i-lbl">Telefon</label><input type="text" id="mt-telefon" class="i-ctl"></div>
  </div>
  <div class="i-row">
   <div class="i-f"><label class="i-lbl">E-Mail Privat</label><input type="email" id="mt-email-privat" class="i-ctl"></div>
   <div class="i-f"><label class="i-lbl">E-Mail Geschäftlich</label><input type="email" id="mt-email-geschaeft" class="i-ctl"></div>
  </div>

  <div style="margin-top:12px;padding-top:10px;border-top:1px solid #e2ecf5">
   <div style="font-size:11px;font-weight:700;color:#2a5fd0;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px">Qualifikationen &amp; Dokumente</div>
   <div class="i-row">
    <div class="i-f"><label class="i-lbl">Führungszeugnis gültig bis</label><input type="date" id="mt-fuehrungszeugnis" class="i-ctl"></div>
    <div class="i-f"><label class="i-lbl">Rettungsschwimmer gültig bis</label><input type="date" id="mt-rettungsschwimmer" class="i-ctl"></div>
   </div>
   <div class="i-row">
    <div class="i-f"><label class="i-lbl">Lizenznummer</label><input type="text" id="mt-lizenznummer" class="i-ctl"></div>
    <div class="i-f"><label class="i-lbl">Lizenz gültig bis</label><input type="date" id="mt-lizenz-gueltig" class="i-ctl"></div>
   </div>
   <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;margin-top:6px">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px"><input type="checkbox" id="mt-trainervertrag" style="width:15px;height:15px;accent-color:#5b94ff"> Trainervertrag vorhanden</label>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px"><input type="checkbox" id="mt-trainerlizenz" style="width:15px;height:15px;accent-color:#5b94ff"> Trainerlizenz vorhanden</label>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px"><input type="checkbox" id="mt-verhaltenskodex" style="width:15px;height:15px;accent-color:#5b94ff"> Verhaltenskodex unterzeichnet</label>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px"><input type="checkbox" id="mt-datenschutz" style="width:15px;height:15px;accent-color:#5b94ff"> Datenschutzerklärung unterzeichnet</label>
   </div>
  </div>

  <div style="margin-top:12px;padding-top:10px;border-top:1px solid #e2ecf5">
   <div style="font-size:11px;font-weight:700;color:#2a5fd0;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px">Mannschaften (Haupttrainer)</div>
   <div id="mt-mann" class="i-cbg"></div>
  </div>

  <div style="margin-top:12px;padding-top:10px;border-top:1px solid #e2ecf5">
   <label class="i-lbl">WordPress-Konto (für Login)</label>
   <select id="mt-user" class="i-ctl"><option value="0">Kein Konto</option></select>
  </div>
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-trainer">Abbrechen</button><button id="mt-save" class="i-btn i-btn-p">Speichern</button></div>
</div></div>

<!-- Schwimmer-Dateien (Lese-Modal aus Mannschaftsliste) -->
<div id="m-sw-files" class="i-ov"><div class="i-modal" style="max-width:560px">
 <div class="i-mhd">
  <span><span id="m-sw-files-name">Dateien</span></span>
  <button class="i-mx" data-close="m-sw-files">&#10005;</button>
 </div>
 <div class="i-mbd">
  <div id="m-sw-files-list"><span class="i-muted">Lade…</span></div>
 </div>
 <div class="i-mft">
  <button class="i-btn" data-close="m-sw-files">Schließen</button>
 </div>
</div></div>

<div id="m-mann" class="i-ov"><div class="i-modal">
 <div class="i-mhd"><span id="m-mann-ttl">Mannschaft</span><button class="i-mx" data-close="m-mann">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="mm-id">
  <label class="i-lbl">Name *</label><input type="text" id="mm-name" class="i-ctl">
  <label class="i-lbl">Reihenfolge</label><input type="number" id="mm-sort" class="i-ctl" value="0">
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-mann">Abbrechen</button><button id="mm-save" class="i-btn i-btn-p">Speichern</button></div>
</div></div>

<div id="m-sw" class="i-ov"><div class="i-modal" style="max-width:560px">
 <div class="i-mhd"><span id="m-sw-ttl">Schwimmer</span><button class="i-mx" data-close="m-sw">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="msw-id">
  <div class="i-row">
   <div class="i-f"><label class="i-lbl">Nachname *</label><input type="text" id="msw-last" class="i-ctl"></div>
   <div class="i-f"><label class="i-lbl">Vorname *</label><input type="text" id="msw-first" class="i-ctl"></div>
  </div>
  <div class="i-row">
   <div class="i-f"><label class="i-lbl">Geburtsdatum *</label><input type="date" id="msw-birth" class="i-ctl"></div>
   <div class="i-f"><label class="i-lbl">Attest gültig bis</label><input type="date" id="msw-attest" class="i-ctl"></div>
  </div>
  <div class="i-row">
   <div class="i-f"><label class="i-lbl">DSV-ID</label><input type="text" id="msw-dsv" class="i-ctl" placeholder="Optional"></div>
   <div class="i-f" style="display:flex;align-items:flex-end;padding-bottom:6px">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
     <input type="checkbox" id="msw-datenschutz" style="width:15px;height:15px;accent-color:#5b94ff"> Datenschutzerklärung unterzeichnet
    </label>
   </div>
  </div>
  <label class="i-lbl">Mannschaften (Mehrfachauswahl) *</label>
  <div id="msw-gruppen-cb" style="border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:8px;max-height:140px;overflow-y:auto;background:rgba(255,255,255,0.05);margin-bottom:8px"></div>
  <label class="i-lbl">Notizen</label><input type="text" id="msw-notes" class="i-ctl" placeholder="Optional">

  <div style="margin-top:14px;padding-top:12px;border-top:1px solid #e2ecf5">
   <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <span style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px">Kontaktpersonen</span>
    <button type="button" id="msw-add-kontakt" class="i-btn i-btn-g i-btn-sm">+ Kontakt hinzufügen</button>
   </div>
   <div id="msw-kontakte-liste"></div>
  </div>

  <!-- Dateien (nur beim Bearbeiten sichtbar) -->
  <div id="msw-dateien-block" style="margin-top:14px;padding-top:12px;border-top:1px solid #e2ecf5;display:none">
   <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px;flex-wrap:wrap">
    <span style="font-size:12px;font-weight:700;color:#6b6e85;text-transform:uppercase;letter-spacing:.4px">Dateien (Atteste, Bestätigungen)</span>
    <button type="button" id="msw-datei-add" class="i-btn i-btn-g i-btn-sm">+ Datei hochladen</button>
   </div>
   <div class="i-lbl-hint" style="margin-bottom:6px">PDF, JPG, PNG · max. 5 MB pro Datei</div>
   <div id="msw-datei-form" style="display:none;padding:10px;background:var(--c-blue-soft);border-radius:8px;margin-bottom:8px">
    <label class="i-lbl" style="margin-top:0">Datei wählen</label>
    <input type="file" id="msw-datei-input" accept=".pdf,.jpg,.jpeg,.png" class="i-ctl">
    <label class="i-lbl">Kommentar (optional)</label>
    <input type="text" id="msw-datei-kommentar" class="i-ctl" placeholder="z.B. 'Attest 2025/2026'" maxlength="500">
    <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:10px">
     <button type="button" id="msw-datei-cancel" class="i-btn i-btn-g i-btn-sm">Abbrechen</button>
     <button type="button" id="msw-datei-upload" class="i-btn i-btn-p i-btn-sm">Hochladen</button>
    </div>
   </div>
   <div id="msw-datei-liste"><span class="i-muted" style="font-size:12px">Lade…</span></div>
  </div>
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-sw">Abbrechen</button><button id="msw-save" class="i-btn i-btn-p">Speichern</button></div>
</div></div>

<div id="m-slot" class="i-ov"><div class="i-modal">
 <div class="i-mhd"><span id="m-slot-ttl">Trainingszeit</span><button class="i-mx" data-close="m-slot">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="msl-id">
  <label class="i-lbl">Mannschaft *</label><select id="msl-mann" class="i-ctl"><option value="">Wählen…</option></select>
  <label class="i-lbl">Wochentag</label>
  <select id="msl-wt" class="i-ctl">
   <option value="1">Montag</option><option value="2">Dienstag</option><option value="3">Mittwoch</option>
   <option value="4">Donnerstag</option><option value="5">Freitag</option><option value="6">Samstag</option><option value="7">Sonntag</option>
  </select>
  <div class="i-row"><div class="i-f"><label class="i-lbl">Von *</label><input type="time" id="msl-von" class="i-ctl"></div><div class="i-f"><label class="i-lbl">Bis *</label><input type="time" id="msl-bis" class="i-ctl"></div></div>
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-slot">Abbrechen</button><button id="msl-save" class="i-btn i-btn-p">Speichern</button></div>
</div></div>

<div id="m-wk" class="i-ov"><div class="i-modal">
 <div class="i-mhd"><span id="m-wk-ttl">Wettkampf</span><button class="i-mx" data-close="m-wk">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="mwk-id"><input type="hidden" id="mwk-abr-id">
  <label class="i-lbl">Datum *</label><input type="date" id="mwk-datum" class="i-ctl">
  <label class="i-lbl">Wettkampfname</label><input type="text" id="mwk-name" class="i-ctl" placeholder="z.B. Stadtmeisterschaften">
  <label class="i-lbl">Anzahl Abschnitte *</label><input type="number" id="mwk-abschnitte" class="i-ctl" min="1" value="1">
  <div id="mwk-berechnung" class="i-notice i-notice-g" style="margin-top:10px;display:none"></div>
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-wk">Abbrechen</button><button id="mwk-save" class="i-btn i-btn-p">Speichern</button></div>
</div></div>

<!-- Person anlegen/bearbeiten (Admin-Tab Personen) -->
<div id="m-pers-adm" class="i-ov"><div class="i-modal" style="max-width:640px">
 <div class="i-mhd"><span id="m-pers-adm-ttl">Person anlegen</span><button class="i-mx" data-close="m-pers-adm">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="mpers-id">
  <div class="mpers-grid">
   <div><label class="i-lbl">Vorname *</label><input type="text" id="mpers-vorname" class="i-ctl"></div>
   <div><label class="i-lbl">Nachname *</label><input type="text" id="mpers-nachname" class="i-ctl"></div>
   <div><label class="i-lbl">Geburtsdatum</label><input type="date" id="mpers-geburtsdatum" class="i-ctl"></div>
   <div><label class="i-lbl">Geschlecht</label>
    <select id="mpers-geschlecht" class="i-ctl">
     <option value="">–</option><option value="M">männlich</option>
     <option value="W">weiblich</option><option value="D">divers</option>
    </select>
   </div>
   <div><label class="i-lbl">E-Mail</label><input type="email" id="mpers-email" class="i-ctl"></div>
   <div><label class="i-lbl">Telefon</label><input type="text" id="mpers-telefon" class="i-ctl"></div>
   <div><label class="i-lbl">DSV-ID</label><input type="text" id="mpers-dsv" class="i-ctl"></div>
   <div><label class="i-lbl">Mitgliedsnummer</label><input type="text" id="mpers-mnr" class="i-ctl"></div>
  </div>

  <label class="i-lbl" style="margin-top:12px">WordPress-Account</label>
  <select id="mpers-wpuser" class="i-ctl"><option value="">– kein Account –</option></select>
  <div class="i-muted" style="font-size:12px;margin-top:4px">Nur nötig, wenn sich die Person anmelden soll. Über den Account erhält sie in der Rechteverwaltung ihre Rechte.</div>

  <label class="i-lbl" style="margin-top:12px">Sparten &amp; Rollen</label>
  <div class="mpers-box">
   <table class="mpers-sr-tbl">
    <thead><tr><th></th><th>Sportler</th><th>Trainer</th></tr></thead>
    <tbody>
     <tr><td><strong>Schwimmen</strong></td>
      <td><input type="checkbox" class="mpers-sr" data-sparte="schwimmen" data-rolle="sportler"></td>
      <td><input type="checkbox" class="mpers-sr" data-sparte="schwimmen" data-rolle="trainer"></td></tr>
     <tr><td><strong>Triathlon</strong></td>
      <td><input type="checkbox" class="mpers-sr" data-sparte="triathlon" data-rolle="sportler"></td>
      <td><input type="checkbox" class="mpers-sr" data-sparte="triathlon" data-rolle="trainer"></td></tr>
     <tr><td><strong>Fitness</strong></td>
      <td><input type="checkbox" class="mpers-sr" data-sparte="fitness" data-rolle="sportler"></td>
      <td><input type="checkbox" class="mpers-sr" data-sparte="fitness" data-rolle="trainer"></td></tr>
    </tbody>
   </table>
  </div>

  <label class="i-lbl" style="margin-top:12px">Mannschaften</label>
  <div id="mpers-mann" class="mpers-box mpers-box-scroll">
   <span class="i-muted">Erst oben eine Sparte anhaken, dann erscheinen hier die Mannschaften.</span>
  </div>

  <label class="i-lbl" style="margin-top:12px">Notizen</label>
  <textarea id="mpers-notizen" class="i-ctl" rows="2"></textarea>

  <label class="pcsv-check"><input type="checkbox" id="mpers-aktiv" checked> Aktiv</label>
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-pers-adm">Abbrechen</button>
  <button id="mpers-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<div id="m-km" class="i-ov"><div class="i-modal">
 <div class="i-mhd"><span id="m-km-ttl">Fahrtkosten</span><button class="i-mx" data-close="m-km">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="mkm-id"><input type="hidden" id="mkm-abr-id">
  <label class="i-lbl">Datum *</label><input type="date" id="mkm-datum" class="i-ctl">
  <label class="i-lbl">Beschreibung</label><input type="text" id="mkm-beschr" class="i-ctl" placeholder="z.B. Fahrt zum Wettkampf Stadtbad">
  <label class="i-lbl">Kilometer (einfache Strecke) *</label><input type="number" id="mkm-km" class="i-ctl" step="0.1" min="0.1" placeholder="0">
  <div style="font-size:12px;color:#6b6e85;margin-top:4px">Bitte nur einfache Fahrt abgeben, die Rückfahrt wird automatisch dazugerechnet.</div>
  <label class="i-lbl" style="margin-top:12px">Anzahl gefahrener Tage *</label><input type="number" id="mkm-tage" class="i-ctl" step="1" min="1" value="1" placeholder="1">
  <div style="font-size:12px;color:#6b6e85;margin-top:4px">Pro Tag werden eine Hin- und eine Rückfahrt berechnet.</div>
  <div id="mkm-berechnung" class="i-notice i-notice-g" style="margin-top:10px;display:none"></div>
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-km">Abbrechen</button><button id="mkm-save" class="i-btn i-btn-p">Speichern</button></div>
</div></div>

<!-- ── Modal: Reflexionsbogen ansehen (global) ──────────────────── -->
<div id="m-refl-bogen" class="i-ov"><div class="i-modal" style="max-width:640px">
 <div class="i-mhd"><span id="m-refl-bogen-title">Reflexionsbogen</span><button class="i-mx" data-close="m-refl-bogen">&#10005;</button></div>
 <div class="i-mbd" id="m-refl-bogen-body"></div>
 <div class="i-mft"><button class="i-btn" data-close="m-refl-bogen">Schließen</button></div>
</div></div>

<!-- ── Modal: Erstellte Links anzeigen (global) ─────────────────── -->
<div id="m-refl-links" class="i-ov"><div class="i-modal" style="max-width:600px">
 <div class="i-mhd"><span>Zugangsschlüssel &amp; Links</span><button class="i-mx" data-close="m-refl-links">&#10005;</button></div>
 <div class="i-mbd" id="m-refl-links-body"></div>
 <div class="i-mft"><button class="i-btn" data-close="m-refl-links">Schließen</button></div>
</div></div>

<!-- ══ EINSTELLUNGEN (Zahnrad auf der Startseite) ══════════════════ -->
<div id="m-einst" class="i-ov"><div class="i-modal i-modal-einst" style="max-width:720px">
 <div class="i-mhd"><span>Einstellungen</span><button class="i-mx" data-close="m-einst">&#10005;</button></div>
 <div class="i-mbd i-einst-bd">
  <nav class="i-einst-nav" role="tablist">
   <button type="button" class="i-einst-tab on" data-ziel="einst-darstellung">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    <span>Darstellung</span>
   </button>
   <button type="button" class="i-einst-tab" data-ziel="einst-passwort">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    <span>Passwort</span>
   </button>
   <button type="button" class="i-einst-tab" data-ziel="einst-kontakt">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="m4 7 8 6 8-6"/></svg>
    <span>Kontaktdaten</span>
   </button>
   <button type="button" class="i-einst-tab" data-ziel="einst-bild">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/></svg>
    <span>Profilbild</span>
   </button>
   <button type="button" class="i-einst-tab" data-ziel="einst-widgets">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
    <span>Widgets</span>
   </button>
  </nav>

  <div class="i-einst-inhalt">

   <!-- Darstellung -->
   <section id="einst-darstellung" class="i-einst-seite">
    <h4 class="i-einst-ttl">Darstellung</h4>
    <p class="i-lbl-hint">Gilt nur für den internen Bereich und nur für dein Konto.</p>
    <div class="i-themewahl">
     <label class="i-themekarte">
      <input type="radio" name="lsv-theme" value="hell">
      <span class="i-themevorschau i-themevorschau-hell"><span></span><span></span><span></span></span>
      <span class="i-themename">Hell</span>
     </label>
     <label class="i-themekarte">
      <input type="radio" name="lsv-theme" value="dunkel">
      <span class="i-themevorschau i-themevorschau-dunkel"><span></span><span></span><span></span></span>
      <span class="i-themename">Dunkel</span>
     </label>
     <label class="i-themekarte">
      <input type="radio" name="lsv-theme" value="auto">
      <span class="i-themevorschau i-themevorschau-auto"><span></span><span></span><span></span></span>
      <span class="i-themename">Automatisch</span>
     </label>
    </div>
    <p class="i-lbl-hint" style="margin-top:12px">„Automatisch“ folgt der Einstellung deines Geräts.</p>
   </section>

   <!-- Passwort -->
   <section id="einst-passwort" class="i-einst-seite" style="display:none">
    <h4 class="i-einst-ttl">Passwort ändern</h4>
    <label class="i-lbl">Aktuelles Passwort</label>
    <input type="password" id="einst-pw-alt" class="i-ctl" autocomplete="current-password" data-no-save>
    <label class="i-lbl" style="margin-top:10px">Neues Passwort</label>
    <input type="password" id="einst-pw-neu" class="i-ctl" autocomplete="new-password" data-no-save>
    <div class="i-lbl-hint">Mindestens 10 Zeichen.</div>
    <label class="i-lbl" style="margin-top:10px">Neues Passwort wiederholen</label>
    <input type="password" id="einst-pw-neu2" class="i-ctl" autocomplete="new-password" data-no-save>
    <button id="einst-pw-save" class="i-btn i-btn-p" style="margin-top:14px">Passwort ändern</button>
   </section>

   <!-- Kontaktdaten -->
   <section id="einst-kontakt" class="i-einst-seite" style="display:none">
    <h4 class="i-einst-ttl">Kontaktdaten</h4>
    <label class="i-lbl">E-Mail-Adresse</label>
    <input type="email" id="einst-email" class="i-ctl" autocomplete="email">
    <label class="i-lbl" style="margin-top:10px">Telefon</label>
    <input type="text" id="einst-telefon" class="i-ctl" autocomplete="tel">
    <div class="i-lbl-hint">Die Telefonnummer wird in deinem Personen-Datensatz gespeichert, sofern einer verknüpft ist.</div>
    <button id="einst-kontakt-save" class="i-btn i-btn-p" style="margin-top:14px">Speichern</button>
   </section>

   <!-- Profilbild -->
   <section id="einst-bild" class="i-einst-seite" style="display:none">
    <h4 class="i-einst-ttl">Profilbild</h4>
    <div class="i-bildwahl">
     <div class="i-avatar i-avatar-gross" id="einst-bild-vorschau"><span class="i-avatar-init">?</span></div>
     <div class="i-bildwahl-aktionen">
      <input type="file" id="einst-bild-datei" accept="image/jpeg,image/png,image/webp" hidden>
      <button type="button" id="einst-bild-waehlen" class="i-btn i-btn-p">Bild auswählen</button>
      <button type="button" id="einst-bild-weg" class="i-btn i-btn-r" style="display:none">Entfernen</button>
      <div class="i-lbl-hint">JPG, PNG oder WebP, maximal 3 MB. Das Bild wird mittig quadratisch zugeschnitten.</div>
     </div>
    </div>
   </section>

   <!-- Widgets -->
   <section id="einst-widgets" class="i-einst-seite" style="display:none">
    <h4 class="i-einst-ttl">Schnellzugriffe</h4>
    <p class="i-lbl-hint">Wähle, welche Symbole auf deiner Startseite erscheinen. Die Auswahl gilt nur für dich.</p>
    <div id="einst-widgets-body"></div>
    <button id="einst-widgets-save" class="i-btn i-btn-p" style="margin-top:14px">Auswahl speichern</button>
   </section>

  </div>
 </div>
 <div class="i-mft">
  <button class="i-btn i-btn-r" data-close="m-einst">Schließen</button>
 </div>
</div></div>

<div id="m-tag" class="i-ov"><div class="i-modal">
 <div class="i-mhd"><span id="mat-ttl">Training hinzufügen</span><button class="i-mx" data-close="m-tag">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="mat-id"><input type="hidden" id="mat-abr-id">
  <label class="i-lbl">Datum *</label><input type="date" id="mat-datum" class="i-ctl">
  <label class="i-lbl">Mannschaft</label><select id="mat-mann" class="i-ctl"><option value="">Wählen…</option></select>
  <label class="i-lbl">Stunden *</label><input type="number" id="mat-std" class="i-ctl" step="0.25" min="0.25" value="1.5">
  <label class="i-lbl" id="mat-begr-lbl">Begründung * <span style="color:#6b6e85;font-weight:400;font-size:11px">(Pflicht für manuelle Einträge)</span></label>
  <textarea id="mat-begr" class="i-ctl" placeholder="Warum wurde dieses Training nicht automatisch erfasst?"></textarea>
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-tag">Abbrechen</button><button id="mat-save" class="i-btn i-btn-p">Speichern</button></div>
</div></div>

<div id="m-tr-sonder" class="i-ov"><div class="i-modal">
 <div class="i-mhd"><span id="mts-ttl">Eintrag hinzufügen</span><button class="i-mx" data-close="m-tr-sonder">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="mts-id"><input type="hidden" id="mts-abr-id">
  <label class="i-lbl">Datum *</label><input type="date" id="mts-datum" class="i-ctl">
  <label class="i-lbl">Stunden *</label><input type="number" id="mts-stunden" class="i-ctl" step="0.25" min="0.25" value="1">
  <label class="i-lbl">Beschreibung <span style="color:#6b6e85;font-weight:400;font-size:11px">(optional)</span></label>
  <input type="text" id="mts-beschreibung" class="i-ctl" placeholder="z.B. Vereinsfest, Sondereinsatz …" maxlength="200">
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-tr-sonder">Abbrechen</button><button id="mts-save" class="i-btn i-btn-p">Speichern</button></div>
</div></div>

<div id="m-rg" class="i-ov"><div class="i-modal">
 <div class="i-mhd"><span>Abrechnung zurückgeben</span><button class="i-mx" data-close="m-rg">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="rg-id">
  <label class="i-lbl">Grund *</label>
  <textarea id="rg-grund" class="i-ctl" placeholder="Bitte beschreiben, was korrigiert werden soll…"></textarea>
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-rg">Abbrechen</button><button id="rg-send" class="i-btn i-btn-r">Zurückgeben</button></div>
</div></div>

<!-- Schwimmer-Profil Modal -->
<div id="m-sw-profil" class="i-ov"><div class="i-modal" style="max-width:520px">
 <div class="i-mhd"><span id="m-sw-profil-ttl">Schwimmer-Profil</span><button class="i-mx" data-close="m-sw-profil">&#10005;</button></div>
 <div class="i-mbd" id="m-sw-profil-bd" style="padding:0"></div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-sw-profil">Schließen</button></div>
</div></div>

<!-- Bestzeiten-Popup (aus Schwimmer-Profil) -->
<div id="m-sw-bz" class="i-ov"><div class="i-modal" style="max-width:520px">
 <div class="i-mhd"><span id="m-sw-bz-ttl">Bestzeiten</span><button class="i-mx" data-close="m-sw-bz">&#10005;</button></div>
 <div class="i-mbd" id="m-sw-bz-bd"></div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-sw-bz">Schließen</button></div>
</div></div>

<!-- Wettkampf anlegen/bearbeiten -->
<div id="m-wkedit" class="i-ov"><div class="i-modal" style="max-width:620px">
 <div class="i-mhd"><span id="mwke-ttl">Wettkampf anlegen</span><button class="i-mx" data-close="m-wkedit">&#10005;</button></div>
 <div class="i-mbd">
  <input type="hidden" id="mwke-id">
  <label class="i-lbl">Name *</label>
  <input type="text" id="mwke-name" class="i-ctl" placeholder="z.B. Stadtmeisterschaften 2026">
  <label class="i-lbl">Ort *</label>
  <input type="text" id="mwke-ort" class="i-ctl" placeholder="z.B. Stadtbad Mannheim">
  <div class="i-row">
   <div class="i-f"><label class="i-lbl">Datum von *</label><input type="date" id="mwke-von" class="i-ctl"></div>
   <div class="i-f"><label class="i-lbl">Datum bis *</label><input type="date" id="mwke-bis" class="i-ctl"></div>
  </div>
  <label class="i-lbl">Teilnehmende Mannschaften *</label>
  <div id="mwke-mann" style="display:flex;flex-wrap:wrap;gap:6px;padding:8px;border:1px solid rgba(255,255,255,0.15);border-radius:6px;background:rgba(255,255,255,0.05);min-height:44px"></div>
  <label class="i-lbl" style="margin-top:12px">Abschnitte pro Tag *</label>
  <div class="i-muted" style="font-size:11px;margin-bottom:6px">Geplante Anzahl Abschnitte pro Wettkampftag (z.B. 2 = Vormittag + Nachmittag).</div>
  <div id="mwke-tage"></div>

  <!-- Dokumente, Freigabe und Erinnerungsadressen: erst sichtbar, sobald der
       Wettkampf angelegt ist (brauchen eine ID). Bei Neuanlage erscheint
       dieser Block direkt nach dem ersten Speichern. -->
  <div id="mwke-erw" style="display:none">

   <div class="mwke-trenner">Dokumente</div>
   <div class="i-muted" style="font-size:11px;margin-bottom:10px">Nur PDF, maximal 10&nbsp;MB. Jedes hier hochgeladene Dokument wird auf der öffentlichen Übersichtsseite verlinkt, sobald der Wettkampf freigegeben ist.</div>
   <div id="mwke-dok-ausschreibung" class="mwke-dok" data-typ="ausschreibung" data-label="Ausschreibung *"></div>
   <div id="mwke-dok-meldeergebnis" class="mwke-dok" data-typ="meldeergebnis" data-label="Meldeergebnis"></div>
   <div id="mwke-dok-protokoll" class="mwke-dok" data-typ="protokoll" data-label="Protokoll"></div>

   <div class="mwke-trenner">Erinnerungs-E-Mails</div>
   <div class="i-muted" style="font-size:11px;margin-bottom:8px">Diese Adressen bekommen automatisch eine Erinnerung: 3&nbsp;Tage vor Beginn (Meldeergebnis hochladen), 1&nbsp;Tag nach Ende (Protokoll hochladen).</div>
   <div id="mwke-erinn-chips" class="mwke-chips"></div>
   <div style="display:flex;gap:8px;margin-top:8px">
    <input type="email" id="mwke-erinn-eingabe" class="i-ctl" placeholder="E-Mail-Adresse eingeben, dann Enter" style="flex:1" data-no-save>
    <button type="button" id="mwke-erinn-add" class="i-btn i-btn-g">+</button>
   </div>

   <!-- Nur sichtbar für Nutzer mit dem Freigabe-Recht (LSV07I.access.can_wk_approve) -->
   <div id="mwke-freigabe-block" style="display:none">
    <div class="mwke-trenner">Freigabe</div>
    <div id="mwke-freigabe-status" class="i-notice" style="margin-bottom:10px"></div>
    <button type="button" id="mwke-freigeben" class="i-btn i-btn-p" style="width:100%">Jetzt freigeben</button>
    <button type="button" id="mwke-unapprove" class="i-btn i-btn-r" style="width:100%;display:none">Freigabe zurückziehen</button>
   </div>
  </div>
 </div>
 <div class="i-mft">
  <?php if($cA||$cSW):?><button id="mwke-del" class="i-btn i-btn-r" style="margin-right:auto;display:none">Löschen</button><?php endif;?>
  <button class="i-btn i-btn-r" data-close="m-wkedit">Abbrechen</button>
  <button id="mwke-save" class="i-btn i-btn-p">Speichern</button>
 </div>
</div></div>

<!-- Offene Wettkampftage (für manuelles Nachtragen in der Abrechnung) -->
<div id="m-wkoffen" class="i-ov"><div class="i-modal" style="max-width:620px">
 <div class="i-mhd"><span>Wettkampftag hinzufügen</span><button class="i-mx" data-close="m-wkoffen">&#10005;</button></div>
 <div class="i-mbd">
  <div class="i-muted" style="font-size:12px;margin-bottom:8px">Wählen Sie Wettkampftage aus, die Sie Ihrer Abrechnung nachträglich hinzufügen möchten.</div>
  <div id="mwko-liste"><div class="i-spin">Wird geladen…</div></div>
 </div>
 <div class="i-mft"><button class="i-btn i-btn-r" data-close="m-wkoffen">Schließen</button></div>
</div></div>
</div><!-- /root -->
