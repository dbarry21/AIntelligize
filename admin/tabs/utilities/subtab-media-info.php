<?php
/**
 * Utilities Subtab: Media Info
 * File: admin/tabs/utilities/subtab-media-info.php
 *
 * Self-contained subtab for inspecting media attachments from three angles:
 *   1. Attachments used by a specific post (post-type → post lookup)
 *   2. All attachments for a chosen post type
 *   3. Full-site media library report
 *
 * All AJAX handlers, HTML, CSS, and JS live in this file.
 * Auto-discovered by admin/tabs/tab-utilities.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================================
 * SHARED ROW BUILDER
 * ========================================================================= */
if ( ! function_exists( 'myls_media_info_row' ) ) {
	/**
	 * Build a normalized row array for a single attachment post.
	 *
	 * @param WP_Post $att Attachment post object.
	 * @return array
	 */
	function myls_media_info_row( $att ) {
		if ( ! $att instanceof WP_Post ) {
			return array();
		}

		$file_path = get_attached_file( $att->ID );
		$file_name = $file_path ? basename( $file_path ) : '';

		$uploader_name = '—';
		if ( (int) $att->post_author > 0 ) {
			$u = get_userdata( (int) $att->post_author );
			if ( $u && ! empty( $u->display_name ) ) {
				$uploader_name = (string) $u->display_name;
			}
		}

		$parent_title = 'Unattached';
		$parent_type  = '—';
		if ( (int) $att->post_parent > 0 ) {
			$parent = get_post( (int) $att->post_parent );
			if ( $parent ) {
				$parent_title = (string) ( get_the_title( $parent ) ?: ( '(no title) #' . $parent->ID ) );
				$parent_type  = (string) $parent->post_type;
			}
		}

		$url = wp_get_attachment_url( $att->ID );
		if ( ! $url ) $url = '';

		return array(
			'id'           => (int) $att->ID,
			'file_name'    => (string) $file_name,
			'title'        => (string) $att->post_title,
			'mime'         => (string) $att->post_mime_type,
			'uploader'     => (string) $uploader_name,
			'date'         => (string) $att->post_date,
			'modified'     => (string) $att->post_modified,
			'parent_title' => (string) $parent_title,
			'parent_type'  => (string) $parent_type,
			'url'          => (string) $url,
		);
	}
}

/* =========================================================================
 * AJAX HANDLERS
 * ========================================================================= */

/** Shared permission + nonce gate. Exits on failure. */
if ( ! function_exists( 'myls_media_info_guard' ) ) {
	function myls_media_info_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'myls_media_info_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ), 403 );
		}
	}
}

/* ---- 1. Get public post types (excluding attachment) ---- */
if ( ! has_action( 'wp_ajax_myls_media_get_post_types' ) ) {
	add_action( 'wp_ajax_myls_media_get_post_types', function () {
		myls_media_info_guard();

		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		$out = array();
		foreach ( $types as $slug => $obj ) {
			$label = isset( $obj->labels->singular_name ) && $obj->labels->singular_name
				? $obj->labels->singular_name
				: $obj->label;
			$out[] = array(
				'value' => (string) $slug,
				'label' => (string) $label,
			);
		}

		// Sort by label.
		usort( $out, function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		} );

		wp_send_json_success( array( 'types' => $out ) );
	} );
}

/* ---- 2. Search posts by title within a post type ---- */
if ( ! has_action( 'wp_ajax_myls_media_search_posts' ) ) {
	add_action( 'wp_ajax_myls_media_search_posts', function () {
		myls_media_info_guard();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : '';
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( $post_type === '' || $post_type === 'attachment' ) {
			wp_send_json_error( array( 'message' => 'Invalid post type.' ) );
		}
		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( array( 'posts' => array() ) );
		}

		$q = new WP_Query( array(
			'post_type'              => $post_type,
			'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			's'                      => $search,
			'posts_per_page'         => 30,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		) );

		$posts = array();
		foreach ( $q->posts as $pid ) {
			$posts[] = array(
				'id'    => (int) $pid,
				'title' => (string) ( get_the_title( (int) $pid ) ?: ( '(no title) #' . $pid ) ),
			);
		}

		wp_send_json_success( array( 'posts' => $posts ) );
	} );
}

