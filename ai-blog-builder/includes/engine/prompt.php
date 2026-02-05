<?php
/**
 * Prompt builder.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('cbia_get_language_prompts')) {
    function cbia_get_language_prompts() {
        $base_structure = 
            "\n\nESTRUCTURA OBLIGATORIA (no añadir ni eliminar secciones)"
            ."\n\n1) Un encabezado usando la etiqueta <h2>"
            ."\n   Párrafo inicial usando la etiqueta <p>."
            ."\n   - Extensión: 180–220 palabras."
            ."\n\n2) Tres bloques principales, cada uno con:"
            ."\n   - (Opcional) un subtítulo usando la etiqueta <h3> SOLO si aporta claridad real."
            ."\n   - Extensión: 250–300 palabras por bloque."
            ."\n   - Listas SOLO cuando ayuden a la comprensión (etiquetas <ul> y <li>)."
            ."\n\n3) Sección de preguntas frecuentes:"
            ."\n   - Un encabezado <h2> con el equivalente natural a \"Preguntas frecuentes\" en este idioma."
            ."\n   - Seis preguntas frecuentes, cada una con:"
            ."\n     • Pregunta en etiqueta <h3>."
            ."\n     • Respuesta en etiqueta <p> (120–150 palabras)."
            ."\n\nINSTRUCCIÓN CRÍTICA"
            ."\n- Ninguna respuesta debe cortarse."
            ."\n- TODAS las respuestas deben terminar en punto final."
            ."\n\nIMÁGENES"
            ."\nInserta marcadores de imagen SOLO donde aporten valor, usando el formato EXACTO:"
            ."\n[IMAGEN: descripción breve, concreta, sin texto ni marcas de agua, estilo realista/editorial]"
            ."\n\nREGLAS DE OBLIGADO CUMPLIMIENTO"
            ."\n- NO usar la etiqueta <h1>."
            ."\n- NO añadir sección de conclusión."
            ."\n- NO incluir CTA final."
            ."\n- NO usar las etiquetas: doctype, html, head, body, script, style, iframe, table, blockquote."
            ."\n- NO enlazar a webs externas (usar el texto plano \"(enlace interno)\" si es necesario)."
            ."\n- Evitar redundancias y muletillas."
            ."\n- No escribir con enfoque SEO por keyword exacta."
            ."\n\nEl resultado debe leerse como un artículo editorial premium, interesante por sí mismo y adecuado para aparecer en Google Discover.";

        return [
            'espanol' => 
                "Escribe un POST COMPLETO en español y en HTML para \"{title}\", optimizado para Google Discover, con una extensión aproximada de 1800–2100 palabras (±10%)."
                ."\n\nREGLA DE IDIOMA (OBLIGATORIA)"
                ."\n- TODO el contenido debe estar escrito EXCLUSIVAMENTE en español."
                ."\n- Esto incluye títulos, encabezados, preguntas frecuentes y respuestas."
                ."\n- Está PROHIBIDO usar cualquier otro idioma en el contenido (salvo el título si viene en otro idioma)."
                ."\n\nEl contenido debe priorizar interés humano, lectura fluida, contexto cultural y experiencia real."
                ."\nEvita el enfoque de SEO tradicional y no fuerces keywords exactas."
                ."\n\nTONO Y ESTILO"
                ."\n- Profesional, cercano y natural."
                ."\n- Editorial y cultural, no enciclopédico."
                ."\n- Narrativo cuando sea adecuado, con criterio y punto de vista."
                ."\n- Pensado para lectores que no estaban buscando activamente el tema."
                . $base_structure,
                
            'portugues' =>
                "Escreva um POST COMPLETO em português e em HTML para \"{title}\", otimizado para Google Discover, com uma extensão aproximada de 1800–2100 palavras (±10%)."
                ."\n\nREGRA DE IDIOMA (OBRIGATÓRIA)"
                ."\n- TODO o conteúdo deve ser escrito EXCLUSIVAMENTE em português."
                ."\n- Isso inclui títulos, cabeçalhos, perguntas frequentes e respostas."
                ."\n- É PROIBIDO usar qualquer outro idioma no conteúdo (exceto o título se vier em outro idioma)."
                ."\n\nO conteúdo deve priorizar interesse humano, leitura fluida, contexto cultural e experiência real."
                ."\nEvite abordagens tradicionais de SEO e não force palavras-chave exatas."
                ."\n\nTOM E ESTILO"
                ."\n- Profissional, próximo e natural."
                ."\n- Editorial e cultural, não enciclopédico."
                ."\n- Narrativo quando apropriado, com critério e ponto de vista."
                ."\n- Pensado para leitores que não estavam procurando ativamente o tópico."
                . $base_structure,
                
            'ingles' =>
                "Write a COMPLETE POST in English and in HTML for \"{title}\", optimized for Google Discover, with an approximate extension of 1800–2100 words (±10%)."
                ."\n\nLANGUAGE RULE (MANDATORY)"
                ."\n- ALL content must be written EXCLUSIVELY in English."
                ."\n- This includes titles, headings, frequently asked questions and answers."
                ."\n- It is PROHIBITED to use any other language in the content (except the title if it comes in another language)."
                ."\n\nContent should prioritize human interest, smooth reading, cultural context and real experience."
                ."\nAvoid traditional SEO approach and do not force exact keywords."
                ."\n\nTONE AND STYLE"
                ."\n- Professional, close and natural."
                ."\n- Editorial and cultural, not encyclopedic."
                ."\n- Narrative when appropriate, with judgment and point of view."
                ."\n- Designed for readers who were not actively looking for the topic."
                . $base_structure,
                
            'frances' =>
                "Écrivez un POST COMPLET en français et en HTML pour \"{title}\", optimisé pour Google Discover, avec une longueur approximative de 1800–2100 mots (±10%)."
                ."\n\nRÈGLE DE LANGUE (OBLIGATOIRE)"
                ."\n- TOUT le contenu doit être écrit EXCLUSIVEMENT en français."
                ."\n- Cela inclut les titres, les en-têtes, les questions fréquemment posées et les réponses."
                ."\n- Il est INTERDIT d'utiliser toute autre langue dans le contenu (sauf le titre s'il provient d'une autre langue)."
                ."\n\nLe contenu doit privilégier l'intérêt humain, la lecture fluide, le contexte culturel et l'expérience réelle."
                ."\nÉvitez l'approche SEO traditionnelle et ne forcez pas les mots-clés exacts."
                ."\n\nTON ET STYLE"
                ."\n- Professionnel, proche et naturel."
                ."\n- Éditorial et culturel, pas encyclopédique."
                ."\n- Narratif lorsque approprié, avec jugement et point de vue."
                ."\n- Destiné aux lecteurs qui ne cherchaient pas activement le sujet."
                . $base_structure,
                
            'italiano' =>
                "Scrivi un POST COMPLETO in italiano e in HTML per \"{title}\", ottimizzato per Google Discover, con una lunghezza approssimativa di 1800–2100 parole (±10%)."
                ."\n\nREGOLA DELLA LINGUA (OBBLIGATORIA)"
                ."\n- TUTTI i contenuti devono essere scritti ESCLUSIVAMENTE in italiano."
                ."\n- Ciò include titoli, intestazioni, domande frequenti e risposte."
                ."\n- È VIETATO utilizzare qualsiasi altra lingua nel contenuto (tranne il titolo se proviene da un'altra lingua)."
                ."\n\nI contenuti devono dare priorità all'interesse umano, alla lettura scorrevole, al contesto culturale e all'esperienza reale."
                ."\nEvita l'approccio SEO tradizionale e non forzare parole chiave esatte."
                ."\n\nTONO E STILE"
                ."\n- Professionale, prossimale e naturale."
                ."\n- Editoriale e culturale, non enciclopedico."
                ."\n- Narrativo quando appropriato, con giudizio e punto di vista."
                ."\n- Progettato per lettori che non stavano cercando attivamente l'argomento."
                . $base_structure,
                
            'aleman' =>
                "Schreiben Sie einen VOLLSTÄNDIGEN BEITRAG auf Deutsch und in HTML für \"{title}\", optimiert für Google Discover, mit einer ungefähren Länge von 1800–2100 Wörtern (±10%)."
                ."\n\nSPRACHENREGEL (OBLIGATORISCH)"
                ."\n- ALLE Inhalte müssen AUSSCHLIESSLICH auf Deutsch verfasst werden."
                ."\n- Dies umfasst Titel, Überschriften, häufig gestellte Fragen und Antworten."
                ."\n- Es ist VERBOTEN, eine andere Sprache im Inhalt zu verwenden (außer dem Titel, falls er in einer anderen Sprache vorkommt)."
                ."\n\nDer Inhalt sollte menschliches Interesse, flüssiges Lesen, kulturellen Kontext und reale Erfahrung priorisieren."
                ."\nVermeiden Sie den traditionellen SEO-Ansatz und erzwingen Sie keine exakten Schlüsselwörter."
                ."\n\nTON UND STIL"
                ."\n- Professionell, nah und natürlich."
                ."\n- Redaktionell und kulturell, nicht enzyklopädisch."
                ."\n- Narrativ, wenn angemessen, mit Urteilsvermögen und Standpunkt."
                ."\n- Für Leser konzipiert, die das Thema nicht aktiv suchten."
                . $base_structure,
        ];
    }
}

if (!function_exists('cbia_build_prompt_for_title')) {
    function cbia_build_prompt_for_title($title) {
        $s = cbia_get_settings();
        $idioma_post = trim((string)($s['post_language'] ?? 'espanol'));

        $prompt_unico = $s['prompt_single_all'] ?? '';
        $prompt_unico = is_string($prompt_unico) ? trim($prompt_unico) : '';

        if ($prompt_unico === '') {
            $language_prompts = cbia_get_language_prompts();
            $prompt_unico = isset($language_prompts[$idioma_post]) 
                ? $language_prompts[$idioma_post] 
                : $language_prompts['espanol'];
        }

        $prompt_unico = str_replace('{title}', $title, $prompt_unico);

        return $prompt_unico;
    }
}
