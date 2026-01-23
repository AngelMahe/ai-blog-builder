<?php
/**
 * CBIA - Costes (estimación + cálculo post-hoc)
 * v10
 *
 * Archivo: includes/cbia-costes.php
 *
 * Base:
 * - Usa tabla de precios aportada por el usuario (USD por 1M tokens):
 *   INPUT / CACHED INPUT / OUTPUT
 * - Muestra todo en EUROS (con conversión USD->EUR configurable).
 *
 * Incluye:
 * - Estimación por PALABRAS (según variante short/medium/long en cbia_settings)
 * - Conversión interna palabras->tokens (aprox)
 * - Multiplicadores de reintentos (texto / imagen) para aproximar coste real
 * - Configurar nº de llamadas de texto e imágenes por post (para estimar)
 * - Selección de modelo de IMAGEN (solo modelos del plugin)
 * - Guardado opcional de usage real por post (si el engine lo llama)
 *
 * NOTA:
 * - El bloqueo de modelos se gestiona en Config (cbia_settings['blocked_models']).
 *   Aquí SOLO lo mostramos como aviso si el modelo actual está bloqueado.
 */

if (!defined('ABSPATH')) exit;

/* =========================================================
   ===================== SETTINGS (COSTES) ==================
   ========================================================= */
if (!function_exists('cbia_costes_settings_key')) {
    function cbia_costes_settings_key() { return 'cbia_costes_settings'; }
}

if (!function_exists('cbia_costes_get_settings')) {
    function cbia_costes_get_settings() {
        $s = get_option(cbia_costes_settings_key(), array());
        return is_array($s) ? $s : array();
    }
}

if (!function_exists('cbia_costes_register_settings')) {
    function cbia_costes_register_settings() {
        register_setting('cbia_costes_settings_group', cbia_costes_settings_key());
    }
    add_action('admin_init', 'cbia_costes_register_settings');
}

/* =========================================================
   ========================= LOG ============================
   ========================================================= */
if (!function_exists('cbia_costes_log_key')) {
    function cbia_costes_log_key() { return 'cbia_costes_log'; }
}
if (!function_exists('cbia_costes_log')) {
    function cbia_costes_log($msg) {
        $log = get_option(cbia_costes_log_key(), '');
        $ts  = current_time('mysql');
        $log .= "[{$ts}] COSTES: {$msg}\n";
        if (strlen($log) > 250000) $log = substr($log, -250000);
        update_option(cbia_costes_log_key(), $log);
        if (function_exists('error_log')) error_log('[CBIA-COSTES] ' . $msg);
    }
}
if (!function_exists('cbia_costes_log_get')) {
    function cbia_costes_log_get() { return (string)get_option(cbia_costes_log_key(), ''); }
}
if (!function_exists('cbia_costes_log_clear')) {
    function cbia_costes_log_clear() { delete_option(cbia_costes_log_key()); }
}

/* =========================================================
   ===================== HELPERS (global) ===================
   ========================================================= */
if (!function_exists('cbia_get_settings')) {
    function cbia_get_settings() {
        $s = get_option('cbia_settings', array());
        return is_array($s) ? $s : array();
    }
}

/* =========================================================
   =============== BLOQUEO MODELOS (desde Config) ============
   ========================================================= */
/**
 * En Config guardas blocked_models como array asociativo: [model => 1]
 * (y no como lista).
 */
if (!function_exists('cbia_costes_is_model_blocked')) {
    function cbia_costes_is_model_blocked($model) {
        $cbia = cbia_get_settings();
        $blocked = isset($cbia['blocked_models']) && is_array($cbia['blocked_models']) ? $cbia['blocked_models'] : array();
        $model = (string)$model;

        if (isset($blocked[$model]) && (int)$blocked[$model] === 1) return true;

        // por si alguna vez viene como lista
        if (in_array($model, array_keys($blocked), true)) return true;

        return false;
    }
}

/* =========================================================
   ===================== TABLA DE PRECIOS ===================
   Valores en USD por 1.000.000 tokens (1M)
   SOLO modelos usados en el plugin (según tu Config actual):
   - Texto: gpt-4.1*, gpt-5*, gpt-5.1, gpt-5.2
   - Imagen: gpt-image-1, gpt-image-1-mini
   ========================================================= */
if (!function_exists('cbia_costes_price_table_usd_per_million')) {
    function cbia_costes_price_table_usd_per_million() {
        // input, cached_input, output  (USD por 1M tokens)
        return array(
            // TEXTO (según tu lista reducida)
            'gpt-4.1'       => array('in'=>2.00,  'cin'=>0.50,  'out'=>8.00),
            'gpt-4.1-mini'  => array('in'=>0.40,  'cin'=>0.10,  'out'=>1.60),
            'gpt-4.1-nano'  => array('in'=>0.10,  'cin'=>0.025, 'out'=>0.40),

            'gpt-5'         => array('in'=>1.25,  'cin'=>0.125, 'out'=>10.00),
            'gpt-5-mini'    => array('in'=>0.25,  'cin'=>0.025, 'out'=>2.00),
            'gpt-5-nano'    => array('in'=>0.05,  'cin'=>0.005, 'out'=>0.40),

            'gpt-5.1'       => array('in'=>1.25,  'cin'=>0.125, 'out'=>10.00),
            'gpt-5.2'       => array('in'=>1.75,  'cin'=>0.175, 'out'=>14.00),

            // IMAGEN (según tabla que pasaste)
            'gpt-image-1'       => array('in'=>10.00, 'cin'=>2.50, 'out'=>40.00),
            'gpt-image-1-mini'  => array('in'=>2.50,  'cin'=>0.25, 'out'=>8.00),
        );
    }
}

