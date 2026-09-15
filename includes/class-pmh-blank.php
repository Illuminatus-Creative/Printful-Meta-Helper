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
	public const META_DISCLAIMERS  = '_pmh_disclaimers';
	public const META_FIT_LABEL    = '_pmh_fit_label';
	public const META_LINK_TEXT    = '_pmh_link_text';
	public const META_HANDLING_MIN = '_pmh_handling_min';
	public const META_HANDLING_MAX = '_pmh_handling_max';
	public const META_CHART        = '_pmh_size_chart';
	public const META_BODY_CHART   = '_pmh_body_chart';

	public const KINDS = array( 'apparel', 'accessory', 'digital' );

	/**
	 * Free-text fields and how they are sanitised: 'text' is a single line,
	 * 'lines' keeps one entry per line. The blank form, the save layer and
	 * the materials paste all iterate this list.
	 */
	public const TEXT_FIELDS = array(
		'material_solid'      => 'text',
		'material_exceptions' => 'lines',
		'fabric_weight'       => 'text',
		'construction'        => 'lines',
		'care'                => 'lines',
		'disclaimers'         => 'lines',
		'fit_label'           => 'text',
		'link_text'           => 'text',
	);

	/** The subset the materials paste can fill. */
	public const PASTE_FIELDS = array( 'material_solid', 'material_exceptions', 'fabric_weight', 'construction', 'disclaimers' );

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
		'disclaimers'         => self::META_DISCLAIMERS,
		'fit_label'           => self::META_FIT_LABEL,
		'link_text'           => self::META_LINK_TEXT,
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
			'disclaimers'         => '',
			'fit_label'           => '',
			'link_text'           => '',
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
	 * Every blank, by name.
	 *
	 * @return WP_Term[]
	 */
	public static function all(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => PMH_TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		return is_wp_error( $terms ) ? array() : array_values( $terms );
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
		return array_values(
			array_filter(
				self::all(),
				static function ( WP_Term $term ) use ( $cat_ids ) {
					$cats = self::get( $term->term_id )['cats'];
					return ! $cats || array_intersect( $cats, $cat_ids );
				}
			)
		);
	}
}
