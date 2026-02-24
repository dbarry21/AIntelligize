<?php
/**
 * MYLS – AJAX: Import Person from LinkedIn (paste-based)
 * File: inc/ajax/ai-person-linkedin.php
 *
 * Endpoint: wp_ajax_myls_person_import_linkedin
 *
 * Strategy:
 *  1. User pastes profile content (plain text or HTML source) from LinkedIn
 *  2. If HTML, extract useful text/meta/JSON-LD before sending to AI
 *  3. Send content to OpenAI for structured JSON extraction
 *  4. Return sanitized profile data
 *
 * @since 4.13.0
 */

if ( ! defined('ABSPATH') ) exit;

add_action('wp_ajax_myls_person_import_linkedin', function () : void {

  // Nonce + capability
  if ( ! function_exists('myls_ai_check_nonce') ) {
    wp_send_json_error(['message' => 'Nonce helper unavailable.'], 500);
  }
  myls_ai_check_nonce('myls_ai_ops');

  if ( ! current_user_can('manage_options') ) {
    wp_send_json_error(['message' => 'Forbidden'], 403);
  }

  // Get pasted content
  $content      = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
  $content_type = sanitize_key($_POST['content_type'] ?? 'text');  // 'text' or 'html'
  $linkedin_url = isset($_POST['linkedin_url']) ? esc_url_raw(wp_unslash(trim($_POST['linkedin_url']))) : '';

  if ( strlen(trim($content)) < 50 ) {
    wp_send_json_error(['message' => 'Content is too short. Please paste the full LinkedIn profile content.'], 400);
  }

  // Ensure AI is available
  if ( ! function_exists('myls_ai_chat') && ! function_exists('myls_openai_chat') ) {
    wp_send_json_error(['message' => 'OpenAI integration not available. Check your API key under Settings.'], 500);
  }

  /* ──────────────────────────────────────────────
   *  Step 1: Process the pasted content
   * ────────────────────────────────────────────── */
  if ( $content_type === 'html' ) {
    $processed = myls_linkedin_extract_from_html($content, $linkedin_url);
  } else {
    // Plain text — clean up whitespace, trim to reasonable size
    $processed = preg_replace('/\s+/', ' ', trim($content));
    $processed = substr($processed, 0, 8000);
    if ( $linkedin_url ) {
      $processed .= "\n\nPROFILE URL: " . $linkedin_url;
    }
  }

  /* ──────────────────────────────────────────────
   *  Step 2: Send to AI
   * ────────────────────────────────────────────── */
  $model = (string) get_option('myls_openai_model', '');

  $system_prompt = <<<'SYSPROMPT'
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
- ONLY extract data clearly present in the provided content — do NOT guess or fabricate
- Include the LinkedIn URL in same_as if provided
- Derive knows_about topics from headline, skills, experience, and endorsements
- For knows_about, if you can confidently match a topic to a Wikidata Q-ID or Wikipedia URL, include it
- Parse education entries into alumni
- Parse certifications and licenses into credentials
- Parse volunteer/org memberships into member_of
- Parse honors and awards into awards
- Return ONLY the JSON — no markdown, no code fences, no explanation
SYSPROMPT;

  $user_prompt = "=== LINKEDIN PROFILE CONTENT ===\n"
    . $processed
    . "\n=== END CONTENT ===\n\n"
    . "Extract all person/professional data from the above into the JSON structure.";

  $chat_fn = function_exists('myls_ai_chat') ? 'myls_ai_chat' : 'myls_openai_chat';
  $result = $chat_fn($user_prompt, [
    'model'       => $model,
    'max_tokens'  => 2000,
    'temperature' => 0.2,
    'system'      => $system_prompt,
  ]);

  if ( $result === '' ) {
    wp_send_json_error(['message' => 'AI returned empty response. Check your OpenAI API key and model settings.'], 500);
  }

  // Strip code fences
  $result = trim($result);
  $result = preg_replace('/^```(?:json)?\s*/i', '', $result);
  $result = preg_replace('/\s*```$/i', '', $result);
  $result = trim($result);

  // Parse JSON
  $data = json_decode($result, true);
  if ( ! is_array($data) || json_last_error() !== JSON_ERROR_NONE ) {
    wp_send_json_error([
      'message' => 'AI returned invalid JSON. Try again.',
      'raw'     => substr($result, 0, 500),
    ], 500);
  }

  /* ──────────────────────────────────────────────
   *  Step 3: Sanitize output
   * ────────────────────────────────────────────── */
  $clean = myls_linkedin_sanitize_profile($data, $linkedin_url);

  wp_send_json_success([
    'message' => 'Profile data extracted successfully.',
    'profile' => $clean,
  ]);
});


/* ====================================================================
 *  Extract useful content from pasted HTML
 * ==================================================================== */
