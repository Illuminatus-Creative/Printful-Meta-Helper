<?php

use PHPUnit\Framework\TestCase;

final class TaxonomyFilterTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
		PMH_Fake_WP::$is_admin = true;
		PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000', 7 );
		PMH_Fake_WP::add_term( 'pmh_blank', 'Bella 3001', 8 );
	}

	private static function query( string $post_type = 'product', bool $main = true ): PMH_Fake_Query {
		$q       = new PMH_Fake_Query();
		$q->main = $main;
		$q->set( 'post_type', $post_type );
		return $q;
	}

	public function test_dropdown_lists_none_option_and_every_blank(): void {
		$_GET['pmh_blank_filter'] = '8';
		ob_start();
		PMH_Taxonomy::render_list_filter( 'product', 'top' );
		$html = ob_get_clean();
		self::assertStringContainsString( '<option value="none"', $html );
		self::assertStringContainsString( '<option value="8" selected="selected">Bella 3001', $html );
		self::assertStringContainsString( '<option value="7">Gildan 5000', $html );
		self::assertStringContainsString( 'title="', $html );
	}

	public function test_dropdown_only_on_the_products_list_top(): void {
		foreach ( array( array( 'post', 'top' ), array( 'product', 'bottom' ) ) as [ $type, $which ] ) {
			ob_start();
			PMH_Taxonomy::render_list_filter( $type, $which );
			self::assertSame( '', ob_get_clean(), "$type/$which" );
		}
	}

	public function test_none_filter_adds_a_not_exists_clause(): void {
		$_GET['pmh_blank_filter'] = 'none';
		$q                        = self::query();
		PMH_Taxonomy::apply_list_filter( $q );
		self::assertSame( array( array( 'taxonomy' => 'pmh_blank', 'operator' => 'NOT EXISTS' ) ), $q->get( 'tax_query' ) );
	}

	public function test_id_filter_adds_a_term_clause_and_keeps_existing_clauses(): void {
		$_GET['pmh_blank_filter'] = '7';
		$q                        = self::query();
		$q->set( 'tax_query', array( array( 'taxonomy' => 'product_cat', 'terms' => 5 ) ) );
		PMH_Taxonomy::apply_list_filter( $q );
		self::assertCount( 2, $q->get( 'tax_query' ) );
		self::assertSame( array( 'taxonomy' => 'pmh_blank', 'field' => 'term_id', 'terms' => 7 ), $q->get( 'tax_query' )[1] );
	}

	public function test_filter_is_ignored_outside_its_scope(): void {
		$_GET['pmh_blank_filter'] = '7';
		foreach ( array( self::query( 'post' ), self::query( 'product', false ) ) as $q ) {
			PMH_Taxonomy::apply_list_filter( $q );
			self::assertSame( '', $q->get( 'tax_query' ) );
		}
		PMH_Fake_WP::$is_admin = false;
		$q                     = self::query();
		PMH_Taxonomy::apply_list_filter( $q );
		self::assertSame( '', $q->get( 'tax_query' ), 'never on the front end' );

		PMH_Fake_WP::$is_admin    = true;
		$_GET['pmh_blank_filter'] = 'DROP TABLE';
		$q                        = self::query();
		PMH_Taxonomy::apply_list_filter( $q );
		self::assertSame( '', $q->get( 'tax_query' ), 'non-numeric, non-none values are dropped' );
	}
}
