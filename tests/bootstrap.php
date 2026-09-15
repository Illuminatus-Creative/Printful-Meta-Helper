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

/**
 * A variable product in both fakes: parent $id with one variation per
 * entry of $sizes (child id => raw attribute meta value), on the given
 * attributes. Registers the pa_size terms S…5XL once.
 *
 * @param array<int, ?string> $sizes  child id => value; null: no meta row.
 * @param string              $attribute 'pa_size' (global) or a custom label.
 */
function pmh_fake_variable_product( int $id, array $sizes, string $attribute = 'pa_size', array $extra_attributes = array() ): void {
	static $terms_seeded = false;
	if ( ! $terms_seeded ) {
		foreach ( array( 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL' ) as $i => $size ) {
			PMH_Fake_WP::add_term( 'pa_size', $size, 500 + $i, strtolower( $size ) );
		}
		$terms_seeded = true;
	} elseif ( ! isset( PMH_Fake_WP::$terms[500] ) ) {
		foreach ( array( 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL' ) as $i => $size ) {
			PMH_Fake_WP::add_term( 'pa_size', $size, 500 + $i, strtolower( $size ) );
		}
	}
	if ( ! isset( PMH_Fake_WP::$posts[ $id ] ) ) {
		PMH_Fake_WP::add_post( $id );
	}
	$meta_key = 'attribute_' . sanitize_title( $attribute );
	foreach ( $sizes as $cid => $raw ) {
		PMH_Fake_WP::add_post( $cid, 'product_variation', array( 'post_parent' => $id ) );
		PMH_Fake_WC::$products[ $cid ] = new PMH_Fake_Variation( $cid, $id );
		if ( null !== $raw ) {
			PMH_Fake_WP::$post_meta[ $cid ][ $meta_key ] = $raw;
		}
	}
	$attributes = array_merge( array( new PMH_Fake_Attribute( $attribute, true ) ), $extra_attributes );
	PMH_Fake_WC::$products[ $id ] = new PMH_Fake_Variable( $id, array_keys( $sizes ), $attributes );
}

function pmh_test_blank(): WP_Term {
	$term          = new WP_Term();
	$term->term_id = 7;
	$term->name    = 'Gildan 5000';
	$term->slug    = 'gildan-5000';
	return $term;
}
