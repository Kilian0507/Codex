<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Das eigene Profil: Kontaktdaten, Passwort, Profilbild, Darstellung.
 *
 * Diese Klasse arbeitet **ausschliesslich am eigenen Konto**. Es gibt
 * keinen Parameter für eine fremde Nummer, nicht einmal für die
 * Administration: Wer die Daten anderer ändert, tut das in der
 * Stammdatenpflege, und das steht dort im Protokoll. Darum braucht das
 * Profil auch keinen Schalter — es genügt, dass das Konto hereinkommt.
 *
 * Gespeichert wird an denselben Stellen wie im internen Bereich
 * (`lsv07i_theme`, `lsv07i_profilbild`, derselbe Ordner): Solange beide
 * Plugins laufen, soll ein Profilbild in beiden dasselbe sein und eine
 * gewählte Darstellung nicht zweimal eingestellt werden müssen.
 *
 * Vier Dinge sind anders:
 *
 * 1. **Ein Profilbild gibt nur das eigene Konto heraus.** Im internen
 *    Bereich nimmt der Ausgabe-Endpunkt eine beliebige `uid` und liefert
 *    jedem mit Zugang das Bild jeder Person. Begründet wird das damit,
 *    dass „der Name ohnehin an jeder Nachricht steht" — ein Gesicht ist
 *    aber mehr als ein Name. In SwimSys zeigt keine Ansicht das Bild
 *    einer anderen Person, also gibt die Ausgabe auch keines heraus.
 *    Käme je eine solche Ansicht, muss dieser Endpunkt mitgeändert
 *    werden; dann steht die Abwägung neu an.
 * 2. **Die E-Mail wird VOR dem Säubern geprüft.** `sanitize_email()`
 *    macht aus Unsinn eine leere Zeichenkette; wer danach prüft, sagt
 *    „bitte eine E-Mail angeben", obwohl eine dastand. Denselben Fehler
 *    hatte die Stammdatenpflege, und dort kostete er die
 *    Erinnerungsmails.
 * 3. **Das neue Passwort darf nicht der eigene Name sein.** Zehn Zeichen
 *    allein sind kein Schutz, wenn es die eigene Anmeldung oder der
 *    vordere Teil der eigenen Adresse ist.
 * 4. **Die Änderung der Adresse steht im Protokoll — ohne die
 *    Adressen.** Dass sie sich geändert hat, ist die Auskunft, die
 *    zählt; die Adresse selbst steht im Konto, und das Protokoll liest
 *    jeder mit dem Protokoll-Schalter.
 */
class LSV07S_Profil {

    /** Die drei Darstellungen. Dieselben Wörter wie im internen Bereich. */
    const THEMEN = [ 'hell', 'dunkel', 'auto' ];

    /** Der Schlüssel im Benutzerspeicher — geteilt mit dem internen Bereich. */
    const META_THEMA = 'lsv07i_theme';
    const META_BILD  = 'lsv07i_profilbild';
    const META_VER   = 'lsv07i_profilbild_ver';

    /** Was als Profilbild hochgeladen werden darf. */
    const BILD_TYPEN = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    const MAX_BYTES = 3145728;   // 3 MB
    const KANTE     = 256;       // Zielgrösse, quadratisch

    /** Die kürzeste erlaubte Länge eines neuen Passworts. */
    const PASS_MIN = 10;

    /** Für Prüfungen: die Prüfung auf eine echte hochgeladene Datei umgehen. */
    public static $pruefe_upload = null;

