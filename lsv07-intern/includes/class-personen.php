<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * LSV07I_Personen
 * ----------------
 * Zentrale CRUD-API für die Personen-Verwaltung.
 *
 * Diese Klasse arbeitet auf der neuen Tabelle lsv07i_personen + ihren
 * M:N-Tabellen lsv07i_personen_sparten_rolle und lsv07i_personen_mannschaft.
 *
 * In Iteration 1 ist sie nur als Service-Klasse mit statischen Methoden
 * verfügbar — ohne eigene UI. Iteration 2 wird die AJAX-Endpoints und
 * das Admin-UI ergänzen.
 *
 * KONVENTIONEN:
 *   Sparten: 'schwimmen' | 'triathlon' | 'fitness'
 *   Rollen:  'sportler'  | 'trainer'
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Personen {

    const SPARTEN = [ 'schwimmen', 'triathlon', 'fitness' ];
    const ROLLEN  = [ 'sportler', 'trainer' ];

    // ─────────────────────────────────────────────────────────────────────────
    //  ABFRAGEN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Eine Person samt aller Zuordnungen laden.
     */
    public static function get( $person_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $person_id = (int) $person_id;
        if ( ! $person_id ) return null;

        $person = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}lsv07i_personen WHERE id = %d LIMIT 1",
            $person_id
        ), ARRAY_A );
        if ( ! $person ) return null;

        $person['sparten_rollen'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT sparte, rolle, aktiv FROM {$p}lsv07i_personen_sparten_rolle
              WHERE person_id = %d", $person_id
        ), ARRAY_A );

        $person['mannschaften'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT sparte, mannschaft_id, rolle FROM {$p}lsv07i_personen_mannschaft
              WHERE person_id = %d", $person_id
        ), ARRAY_A );

        return $person;
    }

    /**
     * Personen-Liste mit optionalen Filtern.
     *
     * @param array $args {
     *     'sparte'   => 'schwimmen'|'triathlon'|'fitness'|null
     *     'rolle'    => 'sportler'|'trainer'|null
     *     'mannschaft_id' => int|null
     *     'aktiv'    => bool|null   (default: nur aktive)
     *     'suche'    => string      (Vor- oder Nachname)
     *     'limit'    => int         (default 1000)
     *     'offset'   => int
     * }
     */
    public static function liste( $args = [] ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $sparte    = $args['sparte']    ?? null;
        $rolle     = $args['rolle']     ?? null;
        $mann_id   = isset( $args['mannschaft_id'] ) ? (int) $args['mannschaft_id'] : null;
        $aktiv     = $args['aktiv']     ?? true;
        $suche     = trim( (string) ( $args['suche'] ?? '' ) );
        $limit     = max( 1, min( 5000, (int) ( $args['limit'] ?? 1000 ) ) );
        $offset    = max( 0, (int) ( $args['offset'] ?? 0 ) );

        $joins  = '';
        $where  = [ '1=1' ];

        if ( $sparte && in_array( $sparte, self::SPARTEN, true ) ) {
            $joins .= " JOIN {$p}lsv07i_personen_sparten_rolle psr ON psr.person_id = p.id ";
            $where[] = $wpdb->prepare( 'psr.sparte = %s AND psr.aktiv = 1', $sparte );
            if ( $rolle && in_array( $rolle, self::ROLLEN, true ) ) {
                $where[] = $wpdb->prepare( 'psr.rolle = %s', $rolle );
            }
        } elseif ( $rolle && in_array( $rolle, self::ROLLEN, true ) ) {
            // Rolle ohne Sparte → über alle Sparten suchen
            $joins .= " JOIN {$p}lsv07i_personen_sparten_rolle psr ON psr.person_id = p.id ";
            $where[] = $wpdb->prepare( 'psr.rolle = %s AND psr.aktiv = 1', $rolle );
        }

        if ( $mann_id !== null ) {
            $joins .= " JOIN {$p}lsv07i_personen_mannschaft pm ON pm.person_id = p.id ";
            $where[] = $wpdb->prepare( 'pm.mannschaft_id = %d', $mann_id );
            if ( $sparte ) $where[] = $wpdb->prepare( 'pm.sparte = %s', $sparte );
        }

        if ( $aktiv === true )       $where[] = 'p.aktiv = 1';
        elseif ( $aktiv === false )  $where[] = 'p.aktiv = 0';

        if ( $suche !== '' ) {
            $like = '%' . $wpdb->esc_like( $suche ) . '%';
            $where[] = $wpdb->prepare(
                '(p.vorname LIKE %s OR p.nachname LIKE %s OR p.email LIKE %s OR p.dsv_id LIKE %s)',
                $like, $like, $like, $like
            );
        }

        $sql = "SELECT DISTINCT p.* FROM {$p}lsv07i_personen p
                $joins
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY p.nachname ASC, p.vorname ASC
                LIMIT $offset, $limit";

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  SCHREIBEN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Speichert eine Person (insert oder update).
     *
     * @param array      $data   Feldwerte (vorname, nachname, ...)
     * @param int|null   $id     Wenn gesetzt → Update, sonst Insert
     * @return int|WP_Error      Person-ID bei Erfolg
     */
    public static function speichern( $data, $id = null ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $vor = trim( (string) ( $data['vorname'] ?? '' ) );
        $nach = trim( (string) ( $data['nachname'] ?? '' ) );
        if ( $vor === '' || $nach === '' ) {
            return new WP_Error( 'name_pflicht', 'Vor- und Nachname sind Pflicht.' );
        }

        $payload = [
            'vorname'         => $vor,
            'nachname'        => $nach,
            'geburtsdatum'    => self::clean_date( $data['geburtsdatum'] ?? '' ),
            'geschlecht'      => self::clean_gender( $data['geschlecht'] ?? '' ),
            'email'           => sanitize_email( $data['email'] ?? '' ),
            'telefon'         => sanitize_text_field( $data['telefon'] ?? '' ),
            'dsv_id'          => sanitize_text_field( $data['dsv_id'] ?? '' ),
            'mitgliedsnummer' => sanitize_text_field( $data['mitgliedsnummer'] ?? '' ),
            'wp_user_id'      => (int) ( $data['wp_user_id'] ?? 0 ),
            'notes'           => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : null,
            'aktiv'           => isset( $data['aktiv'] ) ? ( $data['aktiv'] ? 1 : 0 ) : 1,
        ];

        if ( $id ) {
            $ok = $wpdb->update( $p . 'lsv07i_personen', $payload, [ 'id' => (int) $id ] );
            if ( $ok === false ) {
                return new WP_Error( 'db', 'Speichern fehlgeschlagen'
                    . ( $wpdb->last_error ? ': ' . $wpdb->last_error : '.' ) );
            }
            return (int) $id;
        } else {
            $ok = $wpdb->insert( $p . 'lsv07i_personen', $payload );
            if ( $ok === false ) {
                return new WP_Error( 'db', 'Anlegen fehlgeschlagen'
                    . ( $wpdb->last_error ? ': ' . $wpdb->last_error : '.' ) );
            }
            return (int) $wpdb->insert_id;
        }
    }

    /**
     * Setzt die Sparten/Rollen-Zuordnungen einer Person komplett neu.
     *
     * @param int   $person_id
     * @param array $sparten_rollen  Liste von ['sparte'=>..,'rolle'=>..]
     */
    public static function set_sparten_rollen( $person_id, $sparten_rollen ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $person_id = (int) $person_id;
        if ( ! $person_id ) return false;

        $wpdb->delete( $p . 'lsv07i_personen_sparten_rolle',
            [ 'person_id' => $person_id ], [ '%d' ] );

        foreach ( (array) $sparten_rollen as $sr ) {
            $sparte = $sr['sparte'] ?? '';
            $rolle  = $sr['rolle']  ?? '';
            if ( ! in_array( $sparte, self::SPARTEN, true ) ) continue;
            if ( ! in_array( $rolle,  self::ROLLEN,  true ) ) continue;
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$p}lsv07i_personen_sparten_rolle
                    (person_id, sparte, rolle, aktiv)
                 VALUES (%d, %s, %s, 1)",
                $person_id, $sparte, $rolle
            ) );
        }
        return true;
    }

    /**
     * Setzt die Mannschafts-Zuordnungen einer Person komplett neu.
     *
     * @param int   $person_id
     * @param array $mannschaften Liste von ['sparte'=>..,'mannschaft_id'=>..,'rolle'=>..]
     */
    public static function set_mannschaften( $person_id, $mannschaften ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $person_id = (int) $person_id;
        if ( ! $person_id ) return false;

        $wpdb->delete( $p . 'lsv07i_personen_mannschaft',
            [ 'person_id' => $person_id ], [ '%d' ] );

        foreach ( (array) $mannschaften as $m ) {
            $sparte = $m['sparte'] ?? '';
            $mid    = (int) ( $m['mannschaft_id'] ?? 0 );
            $rolle  = $m['rolle'] ?? 'sportler';
            if ( ! in_array( $sparte, self::SPARTEN, true ) ) continue;
            if ( ! in_array( $rolle,  self::ROLLEN,  true ) ) continue;
            if ( ! $mid ) continue;
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$p}lsv07i_personen_mannschaft
                    (person_id, sparte, mannschaft_id, rolle)
                 VALUES (%d, %s, %d, %s)",
                $person_id, $sparte, $mid, $rolle
            ) );
        }
        return true;
    }

    public static function loeschen( $person_id ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $person_id = (int) $person_id;
        if ( ! $person_id ) return false;
        $wpdb->delete( $p . 'lsv07i_personen_sparten_rolle',
            [ 'person_id' => $person_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_personen_mannschaft',
            [ 'person_id' => $person_id ], [ '%d' ] );
        $wpdb->delete( $p . 'lsv07i_personen',
            [ 'id' => $person_id ], [ '%d' ] );
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HELPER
    // ─────────────────────────────────────────────────────────────────────────

    public static function clean_date( $s ) {
        $s = trim( (string) $s );
        if ( $s === '' ) return null;
        // YYYY-MM-DD oder DD.MM.YYYY akzeptieren
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s ) ) return $s;
        if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m ) ) {
            return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
        }
        return null;
    }

    public static function clean_gender( $s ) {
        $s = strtolower( trim( (string) $s ) );
        if ( in_array( $s, [ 'm', 'männlich', 'maennlich', 'male' ], true ) )    return 'M';
        if ( in_array( $s, [ 'w', 'weiblich', 'female', 'f' ], true ) )           return 'W';
        if ( in_array( $s, [ 'd', 'divers', 'x', 'other' ], true ) )              return 'D';
        return null;
    }
}
