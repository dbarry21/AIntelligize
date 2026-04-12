<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Service CPT — serviceOutput metabox
 * Adds a per-page serviceOutput field to the service CPT edit screen.
 * Meta key: _myls_service_output
 * Used by build-service-schema.php as highest-priority source for
 * Service.serviceOutput.name in JSON-LD schema output.
 */

add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'myls_service_output_box',
        '<span class="dashicons dashicons-awards" style="margin-right:5px;"></span> Service Output (Schema)',
        'myls_service_output_render',
        'service',
        'side',
        'high'
    );
} );

function myls_service_output_render( WP_Post $post ) : void {
    wp_nonce_field( 'myls_service_output_save', 'myls_service_output_nonce' );
    $value = (string) get_post_meta( $post->ID, '_myls_service_output', true );
    ?>
    <div style="padding:4px 0;">
        <div style="margin-bottom:10px;padding:9px 10px;background:#f0f6fc;
                    border-left:3px solid #2271b1;font-size:12px;line-height:1.5;">
            <strong>What does the customer receive?</strong><br>
            Short noun-phrase — the tangible deliverable, not a process.
            Used as <code>serviceOutput.name</code> in schema.
        </div>
        <label for="myls_service_output"
               style="display:block;margin-bottom:5px;font-weight:600;font-size:12px;">
            Deliverable noun-phrase:
        </label>
        <textarea id="myls_service_output" name="myls_service_output"
                  rows="3" class="widefat" style="font-size:12px;"
                  placeholder="e.g. Clean, sealed paver surface with restored joint sand"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p style="margin:6px 0 0;font-size:11px;color:#666;">
            Leave blank to use the site-wide default or smart keyword match.
        </p>
        <?php if ( $value !== '' ) : ?>
        <div style="margin-top:10px;padding:8px 10px;background:#fff;
                    border:1px solid #ddd;border-radius:3px;font-size:12px;">
            <span style="color:#666;display:block;margin-bottom:3px;">Preview:</span>
            <strong><?php echo esc_html( $value ); ?></strong>
        </div>
        <?php endif; ?>
        <div style="margin-top:12px;padding:9px 10px;background:#f9f9f9;
                    border-radius:3px;font-size:11px;color:#666;">
            <strong>Examples:</strong>
            <ul style="margin:5px 0 0;padding-left:16px;">
                <li>Clean, sealed paver surface with restored polymeric sand joints</li>
                <li>Mold-free, pressure-washed concrete driveway surface</li>
                <li>Streak-free, cleaned pool screen enclosure panels</li>
                <li>Rust-free exterior surface with stain-preventive treatment</li>
            </ul>
        </div>
    </div>
    <?php
}

add_action( 'save_post_service', function( int $post_id ) : void {
    if ( ! isset( $_POST['myls_service_output_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['myls_service_output_nonce'], 'myls_service_output_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    $value = isset( $_POST['myls_service_output'] )
        ? sanitize_textarea_field( wp_unslash( $_POST['myls_service_output'] ) )
        : '';
    update_post_meta( $post_id, '_myls_service_output', $value );
}, 10 );
