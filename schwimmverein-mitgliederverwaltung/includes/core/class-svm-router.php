<?php
/**
 * Verarbeitung aller Formularaktionen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alle Aktionen der Verwaltung laufen über admin-post.php und kehren
 * anschließend auf die Seite mit dem Shortcode zurück.
 */
class SVM_Router {

	const ACTION = 'svm_do';

	/**
	 * Registriert den Einstiegspunkt.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Aktionen und die dafür nötigen Rechte.
	 *
	 * @return array action => permission
	 */
	private static function actions() {
		return array(
			// Mitglieder.
			'save_member'           => 'members_edit',
			'delete_member'         => 'members_delete',
			'change_status'         => 'members_status',
			'approve_change'        => 'change_requests',
			'reject_change'         => 'change_requests',
			'save_own_profile'      => 'own_profile_edit',
			'mark_message_read'     => 'portal_access',

			// Familien.
			'save_family'           => 'members_edit',
			'delete_family'         => 'members_edit',
			'add_family_member'     => 'members_edit',
			'remove_family_member'  => 'members_edit',

			// Beiträge je Mitglied.
			'assign_member_fee'     => 'fees_manage',
			'remove_member_fee'     => 'fees_manage',

			// Konfiguration.
			'save_field'            => 'fields_manage',
			'delete_field'          => 'fields_manage',
			'save_field_access'     => 'fields_manage',
			'save_unit'             => 'units_manage',
			'delete_unit'           => 'units_manage',
			'save_unit_type'        => 'units_manage',
			'delete_unit_type'      => 'units_manage',
			'save_status'           => 'settings_manage',
			'delete_status'         => 'settings_manage',
			'save_transition'       => 'settings_manage',
			'delete_transition'     => 'settings_manage',
			'save_role'             => 'roles_manage',
			'delete_role'           => 'roles_manage',
			'assign_user'           => 'roles_manage',
			'save_payment_method'   => 'settings_manage',
			'delete_payment_method' => 'settings_manage',
			'save_number_range'     => 'settings_manage',
			'delete_number_range'   => 'settings_manage',
			'save_template'         => 'settings_manage',
			'delete_template'       => 'settings_manage',
			'save_settings'         => 'settings_manage',
			'repair_schema'         => 'settings_manage',

			// Beiträge.
			'save_fee_type'         => 'fees_manage',
			'delete_fee_type'       => 'fees_manage',
			'save_rule'             => 'fees_manage',
			'delete_rule'           => 'fees_manage',
			'commit_fee_run'        => 'fee_run',
			'delete_fee_run'        => 'fee_run',
			'cancel_invoice'        => 'invoices_edit',
			'create_invoice'        => 'invoices_edit',
			'delete_invoice'        => 'invoices_edit',

			// Zahlungen.
			'record_payment'        => 'payments_record',
			'delete_payment'        => 'payments_record',
			'return_payment'        => 'payments_record',
			'save_mandate'          => 'mandates_manage',
			'revoke_mandate'        => 'mandates_manage',
			'delete_mandate'        => 'mandates_manage',
			'save_file_profile'     => 'payment_run',
			'delete_file_profile'   => 'payment_run',
			'create_payment_run'    => 'payment_run',
			'set_run_status'        => 'payment_run',
			'delete_payment_run'    => 'payment_run',
			'download_run_file'     => 'payment_run',
			'save_dunning_level'    => 'dunning_manage',
			'delete_dunning_level'  => 'dunning_manage',
			'apply_dunning'         => 'dunning_manage',

			// Nachrichten.
			'save_message'          => 'messages_manage',
			'delete_message'        => 'messages_manage',
			'save_message_category' => 'messages_manage',
			'delete_message_category' => 'messages_manage',

			// Daten.
			'save_export_profile'   => 'export_manage',
			'delete_export_profile' => 'export_manage',
			'download_export'       => 'export_run',
			'gdpr_export'           => 'members_view',
			'anonymize_member'      => 'members_delete',
			'read_import'           => 'import_run',
			'run_import'            => 'import_run',
			'export_config'         => 'settings_manage',
			'import_config'         => 'settings_manage',
		);
	}

