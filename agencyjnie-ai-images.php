<?php
/**
 * Plugin Name: AI Images
 * Plugin URI: https://agencyjnie.pl
 * Description: Automatyczne generowanie featured images przy użyciu Google Gemini AI
 * Version: 2.0.0
 * Author: important.is
 * Author URI: https://important.is
 * License: GPL v2 or later
 * Text Domain: agencyjnie-ai-images
 */

// Zabezpieczenie przed bezpośrednim dostępem
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Stałe wtyczki
define( 'AAI_VERSION', '2.0.0' );
define( 'AAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AAI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Ładowanie plików wtyczki
 */
function aai_load_includes() {
    require_once AAI_PLUGIN_DIR . 'includes/image-utils.php'; // Najpierw utils
    require_once AAI_PLUGIN_DIR . 'includes/settings-page.php';
    require_once AAI_PLUGIN_DIR . 'includes/ai-service.php'; // Zmieniono z gemini-api.php
    require_once AAI_PLUGIN_DIR . 'includes/prompt-builder.php';
    require_once AAI_PLUGIN_DIR . 'includes/meta-box.php';
    require_once AAI_PLUGIN_DIR . 'includes/bulk-actions.php';
    require_once AAI_PLUGIN_DIR . 'includes/stats.php';
    require_once AAI_PLUGIN_DIR . 'includes/category-styles.php';
    require_once AAI_PLUGIN_DIR . 'includes/social-images.php';
    require_once AAI_PLUGIN_DIR . 'includes/upscale.php';
    require_once AAI_PLUGIN_DIR . 'includes/generation-queue.php';

    // WooCommerce Product Shots — only load when WooCommerce is active
    if ( class_exists( 'WooCommerce' ) ) {
        require_once AAI_PLUGIN_DIR . 'includes/woo-product-shots.php';
    }
}
add_action( 'plugins_loaded', 'aai_load_includes' );

/**
 * Ładowanie skryptów i stylów w panelu admina
 */
function aai_admin_enqueue_scripts( $hook ) {
    // Ładuj na stronach edycji postów, ustawień wtyczki i liście wpisów (bulk actions)
    $allowed_hooks = array( 'post.php', 'post-new.php', 'settings_page_agencyjnie-ai-images', 'edit.php' );
    
    if ( ! in_array( $hook, $allowed_hooks, true ) ) {
        return;
    }
    
    wp_enqueue_style(
        'aai-admin-css',
        AAI_PLUGIN_URL . 'admin/admin.css',
        array(),
        AAI_VERSION
    );

    wp_enqueue_media();
    
    wp_enqueue_script(
        'aai-admin-js',
        AAI_PLUGIN_URL . 'admin/admin.js',
        array( 'jquery' ),
        AAI_VERSION,
        true
    );
    
    // Przekazanie danych do JavaScript
    $localize_data = array(
        'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'aai_generate_image' ),
        'aiModel'  => aai_get_option( 'ai_model', 'gemini' ),
        'strings'  => array(
            'generating'   => __( 'Generowanie obrazka...', 'agencyjnie-ai-images' ),
            'success'      => __( 'Obrazek wygenerowany pomyślnie!', 'agencyjnie-ai-images' ),
            'error'        => __( 'Błąd podczas generowania obrazka.', 'agencyjnie-ai-images' ),
            'noApiKey'     => __( 'Brak klucza API. Skonfiguruj wtyczkę w ustawieniach.', 'agencyjnie-ai-images' ),
            'confirmReplace' => __( 'Post ma już featured image. Czy chcesz go zastąpić?', 'agencyjnie-ai-images' ),
        ),
    );
    
    // Dodaj dane dla bulk generate jeśli jesteśmy na ekranie listy wpisów
    if ( $hook === 'edit.php' ) {
        $localize_data['bulkNonce'] = wp_create_nonce( 'aai_bulk_generate' );
    }
    
    wp_localize_script( 'aai-admin-js', 'aaiData', $localize_data );
}
add_action( 'admin_enqueue_scripts', 'aai_admin_enqueue_scripts' );

/**
 * AJAX handler do generowania obrazka
 */
function aai_ajax_generate_image() {
    // Weryfikacja nonce
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Błąd bezpieczeństwa. Odśwież stronę i spróbuj ponownie.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Pobranie ID posta
    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    if ( ! $post_id ) {
        wp_send_json_error( array( 'message' => __( 'Nieprawidłowy ID posta.', 'agencyjnie-ai-images' ) ) );
    }

    // Sprawdzenie uprawnień do edycji tego konkretnego posta
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień do edycji tego posta.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Sprawdzenie klucza API
    $api_key = aai_get_secure_option( 'api_key' );
    if ( empty( $api_key ) ) {
        // Sprawdź też OpenAI jeśli wybrano model DALL-E
        $ai_model = aai_get_option( 'ai_model', 'gemini' );
        if ( $ai_model === 'dalle3' ) {
            $openai_key = aai_get_secure_option( 'openai_api_key' );
            if ( empty( $openai_key ) ) {
                wp_send_json_error( array( 'message' => __( 'Brak klucza API OpenAI.', 'agencyjnie-ai-images' ) ) );
            }
        } else {
            wp_send_json_error( array( 'message' => __( 'Brak klucza API Gemini. Skonfiguruj go w ustawieniach wtyczki.', 'agencyjnie-ai-images' ) ) );
        }
    }
    
    // Budowanie promptu
    $prompt = aai_build_prompt( $post_id );

    if ( empty( $prompt ) ) {
        wp_send_json_error( array( 'message' => __( 'Nie udało się zbudować promptu. Sprawdź czy post ma tytuł.', 'agencyjnie-ai-images' ) ) );
    }

    // Allow custom prompt override from prompt editor
    $custom_prompt = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( $_POST['custom_prompt'] ) : '';
    if ( ! empty( $custom_prompt ) ) {
        $prompt = $custom_prompt;
    }
    
    // System instruction (dla lepszej kontroli tekstu)
    $system_instruction = function_exists( 'aai_get_system_instruction' ) ? aai_get_system_instruction( $post_id ) : null;

    // Generowanie obrazka przez API
    $result = aai_generate_image( $prompt, null, $system_instruction );
    
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }
    
    // Zapisanie obrazka do Media Library (featured image)
    $attachment_id = aai_save_image_to_media_library( $result['image_data'], $post_id, 'featured' );
    
    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
    }
    
    // Zapisz aktualny featured image do historii
    $current_thumb = get_post_thumbnail_id( $post_id );
    if ( $current_thumb ) {
        $history = get_post_meta( $post_id, '_aai_image_history', true );
        if ( ! is_array( $history ) ) {
            $history = array();
        }
        array_unshift( $history, array(
            'attachment_id' => $current_thumb,
            'date'          => current_time( 'mysql' ),
        ) );
        $history = array_slice( $history, 0, 10 ); // Keep last 10
        update_post_meta( $post_id, '_aai_image_history', $history );
    }

    // Ustawienie jako featured image
    set_post_thumbnail( $post_id, $attachment_id );

    // Generate social media variants if enabled
    $social_enabled = aai_get_option( 'social_variants', false );
    if ( $social_enabled && function_exists( 'aai_generate_social_variants' ) ) {
        aai_generate_social_variants( $attachment_id, $post_id );
    }

    // Zwrócenie sukcesu z URL obrazka i informacjami o tokenach
    $image_url = wp_get_attachment_image_url( $attachment_id, 'medium' );
    
    // Przygotuj informacje o tokenach
    $tokens = isset( $result['tokens'] ) ? $result['tokens'] : array();
    
    // Zapisz źródło i prompt (do audytu i regeneracji)
    update_post_meta( $attachment_id, '_aai_source', 'ai_generated' );
    update_post_meta( $attachment_id, '_aai_original_prompt', $prompt );

    // Log generation stats
    if ( function_exists( 'aai_log_generation' ) ) {
        $ai_model = aai_get_option( 'ai_model', 'gemini' );
        aai_log_generation( $post_id, $ai_model, $tokens, 'success', 'featured' );
    }

    wp_send_json_success( array(
        'message'       => __( 'Obrazek wygenerowany i ustawiony jako featured image!', 'agencyjnie-ai-images' ),
        'attachment_id' => $attachment_id,
        'image_url'     => $image_url,
        'source'        => 'ai_generated',
        'tokens'        => $tokens,
    ) );
}
add_action( 'wp_ajax_aai_generate_image', 'aai_ajax_generate_image' );

