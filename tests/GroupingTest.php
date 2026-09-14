<?php

use PHPUnit\Framework\TestCase;

final class GroupingTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
	}

	private static function parsed( int $id, array $chart, int $blank_id = 0, array $cats = array() ): array {
		return array(
			'chart'      => $chart,
			'body_chart' => PMH_Size_Chart::empty_chart(),
			'title'      => 'P' . $id,
			'blank_id'   => $blank_id,
			'cats'       => $cats,
		);
	}

	public function test_group_products_by_signature(): void {
		$gildan = pmh_test_chart();
		$other  = PMH_Size_Chart::filter_sizes( $gildan, array( 'S', 'M' ) );

		$groups = PMH_Grouping::group_products(
			array(
				1 => self::parsed( 1, $gildan, 0, array( 5 ) ),
				2 => self::parsed( 2, $other ),
				3 => self::parsed( 3, $gildan, 42, array( 6 ) ),
				4 => self::parsed( 4, $gildan ),
				5 => self::parsed( 5, PMH_Size_Chart::empty_chart() ),
			),
			array( 42 => $gildan, 43 => $other, 44 => PMH_Size_Chart::empty_chart() )
		);

		self::assertCount( 2, $groups, 'empty chart is skipped' );
		$first = reset( $groups );
		self::assertSame( array( 1, 3, 4 ), array_keys( $first['products'] ), 'largest group first' );
		self::assertSame( array( 42 ), $first['blank_matches'] );
		self::assertSame( 42, $first['products'][3]['blank_id'] );
		self::assertSame( array( 5 ), $first['products'][1]['cats'] );
		$second = next( $groups );
		self::assertSame( array( 2 ), array_keys( $second['products'] ) );
		self::assertSame( array( 43 ), $second['blank_matches'] );
	}

	public function test_scan_reads_meta_and_counts_legacy_products(): void {
		$blank = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000' );
		PMH_Blank::update( $blank->term_id, array( 'chart' => pmh_test_chart() ) );
		PMH_Fake_WP::add_term( 'product_cat', 'Tees', 5 );

		PMH_Fake_WP::add_post( 1 );
		PMH_Fake_WP::add_post( 2, 'product', array( 'status' => 'draft' ) );
		PMH_Fake_WP::add_post( 3 ); // legacy: no meta
		PMH_Fake_WP::add_post( 4 ); // unreadable meta
		PMH_Fake_WP::add_post( 9, 'post' );
		PMH_Fake_WP::$post_meta[1][ PMH_Importer::PRODUCT_META_KEY ] = pmh_fixture( 'gildan-5000.json' );
		PMH_Fake_WP::$post_meta[2][ PMH_Importer::PRODUCT_META_KEY ] = pmh_fixture( 'gildan-5000.json' );
		PMH_Fake_WP::$post_meta[4][ PMH_Importer::PRODUCT_META_KEY ] = '{"nope":1}';
		wp_set_object_terms( 1, array( 5 ), 'product_cat' );
		wp_set_object_terms( 1, array( $blank->term_id ), 'pmh_blank' );

		$scan = PMH_Grouping::scan();

		self::assertCount( 1, $scan['groups'] );
		self::assertSame( 1, $scan['no_meta'] );
		self::assertSame( array( 4 ), $scan['unreadable'] );
		$group = reset( $scan['groups'] );
		self::assertSame( array( 1, 2 ), array_keys( $group['products'] ) );
		self::assertSame( $blank->term_id, $group['products'][1]['blank_id'] );
		self::assertSame( array( 5 ), $group['products'][1]['cats'] );
		self::assertSame( array( $blank->term_id ), $group['blank_matches'] );
		self::assertSame( 'Product 2', $group['products'][2]['title'] );
	}
}
