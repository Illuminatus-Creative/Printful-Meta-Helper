<?php

use PHPUnit\Framework\TestCase;

/**
 * Front-end asset loading: early in the head on product pages with a
 * blank, at render time everywhere else.
 */
final class RendererAssetsTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
		PMH_Fake_WP::add_post( 1 );
		PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000', 7 );
	}

	public function test_product_page_with_a_blank_enqueues_in_the_head(): void {
		wp_set_object_terms( 1, array( 7 ), 'pmh_blank' );
		PMH_Fake_WC::$singular = 'product';
		PMH_Renderer::register_assets();
		self::assertSame( array( 'pmh-size-chart', 'pmh-unit-toggle' ), PMH_Fake_WP::$enqueued );
	}

	public function test_product_page_without_a_blank_waits_for_a_shortcode(): void {
		PMH_Fake_WC::$singular = 'product';
		PMH_Renderer::register_assets();
		self::assertSame( array(), PMH_Fake_WP::$enqueued );
		PMH_Renderer::enqueue_assets();
		self::assertSame( array( 'pmh-size-chart', 'pmh-unit-toggle' ), PMH_Fake_WP::$enqueued );
	}

	public function test_other_pages_never_enqueue_early(): void {
		wp_set_object_terms( 1, array( 7 ), 'pmh_blank' );
		PMH_Fake_WC::$singular = 'page';
		PMH_Renderer::register_assets();
		self::assertSame( array(), PMH_Fake_WP::$enqueued );
	}

	public function test_enqueue_before_register_still_registers(): void {
		PMH_Renderer::enqueue_assets();
		self::assertSame( array( 'pmh-size-chart', 'pmh-unit-toggle' ), PMH_Fake_WP::$enqueued );
	}
}
