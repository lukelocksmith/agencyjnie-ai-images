/**
 * JavaScript dla panelu admina wtyczki AI Images
 */

(function ($) {
    'use strict';

    /**
     * Inicjalizacja po załadowaniu DOM
     */
    $(document).ready(function () {
        initModelFeatureVisibility();
        initBulkGenerate();
        initReferenceImages();
        initGenerateButton();
        initTestConnection();
        initTestOpenAIConnection();
        initStyleToggle();
        initStylePreview();
        initColorPickers();
        initPasswordToggle();
        initPromptTemplates();
        initConceptChaining();
        initPromptPreview();
        initImageHistory();
        initVariantsButton();
        initUpscaleEdit();
        initWatermarkUpload();
        initStandaloneGenerator();
        initGenerationQueue();
        initProductShots();
    });

    /**
     * Show/hide features based on selected AI model.
     * DALL-E 3 does not support: reference images, article analysis,
     * visual concepts, upscale/edit, WooCommerce product shots, auto ALT.
     */
    function initModelFeatureVisibility() {
        var model = (typeof aaiData !== 'undefined' && aaiData.aiModel) ? aaiData.aiModel : 'gemini';
        var isDalle = (model === 'dalle3');

        // Settings page: hide Gemini-only setting rows using their container IDs
        if (isDalle) {
            $('#aai_reference_images_container').closest('tr').hide();
            $('input[name="aai_options[auto_generate_alt]"]').closest('tr').hide();
        }
        // DALL-E quality — show only when DALL-E selected
        $('#aai_dalle_quality').closest('tr').toggle(isDalle);

        // Post editor: hide Gemini-only buttons
        if (isDalle) {
            $('#aai-analyze-article').hide();
            $('#aai-concepts-btn').hide();
            $('#aai-upscale-btn').hide();
        }

        // WooCommerce product shots meta box — Gemini only
        if (isDalle) {
            $('#aai_product_shots_box').hide();
        }

        // Settings page: react to model dropdown change for live toggle
        $('input[name="aai_options[ai_model]"]').on('change', function () {
            var newModel = $(this).val();
            var newIsDalle = (newModel === 'dalle3');

            $('#aai_reference_images_container').closest('tr').toggle(!newIsDalle);
            $('input[name="aai_options[auto_generate_alt]"]').closest('tr').toggle(!newIsDalle);
            $('#aai_dalle_quality').closest('tr').toggle(newIsDalle);
        });
    }

    /**
     * Obsługa przycisku generowania obrazka
     */
    function initGenerateButton() {
        var $btn = $('#aai-generate-btn');

        if (!$btn.length) {
            return;
        }

        $btn.on('click', function (e) {
            e.preventDefault();

            var postId = $btn.data('post-id');
            var hasThumbnail = $btn.data('has-thumbnail') === 1 || $btn.data('has-thumbnail') === '1';

            // Sprawdź czy post jest zapisany
            if (!postId || postId === 0) {
                showMessage('error', aaiData.strings.error + ' Post musi być najpierw zapisany.');
                return;
            }

            // Rozpocznij generowanie
            generateImage(postId, hasThumbnail);
        });
    }

    /**
     * Generowanie obrazka przez AJAX
     * @param {number} postId - ID posta
     * @param {boolean} isRegenerate - Czy to regeneracja
     */
    function generateImage(postId, isRegenerate) {
        var $btn = $('#aai-generate-btn');
        var $message = $('#aai-message');
        var $currentImage = $('#aai-current-image');
        var btnOriginalText = $btn.find('.aai-btn-text').text();

        // Pokaż stan ładowania
        $btn.addClass('is-loading');
        $btn.find('.aai-btn-text').text(isRegenerate ? 'Regenerowanie...' : aaiData.strings.generating);
        $message.hide();

        // Build request data
        var requestData = {
            action: 'aai_generate_image',
            post_id: postId,
            nonce: aaiData.nonce
        };

        // Send custom prompt if user edited it
        var $promptEditor = $('#aai-prompt-editor');
        if ($promptEditor.length && $promptEditor.hasClass('is-modified')) {
            requestData.custom_prompt = $promptEditor.val();
        }

        // Wykonaj request AJAX
        $.ajax({
            url: aaiData.ajaxUrl,
            type: 'POST',
            data: requestData,
            timeout: 120000, // 2 minuty timeout
            success: function (response) {
                if (response.success) {
                    // Sukces - pokaż nowy obrazek
                    var message = response.data.message;

                    // Dodaj informację o tokenach jeśli dostępna
                    if (response.data.tokens && response.data.tokens.total_tokens > 0) {
                        message += ' ' + formatTokenUsage(response.data.tokens);
                    }

                    showMessage('success', message);

                    // Wyświetl tokeny w osobnym miejscu
                    updateTokensDisplay(response.data.tokens);

                    // Aktualizuj podgląd obrazka
                    if (response.data.image_url) {
                        var $img = $('<img>', {
                            src: response.data.image_url + '?t=' + Date.now(),
                            alt: '',
                            'class': 'aai-thumbnail-preview'
                        });
                        var $label = $('<p>', { 'class': 'aai-label', text: 'Aktualny Featured Image:' });
                        $currentImage.empty().append($label).append($img);

                        if (response.data.source) {
                            var $source = $('<span>', {
                                'class': 'aai-source-indicator source-' + escapeHtml(response.data.source),
                                text: getSourceLabel(response.data.source)
                            });
                            $currentImage.append($source);
                        }

                        // Zaktualizuj stan przycisku na "Regeneruj"
                        $btn.data('has-thumbnail', '1');
                        $btn.find('.aai-btn-text').text('Regeneruj Featured Image');

                        // Odśwież featured image w edytorze Gutenberga (jeśli dostępny)
                        refreshGutenbergFeaturedImage(response.data.attachment_id);
                    }
                } else {
                    // Błąd z API
                    showMessage('error', response.data.message || aaiData.strings.error);
                    $btn.find('.aai-btn-text').text(btnOriginalText); // Przywróć tekst w razie błędu
                }
            },
            error: function (xhr, status, error) {
                var errorMessage = aaiData.strings.error;

                if (status === 'timeout') {
                    errorMessage = 'Przekroczono czas oczekiwania. Generowanie obrazka trwa zbyt długo.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }

                showMessage('error', errorMessage);
                $btn.find('.aai-btn-text').text(btnOriginalText);
            },
            complete: function () {
                // Przywróć stan przycisku
                $btn.removeClass('is-loading');
            }
        });
    }

    /**
     * Wyświetlanie komunikatów
     */
    function showMessage(type, message) {
        var $message = $('#aai-message');

        $message
            .removeClass('success error info')
            .addClass(type)
            .text(message)
            .fadeIn();

        // Automatyczne ukrycie po 10 sekundach dla sukcesu
        if (type === 'success') {
            setTimeout(function () {
                $message.fadeOut();
            }, 10000);
        }
    }

    /**
     * Odświeżenie featured image w edytorze Gutenberga
     */
    function refreshGutenbergFeaturedImage(attachmentId) {
        // Sprawdź czy jesteśmy w edytorze Gutenberga
        if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
            try {
                // Ustaw nowy featured image
                wp.data.dispatch('core/editor').editPost({
                    featured_media: attachmentId
                });
            } catch (e) {
                // Ignoruj błędy - klasyczny edytor
                console.log('AAI: Could not update Gutenberg featured image', e);
            }
        }
    }

    /**
     * Test połączenia z API
     */
    function initTestConnection() {
        var $btn = $('#aai_test_connection');

        if (!$btn.length) {
            return;
        }

        $btn.on('click', function (e) {
            e.preventDefault();

            var $result = $('#aai_test_result');
            var apiKey = $('#aai_api_key').val();

            // Sprawdź czy klucz jest wpisany w formularzu
            if (!apiKey || !apiKey.trim()) {
                $result
                    .removeClass('success loading')
                    .addClass('error')
                    .text('✗ Wprowadź klucz API w polu powyżej');
                return;
            }

            $btn.prop('disabled', true).text('Testowanie...');
            $result
                .removeClass('success error')
                .addClass('loading')
                .text('Sprawdzanie połączenia...');

            // Wyślij klucz tylko jeśli użytkownik wpisał nowy (nie zamaskowany gwiazdkami)
            var requestData = {
                action: 'aai_test_connection',
                nonce: aaiData.nonce
            };
            if (apiKey.indexOf('*') === -1) {
                requestData.api_key = apiKey;
            }

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: requestData,
                timeout: 30000,
                success: function (response) {
                    $result.removeClass('loading');
                    if (response.success) {
                        $result.addClass('success').text('✓ ' + response.data.message);
                    } else {
                        $result.addClass('error').text('✗ ' + response.data.message);
                    }
                },
                error: function (xhr, status) {
                    $result.removeClass('loading').addClass('error');
                    if (status === 'timeout') {
                        $result.text('✗ Przekroczono czas oczekiwania');
                    } else {
                        $result.text('✗ Błąd połączenia z serwerem');
                    }
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Testuj połączenie');
                }
            });
        });
    }

    /**
     * Test połączenia z OpenAI API
     */
    function initTestOpenAIConnection() {
        var $btn = $('#aai_test_openai_connection');

        if (!$btn.length) {
            return;
        }

        $btn.on('click', function (e) {
            e.preventDefault();

            var $result = $('#aai_test_openai_result');
            var apiKey = $('#aai_openai_api_key').val();

            if (!apiKey || !apiKey.trim()) {
                $result
                    .removeClass('success loading')
                    .addClass('error')
                    .text('✗ Wprowadź klucz API OpenAI');
                return;
            }

            $btn.prop('disabled', true).text('Testowanie...');
            $result
                .removeClass('success error')
                .addClass('loading')
                .text('Sprawdzanie połączenia...');

            // Wyślij klucz tylko jeśli użytkownik wpisał nowy
            var requestData = {
                action: 'aai_test_openai_connection',
                nonce: aaiData.nonce
            };
            if (apiKey.indexOf('*') === -1) {
                requestData.api_key = apiKey;
            }

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: requestData,
                timeout: 30000,
                success: function (response) {
                    $result.removeClass('loading');
                    if (response.success) {
                        $result.addClass('success').text('✓ ' + response.data.message);
                    } else {
                        $result.addClass('error').text('✗ ' + response.data.message);
                    }
                },
                error: function (xhr, status) {
                    $result.removeClass('loading').addClass('error');
                    if (status === 'timeout') {
                        $result.text('✗ Przekroczono czas oczekiwania');
                    } else {
                        $result.text('✗ Błąd połączenia z serwerem');
                    }
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Testuj');
                }
            });
        });
    }

    /**
     * Toggle dla pola własnego stylu
     */
    function initStyleToggle() {
        var $select = $('#aai_style');
        var $customWrapper = $('#aai_custom_style_wrapper');

        if (!$select.length) {
            return;
        }

        $select.on('change', function () {
            if ($(this).val() === 'custom') {
                $customWrapper.slideDown();
            } else {
                $customWrapper.slideUp();
            }
        });
    }

    /**
     * Live style preview — shows AI-generated thumbnail + description when dropdown changes
     */
    function initStylePreview() {
        var $select = $('#aai_style');
        var $preview = $('#aai-style-preview');

        if (!$select.length || !$preview.length) return;

        var previews = {};
        var images = {};
        try {
            previews = JSON.parse($select.attr('data-style-previews') || '{}');
            images = JSON.parse($select.attr('data-style-images') || '{}');
        } catch (e) {
            return;
        }

        function renderPreview(styleKey) {
            var data = previews[styleKey];
            if (!data || styleKey === 'custom') {
                $preview.slideUp(200);
                return;
            }

            var label = $select.find('option[value="' + styleKey + '"]').text().trim();
            var imgUrl = images[styleKey] || '';
            var thumbHtml;

            if (imgUrl) {
                thumbHtml = '<img class="aai-style-preview-thumb" src="' + escapeHtml(imgUrl) + '" alt="' + escapeHtml(label) + '" />';
            } else {
                thumbHtml = '<div class="aai-style-preview-thumb" style="background: ' + data.gradient + ';"></div>';
            }

            $preview.html(
                '<div class="aai-style-preview-card">' +
                    thumbHtml +
                    '<div class="aai-style-preview-info">' +
                        '<strong class="aai-style-preview-name">' + escapeHtml(label) + '</strong>' +
                        '<p class="aai-style-preview-desc">' + escapeHtml(data.desc) + '</p>' +
                    '</div>' +
                '</div>'
            ).slideDown(200);
        }

        $select.on('change', function () {
            renderPreview($(this).val());
        });
    }


    /**
     * Obsługa color pickerów
     */
    function initColorPickers() {
        var $container = $('#aai_colors_container');
        var $addBtn = $('.aai-add-color');

        if (!$container.length) {
            return;
        }

        // Dodawanie nowego koloru
        $addBtn.on('click', function (e) {
            e.preventDefault();

            var colorCount = $container.find('.aai-color-item').length;

            if (colorCount >= 5) {
                alert('Maksymalnie 5 kolorów');
                return;
            }

            var $newColor = $(
                '<div class="aai-color-item">' +
                '<input type="color" name="aai_options[colors][]" value="#2563eb" class="aai-color-picker" />' +
                '<button type="button" class="button aai-remove-color" title="Usuń kolor">×</button>' +
                '</div>'
            );

            $container.append($newColor);
        });

        // Usuwanie koloru
        $container.on('click', '.aai-remove-color', function (e) {
            e.preventDefault();

            var colorCount = $container.find('.aai-color-item').length;

            if (colorCount <= 1) {
                alert('Musi pozostać przynajmniej jeden kolor');
                return;
            }

            $(this).closest('.aai-color-item').remove();
        });
    }

    /**
     * Toggle pokazywania/ukrywania hasła API key
     */
    function initPasswordToggle() {
        $('.aai-toggle-password').on('click', function (e) {
            e.preventDefault();

            var targetId = $(this).data('target');
            var $input = $('#' + targetId);

            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
            } else {
                $input.attr('type', 'password');
            }
        });
    }

    /**
     * Prompt Templates — settings page (add/remove) + meta-box (select to fill)
     */
    function initPromptTemplates() {
        // === Settings page: dynamic add/remove template rows ===
        var $list = $('#aai-templates-list');
        var $addBtn = $('#aai-add-template');

        if ($addBtn.length) {
            var templateIndex = $list.find('.aai-template-row').length;

            $addBtn.on('click', function (e) {
                e.preventDefault();
                var html = '<div class="aai-template-row">' +
                    '<input type="text" name="aai_options[prompt_templates][' + templateIndex + '][name]" ' +
                    'value="" placeholder="Nazwa szablonu" class="regular-text aai-template-name" />' +
                    '<textarea name="aai_options[prompt_templates][' + templateIndex + '][prompt]" ' +
                    'rows="2" class="large-text aai-template-prompt" placeholder="Treść promptu..."></textarea>' +
                    '<button type="button" class="button aai-remove-template" title="Usuń">&times;</button>' +
                    '</div>';
                $list.append(html);
                templateIndex++;
            });

            $list.on('click', '.aai-remove-template', function (e) {
                e.preventDefault();
                $(this).closest('.aai-template-row').slideUp(200, function () { $(this).remove(); });
            });
        }

        // === Meta-box: template select → fill prompt editor ===
        var $select = $('#aai-template-select');
        var $editor = $('#aai-prompt-editor');

        if ($select.length && $editor.length) {
            $select.on('change', function () {
                var val = $(this).val();
                if (val) {
                    $editor.val(val).addClass('is-modified').trigger('input');
                    // Reset dropdown
                    $(this).val('');
                }
            });
        }
    }

    /**
     * Concept Chaining — generates 3 visual concepts, user picks one
     */
    function initConceptChaining() {
        var $btn = $('#aai-generate-concepts');
        if (!$btn.length) return;

        $btn.on('click', function (e) {
            e.preventDefault();
            var postId = $('#aai-generate-btn').data('post-id');
            if (!postId) return;

            $btn.prop('disabled', true).text('Generowanie...');

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_generate_concepts',
                    post_id: postId,
                    nonce: aaiData.nonce
                },
                timeout: 30000,
                success: function (response) {
                    if (response.success && response.data.concepts) {
                        showConceptsPanel(response.data.concepts);
                    } else {
                        showMessage('error', response.data.message || 'Nie udało się wygenerować koncepcji.');
                    }
                },
                error: function (xhr, status) {
                    var msg = status === 'timeout' ? 'Przekroczono czas oczekiwania.' : 'Błąd połączenia.';
                    showMessage('error', msg);
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Koncepcje');
                }
            });
        });

        function showConceptsPanel(concepts) {
            // Remove existing panel if any
            $('#aai-concepts-panel').remove();

            var html = '<div id="aai-concepts-panel" class="aai-concepts-panel">';
            html += '<div class="aai-concepts-header"><strong>Wybierz koncepcję:</strong></div>';

            for (var i = 0; i < concepts.length; i++) {
                html += '<div class="aai-concept-card" data-prompt="' + escapeAttr(concepts[i].prompt) + '">';
                html += '<div class="aai-concept-title">' + escapeHtml(concepts[i].title) + '</div>';
                html += '<div class="aai-concept-prompt">' + escapeHtml(concepts[i].prompt.substring(0, 120)) + '...</div>';
                html += '<button type="button" class="button button-small aai-concept-use">Użyj</button>';
                html += '</div>';
            }

            html += '<button type="button" class="button button-small aai-concepts-close">Zamknij</button>';
            html += '</div>';

            // Insert before prompt editor
            var $promptSection = $('.aai-prompt-section');
            $promptSection.before(html);

            // Handle concept selection
            $('#aai-concepts-panel').on('click', '.aai-concept-use', function () {
                var prompt = $(this).closest('.aai-concept-card').data('prompt');
                $('#aai-prompt-editor').val(prompt).addClass('is-modified').trigger('input');
                $('#aai-concepts-panel').slideUp(200, function () { $(this).remove(); });
                showMessage('info', 'Koncepcja wybrana. Możesz edytować prompt i generować.');
            });

            $('#aai-concepts-panel').on('click', '.aai-concepts-close', function () {
                $('#aai-concepts-panel').slideUp(200, function () { $(this).remove(); });
            });
        }

        function escapeAttr(text) {
            return text.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    }

    /**
     * Podgląd i edycja promptu
     */
    function initPromptPreview() {
        var $editor = $('#aai-prompt-editor');
        var $refreshBtn = $('#aai-refresh-prompt');

        if (!$editor.length) return;

        // Track if user has modified the prompt
        var isModified = false;
        $editor.on('input', function () {
            isModified = true;
            $(this).addClass('is-modified');
        });

        // Refresh prompt button
        $refreshBtn.on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var postId = $('#aai-generate-btn').data('post-id');

            if (!postId) return;

            $btn.prop('disabled', true).text('Ładowanie...');

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_preview_prompt',
                    post_id: postId,
                    nonce: aaiData.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $editor.val(response.data.prompt).removeClass('is-modified');
                        isModified = false;
                    }
                },
                complete: function () {
                    $btn.prop('disabled', false).text('Odśwież');
                }
            });
        });

        // Analyze article button
        var $analyzeBtn = $('#aai-analyze-article');
        if ($analyzeBtn.length) {
            $analyzeBtn.on('click', function (e) {
                e.preventDefault();
                var $btn = $(this);
                var postId = $('#aai-generate-btn').data('post-id');

                if (!postId) return;

                $btn.prop('disabled', true).text('Analizowanie...');

                $.ajax({
                    url: aaiData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'aai_analyze_article',
                        post_id: postId,
                        nonce: aaiData.nonce
                    },
                    timeout: 30000,
                    success: function (response) {
                        if (response.success && response.data.prompt) {
                            $editor.val(response.data.prompt).addClass('is-modified');
                            isModified = true;
                            showMessage('info', 'Prompt wygenerowany na podstawie analizy artykułu. Możesz go edytować.');
                        } else {
                            showMessage('error', response.data.message || 'Nie udało się przeanalizować artykułu.');
                        }
                    },
                    error: function (xhr, status) {
                        var msg = status === 'timeout' ? 'Przekroczono czas oczekiwania.' : 'Błąd połączenia.';
                        showMessage('error', msg);
                    },
                    complete: function () {
                        $btn.prop('disabled', false).text('Analizuj artykuł');
                    }
                });
            });
        }
    }

    /**
     * Obsługa historii obrazków i rollback
     */
    function initImageHistory() {
        var $historyList = $('#aai-history-list');

        if (!$historyList.length) return;

        $historyList.on('click', '.aai-history-item', function (e) {
            e.preventDefault();

            var $item = $(this);
            var attachmentId = $item.data('attachment-id');
            var postId = $('#aai-generate-btn').data('post-id');

            if (!postId || !attachmentId) return;

            // Confirm rollback
            if (!confirm('Przywrócić ten obrazek jako featured image?')) return;

            $item.addClass('is-loading');

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_rollback_image',
                    post_id: postId,
                    attachment_id: attachmentId,
                    nonce: aaiData.nonce
                },
                success: function (response) {
                    if (response.success) {
                        showMessage('success', response.data.message);

                        // Update image preview
                        if (response.data.image_url) {
                            var $currentImage = $('#aai-current-image');
                            var $img = $('<img>', {
                                src: response.data.image_url + '?t=' + Date.now(),
                                alt: '',
                                'class': 'aai-thumbnail-preview'
                            });
                            var $label = $('<p>', { 'class': 'aai-label', text: 'Aktualny Featured Image:' });
                            $currentImage.empty().append($label).append($img);

                            // Remove this item from history list
                            $item.fadeOut(300, function () { $(this).remove(); });

                            // Refresh Gutenberg
                            refreshGutenbergFeaturedImage(response.data.attachment_id);
                        }
                    } else {
                        showMessage('error', response.data.message);
                    }
                },
                error: function () {
                    showMessage('error', 'Błąd połączenia.');
                },
                complete: function () {
                    $item.removeClass('is-loading');
                }
            });
        });
    }

    /**
     * Obsługa przycisku wariantów
     */
    function initVariantsButton() {
        var $btn = $('#aai-variants-btn');

        if (!$btn.length) return;

        $btn.on('click', function(e) {
            e.preventDefault();

            var postId = $btn.data('post-id');
            if (!postId) return;

            // Get custom prompt if edited
            var customPrompt = '';
            var $promptEditor = $('#aai-prompt-editor');
            if ($promptEditor.length && $promptEditor.hasClass('is-modified')) {
                customPrompt = $promptEditor.val();
            }

            showVariantsModal(postId, customPrompt);
        });
    }

    /**
     * Modal z wariantami obrazków
     */
    function showVariantsModal(postId, customPrompt) {
        // Build modal HTML
        var html = '<div id="aai-variants-overlay" class="aai-variants-overlay">';
        html += '<div class="aai-variants-modal">';
        html += '<div class="aai-variants-header">';
        html += '<h3>Warianty obrazka</h3>';
        html += '<button type="button" class="aai-variants-close" id="aai-variants-close">&times;</button>';
        html += '</div>';
        html += '<div class="aai-variants-progress" id="aai-variants-progress">Generowanie wariantu 1 z 3...</div>';
        html += '<div class="aai-variants-grid" id="aai-variants-grid">';
        for (var i = 0; i < 3; i++) {
            html += '<div class="aai-variant-card aai-variant-loading" id="aai-variant-' + i + '">';
            html += '<div class="aai-variant-placeholder"><span class="spinner is-active"></span></div>';
            html += '</div>';
        }
        html += '</div>';
        html += '<div class="aai-variants-actions" id="aai-variants-actions" style="display:none;">';
        html += '<button type="button" class="button" id="aai-variants-cancel">Odrzuć wszystkie</button>';
        html += '</div>';
        html += '</div></div>';

        $('body').append(html);

        var attachmentIds = [];
        var completed = 0;
        var selectedId = null;

        // Generate 3 variants sequentially
        function generateNext(index) {
            if (index >= 3) {
                $('#aai-variants-progress').text('Gotowe! Kliknij na obrazek, aby go wybrać.');
                $('#aai-variants-actions').show();
                return;
            }

            $('#aai-variants-progress').text('Generowanie wariantu ' + (index + 1) + ' z 3...');

            var requestData = {
                action: 'aai_generate_variant',
                post_id: postId,
                nonce: aaiData.nonce
            };
            if (customPrompt) {
                requestData.custom_prompt = customPrompt;
            }

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: requestData,
                timeout: 120000,
                success: function(response) {
                    var $card = $('#aai-variant-' + index);

                    if (response.success) {
                        attachmentIds[index] = response.data.attachment_id;
                        $card.removeClass('aai-variant-loading').addClass('aai-variant-ready');
                        $card.html(
                            '<img src="' + response.data.image_url + '" alt="Wariant ' + (index + 1) + '" />' +
                            '<div class="aai-variant-label">Wariant ' + (index + 1) + '</div>'
                        );

                        // Click to select
                        $card.on('click', function() {
                            if (selectedId) return; // Already selecting
                            selectedId = response.data.attachment_id;
                            $card.addClass('aai-variant-selected');
                            selectVariant(postId, selectedId, attachmentIds);
                        });
                    } else {
                        $card.removeClass('aai-variant-loading').addClass('aai-variant-error');
                        $card.html('<div class="aai-variant-error-msg">' + escapeHtml(response.data.message || 'Błąd') + '</div>');
                    }

                    completed++;
                },
                error: function() {
                    var $card = $('#aai-variant-' + index);
                    $card.removeClass('aai-variant-loading').addClass('aai-variant-error');
                    $card.html('<div class="aai-variant-error-msg">Błąd połączenia</div>');
                    completed++;
                },
                complete: function() {
                    generateNext(index + 1);
                }
            });
        }

        generateNext(0);

        // Close/cancel handlers
        $('#aai-variants-close, #aai-variants-cancel').on('click', function() {
            // Delete all draft variants
            attachmentIds.forEach(function(id) {
                if (id && id !== selectedId) {
                    $.post(aaiData.ajaxUrl, {
                        action: 'aai_set_variant',
                        post_id: postId,
                        attachment_id: 0,
                        reject_ids: [id],
                        nonce: aaiData.nonce
                    });
                }
            });
            $('#aai-variants-overlay').remove();
        });
    }

    /**
     * Ustawia wybrany wariant jako featured image
     */
    function selectVariant(postId, attachmentId, allIds) {
        var rejectIds = allIds.filter(function(id) { return id && id !== attachmentId; });

        $('#aai-variants-progress').text('Ustawianie wybranego wariantu...');

        $.ajax({
            url: aaiData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aai_set_variant',
                post_id: postId,
                attachment_id: attachmentId,
                reject_ids: rejectIds,
                nonce: aaiData.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update main image preview
                    var $currentImage = $('#aai-current-image');
                    var $img = $('<img>', {
                        src: response.data.image_url + '?t=' + Date.now(),
                        alt: '',
                        'class': 'aai-thumbnail-preview'
                    });
                    var $label = $('<p>', { 'class': 'aai-label', text: 'Aktualny Featured Image:' });
                    $currentImage.empty().append($label).append($img);

                    var $btn = $('#aai-generate-btn');
                    $btn.data('has-thumbnail', '1');
                    $btn.find('.aai-btn-text').text('Regeneruj Featured Image');

                    refreshGutenbergFeaturedImage(attachmentId);
                    showMessage('success', response.data.message);
                } else {
                    showMessage('error', response.data.message || 'Błąd');
                }
            },
            error: function() {
                showMessage('error', 'Błąd połączenia.');
            },
            complete: function() {
                $('#aai-variants-overlay').remove();
            }
        });
    }

    /**
     * Obsługa przycisków upscale i edycji
     */
    function initUpscaleEdit() {
        var $upscaleBtn = $('#aai-upscale-btn');
        var $editBtn = $('#aai-edit-btn');
        var $editWrap = $('#aai-edit-prompt-wrap');
        var $editSubmit = $('#aai-edit-submit');

        if (!$upscaleBtn.length) return;

        // Upscale button
        $upscaleBtn.on('click', function(e) {
            e.preventDefault();
            var postId = $(this).data('post-id');
            var attachmentId = $(this).data('attachment-id');

            if (!postId || !attachmentId) return;

            $upscaleBtn.prop('disabled', true).text('Powiększanie...');

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_upscale_image',
                    post_id: postId,
                    attachment_id: attachmentId,
                    nonce: aaiData.nonce
                },
                timeout: 120000,
                success: function(response) {
                    if (response.success) {
                        showMessage('success', response.data.message);
                        updateImagePreviewAfterEdit(response.data);
                    } else {
                        showMessage('error', response.data.message || 'Błąd');
                    }
                },
                error: function(xhr, status) {
                    var msg = status === 'timeout' ? 'Przekroczono czas oczekiwania.' : 'Błąd połączenia.';
                    showMessage('error', msg);
                },
                complete: function() {
                    $upscaleBtn.prop('disabled', false).text('Powiększ');
                }
            });
        });

        // Edit toggle
        $editBtn.on('click', function(e) {
            e.preventDefault();
            $editWrap.slideToggle(200);
        });

        // Edit submit
        $editSubmit.on('click', function(e) {
            e.preventDefault();
            var postId = $(this).data('post-id');
            var attachmentId = $(this).data('attachment-id');
            var editPrompt = $('#aai-edit-prompt').val().trim();

            if (!postId || !attachmentId || !editPrompt) {
                showMessage('error', 'Opisz jakie zmiany chcesz wprowadzić.');
                return;
            }

            $editSubmit.prop('disabled', true).text('Edytowanie...');

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_edit_image',
                    post_id: postId,
                    attachment_id: attachmentId,
                    edit_prompt: editPrompt,
                    nonce: aaiData.nonce
                },
                timeout: 120000,
                success: function(response) {
                    if (response.success) {
                        showMessage('success', response.data.message);
                        updateImagePreviewAfterEdit(response.data);
                        $('#aai-edit-prompt').val('');
                        $editWrap.slideUp(200);
                    } else {
                        showMessage('error', response.data.message || 'Błąd');
                    }
                },
                error: function(xhr, status) {
                    var msg = status === 'timeout' ? 'Przekroczono czas oczekiwania.' : 'Błąd połączenia.';
                    showMessage('error', msg);
                },
                complete: function() {
                    $editSubmit.prop('disabled', false).text('Zastosuj edycję');
                }
            });
        });

        // Helper to update image preview after upscale/edit
        function updateImagePreviewAfterEdit(data) {
            if (data.image_url) {
                var $currentImage = $('#aai-current-image');
                var $img = $('<img>', {
                    src: data.image_url + '?t=' + Date.now(),
                    alt: '',
                    'class': 'aai-thumbnail-preview'
                });
                var $label = $('<p>', { 'class': 'aai-label', text: 'Aktualny Featured Image:' });
                $currentImage.empty().append($label).append($img);

                // Update attachment IDs on buttons
                $upscaleBtn.data('attachment-id', data.attachment_id);
                $editSubmit.data('attachment-id', data.attachment_id);

                // Refresh Gutenberg
                refreshGutenbergFeaturedImage(data.attachment_id);

                // Update tokens if available
                if (data.tokens) {
                    updateTokensDisplay(data.tokens);
                }
            }
        }
    }

    /**
     * WooCommerce Product Shots meta box
     */
    function initProductShots() {
        var $genBtn = $('#aai-ps-generate');
        if (!$genBtn.length) return;

        var $sourcePreview = $('#aai-ps-source-preview');
        var $sourceId = $('#aai-ps-source-id');
        var $scenes = $('#aai-ps-scenes');
        var $results = $('#aai-ps-results');
        var $message = $('#aai-ps-message');
        var $progressWrap = $('#aai-ps-progress');
        var $progressText = $('#aai-ps-progress-text');
        var productId = $genBtn.data('product-id');
        var frame;

        // Upload source image
        $('#aai-ps-upload-source').on('click', function (e) {
            e.preventDefault();
            if (frame) { frame.open(); return; }

            frame = wp.media({
                title: 'Wybierz zdjęcie produktu',
                button: { text: 'Użyj tego zdjęcia' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $sourceId.val(attachment.id);
                $sourcePreview.html('<img src="' + attachment.sizes.thumbnail.url + '" alt="" />');
            });

            frame.open();
        });

        // Add scene row
        $('#aai-ps-add-scene').on('click', function (e) {
            e.preventDefault();
            var count = $scenes.find('.aai-ps-scene-row').length;
            if (count >= 5) { return; }

            var $first = $scenes.find('.aai-ps-scene-row:first');
            var $clone = $first.clone();
            $clone.find('select').val('');
            $clone.find('textarea').val('');
            $clone.find('.aai-ps-scene-result').remove();
            $scenes.append($clone);
        });

        // Preset select fills textarea
        $scenes.on('change', '.aai-ps-preset', function () {
            var val = $(this).val();
            if (val) {
                $(this).closest('.aai-ps-scene-row').find('.aai-ps-scene-prompt').val(val);
            }
        });

        // Generate product shots
        $genBtn.on('click', function (e) {
            e.preventDefault();

            var sourceImageId = $sourceId.val();
            if (!sourceImageId) {
                $message.removeClass('success').addClass('error').text('Wybierz zdjęcie produktu.').fadeIn();
                return;
            }

            // Collect scene prompts
            var scenePrompts = [];
            $scenes.find('.aai-ps-scene-row').each(function () {
                var prompt = $(this).find('.aai-ps-scene-prompt').val().trim();
                if (prompt) {
                    scenePrompts.push(prompt);
                }
            });

            if (scenePrompts.length === 0) {
                $message.removeClass('success').addClass('error').text('Dodaj przynajmniej jedną scenę.').fadeIn();
                return;
            }

            $genBtn.prop('disabled', true);
            $genBtn.find('.aai-btn-text').text('Generowanie...');
            $genBtn.find('.aai-btn-spinner').show();
            $message.hide();
            $results.empty();
            $progressWrap.show();

            // Sequential generation per scene
            var completed = 0;
            function generateScene(index) {
                if (index >= scenePrompts.length) {
                    $progressWrap.hide();
                    $genBtn.prop('disabled', false);
                    $genBtn.find('.aai-btn-text').text('Generuj zdjęcia produktowe');
                    $genBtn.find('.aai-btn-spinner').hide();
                    $message.removeClass('error').addClass('success').text('Gotowe! Wygenerowano ' + completed + ' zdjęć.').fadeIn();
                    return;
                }

                $progressText.text('Generowanie sceny ' + (index + 1) + ' z ' + scenePrompts.length + '...');

                $.ajax({
                    url: aaiData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'aai_generate_product_shot',
                        product_id: productId,
                        source_image_id: sourceImageId,
                        scene_prompt: scenePrompts[index],
                        nonce: aaiData.nonce
                    },
                    timeout: 120000,
                    success: function (response) {
                        if (response.success) {
                            completed++;
                            var html = '<div class="aai-ps-result-card">';
                            html += '<img src="' + response.data.image_url + '" alt="" />';
                            html += '<div class="aai-ps-result-actions">';
                            html += '<button type="button" class="button button-small aai-ps-add-gallery" ' +
                                'data-attachment-id="' + response.data.attachment_id + '">' +
                                'Dodaj do galerii</button>';
                            html += '</div></div>';
                            $results.append(html);
                        } else {
                            $results.append(
                                '<div class="aai-ps-result-error">Scena ' + (index + 1) + ': ' +
                                escapeHtml(response.data.message || 'Błąd') + '</div>'
                            );
                        }
                    },
                    error: function () {
                        $results.append(
                            '<div class="aai-ps-result-error">Scena ' + (index + 1) + ': Błąd połączenia</div>'
                        );
                    },
                    complete: function () {
                        generateScene(index + 1);
                    }
                });
            }

            generateScene(0);
        });

        // Add to product gallery
        $results.on('click', '.aai-ps-add-gallery', function () {
            var $btn = $(this);
            var attachmentId = $btn.data('attachment-id');
            $btn.prop('disabled', true).text('Dodawanie...');

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_add_to_product_gallery',
                    product_id: productId,
                    attachment_id: attachmentId,
                    nonce: aaiData.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $btn.text('Dodano!').addClass('button-primary');
                    } else {
                        $btn.text('Błąd').prop('disabled', false);
                    }
                },
                error: function () {
                    $btn.text('Błąd').prop('disabled', false);
                }
            });
        });
    }

    /**
     * Generation Queue — find posts without images and generate
     */
    function initGenerationQueue() {
        var $scanBtn = $('#aai-queue-scan');
        if (!$scanBtn.length) return;

        var $list = $('#aai-queue-list');
        var $progress = $('#aai-queue-progress');
        var $fill = $('#aai-queue-fill');
        var $status = $('#aai-queue-status');
        var $actions = $('#aai-queue-actions');
        var $startBtn = $('#aai-queue-start');
        var $stopBtn = $('#aai-queue-stop');
        var $summary = $('#aai-queue-summary');
        var $overwrite = $('#aai-queue-overwrite');

        var postIds = [];
        var isStopped = false;

        // Scan for posts
        $scanBtn.on('click', function (e) {
            e.preventDefault();
            $scanBtn.prop('disabled', true).text('Skanowanie...');
            $list.empty();
            $actions.hide();
            $summary.hide();

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_find_posts_without_images',
                    overwrite: $overwrite.is(':checked') ? '1' : '0',
                    nonce: aaiData.nonce
                },
                timeout: 30000,
                success: function (response) {
                    if (response.success && response.data.posts.length > 0) {
                        postIds = [];
                        var html = '';
                        response.data.posts.forEach(function (p) {
                            postIds.push(p.id);
                            var thumbHtml = p.thumb_url
                                ? '<img src="' + p.thumb_url + '" alt="" class="aai-queue-thumb" />'
                                : '';
                            html += '<div class="aai-bulk-item" id="aai-queue-item-' + p.id + '">';
                            html += '<span class="aai-bulk-item-status">⏳</span>';
                            html += '<span class="aai-bulk-item-title">' + escapeHtml(p.title) + '</span>';
                            html += '<span class="aai-bulk-item-result"></span>';
                            html += '<span class="aai-bulk-item-thumb">' + thumbHtml + '</span>';
                            html += '</div>';
                        });
                        $list.html(html);
                        $actions.show();
                        $status.text('Znaleziono ' + postIds.length + ' postów.');
                        $progress.show();
                    } else if (response.success) {
                        $list.html('<p style="padding:10px;color:#64748b;">Nie znaleziono postów bez obrazka.</p>');
                    } else {
                        $list.html('<p style="padding:10px;color:#dc2626;">' + escapeHtml(response.data.message || 'Błąd') + '</p>');
                    }
                },
                error: function () {
                    $list.html('<p style="padding:10px;color:#dc2626;">Błąd połączenia.</p>');
                },
                complete: function () {
                    $scanBtn.prop('disabled', false).text('Znajdź posty bez obrazka');
                }
            });
        });

        // Start generation
        $startBtn.on('click', function () {
            if (!postIds.length) return;
            isStopped = false;
            $startBtn.hide();
            $stopBtn.show();
            $scanBtn.prop('disabled', true);
            $overwrite.prop('disabled', true);

            var stats = { success: 0, error: 0, skipped: 0 };
            var overwrite = $overwrite.is(':checked') ? '1' : '0';
            var startTime = Date.now();

            processQueue(0, stats, overwrite, startTime);
        });

        // Stop generation
        $stopBtn.on('click', function () {
            isStopped = true;
            $stopBtn.text('Zatrzymywanie...');
        });

        function processQueue(index, stats, overwrite, startTime) {
            if (index >= postIds.length || isStopped) {
                showQueueSummary(stats, postIds.length, isStopped);
                return;
            }

            var postId = postIds[index];
            var $item = $('#aai-queue-item-' + postId);
            var pct = Math.round((index / postIds.length) * 100);

            $fill.css('width', pct + '%');

            // Estimate remaining time
            var elapsed = (Date.now() - startTime) / 1000;
            var avgPerItem = index > 0 ? elapsed / index : 0;
            var remaining = avgPerItem * (postIds.length - index);
            var etaText = remaining > 60
                ? Math.round(remaining / 60) + ' min'
                : Math.round(remaining) + ' sek';
            $status.text('Generowanie ' + (index + 1) + ' z ' + postIds.length + '... (ETA: ~' + etaText + ')');

            $item.addClass('is-processing');
            $item.find('.aai-bulk-item-status').text('⚙️');

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_bulk_generate',
                    post_id: postId,
                    overwrite: overwrite,
                    nonce: aaiData.nonce
                },
                timeout: 180000,
                success: function (response) {
                    $item.removeClass('is-processing');
                    if (response.success) {
                        if (response.data.skipped) {
                            stats.skipped++;
                            $item.addClass('is-skipped');
                            $item.find('.aai-bulk-item-status').text('⏭️');
                            $item.find('.aai-bulk-item-result').text('Pominięto');
                        } else {
                            stats.success++;
                            $item.addClass('is-success');
                            $item.find('.aai-bulk-item-status').text('✅');
                            $item.find('.aai-bulk-item-result').text('Wygenerowano!');
                            if (response.data.image_url) {
                                $item.find('.aai-bulk-item-thumb').html(
                                    '<img src="' + response.data.image_url + '" alt="" />'
                                );
                            }
                        }
                    } else {
                        stats.error++;
                        $item.addClass('is-error');
                        $item.find('.aai-bulk-item-status').text('❌');
                        $item.find('.aai-bulk-item-result').text(response.data.message || 'Błąd');
                    }
                },
                error: function (xhr, status) {
                    stats.error++;
                    $item.removeClass('is-processing').addClass('is-error');
                    $item.find('.aai-bulk-item-status').text('❌');
                    $item.find('.aai-bulk-item-result').text(status === 'timeout' ? 'Timeout' : 'Błąd');
                },
                complete: function () {
                    processQueue(index + 1, stats, overwrite, startTime);
                }
            });
        }

        function showQueueSummary(stats, total, wasStopped) {
            var pct = wasStopped
                ? Math.round(((stats.success + stats.error + stats.skipped) / total) * 100)
                : 100;
            $fill.css('width', pct + '%');
            $status.text(wasStopped ? 'Przerwano!' : 'Zakończono!');

            var html = '<div class="aai-bulk-summary-grid">';
            html += '<div class="aai-bulk-stat aai-bulk-stat-success"><strong>' + stats.success + '</strong><span>Wygenerowano</span></div>';
            html += '<div class="aai-bulk-stat aai-bulk-stat-skipped"><strong>' + stats.skipped + '</strong><span>Pominięto</span></div>';
            html += '<div class="aai-bulk-stat aai-bulk-stat-error"><strong>' + stats.error + '</strong><span>Błędy</span></div>';
            html += '</div>';

            if (wasStopped) {
                html += '<p class="aai-bulk-cancelled">Proces został przerwany.</p>';
            }

            $summary.html(html).slideDown();
            $stopBtn.hide();
            $startBtn.hide();
            $scanBtn.prop('disabled', false);
            $overwrite.prop('disabled', false);
        }
    }

    /**
     * Standalone Image Generator (settings tab)
     */
    function initStandaloneGenerator() {
        var $btn = $('#aai-gen-submit');
        if (!$btn.length) return;

        var $prompt = $('#aai-gen-prompt');
        var $style = $('#aai-gen-style');
        var $ratio = $('#aai-gen-ratio');
        var $message = $('#aai-gen-message');
        var $result = $('#aai-gen-result');
        var $resultImg = $('#aai-gen-result-img');
        var $download = $('#aai-gen-download');
        var $mediaLink = $('#aai-gen-media-link');

        $btn.on('click', function (e) {
            e.preventDefault();

            var prompt = $prompt.val().trim();
            if (!prompt) {
                $message.removeClass('success error').addClass('error').text('Wpisz prompt.').fadeIn();
                return;
            }

            $btn.prop('disabled', true);
            $btn.find('.aai-btn-text').text('Generowanie...');
            $btn.find('.aai-btn-spinner').show();
            $message.hide();
            $result.hide();

            $.ajax({
                url: aaiData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'aai_generate_standalone',
                    prompt: prompt,
                    style: $style.val(),
                    aspect_ratio: $ratio.val(),
                    nonce: aaiData.nonce
                },
                timeout: 120000,
                success: function (response) {
                    if (response.success) {
                        $message.removeClass('error').addClass('success').text(response.data.message).fadeIn();
                        $resultImg.attr('src', response.data.image_url + '?t=' + Date.now());
                        $download.attr('href', response.data.image_url);
                        $mediaLink.attr('href', response.data.edit_url);
                        $result.fadeIn();
                    } else {
                        $message.removeClass('success').addClass('error').text(response.data.message || 'Błąd').fadeIn();
                    }
                },
                error: function (xhr, status) {
                    var msg = status === 'timeout' ? 'Przekroczono czas oczekiwania.' : 'Błąd połączenia.';
                    $message.removeClass('success').addClass('error').text(msg).fadeIn();
                },
                complete: function () {
                    $btn.prop('disabled', false);
                    $btn.find('.aai-btn-text').text('Generuj obrazek');
                    $btn.find('.aai-btn-spinner').hide();
                }
            });
        });

        // "Generate another" resets result view
        $('#aai-gen-another').on('click', function () {
            $result.fadeOut(200);
            $prompt.focus();
        });
    }

    /**
     * Watermark logo upload in settings
     */
    function initWatermarkUpload() {
        var $uploadBtn = $('#aai-upload-watermark');
        var $removeBtn = $('#aai-remove-watermark');
        var $preview = $('#aai-watermark-preview');
        var $input = $('#aai_watermark_logo');
        var frame;

        if (!$uploadBtn.length) return;

        $uploadBtn.on('click', function (e) {
            e.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Wybierz logo / watermark',
                button: { text: 'Użyj tego obrazka' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $input.val(attachment.url);
                $preview.html('<img src="' + attachment.url + '" alt="Watermark" />');

                // Show remove button if not present
                if (!$removeBtn.length) {
                    $uploadBtn.after(' <button type="button" id="aai-remove-watermark" class="button">Usuń</button>');
                    $removeBtn = $('#aai-remove-watermark');
                    bindRemove();
                }
            });

            frame.open();
        });

        function bindRemove() {
            $removeBtn.on('click', function (e) {
                e.preventDefault();
                $input.val('');
                $preview.empty();
                $(this).remove();
                $removeBtn = $();
            });
        }

        if ($removeBtn.length) {
            bindRemove();
        }
    }

    /**
     * Formatowanie informacji o tokenach
     */
    function formatTokenUsage(tokens) {
        if (!tokens || tokens.total_tokens === 0) {
            return '';
        }

        return '(Tokeny: ' + tokens.prompt_tokens + ' prompt + ' + tokens.completion_tokens + ' odpowiedź = ' + tokens.total_tokens + ' total)';
    }

    /**
     * Pobieranie etykiety źródła obrazka
     */
    function getSourceLabel(source) {
        var labels = {
            'ai_generated': 'AI Generated'
        };

        return labels[source] || source;
    }

    /**
     * Obsługa obrazków referencyjnych
     */
    function initReferenceImages() {
        var $container = $('#aai_reference_images_container');
        var $addBtn = $('#aai_add_reference_image');
        var frame;

        if (!$container.length) {
            return;
        }

        // Dodawanie obrazka
        $addBtn.on('click', function (e) {
            e.preventDefault();

            // Jeśli frame już istnieje, otwórz go
            if (frame) {
                frame.open();
                return;
            }

            // Utwórz nowy frame
            frame = wp.media({
                title: 'Wybierz obrazek referencyjny',
                button: {
                    text: 'Użyj tego obrazka'
                },
                multiple: false  // Na razie jeden po drugim, bo łatwiej obsłużyć limit
            });

            // Po wyborze
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var imageUrl = attachment.url;

                // Dodaj do listy
                addReferenceImage(imageUrl);
            });

            frame.open();
        });

        // Funkcja dodająca HTML
        function addReferenceImage(url) {
            var count = $container.find('.aai-reference-image-item').length;

            if (count >= 3) {
                alert('Limit 3 obrazków referencyjnych!');
                return;
            }

            var html = '<div class="aai-reference-image-item">';
            html += '<input type="hidden" name="aai_options[reference_images][]" value="' + url + '" />';
            html += '<img src="' + url + '" alt="Reference" />';
            html += '<button type="button" class="button aai-remove-reference-image" title="Usuń">×</button>';
            html += '</div>';

            $container.append(html);

            checkLimit();
        }

        // Usuwanie obrazka
        $container.on('click', '.aai-remove-reference-image', function () {
            $(this).closest('.aai-reference-image-item').remove();
            checkLimit();
        });

        // Sprawdzanie limitu i blokowanie przycisku
        function checkLimit() {
            var count = $container.find('.aai-reference-image-item').length;
            if (count >= 3) {
                $addBtn.prop('disabled', true);
            } else {
                $addBtn.prop('disabled', false);
            }
        }
    }

    /**
     * Aktualizacja wyświetlania tokenów w meta boxie
     */
    function updateTokensDisplay(tokens) {
        var $tokensDisplay = $('#aai-tokens-display');

        if (!$tokensDisplay.length) {
            // Dodaj element jeśli nie istnieje
            var $metaBox = $('.aai-meta-box-content');
            if ($metaBox.length) {
                $metaBox.append('<div id="aai-tokens-display" class="aai-tokens-display"></div>');
                $tokensDisplay = $('#aai-tokens-display');
            }
        }

        if ($tokensDisplay.length && tokens && tokens.total_tokens > 0) {
            $tokensDisplay.html(
                '<div class="aai-tokens-info">' +
                '<span class="aai-tokens-label">Ostatnie użycie tokenów:</span>' +
                '<span class="aai-tokens-value">' +
                '<span class="aai-token-item">Prompt: ' + tokens.prompt_tokens + '</span>' +
                '<span class="aai-token-item">Odpowiedź: ' + tokens.completion_tokens + '</span>' +
                '<span class="aai-token-item aai-token-total">Razem: ' + tokens.total_tokens + '</span>' +
                '</span>' +
                '</div>'
            ).fadeIn();
        }
    }

    /**
     * ============================
     * Bulk Generate Featured Images
     * ============================
     */
    function initBulkGenerate() {
        // Sprawdź czy jesteśmy po redirectcie z bulk action
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('aai_bulk_generate') !== '1') {
            return;
        }

        var postIdsStr = urlParams.get('aai_post_ids') || '';
        var nonce = aaiData.bulkNonce || urlParams.get('aai_nonce') || '';

        if (!postIdsStr || !nonce) {
            return;
        }

        var postIds = postIdsStr.split(',').map(function (id) { return parseInt(id, 10); }).filter(function (id) { return id > 0; });

        if (postIds.length === 0) {
            return;
        }

        // Wyczyść parametry z URL (żeby refresh nie uruchamiał ponownie)
        var cleanUrl = window.location.href.replace(/[?&]aai_bulk_generate=[^&]*/g, '')
            .replace(/[?&]aai_post_ids=[^&]*/g, '')
            .replace(/[?&]aai_nonce=[^&]*/g, '');
        if (cleanUrl.indexOf('?') === -1 && cleanUrl.indexOf('&') > -1) {
            cleanUrl = cleanUrl.replace('&', '?');
        }
        window.history.replaceState({}, '', cleanUrl);

        // Pobierz tytuły postów z tabeli
        var postTitles = {};
        postIds.forEach(function (id) {
            var $row = $('#post-' + id);
            if ($row.length) {
                postTitles[id] = $row.find('.row-title').text() || 'Post #' + id;
            } else {
                postTitles[id] = 'Post #' + id;
            }
        });

        // Uruchom modal
        showBulkModal(postIds, postTitles, nonce);
    }

    /**
     * Wyświetla modal z przebiegiem bulk generowania
     */
    function showBulkModal(postIds, postTitles, nonce) {
        var total = postIds.length;

        // Buduj HTML modala
        var html = '<div id="aai-bulk-overlay" class="aai-bulk-overlay">';
        html += '<div class="aai-bulk-modal">';

        // Header
        html += '<div class="aai-bulk-header">';
        html += '<h2>🎨 Generowanie AI Featured Images</h2>';
        html += '<span class="aai-bulk-count">' + total + ' wpisów</span>';
        html += '</div>';

        // Opcje
        html += '<div class="aai-bulk-options">';
        html += '<label><input type="checkbox" id="aai-bulk-overwrite" /> Nadpisz istniejące featured images</label>';
        html += '</div>';

        // Progress
        html += '<div class="aai-bulk-progress">';
        html += '<div class="aai-bulk-progress-bar"><div class="aai-bulk-progress-fill" id="aai-bulk-fill"></div></div>';
        html += '<div class="aai-bulk-progress-text" id="aai-bulk-status">Gotowe do rozpoczęcia</div>';
        html += '</div>';

        // Lista postów
        html += '<div class="aai-bulk-list" id="aai-bulk-list">';
        postIds.forEach(function (id) {
            html += '<div class="aai-bulk-item" data-post-id="' + id + '" id="aai-bulk-item-' + id + '">';
            html += '<span class="aai-bulk-item-status">⏳</span>';
            html += '<span class="aai-bulk-item-title">' + escapeHtml(postTitles[id]) + '</span>';
            html += '<span class="aai-bulk-item-result"></span>';
            html += '<span class="aai-bulk-item-thumb"></span>';
            html += '</div>';
        });
        html += '</div>';

        // Podsumowanie (ukryte do końca)
        html += '<div class="aai-bulk-summary" id="aai-bulk-summary" style="display:none;"></div>';

        // Przyciski
        html += '<div class="aai-bulk-actions">';
        html += '<button type="button" class="button" id="aai-bulk-cancel">Anuluj</button>';
        html += '<button type="button" class="button button-primary" id="aai-bulk-start">Rozpocznij generowanie</button>';
        html += '</div>';

        html += '</div>'; // modal
        html += '</div>'; // overlay

        $('body').append(html);

        // Obsługa przycisków
        var isRunning = false;
        var isCancelled = false;

        $('#aai-bulk-start').on('click', function () {
            if (isRunning) return;
            isRunning = true;
            isCancelled = false;
            $(this).prop('disabled', true).text('Generowanie...');
            $('#aai-bulk-cancel').text('Zatrzymaj');
            $('#aai-bulk-overwrite').prop('disabled', true);

            var overwrite = $('#aai-bulk-overwrite').is(':checked') ? '1' : '0';
            processBulkQueue(postIds, nonce, overwrite, 0, { success: 0, error: 0, skipped: 0 }, function () {
                isRunning = false;
            }, function () { return isCancelled; });
        });

        $('#aai-bulk-cancel').on('click', function () {
            if (isRunning) {
                isCancelled = true;
                $(this).text('Zatrzymywanie...');
            } else {
                $('#aai-bulk-overlay').remove();
            }
        });
    }

    /**
     * Przetwarza kolejkę postów sekwencyjnie
     */
    function processBulkQueue(postIds, nonce, overwrite, index, stats, onComplete, isCancelled) {
        var total = postIds.length;

        if (index >= total || isCancelled()) {
            // Zakończono
            showBulkSummary(stats, total, isCancelled());
            onComplete();
            return;
        }

        var postId = postIds[index];
        var $item = $('#aai-bulk-item-' + postId);
        var progress = Math.round(((index) / total) * 100);

        // Aktualizuj UI
        $('#aai-bulk-fill').css('width', progress + '%');
        $('#aai-bulk-status').text('Generowanie ' + (index + 1) + ' z ' + total + '...');
        $item.addClass('is-processing');
        $item.find('.aai-bulk-item-status').text('⚙️');

        // Scroll do aktualnego elementu
        var $list = $('#aai-bulk-list');
        var itemTop = $item.position().top + $list.scrollTop() - $list.height() / 2;
        $list.animate({ scrollTop: Math.max(0, itemTop) }, 200);

        $.ajax({
            url: aaiData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aai_bulk_generate',
                post_id: postId,
                overwrite: overwrite,
                nonce: nonce
            },
            timeout: 180000, // 3 min per post
            success: function (response) {
                $item.removeClass('is-processing');
                if (response.success) {
                    if (response.data.skipped) {
                        stats.skipped++;
                        $item.addClass('is-skipped');
                        $item.find('.aai-bulk-item-status').text('⏭️');
                        $item.find('.aai-bulk-item-result').text('Pominięto');
                    } else {
                        stats.success++;
                        $item.addClass('is-success');
                        $item.find('.aai-bulk-item-status').text('✅');
                        $item.find('.aai-bulk-item-result').text('Wygenerowano!');
                        if (response.data.image_url) {
                            $item.find('.aai-bulk-item-thumb').html(
                                '<img src="' + response.data.image_url + '" alt="" />'
                            );
                        }
                    }
                } else {
                    stats.error++;
                    $item.addClass('is-error');
                    $item.find('.aai-bulk-item-status').text('❌');
                    $item.find('.aai-bulk-item-result').text(response.data.message || 'Błąd');
                }
            },
            error: function (xhr, status) {
                stats.error++;
                $item.removeClass('is-processing').addClass('is-error');
                $item.find('.aai-bulk-item-status').text('❌');
                var msg = status === 'timeout' ? 'Timeout' : 'Błąd połączenia';
                $item.find('.aai-bulk-item-result').text(msg);
            },
            complete: function () {
                // Następny w kolejce
                processBulkQueue(postIds, nonce, overwrite, index + 1, stats, onComplete, isCancelled);
            }
        });
    }

    /**
     * Wyświetla podsumowanie po zakończeniu
     */
    function showBulkSummary(stats, total, wasCancelled) {
        var progress = wasCancelled ? Math.round(((stats.success + stats.error + stats.skipped) / total) * 100) : 100;
        $('#aai-bulk-fill').css('width', progress + '%');

        var statusText = wasCancelled ? 'Przerwano!' : 'Zakończono!';
        $('#aai-bulk-status').text(statusText);

        var summaryHtml = '<div class="aai-bulk-summary-grid">';
        summaryHtml += '<div class="aai-bulk-stat aai-bulk-stat-success"><strong>' + stats.success + '</strong><span>Wygenerowano</span></div>';
        summaryHtml += '<div class="aai-bulk-stat aai-bulk-stat-skipped"><strong>' + stats.skipped + '</strong><span>Pominięto</span></div>';
        summaryHtml += '<div class="aai-bulk-stat aai-bulk-stat-error"><strong>' + stats.error + '</strong><span>Błędy</span></div>';
        summaryHtml += '</div>';

        if (wasCancelled) {
            summaryHtml += '<p class="aai-bulk-cancelled">Proces został przerwany przez użytkownika.</p>';
        }

        $('#aai-bulk-summary').html(summaryHtml).slideDown();

        // Zmień przyciski
        $('#aai-bulk-start').hide();
        $('#aai-bulk-cancel').text('Zamknij').off('click').on('click', function () {
            $('#aai-bulk-overlay').remove();
            // Odśwież stronę żeby zobaczyć nowe thumbnails
            window.location.reload();
        });
    }

    /**
     * Escape HTML w stringach
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

})(jQuery);