/* =========================================================
   ============== ESTIMACIÓN: palabras -> tokens ============
   ========================================================= */
if (!function_exists('cbia_costes_words_for_variant')) {
    function cbia_costes_words_for_variant($variant) {
        $variant = (string)$variant;
        if ($variant === 'short') return 1000;
        if ($variant === 'long')  return 2200;
        return 1700;
    }
}

if (!function_exists('cbia_costes_count_words')) {
    function cbia_costes_count_words($text) {
        $txt = wp_strip_all_tags((string)$text);
        $txt = preg_replace('/\s+/u', ' ', $txt);
        $txt = trim($txt);
        if ($txt === '') return 0;
        return count(preg_split('/\s+/u', $txt));
    }
}

/**
 * Conversión aproximada palabras->tokens.
 * Ajustable en settings (tokens_per_word).
 */
if (!function_exists('cbia_costes_words_to_tokens')) {
    function cbia_costes_words_to_tokens($words, $tokens_per_word = 1.30) {
        $w = max(0, (float)$words);
        $tpw = max(0.5, min(2.0, (float)$tokens_per_word));
        return (int)ceil($w * $tpw);
    }
}

/**
 * Estima tokens de input (texto):
 * - prompt_single_all (config)
 * - título
 * - overhead fijo
 */
if (!function_exists('cbia_costes_estimate_input_tokens')) {
    function cbia_costes_estimate_input_tokens($title, $settings_cbia, $tokens_per_word, $input_overhead_tokens) {
        $prompt = isset($settings_cbia['prompt_single_all']) ? (string)$settings_cbia['prompt_single_all'] : '';
        $words_prompt = cbia_costes_count_words($prompt);
        $words_title  = cbia_costes_count_words((string)$title);

        $tokens = cbia_costes_words_to_tokens($words_prompt + $words_title, $tokens_per_word);
        $tokens += (int)max(0, (int)$input_overhead_tokens);
        return $tokens;
    }
}

/**
 * Estima tokens de output (texto) según variante.
 */
if (!function_exists('cbia_costes_estimate_output_tokens')) {
    function cbia_costes_estimate_output_tokens($settings_cbia, $tokens_per_word) {
        $variant = $settings_cbia['post_length_variant'] ?? 'medium';
        $words = cbia_costes_words_for_variant($variant);
        return cbia_costes_words_to_tokens($words, $tokens_per_word);
    }
}

/**
 * Estima tokens input por imágenes (prompt imagen) *por llamada de imagen*.
 */
if (!function_exists('cbia_costes_estimate_image_prompt_input_tokens_per_call')) {
    function cbia_costes_estimate_image_prompt_input_tokens_per_call($settings_cbia, $tokens_per_word, $per_image_overhead_words) {
        $p_intro = (string)($settings_cbia['prompt_img_intro'] ?? '');
        $p_body  = (string)($settings_cbia['prompt_img_body'] ?? '');
        $p_conc  = (string)($settings_cbia['prompt_img_conclusion'] ?? '');
        $p_faq   = (string)($settings_cbia['prompt_img_faq'] ?? '');

        $sum_words = 0;
        $sum_words += max(10, cbia_costes_count_words($p_intro));
        $sum_words += max(10, cbia_costes_count_words($p_body));
        $sum_words += max(10, cbia_costes_count_words($p_conc));
        $sum_words += max(10, cbia_costes_count_words($p_faq));

        // promedio de los 4 prompts (aprox)
        $avg_words = (int)ceil($sum_words / 4);
        $avg_words += (int)max(0, (int)$per_image_overhead_words);

        return cbia_costes_words_to_tokens($avg_words, $tokens_per_word);
    }
}

/* =========================================================
   ===================== CÁLCULO DE COSTE ===================
   ========================================================= */
if (!function_exists('cbia_costes_calc_cost_eur')) {
    /**
     * @param string $model
     * @param int $in_tokens
     * @param int $out_tokens
     * @param float $usd_to_eur
     * @param float $cached_input_ratio 0..1 parte de input que se cobra como cached_input
     * @return array [eur_total, eur_in, eur_out]
     */
    function cbia_costes_calc_cost_eur($model, $in_tokens, $out_tokens, $usd_to_eur, $cached_input_ratio = 0.0) {
        $table = cbia_costes_price_table_usd_per_million();
        $model = (string)$model;

        if (!isset($table[$model])) return array(null, null, null);

        $p = $table[$model];
        $usd_in_per_m  = (float)$p['in'];
        $usd_cin_per_m = (float)$p['cin'];
        $usd_out_per_m = (float)$p['out'];

        $in_tokens  = max(0, (int)$in_tokens);
        $out_tokens = max(0, (int)$out_tokens);

        $ratio = (float)$cached_input_ratio;
        if ($ratio < 0) $ratio = 0;
        if ($ratio > 1) $ratio = 1;

        $in_cached = (int)floor($in_tokens * $ratio);
        $in_normal = $in_tokens - $in_cached;

        $usd_in  = ($in_normal / 1000000.0) * $usd_in_per_m;
        $usd_in += ($in_cached / 1000000.0) * $usd_cin_per_m;

        $usd_out = ($out_tokens / 1000000.0) * $usd_out_per_m;

        $usd_total = $usd_in + $usd_out;

        $eur_total = $usd_total * (float)$usd_to_eur;
        $eur_in    = $usd_in    * (float)$usd_to_eur;
        $eur_out   = $usd_out   * (float)$usd_to_eur;

        return array($eur_total, $eur_in, $eur_out);
    }
}

