<?php
// File: includes/cbia-blog.php
if (!defined('ABSPATH')) exit;

/**
 * TAB BLOG (v9.1)
 * - UI + acciones de lote con checkpoint
 * - Lanzamiento por AJAX + evento (no bloquea pantalla) + log “en vivo”
 * - IMPORTANTE: procesado por tandas para evitar timeouts (1 post por evento por defecto)
 */

/* =========================================================
   =================== HELPERS: LAST SCHEDULED =============
   ========================================================= */
if (!function_exists('cbia_get_last_scheduled_at')) {
    function cbia_get_last_scheduled_at() {
        return (string) get_option('_cbia_last_scheduled_at', '');
    }
}
if (!function_exists('cbia_set_last_scheduled_at')) {
    function cbia_set_last_scheduled_at($datetime) {
        if ($datetime) update_option('_cbia_last_scheduled_at', $datetime);
    }
}

/* =========================================================
   =================== HELPERS: CHECKPOINT =================
   ========================================================= */
if (!function_exists('cbia_checkpoint_clear')) {
    function cbia_checkpoint_clear(){ delete_option('cbia_checkpoint'); }
}
if (!function_exists('cbia_checkpoint_get')) {
    function cbia_checkpoint_get(){
        $cp = get_option('cbia_checkpoint', array());
        return is_array($cp) ? $cp : array();
    }
}
if (!function_exists('cbia_checkpoint_save')) {
    function cbia_checkpoint_save($cp){ update_option('cbia_checkpoint', $cp); }
}

/* =========================================================
   =================== GET TITLES (manual/CSV) =============
   ========================================================= */
if (!function_exists('cbia_get_titles')) {
    function cbia_get_titles(){
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array());
        $mode = $settings['title_input_mode'] ?? 'manual';

        if ($mode === 'manual') {
            $manual = (string)($settings['manual_titles'] ?? '');
            $arr = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $manual)));
            if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Títulos cargados manualmente: ".count($arr));
            return $arr;
        }

        if ($mode === 'csv') {
            $csv_url = trim((string)($settings['csv_url'] ?? ''));
            if ($csv_url === '') {
                if (function_exists('cbia_log_message')) cbia_log_message("[ERROR] Modo CSV: falta URL.");
                return array();
            }

            $resp = wp_remote_get($csv_url, array('timeout' => 25));
            if (is_wp_error($resp)) {
                if (function_exists('cbia_log_message')) cbia_log_message("[ERROR] CSV error: ".$resp->get_error_message());
                return array();
            }
            $body = (string) wp_remote_retrieve_body($resp);
            $lines = preg_split('/\r\n|\r|\n/', $body);

            $out = array();
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (stripos($line, 'titulo') !== false || stripos($line, 'título') !== false) continue;
                $out[] = $line;
            }
            $out = array_values(array_unique(array_filter(array_map('trim', $out))));
            if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Títulos cargados desde CSV: ".count($out));
            return $out;
        }

        if (function_exists('cbia_log_message')) cbia_log_message("[ERROR] Modo de entrada de títulos no válido.");
        return array();
    }
}

/* =========================================================
   =================== PREPARE QUEUE ========================
   ========================================================= */
if (!function_exists('cbia_prepare_queue_from_titles')) {
    function cbia_prepare_queue_from_titles($titles){
        $queue = array();
        foreach ((array)$titles as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;

            // Evitar duplicados por título si existe helper
            if (function_exists('cbia_post_exists_by_title') && cbia_post_exists_by_title($t)) {
                if (function_exists('cbia_log_message')) cbia_log_message("[INFO] El post '{$t}' ya existe. Omitido (cola).");
                continue;
            }

            $queue[] = $t;
        }
        $queue = array_values(array_unique($queue));
        return $queue;
    }
}

/* =========================================================
   =================== COMPUTE NEXT DATETIME ===============
   ========================================================= */
if (!function_exists('cbia_compute_next_datetime')) {
    function cbia_compute_next_datetime($interval_days){
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array());
        $first_dt = trim((string)($settings['first_publication_datetime'] ?? ''));
        $last = cbia_get_last_scheduled_at();

        // Si no hay last, usar first_dt si existe
        if ($last === '') {
            if ($first_dt !== '') {
                // first_dt viene como 'Y-m-d H:i:s'
                if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $first_dt)) return $first_dt;
                // tolerancia: 'Y-m-d H:i'
                if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $first_dt)) return $first_dt . ':00';
            }
            return '';
        }

        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
            $dt = new DateTime($last, $tz);
            $dt->modify('+' . max(1, (int)$interval_days) . ' day');
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            if (function_exists('cbia_log_message')) cbia_log_message("[ERROR] Error calculando próxima fecha: ".$e->getMessage());
            return '';
        }
    }
}

