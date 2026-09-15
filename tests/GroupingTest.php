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

	private function seed_two_products_in_one_group(): string {
		PMH_Fake_WP::add_post( 1 );
		PMH_Fake_WP::add_post( 2 );
		PMH_Fake_WP::$post_meta[1][ PMH_Importer::PRODUCT_META_KEY ] = pmh_fixture( 'gildan-5000.json' );
		PMH_Fake_WP::$post_meta[2][ PMH_Importer::PRODUCT_META_KEY ] = pmh_fixture( 'gildan-5000.json' );
		return PMH_Size_Chart::signature( pmh_test_chart() );
	}

	public function test_apply_creates_a_blank_from_the_group_and_assigns_it(): void {
		$sig = $this->seed_two_products_in_one_group();
		PMH_Fake_WP::add_term( 'product_cat', 'Tees', 5 );
		wp_set_object_terms( 1, array( 5 ), 'product_cat' );

		$r = PMH_Grouping::apply_groups(
			array( array( 'apply' => '1', 'signature' => $sig, 'mode' => 'new', 'name' => ' Gildan <b>5000</b> ' ) ),
			true
		);

		self::assertSame( array( 'created' => 1, 'assigned' => 2, 'skipped' => 0, 'errors' => array() ), $r );
		$blank = PMH_Blank::for_product( 2 );
		self::assertSame( 'Gildan 5000', $blank->name, 'name sanitised' );
		$data = PMH_Blank::get( $blank->term_id );
		self::assertSame( 'apparel', $data['kind'] );
		self::assertSame( array( 5 ), $data['cats'], 'union of product categories' );
		self::assertSame( 8, count( $data['chart']['sizes'] ) );
		self::assertSame( 3, count( $data['body_chart']['rows'] ) );
	}

	public function test_apply_skips_products_the_user_cannot_edit(): void {
		$sig   = $this->seed_two_products_in_one_group();
		$blank = PMH_Fake_WP::add_term( 'pmh_blank', 'Existing' );
		PMH_Fake_WP::$can_callback = static fn( string $cap, array $args ) => ! ( 'edit_post' === $cap && 2 === (int) ( $args[0] ?? 0 ) );

		$r = PMH_Grouping::apply_groups(
			array( array( 'apply' => '1', 'signature' => $sig, 'mode' => 'existing', 'existing' => (string) $blank->term_id ) ),
			false
		);

		self::assertSame( 1, $r['assigned'] );
		self::assertSame( 1, $r['skipped'] );
		self::assertNotNull( PMH_Blank::for_product( 1 ) );
		self::assertNull( PMH_Blank::for_product( 2 ), 'product the user may not edit is untouched' );
	}

	public function test_apply_rejects_bad_rows_without_writing(): void {
		$sig = $this->seed_two_products_in_one_group();
		$r   = PMH_Grouping::apply_groups(
			array(
				array( 'apply' => '1', 'signature' => 'deadbeef', 'mode' => 'new', 'name' => 'X' ),
				array( 'apply' => '1', 'signature' => $sig, 'mode' => 'existing', 'existing' => '999' ),
				array( 'apply' => '1', 'signature' => $sig, 'mode' => 'new', 'name' => '' ),
				array( 'apply' => '', 'signature' => $sig, 'mode' => 'new', 'name' => 'Unticked' ),
				'not a row',
			),
			true
		);
		self::assertSame( 0, $r['created'] );
		self::assertSame( 0, $r['assigned'] );
		self::assertCount( 3, $r['errors'] );
		self::assertSame( array(), PMH_Fake_WP::$set_calls );
	}

	public function test_apply_refuses_duplicate_blank_name_and_leaves_already_assigned(): void {
		$sig   = $this->seed_two_products_in_one_group();
		$other = PMH_Fake_WP::add_term( 'pmh_blank', 'Taken' );
		wp_set_object_terms( 1, array( $other->term_id ), 'pmh_blank' );
		PMH_Fake_WP::$set_calls = array();

		$dup = PMH_Grouping::apply_groups( array( array( 'apply' => '1', 'signature' => $sig, 'mode' => 'new', 'name' => 'Taken' ) ), true );
		self::assertSame( 0, $dup['created'] );
		self::assertStringContainsString( 'already exists', $dup['errors'][0] );

		$ok = PMH_Grouping::apply_groups( array( array( 'apply' => '1', 'signature' => $sig, 'mode' => 'existing', 'existing' => (string) $other->term_id ) ), true );
		self::assertSame( 1, $ok['skipped'], 'product 1 already assigned and left alone' );
		self::assertSame( 1, $ok['assigned'] );
	}

	public function test_scan_primes_meta_in_chunks_not_per_product(): void {
		$json = pmh_fixture( 'gildan-5000.json' );
		$n    = PMH_Grouping::SCAN_CHUNK * 2 + 50;
		for ( $i = 1; $i <= $n; $i++ ) {
			PMH_Fake_WP::add_post( $i );
			PMH_Fake_WP::$post_meta[ $i ][ PMH_Importer::PRODUCT_META_KEY ] = $json;
		}
		PMH_Fake_WP::$calls = array();

		$scan = PMH_Grouping::scan();

		self::assertSame( $n, count( reset( $scan['groups'] )['products'] ) );
		self::assertSame( 3, PMH_Fake_WP::$calls['update_meta_cache'], 'ceil(450 / 200) primes' );
		self::assertSame( $n, PMH_Fake_WP::$calls['get_post_meta_hit'] );
		self::assertArrayNotHasKey( 'get_post_meta_miss', PMH_Fake_WP::$calls );
	}

	public function test_render_page_lists_groups_with_matches_preselected(): void {
		$sig   = $this->seed_two_products_in_one_group();
		$blank = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000' );
		PMH_Blank::update( $blank->term_id, array( 'chart' => pmh_test_chart() ) );
		PMH_Fake_WP::add_post( 3 ); // legacy, no meta
		PMH_Fake_WP::add_post( 4 );
		PMH_Fake_WP::$post_meta[4][ PMH_Importer::PRODUCT_META_KEY ] = 'not json';
		wp_set_object_terms( 1, array( $blank->term_id ), 'pmh_blank' );

		ob_start();
		PMH_Grouping::render_page();
		$html = ob_get_clean();

		self::assertStringContainsString( '1 groups across 2 products. 1 products have no Printful chart', $html );
		self::assertStringContainsString( '1 products have a chart that could not be read', $html );
		self::assertStringContainsString( 'name="groups[0][signature]" value="' . $sig . '"', $html );
		self::assertStringContainsString( 'name="groups[0][apply]" value="1" checked', $html, 'matching blank => ticked' );
		self::assertStringContainsString( 'value="' . $blank->term_id . '" selected="selected">Gildan 5000 ✓', $html );
		self::assertStringContainsString( 'value="existing" checked', $html );
		self::assertStringContainsString( '2 products (1 already have a blank)', $html );
		self::assertStringContainsString( '— Gildan 5000</span>', $html, 'assigned product shows its blank' );
		self::assertStringContainsString( 'Length · Width · Sleeve length', $html );
		self::assertStringContainsString( '<h2>Unreadable charts</h2>', $html );
		self::assertStringContainsString( 'post=4&#038;action=edit', $html );
		self::assertSame( 5, substr_count( $html, 'class="pmh-tip' ), 'skip option plus four headers' );
	}

	public function test_render_page_with_no_blanks_defaults_to_new(): void {
		$this->seed_two_products_in_one_group();
		ob_start();
		PMH_Grouping::render_page();
		$html = ob_get_clean();
		self::assertStringContainsString( 'value="existing" disabled', $html );
		self::assertStringContainsString( 'value="new" checked', $html );
		self::assertStringNotContainsString( 'value="existing" checked', $html );
	}

	public function test_render_page_without_groups_has_no_form(): void {
		ob_start();
		PMH_Grouping::render_page();
		$html = ob_get_clean();
		self::assertStringContainsString( '0 groups across 0 products', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_render_page_requires_capability(): void {
		PMH_Fake_WP::$can = false;
		$this->expectException( RuntimeException::class );
		PMH_Grouping::render_page();
	}
}
