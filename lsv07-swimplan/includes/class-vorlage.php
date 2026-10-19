<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Übungsbibliothek.
 *
 * Eine Vorlage ist eine Folge von Sets mit einem Namen — „Technikblock
 * Brust", „Einschwimmen kurz", „Hauptserie 8 × 100". Der Trainer legt sie
 * einmal an und fügt sie danach in jeden Plan ein, in dem er sie braucht.
 *
 * Die eine Festlegung, die das Konzept ausdrücklich verlangt:
 *
 *   **Beim Einfügen werden die Sets KOPIERT.**
 *
 * Es gibt also keine Verbindung zwischen dem Set im Plan und dem Set in der
 * Vorlage. Wer die Vorlage später ändert, ändert damit keinen einzigen
 * bereits erstellten oder veröffentlichten Plan. Mit einem Verweis statt
 * einer Kopie hätte sich der Plan, den die Schwimmer gestern gelesen haben,
 * heute still verändert — und genau das darf nicht passieren.
 *
 * Der Preis der Kopie: Eine verbesserte Vorlage verbessert keinen alten
 * Plan. Das ist der richtige Preis.
 */
class LSV07P_Vorlage {

    /** Vorschläge für das Feld Kategorie. Freitext bleibt möglich. */
    const KATEGORIEN = [
        'Einschwimmen', 'Technik', 'Hauptserie', 'Beine', 'Arme',
        'Sprint', 'Ausdauer', 'Starts und Wenden', 'Ausschwimmen',
    ];

    // ── Lesen ───────────────────────────────────────────────────────────

    public static function holen( $id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_vorlage WHERE id = %d", (int) $id ), ARRAY_A );
    }

    public static function sets( $vorlage_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_vorlage_set WHERE vorlage_id = %d
           ORDER BY position ASC, id ASC", (int) $vorlage_id ), ARRAY_A ) ?: [];
    }

