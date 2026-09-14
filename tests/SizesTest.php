<?php

use PHPUnit\Framework\TestCase;

final class SizesTest extends TestCase {

	private static function sizes_result( string $state, array $sizes = array() ): array {
		return array(
			'state'     => $state,
			'sizes'     => $sizes,
			'attribute' => 'pa_size',
		);
	}

	public function test_no_size_attribute_renders_full_chart(): void {
		$applied = PMH_Sizes::apply( pmh_test_chart(), self::sizes_result( PMH_Sizes::UNFILTERED ) );
		self::assertSame( 'unfiltered', $applied['state'] );
		self::assertSame( array( 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL' ), $applied['chart']['sizes'] );
	}

	public function test_sizes_collapse_chart_in_chart_order(): void {
		$applied = PMH_Sizes::apply( pmh_test_chart(), self::sizes_result( PMH_Sizes::SIZES, array( '2XL', 'S', 'XL', 'M', 'L' ) ) );
		self::assertSame( 'filtered', $applied['state'] );
		self::assertSame( array( 'S', 'M', 'L', 'XL', '2XL' ), $applied['chart']['sizes'] );
		foreach ( $applied['chart']['rows'] as $row ) {
			self::assertSame( array( 'S', 'M', 'L', 'XL', '2XL' ), array_keys( $row['values'] ) );
		}
	}

	public function test_aliases_from_shop_match_chart(): void {
		$applied = PMH_Sizes::apply( pmh_test_chart(), self::sizes_result( PMH_Sizes::SIZES, array( 'xxl', 'XXXL' ) ) );
		self::assertSame( array( '2XL', '3XL' ), $applied['chart']['sizes'] );
	}

	public function test_no_overlap_renders_nothing(): void {
		$applied = PMH_Sizes::apply( pmh_test_chart(), self::sizes_result( PMH_Sizes::SIZES, array( '10', '12', '14' ) ) );
		self::assertSame( 'no_overlap', $applied['state'] );
		self::assertNull( $applied['chart'] );
	}

	public function test_size_attribute_with_no_variations_renders_nothing(): void {
		$applied = PMH_Sizes::apply( pmh_test_chart(), self::sizes_result( PMH_Sizes::NONE ) );
		self::assertSame( 'no_variations', $applied['state'] );
		self::assertNull( $applied['chart'] );
	}

	public function test_empty_chart_renders_nothing(): void {
		$applied = PMH_Sizes::apply( PMH_Size_Chart::empty_chart(), self::sizes_result( PMH_Sizes::UNFILTERED ) );
		self::assertSame( 'empty_chart', $applied['state'] );
		self::assertNull( $applied['chart'] );
	}
}
