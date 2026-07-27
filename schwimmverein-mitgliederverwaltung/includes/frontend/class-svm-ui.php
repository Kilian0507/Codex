<?php
/**
 * Bausteine der Oberfläche.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alle Ansichten bauen aus diesen Komponenten — dadurch sieht die gesamte
 * Verwaltung gleich aus und bleibt auf dem Handy bedienbar.
 */
class SVM_UI {

	/* ---------------------------------------------------------------------
	 * Seitenaufbau
	 * ------------------------------------------------------------------ */

	/**
	 * Seitenkopf einer Ansicht.
	 *
	 * @param string $title    Überschrift.
	 * @param string $subtitle Erläuterung.
	 * @param array  $actions  Liste aus label, url, primary.
	 * @return void
	 */
	public static function page_head( $title, $subtitle = '', array $actions = array() ) {
		echo '<div class="svm-page-head">';
		echo '<div><h2 class="svm-page-title">' . esc_html( $title ) . '</h2>';

		if ( '' !== $subtitle ) {
			echo '<p class="svm-page-sub">' . esc_html( $subtitle ) . '</p>';
		}

		echo '</div>';

		if ( ! empty( $actions ) ) {
			echo '<div class="svm-page-actions">';

			foreach ( $actions as $action ) {
				printf(
					'<a class="svm-btn%s" href="%s">%s</a>',
					empty( $action['primary'] ) ? '' : ' svm-btn-primary',
					esc_url( $action['url'] ),
					esc_html( $action['label'] )
				);
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Öffnet eine Karte.
	 *
	 * @param string $title Titel.
	 * @param string $help  Erläuterung.
	 * @return void
	 */
	public static function card_open( $title = '', $help = '' ) {
		echo '<section class="svm-card">';

		if ( '' !== $title ) {
			echo '<h3 class="svm-card-title">' . esc_html( $title ) . '</h3>';
		}

		if ( '' !== $help ) {
			echo '<p class="svm-card-help">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Schließt eine Karte.
	 *
	 * @return void
	 */
	public static function card_close() {
		echo '</section>';
	}

	/**
	 * Kennzahlenkacheln.
	 *
	 * @param array $stats Liste aus label, value, url, hint.
	 * @return void
	 */
	public static function stats( array $stats ) {
		echo '<div class="svm-stats">';

		foreach ( $stats as $stat ) {
			$inner = '<span class="svm-stat-label">' . esc_html( $stat['label'] ) . '</span>' .
				'<span class="svm-stat-value">' . esc_html( $stat['value'] ) . '</span>';

			if ( ! empty( $stat['hint'] ) ) {
				$inner .= '<span class="svm-stat-hint">' . esc_html( $stat['hint'] ) . '</span>';
			}

			if ( ! empty( $stat['url'] ) ) {
				printf( '<a class="svm-stat" href="%s">%s</a>', esc_url( $stat['url'] ), $inner ); // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				printf( '<div class="svm-stat">%s</div>', $inner ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}

		echo '</div>';
	}

	/**
	 * Hinweisbox.
	 *
	 * @param string $text Text.
	 * @param string $type info|success|warning|error.
	 * @return void
	 */
	public static function notice( $text, $type = 'info' ) {
		printf(
			'<div class="svm-notice svm-notice-%s">%s</div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/**
	 * Leerer Zustand mit Handlungsaufforderung.
	 *
	 * @param string $text  Text.
	 * @param string $url   Ziel.
	 * @param string $label Beschriftung.
	 * @return void
	 */
	public static function empty_state( $text, $url = '', $label = '' ) {
		echo '<div class="svm-empty"><p>' . esc_html( $text ) . '</p>';

		if ( '' !== $url ) {
			echo '<a class="svm-btn svm-btn-primary" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '</div>';
	}

	/**
	 * Reiternavigation innerhalb einer Ansicht.
	 *
	 * @param array  $tabs    Schlüssel => array( label, url ).
	 * @param string $current Aktiver Reiter.
	 * @return void
	 */
	public static function tabs( array $tabs, $current ) {
		echo '<nav class="svm-subnav">';

		foreach ( $tabs as $key => $tab ) {
			printf(
				'<a class="svm-subnav-item%s" href="%s">%s</a>',
				$key === $current ? ' is-active' : '',
				esc_url( $tab['url'] ),
				esc_html( $tab['label'] )
			);
		}

		echo '</nav>';
	}

	/**
	 * Kennzeichnung.
	 *
	 * @param string $text Text.
	 * @param string $tone neutral|ok|warn|bad.
	 * @return string
	 */
	public static function badge( $text, $tone = 'neutral' ) {
		return '<span class="svm-badge svm-badge-' . esc_attr( $tone ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Geldbetrag.
	 *
	 * @param float $amount Betrag.
	 * @return string
	 */
	public static function money( $amount ) {
		return number_format_i18n( (float) $amount, 2 ) . ' €';
	}

	/**
	 * Datum in lokaler Schreibweise.
	 *
	 * @param string $date Datum.
	 * @return string
	 */
	public static function date( $date ) {
		if ( empty( $date ) || '0000-00-00' === $date ) {
			return '—';
		}

		$timestamp = strtotime( (string) $date );

		return $timestamp ? date_i18n( 'd.m.Y', $timestamp ) : (string) $date;
	}

	/* ---------------------------------------------------------------------
	 * Tabellen
	 * ------------------------------------------------------------------ */

	/**
	 * Gibt eine Tabelle aus. Auf schmalen Bildschirmen werden die Zeilen
	 * untereinander als Blöcke dargestellt.
	 *
	 * @param array $columns Spaltenüberschriften.
	 * @param array $rows    Zeilen, je Zeile eine Liste bereits escapter HTML-Zellen.
	 * @param string $empty  Text, wenn keine Zeilen vorhanden sind.
	 * @return void
	 */
	public static function table( array $columns, array $rows, $empty = '' ) {
		if ( empty( $rows ) ) {
			if ( '' !== $empty ) {
				self::empty_state( $empty );
			}
			return;
		}

		echo '<div class="svm-table-wrap"><table class="svm-table"><thead><tr>';

		foreach ( $columns as $column ) {
			echo '<th>' . esc_html( $column ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';

			$index = 0;
			foreach ( $row as $cell ) {
				$label = isset( $columns[ $index ] ) ? $columns[ $index ] : '';
				echo '<td data-label="' . esc_attr( $label ) . '">' . $cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				$index++;
			}

			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/* ---------------------------------------------------------------------
	 * Formulare
	 * ------------------------------------------------------------------ */

	/**
	 * Öffnet ein Formular, das über den Router läuft.
	 *
	 * @param string $action   Aktionsschlüssel.
	 * @param array  $hidden   Versteckte Felder.
	 * @param bool   $upload   Datei-Upload erlauben.
	 * @return void
	 */
	public static function form_open( $action, array $hidden = array(), $upload = false ) {
		printf(
			'<form class="svm-form" method="post" action="%s"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$upload ? ' enctype="multipart/form-data"' : ''
		);

		echo '<input type="hidden" name="action" value="' . esc_attr( SVM_Router::ACTION ) . '" />';
		echo '<input type="hidden" name="svm_action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="svm_return" value="' . esc_url( SVM_App::page_url() ) . '" />';

		foreach ( $hidden as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}

		wp_nonce_field( 'svm_' . $action, '_svm_nonce' );
	}

	/**
	 * Schließt ein Formular.
	 *
	 * @param string $submit  Beschriftung der Schaltfläche.
	 * @param string $variant primary|secondary|danger.
	 * @param string $confirm Rückfrage vor dem Absenden.
	 * @return void
	 */
	public static function form_close( $submit = '', $variant = 'primary', $confirm = '' ) {
		if ( '' !== $submit ) {
			printf(
				'<p class="svm-form-actions"><button type="submit" class="svm-btn svm-btn-%s"%s>%s</button></p>',
				esc_attr( $variant ),
				'' !== $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '',
				esc_html( $submit )
			);
		}

		echo '</form>';
	}

	/**
	 * Kompaktes Formular für eine einzelne Schaltfläche.
	 *
	 * @param string $action  Aktionsschlüssel.
	 * @param array  $hidden  Versteckte Felder.
	 * @param string $label   Beschriftung.
	 * @param string $variant Stil.
	 * @param string $confirm Rückfrage.
	 * @return void
	 */
	public static function action_button( $action, array $hidden, $label, $variant = 'secondary', $confirm = '' ) {
		echo '<span class="svm-inline-form">';
		self::form_open( $action, $hidden );
		printf(
			'<button type="submit" class="svm-btn svm-btn-%s svm-btn-small"%s>%s</button>',
			esc_attr( $variant ),
			'' !== $confirm ? ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '',
			esc_html( $label )
		);
		echo '</form></span>';
	}

	/**
	 * Ein Formularfeld mit Beschriftung.
	 *
	 * @param string $label Beschriftung.
	 * @param string $html  Eingabefeld.
	 * @param string $help  Hilfetext.
	 * @param string $for   ID des Feldes.
	 * @return void
	 */
	public static function field( $label, $html, $help = '', $for = '' ) {
		echo '<div class="svm-field">';

		if ( '' !== $label ) {
			printf(
				'<label class="svm-label"%s>%s</label>',
				'' !== $for ? ' for="' . esc_attr( $for ) . '"' : '',
				esc_html( $label )
			);
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( '' !== $help ) {
			echo '<p class="svm-help">' . esc_html( $help ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Öffnet eine Feldgruppe (zweispaltig auf großen Bildschirmen).
	 *
	 * @return void
	 */
	public static function grid_open() {
		echo '<div class="svm-grid">';
	}

	/**
	 * Schließt eine Feldgruppe.
	 *
	 * @return void
	 */
	public static function grid_close() {
		echo '</div>';
	}

	/**
	 * Eingabefeld.
	 *
	 * @param string $name  Feldname.
	 * @param mixed  $value Wert.
	 * @param array  $args  type, placeholder, required, step, min, max, id, class.
	 * @return string
	 */
	public static function input( $name, $value, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'        => 'text',
				'placeholder' => '',
				'required'    => false,
				'disabled'    => false,
				'step'        => '',
				'min'         => '',
				'max'         => '',
				'id'          => '',
				'class'       => '',
			)
		);

		$id = '' !== $args['id'] ? $args['id'] : 'svm-' . sanitize_key( $name );

		$html = '<input class="svm-input ' . esc_attr( $args['class'] ) . '"';
		$html .= ' type="' . esc_attr( $args['type'] ) . '"';
		$html .= ' name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"';
		$html .= ' value="' . esc_attr( (string) $value ) . '"';

		if ( '' !== $args['placeholder'] ) {
			$html .= ' placeholder="' . esc_attr( $args['placeholder'] ) . '"';
		}

		foreach ( array( 'step', 'min', 'max' ) as $attribute ) {
			if ( '' !== $args[ $attribute ] ) {
				$html .= ' ' . $attribute . '="' . esc_attr( $args[ $attribute ] ) . '"';
			}
		}

		$html .= $args['required'] ? ' required' : '';
		$html .= $args['disabled'] ? ' disabled' : '';

		return $html . ' />';
	}

	/**
	 * Mehrzeiliges Eingabefeld.
	 *
	 * @param string $name  Feldname.
	 * @param mixed  $value Wert.
	 * @param array  $args  rows.
	 * @return string
	 */
	public static function textarea( $name, $value, array $args = array() ) {
		$args = wp_parse_args( $args, array( 'rows' => 4, 'placeholder' => '' ) );

		return '<textarea class="svm-input" name="' . esc_attr( $name ) . '"' .
			' id="svm-' . esc_attr( sanitize_key( $name ) ) . '"' .
			' rows="' . (int) $args['rows'] . '"' .
			( '' !== $args['placeholder'] ? ' placeholder="' . esc_attr( $args['placeholder'] ) . '"' : '' ) .
			'>' . esc_textarea( (string) $value ) . '</textarea>';
	}

	/**
	 * Auswahlfeld.
	 *
	 * @param string $name    Feldname.
	 * @param mixed  $value   Wert.
	 * @param array  $choices Auswahl.
	 * @param array  $args    multiple, size, required.
	 * @return string
	 */
	public static function select( $name, $value, array $choices, array $args = array() ) {
		$args = wp_parse_args( $args, array( 'multiple' => false, 'size' => 0, 'required' => false ) );

		$field    = $args['multiple'] ? $name . '[]' : $name;
		$selected = $args['multiple'] ? array_map( 'strval', (array) $value ) : array( (string) $value );

		$html = '<select class="svm-input" name="' . esc_attr( $field ) . '"';
		$html .= ' id="svm-' . esc_attr( sanitize_key( $name ) ) . '"';
		$html .= $args['multiple'] ? ' multiple' : '';
		$html .= $args['size'] > 0 ? ' size="' . (int) $args['size'] . '"' : '';
		$html .= $args['required'] ? ' required' : '';
		$html .= '>';

		foreach ( $choices as $key => $label ) {
			$html .= '<option value="' . esc_attr( (string) $key ) . '"' .
				( in_array( (string) $key, $selected, true ) ? ' selected' : '' ) . '>' .
				esc_html( $label ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * Kontrollkästchen.
	 *
	 * @param string $name    Feldname.
	 * @param bool   $checked Angehakt.
	 * @param string $label   Beschriftung.
	 * @return string
	 */
	public static function checkbox( $name, $checked, $label = '' ) {
		return '<label class="svm-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' .
			( $checked ? ' checked' : '' ) . ' /> <span>' . esc_html( $label ) . '</span></label>';
	}

	/**
	 * Suchleiste als GET-Formular.
	 *
	 * @param array $fields Liste aus HTML-Feldern.
	 * @param array $hidden Versteckte Parameter.
	 * @return void
	 */
	public static function filter_bar( array $fields, array $hidden = array() ) {
		echo '<form class="svm-filters" method="get" action="' . esc_url( SVM_App::page_url() ) . '">';

		foreach ( $hidden as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}

		foreach ( $fields as $field ) {
			echo '<span class="svm-filter">' . $field . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '<button type="submit" class="svm-btn svm-btn-secondary">' . esc_html__( 'Anzeigen', 'svm' ) . '</button>';
		echo '</form>';
	}

	/* ---------------------------------------------------------------------
	 * Felder aus der Konfiguration
	 * ------------------------------------------------------------------ */

	/**
	 * Eingabefeld zu einer Felddefinition.
	 *
	 * @param array $def   Felddefinition.
	 * @param mixed $value Wert.
	 * @return string
	 */
	public static function field_input( array $def, $value ) {
		$name = 'svm_field[' . (int) $def['id'] . ']';
		$args = array( 'required' => ! empty( $def['is_required'] ) );

		switch ( $def['field_type'] ) {
			case 'textarea':
				return self::textarea( $name, $value );

			case 'boolean':
				return self::checkbox( $name, ! empty( $value ), __( 'Ja', 'svm' ) );

			case 'select':
				return self::select(
					$name,
					$value,
					array( '' => __( '— bitte wählen —', 'svm' ) ) + SVM_Fields::option_map( (int) $def['id'] ),
					$args
				);

			case 'multiselect':
				$choices  = SVM_Fields::option_map( (int) $def['id'] );
				$selected = json_decode( (string) $value, true );

				if ( ! is_array( $selected ) ) {
					$selected = '' !== (string) $value ? array( $value ) : array();
				}

				return self::select(
					$name,
					$selected,
					$choices,
					array( 'multiple' => true, 'size' => min( 6, max( 3, count( $choices ) ) ) )
				);

			case 'date':
				$args['type'] = 'date';
				return self::input( $name, $value, $args );

			case 'number':
				$args['type'] = 'number';
				$args['step'] = 'any';
				$args['min']  = $def['min_value'];
				$args['max']  = $def['max_value'];
				return self::input( $name, $value, $args );

			case 'amount':
				$args['type'] = 'number';
				$args['step'] = '0.01';
				return self::input( $name, $value, $args );

			case 'email':
				$args['type'] = 'email';
				return self::input( $name, $value, $args );

			case 'phone':
				$args['type'] = 'tel';
				return self::input( $name, $value, $args );

			case 'url':
				$args['type'] = 'url';
				return self::input( $name, $value, $args );

			case 'member_ref':
				$choices = array( 0 => __( '— keines —', 'svm' ) );

				foreach ( SVM_Members::query( array( 'limit' => 500 ) ) as $member ) {
					$choices[ (int) $member['id'] ] = SVM_Members::display_name( (int) $member['id'] );
				}

				return self::select( $name, $value, $choices );

			case 'file':
				return self::input( $name, $value, array( 'type' => 'number' ) );

			case 'heading':
			case 'computed':
				return '';

			default:
				return self::input( $name, $value, $args );
		}
	}

	/**
	 * Zeigt einen gespeicherten Feldwert an.
	 *
	 * @param array $def   Felddefinition.
	 * @param mixed $value Wert.
	 * @return string
	 */
	public static function field_display( array $def, $value ) {
		if ( ! empty( $def['is_sensitive'] ) && 'iban' === $def['field_type'] ) {
			$masked = SVM_IBAN::mask( (string) $value );
			return '' !== $masked ? esc_html( $masked ) : '—';
		}

		$display = SVM_Fields::format_value( $def, $value );

		return '' !== $display ? esc_html( $display ) : '—';
	}

	/**
	 * Rendert alle sichtbaren Felder einer Entität, gruppiert nach Abschnitt.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param bool   $readonly    Nur anzeigen.
	 * @return int Anzahl bearbeitbarer Felder.
	 */
	public static function entity_fields( $entity_type, $entity_id, $readonly = false ) {
		$defs     = SVM_Fields::viewable_defs( $entity_type );
		$values   = $entity_id ? SVM_Fields::values( $entity_type, $entity_id ) : array();
		$section  = null;
		$open     = false;
		$editable = 0;

		foreach ( $defs as $def ) {
			if ( $def['section'] !== $section ) {
				if ( $open ) {
					self::grid_close();
				}

				$section = $def['section'];

				if ( '' !== $section ) {
					echo '<h4 class="svm-section-title">' . esc_html( $section ) . '</h4>';
				}

				self::grid_open();
				$open = true;
			}

			if ( 'heading' === $def['field_type'] ) {
				continue;
			}

			$value = isset( $values[ (int) $def['id'] ] ) ? $values[ (int) $def['id'] ] : $def['default_value'];
			$rules = isset( $def['_rules'] ) ? $def['_rules'] : SVM_Fields::user_field_rules( $def );
			$locked = $readonly || empty( $rules['can_edit'] );

			if ( 'computed' === $def['field_type'] ) {
				$html = '<p class="svm-readonly">' . esc_html( SVM_Fields::computed_value( $def, $entity_type, $entity_id ) ) . '</p>';
			} elseif ( $locked ) {
				$html = '<p class="svm-readonly">' . self::field_display( $def, $value ) . '</p>';
			} else {
				$html = self::field_input( $def, $value );
				$editable++;
			}

			$help = (string) $def['help_text'];

			if ( ! $locked && ! empty( $rules['requires_approval'] ) ) {
				$help = trim( $help . ' ' . __( 'Diese Änderung wird erst nach Freigabe wirksam.', 'svm' ) );
			}

			self::field(
				$def['label'] . ( $def['is_required'] ? ' *' : '' ),
				$html,
				$help,
				'svm-svm_field_' . (int) $def['id']
			);
		}

		if ( $open ) {
			self::grid_close();
		}

		return $editable;
	}

	/* ---------------------------------------------------------------------
	 * Bedingungseditor
	 * ------------------------------------------------------------------ */

	/**
	 * Editor für Regelbedingungen.
	 *
	 * @param array|null $rule     Regel.
	 * @param int        $min_rows Mindestanzahl Zeilen.
	 * @return void
	 */
	public static function conditions( $rule = null, $min_rows = 3 ) {
		$conditions = $rule ? SVM_Rules::conditions( (int) $rule['id'] ) : array();
		$rows       = max( $min_rows, count( $conditions ) + 1 );

		$subjects  = SVM_Rule_Engine::subjects();
		$operators = SVM_Rule_Engine::operators();

		$fields = array( 0 => __( '— Feld wählen —', 'svm' ) );

		foreach ( SVM_Fields::defs( 'member' ) as $def ) {
			if ( 'heading' !== $def['field_type'] ) {
				$fields[ (int) $def['id'] ] = $def['label'];
			}
		}

		$units    = SVM_Units::options();
		$statuses = SVM_Statuses::options();

		echo '<div class="svm-conditions">';

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

			echo '<div class="svm-condition-row">';

			echo '<div class="svm-condition-cell">';
			echo '<span class="svm-condition-label">' . esc_html__( 'Worauf', 'svm' ) . '</span>';
			echo self::select( $prefix . '[subject]', $condition['subject'], array( '' => __( '— keine Bedingung —', 'svm' ) ) + $subjects ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';

			echo '<div class="svm-condition-cell">';
			echo '<span class="svm-condition-label">' . esc_html__( 'Feld (nur bei „Feldwert“)', 'svm' ) . '</span>';
			echo self::select( $prefix . '[field_id]', $condition['field_id'], $fields ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';

			echo '<div class="svm-condition-cell">';
			echo '<span class="svm-condition-label">' . esc_html__( 'Vergleich', 'svm' ) . '</span>';
			echo self::select( $prefix . '[operator]', $condition['operator'], $operators ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';

			echo '<div class="svm-condition-cell">';
			echo '<span class="svm-condition-label">' . esc_html__( 'Wert', 'svm' ) . '</span>';
			echo '<div class="svm-condition-values">';
			echo self::input( $prefix . '[compare_value]', $condition['compare_value'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo self::input( $prefix . '[compare_value_2]', $condition['compare_value_2'], array( 'placeholder' => __( 'bis', 'svm' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';
			echo '</div>';

			echo '<div class="svm-condition-cell">';
			echo '<span class="svm-condition-label">' . esc_html__( 'Stichtag beim Alter', 'svm' ) . '</span>';
			echo self::select( $prefix . '[reference_date]', $condition['reference_date'], SVM_Rule_Engine::reference_dates() ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';

			echo '</div>';
		}

		echo '</div>';

		// Nachschlagehilfe für IDs.
		$hints = array();

		if ( ! empty( $units ) ) {
			$parts = array();
			foreach ( $units as $id => $label ) {
				$parts[] = trim( str_replace( '—', '', $label ) ) . ' = ' . $id;
			}
			$hints[] = __( 'Sparten:', 'svm' ) . ' ' . implode( ' · ', $parts );
		}

		if ( ! empty( $statuses ) ) {
			$parts = array();
			foreach ( $statuses as $id => $label ) {
				$parts[] = $label . ' = ' . $id;
			}
			$hints[] = __( 'Status:', 'svm' ) . ' ' . implode( ' · ', $parts );
		}

		if ( ! empty( $hints ) ) {
			echo '<details class="svm-details"><summary>' . esc_html__( 'Welche Nummer hat welche Sparte?', 'svm' ) . '</summary>';
			foreach ( $hints as $hint ) {
				echo '<p class="svm-help">' . esc_html( $hint ) . '</p>';
			}
			echo '</details>';
		}
	}
}
