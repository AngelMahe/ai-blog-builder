<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) return;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$service = isset($cbia_blog_service) ? $cbia_blog_service : null;

$saved_notice = '';
if ($service && method_exists($service, 'handle_post')) {
    $saved_notice = (string)$service->handle_post();
} elseif (function_exists('cbia_blog_handle_post')) {
    $saved_notice = cbia_blog_handle_post();
}

$settings = $service && method_exists($service, 'get_settings')
    ? $service->get_settings()
    : (function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array()));

$mode = $settings['title_input_mode'] ?? 'manual';
$manual_titles = $settings['manual_titles'] ?? '';
$csv_url = $settings['csv_url'] ?? '';

$first_dt = $settings['first_publication_datetime'] ?? '';
$first_dt_local = '';
if ($first_dt !== '') $first_dt_local = substr(str_replace(' ', 'T', $first_dt), 0, 16);

$interval = max(1, intval($settings['publication_interval'] ?? 5));
$enable_cron = !empty($settings['enable_cron_fill']);

$cp_status = 'inactivo';
$last_dt = '(sin registros)';
if ($service && method_exists($service, 'get_checkpoint_status')) {
    $status_payload = $service->get_checkpoint_status();
    if (is_array($status_payload)) {
        $cp_status = (string)($status_payload['status'] ?? $cp_status);
        $last_dt = (string)($status_payload['last'] ?? $last_dt);
    }
} else {
    $cp = cbia_checkpoint_get();
    $cp_status = (!empty($cp) && !empty($cp['running']))
        ? ('EN CURSO | idx ' . intval($cp['idx'] ?? 0) . ' de ' . count((array)($cp['queue'] ?? array())))
        : 'inactivo';
    $last_dt = $service && method_exists($service, 'get_last_scheduled_at')
        ? ($service->get_last_scheduled_at() ?: '(sin registros)')
        : (function_exists('cbia_get_last_scheduled_at') ? (cbia_get_last_scheduled_at() ?: '(sin registros)') : '(sin registros)');
}

$log_payload = $service && method_exists($service, 'get_log') ? $service->get_log() : cbia_get_log();
$log_text = is_array($log_payload) ? (string)($log_payload['log'] ?? '') : '';

if ($saved_notice === 'guardado') {
    echo '<div class="notice notice-success is-dismissible"><p>Configuracion de Blog guardada.</p></div>';
} elseif ($saved_notice === 'test') {
    echo '<div class="notice notice-success is-dismissible"><p>Prueba ejecutada. Revisa el log.</p></div>';
} elseif ($saved_notice === 'stop') {
    echo '<div class="notice notice-warning is-dismissible"><p>Stop activado.</p></div>';
} elseif ($saved_notice === 'pending') {
    echo '<div class="notice notice-success is-dismissible"><p>Relleno de pendientes ejecutado. Revisa el log.</p></div>';
} elseif ($saved_notice === 'checkpoint') {
    echo '<div class="notice notice-success is-dismissible"><p>Checkpoint limpiado y programacion reseteada.</p></div>';
} elseif ($saved_notice === 'log') {
    echo '<div class="notice notice-success is-dismissible"><p>Log limpiado.</p></div>';
}

$ajax_nonce = wp_create_nonce('cbia_ajax_nonce');
?>

<h2>Titulos</h2>
<form method="post">
<input type="hidden" name="cbia_form" value="blog_save" />
<?php wp_nonce_field('cbia_blog_save_nonce'); ?>

<table class="form-table">
<tr>
<th>Modo</th>
<td>
<label><input type="radio" name="title_input_mode" value="manual" <?php checked($mode,'manual'); ?> /> Manual</label>
&nbsp;&nbsp;
<label><input type="radio" name="title_input_mode" value="csv" <?php checked($mode,'csv'); ?> /> CSV</label>
</td>
</tr>
<tr id="cbia_row_manual" <?php if($mode!=='manual') echo 'style="display:none;"'; ?>>
<th>Titulos manuales</th>
<td>
<textarea name="manual_titles" rows="6" style="width:100%;max-width:1100px;" placeholder="Un titulo por linea"><?php echo esc_textarea($manual_titles); ?></textarea>
<p class="description">Guarda y luego pulsa "Crear Blogs (con reanudacion)".</p>
<p style="margin-top:10px;">
<button type="submit" class="button button-primary">Guardar</button>
</p>
</td>
</tr>
<tr id="cbia_row_csv" <?php if($mode!=='csv') echo 'style="display:none;"'; ?>>
<th>URL CSV</th>
<td>
<input type="text" name="csv_url" value="<?php echo esc_attr($csv_url); ?>" style="width:100%;max-width:1100px;" />
</td>
</tr>
</table>
</form>

