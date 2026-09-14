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
	}

	public function test_materials_split_ignores_prose_only(): void {
		$m = PMH_Importer::materials_from_text( "Just a paragraph with 100% cotton in it.\n" );
		self::assertSame( '', $m['material_solid'] );
		self::assertSame( '', $m['construction'] );
	}
}
