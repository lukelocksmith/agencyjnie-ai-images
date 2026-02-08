<?php
/**
 * Meta box w edytorze postów do generowania obrazków AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sprawdza czy post ma wygenerowane obrazki w treści
 * 
 * @param int $post_id ID posta
 * @return bool
 */
function aai_post_has_generated_images( $post_id ) {
    $post = get_post( $post_id );
    
    if ( ! $post ) {
        return false;
    }
    
    // Szukaj obrazków z klasą aai-generated-image w treści
    if ( strpos( $post->post_content, 'aai-generated-image' ) !== false ) {
        return true;
    }
    
    // Lub sprawdź w media library
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'meta_query'     => array(
            array(
                'key'   => '_aai_content_image',
                'value' => '1',
            ),
            array(
                'key'   => '_aai_source_post',
                'value' => $post_id,
            ),
        ),
    );
    
    $attachments = get_posts( $args );
    
    return ! empty( $attachments );
}

/**
 * Rejestracja meta boxa
 */
function aai_register_meta_box() {
    // Typy postów, dla których pokazujemy meta box
    $post_types = apply_filters( 'aai_meta_box_post_types', array( 'post', 'page' ) );
    
    foreach ( $post_types as $post_type ) {
        add_meta_box(
            'aai_generate_image_box',
            __( 'AI Image Generator', 'agencyjnie-ai-images' ),
            'aai_render_meta_box',
            $post_type,
            'side',
            'default'
        );
    }
}
add_action( 'add_meta_boxes', 'aai_register_meta_box' );

/**
 * Renderowanie zawartości meta boxa
 * 
 * @param WP_Post $post Aktualny post
 */
function aai_render_meta_box( $post ) {
    // Sprawdź czy mamy skonfigurowane API (sprawdź dowolny klucz AI)
    $api_key = aai_get_secure_option( 'api_key' );
    $openai_key = aai_get_secure_option( 'openai_api_key' );
    $has_api_key = ! empty( $api_key ) || ! empty( $openai_key );
    
    // Sprawdź czy post ma już featured image
    $has_thumbnail = has_post_thumbnail( $post->ID );
    $thumbnail_url = $has_thumbnail ? get_the_post_thumbnail_url( $post->ID, 'medium' ) : '';
    
    // Nonce dla bezpieczeństwa
    wp_nonce_field( 'aai_meta_box', 'aai_meta_box_nonce' );
    ?>
    <div class="aai-meta-box-content">
        <?php if ( ! $has_api_key ) : ?>
            <div class="aai-notice aai-notice-warning">
                <p>
                    <?php 
                    printf(
                        /* translators: %s: link do ustawień */
                        esc_html__( 'Brak klucza API. %s', 'agencyjnie-ai-images' ),
                        '<a href="' . esc_url( admin_url( 'options-general.php?page=agencyjnie-ai-images' ) ) . '">' . 
                        esc_html__( 'Skonfiguruj wtyczkę', 'agencyjnie-ai-images' ) . 
                        '</a>'
                    );
                    ?>
                </p>
            </div>
        <?php else : ?>
            
            <!-- Podgląd aktualnego obrazka -->
            <div class="aai-current-image" id="aai-current-image">
                <?php if ( $has_thumbnail ) : ?>
                    <p class="aai-label"><?php esc_html_e( 'Aktualny Featured Image:', 'agencyjnie-ai-images' ); ?></p>
                    <img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="" class="aai-thumbnail-preview" />
                    <?php 
                    // Pokaż źródło jeśli to obrazek wygenerowany przez wtyczkę
                    $thumbnail_id = get_post_thumbnail_id( $post->ID );
                    $image_source = get_post_meta( $thumbnail_id, '_aai_source', true );
                    if ( $image_source ) :
                    ?>
                        <span class="aai-source-indicator source-<?php echo esc_attr( $image_source ); ?>">
                            <?php echo esc_html( function_exists( 'aai_get_source_label' ) ? aai_get_source_label( $image_source ) : $image_source ); ?>
                        </span>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="aai-no-image"><?php esc_html_e( 'Brak featured image', 'agencyjnie-ai-images' ); ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Wskaźnik tokenów -->
            <div id="aai-tokens-display" class="aai-tokens-display" style="display: none;"></div>
            
            <!-- Przycisk generowania Featured Image -->
            <div class="aai-generate-section">
                <button 
                    type="button" 
                    id="aai-generate-btn" 
                    class="button button-primary aai-generate-btn"
                    data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                    data-has-thumbnail="<?php echo $has_thumbnail ? '1' : '0'; ?>"
                >
                    <span class="aai-btn-text">
                        <?php echo $has_thumbnail ? esc_html__( 'Regeneruj Featured Image', 'agencyjnie-ai-images' ) : esc_html__( 'Generuj Featured Image', 'agencyjnie-ai-images' ); ?>
                    </span>
                    <span class="aai-btn-spinner spinner" style="display: none;"></span>
                </button>
                
                <p class="description aai-generate-description">
                    <?php 
                    $ai_model = aai_get_option( 'ai_model', 'gemini' );
                    $model_name = $ai_model === 'dalle3' ? 'DALL-E 3' : 'Gemini';
                    printf(
                        esc_html__( 'Używa: %s.', 'agencyjnie-ai-images' ),
                        '<strong>' . esc_html( $model_name ) . '</strong>'
                    );
                    ?>
                </p>
            </div>
            
            <!-- Komunikaty dla Featured Image -->
            <div id="aai-message" class="aai-message" style="display: none;"></div>
            
            <!-- DISABLED: Sekcja "Obrazki w treści" (content-images.php jest wyłączony) -->

            <!-- Podgląd promptu (opcjonalny, do debugowania) -->
            <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
                <div class="aai-debug-section">
                    <button type="button" class="button aai-toggle-prompt" id="aai-toggle-prompt">
                        <?php esc_html_e( 'Pokaż prompt', 'agencyjnie-ai-images' ); ?>
                    </button>
                    <div id="aai-prompt-preview" class="aai-prompt-preview" style="display: none;">
                        <p class="aai-label"><?php esc_html_e( 'Prompt który zostanie wysłany:', 'agencyjnie-ai-images' ); ?></p>
                        <pre class="aai-prompt-text"><?php echo esc_html( aai_build_prompt( $post->ID ) ); ?></pre>
                    </div>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Dodanie wsparcia dla edytora blokowego (Gutenberg)
 * Rejestruje sidebar panel
 */
function aai_register_block_editor_assets() {
    // Sprawdź czy jesteśmy w edytorze blokowym
    $screen = get_current_screen();
    
    if ( ! $screen || ! $screen->is_block_editor() ) {
        return;
    }
    
    // Dla edytora blokowego, meta box działa automatycznie
    // ale możemy dodać dodatkowe style
    wp_add_inline_style( 'aai-admin-css', '
        .block-editor .aai-meta-box-content {
            padding: 0;
        }
        .block-editor .aai-generate-btn {
            width: 100%;
            justify-content: center;
        }
    ' );
}
add_action( 'enqueue_block_editor_assets', 'aai_register_block_editor_assets' );
