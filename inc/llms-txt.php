<?php
/**
 * AIntelligize – llms.txt & llms-full.txt
 *
 * Endpoints:
 *   /llms.txt      – Lightweight index with links & one-line descriptions (spec-compliant)
 *   /llms-full.txt – Comprehensive content: full service descriptions, area details, FAQs
 *
 * Service areas are presented hierarchically:
 *   Root (city) → Child posts (specific services in that city)
 *
 * Spec / guidance: https://llmstxt.org/
 *
 * @since 6.3.0.8
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Options
 * ------------------------------------------------------------------------- */

/**
 * Get a boolean-ish option.
 * Accepts: 1, '1', true, 'true', 'yes', 'on'
 */
if ( ! function_exists('myls_llms_opt_bool') ) {
	function myls_llms_opt_bool( string $key, bool $default = true ) : bool {
		$val = get_option( $key, $default ? '1' : '0' );
		if ( is_bool($val) ) return $val;
		$val = is_string($val) ? strtolower(trim($val)) : $val;
		return in_array( $val, [ 1, '1', 'true', 'yes', 'on' ], true );
	}
}

/**
 * Get an integer option with sane clamping.
 */
if ( ! function_exists('myls_llms_opt_int') ) {
	function myls_llms_opt_int( string $key, int $default, int $min = 0, int $max = 500 ) : int {
		$val = (int) get_option( $key, (string) $default );
		if ( $val < $min ) $val = $min;
		if ( $val > $max ) $val = $max;
		return $val;
	}
}

/* -------------------------------------------------------------------------
 * Builders — Shared helpers
 * ------------------------------------------------------------------------- */

/**
 * Build a simple list of markdown links for a post type.
 */
if ( ! function_exists('myls_llms_links_for_post_type') ) {
	function myls_llms_links_for_post_type( string $post_type, int $limit = 15 ) : array {
		$limit = max( 1, min( 200, $limit ) );

		$q = new WP_Query([
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'orderby'             => 'menu_order title',
			'order'               => 'ASC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'fields'              => 'ids',
		]);

		if ( empty($q->posts) ) return [];

		$lines = [];
		foreach ( $q->posts as $pid ) {
			$pid   = (int) $pid;
			$title = get_the_title( $pid );
			$url   = get_permalink( $pid );
			if ( ! $title || ! $url ) continue;
			$lines[] = '- [' . wp_strip_all_tags($title) . '](' . esc_url_raw($url) . ')';
		}
		return $lines;
	}
}

/**
 * Build a master list of FAQ links by scanning posts that have MYLS FAQ meta.
 *
 * Storage:
 *  - _myls_faq_items = [ [ 'q' => '...', 'a' => '...' ], ... ]
 *
 * Output:
 *  - [Question](Page URL#faq-N)
 *
 * Notes:
 *  - Global dedupe by question text to avoid repeated Qs across pages.
 */
if ( ! function_exists('myls_llms_collect_master_faq_links') ) {
	function myls_llms_collect_master_faq_links( int $limit = 25 ) : array {
		global $wpdb;

		$limit = max( 1, min( 200, $limit ) );

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"
				SELECT DISTINCT pm.post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s
				  AND p.post_status = 'publish'
				  AND p.post_type NOT IN ('revision','nav_menu_item','attachment')
				ORDER BY p.post_modified_gmt DESC
				LIMIT %d
				",
				'_myls_faq_items',
				500
			)
		);

		if ( empty( $post_ids ) ) return [];

		$lines       = [];
		$seen_q      = [];
		$total_added = 0;

		foreach ( $post_ids as $post_id ) {
			if ( $total_added >= $limit ) break;

			$post_id = (int) $post_id;
			$url     = get_permalink( $post_id );
			if ( ! $url ) continue;

			$items = get_post_meta( $post_id, '_myls_faq_items', true );
			if ( ! is_array( $items ) || empty( $items ) ) continue;

			$idx = 0;
			foreach ( $items as $row ) {
				$pos = $idx + 1;
				$idx++;
				if ( $total_added >= $limit ) break;
				if ( ! is_array( $row ) ) continue;

				$q = trim( sanitize_text_field( (string) ( $row['q'] ?? '' ) ) );
				$a = (string) ( $row['a'] ?? '' );

				$a_plain = trim( wp_strip_all_tags( $a ) );
				if ( $q === '' || $a_plain === '' ) continue;

				$q_key = strtolower( preg_replace('/\s+/', ' ', $q ) );
				if ( isset( $seen_q[ $q_key ] ) ) continue;
				$seen_q[ $q_key ] = true;

				$anchor = '#faq-' . $pos;
				$lines[] = '- [' . $q . '](' . esc_url_raw( $url . $anchor ) . ')';
				$total_added++;
			}
		}

		return $lines;
	}
}

