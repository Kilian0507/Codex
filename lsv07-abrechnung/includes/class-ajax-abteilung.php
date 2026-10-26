<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Verwaltung der Abteilungsstruktur.
 *
 * Alles hier ist der Administration vorbehalten: Wer eine Abteilung anlegt,
 * verschiebt oder ihre Leitung setzt, entscheidet damit, **wer über Geld
 * entscheidet**. Das ist keine Einstellung, die man nebenbei vergibt.
 *
 * Eine Ausnahme beim Lesen: `auswahl` liefert nur Nummern und Namen und ist
 * für jedes Konto mit Zugang offen — die Oberfläche braucht die Liste, um zu
 * zeigen, zu welcher Abteilung eine Rechnung gehört. Namen von Abteilungen
 * sind im Verein kein Geheimnis; Leitung, Mannschaften und Kontozahlen
 * stehen nur in `liste`, und die ist zu.
 */
class LSV07A_Ajax_Abteilung {

    public static function init() {
        $aktionen = [
            'liste', 'auswahl', 'neu', 'speichern', 'weg',
            'mannschaften', 'leitung', 'konto', 'vorschlaege',
            /* Eigene Mannschaften — nur in der Abrechnung, nie im
               internen Bereich. */
            'm_neu', 'm_speichern', 'm_weg',
        ];
        foreach ( $aktionen as $a ) {
            add_action( 'wp_ajax_lsv07a_abt_' . $a, [ __CLASS__, $a ] );
        }
    }

    private static function id() { return absint( $_POST['abteilung_id'] ?? 0 ); }

