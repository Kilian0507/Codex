<?php
/**
 * Startkonfiguration.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ein leeres System wäre unbrauchbar — deshalb wird eine vollständige,
 * fachlich sinnvolle Konfiguration angelegt, die anschließend frei änderbar ist.
 */
class SVM_Default_Config {

	/**
	 * IDs der angelegten Rollen.
	 *
	 * @var array
	 */
	private static $roles = array();

	/**
	 * IDs der angelegten Felder.
	 *
	 * @var array
	 */
	private static $fields = array();

	/**
	 * IDs der angelegten Einheiten.
	 *
	 * @var array
	 */
	private static $units = array();

	/**
	 * Legt die Startkonfiguration an.
	 *
	 * @return void
	 */
	public static function seed() {
		self::seed_number_ranges();
		self::seed_statuses();
		self::seed_units();
		self::seed_roles();
		self::seed_fields();
		self::seed_field_visibility();
		self::seed_payment_methods();
		self::seed_fee_types();
		self::seed_messages();
		self::seed_file_profile();
		self::seed_templates();
		self::seed_export_profiles();

		if ( ! get_option( 'svm_club_name' ) ) {
			update_option( 'svm_club_name', get_bloginfo( 'name' ) );
		}

		update_option( 'svm_retention_months', 120 );
		update_option( 'svm_fiscal_year_start', '01-01' );

		SVM_Fields::flush_cache();
	}

	/**
	 * Nummernkreise.
	 *
	 * @return void
	 */
	private static function seed_number_ranges() {
		SVM_Number_Range::save(
			array(
				'range_key'  => 'member',
				'label'      => __( 'Mitgliedsnummern', 'svm' ),
				'prefix'     => 'SV',
				'use_year'   => 1,
				'digits'     => 5,
				'next_value' => 1,
			)
		);

		SVM_Number_Range::save(
			array(
				'range_key'  => 'mandate',
				'label'      => __( 'Mandatsreferenzen', 'svm' ),
				'prefix'     => 'MND',
				'use_year'   => 0,
				'digits'     => 6,
				'next_value' => 1,
			)
		);
	}

	/**
	 * Mitgliedsstatus.
	 *
	 * @return void
	 */
	private static function seed_statuses() {
		$statuses = array(
			array( 'aktiv', __( 'aktiv', 'svm' ), 1, 1, 1, 1, 10 ),
			array( 'passiv', __( 'passiv (fördernd)', 'svm' ), 1, 1, 1, 0, 20 ),
			array( 'ruhend', __( 'ruhend', 'svm' ), 0, 0, 1, 0, 30 ),
			array( 'ehrenmitglied', __( 'Ehrenmitglied', 'svm' ), 1, 0, 1, 0, 40 ),
			array( 'ausgetreten', __( 'ausgetreten', 'svm' ), 0, 0, 0, 0, 50 ),
		);

		foreach ( $statuses as $status ) {
			SVM_Statuses::save(
				array(
					'status_key'       => $status[0],
					'label'            => $status[1],
					'counts_as_active' => $status[2],
					'is_billable'      => $status[3],
					'allows_login'     => $status[4],
					'is_default'       => $status[5],
					'sort_order'       => $status[6],
				)
			);
		}

		// Beim Austritt offene Forderungen stornieren und Mandate deaktivieren.
		$active = SVM_Statuses::get_by_key( 'aktiv' );
		$left   = SVM_Statuses::get_by_key( 'ausgetreten' );

		if ( $active && $left ) {
			SVM_Statuses::save_transition(
				array(
					'from_status_id'  => (int) $active['id'],
					'to_status_id'    => (int) $left['id'],
					'requires_reason' => 1,
					'requires_date'   => 1,
					'on_transition'   => 'revoke_mandates',
				)
			);
		}
	}