/**
 * Build "Business details" lines.
 *
 * Source order:
 *  1) MYLS Organization options
 *  2) LocalBusiness location #1 (myls_lb_locations)
 *  3) Site name + home URL
 */
if ( ! function_exists('myls_llms_business_details_lines') ) {
	function myls_llms_business_details_lines() : array {
		$site_name = wp_strip_all_tags( get_bloginfo('name') );
		$home      = home_url( '/' );

		$org_name = trim( (string) get_option('myls_org_name', '') );
		$org_url  = trim( (string) get_option('myls_org_url', '') );
		$org_tel  = trim( (string) get_option('myls_org_tel', '') );
		$street   = trim( (string) get_option('myls_org_street', '') );
		$city     = trim( (string) get_option('myls_org_locality', '') );
		$state    = trim( (string) get_option('myls_org_region', '') );
		$zip      = trim( (string) get_option('myls_org_postal', '') );
		$country  = trim( (string) get_option('myls_org_country', '') );

		// Fallback to LocalBusiness location #1 if org fields are missing.
		$locs = function_exists('myls_lb_get_locations_cached') ? (array) myls_lb_get_locations_cached() : (array) get_option('myls_lb_locations', []);
		$loc0 = ( ! empty($locs) && is_array($locs[0] ?? null) ) ? (array) $locs[0] : [];

		$name = $org_name ?: trim( (string) ( $loc0['name'] ?? '' ) );
		$url  = $org_url ?: $home;
		$tel  = $org_tel ?: trim( (string) ( $loc0['phone'] ?? '' ) );

		if ( ! $street )  $street  = trim( (string) ( $loc0['street']  ?? '' ) );
		if ( ! $city )    $city    = trim( (string) ( $loc0['city']    ?? '' ) );
		if ( ! $state )   $state   = trim( (string) ( $loc0['state']   ?? '' ) );
		if ( ! $zip )     $zip     = trim( (string) ( $loc0['zip']     ?? '' ) );
		if ( ! $country ) $country = trim( (string) ( $loc0['country'] ?? '' ) );

		$name = $name ?: ( $site_name ?: 'Business' );
		$url  = $url ?: $home;

		$addr_parts = array_filter( [ $street, $city, $state, $zip, $country ] );
		$addr       = $addr_parts ? implode( ', ', $addr_parts ) : '';

		$lines = [];
		$lines[] = '- Legal name: ' . $name;
		$lines[] = '- Website: ' . $url;
		if ( $tel )  $lines[] = '- Phone: ' . $tel;
		if ( $addr ) $lines[] = '- Address: ' . $addr;

		return $lines;
	}
}

/**
 * Build a hierarchical tree of service_area posts.
 *
 * Returns root-level posts with their children nested.
 * Each entry: [
 *   'id'         => int,
 *   'title'      => string,
 *   'url'        => string,
 *   'city_state' => string,   // from _myls_city_state meta
 *   'children'   => [ same structure without 'children' ]
 * ]
 *
 * @since 6.3.0.8
 */
