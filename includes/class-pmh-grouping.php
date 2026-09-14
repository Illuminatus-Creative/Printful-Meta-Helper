<?php
/**
 * Blank Groups: a one-off admin screen that seeds blanks from the catalogue.
 *
 * Every product Printful has pushed carries the size-guide JSON in
 * pf_advanced_size_chart. Two products on the same garment carry identical
 * inch rows, so grouping products by chart signature recovers "one group
 * per garment" without anyone typing. Each group can be assigned to an
 * existing blank whose chart matches, or to a new blank created from the
 * group's chart. Products without the meta are counted, not hidden: they
 * remain in the products list under "No blank assigned".
 */

defined( 'ABSPATH' ) || exit;

final class PMH_Grouping {

	public const SLUG    = 'pmh-blank-groups';
	private const ACTION = 'pmh_apply_groups';
	private const NONCE  = 'pmh_groups_nonce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_apply' ) );
	}

	public static function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Blank Groups', 'printful-meta-helper' ),
			__( 'Blank Groups', 'printful-meta-helper' ),
			'manage_product_terms',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Scan                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array{groups: array<string, array>, no_meta: int, unreadable: int[]}
	 *   groups keyed by chart signature: chart, body_chart, products (id =>
	 *   ['title','blank_id','cats']), blank_matches (term IDs with the same chart).
	 */
	public static function scan(): array {
		$with_meta = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => PMH_Importer::PRODUCT_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare'   => 'EXISTS',
				'no_found_rows'  => true,
			)
		);
		$counts = wp_count_posts( 'product' );
		$total  = 0;
		foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $status ) {
			$total += (int) ( $counts->$status ?? 0 );
		}

		// One query each for titles and terms instead of one per product.
		if ( $with_meta ) {
			_prime_post_caches( $with_meta, false, false );
			update_object_term_cache( $with_meta, 'product' );
		}

		$parsed     = array();
		$unreadable = array();
		foreach ( $with_meta as $product_id ) {
			$product_id = (int) $product_id;
			$result     = PMH_Importer::from_product_meta( $product_id );
			if ( ! $result || ! $result['product'] ) {
				$unreadable[] = $product_id;
				continue;
			}
			$blank = PMH_Blank::for_product( $product_id );
			$cats  = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

			$parsed[ $product_id ] = array(
				'chart'      => $result['product'],
				'body_chart' => $result['body'] ?: PMH_Size_Chart::empty_chart(),
				'title'      => get_the_title( $product_id ),
				'blank_id'   => $blank ? (int) $blank->term_id : 0,
				'cats'       => is_wp_error( $cats ) ? array() : array_map( 'intval', $cats ),
			);
		}

		$blank_charts = array();
		foreach ( PMH_Blank::all() as $blank ) {
			$blank_charts[ $blank->term_id ] = PMH_Blank::get( $blank->term_id )['chart'];
		}

		return array(
			'groups'     => self::group_products( $parsed, $blank_charts ),
			'no_meta'    => max( 0, $total - count( $with_meta ) ),
			'unreadable' => $unreadable,
		);
	}

	/**
	 * Group parsed products by chart signature and attach matching blanks.
	 * Pure: no WordPress calls, so it is unit-tested directly.
	 *
	 * @param array<int, array{chart: array, body_chart: array, title: string, blank_id: int, cats: int[]}> $parsed
	 * @param array<int, array> $blank_charts term_id => chart.
	 * @return array<string, array{chart: array, body_chart: array, products: array, blank_matches: int[]}>
	 */
	public static function group_products( array $parsed, array $blank_charts ): array {
		$groups = array();
		foreach ( $parsed as $product_id => $p ) {
			$signature = PMH_Size_Chart::signature( $p['chart'] );
			if ( '' === $signature ) {
				continue;
			}
			if ( ! isset( $groups[ $signature ] ) ) {
				$groups[ $signature ] = array(
					'chart'         => PMH_Size_Chart::normalise( $p['chart'] ),
					'body_chart'    => PMH_Size_Chart::normalise( $p['body_chart'] ),
					'products'      => array(),
					'blank_matches' => array(),
				);
			}
			$groups[ $signature ]['products'][ (int) $product_id ] = array(
				'title'    => $p['title'],
				'blank_id' => (int) $p['blank_id'],
				'cats'     => array_map( 'intval', $p['cats'] ),
			);
		}

		foreach ( $blank_charts as $term_id => $chart ) {
			$sig = PMH_Size_Chart::signature( $chart );
			if ( '' !== $sig && isset( $groups[ $sig ] ) ) {
				$groups[ $sig ]['blank_matches'][] = (int) $term_id;
			}
		}

		// Largest groups first.
		uasort( $groups, static fn( $a, $b ) => count( $b['products'] ) <=> count( $a['products'] ) );

		return $groups;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                               */
	/* ------------------------------------------------------------------ */

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'printful-meta-helper' ) );
		}

		$scan   = self::scan();
		$blanks = PMH_Blank::all();
		$by_id  = array();
		foreach ( $blanks as $b ) {
			$by_id[ $b->term_id ] = $b;
		}

		echo '<div class="wrap pmh-groups">';
		echo '<h1>' . esc_html__( 'Blank Groups', 'printful-meta-helper' ) . '</h1>';

		if ( isset( $_GET['pmh_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			PMH_Notices::render( PMH_Notices::take( 'groups' ), false );
		}

		echo '<p>' . esc_html__( 'Products Printful has pushed carry their size chart. Products on the same garment carry the same chart, so they group together here. Assign each group to an existing blank or create one from its chart; the blank is created with the group’s chart, body chart and the union of the products’ categories, and stays fully editable.', 'printful-meta-helper' ) . '</p>';

		printf(
			'<p class="pmh-groups__summary">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: group count, 2: product count with meta, 3: products without meta, 4: unreadable count */
					__( '%1$d groups across %2$d products. %3$d products have no Printful chart (legacy imports; assign them by hand via the “No blank assigned” filter). %4$d products have a chart that could not be read.', 'printful-meta-helper' ),
					count( $scan['groups'] ),
					array_sum( array_map( static fn( $g ) => count( $g['products'] ), $scan['groups'] ) ),
					$scan['no_meta'],
					count( $scan['unreadable'] )
				)
			)
		);

		if ( ! $scan['groups'] ) {
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION, self::NONCE );

		echo '<p><label><input type="checkbox" name="skip_assigned" value="1" checked> ' . esc_html__( 'Leave products that already have a blank as they are', 'printful-meta-helper' ) . '</label></p>';

		echo '<table class="widefat striped pmh-groups__table"><thead><tr>';
		echo '<th class="pmh-groups__apply">' . esc_html__( 'Apply', 'printful-meta-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Chart', 'printful-meta-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Products', 'printful-meta-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Assign to', 'printful-meta-helper' ) . '</th>';
		echo '</tr></thead><tbody>';

		$i = 0;
		foreach ( $scan['groups'] as $signature => $group ) {
			$name     = 'groups[' . $i . ']';
			$assigned = count( array_filter( $group['products'], static fn( $p ) => $p['blank_id'] > 0 ) );
			$match_id = $group['blank_matches'][0] ?? 0;

			echo '<tr>';
			echo '<td class="pmh-groups__apply"><input type="checkbox" name="' . esc_attr( $name . '[apply]' ) . '" value="1"' . ( $match_id ? ' checked' : '' ) . '>';
			echo '<input type="hidden" name="' . esc_attr( $name . '[signature]' ) . '" value="' . esc_attr( $signature ) . '"></td>';

			echo '<td class="pmh-groups__chart">';
			echo '<strong>' . esc_html( implode( ' · ', array_column( $group['chart']['rows'], 'label' ) ) ) . '</strong><br>';
			echo '<span class="description">' . esc_html( implode( ', ', $group['chart']['sizes'] ) ) . '</span><br>';
			$first = $group['chart']['rows'][0] ?? null;
			if ( $first ) {
				$sample = array();
				foreach ( array_slice( $group['chart']['sizes'], 0, 3 ) as $size ) {
					if ( isset( $first['values'][ $size ] ) ) {
						$sample[] = $size . ' ' . PMH_Size_Chart::format_values( $first['values'][ $size ] ) . '"';
					}
				}
				echo '<span class="description">' . esc_html( $first['label'] . ': ' . implode( ', ', $sample ) . ' …' ) . '</span>';
			}
			echo '</td>';

			echo '<td class="pmh-groups__products">';
			printf(
				'<details><summary>%s</summary><ul>',
				esc_html(
					sprintf(
						/* translators: 1: product count, 2: already-assigned count */
						__( '%1$d products (%2$d already have a blank)', 'printful-meta-helper' ),
						count( $group['products'] ),
						$assigned
					)
				)
			);
			foreach ( $group['products'] as $pid => $p ) {
				$blank_name = $p['blank_id'] && isset( $by_id[ $p['blank_id'] ] ) ? $by_id[ $p['blank_id'] ]->name : '';
				printf(
					'<li><a href="%1$s">%2$s</a>%3$s</li>',
					esc_url( get_edit_post_link( $pid ) ),
					esc_html( $p['title'] ?: '#' . $pid ),
					$blank_name ? ' <span class="description">— ' . esc_html( $blank_name ) . '</span>' : ''
				);
			}
			echo '</ul></details></td>';

			$use_existing = $match_id > 0; // no blanks at all => 0 => "new" is the default

			echo '<td class="pmh-groups__assign">';
			echo '<label><input type="radio" name="' . esc_attr( $name . '[mode]' ) . '" value="existing"' . ( $use_existing ? ' checked' : '' ) . ( $blanks ? '' : ' disabled' ) . '> ' . esc_html__( 'Existing blank', 'printful-meta-helper' ) . '</label> ';
			echo '<select name="' . esc_attr( $name . '[existing]' ) . '"' . ( $blanks ? '' : ' disabled' ) . '>';
			echo '<option value="">' . esc_html__( '— choose —', 'printful-meta-helper' ) . '</option>';
			foreach ( $blanks as $b ) {
				$is_match = in_array( (int) $b->term_id, $group['blank_matches'], true );
				printf(
					'<option value="%1$d"%2$s>%3$s%4$s</option>',
					(int) $b->term_id,
					selected( $match_id, (int) $b->term_id, false ),
					esc_html( $b->name ),
					$is_match ? ' ✓' : ''
				);
			}
			echo '</select><br>';
			echo '<label><input type="radio" name="' . esc_attr( $name . '[mode]' ) . '" value="new"' . ( $use_existing ? '' : ' checked' ) . '> ' . esc_html__( 'New blank named', 'printful-meta-helper' ) . '</label> ';
			echo '<input type="text" name="' . esc_attr( $name . '[name]' ) . '" class="regular-text" placeholder="' . esc_attr__( 'Gildan 5000', 'printful-meta-helper' ) . '">';
			if ( $match_id ) {
				echo '<p class="description">' . esc_html__( 'A blank with an identical chart exists and is preselected.', 'printful-meta-helper' ) . '</p>';
			}
			echo '</td></tr>';
			$i++;
		}

		echo '</tbody></table>';
		submit_button( __( 'Apply to ticked groups', 'printful-meta-helper' ) );
		echo '</form>';

		if ( $scan['unreadable'] ) {
			echo '<h2>' . esc_html__( 'Unreadable charts', 'printful-meta-helper' ) . '</h2><ul>';
			foreach ( $scan['unreadable'] as $pid ) {
				printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( get_edit_post_link( $pid ) ), esc_html( get_the_title( $pid ) ?: '#' . $pid ) );
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Apply                                                              */
	/* ------------------------------------------------------------------ */

	public static function handle_apply(): void {
		if ( ! current_user_can( 'manage_product_terms' ) || ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'printful-meta-helper' ) );
		}
		check_admin_referer( self::ACTION, self::NONCE );

		$skip_assigned = ! empty( $_POST['skip_assigned'] );
		$submitted     = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? wp_unslash( $_POST['groups'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in apply_groups().

		$result = self::apply_groups( $submitted, $skip_assigned );

		PMH_Notices::set(
			'groups',
			array(
				sprintf(
					/* translators: 1: blanks created, 2: products assigned, 3: products skipped */
					__( '%1$d blanks created, %2$d products assigned, %3$d products skipped (already assigned, or not yours to edit).', 'printful-meta-helper' ),
					$result['created'],
					$result['assigned'],
					$result['skipped']
				),
			),
			$result['errors']
		);

		wp_safe_redirect( add_query_arg( array( 'pmh_result' => 1 ), admin_url( 'edit.php?post_type=product&page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Apply the submitted group rows. Separated from the request handler so
	 * it can be tested; every field is sanitised here.
	 *
	 * @param array $submitted Raw groups[] rows from the form.
	 * @param bool  $skip_assigned Leave products that already have a blank.
	 * @return array{created: int, assigned: int, skipped: int, errors: string[]}
	 */
	public static function apply_groups( array $submitted, bool $skip_assigned ): array {
		$scan     = self::scan();
		$created  = 0;
		$assigned = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $submitted as $row ) {
			if ( ! is_array( $row ) || empty( $row['apply'] ) ) {
				continue;
			}
			$signature = isset( $row['signature'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $row['signature'] ) : '';
			if ( '' === $signature || ! isset( $scan['groups'][ $signature ] ) ) {
				$errors[] = __( 'A ticked group no longer exists; the catalogue changed. Reload and try again.', 'printful-meta-helper' );
				continue;
			}
			$group = $scan['groups'][ $signature ];
			$mode  = isset( $row['mode'] ) && 'new' === $row['mode'] ? 'new' : 'existing';

			if ( 'existing' === $mode ) {
				$term_id = isset( $row['existing'] ) ? absint( $row['existing'] ) : 0;
				$term    = $term_id ? get_term( $term_id, PMH_TAXONOMY ) : null;
				if ( ! $term instanceof WP_Term ) {
					$errors[] = __( 'A ticked group had no existing blank chosen and was skipped.', 'printful-meta-helper' );
					continue;
				}
			} else {
				$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
				if ( '' === $name ) {
					$errors[] = __( 'A ticked group had no name for its new blank and was skipped.', 'printful-meta-helper' );
					continue;
				}
				$term_id = self::create_blank( $name, $group, $errors );
				if ( ! $term_id ) {
					continue;
				}
				$created++;
			}

			foreach ( $group['products'] as $pid => $p ) {
				if ( $skip_assigned && $p['blank_id'] > 0 ) {
					$skipped++;
					continue;
				}
				// The screen-level capability lets the user run the tool; each
				// product still needs to be one they may edit and tag.
				if ( ! current_user_can( 'edit_post', (int) $pid ) || ! current_user_can( 'assign_product_terms' ) ) {
					$skipped++;
					continue;
				}
				if ( $p['blank_id'] === (int) $term_id ) {
					continue;
				}
				$set = wp_set_object_terms( (int) $pid, array( (int) $term_id ), PMH_TAXONOMY, false );
				if ( is_wp_error( $set ) ) {
					/* translators: %d: product ID */
					$errors[] = sprintf( __( 'Could not assign product %d.', 'printful-meta-helper' ), $pid );
					continue;
				}
				$assigned++;
			}
		}

		return array(
			'created'  => $created,
			'assigned' => $assigned,
			'skipped'  => $skipped,
			'errors'   => array_values( array_unique( $errors ) ),
		);
	}

	/**
	 * Create a blank from a group: apparel kind, the group's charts, and
	 * the union of the products' categories as "applies to".
	 */
	private static function create_blank( string $name, array $group, array &$errors ): int {
		$existing = term_exists( $name, PMH_TAXONOMY );
		if ( $existing ) {
			/* translators: %s: blank name */
			$errors[] = sprintf( __( 'A blank named “%s” already exists; choose it as an existing blank instead.', 'printful-meta-helper' ), $name );
			return 0;
		}
		$inserted = wp_insert_term( $name, PMH_TAXONOMY );
		if ( is_wp_error( $inserted ) ) {
			$errors[] = $inserted->get_error_message();
			return 0;
		}
		$term_id = (int) $inserted['term_id'];

		$cats = array();
		foreach ( $group['products'] as $p ) {
			$cats = array_merge( $cats, $p['cats'] );
		}

		PMH_Blank::update(
			$term_id,
			array(
				'kind'       => 'apparel',
				'cats'       => array_values( array_unique( array_map( 'intval', $cats ) ) ),
				'chart'      => $group['chart'],
				'body_chart' => $group['body_chart'],
			)
		);

		return $term_id;
	}
}