	/**
	 * Einheitstypen und Sparten.
	 *
	 * @return void
	 */
	private static function seed_units() {
		$sparte = SVM_Units::save_type( array( 'type_key' => 'sparte', 'label' => __( 'Sparte', 'svm' ), 'sort_order' => 10 ) );
		SVM_Units::save_type( array( 'type_key' => 'gruppe', 'label' => __( 'Trainingsgruppe', 'svm' ), 'sort_order' => 20 ) );
		SVM_Units::save_type( array( 'type_key' => 'mannschaft', 'label' => __( 'Mannschaft', 'svm' ), 'sort_order' => 30 ) );

		$names = array(
			'schwimmen'    => __( 'Schwimmen', 'svm' ),
			'wasserball'   => __( 'Wasserball', 'svm' ),
			'breitensport' => __( 'Breitensport', 'svm' ),
		);

		$sort = 10;

		foreach ( $names as $key => $name ) {
			self::$units[ $key ] = SVM_Units::save(
				array(
					'name'         => $name,
					'short_name'   => strtoupper( substr( $name, 0, 2 ) ),
					'unit_type_id' => $sparte,
					'sort_order'   => $sort,
				)
			);

			$sort += 10;
		}
	}

	/**
	 * Rollenvorlagen.
	 *
	 * @return void
	 */
	private static function seed_roles() {
		$all = SVM_Permissions::all_keys();

		$roles = array(
			'verwaltung'    => array(
				'label'       => __( 'Vereinsverwaltung', 'svm' ),
				'description' => __( 'Vollzugriff auf Mitglieder, Beiträge, Zahlungen und Konfiguration.', 'svm' ),
				'scope'       => 'all',
				'permissions' => $all,
			),
			'kassenwart'    => array(
				'label'       => __( 'Kassenwart', 'svm' ),
				'description' => __( 'Finanzen: Beiträge, Zahlungen, SEPA, Mahnwesen — ohne Rechteverwaltung.', 'svm' ),
				'scope'       => 'all',
				'permissions' => array(
					'members_view', 'bank_data_view', 'units_view',
					'fees_view', 'fees_manage', 'fee_run', 'invoices_view', 'invoices_edit',
					'payments_view', 'payments_record', 'mandates_manage', 'payment_run', 'dunning_manage',
					'messages_view', 'export_run', 'import_run', 'portal_access', 'own_profile_edit', 'own_finance_view',
				),
			),
			'spartenleiter' => array(
				'label'       => __( 'Sparten-Leiter', 'svm' ),
				'description' => __( 'Sieht und pflegt nur Mitglieder der eigenen Sparte — ohne Bankdaten.', 'svm' ),
				'scope'       => 'units',
				'permissions' => array(
					'members_view', 'members_edit', 'members_create', 'units_view',
					'invoices_view', 'messages_view', 'messages_manage', 'export_run',
					'portal_access', 'own_profile_edit', 'own_finance_view',
				),
			),
			'uebungsleiter' => array(
				'label'       => __( 'Übungsleiter', 'svm' ),
				'description' => __( 'Kontaktdaten der eigenen Gruppe — keine Finanzdaten, keine Bankdaten.', 'svm' ),
				'scope'       => 'group',
				'permissions' => array(
					'members_view', 'units_view', 'messages_view',
					'portal_access', 'own_profile_edit', 'own_finance_view',
				),
			),
			'vorstand'      => array(
				'label'       => __( 'Vorstand', 'svm' ),
				'description' => __( 'Lesender Zugriff auf Berichte und Auswertungen.', 'svm' ),
				'scope'       => 'all',
				'permissions' => array(
					'members_view', 'units_view', 'fees_view', 'invoices_view', 'payments_view',
					'messages_view', 'export_run', 'audit_view',
					'portal_access', 'own_profile_edit', 'own_finance_view',
				),
			),
			'mitglied'      => array(
				'label'          => __( 'Mitglied', 'svm' ),
				'description'    => __( 'Sieht nur die eigenen Daten, liest Nachrichten und pflegt die eigenen Stammdaten.', 'svm' ),
				'scope'          => 'self',
				'is_member_role' => 1,
				'permissions'    => array( 'portal_access', 'own_profile_edit', 'own_finance_view', 'messages_view' ),
			),
		);

		$sort = 10;

		foreach ( $roles as $key => $role ) {
			self::$roles[ $key ] = SVM_Roles::save(
				array(
					'role_key'       => $key,
					'label'          => $role['label'],
					'description'    => $role['description'],
					'scope'          => $role['scope'],
					'is_member_role' => isset( $role['is_member_role'] ) ? $role['is_member_role'] : 0,
					'sort_order'     => $sort,
				),
				$role['permissions']
			);

			$sort += 10;
		}
	}

