<?php
/**
 * Every piece of "how to use" text in the admin, in one place.
 *
 * Three layers, all WordPress or WooCommerce natives:
 *  - descriptions under fields (rendered by the screens themselves),
 *  - help tips: WooCommerce's "?" icon next to a label, via tip(),
 *  - help tabs: the Help pull-down at the top of a screen, plus an intro
 *    above the "Add new blank" form.
 * Nothing is available only in a tooltip: every tip is also covered by a
 * description or a help tab.
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Admin_Help {

	public static function init(): void {
		add_action( PMH_TAXONOMY . '_pre_add_form', array( __CLASS__, 'render_intro' ) );
		foreach ( array( 'load-edit-tags.php', 'load-term.php', 'load-post.php', 'load-post-new.php', 'load-product_page_' . PMH_Grouping::SLUG ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'register_help_tabs' ) );
		}
		// WooCommerce loads its admin styles and tooltip script only on its own
		// screens; opt ours in so the "?" icons look and behave the same.
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'woocommerce_screen_ids' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Strings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Help-tip text by key. Utilitarian: what the control does, what
	 * happens on save, and why it exists.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			// Blank screen: applies to / kind.
			'applies_to'          => __( 'Controls which blanks the product screen offers. A blank is listed when at least one of its categories is ticked on the product. Leave every box unticked to offer this blank on every product. On the product screen, "Show all blanks" bypasses this filter.', 'printful-meta-helper' ),
			'kind'                => __( 'Apparel renders a size chart. Accessory and Digital render materials only, so a mug or a download shows no chart and leaves no gap on the page.', 'printful-meta-helper' ),
			// Materials.
			'material_base'       => __( 'The garment\'s standard fabric as Printful lists it, for example "100% cotton". Rendered as the first line of [pmh_materials].', 'printful-meta-helper' ),
			'material_exceptions' => __( 'Colourways whose fabric differs from the base, one per line, for example "Sport Grey is 90% cotton, 10% polyester". On a product page only the lines naming a colour that product is sold in render, so a black-and-navy tee shows no heather line. Write the colour as Printful does so it matches the variation name.', 'printful-meta-helper' ),
			'fabric_weight'       => __( 'Free text, so a range such as "5.0–5.3 oz/yd² (170-180 g/m²)" survives as written.', 'printful-meta-helper' ),
			'construction'        => __( 'One feature per line: seams, taping, hem. Rendered as a list. A single line renders as plain text.', 'printful-meta-helper' ),
			'care'                => __( 'One washing instruction per line. Printful does not supply this, so it is optional; leave it empty and the row is omitted.', 'printful-meta-helper' ),
			'disclaimers'         => __( 'Printful\'s disclaimers for the garment, one per line. Rendered as the last row of [pmh_materials]. A line naming a colour next to the word "color" ("the White color variant") renders only on products sold in that colour; a general line renders everywhere. The paste box reads both of Printful\'s shapes: a "Disclaimers:" heading with lines under it, or an inline "Disclaimer: …" sentence.', 'printful-meta-helper' ),
			'materials_paste'     => __( 'Paste Printful\'s materials list, bullets optional. On save the first "%" line becomes the base material, further "%" lines colour exceptions, the "Fabric weight" line the weight, disclaimer lines disclaimers, and the rest construction. Intro prose and sourcing lines are dropped. The fields above are replaced and stay editable.', 'printful-meta-helper' ),
			// Size chart imports.
			'import_json'         => __( 'The size-guide JSON. On products Printful has pushed, it is stored in the product\'s Custom Fields under pf_advanced_size_chart; otherwise copy it from the Printful website. Only the inch rows are read, because the centimetre rows are exact conversions. Replaces both charts below.', 'printful-meta-helper' ),
			'import_product'      => __( 'Type a product ID or SKU and, on save, the chart is read from the size-guide JSON Printful stored on that product. Saves opening the Custom Fields panel to copy it. Products imported before Printful started storing the JSON have none and produce a notice.', 'printful-meta-helper' ),
			'import_text'         => __( 'Copy the size table straight off the Printful product page, header row included, and paste it here. Pick the unit the page was showing when you copied. Replaces the garment chart only.', 'printful-meta-helper' ),
			'chart'               => __( 'What [pmh_size_chart] renders. Columns are sizes, rows are measurements; a cell takes 28, 34-37 or 16 ½. Keep every size the garment comes in: each product shows only the sizes it has variations for. Inches only; centimetres are computed and values display to one decimal, as Printful shows them.', 'printful-meta-helper' ),
			'body_chart'          => __( 'Body measurements ("measure yourself"), imported alongside the garment chart. By default [pmh_size_chart] appends its Chest row to the garment table as an extra column, so buyers see the chest range that fits beside the garment width; body_rows="" removes it, body_rows="Chest,Waist" adds more. table="body" renders the whole body chart instead.', 'printful-meta-helper' ),
			'handling'            => __( 'Reserved for a future product-feed integration. Leave empty.', 'printful-meta-helper' ),
			// Companion link.
			'fit_label'           => __( 'Printed before the companion link on this blank\'s products, for example "Unisex sizing." Leave empty on a blank whose products should show the link alone, as a women\'s tee usually does.', 'printful-meta-helper' ),
			'link_text'           => __( 'The link shown on this blank\'s products, pointing at their companion: "Looking for women\'s sizes?" on the unisex blank, "Looking for men\'s/unisex sizes?" on the women\'s blank. Empty means the link renders nothing on this blank\'s products.', 'printful-meta-helper' ),
			'companion'           => __( 'The women\'s or unisex twin of this product. Saving links both products to each other; if the chosen product was already linked elsewhere, that link is cleared and a notice says so. The line under the field shows what [pmh_companion_link] will print, or why it prints nothing.', 'printful-meta-helper' ),
			// Product screen.
			'product_select'      => __( 'One blank per product. The size chart and materials are read from the blank when the page renders, so correcting the blank corrects every product that uses it. Only blanks offered for the product\'s ticked categories are listed.', 'printful-meta-helper' ),
			'product_show_all'    => __( 'Lists every blank regardless of the product\'s categories. Use it for a product in a new or unusual category, or when the right blank is not listed.', 'printful-meta-helper' ),
			'product_preview'     => __( 'What will render, computed from the product\'s saved variations. Save the product after changing variations to refresh it.', 'printful-meta-helper' ),
			// Blank Groups.
			'groups_apply'        => __( 'Only ticked groups change when you apply. Groups with a matching blank are ticked for you.', 'printful-meta-helper' ),
			'groups_chart'        => __( 'The measurements, sizes and a sample row shared by every product in the group. Identical charts mean the same garment.', 'printful-meta-helper' ),
			'groups_products'     => __( 'Expand to confirm the group is one garment before applying. Two different garments with identical charts would land in one group.', 'printful-meta-helper' ),
			'groups_assign'       => __( 'Pick an existing blank whose chart already matches (marked with a tick) or name a new one. A new blank is created as apparel with this chart, the body chart and the union of the products\' categories, and can be edited afterwards.', 'printful-meta-helper' ),
			'groups_skip'         => __( 'Ticked: products that already have a blank keep it. Untick to reassign them to the blank chosen for their group.', 'printful-meta-helper' ),
		);
	}

	/**
	 * A help-tip icon for a key. WooCommerce's helper when present; a
	 * focusable Dashicon with the same text otherwise.
	 */
	public static function tip( string $key ): string {
		$text = self::strings()[ $key ] ?? '';
		if ( '' === $text ) {
			return '';
		}
		if ( function_exists( 'wc_help_tip' ) ) {
			return wc_help_tip( $text );
		}
		return sprintf(
			'<span class="pmh-tip dashicons dashicons-editor-help" tabindex="0" role="img" title="%1$s" aria-label="%1$s"></span>',
			esc_attr( $text )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Intro and shortcodes box                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Above the "Add new blank" form, where a first-time editor lands.
	 */
	public static function render_intro(): void {
		$count = wp_count_terms( array( 'taxonomy' => PMH_TAXONOMY, 'hide_empty' => false ) );
		$count = is_wp_error( $count ) ? 0 : (int) $count;

		echo '<div class="pmh-intro">';
		echo '<p>' . esc_html__( 'A blank is the garment or item a product is printed on: a Gildan 5000, a Bella+Canvas 3001, an 11 oz mug. Define it once here with its size chart and materials, assign it to products, and place the shortcodes on the product page. Products store only a reference, so correcting a blank corrects every product that uses it.', 'printful-meta-helper' ) . '</p>';
		if ( 0 === $count ) {
			printf(
				'<p><strong>%s</strong> %s <a href="%s">%s</a></p>',
				esc_html__( 'No blanks yet.', 'printful-meta-helper' ),
				esc_html__( 'The fastest start is Blank Groups, which creates blanks from the size charts Printful already stored on your products:', 'printful-meta-helper' ),
				esc_url( admin_url( 'edit.php?post_type=product&page=' . PMH_Grouping::SLUG ) ),
				esc_html__( 'Open Blank Groups', 'printful-meta-helper' )
			);
		}
		echo '<p>' . esc_html__( 'The Help tab at the top right of this screen explains where the data comes from and lists the shortcodes.', 'printful-meta-helper' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Read-only shortcode reference for the blank edit screen.
	 */
	public static function shortcodes_box(): string {
		$rows = array(
			array( '[pmh_size_chart]', __( 'Size chart for the product in the loop, with the body chart\'s Chest range as an extra column, the inches/centimetres switch, the fixed "Measurements are provided by suppliers." line and the blank\'s note. Attributes: product_id, unit="cm", toggle="0", supplier="0", note="0", body_rows="Chest,Waist" (or "" for none), table="body", class="".', 'printful-meta-helper' ) ),
			array( '[pmh_materials]', __( 'Material with colour exceptions limited to the product\'s colours, fabric weight, construction, care and disclaimers, skipping empty fields. Attributes: product_id, fields="material,weight", labels="0", filter_colours="0", class="".', 'printful-meta-helper' ) ),
			array( '[pmh_blank_name]', __( 'The blank\'s name as plain text, for use inside a sentence.', 'printful-meta-helper' ) ),
			array( '[pmh_companion_link]', __( 'This blank\'s fit label plus its companion link text, linking to the product\'s companion (unisex ↔ women\'s). Nothing when the product has no companion. Attributes: product_id, class="".', 'printful-meta-helper' ) ),
		);
		$html = '<table class="pmh-shortcodes"><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr><th scope="row"><code>' . esc_html( $row[0] ) . '</code></th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		$html .= '<p class="description">' . esc_html__( 'Place them in any text block on a product page or template. Each returns nothing, not a message, when the product has no blank, the blank is not apparel, or no chart size matches the product\'s variations.', 'printful-meta-helper' ) . '</p>';
		return $html;
	}

	/* ------------------------------------------------------------------ */
	/* Help tabs                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Register the tabs for whichever of our screens is loading.
	 */
	public static function register_help_tabs(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$context = self::context_for_screen( $screen );
		if ( '' === $context ) {
			return;
		}
		foreach ( self::tabs( $context ) as $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => 'pmh-' . $tab['id'],
					'title'   => $tab['title'],
					'content' => wp_kses_post( $tab['content'] ),
				)
			);
		}
	}

	/**
	 * 'blank', 'product', 'groups' or '' for screens that are not ours.
	 *
	 * @param object $screen WP_Screen.
	 */
	public static function context_for_screen( $screen ): string {
		if ( isset( $screen->taxonomy ) && PMH_TAXONOMY === $screen->taxonomy ) {
			return 'blank';
		}
		if ( isset( $screen->id ) && 'product_page_' . PMH_Grouping::SLUG === $screen->id ) {
			return 'groups';
		}
		if ( isset( $screen->post_type, $screen->base ) && 'product' === $screen->post_type && 'post' === $screen->base ) {
			return 'product';
		}
		return '';
	}

	/**
	 * @return array<int, array{id: string, title: string, content: string}>
	 */
	public static function tabs( string $context ): array {
		$p = static fn( string $text ): string => '<p>' . esc_html( $text ) . '</p>';

		$about = array(
			'id'      => 'about',
			'title'   => __( 'About blanks', 'printful-meta-helper' ),
			'content' => $p( __( 'A blank is the base garment or item a product is printed on. It holds the full size chart and the materials once. Products store only a reference to their blank, never a copy, so a correction to the blank reaches every product that uses it the next time the page renders.', 'printful-meta-helper' ) )
				. $p( __( 'The blank holds every size the garment comes in. Each product shows only the sizes it has variations for, so a product sold in S to 2XL shows five rows from a chart that goes to 5XL, and re-enabling 3XL grows its chart with nobody editing data.', 'printful-meta-helper' ) )
				. $p( __( 'Workflow: create the blank (import its chart and materials from Printful), assign it to products (on the product screen, by Bulk Edit, or with Blank Groups), then place the shortcodes on the product page. The products list has a "No blank assigned" filter for stragglers.', 'printful-meta-helper' ) )
				. $p( __( 'Companion link: when a unisex product has a women\'s-sizing twin (or the reverse), the two products are linked on the product screen and [pmh_companion_link] renders the sentence. The wording comes from the product\'s own blank: "Fit label" is printed first ("Unisex sizing."), then "Companion link text" is the link ("Looking for women\'s sizes?"). Give the women\'s blank its own pair ("Looking for men\'s/unisex sizes?", usually with an empty fit label) and the sentence reads correctly in either direction.', 'printful-meta-helper' ) )
				. $p( __( 'Colour exceptions and disclaimers are filtered per product: a line renders only when it names a colour that product is sold in, so a tee sold in Black and Navy shows no heather line and no "White may appear off-white" disclaimer. Lines naming no colour always render, and products without a colour attribute show every line. The [pmh_materials] attribute filter_colours="0" turns the filter off.', 'printful-meta-helper' ) ),
		);
		$data = array(
			'id'      => 'data',
			'title'   => __( 'Getting the data', 'printful-meta-helper' ),
			'content' => $p( __( 'Size chart, three sources, in order of preference: Import from product (type an ID or SKU of a product Printful pushed; the chart is read from the JSON Printful stored on it), Import Printful JSON (paste the same JSON, found in the product\'s Custom Fields under pf_advanced_size_chart or on the Printful website), or Import pasted table (copy the table off the Printful product page). Products imported before Printful started storing the JSON have none; use the pasted table for those.', 'printful-meta-helper' ) )
				. $p( __( 'Materials: paste the list from Printful\'s product description into "Paste from Printful", bullets optional. It is split into base material, colour exceptions, fabric weight, construction and disclaimers on save. Care is not supplied by Printful and is optional.', 'printful-meta-helper' ) )
				. $p( __( 'Every import box is ignored when empty, so saving again never overwrites data. Everything imported stays editable in the grid and the fields.', 'printful-meta-helper' ) ),
		);
		$shortcodes = array(
			'id'      => 'shortcodes',
			'title'   => __( 'Shortcodes', 'printful-meta-helper' ),
			'content' => '<p><code>[pmh_size_chart]</code> ' . esc_html__( 'renders the size chart for the current product, filtered to its sizes, with the body chart\'s Chest range appended as an extra column (the range that fits, next to the garment width), an inches/centimetres switch, then the fixed line "Measurements are provided by suppliers." and the blank\'s note. Values show to one decimal, as Printful displays them. Attributes: product_id, unit="cm" (initial unit), toggle="0", supplier="0" (hide the fixed line), note="0", body_rows="Chest,Waist" ("" for none), table="body", class="extra classes".', 'printful-meta-helper' ) . '</p>'
				. '<p><code>[pmh_materials]</code> ' . esc_html__( 'renders material with colour exceptions, fabric weight, construction, care and disclaimers, skipping empty fields. Colour exceptions are limited to lines naming a colour the product is sold in; filter_colours="0" shows them all. Attributes: product_id, fields="material,weight,construction,care,disclaimers", labels="0", filter_colours="0", class="".', 'printful-meta-helper' ) . '</p>'
				. '<p><code>[pmh_blank_name]</code> ' . esc_html__( 'renders the blank\'s name as plain text for use inside a sentence.', 'printful-meta-helper' ) . '</p>'
				. '<p><code>[pmh_companion_link]</code> ' . esc_html__( 'renders the blank\'s fit label and companion link text as a link to the product\'s companion, for example "Unisex sizing. Looking for women\'s sizes?". Inline markup, so the text block it sits in controls the styling. Nothing renders when the product has no companion or its blank has no link text; the product screen says which. Attributes: product_id, class="".', 'printful-meta-helper' ) . '</p>'
				. $p( __( 'Place them in any text block on a product page or template. Each returns nothing, not a message, when there is nothing to show: no blank, a non-apparel blank, an empty chart, or no size in common between the chart and the product\'s variations. A text block containing only an empty shortcode collapses, so a mug page shows no chart and no gap.', 'printful-meta-helper' ) ),
		);
		$product = array(
			'id'      => 'blank',
			'title'   => __( 'Blank', 'printful-meta-helper' ),
			'content' => $p( __( 'The Blank box assigns one blank to this product. The list is filtered to blanks offered for the product\'s ticked categories and re-filters as you tick; "Show all blanks" lists every blank. A product holds exactly one blank; if more than one is submitted, for example by Bulk Edit, the most recently added wins.', 'printful-meta-helper' ) )
				. $p( __( 'The panel under the select previews what will render from the saved variations: the material and weight, the sizes the chart will show, and a warning when nothing would render. On products Printful has pushed it also says whether the blank\'s chart matches the product\'s own Printful chart and names the blanks that do. A mismatch means either the wrong blank or that Printful has updated the garment\'s spec since the blank was entered.', 'printful-meta-helper' ) )
				. $p( __( 'Companion product links a unisex product to its women\'s-sizing twin, or the reverse. Saving links both ways; a companion already linked elsewhere has that link cleared, with a notice. [pmh_companion_link] then renders this product\'s blank\'s fit label and companion link text, both set on the blank screen; the line under the field shows the sentence as it will print, or the reason nothing prints.', 'printful-meta-helper' ) )
				. $p( __( 'Place [pmh_size_chart], [pmh_materials] and [pmh_companion_link] in a text block on the product page or its template to render the blank\'s data. Blanks are managed under Products → Blanks.', 'printful-meta-helper' ) ),
		);
		$groups_how = array(
			'id'      => 'groups-how',
			'title'   => __( 'How grouping works', 'printful-meta-helper' ),
			'content' => $p( __( 'Printful stores the size-guide JSON on every product it pushes. Products on the same garment carry identical inch rows, so grouping products by their chart recovers one group per garment without anyone typing. Products without the stored JSON, typically ones imported before Printful began storing it, are counted on this screen and left in the products list under the "No blank assigned" filter for hand assignment.', 'printful-meta-helper' ) )
				. $p( __( 'For each group you either pick an existing blank whose chart matches, preselected and marked with a tick, or name a new one. A new blank is created as apparel with the group\'s garment chart, body chart and the union of the products\' categories, then every product in the group is assigned to it. Products that already have a blank are left alone unless you untick that option.', 'printful-meta-helper' ) )
				. $p( __( 'Two different garments with byte-identical charts would land in one group. Expand the product list under a group before applying if in doubt.', 'printful-meta-helper' ) ),
		);
		$groups_run = array(
			'id'      => 'groups-run',
			'title'   => __( 'Recommended first run', 'printful-meta-helper' ),
			'content' => $p( __( '1. Read the summary line: the group count should roughly match the number of distinct garments you sell.', 'printful-meta-helper' ) )
				. $p( __( '2. Name each group\'s new blank after its garment, leave "Leave products that already have a blank as they are" ticked, and apply.', 'printful-meta-helper' ) )
				. $p( __( '3. Open one created blank to check its grid, categories and materials; add the materials by pasting Printful\'s bullet list, which grouping cannot supply.', 'printful-meta-helper' ) )
				. $p( __( '4. Assign the remaining products by hand from the products list, filtered to "No blank assigned".', 'printful-meta-helper' ) ),
		);

		switch ( $context ) {
			case 'blank':
				return array( $about, $data, $shortcodes );
			case 'product':
				return array( $product );
			case 'groups':
				return array( $groups_how, $groups_run );
		}
		return array();
	}

	/**
	 * @param string[] $ids WooCommerce's screen list.
	 * @return string[]
	 */
	public static function woocommerce_screen_ids( $ids ): array {
		$ids   = (array) $ids;
		$ids[] = 'edit-' . PMH_TAXONOMY;
		$ids[] = 'product_page_' . PMH_Grouping::SLUG;
		return array_values( array_unique( $ids ) );
	}
}
