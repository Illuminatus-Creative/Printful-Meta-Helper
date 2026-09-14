<?php

use PHPUnit\Framework\TestCase;

final class TermMetaTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
		PMH_Fake_WP::add_term( 'product_cat', 'Tees', 5 );
		PMH_Fake_WP::add_term( 'product_cat', 'Hoodies', 6 );
	}

	private static function base_post( array $overrides = array() ): array {
		return array_merge(
			array(
				'pmh_cats'                => array( '5', '6', '99', 'x' ),
				'pmh_kind'                => 'apparel',
				'pmh_material_solid'      => ' 100% cotton <b>x</b> ',
				'pmh_material_exceptions' => "Sport Grey is 90/10\n\n Heather 50/50 ",
				'pmh_fabric_weight'       => '5.3 oz',
				'pmh_construction'        => 'Tubular',
				'pmh_care'                => '',
				'pmh_handling_min'        => '',
				'pmh_handling_max'        => '4',
				'pmh_chart'               => '',
				'pmh_body_chart'          => '',
			),
			$overrides
		);
	}

	public function test_basics_and_materials_are_sanitised(): void {
		$r = PMH_Term_Meta::collect( self::base_post() );
		$d = $r['data'];

		self::assertEqualsCanonicalizing( array( 5, 6 ), $d['cats'], 'unknown category IDs dropped' );
		self::assertSame( 'apparel', $d['kind'] );
		self::assertSame( '100% cotton x', $d['material_solid'] );
		self::assertSame( "Sport Grey is 90/10\nHeather 50/50", $d['material_exceptions'] );
		self::assertSame( '', $d['handling_min'] );
		self::assertSame( '4', $d['handling_max'] );
		self::assertTrue( PMH_Size_Chart::is_empty( $d['chart'] ), 'empty textarea clears the chart' );
		self::assertSame( array(), $r['errors'] );
	}

	public function test_unknown_kind_falls_back(): void {
		self::assertSame( 'apparel', PMH_Term_Meta::collect( self::base_post( array( 'pmh_kind' => 'hat' ) ) )['data']['kind'] );
	}

	public function test_materials_paste_overrides_fields(): void {
		$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_materials_paste' => pmh_fixture( 'gildan-5000-materials.txt' ) ) ) );
		self::assertSame( '100% cotton', $r['data']['material_solid'] );
		self::assertSame( '5.0–5.3 oz/yd² (170-180 g/m²)', $r['data']['fabric_weight'] );
		self::assertStringContainsString( 'Ash Grey', $r['data']['material_exceptions'] );
		self::assertSame( array( 'Materials imported from the pasted paragraph.' ), $r['messages'] );
	}

	public function test_materials_paste_without_bullets_leaves_fields(): void {
		$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_materials_paste' => 'just prose' ) ) );
		self::assertSame( '100% cotton x', $r['data']['material_solid'] );
		self::assertCount( 1, $r['errors'] );
	}

	public function test_json_import_sets_both_charts(): void {
		$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_import_json' => pmh_fixture( 'gildan-5000.json' ) ) ) );
		self::assertSame( array( 'Length', 'Width', 'Sleeve length' ), array_column( $r['data']['chart']['rows'], 'label' ) );
		self::assertSame( array( 'Length', 'Chest', 'Sleeve length' ), array_column( $r['data']['body_chart']['rows'], 'label' ) );
		self::assertSame( array( 'Size chart imported from JSON: 3 garment rows, 3 body rows.' ), $r['messages'] );
	}

	public function test_json_import_beats_product_and_text(): void {
		PMH_Fake_WP::add_post( 10 );
		PMH_Fake_WP::$post_meta[10][ PMH_Importer::PRODUCT_META_KEY ] = pmh_fixture( 'gildan-5000.json' );
		$json = json_encode( array( 'availableSizes' => array( 'S' ), 'productMeasurements' => array( 'sizeTableRows' => array( array( 'unit' => 'inch', 'title' => 'Only', 'sizes' => array( 'S' => array( 1 ) ) ) ) ) ) );

		$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_import_json' => $json, 'pmh_import_product' => '10', 'pmh_import_text' => "Size\tLength\nS\t28" ) ) );
		self::assertSame( array( 'Only' ), array_column( $r['data']['chart']['rows'], 'label' ) );
	}

	public function test_product_import_by_id_and_sku(): void {
		PMH_Fake_WP::add_post( 10, 'product', array( 'sku' => 'GIL-5000' ) );
		PMH_Fake_WP::add_post( 11, 'product_variation', array( 'post_parent' => 10 ) );
		PMH_Fake_WP::$post_meta[10][ PMH_Importer::PRODUCT_META_KEY ] = pmh_fixture( 'gildan-5000.json' );

		foreach ( array( '10', 'GIL-5000', '11' ) as $ref ) {
			$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_import_product' => $ref ) ) );
			self::assertSame( 8, count( $r['data']['chart']['sizes'] ), $ref );
			self::assertSame( array( 'Size chart imported from product 10: 3 garment rows, 3 body rows.' ), $r['messages'], $ref );
		}
	}

	public function test_product_import_legacy_product_errors_and_keeps_textarea(): void {
		PMH_Fake_WP::add_post( 10 );
		$textarea = json_encode( array( 'sizes' => array( 'S' ), 'rows' => array( array( 'label' => 'Kept', 'values' => array( 'S' => '28' ) ) ) ) );
		$r        = PMH_Term_Meta::collect( self::base_post( array( 'pmh_import_product' => '10', 'pmh_chart' => $textarea ) ) );
		self::assertSame( 'Kept', $r['data']['chart']['rows'][0]['label'] );
		self::assertStringContainsString( 'no readable Printful size chart', $r['errors'][0] );
	}

	public function test_product_import_unknown_ref_errors(): void {
		$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_import_product' => 'NOPE' ) ) );
		self::assertStringContainsString( 'no product found for "NOPE"', $r['errors'][0] );
	}

	public function test_text_import_in_cm(): void {
		$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_import_text' => "Size\tLength\nS\t71.1", 'pmh_import_text_unit' => 'cm' ) ) );
		self::assertSame( array( 27.99 ), $r['data']['chart']['rows'][0]['values']['S'] );
		self::assertArrayNotHasKey( 'body_chart', array_filter( $r['data'], static fn( $v ) => is_array( $v ) && ! PMH_Size_Chart::is_empty( $v ) ), 'text import never touches the body chart' );
	}

	public function test_textarea_json_is_normalised(): void {
		$textarea = json_encode( array( 'sizes' => array( 'xxl', 'S' ), 'note' => ' n ', 'rows' => array( array( 'label' => ' Chest ', 'values' => array( 'S' => '34-37', 'XXL' => array( 50, 53 ), 'M' => '1' ) ) ) ) );
		$r        = PMH_Term_Meta::collect( self::base_post( array( 'pmh_chart' => $textarea ) ) );
		$chart    = $r['data']['chart'];
		self::assertSame( array( '2XL', 'S' ), $chart['sizes'] );
		self::assertSame( 'n', $chart['note'] );
		self::assertSame( 'Chest', $chart['rows'][0]['label'] );
		self::assertSame( array( '2XL' => array( 50.0, 53.0 ), 'S' => array( 34.0, 37.0 ) ), $chart['rows'][0]['values'] );
	}

	public function test_invalid_textarea_json_leaves_chart_unchanged(): void {
		$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_chart' => '{nope', 'pmh_body_chart' => '{"sizes":["S"],"rows":[]}' ) ) );
		self::assertArrayNotHasKey( 'chart', $r['data'] );
		self::assertArrayNotHasKey( 'body_chart', $r['data'] );
		self::assertCount( 2, $r['errors'] );
	}

	public function test_failed_json_import_falls_back_to_textarea(): void {
		$textarea = json_encode( array( 'sizes' => array( 'S' ), 'rows' => array( array( 'label' => 'Kept', 'values' => array( 'S' => 28 ) ) ) ) );
		$r        = PMH_Term_Meta::collect( self::base_post( array( 'pmh_import_json' => '{"foo":1}', 'pmh_chart' => $textarea ) ) );
		self::assertSame( 'Kept', $r['data']['chart']['rows'][0]['label'] );
		self::assertStringStartsWith( 'JSON import failed:', $r['errors'][0] );
	}

	public function test_oversized_json_is_rejected(): void {
		$r = PMH_Term_Meta::collect( self::base_post( array( 'pmh_import_json' => str_repeat( '{', 600 * 1024 ) ) ) );
		self::assertStringContainsString( 'too large', $r['errors'][0] );
	}

	public function test_save_is_gated_by_nonce_and_capability(): void {
		$t     = PMH_Fake_WP::add_term( 'pmh_blank', 'X' );
		$_POST = self::base_post( array( 'pmh_blank_nonce' => 'n' ) );

		PMH_Fake_WP::$nonce_ok = false;
		PMH_Term_Meta::save( $t->term_id );
		self::assertArrayNotHasKey( $t->term_id, PMH_Fake_WP::$term_meta );

		PMH_Fake_WP::$nonce_ok = true;
		PMH_Fake_WP::$can      = false;
		PMH_Term_Meta::save( $t->term_id );
		self::assertArrayNotHasKey( $t->term_id, PMH_Fake_WP::$term_meta );

		PMH_Fake_WP::$can = true;
		PMH_Term_Meta::save( $t->term_id );
		self::assertSame( '100% cotton x', PMH_Blank::get( $t->term_id )['material_solid'] );
		$_POST = array();
	}

	public function test_save_without_our_nonce_field_is_a_no_op(): void {
		// Inline quick-edit of a term name fires the same hook without our form.
		$t = PMH_Fake_WP::add_term( 'pmh_blank', 'X' );
		PMH_Blank::update( $t->term_id, array( 'material_solid' => 'keep me' ) );
		$_POST = array( 'name' => 'Renamed' );
		PMH_Term_Meta::save( $t->term_id );
		self::assertSame( 'keep me', PMH_Blank::get( $t->term_id )['material_solid'] );
		$_POST = array();
	}

	public function test_save_queues_notices(): void {
		$t     = PMH_Fake_WP::add_term( 'pmh_blank', 'X' );
		$_POST = self::base_post( array( 'pmh_blank_nonce' => 'n', 'pmh_import_json' => pmh_fixture( 'gildan-5000.json' ) ) );
		PMH_Term_Meta::save( $t->term_id );
		$notice = PMH_Notices::take( 'blank' );
		self::assertNotNull( $notice );
		self::assertStringContainsString( 'imported from JSON', $notice['messages'][0] );
		$_POST = array();
	}
}
