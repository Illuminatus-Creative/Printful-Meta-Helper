<?php
/**
 * A small in-memory WordPress for unit tests. Only what the plugin calls,
 * with behaviour close enough to core for the logic under test. Reset
 * between tests with PMH_Fake_WP::reset().
 */

final class PMH_Fake_WP {
	public static array $posts        = array(); // id => [post_type, post_parent, title, status, sku]
	public static array $post_meta    = array(); // id => [key => value]
	public static array $terms        = array(); // term_id => WP_Term
	public static array $term_meta    = array(); // term_id => [key => value]
	public static array $object_terms = array(); // object_id => [taxonomy => [term_id, ...]]
	public static array $transients   = array();
	public static array $primed_meta  = array(); // post ids whose meta is in the object cache
	public static array $object_cache = array(); // group => key => value
	public static array $options      = array();
	/** @var PMH_Fake_Screen|null returned by get_current_screen() */
	public static $screen             = null;
	public static array $enqueued     = array(); // handles passed to wp_enqueue_*
	public static array $inline       = array(); // handle => inline script
	public static bool $is_admin      = false;
	public static array $query_vars   = array(); // $_GET for the list-table tests
	public static array $set_calls    = array(); // wp_set_object_terms() log
	public static bool $can           = true;
	/** @var callable|null fn( string $cap, array $args ): bool — overrides $can when set. */
	public static $can_callback   = null;
	public static bool $nonce_ok      = true;
	public static int $next_term_id   = 100;
	/** Calls that hit the database or object cache on a real site. */
	public static array $calls        = array();

	public static function count( string $fn ): void {
		self::$calls[ $fn ] = ( self::$calls[ $fn ] ?? 0 ) + 1;
	}

	public static function reset(): void {
		self::$posts        = array();
		self::$post_meta    = array();
		self::$terms        = array();
		self::$term_meta    = array();
		self::$object_terms = array();
		self::$transients   = array();
		self::$primed_meta  = array();
		self::$object_cache = array();
		self::$options      = array();
		self::$screen       = null;
		self::$enqueued     = array();
		self::$inline       = array();
		self::$is_admin     = false;
		$_GET               = array();
		PMH_Fake_Cache_Helper::$prefixes = array();
		PMH_Fake_WC::$singular   = '';
		PMH_Fake_WC::$queried_id = 1;
		self::$set_calls    = array();
		self::$can          = true;
		self::$can_callback = null;
		self::$nonce_ok     = true;
		self::$next_term_id = 100;
		self::$calls        = array();
	}

	public static function add_term( string $taxonomy, string $name, ?int $id = null, ?string $slug = null ): WP_Term {
		$term                   = new WP_Term();
		$term->term_id          = $id ?? self::$next_term_id++;
		$term->term_taxonomy_id = $term->term_id + 1000;
		$term->name             = $name;
		$term->slug             = $slug ?? strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) );
		$term->taxonomy         = $taxonomy;
		self::$terms[ $term->term_id ] = $term;
		return $term;
	}

	public static function add_post( int $id, string $type = 'product', array $extra = array() ): void {
		self::$posts[ $id ] = array_merge(
			array(
				'post_type'   => $type,
				'post_parent' => 0,
				'title'       => 'Product ' . $id,
				'status'      => 'publish',
				'sku'         => '',
			),
			$extra
		);
	}
}

class WP_Term {
	public int $term_id = 0;
	public int $term_taxonomy_id = 0;
	public string $name = '';
	public string $slug = '';
	public string $taxonomy = '';
	public int $parent = 0;
	public int $count = 0;
}

/** Enough of WP_Screen for help-tab registration. */
class PMH_Fake_Screen {
	public string $id = '';
	public string $base = '';
	public string $post_type = '';
	public string $taxonomy = '';
	public array $help_tabs = array();
	public function add_help_tab( array $args ): void {
		$this->help_tabs[] = $args;
	}
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
	public function get_error_message(): string {
		return $this->message;
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/* ---- escaping / i18n ---- */
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $t ) { return str_replace( '&', '&#038;', (string) $t ); }
function esc_html__( $t, $d = null ) { return esc_html( $t ); }
function esc_attr__( $t, $d = null ) { return esc_attr( $t ); }
function __( $t, $d = null ) { return $t; }
function _x( $t, $c, $d = null ) { return $t; }

/* ---- hooks (no-ops) ---- */
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function apply_filters( $hook, $value ) { return $value; }

/* ---- sanitising ---- */
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $s ); }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9_]+/i', '-', (string) $s ), '-' ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function checked( $a, $b = true, $echo = true ) { return $a == $b ? ' checked="checked"' : ''; }
function selected( $a, $b = true, $echo = true ) { return $a == $b ? ' selected="selected"' : ''; }

