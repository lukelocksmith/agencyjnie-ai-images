<?php
/**
 * Źródło obrazków: Brandfetch API
 * Pobiera logo i materiały brandowe znanych firm
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lista znanych brandów i ich domen
 * Używana do wykrywania nazw w tekście
 */
function aai_get_known_brands() {
    return array(
        // Tech Giants
        'google'      => 'google.com',
        'microsoft'   => 'microsoft.com',
        'apple'       => 'apple.com',
        'amazon'      => 'amazon.com',
        'meta'        => 'meta.com',
        'facebook'    => 'facebook.com',
        
        // Productivity & Communication
        'slack'       => 'slack.com',
        'notion'      => 'notion.so',
        'trello'      => 'trello.com',
        'asana'       => 'asana.com',
        'monday'      => 'monday.com',
        'clickup'     => 'clickup.com',
        'basecamp'    => 'basecamp.com',
        'todoist'     => 'todoist.com',
        'evernote'    => 'evernote.com',
        'zoom'        => 'zoom.us',
        'teams'       => 'microsoft.com',
        'discord'     => 'discord.com',
        'telegram'    => 'telegram.org',
        'whatsapp'    => 'whatsapp.com',
        
        // Design & Creative
        'figma'       => 'figma.com',
        'canva'       => 'canva.com',
        'adobe'       => 'adobe.com',
        'photoshop'   => 'adobe.com',
        'illustrator' => 'adobe.com',
        'sketch'      => 'sketch.com',
        'invision'    => 'invisionapp.com',
        'miro'        => 'miro.com',
        'dribbble'    => 'dribbble.com',
        'behance'     => 'behance.net',
        
        // Development
        'github'      => 'github.com',
        'gitlab'      => 'gitlab.com',
        'bitbucket'   => 'bitbucket.org',
        'jira'        => 'atlassian.com',
        'confluence'  => 'atlassian.com',
        'vercel'      => 'vercel.com',
        'netlify'     => 'netlify.com',
        'heroku'      => 'heroku.com',
        'docker'      => 'docker.com',
        'kubernetes'  => 'kubernetes.io',
        'aws'         => 'aws.amazon.com',
        'azure'       => 'azure.microsoft.com',
        
        // Marketing & Analytics
        'mailchimp'   => 'mailchimp.com',
        'hubspot'     => 'hubspot.com',
        'salesforce'  => 'salesforce.com',
        'semrush'     => 'semrush.com',
        'ahrefs'      => 'ahrefs.com',
        'moz'         => 'moz.com',
        'hotjar'      => 'hotjar.com',
        'mixpanel'    => 'mixpanel.com',
        'amplitude'   => 'amplitude.com',
        'segment'     => 'segment.com',
        'intercom'    => 'intercom.com',
        'zendesk'     => 'zendesk.com',
        
        // E-commerce
        'shopify'     => 'shopify.com',
        'woocommerce' => 'woocommerce.com',
        'stripe'      => 'stripe.com',
        'paypal'      => 'paypal.com',
        'square'      => 'squareup.com',
        
        // Social Media
        'twitter'     => 'twitter.com',
        'x'           => 'x.com',
        'instagram'   => 'instagram.com',
        'linkedin'    => 'linkedin.com',
        'tiktok'      => 'tiktok.com',
        'youtube'     => 'youtube.com',
        'pinterest'   => 'pinterest.com',
        'reddit'      => 'reddit.com',
        
        // CMS & Website Builders
        'wordpress'   => 'wordpress.org',
        'webflow'     => 'webflow.com',
        'wix'         => 'wix.com',
        'squarespace' => 'squarespace.com',
        'ghost'       => 'ghost.org',
        
        // AI & Automation
        'openai'      => 'openai.com',
        'chatgpt'     => 'openai.com',
        'anthropic'   => 'anthropic.com',
        'claude'      => 'anthropic.com',
        'midjourney'  => 'midjourney.com',
        'zapier'      => 'zapier.com',
        'make'        => 'make.com',
        'ifttt'       => 'ifttt.com',
        'n8n'         => 'n8n.io',
    );
}

/**
 * Wykrywa nazwy brandów w tekście
 * 
 * @param string $text Tekst do analizy
 * @return array Lista wykrytych brandów z domenami
 */
function aai_detect_brands_in_text( $text ) {
    $known_brands = aai_get_known_brands();
    $detected = array();
    
    $text_lower = strtolower( $text );
    
    foreach ( $known_brands as $brand_name => $domain ) {
        // Szukaj całego słowa (word boundary)
        $pattern = '/\b' . preg_quote( $brand_name, '/' ) . '\b/i';
        
        if ( preg_match( $pattern, $text ) ) {
            $detected[ $brand_name ] = $domain;
        }
    }
    
    return $detected;
}

