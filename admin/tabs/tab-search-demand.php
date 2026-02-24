<?php
/**
 * Admin Tab: Search Demand
 * Path: admin/tabs/tab-search-demand.php
 *
 * Top-level tab that auto-discovers subtabs from
 * admin/tabs/search-demand/subtab-*.php
 *
 * @since 6.3.2.7
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Shared CSS for all Search Demand subtabs.
 * Called from each subtab's footer script hook.
 */
if ( ! function_exists('myls_sd_shared_css') ) {
  function myls_sd_shared_css() {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <style>
    /* ── Badges ── */
    .myls-sd-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;line-height:1.4}
    .myls-sd-high{background:#d4edda;color:#155724}
    .myls-sd-medium{background:#fff3cd;color:#856404}
    .myls-sd-low{background:#ffe0cc;color:#8a4500}
    .myls-sd-none{background:#f8d7da;color:#721c24}
    .myls-sd-error{background:#e2e3e5;color:#383d41}
    .myls-sd-pending{background:#f0f0f1;color:#787c82}
    .myls-sd-info{background:#e8f4fd;color:#0073aa}
    .myls-sd-checking{background:#e8f4fd;color:#0073aa;animation:myls-pulse 1.2s ease-in-out infinite}
    @keyframes myls-pulse{0%,100%{opacity:1}50%{opacity:.5}}

    /* ── Inline spinners ── */
    .myls-sd-inline-spinner{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#2271b1}
    .myls-spin{animation:myls-spin-anim 1s linear infinite}
    @keyframes myls-spin-anim{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

    /* ── Summary bar ── */
    .myls-sd-summary{padding:8px 12px;background:#f0f0f1;border-radius:4px;display:flex;flex-wrap:wrap;align-items:center;gap:6px}

    /* ── Progress bar ── */
    .myls-sd-progress-bar-outer{width:100%;height:22px;background:#e5e5e5;border-radius:4px;overflow:hidden}
    .myls-sd-progress-bar-inner{height:100%;background:#2271b1;border-radius:4px;transition:width .3s ease}

    /* ── Tables ── */
    .myls-sd-table{margin-top:6px;width:100%}
    .myls-sd-table th{font-weight:600;background:#f6f7f7;padding:8px 10px}
    .myls-sd-table td{vertical-align:top;padding:8px 10px}
    .myls-sd-table .myls-sd-row-active{background:#f0f6fc !important}
    .myls-sd-group-header td{background:#f0f6fc !important;font-weight:600;border-top:2px solid #c3c4c7;padding:6px 10px}
    .myls-sd-match-tag{display:inline-block;background:#e8f4fd;color:#0073aa;padding:1px 6px;border-radius:3px;font-size:11px;margin:1px 2px}
    .myls-sd-suggestions{font-size:12px;color:#50575e;line-height:1.6}
    .myls-sd-more{margin-top:4px}
    .myls-sd-toggle{font-size:11px}

    /* ── Focus keyword panel ── */
    .myls-fk-panel{background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:10px 14px}
    .myls-fk-grid{display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:start}
    @media(max-width:600px){.myls-fk-grid{grid-template-columns:1fr}}

    /* ── Row spinner ── */
    .myls-sd-cell-spinner{display:inline-flex;align-items:center;gap:4px;color:#2271b1;font-size:12px}
    .myls-sd-cell-spinner .dashicons{font-size:16px;width:16px;height:16px}

    /* ── AC suggestion cards ── */
    .myls-fkac-group{margin-bottom:16px;border:1px solid #ddd;border-radius:4px;overflow:hidden}
    .myls-fkac-group-header{background:#f6f7f7;padding:8px 12px;font-weight:600;border-bottom:1px solid #ddd;display:flex;align-items:center;justify-content:space-between}
    .myls-fkac-group-header small{font-weight:400;color:#787c82}
    .myls-fkac-list{padding:8px 12px}
    .myls-fkac-item{padding:4px 0;border-bottom:1px solid #f0f0f1;font-size:13px}
    .myls-fkac-item:last-child{border-bottom:none}
    .myls-fkac-query-tag{display:inline-block;background:#f0f0f1;color:#50575e;padding:1px 6px;border-radius:3px;font-size:11px;font-family:monospace}
    .myls-fkac-no-kw{padding:20px;text-align:center;color:#787c82}

    /* ── FK AC inline detail groups ── */
    .myls-fkac-detail{border:1px solid #e5e5e5;border-radius:4px;background:#fafafa;padding:6px}
    .myls-fkac-mini-group{margin-bottom:6px}
    .myls-fkac-mini-group:last-child{margin-bottom:0}
    .myls-fkac-mini-header{font-size:11px;padding:3px 6px;background:#f0f0f1;border-radius:3px;margin-bottom:2px}
    .myls-fkac-mini-item{font-size:12px;padding:2px 6px;color:#1d2327}
    .myls-fkac-expand{font-size:11px;margin-left:4px}

    /* ── Sub-grid table (AC suggestions + GSC data) ── */
    .myls-fkac-subgrid{width:100%;border-collapse:collapse;font-size:12px}
    .myls-fkac-subgrid th{background:#f0f0f1;padding:4px 8px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;border-bottom:2px solid #c3c4c7}
    .myls-fkac-subgrid td{padding:4px 8px;border-bottom:1px solid #f0f0f1}
    .myls-fkac-subgrid tr:last-child td{border-bottom:none}
    .myls-fkac-gsc-match{background:#f0fdf4 !important}
    .myls-fkac-gsc-bonus{background:#fefce8 !important}
    .myls-fkac-bonus-header td{background:#fef3c7 !important;padding:6px 8px !important;border-top:2px solid #d97706}
    .myls-fkac-type-tag{display:inline-block;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:600;background:#e5e5e5;color:#50575e}
    .myls-fkac-type-gsc{background:#e8f4fd;color:#0073aa}
    .myls-fkac-gsc-status{margin-left:6px}
    .myls-fkac-ai-badge{background:#ede9fe;color:#6d28d9}
    .myls-fkac-ai-tag{display:inline-block;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;background:#ede9fe;color:#6d28d9}
    .text-end{text-align:right}
    .fw-semibold{font-weight:600}

    /* ── Dashboard detail rows ── */
    .sd-detail-inner{padding:12px 16px;background:#fafbfc;border-top:2px solid var(--ms-accent,#2271b1)}
    .sd-expand{color:var(--ms-accent,#2271b1);text-decoration:none;font-size:16px}
    .sd-expand:hover{color:#135e96}
    .sd-detail-row td{background:#fafbfc !important}

    /* ── Print styles ── */
    @media print {
      /* Hide WP admin chrome */
      #adminmenumain, #wpadminbar, #wpfooter, #screen-meta,
      .notice, .updated, .error,
      .myls-no-print,
      .myls-sd-inline-spinner,
      .myls-sd-progress-bar-outer,
      button, .button,
      select, input,
      label.form-label,
      .description,
      .myls-fkac-expand,
      .sd-expand,
      .ms-period-selector { display: none !important; }

      #wpcontent, #wpbody, #wpbody-content { margin-left: 0 !important; padding: 0 !important; }

      /* Expand all details */
      .myls-fkac-detail { display: block !important; }

      /* Table readability */
      .myls-sd-table, .myls-fkac-subgrid { font-size: 10px; page-break-inside: auto; }
      .myls-sd-table tr, .myls-fkac-subgrid tr { page-break-inside: avoid; }
      .myls-fkac-subgrid th { background: #eee !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .myls-fkac-gsc-match { background: #f0fdf4 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .myls-fkac-gsc-bonus { background: #fefce8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .myls-fkac-ai-badge, .myls-fkac-ai-tag { background: #ede9fe !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .myls-sd-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

      /* Card headers */
      .ms-card { border: 1px solid #ccc; margin-bottom: 12px; page-break-inside: avoid; }
      .myls-card { border: 1px solid #ccc; margin-bottom: 12px; page-break-inside: avoid; }
      .ms-card-title .bi, .myls-card-header { border-bottom: 1px solid #ccc; }

      /* Dashboard detail rows visible */
      .sd-detail-row { display: table-row !important; }
      .sd-detail-inner { background: #fafbfc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

      /* KPI cards */
      .ms-kpi { border: 1px solid #ccc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

      /* Summary always visible */
      .myls-sd-summary { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    </style>
    <?php
  }
}

myls_register_admin_tab([
  'id'    => 'search-demand',
  'title' => 'Search Demand',
  'order' => 45,
  'cap'   => 'manage_options',
  'icon'  => 'bi-graph-up-arrow',
  'cb'    => function () {
    $subtabs = [];
    foreach ( glob( __DIR__ . '/search-demand/subtab-*.php' ) as $file ) {
      $spec = include $file;
      if ( is_array($spec) && isset($spec['id']) ) {
        $subtabs[ $spec['id'] ] = $spec;
      }
    }
    usort( $subtabs, function($a, $b) {
      return ( $a['order'] ?? 0 ) <=> ( $b['order'] ?? 0 );
    });
    myls_render_subtabs( 'search-demand', $subtabs );
  }
]);
