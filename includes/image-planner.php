<?php
/**
 * AI Image Planner — article analysis and image placement suggestions
 *
 * Uses Gemini Flash to analyze article structure and suggest where to insert
 * AI-generated images with appropriate content types and art styles.
 *
 * @package AgencyjnieAIImages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Analyze article sections and suggest image placements using Gemini Flash.
 *
 * Accepts sections from the JS editor (not from DB) so unsaved changes are included.
 *
 * @param array  $sections Array of ['type' => 'HEADING|PARAGRAPH|LIST', 'text' => '...']
 * @param string $title    Article title
 * @return array|WP_Error  Array of suggestions or error
 */
function aai_plan_content_images( $sections, $title ) {
    $api_key = aai_get_secure_option( 'api_key' );

    if ( empty( $api_key ) ) {
        return new WP_Error( 'no_api_key', __( 'Brak klucza API Gemini.', 'agencyjnie-ai-images' ) );
    }

    if ( empty( $sections ) || ! is_array( $sections ) ) {
        return new WP_Error( 'no_sections', __( 'Brak sekcji do analizy.', 'agencyjnie-ai-images' ) );
    }

    // Build section list for the prompt
    $sections_text = '';
    foreach ( $sections as $index => $section ) {
        $type = isset( $section['type'] ) ? strtoupper( $section['type'] ) : 'UNKNOWN';
        $text = isset( $section['text'] ) ? trim( $section['text'] ) : '';
        if ( mb_strlen( $text ) > 200 ) {
            $text = mb_substr( $text, 0, 200 ) . '...';
        }
        $sections_text .= "[{$index}] {$type}: {$text}\n";
    }

    // Get valid keys for content types and art styles
    $valid_content_types = array_keys( aai_get_all_content_type_descriptions() );
    $valid_art_styles    = array_keys( aai_get_all_style_descriptions() );

    $content_types_str = implode( ', ', $valid_content_types );
    $art_styles_str    = implode( ', ', $valid_art_styles );

    $system_instruction = 'You are an expert content editor and visual strategist. '
        . 'Analyze a blog article structure and suggest where to insert AI-generated images.';

    $user_prompt  = "ARTICLE TITLE: \"{$title}\"\n\n";
    $user_prompt .= "ARTICLE SECTIONS:\n{$sections_text}\n";
    $user_prompt .= "Available content types: {$content_types_str}\n";
    $user_prompt .= "Available art styles: {$art_styles_str}\n\n";
    $user_prompt .= "RULES:\n";
    $user_prompt .= "- Read the entire article and decide where images would genuinely help the reader\n";
    $user_prompt .= "- Use your editorial judgment — place images where they add value, skip where they don't\n";
    $user_prompt .= "- Use \"after_index\" to specify position (after which section index)\n";
    $user_prompt .= "- It's OK to place images close together if the content warrants it\n";
    $user_prompt .= "- It's also OK to have long stretches without images if the text is self-explanatory\n";
    $user_prompt .= "- Avoid: after very short paragraphs, directly after headings without content between them\n";
    $user_prompt .= "- Write prompts in English (they will be used for image generation)\n";
    $user_prompt .= "- Write reasons in Polish (they will be shown to the user)\n\n";
    $user_prompt .= "OUTPUT JSON only:\n";
    $user_prompt .= "[{\"after_index\": 3, \"content_type\": \"comparison\", \"art_style\": \"flat_illustration\", \"prompt\": \"...\", \"reason\": \"Porównanie typów CRM ułatwi zrozumienie\"}]\n";

    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    $request_body = array(
        'systemInstruction' => array(
            'parts' => array(
                array( 'text' => $system_instruction ),
            ),
        ),
        'contents' => array(
            array(
                'parts' => array(
                    array( 'text' => $user_prompt ),
                ),
            ),
        ),
        'generationConfig' => array(
            'maxOutputTokens' => 4096,
            'temperature'     => 0.7,
            'thinkingConfig'  => array(
                'thinkingBudget' => 0,
            ),
        ),
    );

    $response = wp_remote_post( $api_url, array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type'   => 'application/json',
            'x-goog-api-key' => $api_key,
        ),
        'body' => wp_json_encode( $request_body ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $body          = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 !== $response_code ) {
        $error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Błąd API Gemini';
        return new WP_Error( 'api_error', $error_msg );
    }

    if ( ! isset( $body['candidates'][0]['content']['parts'] ) ) {
        return new WP_Error( 'no_result', __( 'Nie udało się przeanalizować artykułu.', 'agencyjnie-ai-images' ) );
    }

    // Gemini 2.5 Flash returns thinking parts (thought: true) before the actual response.
    // Find the last non-thought text part which contains the JSON.
    $parts    = $body['candidates'][0]['content']['parts'];
    $raw_text = '';
    foreach ( $parts as $part ) {
        if ( ! empty( $part['thought'] ) ) {
            continue;
        }
        if ( isset( $part['text'] ) ) {
            $raw_text = $part['text'];
        }
    }

    if ( empty( $raw_text ) ) {
        return new WP_Error( 'no_result', __( 'Nie udało się przeanalizować artykułu.', 'agencyjnie-ai-images' ) );
    }

    $json_str    = aai_clean_json_string( $raw_text );
    $suggestions = json_decode( $json_str, true );

    if ( ! is_array( $suggestions ) || empty( $suggestions ) ) {
        // Dump full response to file for debugging (nginx truncates error_log)
        $debug_file = WP_CONTENT_DIR . '/aai-planner-debug.txt';
        file_put_contents( $debug_file, "=== RAW TEXT ===\n" . $raw_text . "\n\n=== CLEANED ===\n" . $json_str . "\n\n=== JSON ERROR ===\n" . json_last_error_msg() . "\n" );
        error_log( '[AAI Planner] Parse failed. Debug dumped to: ' . $debug_file );
        return new WP_Error( 'parse_error', __( 'Nie udało się sparsować sugestii z odpowiedzi AI.', 'agencyjnie-ai-images' ) );
    }

    // Sanitize and validate each suggestion
    $max_index  = count( $sections ) - 1;
    $sanitized  = array();

    foreach ( $suggestions as $s ) {
        if ( ! isset( $s['after_index'], $s['content_type'], $s['art_style'], $s['prompt'] ) ) {
            continue;
        }

        $after_index  = intval( $s['after_index'] );
        $content_type = sanitize_text_field( $s['content_type'] );
        $art_style    = sanitize_text_field( $s['art_style'] );
        $prompt       = sanitize_textarea_field( $s['prompt'] );
        $reason       = isset( $s['reason'] ) ? sanitize_text_field( $s['reason'] ) : '';

        // Validate index range
        if ( $after_index < 0 || $after_index > $max_index ) {
            continue;
        }

        // Validate content_type key
        if ( ! in_array( $content_type, $valid_content_types, true ) ) {
            $content_type = 'simple_illustration'; // safe fallback
        }

        // Validate art_style key
        if ( ! in_array( $art_style, $valid_art_styles, true ) ) {
            $art_style = 'flat_illustration'; // safe fallback
        }

        $sanitized[] = array(
            'after_index'  => $after_index,
            'content_type' => $content_type,
            'art_style'    => $art_style,
            'prompt'       => $prompt,
            'reason'       => $reason,
        );
    }

    if ( empty( $sanitized ) ) {
        return new WP_Error( 'no_valid', __( 'Żadna z sugestii AI nie przeszła walidacji.', 'agencyjnie-ai-images' ) );
    }

    return $sanitized;
}

