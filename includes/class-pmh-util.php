<?php
/**
 * Small helpers shared across classes. Pure PHP except resolve_product_id.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Util {

	/**
	 * Non-empty trimmed lines of a multi-line string.
	 *
	 * @return string[]
	 */
	public static function lines( string $text ): array {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/**
	 * Space-separated extra classes from a shortcode attribute, sanitised.
	 *
	 * @return string[]
	 */
	public static function extra_classes( string $attr ): array {
		$out = array();
		foreach ( preg_split( '/\s+/', $attr, -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			$class = sanitize_html_class( $class );
			if ( '' !== $class ) {
				$out[] = $class;
			}
		}
		return $out;
	}

	/**
	 * A product ID or SKU -> parent product ID, or 0 when it is not a product.
	 * Variations resolve to their parent.
	 */
	public static function resolve_product_id( $ref ): int {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return 0;
		}
		$id = 0;
		if ( ctype_digit( $ref ) ) {
			$id = (int) $ref;
		} elseif ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = (int) wc_get_product_id_by_sku( $ref );
		}
		if ( ! $id ) {
			return 0;
		}
		$type = get_post_type( $id );
		if ( 'product_variation' === $type ) {
			$id   = (int) wp_get_post_parent_id( $id );
			$type = get_post_type( $id );
		}
		return 'product' === $type ? $id : 0;
	}

	/**
	 * Shortcode-style truthiness: "0", "false", "no", "off" and "" are false.
	 */
	public static function truthy( $value ): bool {
		return ! in_array( strtolower( trim( (string) $value ) ), array( '0', 'false', 'no', 'off', '' ), true );
	}
}
