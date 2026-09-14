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
	private const NOTICE_KEY   = 'pmh_blank_notice_';

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

		self::heading( $mode, __( 'Applies to', 'printful-meta-helper' ) );
		self::field(
			$mode,
			'pmh_cats',
			__( 'Product categories', 'printful-meta-helper' ),
			self::categories_checklist( $data['cats'] ),
			__( 'Blank is offered for products in these categories. Leave all unticked to offer it everywhere.', 'printful-meta-helper' )
		);
		self::field(
			$mode,
			'pmh_kind',
			__( 'Kind', 'printful-meta-helper' ),
			self::kind_select( $data['kind'] ),
			__( 'Only apparel renders a size chart.', 'printful-meta-helper' )
		);

		self::heading( $mode, __( 'Materials', 'printful-meta-helper' ) );
		self::field( $mode, 'pmh_material_solid', __( 'Base material', 'printful-meta-helper' ), self::text( 'pmh_material_solid', $data['material_solid'], '100% cotton' ) );
		self::field(
			$mode,
			'pmh_material_exceptions',
			__( 'Colour exceptions', 'printful-meta-helper' ),
			self::textarea( 'pmh_material_exceptions', $data['material_exceptions'], 3, "Sport Grey is 90% cotton, 10% polyester\nHeather colors are 50% cotton, 50% polyester" ),
			__( 'One per line.', 'printful-meta-helper' )
		);
		self::field( $mode, 'pmh_fabric_weight', __( 'Fabric weight', 'printful-meta-helper' ), self::text( 'pmh_fabric_weight', $data['fabric_weight'], '5.0–5.3 oz/yd² (170-180 g/m²)' ) );
		self::field( $mode, 'pmh_construction', __( 'Construction', 'printful-meta-helper' ), self::textarea( 'pmh_construction', $data['construction'], 4, "Tubular fabric\nTaped neck and shoulders" ), __( 'One per line.', 'printful-meta-helper' ) );
		self::field( $mode, 'pmh_care', __( 'Care', 'printful-meta-helper' ), self::textarea( 'pmh_care', $data['care'], 3 ), __( 'One per line.', 'printful-meta-helper' ) );
		self::field(
			$mode,
			'pmh_materials_paste',
			__( 'Paste from Printful', 'printful-meta-helper' ),
			self::textarea( 'pmh_materials_paste', '', 5, "• 100% cotton\n• Sport Grey is 90% cotton, 10% polyester\n• Fabric weight: 5.0–5.3 oz/yd²\n• Tubular fabric" ),
			__( 'Paste the bulleted materials paragraph. On save it is split into the fields above, replacing them. Leave empty to keep the fields as they are.', 'printful-meta-helper' )
		);

		self::heading( $mode, __( 'Size chart', 'printful-meta-helper' ) );
		self::field(
			$mode,
			'pmh_import_json',
			__( 'Import Printful JSON', 'printful-meta-helper' ),
			self::textarea( 'pmh_import_json', '', 6, '{"availableSizes":["S","M"],"productMeasurements":{...},"modelMeasurements":{...}}' ),
			__( 'Paste the size-guide JSON. On save, the garment chart and body chart below are replaced with the inch rows. Leave empty to keep them.', 'printful-meta-helper' )
		);
		self::field(
			$mode,
			'pmh_import_product',
			__( 'Import from product', 'printful-meta-helper' ),
			self::text( 'pmh_import_product', '', '123 or SKU' ),
			__( 'Product ID or SKU. On save, the size-guide JSON Printful stored on that product (pf_advanced_size_chart) replaces the charts below. Only products Printful has pushed carry it. Ignored if JSON is pasted above.', 'printful-meta-helper' )
		);
		self::field(
			$mode,
			'pmh_import_text',
			__( 'Import pasted table', 'printful-meta-helper' ),
			self::textarea( 'pmh_import_text', '', 6, "Size Label\tLength\tWidth\nS\t28\t18\nM\t29\t20" )
			. '<p><label><input type="radio" name="pmh_import_text_unit" value="in" checked> ' . esc_html__( 'Values are inches', 'printful-meta-helper' ) . '</label> &nbsp; <label><input type="radio" name="pmh_import_text_unit" value="cm"> ' . esc_html__( 'Values are centimetres', 'printful-meta-helper' ) . '</label></p>',
			__( 'Copy the table from the Printful page (columns separated by tabs). On save it replaces the garment chart. Ignored if JSON is also pasted.', 'printful-meta-helper' )
		);
		self::field(
			$mode,
			'pmh_chart',
			__( 'Garment chart (inches)', 'printful-meta-helper' ),
			self::textarea( 'pmh_chart', self::chart_json( $data['chart'] ), 12, '', 'code pmh-chart-json' ),
			__( 'Measurements of the garment laid flat, rendered by [pmh_size_chart]. Columns are sizes, rows are measurements; a cell takes 28, 34-37 or 16 ½. Inches only; centimetres are computed. "Edit as JSON" shows the stored structure.', 'printful-meta-helper' )
		);
		self::field(
			$mode,
			'pmh_body_chart',
			__( 'Body chart (inches)', 'printful-meta-helper' ),
			self::textarea( 'pmh_body_chart', self::chart_json( $data['body_chart'] ), 8, '', 'code pmh-chart-json' ),
			__( 'Body measurements ("measure yourself"). Stored separately; rendered only when a shortcode asks for it.', 'printful-meta-helper' )
		);

		self::heading( $mode, __( 'Parked', 'printful-meta-helper' ) );
		self::field(
			$mode,
			'pmh_handling',
			__( 'Handling time (days)', 'printful-meta-helper' ),
			'<input type="number" min="0" step="1" name="pmh_handling_min" id="pmh_handling_min" value="' . esc_attr( $data['handling_min'] ) . '" class="small-text"> – <input type="number" min="0" step="1" name="pmh_handling_max" id="pmh_handling_max" value="' . esc_attr( $data['handling_max'] ) . '" class="small-text">',
			__( 'Reserved for later feed work. Leave empty.', 'printful-meta-helper' )
		);
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
	 * the add screen, tr.form-field on the edit screen.
	 */
	private static function field( string $mode, string $id, string $label, string $control, string $description = '' ): void {
		$desc = '' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : '';
		if ( 'add' === $mode ) {
			printf(
				'<div class="form-field pmh-field pmh-field--%1$s"><label for="%1$s">%2$s</label>%3$s%4$s</div>',
				esc_attr( $id ),
				esc_html( $label ),
				$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
				$desc // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		} else {
			printf(
				'<tr class="form-field pmh-field pmh-field--%1$s"><th scope="row"><label for="%1$s">%2$s</label></th><td>%3$s%4$s</td></tr>',
				esc_attr( $id ),
				esc_html( $label ),
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

		$post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitised below.
		$messages = array();
		$errors   = array();
		$data     = array();

		// Applies-to and kind.
		$data['cats'] = self::sanitise_cats( $post['pmh_cats'] ?? array() );
		$kind         = isset( $post['pmh_kind'] ) ? sanitize_key( $post['pmh_kind'] ) : 'apparel';
		$data['kind'] = in_array( $kind, PMH_Blank::KINDS, true ) ? $kind : 'apparel';

		// Materials: pasted paragraph overrides the individual fields.
		$materials_paste = isset( $post['pmh_materials_paste'] ) ? trim( (string) $post['pmh_materials_paste'] ) : '';
		if ( '' !== $materials_paste ) {
			$split = PMH_Importer::materials_from_text( $materials_paste );
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
		$data['material_solid']      = sanitize_text_field( (string) ( $post['pmh_material_solid'] ?? '' ) );
		$data['material_exceptions'] = self::sanitise_lines( (string) ( $post['pmh_material_exceptions'] ?? '' ) );
		$data['fabric_weight']       = sanitize_text_field( (string) ( $post['pmh_fabric_weight'] ?? '' ) );
		$data['construction']        = self::sanitise_lines( (string) ( $post['pmh_construction'] ?? '' ) );
		$data['care']                = self::sanitise_lines( (string) ( $post['pmh_care'] ?? '' ) );

		// Handling (parked).
		$data['handling_min'] = self::sanitise_int_or_empty( $post['pmh_handling_min'] ?? '' );
		$data['handling_max'] = self::sanitise_int_or_empty( $post['pmh_handling_max'] ?? '' );

		// Charts: JSON import > text import > edited textareas.
		$import_json    = isset( $post['pmh_import_json'] ) ? trim( (string) $post['pmh_import_json'] ) : '';
		$import_product = isset( $post['pmh_import_product'] ) ? trim( (string) $post['pmh_import_product'] ) : '';
		$import_text    = isset( $post['pmh_import_text'] ) ? trim( (string) $post['pmh_import_text'] ) : '';
		$imported       = false;

		if ( '' !== $import_json ) {
			if ( strlen( $import_json ) > 512 * 1024 ) {
				$errors[] = __( 'JSON import: paste is too large (limit 512 KB).', 'printful-meta-helper' );
			} else {
				try {
					$result = PMH_Importer::from_json( $import_json );
					if ( $result['product'] ) {
						$data['chart'] = self::sanitise_chart( $result['product'] );
					}
					if ( $result['body'] ) {
						$data['body_chart'] = self::sanitise_chart( $result['body'] );
					}
					if ( ! $result['product'] && ! $result['body'] ) {
						$errors[] = __( 'JSON import: no inch rows found in either table.', 'printful-meta-helper' );
					} else {
						$imported   = true;
						$messages[] = sprintf(
							/* translators: 1: garment row count, 2: body row count */
							__( 'Size chart imported from JSON: %1$d garment rows, %2$d body rows.', 'printful-meta-helper' ),
							$result['product'] ? count( $result['product']['rows'] ) : 0,
							$result['body'] ? count( $result['body']['rows'] ) : 0
						);
					}
				} catch ( PMH_Import_Exception $e ) {
					$errors[] = __( 'JSON import failed: ', 'printful-meta-helper' ) . $e->getMessage();
				}
			}
		} elseif ( '' !== $import_product ) {
			$product_id = self::resolve_product_ref( $import_product );
			if ( ! $product_id ) {
				/* translators: %s: what the user typed */
				$errors[] = sprintf( __( 'Import from product: no product found for "%s".', 'printful-meta-helper' ), $import_product );
			} else {
				$result = PMH_Importer::from_product_meta( $product_id );
				if ( ! $result || ( ! $result['product'] && ! $result['body'] ) ) {
					/* translators: %d: product ID */
					$errors[] = sprintf( __( 'Import from product: product %d has no readable Printful size chart (legacy products never do).', 'printful-meta-helper' ), $product_id );
				} else {
					if ( $result['product'] ) {
						$data['chart'] = self::sanitise_chart( $result['product'] );
					}
					if ( $result['body'] ) {
						$data['body_chart'] = self::sanitise_chart( $result['body'] );
					}
					$imported   = true;
					$messages[] = sprintf(
						/* translators: 1: product ID, 2: garment row count, 3: body row count */
						__( 'Size chart imported from product %1$d: %2$d garment rows, %3$d body rows.', 'printful-meta-helper' ),
						$product_id,
						$result['product'] ? count( $result['product']['rows'] ) : 0,
						$result['body'] ? count( $result['body']['rows'] ) : 0
					);
				}
			}
		} elseif ( '' !== $import_text ) {
			$unit = ( $post['pmh_import_text_unit'] ?? 'in' ) === 'cm' ? 'cm' : 'in';
			try {
				$data['chart'] = self::sanitise_chart( PMH_Importer::from_text( $import_text, $unit ) );
				$imported      = true;
				$messages[]    = sprintf(
					/* translators: %d: row count */
					__( 'Garment chart imported from pasted table: %d rows.', 'printful-meta-helper' ),
					count( $data['chart']['rows'] )
				);
			} catch ( PMH_Import_Exception $e ) {
				$errors[] = __( 'Table import failed: ', 'printful-meta-helper' ) . $e->getMessage();
			}
		}

		if ( ! $imported || ! isset( $data['chart'] ) ) {
			$parsed = self::chart_from_textarea( (string) ( $post['pmh_chart'] ?? '' ), __( 'Garment chart', 'printful-meta-helper' ), $errors );
			if ( null !== $parsed ) {
				$data['chart'] = $parsed;
			}
		}
		if ( ! $imported || ! isset( $data['body_chart'] ) ) {
			$parsed = self::chart_from_textarea( (string) ( $post['pmh_body_chart'] ?? '' ), __( 'Body chart', 'printful-meta-helper' ), $errors );
			if ( null !== $parsed ) {
				$data['body_chart'] = $parsed;
			}
		}

		PMH_Blank::update( $term_id, $data );

		if ( $messages || $errors ) {
			set_transient( self::NOTICE_KEY . get_current_user_id(), compact( 'messages', 'errors' ), 120 );
		}
	}

	/**
	 * A product ID or SKU typed by an editor -> product ID, or 0.
	 * Variations resolve to their parent, which is where Printful writes.
	 */
	private static function resolve_product_ref( string $ref ): int {
		$ref = trim( $ref );
		$id  = 0;
		if ( ctype_digit( $ref ) ) {
			$id = (int) $ref;
		} elseif ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = (int) wc_get_product_id_by_sku( $ref );
		}
		if ( ! $id ) {
			return 0;
		}
		$type = get_post_type( $id );
		if ( 'product_variation' === $type ) {
			$id   = (int) wp_get_post_parent_id( $id );
			$type = get_post_type( $id );
		}
		return 'product' === $type ? $id : 0;
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
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = sanitize_text_field( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
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
		$key    = self::NOTICE_KEY . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( (array) ( $notice['errors'] ?? array() ) as $text ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		}
		foreach ( (array) ( $notice['messages'] ?? array() ) as $text ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		}
	}
}