    private static function darf() {
        return LSV07S_Schalter::zugang();
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** Was im Profildialog steht. */
    public static function daten() {
        global $wpdb;
        if ( ! self::darf() ) return 'Dieses Konto kommt in SwimSys nicht herein.';
        $uid  = get_current_user_id();
        $konto = get_user_by( 'id', $uid );
        if ( ! $konto ) return 'Dieses Konto gibt es nicht.';

        $telefon = '';
        if ( LSV07S_Tabellen::da( 'personen' ) ) {
            $telefon = (string) $wpdb->get_var( $wpdb->prepare(
                'SELECT telefon FROM ' . LSV07S_Tabellen::t( 'personen' )
                . ' WHERE wp_user_id = %d LIMIT 1', $uid ) );
        }

        return [
            'name'      => LSV07S_Person::konto_name( $uid ),
            'email'     => (string) ( $konto->user_email ?? '' ),
            'telefon'   => $telefon,
            'thema'     => self::thema( $uid ),
            'bild'      => self::bild_da( $uid ),
            'initialen' => self::initialen( $uid ),
            'pass_min'  => self::PASS_MIN,
            'max_mb'    => (int) round( self::MAX_BYTES / 1048576 ),
        ];
    }

    /** Die gewählte Darstellung. Unbekanntes gilt als „hell". */
    public static function thema( $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        $t = (string) get_user_meta( $uid, self::META_THEMA, true );
        return in_array( $t, self::THEMEN, true ) ? $t : 'hell';
    }

    /**
     * Die Initialen, wenn kein Bild hinterlegt ist.
     *
     * Aus Vor- und Nachnamen, sonst aus dem Anzeigenamen. Nie leer: Ein
     * leerer Kreis sieht aus wie ein Fehler.
     */
    public static function initialen( $uid ) {
        $konto = get_user_by( 'id', (int) $uid );
        if ( ! $konto ) return '?';
        $vor  = trim( (string) get_user_meta( (int) $uid, 'first_name', true ) );
        $nach = trim( (string) get_user_meta( (int) $uid, 'last_name', true ) );
        if ( $vor !== '' || $nach !== '' ) {
            return mb_strtoupper( mb_substr( $vor !== '' ? $vor : $nach, 0, 1 )
                 . ( $vor !== '' && $nach !== '' ? mb_substr( $nach, 0, 1 ) : '' ) );
        }
        $anzeige = trim( (string) ( $konto->display_name ?? '' ) );
        if ( $anzeige === '' ) return '?';
        $teile = preg_split( '/\s+/', $anzeige );
        if ( count( $teile ) >= 2 ) {
            return mb_strtoupper( mb_substr( $teile[0], 0, 1 ) . mb_substr( $teile[1], 0, 1 ) );
        }
        return mb_strtoupper( mb_substr( $anzeige, 0, 2 ) );
    }

    // ── Darstellung ──────────────────────────────────────────────────────

    public static function thema_setzen( $wert ) {
        if ( ! self::darf() ) return 'Dieses Konto kommt in SwimSys nicht herein.';
        $wert = (string) $wert;
        if ( ! in_array( $wert, self::THEMEN, true ) ) {
            return 'Diese Darstellung gibt es nicht.';
        }
        update_user_meta( get_current_user_id(), self::META_THEMA, $wert );
        return true;
    }

    // ── Passwort ─────────────────────────────────────────────────────────

    /**
     * Das eigene Passwort ändern.
     *
     * Das alte muss stimmen — sonst genügte eine fremde, offen stehende
     * Sitzung, um das Konto zu übernehmen.
     */
    public static function passwort_aendern( $alt, $neu, $neu2 ) {
        if ( ! self::darf() ) return 'Dieses Konto kommt in SwimSys nicht herein.';
        $uid   = get_current_user_id();
        $konto = get_user_by( 'id', $uid );
        if ( ! $konto ) return 'Dieses Konto gibt es nicht.';

        $alt = (string) $alt; $neu = (string) $neu; $neu2 = (string) $neu2;
        if ( $alt === '' || $neu === '' || $neu2 === '' ) {
            return 'Bitte alle drei Felder ausfüllen.';
        }
        if ( ! wp_check_password( $alt, $konto->user_pass ?? '', $uid ) ) {
            return 'Das aktuelle Passwort stimmt nicht.';
        }
        if ( $neu !== $neu2 ) {
            return 'Die beiden neuen Passwörter stimmen nicht überein.';
        }
        if ( mb_strlen( $neu ) < self::PASS_MIN ) {
            return 'Das neue Passwort braucht mindestens ' . self::PASS_MIN . ' Zeichen.';
        }
        if ( $neu === $alt ) {
            return 'Das neue Passwort muss sich vom alten unterscheiden.';
        }

        /* Zehn Zeichen sind kein Schutz, wenn es der eigene Name ist.
           Geprüft wird ohne Rücksicht auf Gross- und Kleinschreibung:
           „Nachname2026" ist so gut geraten wie „nachname2026". */
        $klein = mb_strtolower( $neu );
        $tabu  = [];
        foreach ( [ (string) ( $konto->user_login ?? '' ),
                    (string) get_user_meta( $uid, 'first_name', true ),
                    (string) get_user_meta( $uid, 'last_name', true ) ] as $w ) {
            if ( mb_strlen( trim( $w ) ) >= 4 ) $tabu[] = mb_strtolower( trim( $w ) );
        }
        $mail = (string) ( $konto->user_email ?? '' );
        if ( strpos( $mail, '@' ) !== false ) {
            $vorn = mb_strtolower( substr( $mail, 0, strpos( $mail, '@' ) ) );
            if ( mb_strlen( $vorn ) >= 4 ) $tabu[] = $vorn;
        }
        foreach ( $tabu as $w ) {
            if ( strpos( $klein, $w ) !== false ) {
                return 'Das neue Passwort darf nicht Ihren Namen, Ihre Anmeldung oder '
                     . 'Ihre E-Mail-Adresse enthalten.';
            }
        }

        wp_set_password( $neu, $uid );
        /* `wp_set_password` meldet alle Sitzungen ab. Die eigene wieder
           anmelden, damit man nicht mitten in der Arbeit herausfällt —
           die anderen bleiben abgemeldet, und das ist richtig so. */
        wp_set_current_user( $uid );
        wp_set_auth_cookie( $uid, true );

        /* Im Protokoll steht, DASS es geändert wurde. Nichts weiter —
           über ein Passwort gibt es nichts zu protokollieren. */
        LSV07S_Log::schreibe( 'profil.passwort', [
            'bereich' => 'profil', 'ziel_typ' => 'konto', 'ziel_id' => $uid,
        ] );
        return true;
    }

    // ── Kontaktdaten ─────────────────────────────────────────────────────

    /**
     * Eigene E-Mail und Telefonnummer.
     *
     * Die Adresse ist der Weg, über den ein Passwort zurückgesetzt wird —
     * eine Änderung daran ist also eine Änderung am Zugang, und sie steht
     * im Protokoll.
     */
    public static function kontakt_speichern( $email, $telefon ) {
        global $wpdb;
        if ( ! self::darf() ) return 'Dieses Konto kommt in SwimSys nicht herein.';
        $uid = get_current_user_id();

        /* Erst prüfen, dann säubern: `sanitize_email` macht aus Unsinn
           eine leere Zeichenkette, und „bitte eine Adresse angeben" ist
           dann der falsche Satz. */
        $roh = trim( (string) $email );
        if ( $roh === '' ) return 'Bitte eine E-Mail-Adresse angeben.';
        if ( ! is_email( $roh ) ) return 'Diese E-Mail-Adresse stimmt nicht.';
        $email = sanitize_email( $roh );
        if ( $email === '' ) return 'Diese E-Mail-Adresse stimmt nicht.';

        $belegt = email_exists( $email );
        if ( $belegt && (int) $belegt !== $uid ) {
            return 'Diese E-Mail-Adresse gehört schon zu einem anderen Konto.';
        }

        $tel = mb_substr( sanitize_text_field( (string) $telefon ), 0, 60 );

        $alt_mail = (string) ( ( get_user_by( 'id', $uid )->user_email ) ?? '' );
        $erg = wp_update_user( [ 'ID' => $uid, 'user_email' => $email ] );
        if ( is_wp_error( $erg ) ) return $erg->get_error_message();

        /* Die Nummer steht im Personendatensatz, wenn einer mit dem Konto
           verknüpft ist. Angelegt wird keiner: Ein Konto ohne Person ist
           ein Konto ohne Person, und ein leerer Datensatz hilft niemandem. */
        if ( LSV07S_Tabellen::da( 'personen' ) ) {
            $t = LSV07S_Tabellen::t( 'personen' );
            $pid = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $t WHERE wp_user_id = %d LIMIT 1", $uid ) );
            if ( $pid ) {
                $wpdb->update( $t, [ 'telefon' => $tel, 'email' => $email ],
                    [ 'id' => $pid ], [ '%s', '%s' ], [ '%d' ] );
            }
        }

        if ( $alt_mail !== $email ) {
            /* OHNE die Adressen: Dass sie sich geändert hat, ist die
               Auskunft; die Adresse selbst steht im Konto, und das
               Protokoll liest jeder mit dem Protokoll-Schalter. */
            LSV07S_Log::schreibe( 'profil.email', [
                'bereich' => 'profil', 'ziel_typ' => 'konto', 'ziel_id' => $uid,
                'details' => 'E-Mail-Adresse geändert',
            ] );
        }
        return true;
    }

    // ── Profilbild ───────────────────────────────────────────────────────

    /**
     * Der Ordner der Profilbilder.
     *
     * Derselbe wie im internen Bereich, und gesperrt mit derselben
     * Fassung der Sperre wie der Attestordner — zwei Fassungen derselben
     * drei Dateien würden auseinanderlaufen.
     */
    public static function ordner() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/profilbilder';
        if ( ! is_dir( $base ) ) wp_mkdir_p( $base );
        LSV07S_Attest::ordner_sperren( dirname( $base ) );
        LSV07S_Attest::ordner_sperren( $base );
        return $base;
    }

