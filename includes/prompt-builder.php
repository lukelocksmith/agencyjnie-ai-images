<?php
/**
 * Budowanie promptu dla Gemini API
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Buduje prompt do wygenerowania obrazka na podstawie posta i ustawień
 * 
 * @param int $post_id ID posta
 * @return string Gotowy prompt dla API
 */
function aai_build_prompt( $post_id ) {
    $post = get_post( $post_id );
    
    if ( ! $post ) {
        return '';
    }
    
    // Pobierz tytuł
    $title = $post->post_title;
    
    if ( empty( $title ) ) {
        return '';
    }
    
    // Pobierz wstęp (excerpt lub pierwsze 200 znaków treści)
    $excerpt = aai_get_post_excerpt( $post );
    
    // Pobierz ustawienia
    $base_prompt    = aai_get_option( 'base_prompt', '' );
    $negative_prompt = aai_get_option( 'negative_prompt', '' );

    // Check for category-specific style override
    $category_style_key = function_exists( 'aai_get_category_style' ) ? aai_get_category_style( $post_id ) : null;
    if ( $category_style_key ) {
        $all_styles = aai_get_all_style_descriptions();
        $style = isset( $all_styles[ $category_style_key ] ) ? $all_styles[ $category_style_key ] : aai_get_style_description();
    } else {
        $style = aai_get_style_description();
    }

    $colors         = aai_get_colors_description();
    $aspect_ratio   = aai_get_option( 'aspect_ratio', '16:9' );
    $image_language = aai_get_option( 'image_language', 'pl' );
    
    // Zbuduj prompt
    $prompt_parts = array();
    
    // Główna instrukcja
    $prompt_parts[] = "Create a visually striking image for a blog article.";
    
    // Tytuł artykułu
    $prompt_parts[] = sprintf( "Article title: \"%s\"", $title );
    
    // Wstęp/opis artykułu
    if ( ! empty( $excerpt ) ) {
        $prompt_parts[] = sprintf( "Article summary: %s", $excerpt );
    }
    
    // Styl artystyczny
    if ( ! empty( $style ) ) {
        $prompt_parts[] = sprintf( "Art style: %s", $style );
    }
    
    // Prompt bazowy (ogólne wytyczne stylu)
    if ( ! empty( $base_prompt ) ) {
        $prompt_parts[] = sprintf( "Style guidelines: %s", $base_prompt );
    }
    
    // Kolory
    if ( ! empty( $colors ) ) {
        $prompt_parts[] = sprintf( "Color palette: Use these dominant colors: %s", $colors );
    }
    
    // Proporcje
    $prompt_parts[] = sprintf( "Aspect ratio: %s", $aspect_ratio );
    
    // Negative prompt (instrukcja czego unikać)
    if ( ! empty( $negative_prompt ) ) {
        $prompt_parts[] = sprintf( "IMPORTANT - DO NOT INCLUDE / NEGATIVE PROMPT: %s", $negative_prompt );
    }
    
    // Instrukcje dotyczące języka/tekstu na obrazku
    $language_instruction = aai_get_language_instruction( $image_language );
    
    // Dodatkowe instrukcje dla lepszej jakości (quality boosters)
    $prompt_parts[] = "The image should be professional, high-quality, and suitable for a blog header.";
    
    // Text constraint - MUST BE LAST for recency bias
    $prompt_parts[] = $language_instruction;

    // Złącz wszystkie części
    $prompt = implode( "\n\n", $prompt_parts );
    
    // Filtr pozwalający modyfikować prompt
    $prompt = apply_filters( 'aai_image_prompt', $prompt, $post_id, $post );
    
    return $prompt;
}

/**
 * Zwraca instrukcję systemową (System Instruction) dla modelu
 * 
 * @param int $post_id ID posta
 * @return string System Instruction
 */
function aai_get_system_instruction( $post_id ) {
    $instructions = array();
    $instructions[] = "You are an expert AI image generator. Your task is to create a visually striking featured image for a blog article.";
    
    $image_language = aai_get_option( 'image_language', 'pl' );
    
    if ( $image_language === 'none' ) {
        $instructions[] = "CRITICAL RULE: The output image must NOT contain any text, words, letters, numbers, or glyphs. It must be a purely visual representation. If the prompt implies text (like a title), visualize the CONCEPT, do not write the words.";
    } elseif ( $image_language === 'numbers_only' ) {
        $instructions[] = "CRITICAL RULE: The output image may contain numbers and charts, but must NOT contain any words, letters, or written language.";
    }
    
    return implode( " ", $instructions );
}

/**
 * Zwraca instrukcję dotyczącą języka/tekstu na obrazku
 * 
 * @param string $language_code Kod języka
 * @return string Instrukcja dla AI
 */
