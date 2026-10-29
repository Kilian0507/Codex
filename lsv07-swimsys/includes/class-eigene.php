<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * „Meine Daten" — was über die angemeldete Person gespeichert ist.
 *
 * Im internen Bereich gibt es das Recht `schwimmen.eigen.read`, aber
 * keine Funktion dazu: Es ist nie gebaut worden. Wer wissen wollte, was
 * über ihn gespeichert ist, musste fragen.
 *
 * **Die Zuordnung ist das Problem, nicht die Anzeige.** Ein Trainer
 * hängt über `wp_user_id` an seinem Profil — das ist eine Nummer, also
 * eindeutig. Ein Sportler hängt an nichts: `mv_swimmers` hat keine
 * Kontonummer, und die Anmeldung am Schwimmerportal läuft über DSV-ID
 * und Geburtsdatum, nicht über ein WordPress-Konto. Bleibt die E-Mail.
 *
 * Eine E-Mail ist aber kein Ausweis. In einem Schwimmverein steht bei
 * zwei Geschwistern dieselbe Adresse der Eltern — wer über die E-Mail
 * zuordnet, zeigt dann die Daten des falschen Kindes. Darum gilt hier:
 *
 * 1. Nur ein **eindeutiger** Treffer zählt. Passen zwei Sportler auf
 *    dieselbe Adresse, wird NICHTS gezeigt und der Grund genannt.
 * 2. Gesundheitsdaten und Reflexionsbögen stehen nicht im Klartext da.
 *    Vom Attest steht die Frist (die braucht man, und sie steht ohnehin
 *    in der Mannschaftsliste), vom Bogen nur sein Zustand. Die Antworten
 *    gibt es weiter nur mit dem Zugangsschlüssel auf der öffentlichen
 *    Seite — der Schlüssel ist der zweite Faktor, und eine E-Mail ersetzt
 *    ihn nicht.
 *
 * Was ohne jede Zuordnung immer dasteht: das WordPress-Konto und die
 * Liste der Funktionen, die für dieses Konto eingeschaltet sind. Das ist
 * die Antwort auf „warum sehe ich das nicht?", und sie gehört der Person.
 */
class LSV07S_Eigene {

    /**
     * Alles über die angemeldete Person.
     *
     * Verlangt keinen Schalter für das Konto und die Funktionsliste — die
     * eigenen Daten gehören der Person. Für die Sportler- und
     * Trainerdaten je Sparte gilt der Schalter `<sparte>/eigene`: Ohne
     * ihn steht der Bereich nicht in der Navigation, und dann hat auch
     * diese Antwort dort nichts zu suchen.
     */
    public static function alles() {
        $uid = (int) get_current_user_id();
        if ( ! $uid ) return 'Bitte melden Sie sich an.';

        $konto = [
            'name'      => LSV07S_Person::konto_name( $uid ),
            'email'     => self::konto_email( $uid ),
            'ist_admin' => LSV07S_Rechte::ist_admin( $uid ),
            'gesperrt'  => LSV07S_Rechte::gesperrt( $uid ),
        ];

        /* Welche Funktionen für dieses Konto an sind — mit Namen, nicht
           als Schlüssel. */
        $katalog = LSV07S_Schalter::katalog();
        $an = [];
        foreach ( array_keys( $katalog ) as $f ) {
            if ( ! LSV07S_Schalter::an( $f, $uid ) ) continue;
            $teile = explode( '/', $f, 2 );
            $an[] = [
                'funktion' => $f,
                'bereich'  => LSV07S_Schalter::BEREICHE[ $teile[0] ] ?? $teile[0],
                'name'     => (string) ( $katalog[ $f ]['name'] ?? $f ),
                'was'      => (string) ( $katalog[ $f ]['was'] ?? '' ),
            ];
        }

        $sparten = [];
        foreach ( LSV07S_Sparte::alle() as $sp ) {
            if ( ! LSV07S_Sparte::da( $sp ) ) continue;
            if ( ! LSV07S_Schalter::an( LSV07S_Sparte::funktion( $sp, 'eigene' ), $uid ) ) continue;
            $sparten[] = [
                'kennung'  => $sp,
                'name'     => LSV07S_Sparte::feld( $sp, 'name' ),
                'trainer'  => self::trainer( $sp, $uid ),
                'sportler' => self::sportler( $sp, $konto['email'] ),
            ];
        }

        return [
            'konto'      => $konto,
            'funktionen' => $an,
            'sparten'    => $sparten,
        ];
    }

    /** Die E-Mail des WordPress-Kontos. */
    private static function konto_email( $uid ) {
        $u = get_userdata( (int) $uid );
        return $u ? (string) $u->user_email : '';
    }

