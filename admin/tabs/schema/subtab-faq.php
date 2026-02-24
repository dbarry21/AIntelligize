<?php if (!defined('ABSPATH')) exit;

$spec = [
  'id'    => 'faq',
  'label' => 'FAQ',
  'order' => 50,

  'render'=> function () {
    $enabled         = get_option('myls_faq_enabled', '0');
    $existing_page   = (int) get_option('myls_service_faq_page_id', 0);
    $page_exists     = ( $existing_page && get_post_status( $existing_page ) !== false );
    $page_title_val  = $page_exists ? get_the_title( $existing_page ) : 'Service FAQs';
    $page_slug_val   = $page_exists ? get_post_field( 'post_name', $existing_page ) : 'service-faqs';
    $page_status_val = $page_exists ? get_post_status( $existing_page ) : 'publish';

    // Count services + FAQ stats (with dedup).
    $service_count     = 0;
    $faq_service_count = 0;
    $total_faq_raw     = 0;
    $total_faq_deduped = 0;
    $seen_questions    = [];

    if ( post_type_exists('service') ) {
      $svc_ids = get_posts([
        'post_type'      => 'service',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
      ]);
      $service_count = count( $svc_ids );
      foreach ( $svc_ids as $sid ) {
        $items = function_exists('myls_get_faq_items_meta') ? myls_get_faq_items_meta( (int) $sid ) : [];
        if ( ! empty($items) ) {
          $faq_service_count++;
          $total_faq_raw += count($items);
          foreach ( $items as $row ) {
            $key = mb_strtolower( trim( $row['q'] ?? '' ) );
            if ( $key !== '' && ! isset( $seen_questions[ $key ] ) ) {
              $seen_questions[ $key ] = true;
              $total_faq_deduped++;
            }
          }
        }
      }
    }
    $dupes = $total_faq_raw - $total_faq_deduped;
    ?>
    <!-- IMPORTANT: no <form>; this lives inside the main tab's form -->
    <div class="container-fluid px-0 myls-rounded">
      <div class="row g-3">

        <!-- LEFT: Form card -->
        <div class="col-12 col-lg-8">
          <div class="card mb-0 shadow-sm myls-card h-100">
            <div class="card-header bg-primary text-white">
              <strong>FAQ Schema</strong>
            </div>
            <div class="card-body">

              <!-- Enable toggle (switch) -->
              <div class="form-check form-switch mb-3">
                <input type="hidden" name="myls_faq_enabled" value="0">
                <input
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  id="myls_faq_enabled"
                  name="myls_faq_enabled"
                  value="1"
                  <?php checked('1', $enabled); ?>
                >
                <label class="form-check-label" for="myls_faq_enabled">
                  Enable FAQPage / FAQ markup
                </label>
              </div>

              <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">Save</button>
              </div>

            </div>
          </div>
        </div>

        <!-- RIGHT: Info card -->
        <div class="col-12 col-lg-4">
          <div class="card mb-0 shadow-sm myls-card h-100">
            <div class="card-header bg-primary text-white">
              <strong>Info</strong>
            </div>
            <div class="card-body">
              <p class="mb-2">
                This toggle enables the framework to output <em>FAQPage</em> / FAQ markup where appropriate.
                Individual Q&amp;A items are managed on the specific pages/posts (not here).
              </p>
              <ul class="mb-0">
                <li>Great for service pages with a short Q&amp;A section.</li>
                <li>Pairs with Organization/LocalBusiness data.</li>
                <li>Keep answers concise; avoid heavy HTML in JSON-LD.</li>
              </ul>
            </div>
          </div>
        </div>

      </div>

      <!-- ================================================================
           Generate Service FAQ Page Card
           ================================================================ -->
      <div class="row g-3 mt-2">
        <div class="col-12 col-lg-8">
          <div class="card mb-0 shadow-sm myls-card">
            <div class="card-header bg-primary text-white d-flex" style="justify-content:space-between;align-items:center;">
              <strong><i class="bi bi-file-earmark-text"></i> Generate Service FAQ Page</strong>
              <?php if ( $page_exists ) : ?>
                <span class="badge" style="background:#198754;color:#fff;font-size:.7rem;">Page exists</span>
              <?php endif; ?>
            </div>
            <div class="card-body">

              <p class="text-muted mb-3" style="font-size:.9rem;">
                Creates a WordPress page that dynamically renders FAQs from <strong>all published Service posts</strong>.
                Uses the <code>[service_faq_page]</code> shortcode so the page always reflects current FAQ data.
                Duplicate questions across services are automatically removed. Valid <strong>FAQPage JSON-LD</strong> schema
                is injected into <code>&lt;head&gt;</code>.
              </p>

              <div class="row g-3">
                <div class="col-12 col-md-4">
                  <label class="form-label" for="myls_svc_faq_page_title">Page Title</label>
                  <input
                    type="text"
                    class="form-control"
                    id="myls_svc_faq_page_title"
                    value="<?php echo esc_attr( $page_title_val ); ?>"
                    placeholder="Service FAQs"
                  >
                </div>
                <div class="col-12 col-md-4">
                  <label class="form-label" for="myls_svc_faq_page_slug">Page Slug</label>
                  <input
                    type="text"
                    class="form-control"
                    id="myls_svc_faq_page_slug"
                    value="<?php echo esc_attr( $page_slug_val ); ?>"
                    placeholder="service-faqs"
                  >
                  <small class="text-muted"><?php echo esc_html( home_url( '/' ) ); ?><span id="myls_slug_preview"><?php echo esc_html( $page_slug_val ); ?></span>/</small>
                </div>
                <div class="col-12 col-md-4">
                  <label class="form-label" for="myls_svc_faq_page_status">Page Status</label>
                  <select class="form-select form-control" id="myls_svc_faq_page_status">
                    <option value="publish" <?php selected( $page_status_val, 'publish' ); ?>>Published</option>
                    <option value="draft"   <?php selected( $page_status_val, 'draft' ); ?>>Draft</option>
                  </select>
                </div>
              </div>

              <div class="d-flex gap-2 mt-3" style="align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn btn-primary" id="myls_generate_svc_faq_page_btn">
                  <i class="bi bi-lightning-charge"></i>
                  <?php echo $page_exists ? 'Update FAQ Page' : 'Generate FAQ Page'; ?>
                </button>

                <?php if ( $page_exists ) : ?>
                  <a href="<?php echo esc_url( get_permalink( $existing_page ) ); ?>" target="_blank" class="btn btn-outline-secondary" style="text-decoration:none;">
                    <i class="bi bi-eye"></i> View Page
                  </a>
                  <a href="<?php echo esc_url( get_edit_post_link( $existing_page, 'raw' ) ); ?>" target="_blank" class="btn btn-outline-secondary" style="text-decoration:none;">
                    <i class="bi bi-pencil-square"></i> Edit Page
                  </a>
                <?php endif; ?>

                <span id="myls_svc_faq_spinner" style="display:none;">
                  <span class="spinner" style="visibility:visible;float:none;margin:0 6px;"></span>
                </span>
              </div>

              <div id="myls_svc_faq_result" class="mt-3" style="display:none;"></div>

            </div>
          </div>
        </div>

        <!-- RIGHT: Stats card -->
        <div class="col-12 col-lg-4">
          <div class="card mb-0 shadow-sm myls-card h-100">
            <div class="card-header bg-primary text-white">
              <strong><i class="bi bi-bar-chart"></i> FAQ Stats</strong>
            </div>
            <div class="card-body">
              <?php if ( ! post_type_exists('service') ) : ?>
                <p class="text-muted">The <strong>Service</strong> CPT is not enabled. Enable it in the CPT tab first.</p>
              <?php else : ?>
                <table style="width:100%;border-collapse:collapse;">
                  <tr>
                    <td style="padding:6px 0;font-weight:600;">Published Services</td>
                    <td style="padding:6px 0;text-align:right;">
                      <span class="badge"><?php echo (int) $service_count; ?></span>
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;font-weight:600;">Services with FAQs</td>
                    <td style="padding:6px 0;text-align:right;">
                      <span class="badge"><?php echo (int) $faq_service_count; ?></span>
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;font-weight:600;">Total FAQ Items</td>
                    <td style="padding:6px 0;text-align:right;">
                      <span class="badge"><?php echo (int) $total_faq_raw; ?></span>
                    </td>
                  </tr>
                  <tr>
                    <td style="padding:6px 0;font-weight:600;">After Dedup</td>
                    <td style="padding:6px 0;text-align:right;">
                      <span class="badge" style="background:#198754;color:#fff;"><?php echo (int) $total_faq_deduped; ?></span>
                    </td>
                  </tr>
                  <?php if ( $dupes > 0 ) : ?>
                  <tr>
                    <td style="padding:6px 0;font-weight:600;">Duplicates Removed</td>
                    <td style="padding:6px 0;text-align:right;">
                      <span class="badge" style="background:#dc3545;color:#fff;"><?php echo (int) $dupes; ?></span>
                    </td>
                  </tr>
                  <?php endif; ?>
                </table>
                <?php if ( $service_count > 0 && $faq_service_count === 0 ) : ?>
                  <p class="text-muted mt-2" style="font-size:.85rem;">
                    <i class="bi bi-info-circle"></i>
                    No FAQ items found yet. Add FAQs via the MYLS FAQs metabox on each Service post.
                  </p>
                <?php endif; ?>
                <?php if ( $page_exists ) : ?>
                  <hr style="margin:12px 0;">
                  <p style="font-size:.85rem;margin:0;">
                    <i class="bi bi-check-circle" style="color:#198754;"></i>
                    <strong>FAQPage JSON-LD</strong> schema is automatically output in <code>&lt;head&gt;</code> on the generated page.
                  </p>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Generate Page AJAX + Slug preview -->
    <script>
    (function(){
      var btn      = document.getElementById('myls_generate_svc_faq_page_btn');
      var spinner  = document.getElementById('myls_svc_faq_spinner');
      var result   = document.getElementById('myls_svc_faq_result');
      var slugIn   = document.getElementById('myls_svc_faq_page_slug');
      var slugPrev = document.getElementById('myls_slug_preview');

      // Live slug preview.
      if (slugIn && slugPrev) {
        slugIn.addEventListener('input', function(){
          var v = this.value.trim().toLowerCase()
            .replace(/[^a-z0-9\-]/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
          slugPrev.textContent = v || 'service-faqs';
        });
      }

      if (!btn) return;

      btn.addEventListener('click', function(e){
        e.preventDefault();

        var title  = document.getElementById('myls_svc_faq_page_title').value.trim() || 'Service FAQs';
        var slug   = slugIn ? slugIn.value.trim() : '';
        var status = document.getElementById('myls_svc_faq_page_status').value || 'publish';

        btn.disabled = true;
        spinner.style.display = 'inline-block';
        result.style.display  = 'none';

        var fd = new FormData();
        fd.append('action',      'myls_generate_service_faq_page');
        fd.append('nonce',       '<?php echo wp_create_nonce("myls_schema_save"); ?>');
        fd.append('page_title',  title);
        fd.append('page_slug',   slug);
        fd.append('page_status', status);

        fetch(ajaxurl, { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(resp){
            btn.disabled = false;
            spinner.style.display = 'none';
            result.style.display  = 'block';

            if (resp.success) {
              var d = resp.data;
              var html = '<div class="alert" style="background:#d1e7dd;border:1px solid #badbcc;border-radius:.5rem;padding:12px 16px;">';
              html += '<strong>&#10003; ' + d.message + '</strong><br>';
              html += '<span style="font-size:.9rem;">';
              html += d.total_services + ' service(s) &middot; ';
              html += d.services_w_faqs + ' with FAQs &middot; ';
              html += d.total_faqs + ' unique FAQ items';
              if (d.dupes_removed > 0) {
                html += ' &middot; <span style="color:#dc3545;">' + d.dupes_removed + ' duplicate(s) removed</span>';
              }
              html += '</span><br>';
              html += '<a href="' + d.view_url + '" target="_blank" style="margin-right:10px;">View Page &rarr;</a>';
              html += '<a href="' + d.edit_url + '" target="_blank">Edit Page &rarr;</a>';
              html += '</div>';
              result.innerHTML = html;

              // Update slug field + preview with actual saved slug.
              if (d.page_slug && slugIn) {
                slugIn.value = d.page_slug;
                if (slugPrev) slugPrev.textContent = d.page_slug;
              }

              // Update button text.
              btn.innerHTML = '<i class="bi bi-lightning-charge"></i> Update FAQ Page';
            } else {
              result.innerHTML = '<div class="alert" style="background:#f8d7da;border:1px solid #f5c2c7;border-radius:.5rem;padding:12px 16px;">'
                + '<strong>Error:</strong> ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error')
                + '</div>';
            }
          })
          .catch(function(err){
            btn.disabled = false;
            spinner.style.display = 'none';
            result.style.display  = 'block';
            result.innerHTML = '<div class="alert" style="background:#f8d7da;border:1px solid #f5c2c7;border-radius:.5rem;padding:12px 16px;">'
              + '<strong>Error:</strong> ' + err.message
              + '</div>';
          });
      });
    })();
    </script>
    <?php
  },

  'on_save'=> function () {
    if (
      ! isset($_POST['myls_schema_nonce']) ||
      ! wp_verify_nonce($_POST['myls_schema_nonce'],'myls_schema_save') ||
      ! current_user_can('manage_options')
    ) {
      return;
    }

    $val = (isset($_POST['myls_faq_enabled']) && $_POST['myls_faq_enabled'] === '1') ? '1' : '0';
    update_option('myls_faq_enabled', $val);
  }
];

if (defined('MYLS_SCHEMA_DISCOVERY') && MYLS_SCHEMA_DISCOVERY) return $spec;
return null;
