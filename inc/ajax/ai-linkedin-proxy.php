<?php
/**
 * MYLS - AJAX: LinkedIn Import Methods
 * File: inc/ajax/ai-linkedin-proxy.php
 *
 * WHY HIDDEN FORM INSTEAD OF fetch():
 *  fetch() from linkedin.com to yoursite.com always fails:
 *  - SameSite=Lax blocks WP session cookies on cross-origin POST
 *  - CORS headers must arrive before wp_die() fires (impossible to guarantee)
 *  - Security plugins add their own early blocks
 *
 *  Hidden <form> with target="_blank" bypasses all of this. Browsers have
 *  always allowed cross-origin form POSTs - it is how payment redirects and
 *  OAuth flows work. No CORS headers needed anywhere.
 *
 *  Flow:
 *   1. Admin loads Person tab -> get_bookmarklet generates token + auth transient
 *   2. Admin drags bookmarklet to bookmarks bar (one time setup)
 *   3. Admin opens a LinkedIn profile, clicks bookmarklet:
 *      - JS extracts DOM data
 *      - Submits hidden form (cross-origin POST, no CORS needed)
 *      - New tab opens on WP domain showing success/error HTML page
 *      - WP stores profile in result transient keyed by token
 *   4. WP admin Person tab polls bookmarklet_poll every 2s (same-origin)
 *      - Picks up result, populates person card, stops polling
 *
 * @since 7.8.28
 */

if ( ! defined('ABSPATH') ) exit;


/* ====================================================================
 *  1. PROXY FETCH  (server-side LinkedIn fetch for public profiles)
 * ==================================================================== */
add_action('wp_ajax_myls_linkedin_proxy_fetch', function () : void {

    myls_ai_check_nonce('myls_ai_ops');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    $url = isset($_POST['linkedin_url'])
        ? esc_url_raw( wp_unslash( trim( $_POST['linkedin_url'] ) ) )
        : '';

    if ( ! $url || strpos($url, 'linkedin.com') === false ) {
        wp_send_json_error(['message' => 'A valid LinkedIn URL is required.'], 400);
    }

    $response = wp_remote_get( $url, [
        'timeout'    => 20,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                      . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'headers'    => [
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Cache-Control'   => 'no-cache',
        ],
        'sslverify'  => true,
    ] );

    if ( is_wp_error($response) ) {
        wp_send_json_error([
            'message' => 'Could not reach LinkedIn: ' . $response->get_error_message(),
        ], 502);
    }

    $code = wp_remote_retrieve_response_code($response);
    $html = wp_remote_retrieve_body($response);

    if ( $code === 999 || strpos($html, 'authwall') !== false || strpos($html, 'login?session_redirect') !== false ) {
        wp_send_json_error([
            'message'    => 'LinkedIn requires authentication. Use the Bookmarklet method instead.',
            'needs_auth' => true,
        ]);
    }

    if ( $code !== 200 || strlen(trim($html)) < 500 ) {
        wp_send_json_error([
            'message' => "LinkedIn returned HTTP {$code}. Profile may be private or require login.",
        ], 422);
    }

    if ( ! function_exists('myls_linkedin_extract_from_html') ) {
        wp_send_json_error(['message' => 'LinkedIn HTML extractor unavailable.'], 500);
    }

    $extracted = myls_linkedin_extract_from_html($html, $url);

    if ( strlen(trim($extracted)) < 50 ) {
        wp_send_json_error([
            'message' => 'Page fetched but contained no usable text. Try the Bookmarklet method.',
        ]);
    }

    $model   = (string) get_option('myls_openai_model', '');
    $chat_fn = function_exists('myls_ai_chat') ? 'myls_ai_chat' : 'myls_openai_chat';
    $result  = $chat_fn(
        "=== LINKEDIN PROFILE CONTENT (server-fetched) ===\n{$extracted}\n=== END ===\n\nExtract into JSON structure.",
        [
            'model'       => $model,
            'max_tokens'  => 2000,
            'temperature' => 0.2,
            'system'      => myls_linkedin_extraction_system_prompt(),
        ]
    );

    $result = preg_replace('/^```(?:json)?\s*/i', '', trim($result));
    $result = preg_replace('/\s*```$/i', '', $result);
    $data   = json_decode(trim($result), true);

    if ( ! is_array($data) || json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error(['message' => 'AI returned invalid JSON.', 'raw' => substr($result, 0, 400)], 500);
    }

    wp_send_json_success([
        'message' => 'Profile extracted via server fetch.',
        'method'  => 'proxy',
        'profile' => myls_linkedin_sanitize_profile($data, $url),
    ]);
});


