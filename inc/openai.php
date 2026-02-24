<?php
/**
 * AIntelligize – AI Provider Router
 * Path: inc/openai.php
 *
 * Multi-provider support: OpenAI + Anthropic (Claude)
 * Provider is determined by:
 *   1. The model string (claude-* → Anthropic, gpt-* → OpenAI)
 *   2. The global default provider setting (myls_ai_provider)
 *
 * Provides:
 *  - myls_ai_get_provider()         → 'openai' | 'anthropic'
 *  - myls_ai_get_api_key()          → key for active provider
 *  - myls_openai_get_api_key()      → OpenAI key (backward compat)
 *  - myls_openai_chat()             → OpenAI Chat Completions
 *  - myls_anthropic_chat()          → Anthropic Messages API
 *  - myls_ai_chat()                 → Auto-routes to correct provider
 *  - Filter: myls_ai_complete       → Context-aware completion (FAQs, About, etc.)
 *  - Filter: myls_ai_generate_text  → Legacy meta titles/descriptions
 *
 * @since 6.3.1
 */

if ( ! defined('ABSPATH') ) exit;

/* =========================================================================
 * Provider + Key helpers
 * ========================================================================= */

if ( ! function_exists('myls_ai_get_provider') ) {
	function myls_ai_get_provider() : string {
		$p = strtolower( trim( (string) get_option('myls_ai_provider', 'openai') ) );
		return in_array($p, ['openai', 'anthropic'], true) ? $p : 'openai';
	}
}

if ( ! function_exists('myls_ai_get_default_model') ) {
	function myls_ai_get_default_model() : string {
		$saved = trim( (string) get_option('myls_ai_default_model', '') );
		if ( $saved !== '' ) return $saved;
		return myls_ai_get_provider() === 'anthropic' ? 'claude-sonnet-4-20250514' : 'gpt-4o';
	}
}

if ( ! function_exists('myls_ai_provider_from_model') ) {
	function myls_ai_provider_from_model( string $model ) : string {
		return ( strpos( strtolower( trim($model) ), 'claude' ) === 0 ) ? 'anthropic' : 'openai';
	}
}

if ( ! function_exists('myls_ai_get_api_key') ) {
	function myls_ai_get_api_key( string $provider = '' ) : string {
		if ( $provider === '' ) $provider = myls_ai_get_provider();
		if ( $provider === 'anthropic' ) {
			$key = trim( (string) get_option('myls_anthropic_api_key', '') );
			return (string) apply_filters('myls_anthropic_api_key', $key);
		}
		return myls_openai_get_api_key();
	}
}

/**
 * Check if an AI API key is configured for the active provider.
 * Use this instead of checking myls_openai_api_key directly.
 *
 * @since 6.3.2.3
 * @return bool True if the active provider has an API key configured.
 */
if ( ! function_exists('myls_ai_has_key') ) {
	function myls_ai_has_key() : bool {
		return trim( myls_ai_get_api_key() ) !== '';
	}
}

if ( ! function_exists('myls_openai_get_api_key') ) {
	function myls_openai_get_api_key() : string {
		$keys = [
			get_option('myls_openai_api_key', ''),
			get_option('ssseo_openai_api_key', ''),
			get_option('openai_api_key', ''),
		];
		$key = '';
		foreach ($keys as $k) { if (is_string($k) && $k !== '') { $key = $k; break; } }
		return (string) apply_filters('myls_openai_api_key', $key);
	}
}

if ( ! function_exists('myls_ai_get_models') ) {
	function myls_ai_get_models( string $provider = '' ) : array {
		if ( $provider === '' ) $provider = myls_ai_get_provider();
		if ( $provider === 'anthropic' ) {
			return [
				'claude-sonnet-4-20250514'  => 'Claude Sonnet 4 (Recommended)',
				'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (Fast / Light)',
			];
		}
		return [
			'gpt-4o'      => 'GPT-4o (Recommended)',
			'gpt-4o-mini' => 'GPT-4o Mini (Fast / Light)',
			'gpt-4-turbo' => 'GPT-4 Turbo',
		];
	}
}

