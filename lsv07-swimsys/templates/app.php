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
<div id="s-root">

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
