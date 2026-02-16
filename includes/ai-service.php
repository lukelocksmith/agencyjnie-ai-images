<?php
/**
 * Komunikacja z AI API (Gemini i DALL-E 3)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ładowanie DALL-E API
require_once __DIR__ . '/dalle-api.php';

/**
 * Wrapper - generowanie obrazka przez wybrany model AI
 * 
 * @param string $prompt Prompt do wygenerowania obrazka
 * @return array|WP_Error Tablica z danymi obrazka lub błąd
 */
function aai_generate_image( $prompt, $aspect_ratio = null, $system_instruction = null ) {
    $model = aai_get_option( 'ai_model', 'gemini' );

    if ( $model === 'dalle3' ) {
        return aai_generate_image_dalle( $prompt, $aspect_ratio );
    }

    // Mapowanie ustawień na nazwy modeli Gemini
    $gemini_models = array(
        'gemini'     => 'gemini-2.5-flash-image', // Default (Flash)
        'gemini-pro' => 'gemini-3-pro-image-preview', // Pro (Gemini 3)
        'imagen3'    => 'imagen-3.0-generate-001', // Imagen 3 (Specialized)
    );
    $gemini_model = isset( $gemini_models[ $model ] ) ? $gemini_models[ $model ] : 'gemini-2.5-flash-image';

    // Pass system instruction if available
    return aai_generate_image_gemini( $prompt, $aspect_ratio, $gemini_model, $system_instruction );
}

/**
 * Generuje tekst alternatywny (ALT) dla obrazka
 * 
 * @param string $prompt Prompt, z którego wygenerowano obrazek
 * @param string $context_text Tekst kontekstowy (fragment artykułu)
 * @param string $lang Kod języka (np. 'pl')
 * @return string|WP_Error Wygenerowany opis ALT
 */
function aai_generate_alt_text( $prompt, $context_text = '', $lang = 'pl' ) {
    $api_key = aai_get_secure_option( 'api_key' );
    
    if ( empty( $api_key ) ) {
        // Fallback do OpenAI jeśli brak Gemini
        $api_key = aai_get_secure_option( 'openai_api_key' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'Brak klucza API.', 'agencyjnie-ai-images' ) );
        }
        // TODO: Obsługa OpenAI dla tekstu (na razie tylko Gemini)
        return new WP_Error( 'openai_not_supported', __( 'Generowanie tekstu wymaga klucza Gemini.', 'agencyjnie-ai-images' ) );
    }
    
    // Ustal nazwę języka
    $languages = array(
        'pl' => 'Polish', 'en' => 'English', 'de' => 'German', 
        'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian'
    );
    $language_name = isset( $languages[ $lang ] ) ? $languages[ $lang ] : 'Polish';
    
    // Buduj prompt dla generatora tekstu
    $system_instruction = "You are an SEO and Accessibility (a11y) expert. Your task is to write a concise, descriptive alternative text (ALT attribute) for an image.";
    
    $user_prompt = "Based on the following image generation prompt and article context, write a short, descriptive ALT text in {$language_name}.
    
    IMAGE PROMPT: \"{$prompt}\"
    
    CONTEXT: \"{$context_text}\"
    
    GUIDELINES:
    - Describe what the image depicts concisely (max 15-20 words).
    - Focus on the main subject and action.
    - Do NOT start with 'Image of', 'Picture of', etc.
    - Include relevant keywords naturally.
    - Output ONLY the ALT text, no quotation marks or explanations.";
    
    // Wywołaj Gemini API (model tekstowy)
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    $request_body = array(
        'contents' => array(
            array(
                'parts' => array(
                    array( 'text' => $system_instruction . "\n\n" . $user_prompt )
                )
            )
        )
    );

    $response = wp_remote_post( $api_url, array(
        'timeout' => 15,
        'headers' => array(
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ),
        'body'    => wp_json_encode( $request_body )
    ) );
    
    if ( is_wp_error( $response ) ) {
        return $response;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
        $alt_text = trim( $data['candidates'][0]['content']['parts'][0]['text'] );
        // Usuń ewentualne cudzysłowy
        $alt_text = trim( $alt_text, '"\'' );
        return $alt_text;
    }
    
    return new WP_Error( 'api_error', __( 'Nie udało się wygenerować opisu ALT.', 'agencyjnie-ai-images' ) );
}

