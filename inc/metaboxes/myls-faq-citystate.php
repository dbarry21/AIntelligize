<?php
/**
 * MYLS – Native editor meta boxes (ACF replacement)
 * File: inc/metaboxes/myls-faq-citystate.php
 *
 * Adds meta boxes on ALL public post types (excluding attachments):
 *  - FAQs: question (textbox) + answer (WYSIWYG editor)
 *  - City, State: textbox
 *
 * Storage (custom fields):
 *  - _myls_faq_items   array of [ ['q' => string, 'a' => string (HTML)], ... ]
 *  - _myls_city_state  string
 *
 * Notes:
 *  - No dynamic wp_editor creation. We pre-render extra blank rows (hidden) and reveal them via an "Add row" button.
 *  - Supports a delete checkbox per row.
 *  - If a row is empty (question AND answer are empty), "Delete this FAQ on save" is checked by default.
 */

if ( ! defined('ABSPATH') ) exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */
if ( ! function_exists('myls_get_public_post_types_no_attachments') ) {
	function myls_get_public_post_types_no_attachments() : array {
		$pts = get_post_types(['public' => true], 'names');
		unset($pts['attachment']);
		return array_values($pts);
	}
}

if ( ! function_exists('myls_get_faq_items_meta') ) {
	function myls_get_faq_items_meta( int $post_id ) : array {
		$items = get_post_meta($post_id, '_myls_faq_items', true);
		return is_array($items) ? $items : [];
	}
}

if ( ! function_exists('myls_get_city_state_meta') ) {
	function myls_get_city_state_meta( int $post_id ) : string {
		$val = get_post_meta($post_id, '_myls_city_state', true);
		return is_string($val) ? $val : '';
	}
}

if ( ! function_exists('myls_faq_row_is_empty') ) {
	/**
	 * True if question is blank AND answer is blank (treating empty HTML as blank).
	 */
	function myls_faq_row_is_empty( string $q, string $a_html ) : bool {
		$q = trim((string) wp_strip_all_tags($q));
		$a = trim((string) wp_strip_all_tags((string) $a_html));
		return ($q === '' && $a === '');
	}
}

/* -------------------------------------------------------------------------
 * Meta boxes
 * ------------------------------------------------------------------------- */
add_action('add_meta_boxes', function() {
	foreach ( myls_get_public_post_types_no_attachments() as $pt ) {
		add_meta_box(
			'myls_faq_box',
			__('MYLS FAQs', 'aintelligize'),
			'myls_render_faq_metabox',
			$pt,
			'normal',
			'high'
		);

		add_meta_box(
			'myls_city_state_box',
			__('MYLS City, State', 'aintelligize'),
			'myls_render_city_state_metabox',
			$pt,
			'side',
			'default'
		);
	}
});

function myls_render_city_state_metabox( $post ) {
	wp_nonce_field('myls_city_state_save', 'myls_city_state_nonce');
	$val = myls_get_city_state_meta((int)$post->ID);
	$alt_title = get_post_meta((int)$post->ID, '_myls_alt_page_title', true);
	$alt_title = is_string($alt_title) ? $alt_title : '';

	echo '<p><label for="myls_alt_page_title"><strong>Alternate Page Title</strong></label></p>';
	echo '<input type="text" class="widefat" id="myls_alt_page_title" name="myls_alt_page_title" value="' . esc_attr($alt_title) . '" placeholder="Leave blank to use WP page title" />';
	echo '<p style="margin-top:4px;"><small>Used by <code>[heading_title]</code> shortcode. Saved to <code>_myls_alt_page_title</code>.</small></p>';

	echo '<hr style="margin:12px 0;" />';

	echo '<p><label for="myls_city_state"><strong>City, State</strong></label></p>';
	echo '<input type="text" class="widefat" id="myls_city_state" name="myls_city_state" value="' . esc_attr($val) . '" placeholder="Tampa, FL" />';
	echo '<p style="margin-top:8px;"><small>Saved to <code>_myls_city_state</code>.</small></p>';
}

