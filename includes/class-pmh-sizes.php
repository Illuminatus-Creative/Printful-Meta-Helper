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
		$result  = array(
			'state'     => self::UNFILTERED,
			'sizes'     => array(),
			'attribute' => '',
		);
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $result;
		}
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $result;
		}

		$attribute = self::find_size_attribute( $product );
		if ( '' === $attribute ) {
			return $result;
		}
		$result['attribute'] = $attribute;

		$meta_key = wc_variation_attribute_name( $attribute );
		$sizes    = array();

		foreach ( $product->get_visible_children() as $child_id ) {
			$raw = get_post_meta( $child_id, $meta_key, true );
			if ( '' === $raw || null === $raw ) {
				// "Any size" variation: every size is purchasable.
				return $result;
			}
			$sizes[] = PMH_Size_Chart::normalise_size( self::resolve_value( $attribute, (string) $raw ) );
		}

		$sizes = array_values( array_unique( array_filter( $sizes ) ) );

		/**
		 * Filter the purchasable sizes read from a product's variations.
		 *
		 * @param string[]   $sizes     Canonical size labels.
		 * @param WC_Product $product   Parent product.
		 * @param string     $attribute Attribute name used ('pa_size', 'size').
		 */
		$sizes = (array) apply_filters( 'pmh_product_sizes', $sizes, $product, $attribute );

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
		if ( taxonomy_exists( $attribute ) ) {
			$term = get_term_by( 'slug', $raw, $attribute );
			if ( $term instanceof WP_Term ) {
				return $term->name;
			}
		}
		return $raw;
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
