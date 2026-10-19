<?php
/**
 * Die gesamte Oberfläche von SwimPlan.
 *
 * Aufgebaut als eine Seite mit Bereichen, die ein- und ausgeblendet werden:
 * So bleibt die Bedienung auf dem Telefon flüssig und es gibt keine
 * Ladezeiten zwischen den Ansichten.
 *
 * Serverseitig ausgegeben wird NICHTS, was nur für Berechtigte bestimmt ist
 * — keine Namen, keine Mannschaften, keine Pläne. Die Listen füllt das
 * Skript über die Endpunkte, und jeder Endpunkt prüft die Berechtigung
 * selbst. Dass ein Bereich im Dokument steht, heisst also nicht, dass
 * darin etwas zu holen ist.
 *
 * Die Bühne (Vollbild, Splitscreen, freie Größe) liegt als eigene Ebene
 * über allem. Sie hat ihre eigene Bedienleiste, weil sie am Beckenrand
 * benutzt wird: grosse Knöpfe, wenige davon, nasse Finger.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$logo   = LSV07P_URL . 'assets/img/swimplan-logo.svg';
$marke  = LSV07P_URL . 'assets/img/swimplan-marke.svg';
$zugang = LSV07P_Rollen::hat_zugang();
$drin   = is_user_logged_in();
$hier   = get_permalink();
?>
<div id="p-root" class="<?php echo $zugang ? 'p-ist-drin' : 'p-ist-draussen'; ?>">

 <div id="p-ladebalken" aria-hidden="true"></div>
 <div id="p-toast" role="status" aria-live="polite"></div>

 <!-- ══ Tor ═════════════════════════════════════════════════════════════
      SwimPlan hat keine eigene Anmeldung: Wer hier etwas sehen will, ist
      bei WordPress angemeldet. Zwei Fälle sind zu unterscheiden, und sie
      brauchen zwei verschiedene Sätze — „melde dich an" hilft dem nicht,
      der angemeldet ist und bloss nicht eingetragen.                    -->
 <section id="p-tor" class="p-tor"<?php echo $zugang ? ' hidden' : ''; ?>>
  <div class="p-tor-karte">
   <img class="p-tor-logo" src="<?php echo esc_url( $logo ); ?>"
        alt="SwimPlan" width="200" height="164">

   <?php if ( ! $drin ) : ?>
    <h1 class="p-tor-titel">Trainingsplanung des LSV07</h1>
    <p class="p-tor-text">
     SwimPlan läuft über dein Vereinskonto. Melde dich bei der Vereinsseite
     an, dann bist du auch hier angemeldet — ein zweites Passwort gibt es
     nicht.
    </p>
    <a class="p-btn p-btn-p p-btn-gross"
       href="<?php echo esc_url( wp_login_url( $hier ) ); ?>">Anmelden</a>
   <?php else : ?>
    <h1 class="p-tor-titel">Noch nicht freigeschaltet</h1>
    <p class="p-tor-text">
     Du bist angemeldet, aber dein Konto ist für SwimPlan nicht eingetragen.
     Das macht die Verwaltung — sag ihr Bescheid, dann bist du beim nächsten
     Aufruf hier drin.
    </p>
    <p class="p-tor-text p-muted">
     Das ist Absicht und kein Fehler: Zugriff auf Trainingsdaten bekommt
     nur, wer dafür benannt wurde. Auch ein Konto mit allen Rechten in
     WordPress bekommt ihn nicht von selbst.
    </p>
    <a class="p-btn p-btn-nein p-btn-klein"
       href="<?php echo esc_url( wp_logout_url( $hier ) ); ?>">Abmelden</a>
   <?php endif; ?>

   <p class="p-tor-fuss">
    Trainingspläne, Mannschaften und Zeiten sind nur für Berechtigte
    sichtbar. Diese Seite steht in keinem Suchindex.
   </p>
  </div>
 </section>

 <!-- ══ Anwendung ═══════════════════════════════════════════════════════ -->
 <div id="p-app"<?php echo $zugang ? '' : ' hidden'; ?>>

  <header id="p-top">
   <div class="p-top-in">
    <button type="button" class="p-marke" id="p-heim" aria-label="Zur Startseite">
     <img src="<?php echo esc_url( $marke ); ?>" alt="" width="34" height="24" aria-hidden="true">
     <span>SwimPlan</span>
    </button>

    <nav id="p-nav" aria-label="Bereiche"></nav>

    <div class="p-top-rechts">
     <span class="p-wer" id="p-wer"></span>
     <button type="button" id="p-abmelden" class="p-btn p-btn-klein p-btn-nein">Abmelden</button>
     <button type="button" id="p-menue" aria-label="Menü" aria-expanded="false">
      <span></span><span></span><span></span>
     </button>
    </div>
   </div>
  </header>

  <div id="p-nav-schatten" hidden></div>

  <main id="p-body">

   <!-- ── Start ──────────────────────────────────────────────────────── -->
   <section class="p-seite on" id="p-s-start">
    <h1 class="p-gruss" id="p-gruss">Hallo</h1>
    <p class="p-gruss-sub" id="p-gruss-sub"></p>
    <div class="p-kacheln" id="p-start-kacheln"></div>
    <div id="p-start-inhalt"></div>
   </section>

   <!-- ── Pläne (Trainer) ────────────────────────────────────────────── -->
   <section class="p-seite" id="p-s-plaene">
    <div class="p-kopf">
     <div>
      <h2>Trainingspläne</h2>
      <div class="p-sub" id="p-plaene-sub"></div>
     </div>
     <div class="p-kopf-ctl">
      <input type="date" id="p-f-von" class="p-ctl p-ctl-datum" aria-label="von">
      <input type="date" id="p-f-bis" class="p-ctl p-ctl-datum" aria-label="bis">
      <select id="p-f-status" class="p-ctl p-schmal" aria-label="Zustand"></select>
      <button type="button" class="p-btn p-btn-klein" id="p-f-los">Anzeigen</button>
      <button type="button" class="p-btn p-btn-klein" id="p-buehne-auf">Bühne</button>
      <button type="button" class="p-btn p-btn-p p-btn-klein" id="p-plan-neu">Neue Einheit</button>
     </div>
    </div>
    <div class="p-eintraege" id="p-plan-liste"></div>
   </section>

   <!-- ── Eine Einheit ───────────────────────────────────────────────── -->
   <section class="p-seite" id="p-s-plan">
    <div class="p-kopf">
     <div>
      <h2 id="p-plan-name">Einheit</h2>
      <div class="p-sub" id="p-plan-sub"></div>
     </div>
     <div class="p-kopf-ctl" id="p-plan-ctl">
      <button type="button" class="p-btn p-btn-klein" id="p-plan-zurueck">Zurück</button>
     </div>
    </div>
    <div id="p-plan-inhalt"></div>
   </section>

   <!-- ── Bibliothek ─────────────────────────────────────────────────── -->
   <section class="p-seite" id="p-s-bibliothek">
    <div class="p-kopf">
     <div>
      <h2>Übungsbibliothek</h2>
      <div class="p-sub">
       Beim Einfügen werden die Sets <strong>kopiert</strong> — eine später
       geänderte Vorlage verändert keinen bestehenden Plan.
      </div>
     </div>
     <div class="p-kopf-ctl">
      <select id="p-b-kat" class="p-ctl p-schmal" aria-label="Kategorie"></select>
      <input type="search" id="p-b-suche" class="p-ctl p-schmal" placeholder="Suche"
             aria-label="Suche">
      <button type="button" class="p-btn p-btn-klein" id="p-b-los">Anzeigen</button>
      <button type="button" class="p-btn p-btn-p p-btn-klein" id="p-vorlage-neu">Neue Vorlage</button>
     </div>
    </div>
    <div class="p-eintraege" id="p-vorlage-liste"></div>
   </section>

   <!-- ── Leistungen (Trainer) ───────────────────────────────────────── -->
   <section class="p-seite" id="p-s-leistung">
    <div class="p-kopf">
     <div>
      <h2>Zeiten und Bestleistungen</h2>
      <div class="p-sub">
       Die Bestzeit wird aus den erfassten Zeiten ermittelt, nicht gepflegt.
      </div>
     </div>
     <div class="p-kopf-ctl">
      <select id="p-l-wer" class="p-ctl" aria-label="Schwimmerin oder Schwimmer"></select>
      <button type="button" class="p-btn p-btn-klein" id="p-rangliste">Rangliste</button>
      <button type="button" class="p-btn p-btn-p p-btn-klein" id="p-zeit-neu">Zeit erfassen</button>
     </div>
    </div>
    <div id="p-leistung-inhalt"></div>
   </section>

   <!-- ── Mannschaften ───────────────────────────────────────────────── -->
   <section class="p-seite" id="p-s-teams">
    <div class="p-kopf">
     <div>
      <h2>Mannschaften</h2>
      <div class="p-sub" id="p-teams-sub"></div>
     </div>
     <div class="p-kopf-ctl" id="p-teams-ctl"></div>
    </div>
    <div class="p-eintraege" id="p-team-liste"></div>
   </section>

   <!-- ── Konten (nur Verwaltung) ────────────────────────────────────── -->
   <section class="p-seite" id="p-s-konten">
    <div class="p-kopf">
     <div>
      <h2>Konten und Rollen</h2>
      <div class="p-sub">
       Ein WordPress-Konto bekommt erst Zugang, wenn es hier eingetragen
       ist. Wer ihn verliert, wird abgeschaltet und nicht gelöscht.
      </div>
     </div>
     <div class="p-kopf-ctl">
      <input type="search" id="p-k-suche" class="p-ctl" placeholder="Konto suchen"
             autocomplete="off" aria-label="Konto suchen">
     </div>
    </div>

    <div class="p-eintraege" id="p-kandidaten"></div>

    <div class="p-karte">
     <div class="p-karte-hd">Eingetragen</div>
     <div class="p-karte-bd" id="p-konten-liste"></div>
    </div>

    <div class="p-karte">
     <div class="p-karte-hd">Richtwert für die Dauerschätzung</div>
     <div class="p-karte-bd">
      <label class="p-lbl" for="p-cfg-richtwert">100 m Freistil im Training</label>
      <input type="text" id="p-cfg-richtwert" class="p-ctl p-schmal" placeholder="1:50">
      <div class="p-hilfe">
       Damit wird die Dauer von Sets geschätzt, bei denen kein Intervall
       eingetragen ist. Sets mit Intervall werden gezählt, nicht geschätzt.
      </div>
     </div>
     <div class="p-karte-ft">
      <button type="button" class="p-btn p-btn-p p-btn-klein" id="p-cfg-speichern">Speichern</button>
     </div>
    </div>

    <div class="p-karte">
     <div class="p-karte-hd">Was zuletzt passiert ist</div>
     <div class="p-karte-bd" id="p-spur"></div>
     <div class="p-karte-ft p-muted">
      Protokolliert wird, was Rechte oder Sichtbarkeit verändert. Die Adresse
      steht nur als Hash darin. Nach <?php echo (int) LSV07P_DB::SPUR_TAGE; ?>
      Tagen wird gelöscht.
     </div>
    </div>
   </section>

   <!-- ── Mein Training (Schwimmer) ──────────────────────────────────── -->
   <section class="p-seite" id="p-s-mein">
    <div class="p-kopf">
     <div>
      <h2>Mein Training</h2>
      <div class="p-sub" id="p-mein-sub"></div>
     </div>
     <div class="p-kopf-ctl">
      <button type="button" class="p-btn p-btn-klein" id="p-buehne-auf-2">Bühne</button>
     </div>
    </div>
    <div class="p-eintraege" id="p-mein-liste"></div>
   </section>

   <!-- ── Meine Zeiten (Schwimmer) ───────────────────────────────────── -->
   <section class="p-seite" id="p-s-meine-zeiten">
    <div class="p-kopf">
     <div>
      <h2>Meine Zeiten</h2>
      <div class="p-sub">
       Zeiten trägt die Trainerin oder der Trainer ein. Stimmt eine Zeit
       nicht, sag es ihnen.
      </div>
     </div>
     <div class="p-kopf-ctl">
      <button type="button" class="p-btn p-btn-klein" id="p-best-pdf">Als PDF</button>
     </div>
    </div>
    <div id="p-meine-zeiten"></div>
   </section>

  </main>
 </div>

 <!-- ══ Die Bühne ═══════════════════════════════════════════════════════
      Vollbild für einen Plan, Splitscreen für mehrere, Größe frei
      einstellbar. Sie liegt über der Anwendung und hat ihre eigene
      Bedienleiste — am Beckenrand zählt, dass man sie mit einem Finger
      bedienen kann.                                                     -->
 <div id="p-buehne" class="p-buehne" hidden>

  <div class="p-bl" id="p-buehne-leiste">
   <button type="button" class="p-bb p-bb-zu" id="p-bb-zu">Schließen</button>

   <span class="p-bb-grp p-bb-grp-spalten" role="group" aria-label="Aufteilung">
    <span class="p-bb-txt">Spalten</span>
    <button type="button" class="p-bb p-bb-s" data-spalten="1" aria-pressed="false">1</button>
    <button type="button" class="p-bb p-bb-s" data-spalten="2" aria-pressed="false">2</button>
    <button type="button" class="p-bb p-bb-s" data-spalten="3" aria-pressed="false">3</button>
    <button type="button" class="p-bb p-bb-s" data-spalten="4" aria-pressed="false">4</button>
   </span>

   <span class="p-bb-grp" role="group" aria-label="Größe">
    <button type="button" class="p-bb" id="p-bb-kleiner" aria-label="Kleiner">−</button>
    <input type="range" id="p-bb-groesse" min="60" max="220" step="10" value="100"
           aria-label="Größe in Prozent">
    <span class="p-bb-wert" id="p-bb-wert">100 %</span>
    <button type="button" class="p-bb" id="p-bb-groesser" aria-label="Größer">+</button>
   </span>

   <span class="p-bb-grp">
    <button type="button" class="p-bb" id="p-bb-kompakt" aria-pressed="false">Knapp</button>
    <button type="button" class="p-bb" id="p-bb-waehlen">Pläne</button>
    <button type="button" class="p-bb" id="p-bb-vollbild" aria-pressed="false">Vollbild</button>
    <button type="button" class="p-bb" id="p-bb-pdf">PDF</button>
   </span>
  </div>

  <div class="p-bf" id="p-buehne-flaeche"></div>

  <div class="p-bleer" id="p-buehne-leer">
   <p>Noch kein Plan auf der Bühne.</p>
   <button type="button" class="p-btn p-btn-p" id="p-bb-waehlen-2">Pläne wählen</button>
  </div>
 </div>

 <!-- ══ Dialog ══════════════════════════════════════════════════════════ -->
 <div class="p-ov" id="p-ov">
  <div class="p-dlg" role="dialog" aria-modal="true" aria-labelledby="p-dlg-titel">
   <div class="p-dlg-hd">
    <span id="p-dlg-titel">—</span>
    <button type="button" class="p-x" id="p-dlg-zu" aria-label="Schließen">×</button>
   </div>
   <div class="p-dlg-bd" id="p-dlg-bd"></div>
   <div class="p-dlg-ft" id="p-dlg-ft"></div>
  </div>
 </div>

</div>
