<?php
/**
 * Główna logika wyboru źródła obrazka
 * Koordynuje wyszukiwanie w różnych źródłach według priorytetów
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ładowanie plików źródeł
require_once __DIR__ . '/sources/media-library.php';
require_once __DIR__ . '/sources/screenshots.php';
require_once __DIR__ . '/sources/brandfetch.php';
require_once __DIR__ . '/sources/stock-photos.php';

/**
 * Znajduje najlepsze źródło obrazka dla danego kontekstu
 * 
 * Priorytet:
 * 1. Media Library (jeśli mamy pasujący obrazek)
 * 2. Screenshot strony (jeśli wykryto brand)
 * 3. Brandfetch (logo brandu jako fallback)
 * 4. Unsplash/Pexels (stockowe zdjęcia)
 * 5. AI Gemini (generowanie jako fallback)
 * 
 * @param int    $post_id   ID posta
 * @param string $context   Tekst kontekstu (paragraf, tytuł)
 * @param array  $keywords  Słowa kluczowe
 * @param array  $brands    Wykryte brandy (opcjonalne)
 * @return array Dane obrazka ze źródłem
 */
function aai_find_best_image_source( $post_id, $context, $keywords = array(), $brands = array() ) {
    $result = array(
        'found'    => false,
        'source'   => null,
        'data'     => null,
        'fallback' => 'ai', // Co zrobić jeśli nic nie znaleziono
    );
    
    // Jeśli nie podano keywords, wyciągnij z kontekstu
    if ( empty( $keywords ) && ! empty( $context ) ) {
        $keywords = aai_extract_keywords_from_text( $context );
    }
    
    // Jeśli nie podano brands, wykryj w kontekście
    if ( empty( $brands ) && ! empty( $context ) ) {
        $brands = aai_detect_brands_in_text( $context );
    }
    
    // 1. Sprawdź Media Library
    if ( aai_get_option( 'source_media_library', true ) && ! empty( $keywords ) ) {
        $media_result = aai_search_media_library( $keywords, $post_id );
        
        if ( $media_result ) {
            return array(
                'found'         => true,
                'source'        => 'media_library',
                'data'          => $media_result,
                'attachment_id' => $media_result['attachment_id'],
                'already_saved' => true,
            );
        }
    }
    
    // 2. Sprawdź Screenshoty stron (jeśli wykryto brandy)
    if ( aai_get_option( 'source_screenshots', false ) && ! empty( $brands ) ) {
        $screenshot_result = aai_capture_screenshot_for_brands( $brands );
        
        if ( $screenshot_result ) {
            return array(
                'found'          => true,
                'source'         => 'screenshot',
                'data'           => $screenshot_result,
                'brand_name'     => $screenshot_result['brand_name'],
                'domain'         => $screenshot_result['domain'],
                'needs_download' => true,
            );
        }
    }
    
    // 3. Sprawdź Brandfetch (jeśli wykryto brandy) - fallback do logo
    if ( aai_get_option( 'source_brandfetch', false ) && ! empty( $brands ) ) {
        foreach ( $brands as $brand_name => $domain ) {
            $brand_result = aai_fetch_brand_logo( $domain );
            
            if ( $brand_result ) {
                return array(
                    'found'      => true,
                    'source'     => 'brandfetch',
                    'data'       => $brand_result,
                    'brand_name' => $brand_name,
                    'needs_download' => true,
                );
            }
        }
    }
    
    // 4. Sprawdź Unsplash/Pexels
    $unsplash_enabled = aai_get_option( 'source_unsplash', false );
    $pexels_enabled = aai_get_option( 'source_pexels', false );
    
    if ( ( $unsplash_enabled || $pexels_enabled ) && ! empty( $keywords ) ) {
        // Przetłumacz keywords na angielski dla lepszych wyników
        $translated_keywords = aai_translate_keywords_for_stock( $keywords );
        
        $stock_result = aai_search_stock_photos( $translated_keywords );
        
        if ( $stock_result ) {
            return array(
                'found'          => true,
                'source'         => $stock_result['source'],
                'data'           => $stock_result,
                'needs_download' => true,
            );
        }
    }
    
    // 5. Fallback do AI (jeśli włączone)
    if ( aai_get_option( 'source_ai_fallback', true ) ) {
        return array(
            'found'    => false,
            'source'   => 'ai_generated',
            'fallback' => 'ai',
            'keywords' => $keywords,
            'brands'   => $brands,
        );
    }
    
    // Żadne źródło nie zadziałało
    return array(
        'found'  => false,
        'source' => null,
        'error'  => __( 'Nie znaleziono pasującego obrazka w żadnym źródle.', 'agencyjnie-ai-images' ),
    );
}

/**
 * Pobiera obrazek z wybranego źródła i zapisuje do Media Library
 * 
 * @param array  $source_result Wynik z aai_find_best_image_source()
 * @param int    $post_id       ID posta
 * @param string $context       Kontekst dla nazwy pliku
 * @return int|WP_Error ID załącznika lub błąd
 */