/* =========================================================
   ====== GUARDAR USAGE REAL POR POST (engine debe llamar) ===
   ========================================================= */
/**
 * El engine debe llamar a esta función tras cada llamada a OpenAI (texto o imagen).
 * Guarda tokens reales y costes estimados en meta para cálculo post-hoc.
 *
 * Ejemplo de $usage:
 * [
 *   'type' => 'text'|'image',
 *   'model' => 'gpt-4.1-mini' | 'gpt-image-1',
 *   'input_tokens' => 1234,
 *   'output_tokens' => 5678,
 *   'cached_input_tokens' => 0,
 *   'attempt' => 1,
 *   'ok' => true,
 *   'error' => '' // si falla
 * ]
 */
if (!function_exists('cbia_costes_record_usage')) {
    function cbia_costes_record_usage($post_id, $usage) {
        $post_id = (int)$post_id;
        if ($post_id <= 0) return false;
        if (!is_array($usage)) return false;

        $type  = isset($usage['type']) ? (string)$usage['type'] : 'text';
        $model = isset($usage['model']) ? (string)$usage['model'] : '';
        $in_t  = isset($usage['input_tokens']) ? (int)$usage['input_tokens'] : 0;
        $out_t = isset($usage['output_tokens']) ? (int)$usage['output_tokens'] : 0;
        $cin_t = isset($usage['cached_input_tokens']) ? (int)$usage['cached_input_tokens'] : 0;
        $ok    = !empty($usage['ok']) ? 1 : 0;
        $attempt = isset($usage['attempt']) ? (int)$usage['attempt'] : 1;
        $err   = isset($usage['error']) ? (string)$usage['error'] : '';

        $row = array(
            'ts' => current_time('mysql'),
            'type' => $type,
            'model' => $model,
            'in' => $in_t,
            'cin' => $cin_t,
            'out' => $out_t,
            'ok' => $ok,
            'attempt' => $attempt,
            'error' => $err,
        );

        $key = '_cbia_usage_rows';
        $rows = get_post_meta($post_id, $key, true);
        if (!is_array($rows)) $rows = array();
        $rows[] = $row;

        if (count($rows) > 120) $rows = array_slice($rows, -120);

        update_post_meta($post_id, $key, $rows);
        update_post_meta($post_id, '_cbia_usage_last_ts', $row['ts']);
        update_post_meta($post_id, '_cbia_usage_last_model', $model);

        return true;
    }
}

/* =========================================================
   ====== CALCULAR USAGE REAL GUARDADO (post-hoc) ============
   ========================================================= */
if (!function_exists('cbia_costes_sum_usage_for_post')) {
    function cbia_costes_sum_usage_for_post($post_id) {
        $rows = get_post_meta((int)$post_id, '_cbia_usage_rows', true);
        if (!is_array($rows) || empty($rows)) return null;

        $sum = array(
            'text_in'=>0, 'text_cin'=>0, 'text_out'=>0,
            'image_in'=>0, 'image_cin'=>0, 'image_out'=>0,
            'models'=>array(),
            'calls'=>0,
            'fails'=>0,
        );

        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $type = isset($r['type']) ? (string)$r['type'] : 'text';
            $in   = isset($r['in']) ? (int)$r['in'] : 0;
            $cin  = isset($r['cin']) ? (int)$r['cin'] : 0;
            $out  = isset($r['out']) ? (int)$r['out'] : 0;
            $ok   = !empty($r['ok']) ? 1 : 0;
            $model= isset($r['model']) ? (string)$r['model'] : '';

            $sum['calls']++;
            if (!$ok) $sum['fails']++;

            if ($model !== '') $sum['models'][$model] = true;

            if ($type === 'image') {
                $sum['image_in']  += max(0,$in);
                $sum['image_cin'] += max(0,$cin);
                $sum['image_out'] += max(0,$out);
            } else {
                $sum['text_in']  += max(0,$in);
                $sum['text_cin'] += max(0,$cin);
                $sum['text_out'] += max(0,$out);
            }
        }

        $sum['models'] = array_keys($sum['models']);
        return $sum;
    }
}

/* =========================================================
   ===================== UI TAB: COSTES =====================
   ========================================================= */