/* =========================================================================
 * Timeout calculation — scales with max_tokens so big jobs don't die
 *
 * GPT-4o: ~50-80 tok/sec → 10k tokens ≈ 125-200 sec
 * Claude Sonnet: ~80-120 tok/sec → 10k tokens ≈ 83-125 sec
 * Old hardcoded 45s was killing FAQ generation mid-stream.
 * ========================================================================= */
if ( ! function_exists('myls_ai_calc_timeout') ) {
	function myls_ai_calc_timeout( int $max_tokens, string $provider = 'openai' ) : int {
		$rate = ($provider === 'anthropic') ? 1.0 : 1.5;
		$timeout = 30 + (int) ceil( ($max_tokens / 100) * $rate );
		return max(30, min(300, $timeout));
	}
}

/* =========================================================================
 * OpenAI Chat Completions
 * ========================================================================= */
if ( ! function_exists('myls_openai_chat') ) {
	function myls_openai_chat( string $prompt, array $args = [] ) : string {
		$key = myls_openai_get_api_key();
		if ( $key === '' ) {
			$GLOBALS['myls_ai_last_error'] = 'OpenAI API key missing. Set in plugin settings.';
			error_log('[MYLS] OpenAI key missing.');
			return '';
		}

		$model   = $args['model']       ?? 'gpt-4o';
		$max_tok = isset($args['max_tokens']) ? (int)$args['max_tokens'] : 300;
		$temp    = isset($args['temperature']) ? (float)$args['temperature'] : 0.7;
		$system  = isset($args['system']) && is_string($args['system'])
			? $args['system']
			: 'You are a helpful SEO/content assistant. Write clean, accurate text.';

		$body = [
			'model'    => $model,
			'messages' => [
				['role' => 'system', 'content' => $system],
				['role' => 'user',   'content' => $prompt],
			],
			'temperature' => $temp,
			'max_tokens'  => $max_tok,
		];

		if ( isset($args['presence_penalty']) && is_numeric($args['presence_penalty']) ) {
			$body['presence_penalty'] = (float) $args['presence_penalty'];
		}
		if ( isset($args['frequency_penalty']) && is_numeric($args['frequency_penalty']) ) {
			$body['frequency_penalty'] = (float) $args['frequency_penalty'];
		}

		$timeout = myls_ai_calc_timeout($max_tok, 'openai');

		$resp = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
			'timeout' => $timeout,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $key,
			],
			'body' => wp_json_encode( $body ),
		]);

		if ( is_wp_error($resp) ) {
			$GLOBALS['myls_ai_last_error'] = 'HTTP error: ' . $resp->get_error_message();
			error_log('[MYLS] OpenAI HTTP error: ' . $resp->get_error_message());
			return '';
		}
		$code = wp_remote_retrieve_response_code($resp);
		$json = json_decode( wp_remote_retrieve_body($resp), true );

		if ( $code !== 200 || !is_array($json) ) {
			$err_detail = mb_substr(wp_remote_retrieve_body($resp), 0, 500);
			$GLOBALS['myls_ai_last_error'] = 'HTTP ' . $code . ': ' . $err_detail;
			error_log('[MYLS] OpenAI response ' . $code . ': ' . $err_detail);
			return '';
		}

		$text = $json['choices'][0]['message']['content'] ?? '';
		return is_string($text) ? trim($text) : '';
	}
}

/* =========================================================================
 * Anthropic Messages API
 * ========================================================================= */
