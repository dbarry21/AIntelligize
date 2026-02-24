<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Schema > About Us
 *
 * - Enable/disable
 * - Select the About page (page selector)
 * - Optional overrides (headline, description, primary image URL)
 *
 * Saved options:
 * - myls_about_enabled
 * - myls_about_page_id
 * - myls_about_headline
 * - myls_about_description
 * - myls_about_primary_image
 */

return [
  'id'    => 'about',
  'label' => 'About Us',
  'order' => 35,
  'render'=> function () {

    $enabled = (string) get_option('myls_about_enabled', '0');
    $page_id = (int) get_option('myls_about_page_id', 0);

    $headline = (string) get_option('myls_about_headline', '');
    $desc     = (string) get_option('myls_about_description', '');
    $img      = (string) get_option('myls_about_primary_image', '');

    ?>
    <div class="myls-section">
      <div class="myls-section-title">
        <i class="bi bi-info-circle"></i>
        About Us Schema
        <span class="badge">AboutPage</span>
      </div>

      <div class="row g-4">
        <!-- Left: Form -->
        <div class="col-12 col-lg-6">
          <div class="myls-section" style="border:1px solid #000; border-radius:1em;">
            <div class="myls-section-title">Settings</div>

            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="myls_about_enabled" name="myls_about_enabled" value="1" <?php checked($enabled,'1'); ?> />
              <label class="form-check-label" for="myls_about_enabled"><strong>Enable About Us schema</strong></label>
            </div>

            <label class="form-label" for="myls_about_page_id">About Page</label>
            <?php
              // Pages only, selector for now.
              wp_dropdown_pages([
                'name'              => 'myls_about_page_id',
                'id'                => 'myls_about_page_id',
                'selected'          => $page_id,
                'show_option_none'  => '— Select a page —',
                'option_none_value' => '0',
              ]);
            ?>
            <p class="text-muted mt-2">Schema only outputs on the selected About page.</p>

            <hr class="myls-hr" />

            <div class="myls-section-title" style="margin-top:0;">About Page Overrides (optional)</div>

            <label class="form-label" for="myls_about_headline">Headline / Name Override</label>
            <input type="text" class="form-control" id="myls_about_headline" name="myls_about_headline" value="<?php echo esc_attr($headline); ?>" placeholder="Leave blank to use the page title" />

            <div class="mt-3">
              <label class="form-label" for="myls_about_description">Description Override</label>
              <textarea class="form-control" id="myls_about_description" name="myls_about_description" rows="4" placeholder="Short summary used in schema (not a full page dump)"><?php echo esc_textarea($desc); ?></textarea>
              <p class="text-muted mt-2">Tip: keep this concise (1–2 sentences). If blank, no description is added.</p>
            </div>

            <div class="mt-3">
              <label class="form-label" for="myls_about_primary_image">Primary Image URL (optional)</label>
              <input type="url" class="form-control" id="myls_about_primary_image" name="myls_about_primary_image" value="<?php echo esc_attr($img); ?>" placeholder="https://example.com/wp-content/uploads/about.jpg" />
              <p class="text-muted mt-2">Shown as <code>primaryImageOfPage</code> when provided.</p>
            </div>
          </div>
        </div>

        <!-- Right: Info -->
        <div class="col-12 col-lg-6">
          <div class="myls-section" style="border:1px solid #000; border-radius:1em;">
            <div class="myls-section-title">How this works</div>
            <ul style="margin:0; padding-left:1.1rem;">
              <li><strong>Outputs an <code>AboutPage</code></strong> node on the selected page.</li>
              <li>Includes a minimal <code>WebSite</code> node for clean validation.</li>
              <li>Embeds your primary business entity as <code>Organization</code> or <code>LocalBusiness</code>.</li>
              <li>Business details are pulled from your existing Schema settings (Organization / LocalBusiness).</li>
            </ul>

            <hr class="myls-hr" />

            <div class="myls-section-title">Recommended setup</div>
            <ol style="margin:0; padding-left:1.2rem;">
              <li>Fill out your <strong>Organization</strong> and/or <strong>LocalBusiness</strong> schema settings first.</li>
              <li>Select your About page here and enable this schema.</li>
              <li>Validate with the Schema.org validator (Admin Bar → SEO Stuff → Test Schema.ORG).</li>
            </ol>
          </div>
        </div>
      </div>
    </div>
    <?php
  },

  'on_save' => function () {
    // Enable
    $enabled = ! empty($_POST['myls_about_enabled']) ? '1' : '0';
    update_option('myls_about_enabled', $enabled);

    // Page selector
    $page_id = isset($_POST['myls_about_page_id']) ? absint($_POST['myls_about_page_id']) : 0;
    update_option('myls_about_page_id', $page_id);

    // Overrides
    $headline = isset($_POST['myls_about_headline']) ? sanitize_text_field( wp_unslash($_POST['myls_about_headline']) ) : '';
    update_option('myls_about_headline', $headline);

    $desc = isset($_POST['myls_about_description']) ? sanitize_textarea_field( wp_unslash($_POST['myls_about_description']) ) : '';
    update_option('myls_about_description', $desc);

    $img = isset($_POST['myls_about_primary_image']) ? esc_url_raw( wp_unslash($_POST['myls_about_primary_image']) ) : '';
    update_option('myls_about_primary_image', $img);
  },
];
