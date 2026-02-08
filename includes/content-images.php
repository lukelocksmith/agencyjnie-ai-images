<?php
/**
 * Generowanie obrazków w treści artykułu
 * AI analizuje treść i wybiera miejsca na obrazki
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Oblicza optymalną liczbę obrazków na podstawie długości treści
 * 
 * @param string $content Treść artykułu
 * @return int Optymalna liczba obrazków
 */
function aai_calculate_optimal_images( $content ) {
    // Policz słowa w treści
    $clean_content = wp_strip_all_tags( $content );
    $word_count = str_word_count( $clean_content );
    
    // Pobierz limit z ustawień
    $max_limit = aai_get_option( 'max_content_images', 5 );
    
    // Oblicz optymalną liczbę na podstawie długości
    // Zasada: 1 obrazek na każde 300-400 słów
    if ( $word_count < 300 ) {
        $optimal = 1;
    } elseif ( $word_count < 500 ) {
        $optimal = 2;
    } elseif ( $word_count < 800 ) {
        $optimal = 2;
    } elseif ( $word_count < 1000 ) {
        $optimal = 3;
    } elseif ( $word_count < 1500 ) {
        $optimal = 4;
    } elseif ( $word_count < 2000 ) {
        $optimal = 5;
    } elseif ( $word_count < 2500 ) {
        $optimal = 6;
    } else {
        $optimal = 7;
    }
    
    // Zastosuj limit
    return min( $optimal, $max_limit );
}

/**
 * Analizuje treść artykułu i zwraca sugerowane lokalizacje dla obrazków
 * 
 * @param int $post_id ID posta
 * @param int $max_images Maksymalna liczba obrazków (0 = automatyczne obliczanie)
 * @return array|WP_Error Tablica lokalizacji lub błąd
 */
function aai_analyze_content_for_images( $post_id, $max_images = 0 ) {
    $post = get_post( $post_id );
    
    if ( ! $post ) {
        return new WP_Error( 'invalid_post', __( 'Nie znaleziono posta.', 'agencyjnie-ai-images' ) );
    }
    
    $content = $post->post_content;
    
    if ( empty( $content ) ) {
        return new WP_Error( 'empty_content', __( 'Post nie ma treści.', 'agencyjnie-ai-images' ) );
    }
    
    // Sprawdź klucz API
    $api_key = aai_get_secure_option( 'api_key' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', __( 'Brak klucza API.', 'agencyjnie-ai-images' ) );
    }
    
    // Przygotuj treść do analizy (usuń HTML, zachowaj strukturę)
    $clean_content = aai_prepare_content_for_analysis( $content );
    
    // Oblicz optymalną liczbę obrazków (dynamicznie na podstawie długości)
    if ( $max_images <= 0 ) {
        $max_images = aai_calculate_optimal_images( $content );
    }
    
    // Pobierz ustawienia stylu
    $style = aai_get_style_description();
    $base_prompt = aai_get_option( 'base_prompt', '' );
    
    // Sprawdź cache
    $cache_key = 'aai_analysis_' . md5( $clean_content . $max_images . $style . $base_prompt );
    $cached_locations = get_transient( $cache_key );
    
    if ( $cached_locations !== false ) {
        return $cached_locations;
    }
    
    // Prompt do analizy treści
    $analysis_prompt = aai_build_analysis_prompt( $post->post_title, $clean_content, $max_images, $style, $base_prompt );
    
    // Wyślij do Gemini (model tekstowy - szybszy)
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent';
    $api_url = add_query_arg( 'key', $api_key, $api_url );
    
    $request_body = array(
        'contents' => array(
            array(
                'parts' => array(
                    array(
                        'text' => $analysis_prompt,
                    ),
                ),
            ),
        ),
        'generationConfig' => array(
            'responseMimeType' => 'application/json',
        ),
    );
    
    $response = wp_remote_post( $api_url, array(
        'timeout' => 60,
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'body'    => wp_json_encode( $request_body ),
    ) );
    
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'api_error', $response->get_error_message() );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );
    
    if ( $response_code !== 200 ) {
        $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Błąd API', 'agencyjnie-ai-images' );
        return new WP_Error( 'api_error', $error_message );
    }
    
    // Parsuj odpowiedź JSON
    $locations = aai_parse_analysis_response( $data );
    
    if ( is_wp_error( $locations ) ) {
        return $locations;
    }
    
    // Zapisz w cache na 1 godzinę
    set_transient( $cache_key, $locations, HOUR_IN_SECONDS );
    
    return $locations;
}

