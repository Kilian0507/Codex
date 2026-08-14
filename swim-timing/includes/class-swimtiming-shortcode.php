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
				'addSplit'             => __( '+ Hinzufügen', 'swim-timing' ),
				'editSplit'            => __( 'Speichern', 'swim-timing' ),
				'addSplitTitle'        => __( 'Zwischenzeit hinzufügen', 'swim-timing' ),
				'editSplitTitle'       => __( 'Zwischenzeit bearbeiten', 'swim-timing' ),
				'confirmDeleteAll'     => __( 'Wirklich ALLE Startpersonen und Zwischenzeiten unwiderruflich löschen? Zum Bestätigen unten „LÖSCHEN“ eingeben.', 'swim-timing' ),
				'deleteAllPrompt'      => __( 'Bitte zur Bestätigung „LÖSCHEN“ eingeben:', 'swim-timing' ),
				'deleteAllDone'        => __( 'Alle Daten wurden gelöscht.', 'swim-timing' ),
				'noneTeam'             => __( 'Keine', 'swim-timing' ),
				'searchPlaceholder'    => __( 'Name eingeben…', 'swim-timing' ),
				'pleaseSelectStarter'  => __( 'Bitte eine Person aus den Vorschlägen auswählen.', 'swim-timing' ),
				'entrySaved'           => __( 'Zeit gespeichert.', 'swim-timing' ),
				'nextStart'            => __( 'Nächste Startzeit', 'swim-timing' ),
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

			<?php
			if ( $is_admin ) {
				$this->render_admin_area();
			} elseif ( isset( $_GET['swimtiming_entry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch, no state change.
				$this->render_entry_area();
			} else {
				$this->render_public_area();
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_admin_area() {
		$page_url = get_permalink();
		if ( ! $page_url ) {
			$page_url = home_url( '/' );
		}
		$entry_url = add_query_arg( 'swimtiming_entry', '1', $page_url );
		$qr_download_url = add_query_arg(
			array(
				'action' => 'swimtiming_qrcode_download',
				'nonce'  => wp_create_nonce( 'swimtiming_admin' ),
				'url'    => rawurlencode( $entry_url ),
			),
			admin_url( 'admin-ajax.php' )
		);
		$qr_preview_url = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode( $entry_url );
		?>
		<div id="swimtiming-admin" class="swimtiming-admin">
			<div class="swimtiming-tabs">
				<button type="button" class="swimtiming-tab is-active" data-tab="starters"><?php esc_html_e( 'Startpersonen', 'swim-timing' ); ?></button>
				<button type="button" class="swimtiming-tab" data-tab="import"><?php esc_html_e( 'Tabelle einfügen', 'swim-timing' ); ?></button>
				<button type="button" class="swimtiming-tab" data-tab="qrcode"><?php esc_html_e( 'QR-Code', 'swim-timing' ); ?></button>
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
								<th><?php esc_html_e( 'Staffel', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Pos.', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Meldezeit', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Startzeit', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Endzeit', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Zwischenzeiten', 'swim-timing' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody id="swimtiming-starters-tbody">
							<tr><td colspan="9" class="swimtiming-empty"><?php esc_html_e( 'Lade…', 'swim-timing' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="swimtiming-panel" data-panel="import">
				<div class="swimtiming-card">
					<h3><?php esc_html_e( 'Startpersonen aus Tabelle einfügen', 'swim-timing' ); ?></h3>
					<p class="swimtiming-hint"><?php esc_html_e( 'Spalten in dieser Reihenfolge kopieren (z. B. aus Excel/Google Sheets) und hier einfügen: Vorname, Nachname, Meldezeit, Startzeit, Staffel (rot/gelb, optional), Position in der Staffel (optional, sonst automatisch nach Zeilenreihenfolge).', 'swim-timing' ); ?></p>
					<form id="swimtiming-import-starters-form">
						<textarea name="data" rows="6" placeholder="Anna&#9;Muster&#9;01:23:45&#9;08:15&#9;rot&#9;1&#10;Ben&#9;Beispiel&#9;01:45:12&#9;08:16&#9;rot&#9;2" required></textarea>
						<button type="submit" class="swimtiming-btn swimtiming-btn-primary"><?php esc_html_e( 'Übernehmen', 'swim-timing' ); ?></button>
					</form>
					<div class="swimtiming-import-result" id="swimtiming-import-starters-result"></div>
				</div>

				<div class="swimtiming-card">
					<h3><?php esc_html_e( 'Zwischenzeiten aus Tabelle einfügen', 'swim-timing' ); ?></h3>
					<p class="swimtiming-hint"><?php esc_html_e( 'Spalten in dieser Reihenfolge kopieren und hier einfügen: Nummer, Vorname, Nachname, Zeit. Die Startperson wird über Vor- und Nachname zugeordnet.', 'swim-timing' ); ?></p>
					<form id="swimtiming-import-splits-form">
						<textarea name="data" rows="6" placeholder="1&#9;Anna&#9;Muster&#9;01:23:45&#10;2&#9;Anna&#9;Muster&#9;02:47:90" required></textarea>
						<button type="submit" class="swimtiming-btn swimtiming-btn-primary"><?php esc_html_e( 'Übernehmen', 'swim-timing' ); ?></button>
					</form>
					<div class="swimtiming-import-result" id="swimtiming-import-splits-result"></div>
				</div>

				<div class="swimtiming-card swimtiming-card-danger">
					<h3><?php esc_html_e( 'Alle Daten löschen', 'swim-timing' ); ?></h3>
					<p class="swimtiming-hint"><?php esc_html_e( 'Löscht unwiderruflich alle Startpersonen und alle Zwischenzeiten.', 'swim-timing' ); ?></p>
					<button type="button" class="swimtiming-btn swimtiming-btn-danger" id="swimtiming-delete-all"><?php esc_html_e( 'Alle Daten löschen', 'swim-timing' ); ?></button>
				</div>
			</div>

			<div class="swimtiming-panel" data-panel="qrcode">
				<div class="swimtiming-card swimtiming-qrcode-card">
					<h3><?php esc_html_e( 'QR-Code für die Zeiterfassung vor Ort', 'swim-timing' ); ?></h3>
					<p class="swimtiming-hint"><?php esc_html_e( 'Wer diesen QR-Code scannt, kann ohne Anmeldung eine Startperson suchen und deren Zeit eintragen. Ideal zum Ausdrucken und an den Wechselpunkten aufhängen.', 'swim-timing' ); ?></p>
					<img src="<?php echo esc_url( $qr_preview_url ); ?>" alt="QR-Code" width="220" height="220" class="swimtiming-qrcode-preview" />
					<p class="swimtiming-hint"><code><?php echo esc_html( $entry_url ); ?></code></p>
					<a href="<?php echo esc_url( $qr_download_url ); ?>" class="swimtiming-btn swimtiming-btn-primary" download><?php esc_html_e( 'QR-Code als Bild herunterladen', 'swim-timing' ); ?></a>
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
						<label><?php esc_html_e( 'Staffel', 'swim-timing' ); ?>
							<select name="team">
								<option value=""><?php esc_html_e( 'Keine', 'swim-timing' ); ?></option>
								<option value="rot"><?php esc_html_e( 'Rot', 'swim-timing' ); ?></option>
								<option value="gelb"><?php esc_html_e( 'Gelb', 'swim-timing' ); ?></option>
							</select>
						</label>
						<label><?php esc_html_e( 'Position in der Staffel', 'swim-timing' ); ?>
							<input type="number" name="team_position" min="1" placeholder="<?php esc_attr_e( 'automatisch', 'swim-timing' ); ?>" />
						</label>
					</div>
					<div class="swimtiming-form-row">
						<label><?php esc_html_e( 'Startzeit', 'swim-timing' ); ?>
							<input type="time" name="start_time" class="swimtiming-clock-input" />
						</label>
						<label><?php esc_html_e( 'Meldezeit', 'swim-timing' ); ?>
							<span class="swimtiming-time-input" data-name="report_time">00:00:00</span>
						</label>
					</div>
					<div class="swimtiming-form-row">
						<label><?php esc_html_e( 'Endzeit', 'swim-timing' ); ?>
							<span class="swimtiming-time-input" data-name="end_time">00:00:00</span>
						</label>
					</div>
					<p class="swimtiming-hint"><?php esc_html_e( 'Bei einer Staffel legt die Position fest, wer als Nächstes startet (wird beim Zuweisen einer Staffel automatisch vergeben, kann aber angepasst werden). Ändert sich die Start- oder Endzeit einer Person, wird die Startzeit der nächsten Person in derselben Staffel automatisch neu berechnet: Startzeit + Endzeit + 1 Minute.', 'swim-timing' ); ?></p>
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

				<h4 id="swimtiming-split-form-title"><?php esc_html_e( 'Zwischenzeit hinzufügen', 'swim-timing' ); ?></h4>
				<p class="swimtiming-hint"><?php esc_html_e( 'Einfach durchtippen: Minuten, dann Sekunden, dann Hundertstel, z. B. „24“ für 24 Minuten (24:00:00) oder „12345“ für 12 Minuten, 34,5 Sekunden (12:34:50). Danach Enter drücken.', 'swim-timing' ); ?></p>
				<form id="swimtiming-split-form">
					<input type="hidden" name="id" value="" />
					<input type="hidden" name="starter_id" value="" />
					<div class="swimtiming-form-row">
						<label><?php esc_html_e( 'Nummer', 'swim-timing' ); ?>
							<input type="number" name="split_number" min="1" />
						</label>
						<label><?php esc_html_e( 'Zeit', 'swim-timing' ); ?>
							<span class="swimtiming-time-input" data-name="split_time">00:00:00</span>
						</label>
					</div>
					<label class="swimtiming-full"><?php esc_html_e( 'Kommentar', 'swim-timing' ); ?>
						<input type="text" name="comment" />
					</label>
					<div class="swimtiming-form-actions">
						<button type="button" class="swimtiming-btn" id="swimtiming-split-cancel-edit" hidden><?php esc_html_e( 'Abbrechen', 'swim-timing' ); ?></button>
						<button type="submit" class="swimtiming-btn swimtiming-btn-primary" id="swimtiming-split-submit"><?php esc_html_e( '+ Hinzufügen', 'swim-timing' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_public_area() {
		?>
		<div id="swimtiming-public" class="swimtiming-public">
			<div class="swimtiming-card">
				<h3><?php esc_html_e( 'Startzeiten', 'swim-timing' ); ?></h3>
				<div class="swimtiming-table-wrap">
					<table class="swimtiming-table" id="swimtiming-schedule-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Staffel', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Name', 'swim-timing' ); ?></th>
								<th><?php esc_html_e( 'Startzeit', 'swim-timing' ); ?></th>
							</tr>
						</thead>
						<tbody id="swimtiming-schedule-tbody">
							<tr><td colspan="3" class="swimtiming-empty"><?php esc_html_e( 'Lade…', 'swim-timing' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<form id="swimtiming-lookup-form" class="swimtiming-card">
				<h3><?php esc_html_e( 'Meine Zeiten', 'swim-timing' ); ?></h3>
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
						<input type="time" name="start_time" class="swimtiming-clock-input" value="00:00" required />
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

	/**
	 * Unauthenticated entry mask reached via the QR code: search for a
	 * starter by name, pick them from the suggestions, then enter their
	 * time. No login required.
	 */
	private function render_entry_area() {
		?>
		<div id="swimtiming-entry" class="swimtiming-public">
			<form id="swimtiming-entry-form" class="swimtiming-card">
				<h3><?php esc_html_e( 'Zeit eintragen', 'swim-timing' ); ?></h3>
				<p class="swimtiming-hint"><?php esc_html_e( 'Namen eingeben und die passende Person aus den Vorschlägen auswählen, dann die Zeit eintippen.', 'swim-timing' ); ?></p>

				<label class="swimtiming-full swimtiming-autocomplete">
					<?php esc_html_e( 'Name', 'swim-timing' ); ?>
					<input type="text" id="swimtiming-entry-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Name eingeben…', 'swim-timing' ); ?>" />
					<div class="swimtiming-suggestions" id="swimtiming-entry-suggestions" hidden></div>
				</label>
				<input type="hidden" id="swimtiming-entry-starter-id" value="" />

				<div class="swimtiming-selected-starter" id="swimtiming-entry-selected" hidden>
					<?php esc_html_e( 'Ausgewählt:', 'swim-timing' ); ?> <strong id="swimtiming-entry-selected-name"></strong>
					<button type="button" class="swimtiming-btn swimtiming-btn-icon" id="swimtiming-entry-clear">&times;</button>
				</div>

				<label class="swimtiming-full">
					<?php esc_html_e( 'Art der Zeit', 'swim-timing' ); ?>
					<select name="time_type" id="swimtiming-entry-type">
						<option value="split"><?php esc_html_e( 'Zwischenzeit', 'swim-timing' ); ?></option>
						<option value="end"><?php esc_html_e( 'Endzeit', 'swim-timing' ); ?></option>
					</select>
				</label>

				<label class="swimtiming-full">
					<?php esc_html_e( 'Zeit', 'swim-timing' ); ?>
					<span class="swimtiming-time-input" data-name="entry_time">00:00:00</span>
				</label>

				<div class="swimtiming-form-actions">
					<button type="submit" class="swimtiming-btn swimtiming-btn-primary"><?php esc_html_e( 'Zeit eintragen', 'swim-timing' ); ?></button>
				</div>
			</form>

			<p class="swimtiming-import-ok" id="swimtiming-entry-success" hidden></p>
			<p class="swimtiming-error" id="swimtiming-entry-error" hidden></p>
		</div>
		<?php
	}
}