if ( ! function_exists('myls_anthropic_chat') ) {
	function myls_anthropic_chat( string $prompt, array $args = [] ) : string {
		$key = trim( (string) get_option('myls_anthropic_api_key', '') );
		$key = (string) apply_filters('myls_anthropic_api_key', $key);

		if ( $key === '' ) {
			$GLOBALS['myls_ai_last_error'] = 'Anthropic API key missing. Set in plugin settings.';
			error_log('[MYLS] Anthropic key missing (myls_anthropic_api_key).');
			return '';
		}

		$model   = $args['model']       ?? 'claude-sonnet-4-20250514';
		$max_tok = isset($args['max_tokens']) ? (int)$args['max_tokens'] : 1024;
		$temp    = isset($args['temperature']) ? (float)$args['temperature'] : 0.7;
		$system  = isset($args['system']) && is_string($args['system'])
			? $args['system']
			: 'You are a helpful SEO/content assistant. Write clean, accurate text.';

		// Anthropic clamps temperature 0.0–1.0
		$temp = max(0.0, min(1.0, $temp));

		$body = [
			'model'      => $model,
			'max_tokens' => $max_tok,
			'temperature'=> $temp,
			'system'     => $system,
			'messages'   => [
				['role' => 'user', 'content' => $prompt],
			],
		];

		$timeout = myls_ai_calc_timeout($max_tok, 'anthropic');

		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'timeout' => $timeout,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			],
			'body' => wp_json_encode( $body ),
		]);

		if ( is_wp_error($resp) ) {
			$GLOBALS['myls_ai_last_error'] = 'HTTP error: ' . $resp->get_error_message();
			error_log('[MYLS] Anthropic HTTP error: ' . $resp->get_error_message());
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$json = json_decode( wp_remote_retrieve_body($resp), true );

		if ( $code !== 200 || ! is_array($json) ) {
			$err_msg = is_array($json) && isset($json['error']['message']) ? $json['error']['message'] : mb_substr(wp_remote_retrieve_body($resp), 0, 500);
			$GLOBALS['myls_ai_last_error'] = 'HTTP ' . $code . ': ' . $err_msg;
			error_log('[MYLS] Anthropic response ' . $code . ': ' . $err_msg);
			return '';
		}

		// Response shape: { content: [ { type: "text", text: "..." } ] }
		$text = '';
		if ( isset($json['content']) && is_array($json['content']) ) {
			foreach ( $json['content'] as $block ) {
				if ( isset($block['type'], $block['text']) && $block['type'] === 'text' ) {
					$text .= $block['text'];
				}
			}
		}

		return trim((string)$text);
	}
}

/* =========================================================================
 * Unified router — picks provider from model string or global setting
 * ========================================================================= */
if ( ! function_exists('myls_ai_chat') ) {
	function myls_ai_chat( string $prompt, array $args = [] ) : string {
		$model    = isset($args['model']) && trim((string)$args['model']) !== '' ? trim((string)$args['model']) : '';
		$provider = ($model !== '') ? myls_ai_provider_from_model($model) : myls_ai_get_provider();

		// Strip empty model so downstream functions use their built-in defaults
		if ( $model === '' ) {
			unset($args['model']);
		}

		// Track last call for debugging/logging
		global $myls_ai_last_call;
		$myls_ai_last_call = [
			'provider'       => $provider,
			'requested_model'=> $model,
		];

		$t_start = microtime(true);

		if ( $provider === 'anthropic' ) {
			$result = myls_anthropic_chat($prompt, $args);
		} else {
			$result = myls_openai_chat($prompt, $args);
		}

		$resolved = $args['model'] ?? ($model !== '' ? $model : ($provider === 'anthropic' ? 'claude-sonnet-4-20250514' : 'gpt-4o'));
		$myls_ai_last_call['resolved_model'] = $resolved;
		$duration_ms = (int) round( ( microtime(true) - $t_start ) * 1000 );

		// ── Auto-log to AI Usage table ──
		if ( class_exists('MYLS_AI_Usage_Logger') ) {
			global $myls_ai_usage_context;
			$ctx = is_array($myls_ai_usage_context) ? $myls_ai_usage_context : [];
			MYLS_AI_Usage_Logger::log([
				'handler'              => $ctx['handler'] ?? 'unknown',
				'model'                => $resolved,
				'provider'             => $provider,
				'post_id'              => $ctx['post_id'] ?? 0,
				'prompt_chars'         => strlen($prompt),
				'output_chars'         => is_string($result) ? strlen($result) : 0,
				'max_tokens_requested' => $args['max_tokens'] ?? 0,
				'temperature'          => $args['temperature'] ?? 0.7,
				'duration_ms'          => $duration_ms,
				'status'               => ( is_string($result) && $result !== '' ) ? 'ok' : 'error',
				'error_message'        => ( is_string($result) && $result !== '' ) ? null : 'Empty response',
				'batch_id'             => $ctx['batch_id'] ?? null,
			]);
		}

		return $result;
	}
}

