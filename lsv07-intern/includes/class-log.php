<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Log — Zentrales Audit-Log.
 *
 * Verwendung:
 *   LSV07I_Log::write('schwimmer.update', ['ziel_id'=>$sid, 'ziel_name'=>$name]);
 *   LSV07I_Log::write('rechte.assign', ['ziel_id'=>$uid, 'erfolg'=>false, 'details'=>'Fehler...']);
 *
 * Schreibzugriffe und kritische Aktionen werden geloggt; reine Lesezugriffe nicht
 * (sonst würde die Tabelle in wenigen Wochen Millionen Zeilen haben).
 */
class LSV07I_Log {

    /**
     * Schreibt einen Log-Eintrag.
     *
     * @param string $aktion  Aktionsname, z.B. 'schwimmer.update', 'datei.upload'
     * @param array  $args    Optional:
     *                        - bereich    (Schwimmen/Triathlon/Fitness/Admin/Rechte/Datei/...)
     *                        - ziel_typ   ('schwimmer','user','datei','konfig',...)
     *                        - ziel_id    ID des betroffenen Objekts
     *                        - ziel_name  lesbarer Name (Mustermann, Max)
     *                        - erfolg     bool, default true
     *                        - details    zusätzliche Info als Text
     */
    public static function write( $aktion, $args = [] ) {
        // DSGVO-Speicherbegrenzung: Log-Eintraege werden maximal 180 Tage
        // aufbewahrt. Der Cleanup laeuft probabilistisch bei ~1 % der
        // Schreibvorgaenge mit — das haelt die Tabelle ohne Cron-Abhaengigkeit
        // dauerhaft innerhalb der Aufbewahrungsfrist.
        if ( wp_rand( 1, 100 ) === 1 ) {
            self::cleanup();
        }
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_log';

        // Falls Tabelle noch nicht da (frische Installation, Migration noch nicht
        // gelaufen), nicht hart crashen — Log darf nie die App killen.
        static $exists = null;
        if ( $exists === null ) {
            $exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" );
        }
        if ( ! $exists ) return false;

        $uid  = get_current_user_id();
        $user = $uid ? get_user_by( 'id', $uid ) : null;
        $name = $user ? ( $user->display_name ?: $user->user_login ) : '';

        // mb_substr-Fallback (Mbstring sollte da sein, aber sicher ist sicher)
        $cut = function( $v, $len ) {
            if ( $v === null || $v === '' ) return $v === null ? null : '';
            return function_exists( 'mb_substr' )
                ? mb_substr( (string) $v, 0, $len )
                : substr( (string) $v, 0, $len );
        };

        $data = [
            'user_id'   => (int) $uid,
            'user_name' => $cut( $name, 120 ),
            'aktion'    => $cut( $aktion, 80 ),
            'bereich'   => $cut( $args['bereich'] ?? '', 40 ),
            'ziel_typ'  => isset( $args['ziel_typ'] )  ? $cut( $args['ziel_typ'], 40 )   : null,
            'ziel_id'   => isset( $args['ziel_id'] )   ? $cut( $args['ziel_id'], 80 )    : null,
            'ziel_name' => isset( $args['ziel_name'] ) ? $cut( $args['ziel_name'], 200 ) : null,
            'erfolg'    => isset( $args['erfolg'] ) ? ( $args['erfolg'] ? 1 : 0 ) : 1,
            'details'   => isset( $args['details'] ) ? (string) $args['details'] : null,
            'ip'        => self::client_ip(),
            'zeitpunkt' => current_time( 'mysql' ),
        ];

        return $wpdb->insert( $tbl, $data, [ '%d','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s' ] );
    }

    private static function client_ip() {
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip = trim( $parts[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // DSGVO / Datensparsamkeit (Art. 5 Abs. 1 lit. c): IP-Adressen sind
        // personenbezogene Daten. Fuer den Zweck des Logs (Nachvollziehbarkeit
        // grober Herkunft, Missbrauchserkennung) genuegt die anonymisierte
        // Form. IPv4: letztes Oktett genullt; IPv6: auf das /64-Praefix
        // gekuerzt (wie wp_privacy_anonymize_ip in WordPress-Core).
        if ( function_exists( 'wp_privacy_anonymize_ip' ) ) {
            $ip = wp_privacy_anonymize_ip( $ip );
        } else {
            if ( strpos( $ip, '.' ) !== false && strpos( $ip, ':' ) === false ) {
                $o = explode( '.', $ip );
                if ( count( $o ) === 4 ) { $o[3] = '0'; $ip = implode( '.', $o ); }
            } elseif ( strpos( $ip, ':' ) !== false ) {
                $seg = explode( ':', $ip );
                $ip  = implode( ':', array_slice( $seg, 0, 4 ) ) . '::';
            }
        }
        return substr( $ip, 0, 45 );
    }

    /**
     * Liefert Log-Einträge nach Filtern. Wird vom AJAX-Endpunkt genutzt.
     */
    public static function query( array $filters = [], $limit = 100, $offset = 0 ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'lsv07i_log';

        $where = [ '1=1' ];
        $vals  = [];
        if ( ! empty( $filters['user_id'] ) ) {
            $where[] = 'user_id = %d';
            $vals[]  = (int) $filters['user_id'];
        }
        if ( ! empty( $filters['aktion'] ) ) {
            $where[] = 'aktion LIKE %s';
            $vals[]  = '%' . $wpdb->esc_like( $filters['aktion'] ) . '%';
        }
        if ( ! empty( $filters['bereich'] ) ) {
            $where[] = 'bereich = %s';
            $vals[]  = $filters['bereich'];
        }
        if ( ! empty( $filters['von'] ) ) {
            $where[] = 'zeitpunkt >= %s';
            $vals[]  = $filters['von'] . ' 00:00:00';
        }
        if ( ! empty( $filters['bis'] ) ) {
            $where[] = 'zeitpunkt <= %s';
            $vals[]  = $filters['bis'] . ' 23:59:59';
        }
        if ( ! empty( $filters['search'] ) ) {
            $where[] = '(user_name LIKE %s OR ziel_name LIKE %s OR details LIKE %s)';
            $like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
            $vals[] = $like; $vals[] = $like; $vals[] = $like;
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS * FROM $tbl WHERE " . implode( ' AND ', $where )
             . " ORDER BY zeitpunkt DESC, id DESC LIMIT %d OFFSET %d";
        $vals[] = (int) $limit;
        $vals[] = (int) $offset;

        $rows  = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
        $total = (int) $wpdb->get_var( "SELECT FOUND_ROWS()" );
        return [ 'rows' => $rows ?: [], 'total' => $total ];
    }

    /**
     * Entfernt Log-Eintraege, die aelter als die Aufbewahrungsfrist sind.
     * Frist per Filter 'lsv07i_log_retention_days' anpassbar (Default 180).
     */
    public static function cleanup() {
        global $wpdb;
        $days = (int) apply_filters( 'lsv07i_log_retention_days', 180 );
        if ( $days < 1 ) return;
        $tbl = $wpdb->prefix . 'lsv07i_log';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$tbl} WHERE zeitpunkt < DATE_SUB( NOW(), INTERVAL %d DAY ) LIMIT 5000",
            $days
        ) );
    }
}
