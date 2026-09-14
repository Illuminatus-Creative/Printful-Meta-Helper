<?php

use PHPUnit\Framework\TestCase;

final class TaxonomyGuardTest extends TestCase {

	private WP_Term $a;
	private WP_Term $b;
	private WP_Term $c;

	protected function setUp(): void {
		PMH_Fake_WP::reset();
		$this->a = PMH_Fake_WP::add_term( 'pmh_blank', 'A' );
		$this->b = PMH_Fake_WP::add_term( 'pmh_blank', 'B' );
		$this->c = PMH_Fake_WP::add_term( 'pmh_blank', 'C' );
	}

	private function fire( array $tt_ids, array $old_tt_ids, bool $append = false ): void {
		PMH_Taxonomy::enforce_single_term( 10, array(), $tt_ids, 'pmh_blank', $append, $old_tt_ids );
	}

	public function test_single_term_is_left_alone(): void {
		$this->fire( array( $this->a->term_taxonomy_id ), array() );
		self::assertSame( array(), PMH_Fake_WP::$set_calls );
	}

	public function test_other_taxonomies_are_ignored(): void {
		PMH_Taxonomy::enforce_single_term( 10, array(), array( 1, 2 ), 'product_cat', false, array() );
		self::assertSame( array(), PMH_Fake_WP::$set_calls );
	}

	public function test_bulk_edit_merge_keeps_the_newly_added_term(): void {
		// Bulk edit: old = [A], submitted union = [A, B]; B is the editor's intent.
		$this->fire( array( $this->a->term_taxonomy_id, $this->b->term_taxonomy_id ), array( $this->a->term_taxonomy_id ) );
		self::assertCount( 1, PMH_Fake_WP::$set_calls );
		self::assertSame( array( 10, array( $this->b->term_id ), 'pmh_blank', false ), PMH_Fake_WP::$set_calls[0] );
	}

	public function test_two_new_terms_keeps_the_last_added(): void {
		$this->fire( array( $this->b->term_taxonomy_id, $this->c->term_taxonomy_id ), array() );
		self::assertSame( array( $this->c->term_id ), PMH_Fake_WP::$set_calls[0][1] );
	}

	public function test_nothing_new_keeps_the_last_in_list(): void {
		$old = array( $this->a->term_taxonomy_id, $this->b->term_taxonomy_id );
		$this->fire( $old, $old );
		self::assertSame( array( $this->b->term_id ), PMH_Fake_WP::$set_calls[0][1] );
	}

	public function test_append_uses_the_union(): void {
		// Append: the action only carries the appended term; the product holds old + new.
		$this->fire( array( $this->c->term_taxonomy_id ), array( $this->a->term_taxonomy_id ), true );
		self::assertSame( array( $this->c->term_id ), PMH_Fake_WP::$set_calls[0][1] );
	}

	public function test_append_of_a_single_term_onto_nothing_is_left_alone(): void {
		$this->fire( array( $this->c->term_taxonomy_id ), array(), true );
		self::assertSame( array(), PMH_Fake_WP::$set_calls );
	}

	public function test_unknown_tt_id_does_nothing(): void {
		$this->fire( array( 55555, 66666 ), array() );
		self::assertSame( array(), PMH_Fake_WP::$set_calls );
	}
}
