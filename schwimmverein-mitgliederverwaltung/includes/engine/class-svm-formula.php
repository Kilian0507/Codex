<?php
/**
 * Auswertung von Formeln ohne eval().
 *
 * @package SVM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kleiner Ausdrucksparser für Betrags- und Berechnungsfelder.
 *
 * Unterstützt Zahlen, {feldschluessel}, + - * / ( ) sowie die Funktionen
 * min, max, round, floor, ceil und abs.
 */
class SVM_Formula {

	/**
	 * Tokenliste.
	 *
	 * @var array
	 */
	private $tokens = array();

	/**
	 * Aktuelle Position.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * Variablenwerte.
	 *
	 * @var array
	 */
	private $vars = array();

	/**
	 * Wertet eine Formel aus.
	 *
	 * @param string $expression Formel.
	 * @param array  $vars       Variablen (Schlüssel => Wert).
	 * @return float|WP_Error
	 */
	public static function evaluate( $expression, array $vars = array() ) {
		$parser = new self();

		try {
			return $parser->run( (string) $expression, $vars );
		} catch ( Exception $e ) {
			return new WP_Error( 'svm_formula_error', $e->getMessage() );
		}
	}

	/**
	 * Führt die Auswertung aus.
	 *
	 * @param string $expression Formel.
	 * @param array  $vars       Variablen.
	 * @return float
	 * @throws Exception Bei Syntaxfehlern.
	 */
	private function run( $expression, array $vars ) {
		$this->vars   = $vars;
		$this->tokens = $this->tokenize( $expression );
		$this->pos    = 0;

		if ( empty( $this->tokens ) ) {
			return 0.0;
		}

		$value = $this->parse_expression();

		if ( $this->pos < count( $this->tokens ) ) {
			throw new Exception( __( 'Unerwartetes Zeichen in der Formel.', 'svm' ) );
		}

		return (float) $value;
	}

	/**
	 * Zerlegt die Formel in Token.
	 *
	 * @param string $expression Formel.
	 * @return array
	 * @throws Exception Bei ungültigen Zeichen.
	 */
	private function tokenize( $expression ) {
		$tokens = array();
		$length = strlen( $expression );
		$i      = 0;

		while ( $i < $length ) {
			$char = $expression[ $i ];

			if ( ctype_space( $char ) ) {
				$i++;
				continue;
			}

			if ( strpos( '+-*/(),', $char ) !== false ) {
				$tokens[] = array( 'type' => $char );
				$i++;
				continue;
			}

			if ( ctype_digit( $char ) || '.' === $char ) {
				$number = '';
				while ( $i < $length && ( ctype_digit( $expression[ $i ] ) || '.' === $expression[ $i ] ) ) {
					$number .= $expression[ $i ];
					$i++;
				}
				$tokens[] = array( 'type' => 'number', 'value' => (float) $number );
				continue;
			}

			if ( '{' === $char ) {
				$end = strpos( $expression, '}', $i );
				if ( false === $end ) {
					throw new Exception( __( 'Nicht geschlossene Feldreferenz in der Formel.', 'svm' ) );
				}
				$name     = substr( $expression, $i + 1, $end - $i - 1 );
				$tokens[] = array( 'type' => 'var', 'value' => trim( $name ) );
				$i        = $end + 1;
				continue;
			}

			if ( ctype_alpha( $char ) || '_' === $char ) {
				$name = '';
				while ( $i < $length && ( ctype_alnum( $expression[ $i ] ) || '_' === $expression[ $i ] ) ) {
					$name .= $expression[ $i ];
					$i++;
				}
				$tokens[] = array( 'type' => 'func', 'value' => strtolower( $name ) );
				continue;
			}

			throw new Exception(
				sprintf(
					/* translators: %s: unerlaubtes Zeichen. */
					__( 'Unerlaubtes Zeichen „%s“ in der Formel.', 'svm' ),
					$char
				)
			);
		}

		return $tokens;
	}

	/**
	 * Aktuelles Token.
	 *
	 * @return array|null
	 */
	private function peek() {
		return isset( $this->tokens[ $this->pos ] ) ? $this->tokens[ $this->pos ] : null;
	}

