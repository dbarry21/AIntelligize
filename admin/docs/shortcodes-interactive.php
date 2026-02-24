<?php
/**
 * AIntelligize – Interactive Shortcodes Documentation
 * File: admin/docs/shortcodes-interactive.php
 *
 * Redesigned in v5.0 — single-column accordion layout
 * - Persistent search with instant filtering
 * - Category pill filters
 * - One-click copy for shortcode and examples
 * - Collapsible attribute tables and examples
 * - Clean, scannable layout
 *
 * @since 5.0.0
 */

if (!defined('ABSPATH')) exit;

$shortcodes_data = mlseo_compile_shortcode_documentation();

$categories = [
    'location' => ['label' => 'Location',       'icon' => '📍', 'color' => '#4CAF50'],
    'services' => ['label' => 'Services',        'icon' => '🔧', 'color' => '#2196F3'],
    'content'  => ['label' => 'Content',         'icon' => '📄', 'color' => '#FF9800'],
    'schema'   => ['label' => 'Schema & SEO',    'icon' => '🏷️', 'color' => '#9C27B0'],
    'social'   => ['label' => 'Social',          'icon' => '🔗', 'color' => '#00BCD4'],
    'youtube'  => ['label' => 'YouTube',         'icon' => '🎥', 'color' => '#FF0000'],
    'utility'  => ['label' => 'Utility & Tools', 'icon' => '⚙️', 'color' => '#607D8B'],
];

// Count per category
$cat_counts = [];
foreach ($shortcodes_data as $sc) {
    $cat = $sc['category'] ?? 'utility';
    $cat_counts[$cat] = ($cat_counts[$cat] ?? 0) + 1;
}
?>

<style>
/* Layout */
.myls-sc-docs { max-width: 960px; margin: 0 auto; }

