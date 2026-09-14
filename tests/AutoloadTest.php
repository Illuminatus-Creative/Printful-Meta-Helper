<?php

use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase {

	/** The plugin's autoloader, loaded without running the bootstrap hooks. */
	private static function autoloader(): callable {
		static $fn = null;
		if ( null === $fn ) {
			$src = (string) file_get_contents( PMH_DIR . 'printful-meta-helper.php' );
			preg_match( '/function pmh_autoload\( string \$class \): void \{.*?\n\}/s', $src, $m );
			self::assertNotEmpty( $m, 'autoloader found in plugin file' );
			$fn = eval( 'return ' . str_replace( 'function pmh_autoload', 'function', $m[0] ) . ';' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test isolates the real function body.
		}
		return $fn;
	}

	public function test_only_plain_pmh_class_names_reach_the_filesystem(): void {
		$loader = self::autoloader();
		foreach ( array( 'PMH_Evil\\..\\..\\x', 'PMH_../../x', 'Other_Class', 'PMH_', 'pmh_size_chart', "PMH_A\0B" ) as $bad ) {
			$loader( $bad );
			self::assertFalse( class_exists( $bad, false ), $bad );
		}
	}

	public function test_a_real_class_name_maps_to_its_file(): void {
		$loader = self::autoloader();
		$loader( 'PMH_Util' );
		self::assertTrue( class_exists( 'PMH_Util', false ) );
	}
}