	/**
	 * Nimmt eine Aktion entgegen.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Bitte melden Sie sich an.', 'svm' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action  = isset( $_POST['svm_action'] ) ? sanitize_key( wp_unslash( $_POST['svm_action'] ) ) : '';
		$actions = self::actions();

		if ( '' === $action || ! isset( $actions[ $action ] ) ) {
			self::redirect( array( 'svm_error' => __( 'Unbekannte Aktion.', 'svm' ) ) );
		}

		check_admin_referer( 'svm_' . $action, '_svm_nonce' );

		if ( ! SVM_Permissions::current_user_can( $actions[ $action ] ) ) {
			self::redirect( array( 'svm_error' => __( 'Für diese Aktion fehlt die Berechtigung.', 'svm' ) ) );
		}

		$method = 'do_' . $action;

		if ( ! method_exists( __CLASS__, $method ) ) {
			self::redirect( array( 'svm_error' => __( 'Diese Aktion ist nicht verfügbar.', 'svm' ) ) );
		}

		$result = self::$method();

		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'svm_error' => $result->get_error_message() ) );
		}

		self::redirect( is_array( $result ) ? $result : array() );
	}

	/**
	 * Kehrt zur aufrufenden Seite zurück.
	 *
	 * @param array $args Zusätzliche Parameter.
	 * @return void
	 */
	private static function redirect( array $args = array() ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$target = isset( $_POST['svm_return'] ) ? esc_url_raw( wp_unslash( $_POST['svm_return'] ) ) : '';

		if ( '' === $target ) {
			$target = SVM_App::page_url();
		}

		if ( ! isset( $args['svm_error'] ) && ! isset( $args['svm_notice'] ) ) {
			$args['svm_notice'] = __( 'Gespeichert.', 'svm' );
		}

		foreach ( array( 'svm_notice', 'svm_error' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$args[ $key ] = rawurlencode( $args[ $key ] );
			}
		}

		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Eingabehilfen
	 * ------------------------------------------------------------------ */

	/**
	 * Liest einen Wert aus dem Request.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Voreinstellung.
	 * @return mixed
	 */
	private static function post( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = wp_unslash( $_POST[ $key ] );

		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Liest einen mehrzeiligen Wert.
	 *
	 * @param string $key Schlüssel.
	 * @return string
	 */
	private static function post_html( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return wp_kses_post( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Liest ein Rohdaten-Array.
	 *
	 * @param string $key Schlüssel.
	 * @return array
	 */
	private static function post_array( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Ganzzahl aus dem Request.
	 *
	 * @param string $key Schlüssel.
	 * @return int
	 */
	private static function id( $key = 'id' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0;
	}

	/**
	 * Datum oder null.
	 *
	 * @param string $key Schlüssel.
	 * @return string|null
	 */
	private static function date( $key ) {
		$value = self::post( $key );

		return '' !== $value ? $value : null;
	}

	/**
	 * Baut die Rückkehr-Parameter für eine Ansicht.
	 *
	 * @param string $view    Ansicht.
	 * @param array  $extra   Weitere Parameter.
	 * @param string $message Meldung.
	 * @return array
	 */
	private static function to( $view, array $extra = array(), $message = '' ) {
		return array_merge(
			array( 'svm_view' => $view ),
			$extra,
			array( 'svm_notice' => '' !== $message ? $message : __( 'Gespeichert.', 'svm' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Mitglieder
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert ein Mitglied.
	 *
	 * @return array|WP_Error
	 */
	private static function do_save_member() {
		$member_id = self::id( 'member_id' );

		$core = array(
			'status_id'         => absint( self::post( 'status_id' ) ),
			'payment_method_id' => absint( self::post( 'payment_method_id' ) ),
			'joined_at'         => self::date( 'joined_at' ),
			'left_at'           => self::date( 'left_at' ),
			'wp_user_id'        => absint( self::post( 'wp_user_id' ) ),
		);

		if ( 0 === $member_id ) {
			if ( ! SVM_Permissions::current_user_can( 'members_create' ) ) {
				return new WP_Error( 'svm_denied', __( 'Für das Anlegen von Mitgliedern fehlt die Berechtigung.', 'svm' ) );
			}

			$number    = self::post( 'member_number' );
			$member_id = SVM_Members::create(
				array_merge( $core, '' !== $number ? array( 'member_number' => $number ) : array() )
			);
		} else {
			if ( ! SVM_Permissions::can_access_member( $member_id ) ) {
				return new WP_Error( 'svm_denied', __( 'Dieses Mitglied liegt außerhalb Ihres Zuständigkeitsbereichs.', 'svm' ) );
			}

			SVM_Members::update( $member_id, $core );
		}

		$values = SVM_Fields::collect_input( 'member', $member_id, self::post_array( 'svm_field' ) );

		if ( ! empty( $values['errors'] ) ) {
			return new WP_Error( 'svm_validation', implode( ' ', $values['errors'] ) );
		}

		if ( ! empty( $values['saved'] ) ) {
			SVM_Fields::save_values( 'member', $member_id, $values['saved'] );
		}

		foreach ( $values['pending'] as $field_id => $value ) {
			SVM_Change_Requests::create( $member_id, $field_id, $value );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['unit_ids'] ) ) {
			SVM_Members::set_units( $member_id, array_map( 'absint', (array) self::post( 'unit_ids', array() ) ) );
		}

		$family_id = absint( self::post( 'family_group_id' ) );

		if ( $family_id > 0 ) {
			SVM_Families::add_member(
				$family_id,
				$member_id,
				sanitize_key( self::post( 'relation_type', 'sonstig' ) ),
				absint( self::post( 'parent_member_id' ) )
			);
		}

		$message = empty( $values['pending'] )
			? __( 'Mitglied gespeichert.', 'svm' )
			: __( 'Gespeichert. Änderungen an freigabepflichtigen Feldern warten auf Bestätigung.', 'svm' );

		return self::to( 'member', array( 'id' => $member_id ), $message );
	}

	/**
	 * Löscht ein Mitglied.
	 *
	 * @return array
	 */
	private static function do_delete_member() {
		$member_id = self::id( 'member_id' );

		if ( 'yes' === self::post( 'hard_delete' ) ) {
			SVM_Members::hard_delete( $member_id );
		} else {
			SVM_Members::soft_delete( $member_id );
		}

		return self::to( 'members', array(), __( 'Mitglied gelöscht.', 'svm' ) );
	}

	/**
	 * Ändert den Status.
	 *
	 * @return array|WP_Error
	 */
	private static function do_change_status() {
		$member_id = self::id( 'member_id' );

		$result = SVM_Members::change_status(
			$member_id,
			absint( self::post( 'status_id' ) ),
			self::post( 'reason' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'member', array( 'id' => $member_id ), __( 'Status geändert.', 'svm' ) );
	}

	/**
	 * Genehmigt einen Änderungsantrag.
	 *
	 * @return array
	 */
	private static function do_approve_change() {
		SVM_Change_Requests::approve( self::id(), self::post( 'note' ) );

		return self::to( 'requests', array(), __( 'Änderung übernommen.', 'svm' ) );
	}

	/**
	 * Lehnt einen Änderungsantrag ab.
	 *
	 * @return array
	 */
	private static function do_reject_change() {
		SVM_Change_Requests::reject( self::id(), self::post( 'note' ) );

		return self::to( 'requests', array(), __( 'Änderung abgelehnt.', 'svm' ) );
	}

	/**
	 * Speichert die eigenen Stammdaten aus dem Self-Service.
	 *
	 * @return array|WP_Error
	 */
	private static function do_save_own_profile() {
		$member = SVM_Members::get_by_user( get_current_user_id() );

		if ( ! $member ) {
			return new WP_Error( 'svm_no_member', __( 'Ihrem Konto ist kein Mitgliedsdatensatz zugeordnet.', 'svm' ) );
		}

		$member_id = (int) $member['id'];
		$values    = SVM_Fields::collect_input( 'member', $member_id, self::post_array( 'svm_field' ) );

		if ( ! empty( $values['errors'] ) ) {
			return new WP_Error( 'svm_validation', implode( ' ', $values['errors'] ) );
		}

		if ( ! empty( $values['saved'] ) ) {
			SVM_Fields::save_values( 'member', $member_id, $values['saved'] );
		}

		foreach ( $values['pending'] as $field_id => $value ) {
			SVM_Change_Requests::create( $member_id, $field_id, $value );
		}

		return self::to(
			'profile',
			array(),
			empty( $values['pending'] )
				? __( 'Ihre Änderungen wurden gespeichert.', 'svm' )
				: __( 'Ihre Änderungen wurden weitergeleitet und werden nach Prüfung übernommen.', 'svm' )
		);
	}

	/**
	 * Lesebestätigung einer Nachricht.
	 *
	 * @return array
	 */
	private static function do_mark_message_read() {
		$member = SVM_Members::get_by_user( get_current_user_id() );

		if ( $member ) {
			SVM_Messages::mark_read( self::id( 'message_id' ), (int) $member['id'] );
		}

		return self::to( 'profile', array(), __( 'Danke für die Rückmeldung.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Familien
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert eine Familie.
	 *
	 * @return array
	 */
	private static function do_save_family() {
		$family_id = SVM_Families::save(
			array(
				'id'                => self::id(),
				'name'              => self::post( 'name' ),
				'primary_member_id' => absint( self::post( 'primary_member_id' ) ),
				'payment_method_id' => absint( self::post( 'payment_method_id' ) ),
				'billing_mode'      => self::post( 'billing_mode', 'individual' ),
				'note'              => self::post( 'note' ),
			)
		);

		return self::to( 'family', array( 'id' => $family_id ), __( 'Familie gespeichert.', 'svm' ) );
	}

	/**
	 * Löst eine Familie auf.
	 *
	 * @return array
	 */
	private static function do_delete_family() {
		SVM_Families::delete( self::id() );

		return self::to( 'families', array(), __( 'Familie aufgelöst. Die Mitglieder bleiben bestehen.', 'svm' ) );
	}

	/**
	 * Nimmt ein Mitglied in eine Familie auf.
	 *
	 * @return array|WP_Error
	 */
	private static function do_add_family_member() {
		$family_id = self::id( 'family_id' );
		$member_id = absint( self::post( 'member_id' ) );

		if ( $member_id <= 0 ) {
			return new WP_Error( 'svm_no_member', __( 'Bitte ein Mitglied auswählen.', 'svm' ) );
		}

		SVM_Families::add_member(
			$family_id,
			$member_id,
			sanitize_key( self::post( 'relation_type', 'sonstig' ) ),
			absint( self::post( 'parent_member_id' ) )
		);

		return self::to( 'family', array( 'id' => $family_id ), __( 'Mitglied zur Familie hinzugefügt.', 'svm' ) );
	}

	/**
	 * Entfernt ein Mitglied aus der Familie.
	 *
	 * @return array
	 */
	private static function do_remove_family_member() {
		$family_id = self::id( 'family_id' );

		SVM_Families::remove_member( absint( self::post( 'member_id' ) ) );

		return self::to( 'family', array( 'id' => $family_id ), __( 'Mitglied aus der Familie entfernt.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Beiträge je Mitglied
	 * ------------------------------------------------------------------ */

	/**
	 * Ordnet einem Mitglied eine Beitragsart zu.
	 *
	 * @return array|WP_Error
	 */
	private static function do_assign_member_fee() {
		$member_id   = self::id( 'member_id' );
		$fee_type_id = absint( self::post( 'fee_type_id' ) );

		if ( $fee_type_id <= 0 ) {
			return new WP_Error( 'svm_no_fee', __( 'Bitte eine Beitragsart auswählen.', 'svm' ) );
		}

		SVM_Member_Fees::assign(
			$member_id,
			$fee_type_id,
			self::post( 'mode', 'include' ),
			self::post( 'amount_override' ),
			self::post( 'note' )
		);

		return self::to( 'member', array( 'id' => $member_id, 'tab' => 'fees' ), __( 'Beitrag zugeordnet.', 'svm' ) );
	}

	/**
	 * Entfernt eine Beitragszuordnung.
	 *
	 * @return array
	 */
	private static function do_remove_member_fee() {
		$member_id = self::id( 'member_id' );

		SVM_Member_Fees::remove( absint( self::post( 'assignment_id' ) ) );

		return self::to( 'member', array( 'id' => $member_id, 'tab' => 'fees' ), __( 'Zuordnung entfernt.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Konfiguration
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert eine Felddefinition.
	 *
	 * @return array
	 */
	private static function do_save_field() {
		$id = SVM_Fields::save_def(
			array(
				'id'               => self::id(),
				'entity_type'      => 'member',
				'field_key'        => sanitize_key( self::post( 'field_key' ) ),
				'label'            => self::post( 'label' ),
				'field_type'       => self::post( 'field_type', 'text' ),
				'help_text'        => self::post( 'help_text' ),
				'section'          => self::post( 'section' ),
				'is_required'      => self::post( 'is_required' ) ? 1 : 0,
				'validation_regex' => self::post( 'validation_regex' ),
				'min_value'        => self::post( 'min_value' ),
				'max_value'        => self::post( 'max_value' ),
				'default_value'    => self::post( 'default_value' ),
				'formula'          => self::post( 'formula' ),
				'system_role'      => self::post( 'system_role' ),
				'is_sensitive'     => self::post( 'is_sensitive' ) ? 1 : 0,
				'in_gdpr_export'   => self::post( 'in_gdpr_export' ) ? 1 : 0,
				'show_in_list'     => self::post( 'show_in_list' ) ? 1 : 0,
				'retention_months' => absint( self::post( 'retention_months' ) ),
				'sort_order'       => absint( self::post( 'sort_order' ) ),
				'is_active'        => self::post( 'is_active' ) ? 1 : 0,
			)
		);

		$options = self::post( 'options' );

		if ( is_string( $options ) ) {
			SVM_Fields::set_options( $id, '' !== trim( $options ) ? preg_split( '/\r\n|\r|\n/', $options ) : array() );
		}

		return self::to( 'field', array( 'id' => $id ), __( 'Feld gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht ein Feld.
	 *
	 * @return array
	 */
	private static function do_delete_field() {
		SVM_Fields::delete_def( self::id() );

		return self::to( 'fields', array(), __( 'Feld gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert die Feldrechte.
	 *
	 * @return array
	 */
	private static function do_save_field_access() {
		$field_id = self::id( 'field_id' );
		$access   = self::post_array( 'access' );

		foreach ( SVM_Roles::all() as $role ) {
			$role_id = (int) $role['id'];
			$rules   = isset( $access[ $role_id ] ) ? (array) $access[ $role_id ] : array();

			SVM_Fields::set_visibility(
				$field_id,
				$role_id,
				array(
					'can_view'          => ! empty( $rules['can_view'] ),
					'can_edit'          => ! empty( $rules['can_edit'] ),
					'requires_approval' => ! empty( $rules['requires_approval'] ),
				)
			);
		}

		return self::to( 'field', array( 'id' => $field_id ), __( 'Feldrechte gespeichert.', 'svm' ) );
	}

	/**
	 * Speichert eine Einheit.
	 *
	 * @return array
	 */
	private static function do_save_unit() {
		SVM_Units::save(
			array(
				'id'             => self::id(),
				'parent_id'      => absint( self::post( 'parent_id' ) ),
				'unit_type_id'   => absint( self::post( 'unit_type_id' ) ),
				'name'           => self::post( 'name' ),
				'short_name'     => self::post( 'short_name' ),
				'leader_user_id' => absint( self::post( 'leader_user_id' ) ),
				'is_active'      => self::post( 'is_active' ) ? 1 : 0,
				'sort_order'     => absint( self::post( 'sort_order' ) ),
			)
		);

		return self::to( 'units', array(), __( 'Sparte gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Einheit.
	 *
	 * @return array
	 */
	private static function do_delete_unit() {
		SVM_Units::delete( self::id() );

		return self::to( 'units', array(), __( 'Sparte gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert einen Einheitstyp.
	 *
	 * @return array
	 */
	private static function do_save_unit_type() {
		SVM_Units::save_type(
			array(
				'id'         => self::id(),
				'label'      => self::post( 'label' ),
				'sort_order' => absint( self::post( 'sort_order' ) ),
			)
		);

		return self::to( 'units', array(), __( 'Ebene gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht einen Einheitstyp.
	 *
	 * @return array
	 */
	private static function do_delete_unit_type() {
		SVM_Units::delete_type( self::id() );

		return self::to( 'units', array(), __( 'Ebene gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert einen Status.
	 *
	 * @return array
	 */
	private static function do_save_status() {
		SVM_Statuses::save(
			array(
				'id'               => self::id(),
				'label'            => self::post( 'label' ),
				'status_key'       => sanitize_key( self::post( 'status_key' ) ),
				'color'            => self::post( 'color' ),
				'counts_as_active' => self::post( 'counts_as_active' ) ? 1 : 0,
				'is_billable'      => self::post( 'is_billable' ) ? 1 : 0,
				'allows_login'     => self::post( 'allows_login' ) ? 1 : 0,
				'is_default'       => self::post( 'is_default' ) ? 1 : 0,
				'sort_order'       => absint( self::post( 'sort_order' ) ),
			)
		);

		return self::to( 'statuses', array(), __( 'Status gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht einen Status.
	 *
	 * @return array|WP_Error
	 */
	private static function do_delete_status() {
		$result = SVM_Statuses::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'statuses', array(), __( 'Status gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert einen Statusübergang.
	 *
	 * @return array
	 */
	private static function do_save_transition() {
		SVM_Statuses::save_transition(
			array(
				'from_status_id'  => absint( self::post( 'from_status_id' ) ),
				'to_status_id'    => absint( self::post( 'to_status_id' ) ),
				'requires_reason' => self::post( 'requires_reason' ) ? 1 : 0,
				'requires_date'   => self::post( 'requires_date' ) ? 1 : 0,
				'on_transition'   => self::post( 'on_transition' ),
			)
		);

		return self::to( 'statuses', array(), __( 'Übergang gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht einen Statusübergang.
	 *
	 * @return array
	 */
	private static function do_delete_transition() {
		SVM_Statuses::delete_transition( self::id() );

		return self::to( 'statuses', array(), __( 'Übergang gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert eine Rolle.
	 *
	 * @return array
	 */
	private static function do_save_role() {
		SVM_Roles::save(
			array(
				'id'               => self::id(),
				'role_key'         => sanitize_key( self::post( 'role_key' ) ),
				'label'            => self::post( 'label' ),
				'description'      => self::post( 'description' ),
				'scope'            => self::post( 'scope', 'self' ),
				'include_subunits' => self::post( 'include_subunits' ) ? 1 : 0,
				'is_member_role'   => self::post( 'is_member_role' ) ? 1 : 0,
				'sort_order'       => absint( self::post( 'sort_order' ) ),
			),
			array_map( 'sanitize_key', (array) self::post( 'permissions', array() ) )
		);

		return self::to( 'roles', array(), __( 'Rolle gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Rolle.
	 *
	 * @return array|WP_Error
	 */
	private static function do_delete_role() {
		$result = SVM_Roles::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'roles', array(), __( 'Rolle gelöscht.', 'svm' ) );
	}

	/**
	 * Weist einem Benutzer Rollen zu.
	 *
	 * @return array
	 */
	private static function do_assign_user() {
		$user_id = absint( self::post( 'user_id' ) );

		SVM_Roles::assign_user( $user_id, array_map( 'absint', (array) self::post( 'role_ids', array() ) ) );
		SVM_Roles::assign_user_units( $user_id, array_map( 'absint', (array) self::post( 'unit_ids', array() ) ) );

		return self::to( 'team', array(), __( 'Zuordnung gespeichert.', 'svm' ) );
	}

	/**
	 * Speichert eine Zahlart.
	 *
	 * @return array
	 */
	private static function do_save_payment_method() {
		SVM_Payment_Methods::save(
			array(
				'id'                    => self::id(),
				'label'                 => self::post( 'label' ),
				'method_key'            => sanitize_key( self::post( 'method_key' ) ),
				'behavior'              => self::post( 'behavior', 'manual' ),
				'requires_mandate'      => self::post( 'requires_mandate' ) ? 1 : 0,
				'requires_bank_account' => self::post( 'requires_bank_account' ) ? 1 : 0,
				'is_default'            => self::post( 'is_default' ) ? 1 : 0,
				'allow_self_select'     => self::post( 'allow_self_select' ) ? 1 : 0,
				'is_active'             => self::post( 'is_active' ) ? 1 : 0,
				'sort_order'            => absint( self::post( 'sort_order' ) ),
			)
		);

		return self::to( 'methods', array(), __( 'Zahlart gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Zahlart.
	 *
	 * @return array|WP_Error
	 */
	private static function do_delete_payment_method() {
		$result = SVM_Payment_Methods::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'methods', array(), __( 'Zahlart gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert einen Nummernkreis.
	 *
	 * @return array
	 */
	private static function do_save_number_range() {
		SVM_Number_Range::save(
			array(
				'id'         => self::id(),
				'range_key'  => sanitize_key( self::post( 'range_key' ) ),
				'label'      => self::post( 'label' ),
				'prefix'     => self::post( 'prefix' ),
				'use_year'   => self::post( 'use_year' ) ? 1 : 0,
				'digits'     => max( 1, absint( self::post( 'digits' ) ) ),
				'next_value' => max( 1, absint( self::post( 'next_value' ) ) ),
			)
		);

		return self::to( 'numbers', array(), __( 'Nummernkreis gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht einen Nummernkreis.
	 *
	 * @return array
	 */
	private static function do_delete_number_range() {
		SVM_Number_Range::delete( self::id() );

		return self::to( 'numbers', array(), __( 'Nummernkreis gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert eine Vorlage.
	 *
	 * @return array
	 */
	private static function do_save_template() {
		SVM_Templates::save(
			array(
				'id'           => self::id(),
				'template_key' => sanitize_key( self::post( 'template_key' ) ),
				'label'        => self::post( 'label' ),
				'type'         => self::post( 'type', 'email' ),
				'subject'      => self::post( 'subject' ),
				'body'         => self::post_html( 'body' ),
			)
		);

		return self::to( 'templates', array(), __( 'Vorlage gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Vorlage.
	 *
	 * @return array
	 */
	private static function do_delete_template() {
		SVM_Templates::delete( self::id() );

		return self::to( 'templates', array(), __( 'Vorlage gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert die Grundeinstellungen.
	 *
	 * @return array
	 */
	private static function do_save_settings() {
		update_option( 'svm_club_name', self::post( 'club_name' ) );
		update_option( 'svm_portal_page_id', absint( self::post( 'portal_page_id' ) ) );
		update_option( 'svm_fiscal_year_start', self::post( 'fiscal_year_start', '01-01' ) );
		update_option( 'svm_retention_months', absint( self::post( 'retention_months' ) ) );

		return self::to( 'settings', array(), __( 'Einstellungen gespeichert.', 'svm' ) );
	}

	/**
	 * Prüft die Datenbank und legt fehlende Tabellen an.
	 *
	 * @return array
	 */
	private static function do_repair_schema() {
		SVM_Installer::migrate();

		$missing = SVM_Installer::known_missing();

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'svm_schema_incomplete',
				sprintf(
					/* translators: %s: Liste der fehlenden Tabellen. */
					__( 'Diese Tabellen konnten nicht angelegt werden: %s. Bitte prüfen Sie die Rechte des Datenbankbenutzers.', 'svm' ),
					implode( ', ', $missing )
				)
			);
		}

		return self::to( 'settings', array(), __( 'Die Datenbank ist vollständig. Fehlende Vorlagen wurden ergänzt.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Beiträge
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert eine Beitragsart.
	 *
	 * @return array
	 */
	private static function do_save_fee_type() {
		$fee_type_id = SVM_Fee_Types::save(
			array(
				'id'              => self::id(),
				'label'           => self::post( 'label' ),
				'description'     => self::post( 'description' ),
				'account_code'    => self::post( 'account_code' ),
				'amount'          => self::post( 'amount' ),
				'amount_mode'     => self::post( 'amount_mode', 'fixed' ),
				'amount_field_id' => absint( self::post( 'amount_field_id' ) ),
				'cycle'           => self::post( 'cycle', 'yearly' ),
				'due_rule'        => self::post( 'due_rule' ),
				'proration'       => self::post( 'proration', 'none' ),
				'valid_from'      => self::date( 'valid_from' ),
				'valid_to'        => self::date( 'valid_to' ),
				'priority'        => absint( self::post( 'priority' ) ),
				'is_active'       => self::post( 'is_active' ) ? 1 : 0,
			)
		);

		$existing = SVM_Fee_Types::condition_rule( $fee_type_id );

		SVM_Rules::save(
			array(
				'id'         => $existing ? (int) $existing['id'] : 0,
				'rule_type'  => 'fee_condition',
				'owner_type' => 'fee_type',
				'owner_id'   => $fee_type_id,
				'label'      => self::post( 'label' ),
				'logic'      => 'OR' === self::post( 'logic' ) ? 'OR' : 'AND',
			),
			self::collect_conditions()
		);

		return self::to( 'fee', array( 'id' => $fee_type_id ), __( 'Beitragsart gespeichert.', 'svm' ) );
	}

	/**
	 * Liest die Bedingungszeilen aus dem Formular.
	 *
	 * @return array
	 */
	private static function collect_conditions() {
		$raw        = self::post_array( 'condition' );
		$conditions = array();

		foreach ( $raw as $row ) {
			if ( empty( $row['subject'] ) ) {
				continue;
			}

			$value = isset( $row['compare_value'] ) ? $row['compare_value'] : '';

			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'sanitize_text_field', $value ) );
			} else {
				$value = sanitize_text_field( $value );
			}

			$conditions[] = array(
				'subject'         => sanitize_key( $row['subject'] ),
				'field_id'        => isset( $row['field_id'] ) ? absint( $row['field_id'] ) : 0,
				'operator'        => isset( $row['operator'] ) ? sanitize_key( $row['operator'] ) : 'equals',
				'compare_value'   => $value,
				'compare_value_2' => isset( $row['compare_value_2'] ) ? sanitize_text_field( $row['compare_value_2'] ) : '',
				'reference_date'  => isset( $row['reference_date'] ) ? sanitize_key( $row['reference_date'] ) : 'year_start',
			);
		}

		return $conditions;
	}

	/**
	 * Löscht eine Beitragsart.
	 *
	 * @return array
	 */
	private static function do_delete_fee_type() {
		SVM_Fee_Types::delete( self::id() );

		return self::to( 'fees', array(), __( 'Beitragsart gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert eine Rabatt- oder Zuschlagsregel.
	 *
	 * @return array
	 */
	private static function do_save_rule() {
		SVM_Rules::save(
			array(
				'id'               => self::id(),
				'rule_type'        => 'fee_adjustment',
				'owner_type'       => 'fee_type',
				'owner_id'         => absint( self::post( 'owner_id' ) ),
				'label'            => self::post( 'label' ),
				'logic'            => 'OR' === self::post( 'logic' ) ? 'OR' : 'AND',
				'adjustment_type'  => self::post( 'adjustment_type', 'none' ),
				'adjustment_value' => SVM_Fields::to_number( self::post( 'adjustment_value' ) ),
				'applies_to'       => self::post( 'applies_to', 'line' ),
				'stackable'        => self::post( 'stackable' ) ? 1 : 0,
				'priority'         => absint( self::post( 'priority' ) ),
				'is_active'        => self::post( 'is_active' ) ? 1 : 0,
			),
			self::collect_conditions()
		);

		return self::to( 'discounts', array(), __( 'Regel gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Regel.
	 *
	 * @return array
	 */
	private static function do_delete_rule() {
		SVM_Rules::delete( self::id() );

		return self::to( 'discounts', array(), __( 'Regel gelöscht.', 'svm' ) );
	}

	/**
	 * Schreibt einen Beitragslauf fest.
	 *
	 * @return array
	 */
	private static function do_commit_fee_run() {
		$result = SVM_Fee_Run::execute(
			array(
				'period_start'  => self::post( 'period_start' ),
				'period_end'    => self::post( 'period_end' ),
				'label'         => self::post( 'label' ),
				'unit_id'       => absint( self::post( 'unit_id' ) ),
				'skip_existing' => true,
			),
			true
		);

		return self::to(
			'feerun',
			array(),
			sprintf(
				/* translators: 1: Anzahl Forderungen, 2: Gesamtbetrag. */
				__( 'Beitragslauf abgeschlossen: %1$d Forderungen über %2$s €.', 'svm' ),
				$result['totals']['invoices'],
				number_format_i18n( $result['totals']['amount'], 2 )
			)
		);
	}

	/**
	 * Nimmt einen Beitragslauf zurück.
	 *
	 * @return array|WP_Error
	 */
	private static function do_delete_fee_run() {
		$result = SVM_Fee_Run::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to(
			'feerun',
			array(),
			sprintf(
				/* translators: %d: Anzahl geloeschter Forderungen. */
				__( 'Beitragslauf zurückgenommen: %d Forderungen gelöscht.', 'svm' ),
				$result
			)
		);
	}

	/**
	 * Löscht eine Forderung endgültig.
	 *
	 * @return array|WP_Error
	 */
	private static function do_delete_invoice() {
		$result = SVM_Invoices::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'invoices', array(), __( 'Forderung gelöscht.', 'svm' ) );
	}

	/**
	 * Storniert eine Forderung.
	 *
	 * @return array
	 */
	private static function do_cancel_invoice() {
		SVM_Invoices::cancel( self::id(), self::post( 'reason' ) );

		return self::to( 'invoices', array(), __( 'Forderung storniert.', 'svm' ) );
	}

	/**
	 * Legt eine Forderung von Hand an.
	 *
	 * @return array
	 */
	private static function do_create_invoice() {
		$member_id = absint( self::post( 'member_id' ) );

		SVM_Invoices::create(
			array(
				'member_id'    => $member_id,
				'description'  => self::post( 'description' ),
				'period_start' => self::date( 'period_start' ),
				'period_end'   => self::date( 'period_end' ),
				'due_date'     => self::date( 'due_date' ),
				'amount'       => SVM_Fields::to_number( self::post( 'amount' ) ),
			)
		);

		return self::to( 'member', array( 'id' => $member_id, 'tab' => 'fees' ), __( 'Forderung angelegt.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Zahlungen
	 * ------------------------------------------------------------------ */

	/**
	 * Erfasst eine Zahlung.
	 *
	 * @return array|WP_Error
	 */
	private static function do_record_payment() {
		$allocations = array();

		foreach ( self::post_array( 'allocation' ) as $invoice_id => $amount ) {
			$amount = SVM_Fields::to_number( $amount );

			if ( $amount > 0 ) {
				$allocations[ (int) $invoice_id ] = $amount;
			}
		}

		$result = SVM_Payments::record(
			array(
				'member_id'         => absint( self::post( 'member_id' ) ),
				'payment_method_id' => absint( self::post( 'payment_method_id' ) ),
				'amount'            => self::post( 'amount' ),
				'paid_at'           => self::post( 'paid_at' ),
				'payer_name'        => self::post( 'payer_name' ),
				'reference'         => self::post( 'reference' ),
				'note'              => self::post( 'note' ),
			),
			$allocations
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'payments', array(), __( 'Zahlung erfasst.', 'svm' ) );
	}

	/**
	 * Löscht eine Zahlung.
	 *
	 * @return array
	 */
	private static function do_delete_payment() {
		SVM_Payments::delete( self::id() );

		return self::to( 'payments', array(), __( 'Zahlung gelöscht.', 'svm' ) );
	}

	/**
	 * Kennzeichnet eine Zahlung als Rücklastschrift.
	 *
	 * @return array
	 */
	private static function do_return_payment() {
		SVM_Payments::mark_returned( self::id(), SVM_Fields::to_number( self::post( 'return_fee' ) ) );

		return self::to( 'payments', array(), __( 'Als Rücklastschrift gekennzeichnet.', 'svm' ) );
	}

	/**
	 * Speichert ein Mandat.
	 *
	 * @return array|WP_Error
	 */
	private static function do_save_mandate() {
		$member_id = absint( self::post( 'member_id' ) );

		$result = SVM_Mandates::save(
			array(
				'id'             => self::id(),
				'member_id'      => $member_id,
				'mandate_ref'    => self::post( 'mandate_ref' ),
				'iban'           => self::post( 'iban' ),
				'bic'            => self::post( 'bic' ),
				'account_holder' => self::post( 'account_holder' ),
				'signed_at'      => self::date( 'signed_at' ),
				'sequence_type'  => self::post( 'sequence_type', 'FRST' ),
				'status'         => self::post( 'status', 'active' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'member', array( 'id' => $member_id, 'tab' => 'bank' ), __( 'Mandat gespeichert.', 'svm' ) );
	}

	/**
	 * Widerruft ein Mandat.
	 *
	 * @return array
	 */
	private static function do_revoke_mandate() {
		$member_id = absint( self::post( 'member_id' ) );

		SVM_Mandates::revoke( self::id() );

		return self::to( 'member', array( 'id' => $member_id, 'tab' => 'bank' ), __( 'Mandat widerrufen.', 'svm' ) );
	}

	/**
	 * Löscht ein noch nie verwendetes Mandat.
	 *
	 * @return array|WP_Error
	 */
	private static function do_delete_mandate() {
		$member_id = absint( self::post( 'member_id' ) );
		$result    = SVM_Mandates::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'member', array( 'id' => $member_id, 'tab' => 'bank' ), __( 'Mandat gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert ein Dateiprofil.
	 *
	 * @return array|WP_Error
	 */
	private static function do_save_file_profile() {
		$result = SVM_File_Profiles::save(
			array(
				'id'               => self::id(),
				'label'            => self::post( 'label' ),
				'format'           => self::post( 'format', 'pain.008.001.02' ),
				'creditor_id'      => self::post( 'creditor_id' ),
				'creditor_name'    => self::post( 'creditor_name' ),
				'creditor_iban'    => self::post( 'creditor_iban' ),
				'creditor_bic'     => self::post( 'creditor_bic' ),
				'purpose_template' => self::post( 'purpose_template' ),
				'batch_booking'    => self::post( 'batch_booking' ) ? 1 : 0,
				'lead_days_frst'   => absint( self::post( 'lead_days_frst' ) ),
				'lead_days_rcur'   => absint( self::post( 'lead_days_rcur' ) ),
				'csv_delimiter'    => self::post( 'csv_delimiter', ';' ),
				'is_active'        => self::post( 'is_active' ) ? 1 : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'bankaccounts', array(), __( 'Bankprofil gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht ein Dateiprofil.
	 *
	 * @return array
	 */
	private static function do_delete_file_profile() {
		SVM_File_Profiles::delete( self::id() );

		return self::to( 'bankaccounts', array(), __( 'Bankprofil gelöscht.', 'svm' ) );
	}

	/**
	 * Erzeugt einen Zahllauf.
	 *
	 * @return array|WP_Error
	 */
	private static function do_create_payment_run() {
		$result = SVM_Payment_Runs::create(
			array(
				'file_profile_id' => absint( self::post( 'file_profile_id' ) ),
				'collection_date' => self::post( 'collection_date' ),
				'label'           => self::post( 'label' ),
				'unit_id'         => absint( self::post( 'unit_id' ) ),
				'due_before'      => self::post( 'due_before' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to(
			'sepa',
			array(),
			sprintf(
				/* translators: %d: Anzahl Positionen. */
				__( 'Zahllauf mit %d Positionen erzeugt — die Datei steht zum Download bereit.', 'svm' ),
				$result['totals']['count']
			)
		);
	}

	/**
	 * Setzt den Status eines Zahllaufs.
	 *
	 * @return array
	 */
	private static function do_set_run_status() {
		SVM_Payment_Runs::set_status( self::id(), self::post( 'status', 'created' ) );

		return self::to( 'sepa', array(), __( 'Status aktualisiert.', 'svm' ) );
	}

	/**
	 * Löscht einen stornierten Zahllauf.
	 *
	 * @return array|WP_Error
	 */
	private static function do_delete_payment_run() {
		$result = SVM_Payment_Runs::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::to( 'sepa', array(), __( 'Zahllauf gelöscht.', 'svm' ) );
	}

	/**
	 * Lädt die Datei eines Zahllaufs herunter.
	 *
	 * @return array|WP_Error
	 */
	private static function do_download_run_file() {
		$run = SVM_Payment_Runs::get( self::id() );

		if ( ! $run ) {
			return new WP_Error( 'svm_run_missing', __( 'Zahllauf nicht gefunden.', 'svm' ) );
		}

		SVM_Audit::log( 'payment_run', (int) $run['id'], 'downloaded' );

		self::send_download(
			$run['file_content'],
			$run['file_name'],
			'csv' === substr( $run['file_name'], -3 ) ? 'text/csv' : 'application/xml'
		);

		return array();
	}

	/**
	 * Speichert eine Mahnstufe.
	 *
	 * @return array
	 */
	private static function do_save_dunning_level() {
		SVM_Dunning::save_level(
			array(
				'id'             => self::id(),
				'level_no'       => max( 1, absint( self::post( 'level_no' ) ) ),
				'label'          => self::post( 'label' ),
				'days_after_due' => absint( self::post( 'days_after_due' ) ),
				'fee'            => SVM_Fields::to_number( self::post( 'fee' ) ),
				'template_id'    => absint( self::post( 'template_id' ) ),
				'is_automatic'   => self::post( 'is_automatic' ) ? 1 : 0,
				'is_active'      => self::post( 'is_active' ) ? 1 : 0,
			)
		);

		return self::to( 'dunning', array(), __( 'Mahnstufe gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Mahnstufe.
	 *
	 * @return array
	 */
	private static function do_delete_dunning_level() {
		SVM_Dunning::delete_level( self::id() );

		return self::to( 'dunning', array(), __( 'Mahnstufe gelöscht.', 'svm' ) );
	}

	/**
	 * Führt die automatischen Mahnstufen aus.
	 *
	 * @return array
	 */
	private static function do_apply_dunning() {
		$count = SVM_Dunning::run_automatic();

		return self::to(
			'dunning',
			array(),
			sprintf(
				/* translators: %d: Anzahl Forderungen. */
				__( 'Mahnlauf abgeschlossen: %d Forderungen bearbeitet.', 'svm' ),
				$count
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Nachrichten
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert eine Nachricht.
	 *
	 * @return array
	 */
	private static function do_save_message() {
		$targets = array();

		foreach ( self::post_array( 'target' ) as $row ) {
			if ( empty( $row['target_type'] ) ) {
				continue;
			}

			$targets[] = array(
				'target_type' => sanitize_key( $row['target_type'] ),
				'target_id'   => isset( $row['target_id'] ) ? absint( $row['target_id'] ) : 0,
			);
		}

		SVM_Messages::save(
			array(
				'id'           => self::id(),
				'category_id'  => absint( self::post( 'category_id' ) ),
				'title'        => self::post( 'title' ),
				'body'         => self::post_html( 'body' ),
				'visible_from' => self::date( 'visible_from' ),
				'visible_to'   => self::date( 'visible_to' ),
				'is_important' => self::post( 'is_important' ) ? 1 : 0,
				'requires_ack' => self::post( 'requires_ack' ) ? 1 : 0,
				'send_email'   => self::post( 'send_email' ) ? 1 : 0,
				'status'       => self::post( 'status', 'draft' ),
			),
			$targets
		);

		return self::to( 'messages', array(), __( 'Nachricht gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Nachricht.
	 *
	 * @return array
	 */
	private static function do_delete_message() {
		SVM_Messages::delete( self::id() );

		return self::to( 'messages', array(), __( 'Nachricht gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert eine Nachrichtenkategorie.
	 *
	 * @return array
	 */
	private static function do_save_message_category() {
		SVM_Messages::save_category(
			array(
				'id'           => self::id(),
				'label'        => self::post( 'label' ),
				'color'        => self::post( 'color' ),
				'is_important' => self::post( 'is_important' ) ? 1 : 0,
				'send_email'   => self::post( 'send_email' ) ? 1 : 0,
				'is_active'    => self::post( 'is_active' ) ? 1 : 0,
				'sort_order'   => absint( self::post( 'sort_order' ) ),
			)
		);

		return self::to( 'messages', array( 'tab' => 'categories' ), __( 'Kategorie gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Nachrichtenkategorie.
	 *
	 * @return array
	 */
	private static function do_delete_message_category() {
		SVM_Messages::delete_category( self::id() );

		return self::to( 'messages', array( 'tab' => 'categories' ), __( 'Kategorie gelöscht.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Daten
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert ein Export-Profil.
	 *
	 * @return array
	 */
	private static function do_save_export_profile() {
		SVM_Export_Profiles::save(
			array(
				'id'            => self::id(),
				'label'         => self::post( 'label' ),
				'entity_type'   => self::post( 'entity_type', 'member' ),
				'columns_json'  => array_map( 'sanitize_text_field', (array) self::post( 'columns', array() ) ),
				'format'        => self::post( 'format', 'csv' ),
				'delimiter'     => self::post( 'delimiter', ';' ),
				'charset'       => self::post( 'charset', 'UTF-8' ),
				'allowed_roles' => array_map( 'absint', (array) self::post( 'allowed_roles', array() ) ),
				'is_active'     => self::post( 'is_active' ) ? 1 : 0,
			)
		);

		return self::to( 'export', array(), __( 'Export-Vorlage gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht ein Export-Profil.
	 *
	 * @return array
	 */
	private static function do_delete_export_profile() {
		SVM_Export_Profiles::delete( self::id() );

		return self::to( 'export', array(), __( 'Export-Vorlage gelöscht.', 'svm' ) );
	}

	/**
	 * Startet einen Export-Download.
	 *
	 * @return array|WP_Error
	 */
	private static function do_download_export() {
		$profile = SVM_Export_Profiles::get( self::id() );

		if ( ! $profile ) {
			return new WP_Error( 'svm_export_missing', __( 'Export-Vorlage nicht gefunden.', 'svm' ) );
		}

		if ( ! SVM_Export_Profiles::can_run( $profile ) ) {
			return new WP_Error( 'svm_export_denied', __( 'Für diese Export-Vorlage fehlt die Berechtigung.', 'svm' ) );
		}

		SVM_Exporter::download( $profile );

		return array();
	}

	/**
	 * DSGVO-Auskunft.
	 *
	 * @return array|WP_Error
	 */
	private static function do_gdpr_export() {
		$member_id = self::id( 'member_id' );

		if ( ! SVM_Permissions::can_access_member( $member_id ) ) {
			return new WP_Error( 'svm_denied', __( 'Kein Zugriff auf dieses Mitglied.', 'svm' ) );
		}

		SVM_GDPR::download( $member_id );

		return array();
	}

	/**
	 * Anonymisiert ein Mitglied.
	 *
	 * @return array
	 */
	private static function do_anonymize_member() {
		SVM_GDPR::anonymize( self::id( 'member_id' ) );

		return self::to( 'privacy', array(), __( 'Mitglied anonymisiert.', 'svm' ) );
	}

	/**
	 * Liest eine Importdatei ein und merkt sie für die Spaltenzuordnung vor.
	 *
	 * @return array|WP_Error
	 */
	private static function do_read_import() {
		$content = self::uploaded_file( 'import_file' );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$parsed = SVM_Importer::parse_csv( $content, self::post( 'delimiter', ';' ) );

		if ( empty( $parsed['header'] ) ) {
			return new WP_Error( 'svm_import_empty', __( 'In der Datei wurde keine Kopfzeile gefunden.', 'svm' ) );
		}

		set_transient( 'svm_import_' . get_current_user_id(), $parsed, DAY_IN_SECONDS );

		return self::to(
			'import',
			array( 'step' => 'map' ),
			sprintf(
				/* translators: %d: Anzahl Datenzeilen. */
				__( '%d Zeilen eingelesen. Bitte ordnen Sie die Spalten zu.', 'svm' ),
				count( $parsed['rows'] )
			)
		);
	}

	/**
	 * Führt den Import aus.
	 *
	 * @return array|WP_Error
	 */
	private static function do_run_import() {
		$stored = get_transient( 'svm_import_' . get_current_user_id() );

		if ( ! is_array( $stored ) || empty( $stored['rows'] ) ) {
			return new WP_Error( 'svm_import_expired', __( 'Die eingelesenen Daten sind nicht mehr verfügbar. Bitte die Datei erneut hochladen.', 'svm' ) );
		}

		$mapping = array_map( 'sanitize_text_field', (array) self::post_array( 'mapping' ) );
		$dry_run = 'yes' === self::post( 'dry_run' );

		$result = SVM_Importer::run(
			$stored['rows'],
			$mapping,
			array(
				'dry_run'         => $dry_run,
				'update_existing' => (bool) self::post( 'update_existing' ),
			)
		);

		$message = $dry_run
			? sprintf(
				/* translators: 1: neu, 2: aktualisiert, 3: uebersprungen. */
				__( 'Testlauf: %1$d neu, %2$d aktualisiert, %3$d übersprungen. Es wurde nichts gespeichert.', 'svm' ),
				$result['created'],
				$result['updated'],
				$result['skipped']
			)
			: sprintf(
				/* translators: 1: neu, 2: aktualisiert, 3: uebersprungen. */
				__( 'Import abgeschlossen: %1$d neu, %2$d aktualisiert, %3$d übersprungen.', 'svm' ),
				$result['created'],
				$result['updated'],
				$result['skipped']
			);

		if ( ! empty( $result['errors'] ) ) {
			$message .= ' ' . implode( ' | ', array_slice( $result['errors'], 0, 3 ) );
		}

		if ( ! $dry_run ) {
			delete_transient( 'svm_import_' . get_current_user_id() );

			return self::to( 'import', array(), $message );
		}

		return self::to( 'import', array( 'step' => 'map' ), $message );
	}

	/**
	 * Exportiert die Konfiguration.
	 *
	 * @return array
	 */
	private static function do_export_config() {
		self::send_download(
			wp_json_encode( SVM_Config_Transfer::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
			'svm-konfiguration-' . gmdate( 'Y-m-d' ) . '.json',
			'application/json'
		);

		return array();
	}

	/**
	 * Importiert eine Konfiguration.
	 *
	 * @return array|WP_Error
	 */
	private static function do_import_config() {
		$content = self::uploaded_file( 'config_file' );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$config = json_decode( $content, true );

		if ( ! is_array( $config ) ) {
			return new WP_Error( 'svm_config_invalid', __( 'Die Datei enthält keine gültige Konfiguration.', 'svm' ) );
		}

		$count = SVM_Config_Transfer::import( $config );

		return self::to(
			'settings',
			array(),
			sprintf(
				/* translators: %d: Anzahl Eintraege. */
				__( 'Konfiguration importiert: %d Einträge.', 'svm' ),
				$count
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Hilfsfunktionen
	 * ------------------------------------------------------------------ */

	/**
	 * Liest eine hochgeladene Datei ein.
	 *
	 * @param string $key Feldname.
	 * @return string|WP_Error
	 */
	private static function uploaded_file( $key ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES[ $key ]['tmp_name'] ) ) {
			return new WP_Error( 'svm_no_file', __( 'Bitte eine Datei auswählen.', 'svm' ) );
		}

		if ( ! empty( $_FILES[ $key ]['error'] ) ) {
			return new WP_Error( 'svm_upload_error', __( 'Die Datei konnte nicht hochgeladen werden.', 'svm' ) );
		}

		$tmp = sanitize_text_field( wp_unslash( $_FILES[ $key ]['tmp_name'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'svm_upload_invalid', __( 'Ungültiger Upload.', 'svm' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$content = file_get_contents( $tmp );

		if ( false === $content ) {
			return new WP_Error( 'svm_read_error', __( 'Die Datei konnte nicht gelesen werden.', 'svm' ) );
		}

		return $content;
	}

	/**
	 * Sendet einen Dateidownload.
	 *
	 * @param string $content   Inhalt.
	 * @param string $filename  Dateiname.
	 * @param string $mime_type MIME-Typ.
	 * @return void
	 */
	private static function send_download( $content, $filename, $mime_type ) {
		nocache_headers();
		header( 'Content-Type: ' . $mime_type . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( (string) $content ) );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