/**
 * AJAX handler do podglądu promptu
 */
function aai_ajax_preview_prompt() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }
    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }
    $prompt = aai_build_prompt( $post_id );
    wp_send_json_success( array( 'prompt' => $prompt ) );
}
add_action( 'wp_ajax_aai_preview_prompt', 'aai_ajax_preview_prompt' );

/**
 * AJAX handler do przywracania poprzedniego obrazka (rollback)
 */
function aai_ajax_rollback_image() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $attachment_id  = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

    if ( ! $post_id || ! $attachment_id ) {
        wp_send_json_error( array( 'message' => 'Nieprawidłowe parametry.' ) );
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    // Verify attachment exists and is in history
    $history = get_post_meta( $post_id, '_aai_image_history', true );
    if ( ! is_array( $history ) ) {
        wp_send_json_error( array( 'message' => 'Brak historii.' ) );
    }

    $found = false;
    foreach ( $history as $item ) {
        if ( (int) $item['attachment_id'] === $attachment_id ) {
            $found = true;
            break;
        }
    }

    if ( ! $found ) {
        wp_send_json_error( array( 'message' => 'Obrazek nie znajduje się w historii.' ) );
    }

    // Verify attachment still exists
    if ( ! wp_get_attachment_url( $attachment_id ) ) {
        wp_send_json_error( array( 'message' => 'Obrazek został usunięty z biblioteki mediów.' ) );
    }

    // Save current featured image to history before rollback
    $current_thumb = get_post_thumbnail_id( $post_id );
    if ( $current_thumb && $current_thumb !== $attachment_id ) {
        array_unshift( $history, array(
            'attachment_id' => $current_thumb,
            'date'          => current_time( 'mysql' ),
        ) );
        $history = array_slice( $history, 0, 10 );
    }

    // Remove the rolled-back image from history
    $history = array_filter( $history, function( $item ) use ( $attachment_id ) {
        return (int) $item['attachment_id'] !== $attachment_id;
    });
    $history = array_values( $history );
    update_post_meta( $post_id, '_aai_image_history', $history );

    // Set as featured image
    set_post_thumbnail( $post_id, $attachment_id );

    $image_url = wp_get_attachment_image_url( $attachment_id, 'medium' );

    wp_send_json_success( array(
        'message'       => 'Przywrócono poprzedni obrazek!',
        'attachment_id' => $attachment_id,
        'image_url'     => $image_url,
    ) );
}
add_action( 'wp_ajax_aai_rollback_image', 'aai_ajax_rollback_image' );

