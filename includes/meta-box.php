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

            <!-- Przycisk wariantów -->
            <div class="aai-variants-section">
                <button
                    type="button"
                    id="aai-variants-btn"
                    class="button aai-variants-btn"
                    data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                >
                    <span class="aai-btn-text"><?php esc_html_e( 'Generuj 3 warianty', 'agencyjnie-ai-images' ); ?></span>
                    <span class="aai-btn-spinner spinner" style="display: none;"></span>
                </button>
            </div>

            <!-- Upscale i edycja -->
            <?php if ( $has_thumbnail ) : ?>
            <div class="aai-edit-section">
                <div class="aai-edit-buttons">
                    <button
                        type="button"
                        id="aai-upscale-btn"
                        class="button button-small"
                        data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                        data-attachment-id="<?php echo esc_attr( get_post_thumbnail_id( $post->ID ) ); ?>"
                    >
                        <?php esc_html_e( 'Powiększ', 'agencyjnie-ai-images' ); ?>
                    </button>
                    <button
                        type="button"
                        id="aai-edit-btn"
                        class="button button-small"
                    >
                        <?php esc_html_e( 'Edytuj AI', 'agencyjnie-ai-images' ); ?>
                    </button>
                </div>
                <div class="aai-edit-prompt-wrap" id="aai-edit-prompt-wrap" style="display:none;">
                    <textarea id="aai-edit-prompt" class="aai-edit-prompt" rows="3" placeholder="<?php esc_attr_e( 'Opisz zmiany, np. "Zmień niebo na zachód słońca"', 'agencyjnie-ai-images' ); ?>"></textarea>
                    <button
                        type="button"
                        id="aai-edit-submit"
                        class="button button-primary button-small"
                        data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                        data-attachment-id="<?php echo esc_attr( get_post_thumbnail_id( $post->ID ) ); ?>"
                    >
                        <?php esc_html_e( 'Zastosuj edycję', 'agencyjnie-ai-images' ); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Komunikaty dla Featured Image -->
            <div id="aai-message" class="aai-message" style="display: none;"></div>
            
            <!-- Historia wygenerowanych obrazków -->
            <?php
            $image_history = get_post_meta( $post->ID, '_aai_image_history', true );
            if ( is_array( $image_history ) && ! empty( $image_history ) ) :
                // Filter out deleted attachments
                $valid_history = array_filter( $image_history, function( $item ) {
                    return wp_get_attachment_url( $item['attachment_id'] );
                });
                if ( ! empty( $valid_history ) ) :
            ?>
                <div class="aai-history-section">
                    <span class="aai-label"><?php esc_html_e( 'Historia:', 'agencyjnie-ai-images' ); ?></span>
                    <div class="aai-history-list" id="aai-history-list">
                        <?php foreach ( array_slice( $valid_history, 0, 5 ) as $hist_item ) :
                            $thumb_url = wp_get_attachment_image_url( $hist_item['attachment_id'], 'thumbnail' );
                            if ( ! $thumb_url ) continue;
                        ?>
                            <div class="aai-history-item"
                                 data-attachment-id="<?php echo esc_attr( $hist_item['attachment_id'] ); ?>"
                                 title="<?php echo esc_attr( $hist_item['date'] ); ?>">
                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" />
                                <span class="aai-history-rollback"><?php esc_html_e( 'Przywróć', 'agencyjnie-ai-images' ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php
                endif;
            endif;
            ?>

            <!-- DISABLED: Sekcja "Obrazki w treści" (content-images.php jest wyłączony) -->

            <!-- Social media variants -->
            <?php
            $social_variants_enabled = aai_get_option( 'social_variants', false );
            if ( $social_variants_enabled && function_exists( 'aai_get_social_variants' ) ) :
                $social_variants = aai_get_social_variants( $post->ID );
                if ( ! empty( $social_variants ) ) :
            ?>
                <div class="aai-social-section">
                    <span class="aai-label"><?php esc_html_e( 'Social media:', 'agencyjnie-ai-images' ); ?></span>
                    <div class="aai-social-list">
                        <?php foreach ( $social_variants as $key => $variant ) : ?>
                            <div class="aai-social-item" title="<?php echo esc_attr( $variant['label'] . ' (' . $variant['width'] . 'x' . $variant['height'] . ')' ); ?>">
                                <img src="<?php echo esc_url( $variant['url'] ); ?>" alt="<?php echo esc_attr( $variant['label'] ); ?>" />
                                <span class="aai-social-label"><?php echo esc_html( $variant['label'] ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php
                endif;
            endif;
            ?>

            <!-- Podgląd i edytor promptu -->
            <div class="aai-prompt-section">
                <div class="aai-prompt-header">
                    <span class="aai-label"><?php esc_html_e( 'Prompt:', 'agencyjnie-ai-images' ); ?></span>
                    <div class="aai-prompt-buttons">
                        <button type="button" class="button button-small" id="aai-analyze-article">
                            <?php esc_html_e( 'Analizuj artykuł', 'agencyjnie-ai-images' ); ?>
                        </button>
                        <button type="button" class="button button-small aai-refresh-prompt" id="aai-refresh-prompt">
                            <?php esc_html_e( 'Odśwież', 'agencyjnie-ai-images' ); ?>
                        </button>
                    </div>
                </div>
                <textarea id="aai-prompt-editor" class="aai-prompt-editor" rows="6"><?php echo esc_textarea( aai_build_prompt( $post->ID ) ); ?></textarea>
                <p class="description aai-prompt-hint">
                    <?php esc_html_e( 'Możesz edytować prompt przed generowaniem. Kliknij "Odśwież" aby przywrócić automatyczny.', 'agencyjnie-ai-images' ); ?>
                </p>
            </div>
            
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
