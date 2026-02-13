<?php
/**
 * Bulk Actions - Masowe generowanie AI Featured Image z listy wpisów
 *
 * @package AI_Images
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dodanie custom bulk action do dropdowna na liście wpisów
 */
function aai_register_bulk_actions( $bulk_actions ) {
    $bulk_actions['aai_generate_featured'] = '🎨 Generuj AI Featured Image';
    return $bulk_actions;
}

/**
 * Rejestracja bulk actions dla dozwolonych post types
 */
function aai_init_bulk_actions() {
    $allowed_post_types = apply_filters( 'aai_allowed_post_types', array( 'post' ) );
    
    foreach ( $allowed_post_types as $post_type ) {
        add_filter( "bulk_actions-edit-{$post_type}", 'aai_register_bulk_actions' );
        add_filter( "handle_bulk_actions-edit-{$post_type}", 'aai_handle_bulk_action', 10, 3 );
    }
}
add_action( 'admin_init', 'aai_init_bulk_actions' );

/**
 * Handler bulk action — redirect z parametrami
 */
function aai_handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
    if ( 'aai_generate_featured' !== $doaction ) {
        return $redirect_to;
    }
    
    // Filtruj tylko posty do których user ma uprawnienia
    $valid_ids = array();
    foreach ( $post_ids as $post_id ) {
        if ( current_user_can( 'edit_post', $post_id ) ) {
            $valid_ids[] = absint( $post_id );
        }
    }
    
    if ( empty( $valid_ids ) ) {
        return $redirect_to;
    }
    
    // Redirect z parametrami do JS
    $redirect_to = add_query_arg(
        array(
            'aai_bulk_generate' => '1',
            'aai_post_ids'     => implode( ',', $valid_ids ),
            'aai_nonce'        => wp_create_nonce( 'aai_bulk_generate' ),
        ),
        $redirect_to
    );
    
    return $redirect_to;
}

/**
 * AJAX handler — generuje obraz dla jednego posta
 */
function aai_ajax_bulk_generate() {
    // Weryfikacja nonce
    if ( ! check_ajax_referer( 'aai_bulk_generate', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }
    
    $post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $overwrite = isset( $_POST['overwrite'] ) && $_POST['overwrite'] === '1';
    
    if ( ! $post_id || ! get_post( $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Nieprawidłowy post.' ) );
    }
    
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }
    
    // Sprawdź czy post ma już featured image
    if ( has_post_thumbnail( $post_id ) && ! $overwrite ) {
        wp_send_json_success( array(
            'message' => 'Pominięto — post ma już featured image.',
            'skipped' => true,
            'post_id' => $post_id,
        ) );
    }
    
    // Sprawdź API key
    $api_key    = aai_get_secure_option( 'api_key' );
    $openai_key = aai_get_secure_option( 'openai_api_key' );
    
    if ( empty( $api_key ) && empty( $openai_key ) ) {
        wp_send_json_error( array( 'message' => 'Brak skonfigurowanego klucza API.' ) );
    }
    
    // Budowanie promptu
    $prompt = aai_build_prompt( $post_id );
    
    if ( empty( $prompt ) ) {
        wp_send_json_error( array( 'message' => 'Nie udało się zbudować promptu. Sprawdź czy post ma tytuł.' ) );
    }
    
    // System instruction
    $system_instruction = function_exists( 'aai_get_system_instruction' ) ? aai_get_system_instruction( $post_id ) : null;

    // Generowanie obrazka
    $result = aai_generate_image( $prompt, null, $system_instruction );
    
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }
    
    // Zapisanie do Media Library
    $attachment_id = aai_save_image_to_media_library( $result['image_data'], $post_id, 'featured' );
    
    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
    }
    
    // Ustaw jako featured image
    set_post_thumbnail( $post_id, $attachment_id );
    
    // Zapisz metadane
    update_post_meta( $attachment_id, '_aai_source', 'ai_generated' );
    update_post_meta( $attachment_id, '_aai_original_prompt', $prompt );
    update_post_meta( $post_id, '_aai_bulk_generated', current_time( 'mysql' ) );
    
    $image_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
    
    wp_send_json_success( array(
        'message'       => 'Obrazek wygenerowany!',
        'post_id'       => $post_id,
        'attachment_id' => $attachment_id,
        'image_url'     => $image_url,
        'skipped'       => false,
    ) );
}
add_action( 'wp_ajax_aai_bulk_generate', 'aai_ajax_bulk_generate' );
