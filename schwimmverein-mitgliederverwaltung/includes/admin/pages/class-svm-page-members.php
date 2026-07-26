<?php
/**
 * Mitgliederseite.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liste, Bearbeitung und Freigabe von Änderungsanträgen.
 */
class SVM_Page_Members {

	/**
	 * Gibt die Seite aus.
	 *
	 * @return void
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view      = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
		$member_id = isset( $_GET['member_id'] ) ? absint( $_GET['member_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap svm-wrap">';

		SVM_Admin_Menu::notices();

		switch ( $view ) {
			case 'edit':
				self::render_edit( $member_id );
				break;

			case 'requests':
				self::render_requests();
				break;

			default:
				self::render_list();
				break;
		}

		echo '</div>';
	}

	/**
	 * Mitgliederliste mit Filtern.
	 *
	 * @return void
	 */
	private static function render_list() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$unit_id   = isset( $_GET['unit_id'] ) ? absint( $_GET['unit_id'] ) : 0;
		$status_id = isset( $_GET['status_id'] ) ? absint( $_GET['status_id'] ) : 0;
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 25;

		$args = array(
			'search'    => $search,
			'unit_id'   => $unit_id,
			'status_id' => $status_id,
			'limit'     => $per_page,
			'offset'    => ( $paged - 1 ) * $per_page,
		);

		$members = SVM_Members::query( $args );
		$total   = SVM_Members::count( $args );

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Mitglieder', 'svm' ) . '</h1>';

		if ( SVM_Permissions::current_user_can( 'members_create' ) ) {
			echo ' <a href="' . esc_url( SVM_Admin_Menu::url( 'svm-members', array( 'view' => 'edit', 'member_id' => 0 ) ) ) .
				'" class="page-title-action">' . esc_html__( 'Neues Mitglied', 'svm' ) . '</a>';
		}

		echo '<hr class="wp-header-end" />';

