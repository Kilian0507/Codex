<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Abteilungsstruktur — und damit die Genehmigungseinheit.
 *
 * Ein Verein ab einer gewissen Größe ist in Abteilungen gegliedert:
 * Schwimmen, Wasserball, Leichtathletik, Geschäftsstelle. Jedes Konto gehört
 * zu einer, und **der Abteilungsleiter genehmigt, was aus seiner Abteilung
 * kommt**. Das ist der Unterschied zur Zuständigkeit des Warts: Die hängt an
 * den Mannschaften einer Abrechnung, die Abteilung hängt an der PERSON. Eine
 * Rechnung über neue Startblöcke hat keine Mannschaft — einen Abteilungs-
 * leiter hat sie trotzdem.
 *
 * Drei Festlegungen:
 *
 *  1. **Der interne Bereich bleibt unberührt.** Er kennt keine Abteilungen,
 *     und es wird nichts dort angelegt oder geändert. Seine Mannschaften
 *     (`lsv07_gruppen`) werden nur gelesen; hier steht nur, welche Nummer zu
 *     welcher Abteilung gehört. Wird dieses Plugin entfernt, ist der interne
 *     Bereich genau so, wie er vorher war.
 *  2. **Eine Abteilung muss keine Mannschaften haben.** Die Geschäftsstelle
 *     rechnet ab, trainiert aber nicht. Darum eine eigene Tabelle statt
 *     einer Spalte: keine Zeile ist ein gültiger Zustand.
 *  3. **Eine Mannschaft gehört zu höchstens einer Abteilung.** Sonst wäre
 *     nicht zu sagen, wer sie genehmigt — und die Frage „wer entscheidet"
 *     darf keine zwei Antworten haben.
 */
class LSV07A_Abteilung {

    private static $cache_leitet = [];

    private static function t( $n ) { global $wpdb; return $wpdb->prefix . $n; }

    // ── Lesen ────────────────────────────────────────────────────────────