/* Search */
.myls-sc-search {
    position: sticky; top: 32px; z-index: 100;
    background: #fff; padding: 16px 0 12px; margin-bottom: 8px;
    border-bottom: 1px solid #e0e0e0;
}
.myls-sc-search input {
    width: 100%; padding: 10px 16px; font-size: 15px;
    border: 2px solid #ddd; border-radius: 8px; outline: none;
    transition: border-color 0.2s;
}
.myls-sc-search input:focus { border-color: #2271b1; }

/* Category pills */
.myls-sc-pills {
    display: flex; flex-wrap: wrap; gap: 6px;
    padding: 10px 0; margin-bottom: 12px;
}
.myls-sc-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 14px; border-radius: 20px; font-size: 13px; font-weight: 500;
    border: 1px solid #ccc; background: #fff; cursor: pointer;
    transition: all 0.15s;
}
.myls-sc-pill:hover { background: #f0f6fc; border-color: #2271b1; }
.myls-sc-pill.active { background: #2271b1; color: #fff; border-color: #2271b1; }
.myls-sc-pill .pill-count {
    font-size: 11px; opacity: 0.7; margin-left: 2px;
}

/* Stats */
.myls-sc-stats {
    font-size: 13px; color: #666; padding: 6px 0 14px;
}

/* Shortcode items */
.myls-sc-item {
    border: 1px solid #e0e0e0; border-radius: 8px;
    margin-bottom: 10px; background: #fff;
    transition: box-shadow 0.15s;
}
.myls-sc-item:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }

/* Item header (always visible) */
.myls-sc-header {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; cursor: pointer; user-select: none;
}
.myls-sc-header:hover { background: #fafafa; }

.myls-sc-cat-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
}
.myls-sc-name {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 15px; font-weight: 700; color: #1d2327;
    min-width: 200px;
}
.myls-sc-desc {
    font-size: 13px; color: #555; flex: 1;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.myls-sc-copy-short {
    flex-shrink: 0; padding: 3px 10px; font-size: 12px;
    border: 1px solid #ccc; border-radius: 4px; background: #f9f9f9;
    cursor: pointer; transition: all 0.15s; white-space: nowrap;
}
.myls-sc-copy-short:hover { background: #2271b1; color: #fff; border-color: #2271b1; }
.myls-sc-copy-short.copied { background: #00a32a; color: #fff; border-color: #00a32a; }
.myls-sc-chevron {
    flex-shrink: 0; font-size: 16px; color: #999; transition: transform 0.2s;
}
.myls-sc-item.open .myls-sc-chevron { transform: rotate(90deg); }

/* Detail panel (collapsible) */
.myls-sc-detail {
    display: none; padding: 0 16px 16px;
    border-top: 1px solid #f0f0f0;
}
.myls-sc-item.open .myls-sc-detail { display: block; }

/* Basic usage box */
.myls-sc-usage {
    display: flex; align-items: center; gap: 10px;
    background: #f6f7f7; border-left: 3px solid #2271b1;
    padding: 10px 14px; margin: 12px 0;
    font-family: monospace; font-size: 14px;
}
.myls-sc-usage code { flex: 1; font-size: 14px; }
.myls-sc-usage button {
    padding: 2px 10px; font-size: 11px;
    border: 1px solid #ccc; border-radius: 3px; background: #fff; cursor: pointer;
}
.myls-sc-usage button:hover { background: #2271b1; color: #fff; border-color: #2271b1; }

/* Attribute table */
.myls-sc-attrs { width: 100%; border-collapse: collapse; font-size: 13px; margin: 10px 0; }
.myls-sc-attrs th {
    text-align: left; padding: 6px 10px; background: #f0f6fc;
    font-weight: 600; font-size: 12px; text-transform: uppercase; color: #555;
}
.myls-sc-attrs td { padding: 6px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.myls-sc-attrs .attr-name {
    font-family: monospace; font-weight: 600; color: #d63638; white-space: nowrap;
}
.myls-sc-attrs .attr-default {
    font-family: monospace; background: #f0f0f1; padding: 1px 6px;
    border-radius: 3px; font-size: 12px; white-space: nowrap;
}

/* Examples */
.myls-sc-examples { margin: 10px 0; }
.myls-sc-example {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 12px; margin: 4px 0;
    background: #f9f9f9; border-radius: 4px;
}
.myls-sc-example .ex-label { font-size: 12px; color: #666; min-width: 140px; }
.myls-sc-example code { flex: 1; font-size: 13px; }
.myls-sc-example button {
    padding: 1px 8px; font-size: 11px;
    border: 1px solid #ddd; border-radius: 3px; background: #fff; cursor: pointer;
}
.myls-sc-example button:hover { background: #2271b1; color: #fff; border-color: #2271b1; }

/* Tips */
.myls-sc-tips {
    margin: 12px 0 4px; padding: 10px 14px;
    background: #fef8e7; border-left: 3px solid #dba617; border-radius: 0 4px 4px 0;
    font-size: 13px;
}
.myls-sc-tips ul { margin: 4px 0 0 16px; padding: 0; }
.myls-sc-tips li { margin: 2px 0; }

/* Section headers */
.myls-sc-section-header {
    display: flex; align-items: center; gap: 8px;
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    color: #888; letter-spacing: 0.5px; margin: 6px 0;
}

/* No results */
.myls-sc-empty {
    text-align: center; padding: 40px 20px; color: #999; display: none;
}

/* Responsive */
@media (max-width: 782px) {
    .myls-sc-header { flex-wrap: wrap; }
    .myls-sc-desc { display: none; }
    .myls-sc-name { min-width: auto; }
}
</style>

<div class="myls-sc-docs">

    <!-- Search -->
    <div class="myls-sc-search">
        <input type="text" id="myls-sc-search" placeholder="Search shortcodes — type a name, attribute, or keyword..." autocomplete="off">
    </div>

    <!-- Category pills -->
    <div class="myls-sc-pills">
        <span class="myls-sc-pill active" data-cat="all">All <span class="pill-count"><?php echo count($shortcodes_data); ?></span></span>
        <?php foreach ($categories as $key => $cat):
            $count = $cat_counts[$key] ?? 0;
            if (!$count) continue;
        ?>
            <span class="myls-sc-pill" data-cat="<?php echo esc_attr($key); ?>" style="--pill-color:<?php echo esc_attr($cat['color']); ?>">
                <?php echo $cat['icon']; ?> <?php echo esc_html($cat['label']); ?>
                <span class="pill-count"><?php echo $count; ?></span>
            </span>
        <?php endforeach; ?>
    </div>

    <!-- Stats -->
    <div class="myls-sc-stats">
        Showing <strong id="myls-sc-visible"><?php echo count($shortcodes_data); ?></strong> of <?php echo count($shortcodes_data); ?> shortcodes
    </div>

    <!-- Shortcode list -->
    <div id="myls-sc-list">
    <?php
    $last_cat = '';
    foreach ($shortcodes_data as $idx => $sc):
        $cat = $sc['category'] ?? 'utility';
        $cat_info = $categories[$cat] ?? ['label' => 'Other', 'icon' => '📦', 'color' => '#999'];
        $slug = sanitize_title($sc['name']);

        // Category section header
        if ($cat !== $last_cat):
            $last_cat = $cat;
        ?>
            <div class="myls-sc-section-header" data-cat="<?php echo esc_attr($cat); ?>">
                <span><?php echo $cat_info['icon']; ?></span>
                <?php echo esc_html($cat_info['label']); ?>
            </div>
        <?php endif; ?>

        <div class="myls-sc-item" data-cat="<?php echo esc_attr($cat); ?>"
             data-search="<?php echo esc_attr(strtolower($sc['name'] . ' ' . $sc['description'] . ' ' . implode(' ', array_keys($sc['attributes'] ?? [])))); ?>">

            <!-- Header row (always visible) -->
            <div class="myls-sc-header" data-toggle="<?php echo esc_attr($slug); ?>">
                <span class="myls-sc-cat-dot" style="background:<?php echo esc_attr($cat_info['color']); ?>"></span>
                <span class="myls-sc-name">[<?php echo esc_html($sc['name']); ?>]</span>
                <span class="myls-sc-desc"><?php echo esc_html($sc['description']); ?></span>
                <button class="myls-sc-copy-short" data-copy="[<?php echo esc_attr($sc['name']); ?>]" title="Copy shortcode" onclick="event.stopPropagation();">Copy</button>
                <span class="myls-sc-chevron">›</span>
            </div>

            <!-- Expandable detail -->
            <div class="myls-sc-detail">

                <!-- Full description -->
                <p style="margin:12px 0 6px;color:#333;"><?php echo esc_html($sc['description']); ?></p>

                <!-- Basic usage -->
                <div class="myls-sc-usage">
                    <code><?php echo esc_html($sc['basic_usage']); ?></code>
                    <button class="myls-sc-copy-btn" data-copy="<?php echo esc_attr($sc['basic_usage']); ?>">Copy</button>
                </div>

                <!-- Attributes table -->
                <?php if (!empty($sc['attributes'])): ?>
                    <table class="myls-sc-attrs">
                        <thead><tr><th>Attribute</th><th>Default</th><th>Description</th></tr></thead>
                        <tbody>
                        <?php foreach ($sc['attributes'] as $attr => $info): ?>
                            <tr>
                                <td class="attr-name"><?php echo esc_html($attr); ?></td>
                                <td><span class="attr-default"><?php echo esc_html($info['default'] ?? '—'); ?></span></td>
                                <td><?php echo esc_html($info['description'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- Examples -->
                <?php if (!empty($sc['examples'])): ?>
                    <div class="myls-sc-examples">
                        <strong style="font-size:12px;color:#555;">EXAMPLES</strong>
                        <?php foreach ($sc['examples'] as $ex): ?>
                            <div class="myls-sc-example">
                                <span class="ex-label"><?php echo esc_html($ex['label']); ?></span>
                                <code><?php echo esc_html($ex['code']); ?></code>
                                <button class="myls-sc-copy-btn" data-copy="<?php echo esc_attr($ex['code']); ?>">Copy</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Tips -->
                <?php if (!empty($sc['tips'])): ?>
                    <div class="myls-sc-tips">
                        <strong>💡 Tips</strong>
                        <ul>
                        <?php foreach ($sc['tips'] as $tip): ?>
                            <li><?php echo esc_html($tip); ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- No results -->
    <div class="myls-sc-empty" id="myls-sc-empty">
        <p style="font-size:18px;">No shortcodes match your search.</p>
        <p>Try a different keyword or clear the filter.</p>
    </div>

</div>

<script>
jQuery(function($) {
    var $search  = $('#myls-sc-search');
    var $pills   = $('.myls-sc-pill');
    var $items   = $('.myls-sc-item');
    var $headers = $('.myls-sc-section-header');
    var $visible = $('#myls-sc-visible');
    var $empty   = $('#myls-sc-empty');
    var $list    = $('#myls-sc-list');

    var activeCat = 'all';
    var query = '';

    function filterAll() {
        var count = 0;
        var visibleCats = {};

        $items.each(function() {
            var $el = $(this);
            var cat = $el.data('cat');
            var text = $el.data('search');
            var catOk = (activeCat === 'all' || cat === activeCat);
            var searchOk = (!query || text.indexOf(query) !== -1);
            if (catOk && searchOk) {
                $el.show();
                visibleCats[cat] = true;
                count++;
            } else {
                $el.hide();
            }
        });

        // Show/hide section headers
        $headers.each(function() {
            var hCat = $(this).data('cat');
            $(this).toggle(!!(visibleCats[hCat]));
        });

        $visible.text(count);
        $list.toggle(count > 0);
        $empty.toggle(count === 0);
    }

    // Search
    $search.on('input', function() {
        query = $(this).val().toLowerCase().trim();
        filterAll();
    });

    // Category pills
    $pills.on('click', function() {
        $pills.removeClass('active');
        $(this).addClass('active');
        activeCat = $(this).data('cat');
        filterAll();
    });

    // Toggle detail panels
    $(document).on('click', '.myls-sc-header', function() {
        $(this).closest('.myls-sc-item').toggleClass('open');
    });

    // Copy buttons
    $(document).on('click', '.myls-sc-copy-short, .myls-sc-copy-btn', function(e) {
        e.stopPropagation();
        var $btn = $(this);
        var text = $btn.data('copy');
        navigator.clipboard.writeText(text).then(function() {
            var orig = $btn.text();
            $btn.text('Copied!').addClass('copied');
            setTimeout(function() { $btn.text(orig).removeClass('copied'); }, 1500);
        });
    });
});
</script>

<?php