/**
 * Pobiera logo brandu z Brandfetch API
 * 
 * @param string $domain Domena brandu (np. slack.com)
 * @return array|false Dane logo lub false
 */
function aai_fetch_brand_logo( $domain ) {
    // Sprawdź czy źródło jest włączone
    if ( ! aai_get_option( 'source_brandfetch', false ) ) {
        return false;
    }
    
    $api_key = aai_get_secure_option( 'brandfetch_api_key', '' );
    
    if ( empty( $api_key ) ) {
        return false;
    }
    
    // Brandfetch API endpoint
    $api_url = 'https://api.brandfetch.io/v2/brands/' . urlencode( $domain );
    
    $response = wp_remote_get( $api_url, array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
        ),
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
    
    if ( empty( $data ) ) {
        return false;
    }
    
    // Wyciągnij najlepsze logo
    $logo_url = aai_extract_best_logo_from_brandfetch( $data );
    
    if ( ! $logo_url ) {
        return false;
    }
    
    return array(
        'source'     => 'brandfetch',
        'url'        => $logo_url,
        'brand_name' => isset( $data['name'] ) ? $data['name'] : $domain,
        'domain'     => $domain,
    );
}

/**
 * Wyciąga najlepsze logo z odpowiedzi Brandfetch
 * Priorytet: logo > icon > symbol
 */
function aai_extract_best_logo_from_brandfetch( $data ) {
    // Sprawdź logos
    if ( ! empty( $data['logos'] ) ) {
        foreach ( $data['logos'] as $logo ) {
            if ( isset( $logo['formats'] ) ) {
                // Preferuj PNG lub SVG
                foreach ( $logo['formats'] as $format ) {
                    if ( isset( $format['src'] ) ) {
                        $src = $format['src'];
                        // Preferuj większe rozmiary
                        if ( strpos( $src, '.png' ) !== false || strpos( $src, '.svg' ) !== false ) {
                            return $src;
                        }
                    }
                }
                // Fallback - pierwszy dostępny format
                if ( isset( $logo['formats'][0]['src'] ) ) {
                    return $logo['formats'][0]['src'];
                }
            }
        }
    }
    
    // Sprawdź icons jako fallback
    if ( ! empty( $data['icons'] ) ) {
        foreach ( $data['icons'] as $icon ) {
            if ( isset( $icon['formats'] ) ) {
                foreach ( $icon['formats'] as $format ) {
                    if ( isset( $format['src'] ) ) {
                        return $format['src'];
                    }
                }
            }
        }
    }
    
    return false;
}

/**
 * Szuka logo brandu dla tekstu (wrapper)
 * 
 * @param string $text Tekst do analizy
 * @return array|false Dane logo pierwszego wykrytego brandu lub false
 */
function aai_find_brand_logo_for_text( $text ) {
    $detected_brands = aai_detect_brands_in_text( $text );
    
    if ( empty( $detected_brands ) ) {
        return false;
    }
    
    // Spróbuj pobrać logo dla pierwszego wykrytego brandu
    foreach ( $detected_brands as $brand_name => $domain ) {
        $logo = aai_fetch_brand_logo( $domain );
        
        if ( $logo ) {
            $logo['detected_brand'] = $brand_name;
            return $logo;
        }
    }
    
    return false;
}

/**
 * Pobiera i zapisuje logo brandu do Media Library
 * 
 * @param string $logo_url URL logo
 * @param int    $post_id  ID posta
 * @param string $brand_name Nazwa brandu
 * @return int|WP_Error ID załącznika lub błąd
 */
function aai_download_brand_logo( $logo_url, $post_id, $brand_name ) {
    // Przygotuj metadane dla unified saver
    $meta = array(
        'title'       => $brand_name . ' - Logo',
        'alt'         => 'Logo ' . $brand_name,
        'caption'     => 'Logo ' . $brand_name,
        'description' => '',
        'source'      => 'brandfetch',
        'source_url'  => $logo_url,
        'context'     => 'logo-' . sanitize_title( $brand_name ),
        'brand_name'  => $brand_name,
    );
    
    // Zapisz używając unified saver
    $attachment_id = aai_save_remote_image( $logo_url, $post_id, $meta );
    
    if ( is_wp_error( $attachment_id ) ) {
        return $attachment_id;
    }
    
    // Dodatkowe metadane specyficzne dla Brandfetch (zachowanie kompatybilności)
    update_post_meta( $attachment_id, '_aai_brand_name', $brand_name );
    
    return $attachment_id;
}
