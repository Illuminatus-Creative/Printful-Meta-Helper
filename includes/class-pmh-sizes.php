<?php
/**
 * Which sizes a product can actually be bought in.
 *
 * Reads the product's visible variations (published, priced, and subject to
 * the store's own "hide out of stock" setting) and their size attribute.
 * Stock is otherwise ignored on purpose: the variation buttons already show
 * availability, and a chart that changes with stock goes stale in page caches.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Sizes {

	/** Object-cache group for computed size results. */
	private const CACHE_GROUP = 'pmh_sizes';

	public static function init(): void {
		// Product and variation saves already bump WooCommerce's per-product
		// cache prefix, which our key includes. Stock changes can alter the
		// visible children without a save when the store hides out-of-stock
		// items, so drop the entry on those too.
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_for_product' ) );
		}
	}

	/** No size attribute on the product: the full chart applies. */
	public const UNFILTERED = 'unfiltered';

	/** Sizes found: intersect with the chart. */
	public const SIZES = 'sizes';

	/** A size attribute exists but no visible variation carries a value. */
	public const NONE = 'none';

	/** Words that identify the size attribute, and the colour attribute. */
	private const SIZE_NEEDLES   = array( 'size' );
	private const COLOUR_NEEDLES = array( 'colour', 'color' );

	/**
	 * Sizes the product can be bought in.
	 *
	 * @return array{state: string, sizes: string[], attribute: string}
	 */
	public static function for_product( int $product_id ): array {
		$values = self::attribute_values( $product_id, 'size', self::SIZE_NEEDLES );
		$sizes  = array_values( array_unique( array_filter( array_map( array( PMH_Size_Chart::class, 'normalise_size' ), $values['values'] ) ) ) );
		return array(
			'state'     => self::UNFILTERED === $values['state'] ? self::UNFILTERED : ( $sizes ? self::SIZES : self::NONE ),
			'sizes'     => $sizes,
			'attribute' => $values['attribute'],
		);
	}

	/**
	 * Colours the product can be bought in, as attribute names ("Sport Grey").
	 * UNFILTERED when the product has no colour attribute.
	 *
	 * @return array{state: string, colours: string[], attribute: string}
	 */
	public static function colours_for_product( int $product_id ): array {
		$values = self::attribute_values( $product_id, 'colour', self::COLOUR_NEEDLES );
		return array(
			'state'     => $values['state'],
			'colours'   => $values['values'],
			'attribute' => $values['attribute'],
		);
	}

	/**
	 * Distinct values of one variation attribute across the product's
	 * visible variations, cached per product and attribute group.
	 *
	 * @param string   $group   Cache tag and filter suffix: 'size' or 'colour'.
	 * @param string[] $needles Words that identify the attribute by name or label.
	 * @return array{state: string, values: string[], attribute: string}
	 */
	private static function attribute_values( int $product_id, string $group, array $needles ): array {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$product_id = (int) $product->get_parent_id();
			$product    = wc_get_product( $product_id );
		}

		$key    = self::cache_key( $product_id, $group );
		$cached = $key ? wp_cache_get( $key, self::CACHE_GROUP ) : false;
		if ( ! is_array( $cached ) ) {
			$cached = self::compute( $product, $group, $needles );
			if ( $key ) {
				wp_cache_set( $key, $cached, self::CACHE_GROUP );
			}
		}
		return self::filtered( $cached, $product, $group );
	}

	/**
	 * Drop the cached result for a product or variation (parent resolved).
	 *
	 * @param int|WC_Product $product Product, variation, or its ID.
	 */
	public static function flush_for_product( $product ): void {
		$id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
		if ( $product instanceof WC_Product && $product->get_parent_id() ) {
			$id = (int) $product->get_parent_id();
		} elseif ( 'product_variation' === get_post_type( $id ) ) {
			$id = (int) wp_get_post_parent_id( $id );
		}
		foreach ( array( 'size', 'colour' ) as $group ) {
			$key = self::cache_key( $id, $group );
			if ( $key ) {
				wp_cache_delete( $key, self::CACHE_GROUP );
			}
		}
	}

	/**
	 * Cache key that changes whenever WooCommerce invalidates the product
	 * (its prefix), or the store's hide-out-of-stock setting flips (which
	 * changes the visible children without touching the product). Empty
	 * when WooCommerce's cache helper is unavailable.
	 */
	private static function cache_key( int $product_id, string $group = 'size' ): string {
		if ( $product_id <= 0 || ! class_exists( 'WC_Cache_Helper' ) ) {
			return '';
		}
		$hide = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ? '1' : '0';
		return WC_Cache_Helper::get_cache_prefix( 'product_' . $product_id ) . $group . 's_' . $product_id . '_' . $hide;
	}

	/**
	 * The pmh_product_sizes / pmh_product_colours filter runs on every call,
	 * never on the cached value, so a filter that depends on request context
	 * keeps working.
	 */
	private static function filtered( array $result, $product, string $group ): array {
		if ( self::SIZES !== $result['state'] || ! $product ) {
			return $result;
		}
		/**
		 * Filter the purchasable values read from a product's variations.
		 * pmh_product_sizes receives canonical size labels;
		 * pmh_product_colours receives colour attribute names.
		 *
		 * @param string[]   $values    Values.
		 * @param WC_Product $product   Parent product.
		 * @param string     $attribute Attribute name used ('pa_size', 'color').
		 */
		$values           = (array) apply_filters( 'pmh_product_' . $group . 's', $result['values'], $product, $result['attribute'] );
		$result['values'] = array_values( $values );
		$result['state']  = $values ? self::SIZES : self::NONE;
		return $result;
	}

	/**
	 * The uncached computation: read one attribute from every visible
	 * variation.
	 *
	 * @param WC_Product|false|null $product Parent product.
	 * @param string[]              $needles Words identifying the attribute.
	 */
	private static function compute( $product, string $group, array $needles ): array {
		$result = array(
			'state'     => self::UNFILTERED,
			'values'    => array(),
			'attribute' => '',
		);
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $result;
		}

		$attribute = self::find_attribute( $product, $group, $needles );
		if ( '' === $attribute ) {
			return $result;
		}
		$result['attribute'] = $attribute;

		$meta_key = wc_variation_attribute_name( $attribute );
		$children = array_map( 'intval', $product->get_visible_children() );
		$raws     = array();

		// One query for every variation's meta instead of one per variation.
		// A no-op for IDs WooCommerce's own add-to-cart form already primed.
		if ( $children ) {
			update_meta_cache( 'post', $children );
		}

		foreach ( $children as $child_id ) {
			$raw = get_post_meta( $child_id, $meta_key, true );
			if ( '' === $raw || null === $raw ) {
				// "Any …" variation: every value is purchasable.
				return $result;
			}
			$raws[ (string) $raw ] = true;
		}

		$values = array_values( array_unique( array_filter( array_map( 'trim', self::resolve_values( $attribute, array_keys( $raws ) ) ) ) ) );

		$result['values'] = $values;
		$result['state']  = $values ? self::SIZES : self::NONE;
		return $result;
	}

	/**
	 * Name of the variation attribute that holds sizes: 'pa_size' for a
	 * global attribute, the sanitised label ('size') for a custom one.
	 */
	public static function find_size_attribute( WC_Product $product ): string {
		return self::find_attribute( $product, 'size', self::SIZE_NEEDLES );
	}

	/**
	 * The variation attribute whose name or label contains one of the
	 * needles. Filterable as pmh_size_attribute / pmh_colour_attribute.
	 *
	 * @param string[] $needles Case-insensitive words to look for.
	 */
	private static function find_attribute( WC_Product $product, string $group, array $needles ): string {
		$found = '';
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$name  = $attribute->get_name();
			$label = wc_attribute_label( $name, $product );
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $name, $needle ) || false !== stripos( $label, $needle ) ) {
					$found = $attribute->is_taxonomy() ? $name : sanitize_title( $name );
					break 2;
				}
			}
		}

		/**
		 * Filter which variation attribute is treated as the size (or colour).
		 *
		 * @param string     $found   Attribute name, '' when none matched.
		 * @param WC_Product $product Parent product.
		 */
		return (string) apply_filters( 'pmh_' . $group . '_attribute', $found, $product );
	}

	/**
	 * Variation meta stores a term slug for taxonomy attributes; turn the
	 * distinct slugs back into names ("2xl" -> "2XL") with one term query,
	 * preserving order. Values with no matching term, and custom (non-
	 * taxonomy) attributes, come back unchanged.
	 *
	 * @param string[] $raws Distinct raw meta values.
	 * @return string[]
	 */
	public static function resolve_values( string $attribute, array $raws ): array {
		if ( ! $raws || ! taxonomy_exists( $attribute ) ) {
			return array_values( $raws );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $attribute,
				'slug'       => $raws,
				'hide_empty' => false,
			)
		);
		$names = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$names[ $term->slug ] = $term->name;
			}
		}
		return array_map( static fn( string $raw ): string => $names[ $raw ] ?? $raw, array_values( $raws ) );
	}

	/**
	 * Apply a for_product() result to a chart.
	 *
	 * @return array{chart: ?array, state: string} chart is null when nothing
	 *         should render; state is 'unfiltered', 'filtered', 'no_variations'
	 *         or 'no_overlap' (sizes exist but none match the chart: a data
	 *         mismatch an editor should see).
	 */
	public static function apply( array $chart, array $result ): array {
		if ( PMH_Size_Chart::is_empty( $chart ) ) {
			return array(
				'chart' => null,
				'state' => 'empty_chart',
			);
		}
		switch ( $result['state'] ) {
			case self::UNFILTERED:
				return array(
					'chart' => PMH_Size_Chart::normalise( $chart ),
					'state' => 'unfiltered',
				);
			case self::NONE:
				return array(
					'chart' => null,
					'state' => 'no_variations',
				);
		}
		$filtered = PMH_Size_Chart::filter_sizes( $chart, $result['sizes'] );
		if ( PMH_Size_Chart::is_empty( $filtered ) ) {
			return array(
				'chart' => null,
				'state' => 'no_overlap',
			);
		}
		return array(
			'chart' => $filtered,
			'state' => 'filtered',
		);
	}
}
