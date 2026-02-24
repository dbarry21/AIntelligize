<?php
/**
 * MYLS Content Quality Analyzer
 * Provides per-item quality metrics for enterprise logging.
 *
 * @since 6.3.0
 * @package MyLocalSEO\AI
 */
if ( ! defined('ABSPATH') ) exit;

class MYLS_Content_Analyzer {

    /**
     * Stock opening phrases that indicate low variation quality.
     */
    private static array $stock_openers = [
        'nestled in',
        'located in',
        'situated in',
        'welcome to',
        'known for',
        'in the heart of',
        'when it comes to',
        'if you\'re looking',
        'as a homeowner',
        'as a resident',
        'here at',
        'at our',
        'our team',
        'we are proud',
        'we\'re proud',
    ];

    /**
     * Analyze HTML content and return quality metrics.
     *
     * @param string $html  The HTML content to analyze.
     * @param array  $opts  Optional context: 'city_state', 'focus_keyword', etc.
     * @return array         Quality metrics array.
     */
    public static function analyze( string $html, array $opts = [] ) : array {
        $plain = wp_strip_all_tags( $html );
        $plain = preg_replace( '/\s+/u', ' ', trim( $plain ) );

        // ── Counts ───────────────────────────────────────────────────
        $words      = $plain !== '' ? count( preg_split( '/\s+/u', $plain ) ) : 0;
        $chars      = mb_strlen( $plain );
        $paragraphs = preg_match_all( '/<p[\s>]/i', $html );
        $h2_count   = preg_match_all( '/<h2[\s>]/i', $html );
        $h3_count   = preg_match_all( '/<h3[\s>]/i', $html );
        $ul_count   = preg_match_all( '/<ul[\s>]/i', $html );
        $li_count   = preg_match_all( '/<li[\s>]/i', $html );
        $link_count = preg_match_all( '/<a[\s]/i', $html );

        // ── Sentences ────────────────────────────────────────────────
        // Split on sentence-ending punctuation followed by space or end
        $sentences = preg_split( '/[.!?]+[\s"\'»)]*(?=[A-Z\d]|$)/u', $plain, -1, PREG_SPLIT_NO_EMPTY );
        $sentence_count = count( $sentences );
        $avg_sentence_len = $sentence_count > 0
            ? round( $words / $sentence_count, 1 )
            : 0;

        // ── First sentence (for uniqueness comparison) ──────────────
        $first_sentence = '';
        if ( ! empty( $sentences[0] ) ) {
            $first_sentence = trim( $sentences[0] );
            if ( mb_strlen( $first_sentence ) > 150 ) {
                $first_sentence = mb_substr( $first_sentence, 0, 150 ) . '…';
            }
        }

        // ── Opening pattern detection ────────────────────────────────
        $opening_match = '(none)';
        $lower_start = mb_strtolower( mb_substr( $plain, 0, 80 ) );
        foreach ( self::$stock_openers as $phrase ) {
            if ( mb_strpos( $lower_start, $phrase ) !== false ) {
                $opening_match = $phrase;
                break;
            }
        }

        // ── Location mention check ───────────────────────────────────
        $location_mentions = 0;
        $city_state = $opts['city_state'] ?? '';
        if ( $city_state !== '' ) {
            $escaped = preg_quote( $city_state, '/' );
            $location_mentions = preg_match_all( '/' . $escaped . '/i', $plain );
            // Also count partial (city only)
            $parts = preg_split( '/[,\s]+/', $city_state );
            if ( count( $parts ) >= 1 && strlen( $parts[0] ) > 2 ) {
                $city_only = preg_match_all( '/' . preg_quote( $parts[0], '/' ) . '/i', $plain );
                $location_mentions = max( $location_mentions, $city_only );
            }
        }

        // ── Focus keyword density ────────────────────────────────────
        $keyword_count = 0;
        $keyword_density = 0.0;
        $focus_keyword = $opts['focus_keyword'] ?? '';
        if ( $focus_keyword !== '' && $words > 0 ) {
            $keyword_count = preg_match_all(
                '/' . preg_quote( $focus_keyword, '/' ) . '/i',
                $plain
            );
            $kw_words = count( preg_split( '/\s+/u', $focus_keyword ) );
            $keyword_density = round( ( $keyword_count * $kw_words / $words ) * 100, 2 );
        }

        // ── Readability grade (simple Flesch-Kincaid approximation) ──
        $syllables = self::count_syllables( $plain );
        $readability_grade = 0.0;
        if ( $sentence_count > 0 && $words > 0 ) {
            $readability_grade = round(
                0.39 * ( $words / $sentence_count )
                + 11.8 * ( $syllables / $words )
                - 15.59,
                1
            );
        }

        return [
            'words'              => $words,
            'chars'              => $chars,
            'paragraphs'         => $paragraphs,
            'h2_count'           => $h2_count,
            'h3_count'           => $h3_count,
            'ul_count'           => $ul_count,
            'li_count'           => $li_count,
            'link_count'         => $link_count,
            'sentences'          => $sentence_count,
            'avg_sentence_len'   => $avg_sentence_len,
            'first_sentence'     => $first_sentence,
            'opening_match'      => $opening_match,
            'location_mentions'  => $location_mentions,
            'keyword_count'      => $keyword_count,
            'keyword_density'    => $keyword_density,
            'readability_grade'  => $readability_grade,
        ];
    }

    /**
     * Rough syllable counter for English text.
     */
    private static function count_syllables( string $text ) : int {
        $words_arr = preg_split( '/\s+/u', strtolower( trim( $text ) ) );
        $total = 0;
        foreach ( $words_arr as $word ) {
            $word = preg_replace( '/[^a-z]/u', '', $word );
            if ( strlen( $word ) <= 3 ) { $total += 1; continue; }
            // Count vowel groups
            $count = preg_match_all( '/[aeiouy]+/i', $word );
            // Subtract silent e
            if ( preg_match( '/e$/i', $word ) ) $count = max( 1, $count - 1 );
            $total += max( 1, $count );
        }
        return $total;
    }

    /**
     * Estimate API cost based on model and token usage.
     * Rough estimates as of early 2025 pricing.
     *
     * @param string $model        Model identifier.
     * @param int    $prompt_chars  Prompt character count.
     * @param int    $output_chars  Output character count.
     * @return array  ['input_tokens', 'output_tokens', 'est_cost_usd']
     */
    public static function estimate_cost( string $model, int $prompt_chars, int $output_chars ) : array {
        // ~4 chars per token (English average)
        $input_tokens  = (int) ceil( $prompt_chars / 4 );
        $output_tokens = (int) ceil( $output_chars / 4 );

        // Pricing per 1M tokens (input / output)
        $pricing = [
            'gpt-4o'         => [ 2.50,  10.00 ],
            'gpt-4o-mini'    => [ 0.15,   0.60 ],
            'gpt-4-turbo'    => [ 10.00, 30.00 ],
            'gpt-4'          => [ 30.00, 60.00 ],
            'gpt-3.5-turbo'  => [ 0.50,   1.50 ],
            'o1-mini'        => [ 3.00,  12.00 ],
        ];

        $rates = $pricing[ strtolower( $model ) ] ?? $pricing['gpt-4o'];

        $cost = ( $input_tokens / 1_000_000 ) * $rates[0]
              + ( $output_tokens / 1_000_000 ) * $rates[1];

        return [
            'input_tokens'  => $input_tokens,
            'output_tokens' => $output_tokens,
            'est_cost_usd'  => round( $cost, 6 ),
        ];
    }
}