function myls_render_faq_metabox( $post ) {
	wp_nonce_field('myls_faq_save', 'myls_faq_nonce');

	$post_id = (int) $post->ID;
	$stored  = myls_get_faq_items_meta($post_id);
	$stored  = is_array($stored) ? $stored : [];
	$existing_count = count($stored);

	// Pre-render extra blank rows (hidden) so wp_editor exists before any JS reveal.
	$extra_blank_rows = 10;
	$items = $stored;
	for ( $i = 0; $i < $extra_blank_rows; $i++ ) {
		$items[] = ['q' => '', 'a' => '', '__blank' => true];
	}

	echo '<div class="myls-faq-metabox" id="myls-faq-metabox-root">';
		echo '<p style="margin:0 0 10px;"><small>Saved to <code>_myls_faq_items</code>. Utilities cleanup: <strong>AIntelligize → Utilities</strong>.</small></p>';

		echo '<p style="margin:0 0 10px; display:flex; gap:10px; align-items:center;">';
			echo '<button type="button" class="button" id="myls-faq-add-row">Add FAQ Row</button>';
			echo '<span class="description">Reveals a new blank row (pre-rendered) so the editor works reliably.</span>';
		echo '</p>';

		foreach ( $items as $idx => $row ) {
			$q = isset($row['q']) ? (string)$row['q'] : '';
			$a = isset($row['a']) ? (string)$row['a'] : '';
			$is_blank = ! empty($row['__blank']);

			$style = '';
			$classes = 'myls-faq-row';
			if ( $is_blank ) {
				$classes .= ' myls-faq-row-blank';
				$style = 'display:none;';
			}

			$should_default_delete = myls_faq_row_is_empty($q, $a);

			echo '<div class="' . esc_attr($classes) . '" data-idx="' . esc_attr((string)$idx) . '" style="border:1px solid #ddd; padding:12px; border-radius:8px; margin:12px 0; ' . esc_attr($style) . '">';
				echo '<p style="margin:0 0 6px;"><strong>Question</strong></p>';
				echo '<input type="text" class="widefat myls-faq-q" name="myls_faq[' . esc_attr((string)$idx) . '][q]" value="' . esc_attr($q) . '" />';

				echo '<p style="margin:10px 0 6px;"><strong>Answer</strong></p>';

				// Each editor needs a stable, unique ID.
				$editor_id = 'myls_faq_answer_' . (int)$idx . '_' . (int)$post_id;
				wp_editor(
					$a,
					$editor_id,
					[
						'textarea_name' => 'myls_faq[' . esc_attr((string)$idx) . '][a]',
						'media_buttons' => true,
						'textarea_rows' => 6,
						'teeny'         => false,
						'quicktags'     => true,
					]
				);

				echo '<label style="display:inline-block; margin-top:8px;">';
				// data-auto="1" means this box was auto-checked because the row is empty.
				// JS will ONLY auto-uncheck delete for auto-checked boxes (so users can intentionally delete non-empty rows).
				$auto_attr = $should_default_delete ? ' data-auto="1"' : '';
				echo '<input type="checkbox" class="myls-faq-del"' . $auto_attr . ' name="myls_faq[' . esc_attr((string)$idx) . '][_delete]" value="1"' . checked($should_default_delete, true, false) . ' /> ';
				echo 'Delete this FAQ on save';
				echo '</label>';

				if ( $should_default_delete ) {
					echo '<div class="description" style="margin-top:6px;">This row is empty, so it is set to delete automatically on the next save.</div>';
				}
			echo '</div>';
		}

	echo '</div>';

	/* ------------------------------------------------------------------
	 * HowTo Schema repeater — appears below the FAQ rows in the same metabox
	 * ------------------------------------------------------------------ */
	$howto_name  = (string) get_post_meta( $post_id, '_myls_howto_name', true );
	if ( $howto_name === '' ) $howto_name = 'How ' . get_the_title( $post_id ) . ' Works';
	$howto_steps = json_decode( (string) get_post_meta( $post_id, '_myls_howto_steps', true ), true );
	if ( ! is_array( $howto_steps ) ) $howto_steps = [];

	echo '<div style="margin-top:20px;padding:16px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;" id="myls-howto-section">';

		echo '<h3 style="margin:0 0 4px;">HowTo Schema</h3>';
		echo '<p style="margin:0 0 12px;color:#666;font-size:12px;">Outputs a HowTo @graph node — step-by-step rich results in Google. Saved to <code>_myls_howto_steps</code>.</p>';

		echo '<p style="margin:0 0 6px;"><strong>HowTo Title</strong></p>';
		echo '<input type="text" name="_myls_howto_name" value="' . esc_attr( $howto_name ) . '" class="widefat" style="margin-bottom:12px;" placeholder="How Professional Paver Sealing Works" />';

		echo '<div style="margin-bottom:12px;">';
			echo '<button type="button" id="myls-howto-ai-btn" class="button button-secondary" style="background:#6c37c9;color:#fff;border-color:#5a2db0;font-weight:600;">✨ Generate Steps from Page Content</button>';
			echo '<span id="myls-howto-ai-status" style="margin-left:10px;font-style:italic;color:#666;display:none;"></span>';
		echo '</div>';

		echo '<div id="myls-howto-steps">';
		foreach ( $howto_steps as $i => $step ) {
			$sname = esc_attr( $step['name'] ?? '' );
			$stext = esc_textarea( $step['text'] ?? '' );
			echo '<div class="myls-howto-step" style="background:#fff;border:1px solid #ddd;border-radius:3px;padding:12px;margin-bottom:8px;">';
				echo '<div style="display:flex;align-items:center;margin-bottom:6px;">';
					echo '<span class="myls-howto-step-num" style="background:#6c37c9;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;margin-right:8px;flex-shrink:0;">' . ( $i + 1 ) . '</span>';
					echo '<input type="text" name="_myls_howto_steps[' . $i . '][name]" value="' . $sname . '" class="widefat" placeholder="Step name (e.g. Free Surface Inspection)" />';
				echo '</div>';
				echo '<textarea name="_myls_howto_steps[' . $i . '][text]" rows="3" class="widefat" placeholder="Describe what happens in this step..." style="margin-bottom:6px;">' . $stext . '</textarea>';
				echo '<button type="button" class="button myls-howto-remove" style="color:#a00;border-color:#a00;">✕ Remove Step</button>';
			echo '</div>';
		}
		echo '</div>';

		echo '<button type="button" id="myls-howto-add" class="button" style="margin-top:4px;">+ Add Step Manually</button>';

	echo '</div>';
	?>
	<script>
	(function(){
		'use strict';
		var myls_howto_data = {
			ajax_url: <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>,
			post_id:  <?php echo (int) $post_id; ?>,
			nonce:    <?php echo wp_json_encode( wp_create_nonce('myls_howto_nonce') ); ?>
		};

		var container = document.getElementById('myls-howto-steps');
		var addBtn    = document.getElementById('myls-howto-add');
		var aiBtn     = document.getElementById('myls-howto-ai-btn');
		var aiStatus  = document.getElementById('myls-howto-ai-status');

		function getCount() {
			return container.querySelectorAll('.myls-howto-step').length;
		}

		function buildRow(idx, name, text) {
			name = (name || '').replace(/"/g, '&quot;');
			text = (text || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
			return '<div class="myls-howto-step" style="background:#fff;border:1px solid #ddd;border-radius:3px;padding:12px;margin-bottom:8px;">' +
				'<div style="display:flex;align-items:center;margin-bottom:6px;">' +
					'<span class="myls-howto-step-num" style="background:#6c37c9;color:#fff;border-radius:50%;width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;margin-right:8px;flex-shrink:0;">' + (idx+1) + '</span>' +
					'<input type="text" name="_myls_howto_steps[' + idx + '][name]" value="' + name + '" class="widefat" placeholder="Step name (e.g. Free Surface Inspection)" />' +
				'</div>' +
				'<textarea name="_myls_howto_steps[' + idx + '][text]" rows="3" class="widefat" placeholder="Describe what happens in this step..." style="margin-bottom:6px;">' + text + '</textarea>' +
				'<button type="button" class="button myls-howto-remove" style="color:#a00;border-color:#a00;">✕ Remove Step</button>' +
			'</div>';
		}

		function reindex() {
			container.querySelectorAll('.myls-howto-step').forEach(function(row, i) {
				row.querySelector('.myls-howto-step-num').textContent = i + 1;
				row.querySelector('input[type="text"]').name = '_myls_howto_steps[' + i + '][name]';
				row.querySelector('textarea').name = '_myls_howto_steps[' + i + '][text]';
			});
		}

		function bindRemove(btn) {
			btn.addEventListener('click', function() {
				btn.closest('.myls-howto-step').remove();
				reindex();
			});
		}

		container.querySelectorAll('.myls-howto-remove').forEach(bindRemove);

		addBtn.addEventListener('click', function() {
			var idx = getCount();
			var tmp = document.createElement('div');
			tmp.innerHTML = buildRow(idx, '', '');
			var row = tmp.firstChild;
			container.appendChild(row);
			bindRemove(row.querySelector('.myls-howto-remove'));
			row.querySelector('input').focus();
		});

		aiBtn.addEventListener('click', function() {
			aiBtn.disabled = true;
			aiStatus.style.display  = 'inline';
			aiStatus.style.color    = '#666';
			aiStatus.textContent    = 'Analyzing page content…';

			fetch(myls_howto_data.ajax_url, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: new URLSearchParams({
					action:  'myls_generate_howto_steps',
					post_id: myls_howto_data.post_id,
					nonce:   myls_howto_data.nonce
				})
			})
			.then(function(r){ return r.json(); })
			.then(function(data) {
				if (!data.success) {
					aiStatus.style.color = '#a00';
					aiStatus.textContent = '⚠ ' + (data.data || 'Could not generate steps.');
					aiBtn.disabled = false;
					return;
				}
				var steps = data.data.steps;
				var title = data.data.title;
				if (title) {
					document.querySelector('#myls-howto-section input[name="_myls_howto_name"]').value = title;
				}
				container.innerHTML = '';
				steps.forEach(function(step, i) {
					var tmp = document.createElement('div');
					tmp.innerHTML = buildRow(i, step.name, step.text);
					var row = tmp.firstChild;
					container.appendChild(row);
					bindRemove(row.querySelector('.myls-howto-remove'));
				});
				aiStatus.style.color = '#006505';
				aiStatus.textContent = '✓ ' + steps.length + ' steps generated — review and save.';
				aiBtn.disabled = false;
			})
			.catch(function(err) {
				aiStatus.style.color = '#a00';
				aiStatus.textContent = '⚠ Request failed. Check browser console.';
				aiBtn.disabled = false;
				console.error('[MYLS HowTo AI]', err);
			});
		});
	})();
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Saving
 * ------------------------------------------------------------------------- */
add_action('save_post', function( $post_id ) {
	$post_id = (int)$post_id;
	if ( $post_id <= 0 ) return;
	if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision($post_id) ) return;
	if ( ! current_user_can('edit_post', $post_id) ) return;

	// City/State + Alternate Page Title
	if ( isset($_POST['myls_city_state_nonce']) && wp_verify_nonce($_POST['myls_city_state_nonce'], 'myls_city_state_save') ) {
		if ( isset($_POST['myls_city_state']) ) {
			$val = sanitize_text_field((string)$_POST['myls_city_state']);
			if ( $val === '' ) {
				delete_post_meta($post_id, '_myls_city_state');
			} else {
				update_post_meta($post_id, '_myls_city_state', $val);
			}
		}

		if ( isset($_POST['myls_alt_page_title']) ) {
			$alt = sanitize_text_field((string)$_POST['myls_alt_page_title']);
			if ( $alt === '' ) {
				delete_post_meta($post_id, '_myls_alt_page_title');
			} else {
				update_post_meta($post_id, '_myls_alt_page_title', $alt);
			}
		}
	}

	// FAQs
	if ( ! ( isset($_POST['myls_faq_nonce']) && wp_verify_nonce($_POST['myls_faq_nonce'], 'myls_faq_save') ) ) {
		return;
	}

	// HowTo name
	if ( isset( $_POST['_myls_howto_name'] ) ) {
		$howto_name_val = sanitize_text_field( (string) $_POST['_myls_howto_name'] );
		if ( $howto_name_val === '' ) {
			delete_post_meta( $post_id, '_myls_howto_name' );
		} else {
			update_post_meta( $post_id, '_myls_howto_name', $howto_name_val );
		}
	}

	// HowTo steps
	if ( isset( $_POST['_myls_howto_steps'] ) && is_array( $_POST['_myls_howto_steps'] ) ) {
		$steps = [];
		foreach ( $_POST['_myls_howto_steps'] as $step ) {
			$n = sanitize_text_field( $step['name'] ?? '' );
			$t = sanitize_textarea_field( $step['text'] ?? '' );
			if ( $n !== '' && $t !== '' ) {
				$steps[] = [ 'name' => $n, 'text' => $t ];
			}
		}
		if ( empty( $steps ) ) {
			delete_post_meta( $post_id, '_myls_howto_steps' );
		} else {
			update_post_meta( $post_id, '_myls_howto_steps', wp_json_encode( $steps ) );
		}
	} else {
		delete_post_meta( $post_id, '_myls_howto_steps' );
	}

	if ( ! isset($_POST['myls_faq']) || ! is_array($_POST['myls_faq']) ) {
		return;
	}

	$clean = [];
	foreach ( $_POST['myls_faq'] as $row ) {
		if ( ! is_array($row) ) continue;
		if ( ! empty($row['_delete']) ) continue;

		$q = isset($row['q']) ? sanitize_text_field((string)$row['q']) : '';
		$a = isset($row['a']) ? wp_kses_post((string)$row['a']) : '';

		// Skip rows that are effectively empty.
		if ( $q === '' && trim(wp_strip_all_tags($a)) === '' ) {
			continue;
		}

		$clean[] = [ 'q' => $q, 'a' => $a ];
	}

	if ( empty($clean) ) {
		delete_post_meta($post_id, '_myls_faq_items');
	} else {
		update_post_meta($post_id, '_myls_faq_items', $clean);
	}
}, 20);

/* -------------------------------------------------------------------------
 * Admin JS (Add row button + auto-uncheck delete)
 * ------------------------------------------------------------------------- */
add_action('admin_enqueue_scripts', function($hook){
	// Only on editor screens.
	if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;

	// Ensure TinyMCE + wp.editor JS is loaded (needed for Gutenberg metabox init).
	if ( function_exists('wp_enqueue_editor') ) {
		wp_enqueue_editor();
	}

	$src = ( defined('MYLS_PLUGIN_URL') ? MYLS_PLUGIN_URL : trailingslashit(MYLS_URL) ) . 'assets/js/myls-faq-metabox.js';
	wp_enqueue_script('myls-faq-metabox', $src, [], defined('MYLS_VERSION') ? MYLS_VERSION : null, true);
});