    /**
     * Die Vorlagen, die das angemeldete Konto benutzen darf: die eigenen
     * und die, die jemand für alle freigegeben hat. Die Verwaltung sieht
     * alle.
     */
    public static function liste( $kategorie = '', $suche = '' ) {
        global $wpdb;
        if ( ! LSV07P_Rollen::darf_planen() ) return [];

        $p    = $wpdb->prefix;
        $wo   = [];
        $args = [];

        if ( ! LSV07P_Rollen::ist_admin() ) {
            $wo[]   = '(erstellt_von = %d OR oeffentlich = 1)';
            $args[] = LSV07P_Rollen::user_id();
        }
        if ( $kategorie !== '' ) {
            $wo[]   = 'kategorie = %s';
            $args[] = LSV07P_Plan::text( $kategorie, 60 );
        }
        if ( trim( (string) $suche ) !== '' ) {
            $wo[]   = '(name LIKE %s OR beschreibung LIKE %s)';
            $wie    = '%' . $wpdb->esc_like( LSV07P_Plan::text( $suche, 80 ) ) . '%';
            $args[] = $wie;
            $args[] = $wie;
        }

        $sql = "SELECT * FROM {$p}lsv07p_vorlage";
        if ( $wo ) $sql .= ' WHERE ' . implode( ' AND ', $wo );
        $sql .= ' ORDER BY kategorie ASC, name ASC';

        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A )
                      : $wpdb->get_results( $sql, ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $sets = self::sets( (int) $r['id'] );
            /* Zum Rechnen eine 25-m-Bahn annehmen: Eine Vorlage gehört zu
               keinem Becken, erst der Plan hat eine Bahnlänge. */
            $re = LSV07P_Rechnung::plan( [ 'bahnlaenge' => 25, 'zusatz_min' => 0 ], $sets );
            $out[] = [
                'id'          => (int) $r['id'],
                'name'        => (string) $r['name'],
                'beschreibung' => (string) ( $r['beschreibung'] ?? '' ),
                'kategorie'   => (string) $r['kategorie'],
                'oeffentlich' => (int) $r['oeffentlich'] === 1,
                'eigen'       => (int) $r['erstellt_von'] === LSV07P_Rollen::user_id(),
                'ersteller'   => LSV07P_Rollen::name( (int) $r['erstellt_von'] ),
                'sets'        => count( $sets ),
                'meter'       => $re['meter'],
                'meter_text'  => LSV07P_Rechnung::meter_text( $re['meter'] ),
                'dauer_text'  => $re['dauer_text'],
            ];
        }
        return $out;
    }

    /** Eine Vorlage samt Sets, für die Vorschau vor dem Einfügen. */
    public static function voll( $id ) {
        $v = self::holen( $id );
        if ( ! $v ) return null;

        $sets = self::sets( $id );
        $re   = LSV07P_Rechnung::plan( [ 'bahnlaenge' => 25, 'zusatz_min' => 0 ], $sets );

        $zeilen = [];
        foreach ( $sets as $i => $s ) {
            $rr = $re['zeilen'][ $i ] ?? [];
            $zeilen[] = [
                'id'       => (int) $s['id'],
                'position' => (int) $s['position'],
                'titel'    => (string) $s['titel'],
                'wiederholungen' => (int) $s['wiederholungen'],
                'strecke_m' => (int) $s['strecke_m'],
                'lage'     => (string) $s['lage'],
                'lage_name' => LSV07P_Rechnung::lage_name( $s['lage'] ),
                'intervall_sek' => (int) $s['intervall_sek'],
                'pause_sek' => (int) $s['pause_sek'],
                'material' => (string) $s['material'],
                'hinweis'  => (string) ( $s['hinweis'] ?? '' ),
                'meter'    => $rr['meter'] ?? 0,
                'kurz'     => $rr['text'] ?? '',
            ];
        }

        return [
            'id'          => (int) $v['id'],
            'name'        => (string) $v['name'],
            'beschreibung' => (string) ( $v['beschreibung'] ?? '' ),
            'kategorie'   => (string) $v['kategorie'],
            'oeffentlich' => (int) $v['oeffentlich'] === 1,
            'eigen'       => (int) $v['erstellt_von'] === LSV07P_Rollen::user_id(),
            'ersteller'   => LSV07P_Rollen::name( (int) $v['erstellt_von'] ),
            'meter'       => $re['meter'],
            'meter_text'  => LSV07P_Rechnung::meter_text( $re['meter'] ),
            'dauer_text'  => $re['dauer_text'],
            'sets'        => $zeilen,
        ];
    }

    // ── Rechte ──────────────────────────────────────────────────────────

    /** Benutzen darf man die eigenen und die öffentlichen. */
    public static function darf_sehen( $id ) {
        if ( ! LSV07P_Rollen::darf_planen() ) return false;
        $v = self::holen( $id );
        if ( ! $v ) return false;
        if ( LSV07P_Rollen::ist_admin() ) return true;
        if ( (int) $v['oeffentlich'] === 1 ) return true;
        return (int) $v['erstellt_von'] === LSV07P_Rollen::user_id();
    }

    /**
     * Ändern darf nur, wer sie angelegt hat (und die Verwaltung). Eine
     * öffentliche Vorlage ist zum Benutzen freigegeben, nicht zum
     * Umschreiben — sonst findet der Ersteller seine Serie nicht wieder.
     */
    public static function darf_aendern( $id ) {
        if ( ! LSV07P_Rollen::darf_planen() ) return false;
        $v = self::holen( $id );
        if ( ! $v ) return false;
        if ( LSV07P_Rollen::ist_admin() ) return true;
        return (int) $v['erstellt_von'] === LSV07P_Rollen::user_id();
    }

    // ── Schreiben ───────────────────────────────────────────────────────

    public static function anlegen( array $f ) {
        global $wpdb;
        if ( ! LSV07P_Rollen::darf_planen() ) return 0;

        $name = LSV07P_Plan::text( $f['name'] ?? '', 200 );
        if ( $name === '' ) return 0;

        $wpdb->insert( $wpdb->prefix . 'lsv07p_vorlage', [
            'erstellt_von' => LSV07P_Rollen::user_id(),
            'name'         => $name,
            'beschreibung' => LSV07P_Plan::mehrzeilig( $f['beschreibung'] ?? '', 2000 ),
            'kategorie'    => LSV07P_Plan::text( $f['kategorie'] ?? '', 60 ),
            'oeffentlich'  => empty( $f['oeffentlich'] ) ? 0 : 1,
            'erstellt_am'  => current_time( 'mysql' ),
            'geaendert_am' => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ] );

        $id = (int) $wpdb->insert_id;
        if ( $id ) LSV07P_DB::spur( 'vorlage.neu', $id, $name );
        return $id;
    }

    public static function aendern( $id, array $f ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::holen( $id ) ) return false;

        $daten = [];
        $form  = [];
        if ( isset( $f['name'] ) ) {
            $n = LSV07P_Plan::text( $f['name'], 200 );
            if ( $n === '' ) return false;
            $daten['name'] = $n; $form[] = '%s';
        }
        if ( isset( $f['beschreibung'] ) ) {
            $daten['beschreibung'] = LSV07P_Plan::mehrzeilig( $f['beschreibung'], 2000 );
            $form[] = '%s';
        }
        if ( isset( $f['kategorie'] ) ) {
            $daten['kategorie'] = LSV07P_Plan::text( $f['kategorie'], 60 ); $form[] = '%s';
        }
        if ( isset( $f['oeffentlich'] ) ) {
            $daten['oeffentlich'] = $f['oeffentlich'] ? 1 : 0; $form[] = '%d';
        }
        if ( ! $daten ) return true;

        $daten['geaendert_am'] = current_time( 'mysql' );
        $form[] = '%s';
        $wpdb->update( $wpdb->prefix . 'lsv07p_vorlage', $daten, [ 'id' => $id ], $form, [ '%d' ] );
        LSV07P_DB::spur( 'vorlage.aendern', $id, implode( ', ', array_keys( $daten ) ) );
        return true;
    }

    /**
     * Eine Vorlage löschen rührt keinen Plan an — die Sets dort sind
     * Kopien und stehen für sich.
     */
    public static function loeschen( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! self::holen( $id ) ) return false;
        $p = $wpdb->prefix;
        $wpdb->delete( $p . 'lsv07p_vorlage_set', [ 'vorlage_id' => $id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07p_vorlage',     [ 'id' => $id ],         [ '%d' ] );
        LSV07P_DB::spur( 'vorlage.weg', $id, '' );
        return true;
    }

    public static function set_anlegen( $vorlage_id, array $f ) {
        global $wpdb;
        $vorlage_id = (int) $vorlage_id;
        if ( ! self::holen( $vorlage_id ) ) return 0;

        $p   = $wpdb->prefix;
        $pos = isset( $f['position'] )
            ? LSV07P_Plan::zahl( $f['position'], 0, 999 )
            : 1 + (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(MAX(position), 0) FROM {$p}lsv07p_vorlage_set
                  WHERE vorlage_id = %d", $vorlage_id ) );

        $wpdb->insert( $p . 'lsv07p_vorlage_set', [
            'vorlage_id'    => $vorlage_id,
            'position'      => $pos,
            'titel'         => LSV07P_Plan::text( $f['titel'] ?? '', 200 ),
            'wiederholungen' => LSV07P_Plan::zahl( $f['wiederholungen'] ?? 1, 1, 99 ),
            'strecke_m'     => LSV07P_Plan::zahl( $f['strecke_m'] ?? 0, 0, 5000 ),
            'lage'          => LSV07P_Plan::lage( $f['lage'] ?? 'frei' ),
            'intervall_sek' => LSV07P_Plan::zahl( $f['intervall_sek'] ?? 0, 0, 3600 ),
            'pause_sek'     => LSV07P_Plan::zahl( $f['pause_sek'] ?? 0, 0, 3600 ),
            'material'      => LSV07P_Plan::text( $f['material'] ?? '', 200 ),
            'hinweis'       => LSV07P_Plan::mehrzeilig( $f['hinweis'] ?? '', 1000 ),
        ], [ '%d','%d','%s','%d','%d','%s','%d','%d','%s','%s' ] );

        return (int) $wpdb->insert_id;
    }

    public static function set_holen( $set_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07p_vorlage_set WHERE id = %d", (int) $set_id ), ARRAY_A );
    }

    public static function set_aendern( $set_id, array $f ) {
        global $wpdb;
        if ( ! self::set_holen( $set_id ) ) return false;

        $daten = [];
        $form  = [];
        $zahlen = [
            'wiederholungen' => [ 1, 99 ], 'strecke_m' => [ 0, 5000 ],
            'intervall_sek'  => [ 0, 3600 ], 'pause_sek' => [ 0, 3600 ],
            'position'       => [ 0, 999 ],
        ];
        foreach ( $zahlen as $name => $g ) {
            if ( ! isset( $f[ $name ] ) ) continue;
            $daten[ $name ] = LSV07P_Plan::zahl( $f[ $name ], $g[0], $g[1] );
            $form[] = '%d';
        }
        foreach ( [ 'titel' => 200, 'material' => 200 ] as $name => $max ) {
            if ( ! isset( $f[ $name ] ) ) continue;
            $daten[ $name ] = LSV07P_Plan::text( $f[ $name ], $max );
            $form[] = '%s';
        }
        if ( isset( $f['hinweis'] ) ) {
            $daten['hinweis'] = LSV07P_Plan::mehrzeilig( $f['hinweis'], 1000 ); $form[] = '%s';
        }
        if ( isset( $f['lage'] ) ) {
            $daten['lage'] = LSV07P_Plan::lage( $f['lage'] ); $form[] = '%s';
        }
        if ( ! $daten ) return true;

        $wpdb->update( $wpdb->prefix . 'lsv07p_vorlage_set', $daten,
            [ 'id' => (int) $set_id ], $form, [ '%d' ] );
        return true;
    }

    public static function set_loeschen( $set_id ) {
        global $wpdb;
        if ( ! self::set_holen( $set_id ) ) return false;
        $wpdb->delete( $wpdb->prefix . 'lsv07p_vorlage_set',
            [ 'id' => (int) $set_id ], [ '%d' ] );
        return true;
    }

    public static function sets_ordnen( $vorlage_id, array $ids ) {
        global $wpdb;
        $erlaubt = [];
        foreach ( self::sets( $vorlage_id ) as $s ) $erlaubt[ (int) $s['id'] ] = true;

        $pos = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( ! isset( $erlaubt[ $id ] ) ) continue;
            $pos++;
            $wpdb->update( $wpdb->prefix . 'lsv07p_vorlage_set', [ 'position' => $pos ],
                [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        }
        return true;
    }

    // ── Einfügen und Abnehmen ───────────────────────────────────────────

    /**
     * Die Sets einer Vorlage hinten an einen Plan hängen — als Kopie.
     *
     * Hier steht der Satz aus dem Konzept in Code: Es wird nichts verknüpft,
     * es wird abgeschrieben. Danach weiss der Plan nicht mehr, woher seine
     * Sets kommen, und die Vorlage nicht, wohin sie gegangen sind.
     *
     * Gibt die Anzahl der eingefügten Sets zurück.
     */
    public static function einfuegen( $vorlage_id, $plan_id ) {
        $vorlage_id = (int) $vorlage_id;
        $plan_id    = (int) $plan_id;
        if ( ! self::holen( $vorlage_id ) ) return 0;
        if ( ! LSV07P_Plan::holen( $plan_id ) ) return 0;

        $n = 0;
        foreach ( self::sets( $vorlage_id ) as $s ) {
            $neu = LSV07P_Plan::set_anlegen( $plan_id, [
                'titel'          => $s['titel'],
                'wiederholungen' => $s['wiederholungen'],
                'strecke_m'      => $s['strecke_m'],
                'lage'           => $s['lage'],
                'intervall_sek'  => $s['intervall_sek'],
                'pause_sek'      => $s['pause_sek'],
                'material'       => $s['material'],
                'hinweis'        => $s['hinweis'],
            ], false );
            if ( $neu ) $n++;
        }

        if ( $n ) {
            LSV07P_Plan::nach_aenderung( $plan_id, 'Vorlage eingefügt (' . $n . ' Sets)' );
            LSV07P_DB::spur( 'vorlage.einfuegen', $plan_id, 'Vorlage ' . $vorlage_id );
        }
        return $n;
    }

    /**
     * Aus einem Plan eine Vorlage machen — der umgekehrte Weg. Auch das
     * kopiert; der Plan bleibt, wie er ist.
     */
    public static function aus_plan( $plan_id, $name, $kategorie = '', $oeffentlich = false ) {
        $plan = LSV07P_Plan::holen( $plan_id );
        if ( ! $plan ) return 0;

        $id = self::anlegen( [
            'name'        => $name !== '' ? $name : $plan['titel'],
            'beschreibung' => 'Aus dem Training vom ' . LSV07P_Plan::datum_lang( $plan['datum'] ),
            'kategorie'   => $kategorie,
            'oeffentlich' => $oeffentlich,
        ] );
        if ( ! $id ) return 0;

        foreach ( LSV07P_Plan::sets( $plan_id ) as $s ) {
            self::set_anlegen( $id, $s );
        }
        LSV07P_DB::spur( 'vorlage.aus_plan', $id, 'Plan ' . (int) $plan_id );
        return $id;
    }
}
