<?php
/**
 * Dodatkowe instrukcje promptu per kategoria
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gets the additional prompt instructions for a post based on its categories.
 *
 * @param int $post_id
 * @return string|null Additional prompt text, or null if none set.
 */
function aai_get_category_style( $post_id ) {
    $mappings = get_option( 'aai_category_styles', array() );
    if ( empty( $mappings ) || ! is_array( $mappings ) ) {
        return null;
    }

    $categories = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
    if ( empty( $categories ) ) {
        return null;
    }

    // First match wins
    foreach ( $categories as $cat_id ) {
        if ( isset( $mappings[ $cat_id ] ) && ! empty( $mappings[ $cat_id ] ) ) {
            return $mappings[ $cat_id ];
        }
    }

    return null;
}

/**
 * Handle save action
 */
function aai_save_category_styles() {
    if ( ! isset( $_POST['aai_category_styles_nonce'] ) || ! wp_verify_nonce( $_POST['aai_category_styles_nonce'], 'aai_save_category_styles' ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $inputs = isset( $_POST['category_styles'] ) ? (array) $_POST['category_styles'] : array();

    $sanitized = array();
    foreach ( $inputs as $cat_id => $prompt_text ) {
        $cat_id      = absint( $cat_id );
        $prompt_text = sanitize_textarea_field( wp_unslash( $prompt_text ) );
        if ( $cat_id > 0 && ! empty( $prompt_text ) ) {
            $sanitized[ $cat_id ] = $prompt_text;
        }
    }

    update_option( 'aai_category_styles', $sanitized );

    add_settings_error( 'aai_category_styles', 'saved', __( 'Instrukcje kategorii zapisane.', 'agencyjnie-ai-images' ), 'updated' );
}
add_action( 'admin_init', 'aai_save_category_styles' );

/**
 * Render the category prompt instructions admin page
 */
function aai_render_category_styles_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $mappings   = get_option( 'aai_category_styles', array() );
    $categories = get_categories( array( 'hide_empty' => false ) );

    settings_errors( 'aai_category_styles' );
    ?>
    <div class="aai-category-styles-wrap">
        <p class="description">
            <?php esc_html_e( 'Wpisz dodatkowe instrukcje promptu dla każdej kategorii. Zostaną one dołączone do promptu przy generowaniu obrazka dla wpisów z danej kategorii. Możesz np. określić styl, kolorystykę, motyw wizualny lub dowolne inne wskazówki dla AI.', 'agencyjnie-ai-images' ); ?>
        </p>
        <p class="description" style="margin-top:4px;">
            <em><?php esc_html_e( 'Przykład: "Use warm orange and brown tones, autumn atmosphere, cozy coffee shop vibe"', 'agencyjnie-ai-images' ); ?></em>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=agencyjnie-ai-images&tab=categories' ) ); ?>">
            <?php wp_nonce_field( 'aai_save_category_styles', 'aai_category_styles_nonce' ); ?>

            <table class="widefat striped aai-category-styles-table">
                <thead>
                    <tr>
                        <th style="width:200px"><?php esc_html_e( 'Kategoria', 'agencyjnie-ai-images' ); ?></th>
                        <th><?php esc_html_e( 'Dodatkowe instrukcje promptu', 'agencyjnie-ai-images' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $categories as $cat ) :
                        $current = isset( $mappings[ $cat->term_id ] ) ? $mappings[ $cat->term_id ] : '';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $cat->name ); ?></strong><br>
                            <span class="description"><?php echo esc_html( $cat->count ); ?> wpisów</span>
                        </td>
                        <td>
                            <textarea
                                name="category_styles[<?php echo esc_attr( $cat->term_id ); ?>]"
                                rows="2"
                                style="width:100%;font-size:13px;"
                                placeholder="<?php esc_attr_e( 'Zostaw puste aby użyć globalnych ustawień...', 'agencyjnie-ai-images' ); ?>"
                            ><?php echo esc_textarea( $current ); ?></textarea>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button( __( 'Zapisz instrukcje', 'agencyjnie-ai-images' ) ); ?>
        </form>
    </div>
    <?php
}