/** Get info about what the last myls_ai_chat() call actually used */
if ( ! function_exists('myls_ai_last_call') ) {
	function myls_ai_last_call() : array {
		global $myls_ai_last_call;
		return is_array($myls_ai_last_call) ? $myls_ai_last_call : [];
	}
}

/* =========================================================================
 * Context-aware completion (primary hook for ALL AI features)
 * ========================================================================= */
if ( ! function_exists('myls_openai_complete') ) {
	function myls_openai_complete( $out, array $args ) : string {
		if ( is_string($out) && $out !== '' ) return $out;

		$prompt      = isset($args['prompt']) ? (string)$args['prompt'] : '';
		$context     = isset($args['context']) ? (string)$args['context'] : '';
		$model       = isset($args['model']) && trim((string)$args['model']) !== '' ? trim((string)$args['model']) : null;
		$max_tokens  = isset($args['max_tokens']) ? (int)$args['max_tokens'] : null;
		$temperature = isset($args['temperature']) ? (float)$args['temperature'] : 0.7;

		$provider     = $model ? myls_ai_provider_from_model($model) : myls_ai_get_provider();
		$is_anthropic = ($provider === 'anthropic');

		// User's saved default model takes priority over context-based tier defaults
		$saved_default = myls_ai_get_default_model();

		// Model tiers per provider (fallback only if no saved default)
		$heavy = $is_anthropic ? 'claude-sonnet-4-20250514' : 'gpt-4o';
		$light = $is_anthropic ? 'claude-haiku-4-5-20251001' : 'gpt-4o-mini';

		// ── Context defaults ──
		// Priority: explicit model > user's saved default > context tier (heavy/light)
		if ( $context === 'about_the_area' ) {
			$model      = $model ?: $saved_default ?: $heavy;
			$max_tokens = $max_tokens ?: 1600;
			$system     = 'You write clean, structured, sectioned HTML for local area guides. Use HTML tags like <strong>, <em>, <h3>, <p>, <ul>, <li>. NEVER use markdown syntax such as ** or __ for bold, * for italic, or ### for headings. Output raw HTML only, no code fences.';
			$temperature = max($temperature, 0.85);

		} elseif ( $context === 'faqs_generate' ) {
			$model      = $model ?: $saved_default ?: $heavy;
			$max_tokens = $max_tokens ?: 10000;
			$system     = 'You are an expert local SEO copywriter. CRITICAL OUTPUT RULES: Return clean, valid HTML only. Use ONLY these tags: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <a>. NEVER use markdown syntax (no #, no **, no *, no -, no 1., no ```, no code fences). Every FAQ question MUST be in <h3> tags. Every answer paragraph MUST be in <p> tags. Every list MUST use <ul> or <ol> with <li> items. Output will be rejected if it contains markdown.';
			$temperature = max($temperature, 0.85);

		} elseif ( $context === 'geo_rewrite' ) {
			$model      = $model ?: $saved_default ?: $heavy;
			$max_tokens = $max_tokens ?: 4000;
			$system     = 'You are a GEO specialist. Rewrite content for AI answer engine extractability. Return clean HTML only, no markdown.';
			$temperature = max($temperature, 0.7);

		} elseif ( $context === 'html_excerpt' ) {
			$model      = $model ?: $saved_default ?: $heavy;
			$max_tokens = $max_tokens ?: 800;
			$system     = 'You write concise HTML excerpts for local service pages. Use <p>, <strong>, <em> tags only. No markdown.';

		} elseif ( $context === 'tagline' ) {
			$model      = $model ?: $saved_default ?: $light;
			$max_tokens = $max_tokens ?: 200;
			$system     = 'You write short, punchy service taglines. Return plain text only.';

		} elseif ( $context === 'llms_txt_generate' ) {
			$model      = $model ?: $saved_default ?: $heavy;
			$max_tokens = $max_tokens ?: 4000;
			$system     = 'You are an advanced local SEO strategist. Generate clean Markdown text for llms.txt files. Output raw Markdown only — no code fences, no HTML, no explanations before or after. Start directly with the # heading.';
			$temperature = max($temperature, 0.7);

		} else {
			$model      = $model ?: $saved_default ?: $light;
			$max_tokens = $max_tokens ?: 300;
			$system     = 'You are a helpful SEO assistant. Keep responses concise and accurate.';
		}

		$chat_args = [
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'system'      => $system,
		];

		// OpenAI-only penalties (Anthropic doesn't support these)
		if ( ! $is_anthropic ) {
			$pp = isset($args['presence_penalty'])  ? (float)$args['presence_penalty']  : null;
			$fp = isset($args['frequency_penalty']) ? (float)$args['frequency_penalty'] : null;

			if ( in_array($context, ['about_the_area', 'faqs_generate', 'geo_rewrite'], true) ) {
				$pp = $pp ?? 0.9;
				$fp = $fp ?? 0.5;
			}
			if ( $pp !== null ) $chat_args['presence_penalty']  = $pp;
			if ( $fp !== null ) $chat_args['frequency_penalty'] = $fp;
		}

		// Track what model+provider was resolved for logging
		global $myls_ai_last_call;
		$myls_ai_last_call = [
			'provider'        => $provider,
			'requested_model' => $args['model'] ?? '',
			'resolved_model'  => $model,
			'context'         => $context,
		];

		// Set usage logger context (maps context string to handler name)
		global $myls_ai_usage_context;
		$handler_map = [
			'about_the_area'   => 'about_area',
			'faqs_generate'    => 'faqs',
			'geo_rewrite'      => 'geo',
			'html_excerpt'     => 'html_excerpt',
			'tagline'          => 'taglines',
			'llms_txt_generate'=> 'llms_txt',
		];
		if ( ! is_array($myls_ai_usage_context) ) $myls_ai_usage_context = [];
		if ( empty($myls_ai_usage_context['handler']) && $context !== '' ) {
			$myls_ai_usage_context['handler'] = $handler_map[$context] ?? $context;
		}

		return myls_ai_chat($prompt, $chat_args);
	}
	add_filter('myls_ai_complete', 'myls_openai_complete', 10, 2);
}

/* =========================================================================
 * LEGACY: Simple text generator for Meta tab (backward compat)
 * ========================================================================= */
if ( ! function_exists('myls_openai_generate_text') ) {
	function myls_openai_generate_text( $out, string $prompt ) : string {
		if ( is_string($out) && $out !== '' ) return $out;

		$is_title = stripos($prompt, 'Title only') !== false;
		$provider = myls_ai_get_provider();
		$light    = ($provider === 'anthropic') ? 'claude-haiku-4-5-20251001' : 'gpt-4o-mini';

		return myls_ai_chat($prompt, [
			'max_tokens'  => $is_title ? 40 : 80,
			'temperature' => 0.7,
			'model'       => $light,
			'system'      => 'You are an SEO assistant that writes concise, high-quality metadata.',
		]);
	}
	add_filter('myls_ai_generate_text', 'myls_openai_generate_text', 10, 2);
}
