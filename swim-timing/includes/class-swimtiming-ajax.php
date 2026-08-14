<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SwimTiming_Ajax {

	public function __construct() {
		// Admin (logged-in, authorized role) actions.
		add_action( 'wp_ajax_swimtiming_list_starters', array( $this, 'list_starters' ) );
		add_action( 'wp_ajax_swimtiming_get_starter', array( $this, 'get_starter' ) );
		add_action( 'wp_ajax_swimtiming_add_starter', array( $this, 'add_starter' ) );
		add_action( 'wp_ajax_swimtiming_update_starter', array( $this, 'update_starter' ) );
		add_action( 'wp_ajax_swimtiming_delete_starter', array( $this, 'delete_starter' ) );
		add_action( 'wp_ajax_swimtiming_import_starters_paste', array( $this, 'import_starters_paste' ) );
		add_action( 'wp_ajax_swimtiming_delete_all_data', array( $this, 'delete_all_data' ) );
		add_action( 'wp_ajax_swimtiming_qrcode_download', array( $this, 'download_qrcode' ) );

		add_action( 'wp_ajax_swimtiming_add_split', array( $this, 'add_split' ) );
		add_action( 'wp_ajax_swimtiming_update_split', array( $this, 'update_split' ) );
		add_action( 'wp_ajax_swimtiming_delete_split', array( $this, 'delete_split' ) );
		add_action( 'wp_ajax_swimtiming_import_splits_paste', array( $this, 'import_splits_paste' ) );

		// Public (no login required) actions.
		add_action( 'wp_ajax_swimtiming_public_lookup', array( $this, 'public_lookup' ) );
		add_action( 'wp_ajax_nopriv_swimtiming_public_lookup', array( $this, 'public_lookup' ) );

		add_action( 'wp_ajax_swimtiming_public_pdf', array( $this, 'public_pdf' ) );
		add_action( 'wp_ajax_nopriv_swimtiming_public_pdf', array( $this, 'public_pdf' ) );

		add_action( 'wp_ajax_swimtiming_public_schedule', array( $this, 'public_schedule' ) );
		add_action( 'wp_ajax_nopriv_swimtiming_public_schedule', array( $this, 'public_schedule' ) );

		add_action( 'wp_ajax_swimtiming_public_search_starters', array( $this, 'public_search_starters' ) );
		add_action( 'wp_ajax_nopriv_swimtiming_public_search_starters', array( $this, 'public_search_starters' ) );

		add_action( 'wp_ajax_swimtiming_public_submit_end_time', array( $this, 'public_submit_end_time' ) );
		add_action( 'wp_ajax_nopriv_swimtiming_public_submit_end_time', array( $this, 'public_submit_end_time' ) );

		add_action( 'wp_ajax_swimtiming_public_add_split', array( $this, 'public_add_split' ) );
		add_action( 'wp_ajax_nopriv_swimtiming_public_add_split', array( $this, 'public_add_split' ) );
	}

	private function check_admin_permission() {
		check_ajax_referer( 'swimtiming_admin', 'nonce' );
		if ( ! SwimTiming_Settings::current_user_is_admin() ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'swim-timing' ) ), 403 );
		}
	}

	private function starter_payload( $starter ) {
		return array(
			'id'            => (int) $starter['id'],
			'first_name'    => $starter['first_name'],
			'last_name'     => $starter['last_name'],
			'team'          => $starter['team'],
			'team_position' => (int) $starter['team_position'],
			'report_time'   => $starter['report_time'],
			'start_time'    => $starter['start_time'],
			'end_time'      => $starter['end_time'],
		);
	}

	private function split_payload( $split ) {
		return array(
			'id'           => (int) $split['id'],
			'starter_id'   => (int) $split['starter_id'],
			'split_number' => (int) $split['split_number'],
			'split_time'   => $split['split_time'],
			'comment'      => $split['comment'],
		);
	}

	/* ---------------- Admin: Starters ---------------- */

	public function list_starters() {
		$this->check_admin_permission();
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$starters = SwimTiming_DB::get_starters( array( 'search' => $search ) );

		$data = array();
		foreach ( $starters as $starter ) {
			$row = $this->starter_payload( $starter );
			$row['split_count'] = SwimTiming_DB::count_splits( $starter['id'] );
			$data[] = $row;
		}

		wp_send_json_success( array( 'starters' => $data ) );
	}

	public function get_starter() {
		$this->check_admin_permission();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$starter = SwimTiming_DB::get_starter( $id );
		if ( ! $starter ) {
			wp_send_json_error( array( 'message' => __( 'Startperson nicht gefunden.', 'swim-timing' ) ), 404 );
		}
		$splits = array_map( array( $this, 'split_payload' ), SwimTiming_DB::get_splits_for_starter( $id ) );
		wp_send_json_success( array(
			'starter' => $this->starter_payload( $starter ),
			'splits'  => $splits,
		) );
	}

	public function add_starter() {
		$this->check_admin_permission();

		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';

		if ( '' === $first_name || '' === $last_name ) {
			wp_send_json_error( array( 'message' => __( 'Vor- und Nachname sind erforderlich.', 'swim-timing' ) ), 400 );
		}

		$id = SwimTiming_DB::insert_starter( array(
			'first_name'    => $first_name,
			'last_name'     => $last_name,
			'team'          => isset( $_POST['team'] ) ? wp_unslash( $_POST['team'] ) : '',
			'team_position' => isset( $_POST['team_position'] ) ? wp_unslash( $_POST['team_position'] ) : '',
			'report_time'   => isset( $_POST['report_time'] ) ? wp_unslash( $_POST['report_time'] ) : '',
			'start_time'    => isset( $_POST['start_time'] ) ? wp_unslash( $_POST['start_time'] ) : '',
		) );

		$starter = SwimTiming_DB::get_starter( $id );
		wp_send_json_success( array( 'starter' => $this->starter_payload( $starter ) ) );
	}

	public function update_starter() {
		$this->check_admin_permission();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id || ! SwimTiming_DB::get_starter( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Startperson nicht gefunden.', 'swim-timing' ) ), 404 );
		}

		$data = array();
		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}
		if ( isset( $_POST['team'] ) ) {
			$data['team'] = wp_unslash( $_POST['team'] );
		}
		if ( isset( $_POST['team_position'] ) ) {
			$data['team_position'] = wp_unslash( $_POST['team_position'] );
		}
		foreach ( array( 'report_time', 'start_time', 'end_time' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = wp_unslash( $_POST[ $field ] );
			}
		}

		SwimTiming_DB::update_starter( $id, $data );

		// Startzeit, Endzeit UND Meldezeit (als Schätzung, solange die
		// echte Endzeit noch fehlt) fließen in die Kaskade ein - jede
		// Änderung muss die Folgezeiten in der Staffel neu berechnen:
		// Startzeit(nächster) = Startzeit(dieser) + Endzeit(dieser, oder
		// geschätzt über die Meldezeit) + 1 Min.
		if ( array_key_exists( 'end_time', $data ) || array_key_exists( 'start_time', $data ) || array_key_exists( 'report_time', $data ) ) {
			SwimTiming_DB::cascade_after_end_time_change( $id );
		}

		$starter = SwimTiming_DB::get_starter( $id );
		wp_send_json_success( array( 'starter' => $this->starter_payload( $starter ) ) );
	}

	public function delete_starter() {
		$this->check_admin_permission();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		SwimTiming_DB::delete_starter( $id );
		wp_send_json_success();
	}

	public function import_starters_paste() {
		$this->check_admin_permission();

		$text = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		if ( '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Bitte zuerst eine Tabelle einfügen.', 'swim-timing' ) ), 400 );
		}

		$result = SwimTiming_CSV::import_starters( $text );
		wp_send_json_success( $result );
	}

	public function delete_all_data() {
		$this->check_admin_permission();

		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		if ( 'LÖSCHEN' !== $confirm ) {
			wp_send_json_error( array( 'message' => __( 'Bestätigung stimmt nicht überein.', 'swim-timing' ) ), 400 );
		}

		SwimTiming_DB::delete_all_data();
		wp_send_json_success();
	}

	/**
	 * Streams a downloadable PNG of the QR code pointing at the public
	 * unauthenticated entry page, so the admin can print/post it at the pool.
	 */
	public function download_qrcode() {
		check_ajax_referer( 'swimtiming_admin', 'nonce' );
		if ( ! SwimTiming_Settings::current_user_is_admin() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'swim-timing' ), 403 );
		}

		$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
		if ( '' === $url ) {
			wp_die( esc_html__( 'Fehlende URL.', 'swim-timing' ), 400 );
		}

		$qr_api = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&data=' . rawurlencode( $url );
		$response = wp_remote_get( $qr_api, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			wp_die( esc_html__( 'QR-Code konnte nicht erzeugt werden.', 'swim-timing' ), 502 );
		}

		$body = wp_remote_retrieve_body( $response );

		nocache_headers();
		header( 'Content-Type: image/png' );
		header( 'Content-Disposition: attachment; filename="swim-timing-qrcode.png"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore -- raw binary PNG output.
		exit;
	}

	/* ---------------- Admin: Splits ---------------- */

	public function add_split() {
		$this->check_admin_permission();

		$starter_id = isset( $_POST['starter_id'] ) ? (int) $_POST['starter_id'] : 0;
		if ( ! $starter_id || ! SwimTiming_DB::get_starter( $starter_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Startperson nicht gefunden.', 'swim-timing' ) ), 404 );
		}

		$split_number = isset( $_POST['split_number'] ) && '' !== $_POST['split_number']
			? (int) $_POST['split_number']
			: SwimTiming_DB::get_next_split_number( $starter_id );

		$id = SwimTiming_DB::insert_split( array(
			'starter_id'   => $starter_id,
			'split_number' => $split_number,
			'split_time'   => isset( $_POST['split_time'] ) ? wp_unslash( $_POST['split_time'] ) : '',
			'comment'      => isset( $_POST['comment'] ) ? wp_unslash( $_POST['comment'] ) : '',
		) );

		$splits = array_map( array( $this, 'split_payload' ), SwimTiming_DB::get_splits_for_starter( $starter_id ) );
		wp_send_json_success( array( 'splits' => $splits ) );
	}

	public function update_split() {
		$this->check_admin_permission();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$starter_id = isset( $_POST['starter_id'] ) ? (int) $_POST['starter_id'] : 0;

		$data = array();
		if ( isset( $_POST['split_time'] ) ) {
			$data['split_time'] = wp_unslash( $_POST['split_time'] );
		}
		if ( isset( $_POST['comment'] ) ) {
			$data['comment'] = wp_unslash( $_POST['comment'] );
		}
		if ( isset( $_POST['split_number'] ) && '' !== $_POST['split_number'] ) {
			$data['split_number'] = (int) $_POST['split_number'];
		}

		SwimTiming_DB::update_split( $id, $data );
		$splits = array_map( array( $this, 'split_payload' ), SwimTiming_DB::get_splits_for_starter( $starter_id ) );
		wp_send_json_success( array( 'splits' => $splits ) );
	}

	public function delete_split() {
		$this->check_admin_permission();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$starter_id = isset( $_POST['starter_id'] ) ? (int) $_POST['starter_id'] : 0;
		SwimTiming_DB::delete_split( $id );
		$splits = array_map( array( $this, 'split_payload' ), SwimTiming_DB::get_splits_for_starter( $starter_id ) );
		wp_send_json_success( array( 'splits' => $splits ) );
	}

	public function import_splits_paste() {
		$this->check_admin_permission();

		$text = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		if ( '' === trim( $text ) ) {
			wp_send_json_error( array( 'message' => __( 'Bitte zuerst eine Tabelle einfügen.', 'swim-timing' ) ), 400 );
		}

		$result = SwimTiming_CSV::import_splits( $text );
		wp_send_json_success( $result );
	}

	/* ---------------- Public ---------------- */

	public function public_lookup() {
		check_ajax_referer( 'swimtiming_public', 'nonce' );

		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$start_time = isset( $_POST['start_time'] ) ? wp_unslash( $_POST['start_time'] ) : '';

		if ( '' === $first_name || '' === $last_name || '' === trim( str_replace( ':', '', $start_time ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Bitte Vorname, Nachname und Startzeit angeben.', 'swim-timing' ) ), 400 );
		}

		$starter = SwimTiming_DB::find_starter_by_identity( $first_name, $last_name, $start_time );
		if ( ! $starter ) {
			wp_send_json_error( array( 'message' => __( 'Keine Startperson mit diesen Angaben gefunden.', 'swim-timing' ) ), 404 );
		}

		$splits = array_map( array( $this, 'split_payload' ), SwimTiming_DB::get_splits_for_starter( $starter['id'] ) );

		wp_send_json_success( array(
			'starter' => $this->starter_payload( $starter ),
			'splits'  => $splits,
		) );
	}

	public function public_pdf() {
		check_ajax_referer( 'swimtiming_public', 'nonce' );

		$first_name = isset( $_GET['first_name'] ) ? sanitize_text_field( wp_unslash( $_GET['first_name'] ) ) : '';
		$last_name  = isset( $_GET['last_name'] ) ? sanitize_text_field( wp_unslash( $_GET['last_name'] ) ) : '';
		$start_time = isset( $_GET['start_time'] ) ? wp_unslash( $_GET['start_time'] ) : '';

		$starter = SwimTiming_DB::find_starter_by_identity( $first_name, $last_name, $start_time );
		if ( ! $starter ) {
			wp_die( esc_html__( 'Keine Startperson mit diesen Angaben gefunden.', 'swim-timing' ), 404 );
		}

		$splits = SwimTiming_DB::get_splits_for_starter( $starter['id'] );

		$pdf = new SwimTiming_PDF();
		$pdf->add_heading( SwimTiming_Settings::get_title() );
		$pdf->add_subheading( $starter['first_name'] . ' ' . $starter['last_name'] );
		$pdf->add_spacer( 4 );
		$pdf->add_text( __( 'Meldezeit:', 'swim-timing' ) . ' ' . ( $starter['report_time'] ?: '-' ) );
		$pdf->add_text( __( 'Startzeit:', 'swim-timing' ) . ' ' . ( $starter['start_time'] ?: '-' ) );
		$pdf->add_text( __( 'Endzeit:', 'swim-timing' ) . ' ' . ( $starter['end_time'] ?: '-' ) );
		$pdf->add_spacer( 8 );

		if ( $splits ) {
			$pdf->add_subheading( __( 'Zwischenzeiten', 'swim-timing' ) );
			foreach ( $splits as $split ) {
				$line = sprintf( '#%d  %s', $split['split_number'], $split['split_time'] );
				if ( ! empty( $split['comment'] ) ) {
					$line .= '  – ' . $split['comment'];
				}
				$pdf->add_text( $line );
			}
		} else {
			$pdf->add_text( __( 'Keine Zwischenzeiten erfasst.', 'swim-timing' ) );
		}

		$filename = sanitize_file_name( $starter['last_name'] . '_' . $starter['first_name'] . '_zeiten.pdf' );
		$pdf->output( $filename );
		exit;
	}

	/**
	 * Public, read-only schedule of all start times (no login required).
	 */
	public function public_schedule() {
		check_ajax_referer( 'swimtiming_public', 'nonce' );
		$rows = SwimTiming_DB::get_public_schedule();
		wp_send_json_success( array( 'schedule' => $rows ) );
	}

	/**
	 * Name typeahead for the unauthenticated QR-code entry mask: type a
	 * name, then pick the matching starter from the suggestions.
	 */
	public function public_search_starters() {
		check_ajax_referer( 'swimtiming_public', 'nonce' );

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( mb_strlen( $query ) < 2 ) {
			wp_send_json_success( array( 'results' => array() ) );
		}

		$results = SwimTiming_DB::search_starters( $query, 8 );
		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Unauthenticated entry: record the finish (Endzeit) of a swimmer that
	 * was picked from the search suggestions, then cascade the relay start
	 * times for the rest of the team.
	 */
	public function public_submit_end_time() {
		check_ajax_referer( 'swimtiming_public', 'nonce' );

		$starter_id = isset( $_POST['starter_id'] ) ? (int) $_POST['starter_id'] : 0;
		$time = isset( $_POST['end_time'] ) ? wp_unslash( $_POST['end_time'] ) : '';

		$starter = SwimTiming_DB::get_starter( $starter_id );
		if ( ! $starter ) {
			wp_send_json_error( array( 'message' => __( 'Bitte eine Startperson aus den Vorschlägen auswählen.', 'swim-timing' ) ), 404 );
		}
		if ( null === SwimTiming_DB::normalize_time( $time ) ) {
			wp_send_json_error( array( 'message' => __( 'Bitte eine Zeit eingeben.', 'swim-timing' ) ), 400 );
		}

		SwimTiming_DB::update_starter( $starter_id, array( 'end_time' => $time ) );
		SwimTiming_DB::cascade_after_end_time_change( $starter_id );

		$updated = SwimTiming_DB::get_starter( $starter_id );
		$next = SwimTiming_DB::get_next_in_team( $updated['team'], $updated['team_position'], $starter_id );

		wp_send_json_success( array(
			'starter' => $this->starter_payload( $updated ),
			'next'    => $next ? $this->starter_payload( $next ) : null,
		) );
	}

	/**
	 * Unauthenticated entry: record a Zwischenzeit (split) for a swimmer
	 * that was picked from the search suggestions. Splits are numbered
	 * automatically and don't affect the relay start-time cascade.
	 */
	public function public_add_split() {
		check_ajax_referer( 'swimtiming_public', 'nonce' );

		$starter_id = isset( $_POST['starter_id'] ) ? (int) $_POST['starter_id'] : 0;
		$time = isset( $_POST['split_time'] ) ? wp_unslash( $_POST['split_time'] ) : '';

		$starter = SwimTiming_DB::get_starter( $starter_id );
		if ( ! $starter ) {
			wp_send_json_error( array( 'message' => __( 'Bitte eine Startperson aus den Vorschlägen auswählen.', 'swim-timing' ) ), 404 );
		}
		if ( null === SwimTiming_DB::normalize_time( $time ) ) {
			wp_send_json_error( array( 'message' => __( 'Bitte eine Zeit eingeben.', 'swim-timing' ) ), 400 );
		}

		SwimTiming_DB::insert_split( array(
			'starter_id'   => $starter_id,
			'split_number' => SwimTiming_DB::get_next_split_number( $starter_id ),
			'split_time'   => $time,
			'comment'      => '',
		) );

		wp_send_json_success( array(
			'starter' => $this->starter_payload( $starter ),
		) );
	}
}