function aai_get_language_instruction( $language_code ) {
    // Mapowanie kodów języków na pełne nazwy
    $language_names = array(
        'pl' => 'Polish',
        'en' => 'English',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'uk' => 'Ukrainian',
        'ru' => 'Russian',
    );
    
    // Bez tekstu i liczb - całkowicie wizualny obrazek
    if ( $language_code === 'none' ) {
        return "IMPORTANT FINAL INSTRUCTION: Do NOT include any text, words, letters, numbers, labels, captions, watermarks, or any written content in the image. The image must be purely visual without any textual elements. Visuals ONLY.";
    }
    
    // Tylko liczby - pozwala na cyfry, procenty, statystyki, ale bez słów
    if ( $language_code === 'numbers_only' ) {
        return "IMPORTANT FINAL INSTRUCTION: You MAY include numbers, digits, percentages (%), statistics, mathematical symbols, charts, and graphs in the image. However, do NOT include any words, letters, text labels, or written language. Only numerical data and mathematical notation are allowed.";
    }
    
    $language_name = isset( $language_names[ $language_code ] ) ? $language_names[ $language_code ] : 'English';
    
    return sprintf(
        "LANGUAGE REQUIREMENT: If the image contains any text, labels, signs, captions, or written elements, they MUST be in %s language. All textual content on the image should be written in %s.",
        $language_name,
        $language_name
    );
}

/**
 * Pobiera excerpt lub pierwsze 200 znaków treści posta
 * 
 * @param WP_Post $post Obiekt posta
 * @return string Wstęp artykułu
 */
function aai_get_post_excerpt( $post ) {
    // Najpierw spróbuj excerpt
    if ( ! empty( $post->post_excerpt ) ) {
        return wp_strip_all_tags( $post->post_excerpt );
    }
    
    // Jeśli brak excerpta, weź początek treści
    $content = $post->post_content;
    
    // Usuń shortcodes i bloki Gutenberga
    $content = strip_shortcodes( $content );
    $content = preg_replace( '/<!--.*?-->/', '', $content ); // Usuń komentarze HTML (bloki Gutenberga)
    $content = wp_strip_all_tags( $content );
    
    // Usuń nadmiarowe białe znaki
    $content = preg_replace( '/\s+/', ' ', $content );
    $content = trim( $content );
    
    // Ogranicz do 200 znaków
    if ( mb_strlen( $content ) > 200 ) {
        $content = mb_substr( $content, 0, 200 );
        // Znajdź ostatnią spację, żeby nie ciąć w środku słowa
        $last_space = mb_strrpos( $content, ' ' );
        if ( $last_space !== false && $last_space > 150 ) {
            $content = mb_substr( $content, 0, $last_space );
        }
        $content .= '...';
    }
    
    return $content;
}

/**
 * Pobiera opis stylu artystycznego
 * 
 * @return string Opis stylu
 */
function aai_get_style_description() {
    $style = aai_get_option( 'style', 'photorealistic' );
    
    // Jeśli własny styl, zwróć go
    if ( $style === 'custom' ) {
        return aai_get_option( 'custom_style', '' );
    }
    
    // Mapowanie stylów na opisy
    $style_descriptions = aai_get_all_style_descriptions();
    
    return isset( $style_descriptions[ $style ] ) ? $style_descriptions[ $style ] : '';
}

/**
 * Pobiera opis kolorów w formacie czytelnym dla AI
 * 
 * @return string Opis kolorów
 */
function aai_get_colors_description() {
    $colors = aai_get_option( 'colors', array() );
    
    if ( empty( $colors ) || ! is_array( $colors ) ) {
        return '';
    }
    
    $color_names = array();
    
    foreach ( $colors as $hex_color ) {
        // Konwertuj hex na nazwę koloru (przybliżoną)
        $color_name = aai_hex_to_color_name( $hex_color );
        if ( $color_name ) {
            $color_names[] = $color_name . ' (' . $hex_color . ')';
        }
    }
    
    if ( empty( $color_names ) ) {
        return '';
    }
    
    return implode( ', ', $color_names );
}

/**
 * Konwertuje kolor hex na przybliżoną nazwę koloru
 * 
 * @param string $hex Kolor w formacie hex (#RRGGBB)
 * @return string Nazwa koloru
 */