/**
 * AJAX handler for planning content images.
 */
function aai_ajax_plan_content_images() {
    if ( ! check_ajax_referer( 'aai_block_generate', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => __( 'Błąd bezpieczeństwa.', 'agencyjnie-ai-images' ) ) );
    }

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'agencyjnie-ai-images' ) ) );
    }

    $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

    if ( empty( $title ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak tytułu artykułu.', 'agencyjnie-ai-images' ) ) );
    }

    $sections_raw = isset( $_POST['sections'] ) ? wp_unslash( $_POST['sections'] ) : '';
    $sections     = json_decode( $sections_raw, true );

    if ( ! is_array( $sections ) || empty( $sections ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak sekcji do analizy.', 'agencyjnie-ai-images' ) ) );
    }

    // Sanitize each section
    $clean_sections = array();
    foreach ( $sections as $section ) {
        if ( ! isset( $section['type'], $section['text'] ) ) {
            continue;
        }
        $clean_sections[] = array(
            'type'     => sanitize_text_field( $section['type'] ),
            'text'     => sanitize_textarea_field( $section['text'] ),
            'clientId' => isset( $section['clientId'] ) ? sanitize_text_field( $section['clientId'] ) : '',
        );
    }

    if ( empty( $clean_sections ) ) {
        wp_send_json_error( array( 'message' => __( 'Brak prawidłowych sekcji.', 'agencyjnie-ai-images' ) ) );
    }

    $suggestions = aai_plan_content_images( $clean_sections, $title );

    if ( is_wp_error( $suggestions ) ) {
        wp_send_json_error( array( 'message' => $suggestions->get_error_message() ) );
    }

    wp_send_json_success( array( 'suggestions' => $suggestions ) );
}
add_action( 'wp_ajax_aai_plan_content_images', 'aai_ajax_plan_content_images' );
