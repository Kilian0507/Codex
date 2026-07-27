<?php
/**
 * Nachrichtenansichten.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mitteilungen an alle, an einzelne Sparten oder an Rollen.
 */
class SVM_View_Messages {

	/**
	 * Übersicht.
	 *
	 * @return void
	 */
	public static function listing() {
		$tab = SVM_App::arg( 'tab', 'list' );

		$actions = array();

		if ( SVM_Permissions::current_user_can( 'messages_manage' ) ) {
			$actions[] = array(
				'label'   => __( 'Neue Nachricht', 'svm' ),
				'url'     => SVM_App::url( 'message', array( 'id' => 0 ) ),
				'primary' => true,
			);
		}

		SVM_UI::page_head(
			__( 'Nachrichten', 'svm' ),
			__( 'Mitteilungen erscheinen im Bereich „Meine Daten“ der Mitglieder.', 'svm' ),
			$actions
		);

		if ( SVM_Permissions::current_user_can( 'messages_manage' ) ) {
			SVM_UI::tabs(
				array(
					'list'       => array( 'label' => __( 'Nachrichten', 'svm' ), 'url' => SVM_App::url( 'messages' ) ),
					'categories' => array( 'label' => __( 'Kategorien', 'svm' ), 'url' => SVM_App::url( 'messages', array( 'tab' => 'categories' ) ) ),
				),
				$tab
			);
		}

		if ( 'categories' === $tab ) {
			self::categories();
			return;
		}

		$categories = SVM_Messages::category_options();
		$types      = SVM_Messages::target_types();
		$rows       = array();

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

			$title = SVM_Permissions::current_user_can( 'messages_manage' )
				? '<a class="svm-strong" href="' . esc_url( SVM_App::url( 'message', array( 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['title'] ) . '</a>'
				: '<span class="svm-strong">' . esc_html( $item['title'] ) . '</span>';

			$rows[] = array(
				$title,
				esc_html( isset( $categories[ (int) $item['category_id'] ] ) ? $categories[ (int) $item['category_id'] ] : '—' ),
				esc_html( empty( $targets ) ? __( 'alle Mitglieder', 'svm' ) : implode( ', ', $targets ) ),
				'published' === $item['status']
					? SVM_UI::badge( __( 'veröffentlicht', 'svm' ), 'ok' )
					: SVM_UI::badge( __( 'Entwurf', 'svm' ), 'neutral' ),
				esc_html( number_format_i18n( SVM_Messages::read_count( (int) $item['id'] ) ) ),
			);
		}

		SVM_UI::table(
			array( __( 'Titel', 'svm' ), __( 'Kategorie', 'svm' ), __( 'Für wen', 'svm' ), __( 'Status', 'svm' ), __( 'Gelesen', 'svm' ) ),
			$rows,
			__( 'Noch keine Nachricht verfasst.', 'svm' )
		);
	}

	/**
	 * Nachricht bearbeiten.
	 *
	 * @return void
	 */
	public static function edit() {
		$message_id = SVM_App::id();
		$message    = $message_id ? SVM_Messages::get( $message_id ) : null;

		SVM_UI::page_head(
			$message ? __( 'Nachricht bearbeiten', 'svm' ) : __( 'Neue Nachricht', 'svm' ),
			__( 'Wählen Sie, wer die Nachricht sehen soll.', 'svm' ),
			array(
				array(
					'label' => __( '← Alle Nachrichten', 'svm' ),
					'url'   => SVM_App::url( 'messages' ),
				),
			)
		);

		SVM_UI::card_open();
		SVM_UI::form_open( 'save_message', array( 'id' => $message_id ) );

		SVM_UI::field( __( 'Titel', 'svm' ), SVM_UI::input( 'title', $message ? $message['title'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Text', 'svm' ), SVM_UI::textarea( 'body', $message ? $message['body'] : '', array( 'rows' => 8 ) ) );

		SVM_UI::grid_open();
		SVM_UI::field( __( 'Kategorie', 'svm' ), SVM_UI::select( 'category_id', $message ? $message['category_id'] : 0, SVM_Messages::category_options() ) );
		SVM_UI::field(
			__( 'Status', 'svm' ),
			SVM_UI::select(
				'status',
				$message ? $message['status'] : 'draft',
				array( 'draft' => __( 'Entwurf', 'svm' ), 'published' => __( 'veröffentlicht', 'svm' ) )
			)
		);
		SVM_UI::field( __( 'Sichtbar ab', 'svm' ), SVM_UI::input( 'visible_from', $message ? $message['visible_from'] : '', array( 'type' => 'date' ) ) );
		SVM_UI::field( __( 'Sichtbar bis', 'svm' ), SVM_UI::input( 'visible_to', $message ? $message['visible_to'] : '', array( 'type' => 'date' ) ) );
		SVM_UI::grid_close();

		SVM_UI::field( '', SVM_UI::checkbox( 'is_important', $message ? $message['is_important'] : 0, __( 'Wichtig — wird oben angezeigt', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'requires_ack', $message ? $message['requires_ack'] : 0, __( 'Lesebestätigung anfordern', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'send_email', $message ? $message['send_email'] : 0, __( 'Zusätzlich per E-Mail versenden', 'svm' ) ) );

		echo '<h4 class="svm-section-title">' . esc_html__( 'Wer soll die Nachricht sehen?', 'svm' ) . '</h4>';
		echo '<p class="svm-help">' . esc_html__( 'Ohne Eintrag sehen alle Mitglieder die Nachricht.', 'svm' ) . '</p>';

		self::targets( $message_id );

		SVM_UI::form_close( __( 'Nachricht speichern', 'svm' ) );

		if ( $message ) {
			echo '<div class="svm-button-row">';
			SVM_UI::action_button( 'delete_message', array( 'id' => $message_id ), __( 'Nachricht löschen', 'svm' ), 'danger' );
			echo '</div>';
		}

		SVM_UI::card_close();
	}

	/**
	 * Zielgruppenauswahl.
	 *
	 * @param int $message_id Nachrichten-ID.
	 * @return void
	 */
	private static function targets( $message_id ) {
		$targets = $message_id ? SVM_Messages::targets( $message_id ) : array();
		$rows    = max( 3, count( $targets ) + 1 );

		$types = array( '' => __( '— keine Einschränkung —', 'svm' ) ) + SVM_Messages::target_types();

		$ids = array( 0 => __( '— alle —', 'svm' ) );

		foreach ( SVM_Units::options() as $id => $label ) {
			$ids[ $id ] = __( 'Sparte:', 'svm' ) . ' ' . trim( str_replace( '—', '', $label ) );
		}

		foreach ( SVM_Roles::all() as $role ) {
			$ids[ (int) $role['id'] ] = __( 'Rolle:', 'svm' ) . ' ' . $role['label'];
		}

		echo '<div class="svm-conditions">';

		for ( $i = 0; $i < $rows; $i++ ) {
			$target = isset( $targets[ $i ] ) ? $targets[ $i ] : array( 'target_type' => '', 'target_id' => 0 );
			$prefix = 'target[' . $i . ']';

			echo '<div class="svm-condition-row">';

			echo '<div class="svm-condition-cell">';
			echo '<span class="svm-condition-label">' . esc_html__( 'Art', 'svm' ) . '</span>';
			echo SVM_UI::select( $prefix . '[target_type]', $target['target_type'], $types ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';

			echo '<div class="svm-condition-cell">';
			echo '<span class="svm-condition-label">' . esc_html__( 'Sparte oder Rolle', 'svm' ) . '</span>';
			echo SVM_UI::select( $prefix . '[target_id]', $target['target_id'], $ids ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Kategorien.
	 *
	 * @return void
	 */
	private static function categories() {
		$category_id = SVM_App::id();
		$category    = $category_id ? SVM_DB::get( 'message_categories', $category_id ) : null;

		$rows = array();

		foreach ( SVM_Messages::categories() as $item ) {
			$rows[] = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'messages', array( 'tab' => 'categories', 'id' => (int) $item['id'] ) ) ) . '">' .
					esc_html( $item['label'] ) . '</a>',
				$item['is_important'] ? SVM_UI::badge( __( 'wichtig', 'svm' ), 'warn' ) : '—',
				$item['send_email'] ? SVM_UI::badge( __( 'per E-Mail', 'svm' ), 'ok' ) : '—',
				SVM_View_Members::button_cell(
					'delete_message_category',
					array( 'id' => (int) $item['id'] ),
					__( 'Löschen', 'svm' ),
					__( 'Kategorie wirklich löschen? Vorhandene Nachrichten bleiben bestehen und stehen danach ohne Kategorie da.', 'svm' )
				),
			);
		}

		SVM_UI::table(
			array( __( 'Kategorie', 'svm' ), __( 'Kennzeichnung', 'svm' ), __( 'Versand', 'svm' ), '' ),
			$rows,
			__( 'Noch keine Kategorie angelegt.', 'svm' )
		);

		SVM_UI::card_open( $category ? __( 'Kategorie bearbeiten', 'svm' ) : __( 'Neue Kategorie', 'svm' ) );
		SVM_UI::form_open( 'save_message_category', array( 'id' => $category_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Bezeichnung', 'svm' ), SVM_UI::input( 'label', $category ? $category['label'] : '', array( 'required' => true ) ) );
		SVM_UI::field( __( 'Farbe', 'svm' ), SVM_UI::input( 'color', $category ? $category['color'] : '#2b6cb0', array( 'type' => 'color' ) ) );
		SVM_UI::grid_close();
		SVM_UI::field( '', SVM_UI::checkbox( 'is_important', $category ? $category['is_important'] : 0, __( 'Standardmäßig als wichtig kennzeichnen', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'send_email', $category ? $category['send_email'] : 0, __( 'Standardmäßig per E-Mail versenden', 'svm' ) ) );
		SVM_UI::field( '', SVM_UI::checkbox( 'is_active', $category ? $category['is_active'] : 1, __( 'Kategorie verwenden', 'svm' ) ) );
		SVM_UI::form_close( __( 'Kategorie speichern', 'svm' ) );
		SVM_UI::card_close();
	}
}
