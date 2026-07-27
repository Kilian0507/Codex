<?php
/**
 * Verweis im WordPress-Adminbereich.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Die Verwaltung selbst liegt im Frontend. Im Adminbereich steht nur noch
 * eine Einstiegsseite, die dorthin führt und beim Einrichten hilft.
 */
class SVM_Admin_Menu {

	/**
	 * Registriert den Menüpunkt.
	 *
	 * @return void
	 */
	public static function register() {
		add_menu_page(
			__( 'Vereinsverwaltung', 'svm' ),
			__( 'Verein', 'svm' ),
			'read',
			'svm-start',
			array( __CLASS__, 'render' ),
			'dashicons-groups',
			26
		);
	}

	/**
	 * Gibt die Einstiegsseite aus.
	 *
	 * @return void
	 */
	public static function render() {
		$page_id = (int) get_option( 'svm_portal_page_id', 0 );
		$page    = $page_id ? get_post( $page_id ) : null;
		$url     = $page ? get_permalink( $page ) : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Vereinsverwaltung', 'svm' ) . '</h1>';

		echo '<p>' . esc_html__( 'Die gesamte Verwaltung — Mitglieder, Familien, Beiträge, Zahlungen, Nachrichten und Einstellungen — läuft auf der Seite mit dem Shortcode im Frontend.', 'svm' ) . '</p>';

		if ( $url ) {
			printf(
				'<p><a class="button button-primary button-hero" href="%s">%s</a></p>',
				esc_url( $url ),
				esc_html__( 'Zur Vereinsverwaltung', 'svm' )
			);
		} else {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Es ist noch keine Seite hinterlegt. Legen Sie eine Seite an, fügen Sie dort den Shortcode ein und tragen Sie die Seite anschließend in den Einstellungen ein.', 'svm' );
			echo '</p></div>';
		}

		echo '<h2>' . esc_html__( 'So richten Sie die Seite ein', 'svm' ) . '</h2>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Eine neue WordPress-Seite anlegen, zum Beispiel „Vereinsverwaltung“.', 'svm' ) . '</li>';
		printf(
			'<li>%s <code>[svm_app]</code></li>',
			esc_html__( 'Dort diesen Shortcode einfügen:', 'svm' )
		);
		echo '<li>' . esc_html__( 'Seite veröffentlichen und aufrufen. Wer welche Bereiche sieht, ergibt sich aus den Rollen.', 'svm' ) . '</li>';
		echo '<li>' . esc_html__( 'In der Verwaltung unter „Einstellungen → Verein“ die Seite eintragen, damit Formulare korrekt zurückführen.', 'svm' ) . '</li>';
		echo '</ol>';

		if ( $page ) {
			$has_shortcode = has_shortcode( (string) $page->post_content, 'svm_app' ) ||
				has_shortcode( (string) $page->post_content, 'svm_portal' );

			if ( ! $has_shortcode ) {
				echo '<div class="notice notice-warning"><p>';
				echo esc_html__( 'Auf der hinterlegten Seite wurde der Shortcode nicht gefunden. Bitte ergänzen Sie [svm_app].', 'svm' );
				echo '</p></div>';
			}
		}

		echo '<h2>' . esc_html__( 'Hinweis für Administratoren', 'svm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Benutzer mit WordPress-Administratorrechten haben in der Verwaltung automatisch Vollzugriff. Alle anderen erhalten ihre Rechte über die Rollen des Plugins.', 'svm' ) . '</p>';

		echo '</div>';
	}
}
