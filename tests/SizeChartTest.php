<?php

use PHPUnit\Framework\TestCase;

final class SizeChartTest extends TestCase {

	/** @return array<string, array{string, ?array}> */
	public static function cells(): array {
		return array(
			'integer'            => array( '28', array( 28.0 ) ),
			'decimal'            => array( '28.5', array( 28.5 ) ),
			'comma decimal'      => array( '28,5', array( 28.5 ) ),
			'mixed fraction'     => array( '15 5/8', array( 15.63 ) ),
			'unicode fraction'   => array( '16 ½', array( 16.5 ) ),
			'tight unicode'      => array( '16½', array( 16.5 ) ),
			'bare fraction'      => array( '⅝', array( 0.63 ) ),
			'inch mark'          => array( '28"', array( 28.0 ) ),
			'inch word'          => array( '28 in', array( 28.0 ) ),
			'range hyphen'       => array( '34-37', array( 34.0, 37.0 ) ),
			'range spaced'       => array( '34 - 37', array( 34.0, 37.0 ) ),
			'range en dash'      => array( '34 – 37', array( 34.0, 37.0 ) ),
			'range to'           => array( '34 to 37', array( 34.0, 37.0 ) ),
			'range fractions'    => array( '16 ½ - 17 ¼', array( 16.5, 17.25 ) ),
			'range reversed'     => array( '37-34', array( 34.0, 37.0 ) ),
			'empty'              => array( '', null ),
			'whitespace'         => array( '   ', null ),
			'garbage'            => array( 'n/a', null ),
			'three parts'        => array( '1-2-3', null ),
		);
	}

	/** @dataProvider cells */
	public function test_parse_cell( string $input, ?array $expected ): void {
		self::assertSame( $expected, PMH_Size_Chart::parse_cell( $input ) );
	}

	public function test_normalise_size_aliases(): void {
		self::assertSame( '2XL', PMH_Size_Chart::normalise_size( 'xxl' ) );
		self::assertSame( '2XL', PMH_Size_Chart::normalise_size( ' 2xl ' ) );
		self::assertSame( '3XL', PMH_Size_Chart::normalise_size( 'XXXL' ) );
		self::assertSame( 'ONE SIZE', PMH_Size_Chart::normalise_size( 'One  size' ) );
	}

	public function test_conversion(): void {
		self::assertSame( 71.1, PMH_Size_Chart::to_cm( 28.0 ) );
		self::assertSame( 39.7, PMH_Size_Chart::to_cm( 15.63 ) );
		self::assertSame( 57.9, PMH_Size_Chart::to_cm( 22.8 ) );
		self::assertSame( 28.0, PMH_Size_Chart::from_cm( 71.12 ) );
	}

	public function test_normalise_orders_and_filters_by_size_list(): void {
		$chart = PMH_Size_Chart::normalise(
			array(
				'sizes' => array( 'S', 'M', 'xxl' ),
				'note'  => '  Note ',
				'rows'  => array(
					array( 'label' => 'Length', 'values' => array( '2XL' => '32', 'S' => 28, 'M' => array( 29 ), 'L' => 30 ) ),
					array( 'label' => '', 'values' => array( 'S' => 1 ) ),
					array( 'label' => 'Empty', 'values' => array( 'XL' => 1 ) ),
					'not a row',
				),
			)
		);

		self::assertSame( array( 'S', 'M', '2XL' ), $chart['sizes'] );
		self::assertSame( 'Note', $chart['note'] );
		self::assertCount( 1, $chart['rows'] );
		self::assertSame( array( 'S' => array( 28.0 ), 'M' => array( 29.0 ), '2XL' => array( 32.0 ) ), $chart['rows'][0]['values'] );
	}

	public function test_normalise_infers_sizes_when_no_list(): void {
		$chart = PMH_Size_Chart::normalise(
			array(
				'rows' => array(
					array( 'label' => 'Chest', 'values' => array( 'M' => '38-41', 'S' => '34-37' ) ),
				),
			)
		);
		self::assertSame( array( 'M', 'S' ), $chart['sizes'] );
		self::assertSame( array( 34.0, 37.0 ), $chart['rows'][0]['values']['S'] );
	}

	public function test_normalise_rejects_junk(): void {
		self::assertTrue( PMH_Size_Chart::is_empty( PMH_Size_Chart::normalise( 'nope' ) ) );
		self::assertTrue( PMH_Size_Chart::is_empty( PMH_Size_Chart::normalise( array( 'rows' => array( array( 'label' => 'X', 'values' => array( 'S' => 'abc' ) ) ) ) ) ) );
	}

	public function test_filter_sizes_keeps_chart_order(): void {
		$chart = PMH_Size_Chart::normalise(
			array(
				'sizes' => array( 'S', 'M', 'L', 'XL', '2XL', '3XL' ),
				'rows'  => array( array( 'label' => 'Length', 'values' => array( 'S' => 28, 'M' => 29, 'L' => 30, 'XL' => 31, '2XL' => 32, '3XL' => 33 ) ) ),
			)
		);
		$filtered = PMH_Size_Chart::filter_sizes( $chart, array( 'xxl', 'S', 'L', '7XL' ) );
		self::assertSame( array( 'S', 'L', '2XL' ), $filtered['sizes'] );
		self::assertSame( array( 'S', 'L', '2XL' ), array_keys( $filtered['rows'][0]['values'] ) );
	}

	public function test_format_values(): void {
		self::assertSame( '28', PMH_Size_Chart::format_values( array( 28.0 ) ) );
		self::assertSame( '15.63', PMH_Size_Chart::format_values( array( 15.63 ) ) );
		self::assertSame( '34–37', PMH_Size_Chart::format_values( array( 34.0, 37.0 ) ) );
		self::assertSame( '86.4–94', PMH_Size_Chart::format_values( array( 34.0, 37.0 ), 'cm' ) );
	}
}
