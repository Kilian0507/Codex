<?php
/**
 * Mitgliederansichten.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liste, Detailansicht mit Reitern und Freigabe von Änderungswünschen.
 */
class SVM_View_Members {

	/**
	 * Mitgliederliste.
	 *
	 * @return void
	 */
	public static function listing() {
		$search    = SVM_App::arg( 's' );
		$unit_id   = SVM_App::id( 'unit_id' );
		$status_id = SVM_App::id( 'status_id' );
		$page      = max( 1, SVM_App::id( 'p' ) );
		$per_page  = 25;

		$actions = array();

		if ( SVM_Permissions::current_user_can( 'members_create' ) ) {
			$actions[] = array(
				'label'   => __( 'Neues Mitglied', 'svm' ),
				'url'     => SVM_App::url( 'member_new' ),
				'primary' => true,
			);
		}

		SVM_UI::page_head(
			__( 'Mitglieder', 'svm' ),
			__( 'Suchen, bearbeiten und neue Mitglieder aufnehmen.', 'svm' ),
			$actions
		);

		SVM_App::subnav(
			array(
				'members'  => __( 'Alle Mitglieder', 'svm' ),
				'requests' => __( 'Änderungswünsche', 'svm' ),
			),
			'members'
		);

		SVM_UI::filter_bar(
			array(
				SVM_UI::input( 's', $search, array( 'placeholder' => __( 'Name oder Nummer', 'svm' ) ) ),
				SVM_UI::select( 'unit_id', $unit_id, array( 0 => __( 'Alle Sparten', 'svm' ) ) + SVM_Units::options() ),
				SVM_UI::select( 'status_id', $status_id, array( 0 => __( 'Alle Status', 'svm' ) ) + SVM_Statuses::options() ),
			),
			array( 'svm_view' => 'members' )
		);

		$args = array(
			'search'    => $search,
			'unit_id'   => $unit_id,
			'status_id' => $status_id,
			'limit'     => $per_page,
			'offset'    => ( $page - 1 ) * $per_page,
		);

		$members = SVM_Members::query( $args );
		$total   = SVM_Members::count( $args );

		$extra = array();

		foreach ( SVM_Fields::viewable_defs( 'member' ) as $def ) {
			if ( ! empty( $def['show_in_list'] ) ) {
				$extra[] = $def;
			}
		}

		$columns = array( __( 'Mitglied', 'svm' ) );

		foreach ( $extra as $def ) {
			$columns[] = $def['label'];
		}

		$columns[] = __( 'Sparten', 'svm' );
		$columns[] = __( 'Status', 'svm' );
		$columns[] = __( 'Offen', 'svm' );

		$rows = array();

		foreach ( $members as $member ) {
			$id     = (int) $member['id'];
			$status = SVM_Statuses::get( (int) $member['status_id'] );
			$units  = array();

			foreach ( SVM_Members::unit_ids( $id ) as $unit ) {
				$units[] = SVM_Units::name( $unit );
			}

			$row = array(
				'<a class="svm-strong" href="' . esc_url( SVM_App::url( 'member', array( 'id' => $id ) ) ) . '">' .
					esc_html( SVM_Members::display_name( $id ) ) . '</a><br /><span class="svm-muted">' .
					esc_html( $member['member_number'] ) . '</span>',
			);

			foreach ( $extra as $def ) {
				$row[] = SVM_UI::field_display( $def, SVM_Fields::get_value_by_id( 'member', $id, (int) $def['id'] ) );
			}

			$open = SVM_Invoices::open_total( $id );

			$row[] = esc_html( implode( ', ', array_filter( $units ) ) );
			$row[] = $status ? SVM_UI::badge( $status['label'], $status['is_billable'] ? 'ok' : 'neutral' ) : '—';
			$row[] = $open > 0
				? '<span class="svm-strong">' . esc_html( SVM_UI::money( $open ) ) . '</span>'
				: '<span class="svm-muted">' . esc_html( SVM_UI::money( 0 ) ) . '</span>';

			$rows[] = $row;
		}

		SVM_UI::table( $columns, $rows, __( 'Keine Mitglieder gefunden. Passen Sie die Suche an oder legen Sie ein neues Mitglied an.', 'svm' ) );

		self::pagination( $total, $per_page, $page, array( 'svm_view' => 'members', 's' => $search, 'unit_id' => $unit_id, 'status_id' => $status_id ) );
	}

