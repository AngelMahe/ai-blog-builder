<?php
/**
 * Admin router: register tabs and menus.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Admin_Router')) {
    class CBIA_Admin_Router {
        private $tabs = array();

        public function register_tab($key, $label, $callback, $priority = 10) {
            $this->tabs[$key] = array(
                'label' => $label,
                'callback' => $callback,
                'priority' => (int)$priority,
            );
        }

        public function register_tab_object($tab_object) {
            if (!is_object($tab_object)) return;
            if (!method_exists($tab_object, 'get_key')) return;
            if (!method_exists($tab_object, 'get_label')) return;
            if (!method_exists($tab_object, 'render')) return;

            $key = (string)$tab_object->get_key();
            $label = (string)$tab_object->get_label();
            $callback = array($tab_object, 'render');
            $priority = method_exists($tab_object, 'get_priority') ? (int)$tab_object->get_priority() : 10;

            if ($key === '' || $label === '') return;
            $this->register_tab($key, $label, $callback, $priority);
        }

        public function register() {
            add_action('admin_menu', array($this, 'on_admin_menu'));
        }

        public function on_admin_menu() {
            add_menu_page(
                'AI Blog Builder',
                'AI Blog Builder',
                'manage_options',
                'cbia',
                array($this, 'render_page'),
                'dashicons-edit-page',
                56
            );
        }

        public function get_tabs() {
            if (!empty($this->tabs)) {
                $tabs = $this->tabs;
                $tabs = apply_filters('cbia_admin_tabs', $tabs);
                uasort($tabs, function($a, $b) {
                    return (int)($a['priority'] ?? 10) <=> (int)($b['priority'] ?? 10);
                });
                return $tabs;
            }

            if (function_exists('cbia_get_admin_tabs')) {
                $legacy = cbia_get_admin_tabs();
                if (is_array($legacy)) {
                    $tabs = array();
                    foreach ($legacy as $key => $tab) {
                        $tabs[$key] = array(
                            'label' => (string)($tab['label'] ?? $key),
                            'callback' => $tab['render'] ?? null,
                        );
                    }
                    $tabs = apply_filters('cbia_admin_tabs', $tabs);
                    uasort($tabs, function($a, $b) {
                        return (int)($a['priority'] ?? 10) <=> (int)($b['priority'] ?? 10);
                    });
                    return $tabs;
                }
            }

            $tabs = apply_filters('cbia_admin_tabs', array());
            uasort($tabs, function($a, $b) {
                return (int)($a['priority'] ?? 10) <=> (int)($b['priority'] ?? 10);
            });
            return $tabs;
        }

        public function get_current_tab($tabs) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab navigation
            $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'config';
            if (isset($tabs[$tab])) return $tab;
            return array_key_first($tabs) ?: 'config';
        }

        public function render_page() {
            if (!current_user_can('manage_options')) {
                wp_die('No tienes permisos para ver esta pagina.');
            }

            $tabs = $this->get_tabs();
            if (empty($tabs)) {
                echo '<div class="wrap"><p>No hay pestanas registradas.</p></div>';
                return;
            }

            $current = $this->get_current_tab($tabs);

            $logo = plugins_url('assets/images/ai-blog-builder-ico.svg', CBIA_PLUGIN_FILE);
            echo '<div class="wrap cbia-shell">';
            echo '<h1 class="cbia-header"><img class="cbia-logo" src="' . esc_url($logo) . '" alt="AI Blog Builder" /> AI Blog Builder <small style="font-weight:normal;opacity:.7;">v' . esc_html(defined('CBIA_VERSION') ? CBIA_VERSION : '1.0.0') . '</small></h1>';

            echo '<h2 class="nav-tab-wrapper">';
            foreach ($tabs as $key => $t) {
                $url = admin_url('admin.php?page=cbia&tab=' . $key);
                $cls = 'nav-tab' . ($key === $current ? ' nav-tab-active' : '');
                echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($t['label']) . '</a>';
            }
            echo '</h2>';

            do_action('cbia_admin_before_render', $current, $tabs);

            $render = $tabs[$current]['callback'] ?? null;
            if (is_callable($render)) {
                call_user_func($render);
            } else {
                echo '<p>No se pudo cargar esta pestana.</p>';
                if (function_exists('cbia_log')) {
                    cbia_log('Render no callable para tab: ' . $current, 'ERROR');
                }
            }

            do_action('cbia_admin_after_render', $current, $tabs);
            echo '</div>';
        }
    }
}
