<?php
function mlseo_render_doc_page($filename) {
    $doc_path = plugin_dir_path(__FILE__) . '../../../plugin-docs/' . $filename;
    if (file_exists($doc_path)) {
        $content = file_get_contents($doc_path);
        echo '<div class="wrap"><h1>AIntelligize Documentation</h1><pre style="white-space: pre-wrap; background: #fff; padding: 1em; border: 1px solid #ccc;">' . esc_html($content) . '</pre></div>';
    } else {
        echo '<div class="wrap"><h1>AIntelligize Documentation</h1><p>Documentation file not found.</p></div>';
    }
}
mlseo_render_doc_page('shortcodes.md');
?>
