<?php
/**
 * OpenAI calls (Responses + Images).
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_get_current_provider_key')) {
	function cbia_get_current_provider_key(): string {
		if (function_exists('cbia_providers_get_settings') && function_exists('cbia_providers_get_current_provider')) {
			$provider_settings = cbia_providers_get_settings();
			$current_provider = cbia_providers_get_current_provider($provider_settings);
			return $current_provider ?: 'openai';
		}
		return 'openai';
	}
}

if (!function_exists('cbia_get_provider_config')) {
	function cbia_get_provider_config(string $provider): array {
		if (function_exists('cbia_providers_get_provider')) {
			return cbia_providers_get_provider($provider);
		}
		return [];
	}
}

if (!function_exists('cbia_get_provider_model')) {
	function cbia_get_provider_model(string $provider, string $fallback = ''): string {
		$cfg = cbia_get_provider_config($provider);
		$model = isset($cfg['model']) ? (string)$cfg['model'] : '';
		return $model !== '' ? $model : $fallback;
	}
}

if (!function_exists('cbia_google_generate_content_call')) {
	/**
	 * Google Gemini generateContent (REST).
	 * Returns [ok, text, usage, model, err, raw]
	 */
	function cbia_google_generate_content_call($prompt, $system = '', $tries = 2) {
		$cfg = cbia_get_provider_config('google');
		$api_key = (string)($cfg['api_key'] ?? '');
		if ($api_key === '') {
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No hay API key (Google)', []];
		}

		$model = cbia_get_provider_model('google', 'gemini-1.5-flash-latest');
		$base_url = rtrim((string)($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com'), '/');
		$api_version = trim((string)($cfg['api_version'] ?? 'v1beta'), '/');

		$max_out = (int)(cbia_get_settings()['responses_max_output_tokens'] ?? 6000);
		if ($max_out < 256) $max_out = 256;
		if ($max_out > 12000) $max_out = 12000;

		$url = $base_url . '/' . $api_version . '/models/' . rawurlencode($model) . ':generateContent';

		$payload = [
			'contents' => [
				[
					'role' => 'user',
					'parts' => [
						['text' => (string)$prompt],
					],
				],
			],
			'generationConfig' => [
				'maxOutputTokens' => $max_out,
			],
		];
		if ($system !== '') {
			$payload['system_instruction'] = [
				'parts' => [
					['text' => (string)$system],
				],
			];
		}

		for ($t = 1; $t <= max(1, (int)$tries); $t++) {
			if (cbia_is_stop_requested()) {
				return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'STOP activado', []];
			}

			cbia_log(__("Google Gemini: modelo={$model} intento {$t}/{$tries}","ai-blog-builder-pro"), 'INFO');

			$resp = wp_remote_post($url, [
				'headers' => [
					'Content-Type' => 'application/json',
					'x-goog-api-key' => $api_key,
				],
				'body'    => wp_json_encode($payload),
				'timeout' => 60,
			]);

			if (is_wp_error($resp)) {
				cbia_log(__("Google Gemini HTTP error: ","ai-blog-builder-pro") . $resp->get_error_message(), 'ERROR');
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($resp);
			$body = (string) wp_remote_retrieve_body($resp);
			$data = json_decode($body, true);

			if ($code < 200 || $code >= 300) {
				$msg = '';
				if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
				$err = "HTTP {$code}" . ($msg ? " | {$msg}" : '');
				cbia_log(__("Google Gemini error: {$err}","ai-blog-builder-pro"), 'ERROR');
				continue;
			}

			if (!is_array($data)) {
				cbia_log(__("Google Gemini: respuesta invalida","ai-blog-builder-pro"), 'ERROR');
				continue;
			}

			$text = '';
			if (!empty($data['candidates'][0]['content']['parts']) && is_array($data['candidates'][0]['content']['parts'])) {
				foreach ($data['candidates'][0]['content']['parts'] as $p) {
					if (is_array($p) && isset($p['text'])) {
						$text .= (string)$p['text'];
					}
				}
			}

			if ($text === '') {
				cbia_log(__("Google Gemini: respuesta sin texto (modelo={$model})","ai-blog-builder-pro"), 'ERROR');
				continue;
			}

			$usage = ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];
			if (!empty($data['usageMetadata'])) {
				$usage['input_tokens'] = (int)($data['usageMetadata']['promptTokenCount'] ?? 0);
				$usage['output_tokens'] = (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0);
				$usage['total_tokens'] = (int)($data['usageMetadata']['totalTokenCount'] ?? 0);
			}

			cbia_log(__("Google Gemini OK: modelo={$model} tokens_in=","ai-blog-builder-pro") . (int)$usage['input_tokens'] . " tokens_out=" . (int)$usage['output_tokens'], 'INFO');
			return [true, $text, $usage, $model, '', $data];
		}

		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'No se pudo obtener respuesta', []];
	}
}

