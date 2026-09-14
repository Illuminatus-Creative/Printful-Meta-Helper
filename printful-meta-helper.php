<?php
/**
 * Plugin Name:       Printful Meta Helper
 * Description:       INTERNAL USE ONLY — not for distribution. Defines reusable garment blanks (size charts, materials) and renders them into WooCommerce product pages via shortcode.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Illuminatus Creative
 * Text Domain:       printful-meta-helper
 * Update URI:        false
 */

defined( 'ABSPATH' ) || exit;

define( 'PMH_VERSION', '0.1.0' );
define( 'PMH_FILE', __FILE__ );
define( 'PMH_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMH_URL', plugin_dir_url( __FILE__ ) );
define( 'PMH_TAXONOMY', 'pmh_blank' );

/**
 * Declare WooCommerce feature compatibility.
 *
 * HPOS: the plugin never touches orders, so it is compatible.
 * Block product editor: the blank selector is a classic-editor metabox and
 * does not exist in the block editor, so declare incompatible so WooCommerce
 * warns before anyone enables it.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PMH_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', PMH_FILE, false );
		}
	}
);

/**
 * Bootstrap once all plugins are loaded so the WooCommerce check is reliable.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'pmh_woocommerce_missing_notice' );
			return;
		}

		require_once PMH_DIR . 'includes/class-pmh-taxonomy.php';

		PMH_Taxonomy::init();
	}
);

/**
 * Admin notice shown when WooCommerce is not active.
 */
function pmh_woocommerce_missing_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Printful Meta Helper requires WooCommerce to be installed and active.', 'printful-meta-helper' )
	);
}
