<?php
/**
 * Admin Tab: Site Builder (Unified)
 */
if (!defined('ABSPATH')) exit;
if (!function_exists('myls_sb_is_enabled')) { echo '<div class="notice notice-error"><p>Site Builder bootstrap not loaded. Ensure inc/sitebuilder/bootstrap.php is included.</p></div>'; return; }

$settings = get_option('myls_sb_settings', [
  'business_name' => '',
  'city'          => '',
  'phone'         => '',
  'email'         => '',
  'services'      => "Roof Repair\nRoof Replacement\nStorm Tarping",
  'areas'         => "Tampa\nBrandon\nRiverview",
]);

myls_register_admin_tab([
  'id'    => 'site_builder',
  'title' => 'Site Builder',
  'order' => 30,
  'cap'   => 'manage_options',
  'icon'  => 'dashicons-hammer',
  'cb'    => function () use ($settings) {
    $enabled = myls_sb_is_enabled(); ?>
    <div class="container-fluid my-3">
      <div class="row g-3">
        <div class="col-12 col-xxl-8">
          <form method="post" id="myls_sb_settings_form">
            <?php wp_nonce_field('myls_sb_settings'); ?>
            <div class="card border-1">
              <div class="card-header fw-bold">Business Settings</div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-6"><label class="form-label">Business Name</label><input type="text" name="business_name" class="form-control" value="<?php echo esc_attr($settings['business_name'] ?? ''); ?>"/></div>
                  <div class="col-md-6"><label class="form-label">City, State</label><input type="text" name="city" class="form-control" value="<?php echo esc_attr($settings['city'] ?? ''); ?>"/></div>
                  <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?php echo esc_attr($settings['phone'] ?? ''); ?>"/></div>
                  <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?php echo esc_attr($settings['email'] ?? ''); ?>"/></div>
                  <div class="col-md-6"><label class="form-label">Services (one per line)</label><textarea name="services" rows="6" class="form-control"><?php echo esc_textarea($settings['services'] ?? ''); ?></textarea></div>
                  <div class="col-md-6"><label class="form-label">Service Areas / Cities (one per line)</label><textarea name="areas" rows="6" class="form-control"><?php echo esc_textarea($settings['areas'] ?? ''); ?></textarea></div>
                </div>
              </div>
              <div class="card-footer d-flex gap-2"><button type="button" class="btn btn-secondary" id="myls_sb_save_settings">Save Settings</button></div>
            </div>
          </form>

          <div class="card border-1 mt-3">
            <div class="card-header fw-bold">Look & Feel Importer (External URL)</div>
            <div class="card-body">
              <div class="row g-3 align-items-end">
                <div class="col-md-8"><label class="form-label">URL to analyze</label><input type="url" id="myls_sb_skin_url" class="form-control" placeholder="https://example.com/"></div>
                <div class="col-md-4"><button type="button" class="btn btn-outline-dark w-100" id="myls_sb_import_skin">Import Look & Feel</button></div>
              </div>
              <div class="row g-3 mt-3">
                <div class="col-md-6"><div class="p-3 border rounded"><div class="small text-muted mb-2">Detected Palette</div><div id="myls_sb_palette" class="d-flex gap-2 flex-wrap"></div></div></div>
                <div class="col-md-6"><div class="p-3 border rounded"><div class="small text-muted mb-2">Fonts / Radius</div><div id="myls_sb_fonts" class="small"></div></div></div>
              </div>
              <div class="small text-muted mt-2">We derive color variables, fonts and border radius; we do not copy content or proprietary CSS.</div>
            </div>
          </div>

          <div class="card mt-3">
            <div class="card-header fw-bold">Generation Log</div>
            <div class="card-body"><pre id="myls_sb_log" class="bg-light p-3 small mb-0" style="min-height:160px; white-space:pre-wrap;"></pre></div>
          </div>
        </div>

        <div class="col-12 col-xxl-4">
          <form method="post">
            <?php wp_nonce_field('myls_sb_toggle','myls_sb_nonce'); ?>
            <div class="card border-1 mb-3">
              <div class="card-header fw-bold">Builder Switch</div>
              <div class="card-body">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="myls_sb_enabled" id="myls_sb_enabled" <?php checked($enabled); ?>>
                  <label class="form-check-label" for="myls_sb_enabled"><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></label>
                </div>
                <p class="small text-muted mb-0">Turn the AI Site Builder on/off globally.</p>
              </div>
              <div class="card-footer d-flex gap-2"><button class="btn btn-primary" name="myls_sb_save" value="1">Save</button></div>
            </div>
          </form>

          <div class="card border-1 mb-3">
            <div class="card-header fw-bold">Generate Core Draft Site</div>
            <div class="card-body">
              <ul class="mb-3"><li>Home</li><li>About</li><li>Contact</li><li>Services (overview)</li><li>FAQ</li></ul>
              <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="myls_sb_per_service" checked><label for="myls_sb_per_service" class="form-check-label">Per-Service pages</label></div>
              <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="myls_sb_service_areas" checked><label for="myls_sb_service_areas" class="form-check-label">Service Area pages</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="myls_sb_blog_starters"><label for="myls_sb_blog_starters" class="form-check-label">3 Blog draft starters</label></div>
            </div>
            <div class="card-footer"><button type="button" class="btn btn-success w-100" id="myls_sb_generate" <?php disabled( ! $enabled ); ?>>Generate Draft Site</button><div class="small text-muted mt-2">Re-running updates content – no duplicates.</div></div>
          </div>

          <div class="card border-1 mb-3">
            <div class="card-header fw-bold">Create Single Add-On Page</div>
            <div class="card-body">
              <div class="mb-2"><label class="form-label">Type</label><select id="myls_sb_single_type" class="form-select"><option value="service">Service Page</option><option value="area">Service Area Page</option><option value="faq">FAQ Page</option><option value="blog">Blog Starter</option><option value="page">Custom Page</option></select></div>
              <div class="mb-2" id="wrap_service_name"><label class="form-label">Service Name</label><input type="text" id="myls_sb_service_name" class="form-control" placeholder="e.g., Roof Repair"></div>
              <div class="mb-2" id="wrap_area_city" style="display:none;"><label class="form-label">Area / City</label><input type="text" id="myls_sb_area_city" class="form-control" placeholder="e.g., Brandon"></div>
              <div class="mb-2" id="wrap_blog_topic" style="display:none;"><label class="form-label">Blog Topic</label><input type="text" id="myls_sb_blog_topic" class="form-control" placeholder="e.g., Storm roof damage checklist"></div>
              <div id="wrap_custom_page" style="display:none;">
                <div class="mb-2"><label class="form-label">Page Title</label><input type="text" id="myls_sb_page_title" class="form-control" placeholder="e.g., Intelligize Chat - AI-Powered Chat Plugin"></div>
                <div class="mb-2"><label class="form-label">Description / Instructions <span class="text-muted fw-normal">(optional)</span></label><textarea id="myls_sb_page_description" rows="4" class="form-control" placeholder="Describe what this page is about and any specific instructions for AI content generation.&#10;&#10;Example: Intelligize Chat is a new WordPress plugin that adds an AI-powered chat widget. Highlight features like multi-language support, customizable appearance, and easy setup. Include a CTA for downloading."></textarea><div class="form-text">Give AI context about the page purpose, key features, target audience, or tone.</div></div>
                <div class="mb-2"><label class="form-label d-flex justify-content-between align-items-center">AI Prompt Template <span class="text-muted fw-normal">(optional – uses default if blank)</span><button type="button" class="btn btn-outline-secondary btn-sm" id="myls_sb_reset_prompt">Reset to Default</button></label><textarea id="myls_sb_page_prompt" rows="6" class="form-control font-monospace small" placeholder=""></textarea><div class="form-text">Available tokens: <code>{{PAGE_TITLE}}</code>, <code>{{DESCRIPTION}}</code>, <code>{{BUSINESS_NAME}}</code>, <code>{{CITY}}</code>, <code>{{PHONE}}</code>, <code>{{EMAIL}}</code></div></div>
              </div>
            </div>
            <div class="card-footer"><button type="button" class="btn btn-outline-primary w-100" id="myls_sb_generate_single" <?php disabled( ! $enabled ); ?>>Create Single Page</button></div>
          </div>

          <div class="card border-1">
            <div class="card-header fw-bold">Batch Add-Ons</div>
            <div class="card-body">
              <div class="mb-2"><label class="form-label">Services (one per line)</label><textarea id="myls_sb_batch_services" rows="4" class="form-control" placeholder="If blank, uses saved settings"></textarea></div>
              <div class="mb-2"><label class="form-label">Areas / Cities (one per line)</label><textarea id="myls_sb_batch_areas" rows="4" class="form-control" placeholder="If blank, uses saved settings"></textarea></div>
              <div class="row g-2">
                <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="myls_sb_batch_make_services" checked><label class="form-check-label" for="myls_sb_batch_make_services">Make Service Pages</label></div></div>
                <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="myls_sb_batch_make_areas" checked><label class="form-check-label" for="myls_sb_batch_make_areas">Make Area Pages</label></div></div>
              </div>
              <div class="mt-2"><label class="form-label">Blog topics (one per line, optional)</label><textarea id="myls_sb_batch_blog_topics" rows="3" class="form-control" placeholder="Each topic becomes a blog draft"></textarea></div>
            </div>
            <div class="card-footer"><button type="button" class="btn btn-outline-success w-100" id="myls_sb_generate_batch" <?php disabled( ! $enabled ); ?>>Run Batch Add-Ons</button></div>
          </div>
        </div>
      </div>
    </div>

    <script>
      (function(){
        const saveBtn  = document.getElementById('myls_sb_save_settings');
        const form     = document.getElementById('myls_sb_settings_form');
        const genBtn   = document.getElementById('myls_sb_generate');
        const logEl    = document.getElementById('myls_sb_log');

        const singleType = document.getElementById('myls_sb_single_type');
        const wrapService= document.getElementById('wrap_service_name');
        const wrapArea   = document.getElementById('wrap_area_city');
        const wrapBlog   = document.getElementById('wrap_blog_topic');
        const wrapCustom = document.getElementById('wrap_custom_page');
        const singleBtn  = document.getElementById('myls_sb_generate_single');

        const batchBtn   = document.getElementById('myls_sb_generate_batch');

        const defaultPrompt = `Create a professional, SEO-optimized WordPress page for "{{PAGE_TITLE}}".

Business: {{BUSINESS_NAME}} in {{CITY}}
Phone: {{PHONE}} | Email: {{EMAIL}}

Page Description & Instructions:
{{DESCRIPTION}}

Requirements:
- Write clean, semantic HTML using Bootstrap 5 classes
- Include an engaging hero section with a clear headline
- Add 3-5 content sections covering key features/benefits
- Include a strong call-to-action section
- Use <section>, <h2>, <h3>, <p>, <ul> tags — NO markdown
- Make it locally relevant and SEO-friendly
- Output raw HTML only, no code fences or explanation`;

        const promptEl = document.getElementById('myls_sb_page_prompt');
        if (promptEl && !promptEl.value) promptEl.value = defaultPrompt;

        document.getElementById('myls_sb_reset_prompt')?.addEventListener('click', function(){
          promptEl.value = defaultPrompt;
        });

        function toggleSingleUI(){
          const t = singleType.value;
          wrapService.style.display = (t === 'service') ? '' : 'none';
          wrapArea.style.display    = (t === 'area')    ? '' : 'none';
          wrapBlog.style.display    = (t === 'blog')    ? '' : 'none';
          wrapCustom.style.display  = (t === 'page')    ? '' : 'none';
        }
        singleType && singleType.addEventListener('change', toggleSingleUI); toggleSingleUI();

        saveBtn && saveBtn.addEventListener('click', async function(){
          const fd = new FormData(form); fd.append('action','myls_sb_save_settings');
          fd.append('_wpnonce', '<?php echo wp_create_nonce('myls_sb_settings'); ?>');
          const res = await fetch(ajaxurl, { method:'POST', body: fd });
          const data = await res.json();
          alert(data?.success ? 'Settings saved.' : (data?.data?.message || 'Error saving'));
        });

        genBtn && genBtn.addEventListener('click', async function(){
          logEl.textContent = 'Starting core generation…';
          const payload = { action: 'myls_sb_generate',
            per_service: document.getElementById('myls_sb_per_service').checked ? 1 : 0,
            service_areas: document.getElementById('myls_sb_service_areas').checked ? 1 : 0,
            blog_starters: document.getElementById('myls_sb_blog_starters').checked ? 1 : 0,
            _wpnonce: '<?php echo wp_create_nonce('myls_sb_generate'); ?>' };
          try {
            const res = await fetch(ajaxurl, {method:'POST', body: new URLSearchParams(payload)});
            const data = await res.json();
            logEl.textContent = (data?.data?.log || data?.data?.message || data?.message || JSON.stringify(data, null, 2));
          } catch(e){ logEl.textContent = 'Error: ' + e.message; }
        });

        singleBtn && singleBtn.addEventListener('click', async function(){
          const type = singleType.value;
          const payload = { action: 'myls_sb_generate_single', type: type,
            service: document.getElementById('myls_sb_service_name').value || '',
            area:    document.getElementById('myls_sb_area_city').value || '',
            topic:   document.getElementById('myls_sb_blog_topic').value || '',
            page_title:       document.getElementById('myls_sb_page_title').value || '',
            page_description: document.getElementById('myls_sb_page_description').value || '',
            page_prompt:      document.getElementById('myls_sb_page_prompt').value || '',
            _wpnonce: '<?php echo wp_create_nonce('myls_sb_generate_single'); ?>' };

          if (type === 'page' && !payload.page_title.trim()) {
            alert('Please enter a Page Title.'); return;
          }

          logEl.textContent = 'Creating single page…';
          singleBtn.disabled = true; singleBtn.textContent = 'Generating…';
          try {
            const res = await fetch(ajaxurl, {method:'POST', body: new URLSearchParams(payload)});
            const data = await res.json();
            logEl.textContent = (data?.data?.log || data?.data?.message || data?.message || JSON.stringify(data, null, 2));
          } catch(e) { logEl.textContent = 'Error: ' + e.message; }
          finally { singleBtn.disabled = false; singleBtn.textContent = 'Create Single Page'; }
        });

        batchBtn && batchBtn.addEventListener('click', async function(){
          const payload = { action: 'myls_sb_generate_batch',
            make_services: document.getElementById('myls_sb_batch_make_services').checked ? 1 : 0,
            make_areas:    document.getElementById('myls_sb_batch_make_areas').checked ? 1 : 0,
            services:      document.getElementById('myls_sb_batch_services').value || '',
            areas:         document.getElementById('myls_sb_batch_areas').value || '',
            topics:        document.getElementById('myls_sb_batch_blog_topics').value || '',
            _wpnonce:      '<?php echo wp_create_nonce('myls_sb_generate_batch'); ?>' };
          logEl.textContent = 'Running batch add-ons…';
          const res = await fetch(ajaxurl, {method:'POST', body: new URLSearchParams(payload)});
          const data = await res.json();
          logEl.textContent = (data?.data?.log || data?.data?.message || data?.message || JSON.stringify(data, null, 2));
        });

        const btn  = document.getElementById('myls_sb_import_skin');
        const urlI = document.getElementById('myls_sb_skin_url');
        const pal  = document.getElementById('myls_sb_palette');
        const fnt  = document.getElementById('myls_sb_fonts');
        btn && btn.addEventListener('click', async function(){
          const url = urlI.value.trim(); if (!url) { alert('Enter a URL'); return; }
          const payload = { action: 'myls_sb_import_skin', url: url, _wpnonce: '<?php echo wp_create_nonce('myls_sb_import_skin'); ?>' };
          btn.disabled = true; btn.textContent = 'Analyzing…';
          try {
            const res = await fetch(ajaxurl, {method:'POST', body:new URLSearchParams(payload)});
            const data = await res.json();
            if (!data?.success) throw new Error(data?.data?.message || 'Import failed');
            const skin = data.data.skin || {}; pal.innerHTML = '';
            ['primary','secondary','accent'].forEach(k => { const c = skin[k] || '#ccc';
              const chip = document.createElement('div'); chip.style.cssText = 'width:64px;height:32px;border-radius:6px;border:1px solid #ddd'; chip.style.background = c; chip.title = k + ': ' + c; pal.appendChild(chip); });
            fnt.innerHTML = `<div><strong>Headings:</strong> ${skin.font_head || ''}</div><div><strong>Body:</strong> ${skin.font_body || ''}</div><div><strong>Radius:</strong> ${skin.radius || ''}</div>`;
            alert(data.data.message || 'Imported look & feel.');
          } catch(e) { alert(e.message); } finally { btn.disabled = false; btn.textContent = 'Import Look & Feel'; }
        });
      })();
    </script>
    <?php if ( isset($_POST['myls_sb_save']) && check_admin_referer('myls_sb_toggle','myls_sb_nonce') ) { myls_sb_set_enabled( ! empty($_POST['myls_sb_enabled']) ); echo '<div class="alert alert-info mt-3">Builder '.(myls_sb_is_enabled()?'Enabled':'Disabled').'.</div>'; } ?>
  <?php }
]);
