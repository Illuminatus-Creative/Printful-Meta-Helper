<?php
/**
 * Registers the pmh_blank taxonomy and its admin behaviour.
 *
 * - Hierarchical so quick edit and bulk edit render a checklist.
 * - Private: no front-end archives, no rewrite, no query var.
 * - A product has exactly one blank; enforced on set_object_terms because
 *   bulk edit merges terms and a checklist allows more than one tick.
 * - Products list gets a filter dropdown including "No blank assigned".
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Taxonomy {

	/** Query arg used by the products-list filter dropdown. */
	private const FILTER_ARG = 'pmh_blank_filter';

	/** Value of FILTER_ARG meaning "products with no blank". */
	private const FILTER_NONE = 'none';

	/** Re-entrancy guard for the single-term enforcement. */
	private static bool $enforcing = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'enforce_single_term' ), 10, 6 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_list_filter' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_list_filter' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	public static function register(): void {
		$labels = array(
			'name'              => _x( 'Blanks', 'taxonomy general name', 'printful-meta-helper' ),
			'singular_name'     => _x( 'Blank', 'taxonomy singular name', 'printful-meta-helper' ),
			'menu_name'         => __( 'Blanks', 'printful-meta-helper' ),
			'all_items'         => __( 'All Blanks', 'printful-meta-helper' ),
			'edit_item'         => __( 'Edit Blank', 'printful-meta-helper' ),
			'view_item'         => __( 'View Blank', 'printful-meta-helper' ),
			'update_item'       => __( 'Update Blank', 'printful-meta-helper' ),
			'add_new_item'      => __( 'Add New Blank', 'printful-meta-helper' ),
			'new_item_name'     => __( 'New Blank Name', 'printful-meta-helper' ),
			'search_items'      => __( 'Search Blanks', 'printful-meta-helper' ),
			'not_found'         => __( 'No blanks found.', 'printful-meta-helper' ),
			'no_terms'          => __( 'No blank', 'printful-meta-helper' ),
			'items_list'        => __( 'Blanks list', 'printful-meta-helper' ),
			'back_to_items'     => __( '&larr; Go to Blanks', 'printful-meta-helper' ),
			'parent_item'       => null,
			'parent_item_colon' => null,
		);

		register_taxonomy(
			PMH_TAXONOMY,
			'product',
			array(
				'labels'                => $labels,
				'description'           => __( 'Base garment or item a product is printed on. Holds the size chart and materials.', 'printful-meta-helper' ),
				'hierarchical'          => true,
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'show_tagcloud'         => false,
				'show_in_quick_edit'    => true,
				'show_admin_column'     => true,
				'query_var'             => false,
				'rewrite'               => false,
				// Explicit: a custom meta_box_cb (phase 7) would otherwise fall
				// back to the name-based sanitiser and create junk terms from IDs.
				'meta_box_sanitize_cb'  => 'taxonomy_meta_box_sanitize_cb_checkboxes',
				'capabilities'          => array(
					'manage_terms' => 'manage_product_terms',
					'edit_terms'   => 'edit_product_terms',
					'delete_terms' => 'delete_product_terms',
					'assign_terms' => 'assign_product_terms',
				),
			)
		);
	}

	/**
	 * Keep exactly one blank per product.
	 *
	 * Bulk edit merges submitted terms with existing ones, and the checklist
	 * allows multiple ticks. When more than one term lands, keep the one that
	 * was newly added (the editor's most recent intent); if nothing is new,
	 * keep the last one in the list.
	 *
	 * @param int      $object_id  Product ID.
	 * @param array    $terms      Terms as submitted (ids, slugs or names).
	 * @param int[]    $tt_ids     Term taxonomy IDs now assigned.
	 * @param string   $taxonomy   Taxonomy slug.
	 * @param bool     $append     Whether terms were appended.
	 * @param int[]    $old_tt_ids Term taxonomy IDs previously assigned.
	 */
	public static function enforce_single_term( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( PMH_TAXONOMY !== $taxonomy || self::$enforcing ) {
			return;
		}

		$tt_ids     = array_values( array_unique( array_map( 'intval', (array) $tt_ids ) ) );
		$old_tt_ids = array_values( array_unique( array_map( 'intval', (array) $old_tt_ids ) ) );

		// With $append the action receives only the terms just added; the
		// product now holds the union.
		$final = $append ? array_values( array_unique( array_merge( $old_tt_ids, $tt_ids ) ) ) : $tt_ids;
		if ( count( $final ) < 2 ) {
			return;
		}

		$added      = array_values( array_diff( $tt_ids, $old_tt_ids ) );
		$keep_tt_id = $added ? end( $added ) : end( $final );

		$term = get_term_by( 'term_taxonomy_id', $keep_tt_id, PMH_TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		self::$enforcing = true;
		wp_set_object_terms( (int) $object_id, array( (int) $term->term_id ), PMH_TAXONOMY, false );
		self::$enforcing = false;
	}

	/**
	 * Dropdown on the products list: every blank plus "No blank assigned".
	 *
	 * @param string $post_type Current list post type.
	 * @param string $which     'top' or 'bottom'.
	 */
	public static function render_list_filter( $post_type, $which = 'top' ): void {
		if ( 'product' !== $post_type || 'top' !== $which ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => PMH_TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$current = isset( $_GET[ self::FILTER_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FILTER_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<label class="screen-reader-text" for="pmh-blank-filter">' . esc_html__( 'Filter by blank', 'printful-meta-helper' ) . '</label>';
		echo '<select name="' . esc_attr( self::FILTER_ARG ) . '" id="pmh-blank-filter">';
		echo '<option value="">' . esc_html__( 'All blanks', 'printful-meta-helper' ) . '</option>';
		echo '<option value="' . esc_attr( self::FILTER_NONE ) . '"' . selected( $current, self::FILTER_NONE, false ) . '>' . esc_html__( 'No blank assigned', 'printful-meta-helper' ) . '</option>';
		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$d"%2$s>%3$s (%4$d)</option>',
				(int) $term->term_id,
				selected( $current, (string) $term->term_id, false ),
				esc_html( $term->name ),
				(int) $term->count
			);
		}
		echo '</select>';
	}

	/**
	 * Apply the dropdown filter to the products list query.
	 *
	 * @param WP_Query $query Main admin query.
	 */
	public static function apply_list_filter( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( empty( $_GET[ self::FILTER_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$value     = sanitize_text_field( wp_unslash( $_GET[ self::FILTER_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		if ( self::FILTER_NONE === $value ) {
			$tax_query[] = array(
				'taxonomy' => PMH_TAXONOMY,
				'operator' => 'NOT EXISTS',
			);
		} elseif ( ctype_digit( $value ) ) {
			$tax_query[] = array(
				'taxonomy' => PMH_TAXONOMY,
				'field'    => 'term_id',
				'terms'    => (int) $value,
			);
		} else {
			return;
		}

		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Admin CSS on the blank term screens only (hides the parent field the
	 * hierarchical registration adds, which has no meaning for blanks).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_admin_assets( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || PMH_TAXONOMY !== $screen->taxonomy ) {
			return;
		}
		wp_enqueue_style( 'pmh-admin', PMH_URL . 'admin/css/admin.css', array(), PMH_VERSION );
	}
}
