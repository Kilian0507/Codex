<?php
/**
 * Generische Formularausgabe.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ein Renderer für alle Formulare — Voraussetzung dafür, dass Felder frei anlegbar sind.
 */
class SVM_Form {

	/**
	 * Öffnet eine Formulartabelle.
	 *
	 * @return void
	 */
	public static function open_table() {
		echo '<table class="form-table" role="presentation"><tbody>';
	}

	/**
	 * Schließt eine Formulartabelle.
	 *
	 * @return void
	 */
	public static function close_table() {
		echo '</tbody></table>';
	}

	/**
	 * Gibt eine Zeile mit Beschriftung aus.
	 *
	 * @param string $label   Beschriftung.
	 * @param string $content HTML des Eingabefeldes.
	 * @param string $help    Hilfetext.
	 * @param string $for     ID des Feldes.
	 * @return void
	 */
	public static function row( $label, $content, $help = '', $for = '' ) {
		echo '<tr><th scope="row">';

		if ( '' !== $for ) {
			echo '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>';
		} else {
			echo esc_html( $label );
		}

		echo '</th><td>';
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Textfeld.
	 *
	 * @param string $name  Feldname.
	 * @param string $value Wert.
	 * @param array  $args  type, class, placeholder, required, disabled, step, min, max.
	 * @return string
	 */
	public static function input( $name, $value, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'        => 'text',
				'class'       => 'regular-text',
				'placeholder' => '',
				'required'    => false,
				'disabled'    => false,
				'step'        => '',
				'min'         => '',
				'max'         => '',
				'id'          => '',
			)
		);

		$id   = '' !== $args['id'] ? $args['id'] : 'svm-' . sanitize_key( $name );
		$html = '<input type="' . esc_attr( $args['type'] ) . '" name="' . esc_attr( $name ) . '"';
		$html .= ' id="' . esc_attr( $id ) . '"';
		$html .= ' value="' . esc_attr( (string) $value ) . '"';
		$html .= ' class="' . esc_attr( $args['class'] ) . '"';

		if ( '' !== $args['placeholder'] ) {
			$html .= ' placeholder="' . esc_attr( $args['placeholder'] ) . '"';
		}

		foreach ( array( 'step', 'min', 'max' ) as $attribute ) {
			if ( '' !== $args[ $attribute ] ) {
				$html .= ' ' . $attribute . '="' . esc_attr( $args[ $attribute ] ) . '"';
			}
		}

		if ( $args['required'] ) {
			$html .= ' required';
		}

		if ( $args['disabled'] ) {
			$html .= ' disabled';
		}

