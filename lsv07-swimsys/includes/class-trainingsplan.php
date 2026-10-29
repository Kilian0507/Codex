<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Trainingspläne: Titel und eine geordnete Liste von Sessions.
 *
 * Ein Plan gehört seinem Ersteller. Wer ihn **freigibt**, zeigt ihn den
 * anderen Trainern — zum Lesen, nicht zum Ändern. Dazu kann ein Plan
 * PDFs tragen: ein eingescannter Plan ist ein Plan.
 *
 * **Ein Recht, nicht zwei.** Anders als bei den Reflexionsbögen gibt es
 * hier kein eigenes Recht zum Schreiben: Ein Plan ist das Arbeitsmaterial
 * des Trainers selbst, und wer Pläne sehen darf, darf eigene schreiben.
 * Fremde ändert niemand — das hängt am Eigentum, nicht am Recht.
 *
 * Was hier anders ist als im internen Bereich:
 *
 * 1. **Das Protokoll unterscheidet Anlegen und Ändern.** Dort steht bei
 *    jedem Speichern `trainingsplan.update`, auch beim ersten — die
 *    Nummer wird vor dem Schreiben des Protokolls gesetzt, und damit ist
 *    die Bedingung immer wahr.
 * 2. **Die Tabellen werden nicht nebenbei angelegt.** Dort legt jeder
 *    Aufruf sie an, falls sie fehlen. Hier gehört das Schema an eine
 *    Stelle (LSV07S_Schema).
 * 3. **Eine Session behält ihre Nummer**, wenn sie beim Speichern
 *    mitkommt. Dort werden alle gelöscht und neu geschrieben.
 * 4. **Freigeben und Zurückziehen stehen im Protokoll**, beides.
 */
class LSV07S_Trainingsplan {

    /** Mehr Sessions sind kein Plan mehr. */
    const SESSIONS_MAX = 100;

    /** PDFs je Plan, und wie groß eine sein darf. */
    const DATEIEN_MAX = 20;
    const DATEI_MAX = 20971520;   // 20 MB

    /** Für Prüfungen: die Prüfung auf einen echten Upload umgehen. */
    public static $pruefe_upload = null;

    // ── Rechte ───────────────────────────────────────────────────────────

    private static function darf_lesen() {
        return LSV07S_Rechte::darf( LSV07S_Rechte::SCHWIMMEN_TRAININGSPLAN_READ );
    }

