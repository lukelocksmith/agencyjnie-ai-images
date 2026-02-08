<?php
/**
 * Źródło obrazków: Unsplash i Pexels
 * Wyszukuje i pobiera darmowe zdjęcia stockowe
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Szuka zdjęcia stockowego według słów kluczowych
 * Automatycznie wybiera źródło według ustawień
 * 
 * @param array $keywords Słowa kluczowe
 * @return array|false Dane zdjęcia lub false
 */
function aai_search_stock_photos( $keywords ) {
    if ( empty( $keywords ) ) {
        return false;
    }
    
    // Inteligentne budowanie query:
    // - Weź max 2 najważniejsze słowa (pierwsze są zwykle najbardziej istotne)
    // - Dodaj kontekst "business" lub "professional" jeśli query jest zbyt ogólne
    $keywords = array_slice( $keywords, 0, 2 ); // Max 2 słowa dla precyzji
    $query = implode( ' ', $keywords );
    
    // Lista słów, które potrzebują kontekstu biznesowego
    $generic_words = array( 'building', 'growth', 'development', 'process', 'basics', 'fundamentals' );
    $needs_context = false;
    
    foreach ( $keywords as $kw ) {
        if ( in_array( strtolower( $kw ), $generic_words, true ) ) {
            $needs_context = true;
            break;
        }
    }
    
    // Dodaj kontekst biznesowy dla zbyt ogólnych słów
    if ( $needs_context ) {
        $query .= ' business professional';
    }
    
    // Sprawdź cache
    $cache_key = 'aai_stock_search_' . md5( $query );
    $cached_result = get_transient( $cache_key );
    
    if ( $cached_result !== false ) {
        return $cached_result;
    }
    
    // Sprawdź preferowane źródło
    $preferred = aai_get_option( 'preferred_stock_source', 'unsplash' );
    
    $result = false;
    
    // Spróbuj preferowane źródło najpierw
    if ( $preferred === 'unsplash' ) {
        $result = aai_search_unsplash( $query );
        if ( ! $result ) {
            // Fallback do Pexels
            $result = aai_search_pexels( $query );
        }
    } else {
        $result = aai_search_pexels( $query );
        if ( ! $result ) {
            // Fallback do Unsplash
            $result = aai_search_unsplash( $query );
        }
    }
    
    // Zapisz wynik w cache na 24 godziny (tylko jeśli znaleziono)
    if ( $result ) {
        set_transient( $cache_key, $result, DAY_IN_SECONDS );
    }
    
    return $result;
}

/**
 * Szuka zdjęcia na Unsplash
 * 
 * @param string $query Zapytanie
 * @return array|false Dane zdjęcia lub false
 */
