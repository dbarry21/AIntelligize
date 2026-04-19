<?php
/**
 * Shortcode: [myls_pricing_table]
 *
 * Renders a live pricing table from the Service Schema → Price Ranges settings
 * (myls_service_price_ranges option).  Because it reads the option at render time
 * rather than baking HTML into Elementor at generation time, any changes to price
 * ranges in the admin are reflected on the page immediately — no page regeneration
 * required.
 *
 * Usage:
 *   [myls_pricing_table]                        — show all global ranges + ranges for current post
 *   [myls_pricing_table post_id="0"]            — show global ranges only (no post filtering)
 *   [myls_pricing_table post_id="123"]          — show global + ranges assigned to post 123
 *
 * Attributes:
 *   post_id  (int)     Post ID to filter by. Defaults to current post (get_the_ID()).
 *                      Pass 0 to show only global (unassigned) ranges.
 *
 * @package AIntelligize
 * @since   7.8.52
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render the pricing table HTML.
 *
 * @param  array  $atts  Shortcode attributes.
 * @return string        Table HTML.
 */
function myls_pricing_table_shortcode( array $atts ): string {
    $atts = shortcode_atts(
        [ 'post_id' => '__current__' ],
        $atts,
        'myls_pricing_table'
    );

    // Resolve post ID: '__current__' means use get_the_ID() at render time.
    if ( $atts['post_id'] === '__current__' ) {
        $post_id = (int) get_the_ID();
    } else {
        $post_id = (int) $atts['post_id'];
    }

    $price_ranges = (array) get_option( 'myls_service_price_ranges', [] );
    $rows         = [];

    foreach ( $price_ranges as $range ) {
        if ( ! is_array( $range ) ) continue;

        $label    = trim( (string) ( $range['label']    ?? '' ) );
        $low      = trim( (string) ( $range['low']      ?? '' ) );
        $high     = trim( (string) ( $range['high']     ?? '' ) );
        $currency = strtoupper( trim( (string) ( $range['currency'] ?? 'USD' ) ) );
        $post_ids = array_map( 'intval', (array) ( $range['post_ids'] ?? [] ) );

        if ( $label === '' && $low === '' ) continue;

        // Include: global ranges (no post_ids set) OR ranges assigned to this post.
        if ( empty( $post_ids ) || ( $post_id > 0 && in_array( $post_id, $post_ids, true ) ) ) {
            $symbol   = function_exists('myls_service_price_currency_symbol')
                ? myls_service_price_currency_symbol( $currency )
                : '$';
            $low_fmt  = $low  !== '' ? $symbol . number_format( (float) $low,  0 ) : '';
            $high_fmt = $high !== '' ? $symbol . number_format( (float) $high, 0 ) : '';
            $rows[]   = [
                'label'     => $label,
                'low'       => $low_fmt,
                'high'      => $high_fmt,
                'only_low'  => ( $low  !== '' && $high === '' ),
                'only_high' => ( $low  === '' && $high !== '' ),
            ];
        }
    }

    // Nothing to show — return empty string so the section stays blank rather
    // than rendering a confusing empty table.
    if ( empty( $rows ) ) {
        return '';
    }

    // ── Build table HTML ────────────────────────────────────────────────────
    ob_start();
    ?>
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead>
            <tr>
                <th style="text-align:left;padding:10px 12px;background:#ede9fe;border-bottom:2px solid #8b5cf6;color:#4c1d95;">Service</th>
                <th style="text-align:center;padding:10px 12px;background:#ede9fe;border-bottom:2px solid #8b5cf6;color:#4c1d95;">Starting At</th>
                <th style="text-align:center;padding:10px 12px;background:#ede9fe;border-bottom:2px solid #8b5cf6;color:#4c1d95;">Up To</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rows as $i => $row ) :
                $bg = ( $i % 2 === 0 ) ? '#faf5ff' : '#ffffff';
            ?>
            <tr style="background:<?php echo esc_attr( $bg ); ?>;">
                <td style="padding:9px 12px;border-bottom:1px solid #e9d5ff;font-weight:600;color:#1e1b4b;"><?php echo esc_html( $row['label'] ); ?></td>
                <?php if ( $row['only_low'] ) : ?>
                    <td colspan="2" style="padding:9px 12px;border-bottom:1px solid #e9d5ff;text-align:center;color:#6d28d9;font-weight:700;">Starts at <?php echo esc_html( $row['low'] ); ?></td>
                <?php elseif ( $row['only_high'] ) : ?>
                    <td colspan="2" style="padding:9px 12px;border-bottom:1px solid #e9d5ff;text-align:center;color:#6d28d9;font-weight:700;">Up to <?php echo esc_html( $row['high'] ); ?></td>
                <?php else : ?>
                    <td style="padding:9px 12px;border-bottom:1px solid #e9d5ff;text-align:center;color:#6d28d9;font-weight:700;"><?php echo esc_html( $row['low'] ); ?></td>
                    <td style="padding:9px 12px;border-bottom:1px solid #e9d5ff;text-align:center;color:#6d28d9;"><?php echo esc_html( $row['high'] ); ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

add_shortcode( 'myls_pricing_table', 'myls_pricing_table_shortcode' );
