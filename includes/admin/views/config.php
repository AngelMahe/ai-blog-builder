<?php
if (!defined('ABSPATH')) exit;

// Config tab view (extracted from legacy cbia-config.php)

$settings_service = isset($cbia_settings_service) ? $cbia_settings_service : null;
$s = $settings_service && method_exists($settings_service, 'get_settings')
    ? $settings_service->get_settings()
    : cbia_get_settings();

$provider_settings = function_exists('cbia_providers_get_settings') ? cbia_providers_get_settings() : [];
$providers_all = function_exists('cbia_providers_get_all') ? cbia_providers_get_all() : [];
$providers_list = isset($providers_all['providers']) && is_array($providers_all['providers']) ? $providers_all['providers'] : [];
$provider_current = function_exists('cbia_providers_get_current_provider') ? cbia_providers_get_current_provider() : 'openai';
$provider_key_urls = array(
    'openai' => 'https://platform.openai.com/api-keys',
    'google' => 'https://makersuite.google.com/app/apikey',
    'deepseek' => 'https://platform.deepseek.com/api_keys',
);

// Defaults seguros
$recommended = cbia_get_recommended_text_model();
$s['openai_model'] = cbia_config_safe_model($s['openai_model'] ?? $recommended);
if (!isset($s['openai_temperature'])) $s['openai_temperature'] = 0.7;
if (!isset($s['post_length_variant'])) $s['post_length_variant'] = 'medium';
if (!isset($s['images_limit'])) $s['images_limit'] = 3;
if (!isset($s['default_category'])) $s['default_category'] = 'Noticias';
if (!isset($s['post_language'])) $s['post_language'] = 'Espanol';
if (!isset($s['responses_max_output_tokens'])) $s['responses_max_output_tokens'] = 6000;
if (!isset($s['image_model'])) $s['image_model'] = 'gpt-image-1-mini';
if (!isset($s['content_images_banner_enabled'])) $s['content_images_banner_enabled'] = 1;
if (!isset($s['openai_consent'])) $s['openai_consent'] = 1;
if (!isset($s['content_images_banner_css']) || trim((string)$s['content_images_banner_css']) === '') {
    $defaults = function_exists('cbia_get_default_settings') ? cbia_get_default_settings() : [];
    $s['content_images_banner_css'] = (string)($defaults['content_images_banner_css'] ?? '');
}
// Formatos (UI). Nota: el engine fuerza intro=panoramica, resto=banner.
if (!isset($s['image_format_intro'])) $s['image_format_intro'] = 'panoramic_1536x1024';
if (!isset($s['image_format_body'])) $s['image_format_body'] = 'banner_1536x1024';
if (!isset($s['image_format_conclusion'])) $s['image_format_conclusion'] = 'banner_1536x1024';
if (!isset($s['image_format_faq'])) $s['image_format_faq'] = 'banner_1536x1024';
if (!isset($s['blocked_models']) || !is_array($s['blocked_models'])) $s['blocked_models'] = [];
if (!isset($s['default_author_id'])) $s['default_author_id'] = 0;
if (!isset($s['prompt_img_global'])) $s['prompt_img_global'] = '';
$default_img_prompt = function_exists('cbia_default_image_prompt_template') ? cbia_default_image_prompt_template() : 'Professional editorial photography. Subject: {desc} related to {title}. {format} No text, no logos, no watermarks.';
if (trim((string)$s['prompt_img_global']) === '') $s['prompt_img_global'] = $default_img_prompt;

echo '<div style="margin-top:12px;">';
if (isset($_GET['saved'])) {
    echo '<div class="notice notice-success is-dismissible"><p>Configuracion guardada.</p></div>';
}

echo '<form method="post">';
wp_nonce_field('cbia_config_save_action', 'cbia_config_nonce');
echo '<input type="hidden" name="cbia_config_save" value="1" />';

echo '<div class="cbia-section">';
echo '<div class="cbia-section-title">Proveedor y modelo</div>';
echo '<div class="abb-provider-card">';

$image_models = ['gpt-image-1-mini', 'gpt-image-1'];
$current_provider_data = $providers_list[$provider_current] ?? [];
$current_provider_label = (string)($current_provider_data['label'] ?? 'OpenAI');
$current_provider_logo = plugins_url('assets/images/providers/' . $provider_current . '.svg', CBIA_PRO_PLUGIN_FILE);
if (!file_exists(plugin_dir_path(CBIA_PRO_PLUGIN_FILE) . 'assets/images/providers/' . $provider_current . '.svg')) {
    $current_provider_logo = plugins_url('assets/images/providers/openai.svg', CBIA_PRO_PLUGIN_FILE);
}