/* =========================================================
   =================== EVENT SCHEDULING HELPERS =============
   ========================================================= */
if (!function_exists('cbia_schedule_generation_event')) {
    function cbia_schedule_generation_event($delay_seconds = 5){
        $delay_seconds = max(1, (int)$delay_seconds);
        if (!wp_next_scheduled('cbia_generation_event')) {
            wp_schedule_single_event(time() + $delay_seconds, 'cbia_generation_event');
            if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Evento reprogramado en {$delay_seconds}s.");
        }
    }
}

/* =========================================================
   =================== BATCH con CHECKPOINT =================
   Procesa SOLO N posts por ejecución para evitar timeouts
   ========================================================= */
if (!function_exists('cbia_create_all_posts_checkpointed')) {
    function cbia_create_all_posts_checkpointed($incoming_titles=null, $max_per_run = 1){

        if (!function_exists('cbia_create_single_blog_post')) {
            if (function_exists('cbia_log_message')) cbia_log_message("[ERROR] Falta cbia_create_single_blog_post() (motor). Revisa includes/cbia-engine.php.");
            return array('done'=>true,'processed'=>0);
        }

        if (function_exists('cbia_set_stop_flag')) cbia_set_stop_flag(false);

        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array());
        $interval_days = max(1, intval($settings['publication_interval'] ?? 5));

        $cp = cbia_checkpoint_get();

        if (!$incoming_titles && !empty($cp) && !empty($cp['running']) && isset($cp['queue']) && is_array($cp['queue'])) {
            if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Reanudando desde checkpoint: ".count($cp['queue'])." en cola, idx=".intval($cp['idx'] ?? 0).".");
            $queue = $cp['queue'];
            $idx   = intval($cp['idx'] ?? 0);
        } else {
            $titles = $incoming_titles ?? cbia_get_titles();
            if (empty($titles)) {
                if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Sin títulos. Fin.");
                return array('done'=>true,'processed'=>0);
            }
            $queue = cbia_prepare_queue_from_titles($titles);
            $idx = 0;
            $cp = array('queue'=>$queue,'idx'=>$idx,'created_total'=>0,'running'=>true);
            cbia_checkpoint_save($cp);
            if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Checkpoint creado. Iniciando lote... cola=".count($queue));
        }

        if (empty($queue)) {
            if (function_exists('cbia_log_message')) cbia_log_message("[INFO] No hay títulos nuevos. Fin.");
            cbia_checkpoint_clear();
            return array('done'=>true,'processed'=>0);
        }

        $max_per_run = max(1, (int)$max_per_run);
        $processed_this_run = 0;

        foreach ($queue as $i => $title) {

            if (function_exists('cbia_check_stop_flag') && cbia_check_stop_flag()) {
                if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Detenido durante lote (STOP).");
                break;
            }

            if ($i < $idx) continue;

            $title = trim((string)$title);
            if ($title === '') {
                $cp['idx'] = $i + 1;
                cbia_checkpoint_save($cp);
                continue;
            }

            $next_dt = cbia_compute_next_datetime($interval_days);

            if ($next_dt === '') {
                if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Creando post: {$title} | Publicado ahora");
                $post_id = cbia_create_single_blog_post($title, null);
                if ($post_id) {
                    $now_local = current_time('mysql');
                    cbia_set_last_scheduled_at($now_local);
                    $cp['created_total']++;
                } else {
                    if (function_exists('cbia_log_message')) cbia_log_message("[ERROR] No se pudo crear '{$title}'.");
                }
            } else {
                if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Creando post: {$title} | Programado: {$next_dt}");
                $post_id = cbia_create_single_blog_post($title, $next_dt);
                if ($post_id) {
                    cbia_set_last_scheduled_at($next_dt);
                    $cp['created_total']++;
                } else {
                    if (function_exists('cbia_log_message')) cbia_log_message("[ERROR] No se pudo programar '{$title}'.");
                }
            }

            $cp['idx'] = $i + 1;
            cbia_checkpoint_save($cp);

            $processed_this_run++;

            // 👇 CLAVE: cortar aquí para evitar timeout y continuar en el siguiente evento
            if ($processed_this_run >= $max_per_run) {
                if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Tanda completada: processed_this_run={$processed_this_run}. Se continuará en el siguiente evento.");
                break;
            }
        }

        // Final / estado
        $queue_count = count((array)($cp['queue'] ?? array()));
        $idx_now = intval($cp['idx'] ?? 0);

        if ($queue_count > 0 && $idx_now >= $queue_count) {
            if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Cola finalizada. Total creados: ".intval($cp['created_total']));
            $cp['running'] = false;
            cbia_checkpoint_save($cp);
            cbia_checkpoint_clear();
            return array('done'=>true,'processed'=>$processed_this_run);
        }

        if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Cola pendiente. Checkpoint idx={$idx_now}/{$queue_count}. Total creados=".intval($cp['created_total']));
        return array('done'=>false,'processed'=>$processed_this_run);
    }
}