/**
 * Przygotowuje treść do analizy (numeruje paragrafy)
 * Rozróżnia nagłówki od paragrafów żeby AI miał kontekst sekcji
 */
function aai_prepare_content_for_analysis( $content ) {
    // Usuń bloki Gutenberga (komentarze HTML)
    $content = preg_replace( '/<!--.*?-->/s', '', $content );
    
    $numbered_content = '';
    $paragraph_counter = 0;
    $current_section = '';
    
    // Znajdź wszystkie nagłówki i paragrafy (zachowaj kolejność)
    // Dopasuj <h1>...<h6> oraz <p>
    preg_match_all( '/<(h[1-6]|p)[^>]*>(.*?)<\/\1>/is', $content, $matches, PREG_SET_ORDER );
    
    foreach ( $matches as $match ) {
        $tag = strtolower( $match[1] );
        $text = trim( strip_tags( $match[2] ) );
        
        if ( empty( $text ) ) {
            continue;
        }
        
        // Jeśli to nagłówek - zapamiętaj jako kontekst sekcji
        if ( preg_match( '/^h[1-6]$/', $tag ) ) {
            $current_section = $text;
            $numbered_content .= "\n[SECTION: " . $text . "]\n\n";
        } else {
            // To jest paragraf - numeruj
            $paragraph_counter++;
            $numbered_content .= "[PARAGRAPH " . $paragraph_counter . "]: " . $text . "\n\n";
        }
    }
    
    return $numbered_content;
}

/**
 * Buduje prompt do analizy treści
 * Rozszerzony o ekstrakcję keywords i wykrywanie brandów
 */
