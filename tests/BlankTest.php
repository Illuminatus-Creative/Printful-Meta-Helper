<?php

use PHPUnit\Framework\TestCase;

final class BlankTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
	}

	public function test_get_returns_defaults_for_unknown_term(): void {
		$data = PMH_Blank::get( 999 );
		self::assertSame( 'apparel', $data['kind'] );
		self::assertSame( array(), $data['cats'] );
		self::assertTrue( PMH_Size_Chart::is_empty( $data['chart'] ) );
	}

	public function test_update_round_trip_and_empty_deletes(): void {
		$t = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000' );
		PMH_Blank::update(
			$t->term_id,
			array(
				'kind'           => 'accessory',
				'cats'           => array( 3, 4 ),
				'material_solid' => '100% cotton',
				'disclaimers'    => 'Sheer',
				'chart'          => pmh_test_chart(),
				'bogus'          => 'ignored',
			)
		);
		$data = PMH_Blank::get( $t->term_id );
		self::assertSame( 'accessory', $data['kind'] );
		self::assertSame( array( 3, 4 ), $data['cats'] );
		self::assertSame( '100% cotton', $data['material_solid'] );
		self::assertSame( 'Sheer', $data['disclaimers'] );
		self::assertSame( 8, count( $data['chart']['sizes'] ) );
		self::assertArrayNotHasKey( 'bogus', PMH_Fake_WP::$term_meta[ $t->term_id ] );

		PMH_Blank::update( $t->term_id, array( 'material_solid' => '', 'cats' => array(), 'chart' => PMH_Size_Chart::empty_chart() ) );
		self::assertArrayNotHasKey( PMH_Blank::META_MATERIAL, PMH_Fake_WP::$term_meta[ $t->term_id ] );
		self::assertArrayNotHasKey( PMH_Blank::META_CATS, PMH_Fake_WP::$term_meta[ $t->term_id ] );
		self::assertArrayNotHasKey( PMH_Blank::META_CHART, PMH_Fake_WP::$term_meta[ $t->term_id ] );
	}

	public function test_get_repairs_bad_stored_values(): void {
		$t = PMH_Fake_WP::add_term( 'pmh_blank', 'X' );
		PMH_Fake_WP::$term_meta[ $t->term_id ] = array(
			PMH_Blank::META_KIND  => 'weird',
			PMH_Blank::META_CATS  => array( '3', 'x', 0 ),
			PMH_Blank::META_CHART => 'not an array',
		);
		$data = PMH_Blank::get( $t->term_id );
		self::assertSame( 'apparel', $data['kind'] );
		self::assertSame( array( 3 ), $data['cats'] );
		self::assertTrue( PMH_Size_Chart::is_empty( $data['chart'] ) );
	}

	public function test_for_product_and_all(): void {
		$a = PMH_Fake_WP::add_term( 'pmh_blank', 'Bella 3001' );
		$b = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000' );
		PMH_Fake_WP::add_term( 'product_cat', 'Tees' );
		PMH_Fake_WP::add_post( 10 );
		wp_set_object_terms( 10, array( $b->term_id ), 'pmh_blank' );

		self::assertSame( $b->term_id, PMH_Blank::for_product( 10 )->term_id );
		self::assertNull( PMH_Blank::for_product( 11 ) );
		self::assertSame( array( 'Bella 3001', 'Gildan 5000' ), array_map( static fn( $t ) => $t->name, PMH_Blank::all() ), 'only blanks, by name' );
		unset( $a );
	}

	public function test_for_categories(): void {
		$any  = PMH_Fake_WP::add_term( 'pmh_blank', 'Anywhere' );
		$tees = PMH_Fake_WP::add_term( 'pmh_blank', 'Tees only' );
		PMH_Blank::update( $tees->term_id, array( 'cats' => array( 5 ) ) );

		$names = static fn( array $terms ) => array_map( static fn( $t ) => $t->name, $terms );
		self::assertSame( array( 'Anywhere', 'Tees only' ), $names( PMH_Blank::for_categories( array( 5, 6 ) ) ) );
		self::assertSame( array( 'Anywhere' ), $names( PMH_Blank::for_categories( array( 6 ) ) ) );
		self::assertSame( array( 'Anywhere' ), $names( PMH_Blank::for_categories( array() ) ) );
		unset( $any );
	}
}
