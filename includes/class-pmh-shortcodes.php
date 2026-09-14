<?php
/**
 * Shortcodes. Every one returns an empty string, never a message, when it
 * has nothing to show, so an otherwise-empty text block collapses.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Shortcodes {

	public static function init(): void {
		add_shortcode( 'pmh_size_chart', array( __CLASS__, 'size_chart' ) );
	}

	/**
	 * [pmh_size_chart product_id="" unit="in" toggle="1" note="1" table="product" class=""]
	 */
	public static function size_chart( $atts ): string {
		$atts = shortcode_atts(
			array(
				'product_id' => 0,
				'unit'       => 'in',
				'toggle'     => '1',
				'note'       => '1',
				'table'      => 'product',
				'class'      => '',
			),
			$atts,
			'pmh_size_chart'
		);

		$product_id = self::resolve_product_id( $atts['product_id'] );
		if ( ! $product_id ) {
			return '';
		}

		$blank = PMH_Blank::for_product( $product_id );
		if ( ! $blank ) {
			return '';
		}

		$data = PMH_Blank::get( $blank->term_id );
		if ( 'apparel' !== $data['kind'] ) {
			return '';
		}

		$table = 'body' === $atts['table'] ? 'body' : 'product';
		$chart = 'body' === $table ? $data['body_chart'] : $data['chart'];

		$applied = PMH_Sizes::apply( $chart, PMH_Sizes::for_product( $product_id ) );
		if ( null === $applied['chart'] ) {
			return '';
		}

		PMH_Renderer::enqueue_assets();

		return PMH_Renderer::size_chart(
			$blank,
			$applied['chart'],
			array(
				'unit'   => 'cm' === strtolower( (string) $atts['unit'] ) ? 'cm' : 'in',
				'toggle' => self::truthy( $atts['toggle'] ),
				'note'   => self::truthy( $atts['note'] ),
				'table'  => $table,
				'class'  => (string) $atts['class'],
			)
		);
	}

	/**
	 * Explicit attribute, else the product in the loop, else the queried
	 * post. Variations resolve to their parent.
	 */
	public static function resolve_product_id( $explicit ): int {
		$id = absint( $explicit );

		if ( ! $id ) {
			global $product;
			if ( $product instanceof WC_Product ) {
				$id = $product->get_id();
			}
		}
		if ( ! $id ) {
			$id = (int) get_the_ID();
		}
		if ( ! $id ) {
			return 0;
		}

		$post_type = get_post_type( $id );
		if ( 'product_variation' === $post_type ) {
			$id = (int) wp_get_post_parent_id( $id );
		} elseif ( 'product' !== $post_type ) {
			return 0;
		}
		return $id;
	}

	private static function truthy( $value ): bool {
		return ! in_array( strtolower( trim( (string) $value ) ), array( '0', 'false', 'no', 'off', '' ), true );
	}
}
