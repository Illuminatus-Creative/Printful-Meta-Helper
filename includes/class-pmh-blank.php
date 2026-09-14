<?php
/**
 * Read/write access to a blank's term meta. Every other class goes through
 * here so the meta keys and defaults live in one place.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Blank {

	public const META_CATS         = '_pmh_blank_cats';
	public const META_KIND         = '_pmh_blank_kind';
	public const META_MATERIAL     = '_pmh_material_solid';
	public const META_EXCEPTIONS   = '_pmh_material_exceptions';
	public const META_WEIGHT       = '_pmh_fabric_weight';
	public const META_CONSTRUCTION = '_pmh_construction';
	public const META_CARE         = '_pmh_care';
	public const META_HANDLING_MIN = '_pmh_handling_min';
	public const META_HANDLING_MAX = '_pmh_handling_max';
	public const META_CHART        = '_pmh_size_chart';
	public const META_BODY_CHART   = '_pmh_body_chart';

	public const KINDS = array( 'apparel', 'accessory', 'digital' );

	/**
	 * Field name => meta key. Field names are what forms and importers use.
	 */
	public const FIELDS = array(
		'cats'                => self::META_CATS,
		'kind'                => self::META_KIND,
		'material_solid'      => self::META_MATERIAL,
		'material_exceptions' => self::META_EXCEPTIONS,
		'fabric_weight'       => self::META_WEIGHT,
		'construction'        => self::META_CONSTRUCTION,
		'care'                => self::META_CARE,
		'handling_min'        => self::META_HANDLING_MIN,
		'handling_max'        => self::META_HANDLING_MAX,
		'chart'               => self::META_CHART,
		'body_chart'          => self::META_BODY_CHART,
	);

	public static function defaults(): array {
		return array(
			'cats'                => array(),
			'kind'                => 'apparel',
			'material_solid'      => '',
			'material_exceptions' => '',
			'fabric_weight'       => '',
			'construction'        => '',
			'care'                => '',
			'handling_min'        => '',
			'handling_max'        => '',
			'chart'               => PMH_Size_Chart::empty_chart(),
			'body_chart'          => PMH_Size_Chart::empty_chart(),
		);
	}

	/**
	 * All fields for a blank, with defaults applied.
	 */
	public static function get( int $term_id ): array {
		$data = self::defaults();
		if ( $term_id <= 0 ) {
			return $data;
		}
		foreach ( self::FIELDS as $field => $key ) {
			$value = get_term_meta( $term_id, $key, true );
			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			switch ( $field ) {
				case 'cats':
					$data[ $field ] = array_values( array_filter( array_map( 'absint', (array) $value ) ) );
					break;
				case 'kind':
					$data[ $field ] = in_array( $value, self::KINDS, true ) ? $value : 'apparel';
					break;
				case 'chart':
				case 'body_chart':
					$data[ $field ] = PMH_Size_Chart::normalise( $value );
					break;
				default:
					$data[ $field ] = (string) $value;
			}
		}
		return $data;
	}

	/**
	 * Write already-sanitised fields. Empty values delete the meta row.
	 *
	 * @param array $data Subset of the field names in self::FIELDS.
	 */
	public static function update( int $term_id, array $data ): void {
		foreach ( $data as $field => $value ) {
			if ( ! isset( self::FIELDS[ $field ] ) ) {
				continue;
			}
			$key   = self::FIELDS[ $field ];
			$empty = is_array( $value ) ? empty( $value ) || ( isset( $value['rows'] ) && PMH_Size_Chart::is_empty( $value ) ) : ( '' === (string) $value );
			if ( $empty ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}
	}

	/**
	 * The blank assigned to a product, or null.
	 */
	public static function for_product( int $product_id ): ?WP_Term {
		$terms = get_the_terms( $product_id, PMH_TAXONOMY );
		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}
		$term = reset( $terms );
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Blanks whose "applies to" categories intersect the given category IDs.
	 * A blank with no categories set applies everywhere.
	 *
	 * @param int[] $cat_ids product_cat term IDs.
	 * @return WP_Term[]
	 */
	public static function for_categories( array $cat_ids ): array {
		$cat_ids = array_map( 'absint', $cat_ids );
		$all     = get_terms(
			array(
				'taxonomy'   => PMH_TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $all ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$all,
				static function ( WP_Term $term ) use ( $cat_ids ) {
					$cats = self::get( $term->term_id )['cats'];
					return ! $cats || array_intersect( $cats, $cat_ids );
				}
			)
		);
	}
}
