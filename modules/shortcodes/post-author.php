<?php
/**
 * Shortcode: [post_author]
 * Output the current post's author (name, avatar, and/or link), with optional schema markup.
 *
 * Attributes:
 *  - id=""             Optional post ID. Defaults to current post.
 *  - user_id=""        Optional user ID (overrides post author).
 *  - field="display_name"  One of: display_name, first_name, last_name, nickname, user_nicename, user_login, description, email
 *  - link="true"       Link author name to their author archive (true|false).
 *  - url=""            Custom link URL (overrides author archive when provided).
 *  - rel="author"      rel attribute for the link.
 *  - target=""         target attribute for the link (e.g., _blank).
 *  - show="name"       What to show: name | avatar | both
 *  - avatar="false"    Show avatar (true|false). If show="both", avatar is shown regardless of this flag.
 *  - size="48"         Avatar size in px.
 *  - class=""          Extra CSS classes on wrapper element.
 *  - before=""         Output before the author HTML (not escaped; use carefully).
 *  - after=""          Output after the author HTML (not escaped; use carefully).
 *  - schema="off"      If "person", wrap in https://schema.org/Person microdata.
 *
 * Examples:
 *  [post_author]                                      => Linked display name
 *  [post_author show="both" size="64"]                => Avatar (64px) + linked name
 *  [post_author field="first_name" link="false"]      => First name, no link
 *  [post_author schema="person" show="name"]          => Name wrapped with Person microdata
 *  [post_author id="123" show="avatar" size="96"]     => Avatar of post 123's author
 */