	/**
	 * Standardfelder.
	 *
	 * Auch Name und Adresse sind normale Felder und jederzeit änderbar.
	 *
	 * @return void
	 */
	private static function seed_fields() {
		$section_person = __( 'Person', 'svm' );
		$section_kontakt = __( 'Kontakt', 'svm' );
		$section_bank   = __( 'Bankverbindung', 'svm' );
		$section_extra  = __( 'Weiteres', 'svm' );

		$fields = array(
			array(
				'key'   => 'vorname',
				'label' => __( 'Vorname', 'svm' ),
				'type'  => 'text',
				'role'  => 'first_name',
				'section' => $section_person,
				'required' => 1,
				'list'  => 1,
			),
			array(
				'key'   => 'nachname',
				'label' => __( 'Nachname', 'svm' ),
				'type'  => 'text',
				'role'  => 'last_name',
				'section' => $section_person,
				'required' => 1,
				'list'  => 1,
			),
			array(
				'key'   => 'geburtsdatum',
				'label' => __( 'Geburtsdatum', 'svm' ),
				'type'  => 'date',
				'role'  => 'birthdate',
				'section' => $section_person,
				'help'  => __( 'Grundlage für Altersregeln in der Beitragsberechnung.', 'svm' ),
			),
			array(
				'key'     => 'geschlecht',
				'label'   => __( 'Geschlecht', 'svm' ),
				'type'    => 'select',
				'section' => $section_person,
				'options' => array( 'w|' . __( 'weiblich', 'svm' ), 'm|' . __( 'männlich', 'svm' ), 'd|' . __( 'divers', 'svm' ), 'k|' . __( 'keine Angabe', 'svm' ) ),
			),
			array(
				'key'     => 'strasse',
				'label'   => __( 'Straße und Hausnummer', 'svm' ),
				'type'    => 'text',
				'role'    => 'street',
				'section' => $section_kontakt,
			),
			array(
				'key'     => 'plz',
				'label'   => __( 'Postleitzahl', 'svm' ),
				'type'    => 'text',
				'role'    => 'postal_code',
				'section' => $section_kontakt,
			),
			array(
				'key'     => 'ort',
				'label'   => __( 'Ort', 'svm' ),
				'type'    => 'text',
				'role'    => 'city',
				'section' => $section_kontakt,
			),
			array(
				'key'     => 'email',
				'label'   => __( 'E-Mail', 'svm' ),
				'type'    => 'email',
				'role'    => 'email',
				'section' => $section_kontakt,
				'list'    => 1,
			),
			array(
				'key'     => 'telefon',
				'label'   => __( 'Telefon', 'svm' ),
				'type'    => 'phone',
				'section' => $section_kontakt,
			),
			array(
				'key'     => 'kontakt_notfall',
				'label'   => __( 'Notfallkontakt', 'svm' ),
				'type'    => 'text',
				'section' => $section_kontakt,
				'help'    => __( 'Name und Telefonnummer, besonders bei Minderjährigen.', 'svm' ),
			),
			array(
				'key'       => 'iban',
				'label'     => __( 'IBAN', 'svm' ),
				'type'      => 'iban',
				'role'      => 'iban',
				'section'   => $section_bank,
				'sensitive' => 1,
			),
			array(
				'key'       => 'bic',
				'label'     => __( 'BIC', 'svm' ),
				'type'      => 'bic',
				'role'      => 'bic',
				'section'   => $section_bank,
				'sensitive' => 1,
			),
			array(
				'key'       => 'kontoinhaber',
				'label'     => __( 'Kontoinhaber (falls abweichend)', 'svm' ),
				'type'      => 'text',
				'role'      => 'account_holder',
				'section'   => $section_bank,
				'sensitive' => 1,
			),
			array(
				'key'     => 'foto_einwilligung',
				'label'   => __( 'Einwilligung Foto/Video', 'svm' ),
				'type'    => 'boolean',
				'section' => $section_extra,
				'help'    => __( 'Widerruf jederzeit möglich; im Portal selbst änderbar.', 'svm' ),
			),
			array(
				'key'       => 'ermaessigung',
				'label'     => __( 'Ermäßigungsnachweis liegt vor', 'svm' ),
				'type'      => 'boolean',
				'section'   => $section_extra,
				'help'      => __( 'Kann als Bedingung in Beitragsregeln verwendet werden.', 'svm' ),
			),
			array(
				'key'     => 'notizen',
				'label'   => __( 'Interne Notizen', 'svm' ),
				'type'    => 'textarea',
				'section' => $section_extra,
				'gdpr'    => 1,
			),
		);

		$sort = 10;

		foreach ( $fields as $field ) {
			$id = SVM_Fields::save_def(
				array(
					'entity_type'    => 'member',
					'field_key'      => $field['key'],
					'label'          => $field['label'],
					'field_type'     => $field['type'],
					'section'        => isset( $field['section'] ) ? $field['section'] : '',
					'help_text'      => isset( $field['help'] ) ? $field['help'] : '',
					'system_role'    => isset( $field['role'] ) ? $field['role'] : '',
					'is_required'    => isset( $field['required'] ) ? $field['required'] : 0,
					'is_sensitive'   => isset( $field['sensitive'] ) ? $field['sensitive'] : 0,
					'in_gdpr_export' => 1,
					'show_in_list'   => isset( $field['list'] ) ? $field['list'] : 0,
					'sort_order'     => $sort,
				)
			);

			self::$fields[ $field['key'] ] = $id;

			if ( ! empty( $field['options'] ) ) {
				SVM_Fields::set_options( $id, $field['options'] );
			}

			$sort += 10;
		}
	}

