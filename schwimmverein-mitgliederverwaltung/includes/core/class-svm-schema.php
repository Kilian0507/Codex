<?php
/**
 * Tabellendefinitionen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liefert alle CREATE-TABLE-Anweisungen für dbDelta.
 *
 * Grundsatz: Kernspalten sind echte Spalten (schnell filterbar), alles fachlich
 * Variable liegt in Konfigurationstabellen bzw. in svm_field_values.
 */
class SVM_Schema {

	/**
	 * Alle Tabellendefinitionen.
	 *
	 * @return string[]
	 */
	public static function tables() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'svm_';

		$t = array();

		/* ---------- Konfiguration: Struktur ---------- */

		$t[] = "CREATE TABLE {$p}unit_types (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type_key varchar(64) NOT NULL,
			label varchar(190) NOT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY type_key (type_key)
		) $c;";

		$t[] = "CREATE TABLE {$p}units (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			unit_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL,
			short_name varchar(64) NOT NULL DEFAULT '',
			leader_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY parent_id (parent_id),
			KEY unit_type_id (unit_type_id)
		) $c;";

		/* ---------- Konfiguration: Status ---------- */

		$t[] = "CREATE TABLE {$p}statuses (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			status_key varchar(64) NOT NULL,
			label varchar(190) NOT NULL,
			color varchar(16) NOT NULL DEFAULT '',
			counts_as_active tinyint(1) NOT NULL DEFAULT 1,
			is_billable tinyint(1) NOT NULL DEFAULT 1,
			allows_login tinyint(1) NOT NULL DEFAULT 1,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY status_key (status_key)
		) $c;";

		$t[] = "CREATE TABLE {$p}status_transitions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			from_status_id bigint(20) unsigned NOT NULL,
			to_status_id bigint(20) unsigned NOT NULL,
			requires_reason tinyint(1) NOT NULL DEFAULT 0,
			requires_date tinyint(1) NOT NULL DEFAULT 0,
			on_transition varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY from_status_id (from_status_id)
		) $c;";

		/* ---------- Konfiguration: Felder ---------- */

		$t[] = "CREATE TABLE {$p}field_defs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_type varchar(32) NOT NULL DEFAULT 'member',
			field_key varchar(64) NOT NULL,
			label varchar(190) NOT NULL,
			field_type varchar(32) NOT NULL DEFAULT 'text',
			help_text text NULL,
			section varchar(190) NOT NULL DEFAULT '',
			is_required tinyint(1) NOT NULL DEFAULT 0,
			validation_regex varchar(255) NOT NULL DEFAULT '',
			min_value varchar(64) NOT NULL DEFAULT '',
			max_value varchar(64) NOT NULL DEFAULT '',
			default_value text NULL,
			formula text NULL,
			system_role varchar(32) NOT NULL DEFAULT '',
			unit_scope text NULL,
			is_sensitive tinyint(1) NOT NULL DEFAULT 0,
			in_gdpr_export tinyint(1) NOT NULL DEFAULT 1,
			show_in_list tinyint(1) NOT NULL DEFAULT 0,
			retention_months int(11) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY entity_field (entity_type,field_key),
			KEY system_role (system_role)
		) $c;";

		$t[] = "CREATE TABLE {$p}field_options (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			field_id bigint(20) unsigned NOT NULL,
			option_value varchar(190) NOT NULL,
			option_label varchar(190) NOT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY field_id (field_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}field_visibility (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			field_id bigint(20) unsigned NOT NULL,
			role_id bigint(20) unsigned NOT NULL,
			can_view tinyint(1) NOT NULL DEFAULT 1,
			can_edit tinyint(1) NOT NULL DEFAULT 0,
			requires_approval tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY field_role (field_id,role_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}field_values (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entity_type varchar(32) NOT NULL DEFAULT 'member',
			entity_id bigint(20) unsigned NOT NULL,
			field_id bigint(20) unsigned NOT NULL,
			value_text longtext NULL,
			value_number decimal(20,4) NULL,
			value_date datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entity_field (entity_type,entity_id,field_id),
			KEY field_number (field_id,value_number),
			KEY field_date (field_id,value_date)
		) $c;";

		/* ---------- Konfiguration: Rollen und Rechte ---------- */

		$t[] = "CREATE TABLE {$p}roles (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			role_key varchar(64) NOT NULL,
			label varchar(190) NOT NULL,
			description text NULL,
			scope varchar(32) NOT NULL DEFAULT 'self',
			include_subunits tinyint(1) NOT NULL DEFAULT 1,
			is_member_role tinyint(1) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY role_key (role_key)
		) $c;";

		$t[] = "CREATE TABLE {$p}role_permissions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			role_id bigint(20) unsigned NOT NULL,
			permission_key varchar(64) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY role_permission (role_id,permission_key)
		) $c;";

		$t[] = "CREATE TABLE {$p}user_roles (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			role_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_role (user_id,role_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}user_units (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			unit_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_unit (user_id,unit_id)
		) $c;";

		/* ---------- Mitglieder ---------- */

		$t[] = "CREATE TABLE {$p}family_groups (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			primary_member_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id)
		) $c;";

		$t[] = "CREATE TABLE {$p}members (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_number varchar(64) NOT NULL DEFAULT '',
			status_id bigint(20) unsigned NOT NULL DEFAULT 0,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			family_group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			family_position int(11) NOT NULL DEFAULT 0,
			payment_method_id bigint(20) unsigned NOT NULL DEFAULT 0,
			joined_at date NULL,
			left_at date NULL,
			status_changed_at datetime NULL,
			status_reason varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NULL,
			deleted_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY member_number (member_number),
			KEY status_id (status_id),
			KEY wp_user_id (wp_user_id),
			KEY family_group_id (family_group_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}member_units (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_id bigint(20) unsigned NOT NULL,
			unit_id bigint(20) unsigned NOT NULL,
			role_in_unit varchar(64) NOT NULL DEFAULT '',
			joined_at date NULL,
			left_at date NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY member_id (member_id),
			KEY unit_id (unit_id)
		) $c;";

		/* ---------- Beiträge ---------- */

		$t[] = "CREATE TABLE {$p}fee_types (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(190) NOT NULL,
			description text NULL,
			account_code varchar(64) NOT NULL DEFAULT '',
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			amount_mode varchar(16) NOT NULL DEFAULT 'fixed',
			amount_field_id bigint(20) unsigned NOT NULL DEFAULT 0,
			cycle varchar(16) NOT NULL DEFAULT 'yearly',
			due_rule varchar(64) NOT NULL DEFAULT '',
			proration varchar(16) NOT NULL DEFAULT 'none',
			valid_from date NULL,
			valid_to date NULL,
			priority int(11) NOT NULL DEFAULT 10,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY is_active (is_active)
		) $c;";

		$t[] = "CREATE TABLE {$p}rules (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_type varchar(32) NOT NULL DEFAULT 'fee_condition',
			owner_type varchar(32) NOT NULL DEFAULT '',
			owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label varchar(190) NOT NULL DEFAULT '',
			logic varchar(8) NOT NULL DEFAULT 'AND',
			adjustment_type varchar(32) NOT NULL DEFAULT 'none',
			adjustment_value decimal(12,2) NOT NULL DEFAULT 0.00,
			applies_to varchar(32) NOT NULL DEFAULT 'member',
			stackable tinyint(1) NOT NULL DEFAULT 1,
			priority int(11) NOT NULL DEFAULT 10,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY rule_owner (rule_type,owner_type,owner_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}rule_conditions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) unsigned NOT NULL,
			subject varchar(32) NOT NULL DEFAULT 'field',
			field_id bigint(20) unsigned NOT NULL DEFAULT 0,
			operator varchar(32) NOT NULL DEFAULT 'equals',
			compare_value text NULL,
			compare_value_2 varchar(190) NOT NULL DEFAULT '',
			reference_date varchar(32) NOT NULL DEFAULT 'year_start',
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY rule_id (rule_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}fee_runs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(190) NOT NULL DEFAULT '',
			period_start date NULL,
			period_end date NULL,
			due_date date NULL,
			status varchar(16) NOT NULL DEFAULT 'simulated',
			member_count int(11) NOT NULL DEFAULT 0,
			invoice_count int(11) NOT NULL DEFAULT 0,
			total_amount decimal(14,2) NOT NULL DEFAULT 0.00,
			summary longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id)
		) $c;";

		$t[] = "CREATE TABLE {$p}invoices (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_id bigint(20) unsigned NOT NULL,
			fee_type_id bigint(20) unsigned NOT NULL DEFAULT 0,
			run_id bigint(20) unsigned NOT NULL DEFAULT 0,
			description varchar(255) NOT NULL DEFAULT '',
			period_start date NULL,
			period_end date NULL,
			due_date date NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			amount_paid decimal(12,2) NOT NULL DEFAULT 0.00,
			status varchar(16) NOT NULL DEFAULT 'open',
			payment_method_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dunning_level int(11) NOT NULL DEFAULT 0,
			calculation_log longtext NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY member_id (member_id),
			KEY status (status),
			KEY run_id (run_id),
			KEY period (period_start,period_end)
		) $c;";

		/* ---------- Zahlungen ---------- */

		$t[] = "CREATE TABLE {$p}payment_methods (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(190) NOT NULL,
			method_key varchar(64) NOT NULL,
			behavior varchar(16) NOT NULL DEFAULT 'manual',
			requires_mandate tinyint(1) NOT NULL DEFAULT 0,
			requires_bank_account tinyint(1) NOT NULL DEFAULT 0,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			allow_self_select tinyint(1) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY method_key (method_key)
		) $c;";

		$t[] = "CREATE TABLE {$p}payments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payment_method_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			paid_at date NULL,
			payer_name varchar(190) NOT NULL DEFAULT '',
			reference varchar(255) NOT NULL DEFAULT '',
			note text NULL,
			status varchar(16) NOT NULL DEFAULT 'booked',
			run_id bigint(20) unsigned NOT NULL DEFAULT 0,
			recorded_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY member_id (member_id),
			KEY paid_at (paid_at),
			KEY status (status)
		) $c;";

		$t[] = "CREATE TABLE {$p}payment_allocations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			payment_id bigint(20) unsigned NOT NULL,
			invoice_id bigint(20) unsigned NOT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY payment_id (payment_id),
			KEY invoice_id (invoice_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}mandates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_id bigint(20) unsigned NOT NULL,
			mandate_ref varchar(64) NOT NULL,
			iban varchar(64) NOT NULL DEFAULT '',
			bic varchar(16) NOT NULL DEFAULT '',
			account_holder varchar(190) NOT NULL DEFAULT '',
			signed_at date NULL,
			sequence_type varchar(8) NOT NULL DEFAULT 'FRST',
			status varchar(16) NOT NULL DEFAULT 'active',
			last_used_at date NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY mandate_ref (mandate_ref),
			KEY member_id (member_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}file_profiles (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(190) NOT NULL,
			format varchar(32) NOT NULL DEFAULT 'pain.008.001.02',
			creditor_id varchar(64) NOT NULL DEFAULT '',
			creditor_name varchar(190) NOT NULL DEFAULT '',
			creditor_iban varchar(64) NOT NULL DEFAULT '',
			creditor_bic varchar(16) NOT NULL DEFAULT '',
			purpose_template varchar(255) NOT NULL DEFAULT '',
			batch_booking tinyint(1) NOT NULL DEFAULT 1,
			lead_days_frst int(11) NOT NULL DEFAULT 5,
			lead_days_rcur int(11) NOT NULL DEFAULT 2,
			csv_mapping longtext NULL,
			csv_delimiter varchar(4) NOT NULL DEFAULT ';',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id)
		) $c;";

		$t[] = "CREATE TABLE {$p}payment_runs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
			label varchar(190) NOT NULL DEFAULT '',
			direction varchar(16) NOT NULL DEFAULT 'debit',
			collection_date date NULL,
			total_amount decimal(14,2) NOT NULL DEFAULT 0.00,
			item_count int(11) NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'created',
			message_id varchar(64) NOT NULL DEFAULT '',
			file_name varchar(190) NOT NULL DEFAULT '',
			file_content longtext NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		$t[] = "CREATE TABLE {$p}payment_run_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
			member_id bigint(20) unsigned NOT NULL DEFAULT 0,
			mandate_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			end_to_end_id varchar(64) NOT NULL DEFAULT '',
			sequence_type varchar(8) NOT NULL DEFAULT 'RCUR',
			status varchar(16) NOT NULL DEFAULT 'included',
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY invoice_id (invoice_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}dunning_levels (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level_no int(11) NOT NULL DEFAULT 1,
			label varchar(190) NOT NULL,
			days_after_due int(11) NOT NULL DEFAULT 14,
			fee decimal(12,2) NOT NULL DEFAULT 0.00,
			template_id bigint(20) unsigned NOT NULL DEFAULT 0,
			is_automatic tinyint(1) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id)
		) $c;";

		/* ---------- Nachrichten ---------- */

		$t[] = "CREATE TABLE {$p}message_categories (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(190) NOT NULL,
			color varchar(16) NOT NULL DEFAULT '',
			is_important tinyint(1) NOT NULL DEFAULT 0,
			send_email tinyint(1) NOT NULL DEFAULT 0,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) $c;";

		$t[] = "CREATE TABLE {$p}messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			category_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(255) NOT NULL,
			body longtext NULL,
			visible_from datetime NULL,
			visible_to datetime NULL,
			is_important tinyint(1) NOT NULL DEFAULT 0,
			requires_ack tinyint(1) NOT NULL DEFAULT 0,
			send_email tinyint(1) NOT NULL DEFAULT 0,
			email_sent_at datetime NULL,
			status varchar(16) NOT NULL DEFAULT 'draft',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;";

		$t[] = "CREATE TABLE {$p}message_targets (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL,
			target_type varchar(16) NOT NULL DEFAULT 'all',
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY message_id (message_id)
		) $c;";

		$t[] = "CREATE TABLE {$p}message_reads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			message_id bigint(20) unsigned NOT NULL,
			member_id bigint(20) unsigned NOT NULL,
			read_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY message_member (message_id,member_id)
		) $c;";

		/* ---------- Self-Service, Export, Protokoll ---------- */

		$t[] = "CREATE TABLE {$p}change_requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_id bigint(20) unsigned NOT NULL,
			field_id bigint(20) unsigned NOT NULL,
			old_value text NULL,
			new_value text NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			requested_by bigint(20) unsigned NOT NULL DEFAULT 0,
			requested_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			decided_at datetime NULL,
			note text NULL,
			PRIMARY KEY  (id),
			KEY member_id (member_id),
			KEY status (status)
		) $c;";

		$t[] = "CREATE TABLE {$p}export_profiles (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(190) NOT NULL,
			entity_type varchar(32) NOT NULL DEFAULT 'member',
			columns_json longtext NULL,
			filter_rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
			format varchar(16) NOT NULL DEFAULT 'csv',
			delimiter varchar(4) NOT NULL DEFAULT ';',
			charset varchar(16) NOT NULL DEFAULT 'UTF-8',
			allowed_roles longtext NULL,
			sort_column varchar(64) NOT NULL DEFAULT '',
			sort_direction varchar(4) NOT NULL DEFAULT 'ASC',
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id)
		) $c;";

		$t[] = "CREATE TABLE {$p}number_ranges (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			range_key varchar(64) NOT NULL,
			label varchar(190) NOT NULL DEFAULT '',
			prefix varchar(32) NOT NULL DEFAULT '',
			use_year tinyint(1) NOT NULL DEFAULT 0,
			digits int(11) NOT NULL DEFAULT 5,
			next_value bigint(20) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY range_key (range_key)
		) $c;";

		$t[] = "CREATE TABLE {$p}templates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			template_key varchar(64) NOT NULL,
			label varchar(190) NOT NULL,
			type varchar(16) NOT NULL DEFAULT 'email',
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY template_key (template_key)
		) $c;";

		$t[] = "CREATE TABLE {$p}audit_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entity_type varchar(32) NOT NULL DEFAULT '',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(64) NOT NULL DEFAULT '',
			field_key varchar(64) NOT NULL DEFAULT '',
			old_value text NULL,
			new_value text NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY entity (entity_type,entity_id),
			KEY created_at (created_at)
		) $c;";

		return $t;
	}
}
