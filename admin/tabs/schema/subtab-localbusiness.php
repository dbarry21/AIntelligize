<?php
/**
 * Subtab: Local Business
 * Path: admin/tabs/schema/subtab-localbusiness.php
 *
 * Hours UI:
 * - .myls-hours-list is block (not flex) so each .myls-hours-row stays on its own line.
 * - Grid-based row keeps Day | Open | Close aligned; horizontal scroll on narrow screens.
 */

if (!defined('ABSPATH')) exit;

$spec = [
  'id'    => 'localbusiness',
  'label' => 'Local Business',
  'order' => 20,

  'render'=> function () {

    // Enqueue WP media library for image URL pickers on location rows.
    if ( function_exists('wp_enqueue_media') ) {
      wp_enqueue_media();
    }

    // ---------- US states & Countries ----------
    $us_states = [
      'AL'=>'Alabama','AK'=>'Alaska','AZ'=>'Arizona','AR'=>'Arkansas','CA'=>'California','CO'=>'Colorado','CT'=>'Connecticut',
      'DE'=>'Delaware','FL'=>'Florida','GA'=>'Georgia','HI'=>'Hawaii','ID'=>'Idaho','IL'=>'Illinois','IN'=>'Indiana','IA'=>'Iowa',
      'KS'=>'Kansas','KY'=>'Kentucky','LA'=>'Louisiana','ME'=>'Maine','MD'=>'Maryland','MA'=>'Massachusetts','MI'=>'Michigan',
      'MN'=>'Minnesota','MS'=>'Mississippi','MO'=>'Missouri','MT'=>'Montana','NE'=>'Nebraska','NV'=>'Nevada','NH'=>'New Hampshire',
      'NJ'=>'New Jersey','NM'=>'New Mexico','NY'=>'New York','NC'=>'North Carolina','ND'=>'North Dakota','OH'=>'Ohio','OK'=>'Oklahoma',
      'OR'=>'Oregon','PA'=>'Pennsylvania','RI'=>'Rhode Island','SC'=>'South Carolina','SD'=>'South Dakota','TN'=>'Tennessee',
      'TX'=>'Texas','UT'=>'Utah','VT'=>'Vermont','VA'=>'Virginia','WA'=>'Washington','WV'=>'West Virginia','WI'=>'Wisconsin','WY'=>'Wyoming',
      'DC'=>'District of Columbia','PR'=>'Puerto Rico'
    ];
    $state_name_to_code = [];
    foreach ($us_states as $code => $name) $state_name_to_code[strtolower($name)] = $code;

    $countries = [
      'US'=>'United States','CA'=>'Canada','MX'=>'Mexico','GB'=>'United Kingdom','IE'=>'Ireland','AU'=>'Australia','NZ'=>'New Zealand',
      'DE'=>'Germany','FR'=>'France','ES'=>'Spain','IT'=>'Italy','NL'=>'Netherlands','SE'=>'Sweden','NO'=>'Norway','DK'=>'Denmark',
      'FI'=>'Finland','PT'=>'Portugal','CH'=>'Switzerland','AT'=>'Austria','BE'=>'Belgium','PL'=>'Poland','CZ'=>'Czech Republic',
      'JP'=>'Japan','SG'=>'Singapore','IN'=>'India','ZA'=>'South Africa','BR'=>'Brazil','AR'=>'Argentina'
    ];

    // ---------- Normalizers ----------
    $normalize_state = function($state) use ($us_states, $state_name_to_code) {
      $s = trim((string)$state);
      if (isset($us_states[strtoupper($s)])) return strtoupper($s);
      $k = strtolower($s);
      return $state_name_to_code[$k] ?? '';
    };
    $normalize_country = function($country) use ($countries) {
      $c = trim((string)$country);
      if (isset($countries[strtoupper($c)])) return strtoupper($c);
      foreach ($countries as $code=>$name) if (strcasecmp($name, $c) === 0) return $code;
      return 'US';
    };

    // ---------- Collect raw org options with fallbacks to ssseo_* ----------
    $org_options_raw = [
      '_myls' => [
        'name'     => get_option('myls_org_name', ''),
        'tel'      => get_option('myls_org_tel', ''),
        'street'   => get_option('myls_org_street', ''),
        'locality' => get_option('myls_org_locality', ''),
        'region'   => get_option('myls_org_region', ''),
        'postal'   => get_option('myls_org_postal', ''),
        'country'  => get_option('myls_org_country', ''),
        'lat'      => get_option('myls_org_lat', ''),
        'lng'      => get_option('myls_org_lng', ''),
      ],
      '_ssseo' => [
        'name'     => get_option('ssseo_organization_name',''),
        'tel'      => get_option('ssseo_organization_phone',''),
        'street'   => get_option('ssseo_organization_address',''),
        'locality' => get_option('ssseo_organization_locality',''),
        'region'   => get_option('ssseo_organization_state',''),
        'postal'   => get_option('ssseo_organization_postal_code',''),
        'country'  => get_option('ssseo_organization_country',''),
        'lat'      => get_option('ssseo_organization_latitude',''),
        'lng'      => get_option('ssseo_organization_longitude',''),
      ],
    ];

    // Effective org values = myls_* OR fallback to ssseo_*
    $org_effective = [
      'name'     => $org_options_raw['_myls']['name']     !== '' ? $org_options_raw['_myls']['name']     : $org_options_raw['_ssseo']['name'],
      'tel'      => $org_options_raw['_myls']['tel']      !== '' ? $org_options_raw['_myls']['tel']      : $org_options_raw['_ssseo']['tel'],
      'street'   => $org_options_raw['_myls']['street']   !== '' ? $org_options_raw['_myls']['street']   : $org_options_raw['_ssseo']['street'],
      'locality' => $org_options_raw['_myls']['locality'] !== '' ? $org_options_raw['_myls']['locality'] : $org_options_raw['_ssseo']['locality'],
      'region'   => $org_options_raw['_myls']['region']   !== '' ? $org_options_raw['_myls']['region']   : $org_options_raw['_ssseo']['region'],
      'postal'   => $org_options_raw['_myls']['postal']   !== '' ? $org_options_raw['_myls']['postal']   : $org_options_raw['_ssseo']['postal'],
      'country'  => $org_options_raw['_myls']['country']  !== '' ? $org_options_raw['_myls']['country']  : $org_options_raw['_ssseo']['country'],
      'lat'      => $org_options_raw['_myls']['lat']      !== '' ? $org_options_raw['_myls']['lat']      : $org_options_raw['_ssseo']['lat'],
      'lng'      => $org_options_raw['_myls']['lng']      !== '' ? $org_options_raw['_myls']['lng']      : $org_options_raw['_ssseo']['lng'],
    ];

    // ---------- Organization defaults ----------
    $org_defaults = [
      'location_label' => 'Headquarters (Default)',
      'name'    => $org_effective['name'],
      'phone'   => $org_effective['tel'],
      'price'   => '',
      'street'  => $org_effective['street'],
      'city'    => $org_effective['locality'],
      'state'   => $normalize_state($org_effective['region']),
      'zip'     => $org_effective['postal'],
      'country' => $normalize_country($org_effective['country']),
      'lat'     => $org_effective['lat'],
      'lng'     => $org_effective['lng'],
      'hours'   => [['day'=>'','open'=>'','close'=>'']],
      'pages'   => [],
      'image_url' => '',  
    ];

    // ---------- DEBUG scaffold ----------
    $debug = [
      'org_options'   => $org_options_raw,
      'org_effective' => $org_effective,
      'org_defaults'  => $org_defaults,
      'raw_option'    => null,
      'seed_path'     => [],
      'locations_after' => null,
    ];

    // ---------- Load & prefill ----------
    $locations_raw = get_option('myls_lb_locations', []);
    $debug['raw_option'] = $locations_raw;
    if (!is_array($locations_raw)) $locations_raw = [];

    $merge_with_defaults = function(array $defaults, array $loc) use ($normalize_state, $normalize_country) : array {
      if (isset($loc['state']))   { $loc['state']   = $normalize_state($loc['state']); }
      if (isset($loc['country'])) { $loc['country'] = $normalize_country($loc['country'] ?: 'US'); }
      $out = $defaults;
      foreach ($defaults as $k => $defVal) {
        if (!array_key_exists($k, $loc)) continue;
        $val = $loc[$k];
        $is_empty = ($val === '' || $val === null || (is_array($val) && count(array_filter($val, function($x){
          if (is_array($x)) { return implode('', array_map('strval', $x)) !== ''; }
          return (string)$x !== '';
        })) === 0));
        if (!$is_empty) $out[$k] = $val;
      }
      if (empty($out['hours']) || !is_array($out['hours'])) $out['hours'] = [['day'=>'','open'=>'','close'=>'']];
      if (!isset($out['pages']) || !is_array($out['pages'])) $out['pages'] = [];
      return $out;
    };

    $locations = [];
    if (empty($locations_raw) || (count($locations_raw) === 1 && !is_array($locations_raw[0]))) {
      $locations = [ $org_defaults ];
      $debug['seed_path'][] = 'seed_from_org: empty_or_malformed_db';
    } else {
      foreach ($locations_raw as $i => $loc) {
        $loc = is_array($loc) ? $loc : [];
        $locations[$i] = $merge_with_defaults($org_defaults, $loc);
      }
      $critical = ['name','street','city','state','zip','country'];
      $first_missing = true;
      foreach ($critical as $k) { if (!empty($locations[0][$k])) { $first_missing = false; break; } }
      if ($first_missing) {
        $locations[0] = $org_defaults;
        $debug['seed_path'][] = 'first_empty_overwrite';
      } else {
        $debug['seed_path'][] = 'empty_safe_merge';
      }
    }

    // Build pages map for Assignments UI
    $loc_pages_map = [];
    foreach ($locations as $i => $loc) $loc_pages_map[$i] = array_map('absint', (array)($loc['pages'] ?? []));
    $debug['locations_after'] = $locations;

    $org_all_blank = (trim($org_defaults['name'].$org_defaults['street'].$org_defaults['city'].$org_defaults['state'].$org_defaults['zip']) === '');

    // ---------- knowsAbout include list ----------
    // Opt-in: only explicitly selected items appear in knowsAbout schema output.
    // Stored as an array of post IDs (ints) plus the sentinel '__subtype__' for
    // the Service schema name field (myls_service_subtype).
    $service_posts_all = post_type_exists('service') ? get_posts([
      'post_type'      => 'service',
      'post_status'    => 'publish',
      'numberposts'    => 200,
      'orderby'        => 'menu_order title',
      'order'          => 'ASC',
    ]) : [];

    $service_subtype_val = trim( (string) get_option('myls_service_subtype', '') );

    // Saved include list: mix of int post IDs and '__subtype__' sentinel.
    $knows_about_include = (array) get_option('myls_lb_knows_about_include', []);

    // ---------- Assignable content ----------
    $assignable = get_posts([
      'post_type'   => ['page','post','service_area'],
      'post_status' => 'publish',
      'numberposts' => -1,
      'orderby'     => 'title',
      'order'       => 'asc',
    ]);
    ?>

    <style>
      .myls-lb-wrap { width: 100%; }
      .myls-lb-grid { display:flex; flex-wrap:wrap; gap:8px; align-items:stretch; }
      .myls-lb-left  { flex:3 1 520px; min-width:320px; }
      .myls-lb-right { flex:1 1 280px; min-width:260px; }

      .myls-block { background:#fff; border:1px solid #000; border-radius:1em; padding:12px; }
      .myls-block-title { font-weight:800; margin:0 0 8px; }

      .myls-lb-wrap input[type="text"], .myls-lb-wrap input[type="email"], .myls-lb-wrap input[type="url"],
      .myls-lb-wrap input[type="time"], .myls-lb-wrap input[type="tel"], .myls-lb-wrap textarea, .myls-lb-wrap select {
        border:1px solid #000 !important; border-radius:1em !important; padding:.6rem .9rem; width:100%;
      }
      .form-label { font-weight:600; margin-bottom:.35rem; display:block; }
      .myls-hr { height:1px; background:#000; opacity:.15; border:0; margin:8px 0 10px; }
      .myls-actions { margin-top:10px; display:flex; gap:.5rem; flex-wrap:wrap; }
      .myls-btn { display:inline-block; font-weight:600; border:1px solid #000; padding:.45rem .9rem; border-radius:1em; background:#f8f9fa; color:#111; cursor:pointer; }
      .myls-btn-primary { background:#0d6efd; color:#fff; border-color:#0d6efd; }
      .myls-btn-outline { background:transparent; }
      .myls-btn-danger  { border-color:#dc3545; color:#dc3545; }
      .myls-btn-danger:hover { background:#dc3545; color:#fff; }
      .myls-btn:hover { filter:brightness(.97); }

      .myls-row { display:flex; flex-wrap:wrap; margin-left:-.5rem; margin-right:-.5rem; }
      .myls-col { padding-left:.5rem; padding-right:.5rem; margin-bottom:.75rem; }
      .col-12 { flex:0 0 100%; max-width:100%; }
      .col-6  { flex:0 0 50%;  max-width:50%; }
      .col-3  { flex:0 0 25%;  max-width:25%; }

      .myls-fold { border:1px solid #000; border-radius:1em; padding:8px 12px; margin-bottom:8px; background:#fff; }
      .myls-fold > summary { cursor:pointer; font-weight:700; list-style:none; margin:-8px -12px 8px -12px; padding:8px 12px; border-radius:1em; background:#f0f6ff; }
      .myls-fold[open] > summary { background:#e2ecff; }
      .myls-fold summary::-webkit-details-marker { display:none; }

      .myls-debug details { margin-top:8px; }
      .myls-debug pre { max-height:360px; overflow:auto; padding:8px; border:1px solid #000; border-radius:8px; background:#fafafa; }
      .myls-note { margin:8px 0; padding:8px 10px; border:1px dashed #666; border-radius:8px; background:#fffef5; font-size:13px; }

      /* --- HOURS: keep each row on its own line --- */
      .myls-hours-wrap { overflow-x: auto; }
      .myls-hours-list { display:block; }
      .myls-hours-row {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;  /* Day | Open | Close */
        gap: .5rem;
        align-items: center;
        min-width: 540px;
        margin-top: .25rem;
      }
      .myls-hours-row .myls-col { margin-bottom: 0; padding-left: 0; padding-right: 0; }
      .myls-hours-row select,
      .myls-hours-row input[type="time"] { width: 100%; }
    </style>

    <!-- Render -->
    <div class="myls-lb-wrap">
      <div class="myls-lb-grid">
        <!-- LEFT -->
        <div class="myls-lb-left">
          <div class="myls-block">
            <div class="myls-block-title">Locations <span style="font-weight:600">(Location #1 is default)</span></div>

            <?php if ($org_all_blank): ?>
              <div class="myls-note">
                Organization values look empty. Open <em>Schema → Organization</em> and click <strong>Save Settings</strong> once.
              </div>
            <?php endif; ?>

            <div id="myls-location-list">
              <?php foreach ($locations as $i=>$loc):
                ob_start();
                echo '<option value="">— Select —</option>';
                foreach ($us_states as $code=>$name) {
                  printf('<option value="%s"%s>%s</option>',
                    esc_attr($code),
                    selected($loc['state'], $code, false),
                    esc_html($name)
                  );
                }
                $state_options_html = ob_get_clean();

                ob_start();
                foreach ($countries as $code=>$name) {
                  printf('<option value="%s"%s>%s</option>',
                    esc_attr($code),
                    selected(($loc['country'] ?: 'US'), $code, false),
                    esc_html($name)
                  );
                }
                $country_options_html = ob_get_clean();
              ?>
              <details class="myls-fold" <?php echo $i===0 ? 'open' : ''; ?>>
                <summary><?php echo $loc['location_label'] ? esc_html($loc['location_label']) : 'Location #'.($i+1); ?></summary>

                <div class="myls-row">
                  <div class="myls-col col-6">
                    <label class="form-label">Location Label</label>
                    <input type="text" name="myls_locations[<?php echo $i;?>][location_label]" value="<?php echo esc_attr($loc['location_label']); ?>">
                  </div>
                  <div class="myls-col col-6">
                    <label class="form-label">Business Name</label>
                    <input type="text" name="myls_locations[<?php echo $i;?>][name]" value="<?php echo esc_attr($loc['name']); ?>">
                  </div>

                  <div class="myls-col col-6">
                    <label class="form-label">Business Image URL</label>
                    <div style="display:flex;gap:.4rem;align-items:center;">
                      <input type="url" class="myls-loc-image-url" name="myls_locations[<?php echo $i; ?>][image_url]" value="<?php echo esc_attr($loc['image_url'] ?? ''); ?>" placeholder="https://example.com/path/to/image.jpg" style="flex:1;">
                      <button type="button" class="myls-btn myls-btn-outline myls-loc-image-pick" style="white-space:nowrap;">Select</button>
                      <button type="button" class="myls-btn myls-btn-danger myls-loc-image-clear" style="padding:0 8px;">✕</button>
                    </div>
                  </div>

                  <div class="myls-col col-6">
                    <label class="form-label">Phone</label>
                    <input class="myls-phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="(555) 555-1234"
                           name="myls_locations[<?php echo $i;?>][phone]" value="<?php echo esc_attr($loc['phone']); ?>">
                  </div>
                  <div class="myls-col col-6">
                    <label class="form-label">Price Range</label>
                    <input type="text" name="myls_locations[<?php echo $i;?>][price]" value="<?php echo esc_attr($loc['price']); ?>">
                  </div>

                  <div class="myls-col col-6">
                    <label class="form-label">Street</label>
                    <input type="text" name="myls_locations[<?php echo $i;?>][street]" value="<?php echo esc_attr($loc['street']); ?>">
                  </div>
                  <div class="myls-col col-3">
                    <label class="form-label">City</label>
                    <input type="text" name="myls_locations[<?php echo $i;?>][city]" value="<?php echo esc_attr($loc['city']); ?>">
                  </div>
                  <div class="myls-col col-3">
                    <label class="form-label">State</label>
                    <select name="myls_locations[<?php echo $i;?>][state]"><?php echo $state_options_html; ?></select>
                  </div>
                  <div class="myls-col col-3">
                    <label class="form-label">ZIP</label>
                    <input type="text" name="myls_locations[<?php echo $i;?>][zip]" value="<?php echo esc_attr($loc['zip']); ?>">
                  </div>
                  <div class="myls-col col-3">
                    <label class="form-label">Country</label>
                    <select name="myls_locations[<?php echo $i;?>][country]"><?php echo $country_options_html; ?></select>
                  </div>

                  <div class="myls-col col-3">
                    <label class="form-label">Latitude</label>
                    <input type="text" name="myls_locations[<?php echo $i;?>][lat]" value="<?php echo esc_attr($loc['lat']); ?>">
                  </div>
                  <div class="myls-col col-3">
                    <label class="form-label">Longitude</label>
                    <input type="text" name="myls_locations[<?php echo $i;?>][lng]" value="<?php echo esc_attr($loc['lng']); ?>">
                  </div>

                </div>

                <hr class="myls-hr">

                <!-- HOURS -->
                <label class="form-label">Opening Hours</label>
                <div class="myls-hours-wrap">
                  <div class="myls-hours-list" id="hours-<?php echo $i; ?>">
                    <?php foreach ($loc['hours'] as $j => $h): ?>
                      <div class="myls-hours-row">
                        <div class="myls-col">
                          <select name="myls_locations[<?php echo $i;?>][hours][<?php echo $j;?>][day]">
                            <option value="">-- Day --</option>
                            <?php foreach (["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"] as $d): ?>
                              <option value="<?php echo esc_attr($d); ?>" <?php selected($h['day']??'', $d); ?>>
                                <?php echo esc_html($d); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="myls-col">
                          <input type="time" name="myls_locations[<?php echo $i;?>][hours][<?php echo $j;?>][open]" value="<?php echo esc_attr($h['open']??''); ?>">
                        </div>
                        <div class="myls-col">
                          <input type="time" name="myls_locations[<?php echo $i;?>][hours][<?php echo $j;?>][close]" value="<?php echo esc_attr($h['close']??''); ?>">
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="myls-actions">
                  <button type="button" class="myls-btn myls-btn-outline myls-add-hours" data-target="hours-<?php echo $i; ?>" data-index="<?php echo $i; ?>">+ Add Hours Row</button>
                  <button type="submit" name="myls_delete_location" value="<?php echo esc_attr($i); ?>" class="myls-btn myls-btn-danger">Delete This Location</button>
                </div>
              </details>
              <?php endforeach; ?>
            </div>

            <div class="myls-actions">
              <button type="button" class="myls-btn myls-btn-outline" id="myls-add-location">+ Add Location</button>
              <button class="myls-btn myls-btn-primary" type="submit">Save Locations</button>
            </div>

            <div class="myls-debug">
              <details>
                <summary><strong>Debug (LocalBusiness)</strong></summary>
                <pre><?php echo esc_html( wp_json_encode($debug, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) ); ?></pre>
              </details>
            </div>

          </div>
        </div>

        <!-- RIGHT -->
        <div class="myls-lb-right">
          <div class="myls-block">
            <div class="myls-block-title">Assignments</div>

            <label class="form-label" for="myls-assign-loc">Edit assignments for:</label>
            <select id="myls-assign-loc"></select>

            <label class="form-label" style="margin-top:.5rem">Pages / Posts / Service Areas</label>
            <select id="myls-assign-pages" multiple size="16" style="min-height: 420px;">
              <?php foreach ($assignable as $p):
                $pt = get_post_type($p->ID);
                $pre = $pt==='service_area' ? 'Service Area: ' : ($pt==='post' ? 'Post: ' : '');
                echo '<option value="'.absint($p->ID).'">'.$pre.esc_html($p->post_title).'</option>';
              endforeach; ?>
            </select>
            <div id="myls-assignment-hidden"></div>
            <div class="small" style="opacity:.8; margin-top:.5rem;">
              Tip: pick a location above, select its pages here, then Save.
            </div>
          </div>
        </div>
      </div>

      <div class="myls-block" style="margin-top:8px;">
        <div class="myls-block-title">Tips</div>
        <p>Use clear <em>Location Label</em> names (e.g., “Downtown Tampa”).</p>
        <p><strong>Note:</strong> Location #1 is the default when a page doesn’t match any assigned location.</p>
      </div>

      <!-- Site-wide defaults for optional LocalBusiness fields -->
      <div class="myls-block" style="margin-top:8px;">
        <div class="myls-block-title">Site-wide Defaults</div>
        <p style="margin:0 0 10px;">
          These values apply to <strong>all locations</strong> as a fallback when the per-location field is blank.
          Filling them eliminates the optional warnings in Google's Rich Results Test.
        </p>

        <div class="myls-row">
          <div class="myls-col col-6">
            <label class="form-label" for="myls_lb_default_price_range">
              Default Price Range
              <span style="font-weight:400;color:#6b7280;"> — used when no per-location Price Range is set</span>
            </label>
            <input type="text"
                   id="myls_lb_default_price_range"
                   name="myls_lb_default_price_range"
                   value="<?php echo esc_attr( get_option('myls_lb_default_price_range', '') ); ?>"
                   placeholder="e.g. $$ or $150–$500">
            <p class="form-text" style="margin-top:4px;opacity:.75;">
              Schema.org accepts a free-text string. Common formats: <code>$$</code>, <code>$–$$$</code>, or a dollar range like <code>$150–$500</code>.
            </p>
          </div>

          <div class="myls-col col-6">
            <label class="form-label">
              Image Fallback Chain
              <span style="font-weight:400;color:#6b7280;"> — read-only reference</span>
            </label>
            <p style="margin:4px 0 0;font-size:.875rem;line-height:1.6;">
              <?php
              $fb_loc   = '❌ Not set (per-location field)';
              $fb_logo  = '❌ Not set (Org logo attachment)';
              $fb_img   = '❌ Not set (Org image URL)';
              $logo_id_v = (int) get_option('myls_org_logo_id', 0);
              if ( $logo_id_v ) {
                $u = wp_get_attachment_image_url($logo_id_v, 'thumbnail');
                $fb_logo = $u ? '✅ Org logo: <img src="'.esc_url($u).'" style="height:28px;vertical-align:middle;margin-left:4px;">' : '⚠️ Logo ID set but URL unavailable';
              }
              $img_url_v = trim((string) get_option('myls_org_image_url', ''));
              if ( $img_url_v ) $fb_img = '✅ Org image URL set';
              echo '<strong>1.</strong> Per-location Business Image URL<br>';
              echo '<strong>2.</strong> '.$fb_logo.'<br>';
              echo '<strong>3.</strong> '.$fb_img;
              ?>
            </p>
            <p class="form-text" style="margin-top:6px;opacity:.75;">
              Set Org Logo under Schema → Organization, or Org Image URL in the Organization settings to resolve the image warning.
            </p>
          </div>
        </div>
      </div>

      <!-- knowsAbout include list — global, not per-location -->
      <div class="myls-block" style="margin-top:8px;">
        <div class="myls-block-title">knowsAbout — Schema Topics (opt-in)</div>
        <p style="margin:0 0 8px;">
          Select which Service posts (and optionally the Service schema name) should appear
          as <code>knowsAbout</code> entries on your LocalBusiness and Organization schema.
          Only selected items are included — nothing is added automatically.
        </p>

        <?php if ( empty($service_posts_all) && empty($service_subtype_val) ) : ?>
          <p style="color:#888;font-style:italic;">
            No published Service posts found and no Service schema name is set.
            Publish Service posts or set a name under Schema → Service to populate this list.
          </p>
        <?php else : ?>
          <label class="form-label" for="myls-knows-about-select">
            Hold <strong>Ctrl / Cmd</strong> to select multiple items.
          </label>
          <select id="myls-knows-about-select"
                  name="myls_lb_knows_about_include[]"
                  multiple
                  size="<?php echo max(4, min(12, count($service_posts_all) + ($service_subtype_val ? 1 : 0))); ?>"
                  style="width:100%;">

            <?php if ( $service_subtype_val !== '' ) :
              $sub_selected = in_array('__subtype__', $knows_about_include, true) ? ' selected' : '';
            ?>
              <option value="__subtype__"<?php echo $sub_selected; ?>>
                [Service Schema Name] <?php echo esc_html($service_subtype_val); ?>
              </option>
            <?php endif; ?>

            <?php foreach ( $service_posts_all as $sp ) :
              $sel = in_array( (int) $sp->ID, array_map('intval', $knows_about_include), true ) ? ' selected' : '';
            ?>
              <option value="<?php echo absint($sp->ID); ?>"<?php echo $sel; ?>>
                <?php echo esc_html( $sp->post_title ); ?>
              </option>
            <?php endforeach; ?>

          </select>
          <p class="form-text" style="margin-top:4px;opacity:.75;">
            <?php echo count($service_posts_all); ?> service post<?php echo count($service_posts_all) !== 1 ? 's' : ''; ?> available.
            Selected items appear as <code>{"@type":"Thing","name":"..."}</code> in both LocalBusiness and Organization schema.
          </p>
        <?php endif; ?>
      </div>
    </div>

    <script>
(function(){
  const ORG_DEFAULTS = <?php echo wp_json_encode($org_defaults, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  const LOC_PAGES  = <?php echo wp_json_encode($loc_pages_map, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
  const esc = (s)=> String(s===null||s===undefined?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

  function formatUSPhone(value) {
    const digits = value.replace(/\D/g, '').slice(0, 10);
    if (digits.length < 4) return digits;
    if (digits.length < 7) return `(${digits.slice(0,3)}) ${digits.slice(3)}`;
    return `(${digits.slice(0,3)}) ${digits.slice(3,6)}-${digits.slice(6)}`;
  }
  function attachPhoneMask(root=document) {
    root.querySelectorAll('input.myls-phone').forEach(inp => {
      inp.addEventListener('input', () => {
        const start = inp.selectionStart, before = inp.value;
        inp.value = formatUSPhone(inp.value);
        if (document.activeElement === inp) {
          const diff = inp.value.length - before.length;
          inp.setSelectionRange(start + diff, start + diff);
        }
      });
      inp.value = formatUSPhone(inp.value);
    });
  }

  const assignLocSel   = document.getElementById('myls-assign-loc');
  const assignPagesSel = document.getElementById('myls-assign-pages');
  const hiddenWrap     = document.getElementById('myls-assignment-hidden');

  // --- PERSISTENT ASSIGNMENT UX FIXES ---
  const LS_KEY = 'myls_lb_assign_loc_index';
  let currentIndex = 0; // tracks which location we’re viewing

  // Build dropdown ONCE from current locations.
  function buildAssignLocationDropdownOnce(){
    const items = document.querySelectorAll('#myls-location-list details.myls-fold');
    const fr = document.createDocumentFragment();
    items.forEach((item, idx) => {
      const opt = document.createElement('option');
      opt.value = String(idx);
      opt.textContent = getLocationLabel(idx);
      fr.appendChild(opt);
      // ensure LOC_PAGES has an array for new indices
      if (!Array.isArray(LOC_PAGES[idx])) LOC_PAGES[idx] = [];
    });
    assignLocSel.innerHTML = '';
    assignLocSel.appendChild(fr);

    // restore last selected, default to 0
    const saved = localStorage.getItem(LS_KEY);
    const want  = saved !== null ? saved : '0';
    assignLocSel.value = assignLocSel.querySelector(`option[value="${want}"]`) ? want : '0';
    currentIndex = parseInt(assignLocSel.value || '0', 10);
  }

  // Read current label for a given index, falling back to defaults.
  function getLocationLabel(idx){
    const input = document.querySelector(`input[name="myls_locations[${idx}][location_label]"]`);
    const val   = (input && input.value.trim()) || '';
    return val || `Location #${idx+1}${idx===0?' (Default)':''}`;
  }

  // Update JUST the one <option>'s text when its label input changes.
  function wireLiveOptionLabelBinding(){
    const list = document.getElementById('myls-location-list');
    list?.addEventListener('input', (e) => {
      const t = e.target;
      if (!t || !t.name) return;
      const m = t.name.match(/^myls_locations\[(\d+)\]\[location_label\]$/);
      if (!m) return;
      const idx = m[1];
      const opt = assignLocSel.querySelector(`option[value="${idx}"]`);
      if (opt) opt.textContent = getLocationLabel(Number(idx));
    });
  }

  // Reflect LOC_PAGES[index] into the multi-select and (preview) hidden inputs
  function syncAssignListFor(index){
    const selected = new Set((LOC_PAGES[index] || []).map(String));
    for (const opt of assignPagesSel.options) opt.selected = selected.has(opt.value);

    // preview-only hidden inputs for the active index
    hiddenWrap.innerHTML = '';
    (LOC_PAGES[index] || []).forEach(val => {
      hiddenWrap.insertAdjacentHTML('beforeend',
        `<input type="hidden" data-volatile="1" name="myls_locations[${index}][pages][]" value="${String(val).replace(/"/g,'&quot;')}">`
      );
    });
  }

  // Pull current UI choices from multi-select into LOC_PAGES[index]
  function commitAssignSelectionTo(index){
    const chosen = Array.from(assignPagesSel.selectedOptions).map(o => o.value);
    LOC_PAGES[index] = chosen.map(v => parseInt(v,10)).filter(v => !isNaN(v));
  }

  // Rebuild ALL hidden inputs for ALL locations (final step before submit)
  function rebuildAllHiddenInputs(){
    hiddenWrap.innerHTML = '';
    Object.keys(LOC_PAGES).forEach(idx => {
      const arr = LOC_PAGES[idx] || [];
      arr.forEach(val => {
        hiddenWrap.insertAdjacentHTML('beforeend',
          `<input type="hidden" name="myls_locations[${idx}][pages][]" value="${val}">`
        );
      });
    });
  }

  // Initialize
  buildAssignLocationDropdownOnce();
  wireLiveOptionLabelBinding();
  syncAssignListFor(currentIndex);
  attachPhoneMask(document);

  // Remember last chosen location; commit current before switching
  assignLocSel.addEventListener('change', () => {
    // 1) commit current selections for the old index
    commitAssignSelectionTo(currentIndex);
    // 2) switch index and reflect state
    currentIndex = parseInt(assignLocSel.value, 10);
    localStorage.setItem(LS_KEY, String(currentIndex));
    syncAssignListFor(currentIndex);
  });

  // Keep LOC_PAGES[currentIndex] updated as user picks/unpicks
  assignPagesSel.addEventListener('change', () => {
    commitAssignSelectionTo(currentIndex);
    // optional: keep preview hidden inputs fresh
    syncAssignListFor(currentIndex);
  });

  // Ensure ALL locations’ hidden inputs exist on submit
  const parentForm = document.querySelector('.myls-lb-wrap')?.closest('form');
  parentForm?.addEventListener('submit', () => {
    // commit whatever is currently on-screen
    commitAssignSelectionTo(currentIndex);
    // now write hidden inputs for every location
    rebuildAllHiddenInputs();
  });

  // When adding a NEW location dynamically, append one <option> and init array
  document.getElementById('myls-add-location')?.addEventListener('click', () => {
    // wait for DOM insert
    requestAnimationFrame(() => {
      const idx = assignLocSel.options.length; // next index
      const opt = document.createElement('option');
      opt.value = String(idx);
      opt.textContent = getLocationLabel(idx);
      assignLocSel.appendChild(opt);
      if (!Array.isArray(LOC_PAGES[idx])) LOC_PAGES[idx] = [];
    });
  });

  // --- HOURS: Add row ---
  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('.myls-add-hours');
    if (!btn) return;

    const targetId = btn.getAttribute('data-target');
    const tgt = document.getElementById(targetId);
    const idx = btn.getAttribute('data-index');
    if (!tgt) return;

    const j = tgt.querySelectorAll('select[name^="myls_locations['+idx+'][hours]"][name$="[day]"]').length;

    const row = document.createElement('div');
    row.className = 'myls-hours-row';
    row.innerHTML =
      '<div class="myls-col">' +
        '<select name="myls_locations['+idx+'][hours]['+j+'][day]">' +
          '<option value="">-- Day --</option>' +
          ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"].map(d=>`<option value="${d}">${d}</option>`).join('') +
        '</select>' +
      '</div>' +
      '<div class="myls-col">' +
        '<input type="time" name="myls_locations['+idx+'][hours]['+j+'][open]">' +
      '</div>' +
      '<div class="myls-col">' +
        '<input type="time" name="myls_locations['+idx+'][hours]['+j+'][close]">' +
      '</div>';

    tgt.appendChild(row);
  });

})();

// Per-location image URL media pickers — delegated to handle dynamic rows
(function(){
  // Use event delegation on the location list so dynamically added rows also work.
  const list = document.getElementById('myls-location-list');
  if (!list) return;

  list.addEventListener('click', function(e){
    // Select button
    const pickBtn = e.target.closest('.myls-loc-image-pick');
    if (pickBtn) {
      e.preventDefault();
      const row = pickBtn.closest('.myls-col');
      const inp = row ? row.querySelector('.myls-loc-image-url') : null;
      if (!inp) return;
      // Re-use cached frame per-input using a data attribute key
      const frame = wp.media({ title: 'Select Location Image', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } });
      frame.on('select', function(){
        const att = frame.state().get('selection').first().toJSON();
        inp.value = att.url;
      });
      frame.open();
      return;
    }
    // Clear button
    const clearBtn = e.target.closest('.myls-loc-image-clear');
    if (clearBtn) {
      const row = clearBtn.closest('.myls-col');
      const inp = row ? row.querySelector('.myls-loc-image-url') : null;
      if (inp) inp.value = '';
    }
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
    ) { return; }

    if (isset($_POST['myls_delete_location'])) {
      $idx = (int) $_POST['myls_delete_location'];
      $ex  = (array) get_option('myls_lb_locations',[]);
      if (isset($ex[$idx])) { unset($ex[$idx]); update_option('myls_lb_locations', array_values($ex)); }
      return;
    }

    $valid_states = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC','PR'];
    $valid_countries = ['US','CA','MX','GB','IE','AU','NZ','DE','FR','ES','IT','NL','SE','NO','DK','FI','PT','CH','AT','BE','PL','CZ','JP','SG','IN','ZA','BR','AR'];

    $raw = isset($_POST['myls_locations']) && is_array($_POST['myls_locations']) ? $_POST['myls_locations'] : [];
    $clean = [];
    foreach ($raw as $loc){
      $state   = strtoupper(trim((string)($loc['state'] ?? '')));
      $country = strtoupper(trim((string)($loc['country'] ?? 'US')));
      if (!in_array($state, $valid_states, true))       $state = '';
      if (!in_array($country, $valid_countries, true))  $country = 'US';

      $one = [
        'location_label' => sanitize_text_field($loc['location_label'] ?? ''),
        'name'       => sanitize_text_field($loc['name'] ?? ''),
        'image_url'  => esc_url_raw($loc['image_url'] ?? ''),
        'phone'      => sanitize_text_field($loc['phone'] ?? ''),
        'price'      => sanitize_text_field($loc['price'] ?? ''),
        'street'     => sanitize_text_field($loc['street'] ?? ''),
        'city'       => sanitize_text_field($loc['city'] ?? ''),
        'state'      => $state,
        'zip'        => sanitize_text_field($loc['zip'] ?? ''),
        'country'    => $country,
        'lat'          => sanitize_text_field($loc['lat'] ?? ''),
        'lng'          => sanitize_text_field($loc['lng'] ?? ''),

        'pages'        => array_map('absint', (array)($loc['pages'] ?? [])),
        'hours'      => [],
      ];

      if (!empty($loc['hours']) && is_array($loc['hours'])){
        foreach ($loc['hours'] as $h){
          $d = sanitize_text_field($h['day']   ?? '');
          $o = sanitize_text_field($h['open']  ?? '');
          $c = sanitize_text_field($h['close'] ?? '');
          if ($d || $o || $c) $one['hours'][] = ['day'=>$d,'open'=>$o,'close'=>$c];
        }
      }
      $clean[] = $one;
    }

    update_option('myls_lb_locations', $clean);

    // Save site-wide defaults.
    update_option('myls_lb_default_price_range', sanitize_text_field($_POST['myls_lb_default_price_range'] ?? ''));

    // Save knowsAbout include list.
    // Values are either int post IDs or the '__subtype__' sentinel string.
    $raw_include = isset($_POST['myls_lb_knows_about_include']) && is_array($_POST['myls_lb_knows_about_include'])
      ? $_POST['myls_lb_knows_about_include']
      : [];
    $clean_include = [];
    foreach ( $raw_include as $v ) {
      if ( $v === '__subtype__' ) {
        $clean_include[] = '__subtype__';
      } elseif ( is_numeric($v) && (int) $v > 0 ) {
        $clean_include[] = (int) $v;
      }
    }
    update_option('myls_lb_knows_about_include', $clean_include);

    // Persist assignments to post meta for quick lookup + durability
    if ( function_exists('myls_lb_sync_postmeta_from_locations') ) {
      myls_lb_sync_postmeta_from_locations( $clean );
    }
  }
];

if (defined('MYLS_SCHEMA_DISCOVERY') && MYLS_SCHEMA_DISCOVERY) return $spec;
return null;