if ( ! function_exists('myls_llms_service_area_tree') ) {
	function myls_llms_service_area_tree( int $limit = 300 ) : array {
		$q = new WP_Query([
			'post_type'      => 'service_area',
			'post_status'    => 'publish',
			'posts_per_page' => min( $limit, 500 ),
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		]);

		if ( empty( $q->posts ) ) return [];

		// Index all posts by ID and group children by parent.
		$roots    = [];
		$children = [];

		foreach ( $q->posts as $p ) {
			$entry = [
				'id'         => $p->ID,
				'title'      => get_the_title( $p ),
				'url'        => get_permalink( $p ),
				'city_state' => trim( (string) get_post_meta( $p->ID, '_myls_city_state', true ) ),
			];

			if ( (int) $p->post_parent === 0 ) {
				$entry['children'] = [];
				$roots[ $p->ID ] = $entry;
			} else {
				$children[ $p->post_parent ][] = $entry;
			}
		}

		// Attach children to their root.
		foreach ( $children as $parent_id => $kids ) {
			if ( isset( $roots[ $parent_id ] ) ) {
				$roots[ $parent_id ]['children'] = $kids;
			} else {
				// Orphaned children (parent not in results) — show as roots.
				foreach ( $kids as $kid ) {
					$roots[ $kid['id'] ] = $kid;
				}
			}
		}

		return array_values( $roots );
	}
}

/**
 * Get clean plain text from a post for llms-full.txt.
 *
 * Delegates to the centralized myls_get_post_plain_text() utility
 * which handles Classic, Gutenberg, DIVI, WPBakery, Elementor,
 * and Beaver Builder content.
 *
 * @since 6.3.0.8
 *
 * @param string $content  The post_content (kept for backward compat signature).
 * @param int    $post_id  The post ID.
 * @return string           Clean plain text.
 */
if ( ! function_exists('myls_llms_clean_post_text') ) {
	function myls_llms_clean_post_text( string $content, int $post_id = 0 ) : string {
		// Use centralized utility if post ID is available.
		if ( $post_id > 0 && function_exists('myls_get_post_plain_text') ) {
			return myls_get_post_plain_text( $post_id );
		}

		// Fallback: simple strip (no builder support without post_id).
		$text = strip_shortcodes( $content );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}
}

/**
 * Collect all FAQs grouped by post_id for llms-full.txt.
 *
 * Returns: [ post_id => [ [ 'q' => ..., 'a' => ... ], ... ], ... ]
 *
 * @since 6.3.0.8
 */
if ( ! function_exists('myls_llms_collect_all_faqs_by_post') ) {
	function myls_llms_collect_all_faqs_by_post() : array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_status = 'publish'
				 ORDER BY p.post_title ASC",
				'_myls_faq_items'
			)
		);

		$out = [];
		foreach ( $rows as $row ) {
			$items = maybe_unserialize( $row->meta_value );
			if ( ! is_array($items) ) {
				$items = json_decode( $row->meta_value, true );
			}
			if ( ! is_array($items) || empty($items) ) continue;

			$clean = [];
			foreach ( $items as $faq ) {
				$q = trim( wp_strip_all_tags( (string) ($faq['question'] ?? $faq['q'] ?? '') ) );
				$a = trim( wp_strip_all_tags( (string) ($faq['answer']   ?? $faq['a'] ?? '') ) );
				if ( $q && $a ) {
					$clean[] = [ 'q' => $q, 'a' => $a ];
				}
			}
			if ( ! empty($clean) ) {
				$out[ (int) $row->post_id ] = $clean;
			}
		}

		return $out;
	}
}


/* -------------------------------------------------------------------------
 * Rewrite rules & query var
 * ------------------------------------------------------------------------- */

