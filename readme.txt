=== Creador Blog IA ===
Contributors: webgoh
Requires at least: 6.9
Tested up to: 6.9.0
Stable tag: 1.0.0
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Genera entradas con IA (texto + 1 imagen destacada) con reanudacion por checkpoint y log en vivo.

== Description ==

Plugin FREE para crear posts con OpenAI sin bloquear la pantalla. Procesa por tandas, reanuda con checkpoint y deja un log claro de todo el proceso.
Este plugin usa la API de OpenAI unicamente cuando el usuario lo activa desde el panel y con consentimiento explicito en Configuracion.

Caracteristicas clave:
* Generacion de texto + 1 imagen destacada
* Reanudacion con checkpoint (no bloquea el admin)
* Log en vivo y STOP seguro
* Uso con API Key del usuario
* Publicacion inmediata

== Installation ==
1. Sube la carpeta del plugin a `wp-content/plugins/`.
2. Activa el plugin desde ?Plugins?.
3. Ve a Ajustes ? Creador Blog IA, a?ade tu API Key y configura.

== Frequently Asked Questions ==

= ?Como funciona la reanudacion? =
Usa un checkpoint que guarda cola, indice y totales. El boton ?Crear blogs (con reanudacion)? procesa 1 post por tanda y, si queda cola, reprograma la siguiente.

= ?Necesito Yoast o CRON? =
No. El plugin funciona sin Yoast y sin CRON.

= ?Se puede usar sin API Key? =
No. Necesitas una API Key de OpenAI y consentimiento explicito en Configuracion.

== Changelog ==
* 1.0.0 - Primera version FREE estable.
