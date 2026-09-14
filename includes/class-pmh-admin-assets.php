<?php
/**
 * Every admin stylesheet and script the plugin loads, keyed by screen, so
 * screen detection lives in one place.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Admin_Assets {

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook_suffix ): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$on_blank_screen   = in_array( $hook_suffix, array( 'edit-tags.php', 'term.php' ), true ) && PMH_TAXONOMY === $screen->taxonomy;
		$on_product_screen = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) && 'product' === $screen->post_type;
		$on_groups_screen  = 'product_page_' . PMH_Grouping::SLUG === $hook_suffix;

		if ( ! $on_blank_screen && ! $on_product_screen && ! $on_groups_screen ) {
			return;
		}

		wp_enqueue_style( 'pmh-admin', PMH_URL . 'admin/css/admin.css', array(), PMH_VERSION );

		if ( $on_blank_screen ) {
			wp_enqueue_script( 'pmh-size-grid', PMH_URL . 'admin/js/size-grid.js', array(), PMH_VERSION, array( 'in_footer' => true ) );
			wp_add_inline_script( 'pmh-size-grid', 'window.pmhGridI18n = ' . wp_json_encode( self::grid_i18n() ) . ';', 'before' );
		}

		if ( $on_product_screen ) {
			$product_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_script( 'pmh-blank-filter', PMH_URL . 'admin/js/blank-filter.js', array(), PMH_VERSION, array( 'in_footer' => true ) );
			wp_add_inline_script( 'pmh-blank-filter', 'window.pmhAssign = ' . wp_json_encode( PMH_Product_Meta::inline_data( $product_id ) ) . ';', 'before' );
		}
	}

	private static function grid_i18n(): array {
		return array(
			'editJson'         => __( 'Edit as JSON', 'printful-meta-helper' ),
			'editGrid'         => __( 'Back to grid', 'printful-meta-helper' ),
			'badJson'          => __( 'The JSON could not be read. Fix it in the JSON view, or clear it and start the grid from scratch.', 'printful-meta-helper' ),
			'note'             => __( 'Note', 'printful-meta-helper' ),
			'notePlaceholder'  => __( 'Product measurements may vary by up to 2" (5 cm).', 'printful-meta-helper' ),
			'measurement'      => __( 'Measurement', 'printful-meta-helper' ),
			'labelPlaceholder' => __( 'Length', 'printful-meta-helper' ),
			'size'             => __( 'Size', 'printful-meta-helper' ),
			'sizeName'         => __( 'Size name', 'printful-meta-helper' ),
			'removeSize'       => __( 'Remove this size', 'printful-meta-helper' ),
			'removeRow'        => __( 'Remove this measurement', 'printful-meta-helper' ),
			'empty'            => __( 'No chart yet. Add a size and a measurement, or use one of the import boxes above.', 'printful-meta-helper' ),
		);
	}
}
