<?php
/**
 * Familienmitgliedschaften.
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Eine Familie bündelt Mitglieder und bildet über den Elternverweis Verzweigungen ab:
 * Hauptmitglied → Partner → Kinder, Kinder können wiederum eigene Zweige haben.
 */
class SVM_Families {

	/**
	 * Mögliche Beziehungen innerhalb einer Familie.
	 *
	 * @return array
	 */
	public static function relations() {
		return apply_filters(
			'svm_family_relations',
			array(
				'haupt'   => __( 'Hauptmitglied', 'svm' ),
				'partner' => __( 'Partnerin / Partner', 'svm' ),
				'kind'    => __( 'Kind', 'svm' ),
				'sonstig' => __( 'weitere Person', 'svm' ),
			)
		);
	}

	/**
	 * Bezeichnung einer Beziehung.
	 *
	 * @param string $relation Beziehungsschlüssel.
	 * @return string
	 */
	public static function relation_label( $relation ) {
		$relations = self::relations();
		return isset( $relations[ $relation ] ) ? $relations[ $relation ] : '';
	}

	/**
	 * Abrechnungsarten einer Familie.
	 *
	 * @return array
	 */
	public static function billing_modes() {
		return array(
			'individual' => __( 'Jedes Mitglied zahlt selbst', 'svm' ),
			'primary'    => __( 'Hauptmitglied zahlt für alle', 'svm' ),
		);
	}

	/**
	 * Alle Familien.
	 *
	 * @return array
	 */
	public static function all() {
		return SVM_DB::all( 'family_groups', '', 'name ASC' );
	}

	/**
	 * Eine Familie.
	 *
	 * @param int $id Familien-ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return SVM_DB::get( 'family_groups', $id );
	}

	/**
	 * Auswahlliste.
	 *
	 * @return array
	 */
	public static function options() {
		$out = array( 0 => __( '— keine Familie —', 'svm' ) );

		foreach ( self::all() as $family ) {
			$out[ (int) $family['id'] ] = $family['name'];
		}

		return $out;
	}

	/**
	 * Speichert eine Familie.
	 *
	 * @param array $data Daten.
	 * @return int
	 */
	public static function save( array $data ) {
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		unset( $data['id'] );

		$data = wp_parse_args(
			$data,
			array(
				'name'              => '',
				'primary_member_id' => 0,
				'payment_method_id' => 0,
				'billing_mode'      => 'individual',
				'note'              => '',
			)
		);

		if ( '' === trim( $data['name'] ) && $data['primary_member_id'] ) {
			$data['name'] = sprintf(
				/* translators: %s: Name des Hauptmitglieds. */
				__( 'Familie %s', 'svm' ),
				SVM_Members::display_name( (int) $data['primary_member_id'] )
			);
		}

		if ( $id > 0 ) {
			SVM_DB::update( 'family_groups', $id, $data );
		} else {
			$data['created_at'] = SVM_DB::now();
			$id                 = SVM_DB::insert( 'family_groups', $data );
		}

		if ( $data['primary_member_id'] ) {
			self::add_member( $id, (int) $data['primary_member_id'], 'haupt', 0 );
		}

		self::renumber( $id );

		SVM_Audit::log( 'family', $id, 'saved', 'name', '', $data['name'] );

		return $id;
	}

