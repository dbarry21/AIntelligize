<?php
/**
 * AJAX for Bulk → Clone Service Areas
 * Path: admin/tabs/bulk/_clone-service-areas-ajax.php
 */

if ( ! defined('ABSPATH') ) exit;

/** Shared: resolve Service Area post type key (same logic as tab-bulk.php) */
if ( ! function_exists('myls_sa_post_type') ) {
	function myls_sa_post_type() {
		$pt = get_option('myls_cpt_service_area');
		if ( ! $pt ) $pt = get_option('ssseo_cpt_service_area');
		$pt = $pt ?: 'service_area';
		return post_type_exists($pt) ? $pt : 'service_area';
	}
}

if ( ! function_exists('myls_ajax_check') ) {
	function myls_ajax_check( $nonce_key = 'myls_bulk_ops' ) {
		if ( empty($_POST['nonce']) || ! wp_verify_nonce( $_POST['nonce'], $nonce_key ) ) {
			wp_send_json_error( 'Invalid or missing nonce.' );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}
	}
}

/** Utility: get published Service Area IDs (ASC title) */
if ( ! function_exists('myls_sa_ids') ) {
	function myls_sa_ids() {
		$sa_pt = myls_sa_post_type();
		return array_map( 'intval', get_posts( array(
			'post_type'        => $sa_pt,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => true,
			'no_found_rows'    => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		) ) );
	}
}

/** Utility: build flat list [{id,title}] */
if ( ! function_exists('myls_sa_flat_items') ) {
	function myls_sa_flat_items() {
		$out = array();
		foreach ( myls_sa_ids() as $pid ) {
			$out[] = array(
				'id'    => (int) $pid,
				'title' => get_the_title( $pid ) ?: '(no title)',
			);
		}
		return $out;
	}
}