	/**
	 * Feldrechte je Rolle.
	 *
	 * @return void
	 */
	private static function seed_field_visibility() {
		$bank_fields    = array( 'iban', 'bic', 'kontoinhaber' );
		$contact_fields = array( 'strasse', 'plz', 'ort', 'email', 'telefon', 'kontakt_notfall' );
		$person_fields  = array( 'vorname', 'nachname', 'geburtsdatum', 'geschlecht' );

		foreach ( self::$fields as $key => $field_id ) {
			$is_bank     = in_array( $key, $bank_fields, true );
			$is_contact  = in_array( $key, $contact_fields, true );
			$is_person   = in_array( $key, $person_fields, true );
			$is_internal = 'notizen' === $key;

			// Vereinsverwaltung sieht und ändert alles.
			SVM_Fields::set_visibility(
				$field_id,
				self::$roles['verwaltung'],
				array( 'can_view' => 1, 'can_edit' => 1 )
			);

			// Kassenwart: alles sehen, Bankdaten ändern.
			SVM_Fields::set_visibility(
				$field_id,
				self::$roles['kassenwart'],
				array( 'can_view' => 1, 'can_edit' => $is_bank ? 1 : 0 )
			);

			// Sparten-Leiter: keine Bankdaten, sonst pflegen.
			SVM_Fields::set_visibility(
				$field_id,
				self::$roles['spartenleiter'],
				array(
					'can_view' => $is_bank ? 0 : 1,
					'can_edit' => ( $is_contact || $is_person ) ? 1 : 0,
				)
			);

			// Übungsleiter: nur Kontakt- und Personendaten lesen.
			SVM_Fields::set_visibility(
				$field_id,
				self::$roles['uebungsleiter'],
				array(
					'can_view' => ( $is_bank || $is_internal ) ? 0 : 1,
					'can_edit' => 0,
				)
			);

			// Vorstand: lesend, ohne Bankdaten.
			SVM_Fields::set_visibility(
				$field_id,
				self::$roles['vorstand'],
				array( 'can_view' => $is_bank ? 0 : 1, 'can_edit' => 0 )
			);

			// Mitglied: Kontaktdaten direkt ändern, Name/Geburtsdatum/IBAN nur mit Freigabe.
			$member_rules = array(
				'can_view'          => $is_internal ? 0 : 1,
				'can_edit'          => 0,
				'requires_approval' => 0,
			);

			if ( $is_contact || in_array( $key, array( 'foto_einwilligung' ), true ) ) {
				$member_rules['can_edit'] = 1;
			}

			if ( $is_person || $is_bank ) {
				$member_rules['can_edit']          = 1;
				$member_rules['requires_approval'] = 1;
			}

			SVM_Fields::set_visibility( $field_id, self::$roles['mitglied'], $member_rules );
		}
	}

