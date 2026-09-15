<?php

use PHPUnit\Framework\TestCase;

/**
 * Colour exceptions render only for colours the product is sold in.
 */
final class MaterialsColourTest extends TestCase {

	private const GILDAN = array(
		'Sport Grey is 90% cotton, 10% polyester',
		'Ash Grey is 99% cotton, 1% polyester',
		'Heather colors are 50% cotton, 50% polyester',
	);

	private const BELLA = array(
		'Ash color is 99% combed and ring-spun cotton, 1% polyester',
		'Heather colors are 52% combed and ring-spun cotton, 48% polyester',
		'Athletic and Black Heather are 90% combed and ring-spun cotton, 10% polyester',
		'Heather Prism colors are 99% combed and ring-spun cotton, 1% polyester',
	);

	protected function setUp(): void {
		PMH_Fake_WP::reset();
		PMH_Fake_WC::$products = array();
	}

	public function test_solid_colours_drop_every_exception(): void {
		self::assertSame( array(), PMH_Renderer::exceptions_for_colours( self::GILDAN, array( 'Black', 'Navy', 'Olive' ) ) );
	}

	public function test_matching_is_by_whole_phrase_inside_the_colour_name(): void {
		self::assertSame( array( self::GILDAN[0] ), PMH_Renderer::exceptions_for_colours( self::GILDAN, array( 'Sport Grey' ) ) );
		self::assertSame( array( self::GILDAN[0] ), PMH_Renderer::exceptions_for_colours( self::GILDAN, array( 'Sport Gray' ) ), 'gray/grey spelling' );
		self::assertSame( array( self::GILDAN[2] ), PMH_Renderer::exceptions_for_colours( self::GILDAN, array( 'Dark Heather' ) ), '"Heather colors" matches any heather' );
		self::assertSame( array(), PMH_Renderer::exceptions_for_colours( self::GILDAN, array( 'Ash' ) ), '"Ash Grey" is not contained in "Ash"' );
		self::assertSame( array( self::GILDAN[1] ), PMH_Renderer::exceptions_for_colours( self::GILDAN, array( 'Ash Grey' ) ) );
	}

	public function test_compound_subjects_split_on_and(): void {
		self::assertSame( array(), PMH_Renderer::exceptions_for_colours( self::BELLA, array( 'Black' ) ), '"Black Heather" must not match plain Black' );
		self::assertSame( array( self::BELLA[1], self::BELLA[2] ), PMH_Renderer::exceptions_for_colours( self::BELLA, array( 'Black Heather' ) ) );
		self::assertSame( array( self::BELLA[1], self::BELLA[2] ), PMH_Renderer::exceptions_for_colours( self::BELLA, array( 'Athletic Heather' ) ) );
		self::assertSame( array( self::BELLA[1], self::BELLA[3] ), PMH_Renderer::exceptions_for_colours( self::BELLA, array( 'Heather Prism Lilac' ) ) );
		self::assertSame( array( self::BELLA[0] ), PMH_Renderer::exceptions_for_colours( self::BELLA, array( 'Ash' ) ), '"Ash color" strips the filler word' );
	}

	private const DISCLAIMERS = array(
		'Due to the fabric properties, the White color variant may appear off-white rather than bright white.',
		'Dark color speckles throughout the fabric are expected for the color Natural.',
		'The fabric is slightly sheer and may appear see-through, especially in lighter colors or under certain lighting conditions.',
	);

	public function test_disclaimers_naming_a_colour_follow_the_product_colours(): void {
		self::assertSame( array( self::DISCLAIMERS[2] ), PMH_Renderer::disclaimers_for_colours( self::DISCLAIMERS, array( 'Black', 'Navy' ) ), 'general line stays, colour-specific lines go' );
		self::assertSame( array( self::DISCLAIMERS[0], self::DISCLAIMERS[2] ), PMH_Renderer::disclaimers_for_colours( self::DISCLAIMERS, array( 'White' ) ), '"the White color variant"' );
		self::assertSame( array( self::DISCLAIMERS[1], self::DISCLAIMERS[2] ), PMH_Renderer::disclaimers_for_colours( self::DISCLAIMERS, array( 'Natural' ) ), '"the color Natural"' );
		self::assertSame( self::DISCLAIMERS, PMH_Renderer::disclaimers_for_colours( self::DISCLAIMERS, array( 'White', 'Natural' ) ) );
		self::assertSame( array( 'Heather colors may show slight marbling.' ), PMH_Renderer::disclaimers_for_colours( array( 'Heather colors may show slight marbling.' ), array( 'Dark Heather' ) ) );
		self::assertSame( array(), PMH_Renderer::disclaimers_for_colours( array( 'Heather colors may show slight marbling.' ), array( 'Black' ) ) );
	}