    /** Die Felder einer Abteilung, soweit sie mitgeschickt wurden. */
    private static function felder() {
        $f = [];
        foreach ( [ 'name', 'kurz' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $f[ $n ] = sanitize_text_field( wp_unslash( $_POST[ $n ] ) );
        }
        if ( isset( $_POST['beschreibung'] ) ) {
            $f['beschreibung'] = sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ) );
        }
        if ( isset( $_POST['sortierung'] ) ) $f['sortierung'] = (int) $_POST['sortierung'];
        if ( isset( $_POST['aktiv'] ) )      $f['aktiv'] = (string) $_POST['aktiv'] === '1';
        return $f;
    }

    /** Eine Liste von Nummern aus dem Formular — leer ist erlaubt. */
    private static function nummern( $feld ) {
        $roh = $_POST[ $feld ] ?? [];
        if ( is_string( $roh ) ) {
            /* Auch als "3,7,12" hinnehmen: Ein Formular ohne JavaScript
               schickt kein Array, und eine leere Zeichenkette heisst „keine". */
            $roh = $roh === '' ? [] : explode( ',', $roh );
        }
        $out = [];
        foreach ( (array) $roh as $w ) {
            $n = absint( $w );
            if ( $n ) $out[] = $n;
        }
        return array_values( array_unique( $out ) );
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** Alles, was die Verwaltung zur Struktur braucht — in einem Aufruf. */
    public static function liste() {
        LSV07A_Access::check( 'admin' );

        /* Welche Mannschaften sind noch keiner Abteilung zugeordnet? Das ist
           die Frage, die nach dem Einrichten offen bleibt, und sie soll
           sichtbar sein statt gesucht werden müssen. */
        $vergeben = [];
        foreach ( LSV07A_Abteilung::alle() as $a ) {
            foreach ( $a['mannschaften'] as $m ) $vergeben[ $m['wert'] ] = true;
        }
        $frei = [];
        foreach ( LSV07A_Intern::mannschaften() as $m ) {
            if ( ! isset( $vergeben[ 'intern:' . (int) $m['id'] ] ) ) {
                $frei[] = [ 'wert' => 'intern:' . (int) $m['id'], 'name' => $m['name'],
                            'eigen' => false ];
            }
        }
        foreach ( LSV07A_Mannschaft::alle() as $m ) {
            if ( ! isset( $vergeben[ 'eigen:' . (int) $m['id'] ] ) ) {
                $frei[] = [ 'wert' => 'eigen:' . (int) $m['id'], 'name' => $m['name'],
                            'eigen' => true ];
            }
        }

        $konten = LSV07A_Rollen::konten();
        $ohne   = 0;
        foreach ( $konten as $k ) if ( ! (int) $k['abteilung_id'] ) $ohne++;

        /* Die Auswahlliste führt beide Quellen in EINER Liste, jede Zeile
           mit „quelle:nummer" als Wert. Zwei getrennte Listen hätten
           zusammenstossende Nummern. */
        $auswahl = [];
        foreach ( LSV07A_Intern::mannschaften() as $m ) {
            $auswahl[] = [ 'wert' => 'intern:' . (int) $m['id'], 'name' => $m['name'],
                           'eigen' => false, 'aktiv' => true ];
        }
        foreach ( LSV07A_Mannschaft::alle() as $m ) {
            $auswahl[] = [ 'wert' => 'eigen:' . (int) $m['id'], 'name' => $m['name'],
                           'eigen' => true, 'aktiv' => $m['aktiv'] ];
        }

        wp_send_json_success( [
            'abteilungen'    => LSV07A_Abteilung::alle(),
            'mannschaften'   => $auswahl,
            'eigene'         => LSV07A_Mannschaft::alle(),
            'frei'           => $frei,
            'konten'         => $konten,
            'ohne_abteilung' => $ohne,
            'hinweis_intern' => LSV07A_Intern::hinweis(),
        ] );
    }

    /** Nur Nummer und Name — für Auswahlfelder in der ganzen Anwendung. */
    public static function auswahl() {
        LSV07A_Access::check( 'zugang' );
        wp_send_json_success( [ 'abteilungen' => LSV07A_Abteilung::auswahl() ] );
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    public static function neu() {
        LSV07A_Access::check( 'admin', true );
        $erg = LSV07A_Abteilung::anlegen( self::felder() );
        if ( ! is_int( $erg ) ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'abteilung_id' => $erg,
                                'abteilungen'  => LSV07A_Abteilung::alle() ] );
    }

    public static function speichern() {
        LSV07A_Access::check( 'admin', true );
        $erg = LSV07A_Abteilung::aendern( self::id(), self::felder() );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'abteilungen' => LSV07A_Abteilung::alle() ] );
    }

    public static function weg() {
        LSV07A_Access::check( 'admin', true );
        $erg = LSV07A_Abteilung::loeschen( self::id() );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'abteilungen' => LSV07A_Abteilung::alle(),
                                'message'     => 'Abteilung gelöscht.' ] );
    }

    /**
     * Die Mannschaften einer Abteilung neu setzen.
     *
     * Eine leere Liste ist eine gültige Angabe und bedeutet „diese Abteilung
     * hat keine Mannschaften" — die Geschäftsstelle rechnet ab, trainiert
     * aber nicht.
     */
    public static function mannschaften() {
        LSV07A_Access::check( 'admin', true );
        $erg = LSV07A_Abteilung::mannschaften_setzen( self::id(), self::nummern( 'mannschaften' ) );
        if ( ! is_array( $erg ) ) wp_send_json_error( [ 'message' => (string) $erg ] );

        /* Umgehängte Mannschaften werden GEMELDET. Sonst sucht jemand, warum
           die Jugend in seiner Abteilung verschwunden ist. */
        $hinweis = '';
        if ( $erg['umgehaengt'] ) {
            $namen = [];
            foreach ( LSV07A_Intern::mannschaften() as $m ) {
                if ( in_array( 'intern:' . (int) $m['id'], $erg['umgehaengt'], true ) ) {
                    $namen[] = $m['name'];
                }
            }
            foreach ( LSV07A_Mannschaft::alle() as $m ) {
                if ( in_array( 'eigen:' . (int) $m['id'], $erg['umgehaengt'], true ) ) {
                    $namen[] = $m['name'];
                }
            }
            $hinweis = count( $namen ) === 1
                ? $namen[0] . ' gehörte zu einer anderen Abteilung und wurde umgehängt.'
                : implode( ', ', $namen ) . ' gehörten zu anderen Abteilungen und wurden umgehängt.';
        }
        wp_send_json_success( [
            'abteilungen' => LSV07A_Abteilung::alle(),
            'anzahl'      => $erg['anzahl'],
            'hinweis'     => $hinweis,
        ] );
    }

    /**
     * Die Leitung einer Abteilung setzen.
     *
     * Wer hier eingetragen wird, braucht zusätzlich die Rolle
     * „Abteilungsleitung" — ohne sie öffnet das Tor nicht. Das wird GEPRÜFT
     * und gemeldet, nicht stillschweigend mitvergeben: Rollen vergibt die
     * Administration bewusst unter Konten, und zwei Wege, auf denen eine
     * Rolle entsteht, wären einer zu viel.
     */
    public static function leitung() {
        LSV07A_Access::check( 'admin', true );
        $uids = self::nummern( 'uids' );
        $erg  = LSV07A_Abteilung::leitung_setzen( self::id(), $uids );
        if ( ! is_int( $erg ) ) wp_send_json_error( [ 'message' => (string) $erg ] );

        $fehlt = [];
        foreach ( $uids as $uid ) {
            if ( LSV07A_Rollen::ist_genau( 'leiter', $uid ) ) continue;
            if ( LSV07A_Rollen::ist_admin_echt( $uid ) ) continue;
            $u = get_userdata( $uid );
            $fehlt[] = $u ? $u->display_name : ( 'Konto ' . $uid );
        }
        wp_send_json_success( [
            'abteilungen' => LSV07A_Abteilung::alle(),
            'anzahl'      => $erg,
            'hinweis'     => $fehlt
                ? ( count( $fehlt ) === 1 ? $fehlt[0] . ' hat noch nicht die Rolle „Abteilungsleitung".'
                                          : implode( ', ', $fehlt ) . ' haben noch nicht die Rolle „Abteilungsleitung".' )
                  . ' Ohne sie bleibt die Genehmigung gesperrt — zu vergeben unter Verwaltung → Konten.'
                : '',
        ] );
    }

    // ── Eigene Mannschaften ──────────────────────────────────────────────

    private static function m_felder() {
        $f = [];
        foreach ( [ 'name', 'notiz' ] as $n ) {
            if ( isset( $_POST[ $n ] ) ) $f[ $n ] = sanitize_text_field( wp_unslash( $_POST[ $n ] ) );
        }
        if ( isset( $_POST['aktiv'] ) ) $f['aktiv'] = (string) $_POST['aktiv'] === '1';
        return $f;
    }

    /**
     * Eine eigene Mannschaft anlegen.
     *
     * Sie entsteht AUSSCHLIESSLICH in der Abrechnung. Im internen Bereich
     * wird dafür nichts angelegt und nichts geändert.
     */
    public static function m_neu() {
        LSV07A_Access::check( 'admin', true );
        $f   = self::m_felder();
        $erg = LSV07A_Mannschaft::anlegen( $f );
        if ( ! is_int( $erg ) ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [
            'mannschaft_id' => $erg,
            'eigene'        => LSV07A_Mannschaft::alle(),
            /* Gleicher Name wie im internen Bereich: gewarnt, nicht
               verboten — der interne Bereich bestimmt hier nicht mit. */
            'hinweis'       => LSV07A_Mannschaft::warnung( $f['name'] ?? '' ),
        ] );
    }

    public static function m_speichern() {
        LSV07A_Access::check( 'admin', true );
        $f   = self::m_felder();
        $erg = LSV07A_Mannschaft::aendern( absint( $_POST['mannschaft_id'] ?? 0 ), $f );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [
            'eigene'      => LSV07A_Mannschaft::alle(),
            'abteilungen' => LSV07A_Abteilung::alle(),
            'hinweis'     => isset( $f['name'] ) ? LSV07A_Mannschaft::warnung( $f['name'] ) : '',
        ] );
    }

    public static function m_weg() {
        LSV07A_Access::check( 'admin', true );
        $erg = LSV07A_Mannschaft::loeschen( absint( $_POST['mannschaft_id'] ?? 0 ) );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'eigene' => LSV07A_Mannschaft::alle(),
                                'message' => 'Mannschaft gelöscht.' ] );
    }

    /** Ein Konto einer Abteilung zuordnen. 0 nimmt die Zuordnung weg. */
    public static function konto() {
        LSV07A_Access::check( 'admin', true );
        $uid = absint( $_POST['wp_user_id'] ?? 0 );
        if ( ! $uid ) wp_send_json_error( [ 'message' => 'Welches Konto?' ] );
        $erg = LSV07A_Abteilung::konto_setzen( $uid, absint( $_POST['abteilung_id'] ?? 0 ) );
        if ( $erg !== true ) wp_send_json_error( [ 'message' => (string) $erg ] );
        wp_send_json_success( [ 'konten' => LSV07A_Rollen::konten(),
                                'abteilungen' => LSV07A_Abteilung::alle() ] );
    }

    /**
     * Vorschläge für alle Konten ohne Abteilung.
     *
     * Nach dem Update steht jedes Konto auf „keine Abteilung". Hundert
     * Zuordnungen von Hand zu treffen, wäre eine Stunde Klickarbeit — und
     * die Antwort steht schon in den Daten: Wer bisher die Jugend
     * abgerechnet hat, gehört zu der Abteilung, zu der die Jugend gehört.
     *
     * VORGESCHLAGEN wird nur. Zugeordnet wird mit einem eigenen Klick, denn
     * ein Vorschlag aus alten Abrechnungen kann falsch sein, und wer
     * genehmigt, soll nicht aus einer Statistik folgen.
     */
    public static function vorschlaege() {
        LSV07A_Access::check( 'admin' );
        $out = [];
        foreach ( LSV07A_Rollen::konten() as $k ) {
            if ( (int) $k['abteilung_id'] ) continue;
            $abt = LSV07A_Abteilung::vorschlag( (int) $k['wp_user_id'] );
            if ( ! $abt ) continue;
            $out[] = [
                'wp_user_id'   => (int) $k['wp_user_id'],
                'name'         => $k['name'],
                'abteilung_id' => $abt,
                'abteilung'    => LSV07A_Abteilung::name( $abt ),
            ];
        }
        wp_send_json_success( [ 'vorschlaege' => $out ] );
    }
}