	/**
	 * Zahlarten.
	 *
	 * @return void
	 */
	private static function seed_payment_methods() {
		SVM_Payment_Methods::save(
			array(
				'method_key'            => 'sepa_lastschrift',
				'label'                 => __( 'SEPA-Lastschrift', 'svm' ),
				'behavior'              => 'file',
				'requires_mandate'      => 1,
				'requires_bank_account' => 1,
				'is_default'            => 1,
				'allow_self_select'     => 1,
				'sort_order'            => 10,
			)
		);

		SVM_Payment_Methods::save(
			array(
				'method_key'        => 'ueberweisung',
				'label'             => __( 'Überweisung', 'svm' ),
				'behavior'          => 'manual',
				'allow_self_select' => 1,
				'sort_order'        => 20,
			)
		);

		SVM_Payment_Methods::save(
			array(
				'method_key' => 'barzahlung',
				'label'      => __( 'Barzahlung', 'svm' ),
				'behavior'   => 'manual',
				'sort_order' => 30,
			)
		);
	}

	/**
	 * Beitragsarten mit Beispielregeln.
	 *
	 * @return void
	 */
	private static function seed_fee_types() {
		$birthdate = isset( self::$fields['geburtsdatum'] ) ? self::$fields['geburtsdatum'] : 0;

		// Grundbeitrag Erwachsene.
		$adult = SVM_Fee_Types::save(
			array(
				'label'     => __( 'Grundbeitrag Erwachsene', 'svm' ),
				'amount'    => 120,
				'cycle'     => 'yearly',
				'due_rule'  => '01.03.',
				'proration' => 'monthly',
				'priority'  => 10,
			)
		);

		SVM_Rules::save(
			array(
				'rule_type'  => 'fee_condition',
				'owner_type' => 'fee_type',
				'owner_id'   => $adult,
				'label'      => __( 'Grundbeitrag Erwachsene', 'svm' ),
				'logic'      => 'AND',
			),
			array(
				array(
					'subject'        => 'age',
					'operator'       => 'gte',
					'compare_value'  => '18',
					'reference_date' => 'year_start',
				),
			)
		);

		// Grundbeitrag Jugend.
		$youth = SVM_Fee_Types::save(
			array(
				'label'     => __( 'Grundbeitrag Jugend', 'svm' ),
				'amount'    => 72,
				'cycle'     => 'yearly',
				'due_rule'  => '01.03.',
				'proration' => 'monthly',
				'priority'  => 20,
			)
		);

		SVM_Rules::save(
			array(
				'rule_type'  => 'fee_condition',
				'owner_type' => 'fee_type',
				'owner_id'   => $youth,
				'label'      => __( 'Grundbeitrag Jugend', 'svm' ),
				'logic'      => 'AND',
			),
			array(
				array(
					'subject'        => 'age',
					'operator'       => 'lt',
					'compare_value'  => '18',
					'reference_date' => 'year_start',
				),
			)
		);

		// Spartenbeitrag Wasserball.
		if ( isset( self::$units['wasserball'] ) ) {
			$waterpolo = SVM_Fee_Types::save(
				array(
					'label'     => __( 'Spartenbeitrag Wasserball', 'svm' ),
					'amount'    => 60,
					'cycle'     => 'yearly',
					'due_rule'  => '01.03.',
					'proration' => 'monthly',
					'priority'  => 30,
				)
			);

			SVM_Rules::save(
				array(
					'rule_type'  => 'fee_condition',
					'owner_type' => 'fee_type',
					'owner_id'   => $waterpolo,
					'label'      => __( 'Mitglieder der Sparte Wasserball', 'svm' ),
					'logic'      => 'AND',
				),
				array(
					array(
						'subject'       => 'unit',
						'operator'      => 'in',
						'compare_value' => (string) self::$units['wasserball'],
					),
				)
			);
		}

		// Aufnahmegebühr.
		SVM_Fee_Types::save(
			array(
				'label'    => __( 'Aufnahmegebühr', 'svm' ),
				'amount'   => 25,
				'cycle'    => 'once',
				'due_rule' => '+30d',
				'priority' => 5,
			)
		);

		// Familienrabatt ab dem dritten Kind.
		SVM_Rules::save(
			array(
				'rule_type'        => 'fee_adjustment',
				'owner_type'       => 'fee_type',
				'owner_id'         => 0,
				'label'            => __( 'Beitragsfrei ab dem 3. Kind', 'svm' ),
				'logic'            => 'AND',
				'adjustment_type'  => 'discount_pct',
				'adjustment_value' => 100,
				'applies_to'       => 'member',
				'priority'         => 10,
				'is_active'        => 1,
			),
			array(
				array(
					'subject'       => 'child_position',
					'operator'      => 'gte',
					'compare_value' => '3',
				),
			)
		);

		// Höchstbetrag je Familie — als Beispiel angelegt, aber zunächst inaktiv.
		SVM_Rules::save(
			array(
				'rule_type'        => 'fee_adjustment',
				'owner_type'       => 'fee_type',
				'owner_id'         => 0,
				'label'            => __( 'Familien zahlen höchstens 300 € im Jahr', 'svm' ),
				'logic'            => 'AND',
				'adjustment_type'  => 'cap_family',
				'adjustment_value' => 300,
				'applies_to'       => 'family',
				'priority'         => 30,
				'is_active'        => 0,
			),
			array(
				array(
					'subject'       => 'family_size',
					'operator'      => 'gte',
					'compare_value' => '2',
				),
			)
		);

		// Beispiel für einen Mehrspartenrabatt.
		SVM_Rules::save(
			array(
				'rule_type'        => 'fee_adjustment',
				'owner_type'       => 'fee_type',
				'owner_id'         => 0,
				'label'            => __( 'Mehrsparten-Rabatt 10 %', 'svm' ),
				'logic'            => 'AND',
				'adjustment_type'  => 'discount_pct',
				'adjustment_value' => 10,
				'applies_to'       => 'member',
				'priority'         => 20,
				'is_active'        => 0,
			),
			array(
				array(
					'subject'       => 'unit_count',
					'operator'      => 'gte',
					'compare_value' => '2',
				),
			)
		);

		unset( $birthdate );
	}

