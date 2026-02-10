# AI Blog Builder Pro Pro (WordPress) v3.0.3

Genera entradas completas con IA (requiere la versión gratuita activa) (texto + im�genes) en una sola pasada. Incluye marcadores de imagen inteligentes, programaci�n con reanudaci�n por checkpoint, rellenado de im�genes pendientes y c�lculo de costes (estimado y real).

Tabla de contenidos
- Qu� hace y c�mo funciona
- Flujo de trabajo (pesta�as)
  - Configuraci�n
  - Blog (creaci�n con checkpoint)
  - Costes (estimaci�n y real)
  - Yoast/SEO
  - Diagn�stico
- Marcadores de imagen y pendientes
- Programaci�n y CRON
- Logs y diagn�stico
- Requisitos e instalaci�n
- Soluci�n de problemas
- Desarrollo y hooks
- Migraci�n legacy (eliminada en 3.0)

## Qu� hace y c�mo funciona

El plugin llama a OpenAI (Responses) para generar el HTML de un post y procesa marcadores de imagen con OpenAI Images. Crea la destacada y las im�genes internas respetando el l�mite configurado. Si alguna imagen falla, deja un marcador �pendiente� oculto para rellenarlo luego (manual o por CRON).
Este plugin usa la API de OpenAI �nicamente cuando el usuario lo activa desde el panel y con consentimiento expl�cito en Configuraci�n.

Puntos clave:
- 1 destacada + (images_limit - 1) im�genes en contenido.
- Marcadores sobrantes se eliminan; marcadores fallidos se sustituyen por un �pendiente� oculto y rastreable.
- Reanudaci�n con checkpoint: nunca bloquea la pantalla; procesa por tandas cortas y reprograma el siguiente evento.
- Log de actividad en vivo.

## Flujo de trabajo (pesta�as)

### 1) Configuraci�n
- OpenAI API Key y modelo de texto preferido (con fallback entre gpt-5 / gpt-4.1).
- Longitud objetivo, temperatura, l�mite de im�genes.
- Prompts de imagen por secci�n (intro/cuerpo/cierre/FAQ).
- Reglas: keywords ? categor�as; �Tags permitidas� (lista blanca) para autoseleccionar etiquetas.
- Bloqueo de modelos (no se usan aunque est�n en el fallback).

### 2) Blog (creaci�n con checkpoint)
- T�tulos: manual o desde CSV (URL).
- Programaci�n: �primera fecha/hora� + intervalo (d�as). Si no hay fecha, publica inmediato.
- Botones:
  - Probar configuraci�n.
  - Crear Blogs (con reanudaci�n): encola un evento y comienza a procesar 1 t�tulo por tanda (ajustable en c�digo). Reanuda autom�ticamente hasta terminar.
  - Detener (STOP) para cortar de forma segura.
  - Rellenar pendientes: llama a OpenAI Images para completar los �pendientes�.
  - Limpiar checkpoint / Limpiar log.
- Estado: muestra checkpoint, �ltima fecha programada y log en vivo.

### 3) Costes (estimaci�n y real)
- Estimaci�n r�pida por post (texto, im�genes, SEO) seg�n configuraciones y tabla de precios.
- C�lculo REAL �post-hoc� sumando cada llamada guardada (modelo real por llamada). Opciones:
  - Precio fijo por imagen (recomendado) con importes mini/full en USD.
  - Sobrecoste fijo por llamada de texto/SEO (USD) para cuadrar billing.
  - Multiplicador de ajuste total del coste real.
- Acciones: �Calcular� (usa real y, si falta, estimaci�n) o �Calcular SOLO real�. Log de costes en vivo.

### 4) Yoast/SEO (opcional)
- Genera metadescripci�n b�sica y focus keyphrase. Si tienes Yoast SEO activo, se aprovechan sus metas y hook para ampliar el relleno.
- Si no usas Yoast, el post se crea igualmente (las metas quedan como metadatos est�ndar; no es requisito).

