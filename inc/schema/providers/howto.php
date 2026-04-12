<?php
/**
 * AIntelligize — HowTo Schema Provider
 * File: inc/schema/providers/howto.php
 *
 * Appends a HowTo @graph node when _myls_howto_steps post meta is populated.
 * Steps are entered via the HowTo repeater in the MYLS FAQs metabox or
 * AI-generated using the "Generate Steps from Page Content" button.
 *
 * Meta keys:
 *   _myls_howto_name  — string: HowTo title (defaults to "How [page title] Works")
 *   _myls_howto_steps — JSON array of { name, text } objects
 *
 * Priority 55 — runs after FAQPage (50) so it is adjacent in the @graph.
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('myls_build_howto_node') ) {
	/**
	 * Build the HowTo @graph node for the current post.
	 *
	 * @param  int $post_id
	 * @return array|null  HowTo node array, or null when no valid steps exist.
	 */
	function myls_build_howto_node( int $post_id ) : ?array {
		$raw = (string) get_post_meta( $post_id, '_myls_howto_steps', true );
		if ( $raw === '' ) return null;

		$steps = json_decode( $raw, true );
		if ( ! is_array( $steps ) || empty( $steps ) ) return null;

		$name = (string) get_post_meta( $post_id, '_myls_howto_name', true );
		if ( $name === '' ) {
			$name = 'How ' . get_the_title( $post_id ) . ' Works';
		}

		$step_nodes = [];
		foreach ( $steps as $i => $step ) {
			$step_name = sanitize_text_field( $step['name'] ?? '' );
			$step_text = sanitize_textarea_field( $step['text'] ?? '' );
			if ( $step_name === '' || $step_text === '' ) continue;

			$step_nodes[] = [
				'@type'    => 'HowToStep',
				'position' => $i + 1,
				'name'     => $step_name,
				'text'     => $step_text,
			];
		}

		if ( empty( $step_nodes ) ) return null;

		$permalink = get_permalink( $post_id );

		return [
			'@type'    => 'HowTo',
			'@id'      => $permalink . '#howto',
			'name'     => $name,
			'step'     => $step_nodes,
			'isPartOf' => [ '@id' => $permalink . '#webpage' ],
			'provider' => [ '@id' => home_url( '/#localbusiness' ) ],
		];
	}
}

add_filter( 'myls_schema_graph', function( array $graph ) : array {
	if ( ! is_singular() ) return $graph;

	$post_id = get_the_ID();
	if ( ! $post_id ) return $graph;

	$node = myls_build_howto_node( (int) $post_id );
	if ( $node ) {
		$graph[] = $node;
	}

	return $graph;
}, 55 );