    /** Liegt ein Bild? Dann der Pfad, sonst ''. NUR für das eigene Konto. */
    private static function pfad( $uid ) {
        $name = (string) get_user_meta( (int) $uid, self::META_BILD, true );
        if ( $name === '' ) return '';
        /* `basename`, damit aus einem manipulierten Benutzerspeicher kein
           Pfad nach oben wird. */
        $pfad = self::ordner() . '/' . basename( $name );
        return is_file( $pfad ) ? $pfad : '';
    }

    /** Hat dieses Konto ein Bild? Für die Oberfläche. */
    public static function bild_da( $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        return self::pfad( $uid ) !== '';
    }

    /**
     * Das eigene Bild zur Ausgabe.
     *
     * Kein Parameter: Es gibt genau ein Bild, das dieser Endpunkt
     * herausgibt, und das ist das eigene.
     */
    public static function bild_pfad() {
        if ( ! self::darf() ) return 'Dieses Konto kommt in SwimSys nicht herein.';
        $uid  = get_current_user_id();
        $pfad = self::pfad( $uid );
        if ( $pfad === '' ) return 'Für dieses Konto liegt kein Bild.';

        /* Der Typ kommt aus dem INHALT, nicht aus der Endung: Die Datei
           liegt in einem gesperrten Ordner, aber ausgegeben wird sie mit
           einem Content-Type, und der entscheidet, was der Browser damit
           tut. */
        $info = @getimagesize( $pfad );
        $typ  = $info && ! empty( $info['mime'] ) ? (string) $info['mime'] : '';
        if ( ! isset( self::BILD_TYPEN[ $typ ] ) ) {
            return 'Diese Datei ist kein Bild mehr.';
        }
        return [ 'pfad' => $pfad, 'typ' => $typ ];
    }