function aai_hex_to_color_name( $hex ) {
    // Usuń # jeśli jest
    $hex = ltrim( $hex, '#' );
    
    if ( strlen( $hex ) !== 6 ) {
        return $hex;
    }
    
    // Parsuj RGB
    $r = hexdec( substr( $hex, 0, 2 ) );
    $g = hexdec( substr( $hex, 2, 2 ) );
    $b = hexdec( substr( $hex, 4, 2 ) );
    
    // Podstawowe kolory i ich przybliżone wartości RGB
    $colors = array(
        'red'         => array( 255, 0, 0 ),
        'green'       => array( 0, 128, 0 ),
        'blue'        => array( 0, 0, 255 ),
        'yellow'      => array( 255, 255, 0 ),
        'orange'      => array( 255, 165, 0 ),
        'purple'      => array( 128, 0, 128 ),
        'pink'        => array( 255, 192, 203 ),
        'cyan'        => array( 0, 255, 255 ),
        'teal'        => array( 0, 128, 128 ),
        'navy'        => array( 0, 0, 128 ),
        'brown'       => array( 139, 69, 19 ),
        'gray'        => array( 128, 128, 128 ),
        'black'       => array( 0, 0, 0 ),
        'white'       => array( 255, 255, 255 ),
        'lime'        => array( 0, 255, 0 ),
        'indigo'      => array( 75, 0, 130 ),
        'coral'       => array( 255, 127, 80 ),
        'gold'        => array( 255, 215, 0 ),
        'silver'      => array( 192, 192, 192 ),
        'beige'       => array( 245, 245, 220 ),
    );
    
    $closest_color = 'unknown';
    $min_distance = PHP_INT_MAX;
    
    foreach ( $colors as $name => $rgb ) {
        // Oblicz odległość euklidesową w przestrzeni RGB
        $distance = sqrt(
            pow( $r - $rgb[0], 2 ) +
            pow( $g - $rgb[1], 2 ) +
            pow( $b - $rgb[2], 2 )
        );
        
        if ( $distance < $min_distance ) {
            $min_distance = $distance;
            $closest_color = $name;
        }
    }
    
    return $closest_color;
}

/**
 * Podgląd promptu dla danego posta (do debugowania)
 * 
 * @param int $post_id ID posta
 * @return void
 */
function aai_preview_prompt( $post_id ) {
    $prompt = aai_build_prompt( $post_id );
    
    echo '<pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; white-space: pre-wrap;">';
    echo esc_html( $prompt );
    echo '</pre>';
}

/**
 * Centralna definicja wszystkich stylów artystycznych z promptami
 *
 * @return array Klucz stylu => opis promptu
 */
function aai_get_all_style_descriptions() {
    return array(
        'photorealistic' => 'Photorealistic, high-resolution photograph style, natural lighting, professional photography, 8k resolution, highly detailed, cinematic composition',
        'digital_art'    => 'Digital art style, modern, vibrant colors, clean lines, digital illustration, trending on ArtStation',
        'isometric'      => 'Isometric 3D render style, cute, blender style, soft lighting, toy-like appearance, miniature diorama feel',
        'minimalist'     => 'Minimalist design, simple shapes, clean composition, lots of negative space, subtle muted colors, flat design, elegant simplicity',
        'cyberpunk'      => 'Cyberpunk style, neon lights, dark atmosphere, futuristic, high contrast, sci-fi elements, rain-slicked streets, holographic displays',
        'watercolor'     => 'Watercolor painting style, soft edges, flowing colors, artistic and organic feel, paper texture, delicate washes, wet-on-wet technique',
        'sketch'         => 'Pencil sketch style, black and white, hand-drawn look, artistic shading, crosshatching, fine detail',
        'pop_art'        => 'Pop Art style, bold colors, comic book aesthetic, halftones, strong outlines, Roy Lichtenstein inspired',
        'abstract'       => 'Abstract art, bold geometric shapes, overlapping translucent forms, dynamic composition, vibrant gradients, Kandinsky meets modern design, expressive color fields',
        'flat_illustration' => 'Flat vector illustration, clean geometric shapes, limited color palette, Dribbble/Behance style, modern UI illustration, friendly and approachable, no gradients',
        '3d_render'      => '3D render, glossy plastic materials, soft studio lighting, octane render quality, floating objects, depth of field, product visualization aesthetic',
        'retro_vintage'  => 'Retro vintage style, faded warm color palette, film grain texture, 1970s poster aesthetic, halftone dots, aged paper feel, nostalgic and warm',
        'neon_glow'      => 'Neon glow art, luminous outlines on dark background, electric blue and magenta, light trails, glowing particles, blacklight aesthetic, vibrant against darkness',
        'paper_cut'      => 'Paper cut art style, layered paper with shadows, depth through overlapping layers, craft aesthetic, subtle textures, kirigami inspired, soft ambient shadows between layers',
        'pixel_art'      => 'Pixel art style, retro 16-bit game aesthetic, limited color palette, crisp pixels, nostalgic gaming feel, no anti-aliasing, chunky charm',
        'line_art'       => 'Elegant line art, single continuous weight lines, minimal and sophisticated, white background, architectural precision, luxury brand aesthetic, clean monochrome',
        'gradient_mesh'  => 'Gradient mesh art, smooth flowing color transitions, aurora-like gradients, organic blob shapes, modern tech startup aesthetic, vibrant iridescent colors, glassmorphism influence',
        'collage'        => 'Mixed media collage, cut-out photographic elements, textured paper layers, bold typography fragments, editorial magazine style, creative juxtaposition, modern zine aesthetic',
    );
}
