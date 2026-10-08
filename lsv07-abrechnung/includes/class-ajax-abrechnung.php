<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die eigene Abrechnung: laden, Posten pflegen, einreichen.
 *
 * Gerechnet wird ausschließlich hier auf dem Server. Die Oberfläche schickt
 * nur, was eingegeben wurde (Stunden, Abschnitte, Kilometer) — die Beträge
 * entstehen aus den eingestellten Sätzen. Was im Browser steht, kann
 * verändert werden; was in der Datenbank landet, soll stimmen.
 */
class LSV07A_Ajax_Abrechnung {

    public static function init() {
        $map = [
            'lsv07a_get'                => 'get',
            'lsv07a_training_angebot'   => 'training_angebot',
            'lsv07a_training_uebernehmen'=> 'training_uebernehmen',
            'lsv07a_wettkampf_angebot'  => 'wettkampf_angebot',
            'lsv07a_posten_speichern'   => 'posten_speichern',
            'lsv07a_posten_loeschen'    => 'posten_loeschen',
            'lsv07a_einreichen'         => 'einreichen',
            'lsv07a_zurueckziehen'      => 'zurueckziehen',
            'lsv07a_zahlungsdaten_get'  => 'zahlungsdaten_get',
            'lsv07a_zahlungsdaten_save' => 'zahlungsdaten_save',
            'lsv07a_meine_liste'        => 'meine_liste',
            'lsv07a_posten_wartezeit'   => 'posten_wartezeit',
            'lsv07a_einstellung_save'   => 'einstellung_save',
            'lsv07a_nachtrag'           => 'nachtrag',
        ];
        foreach ( $map as $aktion => $methode ) {
            add_action( 'wp_ajax_' . $aktion, [ __CLASS__, $methode ] );
        }
    }

    // ── Hilfen ───────────────────────────────────────────────────────────

    private static function tbl( $name ) { global $wpdb; return $wpdb->prefix . $name; }

    /** Die Abrechnung dieser Person für Quartal/Jahr — notfalls neu angelegt. */
    private static function holen_oder_anlegen( $uid, $quartal, $jahr ) {
        global $wpdb;
        /* Zu einem Quartal kann es mehrere Abrechnungen geben: die
           ursprüngliche und ihre Nachträge. Gearbeitet wird immer am
           Ende der Kette — die früheren sind abgeschlossen und stehen
           unter „Frühere Abrechnungen". */
        $zeile = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . "
              WHERE wp_user_id = %d AND quartal = %s AND jahr = %d
           ORDER BY id DESC LIMIT 1", $uid, $quartal, $jahr ), ARRAY_A );
        if ( $zeile ) return $zeile;

        $person = LSV07A_Person::holen( $uid );
        $ok = $wpdb->insert( self::tbl( 'lsv07a_abrechnung' ), [
            'wp_user_id'     => $uid,
            'quartal'        => $quartal,
            'jahr'           => $jahr,
            'status'         => 'entwurf',
            'stundensatz'    => $person['stundensatz'],
            'abrechnungsart' => $person['abrechnungsart'],
        ], [ '%d', '%s', '%d', '%s', '%f', '%s' ] );
        if ( $ok === false ) {
            wp_send_json_error( [ 'message' => 'Die Abrechnung konnte nicht angelegt werden: '
                . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' ) ] );
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d",
            $wpdb->insert_id ), ARRAY_A );
    }

    /**
     * Solange eine Abrechnung offen ist, folgt sie den aktuellen Sätzen:
     * Ändert der Administrator den Stundensatz, die Pauschale oder die
     * Abrechnungsart, werden die Posten neu gerechnet. Ab dem Einreichen
     * bleibt alles stehen — geprüft und genehmigt wird genau das, was
     * eingereicht wurde.
     */
    public static function neu_rechnen( $abr ) {
        if ( ! LSV07A_Berechnung::offen( $abr['status'] ) ) return;
        global $wpdb;

        $person = LSV07A_Person::holen( $abr['wp_user_id'] );
        $cfg    = LSV07A_DB::config_alle();
        $pausch = LSV07A_Berechnung::pauschalen();
        $pers_p = LSV07A_Berechnung::person_pauschalen();
        $satz   = (float) $person['stundensatz'];
        $art    = $person['abrechnungsart'];

        // Schnappschuss der Abrechnung mitführen, solange sie offen ist
        if ( (float) $abr['stundensatz'] !== $satz || $abr['abrechnungsart'] !== $art ) {
            $wpdb->update( self::tbl( 'lsv07a_abrechnung' ),
                [ 'stundensatz' => $satz, 'abrechnungsart' => $art ],
                [ 'id' => (int) $abr['id'] ], [ '%f', '%s' ], [ '%d' ] );
        }

        $posten = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_posten' ) . " WHERE abrechnung_id = %d",
            (int) $abr['id'] ), ARRAY_A ) ?: [];

