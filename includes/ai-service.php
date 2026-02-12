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
function aai_generate_image( $prompt, $aspect_ratio = null ) {
    $model = aai_get_option( 'ai_model', 'gemini' );

    if ( $model === 'dalle3' ) {
        return aai_generate_image_dalle( $prompt, $aspect_ratio );
    }

    // Mapowanie ustawień na nazwy modeli Gemini
    $gemini_models = array(
        'gemini'     => 'gemini-2.5-flash-image',
        'gemini-pro' => 'gemini-2.5-pro-preview-06-05',
    );
    $gemini_model = isset( $gemini_models[ $model ] ) ? $gemini_models[ $model ] : 'gemini-2.5-flash-image';

    return aai_generate_image_gemini( $prompt, $aspect_ratio, $gemini_model );
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
function aai_generate_image_gemini( $prompt, $aspect_ratio = null, $gemini_model = 'gemini-2.5-flash-image' ) {
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
                'parts' => array(
                    array(
                        'text' => $prompt,
                    ),
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
 * Test połączenia z Urlbox API
 */
function aai_test_urlbox_connection() {
    check_ajax_referer( 'aai_generate_image', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień', 'agencyjnie-ai-images' ) ) );
    }
    
    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
    
    if ( empty( $api_key ) ) {
        wp_send_json_error( array( 'message' => __( 'Wprowadź klucz API', 'agencyjnie-ai-images' ) ) );
    }
    
    // Urlbox - sprawdź format klucza (powinien być długi string)
    if ( strlen( $api_key ) < 20 ) {
        wp_send_json_error( array( 'message' => __( 'Klucz API wygląda na zbyt krótki', 'agencyjnie-ai-images' ) ) );
    }
    
    // Urlbox używa Bearer token authentication
    $response = wp_remote_post( 'https://api.urlbox.io/v1/render/sync', array(
        'timeout'   => 30,
        'sslverify' => false, // Wyłącz weryfikację SSL dla DDEV
        'headers'   => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode( array(
            'url'    => 'https://example.com',
            'format' => 'png',
            'width'  => 100,
            'height' => 100,
        ) ),
    ) );
    
    if ( is_wp_error( $response ) ) {
        $error_code = $response->get_error_code();
        $error_msg = $response->get_error_message();
        wp_send_json_error( array( 
            'message' => sprintf( __( 'Błąd połączenia (%s): %s', 'agencyjnie-ai-images' ), $error_code, $error_msg ) 
        ) );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    $raw_body = wp_remote_retrieve_body( $response );
    $body = json_decode( $raw_body, true );
    
    if ( $response_code === 200 && isset( $body['renderUrl'] ) ) {
        wp_send_json_success( array( 'message' => __( 'Urlbox API działa poprawnie!', 'agencyjnie-ai-images' ) ) );
    } elseif ( $response_code === 401 ) {
        wp_send_json_error( array( 'message' => __( 'Nieprawidłowy klucz API Urlbox', 'agencyjnie-ai-images' ) ) );
    } elseif ( $response_code === 403 ) {
        wp_send_json_error( array( 'message' => __( 'Brak dostępu - sprawdź uprawnienia klucza Urlbox', 'agencyjnie-ai-images' ) ) );
    } elseif ( $response_code === 402 ) {
        wp_send_json_error( array( 'message' => __( 'Wyczerpano limit Urlbox lub wymagana płatność', 'agencyjnie-ai-images' ) ) );
    } else {
        // Parsuj błąd z odpowiedzi
        $error_msg = '';
        if ( isset( $body['error'] ) ) {
            $error_msg = is_array( $body['error'] ) ? wp_json_encode( $body['error'] ) : $body['error'];
        } elseif ( isset( $body['message'] ) ) {
            $error_msg = is_array( $body['message'] ) ? wp_json_encode( $body['message'] ) : $body['message'];
        } elseif ( isset( $body['errors'] ) ) {
            $error_msg = is_array( $body['errors'] ) ? wp_json_encode( $body['errors'] ) : $body['errors'];
        } else {
            $error_msg = $raw_body;
        }
        wp_send_json_error( array( 'message' => sprintf( __( 'Błąd Urlbox (kod %d): %s', 'agencyjnie-ai-images' ), $response_code, $error_msg ) ) );
    }
}
// DISABLED: add_action( 'wp_ajax_aai_test_urlbox', 'aai_test_urlbox_connection' );

/**
 * Test połączenia z Unsplash API
 */
function aai_test_unsplash_connection() {
    check_ajax_referer( 'aai_generate_image', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień', 'agencyjnie-ai-images' ) ) );
    }
    
    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
    
    if ( empty( $api_key ) ) {
        wp_send_json_error( array( 'message' => __( 'Wprowadź klucz API', 'agencyjnie-ai-images' ) ) );
    }
    
    // Test przez pobranie losowego zdjęcia
    $response = wp_remote_get( 'https://api.unsplash.com/photos/random?count=1', array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Client-ID ' . $api_key,
        ),
    ) );
    
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array( 
            'message' => sprintf( __( 'Błąd połączenia: %s', 'agencyjnie-ai-images' ), $response->get_error_message() ) 
        ) );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    
    if ( $response_code === 200 ) {
        $remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
        $message = __( 'Unsplash API działa poprawnie!', 'agencyjnie-ai-images' );
        if ( $remaining !== '' ) {
            $message .= ' ' . sprintf( __( '(Pozostało: %s req/godz.)', 'agencyjnie-ai-images' ), $remaining );
        }
        wp_send_json_success( array( 'message' => $message ) );
    } elseif ( $response_code === 401 ) {
        wp_send_json_error( array( 'message' => __( 'Nieprawidłowy klucz API Unsplash', 'agencyjnie-ai-images' ) ) );
    } elseif ( $response_code === 403 ) {
        wp_send_json_error( array( 'message' => __( 'Przekroczono limit Unsplash lub klucz nieaktywny', 'agencyjnie-ai-images' ) ) );
    } else {
        wp_send_json_error( array( 'message' => sprintf( __( 'Błąd Unsplash (kod %d)', 'agencyjnie-ai-images' ), $response_code ) ) );
    }
}
// DISABLED: add_action( 'wp_ajax_aai_test_unsplash', 'aai_test_unsplash_connection' );

/**
 * Test połączenia z Pexels API
 */
function aai_test_pexels_connection() {
    check_ajax_referer( 'aai_generate_image', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień', 'agencyjnie-ai-images' ) ) );
    }
    
    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
    
    if ( empty( $api_key ) ) {
        wp_send_json_error( array( 'message' => __( 'Wprowadź klucz API', 'agencyjnie-ai-images' ) ) );
    }
    
    // Test przez proste wyszukiwanie
    $response = wp_remote_get( 'https://api.pexels.com/v1/search?query=test&per_page=1', array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => $api_key,
        ),
    ) );
    
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array( 
            'message' => sprintf( __( 'Błąd połączenia: %s', 'agencyjnie-ai-images' ), $response->get_error_message() ) 
        ) );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    
    if ( $response_code === 200 ) {
        $remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
        $message = __( 'Pexels API działa poprawnie!', 'agencyjnie-ai-images' );
        if ( $remaining !== '' ) {
            $message .= ' ' . sprintf( __( '(Pozostało: %s req/mies.)', 'agencyjnie-ai-images' ), $remaining );
        }
        wp_send_json_success( array( 'message' => $message ) );
    } elseif ( $response_code === 401 ) {
        wp_send_json_error( array( 'message' => __( 'Nieprawidłowy klucz API Pexels', 'agencyjnie-ai-images' ) ) );
    } elseif ( $response_code === 429 ) {
        wp_send_json_error( array( 'message' => __( 'Przekroczono limit Pexels', 'agencyjnie-ai-images' ) ) );
    } else {
        wp_send_json_error( array( 'message' => sprintf( __( 'Błąd Pexels (kod %d)', 'agencyjnie-ai-images' ), $response_code ) ) );
    }
}
// DISABLED: add_action( 'wp_ajax_aai_test_pexels', 'aai_test_pexels_connection' );

