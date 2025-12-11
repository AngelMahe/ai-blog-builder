# Creador Blog IA (WordPress)

Plugin para generar entradas con IA (texto + imágenes) en una sola pasada.  
Incluye: marcadores `[IMAGEN: ...]`, FAQ en JSON-LD, asignación de categorías/etiquetas, programación por intervalo, **reanudación con checkpoint** y **rellenado de imágenes pendientes**.

## Características
- Modelos OpenAI 4.x (Responses/Chat) y gpt-image-1 / gpt-image-1-mini.
- Stop inmediato y log en tiempo real.
- Mapeo de keywords → categorías y tags.
- Programación con intervalo y “primera fecha”.
- Rellenado automático de imágenes pendientes (WP-Cron opcional).

## Requisitos
- WordPress 6.x+, PHP 8.0+.
- Clave de API de OpenAI.

## Instalación
1. Sube la carpeta del plugin a `wp-content/plugins/`.
2. Activa el plugin en **Plugins**.
3. Ve a **Ajustes → Creador Blog IA** y configura tu API Key.
