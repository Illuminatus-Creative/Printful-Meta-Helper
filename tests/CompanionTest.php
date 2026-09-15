<?php

use PHPUnit\Framework\TestCase;

final class CompanionTest extends TestCase {

	private WP_Term $unisex;
	private WP_Term $womens;

	protected function setUp(): void {
		PMH_Fake_WP::reset();
		$this->unisex = PMH_Fake_WP::add_term( 'pmh_blank', 'Bella 3001', 30, 'bella-3001' );
		$this->womens = PMH_Fake_WP::add_term( 'pmh_blank', 'Bella 6004', 31, 'bella-6004' );
		PMH_Blank::update( $this->unisex->term_id, array( 'fit_label' => 'Unisex sizing.', 'link_text' => "Looking for men's/unisex sizes?" ) );
		PMH_Blank::update( $this->womens->term_id, array( 'fit_label' => '', 'link_text' => "Looking for women\u{2019}s sizes?" ) );
		foreach ( array( 1, 2, 3 ) as $id ) {
			PMH_Fake_WP::add_post( $id, 'product', array( 'title' => 'Product ' . $id ) );
		}
		wp_set_object_terms( 1, array( $this->unisex->term_id ), 'pmh_blank' );
		wp_set_object_terms( 2, array( $this->womens->term_id ), 'pmh_blank' );
		wp_set_object_terms( 3, array( $this->womens->term_id ), 'pmh_blank' );
	}

	public function test_set_links_both_ways(): void {
		self::assertSame( array( 'cleared' => array() ), PMH_Companion::set( 1, 2 ) );
		self::assertSame( 2, PMH_Companion::get( 1 ) );
		self::assertSame( 1, PMH_Companion::get( 2 ) );
	}

	public function test_repointing_clears_the_old_partner_and_the_new_partners_old_partner(): void {
		PMH_Companion::set( 1, 2 );
		PMH_Fake_WP::add_post( 4 );
		PMH_Companion::set( 4, 3 ); // 4<->3
		$r = PMH_Companion::set( 1, 3 ); // 1<->3; 2 orphaned, 4 orphaned
		self::assertSame( array( 4 ), $r['cleared'], 'the new partner\'s previous partner is reported' );
		self::assertSame( 3, PMH_Companion::get( 1 ) );
		self::assertSame( 1, PMH_Companion::get( 3 ) );
		self::assertSame( 0, PMH_Companion::get( 2 ), 'old partner unlinked' );
		self::assertSame( 0, PMH_Companion::get( 4 ) );
	}

	public function test_clearing_and_no_ops(): void {
		PMH_Companion::set( 1, 2 );
		PMH_Companion::set( 1, 0 );
		self::assertSame( 0, PMH_Companion::get( 1 ) );
		self::assertSame( 0, PMH_Companion::get( 2 ) );

		PMH_Companion::set( 1, 1 );
		self::assertSame( 0, PMH_Companion::get( 1 ), 'self link ignored' );
		PMH_Companion::set( 1, 999 );
		self::assertSame( 0, PMH_Companion::get( 1 ), 'non-product ignored' );
		PMH_Fake_WP::add_post( 5, 'post' );
		PMH_Companion::set( 1, 5 );
		self::assertSame( 0, PMH_Companion::get( 1 ), 'a page is not a companion' );
	}

	public function test_delete_clears_the_partner(): void {
		PMH_Companion::set( 1, 2 );
		PMH_Companion::on_delete( 2 );
		self::assertSame( 0, PMH_Companion::get( 1 ) );
	}