		// Filterleiste.
		echo '<form method="get" class="svm-filters">';
		echo '<input type="hidden" name="page" value="svm-members" />';
		echo '<p class="search-box">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name oder Nummer', 'svm' ) . '" /> ';
		echo SVM_Form::select( 'unit_id', $unit_id, array( 0 => __( 'Alle Sparten/Gruppen', 'svm' ) ) + SVM_Units::options() ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' ';
		echo SVM_Form::select( 'status_id', $status_id, array( 0 => __( 'Alle Status', 'svm' ) ) + SVM_Statuses::options() ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo ' <button type="submit" class="button">' . esc_html__( 'Filtern', 'svm' ) . '</button>';
		echo '</p></form>';

		$list_fields = array();

		foreach ( SVM_Fields::viewable_defs( 'member' ) as $def ) {
			if ( ! empty( $def['show_in_list'] ) ) {
				$list_fields[] = $def;
			}
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Nummer', 'svm' ) . '</th>';

		foreach ( $list_fields as $def ) {
			echo '<th>' . esc_html( $def['label'] ) . '</th>';
		}

		echo '<th>' . esc_html__( 'Status', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Sparten/Gruppen', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Offen', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $members ) ) {
			echo '<tr><td colspan="' . ( count( $list_fields ) + 4 ) . '">' .
				esc_html__( 'Keine Mitglieder gefunden.', 'svm' ) . '</td></tr>';
		}

		foreach ( $members as $member ) {
			$id     = (int) $member['id'];
			$status = SVM_Statuses::get( (int) $member['status_id'] );
			$units  = array();

			foreach ( SVM_Members::unit_ids( $id ) as $unit_id_item ) {
				$units[] = SVM_Units::name( $unit_id_item );
			}

			$edit_url = SVM_Admin_Menu::url( 'svm-members', array( 'view' => 'edit', 'member_id' => $id ) );

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $member['member_number'] ) . '</a></strong><br />' .
				esc_html( SVM_Members::display_name( $id ) ) . '</td>';

			foreach ( $list_fields as $def ) {
				$value = SVM_Fields::get_value_by_id( 'member', $id, (int) $def['id'] );
				echo '<td>' . esc_html( SVM_Fields::format_value( $def, $value ) ) . '</td>';
			}

			echo '<td>' . esc_html( $status ? $status['label'] : '—' ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', array_filter( $units ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( SVM_Invoices::open_total( $id ), 2 ) ) . ' €</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		self::pagination( $total, $per_page, $paged );
	}

	/**
	 * Seitennavigation.
	 *
	 * @param int $total    Gesamtzahl.
	 * @param int $per_page Einträge je Seite.
	 * @param int $paged    Aktuelle Seite.
	 * @return void
	 */
	private static function pagination( $total, $per_page, $paged ) {
		$pages = (int) ceil( $total / $per_page );

		if ( $pages <= 1 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %d: Anzahl. */
				_n( '%d Eintrag', '%d Einträge', $total, 'svm' ),
				$total
			)
		) . '</span> ';

		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			)
		);

		echo '</div></div>';
	}

	/**
	 * Bearbeitungsformular.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function render_edit( $member_id ) {
		$member = $member_id ? SVM_Members::get( $member_id ) : null;

		if ( $member_id && ! $member ) {
			echo '<h1>' . esc_html__( 'Mitglied nicht gefunden', 'svm' ) . '</h1>';
			return;
		}

		if ( $member_id && ! SVM_Permissions::can_access_member( $member_id ) ) {
			echo '<h1>' . esc_html__( 'Kein Zugriff', 'svm' ) . '</h1>';
			echo '<p>' . esc_html__( 'Dieses Mitglied liegt außerhalb Ihres Zuständigkeitsbereichs.', 'svm' ) . '</p>';
			return;
		}

		echo '<h1>' . esc_html( $member_id ? SVM_Members::display_name( $member_id ) : __( 'Neues Mitglied', 'svm' ) ) . '</h1>';
		echo '<p><a href="' . esc_url( SVM_Admin_Menu::url( 'svm-members' ) ) . '">← ' . esc_html__( 'Zurück zur Liste', 'svm' ) . '</a></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields(
			'save_member',
			array(
				'member_id' => $member_id,
				'svm_page'  => 'svm-members',
			)
		);

		echo '<h2>' . esc_html__( 'Grunddaten', 'svm' ) . '</h2>';
		SVM_Form::open_table();

		SVM_Form::row(
			__( 'Mitgliedsnummer', 'svm' ),
			$member_id
				? '<strong>' . esc_html( $member['member_number'] ) . '</strong>'
				: SVM_Form::input( 'member_number', '', array( 'placeholder' => __( 'automatisch', 'svm' ) ) ),
			$member_id ? '' : __( 'Leer lassen für automatische Vergabe aus dem Nummernkreis.', 'svm' )
		);

		SVM_Form::row(
			__( 'Status', 'svm' ),
			SVM_Form::select( 'status_id', $member ? $member['status_id'] : 0, SVM_Statuses::options() )
		);

		SVM_Form::row(
			__( 'Zahlart', 'svm' ),
			SVM_Form::select(
				'payment_method_id',
				$member ? $member['payment_method_id'] : 0,
				array( 0 => __( '— keine —', 'svm' ) ) + SVM_Payment_Methods::options()
			)
		);

		SVM_Form::row(
			__( 'Eintrittsdatum', 'svm' ),
			SVM_Form::input( 'joined_at', $member ? $member['joined_at'] : gmdate( 'Y-m-d' ), array( 'type' => 'date' ) )
		);

		SVM_Form::row(
			__( 'Austrittsdatum', 'svm' ),
			SVM_Form::input( 'left_at', $member ? $member['left_at'] : '', array( 'type' => 'date' ) )
		);

		SVM_Form::row(
			__( 'Familienverbund', 'svm' ),
			SVM_Form::input( 'family_group_id', $member ? $member['family_group_id'] : 0, array( 'type' => 'number', 'class' => 'small-text' ) ) .
			' ' . esc_html__( 'Position:', 'svm' ) . ' ' .
			SVM_Form::input( 'family_position', $member ? $member['family_position'] : 0, array( 'type' => 'number', 'class' => 'small-text' ) ),
			__( 'Gleiche Nummer = gleicher Verbund. Die Position steuert Regeln wie „ab dem 3. Kind“.', 'svm' )
		);

		$users = array( 0 => __( '— kein Benutzerkonto —', 'svm' ) );

		foreach ( get_users( array( 'number' => 200, 'fields' => array( 'ID', 'display_name', 'user_email' ) ) ) as $user ) {
			$users[ (int) $user->ID ] = $user->display_name . ' (' . $user->user_email . ')';
		}

		SVM_Form::row(
			__( 'Portalzugang', 'svm' ),
			SVM_Form::select( 'wp_user_id', $member ? $member['wp_user_id'] : 0, $users ),
			__( 'Verknüpftes WordPress-Konto für das Mitgliederportal.', 'svm' )
		);

		SVM_Form::row(
			__( 'Sparten/Gruppen', 'svm' ),
			SVM_Form::select(
				'unit_ids',
				$member_id ? SVM_Members::unit_ids( $member_id ) : array(),
				SVM_Units::options( true ),
				array( 'multiple' => true, 'size' => 6 )
			),
			__( 'Mehrfachauswahl mit Strg/Cmd.', 'svm' )
		);

		SVM_Form::close_table();

		echo '<h2>' . esc_html__( 'Stammdaten', 'svm' ) . '</h2>';
		SVM_Form::render_entity_fields( 'member', $member_id );

		submit_button( $member_id ? __( 'Änderungen speichern', 'svm' ) : __( 'Mitglied anlegen', 'svm' ) );
		echo '</form>';

		if ( $member_id ) {
			self::render_finance_box( $member_id );
			self::render_mandates_box( $member_id );
			self::render_actions_box( $member_id );
		}
	}

	/**
	 * Finanzübersicht eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function render_finance_box( $member_id ) {
		if ( ! SVM_Permissions::current_user_can( 'invoices_view' ) ) {
			return;
		}

		$invoices = SVM_Invoices::query( array( 'member_id' => $member_id, 'limit' => 25 ) );
		$statuses = SVM_Invoices::statuses();

		echo '<h2>' . esc_html__( 'Beiträge und Zahlungen', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Offener Betrag:', 'svm' ) . ' <strong>' .
			esc_html( number_format_i18n( SVM_Invoices::open_total( $member_id ), 2 ) ) . ' €</strong> · ' .
			esc_html__( 'Guthaben:', 'svm' ) . ' <strong>' .
			esc_html( number_format_i18n( SVM_Payments::credit_balance( $member_id ), 2 ) ) . ' €</strong></p>';

		if ( empty( $invoices ) ) {
			echo '<p>' . esc_html__( 'Noch keine Forderungen vorhanden.', 'svm' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Beschreibung', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Fällig', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Betrag', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Bezahlt', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Berechnung', 'svm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $invoices as $invoice ) {
			$log = json_decode( (string) $invoice['calculation_log'], true );

			echo '<tr>';
			echo '<td>' . esc_html( $invoice['description'] ) . '</td>';
			echo '<td>' . esc_html( $invoice['due_date'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $invoice['amount'], 2 ) ) . ' €</td>';
			echo '<td>' . esc_html( number_format_i18n( (float) $invoice['amount_paid'], 2 ) ) . ' €</td>';
			echo '<td>' . esc_html( isset( $statuses[ $invoice['status'] ] ) ? $statuses[ $invoice['status'] ] : $invoice['status'] ) . '</td>';
			echo '<td>';

			if ( is_array( $log ) && ! empty( $log ) ) {
				echo '<details><summary>' . esc_html__( 'anzeigen', 'svm' ) . '</summary><ul class="svm-log">';
				foreach ( $log as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul></details>';
			} else {
				echo '—';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Mandate eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function render_mandates_box( $member_id ) {
		if ( ! SVM_Permissions::current_user_can( 'mandates_manage' ) ) {
			return;
		}

		$mandates = SVM_Mandates::for_member( $member_id );
		$statuses = SVM_Mandates::statuses();

		echo '<h2>' . esc_html__( 'SEPA-Mandate', 'svm' ) . '</h2>';

		if ( ! empty( $mandates ) ) {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Referenz', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'IBAN', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Kontoinhaber', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Unterschrieben', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Sequenz', 'svm' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'svm' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';

			foreach ( $mandates as $mandate ) {
				echo '<tr>';
				echo '<td>' . esc_html( $mandate['mandate_ref'] ) . '</td>';
				echo '<td>' . esc_html(
					SVM_Permissions::current_user_can( 'bank_data_view' )
						? SVM_IBAN::format( $mandate['iban'] )
						: SVM_IBAN::mask( $mandate['iban'] )
				) . '</td>';
				echo '<td>' . esc_html( $mandate['account_holder'] ) . '</td>';
				echo '<td>' . esc_html( $mandate['signed_at'] ) . '</td>';
				echo '<td>' . esc_html( $mandate['sequence_type'] ) . '</td>';
				echo '<td>' . esc_html( isset( $statuses[ $mandate['status'] ] ) ? $statuses[ $mandate['status'] ] : $mandate['status'] ) . '</td>';
				echo '<td>';

				if ( 'active' === $mandate['status'] ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
					SVM_Form::action_fields(
						'revoke_mandate',
						array(
							'id'        => (int) $mandate['id'],
							'svm_page'  => 'svm-members',
							'member_id' => $member_id,
						)
					);
					echo '<button type="submit" class="button button-small">' . esc_html__( 'Widerrufen', 'svm' ) . '</button>';
					echo '</form>';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Neues Mandat erfassen', 'svm' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		SVM_Form::action_fields(
			'save_mandate',
			array(
				'svm_page' => 'svm-members',
				'view'     => 'edit',
			)
		);
		echo '<input type="hidden" name="member_id" value="' . esc_attr( $member_id ) . '" />';

		SVM_Form::open_table();
		SVM_Form::row( __( 'IBAN', 'svm' ), SVM_Form::input( 'iban', '' ) );
		SVM_Form::row( __( 'BIC (optional)', 'svm' ), SVM_Form::input( 'bic', '', array( 'class' => 'regular-text' ) ) );
		SVM_Form::row(
			__( 'Kontoinhaber', 'svm' ),
			SVM_Form::input( 'account_holder', '', array( 'placeholder' => SVM_Members::display_name( $member_id ) ) ),
			__( 'Nur ausfüllen, wenn abweichend vom Mitglied.', 'svm' )
		);
		SVM_Form::row( __( 'Unterschrieben am', 'svm' ), SVM_Form::input( 'signed_at', gmdate( 'Y-m-d' ), array( 'type' => 'date' ) ) );
		SVM_Form::row( __( 'Sequenztyp', 'svm' ), SVM_Form::select( 'sequence_type', 'FRST', SVM_Mandates::sequence_types() ) );
		SVM_Form::close_table();

		submit_button( __( 'Mandat speichern', 'svm' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Statuswechsel, DSGVO und Löschung.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function render_actions_box( $member_id ) {
		echo '<h2>' . esc_html__( 'Weitere Aktionen', 'svm' ) . '</h2>';
		echo '<div class="svm-action-row">';

		if ( SVM_Permissions::current_user_can( 'members_status' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form">';
			SVM_Form::action_fields(
				'change_status',
				array(
					'member_id' => $member_id,
					'svm_page'  => 'svm-members',
				)
			);
			echo '<strong>' . esc_html__( 'Status wechseln:', 'svm' ) . '</strong> ';
			echo SVM_Form::select( 'status_id', 0, SVM_Statuses::options() ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo ' ' . SVM_Form::input( 'reason', '', array( 'placeholder' => __( 'Begründung', 'svm' ), 'class' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo ' <button type="submit" class="button">' . esc_html__( 'Wechseln', 'svm' ) . '</button>';
			echo '</form>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form">';
		SVM_Form::action_fields(
			'gdpr_export',
			array(
				'member_id' => $member_id,
				'svm_page'  => 'svm-members',
			)
		);
		echo '<button type="submit" class="button">' . esc_html__( 'DSGVO-Auskunft herunterladen', 'svm' ) . '</button>';
		echo '</form>';

		if ( SVM_Permissions::current_user_can( 'members_delete' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form" ' .
				'onsubmit="return confirm(\'' . esc_attr__( 'Mitglied wirklich löschen? Die Finanzhistorie bleibt erhalten.', 'svm' ) . '\');">';
			SVM_Form::action_fields(
				'delete_member',
				array(
					'member_id' => $member_id,
					'svm_page'  => 'svm-members',
				)
			);
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Mitglied löschen', 'svm' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * Offene Änderungsanträge.
	 *
	 * @return void
	 */
	private static function render_requests() {
		SVM_Permissions::require_permission( 'change_requests' );

		$requests = SVM_Change_Requests::pending();

		echo '<h1>' . esc_html__( 'Offene Änderungsanträge', 'svm' ) . '</h1>';
		echo '<p>' . esc_html__( 'Änderungen an freigabepflichtigen Feldern werden erst nach Bestätigung wirksam.', 'svm' ) . '</p>';

		if ( empty( $requests ) ) {
			echo '<p>' . esc_html__( 'Es liegen keine Anträge vor.', 'svm' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Mitglied', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Feld', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Bisher', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Neu', 'svm' ) . '</th>';
		echo '<th>' . esc_html__( 'Beantragt', 'svm' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $requests as $request ) {
			$def = SVM_Fields::get_def( (int) $request['field_id'] );

			echo '<tr>';
			echo '<td>' . esc_html( SVM_Members::display_name( (int) $request['member_id'] ) ) . '</td>';
			echo '<td>' . esc_html( $def ? $def['label'] : '#' . $request['field_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $request['old_value'] ) . '</td>';
			echo '<td><strong>' . esc_html( (string) $request['new_value'] ) . '</strong></td>';
			echo '<td>' . esc_html( $request['requested_at'] ) . '</td>';
			echo '<td><div class="svm-action-row">';

			foreach ( array( 'approve_change' => __( 'Übernehmen', 'svm' ), 'reject_change' => __( 'Ablehnen', 'svm' ) ) as $action => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="svm-inline-form">';
				SVM_Form::action_fields(
					$action,
					array(
						'id'       => (int) $request['id'],
						'svm_page' => 'svm-members',
						'view'     => 'requests',
					)
				);
				echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
				echo '</form>';
			}

			echo '</div></td></tr>';
		}

		echo '</tbody></table>';
	}
}
