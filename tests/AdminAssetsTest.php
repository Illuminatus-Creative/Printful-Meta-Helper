<?php

use PHPUnit\Framework\TestCase;

final class AdminAssetsTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
	}

	private static function screen( array $props ): void {
		$s = new PMH_Fake_Screen();
		foreach ( $props as $k => $v ) {
			$s->$k = $v;
		}
		PMH_Fake_WP::$screen = $s;
	}

	public function test_blank_screens_get_css_and_the_grid_script(): void {
		self::screen( array( 'taxonomy' => 'pmh_blank', 'base' => 'edit-tags' ) );
		PMH_Admin_Assets::enqueue( 'edit-tags.php' );
		self::assertSame( array( 'pmh-admin', 'pmh-size-grid' ), PMH_Fake_WP::$enqueued );
		self::assertStringStartsWith( 'window.pmhGridI18n = {', PMH_Fake_WP::$inline['pmh-size-grid'] );
	}

	public function test_product_screen_gets_css_filter_script_and_inline_data(): void {
		PMH_Fake_WP::add_post( 5 );
		$_GET['post'] = '5';
		self::screen( array( 'post_type' => 'product', 'base' => 'post' ) );
		PMH_Admin_Assets::enqueue( 'post.php' );
		self::assertSame( array( 'pmh-admin', 'pmh-blank-filter' ), PMH_Fake_WP::$enqueued );
		self::assertStringStartsWith( 'window.pmhAssign = {', PMH_Fake_WP::$inline['pmh-blank-filter'] );
		self::assertStringContainsString( '"sizesState":"unfiltered"', PMH_Fake_WP::$inline['pmh-blank-filter'] );
	}

	public function test_inline_json_is_html_safe(): void {
		PMH_Fake_WP::add_term( 'pmh_blank', 'Evil </script><script>alert(1)</script>' );
		self::screen( array( 'post_type' => 'product', 'base' => 'post' ) );
		PMH_Admin_Assets::enqueue( 'post-new.php' );
		$js = PMH_Fake_WP::$inline['pmh-blank-filter'];
		self::assertStringNotContainsString( '<', substr( $js, strpos( $js, '{' ) ), 'no raw angle bracket anywhere in the JSON' );
		self::assertStringContainsString( '\u003C\/script\u003E', $js );
	}

	public function test_inline_data_carries_signatures_for_the_mismatch_check(): void {
		PMH_Fake_WP::add_post( 5 );
		PMH_Fake_WP::$post_meta[5][ PMH_Importer::PRODUCT_META_KEY ] = pmh_fixture( 'gildan-5000.json' );
		$match = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000' );
		$other = PMH_Fake_WP::add_term( 'pmh_blank', 'Bella 3001' );
		PMH_Blank::update( $match->term_id, array( 'chart' => pmh_test_chart() ) );
		PMH_Blank::update( $other->term_id, array( 'chart' => PMH_Size_Chart::filter_sizes( pmh_test_chart(), array( 'S', 'M' ) ) ) );

		$data = PMH_Product_Meta::inline_data( 5 );

		self::assertTrue( $data['product']['hasPrintfulChart'] );
		self::assertSame( $data['product']['signature'], $data['blanks'][ $match->term_id ]['signature'], 'matching blank shares the product signature' );
		self::assertNotSame( $data['product']['signature'], $data['blanks'][ $other->term_id ]['signature'] );
		self::assertSame( array( 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL' ), $data['product']['printfulSizes'] );
		self::assertSame( 'unfiltered', $data['product']['sizesState'], 'not a variable product' );
		self::assertArrayHasKey( 'mismatch', $data['i18n'] );
	}

	public function test_groups_screen_gets_css_only(): void {
		self::screen( array( 'id' => 'product_page_pmh-blank-groups' ) );
		PMH_Admin_Assets::enqueue( 'product_page_pmh-blank-groups' );
		self::assertSame( array( 'pmh-admin' ), PMH_Fake_WP::$enqueued );
	}

	public function test_other_screens_get_nothing(): void {
		foreach ( array(
			array( 'edit-tags.php', array( 'taxonomy' => 'product_cat', 'base' => 'edit-tags' ) ),
			array( 'post.php', array( 'post_type' => 'page', 'base' => 'post' ) ),
			array( 'edit.php', array( 'post_type' => 'product', 'base' => 'edit' ) ),
		) as [ $hook, $props ] ) {
			self::screen( $props );
			PMH_Admin_Assets::enqueue( $hook );
			self::assertSame( array(), PMH_Fake_WP::$enqueued, $hook );
		}
		PMH_Fake_WP::$screen = null;
		PMH_Admin_Assets::enqueue( 'post.php' );
		self::assertSame( array(), PMH_Fake_WP::$enqueued, 'no screen object' );
	}
}
