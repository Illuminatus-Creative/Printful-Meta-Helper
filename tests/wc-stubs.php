<?php
/**
 * Just enough WooCommerce for PMH_Sizes and the shortcodes to run.
 */

final class PMH_Fake_WC {
	/** @var array<int, PMH_Fake_Variable> */
	public static array $products = array();
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

function wc_get_product( $id ) {
	PMH_Fake_WP::count( 'wc_get_product' );
	return PMH_Fake_WC::$products[ (int) $id ] ?? false;
}
function wc_attribute_label( $name, $product = null ) { return ucfirst( str_replace( 'pa_', '', $name ) ); }
function wc_variation_attribute_name( $name ) { return 'attribute_' . sanitize_title( $name ); }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) { return array_merge( $pairs, (array) $atts ); }
function get_the_ID() { return 1; }
function is_singular( $t = '' ) { return false; }
function has_term() { return false; }
function wp_register_style() {}
function wp_register_script() {}
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function get_queried_object_id() { return 1; }
