<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Das Gerüst. Gefüllt wird es vom Skript.
 *
 * Absichtlich klein: Im internen Bereich stehen 995 <div> und 41 Dialoge
 * in einer Datei mit 3172 Zeilen, 521 davon mit Inline-Stil — danach
 * findet man nichts mehr. Hier steht der Rahmen (Kopfband, Reiterleiste,
 * Bühne, ein Dialog), und was eine Ansicht ausmacht, entsteht im Skript
 * aus den Daten.
 *
 * Der Aufbau ist derselbe wie in der Abrechnung: dunkles Kopfband mit den
 * Bereichen als Pivot-Reiter, darunter die Teile des Bereichs als
 * unterstrichene Reiterleiste.
 */
?>
<?php
/* Die gewählte Darstellung steht IM Markup, nicht erst im Skript: Sonst
   erscheint die Seite hell und wird dunkel, sobald das Skript läuft —
   ein Blitzen, das man nicht wieder einfängt. */
$lsv07s_thema = class_exists( 'LSV07S_Profil' ) ? LSV07S_Profil::thema() : 'hell';
?>
<div id="s-root" data-theme="<?php echo esc_attr( $lsv07s_thema ); ?>">

  <header id="s-top">
    <div class="s-top-in">
      <button id="s-menue" aria-label="Bereiche" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
      <a class="s-marke" href="#start/aufgaben">SwimSys</a>

      <nav id="s-nav" aria-label="Bereiche"></nav>

      <div class="s-suche">
        <input type="search" id="s-suche-feld" autocomplete="off"
               placeholder="Suchen (Taste /)" aria-label="Suchen">
        <div class="s-suche-aus" id="s-suche-aus" hidden></div>
      </div>

      <div class="s-top-rechts">
        <!-- Die Glocke. Sie steht für JEDES Konto da, das hereinkommt:
             Eine Mitteilung „Das Becken ist gesperrt" nützt nichts, wenn
             sie der halben Mannschaft nicht angezeigt wird. Der Zähler
             erscheint erst, wenn etwas ungelesen ist — eine Null ist
             keine Nachricht. -->
        <!-- Die Glocke als SVG, nicht als Zeichen aus einer Schrift: Ein
             Emoji sieht auf jedem System anders aus, und auf manchen
             fehlt es ganz. -->
        <button id="s-glocke" class="s-glocke" aria-label="Mitteilungen">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 3a5 5 0 0 0-5 5v3.6l-1.3 2.6a1 1 0 0 0 .9 1.5h10.8a1 1 0 0 0
                     .9-1.5L17 11.6V8a5 5 0 0 0-5-5z"></path>
            <path d="M10 18.5a2 2 0 0 0 4 0z"></path>
          </svg>
          <span class="s-glocke-z" id="s-glocke-z" hidden></span>
        </button>
        <!-- Das eigene Profil. Der Knopf trägt das Bild, wenn eines
             hinterlegt ist, sonst die Initialen — ein leerer Kreis sieht
             aus wie ein Fehler. -->
        <button id="s-ich" class="s-ich" aria-label="Mein Profil"></button>
        <span class="s-wer" id="s-wer"></span>
      </div>
    </div>
  </header>

  <div id="s-body">
    <div class="s-seite" id="s-seite">
      <div class="s-reiter" id="s-reiter"></div>
      <div id="s-buehne" tabindex="-1">
        <div class="s-laedt">Wird geladen …</div>
      </div>
    </div>
  </div>

  <!-- EIN Dialog für alles. Vier Kopien eines Dialogs laufen auseinander. -->
  <div class="s-ov" id="s-dialog">
    <div class="s-dlg" role="dialog" aria-modal="true" aria-labelledby="s-dialog-titel">
      <div class="s-dlg-hd">
        <h2 id="s-dialog-titel"></h2>
        <button class="s-x" data-zu aria-label="Schließen">×</button>
      </div>
      <div class="s-dlg-bd" id="s-dialog-inhalt"></div>
      <div class="s-dlg-ft" id="s-dialog-fuss"></div>
    </div>
  </div>

  <div class="s-toast" id="s-toast" role="status" aria-live="polite"></div>
</div>

