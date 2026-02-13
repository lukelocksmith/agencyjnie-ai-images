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
    
    add_settings_field(
        'github_token',
        __( 'GitHub Token (auto-aktualizacje)', 'agencyjnie-ai-images' ),
        'aai_render_github_token_field',
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
                <?php esc_html_e( 'Gemini 2.5 Pro — najwyższa jakość ✨', 'agencyjnie-ai-images' ); ?>
            </option>
        </optgroup>
        <optgroup label="OpenAI">
            <option value="dalle3" <?php selected( $model, 'dalle3' ); ?>>
                <?php esc_html_e( 'DALL-E 3 — dobry tekst na obrazkach', 'agencyjnie-ai-images' ); ?>
            </option>
        </optgroup>
    </select>
    <p class="description">
        <?php esc_html_e( 'Flash: ~$0.01/obr, szybki. Pro: ~$0.05/obr, najlepsza jakość. DALL-E 3: ~$0.04–0.12/obr, lepszy tekst.', 'agencyjnie-ai-images' ); ?>
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
 * Pole: GitHub Token (auto-aktualizacje)
 */
function aai_render_github_token_field() {
    $token = aai_get_option( 'github_token', '' );
    $display_token = ! empty( $token ) ? str_repeat( '*', 20 ) : '';
    ?>
    <div class="aai-api-key-row">
        <input 
            type="password" 
            id="aai_github_token" 
            name="aai_options[github_token]" 
            value="<?php echo esc_attr( $display_token ); ?>" 
            class="regular-text"
            autocomplete="off"
            placeholder="<?php echo ! empty( $token ) ? esc_attr__( 'Zmień token...', 'agencyjnie-ai-images' ) : esc_attr__( 'ghp_...', 'agencyjnie-ai-images' ); ?>"
        />
        <button type="button" class="button aai-toggle-password" data-target="aai_github_token">
            <?php esc_html_e( 'Pokaż/Ukryj', 'agencyjnie-ai-images' ); ?>
        </button>
    </div>
    <p class="description">
        <?php 
        printf(
            esc_html__( 'Wymagany dla prywatnych repozytoriów. Utwórz token z uprawnieniem "repo". %s', 'agencyjnie-ai-images' ),
            '<a href="https://github.com/settings/tokens/new?scopes=repo&description=AI+Images+Updater" target="_blank">' . esc_html__( 'Utwórz token', 'agencyjnie-ai-images' ) . '</a>'
        );
        ?>
        <br>
        <strong><?php esc_html_e( 'Aktualna wersja:', 'agencyjnie-ai-images' ); ?></strong> <?php echo esc_html( AAI_VERSION ); ?>
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
    <select id="aai_style" name="aai_options[style]" class="aai-style-select">
        <?php foreach ( $styles as $value => $label ) : ?>
            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $style, $value ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>
    
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
    
    // GitHub Token
    if ( isset( $input['github_token'] ) ) {
        if ( strpos( $input['github_token'], '***' ) !== false ) {
            $sanitized['github_token'] = aai_get_option( 'github_token' );
        } else {
            $sanitized['github_token'] = aai_encrypt( sanitize_text_field( $input['github_token'] ) );
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
 * Renderowanie strony ustawień
 */
function aai_render_settings_page() {
    // Sprawdzenie uprawnień
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap aai-settings-wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        
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
    </div>
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
