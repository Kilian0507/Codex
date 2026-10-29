<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reflexionsbögen: Was ein Schwimmer sich für die Saison vornimmt.
 *
 * Je Mannschaft gibt es Stammfragen; je Saison und Schwimmer einen
 * Bogen mit einem Schlüssel, mit dem der Schwimmer ihn ausfüllt. Die
 * Antworten liegen **verschlüsselt** in der Datenbank.
 *
 * Drei Dinge, die hier genau wie im internen Bereich sein MÜSSEN, weil
 * beide Plugins dieselben Zeilen lesen:
 *
 * 1. Der Schlüssel liegt nur als HMAC in der Spalte `schluessel_hash`;
 *    im Klartext kommt er genau einmal zurück, beim Erzeugen.
 * 2. Die Antworten sind mit AES-256-GCM verschlüsselt, der Schlüssel
 *    wird aus `wp_salt('secure_auth')` abgeleitet — er steht in
 *    wp-config.php, nicht in der Datenbank. Ein gestohlener
 *    Datenbankabzug allein gibt die Antworten nicht her.
 * 3. Das Format ist `v1:` + base64(iv|tag|cipher).
 *
 * Diese drei Dinge sind ein Protokoll, keine Regel: Sie stehen hier
 * noch einmal, weil zwei Programme sich darauf verständigen müssen.
 * Weicht eine Fassung ab, sind die Antworten der anderen unlesbar —
 * deshalb prüft `sysreflexionstest.php` sie gegen die Quelle von
 * lsv07-intern.
 *
 * Was hier anders ist:
 *
 * 1. **Eine Frage wird stillgelegt, nicht gelöscht.** Im internen
 *    Bereich löscht das Speichern alle Fragen und schreibt sie neu —
 *    damit sind die Nummern weg, auf die abgegebene Bögen zeigen.
 * 2. **Ein Schlüssel lässt sich neu ausgeben.** Dort gibt es ihn
 *    einmal; wer ihn verliert, bekommt keinen Bogen mehr.
 * 3. **Jede Änderung steht im Protokoll** — dort nur das Erzeugen.
 */
class LSV07S_Reflexion {

    /** Zeichen ohne Verwechslungsgefahr: kein 0/O, kein 1/I/L. */
    const ZEICHEN = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    // ── Schalter ─────────────────────────────────────────────────────────

    /**
     * Ein Schalter für die Reflexionsbögen.
     *
     * Ansehen und Verwalten — Fragen setzen, Bögen anlegen, Schlüssel
     * ausgeben, freischalten, archivieren, löschen — hängen an einem
     * Häkchen. Was bleibt, ist die Mannschaftsgrenze: Bögen einer
     * fremden Mannschaft sieht niemand.
     *
     * Dass ein Bogen gelesen wurde, steht trotzdem im Protokoll. Das
     * ist der Schutz, der hier wirklich trägt: Es ist nachvollziehbar,
     * wer nachgesehen hat, was ein Kind sich vorgenommen hat.
     */
    private static function darf_reflexion() {
        return LSV07S_Schalter::an( 'schwimmen/reflexion' );
    }

    private static function darf_mannschaft( $mannschaft_id ) {
        return LSV07S_Rechte::darf_mannschaft( LSV07S_Sparte::SCHWIMMEN, $mannschaft_id );
    }

    // ── Das Protokoll mit dem internen Bereich ───────────────────────────

    /**
     * Derselbe abgeleitete Schlüssel wie im internen Bereich.
     *
     * `wp_salt('secure_auth')` steht in wp-config.php. Ein Abzug der
     * Datenbank allein gibt die Antworten damit nicht her.
     */
    private static function geheim( $zweck ) {
        $basis = function_exists( 'wp_salt' )
            ? wp_salt( 'secure_auth' ) : 'lsv07i-fallback-salt';
        return hash( 'sha256', 'lsv07i|' . $zweck . '|' . $basis, true );
    }

    /** Der Hash eines Schlüssels — dieselbe Form wie im internen Bereich. */
    public static function schluessel_hash( $schluessel ) {
        return hash_hmac( 'sha256', self::norm_schluessel( $schluessel ),
            self::geheim( 'refl_key' ) );
    }

