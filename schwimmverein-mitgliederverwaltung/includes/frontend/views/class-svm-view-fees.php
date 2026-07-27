<?php
/**
 * Beitragsansichten.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Beitragsarten, Rabatte, Beitragslauf und offene Posten.
 */
class SVM_View_Fees {

	/**
	 * Reiter dieses Bereichs.
	 *
	 * @return array
	 */
	private static function tabs() {
		return array(
			'fees'      => __( 'Beitragsarten', 'svm' ),
			'discounts' => __( 'Rabatte', 'svm' ),
			'feerun'    => __( 'Beiträge berechnen', 'svm' ),
			'invoices'  => __( 'Offene Posten', 'svm' ),
		);
	}

	/**
	 * Übersicht der Beitragsarten.
	 *
	 * @return void
	 */
	public static function listing() {
		$actions = array();

		if ( SVM_Permissions::current_user_can( 'fees_manage' ) ) {
			$actions[] = array(
				'label'   => __( 'Neue Beitragsart', 'svm' ),
				'url'     => SVM_App::url( 'fee', array( 'id' => 0 ) ),
				'primary' => true,
			);
		}

		SVM_UI::page_head(
			__( 'Beiträge', 'svm' ),
			__( 'Welche Beiträge es gibt, wer sie zahlt und wann sie fällig werden.', 'svm' ),
			$actions
		);

		SVM_App::subnav( self::tabs(), 'fees' );

		$cycles = SVM_Fee_Types::cycles();
		$rows   = array();

		foreach ( SVM_Fee_Types::all() as $fee_type ) {
			$rule = SVM_Fee_Types::condition_rule( (int) $fee_type['id'] );

			$amount = 'formula' === $fee_type['amount_mode']
				? '<code>' . esc_html( $fee_type['amount'] ) . '</code>'
				: esc_html( SVM_UI::money( $fee_type['amount'] ) );

			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'fee', array( 'id' => (int) $fee_type['id'] ) ) ) . '">' .
					esc_html( $fee_type['label'] ) . '</a>' .
					( $fee_type['is_active'] ? '' : ' ' . SVM_UI::badge( __( 'inaktiv', 'svm' ), 'neutral' ) ),
				$amount,
				esc_html( isset( $cycles[ $fee_type['cycle'] ] ) ? $cycles[ $fee_type['cycle'] ] : $fee_type['cycle'] ),
				esc_html( $rule ? SVM_Rules::describe( $rule ) : __( 'alle Mitglieder', 'svm' ) ),
			);
		}

		SVM_UI::table(
			array( __( 'Beitrag', 'svm' ), __( 'Betrag', 'svm' ), __( 'Turnus', 'svm' ), __( 'Gilt für', 'svm' ) ),
			$rows,
			__( 'Noch keine Beitragsart angelegt.', 'svm' )
		);
	}

	/**
	 * Beitragsart bearbeiten.
	 *
	 * @return void
	 */
	public static function edit() {
		$fee_type_id = SVM_App::id();
		$fee_type    = $fee_type_id ? SVM_Fee_Types::get( $fee_type_id ) : null;
		$rule        = $fee_type ? SVM_Fee_Types::condition_rule( $fee_type_id ) : null;

		SVM_UI::page_head(
			$fee_type ? $fee_type['label'] : __( 'Neue Beitragsart', 'svm' ),
			__( 'Betrag, Turnus und die Bedingung, für wen der Beitrag gilt.', 'svm' ),
			array(
				array(
					'label' => __( '← Alle Beiträge', 'svm' ),
					'url'   => SVM_App::url( 'fees' ),
				),
			)
		);

		$amount_fields = array( 0 => __( '— kein Feld —', 'svm' ) );

		foreach ( SVM_Fields::defs( 'member' ) as $def ) {
			if ( in_array( $def['field_type'], array( 'amount', 'number' ), true ) ) {
				$amount_fields[ (int) $def['id'] ] = $def['label'];
			}
		}

		SVM_UI::card_open( __( 'Grunddaten', 'svm' ) );
		SVM_UI::form_open( 'save_fee_type', array( 'id' => $fee_type_id ) );

		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $fee_type ? $fee_type['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field(
			__( 'Betrag', 'svm' ),
			SVM_UI::input( 'amount', $fee_type ? $fee_type['amount'] : '0.00' ),
			__( 'Bei fester Betragsermittlung z. B. 120. Bei Formel z. B. max(30, {alter} * 1.5).', 'svm' )
		);
		SVM_UI::field(
			__( 'Betragsermittlung', 'svm' ),
			SVM_UI::select(
				'amount_mode',
				$fee_type ? $fee_type['amount_mode'] : 'fixed',
				array(
					'fixed'   => __( 'Fester Betrag', 'svm' ),
					'field'   => __( 'Aus einem Feld des Mitglieds', 'svm' ),
					'formula' => __( 'Formel', 'svm' ),
				)
			)
		);
		SVM_UI::field( __( 'Betragsfeld', 'svm' ), SVM_UI::select( 'amount_field_id', $fee_type ? $fee_type['amount_field_id'] : 0, $amount_fields ) );
		SVM_UI::field( __( 'Turnus', 'svm' ), SVM_UI::select( 'cycle', $fee_type ? $fee_type['cycle'] : 'yearly', SVM_Fee_Types::cycles() ) );
		SVM_UI::field(
			__( 'Fällig', 'svm' ),
			SVM_UI::input( 'due_rule', $fee_type ? $fee_type['due_rule'] : '', array( 'placeholder' => '01.03.' ) ),
			__( 'Leer = zum Periodenbeginn · „01.03.“ = fester Termin · „+30d“ = 30 Tage danach.', 'svm' )
		);
		SVM_UI::field(
			__( 'Bei unterjährigem Eintritt', 'svm' ),
			SVM_UI::select( 'proration', $fee_type ? $fee_type['proration'] : 'none', SVM_Fee_Types::prorations() )
		);
		SVM_UI::field( __( 'Gültig ab', 'svm' ), SVM_UI::input( 'valid_from', $fee_type ? $fee_type['valid_from'] : '', array( 'type' => 'date' ) ) );
		SVM_UI::field(
			__( 'Gültig bis', 'svm' ),
			SVM_UI::input( 'valid_to', $fee_type ? $fee_type['valid_to'] : '', array( 'type' => 'date' ) ),
			__( 'Bei einer Beitragserhöhung die alte Art hier beenden und eine neue anlegen.', 'svm' )
		);
		SVM_UI::field( __( 'Reihenfolge', 'svm' ), SVM_UI::input( 'priority', $fee_type ? $fee_type['priority'] : 10, array( 'type' => 'number' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $fee_type ? $fee_type['is_active'] : 1, __( 'Beitrag wird berechnet', 'svm' ) ) );
		SVM_UI::grid_close();

		echo '<h4 class="svm-section-title">' . esc_html__( 'Für wen gilt dieser Beitrag?', 'svm' ) . '</h4>';
		echo '<p class="svm-help">' . esc_html__( 'Ohne Bedingung zahlen ihn alle beitragspflichtigen Mitglieder. Einzelne Mitglieder können den Beitrag zusätzlich direkt zugeordnet bekommen.', 'svm' ) . '</p>';

		SVM_UI::field(
			__( 'Verknüpfung der Bedingungen', 'svm' ),
			SVM_UI::select(
				'logic',
				$rule ? $rule['logic'] : 'AND',
				array(
					'AND' => __( 'Alle Bedingungen müssen zutreffen', 'svm' ),
					'OR'  => __( 'Eine Bedingung genügt', 'svm' ),
				)
			)
		);

		SVM_UI::conditions( $rule );
		SVM_UI::form_close( __( 'Beitragsart speichern', 'svm' ) );
		SVM_UI::card_close();

		if ( $fee_type ) {
			SVM_UI::card_open();
			echo '<div class="svm-button-row">';
			SVM_UI::action_button(
				'delete_fee_type',
				array( 'id' => $fee_type_id ),
				__( 'Beitragsart löschen', 'svm' ),
				'danger',
				__( 'Beitragsart wirklich löschen? Bereits erzeugte Forderungen bleiben bestehen.', 'svm' )
			);
			echo '</div>';
			SVM_UI::card_close();
		}
	}

	/**
	 * Rabatte und Zuschläge.
	 *
	 * @return void
	 */
	public static function discounts() {
		$rule_id = SVM_App::id();
		$rule    = $rule_id ? SVM_Rules::get( $rule_id ) : null;

		SVM_UI::page_head(
			__( 'Rabatte und Zuschläge', 'svm' ),
			__( 'Familienrabatte, Mehrsparten-Nachlässe oder Höchstbeträge.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'discounts' );

		$types  = SVM_Rules::adjustment_types();
		$levels = array(
			'line'   => __( 'je Beitrag', 'svm' ),
			'member' => __( 'je Mitglied', 'svm' ),
			'family' => __( 'je Familie', 'svm' ),
		);

		$rows = array();

		foreach ( SVM_Rules::for_owner( 'fee_adjustment' ) as $item ) {
			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'discounts', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>' .
					( $item['is_active'] ? '' : ' ' . SVM_UI::badge( __( 'inaktiv', 'svm' ), 'neutral' ) ),
				esc_html(
					( isset( $types[ $item['adjustment_type'] ] ) ? $types[ $item['adjustment_type'] ] : $item['adjustment_type'] ) .
					' ' . number_format_i18n( (float) $item['adjustment_value'], 2 )
				),
				esc_html( isset( $levels[ $item['applies_to'] ] ) ? $levels[ $item['applies_to'] ] : $item['applies_to'] ),
				esc_html( SVM_Rules::describe( $item ) ),
			);
		}

		SVM_UI::table(
			array( __( 'Regel', 'svm' ), __( 'Wirkung', 'svm' ), __( 'Ebene', 'svm' ), __( 'Bedingung', 'svm' ) ),
			$rows,
			__( 'Noch keine Regel angelegt.', 'svm' )
		);

		$fee_types = array( 0 => __( 'alle Beitragsarten', 'svm' ) );

		foreach ( SVM_Fee_Types::all() as $fee_type ) {
			$fee_types[ (int) $fee_type['id'] ] = $fee_type['label'];
		}

		SVM_UI::card_open( $rule ? __( 'Regel bearbeiten', 'svm' ) : __( 'Neue Regel', 'svm' ) );
		SVM_UI::form_open( 'save_rule', array( 'id' => $rule_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $rule ? $rule['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Wirkung', 'svm' ), SVM_UI::select( 'adjustment_type', $rule ? $rule['adjustment_type'] : 'discount_pct', $types ) );
		SVM_UI::field( __( 'Wert', 'svm' ), SVM_UI::input( 'adjustment_value', $rule ? $rule['adjustment_value'] : '0', array( 'type' => 'number', 'step' => '0.01' ) ) );
		SVM_UI::field(
			__( 'Ebene', 'svm' ),
			SVM_UI::select( 'applies_to', $rule ? $rule['applies_to'] : 'line', $levels ),
			__( '„je Familie“ verteilt den Nachlass über alle Personen der Familie.', 'svm' )
		);
		SVM_UI::field( __( 'Nur für Beitragsart', 'svm' ), SVM_UI::select( 'owner_id', $rule ? $rule['owner_id'] : 0, $fee_types ) );
		SVM_UI::field( __( 'Reihenfolge', 'svm' ), SVM_UI::input( 'priority', $rule ? $rule['priority'] : 10, array( 'type' => 'number' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'stackable', $rule ? $rule['stackable'] : 1, __( 'Weitere Regeln dürfen danach greifen', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $rule ? $rule['is_active'] : 1, __( 'Regel anwenden', 'svm' ) ) );
		SVM_UI::grid_close();

		SVM_UI::field(
			__( 'Verknüpfung', 'svm' ),
			SVM_UI::select(
				'logic',
				$rule ? $rule['logic'] : 'AND',
				array( 'AND' => __( 'Alle Bedingungen', 'svm' ), 'OR' => __( 'Eine genügt', 'svm' ) )
			)
		);

		SVM_UI::conditions( $rule );
		SVM_UI::form_close( __( 'Regel speichern', 'svm' ) );

		if ( $rule ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_rule', array( 'id' => $rule_id ), __( 'Regel löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();
	}

	/**
	 * Beitragslauf mit Vorschau.
	 *
	 * @return void
	 */
	public static function run() {
		$period_start = SVM_App::arg( 'period_start', gmdate( 'Y-01-01' ) );
		$period_end   = SVM_App::arg( 'period_end', gmdate( 'Y-12-31' ) );
		$unit_id      = SVM_App::id( 'unit_id' );
		$preview      = '1' === SVM_App::arg( 'preview' );

		SVM_UI::page_head(
			__( 'Beiträge berechnen', 'svm' ),
			__( 'Erst die Vorschau ansehen, dann die Forderungen erzeugen. Die Vorschau verändert nichts.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'feerun' );

		SVM_UI::card_open( __( 'Zeitraum wählen', 'svm' ) );
		SVM_UI::filter_bar(
			array(
				SVM_UI::input( 'period_start', $period_start, array( 'type' => 'date' ) ),
				SVM_UI::input( 'period_end', $period_end, array( 'type' => 'date' ) ),
				SVM_UI::select( 'unit_id', $unit_id, array( 0 => __( 'Alle Sparten', 'svm' ) ) + SVM_Units::options() ),
			),
			array( 'svm_view' => 'feerun', 'preview' => 1 )
		);
		SVM_UI::card_close();

		if ( ! $preview ) {
			self::run_history();
			return;
		}

		$result = SVM_Fee_Run::execute(
			array(
				'period_start' => $period_start,
				'period_end'   => $period_end,
				'unit_id'      => $unit_id,
			),
			false
		);

		foreach ( $result['warnings'] as $warning ) {
			SVM_UI::notice( $warning, 'warning' );
		}

		SVM_UI::stats(
			array(
				array( 'label' => __( 'Mitglieder', 'svm' ), 'value' => number_format_i18n( $result['totals']['members'] ) ),
				array( 'label' => __( 'Neue Forderungen', 'svm' ), 'value' => number_format_i18n( $result['totals']['invoices'] ) ),
				array( 'label' => __( 'Gesamtbetrag', 'svm' ), 'value' => SVM_UI::money( $result['totals']['amount'] ) ),
			)
		);

		if ( ! empty( $result['rows'] ) ) {
			$rows  = array();
			$shown = 0;

			foreach ( $result['rows'] as $row ) {
				foreach ( $row['lines'] as $line ) {
					if ( $shown >= 150 ) {
						break 2;
					}

					$why = '<details class="svm-details"><summary>' . esc_html__( 'Berechnung', 'svm' ) . '</summary><ul>';

					foreach ( $line['log'] as $entry ) {
						$why .= '<li>' . esc_html( $entry ) . '</li>';
					}

					$why .= '</ul></details>';

					$rows[] = array(
						esc_html( $row['member_name'] ) . '<br /><span class="svm-muted">' . esc_html( $row['member_number'] ) . '</span>',
						esc_html( $line['description'] ),
						esc_html( SVM_UI::date( $line['due_date'] ) ),
						'<span class="svm-strong">' . esc_html( SVM_UI::money( $line['amount'] ) ) . '</span>',
						$why,
					);

					$shown++;
				}
			}

			SVM_UI::card_open( __( 'Vorschau', 'svm' ) );
			SVM_UI::table(
				array( __( 'Mitglied', 'svm' ), __( 'Beitrag', 'svm' ), __( 'Fällig', 'svm' ), __( 'Betrag', 'svm' ), '' ),
				$rows
			);

			if ( $shown >= 150 ) {
				echo '<p class="svm-help">' . esc_html__( 'Es werden die ersten 150 Positionen angezeigt.', 'svm' ) . '</p>';
			}

			SVM_UI::card_close();

			SVM_UI::card_open( __( 'Forderungen jetzt erzeugen', 'svm' ) );
			SVM_UI::form_open(
				'commit_fee_run',
				array(
					'period_start' => $period_start,
					'period_end'   => $period_end,
					'unit_id'      => $unit_id,
				)
			);
			SVM_UI::field( __( 'Bezeichnung des Laufs', 'svm' ), SVM_UI::input( 'label', '', array( 'placeholder' => __( 'z. B. Jahresbeiträge 2026', 'svm' ) ) ) );
			SVM_UI::form_close(
				__( 'Forderungen verbindlich erzeugen', 'svm' ),
				'primary',
				__( 'Sollen die angezeigten Forderungen jetzt erzeugt werden?', 'svm' )
			);
			SVM_UI::card_close();
		}

		self::run_history();
	}

	/**
	 * Bisherige Läufe.
	 *
	 * @return void
	 */
	private static function run_history() {
		$runs = SVM_Fee_Run::history();

		if ( empty( $runs ) ) {
			return;
		}

		$rows = array();

		foreach ( $runs as $run ) {
			$run_id  = (int) $run['id'];
			$actions = SVM_Fee_Run::can_delete( $run_id )
				? SVM_View_Members::button_cell(
					'delete_fee_run',
					array( 'id' => $run_id ),
					__( 'Zurücknehmen', 'svm' ),
					__( 'Diesen Lauf zurücknehmen? Alle daraus erzeugten Forderungen werden gelöscht.', 'svm' )
				)
				: '<span class="svm-muted">' . esc_html__( 'bereits bezahlt', 'svm' ) . '</span>';

			$rows[] = array(
				esc_html( $run['label'] ),
				esc_html( SVM_UI::date( $run['period_start'] ) . ' – ' . SVM_UI::date( $run['period_end'] ) ),
				esc_html( number_format_i18n( $run['invoice_count'] ) ),
				esc_html( SVM_UI::money( $run['total_amount'] ) ),
				esc_html( SVM_UI::date( $run['created_at'] ) ),
				$actions,
			);
		}

		SVM_UI::card_open(
			__( 'Bisherige Berechnungen', 'svm' ),
			__( 'Ein Lauf lässt sich vollständig zurücknehmen, solange auf keine seiner Forderungen gezahlt wurde.', 'svm' )
		);
		SVM_UI::table(
			array(
				__( 'Bezeichnung', 'svm' ),
				__( 'Zeitraum', 'svm' ),
				__( 'Forderungen', 'svm' ),
				__( 'Summe', 'svm' ),
				__( 'Erzeugt', 'svm' ),
				'',
			),
			$rows
		);
		SVM_UI::card_close();
	}

	/**
	 * Offene Posten.
	 *
	 * @return void
	 */
	public static function invoices() {
		$status  = SVM_App::arg( 'status', 'unpaid' );
		$unit_id = SVM_App::id( 'unit_id' );

		SVM_UI::page_head(
			__( 'Offene Posten', 'svm' ),
			__( 'Alle Forderungen mit ihrem Zahlstand.', 'svm' )
		);

		SVM_App::subnav( self::tabs(), 'invoices' );

		$statuses = SVM_Invoices::statuses();

		SVM_UI::filter_bar(
			array(
				SVM_UI::select(
					'status',
					$status,
					array( 'unpaid' => __( 'offen und teilbezahlt', 'svm' ), '' => __( 'alle', 'svm' ) ) + $statuses
				),
				SVM_UI::select( 'unit_id', $unit_id, array( 0 => __( 'Alle Sparten', 'svm' ) ) + SVM_Units::options() ),
			),
			array( 'svm_view' => 'invoices' )
		);

		$args     = array( 'status' => $status, 'unit_id' => $unit_id, 'limit' => 200 );
		$invoices = SVM_Invoices::query( $args );
		$totals   = SVM_Invoices::totals( $args );

		SVM_UI::stats(
			array(
				array( 'label' => __( 'Posten', 'svm' ), 'value' => number_format_i18n( $totals['count'] ) ),
				array( 'label' => __( 'Gesamt', 'svm' ), 'value' => SVM_UI::money( $totals['total'] ) ),
				array( 'label' => __( 'Bezahlt', 'svm' ), 'value' => SVM_UI::money( $totals['paid'] ) ),
				array( 'label' => __( 'Offen', 'svm' ), 'value' => SVM_UI::money( $totals['open'] ) ),
			)
		);

		$rows = array();

		// Löschbarkeit einmal für die ganze Liste ermitteln statt je Zeile.
		$invoice_ids = array();

		foreach ( $invoices as $invoice ) {
			$invoice_ids[] = (int) $invoice['id'];
		}

		$deletable = SVM_Permissions::current_user_can( 'invoices_edit' )
			? SVM_Invoices::deletable_ids( $invoice_ids )
			: array();

		foreach ( $invoices as $invoice ) {
			$actions = '';

			if ( SVM_Permissions::current_user_can( 'invoices_edit' ) ) {
				if ( 'cancelled' !== $invoice['status'] ) {
					$actions .= SVM_View_Members::button_cell(
						'cancel_invoice',
						array( 'id' => (int) $invoice['id'] ),
						__( 'Stornieren', 'svm' ),
						__( 'Forderung wirklich stornieren?', 'svm' )
					);
				}

				// Endgültig löschen geht nur, solange nichts gezahlt wurde.
				if ( in_array( (int) $invoice['id'], $deletable, true ) ) {
					$actions .= SVM_View_Members::button_cell(
						'delete_invoice',
						array( 'id' => (int) $invoice['id'] ),
						__( 'Löschen', 'svm' ),
						__( 'Forderung endgültig löschen? Das lässt sich nicht rückgängig machen.', 'svm' )
					);
				}

				$actions = '' !== $actions ? '<div class="svm-button-row">' . $actions . '</div>' : '';
			}

			$tone = 'paid' === $invoice['status'] ? 'ok' : ( 'cancelled' === $invoice['status'] ? 'neutral' : 'warn' );

			$rows[] = array(
				'<a href="' . esc_url( SVM_App::url( 'member', array( 'id' => (int) $invoice['member_id'] ) ) ) . '">' .
					esc_html( SVM_Members::display_name( (int) $invoice['member_id'] ) ) . '</a>',
				esc_html( $invoice['description'] ),
				esc_html( SVM_UI::date( $invoice['due_date'] ) ),
				esc_html( SVM_UI::money( $invoice['amount'] ) ),
				esc_html( SVM_UI::money( SVM_Invoices::remaining( $invoice ) ) ),
				SVM_UI::badge( isset( $statuses[ $invoice['status'] ] ) ? $statuses[ $invoice['status'] ] : $invoice['status'], $tone ),
				$actions,
			);
		}

		SVM_UI::table(
			array(
				__( 'Mitglied', 'svm' ),
				__( 'Beschreibung', 'svm' ),
				__( 'Fällig', 'svm' ),
				__( 'Betrag', 'svm' ),
				__( 'Offen', 'svm' ),
				__( 'Status', 'svm' ),
				'',
			),
			$rows,
			__( 'Keine Forderungen für diese Auswahl.', 'svm' )
		);
	}
}
