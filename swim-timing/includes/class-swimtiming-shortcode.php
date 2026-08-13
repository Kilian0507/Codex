<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SwimTiming_Shortcode {

	public function __construct() {
		add_shortcode( 'swim_timing', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	private function shortcode_present() {
		global $post;
		return ( $post instanceof WP_Post ) && has_shortcode( $post->post_content, 'swim_timing' );
	}

	public function maybe_enqueue() {
		if ( ! $this->shortcode_present() ) {
			return;
		}
		$this->enqueue_assets();
	}

	private function enqueue_assets() {
		wp_enqueue_style( 'swimtiming', SWIMTIMING_URL . 'assets/css/swim-timing.css', array(), SWIMTIMING_VERSION );
		wp_enqueue_script( 'swimtiming', SWIMTIMING_URL . 'assets/js/swim-timing.js', array(), SWIMTIMING_VERSION, true );

		$is_admin = SwimTiming_Settings::current_user_is_admin();

		wp_localize_script( 'swimtiming', 'SwimTimingData', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'isAdmin'      => $is_admin,
			'adminNonce'   => $is_admin ? wp_create_nonce( 'swimtiming_admin' ) : '',
			'publicNonce'  => wp_create_nonce( 'swimtiming_public' ),
			'title'        => SwimTiming_Settings::get_title(),
			'i18n'         => array(
				'confirmDeleteStarter' => __( 'Diese Startperson inkl. aller Zwischenzeiten wirklich löschen?', 'swim-timing' ),
				'confirmDeleteSplit'   => __( 'Diese Zwischenzeit wirklich löschen?', 'swim-timing' ),
				'loading'              => __( 'Lade…', 'swim-timing' ),
				'save'                 => __( 'Speichern', 'swim-timing' ),
				'saved'                => __( 'Gespeichert.', 'swim-timing' ),
				'error'                => __( 'Es ist ein Fehler aufgetreten.', 'swim-timing' ),
				'noResults'            => __( 'Keine Startperson mit diesen Angaben gefunden.', 'swim-timing' ),
				'requiredFields'       => __( 'Bitte alle Pflichtfelder ausfüllen.', 'swim-timing' ),
			),
		) );
	}

	public function render( $atts ) {
		// Ensure assets are present even if detection via has_shortcode() missed
		// this instance (e.g. shortcode rendered via widget or block editor dynamically).
		if ( ! wp_script_is( 'swimtiming', 'enqueued' ) ) {
			$this->enqueue_assets();
		}

		$title = SwimTiming_Settings::get_title();
		$is_admin = SwimTiming_Settings::current_user_is_admin();

		ob_start();
		?>
		<div class="swimtiming-wrap">
			<h2 class="swimtiming-title"><?php echo esc_html( $title ); ?></h2>

			<?php if ( $is_admin ) : ?>
				<?php $this->render_admin_area(); ?>
			<?php else : ?>
				<?php $this->render_public_area(); ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_admin_area() {
		?>
		<div id="swimtiming-admin" class="swimtiming-admin">
			<div class="swimtiming-tabs">
				<button type="button" class="swimtiming-tab is-active" data-tab="starters"><?php esc_html_e( 'Startpersonen', 'swim-timing' ); ?></button>
				<button type="button" class="swimtiming-tab" data-tab="import"><?php esc_html_e( 'CSV-Import', 'swim-timing' ); ?></button>
			</div>

			<div class="swimtiming-panel is-active" data-panel="starters">
				<div class="swimtiming-toolbar">
					<input type="search" id="swimtiming-search" placeholder="<?php esc_attr_e( 'Suche nach Name…', 'swim-timing' ); ?>" />
					<button type="button" class="swimtiming-btn swimtiming-btn-primary" id="swimtiming-new-starter"><?php esc_html_e( '+ Startperson anlegen', 'swim-timing' ); ?></button>
				</div>

				<div class="swimtiming-table-wrap">
					<table class="swimtiming-table" id="swimtiming-starters-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Vorname', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Nachname', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Meldezeit', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Startzeit', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Endzeit', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Zwischenzeiten', 'swim-timing' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody id="swimtiming-starters-tbody">
							<tr><td colspan="7" class="swimtiming-empty"><?php esc_html_e( 'Lade…', 'swim-timing' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="swimtiming-panel" data-panel="import">
				<div class="swimtiming-card">
					<h3><?php esc_html_e( 'Startpersonen importieren', 'swim-timing' ); ?></h3>
					<p class="swimtiming-hint"><?php esc_html_e( 'CSV-Spalten: Vorname, Nachname, Meldezeit, Startzeit', 'swim-timing' ); ?></p>
					<form id="swimtiming-import-starters-form">
						<input type="file" name="csv_file" accept=".csv" required />
						<button type="submit" class="swimtiming-btn swimtiming-btn-primary"><?php esc_html_e( 'Importieren', 'swim-timing' ); ?></button>
					</form>
					<div class="swimtiming-import-result" id="swimtiming-import-starters-result"></div>
				</div>

				<div class="swimtiming-card">
					<h3><?php esc_html_e( 'Zwischenzeiten importieren', 'swim-timing' ); ?></h3>
					<p class="swimtiming-hint"><?php esc_html_e( 'CSV-Spalten: Nummer, Vorname, Nachname, Zeit', 'swim-timing' ); ?></p>
					<form id="swimtiming-import-splits-form">
						<input type="file" name="csv_file" accept=".csv" required />
						<button type="submit" class="swimtiming-btn swimtiming-btn-primary"><?php esc_html_e( 'Importieren', 'swim-timing' ); ?></button>
					</form>
					<div class="swimtiming-import-result" id="swimtiming-import-splits-result"></div>
				</div>
			</div>
		</div>

		<!-- Starter modal (create / edit) -->
		<div class="swimtiming-modal" id="swimtiming-starter-modal" hidden>
			<div class="swimtiming-modal-inner">
				<button type="button" class="swimtiming-modal-close" data-close-modal>&times;</button>
				<h3 id="swimtiming-starter-modal-title"><?php esc_html_e( 'Startperson anlegen', 'swim-timing' ); ?></h3>
				<form id="swimtiming-starter-form">
					<input type="hidden" name="id" value="" />
					<div class="swimtiming-form-row">
						<label><?php esc_html_e( 'Vorname', 'swim-timing' ); ?>
							<input type="text" name="first_name" required />
						</label>
						<label><?php esc_html_e( 'Nachname', 'swim-timing' ); ?>
							<input type="text" name="last_name" required />
						</label>
					</div>
					<div class="swimtiming-form-row">
						<label><?php esc_html_e( 'Meldezeit', 'swim-timing' ); ?>
							<input type="time" name="report_time" class="swimtiming-clock-input" />
						</label>
						<label><?php esc_html_e( 'Startzeit', 'swim-timing' ); ?>
							<input type="time" name="start_time" class="swimtiming-clock-input" />
						</label>
					</div>
					<div class="swimtiming-form-row">
						<label><?php esc_html_e( 'Endzeit', 'swim-timing' ); ?>
							<input type="time" name="end_time" class="swimtiming-clock-input" />
						</label>
					</div>
					<div class="swimtiming-form-actions">
						<button type="submit" class="swimtiming-btn swimtiming-btn-primary"><?php esc_html_e( 'Speichern', 'swim-timing' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<!-- Starter detail / splits modal -->
		<div class="swimtiming-modal swimtiming-modal-wide" id="swimtiming-detail-modal" hidden>
			<div class="swimtiming-modal-inner">
				<button type="button" class="swimtiming-modal-close" data-close-modal>&times;</button>
				<h3 id="swimtiming-detail-name"></h3>
				<p class="swimtiming-hint" id="swimtiming-detail-meta"></p>

				<h4><?php esc_html_e( 'Zwischenzeiten', 'swim-timing' ); ?></h4>
				<div class="swimtiming-table-wrap">
					<table class="swimtiming-table" id="swimtiming-splits-table">
						<thead>
							<tr>
								<th>#</th>
								<th><?php esc_html_e( 'Zeit', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Kommentar', 'swim-timing' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody id="swimtiming-splits-tbody"></tbody>
					</table>
				</div>

				<h4><?php esc_html_e( 'Zwischenzeit hinzufügen', 'swim-timing' ); ?></h4>
				<form id="swimtiming-split-form">
					<input type="hidden" name="starter_id" value="" />
					<div class="swimtiming-form-row">
						<label><?php esc_html_e( 'Nummer', 'swim-timing' ); ?>
							<input type="number" name="split_number" min="1" />
						</label>
						<label><?php esc_html_e( 'Zeit', 'swim-timing' ); ?>
							<span class="swimtiming-time-input" data-name="split_time"></span>
						</label>
					</div>
					<label class="swimtiming-full"><?php esc_html_e( 'Kommentar', 'swim-timing' ); ?>
						<input type="text" name="comment" />
					</label>
					<div class="swimtiming-form-actions">
						<button type="submit" class="swimtiming-btn swimtiming-btn-primary"><?php esc_html_e( '+ Hinzufügen', 'swim-timing' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_public_area() {
		?>
		<div id="swimtiming-public" class="swimtiming-public">
			<form id="swimtiming-lookup-form" class="swimtiming-card">
				<p class="swimtiming-hint"><?php esc_html_e( 'Bitte gib deinen Vornamen, Nachnamen und deine Startzeit ein, um deine Zwischenzeiten abzurufen.', 'swim-timing' ); ?></p>
				<div class="swimtiming-form-row">
					<label><?php esc_html_e( 'Vorname', 'swim-timing' ); ?>
						<input type="text" name="first_name" required />
					</label>
					<label><?php esc_html_e( 'Nachname', 'swim-timing' ); ?>
						<input type="text" name="last_name" required />
					</label>
				</div>
				<div class="swimtiming-form-row">
					<label><?php esc_html_e( 'Startzeit', 'swim-timing' ); ?>
						<input type="time" name="start_time" class="swimtiming-clock-input" required />
					</label>
				</div>
				<div class="swimtiming-form-actions">
					<button type="submit" class="swimtiming-btn swimtiming-btn-primary"><?php esc_html_e( 'Meine Zeiten anzeigen', 'swim-timing' ); ?></button>
				</div>
			</form>

			<div class="swimtiming-card swimtiming-result" id="swimtiming-public-result" hidden>
				<div class="swimtiming-result-header">
					<div>
						<h3 id="swimtiming-public-name"></h3>
						<p class="swimtiming-hint" id="swimtiming-public-meta"></p>
					</div>
					<a href="#" class="swimtiming-btn" id="swimtiming-public-pdf" target="_blank" rel="noopener"><?php esc_html_e( 'Als PDF herunterladen', 'swim-timing' ); ?></a>
				</div>
				<div class="swimtiming-table-wrap">
					<table class="swimtiming-table">
						<thead>
							<tr>
								<th>#</th>
								<th><?php esc_html_e( 'Zeit', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Kommentar', 'swim-timing' ); ?></th>
							</tr>
						</thead>
						<tbody id="swimtiming-public-splits"></tbody>
					</table>
				</div>
			</div>

			<p class="swimtiming-error" id="swimtiming-public-error" hidden></p>
		</div>
		<?php
	}
}
