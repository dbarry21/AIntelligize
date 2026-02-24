<?php
/**
 * GitHub Plugin Updater for AIntelligize
 * Author: Dave Barry
 */
if ( ! defined('ABSPATH') ) exit;

/** ─────────────────────────────────────────────────────────────────────
 * Configure your GitHub repo here (owner/repo). Adjust if needed.
 * ──────────────────────────────────────────────────────────────────── */
if ( ! defined('MYLS_GH_USER') ) define('MYLS_GH_USER', 'dbarry21');
if ( ! defined('MYLS_GH_REPO') ) define('MYLS_GH_REPO', 'AIntelligize');

/** Resolve plugin identifiers */
$__myls_slug       = 'aintelligize';
$__myls_basename   = defined('MYLS_BASENAME') ? MYLS_BASENAME : plugin_basename(dirname(__DIR__) . '/aintelligize.php');
$__myls_main_file  = WP_PLUGIN_DIR . '/' . $__myls_basename;

/** Utility: current plugin version (prefer header; fallback to constant) */
if ( ! function_exists('__myls_get_version') ) {
    function __myls_get_version($file) {
        if ( ! function_exists('get_plugin_data') ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = @get_plugin_data($file, false, false);
        if ( ! empty($data['Version']) ) return $data['Version'];
        if ( defined('MYLS_VERSION') ) return MYLS_VERSION;
        return '0.0.0';
    }
}

/**
 * Update check: compare local version to latest GitHub release (or latest tag).
 */
add_filter('pre_set_site_transient_update_plugins', function($transient) use ($__myls_slug, $__myls_basename, $__myls_main_file) {
    if ( empty($transient->checked) ) return $transient;

    $current_version = __myls_get_version($__myls_main_file);

    // 1) Try latest release API
    $release_api = sprintf('https://api.github.com/repos/%s/%s/releases/latest', MYLS_GH_USER, MYLS_GH_REPO);
    $args = [
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version'),
        ],
        'timeout' => 15,
    ];
    $response = wp_remote_get($release_api, $args);

    $remote_version = null;
    $package_url    = null;

    if ( ! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 ) {
        $release = json_decode(wp_remote_retrieve_body($response), true);
        if ( ! empty($release['tag_name']) ) {
            $remote_version = ltrim($release['tag_name'], 'v');
            // Prefer release asset; fallback to zipball_url
            if ( ! empty($release['assets'][0]['browser_download_url']) ) {
                $package_url = $release['assets'][0]['browser_download_url'];
            } elseif ( ! empty($release['zipball_url']) ) {
                $package_url = $release['zipball_url'];
            } else {
                // Construct tag zip URL as final fallback
                $package_url = sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip', MYLS_GH_USER, MYLS_GH_REPO, $release['tag_name']);
            }
        }
    }

    // 2) If no releases, fall back to latest tag
    if ( ! $remote_version ) {
        $tags_api = sprintf('https://api.github.com/repos/%s/%s/tags', MYLS_GH_USER, MYLS_GH_REPO);
        $tags_resp = wp_remote_get($tags_api, $args);
        if ( ! is_wp_error($tags_resp) && wp_remote_retrieve_response_code($tags_resp) === 200 ) {
            $tags = json_decode(wp_remote_retrieve_body($tags_resp), true);
            if ( is_array($tags) && ! empty($tags[0]['name']) ) {
                $tag = $tags[0]['name'];
                $remote_version = ltrim($tag, 'v');
                $package_url    = sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip', MYLS_GH_USER, MYLS_GH_REPO, $tag);
            }
        }
    }

    if ( $remote_version && version_compare($current_version, $remote_version, '<') ) {
        $transient->response[$__myls_basename] = (object) [
            'slug'        => $__myls_slug,
            'plugin'      => $__myls_basename,
            'new_version' => $remote_version,
            'url'         => sprintf('https://github.com/%s/%s', MYLS_GH_USER, MYLS_GH_REPO),
            'package'     => $package_url,
            // Optional fields WP recognizes:
            // 'tested'   => get_bloginfo('version'),
            // 'requires' => '6.0',
        ];
    }

    return $transient;
});

/**
 * Plugin info modal (when clicking "View details").
 */
add_filter('plugins_api', function ($result, $action, $args) use ($__myls_slug) {
    if ( $action !== 'plugin_information' || empty($args->slug) || $args->slug !== $__myls_slug ) {
        return $result;
    }

    $info = (object) [
        'name'        => 'AIntelligize',
        'slug'        => $__myls_slug,
        'version'     => defined('MYLS_VERSION') ? MYLS_VERSION : '1.0.0',
        'author'      => '<a href="https://github.com/' . esc_attr(MYLS_GH_USER) . '">Dave Barry</a>',
        'homepage'    => 'https://github.com/' . esc_attr(MYLS_GH_USER) . '/' . esc_attr(MYLS_GH_REPO),
        'sections'    => [
            'description' => '<p>Modular local SEO toolkit with admin tabs, CPTs, Schema, and more.</p>',
            'changelog'   => '<p>See releases on GitHub.</p>',
        ],
        'download_link' => 'https://github.com/' . esc_attr(MYLS_GH_USER) . '/' . esc_attr(MYLS_GH_REPO) . '/releases/latest',
        // Optional:
        // 'requires'      => '6.0',
        // 'tested'        => get_bloginfo('version'),
        // 'requires_php'  => '7.4',
    ];

    return $info;
}, 10, 3);

/**
 * Ensure extracted GitHub zip folder name matches the plugin folder.
 * (GitHub zips often extract as repo-<hash> or repo-<tag>; WP expects folder name to match the installed plugin.)
 */
add_filter('upgrader_source_selection', function($source, $remote_source, $upgrader, $hook_extra) use ($__myls_basename) {
    if ( empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $__myls_basename ) {
        return $source;
    }

    // Desired folder name = current plugin folder (e.g., 'aintelligize')
    $desired = dirname($__myls_basename);
    $parent  = dirname($source);
    $current = basename($source);

    if ( $current === $desired ) return $source;

    $new_source = trailingslashit($parent) . $desired . '/';

    // Attempt to rename extracted directory
    if ( @rename($source, $new_source) ) {
        return $new_source;
    }

    return $source; // fallback
}, 10, 4);