<h2>Publicacion y clasificacion</h2>
<form method="post">
<input type="hidden" name="cbia_form" value="blog_save" />
<?php wp_nonce_field('cbia_blog_save_nonce'); ?>
<table class="form-table">
<tr>
<th>Autor por defecto</th>
<td>
<p class="description">Recomendado para ejecuciones por evento/cron. Si lo dejas en "Automatico", WordPress puede mostrar "-" si no hay usuario actual.</p>
<?php
$author_args = [
    'name'             => 'default_author_id',
    'selected'         => (int)($settings['default_author_id'] ?? 0),
    'show_option_none' => '- Automatico (usuario actual / admin) -',
    'option_none_value'=> 0,
    'capability'       => ['edit_posts'],
    'class'            => 'regular-text',
];
ob_start();
wp_dropdown_users($author_args);
$dd = ob_get_clean();
$dd = str_replace('class=\'', 'style="width:420px;" class=\'', $dd);
$dd = str_replace('class="', 'style="width:420px;" class="', $dd);
echo $dd;
?>
</td>
</tr>
<tr>
<th>Idioma del post</th>
<td>
<?php
$language_options = [
    'Espanol'   => 'Espanol',
    'Portugues' => 'Portugues',
    'Ingles'    => 'Ingles',
    'Frances'   => 'Frances',
    'italiano'  => 'Italiano',
    'Aleman'    => 'Aleman',
    'Holandes'  => 'Holandes',
    'sueco'     => 'Sueco',
    'Danes'     => 'Danes',
    'noruego'   => 'Noruego',
    'Fines'     => 'Fines',
    'polaco'    => 'Polaco',
    'checo'     => 'Checo',
    'eslovaco'  => 'Eslovaco',
    'Hungaro'   => 'Hungaro',
    'rumano'    => 'Rumano',
    'Bulgaro'   => 'Bulgaro',
    'griego'    => 'Griego',
    'croata'    => 'Croata',
    'esloveno'  => 'Esloveno',
    'estonio'   => 'Estonio',
    'Leton'     => 'Leton',
    'lituano'   => 'Lituano',
    'Irlandes'  => 'Irlandes',
    'Maltes'    => 'Maltes',
    'romanche'  => 'Romanche',
];
$current_language = (string)($settings['post_language'] ?? 'Espanol');
echo '<select name="post_language" style="width:220px;">';
foreach ($language_options as $val => $label) {
    echo '<option value="' . esc_attr($val) . '" ' . selected($current_language, $val, false) . '>' . esc_html($label) . '</option>';
}
echo '</select>';
?>
<p class="description">Se usa para {IDIOMA_POST} y para normalizar el titulo de "Preguntas frecuentes".</p>
</td>
</tr>
<tr>
<th>Categoria por defecto</th>
<td>
<input type="text" name="default_category" value="<?php echo esc_attr((string)($settings['default_category'] ?? 'Noticias')); ?>" style="width:420px;" />
</td>
</tr>
<tr>
<th>Reglas: keywords - Categorias</th>
<td>
<textarea name="keywords_to_categories" rows="6" style="width:100%;"><?php echo esc_textarea((string)($settings['keywords_to_categories'] ?? '')); ?></textarea>
<p class="description">Formato por linea: <code>Categoria: kw1, kw2, kw3</code>. Se compara contra (titulo+contenido).</p>
</td>
</tr>
<tr>
<th>Tags permitidas</th>
<td>
<input type="text" name="default_tags" value="<?php echo esc_attr((string)($settings['default_tags'] ?? '')); ?>" style="width:100%;" />
<p class="description">Separadas por comas. El engine SOLO podra usar estas tags (max 7 por post).</p>
</td>
</tr>
</table>
<p style="margin-top:10px;">
<button type="submit" class="button button-primary">Guardar</button>
</p>
</form>

<h2>Programacion</h2>
<form method="post">
<input type="hidden" name="cbia_form" value="blog_save" />
<?php wp_nonce_field('cbia_blog_save_nonce'); ?>

<table class="form-table">
<tr>
<th>Primera fecha/hora</th>
<td>
<input type="datetime-local" name="first_publication_datetime_local" value="<?php echo esc_attr($first_dt_local); ?>" />
<p class="description">Si lo dejas vacio, empieza inmediato. Si defines fecha/hora, la primera se programa y las siguientes respetan el intervalo.</p>
</td>
</tr>
<tr>
<th>Intervalo entre publicaciones (dias)</th>
<td>
<input type="number" min="1" name="publication_interval" value="<?php echo esc_attr($interval); ?>" style="width:90px;" />
</td>
</tr>
</table>

<h2>CRON: rellenar pendientes</h2>
<label>
<input type="checkbox" name="enable_cron_fill" <?php checked($enable_cron); ?> />
Activar CRON hourly para rellenar imagenes pendientes
</label>

<p style="margin-top:10px;">
<button type="submit" class="button button-primary">Guardar</button>
</p>
</form>

<hr/>