if ( ! function_exists('myls_post_author_shortcode_handler') ) {
	function myls_post_author_shortcode_handler( $atts ) {
		// ---------- Defaults & sanitize ----------
		$atts = shortcode_atts( array(
			'id'       => '',
			'user_id'  => '',
			'field'    => 'display_name',
			'link'     => 'true',
			'url'      => '',
			'rel'      => 'author',
			'target'   => '',
			'show'     => 'name', // name|avatar|both
			'avatar'   => 'false',
			'size'     => '48',
			'class'    => '',
			'before'   => '',
			'after'    => '',
			'schema'   => 'off', // off|person
		), $atts, 'post_author' );

		$truthy = function( $v ) {
			return in_array( strtolower( (string) $v ), array('1','true','yes','on'), true );
		};

		$show        = in_array( strtolower($atts['show']), array('name','avatar','both'), true ) ? strtolower($atts['show']) : 'name';
		$want_link   = $truthy( $atts['link'] );
		$want_avatar = $show === 'both' ? true : $truthy( $atts['avatar'] );
		$size        = max( 1, (int) $atts['size'] );
		$field       = sanitize_key( $atts['field'] );
		$rel         = $atts['rel'] !== '' ? sanitize_text_field( $atts['rel'] ) : 'author';
		$target      = sanitize_text_field( $atts['target'] );
		$class       = sanitize_html_class( $atts['class'] );
		$schema_mode = strtolower( $atts['schema'] ) === 'person' ? 'person' : 'off';
		$custom_url  = esc_url_raw( $atts['url'] );

		// ---------- Resolve author user ----------
		$user = null;

		// 1) Explicit user_id override
		if ( $atts['user_id'] !== '' ) {
			$user = get_user_by( 'ID', (int) $atts['user_id'] );
		}

		// 2) From specific post ID
		if ( ! $user ) {
			$post_id = $atts['id'] !== '' ? (int) $atts['id'] : 0;
			if ( ! $post_id ) {
				$post = get_post();
				if ( $post ) {
					$post_id = (int) $post->ID;
				}
			}
			if ( $post_id ) {
				$author_id = (int) get_post_field( 'post_author', $post_id );
				if ( $author_id ) {
					$user = get_user_by( 'ID', $author_id );
				}
			}
		}

		// 3) Fallback to current user if still missing (rare)
		if ( ! $user && is_user_logged_in() ) {
			$user = wp_get_current_user();
		}

		if ( ! $user || ! $user->exists() ) {
			return ''; // nothing to output
		}

		$author_id   = (int) $user->ID;
		$display     = '';
		$email_safe  = '';

		// ---------- Determine display field ----------
		switch ( $field ) {
			case 'first_name':
			case 'last_name':
			case 'nickname':
			case 'description':
				$display = (string) get_user_meta( $author_id, $field, true );
				break;
			case 'user_nicename':
			case 'user_login':
			case 'display_name':
				$display = (string) $user->{$field};
				break;
			case 'email':
				$email_safe = antispambot( (string) $user->user_email );
				$display    = $email_safe;
				break;
			default:
				$display = (string) $user->display_name;
				break;
		}
		$display = $display !== '' ? $display : (string) $user->display_name;

		// ---------- Link URL ----------
		$author_url = $custom_url !== '' ? $custom_url : get_author_posts_url( $author_id );

		// ---------- Avatar ----------
		$avatar_html = '';
		if ( $want_avatar ) {
			// get_avatar() escapes attributes and returns an <img> tag
			$avatar_html = get_avatar( $author_id, $size, '', $display );
		}

		// ---------- Name HTML (with optional link) ----------
		$name_html = esc_html( $display );
		if ( $want_link && $author_url ) {
			$attrs = array( 'href="'. esc_url( $author_url ) .'"' );
			if ( $rel !== '' )    { $attrs[] = 'rel="'. esc_attr( $rel ) .'"'; }
			if ( $target !== '' ) { $attrs[] = 'target="'. esc_attr( $target ) .'"'; }
			$name_html = '<a '. implode(' ', $attrs) .'>'. $name_html .'</a>';
		}

		// ---------- Schema (optional Person microdata) ----------
		$wrap_attrs = array();
		$inner_html = '';

		if ( $schema_mode === 'person' ) {
			$wrap_attrs[] = 'itemscope';
			$wrap_attrs[] = 'itemtype="https://schema.org/Person"';

			// If avatar is present, add itemprop="image"
			if ( $avatar_html ) {
				// Safely inject itemprop into the avatar img
				$avatar_html = preg_replace(
					'/^<img\b/i',
					'<img itemprop="image"',
					$avatar_html,
					1
				);
			}

			// If linked, wrap the linked name with itemprop="url" + child itemprop="name"
			if ( $want_link && strpos( $name_html, '<a ' ) === 0 ) {
				$name_html = preg_replace(
					'/^<a\b(.*?)>(.*?)<\/a>$/is',
					'<a$1 itemprop="url"><span itemprop="name">$2</span></a>',
					$name_html,
					1
				);
			} else {
				$name_html = '<span itemprop="name">'. $name_html .'</span>';
			}
		}

		// ---------- Assemble output ----------
		if ( $class !== '' ) {
			$wrap_attrs[] = 'class="'. esc_attr( $class ) .'"';
		}
		$wrap_open  = '<span'. ( $wrap_attrs ? ' '. implode(' ', $wrap_attrs) : '' ) .'>';
		$wrap_close = '</span>';

		if ( $show === 'avatar' ) {
			$inner_html = $avatar_html;
		} elseif ( $show === 'both' ) {
			// Simple layout: avatar + space + name
			$inner_html = trim( $avatar_html . ' ' . $name_html );
		} else { // name
			$inner_html = $name_html;
		}

		// Allow raw before/after (caller responsibility)
		$out = (string) $atts['before'] . $wrap_open . $inner_html . $wrap_close . (string) $atts['after'];

		/**
		 * Filter for customization.
		 * @param string $out    Final HTML.
		 * @param array  $atts   Shortcode attributes.
		 * @param WP_User $user  The resolved author user object.
		 */
		return apply_filters( 'myls_post_author_shortcode_output', $out, $atts, $user );
	}
}

// Register the shortcode (supports your custom registrar or vanilla WP)
if ( function_exists('myls_register_shortcode') ) {
	myls_register_shortcode( 'post_author', 'myls_post_author_shortcode_handler', array(
		'tag'         => 'post_author',
		'description' => 'Output the current post author (name, avatar, link), with optional schema.org Person microdata.',
		'params'      => array(
			'id'       => 'Post ID (default: current post).',
			'user_id'  => 'User ID override.',
			'field'    => 'Author field to display (display_name, first_name, last_name, nickname, user_nicename, user_login, description, email).',
			'link'     => 'Link to author archive (true/false). Default true.',
			'url'      => 'Custom link URL (overrides author archive).',
			'rel'      => 'rel attribute for link. Default "author".',
			'target'   => 'target attribute for link.',
			'show'     => 'name|avatar|both. Default name.',
			'avatar'   => 'Show avatar (true/false). Default false.',
			'size'     => 'Avatar size in px. Default 48.',
			'class'    => 'Extra CSS class on wrapper element.',
			'before'   => 'HTML to output before.',
			'after'    => 'HTML to output after.',
			'schema'   => 'off|person. If "person", wrap output with schema.org/Person microdata.',
		),
	) );
} else {
	add_shortcode( 'post_author', 'myls_post_author_shortcode_handler' );
}