function aai_build_analysis_prompt( $title, $content, $max_images, $style, $base_prompt ) {
    // Pobierz ustawienie języka
    $image_language = aai_get_option( 'image_language', 'pl' );
    $language_instruction = aai_get_language_instruction( $image_language );
    
    $prompt = "You are an expert content analyst. Analyze the following article and suggest the best locations for illustrative images.

ARTICLE TITLE: {$title}

ARTICLE CONTENT:
{$content}

IMAGE STYLE GUIDELINES:
- Style: {$style}
- Additional guidelines: {$base_prompt}
- {$language_instruction}

TASK:
1. Read the entire article carefully
2. Identify up to {$max_images} paragraphs that would benefit most from an illustrative image
3. For each selected location, analyze ONLY THAT SPECIFIC PARAGRAPH:
   - Extract 3-5 relevant keywords (in English) for stock photo search FROM THAT PARAGRAPH
   - Identify brand names mentioned ONLY IN THAT SPECIFIC PARAGRAPH (not from other paragraphs!)
   - Create a detailed image generation prompt that matches THAT PARAGRAPH's content

CRITICAL RULES FOR BRAND DETECTION:
- detected_brands MUST contain ONLY brands explicitly mentioned in that specific paragraph
- DO NOT include brands from other paragraphs
- If a paragraph talks about Slack, detected_brands should be [\"Slack\"], not brands from paragraph 1
- If no brands are mentioned in that specific paragraph, use empty array: []

GENERAL RULES:
- Select paragraphs that discuss concepts that can be visualized
- Avoid paragraphs that are just introductions or conclusions
- Space out the images throughout the article (not all at the beginning or end)
- Keywords should be relevant to THE SPECIFIC PARAGRAPH being illustrated
- Image prompts should be detailed and specific to the paragraph content
- Image prompts should follow the style guidelines provided

RESPOND WITH VALID JSON ONLY (no markdown, no explanation):
{
    \"images\": [
        {
            \"paragraph_index\": 1,
            \"position\": \"after\",
            \"keywords\": [\"remote work\", \"laptop\", \"home office\"],
            \"detected_brands\": [\"Slack\"],
            \"prompt\": \"Detailed image generation prompt matching this paragraph\",
            \"reason\": \"Brief explanation why this paragraph needs an image\"
        }
    ]
}

IMPORTANT:
- The content shows [SECTION: Title] headers followed by [PARAGRAPH X] entries
- paragraph_index is the number shown in [PARAGRAPH X] - these are sequential across the entire article
- Use the [SECTION] headers to understand context, but only reference [PARAGRAPH] numbers
- position must be either \"before\" or \"after\"
- keywords must be in English and relevant to that specific paragraph's content
- detected_brands MUST ONLY contain brands mentioned IN THAT SPECIFIC PARAGRAPH TEXT (this is critical!)
- For a paragraph about Notion, detected_brands should be [\"Notion\"], NOT brands from other paragraphs
- Return between 1 and {$max_images} image suggestions
- If no good locations found, return empty images array: {\"images\": []}";

    return $prompt;
}

/**
 * Parsuje odpowiedź AI z lokalizacjami obrazków
 * Rozszerzony o keywords i detected_brands
 */
function aai_parse_analysis_response( $response ) {
    // Wyciągnij tekst z odpowiedzi
    if ( ! isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
        return new WP_Error( 'invalid_response', __( 'Nieprawidłowa odpowiedź z API.', 'agencyjnie-ai-images' ) );
    }
    
    $text = $response['candidates'][0]['content']['parts'][0]['text'];
    
    // Spróbuj sparsować JSON
    // Użyj helpera do czyszczenia odpowiedzi (usuwa Markdown)
    $clean_text = aai_clean_json_string( $text );
    $json = json_decode( $clean_text, true );
    
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        // Fallback - spróbuj wyciągnąć JSON z tekstu (jeśli helper nie zadziałał)
        if ( preg_match( '/\{[\s\S]*\}/', $text, $matches ) ) {
            $json = json_decode( $matches[0], true );
        }
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'json_error', __( 'Nie udało się sparsować odpowiedzi AI.', 'agencyjnie-ai-images' ) );
        }
    }
    
    if ( ! isset( $json['images'] ) || ! is_array( $json['images'] ) ) {
        return new WP_Error( 'invalid_format', __( 'Nieprawidłowy format odpowiedzi.', 'agencyjnie-ai-images' ) );
    }
    
    // Waliduj każdą lokalizację
    $validated = array();
    foreach ( $json['images'] as $image ) {
        if ( isset( $image['paragraph_index'] ) && isset( $image['prompt'] ) ) {
            // Wyciągnij i zwaliduj keywords
            $keywords = array();
            if ( isset( $image['keywords'] ) && is_array( $image['keywords'] ) ) {
                foreach ( $image['keywords'] as $keyword ) {
                    $clean_keyword = sanitize_text_field( $keyword );
                    if ( ! empty( $clean_keyword ) ) {
                        $keywords[] = $clean_keyword;
                    }
                }
            }
            
            // Wyciągnij i zwaliduj detected_brands
            $detected_brands = array();
            if ( isset( $image['detected_brands'] ) && is_array( $image['detected_brands'] ) ) {
                foreach ( $image['detected_brands'] as $brand ) {
                    $clean_brand = sanitize_text_field( $brand );
                    if ( ! empty( $clean_brand ) ) {
                        $detected_brands[] = $clean_brand;
                    }
                }
            }
            
            $validated[] = array(
                'paragraph_index' => intval( $image['paragraph_index'] ),
                'position'        => isset( $image['position'] ) && $image['position'] === 'before' ? 'before' : 'after',
                'keywords'        => $keywords,
                'detected_brands' => $detected_brands,
                'prompt'          => sanitize_text_field( $image['prompt'] ),
                'reason'          => isset( $image['reason'] ) ? sanitize_text_field( $image['reason'] ) : '',
            );
        }
    }
    
    return $validated;
}