if ( ! function_exists('myls_linkedin_extract_from_html') ) {
  function myls_linkedin_extract_from_html( string $html, string $url ) : string {
    $parts = [];

    // 1) Open Graph tags
    $og_tags = ['og:title', 'og:description', 'og:image'];
    foreach ($og_tags as $tag) {
      if ( preg_match('/<meta[^>]+property=["\']' . preg_quote($tag, '/') . '["\'][^>]+content=["\']([^"\']+)/i', $html, $m) ) {
        $parts[] = strtoupper(str_replace('og:', '', $tag)) . ': ' . html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
      }
    }

    // 2) Meta description
    if ( preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i', $html, $m) ) {
      $parts[] = "META DESCRIPTION: " . html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    // 3) Page title
    if ( preg_match('/<title[^>]*>([^<]+)/i', $html, $m) ) {
      $parts[] = "PAGE TITLE: " . html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    // 4) JSON-LD structured data
    if ( preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches) ) {
      foreach ($matches[1] as $jsonld) {
        $decoded = json_decode(trim($jsonld), true);
        if ( is_array($decoded) ) {
          $type = $decoded['@type'] ?? '';
          if ( in_array($type, ['Person', 'ProfilePage', 'WebPage'], true) || isset($decoded['name']) ) {
            $parts[] = "JSON-LD DATA: " . wp_json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          }
        }
      }
    }

    // 5) Noscript content (LinkedIn's bot-visible profile summary)
    if ( preg_match_all('/<noscript>(.*?)<\/noscript>/si', $html, $noscript) ) {
      foreach ($noscript[1] as $ns) {
        $text = strip_tags($ns);
        $text = preg_replace('/\s+/', ' ', trim($text));
        if ( strlen($text) > 50 && strlen($text) < 15000 ) {
          $parts[] = "NOSCRIPT CONTENT: " . $text;
        }
      }
    }

    // 6) Strip scripts/styles/nav/footer, extract visible text
    $stripped = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
    $stripped = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $stripped);
    $stripped = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $stripped);
    $stripped = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $stripped);
    $text     = strip_tags($stripped);
    $text     = preg_replace('/\s+/', ' ', trim($text));

    if ( strlen($text) > 100 ) {
      $parts[] = "PAGE TEXT: " . substr($text, 0, 8000);
    }

    if ( $url ) {
      $parts[] = "PROFILE URL: " . $url;
    }

    return implode("\n\n", $parts);
  }
}


/* ====================================================================
 *  Sanitize AI response into clean profile array
 * ==================================================================== */
if ( ! function_exists('myls_linkedin_sanitize_profile') ) {
  function myls_linkedin_sanitize_profile( array $data, string $linkedin_url ) : array {
    $clean = [
      'name'             => sanitize_text_field($data['name'] ?? ''),
      'job_title'        => sanitize_text_field($data['job_title'] ?? ''),
      'honorific_prefix' => sanitize_text_field($data['honorific_prefix'] ?? ''),
      'description'      => sanitize_textarea_field($data['description'] ?? ''),
      'url'              => esc_url_raw($data['url'] ?? ''),
      'email'            => sanitize_email($data['email'] ?? ''),
      'phone'            => sanitize_text_field($data['phone'] ?? ''),
      'same_as'          => [],
      'knows_about'      => [],
      'credentials'      => [],
      'alumni'           => [],
      'member_of'        => [],
      'awards'           => [],
      'languages'        => [],
    ];

    // Ensure LinkedIn URL is in sameAs
    $has_li = false;
    if ( ! empty($data['same_as']) && is_array($data['same_as']) ) {
      foreach ( $data['same_as'] as $u ) {
        $u = esc_url_raw(trim($u));
        if ( ! $u ) continue;
        if ( strpos($u, 'linkedin.com') !== false ) $has_li = true;
        $clean['same_as'][] = $u;
      }
    }
    if ( ! $has_li && $linkedin_url ) {
      array_unshift($clean['same_as'], esc_url_raw($linkedin_url));
    }

    // knowsAbout
    if ( ! empty($data['knows_about']) && is_array($data['knows_about']) ) {
      foreach ( $data['knows_about'] as $ka ) {
        if ( ! is_array($ka) ) continue;
        $name = sanitize_text_field($ka['name'] ?? '');
        if ( ! $name ) continue;
        $clean['knows_about'][] = [
          'name'      => $name,
          'wikidata'  => esc_url_raw($ka['wikidata'] ?? ''),
          'wikipedia' => esc_url_raw($ka['wikipedia'] ?? ''),
        ];
      }
    }

    // credentials
    if ( ! empty($data['credentials']) && is_array($data['credentials']) ) {
      foreach ( $data['credentials'] as $cr ) {
        if ( ! is_array($cr) ) continue;
        $name = sanitize_text_field($cr['name'] ?? '');
        if ( ! $name ) continue;
        $clean['credentials'][] = [
          'name'       => $name,
          'abbr'       => sanitize_text_field($cr['abbr'] ?? ''),
          'issuer'     => sanitize_text_field($cr['issuer'] ?? ''),
          'issuer_url' => esc_url_raw($cr['issuer_url'] ?? ''),
        ];
      }
    }

    // alumni
    if ( ! empty($data['alumni']) && is_array($data['alumni']) ) {
      foreach ( $data['alumni'] as $al ) {
        if ( ! is_array($al) ) continue;
        $name = sanitize_text_field($al['name'] ?? '');
        if ( ! $name ) continue;
        $clean['alumni'][] = [
          'name' => $name,
          'url'  => esc_url_raw($al['url'] ?? ''),
        ];
      }
    }

    // memberOf
    if ( ! empty($data['member_of']) && is_array($data['member_of']) ) {
      foreach ( $data['member_of'] as $mo ) {
        if ( ! is_array($mo) ) continue;
        $name = sanitize_text_field($mo['name'] ?? '');
        if ( ! $name ) continue;
        $clean['member_of'][] = [
          'name' => $name,
          'url'  => esc_url_raw($mo['url'] ?? ''),
        ];
      }
    }

    // awards
    if ( ! empty($data['awards']) && is_array($data['awards']) ) {
      foreach ( $data['awards'] as $aw ) {
        $aw = sanitize_text_field(trim($aw));
        if ( $aw ) $clean['awards'][] = $aw;
      }
    }

    // languages
    if ( ! empty($data['languages']) && is_array($data['languages']) ) {
      foreach ( $data['languages'] as $lg ) {
        $lg = sanitize_text_field(trim($lg));
        if ( $lg ) $clean['languages'][] = $lg;
      }
    }

    return $clean;
  }
}