	/**
	 * Nachrichtenkategorien.
	 *
	 * @return void
	 */
	private static function seed_messages() {
		$categories = array(
			array( __( 'Vereinsnachrichten', 'svm' ), 0, 10 ),
			array( __( 'Trainingsausfall', 'svm' ), 1, 20 ),
			array( __( 'Wettkämpfe', 'svm' ), 0, 30 ),
			array( __( 'Beiträge und Zahlungen', 'svm' ), 0, 40 ),
		);

		foreach ( $categories as $category ) {
			SVM_Messages::save_category(
				array(
					'label'        => $category[0],
					'is_important' => $category[1],
					'sort_order'   => $category[2],
				)
			);
		}
	}

	/**
	 * Dateiprofil für den Beitragseinzug.
	 *
	 * @return void
	 */
	private static function seed_file_profile() {
		SVM_File_Profiles::save(
			array(
				'label'            => __( 'SEPA-Lastschrift Hauptkonto', 'svm' ),
				'format'           => 'pain.008.001.02',
				'creditor_name'    => get_bloginfo( 'name' ),
				'purpose_template' => __( 'Beitrag {zeitraum} {mitgliedsnummer}', 'svm' ),
				'lead_days_frst'   => 5,
				'lead_days_rcur'   => 2,
				'batch_booking'    => 1,
			)
		);
	}