/* ====================================================================
 *  1b. BADGE API FETCH  (public endpoint — no auth required)
 *
 *  LinkedIn's badge service exposes structured profile data publicly
 *  for any profile that has badges enabled. Returns name, headline,
 *  location, and profile image URL. Much more reliable than scraping.
 *
 *  Endpoint:
 *  https://www.linkedin.com/badges/profile/create
 *    ?vanityname={slug}
 *    &preferredlocale=en_US
 *    &version=v1
 *    &size=medium
 *    &badgetype=VERTICAL
 *    &FORMAT=json
 * ==================================================================== */
add_action('wp_ajax_myls_linkedin_badge_fetch', function () : void {

    myls_ai_check_nonce('myls_ai_ops');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    $input_url = isset($_POST['linkedin_url'])
        ? esc_url_raw( wp_unslash( trim( $_POST['linkedin_url'] ) ) )
        : '';

    if ( ! $input_url || strpos($input_url, 'linkedin.com') === false ) {
        wp_send_json_error(['message' => 'A valid LinkedIn profile URL is required.'], 400);
    }

    /* Extract vanity name from URL — handles:
     *   linkedin.com/in/username
     *   linkedin.com/in/username/
     *   linkedin.com/in/username?trk=...  */
    if ( ! preg_match('#linkedin\.com/in/([^/?&#]+)#i', $input_url, $m) ) {
        wp_send_json_error(['message' => 'Could not extract username from URL. Use the format: linkedin.com/in/username'], 400);
    }

    $vanity = sanitize_text_field( $m[1] );

    $badge_url = add_query_arg([
        'vanityname'      => $vanity,
        'preferredlocale' => 'en_US',
        'version'         => 'v1',
        'size'            => 'medium',
        'badgetype'       => 'VERTICAL',
        'FORMAT'          => 'json',
    ], 'https://www.linkedin.com/badges/profile/create');

    $response = wp_remote_get( $badge_url, [
        'timeout'    => 15,
        'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo('version') . ')',
        'headers'    => [
            'Accept' => 'application/json, text/javascript, */*',
        ],
    ] );

    if ( is_wp_error($response) ) {
        wp_send_json_error(['message' => 'Request failed: ' . $response->get_error_message()], 502);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ( $code !== 200 || empty($body) ) {
        wp_send_json_error(['message' => "LinkedIn Badge API returned HTTP {$code}. Profile may be private or badges may be disabled."], 422);
    }

    /* Response is JSONP: badgeProfileCallback({...}) or plain JSON */
    $json = preg_replace('/^\s*\w+\s*\(\s*(.*)\s*\)\s*;?\s*$/s', '$1', trim($body));
    $data = json_decode($json, true);

    if ( ! is_array($data) || json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error(['message' => 'Unexpected response format from LinkedIn Badge API.', 'raw' => substr($body, 0, 200)], 500);
    }

    /* Build profile from badge data fields */
    $profile = [
        'name'             => sanitize_text_field( $data['fullName']  ?? $data['name'] ?? '' ),
        'job_title'        => sanitize_text_field( $data['headline']  ?? $data['title'] ?? '' ),
        'description'      => sanitize_textarea_field( $data['summary'] ?? '' ),
        'url'              => '',
        'email'            => '',
        'phone'            => '',
        'honorific_prefix' => '',
        'same_as'          => [ 'https://www.linkedin.com/in/' . $vanity ],
        'knows_about'      => [],
        'credentials'      => [],
        'alumni'           => [],
        'member_of'        => [],
        'awards'           => [],
        'languages'        => [],
    ];

    /* Some badge responses nest data differently */
    if ( empty($profile['name']) && ! empty($data['badgeContainerContent']) ) {
        /* Fallback: parse name from the HTML fragment */
        if ( preg_match('/<div[^>]+LI-profile-badge[^>]*>.*?<span[^>]*>([^<]+)<\/span>/si', $data['badgeContainerContent'], $nm) ) {
            $profile['name'] = sanitize_text_field( trim($nm[1]) );
        }
    }

    if ( empty($profile['name']) ) {
        wp_send_json_error(['message' => 'Badge API returned data but name was empty. The profile may have badges disabled — try the Bookmarklet method.', 'raw' => array_keys($data)]);
    }

    wp_send_json_success([
        'message' => 'Profile fetched via LinkedIn Badge API.',
        'method'  => 'badge_api',
        'profile' => $profile,
    ]);
});


