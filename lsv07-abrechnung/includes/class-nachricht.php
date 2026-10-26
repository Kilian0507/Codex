<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Benachrichtigungen.
 *
 * Bewusst IM System und nicht per E-Mail: Eine Abrechnung enthält Beträge,
 * Namen und Zeiträume. Die gehören nicht ungefragt in ein fremdes Postfach,
 * das über fremde Server läuft und dort liegen bleibt. Wer etwas wissen
 * muss, sieht es beim nächsten Öffnen der Abrechnung.
 *
 * Eine Mitteilung sagt immer nur, DASS etwas passiert ist und wo es steht —
 * nie, um wie viel Geld es geht. Wer den Betrag sehen darf, sieht ihn beim
 * Öffnen; dort greift die Rechteprüfung.
 */
class LSV07A_Nachricht {

    private static function tbl() {
        global $wpdb;
        return $wpdb->prefix . 'lsv07a_nachricht';
    }

    /**
     * Eine Mitteilung ablegen. `schluessel` macht sie eindeutig: Derselbe
     * Vorgang erzeugt sie nur einmal, auch wenn der Auslöser mehrfach
     * feuert. Ohne Schlüssel ist jede Mitteilung neu.
     */
    public static function an( $wp_user_id, $art, $titel, $text = '',
                               $bereich = '', $ziel_id = 0, $schluessel = null ) {
        global $wpdb;
        $wp_user_id = (int) $wp_user_id;
        if ( ! $wp_user_id ) return false;

        // Niemand bekommt eine Mitteilung über das, was er selbst getan hat.
        if ( $wp_user_id === get_current_user_id() ) return false;

        $wpdb->suppress_errors( true );
        $ok = $wpdb->insert( self::tbl(), [
            'wp_user_id'   => $wp_user_id,
            'art'          => substr( (string) $art, 0, 40 ),
            'titel'        => substr( sanitize_text_field( $titel ), 0, 160 ),
            'text'         => substr( sanitize_text_field( $text ), 0, 400 ),
            'ziel_bereich' => substr( (string) $bereich, 0, 30 ),
            'ziel_id'      => (int) $ziel_id,
            'schluessel'   => $schluessel === null ? null : substr( (string) $schluessel, 0, 120 ),
        ], [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ] );
        $wpdb->suppress_errors( false );
        return (bool) $ok;   // Dublette (uq_schluessel) scheitert still — so gewollt
    }

    /** Dieselbe Mitteilung an alle Konten mit einer Rolle. */
    public static function an_rolle( $rolle, $art, $titel, $text = '',
                                     $bereich = '', $ziel_id = 0, $schluessel = null ) {
        /* Über konten_mit() und nicht über eine eigene Abfrage: Dort sind
           auch die echten WordPress-Administratoren mitgezählt, die keine
           Zeile in lsv07a_rolle haben. */
        foreach ( self::konten_mit( $rolle ) as $uid ) {
            self::an( (int) $uid, $art, $titel, $text, $bereich, $ziel_id,
                      $schluessel === null ? null : $schluessel );
        }
    }