if (!function_exists('cbia_render_tab_costes')) {
    function cbia_render_tab_costes() {
        if (!current_user_can('manage_options')) return;

        $cbia = cbia_get_settings();
        $cost = cbia_costes_get_settings();

        $defaults = array(
            'usd_to_eur' => 0.92,
            'tokens_per_word' => 1.30,
            'input_overhead_tokens' => 350,
            'per_image_overhead_words' => 18,
            'cached_input_ratio' => 0.0, // 0..1

            // Multiplicadores para aproximar fallos/reintentos
            'mult_text'  => 1.00,
            'mult_image' => 1.00,

            // NUEVO: llamadas por post (estimación)
            'text_calls_per_post'  => 1, // nº de llamadas de texto por post
            'image_calls_per_post' => 0, // 0 => usa images_limit

            // NUEVO: modelo imagen (solo plugin)
            'image_model' => 'gpt-image-1-mini',

            // NUEVO: output tokens por llamada de imagen (opcional)
            'image_output_tokens_per_call' => 0,
        );
        $cost = array_merge($defaults, $cost);

        $table = cbia_costes_price_table_usd_per_million();

        $model_text_current = isset($cbia['openai_model']) ? (string)$cbia['openai_model'] : 'gpt-4.1-mini';
        if (!isset($table[$model_text_current])) $model_text_current = 'gpt-4.1-mini';

        $model_img_current = isset($cost['image_model']) ? (string)$cost['image_model'] : 'gpt-image-1-mini';
        if (!isset($table[$model_img_current])) $model_img_current = 'gpt-image-1-mini';

        $notice = '';

        /* ===== Handle POST ===== */
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $u = wp_unslash($_POST);

            if (!empty($u['cbia_form']) && $u['cbia_form'] === 'costes_settings' && check_admin_referer('cbia_costes_settings_nonce')) {

                $cost['usd_to_eur'] = isset($u['usd_to_eur']) ? (float)str_replace(',', '.', (string)$u['usd_to_eur']) : $cost['usd_to_eur'];
                if ($cost['usd_to_eur'] <= 0) $cost['usd_to_eur'] = 0.92;

                $cost['tokens_per_word'] = isset($u['tokens_per_word']) ? (float)str_replace(',', '.', (string)$u['tokens_per_word']) : $cost['tokens_per_word'];
                if ($cost['tokens_per_word'] < 0.5) $cost['tokens_per_word'] = 0.5;
                if ($cost['tokens_per_word'] > 2.0) $cost['tokens_per_word'] = 2.0;

                $cost['input_overhead_tokens'] = isset($u['input_overhead_tokens']) ? (int)$u['input_overhead_tokens'] : (int)$cost['input_overhead_tokens'];
                if ($cost['input_overhead_tokens'] < 0) $cost['input_overhead_tokens'] = 0;
                if ($cost['input_overhead_tokens'] > 5000) $cost['input_overhead_tokens'] = 5000;

                $cost['per_image_overhead_words'] = isset($u['per_image_overhead_words']) ? (int)$u['per_image_overhead_words'] : (int)$cost['per_image_overhead_words'];
                if ($cost['per_image_overhead_words'] < 0) $cost['per_image_overhead_words'] = 0;
                if ($cost['per_image_overhead_words'] > 300) $cost['per_image_overhead_words'] = 300;

                $cost['cached_input_ratio'] = isset($u['cached_input_ratio']) ? (float)str_replace(',', '.', (string)$u['cached_input_ratio']) : (float)$cost['cached_input_ratio'];
                if ($cost['cached_input_ratio'] < 0) $cost['cached_input_ratio'] = 0;
                if ($cost['cached_input_ratio'] > 1) $cost['cached_input_ratio'] = 1;

                $cost['mult_text'] = isset($u['mult_text']) ? (float)str_replace(',', '.', (string)$u['mult_text']) : (float)$cost['mult_text'];
                if ($cost['mult_text'] < 1.0) $cost['mult_text'] = 1.0;
                if ($cost['mult_text'] > 5.0) $cost['mult_text'] = 5.0;

                $cost['mult_image'] = isset($u['mult_image']) ? (float)str_replace(',', '.', (string)$u['mult_image']) : (float)$cost['mult_image'];
                if ($cost['mult_image'] < 1.0) $cost['mult_image'] = 1.0;
                if ($cost['mult_image'] > 5.0) $cost['mult_image'] = 5.0;

                // NUEVO: nº llamadas texto/imagen
                $cost['text_calls_per_post'] = isset($u['text_calls_per_post']) ? (int)$u['text_calls_per_post'] : (int)$cost['text_calls_per_post'];
                if ($cost['text_calls_per_post'] < 1) $cost['text_calls_per_post'] = 1;
                if ($cost['text_calls_per_post'] > 20) $cost['text_calls_per_post'] = 20;

                $cost['image_calls_per_post'] = isset($u['image_calls_per_post']) ? (int)$u['image_calls_per_post'] : (int)$cost['image_calls_per_post'];
                if ($cost['image_calls_per_post'] < 0) $cost['image_calls_per_post'] = 0;
                if ($cost['image_calls_per_post'] > 20) $cost['image_calls_per_post'] = 20;

                // NUEVO: modelo imagen (solo 2)
                $im = isset($u['image_model']) ? sanitize_text_field((string)$u['image_model']) : (string)$cost['image_model'];
                if (!isset($table[$im]) || ($im !== 'gpt-image-1' && $im !== 'gpt-image-1-mini')) {
                    $im = 'gpt-image-1-mini';
                }
                $cost['image_model'] = $im;

                // NUEVO: output tokens por imagen
                $cost['image_output_tokens_per_call'] = isset($u['image_output_tokens_per_call']) ? (int)$u['image_output_tokens_per_call'] : (int)$cost['image_output_tokens_per_call'];
                if ($cost['image_output_tokens_per_call'] < 0) $cost['image_output_tokens_per_call'] = 0;
                if ($cost['image_output_tokens_per_call'] > 50000) $cost['image_output_tokens_per_call'] = 50000;

                update_option(cbia_costes_settings_key(), $cost);
                $notice = 'saved';
                cbia_costes_log("Configuración guardada.");
            }

            if (!empty($u['cbia_form']) && $u['cbia_form'] === 'costes_actions' && check_admin_referer('cbia_costes_actions_nonce')) {
                $action = isset($u['cbia_action']) ? sanitize_text_field((string)$u['cbia_action']) : '';

                if ($action === 'clear_log') {
                    cbia_costes_log_clear();
                    cbia_costes_log("Log limpiado manualmente.");
                    $notice = 'log';
                }

                if ($action === 'calc_last') {
                    $n = isset($u['calc_last_n']) ? (int)$u['calc_last_n'] : 20;
                    $n = max(1, min(200, $n));

                    $only_cbia = !empty($u['calc_only_cbia']) ? true : false;
                    $use_est_if_missing = !empty($u['calc_estimate_if_missing']) ? true : false;

                    $sum = cbia_costes_calc_last_posts($n, $only_cbia, $use_est_if_missing, $cost, $cbia);
                    if ($sum) {
                        cbia_costes_log("Cálculo últimos {$n}: posts={$sum['posts']} real={$sum['real_posts']} est={$sum['est_posts']} total€=" . number_format((float)$sum['eur_total'], 4, ',', '.'));
                    } else {
                        cbia_costes_log("Cálculo últimos {$n}: sin resultados.");
                    }
                    $notice = 'calc';
                }
            }
        }

        // refrescar
        $cost = array_merge($defaults, cbia_costes_get_settings());
        $log  = cbia_costes_log_get();

        $model_text_current = isset($cbia['openai_model']) ? (string)$cbia['openai_model'] : 'gpt-4.1-mini';
        if (!isset($table[$model_text_current])) $model_text_current = 'gpt-4.1-mini';

        $model_img_current = isset($cost['image_model']) ? (string)$cost['image_model'] : 'gpt-image-1-mini';
        if (!isset($table[$model_img_current])) $model_img_current = 'gpt-image-1-mini';

        // llamadas por post
        $text_calls = max(1, (int)$cost['text_calls_per_post']);
        $img_calls  = (int)$cost['image_calls_per_post'];

        // si img_calls = 0, usamos images_limit de Config (1..4)
        if ($img_calls <= 0) {
            $img_calls = isset($cbia['images_limit']) ? (int)$cbia['images_limit'] : 3;
        }
        $img_calls = max(0, min(20, $img_calls));

        // Estimación por defecto: 1 llamada de texto => input+output, multiplicado por text_calls
        $in_tokens_text_per_call  = cbia_costes_estimate_input_tokens('{title}', $cbia, $cost['tokens_per_word'], $cost['input_overhead_tokens']);
        $out_tokens_text_per_call = cbia_costes_estimate_output_tokens($cbia, $cost['tokens_per_word']);

        // imagen: estimar input por llamada, y output configurable
        $in_tokens_img_per_call   = cbia_costes_estimate_image_prompt_input_tokens_per_call($cbia, $cost['tokens_per_word'], $cost['per_image_overhead_words']);
        $out_tokens_img_per_call  = max(0, (int)$cost['image_output_tokens_per_call']);

        // Multiplicadores reintentos (aplicamos a tokens, y luego multiplicamos por nº llamadas)
        $in_tokens_text_per_call_m  = (int)ceil($in_tokens_text_per_call  * (float)$cost['mult_text']);
        $out_tokens_text_per_call_m = (int)ceil($out_tokens_text_per_call * (float)$cost['mult_text']);

        $in_tokens_img_per_call_m   = (int)ceil($in_tokens_img_per_call   * (float)$cost['mult_image']);
        $out_tokens_img_per_call_m  = (int)ceil($out_tokens_img_per_call  * (float)$cost['mult_image']);

        // Totales por post (estimación)
        $in_tokens_text_total  = $in_tokens_text_per_call_m  * $text_calls;
        $out_tokens_text_total = $out_tokens_text_per_call_m * $text_calls;

        $in_tokens_img_total   = $in_tokens_img_per_call_m   * $img_calls;
        $out_tokens_img_total  = $out_tokens_img_per_call_m  * $img_calls;

        // Coste texto (modelo de texto de Config)
        list($eur_total_text, $eur_in_text, $eur_out_text) =
            cbia_costes_calc_cost_eur($model_text_current, $in_tokens_text_total, $out_tokens_text_total, $cost['usd_to_eur'], $cost['cached_input_ratio']);

        // Coste imágenes (modelo imagen seleccionado en esta pestaña)
        list($eur_total_img, $eur_in_img, $eur_out_img) =
            cbia_costes_calc_cost_eur($model_img_current, $in_tokens_img_total, $out_tokens_img_total, $cost['usd_to_eur'], $cost['cached_input_ratio']);

        $eur_total_est = null;
        if ($eur_total_text !== null && $eur_total_img !== null) {
            $eur_total_est = $eur_total_text + $eur_total_img;
        }

        // Notices
        if ($notice === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>Configuración de Costes guardada.</p></div>';
        } elseif ($notice === 'log') {
            echo '<div class="notice notice-success is-dismissible"><p>Log limpiado.</p></div>';
        } elseif ($notice === 'calc') {
            echo '<div class="notice notice-success is-dismissible"><p>Cálculo ejecutado. Revisa el log.</p></div>';
        }

        ?>
        <div class="wrap" style="padding-left:0;">
            <h2>Costes</h2>

            <h3>Estimación rápida (según Config actual)</h3>
            <table class="widefat striped" style="max-width:980px;">
                <tbody>
                    <tr>
                        <td style="width:280px;"><strong>Modelo TEXTO (Config)</strong></td>
                        <td>
                            <code><?php echo esc_html($model_text_current); ?></code>
                            <?php echo cbia_costes_is_model_blocked($model_text_current) ? '<span style="color:#b70000;font-weight:700;">(BLOQUEADO en Config)</span>' : ''; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Modelo IMAGEN (Costes)</strong></td>
                        <td><code><?php echo esc_html($model_img_current); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Llamadas texto por post</strong></td>
                        <td><code><?php echo esc_html((int)$text_calls); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Llamadas imagen por post</strong></td>
                        <td><code><?php echo esc_html((int)$img_calls); ?></code></td>
                    </tr>

                    <tr>
                        <td><strong>Input tokens TEXTO (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$in_tokens_text_total); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Output tokens TEXTO (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$out_tokens_text_total); ?></code></td>
                    </tr>

                    <tr>
                        <td><strong>Input tokens IMAGEN (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$in_tokens_img_total); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Output tokens IMAGEN (total post)</strong></td>
                        <td><code><?php echo esc_html((int)$out_tokens_img_total); ?></code> <span class="description">(si lo dejas a 0, solo estimamos input)</span></td>
                    </tr>

                    <tr>
                        <td><strong>Coste estimado (TEXTO)</strong></td>
                        <td>
                            <?php
                            echo ($eur_total_text === null)
                                ? '<span style="color:#b70000;">Modelo no encontrado en tabla</span>'
                                : '<strong>' . esc_html(number_format((float)$eur_total_text, 4, ',', '.')) . ' €</strong> <span class="description">(in ' . number_format((float)$eur_in_text, 4, ',', '.') . ' € | out ' . number_format((float)$eur_out_text, 4, ',', '.') . ' €)</span>';
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>Coste estimado (IMÁGENES)</strong></td>
                        <td>
                            <?php
                            echo ($eur_total_img === null)
                                ? '<span style="color:#b70000;">Modelo no encontrado en tabla</span>'
                                : '<strong>' . esc_html(number_format((float)$eur_total_img, 4, ',', '.')) . ' €</strong> <span class="description">(in ' . number_format((float)$eur_in_img, 4, ',', '.') . ' € | out ' . number_format((float)$eur_out_img, 4, ',', '.') . ' €)</span>';
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <td><strong>Coste total estimado</strong></td>
                        <td>
                            <?php
                            echo ($eur_total_est === null)
                                ? '<span style="color:#b70000;">No se pudo estimar (modelo no en tabla)</span>'
                                : '<strong style="font-size:16px;">' . esc_html(number_format((float)$eur_total_est, 4, ',', '.')) . ' €</strong>';
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <hr/>

            <h3>Configuración</h3>
            <form method="post" action="" autocomplete="off">
                <input type="hidden" name="cbia_form" value="costes_settings" />
                <?php wp_nonce_field('cbia_costes_settings_nonce'); ?>

                <table class="form-table" style="max-width:980px;">
                    <tr>
                        <th>Conversión USD → EUR</th>
                        <td>
                            <input type="number" step="0.01" min="0.5" max="1.5" name="usd_to_eur" value="<?php echo esc_attr((string)$cost['usd_to_eur']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Tokens por palabra (aprox)</th>
                        <td>
                            <input type="number" step="0.01" min="0.5" max="2" name="tokens_per_word" value="<?php echo esc_attr((string)$cost['tokens_per_word']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Overhead input (tokens) por llamada de texto</th>
                        <td>
                            <input type="number" min="0" max="5000" name="input_overhead_tokens" value="<?php echo esc_attr((int)$cost['input_overhead_tokens']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Overhead por imagen (palabras) por llamada</th>
                        <td>
                            <input type="number" min="0" max="300" name="per_image_overhead_words" value="<?php echo esc_attr((int)$cost['per_image_overhead_words']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Ratio cached input (0..1)</th>
                        <td>
                            <input type="number" step="0.05" min="0" max="1" name="cached_input_ratio" value="<?php echo esc_attr((string)$cost['cached_input_ratio']); ?>" style="width:120px;" />
                        </td>
                    </tr>

                    <tr>
                        <th>Multiplicador reintentos (texto)</th>
                        <td>
                            <input type="number" step="0.05" min="1" max="5" name="mult_text" value="<?php echo esc_attr((string)$cost['mult_text']); ?>" style="width:120px;" />
                        </td>
                    </tr>
                    <tr>
                        <th>Multiplicador reintentos (imágenes)</th>
                        <td>
                            <input type="number" step="0.05" min="1" max="5" name="mult_image" value="<?php echo esc_attr((string)$cost['mult_image']); ?>" style="width:120px;" />
                        </td>
                    </tr>

                    <tr>
                        <th>Nº llamadas de TEXTO por post</th>
                        <td>
                            <input type="number" min="1" max="20" name="text_calls_per_post" value="<?php echo esc_attr((int)$cost['text_calls_per_post']); ?>" style="width:120px;" />
                            <p class="description">Si tu engine hace más de 1 llamada para el texto (por ejemplo: 2 pasos), súbelo aquí.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Nº llamadas de IMAGEN por post</th>
                        <td>
                            <input type="number" min="0" max="20" name="image_calls_per_post" value="<?php echo esc_attr((int)$cost['image_calls_per_post']); ?>" style="width:120px;" />
                            <p class="description">Si pones 0, se usa <code>images_limit</code> de Config.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Modelo de imagen</th>
                        <td>
                            <select name="image_model" style="width:240px;">
                                <option value="gpt-image-1-mini" <?php selected($model_img_current, 'gpt-image-1-mini'); ?>>gpt-image-1-mini</option>
                                <option value="gpt-image-1" <?php selected($model_img_current, 'gpt-image-1'); ?>>gpt-image-1</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>Output tokens por llamada de imagen (opcional)</th>
                        <td>
                            <input type="number" min="0" max="50000" name="image_output_tokens_per_call" value="<?php echo esc_attr((int)$cost['image_output_tokens_per_call']); ?>" style="width:120px;" />
                            <p class="description">Si lo dejas en 0, la estimación de imagen contará básicamente el input (más conservador). Si quieres afinar, ajusta aquí.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary">Guardar configuración de Costes</button>
                </p>
            </form>

            <hr/>

            <h3>Acciones (post-hoc)</h3>
            <form method="post" action="" autocomplete="off">
                <input type="hidden" name="cbia_form" value="costes_actions" />
                <?php wp_nonce_field('cbia_costes_actions_nonce'); ?>

                <table class="form-table" style="max-width:980px;">
                    <tr>
                        <th>Calcular últimos N posts</th>
                        <td>
                            <input type="number" name="calc_last_n" min="1" max="200" value="20" style="width:120px;" />
                            <label style="margin-left:14px;">
                                <input type="checkbox" name="calc_only_cbia" value="1" checked />
                                Solo posts del plugin (<code>_cbia_created=1</code>)
                            </label>
                            <label style="margin-left:14px;">
                                <input type="checkbox" name="calc_estimate_if_missing" value="1" checked />
                                Si no hay usage real, usar estimación
                            </label>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary" name="cbia_action" value="calc_last">Calcular</button>
                    <button type="submit" class="button button-secondary" name="cbia_action" value="clear_log" style="margin-left:8px;">Limpiar log</button>
                </p>
            </form>

            <h3>Log Costes</h3>
            <textarea id="cbia-costes-log" rows="14" cols="120" readonly style="background:#f9f9f9;width:100%;"><?php echo esc_textarea($log); ?></textarea>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const logBox = document.getElementById('cbia-costes-log');
                function refreshLog(){
                    if (typeof ajaxurl === 'undefined') return;
                    fetch(ajaxurl + '?action=cbia_get_costes_log', { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
                            if(data && data.success && logBox){
                                logBox.value = data.data || '';
                                logBox.scrollTop = logBox.scrollHeight;
                            }
                        })
                        .catch(() => {});
                }
                setInterval(refreshLog, 3000);
            });
            </script>
        </div>
        <?php
    }
}

