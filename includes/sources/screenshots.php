<?php
/**
 * Źródło obrazków: Screenshoty stron narzędzi
 * Robi screenshoty stron głównych wykrytych narzędzi (Make, Figma, n8n, etc.)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Robi screenshot strony internetowej przez Urlbox API
 * 
 * @param string $url URL strony do screenshota
 * @return array|false Dane screenshota lub false
 */
function aai_capture_website_screenshot( $url ) {
    // Sprawdź czy źródło jest włączone
    if ( ! aai_get_option( 'source_screenshots', false ) ) {
        return false;
    }
    
    $api_key = aai_get_secure_option( 'urlbox_api_key', '' );
    
    if ( empty( $api_key ) ) {
        return false;
    }
    
    // Urlbox API endpoint
    $api_url = 'https://api.urlbox.io/v1/render/sync';
    
    // Parametry screenshota
    $request_body = array(
        'url'         => $url,
        'width'       => 1280,
        'height'      => 800,
        'format'      => 'png',
        'full_page'   => false,
        'retina'      => false,
        'block_ads'   => true,
        'hide_cookie_banners' => true,
    );
    
    $response = wp_remote_post( $api_url, array(
        'timeout' => 60, // Screenshoty mogą trwać dłużej
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode( $request_body ),
    ) );
    
    if ( is_wp_error( $response ) ) {
        return false;
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    
    if ( $response_code !== 200 ) {
        return false;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( empty( $data['renderUrl'] ) ) {
        return false;
    }
    
    return array(
        'source'      => 'screenshot',
        'url'         => $data['renderUrl'],
        'render_time' => isset( $data['renderTime'] ) ? $data['renderTime'] : 0,
        'original_url' => $url,
    );
}

/**
 * Szuka screenshota dla wykrytych brandów
 * 
 * @param array $brands Tablica brandów z domenami
 * @return array|false Dane screenshota lub false
 */
function aai_capture_screenshot_for_brands( $brands ) {
    if ( empty( $brands ) ) {
        return false;
    }
    
    // Sprawdź czy źródło jest włączone
    if ( ! aai_get_option( 'source_screenshots', false ) ) {
        return false;
    }
    
    // Weź pierwszy wykryty brand
    foreach ( $brands as $brand_name => $domain ) {
        // Buduj pełny URL
        $url = 'https://' . $domain;
        
        $screenshot = aai_capture_website_screenshot( $url );
        
        if ( $screenshot ) {
            $screenshot['brand_name'] = $brand_name;
            $screenshot['domain'] = $domain;
            return $screenshot;
        }
    }
    
    return false;
}

/**
 * Pobiera i zapisuje screenshot do Media Library
 * 
 * @param string $screenshot_url URL screenshota z Urlbox
 * @param int    $post_id        ID posta
 * @param string $brand_name     Nazwa brandu
 * @param string $domain         Domena
 * @return int|WP_Error ID załącznika lub błąd
 */
function aai_download_screenshot( $screenshot_url, $post_id, $brand_name, $domain = '' ) {
    // Przygotuj metadane dla unified saver
    $title = sprintf( 
        __( 'Screenshot strony %s', 'agencyjnie-ai-images' ), 
        ucfirst( $brand_name ) 
    );
    
    $meta = array(
        'title'       => $title,
        'alt'         => sprintf( __( 'Strona główna %s - screenshot', 'agencyjnie-ai-images' ), ucfirst( $brand_name ) ),
        'caption'     => sprintf( __( 'Screenshot strony głównej %s', 'agencyjnie-ai-images' ), $domain ),
        'description' => '',
        'source'      => 'screenshot',
        'source_url'  => 'https://' . $domain,
        'context'     => 'screenshot-' . sanitize_title( $brand_name ),
        'brand_name'  => $brand_name,
    );
    
    // Zapisz używając unified saver
    $attachment_id = aai_save_remote_image( $screenshot_url, $post_id, $meta );
    
    if ( is_wp_error( $attachment_id ) ) {
        return $attachment_id;
    }
    
    // Dodatkowe metadane specyficzne dla screenshotów (kompatybilność)
    update_post_meta( $attachment_id, '_aai_brand_name', $brand_name );
    
    return $attachment_id;
}

/**
 * Sprawdza czy brand ma stronę nadającą się do screenshota
 * Niektóre strony mogą blokować screenshoty lub mieć problemy
 */
function aai_is_brand_screenshotable( $domain ) {
    // Lista domen, które dobrze się screenshotują
    $good_domains = array(
        'make.com',
        'figma.com',
        'n8n.io',
        'notion.so',
        'slack.com',
        'trello.com',
        'asana.com',
        'monday.com',
        'clickup.com',
        'airtable.com',
        'zapier.com',
        'webflow.com',
        'canva.com',
        'miro.com',
        'linear.app',
        'github.com',
        'gitlab.com',
        'vercel.com',
        'netlify.com',
        'stripe.com',
        'mailchimp.com',
        'hubspot.com',
        'intercom.com',
        'zendesk.com',
        'shopify.com',
        'squarespace.com',
        'wix.com',
        'ghost.org',
        'buffer.com',
        'hootsuite.com',
        'semrush.com',
        'ahrefs.com',
        'hotjar.com',
        'mixpanel.com',
        'amplitude.com',
        'segment.com',
        'calendly.com',
        'loom.com',
        'dropbox.com',
        'evernote.com',
        'todoist.com',
        'basecamp.com',
        'jira.atlassian.com',
        'confluence.atlassian.com',
    );
    
    // Sprawdź czy domena jest na liście
    foreach ( $good_domains as $good_domain ) {
        if ( strpos( $domain, $good_domain ) !== false || $domain === $good_domain ) {
            return true;
        }
    }
    
    // Domyślnie też pozwalamy - może zadziała
    return true;
}
