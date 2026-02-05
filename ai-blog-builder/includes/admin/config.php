<?php
if (!defined('ABSPATH')) exit;

/**
 * TAB: Configuracion (FREE)
 * Guarda en cbia_settings solo los campos basicos.
 */

if (!function_exists('cbia_config_handle_post')) {
    function cbia_config_handle_post(): void {
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['cbia_config_save'])) return;

        check_admin_referer('cbia_config_save_action', 'cbia_config_nonce');

        $settings = cbia_get_settings();

        $api_key = isset($_POST['openai_api_key']) ? sanitize_text_field(wp_unslash($_POST['openai_api_key'])) : '';
        $openai_consent = !empty($_POST['openai_consent']) ? 1 : 0;

        $model = isset($_POST['openai_model']) ? sanitize_text_field(wp_unslash($_POST['openai_model'])) : ($settings['openai_model'] ?? '');
        $model = cbia_config_safe_model($model);

        $temp = isset($_POST['openai_temperature'])
            ? (float) str_replace(',', '.', (string) sanitize_text_field(wp_unslash($_POST['openai_temperature'])))
            : (float)($settings['openai_temperature'] ?? 0.7);
        if ($temp < 0) $temp = 0;
        if ($temp > 2) $temp = 2;

        $post_length_variant = isset($_POST['post_length_variant'])
            ? sanitize_key((string) wp_unslash($_POST['post_length_variant']))
            : (string)($settings['post_length_variant'] ?? 'medium');
        if (!in_array($post_length_variant, ['short','medium','long'], true)) $post_length_variant = 'medium';

        // FREE: solo 1 imagen (destacada)
        $images_limit = 1;

        $prompt_single_all = isset($_POST['prompt_single_all'])
            ? cbia_sanitize_textarea_preserve_lines(wp_unslash($_POST['prompt_single_all']))
            : (string)($settings['prompt_single_all'] ?? '');

        $responses_max_output_tokens = isset($_POST['responses_max_output_tokens'])
            ? absint(wp_unslash($_POST['responses_max_output_tokens']))
            : (int)($settings['responses_max_output_tokens'] ?? 6000);
        if ($responses_max_output_tokens < 1500) $responses_max_output_tokens = 1500;
        if ($responses_max_output_tokens > 12000) $responses_max_output_tokens = 12000;

        $post_language = isset($_POST['post_language'])
            ? sanitize_text_field(wp_unslash($_POST['post_language']))
            : (string)($settings['post_language'] ?? 'espanol');
        if ($post_language === '') $post_language = 'espanol';

        $faq_heading_custom = isset($_POST['faq_heading_custom'])
            ? sanitize_text_field(wp_unslash($_POST['faq_heading_custom']))
            : (string)($settings['faq_heading_custom'] ?? '');

        $default_category = isset($_POST['default_category'])
            ? sanitize_text_field(wp_unslash($_POST['default_category']))
            : (string)($settings['default_category'] ?? 'Noticias');
        if ($default_category === '') $default_category = 'Noticias';

        $default_tags = isset($_POST['default_tags'])
            ? cbia_sanitize_csv_tags(wp_unslash($_POST['default_tags']))
            : (string)($settings['default_tags'] ?? '');

        $default_author_id = isset($_POST['default_author_id']) ? absint(wp_unslash($_POST['default_author_id'])) : (int)($settings['default_author_id'] ?? 0);
        if ($default_author_id < 0) $default_author_id = 0;

        $partial = [
            'openai_api_key'         => $api_key,
            'openai_consent'         => $openai_consent,
            'openai_model'           => $model,
            'openai_temperature'     => $temp,
            'post_length_variant'    => $post_length_variant,
            'images_limit'           => $images_limit,
            'prompt_single_all'      => $prompt_single_all,
            'prompt_img_intro'       => '',
            'prompt_img_body'        => '',
            'prompt_img_conclusion'  => '',
            'prompt_img_faq'         => '',
            'responses_max_output_tokens' => $responses_max_output_tokens,
            'post_language'          => $post_language,
            'faq_heading_custom'     => $faq_heading_custom,
            'content_images_banner_enabled' => 0,
            'content_images_banner_css' => '',
            'default_category'       => $default_category,
            'default_tags'           => $default_tags,
            'default_author_id'      => $default_author_id,
            'keywords_to_categories' => '',
            'blocked_models'         => [],
            // Keep safe defaults for formats (not used in FREE)
            'image_format_intro'     => 'panoramic_1536x1024',
            'image_format_body'      => 'banner_1536x1024',
            'image_format_conclusion'=> 'banner_1536x1024',
            'image_format_faq'       => 'banner_1536x1024',
        ];

        cbia_update_settings_merge($partial);
        cbia_log('Configuracion guardada correctamente.', 'INFO');

        wp_safe_redirect(admin_url('admin.php?page=cbia&tab=config&saved=1'));
        exit;
    }
}

add_action('admin_init', 'cbia_config_handle_post');

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