/* =========================================================
   ======================= AJAX LOG =========================
   ========================================================= */
add_action('wp_ajax_cbia_get_costes_log', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    wp_send_json_success(cbia_costes_log_get());
});

/* =========================================================
   ============ CÁLCULO ÚLTIMOS POSTS (real/estimado) =======
   ========================================================= */
if (!function_exists('cbia_costes_calc_last_posts')) {
    function cbia_costes_calc_last_posts($n, $only_cbia, $use_est_if_missing, $cost_settings, $cbia_settings) {
        $n = max(1, min(200, (int)$n));

        $args = array(
            'post_type'      => 'post',
            'posts_per_page' => $n,
            'post_status'    => array('publish','future','draft','pending'),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        );

        if ($only_cbia) {
            $args['meta_query'] = array(
                array('key' => '_cbia_created', 'value' => '1', 'compare' => '=')
            );
        }

        $q = new WP_Query($args);
        $ids = !empty($q->posts) ? $q->posts : array();
        if (empty($ids)) return null;

        $usd_to_eur = (float)$cost_settings['usd_to_eur'];
        $cached_ratio = (float)$cost_settings['cached_input_ratio'];

        $total_eur = 0.0;
        $real_posts = 0;
        $est_posts  = 0;

        foreach ($ids as $post_id) {
            $sum = cbia_costes_sum_usage_for_post($post_id);

            if (is_array($sum)) {
                // Si hay usage real: intentamos sumar por modelo, pero para simplificar:
                // - si hay varios modelos, calculamos todo con el primero disponible en la tabla.
                $models = isset($sum['models']) ? (array)$sum['models'] : array();
                $table = cbia_costes_price_table_usd_per_million();

                $model = '';
                foreach ($models as $m) {
                    if (isset($table[$m])) { $model = (string)$m; break; }
                }
                if ($model === '') {
                    $model = (string)($cbia_settings['openai_model'] ?? 'gpt-4.1-mini');
                }
                if (!isset($table[$model])) {
                    // si no está, intentamos estimación si está permitido
                    if ($use_est_if_missing) {
                        $est = cbia_costes_estimate_for_post($post_id, $cost_settings, $cbia_settings);
                        if ($est !== null) { $total_eur += (float)$est; $est_posts++; }
                    }
                    continue;
                }

                $in_total  = (int)$sum['text_in'] + (int)$sum['image_in'];
                $cin_total = (int)$sum['text_cin'] + (int)$sum['image_cin'];
                $out_total = (int)$sum['text_out'] + (int)$sum['image_out'];

                $ratio = 0.0;
                if ($in_total > 0 && $cin_total > 0) {
                    $ratio = min(1.0, max(0.0, $cin_total / (float)max(1,$in_total)));
                } else {
                    $ratio = $cached_ratio;
                }

                list($eur, $eur_in, $eur_out) = cbia_costes_calc_cost_eur($model, $in_total, $out_total, $usd_to_eur, $ratio);
                if ($eur !== null) {
                    $total_eur += (float)$eur;
                    $real_posts++;
                } else {
                    if ($use_est_if_missing) {
                        $est = cbia_costes_estimate_for_post($post_id, $cost_settings, $cbia_settings);
                        if ($est !== null) { $total_eur += (float)$est; $est_posts++; }
                    }
                }

            } else {
                if ($use_est_if_missing) {
                    $est = cbia_costes_estimate_for_post($post_id, $cost_settings, $cbia_settings);
                    if ($est !== null) { $total_eur += (float)$est; $est_posts++; }
                }
            }
        }

        return array(
            'posts' => count($ids),
            'real_posts' => $real_posts,
            'est_posts' => $est_posts,
            'eur_total' => $total_eur,
        );
    }
}

