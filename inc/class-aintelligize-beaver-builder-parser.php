<?php
/**
 * AIntelligize — Beaver Builder Parser
 *
 * Reference implementation. Mirrors the role of the existing Elementor parser
 * but reads/writes against Beaver Builder's storage model.
 *
 * Storage model recap (stable since BB 2.0):
 *   _fl_builder_enabled          → '1' if BB is active on the post
 *   _fl_builder_data             → published layout (PHP-serialized, flat dict of stdClass nodes)
 *   _fl_builder_data_settings    → published global settings (PHP-serialized)
 *   _fl_builder_draft            → draft layout
 *   _fl_builder_draft_settings   → draft global settings
 *
 * Each node is a stdClass with at minimum:
 *   ->node      (string ID)
 *   ->type      ('row' | 'column' | 'module' | 'column-group')
 *   ->parent    (parent node ID or null)
 *   ->position  (int)
 *   ->settings  (stdClass with module-specific fields; ->settings->type = module slug)
 *
 * @package AIntelligize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIntelligize_Beaver_Builder_Parser {

	/**
	 * Minimum supported Beaver Builder version.
	 */
	const MIN_BB_VERSION = '2.0';

	/**
	 * Module type aliases. Add new BB module slugs here as you encounter them.
	 * Order matters within each list: canonical name first, legacy aliases after.
	 */
	private $heading_module_types = array( 'heading' );

	private $text_module_types = array(
		'rich-text',
		'text-editor', // legacy alias seen in older layouts
	);

	private $button_module_types = array(
		'button',
		'cta', // call-to-action sometimes used as a button proxy
	);

	private $image_module_types = array(
		'photo',
		'image',
	);

	private $video_module_types = array(
		'video',
	);

	private $accordion_module_types = array(
		'accordion',
		'fl-accordion',
	);

	private $list_module_types = array(
		'icon-group',
		'list-icon',
	);

	/* -----------------------------------------------------------------
	 * Environment detection
	 * ----------------------------------------------------------------- */

	/**
	 * Is the Beaver Builder plugin active?
	 */
	public function is_bb_plugin_active() {
		return class_exists( 'FLBuilder' ) || defined( 'FL_BUILDER_VERSION' );
	}

	/**
	 * Is the Beaver Builder Theme (parent or child) active?
	 */
	public function is_bb_theme_active() {
		$theme = wp_get_theme();
		if ( ! $theme ) {
			return false;
		}
		// Match either the parent theme or any child of bb-theme.
		if ( $theme->get( 'Name' ) === 'Beaver Builder Theme' ) {
			return true;
		}
		if ( $theme->get( 'Template' ) === 'bb-theme' ) {
			return true;
		}
		// Defensive: stylesheet check for child themes that override Template.
		return ( $theme->get_stylesheet() === 'bb-theme' || $theme->get_template() === 'bb-theme' );
	}

	/**
	 * Is Beaver Themer active? (Themer layouts live on fl-theme-layout post type.)
	 */
	public function is_bb_themer_active() {
		return class_exists( 'FLThemeBuilder' );
	}

	/**
	 * Detected BB plugin version string, or '0.0.0' if unknown.
	 */
	public function get_bb_version() {
		return defined( 'FL_BUILDER_VERSION' ) ? FL_BUILDER_VERSION : '0.0.0';
	}

	/**
	 * Detected BB Theme version string, or '0.0.0' if not active.
	 */
	public function get_bb_theme_version() {
		$theme = wp_get_theme();
		if ( ! $theme ) {
			return '0.0.0';
		}
		if ( $theme->get( 'Template' ) === 'bb-theme' ) {
			$parent = wp_get_theme( 'bb-theme' );
			return $parent ? $parent->get( 'Version' ) : '0.0.0';
		}
		if ( $theme->get( 'Name' ) === 'Beaver Builder Theme' ) {
			return $theme->get( 'Version' );
		}
		return '0.0.0';
	}

	/**
	 * Best-effort edition detection. Don't gate critical features on this.
	 */
	public function get_bb_edition() {
		if ( ! $this->is_bb_plugin_active() ) {
			return 'none';
		}
		if ( class_exists( 'FLBuilderUserAccess' ) ) {
			return 'pro_or_agency';
		}
		return 'lite';
	}

	/**
	 * Hard floor. Returns true if installed BB is new enough to parse safely.
	 */
	public function is_supported_version() {
		return version_compare( $this->get_bb_version(), self::MIN_BB_VERSION, '>=' );
	}

	/**
	 * Snapshot of the current environment for the admin status panel.
	 */
	public function get_environment_status() {
		return array(
			'bb_plugin_active'   => $this->is_bb_plugin_active(),
			'bb_plugin_version'  => $this->get_bb_version(),
			'bb_edition'         => $this->get_bb_edition(),
			'bb_theme_active'    => $this->is_bb_theme_active(),
			'bb_theme_version'   => $this->get_bb_theme_version(),
			'bb_themer_active'   => $this->is_bb_themer_active(),
			'is_child_theme'     => is_child_theme(),
			'child_theme_name'   => is_child_theme() ? wp_get_theme()->get( 'Name' ) : '',
			'supported_version'  => $this->is_supported_version(),
		);
	}

	/* -----------------------------------------------------------------
	 * Per-post checks
	 * ----------------------------------------------------------------- */

	/**
	 * Is BB enabled on this specific post?
	 */
	public function is_bb_enabled( $post_id ) {
		return get_post_meta( $post_id, '_fl_builder_enabled', true ) === '1';
	}

	/* -----------------------------------------------------------------
	 * Layout retrieval
	 * ----------------------------------------------------------------- */

	/**
	 * Get the published layout data for a post as a flat dict of node ID => stdClass.
	 * Returns empty array on failure or if BB is not enabled.
	 */
	public function get_layout_data( $post_id ) {
		if ( ! $this->is_bb_enabled( $post_id ) ) {
			return array();
		}
		$raw = get_post_meta( $post_id, '_fl_builder_data', true );
		return $this->safe_unserialize( $raw );
	}

	/**
	 * Get global page settings.
	 */
	public function get_layout_settings( $post_id ) {
		$raw = get_post_meta( $post_id, '_fl_builder_data_settings', true );
		$result = $this->safe_unserialize( $raw );
		return is_object( $result ) ? $result : new stdClass();
	}

	/**
	 * Defensive unserializer. Whitelists expected classes only.
	 *
	 * @return array Always returns array. Empty on failure.
	 */
	private function safe_unserialize( $data ) {
		if ( ! is_string( $data ) || empty( $data ) ) {
			return array();
		}

		// PHP 7.0+ allowed_classes whitelist.
		$result = @unserialize(
			$data,
			array(
				'allowed_classes' => array( 'stdClass', 'FLBuilderModule' ),
			)
		);

		if ( $result === false ) {
			error_log( sprintf( 'AIntelligize BB: failed to unserialize layout data (length=%d)', strlen( $data ) ) );
			return array();
		}

		// BB stores layouts as arrays of stdClass keyed by node ID.
		if ( is_object( $result ) ) {
			$result = (array) $result;
		}

		return is_array( $result ) ? $result : array();
	}

	/* -----------------------------------------------------------------
	 * Hierarchy helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Reconstruct the parent/child hierarchy from the flat dictionary.
	 * Returns root nodes with ->children populated recursively, sorted by ->position.
	 */
	public function build_tree( $post_id ) {
		$nodes = $this->get_layout_data( $post_id );
		if ( empty( $nodes ) ) {
			return array();
		}

		// Initialize children buckets.
		foreach ( $nodes as $id => $node ) {
			$nodes[ $id ]->children = array();
		}

		// Attach children to parents.
		$roots = array();
		foreach ( $nodes as $id => $node ) {
			$parent_id = isset( $node->parent ) ? $node->parent : null;
			if ( $parent_id && isset( $nodes[ $parent_id ] ) ) {
				$nodes[ $parent_id ]->children[] = & $nodes[ $id ];
			} else {
				$roots[] = & $nodes[ $id ];
			}
		}

		// Sort each level by position.
		$sort_fn = function( &$list ) use ( &$sort_fn ) {
			usort( $list, function( $a, $b ) {
				$ap = isset( $a->position ) ? (int) $a->position : 0;
				$bp = isset( $b->position ) ? (int) $b->position : 0;
				return $ap <=> $bp;
			} );
			foreach ( $list as &$node ) {
				if ( ! empty( $node->children ) ) {
					$sort_fn( $node->children );
				}
			}
		};
		$sort_fn( $roots );

		return $roots;
	}

	/* -----------------------------------------------------------------
	 * Module queries
	 * ----------------------------------------------------------------- */

	/**
	 * All module nodes from the layout, in document order (sorted by position within each parent).
	 */
	public function get_modules( $post_id ) {
		$tree = $this->build_tree( $post_id );
		$modules = array();
		$walk = function( $nodes ) use ( &$walk, &$modules ) {
			foreach ( $nodes as $node ) {
				if ( isset( $node->type ) && $node->type === 'module' ) {
					$modules[] = $node;
				}
				if ( ! empty( $node->children ) ) {
					$walk( $node->children );
				}
			}
		};
		$walk( $tree );
		return $modules;
	}

	/**
	 * Modules whose ->settings->type matches one of the given module slugs.
	 *
	 * @param int          $post_id
	 * @param string|array $types Single module slug or array of slugs.
	 */
	public function get_modules_by_type( $post_id, $types ) {
		$types = (array) $types;
		$matches = array();
		foreach ( $this->get_modules( $post_id ) as $module ) {
			$slug = $this->get_setting( $module, 'type' );
			if ( $slug && in_array( $slug, $types, true ) ) {
				$matches[] = $module;
			}
		}
		return $matches;
	}

	/* -----------------------------------------------------------------
	 * Setting access with fallback
	 * ----------------------------------------------------------------- */

	/**
	 * Read a setting from a node, with optional fallback field names for
	 * version compatibility. Returns $default if nothing is set.
	 *
	 * Usage:
	 *   $tag = $parser->get_setting( $node, 'tag', array( 'heading_tag' ), 'h2' );
	 */
	public function get_setting( $node, $field, $fallback_fields = array(), $default = '' ) {
		if ( ! isset( $node->settings ) ) {
			return $default;
		}
		$settings = $node->settings;

		if ( isset( $settings->{$field} ) && $settings->{$field} !== '' ) {
			return $settings->{$field};
		}
		foreach ( (array) $fallback_fields as $alt ) {
			if ( isset( $settings->{$alt} ) && $settings->{$alt} !== '' ) {
				return $settings->{$alt};
			}
		}
		return $default;
	}

	/* -----------------------------------------------------------------
	 * High-level extractors (for schema, audits, AI input)
	 * ----------------------------------------------------------------- */

	/**
	 * Extract headings as array of [ 'tag' => 'h1'..'h6', 'text' => '...' ].
	 * Pulls from heading modules AND from HTML inside text/rich-text modules.
	 */
	public function get_headings( $post_id ) {
		$results = array();

		// 1) Native heading modules.
		foreach ( $this->get_modules_by_type( $post_id, $this->heading_module_types ) as $module ) {
			$tag  = strtolower( $this->get_setting( $module, 'tag', array( 'heading_tag' ), 'h2' ) );
			$text = wp_strip_all_tags( $this->get_setting( $module, 'heading', array( 'text' ), '' ) );
			if ( $text !== '' ) {
				$results[] = array( 'tag' => $tag, 'text' => trim( $text ) );
			}
		}

		// 2) Headings inside text/rich-text modules.
		foreach ( $this->get_modules_by_type( $post_id, $this->text_module_types ) as $module ) {
			$html = $this->get_setting( $module, 'text', array( 'content' ), '' );
			if ( $html === '' ) {
				continue;
			}
			$results = array_merge( $results, $this->extract_headings_from_html( $html ) );
		}

		return $results;
	}

	/**
	 * Concatenated plaintext from all text-bearing modules.
	 * Useful as input for AI summarization, schema description fields, etc.
	 */
	public function get_text_content( $post_id ) {
		$chunks = array();

		foreach ( $this->get_modules( $post_id ) as $module ) {
			$slug = $this->get_setting( $module, 'type' );
			if ( in_array( $slug, $this->heading_module_types, true ) ) {
				$chunks[] = $this->get_setting( $module, 'heading', array( 'text' ), '' );
			} elseif ( in_array( $slug, $this->text_module_types, true ) ) {
				$chunks[] = $this->get_setting( $module, 'text', array( 'content' ), '' );
			} elseif ( in_array( $slug, $this->button_module_types, true ) ) {
				$chunks[] = $this->get_setting( $module, 'text', array( 'label' ), '' );
			}
		}

		$plain = wp_strip_all_tags( implode( "\n\n", array_filter( $chunks ) ) );
		return trim( preg_replace( '/\n{3,}/', "\n\n", $plain ) );
	}

	/**
	 * Extract h1-h6 from an HTML blob using DOMDocument.
	 */
	private function extract_headings_from_html( $html ) {
		$out = array();
		if ( trim( $html ) === '' ) {
			return $out;
		}

		$prev = libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		// UTF-8 wrap to keep multibyte text intact.
		$dom->loadHTML( '<?xml encoding="UTF-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$nodes = $dom->getElementsByTagName( $tag );
			foreach ( $nodes as $node ) {
				$text = trim( $node->textContent );
				if ( $text !== '' ) {
					$out[] = array( 'tag' => $tag, 'text' => $text );
				}
			}
		}
		return $out;
	}

	/* -----------------------------------------------------------------
	 * BB Theme child-theme awareness
	 * ----------------------------------------------------------------- */

	/**
	 * If BB Theme is active, expose theme-layer settings that affect
	 * schema/SEO output (logo, header layout, etc.).
	 *
	 * BB Theme stores its settings under the 'fl-theme' option group.
	 * This is a convenience wrapper — extend as needed.
	 */
	public function get_bb_theme_settings() {
		if ( ! $this->is_bb_theme_active() ) {
			return array();
		}
		$settings = get_option( '_fl_theme_settings', array() );
		return is_array( $settings ) ? $settings : (array) $settings;
	}

	/* =================================================================
	 * LAYOUT WRITER (added by AIntelligize for the "Beaver Builder Builder"
	 * sub-tab — generates and persists native BB layouts from AI output).
	 *
	 * BB hierarchy depth is fixed:  row → column-group → column → module
	 * Storage: a flat dict keyed by node id, each value is a stdClass node.
	 * ================================================================= */

	/**
	 * 8-char hex node ID, mirroring FLBuilderModel::generate_node_id().
	 */
	public function uid() {
		return substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	/**
	 * Make an empty BB-flavoured stdClass for a row.
	 *
	 * @param array $opts settings overrides
	 */
	public function make_row( array $opts = array() ) {
		$node           = new stdClass();
		$node->node     = $this->uid();
		$node->type     = 'row';
		$node->parent   = null;
		$node->position = isset( $opts['position'] ) ? (int) $opts['position'] : 0;
		$node->settings = (object) array_merge(
			array(
				'type'                => 'fixed',           // 'fixed' or 'full-width'
				'width'               => 'fixed',           // legacy alias
				'content_width'       => 'fixed',
				'bg_type'             => 'none',
				'bg_color'            => '',
				'bg_image_src'        => '',
				'full_height'         => 'default',
				'min_height'          => 0,
				'min_height_unit'     => 'px',
				'top_edge_shape'      => 'none',
				'bottom_edge_shape'   => 'none',
				'aintelligize_marker' => 1,                  // marker for downstream patches
			),
			$opts['settings'] ?? array()
		);
		return $node;
	}

	/**
	 * Make a column-group child of $parent_id.
	 */
	public function make_column_group( $parent_id, array $opts = array() ) {
		$node           = new stdClass();
		$node->node     = $this->uid();
		$node->type     = 'column-group';
		$node->parent   = (string) $parent_id;
		$node->position = isset( $opts['position'] ) ? (int) $opts['position'] : 0;
		$node->settings = (object) ( $opts['settings'] ?? array() );
		return $node;
	}

	/**
	 * Make a column child of a column-group.
	 *
	 * @param string|int $parent_id  ID of the parent column-group
	 * @param array      $opts       settings overrides; accepts 'size' (1–100)
	 */
	public function make_column( $parent_id, array $opts = array() ) {
		$node           = new stdClass();
		$node->node     = $this->uid();
		$node->type     = 'column';
		$node->parent   = (string) $parent_id;
		$node->position = isset( $opts['position'] ) ? (int) $opts['position'] : 0;
		$node->settings = (object) array_merge(
			array(
				'size'                => isset( $opts['size'] ) ? (float) $opts['size'] : 100,
				'equal_height'        => 'no',
				'content_alignment'   => 'flex-start',
				'responsive_display'  => 'desktop,medium,responsive',
			),
			$opts['settings'] ?? array()
		);
		return $node;
	}

	/**
	 * Make a module child of a column.
	 *
	 * @param string|int $parent_id  ID of the parent column
	 * @param string     $slug       BB module slug, e.g. 'heading', 'rich-text', 'button', 'photo'
	 * @param array      $settings   module-specific settings (will become ->settings)
	 * @param array      $opts       extra options ('position')
	 */
	public function make_module( $parent_id, $slug, array $settings = array(), array $opts = array() ) {
		$node           = new stdClass();
		$node->node     = $this->uid();
		$node->type     = 'module';
		$node->parent   = (string) $parent_id;
		$node->position = isset( $opts['position'] ) ? (int) $opts['position'] : 0;

		// BB modules embed their slug as ->settings->type.
		$settings['type'] = $slug;

		$node->settings = (object) $settings;
		return $node;
	}

	/**
	 * Convenience: build a single-column row that wraps the supplied modules.
	 * Returns the new nodes (row + column-group + column + each module) keyed
	 * by node ID, ready to merge into a layout dict.
	 *
	 * @param array $modules   already-built module nodes (from make_module)
	 * @param array $row_opts  optional overrides forwarded to make_row
	 *
	 * @return array<string,stdClass>
	 */
	public function wrap_modules_in_row( array $modules, array $row_opts = array() ) {
		$row = $this->make_row( $row_opts );
		$cg  = $this->make_column_group( $row->node, array( 'position' => 0 ) );
		$col = $this->make_column( $cg->node, array( 'size' => 100, 'position' => 0 ) );

		$out = array();
		$out[ $row->node ] = $row;
		$out[ $cg->node  ] = $cg;
		$out[ $col->node ] = $col;

		$pos = 0;
		foreach ( $modules as $module ) {
			// Re-parent the module to this column.
			$module->parent   = $col->node;
			$module->position = $pos++;
			$out[ $module->node ] = $module;
		}

		return $out;
	}

	/**
	 * Convenience: build a multi-column row. $columns is an array of arrays
	 * of pre-built module nodes — outer array length = number of columns.
	 *
	 * @param array $columns  e.g. [ [ $heading_node, $text_node ], [ $img_node ] ]
	 * @param array $row_opts forwarded to make_row
	 *
	 * @return array<string,stdClass>
	 */
	public function wrap_columns_in_row( array $columns, array $row_opts = array() ) {
		$row = $this->make_row( $row_opts );
		$cg  = $this->make_column_group( $row->node, array( 'position' => 0 ) );

		$out = array();
		$out[ $row->node ] = $row;
		$out[ $cg->node  ] = $cg;

		$col_count = max( 1, count( $columns ) );
		$col_size  = round( 100 / $col_count, 4 );

		$col_pos = 0;
		foreach ( $columns as $col_modules ) {
			$col = $this->make_column( $cg->node, array( 'size' => $col_size, 'position' => $col_pos++ ) );
			$out[ $col->node ] = $col;

			$mod_pos = 0;
			foreach ( (array) $col_modules as $module ) {
				$module->parent   = $col->node;
				$module->position = $mod_pos++;
				$out[ $module->node ] = $module;
			}
		}

		return $out;
	}

	/**
	 * Persist a layout to the post.
	 *
	 * - Sets `_fl_builder_enabled = '1'`.
	 * - Writes `_fl_builder_data` AND `_fl_builder_draft` (BB requires both).
	 * - Writes `_fl_builder_data_settings` and `_fl_builder_draft_settings` as
	 *   stdClass (BB will not render without them).
	 *
	 * @param int                    $post_id
	 * @param array<string,stdClass> $nodes            Flat layout dict keyed by node ID.
	 * @param array|stdClass         $global_settings  Optional global page settings.
	 *
	 * @return bool
	 */
	public function save_layout( $post_id, array $nodes, $global_settings = array() ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		// Re-key by ->node to defend against callers that pass numerically-keyed arrays.
		$keyed = array();
		foreach ( $nodes as $n ) {
			if ( is_object( $n ) && isset( $n->node ) ) {
				$keyed[ (string) $n->node ] = $n;
			}
		}

		$settings_obj = is_object( $global_settings )
			? $global_settings
			: (object) ( is_array( $global_settings ) ? $global_settings : array() );

		// wp_slash() because update_post_meta() runs stripslashes() on its values
		// and we want the serialized payload preserved byte-for-byte.
		$serialized_layout   = serialize( $keyed );
		$serialized_settings = serialize( $settings_obj );

		update_post_meta( $post_id, '_fl_builder_enabled',         '1' );
		update_post_meta( $post_id, '_fl_builder_data',            wp_slash( $serialized_layout ) );
		update_post_meta( $post_id, '_fl_builder_draft',           wp_slash( $serialized_layout ) );
		update_post_meta( $post_id, '_fl_builder_data_settings',   wp_slash( $serialized_settings ) );
		update_post_meta( $post_id, '_fl_builder_draft_settings',  wp_slash( $serialized_settings ) );

		// Tag the post so future tooling can recognise AIntelligize-built layouts.
		update_post_meta( $post_id, '_aintelligize_bb_built', 1 );
		update_post_meta( $post_id, '_aintelligize_bb_built_at', current_time( 'mysql' ) );

		// Clear FLBuilder render cache for this post so next view re-renders.
		if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'delete_asset_cache' ) ) {
			try {
				FLBuilderModel::delete_asset_cache( $post_id );
			} catch ( \Throwable $e ) { /* silent */ }
		}

		return true;
	}

	/**
	 * Build a complete BB layout from the AIntelligize section JSON shape.
	 *
	 * The AI returns a JSON object keyed by section name (hero, tldr, trust_bar,
	 * intro, features, rich_content, process, pricing, cta). This method walks
	 * a sections_order list and emits BB rows/columns/modules in that order.
	 *
	 * @param array $sections_data    Decoded AI JSON (associative array).
	 * @param array $sections_order   Ordered list of { id, type, enabled, ... }.
	 * @param array $context          Extra context (page_title, contact_url, ...).
	 *
	 * @return array{ nodes: array<string,stdClass>, section_count: int, tldr_text: string }
	 */
	public function build_layout_from_sections( array $sections_data, array $sections_order, array $context = array() ) {
		$out_nodes      = array();
		$section_count  = 0;
		$tldr_text      = '';
		$contact_url    = $context['contact_url'] ?? '/contact-us/';
		$position       = 0;

		foreach ( $sections_order as $entry ) {
			$id      = $entry['id']      ?? '';
			$type    = $entry['type']    ?? 'section';
			$enabled = ! empty( $entry['enabled'] );
			if ( ! $enabled || ! $id ) {
				continue;
			}

			$row_nodes = array();

			if ( $type === 'template' ) {
				// v1: BB templates can't be cloned losslessly without going through
				// FLBuilderModel::apply_node_template — the parser layer doesn't have
				// access to that public surface in a stable way. Skip silently and
				// log a marker.
				continue;
			}

			switch ( $id ) {
				case 'hero':
					$row_nodes = $this->build_hero_row( $sections_data['hero'] ?? array(), $contact_url );
					break;
				case 'tldr':
					$tldr_text = trim( (string) ( $sections_data['tldr']['text'] ?? '' ) );
					$row_nodes = $this->build_tldr_row( $sections_data['tldr'] ?? array() );
					break;
				case 'trust_bar':
					$row_nodes = $this->build_trust_bar_row( $sections_data['trust_bar'] ?? array() );
					break;
				case 'intro':
					$row_nodes = $this->build_intro_row( $sections_data['intro'] ?? array() );
					break;
				case 'features':
					$cols = max( 1, min( 6, (int) ( $entry['cols'] ?? 3 ) ) );
					$rows = max( 1, min( 6, (int) ( $entry['rows'] ?? 1 ) ) );
					$widget_type = ( $entry['widget_type'] ?? 'icon' ) === 'image' ? 'image' : 'icon';
					$row_nodes = $this->build_features_row( $sections_data['features'] ?? array(), $cols, $rows, $widget_type );
					break;
				case 'rich_content':
					$row_nodes = $this->build_rich_content_row( $sections_data['rich_content'] ?? array() );
					break;
				case 'process':
					$cols = max( 1, min( 6, (int) ( $entry['cols'] ?? 2 ) ) );
					$rows = max( 1, min( 6, (int) ( $entry['rows'] ?? 2 ) ) );
					$row_nodes = $this->build_process_row( $sections_data['process'] ?? array(), $cols, $rows );
					break;
				case 'pricing':
					if ( isset( $sections_data['pricing'] ) ) {
						$row_nodes = $this->build_pricing_row( $sections_data['pricing'] );
					}
					break;
				case 'cta':
					$row_nodes = $this->build_cta_row( $sections_data['cta'] ?? array(), $contact_url );
					break;
			}

			if ( ! empty( $row_nodes ) ) {
				// Stamp section type + position on the row's settings for downstream patching.
				$root_row_id = null;
				foreach ( $row_nodes as $node_id => $node ) {
					if ( $node->type === 'row' && $node->parent === null ) {
						$root_row_id      = $node_id;
						$node->position   = $position++;
						$node->settings->aintelligize_section = $id;
						break;
					}
				}
				$out_nodes = array_merge( $out_nodes, $row_nodes );
				$section_count++;
			}
		}

		return array(
			'nodes'         => $out_nodes,
			'section_count' => $section_count,
			'tldr_text'     => $tldr_text,
		);
	}

	/* -----------------------------------------------------------------
	 * Section builders — each returns a node dict for one row.
	 * ----------------------------------------------------------------- */

	private function build_hero_row( array $data, $contact_url ) {
		$title       = (string) ( $data['title']       ?? '' );
		$subtitle    = (string) ( $data['subtitle']    ?? '' );
		$button_text = (string) ( $data['button_text'] ?? 'Get In Touch' );
		$button_url  = (string) ( $data['button_url']  ?? $contact_url );
		if ( $button_url === '/contact/' || $button_url === '' ) {
			$button_url = $contact_url;
		}

		$modules = array();
		if ( $title !== '' ) {
			$modules[] = $this->make_module( '__placeholder__', 'heading', array(
				'heading' => $title,
				'tag'     => 'h1',
			) );
		}
		if ( $subtitle !== '' ) {
			$modules[] = $this->make_module( '__placeholder__', 'rich-text', array(
				'text' => '<p>' . wp_kses_post( $subtitle ) . '</p>',
			) );
		}
		if ( $button_text !== '' ) {
			$modules[] = $this->make_module( '__placeholder__', 'button', array(
				'text'   => $button_text,
				'link'   => esc_url_raw( $button_url ),
				'link_target' => '_self',
				'style'  => 'flat',
				'align'  => 'left',
			) );
		}

		$nodes = $this->wrap_modules_in_row(
			$modules,
			array(
				'settings' => array(
					'aintelligize_section' => 'hero',
					'bg_color'             => '#0d1b2a',
					'bg_type'              => 'color',
					'min_height'           => 420,
					'min_height_unit'      => 'px',
					'full_height'          => 'custom',
					'content_alignment'    => 'flex-start',
				),
			)
		);
		return $nodes;
	}

	private function build_tldr_row( array $data ) {
		$text = trim( (string) ( $data['text'] ?? '' ) );
		if ( $text === '' ) {
			return array();
		}
		$module = $this->make_module( '__placeholder__', 'rich-text', array(
			'text' => '<p class="aintelligize-tldr"><strong>TL;DR:</strong> ' . wp_kses_post( $text ) . '</p>',
		) );
		return $this->wrap_modules_in_row(
			array( $module ),
			array( 'settings' => array( 'aintelligize_section' => 'tldr', 'bg_color' => '#f0fdf4', 'bg_type' => 'color' ) )
		);
	}

	private function build_trust_bar_row( array $data ) {
		$stats = is_array( $data['stats'] ?? null ) ? array_slice( $data['stats'], 0, 4 ) : array();
		if ( empty( $stats ) ) {
			return array();
		}

		$columns = array();
		foreach ( $stats as $stat ) {
			$icon  = (string) ( $stat['icon']  ?? 'fas fa-star' );
			$value = (string) ( $stat['stat']  ?? '' );
			$label = (string) ( $stat['label'] ?? '' );
			$html  = '<div style="text-align:center;">';
			$html .= '<i class="' . esc_attr( $icon ) . '" style="font-size:28px;color:#2271b1;margin-bottom:6px;"></i>';
			$html .= '<div style="font-size:22px;font-weight:700;">' . esc_html( $value ) . '</div>';
			$html .= '<div style="font-size:13px;color:#555;">' . esc_html( $label ) . '</div>';
			$html .= '</div>';
			$columns[] = array(
				$this->make_module( '__placeholder__', 'rich-text', array( 'text' => $html ) ),
			);
		}
		return $this->wrap_columns_in_row(
			$columns,
			array( 'settings' => array( 'aintelligize_section' => 'trust_bar', 'bg_color' => '#fff8e1', 'bg_type' => 'color' ) )
		);
	}

	private function build_intro_row( array $data ) {
		$heading    = (string) ( $data['heading'] ?? '' );
		$paragraphs = is_array( $data['paragraphs'] ?? null ) ? $data['paragraphs'] : array();

		$modules = array();
		if ( $heading !== '' ) {
			$modules[] = $this->make_module( '__placeholder__', 'heading', array(
				'heading' => $heading,
				'tag'     => 'h2',
			) );
		}
		$body_html = '';
		foreach ( $paragraphs as $p ) {
			$p = trim( (string) $p );
			if ( $p !== '' ) {
				$body_html .= '<p>' . wp_kses_post( $p ) . '</p>';
			}
		}
		if ( $body_html !== '' ) {
			$modules[] = $this->make_module( '__placeholder__', 'rich-text', array( 'text' => $body_html ) );
		}
		if ( empty( $modules ) ) {
			return array();
		}
		return $this->wrap_modules_in_row( $modules, array( 'settings' => array( 'aintelligize_section' => 'intro' ) ) );
	}

	private function build_features_row( array $data, $cols, $rows, $widget_type = 'icon' ) {
		$heading = (string) ( $data['heading'] ?? '' );
		$items   = is_array( $data['items'] ?? null ) ? $data['items'] : array();
		$total   = max( 1, $cols * $rows );
		$items   = array_slice( $items, 0, $total );

		$out_nodes = array();
		$position  = 0;

		// Heading row first (single column, full width).
		if ( $heading !== '' ) {
			$head_nodes = $this->wrap_modules_in_row(
				array( $this->make_module( '__placeholder__', 'heading', array( 'heading' => $heading, 'tag' => 'h2' ) ) ),
				array( 'settings' => array( 'aintelligize_section' => 'features_heading' ) )
			);
			foreach ( $head_nodes as $id => $n ) {
				if ( $n->type === 'row' && $n->parent === null ) {
					$n->position = $position++;
				}
			}
			$out_nodes = array_merge( $out_nodes, $head_nodes );
		}

		// Card rows (cols × rows grid). Each row of the grid = one BB row.
		$idx = 0;
		for ( $r = 0; $r < $rows; $r++ ) {
			$columns = array();
			for ( $c = 0; $c < $cols; $c++ ) {
				$item = $items[ $idx ] ?? null;
				$idx++;
				if ( ! is_array( $item ) ) {
					$columns[] = array();
					continue;
				}
				$icon  = (string) ( $item['icon']        ?? 'fas fa-check-circle' );
				$title = (string) ( $item['title']       ?? '' );
				$desc  = (string) ( $item['description'] ?? '' );

				$col_modules = array();
				if ( $widget_type === 'image' ) {
					// Image-box style — placeholder until a real image is patched in.
					$col_modules[] = $this->make_module( '__placeholder__', 'photo', array(
						'photo_source'  => 'library',
						'photo'         => '',
						'crop'          => 'landscape',
						'align'         => 'center',
					) );
				} else {
					$col_modules[] = $this->make_module( '__placeholder__', 'icon', array(
						'icon'  => $icon,
						'align' => 'center',
						'size'  => '40',
					) );
				}
				if ( $title !== '' ) {
					$col_modules[] = $this->make_module( '__placeholder__', 'heading', array(
						'heading' => $title,
						'tag'     => 'h3',
					) );
				}
				if ( $desc !== '' ) {
					$col_modules[] = $this->make_module( '__placeholder__', 'rich-text', array(
						'text' => '<p>' . wp_kses_post( $desc ) . '</p>',
					) );
				}
				$columns[] = $col_modules;
			}

			$row_nodes = $this->wrap_columns_in_row(
				$columns,
				array( 'settings' => array( 'aintelligize_section' => 'features_row_' . $r ) )
			);
			foreach ( $row_nodes as $id => $n ) {
				if ( $n->type === 'row' && $n->parent === null ) {
					$n->position = $position++;
				}
			}
			$out_nodes = array_merge( $out_nodes, $row_nodes );
		}

		return $out_nodes;
	}

	private function build_rich_content_row( array $data ) {
		$html = trim( (string) ( $data['html'] ?? '' ) );
		if ( $html === '' ) {
			return array();
		}
		$module = $this->make_module( '__placeholder__', 'rich-text', array( 'text' => wp_kses_post( $html ) ) );
		return $this->wrap_modules_in_row( array( $module ), array( 'settings' => array( 'aintelligize_section' => 'rich_content' ) ) );
	}

	private function build_process_row( array $data, $cols, $rows ) {
		$heading = (string) ( $data['heading'] ?? '' );
		$steps   = is_array( $data['steps'] ?? null ) ? $data['steps'] : array();
		$total   = max( 1, $cols * $rows );
		$steps   = array_slice( $steps, 0, $total );

		$out_nodes = array();
		$position  = 0;
		if ( $heading !== '' ) {
			$head_nodes = $this->wrap_modules_in_row(
				array( $this->make_module( '__placeholder__', 'heading', array( 'heading' => $heading, 'tag' => 'h2' ) ) ),
				array( 'settings' => array( 'aintelligize_section' => 'process_heading' ) )
			);
			foreach ( $head_nodes as $id => $n ) {
				if ( $n->type === 'row' && $n->parent === null ) {
					$n->position = $position++;
				}
			}
			$out_nodes = array_merge( $out_nodes, $head_nodes );
		}

		$idx = 0;
		for ( $r = 0; $r < $rows; $r++ ) {
			$columns = array();
			for ( $c = 0; $c < $cols; $c++ ) {
				$step = $steps[ $idx ] ?? null;
				$idx++;
				if ( ! is_array( $step ) ) {
					$columns[] = array();
					continue;
				}
				$icon  = (string) ( $step['icon']        ?? 'fas fa-check' );
				$title = (string) ( $step['title']       ?? '' );
				$desc  = (string) ( $step['description'] ?? '' );

				$col_modules = array();
				$col_modules[] = $this->make_module( '__placeholder__', 'icon', array(
					'icon'  => $icon,
					'align' => 'center',
					'size'  => '36',
				) );
				if ( $title !== '' ) {
					$col_modules[] = $this->make_module( '__placeholder__', 'heading', array(
						'heading' => ( $idx /* already incremented */ ) . '. ' . $title,
						'tag'     => 'h3',
					) );
				}
				if ( $desc !== '' ) {
					$col_modules[] = $this->make_module( '__placeholder__', 'rich-text', array(
						'text' => '<p>' . wp_kses_post( $desc ) . '</p>',
					) );
				}
				$columns[] = $col_modules;
			}
			$row_nodes = $this->wrap_columns_in_row(
				$columns,
				array( 'settings' => array( 'aintelligize_section' => 'process_row_' . $r ) )
			);
			foreach ( $row_nodes as $id => $n ) {
				if ( $n->type === 'row' && $n->parent === null ) {
					$n->position = $position++;
				}
			}
			$out_nodes = array_merge( $out_nodes, $row_nodes );
		}
		return $out_nodes;
	}

	private function build_pricing_row( array $data ) {
		$heading = (string) ( $data['heading'] ?? 'Pricing' );
		$caveat  = (string) ( $data['caveat']  ?? '' );

		$modules = array();
		$modules[] = $this->make_module( '__placeholder__', 'heading', array( 'heading' => $heading, 'tag' => 'h2' ) );
		// BB doesn't ship a built-in shortcode module, but the rich-text module
		// renders shortcodes on the published page, so the schema range list
		// shortcode (or whatever is configured) goes here.
		$shortcode_html = '<div class="aintelligize-pricing-block">[myls_service_price_range]</div>';
		$modules[] = $this->make_module( '__placeholder__', 'rich-text', array( 'text' => $shortcode_html ) );
		if ( $caveat !== '' ) {
			$modules[] = $this->make_module( '__placeholder__', 'rich-text', array(
				'text' => '<p class="pricing-caveat" style="font-size:13px;color:#666;">' . wp_kses_post( $caveat ) . '</p>',
			) );
		}

		return $this->wrap_modules_in_row( $modules, array( 'settings' => array( 'aintelligize_section' => 'pricing' ) ) );
	}

	private function build_cta_row( array $data, $contact_url ) {
		$heading     = (string) ( $data['heading']     ?? 'Ready to Get Started?' );
		$subtitle    = (string) ( $data['subtitle']    ?? '' );
		$button_text = (string) ( $data['button_text'] ?? 'Contact Us' );
		$button_url  = (string) ( $data['button_url']  ?? $contact_url );
		if ( $button_url === '/contact/' || $button_url === '' ) {
			$button_url = $contact_url;
		}

		$modules = array();
		$modules[] = $this->make_module( '__placeholder__', 'heading', array( 'heading' => $heading, 'tag' => 'h2' ) );
		if ( $subtitle !== '' ) {
			$modules[] = $this->make_module( '__placeholder__', 'rich-text', array(
				'text' => '<p>' . wp_kses_post( $subtitle ) . '</p>',
			) );
		}
		$modules[] = $this->make_module( '__placeholder__', 'button', array(
			'text'        => $button_text,
			'link'        => esc_url_raw( $button_url ),
			'link_target' => '_self',
			'style'       => 'flat',
			'align'       => 'center',
		) );

		return $this->wrap_modules_in_row(
			$modules,
			array( 'settings' => array(
				'aintelligize_section' => 'cta',
				'bg_color'             => '#1e3a8a',
				'bg_type'              => 'color',
			) )
		);
	}
}
