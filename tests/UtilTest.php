<?php

use PHPUnit\Framework\TestCase;

final class UtilTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
	}

	public function test_lines(): void {
		self::assertSame( array( 'a', 'b' ), PMH_Util::lines( " a \r\n\n\tb\n" ) );
		self::assertSame( array(), PMH_Util::lines( "\n \n" ) );
	}

	public function test_extra_classes(): void {
		self::assertSame( array( 'one', 'two', 'bad' ), PMH_Util::extra_classes( ' one  two <bad> ' ) );
		self::assertSame( array(), PMH_Util::extra_classes( '' ) );
	}

	public function test_truthy(): void {
		foreach ( array( '0', 'false', 'no', 'off', '', ' ' ) as $f ) {
			self::assertFalse( PMH_Util::truthy( $f ), $f );
		}
		foreach ( array( '1', 'yes', 'true', 'anything' ) as $t ) {
			self::assertTrue( PMH_Util::truthy( $t ), $t );
		}
	}

	public function test_resolve_product_id(): void {
		PMH_Fake_WP::add_post( 10, 'product', array( 'sku' => 'TEE-1' ) );
		PMH_Fake_WP::add_post( 11, 'product_variation', array( 'post_parent' => 10 ) );
		PMH_Fake_WP::add_post( 12, 'post' );

		self::assertSame( 10, PMH_Util::resolve_product_id( '10' ) );
		self::assertSame( 10, PMH_Util::resolve_product_id( 11 ), 'variation resolves to parent' );
		self::assertSame( 10, PMH_Util::resolve_product_id( 'TEE-1' ), 'SKU lookup' );
		self::assertSame( 0, PMH_Util::resolve_product_id( '12' ), 'not a product' );
		self::assertSame( 0, PMH_Util::resolve_product_id( '999' ) );
		self::assertSame( 0, PMH_Util::resolve_product_id( '' ) );
	}
}
