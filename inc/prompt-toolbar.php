<?php
/**
 * Prompt History Toolbar — Reusable UI Component
 *
 * Renders a toolbar above any prompt textarea with:
 *   - Saved versions dropdown (load/save/delete)
 *   - Reload Default button (pulls from assets/prompts/*.txt)
 *
 * Usage in any subtab:
 *   myls_prompt_toolbar('meta-title', 'myls_ai_title_prompt');
 *
 * The second arg is the textarea ID the toolbar controls.
 *
 * @since 6.2.0
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! function_exists('myls_prompt_toolbar') ) {

    /**
     * Render the prompt history toolbar HTML.
     *
     * @param string $prompt_key   The prompt identifier (matches filename in assets/prompts/).
     * @param string $textarea_id  The DOM id of the textarea this toolbar controls.
     */
    function myls_prompt_toolbar( string $prompt_key, string $textarea_id ) : void {
        $safe_key = esc_attr( $prompt_key );
        $safe_ta  = esc_attr( $textarea_id );
        ?>
        <div class="myls-prompt-toolbar" data-prompt-key="<?php echo $safe_key; ?>" data-textarea-id="<?php echo $safe_ta; ?>">
            <div class="d-flex gap-1 align-items-center flex-wrap mb-2">
                <select class="form-select form-select-sm myls-pt-history-select" style="max-width:260px;" aria-label="Saved prompt versions">
                    <option value="">— Saved Versions (0) —</option>
                </select>
                <button type="button" class="button button-small myls-pt-btn-load" title="Load selected version">
                    <i class="bi bi-folder2-open"></i>
                </button>
                <button type="button" class="button button-small myls-pt-btn-save" title="Save current prompt as a named version">
                    <i class="bi bi-floppy"></i>
                </button>
                <button type="button" class="button button-small myls-pt-btn-delete" title="Delete selected version" style="color:#dc3545;">
                    <i class="bi bi-trash"></i>
                </button>
                <span style="border-left:1px solid #ccc;height:20px;margin:0 4px;"></span>
                <button type="button" class="button button-small myls-pt-btn-reload-default" title="Reload factory default from file">
                    <i class="bi bi-arrow-counterclockwise"></i> Reload Default
                </button>
            </div>
        </div>
        <?php
    }
}