    /** Die Mitteilungen einer Person, neueste zuerst. */
    public static function liste( $wp_user_id, $limit = 40 ) {
        global $wpdb;
        $limit = max( 1, min( 200, (int) $limit ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, art, titel, text, ziel_bereich, ziel_id, gelesen_am, erstellt_am
               FROM " . self::tbl() . "
              WHERE wp_user_id = %d
           ORDER BY id DESC LIMIT $limit", (int) $wp_user_id ), ARRAY_A ) ?: [];
    }

    public static function offen( $wp_user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::tbl() . "
              WHERE wp_user_id = %d AND gelesen_am IS NULL", (int) $wp_user_id ) );
    }

    /**
     * Als gelesen markieren. IMMER zusätzlich auf das eigene Konto
     * eingeschränkt — eine fremde ID darf nichts bewirken.
     */
    public static function gelesen( $wp_user_id, $id = 0 ) {
        global $wpdb;
        $wp_user_id = (int) $wp_user_id;
        $jetzt = current_time( 'mysql' );
        if ( $id ) {
            return (bool) $wpdb->query( $wpdb->prepare(
                "UPDATE " . self::tbl() . " SET gelesen_am = %s
                  WHERE id = %d AND wp_user_id = %d AND gelesen_am IS NULL",
                $jetzt, (int) $id, $wp_user_id ) );
        }
        return (bool) $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::tbl() . " SET gelesen_am = %s
              WHERE wp_user_id = %d AND gelesen_am IS NULL", $jetzt, $wp_user_id ) );
    }

    /** Aufräumen: Gelesenes älter als ein halbes Jahr fliegt raus. */
    public static function aufraeumen() {
        global $wpdb;
        $wpdb->query( "DELETE FROM " . self::tbl() . "
                        WHERE gelesen_am IS NOT NULL
                          AND erstellt_am < DATE_SUB(NOW(), INTERVAL 180 DAY)" );
    }

    // ── Die Anlässe ──────────────────────────────────────────────────────
    /** Der Name einer Person, wie er in einer Mitteilung erscheinen darf. */
    private static function name( $uid ) {
        $u = get_userdata( (int) $uid );
        return $u ? $u->display_name : 'Ein Konto';
    }

    /** Die Konten einer Rolle — für den Mailversand. */
    private static function konten_mit( $rolle ) {
        global $wpdb;
        $uids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT wp_user_id FROM {$wpdb->prefix}lsv07a_rolle WHERE rolle = %s", $rolle ) ) ?: [] );

        /* Bei 'admin' kommen die echten WordPress-Administratoren dazu.
           LSV07A_Rollen rechnet sie überall als Administratoren mit, OHNE
           dass eine Zeile in lsv07a_rolle steht — ein Verein, in dem die
           Vorsitzende einfach WordPress-Administratorin ist, hat dort also
           keinen Eintrag. Ohne diese Ergänzung ginge eine Mitteilung „an
           die Administration" an niemanden, und das wäre genau dann
           schlimm, wenn sie die letzte Rückfallebene ist. */
        if ( $rolle === 'admin' && function_exists( 'get_users' ) ) {
            foreach ( (array) get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] ) as $u ) {
                $id = (int) ( is_object( $u ) ? ( $u->ID ?? 0 ) : $u );
                if ( $id && ! in_array( $id, $uids, true ) ) $uids[] = $id;
            }
        }
        return $uids;
    }

    public static function eingereicht( $abr ) {
        $zeit = LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . $abr['jahr'];
        $name = self::name( $abr['wp_user_id'] );
        self::an_rolle( 'wart', 'eingereicht',
            'Neue Abrechnung zur Prüfung',
            $name . ' hat die Abrechnung für ' . $zeit . ' eingereicht.',
            'pruefung', (int) $abr['id'], 'eingereicht:' . $abr['id'] );

        $wer = self::konten_mit( 'wart' );
        // Wer selbst eingereicht hat, bekommt darüber keine Post.
        $wer = array_values( array_diff( $wer, [ get_current_user_id() ] ) );
        LSV07A_Mail::senden( 'eingereicht',
            array_merge( $wer, LSV07A_Mail::extra( 'wart' ) ),
            [ '{name}' => $name, '{zeitraum}' => $zeit ] );
    }

    public static function genehmigt( $abr ) {
        $zeit = LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . $abr['jahr'];
        self::an( (int) $abr['wp_user_id'], 'genehmigt',
            'Abrechnung genehmigt',
            'Ihre Abrechnung für ' . $zeit . ' wurde genehmigt und liegt jetzt bei der Kasse.',
            'eigene', (int) $abr['id'], 'genehmigt:' . $abr['id'] );
        self::an_rolle( 'kasse', 'auszahlbar',
            'Abrechnung zur Auszahlung',
            self::name( $abr['wp_user_id'] ) . ' — ' . $zeit . ' ist genehmigt.',
            'kasse', (int) $abr['id'], 'auszahlbar:' . $abr['id'] );

        $werte = [ '{name}' => self::name( $abr['wp_user_id'] ), '{zeitraum}' => $zeit ];
        LSV07A_Mail::senden( 'genehmigt', [ (int) $abr['wp_user_id'] ], $werte );
        $kasse = array_values( array_diff( self::konten_mit( 'kasse' ), [ get_current_user_id() ] ) );
        LSV07A_Mail::senden( 'genehmigt',
            array_merge( $kasse, LSV07A_Mail::extra( 'kasse' ) ), $werte );
    }

    public static function zurueckgegeben( $abr, $grund = '' ) {
        $zeit = LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . $abr['jahr'];
        self::an( (int) $abr['wp_user_id'], 'zurueck',
            'Abrechnung zurückgegeben',
            'Ihre Abrechnung für ' . $zeit . ' wurde zur Überarbeitung zurückgegeben.'
            . ( $grund !== '' ? ' Grund: ' . $grund : '' ),
            'eigene', (int) $abr['id'], 'zurueck:' . $abr['id'] . ':' . substr( md5( (string) $grund ), 0, 8 ) );
        LSV07A_Mail::senden( 'zurueck', [ (int) $abr['wp_user_id'] ], [
            '{name}' => self::name( $abr['wp_user_id'] ), '{zeitraum}' => $zeit,
            '{grund}' => $grund !== '' ? $grund : 'ohne Angabe' ] );
    }

    public static function bezahlt( $abr ) {
        $zeit = LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . $abr['jahr'];
        self::an( (int) $abr['wp_user_id'], 'bezahlt',
            'Abrechnung bezahlt',
            'Ihre Abrechnung für ' . $zeit . ' ist als bezahlt vermerkt.',
            'eigene', (int) $abr['id'], 'bezahlt:' . $abr['id'] );
        /* Die Abrechnung selbst geht mit: im Mailtext und als PDF. Sie
           geht an die eigene Adresse der Person und an keine andere —
           darum darf hier stehen, was in einer Mitteilung sonst nicht
           steht. Abschalten lässt es sich in der Verwaltung. */
        LSV07A_Mail::senden( 'bezahlt', [ (int) $abr['wp_user_id'] ],
            [ '{name}' => self::name( $abr['wp_user_id'] ), '{zeitraum}' => $zeit ],
            [ 'beleg' => (int) $abr['id'] ] );
    }

    // ── Am einzelnen Posten ──────────────────────────────────────────────

    /** Kurzer Verweis auf die betroffene Zeile: Datum und Bezeichnung. */
    private static function zeile( $posten ) {
        $d = strtotime( (string) ( $posten['datum'] ?? '' ) );
        return ( $d ? date( 'd.m.Y', $d ) . ' ' : '' )
             . ( trim( (string) ( $posten['bezeichnung'] ?? '' ) ) ?: 'Posten' );
    }

    public static function posten_beanstandet( $abr, $posten, $grund ) {
        $zeit = LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . $abr['jahr'];
        self::an( (int) $abr['wp_user_id'], 'beanstandung',
            'Ein Posten wurde beanstandet',
            self::zeile( $posten ) . ' (' . $zeit . '): ' . $grund,
            'eigene', (int) $abr['id'], 'beanst:' . $posten['id'] . ':' . time() );
        LSV07A_Mail::senden( 'beanstandung', [ (int) $abr['wp_user_id'] ],
            [ '{name}' => self::name( $abr['wp_user_id'] ), '{zeitraum}' => $zeit,
              '{grund}' => self::zeile( $posten ) . ' — ' . $grund ] );
    }

    public static function posten_frage( $abr, $posten, $text ) {
        $zeit = LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . $abr['jahr'];
        self::an( (int) $abr['wp_user_id'], 'rueckfrage',
            'Rückfrage zu einem Posten',
            self::zeile( $posten ) . ' (' . $zeit . '): ' . $text,
            'eigene', (int) $abr['id'], 'frage:' . $posten['id'] . ':' . time() );
        LSV07A_Mail::senden( 'rueckfrage', [ (int) $abr['wp_user_id'] ],
            [ '{name}' => self::name( $abr['wp_user_id'] ), '{zeitraum}' => $zeit,
              '{grund}' => self::zeile( $posten ) . ' — ' . $text ] );
    }

    /** Die Antwort geht zurück an die Warte — und an die extra Adressen. */
    public static function posten_antwort( $abr, $posten, $text ) {
        $zeit = LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . $abr['jahr'];
        $wer  = self::name( $abr['wp_user_id'] );
        self::an_rolle( 'wart', 'rueckfrage',
            'Antwort auf eine Rückfrage',
            $wer . ' hat zu ' . self::zeile( $posten ) . ' (' . $zeit . ') geantwortet.',
            'pruefung', (int) $abr['id'], 'antwort:' . $posten['id'] . ':' . time() );
        $empfaenger = array_merge( self::konten_mit( 'wart' ), LSV07A_Mail::extra( 'wart' ) );
        LSV07A_Mail::senden( 'rueckfrage', $empfaenger,
            [ '{name}' => $wer, '{zeitraum}' => $zeit,
              '{grund}' => self::zeile( $posten ) . ' — ' . $text ] );
    }

    public static function wieder_offen( $abr ) {
        $zeit = LSV07A_Berechnung::quartal_name( $abr['quartal'] ) . ' ' . $abr['jahr'];
        self::an( (int) $abr['wp_user_id'], 'offen',
            'Abrechnung wieder geöffnet',
            'Die Administration hat Ihre Abrechnung für ' . $zeit . ' wieder zur Bearbeitung geöffnet.',
            'eigene', (int) $abr['id'], 'offen:' . $abr['id'] . ':' . time() );
        LSV07A_Mail::senden( 'offen', [ (int) $abr['wp_user_id'] ],
            [ '{name}' => self::name( $abr['wp_user_id'] ), '{zeitraum}' => $zeit ] );
    }

    /** Satz oder Abrechnungsart eines Kontos geändert. */
    public static function satz_geaendert( $wp_user_id, $was ) {
        self::an( (int) $wp_user_id, 'satz',
            'Ihre Abrechnungsvorgaben wurden geändert',
            $was . ' Offene Abrechnungen rechnen sich damit neu; Eingereichtes bleibt unberührt.',
            'eigene', 0, 'satz:' . $wp_user_id . ':' . substr( md5( $was ), 0, 8 ) );
        LSV07A_Mail::senden( 'satz', [ (int) $wp_user_id ],
            [ '{name}' => self::name( $wp_user_id ), '{grund}' => $was ] );
    }

    // ── Andere Abrechnung: Rechnungen ────────────────────────────────────

    /**
     * Wie eine Rechnung in einer Mitteilung erscheint: Nummer und Betreff,
     * nie der Betrag. Wer ihn sehen darf, sieht ihn beim Öffnen.
     */
    private static function r_kennung( $r ) {
        $nr = 'RG-' . str_pad( (string) (int) ( $r['id'] ?? 0 ), 4, '0', STR_PAD_LEFT );
        $titel = trim( (string) ( $r['titel'] ?? '' ) );
        return $titel === '' ? $nr : ( $nr . ' — ' . $titel );
    }

    /**
     * Eine Rechnung ist eingereicht — die Abteilungsleitung ist gefragt.
     *
     * Angeschrieben wird die Leitung GENAU DIESER Abteilung und nicht jede
     * Leitung im Verein: Eine Rechnung des Wasserballs geht die
     * Schwimmabteilung nichts an.
     *
     * Hat die Abteilung noch keine Leitung, geht die Mitteilung an die
     * Administration. Sonst läge die Rechnung da und niemand erfährt davon —
     * eine stille Warteschlange ist schlimmer als eine Mitteilung an der
     * falschen Stelle.
     */
    public static function rechnung_eingereicht( $r ) {
        if ( ! $r ) return;
        $kennung = self::r_kennung( $r );
        $name    = self::name( $r['wp_user_id'] );
        $abt     = LSV07A_Abteilung::name( $r['abteilung_id'] );
        $wer     = LSV07A_Abteilung::leitung( (int) $r['abteilung_id'] );

        $titel = 'Neue Rechnung zur Genehmigung';
        $text  = $name . ' hat ' . $kennung . ' eingereicht'
               . ( $abt !== '' ? ' (Abteilung ' . $abt . ')' : '' ) . '.';

        if ( $wer ) {
            foreach ( $wer as $uid ) {
                self::an( (int) $uid, 'r_eingereicht', $titel, $text,
                          'genehmigung', (int) $r['id'], 'r_ein:' . $r['id'] . ':' . $uid );
            }
        } else {
            self::an_rolle( 'admin', 'r_eingereicht', $titel,
                $text . ' Diese Abteilung hat noch keine Leitung.',
                'genehmigung', (int) $r['id'], 'r_ein_admin:' . $r['id'] );
        }

        $empfaenger = $wer ? array_values( array_diff( $wer, [ get_current_user_id() ] ) )
                           : self::konten_mit( 'admin' );
        LSV07A_Mail::senden( 'r_eingereicht', $empfaenger,
            [ '{name}' => $name, '{rechnung}' => $kennung, '{abteilung}' => $abt ] );
    }

    /**
     * Über eine Rechnung ist entschieden worden: genehmigt, zurückgegeben
     * oder bezahlt. Die eine Stelle für alle drei — so stehen sie nicht in
     * drei Fassungen auseinander.
     */
    public static function rechnung_entschieden( $r, $was ) {
        if ( ! $r ) return;
        $kennung = self::r_kennung( $r );
        $name    = self::name( $r['wp_user_id'] );
        $uid     = (int) $r['wp_user_id'];
        $werte   = [ '{name}' => $name, '{rechnung}' => $kennung,
                     '{abteilung}' => LSV07A_Abteilung::name( $r['abteilung_id'] ),
                     '{grund}' => (string) ( $r['rueckgabe_grund'] ?? '' ) ];

        if ( $was === 'genehmigt' ) {
            self::an( $uid, 'r_genehmigt', 'Rechnung genehmigt',
                $kennung . ' wurde genehmigt und liegt jetzt bei der Kasse.',
                'rechnungen', (int) $r['id'], 'r_gen:' . $r['id'] );
            self::an_rolle( 'kasse', 'r_zahlbar', 'Rechnung zur Überweisung',
                $kennung . ' von ' . $name . ' ist genehmigt.',
                'kasse', (int) $r['id'], 'r_zahl:' . $r['id'] );

            LSV07A_Mail::senden( 'r_genehmigt', [ $uid ], $werte );
            $kasse = array_values( array_diff( self::konten_mit( 'kasse' ), [ get_current_user_id() ] ) );
            LSV07A_Mail::senden( 'r_genehmigt',
                array_merge( $kasse, LSV07A_Mail::extra( 'kasse' ) ), $werte );
            return;
        }

        if ( $was === 'zurueck' ) {
            $grund = trim( (string) ( $r['rueckgabe_grund'] ?? '' ) );
            self::an( $uid, 'r_zurueck', 'Rechnung zurückgegeben',
                $kennung . ' kommt zur Überarbeitung zurück.'
                . ( $grund !== '' ? ' Grund: ' . $grund : '' ),
                'rechnungen', (int) $r['id'],
                'r_zur:' . $r['id'] . ':' . substr( md5( $grund ), 0, 8 ) );
            if ( $grund === '' ) $werte['{grund}'] = 'ohne Angabe';
            LSV07A_Mail::senden( 'r_zurueck', [ $uid ], $werte );
            return;
        }

        if ( $was === 'bezahlt' ) {
            self::an( $uid, 'r_bezahlt', 'Rechnung bezahlt',
                $kennung . ' ist überwiesen und als bezahlt vermerkt.',
                'rechnungen', (int) $r['id'], 'r_bez:' . $r['id'] );
            LSV07A_Mail::senden( 'r_bezahlt', [ $uid ], $werte );
        }
    }

    /**
     * Ein Quartal ist vorbei und kann abgerechnet werden. Wird beim Öffnen
     * geprüft, damit es keine Hintergrundaufgabe braucht, die irgendwann
     * still ausfällt.
     */
    public static function quartal_faellig( $wp_user_id ) {
        if ( ! LSV07A_Rollen::ist_trainer( $wp_user_id ) ) return;
        $heute = current_time( 'Y-m-d' );
        [ $q, $j ] = LSV07A_Berechnung::vorquartal( $heute );
        // Erst ab dem 1. des Folgemonats und nur im ersten Monat danach
        $name = LSV07A_Berechnung::quartal_name( $q ) . ' ' . $j;
        self::an( (int) $wp_user_id, 'faellig',
            $name . ' kann abgerechnet werden',
            'Das Quartal ist vorbei. Trainings und Wettkämpfe lassen sich jetzt vollständig übernehmen.',
            'eigene', 0, 'faellig:' . $q . ':' . $j );
        LSV07A_Mail::senden( 'faellig', [ (int) $wp_user_id ],
            [ '{name}' => self::name( $wp_user_id ), '{zeitraum}' => $name ] );
    }
}
