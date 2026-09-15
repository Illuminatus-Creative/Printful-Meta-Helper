<?php
/**
 * HTML for the size chart (materials live in PMH_Materials). The markup is
 * a contract for theme CSS:
 *
 * .pmh-chart (wrapper; also .pmh-chart--{blank-slug}, .pmh-chart--unit-in|cm)
 *   .pmh-chart__toggle > button.pmh-chart__unit[data-unit][aria-pressed]
 *   table.pmh-chart__table
 *     thead th.pmh-chart__head (+ --size on the first, --body on body-chart columns)
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
				'note'      => true,
				'supplier'  => true,
				'class'     => '',
				'table'     => 'product',
				'body_rows' => array(), // rows from the body chart appended as columns
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
		$html .= self::table_html( $chart, $suffix, (array) $opts['body_rows'] );

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
	 * every cell. Body-chart rows, when given, follow the garment columns
	 * and carry a --body modifier so they can be styled apart.
	 *
	 * @param array $suffix    ['in' => '"', 'cm' => ''].
	 * @param array $body_rows Rows from PMH_Size_Chart::pick_rows().
	 */
	private static function table_html( array $chart, array $suffix, array $body_rows = array() ): string {
		$columns = array();
		foreach ( $chart['rows'] as $row ) {
			$columns[] = array( $row, '' );
		}
		foreach ( $body_rows as $row ) {
			$columns[] = array( $row, ' pmh-chart__head--body' );
		}

		$html  = '<div class="pmh-chart__scroll"><table class="pmh-chart__table">';
		$html .= '<thead><tr><th scope="col" class="pmh-chart__head pmh-chart__head--size">' . esc_html__( 'Size', 'printful-meta-helper' ) . '</th>';
		foreach ( $columns as [ $row, $modifier ] ) {
			$html .= '<th scope="col" class="pmh-chart__head' . esc_attr( $modifier ) . '">' . esc_html( $row['label'] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ( $chart['sizes'] as $size ) {
			$html .= '<tr class="pmh-chart__row" data-size="' . esc_attr( $size ) . '">';
			$html .= '<th scope="row" class="pmh-chart__size">' . esc_html( $size ) . '</th>';
			foreach ( $columns as [ $row, $modifier ] ) {
				$cell_mod = '' !== $modifier ? ' pmh-chart__cell--body' : '';
				$values   = $row['values'][ $size ] ?? null;
				if ( null === $values ) {
					$html .= '<td class="pmh-chart__cell pmh-chart__cell--empty' . esc_attr( $cell_mod ) . '"></td>';
					continue;
				}
				$html .= '<td class="pmh-chart__cell' . esc_attr( $cell_mod ) . '">';
				$html .= '<span class="pmh-chart__val pmh-chart__val--in">' . esc_html( PMH_Size_Chart::format_values( $values, 'in' ) . ( $suffix['in'] ?? '' ) ) . '</span>';
				$html .= '<span class="pmh-chart__val pmh-chart__val--cm">' . esc_html( PMH_Size_Chart::format_values( $values, 'cm' ) . ( $suffix['cm'] ?? '' ) ) . '</span>';
				$html .= '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div>';
	}
}
