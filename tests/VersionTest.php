<?php

use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase {

	public function test_header_and_constant_agree_and_changelog_has_the_entry(): void {
		$main = (string) file_get_contents( PMH_DIR . 'printful-meta-helper.php' );
		self::assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $main, $h ), 'Version header present' );
		self::assertSame( 1, preg_match( "/define\( 'PMH_VERSION', '([^']+)' \);/", $main, $c ), 'PMH_VERSION constant present' );
		self::assertSame( $h[1], $c[1], 'header and constant move together' );
		self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $h[1] );
		self::assertStringContainsString( "\n## " . $h[1] . "\n", (string) file_get_contents( PMH_DIR . 'CHANGELOG.md' ), 'CHANGELOG has an entry for the current version' );
	}
}
