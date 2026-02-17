<?php
/**
 * Generation Queue — find posts without images and generate in batch
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the queue tab content
 */
function aai_render_queue_tab_content() {
    $api_key    = aai_get_secure_option( 'api_key' );
    $openai_key = aai_get_secure_option( 'openai_api_key' );
    $has_key    = ! empty( $api_key ) || ! empty( $openai_key );

    if ( ! $has_key ) {
        echo '<div class="notice notice-warning"><p>';
        printf(
            esc_html__( 'Brak klucza API. %s', 'agencyjnie-ai-images' ),
            '<a href="' . esc_url( admin_url( 'options-general.php?page=agencyjnie-ai-images&tab=settings' ) ) . '">' .
            esc_html__( 'Skonfiguruj wtyczkę', 'agencyjnie-ai-images' ) . '</a>'
        );
        echo '</p></div>';
        return;
    }

    $post_types = aai_get_option( 'post_types', array( 'post' ) );
    if ( ! is_array( $post_types ) || empty( $post_types ) ) {
        $post_types = array( 'post' );
    }
    ?>
    <div class="aai-queue-wrap">
        <div class="aai-queue-header">
            <p><?php esc_html_e( 'Znajdź posty bez featured image i wygeneruj obrazki w jednym przebiegu.', 'agencyjnie-ai-images' ); ?></p>
        </div>

        <div class="aai-queue-controls">
            <button type="button" id="aai-queue-scan" class="button button-secondary">
                <?php esc_html_e( 'Znajdź posty bez obrazka', 'agencyjnie-ai-images' ); ?>
            </button>
            <label style="margin-left: 12px;">
                <input type="checkbox" id="aai-queue-overwrite" />
                <?php esc_html_e( 'Nadpisz istniejące', 'agencyjnie-ai-images' ); ?>
            </label>
        </div>

        <div id="aai-queue-progress" class="aai-queue-progress" style="display: none;">
            <div class="aai-bulk-progress-bar">
                <div class="aai-bulk-progress-fill" id="aai-queue-fill"></div>
            </div>
            <div class="aai-bulk-progress-text" id="aai-queue-status"></div>
        </div>

        <div id="aai-queue-list" class="aai-queue-list"></div>

        <div id="aai-queue-actions" class="aai-queue-actions" style="display: none;">
            <button type="button" id="aai-queue-start" class="button button-primary">
                <?php esc_html_e( 'Generuj wszystkie', 'agencyjnie-ai-images' ); ?>
            </button>
            <button type="button" id="aai-queue-stop" class="button" style="display: none;">
                <?php esc_html_e( 'Zatrzymaj', 'agencyjnie-ai-images' ); ?>
            </button>
        </div>

        <div id="aai-queue-summary" class="aai-bulk-summary" style="display: none;"></div>
    </div>
    <?php
}

/**
 * AJAX — find posts without featured image
 */
function aai_ajax_find_posts_without_images() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    $include_with_thumbnail = ! empty( $_POST['overwrite'] ) && $_POST['overwrite'] === '1';

    $post_types = aai_get_option( 'post_types', array( 'post' ) );
    if ( ! is_array( $post_types ) || empty( $post_types ) ) {
        $post_types = array( 'post' );
    }

    $args = array(
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'fields'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    if ( ! $include_with_thumbnail ) {
        $args['meta_query'] = array(
            'relation' => 'OR',
            array(
                'key'     => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'   => '_thumbnail_id',
                'value' => '',
            ),
        );
    }

    $query = new WP_Query( $args );
    $posts = array();

    foreach ( $query->posts as $pid ) {
        $post = get_post( $pid );
        $has_thumb = has_post_thumbnail( $pid );
        $thumb_url = $has_thumb ? get_the_post_thumbnail_url( $pid, 'thumbnail' ) : '';

        $posts[] = array(
            'id'        => $pid,
            'title'     => $post->post_title,
            'has_thumb' => $has_thumb,
            'thumb_url' => $thumb_url,
        );
    }

    wp_send_json_success( array(
        'posts' => $posts,
        'total' => count( $posts ),
    ) );
}
add_action( 'wp_ajax_aai_find_posts_without_images', 'aai_ajax_find_posts_without_images' );
