<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses pasted spreadsheet tables (copy/paste from Excel, Google Sheets, ...)
 * for starters and splits. Auto-detects tab / semicolon / comma as column
 * separator so a direct paste "just works" without any file upload.
 */
class SwimTiming_CSV {

	private static function detect_delimiter( $line ) {
		$tab = substr_count( $line, "\t" );
		if ( $tab > 0 ) {
			return "\t";
		}
		$semi = substr_count( $line, ';' );
		$comma = substr_count( $line, ',' );
		return $semi >= $comma ? ';' : ',';
	}

	private static function parse_rows( $text ) {
		$rows = array();
		$text = (string) $text;
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', $text ); // Strip UTF-8 BOM.
		$lines = preg_split( '/\r\n|\r|\n/', $text );

		$first_non_empty = '';
		foreach ( $lines as $line ) {
			if ( '' !== trim( $line ) ) {
				$first_non_empty = $line;
				break;
			}
		}
		if ( '' === $first_non_empty ) {
			return $rows;
		}
		$delimiter = self::detect_delimiter( $first_non_empty );

		$is_first = true;
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$row = str_getcsv( $line, $delimiter );
			$row = array_map( 'trim', $row );

			if ( $is_first ) {
				$is_first = false;
				$joined = strtolower( implode( ' ', $row ) );
				if ( false !== strpos( $joined, 'vorname' ) || false !== strpos( $joined, 'nachname' ) || false !== strpos( $joined, 'name' ) || false !== strpos( $joined, 'zeit' ) || false !== strpos( $joined, 'nummer' ) ) {
					continue;
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Import starters: Vorname, Nachname, Meldezeit, Startzeit, Staffel
	 */
	public static function import_starters( $text ) {
		$rows = self::parse_rows( $text );
		$imported = 0;
		$errors = array();

		foreach ( $rows as $i => $row ) {
			$first_name = isset( $row[0] ) ? $row[0] : '';
			$last_name  = isset( $row[1] ) ? $row[1] : '';
			$report     = isset( $row[2] ) ? $row[2] : '';
			$start      = isset( $row[3] ) ? $row[3] : '';
			$team       = isset( $row[4] ) ? $row[4] : '';

			if ( '' === $first_name || '' === $last_name ) {
				$errors[] = sprintf( __( 'Zeile %d: Vor- oder Nachname fehlt.', 'swim-timing' ), $i + 1 );
				continue;
			}

			SwimTiming_DB::insert_starter( array(
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'report_time' => $report,
				'start_time'  => $start,
				'team'        => $team,
			) );
			$imported++;
		}

		return array( 'imported' => $imported, 'errors' => $errors, 'total' => count( $rows ) );
	}

	/**
	 * Import splits: Nummer, Vorname, Nachname, Zeit
	 */
	public static function import_splits( $text ) {
		$rows = self::parse_rows( $text );
		$imported = 0;
		$errors = array();

		foreach ( $rows as $i => $row ) {
			$number     = isset( $row[0] ) ? $row[0] : '';
			$first_name = isset( $row[1] ) ? $row[1] : '';
			$last_name  = isset( $row[2] ) ? $row[2] : '';
			$time       = isset( $row[3] ) ? $row[3] : '';

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