	/**
	 * Löst eine Familie auf; die Mitglieder bleiben bestehen.
	 *
	 * @param int $id Familien-ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		$wpdb->update(
			SVM_DB::table( 'members' ),
			array(
				'family_group_id'  => 0,
				'family_position'  => 0,
				'parent_member_id' => 0,
				'relation_type'    => '',
			),
			array( 'family_group_id' => $id )
		); // phpcs:ignore WordPress.DB

		SVM_DB::delete( 'family_groups', $id );
		SVM_Audit::log( 'family', $id, 'deleted' );
	}

	/**
	 * Mitglieder einer Familie.
	 *
	 * @param int $family_id Familien-ID.
	 * @return array
	 */
	public static function members( $family_id ) {
		global $wpdb;

		if ( (int) $family_id <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . SVM_DB::table( 'members' ) . '
				WHERE family_group_id = %d AND deleted_at IS NULL
				ORDER BY family_position ASC, id ASC',
				(int) $family_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB

		return $rows ? $rows : array();
	}

	/**
	 * Baut den Familienbaum: Hauptmitglied und Partner auf oberster Ebene,
	 * darunter die Personen, die einem Mitglied zugeordnet sind.
	 *
	 * @param int $family_id Familien-ID.
	 * @return array Liste aus member, children.
	 */
	public static function tree( $family_id ) {
		$members = self::members( $family_id );

		if ( empty( $members ) ) {
			return array();
		}

		$by_parent = array();

		foreach ( $members as $member ) {
			$by_parent[ (int) $member['parent_member_id'] ][] = $member;
		}

		$ids = array();
		foreach ( $members as $member ) {
			$ids[] = (int) $member['id'];
		}

		// Verwaiste Zweige (Elternteil nicht in dieser Familie) auf die oberste Ebene holen.
		foreach ( $by_parent as $parent_id => $children ) {
			if ( 0 !== $parent_id && ! in_array( (int) $parent_id, $ids, true ) ) {
				$by_parent[0] = array_merge( isset( $by_parent[0] ) ? $by_parent[0] : array(), $children );
				unset( $by_parent[ $parent_id ] );
			}
		}

		return self::build_branch( $by_parent, 0, 0 );
	}

	/**
	 * Rekursiver Aufbau eines Zweigs.
	 *
	 * @param array $by_parent Mitglieder gruppiert nach Elternteil.
	 * @param int   $parent_id Aktueller Elternteil.
	 * @param int   $depth     Ebene.
	 * @return array
	 */
	private static function build_branch( array $by_parent, $parent_id, $depth ) {
		if ( empty( $by_parent[ $parent_id ] ) || $depth > 5 ) {
			return array();
		}

		$out = array();

		foreach ( $by_parent[ $parent_id ] as $member ) {
			$out[] = array(
				'member'   => $member,
				'depth'    => $depth,
				'children' => self::build_branch( $by_parent, (int) $member['id'], $depth + 1 ),
			);
		}

		return $out;
	}

	/**
	 * Flache Liste des Baums mit Einrückungstiefe.
	 *
	 * @param int $family_id Familien-ID.
	 * @return array
	 */
	public static function flat_tree( $family_id ) {
		$out = array();

		self::flatten( self::tree( $family_id ), $out );

		return $out;
	}

	/**
	 * Hilfsfunktion zum Verflachen.
	 *
	 * @param array $branch Zweig.
	 * @param array $out    Ergebnis.
	 * @return void
	 */
	private static function flatten( array $branch, array &$out ) {
		foreach ( $branch as $node ) {
			$out[] = array(
				'member' => $node['member'],
				'depth'  => $node['depth'],
			);
			self::flatten( $node['children'], $out );
		}
	}

	/**
	 * Nimmt ein Mitglied in eine Familie auf.
	 *
	 * @param int    $family_id Familien-ID.
	 * @param int    $member_id Mitglieds-ID.
	 * @param string $relation  Beziehung.
	 * @param int    $parent_id Übergeordnetes Mitglied (für Verzweigungen).
	 * @return void
	 */
	public static function add_member( $family_id, $member_id, $relation = 'sonstig', $parent_id = 0 ) {
		$member_id = (int) $member_id;
		$family_id = (int) $family_id;

		if ( $member_id <= 0 || $family_id <= 0 ) {
			return;
		}

		// Ein Mitglied darf nicht sich selbst untergeordnet sein.
		if ( (int) $parent_id === $member_id ) {
			$parent_id = 0;
		}

		SVM_Members::update(
			$member_id,
			array(
				'family_group_id'  => $family_id,
				'relation_type'    => $relation,
				'parent_member_id' => (int) $parent_id,
			)
		);

		self::renumber( $family_id );

		SVM_Audit::log( 'family', $family_id, 'member_added', 'member', '', $member_id );
	}

	/**
	 * Entfernt ein Mitglied aus der Familie; untergeordnete Personen rücken nach oben.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return void
	 */
	public static function remove_member( $member_id ) {
		global $wpdb;

		$member = SVM_Members::get( $member_id );

		if ( ! $member ) {
			return;
		}

		$family_id = (int) $member['family_group_id'];

		$wpdb->update(
			SVM_DB::table( 'members' ),
			array( 'parent_member_id' => (int) $member['parent_member_id'] ),
			array( 'parent_member_id' => (int) $member_id )
		); // phpcs:ignore WordPress.DB

		SVM_Members::update(
			$member_id,
			array(
				'family_group_id'  => 0,
				'family_position'  => 0,
				'parent_member_id' => 0,
				'relation_type'    => '',
			)
		);

		if ( $family_id ) {
			$family = self::get( $family_id );

			if ( $family && (int) $family['primary_member_id'] === (int) $member_id ) {
				SVM_DB::update( 'family_groups', $family_id, array( 'primary_member_id' => 0 ) );
			}

			self::renumber( $family_id );
		}

		SVM_Audit::log( 'family', $family_id, 'member_removed', 'member', '', $member_id );
	}

	/**
	 * Vergibt die Positionen innerhalb der Familie neu.
	 *
	 * Reihenfolge: Hauptmitglied, Partner, Kinder (nach Alter), weitere Personen.
	 * Zusätzlich wird die Kind-Position gezählt, damit Regeln wie „ab dem 3. Kind“ greifen.
	 *
	 * @param int $family_id Familien-ID.
	 * @return void
	 */
	public static function renumber( $family_id ) {
		$members = self::members( $family_id );

		if ( empty( $members ) ) {
			return;
		}

		$order = array( 'haupt' => 1, 'partner' => 2, 'kind' => 3, 'sonstig' => 4 );

		usort(
			$members,
			static function ( $a, $b ) use ( $order ) {
				$rank_a = isset( $order[ $a['relation_type'] ] ) ? $order[ $a['relation_type'] ] : 5;
				$rank_b = isset( $order[ $b['relation_type'] ] ) ? $order[ $b['relation_type'] ] : 5;

				if ( $rank_a !== $rank_b ) {
					return $rank_a - $rank_b;
				}

				// Innerhalb gleicher Beziehung: älteste Person zuerst.
				$birth_a = SVM_Fields::get_system_value( 'member', (int) $a['id'], 'birthdate' );
				$birth_b = SVM_Fields::get_system_value( 'member', (int) $b['id'], 'birthdate' );

				if ( $birth_a && $birth_b && $birth_a !== $birth_b ) {
					return strcmp( (string) $birth_a, (string) $birth_b );
				}

				return (int) $a['id'] - (int) $b['id'];
			}
		);

		$position = 0;

		foreach ( $members as $member ) {
			$position++;

			if ( (int) $member['family_position'] !== $position ) {
				SVM_DB::update( 'members', (int) $member['id'], array( 'family_position' => $position ) );
			}
		}
	}

	/**
	 * Position eines Mitglieds unter den Kindern seiner Familie.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return int 0, wenn das Mitglied kein Kind ist.
	 */
	public static function child_position( $member_id ) {
		$member = SVM_Members::get( $member_id );

		if ( ! $member || 'kind' !== $member['relation_type'] || ! $member['family_group_id'] ) {
			return 0;
		}

		$position = 0;

		foreach ( self::members( (int) $member['family_group_id'] ) as $candidate ) {
			if ( 'kind' !== $candidate['relation_type'] ) {
				continue;
			}

			$position++;

			if ( (int) $candidate['id'] === (int) $member_id ) {
				return $position;
			}
		}

		return 0;
	}

	/**
	 * Legt aus einem Mitglied heraus eine neue Familie an.
	 *
	 * @param int $member_id Mitglieds-ID.
	 * @return int Familien-ID.
	 */
	public static function create_from_member( $member_id ) {
		$family_id = self::save(
			array(
				'name'              => sprintf(
					/* translators: %s: Name des Mitglieds. */
					__( 'Familie %s', 'svm' ),
					SVM_Members::display_name( $member_id )
				),
				'primary_member_id' => (int) $member_id,
			)
		);

		return $family_id;
	}

	/**
	 * Zusammenfassung einer Familie für Listen.
	 *
	 * @param int $family_id Familien-ID.
	 * @return array count, open, names
	 */
	public static function summary( $family_id ) {
		$members = self::members( $family_id );
		$open    = 0.0;
		$names   = array();

		foreach ( $members as $member ) {
			$open   += SVM_Invoices::open_total( (int) $member['id'] );
			$names[] = SVM_Members::display_name( (int) $member['id'] );
		}

		return array(
			'count' => count( $members ),
			'open'  => round( $open, 2 ),
			'names' => $names,
		);
	}
}