/**
 * AJAX handler - generuje pojedynczy wariant obrazka (dla modalu wariantów)
 */
function aai_ajax_generate_variant() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    // Build prompt (use custom if provided)
    $custom_prompt = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( $_POST['custom_prompt'] ) : '';
    $prompt = ! empty( $custom_prompt ) ? $custom_prompt : aai_build_prompt( $post_id );

    if ( empty( $prompt ) ) {
        wp_send_json_error( array( 'message' => 'Nie udało się zbudować promptu.' ) );
    }

    $system_instruction = function_exists( 'aai_get_system_instruction' ) ? aai_get_system_instruction( $post_id ) : null;

    $result = aai_generate_image( $prompt, null, $system_instruction );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Save to media library but DON'T set as featured
    $attachment_id = aai_save_image_to_media_library( $result['image_data'], $post_id, 'featured' );

    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
    }

    update_post_meta( $attachment_id, '_aai_source', 'ai_generated' );
    update_post_meta( $attachment_id, '_aai_original_prompt', $prompt );
    update_post_meta( $attachment_id, '_aai_variant_draft', true );

    $image_url = wp_get_attachment_image_url( $attachment_id, 'medium' );
    $tokens = isset( $result['tokens'] ) ? $result['tokens'] : array();

    // Log generation stats
    if ( function_exists( 'aai_log_generation' ) ) {
        $ai_model = aai_get_option( 'ai_model', 'gemini' );
        aai_log_generation( $post_id, $ai_model, $tokens, 'success', 'variant' );
    }

    wp_send_json_success( array(
        'attachment_id' => $attachment_id,
        'image_url'     => $image_url,
        'tokens'        => $tokens,
    ) );
}
add_action( 'wp_ajax_aai_generate_variant', 'aai_ajax_generate_variant' );

/**
 * AJAX handler - ustawia wybrany wariant jako featured image
 */