/** Utility: build tree rows [{id,title,depth}] */
if ( ! function_exists('myls_sa_tree_items') ) {
	function myls_sa_tree_items() {
		$sa_pt = myls_sa_post_type();
		$posts = get_posts( array(
			'post_type'        => $sa_pt,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => array('menu_order' => 'ASC', 'title' => 'ASC'),
			'order'            => 'ASC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		) );

		$by_id = array();
		foreach ( $posts as $p ) {
			$by_id[$p->ID] = array(
				'id'     => (int) $p->ID,
				'title'  => get_the_title($p) ?: '(no title)',
				'parent' => (int) $p->post_parent,
				'kids'   => array(),
			);
		}
		foreach ( $by_id as $id => &$node ) {
			if ( $node['parent'] && isset( $by_id[ $node['parent'] ] ) ) {
				$by_id[ $node['parent'] ]['kids'][] = &$node;
			}
		}
		unset($node);

		$roots = array();
		foreach ( $by_id as $id => $node ) {
			if ( empty($node['parent']) || ! isset($by_id[ $node['parent'] ]) ) $roots[] = $id;
		}

		$out = array();
		$dfs = function( $id, $depth ) use ( &$by_id, &$out, &$dfs ) {
			$node = $by_id[$id];
			$out[] = array(
				'id'    => $node['id'],
				'title' => $node['title'],
				'depth' => max(0, (int) $depth),
			);
			if ( ! empty( $node['kids'] ) ) {
				usort($node['kids'], function($a,$b){ return strcasecmp($a['title'], $b['title']); });
				foreach ( $node['kids'] as $child ) $dfs( $child['id'], $depth + 1 );
			}
		};
		foreach ( $roots as $rid ) $dfs( $rid, 0 );

		return $out;
	}
}

/** ---------- AJAX: flat list */
add_action( 'wp_ajax_myls_sa_all_published', function() {
	myls_ajax_check();
	wp_send_json_success( array( 'items' => myls_sa_flat_items() ) );
} );

/** ---------- AJAX: tree list */
add_action( 'wp_ajax_myls_sa_tree_published', function() {
	myls_ajax_check();
	wp_send_json_success( array( 'items' => myls_sa_tree_items() ) );
} );

/**
 * ---------- AJAX: clone source under each target parent
 */
add_action( 'wp_ajax_myls_clone_sa_to_parents', function() {
	myls_ajax_check();

	$sa_pt     = myls_sa_post_type();
	$source_id = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
	$targets   = isset($_POST['target_parent_ids']) ? (array) $_POST['target_parent_ids'] : array();
	$as_draft  = ! empty($_POST['as_draft']) ? 1 : 0;
	$skip      = ! empty($_POST['skip_existing']) ? 1 : 0;
	$new_slug  = sanitize_title( wp_unslash( $_POST['new_slug'] ?? '' ) );
	$focus_base= sanitize_text_field( wp_unslash( $_POST['focus_base'] ?? '' ) );
	$debug     = ! empty($_POST['debug']) ? 1 : 0;

	if ( ! $source_id || get_post_type( $source_id ) !== $sa_pt ) {
		wp_send_json_error( 'Invalid source.' );
	}
	if ( empty( $targets ) ) {
		wp_send_json_error( 'No target parents provided.' );
	}

	$log = array();
	$src = get_post( $source_id );

	foreach ( $targets as $parent_id ) {
		$parent_id = intval($parent_id);
		if ( ! $parent_id || get_post_type( $parent_id ) !== $sa_pt ) {
			$log[] = "Skipping invalid parent ID {$parent_id}.";
			continue;
		}

		$title = get_the_title( $src ) ?: 'Untitled';

		// Optional: skip if a child with same title exists
		if ( $skip ) {
			$exists = get_pages( array(
				'post_type'   => $sa_pt,
				'parent'      => $parent_id,
				'post_status' => array('publish','draft','pending','future','private'),
				'sort_column' => 'post_title',
				'hierarchical'=> false,
			) );
			$found_same = false;
			foreach ( $exists as $e ) {
				if ( strcasecmp( $e->post_title, $title ) === 0 ) { $found_same = true; break; }
			}
			if ( $found_same ) {
				$log[] = "Parent {$parent_id}: skipped (child '{$title}' already exists).";
				continue;
			}
		}

		$new_id = wp_insert_post( array(
			'post_type'      => $sa_pt,
			'post_status'    => $as_draft ? 'draft' : 'publish',
			'post_parent'    => $parent_id,
			'post_title'     => $title,
			'post_name'      => $new_slug ? $new_slug : '',
			'post_content'   => $src->post_content,
			'post_excerpt'   => $src->post_excerpt,
			'menu_order'     => 0,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		), true );

		if ( is_wp_error( $new_id ) ) {
			$log[] = "Parent {$parent_id}: ❌ Insert failed: " . $new_id->get_error_message();
			continue;
		}

		// Thumbnail
		$thumb = get_post_thumbnail_id( $source_id );
		if ( $thumb ) { set_post_thumbnail( $new_id, $thumb ); }

		// Taxonomies
		$taxes = get_object_taxonomies( $sa_pt, 'names' );
		foreach ( $taxes as $tax ) {
			$terms = wp_get_object_terms( $source_id, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) { wp_set_object_terms( $new_id, $terms, $tax, false ); }
		}

		// Meta
		$meta = get_post_meta( $source_id );
		foreach ( $meta as $key => $vals ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) continue;
			foreach ( (array) $vals as $v ) { add_post_meta( $new_id, $key, maybe_unserialize( $v ) ); }
		}

		// ACF: set city_state from parent
		$parent_city_state = get_post_meta( $parent_id, 'city_state', true );
		if ( $parent_city_state !== '' ) {
			update_post_meta( $new_id, 'city_state', $parent_city_state );
		}

		// Yoast focus keyphrase (optional)
		if ( $focus_base !== '' ) {
			update_post_meta( $new_id, '_yoast_wpseo_focuskw',            sanitize_text_field( $focus_base . ' ' . $parent_city_state ) );
			update_post_meta( $new_id, '_yoast_wpseo_focuskw_text_input', sanitize_text_field( $focus_base . ' ' . $parent_city_state ) );
		}

		$log[] = "Parent {$parent_id}: ✅ cloned to post ID {$new_id}.";
	}

	wp_send_json_success( array( 'log' => $log ) );
} );