/**
 * Generuje obrazki dla wskazanych lokalizacji
 * Używa hybrydowego systemu źródeł: Media Library → Brandfetch → Unsplash/Pexels → AI
 * 
 * @param int   $post_id   ID posta
 * @param array $locations Lokalizacje z promptami, keywords i brandami
 * @return array Tablica z wynikami (attachment_id dla każdej lokalizacji)
 */
function aai_generate_content_images( $post_id, $locations ) {
    $results = array();
    
    foreach ( $locations as $index => $location ) {
        $image_number = $index + 1;
        $attachment_id = null;
        $source_used = 'ai_generated';
        $tokens = array();
        
        // Pobierz keywords i brandy z analizy AI
        $keywords = isset( $location['keywords'] ) ? $location['keywords'] : array();
        $detected_brands = isset( $location['detected_brands'] ) ? $location['detected_brands'] : array();
        
        // Walidacja: sprawdź czy wykryte brandy faktycznie występują w prompcie/kontekście
        // To zapobiega sytuacji gdy AI przypisuje brandy z innych paragrafów
        $prompt_text = isset( $location['prompt'] ) ? strtolower( $location['prompt'] ) : '';
        $reason_text = isset( $location['reason'] ) ? strtolower( $location['reason'] ) : '';
        $context_for_validation = $prompt_text . ' ' . $reason_text;
        
        $validated_brands = array();
        foreach ( $detected_brands as $brand_name ) {
            // Sprawdź czy brand jest wspomniany w kontekście tej lokalizacji
            if ( stripos( $context_for_validation, strtolower( $brand_name ) ) !== false ) {
                $validated_brands[] = $brand_name;
            }
        }
        
        // Użyj tylko zwalidowanych brandów
        $detected_brands = $validated_brands;
        
        // Konwertuj brandy na format oczekiwany przez funkcję źródeł
        $brands = array();
        if ( ! empty( $detected_brands ) ) {
            $known_brands = aai_get_known_brands();
            foreach ( $detected_brands as $brand_name ) {
                $brand_lower = strtolower( $brand_name );
                if ( isset( $known_brands[ $brand_lower ] ) ) {
                    $brands[ $brand_lower ] = $known_brands[ $brand_lower ];
                }
            }
        }
        
        // Spróbuj znaleźć obrazek z różnych źródeł
        $source_result = aai_find_best_image_source( $post_id, $location['prompt'], $keywords, $brands );
        
        if ( $source_result['found'] ) {
            // Znaleziono obrazek w jednym ze źródeł
            $attachment_id = aai_get_image_from_source( $source_result, $post_id, 'Image ' . $image_number );
            
            if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
                $source_used = $source_result['source'];
            } else {
                // Fallback do AI jeśli pobieranie nie powiodło się
                $attachment_id = null;
            }
        }
        
        // Jeśli nie znaleziono - użyj AI jako fallback
        if ( ! $attachment_id && aai_get_option( 'source_ai_fallback', true ) ) {
            // Dodaj styl do promptu
            $style = aai_get_style_description();
            $colors = aai_get_colors_description();
            $base_prompt = aai_get_option( 'base_prompt', '' );
            $image_language = aai_get_option( 'image_language', 'pl' );
            
            $full_prompt = $location['prompt'];
            if ( ! empty( $style ) ) {
                $full_prompt .= "\n\nArt style: " . $style;
            }
            if ( ! empty( $colors ) ) {
                $full_prompt .= "\nColor palette: " . $colors;
            }
            if ( ! empty( $base_prompt ) ) {
                $full_prompt .= "\nAdditional guidelines: " . $base_prompt;
            }
            
            // Dodaj instrukcję językową
            $language_instruction = aai_get_language_instruction( $image_language );
            $full_prompt .= "\n\n" . $language_instruction;
            
            // Generuj obrazek przez AI
            $image_result = aai_generate_image( $full_prompt );
            
            if ( is_wp_error( $image_result ) ) {
                $results[] = array(
                    'success'         => false,
                    'paragraph_index' => $location['paragraph_index'],
                    'position'        => $location['position'],
                    'error'           => $image_result->get_error_message(),
                );
                continue;
            }
            
            // Zapisz do Media Library
            // Przygotuj metadane dla unified saver
            $post = get_post( $post_id );
            $meta = array(
                'title'   => aai_generate_seo_title( $post, 'content', strval( $image_number ) ),
                'alt'     => aai_generate_seo_alt( $post, 'content', strval( $image_number ) ),
                'source'  => 'ai_generated',
                'context' => 'ilustracja-' . strval( $image_number ),
            );
            
            $attachment_id = aai_save_remote_image( $image_result['image_data'], $post_id, $meta );
            
            $source_used = 'ai_generated';
            $tokens = isset( $image_result['tokens'] ) ? $image_result['tokens'] : array();
        }
        
        if ( is_wp_error( $attachment_id ) ) {
            $results[] = array(
                'success'         => false,
                'paragraph_index' => $location['paragraph_index'],
                'position'        => $location['position'],
                'error'           => $attachment_id->get_error_message(),
            );
            continue;
        }
        
        if ( ! $attachment_id ) {
            $results[] = array(
                'success'         => false,
                'paragraph_index' => $location['paragraph_index'],
                'position'        => $location['position'],
                'error'           => __( 'Nie udało się znaleźć ani wygenerować obrazka.', 'agencyjnie-ai-images' ),
            );
            continue;
        }
        
        // Dodaj meta informacje
        update_post_meta( $attachment_id, '_aai_content_image', true );
        update_post_meta( $attachment_id, '_aai_paragraph_index', $location['paragraph_index'] );
        update_post_meta( $attachment_id, '_aai_image_number', $image_number );
        
        // Zapisz źródło (jeśli nie było wcześniej zapisane)
        if ( ! get_post_meta( $attachment_id, '_aai_source', true ) ) {
            update_post_meta( $attachment_id, '_aai_source', $source_used );
        }
        
        $results[] = array(
            'success'         => true,
            'paragraph_index' => $location['paragraph_index'],
            'position'        => $location['position'],
            'attachment_id'   => $attachment_id,
            'url'             => wp_get_attachment_url( $attachment_id ),
            'source'          => $source_used,
            'tokens'          => $tokens,
        );
    }
    
    return $results;
}

