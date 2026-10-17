<?php
/**
 * Die gesamte Oberfläche von SwimView.
 *
 * Drei Zustände in einem Dokument: das Anmeldefeld, die Anwendung für
 * angemeldete Schwimmer, das Trainerportal. Welcher davon zu sehen ist,
 * entscheidet das Skript anhand dessen, was LSV07S mitbringt — serverseitig
 * ausgegeben wird NICHTS, was nur für Angemeldete bestimmt ist.
 *
 * Aufgebaut als eine Seite mit Bereichen, die ein- und ausgeblendet werden:
 * So bleibt die Bedienung auf dem Telefon flüssig und es gibt keine
 * Ladezeiten zwischen den Ansichten.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$logo    = LSV07S_URL . 'assets/img/swimview-logo.png';
$marke   = LSV07S_URL . 'assets/img/swimview-marke.png';
$ich     = LSV07S_Login::schwimmer();
$trainer = LSV07S_Trainer::hat_zugang();
?>
<div id="s-root" class="<?php echo $ich ? 's-ist-drin' : 's-ist-draussen'; ?>">

 <div id="s-ladebalken" aria-hidden="true"></div>
 <div id="s-toast" role="status" aria-live="polite"></div>

 <!-- ══ Anmeldung ═══════════════════════════════════════════════════════
      Steht auch ohne Skript vollständig da. Abgeschickt wird sie per
      Skript; ohne Skript führt der Knopf nirgendwo hin, aber das Feld ist
      wenigstens nicht unsichtbar.                                       -->
 <section id="s-tor" class="s-tor"<?php echo $ich ? ' hidden' : ''; ?>>
  <div class="s-tor-karte">
   <img class="s-tor-logo" src="<?php echo esc_url( $logo ); ?>"
        alt="SwimView" width="220" height="180">

   <h1 class="s-tor-titel">Deine Daten beim LSV07</h1>
   <p class="s-tor-text">
    Melde dich mit deiner DSV-ID und deinem Geburtsdatum an. Deine DSV-ID
    steht in jedem Meldeergebnis — im Zweifel fragst du deinen Trainer.
   </p>

   <form id="s-anmeldung" class="s-form" autocomplete="off" novalidate>
    <label class="s-lbl" for="s-dsv">DSV-ID</label>
    <input type="text" id="s-dsv" class="s-ctl" inputmode="numeric"
           autocomplete="off" spellcheck="false"
           placeholder="z. B. 1234567" aria-describedby="s-dsv-hilfe">
    <div class="s-hilfe" id="s-dsv-hilfe">Leerzeichen und Bindestriche sind egal.</div>

    <label class="s-lbl" for="s-geb">Geburtsdatum</label>
    <input type="date" id="s-geb" class="s-ctl" autocomplete="off">

    <button type="submit" class="s-btn s-btn-p s-btn-gross" id="s-anmelden">Anmelden</button>

    <div class="s-tor-fehler" id="s-tor-fehler" role="alert" hidden></div>
   </form>

   <?php if ( $trainer ) : ?>
    <div class="s-tor-trainer">
     Du bist als Trainerin oder Trainer angemeldet.
     <button type="button" class="s-btn s-btn-b s-btn-klein" id="s-zum-portal">Zum Trainerportal</button>
    </div>
   <?php endif; ?>

   <p class="s-tor-fuss">
    Du siehst hier nur deine eigenen Daten, und davon nur das, was deine
    Trainerin oder dein Trainer freigegeben hat.
   </p>
  </div>
 </section>

 <!-- ══ Anwendung ═══════════════════════════════════════════════════════ -->
 <div id="s-app"<?php echo $ich ? '' : ' hidden'; ?>>

  <header id="s-top">
   <div class="s-top-in">
    <button type="button" class="s-marke" id="s-heim" aria-label="Zur Startseite">
     <img src="<?php echo esc_url( $marke ); ?>" alt="" width="34" height="21" aria-hidden="true">
     <span>SwimView</span>
    </button>

    <nav id="s-nav" aria-label="Bereiche"></nav>

    <div class="s-top-rechts">
     <span class="s-wer" id="s-wer"></span>
     <button type="button" id="s-abmelden" class="s-btn s-btn-klein s-btn-nein">Abmelden</button>
     <button type="button" id="s-menue" aria-label="Menü" aria-expanded="false">
      <span></span><span></span><span></span>
     </button>
    </div>
   </div>
  </header>

  <div id="s-nav-schatten" hidden></div>

  <main id="s-body">

   <!-- Dashboard -->
   <section class="s-seite on" id="s-s-start">
    <div class="s-gruss">
     <h1 id="s-gruss-titel">Hallo!</h1>
     <p class="s-gruss-sub" id="s-gruss-sub"></p>
    </div>
    <div class="s-kacheln" id="s-start-kacheln"></div>
    <div id="s-start-leer" hidden>
     <div class="s-karte s-schmal">
      <div class="s-karte-bd">
       <p><strong>Für dich ist noch nichts freigegeben.</strong></p>
       <p class="s-muted">Deine Trainerin oder dein Trainer entscheidet, welche Daten
        du hier sehen kannst. Frag einfach nach — es ist mit zwei Klicks erledigt.</p>
      </div>
     </div>
    </div>
   </section>

   <!-- Anwesenheiten -->
   <section class="s-seite" id="s-s-anwesenheit">
    <div class="s-kopf">
     <div><h1>Anwesenheiten</h1>
      <p class="s-sub">Deine Termine aus dem Mannschaftsbuch.</p></div>
     <div class="s-kopf-ctl">
      <label class="s-lbl-zeile" for="s-anw-von">von</label>
      <input type="date" id="s-anw-von" class="s-ctl s-ctl-datum">
      <label class="s-lbl-zeile" for="s-anw-bis">bis</label>
      <input type="date" id="s-anw-bis" class="s-ctl s-ctl-datum">
      <button type="button" class="s-btn s-btn-b s-btn-klein" data-neu="anwesenheit">Zeigen</button>
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="anwesenheit">Als PDF</button>
     </div>
    </div>
    <div id="s-anw-zahlen" class="s-kacheln s-kacheln-eng"></div>
    <div id="s-anw-liste"></div>
   </section>

   <!-- Vermerke -->
   <section class="s-seite" id="s-s-vermerke">
    <div class="s-kopf">
     <div><h1>Vermerke der Mannschaft</h1>
      <p class="s-sub">Was für die ganze Mannschaft notiert wurde — Trainingsziele und Hinweise.</p></div>
     <div class="s-kopf-ctl">
      <label class="s-lbl-zeile" for="s-ver-von">von</label>
      <input type="date" id="s-ver-von" class="s-ctl s-ctl-datum">
      <label class="s-lbl-zeile" for="s-ver-bis">bis</label>
      <input type="date" id="s-ver-bis" class="s-ctl s-ctl-datum">
      <button type="button" class="s-btn s-btn-b s-btn-klein" data-neu="vermerke">Zeigen</button>
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="vermerke">Als PDF</button>
     </div>
    </div>
    <div id="s-ver-liste"></div>
   </section>

   <!-- Aufgaben -->
   <section class="s-seite" id="s-s-aufgaben">
    <div class="s-kopf">
     <div><h1>Aufgaben</h1>
      <p class="s-sub">Deine Aufgaben und die der ganzen Mannschaft.</p></div>
     <div class="s-kopf-ctl">
      <label class="s-lbl-zeile" for="s-auf-von">von</label>
      <input type="date" id="s-auf-von" class="s-ctl s-ctl-datum">
      <label class="s-lbl-zeile" for="s-auf-bis">bis</label>
      <input type="date" id="s-auf-bis" class="s-ctl s-ctl-datum">
      <button type="button" class="s-btn s-btn-b s-btn-klein" data-neu="aufgaben">Zeigen</button>
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="aufgaben">Als PDF</button>
     </div>
    </div>
    <div id="s-auf-liste"></div>
   </section>

   <!-- Dokumentation -->
   <section class="s-seite" id="s-s-dokumentation">
    <div class="s-kopf">
     <div><h1>Dokumentation</h1>
      <p class="s-sub">Was im Training gemacht wurde und was als Nächstes dran ist.</p></div>
     <div class="s-kopf-ctl">
      <label class="s-lbl-zeile" for="s-dok-von">von</label>
      <input type="date" id="s-dok-von" class="s-ctl s-ctl-datum">
      <label class="s-lbl-zeile" for="s-dok-bis">bis</label>
      <input type="date" id="s-dok-bis" class="s-ctl s-ctl-datum">
      <button type="button" class="s-btn s-btn-b s-btn-klein" data-neu="dokumentation">Zeigen</button>
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="dokumentation">Als PDF</button>
     </div>
    </div>
    <div id="s-dok-liste"></div>
   </section>

   <!-- Wettkämpfe -->
   <section class="s-seite" id="s-s-wettkaempfe">
    <div class="s-kopf">
     <div><h1>Wettkämpfe</h1>
      <p class="s-sub">Was ansteht, mit Ausschreibung, Meldeergebnis, Protokoll und Links.</p></div>
     <div class="s-kopf-ctl">
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="wettkaempfe">Als PDF</button>
     </div>
    </div>
    <div id="s-wk-liste"></div>
   </section>

   <!-- Bestzeiten -->
   <section class="s-seite" id="s-s-bestzeiten">
    <div class="s-kopf">
     <div><h1>Bestzeiten</h1>
      <p class="s-sub">Deine schnellsten Zeiten, wie sie im Verein geführt werden.</p></div>
     <div class="s-kopf-ctl">
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="bestzeiten">Als PDF</button>
     </div>
    </div>
    <div id="s-bz-liste"></div>
   </section>

   <!-- Reflexionsbogen -->
   <section class="s-seite" id="s-s-reflexion">
    <div class="s-kopf">
     <div><h1>Reflexionsbogen</h1>
      <p class="s-sub" id="s-refl-sub">Nimm dir Zeit — du kannst zwischendurch speichern.</p></div>
     <div class="s-kopf-ctl">
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="reflexion">Als PDF</button>
     </div>
    </div>
    <div id="s-refl-inhalt"></div>
   </section>

   <!-- Trainingsplan -->
   <section class="s-seite" id="s-s-trainingsplan">
    <div class="s-kopf">
     <div><h1>Trainingsplan</h1>
      <p class="s-sub">Die Pläne, die für dich freigegeben sind.</p></div>
     <div class="s-kopf-ctl">
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="trainingsplan">Alle als PDF</button>
     </div>
    </div>
    <div id="s-tp-liste"></div>
   </section>

   <!-- Nachrichten -->
   <section class="s-seite" id="s-s-nachrichten">
    <div class="s-kopf">
     <div><h1>Nachrichten</h1>
      <p class="s-sub">Was dein Trainerteam dir und deiner Mannschaft schreibt.</p></div>
     <div class="s-kopf-ctl">
      <button type="button" class="s-btn s-btn-klein s-pdf" data-bereich="nachrichten">Als PDF</button>
     </div>
    </div>
    <div id="s-na-liste"></div>
   </section>

  </main>
 </div>

 <!-- ══ Trainerportal ═══════════════════════════════════════════════════
      Nur für angemeldete WordPress-Konten mit Trainerzugang. Ob jemand das
      sein darf, entscheidet der Server — hier steht nur die Hülle.      -->
 <?php if ( $trainer ) : ?>
 <div id="s-portal" hidden>
  <header id="s-ptop">
   <div class="s-top-in">
    <button type="button" class="s-marke" id="s-portal-heim" aria-label="Übersicht">
     <img src="<?php echo esc_url( $marke ); ?>" alt="" width="34" height="21" aria-hidden="true">
     <span>SwimView · Trainerportal</span>
    </button>
    <div class="s-top-rechts">
     <select id="s-p-mann" class="s-ctl s-ctl-dunkel" aria-label="Mannschaft"></select>
     <button type="button" class="s-btn s-btn-klein s-btn-b" id="s-portal-zurueck">Schwimmeransicht</button>
    </div>
   </div>
  </header>

  <main id="s-pbody">
   <div id="s-p-hinweis" class="s-notiz s-notiz-warn" hidden></div>

   <section class="s-karte">
    <div class="s-karte-hd"><h2>Was die Mannschaft sehen darf</h2>
     <span class="s-muted" id="s-p-stand"></span></div>
    <div class="s-karte-bd">
     <p class="s-muted" style="margin-top:0">
      Die Einstellung gilt für die Mannschaft, nicht für dich persönlich: Wer
      dieselbe Mannschaft betreut, sieht und ändert genau dieselben Häkchen.
     </p>
     <div id="s-p-bereiche" class="s-schalter"></div>
    </div>
   </section>

   <section class="s-karte" id="s-p-plan-karte">
    <div class="s-karte-hd"><h2>Trainingspläne freigeben</h2></div>
    <div class="s-karte-bd">
     <p class="s-muted" style="margin-top:0">
      Hier stehen deine eigenen Pläne. Freigegeben heisst: Die Kinder dieser
      Mannschaft können den Plan lesen und als PDF mitnehmen.
     </p>
     <div id="s-p-plaene" class="s-schalter"></div>
    </div>
   </section>

   <section class="s-karte">
    <div class="s-karte-hd"><h2>Nachricht schreiben</h2></div>
    <div class="s-karte-bd">
     <form id="s-p-form" class="s-form" enctype="multipart/form-data">
      <label class="s-lbl" for="s-p-an">An</label>
      <select id="s-p-an" class="s-ctl">
       <option value="0">Die ganze Mannschaft</option>
      </select>

      <label class="s-lbl" for="s-p-betreff">Betreff</label>
      <input type="text" id="s-p-betreff" class="s-ctl" maxlength="160"
             placeholder="Worum geht es?">

      <label class="s-lbl" for="s-p-text">Nachricht</label>
      <textarea id="s-p-text" class="s-ctl" rows="5" maxlength="5000"
                placeholder="Was die Kinder wissen sollen."></textarea>

      <label class="s-lbl" for="s-p-datei">Dateien (PDF, JPG, PNG, WEBP)</label>
      <input type="file" id="s-p-datei" class="s-ctl" multiple
             accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
      <div class="s-hilfe" id="s-p-datei-hilfe"></div>

      <button type="submit" class="s-btn s-btn-ok" id="s-p-senden">Abschicken</button>
     </form>
    </div>
   </section>

   <section class="s-karte">
    <div class="s-karte-hd"><h2>Verschickt</h2></div>
    <div class="s-karte-bd" id="s-p-nachrichten"></div>
   </section>
  </main>
 </div>
 <?php endif; ?>

</div>
