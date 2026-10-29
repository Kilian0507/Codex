<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sperre, Administration und Reichweite.
 *
 * **Was hier NICHT mehr steht: Rechte.** SwimSys fragt die Rechtetabelle
 * des internen Bereichs (`lsv07i_user_rechte`) nicht mehr. Ob eine
 * Funktion für ein Konto offen ist, entscheidet ein Schalter —
 * siehe LSV07S_Schalter. Die alte Tabelle bleibt stehen, weil der
 * interne Bereich sie weiter liest; SwimSys schreibt dort nichts.
 *
 * Geblieben sind drei Fragen, die keine Schalterfragen sind:
 *
 * 1. **Die Sperre.** Ein gesperrtes Konto ist gesperrt — vor allem
 *    anderen und auch für die Administration. Im internen Bereich wurde
 *    die Sperrliste nur in den is_*-Methoden geprüft, nicht in der
 *    Zugriffsfrage selbst; weil Administratoren dort einen Freibrief
 *    haben und 26 Stellen zusätzlich direkt
 *    `current_user_can('administrator')` fragen, bestand ein gesperrter
 *    Administrator jede Prüfung.
 *
 *    Offen gesagt: Gegen jemanden mit WordPress-Administratorrechten ist
 *    das keine Sicherheitsgrenze — wer das Backend hat, kann das Plugin
 *    abschalten. Die Sperre ist eine Vereinsregel im Programm, kein
 *    Schutz gegen den Serveradministrator.
 *
 * 2. **Die Administration.** Ein WordPress-Administrator darf alles, was
 *    global an ist. Das ist das Ventil gegen das Aussperren der eigenen
 *    Verwaltung: Er muss die Schalterseite erreichen können.
 *
 * 3. **Die Reichweite.** „Nur die eigenen Mannschaften" oder „alle" ist
 *    keine Funktion, die man an- und abschaltet, sondern die Frage, wie
 *    weit jemand sieht. Sie hängt an einem Schalter je Sparte
 *    (`schwimmen/alle`) und an der Trainerzuordnung — und steht hier an
 *    einer Stelle, weil sie im internen Bereich in fünf Fassungen
 *    stand, von denen eine WordPress-Rollen prüfte und eine die
 *    Mehrfachzuordnung von Schwimmern überging.
 */
class LSV07S_Rechte {

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

    /**
     * Administration — ohne Sperre.
     *
     * Die Anmeldung läuft über WordPress; wer dort Administrator ist, ist
     * es auch hier. Gesperrt schlägt das.
     */
    public static function ist_admin( $uid = 0 ) {
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid ) return false;
        if ( self::gesperrt( $uid ) ) return false;
        return user_can( $uid, 'administrator' );
    }

    // ── Reichweite ───────────────────────────────────────────────────────

    /**
     * Sieht dieses Konto über die eigenen Mannschaften hinaus?
     *
     * Der Schalter `<sparte>/alle`. Ohne Sparte gilt die Frage „in
     * irgendeiner Sparte?" — das braucht die Saisongrenze, die nicht nach
     * Sparten unterscheidet.
     *
     * Die Sparte gehört dazu: Wer alle Schwimmmannschaften sieht, sieht
     * damit nicht die Triathlongruppen.
     */
    public static function sieht_alles( $sparte = null, $uid = 0 ) {
        if ( self::ist_admin( $uid ) ) return true;
        if ( $sparte === null ) {
            foreach ( LSV07S_Sparte::alle() as $s ) {
                if ( self::sieht_alles( $s, $uid ) ) return true;
            }
            return false;
        }
        return LSV07S_Schalter::an( $sparte . '/alle', $uid );
    }

    /**
     * Darf dieses Konto in dieser Sparte überhaupt Sportlerdaten sehen?
     *
     * Eine Stelle, weil es sonst zwei werden: Die Suche hatte diese Frage
     * zuerst selbst beantwortet und dabei nur die Leserechte geprüft — ein
     * Wart, der alle Mannschaften sieht, fand damit keinen einzigen
     * Schwimmer, obwohl die Liste ihm alle zeigt. Zwei Regeln für dieselbe
     * Frage laufen auseinander; diese hier gilt für beide.
     */
    public static function sieht_sportler( $sparte, $uid = 0 ) {
        if ( self::sieht_alles( $sparte, $uid ) ) return true;
        return LSV07S_Schalter::an( LSV07S_Sparte::funktion_gruppe( $sparte ), $uid );
    }

    /**
     * Die Mannschafts-Nummern einer Sparte, für die dieses Konto zuständig
     * ist. Leeres Feld heisst: keine.
     */
    public static function eigene_mannschaften( $sparte, $uid = 0 ) {
        return LSV07S_Mannschaft::eigene_ids( $sparte, $uid );
    }

    /**
     * Darf dieses Konto in dieser Mannschaft arbeiten?
     *
     * Im alten Bereich gab es diese Antwort in fünf Fassungen: drei
     * wortgleiche Kopien von erlaubte_mannschaften(), eine mit anderem
     * Admin-Begriff, eine mit eigenem SQL. Eine davon prüfte WordPress-
     * Rollen statt Rechte und berücksichtigte die Mehrfachzuordnung von
     * Schwimmern, eine andere nur die alte team_id. Hier eine Fassung.
     *
     * Die SPARTE muss dabeistehen. Die Nummern kommen aus drei
     * verschiedenen Tabellen (lsv07_gruppen, lsv07i_tri_gruppen,
     * lsv07i_fit_gruppen) und können sich überschneiden: Mannschaft 3 im
     * Schwimmen und Gruppe 3 im Triathlon sind zwei verschiedene Dinge mit
     * derselben Nummer. Eine Prüfung ohne Sparte öffnet die eine über die
     * Zuständigkeit für die andere — quer über die Sparten.
     */
    public static function darf_mannschaft( $sparte, $mannschaft_id, $uid = 0 ) {
        $mannschaft_id = (int) $mannschaft_id;
        if ( $mannschaft_id <= 0 ) return false;
        if ( ! LSV07S_Sparte::gibt( $sparte ) ) return false;
        if ( self::sieht_alles( $sparte, $uid ) ) return true;
        return in_array( $mannschaft_id, self::eigene_mannschaften( $sparte, $uid ), true );
    }

    /** Für Prüfungen. Die Schalter haben ihr eigenes Vergessen. */
    public static function vergessen( $uid = null ) {
        LSV07S_Schalter::vergessen( $uid );
    }
}