		return $html . ' />';
	}

	/**
	 * Mehrzeiliges Textfeld.
	 *
	 * @param string $name  Feldname.
	 * @param string $value Wert.
	 * @param array  $args  rows, class, disabled.
	 * @return string
	 */
	public static function textarea( $name, $value, array $args = array() ) {
		$args = wp_parse_args( $args, array( 'rows' => 4, 'class' => 'large-text', 'disabled' => false ) );

		$html = '<textarea name="' . esc_attr( $name ) . '" id="svm-' . esc_attr( sanitize_key( $name ) ) . '"';
		$html .= ' rows="' . (int) $args['rows'] . '" class="' . esc_attr( $args['class'] ) . '"';
		$html .= $args['disabled'] ? ' disabled' : '';
		$html .= '>' . esc_textarea( (string) $value ) . '</textarea>';

		return $html;
	}

	/**
	 * Auswahlfeld.
	 *
	 * @param string $name    Feldname.
	 * @param mixed  $value   Aktueller Wert.
	 * @param array  $choices Auswahlmöglichkeiten.
	 * @param array  $args    multiple, disabled, class, size.
	 * @return string
	 */
	public static function select( $name, $value, array $choices, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'multiple' => false,
				'disabled' => false,
				'class'    => '',
				'size'     => 0,
			)
		);

		$field_name = $args['multiple'] ? $name . '[]' : $name;
		$selected   = $args['multiple'] ? array_map( 'strval', (array) $value ) : array( (string) $value );

		$html = '<select name="' . esc_attr( $field_name ) . '" id="svm-' . esc_attr( sanitize_key( $name ) ) . '"';
		$html .= '' !== $args['class'] ? ' class="' . esc_attr( $args['class'] ) . '"' : '';
		$html .= $args['multiple'] ? ' multiple' : '';
		$html .= $args['size'] > 0 ? ' size="' . (int) $args['size'] . '"' : '';
		$html .= $args['disabled'] ? ' disabled' : '';
		$html .= '>';

		foreach ( $choices as $key => $label ) {
			$html .= '<option value="' . esc_attr( (string) $key ) . '"';
			$html .= in_array( (string) $key, $selected, true ) ? ' selected' : '';
			$html .= '>' . esc_html( $label ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * Kontrollkästchen.
	 *
	 * @param string $name    Feldname.
	 * @param bool   $checked Angehakt.
	 * @param string $label   Beschriftung.
	 * @param array  $args    disabled.
	 * @return string
	 */
	public static function checkbox( $name, $checked, $label = '', array $args = array() ) {
		$args = wp_parse_args( $args, array( 'disabled' => false, 'value' => '1' ) );

		$html = '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $args['value'] ) . '"';
		$html .= $checked ? ' checked' : '';
		$html .= $args['disabled'] ? ' disabled' : '';
		$html .= ' /> ' . esc_html( $label ) . '</label>';

		return $html;
	}

	/**
	 * Versteckte Felder für Aktion und Nonce.
	 *
	 * @param string $action Aktionsschlüssel.
	 * @param array  $extra  Weitere versteckte Felder.
	 * @return void
	 */
	public static function action_fields( $action, array $extra = array() ) {
		echo '<input type="hidden" name="svm_action" value="' . esc_attr( $action ) . '" />';

		foreach ( $extra as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}

		wp_nonce_field( 'svm_' . $action, '_svm_nonce' );
	}

	/**
	 * Rendert das Eingabefeld zu einer Felddefinition.
	 *
	 * @param array $def      Felddefinition.
	 * @param mixed $value    Wert.
	 * @param bool  $disabled Nur lesend.
	 * @return string
	 */
	public static function field_input( array $def, $value, $disabled = false ) {
		$name = 'svm_field[' . (int) $def['id'] . ']';
		$type = SVM_Field_Types::get( $def['field_type'] );
		$args = array(
			'disabled' => $disabled,
			'required' => ! empty( $def['is_required'] ),
		);

		switch ( $def['field_type'] ) {
			case 'textarea':
				return self::textarea( $name, $value, array( 'disabled' => $disabled ) );

			case 'boolean':
				return self::checkbox( $name, ! empty( $value ), __( 'Ja', 'svm' ), array( 'disabled' => $disabled ) );

			case 'select':
				$choices = array( '' => __( '— bitte wählen —', 'svm' ) ) + SVM_Fields::option_map( (int) $def['id'] );
				return self::select( $name, $value, $choices, array( 'disabled' => $disabled ) );

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
					array( 'multiple' => true, 'disabled' => $disabled, 'size' => min( 6, max( 3, count( $choices ) ) ) )
				);

			case 'date':
				$args['type'] = 'date';
				return self::input( $name, $value, $args );

			case 'number':
				$args['type'] = 'number';
				$args['step'] = 'any';
				$args['min']  = $def['min_value'];
				$args['max']  = $def['max_value'];
				$args['class'] = 'small-text';
				return self::input( $name, $value, $args );

			case 'amount':
				$args['type']  = 'number';
				$args['step']  = '0.01';
				$args['class'] = 'small-text';
				return self::input( $name, $value, $args );

			case 'member_ref':
				$choices = array( 0 => __( '— keines —', 'svm' ) );
				foreach ( SVM_Members::query( array( 'limit' => 500 ) ) as $member ) {
					$choices[ (int) $member['id'] ] = SVM_Members::display_name( (int) $member['id'] ) . ' (' . $member['member_number'] . ')';
				}
				return self::select( $name, $value, $choices, array( 'disabled' => $disabled ) );

			case 'file':
				$html = self::input( $name, $value, array( 'type' => 'number', 'class' => 'small-text', 'disabled' => $disabled ) );
				$url  = $value ? wp_get_attachment_url( (int) $value ) : '';
				if ( $url ) {
					$html .= ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Datei ansehen', 'svm' ) . '</a>';
				}
				return $html;

			case 'heading':
				return '';

			case 'computed':
				return '<em>' . esc_html__( 'wird berechnet', 'svm' ) . '</em>';

			default:
				$args['type'] = isset( $type['input'] ) && in_array( $type['input'], array( 'email', 'tel', 'url' ), true )
					? $type['input']
					: 'text';
				return self::input( $name, $value, $args );
		}
	}

	/**
	 * Rendert alle sichtbaren Felder einer Entität, gruppiert nach Abschnitt.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param bool   $force_readonly Alles nur lesend anzeigen.
	 * @return void
	 */
	public static function render_entity_fields( $entity_type, $entity_id, $force_readonly = false ) {
		$defs    = SVM_Fields::viewable_defs( $entity_type );
		$values  = $entity_id ? SVM_Fields::values( $entity_type, $entity_id ) : array();
		$section = null;
		$open    = false;

		foreach ( $defs as $def ) {
			if ( $def['section'] !== $section ) {
				if ( $open ) {
					self::close_table();
				}

				$section = $def['section'];

				if ( '' !== $section ) {
					echo '<h3>' . esc_html( $section ) . '</h3>';
				}

				self::open_table();
				$open = true;
			}

			if ( 'heading' === $def['field_type'] ) {
				echo '<tr><th colspan="2"><h4 style="margin:0">' . esc_html( $def['label'] ) . '</h4></th></tr>';
				continue;
			}

			$value    = isset( $values[ (int) $def['id'] ] ) ? $values[ (int) $def['id'] ] : $def['default_value'];
			$rules    = isset( $def['_rules'] ) ? $def['_rules'] : SVM_Fields::user_field_rules( $def );
			$readonly = $force_readonly || empty( $rules['can_edit'] );

			if ( 'computed' === $def['field_type'] ) {
				$content = '<code>' . esc_html( self::computed_value( $def, $entity_type, $entity_id ) ) . '</code>';
			} elseif ( $readonly ) {
				$display = SVM_Fields::format_value( $def, $value );

				if ( ! empty( $def['is_sensitive'] ) && 'iban' === $def['field_type'] ) {
					$display = SVM_IBAN::mask( (string) $value );
				}

				$content = '' !== $display ? esc_html( $display ) : '<span class="description">—</span>';
			} else {
				$content = self::field_input( $def, $value );
			}

			$help = (string) $def['help_text'];

			if ( ! empty( $rules['requires_approval'] ) && ! $readonly ) {
				$help = trim( $help . ' ' . __( 'Änderungen an diesem Feld werden erst nach Freigabe wirksam.', 'svm' ) );
			}

			$label = $def['label'] . ( $def['is_required'] ? ' *' : '' );

			self::row( $label, $content, $help, 'svm-svm_field_' . (int) $def['id'] );
		}

		if ( $open ) {
			self::close_table();
		}
	}

	/**
	 * Wert eines berechneten Feldes.
	 *
	 * @param array  $def         Felddefinition.
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @return string
	 */
	public static function computed_value( array $def, $entity_type, $entity_id ) {
		if ( ! $entity_id || '' === (string) $def['formula'] ) {
			return '';
		}

		$vars = SVM_Fields::values_by_key( $entity_type, $entity_id );

		if ( 'member' === $entity_type ) {
			$age                    = SVM_Members::age( $entity_id );
			$vars['alter']          = null === $age ? 0 : $age;
			$vars['age']            = $vars['alter'];
			$vars['anzahl_sparten'] = count( SVM_Members::unit_ids( $entity_id ) );
		}

		$result = SVM_Formula::evaluate( $def['formula'], $vars );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return number_format_i18n( (float) $result, 2 );
	}

	/**
	 * Verarbeitet die abgeschickten Feldwerte.
	 *
	 * Felder mit Freigabepflicht landen im Änderungsantrag statt direkt im Datensatz.
	 *
	 * @param string $entity_type Entitätstyp.
	 * @param int    $entity_id   Datensatz-ID.
	 * @param array  $input       Rohdaten aus dem Formular.
	 * @return array saved, pending, errors
	 */
	public static function collect_field_values( $entity_type, $entity_id, array $input ) {
		$result = array(
			'saved'   => array(),
			'pending' => array(),
			'errors'  => array(),
		);

		foreach ( SVM_Fields::defs( $entity_type ) as $def ) {
			$field_id = (int) $def['id'];
			$storage  = SVM_Field_Types::storage( $def['field_type'] );

			if ( 'none' === $storage ) {
				continue;
			}

			$rules = SVM_Fields::user_field_rules( $def );

			if ( empty( $rules['can_edit'] ) ) {
				continue;
			}

			if ( 'boolean' === $def['field_type'] ) {
				$value = isset( $input[ $field_id ] ) ? 1 : 0;
			} elseif ( ! array_key_exists( $field_id, $input ) ) {
				continue;
			} else {
				$value = $input[ $field_id ];
			}

			if ( is_array( $value ) ) {
				$value = array_map( 'sanitize_text_field', wp_unslash( $value ) );
			} else {
				$value = 'textarea' === $def['field_type']
					? sanitize_textarea_field( wp_unslash( $value ) )
					: sanitize_text_field( wp_unslash( $value ) );
			}

			if ( 'iban' === $def['field_type'] ) {
				$value = SVM_IBAN::normalize( $value );
			}

			$check = SVM_Fields::validate( $def, $value );

			if ( is_wp_error( $check ) ) {
				$result['errors'][] = $check->get_error_message();
				continue;
			}

			if ( ! empty( $rules['requires_approval'] ) && $entity_id ) {
				$current = SVM_Fields::get_value_by_id( $entity_type, $entity_id, $field_id );
				$new     = is_array( $value ) ? wp_json_encode( array_values( $value ) ) : $value;

				if ( (string) $current !== (string) $new ) {
					$result['pending'][ $field_id ] = $value;
					continue;
				}
			}

			$result['saved'][ $field_id ] = $value;
		}

		return $result;
	}
}
