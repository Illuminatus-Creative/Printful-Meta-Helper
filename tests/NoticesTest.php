<?php

use PHPUnit\Framework\TestCase;

final class NoticesTest extends TestCase {

	protected function setUp(): void {
		PMH_Fake_WP::reset();
	}

	public function test_set_take_clears(): void {
		PMH_Notices::set( 'blank', array( 'ok' ), array( 'bad', 'bad' ) );
		$notice = PMH_Notices::take( 'blank' );
		self::assertSame( array( 'ok' ), $notice['messages'] );
		self::assertSame( array( 'bad' ), $notice['errors'], 'errors are de-duplicated' );
		self::assertNull( PMH_Notices::take( 'blank' ), 'taken once' );
	}

	public function test_nothing_stored_when_empty(): void {
		PMH_Notices::set( 'blank', array(), array() );
		self::assertSame( array(), PMH_Fake_WP::$transients );
	}

	public function test_scopes_are_separate(): void {
		PMH_Notices::set( 'blank', array( 'a' ), array() );
		self::assertNull( PMH_Notices::take( 'groups' ) );
	}

	public function test_render_escapes(): void {
		ob_start();
		PMH_Notices::render( array( 'messages' => array( '<b>x</b>' ), 'errors' => array() ) );
		$html = ob_get_clean();
		self::assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
		self::assertStringContainsString( 'notice-success is-dismissible', $html );
	}
}