	public function test_save_is_gated_and_reports_cleared_links(): void {
		PMH_Companion::set( 2, 3 );
		$_POST = array( 'pmh_companion_nonce' => 'n', 'pmh_companion' => '2' );

		PMH_Fake_WP::$nonce_ok = false;
		PMH_Companion::save( 1 );
		self::assertSame( 0, PMH_Companion::get( 1 ) );

		PMH_Fake_WP::$nonce_ok = true;
		PMH_Fake_WP::$can      = false;
		PMH_Companion::save( 1 );
		self::assertSame( 0, PMH_Companion::get( 1 ) );

		PMH_Fake_WP::$can = true;
		PMH_Companion::save( 1 );
		self::assertSame( 2, PMH_Companion::get( 1 ) );
		self::assertSame( 0, PMH_Companion::get( 3 ) );
		$notice = PMH_Notices::take( 'companion' );
		self::assertStringContainsString( 'Product 3 no longer has a companion', $notice['messages'][0] );

		$_POST = array( 'other' => 'x' );
		PMH_Companion::save( 1 );
		self::assertSame( 2, PMH_Companion::get( 1 ), 'a save without the field leaves the link alone' );
		$_POST = array();
	}

	public function test_notices_render_only_on_product_screens(): void {
		PMH_Notices::set( 'companion', array( 'Moved' ), array() );
		$screen            = new PMH_Fake_Screen();
		$screen->post_type = 'page';
		PMH_Fake_WP::$screen = $screen;
		ob_start();
		PMH_Companion::render_notices();
		self::assertSame( '', ob_get_clean(), 'kept for the product screen' );

		$screen->post_type = 'product';
		ob_start();
		PMH_Companion::render_notices();
		self::assertStringContainsString( 'Moved', ob_get_clean() );
		self::assertNull( PMH_Notices::take( 'companion' ), 'consumed' );
	}

	public function test_link_html_both_directions(): void {
		PMH_Companion::set( 1, 2 );
		$from_unisex = PMH_Companion::link_html( 1 );
		self::assertSame(
			"<span class=\"pmh-companion pmh-companion--bella-3001\"><span class=\"pmh-companion__fit\">Unisex sizing.</span> <a class=\"pmh-companion__link\" href=\"https://example.test/shop/product-2/\"><em>Looking for women\u{2019}s sizes?</em></a></span>",
			$from_unisex
		);
		$from_womens = PMH_Companion::link_html( 2, 'note' );
		self::assertSame(
			'<span class="pmh-companion pmh-companion--bella-6004 note"><a class="pmh-companion__link" href="https://example.test/shop/product-1/"><em>Looking for men&#039;s/unisex sizes?</em></a></span>',
			$from_womens,
			'no fit label on the women\'s side, link only'
		);
	}

	public function test_link_html_empty_cases(): void {
		self::assertSame( '', PMH_Companion::link_html( 1 ), 'no companion' );

		PMH_Companion::set( 1, 2 );
		PMH_Fake_WP::$posts[2]['status'] = 'draft';
		self::assertSame( '', PMH_Companion::link_html( 1 ), 'companion not published' );
		PMH_Fake_WP::$posts[2]['status'] = 'publish';

		PMH_Blank::update( $this->womens->term_id, array( 'link_text' => '' ) );
		self::assertSame( '', PMH_Companion::link_html( 1 ), 'companion blank has no link text' );
		PMH_Blank::update( $this->womens->term_id, array( 'link_text' => 'x' ) );

		PMH_Fake_WP::$object_terms[2] = array();
		self::assertSame( '', PMH_Companion::link_html( 1 ), 'companion has no blank' );
	}

	public function test_shortcode_and_metabox_field(): void {
		PMH_Companion::set( 1, 2 );
		PMH_Fake_WC::$products = array();
		self::assertStringContainsString( 'pmh-companion__link', PMH_Shortcodes::companion_link( array( 'product_id' => '1', 'class' => 'x' ) ) );
		self::assertSame( '', PMH_Shortcodes::companion_link( array( 'product_id' => '3' ) ) );

		$field = PMH_Companion::render_field( 1 );
		self::assertStringContainsString( 'class="wc-product-search"', $field );
		self::assertStringContainsString( 'data-exclude="1"', $field );
		self::assertStringContainsString( '<option value="2" selected="selected">Product 2</option>', $field );
		self::assertStringContainsString( 'name="pmh_companion_nonce"', $field );
		self::assertStringContainsString( '<p class="description">The unisex or women', $field, 'always-visible description, not only a tooltip' );
	}
}