function aai_search_unsplash( $query ) {
    // Sprawdź czy źródło jest włączone
    if ( ! aai_get_option( 'source_unsplash', false ) ) {
        return false;
    }
    
    $api_key = aai_get_secure_option( 'unsplash_api_key', '' );
    
    if ( empty( $api_key ) ) {
        return false;
    }
    
    $api_url = add_query_arg( array(
        'query'       => urlencode( $query ),
        'per_page'    => 1,
        'orientation' => 'landscape', // Preferuj landscape dla artykułów
    ), 'https://api.unsplash.com/search/photos' );
    
    $response = wp_remote_get( $api_url, array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Client-ID ' . $api_key,
            'Accept-Version' => 'v1',
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
    
    if ( empty( $data['results'][0] ) ) {
        return false;
    }
    
    $photo = $data['results'][0];
    
    // Unsplash wymaga podania atrybucji
    $attribution = sprintf(
        'Photo by %s on Unsplash',
        isset( $photo['user']['name'] ) ? $photo['user']['name'] : 'Unknown'
    );
    
    return array(
        'source'      => 'unsplash',
        'url'         => isset( $photo['urls']['regular'] ) ? $photo['urls']['regular'] : $photo['urls']['full'],
        'thumb_url'   => isset( $photo['urls']['thumb'] ) ? $photo['urls']['thumb'] : '',
        'description' => isset( $photo['description'] ) ? $photo['description'] : $query,
        'attribution' => $attribution,
        'author'      => isset( $photo['user']['name'] ) ? $photo['user']['name'] : '',
        'author_url'  => isset( $photo['user']['links']['html'] ) ? $photo['user']['links']['html'] : '',
        'photo_url'   => isset( $photo['links']['html'] ) ? $photo['links']['html'] : '',
        'download_url' => isset( $photo['links']['download'] ) ? $photo['links']['download'] : $photo['urls']['regular'],
    );
}

/**
 * Szuka zdjęcia na Pexels
 * 
 * @param string $query Zapytanie
 * @return array|false Dane zdjęcia lub false
 */
function aai_search_pexels( $query ) {
    // Sprawdź czy źródło jest włączone
    if ( ! aai_get_option( 'source_pexels', false ) ) {
        return false;
    }
    
    $api_key = aai_get_secure_option( 'pexels_api_key', '' );
    
    if ( empty( $api_key ) ) {
        return false;
    }
    
    $api_url = add_query_arg( array(
        'query'       => urlencode( $query ),
        'per_page'    => 1,
        'orientation' => 'landscape',
    ), 'https://api.pexels.com/v1/search' );
    
    $response = wp_remote_get( $api_url, array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => $api_key,
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
    
    if ( empty( $data['photos'][0] ) ) {
        return false;
    }
    
    $photo = $data['photos'][0];
    
    // Pexels też wymaga atrybucji
    $attribution = sprintf(
        'Photo by %s on Pexels',
        isset( $photo['photographer'] ) ? $photo['photographer'] : 'Unknown'
    );
    
    return array(
        'source'      => 'pexels',
        'url'         => isset( $photo['src']['large'] ) ? $photo['src']['large'] : $photo['src']['original'],
        'thumb_url'   => isset( $photo['src']['medium'] ) ? $photo['src']['medium'] : '',
        'description' => isset( $photo['alt'] ) ? $photo['alt'] : $query,
        'attribution' => $attribution,
        'author'      => isset( $photo['photographer'] ) ? $photo['photographer'] : '',
        'author_url'  => isset( $photo['photographer_url'] ) ? $photo['photographer_url'] : '',
        'photo_url'   => isset( $photo['url'] ) ? $photo['url'] : '',
        'download_url' => isset( $photo['src']['original'] ) ? $photo['src']['original'] : $photo['src']['large'],
    );
}

/**
 * Pobiera i zapisuje zdjęcie stockowe do Media Library
 * 
 * @param array  $photo_data Dane zdjęcia z Unsplash/Pexels
 * @param int    $post_id    ID posta
 * @param string $context    Kontekst (do nazwy pliku)
 * @return int|WP_Error ID załącznika lub błąd
 */
function aai_download_stock_photo( $photo_data, $post_id, $context = '' ) {
    $download_url = $photo_data['download_url'];
    
    if ( empty( $download_url ) ) {
        return new WP_Error( 'no_url', __( 'Brak URL do pobrania.', 'agencyjnie-ai-images' ) );
    }
    
    // Użyj opisu ze źródła lub kontekstu
    $description = ! empty( $photo_data['description'] ) ? $photo_data['description'] : $context;
    
    // Przygotuj metadane dla unified saver
    $post = get_post( $post_id );
    $title = $post ? $post->post_title : 'Stock Photo';
    
    // Jeśli mamy opis zdjęcia, użyj go jako części tytułu dla SEO
    if ( ! empty( $description ) && mb_strlen( $description ) < 50 ) {
        $title = $description;
    }
    
    $meta = array(
        'title'       => $title,
        'alt'         => ! empty( $description ) ? $description : $title,
        'caption'     => $photo_data['attribution'],
        'description' => $photo_data['attribution'],
        'source'      => $photo_data['source'],
        'source_url'  => $photo_data['photo_url'],
        'context'     => 'stock',
        'author'      => $photo_data['author'],
    );
    
    // Zapisz używając unified saver
    $attachment_id = aai_save_remote_image( $download_url, $post_id, $meta );
    
    if ( is_wp_error( $attachment_id ) ) {
        return $attachment_id;
    }
    
    // Dodatkowe metadane specyficzne dla stocków
    update_post_meta( $attachment_id, '_aai_attribution', $photo_data['attribution'] );
    update_post_meta( $attachment_id, '_aai_author', $photo_data['author'] );
    update_post_meta( $attachment_id, '_aai_author_url', $photo_data['author_url'] );
    
    return $attachment_id;
}

/**
 * Tłumaczy słowa kluczowe na angielski dla lepszych wyników wyszukiwania
 * Rozbudowany słownik dla branży biznesowej, marketingowej i SEO
 */
function aai_translate_keywords_for_stock( $keywords ) {
    // Rozbudowane tłumaczenia polskich słów na angielskie
    $translations = array(
        // Podstawowe biznesowe
        'marketing'      => 'marketing',
        'biznes'         => 'business',
        'firma'          => 'company',
        'przedsiębiorstwo' => 'enterprise',
        'korporacja'     => 'corporation',
        'organizacja'    => 'organization',
        
        // Technologia i narzędzia
        'technologia'    => 'technology',
        'komputer'       => 'computer',
        'laptop'         => 'laptop',
        'smartfon'       => 'smartphone',
        'telefon'        => 'phone',
        'aplikacja'      => 'application',
        'oprogramowanie' => 'software',
        'narzędzie'      => 'tool',
        'narzędzia'      => 'tools',
        'platforma'      => 'platform',
        'system'         => 'system',
        
        // Praca i biuro
        'biuro'          => 'office',
        'praca'          => 'work',
        'pracownik'      => 'employee',
        'pracownicy'     => 'employees',
        'stanowisko'     => 'workplace',
        'home office'    => 'home office',
        'zdalna'         => 'remote work',
        'zdalnie'        => 'remote work',
        
        // Zespół i ludzie
        'zespół'         => 'team',
        'zespol'         => 'team',
        'budowanie'      => 'building',
        'budowa'         => 'building',
        'grupa'          => 'group',
        'współpraca'     => 'collaboration',
        'wspolpraca'     => 'collaboration',
        'partner'        => 'partner',
        'partnerstwo'    => 'partnership',
        'lider'          => 'leader',
        'kierownik'      => 'manager',
        'menedżer'       => 'manager',
        'dyrektor'       => 'director',
        'prezes'         => 'ceo',
        'właściciel'     => 'owner',
        'założyciel'     => 'founder',
        'rekrutacja'     => 'recruitment',
        'zatrudnienie'   => 'hiring',
        
        // Spotkania i komunikacja
        'spotkanie'      => 'meeting',
        'konferencja'    => 'conference',
        'prezentacja'    => 'presentation',
        'rozmowa'        => 'conversation',
        'negocjacje'     => 'negotiation',
        'komunikacja'    => 'communication',
        'feedback'       => 'feedback',
        
        // Sukces i rozwój
        'sukces'         => 'success',
        'wzrost'         => 'growth',
        'rozwój'         => 'development',
        'postęp'         => 'progress',
        'osiągnięcie'    => 'achievement',
        'cel'            => 'goal',
        'cele'           => 'goals',
        'misja'          => 'mission',
        'wizja'          => 'vision',
        'skalowanie'     => 'scaling',
        
        // Analiza i dane
        'analiza'        => 'analysis',
        'dane'           => 'data',
        'wykres'         => 'chart',
        'raport'         => 'report',
        'statystyki'     => 'statistics',
        'metryki'        => 'metrics',
        'wskaźniki'      => 'indicators',
        'kpi'            => 'kpi',
        'dashboard'      => 'dashboard',
        
        // Strategia i planowanie
        'strategia'      => 'strategy',
        'plan'           => 'plan',
        'planowanie'     => 'planning',
        'budżet'         => 'budget',
        'prognoza'       => 'forecast',
        'roadmapa'       => 'roadmap',
        
        // Klienci i sprzedaż
        'klient'         => 'client',
        'klienci'        => 'clients',
        'klientów'       => 'customers',
        'sprzedaż'       => 'sales',
        'przychód'       => 'revenue',
        'zysk'           => 'profit',
        'oferta'         => 'offer',
        'usługa'         => 'service',
        'usługi'         => 'services',
        'produkt'        => 'product',
        'cena'           => 'price',
        'cennik'         => 'pricing',
        'pakiet'         => 'package',
        'abonament'      => 'subscription',
        
        // Finanse
        'pieniądze'      => 'money',
        'inwestycja'     => 'investment',
        'kapitał'        => 'capital',
        'finansowanie'   => 'funding',
        'faktura'        => 'invoice',
        'płatność'       => 'payment',
        'rozliczenie'    => 'billing',
        
        // Startup i innowacja
        'startup'        => 'startup',
        'innowacja'      => 'innovation',
        'pomysł'         => 'idea',
        'kreatywność'    => 'creativity',
        'twórczość'      => 'creativity',
        
        // Design i projekty
        'design'         => 'design',
        'projekt'        => 'project',
        'projektowanie'  => 'designing',
        'grafika'        => 'graphics',
        'ui'             => 'ui design',
        'ux'             => 'ux design',
        'branding'       => 'branding',
        'logo'           => 'logo',
        'marka'          => 'brand',
        
        // Edukacja i wiedza
        'edukacja'       => 'education',
        'nauka'          => 'learning',
        'szkolenie'      => 'training',
        'kurs'           => 'course',
        'wiedza'         => 'knowledge',
        'umiejętności'   => 'skills',
        'doświadczenie'  => 'experience',
        'kompetencje'    => 'competencies',
        'certyfikat'     => 'certificate',
        'fundamenty'     => 'fundamentals',
        'podstawy'       => 'basics',
        
        // SEO i marketing cyfrowy
        'seo'            => 'seo',
        'pozycjonowanie' => 'seo optimization',
        'wyszukiwarka'   => 'search engine',
        'google'         => 'google',
        'ranking'        => 'ranking',
        'pozycja'        => 'position',
        'słowa kluczowe' => 'keywords',
        'content'        => 'content',
        'treść'          => 'content',
        'copywriting'    => 'copywriting',
        'link'           => 'link',
        'linki'          => 'links',
        'backlink'       => 'backlink',
        'audyt'          => 'audit',
        'optymalizacja'  => 'optimization',
        
        // Content marketing
        'blog'           => 'blog',
        'artykuł'        => 'article',
        'wpis'           => 'post',
        'publikacja'     => 'publication',
        'newsletter'     => 'newsletter',
        'social media'   => 'social media',
        'facebook'       => 'facebook',
        'instagram'      => 'instagram',
        'linkedin'       => 'linkedin',
        'twitter'        => 'twitter',
        
        // Procesy i zarządzanie
        'proces'         => 'process',
        'procedura'      => 'procedure',
        'workflow'       => 'workflow',
        'automatyzacja'  => 'automation',
        'zarządzanie'    => 'management',
        'organizowanie'  => 'organizing',
        'dokumentacja'   => 'documentation',
        'checklist'      => 'checklist',
        
        // Agencja
        'agencja'        => 'agency',
        'agencji'        => 'agency',
        'freelancer'     => 'freelancer',
        'konsultant'     => 'consultant',
        'ekspert'        => 'expert',
        'specjalista'    => 'specialist',
        
        // Umowy i prawo
        'umowa'          => 'contract',
        'kontrakt'       => 'contract',
        'warunki'        => 'terms',
        'regulamin'      => 'regulations',
        'prawny'         => 'legal',
        
        // Networking
        'networking'     => 'networking',
        'kontakty'       => 'contacts',
        'relacje'        => 'relationships',
        'polecenie'      => 'referral',
        'rekomendacja'   => 'recommendation',
        'referencje'     => 'references',
        'portfolio'      => 'portfolio',
        'case study'     => 'case study',
    );
    
    $translated = array();
    
    foreach ( $keywords as $keyword ) {
        $keyword_lower = mb_strtolower( $keyword );
        
        if ( isset( $translations[ $keyword_lower ] ) ) {
            $translated[] = $translations[ $keyword_lower ];
        } else {
            // Zachowaj oryginalne słowo
            $translated[] = $keyword;
        }
    }
    
    return $translated;
}
