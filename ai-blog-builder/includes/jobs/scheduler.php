<?php
/**
 * Scheduler/job hooks (wrapper).
 *
 * Mantiene compatibilidad con el flujo legacy de cron.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('CBIA_Scheduler')) {
    class CBIA_Scheduler {
        public function register() {
            // Por ahora, no aÃ±adimos hooks extra: el legacy ya registra cbia_generation_event.
            // Este mÃ©todo queda listo para centralizar el cron en prÃ³ximas iteraciones.
        }
    }
}
