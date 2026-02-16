<?php
/**
 * Upscale i edycja obrazków AI
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Upscale an image — sends it to Gemini with enhancement prompt
 *
 * @param int $attachment_id Attachment to upscale
 * @param int $post_id Associated post ID
 * @return array|WP_Error Result with new attachment_id and image_url
 */
function aai_upscale_image( $attachment_id, $post_id ) {
    $image_data = aai_get_attachment_base64( $attachment_id );
    if ( is_wp_error( $image_data ) ) {
        return $image_data;
    }

    $prompt = "Reimagine and enhance this image at the highest possible quality and resolution. " .
              "Preserve ALL details, composition, colors, subjects, and style exactly as they are. " .
              "Improve sharpness, clarity, and fine details. Make it look professional and crisp. " .
              "Do NOT change the subject, composition, or artistic style.";

    return aai_send_image_to_gemini( $image_data['base64'], $image_data['mime_type'], $prompt, $post_id, 'upscale' );
}

/**
 * Edit an image — sends it to Gemini with user-provided edit instructions
 *
 * @param int    $attachment_id Attachment to edit
 * @param int    $post_id Associated post ID
 * @param string $edit_prompt User's edit instructions
 * @return array|WP_Error Result with new attachment_id and image_url
 */
function aai_edit_image( $attachment_id, $post_id, $edit_prompt ) {
    if ( empty( $edit_prompt ) ) {
        return new WP_Error( 'empty_prompt', 'Opisz jakie zmiany chcesz wprowadzić.' );
    }

    $image_data = aai_get_attachment_base64( $attachment_id );
    if ( is_wp_error( $image_data ) ) {
        return $image_data;
    }

    $prompt = "Edit this image according to these instructions: " . $edit_prompt . "\n\n" .
              "Keep all other aspects of the image unchanged unless specifically instructed otherwise.";

    return aai_send_image_to_gemini( $image_data['base64'], $image_data['mime_type'], $prompt, $post_id, 'edit' );
}

/**
 * Read an attachment and convert to base64
 */
function aai_get_attachment_base64( $attachment_id ) {
    $file_path = get_attached_file( $attachment_id );
    if ( ! $file_path || ! file_exists( $file_path ) ) {
        return new WP_Error( 'no_file', 'Plik obrazka nie istnieje.' );
    }

    $mime_type = get_post_mime_type( $attachment_id );
    if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
        return new WP_Error( 'not_image', 'Plik nie jest obrazkiem.' );
    }

    $image_content = file_get_contents( $file_path );
    if ( empty( $image_content ) ) {
        return new WP_Error( 'read_failed', 'Nie udało się odczytać pliku.' );
    }

    return array(
        'base64'    => base64_encode( $image_content ),
        'mime_type' => $mime_type,
    );
}

/**
 * Send an image + prompt to Gemini for editing/upscaling
 */
function aai_send_image_to_gemini( $base64_image, $mime_type, $prompt, $post_id, $generation_type = 'edit' ) {
    $api_key = aai_get_secure_option( 'api_key' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', 'Brak klucza API Gemini.' );
    }

    // Use the same model mapping as aai_generate_image()
    $model = aai_get_option( 'ai_model', 'gemini' );
    $gemini_models = array(
        'gemini'     => 'gemini-2.5-flash-image',
        'gemini-pro' => 'gemini-3-pro-image-preview',
        'imagen3'    => 'imagen-3.0-generate-001',
    );
    // For imagen3, fall back to flash since Imagen doesn't support image editing
    $gemini_model = isset( $gemini_models[ $model ] ) ? $gemini_models[ $model ] : 'gemini-2.5-flash-image';
    if ( $model === 'imagen3' ) {
        $gemini_model = 'gemini-2.5-flash-image';
    }

    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $gemini_model . ':generateContent';

    $request_body = array(
        'contents' => array(
            array(
                'role' => 'user',
                'parts' => array(
                    array(
                        'inlineData' => array(
                            'mimeType' => $mime_type,
                            'data'     => $base64_image,
                        ),
                    ),
                    array(
                        'text' => $prompt,
                    ),
                ),
            ),
        ),
        'generationConfig' => array(
            'responseModalities' => array( 'IMAGE', 'TEXT' ),
        ),
        'safetySettings' => array(
            array( 'category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
            array( 'category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH' ),
            array( 'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
            array( 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
        ),
    );

    $response = wp_remote_post( $api_url, array(
        'timeout' => 120,
        'headers' => array(
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ),
        'body' => wp_json_encode( $request_body ),
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'connection_error', 'Błąd połączenia z API: ' . $response->get_error_message() );
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $response_code !== 200 ) {
        $error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Błąd API';
        return new WP_Error( 'api_error', sprintf( 'Błąd API (kod %d): %s', $response_code, $error_msg ) );
    }

    // Extract image from response (reuse existing function)
    $result_image = aai_extract_image_from_response( $data );
    if ( is_wp_error( $result_image ) ) {
        return $result_image;
    }

    $tokens = aai_extract_token_usage( $data );

    // Save to media library
    $new_attachment_id = aai_save_image_to_media_library( $result_image, $post_id, 'featured' );
    if ( is_wp_error( $new_attachment_id ) ) {
        return $new_attachment_id;
    }

    update_post_meta( $new_attachment_id, '_aai_source', 'ai_generated' );
    update_post_meta( $new_attachment_id, '_aai_edit_type', $generation_type );

    // Log stats
    if ( function_exists( 'aai_log_generation' ) ) {
        aai_log_generation( $post_id, $model, $tokens, 'success', $generation_type );
    }

    $image_url = wp_get_attachment_image_url( $new_attachment_id, 'medium' );

    return array(
        'attachment_id' => $new_attachment_id,
        'image_url'     => $image_url,
        'tokens'        => $tokens,
    );
}