/* =========================================================
   =================== ACTION: RUN GENERATION ===============
   ========================================================= */
if (!function_exists('cbia_run_generate_blogs')) {
    function cbia_run_generate_blogs(){
        if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Iniciando creación de blogs (checkpoint)…");

        // ✅ Ajusta aquí si quieres 2 o 3 por tanda (recomendado 1 si hay imágenes)
        $max_per_run = 1;

        $result = cbia_create_all_posts_checkpointed(null, $max_per_run);

        if (is_array($result) && empty($result['done'])) {
            // reprogramar siguiente tanda
            cbia_schedule_generation_event(8);
        } else {
            if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Proceso terminado (sin cola pendiente).");
        }

        if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Llamada finalizada (si hay checkpoint activo, se reanuda).");
    }
}

/* =========================================================
   =================== AJAX: START GENERATION ==============
   ========================================================= */
add_action('wp_ajax_cbia_start_generation', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('cbia_ajax_nonce');

    // Encolar primer evento (o reencolar si no existe)
    cbia_schedule_generation_event(2);

    if (function_exists('cbia_set_stop_flag')) cbia_set_stop_flag(false);
    if (function_exists('cbia_log_message')) cbia_log_message('[INFO] Proceso encolado por AJAX (no bloquea la pantalla).');

    wp_send_json_success('OK');
});

/* =========================================================
   =================== EVENT: RUN GENERATION ===============
   ========================================================= */
add_action('cbia_generation_event', function () {
    if (function_exists('cbia_log_message')) cbia_log_message('[INFO] Ejecutando tanda en evento (background)…');
    cbia_run_generate_blogs();
    if (function_exists('cbia_log_message')) cbia_log_message('[INFO] Evento background finalizado.');
});

/* =========================================================
   ======================= TAB BLOG UI ======================
   ========================================================= */
