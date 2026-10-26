<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Andere Abrechnung: eine Rechnung mit Beleg.
 *
 * Nicht jede Auszahlung ist eine Quartalsabrechnung. Wer Material gekauft
 * hat, eine Hallenmiete auslegt oder die Rechnung eines Lieferanten
 * weiterreicht, legt sie hier ab: Empfänger, Betrag, Beleg als Foto oder
 * PDF. Danach geht sie denselben Weg wie alles andere — einreichen,
 * genehmigen, bezahlen — nur dass hier **der Abteilungsleiter genehmigt**
 * und nicht der Wart: Eine Rechnung über neue Startblöcke hat keine
 * Mannschaft, an der eine Zuständigkeit hängen könnte, aber sie hat eine
 * Abteilung.
 *
 * Zwei Festlegungen zu den Daten:
 *
 *  1. **Die Empfängerdaten stehen als Schnappschuss in der Zeile**, nicht
 *     als Verweis auf die Stammdaten. Sonst zeigte eine vor zwei Jahren
 *     bezahlte Rechnung im Archiv die heutige Bankverbindung, und die Kasse
 *     könnte nicht mehr belegen, wohin damals überwiesen wurde. Beim
 *     Anlegen werden sie aus den Stammdaten vorbelegt — eingefroren werden
 *     sie beim Einreichen.
 *  2. **Der Beleg liegt ausserhalb des Webverzeichnisses.** Eine Rechnung
 *     trägt Namen, Anschriften und Beträge. Der einzige Weg an die Datei
 *     führt über den Download-Endpunkt, und der stellt dieselbe Frage wie
 *     die Ansicht.
 */
class LSV07A_Rechnung {