/* ---- 3. Get all attachments for a specific post ---- */
if ( ! has_action( 'wp_ajax_myls_media_get_post_attachments' ) ) {
	add_action( 'wp_ajax_myls_media_get_post_attachments', function () {
		myls_media_info_guard();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post.' ) );
		}

		$children = get_children( array(
			'post_parent'    => $post_id,
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'numberposts'    => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'suppress_filters' => true,
		) );

		$rows = array();
		if ( is_array( $children ) ) {
			foreach ( $children as $att ) {
				$rows[] = myls_media_info_row( $att );
			}
		}

		wp_send_json_success( array(
			'rows'  => $rows,
			'count' => count( $rows ),
		) );
	} );
}

/* ---- 4. Get all attachments whose parent is a post of given post type ---- */
if ( ! has_action( 'wp_ajax_myls_media_get_posttype_attachments' ) ) {
	add_action( 'wp_ajax_myls_media_get_posttype_attachments', function () {
		myls_media_info_guard();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : '';
		if ( $post_type === '' || $post_type === 'attachment' ) {
			wp_send_json_error( array( 'message' => 'Invalid post type.' ) );
		}

		$cap = 500;

		// Get all post IDs of this type.
		$parent_ids = get_posts( array(
			'post_type'              => $post_type,
			'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		) );

		if ( empty( $parent_ids ) ) {
			wp_send_json_success( array(
				'rows'   => array(),
				'count'  => 0,
				'total'  => 0,
				'capped' => false,
			) );
		}

		// Get attachments whose post_parent matches. Pull slightly more than cap
		// so we can tell the user whether the result set was capped.
		$att_query = new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_parent__in'        => array_map( 'intval', $parent_ids ),
			'posts_per_page'         => $cap + 1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		) );

		$posts_all = $att_query->posts;
		$total     = (int) $att_query->found_posts;
		$capped    = $total > $cap;
		$posts     = $capped ? array_slice( $posts_all, 0, $cap ) : $posts_all;

		$rows = array();
		foreach ( $posts as $att ) {
			$rows[] = myls_media_info_row( $att );
		}

		wp_send_json_success( array(
			'rows'   => $rows,
			'count'  => count( $rows ),
			'total'  => $total,
			'capped' => $capped,
		) );
	} );
}

/* ---- 5. Full library report ---- */
if ( ! has_action( 'wp_ajax_myls_media_get_full_library' ) ) {
	add_action( 'wp_ajax_myls_media_get_full_library', function () {
		global $wpdb;
		myls_media_info_guard();

		$cap = 1000;

		$counts = wp_count_posts( 'attachment' );
		$total  = 0;
		if ( is_object( $counts ) ) {
			foreach ( $counts as $n ) {
				$total += (int) $n;
			}
		}

		// Read filters.
		$uploader_id = isset( $_POST['uploader_id'] ) ? (int) $_POST['uploader_id'] : 0;
		$parent_id   = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
		$file_name   = isset( $_POST['file_name'] ) ? sanitize_text_field( wp_unslash( $_POST['file_name'] ) ) : '';

		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => $cap,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( $uploader_id > 0 ) {
			$args['author'] = $uploader_id;
		}
		if ( $parent_id > 0 ) {
			$args['post_parent'] = $parent_id;
		}

		// Filename LIKE filter: match against post_title OR the _wp_attached_file meta value.
		$where_cb = null;
		if ( $file_name !== '' ) {
			$like        = '%' . $wpdb->esc_like( $file_name ) . '%';
			$like_sql    = $wpdb->prepare( '%s', $like );
			$meta_exists = $wpdb->prepare(
				"EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = %s AND pm.meta_value LIKE %s)",
				'_wp_attached_file',
				$like
			);
			$where_cb = function ( $where ) use ( $like_sql, $meta_exists, $wpdb ) {
				$where .= " AND ( {$wpdb->posts}.post_title LIKE {$like_sql} OR {$meta_exists} )";
				return $where;
			};
			add_filter( 'posts_where', $where_cb );
		}

		$att_query = new WP_Query( $args );

		if ( $where_cb ) {
			remove_filter( 'posts_where', $where_cb );
		}

		$rows = array();
		foreach ( $att_query->posts as $att ) {
			$rows[] = myls_media_info_row( $att );
		}

		wp_send_json_success( array(
			'rows'   => $rows,
			'count'  => count( $rows ),
			'total'  => $total,
			'capped' => count( $rows ) >= $cap,
		) );
	} );
}

