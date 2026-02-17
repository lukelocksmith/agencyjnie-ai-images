<?php
/**
 * WooCommerce Product Shots — AI-generated lifestyle product photography
 * Only loaded when WooCommerce is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register meta box on WooCommerce product editor
 */
function aai_register_product_shots_meta_box() {
    add_meta_box(
        'aai_product_shots_box',
        __( 'AI Product Shots', 'agencyjnie-ai-images' ),
        'aai_render_product_shots_box',
        'product',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'aai_register_product_shots_meta_box' );

/**
 * Render the product shots meta box
 *
 * @param WP_Post $post Current product post
 */
function aai_render_product_shots_box( $post ) {
    $api_key = aai_get_secure_option( 'api_key' );
    if ( empty( $api_key ) ) {
        printf(
            '<p>%s <a href="%s">%s</a></p>',
            esc_html__( 'Brak klucza API Gemini.', 'agencyjnie-ai-images' ),
            esc_url( admin_url( 'options-general.php?page=agencyjnie-ai-images' ) ),
            esc_html__( 'Skonfiguruj', 'agencyjnie-ai-images' )
        );
        return;
    }

    wp_nonce_field( 'aai_product_shots', 'aai_product_shots_nonce' );

    $scene_presets = array(
        'hand'      => __( 'Produkt w ręku osoby', 'agencyjnie-ai-images' ),
        'white_bg'  => __( 'Produkt na białym tle', 'agencyjnie-ai-images' ),
        'lifestyle' => __( 'Lifestyle — produkt w użyciu', 'agencyjnie-ai-images' ),
        'flat_lay'  => __( 'Flat lay z akcesoriami', 'agencyjnie-ai-images' ),
    );
    ?>
    <div class="aai-product-shots-wrap">
        <!-- Source product images -->
        <div class="aai-ps-section">
            <label class="aai-label"><?php esc_html_e( 'Zdjęcie produktu:', 'agencyjnie-ai-images' ); ?></label>
            <div id="aai-ps-source-preview" class="aai-ps-source-preview"></div>
            <input type="hidden" id="aai-ps-source-id" value="" />
            <button type="button" id="aai-ps-upload-source" class="button button-small">
                <?php esc_html_e( 'Wybierz zdjęcie', 'agencyjnie-ai-images' ); ?>
            </button>
        </div>

        <!-- Scene descriptions -->
        <div class="aai-ps-section">
            <label class="aai-label"><?php esc_html_e( 'Sceny do wygenerowania:', 'agencyjnie-ai-images' ); ?></label>
            <div id="aai-ps-scenes" class="aai-ps-scenes">
                <div class="aai-ps-scene-row">
                    <select class="aai-ps-preset">
                        <option value=""><?php esc_html_e( 'Wybierz preset...', 'agencyjnie-ai-images' ); ?></option>
                        <?php foreach ( $scene_presets as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea class="aai-ps-scene-prompt" rows="2" placeholder="<?php esc_attr_e( 'Opis sceny...', 'agencyjnie-ai-images' ); ?>"></textarea>
                </div>
            </div>
            <button type="button" id="aai-ps-add-scene" class="button button-small">
                <?php esc_html_e( '+ Dodaj scenę', 'agencyjnie-ai-images' ); ?>
            </button>
        </div>

        <!-- Generate button -->
        <div class="aai-ps-section">
            <button type="button" id="aai-ps-generate" class="button button-primary" style="width:100%;"
                    data-product-id="<?php echo esc_attr( $post->ID ); ?>">
                <span class="aai-btn-text"><?php esc_html_e( 'Generuj zdjęcia produktowe', 'agencyjnie-ai-images' ); ?></span>
                <span class="aai-btn-spinner spinner" style="display: none;"></span>
            </button>
        </div>

        <!-- Progress & messages -->
        <div id="aai-ps-message" class="aai-message" style="display: none;"></div>
        <div id="aai-ps-progress" class="aai-ps-progress" style="display: none;">
            <span id="aai-ps-progress-text"></span>
        </div>

        <!-- Results gallery -->
        <div id="aai-ps-results" class="aai-ps-results"></div>
    </div>
    <?php
}

/**
 * AJAX handler — generate a single product shot
 */
function aai_ajax_generate_product_shot() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    $source_id   = isset( $_POST['source_image_id'] ) ? absint( $_POST['source_image_id'] ) : 0;
    $scene_prompt = isset( $_POST['scene_prompt'] ) ? sanitize_textarea_field( $_POST['scene_prompt'] ) : '';

    if ( ! $product_id || ! $source_id || empty( $scene_prompt ) ) {
        wp_send_json_error( array( 'message' => 'Brak wymaganych parametrów.' ) );
    }

    if ( ! current_user_can( 'edit_post', $product_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    // Load source image as base64
    $base64_data = aai_get_attachment_base64( $source_id );
    if ( is_wp_error( $base64_data ) ) {
        wp_send_json_error( array( 'message' => $base64_data->get_error_message() ) );
    }

    // Get style description
    $style_desc = aai_get_style_description();

    // Build the prompt for product shot
    $prompt = sprintf(
        'Create a professional product photograph. Scene: %s. ' .
        'The product shown in the reference image should be the hero of the composition. ' .
        'Style: %s. ' .
        'IMPORTANT: Keep the product recognizable and accurate to the reference. ' .
        'High-quality commercial photography, professional lighting.',
        $scene_prompt,
        $style_desc ? $style_desc : 'professional product photography'
    );

    // Send to Gemini via existing function
    $result = aai_send_image_to_gemini(
        $base64_data['base64'],
        $base64_data['mime_type'],
        $prompt,
        $product_id,
        'product_shot'
    );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Set product-specific meta
    update_post_meta( $result['attachment_id'], '_aai_product_shot', true );
    update_post_meta( $result['attachment_id'], '_aai_source_product', $product_id );

    wp_send_json_success( array(
        'attachment_id' => $result['attachment_id'],
        'image_url'     => $result['image_url'],
        'tokens'        => $result['tokens'],
    ) );
}
add_action( 'wp_ajax_aai_generate_product_shot', 'aai_ajax_generate_product_shot' );

/**
 * AJAX handler — add attachment to WooCommerce product gallery
 */
function aai_ajax_add_to_product_gallery() {
    if ( ! check_ajax_referer( 'aai_generate_image', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa.' ) );
    }

    $product_id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

    if ( ! $product_id || ! $attachment_id || ! current_user_can( 'edit_post', $product_id ) ) {
        wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
    }

    // Get current gallery
    $gallery = get_post_meta( $product_id, '_product_image_gallery', true );
    $gallery_ids = $gallery ? explode( ',', $gallery ) : array();

    // Add if not already present
    if ( ! in_array( (string) $attachment_id, $gallery_ids, true ) ) {
        $gallery_ids[] = $attachment_id;
        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
    }

    wp_send_json_success( array( 'message' => 'Dodano do galerii produktu!' ) );
}
add_action( 'wp_ajax_aai_add_to_product_gallery', 'aai_ajax_add_to_product_gallery' );
