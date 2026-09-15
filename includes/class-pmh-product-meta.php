<?php
/**
 * Product edit screen: the blank selector.
 *
 * Replaces the default checklist with a single select. Options are filtered
 * client-side to blanks whose "applies to" categories intersect the
 * product's currently ticked categories; "Show all blanks" bypasses that.
 * A preview panel shows the chosen blank's material, weight, the sizes that
 * will render given the product's saved variations, and whether the blank
 * matches the product's own Printful chart when the product carries one.
 *
 * Nothing here talks to the server after page load. Assets and the inline
 * data are enqueued by PMH_Admin_Assets.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Product_Meta {

	/**
	 * Taxonomy meta_box_cb. Signature fixed by core: ( $post, $box ).
	 */
	public static function render_metabox( $post ): void {
		$product_id = (int) $post->ID;
		$current    = PMH_Blank::for_product( $product_id );
		$current_id = $current ? (int) $current->term_id : 0;

		$blanks = PMH_Blank::all();

		echo '<div class="pmh-assign" id="pmh-assign">';
		// Hidden 0 so choosing "No blank" clears the term (core's category box does the same).
		echo '<input type="hidden" name="tax_input[' . esc_attr( PMH_TAXONOMY ) . '][]" value="0">';
		echo '<p class="pmh-assign__label"><label for="pmh_blank_select">' . esc_html__( 'Blank', 'printful-meta-helper' ) . '</label> ' . PMH_Admin_Help::tip( 'product_select' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tip() escapes.
		echo '<select name="tax_input[' . esc_attr( PMH_TAXONOMY ) . '][]" id="pmh_blank_select" class="widefat">';
		echo '<option value="">' . esc_html__( '— No blank —', 'printful-meta-helper' ) . '</option>';
		foreach ( $blanks as $blank ) {
			$data = PMH_Blank::get( $blank->term_id );
			printf(
				'<option value="%1$d" data-cats="%2$s" data-kind="%3$s"%4$s>%5$s</option>',
				(int) $blank->term_id,
				esc_attr( implode( ',', $data['cats'] ) ),
				esc_attr( $data['kind'] ),
				selected( $current_id, (int) $blank->term_id, false ),
				esc_html( $blank->name )
			);
		}
		echo '</select>';
		echo '<p class="pmh-assign__showall"><label><input type="checkbox" id="pmh_show_all"> ' . esc_html__( 'Show all blanks', 'printful-meta-helper' ) . '</label> ' . PMH_Admin_Help::tip( 'product_show_all' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tip() escapes.
		echo '<p class="pmh-assign__previewlabel">' . esc_html__( 'Preview', 'printful-meta-helper' ) . ' ' . PMH_Admin_Help::tip( 'product_preview' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tip() escapes.
		echo '<div class="pmh-assign__preview" id="pmh_blank_preview" aria-live="polite"></div>';
		if ( ! $blanks ) {
			echo '<p class="description">' . esc_html__( 'No blanks exist yet. Create one under Products → Blanks.', 'printful-meta-helper' ) . '</p>';
		}
		echo PMH_Companion::render_field( $product_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
		printf(
			'<p class="pmh-assign__manage"><a href="%s">%s</a></p>',
			esc_url( admin_url( 'edit-tags.php?taxonomy=' . PMH_TAXONOMY . '&post_type=product' ) ),
			esc_html__( 'Manage blanks', 'printful-meta-helper' )
		);
		echo '</div>';
	}

	/**
	 * Everything the preview needs, computed once at page load.
	 */
	public static function inline_data( int $product_id ): array {
		$blanks = array();
		foreach ( PMH_Blank::all() as $term ) {
			$data                      = PMH_Blank::get( $term->term_id );
			$blanks[ $term->term_id ] = array(
				'name'      => $term->name,
				'kind'      => $data['kind'],
				'cats'      => $data['cats'],
				'material'  => $data['material_solid'],
				'weight'    => $data['fabric_weight'],
				'sizes'     => $data['chart']['sizes'],
				'signature' => PMH_Size_Chart::signature( $data['chart'] ),
			);
		}

		$sizes     = $product_id ? PMH_Sizes::for_product( $product_id ) : array(
			'state' => PMH_Sizes::UNFILTERED,
			'sizes' => array(),
		);
		$printful  = $product_id ? PMH_Importer::from_product_meta( $product_id ) : null;
		$signature = $printful && $printful['product'] ? PMH_Size_Chart::signature( $printful['product'] ) : '';

		return array(
			'blanks'  => $blanks,
			'product' => array(
				'sizesState'       => $sizes['state'],
				'sizes'            => $sizes['sizes'],
				'hasPrintfulChart' => '' !== $signature,
				'signature'        => $signature,
				'printfulSizes'    => $printful && $printful['product'] ? $printful['product']['sizes'] : array(),
			),
			'i18n'    => array(
				'noBlank'        => __( 'No blank assigned. The size chart and materials shortcodes will output nothing.', 'printful-meta-helper' ),
				'notApparel'     => __( 'Not apparel: no size chart renders.', 'printful-meta-helper' ),
				'noChart'        => __( 'This blank has no size chart yet.', 'printful-meta-helper' ),
				'sizesAll'       => __( 'Sizes that will render (product has no size attribute, full chart):', 'printful-meta-helper' ),
				'sizesFiltered'  => __( 'Sizes that will render (from saved variations):', 'printful-meta-helper' ),
				'sizesNone'      => __( 'The product has a size attribute but no visible variation carries a size. Nothing renders.', 'printful-meta-helper' ),
				'sizesNoOverlap' => __( 'None of the product’s sizes match this chart. Nothing renders. Product sizes:', 'printful-meta-helper' ),
				'notForCats'     => __( 'This blank is not offered for the product’s current categories.', 'printful-meta-helper' ),
				'match'          => __( 'Matches the product’s own Printful size chart.', 'printful-meta-helper' ),
				'mismatch'       => __( 'Differs from the product’s own Printful size chart. Either the wrong blank, or Printful has updated the spec since this blank was entered.', 'printful-meta-helper' ),
				'suggest'        => __( 'Blanks matching this product’s Printful chart:', 'printful-meta-helper' ),
				'suggestNone'    => __( 'No blank matches this product’s Printful chart.', 'printful-meta-helper' ),
				'material'       => __( 'Material', 'printful-meta-helper' ),
				'weight'         => __( 'Weight', 'printful-meta-helper' ),
			),
		);
	}
}
