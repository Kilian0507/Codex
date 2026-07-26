<?php
/**
 * Beitragsseite.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Beitragsarten, Rabattregeln, Beitragslauf mit Simulation und Forderungen.
 */
class SVM_Page_Fees {

	/**
	 * Gibt die Seite aus.
	 *
	 * @return void
	 */
	public static function render() {
		$tabs = array(
			'types'       => __( 'Beitragsarten', 'svm' ),
			'adjustments' => __( 'Rabatte & Zuschläge', 'svm' ),
			'run'         => __( 'Beitragslauf', 'svm' ),
			'invoices'    => __( 'Forderungen', 'svm' ),
		);

		$current = SVM_Admin_Menu::current_tab( 'types' );

		echo '<div class="wrap svm-wrap">';
		echo '<h1>' . esc_html__( 'Beiträge', 'svm' ) . '</h1>';

		SVM_Admin_Menu::notices();
		SVM_Admin_Menu::tabs( 'svm-fees', $tabs, $current );

		switch ( $current ) {
			case 'adjustments':
				self::render_adjustments();
				break;

			case 'run':
				self::render_run();
				break;

			case 'invoices':
				self::render_invoices();
				break;

			default:
				self::render_types();
				break;
		}

		echo '</div>';
	}

	/**
	 * Beitragsarten.
	 *
	 * @return void
	 */
	private static function render_types() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id  = isset( $_GET['fee_type_id'] ) ? absint( $_GET['fee_type_id'] ) : 0;
		$fee_type = $edit_id ? SVM_Fee_Types::get( $edit_id ) : null;
		$cycles   = SVM_Fee_Types::cycles();

		echo '<h2>' . esc_html__( 'Vorhandene Beitragsarten', 'svm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Betrag', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Turnus', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Gilt für', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Gültig', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Fee_Types::all() as $type ) {
			$rule = SVM_Fee_Types::condition_rule( (int) $type['id'] );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $type['label'] ) . '</strong>';
			if ( ! $type['is_active'] ) {
				echo ' <em>(' . esc_html__( 'inaktiv', 'svm' ) . ')</em>';
			}
			echo '</td>';

			echo '<td>' . esc_html(
				'formula' === $type['amount_mode']
					? $type['amount']
					: number_format_i18n( (float) $type['amount'], 2 ) . ' €'
			) . '</td>';