/* ====================================================================
 *  2. BOOKMARKLET RECEIVE
 *
 *  Receives a cross-origin form POST (target="_blank") from the
 *  bookmarklet on linkedin.com. Outputs an HTML page (not JSON) because
 *  this is a form submission opening in a new browser tab.
 *
 *  Stores the processed profile in a transient so the admin page can
 *  retrieve it via the poll endpoint below (same-origin AJAX).
 * ==================================================================== */
add_action('wp_ajax_nopriv_myls_linkedin_bookmarklet_receive', 'myls_linkedin_bookmarklet_receive_handler');
add_action('wp_ajax_myls_linkedin_bookmarklet_receive',        'myls_linkedin_bookmarklet_receive_handler');

function myls_linkedin_bookmarklet_receive_handler() : void {

    // Accept both GET (window.open navigation) and POST — $_REQUEST covers both
    $token = sanitize_text_field( wp_unslash( $_REQUEST['token'] ?? '' ) );

    if ( strlen($token) !== 64 ) {
        myls_linkedin_bookmarklet_page( false, 'Invalid or missing token. Please regenerate the bookmarklet.' );
    }

    $auth_key = 'myls_bm_auth_' . $token;
    $user_id  = get_transient( $auth_key );

    if ( ! $user_id ) {
        myls_linkedin_bookmarklet_page( false, 'Token expired (2-hour limit). Go back to WordPress and regenerate the bookmarklet.' );
    }

    if ( ! user_can( (int) $user_id, 'manage_options' ) ) {
        myls_linkedin_bookmarklet_page( false, 'Permission denied.' );
    }

    $payload_raw  = isset($_REQUEST['payload'])      ? wp_unslash($_REQUEST['payload'])                   : '';
    $linkedin_url = isset($_REQUEST['linkedin_url']) ? esc_url_raw(wp_unslash($_REQUEST['linkedin_url'])) : '';

    if ( strlen(trim($payload_raw)) < 5 ) {
        myls_linkedin_bookmarklet_page( false, 'Received empty payload. Try clicking the bookmarklet again.' );
    }

    $pre_extracted = json_decode($payload_raw, true);

    /* If name came through, use structured data directly — no AI needed */
    if ( is_array($pre_extracted) && ! empty($pre_extracted['name']) ) {
        // Merge page_title hint into description if description is empty
        if ( empty($pre_extracted['description']) && ! empty($pre_extracted['page_title']) ) {
            $pre_extracted['description'] = '';
        }
        $profile = myls_linkedin_sanitize_profile($pre_extracted, $linkedin_url);
        $method  = 'bookmarklet_structured';
    } else {
        /* Name missing — run AI with whatever context we have */
        $text = is_array($pre_extracted)
            ? json_encode($pre_extracted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : $payload_raw;

        $text = substr( trim($text), 0, 3000 );
        if ( $linkedin_url ) {
            $text .= "\n\nPROFILE URL: " . $linkedin_url;
        }

        $model   = (string) get_option('myls_openai_model', '');
        $chat_fn = function_exists('myls_ai_chat') ? 'myls_ai_chat' : 'myls_openai_chat';
        $result  = $chat_fn(
            "=== LINKEDIN PROFILE CONTENT (bookmarklet) ===\n{$text}\n=== END ===\n\nExtract into JSON structure.",
            [
                'model'       => $model,
                'max_tokens'  => 2000,
                'temperature' => 0.2,
                'system'      => myls_linkedin_extraction_system_prompt(),
            ]
        );

        $result = preg_replace('/^```(?:json)?\s*/i', '', trim($result));
        $result = preg_replace('/\s*```$/i', '', $result);
        $data   = json_decode(trim($result), true);

        if ( ! is_array($data) || json_last_error() !== JSON_ERROR_NONE ) {
            myls_linkedin_bookmarklet_page( false, 'AI returned invalid JSON. Please try again.' );
        }

        $profile = myls_linkedin_sanitize_profile($data, $linkedin_url);
        $method  = 'bookmarklet_ai';
    }

    /* Store result keyed by user_id — survives page reloads and token changes */
    set_transient( 'myls_bm_result_u' . $user_id, [
        'profile' => $profile,
        'method'  => $method,
    ], 10 * MINUTE_IN_SECONDS );

    /* Auth transient stays - token can be reused within the 2-hour window */

    myls_linkedin_bookmarklet_page( true, $profile['name'] ?: 'Profile' );
}


/* ====================================================================
 *  3. BOOKMARKLET POLL  (called by the WP admin page, same-origin)
 *
 *  Admin page polls this every 2 seconds with the current token.
 *  Returns profile data when found; {pending:true} while waiting.
 * ==================================================================== */
add_action('wp_ajax_myls_linkedin_bookmarklet_poll', function () : void {

    myls_ai_check_nonce('myls_ai_ops');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    // Key by user_id — works regardless of which page load generated the bookmarklet
    $result_key = 'myls_bm_result_u' . get_current_user_id();
    $result     = get_transient( $result_key );

    if ( $result === false ) {
        wp_send_json_success(['pending' => true]);
        return;
    }

    delete_transient( $result_key );

    wp_send_json_success([
        'pending' => false,
        'profile' => $result['profile'],
        'method'  => $result['method'],
    ]);
});


/* ====================================================================
 *  4. BOOKMARKLET GENERATOR
 * ==================================================================== */
add_action('wp_ajax_myls_linkedin_get_bookmarklet', function () : void {

    myls_ai_check_nonce('myls_ai_ops');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    $token    = bin2hex( random_bytes(32) );
    $auth_key = 'myls_bm_auth_' . $token;

    set_transient( $auth_key, get_current_user_id(), 2 * HOUR_IN_SECONDS );

    $ajax_url = admin_url('admin-ajax.php');
    $js       = myls_linkedin_build_bookmarklet_js($ajax_url, $token);

    wp_send_json_success([
        'bookmarklet' => 'javascript:' . rawurlencode($js),
        'token'       => $token,
    ]);
});


/* ====================================================================
 *  HTML success/error page shown in the new tab after form submission
 * ==================================================================== */
if ( ! function_exists('myls_linkedin_bookmarklet_page') ) {
    function myls_linkedin_bookmarklet_page( bool $success, string $detail ) : void {
        $title = $success ? 'Profile Received!' : 'Import Error';
        $icon  = $success ? '&#x2705;' : '&#x274C;';
        $color = $success ? '#059669'  : '#dc2626';
        $msg   = $success
            ? 'LinkedIn profile for <strong>' . esc_html($detail) . '</strong> was received. '
              . 'Switch back to your WordPress tab &mdash; the person card will populate automatically.'
            : esc_html($detail);

        status_header( $success ? 200 : 400 );
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head>'
           . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>' . esc_html($title) . ' &mdash; AIntelligize</title>'
           . '<style>'
           . 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;'
           . 'background:#f0f4ff;display:flex;align-items:center;justify-content:center;min-height:100vh;}'
           . '.card{background:#fff;border-radius:1rem;padding:2.5rem 3rem;'
           . 'box-shadow:0 8px 32px rgba(0,0,0,.12);max-width:480px;text-align:center;}'
           . '.icon{font-size:3rem;margin-bottom:.75rem;}'
           . 'h1{font-size:1.4rem;margin:.5rem 0;color:' . $color . ';}'
           . 'p{color:#4b5563;line-height:1.6;margin:.5rem 0 1.5rem;}'
           . 'button{background:' . $color . ';color:#fff;border:none;border-radius:.5rem;'
           . 'padding:.65rem 1.5rem;font-size:14px;font-weight:700;cursor:pointer;}'
           . 'button:hover{opacity:.9;}'
           . '.brand{margin-top:1.5rem;font-size:12px;color:#9ca3af;}'
           . '</style></head><body>'
           . '<div class="card">'
           . '<div class="icon">' . $icon . '</div>'
           . '<h1>' . esc_html($title) . '</h1>'
           . '<p>' . $msg . '</p>'
           . '<button onclick="window.close()">Close This Tab</button>'
           . '<div class="brand">AIntelligize &mdash; LinkedIn Import</div>'
           . '</div>'
           . ( $success ? '<script>setTimeout(function(){window.close();},6000);</script>' : '' )
           . '</body></html>';
        exit;
    }
}


/* ====================================================================
 *  Shared: Extraction system prompt
 * ==================================================================== */
/* ====================================================================
 *  5. SECTION IMPORT  — AI extracts one section from pasted text
 *     and returns only those fields for merging into the card.
 *
 *  Supported sections: certifications, skills, education,
 *                      experience, honors, languages, organizations
 * ==================================================================== */
add_action('wp_ajax_myls_linkedin_section_import', function () : void {

    myls_ai_check_nonce('myls_ai_ops');

    if ( ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    $content = isset($_POST['content']) ? wp_unslash( trim( $_POST['content'] ) ) : '';
    $section = sanitize_key( $_POST['section'] ?? '' );

    $allowed = ['certifications','skills','education','experience','honors','languages','organizations'];
    if ( ! in_array($section, $allowed, true) ) {
        wp_send_json_error(['message' => 'Unknown section type.'], 400);
    }

    if ( strlen($content) < 30 ) {
        wp_send_json_error(['message' => 'Content too short — please paste the full section page.'], 400);
    }

    $content = substr( $content, 0, 8000 );

    /* Section-specific prompts — each returns a focused JSON fragment */
    $prompts = [
        'certifications' => [
            'system' => 'Extract certifications and licenses from the pasted LinkedIn section text. '
                      . 'Return ONLY valid JSON: {"credentials":[{"name":"...","abbr":"","issuer":"...","issuer_url":""}]}. '
                      . 'No markdown, no explanation.',
            'field'  => 'credentials',
            'label'  => 'certifications',
        ],
        'skills' => [
            'system' => 'Extract skills/expertise topics from the pasted LinkedIn section text. '
                      . 'Return ONLY valid JSON: {"knows_about":[{"name":"...","wikidata":"","wikipedia":""}]}. '
                      . 'No markdown, no explanation.',
            'field'  => 'knows_about',
            'label'  => 'skills',
        ],
        'education' => [
            'system' => 'Extract education/alumni info from the pasted LinkedIn section text. '
                      . 'Return ONLY valid JSON: {"alumni":[{"name":"School Name","url":""}]}. '
                      . 'No markdown, no explanation.',
            'field'  => 'alumni',
            'label'  => 'education entries',
        ],
        'experience' => [
            'system' => 'Extract professional expertise topics from the pasted LinkedIn experience section. '
                      . 'Each job title and industry becomes a knows_about entry. '
                      . 'Return ONLY valid JSON: {"knows_about":[{"name":"...","wikidata":"","wikipedia":""}]}. '
                      . 'No markdown, no explanation.',
            'field'  => 'knows_about',
            'label'  => 'expertise topics from experience',
        ],
        'honors' => [
            'system' => 'Extract honors and awards from the pasted LinkedIn section text. '
                      . 'Return ONLY valid JSON: {"awards":["Award name","..."]}. '
                      . 'No markdown, no explanation.',
            'field'  => 'awards',
            'label'  => 'honors and awards',
        ],
        'languages' => [
            'system' => 'Extract languages from the pasted LinkedIn section text. '
                      . 'Return ONLY valid JSON: {"languages":["English","Spanish","..."]}. '
                      . 'No markdown, no explanation.',
            'field'  => 'languages',
            'label'  => 'languages',
        ],
        'organizations' => [
            'system' => 'Extract organization memberships from the pasted LinkedIn section text. '
                      . 'Return ONLY valid JSON: {"member_of":[{"name":"Org Name","url":""}]}. '
                      . 'No markdown, no explanation.',
            'field'  => 'member_of',
            'label'  => 'organizations',
        ],
    ];

    $cfg     = $prompts[$section];
    $model   = (string) get_option('myls_openai_model', '');
    $chat_fn = function_exists('myls_ai_chat') ? 'myls_ai_chat' : 'myls_openai_chat';

    $result = $chat_fn(
        "=== LINKEDIN {$section} SECTION ===\n{$content}\n=== END ===",
        [
            'model'       => $model,
            'max_tokens'  => 3000,
            'temperature' => 0.1,
            'system'      => $cfg['system'],
        ]
    );

    $result = preg_replace('/^```(?:json)?\s*/i', '', trim($result));
    $result = preg_replace('/\s*```$/i', '', $result);
    $data   = json_decode(trim($result), true);

    if ( ! is_array($data) || json_last_error() !== JSON_ERROR_NONE ) {
        wp_send_json_error([
            'message' => 'AI returned invalid JSON for section "' . $section . '".',
            'raw'     => substr($result, 0, 300),
        ], 500);
    }

    $field = $cfg['field'];
    $items = $data[$field] ?? [];

    if ( empty($items) ) {
        wp_send_json_error(['message' => 'No ' . $cfg['label'] . ' found in the pasted content. Make sure you copied the full section page.']);
    }

    $count = count($items);

    wp_send_json_success([
        'message' => "Found {$count} {$cfg['label']} — merged into card.",
        'fields'  => [ $field => $items ],
        'section' => $section,
    ]);
});


if ( ! function_exists('myls_linkedin_extraction_system_prompt') ) {
    function myls_linkedin_extraction_system_prompt() : string {
        return <<<'SYSPROMPT'
You are a data extraction assistant. You will receive content copied from a LinkedIn profile page.
Parse the provided content carefully and extract ONLY information that actually appears in it.

Return ONLY valid JSON with this exact structure (use empty strings or empty arrays for fields not found):
{
  "name": "Full Name",
  "job_title": "Current Job Title / Headline",
  "honorific_prefix": "Dr., Rev., etc. or empty string",
  "description": "1-3 sentence professional bio from their About/Summary section",
  "url": "Their personal website URL if found in the profile, or empty string",
  "email": "Email if visible, or empty string",
  "phone": "Phone if visible, or empty string",
  "same_as": ["linkedin profile URL", "any other website/profile URLs found"],
  "knows_about": [
    {"name": "Expertise Topic", "wikidata": "", "wikipedia": ""}
  ],
  "credentials": [
    {"name": "Credential/License Name", "abbr": "ABBR", "issuer": "Issuing Org", "issuer_url": ""}
  ],
  "alumni": [
    {"name": "University/School Name", "url": ""}
  ],
  "member_of": [
    {"name": "Organization Name", "url": ""}
  ],
  "awards": ["Award or honor name"],
  "languages": ["Language"]
}

Rules:
- ONLY extract data clearly present in the provided content - do NOT guess or fabricate
- Include the LinkedIn URL in same_as if provided
- Derive knows_about topics from headline, skills, experience, and endorsements
- For knows_about wikidata/wikipedia, only include if you are highly confident of the match
- Parse education entries into alumni
- Parse certifications and licenses into credentials
- Parse volunteer/org memberships into member_of
- Parse honors and awards into awards
- Return ONLY the JSON - no markdown, no code fences, no explanation
SYSPROMPT;
    }
}


/* ====================================================================
 *  Bookmarklet JS  (hidden form POST - no fetch, no CORS)
 * ==================================================================== */
if ( ! function_exists('myls_linkedin_build_bookmarklet_js') ) {
    function myls_linkedin_build_bookmarklet_js( string $ajax_url, string $token ) : string {

        // Use PHP string interpolation for ajax_url and token.
        // Escape all JS backslashes as \\ so heredoc doesn't mangle regex.
        return <<<BOOKMARKLET_JS
(function(){
  if(window.location.hostname.indexOf('linkedin.com')===-1){
    alert('Please run this bookmarklet while viewing a LinkedIn profile page.');
    return;
  }

  /* --- Extract name + job title ----------------------------------------
   * Try selectors first (most reliable when present), fall back to title.
   * LinkedIn title format: "Dave Barry - CEO at Acme | LinkedIn"
   * but headline is sometimes omitted from title so selectors are preferred. */
  function q(sel){var el=document.querySelector(sel);return el?(el.innerText||el.textContent||'').trim():'';}

  var name     = q('h1') || q('.top-card-layout__title') || '';
  var jobTitle = q('.text-body-medium.break-words') || q('.top-card-layout__headline') || '';

  /* Title fallback: strip " | LinkedIn" suffix then split on " - " */
  if(!name){
    var t=(document.title||'').split(' | ')[0].trim();
    var dashIdx=t.indexOf(' - ');
    if(dashIdx>0){ name=t.slice(0,dashIdx); jobTitle=jobTitle||t.slice(dashIdx+3); }
    else { name=t; }
  }

  /* Short description — cap at 300 chars */
  var description=(q('.pv-shared-text-with-see-more')||q('.pv-about__summary-text')||'').slice(0,300);

  /* Raw page title for AI context */
  var pageTitle=(document.title||'').split(' | ')[0].trim();

  var data={
    name:        name,
    job_title:   jobTitle,
    description: description,
    page_title:  pageTitle,
    url:'',email:'',phone:'',honorific_prefix:'',
    same_as:[window.location.href],
    knows_about:[],credentials:[],alumni:[],member_of:[],awards:[],languages:[]
  };

  var payload=JSON.stringify(data);
  var url='$ajax_url'
    +'?action=myls_linkedin_bookmarklet_receive'
    +'&token=$token'
    +'&linkedin_url='+encodeURIComponent(window.location.href)
    +'&payload='+encodeURIComponent(payload);

  console.log('AIntelligize bookmarklet | name:',name,'| job:',jobTitle,'| payload:',payload.length,'chars');

  var win=window.open(url,'_blank');
  if(!win){
    alert('Pop-up was blocked. Please allow pop-ups for linkedin.com, or use the Paste tab in WordPress.');
    return;
  }

  var d=document.createElement('div');
  d.style.cssText='position:fixed;top:20px;right:20px;z-index:99999;padding:14px 20px;border-radius:8px;font:600 14px/1.4 -apple-system,sans-serif;color:#fff;background:#059669;box-shadow:0 4px 16px rgba(0,0,0,.25);';
  d.textContent='Sent to WordPress! Switch back and check.';
  document.body.appendChild(d);
  setTimeout(function(){if(d.parentNode)d.parentNode.removeChild(d);},4000);
})();
BOOKMARKLET_JS;
    }
}
