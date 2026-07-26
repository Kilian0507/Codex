<?php
/**
 * Mitgliederdatensätze.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kernspalten liegen in der Tabelle, alle fachlichen Stammdaten in svm_field_values.
 */
class SVM_Members {

	/**
	 * Ein Mitglied.
	 *
	 * @param int $id Mitglieds-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = SVM_DB::get( 'members', $id );
		return ( $row && null === $row['deleted_at'] ) ? $row : $row;
	}

	/**
	 * Mitglied zu einem WordPress-Benutzer.
	 *
	 * @param int $user_id Benutzer-ID.
	 * @return array|null
	 */
	public static function get_by_user( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'members' ) . ' WHERE wp_user_id = %d AND deleted_at IS NULL LIMIT 1',
				$user_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $row ? $row : null;
	}

	/**
	 * Mitglied anhand der Mitgliedsnummer.
	 *
	 * @param string $number Mitgliedsnummer.
	 * @return array|null
	 */
	public static function get_by_number( $number ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'members' ) . ' WHERE member_number = %s LIMIT 1',
				(string) $number
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $row ? $row : null;
	}

	/**
	 * Legt ein Mitglied an.
	 *
	 * @param array $data   Kerndaten.
	 * @param array $values Feldwerte (field_id => Wert).
	 * @return int Mitglieds-ID.
	 */
	public static function create( array $data = array(), array $values = array() ) {
		$status = SVM_Statuses::get_default();

		$data = wp_parse_args(
			$data,
			array(
				'member_number'     => '',
				'status_id'         => $status ? (int) $status['id'] : 0,
				'wp_user_id'        => 0,
				'family_group_id'   => 0,
				'family_position'   => 0,
				'payment_method_id' => self::default_payment_method_id(),
				'joined_at'         => gmdate( 'Y-m-d' ),
				'left_at'           => null,
			)
		);

		if ( '' === $data['member_number'] ) {
			$data['member_number'] = SVM_Number_Range::next( 'member' );
		}

		$data['created_at']        = SVM_DB::now();
		$data['status_changed_at'] = SVM_DB::now();

		$id = SVM_DB::insert( 'members', $data );

		if ( ! empty( $values ) ) {
			SVM_Fields::save_values( 'member', $id, $values, false );
		}

		SVM_Audit::log( 'member', $id, 'created' );

		do_action( 'svm_member_created', $id );

		return $id;
	}

	/**
	 * Aktualisiert die Kerndaten eines Mitglieds.
	 *
	 * @param int   $id   Mitglieds-ID.
	 * @param array $data Kerndaten.
	 * @return void
	 */
	public static function update( $id, array $data ) {
		$id     = (int) $id;
		$before = self::get( $id );

		unset( $data['id'], $data['created_at'] );
		$data['updated_at'] = SVM_DB::now();

		SVM_DB::update( 'members', $id, $data );

		if ( $before ) {
			foreach ( $data as $key => $value ) {
				if ( 'updated_at' === $key ) {
					continue;
				}
				if ( isset( $before[ $key ] ) && (string) $before[ $key ] !== (string) $value ) {
					SVM_Audit::log( 'member', $id, 'core_changed', $key, $before[ $key ], $value );
				}
			}
		}

		do_action( 'svm_member_updated', $id );
	}

	/**
	 * Markiert ein Mitglied als gelöscht (Finanzhistorie bleibt erhalten).
	 *
	 * @param int $id Mitglieds-ID.
	 * @return void
	 */
	public static function soft_delete( $id ) {
		SVM_DB::update( 'members', $id, array( 'deleted_at' => SVM_DB::now() ) );
		SVM_Audit::log( 'member', $id, 'deleted' );
	}

	/**
	 * Entfernt ein Mitglied endgültig samt Feldwerten.
	 *
	 * @param int $id Mitglieds-ID.
	 * @return void
	 */
	public static function hard_delete( $id ) {
		global $wpdb;

		$id = (int) $id;
		SVM_Fields::delete_values( 'member', $id );
		$wpdb->delete( SVM_DB::table( 'member_units' ), array( 'member_id' => $id ) ); // phpcs:ignore WordPress.DB
		SVM_DB::delete( 'members', $id );

		SVM_Audit::log( 'member', $id, 'purged' );
	}

	/**
	 * Ändert den Status unter Beachtung der konfigurierten Übergänge.
	 *
	 * @param int    $id            Mitglieds-ID.
	 * @param int    $new_status_id Zielstatus.
	 * @param string $reason        Begründung.
	 * @return true|WP_Error
	 */
	public static function change_status( $id, $new_status_id, $reason = '' ) {
		$member = self::get( $id );
		if ( ! $member ) {
			return new WP_Error( 'svm_member_missing', __( 'Mitglied nicht gefunden.', 'svm' ) );
		}

		$from = (int) $member['status_id'];
		$to   = (int) $new_status_id;

		if ( ! SVM_Statuses::transition_allowed( $from, $to ) ) {
			return new WP_Error(
				'svm_transition_denied',
				__( 'Dieser Statuswechsel ist in der Konfiguration nicht vorgesehen.', 'svm' )
			);
		}

		self::update(
			$id,
			array(
				'status_id'         => $to,
				'status_reason'     => $reason,
				'status_changed_at' => SVM_DB::now(),
			)
		);

		foreach ( SVM_Statuses::transitions( $from ) as $transition ) {
			if ( (int) $transition['to_status_id'] === $to && '' !== $transition['on_transition'] ) {
				SVM_Statuses::run_transition_action( $transition['on_transition'], $id );
			}
		}

		SVM_Audit::log( 'member', $id, 'status_changed', 'status_id', $from, $to );

		do_action( 'svm_member_status_changed', $id, $from, $to );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Abfragen
	 * ------------------------------------------------------------------ */

	/**
	 * Sucht Mitglieder.
	 *
	 * @param array $args status_id, unit_id, search, include_deleted, limit, offset, respect_scope, orderby.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status_id'       => 0,
				'unit_id'         => 0,
				'search'          => '',
				'include_deleted' => false,
				'limit'           => 50,
				'offset'          => 0,
				'respect_scope'   => true,
				'orderby'         => 'm.member_number',
				'order'           => 'ASC',
				'ids_only'        => false,
			)
		);

		$where  = array();
		$params = array();

		if ( ! $args['include_deleted'] ) {
			$where[] = 'm.deleted_at IS NULL';
		}

		if ( $args['status_id'] > 0 ) {
			$where[]  = 'm.status_id = %d';
			$params[] = (int) $args['status_id'];
		}

		if ( $args['unit_id'] > 0 ) {
			$where[]  = 'm.id IN (SELECT member_id FROM ' . SVM_DB::table( 'member_units' ) . ' WHERE unit_id = %d AND is_active = 1)';
			$params[] = (int) $args['unit_id'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(m.member_number LIKE %s OR m.id IN (
				SELECT entity_id FROM ' . SVM_DB::table( 'field_values' ) . '
				WHERE entity_type = %s AND value_text LIKE %s
			))';
			$params[] = $like;
			$params[] = 'member';
			$params[] = $like;
		}

		if ( $args['respect_scope'] ) {
			$where[] = SVM_Permissions::member_scope_sql( 'm' );
		}

		$where_sql = empty( $where ) ? '1=1' : implode( ' AND ', $where );

		$allowed_order = array( 'm.member_number', 'm.id', 'm.created_at', 'm.joined_at' );
		$orderby       = in_array( $args['orderby'], $allowed_order, true ) ? $args['orderby'] : 'm.member_number';
		$order         = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$select = $args['ids_only'] ? 'm.id' : 'm.*';
		$sql    = "SELECT {$select} FROM " . SVM_DB::table( 'members' ) . " m WHERE {$where_sql} ORDER BY {$orderby} {$order}";

		if ( $args['limit'] > 0 ) {
			$sql      .= ' LIMIT %d OFFSET %d';
			$params[]  = (int) $args['limit'];
			$params[]  = (int) $args['offset'];
		}

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB
		}

		if ( $args['ids_only'] ) {
			$rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB
			return $rows ? array_map( 'intval', $rows ) : array();
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Zählt Mitglieder für dieselben Filter.
	 *
	 * @param array $args Filter wie in query().
	 * @return int
	 */
	public static function count( array $args = array() ) {
		$args['limit']    = 0;
		$args['ids_only'] = true;

		return count( self::query( $args ) );
	}

	/* ---------------------------------------------------------------------
	 * Darstellung
	 * ------------------------------------------------------------------ */

	/**
	 * Anzeigename eines Mitglieds aus den Feldern mit Systemrolle.
	 *
	 * @param int $id Mitglieds-ID.
	 * @return string
	 */
	public static function display_name( $id ) {
		$display = SVM_Fields::get_system_value( 'member', $id, 'display_name' );
		if ( $display ) {
			return $display;
		}

		$first = SVM_Fields::get_system_value( 'member', $id, 'first_name' );
		$last  = SVM_Fields::get_system_value( 'member', $id, 'last_name' );
		$name  = trim( (string) $first . ' ' . (string) $last );

		if ( '' !== $name ) {
			return $name;
		}

		$member = self::get( $id );

		return $member ? $member['member_number'] : '#' . (int) $id;
	}

	/**
	 * E-Mail-Adresse eines Mitglieds.
	 *
	 * @param int $id Mitglieds-ID.
	 * @return string
	 */
	public static function email( $id ) {
		$email = SVM_Fields::get_system_value( 'member', $id, 'email' );

		if ( ! $email ) {
			$member = self::get( $id );
			if ( $member && $member['wp_user_id'] ) {
				$user  = get_userdata( (int) $member['wp_user_id'] );
				$email = $user ? $user->user_email : '';
			}
		}

		return (string) $email;
	}

	/**
	 * Alter zum Stichtag.
	 *
	 * @param int    $id             Mitglieds-ID.
	 * @param string $reference_date Stichtag (Y-m-d).
	 * @return int|null
	 */
	public static function age( $id, $reference_date = '' ) {
		$birthdate = SVM_Fields::get_system_value( 'member', $id, 'birthdate' );
		if ( ! $birthdate ) {
			return null;
		}

		$birth = strtotime( (string) $birthdate );
		if ( ! $birth ) {
			return null;
		}

		$reference = '' !== $reference_date ? strtotime( $reference_date ) : time();
		if ( ! $reference ) {
			$reference = time();
		}

		$birth_dt = new DateTime( gmdate( 'Y-m-d', $birth ) );
		$ref_dt   = new DateTime( gmdate( 'Y-m-d', $reference ) );

		return (int) $birth_dt->diff( $ref_dt )->y;
	}

	/* ---------------------------------------------------------------------
	 * Einheitenzuordnung
	 * ------------------------------------------------------------------ */

	/**
	 * Mitgliedschaften eines Mitglieds.
	 *
	 * @param int  $member_id   Mitglieds-ID.
	 * @param bool $only_active Nur aktive.
	 * @return array
	 */
	public static function memberships( $member_id, $only_active = true ) {
		global $wpdb;

		$sql = 'SELECT * FROM ' . SVM_DB::table( 'member_units' ) . ' WHERE member_id = %d';
		if ( $only_active ) {
			$sql .= ' AND is_active = 1';
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, (int) $member_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Einheiten-IDs eines Mitglieds.
	 *
	 * @param int  $member_id   Mitglieds-ID.
	 * @param bool $only_active Nur aktive.
	 * @return int[]
	 */
	public static function unit_ids( $member_id, $only_active = true ) {
		$out = array();
		foreach ( self::memberships( $member_id, $only_active ) as $row ) {
			$out[] = (int) $row['unit_id'];
		}
		return $out;
	}

	/**
	 * Setzt die Einheitenzuordnung neu.
	 *
	 * @param int   $member_id Mitglieds-ID.
	 * @param array $unit_ids  Einheiten-IDs.
	 * @return void
	 */
	public static function set_units( $member_id, array $unit_ids ) {
		global $wpdb;

		$member_id = (int) $member_id;
		$new       = array_values( array_unique( array_map( 'intval', $unit_ids ) ) );
		$current   = self::unit_ids( $member_id );

		foreach ( array_diff( $current, $new ) as $remove ) {
			$wpdb->update(
				SVM_DB::table( 'member_units' ),
				array(
					'is_active' => 0,
					'left_at'   => gmdate( 'Y-m-d' ),
				),
				array(
					'member_id' => $member_id,
					'unit_id'   => (int) $remove,
				)
			); // phpcs:ignore WordPress.DB
		}

		foreach ( array_diff( $new, $current ) as $add ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . SVM_DB::table( 'member_units' ) . ' WHERE member_id = %d AND unit_id = %d',
					$member_id,
					(int) $add
				)
			); // phpcs:ignore WordPress.DB

			if ( $existing ) {
				SVM_DB::update(
					'member_units',
					(int) $existing,
					array(
						'is_active' => 1,
						'left_at'   => null,
						'joined_at' => gmdate( 'Y-m-d' ),
					)
				);
			} else {
				SVM_DB::insert(
					'member_units',
					array(
						'member_id' => $member_id,
						'unit_id'   => (int) $add,
						'joined_at' => gmdate( 'Y-m-d' ),
						'is_active' => 1,
					)
				);
			}
		}

		if ( $current !== $new ) {
			SVM_Audit::log( 'member', $member_id, 'units_changed', 'units', implode( ',', $current ), implode( ',', $new ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Kontext für die Regel-Engine
	 * ------------------------------------------------------------------ */

	/**
	 * Baut den Auswertungskontext eines Mitglieds.
	 *
	 * @param int    $member_id      Mitglieds-ID.
	 * @param string $reference_date Stichtag für Altersregeln.
	 * @return array|null
	 */
	public static function context( $member_id, $reference_date = '' ) {
		$member = self::get( $member_id );
		if ( ! $member ) {
			return null;
		}

		$fields = SVM_Fields::values_by_key( 'member', $member_id );
		$units  = self::unit_ids( $member_id );

		return array(
			'member'          => $member,
			'member_id'       => (int) $member_id,
			'fields'          => $fields,
			'unit_ids'        => $units,
			'unit_count'      => count( $units ),
			'age'             => self::age( $member_id, $reference_date ),
			'status_id'       => (int) $member['status_id'],
			'family_group_id' => (int) $member['family_group_id'],
			'family_position' => (int) $member['family_position'],
			'reference_date'  => '' !== $reference_date ? $reference_date : gmdate( 'Y-m-d' ),
		);
	}

	/**
	 * Standard-Zahlart für neue Mitglieder.
	 *
	 * @return int
	 */
	private static function default_payment_method_id() {
		global $wpdb;

		$id = $wpdb->get_var(
			'SELECT id FROM ' . SVM_DB::table( 'payment_methods' ) . ' WHERE is_default = 1 AND is_active = 1 LIMIT 1'
		); // phpcs:ignore WordPress.DB

		return (int) $id;
	}

	/**
	 * Mitglieder eines Familienverbunds.
	 *
	 * @param int $family_group_id Familien-ID.
	 * @return array
	 */
	public static function family_members( $family_group_id ) {
		global $wpdb;

		if ( (int) $family_group_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'members' ) . '
				WHERE family_group_id = %d AND deleted_at IS NULL ORDER BY family_position ASC, id ASC',
				(int) $family_group_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}
}