if (!function_exists('cbia_costes_estimate_for_post')) {
    function cbia_costes_estimate_for_post($post_id, $cost_settings, $cbia_settings) {
        $table = cbia_costes_price_table_usd_per_million();

        $title = get_the_title((int)$post_id);
        if (!$title) $title = '{title}';

        $model_text = (string)($cbia_settings['openai_model'] ?? 'gpt-4.1-mini');
        if (!isset($table[$model_text])) $model_text = 'gpt-4.1-mini';

        $model_img = (string)($cost_settings['image_model'] ?? 'gpt-image-1-mini');
        if (!isset($table[$model_img])) $model_img = 'gpt-image-1-mini';

        $text_calls = max(1, (int)($cost_settings['text_calls_per_post'] ?? 1));
        $img_calls  = (int)($cost_settings['image_calls_per_post'] ?? 0);
        if ($img_calls <= 0) {
            $img_calls = isset($cbia_settings['images_limit']) ? (int)$cbia_settings['images_limit'] : 3;
        }
        $img_calls = max(0, min(20, $img_calls));

        // TEXTO (por llamada)
        $in_text  = cbia_costes_estimate_input_tokens($title, $cbia_settings, $cost_settings['tokens_per_word'], $cost_settings['input_overhead_tokens']);
        $out_text = cbia_costes_estimate_output_tokens($cbia_settings, $cost_settings['tokens_per_word']);

        $in_text  = (int)ceil($in_text  * (float)$cost_settings['mult_text']);
        $out_text = (int)ceil($out_text * (float)$cost_settings['mult_text']);

        $in_text_total  = $in_text  * $text_calls;
        $out_text_total = $out_text * $text_calls;

        list($eur_text, $eur_in_text, $eur_out_text) =
            cbia_costes_calc_cost_eur($model_text, $in_text_total, $out_text_total, (float)$cost_settings['usd_to_eur'], (float)$cost_settings['cached_input_ratio']);
        if ($eur_text === null) return null;

        // IMAGEN (por llamada)
        $in_img_per_call = cbia_costes_estimate_image_prompt_input_tokens_per_call($cbia_settings, $cost_settings['tokens_per_word'], $cost_settings['per_image_overhead_words']);
        $out_img_per_call = max(0, (int)($cost_settings['image_output_tokens_per_call'] ?? 0));

        $in_img_per_call  = (int)ceil($in_img_per_call  * (float)$cost_settings['mult_image']);
        $out_img_per_call = (int)ceil($out_img_per_call * (float)$cost_settings['mult_image']);

        $in_img_total  = $in_img_per_call  * $img_calls;
        $out_img_total = $out_img_per_call * $img_calls;

        list($eur_img, $eur_in_img, $eur_out_img) =
            cbia_costes_calc_cost_eur($model_img, $in_img_total, $out_img_total, (float)$cost_settings['usd_to_eur'], (float)$cost_settings['cached_input_ratio']);
        if ($eur_img === null) $eur_img = 0.0;

        return (float)$eur_text + (float)$eur_img;
    }
}

/* ------------------------- FIN includes/cbia-costes.php ------------------------- */
