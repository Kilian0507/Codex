<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Benachrichtigungen der Administration an alle Konten.
 *
 * Eine Mitteilung steht IM System, nicht in einer E-Mail: Sie geht an
 * jeden, der hereinkommt, und niemand kann sagen, in welchem fremden
 * Postfach sie dann liegen bleibt. Wer sie sehen muss, sieht sie beim
 * nächsten Öffnen — an der Glocke oben, mit einem Zähler für die
 * ungelesenen.
 *
 * Wer sie sehen darf: **jedes Konto, das überhaupt hereinkommt**. Dafür
 * gibt es keinen eigenen Schalter — eine Mitteilung „Das Becken ist am
 * Samstag gesperrt" nützt nichts, wenn sie der halben Mannschaft nicht
 * angezeigt wird. Geschrieben und gelöscht wird dagegen nur mit dem
 * Schalter `verwaltung/benachrichtigungen`.
 *
 * Fünf Dinge sind anders als im internen Bereich:
 *
 * 1. **Die Lesebestätigung sagt, dass sie eine ist.** Dort sieht die
 *    Administration, wer wann gelesen hat, und auf der Seite steht
 *    nichts darüber. Hier steht es im Text der Ansicht, und das Öffnen
 *    der Bestätigung steht im Protokoll: Wer mitliest, wer liest, soll
 *    das nicht unbemerkt tun.
 * 2. **Die Liste der Nummern wird vorbereitet, nicht eingesetzt.** Dort
 *    wird `implode(',', $ids)` in das SQL geschrieben. Die Nummern kommen
 *    aus der Datenbank, es ist also heute keine Lücke — aber es ist die
 *    Stelle, an der morgen eine entsteht.
 * 3. **Ein Titel ohne Text wird abgewiesen, und zwar vor dem Säubern.**
 *    Dort wird nach `sanitize_text_field` geprüft; wer nur `<b></b>`
 *    eintippt, bekommt dort „Bitte einen Titel angeben" — richtig, aber
 *    aus dem falschen Grund. Hier wird beides geprüft und gesagt, was
 *    fehlt.
 * 4. **Die Tabellen gehören zum Schema.** Dort legt sie der Endpunkt bei
 *    jedem Aufruf per `CREATE TABLE IF NOT EXISTS` an. Hier stehen sie in
 *    `LSV07S_Schema`, und SwimSys prüft nur, ob sie da sind.
 * 5. **Der Name des Lesers wird frisch geholt**, wenn das Konto noch da
 *    ist. Siehe `leser()` — die Kopie in der Spalte bleibt, aber sie ist
 *    nicht mehr das, was angezeigt wird.
 */
class LSV07S_Benachrichtigung {

    /** Der Schalter zum Schreiben und Löschen. */
    const FUNKTION = 'verwaltung/benachrichtigungen';

    private static function darf_lesen() {
        return LSV07S_Schalter::zugang();
    }

    private static function darf_verwalten() {
        return LSV07S_Schalter::an( self::FUNKTION );
    }

    private static function tabellen_da() {
        return LSV07S_Tabellen::da( 'ben' ) && LSV07S_Tabellen::da( 'ben_gelesen' );
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /**
     * Wie viele Mitteilungen dieses Konto noch nicht gelesen hat.
     *
     * Für den Zähler an der Glocke. Ohne Zugang: 0 — und kein Fehler, denn
     * der Zähler wird bei jedem Seitenaufbau geholt, und ein Fehler dort
     * wäre ein roter Kasten auf jeder Ansicht.
     */
    public static function ungelesen() {
        global $wpdb;
        if ( ! self::darf_lesen() || ! self::tabellen_da() ) return 0;
        $t = LSV07S_Tabellen::t( 'ben' );
        $g = LSV07S_Tabellen::t( 'ben_gelesen' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $t b WHERE NOT EXISTS ("
            . " SELECT 1 FROM $g g WHERE g.benachrichtigung_id = b.id AND g.user_id = %d )",
            get_current_user_id() ) );
    }

    /**
     * Alle Mitteilungen, die neueste zuerst, mit dem EIGENEN Lesestand.
     *
     * Wer wann gelesen hat, steht hier nicht: Das sieht nur, wer den
     * Schalter hat, und zwar in `verwaltung()`.
     */
    public static function liste() {
        global $wpdb;
        if ( ! self::darf_lesen() || ! self::tabellen_da() ) return [];
        $t = LSV07S_Tabellen::t( 'ben' );
        $g = LSV07S_Tabellen::t( 'ben_gelesen' );
        $uid = get_current_user_id();

        /* Der eigene Lesestand kommt als LEFT JOIN mit, statt in einer
           zweiten Abfrage: Der interne Bereich holt die Zeilen, baut aus
           ihren Nummern eine Zeichenkette und fragt noch einmal. */
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT b.id, b.titel, b.text, b.ersteller_id, b.ersteller_name,"
            . " b.erstellt_am, g.gelesen_am"
            . " FROM $t b LEFT JOIN $g g"
            . " ON g.benachrichtigung_id = b.id AND g.user_id = %d"
            . ' ORDER BY b.erstellt_am DESC, b.id DESC', $uid ), ARRAY_A );

