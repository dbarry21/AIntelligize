<?php
if ( ! defined('ABSPATH') ) exit;

return [
    'id'    => 'service-output',
    'label' => 'Service Output',
    'icon'  => 'bi-award',
    'order' => 45,
    'render'=> function () {

        // ── Default prompt ───────────────────────────────────────────────
        $default_prompt = "You are writing a Schema.org serviceOutput noun-phrase for a local service business page.\n\nService: {{TITLE}}\nLocation: {{CITY_STATE}}\nPage content summary: {{PAGE_TEXT}}\n\nWrite ONE short noun-phrase (8-15 words) describing the specific tangible deliverable the customer receives after this service is complete.\n\nRules:\n- Must be a noun phrase, not a sentence or marketing slogan\n- Describe the physical result, not the process\n- Be specific to this service type\n- Do not start with verbs like \"Get\" or \"Enjoy\"\n- Do not include the business name\n\nGood examples:\n- Clean, sealed paver surface with restored polymeric sand joints\n- Mold-free, pressure-washed concrete driveway surface\n- Streak-free, cleaned pool screen enclosure panels\n\nOutput ONLY the noun-phrase. No quotes, no preamble, no punctuation at end.";

        // ── Save prompt ──────────────────────────────────────────────────
        if (
            isset( $_POST['myls_svcout_prompt_save'] ) &&
            check_admin_referer( 'myls_svcout_prompt_nonce', 'myls_svcout_prompt_nonce' )
        ) {
            update_option( 'myls_service_output_prompt',
                sanitize_textarea_field( wp_unslash( $_POST['myls_service_output_prompt'] ?? '' ) )
            );
            echo '<div class="updated notice"><p>Prompt template saved.</p></div>';
        }

        $prompt = (string) get_option( 'myls_service_output_prompt', '' );
        if ( $prompt === '' ) $prompt = $default_prompt;

        // ── Load service posts ───────────────────────────────────────────
        $service_posts = [];
        if ( post_type_exists( 'service' ) ) {
            $service_posts = get_posts( [
                'post_type'        => 'service',
                'post_status'      => ['publish', 'draft'],
                'posts_per_page'   => 200,
                'orderby'          => 'title',
                'order'            => 'ASC',
                'suppress_filters' => true,
            ] );
        }

        $nonce = wp_create_nonce( 'myls_svcout_ops' );
        ?>

        <div class="myls-two-col">

            <!-- LEFT: Prompt + instructions -->
            <div class="myls-card">
                <div class="myls-card-header">
                    <h2 class="myls-card-title">
                        <i class="bi bi-chat-left-text"></i>
                        AI Prompt Template
                    </h2>
                </div>

                <div style="margin-bottom:14px;padding:12px;background:#f0f6fc;
                            border-left:3px solid #2271b1;font-size:13px;line-height:1.6;">
                    <strong>serviceOutput</strong> is a Schema.org property that describes
                    the tangible result a customer receives — not the process.
                    AI-generated values are saved as <code>_myls_service_output</code>
                    post meta and used as the highest priority source in schema output.<br><br>
                    <strong>Variables:</strong>
                    <code>{{TITLE}}</code> &nbsp;
                    <code>{{PAGE_TEXT}}</code> &nbsp;
                    <code>{{CITY_STATE}}</code>
                </div>

                <form method="post">
                    <?php wp_nonce_field( 'myls_svcout_prompt_nonce', 'myls_svcout_prompt_nonce' ); ?>
                    <div class="mb-3">
                        <label class="form-label"><strong>Prompt Template</strong></label>
                        <textarea name="myls_service_output_prompt"
                                  class="widefat" rows="18"
                                  style="font-family:monospace;font-size:12px;"
                        ><?php echo esc_textarea( $prompt ); ?></textarea>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="submit" name="myls_svcout_prompt_save"
                                class="button button-primary">
                            Save Prompt
                        </button>
                        <button type="button" id="myls_svcout_reset_prompt"
                                class="button">
                            Reset to Default
                        </button>
                    </div>
                </form>

                <hr class="myls-divider"/>

                <div>
                    <h3 class="h6 mb-2">Bulk Actions</h3>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="button button-primary"
                                id="myls_svcout_generate_all">
                            <i class="bi bi-stars"></i>
                            Generate All (AI)
                        </button>
                        <button type="button" class="button"
                                id="myls_svcout_stop" disabled>
                            <i class="bi bi-stop-circle"></i> Stop
                        </button>
                    </div>
                    <div class="mt-2">
                        <label style="display:flex;align-items:center;gap:6px;">
                            <input type="checkbox" id="myls_svcout_skip_existing" checked>
                            <span>Skip posts that already have a value</span>
                        </label>
                    </div>
                    <div class="mt-2">
                        <span class="myls-spinner" id="myls_svcout_spinner" style="display:none;">
                            <span class="dashicons dashicons-update"></span>
                            Processing…
                        </span>
                        <span id="myls_svcout_bulk_status" class="myls-text-muted"></span>
                    </div>
                </div>

                <hr class="myls-divider"/>

                <div>
                    <h3 class="h6 mb-2"><i class="bi bi-terminal"></i> Results Log</h3>
                    <pre id="myls_svcout_log"
                         class="myls-results-terminal"
                         style="min-height:120px;">Ready.</pre>
                </div>
            </div>

            <!-- RIGHT: Per-service rows -->
            <div class="myls-card">
                <div class="myls-card-header">
                    <h2 class="myls-card-title">
                        <i class="bi bi-list-check"></i>
                        Service Pages
                        <span class="myls-badge myls-badge-primary" style="margin-left:8px;">
                            <?php echo count( $service_posts ); ?>
                        </span>
                    </h2>
                </div>

                <?php if ( empty( $service_posts ) ) : ?>
                    <div class="notice notice-warning inline">
                        <p>No published or draft service posts found.
                        Make sure the <strong>service</strong> CPT has posts.</p>
                    </div>
                <?php else : ?>

                <div style="margin-bottom:12px;font-size:12px;color:#666;">
                    Edit or generate <code>serviceOutput</code> per service.
                    Changes save immediately when you click Save or Generate.
                </div>

                <table class="wp-list-table widefat fixed striped"
                       id="myls_svcout_table">
                    <thead>
                        <tr>
                            <th style="width:30%;">Service</th>
                            <th style="width:45%;">serviceOutput</th>
                            <th style="width:25%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $service_posts as $svc ) :
                        $current = (string) get_post_meta( $svc->ID, '_myls_service_output', true );
                        $status  = $current !== '' ? 'set' : 'empty';
                    ?>
                        <tr data-post-id="<?php echo (int) $svc->ID; ?>"
                            data-status="<?php echo esc_attr( $status ); ?>">
                            <td>
                                <strong><?php echo esc_html( get_the_title( $svc->ID ) ); ?></strong>
                                <br>
                                <a href="<?php echo esc_url( get_edit_post_link( $svc->ID ) ); ?>"
                                   target="_blank" style="font-size:11px;">Edit post ↗</a>
                            </td>
                            <td>
                                <textarea
                                    class="widefat myls-svcout-input"
                                    rows="2"
                                    style="font-size:12px;resize:vertical;"
                                    placeholder="Leave blank to use smart default"
                                ><?php echo esc_textarea( $current ); ?></textarea>
                                <span class="myls-svcout-row-status"
                                      style="font-size:11px;color:#666;display:block;margin-top:3px;">
                                    <?php echo $status === 'set'
                                        ? '<span style="color:#00a32a;">✓ Saved</span>'
                                        : '<span style="color:#999;">— not set</span>'; ?>
                                </span>
                            </td>
                            <td style="vertical-align:middle;">
                                <div class="d-flex flex-column gap-1">
                                    <button type="button"
                                            class="button myls-svcout-save-btn"
                                            data-post-id="<?php echo (int) $svc->ID; ?>">
                                        <i class="bi bi-floppy"></i> Save
                                    </button>
                                    <button type="button"
                                            class="button myls-svcout-gen-btn"
                                            data-post-id="<?php echo (int) $svc->ID; ?>">
                                        <i class="bi bi-stars"></i> Generate
                                    </button>
                                    <button type="button"
                                            class="button myls-svcout-clear-btn"
                                            data-post-id="<?php echo (int) $svc->ID; ?>">
                                        <i class="bi bi-x-circle"></i> Clear
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php endif; ?>
            </div>

        </div>

        <script>
        (function($){
            const NONCE  = <?php echo json_encode( $nonce ); ?>;
            const AJAX   = <?php echo json_encode( admin_url('admin-ajax.php') ); ?>;
            const DEFAULT_PROMPT = <?php echo json_encode( $default_prompt ); ?>;

            let stopBulk = false;

            // ── Reset prompt to default ───────────────────────────────────
            $('#myls_svcout_reset_prompt').on('click', function() {
                if ( confirm('Reset prompt to plugin default?') ) {
                    $('textarea[name="myls_service_output_prompt"]').val( DEFAULT_PROMPT );
                }
            });

            // ── Log helper ───────────────────────────────────────────────
            function log( msg ) {
                const $log = $('#myls_svcout_log');
                $log.text( $log.text() + '\n' + msg );
                $log.scrollTop( $log[0].scrollHeight );
            }

            // ── Per-row: Save manual value ───────────────────────────────
            $(document).on('click', '.myls-svcout-save-btn', function() {
                const $btn  = $(this);
                const $row  = $btn.closest('tr');
                const pid   = $row.data('post-id');
                const value = $row.find('.myls-svcout-input').val().trim();
                const $stat = $row.find('.myls-svcout-row-status');

                $btn.prop('disabled', true).text('Saving…');

                $.post( AJAX, {
                    action: 'myls_service_output_save_single',
                    nonce:   NONCE,
                    post_id: pid,
                    value:   value
                }, function( res ) {
                    if ( res.success ) {
                        $stat.html('<span style="color:#00a32a;">✓ Saved</span>');
                        $row.data('status', value !== '' ? 'set' : 'empty');
                        log( '✓ Saved: ' + $row.find('strong').first().text() );
                    } else {
                        $stat.html('<span style="color:#d63638;">✗ Error</span>');
                        log( '✗ Error saving: ' + pid );
                    }
                } ).always( function() {
                    $btn.prop('disabled', false).html('<i class="bi bi-floppy"></i> Save');
                });
            });

            // ── Per-row: AI Generate ─────────────────────────────────────
            $(document).on('click', '.myls-svcout-gen-btn', function() {
                const $btn  = $(this);
                const $row  = $btn.closest('tr');
                const pid   = $row.data('post-id');
                const $ta   = $row.find('.myls-svcout-input');
                const $stat = $row.find('.myls-svcout-row-status');

                $btn.prop('disabled', true).html(
                    '<span class="dashicons dashicons-update spin"></span> Generating…'
                );
                $stat.html('<span style="color:#2271b1;">Generating…</span>');

                $.post( AJAX, {
                    action: 'myls_service_output_generate_single',
                    nonce:   NONCE,
                    post_id: pid
                }, function( res ) {
                    if ( res.success && res.data.value ) {
                        $ta.val( res.data.value );
                        $stat.html('<span style="color:#00a32a;">✓ Generated & saved</span>');
                        log( '✓ Generated: ' + $row.find('strong').first().text()
                             + ' → ' + res.data.value );
                    } else {
                        $stat.html('<span style="color:#d63638;">✗ Failed</span>');
                        log( '✗ Generation failed: ' + pid
                             + ( res.data?.message ? ' — ' + res.data.message : '' ) );
                    }
                } ).always( function() {
                    $btn.prop('disabled', false)
                        .html('<i class="bi bi-stars"></i> Generate');
                });
            });

            // ── Per-row: Clear ───────────────────────────────────────────
            $(document).on('click', '.myls-svcout-clear-btn', function() {
                const $btn  = $(this);
                const $row  = $btn.closest('tr');
                const pid   = $row.data('post-id');
                const $ta   = $row.find('.myls-svcout-input');
                const $stat = $row.find('.myls-svcout-row-status');

                $ta.val('');
                $btn.prop('disabled', true);

                $.post( AJAX, {
                    action: 'myls_service_output_save_single',
                    nonce:   NONCE,
                    post_id: pid,
                    value:   ''
                }, function( res ) {
                    if ( res.success ) {
                        $stat.html('<span style="color:#999;">— cleared</span>');
                        $row.data('status', 'empty');
                        log( '✓ Cleared: ' + $row.find('strong').first().text() );
                    }
                } ).always( function() {
                    $btn.prop('disabled', false)
                        .html('<i class="bi bi-x-circle"></i> Clear');
                });
            });

            // ── Bulk: Generate All ───────────────────────────────────────
            $('#myls_svcout_generate_all').on('click', function() {
                stopBulk = false;
                const skipExisting = $('#myls_svcout_skip_existing').is(':checked');
                const $rows = $('#myls_svcout_table tbody tr');
                const toProcess = [];

                $rows.each(function() {
                    const $row = $(this);
                    if ( skipExisting && $row.data('status') === 'set' ) return;
                    toProcess.push( $row );
                });

                if ( toProcess.length === 0 ) {
                    alert('No posts to process. Uncheck "Skip existing" to regenerate all.');
                    return;
                }

                $('#myls_svcout_generate_all').prop('disabled', true);
                $('#myls_svcout_stop').prop('disabled', false);
                $('#myls_svcout_spinner').show();
                $('#myls_svcout_log').text('Starting bulk generation…\n');

                let idx = 0;

                function processNext() {
                    if ( stopBulk || idx >= toProcess.length ) {
                        $('#myls_svcout_generate_all').prop('disabled', false);
                        $('#myls_svcout_stop').prop('disabled', true);
                        $('#myls_svcout_spinner').hide();
                        const msg = stopBulk ? 'Stopped.' : 'Done. ' + idx + ' processed.';
                        $('#myls_svcout_bulk_status').text( msg );
                        log( '\n' + msg );
                        return;
                    }

                    const $row = toProcess[idx];
                    const pid  = $row.data('post-id');
                    const name = $row.find('strong').first().text();
                    const $ta  = $row.find('.myls-svcout-input');
                    const $stat = $row.find('.myls-svcout-row-status');

                    $('#myls_svcout_bulk_status').text(
                        ( idx + 1 ) + '/' + toProcess.length + ': ' + name
                    );
                    $stat.html('<span style="color:#2271b1;">Generating…</span>');

                    $.post( AJAX, {
                        action: 'myls_service_output_generate_single',
                        nonce:   NONCE,
                        post_id: pid
                    }, function( res ) {
                        if ( res.success && res.data.value ) {
                            $ta.val( res.data.value );
                            $stat.html('<span style="color:#00a32a;">✓ Generated</span>');
                            $row.data('status', 'set');
                            log( '✓ [' + (idx+1) + '/' + toProcess.length + '] '
                                 + name + ' → ' + res.data.value );
                        } else {
                            $stat.html('<span style="color:#d63638;">✗ Failed</span>');
                            log( '✗ [' + (idx+1) + '/' + toProcess.length + '] '
                                 + name + ' — failed' );
                        }
                        idx++;
                        setTimeout( processNext, 800 );
                    } ).fail( function() {
                        log( '✗ [' + (idx+1) + '/' + toProcess.length + '] '
                             + name + ' — request failed' );
                        idx++;
                        setTimeout( processNext, 800 );
                    });
                }

                processNext();
            });

            // ── Stop bulk ────────────────────────────────────────────────
            $('#myls_svcout_stop').on('click', function() {
                stopBulk = true;
                $(this).prop('disabled', true);
            });

            // ── Spin animation ───────────────────────────────────────────
            $('<style>.spin{animation:myls-spin 1s linear infinite;}'
              + '@keyframes myls-spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}'
              + '</style>').appendTo('head');

        })(jQuery);
        </script>

        <?php
    }
];
