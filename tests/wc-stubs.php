<?php
/**
 * Just enough WooCommerce for PMH_Sizes and the shortcodes to run.
 */

final class PMH_Fake_WC {
	/** @var array<int, PMH_Fake_Variable> */
	public static array $products = array();
	public static string $singular = '';  // post type of the queried singular, '' when not singular
	public static int $queried_id  = 1;
}

class WC_Product {
	public function __construct( protected int $id ) {}
	public function get_id(): int { return $this->id; }
	public function is_type( $type ): bool { return 'variable' === $type; }
	public function get_parent_id(): int { return 0; }
}

class WC_Product_Attribute {
	public function __construct( private string $name, private bool $variation ) {}
	public function get_name(): string { return $this->name; }
	public function get_variation(): bool { return $this->variation; }
	public function is_taxonomy(): bool { return 0 === strpos( $this->name, 'pa_' ); }
}

final class PMH_Fake_Attribute extends WC_Product_Attribute {}

final class PMH_Fake_Variation extends WC_Product {
	public function __construct( int $id, private int $parent ) {
		parent::__construct( $id );
	}
	public function is_type( $type ): bool { return 'variation' === $type; }
	public function get_parent_id(): int { return $this->parent; }
}

final class PMH_Fake_Variable extends WC_Product {
	/** @param int[] $children */
	public function __construct( int $id, private array $children, private array $attributes ) {
		parent::__construct( $id );
	}
	public function get_attributes(): array { return $this->attributes; }
	public function get_visible_children(): array {
		PMH_Fake_WP::count( 'get_visible_children' );
		return $this->children;
	}
}

/** Mirrors WC_Cache_Helper's per-group prefix that product saves bump. */
final class PMH_Fake_Cache_Helper {
	public static array $prefixes = array();
	public static function get_cache_prefix( string $group ): string {
		return 'wc_cache_' . ( self::$prefixes[ $group ] ?? '0' ) . '_';
	}
	public static function invalidate_cache_group( string $group ): void {
		self::$prefixes[ $group ] = (string) ( ( (int) ( self::$prefixes[ $group ] ?? 0 ) ) + 1 );
	}
}
class_alias( 'PMH_Fake_Cache_Helper', 'WC_Cache_Helper' );

function wc_get_product( $id ) {
	PMH_Fake_WP::count( 'wc_get_product' );
	return PMH_Fake_WC::$products[ (int) $id ] ?? false;
}
function wc_attribute_label( $name, $product = null ) { return ucfirst( str_replace( 'pa_', '', $name ) ); }
function wc_variation_attribute_name( $name ) { return 'attribute_' . sanitize_title( $name ); }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) { return array_merge( $pairs, (array) $atts ); }
function get_the_ID() { return PMH_Fake_WC::$queried_id; }
function is_singular( $t = '' ) { return PMH_Fake_WC::$singular === $t; }
function has_term( $term, $taxonomy, $object_id = null ) {
	$ids = PMH_Fake_WP::$object_terms[ (int) $object_id ][ $taxonomy ] ?? array();
	return '' === $term ? ! empty( $ids ) : in_array( $term, $ids, true );
}
function wp_register_style() {}
function wp_register_script() {}
function wp_enqueue_style( $handle = '' ) { PMH_Fake_WP::$enqueued[] = $handle; }
function wp_enqueue_script( $handle = '' ) { PMH_Fake_WP::$enqueued[] = $handle; }
function get_queried_object_id() { return PMH_Fake_WC::$queried_id; }
