<?php
// File: includes/integrations/yoast-legacy.php
if (!defined('ABSPATH')) exit;

/**
 * CBIA - YOAST (FREE minimal)
 *
 * - Genera metadescripcion y keyphrase basicas.
 * - Calcula puntuaciones SEO/legibilidad (heuristica).
 * - Dispara reindex best-effort si Yoast esta activo.
 */

if (!function_exists('cbia_yoast_log_key')) {
	function cbia_yoast_log_key() { return 'cbia_yoast_log'; }
}

if (!function_exists('cbia_yoast_log')) {
	function cbia_yoast_log($msg) {
		if (function_exists('cbia_log')) {
			cbia_log('[YOAST] ' . (string)$msg, 'INFO');
			return;
		}
		$log = (string)get_option(cbia_yoast_log_key(), '');
		$ts  = current_time('mysql');
		$log .= "[{$ts}] {$msg}\n";
		if (strlen($log) > 250000) $log = substr($log, -250000);
		update_option(cbia_yoast_log_key(), $log, false);
	}
}

/* =========================================================
   ====================== HELPERS (safe) ====================
   ========================================================= */

if (!function_exists('cbia_yoast_first_paragraph_text')) {
	function cbia_yoast_first_paragraph_text($html) {
		if (preg_match('/<p[^>]*>(.*?)<\/p>/is', (string)$html, $m)) return wp_strip_all_tags($m[1]);
		return wp_strip_all_tags((string)$html);
	}
}

if (!function_exists('cbia_yoast_generate_focus_keyphrase')) {
	function cbia_yoast_generate_focus_keyphrase($title, $content) {
		if (function_exists('cbia_generate_focus_keyphrase')) return cbia_generate_focus_keyphrase($title, $content);
		$words = preg_split('/\s+/', wp_strip_all_tags((string)$title));
		$words = array_filter($words);
		return implode(' ', array_slice($words, 0, 4));
	}
}

if (!function_exists('cbia_yoast_generate_meta_description')) {
	function cbia_yoast_generate_meta_description($title, $content) {
		if (function_exists('cbia_generate_meta_description')) return cbia_generate_meta_description($title, $content);
		$base = cbia_yoast_first_paragraph_text((string)$content);
		$t = trim(wp_strip_all_tags((string)$title));
		if ($t !== '') {
			$base = $t . '. ' . $base;
		}
		$base = trim(preg_replace('/\s+/', ' ', $base));
		return mb_substr($base, 0, 155);
	}
}

if (!function_exists('cbia_yoast_word_count')) {
	function cbia_yoast_word_count($html) {
		$txt = wp_strip_all_tags((string)$html);
		$txt = preg_replace('/\s+/', ' ', $txt);
		$txt = trim($txt);
		if ($txt === '') return 0;
		return count(preg_split('/\s+/', $txt));
	}
}

if (!function_exists('cbia_yoast_sentence_count')) {
	function cbia_yoast_sentence_count($html) {
		$txt = wp_strip_all_tags((string)$html);
		$txt = trim($txt);
		if ($txt === '') return 0;
		$sentences = preg_split('/[.!?]+/', $txt);
		$sentences = array_filter(array_map('trim', $sentences));
		return count($sentences);
	}
}

if (!function_exists('cbia_yoast_has_h2')) {
	function cbia_yoast_has_h2($html) {
		return (bool)preg_match('/<h2\b/i', (string)$html);
	}
}

if (!function_exists('cbia_yoast_has_lists')) {
	function cbia_yoast_has_lists($html) {
		return (bool)preg_match('/<(ul|ol)\b/i', (string)$html);
	}
}

/* =========================================================
   ============ YOAST: REINDEX best-effort ==================
   ========================================================= */

if (!function_exists('cbia_yoast_try_reindex_post')) {
	function cbia_yoast_try_reindex_post($post_id) {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return false;

		// Si no hay YoastSEO(), intentamos hooks clásicos
		if (!function_exists('YoastSEO')) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			do_action('wpseo_save_postdata', $post_id);
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			do_action('wpseo_save_post', $post_id);
			clean_post_cache($post_id);
			return false;
		}

		try {
			$yoast = YoastSEO();
			if (is_object($yoast) && isset($yoast->classes) && is_object($yoast->classes) && method_exists($yoast->classes, 'get')) {
				$candidates = array(
					'\\Yoast\\WP\\SEO\\Actions\\Indexing\\Indexable_Post_Indexing_Action',
					'\\Yoast\\WP\\SEO\\Actions\\Indexing\\Indexable_Indexing_Action',
					'\\Yoast\\WP\\SEO\\Actions\\Indexing\\Indexing_Action',
				);
				foreach ($candidates as $class) {
					$obj = $yoast->classes->get($class);
					if (is_object($obj)) {
						if (method_exists($obj, 'index')) {
							$obj->index($post_id);
							return true;
						}
						if (method_exists($obj, 'reindex')) {
							$obj->reindex($post_id);
							return true;
						}
					}
				}
			}
		} catch (Throwable $e) {
			cbia_yoast_log("Reindex error post {$post_id}: " . $e->getMessage());
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action('wpseo_save_postdata', $post_id);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action('wpseo_save_post', $post_id);
		clean_post_cache($post_id);
		return false;
	}
}