if (!function_exists('cbia_deepseek_chat_call')) {
	/**
	 * DeepSeek chat completions (OpenAI-compatible).
	 * Returns [ok, text, usage, model, err, raw]
	 */
	function cbia_deepseek_chat_call($prompt, $system = '', $tries = 2) {
		$cfg = cbia_get_provider_config('deepseek');
		$api_key = (string)($cfg['api_key'] ?? '');
		if ($api_key === '') {
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No hay API key (DeepSeek)', []];
		}

		$model = cbia_get_provider_model('deepseek', 'deepseek-chat');
		$base_url = rtrim((string)($cfg['base_url'] ?? 'https://api.deepseek.com'), '/');
		$api_version = trim((string)($cfg['api_version'] ?? 'v1'), '/');
		$path = $api_version !== '' ? '/' . $api_version . '/chat/completions' : '/chat/completions';
		$url = $base_url . $path;

		$max_out = (int)(cbia_get_settings()['responses_max_output_tokens'] ?? 6000);
		if ($max_out < 256) $max_out = 256;
		if ($max_out > 12000) $max_out = 12000;

		$messages = [];
		if ($system !== '') {
			$messages[] = ['role' => 'system', 'content' => (string)$system];
		}
		$messages[] = ['role' => 'user', 'content' => (string)$prompt];

		$payload = [
			'model' => $model,
			'messages' => $messages,
			'stream' => false,
			'max_tokens' => $max_out,
			'temperature' => (float)(cbia_get_settings()['openai_temperature'] ?? 0.7),
		];

		for ($t = 1; $t <= max(1, (int)$tries); $t++) {
			if (cbia_is_stop_requested()) {
				return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'STOP activado', []];
			}

			cbia_log(__("DeepSeek: modelo={$model} intento {$t}/{$tries}","ai-blog-builder-pro"), 'INFO');

			$resp = wp_remote_post($url, [
				'headers' => [
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => wp_json_encode($payload),
				'timeout' => 60,
			]);

			if (is_wp_error($resp)) {
				cbia_log(__("DeepSeek HTTP error: ","ai-blog-builder-pro") . $resp->get_error_message(), 'ERROR');
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($resp);
			$body = (string) wp_remote_retrieve_body($resp);
			$data = json_decode($body, true);

			if ($code < 200 || $code >= 300) {
				$msg = '';
				if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
				$err = "HTTP {$code}" . ($msg ? " | {$msg}" : '');
				cbia_log(__("DeepSeek error: {$err}","ai-blog-builder-pro"), 'ERROR');
				continue;
			}

			if (!is_array($data) || empty($data['choices'][0]['message']['content'])) {
				cbia_log(__("DeepSeek: respuesta sin texto (modelo={$model})","ai-blog-builder-pro"), 'ERROR');
				continue;
			}

			$text = (string)$data['choices'][0]['message']['content'];
			$usage = ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];
			if (!empty($data['usage'])) {
				$usage['input_tokens'] = (int)($data['usage']['prompt_tokens'] ?? 0);
				$usage['output_tokens'] = (int)($data['usage']['completion_tokens'] ?? 0);
				$usage['total_tokens'] = (int)($data['usage']['total_tokens'] ?? 0);
			}

			cbia_log(__("DeepSeek OK: modelo={$model} tokens_in=","ai-blog-builder-pro") . (int)$usage['input_tokens'] . " tokens_out=" . (int)$usage['output_tokens'], 'INFO');
			return [true, $text, $usage, $model, '', $data];
		}

		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'No se pudo obtener respuesta', []];
	}
}

/* =========================================================
   =============== OPENAI: RESPONSES CALL (6) ===============
   ========================================================= */