if (!function_exists('cbia_render_tab_blog')) {
    function cbia_render_tab_blog(){
        if(!current_user_can('manage_options')) return;

        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array());
        $saved_notice = '';

        /* ===== Guardar ajustes de BLOG (títulos+automatización) ===== */
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $post_unslashed = wp_unslash($_POST);

            if (!empty($post_unslashed['cbia_form']) && $post_unslashed['cbia_form'] === 'blog_save' && check_admin_referer('cbia_blog_save_nonce')) {
                $mode = (string)($post_unslashed['title_input_mode'] ?? 'manual');
                $settings['title_input_mode'] = in_array($mode, array('manual','csv'), true) ? $mode : 'manual';

                $settings['manual_titles'] = (string)($post_unslashed['manual_titles'] ?? '');
                $settings['csv_url'] = trim((string)($post_unslashed['csv_url'] ?? ''));

                // datetime-local: 2026-02-20T09:00 -> 2026-02-20 09:00:00
                $dt_local = trim((string)($post_unslashed['first_publication_datetime_local'] ?? ''));
                if ($dt_local !== '') {
                    $dt_local = str_replace('T',' ', $dt_local);
                    if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $dt_local)) $dt_local .= ':00';
                    $settings['first_publication_datetime'] = $dt_local;
                } else {
                    $settings['first_publication_datetime'] = '';
                }

                $settings['publication_interval'] = max(1, intval($post_unslashed['publication_interval'] ?? 5));
                $settings['enable_cron_fill'] = !empty($post_unslashed['enable_cron_fill']) ? 1 : 0;

                update_option('cbia_settings', $settings);

                if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Blog: configuración guardada (títulos + automatización).");
                $saved_notice = 'guardado';
            }

            /* ===== Acciones ===== */
            if (!empty($post_unslashed['cbia_form']) && $post_unslashed['cbia_form'] === 'blog_actions' && check_admin_referer('cbia_blog_actions_nonce')) {
                $action = sanitize_text_field((string)($post_unslashed['cbia_action'] ?? ''));

                if ($action === 'test_config') {
                    if (function_exists('cbia_run_test_configuration')) cbia_run_test_configuration();
                    $saved_notice = 'test';

                } elseif ($action === 'stop_generation') {
                    if (function_exists('cbia_set_stop_flag')) cbia_set_stop_flag(true);
                    if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Stop activado por usuario.");
                    $saved_notice = 'stop';

                } elseif ($action === 'fill_pending_imgs') {
                    if (function_exists('cbia_set_stop_flag')) cbia_set_stop_flag(false);
                    if (function_exists('cbia_run_fill_pending_images')) cbia_run_fill_pending_images(10);
                    $saved_notice = 'pending';

                } elseif ($action === 'clear_checkpoint') {
                    cbia_checkpoint_clear();
                    delete_option('_cbia_last_scheduled_at'); // CLAVE: respeta primera fecha
                    if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Checkpoint limpiado + _cbia_last_scheduled_at reseteado.");
                    $saved_notice = 'checkpoint';

                } elseif ($action === 'clear_log') {
                    if (function_exists('cbia_clear_log')) cbia_clear_log();
                    else delete_option('cbia_activity_log');
                    if (function_exists('cbia_log_message')) cbia_log_message("[INFO] Log limpiado manualmente.");
                    $saved_notice = 'log';
                }
            }
        }

        // refrescar settings
        $settings = function_exists('cbia_get_settings') ? cbia_get_settings() : (array)get_option('cbia_settings', array());

        $mode = $settings['title_input_mode'] ?? 'manual';
        $manual_titles = $settings['manual_titles'] ?? '';
        $csv_url = $settings['csv_url'] ?? '';

        $first_dt = $settings['first_publication_datetime'] ?? '';
        $first_dt_local = '';
        if ($first_dt !== '') $first_dt_local = substr(str_replace(' ', 'T', $first_dt), 0, 16);

        $interval = max(1, intval($settings['publication_interval'] ?? 5));
        $enable_cron = !empty($settings['enable_cron_fill']);

        $cp = cbia_checkpoint_get();
        $cp_status = (!empty($cp) && !empty($cp['running']))
            ? ('EN CURSO | idx '.intval($cp['idx'] ?? 0).' de '.count((array)($cp['queue'] ?? array())))
            : 'inactivo';

        $last_dt = cbia_get_last_scheduled_at() ?: '(sin registros)';
        $log = (string)get_option('cbia_activity_log','');

        // Notices
        if ($saved_notice === 'guardado') {
            echo '<div class="notice notice-success is-dismissible"><p>Configuración de Blog guardada.</p></div>';
        } elseif ($saved_notice === 'test') {
            echo '<div class="notice notice-success is-dismissible"><p>Prueba ejecutada. Revisa el log.</p></div>';
        } elseif ($saved_notice === 'stop') {
            echo '<div class="notice notice-warning is-dismissible"><p>Stop activado.</p></div>';
        } elseif ($saved_notice === 'pending') {
            echo '<div class="notice notice-success is-dismissible"><p>Relleno de pendientes ejecutado. Revisa el log.</p></div>';
        } elseif ($saved_notice === 'checkpoint') {
            echo '<div class="notice notice-success is-dismissible"><p>Checkpoint limpiado y programación reseteada.</p></div>';
        } elseif ($saved_notice === 'log') {
            echo '<div class="notice notice-success is-dismissible"><p>Log limpiado.</p></div>';
        }

        $ajax_nonce = wp_create_nonce('cbia_ajax_nonce');
        $blog_nonce = wp_create_nonce('cbia_blog_nonce');
        ?>
        <h2>Programación</h2>
        <form method="post">
            <input type="hidden" name="cbia_form" value="blog_save" />
            <?php wp_nonce_field('cbia_blog_save_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th>Primera fecha/hora</th>
                    <td>
                        <input type="datetime-local" name="first_publication_datetime_local" value="<?php echo esc_attr($first_dt_local); ?>" />
                        <p class="description">Si lo dejas vacío, empieza inmediato. Si defines fecha/hora, la primera se programa y las siguientes respetan el intervalo.</p>
                    </td>
                </tr>
                <tr>
                    <th>Intervalo entre publicaciones (días)</th>
                    <td>
                        <input type="number" min="1" name="publication_interval" value="<?php echo esc_attr($interval); ?>" style="width:90px;" />
                    </td>
                </tr>
            </table>

            <h2>CRON: rellenar pendientes</h2>
            <label>
                <input type="checkbox" name="enable_cron_fill" <?php checked($enable_cron); ?> />
                Activar CRON hourly para rellenar imágenes pendientes
            </label>

            <h2>Títulos</h2>
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
                    <th>Títulos manuales</th>
                    <td>
                        <textarea name="manual_titles" rows="6" style="width:100%;max-width:1100px;" placeholder="Un título por línea"><?php echo esc_textarea($manual_titles); ?></textarea>
                        <p class="description">Guarda y luego pulsa “Crear Blogs (con reanudación)”.</p>
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

        <hr/>

        <h2>Estado del checkpoint</h2>
        <p><strong><?php echo esc_html($cp_status); ?></strong></p>
        <p><strong>Última programada/publicada:</strong> <code><?php echo esc_html($last_dt); ?></code></p>

        <hr/>

        <h2>Acciones</h2>
        <form method="post" id="cbia_actions_form">
            <input type="hidden" name="cbia_form" value="blog_actions" />
            <?php wp_nonce_field('cbia_blog_actions_nonce'); ?>

            <p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <button type="submit" class="button" name="cbia_action" value="test_config">Probar configuración</button>

                <button type="button" class="button button-primary" id="cbia_btn_generate">Crear Blogs (con reanudación)</button>

                <button type="submit" class="button" name="cbia_action" value="stop_generation" style="background:#b70000;color:#fff;border-color:#7a0000;">Detener (STOP)</button>
                <button type="submit" class="button" name="cbia_action" value="fill_pending_imgs">Rellenar pendientes</button>
                <button type="submit" class="button" name="cbia_action" value="clear_checkpoint">Limpiar checkpoint</button>
                <button type="submit" class="button" name="cbia_action" value="clear_log">Limpiar log</button>
            </p>
        </form>

        <h2>Log</h2>
        <textarea id="cbia_log" rows="14" readonly style="width:100%;max-width:1100px;background:#f9f9f9;"><?php echo esc_textarea($log); ?></textarea>

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
                if (payload === null || payload === undefined) return '';
                if (typeof payload === 'string') return payload;
                if (typeof payload === 'object') {
                    if (typeof payload.log === 'string') return payload.log;
                    try { return JSON.stringify(payload, null, 2); } catch(e){ return String(payload); }
                }
                return String(payload);
            }

            function refreshLog(){
                const url = ajaxurl + '?action=cbia_get_log&nonce=' + encodeURIComponent(<?php echo wp_json_encode($blog_nonce); ?>);

                fetch(url, { credentials:'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if(!logBox) return;

                        if (data && typeof data === 'object') {
                            if (data.success) {
                                const txt = extractLogText(data.data);
                                logBox.value = txt;
                                logBox.scrollTop = logBox.scrollHeight;
                                return;
                            }
                            if (typeof data.log === 'string') {
                                logBox.value = data.log;
                                logBox.scrollTop = logBox.scrollHeight;
                                return;
                            }
                        }

                        logBox.value = extractLogText(data);
                        logBox.scrollTop = logBox.scrollHeight;
                    })
                    .catch(()=>{});
            }
            setInterval(refreshLog, 3000);

            const btn = document.getElementById('cbia_btn_generate');
            if(btn){
                btn.addEventListener('click', function(){
                    btn.disabled = true;
                    const old = btn.textContent;
                    btn.textContent = 'Lanzando…';

                    const fd = new FormData();
                    fd.append('action','cbia_start_generation');
                    fd.append('_ajax_nonce', <?php echo wp_json_encode($ajax_nonce); ?>);

                    fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            if(data && data.success){
                                btn.textContent = 'En cola (ver log)…';
                                setTimeout(()=>{ btn.disabled=false; btn.textContent=old; }, 5000);
                            }else{
                                btn.disabled=false; btn.textContent=old;
                                alert((data && data.data) ? data.data : 'No se pudo iniciar');
                            }
                        })
                        .catch(e => {
                            btn.disabled=false; btn.textContent=old;
                            alert('Error: ' + e.message);
                        });
                });
            }
        })();
        </script>
        <?php
    }
}
