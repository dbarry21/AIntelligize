<?php
/**
 * AJAX: Video Transcript Cache operations
 * Path: inc/ajax/video-transcript-cache.php
 *
 * Endpoints for the Video Transcripts admin page:
 *   - myls_vt_load          — load all rows + stats
 *   - myls_vt_sync          — discover channel videos via YouTube API
 *   - myls_vt_fetch_batch   — fetch transcripts for N pending videos
 *   - myls_vt_fetch_single  — fetch transcript for a single video ID
 *   - myls_vt_refetch       — re-fetch transcript for existing row
 *   - myls_vt_delete        — delete single row
 *   - myls_vt_migrate       — copy from legacy myls_video_entries option
 *
 * All require manage_options + nonce myls_vt_ops.
 *
 * @since 7.8.86
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Guard: verify nonce + capability.
 */
function _myls_vt_guard() {
	check_ajax_referer('myls_vt_ops', 'nonce');
	if ( ! current_user_can('manage_options') ) {
		wp_send_json_error('Unauthorized', 403);
	}
}

/**
 * Attempt to fetch transcript for a video using the priority chain.
 * Returns ['transcript'=>string, 'source'=>string, 'lang'=>string] or null.
 */
function _myls_vt_do_fetch( string $video_id ) : ?array {
	// Method 1: Supadata API
	$transcript = _myls_fetch_transcript_supadata( $video_id );
	if ( $transcript !== null ) {
		return ['transcript' => $transcript, 'source' => 'supadata', 'lang' => 'en'];
	}

	// Method 2: YouTube page scrape
	$caption_url = _myls_get_caption_url_from_page( $video_id );
	if ( $caption_url ) {
		$transcript = _myls_fetch_caption_xml( $caption_url );
		if ( $transcript !== null ) {
			return ['transcript' => $transcript, 'source' => 'page_scrape', 'lang' => 'en'];
		}
	}

	// Method 3: Legacy timedtext API
	$caption_url = _myls_get_caption_url_from_timedtext( $video_id );
	if ( $caption_url ) {
		$transcript = _myls_fetch_caption_xml( $caption_url );
		if ( $transcript !== null ) {
			return ['transcript' => $transcript, 'source' => 'timedtext', 'lang' => 'en'];
		}
	}

	return null;
}

