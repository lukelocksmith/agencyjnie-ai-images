<?php
/**
 * Style artystyczne per kategoria
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Gets the style for a post based on its categories
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

    // Check each category (first match wins)
    foreach ( $categories as $cat_id ) {
        if ( isset( $mappings[ $cat_id ] ) && ! empty( $mappings[ $cat_id ] ) ) {
            return $mappings[ $cat_id ];
        }
    }

    return null;
}

/**
 * Register admin submenu page
 */
function aai_register_category_styles_page() {
    add_submenu_page(
        'options-general.php',
        __( 'AI Images - Style kategorii', 'agencyjnie-ai-images' ),
        __( 'AI Images Kategorie', 'agencyjnie-ai-images' ),
        'manage_options',
        'aai-category-styles',
        'aai_render_category_styles_page'
    );
}
add_action( 'admin_menu', 'aai_register_category_styles_page' );

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

    $styles = isset( $_POST['category_styles'] ) ? (array) $_POST['category_styles'] : array();

    // Sanitize
    $sanitized = array();
    $allowed_styles = array_keys( aai_get_all_style_descriptions() );
    $allowed_styles[] = ''; // allow empty (no override)

    foreach ( $styles as $cat_id => $style_key ) {
        $cat_id = absint( $cat_id );
        $style_key = sanitize_text_field( $style_key );
        if ( $cat_id > 0 && in_array( $style_key, $allowed_styles, true ) ) {
            if ( ! empty( $style_key ) ) {
                $sanitized[ $cat_id ] = $style_key;
            }
        }
    }

    update_option( 'aai_category_styles', $sanitized );

    add_settings_error( 'aai_category_styles', 'saved', __( 'Style kategorii zapisane.', 'agencyjnie-ai-images' ), 'updated' );
}
add_action( 'admin_init', 'aai_save_category_styles' );

/**
 * Render the category styles admin page
 */
function aai_render_category_styles_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $mappings = get_option( 'aai_category_styles', array() );
    $all_styles = aai_get_all_style_descriptions();
    $categories = get_categories( array( 'hide_empty' => false ) );

    settings_errors( 'aai_category_styles' );
    ?>
    <div class="wrap aai-category-styles-wrap">
        <h1><?php esc_html_e( 'AI Images - Style per kategoria', 'agencyjnie-ai-images' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Przypisz styl artystyczny do każdej kategorii. Posty z danej kategorii będą automatycznie używać przypisanego stylu zamiast globalnego.', 'agencyjnie-ai-images' ); ?></p>

        <form method="post">
            <?php wp_nonce_field( 'aai_save_category_styles', 'aai_category_styles_nonce' ); ?>

            <table class="widefat striped aai-category-styles-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Kategoria', 'agencyjnie-ai-images' ); ?></th>
                        <th><?php esc_html_e( 'Styl artystyczny', 'agencyjnie-ai-images' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $categories as $cat ) :
                        $current_style = isset( $mappings[ $cat->term_id ] ) ? $mappings[ $cat->term_id ] : '';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $cat->name ); ?></strong>
                            <span class="aai-cat-count">(<?php echo esc_html( $cat->count ); ?> wpisów)</span>
                        </td>
                        <td>
                            <select name="category_styles[<?php echo esc_attr( $cat->term_id ); ?>]">
                                <option value=""><?php esc_html_e( '— Użyj globalnego stylu —', 'agencyjnie-ai-images' ); ?></option>
                                <?php foreach ( $all_styles as $key => $desc ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_style, $key ); ?>>
                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button( __( 'Zapisz style kategorii', 'agencyjnie-ai-images' ) ); ?>
        </form>
    </div>
    <?php
}