        return array_map( fn( $z ) => [
            'id'         => (int) $z['id'],
            'titel'      => (string) $z['titel'],
            'text'       => (string) ( $z['text'] ?? '' ),
            'von'        => self::name( $z['ersteller_id'], $z['ersteller_name'] ),
            'am'         => LSV07S_Datum::de_zeit( $z['erstellt_am'] ),
            'gelesen'    => $z['gelesen_am'] !== null,
            'gelesen_am' => $z['gelesen_am'] === null
                              ? null : LSV07S_Datum::de_zeit( $z['gelesen_am'] ),
        ], $zeilen );
    }

    /**
     * Der Name zu einem Konto — aus dem Konto, nicht aus der Kopie.
     *
     * In der Spalte `user_name` steht eine Kopie des Namens. SwimSys
     * schreibt sie mit, weil der interne Bereich sie liest; liesse
     * SwimSys sie leer, stünde in dessen Lesebestätigung eine namenlose
     * Zeile, solange beide Plugins laufen.
     *
     * **Angezeigt wird sie nicht.** Ist das Konto gelöscht, soll der Name
     * weg sein — und eine Kopie in einer Bridge-Tabelle ist genau die
     * Stelle, an der er sonst stehen bleibt. Dann steht hier
     * „Gelöschtes Konto": Dass jemand gelesen hat, bleibt wahr; WER es
     * war, ist mit dem Konto gegangen. Nur eine Zeile ganz ohne
     * Kontonummer fällt auf die Kopie zurück — die kann nur aus einer
     * Anlage stammen, die älter ist als die Spalte.
     *
     * Die Kopie selbst steht weiter in der Tabelle. Sie auszuräumen
     * gehört zur Löschung nach Art. 17, und die fehlt noch (siehe
     * README, „Was noch fehlt").
     */
    private static function name( $uid, $kopie ) {
        $uid = (int) $uid;
        if ( ! $uid ) return (string) $kopie;
        if ( ! get_user_by( 'id', $uid ) ) return 'Gelöschtes Konto';
        return LSV07S_Person::konto_name( $uid );
    }

    /**
     * Eine Mitteilung als gelesen vermerken.
     *
     * Wiederholbar: Der zweite Aufruf ändert nichts und meldet keinen
     * Fehler — die Oberfläche schickt ihn beim Aufklappen, und das kann
     * zweimal passieren.
     */
    public static function gelesen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_lesen() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! self::tabellen_da() ) return 'Die Tabelle fehlt.';
        $t = LSV07S_Tabellen::t( 'ben' );
        $g = LSV07S_Tabellen::t( 'ben_gelesen' );

        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d", $id ) ) ) {
            return 'Diese Mitteilung gibt es nicht.';
        }
        $uid = get_current_user_id();
        $schon = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $g WHERE benachrichtigung_id = %d AND user_id = %d", $id, $uid ) );
        if ( $schon ) return true;

        $wpdb->insert( $g, [
            'benachrichtigung_id' => $id,
            'user_id'             => $uid,
            /* Die Kopie des Namens: nur für den internen Bereich, der sie
               liest. Angezeigt wird in SwimSys der frische Name. */
            'user_name'           => LSV07S_Person::konto_name( $uid ),
            'gelesen_am'          => LSV07S_Datum::jetzt(),
        ], [ '%d', '%d', '%s', '%s' ] );
        return true;
    }

    // ── Verwalten ────────────────────────────────────────────────────────

    /**
     * Alle Mitteilungen mit ihrer Lesebestätigung.
     *
     * Hier steht, wer wann gelesen hat — und darum steht das Öffnen im
     * Protokoll. Eine Übersicht darüber, wer wann wo hineingesehen hat,
     * ist eine Aussage über Personen, nicht über Mitteilungen.
     */
    public static function verwaltung() {
        global $wpdb;
        if ( ! self::darf_verwalten() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! self::tabellen_da() ) return [];

        $t = LSV07S_Tabellen::t( 'ben' );
        $zeilen = (array) $wpdb->get_results(
            "SELECT id, titel, text, ersteller_id, ersteller_name, erstellt_am"
            . " FROM $t ORDER BY erstellt_am DESC, id DESC", ARRAY_A );
        if ( ! $zeilen ) return [];

        $leser = self::leser( array_map( fn( $z ) => (int) $z['id'], $zeilen ) );
        $konten = count( LSV07S_Schalter::konten_mit_zugang() );

        LSV07S_Log::schreibe( 'ben.bestaetigung', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'benachrichtigung', 'ziel_id' => 0,
            'details' => count( $zeilen ) . ' Mitteilung(en) mit Lesebestätigung',
        ] );

        return array_map( fn( $z ) => [
            'id'      => (int) $z['id'],
            'titel'   => (string) $z['titel'],
            'text'    => (string) ( $z['text'] ?? '' ),
            'von'     => self::name( $z['ersteller_id'], $z['ersteller_name'] ),
            'am'      => LSV07S_Datum::de_zeit( $z['erstellt_am'] ),
            'leser'   => $leser[ (int) $z['id'] ] ?? [],
            /* Wie viele Konten es überhaupt gibt, die hereinkommen —
               sonst sagt „4 haben gelesen" nichts. */
            'konten'  => $konten,
        ], $zeilen );
    }

    /**
     * Wer welche Mitteilung gelesen hat.
     *
     * Die Nummern werden als Platzhalter vorbereitet, nicht in das SQL
     * geschrieben. Sie kommen aus der Datenbank, sind also heute keine
     * Lücke — aber eine Abfrage, die ihre Werte einsetzt, ist die Stelle,
     * an der morgen eine entsteht.
     */
    private static function leser( array $ids ) {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
        if ( ! $ids ) return [];
        $g = LSV07S_Tabellen::t( 'ben_gelesen' );
        $platz = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT benachrichtigung_id, user_id, user_name, gelesen_am FROM $g"
            . " WHERE benachrichtigung_id IN ($platz) ORDER BY gelesen_am ASC",
            ...$ids ), ARRAY_A );

        $raus = [];
        foreach ( $zeilen as $z ) {
            $raus[ (int) $z['benachrichtigung_id'] ][] = [
                'name' => self::name( $z['user_id'], $z['user_name'] ),
                'am'   => LSV07S_Datum::de_zeit( $z['gelesen_am'] ),
            ];
        }
        return $raus;
    }

    /** Eine Mitteilung schreiben. */
    public static function speichern( $titel, $text ) {
        global $wpdb;
        if ( ! self::darf_verwalten() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! self::tabellen_da() ) return 'Die Tabelle fehlt.';

        /* Geprüft wird VOR dem Säubern. `sanitize_text_field` macht aus
           `<b></b>` eine leere Zeichenkette — danach geprüft, hiesse die
           Meldung „Bitte einen Titel angeben", obwohl einer dastand. */
        if ( trim( (string) $titel ) === '' ) return 'Bitte einen Titel angeben.';
        if ( trim( (string) $text ) === '' ) return 'Bitte einen Text angeben.';
        $titel_s = mb_substr( sanitize_text_field( (string) $titel ), 0, 200 );
        $text_s  = sanitize_textarea_field( (string) $text );
        if ( $titel_s === '' || $text_s === '' ) {
            return 'Titel und Text dürfen nicht nur aus Auszeichnung bestehen.';
        }

        $uid = get_current_user_id();
        $wpdb->insert( LSV07S_Tabellen::t( 'ben' ), [
            'titel'          => $titel_s,
            'text'           => $text_s,
            'ersteller_id'   => $uid,
            'ersteller_name' => LSV07S_Person::konto_name( $uid ),
            'erstellt_am'    => LSV07S_Datum::jetzt(),
        ], [ '%s', '%s', '%d', '%s', '%s' ] );
        $neu = (int) $wpdb->insert_id;

        LSV07S_Log::schreibe( 'ben.geschrieben', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'benachrichtigung',
            'ziel_id' => $neu, 'ziel_name' => $titel_s,
        ] );
        return $neu;
    }

    /** Eine Mitteilung löschen — mit ihren Lesebestätigungen. */
    public static function loeschen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_verwalten() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! self::tabellen_da() ) return 'Die Tabelle fehlt.';
        $t = LSV07S_Tabellen::t( 'ben' );
        $titel = $wpdb->get_var( $wpdb->prepare(
            "SELECT titel FROM $t WHERE id = %d", $id ) );
        if ( $titel === null ) return 'Diese Mitteilung gibt es nicht.';

        LSV07S_Log::schreibe( 'ben.geloescht', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'benachrichtigung',
            'ziel_id' => $id, 'ziel_name' => (string) $titel,
        ] );

        /* Erst die Bestätigungen, dann die Mitteilung: In der anderen
           Reihenfolge bliebe bei einem Abbruch eine Bestätigung ohne
           Mitteilung stehen, und die zählt der Zähler nie wieder weg. */
        $wpdb->delete( LSV07S_Tabellen::t( 'ben_gelesen' ),
            [ 'benachrichtigung_id' => $id ], [ '%d' ] );
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );
        return true;
    }
}