if ( ! function_exists('myls_llms_register_rewrite') ) {
	function myls_llms_register_rewrite() : void {
		add_rewrite_rule( '^llms\.txt$',      'index.php?myls_llms=index', 'top' );
		add_rewrite_rule( '^llms-full\.txt$',  'index.php?myls_llms=full',  'top' );
	}
	add_action( 'init', 'myls_llms_register_rewrite', 1 );
}

/**
 * Flush rewrite rules once when rewrite version changes.
 * Ensures /llms-full.txt rule is active after plugin update.
 *
 * @since 6.3.0.8
 */
add_action( 'init', function() {
	$ver_key = 'myls_llms_rewrite_ver';
	$current = '2'; // Bump this when adding/changing rewrite rules.
	if ( get_option( $ver_key ) !== $current ) {
		flush_rewrite_rules( false );
		update_option( $ver_key, $current, true );
	}
}, 99 );

/** Make our query var public. */
add_filter( 'query_vars', function( $vars ) {
	$vars[] = 'myls_llms';
	return $vars;
} );


/* =========================================================================
 * /llms.txt — Lightweight index
 * ========================================================================= */

if ( ! function_exists('myls_llms_generate_content') ) {
	function myls_llms_generate_content() : string {
		$site_name = wp_strip_all_tags( get_bloginfo('name') );
		$tagline   = wp_strip_all_tags( get_bloginfo('description') );

		$org_desc = trim( (string) get_option('myls_org_description', '') );
		$summary  = $org_desc ? wp_strip_all_tags( $org_desc ) : $tagline;

		$home      = home_url( '/' );
		$sitemap   = home_url( '/sitemap.xml' );
		$robots    = home_url( '/robots.txt' );
		$full_url  = home_url( '/llms-full.txt' );

		// Attempt to find a "Contact" page by common slugs.
		$contact_url = '';
		foreach ( [ 'contact', 'contact-us', 'contact-us-2', 'get-a-quote', 'request-a-quote' ] as $slug ) {
			$p = get_page_by_path( $slug );
			if ( $p instanceof WP_Post ) {
				$contact_url = get_permalink( $p );
				break;
			}
		}

		$lines = [];
		$lines[] = '# ' . ( $site_name ?: 'Website' );
		if ( $summary ) {
			$lines[] = '';
			$lines[] = '> ' . $summary;
		}

		$lines[] = '';
		$lines[] = '<!-- Generated by AIntelligize | Last updated: ' . gmdate('Y-m-d') . ' -->';

		// Point to comprehensive version.
		$lines[] = '';
		$lines[] = 'For comprehensive business content including full service descriptions, service area details, and FAQ answers, see [llms-full.txt](' . esc_url_raw($full_url) . ').';

		// -- Key pages --------------------------------------------------------
		$lines[] = '';
		$lines[] = '## Key pages';
		$lines[] = '- [Home](' . esc_url_raw($home) . '): Main homepage.';
		if ( $contact_url ) {
			$lines[] = '- [Contact](' . esc_url_raw($contact_url) . '): Primary contact page.';
		}
		$lines[] = '- [LLM Info](' . esc_url_raw( home_url('/llm-info') ) . '): Detailed business information for AI assistants (HTML).';
		$lines[] = '- [Full LLM Content](' . esc_url_raw($full_url) . '): Complete business content in Markdown.';
		$lines[] = '- [Sitemap](' . esc_url_raw($sitemap) . '): XML sitemap.';
		$lines[] = '- [Robots](' . esc_url_raw($robots) . '): robots.txt directives.';

		// -- Business details -------------------------------------------------
		if ( myls_llms_opt_bool('myls_llms_include_business_details', true) ) {
			$bd = myls_llms_business_details_lines();
			if ( ! empty($bd) ) {
				$lines[] = '';
				$lines[] = '## Business details';
				$lines   = array_merge( $lines, $bd );
			}
		}

		// -- Primary services -------------------------------------------------
		if ( myls_llms_opt_bool('myls_llms_include_services', true) ) {
			$limit = myls_llms_opt_int('myls_llms_services_limit', 15, 1, 200);
			$svc   = myls_llms_links_for_post_type( 'service', $limit );
			if ( ! empty($svc) ) {
				$lines[] = '';
				$lines[] = '## Primary services';
				$lines   = array_merge( $lines, $svc );
			}
		}

		// -- Service areas — hierarchical tree --------------------------------
		if ( myls_llms_opt_bool('myls_llms_include_service_areas', true) ) {
			$limit = myls_llms_opt_int('myls_llms_service_areas_limit', 20, 1, 300);
			$tree  = myls_llms_service_area_tree( $limit );
			if ( ! empty($tree) ) {
				$lines[] = '';
				$lines[] = '## Service areas';
				foreach ( $tree as $root ) {
					$cs = $root['city_state'] ? ' (' . $root['city_state'] . ')' : '';
					$lines[] = '- [' . wp_strip_all_tags($root['title']) . '](' . esc_url_raw($root['url']) . ')' . $cs;
					if ( ! empty($root['children']) ) {
						foreach ( $root['children'] as $child ) {
							$lines[] = '  - [' . wp_strip_all_tags($child['title']) . '](' . esc_url_raw($child['url']) . ')';
						}
					}
				}
			}
		}

		// -- FAQs (links only in index) ---------------------------------------
		if ( myls_llms_opt_bool('myls_llms_include_faqs', true) ) {
			$limit = myls_llms_opt_int('myls_llms_faqs_limit', 25, 1, 200);
			$faqs  = myls_llms_collect_master_faq_links( $limit );
			if ( ! empty($faqs) ) {
				$lines[] = '';
				$lines[] = '## Frequently asked questions';
				$lines   = array_merge( $lines, $faqs );
			}
		}

		// -- Optional ---------------------------------------------------------
		$lines[] = '';
		$lines[] = '## Optional';
		$lines[] = '- [REST API Index](' . esc_url_raw( home_url('/wp-json/') ) . '): WordPress REST API entry point.';

		$content = implode( "\n", $lines ) . "\n";

		/**
		 * Filter: myls_llms_txt_content
		 * Allows other MYLS modules (or themes) to modify/extend the llms.txt output.
		 */
		return (string) apply_filters( 'myls_llms_txt_content', $content );
	}
}