if (!function_exists('cbia_openai_responses_call')) {
	/**
	 * Devuelve 6 valores:
	 * [ok(bool), text(string), usage(array), model_used(string), err(string), raw(array|string)]
	 */
		function cbia_openai_responses_call($prompt, $title_for_log = '', $tries = 2) {
			cbia_try_unlimited_runtime();
			$provider = cbia_get_current_provider_key();
		if (!cbia_openai_consent_ok()) {
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'Consentimiento OpenAI no aceptado', []];
		}

		$s = cbia_get_settings();
		$model_preferred = cbia_pick_model();
		$chain = cbia_model_fallback_chain($model_preferred);
		$blocked = $s['blocked_models'] ?? [];
		if (!is_array($blocked)) $blocked = [];

		$system = "Eres un redactor editorial. Devuelve HTML simple con <h2>, <h3>, <p>, <ul>, <li>. NO uses <h1> ni envolturas <html>/<head>/<body>. No uses <table>, <iframe> ni <blockquote>.";
		$input = [
			['role' => 'system', 'content' => $system],
			['role' => 'user', 'content' => (string)$prompt],
		];

		if ($provider === 'google') {
			return cbia_google_generate_content_call($prompt, $system, $tries);
		}
		if ($provider === 'deepseek') {
			return cbia_deepseek_chat_call($prompt, $system, $tries);
		}
		$api_key = cbia_openai_api_key();
		if (!$api_key) {
			return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No hay API key', []];
		}

		foreach ($chain as $model) {
			if (!empty($blocked[$model])) continue;
			if (!cbia_is_responses_model($model)) continue;

			for ($t = 1; $t <= max(1, (int)$tries); $t++) {
				if (cbia_is_stop_requested()) {
					return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], $model, 'STOP activado', []];
				}

				cbia_log(__("OpenAI Responses: modelo={$model} intento {$t}/{$tries} ","ai-blog-builder-pro") . ($title_for_log ? "| '{$title_for_log}'" : ''), 'INFO');

				$max_out = (int)($s['responses_max_output_tokens'] ?? 6000);
				if ($max_out < 1500) $max_out = 1500;
				if ($max_out > 12000) $max_out = 12000;

				$payload = [
					'model' => $model,
					'input' => $input,
					// Max output prudente (luego el prompt manda)
					'max_output_tokens' => $max_out,
				];

				$resp = wp_remote_post('https://api.openai.com/v1/responses', [
					'headers' => cbia_http_headers_openai($api_key),
					'body'    => wp_json_encode($payload),
					'timeout' => 60,
				]);

				if (is_wp_error($resp)) {
					$err = $resp->get_error_message();
					cbia_log(__("HTTP error: {$err}","ai-blog-builder-pro"), 'ERROR');
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$data = json_decode($body, true);

				if ($code < 200 || $code >= 300) {
					$msg = '';
					if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
					$err = "HTTP {$code}" . ($msg ? " | {$msg}" : '');
					cbia_log(__("OpenAI error: {$err}","ai-blog-builder-pro"), 'ERROR');
					continue;
				}

				if (is_array($data) && !empty($data['error']['message'])) {
					$err = (string)$data['error']['message'];
					cbia_log(__("OpenAI error payload: {$err}","ai-blog-builder-pro"), 'ERROR');
					continue;
				}

				$text = cbia_extract_text_from_responses_payload($data);
				$usage = cbia_usage_from_responses_payload($data);

				if ($text === '') {
					cbia_log(__("Respuesta sin texto (modelo={$model})","ai-blog-builder-pro"), 'ERROR');
					continue;
				}

				cbia_log(__("OpenAI Responses OK: modelo={$model} tokens_in=","ai-blog-builder-pro") . (int)($usage['input_tokens'] ?? 0) . " tokens_out=" . (int)($usage['output_tokens'] ?? 0), 'INFO');

				return [true, $text, $usage, $model, '', $data];
			}
		}

		return [false, '', ['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0], '', 'No se pudo obtener respuesta', []];
	}
}

/* =========================================================
   ================== OPENAI: IMÁGENES ======================
   ========================================================= */

