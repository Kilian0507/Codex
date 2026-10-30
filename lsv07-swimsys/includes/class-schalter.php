<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Was an ist und was aus — die eine Stelle, an der das steht.
 *
 * SwimSys fragt **keine Rechte** mehr. Jede Funktion hat einen Schalter,
 * und zwar zweimal:
 *
 * 1. **Global**: Eine Funktion, die hier aus ist, ist für alle aus —
 *    auch für die, bei denen sie persönlich an steht. So lässt sich ein
 *    Teil abschalten, der noch nicht fertig ist oder nicht gebraucht
 *    wird, ohne jedes Konto anzufassen.
 * 2. **Je Konto**: Was eine Person sieht und tun darf.
 *
 * An ist etwas nur, wenn **beide** Schalter an sind. Das ist die ganze
 * Regel; es gibt keine zweite Stelle, an der Sichtbarkeit entschieden
 * wird, und keine Bereichsfrage, die mehr öffnet als der einzelne
 * Schalter.
 *
 * **Die Voreinstellungen sind mit Absicht verschieden:**
 *
 * - Global ist alles **an**. Sonst wäre nach einer Aktualisierung das
 *   ganze Programm aus, und niemand wüsste, warum.
 * - Je Konto ist alles **aus**. Ein neues Konto sieht also nichts, bis
 *   jemand Häkchen setzt. Beim Raten über Zugriff ist der Fehler in eine
 *   Richtung teuer: Jemand sieht etwas, das er nicht sehen soll.
 *
 * **Angemeldet wird über WordPress.** SwimSys hat keine eigene
 * Anmeldung, kein eigenes Kennwort und keinen eigenen Code. Wer
 * hereinkommt, ist mit seinem WordPress-Konto angemeldet; die Schalter
 * entscheiden danach, was er sieht.
 *
 * **Wer schaltet:** nur WordPress-Administratoren. Niemand kann sich
 * selbst etwas einschalten, und ein Administrator kann sich nicht
 * aussperren — für ihn ist alles an, unabhängig von den Schaltern.
 *
 * Die alte Rechtetabelle (`lsv07i_user_rechte`) bleibt stehen, weil der
 * interne Bereich sie weiter liest. SwimSys schreibt dort nichts und
 * fragt sie nicht mehr; einmalig lässt sich daraus übernehmen, wer was
 * hatte (`aus_rechten`).
 */
class LSV07S_Schalter {

    /** Die eigenen Tabellen. Nur diese legt SwimSys selbst an. */
    const T_GLOBAL = 'lsv07s_schalter';
    const T_KONTO  = 'lsv07s_konto_schalter';

    private static $g = null;        // global, einmal gelesen
    private static $k = [];          // je Konto, einmal gelesen

