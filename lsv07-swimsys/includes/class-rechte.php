<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Wer darf was — eine Stelle, und zwei behobene Löcher.
 *
 * Die Rechte selbst liegen dort, wo sie immer lagen: in
 * lsv07i_user_rechte, vergeben in der Rechteverwaltung des internen
 * Bereichs. SwimSys legt kein zweites Rechtesystem daneben. Was neu ist,
 * ist die Auslegung — und genau dort steckten zwei Fehler:
 *
 * 1. Ein gesperrtes Konto war nicht gesperrt. Die Sperrliste
 *    (blocked_user_ids) wurde nur in den is_*-Methoden geprüft, nicht in
 *    der Rechtefrage selbst. Weil Administratoren dort einen Freibrief
 *    haben und 26 Stellen im Code zusätzlich direkt
 *    current_user_can('administrator') fragen, bestand ein gesperrter
 *    Administrator jede Prüfung. Hier gilt die Sperre für JEDEN und als
 *    Erstes.
 *
 *    Offen gesagt: Gegen jemanden mit WordPress-Administratorrechten ist
 *    das keine Sicherheitsgrenze — wer das Backend hat, kann das Plugin
 *    abschalten. Die Sperre ist eine Vereinsregel im Programm, kein
 *    Schutz gegen den Serveradministrator. Sie wirkt jetzt aber dort, wo
 *    sie wirken soll, und sie lässt sich in der Oberfläche setzen.
 *
 * 2. Ein Mitgliedsrecht öffnete den Trainerbereich. Die alte Frage
 *    „gehört diese Person in den Bereich Schwimmen?" wurde mit „hat sie
 *    irgendein schwimmen.*-Recht?" beantwortet — und öffnete damit ALLE
 *    Kacheln des Bereichs. Ein Sportler mit vier Leserechten sah deshalb
 *    dieselben neun Kacheln wie sein Trainer: Anwesenheit aller,
 *    Mannschaftsbuch, Atteste, Akte. Das ist die schwerste
 *    Datenschutzfolge im alten Bereich.
 *
 *    Hier gibt es die grobe Bereichsfrage nicht mehr. Jede Ansicht nennt
 *    ihr eigenes Recht, und sichtbar ist, was das Recht öffnet. Ob ein
 *    Bereich überhaupt erscheint, ergibt sich daraus — er erscheint, wenn
 *    mindestens eine seiner Ansichten sichtbar ist. Eine Regel weniger,
 *    die auseinanderlaufen kann.
 */
class LSV07S_Rechte {

    /* Die Rechte, die SwimSys abfragt. Die Zeichenketten sind dieselben
       wie im internen Bereich — dieselben Daten heisst auch dieselben
       Namen. */
    const SCHWIMMEN_MANNSCHAFT_READ     = 'schwimmen.mannschaft.read';
    const SCHWIMMEN_MANNSCHAFT_READ_ALL = 'schwimmen.mannschaft.read_all';
    const SCHWIMMEN_MANNSCHAFT_CREATE   = 'schwimmen.mannschaft.create';
    const SCHWIMMEN_ANW_READ            = 'schwimmen.anw.read';
    const SCHWIMMEN_ANW_UPDATE          = 'schwimmen.anw.update';
    const SCHWIMMEN_ANW_AUSFALL         = 'schwimmen.anw.ausfall';
    const SCHWIMMEN_EIGEN_READ          = 'schwimmen.eigen.read';
    const SCHWIMMEN_WETTKAMPF_READ      = 'schwimmen.wettkampf.read';
    const SCHWIMMEN_MELDUNG_READ        = 'schwimmen.meldung.read';
    const SCHWIMMEN_BESTZEIT_READ       = 'schwimmen.bestzeit.read';
    const SCHWIMMEN_SPRINGER_READ       = 'schwimmen.springer.read';
    const SCHWIMMEN_AKTE_READ           = 'schwimmen.akte.read';
    const SCHWIMMEN_TRAINER_READ        = 'schwimmen.trainer.read';
    const SCHWIMMEN_TRAININGSPLAN_READ  = 'schwimmen.trainingsplan.read';
    const SCHWIMMEN_REFLEXION_READ      = 'schwimmen.reflexion.read';
    const MANNSCHAFTSBUCH_READ          = 'mannschaftsbuch.read';
    const MANNSCHAFTSBUCH_EDIT          = 'mannschaftsbuch.edit';
    const ATTEST_READ                   = 'attest.read';
    const ATTEST_EDIT                   = 'attest.edit';
    const ABRECHNUNG_EIGEN_READ         = 'abrechnung.eigen.read';
    const ABRECHNUNG_WART_READ          = 'abrechnung.wart.read';
    const ABRECHNUNG_KASSE_READ         = 'abrechnung.kasse.read';
    const NACHRICHTEN_READ              = 'nachrichten.read';
    const TICKETS_READ_ALL              = 'tickets.read_all';
    const ADMIN_CONFIG_READ             = 'admin.config.read';