    /**
     * Ein Profilbild ablegen.
     *
     * Zugeschnitten und verkleinert wird hier, nicht im Browser: Was
     * hochgeladen wird, soll nicht in Originalgrösse auf der Platte
     * liegen bleiben, und ein Bild von 4000 Pixeln in einem Kreis von 32
     * ist Verschwendung.
     */
    public static function bild_ablegen( array $datei ) {
        if ( ! self::darf() ) return 'Dieses Konto kommt in SwimSys nicht herein.';
        $uid = get_current_user_id();

        $tmp = (string) ( $datei['tmp_name'] ?? '' );
        if ( (int) ( $datei['error'] ?? 1 ) !== 0 || $tmp === '' ) {
            return 'Das Bild ist nicht angekommen.';
        }
        $da = self::$pruefe_upload ? ( self::$pruefe_upload )( $tmp ) : is_uploaded_file( $tmp );
        if ( ! $da ) return 'Das Bild ist nicht angekommen.';

        $groesse = (int) ( $datei['size'] ?? 0 );
        if ( $groesse <= 0 ) return 'Das Bild ist leer.';
        if ( $groesse > self::MAX_BYTES ) {
            return 'Das Bild ist größer als ' . (int) round( self::MAX_BYTES / 1048576 ) . ' MB.';
        }

        $info = @getimagesize( $tmp );
        if ( ! $info || empty( $info['mime'] ) ) return 'Diese Datei ist kein Bild.';
        $typ = (string) $info['mime'];
        if ( ! isset( self::BILD_TYPEN[ $typ ] ) ) {
            return 'Erlaubt sind JPG, PNG und WebP. Diese Datei ist ' . $typ . '.';
        }

        $bild = self::zuschneiden( $tmp, $typ );
        if ( ! $bild ) return 'Dieses Bild liess sich nicht verarbeiten.';

        /* Das alte Bild zuerst weg: Sonst bleibt bei jedem Wechsel eine
           Datei liegen, die niemand mehr findet. */
        $alt = self::pfad( $uid );
        if ( $alt !== '' ) @unlink( $alt );

        $name = 'u' . $uid . '-' . substr( md5( uniqid( '', true ) ), 0, 12 )
              . '.' . self::BILD_TYPEN[ $typ ];
        $ziel = self::ordner() . '/' . $name;
        $ok = self::schreiben( $bild, $ziel, $typ );
        imagedestroy( $bild );
        if ( ! $ok ) return 'Das Bild liess sich nicht speichern.';
        @chmod( $ziel, 0640 );

        update_user_meta( $uid, self::META_BILD, $name );
        update_user_meta( $uid, self::META_VER, (string) time() );
        return true;
    }

