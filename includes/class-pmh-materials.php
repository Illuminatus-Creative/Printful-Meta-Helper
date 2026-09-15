<?php
/**
 * HTML for the materials list, and the per-product colour filters that
 * decide which colour exceptions and disclaimers a product page shows.
 *
 * .pmh-materials (+ --{blank-slug})
 *   dl.pmh-materials__list
 *     div.pmh-materials__item.pmh-materials__item--{material|weight|construction|care|disclaimers}
 *       dt.pmh-materials__label
 *       dd.pmh-materials__value (+ ul.pmh-materials__lines for multi-line fields,
 *                                ul.pmh-materials__exceptions under material)
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Materials {

	/**
	 * Materials as a definition list. Empty fields are skipped; colour
	 * exceptions render as a sub-list under the base material.
	 *
	 * @param WP_Term $blank Blank.
	 * @param array   $data  PMH_Blank::get() result.
	 * @param array   $opts  fields (string[]), labels (bool), class (string),
	 *                       colours (string[]|null): the product's colour
	 *                       names; when given, only colour exceptions that
	 *                       name one of them render. null renders them all.
	 */
	public static function materials( WP_Term $blank, array $data, array $opts = array() ): string {
		$opts = wp_parse_args(
			$opts,
			array(
				'fields'  => array( 'material', 'weight', 'construction', 'care', 'disclaimers' ),
				'labels'  => true,
				'class'   => '',
				'colours' => null,
			)
		);
		if ( is_array( $opts['colours'] ) ) {
			$data['material_exceptions'] = implode(
				"\n",
				self::exceptions_for_colours( PMH_Util::lines( (string) ( $data['material_exceptions'] ?? '' ) ), $opts['colours'] )
			);
			$data['disclaimers'] = implode(
				"\n",
				self::disclaimers_for_colours( PMH_Util::lines( (string) ( $data['disclaimers'] ?? '' ) ), $opts['colours'] )
			);
		}

		/**
		 * Filter the labels shown next to each materials field.
		 *
		 * @param array $labels field => label.
		 */
		$labels = (array) apply_filters(
			'pmh_materials_labels',
			array(
				'material'     => __( 'Material', 'printful-meta-helper' ),
				'weight'       => __( 'Fabric weight', 'printful-meta-helper' ),
				'construction' => __( 'Construction', 'printful-meta-helper' ),
				'care'         => __( 'Care', 'printful-meta-helper' ),
				'disclaimers'  => __( 'Disclaimers', 'printful-meta-helper' ),
			),
			$blank
		);

		$items = '';
		foreach ( (array) $opts['fields'] as $field ) {
			$field = trim( (string) $field );
			$value = self::materials_value( $field, $data );
			if ( '' === $value ) {
				continue;
			}
			$items .= '<div class="pmh-materials__item pmh-materials__item--' . esc_attr( $field ) . '">';
			if ( $opts['labels'] ) {
				$items .= '<dt class="pmh-materials__label">' . esc_html( $labels[ $field ] ?? ucfirst( $field ) ) . '</dt>';
			}
			$items .= '<dd class="pmh-materials__value">' . $value . '</dd></div>';
		}

		if ( '' === $items ) {
			return '';
		}

		$classes = array_merge( array( 'pmh-materials', 'pmh-materials--' . $blank->slug ), PMH_Util::extra_classes( (string) $opts['class'] ) );

		$html = '<div class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '" data-pmh-blank="' . esc_attr( $blank->slug ) . '">'
			. '<dl class="pmh-materials__list">' . $items . '</dl></div>';

		/**
		 * Filter the finished materials HTML.
		 *
		 * @param string  $html  Markup.
		 * @param WP_Term $blank Blank.
		 * @param array   $data  Blank data.
		 * @param array   $opts  Render options.
		 */
		return (string) apply_filters( 'pmh_materials_html', $html, $blank, $data, $opts );
	}

	/** Shortcode field name => blank data key, for the plain fields. */
	private const MATERIAL_FIELDS = array(
		'weight'       => 'fabric_weight',
		'construction' => 'construction',
		'care'         => 'care',
		'disclaimers'  => 'disclaimers',
	);

	/**
	 * Escaped value for one materials field, or '' to skip it. Unknown
	 * field names skip too.
	 */
	private static function materials_value( string $field, array $data ): string {
		if ( 'material' === $field ) {
			return self::material_value( $data );
		}
		if ( ! isset( self::MATERIAL_FIELDS[ $field ] ) ) {
			return '';
		}
		$text = (string) ( $data[ self::MATERIAL_FIELDS[ $field ] ] ?? '' );
		return 'weight' === $field ? esc_html( trim( $text ) ) : self::lines_value( $text );
	}

	/**
	 * Keep the colour-exception lines that apply to the given colours.
	 *
	 * A line's subject is the text before "is" / "are" ("Athletic and Black
	 * Heather", "Heather colors", "Sport Grey"), split on commas and "and"
	 * with filler words removed. A line stays when any subject phrase
	 * occurs, on word boundaries, inside one of the product's colour names:
	 * "Heather" matches "Dark Heather"; "Black Heather" does not match
	 * "Black". Lines with no recognisable subject stay, since dropping them
	 * would hide information nobody can verify automatically.
	 *
	 * @param string[] $lines   Exception lines.
	 * @param string[] $colours Product colour names.
	 * @return string[]
	 */
	public static function exceptions_for_colours( array $lines, array $colours ): array {
		$keep = array();
		foreach ( $lines as $line ) {
			$parts   = preg_split( '/\s+(?:is|are)\s+/iu', $line, 2 );
			$subject = preg_replace( '/\b(?:colou?rs?|variants?|shades?|options?)\b/iu', '', (string) $parts[0] );
			$phrases = preg_split( '/\s*(?:,|&|\/|\band\b)\s*/iu', $subject );

			if ( count( $parts ) < 2 || self::names_any( $phrases, $colours ) ) {
				$keep[] = $line;
			}
		}
		return $keep;
	}

	/**
	 * Keep the disclaimer lines that apply to the given colours.
	 *
	 * Disclaimers are prose, so the colour is found next to the word
	 * "color": "the White color variant", "for the color Natural",
	 * "Heather colors may …". A line naming no colour is general and stays;
	 * a line naming colours stays only when one of them is a product colour.
	 *
	 * @param string[] $lines   Disclaimer lines.
	 * @param string[] $colours Product colour names.
	 * @return string[]
	 */
	public static function disclaimers_for_colours( array $lines, array $colours ): array {
		$keep = array();
		foreach ( $lines as $line ) {
			$named = self::colours_named_in( $line );
			if ( ! $named || self::names_any( $named, $colours ) ) {
				$keep[] = $line;
			}
		}
		return $keep;
	}

	/**
	 * Colour names a prose line points at: capitalised words directly
	 * before "color(s)" ("the White color variant", "Heather colors") or
	 * directly after ("the color Natural", "colors White and Natural").
	 *
	 * @return string[] Raw phrases; empty when the line names no colour.
	 */
	public static function colours_named_in( string $line ): array {
		$named = array();
		foreach ( array(
			'/((?:\p{Lu}[\p{L}\-]*(?:\s+(?:and\s+)?\p{Lu}[\p{L}\-]*)*))\s+colou?rs?\b/u',
			'/\bcolou?rs?\s+((?:\p{Lu}[\p{L}\-]*)(?:\s+(?:and\s+)?\p{Lu}[\p{L}\-]*)*)/u',
		) as $pattern ) {
			if ( preg_match_all( $pattern, $line, $m ) ) {
				foreach ( $m[1] as $found ) {
					foreach ( preg_split( '/\s+and\s+|\s*,\s*/u', $found ) as $part ) {
						$named[] = $part;
					}
				}
			}
		}
		return array_values( array_filter( array_unique( array_map( 'trim', $named ) ) ) );
	}

	/**
	 * Words a materials line may add to a colour name without naming a
	 * different colour: "Ash Grey" in the line is the variation "Ash".
	 * "Heather" is deliberately absent: "Black Heather" is not "Black".
	 */
	private const HUE_WORDS = array( 'grey' );

	/**
	 * Whether any phrase names any of the colours.
	 *
	 * Forward: the phrase occurs, on word boundaries, inside the colour name
	 * ("Heather" in "Dark Heather"; "Black Heather" not in "Black").
	 * Reverse: the colour name occurs inside the phrase and every word the
	 * phrase adds is a hue word ("Ash Grey" names the colour "Ash"; "Black
	 * Heather" does not name "Black"). Case-insensitive; "gray" and "grey"
	 * are the same; whitespace is collapsed.
	 *
	 * @param string[] $phrases Raw phrases (empties ignored).
	 * @param string[] $colours Raw colour names.
	 */
	public static function names_any( array $phrases, array $colours ): bool {
		$phrases = array_filter( array_map( array( __CLASS__, 'normalise_colour' ), $phrases ) );
		$colours = array_filter( array_map( array( __CLASS__, 'normalise_colour' ), $colours ) );
		foreach ( $phrases as $phrase ) {
			foreach ( $colours as $colour ) {
				if ( self::contains_words( $colour, $phrase ) ) {
					return true;
				}
				if ( self::contains_words( $phrase, $colour ) ) {
					$extra = array_diff( explode( ' ', $phrase ), explode( ' ', $colour ) );
					if ( ! array_diff( $extra, self::HUE_WORDS ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** Whether $needle occurs in $haystack on word boundaries. */
	private static function contains_words( string $haystack, string $needle ): bool {
		return (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u', $haystack );
	}

	/** Lower-case, single-spaced, "gray" spelt "grey". */
	public static function normalise_colour( string $s ): string {
		return strtolower( trim( (string) preg_replace( array( '/\bgray\b/i', '/\s+/u' ), array( 'grey', ' ' ), $s ) ) );
	}

	private static function material_value( array $data ): string {
		$base       = trim( (string) ( $data['material_solid'] ?? '' ) );
		$exceptions = PMH_Util::lines( (string) ( $data['material_exceptions'] ?? '' ) );
		if ( '' === $base && ! $exceptions ) {
			return '';
		}
		$html = '' !== $base ? '<span class="pmh-materials__base">' . esc_html( $base ) . '</span>' : '';
		if ( $exceptions ) {
			$html .= '<ul class="pmh-materials__exceptions">';
			foreach ( $exceptions as $line ) {
				$html .= '<li>' . esc_html( $line ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html;
	}

	private static function lines_value( string $text ): string {
		$lines = PMH_Util::lines( $text );
		if ( ! $lines ) {
			return '';
		}
		if ( 1 === count( $lines ) ) {
			return esc_html( $lines[0] );
		}
		$html = '<ul class="pmh-materials__lines">';
		foreach ( $lines as $line ) {
			$html .= '<li>' . esc_html( $line ) . '</li>';
		}
		return $html . '</ul>';
	}
}
