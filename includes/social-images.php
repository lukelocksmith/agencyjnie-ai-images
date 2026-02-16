<?php
/**
 * Generowanie wariantów obrazków dla social media
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Social media size definitions
 */
function aai_get_social_sizes() {
    return array(
        'og'        => array( 'width' => 1200, 'height' => 630, 'label' => 'Facebook/Twitter OG' ),
        'instagram' => array( 'width' => 1080, 'height' => 1080, 'label' => 'Instagram' ),
        'pinterest' => array( 'width' => 1000, 'height' => 1500, 'label' => 'Pinterest' ),
    );
}

/**
 * Generate social media variants from an attachment
 */
function aai_generate_social_variants( $attachment_id, $post_id ) {
    $file_path = get_attached_file( $attachment_id );
    if ( ! $file_path || ! file_exists( $file_path ) ) {
        return new WP_Error( 'no_file', 'Plik źródłowy nie istnieje.' );
    }

    $sizes = aai_get_social_sizes();
    $results = array();
    $upload_dir = wp_upload_dir();

    foreach ( $sizes as $key => $size ) {
        $resized = aai_crop_image( $file_path, $size['width'], $size['height'] );
        if ( is_wp_error( $resized ) ) {
            $results[ $key ] = $resized;
            continue;
        }

        // Generate filename
        $info = pathinfo( $file_path );
        $new_filename = $info['filename'] . '-social-' . $key . '.' . $info['extension'];
        $new_path = trailingslashit( $upload_dir['path'] ) . $new_filename;
        $new_url = trailingslashit( $upload_dir['url'] ) . $new_filename;

        // Save the resized image
        $saved = aai_save_gd_image( $resized, $new_path, $info['extension'] );
        imagedestroy( $resized );

        if ( ! $saved ) {
            $results[ $key ] = new WP_Error( 'save_failed', 'Nie udało się zapisać wariantu ' . $key );
            continue;
        }

        // Create attachment in media library
        $attachment = array(
            'post_mime_type' => wp_check_filetype( $new_path )['type'],
            'post_title'     => get_the_title( $post_id ) . ' - ' . $size['label'],
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $post_id,
        );

        $variant_id = wp_insert_attachment( $attachment, $new_path, $post_id );

        if ( is_wp_error( $variant_id ) ) {
            $results[ $key ] = $variant_id;
            continue;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $variant_id, $new_path );
        wp_update_attachment_metadata( $variant_id, $metadata );

        // Mark as social variant
        update_post_meta( $variant_id, '_aai_social_variant', $key );
        update_post_meta( $variant_id, '_aai_social_source_post', $post_id );

        $results[ $key ] = array(
            'attachment_id' => $variant_id,
            'url'           => $new_url,
            'width'         => $size['width'],
            'height'        => $size['height'],
        );
    }

    // Store variant IDs in post meta
    $variant_ids = array();
    foreach ( $results as $key => $result ) {
        if ( ! is_wp_error( $result ) && isset( $result['attachment_id'] ) ) {
            $variant_ids[ $key ] = $result['attachment_id'];
        }
    }
    update_post_meta( $post_id, '_aai_social_variants', $variant_ids );

    return $results;
}

/**
 * Crop/resize image using GD
 */
function aai_crop_image( $file_path, $target_width, $target_height ) {
    $image_info = getimagesize( $file_path );
    if ( ! $image_info ) {
        return new WP_Error( 'invalid_image', 'Nieprawidłowy plik obrazka.' );
    }

    $mime = $image_info['mime'];
    $src_width = $image_info[0];
    $src_height = $image_info[1];

    // Load source image
    switch ( $mime ) {
        case 'image/jpeg':
            $src = imagecreatefromjpeg( $file_path );
            break;
        case 'image/png':
            $src = imagecreatefrompng( $file_path );
            break;
        case 'image/webp':
            if ( function_exists( 'imagecreatefromwebp' ) ) {
                $src = imagecreatefromwebp( $file_path );
            } else {
                return new WP_Error( 'no_webp', 'Brak obsługi WebP.' );
            }
            break;
        default:
            return new WP_Error( 'unsupported', 'Nieobsługiwany format: ' . $mime );
    }

    if ( ! $src ) {
        return new WP_Error( 'load_failed', 'Nie udało się załadować obrazka.' );
    }

    // Calculate crop dimensions (center crop)
    $src_ratio = $src_width / $src_height;
    $target_ratio = $target_width / $target_height;

    if ( $src_ratio > $target_ratio ) {
        // Source is wider — crop sides
        $crop_height = $src_height;
        $crop_width = (int) ( $src_height * $target_ratio );
        $crop_x = (int) ( ( $src_width - $crop_width ) / 2 );
        $crop_y = 0;
    } else {
        // Source is taller — crop top/bottom
        $crop_width = $src_width;
        $crop_height = (int) ( $src_width / $target_ratio );
        $crop_x = 0;
        $crop_y = (int) ( ( $src_height - $crop_height ) / 2 );
    }

    $dst = imagecreatetruecolor( $target_width, $target_height );

    // Preserve transparency for PNG
    if ( $mime === 'image/png' ) {
        imagealphablending( $dst, false );
        imagesavealpha( $dst, true );
    }

    imagecopyresampled(
        $dst, $src,
        0, 0,
        $crop_x, $crop_y,
        $target_width, $target_height,
        $crop_width, $crop_height
    );

    imagedestroy( $src );

    return $dst;
}