/**
 * Generowanie obrazka przez Gemini API
 * 
 * @param string $prompt Prompt do wygenerowania obrazka
 * @return array|WP_Error Tablica z danymi obrazka lub błąd
 */
function aai_generate_image_gemini( $prompt, $aspect_ratio = null, $gemini_model = 'gemini-2.5-flash-image', $system_instruction = null ) {
    $api_key = aai_get_secure_option( 'api_key' );

    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', __( 'Brak klucza API Gemini.', 'agencyjnie-ai-images' ) );
    }

    if ( empty( $prompt ) ) {
        return new WP_Error( 'empty_prompt', __( 'Prompt nie może być pusty.', 'agencyjnie-ai-images' ) );
    }

    // Endpoint Gemini API z dynamicznym modelem
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $gemini_model . ':generateContent';

    // Pobierz aspect ratio z parametru lub z ustawień
    if ( $aspect_ratio === null ) {
        $aspect_ratio = aai_get_option( 'aspect_ratio', '16:9' );
    }
    
    // Przygotowanie body requestu
    $request_body = array(
        'contents' => array(
            array(
                'role' => 'user',
                'parts' => array_merge(
                    aai_get_processed_reference_images(),
                    array(
                        array(
                            'text' => $prompt,
                        ),
                    )
                ),
            ),
        ),
        'generationConfig' => array(
            'responseModalities' => array( 'IMAGE', 'TEXT' ),
            'imageConfig' => array(
                'aspectRatio' => $aspect_ratio,
            ),
        ),
        'safetySettings' => array(
            array( 'category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
            array( 'category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH' ),
            array( 'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
            array( 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
        ),
    );

    // Dodaj System Instruction jeśli dostępna
    if ( ! empty( $system_instruction ) ) {
        $request_body['systemInstruction'] = array(
            'parts' => array(
                array( 'text' => $system_instruction )
            )
        );
    }
    
    // Wykonanie requestu (klucz API w headerze zamiast query string)
    $response = wp_remote_post( $api_url, array(
        'timeout'     => 120, // Generowanie obrazka może trwać dłużej
        'headers'     => array(
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ),
        'body'        => wp_json_encode( $request_body ),
    ) );
    
    // Sprawdzenie błędów połączenia
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 
            'connection_error', 
            sprintf( 
                __( 'Błąd połączenia z API: %s', 'agencyjnie-ai-images' ), 
                $response->get_error_message() 
            ) 
        );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );
    $data = json_decode( $response_body, true );
    
    // Sprawdzenie kodu odpowiedzi HTTP
    if ( $response_code !== 200 ) {
        $error_message = __( 'Błąd API Gemini.', 'agencyjnie-ai-images' );
        
        // Wyciągnij szczegóły błędu z odpowiedzi
        if ( isset( $data['error']['message'] ) ) {
            $error_message = $data['error']['message'];
        }
        
        return new WP_Error( 
            'api_error', 
            sprintf( 
                __( 'Błąd API (kod %d): %s', 'agencyjnie-ai-images' ), 
                $response_code, 
                $error_message 
            ) 
        );
    }
    
    // Parsowanie odpowiedzi - szukamy danych obrazka
    $image_data = aai_extract_image_from_response( $data );
    
    if ( is_wp_error( $image_data ) ) {
        return $image_data;
    }
    
    // Wyciągnij informacje o tokenach z odpowiedzi
    $tokens = aai_extract_token_usage( $data );
    
    return array(
        'image_data' => $image_data,
        'mime_type'  => 'image/png', // Gemini zwraca PNG
        'tokens'     => $tokens,
    );
}