echo '<div class="abb-provider-grid">';
echo '<div class="abb-field">';
echo '<label>Proveedor</label>';
echo '<div class="abb-provider-select">';
echo '<button type="button" class="abb-provider-trigger" aria-expanded="false">';
echo '<img class="abb-provider-logo" src="' . esc_url($current_provider_logo) . '" alt="' . esc_attr($current_provider_label) . '" />';
echo '<span class="abb-provider-label">' . esc_html($current_provider_label) . '</span>';
echo '<span class="abb-provider-caret">▼</span>';
echo '</button>';
echo '<div class="abb-provider-menu">';
foreach ($providers_list as $pkey => $pdef) {
    $plabel = (string)($pdef['label'] ?? $pkey);
    $plogo = plugins_url('assets/images/providers/' . $pkey . '.svg', CBIA_PRO_PLUGIN_FILE);
    if (!file_exists(plugin_dir_path(CBIA_PRO_PLUGIN_FILE) . 'assets/images/providers/' . $pkey . '.svg')) {
        $plogo = plugins_url('assets/images/providers/openai.svg', CBIA_PRO_PLUGIN_FILE);
    }
    echo '<button type="button" class="abb-provider-option" data-value="' . esc_attr($pkey) . '" data-logo="' . esc_url($plogo) . '" data-label="' . esc_attr($plabel) . '">';
    echo '<img src="' . esc_url($plogo) . '" alt="' . esc_attr($plabel) . '" />';
    echo '<span>' . esc_html($plabel) . '</span>';
    echo '</button>';
}
echo '</div>';
echo '<select class="abb-provider-select-input" name="ai_provider" style="display:none;">';
foreach ($providers_list as $pkey => $pdef) {
    $plabel = (string)($pdef['label'] ?? $pkey);
    $plogo = plugins_url('assets/images/providers/' . $pkey . '.svg', CBIA_PRO_PLUGIN_FILE);
    if (!file_exists(plugin_dir_path(CBIA_PRO_PLUGIN_FILE) . 'assets/images/providers/' . $pkey . '.svg')) {
        $plogo = plugins_url('assets/images/providers/openai.svg', CBIA_PRO_PLUGIN_FILE);
    }
    echo '<option value="' . esc_attr($pkey) . '" data-logo="' . esc_url($plogo) . '"' . selected($provider_current, $pkey, false) . '>' . esc_html($plabel) . '</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div class="abb-field abb-provider-model" data-provider="openai">';
echo '<label>Modelo (texto)</label>';
$models = cbia_get_allowed_models_for_ui();
echo '<select name="openai_model" class="abb-select" id="abb-openai-model">';
foreach ($models as $m) {
    $label = $m;
    if ($m === $recommended) $label .= ' (RECOMENDADO)';
    echo '<option value="' . esc_attr($m) . '" ' . selected($s['openai_model'], $m, false) . '>' . esc_html($label) . '</option>';
}
echo '</select>';
echo '<input type="hidden" name="provider_model[openai]" id="abb-provider-model-openai" value="' . esc_attr((string)($s['openai_model'] ?? $recommended)) . '" />';
echo '</div>';

foreach ($providers_list as $pkey => $pdef) {
    if ($pkey === 'openai') continue;
    $list = cbia_providers_get_model_list($pkey);
    $saved = $provider_settings['providers'][$pkey]['model'] ?? ($pdef['model'] ?? '');
    echo '<div class="abb-field abb-provider-model" data-provider="' . esc_attr($pkey) . '" style="display:none;">';
    echo '<label>Modelo (texto)</label>';
    echo '<select name="provider_model[' . esc_attr($pkey) . ']" class="abb-select">';
    foreach ($list as $mdl) {
        echo '<option value="' . esc_attr($mdl) . '" ' . selected($saved, $mdl, false) . '>' . esc_html($mdl) . '</option>';
    }
    echo '</select>';
    echo '</div>';
}

echo '<div class="abb-field">';
echo '<label>Modelo (imagen)</label>';
echo '<select name="image_model" class="abb-select">';
foreach ($image_models as $im) {
    echo '<option value="' . esc_attr($im) . '" ' . selected((string)($s['image_model'] ?? 'gpt-image-1-mini'), $im, false) . '>' . esc_html($im) . '</option>';
}
echo '</select>';
echo '</div>';
echo '</div>'; // grid

echo '<div class="abb-api-row">';
echo '<label>Clave API</label>';
// OpenAI
echo '<div class="abb-provider-key" data-provider="openai">';
echo '<div class="abb-api-input">';
echo '<input class="abb-input" type="password" name="openai_api_key" id="abb-openai-key" value="' . esc_attr((string)($s['openai_api_key'] ?? '')) . '" autocomplete="off" />';
echo '<input type="hidden" name="provider_api_key[openai]" id="abb-provider-key-openai" value="' . esc_attr((string)($s['openai_api_key'] ?? '')) . '" />';
echo '<a class="button button-secondary abb-api-link" href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">Obtener API Key</a>';
echo '</div>';
echo '</div>';

// Otros proveedores
foreach ($providers_list as $pkey => $pdef) {
    if ($pkey === 'openai') continue;
    $key_val = (string)($provider_settings['providers'][$pkey]['api_key'] ?? '');
    $link = $provider_key_urls[$pkey] ?? '';
    echo '<div class="abb-provider-key" data-provider="' . esc_attr($pkey) . '" style="display:none;">';
    echo '<div class="abb-api-input">';
    echo '<input class="abb-input" type="password" name="provider_api_key[' . esc_attr($pkey) . ']" value="' . esc_attr($key_val) . '" autocomplete="off" />';
    if ($link !== '') {
        echo '<a class="button button-secondary abb-api-link" href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer">Obtener API Key</a>';
    }
    echo '</div>';
    echo '</div>';
}

echo '</div>'; // api row

echo '<p class="description" style="margin-top:8px;">Nota: la generacion de imagenes sigue usando OpenAI por ahora, aunque el proveedor de texto sea Google o DeepSeek.</p>';

echo '</div>'; // provider card
echo '</div>'; // section

echo '<div class="cbia-section">';
echo '<div class="cbia-section-title">Preferencias</div>';
echo '<table class="form-table" role="presentation">';

echo '<tr><th scope="row"><label>Temperatura</label></th><td>';
echo '<input type="text" name="openai_temperature" value="' . esc_attr((string)$s['openai_temperature']) . '" style="width:120px;" />';
echo '<p class="description">Rango recomendado: 0.0 a 1.0 (max 2.0).</p>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Max tokens de salida</label></th><td>';
echo '<input type="number" min="1500" max="12000" name="responses_max_output_tokens" value="' . esc_attr((string)$s['responses_max_output_tokens']) . '" style="width:120px;" />';
echo '<p class="description">Sube este valor si el texto sale cortado. Recomendado 6000-8000.</p>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Longitud de post</label></th><td>';
$variants = [
    'short'  => 'Corto (~1000 palabras)',
    'medium' => 'Medio (~1800-2000 palabras)',
    'long'   => 'Largo (~2000-2200 palabras)',
];
foreach ($variants as $k => $label) {
    echo '<label style="display:block;margin:4px 0;">';
    echo '<input type="radio" name="post_length_variant" value="' . esc_attr($k) . '" ' . checked($s['post_length_variant'], $k, false) . ' /> ';
    echo esc_html($label);
    echo '</label>';
}
echo '</td></tr>';

echo '<tr><th scope="row"><label>Estilo de imagenes internas</label></th><td>';
$images_limit_options = [
    1 => '0 internas (solo destacada)',
    2 => '1 interna',
    3 => '2 internas',
    4 => '3 internas',
];
echo '<select name="images_limit" style="width:220px;">';
foreach ($images_limit_options as $val => $label) {
    echo '<option value="' . esc_attr((string)$val) . '" ' . selected((int)$s['images_limit'], $val, false) . '>' . esc_html($label) . '</option>';
}
echo '</select>';
echo '<p class="description">La imagen destacada se genera siempre. Este selector controla solo las internas.</p>';
echo '</td></tr>';

echo '<tr><th scope="row"><label>Prompt (todo en uno)</label></th><td>';
echo '<textarea name="prompt_single_all" rows="10" style="width:100%;">' . esc_textarea((string)($s['prompt_single_all'] ?? '')) . '</textarea>';
echo '<p class="description">Usa {title}. Marcadores: [IMAGEN: descripcion].</p>';
echo '</td></tr>';


echo '<tr><th scope="row"><label>Imagenes dentro de secciones de contenido (no destacada)</label></th><td>';
echo '<p class="description">La clase <code>cbia-banner</code> se aplica siempre a las imagenes internas. No hay selector en la UI.</p>';
echo '</td></tr>';
echo '</td></tr>';


echo '<tr><th scope="row"><label>Prompts de imagen</label></th><td>';
echo '<p class="description">Se generan automaticamente combinando una base fija + el marcador [IMAGEN: ...] + el titulo del post. No es necesario escribir prompts manuales por imagen.</p>';
echo '<p class="description">Si necesitas ajustar una imagen concreta, usa "Editar prompt" aqui.</p>';

$prompt_posts = get_posts(array(
    'post_type' => 'post',
    'posts_per_page' => 10,
    'post_status' => array('publish','future','draft','pending','private'),
    'orderby' => 'date',
    'order' => 'DESC',
    'meta_query' => array(
        array(
            'key' => '_cbia_created',
            'value' => '1',
            'compare' => '=',
        ),
    ),
));

if (!empty($prompt_posts)) {
    echo '<div class="cbia-prompt-panel" style="margin-top:10px;">';
    echo '<label for="cbia-prompt-post"><strong>Post</strong></label>';
    echo '<select id="cbia-prompt-post" style="min-width:360px;">';
    echo '<option value="">Selecciona un post...</option>';
    foreach ($prompt_posts as $p) {
        echo '<option value="' . esc_attr((int)$p->ID) . '">' . esc_html($p->post_title) . '</option>';
    }
    echo '</select>';
    echo '<div class="cbia-prompt-actions" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">';
    echo '<button type="button" class="button cbia-prompt-btn" data-type="featured" data-idx="0">Editar destacada</button>';
    echo '<button type="button" class="button cbia-prompt-btn" data-type="internal" data-idx="1">Editar interna 1</button>';
    echo '<button type="button" class="button cbia-prompt-btn" data-type="internal" data-idx="2">Editar interna 2</button>';
    echo '<button type="button" class="button cbia-prompt-btn" data-type="internal" data-idx="3">Editar interna 3</button>';
    echo '</div>';
    echo '<p class="description">Edita el prompt final usado para regenerar una imagen concreta. No detiene el bulk.</p>';
    echo '</div>';

    echo '<div id="cbia-prompt-modal" class="cbia-modal" style="display:none;">';
    echo '  <div class="cbia-modal-inner">';
    echo '    <div class="cbia-modal-header">';
    echo '      <strong id="cbia-prompt-title">Editar prompt</strong>';
    echo '      <button type="button" class="button-link cbia-modal-close">Cerrar</button>';
    echo '    </div>';
    echo '    <textarea id="cbia-prompt-text" rows="8" style="width:100%;"></textarea>';
    echo '    <div class="cbia-modal-actions">';
    echo '      <button type="button" class="button" id="cbia-prompt-save">Guardar override</button>';
    echo '      <button type="button" class="button button-primary" id="cbia-prompt-save-regen">Guardar y regenerar</button>';
    echo '    </div>';
    echo '    <div class="cbia-modal-status" id="cbia-prompt-status"></div>';
    echo '  </div>';
    echo '</div>';
} else {
    echo '<p class="description">No hay posts creados por el plugin todavia.</p>';
}

echo '</td></tr>';


echo '<tr><th scope="row"><label>Bloquear modelos (no usables)</label></th><td>';
echo '<p class="description">Si marcas un modelo, el motor lo rechazara aunque este seleccionado.</p>';
$blocked = is_array($s['blocked_models']) ? $s['blocked_models'] : [];
echo '<div style="columns:2;max-width:920px;">';
foreach ($models as $m) {
    $checked = isset($blocked[$m]) ? 'checked' : '';
    $label = $m;
    if ($m === $recommended) $label .= ' (RECOMENDADO)';
    echo '<label style="display:block;margin:3px 0;">';
    echo '<input type="checkbox" name="blocked_models[' . esc_attr($m) . ']" value="1" ' . $checked . ' /> ';
    echo esc_html($label);
    echo '</label>';
}
echo '</div>';
echo '</td></tr>';

echo '</table>';
echo '</div>';

echo '<div class="cbia-section">';
echo '<div class="cbia-section-title">Integraciones</div>';
// Aviso Yoast al final de la configuracion
$yoast_active = defined('WPSEO_VERSION') || class_exists('WPSEO_Options');
if (!$yoast_active) {
    echo '<p class="description"><strong>Yoast SEO:</strong> no detectado. Si lo instalas, el plugin puede actualizar automaticamente la metadescripcion, la keyphrase y las puntuaciones de SEO/legibilidad al crear cada post.</p>';
} else {
    echo '<p class="description"><strong>Yoast SEO:</strong> detectado. Se actualizan automaticamente la metadescripcion, la keyphrase y las puntuaciones de SEO/legibilidad al crear cada post.</p>';
}
echo '</div>';

echo '<p>';
echo '<button type="submit" name="cbia_config_save" class="button button-primary">Guardar Configuracion</button>';
echo '</p>';

echo '</form>';
echo '</div>';
