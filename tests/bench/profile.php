<?php
/**
 * Profiles the plugin's request paths against the in-memory WordPress fake.
 *
 * Reports two things per scenario: wall time for the PHP work, and the
 * number of calls that are a database query or object-cache round trip on
 * a real site (post meta, term meta, term lookups). The fake has no cache
 * layer, so the counts are the cold-cache upper bound: every get_post_meta
 * on an unprimed post is one SELECT in core.
 *
 *   php tests/bench/profile.php [variations] [products] [blanks]
 */

declare( strict_types=1 );

require_once __DIR__ . '/../bootstrap.php';

$variations = (int) ( $argv[1] ?? 80 );
$products   = (int) ( $argv[2] ?? 106 );
$blanks     = (int) ( $argv[3] ?? 10 );
$json       = pmh_fixture( 'gildan-5000.json' );
$chart      = PMH_Importer::from_json( $json )['product'];
$sizes      = array( 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL' );

function bench( string $label, int $iterations, callable $fn ): void {
	PMH_Fake_WP::$calls = array();
	$start              = hrtime( true );
	for ( $i = 0; $i < $iterations; $i++ ) {
		$fn();
	}
	$ms    = ( hrtime( true ) - $start ) / 1e6 / $iterations;
	$calls = PMH_Fake_WP::$calls;
	ksort( $calls );
	$per = array();
	foreach ( $calls as $k => $v ) {
		$per[] = $k . '=' . (int) ( $v / $iterations );
	}
	printf( "%-46s %9.3f ms   %s\n", $label, $ms, implode( ' ', $per ) );
}

/* ---------- catalogue ---------- */
PMH_Fake_WP::reset();
for ( $b = 1; $b <= $blanks; $b++ ) {
	$term = PMH_Fake_WP::add_term( 'pmh_blank', 'Blank ' . $b );
	PMH_Blank::update( $term->term_id, array( 'chart' => $chart, 'material_solid' => '100% cotton', 'fabric_weight' => '5 oz', 'cats' => array( 5 ) ) );
}
$blank_id = 100; // first blank
PMH_Fake_WP::add_term( 'product_cat', 'Tees', 5 );
foreach ( $sizes as $i => $size ) {
	PMH_Fake_WP::add_term( 'pa_size', $size, 500 + $i, strtolower( $size ) );
}

// One variable product with $variations children (8 sizes x N colours).
$pid = 1;
PMH_Fake_WP::add_post( $pid );
PMH_Fake_WP::$post_meta[ $pid ][ PMH_Importer::PRODUCT_META_KEY ] = $json;
wp_set_object_terms( $pid, array( $blank_id ), 'pmh_blank' );
wp_set_object_terms( $pid, array( 5 ), 'product_cat' );
$children = array();
for ( $v = 0; $v < $variations; $v++ ) {
	$cid = 1000 + $v;
	PMH_Fake_WP::add_post( $cid, 'product_variation', array( 'post_parent' => $pid ) );
	PMH_Fake_WP::$post_meta[ $cid ]['attribute_pa_size'] = strtolower( $sizes[ $v % 8 ] );
	$children[] = $cid;
}
PMH_Fake_WC::$products[ $pid ] = new PMH_Fake_Variable( $pid, $children, array( new PMH_Fake_Attribute( 'pa_size', true ), new PMH_Fake_Attribute( 'pa_color', true ) ) );

// $products more products carrying the Printful meta, for the grouping scan.
for ( $p = 2; $p <= $products; $p++ ) {
	PMH_Fake_WP::add_post( $p );
	PMH_Fake_WP::$post_meta[ $p ][ PMH_Importer::PRODUCT_META_KEY ] = $json;
	wp_set_object_terms( $p, array( 5 ), 'product_cat' );
}
PMH_Fake_WP::$calls = array();

printf( "variations=%d products=%d blanks=%d\n\n", $variations, $products, $blanks );

/* ---------- front end: one product page ---------- */
bench( 'PMH_Sizes::for_product', 50, static fn() => PMH_Sizes::for_product( $pid ) );
bench( '[pmh_size_chart] full render', 50, static fn() => PMH_Shortcodes::size_chart( array() ) === '' ? null : null );
bench( '[pmh_materials] full render', 50, static fn() => PMH_Shortcodes::materials( array() ) );

/* ---------- admin: product edit screen ---------- */
bench( 'PMH_Product_Meta::inline_data', 20, static fn() => PMH_Product_Meta::inline_data( $pid ) );

/* ---------- admin: Blank Groups ---------- */
bench( 'PMH_Grouping::scan', 3, static fn() => PMH_Grouping::scan() );

/* ---------- pure hot spots ---------- */
bench( 'PMH_Importer::from_json (10 KB)', 300, static fn() => PMH_Importer::from_json( $json ) );
bench( 'json_decode alone (10 KB)', 300, static fn() => json_decode( $json, true ) );
bench( 'PMH_Size_Chart::normalise (8x3)', 3000, static fn() => PMH_Size_Chart::normalise( $chart ) );
bench( 'PMH_Size_Chart::signature (8x3)', 3000, static fn() => PMH_Size_Chart::signature( $chart ) );
bench( 'PMH_Renderer::size_chart (8x3)', 1000, static fn() => PMH_Renderer::size_chart( pmh_test_blank(), $chart ) );
