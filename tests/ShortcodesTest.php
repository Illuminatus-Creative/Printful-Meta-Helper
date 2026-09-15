<?php

use PHPUnit\Framework\TestCase;

/**
 * The three shortcodes end to end against the fakes: product resolution,
 * the empty-string rules, and that the variation filter is applied.
 */
final class ShortcodesTest extends TestCase {

	private WP_Term $blank;

	protected function setUp(): void {
		PMH_Fake_WP::reset();
		PMH_Fake_WC::$products = array();
		$GLOBALS['product']    = null;

		$this->blank = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000', 7, 'gildan-5000' );
		PMH_Blank::update(
			$this->blank->term_id,
			array(
				'chart'          => pmh_test_chart(),
				'material_solid' => '100% cotton',
				'fabric_weight'  => '5.3 oz',
			)
		);
		pmh_fake_variable_product( 1, array( 10 => 's', 11 => 'm', 12 => 'l' ) );
	}

	public function test_size_chart_renders_filtered_to_variations(): void {
		wp_set_object_terms( 1, array( $this->blank->term_id ), 'pmh_blank' );
		$html = PMH_Shortcodes::size_chart( array( 'product_id' => '1' ) );

		self::assertStringContainsString( 'pmh-chart--gildan-5000', $html );
		self::assertSame( 3, substr_count( $html, '<tr class="pmh-chart__row"' ), 'S, M, L only' );
		self::assertStringNotContainsString( 'data-size="XL"', $html );
		self::assertContains( 'pmh-size-chart', PMH_Fake_WP::$enqueued, 'assets enqueued on render' );
	}

	public function test_size_chart_resolves_the_product_from_the_loop_and_from_a_variation(): void {
		wp_set_object_terms( 1, array( $this->blank->term_id ), 'pmh_blank' );
		$GLOBALS['product'] = PMH_Fake_WC::$products[1];
		self::assertStringContainsString( 'pmh-chart', PMH_Shortcodes::size_chart( array() ) );
		$GLOBALS['product'] = null;
		self::assertStringContainsString( 'pmh-chart', PMH_Shortcodes::size_chart( array( 'product_id' => '11' ) ), 'variation resolves to parent' );
	}

	public function test_size_chart_is_empty_when_nothing_should_show(): void {
		self::assertSame( '', PMH_Shortcodes::size_chart( array( 'product_id' => '1' ) ), 'no blank' );

		wp_set_object_terms( 1, array( $this->blank->term_id ), 'pmh_blank' );
		PMH_Blank::update( $this->blank->term_id, array( 'kind' => 'accessory' ) );
		self::assertSame( '', PMH_Shortcodes::size_chart( array( 'product_id' => '1' ) ), 'not apparel' );

		PMH_Blank::update( $this->blank->term_id, array( 'kind' => 'apparel', 'chart' => PMH_Size_Chart::empty_chart() ) );
		self::assertSame( '', PMH_Shortcodes::size_chart( array( 'product_id' => '1' ) ), 'empty chart' );

		PMH_Blank::update( $this->blank->term_id, array( 'chart' => PMH_Size_Chart::filter_sizes( pmh_test_chart(), array( '4XL', '5XL' ) ) ) );
		self::assertSame( '', PMH_Shortcodes::size_chart( array( 'product_id' => '1' ) ), 'no overlap with S/M/L' );

		self::assertSame( '', PMH_Shortcodes::size_chart( array( 'product_id' => '999' ) ), 'unknown product' );
	}

	public function test_chest_column_comes_from_the_body_chart_by_default(): void {
		wp_set_object_terms( 1, array( $this->blank->term_id ), 'pmh_blank' );
		self::assertStringNotContainsString( 'pmh-chart__head--body', PMH_Shortcodes::size_chart( array( 'product_id' => '1' ) ), 'no body chart yet: no column' );

		PMH_Blank::update( $this->blank->term_id, array( 'body_chart' => PMH_Importer::from_json( pmh_fixture( 'gildan-5000.json' ) )['body'] ) );
		$html = PMH_Shortcodes::size_chart( array( 'product_id' => '1' ) );
		self::assertStringContainsString( '<th scope="col" class="pmh-chart__head pmh-chart__head--body">Chest</th>', $html );
		self::assertSame( 3, substr_count( $html, 'pmh-chart__cell--body' ), 'one chest cell per rendered size (S, M, L)' );
		self::assertStringContainsString( '42–45&quot;', $html, 'L chest range' );

		self::assertStringNotContainsString( '--body', PMH_Shortcodes::size_chart( array( 'product_id' => '1', 'body_rows' => '' ) ), 'body_rows="" removes it' );
		$two = PMH_Shortcodes::size_chart( array( 'product_id' => '1', 'body_rows' => 'Sleeve length, Chest' ) );
		self::assertSame( 2, substr_count( $two, 'pmh-chart__head--body' ) );
		self::assertStringNotContainsString( 'pmh-chart__head--body', PMH_Shortcodes::size_chart( array( 'product_id' => '1', 'table' => 'body' ) ), 'the body table itself gets no extra columns' );
	}

	public function test_size_chart_attributes(): void {
		wp_set_object_terms( 1, array( $this->blank->term_id ), 'pmh_blank' );
		$html = PMH_Shortcodes::size_chart( array( 'product_id' => '1', 'unit' => 'CM', 'toggle' => 'no', 'note' => '0', 'class' => 'x y' ) );
		self::assertStringContainsString( 'pmh-chart--unit-cm', $html );
		self::assertStringContainsString( 'pmh-chart--locked', $html );
		self::assertStringContainsString( ' x y"', $html );
		self::assertStringContainsString( 'pmh-chart__note-line--supplier', $html, 'note="0" leaves the fixed supplier line' );
		self::assertStringNotContainsString( 'pmh-chart__note-line--blank', $html );
		$none = PMH_Shortcodes::size_chart( array( 'product_id' => '1', 'note' => '0', 'supplier' => 'no' ) );
		self::assertStringNotContainsString( 'pmh-chart__note', $none );

		PMH_Blank::update( $this->blank->term_id, array( 'body_chart' => PMH_Importer::from_json( pmh_fixture( 'gildan-5000.json' ) )['body'] ) );
		$body = PMH_Shortcodes::size_chart( array( 'product_id' => '1', 'table' => 'body' ) );
		self::assertStringContainsString( 'pmh-chart--body', $body );
		self::assertStringContainsString( '>Chest<', $body );
	}

	public function test_materials_and_blank_name(): void {
		self::assertSame( '', PMH_Shortcodes::materials( array( 'product_id' => '1' ) ) );
		self::assertSame( '', PMH_Shortcodes::blank_name( array( 'product_id' => '1' ) ) );

		wp_set_object_terms( 1, array( $this->blank->term_id ), 'pmh_blank' );
		$html = PMH_Shortcodes::materials( array( 'product_id' => '1', 'fields' => 'Weight, material', 'labels' => 'false' ) );
		self::assertStringContainsString( 'pmh-materials--gildan-5000', $html );
		self::assertStringContainsString( '5.3 oz', $html );
		self::assertStringNotContainsString( '<dt', $html );
		self::assertSame( 'Gildan 5000', PMH_Shortcodes::blank_name( array( 'product_id' => '1' ) ) );

		PMH_Blank::update( $this->blank->term_id, array( 'kind' => 'accessory' ) );
		self::assertStringContainsString( '100% cotton', PMH_Shortcodes::materials( array( 'product_id' => '1' ) ), 'materials render for any kind' );
	}
}
