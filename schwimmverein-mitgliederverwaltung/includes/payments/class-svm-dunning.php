<?php
/**
 * Mahnwesen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mahnstufen sind frei definierbar — wer nicht mahnen will, legt keine Stufe an.
 */
class SVM_Dunning {

	/**
	 * Alle Mahnstufen.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function levels( $only_active = false ) {
		$where = $only_active ? 'is_active = 1' : '';
		return SVM_DB::all( 'dunning_levels', $where, 'level_no ASC' );
	}

	/**
	 * Eine Mahnstufe.
	 *
	 * @param int $id Stufen-ID.
	 * @return array|null
	 */
	public static function get_level( $id ) {
		return SVM_DB::get( 'dunning_levels', $id );
	}

	/**
	 * Speichert eine Mahnstufe.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save_level( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'level_no'       => 1,
				'label'          => '',
				'days_after_due' => 14,
				'fee'            => 0,
				'template_id'    => 0,
				'is_automatic'   => 0,
				'is_active'      => 1,
			)
		);

		if ( $id > 0 ) {
			SVM_DB::update( 'dunning_levels', $id, $data );
			return $id;
		}

		return SVM_DB::insert( 'dunning_levels', $data );
	}

	/**
	 * Löscht eine Mahnstufe.
	 *
	 * @param int $id Stufen-ID.
	 * @return void
	 */
	public static function delete_level( $id ) {
		SVM_DB::delete( 'dunning_levels', $id );
	}

	/**
	 * Forderungen, die für eine Mahnstufe fällig sind.
	 *
	 * @param array $level Mahnstufe.
	 * @return array
	 */
	public static function due_invoices( array $level ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . (int) $level['days_after_due'] . ' days' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT i.* FROM ' . SVM_DB::table( 'invoices' ) . ' i
				INNER JOIN ' . SVM_DB::table( 'members' ) . ' m ON m.id = i.member_id
				WHERE i.status IN (%s, %s)
				AND i.due_date IS NOT NULL AND i.due_date <= %s
				AND i.dunning_level < %d
				AND m.deleted_at IS NULL
				ORDER BY i.due_date ASC',
				'open',
				'partial',
				$cutoff,
				(int) $level['level_no']
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Setzt eine Mahnstufe für eine Forderung.
	 *
	 * @param int   $invoice_id Forderungs-ID.
	 * @param array $level      Mahnstufe.
	 * @return void
	 */
	public static function apply_level( $invoice_id, array $level ) {
		$invoice = SVM_Invoices::get( $invoice_id );

		if ( ! $invoice ) {
			return;
		}

		SVM_Invoices::update( $invoice_id, array( 'dunning_level' => (int) $level['level_no'] ) );

		$fee = round( (float) $level['fee'], 2 );

		if ( $fee > 0 ) {
			SVM_Invoices::create(
				array(
					'member_id'    => (int) $invoice['member_id'],
					'description'  => sprintf(
						/* translators: %s: Bezeichnung der Mahnstufe. */
						__( 'Mahngebühr (%s)', 'svm' ),
						$level['label']
					),
					'period_start' => $invoice['period_start'],
					'period_end'   => $invoice['period_end'],
					'due_date'     => gmdate( 'Y-m-d', strtotime( '+14 days' ) ),
					'amount'       => $fee,
				)
			);
		}

		self::notify( $invoice, $level );

		SVM_Audit::log( 'invoice', $invoice_id, 'dunning', 'level', (string) $invoice['dunning_level'], (string) $level['level_no'] );
	}

	/**
	 * Versendet die Mahnung, sofern eine Vorlage hinterlegt ist.
	 *
	 * @param array $invoice Forderung.
	 * @param array $level   Mahnstufe.
	 * @return void
	 */
	private static function notify( array $invoice, array $level ) {
		if ( (int) $level['template_id'] <= 0 ) {
			return;
		}

		$template = SVM_Templates::get( (int) $level['template_id'] );
		$email    = SVM_Members::email( (int) $invoice['member_id'] );

		if ( ! $template || '' === $email ) {
			return;
		}

		$rendered = SVM_Templates::render(
			$template,
			array(
				'{name}'            => SVM_Members::display_name( (int) $invoice['member_id'] ),
				'{betrag}'          => number_format_i18n( SVM_Invoices::remaining( $invoice ), 2 ) . ' €',
				'{beschreibung}'    => $invoice['description'],
				'{faelligkeit}'     => $invoice['due_date'] ? date_i18n( 'd.m.Y', strtotime( $invoice['due_date'] ) ) : '',
				'{mahnstufe}'       => $level['label'],
				'{mahngebuehr}'     => number_format_i18n( (float) $level['fee'], 2 ) . ' €',
				'{verein}'          => get_option( 'svm_club_name', get_bloginfo( 'name' ) ),
			)
		);

		wp_mail(
			$email,
			$rendered['subject'],
			$rendered['body'],
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * Führt alle automatischen Mahnstufen aus.
	 *
	 * @return int Anzahl bearbeiteter Forderungen.
	 */
	public static function run_automatic() {
		$count = 0;

		foreach ( self::levels( true ) as $level ) {
			if ( empty( $level['is_automatic'] ) ) {
				continue;
			}

			foreach ( self::due_invoices( $level ) as $invoice ) {
				self::apply_level( (int) $invoice['id'], $level );
				$count++;
			}
		}

		return $count;
	}
}
