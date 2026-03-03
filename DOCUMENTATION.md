# AI Images - Plugin Documentation (v2.0)

## 1. Plugin Overview

**AI Images** is a WordPress plugin that automates featured image creation using Google Gemini AI and OpenAI DALL-E 3. It analyzes article content to generate relevant, high-quality images matching your brand style.

### Feature Summary

| Category | Features |
|----------|----------|
| **Generation** | One-click, auto on publish, bulk, standalone generator, image variants (3 options) |
| **AI Models** | Gemini 2.5 Flash, Gemini 3 Pro Image Preview, DALL-E 3, Imagen 3 |
| **Prompt System** | Auto-build from article, prompt templates, AI article analysis, prompt chaining (3 concepts) |
| **Image Processing** | WebP conversion, watermark/logo overlay, social media crops (OG/Instagram/Pinterest), upscale, AI edit |
| **Post Types** | Posts, Pages, custom post types (configurable), WooCommerce products |
| **Style System** | 18 art presets, per-category style overrides, reference images, color palette, custom styles |
| **Analytics** | Cost/stats dashboard, per-model tracking, daily chart |
| **History** | Generation history per post (last 10), rollback to any previous image |
| **Admin UI** | Unified tabbed interface (Settings, Stats, Categories, Generator, Queue) |
| **Updates** | GitHub auto-updater with tag-based releases |

---

## 2. Architecture

### File Structure

```
agencyjnie-ai-images/
├── agencyjnie-ai-images.php    # Main plugin file — constants, includes, AJAX handlers
├── DOCUMENTATION.md            # This file
├── readme.txt                  # WordPress-style readme
├── admin/
│   ├── admin.js                # All admin JavaScript (~2000 lines, jQuery IIFE)
│   ├── admin.css               # All admin styles (~1900 lines)
│   └── index.php
├── blocks/
│   └── ai-image-block/         # Gutenberg block (vanilla JS, no build step)
│       ├── block.json
│       ├── editor.css
│       └── index.js
└── includes/
    ├── ai-service.php          # Gemini/OpenAI API calls, image generation, ALT text, article analysis, visual concepts
    ├── bulk-actions.php         # Bulk generation from post list
    ├── category-styles.php      # Per-category art style mapping (admin page + logic)
    ├── dalle-api.php            # DALL-E 3 specific integration
    ├── generation-queue.php     # "Queue" tab — find posts without images, bulk generate with progress
    ├── github-updater.php       # Auto-update from GitHub releases
    ├── image-utils.php          # Save to media library, WebP conversion, watermark overlay, encryption
    ├── meta-box.php             # Post editor sidebar — generate button, prompt editor, history, variants, upscale
    ├── prompt-builder.php       # Prompt construction, system instructions, language rules, style descriptions
    ├── settings-page.php        # Unified tabbed settings (Settings, Stats, Categories, Generator tabs), all form fields
    ├── social-images.php        # Social media variant generation (OG, Instagram, Pinterest), OG meta tags
    ├── stats.php                # Custom DB table, generation logging, stats dashboard
    ├── upscale.php              # Image upscaling and AI editing via Gemini
    └── woo-product-shots.php    # WooCommerce product lifestyle photo generation
```

### Key Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `AAI_VERSION` | `2.0.0` | Plugin version |
| `AAI_PLUGIN_DIR` | `plugin_dir_path()` | Absolute path to plugin directory |
| `AAI_PLUGIN_URL` | `plugin_dir_url()` | URL to plugin directory |
| `AAI_PLUGIN_BASENAME` | `plugin_basename()` | e.g. `agencyjnie-ai-images/agencyjnie-ai-images.php` |

### Options Storage

All settings stored in single option `aai_options` (array). Sensitive keys (API keys) encrypted with AES-256-CBC using WP salts.

Key option fields:
- `api_key` — Gemini API key (encrypted)
- `openai_api_key` — OpenAI API key (encrypted)
- `ai_model` — `gemini-2.5-flash-image` | `gemini-3-pro-image-preview` | `dalle3` | `imagen3`
- `base_prompt` — Global style prompt
- `negative_prompt` — Things to avoid
- `art_style` — Style preset key (e.g. `photorealistic`, `isometric_3d`)
- `custom_style` — Custom style description (when art_style = `custom`)
- `dominant_colors` — Array of hex colors (max 5)
- `reference_images` — Array of image URLs (max 3)
- `aspect_ratio` — `16:9` | `4:3` | `1:1` | `3:4` | `9:16`
- `image_language` — Language code | `numbers_only` | `none`
- `auto_generate` — `1` | `0`
- `post_types` — Array of post type slugs
- `webp_conversion` — `1` | `0`
- `social_variants` — `1` | `0`
- `auto_generate_alt` — `1` | `0`
- `dalle_quality` — `standard` | `hd`
- `github_token` — GitHub PAT (encrypted)
- `prompt_templates` — Array of `['name' => string, 'prompt' => string]`
- `watermark_enabled` — `1` | `0`
- `watermark_logo` — Attachment URL
- `watermark_position` — `bottom-right` | `bottom-left` | `top-right` | `top-left`
- `watermark_size` — `small` | `medium` | `large`
- `watermark_opacity` — Integer 10-100

