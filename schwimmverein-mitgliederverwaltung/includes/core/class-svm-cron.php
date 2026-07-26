<?php
/**
 * Zeitgesteuerte Aufgaben.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tägliche Prüfungen und Hintergrundversand.
 */
class SVM_Cron {

	const HOOK_DAILY = 'svm_daily_tasks';
	const HOOK_MAIL  = 'svm_mail_queue';

	/**
	 * Plant die Ereignisse.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
		}
		if ( ! wp_next_scheduled( self::HOOK_MAIL ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK_MAIL );
		}
	}

	/**
	 * Entfernt die Ereignisse.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK_DAILY );
		wp_clear_scheduled_hook( self::HOOK_MAIL );
	}

	/**
	 * Tägliche Aufgaben: Mahnstufen und Mandatsverfall.
	 *
	 * @return void
	 */
	public static function run_daily() {
		SVM_Dunning::run_automatic();
		SVM_Mandates::expire_stale();
	}

	/**
	 * Versendet ausstehende Nachrichten-E-Mails.
	 *
	 * @return void
	 */
	public static function run_mail_queue() {
		SVM_Messages::process_mail_queue();
	}
}