    /**
     * Die Funktionen. Der Schlüssel ist `bereich/teil` — dieselbe
     * Zeichenkette, die in der Adresse steht (`#schwimmen/atteste`).
     *
     * `art`:
     *   'ansicht'  — steht in der Navigation und hat eine Adresse.
     *   'zusatz'   — kein eigener Bildschirm, sondern eine Reichweite:
     *                „alle Mannschaften" statt nur der eigenen, „alle
     *                Anfragen" statt nur der eigenen. Ohne diese beiden
     *                wäre nicht sagbar, wie weit jemand sieht.
     *
     * Schreiben hängt an der Ansicht mit: Wer Wettkämpfe anhat, darf sie
     * auch anlegen, ändern, freigeben und löschen. Das ist bewusst grob —
     * ein Schalter je Ansicht ist zu verstehen und zu setzen, eine Liste
     * mit sechzig Häkchen nicht.
     */
    public static function katalog() {
        $k = [];
        $k['start/aufgaben'] = [ 'name' => 'Meine Aufgaben', 'art' => 'ansicht',
            'was' => 'Die Startseite mit dem, was offen ist.' ];

        foreach ( LSV07S_Sparte::alle() as $sp ) {
            if ( ! LSV07S_Sparte::da( $sp ) ) continue;
            $gruppe = LSV07S_Sparte::feld( $sp, 'recht_gruppe' );   // mannschaft | gruppe
            $viele  = LSV07S_Sparte::wort( $sp, 'gruppe_mehrere' );
            $einer  = LSV07S_Sparte::wort( $sp, 'einer' );

            $k[ $sp . '/' . $gruppe ] = [ 'name' => $viele, 'art' => 'ansicht',
                'was' => 'Die Liste der ' . $viele . ' mit ihrem Kader, und die '
                       . 'Stammdaten der ' . $einer . '.' ];
            $k[ $sp . '/anwesenheit' ] = [ 'name' => 'Anwesenheit', 'art' => 'ansicht',
                'was' => 'Der Tag einer Mannschaft: Anwesenheit, Verspätung, Absage, '
                       . 'Mannschaftsbuch, Bahnen, Springer.' ];
            $k[ $sp . '/trainer' ] = [ 'name' => 'Trainerliste', 'art' => 'ansicht',
                'was' => 'Die Trainer dieser Sparte mit ihren Fristen.' ];
            $k[ $sp . '/eigene' ] = [ 'name' => 'Meine Daten', 'art' => 'ansicht',
                'was' => 'Was über die Person selbst gespeichert ist.' ];

            if ( $sp === LSV07S_Sparte::SCHWIMMEN ) {
                $k['schwimmen/atteste'] = [ 'name' => 'Atteste', 'art' => 'ansicht',
                    'was' => 'Ärztliche Atteste mit Fristen und Dateien. '
                           . 'Gesundheitsdaten.' ];
                $k['schwimmen/dateien'] = [ 'name' => 'Dateien', 'art' => 'ansicht',
                    'was' => 'Papiere an einer Person — Einverständnis, Vereinbarung, '
                           . 'Foto. Nicht die Atteste: die haben ihren eigenen '
                           . 'Schalter.' ];
                $k['schwimmen/akte'] = [ 'name' => 'Akte', 'art' => 'ansicht',
                    'was' => 'Alles zu einer Person auf einem Blatt — der weiteste '
                           . 'Blick, den SwimSys hat.' ];
                $k['schwimmen/wettkampf'] = [ 'name' => 'Wettkämpfe', 'art' => 'ansicht',
                    'was' => 'Wettkämpfe anlegen, Dokumente, Freigabe, Anwesenheit '
                           . 'je Abschnitt.' ];
                $k['schwimmen/meldung'] = [ 'name' => 'Meldungen', 'art' => 'ansicht',
                    'was' => 'Startlisten zu einem Wettkampf.' ];
                $k['schwimmen/bestzeit'] = [ 'name' => 'Bestzeiten', 'art' => 'ansicht',
                    'was' => 'Die Zeitentafel, Einlesen und Löschen.' ];
                $k['schwimmen/springer'] = [ 'name' => 'Springer', 'art' => 'ansicht',
                    'was' => 'Wer wann für wen eingesprungen ist.' ];
                $k['schwimmen/trainingsplan'] = [ 'name' => 'Trainingspläne',
                    'art' => 'ansicht',
                    'was' => 'Eigene Pläne schreiben, freigegebene lesen, Vorlagen.' ];
                $k['schwimmen/reflexion'] = [ 'name' => 'Reflexionsbögen',
                    'art' => 'ansicht',
                    'was' => 'Fragen, Bögen, Schlüssel und die Antworten der '
                           . 'Schwimmer. Was ein Kind sich vornimmt.' ];
            }

            $k[ $sp . '/alle' ] = [ 'name' => 'Alle ' . $viele, 'art' => 'zusatz',
                'was' => 'Ohne diesen Schalter sieht die Person nur die ' . $viele
                       . ', für die sie als Trainer eingetragen ist.' ];
        }

        $k['abrechnung/stunden'] = [ 'name' => 'Meine Stunden', 'art' => 'ansicht',
            'was' => 'Die eigenen Trainings- und Wettkampfstunden. Ohne Satz und '
                   . 'ohne Betrag — abgerechnet wird in der '
                   . 'Übungsleiterabrechnung.' ];
        $k['abrechnung/uebersicht'] = [ 'name' => 'Stunden aller Trainer',
            'art' => 'ansicht',
            'was' => 'Dieselbe Rechnung für alle Trainer — für Prüfung und Kasse.' ];
        $k['reden/nachrichten'] = [ 'name' => 'Nachrichten', 'art' => 'ansicht',
            'was' => 'Unterhaltungen lesen und schreiben, Gruppen, Anhänge.' ];
        $k['reden/anfragen'] = [ 'name' => 'Anfragen', 'art' => 'ansicht',
            'was' => 'Eigene Anfragen stellen und verfolgen.' ];
        $k['reden/anfragen_alle'] = [ 'name' => 'Alle Anfragen bearbeiten',
            'art' => 'zusatz',
            'was' => 'Ohne diesen Schalter sieht die Person nur ihre eigenen '
                   . 'Anfragen und die, die ihr zugewiesen sind.' ];
        $k['verwaltung/einstellungen'] = [ 'name' => 'Einstellungen',
            'art' => 'ansicht', 'was' => 'Die Einstellungen des Vereins.' ];
        $k['verwaltung/benachrichtigungen'] = [ 'name' => 'Benachrichtigungen',
            'art' => 'ansicht',
            'was' => 'Mitteilungen an alle Konten schreiben und löschen, mit '
                   . 'Lesebestätigung. LESEN kann jedes Konto, das hereinkommt — '
                   . 'eine Mitteilung, die der halben Mannschaft nicht angezeigt '
                   . 'wird, nützt nichts.' ];
        $k['verwaltung/protokoll'] = [ 'name' => 'Protokoll', 'art' => 'ansicht',
            'was' => 'Wer wann was geändert hat.' ];

        return $k;
    }

