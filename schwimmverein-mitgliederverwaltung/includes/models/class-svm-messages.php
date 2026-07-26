<?php
/**
 * Nachrichten und Mitteilungen.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zielgruppen sind Regeln, keine festen Listen.
 */
class SVM_Messages {

	/* ---------------------------------------------------------------------
	 * Kategorien
	 * ------------------------------------------------------------------ */

	/**
	 * Alle Kategorien.
	 *
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function categories( $only_active = false ) {
		$where = $only_active ? 'is_active = 1' : '';
		return SVM_DB::all( 'message_categories', $where, 'sort_order ASC, id ASC' );
	}

	/**
	 * Kategorien als Auswahlliste.
	 *
	 * @return array
	 */
	public static function category_options() {
		$out = array( 0 => __( '— ohne Kategorie —', 'svm' ) );

		foreach ( self::categories( true ) as $category ) {
			$out[ (int) $category['id'] ] = $category['label'];
		}

		return $out;
	}

	/**
	 * Speichert eine Kategorie.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save_category( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'label'        => '',
				'color'        => '',
				'is_important' => 0,
				'send_email'   => 0,
				'is_active'    => 1,
				'sort_order'   => 0,
			)
		);

		if ( $id > 0 ) {
			SVM_DB::update( 'message_categories', $id, $data );
			return $id;
		}

		return SVM_DB::insert( 'message_categories', $data );
	}

	/**
	 * Löscht eine Kategorie.
	 *
	 * @param int $id Kategorie-ID.
	 * @return void
	 */
	public static function delete_category( $id ) {
		SVM_DB::delete( 'message_categories', $id );
	}

	/* ---------------------------------------------------------------------
	 * Nachrichten
	 * ------------------------------------------------------------------ */

