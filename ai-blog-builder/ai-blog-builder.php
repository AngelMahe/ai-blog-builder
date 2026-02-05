<?php
/**
 * Plugin Name: AI Blog Builder
 * Description: Genera entradas con IA (texto + 1 imagen destacada) con reanudacion por checkpoint y log en vivo.
 * Version: 1.0.1
 *
 * Author: CBIA Studio
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-blog-builder
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

// Constantes base
if (!defined('CBIA_VERSION')) define('CBIA_VERSION', '1.0.1');
if (!defined('CBIA_PLUGIN_FILE')) define('CBIA_PLUGIN_FILE', __FILE__);
if (!defined('CBIA_PLUGIN_DIR')) define('CBIA_PLUGIN_DIR', plugin_dir_path(__FILE__));
if (!defined('CBIA_PLUGIN_URL')) define('CBIA_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('CBIA_INCLUDES_DIR')) define('CBIA_INCLUDES_DIR', CBIA_PLUGIN_DIR . 'includes/');

if (!defined('CBIA_OPTION_SETTINGS')) define('CBIA_OPTION_SETTINGS', 'cbia_settings');
if (!defined('CBIA_OPTION_LOG')) define('CBIA_OPTION_LOG', 'cbia_activity_log');
if (!defined('CBIA_OPTION_LOG_COUNTER')) define('CBIA_OPTION_LOG_COUNTER', 'cbia_log_counter');
if (!defined('CBIA_OPTION_STOP')) define('CBIA_OPTION_STOP', 'cbia_stop_generation');
if (!defined('CBIA_OPTION_CHECKPOINT')) define('CBIA_OPTION_CHECKPOINT', 'cbia_checkpoint');

// Bootstrap estructura FREE
$cbia_bootstrap = CBIA_INCLUDES_DIR . 'core/bootstrap.php';
if (file_exists($cbia_bootstrap)) {
    require_once $cbia_bootstrap;
}

// Registrar loader nuevo (router + scheduler)
add_action('plugins_loaded', function () {
    if (class_exists('CBIA_Loader') && function_exists('cbia_container')) {
        $container = cbia_container();
        $router = $container ? $container->get('admin_router') : null;
        $scheduler = $container ? $container->get('scheduler') : null;
        $loader = new CBIA_Loader($router, $scheduler);
        $loader->register();
    }
});

// UI header logo (FREE)
if (!function_exists('cbia_admin_header_with_logo')) {
    function cbia_admin_header_with_logo() {
        $logo = plugins_url('assets/images/ai-blog-builder-ico.svg', CBIA_PLUGIN_FILE);
        echo '<div class="wrap cbia-shell">';
        echo '<h1><img class="cbia-logo" src="' . esc_url($logo) . '" alt="AI Blog Builder" /> AI Blog Builder <small style="font-weight:normal;opacity:.7;">v' . esc_html(defined('CBIA_VERSION') ? CBIA_VERSION : '1.0.1') . '</small></h1>';
    }
}

// Activacion: asegurar options base
register_activation_hook(__FILE__, function () {
    if (function_exists('cbia_get_default_settings') && get_option(CBIA_OPTION_SETTINGS, null) === null) {
        update_option(CBIA_OPTION_SETTINGS, cbia_get_default_settings(), false);
    }
    if (get_option(CBIA_OPTION_LOG, null) === null) {
        update_option(CBIA_OPTION_LOG, '', false);
    }
    if (get_option(CBIA_OPTION_LOG_COUNTER, null) === null) {
        update_option(CBIA_OPTION_LOG_COUNTER, 0, false);
    }
    if (get_option(CBIA_OPTION_STOP, null) === null) {
        update_option(CBIA_OPTION_STOP, 0, false);
    }
    if (get_option(CBIA_OPTION_CHECKPOINT, null) === null) {
        update_option(CBIA_OPTION_CHECKPOINT, array(), false);
    }
});

// Cargar modulo engine (define helpers globales)
$cbia_modules = array(
    CBIA_INCLUDES_DIR . 'engine/engine.php',
);
foreach ($cbia_modules as $cbia_file) {
    if (file_exists($cbia_file)) {
        require_once $cbia_file;
    } else {
        if (function_exists('cbia_log')) {
            cbia_log('No se encontro el modulo requerido: ' . basename($cbia_file), 'ERROR');
        }
    }
}

// Registrar hooks core (AJAX, assets, etc.)
if (function_exists('cbia_register_core_hooks')) {
    cbia_register_core_hooks();
}