    /** Gibt es diese Funktion? */
    public static function gibt( $funktion ) {
        return isset( self::katalog()[ (string) $funktion ] );
    }

    /** Die Namen der Bereiche, wie sie oben stehen. */
    const BEREICHE = [
        'start'      => 'Start',
        'schwimmen'  => 'Schwimmen',
        'triathlon'  => 'Triathlon',
        'fitness'    => 'Fitness',
        'abrechnung' => 'Stunden',
        'reden'      => 'Nachrichten & Anfragen',
        'verwaltung' => 'Verwaltung',
    ];

    // ── Die Frage ────────────────────────────────────────────────────────

    /**
     * Ist diese Funktion für dieses Konto an?
     *
     * Reihenfolge mit Absicht:
     *   1. Ohne Anmeldung nichts.
     *   2. Die Sperre gilt für jeden und als Erstes — auch für die
     *      Administration (siehe LSV07S_Rechte::gesperrt).
     *   3. Eine Funktion, die der Katalog nicht kennt, ist aus.
     *   4. Der globale Schalter: aus ist aus, für alle.
     *   5. Die Administration darf alles, was global an ist. Sonst könnte
     *      sich die Verwaltung aussperren und die Schalter nicht mehr
     *      erreichen.
     *   6. Der Schalter des Kontos.
     */
    public static function an( $funktion, $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid ) return false;
        if ( LSV07S_Rechte::gesperrt( $uid ) ) return false;
        $funktion = (string) $funktion;
        /* Eine Funktion, die es nicht gibt, ist aus. Sonst wäre ein
           Tippfehler im Schlüssel für die Administration „an" (sie darf
           ja alles, was global an ist) und für alle anderen „aus" — und
           das fällt niemandem auf. Zugleich ist das die Antwort für
           Sparten, die eine Ansicht nicht haben: `triathlon/springer`
           gibt es nicht. */
        if ( ! self::gibt( $funktion ) ) return false;
        if ( ! self::global_an( $funktion ) ) return false;
        if ( LSV07S_Rechte::ist_admin( $uid ) ) return true;
        return self::konto_an( $funktion, $uid );
    }

    /** Mindestens eine dieser Funktionen. */
    public static function an_eines( array $funktionen, $uid = 0 ) {
        foreach ( $funktionen as $f ) {
            if ( self::an( $f, $uid ) ) return true;
        }
        return false;
    }

    /** Der globale Schalter. Was nicht in der Tabelle steht, ist an. */
    public static function global_an( $funktion ) {
        $aus = self::global_aus();
        return ! isset( $aus[ (string) $funktion ] );
    }

    /**
     * Die global abgeschalteten Funktionen.
     *
     * Gespeichert wird nur, was AUS ist. Damit ist eine neue Funktion
     * von selbst an, und die Tabelle bleibt klein.
     */
    public static function global_aus() {
        global $wpdb;
        if ( self::$g !== null ) return self::$g;
        self::$g = [];
        if ( ! self::tabelle_da( self::T_GLOBAL ) ) return self::$g;
        $zeilen = (array) $wpdb->get_results(
            'SELECT funktion FROM ' . self::tabelle( self::T_GLOBAL )
            . ' WHERE an = 0', ARRAY_A );
        foreach ( $zeilen as $z ) self::$g[ (string) $z['funktion'] ] = true;
        return self::$g;
    }

    /** Der Schalter eines Kontos. Was nicht in der Tabelle steht, ist aus. */
    public static function konto_an( $funktion, $uid ) {
        $an = self::konto_liste( $uid );
        return isset( $an[ (string) $funktion ] );
    }

    /**
     * Die eingeschalteten Funktionen eines Kontos.
     *
     * Gespeichert wird nur, was AN ist — der umgekehrte Fall zum
     * globalen Schalter, und aus demselben Grund: Die Voreinstellung
     * braucht keine Zeile.
     */
    public static function konto_liste( $uid ) {
        global $wpdb;
        $uid = (int) $uid;
        if ( isset( self::$k[ $uid ] ) ) return self::$k[ $uid ];
        self::$k[ $uid ] = [];
        if ( ! $uid || ! self::tabelle_da( self::T_KONTO ) ) return self::$k[ $uid ];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT funktion FROM ' . self::tabelle( self::T_KONTO )
            . ' WHERE user_id = %d AND an = 1', $uid ), ARRAY_A );
        foreach ( $zeilen as $z ) self::$k[ $uid ][ (string) $z['funktion'] ] = true;
        return self::$k[ $uid ];
    }

    /**
     * Kommt dieses Konto überhaupt herein?
     *
     * Ja, wenn für es mindestens eine Funktion an ist. Die Administration
     * kommt immer herein, solange sie nicht gesperrt ist — sie muss die
     * Schalter erreichen können.
     */
    public static function zugang( $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid || LSV07S_Rechte::gesperrt( $uid ) ) return false;
        if ( LSV07S_Rechte::ist_admin( $uid ) ) return true;
        foreach ( array_keys( self::konto_liste( $uid ) ) as $f ) {
            if ( self::gibt( $f ) && self::global_an( $f ) ) return true;
        }
        return false;
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /** Darf dieses Konto Schalter setzen? Nur WordPress-Administratoren. */
    public static function darf_schalten( $uid = 0 ) {
        return LSV07S_Rechte::ist_admin( $uid );
    }

    /** Einen globalen Schalter setzen. */
    public static function global_setzen( $funktion, $an ) {
        global $wpdb;
        if ( ! self::darf_schalten() ) {
            return 'Schalter setzen darf nur ein WordPress-Administrator.';
        }
        $funktion = (string) $funktion;
        if ( ! self::gibt( $funktion ) ) return 'Diese Funktion gibt es nicht.';
        if ( ! self::tabelle_da( self::T_GLOBAL ) ) return 'Die Tabelle fehlt.';
        $t = self::tabelle( self::T_GLOBAL );
        $da = $wpdb->get_var( $wpdb->prepare(
            "SELECT funktion FROM $t WHERE funktion = %s", $funktion ) );
        $werte = [ 'an' => $an ? 1 : 0, 'geaendert_am' => LSV07S_Datum::jetzt(),
                   'geaendert_von' => (int) get_current_user_id() ];
        if ( $da === null ) {
            $werte['funktion'] = $funktion;
            $wpdb->insert( $t, $werte, [ '%d', '%s', '%d', '%s' ] );
        } else {
            $wpdb->update( $t, $werte, [ 'funktion' => $funktion ],
                [ '%d', '%s', '%d' ], [ '%s' ] );
        }
        self::vergessen();
        LSV07S_Log::schreibe( 'schalter.global', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'funktion',
            'ziel_id' => $funktion,
            'ziel_name' => self::katalog()[ $funktion ]['name'] ?? $funktion,
            'details' => $an ? 'an' : 'aus — für alle',
        ] );
        return [ 'funktion' => $funktion, 'an' => (bool) $an,
                 'message' => ( self::katalog()[ $funktion ]['name'] ?? $funktion )
                            . ( $an ? ' ist wieder an.'
                                    : ' ist aus — für alle, auch für die, bei denen '
                                    . 'sie persönlich an steht.' ) ];
    }

    /**
     * Die Schalter eines Kontos setzen.
     *
     * `$an` ist die ganze Liste der Funktionen, die an sein sollen. Was
     * nicht darin steht, wird aus. Eine Liste statt einzelner Häkchen,
     * damit nicht zwei halbe Zustände entstehen, wenn eine Antwort
     * verlorengeht.
     */
    public static function konto_setzen( $uid, array $an ) {
        global $wpdb;
        if ( ! self::darf_schalten() ) {
            return 'Schalter setzen darf nur ein WordPress-Administrator.';
        }
        $uid = (int) $uid;
        if ( ! $uid ) return 'Welches Konto?';
        if ( ! self::tabelle_da( self::T_KONTO ) ) return 'Die Tabelle fehlt.';

        $katalog = self::katalog();
        $soll = [];
        foreach ( $an as $f ) {
            $f = (string) $f;
            if ( isset( $katalog[ $f ] ) ) $soll[ $f ] = true;
        }

        $t = self::tabelle( self::T_KONTO );
        $vorher = self::konto_liste( $uid );
        $wpdb->delete( $t, [ 'user_id' => $uid ], [ '%d' ] );
        foreach ( array_keys( $soll ) as $f ) {
            $wpdb->insert( $t, [
                'user_id' => $uid, 'funktion' => $f, 'an' => 1,
                'geaendert_am' => LSV07S_Datum::jetzt(),
                'geaendert_von' => (int) get_current_user_id(),
            ], [ '%d', '%s', '%d', '%s', '%d' ] );
        }
        self::vergessen( $uid );

        $dazu = array_diff( array_keys( $soll ), array_keys( $vorher ) );
        $weg  = array_diff( array_keys( $vorher ), array_keys( $soll ) );
        LSV07S_Log::schreibe( 'schalter.konto', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'konto', 'ziel_id' => $uid,
            'ziel_name' => LSV07S_Person::konto_name( $uid ),
            'details' => count( $soll ) . ' an'
                       . ( $dazu ? '; dazu: ' . implode( ', ', $dazu ) : '' )
                       . ( $weg ? '; weg: ' . implode( ', ', $weg ) : '' ),
        ] );
        return [ 'user_id' => $uid, 'an' => array_keys( $soll ),
                 'message' => count( $soll ) . ' Funktion(en) an, '
                            . ( count( $katalog ) - count( $soll ) ) . ' aus.' ];
    }

    /**
     * Einmalig aus der alten Rechtetabelle übernehmen.
     *
     * Vor den Schaltern entschieden Rechte (`lsv07i_user_rechte`), und
     * der interne Bereich entscheidet weiter so. Damit nicht jedes Konto
     * von Hand neu gesetzt werden muss, liest das hier einmal nach, wer
     * welche Rechte hat, und schaltet die Funktionen an, die diese
     * Rechte geöffnet hätten.
     *
     * Von Hand angestossen, nicht automatisch: Eine Übernahme, die beim
     * Aktivieren von selbst läuft, schaltet irgendwann etwas ein, das
     * längst abgeschaltet war.
     */
    public static function aus_rechten( $uid = 0 ) {
        global $wpdb;
        if ( ! self::darf_schalten() ) {
            return 'Übernehmen darf nur ein WordPress-Administrator.';
        }
        if ( ! LSV07S_Tabellen::da( 'rechte' ) ) {
            return 'Die alte Rechtetabelle gibt es hier nicht — es ist nichts zu '
                 . 'übernehmen.';
        }
        $t = LSV07S_Tabellen::t( 'rechte' );
        $wo = $uid ? $wpdb->prepare( ' WHERE user_id = %d', (int) $uid ) : '';
        $zeilen = (array) $wpdb->get_results(
            "SELECT user_id, recht FROM $t$wo", ARRAY_A );
        if ( ! $zeilen ) {
            return 'In der alten Tabelle steht nichts' . ( $uid ? ' für dieses Konto' : '' )
                 . '.';
        }

        $je_konto = [];
        foreach ( $zeilen as $z ) {
            $f = self::funktion_zu_recht( (string) $z['recht'] );
            if ( $f === '' ) continue;
            $je_konto[ (int) $z['user_id'] ][ $f ] = true;
        }

        $zahl = 0;
        foreach ( $je_konto as $konto => $funktionen ) {
            /* Was schon an ist, bleibt an: Übernehmen soll niemandem
               etwas wegnehmen, das jemand von Hand gesetzt hat. */
            $zusammen = array_unique( array_merge(
                array_keys( self::konto_liste( $konto ) ), array_keys( $funktionen ) ) );
            $erg = self::konto_setzen( $konto, $zusammen );
            if ( is_array( $erg ) ) $zahl++;
        }
        LSV07S_Log::schreibe( 'schalter.uebernahme', [
            'bereich' => 'verwaltung',
            'details' => $zahl . ' Konto/Konten aus den alten Rechten übernommen',
        ] );
        return [ 'konten' => $zahl,
                 'message' => $zahl . ' Konto/Konten übernommen. Bitte durchsehen — '
                            . 'die alten Rechte waren gröber als die Schalter.' ];
    }

    /**
     * Welche Funktion ein altes Recht geöffnet hätte.
     *
     * Nur für die Übernahme. Danach gibt es diese Namen in SwimSys nicht
     * mehr.
     */
    public static function funktion_zu_recht( $recht ) {
        $r = (string) $recht;
        $fest = [
            'schwimmen.mannschaft.read'     => 'schwimmen/mannschaft',
            'schwimmen.mannschaft.create'   => 'schwimmen/mannschaft',
            'schwimmen.mannschaft.update'   => 'schwimmen/mannschaft',
            'schwimmen.mannschaft.read_all' => 'schwimmen/alle',
            'schwimmen.schwimmer.read'      => 'schwimmen/mannschaft',
            'schwimmen.schwimmer.update'    => 'schwimmen/mannschaft',
            'schwimmen.schwimmer.create'    => 'schwimmen/mannschaft',
            'schwimmen.anw.read'            => 'schwimmen/anwesenheit',
            'schwimmen.anw.update'          => 'schwimmen/anwesenheit',
            'schwimmen.anw.ausfall'         => 'schwimmen/anwesenheit',
            'mannschaftsbuch.read'          => 'schwimmen/anwesenheit',
            'mannschaftsbuch.edit'          => 'schwimmen/anwesenheit',
            'schwimmen.springer.read'       => 'schwimmen/springer',
            'schwimmen.springer.eintragen'  => 'schwimmen/springer',
            'schwimmen.springer.austragen'  => 'schwimmen/springer',
            'attest.read'                   => 'schwimmen/atteste',
            'attest.edit'                   => 'schwimmen/atteste',
            'attest.mail'                   => 'schwimmen/atteste',
            'schwimmen.datei.read'          => 'schwimmen/dateien',
            'schwimmen.datei.upload'        => 'schwimmen/dateien',
            'schwimmen.datei.delete'        => 'schwimmen/dateien',
            'schwimmen.akte.read'           => 'schwimmen/akte',
            'schwimmen.wettkampf.read'      => 'schwimmen/wettkampf',
            'schwimmen.meldung.read'        => 'schwimmen/meldung',
            'schwimmen.bestzeit.read'       => 'schwimmen/bestzeit',
            'schwimmen.trainer.read'        => 'schwimmen/trainer',
            'schwimmen.trainingsplan.read'  => 'schwimmen/trainingsplan',
            'schwimmen.reflexion.read'      => 'schwimmen/reflexion',
            'schwimmen.eigen.read'          => 'schwimmen/eigene',
            'abrechnung.eigen.read'         => 'abrechnung/stunden',
            'abrechnung.wart.read'          => 'abrechnung/uebersicht',
            'abrechnung.kasse.read'         => 'abrechnung/uebersicht',
            'nachrichten.read'              => 'reden/nachrichten',
            'tickets.read'                  => 'reden/anfragen',
            'tickets.read_all'              => 'reden/anfragen_alle',
            'admin.config.read'             => 'verwaltung/einstellungen',
            'admin.log.read'                => 'verwaltung/protokoll',
        ];
        if ( isset( $fest[ $r ] ) ) return $fest[ $r ];

        /* Alles mit demselben Anfang wie eine feste Zeile folgt ihr:
           schwimmen.wettkampf.update → schwimmen/wettkampf. */
        foreach ( $fest as $alt => $neu ) {
            $stamm = substr( $alt, 0, (int) strrpos( $alt, '.' ) );
            if ( $stamm !== '' && strpos( $r, $stamm . '.' ) === 0 ) return $neu;
        }

        /* Triathlon und Fitness: dieselben Endungen, andere Sparte. */
        foreach ( [ LSV07S_Sparte::TRIATHLON, LSV07S_Sparte::FITNESS ] as $sp ) {
            if ( strpos( $r, $sp . '.' ) !== 0 ) continue;
            $gruppe = LSV07S_Sparte::feld( $sp, 'recht_gruppe' );
            $sportler = LSV07S_Sparte::feld( $sp, 'recht_sportler' );
            if ( strpos( $r, $sp . '.' . $gruppe . '.read_all' ) === 0 ) {
                return $sp . '/alle';
            }
            if ( strpos( $r, $sp . '.' . $gruppe . '.' ) === 0
                 || strpos( $r, $sp . '.' . $sportler . '.' ) === 0 ) {
                return $sp . '/' . $gruppe;
            }
            if ( strpos( $r, $sp . '.anw.' ) === 0 ) return $sp . '/anwesenheit';
            if ( strpos( $r, $sp . '.trainer.' ) === 0 ) return $sp . '/trainer';
            if ( strpos( $r, $sp . '.eigen.' ) === 0 ) return $sp . '/eigene';
        }
        return '';
    }

    // ── Die Oberfläche ───────────────────────────────────────────────────

    /**
     * Die Karte: Bereiche mit ihren angeschalteten Ansichten.
     *
     * Ein Bereich steht genau dann oben, wenn mindestens eine seiner
     * Ansichten an ist. Es gibt keine gesonderte Bereichsregel.
     */
    public static function karte( $uid = 0 ) {
        $karte = [];
        foreach ( self::katalog() as $schluessel => $f ) {
            if ( ( $f['art'] ?? 'ansicht' ) !== 'ansicht' ) continue;
            [ $bereich, $teil ] = array_pad( explode( '/', $schluessel, 2 ), 2, '' );
            if ( ! self::an( $schluessel, $uid ) ) continue;
            if ( ! isset( $karte[ $bereich ] ) ) {
                $karte[ $bereich ] = [
                    'name' => self::BEREICHE[ $bereich ] ?? $bereich,
                    'teile' => [],
                ];
            }
            $karte[ $bereich ]['teile'][] = [ 'teil' => $teil, 'name' => $f['name'] ];
        }

        /* Die Schalterseite selbst steht NICHT im Katalog: Sie ist keine
           Funktion, die man abschalten könnte, sondern die Stelle, an der
           abgeschaltet wird. Sie gehört WordPress-Administratoren — wer
           sie abschalten könnte, könnte sich selbst aussperren. */
        if ( self::darf_schalten( $uid ) ) {
            if ( ! isset( $karte['verwaltung'] ) ) {
                $karte['verwaltung'] = [ 'name' => self::BEREICHE['verwaltung'],
                                         'teile' => [] ];
            }
            array_unshift( $karte['verwaltung']['teile'],
                [ 'teil' => 'schalter', 'name' => 'Funktionen' ] );
        }
        return $karte;
    }

    /**
     * Alles für die Schalterseite: der Katalog, was global aus ist, und
     * die Konten mit ihren Schaltern.
     */
    public static function uebersicht() {
        if ( ! self::darf_schalten() ) {
            return 'Diese Seite ist für WordPress-Administratoren.';
        }
        $katalog = [];
        foreach ( self::katalog() as $schluessel => $f ) {
            [ $bereich ] = array_pad( explode( '/', $schluessel, 2 ), 2, '' );
            $katalog[] = [
                'funktion' => $schluessel,
                'bereich' => self::BEREICHE[ $bereich ] ?? $bereich,
                'name' => $f['name'],
                'was' => $f['was'] ?? '',
                'art' => $f['art'] ?? 'ansicht',
                'global_an' => self::global_an( $schluessel ),
            ];
        }

        $konten = [];
        foreach ( self::konten_mit_zugang() as $k ) $konten[] = $k;

        return [ 'katalog' => $katalog, 'konten' => $konten,
                 'anmeldung' => 'Angemeldet wird über das WordPress-Konto. SwimSys '
                              . 'hat keine eigene Anmeldung.' ];
    }

    /**
     * Die Konten, um die es geht: alle, für die etwas an ist, dazu alle
     * WordPress-Konten, damit man jemanden auch neu einschalten kann.
     */
    public static function konten_mit_zugang() {
        $raus = [];
        if ( ! function_exists( 'get_users' ) ) return $raus;
        foreach ( (array) get_users( [ 'fields' => [ 'ID', 'display_name' ],
                                       'number' => 500 ] ) as $u ) {
            $uid = (int) ( is_object( $u ) ? $u->ID : ( $u['ID'] ?? 0 ) );
            if ( ! $uid ) continue;
            $an = array_keys( self::konto_liste( $uid ) );
            $raus[] = [
                'id' => $uid,
                'name' => LSV07S_Person::konto_name( $uid ),
                'admin' => LSV07S_Rechte::ist_admin( $uid ),
                'gesperrt' => LSV07S_Rechte::gesperrt( $uid ),
                'an' => $an,
                'zahl' => count( $an ),
            ];
        }
        usort( $raus, function ( $a, $b ) {
            if ( $a['zahl'] !== $b['zahl'] ) return $b['zahl'] - $a['zahl'];
            return strcmp( $a['name'], $b['name'] );
        } );
        return $raus;
    }

    // ── Tabellen ─────────────────────────────────────────────────────────

    /**
     * Die eigenen Tabellen. Diese legt SwimSys **immer** an, auch wenn
     * der interne Bereich läuft: Sie tragen den eigenen Präfix, der
     * interne Bereich kennt sie nicht, und es gibt nichts, worüber zwei
     * Plugins hier gegeneinander arbeiten könnten.
     */
    public static function tabelle( $name ) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    private static $da = [];

    public static function tabelle_da( $name ) {
        global $wpdb;
        if ( isset( self::$da[ $name ] ) ) return self::$da[ $name ];
        $voll = self::tabelle( $name );
        $gefunden = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $voll ) );
        return self::$da[ $name ] = ( (string) $gefunden === $voll );
    }

    /** Das Schema der eigenen Tabellen — an einer Stelle. */
    public static function tabellen() {
        return [
            self::T_GLOBAL => "
                funktion      VARCHAR(60) NOT NULL,
                an            TINYINT(1) NOT NULL DEFAULT 1,
                geaendert_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (funktion)",
            self::T_KONTO => "
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id       BIGINT UNSIGNED NOT NULL,
                funktion      VARCHAR(60) NOT NULL,
                an            TINYINT(1) NOT NULL DEFAULT 1,
                geaendert_am  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                geaendert_von BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_konto_funktion (user_id, funktion),
                KEY idx_funktion (funktion)",
        ];
    }

    /** Für Prüfungen und nach dem Setzen. */
    public static function vergessen( $uid = null ) {
        self::$g = null;
        self::$da = [];
        if ( $uid === null ) self::$k = [];
        else unset( self::$k[ (int) $uid ] );
    }
}