### Custom Database Table

Table `{prefix}aai_generation_log` (created via `dbDelta` on plugin load):

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT | Auto-increment PK |
| post_id | BIGINT | Associated post (0 for standalone) |
| model | VARCHAR(50) | AI model used |
| tokens_used | INT | Total tokens |
| estimated_cost | DECIMAL(10,6) | Estimated $ cost |
| status | VARCHAR(20) | `success` or `error` |
| created_at | DATETIME | Timestamp |

---

## 3. Core Functions Reference

### Image Generation

| Function | File | Description |
|----------|------|-------------|
| `aai_generate_image($prompt, $aspect, $system)` | ai-service.php | Dispatcher — routes to Gemini or DALL-E based on settings |
| `aai_generate_image_gemini($prompt, $aspect, $model, $system)` | ai-service.php | Gemini API call with retry logic (429/503), reference images |
| `aai_generate_image_dalle($prompt, $aspect)` | dalle-api.php | DALL-E 3 API call |
| `aai_build_prompt($post_id)` | prompt-builder.php | Builds complete prompt from post + settings |
| `aai_get_system_instruction($post_id)` | prompt-builder.php | System instruction for Gemini (language rules) |
| `aai_analyze_article_for_prompt($post_id)` | ai-service.php | Sends article text to Gemini for smart prompt generation |
| `aai_generate_visual_concepts($post_id)` | ai-service.php | Generates 3 visual concept options (prompt chaining) |

### Image Processing

| Function | File | Description |
|----------|------|-------------|
| `aai_save_image_to_media_library($base64, $post_id, $type)` | ai-service.php | Wrapper — saves and sets metadata |
| `aai_save_remote_image($data, $post_id, $meta)` | image-utils.php | Core saver — handles base64/URL, creates attachment, WebP, watermark, ALT |
| `aai_convert_to_webp($file_path)` | image-utils.php | GD/Imagick WebP conversion |
| `aai_apply_watermark($file_path)` | image-utils.php | GD overlay of logo with configurable position/size/opacity |
| `aai_generate_social_variants($attachment_id, $post_id)` | social-images.php | Crops to OG 1200x630, Instagram 1080x1080, Pinterest 1000x1500 |
| `aai_crop_image($file, $w, $h)` | social-images.php | Center-crop with GD |
| `aai_upscale_image($attachment_id, $post_id)` | upscale.php | Send image to Gemini for enhancement |
| `aai_edit_image($attachment_id, $post_id, $edit_prompt)` | upscale.php | Send image + text instructions to Gemini |

### Stats & Logging

| Function | File | Description |
|----------|------|-------------|
| `aai_log_generation($post_id, $model, $tokens, $status)` | stats.php | Log generation to custom table |
| `aai_estimate_cost($model, $tokens)` | stats.php | Per-model cost estimation |
| `aai_get_stats($period)` | stats.php | Aggregate stats (total, by model, daily chart data) |

### Security

| Function | File | Description |
|----------|------|-------------|
| `aai_encrypt($value)` | image-utils.php | AES-256-CBC encrypt using WP salts |
| `aai_decrypt($value)` | image-utils.php | AES-256-CBC decrypt |
| `aai_get_secure_option($key)` | image-utils.php | Get + decrypt sensitive option |
| `aai_set_secure_option($key, $value)` | image-utils.php | Encrypt + save sensitive option |

---

## 4. AJAX Endpoints

All endpoints require nonce verification and `edit_post` / `manage_options` capability.

