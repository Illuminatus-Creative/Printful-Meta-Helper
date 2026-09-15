<?php
/**
 * Companion products: a unisex product and its women's-sizing twin (or
 * the reverse), linked one to one and two ways. The relationship is
 * product meta; the wording comes from each product's blank, so the
 * shortcode on a unisex product prints the unisex blank's fit label and
 * the women's blank's link text, and the reverse falls out automatically.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Companion {

	public const META  = '_pmh_companion';
	private const FIELD = 'pmh_companion';
	private const NONCE = 'pmh_companion_nonce';

	public static function init(): void {
		add_action( 'save_post_product', array( __CLASS__, 'save' ), 20, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/** The companion product ID, or 0. */
	public static function get( int $product_id ): int {
		return absint( get_post_meta( $product_id, self::META, true ) );
	}

	/**
	 * Point $product_id at $companion_id (0 to clear), keeping every link
	 * one to one and two ways.
	 *
	 * @return array{cleared: int[]} Products whose previous link was removed.
	 */
	public static function set( int $product_id, int $companion_id ): array {
		$cleared = array();
		$current = self::get( $product_id );

		if ( $companion_id === $product_id || ( $companion_id && 'product' !== get_post_type( $companion_id ) ) ) {
			$companion_id = 0;
		}
		if ( $companion_id === $current ) {
			return compact( 'cleared' );
		}

		// The old partner no longer points back.
		if ( $current && self::get( $current ) === $product_id ) {
			delete_post_meta( $current, self::META );
		}

		if ( ! $companion_id ) {
			delete_post_meta( $product_id, self::META );
			return compact( 'cleared' );
		}

		// The new partner's previous partner is orphaned; clear it too.
		$previous = self::get( $companion_id );
		if ( $previous && $previous !== $product_id ) {
			delete_post_meta( $previous, self::META );
			$cleared[] = $previous;
		}

		update_post_meta( $product_id, self::META, $companion_id );
		update_post_meta( $companion_id, self::META, $product_id );

		return compact( 'cleared' );
	}

	/**
	 * save_post_product handler. Reads the metabox field when its nonce is
	 * present, so programmatic saves and quick edit leave the link alone.
	 *
	 * @param int     $post_id Product ID.
	 * @param WP_Post $post    Product.
	 */
	public static function save( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$companion = isset( $_POST[ self::FIELD ] ) ? absint( wp_unslash( $_POST[ self::FIELD ] ) ) : 0;
		$result    = self::set( $post_id, $companion );

		if ( $result['cleared'] ) {
			$names = array_map( static fn( int $id ): string => get_the_title( $id ) ?: '#' . $id, $result['cleared'] );
			PMH_Notices::set(
				'companion',
				array(
					sprintf(
						/* translators: %s: product title(s) */
						__( 'Companion link moved: %s no longer has a companion.', 'printful-meta-helper' ),
						implode( ', ', $names )
					),
				),
				array()
			);
		}
	}

	/** Permanent deletion clears the other side. */
	public static function on_delete( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$partner = self::get( $post_id );
		if ( $partner && self::get( $partner ) === $post_id ) {
			delete_post_meta( $partner, self::META );
		}
	}

	public static function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== ( $screen->post_type ?? '' ) ) {
			return;
		}
		PMH_Notices::render( PMH_Notices::take( 'companion' ) );
	}

	/**
	 * The field inside the Blank metabox: WooCommerce's product search.
	 */
	public static function render_field( int $product_id ): string {
		$companion = self::get( $product_id );
		$html      = wp_nonce_field( self::NONCE, self::NONCE, true, false );
		$html     .= '<p class="pmh-assign__label pmh-companion-field"><label for="pmh_companion">' . esc_html__( 'Companion product', 'printful-meta-helper' ) . '</label> ' . PMH_Admin_Help::tip( 'companion' ) . '</p>';
		$html     .= sprintf(
			'<select class="wc-product-search" style="width:100%%" id="pmh_companion" name="%1$s" data-placeholder="%2$s" data-action="woocommerce_json_search_products" data-exclude="%3$d" data-allow_clear="true">',
			esc_attr( self::FIELD ),
			esc_attr__( 'Search for a product…', 'printful-meta-helper' ),
			$product_id
		);
		if ( $companion ) {
			$html .= sprintf( '<option value="%1$d" selected="selected">%2$s</option>', $companion, esc_html( get_the_title( $companion ) ?: '#' . $companion ) );
		}
		$html .= '</select>';
		$html .= '<p class="description">' . esc_html__( 'The unisex or women\'s twin of this product. Saving links both products; clear it to unlink both. [pmh_companion_link] renders the sentence.', 'printful-meta-helper' ) . '</p>';
		return $html;
	}

	/**
	 * The link for a product, or '' when there is nothing to show.
	 *
	 * <span class="pmh-companion pmh-companion--{blank-slug}">
	 *   <span class="pmh-companion__fit">Unisex sizing.</span>
	 *   <a class="pmh-companion__link" href="…"><em>Looking for women’s sizes?</em></a>
	 * </span>
	 *
	 * Inline, so the surrounding text block decides the paragraph styling.
	 */
	public static function link_html( int $product_id, string $extra_class = '' ): string {
		$companion = self::get( $product_id );
		if ( ! $companion || 'publish' !== get_post_status( $companion ) ) {
			return '';
		}
		$target_blank = PMH_Blank::for_product( $companion );
		if ( ! $target_blank ) {
			return '';
		}
		$link_text = PMH_Blank::get( $target_blank->term_id )['link_text'];
		if ( '' === $link_text ) {
			return '';
		}
		$url = get_permalink( $companion );
		if ( ! $url ) {
			return '';
		}

		$own_blank = PMH_Blank::for_product( $product_id );
		$fit_label = $own_blank ? PMH_Blank::get( $own_blank->term_id )['fit_label'] : '';

		$classes = array( 'pmh-companion' );
		if ( $own_blank ) {
			$classes[] = 'pmh-companion--' . $own_blank->slug;
		}
		$classes = array_merge( $classes, PMH_Util::extra_classes( $extra_class ) );

		$html = '<span class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		if ( '' !== $fit_label ) {
			$html .= '<span class="pmh-companion__fit">' . esc_html( $fit_label ) . '</span> ';
		}
		$html .= '<a class="pmh-companion__link" href="' . esc_url( $url ) . '"><em>' . esc_html( $link_text ) . '</em></a></span>';

		/**
		 * Filter the finished companion link HTML.
		 *
		 * @param string $html      Markup.
		 * @param int    $product_id Product shown.
		 * @param int    $companion  Product linked to.
		 */
		return (string) apply_filters( 'pmh_companion_link_html', $html, $product_id, $companion );
	}
}
