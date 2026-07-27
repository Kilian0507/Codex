<?php
/**
 * Die gesamte Vereinsverwaltung auf der Seite mit dem Shortcode.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shell, Navigation und Routing. Was ein Benutzer sieht, ergibt sich
 * ausschließlich aus seinen Rechten.
 */
class SVM_App {

	/**
	 * URL der Seite, auf der der Shortcode steht.
	 *
	 * @var string
	 */
	private static $page_url = '';

	/**
	 * Registriert den Shortcode.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( 'svm_app', array( __CLASS__, 'render' ) );
		add_shortcode( 'svm_portal', array( __CLASS__, 'render' ) );
	}

	/**
	 * Liefert die URL der Verwaltungsseite.
	 *
	 * @param array $args Zusätzliche Parameter.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		$base = self::$page_url;

		if ( '' === $base ) {
			$page_id = (int) get_option( 'svm_portal_page_id', 0 );
			$base    = $page_id ? get_permalink( $page_id ) : home_url( '/' );
		}

		if ( ! $base ) {
			$base = home_url( '/' );
		}

		return empty( $args ) ? $base : add_query_arg( $args, $base );
	}

	/**
	 * URL einer Ansicht.
	 *
	 * @param string $view Ansicht.
	 * @param array  $args Zusätzliche Parameter.
	 * @return string
	 */
	public static function url( $view, array $args = array() ) {
		return self::page_url( array_merge( array( 'svm_view' => $view ), $args ) );
	}