    /**
     * Darf dieses Konto den Plan ändern?
     *
     * Nur der Ersteller und die Administration. Freigegeben heißt
     * sichtbar, nicht änderbar — sonst schreibt einer den Plan eines
     * anderen um, und der merkt es beim nächsten Training.
     */
    private static function mein( $plan ) {
        if ( ! $plan ) return false;
        if ( LSV07S_Rechte::ist_admin() ) return true;
        return (int) $plan['ersteller_id'] === (int) get_current_user_id();
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** Eigene Pläne und die freigegebenen der anderen. */
    public static function liste() {
        global $wpdb;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan' ) ) {
            return [ 'eigene' => [], 'freigegeben' => [] ];
        }
        $uid = (int) get_current_user_id();
        $t = LSV07S_Tabellen::t( 'tplan' );
        $s = LSV07S_Tabellen::t( 'tplan_session' );
        $d = LSV07S_Tabellen::da( 'tplan_datei' )
            ? LSV07S_Tabellen::t( 'tplan_datei' ) : '';

        $zahl = "( SELECT COUNT(*) FROM $s x WHERE x.trainingsplan_id = t.id ) AS sessions";
        $dzahl = $d
            ? ", ( SELECT COUNT(*) FROM $d y WHERE y.plan_id = t.id ) AS dateien"
            : ', 0 AS dateien';

        $eigene = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, $zahl$dzahl FROM $t t WHERE t.ersteller_id = %d"
            . ' ORDER BY t.geaendert_am DESC', $uid ), ARRAY_A );
        $fremd = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*, $zahl$dzahl FROM $t t"
            . ' WHERE t.freigegeben = 1 AND t.ersteller_id <> %d'
            . ' ORDER BY t.geaendert_am DESC', $uid ), ARRAY_A );

        return [
            'eigene' => array_map( [ __CLASS__, 'zeile' ], $eigene ),
            'freigegeben' => array_map( [ __CLASS__, 'zeile' ], $fremd ),
        ];
    }

    private static function zeile( array $z ) {
        return [
            'id' => (int) $z['id'],
            'titel' => (string) $z['titel'],
            'ersteller' => (string) $z['ersteller_name'],
            'freigegeben' => (int) $z['freigegeben'] === 1,
            'sessions' => (int) ( $z['sessions'] ?? 0 ),
            'dateien' => (int) ( $z['dateien'] ?? 0 ),
            'geaendert' => LSV07S_Datum::de_zeit( $z['geaendert_am'] ?? null, '' ),
        ];
    }

    /** Einen Plan mit seinen Sessions und Dateien. */
    public static function holen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan' ) ) return 'Die Tabelle fehlt.';
        $t = LSV07S_Tabellen::t( 'tplan' );
        $p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $p ) return 'Diesen Trainingsplan gibt es nicht.';

        $mein = self::mein( $p );
        if ( ! $mein && (int) $p['freigegeben'] !== 1 ) {
            return 'Dieser Trainingsplan ist nicht freigegeben.';
        }

        return [
            'id' => $id,
            'titel' => (string) $p['titel'],
            'ersteller' => (string) $p['ersteller_name'],
            'freigegeben' => (int) $p['freigegeben'] === 1,
            'geaendert' => LSV07S_Datum::de_zeit( $p['geaendert_am'] ?? null, '' ),
            'darf_aendern' => $mein,
            'sessions' => self::sessions( $id ),
            'dateien' => self::dateien( $id ),
        ];
    }

    /** Die Sessions eines Plans, in ihrer Reihenfolge. */
    public static function sessions( $plan_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'tplan_session' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'tplan_session' )
            . ' WHERE trainingsplan_id = %d ORDER BY sortierung ASC, id ASC',
            (int) $plan_id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id' => (int) $z['id'],
            'anzahl' => (string) $z['anzahl'],
            'strecke' => (string) $z['strecke'],
            'beschreibung' => (string) ( $z['beschreibung'] ?? '' ),
            'ausruestung' => (string) $z['ausruestung'],
            'kommentar' => (string) ( $z['kommentar'] ?? '' ),
        ], $zeilen );
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Einen Plan anlegen oder ändern.
     *
     * `$sessions` ist die ganze Liste; eine Zeile mit `id` behält ihre
     * Nummer. Ein Plan braucht Inhalt: Sessions oder mindestens eine
     * PDF. Ein Titel allein ist kein Trainingsplan.
     */
    public static function speichern( $id, $titel, array $sessions, $pdf_folgt = false ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan' ) ) return 'Die Tabelle fehlt.';
        $titel = mb_substr( trim( sanitize_text_field( (string) $titel ) ), 0, 200 );
        if ( $titel === '' ) return 'Bitte einen Titel angeben.';
        if ( count( $sessions ) > self::SESSIONS_MAX ) {
            return 'Mehr als ' . self::SESSIONS_MAX . ' Sessions sind nicht vorgesehen.';
        }

        $t = LSV07S_Tabellen::t( 'tplan' );
        $plan = null;
        if ( $id ) {
            $plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
                $id ), ARRAY_A );
            if ( ! $plan ) return 'Diesen Trainingsplan gibt es nicht.';
            if ( ! self::mein( $plan ) ) {
                return 'Dieser Trainingsplan gehört nicht Ihnen. Freigegeben heißt '
                     . 'lesen, nicht ändern.';
            }
        }

        /* Leere Zeilen fallen weg: Im Formular steht immer eine mehr. */
        $sauber = [];
        foreach ( $sessions as $s ) {
            if ( ! is_array( $s ) ) continue;
            $z = [
                'id' => absint( $s['id'] ?? 0 ),
                'anzahl' => mb_substr( sanitize_text_field( (string) ( $s['anzahl'] ?? '' ) ), 0, 20 ),
                'strecke' => mb_substr( sanitize_text_field( (string) ( $s['strecke'] ?? '' ) ), 0, 60 ),
                'beschreibung' => mb_substr( sanitize_textarea_field(
                    (string) ( $s['beschreibung'] ?? '' ) ), 0, 5000 ),
                'ausruestung' => mb_substr( sanitize_text_field(
                    (string) ( $s['ausruestung'] ?? '' ) ), 0, 200 ),
                'kommentar' => mb_substr( sanitize_textarea_field(
                    (string) ( $s['kommentar'] ?? '' ) ), 0, 5000 ),
            ];
            if ( $z['anzahl'] === '' && $z['strecke'] === '' && $z['beschreibung'] === ''
                 && $z['ausruestung'] === '' && $z['kommentar'] === '' ) continue;
            $sauber[] = $z;
        }

        /* Ein Plan braucht Inhalt. Ein eingescannter Plan ist einer —
           darum zählt eine vorhandene oder gerade gewählte PDF mit. */
        if ( $sauber === [] ) {
            $hat_pdf = $pdf_folgt || ( $id && self::dateien( $id ) !== [] );
            if ( ! $hat_pdf ) {
                return 'Bitte mindestens eine Session anlegen oder eine PDF '
                     . 'hochladen — ein Titel allein ist kein Trainingsplan.';
            }
        }

        $neu = ! $id;
        if ( $neu ) {
            $wpdb->insert( $t, [
                'titel' => $titel,
                'ersteller_id' => (int) get_current_user_id(),
                'ersteller_name' => LSV07S_Person::konto_name( get_current_user_id() ),
                'freigegeben' => 0,
                'erstellt_am' => LSV07S_Datum::jetzt(),
                'geaendert_am' => LSV07S_Datum::jetzt(),
            ], [ '%s', '%d', '%s', '%d', '%s', '%s' ] );
            $id = (int) $wpdb->insert_id;
            if ( ! $id ) return 'Der Trainingsplan konnte nicht gespeichert werden.';
        } else {
            $wpdb->update( $t, [ 'titel' => $titel,
                                 'geaendert_am' => LSV07S_Datum::jetzt() ],
                [ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
        }

        self::sessions_setzen( $id, $sauber );

        /* Anlegen und Ändern sind zwei Dinge. Im internen Bereich steht
           immer „update": Dort wird `$id` vor dem Protokoll gesetzt. */
        LSV07S_Log::schreibe( $neu ? 'trainingsplan.angelegt' : 'trainingsplan.geaendert', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'trainingsplan',
            'ziel_id' => $id, 'ziel_name' => $titel,
            'details' => count( $sauber ) . ' Session(s)',
        ] );

        return [ 'id' => $id, 'neu' => $neu,
                 'message' => $neu ? 'Angelegt.' : 'Gespeichert.' ];
    }

    /**
     * Die Sessions setzen.
     *
     * Eine Zeile mit Nummer wird geändert, eine ohne kommt dazu, und was
     * nicht mehr mitkommt, geht. Im internen Bereich werden ALLE
     * gelöscht und neu geschrieben — bei jedem Speichern neue Nummern.
     */
    private static function sessions_setzen( $plan_id, array $sessions ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'tplan_session' ) ) return;
        $s = LSV07S_Tabellen::t( 'tplan_session' );
        $da = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $s WHERE trainingsplan_id = %d", (int) $plan_id ) ) );

        $bleiben = [];
        foreach ( $sessions as $i => $z ) {
            $werte = [
                'anzahl' => $z['anzahl'],
                'strecke' => $z['strecke'],
                'beschreibung' => $z['beschreibung'],
                'ausruestung' => $z['ausruestung'],
                'kommentar' => $z['kommentar'],
                'sortierung' => $i,
            ];
            $form = [ '%s', '%s', '%s', '%s', '%s', '%d' ];
            if ( $z['id'] && in_array( (int) $z['id'], $da, true ) ) {
                $wpdb->update( $s, $werte, [ 'id' => (int) $z['id'] ], $form, [ '%d' ] );
                $bleiben[] = (int) $z['id'];
                continue;
            }
            $werte['trainingsplan_id'] = (int) $plan_id;
            $form[] = '%d';
            $wpdb->insert( $s, $werte, $form );
            $bleiben[] = (int) $wpdb->insert_id;
        }
        foreach ( $da as $alt ) {
            if ( in_array( $alt, $bleiben, true ) ) continue;
            $wpdb->delete( $s, [ 'id' => $alt ], [ '%d' ] );
        }
    }

    /** Freigeben heißt: die anderen Trainer dürfen lesen. */
    public static function freigeben( $id, $an = true ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan' ) ) return 'Die Tabelle fehlt.';
        $t = LSV07S_Tabellen::t( 'tplan' );
        $plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $plan ) return 'Diesen Trainingsplan gibt es nicht.';
        if ( ! self::mein( $plan ) ) {
            return 'Nur wer den Plan geschrieben hat, gibt ihn frei.';
        }
        $wpdb->update( $t, [ 'freigegeben' => $an ? 1 : 0,
                             'geaendert_am' => LSV07S_Datum::jetzt() ],
            [ 'id' => $id ], [ '%d', '%s' ], [ '%d' ] );

        /* Beides steht im Protokoll — auch das Zurückziehen. Wer sich auf
           einen Plan verlassen hat, soll nachsehen können, wann er
           verschwand. */
        LSV07S_Log::schreibe( $an ? 'trainingsplan.freigegeben'
                                  : 'trainingsplan.zurueckgezogen', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'trainingsplan',
            'ziel_id' => $id, 'ziel_name' => (string) $plan['titel'],
        ] );
        return [ 'freigegeben' => (bool) $an, 'message' => $an
            ? 'Freigegeben. Die anderen Trainer können ihn lesen.'
            : 'Zurückgezogen. Nur noch Sie sehen ihn.' ];
    }

    /** Einen Plan löschen, mit Sessions und Dateien. */
    public static function loeschen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan' ) ) return 'Die Tabelle fehlt.';
        $t = LSV07S_Tabellen::t( 'tplan' );
        $plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $plan ) return 'Diesen Trainingsplan gibt es nicht.';
        if ( ! self::mein( $plan ) ) {
            return 'Dieser Trainingsplan gehört nicht Ihnen.';
        }

        $dateien = self::dateien( $id );
        foreach ( $dateien as $d ) self::datei_loeschen( (int) $d['id'] );
        if ( LSV07S_Tabellen::da( 'tplan_session' ) ) {
            $wpdb->delete( LSV07S_Tabellen::t( 'tplan_session' ),
                [ 'trainingsplan_id' => $id ], [ '%d' ] );
        }
        $wpdb->delete( $t, [ 'id' => $id ], [ '%d' ] );

        LSV07S_Log::schreibe( 'trainingsplan.geloescht', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'trainingsplan',
            'ziel_id' => $id, 'ziel_name' => (string) $plan['titel'],
            'details' => count( $dateien ) . ' Datei(en)',
        ] );
        return [ 'message' => 'Gelöscht'
            . ( $dateien ? ', mit ' . count( $dateien ) . ' Datei(en)' : '' ) . '.' ];
    }

    // ── PDFs am Plan ─────────────────────────────────────────────────────

    /**
     * Derselbe Ordner wie im internen Bereich.
     *
     * Beide Plugins geben dieselben Dateien aus, solange beide laufen.
     * Der Ordner liegt in den Uploads und wird gesperrt — ausgegeben
     * wird nur durch das Plugin, mit Rechteprüfung.
     */
    public static function ordner( $plan_id = 0 ) {
        $up = wp_upload_dir();
        $base = trailingslashit( $up['basedir'] ) . 'lsv07i-private/trainingsplan';
        if ( ! is_dir( $base ) ) wp_mkdir_p( $base );
        LSV07S_Attest::ordner_sperren( dirname( $base ) );
        LSV07S_Attest::ordner_sperren( $base );
        if ( ! $plan_id ) return $base;
        $dir = trailingslashit( $base ) . (int) $plan_id;
        if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
        return $dir;
    }

    /** Die PDFs eines Plans. */
    public static function dateien( $plan_id ) {
        global $wpdb;
        if ( ! LSV07S_Tabellen::da( 'tplan_datei' ) ) return [];
        $zeilen = (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'tplan_datei' )
            . ' WHERE plan_id = %d ORDER BY sortierung ASC, id ASC',
            (int) $plan_id ), ARRAY_A );
        return array_map( fn( $z ) => [
            'id' => (int) $z['id'],
            'name' => (string) $z['dateiname'],
            'groesse' => (int) $z['groesse'],
            'am' => LSV07S_Datum::de_zeit( $z['hochgeladen_am'] ?? null, '' ),
        ], $zeilen );
    }

    /** Eine PDF an einen eigenen Plan legen. */
    public static function datei_ablegen( $plan_id, array $datei ) {
        global $wpdb;
        $plan_id = (int) $plan_id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan_datei' ) ) return 'Die Tabelle fehlt.';
        $t = LSV07S_Tabellen::t( 'tplan' );
        $plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            $plan_id ), ARRAY_A );
        if ( ! $plan ) return 'Diesen Trainingsplan gibt es nicht.';
        if ( ! self::mein( $plan ) ) {
            return 'Dieser Trainingsplan gehört nicht Ihnen.';
        }
        if ( count( self::dateien( $plan_id ) ) >= self::DATEIEN_MAX ) {
            return 'Mehr als ' . self::DATEIEN_MAX . ' PDFs je Plan sind nicht vorgesehen.';
        }

        $tmp = (string) ( $datei['tmp_name'] ?? '' );
        if ( (int) ( $datei['error'] ?? 1 ) !== 0 || $tmp === '' ) {
            return 'Die Datei ist nicht angekommen.';
        }
        $da = self::$pruefe_upload ? ( self::$pruefe_upload )( $tmp ) : is_uploaded_file( $tmp );
        if ( ! $da ) return 'Die Datei ist nicht angekommen.';
        $groesse = (int) ( $datei['size'] ?? 0 );
        if ( $groesse <= 0 ) return 'Die Datei ist leer.';
        if ( $groesse > self::DATEI_MAX ) return 'Die Datei ist größer als 20 MB.';

        /* Geprüft wird der Inhalt, nicht die Endung: `plan.pdf` kann
           alles sein. */
        $typ = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
        if ( $typ !== 'application/pdf' ) {
            return 'Erlaubt sind PDFs. Diese Datei ist '
                 . ( $typ ?: 'von unbekannter Art' ) . '.';
        }

        $name = bin2hex( random_bytes( 8 ) ) . '.pdf';
        $ziel = trailingslashit( self::ordner( $plan_id ) ) . $name;
        $ok = self::$pruefe_upload ? @copy( $tmp, $ziel ) : @move_uploaded_file( $tmp, $ziel );
        if ( ! $ok ) return 'Die Datei konnte nicht gespeichert werden.';
        @chmod( $ziel, 0640 );

        $dt = LSV07S_Tabellen::t( 'tplan_datei' );
        $sort = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(sortierung), -1) + 1 FROM $dt WHERE plan_id = %d",
            $plan_id ) );
        $wpdb->insert( $dt, [
            'plan_id' => $plan_id,
            'dateiname' => mb_substr( sanitize_file_name(
                (string) ( $datei['name'] ?? $name ) ), 0, 255 ),
            'gespeichert_als' => $name,
            'groesse' => $groesse,
            'sortierung' => $sort,
            'hochgeladen_von' => (int) get_current_user_id(),
            'hochgeladen_am' => LSV07S_Datum::jetzt(),
        ], [ '%d', '%s', '%s', '%d', '%d', '%d', '%s' ] );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) {
            @unlink( $ziel );
            return 'Die Datei konnte nicht gespeichert werden.';
        }

        LSV07S_Log::schreibe( 'trainingsplan.datei', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'trainingsplan',
            'ziel_id' => $plan_id, 'ziel_name' => (string) $plan['titel'],
            'details' => 'PDF hinzugefügt',
        ] );
        return [ 'id' => $id, 'message' => 'Hochgeladen.' ];
    }

    /**
     * Der Weg zu einer PDF — mit derselben Regel wie der Plan selbst.
     *
     * Eigene Pläne und freigegebene; sonst nicht. Im internen Bereich
     * gilt dasselbe, und das muss es auch: Die Dateien liegen in einem
     * Ordner, den der Webserver nicht ausgibt.
     */
    public static function datei_pfad( $datei_id ) {
        global $wpdb;
        $datei_id = (int) $datei_id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan_datei' ) ) return 'Die Tabelle fehlt.';
        $z = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . LSV07S_Tabellen::t( 'tplan_datei' )
            . ' WHERE id = %d LIMIT 1', $datei_id ), ARRAY_A );
        if ( ! $z ) return 'Diese Datei gibt es nicht.';

        $t = LSV07S_Tabellen::t( 'tplan' );
        $plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            (int) $z['plan_id'] ), ARRAY_A );
        if ( ! $plan ) return 'Diesen Trainingsplan gibt es nicht.';
        if ( ! self::mein( $plan ) && (int) $plan['freigegeben'] !== 1 ) {
            return 'Dieser Trainingsplan ist nicht freigegeben.';
        }

        $pfad = trailingslashit( self::ordner( (int) $z['plan_id'] ) )
              . (string) $z['gespeichert_als'];
        if ( ! is_file( $pfad ) ) return 'Die Datei ist nicht mehr da.';
        return [ 'pfad' => $pfad, 'name' => (string) $z['dateiname'],
                 'typ' => 'application/pdf' ];
    }

    /** Eine PDF vom eigenen Plan nehmen. */
    public static function datei_loeschen( $datei_id ) {
        global $wpdb;
        $datei_id = (int) $datei_id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan_datei' ) ) return 'Die Tabelle fehlt.';
        $dt = LSV07S_Tabellen::t( 'tplan_datei' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $dt WHERE id = %d LIMIT 1",
            $datei_id ), ARRAY_A );
        if ( ! $z ) return 'Diese Datei gibt es nicht.';
        $t = LSV07S_Tabellen::t( 'tplan' );
        $plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d LIMIT 1",
            (int) $z['plan_id'] ), ARRAY_A );
        if ( $plan && ! self::mein( $plan ) ) {
            return 'Dieser Trainingsplan gehört nicht Ihnen.';
        }

        $pfad = trailingslashit( self::ordner( (int) $z['plan_id'] ) )
              . (string) $z['gespeichert_als'];
        if ( is_file( $pfad ) ) @unlink( $pfad );
        $wpdb->delete( $dt, [ 'id' => $datei_id ], [ '%d' ] );
        return true;
    }

    // ── Vorlagen für einzelne Sessions ───────────────────────────────────

    /**
     * Vorlagen: einzelne Sessions, die man wiederverwendet.
     *
     * Dieselbe Regel wie beim Plan — eigene und die freigegebenen der
     * anderen, ändern darf sie nur, wem sie gehört.
     */
    public static function vorlagen() {
        global $wpdb;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan_vorlage' ) ) {
            return [ 'eigene' => [], 'freigegeben' => [] ];
        }
        $v = LSV07S_Tabellen::t( 'tplan_vorlage' );
        $uid = (int) get_current_user_id();
        $eigene = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $v WHERE ersteller_id = %d ORDER BY erstellt_am DESC",
            $uid ), ARRAY_A );
        $fremd = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $v WHERE freigegeben = 1 AND ersteller_id <> %d"
            . ' ORDER BY erstellt_am DESC', $uid ), ARRAY_A );
        $form = fn( $z ) => [
            'id' => (int) $z['id'],
            'anzahl' => (string) $z['anzahl'],
            'strecke' => (string) $z['strecke'],
            'beschreibung' => (string) ( $z['beschreibung'] ?? '' ),
            'ausruestung' => (string) $z['ausruestung'],
            'kommentar' => (string) ( $z['kommentar'] ?? '' ),
            'ersteller' => (string) $z['ersteller_name'],
            'freigegeben' => (int) $z['freigegeben'] === 1,
        ];
        return [ 'eigene' => array_map( $form, $eigene ),
                 'freigegeben' => array_map( $form, $fremd ) ];
    }

    /** Eine Vorlage anlegen. */
    public static function vorlage_speichern( array $s ) {
        global $wpdb;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan_vorlage' ) ) return 'Die Tabelle fehlt.';
        $werte = [
            'anzahl' => mb_substr( sanitize_text_field( (string) ( $s['anzahl'] ?? '' ) ), 0, 20 ),
            'strecke' => mb_substr( sanitize_text_field( (string) ( $s['strecke'] ?? '' ) ), 0, 60 ),
            'beschreibung' => mb_substr( sanitize_textarea_field(
                (string) ( $s['beschreibung'] ?? '' ) ), 0, 5000 ),
            'ausruestung' => mb_substr( sanitize_text_field(
                (string) ( $s['ausruestung'] ?? '' ) ), 0, 200 ),
            'kommentar' => mb_substr( sanitize_textarea_field(
                (string) ( $s['kommentar'] ?? '' ) ), 0, 5000 ),
        ];
        if ( implode( '', $werte ) === '' ) {
            return 'Diese Session ist leer — es gibt nichts zu speichern.';
        }
        $werte['ersteller_id'] = (int) get_current_user_id();
        $werte['ersteller_name'] = LSV07S_Person::konto_name( get_current_user_id() );
        $werte['freigegeben'] = 0;
        $werte['erstellt_am'] = LSV07S_Datum::jetzt();
        $wpdb->insert( LSV07S_Tabellen::t( 'tplan_vorlage' ), $werte,
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ] );
        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 'Die Vorlage konnte nicht gespeichert werden.';
        LSV07S_Log::schreibe( 'tp_vorlage.angelegt', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'tp_vorlage',
            'ziel_id' => $id, 'ziel_name' => $werte['strecke'] ?: $werte['anzahl'],
        ] );
        return [ 'id' => $id, 'message' => 'Als Vorlage gespeichert.' ];
    }

    /** Eine eigene Vorlage freigeben oder zurückziehen. */
    public static function vorlage_freigeben( $id, $an = true ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan_vorlage' ) ) return 'Die Tabelle fehlt.';
        $v = LSV07S_Tabellen::t( 'tplan_vorlage' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $v WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $z ) return 'Diese Vorlage gibt es nicht.';
        if ( ! self::mein( $z ) ) return 'Diese Vorlage gehört nicht Ihnen.';
        $wpdb->update( $v, [ 'freigegeben' => $an ? 1 : 0 ], [ 'id' => $id ],
            [ '%d' ], [ '%d' ] );
        return [ 'freigegeben' => (bool) $an, 'message' => $an
            ? 'Freigegeben.' : 'Zurückgezogen.' ];
    }

    /** Eine eigene Vorlage löschen. */
    public static function vorlage_loeschen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::darf_lesen() ) return 'Sie haben keine Berechtigung für diese Aktion.';
        if ( ! LSV07S_Tabellen::da( 'tplan_vorlage' ) ) return 'Die Tabelle fehlt.';
        $v = LSV07S_Tabellen::t( 'tplan_vorlage' );
        $z = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $v WHERE id = %d LIMIT 1",
            $id ), ARRAY_A );
        if ( ! $z ) return 'Diese Vorlage gibt es nicht.';
        if ( ! self::mein( $z ) ) return 'Diese Vorlage gehört nicht Ihnen.';
        $wpdb->delete( $v, [ 'id' => $id ], [ '%d' ] );
        LSV07S_Log::schreibe( 'tp_vorlage.geloescht', [
            'bereich' => LSV07S_Sparte::SCHWIMMEN, 'ziel_typ' => 'tp_vorlage',
            'ziel_id' => $id,
        ] );
        return [ 'message' => 'Gelöscht.' ];
    }
}