/**
 * Test połączenia z Brandfetch API
 */
function aai_test_brandfetch_connection() {
    check_ajax_referer( 'aai_generate_image', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień', 'agencyjnie-ai-images' ) ) );
    }
    
    $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
    
    if ( empty( $api_key ) ) {
        wp_send_json_error( array( 'message' => __( 'Wprowadź klucz API', 'agencyjnie-ai-images' ) ) );
    }
    
    // Test przez pobranie info o znanej marce (Google)
    $response = wp_remote_get( 'https://api.brandfetch.io/v2/brands/google.com', array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
        ),
    ) );
    
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array( 
            'message' => sprintf( __( 'Błąd połączenia: %s', 'agencyjnie-ai-images' ), $response->get_error_message() ) 
        ) );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    
    if ( $response_code === 200 ) {
        wp_send_json_success( array( 'message' => __( 'Brandfetch API działa poprawnie!', 'agencyjnie-ai-images' ) ) );
    } elseif ( $response_code === 401 ) {
        wp_send_json_error( array( 'message' => __( 'Nieprawidłowy klucz API Brandfetch', 'agencyjnie-ai-images' ) ) );
    } elseif ( $response_code === 403 ) {
        wp_send_json_error( array( 'message' => __( 'Brak dostępu - sprawdź uprawnienia klucza', 'agencyjnie-ai-images' ) ) );
    } elseif ( $response_code === 429 ) {
        wp_send_json_error( array( 'message' => __( 'Przekroczono limit Brandfetch', 'agencyjnie-ai-images' ) ) );
    } else {
        wp_send_json_error( array( 'message' => sprintf( __( 'Błąd Brandfetch (kod %d)', 'agencyjnie-ai-images' ), $response_code ) ) );
    }
}
// DISABLED: add_action( 'wp_ajax_aai_test_brandfetch', 'aai_test_brandfetch_connection' );