<script>
/* Vollbild — und zwar verlässlich.
 *
 * Im Stilblatt steht eine Liste bekannter Theme-Namen (#masthead,
 * .site-footer, …). Die hilft nur bei Themes, die genau so heissen. Ein
 * unbekanntes Theme, ein Seitenbaukasten oder ein Block-Template liess
 * Kopf, Navigation, Fusszeile und die Seitenränder stehen.
 *
 * Darum hier nicht nach Namen, sondern nach Stellung: Alles, was zwischen
 * <body> und unserem Container liegt, wird ausgeblendet, und jeder Rahmen
 * darüber verliert Breite, Rand und Innenmaß. Dieselbe Fassung wie in der
 * Abrechnung (templates/app.php dort) — zwei eigenständige Plugins, und
 * wer zwischen ihnen wechselt, soll keinen Bruch sehen.
 */
(function () {
  function behalten(n) {
    return n.id === 'wpadminbar' || n.tagName === 'SCRIPT' || n.tagName === 'STYLE'
        || n.tagName === 'LINK' || n.tagName === 'NOSCRIPT' || n.tagName === 'TEMPLATE';
  }
  function aufraeumen() {
    var root = document.getElementById('s-root');
    if (!root) return;

    /* Die Klasse hier selbst setzen und sich NICHT darauf verlassen, dass
       body_class() sie mitgebracht hat: Jene Erkennung sucht den Shortcode
       im Inhalt der Seite — bei einem Seitenbaukasten (Elementor, Divi,
       WPBakery), in einem Block oder in einem Template-Teil steht er dort
       aber nicht. Dass diese Datei überhaupt läuft, ist der verlässliche
       Beweis, dass der Shortcode gerade ausgegeben wird. */
    document.body.classList.add('lsv07s-vollbild');
    document.documentElement.classList.add('lsv07s-vollbild');

    /* Die graue WordPress-Leiste gehört nicht in eine eigenständige
       Anwendung. Der serverseitige Filter dafür greift nur, wenn
       WordPress den Shortcode im Seiteninhalt findet. */
    var leiste = document.getElementById('wpadminbar');
    if (leiste) {
      leiste.style.setProperty('display', 'none', 'important');
      document.documentElement.style.setProperty('margin-top', '0', 'important');
    }
    document.body.classList.remove('admin-bar');

    /* Heraus aus dem Theme: unser Container wird direktes Kind von <body>.
       Das ist nicht Kosmetik — setzt ein Vorfahre `transform`, `filter`
       oder `perspective` (klebende Kopfleisten, Animationen,
       will-change), dann bezieht sich `position:fixed` auf DIESEN Rahmen
       statt auf das Fenster: Die Kopfleiste klebt an der falschen Stelle
       und der Dialog deckt nicht mehr die ganze Seite ab. Erst ab
       DOMContentLoaded, damit nicht umgehängt wird, während der Browser
       noch Kinder in den Container einliest. */
    if (document.readyState !== 'loading' && root.parentElement !== document.body) {
      document.body.appendChild(root);
    }

    /* Auf JEDER Ebene zwischen <body> und uns die Geschwister ausblenden.
       Kopf, Menü und Fuss des Themes liegen fast nie direkt im <body>,
       sondern mit uns zusammen in einem Rahmen wie <div id="page">. Wer
       nur die Kinder von <body> ausblendet, lässt genau sie stehen. Mit
       !important, weil Theme-Regeln sonst gewinnen können. */
    var el = root;
    while (el && el.parentElement && el.parentElement !== document.body) {
      var eltern = el.parentElement;
      for (var i = 0; i < eltern.children.length; i++) {
        var g = eltern.children[i];
        if (g !== el && !behalten(g)) g.style.setProperty('display', 'none', 'important');
      }
      /* Der Rahmen selbst darf keine Breite, keinen Rand und kein
         Innenmaß mehr vorgeben — sonst bleiben die Seitenränder sichtbar. */
      eltern.style.setProperty('max-width', 'none', 'important');
      eltern.style.setProperty('width', '100%', 'important');
      eltern.style.setProperty('margin', '0', 'important');
      eltern.style.setProperty('padding', '0', 'important');
      eltern.style.setProperty('background', 'transparent', 'important');
      el = eltern;
    }
    if (el && el.parentElement === document.body) {
      for (var j = 0; j < document.body.children.length; j++) {
        var k = document.body.children[j];
        if (k !== el && !behalten(k)) k.style.setProperty('display', 'none', 'important');
      }
    }
  }

  /* Dreimal: sofort, damit der Theme-Kopf gar nicht erst aufblitzt — und
     noch einmal, wenn das Dokument steht, denn beim ersten Durchgang gibt
     es alles NACH dem Shortcode noch nicht, also auch die Fusszeile
     nicht. */
  aufraeumen();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', aufraeumen);
  }
  window.addEventListener('load', aufraeumen);
  window.lsv07sAufraeumen = aufraeumen;
})();
</script>