/* =========================================================================
 * /llms-full.txt — Comprehensive content
 *
 * Includes everything from llms.txt PLUS:
 *  - Full service descriptions (post_content as plain text)
 *  - Service area tree with page content per root and child
 *  - All FAQs inline with questions AND answers
 * ========================================================================= */

if ( ! function_exists('myls_llms_generate_full_content') ) {
	function myls_llms_generate_full_content() : string {
		$site_name = wp_strip_all_tags( get_bloginfo('name') );
		$tagline   = wp_strip_all_tags( get_bloginfo('description') );

		$org_desc = trim( (string) get_option('myls_org_description', '') );
		$summary  = $org_desc ? wp_strip_all_tags( $org_desc ) : $tagline;

		$home = home_url( '/' );

		// Contact page.
		$contact_url = '';
		foreach ( [ 'contact', 'contact-us', 'contact-us-2', 'get-a-quote', 'request-a-quote' ] as $slug ) {
			$p = get_page_by_path( $slug );
			if ( $p instanceof WP_Post ) {
				$contact_url = get_permalink( $p );
				break;
			}
		}

		// Pre-fetch all FAQs grouped by post ID.
		$all_faqs = myls_llms_collect_all_faqs_by_post();

		$lines = [];
		$lines[] = '# ' . ( $site_name ?: 'Website' );
		if ( $summary ) {
			$lines[] = '';
			$lines[] = '> ' . $summary;
		}
		$lines[] = '';
		$lines[] = '<!-- Generated by AIntelligize | Last updated: ' . gmdate('Y-m-d') . ' -->';
		$lines[] = '';
		$lines[] = 'This file contains the complete content of this website in a format optimized for large language models.';

		// -- Key pages --------------------------------------------------------
		$lines[] = '';
		$lines[] = '## Key pages';
		$lines[] = '- [Home](' . esc_url_raw($home) . ')';
		if ( $contact_url ) {
			$lines[] = '- [Contact](' . esc_url_raw($contact_url) . ')';
		}
		$lines[] = '- [LLM Info](' . esc_url_raw( home_url('/llm-info') ) . '): Structured business data (HTML).';
		$lines[] = '- [Sitemap](' . esc_url_raw( home_url('/sitemap.xml') ) . ')';

		// -- Business details -------------------------------------------------
		if ( myls_llms_opt_bool('myls_llms_include_business_details', true) ) {
			$bd = myls_llms_business_details_lines();
			if ( ! empty($bd) ) {
				$lines[] = '';
				$lines[] = '## Business details';
				$lines   = array_merge( $lines, $bd );
			}
		}

		// -- Services: full content -------------------------------------------
		if ( myls_llms_opt_bool('myls_llms_include_services', true) ) {
			$limit = myls_llms_opt_int('myls_llms_services_limit', 15, 1, 200);

			$q = new WP_Query([
				'post_type'      => 'service',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]);

			if ( ! empty($q->posts) ) {
				$lines[] = '';
				$lines[] = '## Services';

				foreach ( $q->posts as $sp ) {
					$title = wp_strip_all_tags( get_the_title($sp) );
					$url   = get_permalink($sp);
					$text  = myls_llms_clean_post_text( $sp->post_content, $sp->ID );

					$lines[] = '';
					$lines[] = '### ' . $title;
					$lines[] = 'URL: ' . esc_url_raw($url);

					if ( $text ) {
						$lines[] = '';
						$lines[] = $text;
					}

					// FAQs for this service post.
					if ( ! empty($all_faqs[ $sp->ID ]) ) {
						$lines[] = '';
						$lines[] = '**FAQs for ' . $title . ':**';
						foreach ( $all_faqs[ $sp->ID ] as $faq ) {
							$lines[] = '';
							$lines[] = '**Q: ' . $faq['q'] . '**';
							$lines[] = $faq['a'];
						}
					}
				}
				wp_reset_postdata();
			}
		}

		// -- Service areas: hierarchical with full content ---------------------
		if ( myls_llms_opt_bool('myls_llms_include_service_areas', true) ) {
			$tree = myls_llms_service_area_tree( 500 );

			if ( ! empty($tree) ) {
				$lines[] = '';
				$lines[] = '## Service areas';

				foreach ( $tree as $root ) {
					$root_post = get_post( $root['id'] );
					$root_text = $root_post ? myls_llms_clean_post_text( $root_post->post_content, $root_post->ID ) : '';
					$cs_label  = $root['city_state'] ? ' — ' . $root['city_state'] : '';

					$lines[] = '';
					$lines[] = '### ' . wp_strip_all_tags($root['title']) . $cs_label;
					$lines[] = 'URL: ' . esc_url_raw($root['url']);

					if ( $root_text ) {
						$lines[] = '';
						$lines[] = $root_text;
					}

					// FAQs for root service area.
					if ( ! empty($all_faqs[ $root['id'] ]) ) {
						$lines[] = '';
						$lines[] = '**FAQs:**';
						foreach ( $all_faqs[ $root['id'] ] as $faq ) {
							$lines[] = '';
							$lines[] = '**Q: ' . $faq['q'] . '**';
							$lines[] = $faq['a'];
						}
					}

					// Child service area posts.
					if ( ! empty($root['children']) ) {
						foreach ( $root['children'] as $child ) {
							$child_post = get_post( $child['id'] );
							$child_text = $child_post ? myls_llms_clean_post_text( $child_post->post_content, $child_post->ID ) : '';

							$lines[] = '';
							$lines[] = '#### ' . wp_strip_all_tags($child['title']);
							$lines[] = 'URL: ' . esc_url_raw($child['url']);

							if ( $child_text ) {
								$lines[] = '';
								$lines[] = $child_text;
							}

							// FAQs for child.
							if ( ! empty($all_faqs[ $child['id'] ]) ) {
								$lines[] = '';
								$lines[] = '**FAQs:**';
								foreach ( $all_faqs[ $child['id'] ] as $faq ) {
									$lines[] = '';
									$lines[] = '**Q: ' . $faq['q'] . '**';
									$lines[] = $faq['a'];
								}
							}
						}
					}
				}
			}
		}

		// -- Standalone FAQs (posts that aren't service/service_area) ----------
		if ( myls_llms_opt_bool('myls_llms_include_faqs', true) ) {
			// Gather FAQ post IDs we already covered above.
			$covered_ids = [];

			// Services.
			if ( myls_llms_opt_bool('myls_llms_include_services', true) ) {
				$sq = new WP_Query([
					'post_type'      => 'service',
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]);
				$covered_ids = array_merge( $covered_ids, $sq->posts );
			}

			// Service areas.
			if ( myls_llms_opt_bool('myls_llms_include_service_areas', true) ) {
				$aq = new WP_Query([
					'post_type'      => 'service_area',
					'post_status'    => 'publish',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]);
				$covered_ids = array_merge( $covered_ids, $aq->posts );
			}

			$covered_ids = array_map( 'intval', $covered_ids );

			// Collect remaining FAQs from other post types.
			$remaining_faqs = [];
			$seen           = [];
			foreach ( $all_faqs as $pid => $faqs ) {
				if ( in_array( $pid, $covered_ids, true ) ) continue;
				foreach ( $faqs as $faq ) {
					$key = strtolower( preg_replace('/\s+/', ' ', $faq['q']) );
					if ( isset($seen[$key]) ) continue;
					$seen[$key] = true;
					$remaining_faqs[] = $faq;
				}
			}

			if ( ! empty($remaining_faqs) ) {
				$lines[] = '';
				$lines[] = '## Additional frequently asked questions';
				foreach ( $remaining_faqs as $faq ) {
					$lines[] = '';
					$lines[] = '**Q: ' . $faq['q'] . '**';
					$lines[] = $faq['a'];
				}
			}
		}

		// -- Optional ---------------------------------------------------------
		$lines[] = '';
		$lines[] = '## Optional';
		$lines[] = '- [REST API Index](' . esc_url_raw( home_url('/wp-json/') ) . '): WordPress REST API entry point.';

		$content = implode( "\n", $lines ) . "\n";

		/**
		 * Filter: myls_llms_full_txt_content
		 * Allows other MYLS modules (or themes) to modify/extend the llms-full.txt output.
		 */
		return (string) apply_filters( 'myls_llms_full_txt_content', $content );
	}
}


/* =========================================================================
 * Serve endpoints
 * ========================================================================= */

add_action( 'template_redirect', function() {
	$flag = (string) get_query_var('myls_llms');
	if ( $flag === '' ) return;

	// Allow disabling the endpoint without removing the rewrite rule.
	if ( ! myls_llms_opt_bool('myls_llms_enabled', true) ) {
		status_header( 404 );
		exit;
	}

	// Determine which endpoint was requested.
	$is_full  = ( $flag === 'full' );
	// Backward compat: '1' or 'index' both serve the lightweight index.
	$is_index = in_array( $flag, [ '1', 'index' ], true );

	if ( ! $is_full && ! $is_index ) return;

	if ( function_exists('nocache_headers') ) nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-My-Local-SEO: ' . ( $is_full ? 'llms-full.txt' : 'llms.txt' ) );

	if ( $is_full ) {
		echo myls_llms_generate_full_content();
	} else {
		echo myls_llms_generate_content();
	}
	exit;
}, 0 );
