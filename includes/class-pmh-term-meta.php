<?php
/**
 * Blank add/edit screens: fields, sanitising, saving, and the import boxes.
 *
 * The size charts are edited as normalised JSON in this phase; the grid
 * editor (phase 8) replaces the textarea but writes the same structure.
 * Import boxes are processed server-side on save: paste, save, and the
 * chart fields are populated. Everything stays editable afterwards.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Term_Meta {

	private const NONCE_ACTION = 'pmh_save_blank';
	private const NONCE_FIELD  = 'pmh_blank_nonce';

	public static function init(): void {
		add_action( PMH_TAXONOMY . '_add_form_fields', array( __CLASS__, 'render_add_form' ) );
		add_action( PMH_TAXONOMY . '_edit_form_fields', array( __CLASS__, 'render_edit_form' ), 10, 2 );
		add_action( 'created_' . PMH_TAXONOMY, array( __CLASS__, 'save' ) );
		add_action( 'edited_' . PMH_TAXONOMY, array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	public static function render_add_form(): void {
		self::render_fields( 'add', PMH_Blank::defaults() );
	}

	public static function render_edit_form( WP_Term $term ): void {
		self::render_fields( 'edit', PMH_Blank::get( $term->term_id ) );
	}

	private static function render_fields( string $mode, array $data ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$section = null;
		foreach ( self::field_specs( $data ) as $spec ) {
			if ( $spec['section'] !== $section ) {
				$section = $spec['section'];
				self::heading( $mode, $section );
			}
			self::field( $mode, $spec['id'], $spec['label'], $spec['control'], $spec['description'], $spec['tip'] );
		}
	}

	/**
	 * Every field on the blank screen, in order, with its section heading,
	 * control markup, description and PMH_Admin_Help tip key. Declarative so
	 * the form, the help copy and the tests read the same list.
	 *
	 * @param array $data PMH_Blank::get() result (or defaults).
	 * @return array<int, array{section: string, id: string, label: string, control: string, description: string, tip: string}>
	 */
	public static function field_specs( array $data ): array {
		$applies   = __( 'Applies to', 'printful-meta-helper' );
		$materials = __( 'Materials', 'printful-meta-helper' );
		$chart     = __( 'Size chart', 'printful-meta-helper' );
		$parked    = __( 'Parked', 'printful-meta-helper' );

		$spec = static fn( string $section, string $id, string $label, string $control, string $description = '', string $tip = '' ): array => compact( 'section', 'id', 'label', 'control', 'description', 'tip' );

		return array(
			$spec( $applies, 'pmh_cats', __( 'Product categories', 'printful-meta-helper' ), self::categories_checklist( $data['cats'] ), __( 'The product screen offers this blank for products in these categories. Leave all unticked to offer it everywhere.', 'printful-meta-helper' ), 'applies_to' ),
			$spec( $applies, 'pmh_kind', __( 'Kind', 'printful-meta-helper' ), self::kind_select( $data['kind'] ), __( 'Only apparel renders a size chart; the other kinds render materials only.', 'printful-meta-helper' ), 'kind' ),

			$spec( $materials, 'pmh_material_solid', __( 'Base material', 'printful-meta-helper' ), self::text( 'pmh_material_solid', $data['material_solid'], '100% cotton' ), __( 'The standard fabric, as Printful lists it.', 'printful-meta-helper' ), 'material_base' ),
			$spec( $materials, 'pmh_material_exceptions', __( 'Colour exceptions', 'printful-meta-helper' ), self::textarea( 'pmh_material_exceptions', $data['material_exceptions'], 3, "Sport Grey is 90% cotton, 10% polyester\nHeather colors are 50% cotton, 50% polyester" ), __( 'Colourways whose fabric differs from the base, one per line.', 'printful-meta-helper' ), 'material_exceptions' ),
			$spec( $materials, 'pmh_fabric_weight', __( 'Fabric weight', 'printful-meta-helper' ), self::text( 'pmh_fabric_weight', $data['fabric_weight'], '5.0–5.3 oz/yd² (170-180 g/m²)' ), __( 'Free text; ranges are kept as written.', 'printful-meta-helper' ), 'fabric_weight' ),
			$spec( $materials, 'pmh_construction', __( 'Construction', 'printful-meta-helper' ), self::textarea( 'pmh_construction', $data['construction'], 4, "Tubular fabric\nTaped neck and shoulders" ), __( 'One feature per line.', 'printful-meta-helper' ), 'construction' ),
			$spec( $materials, 'pmh_care', __( 'Care', 'printful-meta-helper' ), self::textarea( 'pmh_care', $data['care'], 3 ), __( 'One instruction per line. Optional.', 'printful-meta-helper' ), 'care' ),
			$spec( $materials, 'pmh_materials_paste', __( 'Paste from Printful', 'printful-meta-helper' ), self::textarea( 'pmh_materials_paste', '', 5, "• 100% cotton\n• Sport Grey is 90% cotton, 10% polyester\n• Fabric weight: 5.0–5.3 oz/yd²\n• Tubular fabric" ), __( 'Paste the bulleted materials paragraph from Printful. On save it is split into the fields above, replacing them. Leave empty to keep the fields as they are.', 'printful-meta-helper' ), 'materials_paste' ),

			$spec( $chart, 'pmh_import_json', __( 'Import Printful JSON', 'printful-meta-helper' ), self::textarea( 'pmh_import_json', '', 6, '{"availableSizes":["S","M"],"productMeasurements":{...},"modelMeasurements":{...}}' ), __( 'Paste the size-guide JSON. On save, the garment chart and body chart below are replaced with its inch rows. Leave empty to keep them.', 'printful-meta-helper' ), 'import_json' ),
			$spec( $chart, 'pmh_import_product', __( 'Import from product', 'printful-meta-helper' ), self::text( 'pmh_import_product', '', '123 or SKU' ), __( 'Product ID or SKU. On save, the size-guide JSON Printful stored on that product replaces the charts below. Ignored if JSON is pasted above.', 'printful-meta-helper' ), 'import_product' ),
			$spec( $chart, 'pmh_import_text', __( 'Import pasted table', 'printful-meta-helper' ), self::textarea( 'pmh_import_text', '', 6, "Size Label\tLength\tWidth\nS\t28\t18\nM\t29\t20" ) . self::unit_radios(), __( 'Copy the table from the Printful product page, header row included. On save it replaces the garment chart. Ignored if JSON or a product is given above.', 'printful-meta-helper' ), 'import_text' ),
			$spec( $chart, 'pmh_chart', __( 'Garment chart (inches)', 'printful-meta-helper' ), self::textarea( 'pmh_chart', self::chart_json( $data['chart'] ), 12, '', 'code pmh-chart-json' ), __( 'Measurements of the garment laid flat, rendered by [pmh_size_chart]. Columns are sizes, rows are measurements; a cell takes 28, 34-37 or 16 ½. Inches only; centimetres are computed. "Edit as JSON" shows the stored structure.', 'printful-meta-helper' ), 'chart' ),
			$spec( $chart, 'pmh_body_chart', __( 'Body chart (inches)', 'printful-meta-helper' ), self::textarea( 'pmh_body_chart', self::chart_json( $data['body_chart'] ), 8, '', 'code pmh-chart-json' ), __( 'Body measurements ("measure yourself"). Stored separately; rendered only when a shortcode asks for it.', 'printful-meta-helper' ), 'body_chart' ),
			$spec( $chart, 'pmh_shortcodes', __( 'Shortcodes', 'printful-meta-helper' ), PMH_Admin_Help::shortcodes_box() ),

			$spec( $parked, 'pmh_handling', __( 'Handling time (days)', 'printful-meta-helper' ), self::handling_inputs( $data ), __( 'Reserved for later feed work. Leave empty.', 'printful-meta-helper' ), 'handling' ),
		);
	}

	private static function unit_radios(): string {
		return '<p><label><input type="radio" name="pmh_import_text_unit" value="in" checked> ' . esc_html__( 'Values are inches', 'printful-meta-helper' ) . '</label> &nbsp; <label><input type="radio" name="pmh_import_text_unit" value="cm"> ' . esc_html__( 'Values are centimetres', 'printful-meta-helper' ) . '</label></p>';
	}

	private static function handling_inputs( array $data ): string {
		return '<input type="number" min="0" step="1" name="pmh_handling_min" id="pmh_handling_min" value="' . esc_attr( $data['handling_min'] ) . '" class="small-text"> – <input type="number" min="0" step="1" name="pmh_handling_max" id="pmh_handling_max" value="' . esc_attr( $data['handling_max'] ) . '" class="small-text">';
	}

	private static function heading( string $mode, string $text ): void {
		if ( 'add' === $mode ) {
			echo '<h3 class="pmh-heading">' . esc_html( $text ) . '</h3>';
		} else {
			echo '<tr class="pmh-heading-row"><th colspan="2"><h3 class="pmh-heading">' . esc_html( $text ) . '</h3></th></tr>';
		}
	}

	/**
	 * Emit one field in the markup each screen expects: div.form-field on
	 * the add screen, tr.form-field on the edit screen. $tip is a
	 * PMH_Admin_Help key; its icon follows the label.
	 */
	private static function field( string $mode, string $id, string $label, string $control, string $description = '', string $tip = '' ): void {
		$desc  = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';
		$label = '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>' . ( '' !== $tip ? ' ' . PMH_Admin_Help::tip( $tip ) : '' );
		if ( 'add' === $mode ) {
			printf(
				'<div class="form-field pmh-field pmh-field--%1$s">%2$s%3$s%4$s</div>',
				esc_attr( $id ),
				$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$desc // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		} else {
			printf(
				'<tr class="form-field pmh-field pmh-field--%1$s"><th scope="row">%2$s</th><td>%3$s%4$s</td></tr>',
				esc_attr( $id ),
				$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$desc // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}

	private static function text( string $name, string $value, string $placeholder = '' ): string {
		return sprintf(
			'<input type="text" name="%1$s" id="%1$s" value="%2$s" placeholder="%3$s" class="regular-text">',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	private static function textarea( string $name, string $value, int $rows, string $placeholder = '', string $extra_class = '' ): string {
		return sprintf(
			'<textarea name="%1$s" id="%1$s" rows="%2$d" placeholder="%3$s" class="large-text %4$s">%5$s</textarea>',
			esc_attr( $name ),
			$rows,
			esc_attr( $placeholder ),
			esc_attr( $extra_class ),
			esc_textarea( $value )
		);
	}

	private static function kind_select( string $current ): string {
		$labels = array(
			'apparel'   => __( 'Apparel (has a size chart)', 'printful-meta-helper' ),
			'accessory' => __( 'Accessory (mug, hat, sticker, poster)', 'printful-meta-helper' ),
			'digital'   => __( 'Digital', 'printful-meta-helper' ),
		);
		$html   = '<select name="pmh_kind" id="pmh_kind">';
		foreach ( PMH_Blank::KINDS as $kind ) {
			$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $kind ), selected( $current, $kind, false ), esc_html( $labels[ $kind ] ?? $kind ) );
		}
		return $html . '</select>';
	}

	/**
	 * Flat checklist of product categories, labelled with their parent path.
	 */
	private static function categories_checklist( array $selected ): string {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '<p>' . esc_html__( 'No product categories exist yet.', 'printful-meta-helper' ) . '</p>';
		}

		$by_id = array();
		foreach ( $terms as $term ) {
			$by_id[ $term->term_id ] = $term;
		}
		$path = static function ( WP_Term $term ) use ( $by_id ): string {
			$names = array( $term->name );
			$guard = 0;
			while ( $term->parent && isset( $by_id[ $term->parent ] ) && $guard++ < 10 ) {
				$term    = $by_id[ $term->parent ];
				$names[] = $term->name;
			}
			return implode( ' › ', array_reverse( $names ) );
		};

		$items = array();
		foreach ( $terms as $term ) {
			$items[ $path( $term ) ] = $term;
		}
		ksort( $items, SORT_NATURAL | SORT_FLAG_CASE );

		$html = '<ul class="pmh-checklist" id="pmh_cats">';
		foreach ( $items as $label => $term ) {
			$html .= sprintf(
				'<li><label><input type="checkbox" name="pmh_cats[]" value="%1$d"%2$s> %3$s</label></li>',
				(int) $term->term_id,
				checked( in_array( $term->term_id, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		return $html . '</ul>';
	}

	private static function chart_json( array $chart ): string {
		if ( PMH_Size_Chart::is_empty( $chart ) ) {
			return '';
		}
		return (string) wp_json_encode( $chart, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/* ------------------------------------------------------------------ */
	/* Saving                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param int $term_id Blank being created or edited.
	 */
	public static function save( $term_id ): void {
		$term_id = (int) $term_id;
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitised below.
		$result = self::collect( is_array( $post ) ? $post : array() );

		PMH_Blank::update( $term_id, $result['data'] );
		PMH_Notices::set( 'blank', $result['messages'], $result['errors'] );
	}

	/**
	 * Turn the submitted form into sanitised blank data plus notices.
	 * No side effects, so it is unit-tested directly.
	 *
	 * @param array $post Unslashed POST fields.
	 * @return array{data: array, messages: string[], errors: string[]}
	 */
	public static function collect( array $post ): array {
		$messages = array();
		$errors   = array();

		$data = self::collect_basics( $post );
		$data = array_merge( $data, self::collect_materials( $post, $messages, $errors ) );
		$data = array_merge( $data, self::collect_charts( $post, $messages, $errors ) );

		return compact( 'data', 'messages', 'errors' );
	}

	private static function collect_basics( array $post ): array {
		$kind = isset( $post['pmh_kind'] ) ? sanitize_key( (string) $post['pmh_kind'] ) : 'apparel';
		return array(
			'cats'         => self::sanitise_cats( $post['pmh_cats'] ?? array() ),
			'kind'         => in_array( $kind, PMH_Blank::KINDS, true ) ? $kind : 'apparel',
			'handling_min' => self::sanitise_int_or_empty( $post['pmh_handling_min'] ?? '' ),
			'handling_max' => self::sanitise_int_or_empty( $post['pmh_handling_max'] ?? '' ),
		);
	}

	/**
	 * A pasted Printful paragraph overrides the individual fields.
	 */
	private static function collect_materials( array $post, array &$messages, array &$errors ): array {
		$paste = isset( $post['pmh_materials_paste'] ) ? trim( (string) $post['pmh_materials_paste'] ) : '';
		if ( '' !== $paste ) {
			$split = PMH_Importer::materials_from_text( $paste );
			if ( '' === $split['material_solid'] && '' === $split['construction'] && '' === $split['fabric_weight'] ) {
				$errors[] = __( 'Materials paste: no bullet lines found, fields left unchanged.', 'printful-meta-helper' );
			} else {
				$post       = array_merge(
					$post,
					array(
						'pmh_material_solid'      => $split['material_solid'],
						'pmh_material_exceptions' => $split['material_exceptions'],
						'pmh_fabric_weight'       => $split['fabric_weight'],
						'pmh_construction'        => $split['construction'],
					)
				);
				$messages[] = __( 'Materials imported from the pasted paragraph.', 'printful-meta-helper' );
			}
		}
		return array(
			'material_solid'      => sanitize_text_field( (string) ( $post['pmh_material_solid'] ?? '' ) ),
			'material_exceptions' => self::sanitise_lines( (string) ( $post['pmh_material_exceptions'] ?? '' ) ),
			'fabric_weight'       => sanitize_text_field( (string) ( $post['pmh_fabric_weight'] ?? '' ) ),
			'construction'        => self::sanitise_lines( (string) ( $post['pmh_construction'] ?? '' ) ),
			'care'                => self::sanitise_lines( (string) ( $post['pmh_care'] ?? '' ) ),
		);
	}

	/**
	 * Charts, in precedence order: pasted JSON, then a product reference,
	 * then the pasted table, then whatever the grid/textareas hold. An
	 * import that fails leaves the textarea values in charge.
	 */
	private static function collect_charts( array $post, array &$messages, array &$errors ): array {
		$imported = self::import_charts( $post, $messages, $errors );
		$data     = array();

		if ( isset( $imported['chart'] ) ) {
			$data['chart'] = $imported['chart'];
		} else {
			$parsed = self::chart_from_textarea( (string) ( $post['pmh_chart'] ?? '' ), __( 'Garment chart', 'printful-meta-helper' ), $errors );
			if ( null !== $parsed ) {
				$data['chart'] = $parsed;
			}
		}

		if ( isset( $imported['body_chart'] ) ) {
			$data['body_chart'] = $imported['body_chart'];
		} else {
			$parsed = self::chart_from_textarea( (string) ( $post['pmh_body_chart'] ?? '' ), __( 'Body chart', 'printful-meta-helper' ), $errors );
			if ( null !== $parsed ) {
				$data['body_chart'] = $parsed;
			}
		}

		return $data;
	}

	/**
	 * @return array{chart?: array, body_chart?: array} Only the charts an
	 *         import produced.
	 */
	private static function import_charts( array $post, array &$messages, array &$errors ): array {
		$json    = isset( $post['pmh_import_json'] ) ? trim( (string) $post['pmh_import_json'] ) : '';
		$product = isset( $post['pmh_import_product'] ) ? trim( (string) $post['pmh_import_product'] ) : '';
		$text    = isset( $post['pmh_import_text'] ) ? trim( (string) $post['pmh_import_text'] ) : '';

		if ( '' !== $json ) {
			if ( strlen( $json ) > 512 * 1024 ) {
				$errors[] = __( 'JSON import: paste is too large (limit 512 KB).', 'printful-meta-helper' );
				return array();
			}
			try {
				return self::charts_from_result( PMH_Importer::from_json( $json ), __( 'JSON', 'printful-meta-helper' ), $messages, $errors );
			} catch ( PMH_Import_Exception $e ) {
				$errors[] = __( 'JSON import failed: ', 'printful-meta-helper' ) . $e->getMessage();
				return array();
			}
		}

		if ( '' !== $product ) {
			$product_id = PMH_Util::resolve_product_id( $product );
			if ( ! $product_id ) {
				/* translators: %s: what the user typed */
				$errors[] = sprintf( __( 'Import from product: no product found for "%s".', 'printful-meta-helper' ), $product );
				return array();
			}
			$result = PMH_Importer::from_product_meta( $product_id );
			if ( ! $result ) {
				/* translators: %d: product ID */
				$errors[] = sprintf( __( 'Import from product: product %d has no readable Printful size chart (legacy products never do).', 'printful-meta-helper' ), $product_id );
				return array();
			}
			/* translators: %d: product ID */
			return self::charts_from_result( $result, sprintf( __( 'product %d', 'printful-meta-helper' ), $product_id ), $messages, $errors );
		}

		if ( '' !== $text ) {
			$unit = ( $post['pmh_import_text_unit'] ?? 'in' ) === 'cm' ? 'cm' : 'in';
			try {
				$chart      = self::sanitise_chart( PMH_Importer::from_text( $text, $unit ) );
				$messages[] = sprintf(
					/* translators: %d: row count */
					__( 'Garment chart imported from pasted table: %d rows.', 'printful-meta-helper' ),
					count( $chart['rows'] )
				);
				return array( 'chart' => $chart );
			} catch ( PMH_Import_Exception $e ) {
				$errors[] = __( 'Table import failed: ', 'printful-meta-helper' ) . $e->getMessage();
				return array();
			}
		}

		return array();
	}

	/**
	 * @param array{product: ?array, body: ?array} $result Importer output.
	 * @param string $source Human label for the notice.
	 */
	private static function charts_from_result( array $result, string $source, array &$messages, array &$errors ): array {
		$out = array();
		if ( $result['product'] ) {
			$out['chart'] = self::sanitise_chart( $result['product'] );
		}
		if ( $result['body'] ) {
			$out['body_chart'] = self::sanitise_chart( $result['body'] );
		}
		if ( ! $out ) {
			/* translators: %s: import source */
			$errors[] = sprintf( __( 'Import from %s: no inch rows found in either table.', 'printful-meta-helper' ), $source );
			return array();
		}
		$messages[] = sprintf(
			/* translators: 1: import source, 2: garment row count, 3: body row count */
			__( 'Size chart imported from %1$s: %2$d garment rows, %3$d body rows.', 'printful-meta-helper' ),
			$source,
			isset( $out['chart'] ) ? count( $out['chart']['rows'] ) : 0,
			isset( $out['body_chart'] ) ? count( $out['body_chart']['rows'] ) : 0
		);
		return $out;
	}

	/**
	 * Parse the chart JSON textarea. Returns the normalised chart, an empty
	 * chart when the textarea is blank, or null (leave unchanged) on error.
	 */
	private static function chart_from_textarea( string $json, string $label, array &$errors ): ?array {
		$json = trim( $json );
		if ( '' === $json ) {
			return PMH_Size_Chart::empty_chart();
		}
		$decoded = json_decode( $json, true, 16 );
		if ( ! is_array( $decoded ) ) {
			/* translators: %s: field label */
			$errors[] = sprintf( __( '%s: not valid JSON, left unchanged.', 'printful-meta-helper' ), $label );
			return null;
		}
		$chart = self::sanitise_chart( PMH_Size_Chart::normalise( $decoded ) );
		if ( PMH_Size_Chart::is_empty( $chart ) ) {
			/* translators: %s: field label */
			$errors[] = sprintf( __( '%s: JSON parsed but contained no usable rows, left unchanged.', 'printful-meta-helper' ), $label );
			return null;
		}
		return $chart;
	}

	/**
	 * Strings inside a normalised chart pass through WordPress sanitisers;
	 * numbers are already floats.
	 */
	private static function sanitise_chart( array $chart ): array {
		$chart['sizes'] = array_map( 'sanitize_text_field', $chart['sizes'] );
		$chart['note']  = sanitize_text_field( $chart['note'] );
		foreach ( $chart['rows'] as &$row ) {
			$row['label'] = sanitize_text_field( $row['label'] );
		}
		unset( $row );
		return PMH_Size_Chart::normalise( $chart );
	}

	/**
	 * @return int[] Existing product_cat IDs only.
	 */
	private static function sanitise_cats( $raw ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$existing = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'include'    => $ids,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		return is_wp_error( $existing ) ? array() : array_values( array_map( 'intval', $existing ) );
	}

	private static function sanitise_lines( string $text ): string {
		return implode( "\n", array_map( 'sanitize_text_field', PMH_Util::lines( $text ) ) );
	}

	private static function sanitise_int_or_empty( $raw ): string {
		$raw = trim( (string) $raw );
		return '' === $raw ? '' : (string) absint( $raw );
	}

	/* ------------------------------------------------------------------ */
	/* Notices                                                            */
	/* ------------------------------------------------------------------ */

	public static function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || PMH_TAXONOMY !== $screen->taxonomy ) {
			return;
		}
		PMH_Notices::render( PMH_Notices::take( 'blank' ) );
	}
}