/**
 * Wstawia obrazki do treści artykułu
 * 
 * @param int   $post_id ID posta
 * @param array $images  Tablica z wynikami generowania
 * @return bool|WP_Error True jeśli sukces, WP_Error jeśli błąd
 */
function aai_insert_images_to_content( $post_id, $images ) {
    $post = get_post( $post_id );
    
    if ( ! $post ) {
        return new WP_Error( 'invalid_post', __( 'Nie znaleziono posta.', 'agencyjnie-ai-images' ) );
    }
    
    $content = $post->post_content;
    
    // Filtruj tylko udane obrazki
    $successful_images = array_filter( $images, function( $img ) {
        return $img['success'] === true;
    } );
    
    if ( empty( $successful_images ) ) {
        return new WP_Error( 'no_images', __( 'Brak obrazków do wstawienia.', 'agencyjnie-ai-images' ) );
    }
    
    // Sortuj od końca, żeby indeksy się nie przesuwały
    usort( $successful_images, function( $a, $b ) {
        return $b['paragraph_index'] - $a['paragraph_index'];
    } );
    
    // Podziel treść na paragrafy (obsługa Gutenberga i klasycznego edytora)
    $is_gutenberg = strpos( $content, '<!-- wp:' ) !== false;
    
    if ( $is_gutenberg ) {
        $content = aai_insert_images_gutenberg( $content, $successful_images );
    } else {
        $content = aai_insert_images_classic( $content, $successful_images );
    }
    
    // Zaktualizuj post
    $update_result = wp_update_post( array(
        'ID'           => $post_id,
        'post_content' => $content,
    ), true );
    
    if ( is_wp_error( $update_result ) ) {
        return $update_result;
    }
    
    return true;
}

