# Creador Blog IA (FREE)

Genera entradas con IA (texto + 1 imagen destacada) con reanudacion por checkpoint y log en vivo. Ideal para crear posts sin bloquear el admin.

## Que hace
- Genera el texto completo del post con OpenAI.
- Crea 1 imagen destacada.
- Reanuda automaticamente con checkpoint (procesa por tandas).
- Log en vivo y STOP seguro.

## Requisitos
- WordPress 6.9+
- PHP 8.2+
- API Key de OpenAI con consentimiento explicito.

## Uso basico
1. Configura tu API Key en Ajustes ? Creador Blog IA.
2. Escribe tus titulos (uno por linea) en la pesta?a Blog.
3. Pulsa ?Crear blogs (con reanudacion)?.

## Transparencia
Este plugin usa la API de OpenAI unicamente cuando el usuario lo activa desde el panel y con consentimiento explicito.

## Estructura (v1.0.0)
- `includes/core/`: bootstrap, hooks y wiring
- `includes/admin/`: controladores y vistas
- `includes/engine/`: generacion de texto/imagenes
- `includes/integrations/`: OpenAI (y Yoast si se activa)
- `includes/support/`: helpers (logging, sanitizado, encoding)

## Version
- 1.0.0 (FREE)
