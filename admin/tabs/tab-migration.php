<?php
/**
 * Admin Tab: Migration (Posts + Meta + Optional Elementor Templates + Debug)
 *
 * Location: admin/tabs/tab-migration.php
 *
 * Features:
 * - Export: Download JSON with posts + all post meta.
 * - Import: Upload JSON to create/update posts on another site.
 *   • Option to strip Elementor meta on import (content-only mode).
 *   • Option to rebuild Elementor layout after import via Elementor's own API.
 * - Optional: Export Elementor Templates (elementor_library) if needed.
 * - Debug: Inspect Elementor meta for a specific post ID on THIS site
 *          and display the full _elementor_data string.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export posts + meta as JSON and force download.
 *
 * @param string $post_type
 * @param string $post_status
 */
function myls_migration_do_export( $post_type, $post_status = 'any' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export posts.', 'aintelligize' ) );
	}

	$post_type   = sanitize_key( $post_type );
	$post_status = ( 'any' === $post_status ) ? 'any' : sanitize_key( $post_status );

	$posts = get_posts( [
		'post_type'      => $post_type,
		'post_status'    => $post_status,
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'all',
	] );

	$export = [];

	foreach ( $posts as $p ) {
		// Core post fields.
		$post_data = [
			'ID'           => (int) $p->ID,
			'post_title'   => $p->post_title,
			'post_name'    => $p->post_name,
			'post_content' => $p->post_content,
			'post_excerpt' => $p->post_excerpt,
			'post_type'    => $p->post_type,
			'post_status'  => $p->post_status,
			'post_date'    => $p->post_date,
			'post_parent'  => (int) $p->post_parent,
			'menu_order'   => (int) $p->menu_order,
		];

		// Raw meta export – no unserialize / special cases.
		$meta      = [];
		$meta_keys = array_keys( get_post_meta( $p->ID ) );

		foreach ( $meta_keys as $key ) {
			// get_post_meta with $single = false gives raw stored values.
			$values       = get_post_meta( $p->ID, $key, false );
			$meta[ $key ] = $values;
		}

		$export[] = [
			'post' => $post_data,
			'meta' => $meta,
		];
	}

	$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( false === $json ) {
		wp_die( esc_html__( 'Failed to encode export JSON.', 'aintelligize' ) );
	}

	$blog     = sanitize_title( get_bloginfo( 'name' ) );
	$pt       = $post_type;
	$stamp    = gmdate( 'Ymd-His' );
	$filename = "myls-{$blog}-{$pt}-posts-meta-{$stamp}.json";

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . strlen( $json ) );

	echo $json;
	exit;
}

/**
 * Export handler hooked to admin-post.php
 */
function myls_migration_export_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export posts.', 'aintelligize' ) );
	}

	check_admin_referer( 'myls_migration_export' );

	$post_type   = isset( $_POST['myls_export_post_type'] ) ? sanitize_key( $_POST['myls_export_post_type'] ) : 'post';
	$post_status = isset( $_POST['myls_export_post_status'] ) ? sanitize_key( $_POST['myls_export_post_status'] ) : 'any';

	myls_migration_do_export( $post_type, $post_status );
}
add_action( 'admin_post_myls_migration_export', 'myls_migration_export_handler' );

/**
 * Try to rebuild an Elementor layout for a given post ID using Elementor's DB API.
 *
 * @param int   $post_id       The imported post ID.
 * @param mixed $elementor_raw Raw _elementor_data string from post meta.
 */
function myls_migration_rebuild_elementor_layout( $post_id, $elementor_raw ) {
	// Bail if Elementor isn't loaded.
	if ( ! did_action( 'elementor/loaded' ) ) {
		return;
	}

	if ( ! is_string( $elementor_raw ) || '' === trim( $elementor_raw ) ) {
		return;
	}

	// Decode the JSON string into an array of elements.
	$decoded = json_decode( $elementor_raw, true );

	if ( ! is_array( $decoded ) ) {
		return;
	}

	try {
		// Use Elementor's DB layer to save the editor data.
		// This should cause Elementor to:
		// - Validate the element tree
		// - Persist _elementor_data in its expected format
		// - Regenerate CSS and internal structures
		if ( isset( \Elementor\Plugin::$instance ) && isset( \Elementor\Plugin::$instance->db ) ) {
			\Elementor\Plugin::$instance->db->save_editor( $post_id, $decoded );
		}
	} catch ( \Throwable $e ) {
		// Optional: log error for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'MYLS Migration: Elementor rebuild failed for post ' . $post_id . ' – ' . $e->getMessage() );
		}
	}
}