    /**
     * Das Trainerprofil dieses Kontos in dieser Sparte.
     *
     * Über `wp_user_id` — eine Nummer, also eindeutig. Null, wenn es
     * keines gibt; das ist keine Lücke, sondern der Normalfall für
     * jemanden, der nicht trainiert.
     */
    private static function trainer( $sparte, $uid ) {
        global $wpdb;
        $t = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        if ( ! $t ) return null;
        $z = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $t WHERE wp_user_id = %d LIMIT 1", (int) $uid ), ARRAY_A );
        if ( ! $z ) return null;

        $namen = [];
        foreach ( LSV07S_Mannschaft::eigene_ids( $sparte, $uid ) as $m ) {
            $mann = LSV07S_Mannschaft::holen( $sparte, $m );
            if ( $mann ) $namen[] = $mann['name'];
        }
        return [
            'id'         => (int) $z['id'],
            'name'       => (string) $z['name'],
            'email'      => (string) $z['email'],
            'aktiv'      => (int) $z['aktiv'] === 1,
            'mann_namen' => $namen,
        ];
    }

    /**
     * Der Sportlerdatensatz dieses Kontos in dieser Sparte.
     *
     * Nur über die E-Mail, und nur bei einem EINDEUTIGEN Treffer. Gibt
     * ein Feld `grund` zurück, wenn nichts gezeigt wird — ein leeres
     * Kästchen ohne Grund ist schlimmer als keines.
     */
    private static function sportler( $sparte, $email ) {
        global $wpdb;
        $email = trim( (string) $email );
        if ( $email === '' ) {
            return [ 'da' => false,
                     'grund' => 'Für Ihr WordPress-Konto ist keine E-Mail hinterlegt. '
                              . 'Ohne sie lässt sich kein Sportlerdatensatz zuordnen.' ];
        }
        $t = LSV07S_Sparte::tabelle( $sparte, 't_sportler' );
        if ( ! $t ) return [ 'da' => false, 'grund' => 'Diese Sparte ist nicht eingerichtet.' ];

        $treffer = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $t WHERE email = %s", $email ), ARRAY_A );
        if ( ! $treffer ) {
            return [ 'da' => false,
                     'grund' => 'Zu Ihrer E-Mail-Adresse ist in dieser Sparte kein '
                              . 'Sportler eingetragen. Das ist kein Fehler: Sportler '
                              . 'werden nicht über ein WordPress-Konto geführt.' ];
        }
        if ( count( $treffer ) > 1 ) {
            /* Geschwister mit der Adresse der Eltern. Hier zu raten
               hiesse, die Daten des falschen Kindes zu zeigen. */
            return [ 'da' => false,
                     'grund' => 'Auf Ihre E-Mail-Adresse sind ' . count( $treffer )
                              . ' Sportler eingetragen — zum Beispiel Geschwister mit '
                              . 'der Adresse der Eltern. Welcher davon Sie sind, lässt '
                              . 'sich nicht entscheiden, und darum wird hier nichts '
                              . 'gezeigt. Die Trainerin oder der Trainer kann die Daten '
                              . 'nennen.' ];
        }

        $z  = $treffer[0];
        $id = (int) $z['id'];
        $gb = LSV07S_Sparte::feld( $sparte, 'geburt' );

        $mannschaften = [];
        foreach ( LSV07S_Stamm::zuordnung( $sparte, $id ) as $m ) {
            $mann = LSV07S_Mannschaft::holen( $sparte, $m );
            if ( $mann ) $mannschaften[] = $mann;
        }
        $namen = array_column( $mannschaften, 'name' );

        return [
            'da'         => true,
            'id'         => $id,
            'name'       => LSV07S_Person::aus_zeile( $z, $sparte, 'satz' ),
            'geburt_de'  => LSV07S_Datum::de( $z[ $gb ] ?? null ),
            'jahrgang'   => LSV07S_Person::jahrgang( $z[ $gb ] ?? null ),
            'dsv_id'     => (string) ( $z['dsv_id'] ?? '' ),
            'email'      => (string) ( $z['email'] ?? '' ),
            'aktiv'      => (int) ( $z[ LSV07S_Sparte::feld( $sparte, 'aktiv' ) ] ?? 0 ) === 1,
            'mann_namen' => $namen,
            'attest_bis' => self::attest_frist( $sparte, $z ),
            'bestzeiten' => self::bestzeiten( $sparte, $id ),
            'anwesenheit'=> self::anwesenheit( $sparte, $id, $mannschaften ),
            'reflexion'  => self::reflexion( $sparte, $id ),
        ];
    }

