<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07A_Log {
    public static function schreibe( $aktion, $args = [] ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'lsv07a_log', [
            'wp_user_id' => get_current_user_id(),
            'aktion'     => substr( (string) $aktion, 0, 60 ),
            'ziel_typ'   => substr( (string) ( $args['ziel_typ'] ?? '' ), 0, 30 ),
            'ziel_id'    => (int) ( $args['ziel_id'] ?? 0 ),
            'details'    => (string) ( $args['details'] ?? '' ),
        ], [ '%d', '%s', '%s', '%d', '%s' ] );
    }

    public static function liste( $limit = 200 ) {
        global $wpdb;
        $limit = max( 1, min( 1000, (int) $limit ) );
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}lsv07a_log ORDER BY id DESC LIMIT $limit", ARRAY_A ) ?: [];
    }
}