	/**
	 * Vorlagen und eine erste Mahnstufe.
	 *
	 * @return void
	 */
	private static function seed_templates() {
		$template_id = SVM_Templates::save(
			array(
				'template_key' => 'zahlungserinnerung',
				'label'        => __( 'Zahlungserinnerung', 'svm' ),
				'type'         => 'email',
				'subject'      => __( 'Zahlungserinnerung: {beschreibung}', 'svm' ),
				'body'         => __(
					"Hallo {name},\n\nfür {beschreibung} ist noch ein Betrag von {betrag} offen (fällig war der {faelligkeit}).\n\nBitte gleichen Sie den Betrag in den nächsten Tagen aus. Sollte sich die Zahlung überschnitten haben, betrachten Sie diese Nachricht bitte als gegenstandslos.\n\nViele Grüße\n{verein}",
					'svm'
				),
			)
		);

		SVM_Dunning::save_level(
			array(
				'level_no'       => 1,
				'label'          => __( '1. Zahlungserinnerung', 'svm' ),
				'days_after_due' => 14,
				'fee'            => 0,
				'template_id'    => $template_id,
				'is_automatic'   => 0,
				'is_active'      => 1,
			)
		);
	}

	/**
	 * Beispiel-Exportprofile.
	 *
	 * @return void
	 */
	private static function seed_export_profiles() {
		SVM_Export_Profiles::save(
			array(
				'label'        => __( 'Mitgliederliste', 'svm' ),
				'entity_type'  => 'member',
				'format'       => 'xlsx',
				'columns_json' => array(
					'core:member_number',
					'field:' . self::$fields['vorname'],
					'field:' . self::$fields['nachname'],
					'field:' . self::$fields['geburtsdatum'],
					'field:' . self::$fields['email'],
					'field:' . self::$fields['strasse'],
					'field:' . self::$fields['plz'],
					'field:' . self::$fields['ort'],
					'core:status',
					'calc:units',
					'calc:age',
				),
			)
		);

		SVM_Export_Profiles::save(
			array(
				'label'        => __( 'Beitragsübersicht', 'svm' ),
				'entity_type'  => 'invoice',
				'format'       => 'xlsx',
				'columns_json' => array(
					'calc:member_number',
					'calc:member_name',
					'core:description',
					'core:period_start',
					'core:period_end',
					'core:due_date',
					'core:amount',
					'core:amount_paid',
					'calc:open',
					'core:status',
				),
			)
		);

		SVM_Export_Profiles::save(
			array(
				'label'        => __( 'Zahlungsjournal', 'svm' ),
				'entity_type'  => 'payment',
				'format'       => 'xlsx',
				'columns_json' => array(
					'core:paid_at',
					'calc:member_number',
					'calc:member_name',
					'core:payer_name',
					'core:amount',
					'calc:method',
					'core:reference',
					'calc:allocated',
					'core:status',
				),
			)
		);

		SVM_Export_Profiles::save(
			array(
				'label'         => __( 'SEPA-Mandate (nur Kassenwart)', 'svm' ),
				'entity_type'   => 'mandate',
				'format'        => 'csv',
				'allowed_roles' => array( self::$roles['verwaltung'], self::$roles['kassenwart'] ),
				'columns_json'  => array(
					'calc:member_number',
					'calc:member_name',
					'core:mandate_ref',
					'core:iban',
					'core:signed_at',
					'core:sequence_type',
					'core:status',
				),
			)
		);
	}
}
