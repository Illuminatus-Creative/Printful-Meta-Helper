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

	/**
	 * @return array{state: string, sizes: string[], attribute: string}
	 */
	public static function for_product( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$product_id = (int) $product->get_parent_id();
			$product    = wc_get_product( $product_id );
		}

		$key    = self::cache_key( $product_id );
		$cached = $key ? wp_cache_get( $key, self::CACHE_GROUP ) : false;
		if ( is_array( $cached ) ) {
			return self::filtered( $cached, $product );
		}

		$result = self::compute( $product );

		if ( $key ) {
			wp_cache_set( $key, $result, self::CACHE_GROUP );
		}
		return self::filtered( $result, $product );
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
		$key = self::cache_key( $id );
		if ( $key ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
		}
	}

	/**
	 * Cache key that changes whenever WooCommerce invalidates the product
	 * (its prefix), or the store's hide-out-of-stock setting flips (which
	 * changes the visible children without touching the product). Empty
	 * when WooCommerce's cache helper is unavailable.
	 */
	private static function cache_key( int $product_id ): string {
		if ( $product_id <= 0 || ! class_exists( 'WC_Cache_Helper' ) ) {
			return '';
		}
		$hide = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ? '1' : '0';
		return WC_Cache_Helper::get_cache_prefix( 'product_' . $product_id ) . 'sizes_' . $product_id . '_' . $hide;
	}

	/**
	 * The pmh_product_sizes filter runs on every call, never on the cached
	 * value, so a filter that depends on request context keeps working.
	 */
	private static function filtered( array $result, $product ): array {
		if ( self::SIZES !== $result['state'] || ! $product ) {
			return $result;
		}
		$sizes           = (array) apply_filters( 'pmh_product_sizes', $result['sizes'], $product, $result['attribute'] );
		$result['sizes'] = array_values( $sizes );
		$result['state'] = $sizes ? self::SIZES : self::NONE;
		return $result;
	}

	/**
	 * The uncached computation: read the size attribute from every visible
	 * variation.
	 *
	 * @param WC_Product|false|null $product Parent product.
	 */
	private static function compute( $product ): array {
		$result = array(
			'state'     => self::UNFILTERED,
			'sizes'     => array(),
			'attribute' => '',
		);
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $result;
		}

		$attribute = self::find_size_attribute( $product );
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
				// "Any size" variation: every size is purchasable.
				return $result;
			}
			$raws[ (string) $raw ] = true;
		}

		$sizes = array();
		foreach ( self::resolve_values( $attribute, array_keys( $raws ) ) as $name ) {
			$sizes[] = PMH_Size_Chart::normalise_size( $name );
		}
		/*
		 * Filter documented in filtered(): pmh_product_sizes( $sizes, $product,
		 * $attribute ) adjusts the purchasable sizes read from the variations.
		 */
		$sizes = array_values( array_unique( array_filter( $sizes ) ) );

		$result['sizes'] = $sizes;
		$result['state'] = $sizes ? self::SIZES : self::NONE;
		return $result;
	}

	/**
	 * Name of the variation attribute that holds sizes: 'pa_size' for a
	 * global attribute, the sanitised label ('size') for a custom one.
	 * Matches on "size" in the attribute name or its label.
	 */
	public static function find_size_attribute( WC_Product $product ): string {
		$found = '';
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$name  = $attribute->get_name();
			$label = wc_attribute_label( $name, $product );
			if ( false !== stripos( $name, 'size' ) || false !== stripos( $label, 'size' ) ) {
				$found = $attribute->is_taxonomy() ? $name : sanitize_title( $name );
				break;
			}
		}

		/**
		 * Filter which variation attribute is treated as the size.
		 *
		 * @param string     $found   Attribute name, '' when none matched.
		 * @param WC_Product $product Parent product.
		 */
		return (string) apply_filters( 'pmh_size_attribute', $found, $product );
	}

	/**
	 * Variation meta stores a term slug for taxonomy attributes; turn it back
	 * into the term name ("2xl" -> "2XL"). Custom attributes store the text.
	 */
	public static function resolve_value( string $attribute, string $raw ): string {
		return self::resolve_values( $attribute, array( $raw ) )[0] ?? $raw;
	}

	/**
	 * Resolve many raw values with a single term query, preserving order.
	 * Values with no matching term come back unchanged.
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