<h2>Estado del checkpoint</h2>
<p><strong id="cbia_cp_status"><?php echo esc_html($cp_status); ?></strong></p>
<p><strong>Ultima programada/publicada:</strong> <code id="cbia_cp_last"><?php echo esc_html($last_dt); ?></code></p>

<hr/>

<h2>Acciones</h2>
<form method="post" id="cbia_actions_form">
<input type="hidden" name="cbia_form" value="blog_actions" />
<?php wp_nonce_field('cbia_blog_actions_nonce'); ?>

<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
<button type="submit" class="button" name="cbia_action" value="test_config">Probar configuracion</button>

<button type="button" class="button button-primary" id="cbia_btn_generate">Crear Blogs (con reanudacion)</button>

<button type="submit" class="button" name="cbia_action" value="stop_generation" style="background:#b70000;color:#fff;border-color:#7a0000;">Detener (STOP)</button>
<button type="submit" class="button" name="cbia_action" value="fill_pending_imgs">Rellenar pendientes</button>
<button type="submit" class="button" name="cbia_action" value="clear_checkpoint">Limpiar checkpoint</button>
<button type="submit" class="button" name="cbia_action" value="clear_log">Limpiar log</button>
</p>
</form>

<h2>Log</h2>
<textarea id="cbia_log" rows="14" readonly style="width:100%;max-width:1100px;background:#f9f9f9;"><?php echo esc_textarea($log_text); ?></textarea>

<script>
(function(){
    const manualRow = document.getElementById('cbia_row_manual');
    const csvRow = document.getElementById('cbia_row_csv');
    const radios = document.querySelectorAll('input[name="title_input_mode"]');
    radios.forEach(r => r.addEventListener('change', function(){
        if(this.value === 'manual'){ manualRow.style.display=''; csvRow.style.display='none'; }
        else { manualRow.style.display='none'; csvRow.style.display=''; }
    }));

    const logBox = document.getElementById('cbia_log');

    function extractLogText(payload){
        if (!payload) return '';
        if (typeof payload === 'string') return payload;
        if (typeof payload === 'object') {
            if (payload.log && typeof payload.log === 'string') return payload.log;
            if (payload.data && payload.data.log && typeof payload.data.log === 'string') return payload.data.log;
            try { return JSON.stringify(payload, null, 2); } catch(e){ return String(payload); }
        }
        return String(payload);
    }

    function refreshLog(){
        if (typeof ajaxurl === 'undefined') return;
        const logUrl = ajaxurl + '?action=cbia_get_log&_ajax_nonce=' + encodeURIComponent(<?php echo wp_json_encode($ajax_nonce); ?>);
        fetch(logUrl, { credentials:'same-origin' })
        .then(r => r.text())
        .then(text => {
            if(!logBox) return;
            let data = null;
            try { data = JSON.parse(text); } catch(e) { return; }
            if (data && data.success) {
                logBox.value = extractLogText(data.data);
            } else {
                logBox.value = extractLogText(data);
            }
            logBox.scrollTop = logBox.scrollHeight;
        })
        .catch(()=>{});
    }
    setInterval(refreshLog, 3000);
    refreshLog();

    const cpStatus = document.getElementById('cbia_cp_status');
    const cpLast = document.getElementById('cbia_cp_last');

    function refreshCheckpoint(){
        if (typeof ajaxurl === 'undefined') return;
        const statusUrl = ajaxurl + '?action=cbia_get_checkpoint_status&_ajax_nonce=' + encodeURIComponent(<?php echo wp_json_encode($ajax_nonce); ?>);
        fetch(statusUrl, { credentials:'same-origin' })
        .then(r => r.text())
        .then(text => {
            let data = null;
            try { data = JSON.parse(text); } catch(e) { return; }
            if (!data || !data.success || !data.data) return;
            if (cpStatus) cpStatus.textContent = data.data.status || '';
            if (cpLast) cpLast.textContent = data.data.last || '';
        })
        .catch(()=>{});
    }
    setInterval(refreshCheckpoint, 5000);
    refreshCheckpoint();

    const btn = document.getElementById('cbia_btn_generate');
    if(btn){
        btn.addEventListener('click', function(){
            btn.disabled = true;
            const old = btn.textContent;
            btn.textContent = 'Lanzando...';

            const fd = new FormData();
            fd.append('action','cbia_start_generation');
            fd.append('_ajax_nonce', <?php echo wp_json_encode($ajax_nonce); ?>);

            fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
            .then(r => r.text())
            .then(text => {
                let data = null;
                try { data = JSON.parse(text); } catch(e) { data = null; }
                if(data && data.success){
                    btn.textContent = 'En marcha (ver log)...';
                    setTimeout(()=>{ btn.disabled=false; btn.textContent=old; }, 4000);
                }else{
                    btn.disabled=false; btn.textContent=old;
                }
            })
            .catch(() => {
                btn.disabled=false; btn.textContent=old;
            });
        });
    }
})();
</script>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>