<?php
/**
 * HTML for the size chart. The markup is a contract for theme CSS:
 *
 * .pmh-chart (wrapper; also .pmh-chart--{blank-slug}, .pmh-chart--unit-in|cm)
 *   .pmh-chart__toggle > button.pmh-chart__unit[data-unit][aria-pressed]
 *   table.pmh-chart__table
 *     thead th.pmh-chart__head (+ --size on the first)
 *     tbody tr.pmh-chart__row[data-size]
 *       th.pmh-chart__size
 *       td.pmh-chart__cell > span.pmh-chart__val.pmh-chart__val--in | --cm
 *   p.pmh-chart__note > span.pmh-chart__note-line--supplier <br> span.pmh-chart__note-line--blank
 *
 * Both unit values are always in the DOM; CSS shows one based on the
 * wrapper's unit class, so the toggle never rewrites text.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Renderer {

	private static bool $assets_registered = false;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register always; enqueue early on product pages that have a blank so
	 * the stylesheet lands in <head>. Elsewhere the shortcode enqueues at
	 * render time and WordPress prints the assets in the footer.
	 */
	public static function register_assets(): void {
		wp_register_style( 'pmh-size-chart', PMH_URL . 'public/css/size-chart.css', array(), PMH_VERSION );
		wp_register_script( 'pmh-unit-toggle', PMH_URL . 'public/js/unit-toggle.js', array(), PMH_VERSION, array( 'in_footer' => true ) );
		self::$assets_registered = true;

		if ( is_singular( 'product' ) && has_term( '', PMH_TAXONOMY, get_queried_object_id() ) ) {
			self::enqueue_assets();
		}
	}

	public static function enqueue_assets(): void {
		if ( ! self::$assets_registered ) {
			self::register_assets();
		}
		wp_enqueue_style( 'pmh-size-chart' );
		wp_enqueue_script( 'pmh-unit-toggle' );
	}

	/**
	 * @param WP_Term $blank Blank the chart belongs to (for the slug class).
	 * @param array   $chart Normalised chart, already filtered.
	 * @param array   $opts  unit ('in'|'cm'), toggle (bool), note (bool),
	 *                       class (string), table ('product'|'body').
	 */
	public static function size_chart( WP_Term $blank, array $chart, array $opts = array() ): string {
		if ( PMH_Size_Chart::is_empty( $chart ) ) {
			return '';
		}
		$opts = wp_parse_args(
			$opts,
			array(
				'unit'     => 'in',
				'toggle'   => true,
				'note'     => true,
				'supplier' => true,
				'class'    => '',
				'table'    => 'product',
			)
		);
		$unit = 'cm' === $opts['unit'] ? 'cm' : 'in';

		/**
		 * Unit suffixes appended to cell values.
		 *
		 * @param array $suffix ['in' => '"', 'cm' => ''].
		 */
		$suffix = (array) apply_filters( 'pmh_chart_unit_suffix', array( 'in' => '"', 'cm' => '' ), $blank );

		$classes = array(
			'pmh-chart',
			'pmh-chart--' . $blank->slug,
			'pmh-chart--' . ( 'body' === $opts['table'] ? 'body' : 'product' ),
			'pmh-chart--unit-' . $unit,
		);
		if ( ! $opts['toggle'] ) {
			$classes[] = 'pmh-chart--locked';
		}
		$classes = array_merge( $classes, PMH_Util::extra_classes( (string) $opts['class'] ) );

		$html  = '<div class="' . esc_attr( implode( ' ', array_unique( $classes ) ) ) . '" data-pmh-unit="' . esc_attr( $unit ) . '" data-pmh-blank="' . esc_attr( $blank->slug ) . '">';

		if ( $opts['toggle'] ) {
			$html .= self::toggle_html( $unit );
		}
		$html .= self::table_html( $chart, $suffix );

		$lines = array();
		if ( $opts['supplier'] ) {
			/**
			 * The fixed line under every chart. These are print-on-demand
			 * garments, so there is always a supplier, and the store does not
			 * vouch for measurements copied from theirs.
			 *
			 * @param string $line Default "Measurements are provided by suppliers."
			 */
			$supplier = (string) apply_filters( 'pmh_chart_supplier_line', __( 'Measurements are provided by suppliers.', 'printful-meta-helper' ), $blank );
			if ( '' !== $supplier ) {
				$lines[] = '<span class="pmh-chart__note-line pmh-chart__note-line--supplier">' . esc_html( $supplier ) . '</span>';
			}
		}
		if ( $opts['note'] && '' !== $chart['note'] ) {
			$lines[] = '<span class="pmh-chart__note-line pmh-chart__note-line--blank">' . esc_html( $chart['note'] ) . '</span>';
		}
		if ( $lines ) {
			// One paragraph, lines separated by a break, so the notes sit
			// together under the table rather than as spaced paragraphs.
			$html .= '<p class="pmh-chart__note">' . implode( '<br>', $lines ) . '</p>';
		}

		$html .= '</div>';

		/**
		 * Filter the finished size chart HTML.
		 *
		 * @param string  $html  Markup.
		 * @param WP_Term $blank Blank.
		 * @param array   $chart Filtered chart that was rendered.
		 * @param array   $opts  Render options.
		 */
		return (string) apply_filters( 'pmh_size_chart_html', $html, $blank, $chart, $opts );
	}

	private static function toggle_html( string $unit ): string {
		$html  = '<div class="pmh-chart__toggle" role="group" aria-label="' . esc_attr__( 'Measurement units', 'printful-meta-helper' ) . '">';
		$html .= sprintf(
			'<button type="button" class="pmh-chart__unit" data-unit="in" aria-pressed="%s">%s</button>',
			'in' === $unit ? 'true' : 'false',
			esc_html__( 'Inches', 'printful-meta-helper' )
		);
		$html .= sprintf(
			'<button type="button" class="pmh-chart__unit" data-unit="cm" aria-pressed="%s">%s</button>',
			'cm' === $unit ? 'true' : 'false',
			esc_html__( 'Centimeters', 'printful-meta-helper' )
		);
		return $html . '</div>';
	}

	/**
	 * One row per size, one column per measurement, both unit values in
	 * every cell.
	 *
	 * @param array $suffix ['in' => '"', 'cm' => ''].
	 */
	private static function table_html( array $chart, array $suffix ): string {
		$html  = '<div class="pmh-chart__scroll"><table class="pmh-chart__table">';
		$html .= '<thead><tr><th scope="col" class="pmh-chart__head pmh-chart__head--size">' . esc_html__( 'Size', 'printful-meta-helper' ) . '</th>';
		foreach ( $chart['rows'] as $row ) {
			$html .= '<th scope="col" class="pmh-chart__head">' . esc_html( $row['label'] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ( $chart['sizes'] as $size ) {
			$html .= '<tr class="pmh-chart__row" data-size="' . esc_attr( $size ) . '">';
			$html .= '<th scope="row" class="pmh-chart__size">' . esc_html( $size ) . '</th>';
			foreach ( $chart['rows'] as $row ) {
				$values = $row['values'][ $size ] ?? null;
				if ( null === $values ) {
					$html .= '<td class="pmh-chart__cell pmh-chart__cell--empty"></td>';
					continue;
				}
				$html .= '<td class="pmh-chart__cell">';
				$html .= '<span class="pmh-chart__val pmh-chart__val--in">' . esc_html( PMH_Size_Chart::format_values( $values, 'in' ) . ( $suffix['in'] ?? '' ) ) . '</span>';
				$html .= '<span class="pmh-chart__val pmh-chart__val--cm">' . esc_html( PMH_Size_Chart::format_values( $values, 'cm' ) . ( $suffix['cm'] ?? '' ) ) . '</span>';
				$html .= '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * Materials as a definition list. Empty fields are skipped; colour
	 * exceptions render as a sub-list under the base material.
	 *
	 * .pmh-materials (+ --{blank-slug})
	 *   dl.pmh-materials__list
	 *     div.pmh-materials__item.pmh-materials__item--{material|weight|construction|care|disclaimers}
	 *       dt.pmh-materials__label
	 *       dd.pmh-materials__value (+ ul.pmh-materials__lines for multi-line fields,
	 *                                ul.pmh-materials__exceptions under material)
	 *
	 * @param WP_Term $blank Blank.
	 * @param array   $data  PMH_Blank::get() result.
	 * @param array   $opts  fields (string[]), labels (bool), class (string).
	 */
	public static function materials( WP_Term $blank, array $data, array $opts = array() ): string {
		$opts = wp_parse_args(
			$opts,
			array(
				'fields' => array( 'material', 'weight', 'construction', 'care', 'disclaimers' ),
				'labels' => true,
				'class'  => '',
			)
		);

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
