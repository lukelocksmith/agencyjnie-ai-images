<?php
/**
 * Źródło obrazków: Media Library WordPress
 * Wyszukuje pasujące obrazki po tagach i tytułach
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Szuka obrazków w Media Library pasujących do słów kluczowych
 * 
 * @param array  $keywords Słowa kluczowe do wyszukania
 * @param int    $post_id  ID posta (opcjonalne, do wykluczenia już użytych)
 * @return array|false Dane obrazka lub false jeśli nie znaleziono
 */
function aai_search_media_library( $keywords, $post_id = 0 ) {
    if ( empty( $keywords ) || ! is_array( $keywords ) ) {
        return false;
    }
    
    // Sprawdź czy źródło jest włączone
    if ( ! aai_get_option( 'source_media_library', true ) ) {
        return false;
    }
    
    $found_images = array();
    
    foreach ( $keywords as $keyword ) {
        $keyword = sanitize_text_field( $keyword );
        
        if ( empty( $keyword ) || strlen( $keyword ) < 3 ) {
            continue;
        }
        
        // Szukaj po tytule obrazka
        $title_results = aai_search_attachments_by_title( $keyword );
        
        // Szukaj po tagach (jeśli używasz wtyczki do tagowania mediów)
        $tag_results = aai_search_attachments_by_tag( $keyword );
        
        // Szukaj po alt text
        $alt_results = aai_search_attachments_by_alt( $keyword );
        
        // Połącz wyniki
        $found_images = array_merge( $found_images, $title_results, $tag_results, $alt_results );
    }
    
    // Usuń duplikaty
    $found_images = array_unique( $found_images );
    
    if ( empty( $found_images ) ) {
        return false;
    }
    
    // Pobierz obrazki, które nie były jeszcze użyte w tym poście
    $used_images = aai_get_used_images_in_post( $post_id );
    $available_images = array_diff( $found_images, $used_images );
    
    if ( empty( $available_images ) ) {
        // Jeśli wszystkie pasujące były użyte, zwróć pierwszy znaleziony
        $available_images = $found_images;
    }
    
    // Zwróć pierwszy dostępny obrazek
    $attachment_id = reset( $available_images );
    
    return array(
        'source'        => 'media_library',
        'attachment_id' => $attachment_id,
        'url'           => wp_get_attachment_url( $attachment_id ),
        'already_saved' => true, // Obrazek już jest w Media Library
    );
}

/**
 * Szuka attachmentów po tytule
 */
function aai_search_attachments_by_title( $keyword ) {
    global $wpdb;
    
    $results = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} 
        WHERE post_type = 'attachment' 
        AND post_mime_type LIKE 'image/%'
        AND post_title LIKE %s
        LIMIT 10",
        '%' . $wpdb->esc_like( $keyword ) . '%'
    ) );
    
    return $results ? $results : array();
}

/**
 * Szuka attachmentów po tagu (wymaga wtyczki Media Library Assistant lub podobnej)
 */
function aai_search_attachments_by_tag( $keyword ) {
    // Sprawdź czy taksonomia attachment_tag istnieje
    if ( ! taxonomy_exists( 'attachment_tag' ) && ! taxonomy_exists( 'media_tag' ) ) {
        return array();
    }
    
    $taxonomy = taxonomy_exists( 'attachment_tag' ) ? 'attachment_tag' : 'media_tag';
    
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 10,
        'fields'         => 'ids',
        'tax_query'      => array(
            array(
                'taxonomy' => $taxonomy,
                'field'    => 'name',
                'terms'    => $keyword,
                'operator' => 'LIKE',
            ),
        ),
    );
    
    $query = new WP_Query( $args );
    
    return $query->posts ? $query->posts : array();
}

/**
 * Szuka attachmentów po alt text
 */
function aai_search_attachments_by_alt( $keyword ) {
    global $wpdb;
    
    $results = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} 
        WHERE meta_key = '_wp_attachment_image_alt'
        AND meta_value LIKE %s
        LIMIT 10",
        '%' . $wpdb->esc_like( $keyword ) . '%'
    ) );
    
    return $results ? $results : array();
}

/**
 * Pobiera listę obrazków już użytych w danym poście
 */
function aai_get_used_images_in_post( $post_id ) {
    if ( ! $post_id ) {
        return array();
    }
    
    $used = array();
    
    // Featured image
    $thumbnail_id = get_post_thumbnail_id( $post_id );
    if ( $thumbnail_id ) {
        $used[] = $thumbnail_id;
    }
    
    // Obrazki w treści
    $post = get_post( $post_id );
    if ( $post && ! empty( $post->post_content ) ) {
        // Znajdź wszystkie wp-image-XXX klasy
        preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches );
        if ( ! empty( $matches[1] ) ) {
            $used = array_merge( $used, array_map( 'intval', $matches[1] ) );
        }
    }
    
    return array_unique( $used );
}

/**
 * Sprawdza czy obrazek z Media Library pasuje do kontekstu
 * Używane do scoringu wyników
 */
function aai_score_media_match( $attachment_id, $keywords ) {
    $score = 0;
    
    $attachment = get_post( $attachment_id );
    if ( ! $attachment ) {
        return 0;
    }
    
    $title = strtolower( $attachment->post_title );
    $alt = strtolower( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
    $description = strtolower( $attachment->post_content );
    
    foreach ( $keywords as $keyword ) {
        $keyword = strtolower( $keyword );
        
        // Tytuł ma najwyższą wagę
        if ( strpos( $title, $keyword ) !== false ) {
            $score += 10;
        }
        
        // Alt text
        if ( strpos( $alt, $keyword ) !== false ) {
            $score += 5;
        }
        
        // Opis
        if ( strpos( $description, $keyword ) !== false ) {
            $score += 2;
        }
    }
    
    return $score;
}