function aai_ajax_set_variant() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $attachment_id  = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
    $reject_ids    = isset( $_POST['reject_ids'] ) ? array_map( 'absint', (array) $_POST['reject_ids'] ) : array();

    if ( ! $post_id || ! $attachment_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    // Save current featured image to history
    $current_thumb = get_post_thumbnail_id( $post_id );
    if ( $current_thumb ) {
        $history = get_post_meta( $post_id, '_aai_image_history', true );
        if ( ! is_array( $history ) ) {
            $history = array();
        }
        array_unshift( $history, array(
            'attachment_id' => $current_thumb,
            'date'          => current_time( 'mysql' ),
        ) );
        $history = array_slice( $history, 0, 10 );
        update_post_meta( $post_id, '_aai_image_history', $history );
    }

    // Set chosen variant as featured
    set_post_thumbnail( $post_id, $attachment_id );
    delete_post_meta( $attachment_id, '_aai_variant_draft' );

    // Delete rejected variants
    foreach ( $reject_ids as $reject_id ) {
        if ( $reject_id !== $attachment_id && get_post_meta( $reject_id, '_aai_variant_draft', true ) ) {
            wp_delete_attachment( $reject_id, true );
        }
    }

    $image_url = wp_get_attachment_image_url( $attachment_id, 'medium' );

    wp_send_json_success( array(
        'message'       => 'Wariant ustawiony jako featured image!',
        'attachment_id' => $attachment_id,
        'image_url'     => $image_url,
    ) );
}
add_action( 'wp_ajax_aai_set_variant', 'aai_ajax_set_variant' );

/**
 * AJAX handler — analiza artykułu i generowanie optymalnego promptu
 */
function aai_ajax_analyze_article() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    $result = aai_analyze_article_for_prompt( $post_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array( 'prompt' => $result ) );
}
add_action( 'wp_ajax_aai_analyze_article', 'aai_ajax_analyze_article' );

/**
 * AJAX handler — generowanie 3 koncepcji wizualnych z artykułu
 */
function aai_ajax_generate_concepts() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    $result = aai_generate_visual_concepts( $post_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array( 'concepts' => $result ) );
}
add_action( 'wp_ajax_aai_generate_concepts', 'aai_ajax_generate_concepts' );

/**
 * AJAX handler — upscale (powiększenie) obrazka
 */
