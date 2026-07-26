<?php
/**
 * Adminmenü.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registriert die Menüpunkte und leitet an die Seitenklassen weiter.
 */
class SVM_Admin_Menu {

	/**
	 * Registriert alle Menüpunkte.
	 *
	 * @return void
	 */
	public static function register() {
		$badge = '';
		$open  = SVM_Change_Requests::pending_count();

		if ( $open > 0 && SVM_Permissions::current_user_can( 'change_requests' ) ) {
			$badge = ' <span class="update-plugins count-' . (int) $open . '"><span class="update-count">' . (int) $open . '</span></span>';
		}

		add_menu_page(
			__( 'Mitgliederverwaltung', 'svm' ),
			__( 'Verein', 'svm' ) . $badge,
			self::cap( 'portal_access' ),
			'svm-dashboard',
			array( 'SVM_Page_Dashboard', 'render' ),
			'dashicons-groups',
			26
		);

		$pages = array(
			'svm-dashboard' => array( __( 'Übersicht', 'svm' ), 'portal_access', 'SVM_Page_Dashboard' ),
			'svm-members'   => array( __( 'Mitglieder', 'svm' ), 'members_view', 'SVM_Page_Members' ),
			'svm-fees'      => array( __( 'Beiträge', 'svm' ), 'fees_view', 'SVM_Page_Fees' ),
			'svm-payments'  => array( __( 'Zahlungen', 'svm' ), 'payments_view', 'SVM_Page_Payments' ),
			'svm-messages'  => array( __( 'Nachrichten', 'svm' ), 'messages_view', 'SVM_Page_Messages' ),
			'svm-data'      => array( __( 'Auswertungen', 'svm' ), 'export_run', 'SVM_Page_Data' ),
			'svm-config'    => array( __( 'Konfiguration', 'svm' ), 'settings_manage', 'SVM_Page_Config' ),
		);

		foreach ( $pages as $slug => $page ) {
			list( $title, $permission, $class ) = $page;

			add_submenu_page(
				'svm-dashboard',
				$title,
				$title,
				self::cap( $permission ),
				$slug,
				array( $class, 'render' )
			);
		}
	}

	/**
	 * Übersetzt ein Plugin-Recht in eine WordPress-Capability.
	 *
	 * @param string $permission Rechteschlüssel.
	 * @return string
	 */
	public static function cap( $permission ) {
		return SVM_Permissions::current_user_can( $permission ) ? 'read' : 'do_not_allow';
	}

	/**
	 * Aktueller Reiter einer Seite.
	 *
	 * @param string $default Voreinstellung.
	 * @return string
	 */
	public static function current_tab( $default ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return '' !== $tab ? $tab : $default;
	}

	/**
	 * Gibt eine Reiternavigation aus.
	 *
	 * @param string $page    Seitenslug.
	 * @param array  $tabs    Reiter (Schlüssel => Label).
	 * @param string $current Aktiver Reiter.
	 * @return void
	 */
	public static function tabs( $page, array $tabs, $current ) {
		echo '<nav class="nav-tab-wrapper">';

		foreach ( $tabs as $key => $label ) {
			$url = add_query_arg(
				array(
					'page' => $page,
					'tab'  => $key,
				),
				admin_url( 'admin.php' )
			);

			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				$key === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Gibt Hinweise aus der Weiterleitung aus.
	 *
	 * @return void
	 */
	public static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['svm_message'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sanitize_text_field( wp_unslash( $_GET['svm_message'] ) ) )
			);
		}

		if ( isset( $_GET['svm_error'] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( sanitize_text_field( wp_unslash( $_GET['svm_error'] ) ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Baut eine URL innerhalb des Plugins.
	 *
	 * @param string $page Seitenslug.
	 * @param array  $args Zusätzliche Parameter.
	 * @return string
	 */
	public static function url( $page, array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => $page ), $args ),
			admin_url( 'admin.php' )
		);
	}
}