/**
 * Save a GD image resource to file
 */
function aai_save_gd_image( $image, $path, $extension ) {
    switch ( strtolower( $extension ) ) {
        case 'jpg':
        case 'jpeg':
            return imagejpeg( $image, $path, 90 );
        case 'png':
            return imagepng( $image, $path, 8 );
        case 'webp':
            if ( function_exists( 'imagewebp' ) ) {
                return imagewebp( $image, $path, 85 );
            }
            return imagejpeg( $image, $path, 90 );
        default:
            return imagejpeg( $image, $path, 90 );
    }
}

/**
 * Get existing social variants for a post
 */
function aai_get_social_variants( $post_id ) {
    $variant_ids = get_post_meta( $post_id, '_aai_social_variants', true );
    if ( ! is_array( $variant_ids ) || empty( $variant_ids ) ) {
        return array();
    }

    $variants = array();
    $sizes = aai_get_social_sizes();

    foreach ( $variant_ids as $key => $att_id ) {
        $url = wp_get_attachment_url( $att_id );
        if ( $url && isset( $sizes[ $key ] ) ) {
            $variants[ $key ] = array(
                'attachment_id' => $att_id,
                'url'           => $url,
                'label'         => $sizes[ $key ]['label'],
                'width'         => $sizes[ $key ]['width'],
                'height'        => $sizes[ $key ]['height'],
            );
        }
    }

    return $variants;
}

/**
 * Output OG meta tags in wp_head
 */
function aai_output_og_meta_tags() {
    if ( ! is_singular() ) {
        return;
    }

    // Don't output if Yoast or RankMath is active (they handle OG)
    if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
        return;
    }

    $post_id = get_the_ID();
    $variants = aai_get_social_variants( $post_id );

    if ( isset( $variants['og'] ) ) {
        echo '<meta property="og:image" content="' . esc_url( $variants['og']['url'] ) . '" />' . "\n";
        echo '<meta property="og:image:width" content="' . esc_attr( $variants['og']['width'] ) . '" />' . "\n";
        echo '<meta property="og:image:height" content="' . esc_attr( $variants['og']['height'] ) . '" />' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url( $variants['og']['url'] ) . '" />' . "\n";
    }
}
add_action( 'wp_head', 'aai_output_og_meta_tags', 5 );

/**
 * Hook into Yoast OG image filter
 */
function aai_yoast_og_image( $image_url ) {
    if ( ! is_singular() ) {
        return $image_url;
    }

    $variants = aai_get_social_variants( get_the_ID() );
    if ( isset( $variants['og'] ) ) {
        return $variants['og']['url'];
    }

    return $image_url;
}
add_filter( 'wpseo_opengraph_image', 'aai_yoast_og_image' );

/**
 * Hook into RankMath OG image filter
 */
function aai_rankmath_og_image( $image_url ) {
    if ( ! is_singular() ) {
        return $image_url;
    }

    $variants = aai_get_social_variants( get_the_ID() );
    if ( isset( $variants['og'] ) ) {
        return $variants['og']['url'];
    }

    return $image_url;
}
add_filter( 'rank_math/opengraph/facebook/image', 'aai_rankmath_og_image' );
add_filter( 'rank_math/opengraph/twitter/image', 'aai_rankmath_og_image' );
