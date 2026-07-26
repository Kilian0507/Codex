<?php
/**
 * Bedingungseditor.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ein Editor für alle Regelarten — Beiträge, Rabatte, Nachrichten-Zielgruppen, Exportfilter.
 */
class SVM_Condition_Editor {

	/**
	 * Gibt den Editor aus.
	 *
	 * @param array|null $rule      Regel (für bestehende Bedingungen).
	 * @param int        $min_rows  Mindestanzahl leerer Zeilen.
	 * @return void
	 */
	public static function render( $rule = null, $min_rows = 3 ) {
		$conditions = $rule ? SVM_Rules::conditions( (int) $rule['id'] ) : array();
		$rows       = max( $min_rows, count( $conditions ) + 1 );

		$subjects  = SVM_Rule_Engine::subjects();
		$operators = SVM_Rule_Engine::operators();
		$fields    = array( 0 => __( '— Feld wählen —', 'svm' ) );

		foreach ( SVM_Fields::defs( 'member' ) as $def ) {
			if ( in_array( $def['field_type'], array( 'heading' ), true ) ) {
				continue;
			}
			$fields[ (int) $def['id'] ] = $def['label'];
		}

		echo '<p>' . esc_html__( 'Bedingungen bestimmen, für wen diese Regel gilt. Leere Zeilen werden ignoriert.', 'svm' ) . '</p>';

		echo '<table class="widefat svm-conditions"><thead><tr>';
		echo '<th style="width:18%">' . esc_html__( 'Worauf', 'svm' ) . '</th>';
		echo '<th style="width:22%">' . esc_html__( 'Feld', 'svm' ) . '</th>';
		echo '<th style="width:20%">' . esc_html__( 'Vergleich', 'svm' ) . '</th>';
		echo '<th style="width:25%">' . esc_html__( 'Wert', 'svm' ) . '</th>';
		echo '<th style="width:15%">' . esc_html__( 'Stichtag (Alter)', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		for ( $i = 0; $i < $rows; $i++ ) {
			$condition = isset( $conditions[ $i ] ) ? $conditions[ $i ] : array(
				'subject'         => '',
				'field_id'        => 0,
				'operator'        => 'equals',
				'compare_value'   => '',
				'compare_value_2' => '',
				'reference_date'  => 'year_start',
			);

			$prefix = 'condition[' . $i . ']';

			echo '<tr>';

			echo '<td>' . SVM_Form::select( // phpcs:ignore WordPress.Security.EscapeOutput
				$prefix . '[subject]',
				$condition['subject'],
				array( '' => __( '— keine —', 'svm' ) ) + $subjects
			) . '</td>';

			echo '<td>' . SVM_Form::select( $prefix . '[field_id]', $condition['field_id'], $fields ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput

			echo '<td>' . SVM_Form::select( $prefix . '[operator]', $condition['operator'], $operators ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput

			echo '<td>';
			echo SVM_Form::input( $prefix . '[compare_value]', $condition['compare_value'], array( 'class' => 'regular-text' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo ' ' . SVM_Form::input( // phpcs:ignore WordPress.Security.EscapeOutput
				$prefix . '[compare_value_2]',
				$condition['compare_value_2'],
				array( 'class' => 'small-text', 'placeholder' => __( 'bis', 'svm' ) )
			);
			echo '</td>';

			echo '<td>' . SVM_Form::select( // phpcs:ignore WordPress.Security.EscapeOutput
				$prefix . '[reference_date]',
				$condition['reference_date'],
				SVM_Rule_Engine::reference_dates()
			) . '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description">';
		echo esc_html__( 'Hinweise: Bei „Sparte/Gruppe“ und „Mitgliedsstatus“ die ID(s) kommagetrennt eintragen. Bei „ist eines von“ mehrere Werte kommagetrennt angeben.', 'svm' );
		echo '</p>';

		self::reference_list();
	}

	/**
	 * Zeigt die IDs von Einheiten und Status als Nachschlagehilfe.
	 *
	 * @return void
	 */
	private static function reference_list() {
		$units    = SVM_Units::all();
		$statuses = SVM_Statuses::all();

		if ( empty( $units ) && empty( $statuses ) ) {
			return;
		}

		echo '<details class="svm-reference"><summary>' . esc_html__( 'IDs nachschlagen', 'svm' ) . '</summary><div class="svm-reference-body">';

		if ( ! empty( $units ) ) {
			echo '<p><strong>' . esc_html__( 'Sparten/Gruppen', 'svm' ) . ':</strong> ';
			$parts = array();
			foreach ( $units as $unit ) {
				$parts[] = $unit['name'] . ' = ' . (int) $unit['id'];
			}
			echo esc_html( implode( ' · ', $parts ) ) . '</p>';
		}

		if ( ! empty( $statuses ) ) {
			echo '<p><strong>' . esc_html__( 'Status', 'svm' ) . ':</strong> ';
			$parts = array();
			foreach ( $statuses as $status ) {
				$parts[] = $status['label'] . ' = ' . (int) $status['id'];
			}
			echo esc_html( implode( ' · ', $parts ) ) . '</p>';
		}

		echo '</div></details>';
	}
}
