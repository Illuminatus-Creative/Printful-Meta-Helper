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

	public function test_meta_and_terms_are_read_once_per_variation(): void {
		// Documents the current cost: one meta read and one term lookup per
		// variation. The profiler in tests/bench/ measures the same thing.
		$sizes = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$sizes[ 100 + $i ] = array( 's', 'm', 'l', 'xl', '2xl' )[ $i % 5 ];
		}
		$this->product( $sizes, array( new PMH_Fake_Attribute( 'pa_size', true ) ) );
		PMH_Fake_WP::$calls = array();
		PMH_Sizes::for_product( 1 );
		self::assertSame( 40, PMH_Fake_WP::$calls['get_post_meta'] );
		self::assertSame( 40, PMH_Fake_WP::$calls['get_term_by'] );
	}
}