/* =========================================================
   =================== SCORES (heuristica) ==================
   ========================================================= */

if (!function_exists('cbia_yoast_compute_scores_heuristic')) {
	function cbia_yoast_compute_scores_heuristic($post_id) {
		$post = get_post((int)$post_id);
		if (!$post) return array(null, null);
		$content = (string)$post->post_content;
		$wc = cbia_yoast_word_count($content);
		$sc = cbia_yoast_sentence_count($content);
		$has_h2    = cbia_yoast_has_h2($content);
		$has_lists = cbia_yoast_has_lists($content);

		$focus = (string)get_post_meta((int)$post_id, '_yoast_wpseo_focuskw', true);
		$metad = (string)get_post_meta((int)$post_id, '_yoast_wpseo_metadesc', true);

		$seo = 20;
		if ($wc >= 600) $seo += 25;
		if ($has_h2) $seo += 15;
		if ($has_lists) $seo += 10;
		if ($focus !== '') $seo += 15;
		if ($metad !== '') $seo += 15;
		if ($seo > 100) $seo = 100;

		$read = 20;
		if ($sc >= 6) $read += 30;
		if ($wc >= 400) $read += 20;
		if ($has_h2) $read += 15;
		if ($has_lists) $read += 15;
		if ($read > 100) $read = 100;

		return array($seo, $read);
	}
}

if (!function_exists('cbia_yoast_update_semaphore_scores')) {
	/**
	 * Guarda:
	 * - _yoast_wpseo_linkdex
	 * - _yoast_wpseo_content_score
	 *
	 * Retorna: [did(bool), seo(int|null), read(int|null)]
	 */
	function cbia_yoast_update_semaphore_scores($post_id, $force = false) {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return array(false, null, null);

		$linkdex = get_post_meta($post_id, '_yoast_wpseo_linkdex', true);
		$readsc  = get_post_meta($post_id, '_yoast_wpseo_content_score', true);
		if (!$force && $linkdex !== '' && $readsc !== '') {
			return array(false, (int)$linkdex, (int)$readsc);
		}

		list($seo, $read) = cbia_yoast_compute_scores_heuristic($post_id);
		if ($seo === null || $read === null) return array(false, null, null);

		update_post_meta($post_id, '_yoast_wpseo_linkdex', (string)$seo);
		update_post_meta($post_id, '_yoast_wpseo_content_score', (string)$read);

		try {
			if (class_exists('WPSEO_Meta') && method_exists('WPSEO_Meta', 'save_postdata')) {
				WPSEO_Meta::save_postdata($post_id);
			}
		} catch (Throwable $e) {
			cbia_yoast_log("WPSEO_Meta::save_postdata fallo en {$post_id}: " . $e->getMessage());
		}

		cbia_yoast_try_reindex_post($post_id);
		clean_post_cache($post_id);

		return array(true, $seo, $read);
	}
}

if (!function_exists('cbia_yoast_recalc_metas')) {
	/**
	 * Retorna bool didChange
	 */
	function cbia_yoast_recalc_metas($post_id, $force = false) {
		$post_id = (int)$post_id;
		$post = get_post($post_id);
		if (!$post) return false;

		$title = (string)$post->post_title;
		$content = (string)$post->post_content;

		$metadesc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
		$focuskw  = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
		$did = false;

		if ($force || $metadesc === '') {
			$md = cbia_yoast_generate_meta_description($title, $content);
			update_post_meta($post_id, '_yoast_wpseo_metadesc', $md);
			$did = true;
		}
		if ($force || $focuskw === '') {
			$fk = cbia_yoast_generate_focus_keyphrase($title, $content);
			update_post_meta($post_id, '_yoast_wpseo_focuskw', $fk);
			$did = true;
		}

		return $did;
	}
}

/* =========================================================
   ================= HOOK: POST CREADO ======================
   ========================================================= */

if (!function_exists('cbia_yoast_on_post_created')) {
	function cbia_yoast_on_post_created($post_id, $title = '', $content_html = '', $usage = array(), $model_used = '') {
		$post_id = (int)$post_id;
		if ($post_id <= 0) return;

		cbia_yoast_log("HOOK: post creado {$post_id}. Recalculando metas + puntuaciones...");
		$did_metas = cbia_yoast_recalc_metas($post_id, false);
		list($did_scores, $seo, $read) = cbia_yoast_update_semaphore_scores($post_id, true);
		$re = cbia_yoast_try_reindex_post($post_id);

		cbia_yoast_log("HOOK: post {$post_id} metas=" . ($did_metas ? 'actualizadas' : 'ok') .
			" | puntuaciones=" . ($did_scores ? "OK (SEO={$seo}, LEG={$read})" : 'sin cambios') .
			" | reindex=" . ($re ? 'ok' : 'best-effort'));
	}
}

add_action('cbia_after_post_created', 'cbia_yoast_on_post_created', 20, 5);