    /** Eine Abteilung, oder null. */
    public static function holen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'lsv07a_abteilung' ) . " WHERE id = %d", $id ), ARRAY_A ) ?: null;
    }

    public static function name( $id ) {
        $a = self::holen( $id );
        return $a ? (string) $a['name'] : '';
    }

    /**
     * Alle Abteilungen, mit Mannschaften, Leitung und der Zahl der Konten.
     * Das ist die Liste für die Verwaltung — eine Abfrage je Teil, nicht je
     * Abteilung.
     */
    public static function alle( $nur_aktive = false ) {
        global $wpdb;
        $wo = $nur_aktive ? 'WHERE aktiv = 1' : '';
        $zeilen = $wpdb->get_results(
            "SELECT * FROM " . self::t( 'lsv07a_abteilung' ) . " $wo
              ORDER BY sortierung ASC, name ASC", ARRAY_A ) ?: [];
        if ( ! $zeilen ) return [];

        /* Mannschaften je Abteilung, aus BEIDEN Quellen: die des internen
           Bereichs (nur gelesen) und die eigenen. Ist der interne Bereich
           nicht erreichbar, bleibt dort die Nummer stehen; eine Abteilung
           ohne lesbare Mannschaftsnamen ist immer noch eine Abteilung. */
        $namen = [ 'intern' => [], 'eigen' => [] ];
        foreach ( LSV07A_Intern::mannschaften() as $m ) {
            $namen['intern'][ (int) $m['id'] ] = $m['name'];
        }
        foreach ( LSV07A_Mannschaft::alle() as $m ) {
            $namen['eigen'][ (int) $m['id'] ] = $m['name'];
        }

        $mann = [];
        foreach ( (array) $wpdb->get_results(
            "SELECT abteilung_id, mannschaft_id, quelle FROM "
            . self::t( 'lsv07a_abteilung_mannschaft' ), ARRAY_A ) as $z ) {
            $mid = (int) $z['mannschaft_id'];
            $q   = self::quelle( $z['quelle'] ?? 'intern' );
            $mann[ (int) $z['abteilung_id'] ][] = [
                'id'     => $mid,
                'quelle' => $q,
                'wert'   => $q . ':' . $mid,
                'name'   => $namen[ $q ][ $mid ] ?? ( 'Mannschaft ' . $mid ),
                'da'     => isset( $namen[ $q ][ $mid ] ),
                'eigen'  => $q === 'eigen',
            ];
        }

        $leiter = [];
        foreach ( (array) $wpdb->get_results(
            "SELECT abteilung_id, wp_user_id FROM " . self::t( 'lsv07a_abteilung_leiter' ),
            ARRAY_A ) as $z ) {
            $uid = (int) $z['wp_user_id'];
            $u   = get_userdata( $uid );
            $leiter[ (int) $z['abteilung_id'] ][] = [
                'wp_user_id' => $uid,
                'name'       => $u ? $u->display_name : ( 'Konto ' . $uid . ' (gelöscht)' ),
                /* Ohne die Rolle „Abteilungsleiter" nützt die Zuordnung
                   nichts — die Verwaltung soll das sehen und nicht raten. */
                'hat_rolle'  => LSV07A_Rollen::ist_genau( 'leiter', $uid )
                                || LSV07A_Rollen::ist_admin_echt( $uid ),
            ];
        }

        $konten = [];
        foreach ( (array) $wpdb->get_results(
            "SELECT abteilung_id, COUNT(*) AS n FROM " . self::t( 'lsv07a_person' ) . "
              GROUP BY abteilung_id", ARRAY_A ) as $z ) {
            $konten[ (int) $z['abteilung_id'] ] = (int) $z['n'];
        }

        $out = [];
        foreach ( $zeilen as $z ) {
            $id = (int) $z['id'];
            $out[] = [
                'id'           => $id,
                'name'         => (string) $z['name'],
                'kurz'         => (string) $z['kurz'],
                'beschreibung' => (string) ( $z['beschreibung'] ?? '' ),
                'sortierung'   => (int) $z['sortierung'],
                'aktiv'        => (int) $z['aktiv'] === 1,
                'mannschaften' => $mann[ $id ] ?? [],
                'leiter'       => $leiter[ $id ] ?? [],
                'konten'       => $konten[ $id ] ?? 0,
            ];
        }
        return $out;
    }

    /** Nur Nummer und Name — für Auswahlfelder. */
    public static function auswahl() {
        global $wpdb;
        $zeilen = $wpdb->get_results(
            "SELECT id, name FROM " . self::t( 'lsv07a_abteilung' ) . "
              WHERE aktiv = 1 ORDER BY sortierung ASC, name ASC", ARRAY_A ) ?: [];
        return array_map( fn( $z ) => [ 'id' => (int) $z['id'], 'name' => $z['name'] ], $zeilen );
    }

    /**
     * Die zwei Quellen einer Mannschaft. Alles, was nicht 'eigen' heisst,
     * gilt als 'intern' — so bleibt eine unbekannte Angabe aus einer alten
     * Zeile beim bisherigen Verhalten.
     */
    public static function quelle( $wert ) {
        return (string) $wert === 'eigen' ? 'eigen' : 'intern';
    }

    /**
     * Welche Mannschaften gehören zu dieser Abteilung?
     *
     * Ohne Angabe nur die des internen Bereichs — so sehen alle Aufrufer,
     * die mit Trainings und Abrechnungen arbeiten, genau das, was sie
     * erwarten. 'eigen' oder 'alle' liefert die übrigen.
     */
    public static function mannschaften( $abteilung_id, $quelle = 'intern' ) {
        global $wpdb;
        $wo = $wpdb->prepare( 'abteilung_id = %d', (int) $abteilung_id );
        if ( $quelle !== 'alle' ) {
            $wo .= $wpdb->prepare( ' AND quelle = %s', self::quelle( $quelle ) );
        }
        $ids = $wpdb->get_col(
            "SELECT mannschaft_id FROM " . self::t( 'lsv07a_abteilung_mannschaft' ) . "
              WHERE $wo ORDER BY mannschaft_id ASC" ) ?: [];
        return array_map( 'intval', $ids );
    }

    /** Zu welcher Abteilung gehört diese Mannschaft? 0 = zu keiner. */
    public static function zu_mannschaft( $mannschaft_id, $quelle = 'intern' ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT abteilung_id FROM " . self::t( 'lsv07a_abteilung_mannschaft' ) . "
              WHERE mannschaft_id = %d AND quelle = %s",
            (int) $mannschaft_id, self::quelle( $quelle ) ) );
    }

    /** Die Abteilung eines Kontos. 0 = noch keiner zugeordnet. */
    public static function von_konto( $wp_user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT abteilung_id FROM " . self::t( 'lsv07a_person' ) . "
              WHERE wp_user_id = %d", (int) $wp_user_id ) );
    }

    /** Welche Abteilungen leitet diese Person? */
    public static function leitet( $wp_user_id ) {
        global $wpdb;
        $uid = (int) $wp_user_id;
        if ( isset( self::$cache_leitet[ $uid ] ) ) return self::$cache_leitet[ $uid ];
        if ( ! $uid ) return self::$cache_leitet[ $uid ] = [];
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT abteilung_id FROM " . self::t( 'lsv07a_abteilung_leiter' ) . "
              WHERE wp_user_id = %d", $uid ) ) ?: [];
        return self::$cache_leitet[ $uid ] = array_map( 'intval', $ids );
    }

    /** Die Leitung einer Abteilung — als Kontonummern. */
    public static function leitung( $abteilung_id ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM " . self::t( 'lsv07a_abteilung_leiter' ) . "
              WHERE abteilung_id = %d", (int) $abteilung_id ) ) ?: [];
        return array_map( 'intval', $ids );
    }

    /**
     * Die eine Frage: Darf diese Person über dieses Konto entscheiden?
     *
     * Sie steht vor jeder Genehmigung und vor jeder Rückgabe. Die
     * Administration darf immer — sonst käme ein Verein nicht weiter, dessen
     * Abteilungsleiter im Urlaub ist.
     */
    public static function darf_genehmigen( $wp_user_id_der_person, $leiter_uid = 0 ) {
        $leiter_uid = $leiter_uid ?: get_current_user_id();
        if ( LSV07A_Rollen::ist_admin( $leiter_uid ) ) return true;
        if ( ! LSV07A_Rollen::ist_genau( 'leiter', $leiter_uid ) ) return false;

        $abt = self::von_konto( $wp_user_id_der_person );
        if ( ! $abt ) return false;   // ohne Abteilung gibt es keinen Zuständigen
        return in_array( $abt, self::leitet( $leiter_uid ), true );
    }

    /**
     * Die Konten, über die diese Person entscheiden darf. null heisst
     * „alle" (Administration) — dann braucht eine Liste gar nicht zu
     * filtern.
     */
    public static function konten_im_zugriff( $leiter_uid = 0 ) {
        global $wpdb;
        $leiter_uid = $leiter_uid ?: get_current_user_id();
        if ( LSV07A_Rollen::ist_admin( $leiter_uid ) ) return null;

        $meine = self::leitet( $leiter_uid );
        if ( ! $meine ) return [];
        $platz = implode( ',', array_fill( 0, count( $meine ), '%d' ) );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM " . self::t( 'lsv07a_person' ) . "
              WHERE abteilung_id IN ($platz)", $meine ) ) ?: [];
        return array_map( 'intval', $ids );
    }

    /** Lesbar: „Schwimmen, Wasserball" oder „keine Abteilung". */
    public static function beschriftung( $wp_user_id = 0 ) {
        $uid = $wp_user_id ?: get_current_user_id();
        $ids = self::leitet( $uid );
        if ( ! $ids ) return 'keine Abteilung';
        $namen = [];
        foreach ( self::alle() as $a ) {
            if ( in_array( (int) $a['id'], $ids, true ) ) $namen[] = $a['name'];
        }
        return $namen ? implode( ', ', $namen ) : 'keine Abteilung';
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /** Anlegen. Gibt die Nummer zurück oder einen Text mit dem Grund. */
    public static function anlegen( array $f ) {
        global $wpdb;
        $name = trim( (string) ( $f['name'] ?? '' ) );
        if ( $name === '' ) return 'Ohne Namen keine Abteilung.';
        if ( mb_strlen( $name ) > 120 ) $name = mb_substr( $name, 0, 120 );

        if ( self::name_vergeben( $name, 0 ) ) {
            return 'Eine Abteilung mit diesem Namen gibt es schon.';
        }

        $wpdb->insert( self::t( 'lsv07a_abteilung' ), [
            'name'         => $name,
            'kurz'         => mb_substr( trim( (string) ( $f['kurz'] ?? '' ) ), 0, 20 ),
            'beschreibung' => (string) ( $f['beschreibung'] ?? '' ),
            'sortierung'   => max( 0, min( 9999, (int) ( $f['sortierung'] ?? 0 ) ) ),
            'aktiv'        => empty( $f['aktiv'] ) && isset( $f['aktiv'] ) ? 0 : 1,
            'erstellt_am'  => current_time( 'mysql' ),
        ], [ '%s', '%s', '%s', '%d', '%d', '%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( ! $id ) return 'Die Abteilung liess sich nicht anlegen.';
        LSV07A_Log::schreibe( 'abteilung.neu',
            [ 'ziel_typ' => 'abteilung', 'ziel_id' => $id, 'details' => $name ] );
        return $id;
    }

    public static function aendern( $id, array $f ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::holen( $id ) ) return 'Diese Abteilung gibt es nicht.';

        $daten = [];
        $form  = [];
        if ( isset( $f['name'] ) ) {
            $name = trim( (string) $f['name'] );
            if ( $name === '' ) return 'Ohne Namen keine Abteilung.';
            if ( self::name_vergeben( $name, $id ) ) {
                return 'Eine Abteilung mit diesem Namen gibt es schon.';
            }
            $daten['name'] = mb_substr( $name, 0, 120 ); $form[] = '%s';
        }
        if ( isset( $f['kurz'] ) ) {
            $daten['kurz'] = mb_substr( trim( (string) $f['kurz'] ), 0, 20 ); $form[] = '%s';
        }
        if ( isset( $f['beschreibung'] ) ) {
            $daten['beschreibung'] = (string) $f['beschreibung']; $form[] = '%s';
        }
        if ( isset( $f['sortierung'] ) ) {
            $daten['sortierung'] = max( 0, min( 9999, (int) $f['sortierung'] ) ); $form[] = '%d';
        }
        if ( isset( $f['aktiv'] ) ) {
            $daten['aktiv'] = empty( $f['aktiv'] ) ? 0 : 1; $form[] = '%d';
        }
        if ( ! $daten ) return true;

        $wpdb->update( self::t( 'lsv07a_abteilung' ), $daten, [ 'id' => $id ], $form, [ '%d' ] );
        LSV07A_Log::schreibe( 'abteilung.geaendert',
            [ 'ziel_typ' => 'abteilung', 'ziel_id' => $id ] );
        return true;
    }

    private static function name_vergeben( $name, $ausser ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t( 'lsv07a_abteilung' ) . "
              WHERE name = %s AND id <> %d", $name, (int) $ausser ) ) > 0;
    }

    /**
     * Löschen — nur, solange nichts daran hängt.
     *
     * Hängen Konten oder Rechnungen daran, würden sie verwaisen: Eine
     * Rechnung ohne Abteilung hätte keinen Zuständigen mehr, und niemand
     * könnte sie noch genehmigen. Dann wird abgeschaltet statt gelöscht —
     * das hält die Geschichte zusammen.
     */
    public static function loeschen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::holen( $id ) ) return 'Diese Abteilung gibt es nicht.';

        $konten = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t( 'lsv07a_person' ) . " WHERE abteilung_id = %d", $id ) );
        if ( $konten ) {
            return 'An dieser Abteilung hängen noch ' . $konten . ' Konten. Ordne sie zuerst '
                 . 'einer anderen Abteilung zu — oder schalte diese hier ab, dann bleibt alles '
                 . 'erhalten.';
        }
        $rechnungen = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t( 'lsv07a_rechnung' ) . " WHERE abteilung_id = %d", $id ) );
        if ( $rechnungen ) {
            return 'Zu dieser Abteilung gehören noch ' . $rechnungen . ' Rechnungen. Sie würden '
                 . 'ohne Zuständigen zurückbleiben. Schalte die Abteilung stattdessen ab.';
        }

        $wpdb->delete( self::t( 'lsv07a_abteilung_mannschaft' ), [ 'abteilung_id' => $id ], [ '%d' ] );
        $wpdb->delete( self::t( 'lsv07a_abteilung_leiter' ), [ 'abteilung_id' => $id ], [ '%d' ] );
        $wpdb->delete( self::t( 'lsv07a_abteilung' ), [ 'id' => $id ], [ '%d' ] );
        LSV07A_Log::schreibe( 'abteilung.weg', [ 'ziel_typ' => 'abteilung', 'ziel_id' => $id ] );
        return true;
    }

    /**
     * Die Mannschaften einer Abteilung neu setzen.
     *
     * Eine leere Liste ist erlaubt und bedeutet genau das: Diese Abteilung
     * hat keine Mannschaften. Eine Mannschaft, die schon zu einer anderen
     * Abteilung gehört, wird dorthin umgehängt — und das wird gemeldet,
     * damit niemand sucht, warum sie woanders verschwunden ist.
     */
    public static function mannschaften_setzen( $abteilung_id, array $mannschaft_ids ) {
        global $wpdb;
        $id = (int) $abteilung_id;
        if ( ! self::holen( $id ) ) return 'Diese Abteilung gibt es nicht.';

        /* Angenommen wird „quelle:nummer" („intern:3", „eigen:7"). Eine nackte
           Nummer gilt als intern — so arbeiten ältere Aufrufer weiter. */
        $bekannt = [ 'intern' => [], 'eigen' => [] ];
        /* Nur Mannschaften, die es wirklich gibt. Ist der interne Bereich
           nicht erreichbar, wird für ihn nichts geprüft und nichts verworfen
           — sonst löschte ein Ausfall des internen Bereichs die Zuordnung.
           Die eigenen stehen in der eigenen Tabelle und sind immer da. */
        foreach ( LSV07A_Intern::mannschaften() as $m ) $bekannt['intern'][ (int) $m['id'] ] = true;
        foreach ( LSV07A_Mannschaft::alle() as $m ) $bekannt['eigen'][ (int) $m['id'] ] = true;

        $neu = [];
        foreach ( $mannschaft_ids as $roh ) {
            $roh = (string) $roh;
            $q   = 'intern';
            if ( strpos( $roh, ':' ) !== false ) {
                [ $q, $roh ] = explode( ':', $roh, 2 );
                $q = self::quelle( $q );
            }
            $mid = (int) $roh;
            if ( $mid <= 0 ) continue;
            if ( $q === 'eigen' ) {
                if ( ! isset( $bekannt['eigen'][ $mid ] ) ) continue;
            } elseif ( $bekannt['intern'] && ! isset( $bekannt['intern'][ $mid ] ) ) {
                continue;
            }
            $neu[ $q . ':' . $mid ] = [ 'quelle' => $q, 'id' => $mid ];
        }

        $wpdb->delete( self::t( 'lsv07a_abteilung_mannschaft' ), [ 'abteilung_id' => $id ], [ '%d' ] );
        $umgehaengt = [];
        foreach ( $neu as $eintrag ) {
            $vorher = self::zu_mannschaft( $eintrag['id'], $eintrag['quelle'] );
            if ( $vorher && $vorher !== $id ) {
                $umgehaengt[] = $eintrag['quelle'] . ':' . $eintrag['id'];
                $wpdb->delete( self::t( 'lsv07a_abteilung_mannschaft' ),
                    [ 'mannschaft_id' => $eintrag['id'], 'quelle' => $eintrag['quelle'] ],
                    [ '%d', '%s' ] );
            }
            $wpdb->insert( self::t( 'lsv07a_abteilung_mannschaft' ), [
                'abteilung_id'  => $id,
                'mannschaft_id' => $eintrag['id'],
                'quelle'        => $eintrag['quelle'],
                'erstellt_am'   => current_time( 'mysql' ),
            ], [ '%d', '%d', '%s', '%s' ] );
        }

        LSV07A_Log::schreibe( 'abteilung.mannschaften', [
            'ziel_typ' => 'abteilung', 'ziel_id' => $id,
            'details'  => count( $neu ) . ' Mannschaften' ] );
        return [ 'anzahl' => count( $neu ), 'umgehaengt' => $umgehaengt ];
    }

    /** Die Leitung neu setzen. */
    public static function leitung_setzen( $abteilung_id, array $uids ) {
        global $wpdb;
        $id = (int) $abteilung_id;
        if ( ! self::holen( $id ) ) return 'Diese Abteilung gibt es nicht.';

        $wpdb->delete( self::t( 'lsv07a_abteilung_leiter' ), [ 'abteilung_id' => $id ], [ '%d' ] );
        $gesetzt = [];
        foreach ( $uids as $uid ) {
            $uid = (int) $uid;
            if ( $uid <= 0 || isset( $gesetzt[ $uid ] ) ) continue;
            $gesetzt[ $uid ] = true;
            $wpdb->insert( self::t( 'lsv07a_abteilung_leiter' ),
                [ 'abteilung_id' => $id, 'wp_user_id' => $uid, 'erstellt_am' => current_time( 'mysql' ) ],
                [ '%d', '%d', '%s' ] );
            unset( self::$cache_leitet[ $uid ] );
        }
        self::$cache_leitet = [];

        LSV07A_Log::schreibe( 'abteilung.leitung', [
            'ziel_typ' => 'abteilung', 'ziel_id' => $id,
            'details'  => count( $gesetzt ) . ' Leitungen' ] );
        return count( $gesetzt );
    }

    /** Ein Konto einer Abteilung zuordnen. 0 nimmt die Zuordnung weg. */
    public static function konto_setzen( $wp_user_id, $abteilung_id ) {
        global $wpdb;
        $uid = (int) $wp_user_id;
        $aid = (int) $abteilung_id;
        if ( $aid && ! self::holen( $aid ) ) return 'Diese Abteilung gibt es nicht.';

        LSV07A_Person::sicherstellen( $uid );
        $wpdb->update( self::t( 'lsv07a_person' ), [ 'abteilung_id' => $aid ],
            [ 'wp_user_id' => $uid ], [ '%d' ], [ '%d' ] );
        LSV07A_Log::schreibe( 'abteilung.konto', [
            'ziel_typ' => 'person', 'ziel_id' => $uid,
            'details'  => $aid ? self::name( $aid ) : 'keine Abteilung' ] );
        return true;
    }

    /**
     * Ein Vorschlag, welche Abteilung zu einem Konto passt — aus den
     * Mannschaften, in denen die Person bisher abgerechnet hat.
     *
     * Gedacht für die erste Einrichtung: Nach dem Update steht jedes Konto
     * auf „keine Abteilung", und die Verwaltung müsste hundert Zuordnungen
     * von Hand treffen. Vorgeschlagen wird nur; zugeordnet wird bewusst.
     */
    public static function vorschlag( $wp_user_id ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.mannschaft_id
               FROM " . self::t( 'lsv07a_abrechnung' ) . " a
               JOIN " . self::t( 'lsv07a_posten' ) . " p ON p.abrechnung_id = a.id
              WHERE a.wp_user_id = %d AND p.mannschaft_id > 0", (int) $wp_user_id ) ) ?: [];

        $zahl = [];
        foreach ( $ids as $mid ) {
            $abt = self::zu_mannschaft( (int) $mid );
            if ( $abt ) $zahl[ $abt ] = ( $zahl[ $abt ] ?? 0 ) + 1;
        }
        if ( ! $zahl ) return 0;
        arsort( $zahl );
        return (int) array_key_first( $zahl );
    }
}