	public function test_materials_renderer_filters_disclaimers_with_colours(): void {
		$data = array( 'material_solid' => '100% cotton', 'disclaimers' => implode( "\n", self::DISCLAIMERS ) );
		self::assertSame( 3, substr_count( PMH_Renderer::materials( pmh_test_blank(), $data ), '<li>' ) );
		$html = PMH_Renderer::materials( pmh_test_blank(), $data, array( 'colours' => array( 'Black' ) ) );
		self::assertStringContainsString( 'slightly sheer', $html );
		self::assertStringNotContainsString( 'off-white', $html );
		self::assertStringNotContainsString( 'Natural', $html );
	}

	public function test_lines_without_a_subject_are_kept(): void {
		$lines = array( 'Fabric may pill after heavy washing', 'Heather colors are 50/50' );
		self::assertSame( array( $lines[0] ), PMH_Renderer::exceptions_for_colours( $lines, array( 'Black' ) ) );
	}

	public function test_materials_renderer_applies_the_filter_only_when_colours_are_given(): void {
		$data = array( 'material_solid' => '100% cotton', 'material_exceptions' => implode( "\n", self::GILDAN ) );
		$all  = PMH_Renderer::materials( pmh_test_blank(), $data );
		self::assertSame( 3, substr_count( $all, '<li>' ) );
		$some = PMH_Renderer::materials( pmh_test_blank(), $data, array( 'colours' => array( 'Sport Grey', 'Black' ) ) );
		self::assertSame( 1, substr_count( $some, '<li>' ) );
		$none = PMH_Renderer::materials( pmh_test_blank(), $data, array( 'colours' => array( 'Black' ) ) );
		self::assertStringNotContainsString( 'pmh-materials__exceptions', $none );
		self::assertStringContainsString( '100% cotton', $none, 'base material stays' );
	}

	public function test_colours_are_read_from_variations(): void {
		pmh_fake_variable_product( 1, array( 10 => 's', 11 => 'm' ), 'pa_size', array( new PMH_Fake_Attribute( 'Color', true ) ) );
		PMH_Fake_WP::$post_meta[10]['attribute_color'] = 'Black';
		PMH_Fake_WP::$post_meta[11]['attribute_color'] = 'Sport Grey';

		$found = PMH_Sizes::colours_for_product( 1 );
		self::assertSame( PMH_Sizes::SIZES, $found['state'] );
		self::assertSame( array( 'Black', 'Sport Grey' ), $found['colours'] );
		self::assertSame( 'color', $found['attribute'] );

		$sizes = PMH_Sizes::for_product( 1 );
		self::assertSame( array( 'S', 'M' ), $sizes['sizes'], 'size and colour results are cached under separate keys' );
	}

	public function test_no_colour_attribute_is_unfiltered(): void {
		pmh_fake_variable_product( 1, array( 10 => 's' ) );
		self::assertSame( PMH_Sizes::UNFILTERED, PMH_Sizes::colours_for_product( 1 )['state'] );
	}

	public function test_shortcode_filters_by_the_products_colours(): void {
		$blank = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000', 7, 'gildan-5000' );
		PMH_Blank::update( $blank->term_id, array( 'material_solid' => '100% cotton', 'material_exceptions' => implode( "\n", self::GILDAN ) ) );
		pmh_fake_variable_product( 1, array( 10 => 's', 11 => 'm', 12 => 'l' ), 'pa_size', array( new PMH_Fake_Attribute( 'Color', true ) ) );
		foreach ( array( 10 => 'Black', 11 => 'Navy', 12 => 'Olive' ) as $cid => $colour ) {
			PMH_Fake_WP::$post_meta[ $cid ]['attribute_color'] = $colour;
		}
		wp_set_object_terms( 1, array( $blank->term_id ), 'pmh_blank' );

		$html = PMH_Shortcodes::materials( array( 'product_id' => '1' ) );
		self::assertStringNotContainsString( 'Heather', $html, 'black/navy/olive tee shows no heather line' );
		self::assertStringContainsString( '100% cotton', $html );

		$all = PMH_Shortcodes::materials( array( 'product_id' => '1', 'filter_colours' => '0' ) );
		self::assertStringContainsString( 'Heather colors are 50% cotton', $all );

		PMH_Fake_WP::$post_meta[12]['attribute_color'] = 'Dark Heather';
		PMH_Sizes::flush_for_product( 1 );
		self::assertStringContainsString( 'Heather colors are 50% cotton', PMH_Shortcodes::materials( array( 'product_id' => '1' ) ) );
	}

	public function test_shortcode_shows_everything_without_a_colour_attribute(): void {
		$blank = PMH_Fake_WP::add_term( 'pmh_blank', 'Gildan 5000', 7, 'gildan-5000' );
		PMH_Blank::update( $blank->term_id, array( 'material_exceptions' => implode( "\n", self::GILDAN ) ) );
		pmh_fake_variable_product( 1, array( 10 => 's' ) );
		wp_set_object_terms( 1, array( $blank->term_id ), 'pmh_blank' );
		self::assertSame( 3, substr_count( PMH_Shortcodes::materials( array( 'product_id' => '1' ) ), '<li>' ) );
	}
}
