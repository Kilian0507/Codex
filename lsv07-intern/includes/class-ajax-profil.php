<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Ajax_Profil
 * ------------------
 * Persönliche Einstellungen, die jeder angemeldete Nutzer für sich selbst
 * vornimmt — erreichbar über das Zahnrad auf der Startseite:
 *
 *   - lsv07i_profil_get         Aktuelle Daten für den Einstellungs-Dialog
 *   - lsv07i_profil_theme       Hell/Dunkel/Automatisch merken
 *   - lsv07i_profil_passwort    Passwort ändern (mit Prüfung des alten)
 *   - lsv07i_profil_kontakt     E-Mail und Telefon ändern
 *   - lsv07i_profil_bild        Profilbild hochladen
 *   - lsv07i_profil_bild_weg    Profilbild entfernen
 *
 * Alle Endpunkte arbeiten ausschließlich auf dem eigenen Konto. Es gibt
 * bewusst keinen Parameter für eine fremde Nutzer-ID — wer die Daten anderer
 * ändern will, nutzt die Personenverwaltung im Admin-Bereich.
 */

class LSV07I_Ajax_Profil {

    /** Erlaubte Bildtypen für das Profilbild (Endung ⇒ MIME). */
    const BILD_TYPEN = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];
    const MAX_BYTES  = 3145728;  // 3 MB
    const KANTE      = 256;      // Zielgröße in Pixeln (quadratisch)

    public static function init() {
        $actions = [ 'get', 'theme', 'passwort', 'kontakt', 'bild', 'bild_weg' ];
        foreach ( $actions as $a ) {
            add_action( 'wp_ajax_lsv07i_profil_' . $a, [ __CLASS__, $a ] );
        }
        // Auslieferung der Profilbilder (auch für nopriv nicht nötig — nur intern)
        add_action( 'wp_ajax_lsv07i_profil_avatar', [ __CLASS__, 'avatar' ] );
    }

    /**
     * Gemeinsame Vorprüfung: gültiger Nonce und angemeldeter Nutzer mit
     * Zugang zum internen Bereich.
     */
    private static function pruefen() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Sitzung abgelaufen. Bitte Seite neu laden.' ], 403 );
        }
        if ( ! is_user_logged_in() || ! LSV07I_Access::has_any_access() ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        return get_current_user_id();
    }

    // ─── Verzeichnis & Pfade ────────────────────────────────────────────────

    /** Basisordner der Profilbilder. Legt ihn samt Zugriffsschutz an. */
    public static function bilder_dir() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/profilbilder';
        if ( ! is_dir( $base ) ) wp_mkdir_p( $base );
        // Schutzdateien im übergeordneten lsv07i-private (teilt sich mit
        // den Schwimmer-Dateien) sicherstellen
        $eltern = dirname( $base );
        if ( ! file_exists( $eltern . '/.htaccess' ) ) {
            @file_put_contents( $eltern . '/.htaccess',
                "# LSV07-Intern: Direktzugriff blockieren\nOrder Deny,Allow\nDeny from all\n"
              . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
        }
        if ( ! file_exists( $eltern . '/index.html' ) ) {
            @file_put_contents( $eltern . '/index.html', '<!-- silence -->' );
        }
        return $base;
    }

    /** Dateipfad des Profilbilds eines Nutzers, oder '' wenn keines da ist. */
    public static function bild_pfad( $user_id ) {
        $datei = get_user_meta( (int) $user_id, 'lsv07i_profilbild', true );
        if ( ! $datei ) return '';
        // Nur der reine Dateiname wird gespeichert — Pfadanteile abweisen
        $datei = basename( (string) $datei );
        $pfad  = trailingslashit( self::bilder_dir() ) . $datei;
        return file_exists( $pfad ) ? $pfad : '';
    }

    /** URL, unter der das eigene Profilbild abgerufen werden kann. */
    public static function bild_url( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! self::bild_pfad( $user_id ) ) return '';
        $version = (string) get_user_meta( $user_id, 'lsv07i_profilbild_ver', true );
        return add_query_arg( [
            'action' => 'lsv07i_profil_avatar',
            'uid'    => $user_id,
            'v'      => $version ?: '1',
        ], admin_url( 'admin-ajax.php' ) );
    }

    // ─── Endpunkte ──────────────────────────────────────────────────────────

    public static function get() {
        $uid  = self::pruefen();
        $user = wp_get_current_user();

        // Telefon aus dem Personen-Datensatz, falls einer verknüpft ist
        global $wpdb;
        $p       = $wpdb->prefix;
        $telefon = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT telefon FROM {$p}lsv07i_personen WHERE wp_user_id = %d LIMIT 1", $uid ) );

        wp_send_json_success( [
            'name'       => $user->display_name,
            'vorname'    => $user->first_name,
            'email'      => $user->user_email,
            'telefon'    => $telefon,
            'theme'      => self::theme_von( $uid ),
            'bild_url'   => self::bild_url( $uid ),
            'initialen'  => self::initialen( $user ),
        ] );
    }

    /** Gespeicherte Darstellung: hell, dunkel oder auto. */
    public static function theme_von( $user_id ) {
        $t = (string) get_user_meta( (int) $user_id, 'lsv07i_theme', true );
        return in_array( $t, [ 'hell', 'dunkel', 'auto' ], true ) ? $t : 'hell';
    }

    /** Initialen als Fallback, wenn kein Profilbild hinterlegt ist. */
    public static function initialen( $user ) {
        $vor  = trim( (string) $user->first_name );
        $nach = trim( (string) $user->last_name );
        if ( $vor || $nach ) {
            return mb_strtoupper( mb_substr( $vor ?: 'x', 0, 1 ) . mb_substr( $nach ?: '', 0, 1 ) );
        }
        $anzeige = trim( (string) $user->display_name );
        if ( $anzeige === '' ) return '?';
        $teile = preg_split( '/\s+/', $anzeige );
        if ( count( $teile ) >= 2 ) {
            return mb_strtoupper( mb_substr( $teile[0], 0, 1 ) . mb_substr( $teile[1], 0, 1 ) );
        }
        return mb_strtoupper( mb_substr( $anzeige, 0, 2 ) );
    }

    public static function theme() {
        $uid  = self::pruefen();
        $wert = sanitize_text_field( (string) ( $_POST['theme'] ?? '' ) );
        if ( ! in_array( $wert, [ 'hell', 'dunkel', 'auto' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Unbekannte Darstellung.' ] );
        }
        update_user_meta( $uid, 'lsv07i_theme', $wert );
        wp_send_json_success( [ 'theme' => $wert ] );
    }

    public static function passwort() {
        $uid  = self::pruefen();
        $user = wp_get_current_user();

        $alt  = (string) ( $_POST['alt']  ?? '' );
        $neu  = (string) ( $_POST['neu']  ?? '' );
        $neu2 = (string) ( $_POST['neu2'] ?? '' );

        if ( $alt === '' || $neu === '' ) {
            wp_send_json_error( [ 'message' => 'Bitte alle Felder ausfüllen.' ] );
        }
        if ( ! wp_check_password( $alt, $user->user_pass, $uid ) ) {
            wp_send_json_error( [ 'message' => 'Das aktuelle Passwort stimmt nicht.' ] );
        }
        if ( $neu !== $neu2 ) {
            wp_send_json_error( [ 'message' => 'Die beiden neuen Passwörter stimmen nicht überein.' ] );
        }
        if ( mb_strlen( $neu ) < 10 ) {
            wp_send_json_error( [ 'message' => 'Das neue Passwort muss mindestens 10 Zeichen haben.' ] );
        }
        if ( $neu === $alt ) {
            wp_send_json_error( [ 'message' => 'Das neue Passwort muss sich vom alten unterscheiden.' ] );
        }

        wp_set_password( $neu, $uid );
        // wp_set_password meldet alle Sitzungen ab — die aktuelle wieder anmelden,
        // damit der Nutzer nicht mitten in der Arbeit rausfliegt.
        wp_set_current_user( $uid );
        wp_set_auth_cookie( $uid, true );

        if ( class_exists( 'LSV07I_Log' ) ) {
            LSV07I_Log::write( 'passwort_geaendert', [ 'bereich' => 'profil' ] );
        }
        wp_send_json_success( [ 'message' => 'Passwort geändert.' ] );
    }

    public static function kontakt() {
        $uid   = self::pruefen();
        $email = sanitize_email( (string) ( $_POST['email'] ?? '' ) );
        $tel   = sanitize_text_field( (string) ( $_POST['telefon'] ?? '' ) );

        if ( $email === '' || ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Bitte eine gültige E-Mail-Adresse angeben.' ] );
        }
        // E-Mail darf keinem anderen Konto gehören
        $belegt = email_exists( $email );
        if ( $belegt && (int) $belegt !== $uid ) {
            wp_send_json_error( [ 'message' => 'Diese E-Mail-Adresse wird bereits von einem anderen Konto genutzt.' ] );
        }

        $res = wp_update_user( [ 'ID' => $uid, 'user_email' => $email ] );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( [ 'message' => $res->get_error_message() ] );
        }

        // Telefon (und E-Mail) im verknüpften Personen-Datensatz mitpflegen
        global $wpdb;
        $p  = $wpdb->prefix;
        $pid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07i_personen WHERE wp_user_id = %d LIMIT 1", $uid ) );
        if ( $pid ) {
            $wpdb->update( "{$p}lsv07i_personen",
                [ 'telefon' => $tel, 'email' => $email ], [ 'id' => $pid ] );
        }

        wp_send_json_success( [ 'message' => 'Kontaktdaten gespeichert.', 'email' => $email, 'telefon' => $tel ] );
    }

    public static function bild() {
        $uid = self::pruefen();

        if ( empty( $_FILES['datei'] ) || ! is_uploaded_file( $_FILES['datei']['tmp_name'] ?? '' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Datei empfangen.' ] );
        }
        $datei = $_FILES['datei'];
        if ( ! empty( $datei['error'] ) ) {
            wp_send_json_error( [ 'message' => 'Upload fehlgeschlagen (Fehlercode ' . (int) $datei['error'] . ').' ] );
        }
        if ( (int) $datei['size'] > self::MAX_BYTES ) {
            wp_send_json_error( [ 'message' => 'Das Bild ist zu groß (maximal 3 MB).' ] );
        }

        // Typ am Inhalt prüfen, nicht am übermittelten Namen
        $info = @getimagesize( $datei['tmp_name'] );
        if ( ! $info || empty( $info['mime'] ) ) {
            wp_send_json_error( [ 'message' => 'Die Datei ist kein gültiges Bild.' ] );
        }
        $mime = (string) $info['mime'];
        $endung = array_search( $mime, self::BILD_TYPEN, true );
        if ( $endung === false ) {
            wp_send_json_error( [ 'message' => 'Nur JPG, PNG oder WebP sind erlaubt.' ] );
        }

        $bild = self::quadrat_skalieren( $datei['tmp_name'], $mime );
        if ( ! $bild ) {
            wp_send_json_error( [ 'message' => 'Das Bild konnte nicht verarbeitet werden.' ] );
        }

        // Altes Bild entfernen, damit keine Dateileichen liegen bleiben
        $alt = self::bild_pfad( $uid );
        if ( $alt ) @unlink( $alt );

        $name = 'u' . $uid . '-' . wp_generate_password( 12, false, false ) . '.' . $endung;
        $ziel = trailingslashit( self::bilder_dir() ) . $name;
        if ( ! self::bild_speichern( $bild, $ziel, $mime ) ) {
            imagedestroy( $bild );
            wp_send_json_error( [ 'message' => 'Das Bild konnte nicht gespeichert werden.' ] );
        }
        imagedestroy( $bild );

        update_user_meta( $uid, 'lsv07i_profilbild', $name );
        update_user_meta( $uid, 'lsv07i_profilbild_ver', (string) time() );

        wp_send_json_success( [ 'bild_url' => self::bild_url( $uid ) ] );
    }

    public static function bild_weg() {
        $uid = self::pruefen();
        $alt = self::bild_pfad( $uid );
        if ( $alt ) @unlink( $alt );
        delete_user_meta( $uid, 'lsv07i_profilbild' );
        delete_user_meta( $uid, 'lsv07i_profilbild_ver' );
        wp_send_json_success( [ 'message' => 'Profilbild entfernt.' ] );
    }

    /**
     * Liefert ein Profilbild aus. Die Dateien liegen außerhalb des
     * Web-Roots geschützt, deshalb dieser Umweg. Sichtbar sind sie für
     * jeden, der Zugang zum internen Bereich hat — dort steht der Name
     * ohnehin an jeder Nachricht.
     */
    public static function avatar() {
        if ( ! is_user_logged_in() || ! LSV07I_Access::has_any_access() ) {
            status_header( 403 );
            exit;
        }
        $uid  = (int) ( $_GET['uid'] ?? 0 );
        $pfad = $uid ? self::bild_pfad( $uid ) : '';
        if ( ! $pfad ) {
            status_header( 404 );
            exit;
        }
        $info = @getimagesize( $pfad );
        $mime = $info && ! empty( $info['mime'] ) ? $info['mime'] : 'application/octet-stream';
        if ( ! in_array( $mime, self::BILD_TYPEN, true ) ) {
            status_header( 404 );
            exit;
        }
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $pfad ) );
        header( 'Cache-Control: private, max-age=86400' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $pfad );
        exit;
    }

    // ─── Bildverarbeitung ───────────────────────────────────────────────────

    /**
     * Schneidet mittig ein Quadrat aus und skaliert es auf KANTE Pixel.
     * So sind alle Profilbilder gleich groß und der Speicherbedarf bleibt
     * klein, egal was hochgeladen wurde.
     */
    private static function quadrat_skalieren( $pfad, $mime ) {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) return null;

        switch ( $mime ) {
            case 'image/jpeg': $quelle = @imagecreatefromjpeg( $pfad ); break;
            case 'image/png':  $quelle = @imagecreatefrompng( $pfad );  break;
            case 'image/webp':
                $quelle = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $pfad ) : null;
                break;
            default: $quelle = null;
        }
        if ( ! $quelle ) return null;

        $bw = imagesx( $quelle );
        $bh = imagesy( $quelle );
        $kante = min( $bw, $bh );
        $sx = (int) ( ( $bw - $kante ) / 2 );
        $sy = (int) ( ( $bh - $kante ) / 2 );

        $ziel = imagecreatetruecolor( self::KANTE, self::KANTE );
        // Transparenz von PNG/WebP erhalten
        imagealphablending( $ziel, false );
        imagesavealpha( $ziel, true );
        $transparent = imagecolorallocatealpha( $ziel, 0, 0, 0, 127 );
        imagefilledrectangle( $ziel, 0, 0, self::KANTE, self::KANTE, $transparent );

        imagecopyresampled( $ziel, $quelle, 0, 0, $sx, $sy,
                            self::KANTE, self::KANTE, $kante, $kante );
        imagedestroy( $quelle );
        return $ziel;
    }

    private static function bild_speichern( $bild, $ziel, $mime ) {
        switch ( $mime ) {
            case 'image/jpeg': return imagejpeg( $bild, $ziel, 88 );
            case 'image/png':  return imagepng( $bild, $ziel, 6 );
            case 'image/webp': return function_exists( 'imagewebp' ) && imagewebp( $bild, $ziel, 88 );
        }
        return false;
    }
}
