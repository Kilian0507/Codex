<?php
/**
 * Deinstallation.
 *
 * Entfernt alle Tabellen und Einstellungen des Plugins.
 *
 * @package SVM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$svm_tables = array(
	'audit_log',
	'templates',
	'number_ranges',
	'export_profiles',
	'change_requests',
	'message_reads',
	'message_targets',
	'messages',
	'message_categories',
	'dunning_levels',
	'payment_run_items',
	'payment_runs',
	'file_profiles',
	'mandates',
	'payment_allocations',
	'payments',
	'payment_methods',
	'invoices',
	'fee_runs',
	'rule_conditions',
	'rules',
	'fee_types',
	'member_units',
	'members',
	'family_groups',
	'user_units',
	'user_roles',
	'role_permissions',
	'roles',
	'field_values',
	'field_visibility',
	'field_options',
	'field_defs',
	'status_transitions',
	'statuses',
	'units',
	'unit_types',
);

foreach ( $svm_tables as $svm_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'svm_' . $svm_table ); // phpcs:ignore WordPress.DB
}

$svm_options = array(
	'svm_db_version',
	'svm_seeded',
	'svm_club_name',
	'svm_portal_page_id',
	'svm_fiscal_year_start',
	'svm_retention_months',
	'svm_self_service_units',
);

foreach ( $svm_options as $svm_option ) {
	delete_option( $svm_option );
}
