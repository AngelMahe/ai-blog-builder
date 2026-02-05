<?php
/**
 * Runtime helpers (FREE 1.0.0)
 *
 * Keep execution time generous for long tasks.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_try_unlimited_runtime')) {
    /**
     * Best-effort to remove execution time limits.
     * Safe to call multiple times.
     */
    function cbia_try_unlimited_runtime() {
        if (function_exists('set_time_limit')) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
            @set_time_limit(0);
        }
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @ini_set('max_execution_time', '0');
    }
}