	/**
	 * Seitennavigation.
	 *
	 * @param int   $total    Gesamtzahl.
	 * @param int   $per_page Pro Seite.
	 * @param int   $current  Aktuelle Seite.
	 * @param array $args     Parameter.
	 * @return void
	 */
	private static function pagination( $total, $per_page, $current, array $args ) {
		$pages = (int) ceil( $total / $per_page );

		if ( $pages <= 1 ) {
			return;
		}

		echo '<nav class="svm-pagination">';

		for ( $i = 1; $i <= $pages; $i++ ) {
			printf(
				'<a class="svm-page%s" href="%s">%d</a>',
				$i === $current ? ' is-active' : '',
				esc_url( SVM_App::page_url( array_merge( $args, array( 'p' => $i ) ) ) ),
				$i
			);
		}

		echo '</nav>';
	}

	/**
	 * Formular für ein neues Mitglied.
	 *
	 * @return void
	 */
	public static function create() {
		SVM_UI::page_head(
			__( 'Neues Mitglied', 'svm' ),
			__( 'Pflichtfelder sind mit * gekennzeichnet. Sparten und Beiträge lassen sich danach ergänzen.', 'svm' )
		);

		SVM_UI::card_open();
		SVM_UI::form_open( 'save_member', array( 'member_id' => 0 ) );

		self::core_fields( null );
		SVM_UI::entity_fields( 'member', 0 );

		SVM_UI::form_close( __( 'Mitglied anlegen', 'svm' ) );
		SVM_UI::card_close();
	}

	/**
	 * Detailansicht mit Reitern.
	 *
	 * @return void
	 */
	public static function detail() {
		$member_id = SVM_App::id();
		$member    = $member_id ? SVM_Members::get( $member_id ) : null;

		if ( ! $member ) {
			SVM_UI::notice( __( 'Mitglied nicht gefunden.', 'svm' ), 'error' );
			return;
		}

		if ( ! SVM_Permissions::can_access_member( $member_id ) ) {
			SVM_UI::notice( __( 'Dieses Mitglied liegt außerhalb Ihres Zuständigkeitsbereichs.', 'svm' ), 'warning' );
			return;
		}

		$tab    = SVM_App::arg( 'tab', 'data' );
		$status = SVM_Statuses::get( (int) $member['status_id'] );

		SVM_UI::page_head(
			SVM_Members::display_name( $member_id ),
			sprintf(
				/* translators: 1: Mitgliedsnummer, 2: Status. */
				__( 'Nummer %1$s · Status: %2$s', 'svm' ),
				$member['member_number'],
				$status ? $status['label'] : '—'
			),
			array(
				array(
					'label' => __( '← Zur Liste', 'svm' ),
					'url'   => SVM_App::url( 'members' ),
				),
			)
		);

		$tabs = array(
			'data'   => __( 'Stammdaten', 'svm' ),
			'fees'   => __( 'Beiträge', 'svm' ),
			'bank'   => __( 'Bankverbindung', 'svm' ),
			'family' => __( 'Familie', 'svm' ),
		);

		$links = array();

		foreach ( $tabs as $key => $label ) {
			$links[ $key ] = array(
				'label' => $label,
				'url'   => SVM_App::url( 'member', array( 'id' => $member_id, 'tab' => $key ) ),
			);
		}

		SVM_UI::tabs( $links, $tab );

		switch ( $tab ) {
			case 'fees':
				self::tab_fees( $member );
				break;

			case 'bank':
				self::tab_bank( $member );
				break;

			case 'family':
				self::tab_family( $member );
				break;

			default:
				self::tab_data( $member );
				break;
		}
	}

