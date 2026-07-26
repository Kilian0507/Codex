<?php
/**
 * Nachrichtenseite.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nachrichten mit regelbasierten Zielgruppen und frei anlegbaren Kategorien.
 */
class SVM_Page_Messages {

	/**
	 * Gibt die Seite aus.
	 *
	 * @return void
	 */
	public static function render() {
		$tabs = array(
			'list'       => __( 'Nachrichten', 'svm' ),
			'categories' => __( 'Kategorien', 'svm' ),
		);

		$current = SVM_Admin_Menu::current_tab( 'list' );

		echo '<div class="wrap svm-wrap">';
		echo '<h1>' . esc_html__( 'Nachrichten', 'svm' ) . '</h1>';

		SVM_Admin_Menu::notices();
		SVM_Admin_Menu::tabs( 'svm-messages', $tabs, $current );

		if ( 'categories' === $current ) {
			self::render_categories();
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * Nachrichtenliste und Formular.
	 *
	 * @return void
	 */
	private static function render_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['message_id'] ) ? absint( $_GET['message_id'] ) : 0;
		$message = $edit_id ? SVM_Messages::get( $edit_id ) : null;

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Titel', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Kategorie', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Zielgruppe', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Sichtbar', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Gelesen', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		$categories = SVM_Messages::category_options();
		$types      = SVM_Messages::target_types();

		foreach ( SVM_Messages::all() as $item ) {
			$targets = array();

			foreach ( SVM_Messages::targets( (int) $item['id'] ) as $target ) {
				$label = isset( $types[ $target['target_type'] ] ) ? $types[ $target['target_type'] ] : $target['target_type'];

				if ( 'unit' === $target['target_type'] ) {
					$label = SVM_Units::name( (int) $target['target_id'] );
				} elseif ( 'role' === $target['target_type'] ) {
					$role  = SVM_Roles::get( (int) $target['target_id'] );
					$label = $role ? $role['label'] : $label;
				}

				$targets[] = $label;
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $item['title'] ) . '</strong></td>';
			echo '<td>' . esc_html( isset( $categories[ (int) $item['category_id'] ] ) ? $categories[ (int) $item['category_id'] ] : '—' ) . '</td>';
			echo '<td>' . esc_html( empty( $targets ) ? __( 'alle', 'svm' ) : implode( ', ', $targets ) ) . '</td>';
			echo '<td>' . esc_html( trim( $item['visible_from'] . ' – ' . $item['visible_to'], ' –' ) ) . '</td>';
			echo '<td>' . esc_html( 'published' === $item['status'] ? __( 'veröffentlicht', 'svm' ) : __( 'Entwurf', 'svm' ) ) . '</td>';
			echo '<td>' . esc_html( SVM_Messages::read_count( (int) $item['id'] ) ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-messages', array( 'message_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( ! SVM_Permissions::current_user_can( 'messages_manage' ) ) {
			return;
		}

		echo '<h2>' . esc_html( $message ? __( 'Nachricht bearbeiten', 'svm' ) : __( 'Neue Nachricht', 'svm' ) ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_message', array( 'id' => $edit_id, 'svm_page' => 'svm-messages', 'svm_tab' => 'list' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Titel', 'svm' ), SVM_Form::input( 'title', $message ? $message['title'] : '', array( 'class' => 'large-text', 'required' => true ) ) );
		SVM_Form::row( __( 'Kategorie', 'svm' ), SVM_Form::select( 'category_id', $message ? $message['category_id'] : 0, $categories ) );
		SVM_Form::row( __( 'Inhalt', 'svm' ), SVM_Form::textarea( 'body', $message ? $message['body'] : '', array( 'rows' => 8 ) ) );
		SVM_Form::row(
			__( 'Sichtbar von / bis', 'svm' ),
			SVM_Form::input( 'visible_from', $message ? $message['visible_from'] : '', array( 'type' => 'date' ) ) . ' ' .
			SVM_Form::input( 'visible_to', $message ? $message['visible_to'] : '', array( 'type' => 'date' ) )
		);
		SVM_Form::row( __( 'Wichtig', 'svm' ), SVM_Form::checkbox( 'is_important', $message ? $message['is_important'] : 0, __( 'Oben anzeigen', 'svm' ) ) );
		SVM_Form::row( __( 'Lesebestätigung', 'svm' ), SVM_Form::checkbox( 'requires_ack', $message ? $message['requires_ack'] : 0, __( 'Kenntnisnahme erforderlich', 'svm' ) ) );
		SVM_Form::row(
			__( 'Per E-Mail versenden', 'svm' ),
			SVM_Form::checkbox( 'send_email', $message ? $message['send_email'] : 0, __( 'Beim Veröffentlichen im Hintergrund versenden', 'svm' ) )
		);
		SVM_Form::row(
			__( 'Status', 'svm' ),
			SVM_Form::select(
				'status',
				$message ? $message['status'] : 'draft',
				array( 'draft' => __( 'Entwurf', 'svm' ), 'published' => __( 'veröffentlicht', 'svm' ) )
			)
		);
		SVM_Form::close_table();

		self::render_targets( $edit_id );

		submit_button( __( 'Nachricht speichern', 'svm' ) );
		echo '</form>';

		if ( $message ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			SVM_Form::action_fields( 'delete_message', array( 'id' => $edit_id, 'svm_page' => 'svm-messages', 'svm_tab' => 'list' ) );
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Nachricht löschen', 'svm' ) . '</button>';
			echo '</form>';
		}
	}

	/**
	 * Zielgruppenauswahl.
	 *
	 * @param int $message_id Nachrichten-ID.
	 * @return void
	 */
	private static function render_targets( $message_id ) {
		$targets = $message_id ? SVM_Messages::targets( $message_id ) : array();
		$rows    = max( 3, count( $targets ) + 1 );

		$options = array( '' => __( '— keine —', 'svm' ) ) + SVM_Messages::target_types();

		$ids = array( 0 => __( '— alle —', 'svm' ) );

		foreach ( SVM_Units::options() as $id => $label ) {
			$ids[ 'unit-' . $id ] = __( 'Sparte:', 'svm' ) . ' ' . $label;
		}

		foreach ( SVM_Roles::all() as $role ) {
			$ids[ 'role-' . (int) $role['id'] ] = __( 'Rolle:', 'svm' ) . ' ' . $role['label'];
		}

		foreach ( SVM_Rules::for_owner( 'message_target' ) as $rule ) {
			$ids[ 'rule-' . (int) $rule['id'] ] = __( 'Regel:', 'svm' ) . ' ' . $rule['label'];
		}

		echo '<h3>' . esc_html__( 'Wer soll die Nachricht sehen?', 'svm' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Ohne Eintrag sehen alle Mitglieder die Nachricht.', 'svm' ) . '</p>';

		echo '<table class="widefat"><thead><tr>';
		echo '<th style="width:30%">' . esc_html__( 'Art', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Ziel-ID', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		for ( $i = 0; $i < $rows; $i++ ) {
			$target = isset( $targets[ $i ] ) ? $targets[ $i ] : array( 'target_type' => '', 'target_id' => 0 );
			$prefix = 'target[' . $i . ']';

			echo '<tr>';
			echo '<td>' . SVM_Form::select( $prefix . '[target_type]', $target['target_type'], $options ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . SVM_Form::input( // phpcs:ignore WordPress.Security.EscapeOutput
				$prefix . '[target_id]',
				$target['target_id'],
				array( 'type' => 'number', 'class' => 'small-text' )
			) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		SVM_Condition_Editor::render( null, 0 );
	}

	/**
	 * Kategorien.
	 *
	 * @return void
	 */
	private static function render_categories() {
		SVM_Permissions::require_permission( 'messages_manage' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id  = isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0;
		$category = $edit_id ? SVM_DB::get( 'message_categories', $edit_id ) : null;

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Bezeichnung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Wichtig', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'E-Mail', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( SVM_Messages::categories() as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item['label'] ) . '</td>';
			echo '<td>' . esc_html( $item['is_important'] ? __( 'ja', 'svm' ) : __( 'nein', 'svm' ) ) . '</td>';
			echo '<td>' . esc_html( $item['send_email'] ? __( 'ja', 'svm' ) : __( 'nein', 'svm' ) ) . '</td>';
			echo '<td><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-messages', array( 'tab' => 'categories', 'category_id' => (int) $item['id'] ) ) ) . '">' .
				esc_html__( 'Bearbeiten', 'svm' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html( $category ? __( 'Kategorie bearbeiten', 'svm' ) : __( 'Neue Kategorie', 'svm' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields( 'save_message_category', array( 'id' => $edit_id, 'svm_page' => 'svm-messages', 'svm_tab' => 'categories' ) );

		SVM_Form::open_table();
		SVM_Form::row( __( 'Bezeichnung', 'svm' ), SVM_Form::input( 'label', $category ? $category['label'] : '', array( 'required' => true ) ) );
		SVM_Form::row( __( 'Farbe', 'svm' ), SVM_Form::input( 'color', $category ? $category['color'] : '', array( 'type' => 'color' ) ) );
		SVM_Form::row( __( 'Wichtig', 'svm' ), SVM_Form::checkbox( 'is_important', $category ? $category['is_important'] : 0, __( 'Standardmäßig als wichtig kennzeichnen', 'svm' ) ) );
		SVM_Form::row( __( 'E-Mail', 'svm' ), SVM_Form::checkbox( 'send_email', $category ? $category['send_email'] : 0, __( 'Standardmäßig per E-Mail versenden', 'svm' ) ) );
		SVM_Form::row( __( 'Aktiv', 'svm' ), SVM_Form::checkbox( 'is_active', $category ? $category['is_active'] : 1, __( 'Kategorie verwenden', 'svm' ) ) );
		SVM_Form::close_table();

		submit_button( __( 'Kategorie speichern', 'svm' ) );
		echo '</form>';
	}
}