if (!function_exists('cbia_generate_image_openai')) {
	/**
	 * Retorna [ok(bool), attach_id(int), model_used(string), err(string)]
	 */
		function cbia_generate_image_openai($desc, $section, $title) {
			cbia_try_unlimited_runtime();
			// PRO: provider selector (fallback to OpenAI if different provider selected)
			if (function_exists('cbia_providers_get_settings') && function_exists('cbia_providers_get_current_provider')) {
				$provider_settings = cbia_providers_get_settings();
				$current_provider = cbia_providers_get_current_provider($provider_settings);
				if ($current_provider !== 'openai') {
					cbia_log(sprintf(__('Provider activo "%s" aun no soportado para imagenes. Usando OpenAI como fallback.', 'ai-blog-builder-pro'), (string)$current_provider), 'WARN');
				}
			}
			$api_key = cbia_openai_api_key();
			if (!$api_key) return [false, 0, '', 'No hay API key'];
		if (!cbia_openai_consent_ok()) return [false, 0, '', 'Consentimiento OpenAI no aceptado'];

		if (cbia_is_stop_requested()) return [false, 0, '', 'STOP activado'];

		$s = cbia_get_settings();
		$blocked = $s['blocked_models'] ?? [];
		if (!is_array($blocked)) $blocked = [];

		$prompt = cbia_build_image_prompt($desc, $section, $title);
		$size = cbia_image_size_for_section($section);
		$alt  = cbia_build_img_alt($title, $section, $desc);
		$section_label = function_exists('cbia_section_label') ? cbia_section_label($section) : (string)$section;

		foreach (cbia_image_model_chain() as $model) {
			// Si el usuario bloquea también modelos imagen en el mismo array, lo respetamos
			if (!empty($blocked[$model])) continue;

			$tries = 2;
			for ($t = 1; $t <= $tries; $t++) {
				if (cbia_is_stop_requested()) return [false, 0, $model, 'STOP activado'];

				$delay = function_exists('cbia_get_image_request_delay') ? cbia_get_image_request_delay() : 0;
				if ($delay > 0) sleep($delay);

				cbia_log(__("Imagen IA: modelo={$model} sección={$section_label} intento {$t}/{$tries}","ai-blog-builder-pro"), 'INFO');

				$payload = [
					'model'  => $model,
					'prompt' => $prompt,
					'n'      => 1,
					'size'   => $size,
				];

				$resp = wp_remote_post('https://api.openai.com/v1/images/generations', [
					'headers' => cbia_http_headers_openai($api_key),
					'body'    => wp_json_encode($payload),
					'timeout' => 60,
				]);

				if (is_wp_error($resp)) {
					cbia_log(__("Imagen IA HTTP error: ","ai-blog-builder-pro") . $resp->get_error_message(), 'ERROR');
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$data = json_decode($body, true);

				if ($code < 200 || $code >= 300) {
					$msg = '';
					if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
					cbia_log(__("Imagen IA error HTTP {$code}","ai-blog-builder-pro") . ($msg ? " | {$msg}" : ''), 'ERROR');
					continue;
				}

				if (is_array($data) && !empty($data['error']['message'])) {
					cbia_log(__("Imagen IA error payload: ","ai-blog-builder-pro") . (string)$data['error']['message'], 'ERROR');
					continue;
				}

				$bytes = '';
				if (!empty($data['data'][0]['b64_json'])) {
					$bytes = base64_decode((string)$data['data'][0]['b64_json']);
				} elseif (!empty($data['data'][0]['url'])) {
					$img = wp_remote_get((string)$data['data'][0]['url'], ['timeout' => 60]);
					if (!is_wp_error($img) && (int)wp_remote_retrieve_response_code($img) === 200) {
						$bytes = (string)wp_remote_retrieve_body($img);
					}
				}

				if ($bytes === '') {
					cbia_log(__("Imagen IA: respuesta sin bytes (modelo={$model})","ai-blog-builder-pro"), 'ERROR');
					continue;
				}

				list($attach_id, $uerr) = cbia_upload_image_to_media($bytes, $title, $section, $alt);
				if (!$attach_id) {
					cbia_log(__("Imagen IA: fallo subiendo a Media Library: {$uerr}","ai-blog-builder-pro"), 'ERROR');
					continue;
				}

				cbia_log(__("Imagen IA OK: sección={$section_label} attach_id={$attach_id}","ai-blog-builder-pro"), 'INFO');
				return [true, (int)$attach_id, $model, ''];
			}
		}

		return [false, 0, '', 'No se pudo generar imagen tras reintentos'];
	}
}

if (!function_exists('cbia_generate_image_openai_with_prompt')) {
	/**
	 * Retorna [ok(bool), attach_id(int), model_used(string), err(string)]
	 */
	function cbia_generate_image_openai_with_prompt($prompt, $section, $title, $alt_text = '') {
		cbia_try_unlimited_runtime();
		// PRO: provider selector (fallback to OpenAI if different provider selected)
		if (function_exists('cbia_providers_get_settings') && function_exists('cbia_providers_get_current_provider')) {
			$provider_settings = cbia_providers_get_settings();
			$current_provider = cbia_providers_get_current_provider($provider_settings);
			if ($current_provider !== 'openai') {
				cbia_log(sprintf(__('Provider activo "%s" aun no soportado para imagenes. Usando OpenAI como fallback.', 'ai-blog-builder-pro'), (string)$current_provider), 'WARN');
			}
		}
		$api_key = cbia_openai_api_key();
		if (!$api_key) return [false, 0, '', 'No hay API key'];
		if (!cbia_openai_consent_ok()) return [false, 0, '', 'Consentimiento OpenAI no aceptado'];
		if (cbia_is_stop_requested()) return [false, 0, '', 'STOP activado'];

		$s = cbia_get_settings();
		$blocked = $s['blocked_models'] ?? [];
		if (!is_array($blocked)) $blocked = [];

		$size = cbia_image_size_for_section($section);
		$alt  = $alt_text !== '' ? (string)$alt_text : cbia_build_img_alt($title, $section, $prompt);
		$section_label = function_exists('cbia_section_label') ? cbia_section_label($section) : (string)$section;

		foreach (cbia_image_model_chain() as $model) {
			if (!empty($blocked[$model])) continue;
			$tries = 2;
			for ($t = 1; $t <= $tries; $t++) {
				if (cbia_is_stop_requested()) return [false, 0, $model, 'STOP activado'];

				$delay = function_exists('cbia_get_image_request_delay') ? cbia_get_image_request_delay() : 0;
				if ($delay > 0) sleep($delay);

				cbia_log(__("Imagen IA: modelo={$model} seccion={$section_label} intento {$t}/{$tries}","ai-blog-builder-pro"), 'INFO');

				$payload = [
					'model'  => $model,
					'prompt' => (string)$prompt,
					'n'      => 1,
					'size'   => $size,
				];

				$resp = wp_remote_post('https://api.openai.com/v1/images/generations', [
					'headers' => cbia_http_headers_openai($api_key),
					'body'    => wp_json_encode($payload),
					'timeout' => 60,
				]);

				if (is_wp_error($resp)) {
					cbia_log(__("Imagen IA HTTP error: ","ai-blog-builder-pro") . $resp->get_error_message(), 'ERROR');
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code($resp);
				$body = (string) wp_remote_retrieve_body($resp);
				$data = json_decode($body, true);

				if ($code < 200 || $code >= 300) {
					$msg = '';
					if (is_array($data) && !empty($data['error']['message'])) $msg = (string)$data['error']['message'];
					cbia_log(__("Imagen IA error HTTP {$code}","ai-blog-builder-pro") . ($msg ? " | {$msg}" : ''), 'ERROR');
					continue;
				}

				if (is_array($data) && !empty($data['error']['message'])) {
					cbia_log(__("Imagen IA error payload: ","ai-blog-builder-pro") . (string)$data['error']['message'], 'ERROR');
					continue;
				}

				$bytes = '';
				if (!empty($data['data'][0]['b64_json'])) {
					$bytes = base64_decode((string)$data['data'][0]['b64_json']);
				} elseif (!empty($data['data'][0]['url'])) {
					$img = wp_remote_get((string)$data['data'][0]['url'], ['timeout' => 60]);
					if (!is_wp_error($img) && (int)wp_remote_retrieve_response_code($img) === 200) {
						$bytes = (string)wp_remote_retrieve_body($img);
					}
				}

				if ($bytes === '') {
					cbia_log(__("Imagen IA: respuesta sin bytes (modelo={$model})","ai-blog-builder-pro"), 'ERROR');
					continue;
				}

				list($attach_id, $uerr) = cbia_upload_image_to_media($bytes, $title, $section, $alt);
				if (!$attach_id) {
					cbia_log(__("Imagen IA: fallo subiendo a Media Library: {$uerr}","ai-blog-builder-pro"), 'ERROR');
					continue;
				}

				cbia_log(__("Imagen IA OK: seccion={$section_label} attach_id={$attach_id}","ai-blog-builder-pro"), 'INFO');
				return [true, (int)$attach_id, $model, ''];
			}
		}

		return [false, 0, '', 'No se pudo generar imagen tras reintentos'];
	}
}

