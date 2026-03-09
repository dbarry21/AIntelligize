/**
 * Video Transcripts — Admin Dashboard JS
 * Path: admin/video-transcripts/video-transcripts.js
 *
 * @since 7.8.86
 */
(function($){
'use strict';

var POST = MYLS_VT.ajaxurl;
var NONCE = MYLS_VT.nonce;

/* ── Helpers ── */
function ajax(action, data, cb) {
	data = data || {};
	data.action = action;
	data.nonce  = NONCE;
	$.post(POST, data)
		.done(function(r){ cb(null, r); })
		.fail(function(){ cb('Network error'); });
}

function showMsg(text, type) {
	var $m = $('#myls-vt-message');
	$m.attr('class', 'notice notice-' + (type || 'info'))
	  .html('<p>' + text + '</p>')
	  .show();
	setTimeout(function(){ $m.fadeOut(); }, 6000);
}

function statusBadge(status) {
	var cls = {ok:'myls-vt-badge--ok', pending:'myls-vt-badge--pending', none:'myls-vt-badge--none', error:'myls-vt-badge--error'};
	return '<span class="myls-vt-badge ' + (cls[status] || '') + '">' + (status || '—') + '</span>';
}

function escHtml(s) {
	if (!s) return '';
	var d = document.createElement('div');
	d.appendChild(document.createTextNode(s));
	return d.innerHTML;
}

function truncate(s, n) {
	if (!s) return '';
	return s.length > n ? s.substring(0, n) + '…' : s;
}

/* ── Update stats bar ── */
function updateStats(stats) {
	if (!stats) return;
	$('#vt-stat-total').text(stats.total || 0);
	$('#vt-stat-ok').text(stats.ok || 0);
	$('#vt-stat-pending').text(stats.pending || 0);
	$('#vt-stat-none').text(stats.none || 0);
	$('#vt-stat-error').text(stats.error || 0);
}

/* ── Render table rows ── */
function renderRows(rows) {
	var $tbody = $('#myls-vt-tbody');
	if (!rows || !rows.length) {
		$tbody.html('<tr><td colspan="6" style="text-align:center;padding:20px;">No videos yet. Click "Sync Channel Videos" to start.</td></tr>');
		return;
	}

	var html = '';
	for (var i = 0; i < rows.length; i++) {
		var r = rows[i];
		var vid = escHtml(r.video_id);
		var title = escHtml(r.title || '');
		var fetched = r.fetched_at ? escHtml(r.fetched_at) : '—';
		var hasTranscript = r.status === 'ok' && r.transcript;

		html += '<tr data-vid="' + vid + '">';
		html += '<td><a href="https://youtu.be/' + vid + '" target="_blank" rel="noopener">' + vid + '</a></td>';
		html += '<td>' + (title || '<em>—</em>') + '</td>';
		html += '<td>' + statusBadge(r.status) + '</td>';
		html += '<td>' + escHtml(r.source || '—') + '</td>';
		html += '<td>' + fetched + '</td>';
		html += '<td class="myls-vt-row-actions">';
		html += '<button class="button button-small myls-vt-refetch" data-vid="' + vid + '">Re-fetch</button> ';
		if (hasTranscript) {
			html += '<button class="button button-small myls-vt-view" data-vid="' + vid + '">View</button> ';
		}
		html += '<button class="button button-small myls-vt-del" data-vid="' + vid + '">Delete</button>';
		html += '</td>';
		html += '</tr>';

		// Hidden transcript preview row
		if (hasTranscript) {
			html += '<tr class="myls-vt-preview-row" id="vt-preview-' + vid + '" style="display:none;">';
			html += '<td colspan="6"><div class="myls-vt-transcript-preview">' + escHtml(truncate(r.transcript, 2000)) + '</div></td>';
			html += '</tr>';
		}
	}

	$tbody.html(html);
}

/* ── Load data ── */
function loadData() {
	ajax('myls_vt_load', {}, function(err, r) {
		if (err || !r || !r.success) {
			showMsg('Failed to load data.', 'error');
			return;
		}
		renderRows(r.data.rows);
		updateStats(r.data.stats);
	});
}

/* ── Init ── */
$(function(){
	loadData();

	// Sync Channel Videos
	$('#myls-vt-sync').on('click', function(){
		var $btn = $(this).prop('disabled', true).text('Syncing...');
		ajax('myls_vt_sync', {}, function(err, r) {
			$btn.prop('disabled', false).text('Sync Channel Videos');
			if (err || !r) { showMsg('Sync failed: network error.', 'error'); return; }
			if (!r.success) { showMsg('Sync failed: ' + (r.data || 'Unknown error'), 'error'); return; }
			showMsg(r.data.message, 'success');
			renderRows(r.data.rows);
			updateStats(r.data.stats);
		});
	});

	// Fetch Missing Transcripts (chunked loop)
	$('#myls-vt-fetch-missing').on('click', function(){
		var $btn = $(this).prop('disabled', true).text('Fetching...');
		var $prog = $('#myls-vt-progress').show();
		var $fill = $('#myls-vt-progress-fill');
		var $text = $('#myls-vt-progress-text');
		var totalProcessed = 0;

		function fetchNext() {
			ajax('myls_vt_fetch_batch', {}, function(err, r) {
				if (err || !r || !r.success) {
					$btn.prop('disabled', false).text('Fetch Missing Transcripts');
					$prog.hide();
					showMsg('Fetch error.', 'error');
					return;
				}

				var d = r.data;
				totalProcessed += d.processed;
				var stats = d.stats;
				updateStats(stats);

				if (d.done) {
					$fill.css('width', '100%');
					$text.text('Done! ' + totalProcessed + ' videos processed.');
					$btn.prop('disabled', false).text('Fetch Missing Transcripts');
					loadData(); // refresh table
					setTimeout(function(){ $prog.hide(); }, 3000);
				} else {
					// Calculate progress
					var total = (stats.total || 1);
					var pending = (stats.pending || 0);
					var pct = Math.round(((total - pending) / total) * 100);
					$fill.css('width', pct + '%');
					$text.text(totalProcessed + ' processed, ' + d.remaining + ' remaining...');
					fetchNext(); // continue loop
				}
			});
		}

		fetchNext();
	});

	// Single Video Fetch
	$('#myls-vt-fetch-single').on('click', function(){
		var vid = $.trim($('#myls-vt-single-id').val());
		if (!vid || !/^[a-zA-Z0-9_\-]{11}$/.test(vid)) {
			$('#myls-vt-single-result').html('<span style="color:red;">Enter a valid 11-char video ID.</span>');
			return;
		}

		var $btn = $(this).prop('disabled', true).text('Fetching...');
		var $res = $('#myls-vt-single-result').html('<em>Fetching...</em>');

		ajax('myls_vt_fetch_single', {video_id: vid}, function(err, r) {
			$btn.prop('disabled', false).text('Fetch');
			if (err || !r) {
				$res.html('<span style="color:red;">Network error.</span>');
				return;
			}
			if (r.success) {
				$res.html('<span style="color:green;">' + escHtml(r.data.message) + '</span>');
				updateStats(r.data.stats);
				loadData();
			} else {
				$res.html('<span style="color:red;">' + escHtml(r.data || 'No transcript found.') + '</span>');
				loadData();
			}
		});
	});

	// Migrate Legacy
	$('#myls-vt-migrate').on('click', function(){
		var $btn = $(this).prop('disabled', true).text('Migrating...');
		ajax('myls_vt_migrate', {}, function(err, r) {
			$btn.prop('disabled', false).text('Migrate Legacy Entries');
			if (err || !r) { showMsg('Migration failed.', 'error'); return; }
			if (!r.success) { showMsg('Migration: ' + (r.data || 'No entries.'), 'warning'); return; }
			showMsg(r.data.message, 'success');
			renderRows(r.data.rows);
			updateStats(r.data.stats);
		});
	});

	// Per-row: Re-fetch
	$(document).on('click', '.myls-vt-refetch', function(){
		var vid = $(this).data('vid');
		var $btn = $(this).prop('disabled', true).text('...');
		ajax('myls_vt_refetch', {video_id: vid}, function(err, r) {
			$btn.prop('disabled', false).text('Re-fetch');
			if (err || !r) { showMsg('Re-fetch error.', 'error'); return; }
			if (r.success) {
				showMsg(r.data.message, 'success');
			} else {
				showMsg(r.data || 'Re-fetch failed.', 'warning');
			}
			loadData();
		});
	});

	// Per-row: View transcript
	$(document).on('click', '.myls-vt-view', function(){
		var vid = $(this).data('vid');
		$('#vt-preview-' + vid).toggle();
	});

	// Per-row: Delete
	$(document).on('click', '.myls-vt-del', function(){
		var vid = $(this).data('vid');
		if (!confirm('Delete transcript for ' + vid + '?')) return;
		var $btn = $(this).prop('disabled', true).text('...');
		ajax('myls_vt_delete_row', {video_id: vid}, function(err, r) {
			if (err || !r || !r.success) { showMsg('Delete error.', 'error'); $btn.prop('disabled', false).text('Delete'); return; }
			showMsg('Deleted ' + vid, 'success');
			updateStats(r.data.stats);
			loadData();
		});
	});
});

})(jQuery);