/**
 * Wyciągnięcie informacji o zużyciu tokenów z odpowiedzi API
 * 
 * @param array $response Odpowiedź z API
 * @return array Informacje o tokenach
 */
function aai_extract_token_usage( $response ) {
    $tokens = array(
        'prompt_tokens'     => 0,
        'completion_tokens' => 0,
        'total_tokens'      => 0,
    );
    
    // Gemini API zwraca usageMetadata
    if ( isset( $response['usageMetadata'] ) ) {
        $usage = $response['usageMetadata'];
        
        if ( isset( $usage['promptTokenCount'] ) ) {
            $tokens['prompt_tokens'] = intval( $usage['promptTokenCount'] );
        }
        if ( isset( $usage['candidatesTokenCount'] ) ) {
            $tokens['completion_tokens'] = intval( $usage['candidatesTokenCount'] );
        }
        if ( isset( $usage['totalTokenCount'] ) ) {
            $tokens['total_tokens'] = intval( $usage['totalTokenCount'] );
        } else {
            $tokens['total_tokens'] = $tokens['prompt_tokens'] + $tokens['completion_tokens'];
        }
    }
    
    return $tokens;
}

/**
 * Wyciągnięcie danych obrazka z odpowiedzi API
 * 
 * @param array $response Odpowiedź z API
 * @return string|WP_Error Dane obrazka w base64 lub błąd
 */
function aai_extract_image_from_response( $response ) {
    // Sprawdź strukturę odpowiedzi
    if ( ! isset( $response['candidates'][0]['content']['parts'] ) ) {
        return new WP_Error( 
            'invalid_response', 
            __( 'Nieprawidłowa struktura odpowiedzi z API.', 'agencyjnie-ai-images' ) 
        );
    }
    
    $parts = $response['candidates'][0]['content']['parts'];
    
    // Szukaj części z obrazkiem (inlineData)
    foreach ( $parts as $part ) {
        if ( isset( $part['inlineData']['data'] ) && isset( $part['inlineData']['mimeType'] ) ) {
            // Sprawdź czy to rzeczywiście obrazek
            $mime_type = $part['inlineData']['mimeType'];
            if ( strpos( $mime_type, 'image/' ) === 0 ) {
                return $part['inlineData']['data'];
            }
        }
    }
    
    // Jeśli nie znaleziono obrazka, zwróć błąd
    return new WP_Error( 
        'no_image', 
        __( 'API nie zwróciło obrazka. Spróbuj zmienić prompt.', 'agencyjnie-ai-images' ) 
    );
}

/**
 * Zapisanie obrazka do Media Library z optymalizacją SEO
 * Wrapper dla nowej funkcji aai_save_remote_image (kompatybilność wsteczna)
 */
function aai_save_image_to_media_library( $base64_image, $post_id, $image_type = 'featured', $context = '' ) {
    $post = get_post( $post_id );
    
    // Przygotuj metadane dla unified saver
    // Używamy funkcji z image-utils.php
    $title = $post->post_title;
    $alt   = 'Grafika: ' . $title;
    
    if ( $image_type === 'featured' ) {
        $title .= ' - Obrazek wyróżniający';
        $alt    = 'Grafika ilustrująca artykuł: ' . $post->post_title;
        $context_slug = 'featured-image';
    } elseif ( $image_type === 'content' && ! empty( $context ) ) {
        $title .= ' - Ilustracja ' . $context;
        $alt    = 'Ilustracja do artykułu ' . $post->post_title . ' - sekcja ' . $context;
        $context_slug = 'ilustracja-' . $context;
    } else {
        $context_slug = 'obraz';
    }

    $meta = array(
        'title'   => $title,
        'alt'     => $alt,
        'source'  => 'ai_generated',
        'context' => $context_slug,
    );
    
    $attachment_id = aai_save_remote_image( $base64_image, $post_id, $meta );
    
    if ( is_wp_error( $attachment_id ) ) {
        return $attachment_id;
    }
    
    // Dodatkowe metadane specyficzne dla AI (zachowanie kompatybilności)
    update_post_meta( $attachment_id, '_aai_generated', true );
    update_post_meta( $attachment_id, '_aai_generated_date', current_time( 'mysql' ) );
    update_post_meta( $attachment_id, '_aai_source_post', $post_id );
    update_post_meta( $attachment_id, '_aai_image_type', $image_type );
    
    return $attachment_id;
}

