# Agencyjnie AI Images - Plugin Documentation

## 1. Plugin Overview
**Agencyjnie AI Images** is a WordPress plugin designed to automate the creation of featured images for your posts using Google's Gemini AI (and optionally OpenAI's DALL-E 3). It analyzes the content of your article (title and excerpt) to generate a relevant, high-quality image that matches your specified art style.

### Key Features
- **One-Click Generation**: Generate a featured image directly from the post editor side panel.
- **Auto-Generation**: Automatically generate images for new posts upon publication.
- **Style Customization**: Choose from various art styles (Photorealistic, Digital Art, Cyberpunk, etc.) or define your own.
- **Smart Prompts**: Automatically builds detailed prompts based on your article's title and summary.
- **SEO Friendly**: Automatically generates descriptive ALT text for images (optional).
- **Multiple Sources**: Can fallback to stock photos (Unsplash, Pexels) if AI generation is not desired or fails.

---

## 2. User Guide

### Installation
1.  Upload the `agencyjnie-ai-images` folder to your `/wp-content/plugins/` directory.
2.  Log in to your WordPress Admin Dashboard.
3.  Navigate to **Plugins** and click **Activate** under "Agencyjnie AI Images".

### Configuration
Go to **Settings → AI Images** to configure the plugin.

#### API Settings
-   **Gemini API Key** (Required): You need a key from Google AI Studio.
    -   Get it here: [https://aistudio.google.com/app/apikey](https://aistudio.google.com/app/apikey)
    -   Enter it in the "Klucz API Gemini" field.
-   **OpenAI API Key** (Optional): Required only if you want to use DALL-E 3.
-   **AI Model**: Select between "Gemini 2.5 Flash" (faster, free/cheaper) or "DALL-E 3" (higher quality text rendering, more expensive).

#### Style Settings
-   **Base Prompt**: A general instruction applied to all images (e.g., "Professional, modern, high-quality image").
-   **Negative Prompt**: Attributes to avoid (e.g., "ugly, blurry, text, watermark").
-   **Art Style**: Choose a preset like *Photorealistic*, *Minimalist*, or *Isometric*. Select *User Defined* to write your own style description.
-   **Dominant Colors**: Pick up to 5 colors that the AI should prioritize in the image palette.
-   **Aspect Ratio**: Default is `16:9` (Panoramic), but you can choose `4:3`, `1:1`, etc.
-   **Image Language**: Controls whether text should appear on the image. Options include specific languages, "Numbers only", or "No text".

#### Automation
-   **Auto-generation**: Check this box to automatically generate a featured image when a post is published (status transition to `publish`), *only if* the post doesn't already have one.
-   **Max Content Images**: Limits the number of additional images generated within the post body (if that feature is used).

#### Image Sources
The plugin attempts to find images in this priority order:
1.  **Media Library**: Checks if a matching image already exists.
2.  **Screenshots**: Captures a screenshot if the post mentions a website (requires Urlbox key).
3.  **Brandfetch**: Fetches logos if brand names are detected.
4.  **Stock Photos**: Unsplash/Pexels (requires respective API keys).
5.  **AI Generation**: Generates a new image if no other source is found.

### How to Use

#### Method 1: Manual Generation (Post Editor)
1.  Open a Post in the editor.
2.  Look for the **"Agencyjnie AI Images"** meta box (usually in the sidebar or below the content).
3.  Ensure your post has a **Title**.
4.  Click **"Generuj obrazek"** (Generate Image).
5.  Wait a few seconds. The image will be generated, added to the Media Library, and set as the Featured Image.

#### Method 2: Automatic Generation
1.  Ensure "Auto-generation" is enabled in Settings.
2.  Write a new post and hit **Publish**.
3.  The plugin will run in the background (via WP-Cron) and attach an image shortly after publication.

---

## 3. Technical Documentation

### File Structure
The plugin source is located in `wp-content/plugins/agencyjnie-ai-images/`.

-   **`agencyjnie-ai-images.php`**: The main plugin file. Initializes constants and loads includes.
-   **`includes/`**:
    -   **`ai-service.php`**: Core logic for communicating with Google Gemini and OpenAI APIs. Handles requests and responses.
    -   **`prompt-builder.php`**: specialized logic for constructing the text prompt sent to the AI.
    -   **`settings-page.php`**: Renders the settings UI in WP Admin.
    -   **`image-utils.php`**: Helper functions for handling image data and saving to the Media Library.
    -   **`meta-box.php`**: Adds the UI controls to the Post Editor.
    -   **`content-images.php`**: Logic for inserting images inside post content.
    -   **`image-sources.php`** & **`sources/`**: Handlers for third-party image sources (Unsplash, Pexels, etc.).

### Key Functions

#### `aai_generate_image( $prompt )`
Located in `includes/ai-service.php`.
-   **Input**: `$prompt` (string)
-   **Output**: Array containing `image_data` (base64) and `tokens` usage data, or a `WP_Error`.
-   **Description**: Dispatches the request to the selected AI model (Gemini or DALL-E) and processes the response.

#### `aai_build_prompt( $post_id )`
Located in `includes/prompt-builder.php`.
-   **Input**: `$post_id` (int)
-   **Output**: string (The constructed prompt)
-   **Description**: Combines the post title, excerpt, global style settings, color palette, and language instructions into a coherent prompt for the AI.

#### `aai_save_image_to_media_library( ... )`
Located in `includes/ai-service.php` (wrapper) and `image-utils.php`.
-   **Description**: Decodes the base64 image data, creates a file in the WordPress uploads directory, creates an attachment post, and sets the necessary metadata (ALT text, title).

### Hooks & Filters

#### `aai_image_prompt` (Filter)
Allows developers to modify the generated prompt before it is sent to the AI.
```php
add_filter( 'aai_image_prompt', function( $prompt, $post_id, $post ) {
    return $prompt . " Make it look like a oil painting.";
}, 10, 3 );
```

#### `aai_allowed_post_types` (Filter)
Control which post types trigger the automatic generation on publish. Defaults to `['post']`.
```php
add_filter( 'aai_allowed_post_types', function( $types ) {
    $types[] = 'page';
    $types[] = 'product';
    return $types;
} );
```

### Async Processing
Automatic generation uses **WP-Cron** (`aai_async_auto_generate`) to avoid slowing down the publishing process. The `aai_auto_generate_on_publish` function schedules a single event which is then handled by `aai_process_async_generation`.

---

## 4. Gutenberg Block: AI Image

### Overview
The plugin includes a custom Gutenberg block called **"AI Image"** that allows you to insert AI-generated images anywhere in your post content.

### How to Use
1.  In the Block Editor, click the **"+"** button to add a new block.
2.  Search for **"AI Image"** or find it in the **Media** category.
3.  Enter your **custom prompt** in the text area.
4.  Click **"Generuj obrazek"** (Generate Image).
5.  The image will appear in the block once generated.

### Style Overrides
By default, the block uses your **global plugin settings** (art style, colors, etc.). However, you can override these per-block:
1.  In the block's **Inspector Controls** (right sidebar), toggle **"Nadpisz globalne style"**.
2.  Select a different **Art Style** and/or **Aspect Ratio**.
3.  Click **"Regeneruj obrazek"** to apply the new style.

### Technical Details
-   Block name: `aai/ai-image`
-   Location: `blocks/ai-image-block/`
-   AJAX action: `aai_generate_block_image`

