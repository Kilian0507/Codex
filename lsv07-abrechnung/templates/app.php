<?php
/**
 * Die gesamte Oberfläche der Abrechnung.
 *
 * Aufgebaut als eine Seite mit Bereichen, die ein- und ausgeblendet werden —
 * so bleibt die Bedienung auf dem Handy flüssig und es gibt keine Ladezeiten
 * zwischen den Ansichten. Welche Bereiche jemand sieht, entscheidet die
 * Rollenkarte; maßgeblich bleibt aber immer die Prüfung im Endpunkt.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$z     = LSV07A_Access::karte();
$tabs  = $z['tabs'];
$jahr  = (int) date( 'Y' );
?>
<div id="a-root">

 <!-- Kopfleiste ────────────────────────────────────────────────────── -->
 <header id="a-top">
  <div class="a-top-in">
   <div class="a-marke">Abrechnung</div>

   <nav id="a-nav" aria-label="Bereiche">
    <?php if ( $tabs['eigene'] ) : ?>
     <button class="a-nb on" data-ziel="eigene">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h12l4 4v12H4z"/><path d="M14 4v5h5"/><path d="M8 13h8M8 17h5"/></svg>
      <span>Meine Abrechnung</span></button>
    <?php endif; ?>
    <?php if ( $tabs['pruefung'] ) : ?>
     <button class="a-nb" data-ziel="pruefung">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
      <span>Prüfung</span><span class="a-badge" id="a-badge-pruef" hidden>0</span></button>
    <?php endif; ?>
    <?php if ( $tabs['kasse'] ) : ?>
     <button class="a-nb" data-ziel="kasse">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 11h20"/></svg>
      <span>Kasse</span></button>
    <?php endif; ?>
    <?php if ( $tabs['statistik'] ) : ?>
     <button class="a-nb" data-ziel="statistik">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
      <span>Statistik</span></button>
    <?php endif; ?>
    <?php if ( $tabs['zahlungsdaten'] ) : ?>
     <button class="a-nb" data-ziel="zahlungsdaten">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg>
      <span>Zahlungsdaten</span></button>
    <?php endif; ?>
    <?php if ( $tabs['verwaltung'] ) : ?>
     <button class="a-nb" data-ziel="verwaltung">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
      <span>Verwaltung</span></button>
    <?php endif; ?>
   </nav>

   <div class="a-top-rechts">
    <span class="a-wer" title="<?php echo esc_attr( implode( ', ', $z['rollen'] ) ); ?>">
     <?php echo esc_html( $z['name'] ); ?></span>
    <button id="a-menue" aria-label="Menü" aria-expanded="false">
     <span></span><span></span><span></span>
    </button>
   </div>
  </div>
 </header>
 <div id="a-nav-schatten" hidden></div>

 <main id="a-body">

  <!-- ══ MEINE ABRECHNUNG ══════════════════════════════════════════ -->
  <?php if ( $tabs['eigene'] ) : ?>
  <section class="a-seite on" id="s-eigene">

   <div class="a-kopf">
    <div>
     <h1>Meine Abrechnung</h1>
     <p class="a-sub" id="e-untertitel">Quartalsweise — wählen Sie oben das Quartal.</p>
    </div>
    <div class="a-kopf-ctl">
     <select id="e-quartal" class="a-ctl" aria-label="Quartal">
      <option value="Q1">1. Quartal (Jan–Mär)</option>
      <option value="Q2">2. Quartal (Apr–Jun)</option>
      <option value="Q3">3. Quartal (Jul–Sep)</option>
      <option value="Q4">4. Quartal (Okt–Dez)</option>
     </select>
     <input type="number" id="e-jahr" class="a-ctl a-ctl-zahl" value="<?php echo esc_attr( $jahr ); ?>"
            min="2020" max="2100" aria-label="Jahr">
    </div>
   </div>

   <div id="e-hinweis"></div>

   <!-- Statusband -->
   <div class="a-band" id="e-band" hidden>
    <div class="a-band-txt"><strong id="e-band-titel"></strong><span id="e-band-text"></span></div>
    <div class="a-band-akt" id="e-band-akt"></div>
   </div>

   <!-- Summenkarten -->
   <div class="a-kacheln" id="e-summen"></div>

   <!-- Die fünf Bereiche -->
   <div id="e-gruppen"></div>

   <div class="a-fuss" id="e-fuss" hidden>
    <div class="a-gesamt">
     <span>Gesamt</span><strong id="e-gesamt">0,00 €</strong>
    </div>
    <button id="e-einreichen" class="a-btn a-btn-p a-btn-gross">Zur Genehmigung einreichen</button>
   </div>

   <details class="a-verlauf">
    <summary>Frühere Abrechnungen</summary>
    <div id="e-liste"><div class="a-laden">Wird geladen…</div></div>
   </details>
  </section>
  <?php endif; ?>

  <!-- ══ PRÜFUNG (WART) ════════════════════════════════════════════ -->
  <?php if ( $tabs['pruefung'] ) : ?>
  <section class="a-seite" id="s-pruefung">
   <div class="a-kopf">
    <div><h1>Prüfung</h1>
     <p class="a-sub">Alle Abrechnungen des Quartals — auch die, die noch nicht eingereicht sind.</p></div>
    <div class="a-kopf-ctl">
     <select id="p-quartal" class="a-ctl" aria-label="Quartal">
      <option value="Q1">1. Quartal</option><option value="Q2">2. Quartal</option>
      <option value="Q3">3. Quartal</option><option value="Q4">4. Quartal</option>
     </select>
     <input type="number" id="p-jahr" class="a-ctl a-ctl-zahl" value="<?php echo esc_attr( $jahr ); ?>"
            min="2020" max="2100" aria-label="Jahr">
     <select id="p-status" class="a-ctl" aria-label="Status">
      <option value="">Alle</option>
      <option value="eingereicht">Nur eingereichte</option>
      <option value="entwurf">Nur Entwürfe</option>
      <option value="zurueck">Nur zurückgegebene</option>
      <option value="genehmigt">Nur genehmigte</option>
      <option value="bezahlt">Nur bezahlte</option>
      <option value="offen">Noch nichts erfasst</option>
     </select>
    </div>
   </div>
   <div id="p-liste"><div class="a-laden">Wird geladen…</div></div>
  </section>
  <?php endif; ?>

  <!-- ══ KASSE ═════════════════════════════════════════════════════ -->
  <?php if ( $tabs['kasse'] ) : ?>
  <section class="a-seite" id="s-kasse">
   <div class="a-kopf">
    <div><h1>Kasse</h1>
     <p class="a-sub">Genehmigte Abrechnungen auszahlen. Vorher Genehmigtes erscheint hier nicht.</p></div>
    <div class="a-kopf-ctl">
     <input type="number" id="k-jahr" class="a-ctl a-ctl-zahl" value="<?php echo esc_attr( $jahr ); ?>"
            min="2020" max="2100" aria-label="Jahr">
     <select id="k-quartal" class="a-ctl" aria-label="Quartal">
      <option value="">Ganzes Jahr</option>
      <option value="Q1">1. Quartal</option><option value="Q2">2. Quartal</option>
      <option value="Q3">3. Quartal</option><option value="Q4">4. Quartal</option>
     </select>
     <select id="k-status" class="a-ctl" aria-label="Status">
      <option value="">Genehmigt und bezahlt</option>
      <option value="genehmigt">Nur offene</option>
      <option value="bezahlt">Nur bezahlte</option>
     </select>
    </div>
   </div>
   <div class="a-kacheln" id="k-summen"></div>
   <div id="k-liste"><div class="a-laden">Wird geladen…</div></div>
  </section>
  <?php endif; ?>

  <!-- ══ STATISTIK ═════════════════════════════════════════════════ -->
  <?php if ( $tabs['statistik'] ) : ?>
  <section class="a-seite" id="s-statistik">
   <div class="a-kopf">
    <div><h1>Statistik</h1><p class="a-sub" id="st-sub"></p></div>
    <div class="a-kopf-ctl">
     <input type="number" id="st-jahr" class="a-ctl a-ctl-zahl" value="<?php echo esc_attr( $jahr ); ?>"
            min="2020" max="2100" aria-label="Jahr">
     <div class="a-schalter" id="st-umschalter" hidden>
      <button class="on" data-st="eigene">Meine</button>
      <button data-st="alle">Alle</button>
     </div>
    </div>
   </div>
   <div id="st-inhalt"><div class="a-laden">Wird geladen…</div></div>
  </section>
  <?php endif; ?>

  <!-- ══ ZAHLUNGSDATEN ═════════════════════════════════════════════ -->
  <?php if ( $tabs['zahlungsdaten'] ) : ?>
  <section class="a-seite" id="s-zahlungsdaten">
   <div class="a-kopf"><div><h1>Zahlungsdaten</h1>
    <p class="a-sub">Hierhin überweist die Kasse. Ohne Kontoinhaber und IBAN lässt sich nichts einreichen.</p></div></div>

   <div class="a-karte a-schmal">
    <div class="a-karte-bd">
     <div class="a-feld">
      <label for="z-inhaber">Kontoinhaber</label>
      <input type="text" id="z-inhaber" class="a-ctl" autocomplete="name" placeholder="Vor- und Nachname">
     </div>
     <div class="a-feld">
      <label for="z-iban">IBAN</label>
      <input type="text" id="z-iban" class="a-ctl" autocomplete="off" spellcheck="false"
             placeholder="DE00 0000 0000 0000 0000 00">
     </div>
     <div class="a-feld">
      <label for="z-bic">BIC <span class="a-opt">optional</span></label>
      <input type="text" id="z-bic" class="a-ctl" autocomplete="off" spellcheck="false">
     </div>
     <div class="a-feld">
      <label for="z-strasse">Straße und Hausnummer <span class="a-opt">optional</span></label>
      <input type="text" id="z-strasse" class="a-ctl" autocomplete="street-address">
     </div>
     <div class="a-zwei">
      <div class="a-feld">
       <label for="z-plz">PLZ <span class="a-opt">optional</span></label>
       <input type="text" id="z-plz" class="a-ctl" inputmode="numeric" autocomplete="postal-code">
      </div>
      <div class="a-feld">
       <label for="z-ort">Ort <span class="a-opt">optional</span></label>
       <input type="text" id="z-ort" class="a-ctl" autocomplete="address-level2">
      </div>
     </div>
     <div class="a-hinweis" id="z-konditionen"></div>
    </div>
    <div class="a-karte-ft">
     <button id="z-speichern" class="a-btn a-btn-p">Speichern</button>
    </div>
   </div>
  </section>
  <?php endif; ?>

  <!-- ══ VERWALTUNG ════════════════════════════════════════════════ -->
  <?php if ( $tabs['verwaltung'] ) : ?>
  <section class="a-seite" id="s-verwaltung">
   <div class="a-kopf"><div><h1>Verwaltung</h1>
    <p class="a-sub">Konten und Rollen, Sätze, Pauschalen, Saisons und Trainingszeiten.</p></div></div>

   <div class="a-reiter" id="v-reiter">
    <button class="on" data-v="konten">Konten &amp; Rollen</button>
    <button data-v="saetze">Sätze</button>
    <button data-v="pauschalen">Pauschalen</button>
    <button data-v="saisons">Saisons</button>
    <button data-v="zeiten">Trainingszeiten</button>
    <button data-v="protokoll">Protokoll</button>
   </div>

   <div class="a-vteil on" id="v-konten"><div class="a-laden">Wird geladen…</div></div>
   <div class="a-vteil" id="v-saetze"></div>
   <div class="a-vteil" id="v-pauschalen"></div>
   <div class="a-vteil" id="v-saisons"></div>
   <div class="a-vteil" id="v-zeiten"></div>
   <div class="a-vteil" id="v-protokoll"></div>
  </section>
  <?php endif; ?>

 </main>

 <!-- ══ DIALOGE ═══════════════════════════════════════════════════ -->
 <div class="a-ov" id="d-posten">
  <div class="a-dlg" role="dialog" aria-modal="true" aria-labelledby="d-posten-titel">
   <div class="a-dlg-hd"><span id="d-posten-titel">Posten</span>
    <button class="a-x" data-zu aria-label="Schließen">&times;</button></div>
   <div class="a-dlg-bd" id="d-posten-bd"></div>
   <div class="a-dlg-ft">
    <button class="a-btn" data-zu>Abbrechen</button>
    <button class="a-btn a-btn-p" id="d-posten-ok">Speichern</button>
   </div>
  </div>
 </div>

 <div class="a-ov" id="d-uebernehmen">
  <div class="a-dlg a-dlg-breit" role="dialog" aria-modal="true" aria-labelledby="d-ueb-titel">
   <div class="a-dlg-hd"><span id="d-ueb-titel">Trainings übernehmen</span>
    <button class="a-x" data-zu aria-label="Schließen">&times;</button></div>
   <div class="a-dlg-bd" id="d-ueb-bd"></div>
   <div class="a-dlg-ft">
    <button class="a-btn" data-zu>Abbrechen</button>
    <button class="a-btn a-btn-p" id="d-ueb-ok">Übernehmen</button>
   </div>
  </div>
 </div>

 <div class="a-ov" id="d-detail">
  <div class="a-dlg a-dlg-breit" role="dialog" aria-modal="true" aria-labelledby="d-detail-titel">
   <div class="a-dlg-hd"><span id="d-detail-titel">Abrechnung</span>
    <button class="a-x" data-zu aria-label="Schließen">&times;</button></div>
   <div class="a-dlg-bd" id="d-detail-bd"></div>
   <div class="a-dlg-ft" id="d-detail-ft"></div>
  </div>
 </div>

 <div class="a-ov" id="d-frage">
  <div class="a-dlg" role="dialog" aria-modal="true" aria-labelledby="d-frage-titel">
   <div class="a-dlg-hd"><span id="d-frage-titel">Bitte bestätigen</span>
    <button class="a-x" data-zu aria-label="Schließen">&times;</button></div>
   <div class="a-dlg-bd" id="d-frage-bd"></div>
   <div class="a-dlg-ft">
    <button class="a-btn" data-zu>Abbrechen</button>
    <button class="a-btn a-btn-p" id="d-frage-ok">Ja</button>
   </div>
  </div>
 </div>

 <div id="a-toast" role="status" aria-live="polite"></div>
 <div id="a-ladebalken"></div>
</div>

<script>
/* Vollbild: Alles, was zwischen <body> und unserem Container liegt
   (Theme-Kopf, -Fuß, Seitenleisten), wird ausgeblendet. Das passiert
   sofort beim Einlesen, damit nichts kurz aufblitzt. */
(function () {
  var root = document.getElementById('a-root');
  if (!root || !document.body.classList.contains('lsv07a-fullscreen')) return;
  document.documentElement.classList.add('lsv07a-fullscreen');
  function behalten(n) {
    return n.id === 'a-root' || n.id === 'wpadminbar' || n.tagName === 'SCRIPT'
        || n.tagName === 'STYLE' || n.tagName === 'LINK' || n.tagName === 'NOSCRIPT';
  }
  var el = root;
  while (el && el.parentElement && el.parentElement !== document.body) el = el.parentElement;
  Array.prototype.forEach.call(document.body.children, function (k) {
    if (k !== el && !behalten(k)) k.style.display = 'none';
  });
  if (el !== root) { el.style.cssText = 'margin:0;padding:0;max-width:none;width:100%'; }
  document.body.appendChild(root);
})();
</script>