/**
 * Test połączenia z API
 * Używane przez przycisk "Testuj połączenie" na stronie ustawień
 */
function aai_test_api_connection() {
    // Weryfikacja nonce
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Błąd bezpieczeństwa.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Sprawdzenie uprawnień
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Pobierz klucz API — nowy z formularza (jeśli wpisano) lub zapisany w bazie
    $api_key = '';
    if ( isset( $_POST['api_key'] ) && ! empty( $_POST['api_key'] ) && strpos( $_POST['api_key'], '*' ) === false ) {
        $api_key = sanitize_text_field( $_POST['api_key'] );
    } else {
        $api_key = aai_get_secure_option( 'api_key' );
    }

    if ( empty( $api_key ) ) {
        wp_send_json_error( array( 'message' => __( 'Najpierw wprowadź klucz API.', 'agencyjnie-ai-images' ) ) );
    }

    // Prosty test - sprawdzamy czy API odpowiada
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    $request_body = array(
        'contents' => array(
            array(
                'parts' => array(
                    array(
                        'text' => 'Say "API connection successful" in exactly those words.',
                    ),
                ),
            ),
        ),
    );

    $response = wp_remote_post( $api_url, array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ),
        'body'    => wp_json_encode( $request_body ),
    ) );
    
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array( 
            'message' => sprintf( 
                __( 'Błąd połączenia: %s', 'agencyjnie-ai-images' ), 
                $response->get_error_message() 
            ) 
        ) );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    
    if ( $response_code === 200 ) {
        wp_send_json_success( array( 
            'message' => __( 'Połączenie z API działa poprawnie!', 'agencyjnie-ai-images' ) 
        ) );
    } else {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $error_message = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Nieznany błąd', 'agencyjnie-ai-images' );
        
        wp_send_json_error( array( 
            'message' => sprintf( 
                __( 'Błąd API (kod %d): %s', 'agencyjnie-ai-images' ), 
                $response_code, 
                $error_message 
            ) 
        ) );
    }
}
add_action( 'wp_ajax_aai_test_connection', 'aai_test_api_connection' );


/**
 * Helper: Przygotowanie obrazków referencyjnych dla Gemini API
 */
function aai_get_processed_reference_images() {
    $parts = array();
    
    // Pobierz obrazki z opcji
    $reference_images = aai_get_option( 'reference_images', array() );
    
    // Upewnij się że to tablica i ma elementy
    if ( empty( $reference_images ) || ! is_array( $reference_images ) ) {
        return $parts;
    }
    
    // Limit 3
    $reference_images = array_slice( $reference_images, 0, 3 );
    
    foreach ( $reference_images as $img_url ) {
        // Pobierz obrazek
        $response = wp_remote_get( $img_url, array( 'timeout' => 15, 'sslverify' => false ) );
        
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            continue;
        }
        
        $image_content = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        
        if ( empty( $image_content ) ) {
            continue;
        }

        // Gemini obsługuje image/png, image/jpeg, image/webp, image/heic, image/heif
        // Normalizacja MIME type
        if ( empty( $content_type ) || strpos( $content_type, 'image/' ) !== 0 ) {
             $ext = strtolower( pathinfo( $img_url, PATHINFO_EXTENSION ) );
             if ( $ext === 'jpg' || $ext === 'jpeg' ) $content_type = 'image/jpeg';
             elseif ( $ext === 'png' ) $content_type = 'image/png';
             elseif ( $ext === 'webp' ) $content_type = 'image/webp';
             else $content_type = 'image/jpeg'; // Fallback
        }
        
        $base64_data = base64_encode( $image_content );
        
        $parts[] = array(
            'inlineData' => array(
                'mimeType' => $content_type,
                'data'     => $base64_data
            )
        );
    }
    
    return $parts;
}