	/**
	 * Kernfelder im Formular.
	 *
	 * @param array|null $member Mitglied.
	 * @return void
	 */
	private static function core_fields( $member ) {
		SVM_UI::grid_open();

		if ( $member ) {
			SVM_UI::field(
				__( 'Mitgliedsnummer', 'svm' ),
				'<p class="svm-readonly svm-strong">' . esc_html( $member['member_number'] ) . '</p>'
			);
		} else {
			SVM_UI::field(
				__( 'Mitgliedsnummer', 'svm' ),
				SVM_UI::input( 'member_number', '', array( 'placeholder' => __( 'wird automatisch vergeben', 'svm' ) ) ),
				__( 'Leer lassen, dann vergibt das System die nächste freie Nummer.', 'svm' )
			);
		}

		SVM_UI::field(
			__( 'Status', 'svm' ),
			SVM_UI::select( 'status_id', $member ? $member['status_id'] : 0, SVM_Statuses::options() )
		);

		SVM_UI::field(
			__( 'Zahlart', 'svm' ),
			SVM_UI::select(
				'payment_method_id',
				$member ? $member['payment_method_id'] : 0,
				array( 0 => __( '— noch offen —', 'svm' ) ) + SVM_Payment_Methods::options()
			)
		);

		SVM_UI::field(
			__( 'Eintritt', 'svm' ),
			SVM_UI::input( 'joined_at', $member ? $member['joined_at'] : gmdate( 'Y-m-d' ), array( 'type' => 'date' ) )
		);

		SVM_UI::field(
			__( 'Austritt', 'svm' ),
			SVM_UI::input( 'left_at', $member ? $member['left_at'] : '', array( 'type' => 'date' ) ),
			__( 'Nur ausfüllen, wenn das Mitglied den Verein verlässt.', 'svm' )
		);

		SVM_UI::field(
			__( 'Sparten', 'svm' ),
			SVM_UI::select(
				'unit_ids',
				$member ? SVM_Members::unit_ids( (int) $member['id'] ) : array(),
				SVM_Units::options( true ),
				array( 'multiple' => true, 'size' => 5 )
			),
			__( 'Mehrere möglich — mit gedrückter Strg- bzw. Cmd-Taste auswählen.', 'svm' )
		);

		if ( SVM_Permissions::current_user_can( 'roles_manage' ) ) {
			$users = array( 0 => __( '— kein Zugang —', 'svm' ) );

			foreach ( get_users( array( 'number' => 300, 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
				$users[ (int) $user->ID ] = $user->display_name;
			}

			SVM_UI::field(
				__( 'Zugang zur Verwaltung', 'svm' ),
				SVM_UI::select( 'wp_user_id', $member ? $member['wp_user_id'] : 0, $users ),
				__( 'Verknüpftes Benutzerkonto, damit sich das Mitglied anmelden kann.', 'svm' )
			);
		}

		SVM_UI::grid_close();
	}

	/**
	 * Reiter Stammdaten.
	 *
	 * @param array $member Mitglied.
	 * @return void
	 */
	private static function tab_data( array $member ) {
		$member_id = (int) $member['id'];

		SVM_UI::card_open();
		SVM_UI::form_open( 'save_member', array( 'member_id' => $member_id ) );

		self::core_fields( $member );
		SVM_UI::entity_fields( 'member', $member_id );

		SVM_UI::form_close( __( 'Änderungen speichern', 'svm' ) );
		SVM_UI::card_close();

		if ( SVM_Permissions::current_user_can( 'members_status' ) ) {
			SVM_UI::card_open( __( 'Status ändern', 'svm' ), __( 'Der Wechsel kann automatisch Forderungen stornieren oder Mandate deaktivieren.', 'svm' ) );
			SVM_UI::form_open( 'change_status', array( 'member_id' => $member_id ) );
			SVM_UI::grid_open();
			SVM_UI::field( __( 'Neuer Status', 'svm' ), SVM_UI::select( 'status_id', $member['status_id'], SVM_Statuses::options() ) );
			SVM_UI::field( __( 'Grund', 'svm' ), SVM_UI::input( 'reason', '', array( 'placeholder' => __( 'z. B. Kündigung zum Jahresende', 'svm' ) ) ) );
			SVM_UI::grid_close();
			SVM_UI::form_close( __( 'Status wechseln', 'svm' ), 'secondary' );
			SVM_UI::card_close();
		}

		SVM_UI::card_open( __( 'Weitere Aktionen', 'svm' ) );
		echo '<div class="svm-button-row">';

		SVM_UI::action_button(
			'gdpr_export',
			array( 'member_id' => $member_id ),
			__( 'Datenauskunft herunterladen', 'svm' )
		);

		if ( SVM_Permissions::current_user_can( 'members_delete' ) ) {
			SVM_UI::action_button(
				'delete_member',
				array( 'member_id' => $member_id ),
				__( 'Mitglied löschen', 'svm' ),
				'danger',
				__( 'Mitglied wirklich löschen? Die Zahlungshistorie bleibt für die Kassenprüfung erhalten.', 'svm' )
			);
		}

		echo '</div>';
		SVM_UI::card_close();
	}

	/**
	 * Reiter Beiträge.
	 *
	 * @param array $member Mitglied.
	 * @return void
	 */
	private static function tab_fees( array $member ) {
		$member_id = (int) $member['id'];

		// Wirksame Beiträge.
		$effective = SVM_Member_Fees::effective( $member_id );
		$rows      = array();

		$cycles = SVM_Fee_Types::cycles();

		foreach ( $effective as $entry ) {
			$cycle = $entry['fee_type']['cycle'];

			$rows[] = array(
				esc_html( $entry['fee_type']['label'] ),
				esc_html( SVM_UI::money( $entry['amount'] ) ),
				esc_html( isset( $cycles[ $cycle ] ) ? $cycles[ $cycle ] : $cycle ),
				'manual' === $entry['source']
					? SVM_UI::badge( __( 'manuell zugeordnet', 'svm' ), 'ok' )
					: SVM_UI::badge( __( 'aus Regel', 'svm' ), 'neutral' ),
			);
		}

		SVM_UI::card_open(
			__( 'Welche Beiträge zahlt dieses Mitglied?', 'svm' ),
			__( 'Ein Mitglied kann beliebig viele Beiträge zahlen — etwa je Sparte einen. Beiträge aus Regeln ergeben sich automatisch, zusätzliche lassen sich hier zuordnen.', 'svm' )
		);

		SVM_UI::table(
			array( __( 'Beitrag', 'svm' ), __( 'Betrag', 'svm' ), __( 'Turnus', 'svm' ), __( 'Herkunft', 'svm' ) ),
			$rows,
			__( 'Für dieses Mitglied greift derzeit kein Beitrag.', 'svm' )
		);

		SVM_UI::card_close();

		if ( SVM_Permissions::current_user_can( 'fees_manage' ) ) {
			self::fee_assignment( $member_id );
		}

		// Forderungen.
		if ( SVM_Permissions::current_user_can( 'invoices_view' ) ) {
			self::invoice_list( $member_id );
		}
	}

	/**
	 * Zuordnung zusätzlicher Beiträge.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function fee_assignment( $member_id ) {
		$assignments = SVM_Member_Fees::for_member( $member_id );
		$modes       = SVM_Member_Fees::modes();

		if ( ! empty( $assignments ) ) {
			$rows = array();

			foreach ( $assignments as $assignment ) {
				$fee_type = SVM_Fee_Types::get( (int) $assignment['fee_type_id'] );

				$rows[] = array(
					esc_html( $fee_type ? $fee_type['label'] : '—' ),
					esc_html( isset( $modes[ $assignment['mode'] ] ) ? $modes[ $assignment['mode'] ] : $assignment['mode'] ),
					null !== $assignment['amount_override']
						? esc_html( SVM_UI::money( $assignment['amount_override'] ) )
						: '<span class="svm-muted">' . esc_html__( 'Standardbetrag', 'svm' ) . '</span>',
					esc_html( $assignment['note'] ),
					self::button_cell(
						'remove_member_fee',
						array( 'member_id' => $member_id, 'assignment_id' => (int) $assignment['id'] ),
						__( 'Entfernen', 'svm' )
					),
				);
			}

			SVM_UI::card_open( __( 'Eigene Zuordnungen', 'svm' ) );
			SVM_UI::table(
				array( __( 'Beitrag', 'svm' ), __( 'Wirkung', 'svm' ), __( 'Betrag', 'svm' ), __( 'Notiz', 'svm' ), '' ),
				$rows
			);
			SVM_UI::card_close();
		}

		$fee_types = array( 0 => __( '— Beitrag wählen —', 'svm' ) );

		foreach ( SVM_Fee_Types::all( true ) as $fee_type ) {
			$fee_types[ (int) $fee_type['id'] ] = $fee_type['label'] . ' · ' . SVM_UI::money( $fee_type['amount'] );
		}

		SVM_UI::card_open(
			__( 'Weiteren Beitrag zuordnen', 'svm' ),
			__( 'Damit zahlt das Mitglied einen zusätzlichen Beitrag, auch wenn keine Regel darauf zutrifft. Umgekehrt lässt sich ein Beitrag ausnehmen.', 'svm' )
		);

		SVM_UI::form_open( 'assign_member_fee', array( 'member_id' => $member_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'Beitrag', 'svm' ), SVM_UI::select( 'fee_type_id', 0, $fee_types ) );
		SVM_UI::field( __( 'Wirkung', 'svm' ), SVM_UI::select( 'mode', 'include', $modes ) );
		SVM_UI::field(
			__( 'Abweichender Betrag', 'svm' ),
			SVM_UI::input( 'amount_override', '', array( 'type' => 'number', 'step' => '0.01', 'placeholder' => __( 'optional', 'svm' ) ) ),
			__( 'Leer lassen, um den Betrag der Beitragsart zu übernehmen.', 'svm' )
		);
		SVM_UI::field( __( 'Notiz', 'svm' ), SVM_UI::input( 'note', '', array( 'placeholder' => __( 'z. B. Vereinbarung vom 01.03.', 'svm' ) ) ) );
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Beitrag zuordnen', 'svm' ) );
		SVM_UI::card_close();
	}

	/**
	 * Forderungen eines Mitglieds.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	private static function invoice_list( $member_id ) {
		$invoices = SVM_Invoices::query( array( 'member_id' => $member_id, 'limit' => 50 ) );
		$statuses = SVM_Invoices::statuses();
		$rows     = array();

		foreach ( $invoices as $invoice ) {
			$log  = json_decode( (string) $invoice['calculation_log'], true );
			$why  = '—';

			if ( is_array( $log ) && ! empty( $log ) ) {
				$why = '<details class="svm-details"><summary>' . esc_html__( 'Warum dieser Betrag?', 'svm' ) . '</summary><ul>';

				foreach ( $log as $line ) {
					$why .= '<li>' . esc_html( $line ) . '</li>';
				}

				$why .= '</ul></details>';
			}

			$tone = 'paid' === $invoice['status'] ? 'ok' : ( 'cancelled' === $invoice['status'] ? 'neutral' : 'warn' );

			$rows[] = array(
				esc_html( $invoice['description'] ),
				esc_html( SVM_UI::date( $invoice['due_date'] ) ),
				esc_html( SVM_UI::money( $invoice['amount'] ) ),
				esc_html( SVM_UI::money( SVM_Invoices::remaining( $invoice ) ) ),
				SVM_UI::badge( isset( $statuses[ $invoice['status'] ] ) ? $statuses[ $invoice['status'] ] : $invoice['status'], $tone ),
				$why,
			);
		}

		SVM_UI::card_open( __( 'Forderungen', 'svm' ) );
		SVM_UI::table(
			array(
				__( 'Beschreibung', 'svm' ),
				__( 'Fällig', 'svm' ),
				__( 'Betrag', 'svm' ),
				__( 'Offen', 'svm' ),
				__( 'Status', 'svm' ),
				__( 'Berechnung', 'svm' ),
			),
			$rows,
			__( 'Für dieses Mitglied wurden noch keine Forderungen erzeugt.', 'svm' )
		);
		SVM_UI::card_close();

		if ( SVM_Permissions::current_user_can( 'invoices_edit' ) ) {
			SVM_UI::card_open( __( 'Einzelne Forderung anlegen', 'svm' ), __( 'Für Sonderfälle außerhalb des Beitragsregelwerks.', 'svm' ) );
			SVM_UI::form_open( 'create_invoice', array( 'member_id' => $member_id ) );
			SVM_UI::grid_open();
			SVM_UI::field( __( 'Beschreibung', 'svm' ), SVM_UI::input( 'description', '', array( 'required' => true ) ) );
			SVM_UI::field( __( 'Betrag', 'svm' ), SVM_UI::input( 'amount', '', array( 'type' => 'number', 'step' => '0.01', 'required' => true ) ) );
			SVM_UI::field( __( 'Fällig am', 'svm' ), SVM_UI::input( 'due_date', gmdate( 'Y-m-d' ), array( 'type' => 'date' ) ) );
			SVM_UI::grid_close();
			SVM_UI::form_close( __( 'Forderung anlegen', 'svm' ), 'secondary' );
			SVM_UI::card_close();
		}
	}

	/**
	 * Reiter Bankverbindung.
	 *
	 * @param array $member Mitglied.
	 * @return void
	 */
	private static function tab_bank( array $member ) {
		$member_id = (int) $member['id'];

		if ( ! SVM_Permissions::current_user_can( 'mandates_manage' ) ) {
			SVM_UI::notice( __( 'Bankdaten dürfen nur von der Kasse eingesehen werden.', 'svm' ), 'info' );
			return;
		}

		$mandates = SVM_Mandates::for_member( $member_id );
		$statuses = SVM_Mandates::statuses();
		$rows     = array();

		foreach ( $mandates as $mandate ) {
			$actions = 'active' === $mandate['status']
				? self::button_cell(
					'revoke_mandate',
					array( 'id' => (int) $mandate['id'], 'member_id' => $member_id ),
					__( 'Widerrufen', 'svm' )
				)
				: '';

			$rows[] = array(
				esc_html( $mandate['mandate_ref'] ),
				esc_html(
					SVM_Permissions::current_user_can( 'bank_data_view' )
						? SVM_IBAN::format( $mandate['iban'] )
						: SVM_IBAN::mask( $mandate['iban'] )
				),
				esc_html( $mandate['account_holder'] ),
				esc_html( SVM_UI::date( $mandate['signed_at'] ) ),
				esc_html( $mandate['sequence_type'] ),
				SVM_UI::badge(
					isset( $statuses[ $mandate['status'] ] ) ? $statuses[ $mandate['status'] ] : $mandate['status'],
					'active' === $mandate['status'] ? 'ok' : 'neutral'
				),
				$actions,
			);
		}

		SVM_UI::card_open( __( 'SEPA-Lastschriftmandate', 'svm' ) );
		SVM_UI::table(
			array(
				__( 'Referenz', 'svm' ),
				__( 'IBAN', 'svm' ),
				__( 'Kontoinhaber', 'svm' ),
				__( 'Unterschrieben', 'svm' ),
				__( 'Art', 'svm' ),
				__( 'Status', 'svm' ),
				'',
			),
			$rows,
			__( 'Noch kein Mandat hinterlegt. Ohne Mandat kann der Beitrag nicht eingezogen werden.', 'svm' )
		);
		SVM_UI::card_close();

		SVM_UI::card_open( __( 'Neues Mandat erfassen', 'svm' ) );
		SVM_UI::form_open( 'save_mandate', array( 'member_id' => $member_id ) );
		SVM_UI::grid_open();
		SVM_UI::field( __( 'IBAN', 'svm' ), SVM_UI::input( 'iban', '', array( 'required' => true, 'placeholder' => 'DE00 0000 0000 0000 0000 00' ) ) );
		SVM_UI::field( __( 'BIC', 'svm' ), SVM_UI::input( 'bic', '', array( 'placeholder' => __( 'optional', 'svm' ) ) ) );
		SVM_UI::field(
			__( 'Kontoinhaber', 'svm' ),
			SVM_UI::input( 'account_holder', '', array( 'placeholder' => SVM_Members::display_name( $member_id ) ) ),
			__( 'Nur ausfüllen, wenn jemand anderes zahlt — etwa ein Elternteil.', 'svm' )
		);
		SVM_UI::field( __( 'Unterschrieben am', 'svm' ), SVM_UI::input( 'signed_at', gmdate( 'Y-m-d' ), array( 'type' => 'date' ) ) );
		SVM_UI::field( __( 'Art', 'svm' ), SVM_UI::select( 'sequence_type', 'FRST', SVM_Mandates::sequence_types() ) );
		SVM_UI::grid_close();
		SVM_UI::form_close( __( 'Mandat speichern', 'svm' ) );
		SVM_UI::card_close();
	}

	/**
	 * Reiter Familie.
	 *
	 * @param array $member Mitglied.
	 * @return void
	 */
	private static function tab_family( array $member ) {
		$member_id = (int) $member['id'];
		$family_id = (int) $member['family_group_id'];

		if ( $family_id > 0 ) {
			$family = SVM_Families::get( $family_id );

			SVM_UI::card_open(
				$family ? $family['name'] : __( 'Familie', 'svm' ),
				__( 'Dieses Mitglied gehört zu einer Familie. Beitragsregeln können darauf Bezug nehmen.', 'svm' )
			);

			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: Rolle in der Familie, 2: Position. */
					__( 'Rolle: %1$s · Position in der Familie: %2$d', 'svm' ),
					SVM_Families::relation_label( $member['relation_type'] ),
					(int) $member['family_position']
				)
			) . '</p>';

			echo '<div class="svm-button-row">';
			printf(
				'<a class="svm-btn svm-btn-secondary svm-btn-small" href="%s">%s</a>',
				esc_url( SVM_App::url( 'family', array( 'id' => $family_id ) ) ),
				esc_html__( 'Familie öffnen', 'svm' )
			);

			SVM_UI::action_button(
				'remove_family_member',
				array( 'family_id' => $family_id, 'member_id' => $member_id ),
				__( 'Aus Familie lösen', 'svm' )
			);
			echo '</div>';

			SVM_UI::card_close();
			return;
		}

		SVM_UI::card_open(
			__( 'Noch keiner Familie zugeordnet', 'svm' ),
			__( 'Familien bündeln mehrere Mitglieder — dadurch greifen Familienrabatte und Höchstbeträge.', 'svm' )
		);

		SVM_UI::form_open( 'save_family' );
		echo '<input type="hidden" name="primary_member_id" value="' . esc_attr( $member_id ) . '" />';
		SVM_UI::field(
			__( 'Name der Familie', 'svm' ),
			SVM_UI::input( 'name', sprintf( /* translators: %s: Name. */ __( 'Familie %s', 'svm' ), SVM_Members::display_name( $member_id ) ) )
		);
		SVM_UI::form_close( __( 'Familie gründen', 'svm' ) );

		$families = SVM_Families::options();

		if ( count( $families ) > 1 ) {
			echo '<hr class="svm-divider" />';
			SVM_UI::form_open( 'add_family_member', array( 'member_id' => $member_id ) );
			echo '<input type="hidden" name="family_id" value="0" />';
			SVM_UI::grid_open();
			SVM_UI::field( __( 'Bestehende Familie', 'svm' ), SVM_UI::select( 'family_id', 0, $families ) );
			SVM_UI::field( __( 'Rolle', 'svm' ), SVM_UI::select( 'relation_type', 'kind', SVM_Families::relations() ) );
			SVM_UI::grid_close();
			SVM_UI::form_close( __( 'Zur Familie hinzufügen', 'svm' ), 'secondary' );
		}

		SVM_UI::card_close();
	}

