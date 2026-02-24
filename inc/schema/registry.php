<?php
// File: inc/schema/registry.php
if ( ! defined('ABSPATH') ) exit;

/**
 * Build @graph from registered providers
 */
function myls_schema_build_graph() {
    $graph = [];
    /**
     * Subtabs should hook 'myls_schema_graph' and push nodes by reference:
     * add_filter('myls_schema_graph', function($graph) { $graph[] = [...]; return $graph; });
     */
    $graph = apply_filters('myls_schema_graph', $graph);

    // Remove empties
    $graph = array_values(array_filter($graph, function($n){ return is_array($n) && ! empty($n); }));

    if ( empty($graph) ) return null;

    return [
        '@context' => 'https://schema.org',
        '@graph'   => $graph,
    ];
}

/**
 * Emit JSON-LD in head
 */
add_action('wp_head', function(){
    $data = myls_schema_build_graph();
    if ( ! $data ) return;

    $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ( ! $json ) return;

    echo "\n<!-- AIntelligize: Schema -->\n";
    echo '<script type="application/ld+json">'.$json.'</script>'."\n";
}, 90);