    /** Was als Beleg durchgeht. Geprüft wird an den Bytes, nicht am Namen. */
    const ERLAUBT = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];

    /** 12 MB je Datei. Ein Handyfoto liegt bei 2–5 MB. */
    const MAX_BYTES = 12582912;

    /** Naht für die Prüfungen: Was sonst `is_uploaded_file` beantwortet. */
    public static $pruefe_upload = null;

    private static function t( $n ) { global $wpdb; return $wpdb->prefix . $n; }

    // ── Lesen ────────────────────────────────────────────────────────────

    public static function holen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'lsv07a_rechnung' ) . " WHERE id = %d", $id ), ARRAY_A ) ?: null;
    }

    /** Eine Rechnung mit allem, was die Oberfläche braucht. */
    public static function voll( $id ) {
        $r = self::holen( $id );
        if ( ! $r ) return null;
        $u = get_userdata( $r['wp_user_id'] );
        /* Die Belege einmal holen und weiterreichen: vollstaendig() fragt sie
           sonst noch einmal ab, und in einer Liste mit hundert Rechnungen
           wären das vierhundert Abfragen statt hundert. */
        $dateien = self::dateien( (int) $r['id'] );
        $pruef   = self::vollstaendig( $r, $dateien );
        return [
            'id'             => (int) $r['id'],
            'wp_user_id'     => (int) $r['wp_user_id'],
            'name'           => $u ? $u->display_name : ( 'Konto ' . $r['wp_user_id'] ),
            'abteilung_id'   => (int) $r['abteilung_id'],
            'abteilung'      => LSV07A_Abteilung::name( $r['abteilung_id'] ),
            'titel'          => (string) $r['titel'],
            'zweck'          => (string) $r['zweck'],
            'betrag'         => (float) $r['betrag'],
            'rechnungsdatum' => (string) ( $r['rechnungsdatum'] ?? '' ),
            'jahr'           => (int) $r['jahr'],
            'empf_name'      => (string) $r['empf_name'],
            /* Nicht die rohe Spalte: Wie viel von der Bankverbindung
               herausgeht, entscheidet iban_sicht() je nach Rolle. Der
               Girocode baut auf der Spalte auf und ist davon unberührt —
               ihn bekommt ohnehin nur die Kasse. */
            'empf_iban'      => self::iban_sicht( $r ),
            'empf_iban_voll' => self::iban_sicht( $r ) === (string) $r['empf_iban'],
            'empf_bic'       => (string) $r['empf_bic'],
            'empf_eigen'     => (int) $r['empf_eigen'] === 1,
            'status'         => (string) $r['status'],
            'status_name'    => LSV07A_Berechnung::status_name( $r['status'] ),
            'kommentar'      => (string) ( $r['kommentar'] ?? '' ),
            'rueckgabe_grund' => (string) ( $r['rueckgabe_grund'] ?? '' ),
            'selbst_genehmigt' => self::selbst_genehmigt( $r ),
            'eingereicht_am' => $r['eingereicht_am'],
            'genehmigt_am'   => $r['genehmigt_am'],
            'bezahlt_am'     => $r['bezahlt_am'],
            'dateien'        => $dateien,
            'darf_aendern'   => self::darf_aendern( $r ),
            'vollstaendig'   => $pruef === true,
            'fehlt'          => $pruef === true ? '' : (string) $pruef,
        ];
    }

    /** Die Rechnungen einer Person. */
    public static function meine( $wp_user_id, $jahr = 0 ) {
        global $wpdb;
        $wo = $wpdb->prepare( 'wp_user_id = %d', (int) $wp_user_id );
        if ( $jahr ) $wo .= $wpdb->prepare( ' AND jahr = %d', (int) $jahr );
        $ids = $wpdb->get_col(
            "SELECT id FROM " . self::t( 'lsv07a_rechnung' ) . "
              WHERE $wo ORDER BY COALESCE(rechnungsdatum, erstellt_am) DESC, id DESC" ) ?: [];
        return array_values( array_filter( array_map( [ __CLASS__, 'voll' ], $ids ) ) );
    }

    /**
     * Die Rechnungen, über die jemand entscheiden soll.
     *
     * Die Abteilung der RECHNUNG zählt, nicht die heutige Abteilung der
     * Person: Wer die Abteilung wechselt, nimmt eingereichte Rechnungen
     * nicht mit — sonst verschwänden sie beim bisherigen Leiter aus der
     * Liste, ohne dass jemand etwas davon merkt.
     */
    public static function zur_genehmigung( $leiter_uid = 0, $status = 'eingereicht' ) {
        global $wpdb;
        $leiter_uid = $leiter_uid ?: get_current_user_id();

        $wo = [];
        if ( $status !== '' && in_array( $status, LSV07A_DB::R_STATUS, true ) ) {
            $wo[] = $wpdb->prepare( 'status = %s', $status );
        } else {
            $wo[] = "status <> 'entwurf'";
        }

        if ( ! LSV07A_Rollen::ist_admin( $leiter_uid ) ) {
            $meine = LSV07A_Abteilung::leitet( $leiter_uid );
            if ( ! $meine ) return [];
            $platz = implode( ',', array_map( 'intval', $meine ) );
            $wo[] = "abteilung_id IN ($platz)";
        }

        $ids = $wpdb->get_col(
            "SELECT id FROM " . self::t( 'lsv07a_rechnung' ) . "
              WHERE " . implode( ' AND ', $wo ) . "
              ORDER BY eingereicht_am ASC, id ASC" ) ?: [];
        return array_values( array_filter( array_map( [ __CLASS__, 'voll' ], $ids ) ) );
    }

    /** Die Rechnungen für die Kasse: genehmigt und bezahlt. */
    public static function fuer_kasse( $status = '', $jahr = 0 ) {
        global $wpdb;
        $erlaubt = [ 'genehmigt', 'bezahlt' ];
        if ( $status !== '' && in_array( $status, $erlaubt, true ) ) $erlaubt = [ $status ];
        $in = "'" . implode( "','", array_map( 'esc_sql', $erlaubt ) ) . "'";
        $wo = "status IN ($in)";
        if ( $jahr ) $wo .= $wpdb->prepare( ' AND jahr = %d', (int) $jahr );

        $ids = $wpdb->get_col(
            "SELECT id FROM " . self::t( 'lsv07a_rechnung' ) . "
              WHERE $wo ORDER BY genehmigt_am ASC, id ASC" ) ?: [];
        return array_values( array_filter( array_map( [ __CLASS__, 'voll' ], $ids ) ) );
    }

    /** Die Jahre, in denen es bezahlte Rechnungen gibt — für das Archiv. */
    public static function archiv_jahre() {
        global $wpdb;
        $zeilen = $wpdb->get_results(
            "SELECT jahr, COUNT(*) AS anzahl, COALESCE(SUM(betrag),0) AS summe
               FROM " . self::t( 'lsv07a_rechnung' ) . "
              WHERE status = 'bezahlt' AND jahr > 0
              GROUP BY jahr ORDER BY jahr DESC", ARRAY_A ) ?: [];
        return array_map( fn( $z ) => [
            'jahr'   => (int) $z['jahr'],
            'anzahl' => (int) $z['anzahl'],
            'summe'  => round( (float) $z['summe'], 2 ),
        ], $zeilen );
    }

    /** Die bezahlten Rechnungen eines Jahres. */
    public static function archiv( $jahr ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'lsv07a_rechnung' ) . "
              WHERE status = 'bezahlt' AND jahr = %d
              ORDER BY bezahlt_am ASC, id ASC", (int) $jahr ) ) ?: [];
        return array_values( array_filter( array_map( [ __CLASS__, 'voll' ], $ids ) ) );
    }

    // ── Rechte ───────────────────────────────────────────────────────────

    /**
     * Die Bankverbindung, so viel davon diese Person sehen darf.
     *
     * Vollständig sehen sie drei: wem sie gehört (es sind die eigenen
     * Daten), die Kasse (sie überweist) und die Administration. Für die
     * **Abteilungsleitung** entscheidet die Verwaltung — sie genehmigt eine
     * Zahlung, muss also das Ziel prüfen können, braucht dafür aber nicht
     * zwingend die ganze Nummer.
     *
     * Der Wart kommt an Rechnungen gar nicht heran; für ihn gibt es hier
     * nichts zu entscheiden.
     */
    public static function iban_sicht( $r, $uid = 0 ) {
        $iban = (string) $r['empf_iban'];
        if ( $iban === '' ) return '';
        $uid = $uid ?: get_current_user_id();

        if ( (int) $r['wp_user_id'] === (int) $uid ) return $iban;
        if ( LSV07A_Rollen::ist_admin( $uid ) ) return $iban;
        if ( LSV07A_Rollen::ist_kasse( $uid ) ) return $iban;

        $wie = LSV07A_DB::config( 'leiter_iban', 'kurz' );
        if ( ! in_array( $wie, LSV07A_DB::IBAN_SICHT, true ) ) $wie = 'kurz';
        if ( $wie === 'voll' )  return $iban;
        if ( $wie === 'keine' ) return '';
        /* Verkürzt: Land und Prüfziffer vorn, die letzten vier hinten. Das
           reicht, um zwei Empfänger zu unterscheiden, und nicht, um eine
           Überweisung auszulösen. */
        return mb_substr( $iban, 0, 4 ) . ' … ' . mb_substr( $iban, -4 );
    }

    /** Darf die aktuelle Person diese Rechnung sehen? */
    public static function darf_sehen( $r, $uid = 0 ) {
        if ( is_numeric( $r ) ) $r = self::holen( $r );
        if ( ! $r ) return false;
        $uid = $uid ?: get_current_user_id();

        if ( (int) $r['wp_user_id'] === (int) $uid ) return true;
        if ( LSV07A_Rollen::ist_admin( $uid ) ) return true;

        /* Die Kasse sieht, was genehmigt oder bezahlt ist — davor nichts. */
        if ( LSV07A_Rollen::ist_kasse( $uid )
          && in_array( $r['status'], [ 'genehmigt', 'bezahlt' ], true ) ) return true;

        /* Der Abteilungsleiter sieht, was aus seiner Abteilung kommt — auch
           einen Entwurf nicht: Solange niemand eingereicht hat, geht es
           niemanden etwas an. */
        if ( $r['status'] !== 'entwurf'
          && in_array( (int) $r['abteilung_id'], LSV07A_Abteilung::leitet( $uid ), true )
          && LSV07A_Rollen::ist_genau( 'leiter', $uid ) ) return true;

        return false;
    }

    /** Ändern darf nur, wem sie gehört — und nur, solange sie offen ist. */
    public static function darf_aendern( $r, $uid = 0 ) {
        if ( is_numeric( $r ) ) $r = self::holen( $r );
        if ( ! $r ) return false;
        $uid = $uid ?: get_current_user_id();
        if ( (int) $r['wp_user_id'] !== (int) $uid ) return false;
        return in_array( $r['status'], [ 'entwurf', 'zurueck' ], true );
    }

    /** Darf die aktuelle Person über diese Rechnung entscheiden? */
    public static function darf_genehmigen( $r, $uid = 0 ) {
        if ( is_numeric( $r ) ) $r = self::holen( $r );
        if ( ! $r ) return false;
        $uid = $uid ?: get_current_user_id();

        /* Die eigene Rechnung darf genehmigen, wer die Befugnis dafür hat.
           Das Vier-Augen-Prinzip ist damit NICHT durchgesetzt — dafür wird
           jede Selbstgenehmigung festgehalten: in der Zeile, im Protokoll,
           auf dem Rechnungsblatt und als Mitteilung an die Kasse. Lieber
           nachvollziehbar als verboten; ein Verbot träfe sonst den
           Abteilungsleiter, der sein Startgeld ausgelegt hat, und niemand
           sonst wäre da, der es ihm bestätigt. */

        if ( LSV07A_Rollen::ist_admin( $uid ) ) return true;
        if ( ! LSV07A_Rollen::ist_genau( 'leiter', $uid ) ) return false;
        return in_array( (int) $r['abteilung_id'], LSV07A_Abteilung::leitet( $uid ), true );
    }

    /**
     * Was zum Einreichen fehlt. Gibt true zurück, wenn nichts fehlt.
     *
     * Dieselbe Prüfung steht vor dem Einreichen und hinter dem Knopf in der
     * Oberfläche — zwei getrennte Listen würden auseinanderlaufen, und dann
     * stünde „einreichen" bereit und führte zu einer Fehlermeldung.
     */
    public static function vollstaendig( $r, $dateien = null ) {
        if ( is_numeric( $r ) ) $r = self::holen( $r );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';
        /* Die Belege dürfen mitgegeben werden, wenn der Aufrufer sie ohnehin
           schon geholt hat — sonst werden sie hier abgefragt. */
        if ( $dateien === null ) $dateien = self::dateien( (int) $r['id'] );

        $fehlt = [];
        if ( trim( (string) $r['titel'] ) === '' )     $fehlt[] = 'ein Betreff';
        if ( (float) $r['betrag'] < 0.01 )             $fehlt[] = 'der Betrag';
        if ( trim( (string) $r['empf_name'] ) === '' ) $fehlt[] = 'der Empfänger';
        if ( LSV07A_QR::iban( $r['empf_iban'] ) === '' ) $fehlt[] = 'eine gültige IBAN';
        if ( ! (int) $r['abteilung_id'] )              $fehlt[] = 'die Abteilung (Verwaltung)';
        if ( ! $dateien )                              $fehlt[] = 'der Beleg';

        if ( ! $fehlt ) return true;
        return 'Es fehlt noch: ' . implode( ', ', $fehlt ) . '.';
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Eine neue Rechnung anlegen. Die Empfängerdaten kommen aus den
     * Stammdaten — dort hat die Person alles hinterlegt, was man für eine
     * Überweisung braucht, und soll es nicht für jede Rechnung erneut
     * eintippen.
     */
    public static function anlegen( $wp_user_id, array $f = [] ) {
        global $wpdb;
        $uid  = (int) $wp_user_id;
        $pers = LSV07A_Person::holen( $uid );
        $abt  = (int) $pers['abteilung_id'];

        $datum = self::datum( $f['rechnungsdatum'] ?? '' );
        if ( ( $f['rechnungsdatum'] ?? '' ) !== '' && $datum === '' ) {
            return 'Das Rechnungsdatum ist nicht zu lesen.';
        }
        if ( $datum === '' ) $datum = current_time( 'Y-m-d' );

        $eigen = ! array_key_exists( 'empf_eigen', $f ) || ! empty( $f['empf_eigen'] );

        $wpdb->insert( self::t( 'lsv07a_rechnung' ), [
            'wp_user_id'     => $uid,
            'abteilung_id'   => $abt,
            'titel'          => self::kurz( $f['titel'] ?? '', 200 ),
            'zweck'          => self::kurz( $f['zweck'] ?? '', 140 ),
            'betrag'         => self::betrag( $f['betrag'] ?? 0 ),
            'rechnungsdatum' => $datum,
            'jahr'           => (int) substr( $datum, 0, 4 ),
            'empf_name'      => $eigen ? self::kurz( $pers['kontoinhaber'], 200 )
                                       : self::kurz( $f['empf_name'] ?? '', 200 ),
            'empf_iban'      => $eigen ? (string) $pers['iban']
                                       : strtoupper( preg_replace( '/\s+/', '', (string) ( $f['empf_iban'] ?? '' ) ) ),
            'empf_bic'       => $eigen ? (string) $pers['bic']
                                       : strtoupper( preg_replace( '/\s+/', '', (string) ( $f['empf_bic'] ?? '' ) ) ),
            'empf_eigen'     => $eigen ? 1 : 0,
            'status'         => 'entwurf',
            'kommentar'      => (string) ( $f['kommentar'] ?? '' ),
            'erstellt_am'    => current_time( 'mysql' ),
        ], [ '%d','%d','%s','%s','%f','%s','%d','%s','%s','%s','%d','%s','%s','%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 'Die Rechnung liess sich nicht anlegen.';
        LSV07A_Log::schreibe( 'rechnung.neu', [ 'ziel_typ' => 'rechnung', 'ziel_id' => $id ] );
        return $id;
    }

    public static function aendern( $id, array $f ) {
        global $wpdb;
        $r = self::holen( $id );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';
        if ( ! self::darf_aendern( $r ) ) {
            return 'Diese Rechnung ist eingereicht und lässt sich nicht mehr ändern.';
        }

        $daten = []; $form = [];
        if ( isset( $f['titel'] ) )  { $daten['titel'] = self::kurz( $f['titel'], 200 ); $form[] = '%s'; }
        if ( isset( $f['zweck'] ) )  { $daten['zweck'] = self::kurz( $f['zweck'], 140 ); $form[] = '%s'; }
        if ( isset( $f['kommentar'] ) ) { $daten['kommentar'] = (string) $f['kommentar']; $form[] = '%s'; }
        if ( isset( $f['betrag'] ) ) { $daten['betrag'] = self::betrag( $f['betrag'] ); $form[] = '%f'; }

        if ( isset( $f['rechnungsdatum'] ) ) {
            $datum = self::datum( $f['rechnungsdatum'] );
            if ( $datum === '' ) return 'Das Rechnungsdatum ist nicht zu lesen.';
            $daten['rechnungsdatum'] = $datum; $form[] = '%s';
            $daten['jahr'] = (int) substr( $datum, 0, 4 ); $form[] = '%d';
        }

        if ( array_key_exists( 'empf_eigen', $f ) ) {
            $eigen = ! empty( $f['empf_eigen'] );
            $daten['empf_eigen'] = $eigen ? 1 : 0; $form[] = '%d';
            if ( $eigen ) {
                /* Zurück auf die eigenen Stammdaten: Dann sollen auch die
                   Felder wieder von dort kommen und nicht der alte fremde
                   Empfänger stehen bleiben. */
                $pers = LSV07A_Person::holen( (int) $r['wp_user_id'] );
                $daten['empf_name'] = self::kurz( $pers['kontoinhaber'], 200 ); $form[] = '%s';
                $daten['empf_iban'] = (string) $pers['iban']; $form[] = '%s';
                $daten['empf_bic']  = (string) $pers['bic'];  $form[] = '%s';
            }
        }
        $fremd = array_key_exists( 'empf_eigen', $f ) ? empty( $f['empf_eigen'] )
                                                      : ( (int) $r['empf_eigen'] === 0 );
        if ( $fremd ) {
            foreach ( [ 'empf_name' => 200, 'empf_iban' => 34, 'empf_bic' => 11 ] as $n => $max ) {
                if ( ! isset( $f[ $n ] ) ) continue;
                $wert = (string) $f[ $n ];
                if ( $n !== 'empf_name' ) $wert = strtoupper( preg_replace( '/\s+/', '', $wert ) );
                $daten[ $n ] = self::kurz( $wert, $max ); $form[] = '%s';
            }
        }

        if ( ! $daten ) return true;
        $wpdb->update( self::t( 'lsv07a_rechnung' ), $daten, [ 'id' => (int) $id ], $form, [ '%d' ] );
        return true;
    }

    /** Einreichen — ab hier entscheidet der Abteilungsleiter. */
    public static function einreichen( $id ) {
        global $wpdb;
        $r = self::holen( $id );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';
        if ( ! self::darf_aendern( $r ) ) {
            return 'Diese Rechnung ist bereits eingereicht.';
        }
        $pruef = self::vollstaendig( $r );
        if ( $pruef !== true ) return $pruef;

        $wpdb->update( self::t( 'lsv07a_rechnung' ), [
            'status'          => 'eingereicht',
            'eingereicht_am'  => current_time( 'mysql' ),
            'rueckgabe_grund' => '',
        ], [ 'id' => (int) $id ], [ '%s', '%s', '%s' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'rechnung.eingereicht', [
            'ziel_typ' => 'rechnung', 'ziel_id' => (int) $id,
            'details'  => number_format( (float) $r['betrag'], 2, ',', '.' ) . ' EUR' ] );
        return true;
    }

    /** Zurückholen, solange niemand entschieden hat. */
    public static function zurueckholen( $id ) {
        global $wpdb;
        $r = self::holen( $id );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';
        if ( (int) $r['wp_user_id'] !== get_current_user_id() ) {
            return 'Das ist nicht deine Rechnung.';
        }
        if ( $r['status'] !== 'eingereicht' ) {
            return 'Zurückholen geht nur, solange noch niemand entschieden hat.';
        }
        $wpdb->update( self::t( 'lsv07a_rechnung' ),
            [ 'status' => 'entwurf', 'eingereicht_am' => null ],
            [ 'id' => (int) $id ], [ '%s', '%s' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'rechnung.zurueckgeholt',
            [ 'ziel_typ' => 'rechnung', 'ziel_id' => (int) $id ] );
        return true;
    }

    public static function genehmigen( $id ) {
        global $wpdb;
        $r = self::holen( $id );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';
        if ( ! self::darf_genehmigen( $r ) ) {
            return 'Diese Rechnung gehört nicht zu deiner Abteilung.';
        }
        if ( $r['status'] !== 'eingereicht' ) {
            return 'Genehmigt wird, was eingereicht ist (Stand: „'
                 . LSV07A_Berechnung::status_name( $r['status'] ) . '").';
        }

        $wpdb->update( self::t( 'lsv07a_rechnung' ), [
            'status'        => 'genehmigt',
            'genehmigt_am'  => current_time( 'mysql' ),
            'genehmigt_von' => get_current_user_id(),
        ], [ 'id' => (int) $id ], [ '%s', '%s', '%d' ], [ '%d' ] );
        /* Eine Selbstgenehmigung bekommt eine EIGENE Zeile im Protokoll.
           Im selben Topf wäre sie beim Durchsehen nicht zu finden, und
           genau danach sucht eine Kassenprüfung. */
        $selbst = (int) $r['wp_user_id'] === get_current_user_id();
        LSV07A_Log::schreibe( $selbst ? 'rechnung.genehmigt_selbst' : 'rechnung.genehmigt', [
            'ziel_typ' => 'rechnung', 'ziel_id' => (int) $id,
            'details'  => number_format( (float) $r['betrag'], 2, ',', '.' ) . ' EUR'
                        . ( $selbst ? ' — selbst eingereicht und selbst genehmigt' : '' ) ] );
        return true;
    }

    /**
     * Hat hier jemand die eigene Rechnung genehmigt?
     *
     * Abgeleitet und nicht gespeichert: Es steht ohnehin in der Zeile, wer
     * eingereicht und wer genehmigt hat. Eine eigene Spalte könnte
     * auseinanderlaufen, ein Vergleich nicht.
     */
    public static function selbst_genehmigt( $r ) {
        if ( is_numeric( $r ) ) $r = self::holen( $r );
        if ( ! $r ) return false;
        return (int) $r['genehmigt_von'] > 0
            && (int) $r['genehmigt_von'] === (int) $r['wp_user_id'];
    }

    public static function zurueckgeben( $id, $grund ) {
        global $wpdb;
        $r = self::holen( $id );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';
        if ( ! self::darf_genehmigen( $r ) ) {
            return 'Diese Rechnung gehört nicht zu deiner Abteilung.';
        }
        if ( ! in_array( $r['status'], [ 'eingereicht', 'genehmigt' ], true ) ) {
            return 'Zurückgeben lässt sich nur, was eingereicht oder genehmigt ist.';
        }
        $grund = trim( (string) $grund );
        if ( $grund === '' ) {
            return 'Bitte schreibe dazu, was zu ändern ist — sonst weiss niemand, warum.';
        }

        $wpdb->update( self::t( 'lsv07a_rechnung' ), [
            'status'          => 'zurueck',
            'rueckgabe_grund' => mb_substr( $grund, 0, 1000 ),
            'genehmigt_am'    => null,
            'genehmigt_von'   => 0,
        ], [ 'id' => (int) $id ], [ '%s', '%s', '%s', '%d' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'rechnung.zurueck', [
            'ziel_typ' => 'rechnung', 'ziel_id' => (int) $id, 'details' => $grund ] );
        return true;
    }

    public static function bezahlt( $id ) {
        global $wpdb;
        $r = self::holen( $id );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';
        if ( $r['status'] !== 'genehmigt' ) {
            return $r['status'] === 'bezahlt'
                ? 'Diese Rechnung ist bereits als bezahlt vermerkt.'
                : 'Bezahlt wird, was genehmigt ist (Stand: „'
                  . LSV07A_Berechnung::status_name( $r['status'] ) . '").';
        }
        $wpdb->update( self::t( 'lsv07a_rechnung' ), [
            'status'      => 'bezahlt',
            'bezahlt_am'  => current_time( 'mysql' ),
            'bezahlt_von' => get_current_user_id(),
        ], [ 'id' => (int) $id ], [ '%s', '%s', '%d' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'rechnung.bezahlt', [
            'ziel_typ' => 'rechnung', 'ziel_id' => (int) $id,
            'details'  => number_format( (float) $r['betrag'], 2, ',', '.' ) . ' EUR' ] );
        return true;
    }

    /** Löschen — nur ein eigener Entwurf, und mitsamt seinen Belegen. */
    public static function loeschen( $id ) {
        global $wpdb;
        $r = self::holen( $id );
        if ( ! $r ) return 'Diese Rechnung gibt es nicht.';
        if ( ! self::darf_aendern( $r ) ) {
            return 'Eine eingereichte Rechnung lässt sich nicht löschen.';
        }
        self::dateien_alle_loeschen( (int) $id );
        $wpdb->delete( self::t( 'lsv07a_rechnung' ), [ 'id' => (int) $id ], [ '%d' ] );
        LSV07A_Log::schreibe( 'rechnung.weg', [ 'ziel_typ' => 'rechnung', 'ziel_id' => (int) $id ] );
        return true;
    }

    // ── Der Girocode ─────────────────────────────────────────────────────

    /**
     * Der QR-Code zu einer Rechnung — der Weg, auf dem die Kasse überweist,
     * ohne eine Ziffer abzutippen.
     *
     * Im Verwendungszweck steht die Rechnungsnummer dieses Systems. Das ist
     * kein Beiwerk: Auf dem Kontoauszug muss sich eine Buchung einer
     * Rechnung zuordnen lassen, sonst sucht die Kasse im nächsten Jahr.
     */
    public static function giro( $id ) {
        $r = self::holen( $id );
        if ( ! $r ) return [ 'fehler' => 'Diese Rechnung gibt es nicht.' ];

        $zweck = trim( (string) $r['zweck'] );
        $kennung = 'RG-' . str_pad( (string) (int) $r['id'], 4, '0', STR_PAD_LEFT );
        $zweck = $zweck === '' ? $kennung : ( $kennung . ' ' . $zweck );

        $text = LSV07A_QR::epc( [
            'name'   => $r['empf_name'],
            'iban'   => $r['empf_iban'],
            'bic'    => $r['empf_bic'],
            'betrag' => (float) $r['betrag'],
            'zweck'  => $zweck,
        ] );
        if ( is_array( $text ) ) return $text;

        return [
            'text'  => $text,
            'svg'   => LSV07A_QR::svg( $text ),
            'zweck' => $zweck,
        ];
    }

    // ── Belege ───────────────────────────────────────────────────────────

    private static function ordner( $rechnung_id ) {
        $up = wp_upload_dir();
        $basis = rtrim( $up['basedir'], '/' ) . '/lsv07a-private';
        self::ordner_schuetzen( $basis );
        $pfad = $basis . '/rechnung/' . (int) $rechnung_id;
        if ( ! is_dir( $pfad ) ) wp_mkdir_p( $pfad );
        return $pfad;
    }

    /**
     * Der Ordner wird gesperrt, sobald er entsteht.
     *
     * Apache liest die .htaccess, nginx nicht — darum liegt dort auch ein
     * Hinweis für den, der nginx betreibt. Die leere index.html verhindert
     * eine Verzeichnisauflistung bei Servern, die das noch anbieten.
     */
    private static function ordner_schuetzen( $basis ) {
        if ( ! is_dir( $basis ) ) wp_mkdir_p( $basis );

        $ht = $basis . '/.htaccess';
        if ( ! file_exists( $ht ) ) {
            file_put_contents( $ht,
                "# Belege zu Rechnungen. Kein Zugriff über den Webserver —\n"
              . "# der einzige Weg führt über den Download-Endpunkt des Plugins.\n"
              . "Deny from all\n"
              . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" );
        }
        $nginx = $basis . '/README-NGINX.txt';
        if ( ! file_exists( $nginx ) ) {
            file_put_contents( $nginx,
                "Dieser Ordner enthält Rechnungsbelege und darf vom Webserver nicht\n"
              . "ausgeliefert werden. Apache liest dafür die .htaccess daneben.\n\n"
              . "nginx liest sie NICHT. Dort gehört in die Server-Konfiguration:\n\n"
              . "  location ~* /uploads/lsv07a-private/ { deny all; return 404; }\n" );
        }
        $idx = $basis . '/index.html';
        if ( ! file_exists( $idx ) ) file_put_contents( $idx, '' );
    }

    /**
     * Warnt, wenn der Webserver die .htaccess gar nicht liest.
     *
     * Apache und LiteSpeed lesen sie, nginx NICHT. Läuft nginx, ist der
     * Belegordner trotz aller Sperrdateien ausgeliefert, solange niemand
     * den `location`-Block in die Server-Konfiguration schreibt. Zu holen
     * ist dieser Satz nirgends — also wird er gesagt, dort wo ihn jemand
     * liest, der ihn beheben kann.
     *
     * Geprüft wird nur, was der Server über sich selbst sagt; es wird
     * nichts abgerufen und nichts nach draußen geschickt.
     *
     * @return string Leer, wenn alles in Ordnung ist.
     */
    public static function server_hinweis() {
        $sw = strtolower( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
        $block = 'location ~* /uploads/lsv07a-private/ { deny all; return 404; }';

        if ( strpos( $sw, 'nginx' ) !== false ) {
            return 'Dieser Server ist ein nginx. Er liest die .htaccess NICHT, mit der das '
                 . 'Plugin den Belegordner sperrt — die Rechnungsbelege sind damit über den '
                 . 'Webserver erreichbar. Bitte in die Server-Konfiguration aufnehmen: '
                 . $block;
        }
        if ( $sw === '' || ( strpos( $sw, 'apache' ) === false
                             && strpos( $sw, 'litespeed' ) === false ) ) {
            return 'Dieser Server meldet sich als „' . ( $sw !== '' ? $sw : 'unbekannt' ) . '". '
                 . 'Ob er die .htaccess liest, mit der das Plugin den Belegordner sperrt, lässt '
                 . 'sich von hier nicht feststellen. Prüfen Sie, ob '
                 . 'uploads/lsv07a-private/ über den Webserver erreichbar ist; wenn ja, sperren '
                 . 'Sie den Ordner dort. Bei nginx: ' . $block;
        }
        return '';
    }

    public static function dateien( $rechnung_id ) {
        global $wpdb;
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'lsv07a_rechnung_datei' ) . "
              WHERE rechnung_id = %d ORDER BY id ASC", (int) $rechnung_id ), ARRAY_A ) ?: [];
        $out = [];
        foreach ( $zeilen as $z ) {
            $out[] = [
                'id'      => (int) $z['id'],
                'name'    => (string) $z['dateiname'],
                'mime'    => (string) $z['mime_type'],
                'groesse' => (int) $z['groesse'],
                'groesse_text' => self::groesse_text( (int) $z['groesse'] ),
                'bild'    => strpos( (string) $z['mime_type'], 'image/' ) === 0,
                'am'      => (string) $z['hochgeladen_am'],
            ];
        }
        return $out;
    }

    public static function datei_holen( $datei_id ) {
        global $wpdb;
        $z = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'lsv07a_rechnung_datei' ) . " WHERE id = %d",
            (int) $datei_id ), ARRAY_A );
        if ( ! $z ) return null;

        /* Der Dateiname kommt aus der eigenen Spalte und wird trotzdem
           geprüft, bevor er in einen Pfad wandert. Geschrieben wird er nur
           an einer Stelle und dort als 32 Hexziffern plus Endung — aber ein
           Pfad, der aus der Datenbank kommt, ist ein Pfad aus der
           Datenbank. Passt die Form nicht, gibt es die Datei nicht.
           Verzeichniswechsel („../") lässt sich so gar nicht erst
           ausdrücken. */
        if ( ! preg_match( '/^[0-9a-f]{32}\.(pdf|jpg|png|webp)$/', (string) $z['gespeichert_als'] ) ) {
            return null;
        }
        $z['pfad'] = self::ordner( (int) $z['rechnung_id'] ) . '/' . $z['gespeichert_als'];
        return $z;
    }

    /**
     * Einen Beleg ablegen.
     *
     * Geprüft wird an den tatsächlichen Bytes und nicht an dem, was der
     * Browser behauptet: Eine `rechnung.pdf`, in der ein Skript steht, ist
     * kein PDF. Auf der Platte bekommt die Datei einen Zufallsnamen — wer
     * eine Rechnungsnummer errät, errät damit keinen Pfad.
     */
    public static function ablegen( $rechnung_id, array $datei ) {
        global $wpdb;
        $r = self::holen( $rechnung_id );
        if ( ! $r ) return [ 'ok' => false, 'message' => 'Diese Rechnung gibt es nicht.' ];
        if ( ! self::darf_aendern( $r ) ) {
            return [ 'ok' => false, 'message' =>
                'Diese Rechnung ist eingereicht — ihr Beleg lässt sich nicht mehr tauschen.' ];
        }

        $tmp = (string) ( $datei['tmp_name'] ?? '' );
        if ( $tmp === '' || ! is_file( $tmp ) ) {
            return [ 'ok' => false, 'message' => 'Die Datei ist nicht angekommen.' ];
        }
        $pruefer = self::$pruefe_upload ?: 'is_uploaded_file';
        if ( ! call_user_func( $pruefer, $tmp ) ) {
            return [ 'ok' => false, 'message' => 'Die Datei ist nicht angekommen.' ];
        }

        $groesse = (int) ( $datei['size'] ?? filesize( $tmp ) );
        if ( $groesse <= 0 ) return [ 'ok' => false, 'message' => 'Die Datei ist leer.' ];
        if ( $groesse > self::MAX_BYTES ) {
            return [ 'ok' => false, 'message' => 'Die Datei ist größer als 12 MB. '
                   . 'Fotografiere den Beleg mit weniger Auflösung oder lade ihn als PDF hoch.' ];
        }

        $mime = self::mime( $tmp );
        if ( ! isset( self::ERLAUBT[ $mime ] ) ) {
            return [ 'ok' => false, 'message' => 'Als Beleg gehen ein PDF oder ein Foto '
                   . '(JPEG, PNG, WebP). Diese Datei ist keines von beidem.' ];
        }

        $endung = self::ERLAUBT[ $mime ];
        $name   = bin2hex( random_bytes( 16 ) ) . '.' . $endung;
        $ziel   = self::ordner( (int) $rechnung_id ) . '/' . $name;

        /* Verschlüsselt ablegen. Damit hängt der Schutz nicht mehr an der
           Server-Konfiguration: Wer die Datei in die Hand bekommt, bekommt
           Rauschen. Gebunden wird sie an ihren Namen — eine Datei lässt
           sich so nicht in einen anderen Rechnungsordner umhängen.

           Kann dieser Server es nicht, wird im Klartext abgelegt statt die
           Aufnahme zu verweigern: Eine Abrechnung, die nicht einzureichen
           ist, hilft niemandem. GESAGT wird es dafür in der Verwaltung,
           siehe LSV07A_Tresor::hinweis(). */
        $klartext = @file_get_contents( $tmp );
        if ( $klartext === false ) {
            return [ 'ok' => false, 'message' => 'Die Datei liess sich nicht lesen.' ];
        }
        $inhalt = LSV07A_Tresor::verschluesseln( $klartext, $name );
        if ( $inhalt === null ) $inhalt = $klartext;

        if ( @file_put_contents( $ziel, $inhalt ) === false ) {
            return [ 'ok' => false, 'message' => 'Die Datei liess sich nicht ablegen.' ];
        }

        $wpdb->insert( self::t( 'lsv07a_rechnung_datei' ), [
            'rechnung_id'     => (int) $rechnung_id,
            'dateiname'       => self::kurz( $datei['name'] ?? ( 'Beleg.' . $endung ), 200 ),
            'gespeichert_als' => $name,
            'mime_type'       => $mime,
            'groesse'         => $groesse,
            'hochgeladen_von' => get_current_user_id(),
            'hochgeladen_am'  => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%d', '%d', '%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( ! $id ) { @unlink( $ziel ); return [ 'ok' => false, 'message' => 'Der Beleg liess sich nicht vermerken.' ]; }

        LSV07A_Log::schreibe( 'rechnung.beleg', [
            'ziel_typ' => 'rechnung', 'ziel_id' => (int) $rechnung_id ] );
        return [ 'ok' => true, 'datei' => self::dateien( (int) $rechnung_id ) ];
    }

    /**
     * Der Klartext einer Belegdatei.
     *
     * Der EINZIGE Weg, an den Inhalt zu kommen — damit nirgends versehentlich
     * der verschlüsselte Rohstand ausgeliefert wird.
     *
     * Alte Dateien aus einer Fassung vor dem Tresor liegen im Klartext. Die
     * werden erkannt, ausgeliefert und beim ersten Lesen nachträglich
     * verschlüsselt. Scheitert das Nachziehen, bleibt die Auslieferung
     * trotzdem richtig: Ein Download darf nicht daran scheitern, dass eine
     * Aufräumarbeit nicht geklappt hat.
     *
     * @return string|null Null, wenn die Datei fehlt oder sich nicht
     *                     entschlüsseln lässt.
     */
    public static function inhalt( array $datei ) {
        $pfad = (string) ( $datei['pfad'] ?? '' );
        if ( $pfad === '' || ! is_file( $pfad ) ) return null;
        $roh = @file_get_contents( $pfad );
        if ( $roh === false ) return null;

        $name = (string) ( $datei['gespeichert_als'] ?? basename( $pfad ) );

        if ( LSV07A_Tresor::ist_tresor( $roh ) ) {
            return LSV07A_Tresor::entschluesseln( $roh, $name );
        }

        /* Klartext aus einer früheren Fassung: jetzt nachziehen. */
        $neu = LSV07A_Tresor::verschluesseln( $roh, $name );
        if ( $neu !== null ) {
            if ( @file_put_contents( $pfad, $neu ) !== false ) {
                LSV07A_Log::schreibe( 'rechnung.beleg_verschluesselt', [
                    'ziel_typ' => 'rechnung', 'ziel_id' => (int) ( $datei['rechnung_id'] ?? 0 ) ] );
            }
        }
        return $roh;
    }

    public static function datei_loeschen( $datei_id ) {
        global $wpdb;
        $d = self::datei_holen( $datei_id );
        if ( ! $d ) return 'Diesen Beleg gibt es nicht.';
        $r = self::holen( (int) $d['rechnung_id'] );
        if ( ! $r || ! self::darf_aendern( $r ) ) {
            return 'Diese Rechnung ist eingereicht — ihr Beleg bleibt, wie er ist.';
        }
        if ( file_exists( $d['pfad'] ) ) @unlink( $d['pfad'] );
        $wpdb->delete( self::t( 'lsv07a_rechnung_datei' ), [ 'id' => (int) $datei_id ], [ '%d' ] );
        return true;
    }

    private static function dateien_alle_loeschen( $rechnung_id ) {
        global $wpdb;
        foreach ( self::dateien( $rechnung_id ) as $d ) {
            $voll = self::datei_holen( $d['id'] );
            if ( $voll && file_exists( $voll['pfad'] ) ) @unlink( $voll['pfad'] );
        }
        $wpdb->delete( self::t( 'lsv07a_rechnung_datei' ),
            [ 'rechnung_id' => (int) $rechnung_id ], [ '%d' ] );
        $ordner = self::ordner( $rechnung_id );
        if ( is_dir( $ordner ) ) @rmdir( $ordner );
    }

    /** Der Typ aus den Bytes. finfo zuerst, dann die Kennung am Dateianfang. */
    private static function mime( $pfad ) {
        $mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $fi = finfo_open( FILEINFO_MIME_TYPE );
            if ( $fi ) { $mime = (string) finfo_file( $fi, $pfad ); finfo_close( $fi ); }
        }
        if ( isset( self::ERLAUBT[ $mime ] ) ) return $mime;

        /* Zweite Meinung an den ersten Bytes — manche Hoster liefern finfo
           nicht mit, und dann stünde hier gar keine Prüfung. */
        $kopf = (string) @file_get_contents( $pfad, false, null, 0, 16 );
        if ( strncmp( $kopf, '%PDF-', 5 ) === 0 )                   return 'application/pdf';
        if ( strncmp( $kopf, "\xFF\xD8\xFF", 3 ) === 0 )            return 'image/jpeg';
        if ( strncmp( $kopf, "\x89PNG\r\n\x1A\n", 8 ) === 0 )       return 'image/png';
        if ( strncmp( $kopf, 'RIFF', 4 ) === 0
          && substr( $kopf, 8, 4 ) === 'WEBP' )                     return 'image/webp';
        return $mime;
    }

    // ── Kleinkram ────────────────────────────────────────────────────────

    public static function kurz( $s, $max ) {
        $s = trim( preg_replace( '/[\x00-\x1F]+/', ' ', (string) $s ) );
        return mb_substr( $s, 0, $max );
    }

    /** „12,50", „12.50" oder „1.234,56" als Zahl. */
    public static function betrag( $roh ) {
        if ( is_float( $roh ) || is_int( $roh ) ) return round( max( 0, (float) $roh ), 2 );
        $s = trim( (string) $roh );
        $s = preg_replace( '/[^\d,.\-]/', '', $s );
        /* Steht ein Komma darin, ist es das Dezimalzeichen und der Punkt
           trennt die Tausender — die deutsche Schreibweise. */
        if ( strpos( $s, ',' ) !== false ) {
            $s = str_replace( '.', '', $s );
            $s = str_replace( ',', '.', $s );
        } elseif ( preg_match( '/^-?\d{1,3}(\.\d{3})+$/', $s ) ) {
            /* Kein Komma, aber Punktgruppen von genau drei Ziffern: „1.000"
               und „1.250.000" sind deutsche Tausenderpunkte und nicht 1,00
               bzw. 1,25. Bei „12.50" greift das nicht — zwei Ziffern hinter
               dem Punkt sind ein Dezimalzeichen. Drei Nachkommastellen gibt
               es bei einem Rechnungsbetrag nicht, darum ist die Regel
               eindeutig. */
            $s = str_replace( '.', '', $s );
        }
        return round( max( 0, (float) $s ), 2 );
    }

    /** Deutsches oder ISO-Datum. */
    public static function datum( $roh ) {
        $s = trim( (string) $roh );
        if ( $s === '' ) return '';
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
            return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $s : '';
        }
        if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m ) ) {
            return checkdate( (int) $m[2], (int) $m[1], (int) $m[3] )
                ? sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] ) : '';
        }
        return '';
    }

    public static function groesse_text( $bytes ) {
        if ( $bytes >= 1048576 ) return round( $bytes / 1048576, 1 ) . ' MB';
        if ( $bytes >= 1024 )    return round( $bytes / 1024 ) . ' kB';
        return $bytes . ' Bytes';
    }

    /** Der Dateiname beim Herunterladen: Jahr, Nummer, Betreff. */
    public static function dateiname( $r, $endung = 'pdf' ) {
        if ( is_numeric( $r ) ) $r = self::holen( $r );
        if ( ! $r ) return 'Rechnung.' . $endung;
        $titel = (string) $r['titel'];
        $titel = strtr( $titel, [ 'ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ß'=>'ss' ] );
        $titel = preg_replace( '/[^A-Za-z0-9 _-]+/', '', $titel );
        $titel = trim( preg_replace( '/\s+/', '-', $titel ), '-' );
        if ( $titel === '' ) $titel = 'Rechnung';
        return (int) $r['jahr'] . '_RG-' . str_pad( (string) (int) $r['id'], 4, '0', STR_PAD_LEFT )
             . '_' . mb_substr( $titel, 0, 60 ) . '.' . $endung;
    }
}
