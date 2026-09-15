<?php

use PHPUnit\Framework\TestCase;

final class AdminHelpTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
	}

	public function test_every_tip_key_used_in_code_exists(): void {
		$used = array();
		foreach ( glob( PMH_DIR . 'includes/*.php' ) as $file ) {
			preg_match_all( "/PMH_Admin_Help::tip\\(\\s*'([a-z_]+)'\\s*\\)/", (string) file_get_contents( $file ), $m );
			$used = array_merge( $used, $m[1] );
		}
		// Blank Groups builds its header keys as 'groups_' . $key.
		foreach ( array( 'apply', 'chart', 'products', 'assign' ) as $col ) {
			$used[] = 'groups_' . $col;
		}
		// The blank screen declares its tips in the field list.
		foreach ( PMH_Term_Meta::field_specs( PMH_Blank::defaults() ) as $spec ) {
			if ( '' !== $spec['tip'] ) {
				$used[] = $spec['tip'];
			}
		}

		$used    = array_unique( $used );
		$known   = array_keys( PMH_Admin_Help::strings() );
		$unknown = array_diff( $used, $known );
		self::assertSame( array(), array_values( $unknown ), 'tip keys referenced in code but missing from strings()' );
		self::assertGreaterThan( 15, count( $used ), 'the scan found the tips' );
		$unused = array_diff( $known, $used );
		self::assertSame( array(), array_values( $unused ), 'strings() entries no screen references' );
	}

	public function test_field_specs_are_complete(): void {
		$specs = PMH_Term_Meta::field_specs( PMH_Blank::defaults() );
		$ids   = array_column( $specs, 'id' );
		self::assertSame( $ids, array_unique( $ids ), 'field ids are unique' );
		foreach ( $specs as $spec ) {
			self::assertNotSame( '', $spec['label'], $spec['id'] );
			self::assertNotSame( '', $spec['control'], $spec['id'] );
			self::assertNotSame( '', $spec['section'], $spec['id'] );
			if ( 'pmh_shortcodes' !== $spec['id'] ) {
				self::assertNotSame( '', $spec['description'], $spec['id'] . ' has a description' );
				self::assertNotSame( '', $spec['tip'], $spec['id'] . ' has a tip' );
			}
		}
	}

	public function test_strings_are_utilitarian_length(): void {
		foreach ( PMH_Admin_Help::strings() as $key => $text ) {
			self::assertNotSame( '', trim( $text ), $key );
			self::assertLessThanOrEqual( 420, strlen( $text ), "$key is too long for a tooltip" );
			self::assertStringNotContainsString( '<', $text, "$key contains markup; tips are plain text" );
		}
	}

	public function test_tip_fallback_is_escaped_and_focusable(): void {
		$html = PMH_Admin_Help::tip( 'kind' );
		self::assertStringStartsWith( '<span class="pmh-tip dashicons dashicons-editor-help" tabindex="0" role="img" title="', $html );
		self::assertStringContainsString( 'aria-label="', $html );
		self::assertStringContainsString( 'Apparel renders a size chart.', $html );
		self::assertSame( '', PMH_Admin_Help::tip( 'no_such_key' ) );

		$exc = PMH_Admin_Help::tip( 'material_exceptions' );
		self::assertStringContainsString( '&quot;Sport Grey', $exc, 'double quotes inside the attribute are encoded' );
	}

	public function test_tabs_per_context(): void {
		self::assertSame( array( 'about', 'data', 'shortcodes' ), array_column( PMH_Admin_Help::tabs( 'blank' ), 'id' ) );
		self::assertSame( array( 'blank' ), array_column( PMH_Admin_Help::tabs( 'product' ), 'id' ) );
		self::assertSame( array( 'groups-how', 'groups-run' ), array_column( PMH_Admin_Help::tabs( 'groups' ), 'id' ) );
		self::assertSame( array(), PMH_Admin_Help::tabs( 'elsewhere' ) );

		foreach ( array( 'blank', 'product', 'groups' ) as $context ) {
			foreach ( PMH_Admin_Help::tabs( $context ) as $tab ) {
				self::assertNotSame( '', $tab['title'] );
				self::assertStringContainsString( '<p>', $tab['content'] );
				self::assertSame( $tab['content'], wp_kses_post( $tab['content'] ), 'tab content is safe under kses' );
			}
		}
		$shortcodes = PMH_Admin_Help::tabs( 'blank' )[2]['content'];
		foreach ( array( '[pmh_size_chart]', '[pmh_materials]', '[pmh_blank_name]', '[pmh_companion_link]' ) as $sc ) {
			self::assertStringContainsString( $sc, $shortcodes );
		}
		self::assertStringContainsString( 'supplier=&quot;0&quot;', $shortcodes, 'every size-chart attribute is documented' );
		self::assertStringContainsString( 'disclaimers', $shortcodes );
		self::assertStringContainsString( 'Fit label', PMH_Admin_Help::tabs( 'blank' )[0]['content'], 'companion wording explained where it is set' );
		self::assertStringContainsString( 'Companion product', PMH_Admin_Help::tabs( 'product' )[0]['content'] );
	}

	public function test_context_detection_and_registration(): void {
		$cases = array(
			array( array( 'taxonomy' => 'pmh_blank', 'base' => 'edit-tags', 'id' => 'edit-pmh_blank' ), 3 ),
			array( array( 'taxonomy' => 'pmh_blank', 'base' => 'term', 'id' => 'edit-pmh_blank' ), 3 ),
			array( array( 'post_type' => 'product', 'base' => 'post', 'id' => 'product' ), 1 ),
			array( array( 'id' => 'product_page_pmh-blank-groups', 'base' => 'product_page_pmh-blank-groups' ), 2 ),
			array( array( 'taxonomy' => 'product_cat', 'base' => 'edit-tags', 'id' => 'edit-product_cat' ), 0 ),
			array( array( 'post_type' => 'product', 'base' => 'edit', 'id' => 'edit-product' ), 0 ),
			array( array( 'post_type' => 'page', 'base' => 'post', 'id' => 'page' ), 0 ),
		);
		foreach ( $cases as [ $props, $expected ] ) {
			$screen = new PMH_Fake_Screen();
			foreach ( $props as $k => $v ) {
				$screen->$k = $v;
			}
			PMH_Fake_WP::$screen = $screen;
			PMH_Admin_Help::register_help_tabs();
			self::assertCount( $expected, $screen->help_tabs, $screen->id );
			foreach ( $screen->help_tabs as $tab ) {
				self::assertStringStartsWith( 'pmh-', $tab['id'] );
			}
		}
		PMH_Fake_WP::$screen = null;
		PMH_Admin_Help::register_help_tabs(); // no screen: no error
		self::assertTrue( true );
	}

	public function test_intro_points_to_blank_groups_only_when_empty(): void {
		ob_start();
		PMH_Admin_Help::render_intro();
		$empty = ob_get_clean();
		self::assertStringContainsString( 'No blanks yet.', $empty );
		self::assertStringContainsString( 'page=pmh-blank-groups', $empty );

		PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000' );
		ob_start();
		PMH_Admin_Help::render_intro();
		$has = ob_get_clean();
		self::assertStringNotContainsString( 'No blanks yet.', $has );
		self::assertStringContainsString( 'A blank is the garment or item', $has );
	}

	public function test_shortcodes_box_lists_all_three(): void {
		$html = PMH_Admin_Help::shortcodes_box();
		foreach ( array( '[pmh_size_chart]', '[pmh_materials]', '[pmh_blank_name]', '[pmh_companion_link]' ) as $sc ) {
			self::assertStringContainsString( '<code>' . $sc . '</code>', $html );
		}
	}

	public function test_woocommerce_screen_ids_adds_ours_once(): void {
		$ids = PMH_Admin_Help::woocommerce_screen_ids( array( 'product', 'edit-pmh_blank' ) );
		self::assertSame( array( 'product', 'edit-pmh_blank', 'product_page_pmh-blank-groups' ), $ids );
	}

	public function test_blank_edit_form_carries_tips_descriptions_and_shortcodes(): void {
		PMH_Fake_WP::add_term( 'product_cat', 'Tees', 5 );
		$term = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000' );
		ob_start();
		PMH_Term_Meta::render_edit_form( $term );
		$html = ob_get_clean();

		$tips = substr_count( $html, 'class="pmh-tip' );
		self::assertGreaterThanOrEqual( 17, $tips, 'one tip per explained field' );
		self::assertStringContainsString( '<code>[pmh_size_chart]</code>', $html );
		// Every field row has a description except the shortcodes box.
		$rows = substr_count( $html, '<tr class="form-field pmh-field' );
		$desc = substr_count( $html, '<p class="description">' );
		self::assertGreaterThanOrEqual( $rows, $desc );
	}

	public function test_add_form_uses_div_markup_with_headings_and_nonce(): void {
		ob_start();
		PMH_Term_Meta::render_add_form();
		$html = ob_get_clean();
		self::assertStringContainsString( 'name="pmh_blank_nonce"', $html );
		self::assertStringContainsString( '<div class="form-field pmh-field pmh-field--pmh_kind">', $html );
		self::assertStringNotContainsString( '<tr class="form-field', $html, 'add form uses div rows, not table rows' );
		self::assertSame( 5, substr_count( $html, '<h3 class="pmh-heading">' ), 'Applies to, Materials, Size chart, Companion link, Parked' );
		self::assertSame( substr_count( $html, 'class="pmh-tip' ), count( array_filter( array_column( PMH_Term_Meta::field_specs( PMH_Blank::defaults() ), 'tip' ) ) ) );
	}

	public function test_product_metabox_carries_tips_and_manage_link(): void {
		PMH_Fake_WP::add_post( 1 );
		ob_start();
		PMH_Product_Meta::render_metabox( (object) array( 'ID' => 1 ) );
		$html = ob_get_clean();
		self::assertSame( 4, substr_count( $html, 'class="pmh-tip' ), 'select, show all, preview, companion' );
		self::assertStringContainsString( 'wc-product-search', $html );
		self::assertStringContainsString( 'edit-tags.php?taxonomy=pmh_blank&#038;post_type=product', $html );
		self::assertStringContainsString( 'Manage blanks', $html );
	}
}