/*
 * Import posts + meta from JSON file.
 *
 * - Matches by slug (post_name) + post_type, updates if found, otherwise inserts.
 * - Copies post_content and ALL meta values exactly as exported.
 * - Optional: strip Elementor meta on import (content-only mode).
 * - Optional: rebuild Elementor layout via Elementor's DB API.
 *
 * @return array
 */
function myls_migration_do_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return [
			'type'    => 'error',
			'message' => esc_html__( 'You do not have permission to import posts.', 'aintelligize' ),
		];
	}

	if ( empty( $_FILES['myls_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['myls_import_file']['tmp_name'] ) ) {
		return [
			'type'    => 'error',
			'message' => esc_html__( 'No file uploaded or upload failed.', 'aintelligize' ),
		];
	}

	$file = $_FILES['myls_import_file']['tmp_name'];
	$raw  = file_get_contents( $file );

	if ( false === $raw ) {
		return [
			'type'    => 'error',
			'message' => esc_html__( 'Could not read uploaded file.', 'aintelligize' ),
		];
	}

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) ) {
		return [
			'type'    => 'error',
			'message' => esc_html__( 'Invalid JSON format in uploaded file.', 'aintelligize' ),
		];
	}

	// Checkbox: strip Elementor meta on import (content-only mode).
	$strip_elementor   = ! empty( $_POST['myls_strip_elementor'] );
	// Checkbox: rebuild Elementor layout after import via Elementor API.
	$rebuild_elementor = ! empty( $_POST['myls_rebuild_elementor'] );

	$created = 0;
	$updated = 0;

	foreach ( $data as $entry ) {
		if ( empty( $entry['post'] ) || ! is_array( $entry['post'] ) ) {
			continue;
		}

		$post = $entry['post'];
		$meta = ( ! empty( $entry['meta'] ) && is_array( $entry['meta'] ) ) ? $entry['meta'] : [];

		$post_type    = isset( $post['post_type'] )    ? sanitize_key( $post['post_type'] )    : 'post';
		$post_name    = isset( $post['post_name'] )    ? sanitize_title( $post['post_name'] )  : '';
		$post_title   = isset( $post['post_title'] )   ? $post['post_title']                   : '';
		$post_content = isset( $post['post_content'] ) ? $post['post_content']                 : '';
		$post_status  = isset( $post['post_status'] )  ? sanitize_key( $post['post_status'] )  : 'draft';
		$post_parent  = isset( $post['post_parent'] )  ? (int) $post['post_parent']            : 0;
		$menu_order   = isset( $post['menu_order'] )   ? (int) $post['menu_order']             : 0;
		$post_excerpt = isset( $post['post_excerpt'] ) ? $post['post_excerpt']                 : '';
		$post_date    = isset( $post['post_date'] )    ? $post['post_date']                    : current_time( 'mysql' );

		// Find existing by slug + post_type.
		$existing = null;
		if ( $post_name ) {
			$existing = get_page_by_path( $post_name, OBJECT, $post_type );
		}

		$args = [
			'post_title'   => wp_slash( $post_title ),
			'post_name'    => $post_name,
			'post_content' => wp_slash( $post_content ),
			'post_excerpt' => wp_slash( $post_excerpt ),
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'post_parent'  => $post_parent,
			'menu_order'   => $menu_order,
			'post_date'    => $post_date,
		];

		if ( $existing && $existing->ID ) {
			$args['ID'] = $existing->ID;
			$new_id     = wp_update_post( $args, true );
			if ( ! is_wp_error( $new_id ) ) {
				$updated++;
			}
		} else {
			$new_id = wp_insert_post( $args, true );
			if ( ! is_wp_error( $new_id ) ) {
				$created++;
			}
		}

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			continue;
		}

		// Track raw _elementor_data we import so we can feed it back to Elementor.
		$imported_elementor_raw = null;

		// Import ALL meta exactly as exported (unless we strip Elementor).
		if ( ! empty( $meta ) ) {
			foreach ( $meta as $key => $values ) {
				$key = (string) $key;

				// Optionally strip Elementor meta on import.
				if ( $strip_elementor && 0 === strpos( $key, '_elementor_' ) ) {
					continue;
				}

				// Capture the raw _elementor_data value for rebuild step.
				if ( '_elementor_data' === $key ) {
					// $values is array of raw DB values; take first.
					if ( is_array( $values ) && ! empty( $values ) ) {
						$imported_elementor_raw = reset( $values );
					} elseif ( is_string( $values ) ) {
						$imported_elementor_raw = $values;
					}
				}

				// Remove existing values for this key.
				delete_post_meta( $new_id, $key );

				if ( ! is_array( $values ) ) {
					$values = [ $values ];
				}

				foreach ( $values as $val ) {
					// Store raw value – no unserialize.
					add_post_meta( $new_id, $key, $val );
				}
			}
		}

		// If requested, try to rebuild Elementor layout via Elementor's DB API.
		if ( $rebuild_elementor && ! $strip_elementor && $imported_elementor_raw ) {
			myls_migration_rebuild_elementor_layout( $new_id, $imported_elementor_raw );
		}
	}

	$message = sprintf(
		esc_html__( 'Import complete: %1$d items created, %2$d items updated.', 'aintelligize' ),
		$created,
		$updated
	);

	return [
		'type'    => 'success',
		'message' => $message,
	];
}