    /** Das eigene Bild entfernen. */
    public static function bild_weg() {
        if ( ! self::darf() ) return 'Dieses Konto kommt in SwimSys nicht herein.';
        $uid = get_current_user_id();
        $alt = self::pfad( $uid );
        if ( $alt !== '' ) @unlink( $alt );
        delete_user_meta( $uid, self::META_BILD );
        delete_user_meta( $uid, self::META_VER );
        return true;
    }

    /** Mittig ein Quadrat ausschneiden und auf KANTE verkleinern. */
    private static function zuschneiden( $pfad, $typ ) {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) return null;
        switch ( $typ ) {
            case 'image/jpeg': $quelle = @imagecreatefromjpeg( $pfad ); break;
            case 'image/png':  $quelle = @imagecreatefrompng( $pfad );  break;
            case 'image/webp': $quelle = function_exists( 'imagecreatefromwebp' )
                                           ? @imagecreatefromwebp( $pfad ) : null; break;
            default: $quelle = null;
        }
        if ( ! $quelle ) return null;

        $bw = imagesx( $quelle );
        $bh = imagesy( $quelle );
        $kante = min( $bw, $bh );
        $ziel = imagecreatetruecolor( self::KANTE, self::KANTE );
        /* Durchsichtigkeit erhalten: Ein PNG mit freigestelltem Kopf wird
           sonst schwarz hinterlegt. */
        imagealphablending( $ziel, false );
        imagesavealpha( $ziel, true );
        imagefilledrectangle( $ziel, 0, 0, self::KANTE, self::KANTE,
            imagecolorallocatealpha( $ziel, 0, 0, 0, 127 ) );
        imagecopyresampled( $ziel, $quelle, 0, 0,
            (int) ( ( $bw - $kante ) / 2 ), (int) ( ( $bh - $kante ) / 2 ),
            self::KANTE, self::KANTE, $kante, $kante );
        imagedestroy( $quelle );
        return $ziel;
    }

    private static function schreiben( $bild, $ziel, $typ ) {
        switch ( $typ ) {
            case 'image/jpeg': return imagejpeg( $bild, $ziel, 88 );
            case 'image/png':  return imagepng( $bild, $ziel, 6 );
            case 'image/webp': return function_exists( 'imagewebp' )
                                      && imagewebp( $bild, $ziel, 88 );
        }
        return false;
    }
}