    /**
     * Die Attestfrist — das Datum, nicht das Attest.
     *
     * Ein Attest ist ein Gesundheitsdatum nach Art. 9 DSGVO. Die Frist
     * muss die Person kennen, sonst kann sie nicht rechtzeitig ein neues
     * bringen; die Datei und der Befund gehören nicht in eine Ansicht,
     * die über eine E-Mail zugeordnet wurde.
     */
    private static function attest_frist( $sparte, array $zeile ) {
        if ( $sparte !== LSV07S_Sparte::SCHWIMMEN ) return null;
        $d = (string) ( $zeile['attest_expires'] ?? '' );
        if ( $d === '' || $d === '0000-00-00' ) return null;
        return [ 'bis' => $d, 'bis_de' => LSV07S_Datum::de( $d ),
                 'abgelaufen' => $d < LSV07S_Datum::heute() ];
    }

    /** Die eigenen Bestzeiten. */
    private static function bestzeiten( $sparte, $id ) {
        global $wpdb;
        if ( $sparte !== LSV07S_Sparte::SCHWIMMEN ) return [];
        if ( ! LSV07S_Tabellen::da( 'bestzeiten' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT strecke, zeit_raw FROM ' . LSV07S_Tabellen::t( 'bestzeiten' )
            . ' WHERE swimmer_id = %d ORDER BY strecke ASC', (int) $id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'strecke' => (string) $z['strecke'],
            'zeit'    => (string) $z['zeit_raw'],
        ], $zeilen );
    }

    /**
     * Die eigene Anwesenheit der laufenden Saison, als Zahlen.
     *
     * Gerechnet wird mit LSV07S_Anwesenheit::quote() — derselben Stelle,
     * aus der die Auswertung und die Akte lesen. Eine zweite Abfrage
     * hier hiesse zwei Lesarten von „war da", und die laufen auseinander;
     * genau das ist im internen Bereich passiert.
     *
     * Die einzelnen TAGE stehen nicht da: Daraus liesse sich ablesen, an
     * welchem Tag jemand krank war, und das ist wieder ein
     * Gesundheitsdatum. Es stehen die Summen.
     */
    private static function anwesenheit( $sparte, $id, array $mann_ids ) {
        $s = LSV07S_Datum::saison_aktiv();
        if ( ! $s ) return null;
        $von = (string) $s['start_datum'];
        $bis = (string) ( $s['ende_datum'] ?: LSV07S_Datum::heute() );
        if ( $bis > LSV07S_Datum::heute() ) $bis = LSV07S_Datum::heute();

        $raus = [];
        foreach ( $mann_ids as $m ) {
            foreach ( LSV07S_Anwesenheit::quote( $sparte, (int) $m['id'], $von, $bis ) as $z ) {
                if ( (int) $z['id'] !== (int) $id ) continue;
                $raus[] = [
                    'mannschaft'     => (string) $m['name'],
                    'anwesend'       => $z['anwesend'],
                    'entschuldigt'   => $z['entschuldigt'],
                    'unentschuldigt' => $z['unentschuldigt'],
                    'gesamt'         => $z['gesamt'],
                    'quote'          => $z['quote'],
                    'verspaetungen'  => $z['verspaetungen'],
                ];
            }
        }
        return [ 'saison' => (string) $s['name'], 'von' => $von, 'bis' => $bis,
                 'je_mannschaft' => $raus ];
    }

    /**
     * Die eigenen Reflexionsbögen — nur ihr Zustand.
     *
     * Was ein Kind sich vornimmt, steht verschlüsselt in der Datenbank
     * und wird mit dem Zugangsschlüssel geöffnet. Dieser Schlüssel ist
     * der zweite Faktor; eine übereinstimmende E-Mail ersetzt ihn nicht.
     * Darum steht hier, DASS es einen Bogen gibt, nicht was darin steht.
     */
    private static function reflexion( $sparte, $id ) {
        global $wpdb;
        if ( $sparte !== LSV07S_Sparte::SCHWIMMEN ) return [];
        if ( ! LSV07S_Tabellen::da( 'reflexion' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT b.id, b.status, b.abgegeben_am, s.name AS saison'
            . ' FROM ' . LSV07S_Tabellen::t( 'reflexion' ) . ' b'
            . ' LEFT JOIN ' . LSV07S_Tabellen::t( 'saisons' ) . ' s ON s.id = b.saison_id'
            . ' WHERE b.swimmer_id = %d ORDER BY b.id DESC', (int) $id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id'      => (int) $z['id'],
            'saison'  => (string) ( $z['saison'] ?? '' ),
            'status'  => (string) $z['status'],
            'am_de'   => LSV07S_Datum::de_zeit( $z['abgegeben_am'] ),
        ], $zeilen );
    }
}
