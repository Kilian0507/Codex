<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Reflexionsbögen für den Schwimm-Bereich.
 *
 * Ablauf:
 *  1. Trainer pflegt Stammfragen pro Mannschaft (lsv07i_refl_fragen).
 *  2. Trainer erstellt für eine Saison Bögen für alle Schwimmer einer
 *     Mannschaft. Pro Bogen wird ein Zugangsschlüssel + Token erzeugt.
 *  3. Schwimmer öffnet den öffentlichen Link (?lsv07i_reflexion=<token>),
 *     gibt seinen Zugangsschlüssel ein, beantwortet Fragen + Wunschzeiten.
 *  4. Beim Absenden wird der Bogen auf 'abgegeben' gesetzt — der Link
 *     funktioniert dann nur noch lesend (bis der Trainer wieder freischaltet).
 *  5. Trainer sieht im Dashboard wer abgegeben hat und kann Bögen öffnen.
 *
 * Sicherheit: Ein Trainer sieht/erstellt nur für Mannschaften, die ihm
 * zugeordnet sind. Admin und Schwimmwart sehen alles.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Reflexion {

    /** Prüft ob der aktuelle User Zugriff auf eine Mannschaft hat (Trainer/Admin/SW). */
    public static function darf_mannschaft( $mannschaft_id ) {
        $mannschaft_id = absint( $mannschaft_id );
        if ( ! $mannschaft_id ) return false;
        if ( LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart() ) return true;

        $trainer_id = LSV07I_Access::get_trainer_id();
        if ( ! $trainer_id ) return false;
        $eigene = array_map( 'absint', LSV07I_DB::get_trainer_mannschaften( $trainer_id ) );
        return in_array( $mannschaft_id, $eigene, true );
    }

    /** Liste der Mannschaft-IDs, die der aktuelle User betreuen darf. */
    public static function meine_mannschaften() {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( LSV07I_Access::is_admin() || LSV07I_Access::is_schwimmwart() ) {
            return array_map( 'absint', $wpdb->get_col( "SELECT id FROM {$p}lsv07_gruppen" ) );
        }
        $trainer_id = LSV07I_Access::get_trainer_id();
        if ( ! $trainer_id ) return [];
        return array_map( 'absint', LSV07I_DB::get_trainer_mannschaften( $trainer_id ) );
    }

    /** Erzeugt einen gut lesbaren Zugangsschlüssel (z.B. "K7M-3QX-9PD"). */
    public static function gen_schluessel() {
        // Ohne mehrdeutige Zeichen (0/O, 1/I/L)
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ( $g = 0; $g < 3; $g++ ) {
            if ( $g > 0 ) $out .= '-';
            for ( $i = 0; $i < 3; $i++ ) {
                $out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
            }
        }
        return $out;
    }

    /** Stammfragen einer Mannschaft (nur aktive, sortiert). */
    public static function fragen( $mannschaft_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, frage, sort_order
               FROM {$p}lsv07i_refl_fragen
              WHERE mannschaft_id = %d AND aktiv = 1
              ORDER BY sort_order ASC, id ASC",
            absint( $mannschaft_id )
        ), ARRAY_A );
    }

    /**
     * Bestzeiten eines Schwimmers. Die Bestzeiten-Tabelle speichert den
     * CSV-Rohnamen (z.B. "Mustermann, Max" oder "Max Mustermann (2010)"),
     * nicht das saubere first_name/last_name. Deshalb matchen wir nicht über
     * einen exakten String-Vergleich, sondern über die gleiche normalisierte
     * Vor-/Nachname-Logik wie der Bestzeiten-Import.
     *
     * @param int $mannschaft_id
     * @param int $swimmer_id  Schwimmer-ID aus mv_swimmers
     */
    public static function bestzeiten( $mannschaft_id, $swimmer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $mannschaft_id = absint( $mannschaft_id );
        $swimmer_id    = absint( $swimmer_id );

        // Schwimmer-Stammdaten holen (für den Namens-Match)
        $sw = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, first_name, last_name, birth_date FROM {$p}mv_swimmers WHERE id = %d",
            $swimmer_id
        ), ARRAY_A );
        if ( ! $sw ) return [];

        // Alle Bestzeiten dieser Mannschaft laden
        $alle = $wpdb->get_results( $wpdb->prepare(
            "SELECT swimmer_name, strecke, zeit_raw, zeit_sek
               FROM {$p}lsv07i_bestzeiten
              WHERE mannschaft_id = %d
              ORDER BY zeit_sek ASC",
            $mannschaft_id
        ), ARRAY_A );
        if ( empty( $alle ) ) return [];

        // Distinct swimmer_names ermitteln und gegen den Schwimmer matchen
        $passende_namen = [];
        $gesehen = [];
        foreach ( $alle as $bz ) {
            $name = $bz['swimmer_name'];
            if ( isset( $gesehen[ $name ] ) ) continue;
            $gesehen[ $name ] = true;
            // Jahrgang aus dem Rohnamen ziehen, falls vorhanden "(JJJJ)"
            $jahrgang = 0;
            if ( preg_match( '/\((\d{4})\)/', $name, $m ) ) $jahrgang = (int) $m[1];
            $match = LSV07I_Ajax_Bestzeiten::match_schwimmer( $name, $jahrgang, [ $sw ] );
            // Akzeptiert sowohl 'exact' als auch 'fuzzy_doppelname' — beide haben eine id.
            // 'ambiguous' hat keine id; bei nur einem Vergleichs-Schwimmer ohnehin unmöglich.
            if ( $match && ! empty( $match['id'] ) ) $passende_namen[ $name ] = true;
        }
        if ( empty( $passende_namen ) ) return [];

        // Bestzeiten der passenden Namen zurückgeben
        $result = [];
        foreach ( $alle as $bz ) {
            if ( isset( $passende_namen[ $bz['swimmer_name'] ] ) ) {
                $result[] = [
                    'strecke'  => $bz['strecke'],
                    'zeit_raw' => $bz['zeit_raw'],
                    'zeit_sek' => $bz['zeit_sek'],
                ];
            }
        }
        return $result;
    }

    /** Voller Schwimmer-Name wie er in der Bestzeiten-Tabelle steht. */
    public static function swimmer_name( $swimmer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$p}mv_swimmers WHERE id = %d",
            absint( $swimmer_id )
        ), ARRAY_A );
        if ( ! $row ) return '';
        return trim( $row['first_name'] . ' ' . $row['last_name'] );
    }

    /** Geburtsdatum eines Schwimmers als Y-m-d (oder '' falls unbekannt). */
    public static function geburtsdatum( $swimmer_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $bd = $wpdb->get_var( $wpdb->prepare(
            "SELECT birth_date FROM {$p}mv_swimmers WHERE id = %d",
            absint( $swimmer_id )
        ) );
        if ( ! $bd ) return '';
        // Auf reines Datum normalisieren (falls Zeitanteil dranhängt)
        return substr( (string) $bd, 0, 10 );
    }

    /**
     * Prüft eine Geburtsdatum-Eingabe gegen das gespeicherte Datum.
     * Akzeptiert tolerant verschiedene Formate (TT.MM.JJJJ, JJJJ-MM-TT,
     * mit/ohne führende Nullen, Punkt/Schrägstrich/Bindestrich als Trenner).
     * Vergleich in konstanter Zeit gegen Timing-Angriffe.
     */
    public static function geburtsdatum_passt( $swimmer_id, $eingabe ) {
        $soll = self::geburtsdatum( $swimmer_id );
        if ( $soll === '' ) return false; // ohne hinterlegtes Datum kein Login möglich
        $soll_norm = self::norm_datum( $soll );
        $ist_norm  = self::norm_datum( $eingabe );
        if ( $soll_norm === '' || $ist_norm === '' ) return false;
        return hash_equals( $soll_norm, $ist_norm );
    }

    /** Normalisiert ein Datum zu "JJJJMMTT" (nur Ziffern) oder '' bei ungültig. */
    public static function norm_datum( $s ) {
        $s = trim( (string) $s );
        if ( $s === '' ) return '';
        // ISO: 2010-05-01 oder 2010/05/01
        if ( preg_match( '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $m ) ) {
            return sprintf( '%04d%02d%02d', $m[1], $m[2], $m[3] );
        }
        // Deutsch: 01.05.2010 oder 1.5.2010 oder 01/05/2010
        if ( preg_match( '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m ) ) {
            return sprintf( '%04d%02d%02d', $m[3], $m[2], $m[1] );
        }
        // Reine Ziffern: 20100501 oder 01052010
        if ( preg_match( '/^\d{8}$/', $s ) ) {
            // Heuristik: beginnt mit 19/20 → JJJJMMTT, sonst TTMMJJJJ
            if ( preg_match( '/^(19|20)/', $s ) ) return $s;
            return substr( $s, 4, 4 ) . substr( $s, 2, 2 ) . substr( $s, 0, 2 );
        }
        return '';
    }

    /** Bogen per Token laden (für die öffentliche Seite). */
    public static function bogen_by_token( $token ) {
        global $wpdb;
        $p = $wpdb->prefix;
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_refl_bogen WHERE token = %s LIMIT 1", $token
        ), ARRAY_A );
    }

    // ── Schlüssel-Hashing ──────────────────────────────────────────────────────
    /** Normalisiert einen Schlüssel: Großbuchstaben, nur A-Z0-9 (ohne Trenner). */
    public static function norm_key( $s ) {
        return preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $s ) );
    }

    /**
     * Erzeugt den Lookup-Hash eines Schlüssels. HMAC-SHA256 mit einem aus den
     * WP-Salts abgeleiteten Schlüssel (liegt in wp-config.php, getrennt von der DB).
     * Wer nur die DB hat, kann aus dem Hash den Schlüssel nicht rekonstruieren.
     */
    public static function schluessel_hash( $schluessel ) {
        $norm = self::norm_key( $schluessel );
        return hash_hmac( 'sha256', $norm, self::secret( 'refl_key' ) );
    }

    // ── Verschlüsselung at-rest (AES-256-GCM) ──────────────────────────────────
    /** Leitet einen geheimen 32-Byte-Schlüssel für einen Zweck aus den WP-Salts ab. */
    private static function secret( $purpose ) {
        // wp_salt() ist installationsspezifisch und steht in wp-config.php,
        // nicht in der Datenbank → DB-Leak allein gibt den Schlüssel nicht preis.
        $base = function_exists( 'wp_salt' ) ? wp_salt( 'secure_auth' ) : 'lsv07i-fallback-salt';
        return hash( 'sha256', 'lsv07i|' . $purpose . '|' . $base, true ); // 32 Byte (raw)
    }

    /**
     * Verschlüsselt einen String (AES-256-GCM). Rückgabe: "v1:" + base64(iv|tag|cipher).
     * Bei leerem Input wird '' zurückgegeben (nicht verschlüsselt).
     */
    public static function encrypt( $plain ) {
        $plain = (string) $plain;
        if ( $plain === '' ) return '';
        if ( ! function_exists( 'openssl_encrypt' ) ) return $plain; // Fallback: Klartext
        $key = self::secret( 'refl_data' );
        $iv  = random_bytes( 12 ); // GCM: 96-bit IV empfohlen
        $tag = '';
        $cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( $cipher === false ) return $plain;
        return 'v1:' . base64_encode( $iv . $tag . $cipher );
    }

    /** Entschlüsselt einen mit encrypt() erzeugten String. Klartext wird unverändert zurückgegeben. */
    public static function decrypt( $stored ) {
        $stored = (string) $stored;
        if ( strpos( $stored, 'v1:' ) !== 0 ) return $stored; // unverschlüsselt (Altdaten/Fallback)
        if ( ! function_exists( 'openssl_decrypt' ) ) return '';
        $raw = base64_decode( substr( $stored, 3 ), true );
        if ( $raw === false || strlen( $raw ) < 29 ) return '';
        $iv     = substr( $raw, 0, 12 );
        $tag    = substr( $raw, 12, 16 );
        $cipher = substr( $raw, 28 );
        $key = self::secret( 'refl_data' );
        $plain = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        return $plain === false ? '' : $plain;
    }
}
