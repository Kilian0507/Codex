<?php
/**
 * Prüft die Tabellendefinitionen gegen die Eigenheiten von dbDelta().
 *
 * Hintergrund: dbDelta() zerlegt eine übergebene Zeichenkette an Semikolons.
 * Ein Semikolon in einem Spaltenstandardwert zerschneidet die Anweisung —
 * die Tabelle entsteht dann stillschweigend nicht.
 *
 * @package SVM
 */

define( 'ABSPATH', '/tmp/' );

// Minimale Ersatzfunktionen, damit die Schemaklasse ohne WordPress läuft.
class SVM_Test_Wpdb {
	public $prefix = 'wp_';

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
}

$GLOBALS['wpdb'] = new SVM_Test_Wpdb();

require dirname( __DIR__ ) . '/schwimmverein-mitgliederverwaltung/includes/core/class-svm-schema.php';

$fails = 0;

/**
 * Meldet einen Fehler.
 *
 * @param string $message Meldung.
 * @return void
 */
function svm_fail( $message ) {
	global $fails;
	$fails++;
	echo "  FEHLER: $message\n";
}

$tables = SVM_Schema::tables();

printf( "Tabellendefinitionen: %d\n\n", count( $tables ) );

foreach ( $tables as $sql ) {
	preg_match( '/CREATE TABLE (\S+)/', $sql, $name_match );
	$name = isset( $name_match[1] ) ? $name_match[1] : '(unbekannt)';

	// 1. Genau ein Semikolon, und zwar am Ende.
	$semicolons = substr_count( $sql, ';' );

	if ( 1 !== $semicolons ) {
		svm_fail( "$name enthält $semicolons Semikolons — dbDelta() würde die Anweisung zerschneiden" );
	}

	if ( ';' !== substr( rtrim( $sql ), -1 ) ) {
		svm_fail( "$name endet nicht mit einem Semikolon" );
	}

	// 2. dbDelta verlangt zwei Leerzeichen nach PRIMARY KEY.
	if ( false !== strpos( $sql, 'PRIMARY KEY' ) && ! preg_match( '/PRIMARY KEY {2}\(/', $sql ) ) {
		svm_fail( "$name: PRIMARY KEY braucht zwei Leerzeichen vor der Klammer" );
	}

	// 3. dbDelta kennt kein INDEX, nur KEY.
	if ( preg_match( '/^\s*INDEX /mi', $sql ) ) {
		svm_fail( "$name verwendet INDEX statt KEY" );
	}

	// 4. Klammern müssen aufgehen.
	if ( substr_count( $sql, '(' ) !== substr_count( $sql, ')' ) ) {
		svm_fail( "$name: unausgeglichene Klammern" );
	}
}

// 5. Die Namensliste muss zu den Definitionen passen.
$names = SVM_Schema::table_names();

if ( count( $names ) !== count( $tables ) ) {
	svm_fail(
		sprintf(
			'table_names() liefert %d Namen, es gibt aber %d Definitionen',
			count( $names ),
			count( $tables )
		)
	);
}

foreach ( $names as $name ) {
	if ( '' === $name ) {
		svm_fail( 'Leerer Tabellenname in table_names()' );
	}
}

printf( "Tabellennamen erkannt: %d\n", count( $names ) );

// 6. Gegenprobe: So würde dbDelta() eine Zeichenkette zerlegen.
foreach ( $tables as $sql ) {
	$parts = array_filter( explode( ';', $sql ) );

	if ( count( $parts ) > 1 ) {
		preg_match( '/CREATE TABLE (\S+)/', $sql, $m );
		svm_fail( ( isset( $m[1] ) ? $m[1] : '?' ) . ' zerfällt beim Zerlegen in ' . count( $parts ) . ' Teile' );
	}
}

echo $fails === 0
	? "\nSchema ist dbDelta-tauglich.\n"
	: "\n$fails Problem(e).\n";

exit( $fails === 0 ? 0 : 1 );
