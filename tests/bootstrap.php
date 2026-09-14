<?php
/**
 * Test bootstrap. Tests cover the pure classes (size chart, importer, size
 * filter logic, renderer markup) without loading WordPress. The handful of
 * WordPress functions the renderer calls are stubbed below with behaviour
 * close enough to core for markup assertions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'PMH_TAXONOMY' ) ) {
	define( 'PMH_TAXONOMY', 'pmh_blank' );
}

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
	}
	function esc_html__( $text, $domain = null ) {
		return esc_html( $text );
	}
	function esc_attr__( $text, $domain = null ) {
		return esc_attr( $text );
	}
	function __( $text, $domain = null ) {
		return $text;
	}
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
	function apply_filters( $hook, $value ) {
		return $value;
	}
	function add_action() {}
	function add_shortcode() {}
	function sanitize_html_class( $class ) {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id = 0;
		public string $name = '';
		public string $slug = '';
	}
}

require_once __DIR__ . '/../includes/class-pmh-size-chart.php';
require_once __DIR__ . '/../includes/class-pmh-importer.php';
require_once __DIR__ . '/../includes/class-pmh-sizes.php';
require_once __DIR__ . '/../includes/class-pmh-renderer.php';

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
