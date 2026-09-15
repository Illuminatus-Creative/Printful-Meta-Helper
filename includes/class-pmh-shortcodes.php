<?php
/**
 * Shortcodes. Every one returns an empty string, never a message, when it
 * has nothing to show, so an otherwise-empty text block collapses.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Shortcodes {

	public static function init(): void {
		add_shortcode( 'pmh_size_chart', array( __CLASS__, 'size_chart' ) );
		add_shortcode( 'pmh_materials', array( __CLASS__, 'materials' ) );
		add_shortcode( 'pmh_blank_name', array( __CLASS__, 'blank_name' ) );
		add_shortcode( 'pmh_companion_link', array( __CLASS__, 'companion_link' ) );
	}

	/**
	 * [pmh_companion_link product_id="" class=""] — "Unisex sizing. Looking
	 * for women’s sizes?" linking to the companion product, or nothing.
	 */
	public static function companion_link( $atts ): string {
		$atts       = shortcode_atts( array( 'product_id' => 0, 'class' => '' ), $atts, 'pmh_companion_link' );
		$product_id = self::resolve_product_id( $atts['product_id'] );
		return $product_id ? PMH_Companion::link_html( $product_id, (string) $atts['class'] ) : '';
	}

	/**
	 * [pmh_materials product_id="" fields="material,weight,construction,care,disclaimers" labels="1" filter_colours="1" class=""]
	 *
	 * Colour exceptions are limited to the colours the product is sold in;
	 * filter_colours="0" shows every exception on the blank.
	 */
	public static function materials( $atts ): string {
		$atts = shortcode_atts(
			array(
				'product_id'     => 0,
				'fields'         => 'material,weight,construction,care,disclaimers',
				'labels'         => '1',
				'filter_colours' => '1',
				'class'          => '',
			),
			$atts,
			'pmh_materials'
		);

		$product_id = self::resolve_product_id( $atts['product_id'] );
		$blank      = $product_id ? PMH_Blank::for_product( $product_id ) : null;
		if ( ! $blank ) {
			return '';
		}

		PMH_Renderer::enqueue_assets();

		$colours = null;
		if ( PMH_Util::truthy( $atts['filter_colours'] ) ) {
			$found = PMH_Sizes::colours_for_product( $product_id );
			// No colour attribute: nothing to filter by, show every exception.
			$colours = PMH_Sizes::UNFILTERED === $found['state'] ? null : $found['colours'];
		}

		return PMH_Materials::materials(
			$blank,
			PMH_Blank::get( $blank->term_id ),
			array(
				'fields'  => preg_split( '/\s*,\s*/', strtolower( (string) $atts['fields'] ), -1, PREG_SPLIT_NO_EMPTY ),
				'labels'  => PMH_Util::truthy( $atts['labels'] ),
				'class'   => (string) $atts['class'],
				'colours' => $colours,
			)
		);
	}

	/**
	 * [pmh_blank_name product_id=""] — plain text for use inside a sentence.
	 */
	public static function blank_name( $atts ): string {
		$atts       = shortcode_atts( array( 'product_id' => 0 ), $atts, 'pmh_blank_name' );
		$product_id = self::resolve_product_id( $atts['product_id'] );
		$blank      = $product_id ? PMH_Blank::for_product( $product_id ) : null;
		return $blank ? esc_html( $blank->name ) : '';
	}

	/**
	 * [pmh_size_chart product_id="" unit="in" toggle="1" note="1" supplier="1" table="product" body_rows="Chest" class=""]
	 *
	 * body_rows appends rows from the body chart as extra columns of the
	 * garment table, so a buyer sees the chest range that fits next to the
	 * garment width. Default "Chest"; "" for none; "Chest,Waist" for more.
	 */
	public static function size_chart( $atts ): string {
		$atts = shortcode_atts(
			array(
				'product_id' => 0,
				'unit'       => 'in',
				'toggle'     => '1',
				'note'       => '1',
				'supplier'   => '1',
				'table'      => 'product',
				'body_rows'  => 'Chest',
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

		$sizes   = PMH_Sizes::for_product( $product_id );
		$applied = PMH_Sizes::apply( $chart, $sizes );
		if ( null === $applied['chart'] ) {
			return '';
		}

		$body_rows = array();
		$wanted    = preg_split( '/\s*,\s*/', (string) $atts['body_rows'], -1, PREG_SPLIT_NO_EMPTY );
		if ( 'product' === $table && $wanted && ! PMH_Size_Chart::is_empty( $data['body_chart'] ) ) {
			$body_rows = PMH_Size_Chart::pick_rows( $applied['chart'], $data['body_chart'], $wanted );
		}

		PMH_Renderer::enqueue_assets();

		return PMH_Renderer::size_chart(
			$blank,
			$applied['chart'],
			array(
				'unit'   => 'cm' === strtolower( (string) $atts['unit'] ) ? 'cm' : 'in',
				'toggle'   => PMH_Util::truthy( $atts['toggle'] ),
				'note'     => PMH_Util::truthy( $atts['note'] ),
				'supplier'  => PMH_Util::truthy( $atts['supplier'] ),
				'table'     => $table,
				'body_rows' => $body_rows,
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
		return $id ? PMH_Util::resolve_product_id( $id ) : 0;
	}
}