	/**
	 * Liest einen Parameter aus der Adresszeile.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Voreinstellung.
	 * @return string
	 */
	public static function arg( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * Ganzzahliger Parameter.
	 *
	 * @param string $key Schlüssel.
	 * @return int
	 */
	public static function id( $key = 'id' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0;
	}

	/**
	 * Hauptnavigation.
	 *
	 * @return array
	 */
	private static function nav() {
		return array(
			'dashboard' => array(
				'label'       => __( 'Übersicht', 'svm' ),
				'permissions' => array( 'members_view', 'invoices_view', 'payments_view' ),
			),
			'members'   => array(
				'label'       => __( 'Mitglieder', 'svm' ),
				'permissions' => array( 'members_view' ),
			),
			'families'  => array(
				'label'       => __( 'Familien', 'svm' ),
				'permissions' => array( 'members_view' ),
			),
			'fees'      => array(
				'label'       => __( 'Beiträge', 'svm' ),
				'permissions' => array( 'fees_view', 'invoices_view' ),
			),
			'payments'  => array(
				'label'       => __( 'Zahlungen', 'svm' ),
				'permissions' => array( 'payments_view', 'payments_record' ),
			),
			'messages'  => array(
				'label'       => __( 'Nachrichten', 'svm' ),
				'permissions' => array( 'messages_view' ),
			),
			'export'    => array(
				'label'       => __( 'Import & Export', 'svm' ),
				'permissions' => array( 'export_run', 'import_run' ),
			),
			'settings'  => array(
				'label'       => __( 'Einstellungen', 'svm' ),
				'permissions' => array( 'fields_manage', 'roles_manage', 'settings_manage', 'units_manage' ),
			),
			'profile'   => array(
				'label'       => __( 'Meine Daten', 'svm' ),
				'permissions' => array( 'portal_access' ),
			),
		);
	}

	/**
	 * Alle Ansichten: Ansicht => array( Navigationspunkt, Recht, Klasse, Methode ).
	 *
	 * @return array
	 */
	private static function views() {
		return array(
			'dashboard'    => array( 'dashboard', 'members_view', 'SVM_View_Dashboard', 'render' ),
			'members'      => array( 'members', 'members_view', 'SVM_View_Members', 'listing' ),
			'member'       => array( 'members', 'members_view', 'SVM_View_Members', 'detail' ),
			'member_new'   => array( 'members', 'members_create', 'SVM_View_Members', 'create' ),
			'requests'     => array( 'members', 'change_requests', 'SVM_View_Members', 'requests' ),
			'families'     => array( 'families', 'members_view', 'SVM_View_Families', 'listing' ),
			'family'       => array( 'families', 'members_view', 'SVM_View_Families', 'detail' ),
			'fees'         => array( 'fees', 'fees_view', 'SVM_View_Fees', 'listing' ),
			'fee'          => array( 'fees', 'fees_manage', 'SVM_View_Fees', 'edit' ),
			'discounts'    => array( 'fees', 'fees_manage', 'SVM_View_Fees', 'discounts' ),
			'feerun'       => array( 'fees', 'fee_run', 'SVM_View_Fees', 'run' ),
			'invoices'     => array( 'fees', 'invoices_view', 'SVM_View_Fees', 'invoices' ),
			'payments'     => array( 'payments', 'payments_view', 'SVM_View_Payments', 'listing' ),
			'payment_new'  => array( 'payments', 'payments_record', 'SVM_View_Payments', 'record' ),
			'sepa'         => array( 'payments', 'payment_run', 'SVM_View_Payments', 'sepa' ),
			'bankaccounts' => array( 'payments', 'payment_run', 'SVM_View_Payments', 'profiles' ),
			'dunning'      => array( 'payments', 'dunning_manage', 'SVM_View_Payments', 'dunning' ),
			'messages'     => array( 'messages', 'messages_view', 'SVM_View_Messages', 'listing' ),
			'message'      => array( 'messages', 'messages_manage', 'SVM_View_Messages', 'edit' ),
			'export'       => array( 'export', 'export_run', 'SVM_View_Data', 'export' ),
			'import'       => array( 'export', 'import_run', 'SVM_View_Data', 'import' ),
			'privacy'      => array( 'export', 'members_view', 'SVM_View_Data', 'privacy' ),
			'audit'        => array( 'export', 'audit_view', 'SVM_View_Data', 'audit' ),
			'settings'     => array( 'settings', 'settings_manage', 'SVM_View_Config', 'settings' ),
			'fields'       => array( 'settings', 'fields_manage', 'SVM_View_Config', 'fields' ),
			'field'        => array( 'settings', 'fields_manage', 'SVM_View_Config', 'field' ),
			'units'        => array( 'settings', 'units_manage', 'SVM_View_Config', 'units' ),
			'statuses'     => array( 'settings', 'settings_manage', 'SVM_View_Config', 'statuses' ),
			'roles'        => array( 'settings', 'roles_manage', 'SVM_View_Config', 'roles' ),
			'team'         => array( 'settings', 'roles_manage', 'SVM_View_Config', 'team' ),
			'methods'      => array( 'settings', 'settings_manage', 'SVM_View_Config', 'methods' ),
			'numbers'      => array( 'settings', 'settings_manage', 'SVM_View_Config', 'numbers' ),
			'templates'    => array( 'settings', 'settings_manage', 'SVM_View_Config', 'templates' ),
			'profile'      => array( 'profile', 'portal_access', 'SVM_View_Profile', 'render' ),
		);
	}

	/**
	 * Prüft, ob ein Navigationspunkt sichtbar ist.
	 *
	 * @param array $item Navigationspunkt.
	 * @return bool
	 */
	private static function nav_visible( array $item ) {
		foreach ( $item['permissions'] as $permission ) {
			if ( SVM_Permissions::current_user_can( $permission ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gibt die Anwendung aus.
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		unset( $atts );

		self::$page_url = get_permalink();
		wp_enqueue_style( 'svm-app' );

		if ( ! is_user_logged_in() ) {
			return '<div class="svm-app"><div class="svm-card"><p>' .
				esc_html__( 'Bitte melden Sie sich an, um die Vereinsverwaltung zu nutzen.', 'svm' ) . '</p>' .
				'<p><a class="svm-btn svm-btn-primary" href="' . esc_url( wp_login_url( self::page_url() ) ) . '">' .
				esc_html__( 'Anmelden', 'svm' ) . '</a></p></div></div>';
		}

		$views   = self::views();
		$view    = self::arg( 'svm_view', '' );
		$default = SVM_Permissions::current_user_can( 'members_view' ) ? 'dashboard' : 'profile';

		if ( '' === $view || ! isset( $views[ $view ] ) ) {
			$view = $default;
		}

		list( $nav_key, $permission, $class, $method ) = $views[ $view ];

		if ( ! SVM_Permissions::current_user_can( $permission ) ) {
			$view = $default;
			list( $nav_key, $permission, $class, $method ) = $views[ $view ];
		}

		ob_start();

		echo '<div class="svm-app">';

		self::render_header();
		self::render_nav( $nav_key );
		self::render_notices();

		echo '<main class="svm-main">';

		if ( SVM_Permissions::current_user_can( $permission ) && class_exists( $class ) && method_exists( $class, $method ) ) {
			call_user_func( array( $class, $method ) );
		} else {
			SVM_UI::notice( __( 'Für diesen Bereich fehlt Ihnen die Berechtigung.', 'svm' ), 'warning' );
		}

		echo '</main>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Kopfbereich mit Vereinsname und Benutzer.
	 *
	 * @return void
	 */
	private static function render_header() {
		$user   = wp_get_current_user();
		$member = SVM_Members::get_by_user( get_current_user_id() );

		echo '<header class="svm-app-bar">';
		echo '<div class="svm-app-brand">' . esc_html( get_option( 'svm_club_name', get_bloginfo( 'name' ) ) ) . '</div>';
		echo '<div class="svm-app-user">';
		echo '<span>' . esc_html( $member ? SVM_Members::display_name( (int) $member['id'] ) : $user->display_name ) . '</span>';
		echo '<a href="' . esc_url( wp_logout_url( self::page_url() ) ) . '">' . esc_html__( 'Abmelden', 'svm' ) . '</a>';
		echo '</div>';
		echo '</header>';
	}

	/**
	 * Hauptnavigation.
	 *
	 * @param string $current Aktiver Punkt.
	 * @return void
	 */
	private static function render_nav( $current ) {
		$open = SVM_Change_Requests::pending_count();

		echo '<nav class="svm-nav">';

		foreach ( self::nav() as $key => $item ) {
			if ( ! self::nav_visible( $item ) ) {
				continue;
			}

			$badge = '';

			if ( 'members' === $key && $open > 0 && SVM_Permissions::current_user_can( 'change_requests' ) ) {
				$badge = ' <span class="svm-nav-badge">' . (int) $open . '</span>';
			}

			printf(
				'<a class="svm-nav-item%s" href="%s">%s%s</a>',
				$key === $current ? ' is-active' : '',
				esc_url( self::url( $key ) ),
				esc_html( $item['label'] ),
				$badge // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}

		echo '</nav>';
	}

	/**
	 * Meldungen nach einer Aktion.
	 *
	 * @return void
	 */
	private static function render_notices() {
		$notice = self::arg( 'svm_notice' );
		$error  = self::arg( 'svm_error' );

		if ( '' !== $notice ) {
			SVM_UI::notice( $notice, 'success' );
		}

		if ( '' !== $error ) {
			SVM_UI::notice( $error, 'error' );
		}

		self::render_health_warning();
	}

	/**
	 * Warnt, wenn Datenbanktabellen fehlen.
	 *
	 * Ohne diesen Hinweis liefe ein Speichern ins Leere, ohne dass jemand
	 * den Grund sieht.
	 *
	 * @return void
	 */
	private static function render_health_warning() {
		if ( ! SVM_Permissions::current_user_can( 'settings_manage' ) ) {
			return;
		}

		$missing = SVM_Installer::known_missing();

		if ( empty( $missing ) ) {
			return;
		}

		SVM_UI::notice(
			sprintf(
				/* translators: %s: Liste der fehlenden Tabellen. */
				__( 'In der Datenbank fehlen Tabellen (%s). Angaben in den betroffenen Bereichen lassen sich nicht speichern. Unter „Einstellungen → Verein“ können Sie die Datenbank prüfen und ergänzen lassen.', 'svm' ),
				implode( ', ', $missing )
			),
			'error'
		);
	}

	/**
	 * Baut die Reiter einer Ansicht.
	 *
	 * @param array  $items   Ansicht => Beschriftung.
	 * @param string $current Aktive Ansicht.
	 * @return void
	 */
	public static function subnav( array $items, $current ) {
		$tabs = array();

		foreach ( $items as $view => $label ) {
			$views = self::views();

			if ( isset( $views[ $view ] ) && ! SVM_Permissions::current_user_can( $views[ $view ][1] ) ) {
				continue;
			}

			$tabs[ $view ] = array(
				'label' => $label,
				'url'   => self::url( $view ),
			);
		}

		if ( count( $tabs ) > 1 ) {
			SVM_UI::tabs( $tabs, $current );
		}
	}

	/**
	 * Auswahlliste aller Mitglieder für Formulare.
	 *
	 * @param string $empty_label Beschriftung für „keine Auswahl“.
	 * @return array
	 */
	public static function member_options( $empty_label = '' ) {
		$out = array();

		if ( '' !== $empty_label ) {
			$out[0] = $empty_label;
		}

		foreach ( SVM_Members::query( array( 'limit' => 1000 ) ) as $member ) {
			$id         = (int) $member['id'];
			$out[ $id ] = SVM_Members::display_name( $id ) . ' · ' . $member['member_number'];
		}

		return $out;
	}
}
