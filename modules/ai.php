<?php
/**
 * AI helpers
 * Path: modules/ai.php
 *
 * Exposes:
 *  - myls_ai_generate_about_area_content()
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Internal: call the best available OpenAI chat helper.
 * Adjust this list if your project uses a different function name.
 */
if ( ! function_exists('myls__call_openai_chat') ) {
  function myls__call_openai_chat( string $prompt, array $args = [] ) {
    if ( function_exists('myls_ai_chat') )    return myls_ai_chat($prompt, $args);
    if ( function_exists('myls_openai_chat') ) return myls_openai_chat($prompt, $args);
    if ( function_exists('ssseo_openai_chat') ) return ssseo_openai_chat($prompt, $args);
    return new WP_Error('no_ai_helper', 'No AI chat helper found.');
  }
}

/**
 * Generate HTML for “About the Area”
 *
 * @return array|WP_Error ['html'=>string,'debug'=>string]
 */
if ( ! function_exists('myls_ai_generate_about_area_content') ) {
  function myls_ai_generate_about_area_content( int $post_id, string $city_state, string $template, int $tokens = 600, float $temperature = 0.7 ) {
    $city_state = trim($city_state);
    if ( $city_state === '' ) return new WP_Error('no_city_state', 'city_state is empty for this post.');

    $prompt = str_replace('{{CITY_STATE}}', $city_state, (string)$template);
    if ( trim($prompt) === '' ) return new WP_Error('empty_prompt', 'Prompt template is empty.');

    $args = [
      'model'       => apply_filters('myls_ai_model', ''),
      'max_tokens'  => max(1, $tokens),
      'temperature' => (float) $temperature,
    ];

    $resp = myls__call_openai_chat($prompt, $args);
    if ( is_wp_error($resp) ) return $resp;

    $html = '';
    if ( is_array($resp) ) {
      if ( ! empty($resp['content']) ) {
        $html = (string) $resp['content'];
      } elseif ( ! empty($resp['choices'][0]['message']['content']) ) {
        $html = (string) $resp['choices'][0]['message']['content'];
      }
    } elseif ( is_string($resp) ) {
      $html = $resp;
    }

    $html = trim($html);
    if ( $html !== '' && stripos($html,'<p') === false && stripos($html,'<h') === false ) {
      $html = '<p>' . esc_html($html) . '</p>';
    }

    return [
      'html'  => $html,
      'debug' => "model={$args['model']} tokens={$args['max_tokens']} temp={$args['temperature']} city_state={$city_state}",
    ];
  }
}
