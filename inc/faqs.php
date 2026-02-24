<?php
if ( ! defined('ABSPATH') ) exit;

function myls_get_faq_items( int $post_id ) : array {

	$items = get_post_meta( $post_id, '_myls_faq_items', true );
	if ( is_array($items) && ! empty($items) ) {
		return $items;
	}

	// Legacy ACF fallback
	$rows = get_post_meta( $post_id, 'faq_items', true );
	$legacy = [];

	if ( is_array($rows) ) {
		foreach ( $rows as $row ) {
			if ( empty($row['question']) || empty($row['answer']) ) continue;
			$legacy[] = [
				'q' => wp_strip_all_tags($row['question']),
				'a' => $row['answer'],
			];
		}
	}

	return $legacy;
}

function myls_set_faq_items( int $post_id, array $items ) : void {
	update_post_meta( $post_id, '_myls_faq_items', array_values($items) );
}