			echo '<td>' . esc_html( isset( $cycles[ $type['cycle'] ] ) ? $cycles[ $type['cycle'] ] : $type['cycle'] ) . '</td>';
			echo '<td>' . esc_html( $rule ? SVM_Rules::describe( $rule ) : __( 'alle', 'svm' ) ) . '</td>';
			echo '<td>' . esc_html( trim( $type['valid_from'] . ' – ' . $type['valid_to'], ' –' ) ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-fees', array( 'tab' => 'types', 'fee_type_id' => (int) $type['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html( $fee_type ? __( 'Beitragsart bearbeiten', 'svm' ) : __( 'Neue Beitragsart', 'svm' ) ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields(
			'save_fee_type',
			array(
				'id'       => $edit_id,
				'svm_page' => 'svm-fees',
				'svm_tab'  => 'types',
			)
		);

		$amount_fields = array( 0 => __( '— Feld wählen —', 'svm' ) );

		foreach ( SVM_Fields::defs( 'member' ) as $def ) {
			if ( in_array( $def['field_type'], array( 'amount', 'number' ), true ) ) {
				$amount_fields[ (int) $def['id'] ] = $def['label'];
			}
		}

		SVM_Form::open_table();

		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $fee_type ? $fee_type['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row( __( 'Beschreibung', 'svm' ), SVM_Form::textarea( 'description', $fee_type ? $fee_type['description'] : '', array( 'rows' => 2 ) ) );

		SVM_Form::row(
			__( 'Betragsermittlung', 'svm' ),
			SVM_Form::select(
				'amount_mode',
				$fee_type ? $fee_type['amount_mode'] : 'fixed',
				array(
					'fixed'   => __( 'Fester Betrag', 'svm' ),
					'field'   => __( 'Aus einem Feld des Mitglieds', 'svm' ),
					'formula' => __( 'Formel', 'svm' ),
				)
			)
		);

		SVM_Form::row(
			__( 'Betrag bzw. Formel', 'svm' ),
			SVM_Form::input( 'amount', $fee_type ? $fee_type['amount'] : '0.00' ),
			__( 'Bei Formel z. B. „{grundbeitrag} * 0.5 + 10“ oder „max(30, {alter} * 1.5)“. Verfügbare Variablen: alle Feldschlüssel sowie {alter}, {anzahl_sparten}, {familienposition}.', 'svm' )
		);

		SVM_Form::row( __( 'Betragsfeld', 'svm' ), SVM_Form::select( 'amount_field_id', $fee_type ? $fee_type['amount_field_id'] : 0, $amount_fields ) );

		SVM_Form::row( __( 'Turnus', 'svm' ), SVM_Form::select( 'cycle', $fee_type ? $fee_type['cycle'] : 'yearly', $cycles ) );

		SVM_Form::row(
			__( 'Fälligkeitsregel', 'svm' ),
			SVM_Form::input( 'due_rule', $fee_type ? $fee_type['due_rule'] : '', array( 'class' => 'regular-text' ) ),
			__( 'Leer = zum Periodenbeginn. „01.03.“ = fester Termin. „+30d“ = 30 Tage nach Periodenbeginn.', 'svm' )
		);

		SVM_Form::row(
			__( 'Anteilige Berechnung', 'svm' ),
			SVM_Form::select( 'proration', $fee_type ? $fee_type['proration'] : 'none', SVM_Fee_Types::prorations() ),
			__( 'Greift bei unterjährigem Ein- oder Austritt.', 'svm' )
		);

		SVM_Form::row(
			__( 'Gültig von / bis', 'svm' ),
			SVM_Form::input( 'valid_from', $fee_type ? $fee_type['valid_from'] : '', array( 'type' => 'date' ) ) . ' ' .
			SVM_Form::input( 'valid_to', $fee_type ? $fee_type['valid_to'] : '', array( 'type' => 'date' ) ),
			__( 'Für Beitragserhöhungen: alte Art beenden, neue Art beginnen — die Historie bleibt nachvollziehbar.', 'svm' )
		);

		SVM_Form::row( __( 'Buchungskonto', 'svm' ), SVM_Form::input( 'account_code', $fee_type ? $fee_type['account_code'] : '', array( 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Reihenfolge', 'svm' ), SVM_Form::input( 'priority', $fee_type ? $fee_type['priority'] : 10, array( 'type' => 'number', 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $fee_type ? $fee_type['is_active'] : 1, __( 'Beitragsart wird im Beitragslauf berücksichtigt', 'svm' ) ) );

		SVM_Form::close_table();

		$rule = $fee_type ? SVM_Fee_Types::condition_rule( $edit_id ) : null;

		echo '<h3>' . esc_html__( 'Für wen gilt dieser Beitrag?', 'svm' ) . '</h3>';
		echo '<p>' . esc_html__( 'Verknüpfung der Bedingungen:', 'svm' ) . ' ' .
			SVM_Form::select( 'logic', $rule ? $rule['logic'] : 'AND', array( 'AND' => __( 'alle müssen zutreffen (UND)', 'svm' ), 'OR' => __( 'eine genügt (ODER)', 'svm' ) ) ) . // phpcs:ignore WordPress.Security.EscapeOutput
			'</p>';

		SVM_Condition_Editor::render( $rule );

		submit_button( $fee_type ? __( 'Beitragsart speichern', 'svm' ) : __( 'Beitragsart anlegen', 'svm' ) );
		echo '</form>';

		if ( $fee_type ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" onsubmit="return confirm(\'' .
				esc_attr__( 'Beitragsart wirklich löschen?', 'svm' ) . '\');">';
			SVM_Form::action_fields( 'delete_fee_type', array( 'id' => $edit_id, 'svm_page' => 'svm-fees', 'svm_tab' => 'types' ) );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Beitragsart löschen', 'svm' ) . '</button>';
			echo '</form>';
		}
	}

	/**
	 * Rabatte und Zuschläge.
	 *
	 * @return void
	 */
	private static function render_adjustments() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;
		$rule    = $edit_id ? SVM_Rules::get( $edit_id ) : null;
		$types   = SVM_Rules::adjustment_types();

		echo '<h2>' . esc_html__( 'Regeln für Rabatte, Zuschläge und Deckelungen', 'svm' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Wirkung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Ebene', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Bedingung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Reihenfolge', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		$levels = array(
			'line'   => __( 'je Beitragsposition', 'svm' ),
			'member' => __( 'je Mitglied (Gesamtbetrag)', 'svm' ),
			'family' => __( 'je Familienverbund', 'svm' ),
		);

		foreach ( SVM_Rules::for_owner( 'fee_adjustment' ) as $item ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $item['label'] ) . '</strong></td>';
			echo '<td>' . esc_html(
				( isset( $types[ $item['adjustment_type'] ] ) ? $types[ $item['adjustment_type'] ] : $item['adjustment_type'] ) .
				' ' . number_format_i18n( (float) $item['adjustment_value'], 2 )
			) . '</td>';
			echo '<td>' . esc_html( isset( $levels[ $item['applies_to'] ] ) ? $levels[ $item['applies_to'] ] : $item['applies_to'] ) . '</td>';
			echo '<td>' . esc_html( SVM_Rules::describe( $item ) ) . '</td>';
			echo '<td>' . esc_html( $item['priority'] ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-fees', array( 'tab' => 'adjustments', 'rule_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html( $rule ? __( 'Regel bearbeiten', 'svm' ) : __( 'Neue Regel', 'svm' ) ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields(
			'save_rule',
			array(
				'id'       => $edit_id,
				'svm_page' => 'svm-fees',
				'svm_tab'  => 'adjustments',
			)
		);
		echo '<input type="hidden" name="rule_type" value="fee_adjustment" />';

		$fee_types = array( 0 => __( 'alle Beitragsarten', 'svm' ) );

		foreach ( SVM_Fee_Types::all() as $type ) {
			$fee_types[ (int) $type['id'] ] = $type['label'];
		}

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $rule ? $rule['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row( __( 'Wirkung', 'svm' ), SVM_Form::select( 'adjustment_type', $rule ? $rule['adjustment_type'] : 'discount_pct', $types ) );
		SVM_Form::row( __( 'Wert', 'svm' ), SVM_Form::input( 'adjustment_value', $rule ? $rule['adjustment_value'] : '0', array( 'class' => 'small-text' ) ) );
		SVM_Form::row(
			__( 'Ebene', 'svm' ),
			SVM_Form::select( 'applies_to', $rule ? $rule['applies_to'] : 'line', $levels ),
			__( 'Positionsebene wirkt auf einzelne Beiträge, Mitgliedsebene auf die Summe, Familienebene auf den gesamten Verbund.', 'svm' )
		);
		SVM_Form::row(
			__( 'Nur für Beitragsart', 'svm' ),
			SVM_Form::select( 'owner_id', $rule ? $rule['owner_id'] : 0, $fee_types )
		);
		echo '<input type="hidden" name="owner_type" value="fee_type" />';
		SVM_Form::row( __( 'Reihenfolge', 'svm' ), SVM_Form::input( 'priority', $rule ? $rule['priority'] : 10, array( 'type' => 'number', 'class' => 'small-text' ) ) );
		SVM_Form::row( __( 'Stapelbar', 'svm' ), SVM_Form::checkbox( 'stackable', $rule ? $rule['stackable'] : 1, __( 'Weitere Regeln dürfen danach greifen', 'svm' ) ) );
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $rule ? $rule['is_active'] : 1, __( 'Regel anwenden', 'svm' ) ) );
		SVM_Form::row(
			__( 'Verknüpfung', 'svm' ),
			SVM_Form::select( 'logic', $rule ? $rule['logic'] : 'AND', array( 'AND' => __( 'UND', 'svm' ), 'OR' => __( 'ODER', 'svm' ) ) )
		);
		SVM_Form::close_table();

		SVM_Condition_Editor::render( $rule );

		submit_button( __( 'Regel speichern', 'svm' ) );
		echo '</form>';

		if ( $rule ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			SVM_Form::action_fields( 'delete_rule', array( 'id' => $edit_id, 'svm_page' => 'svm-fees', 'svm_tab' => 'adjustments' ) );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Regel löschen', 'svm' ) . '</button>';
			echo '</form>';
		}
	}

	/**
	 * Beitragslauf mit Vorschau.
	 *
	 * @return void
	 */
	private static function render_run() {
		SVM_Permissions::require_permission( 'fee_run' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$period_start = isset( $_GET['period_start'] ) ? sanitize_text_field( wp_unslash( $_GET['period_start'] ) ) : gmdate( 'Y-01-01' );
		$period_end   = isset( $_GET['period_end'] ) ? sanitize_text_field( wp_unslash( $_GET['period_end'] ) ) : gmdate( 'Y-12-31' );
		$unit_id      = isset( $_GET['unit_id'] ) ? absint( $_GET['unit_id'] ) : 0;
		$simulate     = isset( $_GET['simulate'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<h2>' . esc_html__( 'Beitragslauf', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Erst simulieren, dann festschreiben. Die Simulation verändert nichts und zeigt für jede Position, welche Regeln gegriffen haben.', 'svm' ) . '</p>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="svm-fees" />';
		echo '<input type="hidden" name="tab" value="run" />';

		SVM_Form::open_table();
		SVM_Form::row( __( 'Zeitraum von', 'svm' ), SVM_Form::input( 'period_start', $period_start, array( 'type' => 'date' ) ) );
		SVM_Form::row( __( 'Zeitraum bis', 'svm' ), SVM_Form::input( 'period_end', $period_end, array( 'type' => 'date' ) ) );
		SVM_Form::row(
			__( 'Nur Sparte/Gruppe', 'svm' ),
			SVM_Form::select( 'unit_id', $unit_id, array( 0 => __( 'alle', 'svm' ) ) + SVM_Units::options() )
		);
		SVM_Form::close_table();

		echo '<p><button type="submit" name="simulate" value="1" class="button button-primary">' .
			esc_html__( 'Simulation starten', 'svm' ) . '</button></p>';
		echo '</form>';

		if ( ! $simulate ) {
			self::render_run_history();
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

		echo '<h3>' . esc_html__( 'Vorschau', 'svm' ) . '</h3>';

		foreach ( $result['warnings'] as $warning ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
		}

		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: Mitglieder, 2: Forderungen, 3: Summe. */
				__( '%1$d Mitglieder · %2$d neue Forderungen · %3$s € gesamt', 'svm' ),
				$result['totals']['members'],
				$result['totals']['invoices'],
				number_format_i18n( $result['totals']['amount'], 2 )
			)
		) . '</strong></p>';

		if ( ! empty( $result['rows'] ) ) {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Mitglied', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Position', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Fällig', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Betrag', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Wie kommt der Betrag zustande?', 'svm' ) . '</th>';
			echo '</tr></thead><tbody>';

			$shown = 0;

			foreach ( $result['rows'] as $row ) {
				foreach ( $row['lines'] as $line ) {
					if ( $shown >= 200 ) {
						break 2;
					}

					echo '<tr>';
					echo '<td>' . esc_html( $row['member_name'] ) . '<br /><small>' . esc_html( $row['member_number'] ) . '</small></td>';
					echo '<td>' . esc_html( $line['description'] ) . '</td>';
					echo '<td>' . esc_html( $line['due_date'] ) . '</td>';
					echo '<td><strong>' . esc_html( number_format_i18n( (float) $line['amount'], 2 ) ) . ' €</strong></td>';
					echo '<td><ul class="svm-log">';

					foreach ( $line['log'] as $entry ) {
						echo '<li>' . esc_html( $entry ) . '</li>';
					}

					echo '</ul></td></tr>';
					$shown++;
				}
			}

			echo '</tbody></table>';

			if ( $shown >= 200 ) {
				echo '<p class="description">' . esc_html__( 'Nur die ersten 200 Positionen werden angezeigt.', 'svm' ) . '</p>';
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" onsubmit="return confirm(\'' .
				esc_attr__( 'Forderungen jetzt verbindlich erzeugen?', 'svm' ) . '\');">';
			SVM_Form::action_fields( 'commit_fee_run', array( 'svm_page' => 'svm-fees', 'svm_tab' => 'run' ) );
			echo '<input type="hidden" name="period_start" value="' . esc_attr( $period_start ) . '" />';
			echo '<input type="hidden" name="period_end" value="' . esc_attr( $period_end ) . '" />';
			echo '<input type="hidden" name="unit_id" value="' . esc_attr( $unit_id ) . '" />';
			echo '<p><label>' . esc_html__( 'Bezeichnung des Laufs:', 'svm' ) . ' ' .
				SVM_Form::input( 'label', '' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			submit_button( __( 'Forderungen jetzt erzeugen', 'svm' ), 'primary' );
			echo '</form>';
		}

		self::render_run_history();
	}

	/**
	 * Bisherige Beitragsläufe.
	 *
	 * @return void
	 */
	private static function render_run_history() {
		$runs = SVM_Fee_Run::history();

		if ( empty( $runs ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Bisherige Läufe', 'svm' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Zeitraum', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Forderungen', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Summe', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Erstellt', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $runs as $run ) {
			echo '<tr>';
			echo '<td>' . esc_html( $run['label'] ) . '</td>';
			echo '<td>' . esc_html( $run['period_start'] . ' – ' . $run['period_end'] ) . '</td>';
			echo '<td>' . esc_html( $run['invoice_count'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $run['total_amount'], 2 ) ) . ' €</td>';
			echo '<td>' . esc_html( $run['created_at'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Forderungsliste.
	 *
	 * @return void
	 */
	private static function render_invoices() {
		SVM_Permissions::require_permission( 'invoices_view' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'unpaid';
		$unit_id = isset( $_GET['unit_id'] ) ? absint( $_GET['unit_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$invoices = SVM_Invoices::query( array( 'status' => $status, 'unit_id' => $unit_id, 'limit' => 200 ) );
		$totals   = SVM_Invoices::totals( array( 'status' => $status, 'unit_id' => $unit_id ) );
		$statuses = SVM_Invoices::statuses();

		echo '<h2>' . esc_html__( 'Forderungen', 'svm' ) . '</h2>';

		echo '<form method="get" class="svm-filters">';
		echo '<input type="hidden" name="page" value="svm-fees" />';
		echo '<input type="hidden" name="tab" value="invoices" />';
		echo SVM_Form::select( 'status', $status, array( 'unpaid' => __( 'offen und teilbezahlt', 'svm' ), '' => __( 'alle', 'svm' ) ) + $statuses ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' ' . SVM_Form::select( 'unit_id', $unit_id, array( 0 => __( 'alle Sparten/Gruppen', 'svm' ) ) + SVM_Units::options() ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <button type="submit" class="button">' . esc_html__( 'Filtern', 'svm' ) . '</button>';
		echo '</form>';

		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: Anzahl, 2: Gesamt, 3: Bezahlt, 4: Offen. */
				__( '%1$d Posten · gesamt %2$s € · bezahlt %3$s € · offen %4$s €', 'svm' ),
				$totals['count'],
				number_format_i18n( $totals['total'], 2 ),
				number_format_i18n( $totals['paid'], 2 ),
				number_format_i18n( $totals['open'], 2 )
			)
		) . '</strong></p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Mitglied', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Beschreibung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Fällig', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Betrag', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Offen', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Mahnstufe', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $invoices as $invoice ) {
			echo '<tr>';
			echo '<td><a href="' . esc_url(
				SVM_Admin_Menu::url( 'svm-members', array( 'view' => 'edit', 'member_id' => (int) $invoice['member_id'] ) )
			) . '">' . esc_html( SVM_Members::display_name( (int) $invoice['member_id'] ) ) . '</a></td>';
			echo '<td>' . esc_html( $invoice['description'] ) . '</td>';
			echo '<td>' . esc_html( $invoice['due_date'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $invoice['amount'], 2 ) ) . ' €</td>';
			echo '<td>' . esc_html( number_format_i18n( SVM_Invoices::remaining( $invoice ), 2 ) ) . ' €</td>';
			echo '<td>' . esc_html( isset( $statuses[ $invoice['status'] ] ) ? $statuses[ $invoice['status'] ] : $invoice['status'] ) . '</td>';
			echo '<td>' . esc_html( $invoice['dunning_level'] ? $invoice['dunning_level'] : '—' ) . '</td>';
			echo '<td>';

			if ( SVM_Permissions::current_user_can( 'invoices_edit' ) && 'cancelled' !== $invoice['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
				SVM_Form::action_fields( 'cancel_invoice', array( 'id' => (int) $invoice['id'], 'svm_page' => 'svm-fees', 'svm_tab' => 'invoices' ) );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Stornieren', 'svm' ) . '</button>';
				echo '</form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}
}