/**
 * Wstawia obrazki do treści Gutenberga
 */
function aai_insert_images_gutenberg( $content, $images ) {
    // Znajdź wszystkie bloki paragrafów
    $pattern = '/(<!-- wp:paragraph.*?-->.*?<!-- \/wp:paragraph -->)/s';
    preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );
    
    if ( empty( $matches[0] ) ) {
        // Fallback - spróbuj z klasycznym
        return aai_insert_images_classic( $content, $images );
    }
    
    $paragraphs = $matches[0];
    
    foreach ( $images as $image ) {
        $para_index = $image['paragraph_index'] - 1; // Konwersja na 0-based
        
        if ( ! isset( $paragraphs[ $para_index ] ) ) {
            continue;
        }
        
        // Utwórz blok obrazka Gutenberga
        $image_block = aai_create_gutenberg_image_block( $image['attachment_id'] );
        
        $para_content = $paragraphs[ $para_index ][0];
        $para_offset = $paragraphs[ $para_index ][1];
        
        if ( $image['position'] === 'before' ) {
            // Wstaw przed paragrafem
            $content = substr_replace( $content, $image_block . "\n\n" . $para_content, $para_offset, strlen( $para_content ) );
        } else {
            // Wstaw po paragrafie
            $content = substr_replace( $content, $para_content . "\n\n" . $image_block, $para_offset, strlen( $para_content ) );
        }
        
        // Przelicz offsety (uproszczone - działa bo sortujemy od końca)
    }
    
    return $content;
}

/**
 * Wstawia obrazki do treści klasycznego edytora
 * Używa bezpieczniejszego parsowania niż zwykły regex
 */