/**
 * Debug helper: Inspect Elementor-related meta for a given post ID.
 *
 * @param int $post_id
 * @return array
 */
function myls_migration_debug_post( $post_id ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return [
			'type'    => 'error',
			'message' => esc_html__( 'You do not have permission to debug posts.', 'aintelligize' ),
		];
	}

	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return [
			'type'    => 'error',
			'message' => esc_html__( 'Invalid post ID.', 'aintelligize' ),
		];
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return [
			'type'    => 'error',
			'message' => esc_html__( 'Post not found.', 'aintelligize' ),
		];
	}

	$keys_to_check = [
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_version',
		'_elementor_pro_version',
		'_wp_page_template',
	];

	$meta = [];
	foreach ( $keys_to_check as $k ) {
		$meta[ $k ] = get_post_meta( $post_id, $k, true );
	}

	// FULL _elementor_data string (no truncation).
	$elementor_data = get_post_meta( $post_id, '_elementor_data', true );
	$len            = is_string( $elementor_data ) ? strlen( $elementor_data ) : 0;

	return [
		'type'               => 'success',
		'post'               => $post,
		'meta'               => $meta,
		'elementor_data_len' => $len,
		'elementor_data'     => $elementor_data,
	];
}

/**
 * Register the Migration tab.
 */
