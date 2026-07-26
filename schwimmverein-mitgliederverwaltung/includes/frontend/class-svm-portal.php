<?php
/**
 * Mitgliederportal im Frontend.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Self-Service: eigene Stammdaten, Nachrichten, eigene Beiträge und Zahlungen.
 */
class SVM_Portal {

	/**
	 * Registriert Shortcode und Formularverarbeitung.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'svm_portal', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_svm_portal_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_svm_portal_read', array( __CLASS__, 'handle_read' ) );
	}

	/**
	 * Mitglied des aktuellen Benutzers.
	 *
	 * @return array|null
	 */
	private static function current_member() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		return SVM_Members::get_by_user( get_current_user_id() );
	}

	/**
	 * Gibt das Portal aus.
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'sections' => 'profile,messages,finance',
			),
			$atts,
			'svm_portal'
		);

		wp_enqueue_style( 'svm-portal' );

		if ( ! is_user_logged_in() ) {
			return '<div class="svm-portal"><p>' .
				esc_html__( 'Bitte melden Sie sich an, um Ihre Daten zu sehen.', 'svm' ) .
				' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Zur Anmeldung', 'svm' ) . '</a></p></div>';
		}

		$member = self::current_member();

		if ( ! $member ) {
			return '<div class="svm-portal"><p>' .
				esc_html__( 'Ihrem Benutzerkonto ist noch kein Mitgliedsdatensatz zugeordnet. Bitte wenden Sie sich an die Vereinsverwaltung.', 'svm' ) .
				'</p></div>';
		}

		$status = SVM_Statuses::get( (int) $member['status_id'] );

		if ( $status && ! $status['allows_login'] ) {
			return '<div class="svm-portal"><p>' .
				esc_html__( 'Für Ihren Mitgliedsstatus ist der Portalzugang nicht freigeschaltet.', 'svm' ) .
				'</p></div>';
		}

		$sections  = array_map( 'trim', explode( ',', $atts['sections'] ) );
		$member_id = (int) $member['id'];

		ob_start();

		echo '<div class="svm-portal">';

		self::notice();

		printf(
			'<h2>%s</h2><p class="svm-portal-meta">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: Name des Mitglieds. */
					__( 'Hallo %s', 'svm' ),
					SVM_Members::display_name( $member_id )
				)
			),
			esc_html(
				sprintf(
					/* translators: 1: Mitgliedsnummer, 2: Status. */
					__( 'Mitgliedsnummer %1$s · Status: %2$s', 'svm' ),
					$member['member_number'],
					$status ? $status['label'] : '—'
				)
			)
		);

		if ( in_array( 'messages', $sections, true ) ) {
			self::render_messages( $member_id );
		}

		if ( in_array( 'profile', $sections, true ) ) {
			self::render_profile( $member_id );
		}

		if ( in_array( 'finance', $sections, true ) ) {
			self::render_finance( $member_id );
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Hinweis nach dem Speichern.
	 *
	 * @return void
	 */
	private static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['svm_saved'] ) ) {
			echo '<div class="svm-notice svm-notice-success">' . esc_html__( 'Ihre Änderungen wurden gespeichert.', 'svm' ) . '</div>';
		}

		if ( isset( $_GET['svm_pending'] ) ) {
			echo '<div class="svm-notice svm-notice-info">' .
				esc_html__( 'Ihre Änderungen wurden weitergeleitet und werden nach Prüfung durch die Vereinsverwaltung übernommen.', 'svm' ) .
				'</div>';
		}

		if ( isset( $_GET['svm_error'] ) ) {
			echo '<div class="svm-notice svm-notice-error">' .
				esc_html( sanitize_text_field( wp_unslash( $_GET['svm_error'] ) ) ) . '</div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Nachrichten.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function render_messages( $member_id ) {
		$messages = SVM_Messages::for_member( $member_id, 20 );

		echo '<section class="svm-section"><h3>' . esc_html__( 'Nachrichten', 'svm' ) . '</h3>';

		if ( empty( $messages ) ) {
			echo '<p>' . esc_html__( 'Zurzeit liegen keine Nachrichten vor.', 'svm' ) . '</p></section>';
			return;
		}

		foreach ( $messages as $message ) {
			echo '<article class="svm-message' . ( $message['is_important'] ? ' svm-message-important' : '' ) . '">';
			echo '<h4>' . esc_html( $message['title'] ) . '</h4>';
			echo '<p class="svm-message-date">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $message['created_at'] ) ) ) . '</p>';
			echo '<div class="svm-message-body">' . wp_kses_post( wpautop( $message['body'] ) ) . '</div>';

			if ( $message['requires_ack'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="svm_portal_read" />';
				echo '<input type="hidden" name="message_id" value="' . esc_attr( (int) $message['id'] ) . '" />';
				echo '<input type="hidden" name="redirect_to" value="' . esc_url( get_permalink() ) . '" />';
				wp_nonce_field( 'svm_portal_read', '_svm_nonce' );
				echo '<button type="submit" class="svm-button-small">' . esc_html__( 'Kenntnis genommen', 'svm' ) . '</button>';
				echo '</form>';
			}

			echo '</article>';
		}

		echo '</section>';
	}

	/**
	 * Eigene Stammdaten.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function render_profile( $member_id ) {
		echo '<section class="svm-section"><h3>' . esc_html__( 'Meine Stammdaten', 'svm' ) . '</h3>';

		if ( ! SVM_Permissions::current_user_can( 'own_profile_edit' ) ) {
			echo '<p>' . esc_html__( 'Ihre Daten können nur von der Vereinsverwaltung geändert werden.', 'svm' ) . '</p>';
			self::render_readonly_profile( $member_id );
			echo '</section>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="svm-form">';
		echo '<input type="hidden" name="action" value="svm_portal_save" />';
		echo '<input type="hidden" name="redirect_to" value="' . esc_url( get_permalink() ) . '" />';
		wp_nonce_field( 'svm_portal_save', '_svm_nonce' );

		$values   = SVM_Fields::values( 'member', $member_id );
		$rendered = 0;

		foreach ( SVM_Fields::viewable_defs( 'member' ) as $def ) {
			if ( 'heading' === $def['field_type'] ) {
				echo '<h4>' . esc_html( $def['label'] ) . '</h4>';
				continue;
			}

			$rules = $def['_rules'];
			$value = isset( $values[ (int) $def['id'] ] ) ? $values[ (int) $def['id'] ] : '';

			echo '<div class="svm-field">';
			echo '<label for="svm-svm_field_' . esc_attr( (int) $def['id'] ) . '">' . esc_html( $def['label'] ) .
				( $def['is_required'] ? ' *' : '' ) . '</label>';

			if ( empty( $rules['can_edit'] ) ) {
				$display = SVM_Fields::format_value( $def, $value );

				if ( ! empty( $def['is_sensitive'] ) && 'iban' === $def['field_type'] ) {
					$display = SVM_IBAN::mask( (string) $value );
				}

				echo '<p class="svm-readonly">' . esc_html( '' !== $display ? $display : '—' ) . '</p>';
			} else {
				echo SVM_Form::field_input( $def, $value ); // phpcs:ignore WordPress.Security.EscapeOutput
				$rendered++;

				if ( ! empty( $rules['requires_approval'] ) ) {
					echo '<p class="svm-hint">' . esc_html__( 'Änderungen an diesem Feld werden erst nach Freigabe wirksam.', 'svm' ) . '</p>';
				}
			}

			if ( '' !== (string) $def['help_text'] ) {
				echo '<p class="svm-hint">' . esc_html( $def['help_text'] ) . '</p>';
			}

			echo '</div>';
		}

		if ( $rendered > 0 ) {
			echo '<p><button type="submit" class="svm-button">' . esc_html__( 'Änderungen speichern', 'svm' ) . '</button></p>';
		} else {
			echo '<p>' . esc_html__( 'Für Ihre Rolle sind derzeit keine Felder zur Bearbeitung freigegeben.', 'svm' ) . '</p>';
		}

		echo '</form></section>';
	}

	/**
	 * Stammdaten nur lesend.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function render_readonly_profile( $member_id ) {
		$values = SVM_Fields::values( 'member', $member_id );

		echo '<dl class="svm-definition-list">';

		foreach ( SVM_Fields::viewable_defs( 'member' ) as $def ) {
			if ( 'heading' === $def['field_type'] ) {
				continue;
			}

			$value   = isset( $values[ (int) $def['id'] ] ) ? $values[ (int) $def['id'] ] : '';
			$display = SVM_Fields::format_value( $def, $value );

			if ( ! empty( $def['is_sensitive'] ) && 'iban' === $def['field_type'] ) {
				$display = SVM_IBAN::mask( (string) $value );
			}

			echo '<dt>' . esc_html( $def['label'] ) . '</dt>';
			echo '<dd>' . esc_html( '' !== $display ? $display : '—' ) . '</dd>';
		}

		echo '</dl>';
	}

	/**
	 * Eigene Beiträge und Zahlungen.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function render_finance( $member_id ) {
		if ( ! SVM_Permissions::current_user_can( 'own_finance_view' ) ) {
			return;
		}

		$invoices = SVM_Invoices::query( array( 'member_id' => $member_id, 'limit' => 50, 'respect_scope' => false ) );
		$payments = SVM_Payments::query( array( 'member_id' => $member_id, 'limit' => 50, 'respect_scope' => false ) );
		$statuses = SVM_Invoices::statuses();
		$open     = SVM_Invoices::open_total( $member_id );

		echo '<section class="svm-section"><h3>' . esc_html__( 'Meine Beiträge', 'svm' ) . '</h3>';

		echo '<p class="svm-balance">' . esc_html(
			sprintf(
				/* translators: %s: Betrag. */
				__( 'Offener Betrag: %s €', 'svm' ),
				number_format_i18n( $open, 2 )
			)
		) . '</p>';

		$units = array();

		foreach ( SVM_Members::unit_ids( $member_id ) as $unit_id ) {
			$units[] = SVM_Units::name( $unit_id );
		}

		if ( ! empty( array_filter( $units ) ) ) {
			echo '<p>' . esc_html( __( 'Meine Sparten/Gruppen: ', 'svm' ) . implode( ', ', array_filter( $units ) ) ) . '</p>';
		}

		$mandate = SVM_Mandates::active_for_member( $member_id );

		if ( $mandate ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: Mandatsreferenz, 2: maskierte IBAN. */
					__( 'SEPA-Lastschrift aktiv — Mandat %1$s, Konto %2$s', 'svm' ),
					$mandate['mandate_ref'],
					SVM_IBAN::mask( $mandate['iban'] )
				)
			) . '</p>';
		}

		if ( ! empty( $invoices ) ) {
			echo '<table class="svm-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Beschreibung', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Fällig', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Betrag', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'svm' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $invoices as $invoice ) {
				echo '<tr>';
				echo '<td>' . esc_html( $invoice['description'] ) . '</td>';
				echo '<td>' . esc_html( $invoice['due_date'] ? date_i18n( get_option( 'date_format' ), strtotime( $invoice['due_date'] ) ) : '—' ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $invoice['amount'], 2 ) ) . ' €</td>';
				echo '<td>' . esc_html( isset( $statuses[ $invoice['status'] ] ) ? $statuses[ $invoice['status'] ] : $invoice['status'] ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( ! empty( $payments ) ) {
			echo '<h4>' . esc_html__( 'Meine Zahlungen', 'svm' ) . '</h4>';
			echo '<table class="svm-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Datum', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Betrag', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Gezahlt von', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Verwendungszweck', 'svm' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $payments as $payment ) {
				echo '<tr>';
				echo '<td>' . esc_html( $payment['paid_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $payment['paid_at'] ) ) : '' ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( (float) $payment['amount'], 2 ) ) . ' €</td>';
				echo '<td>' . esc_html( $payment['payer_name'] ) . '</td>';
				echo '<td>' . esc_html( $payment['reference'] ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</section>';
	}

	/**
	 * Verarbeitet das Speichern eigener Stammdaten.
	 *
	 * @return void
	 */
	public static function handle_save() {
		check_admin_referer( 'svm_portal_save', '_svm_nonce' );

		$member = self::current_member();

		if ( ! $member || ! SVM_Permissions::current_user_can( 'own_profile_edit' ) ) {
			wp_die( esc_html__( 'Für diese Aktion fehlt die Berechtigung.', 'svm' ), 403 );
		}

		$member_id = (int) $member['id'];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$input = isset( $_POST['svm_field'] ) && is_array( $_POST['svm_field'] ) ? wp_unslash( $_POST['svm_field'] ) : array();

		$values = SVM_Form::collect_field_values( 'member', $member_id, $input );

		$redirect = self::redirect_target();

		if ( ! empty( $values['errors'] ) ) {
			wp_safe_redirect( add_query_arg( 'svm_error', rawurlencode( implode( ' ', $values['errors'] ) ), $redirect ) );
			exit;
		}

		if ( ! empty( $values['saved'] ) ) {
			SVM_Fields::save_values( 'member', $member_id, $values['saved'] );
		}

		foreach ( $values['pending'] as $field_id => $value ) {
			SVM_Change_Requests::create( $member_id, $field_id, $value );
		}

		$flag = empty( $values['pending'] ) ? 'svm_saved' : 'svm_pending';

		wp_safe_redirect( add_query_arg( $flag, '1', $redirect ) );
		exit;
	}

	/**
	 * Speichert eine Lesebestätigung.
	 *
	 * @return void
	 */
	public static function handle_read() {
		check_admin_referer( 'svm_portal_read', '_svm_nonce' );

		$member = self::current_member();

		if ( $member ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;

			if ( $message_id ) {
				SVM_Messages::mark_read( $message_id, (int) $member['id'] );
			}
		}

		wp_safe_redirect( self::redirect_target() );
		exit;
	}

	/**
	 * Zieladresse nach dem Absenden.
	 *
	 * @return string
	 */
	private static function redirect_target() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$target = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		return $target ? $target : home_url( '/' );
	}
}
