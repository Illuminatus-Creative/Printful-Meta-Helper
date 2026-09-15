<?php

use PHPUnit\Framework\TestCase;

final class ImporterTest extends TestCase {

	public function test_json_import_reads_both_tables_in_inches_only(): void {
		$result = PMH_Importer::from_json( pmh_fixture( 'gildan-5000.json' ) );

		$product = $result['product'];
		$body    = $result['body'];
		self::assertNotNull( $product );
		self::assertNotNull( $body );

		self::assertSame( array( 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL' ), $product['sizes'] );
		self::assertSame( 'Product measurements may vary by up to 2" (5 cm).', $product['note'] );

		self::assertSame( array( 'Length', 'Width', 'Sleeve length' ), array_column( $product['rows'], 'label' ) );
		self::assertSame( array( 'Length', 'Chest', 'Sleeve length' ), array_column( $body['rows'], 'label' ) );

		// Float noise rounded away.
		self::assertSame( array( 15.63 ), $product['rows'][2]['values']['S'] );
		self::assertSame( array( 22.8 ), $product['rows'][2]['values']['3XL'] );
		// Ranges preserved.
		self::assertSame( array( 34.0, 37.0 ), $body['rows'][1]['values']['S'] );
		self::assertSame( array( 62.0, 65.0 ), $body['rows'][1]['values']['5XL'] );
		// Width is a garment measurement, Chest is a body one.
		self::assertSame( array( 18.0 ), $product['rows'][1]['values']['S'] );
	}

	public function test_json_import_tolerates_result_wrapper_and_missing_body(): void {
		$data = json_decode( pmh_fixture( 'gildan-5000.json' ), true );
		unset( $data['modelMeasurements'] );
		$result = PMH_Importer::from_json( json_encode( array( 'result' => $data ) ) );
		self::assertNotNull( $result['product'] );
		self::assertNull( $result['body'] );
	}

	public function test_json_import_rejects_bad_input(): void {
		$this->expectException( PMH_Import_Exception::class );
		PMH_Importer::from_json( '{"foo": 1}' );
	}

	public function test_json_import_rejects_invalid_json(): void {
		$this->expectException( PMH_Import_Exception::class );
		PMH_Importer::from_json( '{not json' );
	}

	public function test_text_import_from_page_paste(): void {
		$chart = PMH_Importer::from_text( pmh_fixture( 'gildan-5000.txt' ) );

		self::assertSame( array( 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL' ), $chart['sizes'] );
		self::assertSame( array( 'Length', 'Width' ), array_column( $chart['rows'], 'label' ) );
		self::assertSame( array( 16.5 ), $chart['rows'][1]['values']['XS'] );
		self::assertSame( array( 35.0 ), $chart['rows'][0]['values']['5XL'] );
		self::assertSame( 'Product measurements may vary by up to 2" (5 cm).', $chart['note'] );
	}

	public function test_text_import_in_centimetres_converts_to_inches(): void {
		$chart = PMH_Importer::from_text( "Size\tLength\nS\t71.1\nM\t73.7", 'cm' );
		self::assertSame( array( 27.99 ), $chart['rows'][0]['values']['S'] );
		self::assertSame( array( 29.02 ), $chart['rows'][0]['values']['M'] );
	}

	public function test_text_import_without_header_fails(): void {
		$this->expectException( PMH_Import_Exception::class );
		PMH_Importer::from_text( "S\t28\nM\t29" );
	}

	public function test_materials_split(): void {
		$m = PMH_Importer::materials_from_text( pmh_fixture( 'gildan-5000-materials.txt' ) );

		self::assertSame( '100% cotton', $m['material_solid'] );
		self::assertSame(
			"Sport Grey is 90% cotton, 10% polyester\nAsh Grey is 99% cotton, 1% polyester\nHeather colors are 50% cotton, 50% polyester",
			$m['material_exceptions']
		);
		self::assertSame( '5.0–5.3 oz/yd² (170-180 g/m²)', $m['fabric_weight'] );
		self::assertSame(
			"Open-end yarn\nTubular fabric\nTaped neck and shoulders\nDouble seam at sleeves and bottom hem",
			$m['construction']
		);
		self::assertSame(
			"Due to the fabric properties, the White color variant may appear off-white rather than bright white.\nDark color speckles throughout the fabric are expected for the color Natural.",
			$m['disclaimers'],
			'Gildan shape: heading then bullet lines'
		);
	}

	public function test_materials_split_bella_inline_disclaimer(): void {
		$m = PMH_Importer::materials_from_text( pmh_fixture( 'bella-3001-materials.txt' ) );
		self::assertSame( 'Solid colors are 100% Airlume combed and ring-spun cotton', $m['material_solid'] );
		self::assertCount( 4, explode( "\n", $m['material_exceptions'] ) );
		self::assertSame( '4.2 oz./yd.² (142 g/m²)', $m['fabric_weight'] );
		self::assertSame( "Pre-shrunk fabric\n30 singles\nSide-seamed construction\nTear-away label\nShoulder-to-shoulder taping", $m['construction'] );
		self::assertSame( 'The fabric is slightly sheer and may appear see-through, especially in lighter colors or under certain lighting conditions.', $m['disclaimers'], 'Bella shape: inline sentence, label stripped' );
		self::assertSame( 13, $m['lines'] );
	}

	public function test_materials_split_inline_disclaimer_without_bullets(): void {
		$bare = str_replace( '* ', '', pmh_fixture( 'bella-3001-materials.txt' ) );
		$m    = PMH_Importer::materials_from_text( $bare );
		self::assertStringStartsWith( 'The fabric is slightly sheer', $m['disclaimers'] );
		self::assertStringNotContainsString( 'sheer', $m['construction'] );
	}

	public function test_materials_split_ignores_prose_only(): void {
		$m = PMH_Importer::materials_from_text( "Just a paragraph with 100% cotton in it. It has two sentences, so it reads as prose.\n" );
		self::assertSame( '', $m['material_solid'] );
		self::assertSame( '', $m['construction'] );
		self::assertSame( 0, $m['lines'] );
	}

	/** The crop-top paste as reported, with asterisk bullets and trailing spaces. */
	private const CROP = "* 100% combed cotton \n* Heather colors are 15% viscose and 85% cotton\n* Fabric weight: 5.3 oz/yd² (180 g/m²)\n* Relaxed fit\n* Cropped length\n* Ribbed crew neck \n* Dropped shoulders\n* Side-seamed construction\n* Shoulder-to-shoulder taping\n* Double-needle hems\n* Preshrunk\n* Blank product sourced from Bangladesh\n";

	private static function assert_crop( array $m, string $label ): void {
		self::assertSame( '100% combed cotton', $m['material_solid'], $label );
		self::assertSame( 'Heather colors are 15% viscose and 85% cotton', $m['material_exceptions'], $label );
		self::assertSame( '5.3 oz/yd² (180 g/m²)', $m['fabric_weight'], $label );
		self::assertSame( "Relaxed fit\nCropped length\nRibbed crew neck\nDropped shoulders\nSide-seamed construction\nShoulder-to-shoulder taping\nDouble-needle hems\nPreshrunk", $m['construction'], $label );
		self::assertSame( 12, $m['lines'], $label );
	}

	public function test_materials_split_accepts_asterisk_bullets(): void {
		self::assert_crop( PMH_Importer::materials_from_text( self::CROP ), 'asterisks' );
		self::assert_crop( PMH_Importer::materials_from_text( str_replace( "\n", "\r\n", self::CROP ) ), 'CRLF' );
		self::assert_crop( PMH_Importer::materials_from_text( str_replace( '* ', "-\xC2\xA0", self::CROP ) ), 'hyphen + nbsp' );
	}

	public function test_materials_split_accepts_bare_lines_as_copied_from_chrome(): void {
		$bare = str_replace( '* ', '', self::CROP );
		self::assert_crop( PMH_Importer::materials_from_text( $bare ), 'no markers' );

		// With the intro paragraph Printful shows above the list.
		$with_intro = "The crop top that has it all. Soft, comfortable, and made to last.\n\n" . $bare;
		self::assert_crop( PMH_Importer::materials_from_text( $with_intro ), 'intro dropped' );
	}

	public function test_materials_split_accepts_html_list(): void {
		$html = '<p>Intro sentence here. Another one.</p><ul><li>100% combed cotton</li><li>Heather colors are 15% viscose and 85% cotton</li><li>Fabric weight: 5.3 oz/yd&sup2; (180 g/m&sup2;)</li><li>Relaxed fit</li><li>Blank product sourced from Bangladesh</li></ul>';
		$m    = PMH_Importer::materials_from_text( $html );
		self::assertSame( '100% combed cotton', $m['material_solid'] );
		self::assertSame( '5.3 oz/yd² (180 g/m²)', $m['fabric_weight'], 'entities decoded' );
		self::assertSame( 'Relaxed fit', $m['construction'] );
	}

	public function test_materials_split_survives_invalid_utf8(): void {
		$latin1 = str_replace( '²', "\xB2", self::CROP ); // Windows-1252 superscript two
		self::assertFalse( preg_match( '//u', $latin1 ), 'fixture really is invalid UTF-8' );
		$m = PMH_Importer::materials_from_text( $latin1 );
		self::assertSame( '100% combed cotton', $m['material_solid'] );
		self::assertSame( '5.3 oz/yd² (180 g/m²)', $m['fabric_weight'] );
	}

	public function test_materials_split_disclaimers_heading_without_markers(): void {
		$m = PMH_Importer::materials_from_text( "100% cotton\nTubular fabric\nDisclaimers:\nWhite may look off-white\nSpeckles are expected on Natural\n" );
		self::assertSame( 'Tubular fabric', $m['construction'] );
		self::assertSame( "White may look off-white\nSpeckles are expected on Natural", $m['disclaimers'] );
	}
}