/**
 * Analizuje artykuł i generuje optymalny prompt do obrazka
 *
 * @param int $post_id ID posta do analizy
 * @return string|WP_Error Wygenerowany prompt lub błąd
 */
function aai_analyze_article_for_prompt( $post_id ) {
    $api_key = aai_get_secure_option( 'api_key' );

    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', __( 'Brak klucza API Gemini.', 'agencyjnie-ai-images' ) );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return new WP_Error( 'no_post', __( 'Post nie istnieje.', 'agencyjnie-ai-images' ) );
    }

    // Get full article content, strip HTML and shortcodes
    $content = $post->post_content;
    $content = strip_shortcodes( $content );
    $content = preg_replace( '/<!--.*?-->/', '', $content );
    $content = wp_strip_all_tags( $content );
    $content = preg_replace( '/\s+/', ' ', $content );
    $content = trim( $content );

    // Limit to 3000 chars to keep token usage low
    if ( mb_strlen( $content ) > 3000 ) {
        $content = mb_substr( $content, 0, 3000 ) . '...';
    }

    $title = $post->post_title;

    // Get current settings for context
    $style = aai_get_style_description();
    $image_language = aai_get_option( 'image_language', 'pl' );
    $language_instruction = aai_get_language_instruction( $image_language );

    $system_instruction = "You are an expert AI image prompt engineer. Your task is to analyze a blog article and create the PERFECT image generation prompt for its featured image. The prompt should be detailed, visual, and produce a stunning blog header image.";

    $user_prompt = "Analyze this article and create an optimized image generation prompt for a featured image.\n\n";
    $user_prompt .= "ARTICLE TITLE: \"{$title}\"\n\n";
    $user_prompt .= "ARTICLE CONTENT:\n{$content}\n\n";

    if ( ! empty( $style ) ) {
        $user_prompt .= "ART STYLE TO USE: {$style}\n\n";
    }

    $user_prompt .= "REQUIREMENTS:\n";
    $user_prompt .= "- Create a detailed, visual prompt that captures the article's essence\n";
    $user_prompt .= "- Include specific visual elements, mood, lighting, and composition\n";
    $user_prompt .= "- The prompt should be in English (the AI image generator works best in English)\n";
    $user_prompt .= "- {$language_instruction}\n";
    $user_prompt .= "- Output ONLY the prompt text, nothing else. No explanations, no quotes.\n";
    $user_prompt .= "- Maximum 500 characters.\n";

    // Call Gemini text model (cheap, fast)
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    $request_body = array(
        'systemInstruction' => array(
            'parts' => array(
                array( 'text' => $system_instruction )
            )
        ),
        'contents' => array(
            array(
                'parts' => array(
                    array( 'text' => $user_prompt )
                )
            )
        ),
        'generationConfig' => array(
            'maxOutputTokens' => 300,
            'temperature'     => 0.7,
        ),
    );

    $response = wp_remote_post( $api_url, array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ),
        'body' => wp_json_encode( $request_body ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $response_code !== 200 ) {
        $error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Błąd API';
        return new WP_Error( 'api_error', $error_msg );
    }

    if ( isset( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
        $prompt = trim( $body['candidates'][0]['content']['parts'][0]['text'] );
        // Remove quotes if wrapped
        $prompt = trim( $prompt, '"\'' );
        return $prompt;
    }

    return new WP_Error( 'no_result', __( 'Nie udało się przeanalizować artykułu.', 'agencyjnie-ai-images' ) );
}
