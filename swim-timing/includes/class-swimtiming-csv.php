<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV import helpers for starters and splits.
 */
class SwimTiming_CSV {

	private static function detect_delimiter( $line ) {
		$semi = substr_count( $line, ';' );
		$comma = substr_count( $line, ',' );
		return $semi >= $comma ? ';' : ',';
	}

	private static function read_rows( $file_path ) {
		$rows = array();
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return $rows;
		}

		$first_line = fgets( $handle );
		if ( false === $first_line ) {
			fclose( $handle );
			return $rows;
		}
		// Strip UTF-8 BOM if present.
		$first_line = preg_replace( '/^\xEF\xBB\xBF/', '', $first_line );
		$delimiter = self::detect_delimiter( $first_line );

		rewind( $handle );
		$is_first = true;
		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			if ( $is_first ) {
				$is_first = false;
				// Skip header row if it looks like text (non-numeric first cell for splits, or contains "vorname"/"name").
				$joined = strtolower( implode( ' ', $row ) );
				if ( false !== strpos( $joined, 'vorname' ) || false !== strpos( $joined, 'nachname' ) || false !== strpos( $joined, 'name' ) || false !== strpos( $joined, 'zeit' ) || false !== strpos( $joined, 'nummer' ) ) {
					continue;
				}
			}
			if ( count( $row ) === 1 && '' === trim( (string) $row[0] ) ) {
				continue;
			}
			$rows[] = $row;
		}
		fclose( $handle );
		return $rows;
	}

	/**
	 * Import starters: Vorname, Nachname, Meldezeit, Startzeit
	 */
	public static function import_starters( $file_path ) {
		$rows = self::read_rows( $file_path );
		$imported = 0;
		$errors = array();

		foreach ( $rows as $i => $row ) {
			$first_name = isset( $row[0] ) ? trim( $row[0] ) : '';
			$last_name  = isset( $row[1] ) ? trim( $row[1] ) : '';
			$report     = isset( $row[2] ) ? trim( $row[2] ) : '';
			$start      = isset( $row[3] ) ? trim( $row[3] ) : '';

			if ( '' === $first_name || '' === $last_name ) {
				$errors[] = sprintf( __( 'Zeile %d: Vor- oder Nachname fehlt.', 'swim-timing' ), $i + 1 );
				continue;
			}

			SwimTiming_DB::insert_starter( array(
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'report_time' => $report,
				'start_time'  => $start,
			) );
			$imported++;
		}

		return array( 'imported' => $imported, 'errors' => $errors, 'total' => count( $rows ) );
	}

	/**
	 * Import splits: Nummer, Vorname, Nachname, Zeit
	 */
	public static function import_splits( $file_path ) {
		$rows = self::read_rows( $file_path );
		$imported = 0;
		$errors = array();

		foreach ( $rows as $i => $row ) {
			$number     = isset( $row[0] ) ? trim( $row[0] ) : '';
			$first_name = isset( $row[1] ) ? trim( $row[1] ) : '';
			$last_name  = isset( $row[2] ) ? trim( $row[2] ) : '';
			$time       = isset( $row[3] ) ? trim( $row[3] ) : '';

			if ( '' === $first_name || '' === $last_name || '' === $time ) {
				$errors[] = sprintf( __( 'Zeile %d: Vorname, Nachname oder Zeit fehlt.', 'swim-timing' ), $i + 1 );
				continue;
			}

			$starter = SwimTiming_DB::find_starter_by_name( $first_name, $last_name );
			if ( ! $starter ) {
				$errors[] = sprintf( __( 'Zeile %d: Startperson "%s %s" nicht gefunden.', 'swim-timing' ), $i + 1, $first_name, $last_name );
				continue;
			}

			$split_number = is_numeric( $number ) ? (int) $number : SwimTiming_DB::get_next_split_number( $starter['id'] );

			SwimTiming_DB::insert_split( array(
				'starter_id'   => $starter['id'],
				'split_number' => $split_number,
				'split_time'   => $time,
				'comment'      => '',
			) );
			$imported++;
		}

		return array( 'imported' => $imported, 'errors' => $errors, 'total' => count( $rows ) );
	}
}
