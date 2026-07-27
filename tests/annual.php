<?php
/**
 * Prüft den Jahreseinzug: mehrere Forderungen eines Mitglieds werden zu einer
 * Buchung gebündelt, die Summe bleibt erhalten.
 *
 * @package SVM
 */

define( 'ABSPATH', '/tmp/' );

/**
 * Übersetzungsstub.
 *
 * @param string $text Text.
 * @param string $domain Textdomain.
 * @return string
 */
function __( $text, $domain = null ) {
	unset( $domain );
	return $text;
}

/**
 * Filterstub.
 *
 * @param string $tag Hook.
 * @param mixed  $value Wert.
 * @return mixed
 */
function apply_filters( $tag, $value ) {
	unset( $tag );
	return $value;
}

/**
 * Argumentstub.
 *
 * @param array $args Argumente.
 * @param array $defaults Vorgaben.
 * @return array
 */
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}

/** Stub für Mitgliedsdaten. */
class SVM_Members {
	/**
	 * Mitglied.
	 *
	 * @param int $id ID.
	 * @return array
	 */
	public static function get( $id ) {
		return array( 'member_number' => 'SV-2026-9000' . $id );
	}
}

/** Stub für den Zeichensatz der Referenz. */
class SVM_SEPA_Generator {
	/**
	 * Kürzt und bereinigt.
	 *
	 * @param string $value Text.
	 * @param int    $length Länge.
	 * @return string
	 */
	public static function text( $value, $length = 140 ) {
		return substr( preg_replace( "/[^A-Za-z0-9\/\-?:().,'+ ]/", ' ', $value ), 0, $length );
	}
}

/** Stub für den Verwendungszweck. */
class SVM_File_Profiles {
	/**
	 * Verwendungszweck eines Jahreseinzugs.
	 *
	 * @param array $profile Profil.
	 * @param int   $member_id Mitglied.
	 * @param int   $year Jahr.
	 * @param float $amount Betrag.
	 * @return string
	 */
	public static function render_annual_purpose( array $profile, $member_id, $year, $amount ) {
		unset( $profile, $amount );
		return 'Jahresbeitrag ' . $year . ' Mitglied ' . $member_id;
	}
}

require dirname( __DIR__ ) . '/schwimmverein-mitgliederverwaltung/includes/payments/class-svm-payment-runs.php';

$fails = 0;

/**
 * Vergleicht Ist und Soll.
 *
 * @param string $label Bezeichnung.
 * @param mixed  $actual Ist.
 * @param mixed  $expected Soll.
 * @return void
 */
function check( $label, $actual, $expected ) {
	global $fails;
	$ok = $actual === $expected;

	if ( ! $ok ) {
		$fails++;
	}

	printf( "%-52s %s%s\n", $label, $ok ? 'OK' : 'FEHL', $ok ? '' : '  -> ' . var_export( $actual, true ) );
}

/**
 * Ruft eine nicht öffentliche Methode auf.
 *
 * @param string $method Methodenname.
 * @param array  $args Argumente.
 * @return mixed
 */
function call_private( $method, array $args ) {
	$ref = new ReflectionMethod( 'SVM_Payment_Runs', $method );
	$ref->setAccessible( true );

	return $ref->invokeArgs( null, $args );
}

/**
 * Baut eine Beispielposition.
 *
 * @param int    $member_id Mitglied.
 * @param int    $invoice_id Forderung.
 * @param float  $amount Betrag.
 * @return array
 */
function item( $member_id, $invoice_id, $amount ) {
	return array(
		'invoice_id'    => $invoice_id,
		'member_id'     => $member_id,
		'member_name'   => 'Mitglied ' . $member_id,
		'amount'        => $amount,
		'purpose'       => 'Einzelbeitrag',
		'end_to_end_id' => 'E2E-' . $invoice_id,
		'name'          => 'Mitglied ' . $member_id,
		'mandate_id'    => $member_id,
		'mandate_ref'   => 'MND-00000' . $member_id,
		'iban'          => 'DE28888888881000100200',
		'bic'           => 'TESTDEFFXXX',
		'signed_at'     => '2024-01-01',
		'sequence_type' => 'RCUR',
	);
}

// Mitglied 1 hat drei Beiträge, Mitglied 2 einen.
$items = array(
	item( 1, 101, 25.00 ),
	item( 1, 102, 72.00 ),
	item( 1, 103, 60.00 ),
	item( 2, 201, 120.00 ),
);

$profile = array( 'purpose_template' => '', 'label' => 'Test' );

$grouped = call_private( 'group_by_member', array( $items ) );
check( 'Gruppierung liefert zwei Mitglieder', count( $grouped ), 2 );
check( 'Mitglied 1 hat drei Forderungen', count( $grouped[1] ), 3 );

$aggregated = call_private( 'aggregate_items', array( $items, $profile, 2026 ) );

check( 'Gebündelt: eine Buchung je Mitglied', count( $aggregated ), 2 );
check( 'Mitglied 1: 25 + 72 + 60', $aggregated[0]['amount'], 157.00 );
check( 'Mitglied 2: unveraendert', $aggregated[1]['amount'], 120.00 );
check( 'Anzahl gebuendelter Forderungen vermerkt', $aggregated[0]['invoice_count'], 3 );
check( 'Mandat bleibt erhalten', $aggregated[0]['mandate_ref'], 'MND-000001' );
check( 'Sequenztyp bleibt erhalten', $aggregated[0]['sequence_type'], 'RCUR' );
check( 'Jahresreferenz statt Einzelreferenz', $aggregated[0]['end_to_end_id'], 'SV-2026-90001-J2026' );
check( 'Verwendungszweck nennt das Jahr', $aggregated[0]['purpose'], 'Jahresbeitrag 2026 Mitglied 1' );

// Die Gesamtsumme darf sich durch das Bündeln nicht ändern.
$sum_single = 0.0;
foreach ( $items as $i ) {
	$sum_single += $i['amount'];
}

$sum_grouped = 0.0;
foreach ( $aggregated as $a ) {
	$sum_grouped += $a['amount'];
}

check( 'Summe bleibt gleich', round( $sum_grouped, 2 ), round( $sum_single, 2 ) );

// Rundung: Centbeträge dürfen nicht driften.
$cents = array( item( 3, 301, 10.005 ), item( 3, 302, 10.005 ) );
$agg   = call_private( 'aggregate_items', array( $cents, $profile, 2026 ) );
check( 'Centbetraege werden auf zwei Stellen gerundet', $agg[0]['amount'], 20.01 );

echo $fails === 0 ? "\nJahreseinzug buendelt korrekt.\n" : "\n$fails Test(s) fehlgeschlagen.\n";

exit( $fails === 0 ? 0 : 1 );
