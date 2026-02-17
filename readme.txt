=== AI Images ===
Contributors: agencyjnie
Tags: ai, images, featured image, gemini, dalle, automatic images, gutenberg
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 2.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatyczne generowanie featured images przy użyciu Google Gemini AI na podstawie tytułu i treści artykułu.

== Description ==

Wtyczka AI Images automatyzuje proces tworzenia obrazków wyróżniających dla Twoich artykułów.
Wykorzystuje Google Gemini AI lub DALL-E 3 do generowania unikalnych obrazków dopasowanych do treści.

**Główne funkcje:**

* Generowanie obrazków jednym kliknięciem w edytorze postów
* Automatyczne generowanie przy publikacji (opcjonalne)
* Konfigurowalny styl obrazków (fotorealistyczny, ilustracja, minimalistyczny, itd.)
* Wybór dominujących kolorów
* Prompt bazowy określający ogólny styl
* Różne proporcje obrazka (16:9, 4:3, 1:1, itd.)
* **NEW** Reference Images (Multimodal) - upload up to 3 images to guide the style
* **NEW** GitHub Auto-Updater

== Installation ==

1. Wgraj folder `agencyjnie-ai-images` do katalogu `/wp-content/plugins/`
2. Aktywuj wtyczkę przez menu 'Wtyczki' w WordPress
3. Przejdź do Ustawienia → AI Images
4. Wprowadź klucz API z Google AI Studio (https://aistudio.google.com/app/apikey)
5. Skonfiguruj styl obrazków według preferencji

== Frequently Asked Questions ==

= Gdzie uzyskać klucz API? =

Klucz API możesz uzyskać bezpłatnie w Google AI Studio: https://aistudio.google.com/app/apikey

= Czy generowanie obrazków jest płatne? =

Google Gemini API ma darmowy limit. Szczegóły znajdziesz w dokumentacji Google AI.

= Jakie formaty obrazków są generowane? =

Obrazki są zapisywane w formacie PNG.

== Changelog ==

= 2.0.0 =
* **Unified Tabbed UI** — Settings, Stats, Categories, Generator, Queue in one page
* **Prompt Templates** — save and reuse favorite prompts
* **Live Style Preview** — AI-generated preview images for all 18 art styles
* **Prompt Chaining** — AI proposes 3 visual concepts, you pick the best one
* **Watermark / Logo** — automatic logo overlay on generated images (GD)
* **Generation Queue** — scan posts without images, batch generate with progress bar
* **Standalone Generator** — generate images from prompt without a post (new tab)
* **WooCommerce Product Shots** — upload product photo, describe scenes, AI generates lifestyle shots
* **DALL-E 3 UX** — hide features unsupported by DALL-E 3 (reference images, upscale, analysis, product shots)
* Removed GitHub token field from settings (auto-updater no longer loaded)

= 1.9.0 =
* Prompt editor with live preview and article analysis
* Image variants (generate multiple options)
* Statistics dashboard with token usage tracking
* Upscale & image editing via Gemini
* Social media OG image generation
* Category-based style overrides
* Negative prompt support
* WebP conversion
* Image language control
* Bulk actions improvements

= 1.4.2 =
* Fixed Gemini Pro model — updated to gemini-3-pro-image-preview (old preview expired)

= 1.4.1 =
* Fixed GitHub auto-updater — folder rename now works correctly
* Fixed auth for private repo downloads (Bearer header instead of query param)

= 1.4.0 =
* Renamed plugin from "Agencyjnie AI Images" to "AI Images"
* Fixed XSS vulnerability in admin.js image preview
* Fixed model validation bug — Gemini Pro selection now saves correctly
* Fixed double API key sanitization overwriting encrypted keys
* Removed ~40% dead code (content images, image sources, disabled API tests)
* Updated documentation

= 1.3.1 =
* Fixed critical PHP syntax error in `ai-service.php`
* Fixed JS syntax errors in `admin.js` preventing button functionality
* Improved "No Text" mode adherence using System Instructions
* Added fallback for API connection issues

= 1.3.0 =
* Added Reference Images feature (Gemini Multimodal)
* Added GitHub Auto-Updater
* Added Model selection (Flash, Pro, Imagen 3, DALL-E 3)
* Updated author to important.is

= 1.0.0 =
* Pierwsza wersja wtyczki
* Generowanie obrazków przez Google Gemini API
* Konfigurowalny styl i kolory
* Auto-generowanie przy publikacji
* Meta box w edytorze postów

== Upgrade Notice ==

= 1.0.0 =
Pierwsza wersja wtyczki.