| Action | Handler | Description |
|--------|---------|-------------|
| `aai_generate_image` | agencyjnie-ai-images.php | Generate featured image for post |
| `aai_generate_block_image` | agencyjnie-ai-images.php | Generate image for Gutenberg block |
| `aai_test_connection` | agencyjnie-ai-images.php | Test API key connection |
| `aai_preview_prompt` | agencyjnie-ai-images.php | Preview built prompt for a post |
| `aai_rollback_image` | agencyjnie-ai-images.php | Rollback to previous featured image |
| `aai_generate_variant` | agencyjnie-ai-images.php | Generate one variant (called 3x for 3 variants) |
| `aai_set_variant` | agencyjnie-ai-images.php | Set chosen variant as featured image |
| `aai_analyze_article` | agencyjnie-ai-images.php | AI article analysis for prompt |
| `aai_generate_concepts` | agencyjnie-ai-images.php | Generate 3 visual concepts (prompt chaining) |
| `aai_upscale_image` | agencyjnie-ai-images.php | Upscale current featured image |
| `aai_edit_image` | agencyjnie-ai-images.php | Edit image with text prompt |
| `aai_bulk_generate` | bulk-actions.php | Generate image for single post (called per-post in bulk) |
| `aai_find_posts_without_images` | generation-queue.php | Find posts missing featured images |
| `aai_generate_standalone` | agencyjnie-ai-images.php | Generate image from prompt (no post) |
| `aai_generate_product_shot` | woo-product-shots.php | Generate lifestyle product photo |
| `aai_add_to_product_gallery` | woo-product-shots.php | Add generated image to WooCommerce product gallery |

---

## 5. Filters & Hooks

### Filters

```php
// Modify prompt before sending to AI
add_filter('aai_image_prompt', function($prompt, $post_id, $post) {
    return $prompt;
}, 10, 3);

// Control which post types support AI images
add_filter('aai_allowed_post_types', function($types) {
    $types[] = 'product';
    return $types;
});

// Same filter but for meta box specifically
add_filter('aai_meta_box_post_types', function($types) {
    return $types;
});
```

### Actions

The plugin hooks into standard WordPress actions:
- `admin_menu` — registers settings page
- `admin_enqueue_scripts` — loads admin JS/CSS (only on relevant pages)
- `transition_post_status` — auto-generation on publish
- `wp_head` — outputs OG meta tags for social images

---

## 6. Admin JavaScript (admin.js)

jQuery IIFE pattern. Key initialization functions:

| Function | Lines | Description |
|----------|-------|-------------|
| `initPromptPreview()` | — | Prompt editor: load, refresh, modify tracking |
| `initPromptTemplates()` | — | Template dropdown in meta box |
| `initImageHistory()` | — | History thumbnails + rollback |
| `initVariantsButton()` | — | "Generate 3 variants" + modal |
| `initConceptChaining()` | — | "Propose concepts" → 3 concept cards |
| `initUpscaleEdit()` | — | Upscale + AI edit buttons |
| `initStandaloneGenerator()` | — | Generator tab AJAX |
| `initGenerationQueue()` | — | Queue tab: find posts, sequential generation, progress |
| `initStylePreview()` | — | Live style preview on settings page |
| `initProductShots()` | — | WooCommerce product shots UI |

### Localized Data (`aaiData`)

Passed from PHP via `wp_localize_script`:
- `aaiData.ajaxUrl` — `admin_url('admin-ajax.php')`
- `aaiData.nonce` — `wp_create_nonce('aai_generate_image')`
- `aaiData.postId` — current post ID
- `aaiData.strings` — translatable UI strings
- `aaiData.bulkNonce` — bulk action nonce
- `aaiData.promptTemplates` — saved templates array

---

## 7. Rate Limiting & Retry

Gemini API calls include automatic retry for rate limiting:
- **HTTP 429** (Rate Limit) and **503** (Resource Exhausted) trigger retry
- **3 retries** with delays: 10s, 25s, 60s
- Respects `Retry-After` header if present
- Final failure returns descriptive Polish error message

---

## 8. WooCommerce Integration

When WooCommerce is active (`class_exists('WooCommerce')`):
- `woo-product-shots.php` is loaded
- Product meta box "AI Zdjęcia produktowe" appears on product edit screen
- Upload product photos → describe scenes → generate lifestyle shots
- One-click "Add to product gallery" for generated images
- Uses `aai_send_image_to_gemini()` from upscale.php for image-to-image generation

---

## 9. GitHub Auto-Updater

Class `AAI_GitHub_Updater` in `github-updater.php`:
- Repo: `lukelocksmith/agencyjnie-ai-images`
- Checks latest release tag vs `AAI_VERSION`
- Downloads ZIP from release assets
- Supports private repos via GitHub token
- Hooks: `pre_set_site_transient_update_plugins`, `plugins_api`, `upgrader_process_complete`
