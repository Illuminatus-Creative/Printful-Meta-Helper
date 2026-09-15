<?php

use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	public function test_markup_contract(): void {
		$chart = PMH_Size_Chart::filter_sizes( pmh_test_chart(), array( 'S', 'M' ) );
		$html  = PMH_Renderer::size_chart( pmh_test_blank(), $chart );

		self::assertStringStartsWith( '<div class="pmh-chart pmh-chart--gildan-5000 pmh-chart--product pmh-chart--unit-in" data-pmh-unit="in" data-pmh-blank="gildan-5000">', $html );
		self::assertStringContainsString( '<button type="button" class="pmh-chart__unit" data-unit="in" aria-pressed="true">Inches</button>', $html );
		self::assertStringContainsString( '<button type="button" class="pmh-chart__unit" data-unit="cm" aria-pressed="false">Centimeters</button>', $html );

		// Header: Size then one column per measurement.
		self::assertStringContainsString( '<th scope="col" class="pmh-chart__head pmh-chart__head--size">Size</th><th scope="col" class="pmh-chart__head">Length</th><th scope="col" class="pmh-chart__head">Width</th><th scope="col" class="pmh-chart__head">Sleeve length</th>', $html );

		// One row per size, both units present in every cell.
		self::assertSame( 2, substr_count( $html, '<tr class="pmh-chart__row"' ) );
		self::assertStringContainsString( '<tr class="pmh-chart__row" data-size="S"><th scope="row" class="pmh-chart__size">S</th>', $html );
		self::assertStringContainsString( '<span class="pmh-chart__val pmh-chart__val--in">28&quot;</span><span class="pmh-chart__val pmh-chart__val--cm">71.1</span>', $html );
		self::assertStringContainsString( '<span class="pmh-chart__val pmh-chart__val--in">15.6&quot;</span><span class="pmh-chart__val pmh-chart__val--cm">39.7</span>', $html );
		self::assertStringNotContainsString( 'data-size="L"', $html );

		self::assertStringContainsString( '<p class="pmh-chart__note pmh-chart__note--supplier">Measurements are provided by suppliers.</p><p class="pmh-chart__note pmh-chart__note--blank">Product measurements may vary by up to 2&quot; (5 cm).</p>', $html, 'supplier line first, then the blank\'s note' );
		self::assertStringEndsWith( '</div>', $html );
	}

	public function test_options(): void {
		$html = PMH_Renderer::size_chart(
			pmh_test_blank(),
			pmh_test_chart(),
			array(
				'unit'     => 'cm',
				'toggle'   => false,
				'note'     => false,
				'supplier' => false,
				'class'    => 'my-chart <bad>',
				'table'    => 'body',
			)
		);
		self::assertStringContainsString( 'pmh-chart--body', $html );
		self::assertStringContainsString( 'pmh-chart--unit-cm', $html );
		self::assertStringContainsString( 'pmh-chart--locked', $html );
		self::assertStringContainsString( ' my-chart bad"', $html );
		self::assertStringNotContainsString( 'pmh-chart__toggle', $html );
		self::assertStringNotContainsString( 'pmh-chart__note', $html );
	}

	public function test_supplier_line_shows_without_a_note_and_can_be_switched_off(): void {
		$chart         = pmh_test_chart();
		$chart['note'] = '';
		$html          = PMH_Renderer::size_chart( pmh_test_blank(), $chart );
		self::assertStringContainsString( 'pmh-chart__note--supplier', $html );
		self::assertStringNotContainsString( 'pmh-chart__note--blank', $html );
		$off = PMH_Renderer::size_chart( pmh_test_blank(), $chart, array( 'supplier' => false ) );
		self::assertStringNotContainsString( 'pmh-chart__note', $off );
	}

	public function test_ranges_and_missing_cells(): void {
		$chart = PMH_Size_Chart::normalise(
			array(
				'sizes' => array( 'S', 'M' ),
				'rows'  => array(
					array( 'label' => 'Chest', 'values' => array( 'S' => array( 34, 37 ) ) ),
				),
			)
		);
		$html = PMH_Renderer::size_chart( pmh_test_blank(), $chart );
		self::assertStringContainsString( '34–37&quot;</span><span class="pmh-chart__val pmh-chart__val--cm">86.4–94</span>', $html );
		self::assertStringContainsString( '<td class="pmh-chart__cell pmh-chart__cell--empty"></td>', $html );
	}

	public function test_empty_chart_is_empty_string(): void {
		self::assertSame( '', PMH_Renderer::size_chart( pmh_test_blank(), PMH_Size_Chart::empty_chart() ) );
	}
}