function aai_get_image_from_source( $source_result, $post_id, $context = '' ) {
    // Jeśli obrazek już jest w Media Library
    if ( ! empty( $source_result['already_saved'] ) && ! empty( $source_result['attachment_id'] ) ) {
        return $source_result['attachment_id'];
    }
    
    // Pobierz obrazek ze źródła zewnętrznego
    switch ( $source_result['source'] ) {
        case 'screenshot':
            return aai_download_screenshot(
                $source_result['data']['url'],
                $post_id,
                $source_result['brand_name'],
                $source_result['domain']
            );
            
        case 'brandfetch':
            return aai_download_brand_logo(
                $source_result['data']['url'],
                $post_id,
                $source_result['brand_name']
            );
            
        case 'unsplash':
        case 'pexels':
            return aai_download_stock_photo(
                $source_result['data'],
                $post_id,
                $context
            );
            
        case 'ai_generated':
            // Zwróć null - caller powinien użyć AI do generowania
            return null;
            
        default:
            return new WP_Error( 'unknown_source', __( 'Nieznane źródło obrazka.', 'agencyjnie-ai-images' ) );
    }
}

/**
 * Wyciąga słowa kluczowe z tekstu
 * Używane gdy nie podano explicite keywords
 */
function aai_extract_keywords_from_text( $text ) {
    // Usuń HTML
    $text = wp_strip_all_tags( $text );
    
    // Usuń znaki specjalne
    $text = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
    
    // Podziel na słowa
    $words = preg_split( '/\s+/', $text );
    
    // Filtruj krótkie słowa i stop words
    $stop_words = array(
        'i', 'a', 'o', 'w', 'z', 'do', 'na', 'za', 'od', 'po', 'dla', 'jak', 'lub',
        'co', 'to', 'jest', 'są', 'być', 'nie', 'tak', 'też', 'już', 'tylko',
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'and', 'or', 'but', 'if', 'then', 'else', 'when', 'at', 'by', 'for',
        'with', 'about', 'against', 'between', 'into', 'through', 'during',
        'przed', 'po', 'między', 'przez', 'podczas', 'można', 'może', 'będzie',
    );
    
    $keywords = array();
    
    foreach ( $words as $word ) {
        $word = mb_strtolower( trim( $word ) );
        
        // Min 3 znaki, max 30, nie jest stop word
        if ( mb_strlen( $word ) >= 3 && mb_strlen( $word ) <= 30 && ! in_array( $word, $stop_words, true ) ) {
            $keywords[] = $word;
        }
    }
    
    // Usuń duplikaty i ogranicz do 10
    $keywords = array_unique( $keywords );
    $keywords = array_slice( $keywords, 0, 10 );
    
    return $keywords;
}

/**
 * Sprawdza dostępność źródeł obrazków
 * Używane do wyświetlenia statusu w UI
 */
function aai_get_available_sources() {
    $sources = array();
    
    // Media Library - zawsze dostępna
    $sources['media_library'] = array(
        'name'      => __( 'Media Library', 'agencyjnie-ai-images' ),
        'enabled'   => aai_get_option( 'source_media_library', true ),
        'available' => true,
        'icon'      => 'dashicons-admin-media',
    );
    
    // Screenshots
    $urlbox_key = aai_get_secure_option( 'urlbox_api_key', '' );
    $sources['screenshot'] = array(
        'name'      => __( 'Screenshot', 'agencyjnie-ai-images' ),
        'enabled'   => aai_get_option( 'source_screenshots', false ),
        'available' => ! empty( $urlbox_key ),
        'icon'      => 'dashicons-desktop',
    );
    
    // Brandfetch
    $brandfetch_key = aai_get_secure_option( 'brandfetch_api_key', '' );
    $sources['brandfetch'] = array(
        'name'      => __( 'Brandfetch', 'agencyjnie-ai-images' ),
        'enabled'   => aai_get_option( 'source_brandfetch', false ),
        'available' => ! empty( $brandfetch_key ),
        'icon'      => 'dashicons-building',
    );
    
    // Unsplash
    $unsplash_key = aai_get_secure_option( 'unsplash_api_key', '' );
    $sources['unsplash'] = array(
        'name'      => __( 'Unsplash', 'agencyjnie-ai-images' ),
        'enabled'   => aai_get_option( 'source_unsplash', false ),
        'available' => ! empty( $unsplash_key ),
        'icon'      => 'dashicons-camera',
    );
    
    // Pexels
    $pexels_key = aai_get_secure_option( 'pexels_api_key', '' );
    $sources['pexels'] = array(
        'name'      => __( 'Pexels', 'agencyjnie-ai-images' ),
        'enabled'   => aai_get_option( 'source_pexels', false ),
        'available' => ! empty( $pexels_key ),
        'icon'      => 'dashicons-format-image',
    );
    
    // AI Gemini
    $gemini_key = aai_get_secure_option( 'api_key', '' );
    $openai_key = aai_get_secure_option( 'openai_api_key', '' );
    $has_ai_key = ! empty( $gemini_key ) || ! empty( $openai_key );
    
    $sources['ai_generated'] = array(
        'name'      => __( 'AI Gemini / DALL-E', 'agencyjnie-ai-images' ),
        'enabled'   => aai_get_option( 'source_ai_fallback', true ),
        'available' => $has_ai_key,
        'icon'      => 'dashicons-superhero',
    );
    
    return $sources;
}

/**
 * Zwraca etykietę źródła do wyświetlenia w UI
 */
function aai_get_source_label( $source ) {
    $labels = array(
        'media_library' => __( 'Media Library', 'agencyjnie-ai-images' ),
        'screenshot'    => __( 'Screenshot', 'agencyjnie-ai-images' ),
        'brandfetch'    => __( 'Brandfetch', 'agencyjnie-ai-images' ),
        'unsplash'      => __( 'Unsplash', 'agencyjnie-ai-images' ),
        'pexels'        => __( 'Pexels', 'agencyjnie-ai-images' ),
        'ai_generated'  => __( 'AI Generated', 'agencyjnie-ai-images' ),
    );
    
    return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
}
