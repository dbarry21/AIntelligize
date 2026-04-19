/* global MYLS_AIV, Chart */
/**
 * AIntelligize — AI Visibility page JS
 * Path: assets/js/myls-ai-visibility.js
 *
 * Fetches chart + table data via the admin-ajax endpoints in
 * admin/tabs/ai-visibility/ajax.php and renders Chart.js instances.
 *
 * v7.9.18.108: GSC row-count selector, path prefix + AI Overview filters,
 *              query×page combos table, click-drill expansion on
 *              query/page rows, client-side text filter per table.
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

	function escapeHtml(s) {
		return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, m => ({
			'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
		}[m]));
	}

	/**
	 * Fill a table with data rows. Each column spec:
	 *   { key, num?:bool, fmt?:(v,row)=>htmlString, html?:bool }
	 * If `html` is true, the returned string is inserted as HTML; otherwise it
	 * is escaped. Rows can carry optional data attributes for drill by
	 * supplying `_drillKey` and `_drillValue` on the row object.
	 */
	function fillTable(target, rows, cols, opts) {
		const tbody = document.querySelector('[data-table="' + target + '"] tbody');
		if (!tbody) return;
		const colspan = cols.length;
		if (!rows || !rows.length) {
			tbody.innerHTML = '<tr><td colspan="' + colspan + '">No data for the current filters.</td></tr>';
			return;
		}
		const drillable = !!(opts && opts.drillable);
		tbody.innerHTML = rows.map(r => {
			const rowAttrs = drillable
				? ' class="myls-aiv-drill-trigger" data-drill-by="' + escapeHtml(r._drillKey || '') + '" data-drill-value="' + escapeHtml(r._drillValue || '') + '"'
				: '';
			return '<tr' + rowAttrs + '>' + cols.map(c => {
				const cls = c.num ? ' class="num"' : '';
				let v = r[c.key];
				if (c.fmt) v = c.fmt(v, r);
				const content = (c.html ? String(v === undefined || v === null ? '' : v) : escapeHtml(v));
				return '<td' + cls + '>' + content + '</td>';
			}).join('') + '</tr>';
		}).join('');
	}

	/** Apply an in-memory substring filter to every row in a table. Drill
	 *  panels mirror their trigger row's visibility so filtered-out rows
	 *  don't leave orphan panels behind. */
	function applyLocalFilter(target, needle) {
		const tbody = document.querySelector('[data-table="' + target + '"] tbody');
		if (!tbody) return;
		const q = (needle || '').trim().toLowerCase();
		tbody.querySelectorAll('tr').forEach(tr => {
			if (tr.classList.contains('myls-aiv-drill-panel')) {
				const prev = tr.previousElementSibling;
				tr.style.display = (prev && prev.style.display === 'none') ? 'none' : '';
				return;
			}
			if (!q) { tr.style.display = ''; return; }
			tr.style.display = tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
		});
	}

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
	 * AJAX helper
	 * ----------------------------------------------------------------- */

	async function postAJAX(action, payload) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', MYLS_AIV.nonce || '');
		Object.keys(payload || {}).forEach(k => {
			const v = payload[k];
			if (v === undefined || v === null) return;
			fd.append(k, v);
		});

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

	/* -----------------------------------------------------------------
	 * Crawlers + Referrers loaders (unchanged)
	 * ----------------------------------------------------------------- */

	async function loadCrawlers(days) {
		setStatus('crawlers', 'Loading…');
		try {
			const d = await postAJAX('myls_aiv_crawlers', { days });
			setKpi('crawlers-total', fmt(d.total));
			setKpi('crawlers-bots',  fmt(d.bot_count));
			setKpi('crawlers-paths', fmt(d.path_count));

			renderStackedLine('myls-aiv-crawlers-chart', pivotStacked(d.by_day || [], 'bot_name', 'hits'));

			fillTable('crawlers-by-bot', d.by_bot || [], [
				{ key: 'bot_name' },
				{ key: 'hits', num: true, fmt: v => fmt(Number(v)) },
			]);

			fillTable('crawlers-top-paths', d.top_paths || [], [
				{ key: 'url_path' },
				{ key: 'bot_name' },
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
				{ key: 'source' },
				{ key: 'visits', num: true, fmt: v => fmt(Number(v)) },
			]);

			fillTable('referrers-top-pages', d.top_pages || [], [
				{ key: 'title', html: true, fmt: (v, r) => {
					const label = escapeHtml(v || r.landing);
					if (r.landing) {
						return '<a href="' + escapeHtml(r.landing) + '" target="_blank" rel="noopener">' + label + '</a>';
					}
					return label;
				}},
				{ key: 'source' },
				{ key: 'visits', num: true, fmt: v => fmt(Number(v)) },
			]);

			setStatus('referrers', '');
		} catch (e) {
			setStatus('referrers', e.message, true);
		}
	}

	/* -----------------------------------------------------------------
	 * GSC loader with filters + combos
	 * ----------------------------------------------------------------- */

	function currentGscFilters() {
		const rowsSel = document.querySelector('.myls-aiv-gsc-rows');
		const pfxInp  = document.querySelector('.myls-aiv-gsc-prefix');
		const aioChk  = document.querySelector('.myls-aiv-gsc-aio');
		return {
			rows: rowsSel ? Number(rowsSel.value) : 100,
			path_prefix: pfxInp ? pfxInp.value.trim() : '',
			ai_overview: aioChk && aioChk.checked ? '1' : '0',
		};
	}

	async function loadGsc(days) {
		setStatus('gsc', 'Loading…');
		try {
			const filters = currentGscFilters();
			const d = await postAJAX('myls_aiv_gsc', Object.assign({ days }, filters));

			setKpi('gsc-impressions', fmt(d.impressions));
			setKpi('gsc-clicks',      fmt(d.clicks));
			setKpi('gsc-ctr',         (Number(d.ctr) * 100).toFixed(2) + '%');
			setKpi('gsc-position',    Number(d.position).toFixed(1));
			setKpi('gsc-aio-impressions', fmt(d.aio_impressions));
			setKpi('gsc-aio-clicks',      fmt(d.aio_clicks));

			const queryRows = (d.top_queries || []).map(r => Object.assign({
				_drillKey: 'query', _drillValue: r.query,
			}, r));
			fillTable('gsc-queries', queryRows, [
				{ key: '_icon',       html: true, fmt: () => '<span class="myls-aiv-chev bi bi-chevron-right"></span>' },
				{ key: 'query' },
				{ key: 'impressions', num: true, fmt: v => fmt(Number(v)) },
				{ key: 'clicks',      num: true, fmt: v => fmt(Number(v)) },
				{ key: 'position',    num: true, fmt: v => Number(v).toFixed(1) },
			], { drillable: true });

			const pageRows = (d.top_pages || []).map(r => Object.assign({
				_drillKey: 'page', _drillValue: r.page,
			}, r));
			fillTable('gsc-pages', pageRows, [
				{ key: '_icon',       html: true, fmt: () => '<span class="myls-aiv-chev bi bi-chevron-right"></span>' },
				{ key: 'page',        html: true, fmt: v => '<a href="' + escapeHtml(v) + '" target="_blank" rel="noopener" onclick="event.stopPropagation();">' + escapeHtml(v) + '</a>' },
				{ key: 'impressions', num: true, fmt: v => fmt(Number(v)) },
				{ key: 'clicks',      num: true, fmt: v => fmt(Number(v)) },
				{ key: 'position',    num: true, fmt: v => Number(v).toFixed(1) },
			], { drillable: true });

			fillTable('gsc-combos', d.combos || [], [
				{ key: 'query' },
				{ key: 'page', html: true, fmt: v => '<a href="' + escapeHtml(v) + '" target="_blank" rel="noopener">' + escapeHtml(v) + '</a>' },
				{ key: 'impressions', num: true, fmt: v => fmt(Number(v)) },
				{ key: 'clicks',      num: true, fmt: v => fmt(Number(v)) },
				{ key: 'position',    num: true, fmt: v => Number(v).toFixed(1) },
			]);

			// Re-apply any active local filters after a reload.
			document.querySelectorAll('.myls-aiv-local-filter').forEach(inp => {
				if (inp.value) applyLocalFilter(inp.dataset.filter, inp.value);
			});

			setStatus('gsc', d.cache === 'hit' ? 'Cached (1 hour)' : '');
		} catch (e) {
			setStatus('gsc', e.message, true);
		}
	}

	/* -----------------------------------------------------------------
	 * Drill expansion (inline row) for GSC query/page tables
	 * ----------------------------------------------------------------- */

	async function toggleDrillRow(tr) {
		const by    = tr.dataset.drillBy;
		const value = tr.dataset.drillValue;
		if (!by || !value) return;

		const colspan = tr.children.length;

		// If the next sibling is our drill panel, toggle it off.
		const next = tr.nextElementSibling;
		if (next && next.classList.contains('myls-aiv-drill-panel') && next.dataset.for === value) {
			next.remove();
			tr.classList.remove('is-open');
			return;
		}

		// Close any other open drill panels in the same table.
		const tbody = tr.parentNode;
		tbody.querySelectorAll('.myls-aiv-drill-panel').forEach(el => el.remove());
		tbody.querySelectorAll('tr.is-open').forEach(el => el.classList.remove('is-open'));

		// Insert a loading panel.
		const panel = document.createElement('tr');
		panel.className = 'myls-aiv-drill-panel';
		panel.dataset.for = value;
		panel.innerHTML = '<td colspan="' + colspan + '">Loading…</td>';
		tr.parentNode.insertBefore(panel, tr.nextSibling);
		tr.classList.add('is-open');

		try {
			const rangeSel = document.querySelector('.myls-aiv-range[data-target="gsc"]');
			const days = rangeSel ? Number(rangeSel.value) : 28;
			const filters = currentGscFilters();
			const d = await postAJAX('myls_aiv_gsc_drill', Object.assign({ days, by, value }, filters));

			const otherLabel = d.other === 'page' ? 'Landing page' : 'Query';
			const rows = d.rows || [];
			if (!rows.length) {
				panel.innerHTML = '<td colspan="' + colspan + '">No related ' + escapeHtml(d.other) + 's in range.</td>';
				return;
			}
			const head = '<thead><tr><th>' + otherLabel + '</th><th class="num">Impr.</th><th class="num">Clicks</th><th class="num">Pos.</th></tr></thead>';
			const body = rows.map(r => {
				const otherVal = r[d.other] || '';
				const label = d.other === 'page'
					? '<a href="' + escapeHtml(otherVal) + '" target="_blank" rel="noopener">' + escapeHtml(otherVal) + '</a>'
					: escapeHtml(otherVal);
				return '<tr>' +
					'<td>' + label + '</td>' +
					'<td class="num">' + fmt(Number(r.impressions)) + '</td>' +
					'<td class="num">' + fmt(Number(r.clicks))      + '</td>' +
					'<td class="num">' + Number(r.position).toFixed(1) + '</td>' +
					'</tr>';
			}).join('');

			panel.innerHTML = '<td colspan="' + colspan + '"><div class="myls-aiv-drill-inner"><table class="widefat striped">' + head + '<tbody>' + body + '</tbody></table></div></td>';
		} catch (e) {
			panel.innerHTML = '<td colspan="' + colspan + '"><em>' + escapeHtml(e.message) + '</em></td>';
		}
	}

	/* -----------------------------------------------------------------
	 * Boot
	 * ----------------------------------------------------------------- */

	function init() {
		// Range pickers (per subtab).
		document.querySelectorAll('.myls-aiv-range').forEach(sel => {
			sel.addEventListener('change', () => {
				const target = sel.dataset.target;
				const days = Number(sel.value);
				if (target === 'crawlers') loadCrawlers(days);
				if (target === 'referrers') loadReferrers(days);
				if (target === 'gsc') loadGsc(days);
			});
		});

		// GSC filter controls — debounce the "Apply" click so accidental
		// double-clicks don't fire duplicate API calls mid-flight.
		const applyBtn = document.querySelector('.myls-aiv-gsc-apply');
		if (applyBtn) {
			applyBtn.addEventListener('click', () => {
				const rangeSel = document.querySelector('.myls-aiv-range[data-target="gsc"]');
				const days = rangeSel ? Number(rangeSel.value) : 28;
				loadGsc(days);
			});
		}

		// Local (client-side) filter inputs on each table.
		document.querySelectorAll('.myls-aiv-local-filter').forEach(inp => {
			inp.addEventListener('input', () => {
				applyLocalFilter(inp.dataset.filter, inp.value);
			});
		});

		// Drill expansion: delegated click on rows inside drillable tables.
		document.querySelectorAll('.myls-aiv-drillable').forEach(table => {
			table.addEventListener('click', ev => {
				const tr = ev.target.closest('tr.myls-aiv-drill-trigger');
				if (!tr || !table.contains(tr)) return;
				// Ignore clicks on inner links (let them navigate).
				if (ev.target.closest('a')) return;
				toggleDrillRow(tr);
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