/* ═══════════════════════════════════════════════════════
 *  Load all rows + stats
 * ═══════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_vt_load', function() {
	_myls_vt_guard();
	wp_send_json_success([
		'rows'  => myls_vt_get_all(),
		'stats' => myls_vt_get_stats(),
	]);
});

/* ═══════════════════════════════════════════════════════
 *  Sync channel videos via YouTube API
 * ═══════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_vt_sync', function() {
	_myls_vt_guard();

	$api_key    = myls_yt_get_api_key();
	$channel_id = myls_yt_get_channel_id();

	if ( $api_key === '' || $channel_id === '' ) {
		wp_send_json_error('YouTube API key and Channel ID are required. Set them in API Integration.');
	}

	$playlist_id = myls_yt_get_uploads_playlist_id( $channel_id, $api_key );
	if ( $playlist_id === '' ) {
		wp_send_json_error('Could not resolve uploads playlist for channel.');
	}

	// Fetch up to 10 pages (500 videos max)
	$videos = myls_yt_fetch_uploads_batch( $playlist_id, $api_key, 10 );
	if ( empty($videos) ) {
		wp_send_json_error('No videos found on channel.');
	}

	// Map to bulk insert format
	$rows = [];
	foreach ( $videos as $v ) {
		$rows[] = [
			'video_id' => $v['videoId'],
			'title'    => $v['title'],
		];
	}

	$inserted = myls_vt_bulk_insert( $rows );

	wp_send_json_success([
		'message'  => sprintf('%d new videos added (%d total from channel).', $inserted, count($videos)),
		'inserted' => $inserted,
		'total'    => count($videos),
		'rows'     => myls_vt_get_all(),
		'stats'    => myls_vt_get_stats(),
	]);
});

/* ═══════════════════════════════════════════════════════
 *  Fetch batch — 5 pending videos per call
 * ═══════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_vt_fetch_batch', function() {
	_myls_vt_guard();

	$batch_size = 5;
	$pending    = myls_vt_get_missing( $batch_size );

	if ( empty($pending) ) {
		wp_send_json_success([
			'done'      => true,
			'processed' => 0,
			'remaining' => 0,
			'stats'     => myls_vt_get_stats(),
		]);
	}

	$processed = 0;
	foreach ( $pending as $row ) {
		$result = _myls_vt_do_fetch( $row['video_id'] );

		if ( $result !== null ) {
			myls_vt_upsert([
				'video_id'   => $row['video_id'],
				'transcript' => $result['transcript'],
				'lang'       => $result['lang'],
				'source'     => $result['source'],
				'status'     => 'ok',
				'fetched_at' => current_time('mysql'),
			]);
		} else {
			myls_vt_upsert([
				'video_id'   => $row['video_id'],
				'status'     => 'none',
				'fetched_at' => current_time('mysql'),
			]);
		}
		$processed++;
	}

	$stats     = myls_vt_get_stats();
	$remaining = (int) ($stats['pending'] ?? 0);

	wp_send_json_success([
		'done'      => $remaining === 0,
		'processed' => $processed,
		'remaining' => $remaining,
		'stats'     => $stats,
	]);
});

/* ═══════════════════════════════════════════════════════
 *  Fetch single — by video ID (ad-hoc)
 * ═══════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_vt_fetch_single', function() {
	_myls_vt_guard();

	$video_id = sanitize_text_field( $_POST['video_id'] ?? '' );
	if ( $video_id === '' || ! preg_match('/^[a-zA-Z0-9_\-]{11}$/', $video_id) ) {
		wp_send_json_error('Invalid video ID.');
	}

	// Ensure row exists (insert if not present)
	$existing = myls_vt_get_by_id( $video_id );
	if ( ! $existing ) {
		myls_vt_upsert([
			'video_id' => $video_id,
			'title'    => '',
			'status'   => 'pending',
		]);
	}

	// Fetch transcript
	$result = _myls_vt_do_fetch( $video_id );

	if ( $result !== null ) {
		myls_vt_upsert([
			'video_id'   => $video_id,
			'transcript' => $result['transcript'],
			'lang'       => $result['lang'],
			'source'     => $result['source'],
			'status'     => 'ok',
			'fetched_at' => current_time('mysql'),
		]);
		$row = myls_vt_get_by_id( $video_id );
		wp_send_json_success([
			'message' => 'Transcript fetched via ' . $result['source'] . '.',
			'row'     => $row,
			'stats'   => myls_vt_get_stats(),
		]);
	} else {
		myls_vt_upsert([
			'video_id'   => $video_id,
			'status'     => 'none',
			'fetched_at' => current_time('mysql'),
		]);
		wp_send_json_error('No transcript found for this video.');
	}
});

/* ═══════════════════════════════════════════════════════
 *  Re-fetch — for existing row
 * ═══════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_vt_refetch', function() {
	_myls_vt_guard();

	$video_id = sanitize_text_field( $_POST['video_id'] ?? '' );
	if ( $video_id === '' ) {
		wp_send_json_error('Missing video_id.');
	}

	$result = _myls_vt_do_fetch( $video_id );

	if ( $result !== null ) {
		myls_vt_upsert([
			'video_id'   => $video_id,
			'transcript' => $result['transcript'],
			'lang'       => $result['lang'],
			'source'     => $result['source'],
			'status'     => 'ok',
			'fetched_at' => current_time('mysql'),
		]);
		wp_send_json_success([
			'message' => 'Transcript re-fetched via ' . $result['source'] . '.',
			'row'     => myls_vt_get_by_id( $video_id ),
		]);
	} else {
		myls_vt_upsert([
			'video_id'   => $video_id,
			'status'     => 'none',
			'fetched_at' => current_time('mysql'),
		]);
		wp_send_json_error('No transcript found on re-fetch.');
	}
});

/* ═══════════════════════════════════════════════════════
 *  Delete single row
 * ═══════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_vt_delete_row', function() {
	_myls_vt_guard();

	$video_id = sanitize_text_field( $_POST['video_id'] ?? '' );
	if ( $video_id === '' ) {
		wp_send_json_error('Missing video_id.');
	}

	myls_vt_delete( $video_id );
	wp_send_json_success([
		'message' => 'Deleted.',
		'stats'   => myls_vt_get_stats(),
	]);
});

/* ═══════════════════════════════════════════════════════
 *  Migrate from legacy myls_video_entries option
 * ═══════════════════════════════════════════════════════ */
add_action('wp_ajax_myls_vt_migrate', function() {
	_myls_vt_guard();

	$entries = get_option('myls_video_entries', []);
	if ( ! is_array($entries) || empty($entries) ) {
		wp_send_json_error('No legacy video entries found.');
	}

	$migrated = 0;
	foreach ( $entries as $entry ) {
		if ( ! is_array($entry) ) continue;
		$vid   = trim($entry['video_id'] ?? '');
		$trans = trim($entry['transcript'] ?? '');
		if ( $vid === '' ) continue;

		$existing = myls_vt_get_by_id( $vid );
		if ( $existing && $existing['status'] === 'ok' ) continue; // don't overwrite

		myls_vt_upsert([
			'video_id'   => $vid,
			'title'      => trim($entry['name'] ?? ''),
			'transcript' => $trans !== '' ? $trans : null,
			'status'     => $trans !== '' ? 'ok' : 'pending',
			'source'     => $trans !== '' ? 'manual' : '',
			'fetched_at' => $trans !== '' ? current_time('mysql') : null,
		]);
		$migrated++;
	}

	wp_send_json_success([
		'message' => sprintf('%d legacy entries migrated.', $migrated),
		'rows'    => myls_vt_get_all(),
		'stats'   => myls_vt_get_stats(),
	]);
});