        foreach ( $posten as $p ) {
            $neu = null;
            if ( $p['typ'] === 'training' ) {
                // Bei Pauschale sind die Stunden ohne Belang, bei den anderen
                // beiden Wegen steckt die Wartezeit schon in menge — deshalb
                // wird sie hier wieder herausgerechnet, bevor neu gerechnet wird.
                $zuschlag = $p['wartezeit'] ? round( max( 0, (int) $cfg['wartezeit_min'] ) / 60, 4 ) : 0.0;
                $basis    = $art === 'pauschale' ? 0.0 : max( 0, (float) $p['menge'] - $zuschlag );
                $neu = LSV07A_Berechnung::training( $art, $basis, $satz, (int) $p['wartezeit'],
                    (int) $p['mannschaft_id'], $pausch, $cfg['wartezeit_min'],
                    LSV07A_Berechnung::wochentag( $p['datum'] ), $pers_p, (int) $abr['wp_user_id'] );
            } elseif ( $p['typ'] === 'wettkampf' ) {
                $neu = LSV07A_Berechnung::wettkampf( $p['menge'], $cfg['wk_satz'] );
            } elseif ( $p['typ'] === 'fahrt' ) {
                $neu = LSV07A_Berechnung::fahrt( $p['menge'], $p['tage'], $cfg['km_satz'],
                    $cfg['km_mindest'], ! empty( $cfg['km_hin_rueck'] ) );
            } elseif ( $p['typ'] === 'vorbereitung' ) {
                $neu = LSV07A_Berechnung::vorbereitung( $p['menge'], $satz );
            }
            if ( ! $neu ) continue;
            if ( abs( (float) $p['betrag'] - $neu['betrag'] ) < 0.005
                 && abs( (float) $p['satz'] - $neu['satz'] ) < 0.005
                 && abs( (float) $p['menge'] - $neu['menge'] ) < 0.005 ) continue;
            $wpdb->update( self::tbl( 'lsv07a_posten' ),
                [ 'menge' => $neu['menge'], 'satz' => $neu['satz'], 'betrag' => $neu['betrag'] ],
                [ 'id' => (int) $p['id'] ], [ '%f', '%f', '%f' ], [ '%d' ] );
        }
    }

    /** Vollständige Abrechnung mit Posten, Summen und Rahmendaten. */
    public static function paket( $abr ) {
        $rechnung = LSV07A_Berechnung::summe( (int) $abr['id'] );
        $person   = LSV07A_Person::holen( $abr['wp_user_id'] );
        $u        = get_userdata( $abr['wp_user_id'] );
        return [
            'id'             => (int) $abr['id'],
            'wp_user_id'     => (int) $abr['wp_user_id'],
            'name'           => $u ? $u->display_name : ( 'Konto ' . $abr['wp_user_id'] ),
            'quartal'        => $abr['quartal'],
            'jahr'           => (int) $abr['jahr'],
            'status'         => $abr['status'],
            'status_name'    => LSV07A_Berechnung::status_name( $abr['status'] ),
            'offen'          => LSV07A_Berechnung::offen( $abr['status'] ),
            'stundensatz'    => (float) $abr['stundensatz'],
            'abrechnungsart' => $abr['abrechnungsart'],
            'art_name'       => LSV07A_Berechnung::art_name( $abr['abrechnungsart'] ),
            'kommentar'      => (string) $abr['kommentar'],
            'rueckgabe_grund'=> (string) $abr['rueckgabe_grund'],
            'eingereicht_am' => $abr['eingereicht_am'],
            'genehmigt_am'   => $abr['genehmigt_am'],
            'bezahlt_am'     => $abr['bezahlt_am'],
            'nachtrag_zu'    => (int) ( $abr['nachtrag_zu'] ?? 0 ),
            'posten'         => $rechnung['posten'],
            'summen'         => $rechnung['summen'],
            'gesamt'         => $rechnung['gesamt'],
            /* Zahlungsdaten gehen NUR an die eigene Person, an die Kasse
               (sie überweist) und an die Administration. Der Wart prüft
               Stunden und Beträge — Bankverbindung und Wohnanschrift
               gehen ihn nichts an. Ob sie vollständig sind, darf er
               wissen, denn ohne sie lässt sich nicht auszahlen; der Wert
               selbst bleibt hier. */
            'zahlungsdaten'  => self::zahlungsdaten_fuer( $person, (int) $abr['wp_user_id'] ),
        ];
    }

    private static function zahlungsdaten_fuer( $person, $gehoert_zu ) {
        $vollstaendig = trim( (string) $person['iban'] ) !== ''
                     && trim( (string) $person['kontoinhaber'] ) !== '';
        $eigene = (int) $gehoert_zu === get_current_user_id();
        if ( ! $eigene && ! LSV07A_Access::darf_zahlungsdaten() ) {
            return [
                'iban' => '', 'bic' => '', 'kontoinhaber' => '',
                'strasse' => '', 'plz' => '', 'ort' => '',
                'vollstaendig' => $vollstaendig,
                'verborgen'    => true,
            ];
        }
        return [
            'iban' => $person['iban'], 'bic' => $person['bic'],
            'kontoinhaber' => $person['kontoinhaber'],
            'strasse' => $person['strasse'], 'plz' => $person['plz'], 'ort' => $person['ort'],
            'vollstaendig' => $vollstaendig,
            'verborgen'    => false,
        ];
    }

    private static function eigene_abrechnung( $abr_id ) {
        global $wpdb;
        $abr = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", (int) $abr_id ), ARRAY_A );
        if ( ! $abr ) wp_send_json_error( [ 'message' => 'Abrechnung nicht gefunden.' ] );
        if ( (int) $abr['wp_user_id'] !== get_current_user_id() && ! LSV07A_Rollen::ist_admin() ) {
            wp_send_json_error( [ 'message' => 'Das ist nicht Ihre Abrechnung.' ], 403 );
        }
        return $abr;
    }

    // ── Endpunkte ────────────────────────────────────────────────────────

    public static function get() {
        LSV07A_Access::check( 'trainer' );
        $uid     = get_current_user_id();
        $quartal = sanitize_text_field( $_POST['quartal'] ?? LSV07A_Berechnung::quartal_von_datum( date( 'Y-m-d' ) ) );
        $jahr    = (int) ( $_POST['jahr'] ?? date( 'Y' ) );
        if ( ! LSV07A_Berechnung::quartal_gueltig( $quartal ) ) wp_send_json_error( [ 'message' => 'Unbekanntes Quartal.' ] );
        if ( ! LSV07A_Berechnung::jahr_gueltig( $jahr ) )       wp_send_json_error( [ 'message' => 'Unmögliches Jahr.' ] );

        LSV07A_Person::sicherstellen( $uid );
        $abr = self::holen_oder_anlegen( $uid, $quartal, $jahr );
        /* Erst übernehmen, dann rechnen: So gehen die frisch übernommenen
           Posten gleich durch dieselbe Berechnung wie alle anderen. */
        [ $auto_neu, $auto_ohne_zeit ] = self::auto_uebernehmen( $abr );
        self::neu_rechnen( $abr );
        $abr = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_abrechnung' ) . " WHERE id = %d", $abr['id'] ), ARRAY_A );

        $paket = self::paket( $abr );
        $paket['hinweis_intern'] = LSV07A_Intern::hinweis();
        $paket['auto_training']  = (int) LSV07A_Person::holen( $uid )['auto_training'];
        $paket['auto_neu']       = $auto_neu;
        $paket['auto_ohne_zeit'] = $auto_ohne_zeit;
        /* Beanstandungen und Rückfragen gehören an die Zeile — die Person
           soll sehen, was gemeint ist, ohne im Rückgabetext zu suchen. */
        $paket = array_merge( $paket, LSV07A_Ajax_Notiz::paket( (int) $abr['id'] ) );
        wp_send_json_success( $paket );
    }

    /**
     * Die Wartezeit eines Trainings an- oder abschalten — direkt in der
     * Abrechnung, nicht nur beim Übernehmen. Ob jemand vor oder nach dem
     * Training gewartet hat, weiss er oft erst hinterher.
     */
    public static function posten_wartezeit() {
        LSV07A_Access::check( 'trainer', true );
        global $wpdb;
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );
        if ( ! LSV07A_Berechnung::offen( $abr['status'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist bereits eingereicht und kann nicht mehr geändert werden.' ] );
        }
        $pid = absint( $_POST['posten_id'] ?? 0 );
        $an  = ! empty( $_POST['wartezeit'] ) ? 1 : 0;

        // Der Posten muss zu DIESER Abrechnung gehören — eine fremde
        // Kennung aus dem Browser darf nichts bewirken.
        $posten = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::tbl( 'lsv07a_posten' ) . "
              WHERE id = %d AND abrechnung_id = %d", $pid, (int) $abr['id'] ), ARRAY_A );
        if ( ! $posten ) wp_send_json_error( [ 'message' => 'Posten nicht gefunden.' ] );
        if ( $posten['typ'] !== 'training' ) {
            wp_send_json_error( [ 'message' => 'Eine Wartezeit gibt es nur bei Trainings.' ] );
        }

        $person = LSV07A_Person::holen( $abr['wp_user_id'] );
        $cfg    = LSV07A_DB::config_alle();
        $pausch = LSV07A_Berechnung::pauschalen();
        $pers_p = LSV07A_Berechnung::person_pauschalen();

        /* Die bisherige Wartezeit steckt schon in der Menge — erst heraus,
           dann neu rechnen. Sonst summierte sich der Aufschlag bei jedem
           Umschalten auf. */
        $zuschlag = $posten['wartezeit'] ? round( max( 0, (int) $cfg['wartezeit_min'] ) / 60, 4 ) : 0.0;
        $basis    = $person['abrechnungsart'] === 'pauschale'
            ? 0.0 : max( 0, (float) $posten['menge'] - $zuschlag );

        $werte = LSV07A_Berechnung::training( $person['abrechnungsart'], $basis,
            $person['stundensatz'], $an, (int) $posten['mannschaft_id'], $pausch, $cfg['wartezeit_min'],
            LSV07A_Berechnung::wochentag( $posten['datum'] ), $pers_p, (int) $abr['wp_user_id'] );

        $wpdb->update( self::tbl( 'lsv07a_posten' ), [
            'wartezeit' => $an, 'menge' => $werte['menge'],
            'satz' => $werte['satz'], 'betrag' => $werte['betrag'],
        ], [ 'id' => $pid ], [ '%d','%f','%f','%f' ], [ '%d' ] );

        wp_send_json_success( [
            'message' => $an ? 'Wartezeit hinzugerechnet.' : 'Wartezeit entfernt.',
        ] );
    }

    /**
     * Die eigene Einstellung: Trainings von selbst übernehmen oder wie
     * bisher auswählen. Jede Person entscheidet das für sich — es ist
     * eine Frage der Arbeitsweise, nicht der Vorgaben.
     */
    public static function einstellung_save() {
        LSV07A_Access::check( 'trainer', true );
        $uid = get_current_user_id();
        $an  = ! empty( $_POST['auto_training'] ) ? 1 : 0;
        LSV07A_Person::speichern( $uid, [ 'auto_training' => $an ] );
        LSV07A_Log::schreibe( 'einstellung.auto_training', [
            'ziel_typ' => 'person', 'ziel_id' => $uid, 'details' => $an ? 'an' : 'aus' ] );
        wp_send_json_success( [
            'auto_training' => $an,
            'message' => $an
                ? 'Trainings werden ab jetzt beim Öffnen von selbst übernommen.'
                : 'Trainings werden wieder ausgewählt.',
        ] );
    }

    /** Welche Trainings aus dem internen Bereich stehen zur Übernahme bereit? */
    public static function training_angebot() {
        LSV07A_Access::check( 'trainer' );
        global $wpdb;
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );
        [ $von, $bis ] = LSV07A_Berechnung::zeitraum( $abr['quartal'], $abr['jahr'] );

        $trainer_id = LSV07A_Intern::trainer_id( $abr['wp_user_id'] );
        if ( ! $trainer_id ) {
            wp_send_json_success( [ 'trainings' => [], 'hinweis' =>
                'Zu diesem Konto gibt es im internen Bereich kein aktives Trainer-Profil. '
                . 'Ohne das lassen sich keine Trainings übernehmen — die Administration kann es dort anlegen.' ] );
        }

        $trainings = LSV07A_Intern::trainings( $trainer_id, $von, $bis );

        // Schon übernommene ausblenden
        $drin = $wpdb->get_results( $wpdb->prepare(
            "SELECT ref_typ, ref_id FROM " . self::tbl( 'lsv07a_posten' ) . "
              WHERE abrechnung_id = %d AND typ = 'training'", (int) $abr['id'] ), ARRAY_A ) ?: [];
        $schon = [];
        foreach ( $drin as $d ) $schon[ $d['ref_typ'] . ':' . $d['ref_id'] ] = true;

        $person = LSV07A_Person::holen( $abr['wp_user_id'] );
        $pausch = LSV07A_Berechnung::pauschalen();
        $pers_p = LSV07A_Berechnung::person_pauschalen();
        $offen  = [];
        foreach ( $trainings as $t ) {
            if ( isset( $schon[ $t['ref_typ'] . ':' . $t['ref_id'] ] ) ) continue;
            // Der Betrag, der an DIESEM Wochentag gilt — nicht irgendeiner.
            $t['pauschale'] = LSV07A_Berechnung::pauschale_endgueltig(
                $pers_p, $pausch, (int) $abr['wp_user_id'],
                $t['mannschaft_id'], LSV07A_Berechnung::wochentag( $t['datum'] ) );
            $offen[] = $t;
        }

        wp_send_json_success( [
            'trainings'      => $offen,
            'abrechnungsart' => $person['abrechnungsart'],
            'art_name'       => LSV07A_Berechnung::art_name( $person['abrechnungsart'] ),
            'stundensatz'    => (float) $person['stundensatz'],
            'hinweis'        => LSV07A_Intern::hinweis(),
        ] );
    }

    /** Ausgewählte Trainings in die Abrechnung holen. */
    public static function training_uebernehmen() {
        LSV07A_Access::check( 'trainer', true );
        global $wpdb;
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );
        if ( ! LSV07A_Berechnung::offen( $abr['status'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist bereits eingereicht und kann nicht mehr geändert werden.' ] );
        }

        $auswahl = json_decode( wp_unslash( $_POST['auswahl'] ?? '[]' ), true );
        if ( ! is_array( $auswahl ) || ! $auswahl ) {
            wp_send_json_error( [ 'message' => 'Bitte mindestens ein Training auswählen.' ] );
        }

        $person = LSV07A_Person::holen( $abr['wp_user_id'] );
        $cfg    = LSV07A_DB::config_alle();
        $pausch = LSV07A_Berechnung::pauschalen();
        $pers_p = LSV07A_Berechnung::person_pauschalen();
        [ $von, $bis ] = LSV07A_Berechnung::zeitraum( $abr['quartal'], $abr['jahr'] );

        // Nur übernehmen, was wirklich aus dem internen Bereich kommt —
        // die Auswahl aus dem Browser wird gegen die Quelle geprüft.
        $trainer_id = LSV07A_Intern::trainer_id( $abr['wp_user_id'] );
        $echte = [];
        foreach ( LSV07A_Intern::trainings( $trainer_id, $von, $bis ) as $t ) {
            $echte[ $t['ref_typ'] . ':' . $t['ref_id'] ] = $t;
        }

        $angelegt = 0; $uebersprungen = 0;
        foreach ( $auswahl as $a ) {
            $schluessel = ( $a['ref_typ'] ?? '' ) . ':' . (int) ( $a['ref_id'] ?? 0 );
            if ( ! isset( $echte[ $schluessel ] ) ) { $uebersprungen++; continue; }
            $t = $echte[ $schluessel ];
            $wartezeit = ! empty( $a['wartezeit'] ) ? 1 : 0;

            $werte = LSV07A_Berechnung::training( $person['abrechnungsart'], $t['stunden'],
                $person['stundensatz'], $wartezeit, $t['mannschaft_id'], $pausch, $cfg['wartezeit_min'],
                LSV07A_Berechnung::wochentag( $t['datum'] ), $pers_p, (int) $abr['wp_user_id'] );

            if ( ! self::training_anlegen( $abr, $t, $wartezeit, $person, $pausch, $cfg, $pers_p ) ) {
                $uebersprungen++; continue;
            }
            /* Von Hand wieder geholt: Dann soll die Automatik es künftig
               auch wieder dürfen. */
            $wpdb->delete( self::tbl( 'lsv07a_nicht_auto' ), [
                'abrechnung_id' => (int) $abr['id'],
                'ref_typ'       => $t['ref_typ'],
                'ref_id'        => (int) $t['ref_id'],
            ], [ '%d', '%s', '%d' ] );
            $angelegt++;
        }

        LSV07A_Log::schreibe( 'training.uebernommen', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => (int) $abr['id'],
            'details'  => $angelegt . ' Training(s) übernommen' ] );

        wp_send_json_success( [
            'angelegt'      => $angelegt,
            'uebersprungen' => $uebersprungen,
            'message'       => $angelegt . ' Training' . ( $angelegt === 1 ? '' : 's' ) . ' übernommen.'
                               . ( $uebersprungen ? ' ' . $uebersprungen . ' übersprungen (schon enthalten oder nicht gefunden).' : '' ),
        ] );
    }

    /**
     * Ein Training als Posten anlegen. Beide Wege — Anklicken und
     * automatisch — gehen hier durch, damit sie nicht auseinanderlaufen.
     * Der eindeutige Schlüssel in der Tabelle verhindert Dubletten; ein
     * fehlgeschlagenes Einfügen heisst also "war schon da".
     */
    private static function training_anlegen( $abr, $t, $wartezeit, $person, $pausch, $cfg, $pers_p = null ) {
        global $wpdb;
        if ( $pers_p === null ) $pers_p = LSV07A_Berechnung::person_pauschalen();
        $werte = LSV07A_Berechnung::training( $person['abrechnungsart'], $t['stunden'],
            $person['stundensatz'], $wartezeit, $t['mannschaft_id'], $pausch, $cfg['wartezeit_min'],
            LSV07A_Berechnung::wochentag( $t['datum'] ), $pers_p, (int) $abr['wp_user_id'] );

        $ok = $wpdb->insert( self::tbl( 'lsv07a_posten' ), [
            'abrechnung_id' => (int) $abr['id'],
            'typ'           => 'training',
            'datum'         => $t['datum'],
            'bezeichnung'   => $t['mannschaft_name'] ?: 'Training',
            'menge'         => $werte['menge'],
            'satz'          => $werte['satz'],
            'betrag'        => $werte['betrag'],
            'wartezeit'     => $wartezeit,
            'tage'          => 1,
            'mannschaft_id' => (int) $t['mannschaft_id'],
            'notiz'         => $t['zeit_von'] && $t['zeit_bis']
                               ? ( substr( $t['zeit_von'], 0, 5 ) . '–' . substr( $t['zeit_bis'], 0, 5 ) ) : '',
            'quelle'        => 'auto',
            'ref_typ'       => $t['ref_typ'],
            'ref_id'        => $t['ref_id'],
        ], [ '%d','%s','%s','%s','%f','%f','%f','%d','%d','%d','%s','%s','%s','%d' ] );
        return $ok !== false;
    }

    /**
     * Trainings von selbst übernehmen, wenn das Konto es so eingestellt hat.
     *
     * Läuft beim Öffnen der eigenen Abrechnung und nur, solange sie offen
     * ist. Die Wartezeit bleibt dabei aus — ob sie anfiel, kann niemand
     * erraten; sie lässt sich an jeder Zeile einzeln anhaken.
     *
     * Trainings OHNE hinterlegte Zeit werden bewusst übersprungen: Sie
     * ergäben einen Posten über 0 €, der still in der Abrechnung stünde.
     * Die Oberfläche sagt stattdessen, dass es sie gibt.
     */
    public static function auto_uebernehmen( $abr ) {
        $person = LSV07A_Person::holen( $abr['wp_user_id'] );
        if ( empty( $person['auto_training'] ) ) return [ 0, 0 ];
        if ( ! LSV07A_Berechnung::offen( $abr['status'] ) ) return [ 0, 0 ];
        /* In einen Nachtrag zieht die Automatik nichts. Er ist dafür da,
           genau das eine Vergessene nachzureichen — würde sie das ganze
           Quartal hineinschütten, stünde alles doppelt da. */
        if ( ! empty( $abr['nachtrag_zu'] ) ) return [ 0, 0 ];

        $trainer_id = LSV07A_Intern::trainer_id( $abr['wp_user_id'] );
        if ( ! $trainer_id ) return [ 0, 0 ];

        global $wpdb;
        [ $von, $bis ] = LSV07A_Berechnung::zeitraum( $abr['quartal'], $abr['jahr'] );
        $drin = $wpdb->get_results( $wpdb->prepare(
            "SELECT ref_typ, ref_id FROM " . self::tbl( 'lsv07a_posten' ) . "
              WHERE abrechnung_id = %d AND typ = 'training'", (int) $abr['id'] ), ARRAY_A ) ?: [];
        $schon = [];
        foreach ( $drin as $d ) $schon[ $d['ref_typ'] . ':' . $d['ref_id'] ] = true;

        // Was einmal entfernt wurde, holt die Automatik nicht zurück.
        $aus = $wpdb->get_results( $wpdb->prepare(
            "SELECT ref_typ, ref_id FROM " . self::tbl( 'lsv07a_nicht_auto' ) . "
              WHERE abrechnung_id = %d", (int) $abr['id'] ), ARRAY_A ) ?: [];
        foreach ( $aus as $d ) $schon[ $d['ref_typ'] . ':' . $d['ref_id'] ] = true;

        $cfg    = LSV07A_DB::config_alle();
        $pausch = LSV07A_Berechnung::pauschalen();
        $pers_p = LSV07A_Berechnung::person_pauschalen();
        $pauschal = $person['abrechnungsart'] === 'pauschale';

        $angelegt = 0; $ohne_zeit = 0;
        foreach ( LSV07A_Intern::trainings( $trainer_id, $von, $bis ) as $t ) {
            if ( isset( $schon[ $t['ref_typ'] . ':' . $t['ref_id'] ] ) ) continue;
            // Ohne Zeit gäbe es 0 € — bei Pauschale spielen Stunden keine Rolle.
            if ( ! empty( $t['zeit_fehlt'] ) && ! $pauschal ) { $ohne_zeit++; continue; }
            if ( self::training_anlegen( $abr, $t, 0, $person, $pausch, $cfg, $pers_p ) ) $angelegt++;
        }

        if ( $angelegt ) {
            LSV07A_Log::schreibe( 'training.automatisch', [
                'ziel_typ' => 'abrechnung', 'ziel_id' => (int) $abr['id'],
                'details'  => $angelegt . ' Training(s) automatisch übernommen' ] );
        }
        return [ $angelegt, $ohne_zeit ];
    }

    public static function wettkampf_angebot() {
        LSV07A_Access::check( 'trainer' );
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );
        [ $von, $bis ] = LSV07A_Berechnung::zeitraum( $abr['quartal'], $abr['jahr'] );
        $trainer_id = LSV07A_Intern::trainer_id( $abr['wp_user_id'] );
        wp_send_json_success( [
            'wettkaempfe' => LSV07A_Intern::wettkaempfe( $trainer_id, $von, $bis ),
            'satz'        => (float) LSV07A_DB::config( 'wk_satz', '30.00' ),
            'hinweis'     => LSV07A_Intern::hinweis(),
        ] );
    }

    /**
     * Einen Posten anlegen oder ändern. Der Betrag kommt NIE aus dem
     * Browser — er wird hier aus Menge und eingestelltem Satz gerechnet.
     */
    public static function posten_speichern() {
        LSV07A_Access::check( 'trainer', true );
        global $wpdb;
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );
        if ( ! LSV07A_Berechnung::offen( $abr['status'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist eingereicht und kann nicht mehr geändert werden.' ] );
        }

        $id    = absint( $_POST['id'] ?? 0 );
        $typ   = sanitize_text_field( $_POST['typ'] ?? '' );
        if ( ! in_array( $typ, LSV07A_DB::POSTEN_TYPEN, true ) ) {
            wp_send_json_error( [ 'message' => 'Unbekannte Art von Posten.' ] );
        }
        $datum = sanitize_text_field( $_POST['datum'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
            wp_send_json_error( [ 'message' => 'Bitte ein Datum angeben.' ] );
        }
        [ $von, $bis ] = LSV07A_Berechnung::zeitraum( $abr['quartal'], $abr['jahr'] );
        if ( $datum < $von || $datum > $bis ) {
            wp_send_json_error( [ 'message' => 'Das Datum liegt außerhalb von '
                . $abr['quartal'] . ' ' . $abr['jahr'] . ' (' . $von . ' bis ' . $bis . ').' ] );
        }

        $bezeichnung = sanitize_text_field( $_POST['bezeichnung'] ?? '' );
        $notiz       = sanitize_textarea_field( $_POST['notiz'] ?? '' );
        $menge       = (float) str_replace( ',', '.', (string) ( $_POST['menge'] ?? 0 ) );
        $tage        = max( 1, (int) ( $_POST['tage'] ?? 1 ) );
        $wartezeit   = ! empty( $_POST['wartezeit'] ) ? 1 : 0;
        $mannschaft  = absint( $_POST['mannschaft_id'] ?? 0 );

        $person = LSV07A_Person::holen( $abr['wp_user_id'] );
        $cfg    = LSV07A_DB::config_alle();
        $pausch = LSV07A_Berechnung::pauschalen();
        $pers_p = LSV07A_Berechnung::person_pauschalen();

        $warnung = '';
        switch ( $typ ) {
            case 'training':
                if ( $bezeichnung === '' ) $bezeichnung = 'Training';
                $werte = LSV07A_Berechnung::training( $person['abrechnungsart'], $menge,
                    $person['stundensatz'], $wartezeit, $mannschaft, $pausch, $cfg['wartezeit_min'],
                    LSV07A_Berechnung::wochentag( $datum ), $pers_p, (int) $abr['wp_user_id'] );
                break;
            case 'wettkampf':
                if ( $bezeichnung === '' ) wp_send_json_error( [ 'message' => 'Bitte den Wettkampf benennen.' ] );
                if ( $menge < 1 ) wp_send_json_error( [ 'message' => 'Bitte mindestens einen Abschnitt angeben.' ] );
                $werte = LSV07A_Berechnung::wettkampf( $menge, $cfg['wk_satz'] );
                break;
            case 'fahrt':
                if ( $bezeichnung === '' ) wp_send_json_error( [ 'message' => 'Bitte angeben, wohin die Fahrt ging.' ] );
                $werte = LSV07A_Berechnung::fahrt( $menge, $tage, $cfg['km_satz'],
                    $cfg['km_mindest'], ! empty( $cfg['km_hin_rueck'] ) );
                if ( ! empty( $werte['unter_mindest'] ) ) {
                    $warnung = 'Die einfache Strecke liegt nicht über '
                             . LSV07A_Berechnung::zahl_kurz( $cfg['km_mindest'] )
                             . ' km — dafür gibt es keine Fahrtkosten. Der Posten wird mit 0,00 € geführt.';
                }
                break;
            case 'vorbereitung':
                if ( $bezeichnung === '' ) wp_send_json_error( [ 'message' => 'Bitte einen Grund für die Vorbereitung angeben.' ] );
                if ( $menge <= 0 ) wp_send_json_error( [ 'message' => 'Bitte die Stunden angeben.' ] );
                $werte = LSV07A_Berechnung::vorbereitung( $menge, $person['stundensatz'] );
                break;
            default: // sonstiges
                if ( $bezeichnung === '' ) wp_send_json_error( [ 'message' => 'Bitte einen Grund angeben.' ] );
                $betrag = (float) str_replace( ',', '.', (string) ( $_POST['betrag'] ?? 0 ) );
                if ( $betrag <= 0 ) wp_send_json_error( [ 'message' => 'Bitte einen Betrag größer als 0 angeben.' ] );
                $werte = LSV07A_Berechnung::sonstiges( $betrag );
        }

        $daten = [
            'abrechnung_id' => (int) $abr['id'],
            'typ'           => $typ,
            'datum'         => $datum,
            'bezeichnung'   => $bezeichnung,
            'menge'         => $werte['menge'],
            'satz'          => $werte['satz'],
            'betrag'        => $werte['betrag'],
            'wartezeit'     => $wartezeit,
            'tage'          => $tage,
            'mannschaft_id' => $typ === 'training' ? $mannschaft : 0,
            'notiz'         => $notiz,
            'quelle'        => 'manuell',
            // ref_typ/ref_id bleiben leer (NULL): Von Hand erfasste Posten
            // haben keine Herkunft im internen Bereich, und nur so darf es
            // mehrere davon in einer Abrechnung geben (siehe class-db.php).
            'ref_typ'       => null,
            'ref_id'        => null,
        ];
        $formate = [ '%d','%s','%s','%s','%f','%f','%f','%d','%d','%d','%s','%s','%s','%d' ];

        if ( $id ) {
            $gehoert = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT abrechnung_id FROM " . self::tbl( 'lsv07a_posten' ) . " WHERE id = %d", $id ) );
            if ( $gehoert !== (int) $abr['id'] ) {
                wp_send_json_error( [ 'message' => 'Dieser Posten gehört zu einer anderen Abrechnung.' ] );
            }
            unset( $daten['abrechnung_id'], $daten['quelle'], $daten['ref_typ'], $daten['ref_id'] );
            $geschrieben = $wpdb->update( self::tbl( 'lsv07a_posten' ), $daten, [ 'id' => $id ], null, [ '%d' ] );
            if ( $geschrieben === false ) {
                wp_send_json_error( [ 'message' => 'Der Posten konnte nicht gespeichert werden: '
                    . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' ) ] );
            }
        } else {
            if ( $wpdb->insert( self::tbl( 'lsv07a_posten' ), $daten, $formate ) === false ) {
                wp_send_json_error( [ 'message' => 'Der Posten konnte nicht angelegt werden: '
                    . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' ) ] );
            }
            $id = (int) $wpdb->insert_id;
        }

        wp_send_json_success( [
            'id'      => $id,
            'betrag'  => $werte['betrag'],
            'warnung' => $warnung,
            'message' => 'Gespeichert.',
        ] );
    }

    public static function posten_loeschen() {
        LSV07A_Access::check( 'trainer', true );
        global $wpdb;
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );
        if ( ! LSV07A_Berechnung::offen( $abr['status'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung ist eingereicht und kann nicht mehr geändert werden.' ] );
        }
        $id = absint( $_POST['id'] ?? 0 );

        /* Vorher nachsehen, woher der Posten kam: Stammt er aus dem internen
           Bereich, wird vermerkt, dass die Automatik ihn nicht wieder holen
           soll — sonst stünde er beim nächsten Öffnen wieder da und liesse
           sich nie entfernen. */
        $posten = $wpdb->get_row( $wpdb->prepare(
            "SELECT ref_typ, ref_id FROM " . self::tbl( 'lsv07a_posten' ) . "
              WHERE id = %d AND abrechnung_id = %d", $id, (int) $abr['id'] ), ARRAY_A );

        $geloescht = $wpdb->delete( self::tbl( 'lsv07a_posten' ),
            [ 'id' => $id, 'abrechnung_id' => (int) $abr['id'] ], [ '%d', '%d' ] );
        if ( ! $geloescht ) wp_send_json_error( [ 'message' => 'Der Posten wurde nicht gefunden.' ] );

        if ( $posten && ! empty( $posten['ref_typ'] ) && ! empty( $posten['ref_id'] ) ) {
            $wpdb->suppress_errors( true );
            $wpdb->insert( self::tbl( 'lsv07a_nicht_auto' ), [
                'abrechnung_id' => (int) $abr['id'],
                'ref_typ'       => $posten['ref_typ'],
                'ref_id'        => (int) $posten['ref_id'],
            ], [ '%d', '%s', '%d' ] );
            $wpdb->suppress_errors( false );
        }
        wp_send_json_success( [ 'message' => 'Posten entfernt.' ] );
    }

    public static function einreichen() {
        LSV07A_Access::check( 'trainer', true );
        global $wpdb;
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );
        if ( ! LSV07A_Berechnung::offen( $abr['status'] ) ) {
            wp_send_json_error( [ 'message' => 'Diese Abrechnung wurde bereits eingereicht.' ] );
        }
        $rechnung = LSV07A_Berechnung::summe( (int) $abr['id'] );
        if ( $rechnung['anzahl'] < 1 ) {
            wp_send_json_error( [ 'message' => 'Die Abrechnung ist leer — bitte zuerst Posten erfassen.' ] );
        }
        $person = LSV07A_Person::holen( $abr['wp_user_id'] );
        if ( trim( $person['iban'] ) === '' || trim( $person['kontoinhaber'] ) === '' ) {
            wp_send_json_error( [ 'message' => 'Bitte zuerst die Zahlungsdaten hinterlegen (Kontoinhaber und IBAN) — '
                . 'ohne sie kann die Kasse nicht auszahlen.' ] );
        }

        $kommentar = sanitize_textarea_field( $_POST['kommentar'] ?? '' );
        $geschrieben = $wpdb->update( self::tbl( 'lsv07a_abrechnung' ), [
            'status'          => 'eingereicht',
            'eingereicht_am'  => current_time( 'mysql' ),
            'kommentar'       => $kommentar,
            'rueckgabe_grund' => '',
            'stundensatz'     => $person['stundensatz'],
            'abrechnungsart'  => $person['abrechnungsart'],
        ], [ 'id' => (int) $abr['id'] ], [ '%s','%s','%s','%s','%f','%s' ], [ '%d' ] );
        if ( $geschrieben === false ) {
            wp_send_json_error( [ 'message' => 'Das Einreichen ist fehlgeschlagen: '
                . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' ) ] );
        }

        LSV07A_Log::schreibe( 'abrechnung.eingereicht', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => (int) $abr['id'],
            'details'  => $abr['quartal'] . ' ' . $abr['jahr'] . ', ' . number_format( $rechnung['gesamt'], 2, ',', '.' ) . ' EUR' ] );

        /* Mit dem erneuten Einreichen sind die Beanstandungen erledigt:
           Entweder wurde nachgebessert, oder der Wart beanstandet wieder.
           Stehen zu lassen hiesse, sie auf eine Fassung zu beziehen, die
           es nicht mehr gibt. */
        LSV07A_Ajax_Notiz::zuruecksetzen( (int) $abr['id'] );

        LSV07A_Nachricht::eingereicht( $abr );
        wp_send_json_success( [ 'message' => 'Abrechnung eingereicht. Der Wart prüft sie jetzt.' ] );
    }

    /** Solange niemand geprüft hat, darf man sie zurückholen. */
    /**
     * Ein Nachtrag zu einer abgeschlossenen Abrechnung.
     *
     * Ein vergessener Posten nach der Auszahlung lässt sich nicht
     * nachtragen, ohne eine bezahlte Abrechnung wieder aufzureißen — und
     * das würde eine Buchung ändern, die längst im Kontoauszug steht.
     * Stattdessen entsteht eine ZWEITE Abrechnung für dasselbe Quartal,
     * die auf die erste verweist und denselben Weg geht: einreichen,
     * prüfen, auszahlen.
     *
     * Sie beginnt leer. Was fehlt, weiß nur die Person.
     */
    public static function nachtrag() {
        LSV07A_Access::check( 'trainer', true );
        global $wpdb;
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );

        if ( ! in_array( $abr['status'], [ 'genehmigt', 'bezahlt' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Ein Nachtrag lohnt sich erst, wenn die Abrechnung '
                . 'genehmigt oder bezahlt ist. Solange sie offen ist, tragen Sie den Posten '
                . 'einfach dort nach.' ] );
        }
        /* Nur am Ende der Kette: Hängt an dieser Abrechnung schon ein
           Nachtrag, gehört der neue an dessen Ende — sonst stolpert der
           eindeutige Schlüssel, und es entstünde ein zweiter Zweig. */
        $schon = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM " . self::tbl( 'lsv07a_abrechnung' ) . "
              WHERE nachtrag_zu = %d", (int) $abr['id'] ), ARRAY_A );
        if ( $schon ) {
            wp_send_json_error( [ 'message' => 'Zu dieser Abrechnung gibt es bereits einen Nachtrag ('
                . LSV07A_Berechnung::status_name( $schon['status'] ) . '). '
                . 'Tragen Sie dort nach oder reichen Sie ihn zuerst ein.' ] );
        }

        $person = LSV07A_Person::holen( $abr['wp_user_id'] );
        $ok = $wpdb->insert( self::tbl( 'lsv07a_abrechnung' ), [
            'wp_user_id'     => (int) $abr['wp_user_id'],
            'quartal'        => $abr['quartal'],
            'jahr'           => (int) $abr['jahr'],
            'status'         => 'entwurf',
            'stundensatz'    => $person['stundensatz'],
            'abrechnungsart' => $person['abrechnungsart'],
            'nachtrag_zu'    => (int) $abr['id'],
        ], [ '%d', '%s', '%d', '%s', '%f', '%s', '%d' ] );
        if ( $ok === false ) {
            wp_send_json_error( [ 'message' => 'Der Nachtrag konnte nicht angelegt werden: '
                . ( $wpdb->last_error ?: 'unbekannter Datenbankfehler' ) ] );
        }
        $neu = (int) $wpdb->insert_id;
        LSV07A_Log::schreibe( 'abrechnung.nachtrag', [
            'ziel_typ' => 'abrechnung', 'ziel_id' => $neu,
            'details'  => 'Nachtrag zu ' . $abr['quartal'] . ' ' . $abr['jahr']
                        . ' (Abrechnung ' . (int) $abr['id'] . ')' ] );
        wp_send_json_success( [
            'message' => 'Nachtrag angelegt. Er ist leer — tragen Sie nur ein, was gefehlt hat.',
            'id'      => $neu,
            'quartal' => $abr['quartal'], 'jahr' => (int) $abr['jahr'] ] );
    }

    public static function zurueckziehen() {
        LSV07A_Access::check( 'trainer', true );
        global $wpdb;
        $abr = self::eigene_abrechnung( $_POST['abrechnung_id'] ?? 0 );
        if ( $abr['status'] !== 'eingereicht' ) {
            wp_send_json_error( [ 'message' => 'Zurückholen geht nur, solange die Abrechnung eingereicht und noch nicht entschieden ist.' ] );
        }
        $wpdb->update( self::tbl( 'lsv07a_abrechnung' ),
            [ 'status' => 'entwurf', 'eingereicht_am' => null ],
            [ 'id' => (int) $abr['id'] ], [ '%s', '%s' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'abrechnung.zurueckgezogen', [ 'ziel_typ' => 'abrechnung', 'ziel_id' => (int) $abr['id'] ] );
        wp_send_json_success( [ 'message' => 'Abrechnung zurückgeholt — Sie können sie wieder bearbeiten.' ] );
    }

    // ── Zahlungsdaten ────────────────────────────────────────────────────

    public static function zahlungsdaten_get() {
        LSV07A_Access::check( 'trainer' );
        $uid = get_current_user_id();
        LSV07A_Person::sicherstellen( $uid );
        $p = LSV07A_Person::holen( $uid );
        wp_send_json_success( [
            'iban' => $p['iban'], 'bic' => $p['bic'], 'kontoinhaber' => $p['kontoinhaber'],
            'strasse' => $p['strasse'], 'plz' => $p['plz'], 'ort' => $p['ort'],
            'stundensatz' => (float) $p['stundensatz'],
            'abrechnungsart' => $p['abrechnungsart'],
            'art_name' => LSV07A_Berechnung::art_name( $p['abrechnungsart'] ),
            'auto_training' => (int) $p['auto_training'],
        ] );
    }

    public static function zahlungsdaten_save() {
        LSV07A_Access::check( 'trainer', true );
        $uid  = get_current_user_id();
        $iban = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( $_POST['iban'] ?? '' ) ) );
        if ( $iban !== '' && ! preg_match( '/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban ) ) {
            wp_send_json_error( [ 'message' => 'Diese IBAN sieht nicht richtig aus. Erwartet wird z. B. DE12 3456 7890 1234 5678 90.' ] );
        }
        $ok = LSV07A_Person::speichern( $uid, [
            'iban'         => $iban,
            'bic'          => strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( $_POST['bic'] ?? '' ) ) ),
            'kontoinhaber' => sanitize_text_field( $_POST['kontoinhaber'] ?? '' ),
            'strasse'      => sanitize_text_field( $_POST['strasse'] ?? '' ),
            'plz'          => sanitize_text_field( $_POST['plz'] ?? '' ),
            'ort'          => sanitize_text_field( $_POST['ort'] ?? '' ),
        ] );
        if ( ! $ok ) wp_send_json_error( [ 'message' => 'Die Zahlungsdaten konnten nicht gespeichert werden.' ] );
        LSV07A_Log::schreibe( 'zahlungsdaten.geaendert', [ 'ziel_typ' => 'person', 'ziel_id' => $uid ] );
        wp_send_json_success( [ 'message' => 'Zahlungsdaten gespeichert.' ] );
    }

    /** Alle eigenen Abrechnungen als Überblick. */
    public static function meine_liste() {
        LSV07A_Access::check( 'trainer' );
        global $wpdb;
        $uid = get_current_user_id();
        $zeilen = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.quartal, a.jahr, a.status, a.eingereicht_am, a.genehmigt_am, a.bezahlt_am,
                    a.nachtrag_zu,
                    COALESCE(SUM(p.betrag), 0) AS gesamt, COUNT(p.id) AS posten
               FROM " . self::tbl( 'lsv07a_abrechnung' ) . " a
          LEFT JOIN " . self::tbl( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
              WHERE a.wp_user_id = %d
           GROUP BY a.id
           ORDER BY a.jahr DESC, a.quartal DESC", $uid ), ARRAY_A ) ?: [];
        foreach ( $zeilen as &$z ) {
            $z['gesamt']      = (float) $z['gesamt'];
            $z['posten']      = (int) $z['posten'];
            $z['nachtrag_zu'] = (int) $z['nachtrag_zu'];
            $z['status_name'] = LSV07A_Berechnung::status_name( $z['status'] );
        }
        wp_send_json_success( $zeilen );
    }
}
