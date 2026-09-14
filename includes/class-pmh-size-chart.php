<?php
/**
 * Size chart value handling. Pure PHP, no WordPress dependencies, so it is
 * unit-testable and reusable by the importer, the save layer and the renderer.
 *
 * Normalised chart structure (inches, floats rounded to 2 dp):
 *
 * [
 *   'sizes' => ['S', 'M', 'L'],
 *   'note'  => 'Product measurements may vary by up to 2" (5 cm).',
 *   'rows'  => [
 *     [ 'label' => 'Length', 'values' => [ 'S' => [28.0], 'M' => [29.0] ] ],
 *     [ 'label' => 'Chest',  'values' => [ 'S' => [34.0, 37.0] ] ],
 *   ],
 * ]
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Size_Chart {

	/** Unicode vulgar fractions to "n/d". */
	private const FRACTIONS = array(
		'¼' => '1/4',
		'½' => '1/2',
		'¾' => '3/4',
		'⅓' => '1/3',
		'⅔' => '2/3',
		'⅕' => '1/5',
		'⅖' => '2/5',
		'⅗' => '3/5',
		'⅘' => '4/5',
		'⅙' => '1/6',
		'⅚' => '5/6',
		'⅛' => '1/8',
		'⅜' => '3/8',
		'⅝' => '5/8',
		'⅞' => '7/8',
	);

	/** Aliases a shop might use for a size, mapped to the chart's spelling. */
	private const SIZE_ALIASES = array(
		'XXL'    => '2XL',
		'XXXL'   => '3XL',
		'XXXXL'  => '4XL',
		'XXXXXL' => '5XL',
		'2X'     => '2XL',
		'3X'     => '3XL',
		'4X'     => '4XL',
		'5X'     => '5XL',
		'XXS'    => '2XS',
	);

	public static function empty_chart(): array {
		return array(
			'sizes' => array(),
			'note'  => '',
			'rows'  => array(),
		);
	}

	/**
	 * Canonical spelling of a size label: trimmed, upper-cased, aliases mapped.
	 */
	public static function normalise_size( string $size ): string {
		$size = strtoupper( trim( preg_replace( '/\s+/u', ' ', $size ) ) );
		return self::SIZE_ALIASES[ $size ] ?? $size;
	}

	/**
	 * Parse one cell of input into a list of one or two floats (inches).
	 *
	 * Accepts "28", "28.5", "15 5/8", "16 ½", "16½", "34-37", "34 – 37",
	 * "34 to 37", with an optional trailing " or in. Returns null for
	 * anything empty or unparseable.
	 *
	 * @return float[]|null
	 */
	public static function parse_cell( string $cell ): ?array {
		$cell = trim( $cell );
		if ( '' === $cell ) {
			return null;
		}

		// "16½" -> "16 1/2"; "½" -> "1/2".
		$cell = preg_replace_callback(
			'/(\d)?([' . implode( '', array_keys( self::FRACTIONS ) ) . '])/u',
			static fn( $m ) => ( $m[1] ?? '' ) . ( isset( $m[1] ) && '' !== $m[1] ? ' ' : '' ) . self::FRACTIONS[ $m[2] ],
			$cell
		);

		// Strip unit markers.
		$cell = preg_replace( '/["″”]|\b(?:in|inch|inches)\b\.?/iu', '', $cell );
		$cell = trim( $cell );

		$parts = preg_split( '/\s*(?:–|—|-|to)\s*/u', $cell );
		if ( ! $parts || count( $parts ) > 2 ) {
			return null;
		}

		$out = array();
		foreach ( $parts as $part ) {
			$value = self::parse_number( trim( $part ) );
			if ( null === $value ) {
				return null;
			}
			$out[] = $value;
		}

		if ( 2 === count( $out ) && $out[0] > $out[1] ) {
			$out = array( $out[1], $out[0] );
		}

		return $out;
	}

	/**
	 * "28", "28.5", "28,5", "15 5/8", "5/8" -> float rounded to 2 dp.
	 */
	public static function parse_number( string $text ): ?float {
		$text = str_replace( ',', '.', trim( $text ) );

		if ( preg_match( '/^(\d+(?:\.\d+)?)$/', $text, $m ) ) {
			return self::round( (float) $m[1] );
		}
		if ( preg_match( '/^(?:(\d+)\s+)?(\d+)\s*\/\s*(\d+)$/', $text, $m ) ) {
			$whole = '' !== $m[1] ? (float) $m[1] : 0.0;
			$den   = (float) $m[3];
			if ( 0.0 === $den ) {
				return null;
			}
			return self::round( $whole + ( (float) $m[2] / $den ) );
		}
		return null;
	}

	public static function round( float $value ): float {
		return round( $value, 2 );
	}

	public static function to_cm( float $inches ): float {
		return round( $inches * 2.54, 1 );
	}

	public static function from_cm( float $cm ): float {
		return self::round( $cm / 2.54 );
	}

	/**
	 * Coerce loosely-shaped input into the normalised structure.
	 *
	 * Drops rows with no label or no values, drops values for sizes not in
	 * the size list, orders sizes as given (or, if no size list, in order of
	 * first appearance), and rounds every number.
	 */
	public static function normalise( $chart ): array {
		$out = self::empty_chart();
		if ( ! is_array( $chart ) ) {
			return $out;
		}

		$sizes = array();
		if ( ! empty( $chart['sizes'] ) && is_array( $chart['sizes'] ) ) {
			foreach ( $chart['sizes'] as $size ) {
				if ( is_scalar( $size ) ) {
					$size = self::normalise_size( (string) $size );
					if ( '' !== $size && ! in_array( $size, $sizes, true ) ) {
						$sizes[] = $size;
					}
				}
			}
		}
		$explicit_sizes = ! empty( $sizes );

		$rows = array();
		foreach ( (array) ( $chart['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) && is_scalar( $row['label'] ) ? trim( (string) $row['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}

			$values = array();
			foreach ( (array) ( $row['values'] ?? array() ) as $size => $value ) {
				$size = self::normalise_size( (string) $size );
				if ( '' === $size ) {
					continue;
				}
				$list = self::coerce_values( $value );
				if ( null === $list ) {
					continue;
				}
				if ( ! $explicit_sizes && ! in_array( $size, $sizes, true ) ) {
					$sizes[] = $size;
				}
				$values[ $size ] = $list;
			}

			if ( $values ) {
				$rows[] = array(
					'label'  => $label,
					'values' => $values,
				);
			}
		}

		// Keep only sizes in the list, in list order.
		foreach ( $rows as &$row ) {
			$ordered = array();
			foreach ( $sizes as $size ) {
				if ( isset( $row['values'][ $size ] ) ) {
					$ordered[ $size ] = $row['values'][ $size ];
				}
			}
			$row['values'] = $ordered;
		}
		unset( $row );
		$rows = array_values( array_filter( $rows, static fn( $r ) => ! empty( $r['values'] ) ) );

		$out['sizes'] = $sizes;
		$out['note']  = isset( $chart['note'] ) && is_scalar( $chart['note'] ) ? trim( (string) $chart['note'] ) : '';
		$out['rows']  = $rows;

		return $out;
	}

	/**
	 * A stored value may be a float, a numeric string, a display string
	 * ("34-37"), or a list of one or two numbers.
	 *
	 * @return float[]|null
	 */
	private static function coerce_values( $value ): ?array {
		if ( is_array( $value ) ) {
			$list = array();
			foreach ( $value as $v ) {
				if ( is_int( $v ) || is_float( $v ) ) {
					$list[] = self::round( (float) $v );
				} elseif ( is_string( $v ) ) {
					$n = self::parse_number( $v );
					if ( null === $n ) {
						return null;
					}
					$list[] = $n;
				} else {
					return null;
				}
			}
			$list = array_slice( $list, 0, 2 );
			if ( ! $list ) {
				return null;
			}
			if ( 2 === count( $list ) && $list[0] > $list[1] ) {
				$list = array( $list[1], $list[0] );
			}
			return $list;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return array( self::round( (float) $value ) );
		}
		if ( is_string( $value ) ) {
			return self::parse_cell( $value );
		}
		return null;
	}

	/**
	 * Display string for a value list: "28" or "34–37". No unit.
	 */
	public static function format_values( array $values, string $unit = 'in' ): string {
		$fmt = static function ( float $v ) use ( $unit ): string {
			$v = 'cm' === $unit ? self::to_cm( $v ) : $v;
			$s = rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
			return '' === $s ? '0' : $s;
		};
		return implode( '–', array_map( $fmt, $values ) );
	}

	/**
	 * Return the chart restricted to the given sizes, in chart order.
	 */
	public static function filter_sizes( array $chart, array $sizes ): array {
		$sizes = array_map( array( __CLASS__, 'normalise_size' ), $sizes );
		$chart = self::normalise( $chart );
		$chart['sizes'] = array_values( array_filter( $chart['sizes'], static fn( $s ) => in_array( $s, $sizes, true ) ) );
		if ( ! $chart['sizes'] ) {
			return self::empty_chart(); // normalise() would infer sizes from the rows.
		}
		return self::normalise( $chart );
	}

	public static function is_empty( $chart ): bool {
		return empty( $chart['rows'] ) || empty( $chart['sizes'] );
	}
}
