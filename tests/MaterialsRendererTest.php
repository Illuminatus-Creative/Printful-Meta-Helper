<?php

use PHPUnit\Framework\TestCase;

final class MaterialsRendererTest extends TestCase {

	private static function data( array $overrides = array() ): array {
		return array_merge(
			array(
				'material_solid'      => '100% cotton',
				'material_exceptions' => "Sport Grey is 90% cotton, 10% polyester\nHeather colors are 50% cotton, 50% polyester",
				'fabric_weight'       => '5.0–5.3 oz/yd² (170-180 g/m²)',
				'construction'        => "Tubular fabric\nTaped neck and shoulders",
				'care'                => '',
				'disclaimers'         => 'The fabric is slightly sheer.',
			),
			$overrides
		);
	}

	public function test_markup_contract(): void {
		$html = PMH_Renderer::materials( pmh_test_blank(), self::data() );

		self::assertStringStartsWith( '<div class="pmh-materials pmh-materials--gildan-5000" data-pmh-blank="gildan-5000"><dl class="pmh-materials__list">', $html );
		self::assertStringContainsString( '<div class="pmh-materials__item pmh-materials__item--material"><dt class="pmh-materials__label">Material</dt><dd class="pmh-materials__value"><span class="pmh-materials__base">100% cotton</span><ul class="pmh-materials__exceptions"><li>Sport Grey is 90% cotton, 10% polyester</li><li>Heather colors are 50% cotton, 50% polyester</li></ul></dd></div>', $html );
		self::assertStringContainsString( '<dt class="pmh-materials__label">Fabric weight</dt><dd class="pmh-materials__value">5.0–5.3 oz/yd² (170-180 g/m²)</dd>', $html );
		self::assertStringContainsString( '<dd class="pmh-materials__value"><ul class="pmh-materials__lines"><li>Tubular fabric</li><li>Taped neck and shoulders</li></ul></dd>', $html );
		// Empty care is skipped entirely.
		self::assertStringNotContainsString( 'pmh-materials__item--care', $html );
		self::assertStringContainsString( '<div class="pmh-materials__item pmh-materials__item--disclaimers"><dt class="pmh-materials__label">Disclaimers</dt><dd class="pmh-materials__value">The fabric is slightly sheer.</dd></div>', $html, 'disclaimers render by default, last' );
		self::assertGreaterThan( strpos( $html, 'item--construction' ), strpos( $html, 'item--disclaimers' ) );
	}

	public function test_field_selection_and_no_labels(): void {
		$html = PMH_Renderer::materials(
			pmh_test_blank(),
			self::data(),
			array(
				'fields' => array( 'weight', 'bogus' ),
				'labels' => false,
				'class'  => 'tight',
			)
		);
		self::assertStringContainsString( 'pmh-materials--gildan-5000 tight"', $html );
		self::assertStringNotContainsString( '<dt', $html );
		self::assertStringNotContainsString( 'pmh-materials__item--material', $html );
		self::assertSame( 1, substr_count( $html, 'pmh-materials__item--' ) );
	}

	public function test_all_empty_is_empty_string(): void {
		$empty = self::data(
			array(
				'material_solid'      => '',
				'material_exceptions' => '',
				'fabric_weight'       => '',
				'construction'        => '',
				'disclaimers'         => '',
			)
		);
		self::assertSame( '', PMH_Renderer::materials( pmh_test_blank(), $empty ) );
	}

	public function test_single_line_field_is_plain_text(): void {
		$html = PMH_Renderer::materials( pmh_test_blank(), self::data( array( 'construction' => 'Tubular fabric' ) ), array( 'fields' => array( 'construction' ) ) );
		self::assertStringContainsString( '<dd class="pmh-materials__value">Tubular fabric</dd>', $html );
	}
}
