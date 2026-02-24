<?php
/**
 * AIntelligize — AI Variation Engine
 * Path: inc/ai/variation-engine.php
 *
 * PURPOSE:
 *   Prevents batch AI generation from producing repetitive, templated content.
 *   When generating content for 10–500 service area pages, the AI model tends to
 *   reuse the same opening phrases and sentence structures. This engine provides
 *   three layers of defense against that pattern:
 *
 *   Layer 1 — Opening Angle Rotation:
 *     Each batch item gets a different "opening angle" (e.g., homeowner scenario,
 *     climate, growth, neighborhood character) injected into the prompt so the AI
 *     is mechanically forced to vary its approach.
 *
 *   Layer 2 — Banned Phrase Injection:
 *     A configurable list of overused stock phrases is injected into the prompt
 *     with instructions to avoid them.
 *
 *   Layer 3 — Similarity Guard (Duplicate Detection):
 *     After generation, the output's first 300 characters are compared against
 *     all previous outputs in the current batch. If similarity exceeds 60%,
 *     the engine triggers a rewrite pass with explicit differentiation instructions.
 *
 * USAGE:
 *   // Get the next opening angle for this context
 *   $angle = MYLS_Variation_Engine::next_angle('about_the_area');
 *
 *   // Inject variation rules into prompt text
 *   $prompt = MYLS_Variation_Engine::inject_variation($prompt, $angle);
 *
 *   // After generation, check for similarity and rewrite if needed
 *   $final = MYLS_Variation_Engine::guard_duplicates('about_the_area', $html, $rewrite_callback);
 *
 * SUPPORTED CONTEXTS:
 *   about_the_area, meta_title, meta_description, excerpt, html_excerpt,
 *   faqs_generate, taglines, geo_rewrite, page_builder
 *
 * STORAGE:
 *   Batch memory is stored in WordPress transients with a 1-hour TTL.
 *   Angle counters are stored per-context in a separate transient.
 *
 * @since 6.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MYLS_Variation_Engine {

    /* ─── Runtime Log (per-request) ────────────────────────────────────
     *
     * Captures detailed info about the last operation for each layer.
     * AJAX handlers read this after calling the engine methods to include
     * the data in their JSON responses for frontend logging.
     * ─────────────────────────────────────────────────────────────────── */

    private static $log = [
        'angle'              => null,
        'angle_index'        => null,
        'angle_pool_size'    => null,
        'banned_count'       => 0,
        'banned_phrases'     => [],
        'dedup_checked'      => false,
        'dedup_max_sim'      => 0.0,
        'dedup_match_index'  => null,
        'dedup_batch_size'   => 0,
        'dedup_rewrite'      => false,
    ];

    /**
     * Get the accumulated log data for the current request.
     * Call after all engine methods to include in the AJAX response.
     *
     * @return array
     */
    public static function get_log() : array {
        return self::$log;
    }

    /**
     * Reset the per-request log (call at start of each AJAX handler).
     */
    public static function reset_log() : void {
        self::$log = [
            'angle'              => null,
            'angle_index'        => null,
            'angle_pool_size'    => null,
            'banned_count'       => 0,
            'banned_phrases'     => [],
            'dedup_checked'      => false,
            'dedup_max_sim'      => 0.0,
            'dedup_match_index'  => null,
            'dedup_batch_size'   => 0,
            'dedup_rewrite'      => false,
        ];
    }

    /* ─── Opening Angle Pools ──────────────────────────────────────────
     *
     * Each context has its own pool of "angles" that rotate sequentially.
     * The AI prompt is told which angle to use, forcing structural variation.
     *
     * For About the Area, angles control the opening paragraph's approach.
     * For Meta Titles, angles control the title's framing strategy.
     * For FAQs, angles control question perspective diversity.
     * ─────────────────────────────────────────────────────────────────── */

    private static $angle_pools = [

        // About the Area: controls opening paragraph strategy
        'about_the_area' => [
            'homeowner-scenario',   // "When your fence starts to lean..."
            'climate-environment',  // "Florida's subtropical climate..."
            'growth-development',   // "With new subdivisions going up..."
            'neighborhood-character', // "Each neighborhood here has its own feel..."
            'infrastructure-access',  // "Just off I-75 and minutes from..."
            'lifestyle-property',     // "Whether you're on a half-acre lot..."
            'seasonal-weather',       // "After hurricane season..."
            'cost-roi',               // "Property values in this area..."
        ],

        // Meta Titles: structural patterns from prompt template (A–F)
        'meta_title' => [
            'service-location-brand',   // A) "Vinyl Fence Installation in Tampa FL | BrandName"
            'benefit-led',              // B) "Durable Wood Fencing Built for Florida Weather | BrandName"
            'qualifier-service',        // C) "Licensed Fence Repair Contractors Serving Lutz FL"
            'problem-solution',         // D) "Storm-Damaged Fence? Same-Day Repair in Wesley Chapel FL"
            'specific-detail',          // E) "6-Foot Privacy Fence Installation in Pasco County"
            'location-led',             // F) "Tampa Bay's Full-Service Fence Contractor Since 2005"
        ],

        // Meta Descriptions: opening approaches from prompt template (A–F)
        'meta_description' => [
            'service-forward',          // A) "Custom vinyl fence installation designed for..."
            'location-forward',         // B) "Serving Tampa Bay homeowners with..."
            'benefit-forward',          // C) "Get a fence that handles Florida storms..."
            'problem-forward',          // D) "Tired of a sagging fence? We replace and repair..."
            'social-proof-forward',     // E) "Trusted by 500+ Pasco County homeowners for..."
            'specificity-forward',      // F) "4-, 5-, and 6-foot privacy fence options..."
        ],

        // Excerpts: structural pattern rotation (matches prompt patterns A–F)
        'excerpt' => [
            'service-forward',     // A: Open with the specific service name
            'audience-forward',    // B: Open with who this page helps
            'location-forward',    // C: Open with the city/area name
            'benefit-forward',     // D: Open with the concrete outcome
            'problem-forward',     // E: Open with the customer problem
            'credential-forward',  // F: Open with a trust signal
        ],

        // HTML Excerpts: structural pattern rotation (matches prompt patterns A–F)
        'html_excerpt' => [
            'service-benefit',     // A: Open with service + primary benefit
            'location-service',    // B: Open with area name + service
            'problem-solution',    // C: Open with pain point + fix
            'credential-trust',    // D: Open with trust signal + service
            'outcome-first',       // E: Open with end result
            'specificity-lead',    // F: Open with specific detail
        ],

        // FAQ Builder: controls question diversity angles
        'faqs_generate' => [
            'cost',          // "How much does..."
            'timing',        // "When is the best time to..."
            'materials',     // "What materials are..."
            'maintenance',   // "How do I maintain..."
            'permits-hoa',   // "Do I need a permit..."
            'lifespan',      // "How long does..."
            'weather',       // "How does Florida weather affect..."
            'resale-value',  // "Does {service} increase..."
        ],

        // Taglines: controls tone/voice of tagline
        'taglines' => [
            'bold-confident',
            'premium-quality',
            'neighborhood-friendly',
            'practical-no-nonsense',
            'technical-expertise',
            'warm-approachable',
        ],
    ];

    /* ─── Banned Phrase Lists ──────────────────────────────────────────
     *
     * Phrases the AI model gravitates to when given similar prompts.
     * These are injected into the prompt as "do not use" instructions.
     * Each context can have its own banned list.
     * ─────────────────────────────────────────────────────────────────── */

    private static $banned_phrases = [

        'about_the_area' => [
            'Nestled in the heart of',
            'Located in the heart of',
            'Welcome to',
            'Situated in',
            'Known for its',
            'In the heart of',
            'vibrant community',
            'charming community',
            'thriving community',
            'Whether you are',
        ],

        'meta_title' => [
            'Your Trusted',
            'Top Rated',
            'Best',
            '#1',
            'Premier',
            'Quality',
            'Professional',
            'Expert',
            'Reliable',
            'Affordable',
            'Top',
        ],

        'meta_description' => [
            'Look no further',
            'We are your go-to',
            'We pride ourselves',
            'second to none',
            'Whether you need',
            'From X to Y, we do it all',
            'Our team of experts',
            'We are committed to',
            'high-quality',
            'top-notch',
            'state-of-the-art',
            'world-class',
            'cutting-edge',
        ],

        'excerpt' => [
            'Whether you need',
            'Look no further',
            'Our team of experts',
            'We pride ourselves',
            'second to none',
            'your go-to',
            'high-quality',
            'top-notch',
            'state-of-the-art',
            'comprehensive solutions',
            'all your needs',
            'trusted professionals',
            'dedicated to providing',
            'committed to excellence',
            'premier',
            'unmatched',
        ],

        'html_excerpt' => [
            'Whether you need',
            'Look no further',
            'Our team of experts',
            'We pride ourselves',
            'second to none',
            'your go-to',
            'high-quality',
            'top-notch',
            'state-of-the-art',
            'comprehensive solutions',
            'all your needs',
            'trusted professionals',
            'dedicated to providing',
            'committed to excellence',
            'premier',
            'unmatched',
            'Don\'t hesitate',
            'Contact us today',
            'Call now',
        ],

        'taglines' => [
            'Quality you can trust',
            'Serving with pride',
            'Your local experts',
            'Excellence in every',
            'Where quality meets',
        ],
    ];

    /* ─── Similarity Threshold ─────────────────────────────────────────
     *
     * If a new output's first 300 chars are more than this % similar to
     * any previous output in the batch, trigger a rewrite.
     * ─────────────────────────────────────────────────────────────────── */

    const SIMILARITY_THRESHOLD = 60;

    /* ─── Public API ───────────────────────────────────────────────────
     *
     * These are the three methods that AJAX handlers call.
     * ─────────────────────────────────────────────────────────────────── */

    /**
     * Get the next opening angle for a given context.
     *
     * Rotates through the angle pool sequentially using a transient counter.
     * Each batch call increments the counter, so consecutive generations
     * in the same batch get different angles automatically.
     *
     * @param  string $context  The generation context (e.g., 'about_the_area').
     * @return string           The angle string to inject into the prompt.
     */
    public static function next_angle( string $context ) : string {
        $pool = self::$angle_pools[ $context ] ?? self::$angle_pools['about_the_area'];
        $pool_size = count( $pool );

        // Read and increment the counter
        $transient_key = 'myls_ve_angle_' . $context;
        $index = (int) get_transient( $transient_key );
        set_transient( $transient_key, $index + 1, HOUR_IN_SECONDS );

        $angle = $pool[ $index % $pool_size ];

        // Log
        self::$log['angle']           = $angle;
        self::$log['angle_index']     = ( $index % $pool_size ) + 1;
        self::$log['angle_pool_size'] = $pool_size;

        return $angle;
    }

    /**
     * Reset the angle counter for a context.
     * Call this at the start of a new batch if desired.
     *
     * @param string $context  The generation context.
     */
    public static function reset_angle( string $context ) : void {
        delete_transient( 'myls_ve_angle_' . $context );
    }

    /**
     * Inject variation rules into a prompt string.
     *
     * Appends:
     *   1. The opening angle instruction
     *   2. Banned phrase avoidance list
     *   3. General variation requirements
     *
     * @param  string $prompt  The original prompt text.
     * @param  string $angle   The angle from next_angle().
     * @param  string $context Optional context for context-specific banned phrases.
     * @return string          The prompt with variation rules appended.
     */
    public static function inject_variation( string $prompt, string $angle, string $context = '' ) : string {

        $is_short_form  = in_array( $context, ['meta_title', 'meta_description', 'taglines'], true );
        $is_medium_form = in_array( $context, ['excerpt', 'html_excerpt'], true );

        // Build the variation block
        $block = "\n\n";
        $block .= "VARIATION REQUIREMENTS (MANDATORY):\n";
        $block .= "- Structural Pattern: {$angle}\n";

        if ( $is_short_form ) {
            // Short-form rules (titles, descriptions, taglines)
            $block .= "- You MUST follow the Structural Pattern above for this output.\n";
            $block .= "- Use a different opening word than any previous output in this batch.\n";
            $block .= "- Use specific, concrete language — not generic filler.\n";
        } elseif ( $is_medium_form ) {
            // Medium-form rules (excerpts — 1-4 sentences)
            $block .= "- You MUST follow the Structural Pattern above for this output.\n";
            $block .= "- Open with a DIFFERENT word than any other excerpt in this batch.\n";
            $block .= "- Use specific, concrete language — name the actual service and location.\n";
            $block .= "- Vary sentence count across the batch (not all the same length).\n";
        } else {
            // Long-form rules (about areas, page builder, etc.)
            $block .= "- The first paragraph MUST follow the Structural Pattern above.\n";
            $block .= "- Do NOT repeat sentence structure patterns across paragraphs.\n";
            $block .= "- Vary paragraph length and rhythm.\n";
            $block .= "- Use specific local references instead of generic descriptors.\n";
        }

        // Context-specific banned phrases
        $banned = self::$banned_phrases[ $context ] ?? [];
        if ( ! empty( $banned ) ) {
            $block .= "- Do NOT begin with or use any of these phrases:\n";
            foreach ( $banned as $phrase ) {
                $block .= "  \"" . $phrase . "\"\n";
            }
        }

        // General anti-duplication rules (tailored by form length)
        if ( $is_short_form ) {
            $block .= "- Do not use the same adjective-noun or verb-noun pairing as other outputs in this batch.\n";
        } elseif ( $is_medium_form ) {
            $block .= "- Do not reuse the same verb-noun or adjective-noun pairing from other items in this batch.\n";
            $block .= "- Avoid generic adjectives: vibrant, beautiful, exceptional, outstanding, reliable.\n";
        } else {
            $block .= "- Avoid generic adjectives: vibrant, charming, thriving, beautiful, bustling.\n";
            $block .= "- Avoid repeating any 4+ word phrase that commonly appears in city descriptions.\n";
        }

        // Log
        self::$log['banned_count']   = count( $banned );
        self::$log['banned_phrases'] = $banned;

        return $prompt . $block;
    }

    /**
     * Check a new output against previous outputs in this batch.
     * If similarity exceeds threshold, call $rewrite_fn to regenerate.
     *
     * The rewrite callback receives the original text and should return
     * a rewritten version (typically by calling the AI with a rewrite prompt).
     *
     * @param  string   $context      The generation context.
     * @param  string   $new_content  The freshly generated content.
     * @param  callable $rewrite_fn   function( string $original ) : string
     * @return string                 The final content (original or rewritten).
     */
    public static function guard_duplicates( string $context, string $new_content, callable $rewrite_fn ) : string {

        $transient_key   = 'myls_ve_batch_' . $context;
        $previous_outputs = get_transient( $transient_key );
        if ( ! is_array( $previous_outputs ) ) {
            $previous_outputs = [];
        }

        self::$log['dedup_checked']    = true;
        self::$log['dedup_batch_size'] = count( $previous_outputs );

        // Compare first 300 chars (stripped of HTML) against all previous outputs
        $new_plain = mb_substr( wp_strip_all_tags( $new_content ), 0, 300 );
        $needs_rewrite = false;
        $max_sim = 0.0;
        $max_sim_index = null;

        foreach ( $previous_outputs as $idx => $prev_plain ) {
            similar_text( $new_plain, $prev_plain, $percent );
            if ( $percent > $max_sim ) {
                $max_sim = $percent;
                $max_sim_index = $idx;
            }
            if ( $percent > self::SIMILARITY_THRESHOLD ) {
                $needs_rewrite = true;
                error_log( sprintf(
                    '[MYLS Variation] Similarity %.1f%% detected in %s batch — triggering rewrite.',
                    $percent, $context
                ) );
                break;
            }
        }

        self::$log['dedup_max_sim']     = round( $max_sim, 1 );
        self::$log['dedup_match_index'] = $max_sim_index;

        // If too similar, ask for a rewrite
        if ( $needs_rewrite ) {
            self::$log['dedup_rewrite'] = true;
            $rewritten = $rewrite_fn( $new_content );
            if ( is_string( $rewritten ) && trim( $rewritten ) !== '' ) {
                $new_content = $rewritten;
                $new_plain   = mb_substr( wp_strip_all_tags( $new_content ), 0, 300 );
            }
        }

        // Store this output's fingerprint for future comparison
        $previous_outputs[] = $new_plain;

        // Keep only last 50 outputs per context to avoid bloat
        if ( count( $previous_outputs ) > 50 ) {
            $previous_outputs = array_slice( $previous_outputs, -50 );
        }

        set_transient( $transient_key, $previous_outputs, HOUR_IN_SECONDS );

        return $new_content;
    }

    /**
     * Clear all batch memory for a context.
     * Useful when starting a completely new batch run.
     *
     * @param string $context  The generation context.
     */
    public static function clear_batch( string $context ) : void {
        delete_transient( 'myls_ve_batch_' . $context );
        delete_transient( 'myls_ve_angle_' . $context );
    }

    /**
     * Get all available angles for a context (for debugging or UI display).
     *
     * @param  string $context
     * @return array
     */
    public static function get_angles( string $context ) : array {
        return self::$angle_pools[ $context ] ?? [];
    }

    /**
     * Get banned phrases for a context.
     *
     * @param  string $context
     * @return array
     */
    public static function get_banned_phrases( string $context ) : array {
        return self::$banned_phrases[ $context ] ?? [];
    }

    /**
     * Build a standard log array for JSON responses.
     * Call after all engine methods in an AJAX handler.
     *
     * @param  float  $start_time  microtime(true) from handler start.
     * @param  array  $extra       Additional key-value pairs to merge.
     * @return array               Standard log data for the 'log' key in JSON response.
     */
    public static function build_item_log( float $start_time = 0, array $extra = [] ) : array {
        $ve = self::get_log();

        $base = [
            'request_id'      => substr( wp_generate_uuid4(), 0, 8 ),
            'timestamp'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'elapsed_ms'      => $start_time > 0 ? round( ( microtime(true) - $start_time ) * 1000 ) : null,
            'model'           => $extra['model'] ?? 'default',
            'tokens'          => $extra['tokens'] ?? null,
            'temperature'     => $extra['temperature'] ?? null,
            'prompt_chars'    => $extra['prompt_chars'] ?? null,
            'output_words'    => $extra['output_words'] ?? null,
            'output_chars'    => $extra['output_chars'] ?? null,
            'page_title'      => $extra['page_title'] ?? '',
            // Variation engine
            'angle'           => $ve['angle'],
            'angle_position'  => $ve['angle_index'],
            'angle_pool_size' => $ve['angle_pool_size'],
            'banned_count'    => $ve['banned_count'],
            'dedup_checked'   => $ve['dedup_checked'],
            'dedup_max_sim'   => $ve['dedup_max_sim'],
            'dedup_batch_pos' => $ve['dedup_batch_size'] + 1,
            'dedup_rewrite'   => $ve['dedup_rewrite'],
        ];

        // ── Content quality analysis ─────────────────────────────────
        if ( class_exists('MYLS_Content_Analyzer') && ! empty( $extra['_html'] ) ) {
            $qa = MYLS_Content_Analyzer::analyze( $extra['_html'], [
                'city_state'    => $extra['city_state'] ?? '',
                'focus_keyword' => $extra['focus_keyword'] ?? '',
            ] );
            $base['quality'] = $qa;
        }

        // ── Cost estimation ──────────────────────────────────────────
        if ( class_exists('MYLS_Content_Analyzer')
             && ! empty( $base['model'] )
             && ! empty( $base['prompt_chars'] )
             && ! empty( $base['output_chars'] ) ) {
            $base['cost'] = MYLS_Content_Analyzer::estimate_cost(
                (string) $base['model'],
                (int) $base['prompt_chars'],
                (int) $base['output_chars']
            );
        }

        // Merge extra fields (except private _html key)
        $public_extra = array_diff_key( $extra, array_flip([
            '_html', 'model', 'tokens', 'temperature', 'prompt_chars',
            'output_words', 'output_chars', 'page_title', 'city_state', 'focus_keyword',
        ]) );

        return array_merge( $base, $public_extra );
    }
}