	/**
	 * Eine Nachricht.
	 *
	 * @param int $id Nachrichten-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'messages', $id );
	}

	/**
	 * Alle Nachrichten.
	 *
	 * @param int $limit Anzahl.
	 * @return array
	 */
	public static function all( $limit = 100 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'messages' ) . ' ORDER BY created_at DESC LIMIT %d',
				(int) $limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Speichert eine Nachricht samt Zielgruppen.
	 *
	 * @param array $data    Daten.
	 * @param array $targets Zielgruppen (target_type, target_id).
	 * @return int
	 */
	public static function save( array $data, array $targets = null ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'category_id'  => 0,
				'title'        => '',
				'body'         => '',
				'visible_from' => null,
				'visible_to'   => null,
				'is_important' => 0,
				'requires_ack' => 0,
				'send_email'   => 0,
				'status'       => 'draft',
			)
		);

		if ( $id > 0 ) {
			SVM_DB::update( 'messages', $id, $data );
		} else {
			$data['created_by'] = get_current_user_id();
			$data['created_at'] = SVM_DB::now();
			$id                 = SVM_DB::insert( 'messages', $data );
		}

		if ( null !== $targets ) {
			self::set_targets( $id, $targets );
		}

		return $id;
	}

	/**
	 * Löscht eine Nachricht.
	 *
	 * @param int $id Nachrichten-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;
		$wpdb->delete( SVM_DB::table( 'message_targets' ), array( 'message_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( SVM_DB::table( 'message_reads' ), array( 'message_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'messages', $id );
	}

	/**
	 * Zielgruppen einer Nachricht.
	 *
	 * @param int $message_id Nachrichten-ID.
	 * @return array
	 */
	public static function targets( $message_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'message_targets' ) . ' WHERE message_id = %d',
				(int) $message_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Setzt die Zielgruppen neu.
	 *
	 * @param int   $message_id Nachrichten-ID.
	 * @param array $targets    Zielgruppen.
	 * @return void
	 */
	public static function set_targets( $message_id, array $targets ) {
		global $wpdb;

		$message_id = (int) $message_id;
		$wpdb->delete( SVM_DB::table( 'message_targets' ), array( 'message_id' => $message_id ) ); // phpcs:ignore WordPress.DB

		foreach ( $targets as $target ) {
			if ( empty( $target['target_type'] ) ) {
				continue;
			}

			SVM_DB::insert(
				'message_targets',
				array(
					'message_id'  => $message_id,
					'target_type' => sanitize_key( $target['target_type'] ),
					'target_id'   => isset( $target['target_id'] ) ? (int) $target['target_id'] : 0,
				)
			);
		}
	}

	/**
	 * Arten von Zielgruppen.
	 *
	 * @return array
	 */
	public static function target_types() {
		return array(
			'all'  => __( 'Alle Mitglieder', 'svm' ),
			'unit' => __( 'Bestimmte Sparte/Gruppe', 'svm' ),
			'role' => __( 'Bestimmte Rolle', 'svm' ),
			'rule' => __( 'Regel (z. B. alle mit offener Forderung)', 'svm' ),
		);
	}

	/**
	 * Prüft, ob eine Nachricht für ein Mitglied sichtbar ist.
	 *
	 * @param array $message   Nachricht.
	 * @param int   $member_id Mitglieds-ID.
	 * @return bool
	 */
	public static function is_visible_for( array $message, $member_id ) {
		if ( 'published' !== $message['status'] ) {
			return false;
		}

		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		if ( ! empty( $message['visible_from'] ) && strtotime( $message['visible_from'] ) > $now ) {
			return false;
		}

		if ( ! empty( $message['visible_to'] ) && strtotime( $message['visible_to'] ) < $now ) {
			return false;
		}

		$targets = self::targets( (int) $message['id'] );

		if ( empty( $targets ) ) {
			return true;
		}

		$member = SVM_Members::get( $member_id );
		if ( ! $member ) {
			return false;
		}

		foreach ( $targets as $target ) {
			switch ( $target['target_type'] ) {
				case 'all':
					return true;

				case 'unit':
					if ( in_array( (int) $target['target_id'], SVM_Members::unit_ids( $member_id ), true ) ) {
						return true;
					}
					break;

				case 'role':
					if ( $member['wp_user_id'] && in_array( (int) $target['target_id'], SVM_Roles::user_role_ids( (int) $member['wp_user_id'] ), true ) ) {
						return true;
					}
					break;

				case 'rule':
					$rule = SVM_Rules::get( (int) $target['target_id'] );
					if ( $rule ) {
						$context = SVM_Members::context( $member_id );
						if ( $context && SVM_Rule_Engine::matches( $rule, $context ) ) {
							return true;
						}
					}
					break;
			}
		}

		return false;
	}

	/**
	 * Nachrichten, die ein Mitglied sehen darf.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @param int $limit     Anzahl.
	 * @return array
	 */
	public static function for_member( $member_id, $limit = 50 ) {
		global $wpdb;

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'messages' ) . '
				WHERE status = %s ORDER BY is_important DESC, created_at DESC LIMIT %d',
				'published',
				(int) $limit * 3
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		$out = array();

		foreach ( (array) $candidates as $message ) {
			if ( self::is_visible_for( $message, $member_id ) ) {
				$out[] = $message;
			}

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Empfänger einer Nachricht.
	 *
	 * @param int $message_id Nachrichten-ID.
	 * @return int[] Mitglieds-IDs.
	 */
	public static function recipients( $message_id ) {
		$message = self::get( $message_id );

		if ( ! $message ) {
			return array();
		}

		$member_ids = SVM_Members::query(
			array(
				'limit'         => 0,
				'ids_only'      => true,
				'respect_scope' => false,
			)
		);

		$out = array();

		foreach ( $member_ids as $member_id ) {
			if ( self::is_visible_for( $message, $member_id ) ) {
				$out[] = (int) $member_id;
			}
		}

		return $out;
	}

	/**
	 * Merkt eine Nachricht als gelesen vor.
	 *
	 * @param int $message_id Nachrichten-ID.
	 * @param int $member_id  Mitglieds-ID.
	 * @return void
	 */
	public static function mark_read( $message_id, $member_id ) {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . SVM_DB::table( 'message_reads' ) . ' WHERE message_id = %d AND member_id = %d',
				(int) $message_id,
				(int) $member_id
			)
		); // phpcs:ignore WordPress.DB

		if ( $exists ) {
			return;
		}

		SVM_DB::insert(
			'message_reads',
			array(
				'message_id' => (int) $message_id,
				'member_id'  => (int) $member_id,
				'read_at'    => SVM_DB::now(),
			)
		);
	}

	/**
	 * Anzahl der Lesebestätigungen.
	 *
	 * @param int $message_id Nachrichten-ID.
	 * @return int
	 */
	public static function read_count( $message_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . SVM_DB::table( 'message_reads' ) . ' WHERE message_id = %d',
				(int) $message_id
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Versendet ausstehende Nachrichten-E-Mails im Hintergrund.
	 *
	 * @param int $batch_size Anzahl Nachrichten pro Durchlauf.
	 * @return int Anzahl versendeter E-Mails.
	 */
	public static function process_mail_queue( $batch_size = 3 ) {
		global $wpdb;

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'messages' ) . '
				WHERE status = %s AND send_email = 1 AND email_sent_at IS NULL LIMIT %d',
				'published',
				(int) $batch_size
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		$sent = 0;

		foreach ( (array) $messages as $message ) {
			foreach ( self::recipients( (int) $message['id'] ) as $member_id ) {
				$email = SVM_Members::email( $member_id );

				if ( '' === $email ) {
					continue;
				}

				$body = '<p>' . esc_html( SVM_Members::display_name( $member_id ) ) . ',</p>' . wpautop( $message['body'] );

				wp_mail(
					$email,
					$message['title'],
					$body,
					array( 'Content-Type: text/html; charset=UTF-8' )
				);

				$sent++;
			}

			SVM_DB::update( 'messages', (int) $message['id'], array( 'email_sent_at' => SVM_DB::now() ) );
		}

		return $sent;
	}
}
