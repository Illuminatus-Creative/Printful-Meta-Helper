<?php

use PHPUnit\Framework\TestCase;

/**
 * PMH_Sizes::for_product against the WooCommerce fakes: attribute
 * discovery, slug resolution, aliases, "Any size" and the three states.
 */
final class SizesProductTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
		PMH_Fake_WC::$products = array();
		foreach ( array( 'S', 'M', 'L', 'XL', '2XL' ) as $i => $size ) {
			PMH_Fake_WP::add_term( 'pa_size', $size, 500 + $i, strtolower( $size ) );
		}
		PMH_Fake_WP::add_post( 1 );
	}

	/** @param array<int, ?string> $sizes child id => raw meta value (null: no meta row) */
	private function product( array $sizes, array $attributes, string $meta_key = 'attribute_pa_size' ): void {
		foreach ( $sizes as $cid => $raw ) {
			PMH_Fake_WP::add_post( $cid, 'product_variation', array( 'post_parent' => 1 ) );
			PMH_Fake_WC::$products[ $cid ] = new PMH_Fake_Variation( $cid, 1 );
			if ( null !== $raw ) {
				PMH_Fake_WP::$post_meta[ $cid ][ $meta_key ] = $raw;
			}
		}
		PMH_Fake_WC::$products[1] = new PMH_Fake_Variable( 1, array_keys( $sizes ), $attributes );
	}

	public function test_global_attribute_slugs_resolve_to_names_and_dedupe(): void {
		$this->product( array( 10 => 's', 11 => 'm', 12 => 's', 13 => '2xl' ), array( new PMH_Fake_Attribute( 'pa_color', true ), new PMH_Fake_Attribute( 'pa_size', true ) ) );
		$r = PMH_Sizes::for_product( 1 );
		self::assertSame( PMH_Sizes::SIZES, $r['state'] );
		self::assertSame( array( 'S', 'M', '2XL' ), $r['sizes'] );
		self::assertSame( 'pa_size', $r['attribute'] );
	}

	public function test_custom_attribute_matched_by_label_and_aliased(): void {
		$this->product( array( 10 => 'XXL', 11 => 'Small ' ), array( new PMH_Fake_Attribute( 'Size', true ) ), 'attribute_size' );
		$r = PMH_Sizes::for_product( 1 );
		self::assertSame( array( '2XL', 'SMALL' ), $r['sizes'] );
		self::assertSame( 'size', $r['attribute'] );
	}

	public function test_any_size_variation_means_unfiltered(): void {
		$this->product( array( 10 => 's', 11 => '' ), array( new PMH_Fake_Attribute( 'pa_size', true ) ) );
		self::assertSame( PMH_Sizes::UNFILTERED, PMH_Sizes::for_product( 1 )['state'] );
	}

	public function test_no_size_attribute_is_unfiltered(): void {
		$this->product( array( 10 => 'red' ), array( new PMH_Fake_Attribute( 'pa_color', true ) ), 'attribute_pa_color' );
		self::assertSame( PMH_Sizes::UNFILTERED, PMH_Sizes::for_product( 1 )['state'] );
	}

	public function test_non_variation_size_attribute_is_ignored(): void {
		$this->product( array( 10 => 's' ), array( new PMH_Fake_Attribute( 'pa_size', false ) ) );
		self::assertSame( PMH_Sizes::UNFILTERED, PMH_Sizes::for_product( 1 )['state'] );
	}

	public function test_size_attribute_but_no_children_is_none(): void {
		$this->product( array(), array( new PMH_Fake_Attribute( 'pa_size', true ) ) );
		self::assertSame( PMH_Sizes::NONE, PMH_Sizes::for_product( 1 )['state'] );
	}

	public function test_unknown_product_is_unfiltered(): void {
		self::assertSame( PMH_Sizes::UNFILTERED, PMH_Sizes::for_product( 999 )['state'] );
	}

	public function test_variation_meta_is_primed_once_and_terms_fetched_once(): void {
		$sizes = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$sizes[ 100 + $i ] = array( 's', 'm', 'l', 'xl', '2xl' )[ $i % 5 ];
		}
		$this->product( $sizes, array( new PMH_Fake_Attribute( 'pa_size', true ) ) );
		PMH_Fake_WP::$calls = array();
		$r = PMH_Sizes::for_product( 1 );

		self::assertSame( array( 'S', 'M', 'L', 'XL', '2XL' ), $r['sizes'] );
		self::assertSame( 1, PMH_Fake_WP::$calls['update_meta_cache'], 'one prime for all variations' );
		self::assertSame( 40, PMH_Fake_WP::$calls['get_post_meta_hit'] );
		self::assertArrayNotHasKey( 'get_post_meta_miss', PMH_Fake_WP::$calls );
		self::assertSame( 1, PMH_Fake_WP::$calls['get_terms'], 'one term query for all distinct slugs' );
		self::assertArrayNotHasKey( 'get_term_by', PMH_Fake_WP::$calls );
	}

	public function test_unmatched_slugs_fall_back_to_the_raw_value(): void {
		$this->product( array( 10 => 's', 11 => 'not-a-term' ), array( new PMH_Fake_Attribute( 'pa_size', true ) ) );
		self::assertSame( array( 'S', 'NOT-A-TERM' ), PMH_Sizes::for_product( 1 )['sizes'] );
	}

	public function test_custom_attribute_values_need_no_term_query(): void {
		$this->product( array( 10 => 'M', 11 => 'L' ), array( new PMH_Fake_Attribute( 'Size', true ) ), 'attribute_size' );
		PMH_Fake_WP::$calls = array();
		PMH_Sizes::for_product( 1 );
		self::assertArrayNotHasKey( 'get_terms', PMH_Fake_WP::$calls );
	}

	private function forty_variation_product(): void {
		$sizes = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$sizes[ 100 + $i ] = array( 's', 'm', 'l', 'xl', '2xl' )[ $i % 5 ];
		}
		$this->product( $sizes, array( new PMH_Fake_Attribute( 'pa_size', true ) ) );
	}

	public function test_second_call_is_served_from_the_object_cache(): void {
		$this->forty_variation_product();
		$first = PMH_Sizes::for_product( 1 );
		PMH_Fake_WP::$calls = array();

		$second = PMH_Sizes::for_product( 1 );

		self::assertSame( $first, $second );
		self::assertSame( 1, PMH_Fake_WP::$calls['wp_cache_get'] );
		foreach ( array( 'update_meta_cache', 'get_post_meta_hit', 'get_post_meta_miss', 'get_terms', 'get_visible_children' ) as $fn ) {
			self::assertArrayNotHasKey( $fn, PMH_Fake_WP::$calls, $fn );
		}
	}

	public function test_variation_id_shares_the_parent_cache_entry(): void {
		$this->forty_variation_product();
		PMH_Sizes::for_product( 1 );
		PMH_Fake_WP::$calls = array();
		self::assertSame( array( 'S', 'M', 'L', 'XL', '2XL' ), PMH_Sizes::for_product( 100 )['sizes'] );
		self::assertArrayNotHasKey( 'update_meta_cache', PMH_Fake_WP::$calls );
	}

	public function test_product_save_invalidates_via_woocommerce_prefix(): void {
		$this->forty_variation_product();
		PMH_Sizes::for_product( 1 );

		// Disable every 2XL variation, then simulate WooCommerce's clear_caches().
		foreach ( PMH_Fake_WP::$post_meta as $cid => $meta ) {
			if ( ( $meta['attribute_pa_size'] ?? '' ) === '2xl' ) {
				PMH_Fake_WP::$post_meta[ $cid ]['attribute_pa_size'] = 's';
			}
		}
		self::assertContains( '2XL', PMH_Sizes::for_product( 1 )['sizes'], 'stale until WooCommerce bumps the prefix' );
		PMH_Fake_Cache_Helper::invalidate_cache_group( 'product_1' );
		self::assertNotContains( '2XL', PMH_Sizes::for_product( 1 )['sizes'] );
	}

	public function test_stock_hooks_flush_the_parent_entry(): void {
		$this->forty_variation_product();
		PMH_Sizes::for_product( 1 );
		PMH_Fake_WP::$post_meta[100]['attribute_pa_size'] = 'xl';
		PMH_Fake_WP::$post_meta[105]['attribute_pa_size'] = 'xl';
		foreach ( array_keys( PMH_Fake_WP::$post_meta ) as $cid ) {
			if ( 's' === ( PMH_Fake_WP::$post_meta[ $cid ]['attribute_pa_size'] ?? '' ) ) {
				PMH_Fake_WP::$post_meta[ $cid ]['attribute_pa_size'] = 'xl';
			}
		}

		PMH_Sizes::flush_for_product( 100 ); // a variation ID, as the stock hooks pass
		self::assertNotContains( 'S', PMH_Sizes::for_product( 1 )['sizes'] );
	}

	public function test_hide_out_of_stock_setting_is_part_of_the_key(): void {
		$this->forty_variation_product();
		PMH_Sizes::for_product( 1 );
		PMH_Fake_WP::$options['woocommerce_hide_out_of_stock_items'] = 'yes';
		PMH_Fake_WP::$calls = array();
		PMH_Sizes::for_product( 1 );
		self::assertSame( 1, PMH_Fake_WP::$calls['update_meta_cache'], 'recomputed under the new key' );
	}

	public function test_results_are_not_cached_without_a_product_id(): void {
		PMH_Fake_WP::$calls = array();
		PMH_Sizes::for_product( 0 );
		self::assertArrayNotHasKey( 'wp_cache_set', PMH_Fake_WP::$calls );
	}
}
