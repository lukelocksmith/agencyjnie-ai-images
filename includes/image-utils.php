<?php
/**
 * Narzędzia pomocnicze do obsługi obrazków i danych
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Szyfrowanie danych (np. kluczy API)
 * 
 * @param string $data Dane do zaszyfrowania
 * @return string Zaszyfrowane dane (base64)
 */
function aai_encrypt( $data ) {
    if ( empty( $data ) ) {
        return $data;
    }
    
    // Jeśli openssl nie jest dostępny, zwróć surowe dane (fallback)
    if ( ! extension_loaded( 'openssl' ) ) {
        return $data;
    }
    
    $method = 'AES-256-CBC';
    $key = wp_salt( 'auth' ); // Użyj soli WordPressa jako klucza
    $iv_length = openssl_cipher_iv_length( $method );
    $iv = openssl_random_pseudo_bytes( $iv_length );
    
    $encrypted = openssl_encrypt( $data, $method, $key, 0, $iv );
    
    // Zwróć IV + zaszyfrowane dane w base64
    return base64_encode( $iv . $encrypted );
}

/**
 * Deszyfrowanie danych
 * 
 * @param string $data Zaszyfrowane dane (base64)
 * @return string Odszyfrowane dane
 */
function aai_decrypt( $data ) {
    if ( empty( $data ) ) {
        return $data;
    }
    
    // Sprawdź czy dane są zaszyfrowane (czy to valid base64)
    if ( ! preg_match( '/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data ) ) {
        return $data; // Zwróć jak jest - prawdopodobnie stare, niezaszyfrowane dane
    }
    
    if ( ! extension_loaded( 'openssl' ) ) {
        return $data;
    }
    
    $method = 'AES-256-CBC';
    $key = wp_salt( 'auth' );
    $iv_length = openssl_cipher_iv_length( $method );
    
    $raw_data = base64_decode( $data );
    
    // Sprawdź długość danych
    if ( strlen( $raw_data ) < $iv_length ) {
        return $data; // Za krótkie na zaszyfrowane dane
    }
    
    $iv = substr( $raw_data, 0, $iv_length );
    $encrypted = substr( $raw_data, $iv_length );
    
    $decrypted = openssl_decrypt( $encrypted, $method, $key, 0, $iv );
    
    // Jeśli deszyfrowanie się nie uda (np. zły klucz lub dane nie były zaszyfrowane tym algorytmem), zwróć oryginał
    if ( $decrypted === false ) {
        return $data;
    }
    
    return $decrypted;
}

/**
 * Pobiera opcję z bezpiecznym deszyfrowaniem
 * Wrapper dla aai_get_option, ale specyficzny dla kluczy API
 */
function aai_get_secure_option( $key, $default = '' ) {
    $value = aai_get_option( $key, $default );
    
    // Lista kluczy, które powinny być zaszyfrowane
    $secure_keys = array(
        'api_key', 
        'openai_api_key', 
        'urlbox_api_key', 
        'unsplash_api_key', 
        'pexels_api_key', 
        'brandfetch_api_key'
    );
    
    if ( in_array( $key, $secure_keys, true ) ) {
        return aai_decrypt( $value );
    }
    
    return $value;
}

/**
 * Zapisuje obrazek (z URL lub Base64) do Biblioteki Mediów WordPress
 * 
 * @param string $image_data URL do obrazka LUB string base64
 * @param int    $post_id    ID posta, do którego przypisać obrazek (opcjonalne)
 * @param array  $metadata   Dodatkowe metadane (tytuł, alt, źródło, kontekst)
 * @return int|WP_Error      ID załącznika lub błąd
 */
function aai_save_remote_image( $image_data, $post_id = 0, $metadata = array() ) {
    // Domyślne metadane
    $defaults = array(
        'title'       => '',
        'alt'         => '',
        'caption'     => '',
        'description' => '',
        'source'      => 'unknown', // np. ai_generated, unsplash, pexels
        'source_url'  => '',
        'context'     => '',        // np. featured, content
    );
    $meta = wp_parse_args( $metadata, $defaults );

    // Generuj nazwę pliku SEO
    $filename_base = aai_generate_seo_filename( $meta['title'], $meta['context'] );
    $filename = $filename_base . '.jpg'; // Domyślne rozszerzenie, zmienimy jeśli trzeba

    $upload_dir = wp_upload_dir();
    $file_content = '';

    // 1. Sprawdź czy to Base64
    if ( preg_match( '/^([A-Za-z0-9+\/]{4})*([A-Za-z0-9+\/]{3}=|[A-Za-z0-9+\/]{2}==)?$/', $image_data ) || base64_encode(base64_decode($image_data, true)) === $image_data ) {
        $file_content = base64_decode( $image_data );
        $filename = $filename_base . '.png'; // DALL-E zwykle zwraca PNG w base64
    } 
    // 2. Sprawdź czy to URL
    elseif ( filter_var( $image_data, FILTER_VALIDATE_URL ) ) {
        // Pobierz zawartość
        $response = wp_remote_get( $image_data, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $file_content = wp_remote_retrieve_body( $response );
        
        // Próba wykrycia rozszerzenia z URL lub Content-Type
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        $ext = '';
        if ( $content_type ) {
            // Mapowanie MIME type na rozszerzenie
            if ( strpos( $content_type, 'image/jpeg' ) !== false ) $ext = 'jpg';
            elseif ( strpos( $content_type, 'image/png' ) !== false ) $ext = 'png';
            elseif ( strpos( $content_type, 'image/webp' ) !== false ) $ext = 'webp';
            elseif ( strpos( $content_type, 'image/gif' ) !== false ) $ext = 'gif';
            elseif ( strpos( $content_type, 'image/svg' ) !== false ) $ext = 'svg';
        }
        
        if ( ! $ext ) {
            $path_info = pathinfo( parse_url( $image_data, PHP_URL_PATH ) );
            if ( isset( $path_info['extension'] ) ) {
                $ext = $path_info['extension'];
            }
        }
        
        if ( $ext ) {
            $filename = $filename_base . '.' . $ext;
        }
    } else {
        return new WP_Error( 'invalid_image_data', __( 'Nieprawidłowe dane obrazka.', 'agencyjnie-ai-images' ) );
    }

    if ( empty( $file_content ) ) {
        return new WP_Error( 'empty_content', __( 'Pobrany plik jest pusty.', 'agencyjnie-ai-images' ) );
    }

    // Zapisz plik na dysku używając wp_upload_bits (obsługuje unikalne nazwy automatycznie)
    $upload_result = wp_upload_bits( $filename, null, $file_content );

    if ( ! empty( $upload_result['error'] ) ) {
        return new WP_Error( 'upload_error', $upload_result['error'] );
    }

    // Użyj ścieżki zwróconej przez WordPress (ma poprawną unikalną nazwę)
    $file = $upload_result['file'];
    
    // Sprawdź typ pliku
    $wp_filetype = wp_check_filetype( basename( $file ), null );
    
    // Przygotuj dane załącznika
    $attachment = array(
        'post_mime_type' => $wp_filetype['type'],
        'post_title'     => $meta['title'],
        'post_content'   => $meta['description'],
        'post_status'    => 'inherit',
        'post_excerpt'   => $meta['caption'], // Podpis obrazka
    );

    // Wstaw załącznik
    $attach_id = wp_insert_attachment( $attachment, $file, $post_id );

    if ( is_wp_error( $attach_id ) ) {
        return $attach_id;
    }

    // Generuj metadane obrazka (rozmiary)
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    $attach_data = wp_generate_attachment_metadata( $attach_id, $file );
    wp_update_attachment_metadata( $attach_id, $attach_data );

    // Konwersja do WebP jeśli włączona
    $webp_enabled = aai_get_option( 'webp_conversion', false );
    if ( $webp_enabled ) {
        $webp_path = aai_convert_to_webp( $file );
        if ( $webp_path ) {
            // Usuń oryginalny plik PNG/JPG
            @unlink( $file );

            // Zaktualizuj załącznik z nową ścieżką WebP
            $webp_filetype = wp_check_filetype( $webp_path );
            update_attached_file( $attach_id, $webp_path );
            wp_update_post( array(
                'ID'             => $attach_id,
                'post_mime_type' => 'image/webp',
            ) );

            // Regeneruj metadane z nowym plikiem
            $attach_data = wp_generate_attachment_metadata( $attach_id, $webp_path );
            wp_update_attachment_metadata( $attach_id, $attach_data );
        }
    }

    // Generowanie inteligentnego opisu ALT (jeśli włączone i brak w metadata)
    // Sprawdzamy opcję w ustawieniach
    $auto_alt_enabled = aai_get_option( 'auto_generate_alt', false );
    
    if ( $auto_alt_enabled && ( empty( $meta['alt'] ) || $meta['source'] === 'ai_generated' ) ) {
        // Dla obrazków AI używamy promptu jako bazy
        if ( $meta['source'] === 'ai_generated' ) {
            // Wyciągnij prompt z kontekstu
            $prompt_context = isset( $metadata['original_prompt'] ) ? $metadata['original_prompt'] : $meta['title'];
            
            // Pobierz język z ustawień (dla ALT tekstu używamy polskiego jako fallback dla opcji bez tekstu)
            $lang = aai_get_option( 'image_language', 'pl' );
            if ( $lang === 'none' || $lang === 'numbers_only' ) $lang = 'pl';
            
            // Generuj ALT
            if ( ! function_exists( 'aai_generate_alt_text' ) ) {
                require_once __DIR__ . '/ai-service.php';
            }
            
            $alt_text = aai_generate_alt_text( $prompt_context, '', $lang );
            
            if ( ! is_wp_error( $alt_text ) && ! empty( $alt_text ) ) {
                $meta['alt'] = $alt_text;
            }
        }
    }

    // Ustaw Alt Text
    if ( ! empty( $meta['alt'] ) ) {
        update_post_meta( $attach_id, '_wp_attachment_image_alt', $meta['alt'] );
    }

    // Zapisz metadane wtyczki (źródło)
    update_post_meta( $attach_id, '_aai_source', $meta['source'] );
    if ( ! empty( $meta['source_url'] ) ) {
        update_post_meta( $attach_id, '_aai_source_url', $meta['source_url'] );
    }
    if ( isset( $meta['brand_name'] ) ) {
        update_post_meta( $attach_id, '_aai_brand_name', $meta['brand_name'] );
    }
    if ( isset( $meta['photographer'] ) ) {
        update_post_meta( $attach_id, '_aai_photographer', $meta['photographer'] );
    }

    return $attach_id;
}

/**
 * Generuje nazwę pliku przyjazną SEO
 */
function aai_generate_seo_filename( $title, $context = '' ) {
    // Transliteracja (zamiana polskich znaków)
    $filename = remove_accents( $title );
    
    // Sanityzacja (tylko bezpieczne znaki)
    $filename = sanitize_title( $filename );
    
    // Dodaj kontekst (opcjonalnie)
    if ( ! empty( $context ) ) {
        $filename .= '-' . sanitize_title( $context );
    }
    
    // Skróć jeśli za długa
    if ( strlen( $filename ) > 100 ) {
        $filename = substr( $filename, 0, 100 );
        $filename = rtrim( $filename, '-' ); // Usuń trailing dash po obcięciu
    }

    return $filename;
}

/**
 * Generuje tytuł obrazka przyjazny SEO
 * 
 * @param WP_Post $post   Obiekt posta
 * @param string  $type   Typ obrazka (featured, content)
 * @param string  $number Numer obrazka (dla content images)
 * @return string Tytuł SEO
 */
function aai_generate_seo_title( $post, $type = 'featured', $number = '' ) {
    $post_title = $post->post_title;
    
    if ( $type === 'content' && ! empty( $number ) ) {
        // Dla obrazków w treści: "Tytuł artykułu - Ilustracja 1"
        return sprintf(
            '%s - %s %s',
            $post_title,
            __( 'Ilustracja', 'agencyjnie-ai-images' ),
            $number
        );
    }
    
    // Dla featured image: "Tytuł artykułu - Featured Image"
    return sprintf(
        '%s - %s',
        $post_title,
        __( 'Obrazek wyróżniający', 'agencyjnie-ai-images' )
    );
}

/**
 * Generuje alt text obrazka przyjazny SEO
 * 
 * @param WP_Post $post   Obiekt posta
 * @param string  $type   Typ obrazka (featured, content)
 * @param string  $number Numer obrazka (dla content images)
 * @return string Alt text SEO
 */
function aai_generate_seo_alt( $post, $type = 'featured', $number = '' ) {
    $post_title = $post->post_title;
    
    if ( $type === 'content' && ! empty( $number ) ) {
        // Dla obrazków w treści
        return sprintf(
            '%s - %s %s',
            $post_title,
            __( 'ilustracja do artykułu', 'agencyjnie-ai-images' ),
            $number
        );
    }
    
    // Dla featured image
    return sprintf(
        '%s - %s',
        $post_title,
        __( 'grafika artykułu', 'agencyjnie-ai-images' )
    );
}

/**
 * Konwertuje obrazek do formatu WebP
 *
 * @param string $file_path Ścieżka do pliku źródłowego
 * @param int    $quality   Jakość WebP (0-100)
 * @return string|false     Ścieżka do pliku WebP lub false w razie błędu
 */
function aai_convert_to_webp( $file_path, $quality = 85 ) {
    if ( ! file_exists( $file_path ) ) {
        return false;
    }

    $webp_path = preg_replace( '/\.(png|jpe?g|gif)$/i', '.webp', $file_path );

    // Jeśli ścieżka się nie zmieniła (nieobsługiwane rozszerzenie), dodaj .webp
    if ( $webp_path === $file_path ) {
        $webp_path = $file_path . '.webp';
    }

    // Próba konwersji przez GD
    if ( function_exists( 'imagewebp' ) ) {
        $mime = wp_check_filetype( $file_path )['type'];
        $image = null;

        if ( $mime === 'image/png' ) {
            $image = @imagecreatefrompng( $file_path );
            if ( $image ) {
                // Zachowaj przezroczystość
                imagepalettetotruecolor( $image );
                imagealphablending( $image, true );
                imagesavealpha( $image, true );
            }
        } elseif ( $mime === 'image/jpeg' ) {
            $image = @imagecreatefromjpeg( $file_path );
        }

        if ( $image ) {
            $result = imagewebp( $image, $webp_path, $quality );
            imagedestroy( $image );

            if ( $result && file_exists( $webp_path ) ) {
                return $webp_path;
            }
        }
    }

    // Fallback: Imagick
    if ( extension_loaded( 'imagick' ) ) {
        try {
            $imagick = new \Imagick( $file_path );
            $imagick->setImageFormat( 'webp' );
            $imagick->setImageCompressionQuality( $quality );

            if ( $imagick->writeImage( $webp_path ) ) {
                $imagick->destroy();
                return $webp_path;
            }
            $imagick->destroy();
        } catch ( \Exception $e ) {
            // Konwersja nieudana - zwróć false
        }
    }

    return false;
}

/**
 * Czyści string JSON zwrócony przez AI z bloków Markdown
 *
 * @param string $raw_response Surowa odpowiedź tekstowa z AI
 * @return string Czysty string JSON
 */
function aai_clean_json_string( $raw_response ) {
    $json_str = $raw_response;
    
    // Usuń bloki kodu Markdown (```json ... ``` lub ``` ...)
    if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $raw_response, $matches ) ) {
        $json_str = $matches[1];
    }
    
    // Usuń ewentualne białe znaki z początku/końca
    return trim( $json_str );
}
