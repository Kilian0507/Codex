<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SwimTiming_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Swim Timing', 'swim-timing' ),
			__( 'Swim Timing', 'swim-timing' ),
			'manage_options',
			'swim-timing-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'swimtiming_settings', 'swimtiming_title', array(
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => __( 'Schwimmen Zeitnahme', 'swim-timing' ),
		) );
		register_setting( 'swimtiming_settings', 'swimtiming_admin_role', array(
			'sanitize_callback' => 'sanitize_key',
			'default'           => 'administrator',
		) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$title = get_option( 'swimtiming_title', __( 'Schwimmen Zeitnahme', 'swim-timing' ) );
		$admin_role = get_option( 'swimtiming_admin_role', 'administrator' );
		$roles = wp_roles()->roles;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Swim Timing – Einstellungen', 'swim-timing' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'swimtiming_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="swimtiming_title"><?php esc_html_e( 'Überschrift', 'swim-timing' ); ?></label></th>
						<td>
							<input type="text" id="swimtiming_title" name="swimtiming_title" value="<?php echo esc_attr( $title ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Diese Überschrift wird überall im Plugin (Frontend Admin- und öffentlicher Bereich, PDF) als Titel verwendet.', 'swim-timing' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="swimtiming_admin_role"><?php esc_html_e( 'Berechtigte Rolle', 'swim-timing' ); ?></label></th>
						<td>
							<select id="swimtiming_admin_role" name="swimtiming_admin_role">
								<?php foreach ( $roles as $role_key => $role ) : ?>
									<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $admin_role, $role_key ); ?>>
										<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Nutzer mit dieser Rolle sehen im Shortcode den Adminbereich zur Verwaltung der Startpersonen. Alle anderen Besucher sehen den öffentlichen Abfragebereich.', 'swim-timing' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<hr />
			<p><?php esc_html_e( 'Shortcode zur Einbindung im Frontend:', 'swim-timing' ); ?> <code>[swim_timing]</code></p>
		</div>
		<?php
	}

	public static function get_title() {
		return get_option( 'swimtiming_title', __( 'Schwimmen Zeitnahme', 'swim-timing' ) );
	}

	public static function get_admin_role() {
		return get_option( 'swimtiming_admin_role', 'administrator' );
	}

	public static function current_user_is_admin() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$role = self::get_admin_role();
		$user = wp_get_current_user();
		return in_array( $role, (array) $user->roles, true );
	}
}
