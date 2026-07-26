<?php
/**
 * Export und Import der gesamten Konfiguration.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ermöglicht Umzug, Sicherung und Weitergabe einer Konfiguration als Startvorlage.
 */
class SVM_Config_Transfer {

	/**
	 * Tabellen, die zur Konfiguration gehören (Bewegungsdaten bleiben außen vor).
	 *
	 * @return string[]
	 */
	private static function tables() {
		return array(
			'unit_types',
			'units',
			'statuses',
			'status_transitions',
			'field_defs',
			'field_options',
			'field_visibility',
			'roles',
			'role_permissions',
			'fee_types',
			'rules',
			'rule_conditions',
			'payment_methods',
			'file_profiles',
			'dunning_levels',
			'message_categories',
			'export_profiles',
			'number_ranges',
			'templates',
		);
	}

	/**
	 * Einstellungen, die mitexportiert werden.
	 *
	 * @return string[]
	 */
	private static function options() {
		return array(
			'svm_club_name',
			'svm_fiscal_year_start',
			'svm_retention_months',
			'svm_self_service_units',
		);
	}

	/**
	 * Baut die Konfigurationsdatei.
	 *
	 * @return array
	 */
	public static function export() {
		$config = array(
			'plugin'    => 'schwimmverein-mitgliederverwaltung',
			'version'   => SVM_VERSION,
			'exported'  => gmdate( 'c' ),
			'tables'    => array(),
			'options'   => array(),
		);

		foreach ( self::tables() as $table ) {
			$config['tables'][ $table ] = SVM_DB::all( $table );
		}

		foreach ( self::options() as $option ) {
			$config['options'][ $option ] = get_option( $option );
		}

		// Bankdaten des Vereins werden bewusst nicht mitexportiert.
		foreach ( $config['tables']['file_profiles'] as $index => $profile ) {
			$config['tables']['file_profiles'][ $index ]['creditor_iban'] = '';
			$config['tables']['file_profiles'][ $index ]['creditor_id']   = '';
		}

		SVM_Audit::log( 'config', 0, 'exported' );

		return $config;
	}

	/**
	 * Spielt eine Konfiguration ein.
	 *
	 * Bestehende Konfigurationstabellen werden ersetzt, Bewegungsdaten bleiben unberührt.
	 *
	 * @param array $config Konfiguration.
	 * @return int Anzahl importierter Zeilen.
	 */
	public static function import( array $config ) {
		global $wpdb;

		if ( empty( $config['tables'] ) || ! is_array( $config['tables'] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( self::tables() as $table ) {
			if ( ! isset( $config['tables'][ $table ] ) || ! is_array( $config['tables'][ $table ] ) ) {
				continue;
			}

			$wpdb->query( 'DELETE FROM ' . SVM_DB::table( $table ) ); // phpcs:ignore WordPress.DB

			foreach ( $config['tables'][ $table ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$wpdb->insert( SVM_DB::table( $table ), $row ); // phpcs:ignore WordPress.DB
				$count++;
			}
		}

		if ( ! empty( $config['options'] ) && is_array( $config['options'] ) ) {
			foreach ( $config['options'] as $option => $value ) {
				if ( in_array( $option, self::options(), true ) ) {
					update_option( $option, $value );
				}
			}
		}

		SVM_Fields::flush_cache();
		SVM_Permissions::flush_cache();

		SVM_Audit::log( 'config', 0, 'imported', 'rows', '', $count );

		return $count;
	}
}
