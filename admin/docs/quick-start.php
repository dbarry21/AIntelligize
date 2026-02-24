<?php
/**
 * AIntelligize – Quick Start Guide
 * File: admin/docs/quick-start.php
 * 
 * Interactive checklist with persistent state
 */

if (!defined('ABSPATH')) exit;

// Get user's progress
$user_id = get_current_user_id();
$progress_key = 'mlseo_quick_start_progress';
$completed_steps = get_user_meta($user_id, $progress_key, true);
if (!is_array($completed_steps)) {
    $completed_steps = [];
}

$total_steps = 15;
$completed_count = count($completed_steps);
$progress_percent = $total_steps > 0 ? round(($completed_count / $total_steps) * 100) : 0;
?>

<style>
.quick-start-wrapper {
    max-width: 1200px;
    margin: 20px auto;
}

.progress-header {
    background: linear-gradient(135deg, #2271b1 0%, #135e96 100%);
    color: white;
    padding: 30px;
    border-radius: 8px;
    margin-bottom: 30px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.progress-bar-container {
    background: rgba(255,255,255,0.2);
    height: 24px;
    border-radius: 12px;
    overflow: hidden;
    margin: 15px 0;
}

.progress-bar-fill {
    height: 100%;
    background: #4CAF50;
    transition: width 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 12px;
}

.step-section {
    background: white;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.step-section h3 {
    color: #2271b1;
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f1;
}

.checklist-item {
    display: flex;
    align-items: flex-start;
    padding: 15px;
    margin: 10px 0;
    background: #f9f9f9;
    border-radius: 6px;
    border-left: 4px solid transparent;
    transition: all 0.2s ease;
}

.checklist-item:hover {
    background: #f0f6fc;
}

.checklist-item.completed {
    background: #e8f5e9;
    border-left-color: #4CAF50;
}

.checkbox-wrapper {
    margin-right: 15px;
    margin-top: 2px;
}

.checkbox-wrapper input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}

.step-content {
    flex: 1;
}

.step-title {
    font-weight: 600;
    font-size: 15px;
    margin-bottom: 5px;
    color: #1d2327;
}

.step-description {
    color: #646970;
    font-size: 14px;
    margin-bottom: 8px;
}

.step-action {
    margin-top: 8px;
}

.step-action a {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #2271b1;
    text-decoration: none;
    font-size: 13px;
}

.step-action a:hover {
    text-decoration: underline;
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
}

.badge-required {
    background: #dc3545;
    color: white;
}

.badge-optional {
    background: #6c757d;
    color: white;
}

.badge-recommended {
    background: #ff9800;
    color: white;
}

.reset-progress {
    margin-top: 20px;
    text-align: center;
}

.completion-message {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
    padding: 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    text-align: center;
}
</style>

<div class="quick-start-wrapper">
    <!-- Progress Header -->
    <div class="progress-header">
        <h2 style="margin:0 0 10px 0;">Quick Start Guide</h2>
        <p style="margin:0 0 15px 0;">Complete these steps to get your AIntelligize plugin fully configured and ready to use.</p>
        
        <div class="progress-bar-container">
            <div class="progress-bar-fill" style="width: <?php echo $progress_percent; ?>%;">
                <?php echo $completed_count; ?> / <?php echo $total_steps; ?> Complete (<?php echo $progress_percent; ?>%)
            </div>
        </div>
        
        <?php if ($completed_count === $total_steps): ?>
            <div style="text-align:center; margin-top:15px;">
                <span style="font-size:32px;">🎉</span>
                <strong style="display:block; margin-top:10px;">Congratulations! You've completed the setup!</strong>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($completed_count === $total_steps): ?>
        <div class="completion-message">
            <h3>🎊 Setup Complete!</h3>
            <p>Your AIntelligize plugin is now fully configured and ready to power your local business website.</p>
            <p><strong>What's Next?</strong> Explore advanced features, generate more content, and optimize your schema markup.</p>
        </div>
    <?php endif; ?>

    <!-- Step 1: Essential Configuration -->
    <div class="step-section">
        <h3>1. Essential Configuration</h3>
        
        <div class="checklist-item <?php echo in_array('org-info', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="org-info" <?php checked(in_array('org-info', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">
                    Configure Organization Information
                    <span class="badge badge-required">REQUIRED</span>
                </div>
                <div class="step-description">
                    Add your business name, address, phone number, and logo. This is the foundation for all schema markup.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=schema&subtab=organization'); ?>">
                        <span class="dashicons dashicons-admin-generic"></span>
                        Go to Schema → Organization
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('api-key', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="api-key" <?php checked(in_array('api-key', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">
                    Add OpenAI API Key
                    <span class="badge badge-required">REQUIRED</span>
                </div>
                <div class="step-description">
                    Enter your OpenAI API key to enable AI-powered content generation features.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=api'); ?>">
                        <span class="dashicons dashicons-admin-network"></span>
                        Go to API Integration
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('google-maps', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="google-maps" <?php checked(in_array('google-maps', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">
                    Add Google Maps API Key
                    <span class="badge badge-recommended">RECOMMENDED</span>
                </div>
                <div class="step-description">
                    Enable Google Maps integration for static map generation on service area pages.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=api'); ?>">
                        <span class="dashicons dashicons-location"></span>
                        Go to API Integration
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 2: Schema Setup -->
    <div class="step-section">
        <h3>2. Schema Markup Setup</h3>

        <div class="checklist-item <?php echo in_array('enable-org-schema', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="enable-org-schema" <?php checked(in_array('enable-org-schema', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Enable Organization Schema</div>
                <div class="step-description">
                    Activate Organization schema to tell Google about your business.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=schema&subtab=organization'); ?>">
                        <span class="dashicons dashicons-building"></span>
                        Go to Schema → Organization
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('enable-service-schema', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="enable-service-schema" <?php checked(in_array('enable-service-schema', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Enable Service Schema</div>
                <div class="step-description">
                    Turn on Service schema for your service pages to appear in Google's service results.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=schema&subtab=serviceschema'); ?>">
                        <span class="dashicons dashicons-hammer"></span>
                        Go to Schema → Service
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('configure-localbusiness', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="configure-localbusiness" <?php checked(in_array('configure-localbusiness', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">
                    Configure Local Business Schema
                    <span class="badge badge-optional">OPTIONAL</span>
                </div>
                <div class="step-description">
                    If you have physical locations, add them here for location-specific schema.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=schema&subtab=localbusiness'); ?>">
                        <span class="dashicons dashicons-store"></span>
                        Go to Schema → Local Business
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 3: Create Content -->
    <div class="step-section">
        <h3>3. Create Your First Content</h3>

        <div class="checklist-item <?php echo in_array('create-service', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="create-service" <?php checked(in_array('create-service', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Create Your First Service</div>
                <div class="step-description">
                    Add a service post with title, description, and featured image.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('post-new.php?post_type=service'); ?>">
                        <span class="dashicons dashicons-plus-alt"></span>
                        Add New Service
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('generate-tagline', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="generate-tagline" <?php checked(in_array('generate-tagline', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Generate AI Tagline</div>
                <div class="step-description">
                    Use the AI tagline generator to create benefit-focused taglines for your service.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=ai&subtab=taglines'); ?>">
                        <span class="dashicons dashicons-megaphone"></span>
                        Go to AI → Taglines
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('create-service-area', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="create-service-area" <?php checked(in_array('create-service-area', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">
                    Create Service Area Page
                    <span class="badge badge-optional">OPTIONAL</span>
                </div>
                <div class="step-description">
                    Create location-specific pages for each area you serve.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('post-new.php?post_type=service_area'); ?>">
                        <span class="dashicons dashicons-location-alt"></span>
                        Add New Service Area
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 4: Add Shortcodes -->
    <div class="step-section">
        <h3>4. Add Shortcodes to Pages</h3>

        <div class="checklist-item <?php echo in_array('add-service-grid', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="add-service-grid" <?php checked(in_array('add-service-grid', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Add Service Grid</div>
                <div class="step-description">
                    Display your services in a professional grid layout using [service_grid] shortcode.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=mlseo-docs&tab=sc_interactive'); ?>">
                        <span class="dashicons dashicons-info"></span>
                        View Shortcode Documentation
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('add-location-shortcodes', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="add-location-shortcodes" <?php checked(in_array('add-location-shortcodes', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Use Location Shortcodes</div>
                <div class="step-description">
                    Make your content dynamic with [city_state], [city_only], and [county] shortcodes.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=mlseo-docs&tab=sc_interactive'); ?>">
                        <span class="dashicons dashicons-info"></span>
                        View Location Shortcodes
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('add-faq-schema', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="add-faq-schema" <?php checked(in_array('add-faq-schema', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">
                    Add FAQ Schema
                    <span class="badge badge-optional">OPTIONAL</span>
                </div>
                <div class="step-description">
                    Use [faq_schema_accordion] to add FAQs with automatic schema markup.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=ai&subtab=faqs'); ?>">
                        <span class="dashicons dashicons-editor-help"></span>
                        Generate FAQs with AI
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 5: Optimization & Testing -->
    <div class="step-section">
        <h3>5. Optimization & Testing</h3>

        <div class="checklist-item <?php echo in_array('test-schema', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="test-schema" <?php checked(in_array('test-schema', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Test Schema Markup</div>
                <div class="step-description">
                    Use Google's Rich Results Test to verify your schema is working correctly.
                </div>
                <div class="step-action">
                    <a href="https://search.google.com/test/rich-results" target="_blank">
                        <span class="dashicons dashicons-external"></span>
                        Open Rich Results Test
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('mobile-test', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="mobile-test" <?php checked(in_array('mobile-test', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Test on Mobile Devices</div>
                <div class="step-description">
                    Preview your service grid and pages on mobile to ensure responsiveness.
                </div>
                <div class="step-action">
                    <a href="<?php echo home_url(); ?>" target="_blank">
                        <span class="dashicons dashicons-smartphone"></span>
                        View Your Site
                    </a>
                </div>
            </div>
        </div>

        <div class="checklist-item <?php echo in_array('backup-settings', $completed_steps) ? 'completed' : ''; ?>">
            <div class="checkbox-wrapper">
                <input type="checkbox" class="progress-checkbox" data-step="backup-settings" <?php checked(in_array('backup-settings', $completed_steps)); ?>>
            </div>
            <div class="step-content">
                <div class="step-title">Backup Your Configuration</div>
                <div class="step-description">
                    Export your plugin settings for safekeeping and easy migration.
                </div>
                <div class="step-action">
                    <a href="<?php echo admin_url('admin.php?page=aintelligize&tab=utilities'); ?>">
                        <span class="dashicons dashicons-download"></span>
                        Go to Utilities
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Reset Progress -->
    <div class="reset-progress">
        <button type="button" class="button" id="reset-progress">
            <span class="dashicons dashicons-update"></span> Reset Progress
        </button>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Handle checkbox changes
    $('.progress-checkbox').on('change', function() {
        const step = $(this).data('step');
        const checked = $(this).is(':checked');
        const $item = $(this).closest('.checklist-item');
        
        // Update UI immediately
        if (checked) {
            $item.addClass('completed');
        } else {
            $item.removeClass('completed');
        }
        
        // Save to database
        $.post(ajaxurl, {
            action: 'mlseo_update_quick_start_progress',
            step: step,
            completed: checked ? 1 : 0,
            _wpnonce: '<?php echo wp_create_nonce('mlseo_quick_start_progress'); ?>'
        }, function(response) {
            if (response.success) {
                // Update progress bar
                const total = <?php echo $total_steps; ?>;
                const completed = response.data.completed_count;
                const percent = Math.round((completed / total) * 100);
                
                $('.progress-bar-fill')
                    .css('width', percent + '%')
                    .text(completed + ' / ' + total + ' Complete (' + percent + '%)');
                
                // Show completion message if all done
                if (completed === total) {
                    location.reload();
                }
            }
        });
    });
    
    // Reset progress
    $('#reset-progress').on('click', function() {
        if (confirm('Are you sure you want to reset your progress? This cannot be undone.')) {
            $.post(ajaxurl, {
                action: 'mlseo_reset_quick_start_progress',
                _wpnonce: '<?php echo wp_create_nonce('mlseo_quick_start_progress'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        }
    });
});
</script>