myls_register_admin_tab( [
	'id'    => 'migration',
	'title' => 'Migration',
	'order' => 80,
	'cap'   => 'manage_options',
	'icon'  => 'dashicons-migrate',
	'cb'    => function () {

		$import_notice = null;
		$debug_result  = null;

		if ( isset( $_POST['myls_migration_action'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['myls_migration_action'] ) );

			if ( 'import' === $action ) {
				check_admin_referer( 'myls_migration_import' );
				$import_notice = myls_migration_do_import();
			} elseif ( 'debug' === $action ) {
				check_admin_referer( 'myls_migration_debug' );
				$post_id      = isset( $_POST['myls_debug_post_id'] ) ? absint( $_POST['myls_debug_post_id'] ) : 0;
				$debug_result = myls_migration_debug_post( $post_id );
			}
		}

		$public_types = get_post_types( [ 'public' => true ], 'objects' );
		$elementor_templates_available = post_type_exists( 'elementor_library' );
		?>
		<div class="wrap myls-admin-wrap myls-migration-wrap">
			<h1><?php esc_html_e( 'Migration: Posts + Post Meta', 'aintelligize' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Move posts and associated post meta (Elementor, ACF, Yoast, etc.) between sites without migrating themes, plugins, or options.', 'aintelligize' ); ?>
			</p>

			<?php if ( $import_notice ) : ?>
				<div class="notice notice-<?php echo ( 'success' === $import_notice['type'] ) ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo esc_html( $import_notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="container-fluid myls-admin-tab-content myls-migration-tab mt-3">
				<div class="row g-4">
					<!-- Export -->
					<div class="col-lg-6">
						<div class="card shadow-sm">
							<div class="card-header">
								<h2 class="h4 mb-0"><?php esc_html_e( 'Export Posts + Meta', 'aintelligize' ); ?></h2>
							</div>
							<div class="card-body">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'myls_migration_export' ); ?>
									<input type="hidden" name="action" value="myls_migration_export" />

									<div class="mb-3">
										<label for="myls_export_post_type" class="form-label">
											<?php esc_html_e( 'Post Type', 'aintelligize' ); ?>
										</label>
										<select name="myls_export_post_type" id="myls_export_post_type" class="form-select">
											<?php foreach ( $public_types as $slug => $obj ) : ?>
												<option value="<?php echo esc_attr( $slug ); ?>">
													<?php echo esc_html( $obj->labels->singular_name . " ({$slug})" ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>

									<div class="mb-3">
										<label for="myls_export_post_status" class="form-label">
											<?php esc_html_e( 'Post Status', 'aintelligize' ); ?>
										</label>
										<select name="myls_export_post_status" id="myls_export_post_status" class="form-select">
											<option value="any"><?php esc_html_e( 'Any (publish, draft, etc.)', 'aintelligize' ); ?></option>
											<option value="publish"><?php esc_html_e( 'Published only', 'aintelligize' ); ?></option>
											<option value="draft"><?php esc_html_e( 'Drafts only', 'aintelligize' ); ?></option>
											<option value="private"><?php esc_html_e( 'Private only', 'aintelligize' ); ?></option>
										</select>
									</div>

									<button type="submit" class="button button-primary">
										<?php esc_html_e( 'Download JSON Export', 'aintelligize' ); ?>
									</button>

									<p class="mt-3 small text-muted">
										<?php esc_html_e( 'Media files are not moved by this tool. Use a media migration/backup if needed.', 'aintelligize' ); ?>
									</p>
								</form>
							</div>
						</div>
					</div>

					<!-- Import -->
					<div class="col-lg-6">
						<div class="card shadow-sm">
							<div class="card-header">
								<h2 class="h4 mb-0"><?php esc_html_e( 'Import Posts + Meta', 'aintelligize' ); ?></h2>
							</div>
							<div class="card-body">
								<form method="post" enctype="multipart/form-data">
									<?php wp_nonce_field( 'myls_migration_import' ); ?>
									<input type="hidden" name="myls_migration_action" value="import" />

									<div class="mb-3">
										<label for="myls_import_file" class="form-label">
											<?php esc_html_e( 'JSON File', 'aintelligize' ); ?>
										</label>
										<input type="file" name="myls_import_file" id="myls_import_file" class="form-control" accept="application/json" required />
									</div>

									<div class="mb-2 form-check">
										<input type="checkbox" class="form-check-input" id="myls_strip_elementor" name="myls_strip_elementor" value="1" />
										<label class="form-check-label" for="myls_strip_elementor">
											<?php esc_html_e( 'Strip Elementor meta on import (content-only mode)', 'aintelligize' ); ?>
										</label>
										<div class="form-text">
											<?php esc_html_e( 'If checked, _elementor_* meta will NOT be imported. Use this if Elementor layouts from another site are causing blank or broken canvases and you only want the content.', 'aintelligize' ); ?>
										</div>
									</div>

									<div class="mb-3 form-check">
										<input type="checkbox" class="form-check-input" id="myls_rebuild_elementor" name="myls_rebuild_elementor" value="1" />
										<label class="form-check-label" for="myls_rebuild_elementor">
											<?php esc_html_e( 'Rebuild Elementor layout after import (experimental)', 'aintelligize' ); ?>
										</label>
										<div class="form-text">
											<?php esc_html_e( 'If checked (and Elementor is active), the imported _elementor_data JSON will be handed to Elementor\'s DB API (save_editor) to regenerate the layout on this site.', 'aintelligize' ); ?>
										</div>
									</div>

									<button type="submit" class="button button-primary">
										<?php esc_html_e( 'Run Import', 'aintelligize' ); ?>
									</button>
								</form>
							</div>
						</div>
					</div>
				</div><!-- .row -->

				<?php if ( $elementor_templates_available ) : ?>
					<hr class="my-4" />
					<div class="row g-4">
						<div class="col-lg-6">
							<div class="card shadow-sm">
								<div class="card-header">
									<h2 class="h4 mb-0"><?php esc_html_e( 'Elementor Templates (Optional)', 'aintelligize' ); ?></h2>
								</div>
								<div class="card-body">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( 'myls_migration_export' ); ?>
										<input type="hidden" name="action" value="myls_migration_export" />
										<input type="hidden" name="myls_export_post_type" value="elementor_library" />

										<div class="mb-3">
											<label for="myls_export_tpl_status" class="form-label">
												<?php esc_html_e( 'Template Status', 'aintelligize' ); ?>
											</label>
											<select name="myls_export_post_status" id="myls_export_tpl_status" class="form-select">
												<option value="any"><?php esc_html_e( 'Any', 'aintelligize' ); ?></option>
												<option value="publish"><?php esc_html_e( 'Published only', 'aintelligize' ); ?></option>
												<option value="draft"><?php esc_html_e( 'Drafts only', 'aintelligize' ); ?></option>
											</select>
										</div>

										<button type="submit" class="button">
											<?php esc_html_e( 'Download Elementor Templates JSON', 'aintelligize' ); ?>
										</button>
									</form>
									<p class="mt-2 small text-muted">
										<?php esc_html_e( 'For this project you can usually skip this and build new Theme Builder layouts on the target site.', 'aintelligize' ); ?>
									</p>
								</div>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<hr class="my-4" />

				<!-- DEBUG SECTION -->
				<div class="row g-4">
					<div class="col-lg-6">
						<div class="card shadow-sm">
							<div class="card-header">
								<h2 class="h4 mb-0"><?php esc_html_e( 'Debug Elementor Meta (Target Site)', 'aintelligize' ); ?></h2>
							</div>
							<div class="card-body">
								<p class="small">
									<?php esc_html_e( 'After importing, enter the Post ID of an imported page on THIS site to see its Elementor-related meta and the full _elementor_data string.', 'aintelligize' ); ?>
								</p>
								<form method="post">
									<?php wp_nonce_field( 'myls_migration_debug' ); ?>
									<input type="hidden" name="myls_migration_action" value="debug" />

									<div class="mb-3">
										<label for="myls_debug_post_id" class="form-label">
											<?php esc_html_e( 'Post ID', 'aintelligize' ); ?>
										</label>
										<input type="number" class="form-control" name="myls_debug_post_id" id="myls_debug_post_id" min="1" step="1" required />
										<div class="form-text">
											<?php esc_html_e( 'Example: /wp-admin/post.php?post=123&action=edit → ID is 123.', 'aintelligize' ); ?>
										</div>
									</div>

									<button type="submit" class="button">
										<?php esc_html_e( 'Inspect Meta', 'aintelligize' ); ?>
									</button>
								</form>
							</div>
						</div>
					</div>

					<?php if ( $debug_result ) : ?>
						<div class="col-lg-6">
							<div class="card shadow-sm">
								<div class="card-header">
									<h2 class="h4 mb-0"><?php esc_html_e( 'Debug Results', 'aintelligize' ); ?></h2>
								</div>
								<div class="card-body">
									<?php if ( 'error' === $debug_result['type'] ) : ?>
										<div class="notice notice-error inline">
											<p><?php echo esc_html( $debug_result['message'] ); ?></p>
										</div>
									<?php else : ?>
										<?php $dbg_post = $debug_result['post']; ?>
										<p><strong><?php esc_html_e( 'Post:', 'aintelligize' ); ?></strong>
											<?php
											printf(
												'#%1$d – %2$s (%3$s)',
												(int) $dbg_post->ID,
												esc_html( $dbg_post->post_title ),
												esc_html( $dbg_post->post_type )
											);
											?>
										</p>

										<ul class="mb-3">
											<li><strong>_elementor_edit_mode:</strong> <?php echo esc_html( $debug_result['meta']['_elementor_edit_mode'] ); ?></li>
											<li><strong>_elementor_template_type:</strong> <?php echo esc_html( $debug_result['meta']['_elementor_template_type'] ); ?></li>
											<li><strong>_elementor_version:</strong> <?php echo esc_html( $debug_result['meta']['_elementor_version'] ); ?></li>
											<li><strong>_elementor_pro_version:</strong> <?php echo esc_html( $debug_result['meta']['_elementor_pro_version'] ); ?></li>
											<li><strong>_wp_page_template:</strong> <?php echo esc_html( $debug_result['meta']['_wp_page_template'] ); ?></li>
											<li><strong>_elementor_data length:</strong> <?php echo (int) $debug_result['elementor_data_len']; ?></li>
										</ul>

										<label class="form-label">
											<?php esc_html_e( 'Full _elementor_data (raw)', 'aintelligize' ); ?>
										</label>
										<textarea class="widefat code" rows="18" readonly><?php
											echo esc_textarea( $debug_result['elementor_data'] );
										?></textarea>

										<p class="mt-2 small text-muted">
											<?php esc_html_e( 'You can copy this entire string, save it to a file, or paste it into a JSON validator. Comparing source vs target _elementor_data should reveal any corruption or differences.', 'aintelligize' ); ?>
										</p>
									<?php endif; ?>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</div><!-- .row debug -->
			</div><!-- .container-fluid -->
		</div><!-- .wrap -->
		<?php
	},
] );
