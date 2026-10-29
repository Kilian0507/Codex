<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Nachrichten: Konversationen zwischen Konten.
 *
 * Drei Arten: `direkt` (zwei Konten), `gruppe` (mehrere) und
 * `verteiler` (an eine Mannschaft gebunden — wer dort Trainer ist,
 * gehört dazu). Dieselben Tabellen wie im internen Bereich, dieselben
 * Rechte.
 *
 * Was hier anders ist:
 *
 * 1. **Stummgeschaltet heisst stumm.** Im internen Bereich schaltet
 *    `stumm` die Mail ab, aber die Konversation springt beim Zählen der
 *    ungelesenen trotzdem nach oben — man wird also weiter erinnert.
 *    Hier zählt eine stumme Konversation nicht in die Zahl im Kopf; sie
 *    steht in der Liste, mit ihrem eigenen Vermerk.
 * 2. **Eine gelöschte Nachricht bleibt sichtbar als „gelöscht".** Dort
 *    verschwindet sie aus der Liste, und in einer Unterhaltung mit
 *    Lücken weiss niemand mehr, worauf eine Antwort sich bezog. Der
 *    Text ist weg, die Spur bleibt.
 * 3. **Ändern und Löschen haben eine Frist.** Eine Nachricht von
 *    letztem Jahr nachträglich umzuschreiben ist keine Korrektur. Nach
 *    24 Stunden geht es nicht mehr; was dann falsch steht, wird
 *    richtiggestellt, nicht ersetzt.
 * 4. **Jede Änderung steht im Protokoll** — dort keine.
 *
 * Was NICHT gebaut ist und im internen Bereich bleibt: Reaktionen
 * („Daumen hoch"), die Anzeige „schreibt gerade", der Online-Punkt und
 * die Zustellung an externe Mailadressen. Das sind Bequemlichkeiten, die
 * ohne eigenen Serverkanal nur über ständiges Nachfragen gehen — und das
 * ist in einem Verein mit zwanzig Konten viel Last für wenig.
 */
class LSV07S_Reden {

    /** Wie lange eine Nachricht geändert oder gelöscht werden darf. */
    const FRIST_STUNDEN = 24;

    /** Was ein Anhang sein darf. */
    const ANHANG_TYPEN = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
    ];
    const ANHANG_MAX = 10485760;   // 10 MB

    /** Für Prüfungen: die Prüfung auf einen echten Upload umgehen. */
    public static $pruefe_upload = null;

    // ── Schalter ─────────────────────────────────────────────────────────

    /**
     * Ein Schalter für die Nachrichten.
     *
     * Lesen, Schreiben, eine Gruppe anlegen, an eine ganze Mannschaft
     * schreiben, eine Datei anhängen — das ist EIN Häkchen. Vorher
     * waren es fünf Rechte; wer nur lesen durfte, schrieb nicht. Jetzt
     * entscheidet der Schalter, und die Grenzen, die bleiben, sind
     * andere: die eigene Mannschaft (für einen Verteiler), die eigene
     * Unterhaltung (zum Mitlesen) und die eigene Nachricht (zum
     * Ändern).
     */
    private static function darf_reden() {
        return LSV07S_Schalter::an( 'reden/nachrichten' );
    }

    /** Gehört dieses Konto zu dieser Konversation? */
    private static function teilnehmer( $konv_id, $uid = 0 ) {
        global $wpdb;
        $uid = (int) ( $uid ?: get_current_user_id() );
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'konv_teil' )
            . " WHERE konv_id = %d AND teilnehmer_typ = 'user' AND teilnehmer_id = %d"
            . ' AND verlassen_am IS NULL LIMIT 1',
            (int) $konv_id, $uid ), ARRAY_A );
    }

    /**
     * Darf dieses Konto hier mitlesen?
     *
     * Teilnehmer ja. Ein Administrator auch — aber das steht dann im
     * Protokoll, wenn er eine Konversation öffnet, in der er nicht
     * Teilnehmer ist. Eine Unterhaltung zweier Trainer ist nichts, was
     * man unbemerkt mitlesen sollte.
     */
    private static function darf_sehen( $konv_id, &$fremd = false ) {
        if ( self::teilnehmer( $konv_id ) ) { $fremd = false; return true; }
        if ( LSV07S_Rechte::ist_admin() ) { $fremd = true; return true; }
        return false;
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /**
     * Meine Konversationen, die neueste zuerst.
     *
     * Die Zahl der ungelesenen zählt stumme NICHT mit — sonst ist die
     * Zahl im Kopf eine Erinnerung an etwas, das man ausdrücklich
     * stummgeschaltet hat.
     */
    public static function liste() {
        global $wpdb;
        if ( ! self::darf_reden() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! LSV07S_Tabellen::da( 'konv' ) ) return [];
        $uid = (int) get_current_user_id();

        $k  = LSV07S_Tabellen::t( 'konv' );
        $tt = LSV07S_Tabellen::t( 'konv_teil' );
        $m  = LSV07S_Tabellen::t( 'konv_msg' );

        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT k.*, t.gelesen_bis, t.stumm, t.ist_admin,"
            . " ( SELECT COUNT(*) FROM $m WHERE konv_id = k.id AND id > t.gelesen_bis"
            . '   AND geloescht_am IS NULL ) AS ungelesen'
            . " FROM $k k JOIN $tt t ON t.konv_id = k.id"
            . "   AND t.teilnehmer_typ = 'user' AND t.teilnehmer_id = %d"
            . '   AND t.verlassen_am IS NULL'
            . ' WHERE k.geschlossen = 0'
            . ' ORDER BY k.letzte_aktivitaet DESC, k.id DESC',
            $uid ), ARRAY_A );

        $raus = [];
        $ungelesen_laut = 0;
        foreach ( $zeilen as $z ) {
            $id = (int) $z['id'];
            $stumm = (int) $z['stumm'] === 1;
            $ungelesen = (int) $z['ungelesen'];
            if ( ! $stumm ) $ungelesen_laut += $ungelesen;

            $letzte = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $m WHERE konv_id = %d AND geloescht_am IS NULL"
                . ' ORDER BY id DESC LIMIT 1', $id ), ARRAY_A );

            $raus[] = [
                'id'        => $id,
                'typ'       => (string) $z['typ'],
                'name'      => self::anzeige_name( $z, $uid ),
                'mannschaft_id' => (int) ( $z['mannschaft_id'] ?? 0 ),
                'teilnehmer'=> self::teilnehmer_liste( $id ),
                'ungelesen' => $ungelesen,
                'stumm'     => $stumm,
                'ist_admin' => (int) $z['ist_admin'] === 1,
                'letzte'    => $letzte ? [
                    'id'   => (int) $letzte['id'],
                    'von'  => self::absender_name( $letzte ),
                    'text' => mb_substr( preg_replace( '/\s+/u', ' ',
                                (string) $letzte['text'] ), 0, 80 ),
                    'am'   => LSV07S_Datum::de_zeit( $letzte['erstellt_am'] ),
                ] : null,
                'aktiv_am'  => LSV07S_Datum::de_zeit( $z['letzte_aktivitaet'] ?? null, '' ),
            ];
        }
        return [
            'konversationen' => $raus,
            'ungelesen' => $ungelesen_laut,
            'darf_senden' => self::darf_reden(),
        ];
    }

    /** Der Name, unter dem eine Konversation in der Liste steht. */
    private static function anzeige_name( $z, $uid ) {
        if ( (string) ( $z['name'] ?? '' ) !== '' ) return (string) $z['name'];
        if ( (string) $z['typ'] === 'direkt' ) {
            /* Eine Unterhaltung zu zweit heisst nach der anderen Person —
               „Direkt" wäre bei zwanzig davon keine Hilfe. */
            foreach ( self::teilnehmer_liste( (int) $z['id'] ) as $t ) {
                if ( (int) $t['user_id'] !== (int) $uid ) return $t['name'];
            }
            return 'Unterhaltung';
        }
        if ( (int) ( $z['mannschaft_id'] ?? 0 ) > 0 ) {
            $m = LSV07S_Mannschaft::holen(
                (string) ( $z['mannschaft_sparte'] ?: LSV07S_Sparte::SCHWIMMEN ),
                (int) $z['mannschaft_id'] );
            if ( $m ) return $m['name'];
        }
        return 'Gruppe';
    }

    /** Die aktiven Teilnehmer einer Konversation. */
    public static function teilnehmer_liste( $konv_id ) {
        global $wpdb;
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'konv_teil' )
            . ' WHERE konv_id = %d AND verlassen_am IS NULL'
            . ' ORDER BY ist_admin DESC, hinzugefuegt_am ASC, id ASC',
            (int) $konv_id ), ARRAY_A );
        return array_map( fn( $t ) => [
            'id'      => (int) $t['id'],
            'typ'     => (string) $t['teilnehmer_typ'],
            'user_id' => (int) $t['teilnehmer_id'],
            'name'    => (string) ( $t['teilnehmer_name'] !== null
                            && $t['teilnehmer_name'] !== ''
                          ? $t['teilnehmer_name']
                          : LSV07S_Person::konto_name( $t['teilnehmer_id'] ) ),
            'ist_admin' => (int) $t['ist_admin'] === 1,
        ], $zeilen );
    }

    private static function absender_name( $msg ) {
        if ( (string) $msg['von_typ'] === 'system' ) return 'System';
        if ( (string) ( $msg['von_name'] ?? '' ) !== '' ) return (string) $msg['von_name'];
        return LSV07S_Person::konto_name( $msg['von_id'] );
    }

    /**
     * Die Nachrichten einer Konversation.
     *
     * Eine gelöschte Nachricht bleibt als Lücke stehen, mit dem Vermerk,
     * dass sie gelöscht wurde — ohne Text.
     */
    public static function nachrichten( $konv_id, $ab = 0, $zahl = 50 ) {
        global $wpdb;
        $konv_id = (int) $konv_id;
        if ( ! self::darf_reden() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        $fremd = false;
        if ( ! self::darf_sehen( $konv_id, $fremd ) ) {
            return 'Diese Unterhaltung gehört nicht zu Ihnen.';
        }
        $konv = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'konv' ) . ' WHERE id = %d LIMIT 1',
            $konv_id ), ARRAY_A );
        if ( ! $konv ) return 'Diese Unterhaltung gibt es nicht.';

        $zahl = max( 1, min( 200, (int) $zahl ) );
        $uid  = (int) get_current_user_id();
        $m    = LSV07S_Tabellen::t( 'konv_msg' );

        $sql = "SELECT * FROM $m WHERE konv_id = %d";
        $werte = [ $konv_id ];
        if ( $ab > 0 ) { $sql .= ' AND id > %d'; $werte[] = (int) $ab; }
        $sql .= ' ORDER BY id ASC LIMIT ' . $zahl;
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$werte ), ARRAY_A );

        $teil = self::teilnehmer( $konv_id );
        $raus = [];
        foreach ( $zeilen as $z ) {
            $weg = $z['geloescht_am'] !== null;
            $raus[] = [
                'id'        => (int) $z['id'],
                'von_id'    => (int) $z['von_id'],
                'von'       => self::absender_name( $z ),
                'eigene'    => (string) $z['von_typ'] === 'user'
                               && (int) $z['von_id'] === $uid,
                'system'    => (string) $z['von_typ'] === 'system',
                'text'      => $weg ? '' : (string) $z['text'],
                'geloescht' => $weg,
                'bearbeitet'=> $z['bearbeitet_am'] !== null,
                'dringend'  => (int) $z['dringend'] === 1,
                'am'        => LSV07S_Datum::de_zeit( $z['erstellt_am'] ),
                'anhaenge'  => self::anhaenge( (int) $z['id'] ),
                'darf_aendern' => ! $weg && (string) $z['von_typ'] === 'user'
                                  && (int) $z['von_id'] === $uid
                                  && self::in_frist( $z['erstellt_am'] ),
            ];
        }

        /* Ein Administrator, der eine fremde Unterhaltung öffnet, steht
           im Protokoll. Mitlesen ist nicht verboten, aber es bleibt
           nachvollziehbar. */
        if ( $fremd ) {
            LSV07S_Log::schreibe( 'nachrichten.fremd_gelesen', [
                'bereich' => 'reden', 'ziel_typ' => 'konversation', 'ziel_id' => $konv_id,
                'details' => 'Als Administration geöffnet, ohne Teilnehmer zu sein',
            ] );
        }

        return [
            'id'   => $konv_id,
            'typ'  => (string) $konv['typ'],
            'name' => self::anzeige_name( $konv, $uid ),
            'teilnehmer' => self::teilnehmer_liste( $konv_id ),
            'nachrichten' => $raus,
            'stumm' => $teil ? (int) $teil['stumm'] === 1 : false,
            'gelesen_bis' => $teil ? (int) $teil['gelesen_bis'] : 0,
            'ist_admin' => $teil ? (int) $teil['ist_admin'] === 1 : false,
            'nur_lesen' => $fremd,
            'darf_senden' => ! $fremd && self::darf_reden(),
            'frist_stunden' => self::FRIST_STUNDEN,
        ];
    }

    /** Liegt dieser Zeitpunkt noch in der Änderungsfrist? */
    private static function in_frist( $zeitpunkt ) {
        $ts = strtotime( (string) $zeitpunkt );
        if ( ! $ts ) return false;
        $jetzt = strtotime( LSV07S_Datum::jetzt() );
        return ( $jetzt - $ts ) <= self::FRIST_STUNDEN * 3600;
    }

    /** Die Zahl der ungelesenen Nachrichten — ohne die stummen. */
    public static function ungelesen( $uid = 0 ) {
        global $wpdb;
        $uid = (int) ( $uid ?: get_current_user_id() );
        if ( ! $uid || ! LSV07S_Tabellen::da( 'konv' ) ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . LSV07S_Tabellen::t( 'konv_msg' ) . ' m'
            . ' JOIN ' . LSV07S_Tabellen::t( 'konv_teil' ) . ' t ON t.konv_id = m.konv_id'
            . ' JOIN ' . LSV07S_Tabellen::t( 'konv' ) . ' k ON k.id = m.konv_id'
            . " WHERE t.teilnehmer_typ = 'user' AND t.teilnehmer_id = %d"
            . ' AND t.verlassen_am IS NULL AND t.stumm = 0 AND k.geschlossen = 0'
            . ' AND m.id > t.gelesen_bis AND m.geloescht_am IS NULL'
            . " AND NOT ( m.von_typ = 'user' AND m.von_id = %d )",
            $uid, $uid ) );
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Eine Konversation anlegen.
     *
     * `$typ`: direkt, gruppe oder verteiler. Bei `verteiler` gehört die
     * Mannschaft dazu, und die Teilnehmer sind ihre Trainer.
     *
     * Eine direkte Unterhaltung mit derselben Person gibt es genau
     * einmal: Ein zweiter Anlauf findet die vorhandene. Im internen
     * Bereich entstehen zwei, und die Antwort landet in der falschen.
     */
    public static function anlegen( $typ, array $konten, $name = '',
                                    $mannschaft_id = 0, $sparte = '' ) {
        global $wpdb;
        $typ = sanitize_key( $typ );
        if ( ! self::darf_reden() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! LSV07S_Tabellen::da( 'konv' ) ) return 'Die Tabelle fehlt.';
        if ( ! in_array( $typ, [ 'direkt', 'gruppe', 'verteiler' ], true ) ) {
            return 'Diese Art von Unterhaltung gibt es nicht.';
        }
        /* Gruppe und Verteiler brauchen kein eigenes Häkchen mehr: Was
           einen Verteiler begrenzt, ist die Mannschaft (weiter unten),
           nicht ein zweiter Schalter. */
        $uid = (int) get_current_user_id();
        $name = mb_substr( trim( sanitize_text_field( (string) $name ) ), 0, 200 );

        $konten = array_values( array_unique( array_filter( array_map( 'absint', $konten ) ) ) );

        if ( $typ === 'verteiler' ) {
            $sparte = $sparte ?: LSV07S_Sparte::SCHWIMMEN;
            $mannschaft_id = (int) $mannschaft_id;
            if ( ! LSV07S_Rechte::darf_mannschaft( $sparte, $mannschaft_id ) ) {
                return 'Für diese Mannschaft fehlt Ihnen die Berechtigung.';
            }
            $konten = self::trainer_konten( $sparte, $mannschaft_id );
            if ( ! in_array( $uid, $konten, true ) ) $konten[] = $uid;
        } else {
            $mannschaft_id = 0;
            $sparte = '';
            if ( ! in_array( $uid, $konten, true ) ) $konten[] = $uid;
            if ( $typ === 'direkt' && count( $konten ) !== 2 ) {
                return 'Eine direkte Unterhaltung hat genau zwei Teilnehmer.';
            }
            if ( count( $konten ) < 2 ) return 'Bitte mindestens eine weitere Person wählen.';
            if ( count( $konten ) > 50 ) return 'Mehr als 50 Teilnehmer sind nicht vorgesehen.';
        }

        /* Nur Konten, die Nachrichten lesen dürfen — sonst steht jemand
           in einer Unterhaltung, die er nie sieht. */
        foreach ( $konten as $k ) {
            if ( $k === $uid ) continue;
            if ( ! LSV07S_Schalter::zugang( $k ) ) {
                return 'Mindestens ein gewähltes Konto hat keinen Zugang.';
            }
        }

        if ( $typ === 'direkt' ) {
            $andere = array_values( array_diff( $konten, [ $uid ] ) )[0] ?? 0;
            $da = self::direkte_finden( $uid, (int) $andere );
            if ( $da ) return [ 'id' => $da, 'neu' => false,
                                'message' => 'Diese Unterhaltung gibt es schon.' ];
        }

        $wpdb->insert( LSV07S_Tabellen::t( 'konv' ), [
            'typ' => $typ,
            'name' => $name !== '' ? $name : null,
            'mannschaft_id' => $mannschaft_id ?: null,
            'mannschaft_sparte' => $sparte !== '' ? $sparte : null,
            'erstellt_von' => $uid,
            'erstellt_am' => LSV07S_Datum::jetzt(),
            'letzte_aktivitaet' => LSV07S_Datum::jetzt(),
        ], [ '%s', '%s', '%d', '%s', '%d', '%s', '%s' ] );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 'Die Unterhaltung konnte nicht angelegt werden.';

        foreach ( $konten as $k ) {
            self::teilnehmer_setzen( $id, (int) $k, $k === $uid );
        }

        LSV07S_Log::schreibe( 'nachrichten.angelegt', [
            'bereich' => 'reden', 'ziel_typ' => 'konversation', 'ziel_id' => $id,
            'ziel_name' => $name,
            'details' => $typ . ', ' . count( $konten ) . ' Teilnehmer',
        ] );
        return [ 'id' => $id, 'neu' => true, 'message' => 'Angelegt.' ];
    }

    /** Die WordPress-Konten der Trainer einer Mannschaft. */
    private static function trainer_konten( $sparte, $mannschaft_id ) {
        global $wpdb;
        $t  = LSV07S_Sparte::tabelle( $sparte, 't_trainer' );
        $tm = LSV07S_Sparte::tabelle( $sparte, 't_tr_gruppe' );
        if ( ! $t || ! $tm ) return [];
        $spalte = LSV07S_Sparte::feld( $sparte, 'gruppe_spalte' );
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT tr.wp_user_id FROM $t tr JOIN $tm tm ON tm.trainer_id = tr.id"
            . " WHERE tm.$spalte = %d AND tr.aktiv = 1 AND tr.wp_user_id > 0",
            (int) $mannschaft_id ) ) );
    }

    /** Gibt es die direkte Unterhaltung dieser beiden Konten schon? */
    private static function direkte_finden( $a, $b ) {
        global $wpdb;
        $tt = LSV07S_Tabellen::t( 'konv_teil' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT k.id FROM ' . LSV07S_Tabellen::t( 'konv' ) . ' k'
            . " JOIN $tt a ON a.konv_id = k.id AND a.teilnehmer_typ = 'user'"
            . '   AND a.teilnehmer_id = %d AND a.verlassen_am IS NULL'
            . " JOIN $tt b ON b.konv_id = k.id AND b.teilnehmer_typ = 'user'"
            . '   AND b.teilnehmer_id = %d AND b.verlassen_am IS NULL'
            . " WHERE k.typ = 'direkt' AND k.geschlossen = 0 LIMIT 1",
            (int) $a, (int) $b ) );
    }

    /** Ein Konto in die Konversation setzen (oder zurückholen). */
    private static function teilnehmer_setzen( $konv_id, $uid, $admin = false ) {
        global $wpdb;
        $tt = LSV07S_Tabellen::t( 'konv_teil' );
        $da = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $tt WHERE konv_id = %d AND teilnehmer_typ = 'user'"
            . ' AND teilnehmer_id = %d LIMIT 1', (int) $konv_id, (int) $uid ), ARRAY_A );
        if ( $da ) {
            $wpdb->update( $tt, [ 'verlassen_am' => null ],
                [ 'id' => (int) $da['id'] ], [ '%s' ], [ '%d' ] );
            return (int) $da['id'];
        }
        $wpdb->insert( $tt, [
            'konv_id' => (int) $konv_id,
            'teilnehmer_typ' => 'user',
            'teilnehmer_id' => (int) $uid,
            'teilnehmer_name' => LSV07S_Person::konto_name( $uid ),
            'ist_admin' => $admin ? 1 : 0,
            'gelesen_bis' => 0,
            'hinzugefuegt_am' => LSV07S_Datum::jetzt(),
            'hinzugefuegt_von' => (int) get_current_user_id(),
        ], [ '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%d' ] );
        return (int) $wpdb->insert_id;
    }

    /** Eine Nachricht senden. */
    public static function senden( $konv_id, $text, $dringend = false ) {
        global $wpdb;
        $konv_id = (int) $konv_id;
        if ( ! self::darf_reden() ) return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        if ( ! self::teilnehmer( $konv_id ) ) {
            return 'Sie gehören nicht zu dieser Unterhaltung.';
        }
        $konv = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'konv' ) . ' WHERE id = %d LIMIT 1',
            $konv_id ), ARRAY_A );
        if ( ! $konv ) return 'Diese Unterhaltung gibt es nicht.';
        if ( (int) $konv['geschlossen'] === 1 ) return 'Diese Unterhaltung ist geschlossen.';

        $text = trim( (string) $text );
        if ( $text === '' ) return 'Bitte etwas schreiben.';
        if ( mb_strlen( $text ) > 10000 ) return 'Die Nachricht ist zu lang.';
        /* Der Text wird NICHT durch sanitize_text_field geschickt: Das
           würde Zeilenumbrüche verschlucken. Entfernt werden Auszeichnung
           und Skripte; beim Ausgeben wird escaped. */
        $text = wp_kses( $text, [] );

        $wpdb->insert( LSV07S_Tabellen::t( 'konv_msg' ), [
            'konv_id' => $konv_id,
            'von_typ' => 'user',
            'von_id' => (int) get_current_user_id(),
            'von_name' => LSV07S_Person::konto_name( get_current_user_id() ),
            'text' => $text,
            'dringend' => $dringend ? 1 : 0,
            'erstellt_am' => LSV07S_Datum::jetzt(),
        ], [ '%d', '%s', '%d', '%s', '%s', '%d', '%s' ] );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 'Die Nachricht konnte nicht gespeichert werden.';

        $wpdb->update( LSV07S_Tabellen::t( 'konv' ),
            [ 'letzte_aktivitaet' => LSV07S_Datum::jetzt() ],
            [ 'id' => $konv_id ], [ '%s' ], [ '%d' ] );

        /* Wer schreibt, hat gelesen: Der Lesestand wandert auf die eigene
           Nachricht. Die Zahl oben zählt eigene Nachrichten ohnehin nicht
           mit; ohne diese Zeile bliebe aber alles davor ungelesen, und die
           Unterhaltung stünde nach der eigenen Antwort weiter fett in der
           Liste. */
        self::gelesen_bis( $konv_id, $id );

        return [ 'id' => $id, 'message' => 'Gesendet.' ];
    }

    /** Bis zu dieser Nachricht gelesen. */
    public static function gelesen_bis( $konv_id, $msg_id ) {
        global $wpdb;
        $teil = self::teilnehmer( $konv_id );
        if ( ! $teil ) return 'Sie gehören nicht zu dieser Unterhaltung.';
        $msg_id = (int) $msg_id;
        /* Nur vorwärts: Ein später geladener älterer Abschnitt darf den
           Stand nicht zurückdrehen. */
        if ( $msg_id <= (int) $teil['gelesen_bis'] ) return true;
        $wpdb->update( LSV07S_Tabellen::t( 'konv_teil' ),
            [ 'gelesen_bis' => $msg_id ], [ 'id' => (int) $teil['id'] ],
            [ '%d' ], [ '%d' ] );
        return true;
    }

    /** Stumm schalten oder wieder laut. */
    public static function stumm( $konv_id, $an = true ) {
        global $wpdb;
        $teil = self::teilnehmer( $konv_id );
        if ( ! $teil ) return 'Sie gehören nicht zu dieser Unterhaltung.';
        $wpdb->update( LSV07S_Tabellen::t( 'konv_teil' ),
            [ 'stumm' => $an ? 1 : 0 ], [ 'id' => (int) $teil['id'] ],
            [ '%d' ], [ '%d' ] );
        return [ 'message' => $an
            ? 'Stumm. Diese Unterhaltung zählt nicht mehr in die Zahl oben.'
            : 'Wieder laut.' ];
    }

    /** Eine Nachricht ändern — innerhalb der Frist, nur die eigene. */
    public static function nachricht_aendern( $msg_id, $text ) {
        global $wpdb;
        $msg_id = (int) $msg_id;
        $m = LSV07S_Tabellen::t( 'konv_msg' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $m WHERE id = %d LIMIT 1",
            $msg_id ), ARRAY_A );
        if ( ! $z ) return 'Diese Nachricht gibt es nicht.';
        if ( (string) $z['von_typ'] !== 'user'
             || (int) $z['von_id'] !== (int) get_current_user_id() ) {
            return 'Sie können nur Ihre eigenen Nachrichten ändern.';
        }
        if ( $z['geloescht_am'] !== null ) return 'Diese Nachricht ist gelöscht.';
        if ( ! self::in_frist( $z['erstellt_am'] ) ) {
            return 'Nach ' . self::FRIST_STUNDEN . ' Stunden lässt sich eine Nachricht '
                 . 'nicht mehr ändern. Schreiben Sie eine neue, die es richtigstellt.';
        }
        $text = trim( (string) $text );
        if ( $text === '' ) return 'Bitte etwas schreiben.';
        if ( mb_strlen( $text ) > 10000 ) return 'Die Nachricht ist zu lang.';

        $wpdb->update( $m, [ 'text' => wp_kses( $text, [] ),
                             'bearbeitet_am' => LSV07S_Datum::jetzt() ],
            [ 'id' => $msg_id ], [ '%s', '%s' ], [ '%d' ] );

        LSV07S_Log::schreibe( 'nachrichten.geaendert', [
            'bereich' => 'reden', 'ziel_typ' => 'nachricht', 'ziel_id' => $msg_id,
            'details' => 'In Konversation ' . (int) $z['konv_id'],
        ] );
        return [ 'message' => 'Geändert.' ];
    }

    /**
     * Eine Nachricht löschen.
     *
     * Der Text geht, die Zeile bleibt: In einer Unterhaltung mit Lücken
     * weiss niemand mehr, worauf eine Antwort sich bezog. Ein
     * Administrator darf auch fremde löschen — das steht im Protokoll.
     */
    public static function nachricht_loeschen( $msg_id ) {
        global $wpdb;
        $msg_id = (int) $msg_id;
        $m = LSV07S_Tabellen::t( 'konv_msg' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $m WHERE id = %d LIMIT 1",
            $msg_id ), ARRAY_A );
        if ( ! $z ) return 'Diese Nachricht gibt es nicht.';
        if ( $z['geloescht_am'] !== null ) return 'Diese Nachricht ist schon gelöscht.';

        $uid = (int) get_current_user_id();
        $eigene = (string) $z['von_typ'] === 'user' && (int) $z['von_id'] === $uid;
        $admin  = LSV07S_Rechte::ist_admin();
        if ( ! $eigene && ! $admin ) {
            return 'Sie können nur Ihre eigenen Nachrichten löschen.';
        }
        if ( $eigene && ! $admin && ! self::in_frist( $z['erstellt_am'] ) ) {
            return 'Nach ' . self::FRIST_STUNDEN . ' Stunden lässt sich eine Nachricht '
                 . 'nicht mehr löschen.';
        }

        foreach ( self::anhaenge( $msg_id ) as $an ) self::anhang_loeschen( $an['id'], true );

        $wpdb->update( $m, [ 'text' => '', 'geloescht_am' => LSV07S_Datum::jetzt(),
                             'geloescht_von' => $uid ],
            [ 'id' => $msg_id ], [ '%s', '%s', '%d' ], [ '%d' ] );

        LSV07S_Log::schreibe( 'nachrichten.geloescht', [
            'bereich' => 'reden', 'ziel_typ' => 'nachricht', 'ziel_id' => $msg_id,
            'details' => 'In Konversation ' . (int) $z['konv_id']
                       . ( $eigene ? '' : ', fremde Nachricht als Administration' ),
        ] );
        return [ 'message' => 'Gelöscht. Die Stelle bleibt als Lücke sichtbar.' ];
    }

    /** Jemanden hinzufügen — nur in einer Gruppe, nur als Admin der Gruppe. */
    public static function teilnehmer_hinzu( $konv_id, $uid_neu ) {
        global $wpdb;
        $konv_id = (int) $konv_id;
        $uid_neu = (int) $uid_neu;
        $teil = self::teilnehmer( $konv_id );
        if ( ! $teil ) return 'Sie gehören nicht zu dieser Unterhaltung.';
        $konv = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'konv' ) . ' WHERE id = %d LIMIT 1',
            $konv_id ), ARRAY_A );
        if ( ! $konv ) return 'Diese Unterhaltung gibt es nicht.';
        if ( (string) $konv['typ'] === 'direkt' ) {
            return 'Eine direkte Unterhaltung hat genau zwei Teilnehmer. Legen Sie '
                 . 'eine Gruppe an.';
        }
        if ( (int) $teil['ist_admin'] !== 1 && ! LSV07S_Rechte::ist_admin() ) {
            return 'Nur wer diese Gruppe verwaltet, kann jemanden hinzufügen.';
        }
        if ( ! LSV07S_Schalter::zugang( $uid_neu ) ) {
            return 'Dieses Konto hat keinen Zugang.';
        }
        if ( self::teilnehmer( $konv_id, $uid_neu ) ) {
            return 'Diese Person ist schon dabei.';
        }

        self::teilnehmer_setzen( $konv_id, $uid_neu, false );
        self::system_nachricht( $konv_id,
            LSV07S_Person::konto_name( $uid_neu ) . ' wurde hinzugefügt von '
            . LSV07S_Person::konto_name( get_current_user_id() ) . '.' );

        LSV07S_Log::schreibe( 'nachrichten.teilnehmer', [
            'bereich' => 'reden', 'ziel_typ' => 'konversation', 'ziel_id' => $konv_id,
            'details' => 'hinzugefügt: ' . LSV07S_Person::konto_name( $uid_neu ),
        ] );
        return [ 'message' => 'Hinzugefügt.' ];
    }

    /** Jemanden entfernen oder selbst gehen. */
    public static function teilnehmer_weg( $konv_id, $uid_weg = 0 ) {
        global $wpdb;
        $konv_id = (int) $konv_id;
        $uid = (int) get_current_user_id();
        $uid_weg = (int) $uid_weg ?: $uid;
        $teil = self::teilnehmer( $konv_id );
        if ( ! $teil ) return 'Sie gehören nicht zu dieser Unterhaltung.';
        if ( $uid_weg !== $uid && (int) $teil['ist_admin'] !== 1
             && ! LSV07S_Rechte::ist_admin() ) {
            return 'Nur wer diese Gruppe verwaltet, kann jemanden entfernen.';
        }
        $weg = self::teilnehmer( $konv_id, $uid_weg );
        if ( ! $weg ) return 'Diese Person gehört nicht zu dieser Unterhaltung.';

        $wpdb->update( LSV07S_Tabellen::t( 'konv_teil' ),
            [ 'verlassen_am' => LSV07S_Datum::jetzt() ],
            [ 'id' => (int) $weg['id'] ], [ '%s' ], [ '%d' ] );

        self::system_nachricht( $konv_id, $uid_weg === $uid
            ? LSV07S_Person::konto_name( $uid ) . ' hat die Unterhaltung verlassen.'
            : LSV07S_Person::konto_name( $uid_weg ) . ' wurde entfernt von '
              . LSV07S_Person::konto_name( $uid ) . '.' );

        /* Eine Gruppe ohne Verwalter wäre nicht mehr zu ändern: Geht der
           letzte Admin, wird der älteste verbleibende Teilnehmer einer. */
        $noch = self::teilnehmer_liste( $konv_id );
        $hat_admin = false;
        foreach ( $noch as $t ) if ( $t['ist_admin'] ) $hat_admin = true;
        if ( ! $hat_admin && $noch ) {
            $wpdb->update( LSV07S_Tabellen::t( 'konv_teil' ), [ 'ist_admin' => 1 ],
                [ 'id' => (int) $noch[0]['id'] ], [ '%d' ], [ '%d' ] );
        }

        LSV07S_Log::schreibe( 'nachrichten.teilnehmer', [
            'bereich' => 'reden', 'ziel_typ' => 'konversation', 'ziel_id' => $konv_id,
            'details' => ( $uid_weg === $uid ? 'verlassen' : 'entfernt: '
                           . LSV07S_Person::konto_name( $uid_weg ) ),
        ] );
        return [ 'message' => $uid_weg === $uid ? 'Verlassen.' : 'Entfernt.' ];
    }

    /** Eine Zeile, die das System schreibt — ohne Absender. */
    private static function system_nachricht( $konv_id, $text ) {
        global $wpdb;
        $wpdb->insert( LSV07S_Tabellen::t( 'konv_msg' ), [
            'konv_id' => (int) $konv_id,
            'von_typ' => 'system',
            'von_id' => 0,
            'von_name' => null,
            'text' => mb_substr( sanitize_text_field( (string) $text ), 0, 500 ),
            'dringend' => 0,
            'erstellt_am' => LSV07S_Datum::jetzt(),
        ], [ '%d', '%s', '%d', '%s', '%s', '%d', '%s' ] );
        $wpdb->update( LSV07S_Tabellen::t( 'konv' ),
            [ 'letzte_aktivitaet' => LSV07S_Datum::jetzt() ],
            [ 'id' => (int) $konv_id ], [ '%s' ], [ '%d' ] );
    }

    /** Eine Gruppe umbenennen. */
    public static function umbenennen( $konv_id, $name ) {
        global $wpdb;
        $konv_id = (int) $konv_id;
        $teil = self::teilnehmer( $konv_id );
        if ( ! $teil ) return 'Sie gehören nicht zu dieser Unterhaltung.';
        if ( (int) $teil['ist_admin'] !== 1 && ! LSV07S_Rechte::ist_admin() ) {
            return 'Nur wer diese Gruppe verwaltet, kann sie umbenennen.';
        }
        $name = mb_substr( trim( sanitize_text_field( (string) $name ) ), 0, 200 );
        if ( $name === '' ) return 'Bitte einen Namen angeben.';
        $wpdb->update( LSV07S_Tabellen::t( 'konv' ), [ 'name' => $name ],
            [ 'id' => $konv_id ], [ '%s' ], [ '%d' ] );
        LSV07S_Log::schreibe( 'nachrichten.umbenannt', [
            'bereich' => 'reden', 'ziel_typ' => 'konversation', 'ziel_id' => $konv_id,
            'ziel_name' => $name,
        ] );
        return [ 'message' => 'Umbenannt.' ];
    }

    // ── Anhänge ──────────────────────────────────────────────────────────

    /** Derselbe Ordner wie im internen Bereich, ebenso gesperrt. */
    public static function ordner() {
        $up   = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/konv';
        if ( ! is_dir( $base ) ) wp_mkdir_p( $base );
        LSV07S_Attest::ordner_sperren( dirname( $base ) );
        LSV07S_Attest::ordner_sperren( $base );
        return $base;
    }

    /** Die Anhänge einer Nachricht. */
    public static function anhaenge( $msg_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'konv_anhang' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'konv_anhang' )
            . ' WHERE msg_id = %d ORDER BY id ASC', (int) $msg_id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id'   => (int) $z['id'],
            'name' => (string) $z['dateiname'],
            'typ'  => (string) $z['mime_type'],
            'groesse' => (int) $z['groesse'],
        ], $zeilen );
    }

    /** Einen Anhang an eine eigene Nachricht legen. */
    public static function anhang_ablegen( $msg_id, array $datei ) {
        global $wpdb;
        $msg_id = (int) $msg_id;
        if ( ! self::darf_reden() ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        if ( ! LSV07S_Tabellen::da( 'konv_anhang' ) ) return 'Die Tabelle fehlt.';
        $m = LSV07S_Tabellen::t( 'konv_msg' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $m WHERE id = %d LIMIT 1",
            $msg_id ), ARRAY_A );
        if ( ! $z ) return 'Diese Nachricht gibt es nicht.';
        if ( (int) $z['von_id'] !== (int) get_current_user_id() ) {
            return 'Sie können nur an Ihre eigenen Nachrichten etwas anhängen.';
        }
        if ( ! self::teilnehmer( (int) $z['konv_id'] ) ) {
            return 'Sie gehören nicht zu dieser Unterhaltung.';
        }

        $tmp = (string) ( $datei['tmp_name'] ?? '' );
        if ( (int) ( $datei['error'] ?? 1 ) !== 0 || $tmp === '' ) {
            return 'Die Datei ist nicht angekommen.';
        }
        $da = self::$pruefe_upload ? ( self::$pruefe_upload )( $tmp ) : is_uploaded_file( $tmp );
        if ( ! $da ) return 'Die Datei ist nicht angekommen.';
        $groesse = (int) ( $datei['size'] ?? 0 );
        if ( $groesse <= 0 ) return 'Die Datei ist leer.';
        if ( $groesse > self::ANHANG_MAX ) return 'Die Datei ist größer als 10 MB.';

        $typ = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
        if ( ! isset( self::ANHANG_TYPEN[ $typ ] ) ) {
            return 'Erlaubt sind PDF, JPG und PNG. Diese Datei ist '
                 . ( $typ ?: 'von unbekannter Art' ) . '.';
        }

        $ordner = self::ordner();
        $name = 'konv-' . (int) $z['konv_id'] . '-' . $msg_id . '-'
              . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.' . self::ANHANG_TYPEN[ $typ ];
        $ziel = $ordner . '/' . $name;
        $ok = self::$pruefe_upload ? @copy( $tmp, $ziel ) : @move_uploaded_file( $tmp, $ziel );
        if ( ! $ok ) return 'Die Datei konnte nicht gespeichert werden.';
        @chmod( $ziel, 0640 );

        /* Die Tabelle hat eine Spalte `token`: Im internen Bereich ist das
           der Schlüssel, mit dem ein Anhang OHNE Anmeldung abgerufen
           werden kann. SwimSys gibt Anhänge nur an Teilnehmer aus und
           benutzt ihn nicht — die Spalte ist aber NOT NULL und eindeutig,
           also wird ein zufälliger geschrieben. Ein leerer würde beim
           zweiten Anhang am eindeutigen Schlüssel scheitern. */
        $wpdb->insert( LSV07S_Tabellen::t( 'konv_anhang' ), [
            'msg_id' => $msg_id,
            'dateiname' => mb_substr( sanitize_file_name(
                             (string) ( $datei['name'] ?? $name ) ), 0, 255 ),
            'gespeichert_als' => $name,
            'mime_type' => $typ,
            'groesse' => $groesse,
            'token' => md5( uniqid( 'lsv07s', true ) ) . md5( $name ),
            'erstellt_am' => LSV07S_Datum::jetzt(),
        ], [ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ] );

        return [ 'id' => (int) $wpdb->insert_id, 'message' => 'Angehängt.' ];
    }

    /**
     * Den Pfad eines Anhangs holen.
     *
     * Nur für Teilnehmer der Konversation. Im internen Bereich hängt der
     * Abruf an einem Token und ist bewusst ohne Anmeldung erreichbar —
     * wer den Verweis hat, bekommt die Datei. Hier nicht.
     */
    public static function anhang_pfad( $id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'konv_anhang' ) ) return 'Die Tabelle fehlt.';
        $z = $wpdb->get_row( $wpdb->prepare(
            'SELECT a.*, m.konv_id FROM ' . LSV07S_Tabellen::t( 'konv_anhang' ) . ' a'
            . ' JOIN ' . LSV07S_Tabellen::t( 'konv_msg' ) . ' m ON m.id = a.msg_id'
            . ' WHERE a.id = %d LIMIT 1', (int) $id ), ARRAY_A );
        if ( ! $z ) return 'Diesen Anhang gibt es nicht.';
        $fremd = false;
        if ( ! self::darf_sehen( (int) $z['konv_id'], $fremd ) ) {
            return 'Dieser Anhang gehört nicht zu Ihnen.';
        }
        $pfad = self::ordner() . '/' . basename( (string) $z['gespeichert_als'] );
        if ( ! is_file( $pfad ) ) return 'Die Datei liegt nicht mehr im Ordner.';
        return [ 'pfad' => $pfad, 'name' => (string) $z['dateiname'],
                 'typ' => (string) $z['mime_type'] ];
    }

    /** Einen Anhang löschen. */
    public static function anhang_loeschen( $id, $still = false ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'konv_anhang' ) ) return 'Die Tabelle fehlt.';
        $t = LSV07S_Tabellen::t( 'konv_anhang' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            (int) $id ), ARRAY_A );
        if ( ! $z ) return 'Diesen Anhang gibt es nicht.';
        if ( ! $still ) {
            /* Wessen Anhang das ist, steht an der Nachricht — die Tabelle
               selbst merkt sich keinen Hochladenden. */
            $von = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT von_id FROM ' . LSV07S_Tabellen::t( 'konv_msg' )
                . ' WHERE id = %d LIMIT 1', (int) $z['msg_id'] ) );
            if ( $von !== (int) get_current_user_id() && ! LSV07S_Rechte::ist_admin() ) {
                return 'Sie können nur Ihre eigenen Anhänge löschen.';
            }
        }
        $pfad = self::ordner() . '/' . basename( (string) $z['gespeichert_als'] );
        if ( is_file( $pfad ) ) @unlink( $pfad );
        $wpdb->delete( $t, [ 'id' => (int) $id ], [ '%d' ] );
        return true;
    }
}