### 5) Diagn�stico
- Resumen del entorno (WP, PHP, l�mites, debug).
- Comprobaci�n r�pida de permisos de escritura y estado de la API Key.
- �ltimas l�neas del log del plugin para soporte.

## Marcadores de imagen y pendientes

Formato en el HTML de texto: `[IMAGEN: descripci�n]`
- El motor extrae hasta (images_limit - 1) marcadores para contenido (la primera imagen va a destacada si procede).
- Si faltan marcadores, inserta autom�ticamente en zonas �tiles (tras primer p�rrafo, antes de FAQ, cierre).
- Si sobran, los elimina.
- Si una imagen falla, el marcador se reemplaza por: `<span class="cbia-img-pendiente" style="display:none">[IMAGEN_PENDIENTE: desc]</span>`
  - Estos spans se ocultan y no rompen el layout; el rellenado posterior los sustituye por una `<img>` real.

Limpieza de artefactos
- El motor limpia puntos sueltos tras marcadores/pendientes (`</span>.`, `</p>.`, l�nea con �.�), y colapsa saltos extra.

## Programaci�n y CRON
- Al pulsar �Crear Blogs�, se encola un evento y se procesa 1 post por tanda (para evitar timeouts). Si queda cola, reprograma la siguiente tanda.
- Puedes activar un CRON hourly para �Rellenar pendientes�.
- STOP detiene con seguridad entre pasos.

## Logs y diagn�stico
- Log de actividad en vivo (AJAX) en la pesta�a Blog.
- Log de Costes con tokens reales y llamadas.
- Mensajes claros en cada fase (cola, checkpoint, evento, im�genes, pendientes, etc.).

## Requisitos e instalaci�n
- WordPress 6.9+ (probado en 6.9.0), PHP 8.2+.
- Multisite compatible (probado en entorno multisite).
- Clave de API de OpenAI con permisos m�nimos.
- Yoast SEO: opcional (el plugin funciona sin �l).

Instalaci�n:
1) Copia la carpeta en `wp-content/plugins/`.
2) Activa el plugin.
3) Ve a Ajustes ? AI Blog Builder Pro, pon tu API Key y configura.

## Soluci�n de problemas
- �No hace nada�: revisa log; si el t�tulo ya existe, se omite. Aseg�rate de tener t�tulos v�lidos (manual o CSV) y que el checkpoint no est� bloqueado.
- Puntos sueltos al final: el motor limpia casos t�picos (`</span>.`, `</p>.`, l�neas con �.�). Si detectas un patr�n nuevo, actualiza y vuelve a procesar.
- Im�genes que no salen: revisa el log (fallo de generaci�n, cuota, red). Usa �Rellenar pendientes�.
- Costes no cuadran: activa �precio fijo por imagen�, ajusta importes mini/full y (si hace falta) el sobrecoste por llamada y el multiplicador real.

## Desarrollo y hooks
Estructura (v2.3):
- `includes/core/`: bootstrap, hooks y wiring de dependencias.
- `includes/admin/`: controladores de pesta�as y vistas (`admin/views/`).
- `includes/engine/`: l�gica de generaci�n (texto, im�genes, pendientes, posts).
- `includes/domain/`: dominios puros (p. ej., costes).
- `includes/integrations/`: integraciones externas (Yoast, OpenAI).
- `includes/jobs/`: CRON y tareas background.
- `includes/support/`: helpers (sanitizado, logging, encoding).
- `includes/legacy/`: cargadores legacy para compatibilidad.

Hooks disponibles:
- `do_action('cbia_after_post_created', $post_id, $title, $html, $usage, $model_used)`
  - �til para enriquecer SEO, relacionar contenido, etc.

Permisos y seguridad
- Todas las acciones de admin requieren `manage_options` y nonce.

## Migraci�n legacy (eliminada en 3.0)
Los wrappers legacy (`includes/legacy/cbia-*.php`) han sido eliminados en la versi�n 3.0.
Si tu instalaci�n depend�a de esos archivos, migra a las rutas nuevas en `includes/` (core/admin/engine/domain).

Licencia
- GPLv2 o posterior.



