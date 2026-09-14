<?php
/**
 * Test bootstrap: PHPUnit autoload, the in-memory WordPress fake, and the
 * plugin's own class autoloading rule. No real WordPress is loaded.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'PMH_TAXONOMY', 'pmh_blank' );
define( 'PMH_DIR', dirname( __DIR__ ) . '/' );
define( 'PMH_URL', 'https://example.test/wp-content/plugins/printful-meta-helper/' );
define( 'PMH_VERSION', 'test' );

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wc-stubs.php';

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! preg_match( '/^PMH_[A-Za-z0-9_]+$/', $class ) ) {
			return;
		}
		$file = PMH_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
// PMH_Import_Exception lives in the importer file, not its own.
require_once PMH_DIR . 'includes/class-pmh-importer.php';

function pmh_fixture( string $name ): string {
	return (string) file_get_contents( __DIR__ . '/fixtures/' . $name );
}

function pmh_test_chart(): array {
	return PMH_Importer::from_json( pmh_fixture( 'gildan-5000.json' ) )['product'];
}

function pmh_test_blank(): WP_Term {
	$term          = new WP_Term();
	$term->term_id = 7;
	$term->name    = 'Gildan 5000';
	$term->slug    = 'gildan-5000';
	return $term;
}
