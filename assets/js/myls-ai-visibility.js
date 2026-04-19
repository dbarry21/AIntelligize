/* global MYLS_AIV, Chart */
/**
 * AIntelligize — AI Visibility page JS
 * Path: assets/js/myls-ai-visibility.js
 *
 * Fetches chart + table data via the admin-ajax endpoints in
 * admin/tabs/ai-visibility/ajax.php and renders Chart.js instances.
 */
(function () {
	'use strict';

	if (typeof MYLS_AIV === 'undefined') return;

	const CHARTS = {};
	const PALETTE = [
		'#0d6efd', '#198754', '#dc3545', '#fd7e14', '#6610f2',
		'#20c997', '#d63384', '#6c757d', '#ffc107', '#0dcaf0',
		'#adb5bd', '#6f42c1'
	];

	function fmt(n) {
		if (n === null || n === undefined) return '—';
		if (typeof n !== 'number') n = Number(n) || 0;
		if (n >= 1000) return n.toLocaleString();
		if (!Number.isInteger(n)) return n.toFixed(2);
		return String(n);
	}

	function setKpi(key, value) {
		document.querySelectorAll('[data-kpi="' + key + '"]').forEach(el => {
			el.textContent = value;
		});
	}

	function setStatus(target, msg, isError) {
		document.querySelectorAll('[data-status="' + target + '"]').forEach(el => {
			el.textContent = msg || '';
			el.classList.toggle('is-error', !!isError);
		});
	}

	function fillTable(target, rows, cols) {
		const tbody = document.querySelector('[data-table="' + target + '"] tbody');
		if (!tbody) return;
		if (!rows || !rows.length) {
			tbody.innerHTML = '<tr><td colspan="' + cols.length + '">No data yet for this range.</td></tr>';
			return;
		}
		tbody.innerHTML = rows.map(r => {
			return '<tr>' + cols.map(c => {
				const cls = c.num ? ' class="num"' : '';
				let v = r[c.key];
				if (c.fmt) v = c.fmt(v, r);
				return '<td' + cls + '>' + (v === undefined || v === null ? '' : v) + '</td>';
			}).join('') + '</tr>';
		}).join('');
	}

	function escapeHtml(s) {
		return String(s || '').replace(/[&<>"']/g, m => ({
			'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
		}[m]));
	}

	/**
	 * Pivot a flat [{day, key_field, hits/visits}] list into Chart.js
	 * stacked-line datasets.
	 */
	function pivotStacked(rows, keyField, valueField) {
		const days = [];
		const daySet = new Set();
		const bySeries = {};

		rows.forEach(r => {
			const d = r.day;
			if (!daySet.has(d)) { daySet.add(d); days.push(d); }
			const k = r[keyField] || '(unknown)';
			bySeries[k] = bySeries[k] || {};
			bySeries[k][d] = (bySeries[k][d] || 0) + (Number(r[valueField]) || 0);
		});

		const seriesKeys = Object.keys(bySeries).sort((a, b) => {
			const sa = Object.values(bySeries[a]).reduce((x, y) => x + y, 0);
			const sb = Object.values(bySeries[b]).reduce((x, y) => x + y, 0);
			return sb - sa;
		});

		const datasets = seriesKeys.map((k, i) => ({
			label: k,
			data: days.map(d => bySeries[k][d] || 0),
			backgroundColor: PALETTE[i % PALETTE.length],
			borderColor: PALETTE[i % PALETTE.length],
			borderWidth: 2,
			fill: true,
			tension: 0.2,
			pointRadius: 2,
		}));

		return { labels: days, datasets };
	}

	function renderStackedLine(canvasId, pivot) {
		if (CHARTS[canvasId]) {
			CHARTS[canvasId].data = pivot;
			CHARTS[canvasId].update();
			return;
		}
		const ctx = document.getElementById(canvasId);
		if (!ctx) return;
		CHARTS[canvasId] = new Chart(ctx, {
			type: 'line',
			data: pivot,
			options: {
				responsive: true,
				interaction: { mode: 'index', intersect: false },
				scales: {
					y: { stacked: true, beginAtZero: true },
					x: { stacked: true }
				},
				plugins: {
					legend: { position: 'bottom' },
					tooltip: { mode: 'index', intersect: false }
				}
			}
		});
	}

	/* -----------------------------------------------------------------
	 * Data loaders
	 * ----------------------------------------------------------------- */

	async function postAJAX(action, payload) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', MYLS_AIV.nonce || '');
		Object.keys(payload || {}).forEach(k => fd.append(k, payload[k]));

		const res = await fetch(MYLS_AIV.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd });
		const txt = await res.text();
		let json;
		try { json = JSON.parse(txt); }
		catch (e) { throw new Error('Invalid JSON response (HTTP ' + res.status + ')'); }
		if (!json || json.success !== true) {
			const msg = (json && json.data && json.data.message) ? json.data.message : 'Request failed.';
			throw new Error(msg);
		}
		return json.data;
	}

	async function loadCrawlers(days) {
		setStatus('crawlers', 'Loading…');
		try {
			const d = await postAJAX('myls_aiv_crawlers', { days });
			setKpi('crawlers-total', fmt(d.total));
			setKpi('crawlers-bots',  fmt(d.bot_count));
			setKpi('crawlers-paths', fmt(d.path_count));

			renderStackedLine('myls-aiv-crawlers-chart', pivotStacked(d.by_day || [], 'bot_name', 'hits'));

			fillTable('crawlers-by-bot', d.by_bot || [], [
				{ key: 'bot_name', fmt: v => escapeHtml(v) },
				{ key: 'hits', num: true, fmt: v => fmt(Number(v)) },
			]);

			fillTable('crawlers-top-paths', d.top_paths || [], [
				{ key: 'url_path', fmt: v => escapeHtml(v) },
				{ key: 'bot_name', fmt: v => escapeHtml(v) },
				{ key: 'hits', num: true, fmt: v => fmt(Number(v)) },
			]);

			setStatus('crawlers', '');
		} catch (e) {
			setStatus('crawlers', e.message, true);
		}
	}

	async function loadReferrers(days) {
		setStatus('referrers', 'Loading…');
		try {
			const d = await postAJAX('myls_aiv_referrers', { days });
			setKpi('referrers-total',   fmt(d.total));
			setKpi('referrers-sources', fmt(d.sources_count));
			setKpi('referrers-pages',   fmt(d.pages_count));

			renderStackedLine('myls-aiv-referrers-chart', pivotStacked(d.by_day || [], 'source', 'visits'));

			fillTable('referrers-by-source', d.by_source || [], [
				{ key: 'source', fmt: v => escapeHtml(v) },
				{ key: 'visits', num: true, fmt: v => fmt(Number(v)) },
			]);

			fillTable('referrers-top-pages', d.top_pages || [], [
				{ key: 'title', fmt: (v, r) => {
					const label = escapeHtml(v || r.landing);
					if (r.post_id) {
						return '<a href="' + escapeHtml(r.landing) + '" target="_blank" rel="noopener">' + label + '</a>';
					}
					return label;
				}},
				{ key: 'source', fmt: v => escapeHtml(v) },
				{ key: 'visits', num: true, fmt: v => fmt(Number(v)) },
			]);

			setStatus('referrers', '');
		} catch (e) {
			setStatus('referrers', e.message, true);
		}
	}

	async function loadGsc(days) {
		setStatus('gsc', 'Loading…');
		try {
			const d = await postAJAX('myls_aiv_gsc', { days });
			setKpi('gsc-impressions', fmt(d.impressions));
			setKpi('gsc-clicks',      fmt(d.clicks));
			setKpi('gsc-ctr',         (Number(d.ctr) * 100).toFixed(2) + '%');
			setKpi('gsc-position',    Number(d.position).toFixed(1));
			setKpi('gsc-aio-impressions', fmt(d.aio_impressions));
			setKpi('gsc-aio-clicks',      fmt(d.aio_clicks));

			fillTable('gsc-queries', d.top_queries || [], [
				{ key: 'query', fmt: v => escapeHtml(v) },
				{ key: 'impressions', num: true, fmt: v => fmt(Number(v)) },
				{ key: 'clicks',      num: true, fmt: v => fmt(Number(v)) },
				{ key: 'position',    num: true, fmt: v => Number(v).toFixed(1) },
			]);

			fillTable('gsc-pages', d.top_pages || [], [
				{ key: 'page', fmt: v => '<a href="' + escapeHtml(v) + '" target="_blank" rel="noopener">' + escapeHtml(v) + '</a>' },
				{ key: 'impressions', num: true, fmt: v => fmt(Number(v)) },
				{ key: 'clicks',      num: true, fmt: v => fmt(Number(v)) },
				{ key: 'position',    num: true, fmt: v => Number(v).toFixed(1) },
			]);

			setStatus('gsc', d.cache === 'hit' ? 'Cached (1 hour)' : '');
		} catch (e) {
			setStatus('gsc', e.message, true);
		}
	}

	/* -----------------------------------------------------------------
	 * Boot
	 * ----------------------------------------------------------------- */

	function init() {
		document.querySelectorAll('.myls-aiv-range').forEach(sel => {
			sel.addEventListener('change', () => {
				const target = sel.dataset.target;
				const days = Number(sel.value);
				if (target === 'crawlers') loadCrawlers(days);
				if (target === 'referrers') loadReferrers(days);
				if (target === 'gsc') loadGsc(days);
			});
		});

		// Only load the currently visible subtab's data.
		if (document.getElementById('myls-aiv-crawlers-chart')) loadCrawlers(28);
		if (document.getElementById('myls-aiv-referrers-chart')) loadReferrers(28);
		if (document.querySelector('[data-table="gsc-queries"]')) loadGsc(28);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
