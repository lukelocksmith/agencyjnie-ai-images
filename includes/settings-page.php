<?php
/**
 * Strona ustawień wtyczki AI Images
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rejestracja strony ustawień w menu
 */
function aai_register_settings_page() {
    add_options_page(
        __( 'AI Images', 'agencyjnie-ai-images' ),
        __( 'AI Images', 'agencyjnie-ai-images' ),
        'manage_options',
        'agencyjnie-ai-images',
        'aai_render_settings_page'
    );
}
add_action( 'admin_menu', 'aai_register_settings_page' );

/**
 * Rejestracja ustawień
 */
function aai_register_settings() {
    register_setting( 'aai_settings_group', 'aai_options', 'aai_sanitize_options' );
    
    // Sekcja: API
    add_settings_section(
        'aai_api_section',
        __( 'Ustawienia API', 'agencyjnie-ai-images' ),
        'aai_api_section_callback',
        'agencyjnie-ai-images'
    );
    
    add_settings_field(
        'api_key',
        __( 'Klucz API Gemini', 'agencyjnie-ai-images' ),
        'aai_render_api_key_field',
        'agencyjnie-ai-images',
        'aai_api_section'
    );
    
    add_settings_field(
        'openai_api_key',
        __( 'Klucz API OpenAI', 'agencyjnie-ai-images' ),
        'aai_render_openai_api_key_field',
        'agencyjnie-ai-images',
        'aai_api_section'
    );
    
    add_settings_field(
        'ai_model',
        __( 'Model AI do generowania', 'agencyjnie-ai-images' ),
        'aai_render_ai_model_field',
        'agencyjnie-ai-images',
        'aai_api_section'
    );
    
    add_settings_field(
        'dalle_quality',
        __( 'Jakość DALL-E 3', 'agencyjnie-ai-images' ),
        'aai_render_dalle_quality_field',
        'agencyjnie-ai-images',
        'aai_api_section'
    );
    
    // Sekcja: Styl obrazków
    add_settings_section(
        'aai_style_section',
        __( 'Styl obrazków', 'agencyjnie-ai-images' ),
        'aai_style_section_callback',
        'agencyjnie-ai-images'
    );
    
    add_settings_field(
        'base_prompt',
        __( 'Prompt bazowy', 'agencyjnie-ai-images' ),
        'aai_render_base_prompt_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );
    
    add_settings_field(
        'negative_prompt',
        __( 'Negative Prompt (Czego unikać)', 'agencyjnie-ai-images' ),
        'aai_render_negative_prompt_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );
    
    add_settings_field(
        'style',
        __( 'Styl artystyczny', 'agencyjnie-ai-images' ),
        'aai_render_style_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );
    
    add_settings_field(
        'colors',
        __( 'Dominujące kolory', 'agencyjnie-ai-images' ),
        'aai_render_colors_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );

    add_settings_field(
        'reference_images',
        __( 'Obrazki referencyjne', 'agencyjnie-ai-images' ),
        'aai_render_reference_images_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );
    
    add_settings_field(
        'aspect_ratio',
        __( 'Proporcje obrazka', 'agencyjnie-ai-images' ),
        'aai_render_aspect_ratio_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );
    
    add_settings_field(
        'image_language',
        __( 'Język treści na obrazkach', 'agencyjnie-ai-images' ),
        'aai_render_image_language_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );

    add_settings_field(
        'webp_conversion',
        __( 'Konwersja do WebP', 'agencyjnie-ai-images' ),
        'aai_render_webp_conversion_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );

    add_settings_field(
        'watermark',
        __( 'Watermark / Logo', 'agencyjnie-ai-images' ),
        'aai_render_watermark_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );

    add_settings_field(
        'aai_social_variants',
        __( 'Warianty social media', 'agencyjnie-ai-images' ),
        'aai_render_social_variants_field',
        'agencyjnie-ai-images',
        'aai_style_section'
    );

    // Sekcja: SEO
    add_settings_section(
        'aai_seo_section',
        __( 'SEO Obrazków', 'agencyjnie-ai-images' ),
        'aai_seo_section_callback',
        'agencyjnie-ai-images'
    );
    
    add_settings_field(
        'auto_generate_alt',
        __( 'Automatyczne opisy ALT', 'agencyjnie-ai-images' ),
        'aai_render_auto_generate_alt_field',
        'agencyjnie-ai-images',
        'aai_seo_section'
    );

    // Sekcja: Automatyzacja
    add_settings_section(
        'aai_automation_section',
        __( 'Automatyzacja', 'agencyjnie-ai-images' ),
        'aai_automation_section_callback',
        'agencyjnie-ai-images'
    );
    
    add_settings_field(
        'auto_generate',
        __( 'Auto-generowanie', 'agencyjnie-ai-images' ),
        'aai_render_auto_generate_field',
        'agencyjnie-ai-images',
        'aai_automation_section'
    );

    add_settings_field(
        'post_types',
        __( 'Obsługiwane typy postów', 'agencyjnie-ai-images' ),
        'aai_render_post_types_field',
        'agencyjnie-ai-images',
        'aai_automation_section'
    );

    // Sekcja: Szablony promptów
    add_settings_section(
        'aai_templates_section',
        __( 'Szablony promptów', 'agencyjnie-ai-images' ),
        'aai_templates_section_callback',
        'agencyjnie-ai-images'
    );

    add_settings_field(
        'prompt_templates',
        __( 'Szablony', 'agencyjnie-ai-images' ),
        'aai_render_prompt_templates_field',
        'agencyjnie-ai-images',
        'aai_templates_section'
    );

}
add_action( 'admin_init', 'aai_register_settings' );

/**
 * Callback dla sekcji API
 */
function aai_api_section_callback() {
    echo '<p>' . esc_html__( 'Skonfiguruj połączenie z Google Gemini API.', 'agencyjnie-ai-images' ) . '</p>';
    echo '<p><a href="https://aistudio.google.com/app/apikey" target="_blank">' . esc_html__( 'Pobierz klucz API z Google AI Studio', 'agencyjnie-ai-images' ) . '</a></p>';
}

/**
 * Callback dla sekcji stylu
 */
function aai_style_section_callback() {
    echo '<p>' . esc_html__( 'Określ domyślny styl generowanych obrazków.', 'agencyjnie-ai-images' ) . '</p>';
}

/**
 * Callback dla sekcji automatyzacji
 */
function aai_automation_section_callback() {
    echo '<p>' . esc_html__( 'Ustawienia automatycznego generowania obrazków.', 'agencyjnie-ai-images' ) . '</p>';
}

/**
 * Pole: Klucz API
 */
function aai_render_api_key_field() {
    $api_key = aai_get_secure_option( 'api_key' );
    // Maskuj klucz
    $display_key = ! empty( $api_key ) ? str_repeat( '*', 20 ) : '';
    ?>
    <div class="aai-api-key-row">
        <input 
            type="password" 
            id="aai_api_key" 
            name="aai_options[api_key]" 
            value="<?php echo esc_attr( $display_key ); ?>" 
            class="regular-text"
            autocomplete="off"
            placeholder="<?php echo ! empty( $api_key ) ? esc_attr__( 'Zmień klucz...', 'agencyjnie-ai-images' ) : ''; ?>"
        />
        <button type="button" class="button aai-toggle-password" data-target="aai_api_key">
            <?php esc_html_e( 'Pokaż/Ukryj', 'agencyjnie-ai-images' ); ?>
        </button>
        <button type="button" id="aai_test_connection" class="button button-secondary aai-test-btn">
            <?php esc_html_e( 'Testuj połączenie', 'agencyjnie-ai-images' ); ?>
        </button>
        <span id="aai_test_result" class="aai-test-result"></span>
    </div>
    <p class="description">
        <?php esc_html_e( 'Wprowadź klucz API z Google AI Studio i przetestuj połączenie.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Klucz API OpenAI
 */
function aai_render_openai_api_key_field() {
    $api_key = aai_get_secure_option( 'openai_api_key', '' );
    $display_key = ! empty( $api_key ) ? str_repeat( '*', 20 ) : '';
    ?>
    <div class="aai-api-key-row">
        <input 
            type="password" 
            id="aai_openai_api_key" 
            name="aai_options[openai_api_key]" 
            value="<?php echo esc_attr( $display_key ); ?>" 
            class="regular-text"
            autocomplete="off"
            placeholder="<?php echo ! empty( $api_key ) ? esc_attr__( 'Zmień klucz...', 'agencyjnie-ai-images' ) : esc_attr__( 'sk-...', 'agencyjnie-ai-images' ); ?>"
        />
        <button type="button" class="button aai-toggle-password" data-target="aai_openai_api_key">
            <?php esc_html_e( 'Pokaż/Ukryj', 'agencyjnie-ai-images' ); ?>
        </button>
        <button type="button" id="aai_test_openai_connection" class="button button-secondary aai-test-btn">
            <?php esc_html_e( 'Testuj', 'agencyjnie-ai-images' ); ?>
        </button>
        <span id="aai_test_openai_result" class="aai-test-result"></span>
    </div>
    <p class="description">
        <?php 
        printf(
            esc_html__( 'Wymagany dla DALL-E 3. %s', 'agencyjnie-ai-images' ),
            '<a href="https://platform.openai.com/api-keys" target="_blank">' . esc_html__( 'Pobierz klucz API', 'agencyjnie-ai-images' ) . '</a>'
        );
        ?>
    </p>
    <?php
}

/**
 * Pole: Wybór modelu AI
 */
function aai_render_ai_model_field() {
    $model = aai_get_option( 'ai_model', 'gemini' );
    ?>
    <select id="aai_ai_model" name="aai_options[ai_model]">
        <optgroup label="Google Gemini">
            <option value="gemini" <?php selected( $model, 'gemini' ); ?>>
                <?php esc_html_e( 'Gemini 2.5 Flash Image — szybki, tani', 'agencyjnie-ai-images' ); ?>
            </option>
            <option value="gemini-pro" <?php selected( $model, 'gemini-pro' ); ?>>
                <?php esc_html_e( 'Gemini 3 Pro — najwyższa jakość ✨', 'agencyjnie-ai-images' ); ?>
            </option>
        </optgroup>
        <optgroup label="OpenAI">
            <option value="dalle3" <?php selected( $model, 'dalle3' ); ?>>
                <?php esc_html_e( 'DALL-E 3 — dobry tekst na obrazkach', 'agencyjnie-ai-images' ); ?>
            </option>
        </optgroup>
    </select>
    <p class="description">
        <?php esc_html_e( 'Flash: ~$0.01/obr, szybki. Gemini 3 Pro: najlepsza jakość (preview). DALL-E 3: ~$0.04–0.12/obr, lepszy tekst.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Jakość DALL-E 3
 */
function aai_render_dalle_quality_field() {
    $quality = aai_get_option( 'dalle_quality', 'standard' );
    ?>
    <select id="aai_dalle_quality" name="aai_options[dalle_quality]">
        <option value="standard" <?php selected( $quality, 'standard' ); ?>>
            <?php esc_html_e( 'Standard (szybszy, tańszy)', 'agencyjnie-ai-images' ); ?>
        </option>
        <option value="hd" <?php selected( $quality, 'hd' ); ?>>
            <?php esc_html_e( 'HD (lepsza jakość, droższy)', 'agencyjnie-ai-images' ); ?>
        </option>
    </select>
    <p class="description">
        <?php esc_html_e( 'Dotyczy tylko DALL-E 3. HD ma więcej detali ale kosztuje ~50% więcej.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Prompt bazowy
 */
function aai_render_base_prompt_field() {
    $base_prompt = aai_get_option( 'base_prompt', 'Professional, modern, high-quality image suitable for a blog article.' );
    ?>
    <textarea 
        id="aai_base_prompt" 
        name="aai_options[base_prompt]" 
        rows="4" 
        class="large-text"
    ><?php echo esc_textarea( $base_prompt ); ?></textarea>
    <p class="description">
        <?php esc_html_e( 'Ogólny opis stylu obrazków. Ten prompt będzie łączony z tytułem i wstępem artykułu.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Negative Prompt
 */
function aai_render_negative_prompt_field() {
    $negative_prompt = aai_get_option( 'negative_prompt', 'text, watermark, blurry, distorted, ugly, bad anatomy, extra limbs' );
    ?>
    <textarea 
        id="aai_negative_prompt" 
        name="aai_options[negative_prompt]" 
        rows="2" 
        class="large-text"
    ><?php echo esc_textarea( $negative_prompt ); ?></textarea>
    <p class="description">
        <?php esc_html_e( 'Lista rzeczy, których AI ma unikać na obrazkach (rozdzielone przecinkami).', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Styl artystyczny
 */
function aai_render_style_field() {
    $style = aai_get_option( 'style', 'photorealistic' );
    $custom_style = aai_get_option( 'custom_style', '' );
    
    $styles = array(
        'photorealistic'   => 'Photorealistic (Cinematic, 8k, detailed)',
        'digital_art'      => 'Digital Art (Modern, vibrant, clean lines)',
        'isometric'        => 'Isometric 3D (Blender style, cute, soft lighting)',
        'minimalist'       => 'Minimalist (Flat design, simple shapes, pastel)',
        'cyberpunk'        => 'Cyberpunk (Neon, futuristic, dark atmosphere)',
        'watercolor'       => 'Watercolor (Artistic, soft edges, paper texture)',
        'sketch'           => 'Sketch (Pencil drawing, black and white)',
        'pop_art'          => 'Pop Art (Bold colors, comic style)',
        'abstract'         => 'Abstract (Geometric shapes, vibrant gradients)',
        'flat_illustration' => 'Flat Illustration (Vector, Dribbble style)',
        '3d_render'        => '3D Render (Glossy, studio lighting, octane)',
        'retro_vintage'    => 'Retro / Vintage (Faded colors, film grain)',
        'neon_glow'        => 'Neon Glow (Luminous on dark, electric)',
        'paper_cut'        => 'Paper Cut (Layered paper, shadows, depth)',
        'pixel_art'        => 'Pixel Art (Retro 16-bit, gaming)',
        'line_art'         => 'Line Art (Elegant lines, minimal, luxury)',
        'gradient_mesh'    => 'Gradient Mesh (Aurora gradients, startup)',
        'collage'          => 'Collage (Mixed media, editorial, zine)',
        'custom'           => __( 'Własny styl', 'agencyjnie-ai-images' ),
    );
    ?>
    <?php
    // Build style descriptions for JS preview
    $style_descriptions = aai_get_all_style_descriptions();

    // Visual preview data: CSS gradient + user-friendly Polish description
    $style_previews = array(
        'photorealistic'    => array(
            'gradient' => 'linear-gradient(135deg, #2c3e50 0%, #4ca1af 50%, #c4e0e5 100%)',
            'desc'     => 'Realistyczne zdjęcie jak z aparatu. Ostre detale, naturalne kolory, kinowe oświetlenie. Idealne do artykułów, które potrzebują autentycznego wyglądu.',
        ),
        'digital_art'      => array(
            'gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%)',
            'desc'     => 'Nowoczesna grafika cyfrowa z czystymi liniami i żywymi kolorami. Styl popularny na platformach kreatywnych — przyciągający, kolorowy i profesjonalny.',
        ),
        'isometric'        => array(
            'gradient' => 'linear-gradient(135deg, #a8edea 0%, #fed6e3 50%, #d299c2 100%)',
            'desc'     => 'Trójwymiarowe obiekty widziane pod kątem izometrycznym (jak w grach strategicznych). Czytelne, przyjemne dla oka, świetne do wizualizacji procesów i technologii.',
        ),
        'minimalist'       => array(
            'gradient' => 'linear-gradient(135deg, #ffecd2 0%, #fcb69f 30%, #f8f9fa 100%)',
            'desc'     => 'Prosty, spokojny design z dużą ilością białej przestrzeni. Pastelowe kolory, geometryczne kształty. Elegancki i czytelny — idealny do tematów lifestyle i biznes.',
        ),
        'cyberpunk'        => array(
            'gradient' => 'linear-gradient(135deg, #0c0c1d 0%, #1a0533 30%, #ff00ff 60%, #00ffff 100%)',
            'desc'     => 'Mroczna, futurystyczna atmosfera z neonowymi akcentami. Kolory magenty i cyjanu na ciemnym tle. Świetny do tematów technologicznych i futurystycznych.',
        ),
        'watercolor'       => array(
            'gradient' => 'linear-gradient(135deg, #e8d5b7 0%, #f5e6cc 30%, #b8d4e3 60%, #d4e2d4 100%)',
            'desc'     => 'Miękki, artystyczny styl imitujący akwarele na papierze. Delikatne przejścia kolorów, rozmyte krawędzie. Ciepły i kreatywny — idealny do tematów artystycznych.',
        ),
        'sketch'           => array(
            'gradient' => 'linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 40%, #d0d0d0 70%, #f0f0f0 100%)',
            'desc'     => 'Czarno-biały rysunek ołówkiem z widocznymi kreskami i cieniowaniem. Klasyczny, elegancki styl — świetny do artykułów edukacyjnych i koncepcyjnych.',
        ),
        'pop_art'          => array(
            'gradient' => 'linear-gradient(135deg, #ff6b6b 0%, #ffd93d 25%, #6bcb77 50%, #4d96ff 75%, #ff6b6b 100%)',
            'desc'     => 'Odważne, nasycone kolory w stylu Andy\'ego Warhola i komiksów. Mocne kontury, płaskie plamy kolorów. Energetyczny i przykuwający uwagę.',
        ),
        'abstract'         => array(
            'gradient' => 'linear-gradient(135deg, #ff9a9e 0%, #fad0c4 25%, #a18cd1 50%, #fbc2eb 75%, #ff9a9e 100%)',
            'desc'     => 'Geometryczne kształty, żywe gradienty i abstrakcyjne formy. Nowoczesny, artystyczny styl — idealny gdy nie potrzebujesz konkretnego obiektu, a raczej nastroju.',
        ),
        'flat_illustration' => array(
            'gradient' => 'linear-gradient(135deg, #43e97b 0%, #38f9d7 40%, #4facfe 100%)',
            'desc'     => 'Czyste ilustracje wektorowe bez cieni i perspektywy. Styl znany z Dribbble i nowoczesnych aplikacji. Profesjonalny i czytelny na każdym urządzeniu.',
        ),
        '3d_render'        => array(
            'gradient' => 'linear-gradient(135deg, #434343 0%, #000000 40%, #667eea 70%, #c4b5fd 100%)',
            'desc'     => 'Błyszczące obiekty 3D z efektownym oświetleniem studyjnym. Plastikowy, lśniący wygląd znany z reklam produktów. Profesjonalny i premium.',
        ),
        'retro_vintage'    => array(
            'gradient' => 'linear-gradient(135deg, #d4a574 0%, #c19a6b 30%, #8b7355 60%, #e8d5b0 100%)',
            'desc'     => 'Wyblakłe kolory, ziarno filmu i ciepła tonacja jak ze starych zdjęć. Nostalgiczny klimat lat 60-80. Idealny do tematów historycznych i kulturalnych.',
        ),
        'neon_glow'        => array(
            'gradient' => 'linear-gradient(135deg, #0a0a2e 0%, #1a0a3e 30%, #ff0080 50%, #7928ca 70%, #0a0a2e 100%)',
            'desc'     => 'Świecące neony i efekty świetlne na ciemnym tle. Atmosfera nocnego miasta, klubu lub cyberprzestrzeni. Dynamiczny i przyciągający wzrok.',
        ),
        'paper_cut'        => array(
            'gradient' => 'linear-gradient(135deg, #fff1eb 0%, #ace0f9 30%, #fff1eb 50%, #f5c6aa 80%, #fff1eb 100%)',
            'desc'     => 'Efekt warstwowego papieru z wyraźnymi cieniami i głębią. Delikatny, rzemieślniczy wygląd jak papierowa wycinanka. Kreatywny i niepowtarzalny.',
        ),
        'pixel_art'        => array(
            'gradient' => 'linear-gradient(135deg, #1a1a2e 0%, #16213e 30%, #e94560 50%, #0f3460 100%)',
            'desc'     => 'Pikselowa grafika rodem z gier retro 16-bitowych. Nostalgiczny styl znany z klasycznych gier. Świetny do tematów gamingowych i tech.',
        ),
        'line_art'         => array(
            'gradient' => 'linear-gradient(135deg, #fafafa 0%, #f0f0f0 30%, #333333 32%, #333333 34%, #f0f0f0 36%, #fafafa 100%)',
            'desc'     => 'Eleganckie, minimalistyczne rysunki składające się z cienkich linii. Luksusowy, delikatny styl — idealny do tematów premium, mody i designu.',
        ),
        'gradient_mesh'    => array(
            'gradient' => 'linear-gradient(135deg, #a855f7 0%, #ec4899 25%, #f97316 50%, #eab308 75%, #22c55e 100%)',
            'desc'     => 'Płynne, kolorowe gradienty jak aurora borealis. Styl popularny w brandingu startupów i firm technologicznych. Nowoczesny i profesjonalny.',
        ),
        'collage'          => array(
            'gradient' => 'linear-gradient(135deg, #f0e68c 0%, #dda0dd 25%, #98fb98 50%, #f08080 75%, #87ceeb 100%)',
            'desc'     => 'Mix mediów: wycinki, tekstury, kolaż elementów jak z zina lub magazynu. Artystyczny, niekonwencjonalny styl dla odważnych treści editorial.',
        ),
    );
    ?>
    <?php
    // Check which preview images already exist
    $previews_dir = AAI_PLUGIN_DIR . 'assets/style-previews/';
    $previews_url = AAI_PLUGIN_URL . 'assets/style-previews/';
    $existing_previews = array();
    foreach ( array_keys( $style_previews ) as $skey ) {
        $webp_path = $previews_dir . $skey . '.webp';
        if ( file_exists( $webp_path ) ) {
            $existing_previews[ $skey ] = $previews_url . $skey . '.webp?v=' . filemtime( $webp_path );
        }
    }
    ?>
    <select id="aai_style" name="aai_options[style]" class="aai-style-select"
        data-style-descriptions="<?php echo esc_attr( wp_json_encode( $style_descriptions ) ); ?>"
        data-style-previews="<?php echo esc_attr( wp_json_encode( $style_previews ) ); ?>"
        data-style-images="<?php echo esc_attr( wp_json_encode( $existing_previews ) ); ?>">
        <?php foreach ( $styles as $value => $label ) : ?>
            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $style, $value ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div id="aai-style-preview" class="aai-style-preview-box" <?php echo ( $style === 'custom' || empty( $style ) ) ? 'style="display:none;"' : ''; ?>>
        <?php
        if ( isset( $style_previews[ $style ] ) ) :
            $sp = $style_previews[ $style ];
            $has_image = isset( $existing_previews[ $style ] );
        ?>
            <div class="aai-style-preview-card">
                <?php if ( $has_image ) : ?>
                    <img class="aai-style-preview-thumb" src="<?php echo esc_url( $existing_previews[ $style ] ); ?>" alt="<?php echo esc_attr( $styles[ $style ] ?? '' ); ?>" />
                <?php else : ?>
                    <div class="aai-style-preview-thumb" style="background: <?php echo esc_attr( $sp['gradient'] ); ?>;"></div>
                <?php endif; ?>
                <div class="aai-style-preview-info">
                    <strong class="aai-style-preview-name"><?php echo esc_html( $styles[ $style ] ?? '' ); ?></strong>
                    <p class="aai-style-preview-desc"><?php echo esc_html( $sp['desc'] ); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div id="aai_custom_style_wrapper" class="aai-custom-style-wrapper" style="<?php echo $style !== 'custom' ? 'display:none;' : ''; ?>">
        <input
            type="text"
            id="aai_custom_style"
            name="aai_options[custom_style]"
            value="<?php echo esc_attr( $custom_style ); ?>"
            class="regular-text"
            placeholder="<?php esc_attr_e( 'np. pixel art, retro 80s', 'agencyjnie-ai-images' ); ?>"
        />
    </div>

    <p class="description">
        <?php esc_html_e( 'Wybierz gotowy preset stylu lub zdefiniuj własny.', 'agencyjnie-ai-images' ); ?>
    </p>

    <?php
}

/**
 * Pole: Kolory dominujące
 */
function aai_render_colors_field() {
    $colors = aai_get_option( 'colors', array( '#2563eb', '#f59e0b', '#10b981' ) );
    
    // Upewnij się, że mamy tablicę
    if ( ! is_array( $colors ) ) {
        $colors = array( '#2563eb', '#f59e0b', '#10b981' );
    }
    ?>
    <div id="aai_colors_container" class="aai-colors-container">
        <?php foreach ( $colors as $index => $color ) : ?>
            <div class="aai-color-item">
                <input 
                    type="color" 
                    name="aai_options[colors][]" 
                    value="<?php echo esc_attr( $color ); ?>" 
                    class="aai-color-picker"
                />
                <button type="button" class="button aai-remove-color" title="<?php esc_attr_e( 'Usuń kolor', 'agencyjnie-ai-images' ); ?>">×</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="button aai-add-color">
        <?php esc_html_e( '+ Dodaj kolor', 'agencyjnie-ai-images' ); ?>
    </button>
    <p class="description">
        <?php esc_html_e( 'Wybierz dominujące kolory dla generowanych obrazków. Możesz dodać maksymalnie 5 kolorów.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Proporcje obrazka
 */
function aai_render_aspect_ratio_field() {
    $aspect_ratio = aai_get_option( 'aspect_ratio', '16:9' );
    
    $ratios = array(
        '16:9' => '16:9 (Panoramiczny)',
        '4:3'  => '4:3 (Standardowy)',
        '1:1'  => '1:1 (Kwadrat)',
        '3:4'  => '3:4 (Portret)',
        '9:16' => '9:16 (Pionowy)',
    );
    ?>
    <select id="aai_aspect_ratio" name="aai_options[aspect_ratio]">
        <?php foreach ( $ratios as $value => $label ) : ?>
            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $aspect_ratio, $value ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description">
        <?php esc_html_e( 'Wybierz proporcje generowanych obrazków.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Język treści na obrazkach
 */
function aai_render_image_language_field() {
    $language = aai_get_option( 'image_language', 'pl' );
    
    $languages = array(
        'pl'           => 'Polski',
        'en'           => 'English',
        'de'           => 'Deutsch',
        'fr'           => 'Français',
        'es'           => 'Español',
        'it'           => 'Italiano',
        'pt'           => 'Português',
        'nl'           => 'Nederlands',
        'cs'           => 'Čeština',
        'sk'           => 'Slovenčina',
        'uk'           => 'Українська',
        'ru'           => 'Русский',
        'numbers_only' => __( 'Tylko liczby (bez tekstu)', 'agencyjnie-ai-images' ),
        'none'         => __( 'Bez tekstu i liczb', 'agencyjnie-ai-images' ),
    );
    ?>
    <select id="aai_image_language" name="aai_options[image_language]">
        <?php foreach ( $languages as $code => $name ) : ?>
            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $language, $code ); ?>>
                <?php echo esc_html( $name ); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description">
        <?php esc_html_e( 'Jeśli AI wygeneruje tekst na obrazku, będzie w wybranym języku.', 'agencyjnie-ai-images' ); ?>
        <br>
        <?php esc_html_e( '"Tylko liczby" - pozwala na cyfry, procenty, statystyki, ale bez słów (idealne dla infografik).', 'agencyjnie-ai-images' ); ?>
        <br>
        <?php esc_html_e( '"Bez tekstu i liczb" - obrazek czysto wizualny, bez jakichkolwiek napisów.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Konwersja do WebP
 */
function aai_render_webp_conversion_field() {
    $enabled = aai_get_option( 'webp_conversion', false );
    $gd_support = function_exists( 'imagewebp' );
    $imagick_support = ( extension_loaded( 'imagick' ) && in_array( 'WEBP', \Imagick::queryFormats(), true ) );
    $supported = $gd_support || $imagick_support;
    ?>
    <label>
        <input
            type="checkbox"
            name="aai_options[webp_conversion]"
            value="1"
            <?php checked( $enabled, true ); ?>
            <?php disabled( ! $supported ); ?>
        />
        <?php esc_html_e( 'Konwertuj wygenerowane obrazki do formatu WebP (mniejszy rozmiar pliku)', 'agencyjnie-ai-images' ); ?>
    </label>
    <?php if ( ! $supported ) : ?>
        <p class="description" style="color: #dc3545;">
            <?php esc_html_e( 'Serwer nie obsługuje WebP. Wymagane rozszerzenie GD z obsługą WebP lub Imagick.', 'agencyjnie-ai-images' ); ?>
        </p>
    <?php else : ?>
        <p class="description">
            <?php
            $lib = $gd_support ? 'GD' : 'Imagick';
            printf(
                esc_html__( 'Używa biblioteki %s. WebP zazwyczaj zmniejsza rozmiar pliku o 25-35%% w porównaniu do PNG.', 'agencyjnie-ai-images' ),
                '<strong>' . esc_html( $lib ) . '</strong>'
            );
            ?>
        </p>
    <?php endif; ?>
    <?php
}

/**
 * Pole: Warianty social media
 */
function aai_render_social_variants_field() {
    $value = aai_get_option( 'social_variants', false );
    ?>
    <label>
        <input type="checkbox" name="aai_options[social_variants]" value="1" <?php checked( $value, 1 ); ?> />
        <?php esc_html_e( 'Automatycznie generuj warianty social media (OG 1200x630, Instagram 1080x1080, Pinterest 1000x1500)', 'agencyjnie-ai-images' ); ?>
    </label>
    <p class="description">
        <?php esc_html_e( 'Po wygenerowaniu featured image, automatycznie tworzy przycięte wersje dla social media.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Watermark / Logo
 */
function aai_render_watermark_field() {
    $enabled  = aai_get_option( 'watermark_enabled', false );
    $logo_url = aai_get_option( 'watermark_logo', '' );
    $position = aai_get_option( 'watermark_position', 'bottom-right' );
    $size     = aai_get_option( 'watermark_size', '10' );
    $opacity  = aai_get_option( 'watermark_opacity', '50' );
    ?>
    <label style="display: block; margin-bottom: 10px;">
        <input type="checkbox" name="aai_options[watermark_enabled]" value="1" <?php checked( $enabled, true ); ?> />
        <?php esc_html_e( 'Włącz watermark na generowanych obrazkach', 'agencyjnie-ai-images' ); ?>
    </label>

    <div class="aai-watermark-settings" style="<?php echo ! $enabled ? 'opacity:0.5;' : ''; ?>">
        <div style="margin-bottom: 10px;">
            <label><strong><?php esc_html_e( 'Logo / Watermark:', 'agencyjnie-ai-images' ); ?></strong></label><br>
            <div id="aai-watermark-preview" class="aai-watermark-preview">
                <?php if ( ! empty( $logo_url ) ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="Watermark" />
                <?php endif; ?>
            </div>
            <input type="hidden" id="aai_watermark_logo" name="aai_options[watermark_logo]" value="<?php echo esc_url( $logo_url ); ?>" />
            <button type="button" id="aai-upload-watermark" class="button button-secondary">
                <?php esc_html_e( 'Wybierz logo', 'agencyjnie-ai-images' ); ?>
            </button>
            <?php if ( ! empty( $logo_url ) ) : ?>
                <button type="button" id="aai-remove-watermark" class="button"><?php esc_html_e( 'Usuń', 'agencyjnie-ai-images' ); ?></button>
            <?php endif; ?>
        </div>

        <div style="margin-bottom: 10px;">
            <label><strong><?php esc_html_e( 'Pozycja:', 'agencyjnie-ai-images' ); ?></strong></label><br>
            <select name="aai_options[watermark_position]">
                <option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>><?php esc_html_e( 'Prawy dolny', 'agencyjnie-ai-images' ); ?></option>
                <option value="bottom-left" <?php selected( $position, 'bottom-left' ); ?>><?php esc_html_e( 'Lewy dolny', 'agencyjnie-ai-images' ); ?></option>
                <option value="top-right" <?php selected( $position, 'top-right' ); ?>><?php esc_html_e( 'Prawy górny', 'agencyjnie-ai-images' ); ?></option>
                <option value="top-left" <?php selected( $position, 'top-left' ); ?>><?php esc_html_e( 'Lewy górny', 'agencyjnie-ai-images' ); ?></option>
            </select>
        </div>

        <div style="margin-bottom: 10px;">
            <label><strong><?php esc_html_e( 'Rozmiar (% szerokości obrazka):', 'agencyjnie-ai-images' ); ?></strong></label><br>
            <select name="aai_options[watermark_size]">
                <option value="5" <?php selected( $size, '5' ); ?>><?php esc_html_e( 'Mały (5%)', 'agencyjnie-ai-images' ); ?></option>
                <option value="10" <?php selected( $size, '10' ); ?>><?php esc_html_e( 'Średni (10%)', 'agencyjnie-ai-images' ); ?></option>
                <option value="15" <?php selected( $size, '15' ); ?>><?php esc_html_e( 'Duży (15%)', 'agencyjnie-ai-images' ); ?></option>
            </select>
        </div>

        <div style="margin-bottom: 10px;">
            <label><strong><?php esc_html_e( 'Przezroczystość:', 'agencyjnie-ai-images' ); ?></strong>
                <span id="aai-opacity-value"><?php echo esc_html( $opacity ); ?>%</span>
            </label><br>
            <input type="range" name="aai_options[watermark_opacity]" min="10" max="100" step="5"
                value="<?php echo esc_attr( $opacity ); ?>"
                oninput="document.getElementById('aai-opacity-value').textContent = this.value + '%'" />
        </div>
    </div>

    <p class="description">
        <?php esc_html_e( 'Logo będzie nakładane na każdy wygenerowany obrazek. Wymaga biblioteki GD.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Callback dla sekcji SEO
 */
function aai_seo_section_callback() {
    echo '<p>' . esc_html__( 'Ustawienia optymalizacji SEO dla generowanych obrazków.', 'agencyjnie-ai-images' ) . '</p>';
}

/**
 * Pole: Automatyczne opisy ALT
 */
function aai_render_auto_generate_alt_field() {
    $enabled = aai_get_option( 'auto_generate_alt', false );
    ?>
    <label>
        <input 
            type="checkbox" 
            name="aai_options[auto_generate_alt]" 
            value="1" 
            <?php checked( $enabled, true ); ?>
        />
        <?php esc_html_e( 'Generuj inteligentne opisy ALT przy użyciu AI (tekstowe)', 'agencyjnie-ai-images' ); ?>
    </label>
    <p class="description">
        <?php esc_html_e( 'Tworzy opisy alternatywne na podstawie promptu i kontekstu artykułu. Poprawia dostępność i SEO.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Pole: Auto-generowanie
 */
function aai_render_auto_generate_field() {
    $auto_generate = aai_get_option( 'auto_generate', false );
    ?>
    <label>
        <input 
            type="checkbox" 
            id="aai_auto_generate" 
            name="aai_options[auto_generate]" 
            value="1" 
            <?php checked( $auto_generate, true ); ?>
        />
        <?php esc_html_e( 'Automatycznie generuj featured image przy publikacji posta (jeśli brak)', 'agencyjnie-ai-images' ); ?>
    </label>
    <p class="description">
        <?php esc_html_e( 'Gdy włączone, obrazek zostanie wygenerowany automatycznie przy pierwszej publikacji posta, który nie ma ustawionego featured image.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}


/**
 * Pole: Obsługiwane typy postów
 */
function aai_render_post_types_field() {
    $saved_types = aai_get_option( 'post_types', array( 'post' ) );
    if ( ! is_array( $saved_types ) ) {
        $saved_types = array( 'post' );
    }

    // Pobierz wszystkie publiczne typy postów
    $post_types = get_post_types( array( 'public' => true ), 'objects' );

    // Usuń 'attachment' - nie ma sensu generować obrazków dla załączników
    unset( $post_types['attachment'] );

    foreach ( $post_types as $post_type ) {
        $checked = in_array( $post_type->name, $saved_types, true );
        ?>
        <label style="display: block; margin-bottom: 6px;">
            <input
                type="checkbox"
                name="aai_options[post_types][]"
                value="<?php echo esc_attr( $post_type->name ); ?>"
                <?php checked( $checked ); ?>
            />
            <?php echo esc_html( $post_type->labels->singular_name ); ?>
            <code style="font-size: 11px; color: #888;">(<?php echo esc_html( $post_type->name ); ?>)</code>
        </label>
        <?php
    }
    ?>
    <p class="description">
        <?php esc_html_e( 'Wybierz typy postów, dla których będzie dostępne generowanie AI obrazków (meta box + auto-generowanie + bulk actions).', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Sanityzacja opcji
 */
function aai_sanitize_options( $input ) {
    $sanitized = array();
    
    // API Key - przechowuj jako tekst (w przyszłości można dodać szyfrowanie)
    if ( isset( $input['api_key'] ) ) {
        // Jeśli hasło nie zostało zmienione (jest zamaskowane), zachowaj stare
        if ( strpos( $input['api_key'], '***' ) !== false ) {
            $sanitized['api_key'] = aai_get_option( 'api_key' );
        } else {
            $sanitized['api_key'] = aai_encrypt( sanitize_text_field( $input['api_key'] ) );
        }
    }
    
    // OpenAI API Key
    if ( isset( $input['openai_api_key'] ) ) {
        if ( strpos( $input['openai_api_key'], '***' ) !== false ) {
            $sanitized['openai_api_key'] = aai_get_option( 'openai_api_key' );
        } else {
            $sanitized['openai_api_key'] = aai_encrypt( sanitize_text_field( $input['openai_api_key'] ) );
        }
    }
    
    $allowed_models = array( 'gemini', 'gemini-pro', 'imagen3', 'dalle3' );
    if ( isset( $input['ai_model'] ) && in_array( $input['ai_model'], $allowed_models, true ) ) {
        $sanitized['ai_model'] = $input['ai_model'];
    } else {
        $sanitized['ai_model'] = 'gemini';
    }
    
    // Jakość DALL-E 3
    $allowed_qualities = array( 'standard', 'hd' );
    if ( isset( $input['dalle_quality'] ) && in_array( $input['dalle_quality'], $allowed_qualities, true ) ) {
        $sanitized['dalle_quality'] = $input['dalle_quality'];
    } else {
        $sanitized['dalle_quality'] = 'standard';
    }
    
    // Prompt bazowy
    if ( isset( $input['base_prompt'] ) ) {
        $sanitized['base_prompt'] = sanitize_textarea_field( $input['base_prompt'] );
    }
    
    // Negative Prompt
    if ( isset( $input['negative_prompt'] ) ) {
        $sanitized['negative_prompt'] = sanitize_textarea_field( $input['negative_prompt'] );
    }
    
    // Styl
    $allowed_styles = array( 'photorealistic', 'digital_art', 'isometric', 'minimalist', 'cyberpunk', 'watercolor', 'sketch', 'pop_art', 'abstract', 'flat_illustration', '3d_render', 'retro_vintage', 'neon_glow', 'paper_cut', 'pixel_art', 'line_art', 'gradient_mesh', 'collage', 'custom' );
    if ( isset( $input['style'] ) && in_array( $input['style'], $allowed_styles, true ) ) {
        $sanitized['style'] = $input['style'];
    } else {
        $sanitized['style'] = 'photorealistic';
    }
    
    // Własny styl
    if ( isset( $input['custom_style'] ) ) {
        $sanitized['custom_style'] = sanitize_text_field( $input['custom_style'] );
    }
    
    // Kolory - tablica hex kolorów
    if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
        $sanitized['colors'] = array();
        foreach ( $input['colors'] as $color ) {
            // Walidacja formatu hex koloru
            if ( preg_match( '/^#[a-fA-F0-9]{6}$/', $color ) ) {
                $sanitized['colors'][] = $color;
            }
        }
        // Ogranicz do 5 kolorów
        $sanitized['colors'] = array_slice( $sanitized['colors'], 0, 5 );
    }
    
    // Proporcje
    $allowed_ratios = array( '16:9', '4:3', '1:1', '3:4', '9:16' );
    if ( isset( $input['aspect_ratio'] ) && in_array( $input['aspect_ratio'], $allowed_ratios, true ) ) {
        $sanitized['aspect_ratio'] = $input['aspect_ratio'];
    } else {
        $sanitized['aspect_ratio'] = '16:9';
    }
    
    // Język obrazków
    $allowed_languages = array( 'pl', 'en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'cs', 'sk', 'uk', 'ru', 'numbers_only', 'none' );
    if ( isset( $input['image_language'] ) && in_array( $input['image_language'], $allowed_languages, true ) ) {
        $sanitized['image_language'] = $input['image_language'];
    } else {
        $sanitized['image_language'] = 'pl';
    }
    
    // Auto-generowanie
    $sanitized['auto_generate'] = ! empty( $input['auto_generate'] );
    
    // Auto-generowanie ALT
    $sanitized['auto_generate_alt'] = ! empty( $input['auto_generate_alt'] );

    // Konwersja WebP
    $sanitized['webp_conversion'] = ! empty( $input['webp_conversion'] );

    // Social media variants
    $sanitized['social_variants'] = ! empty( $input['social_variants'] ) ? 1 : 0;

    // Watermark settings
    $sanitized['watermark_enabled'] = ! empty( $input['watermark_enabled'] );
    $sanitized['watermark_logo'] = isset( $input['watermark_logo'] ) ? esc_url_raw( $input['watermark_logo'] ) : '';

    $allowed_positions = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );
    $sanitized['watermark_position'] = isset( $input['watermark_position'] ) && in_array( $input['watermark_position'], $allowed_positions, true )
        ? $input['watermark_position'] : 'bottom-right';

    $allowed_sizes = array( '5', '10', '15' );
    $sanitized['watermark_size'] = isset( $input['watermark_size'] ) && in_array( $input['watermark_size'], $allowed_sizes, true )
        ? $input['watermark_size'] : '10';

    $opacity = isset( $input['watermark_opacity'] ) ? absint( $input['watermark_opacity'] ) : 50;
    $sanitized['watermark_opacity'] = max( 10, min( 100, $opacity ) );

    // Obsługiwane typy postów
    if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
        $allowed_post_types = array_keys( get_post_types( array( 'public' => true ) ) );
        $sanitized['post_types'] = array_filter( $input['post_types'], function( $type ) use ( $allowed_post_types ) {
            return in_array( $type, $allowed_post_types, true ) && $type !== 'attachment';
        });
        $sanitized['post_types'] = array_values( $sanitized['post_types'] );
    } else {
        $sanitized['post_types'] = array( 'post' ); // Default
    }

    // Szablony promptów
    if ( isset( $input['prompt_templates'] ) && is_array( $input['prompt_templates'] ) ) {
        $sanitized['prompt_templates'] = array();
        foreach ( $input['prompt_templates'] as $tpl ) {
            $name   = isset( $tpl['name'] ) ? sanitize_text_field( $tpl['name'] ) : '';
            $prompt = isset( $tpl['prompt'] ) ? sanitize_textarea_field( $tpl['prompt'] ) : '';
            if ( ! empty( $name ) && ! empty( $prompt ) ) {
                $sanitized['prompt_templates'][] = array(
                    'name'   => $name,
                    'prompt' => $prompt,
                );
            }
        }
        // Limit 20 templates
        $sanitized['prompt_templates'] = array_slice( $sanitized['prompt_templates'], 0, 20 );
    } else {
        // Preserve existing templates if not in form submission
        $sanitized['prompt_templates'] = aai_get_option( 'prompt_templates', array() );
    }

    // Obrazki referencyjne
    if ( isset( $input['reference_images'] ) && is_array( $input['reference_images'] ) ) {
        $sanitized['reference_images'] = array();
        foreach ( $input['reference_images'] as $img_url ) {
            $sanitized['reference_images'][] = esc_url_raw( $img_url );
        }
        // Limit 3
        $sanitized['reference_images'] = array_slice( $sanitized['reference_images'], 0, 3 );
    }
    
    return $sanitized;
}

/**
 * Renderowanie strony ustawień z zakładkami
 */
function aai_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
    $tabs = array(
        'settings'   => __( 'Ustawienia', 'agencyjnie-ai-images' ),
        'stats'      => __( 'Statystyki', 'agencyjnie-ai-images' ),
        'categories' => __( 'Kategorie', 'agencyjnie-ai-images' ),
        'generator'  => __( 'Generator', 'agencyjnie-ai-images' ),
        'queue'      => __( 'Kolejka', 'agencyjnie-ai-images' ),
    );

    if ( ! isset( $tabs[ $current_tab ] ) ) {
        $current_tab = 'settings';
    }
    ?>
    <div class="wrap aai-settings-wrap">
        <h1><?php esc_html_e( 'AI Images', 'agencyjnie-ai-images' ); ?></h1>

        <nav class="nav-tab-wrapper aai-nav-tabs">
            <?php foreach ( $tabs as $tab_key => $tab_label ) :
                $url = admin_url( 'options-general.php?page=agencyjnie-ai-images&tab=' . $tab_key );
                $active = ( $current_tab === $tab_key ) ? ' nav-tab-active' : '';
            ?>
                <a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo esc_attr( $active ); ?>">
                    <?php echo esc_html( $tab_label ); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="aai-tab-content">
            <?php
            switch ( $current_tab ) {
                case 'stats':
                    aai_render_stats_page();
                    break;
                case 'categories':
                    aai_render_category_styles_page();
                    break;
                case 'generator':
                    aai_render_generator_tab();
                    break;
                case 'queue':
                    aai_render_queue_tab();
                    break;
                default:
                    aai_render_settings_tab();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Renderowanie zakładki ustawień (formularz)
 */
function aai_render_settings_tab() {
    ?>
    <div class="aai-settings-header">
        <p><?php esc_html_e( 'Skonfiguruj automatyczne generowanie obrazków featured image przy użyciu AI (Gemini / DALL-E 3).', 'agencyjnie-ai-images' ); ?></p>
    </div>

    <form method="post" action="options.php">
        <?php
        settings_fields( 'aai_settings_group' );
        do_settings_sections( 'agencyjnie-ai-images' );
        submit_button( __( 'Zapisz ustawienia', 'agencyjnie-ai-images' ) );
        ?>
    </form>
    <?php
}
/**
 * Pole: Obrazki referencyjne
 */
function aai_render_reference_images_field() {
    $images = aai_get_option( 'reference_images', array() );
    if ( ! is_array( $images ) ) {
        $images = array();
    }
    
    // Limit to 3 images
    $images = array_slice( $images, 0, 3 );
    ?>
    <div id="aai_reference_images_container" class="aai-reference-images-container">
        <?php foreach ( $images as $img_url ) : ?>
            <div class="aai-reference-image-item">
                <input type="hidden" name="aai_options[reference_images][]" value="<?php echo esc_url( $img_url ); ?>" />
                <img src="<?php echo esc_url( $img_url ); ?>" alt="Reference" />
                <button type="button" class="button aai-remove-reference-image" title="<?php esc_attr_e( 'Usuń', 'agencyjnie-ai-images' ); ?>">×</button>
            </div>
        <?php endforeach; ?>
    </div>
    
    <button type="button" id="aai_add_reference_image" class="button button-secondary" <?php echo count( $images ) >= 3 ? 'disabled' : ''; ?>>
        <?php esc_html_e( 'Dodaj obrazek referencyjny', 'agencyjnie-ai-images' ); ?>
    </button>
    <p class="description">
        <?php esc_html_e( 'Wybierz do 3 obrazków, które posłużą jako wzór stylu dla generowanych grafik (tylko Gemini).', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Callback dla sekcji szablonów promptów
 */
function aai_templates_section_callback() {
    echo '<p>' . esc_html__( 'Zapisz często używane prompty jako szablony do szybkiego użycia w edytorze postów.', 'agencyjnie-ai-images' ) . '</p>';
}

/**
 * Pole: Szablony promptów
 */
function aai_render_prompt_templates_field() {
    $templates = aai_get_option( 'prompt_templates', array() );
    if ( ! is_array( $templates ) ) {
        $templates = array();
    }
    ?>
    <div id="aai-templates-list" class="aai-templates-list">
        <?php if ( ! empty( $templates ) ) : ?>
            <?php foreach ( $templates as $index => $template ) : ?>
                <div class="aai-template-row">
                    <input
                        type="text"
                        name="aai_options[prompt_templates][<?php echo esc_attr( $index ); ?>][name]"
                        value="<?php echo esc_attr( $template['name'] ); ?>"
                        placeholder="<?php esc_attr_e( 'Nazwa szablonu', 'agencyjnie-ai-images' ); ?>"
                        class="regular-text aai-template-name"
                    />
                    <textarea
                        name="aai_options[prompt_templates][<?php echo esc_attr( $index ); ?>][prompt]"
                        rows="2"
                        class="large-text aai-template-prompt"
                        placeholder="<?php esc_attr_e( 'Treść promptu...', 'agencyjnie-ai-images' ); ?>"
                    ><?php echo esc_textarea( $template['prompt'] ); ?></textarea>
                    <button type="button" class="button aai-remove-template" title="<?php esc_attr_e( 'Usuń', 'agencyjnie-ai-images' ); ?>">&times;</button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <button type="button" id="aai-add-template" class="button button-secondary">
        <?php esc_html_e( '+ Dodaj szablon', 'agencyjnie-ai-images' ); ?>
    </button>
    <p class="description">
        <?php esc_html_e( 'Szablony będą dostępne w edytorze postów jako szybki wybór w meta boxie AI Image Generator.', 'agencyjnie-ai-images' ); ?>
    </p>
    <?php
}

/**
 * Renderowanie zakładki Generator (standalone image generation)
 */
function aai_render_generator_tab() {
    $api_key    = aai_get_secure_option( 'api_key' );
    $openai_key = aai_get_secure_option( 'openai_api_key' );
    $has_key    = ! empty( $api_key ) || ! empty( $openai_key );

    if ( ! $has_key ) {
        echo '<div class="notice notice-warning"><p>';
        printf(
            esc_html__( 'Brak klucza API. %s', 'agencyjnie-ai-images' ),
            '<a href="' . esc_url( admin_url( 'options-general.php?page=agencyjnie-ai-images&tab=settings' ) ) . '">' .
            esc_html__( 'Skonfiguruj wtyczkę', 'agencyjnie-ai-images' ) . '</a>'
        );
        echo '</p></div>';
        return;
    }

    $styles = aai_get_all_style_descriptions();
    $ratios = array( '16:9' => '16:9', '4:3' => '4:3', '1:1' => '1:1', '3:4' => '3:4', '9:16' => '9:16' );
    $current_style = aai_get_option( 'style', 'photorealistic' );
    $current_ratio = aai_get_option( 'aspect_ratio', '16:9' );

    // Get recent standalone generations from transient
    $history = get_transient( 'aai_standalone_history_' . get_current_user_id() );
    if ( ! is_array( $history ) ) {
        $history = array();
    }
    ?>
    <div class="aai-generator-wrap">
        <div class="aai-generator-form">
            <div class="aai-generator-field">
                <label for="aai-gen-prompt"><strong><?php esc_html_e( 'Prompt:', 'agencyjnie-ai-images' ); ?></strong></label>
                <textarea id="aai-gen-prompt" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Opisz obrazek, który chcesz wygenerować...', 'agencyjnie-ai-images' ); ?>"></textarea>
            </div>

            <div class="aai-generator-options">
                <div class="aai-generator-field aai-generator-field-half">
                    <label for="aai-gen-style"><strong><?php esc_html_e( 'Styl:', 'agencyjnie-ai-images' ); ?></strong></label>
                    <select id="aai-gen-style">
                        <?php foreach ( $styles as $key => $desc ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_style, $key ); ?>>
                                <?php echo esc_html( $key ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="aai-generator-field aai-generator-field-half">
                    <label for="aai-gen-ratio"><strong><?php esc_html_e( 'Proporcje:', 'agencyjnie-ai-images' ); ?></strong></label>
                    <select id="aai-gen-ratio">
                        <?php foreach ( $ratios as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_ratio, $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="button" id="aai-gen-submit" class="button button-primary button-hero">
                <span class="aai-btn-text"><?php esc_html_e( 'Generuj obrazek', 'agencyjnie-ai-images' ); ?></span>
                <span class="aai-btn-spinner spinner" style="display: none;"></span>
            </button>
        </div>

        <div id="aai-gen-message" class="aai-message" style="display: none;"></div>

        <div id="aai-gen-result" class="aai-generator-result" style="display: none;">
            <img id="aai-gen-result-img" src="" alt="" />
            <div class="aai-generator-result-actions">
                <a id="aai-gen-download" href="#" download class="button"><?php esc_html_e( 'Pobierz', 'agencyjnie-ai-images' ); ?></a>
                <a id="aai-gen-media-link" href="#" target="_blank" class="button"><?php esc_html_e( 'Otwórz w Media Library', 'agencyjnie-ai-images' ); ?></a>
                <button type="button" id="aai-gen-another" class="button button-primary"><?php esc_html_e( 'Generuj kolejny', 'agencyjnie-ai-images' ); ?></button>
            </div>
        </div>

        <?php if ( ! empty( $history ) ) : ?>
            <div class="aai-generator-history">
                <h3><?php esc_html_e( 'Ostatnio wygenerowane:', 'agencyjnie-ai-images' ); ?></h3>
                <div class="aai-generator-history-grid">
                    <?php foreach ( array_slice( $history, 0, 5 ) as $item ) :
                        $thumb_url = wp_get_attachment_image_url( $item['attachment_id'], 'thumbnail' );
                        if ( ! $thumb_url ) continue;
                        $edit_url = admin_url( 'upload.php?item=' . $item['attachment_id'] );
                    ?>
                        <div class="aai-generator-history-item">
                            <a href="<?php echo esc_url( $edit_url ); ?>" target="_blank">
                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" />
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Renderowanie zakładki Kolejka
 */
function aai_render_queue_tab() {
    if ( function_exists( 'aai_render_queue_tab_content' ) ) {
        aai_render_queue_tab_content();
    }
}