/* ---- auth ---- */
function wp_verify_nonce( $n, $a ) { return PMH_Fake_WP::$nonce_ok; }
function current_user_can( $cap, ...$args ) { return PMH_Fake_WP::$can_callback ? (bool) call_user_func( PMH_Fake_WP::$can_callback, $cap, $args ) : PMH_Fake_WP::$can; }
function get_current_user_id() { return 1; }
function wp_nonce_field( $a, $n = '_wpnonce', $referer = true, $echo = true ) {
	$html = '<input type="hidden" name="' . $n . '" value="nonce">';
	if ( $echo ) {
		echo $html;
	}
	return $html;
}
function get_current_screen() { return PMH_Fake_WP::$screen; }
function is_admin() { return PMH_Fake_WP::$is_admin; }
function submit_button( $text = '' ) { echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( $text ) . '</button></p>'; }
function get_edit_post_link( $id ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function wp_die( $msg = '' ) { throw new RuntimeException( 'wp_die: ' . $msg ); }
function wp_add_inline_script( $handle, $code, $pos = 'after' ) { PMH_Fake_WP::$inline[ $handle ] = $code; return true; }

/** Enough of WP_Query for pre_get_posts handlers. */
final class PMH_Fake_Query {
	public array $vars = array();
	public bool $main = true;
	public function get( $k, $default = '' ) { return $this->vars[ $k ] ?? $default; }
	public function set( $k, $v ) { $this->vars[ $k ] = $v; }
	public function is_main_query() { return $this->main; }
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function wp_count_terms( $args = array() ) {
	$n = 0;
	foreach ( PMH_Fake_WP::$terms as $t ) {
		if ( $t->taxonomy === ( $args['taxonomy'] ?? '' ) ) {
			$n++;
		}
	}
	return $n;
}
function wp_kses_post( $html ) {
	return preg_replace( '#<(script|style|iframe)[^>]*>.*?</\1>#is', '', (string) $html );
}

/* ---- object cache / options ---- */
function wp_cache_get( $key, $group = '' ) { PMH_Fake_WP::count( 'wp_cache_get' ); return PMH_Fake_WP::$object_cache[ $group ][ $key ] ?? false; }
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) { PMH_Fake_WP::count( 'wp_cache_set' ); PMH_Fake_WP::$object_cache[ $group ][ $key ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { unset( PMH_Fake_WP::$object_cache[ $group ][ $key ] ); return true; }
function get_option( $name, $default = false ) { return PMH_Fake_WP::$options[ $name ] ?? $default; }

/* ---- transients ---- */
function set_transient( $k, $v, $ttl = 0 ) { PMH_Fake_WP::$transients[ $k ] = $v; return true; }
function get_transient( $k ) { return PMH_Fake_WP::$transients[ $k ] ?? false; }
function delete_transient( $k ) { unset( PMH_Fake_WP::$transients[ $k ] ); return true; }

/* ---- posts ---- */
function get_post_type( $id ) { PMH_Fake_WP::count( 'get_post_type' ); return PMH_Fake_WP::$posts[ (int) $id ]['post_type'] ?? false; }
function wp_get_post_parent_id( $id ) { return (int) ( PMH_Fake_WP::$posts[ (int) $id ]['post_parent'] ?? 0 ); }
function get_the_title( $id ) { PMH_Fake_WP::count( 'get_the_title' ); return PMH_Fake_WP::$posts[ (int) $id ]['title'] ?? ''; }
function update_meta_cache( $type, $ids ) {
	PMH_Fake_WP::count( 'update_meta_cache' );
	foreach ( (array) $ids as $id ) {
		PMH_Fake_WP::$primed_meta[ (int) $id ] = true;
	}
	return array();
}
function update_post_meta( $id, $key, $value ) { PMH_Fake_WP::$post_meta[ (int) $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( PMH_Fake_WP::$post_meta[ (int) $id ][ $key ] ); return true; }
function get_post_status( $id ) { return PMH_Fake_WP::$posts[ (int) $id ]['status'] ?? false; }
function get_permalink( $id ) { return isset( PMH_Fake_WP::$posts[ (int) $id ] ) ? 'https://example.test/shop/product-' . (int) $id . '/' : false; }
function get_post_meta( $id, $key = '', $single = false ) {
	// A miss is one SELECT in core; a hit is served from the object cache.
	PMH_Fake_WP::count( isset( PMH_Fake_WP::$primed_meta[ (int) $id ] ) ? 'get_post_meta_hit' : 'get_post_meta_miss' );
	PMH_Fake_WP::$primed_meta[ (int) $id ] = true;
	$v = PMH_Fake_WP::$post_meta[ (int) $id ][ $key ] ?? '';
	return $single ? $v : ( '' === $v ? array() : array( $v ) );
}
function wc_get_product_id_by_sku( $sku ) {
	foreach ( PMH_Fake_WP::$posts as $id => $p ) {
		if ( '' !== $p['sku'] && $p['sku'] === $sku ) {
			return $id;
		}
	}
	return 0;
}
function get_posts( $args ) { PMH_Fake_WP::count( 'get_posts' );
	$ids = array();
	foreach ( PMH_Fake_WP::$posts as $id => $p ) {
		if ( $p['post_type'] !== ( $args['post_type'] ?? 'post' ) ) {
			continue;
		}
		if ( ! empty( $args['meta_key'] ) && ! isset( PMH_Fake_WP::$post_meta[ $id ][ $args['meta_key'] ] ) ) {
			continue;
		}
		$ids[] = $id;
	}
	return $ids;
}
function wp_count_posts( $type ) {
	$o = (object) array( 'publish' => 0, 'draft' => 0, 'pending' => 0, 'private' => 0, 'future' => 0 );
	foreach ( PMH_Fake_WP::$posts as $p ) {
		if ( $p['post_type'] === $type ) {
			$o->{$p['status']} = ( $o->{$p['status']} ?? 0 ) + 1;
		}
	}
	return $o;
}
function _prime_post_caches() {}
function update_object_term_cache() {}

/* ---- terms ---- */
function taxonomy_exists( $t ) { return in_array( $t, array( 'pmh_blank', 'product_cat', 'pa_size' ), true ); }
function get_terms( $args ) { PMH_Fake_WP::count( 'get_terms' );
	$out = array();
	foreach ( PMH_Fake_WP::$terms as $term ) {
		if ( $term->taxonomy !== ( $args['taxonomy'] ?? '' ) ) {
			continue;
		}
		if ( ! empty( $args['include'] ) && ! in_array( $term->term_id, array_map( 'intval', (array) $args['include'] ), true ) ) {
			continue;
		}
		if ( ! empty( $args['slug'] ) && ! in_array( $term->slug, (array) $args['slug'], true ) ) {
			continue;
		}
		$out[] = $term;
	}
	usort( $out, static fn( $a, $b ) => strcmp( $a->name, $b->name ) );
	if ( ( $args['fields'] ?? '' ) === 'ids' ) {
		return array_map( static fn( $t ) => $t->term_id, $out );
	}
	return $out;
}
function get_term( $id, $tax = '' ) {
	$t = PMH_Fake_WP::$terms[ (int) $id ] ?? null;
	return $t && ( '' === $tax || $t->taxonomy === $tax ) ? $t : null;
}
function get_term_by( $field, $value, $tax ) { PMH_Fake_WP::count( 'get_term_by' );
	foreach ( PMH_Fake_WP::$terms as $t ) {
		if ( $t->taxonomy !== $tax ) {
			continue;
		}
		if ( ( 'slug' === $field && $t->slug === $value ) || ( 'name' === $field && $t->name === $value ) || ( 'term_taxonomy_id' === $field && $t->term_taxonomy_id === (int) $value ) || ( 'id' === $field && $t->term_id === (int) $value ) ) {
			return $t;
		}
	}
	return false;
}
function term_exists( $name, $tax ) {
	$t = get_term_by( 'name', (string) $name, $tax );
	return $t ? array( 'term_id' => $t->term_id ) : null;
}
function wp_insert_term( $name, $tax ) {
	if ( term_exists( $name, $tax ) ) {
		return new WP_Error( 'term_exists', 'A term with the name provided already exists.' );
	}
	$t = PMH_Fake_WP::add_term( $tax, $name );
	return array( 'term_id' => $t->term_id, 'term_taxonomy_id' => $t->term_taxonomy_id );
}
function get_term_meta( $id, $key, $single = false ) { PMH_Fake_WP::count( 'get_term_meta' ); return PMH_Fake_WP::$term_meta[ (int) $id ][ $key ] ?? ''; }
function update_term_meta( $id, $key, $value ) { PMH_Fake_WP::count( 'update_term_meta' ); PMH_Fake_WP::$term_meta[ (int) $id ][ $key ] = $value; return true; }
function delete_term_meta( $id, $key ) { PMH_Fake_WP::count( 'delete_term_meta' ); unset( PMH_Fake_WP::$term_meta[ (int) $id ][ $key ] ); return true; }
function get_the_terms( $object_id, $tax ) { PMH_Fake_WP::count( 'get_the_terms' );
	$ids = PMH_Fake_WP::$object_terms[ (int) $object_id ][ $tax ] ?? array();
	return $ids ? array_values( array_filter( array_map( static fn( $id ) => PMH_Fake_WP::$terms[ $id ] ?? null, $ids ) ) ) : false;
}
function wp_get_post_terms( $object_id, $tax, $args = array() ) { PMH_Fake_WP::count( 'wp_get_post_terms' );
	$terms = get_the_terms( $object_id, $tax ) ?: array();
	return ( $args['fields'] ?? '' ) === 'ids' ? array_map( static fn( $t ) => $t->term_id, $terms ) : $terms;
}
function wp_set_object_terms( $object_id, $terms, $tax, $append = false ) { PMH_Fake_WP::count( 'wp_set_object_terms' );
	$ids = array_values( array_filter( array_map( 'intval', (array) $terms ) ) );
	PMH_Fake_WP::$set_calls[] = array( (int) $object_id, $ids, $tax, (bool) $append );
	$current = $append ? ( PMH_Fake_WP::$object_terms[ (int) $object_id ][ $tax ] ?? array() ) : array();
	PMH_Fake_WP::$object_terms[ (int) $object_id ][ $tax ] = array_values( array_unique( array_merge( $current, $ids ) ) );
	return array_map( static fn( $id ) => $id + 1000, $ids );
}