function aai_ajax_upscale_image() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $attachment_id  = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

    if ( ! $post_id || ! $attachment_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    // Save current to history before replacing
    $current_thumb = get_post_thumbnail_id( $post_id );
    if ( $current_thumb ) {
        $history = get_post_meta( $post_id, '_aai_image_history', true );
        if ( ! is_array( $history ) ) {
            $history = array();
        }
        array_unshift( $history, array(
            'attachment_id' => $current_thumb,
            'date'          => current_time( 'mysql' ),
        ) );
        $history = array_slice( $history, 0, 10 );
        update_post_meta( $post_id, '_aai_image_history', $history );
    }

    $result = aai_upscale_image( $attachment_id, $post_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Set as featured image
    set_post_thumbnail( $post_id, $result['attachment_id'] );

    wp_send_json_success( array(
        'message'       => 'Obrazek powiększony pomyślnie!',
        'attachment_id' => $result['attachment_id'],
        'image_url'     => $result['image_url'],
        'tokens'        => $result['tokens'],
    ) );
}
add_action( 'wp_ajax_aai_upscale_image', 'aai_ajax_upscale_image' );

/**
 * AJAX handler — edycja obrazka z instrukcjami tekstowymi
 */
function aai_ajax_edit_image() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $attachment_id  = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
    $edit_prompt   = isset( $_POST['edit_prompt'] ) ? sanitize_textarea_field( $_POST['edit_prompt'] ) : '';

    if ( ! $post_id || ! $attachment_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    if ( empty( $edit_prompt ) ) {
        wp_send_json_error( array( 'message' => 'Podaj instrukcje edycji.' ) );
    }

    // Save current to history before replacing
    $current_thumb = get_post_thumbnail_id( $post_id );
    if ( $current_thumb ) {
        $history = get_post_meta( $post_id, '_aai_image_history', true );
        if ( ! is_array( $history ) ) {
            $history = array();
        }
        array_unshift( $history, array(
            'attachment_id' => $current_thumb,
            'date'          => current_time( 'mysql' ),
        ) );
        $history = array_slice( $history, 0, 10 );
        update_post_meta( $post_id, '_aai_image_history', $history );
    }

    $result = aai_edit_image( $attachment_id, $post_id, $edit_prompt );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Set as featured image
    set_post_thumbnail( $post_id, $result['attachment_id'] );

    wp_send_json_success( array(
        'message'       => 'Obrazek edytowany pomyślnie!',
        'attachment_id' => $result['attachment_id'],
        'image_url'     => $result['image_url'],
        'tokens'        => $result['tokens'],
    ) );
}
add_action( 'wp_ajax_aai_edit_image', 'aai_ajax_edit_image' );

/**
 * AJAX handler — standalone image generator (no post context)
 */
function aai_ajax_generate_standalone() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    if ( ! current_user_can( 'upload_files' ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    $raw_prompt   = isset( $_POST['prompt'] ) ? sanitize_textarea_field( $_POST['prompt'] ) : '';
    $style_key    = isset( $_POST['style'] ) ? sanitize_text_field( $_POST['style'] ) : '';
    $aspect_ratio = isset( $_POST['aspect_ratio'] ) ? sanitize_text_field( $_POST['aspect_ratio'] ) : '';

    if ( empty( $raw_prompt ) ) {
        wp_send_json_error( array( 'message' => 'Prompt nie może być pusty.' ) );
    }

    // Build final prompt with style + colors + negative prompt
    $parts = array();
    $parts[] = 'Create a visually striking image based on this description:';
    $parts[] = $raw_prompt;

    if ( ! empty( $style_key ) ) {
        $style_desc = aai_get_style_description_by_key( $style_key );
        if ( $style_desc ) {
            $parts[] = sprintf( 'Art style: %s', $style_desc );
        }
    }

    $base_prompt = aai_get_option( 'base_prompt', '' );
    if ( ! empty( $base_prompt ) ) {
        $parts[] = sprintf( 'Style guidelines: %s', $base_prompt );
    }

    $colors = aai_get_colors_description();
    if ( ! empty( $colors ) ) {
        $parts[] = sprintf( 'Color palette: %s', $colors );
    }

    $negative_prompt = aai_get_option( 'negative_prompt', '' );
    if ( ! empty( $negative_prompt ) ) {
        $parts[] = sprintf( 'IMPORTANT - DO NOT INCLUDE: %s', $negative_prompt );
    }

    $image_language = aai_get_option( 'image_language', 'pl' );
    $parts[] = aai_get_language_instruction( $image_language );
    $parts[] = 'The image should be professional and high-quality.';

    $final_prompt = implode( "\n\n", $parts );

    // Validate aspect ratio
    $allowed_ratios = array( '16:9', '4:3', '1:1', '3:4', '9:16' );
    $effective_ratio = ( ! empty( $aspect_ratio ) && in_array( $aspect_ratio, $allowed_ratios, true ) ) ? $aspect_ratio : null;

    $result = aai_generate_image( $final_prompt, $effective_ratio );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Save to media library unattached ($post_id = 0)
    $title = wp_trim_words( $raw_prompt, 10, '' );
    $attachment_id = aai_save_remote_image(
        $result['image_data'],
        0,
        array(
            'title'   => $title,
            'alt'     => $title,
            'source'  => 'ai_generated',
            'context' => 'standalone',
        )
    );

    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
    }

    update_post_meta( $attachment_id, '_aai_standalone', true );
    update_post_meta( $attachment_id, '_aai_original_prompt', $raw_prompt );

    // Log stats
    if ( function_exists( 'aai_log_generation' ) ) {
        $ai_model = aai_get_option( 'ai_model', 'gemini' );
        $tokens = isset( $result['tokens'] ) ? $result['tokens'] : array();
        aai_log_generation( 0, $ai_model, $tokens, 'success', 'standalone' );
    }

    // Save to user-specific history transient (last 5, 24h)
    $user_id = get_current_user_id();
    $history = get_transient( 'aai_standalone_history_' . $user_id );
    if ( ! is_array( $history ) ) {
        $history = array();
    }
    array_unshift( $history, array(
        'attachment_id' => $attachment_id,
        'date'          => current_time( 'mysql' ),
    ) );
    $history = array_slice( $history, 0, 5 );
    set_transient( 'aai_standalone_history_' . $user_id, $history, DAY_IN_SECONDS );

    $image_url = wp_get_attachment_url( $attachment_id );
    $edit_url  = admin_url( 'upload.php?item=' . $attachment_id );

    wp_send_json_success( array(
        'message'       => 'Obrazek wygenerowany!',
        'attachment_id' => $attachment_id,
        'image_url'     => $image_url,
        'edit_url'      => $edit_url,
    ) );
}
add_action( 'wp_ajax_aai_generate_standalone', 'aai_ajax_generate_standalone' );

/**
 * Auto-generowanie obrazka przy publikacji posta
 * Zmodyfikowano, aby używać kolejki WP Cron dla wydajności
 */
function aai_auto_generate_on_publish( $new_status, $old_status, $post ) {
    // Sprawdź czy auto-generowanie jest włączone
    $auto_generate = aai_get_option( 'auto_generate' );
    if ( ! $auto_generate ) {
        return;
    }
    
    // Sprawdź czy to przejście na status 'publish'
    if ( $new_status !== 'publish' || $old_status === 'publish' ) {
        return;
    }
    
    // Sprawdź czy to obsługiwany typ posta (domyślnie tylko 'post')
    $allowed_post_types = apply_filters( 'aai_allowed_post_types', array( 'post' ) );
    if ( ! in_array( $post->post_type, $allowed_post_types, true ) ) {
        return;
    }
    
    // Sprawdź czy post już ma featured image
    if ( has_post_thumbnail( $post->ID ) ) {
        return;
    }
    
    // Zaplanuj zdarzenie asynchroniczne (WP Cron)
    // Używamy time() żeby uruchomić "jak najszybciej" ale w osobnym procesie
    wp_schedule_single_event( time(), 'aai_async_auto_generate', array( $post->ID ) );
}
add_action( 'transition_post_status', 'aai_auto_generate_on_publish', 10, 3 );

/**
 * Zwraca obsługiwane typy postów z ustawień
 */
function aai_get_supported_post_types() {
    $post_types = aai_get_option( 'post_types', array( 'post' ) );
    if ( ! is_array( $post_types ) || empty( $post_types ) ) {
        return array( 'post' );
    }
    return $post_types;
}
add_filter( 'aai_allowed_post_types', 'aai_get_supported_post_types' );
add_filter( 'aai_meta_box_post_types', 'aai_get_supported_post_types' );

/**
 * Handler dla asynchronicznego generowania obrazka (WP Cron)
 */
function aai_process_async_generation( $post_id ) {
    // Sprawdź ponownie czy post istnieje i czy nie ma już obrazka
    if ( ! get_post( $post_id ) || has_post_thumbnail( $post_id ) ) {
        return;
    }

    // Sprawdź czy mamy klucz API
    $api_key = aai_get_secure_option( 'api_key' );
    $openai_key = aai_get_secure_option( 'openai_api_key' );
    
    if ( empty( $api_key ) && empty( $openai_key ) ) {
        error_log( 'AAI Async Error: No API keys configured.' );
        return;
    }
    
    // Generuj obrazek
    $prompt = aai_build_prompt( $post_id );
    
    if ( empty( $prompt ) ) {
        error_log( 'AAI Async Error: Could not build prompt for post ' . $post_id );
        return;
    }
    
    $result = aai_generate_image( $prompt );
    
    if ( is_wp_error( $result ) ) {
        error_log( 'AAI Async Error: ' . $result->get_error_message() );
        return;
    }
    
    $attachment_id = aai_save_image_to_media_library( $result['image_data'], $post_id, 'featured' );
    
    if ( ! is_wp_error( $attachment_id ) ) {
        set_post_thumbnail( $post_id, $attachment_id );
        // Zapisz log sukcesu w meta
        update_post_meta( $post_id, '_aai_auto_generated_success', current_time( 'mysql' ) );
        // Log generation stats
        if ( function_exists( 'aai_log_generation' ) ) {
            $ai_model = aai_get_option( 'ai_model', 'gemini' );
            $async_tokens = isset( $result['tokens'] ) ? $result['tokens'] : array();
            aai_log_generation( $post_id, $ai_model, $async_tokens, 'success', 'auto' );
        }
    } else {
        error_log( 'AAI Async Error: Could not save image. ' . $attachment_id->get_error_message() );
        // Log error
        if ( function_exists( 'aai_log_generation' ) ) {
            $ai_model = aai_get_option( 'ai_model', 'gemini' );
            aai_log_generation( $post_id, $ai_model, array(), 'error', 'auto' );
        }
    }
}
add_action( 'aai_async_auto_generate', 'aai_process_async_generation' );

/**
 * Rejestracja bloku Gutenberg AI Image
 */
function aai_register_gutenberg_block() {
    // Sprawdź czy funkcja istnieje (WP 5.8+)
    if ( ! function_exists( 'register_block_type' ) ) {
        return;
    }
    
    $block_path = AAI_PLUGIN_DIR . 'blocks/ai-image-block/';
    
    // Ręczna rejestracja skryptu edytora (wymagane dla niebudowanych bloków)
    wp_register_script(
        'aai-ai-image-block-editor',
        AAI_PLUGIN_URL . 'blocks/ai-image-block/index.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor', 'jquery' ),
        AAI_VERSION,
        true
    );
    
    // Przekaż dane do skryptu bloku
    wp_localize_script(
        'aai-ai-image-block-editor',
        'aaiBlockData',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'aai_block_generate' ),
        )
    );
    
    // Ręczna rejestracja stylu edytora
    wp_register_style(
        'aai-ai-image-block-editor-style',
        AAI_PLUGIN_URL . 'blocks/ai-image-block/editor.css',
        array(),
        AAI_VERSION
    );
    
    // Rejestracja bloku z ręcznie zarejestrowanymi assetami
    register_block_type( $block_path, array(
        'editor_script' => 'aai-ai-image-block-editor',
        'editor_style'  => 'aai-ai-image-block-editor-style',
    ) );
}
add_action( 'init', 'aai_register_gutenberg_block' );

