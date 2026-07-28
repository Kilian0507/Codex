<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Log {

    public static function init() {
        add_action( 'wp_ajax_lsv07i_log_query', [ __CLASS__, 'query' ] );
    }

    public static function query() {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungültige Anfrage.' ], 403 );
        }
        if ( ! current_user_can( 'administrator' )
          && ! LSV07I_Permissions::can_current( LSV07I_Permissions::ADMIN_LOG_READ )
          && ! LSV07I_Permissions::can_current( LSV07I_Permissions::ADMIN_PERMISSIONS_READ ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }

        $filters = [
            'user_id' => isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0,
            'aktion'  => sanitize_text_field( $_POST['aktion'] ?? '' ),
            'bereich' => sanitize_text_field( $_POST['bereich'] ?? '' ),
            'von'     => sanitize_text_field( $_POST['von'] ?? '' ),
            'bis'     => sanitize_text_field( $_POST['bis'] ?? '' ),
            'search'  => sanitize_text_field( $_POST['search'] ?? '' ),
        ];
        $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
        $limit  = min( 200, max( 10, (int) ( $_POST['limit'] ?? 50 ) ) );

        $result = LSV07I_Log::query( $filters, $limit, $offset );
        wp_send_json_success( $result );
    }
}