	/**
	 * Addition und Subtraktion.
	 *
	 * @return float
	 * @throws Exception Bei Syntaxfehlern.
	 */
	private function parse_expression() {
		$value = $this->parse_term();

		while ( true ) {
			$token = $this->peek();
			if ( ! $token || ( '+' !== $token['type'] && '-' !== $token['type'] ) ) {
				break;
			}
			$this->pos++;
			$right = $this->parse_term();
			$value = ( '+' === $token['type'] ) ? $value + $right : $value - $right;
		}

		return $value;
	}

	/**
	 * Multiplikation und Division.
	 *
	 * @return float
	 * @throws Exception Bei Division durch null.
	 */
	private function parse_term() {
		$value = $this->parse_factor();

		while ( true ) {
			$token = $this->peek();
			if ( ! $token || ( '*' !== $token['type'] && '/' !== $token['type'] ) ) {
				break;
			}
			$this->pos++;
			$right = $this->parse_factor();

			if ( '/' === $token['type'] ) {
				if ( 0.0 === (float) $right ) {
					throw new Exception( __( 'Division durch null in der Formel.', 'svm' ) );
				}
				$value = $value / $right;
			} else {
				$value = $value * $right;
			}
		}

		return $value;
	}

	/**
	 * Zahlen, Variablen, Klammern, Funktionen.
	 *
	 * @return float
	 * @throws Exception Bei Syntaxfehlern.
	 */
	private function parse_factor() {
		$token = $this->peek();

		if ( ! $token ) {
			throw new Exception( __( 'Formel endet unerwartet.', 'svm' ) );
		}

		if ( '-' === $token['type'] ) {
			$this->pos++;
			return -1 * $this->parse_factor();
		}

		if ( '+' === $token['type'] ) {
			$this->pos++;
			return $this->parse_factor();
		}

		if ( 'number' === $token['type'] ) {
			$this->pos++;
			return (float) $token['value'];
		}

		if ( 'var' === $token['type'] ) {
			$this->pos++;
			$name = $token['value'];
			if ( ! array_key_exists( $name, $this->vars ) ) {
				return 0.0;
			}
			return SVM_Fields::to_number( $this->vars[ $name ] );
		}

		if ( '(' === $token['type'] ) {
			$this->pos++;
			$value = $this->parse_expression();
			$this->expect( ')' );
			return $value;
		}

		if ( 'func' === $token['type'] ) {
			$this->pos++;
			return $this->parse_function( $token['value'] );
		}

		throw new Exception( __( 'Unerwartetes Token in der Formel.', 'svm' ) );
	}

	/**
	 * Wertet einen Funktionsaufruf aus.
	 *
	 * @param string $name Funktionsname.
	 * @return float
	 * @throws Exception Bei unbekannten Funktionen.
	 */
	private function parse_function( $name ) {
		$this->expect( '(' );

		$args = array();
		if ( $this->peek() && ')' !== $this->peek()['type'] ) {
			$args[] = $this->parse_expression();
			while ( $this->peek() && ',' === $this->peek()['type'] ) {
				$this->pos++;
				$args[] = $this->parse_expression();
			}
		}

		$this->expect( ')' );

		switch ( $name ) {
			case 'min':
				return empty( $args ) ? 0.0 : (float) min( $args );
			case 'max':
				return empty( $args ) ? 0.0 : (float) max( $args );
			case 'round':
				$precision = isset( $args[1] ) ? (int) $args[1] : 2;
				return isset( $args[0] ) ? round( (float) $args[0], $precision ) : 0.0;
			case 'floor':
				return isset( $args[0] ) ? floor( (float) $args[0] ) : 0.0;
			case 'ceil':
				return isset( $args[0] ) ? ceil( (float) $args[0] ) : 0.0;
			case 'abs':
				return isset( $args[0] ) ? abs( (float) $args[0] ) : 0.0;
		}

		throw new Exception(
			sprintf(
				/* translators: %s: Funktionsname. */
				__( 'Unbekannte Funktion „%s“ in der Formel.', 'svm' ),
				$name
			)
		);
	}

	/**
	 * Erwartet ein bestimmtes Token.
	 *
	 * @param string $type Tokentyp.
	 * @return void
	 * @throws Exception Wenn das Token fehlt.
	 */
	private function expect( $type ) {
		$token = $this->peek();

		if ( ! $token || $token['type'] !== $type ) {
			throw new Exception(
				sprintf(
					/* translators: %s: erwartetes Zeichen. */
					__( 'In der Formel fehlt „%s“.', 'svm' ),
					$type
				)
			);
		}

		$this->pos++;
	}
}
