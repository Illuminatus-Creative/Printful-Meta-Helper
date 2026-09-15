<?php
/**
 * Converts Printful data into the plugin's normalised structures.
 * Pure PHP: no WordPress calls, so the save layer sanitises the output.
 *
 * Inputs:
 *  - from_json(): the size-guide JSON Printful serves for a catalogue
 *    product (top-level keys availableSizes, modelMeasurements,
 *    productMeasurements; rows carry unit "inch" or "centimeter").
 *  - from_text(): the tab-separated table copied from the Printful page.
 *  - materials_from_text(): the bulleted materials paragraph.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Import_Exception extends RuntimeException {}

final class PMH_Importer {

	/** Product meta key Printful's WooCommerce sync writes the size JSON to. */
	public const PRODUCT_META_KEY = 'pf_advanced_size_chart';

	/**
	 * @return array{product: ?array, body: ?array}
	 * @throws PMH_Import_Exception
	 */
	public static function from_json( string $json ): array {
		$json = trim( $json );
		if ( '' === $json ) {
			throw new PMH_Import_Exception( 'Nothing to import.' );
		}
		$data = json_decode( $json, true, 32 );
		if ( ! is_array( $data ) ) {
			throw new PMH_Import_Exception( 'That is not valid JSON.' );
		}
		// Tolerate a wrapping "result" key.
		if ( isset( $data['result'] ) && is_array( $data['result'] ) && ! isset( $data['productMeasurements'] ) ) {
			$data = $data['result'];
		}
		if ( ! isset( $data['productMeasurements'] ) && ! isset( $data['modelMeasurements'] ) ) {
			throw new PMH_Import_Exception( 'No productMeasurements or modelMeasurements found in the JSON.' );
		}

		$sizes = array();
		foreach ( (array) ( $data['availableSizes'] ?? array() ) as $size ) {
			if ( is_scalar( $size ) ) {
				$sizes[] = (string) $size;
			}
		}

		return array(
			'product' => self::table_from_json( $data['productMeasurements'] ?? null, $sizes ),
			'body'    => self::table_from_json( $data['modelMeasurements'] ?? null, $sizes ),
		);
	}

	/**
	 * Printful's sync writes the size-guide JSON to product meta on products
	 * it pushes (legacy products have none). Same parser, different source.
	 *
	 * @return array{product: ?array, body: ?array}|null null when the meta is
	 *         absent or unreadable.
	 */
	public static function from_product_meta( int $product_id ): ?array {
		$json = get_post_meta( $product_id, self::PRODUCT_META_KEY, true );
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return null;
		}
		try {
			return self::from_json( $json );
		} catch ( PMH_Import_Exception $e ) {
			return null;
		}
	}

	private static function table_from_json( $table, array $sizes ): ?array {
		if ( ! is_array( $table ) || empty( $table['sizeTableRows'] ) || ! is_array( $table['sizeTableRows'] ) ) {
			return null;
		}

		$rows = array();
		foreach ( $table['sizeTableRows'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$unit = strtolower( (string) ( $row['unit'] ?? '' ) );
			if ( ! in_array( $unit, array( 'inch', 'inches', 'in' ), true ) ) {
				continue; // cm rows are exact conversions of the inch rows.
			}
			$rows[] = array(
				'label'  => (string) ( $row['title'] ?? '' ),
				'values' => is_array( $row['sizes'] ?? null ) ? $row['sizes'] : array(),
			);
		}

		if ( ! $rows ) {
			return null;
		}

		$chart = PMH_Size_Chart::normalise(
			array(
				'sizes' => $sizes,
				'note'  => (string) ( $table['sizeTableDescription'] ?? '' ),
				'rows'  => $rows,
			)
		);

		return PMH_Size_Chart::is_empty( $chart ) ? null : $chart;
	}

	/**
	 * Parse the table copied from a Printful product page.
	 *
	 * Expected shape (tabs between cells):
	 *   Size Label <tab> Length <tab> Width
	 *   S <tab> 28 <tab> 18
	 *   ...
	 *   Product measurements may vary by up to 2" (5 cm).
	 *
	 * Lines before the header and lines that are just "Inches" /
	 * "Centimeters" / "Size chart" are ignored. A non-tabular line after
	 * the rows becomes the note.
	 *
	 * @param string $text Pasted text.
	 * @param string $unit 'in' or 'cm': the unit the page was showing.
	 * @throws PMH_Import_Exception
	 */
	public static function from_text( string $text, string $unit = 'in' ): array {
		$unit  = 'cm' === strtolower( $unit ) ? 'cm' : 'in';
		$lines = preg_split( '/\r\n|\r|\n/', trim( $text ) );

		$columns = null;
		$sizes   = array();
		$cells   = array(); // size => [ column index => float[] ]
		$note    = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( "\t", $line ) );

			if ( null === $columns ) {
				if ( count( $parts ) >= 2 && preg_match( '/^size/i', $parts[0] ) ) {
					$columns = array_slice( $parts, 1 );
				}
				continue;
			}

			if ( count( $parts ) < 2 ) {
				if ( ! preg_match( '/^(inches|centimeters|centimetres|size chart)$/i', $line ) ) {
					$note[] = $line;
				}
				continue;
			}

			$size = PMH_Size_Chart::normalise_size( array_shift( $parts ) );
			if ( '' === $size ) {
				continue;
			}
			$sizes[] = $size;
			foreach ( $parts as $i => $cell ) {
				$values = PMH_Size_Chart::parse_cell( $cell );
				if ( null === $values ) {
					continue;
				}
				if ( 'cm' === $unit ) {
					$values = array_map( array( PMH_Size_Chart::class, 'from_cm' ), $values );
				}
				$cells[ $size ][ $i ] = $values;
			}
		}

		if ( null === $columns ) {
			throw new PMH_Import_Exception( 'No header row found. The first column heading should start with "Size".' );
		}
		if ( ! $sizes ) {
			throw new PMH_Import_Exception( 'Header found but no size rows under it.' );
		}

		$rows = array();
		foreach ( $columns as $i => $label ) {
			$values = array();
			foreach ( $sizes as $size ) {
				if ( isset( $cells[ $size ][ $i ] ) ) {
					$values[ $size ] = $cells[ $size ][ $i ];
				}
			}
			$rows[] = array(
				'label'  => $label,
				'values' => $values,
			);
		}

		$chart = PMH_Size_Chart::normalise(
			array(
				'sizes' => $sizes,
				'note'  => implode( ' ', $note ),
				'rows'  => $rows,
			)
		);
		if ( PMH_Size_Chart::is_empty( $chart ) ) {
			throw new PMH_Import_Exception( 'No measurements could be read from the rows.' );
		}
		return $chart;
	}

	/**
	 * Split Printful's materials list into fields.
	 *
	 * Accepts the list with bullet markers (•, *, -, ·), without markers (a
	 * rendered list copied from Chrome arrives as bare lines), or as HTML
	 * list items. When any line carries a marker only marked lines count;
	 * otherwise every line that does not read as a prose paragraph counts.
	 *
	 * Rules: the first item containing "%" is the base material; further
	 * "%" items are colour exceptions; a "Fabric weight" item is the
	 * weight; sourcing lines are dropped; every other item is construction.
	 * Disclaimers come in two shapes and both are kept: a "Disclaimers:"
	 * heading followed by lines (Gildan), or an inline "Disclaimer: …"
	 * sentence (Bella+Canvas).
	 *
	 * @return array{material_solid: string, material_exceptions: string, fabric_weight: string, construction: string, disclaimers: string, lines: int}
	 */
	public static function materials_from_text( string $text ): array {
		$out = array(
			'material_solid'      => '',
			'material_exceptions' => '',
			'fabric_weight'       => '',
			'construction'        => '',
			'disclaimers'         => '',
			'lines'               => 0,
		);

		$parsed       = self::material_items( $text );
		$out['lines'] = count( $parsed['items'] ) + count( $parsed['disclaimers'] );
		$exceptions   = array();
		$construction = array();

		foreach ( $parsed['items'] as $item ) {
			if ( preg_match( '/sourced from/i', $item ) ) {
				continue;
			}
			if ( preg_match( '/^fabric weight\s*:?\s*(.+)$/i', $item, $w ) ) {
				$out['fabric_weight'] = trim( $w[1] );
				continue;
			}
			if ( str_contains( $item, '%' ) ) {
				if ( '' === $out['material_solid'] ) {
					$out['material_solid'] = $item;
				} else {
					$exceptions[] = $item;
				}
				continue;
			}
			$construction[] = $item;
		}

		$out['material_exceptions'] = implode( "\n", $exceptions );
		$out['construction']        = implode( "\n", $construction );
		$out['disclaimers']         = implode( "\n", $parsed['disclaimers'] );

		return $out;
	}

	/**
	 * The list items in a materials paste, marker stripped and prose
	 * removed, with disclaimers separated out.
	 *
	 * @return array{items: string[], disclaimers: string[]}
	 */
	private static function material_items( string $text ): array {
		$text = self::utf8( $text );
		if ( false !== stripos( $text, '<li' ) ) {
			$text = strip_tags( preg_replace( '#</li\s*>|<br\s*/?>|</p\s*>#i', "\n", $text ) );
		}
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$lines    = array();
		$bulleted = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line, " \t\x0B\0\xC2\xA0" );
			if ( '' === $line ) {
				continue;
			}
			$marked = (bool) preg_match( '/^[\x{2022}\x{25E6}\x{25AA}\x{2023}\x{00B7}*\-\x{2013}]\s*(.*)$/u', $line, $m );
			if ( $marked ) {
				$bulleted = true;
				$line     = trim( $m[1] );
			}
			$lines[] = array( $line, $marked );
		}

		$items       = array();
		$disclaimers = array();
		$in_disclaim = false;
		foreach ( $lines as [ $line, $marked ] ) {
			if ( '' === $line ) {
				continue;
			}
			// "Disclaimers:" heading, or "Disclaimer: sentence" inline. Either
			// way everything from here on is a disclaimer.
			if ( preg_match( '/^disclaimers?\s*:\s*(.*)$/iu', $line, $d ) ) {
				$in_disclaim = true;
				if ( '' !== trim( $d[1] ) ) {
					$disclaimers[] = trim( $d[1] );
				}
				continue;
			}
			if ( $in_disclaim ) {
				$disclaimers[] = $line;
				continue;
			}
			if ( $bulleted ? ! $marked : self::looks_like_prose( $line ) ) {
				continue;
			}
			$items[] = $line;
		}
		return compact( 'items', 'disclaimers' );
	}

	/**
	 * A paragraph, not a list item: many words or more than one sentence.
	 */
	private static function looks_like_prose( string $line ): bool {
		return str_word_count( $line ) > 14 || (bool) preg_match( '/[.!?]\s+\p{Lu}/u', $line );
	}

	/**
	 * Replace invalid UTF-8 so the /u regexes never fail wholesale (a
	 * Windows-1252 "²" pasted from a legacy source, for example).
	 */
	private static function utf8( string $text ): string {
		if ( preg_match( '//u', $text ) ) {
			return $text;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		return preg_replace( '/[\x80-\xFF]/', '', $text );
	}
}
