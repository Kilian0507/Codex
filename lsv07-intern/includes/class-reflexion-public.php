<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Öffentliche Seite für Schwimmer, um einen Reflexionsbogen auszufüllen.
 * Aufruf: <seite>?lsv07i_reflexion=<token>
 *
 * Ablauf:
 *  - Ohne gültigen Schlüssel: Schlüssel-Abfrage-Formular.
 *  - Mit Schlüssel (POST): Bogen anzeigen (Fragen + Bestzeiten + Wunschzeit-Felder).
 *  - Abgabe (POST): Antworten speichern, Status auf 'abgegeben'.
 *  - Bei bereits abgegebenem Bogen: schreibgeschützte Ansicht.
 *
 * Kein Login nötig. Schutz = Token (im Link) + Zugangsschlüssel (separat).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Reflexion_Public {

    public static function init() {
        add_action( 'template_redirect', [ __CLASS__, 'maybe_handle' ] );
    }

    public static function maybe_handle() {
        if ( empty( $_GET['lsv07i_reflexion'] ) ) return;

        // Security-Header so früh wie möglich setzen
        self::send_security_headers();

        // Der Bogen wird ausschließlich über den Zugangsschlüssel identifiziert.
        // Der Link selbst (?lsv07i_reflexion=1) enthält KEINE personenbezogenen
        // Daten und ist für alle Schwimmer identisch — DSGVO-konform.

        $bogen = null;

        // 1) Bereits entsperrten Bogen aus dem (HMAC-signierten) Cookie laden
        if ( ! empty( $_COOKIE['lsv07i_refl_unlock'] ) ) {
            $bogen = self::bogen_aus_cookie( (string) wp_unslash( $_COOKIE['lsv07i_refl_unlock'] ) );
        }

        // POST-Verarbeitung
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            $action = isset( $_POST['refl_action'] ) ? sanitize_text_field( wp_unslash( $_POST['refl_action'] ) ) : '';

            if ( $action === 'unlock' ) {
                // ── Rate-Limiting gegen Brute-Force ──
                if ( self::ist_gesperrt() ) {
                    self::render_unlock( 'Zu viele Fehlversuche. Bitte warte einige Minuten und versuche es erneut.' );
                    exit;
                }
                // ── CSRF-Schutz ──
                if ( ! self::pruefe_csrf() ) {
                    self::render_unlock( 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.' );
                    exit;
                }

                $eingabe = LSV07I_Reflexion::norm_key( (string) wp_unslash( $_POST['schluessel'] ?? '' ) );
                // Plausible Länge begrenzen (DoS / absurde Eingaben abwehren)
                if ( $eingabe === '' || strlen( $eingabe ) > 40 ) {
                    self::registriere_fehlversuch();
                    self::render_unlock( 'Bitte gib einen gültigen Zugangsschlüssel ein.' );
                    exit;
                }

                $gefunden = self::bogen_by_schluessel( $eingabe );
                if ( ! $gefunden ) {
                    self::registriere_fehlversuch();
                    // Bewusst keine Auskunft ob Schlüssel existiert (kein User-Enumeration)
                    self::render_unlock( 'Der Zugangsschlüssel ist ungültig. Bitte überprüfe deine Eingabe.' );
                    exit;
                }

                // ── Zweiter Faktor: Geburtsdatum ──
                // Selbst wenn ein Schlüssel abgefangen wird, ist ohne das
                // korrekte Geburtsdatum kein Zugriff möglich.
                $geb_eingabe = (string) wp_unslash( $_POST['geburtsdatum'] ?? '' );
                if ( strlen( $geb_eingabe ) > 20
                  || ! LSV07I_Reflexion::geburtsdatum_passt( $gefunden['swimmer_id'], $geb_eingabe ) ) {
                    self::registriere_fehlversuch();
                    // Keine Auskunft, WELCHER Faktor falsch war (kein Enumeration)
                    self::render_unlock( 'Zugangsschlüssel oder Geburtsdatum ist nicht korrekt. Bitte überprüfe deine Eingabe.' );
                    exit;
                }

                // Erfolg → Zähler zurücksetzen, Cookie setzen
                self::reset_fehlversuche();
                self::set_unlock_cookie( $gefunden );
                $bogen = $gefunden;
            }

            if ( $action === 'submit' ) {
                if ( ! $bogen ) {
                    self::render_unlock( 'Deine Sitzung ist abgelaufen. Bitte gib deinen Zugangsschlüssel erneut ein.' );
                    exit;
                }
                // ── CSRF-Schutz auch bei der Abgabe ──
                if ( ! self::pruefe_csrf() ) {
                    self::send_json( [ 'ok' => false, 'fehler' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.' ] );
                    exit;
                }
                if ( $bogen['status'] === 'offen' ) {
                    self::handle_submit( $bogen );
                    exit;
                }
                // Bereits abgegeben/archiviert → kein erneutes Speichern
                self::send_json( [ 'ok' => false, 'fehler' => 'Dieser Bogen kann nicht mehr bearbeitet werden.' ] );
                exit;
            }

            // ── Inhalt NUR nach erfolgreicher Anmeldung (beide Faktoren) abrufen ──
            // Die sensiblen Daten (Fragen, Bestzeiten, Antworten) verlassen den
            // Server erst hier — in einem getrennten, Cookie-authentifizierten
            // Request. Die erste Seite enthält ausschließlich das Login-Formular.
            if ( $action === 'fetch_data' ) {
                if ( ! $bogen ) {
                    self::send_json( [ 'ok' => false, 'fehler' => 'Nicht angemeldet.' ], 401 );
                    exit;
                }
                if ( ! self::pruefe_csrf() ) {
                    self::send_json( [ 'ok' => false, 'fehler' => 'Sitzung abgelaufen.' ], 403 );
                    exit;
                }
                self::send_json( [
                    'ok'   => true,
                    'html' => self::output_bogen_html( $bogen ),
                ] );
                exit;
            }
        }

        // Nicht entsperrt → reine Schlüssel-Abfrage (kein Name, keine Daten)
        if ( ! $bogen ) {
            self::render_unlock();
            exit;
        }

        // Entsperrt: NUR die Hülle ausliefern. Der Inhalt wird per
        // separatem fetch_data-Request nachgeladen (s.o.).
        self::render_bogen_shell();
        exit;
    }

    /** Sendet eine JSON-Antwort (mit no-store, kein Caching sensibler Daten). */
    private static function send_json( $data, $status = 200 ) {
        if ( ! headers_sent() ) {
            status_header( $status );
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
            header( 'Pragma: no-cache' );
        }
        echo wp_json_encode( $data );
    }

    // ── Security-Header ────────────────────────────────────────────────────────
    /** CSP-Nonce für das eine erlaubte Inline-Script (pro Request neu). */
    private static function csp_nonce() {
        static $nonce = null;
        if ( $nonce === null ) $nonce = base64_encode( random_bytes( 16 ) );
        return $nonce;
    }

    private static function send_security_headers() {
        if ( headers_sent() ) return;
        $nonce = self::csp_nonce();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: DENY' );
        header( 'Referrer-Policy: no-referrer' );
        // script-src nur mit exakter Nonce (kein unsafe-inline für Scripts),
        // connect-src 'self' für den fetch-basierten Daten-Abruf.
        header( "Content-Security-Policy: default-src 'none'; "
            . "script-src 'nonce-{$nonce}'; "
            . "style-src 'unsafe-inline'; "
            . "connect-src 'self'; "
            . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
        header( 'X-Robots-Tag: noindex, nofollow' );
    }

    // ── CSRF-Schutz (eigenes Token, da kein eingeloggter User) ─────────────────
    /** Erzeugt/holt das CSRF-Token (im Cookie gespeichert, fürs Formular ausgegeben). */
    private static function csrf_token() {
        static $token = null;
        if ( $token !== null ) return $token;
        if ( ! empty( $_COOKIE['lsv07i_refl_csrf'] ) && preg_match( '/^[a-f0-9]{64}$/', $_COOKIE['lsv07i_refl_csrf'] ) ) {
            $token = $_COOKIE['lsv07i_refl_csrf'];
        } else {
            $token = bin2hex( random_bytes( 32 ) );
            if ( ! headers_sent() ) {
                setcookie( 'lsv07i_refl_csrf', $token, [
                    'expires'  => time() + 6 * 3600,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Strict',
                ] );
            }
        }
        return $token;
    }

    /** Prüft das übermittelte CSRF-Token gegen das Cookie (Double-Submit). */
    private static function pruefe_csrf() {
        $cookie = $_COOKIE['lsv07i_refl_csrf'] ?? '';
        $feld   = $_POST['refl_csrf'] ?? '';
        if ( ! is_string( $cookie ) || ! is_string( $feld ) ) return false;
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $cookie ) ) return false;
        return hash_equals( $cookie, $feld );
    }

    // ── Rate-Limiting ──────────────────────────────────────────────────────────
    private static function ip_hash() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // IP wird nur gehasht gespeichert (DSGVO-schonend, keine Klartext-IP).
        return hash_hmac( 'sha256', $ip, LSV07I_Reflexion::schluessel_hash( 'throttle-ip' ) );
    }

    /** Prüft ob die aktuelle IP gerade gesperrt ist. */
    private static function ist_gesperrt() {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT gesperrt_bis FROM {$p}lsv07i_refl_throttle WHERE ip_hash = %s", self::ip_hash()
        ), ARRAY_A );
        if ( ! $row || empty( $row['gesperrt_bis'] ) ) return false;
        return strtotime( $row['gesperrt_bis'] ) > time();
    }

    /** Registriert einen Fehlversuch; ab 10 Versuchen 15 Min Sperre. */
    private static function registriere_fehlversuch() {
        global $wpdb;
        $p = $wpdb->prefix;
        $ip = self::ip_hash();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, versuche, gesperrt_bis, letzter_am FROM {$p}lsv07i_refl_throttle WHERE ip_hash = %s", $ip
        ), ARRAY_A );

        $jetzt = current_time( 'mysql' );
        if ( ! $row ) {
            $wpdb->insert( $p . 'lsv07i_refl_throttle', [
                'ip_hash' => $ip, 'versuche' => 1, 'letzter_am' => $jetzt,
            ], [ '%s', '%d', '%s' ] );
            return;
        }

        // Zähler nach 15 Min Inaktivität zurücksetzen
        $versuche = (int) $row['versuche'];
        if ( strtotime( $row['letzter_am'] ) < time() - 15 * 60 ) $versuche = 0;
        $versuche++;

        $gesperrt_bis = null;
        if ( $versuche >= 10 ) {
            $gesperrt_bis = gmdate( 'Y-m-d H:i:s', time() + 15 * 60 );
            $versuche = 0; // nach Sperre Zähler zurück
        }
        $wpdb->update( $p . 'lsv07i_refl_throttle', [
            'versuche'     => $versuche,
            'gesperrt_bis' => $gesperrt_bis,
            'letzter_am'   => $jetzt,
        ], [ 'id' => (int) $row['id'] ], [ '%d', '%s', '%s' ], [ '%d' ] );
    }

    private static function reset_fehlversuche() {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( $p . 'lsv07i_refl_throttle', [ 'ip_hash' => self::ip_hash() ], [ '%s' ] );
    }

    /** Findet einen Bogen anhand des Schlüssel-Hashes (indizierter Lookup, kein Full-Scan). */
    private static function bogen_by_schluessel( $key_norm ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $hash = LSV07I_Reflexion::schluessel_hash( $key_norm );
        // Indizierter Lookup über schluessel_hash → schnell + kein Timing-Leak über Zeilenzahl.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_refl_bogen WHERE schluessel_hash = %s LIMIT 1", $hash
        ), ARRAY_A );
        return $row ?: null;
    }

    /** HMAC-signierter Cookie-Wert: bogen_id + Schlüssel-Hash, gegen Manipulation. */
    private static function set_unlock_cookie( $bogen ) {
        $payload = $bogen['id'] . '|' . hash_hmac( 'sha256',
            $bogen['id'] . '|' . $bogen['schluessel_hash'], wp_salt() );
        setcookie( 'lsv07i_refl_unlock', $payload, [
            'expires'  => time() + 6 * 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ] );
    }

    /** Lädt + verifiziert den Bogen aus dem Cookie-Wert. */
    private static function bogen_aus_cookie( $cookie ) {
        $parts = explode( '|', $cookie, 2 );
        if ( count( $parts ) !== 2 ) return null;
        $id = absint( $parts[0] );
        $sig = $parts[1];
        if ( ! $id || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) ) return null;

        global $wpdb;
        $p = $wpdb->prefix;
        $bogen = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_refl_bogen WHERE id = %d", $id
        ), ARRAY_A );
        if ( ! $bogen ) return null;

        $soll = hash_hmac( 'sha256', $bogen['id'] . '|' . $bogen['schluessel_hash'], wp_salt() );
        if ( ! hash_equals( $soll, $sig ) ) return null;
        return $bogen;
    }

    // ── Abgabe verarbeiten ─────────────────────────────────────────────────────
    private static function handle_submit( $bogen ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $bogen_id = (int) $bogen['id'];

        // Alte Antworten löschen (falls Re-Submit nach Freischaltung)
        $wpdb->delete( $p . 'lsv07i_refl_antwort', [ 'bogen_id' => $bogen_id ], [ '%d' ] );

        $sort = 0;

        // Fragen aus dem Snapshot
        $fragen = json_decode( $bogen['fragen_snapshot'] ?? '[]', true );
        if ( ! is_array( $fragen ) ) $fragen = [];
        $antw_fragen = $_POST['frage'] ?? [];
        foreach ( $fragen as $f ) {
            $fid  = (int) ( $f['id'] ?? 0 );
            $text = isset( $antw_fragen[ $fid ] )
                  ? sanitize_textarea_field( wp_unslash( $antw_fragen[ $fid ] ) )
                  : '';
            // Eingabelänge begrenzen, dann verschlüsselt ablegen
            $text = mb_substr( $text, 0, 5000 );
            $wpdb->insert( $p . 'lsv07i_refl_antwort', [
                'bogen_id'     => $bogen_id,
                'typ'          => 'frage',
                'frage_id'     => $fid,
                'frage_text'   => mb_substr( (string) ( $f['frage'] ?? '' ), 0, 1000 ),
                'antwort_text' => LSV07I_Reflexion::encrypt( $text ),
                'sort_order'   => $sort++,
            ], [ '%d', '%s', '%d', '%s', '%s', '%d' ] );
        }

        // Bestzeiten + Wunschzeiten
        $bestzeiten = LSV07I_Reflexion::bestzeiten( $bogen['mannschaft_id'], $bogen['swimmer_id'] );
        $wunsch = $_POST['wunsch'] ?? [];
        foreach ( $bestzeiten as $bz ) {
            $strecke = $bz['strecke'];
            $w = isset( $wunsch[ $strecke ] )
               ? sanitize_text_field( wp_unslash( $wunsch[ $strecke ] ) )
               : '';
            $w = mb_substr( $w, 0, 20 );
            $wpdb->insert( $p . 'lsv07i_refl_antwort', [
                'bogen_id'     => $bogen_id,
                'typ'          => 'zeit',
                'strecke'      => $strecke,
                'zeit_aktuell' => $bz['zeit_raw'],
                'wunschzeit'   => LSV07I_Reflexion::encrypt( $w ),
                'sort_order'   => $sort++,
            ], [ '%d', '%s', '%s', '%s', '%s', '%d' ] );
        }

        // Status auf abgegeben
        $wpdb->update( $p . 'lsv07i_refl_bogen',
            [ 'status' => 'abgegeben', 'abgegeben_am' => current_time( 'mysql' ) ],
            [ 'id' => $bogen_id ],
            [ '%s', '%s' ], [ '%d' ]
        );

        self::send_json( [
            'ok'   => true,
            'html' => '<div class="refl-success">'
                . '<div class="refl-success-icon">✓</div>'
                . '<h2>Bogen abgegeben</h2>'
                . '<p>Deine Antworten wurden gespeichert und an deinen Trainer übermittelt. '
                . 'Du kannst diese Seite jetzt schließen.</p>'
                . '</div>',
        ] );
    }

    // ── Schlüssel-Abfrage rendern (KEIN Name, keine personenbez. Daten) ────────
    private static function render_unlock( $fehler = '' ) {
        $fehler_html = $fehler
            ? '<div class="refl-error">' . esc_html( $fehler ) . '</div>'
            : '';
        $body = '
        <div class="refl-unlock">
            <h2>Reflexionsbogen</h2>
            <p>Bitte gib deinen persönlichen Zugangsschlüssel und dein
               Geburtsdatum ein. Den Schlüssel hast du von deinem Trainer
               bekommen.</p>
            ' . $fehler_html . '
            <form method="post">
                <input type="hidden" name="refl_action" value="unlock">
                <input type="hidden" name="refl_csrf" value="' . esc_attr( self::csrf_token() ) . '">
                <label class="refl-flbl">Zugangsschlüssel</label>
                <input type="text" name="schluessel" class="refl-input refl-key"
                       placeholder="ABC-DEF-GHJ" autocomplete="off" maxlength="40"
                       autocapitalize="characters" autofocus required>
                <label class="refl-flbl">Geburtsdatum</label>
                <input type="text" name="geburtsdatum" class="refl-input"
                       placeholder="TT.MM.JJJJ" autocomplete="off" inputmode="numeric"
                       maxlength="20" required>
                <button type="submit" class="refl-btn">Bogen öffnen</button>
            </form>
        </div>';
        self::render_page( 'Reflexionsbogen', $body );
    }

    // ── Bogen-Hülle: leere Seite, lädt Inhalt per fetch nach Anmeldung ─────────
    // Enthält bewusst KEINE Bogendaten. Fragen/Bestzeiten/Antworten kommen
    // erst über den fetch_data-Request (Cookie-authentifiziert).
    private static function render_bogen_shell() {
        $csrf = esc_attr( self::csrf_token() );
        ob_start();
        ?>
        <div id="refl-app" class="refl-loading">
            <div class="refl-spinner" aria-hidden="true"></div>
            <p class="refl-hint" style="text-align:center">Bogen wird geladen…</p>
        </div>
        <noscript>
            <div class="refl-error">Für diese Seite wird JavaScript benötigt. Bitte aktiviere JavaScript in deinem Browser.</div>
        </noscript>
        <script nonce="<?php echo esc_attr( self::csp_nonce() ); ?>">
        (function(){
            var CSRF = <?php echo wp_json_encode( $csrf ); ?>;
            var app = document.getElementById('refl-app');
            function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}

            // Inhalt erst JETZT vom Server holen (getrennter, authentifizierter Request)
            var fd = new URLSearchParams();
            fd.set('refl_action','fetch_data');
            fd.set('refl_csrf', CSRF);
            fetch(window.location.href, {
                method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body: fd.toString()
            }).then(function(r){return r.json();}).then(function(d){
                if(!d || !d.ok){
                    app.className='';
                    app.innerHTML='<div class="refl-error">'+esc((d&&d.fehler)||'Der Bogen konnte nicht geladen werden. Bitte lade die Seite neu.')+'</div>';
                    return;
                }
                app.className='';
                app.innerHTML = d.html;
                bindForm();
            }).catch(function(){
                app.className='';
                app.innerHTML='<div class="refl-error">Verbindungsfehler. Bitte versuche es erneut.</div>';
            });

            // Absenden ebenfalls per fetch (kein Klartext im initialen HTML)
            function bindForm(){
                var form = document.getElementById('refl-form');
                if(!form) return;
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    if(!window.confirm('Bogen jetzt endgültig absenden? Danach kannst du nichts mehr ändern.')) return;
                    var btn = form.querySelector('button[type=submit]');
                    if(btn){ btn.disabled=true; btn.textContent='Wird gesendet…'; }
                    var body = new URLSearchParams(new FormData(form));
                    body.set('refl_action','submit');
                    body.set('refl_csrf', CSRF);
                    fetch(window.location.href, {
                        method:'POST', credentials:'same-origin',
                        headers:{'Content-Type':'application/x-www-form-urlencoded'},
                        body: body.toString()
                    }).then(function(r){return r.json();}).then(function(d){
                        if(d && d.ok){
                            app.innerHTML = d.html || '<div class="refl-success"><div class="refl-success-icon">✓</div><h2>Bogen abgegeben</h2><p>Vielen Dank!</p></div>';
                        } else {
                            if(btn){ btn.disabled=false; btn.textContent='Absenden'; }
                            alert((d&&d.fehler)||'Senden fehlgeschlagen.');
                        }
                    }).catch(function(){
                        if(btn){ btn.disabled=false; btn.textContent='Absenden'; }
                        alert('Verbindungsfehler. Bitte versuche es erneut.');
                    });
                });
            }
        })();
        </script>
        <?php
        $body = ob_get_clean();
        self::render_page( 'Reflexionsbogen', $body );
    }

    // ── Bogen-Inhalt als HTML-Fragment (wird per fetch_data ausgeliefert) ──────
    private static function output_bogen_html( $bogen ) {
        // Abgegebene UND archivierte Bögen sind schreibgeschützt.
        $readonly   = in_array( $bogen['status'], [ 'abgegeben', 'archiviert' ], true );
        $archiviert = ( $bogen['status'] === 'archiviert' );
        $name = esc_html( $bogen['swimmer_name'] );

        // Bei readonly: gespeicherte Antworten laden + entschlüsseln
        global $wpdb;
        $p = $wpdb->prefix;
        $gespeichert = [];
        if ( $readonly ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT typ, frage_id, antwort_text, strecke, wunschzeit
                   FROM {$p}lsv07i_refl_antwort WHERE bogen_id = %d", (int) $bogen['id']
            ), ARRAY_A );
            foreach ( $rows as $r ) {
                if ( $r['typ'] === 'frage' ) {
                    $gespeichert['frage'][ (int) $r['frage_id'] ] = LSV07I_Reflexion::decrypt( $r['antwort_text'] );
                } else {
                    $gespeichert['wunsch'][ $r['strecke'] ] = LSV07I_Reflexion::decrypt( $r['wunschzeit'] );
                }
            }
        }

        $fragen = json_decode( $bogen['fragen_snapshot'] ?? '[]', true );
        if ( ! is_array( $fragen ) ) $fragen = [];
        $bestzeiten = LSV07I_Reflexion::bestzeiten( $bogen['mannschaft_id'], $bogen['swimmer_id'] );

        // Streckennamen
        $strecken_namen = class_exists( 'LSV07I_Ajax_Bestzeiten' ) ? LSV07I_Ajax_Bestzeiten::STRECKEN : [];

        if ( $archiviert ) {
            $banner = '<div class="refl-banner refl-banner-done">Dieser Bogen wurde archiviert und kann nicht mehr bearbeitet werden.</div>';
        } elseif ( $readonly ) {
            $banner = '<div class="refl-banner refl-banner-done">Dieser Bogen wurde bereits abgegeben. Du kannst deine Antworten ansehen, aber nicht mehr ändern.</div>';
        } else {
            $banner = '<div class="refl-banner">Bitte beantworte alle Fragen und gib für jede Strecke deine Wunschzeit an. Mit „Absenden" gibst du den Bogen endgültig ab.</div>';
        }
        $csrf = esc_attr( self::csrf_token() );

        ob_start();
        ?>
        <form method="post" class="refl-form" id="refl-form">
            <input type="hidden" name="refl_action" value="submit">
            <input type="hidden" name="refl_csrf" value="<?php echo $csrf; ?>">
            <h2>Reflexionsbogen — <?php echo $name; ?></h2>
            <?php echo $banner; ?>

            <?php if ( ! empty( $fragen ) ) : ?>
            <section class="refl-section">
                <h3>Fragen</h3>
                <?php foreach ( $fragen as $f ):
                    $fid = (int) ( $f['id'] ?? 0 );
                    $val = esc_textarea( $gespeichert['frage'][ $fid ] ?? '' );
                ?>
                <div class="refl-field">
                    <label><?php echo esc_html( $f['frage'] ?? '' ); ?></label>
                    <textarea name="frage[<?php echo $fid; ?>]" rows="3" maxlength="5000"
                        <?php echo $readonly ? 'readonly' : 'required'; ?>><?php echo $val; ?></textarea>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if ( ! empty( $bestzeiten ) ) : ?>
            <section class="refl-section">
                <h3>Deine Bestzeiten &amp; Wunschzeiten</h3>
                <p class="refl-hint">Trage für jede Strecke ein, welche Zeit du diese Saison erreichen möchtest.</p>
                <div class="refl-times">
                    <div class="refl-times-head">
                        <span>Strecke</span><span>Aktuelle Bestzeit</span><span>Wunschzeit</span>
                    </div>
                    <?php foreach ( $bestzeiten as $bz ):
                        $strecke = $bz['strecke'];
                        $label = $strecken_namen[ $strecke ] ?? $strecke;
                        $val = esc_attr( $gespeichert['wunsch'][ $strecke ] ?? '' );
                    ?>
                    <div class="refl-times-row">
                        <span class="refl-strecke"><?php echo esc_html( $label ); ?></span>
                        <span class="refl-aktuell"><?php echo esc_html( $bz['zeit_raw'] ); ?></span>
                        <span class="refl-wunsch">
                            <input type="text" name="wunsch[<?php echo esc_attr( $strecke ); ?>]"
                                   value="<?php echo $val; ?>" placeholder="mm:ss,00" maxlength="20"
                                   <?php echo $readonly ? 'readonly' : ''; ?>>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php else: ?>
            <section class="refl-section">
                <p class="refl-hint">Für dich sind noch keine Bestzeiten hinterlegt.</p>
            </section>
            <?php endif; ?>

            <?php if ( ! $readonly ): ?>
            <div class="refl-actions">
                <button type="submit" class="refl-btn">Absenden</button>
            </div>
            <?php endif; ?>
        </form>
        <?php
        return ob_get_clean();
    }

    // ── Standalone HTML-Seite (kein WP-Theme, eigenständig) ────────────────────
    private static function render_page( $title, $body ) {
        header( 'Content-Type: text/html; charset=utf-8' );
        ?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?> — LSV07</title>
<style>
:root{
  --c-pri:#3c7efc; --c-text:#1a202c; --c-text2:#475569;
  --c-line:#e2e8f0; --c-bg:#f1f5f9;
}
*{box-sizing:border-box}
body{
  margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  background:var(--c-bg); color:var(--c-text); line-height:1.5; padding:20px;
}
.refl-wrap{max-width:680px; margin:24px auto;}
.refl-card{
  background:#fff; border:1px solid var(--c-line); border-radius:14px;
  padding:28px; box-shadow:0 4px 20px rgba(0,0,0,.05);
}
h2{margin:0 0 14px; font-size:22px;}
h3{margin:22px 0 10px; font-size:16px; color:var(--c-text);}
p{color:var(--c-text2); margin:0 0 14px;}
.refl-banner{
  background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af;
  padding:10px 14px; border-radius:8px; font-size:14px; margin-bottom:18px;
}
.refl-banner-done{background:#f1f5f9; border-color:var(--c-line); color:var(--c-text2);}
.refl-error{
  background:#fef2f2; border:1px solid #fecaca; color:#991b1b;
  padding:10px 14px; border-radius:8px; font-size:14px; margin-bottom:14px;
}
.refl-section{margin-bottom:24px;}
.refl-field{margin-bottom:16px;}
.refl-field label{display:block; font-weight:600; margin-bottom:6px; font-size:14px;}
.refl-input,textarea,.refl-wunsch input{
  width:100%; padding:10px 12px; border:1px solid var(--c-line);
  border-radius:8px; font-size:15px; font-family:inherit; color:var(--c-text);
}
textarea{resize:vertical;}
textarea:focus,.refl-input:focus,.refl-wunsch input:focus{
  outline:none; border-color:var(--c-pri); box-shadow:0 0 0 3px rgba(60,126,252,.12);
}
textarea[readonly],input[readonly]{background:#f8fafc; color:var(--c-text2);}
.refl-key{
  text-align:center; font-size:22px; letter-spacing:2px; font-weight:600;
  text-transform:uppercase; margin:8px 0 14px;
}
.refl-flbl{
  display:block; font-size:13px; font-weight:600; color:var(--c-text2);
  margin:6px 0 4px;
}
.refl-btn{
  background:var(--c-pri); color:#fff; border:0; border-radius:8px;
  padding:12px 24px; font-size:15px; font-weight:600; cursor:pointer;
  width:100%; transition:background .15s;
}
.refl-btn:hover{background:#2563eb;}
.refl-hint{font-size:13px; color:#94a3b8;}
.refl-times{border:1px solid var(--c-line); border-radius:10px; overflow:hidden;}
.refl-times-head,.refl-times-row{
  display:grid; grid-template-columns:1.4fr 1fr 1fr; gap:10px;
  padding:10px 14px; align-items:center;
}
.refl-times-head{
  background:#f8fafc; font-size:12px; font-weight:600; color:var(--c-text2);
  text-transform:uppercase; letter-spacing:.03em;
}
.refl-times-row{border-top:1px solid var(--c-line);}
.refl-strecke{font-weight:600; font-size:14px;}
.refl-aktuell{font-variant-numeric:tabular-nums; color:var(--c-text2);}
.refl-wunsch input{padding:7px 10px; font-size:14px;}
.refl-actions{margin-top:24px;}
.refl-success{text-align:center; padding:20px 0;}
.refl-success-icon{
  width:64px; height:64px; margin:0 auto 16px; border-radius:50%;
  background:#dcfce7; color:#16a34a; font-size:34px; line-height:64px;
}
.refl-unlock h2{text-align:center;}
.refl-loading{ text-align:center; padding:30px 0; }
.refl-spinner{
  width:38px; height:38px; margin:0 auto 14px;
  border:4px solid #e2e8f0; border-top-color:var(--c-pri);
  border-radius:50%; animation:refl-spin .8s linear infinite;
}
@keyframes refl-spin{ to{ transform:rotate(360deg); } }
@media (max-width:560px){
  .refl-times-head{display:none;}
  .refl-times-row{grid-template-columns:1fr; gap:4px; padding:12px 14px;}
  .refl-strecke{font-size:15px;}
  .refl-aktuell::before{content:"Bestzeit: "; color:#94a3b8;}
  .refl-card{padding:20px;}
}
</style>
</head>
<body>
<div class="refl-wrap">
  <div class="refl-card">
    <?php echo $body; ?>
  </div>
</div>
</body>
</html><?php
    }
}