    /** Rechte, die nur ihre eigene Ansicht öffnen und niemals mehr. */
    const NUR_EIGENE_ANSICHT = [
        self::SCHWIMMEN_MANNSCHAFT_READ_ALL,
        self::SCHWIMMEN_EIGEN_READ,
        self::SCHWIMMEN_BESTZEIT_READ,
        self::SCHWIMMEN_SPRINGER_READ,
    ];

    private static $zwischen = [];

    // ── Die Sperre ───────────────────────────────────────────────────────

    /**
     * Steht dieses Konto auf der Sperrliste?
     *
     * Die Liste steht in lsv07i_config unter blocked_user_ids, als Liste
     * von Konto-Nummern mit Komma. Dieselbe Quelle wie im internen
     * Bereich — eine gesperrte Person soll nicht in einem Plugin gesperrt
     * und im anderen frei sein.
     */
    public static function gesperrt( $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid ) return false;
        $liste = (string) LSV07S_Tabellen::config( 'blocked_user_ids', '' );
        if ( trim( $liste ) === '' ) return false;
        foreach ( explode( ',', $liste ) as $teil ) {
            if ( (int) trim( $teil ) === $uid ) return true;
        }
        return false;
    }

    // ── Die Rechtefrage ──────────────────────────────────────────────────

    /**
     * Hat dieses Konto dieses Recht?
     *
     * Reihenfolge mit Absicht: erst die Sperre, dann die Administration,
     * dann die vergebenen Rechte. Wer gesperrt ist, kommt an der
     * Administratorzeile nicht vorbei — das war das erste Loch.
     */
    public static function darf( $recht, $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid ) return false;
        if ( self::gesperrt( $uid ) ) return false;
        if ( self::ist_admin( $uid ) ) return true;
        return in_array( (string) $recht, self::rechte_von( $uid ), true );
    }

    /** Mindestens eines dieser Rechte. */
    public static function darf_eines( array $rechte, $uid = 0 ) {
        foreach ( $rechte as $r ) {
            if ( self::darf( $r, $uid ) ) return true;
        }
        return false;
    }

    /**
     * Administration — ohne Sperre.
     *
     * Dass ein WordPress-Administrator alles darf, ist das Sicherheitsventil
     * gegen das Aussperren der eigenen Verwaltung. Es gilt aber nur, solange
     * das Konto nicht gesperrt ist; `darf()` fragt die Sperre vorher.
     */
    public static function ist_admin( $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid ) return false;
        if ( self::gesperrt( $uid ) ) return false;
        return user_can( $uid, 'administrator' );
    }

    /** Die ausdrücklich vergebenen Rechte dieses Kontos. */
    public static function rechte_von( $uid ) {
        $uid = (int) $uid;
        if ( isset( self::$zwischen[ $uid ] ) ) return self::$zwischen[ $uid ];
        global $wpdb;
        if ( ! $uid || ! LSV07S_Tabellen::da( 'rechte' ) ) return self::$zwischen[ $uid ] = [];
        $rechte = $wpdb->get_col( $wpdb->prepare(
            'SELECT recht FROM ' . LSV07S_Tabellen::t( 'rechte' ) . ' WHERE user_id = %d', $uid ) );
        return self::$zwischen[ $uid ] = array_map( 'strval', (array) $rechte );
    }

    /**
     * Sind diesem Konto in SwimSys überhaupt Rechte vergeben?
     *
     * Der interne Bereich kennt einen Rückfall: Wer nie Rechte bekommen
     * hat, wird nach seiner alten WordPress-Rolle behandelt. Diesen
     * Rückfall gibt es hier mit Absicht nicht. Er rät, und beim Raten über
     * Rechte ist der Fehler in eine Richtung teuer: Jemand sieht etwas,
     * das er nicht sehen soll. SwimSys zeigt stattdessen offen „für dieses
     * Konto sind noch keine Rechte vergeben" und nennt den Weg dorthin.
     */
    public static function hat_rechte( $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid ) return false;
        if ( self::ist_admin( $uid ) ) return true;
        return self::rechte_von( $uid ) !== [];
    }

    /** Für Prüfungen und nach dem Vergeben von Rechten. */
    public static function vergessen( $uid = null ) {
        if ( $uid === null ) self::$zwischen = [];
        else unset( self::$zwischen[ (int) $uid ] );
    }

    // ── Reichweite ───────────────────────────────────────────────────────

    /**
     * Sieht dieses Konto über die eigenen Mannschaften hinaus?
     *
     * Im alten Bereich stand diese Frage siebenmal als
     * `is_admin() || is_schwimmwart()` im Code, dazu einmal als eigene
     * Methode, die an zwei Stellen benutzt wurde. Hier einmal.
     */
    public static function sieht_alles( $uid = 0 ) {
        return self::darf_eines( [
            self::SCHWIMMEN_MANNSCHAFT_CREATE,   // klassische Schwimmwart-Aufgabe
            self::SCHWIMMEN_MANNSCHAFT_READ_ALL,
        ], $uid ) || self::ist_admin( $uid );
    }

    /**
     * Darf dieses Konto in dieser Sparte überhaupt Sportlerdaten sehen?
     *
     * Eine Stelle, weil es sonst zwei werden: Die Suche hatte diese Frage
     * zuerst selbst beantwortet und dabei nur die Leserechte geprüft — ein
     * Wart, der Mannschaften anlegen darf, fand damit keinen einzigen
     * Schwimmer, obwohl die Liste ihm alle zeigt. Zwei Regeln für dieselbe
     * Frage laufen auseinander; diese hier gilt für beide.
     */
    public static function sieht_sportler( $sparte, $uid = 0 ) {
        if ( self::sieht_alles( $uid ) ) return true;
        $rechte = $sparte === LSV07S_Sparte::SCHWIMMEN
            ? [ self::SCHWIMMEN_MANNSCHAFT_READ, self::SCHWIMMEN_MANNSCHAFT_READ_ALL ]
            : [ LSV07S_Sparte::recht( $sparte, 'sportler.read' ),
                LSV07S_Sparte::recht( $sparte, 'gruppe.read' ) ];
        return self::darf_eines( $rechte, $uid );
    }

    /** Die Mannschafts-Nummern, für die dieses Konto zuständig ist. Leeres Feld heisst: keine. */
    public static function eigene_mannschaften( $uid = 0 ) {
        global $wpdb;
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid ) return [];
        if ( ! LSV07S_Tabellen::da( 'trainer' ) || ! LSV07S_Tabellen::da( 'trainer_mann' ) ) return [];
        $ids = $wpdb->get_col( $wpdb->prepare(
            'SELECT tm.mannschaft_id FROM ' . LSV07S_Tabellen::t( 'trainer_mann' ) . ' tm'
          . ' INNER JOIN ' . LSV07S_Tabellen::t( 'trainer' ) . ' t ON t.id = tm.trainer_id'
          . ' WHERE t.wp_user_id = %d', $uid ) );
        return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
    }

    /**
     * Darf dieses Konto in dieser Mannschaft arbeiten?
     *
     * Im alten Bereich gab es diese Antwort in fünf Fassungen: drei
     * wortgleiche Kopien von erlaubte_mannschaften(), eine mit anderem
     * Admin-Begriff, eine mit eigenem SQL. Eine davon prüfte WordPress-
     * Rollen statt Rechte und berücksichtigte die Mehrfachzuordnung von
     * Schwimmern, eine andere nur die alte team_id. Hier eine Fassung.
     */
    public static function darf_mannschaft( $mannschaft_id, $uid = 0 ) {
        $mannschaft_id = (int) $mannschaft_id;
        if ( $mannschaft_id <= 0 ) return false;
        if ( self::sieht_alles( $uid ) ) return true;
        return in_array( $mannschaft_id, self::eigene_mannschaften( $uid ), true );
    }

    // ── Die Ansichten ────────────────────────────────────────────────────

    /**
     * Jede Ansicht nennt ihr Recht — und nur das.
     *
     * Das ist die Liste, aus der die Oberfläche entsteht: Bereich, Teil,
     * Name, Recht. Es gibt keinen zweiten Ort, an dem Sichtbarkeit
     * entschieden wird, und keine Bereichsfrage, die mehr öffnet als das
     * einzelne Recht. Der Server prüft dasselbe Recht noch einmal, wenn
     * der Endpunkt gerufen wird; die Liste steuert also, was man SIEHT,
     * nicht, was man DARF.
     */
    public static function ansichten() {
        $a = [
            // Bereich Start ist für jeden da, der überhaupt hereinkommt.
            [ 'bereich' => 'start',     'teil' => 'aufgaben',   'name' => 'Meine Aufgaben',    'recht' => '' ],

            [ 'bereich' => 'schwimmen', 'teil' => 'mannschaft', 'name' => 'Mannschaften',
              'recht' => [ self::SCHWIMMEN_MANNSCHAFT_READ, self::SCHWIMMEN_MANNSCHAFT_READ_ALL ] ],
            [ 'bereich' => 'schwimmen', 'teil' => 'anwesenheit','name' => 'Anwesenheit',
              'recht' => [ self::SCHWIMMEN_ANW_READ, self::MANNSCHAFTSBUCH_READ ] ],
            [ 'bereich' => 'schwimmen', 'teil' => 'atteste',    'name' => 'Atteste',
              'recht' => [ self::ATTEST_READ, self::ATTEST_EDIT ] ],
            [ 'bereich' => 'schwimmen', 'teil' => 'wettkampf',  'name' => 'Wettkämpfe',
              'recht' => self::SCHWIMMEN_WETTKAMPF_READ ],
            [ 'bereich' => 'schwimmen', 'teil' => 'meldung',    'name' => 'Meldungen',
              'recht' => self::SCHWIMMEN_MELDUNG_READ ],
            [ 'bereich' => 'schwimmen', 'teil' => 'bestzeit',   'name' => 'Bestzeiten',
              'recht' => self::SCHWIMMEN_BESTZEIT_READ ],
            [ 'bereich' => 'schwimmen', 'teil' => 'springer',   'name' => 'Springer',
              'recht' => self::SCHWIMMEN_SPRINGER_READ ],
            [ 'bereich' => 'schwimmen', 'teil' => 'akte',       'name' => 'Akte',
              'recht' => self::SCHWIMMEN_AKTE_READ ],
            [ 'bereich' => 'schwimmen', 'teil' => 'trainingsplan','name' => 'Trainingsplan',
              'recht' => self::SCHWIMMEN_TRAININGSPLAN_READ ],
            [ 'bereich' => 'schwimmen', 'teil' => 'reflexion',  'name' => 'Reflexion',
              'recht' => self::SCHWIMMEN_REFLEXION_READ ],
            [ 'bereich' => 'schwimmen', 'teil' => 'trainer',    'name' => 'Trainerliste',
              'recht' => self::SCHWIMMEN_TRAINER_READ ],
            /* „Meine Daten": der Grund, warum ein Sportnutzer überhaupt
               hereinkommt. Früher öffnete dieses Recht neun Kacheln. */
            [ 'bereich' => 'schwimmen', 'teil' => 'eigene',     'name' => 'Meine Daten',
              'recht' => self::SCHWIMMEN_EIGEN_READ ],
        ];

        foreach ( [ LSV07S_Sparte::TRIATHLON, LSV07S_Sparte::FITNESS ] as $sp ) {
            $a[] = [ 'bereich' => $sp, 'teil' => 'gruppe', 'name' => LSV07S_Sparte::wort( $sp, 'gruppe_mehrere' ),
                     'recht' => LSV07S_Sparte::recht( $sp, 'gruppe.read' ) ];
            $a[] = [ 'bereich' => $sp, 'teil' => 'anwesenheit', 'name' => 'Anwesenheit',
                     'recht' => LSV07S_Sparte::recht( $sp, 'anw.read' ) ];
            $a[] = [ 'bereich' => $sp, 'teil' => 'trainer', 'name' => 'Trainerliste',
                     'recht' => LSV07S_Sparte::recht( $sp, 'trainer.read' ) ];
            $a[] = [ 'bereich' => $sp, 'teil' => 'eigene', 'name' => 'Meine Daten',
                     'recht' => LSV07S_Sparte::recht( $sp, 'eigen.read' ) ];
        }

        $a[] = [ 'bereich' => 'abrechnung', 'teil' => 'eigene', 'name' => 'Meine Abrechnung',
                 'recht' => self::ABRECHNUNG_EIGEN_READ ];
        $a[] = [ 'bereich' => 'abrechnung', 'teil' => 'pruefen', 'name' => 'Prüfen',
                 'recht' => self::ABRECHNUNG_WART_READ ];
        $a[] = [ 'bereich' => 'abrechnung', 'teil' => 'kasse', 'name' => 'Kasse',
                 'recht' => self::ABRECHNUNG_KASSE_READ ];
        $a[] = [ 'bereich' => 'reden', 'teil' => 'nachrichten', 'name' => 'Nachrichten',
                 'recht' => self::NACHRICHTEN_READ ];
        $a[] = [ 'bereich' => 'reden', 'teil' => 'tickets', 'name' => 'Anfragen', 'recht' => '' ];
        $a[] = [ 'bereich' => 'verwaltung', 'teil' => 'einstellungen', 'name' => 'Einstellungen',
                 'recht' => self::ADMIN_CONFIG_READ ];
        $a[] = [ 'bereich' => 'verwaltung', 'teil' => 'protokoll', 'name' => 'Protokoll',
                 'recht' => self::ADMIN_CONFIG_READ ];

        return $a;
    }

    /** Die Namen der Bereiche, wie sie oben stehen. */
    const BEREICHE = [
        'start'      => 'Start',
        'schwimmen'  => 'Schwimmen',
        'triathlon'  => 'Triathlon',
        'fitness'    => 'Fitness',
        'abrechnung' => 'Abrechnung',
        'reden'      => 'Nachrichten & Anfragen',
        'verwaltung' => 'Verwaltung',
    ];

    /** Ist diese Ansicht für dieses Konto sichtbar? */
    public static function sieht_ansicht( array $ansicht, $uid = 0 ) {
        $recht = $ansicht['recht'] ?? '';
        if ( $recht === '' || $recht === [] ) {
            /* Keine Rechtsangabe heisst: für jeden, der überhaupt
               hereinkommt. Das ist nicht „für jeden" — ohne Zugang kommt
               niemand so weit (siehe LSV07S_Zugang). */
            return self::hat_zugang( $uid );
        }
        return self::darf_eines( (array) $recht, $uid );
    }

    /**
     * Die Karte für die Oberfläche: Bereiche mit ihren sichtbaren Teilen.
     *
     * Ein Bereich steht genau dann oben, wenn mindestens einer seiner
     * Teile sichtbar ist. Es gibt keine gesonderte Bereichsregel, die
     * dazu auch noch eine Meinung hätte.
     */
    public static function karte( $uid = 0 ) {
        $karte = [];
        foreach ( self::ansichten() as $an ) {
            $bereich = $an['bereich'];
            if ( in_array( $bereich, LSV07S_Sparte::alle(), true )
                 && ! LSV07S_Sparte::da( $bereich ) ) {
                continue;   // Sparte ist in dieser Anlage gar nicht eingerichtet
            }
            if ( ! self::sieht_ansicht( $an, $uid ) ) continue;
            if ( ! isset( $karte[ $bereich ] ) ) {
                $karte[ $bereich ] = [
                    'name'  => self::BEREICHE[ $bereich ] ?? $bereich,
                    'teile' => [],
                ];
            }
            $karte[ $bereich ]['teile'][] = [ 'teil' => $an['teil'], 'name' => $an['name'] ];
        }
        return $karte;
    }

    /**
     * Kommt dieses Konto überhaupt herein?
     *
     * Ja, wenn ihm irgendein Recht vergeben ist oder es die Administration
     * ist — und nicht, wenn es gesperrt ist. Gezählt werden nur Rechte,
     * die SwimSys kennt; eine leere Zeile in der Rechtetabelle macht
     * niemanden zum Nutzer.
     */
    public static function hat_zugang( $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid || self::gesperrt( $uid ) ) return false;
        if ( self::ist_admin( $uid ) ) return true;
        return self::rechte_von( $uid ) !== [];
    }
}