/**
 * AJAX handler do generowania obrazka z bloku Gutenberg
 */
function aai_ajax_generate_block_image() {
    // Weryfikacja nonce
    if ( ! check_ajax_referer( 'aai_block_generate', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Błąd bezpieczeństwa.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Pobranie danych z requestu
    $post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
    $custom_prompt = isset( $_POST['custom_prompt'] ) ? sanitize_textarea_field( $_POST['custom_prompt'] ) : '';
    $override_style = isset( $_POST['override_style'] ) && $_POST['override_style'] === '1';
    $art_style     = isset( $_POST['art_style'] ) ? sanitize_text_field( $_POST['art_style'] ) : '';
    $aspect_ratio  = isset( $_POST['aspect_ratio'] ) ? sanitize_text_field( $_POST['aspect_ratio'] ) : '';

    // Sprawdzenie uprawnień do edycji posta
    if ( $post_id > 0 ) {
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Brak uprawnień do edycji tego posta.', 'agencyjnie-ai-images' ) ) );
        }
    } elseif ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'agencyjnie-ai-images' ) ) );
    }

    // Walidacja art_style przeciwko dozwolonym wartościom
    if ( ! empty( $art_style ) ) {
        $allowed_art_styles = array_keys( aai_get_all_style_descriptions() );
        if ( ! in_array( $art_style, $allowed_art_styles, true ) ) {
            $art_style = '';
        }
    }

    // Walidacja aspect_ratio przeciwko dozwolonym wartościom
    $allowed_ratios = array( '16:9', '4:3', '1:1', '3:4', '9:16' );
    if ( ! empty( $aspect_ratio ) && ! in_array( $aspect_ratio, $allowed_ratios, true ) ) {
        $aspect_ratio = '';
    }
    
    if ( empty( $custom_prompt ) ) {
        wp_send_json_error( array( 'message' => __( 'Prompt nie może być pusty.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Sprawdzenie klucza API
    $api_key = aai_get_secure_option( 'api_key' );
    $openai_key = aai_get_secure_option( 'openai_api_key' );
    
    if ( empty( $api_key ) && empty( $openai_key ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak skonfigurowanego klucza API.', 'agencyjnie-ai-images' ) ) );
    }
    
    // Zbuduj prompt z użyciem ustawień globalnych lub nadpisanych
    $prompt_parts = array();
    $prompt_parts[] = "Create a visually striking image based on this description:";
    $prompt_parts[] = $custom_prompt;
    
    // Styl artystyczny
    if ( $override_style && ! empty( $art_style ) ) {
        $style_desc = aai_get_style_description_by_key( $art_style );
        if ( $style_desc ) {
            $prompt_parts[] = sprintf( "Art style: %s", $style_desc );
        }
    } else {
        $style_desc = aai_get_style_description();
        if ( $style_desc ) {
            $prompt_parts[] = sprintf( "Art style: %s", $style_desc );
        }
    }
    
    // Prompt bazowy (globalne wytyczne)
    $base_prompt = aai_get_option( 'base_prompt', '' );
    if ( ! empty( $base_prompt ) ) {
        $prompt_parts[] = sprintf( "Style guidelines: %s", $base_prompt );
    }
    
    // Kolory (zawsze globalne)
    $colors = aai_get_colors_description();
    if ( ! empty( $colors ) ) {
        $prompt_parts[] = sprintf( "Color palette: %s", $colors );
    }
    
    // Negative prompt
    $negative_prompt = aai_get_option( 'negative_prompt', '' );
    if ( ! empty( $negative_prompt ) ) {
        $prompt_parts[] = sprintf( "IMPORTANT - DO NOT INCLUDE: %s", $negative_prompt );
    }
    
    // Język
    $image_language = aai_get_option( 'image_language', 'pl' );
    $prompt_parts[] = aai_get_language_instruction( $image_language );
    
    $prompt_parts[] = "The image should be professional and high-quality.";
    
    $final_prompt = implode( "\n\n", $prompt_parts );
    
    // Przekaż aspect_ratio bezpośrednio jako parametr (bez zapisu do DB — unika race condition)
    $effective_ratio = ( $override_style && ! empty( $aspect_ratio ) ) ? $aspect_ratio : null;

    // Generowanie obrazka
    $result = aai_generate_image( $final_prompt, $effective_ratio );
    
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }
    
    // Zapisanie obrazka
    $attachment_id = aai_save_image_to_media_library( $result['image_data'], $post_id, 'content', 'block' );
    
    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
    }
    
    // Zapisz źródło i oryginalny prompt (do audytu i regeneracji)
    update_post_meta( $attachment_id, '_aai_source', 'ai_generated' );
    update_post_meta( $attachment_id, '_aai_block_image', true );
    update_post_meta( $attachment_id, '_aai_original_prompt', $custom_prompt );

    $image_url = wp_get_attachment_image_url( $attachment_id, 'large' );
    $alt_text  = wp_strip_all_tags( substr( $custom_prompt, 0, 125 ) );

    // Ustaw alt text
    update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

    // Log generation stats
    if ( function_exists( 'aai_log_generation' ) ) {
        $ai_model = aai_get_option( 'ai_model', 'gemini' );
        $block_tokens = isset( $result['tokens'] ) ? $result['tokens'] : array();
        aai_log_generation( $post_id, $ai_model, $block_tokens, 'success', 'block' );
    }

    wp_send_json_success( array(
        'message'       => __( 'Obrazek wygenerowany!', 'agencyjnie-ai-images' ),
        'attachment_id' => $attachment_id,
        'image_url'     => $image_url,
        'alt'           => $alt_text,
    ) );
}
add_action( 'wp_ajax_aai_generate_block_image', 'aai_ajax_generate_block_image' );

/**
 * Helper: Pobierz opis stylu po kluczu
 */
function aai_get_style_description_by_key( $style_key ) {
    $style_descriptions = aai_get_all_style_descriptions();
    return isset( $style_descriptions[ $style_key ] ) ? $style_descriptions[ $style_key ] : '';
}

/**
 * Dodanie linku do ustawień na liście wtyczek
 */
function aai_plugin_action_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'options-general.php?page=agencyjnie-ai-images' ) . '">' . __( 'Ustawienia', 'agencyjnie-ai-images' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . AAI_PLUGIN_BASENAME, 'aai_plugin_action_links' );

/**
 * Aktywacja wtyczki - ustawienie domyślnych opcji
 */
function aai_activate() {
    $default_options = array(
        'api_key'                => '',
        'openai_api_key'         => '',
        'ai_model'               => 'gemini',
        'dalle_quality'          => 'standard',
        'base_prompt'            => 'Professional, modern, high-quality image suitable for a blog article.',
        'style'                  => 'photorealistic',
        'custom_style'           => '',
        'colors'                 => array( '#2563eb', '#f59e0b', '#10b981' ),
        'aspect_ratio'           => '16:9',
        'image_language'         => 'pl',
        'auto_generate'          => false,
        'post_types'             => array( 'post' ),
        'max_content_images'     => 5,
        // Źródła obrazków
        'source_media_library'   => true,
        'source_screenshots'     => false,
        'source_unsplash'        => false,
        'source_pexels'          => false,
        'source_brandfetch'      => false,
        'source_ai_fallback'     => true,
        'urlbox_api_key'         => '',
        'unsplash_api_key'       => '',
        'pexels_api_key'         => '',
        'brandfetch_api_key'     => '',
        'preferred_stock_source' => 'unsplash',
    );
    
    // Zapisz tylko jeśli opcje nie istnieją
    if ( ! get_option( 'aai_options' ) ) {
        add_option( 'aai_options', $default_options );
    }
}
register_activation_hook( __FILE__, 'aai_activate' );

/**
 * Pomocnicza funkcja do pobierania opcji wtyczki
 */
function aai_get_option( $key, $default = '' ) {
    $options = get_option( 'aai_options', array() );
    return isset( $options[ $key ] ) ? $options[ $key ] : $default;
}

/**
 * Pomocnicza funkcja do zapisywania opcji wtyczki
 */
function aai_update_option( $key, $value ) {
    $options = get_option( 'aai_options', array() );
    $options[ $key ] = $value;
    update_option( 'aai_options', $options );
}