/* ---- 6. List users who can upload media ---- */
if ( ! has_action( 'wp_ajax_myls_media_get_upload_users' ) ) {
	add_action( 'wp_ajax_myls_media_get_upload_users', function () {
		myls_media_info_guard();

		$users = get_users( array(
			'capability' => 'upload_files',
			'fields'     => array( 'ID', 'display_name', 'user_login' ),
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		) );

		$out = array();
		foreach ( $users as $u ) {
			$name = ! empty( $u->display_name ) ? $u->display_name : $u->user_login;
			$out[] = array(
				'id'   => (int) $u->ID,
				'name' => (string) $name,
			);
		}

		wp_send_json_success( array( 'users' => $out ) );
	} );
}

/* =========================================================================
 * SUBTAB SPEC
 * ========================================================================= */
return array(
	'id'     => 'media-info',
	'label'  => 'Media Info',
	'order'  => 90,
	'render' => function () {

		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p class="muted">You do not have permission to view this section.</p>';
			return;
		}

		$nonce = wp_create_nonce( 'myls_media_info_nonce' );
		$cfg   = array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => $nonce,
		);
		?>

		<style>
		#myls-media-info-wrap .cardish { margin-bottom: 1rem; }
		#myls-media-info-wrap .mi-section-title { font-size: 1.1rem; font-weight: 700; margin: 0 0 .75rem; }
		#myls-media-info-wrap .mi-results-meta { display:flex; justify-content:space-between; align-items:center; margin:.75rem 0 .5rem; flex-wrap:wrap; gap:.5rem; }
		#myls-media-info-wrap .mi-count { font-weight:600; }
		#myls-media-info-wrap .mi-capped { color:#b45309; font-weight:600; }
		#myls-media-info-wrap table.widefat { margin-top: 0; }
		#myls-media-info-wrap table.widefat th { cursor:pointer; user-select:none; white-space:nowrap; }
		#myls-media-info-wrap table.widefat th .mi-sort-ind { opacity:.4; margin-left:4px; }
		#myls-media-info-wrap table.widefat th.mi-sort-asc .mi-sort-ind,
		#myls-media-info-wrap table.widefat th.mi-sort-desc .mi-sort-ind { opacity:1; }
		#myls-media-info-wrap table.widefat td { vertical-align: top; word-break: break-word; }
		#myls-media-info-wrap .mi-table-wrap { overflow-x:auto; }
		#myls-media-info-wrap .mi-loading { padding:.75rem 0; }
		#myls-media-info-wrap .mi-error { color:#b91c1c; padding:.5rem 0; }
		#myls-media-info-wrap .mi-row { display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end; }
		#myls-media-info-wrap .mi-row > div { flex:1 1 220px; min-width:200px; }
		</style>

		<div id="myls-media-info-wrap" class="myls-util-wrap">

			<h2 class="mb-3">Media Info</h2>
			<p class="muted mb-3">Inspect media attachments by post, by post type, or across the entire library.</p>

			<input type="hidden" id="myls-mi-nonce" value="<?php echo esc_attr( $nonce ); ?>">

			<!-- ── Section A: Post Lookup ─────────────────────────── -->
			<div class="cardish">
				<div class="mi-section-title">Post Lookup</div>
				<p class="muted" style="margin-top:0;">Select a post type, search for a post, then view the media attached to it.</p>

				<div class="mi-row mt-2">
					<div>
						<label class="form-label" for="myls-mi-post-type">Post Type</label>
						<select id="myls-mi-post-type" class="form-select form-control">
							<option value="">Loading…</option>
						</select>
					</div>
					<div>
						<label class="form-label" for="myls-mi-post-search">Search posts</label>
						<input type="text" id="myls-mi-post-search" class="form-control" placeholder="Type 2+ characters…" disabled>
					</div>
					<div>
						<label class="form-label" for="myls-mi-post-results">Matching posts</label>
						<select id="myls-mi-post-results" class="form-select form-control" disabled>
							<option value="">—</option>
						</select>
					</div>
				</div>

				<div id="myls-mi-post-table-wrap" class="mt-3"></div>
			</div>

			<!-- ── Section B: Post Type Report ────────────────────── -->
			<div class="cardish">
				<div class="mi-section-title">Post Type Report</div>
				<p class="muted" style="margin-top:0;">Load every attachment whose parent is a post of the selected post type (max 500).</p>

				<button type="button" id="myls-mi-load-posttype" class="btn btn-primary" disabled>Load All Media for This Post Type</button>

				<div id="myls-mi-posttype-table-wrap" class="mt-3"></div>
			</div>

			<!-- ── Section C: Full Site Library Report ────────────── -->
			<div class="cardish">
				<div class="mi-section-title">Full Site Library Report</div>
				<p class="muted" style="margin-top:0;">Generate a report of every attachment in the media library (max 1000). Use filters below to narrow the results before the cap is applied.</p>

				<div class="mi-row mt-2">
					<div>
						<label class="form-label" for="myls-mi-full-uploader">Uploaded By</label>
						<select id="myls-mi-full-uploader" class="form-select form-control">
							<option value="">— Any user —</option>
						</select>
					</div>
					<div>
						<label class="form-label" for="myls-mi-full-filename">File name contains</label>
						<input type="text" id="myls-mi-full-filename" class="form-control" placeholder="e.g. hero, logo, .pdf">
					</div>
				</div>

				<div class="mi-row mt-2">
					<div>
						<label class="form-label" for="myls-mi-full-parent-type">Assigned To — Post Type</label>
						<select id="myls-mi-full-parent-type" class="form-select form-control">
							<option value="">— Any / select type —</option>
						</select>
					</div>
					<div>
						<label class="form-label" for="myls-mi-full-parent-search">Search posts</label>
						<input type="text" id="myls-mi-full-parent-search" class="form-control" placeholder="Type 2+ characters…" disabled>
					</div>
					<div>
						<label class="form-label" for="myls-mi-full-parent-results">Matching posts</label>
						<select id="myls-mi-full-parent-results" class="form-select form-control" disabled>
							<option value="">—</option>
						</select>
					</div>
				</div>

				<div class="mt-2" style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
					<button type="button" id="myls-mi-load-full" class="btn btn-primary">Generate Full Library Report</button>
					<button type="button" id="myls-mi-full-clear-filters" class="btn">Clear Filters</button>
					<span id="myls-mi-full-active-filters" class="muted"></span>
				</div>

				<div id="myls-mi-full-table-wrap" class="mt-3"></div>
			</div>

		</div>

		<script>
		var MYLS_MEDIA_INFO = <?php echo wp_json_encode( $cfg ); ?>;
		(function(){
			'use strict';

			var CFG = window.MYLS_MEDIA_INFO || {};
			var $id = function(id){ return document.getElementById(id); };

			var COLUMNS = [
				{ key: 'id',           label: 'ID' },
				{ key: 'file_name',    label: 'File Name' },
				{ key: 'title',        label: 'Title (alt)' },
				{ key: 'mime',         label: 'File Type / MIME' },
				{ key: 'uploader',     label: 'Uploaded By' },
				{ key: 'date',         label: 'Upload Date' },
				{ key: 'modified',     label: 'Last Modified' },
				{ key: 'parent_title', label: 'Assigned To' },
				{ key: 'parent_type',  label: 'Assigned Post Type' },
				{ key: 'url',          label: 'File URL' }
			];

			function escapeHtml(str){
				if (str === null || str === undefined) return '';
				return String(str)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#039;');
			}

			function debounce(fn, ms){
				var t = null;
				return function(){
					var ctx = this, args = arguments;
					if (t) clearTimeout(t);
					t = setTimeout(function(){ fn.apply(ctx, args); }, ms);
				};
			}

			function postAjax(action, data){
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', CFG.nonce || '');
				if (data) {
					Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
				}
				return fetch(CFG.ajax_url, {
					method: 'POST',
					credentials: 'same-origin',
					body: fd
				}).then(function(res){
					return res.text().then(function(txt){
						var json;
						try { json = JSON.parse(txt); }
						catch(e){ throw new Error('Invalid JSON response (HTTP ' + res.status + ').'); }
						if (!json || json.success !== true) {
							var msg = (json && json.data && json.data.message) ? json.data.message : 'Request failed.';
							throw new Error(msg);
						}
						return json.data;
					});
				});
			}

			function showLoading(containerId){
				var el = $id(containerId);
				if (el) el.innerHTML = '<p class="muted mi-loading">Loading…</p>';
			}

			function showError(containerId, message){
				var el = $id(containerId);
				if (el) el.innerHTML = '<p class="mi-error">Error: ' + escapeHtml(message) + '</p>';
			}

			// Store state per container for sorting
			var STATE = {};

			function renderTable(containerId, rows, meta){
				var el = $id(containerId);
				if (!el) return;

				STATE[containerId] = {
					rows: rows.slice(),
					sortKey: null,
					sortAsc: true,
					meta: meta || {}
				};

				if (!rows.length) {
					el.innerHTML = '<p class="muted">No attachments found.</p>';
					return;
				}

				var metaHtml = '<div class="mi-results-meta">';
				metaHtml += '<div>';
				metaHtml += '<span class="mi-count">Showing ' + rows.length + ' attachment' + (rows.length === 1 ? '' : 's') + '</span>';
				if (meta && meta.capped) {
					metaHtml += ' <span class="mi-capped">— capped at ' + rows.length + ' of ' + (meta.total || '?') + ' total</span>';
				} else if (meta && typeof meta.total === 'number' && meta.total !== rows.length) {
					metaHtml += ' <span class="muted">(total on site: ' + meta.total + ')</span>';
				}
				metaHtml += '</div>';
				metaHtml += '<button type="button" class="btn mi-copy-csv">Copy as CSV</button>';
				metaHtml += '</div>';

				var thead = '<thead><tr>';
				for (var i = 0; i < COLUMNS.length; i++) {
					var c = COLUMNS[i];
					thead += '<th data-key="' + escapeHtml(c.key) + '">' + escapeHtml(c.label) + '<span class="mi-sort-ind">▲▼</span></th>';
				}
				thead += '</tr></thead>';

				var html = metaHtml + '<div class="mi-table-wrap"><table class="widefat striped">' + thead + '<tbody></tbody></table></div>';
				el.innerHTML = html;

				drawBody(containerId);

				// Wire sort
				var ths = el.querySelectorAll('table.widefat thead th');
				for (var j = 0; j < ths.length; j++) {
					ths[j].addEventListener('click', onSortClick.bind(null, containerId));
				}

				// Wire copy
				var copyBtn = el.querySelector('.mi-copy-csv');
				if (copyBtn) {
					copyBtn.addEventListener('click', function(){
						copyAsCsv(STATE[containerId].rows, copyBtn);
					});
				}
			}

			function drawBody(containerId){
				var el = $id(containerId);
				if (!el) return;
				var tbody = el.querySelector('table.widefat tbody');
				if (!tbody) return;

				var rows = STATE[containerId].rows;
				var html = '';
				for (var i = 0; i < rows.length; i++) {
					var r = rows[i];
					html += '<tr>';
					html += '<td>' + escapeHtml(r.id) + '</td>';
					html += '<td>' + escapeHtml(r.file_name) + '</td>';
					html += '<td>' + escapeHtml(r.title) + '</td>';
					html += '<td>' + escapeHtml(r.mime) + '</td>';
					html += '<td>' + escapeHtml(r.uploader) + '</td>';
					html += '<td>' + escapeHtml(r.date) + '</td>';
					html += '<td>' + escapeHtml(r.modified) + '</td>';
					html += '<td>' + escapeHtml(r.parent_title) + '</td>';
					html += '<td>' + escapeHtml(r.parent_type) + '</td>';
					html += '<td>';
					if (r.url) {
						html += '<a href="' + escapeHtml(r.url) + '" target="_blank" rel="noopener">Open</a>';
					} else {
						html += '—';
					}
					html += '</td>';
					html += '</tr>';
				}
				tbody.innerHTML = html;

				// Update sort indicators
				var state = STATE[containerId];
				var ths = el.querySelectorAll('table.widefat thead th');
				for (var k = 0; k < ths.length; k++) {
					ths[k].classList.remove('mi-sort-asc', 'mi-sort-desc');
					if (ths[k].getAttribute('data-key') === state.sortKey) {
						ths[k].classList.add(state.sortAsc ? 'mi-sort-asc' : 'mi-sort-desc');
					}
				}
			}

			function onSortClick(containerId, ev){
				var th = ev.currentTarget;
				var key = th.getAttribute('data-key');
				if (!key) return;

				var state = STATE[containerId];
				if (!state) return;

				if (state.sortKey === key) {
					state.sortAsc = !state.sortAsc;
				} else {
					state.sortKey = key;
					state.sortAsc = true;
				}

				var asc = state.sortAsc ? 1 : -1;
				state.rows.sort(function(a, b){
					var av = a[key], bv = b[key];
					// numeric for id
					if (key === 'id') {
						return (Number(av) - Number(bv)) * asc;
					}
					av = (av === null || av === undefined) ? '' : String(av).toLowerCase();
					bv = (bv === null || bv === undefined) ? '' : String(bv).toLowerCase();
					if (av < bv) return -1 * asc;
					if (av > bv) return  1 * asc;
					return 0;
				});
				drawBody(containerId);
			}

			function copyAsCsv(rows, btn){
				var lines = [];
				var headers = [];
				for (var i = 0; i < COLUMNS.length; i++) headers.push(COLUMNS[i].label);
				lines.push(headers.join('\t'));

				for (var r = 0; r < rows.length; r++) {
					var row = rows[r];
					var cells = [];
					for (var c = 0; c < COLUMNS.length; c++) {
						var v = row[COLUMNS[c].key];
						if (v === null || v === undefined) v = '';
						v = String(v).replace(/\t/g, ' ').replace(/\r?\n/g, ' ');
						cells.push(v);
					}
					lines.push(cells.join('\t'));
				}
				var text = lines.join('\n');

				var origLabel = btn ? btn.textContent : null;
				var onDone = function(){
					if (btn) {
						btn.textContent = 'Copied!';
						setTimeout(function(){ btn.textContent = origLabel; }, 1500);
					}
				};
				var onFail = function(){
					if (btn) {
						btn.textContent = 'Copy failed';
						setTimeout(function(){ btn.textContent = origLabel; }, 1500);
					}
				};

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(onDone, onFail);
					return;
				}
				// Fallback
				try {
					var ta = document.createElement('textarea');
					ta.value = text;
					ta.style.position = 'fixed';
					ta.style.top = '-1000px';
					document.body.appendChild(ta);
					ta.select();
					document.execCommand('copy');
					document.body.removeChild(ta);
					onDone();
				} catch (e) {
					onFail();
				}
			}

			/* ---- Section A: load post types on init ---- */
			function loadPostTypes(){
				var sel = $id('myls-mi-post-type');
				if (!sel) return;
				postAjax('myls_media_get_post_types', {}).then(function(data){
					var types = (data && data.types) || [];
					var html = '<option value="">— Select post type —</option>';
					for (var i = 0; i < types.length; i++) {
						html += '<option value="' + escapeHtml(types[i].value) + '">' + escapeHtml(types[i].label) + '</option>';
					}
					sel.innerHTML = html;
				}).catch(function(err){
					sel.innerHTML = '<option value="">(error)</option>';
					showError('myls-mi-post-table-wrap', err.message);
				});
			}

			function onPostTypeChange(){
				var ptSel = $id('myls-mi-post-type');
				var search = $id('myls-mi-post-search');
				var results = $id('myls-mi-post-results');
				var loadBtn = $id('myls-mi-load-posttype');

				var pt = ptSel ? ptSel.value : '';
				var enabled = !!pt;

				if (search) {
					search.disabled = !enabled;
					search.value = '';
				}
				if (results) {
					results.disabled = true;
					results.innerHTML = '<option value="">—</option>';
				}
				if (loadBtn) loadBtn.disabled = !enabled;

				var wrap = $id('myls-mi-post-table-wrap');
				if (wrap) wrap.innerHTML = '';
			}

			var doSearch = debounce(function(){
				var ptSel = $id('myls-mi-post-type');
				var search = $id('myls-mi-post-search');
				var results = $id('myls-mi-post-results');
				if (!ptSel || !search || !results) return;

				var pt = ptSel.value;
				var q  = search.value.trim();
				if (!pt || q.length < 2) {
					results.innerHTML = '<option value="">—</option>';
					results.disabled = true;
					return;
				}

				results.innerHTML = '<option value="">Searching…</option>';
				results.disabled = true;

				postAjax('myls_media_search_posts', { post_type: pt, search: q }).then(function(data){
					var posts = (data && data.posts) || [];
					if (!posts.length) {
						results.innerHTML = '<option value="">No matches</option>';
						results.disabled = true;
						return;
					}
					var html = '<option value="">— Select a post —</option>';
					for (var i = 0; i < posts.length; i++) {
						html += '<option value="' + escapeHtml(posts[i].id) + '">' + escapeHtml(posts[i].title) + ' (#' + escapeHtml(posts[i].id) + ')</option>';
					}
					results.innerHTML = html;
					results.disabled = false;
				}).catch(function(err){
					results.innerHTML = '<option value="">(error)</option>';
					showError('myls-mi-post-table-wrap', err.message);
				});
			}, 300);

			function onPostSelected(){
				var results = $id('myls-mi-post-results');
				if (!results) return;
				var pid = parseInt(results.value, 10);
				if (!pid) {
					var wrap = $id('myls-mi-post-table-wrap');
					if (wrap) wrap.innerHTML = '';
					return;
				}
				showLoading('myls-mi-post-table-wrap');
				postAjax('myls_media_get_post_attachments', { post_id: String(pid) }).then(function(data){
					renderTable('myls-mi-post-table-wrap', (data && data.rows) || [], { total: data && data.count });
				}).catch(function(err){
					showError('myls-mi-post-table-wrap', err.message);
				});
			}

			function onLoadPostType(){
				var ptSel = $id('myls-mi-post-type');
				if (!ptSel || !ptSel.value) return;
				showLoading('myls-mi-posttype-table-wrap');
				postAjax('myls_media_get_posttype_attachments', { post_type: ptSel.value }).then(function(data){
					renderTable('myls-mi-posttype-table-wrap', (data && data.rows) || [], {
						total:  data && data.total,
						capped: data && data.capped
					});
				}).catch(function(err){
					showError('myls-mi-posttype-table-wrap', err.message);
				});
			}

			/* ---- Section C: Full library filters ---- */

			function loadUploaders(){
				var sel = $id('myls-mi-full-uploader');
				if (!sel) return;
				postAjax('myls_media_get_upload_users', {}).then(function(data){
					var users = (data && data.users) || [];
					var html = '<option value="">— Any user —</option>';
					for (var i = 0; i < users.length; i++) {
						html += '<option value="' + escapeHtml(users[i].id) + '">' + escapeHtml(users[i].name) + '</option>';
					}
					sel.innerHTML = html;
				}).catch(function(){
					sel.innerHTML = '<option value="">(error loading users)</option>';
				});
			}

			function loadFullParentTypes(){
				var sel = $id('myls-mi-full-parent-type');
				if (!sel) return;
				postAjax('myls_media_get_post_types', {}).then(function(data){
					var types = (data && data.types) || [];
					var html = '<option value="">— Any / select type —</option>';
					for (var i = 0; i < types.length; i++) {
						html += '<option value="' + escapeHtml(types[i].value) + '">' + escapeHtml(types[i].label) + '</option>';
					}
					sel.innerHTML = html;
				}).catch(function(){
					sel.innerHTML = '<option value="">(error)</option>';
				});
			}

			function onFullParentTypeChange(){
				var ptSel   = $id('myls-mi-full-parent-type');
				var search  = $id('myls-mi-full-parent-search');
				var results = $id('myls-mi-full-parent-results');

				var pt = ptSel ? ptSel.value : '';
				var enabled = !!pt;

				if (search) {
					search.disabled = !enabled;
					search.value = '';
				}
				if (results) {
					results.disabled = true;
					results.innerHTML = '<option value="">—</option>';
				}
			}

			var doFullParentSearch = debounce(function(){
				var ptSel   = $id('myls-mi-full-parent-type');
				var search  = $id('myls-mi-full-parent-search');
				var results = $id('myls-mi-full-parent-results');
				if (!ptSel || !search || !results) return;

				var pt = ptSel.value;
				var q  = search.value.trim();
				if (!pt || q.length < 2) {
					results.innerHTML = '<option value="">—</option>';
					results.disabled = true;
					return;
				}

				results.innerHTML = '<option value="">Searching…</option>';
				results.disabled = true;

				postAjax('myls_media_search_posts', { post_type: pt, search: q }).then(function(data){
					var posts = (data && data.posts) || [];
					if (!posts.length) {
						results.innerHTML = '<option value="">No matches</option>';
						results.disabled = true;
						return;
					}
					var html = '<option value="">— Select a post —</option>';
					for (var i = 0; i < posts.length; i++) {
						html += '<option value="' + escapeHtml(posts[i].id) + '">' + escapeHtml(posts[i].title) + ' (#' + escapeHtml(posts[i].id) + ')</option>';
					}
					results.innerHTML = html;
					results.disabled = false;
				}).catch(function(){
					results.innerHTML = '<option value="">(error)</option>';
				});
			}, 300);

			function clearFullFilters(){
				var u  = $id('myls-mi-full-uploader');
				var fn = $id('myls-mi-full-filename');
				var pt = $id('myls-mi-full-parent-type');
				var ps = $id('myls-mi-full-parent-search');
				var pr = $id('myls-mi-full-parent-results');
				if (u)  u.value = '';
				if (fn) fn.value = '';
				if (pt) pt.value = '';
				if (ps) { ps.value = ''; ps.disabled = true; }
				if (pr) { pr.innerHTML = '<option value="">—</option>'; pr.disabled = true; }
				var active = $id('myls-mi-full-active-filters');
				if (active) active.textContent = '';
			}

			function describeActiveFilters(params){
				var parts = [];
				if (params.uploader_id) {
					var uSel = $id('myls-mi-full-uploader');
					var uTxt = uSel && uSel.options[uSel.selectedIndex] ? uSel.options[uSel.selectedIndex].text : ('user #' + params.uploader_id);
					parts.push('Uploaded By: ' + uTxt);
				}
				if (params.file_name) parts.push('Filename contains: "' + params.file_name + '"');
				if (params.parent_id) {
					var pSel = $id('myls-mi-full-parent-results');
					var pTxt = pSel && pSel.options[pSel.selectedIndex] ? pSel.options[pSel.selectedIndex].text : ('post #' + params.parent_id);
					parts.push('Assigned To: ' + pTxt);
				}
				return parts.length ? ('Filters — ' + parts.join(' · ')) : '';
			}

			function onLoadFull(){
				var uSel = $id('myls-mi-full-uploader');
				var fn   = $id('myls-mi-full-filename');
				var pr   = $id('myls-mi-full-parent-results');

				var params = {
					uploader_id: (uSel && uSel.value) ? uSel.value : '0',
					file_name:   (fn && fn.value) ? fn.value.trim() : '',
					parent_id:   (pr && pr.value) ? pr.value : '0'
				};

				var active = $id('myls-mi-full-active-filters');
				if (active) active.textContent = describeActiveFilters(params);

				showLoading('myls-mi-full-table-wrap');
				postAjax('myls_media_get_full_library', params).then(function(data){
					renderTable('myls-mi-full-table-wrap', (data && data.rows) || [], {
						total:  data && data.total,
						capped: data && data.capped
					});
				}).catch(function(err){
					showError('myls-mi-full-table-wrap', err.message);
				});
			}

			// Init on DOM ready
			function init(){
				var ptSel   = $id('myls-mi-post-type');
				var search  = $id('myls-mi-post-search');
				var results = $id('myls-mi-post-results');
				var loadPT  = $id('myls-mi-load-posttype');
				var loadAll = $id('myls-mi-load-full');

				if (ptSel)   ptSel.addEventListener('change', onPostTypeChange);
				if (search)  search.addEventListener('input', doSearch);
				if (results) results.addEventListener('change', onPostSelected);
				if (loadPT)  loadPT.addEventListener('click', onLoadPostType);
				if (loadAll) loadAll.addEventListener('click', onLoadFull);

				// Section C filter wiring
				var fullParentType   = $id('myls-mi-full-parent-type');
				var fullParentSearch = $id('myls-mi-full-parent-search');
				var fullClear        = $id('myls-mi-full-clear-filters');
				if (fullParentType)   fullParentType.addEventListener('change', onFullParentTypeChange);
				if (fullParentSearch) fullParentSearch.addEventListener('input', doFullParentSearch);
				if (fullClear)        fullClear.addEventListener('click', clearFullFilters);

				loadPostTypes();
				loadUploaders();
				loadFullParentTypes();
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>
		<?php
	},
);
