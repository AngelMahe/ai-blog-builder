<?php
if (!defined('ABSPATH')) exit;

/**
 * TAB: Configuracion
 * Guarda en cbia_settings:
 * - openai_api_key, openai_model, openai_temperature
 * - post_length_variant, images_limit
 * - prompt_single_all (+ prompts de imagen por seccion)
 * - default_category, keywords_to_categories, default_tags
 * - blocked_models (checkbox por modelo)
 * - default_author_id (autor fijo para posts, ÃƒÂºtil para cron/evento)
 *
 * Sanitiza y MERGEA sin borrar campos de otros tabs.
 */

/* Helpers moved to includes/support/* (sanitize + config-catalog). */

/**
 * Guardado settings (POST)
 */
if (!function_exists('cbia_config_handle_post')) {
	function cbia_config_handle_post(): void {
		if (!is_admin()) return;
		if (!current_user_can('manage_options')) return;

		if (!isset($_POST['cbia_config_save'])) return;

		check_admin_referer('cbia_config_save_action', 'cbia_config_nonce');

		$settings = cbia_get_settings();

		$api_key = isset($_POST['openai_api_key']) ? sanitize_text_field(wp_unslash($_POST['openai_api_key'])) : '';
		$openai_consent = 1;
		$model   = isset($_POST['openai_model']) ? sanitize_text_field(wp_unslash($_POST['openai_model'])) : ($settings['openai_model'] ?? '');
		$model   = cbia_config_safe_model($model);

		$image_model = isset($_POST['image_model']) ? sanitize_text_field(wp_unslash($_POST['image_model'])) : (string)($settings['image_model'] ?? 'gpt-image-1-mini');
		if (!in_array($image_model, ['gpt-image-1-mini', 'gpt-image-1'], true)) $image_model = 'gpt-image-1-mini';

		// PRO: provider settings (save only)
			if (function_exists('cbia_providers_get_settings') && function_exists('cbia_providers_get_all')) {
				$provider_settings = cbia_providers_get_settings();
				$providers_all = cbia_providers_get_all();
				$providers_list = isset($providers_all['providers']) && is_array($providers_all['providers']) ? $providers_all['providers'] : [];
				$current_provider = isset($_POST['ai_provider']) ? sanitize_text_field(wp_unslash($_POST['ai_provider'])) : ($provider_settings['provider'] ?? 'openai');
				if (!isset($providers_list[$current_provider])) $current_provider = 'openai';

				$providers_new = is_array($provider_settings['providers'] ?? null) ? $provider_settings['providers'] : [];
				foreach ($providers_list as $pkey => $pdef) {
					$api = isset($_POST['provider_api_key'][$pkey]) ? sanitize_text_field(wp_unslash($_POST['provider_api_key'][$pkey])) : (string)($providers_new[$pkey]['api_key'] ?? '');
					$mdl = isset($_POST['provider_model'][$pkey]) ? sanitize_text_field(wp_unslash($_POST['provider_model'][$pkey])) : (string)($providers_new[$pkey]['model'] ?? ($pdef['models'][0] ?? ''));
					$base = isset($_POST['provider_base_url'][$pkey]) ? sanitize_text_field(wp_unslash($_POST['provider_base_url'][$pkey])) : (string)($providers_new[$pkey]['base_url'] ?? ($pdef['base_url'] ?? ''));
					$providers_new[$pkey] = [
						'api_key'  => $api,
						'model'    => $mdl,
						'base_url' => $base,
					];
				}
				if (isset($providers_new['openai'])) {
					$providers_new['openai']['api_key'] = $api_key;
				}

			if (function_exists('cbia_providers_save_settings')) {
				cbia_providers_save_settings([
					'provider'  => $current_provider,
					'providers' => $providers_new,
				]);
			}
		}

		$temp = isset($_POST['openai_temperature'])
			? (float) str_replace(',', '.', (string) wp_unslash($_POST['openai_temperature']))
			: (float)($settings['openai_temperature'] ?? 0.7);

		if ($temp < 0) $temp = 0;
		if ($temp > 2) $temp = 2;

		$post_length_variant = isset($_POST['post_length_variant'])
			? sanitize_key((string) wp_unslash($_POST['post_length_variant']))
			: (string)($settings['post_length_variant'] ?? 'medium');

		if (!in_array($post_length_variant, ['short','medium','long'], true)) $post_length_variant = 'medium';

		$images_limit = isset($_POST['images_limit']) ? absint(wp_unslash($_POST['images_limit'])) : (int)($settings['images_limit'] ?? 3);
		if ($images_limit < 1) $images_limit = 1;
		if ($images_limit > 4) $images_limit = 4;

		$prompt_single_all = isset($_POST['prompt_single_all'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_single_all'])))
			: (string)($settings['prompt_single_all'] ?? '');

		$prompt_img_intro = isset($_POST['prompt_img_intro'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_intro'])))
			: (string)($settings['prompt_img_intro'] ?? '');

		$prompt_img_body = isset($_POST['prompt_img_body'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_body'])))
			: (string)($settings['prompt_img_body'] ?? '');

		$prompt_img_conclusion = isset($_POST['prompt_img_conclusion'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_conclusion'])))
			: (string)($settings['prompt_img_conclusion'] ?? '');

		$prompt_img_faq = isset($_POST['prompt_img_faq'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_faq'])))
			: (string)($settings['prompt_img_faq'] ?? '');

		$prompt_img_global = isset($_POST['prompt_img_global'])
			? cbia_sanitize_textarea_preserve_lines(sanitize_textarea_field(wp_unslash($_POST['prompt_img_global'])))
			: (string)($settings['prompt_img_global'] ?? '');

		$responses_max_output_tokens = isset($_POST['responses_max_output_tokens'])
			? absint(wp_unslash($_POST['responses_max_output_tokens']))
			: (int)($settings['responses_max_output_tokens'] ?? 6000);
		if ($responses_max_output_tokens < 1500) $responses_max_output_tokens = 1500;
		if ($responses_max_output_tokens > 12000) $responses_max_output_tokens = 12000;

		// Preset rapido por modelo (si viene del boton de preset, manda sobre el resto)
		$preset_key = isset($_POST['cbia_preset_model']) ? sanitize_text_field(wp_unslash($_POST['cbia_preset_model'])) : '';
		if ($preset_key !== '' && function_exists('cbia_config_Presets_catalog')) {
			$Presets = cbia_config_Presets_catalog();
			if (isset($Presets[$preset_key])) {
				$p = $Presets[$preset_key];
				$model = cbia_config_safe_model($p['openai_model'] ?? $model);
				$temp = isset($p['openai_temperature']) ? (float)$p['openai_temperature'] : (float)$temp;
				$responses_max_output_tokens = isset($p['responses_max_output_tokens']) ? (int)$p['responses_max_output_tokens'] : (int)$responses_max_output_tokens;
				cbia_log(sprintf(__('Preset aplicado en Config: %s', 'ai-blog-builder-pro'), (string)$preset_key), 'INFO');
			}
		}

		$post_language = isset($_POST['post_language'])
			? sanitize_text_field(wp_unslash($_POST['post_language']))
			: (string)($settings['post_language'] ?? 'espanol');
		if ($post_language === '') $post_language = 'espanol';

		// Banner CSS en Contenido (no destacada)
		$content_images_banner_enabled = 1;
		$content_images_banner_css = (string)($settings['content_images_banner_css'] ?? '');

		// Preset rÃƒÆ’Ã‚Â¡pido de CSS de banner (selector)
		$banner_preset_key = 'forced';

		// Formato de imagen por seccion (UI) - nota: el engine fuerza intro=panorÃƒÂ¡mica y resto=banner (como en v8.4)
		$image_format_intro = isset($_POST['image_format_intro'])
			? cbia_config_sanitize_image_format($_POST['image_format_intro'], 'panoramic_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_intro'] ?? ''), 'panoramic_1536x1024');

		$image_format_body = isset($_POST['image_format_body'])
			? cbia_config_sanitize_image_format($_POST['image_format_body'], 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_body'] ?? ''), 'banner_1536x1024');

		$image_format_conclusion = isset($_POST['image_format_conclusion'])
			? cbia_config_sanitize_image_format($_POST['image_format_conclusion'], 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_conclusion'] ?? ''), 'banner_1536x1024');

		$image_format_faq = isset($_POST['image_format_faq'])
			? cbia_config_sanitize_image_format($_POST['image_format_faq'], 'banner_1536x1024')
			: cbia_config_sanitize_image_format((string)($settings['image_format_faq'] ?? ''), 'banner_1536x1024');

		$default_category = isset($_POST['default_category'])
			? sanitize_text_field(wp_unslash($_POST['default_category']))
			: (string)($settings['default_category'] ?? 'Noticias');

		if ($default_category === '') $default_category = 'Noticias';

		$keywords_to_categories = isset($_POST['keywords_to_categories'])
			? cbia_sanitize_textarea_preserve_lines($_POST['keywords_to_categories'])
			: (string)($settings['keywords_to_categories'] ?? '');

		$default_tags = isset($_POST['default_tags'])
			? cbia_sanitize_csv_tags(sanitize_text_field(wp_unslash($_POST['default_tags'])))
			: (string)($settings['default_tags'] ?? '');

		// Autor por defecto (para cron/evento): 0 = automatico (usuario actual o admin)
		$default_author_id = isset($_POST['default_author_id']) ? (int)$_POST['default_author_id'] : (int)($settings['default_author_id'] ?? 0);
		if ($default_author_id < 0) $default_author_id = 0;

		// Bloqueo modelos
		$blocked_models = [];
		if (!empty($_POST['blocked_models']) && is_array($_POST['blocked_models'])) {
			foreach ($_POST['blocked_models'] as $m => $v) {
				$m = sanitize_text_field((string)$m);
				$blocked_models[$m] = 1;
			}
		}

		$partial = [
			'openai_api_key'         => $api_key,
			'openai_consent'         => $openai_consent,
			'openai_model'           => $model,
			'image_model'            => $image_model,
			'openai_temperature'     => $temp,
			'post_length_variant'    => $post_length_variant,
			'images_limit'           => $images_limit,
			'prompt_single_all'      => $prompt_single_all,
			'prompt_img_intro'       => $prompt_img_intro,
			'prompt_img_body'        => $prompt_img_body,
			'prompt_img_conclusion'  => $prompt_img_conclusion,
			'prompt_img_faq'         => $prompt_img_faq,
			'prompt_img_global'      => $prompt_img_global,
			'responses_max_output_tokens' => $responses_max_output_tokens,
			'post_language'          => $post_language,
			'content_images_banner_enabled' => $content_images_banner_enabled,
			'content_images_banner_css' => $content_images_banner_css,
			'image_format_intro'     => $image_format_intro,
			'image_format_body'      => $image_format_body,
			'image_format_conclusion'=> $image_format_conclusion,
			'image_format_faq'       => $image_format_faq,
			'default_category'       => $default_category,
			'keywords_to_categories' => $keywords_to_categories,
			'default_tags'           => $default_tags,
			'blocked_models'         => $blocked_models,
			'default_author_id'      => $default_author_id,
		];

		cbia_update_settings_merge($partial);
		cbia_log(__('Configuracion guardada correctamente.', 'ai-blog-builder-pro'), 'INFO');

		wp_redirect(admin_url('admin.php?page=cbia&tab=config&saved=1'));
		exit;
	}
}

add_action('admin_init', 'cbia_config_handle_post');

/**
 * Render tab
 */
if (!function_exists('cbia_render_tab_config')) {
    function cbia_render_tab_config(){
        if (!current_user_can('manage_options')) return;

        $view = (defined('CBIA_INCLUDES_DIR') ? CBIA_INCLUDES_DIR . 'admin/views/config.php' : __DIR__ . '/views/config.php');
        if (file_exists($view)) {
            include $view;
            return;
        }

        echo '<p>No se pudo cargar Configuracion.</p>';
    }
}

