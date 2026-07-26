<?php
/**
 * Verarbeitung aller Formularaktionen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Jede Aktion prüft Nonce und Recht, bevor sie ausgeführt wird.
 */
class SVM_Admin_Router {

	/**
	 * Aktionen und die dafür nötigen Rechte.
	 *
	 * @return array action => permission
	 */
	private static function actions() {
		return array(
			'save_member'          => 'members_edit',
			'delete_member'        => 'members_delete',
			'change_status'        => 'members_status',
			'save_member_units'    => 'members_edit',
			'approve_change'       => 'change_requests',
			'reject_change'        => 'change_requests',
			'save_field'           => 'fields_manage',
			'delete_field'         => 'fields_manage',
			'save_field_access'    => 'fields_manage',
			'save_unit'            => 'units_manage',
			'delete_unit'          => 'units_manage',
			'save_unit_type'       => 'units_manage',
			'save_status'          => 'settings_manage',
			'delete_status'        => 'settings_manage',
			'save_transition'      => 'settings_manage',
			'save_role'            => 'roles_manage',
			'delete_role'          => 'roles_manage',
			'assign_user'          => 'roles_manage',
			'save_payment_method'  => 'settings_manage',
			'delete_payment_method' => 'settings_manage',
			'save_number_range'    => 'settings_manage',
			'save_template'        => 'settings_manage',
			'delete_template'      => 'settings_manage',
			'save_settings'        => 'settings_manage',
			'save_fee_type'        => 'fees_manage',
			'delete_fee_type'      => 'fees_manage',
			'save_rule'            => 'fees_manage',
			'delete_rule'          => 'fees_manage',
			'commit_fee_run'       => 'fee_run',
			'cancel_invoice'       => 'invoices_edit',
			'create_invoice'       => 'invoices_edit',
			'record_payment'       => 'payments_record',
			'delete_payment'       => 'payments_record',
			'return_payment'       => 'payments_record',
			'save_mandate'         => 'mandates_manage',
			'revoke_mandate'       => 'mandates_manage',
			'save_file_profile'    => 'payment_run',
			'delete_file_profile'  => 'payment_run',
			'create_payment_run'   => 'payment_run',
			'set_run_status'       => 'payment_run',
			'return_run_item'      => 'payment_run',
			'save_dunning_level'   => 'dunning_manage',
			'delete_dunning_level' => 'dunning_manage',
			'apply_dunning'        => 'dunning_manage',
			'save_message'         => 'messages_manage',
			'delete_message'       => 'messages_manage',
			'save_message_category' => 'messages_manage',
			'save_export_profile'  => 'export_manage',
			'delete_export_profile' => 'export_manage',
			'download_export'      => 'export_run',
			'download_run_file'    => 'payment_run',
			'gdpr_export'          => 'members_view',
			'anonymize_member'     => 'members_delete',
			'run_import'           => 'import_run',
			'export_config'        => 'settings_manage',
			'import_config'        => 'settings_manage',
		);
	}