function aai_insert_images_classic( $content, $images ) {
    // Jeśli dostępny jest WP_HTML_Tag_Processor (WP 6.2+), użyj go
    // Niestety WP_HTML_Tag_Processor nie wspiera jeszcze wstawiania węzłów (tylko modyfikację atrybutów)
    // Więc musimy użyć DOMDocument dla bezpieczeństwa
    
    if ( ! class_exists( 'DOMDocument' ) ) {
        // Fallback do starej metody regex (mniej bezpiecznej ale działa bez libxml)
        return aai_insert_images_classic_regex( $content, $images );
    }
    
    // Obsługa błędów libxml
    $previous_value = libxml_use_internal_errors( true );
    
    $dom = new DOMDocument();
    
    // Dodaj wrapper utf-8 żeby nie psuło polskich znaków
    $content_with_encoding = '<?xml encoding="UTF-8">' . $content;
    
    // Ładuj HTML (używamy loadHTML bo loadXML jest zbyt restrykcyjny dla user content)
    // Opcje: LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD zapobiegają dodawaniu <html><body>
    $dom->loadHTML( $content_with_encoding, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
    
    libxml_clear_errors();
    libxml_use_internal_errors( $previous_value );
    
    // Znajdź wszystkie paragrafy
    $paragraphs = $dom->getElementsByTagName( 'p' );
    $para_count = $paragraphs->length;
    
    // Sortuj obrazki od końca (ważne przy modyfikacji DOM w pętli?)
    // W DOMDocument nie musimy sortować od końca jeśli operujemy na węzłach, ale dla porządku zostawmy
    
    foreach ( $images as $image ) {
        $para_index = $image['paragraph_index'] - 1; // 0-based
        
        if ( $para_index < 0 || $para_index >= $para_count ) {
            continue;
        }
        
        $target_para = $paragraphs->item( $para_index );
        if ( ! $target_para ) {
            continue;
        }
        
        // Stwórz fragment HTML z obrazkiem
        $img_html = aai_create_classic_image_html( $image['attachment_id'] );
        $fragment = $dom->createDocumentFragment();
        
        // Hack na wstawienie HTML do fragmentu
        // appendXML działa tylko dla valid XML, user content może nie być
        // Dlatego tymczasowo ładujemy HTML do nowego DOM i importujemy
        $temp_dom = new DOMDocument();
        $temp_dom->loadHTML( '<?xml encoding="UTF-8">' . $img_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        
        foreach ( $temp_dom->childNodes as $node ) {
            $imported_node = $dom->importNode( $node, true );
            $fragment->appendChild( $imported_node );
        }
        
        if ( $image['position'] === 'before' ) {
            $target_para->parentNode->insertBefore( $fragment, $target_para );
        } else {
            // Insert after: nie ma insertAfter, więc używamy insertBefore na następnym
            if ( $target_para->nextSibling ) {
                $target_para->parentNode->insertBefore( $fragment, $target_para->nextSibling );
            } else {
                $target_para->parentNode->appendChild( $fragment );
            }
        }
    }
    
    // Zwróć HTML (bez wrappera XML)
    return $dom->saveHTML();
}

/**
 * Fallback: Wstawia obrazki metodą Regex (dla serwerów bez DOMDocument)
 */
function aai_insert_images_classic_regex( $content, $images ) {
    // Podziel na paragrafy
    $content = wpautop( $content );
    $parts = preg_split( '/(<p[^>]*>.*?<\/p>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
    
    // Znajdź indeksy paragrafów
    $para_indices = array();
    $para_count = 0;
    foreach ( $parts as $index => $part ) {
        if ( preg_match( '/<p[^>]*>/', $part ) ) {
            $para_count++;
            $para_indices[ $para_count ] = $index;
        }
    }
    
    // Wstaw obrazki (od końca)
    foreach ( $images as $image ) {
        $para_index = $image['paragraph_index'];
        
        if ( ! isset( $para_indices[ $para_index ] ) ) {
            continue;
        }
        
        $part_index = $para_indices[ $para_index ];
        $img_html = aai_create_classic_image_html( $image['attachment_id'] );
        
        if ( $image['position'] === 'before' ) {
            // Wstaw przed
            array_splice( $parts, $part_index, 0, array( $img_html . "\n" ) );
        } else {
            // Wstaw po
            array_splice( $parts, $part_index + 1, 0, array( "\n" . $img_html ) );
        }
    }
    
    return implode( '', $parts );
}

/**
 * Tworzy blok obrazka Gutenberga
 */
function aai_create_gutenberg_image_block( $attachment_id ) {
    $url = wp_get_attachment_url( $attachment_id );
    $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
    
    $block = '<!-- wp:image {"id":' . $attachment_id . ',"sizeSlug":"large","linkDestination":"none","className":"aai-generated-image"} -->';
    $block .= '<figure class="wp-block-image size-large aai-generated-image">';
    $block .= '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $attachment_id . '"/>';
    $block .= '</figure>';
    $block .= '<!-- /wp:image -->';
    
    return $block;
}

/**
 * Tworzy HTML obrazka dla klasycznego edytora
 */
function aai_create_classic_image_html( $attachment_id ) {
    $url = wp_get_attachment_url( $attachment_id );
    $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
    
    return '<p class="aai-generated-image"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="aligncenter size-large wp-image-' . $attachment_id . '" /></p>';
}

/**
 * AJAX handler do generowania obrazków w treści
 */
function aai_ajax_generate_content_images() {
    // Weryfikacja nonce
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Błąd bezpieczeństwa.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Sprawdzenie uprawnień
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'agencyjnie-ai-images' ) ) );
    }
    
    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $step = isset( $_POST['step'] ) ? sanitize_text_field( $_POST['step'] ) : 'analyze';
    
    if ( ! $post_id ) {
        wp_send_json_error( array( 'message' => __( 'Nieprawidłowy ID posta.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Krok 1: Analiza treści
    if ( $step === 'analyze' ) {
        // Użyj 0 żeby automatycznie obliczyć optymalną liczbę obrazków
        $locations = aai_analyze_content_for_images( $post_id, 0 );
        
        if ( is_wp_error( $locations ) ) {
            wp_send_json_error( array( 'message' => $locations->get_error_message() ) );
        }
        
        if ( empty( $locations ) ) {
            wp_send_json_error( array( 'message' => __( 'AI nie znalazło odpowiednich miejsc na obrazki w tej treści.', 'agencyjnie-ai-images' ) ) );
        }
        
        wp_send_json_success( array(
            'step'      => 'analyze',
            'locations' => $locations,
            'count'     => count( $locations ),
            'message'   => sprintf( __( 'Znaleziono %d miejsc na obrazki. Generowanie...', 'agencyjnie-ai-images' ), count( $locations ) ),
        ) );
    }
    
    // Krok 2: Generowanie pojedynczego obrazka
    if ( $step === 'generate' ) {
        $location = isset( $_POST['location'] ) ? $_POST['location'] : null;
        
        if ( ! $location || ! isset( $location['prompt'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak danych lokalizacji.', 'agencyjnie-ai-images' ) ) );
        }
        
        // Sanityzacja
        $location = array(
            'paragraph_index' => intval( $location['paragraph_index'] ),
            'position'        => $location['position'] === 'before' ? 'before' : 'after',
            'prompt'          => sanitize_text_field( $location['prompt'] ),
        );
        
        $results = aai_generate_content_images( $post_id, array( $location ) );
        
        if ( empty( $results ) ) {
            wp_send_json_error( array( 'message' => __( 'Błąd generowania obrazka.', 'agencyjnie-ai-images' ) ) );
        }
        
        $result = $results[0];
        
        if ( ! $result['success'] ) {
            wp_send_json_error( array( 'message' => $result['error'] ) );
        }
        
        wp_send_json_success( array(
            'step'   => 'generate',
            'result' => $result,
        ) );
    }
    
    // Krok 3: Wstawienie obrazków do treści
    if ( $step === 'insert' ) {
        $images = isset( $_POST['images'] ) ? $_POST['images'] : array();
        
        if ( empty( $images ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak obrazków do wstawienia.', 'agencyjnie-ai-images' ) ) );
        }
        
        // Sanityzacja
        $sanitized_images = array();
        foreach ( $images as $img ) {
            $sanitized_images[] = array(
                'success'         => true,
                'paragraph_index' => intval( $img['paragraph_index'] ),
                'position'        => $img['position'] === 'before' ? 'before' : 'after',
                'attachment_id'   => intval( $img['attachment_id'] ),
            );
        }
        
        $insert_result = aai_insert_images_to_content( $post_id, $sanitized_images );
        
        if ( is_wp_error( $insert_result ) ) {
            wp_send_json_error( array( 'message' => $insert_result->get_error_message() ) );
        }
        
        wp_send_json_success( array(
            'step'    => 'insert',
            'message' => sprintf( __( 'Wstawiono %d obrazków do treści!', 'agencyjnie-ai-images' ), count( $sanitized_images ) ),
        ) );
    }
    
    wp_send_json_error( array( 'message' => __( 'Nieznany krok.', 'agencyjnie-ai-images' ) ) );
}
add_action( 'wp_ajax_aai_generate_content_images', 'aai_ajax_generate_content_images' );