    /** Ein Schlüssel ohne Trennstriche und in Großbuchstaben. */
    public static function norm_schluessel( $s ) {
        return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $s ) );
    }

    /** Ein neuer Schlüssel in der Form ABC-DEF-GHJ. */
    public static function neuer_schluessel() {
        $teile = [];
        for ( $g = 0; $g < 3; $g++ ) {
            $t = '';
            for ( $i = 0; $i < 3; $i++ ) {
                $t .= self::ZEICHEN[ random_int( 0, strlen( self::ZEICHEN ) - 1 ) ];
            }
            $teile[] = $t;
        }
        return implode( '-', $teile );
    }

    /** Verschlüsseln wie im internen Bereich: v1: + base64(iv|tag|cipher). */
    public static function verschluesseln( $klartext ) {
        $klartext = (string) $klartext;
        if ( $klartext === '' ) return '';
        if ( ! function_exists( 'openssl_encrypt' ) ) return $klartext;
        $key = self::geheim( 'refl_data' );
        $iv  = random_bytes( 12 );
        $tag = '';
        $c = openssl_encrypt( $klartext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        if ( $c === false ) return $klartext;
        return 'v1:' . base64_encode( $iv . $tag . $c );
    }

    /** Entschlüsseln. Was nicht mit v1: beginnt, ist Klartext aus der Altzeit. */
    public static function entschluesseln( $gespeichert ) {
        $g = (string) $gespeichert;
        if ( strpos( $g, 'v1:' ) !== 0 ) return $g;
        if ( ! function_exists( 'openssl_decrypt' ) ) return '';
        $roh = base64_decode( substr( $g, 3 ), true );
        if ( $roh === false || strlen( $roh ) < 29 ) return '';
        $klar = openssl_decrypt( substr( $roh, 28 ), 'aes-256-gcm',
            self::geheim( 'refl_data' ), OPENSSL_RAW_DATA,
            substr( $roh, 0, 12 ), substr( $roh, 12, 16 ) );
        return $klar === false ? '' : $klar;
    }

    // ── Stammfragen ──────────────────────────────────────────────────────

    /** Die aktiven Fragen einer Mannschaft. */
    public static function fragen( $mannschaft_id, $auch_stille = false ) {
        global $wpdb;
        /* Auch hier, nicht nur in den Aufrufern: Eine öffentliche
           Methode, die ohne Schalter antwortet, wird irgendwann von
           einer Stelle gerufen, die nicht geprüft hat. */
        if ( ! self::darf_reflexion() ) return [];
        if ( ! LSV07S_Tabellen::da( 'refl_fragen' ) ) return [];
        $wo = $auch_stille ? '' : ' AND aktiv = 1';
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'refl_fragen' )
            . " WHERE mannschaft_id = %d$wo ORDER BY sort_order ASC, id ASC",
            (int) $mannschaft_id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id'    => (int) $z['id'],
            'frage' => (string) $z['frage'],
            'sort'  => (int) $z['sort_order'],
            'aktiv' => (int) $z['aktiv'] === 1,
        ], $zeilen );
    }

    /**
     * Die Fragen einer Mannschaft setzen.
     *
     * Eine Frage, die nicht mehr mitkommt, wird **stillgelegt** und
     * nicht gelöscht: Abgegebene Bögen zeigen mit `frage_id` auf sie,
     * und ohne die Zeile stünde dort eine Antwort ohne Frage. Im
     * internen Bereich löscht das Speichern alle Fragen der Mannschaft
     * und schreibt sie neu — mit neuen Nummern.
     */
    public static function fragen_setzen( $mannschaft_id, array $liste ) {
        global $wpdb;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! self::darf_mannschaft( $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( ! LSV07S_Tabellen::da( 'refl_fragen' ) ) return 'Die Tabelle fehlt.';
        if ( count( $liste ) > 50 ) return 'Mehr als 50 Fragen sind nicht vorgesehen.';

        $t = LSV07S_Tabellen::t( 'refl_fragen' );
        $vorher = self::fragen( $mannschaft_id, true );
        $bekannt = [];
        foreach ( $vorher as $f ) $bekannt[ $f['id'] ] = $f;

        $bleiben = [];
        $sort = 0;
        $neu = 0;
        foreach ( $liste as $e ) {
            $text = trim( sanitize_text_field( (string) ( is_array( $e )
                ? ( $e['frage'] ?? '' ) : $e ) ) );
            if ( $text === '' ) continue;
            $text = mb_substr( $text, 0, 500 );
            $id = is_array( $e ) ? absint( $e['id'] ?? 0 ) : 0;

            if ( $id && isset( $bekannt[ $id ] ) ) {
                $wpdb->update( $t, [ 'frage' => $text, 'sort_order' => $sort, 'aktiv' => 1 ],
                    [ 'id' => $id ], [ '%s', '%d', '%d' ], [ '%d' ] );
                $bleiben[] = $id;
            } else {
                $wpdb->insert( $t, [
                    'mannschaft_id' => $mannschaft_id,
                    'frage' => $text,
                    'sort_order' => $sort,
                    'aktiv' => 1,
                    'erstellt_von' => get_current_user_id(),
                    'erstellt_am' => LSV07S_Datum::jetzt(),
                ], [ '%d', '%s', '%d', '%d', '%d', '%s' ] );
                $bleiben[] = (int) $wpdb->insert_id;
                $neu++;
            }
            $sort++;
        }

        $still = 0;
        foreach ( $vorher as $f ) {
            if ( in_array( $f['id'], $bleiben, true ) ) continue;
            if ( ! $f['aktiv'] ) continue;
            $wpdb->update( $t, [ 'aktiv' => 0 ], [ 'id' => $f['id'] ], [ '%d' ], [ '%d' ] );
            $still++;
        }

        LSV07S_Log::schreibe( 'reflexion.fragen', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'mannschaft',
            'ziel_id' => $mannschaft_id,
            'details' => count( $bleiben ) . ' aktiv, ' . $neu . ' neu, '
                       . $still . ' stillgelegt',
        ] );
        return [
            'message' => count( $bleiben ) . ' Frage(n) gespeichert.'
                       . ( $still ? ' ' . $still . ' nicht mehr verwendete bleiben '
                                  . 'stillgelegt stehen — abgegebene Bögen zeigen '
                                  . 'darauf.' : '' ),
            'fragen' => self::fragen( $mannschaft_id ),
        ];
    }

    // ── Bögen ────────────────────────────────────────────────────────────

    /**
     * Für jeden Schwimmer der Mannschaft einen Bogen der laufenden
     * Saison anlegen.
     *
     * Die Klartextschlüssel kommen genau einmal zurück. Wer sie nicht
     * aufschreibt, gibt sie neu aus (`schluessel_neu`).
     */
    public static function boegen_anlegen( $mannschaft_id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! self::darf_mannschaft( $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( ! LSV07S_Tabellen::da( 'reflexion' ) ) return 'Die Tabelle fehlt.';

        $saison = LSV07S_Datum::saison_aktiv();
        if ( ! $saison ) {
            return 'Es ist keine Saison aktiv. Bitte zuerst eine Saison aktivieren — '
                 . 'ein Bogen gehört zu einer Saison.';
        }
        $fragen = self::fragen( $mannschaft_id );
        if ( $fragen === [] ) {
            return 'Für diese Mannschaft sind keine Fragen angelegt. Ein Bogen ohne '
                 . 'Fragen ist ein leeres Blatt.';
        }
        $kader = LSV07S_Mannschaft::sportler( $sparte, $mannschaft_id );
        if ( $kader === [] ) return 'In dieser Mannschaft ist niemand.';

        $t = LSV07S_Tabellen::t( 'reflexion' );
        $snapshot = wp_json_encode( array_map( fn( $f ) => [
            'id' => $f['id'], 'frage' => $f['frage'], 'sort_order' => $f['sort'],
        ], $fragen ) );

        $neu = 0;
        $schon = 0;
        $schluessel = [];
        $ohne_geburt = [];
        foreach ( $kader as $s ) {
            $da = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $t WHERE saison_id = %d AND swimmer_id = %d LIMIT 1",
                (int) $saison['id'], (int) $s['id'] ) );
            if ( $da ) { $schon++; continue; }

            /* Ohne Geburtsdatum fehlt der zweite Faktor beim Ausfüllen —
               der Bogen entsteht trotzdem, aber es wird gesagt. Gefragt
               wird immer die Tabelle: Was die Kaderliste mitgibt, ist für
               die Anzeige gedacht und kann '0000-00-00' sein. */
            if ( ! self::geburtsdatum_da( (int) $s['id'] ) ) {
                $ohne_geburt[] = (string) $s['name'];
            }

            $klar = self::eindeutiger_schluessel();
            $wpdb->insert( $t, [
                'saison_id' => (int) $saison['id'],
                'mannschaft_id' => $mannschaft_id,
                'swimmer_id' => (int) $s['id'],
                'swimmer_name' => (string) $s['name'],
                'schluessel_hash' => self::schluessel_hash( $klar ),
                'token' => bin2hex( random_bytes( 16 ) ),
                'status' => 'offen',
                'fragen_snapshot' => $snapshot,
                'erstellt_von' => get_current_user_id(),
                'erstellt_am' => LSV07S_Datum::jetzt(),
            ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ] );
            $schluessel[] = [ 'name' => (string) $s['name'], 'schluessel' => $klar ];
            $neu++;
        }

        LSV07S_Log::schreibe( 'reflexion.boegen', [
            'bereich' => $sparte, 'ziel_typ' => 'mannschaft', 'ziel_id' => $mannschaft_id,
            'details' => $neu . ' angelegt, ' . $schon . ' gab es schon',
        ] );

        return [
            'neu' => $neu, 'schon' => $schon,
            /* Nur JETZT im Klartext — danach steht in der Datenbank nur
               der Hash. */
            'schluessel' => $schluessel,
            'ohne_geburtsdatum' => $ohne_geburt,
            'message' => $neu . ' Bogen/Bögen angelegt'
                       . ( $schon ? ', ' . $schon . ' gab es schon' : '' ) . '.'
                       . ( $schluessel ? ' Die Schlüssel stehen NUR JETZT hier — bitte '
                                       . 'weitergeben oder aufschreiben.' : '' )
                       . ( $ohne_geburt ? ' Ohne Geburtsdatum können sich diese '
                                        . 'Schwimmer nicht anmelden: '
                                        . implode( ', ', $ohne_geburt ) . '.' : '' ),
        ];
    }

    /** Ein Schlüssel, dessen Hash noch nicht vergeben ist. */
    private static function eindeutiger_schluessel() {
        global $wpdb;
        $t = LSV07S_Tabellen::t( 'reflexion' );
        for ( $i = 0; $i < 20; $i++ ) {
            $k = self::neuer_schluessel();
            $da = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $t WHERE schluessel_hash = %s LIMIT 1",
                self::schluessel_hash( $k ) ) );
            if ( ! $da ) return $k;
        }
        return self::neuer_schluessel() . '-' . random_int( 10, 99 );
    }

    /**
     * Hat dieser Schwimmer ein brauchbares Geburtsdatum?
     *
     * `mv_swimmers.birth_date` ist NOT NULL; eine Zeile ohne Datum steht
     * dort als '0000-00-00'. Leer ist also nicht dasselbe wie „fehlt",
     * und `empty()` auf das Feld sagt nichts.
     */
    private static function geburtsdatum_da( $swimmer_id ) {
        $g = self::geburtsdatum( $swimmer_id );
        return $g !== '' && strpos( $g, '0000' ) !== 0;
    }

    /** Das Geburtsdatum eines Schwimmers — der zweite Faktor beim Ausfüllen. */
    private static function geburtsdatum( $swimmer_id ) {
        global $wpdb;
        $gb = LSV07S_Sparte::feld( LSV07S_Sparte::SCHWIMMEN, 'geburt' );
        $t  = LSV07S_Tabellen::t( 'schwimmer' );
        return substr( (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT $gb FROM $t WHERE id = %d LIMIT 1", (int) $swimmer_id ) ), 0, 10 );
    }

    /**
     * Stimmt das eingetippte Geburtsdatum?
     *
     * Der zweite Faktor beim Ausfüllen: Selbst wer einen Schlüssel
     * abfängt, kommt ohne das Datum nicht an den Bogen. Verglichen wird
     * mit `hash_equals` — die Laufzeit soll nichts verraten.
     *
     * Ohne hinterlegtes Datum ist die Antwort **false**: Dann gibt es
     * keinen zweiten Faktor, und einer allein ist keine zwei. Angelegt
     * wird so ein Bogen trotzdem, und `boegen_anlegen()` sagt, wen es
     * betrifft.
     */
    public static function geburtsdatum_passt( $swimmer_id, $eingabe ) {
        $soll = self::norm_datum( self::geburtsdatum( $swimmer_id ) );
        $ist  = self::norm_datum( $eingabe );
        if ( $soll === '' || $ist === '' ) return false;
        return hash_equals( $soll, $ist );
    }

    /**
     * Ein Datum als JJJJMMTT — oder '', wenn es keines ist.
     *
     * Dieselben Schreibweisen wie im internen Bereich, damit dieselbe
     * Eingabe auf beiden Seiten geht: 2010-05-01, 01.05.2010, 1.5.2010,
     * 20100501 und 01052010.
     */
    public static function norm_datum( $s ) {
        $s = trim( (string) $s );
        if ( $s === '' ) return '';
        $roh = '';
        if ( preg_match( '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $m ) ) {
            $roh = sprintf( '%04d%02d%02d', $m[1], $m[2], $m[3] );
        } elseif ( preg_match( '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m ) ) {
            $roh = sprintf( '%04d%02d%02d', $m[3], $m[2], $m[1] );
        } elseif ( preg_match( '/^\d{8}$/', $s ) ) {
            /* 20100501 oder 01052010 — zu unterscheiden an der ersten Stelle. */
            $roh = (int) substr( $s, 0, 2 ) > 31
                ? $s
                : substr( $s, 4, 4 ) . substr( $s, 2, 2 ) . substr( $s, 0, 2 );
        }
        if ( $roh === '' ) return '';
        /* Ein Datum, das es nicht gibt, ist keines. `birth_date` ist NOT
           NULL: Eine Zeile ohne Geburtsdatum steht dort als
           '0000-00-00'. Ohne diese Prüfung wird daraus '00000000' — und
           wer '00.00.0000' eintippt, kommt damit in den Bogen. Der
           zweite Faktor wäre dort keiner. */
        if ( ! checkdate( (int) substr( $roh, 4, 2 ), (int) substr( $roh, 6, 2 ),
                          (int) substr( $roh, 0, 4 ) ) ) {
            return '';
        }
        return $roh;
    }

    /**
     * Der Link auf die Seite, auf der ein Schwimmer seinen Bogen
     * ausfüllt.
     *
     * Für alle derselbe: Er trägt nichts Personenbezogenes, der
     * Schlüssel wird dort eingetippt. Welche Seite den Bogen zeigt,
     * steht in derselben Option, die der interne Bereich benutzt — es
     * ist dieselbe Seite.
     */
    public static function oeffentlicher_link() {
        $basis = '';
        $seite = (int) get_option( 'lsv07i_reflexion_page_id', 0 );
        if ( $seite && function_exists( 'get_permalink' ) ) {
            $basis = (string) get_permalink( $seite );
        }
        if ( $basis === '' ) {
            $basis = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
        }
        return add_query_arg( LSV07S_Reflexion_Public::SCHALTER, '1', $basis );
    }

    /**
     * Einen neuen Schlüssel ausgeben.
     *
     * Der alte gilt dann nicht mehr. Im internen Bereich gibt es den
     * Schlüssel einmal; wer ihn verliert, kommt an seinen Bogen nicht
     * mehr heran — und der Trainer legt einen zweiten an, den niemand
     * ausfüllt.
     */
    public static function schluessel_neu( $bogen_id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $bogen_id = (int) $bogen_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $t = LSV07S_Tabellen::t( 'reflexion' );
        $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $bogen_id ), ARRAY_A );
        if ( ! $b ) return 'Diesen Bogen gibt es nicht.';
        if ( ! self::darf_mannschaft( (int) $b['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( (string) $b['status'] !== 'offen' ) {
            return 'Dieser Bogen ist abgegeben. Ein neuer Schlüssel würde daran '
                 . 'nichts ändern — zum Richtigstellen zuerst freischalten.';
        }

        $klar = self::eindeutiger_schluessel();
        $wpdb->update( $t, [ 'schluessel_hash' => self::schluessel_hash( $klar ) ],
            [ 'id' => $bogen_id ], [ '%s' ], [ '%d' ] );

        LSV07S_Log::schreibe( 'reflexion.schluessel', [
            'bereich' => $sparte, 'ziel_typ' => 'person',
            'ziel_id' => (int) $b['swimmer_id'],
            'ziel_name' => (string) $b['swimmer_name'],
            'details' => 'neuer Schlüssel ausgegeben',
        ] );
        return [ 'schluessel' => $klar,
                 'message' => 'Neuer Schlüssel: ' . $klar . ' — der alte gilt nicht '
                            . 'mehr. Er steht nur jetzt hier.' ];
    }

    /** Die Bögen einer Mannschaft in der laufenden Saison. */
    public static function uebersicht( $mannschaft_id, $saison_id = 0, $archiv = false ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! self::darf_mannschaft( $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( ! LSV07S_Tabellen::da( 'reflexion' ) ) return [];

        $saison = $saison_id
            ? [ 'id' => (int) $saison_id ] : LSV07S_Datum::saison_aktiv();
        if ( ! $saison ) return [ 'saison' => null, 'boegen' => [],
                                  'fragen' => self::fragen( $mannschaft_id ) ];

        $t = LSV07S_Tabellen::t( 'reflexion' );
        /* Ein archivierter Bogen ist aus dem Weg, nicht weg. Er steht in
           der Übersicht nur, wenn man ihn ausdrücklich sehen will. */
        $wo = 'mannschaft_id = %d AND saison_id = %d'
            . ( $archiv ? " AND status = 'archiviert'" : " AND status <> 'archiviert'" );
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE $wo ORDER BY swimmer_name ASC",
            $mannschaft_id, (int) $saison['id'] ), ARRAY_A );

        $offen = 0;
        $boegen = [];
        foreach ( $zeilen as $z ) {
            if ( (string) $z['status'] === 'offen' ) $offen++;
            $boegen[] = self::zeile( $z );
        }
        $im_archiv = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE mannschaft_id = %d AND saison_id = %d"
            . " AND status = 'archiviert'", $mannschaft_id, (int) $saison['id'] ) );
        return [
            'mannschaft' => LSV07S_Mannschaft::holen( $sparte, $mannschaft_id ),
            'saison' => LSV07S_Datum::saison_aktiv(),
            'fragen' => self::fragen( $mannschaft_id ),
            'boegen' => $boegen,
            'offen' => $offen,
            'archiv' => (bool) $archiv,
            'im_archiv' => $im_archiv,
            /* Die Oberfläche zeigt nur, was das Konto auch darf. Geprüft
               wird es trotzdem noch einmal, wenn der Endpunkt gerufen
               wird — dies hier blendet aus, es entscheidet nicht. */
            'darf_aendern' => self::darf_reflexion(),
            /* Der Link, den die Schwimmer bekommen. Für alle derselbe. */
            'link' => self::oeffentlicher_link(),
        ];
    }

    /** Eine Zeile der Übersicht — die Namen der Zustände an einer Stelle. */
    private static function zeile( array $z ) {
        $stand = (string) $z['status'];
        $namen = [ 'offen' => 'Offen', 'abgegeben' => 'Abgegeben',
                   'archiviert' => 'Archiviert' ];
        return [
            'id'          => (int) $z['id'],
            'person_id'   => (int) $z['swimmer_id'],
            'name'        => (string) $z['swimmer_name'],
            'status'      => $stand,
            'status_name' => $namen[ $stand ] ?? $stand,
            'am'          => LSV07S_Datum::de_zeit( $z['abgegeben_am'] ?? null, '' ),
        ];
    }

    /**
     * Einen abgegebenen Bogen wieder zum Ausfüllen freigeben.
     *
     * Die Antworten bleiben stehen; der Schwimmer kann sie mit seinem
     * Schlüssel richtigstellen. Dass jemand das getan hat, steht im
     * Protokoll — danach ist der Bogen wieder änderbar.
     */
    public static function freischalten( $bogen_id ) {
        global $wpdb;
        $bogen_id = (int) $bogen_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $t = LSV07S_Tabellen::t( 'reflexion' );
        $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $bogen_id ), ARRAY_A );
        if ( ! $b ) return 'Diesen Bogen gibt es nicht.';
        if ( ! self::darf_mannschaft( (int) $b['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( (string) $b['status'] === 'offen' ) {
            return 'Dieser Bogen ist ohnehin offen.';
        }
        $wpdb->update( $t, [ 'status' => 'offen', 'abgegeben_am' => null ],
            [ 'id' => $bogen_id ], [ '%s', '%s' ], [ '%d' ] );
        LSV07S_Log::schreibe( 'reflexion.freigeschaltet', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'person',
            'ziel_id' => (int) $b['swimmer_id'], 'ziel_name' => (string) $b['swimmer_name'],
        ] );
        return [ 'message' => 'Wieder offen. Die Antworten bleiben stehen und lassen '
                            . 'sich mit demselben Schlüssel ändern.' ];
    }

    /**
     * Die Antworten aller abgegebenen Bögen, nach Frage gebündelt.
     *
     * Hier wird **entschlüsselt**: Im internen Bereich tut die Auswertung
     * das nicht, und in jeder Zeile steht dort `v1:` und Base64 statt
     * einer Antwort.
     *
     * Dass jemand die Auswertung geöffnet hat, steht im Protokoll. Es ist
     * das, was eine ganze Mannschaft sich vorgenommen hat, auf einem
     * Blatt — das soll nachvollziehbar bleiben.
     */
    public static function auswertung( $mannschaft_id, $saison_id = 0 ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $mannschaft_id = (int) $mannschaft_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! self::darf_mannschaft( $mannschaft_id ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( ! LSV07S_Tabellen::da( 'reflexion' )
             || ! LSV07S_Tabellen::da( 'refl_antwort' ) ) {
            return 'Die Tabelle fehlt.';
        }
        $saison = $saison_id
            ? [ 'id' => (int) $saison_id ] : LSV07S_Datum::saison_aktiv();
        if ( ! $saison ) return 'Es ist keine Saison aktiv.';

        $t = LSV07S_Tabellen::t( 'reflexion' );
        $a = LSV07S_Tabellen::t( 'refl_antwort' );
        $gesamt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE mannschaft_id = %d AND saison_id = %d",
            $mannschaft_id, (int) $saison['id'] ) );
        $fertig = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE mannschaft_id = %d AND saison_id = %d"
            . " AND status <> 'offen'", $mannschaft_id, (int) $saison['id'] ) );

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT ant.frage_id, ant.frage_text, ant.antwort_text, ant.sort_order,"
            . " b.swimmer_name FROM $a ant JOIN $t b ON b.id = ant.bogen_id"
            . ' WHERE b.mannschaft_id = %d AND b.saison_id = %d'
            . " AND b.status <> 'offen' AND ant.typ = 'frage'"
            . ' ORDER BY ant.sort_order ASC, ant.frage_id ASC, b.swimmer_name ASC',
            $mannschaft_id, (int) $saison['id'] ), ARRAY_A );

        $fragen = [];
        foreach ( $zeilen as $z ) {
            $f = trim( (string) ( $z['frage_text'] ?? '' ) );
            if ( $f === '' ) continue;
            if ( ! isset( $fragen[ $f ] ) ) {
                $fragen[ $f ] = [ 'frage' => $f, 'antworten' => [] ];
            }
            $text = trim( self::entschluesseln( $z['antwort_text'] ?? '' ) );
            if ( $text === '' ) continue;
            $fragen[ $f ]['antworten'][] = [
                'name' => (string) $z['swimmer_name'], 'text' => $text,
            ];
        }

        LSV07S_Log::schreibe( 'reflexion.auswertung', [
            'bereich' => $sparte, 'ziel_typ' => 'mannschaft', 'ziel_id' => $mannschaft_id,
            'details' => $fertig . ' von ' . $gesamt . ' Bögen gelesen',
        ] );

        return [
            'mannschaft' => LSV07S_Mannschaft::holen( $sparte, $mannschaft_id ),
            'gesamt' => $gesamt,
            'abgegeben' => $fertig,
            'fragen' => array_values( $fragen ),
        ];
    }

    /**
     * Einen abgegebenen Bogen ansehen.
     *
     * Die Antworten werden hier entschlüsselt. Dass jemand einen Bogen
     * geöffnet hat, steht im Protokoll: Es ist, was ein Kind sich
     * vorgenommen hat, und niemand soll unbemerkt mitlesen.
     */
    public static function bogen( $bogen_id ) {
        global $wpdb;
        $sparte = LSV07S_Sparte::SCHWIMMEN;
        $bogen_id = (int) $bogen_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $t = LSV07S_Tabellen::t( 'reflexion' );
        $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $bogen_id ), ARRAY_A );
        if ( ! $b ) return 'Diesen Bogen gibt es nicht.';
        if ( ! self::darf_mannschaft( (int) $b['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }

        $antworten = [];
        if ( LSV07S_Tabellen::da( 'refl_antwort' ) ) {
            $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM ' . LSV07S_Tabellen::t( 'refl_antwort' )
                . ' WHERE bogen_id = %d ORDER BY typ ASC, sort_order ASC, id ASC',
                $bogen_id ), ARRAY_A );
            foreach ( $zeilen as $z ) {
                $antworten[] = [
                    'typ'   => (string) $z['typ'],
                    'frage' => (string) ( $z['frage_text'] ?? '' ),
                    'text'  => self::entschluesseln( $z['antwort_text'] ?? '' ),
                    'strecke' => (string) ( $z['strecke'] ?? '' ),
                    'zeit'  => (string) ( $z['zeit_aktuell'] ?? '' ),
                    'wunsch'=> self::entschluesseln( $z['wunschzeit'] ?? '' ),
                ];
            }
        }

        LSV07S_Log::schreibe( 'reflexion.gelesen', [
            'bereich' => $sparte, 'ziel_typ' => 'person',
            'ziel_id' => (int) $b['swimmer_id'],
            'ziel_name' => (string) $b['swimmer_name'],
        ] );

        return [
            'id' => $bogen_id,
            'name' => (string) $b['swimmer_name'],
            'person_id' => (int) $b['swimmer_id'],
            'mannschaft_id' => (int) $b['mannschaft_id'],
            'status' => (string) $b['status'],
            'am' => LSV07S_Datum::de_zeit( $b['abgegeben_am'] ?? null, '' ),
            'antworten' => $antworten,
        ];
    }

    /**
     * Einen Bogen archivieren oder löschen.
     *
     * Archivieren ist der Normalfall: Der Bogen verschwindet aus der
     * Übersicht und bleibt lesbar. Gelöscht wird nur auf Wunsch der
     * Person — dann gehen auch die Antworten.
     */
    public static function archivieren( $bogen_id, $an = true ) {
        global $wpdb;
        $bogen_id = (int) $bogen_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $t = LSV07S_Tabellen::t( 'reflexion' );
        if ( ! LSV07S_Mannschaft::spalte_da( $t, 'archiviert_am' ) ) {
            return 'Diese Anlage kennt das Archiv noch nicht.';
        }
        $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $bogen_id ), ARRAY_A );
        if ( ! $b ) return 'Diesen Bogen gibt es nicht.';
        if ( ! self::darf_mannschaft( (int) $b['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        if ( $an ) {
            if ( (string) $b['status'] === 'offen' ) {
                return 'Ein offener Bogen wird nicht archiviert — er wartet noch auf '
                     . 'die Antworten.';
            }
            if ( (string) $b['status'] === 'archiviert' ) {
                return 'Dieser Bogen ist schon im Archiv.';
            }
            /* Derselbe Zustand und dieselbe Spalte wie im internen
               Bereich: Beide Oberflächen lesen diese Zeile. */
            $wpdb->update( $t, [ 'status' => 'archiviert',
                                 'archiviert_am' => LSV07S_Datum::jetzt() ],
                [ 'id' => $bogen_id ], [ '%s', '%s' ], [ '%d' ] );
        } else {
            if ( (string) $b['status'] !== 'archiviert' ) {
                return 'Dieser Bogen ist nicht im Archiv.';
            }
            /* Zurück in den Zustand, aus dem er kam: abgegeben, wenn ein
               Abgabezeitpunkt steht, sonst offen. */
            $zurueck = ! empty( $b['abgegeben_am'] ) ? 'abgegeben' : 'offen';
            $wpdb->update( $t, [ 'status' => $zurueck, 'archiviert_am' => null ],
                [ 'id' => $bogen_id ], [ '%s', '%s' ], [ '%d' ] );
        }
        LSV07S_Log::schreibe( $an ? 'reflexion.archiviert' : 'reflexion.entarchiviert', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'person',
            'ziel_id' => (int) $b['swimmer_id'], 'ziel_name' => (string) $b['swimmer_name'],
        ] );
        return [ 'message' => $an
            ? 'Archiviert. Der Bogen bleibt lesbar.'
            : 'Zurück in der Übersicht.' ];
    }

    /** Einen Bogen samt Antworten löschen. */
    public static function loeschen( $bogen_id ) {
        global $wpdb;
        $bogen_id = (int) $bogen_id;
        if ( ! self::darf_reflexion() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $t = LSV07S_Tabellen::t( 'reflexion' );
        $b = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $bogen_id ), ARRAY_A );
        if ( ! $b ) return 'Diesen Bogen gibt es nicht.';
        if ( ! self::darf_mannschaft( (int) $b['mannschaft_id'] ) ) {
            return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
        }
        $zahl = 0;
        if ( LSV07S_Tabellen::da( 'refl_antwort' ) ) {
            $zahl = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'refl_antwort' )
                . ' WHERE bogen_id = %d', $bogen_id ) );
            $wpdb->delete( LSV07S_Tabellen::t( 'refl_antwort' ),
                [ 'bogen_id' => $bogen_id ], [ '%d' ] );
        }
        $wpdb->delete( $t, [ 'id' => $bogen_id ], [ '%d' ] );

        LSV07S_Log::schreibe( 'reflexion.geloescht', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'person',
            'ziel_id' => (int) $b['swimmer_id'], 'ziel_name' => (string) $b['swimmer_name'],
            'details' => $zahl . ' Antwort(en)',
        ] );
        return [ 'message' => 'Gelöscht, mit ' . $zahl . ' Antwort(en).' ];
    }
}