	/**
	 * Nimmt eine Aktion entgegen.
	 *
	 * @return void
	 */
	public static function handle_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['svm_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['svm_action'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		$actions = self::actions();

		if ( ! isset( $actions[ $action ] ) ) {
			return;
		}

		check_admin_referer( 'svm_' . $action, '_svm_nonce' );
		SVM_Permissions::require_permission( $actions[ $action ] );

		$method = 'handle_' . $action;

		if ( ! method_exists( __CLASS__, $method ) ) {
			return;
		}

		$result = self::$method();

		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'svm_error' => $result->get_error_message() ) );
		}

		self::redirect( is_array( $result ) ? $result : array() );
	}

	/**
	 * Leitet zurück zur Ausgangsseite.
	 *
	 * @param array $args Zusätzliche Parameter.
	 * @return void
	 */
	private static function redirect( array $args = array() ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_REQUEST['svm_page'] ) ? sanitize_key( wp_unslash( $_REQUEST['svm_page'] ) ) : 'svm-dashboard';
		$tab  = isset( $_REQUEST['svm_tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['svm_tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$base = array( 'page' => $page );

		if ( '' !== $tab ) {
			$base['tab'] = $tab;
		}

		if ( ! isset( $args['svm_message'] ) && ! isset( $args['svm_error'] ) ) {
			$args['svm_message'] = __( 'Gespeichert.', 'svm' );
		}

		wp_safe_redirect( add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

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
	 * Liest ein Rohdaten-Array (z. B. Feldwerte).
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
		// phpcs:ignore WordPress.Security.NonceVerification
		return isset( $_REQUEST[ $key ] ) ? absint( $_REQUEST[ $key ] ) : 0;
	}

	/* ---------------------------------------------------------------------
	 * Mitglieder
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert ein Mitglied samt Feldwerten.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_save_member() {
		$member_id = self::id( 'member_id' );

		$core = array(
			'status_id'         => absint( self::post( 'status_id' ) ),
			'payment_method_id' => absint( self::post( 'payment_method_id' ) ),
			'joined_at'         => self::post( 'joined_at' ) ? self::post( 'joined_at' ) : null,
			'left_at'           => self::post( 'left_at' ) ? self::post( 'left_at' ) : null,
			'family_group_id'   => absint( self::post( 'family_group_id' ) ),
			'family_position'   => absint( self::post( 'family_position' ) ),
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

		$values = SVM_Form::collect_field_values( 'member', $member_id, self::post_array( 'svm_field' ) );

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

		$message = empty( $values['pending'] )
			? __( 'Mitglied gespeichert.', 'svm' )
			: __( 'Mitglied gespeichert. Änderungen an freigabepflichtigen Feldern warten auf Bestätigung.', 'svm' );

		return array(
			'svm_message' => $message,
			'member_id'   => $member_id,
			'view'        => 'edit',
		);
	}

	/**
	 * Löscht ein Mitglied.
	 *
	 * @return array
	 */
	private static function handle_delete_member() {
		$member_id = self::id( 'member_id' );

		if ( 'yes' === self::post( 'hard_delete' ) ) {
			SVM_Members::hard_delete( $member_id );
		} else {
			SVM_Members::soft_delete( $member_id );
		}

		return array( 'svm_message' => __( 'Mitglied gelöscht.', 'svm' ) );
	}

	/**
	 * Ändert den Status eines Mitglieds.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_change_status() {
		$result = SVM_Members::change_status(
			self::id( 'member_id' ),
			absint( self::post( 'status_id' ) ),
			self::post( 'reason' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'svm_message' => __( 'Status geändert.', 'svm' ),
			'member_id'   => self::id( 'member_id' ),
			'view'        => 'edit',
		);
	}

	/**
	 * Speichert die Einheitenzuordnung.
	 *
	 * @return array
	 */
	private static function handle_save_member_units() {
		$member_id = self::id( 'member_id' );

		SVM_Members::set_units( $member_id, array_map( 'absint', (array) self::post( 'unit_ids', array() ) ) );

		return array(
			'svm_message' => __( 'Zuordnung gespeichert.', 'svm' ),
			'member_id'   => $member_id,
			'view'        => 'edit',
		);
	}

	/**
	 * Genehmigt einen Änderungsantrag.
	 *
	 * @return array
	 */
	private static function handle_approve_change() {
		SVM_Change_Requests::approve( self::id(), self::post( 'note' ) );

		return array( 'svm_message' => __( 'Änderung übernommen.', 'svm' ) );
	}

	/**
	 * Lehnt einen Änderungsantrag ab.
	 *
	 * @return array
	 */
	private static function handle_reject_change() {
		SVM_Change_Requests::reject( self::id(), self::post( 'note' ) );

		return array( 'svm_message' => __( 'Änderung abgelehnt.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Konfiguration
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert eine Felddefinition.
	 *
	 * @return array
	 */
	private static function handle_save_field() {
		$id = SVM_Fields::save_def(
			array(
				'id'               => self::id(),
				'entity_type'      => self::post( 'entity_type', 'member' ),
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

		if ( is_string( $options ) && '' !== trim( $options ) ) {
			SVM_Fields::set_options( $id, preg_split( '/\r\n|\r|\n/', $options ) );
		} elseif ( is_string( $options ) ) {
			SVM_Fields::set_options( $id, array() );
		}

		return array( 'svm_message' => __( 'Feld gespeichert.', 'svm' ), 'field_id' => $id );
	}

	/**
	 * Löscht eine Felddefinition.
	 *
	 * @return array
	 */
	private static function handle_delete_field() {
		SVM_Fields::delete_def( self::id() );

		return array( 'svm_message' => __( 'Feld gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert die Feldrechte je Rolle.
	 *
	 * @return array
	 */
	private static function handle_save_field_access() {
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

		return array( 'svm_message' => __( 'Feldrechte gespeichert.', 'svm' ), 'field_id' => $field_id );
	}

	/**
	 * Speichert eine Einheit.
	 *
	 * @return array
	 */
	private static function handle_save_unit() {
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

		return array( 'svm_message' => __( 'Einheit gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Einheit.
	 *
	 * @return array
	 */
	private static function handle_delete_unit() {
		SVM_Units::delete( self::id() );

		return array( 'svm_message' => __( 'Einheit gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert einen Einheitstyp.
	 *
	 * @return array
	 */
	private static function handle_save_unit_type() {
		SVM_Units::save_type(
			array(
				'id'         => self::id(),
				'label'      => self::post( 'label' ),
				'sort_order' => absint( self::post( 'sort_order' ) ),
			)
		);

		return array( 'svm_message' => __( 'Einheitstyp gespeichert.', 'svm' ) );
	}

	/**
	 * Speichert einen Status.
	 *
	 * @return array
	 */
	private static function handle_save_status() {
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

		return array( 'svm_message' => __( 'Status gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht einen Status.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_delete_status() {
		$result = SVM_Statuses::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'svm_message' => __( 'Status gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert einen Statusübergang.
	 *
	 * @return array
	 */
	private static function handle_save_transition() {
		SVM_Statuses::save_transition(
			array(
				'id'              => self::id(),
				'from_status_id'  => absint( self::post( 'from_status_id' ) ),
				'to_status_id'    => absint( self::post( 'to_status_id' ) ),
				'requires_reason' => self::post( 'requires_reason' ) ? 1 : 0,
				'requires_date'   => self::post( 'requires_date' ) ? 1 : 0,
				'on_transition'   => self::post( 'on_transition' ),
			)
		);

		return array( 'svm_message' => __( 'Übergang gespeichert.', 'svm' ) );
	}

	/**
	 * Speichert eine Rolle.
	 *
	 * @return array
	 */
	private static function handle_save_role() {
		$permissions = array_map( 'sanitize_key', (array) self::post( 'permissions', array() ) );

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
			$permissions
		);

		return array( 'svm_message' => __( 'Rolle gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Rolle.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_delete_role() {
		$result = SVM_Roles::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'svm_message' => __( 'Rolle gelöscht.', 'svm' ) );
	}

	/**
	 * Weist einem Benutzer Rollen und Einheiten zu.
	 *
	 * @return array
	 */
	private static function handle_assign_user() {
		$user_id = absint( self::post( 'user_id' ) );

		SVM_Roles::assign_user( $user_id, array_map( 'absint', (array) self::post( 'role_ids', array() ) ) );
		SVM_Roles::assign_user_units( $user_id, array_map( 'absint', (array) self::post( 'unit_ids', array() ) ) );

		return array( 'svm_message' => __( 'Zuordnung gespeichert.', 'svm' ) );
	}

	/**
	 * Speichert eine Zahlart.
	 *
	 * @return array
	 */
	private static function handle_save_payment_method() {
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

		return array( 'svm_message' => __( 'Zahlart gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Zahlart.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_delete_payment_method() {
		$result = SVM_Payment_Methods::delete( self::id() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'svm_message' => __( 'Zahlart gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert einen Nummernkreis.
	 *
	 * @return array
	 */
	private static function handle_save_number_range() {
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

		return array( 'svm_message' => __( 'Nummernkreis gespeichert.', 'svm' ) );
	}

	/**
	 * Speichert eine Vorlage.
	 *
	 * @return array
	 */
	private static function handle_save_template() {
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

		return array( 'svm_message' => __( 'Vorlage gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Vorlage.
	 *
	 * @return array
	 */
	private static function handle_delete_template() {
		SVM_Templates::delete( self::id() );

		return array( 'svm_message' => __( 'Vorlage gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert die Grundeinstellungen.
	 *
	 * @return array
	 */
	private static function handle_save_settings() {
		update_option( 'svm_club_name', self::post( 'club_name' ) );
		update_option( 'svm_portal_page_id', absint( self::post( 'portal_page_id' ) ) );
		update_option( 'svm_fiscal_year_start', self::post( 'fiscal_year_start', '01-01' ) );
		update_option( 'svm_retention_months', absint( self::post( 'retention_months' ) ) );
		update_option( 'svm_self_service_units', self::post( 'self_service_units' ) ? 1 : 0 );

		return array( 'svm_message' => __( 'Einstellungen gespeichert.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Beiträge
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert eine Beitragsart samt Bedingungen.
	 *
	 * @return array
	 */
	private static function handle_save_fee_type() {
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
				'valid_from'      => self::post( 'valid_from' ) ? self::post( 'valid_from' ) : null,
				'valid_to'        => self::post( 'valid_to' ) ? self::post( 'valid_to' ) : null,
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

		return array( 'svm_message' => __( 'Beitragsart gespeichert.', 'svm' ), 'fee_type_id' => $fee_type_id );
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
	private static function handle_delete_fee_type() {
		SVM_Fee_Types::delete( self::id() );

		return array( 'svm_message' => __( 'Beitragsart gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert eine Rabatt-/Zuschlagsregel.
	 *
	 * @return array
	 */
	private static function handle_save_rule() {
		SVM_Rules::save(
			array(
				'id'               => self::id(),
				'rule_type'        => self::post( 'rule_type', 'fee_adjustment' ),
				'owner_type'       => self::post( 'owner_type' ),
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

		return array( 'svm_message' => __( 'Regel gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Regel.
	 *
	 * @return array
	 */
	private static function handle_delete_rule() {
		SVM_Rules::delete( self::id() );

		return array( 'svm_message' => __( 'Regel gelöscht.', 'svm' ) );
	}

	/**
	 * Führt einen Beitragslauf verbindlich aus.
	 *
	 * @return array
	 */
	private static function handle_commit_fee_run() {
		$result = SVM_Fee_Run::execute(
			array(
				'period_start'  => self::post( 'period_start' ),
				'period_end'    => self::post( 'period_end' ),
				'label'         => self::post( 'label' ),
				'unit_id'       => absint( self::post( 'unit_id' ) ),
				'status_id'     => absint( self::post( 'status_id' ) ),
				'fee_type_ids'  => array_map( 'absint', (array) self::post( 'fee_type_ids', array() ) ),
				'skip_existing' => true,
			),
			true
		);

		return array(
			'svm_message' => sprintf(
				/* translators: 1: Anzahl Forderungen, 2: Gesamtbetrag. */
				__( 'Beitragslauf abgeschlossen: %1$d Forderungen über insgesamt %2$s €.', 'svm' ),
				$result['totals']['invoices'],
				number_format_i18n( $result['totals']['amount'], 2 )
			),
		);
	}

	/**
	 * Storniert eine Forderung.
	 *
	 * @return array
	 */
	private static function handle_cancel_invoice() {
		SVM_Invoices::cancel( self::id(), self::post( 'reason' ) );

		return array( 'svm_message' => __( 'Forderung storniert.', 'svm' ) );
	}

	/**
	 * Legt eine Forderung von Hand an.
	 *
	 * @return array
	 */
	private static function handle_create_invoice() {
		SVM_Invoices::create(
			array(
				'member_id'    => absint( self::post( 'member_id' ) ),
				'description'  => self::post( 'description' ),
				'period_start' => self::post( 'period_start' ) ? self::post( 'period_start' ) : null,
				'period_end'   => self::post( 'period_end' ) ? self::post( 'period_end' ) : null,
				'due_date'     => self::post( 'due_date' ) ? self::post( 'due_date' ) : null,
				'amount'       => SVM_Fields::to_number( self::post( 'amount' ) ),
			)
		);

		return array( 'svm_message' => __( 'Forderung angelegt.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Zahlungen
	 * ------------------------------------------------------------------ */

	/**
	 * Erfasst eine Zahlung.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_record_payment() {
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

		return array( 'svm_message' => __( 'Zahlung erfasst.', 'svm' ) );
	}

	/**
	 * Löscht eine Zahlung.
	 *
	 * @return array
	 */
	private static function handle_delete_payment() {
		SVM_Payments::delete( self::id() );

		return array( 'svm_message' => __( 'Zahlung gelöscht.', 'svm' ) );
	}

	/**
	 * Kennzeichnet eine Zahlung als Rücklastschrift.
	 *
	 * @return array
	 */
	private static function handle_return_payment() {
		SVM_Payments::mark_returned( self::id(), SVM_Fields::to_number( self::post( 'return_fee' ) ) );

		return array( 'svm_message' => __( 'Zahlung als Rücklastschrift gekennzeichnet.', 'svm' ) );
	}

	/**
	 * Speichert ein SEPA-Mandat.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_save_mandate() {
		$result = SVM_Mandates::save(
			array(
				'id'             => self::id(),
				'member_id'      => absint( self::post( 'member_id' ) ),
				'mandate_ref'    => self::post( 'mandate_ref' ),
				'iban'           => self::post( 'iban' ),
				'bic'            => self::post( 'bic' ),
				'account_holder' => self::post( 'account_holder' ),
				'signed_at'      => self::post( 'signed_at' ) ? self::post( 'signed_at' ) : null,
				'sequence_type'  => self::post( 'sequence_type', 'FRST' ),
				'status'         => self::post( 'status', 'active' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'svm_message' => __( 'Mandat gespeichert.', 'svm' ) );
	}

	/**
	 * Widerruft ein Mandat.
	 *
	 * @return array
	 */
	private static function handle_revoke_mandate() {
		SVM_Mandates::revoke( self::id() );

		return array( 'svm_message' => __( 'Mandat widerrufen.', 'svm' ) );
	}

	/**
	 * Speichert ein Dateiprofil.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_save_file_profile() {
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

		return array( 'svm_message' => __( 'Dateiprofil gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht ein Dateiprofil.
	 *
	 * @return array
	 */
	private static function handle_delete_file_profile() {
		SVM_File_Profiles::delete( self::id() );

		return array( 'svm_message' => __( 'Dateiprofil gelöscht.', 'svm' ) );
	}

	/**
	 * Erzeugt einen Zahllauf.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_create_payment_run() {
		$result = SVM_Payment_Runs::create(
			array(
				'file_profile_id' => absint( self::post( 'file_profile_id' ) ),
				'collection_date' => self::post( 'collection_date' ),
				'label'           => self::post( 'label' ),
				'unit_id'         => absint( self::post( 'unit_id' ) ),
				'fee_type_id'     => absint( self::post( 'fee_type_id' ) ),
				'due_before'      => self::post( 'due_before' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'svm_message' => sprintf(
				/* translators: %d: Anzahl Positionen. */
				__( 'Zahllauf mit %d Positionen erzeugt. Die Datei steht zum Download bereit.', 'svm' ),
				$result['totals']['count']
			),
			'run_id'      => $result['run_id'],
		);
	}

	/**
	 * Setzt den Status eines Zahllaufs.
	 *
	 * @return array
	 */
	private static function handle_set_run_status() {
		SVM_Payment_Runs::set_status( self::id(), self::post( 'status', 'created' ) );

		return array( 'svm_message' => __( 'Status des Zahllaufs aktualisiert.', 'svm' ) );
	}

	/**
	 * Kennzeichnet eine Position als Rücklastschrift.
	 *
	 * @return array
	 */
	private static function handle_return_run_item() {
		SVM_Payment_Runs::mark_item_returned( self::id(), SVM_Fields::to_number( self::post( 'return_fee' ) ) );

		return array( 'svm_message' => __( 'Position als Rücklastschrift gekennzeichnet.', 'svm' ) );
	}

	/**
	 * Speichert eine Mahnstufe.
	 *
	 * @return array
	 */
	private static function handle_save_dunning_level() {
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

		return array( 'svm_message' => __( 'Mahnstufe gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Mahnstufe.
	 *
	 * @return array
	 */
	private static function handle_delete_dunning_level() {
		SVM_Dunning::delete_level( self::id() );

		return array( 'svm_message' => __( 'Mahnstufe gelöscht.', 'svm' ) );
	}

	/**
	 * Führt die automatischen Mahnstufen aus.
	 *
	 * @return array
	 */
	private static function handle_apply_dunning() {
		$count = SVM_Dunning::run_automatic();

		return array(
			'svm_message' => sprintf(
				/* translators: %d: Anzahl Forderungen. */
				__( 'Mahnlauf abgeschlossen: %d Forderungen bearbeitet.', 'svm' ),
				$count
			),
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
	private static function handle_save_message() {
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
				'visible_from' => self::post( 'visible_from' ) ? self::post( 'visible_from' ) : null,
				'visible_to'   => self::post( 'visible_to' ) ? self::post( 'visible_to' ) : null,
				'is_important' => self::post( 'is_important' ) ? 1 : 0,
				'requires_ack' => self::post( 'requires_ack' ) ? 1 : 0,
				'send_email'   => self::post( 'send_email' ) ? 1 : 0,
				'status'       => self::post( 'status', 'draft' ),
			),
			$targets
		);

		return array( 'svm_message' => __( 'Nachricht gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht eine Nachricht.
	 *
	 * @return array
	 */
	private static function handle_delete_message() {
		SVM_Messages::delete( self::id() );

		return array( 'svm_message' => __( 'Nachricht gelöscht.', 'svm' ) );
	}

	/**
	 * Speichert eine Nachrichtenkategorie.
	 *
	 * @return array
	 */
	private static function handle_save_message_category() {
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

		return array( 'svm_message' => __( 'Kategorie gespeichert.', 'svm' ) );
	}

	/* ---------------------------------------------------------------------
	 * Auswertungen
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert ein Export-Profil.
	 *
	 * @return array
	 */
	private static function handle_save_export_profile() {
		SVM_Export_Profiles::save(
			array(
				'id'             => self::id(),
				'label'          => self::post( 'label' ),
				'entity_type'    => self::post( 'entity_type', 'member' ),
				'columns_json'   => array_map( 'sanitize_text_field', (array) self::post( 'columns', array() ) ),
				'filter_rule_id' => absint( self::post( 'filter_rule_id' ) ),
				'format'         => self::post( 'format', 'csv' ),
				'delimiter'      => self::post( 'delimiter', ';' ),
				'charset'        => self::post( 'charset', 'UTF-8' ),
				'allowed_roles'  => array_map( 'absint', (array) self::post( 'allowed_roles', array() ) ),
				'is_active'      => self::post( 'is_active' ) ? 1 : 0,
			)
		);

		return array( 'svm_message' => __( 'Export-Profil gespeichert.', 'svm' ) );
	}

	/**
	 * Löscht ein Export-Profil.
	 *
	 * @return array
	 */
	private static function handle_delete_export_profile() {
		SVM_Export_Profiles::delete( self::id() );

		return array( 'svm_message' => __( 'Export-Profil gelöscht.', 'svm' ) );
	}

	/**
	 * Startet einen Export-Download.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_download_export() {
		$profile = SVM_Export_Profiles::get( self::id() );

		if ( ! $profile ) {
			return new WP_Error( 'svm_export_missing', __( 'Export-Profil nicht gefunden.', 'svm' ) );
		}

		if ( ! SVM_Export_Profiles::can_run( $profile ) ) {
			return new WP_Error( 'svm_export_denied', __( 'Für dieses Export-Profil fehlt die Berechtigung.', 'svm' ) );
		}

		SVM_Exporter::download( $profile );

		return array();
	}

	/**
	 * Lädt die Datei eines Zahllaufs herunter.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_download_run_file() {
		$run = SVM_Payment_Runs::get( self::id() );

		if ( ! $run ) {
			return new WP_Error( 'svm_run_missing', __( 'Zahllauf nicht gefunden.', 'svm' ) );
		}

		SVM_Audit::log( 'payment_run', (int) $run['id'], 'downloaded' );

		nocache_headers();
		header( 'Content-Type: ' . ( 'csv' === substr( $run['file_name'], -3 ) ? 'text/csv' : 'application/xml' ) . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $run['file_name'] . '"' );

		echo $run['file_content']; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * DSGVO-Auskunft herunterladen.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_gdpr_export() {
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
	private static function handle_anonymize_member() {
		SVM_GDPR::anonymize( self::id( 'member_id' ) );

		return array( 'svm_message' => __( 'Mitglied anonymisiert.', 'svm' ) );
	}

	/**
	 * Führt einen Import aus.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_run_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
			return new WP_Error( 'svm_import_file', __( 'Bitte eine CSV-Datei auswählen.', 'svm' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.WP.AlternativeFunctions
		$content = file_get_contents( $_FILES['import_file']['tmp_name'] );

		if ( false === $content ) {
			return new WP_Error( 'svm_import_read', __( 'Die Datei konnte nicht gelesen werden.', 'svm' ) );
		}

		$parsed  = SVM_Importer::parse_csv( $content, self::post( 'delimiter', ';' ) );
		$mapping = array_map( 'sanitize_text_field', (array) self::post_array( 'mapping' ) );

		if ( empty( $mapping ) ) {
			set_transient( 'svm_import_' . get_current_user_id(), $parsed, HOUR_IN_SECONDS );

			return array(
				'svm_message' => __( 'Datei eingelesen — bitte die Spalten zuordnen.', 'svm' ),
				'step'        => 'map',
			);
		}

		$result = SVM_Importer::run(
			$parsed['rows'],
			$mapping,
			array(
				'dry_run'         => 'yes' === self::post( 'dry_run' ),
				'update_existing' => (bool) self::post( 'update_existing' ),
			)
		);

		$message = sprintf(
			/* translators: 1: neu, 2: aktualisiert, 3: übersprungen. */
			__( 'Import: %1$d neu, %2$d aktualisiert, %3$d übersprungen.', 'svm' ),
			$result['created'],
			$result['updated'],
			$result['skipped']
		);

		if ( ! empty( $result['errors'] ) ) {
			$message .= ' ' . implode( ' | ', array_slice( $result['errors'], 0, 5 ) );
		}

		return array( 'svm_message' => $message );
	}

	/**
	 * Exportiert die gesamte Konfiguration.
	 *
	 * @return array
	 */
	private static function handle_export_config() {
		$config = SVM_Config_Transfer::export();

		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="svm-konfiguration-' . gmdate( 'Y-m-d' ) . '.json"' );

		echo wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Importiert eine Konfiguration.
	 *
	 * @return array|WP_Error
	 */
	private static function handle_import_config() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['config_file']['tmp_name'] ) ) {
			return new WP_Error( 'svm_config_file', __( 'Bitte eine Konfigurationsdatei auswählen.', 'svm' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.WP.AlternativeFunctions
		$content = file_get_contents( $_FILES['config_file']['tmp_name'] );
		$config  = json_decode( (string) $content, true );

		if ( ! is_array( $config ) ) {
			return new WP_Error( 'svm_config_invalid', __( 'Die Datei enthält keine gültige Konfiguration.', 'svm' ) );
		}

		$result = SVM_Config_Transfer::import( $config );

		return array(
			'svm_message' => sprintf(
				/* translators: %d: Anzahl importierter Einträge. */
				__( 'Konfiguration importiert: %d Einträge.', 'svm' ),
				$result
			),
		);
	}
}
