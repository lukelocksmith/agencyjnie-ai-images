<?php
/**
 * Plugin Name: AI Images
 * Plugin URI: https://agencyjnie.pl
 * Description: Automatyczne generowanie featured images przy użyciu Google Gemini AI
 * Version: 1.3.1
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
define( 'AAI_VERSION', '1.3.1' );
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
    require_once AAI_PLUGIN_DIR . 'includes/github-updater.php';
    // DISABLED: Dodatkowe źródła obrazów (tymczasowo wyłączone — focus na core AI generation)
    // require_once AAI_PLUGIN_DIR . 'includes/content-images.php';
    // require_once AAI_PLUGIN_DIR . 'includes/image-sources.php';
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
    
    // Ustawienie jako featured image
    set_post_thumbnail( $post_id, $attachment_id );
    
    // Zwrócenie sukcesu z URL obrazka i informacjami o tokenach
    $image_url = wp_get_attachment_image_url( $attachment_id, 'medium' );
    
    // Przygotuj informacje o tokenach
    $tokens = isset( $result['tokens'] ) ? $result['tokens'] : array();
    
    // Zapisz źródło i prompt (do audytu i regeneracji)
    update_post_meta( $attachment_id, '_aai_source', 'ai_generated' );
    update_post_meta( $attachment_id, '_aai_original_prompt', $prompt );

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
    } else {
        error_log( 'AAI Async Error: Could not save image. ' . $attachment_id->get_error_message() );
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