	/**
	 * Kleine Schaltfläche als Tabellenzelle.
	 *
	 * @param string $action  Aktion.
	 * @param array  $hidden  Felder.
	 * @param string $label   Beschriftung.
	 * @param string $confirm Rückfrage.
	 * @return string
	 */
	public static function button_cell( $action, array $hidden, $label, $confirm = '' ) {
		ob_start();
		SVM_UI::action_button( $action, $hidden, $label, 'secondary', $confirm );

		return (string) ob_get_clean();
	}

	/**
	 * Änderungswünsche der Mitglieder.
	 *
	 * @return void
	 */
	public static function requests() {
		SVM_UI::page_head(
			__( 'Änderungswünsche', 'svm' ),
			__( 'Mitglieder haben diese Angaben im Portal geändert. Sie werden erst nach Ihrer Bestätigung übernommen.', 'svm' )
		);

		SVM_App::subnav(
			array(
				'members'  => __( 'Alle Mitglieder', 'svm' ),
				'requests' => __( 'Änderungswünsche', 'svm' ),
			),
			'requests'
		);

		$requests = SVM_Change_Requests::pending();
		$rows     = array();

		foreach ( $requests as $request ) {
			$def = SVM_Fields::get_def( (int) $request['field_id'] );

			$actions = self::button_cell( 'approve_change', array( 'id' => (int) $request['id'] ), __( 'Übernehmen', 'svm' ) ) .
				self::button_cell( 'reject_change', array( 'id' => (int) $request['id'] ), __( 'Ablehnen', 'svm' ) );

			$rows[] = array(
				'<a href="' . esc_url( SVM_App::url( 'member', array( 'id' => (int) $request['member_id'] ) ) ) . '">' .
					esc_html( SVM_Members::display_name( (int) $request['member_id'] ) ) . '</a>',
				esc_html( $def ? $def['label'] : '—' ),
				'<span class="svm-muted">' . esc_html( (string) $request['old_value'] ) . '</span>',
				'<span class="svm-strong">' . esc_html( (string) $request['new_value'] ) . '</span>',
				esc_html( SVM_UI::date( $request['requested_at'] ) ),
				'<div class="svm-button-row">' . $actions . '</div>',
			);
		}

		SVM_UI::table(
			array(
				__( 'Mitglied', 'svm' ),
				__( 'Feld', 'svm' ),
				__( 'Bisher', 'svm' ),
				__( 'Neu', 'svm' ),
				__( 'Beantragt', 'svm' ),
				'',
			),
			$rows,
			__( 'Es liegen keine Änderungswünsche vor.', 'svm' )
		);
	}
}
