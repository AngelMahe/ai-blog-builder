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

$manual_titles = $settings['manual_titles'] ?? '';

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
    $last_dt = (function_exists('cbia_get_last_scheduled_at') ? (cbia_get_last_scheduled_at() ?: '(sin registros)') : '(sin registros)');
}

$log_payload = $service && method_exists($service, 'get_log') ? $service->get_log() : cbia_get_log();
$log_text = is_array($log_payload) ? (string)($log_payload['log'] ?? '') : '';

// Usage lite (last 7 days, based on _cbia_usage_rows).
$usage_summary = array('calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'images' => 0);
$usage_posts = 0;
$usage_since_ts = time() - (7 * DAY_IN_SECONDS);
$usage_query = new WP_Query(array(
    'post_type'      => 'post',
    'post_status'    => array('publish', 'future', 'draft', 'pending'),
    'posts_per_page' => 200,
    'fields'         => 'ids',
    'no_found_rows'  => true,
));
$usage_ids = !empty($usage_query->posts) ? $usage_query->posts : array();
foreach ($usage_ids as $post_id) {
    $rows = get_post_meta((int) $post_id, '_cbia_usage_rows', true);
    if (empty($rows) || !is_array($rows)) continue;
    $has_usage = false;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $ts = isset($r['ts']) ? strtotime((string) $r['ts']) : 0;
        if ($ts && $ts < $usage_since_ts) continue;
        $has_usage = true;
        $usage_summary['calls']++;
        $usage_summary['tokens_in']  += (int) ($r['in'] ?? 0);
        $usage_summary['tokens_out'] += (int) ($r['out'] ?? 0);
        $type = isset($r['type']) ? strtolower(trim((string) $r['type'])) : 'text';
        if ($type === 'image') $usage_summary['images']++;
    }
    if ($has_usage) $usage_posts++;
}

if ($saved_notice === 'guardado') {
    echo '<div class="notice notice-success is-dismissible"><p>Configuracion de Blog guardada.</p></div>';
} elseif ($saved_notice === 'stop') {
    echo '<div class="notice notice-warning is-dismissible"><p>Stop activado.</p></div>';
} elseif ($saved_notice === 'checkpoint') {
    echo '<div class="notice notice-success is-dismissible"><p>Checkpoint limpiado.</p></div>';
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
<th>Titulos manuales</th>
<td>
<textarea name="manual_titles" rows="6" style="width:100%;max-width:1100px;" placeholder="Un titulo por linea"><?php echo esc_textarea($manual_titles); ?></textarea>
<p class="description">Guarda y luego pulsa "Crear blogs (con reanudacion)".</p>
<p style="margin-top:10px;">
<button type="submit" class="button button-primary">Guardar</button>
</p>
</td>
</tr>
</table>
</form>

<hr/>

<h2>Estado del checkpoint</h2>
<p><strong id="cbia_cp_status"><?php echo esc_html($cp_status); ?></strong></p>
<p><strong>Ultima publicada:</strong> <code id="cbia_cp_last"><?php echo esc_html($last_dt); ?></code></p>

<hr/>

<h2>Uso (lite)</h2>
<p class="description">Resumen de uso real de los ultimos 7 dias. Para detalle avanzado, usa la version Pro.</p>
<div class="cbia-usage-lite">
    <div class="cbia-card">
        <div style="color:#666;font-size:12px;">Posts con uso</div>
        <div style="font-size:20px;font-weight:600;"><?php echo esc_html((int) $usage_posts); ?></div>
    </div>
    <div class="cbia-card">
        <div style="color:#666;font-size:12px;">Llamadas</div>
        <div style="font-size:20px;font-weight:600;"><?php echo esc_html((int) $usage_summary['calls']); ?></div>
    </div>
    <div class="cbia-card">
        <div style="color:#666;font-size:12px;">Tokens entrada</div>
        <div style="font-size:20px;font-weight:600;"><?php echo esc_html((int) $usage_summary['tokens_in']); ?></div>
    </div>
    <div class="cbia-card">
        <div style="color:#666;font-size:12px;">Tokens salida</div>
        <div style="font-size:20px;font-weight:600;"><?php echo esc_html((int) $usage_summary['tokens_out']); ?></div>
    </div>
    <div class="cbia-card">
        <div style="color:#666;font-size:12px;">Imagenes</div>
        <div style="font-size:20px;font-weight:600;"><?php echo esc_html((int) $usage_summary['images']); ?></div>
    </div>
</div>

<hr/>

<h2>Acciones</h2>
<form method="post" id="cbia_actions_form">
<input type="hidden" name="cbia_form" value="blog_actions" />
<?php wp_nonce_field('cbia_blog_actions_nonce'); ?>

<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
<button type="button" class="button button-primary" id="cbia_btn_generate">Crear blogs (con reanudacion)</button>
<button type="submit" class="button" name="cbia_action" value="stop_generation" style="background:#b70000;color:#fff;border-color:#7a0000;">Detener (STOP)</button>
<button type="submit" class="button" name="cbia_action" value="clear_checkpoint">Limpiar checkpoint</button>
<button type="submit" class="button" name="cbia_action" value="clear_log">Limpiar log</button>
</p>
</form>

<h2>Log</h2>
<textarea id="cbia_log" rows="14" readonly style="width:100%;max-width:1100px;background:#f9f9f9;"><?php echo esc_textarea($log_text); ?></textarea>

<script>
(function(){
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
