/**
 * JavaScript dla panelu admina wtyczki Agencyjnie AI Images
 */

(function($) {
    'use strict';

    /**
     * Inicjalizacja po załadowaniu DOM
     */
    $(document).ready(function() {
        initGenerateButton();
        initGenerateContentButton();
        initTestConnection();
        initTestOpenAIConnection();
        initApiTests(); // Testy dla pozostałych API
        initStyleToggle();
        initColorPickers();
        initPasswordToggle();
        initPromptToggle();
        initSourceToggles();
    });

    /**
     * Obsługa przycisku generowania obrazka
     */
    function initGenerateButton() {
        var $btn = $('#aai-generate-btn');
        
        if (!$btn.length) {
            return;
        }

        $btn.on('click', function(e) {
            e.preventDefault();
            
            var postId = $btn.data('post-id');
            var hasThumbnail = $btn.data('has-thumbnail') === 1 || $btn.data('has-thumbnail') === '1';
            
            // Sprawdź czy post jest zapisany
            if (!postId || postId === 0) {
                showMessage('error', aaiData.strings.error + ' Post musi być najpierw zapisany.');
                return;
            }
            
            // Jeśli ma miniaturkę, to jest to akcja regeneracji
            if (hasThumbnail) {
                // Potwierdź tylko jeśli użytkownik nie jest pewien (opcjonalne, tutaj pomijamy dla "Regeneruj")
                // generateImage(postId, true); 
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
        
        // Wykonaj request AJAX
        $.ajax({
            url: aaiData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aai_generate_image',
                post_id: postId,
                nonce: aaiData.nonce
            },
            timeout: 120000, // 2 minuty timeout
            success: function(response) {
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
                        var sourceIndicator = '';
                        if (response.data.source) {
                            sourceIndicator = '<span class="aai-source-indicator source-' + response.data.source + '">' + 
                                getSourceLabel(response.data.source) + '</span>';
                        }
                        
                        $currentImage.html(
                            '<p class="aai-label">Aktualny Featured Image:</p>' +
                            '<img src="' + response.data.image_url + '?t=' + Date.now() + '" alt="" class="aai-thumbnail-preview" />' +
                            sourceIndicator
                        );
                        
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
            error: function(xhr, status, error) {
                var errorMessage = aaiData.strings.error;
                
                if (status === 'timeout') {
                    errorMessage = 'Przekroczono czas oczekiwania. Generowanie obrazka trwa zbyt długo.';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }
                
                showMessage('error', errorMessage);
                $btn.find('.aai-btn-text').text(btnOriginalText);
            },
            complete: function() {
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
            setTimeout(function() {
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

        $btn.on('click', function(e) {
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
                success: function(response) {
                    $result.removeClass('loading');
                    if (response.success) {
                        $result.addClass('success').text('✓ ' + response.data.message);
                    } else {
                        $result.addClass('error').text('✗ ' + response.data.message);
                    }
                },
                error: function(xhr, status) {
                    $result.removeClass('loading').addClass('error');
                    if (status === 'timeout') {
                        $result.text('✗ Przekroczono czas oczekiwania');
                    } else {
                        $result.text('✗ Błąd połączenia z serwerem');
                    }
                },
                complete: function() {
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

        $btn.on('click', function(e) {
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
                success: function(response) {
                    $result.removeClass('loading');
                    if (response.success) {
                        $result.addClass('success').text('✓ ' + response.data.message);
                    } else {
                        $result.addClass('error').text('✗ ' + response.data.message);
                    }
                },
                error: function(xhr, status) {
                    $result.removeClass('loading').addClass('error');
                    if (status === 'timeout') {
                        $result.text('✗ Przekroczono czas oczekiwania');
                    } else {
                        $result.text('✗ Błąd połączenia z serwerem');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Testuj');
                }
            });
        });
    }

    /**
     * Testy dla pozostałych API (Urlbox, Unsplash, Pexels, Brandfetch)
     */
    function initApiTests() {
        // Konfiguracja testów API
        var apiTests = [
            { btn: '#aai_test_urlbox', input: '#aai_urlbox_api_key', result: '#aai_test_urlbox_result', action: 'aai_test_urlbox' },
            { btn: '#aai_test_unsplash', input: '#aai_unsplash_api_key', result: '#aai_test_unsplash_result', action: 'aai_test_unsplash' },
            { btn: '#aai_test_pexels', input: '#aai_pexels_api_key', result: '#aai_test_pexels_result', action: 'aai_test_pexels' },
            { btn: '#aai_test_brandfetch', input: '#aai_brandfetch_api_key', result: '#aai_test_brandfetch_result', action: 'aai_test_brandfetch' }
        ];
        
        apiTests.forEach(function(test) {
            var $btn = $(test.btn);
            
            if (!$btn.length) {
                return;
            }
            
            $btn.on('click', function(e) {
                e.preventDefault();
                
                var $result = $(test.result);
                var apiKey = $(test.input).val();
                
                if (!apiKey || !apiKey.trim()) {
                    $result
                        .removeClass('success loading')
                        .addClass('error')
                        .text('✗ Wprowadź klucz API')
                        .show();
                    return;
                }
                
                $btn.prop('disabled', true).text('Testowanie...');
                $result
                    .removeClass('success error')
                    .addClass('loading')
                    .text('Sprawdzanie...')
                    .show();
                
                $.ajax({
                    url: aaiData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: test.action,
                        nonce: aaiData.nonce,
                        api_key: apiKey
                    },
                    timeout: 30000,
                    success: function(response) {
                        $result.removeClass('loading');
                        if (response.success) {
                            $result.addClass('success').text('✓ ' + response.data.message);
                        } else {
                            $result.addClass('error').text('✗ ' + response.data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        $result.removeClass('loading').addClass('error');
                        if (status === 'timeout') {
                            $result.text('✗ Przekroczono czas oczekiwania');
                        } else {
                            // Dodaj więcej szczegółów do błędu
                            var errorDetail = '';
                            if (xhr.responseText) {
                                try {
                                    var resp = JSON.parse(xhr.responseText);
                                    if (resp.data && resp.data.message) {
                                        errorDetail = resp.data.message;
                                    }
                                } catch(e) {
                                    errorDetail = xhr.responseText.substring(0, 150);
                                }
                            }
                            if (errorDetail) {
                                $result.text('✗ ' + errorDetail);
                            } else {
                                $result.text('✗ Błąd (status: ' + xhr.status + '): ' + status + ' - ' + (error || 'sprawdź konsolę'));
                                console.log('AAI API Test Error:', {status: status, error: error, xhr: xhr});
                            }
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Testuj');
                    }
                });
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

        $select.on('change', function() {
            if ($(this).val() === 'custom') {
                $customWrapper.slideDown();
            } else {
                $customWrapper.slideUp();
            }
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
        $addBtn.on('click', function(e) {
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
        $container.on('click', '.aai-remove-color', function(e) {
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
        $('.aai-toggle-password').on('click', function(e) {
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
     * Toggle podglądu promptu (tryb debug)
     */
    function initPromptToggle() {
        $('#aai-toggle-prompt').on('click', function(e) {
            e.preventDefault();
            $('#aai-prompt-preview').slideToggle();
        });
    }

    /**
     * Toggle dla checkboxów źródeł obrazków
     */
    function initSourceToggles() {
        $('.aai-source-toggle').on('change', function() {
            var targetId = $(this).data('target');
            var $target = $('#' + targetId);
            
            if ($(this).is(':checked')) {
                $target.slideDown();
            } else {
                $target.slideUp();
            }
        });
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
            'media_library': 'Media Library',
            'screenshot': 'Screenshot',
            'brandfetch': 'Brandfetch',
            'unsplash': 'Unsplash',
            'pexels': 'Pexels',
            'ai_generated': 'AI Generated'
        };
        
        return labels[source] || source;
    }

    /**
     * Podsumowanie użytych źródeł obrazków
     */
    function getSourceSummary(images) {
        if (!images || images.length === 0) {
            return '';
        }
        
        var sourceCounts = {};
        
        images.forEach(function(img) {
            if (img.success && img.source) {
                sourceCounts[img.source] = (sourceCounts[img.source] || 0) + 1;
            }
        });
        
        var parts = [];
        for (var source in sourceCounts) {
            parts.push(getSourceLabel(source) + ': ' + sourceCounts[source]);
        }
        
        if (parts.length === 0) {
            return '';
        }
        
        return '(Źródła: ' + parts.join(', ') + ')';
    }

    /**
     * Obsługa przycisku generowania obrazków w treści
     */
    function initGenerateContentButton() {
        var $btn = $('#aai-generate-content-btn');
        
        if (!$btn.length) {
            return;
        }

        $btn.on('click', function(e) {
            e.preventDefault();
            
            var postId = $btn.data('post-id');
            var hasImages = $btn.data('has-images') === 1 || $btn.data('has-images') === '1';
            
            // Sprawdź czy post jest zapisany
            if (!postId || postId === 0) {
                showContentMessage('error', 'Post musi być najpierw zapisany.');
                return;
            }
            
            // Sprawdź czy post ma treść (w Gutenbergu)
            if (typeof wp !== 'undefined' && wp.data && wp.data.select) {
                try {
                    var content = wp.data.select('core/editor').getEditedPostContent();
                    if (!content || content.trim().length < 100) {
                        showContentMessage('error', 'Post musi mieć więcej treści (min. 100 znaków).');
                        return;
                    }
                } catch(e) {
                    // Ignoruj - klasyczny edytor
                }
            }
            
            if (hasImages) {
                if (!confirm('Regenerujesz wszystkie obrazki w treści. Stare obrazki pozostaną w bibliotece mediów. Kontynuować?')) {
                    return;
                }
            }
            
            // Rozpocznij wieloetapowy proces
            generateContentImages(postId, hasImages);
        });
    }

    /**
     * Wieloetapowy proces generowania obrazków w treści
     * @param {number} postId - ID posta
     * @param {boolean} isRegenerate - Czy to regeneracja
     */
    function generateContentImages(postId, isRegenerate) {
        var $btn = $('#aai-generate-content-btn');
        var $progress = $('#aai-content-progress');
        var $progressFill = $('#aai-progress-fill');
        var $progressText = $('#aai-progress-text');
        var btnOriginalText = $btn.find('.aai-btn-text').text();
        
        // Stan procesu
        var state = {
            locations: [],
            generatedImages: [],
            currentIndex: 0,
            totalTokens: 0
        };
        
        // Pokaż stan ładowania
        $btn.addClass('is-loading');
        $btn.find('.aai-btn-text').text('Analizowanie...');
        $progress.show();
        $progressFill.addClass('is-active').css('width', '10%');
        $progressText.text('Analizowanie treści artykułu...');
        showContentMessage('info', 'Trwa analiza treści...');
        
        // Krok 1: Analiza treści
        $.ajax({
            url: aaiData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aai_generate_content_images',
                post_id: postId,
                step: 'analyze',
                nonce: aaiData.nonce
            },
            timeout: 60000,
            success: function(response) {
                if (response.success) {
                    state.locations = response.data.locations;
                    $progressFill.css('width', '20%');
                    $progressText.text('Analiza zakończona. Przygotowywanie podglądu...');
                    showContentMessage('info', response.data.message);
                    
                    // Pokaż UI do przeglądu
                    renderReviewUI(state.locations, $btn, $progress, $progressFill, $progressText, state, postId, btnOriginalText);
                } else {
                    handleContentError(response.data.message, $btn, $progress, $progressFill, btnOriginalText);
                }
            },
            error: function(xhr, status) {
                var msg = status === 'timeout' ? 'Przekroczono czas analizy.' : 'Błąd połączenia.';
                handleContentError(msg, $btn, $progress, $progressFill, btnOriginalText);
            }
        });
    }

    /**
     * Wyświetla interfejs przeglądu i edycji promptów
     */
    function renderReviewUI(locations, $btn, $progress, $progressFill, $progressText, state, postId, btnOriginalText) {
        // Ukryj progress bar na chwilę
        $progress.hide();
        $btn.removeClass('is-loading');
        $btn.find('.aai-btn-text').text(btnOriginalText);
        
        // Usuń stary panel jeśli istnieje
        $('#aai-review-panel').remove();
        
        var html = '<div id="aai-review-panel" class="aai-review-panel">';
        html += '<div class="aai-review-title">';
        html += '<span>Znaleziono ' + locations.length + ' miejsc na obrazki. Edytuj prompty:</span>';
        html += '</div>';
        
        html += '<div class="aai-review-list">';
        
        locations.forEach(function(loc, index) {
            html += '<div class="aai-review-item" data-index="' + index + '">';
            
            // Header
            html += '<div class="aai-review-item-header">';
            html += '<span class="aai-review-location">Po paragrafie ' + loc.paragraph_index + '</span>';
            html += '<span class="dashicons dashicons-trash aai-review-remove" title="Usuń ten obrazek"></span>';
            html += '</div>';
            
            // Prompt
            html += '<label class="aai-review-prompt-label">Prompt:</label>';
            html += '<textarea class="aai-review-prompt">' + loc.prompt + '</textarea>';
            
            // Negative Prompt
            html += '<label class="aai-review-prompt-label" style="margin-top:5px;">Negative Prompt (opcjonalne):</label>';
            html += '<input type="text" class="aai-review-negative-prompt" value="" placeholder="np. text, watermark, blurry" />';
            
            // Meta (Keywords & Brands)
            html += '<div class="aai-review-meta">';
            
            if (loc.keywords && loc.keywords.length > 0) {
                loc.keywords.forEach(function(kw) {
                    html += '<span class="aai-review-tag" title="Słowo kluczowe">' + kw + '</span>';
                });
            }
            
            if (loc.detected_brands && loc.detected_brands.length > 0) {
                loc.detected_brands.forEach(function(brand) {
                    html += '<span class="aai-review-tag" style="background:#e3f2fd;color:#1565c0;" title="Wykryta marka">' + brand + '</span>';
                });
            }
            
            html += '</div>'; // end meta
            
            html += '</div>'; // end item
        });
        
        html += '</div>'; // end list
        
        html += '<div class="aai-review-actions">';
        html += '<button type="button" class="button button-large aai-review-btn-cancel">Anuluj</button>';
        html += '<button type="button" class="button button-primary button-large aai-review-btn-confirm">Zatwierdź i Generuj (' + locations.length + ')</button>';
        html += '</div>';
        
        html += '</div>'; // end panel
        
        // Wstaw panel po przycisku
        $('#aai-generate-content-btn').after(html);
        
        // Obsługa zdarzeń w panelu
        var $panel = $('#aai-review-panel');
        
        // Usuwanie elementu
        $panel.on('click', '.aai-review-remove', function() {
            $(this).closest('.aai-review-item').remove();
            updateConfirmButton();
        });
        
        // Anulowanie
        $panel.on('click', '.aai-review-btn-cancel', function() {
            $panel.remove();
            $progress.hide();
            showContentMessage('info', 'Anulowano generowanie.');
        });
        
        // Zatwierdzenie
        $panel.on('click', '.aai-review-btn-confirm', function() {
            var newLocations = [];
            
            $panel.find('.aai-review-item').each(function() {
                var index = $(this).data('index');
                var newPrompt = $(this).find('.aai-review-prompt').val();
                var newNegativePrompt = $(this).find('.aai-review-negative-prompt').val();
                var originalLoc = locations[index];
                
                // Klonuj obiekt
                var newLoc = $.extend({}, originalLoc);
                newLoc.prompt = newPrompt;
                
                // Dodaj negative prompt do promptu jeśli istnieje
                if (newNegativePrompt && newNegativePrompt.trim() !== '') {
                    newLoc.prompt += "\n\nIMPORTANT - DO NOT INCLUDE / NEGATIVE PROMPT: " + newNegativePrompt;
                }
                
                newLocations.push(newLoc);
            });
            
            if (newLocations.length === 0) {
                alert('Musisz zostawić przynajmniej jeden obrazek do wygenerowania.');
                return;
            }
            
            // Zaktualizuj stan
            state.locations = newLocations;
            state.currentIndex = 0; // Reset indeksu
            
            // Usuń panel
            $panel.remove();
            
            // Przywróć progress bar
            $progress.show();
            $progressText.text('Rozpoczynanie generowania...');
            $btn.addClass('is-loading');
            $btn.find('.aai-btn-text').text('Generowanie...');
            
            // Start pętli
            generateNextImage(postId, state, $btn, $progress, $progressFill, $progressText, btnOriginalText);
        });
        
        function updateConfirmButton() {
            var count = $panel.find('.aai-review-item').length;
            $panel.find('.aai-review-btn-confirm').text('Zatwierdź i Generuj (' + count + ')');
        }
    }

    /**
     * Generowanie kolejnego obrazka
     */
    function generateNextImage(postId, state, $btn, $progress, $progressFill, $progressText, btnOriginalText) {
        if (state.currentIndex >= state.locations.length) {
            // Wszystkie obrazki wygenerowane - wstaw do treści
            insertImagesIntoContent(postId, state, $btn, $progress, $progressFill, $progressText, btnOriginalText);
            return;
        }
        
        var location = state.locations[state.currentIndex];
        var progressPercent = 20 + ((state.currentIndex + 1) / state.locations.length * 60);
        
        $progressFill.css('width', progressPercent + '%');
        $progressText.text('Generowanie obrazka ' + (state.currentIndex + 1) + '/' + state.locations.length + '...');
        $btn.find('.aai-btn-text').text('Generowanie ' + (state.currentIndex + 1) + '/' + state.locations.length);
        
        $.ajax({
            url: aaiData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aai_generate_content_images',
                post_id: postId,
                step: 'generate',
                location: location,
                nonce: aaiData.nonce
            },
            timeout: 120000,
            success: function(response) {
                if (response.success && response.data.result) {
                    state.generatedImages.push(response.data.result);
                    console.log('AAI: Obrazek ' + (state.currentIndex + 1) + ' wygenerowany pomyślnie', response.data.result);
                    
                    // Dodaj tokeny
                    if (response.data.result.tokens) {
                        state.totalTokens += response.data.result.tokens.total_tokens || 0;
                    }
                } else {
                    // Dodaj info o nieudanym obrazku do logów
                    var errorMsg = response.data && response.data.message ? response.data.message : 'Nieznany błąd';
                    console.warn('AAI: Błąd generowania obrazka ' + (state.currentIndex + 1) + '/' + state.locations.length + ': ' + errorMsg);
                    
                    // Dodaj placeholder żeby wiedzieć że próbowano
                    state.generatedImages.push({
                        success: false,
                        error: errorMsg,
                        paragraph_index: state.locations[state.currentIndex].paragraph_index
                    });
                }
                
                state.currentIndex++;
                generateNextImage(postId, state, $btn, $progress, $progressFill, $progressText, btnOriginalText);
            },
            error: function(xhr, status, error) {
                console.error('AAI: Błąd AJAX dla obrazka ' + (state.currentIndex + 1) + ': ' + status + ' - ' + error);
                
                // Dodaj placeholder
                state.generatedImages.push({
                    success: false,
                    error: 'Błąd połączenia: ' + status,
                    paragraph_index: state.locations[state.currentIndex].paragraph_index
                });
                
                state.currentIndex++;
                generateNextImage(postId, state, $btn, $progress, $progressFill, $progressText, btnOriginalText);
            }
        });
    }

    /**
     * Wstawianie obrazków do treści
     */
    function insertImagesIntoContent(postId, state, $btn, $progress, $progressFill, $progressText, btnOriginalText) {
        // Policz udane i nieudane
        var successCount = state.generatedImages.filter(function(img) { return img.success === true; }).length;
        var failedCount = state.generatedImages.filter(function(img) { return img.success === false; }).length;
        
        console.log('AAI: Podsumowanie generowania - udane: ' + successCount + ', nieudane: ' + failedCount);
        
        if (successCount === 0) {
            var errorDetails = state.generatedImages.map(function(img) {
                return img.error || 'nieznany błąd';
            }).join('; ');
            handleContentError('Nie udało się wygenerować żadnego obrazka. Błędy: ' + errorDetails, $btn, $progress, $progressFill, btnOriginalText);
            return;
        }
        
        $progressFill.css('width', '90%');
        $progressText.text('Wstawianie ' + successCount + ' obrazków do treści...');
        $btn.find('.aai-btn-text').text('Wstawianie...');
        
        // Przygotuj dane obrazków do wstawienia (tylko udane)
        var imagesToInsert = state.generatedImages.filter(function(img) {
            return img.success === true;
        }).map(function(img) {
            return {
                paragraph_index: img.paragraph_index,
                position: img.position,
                attachment_id: img.attachment_id
            };
        });
        
        // Loguj nieudane obrazki
        if (failedCount > 0) {
            console.warn('AAI: ' + failedCount + ' obrazków nie udało się wygenerować:', 
                state.generatedImages.filter(function(img) { return img.success === false; }));
        }
        
        $.ajax({
            url: aaiData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aai_generate_content_images',
                post_id: postId,
                step: 'insert',
                images: imagesToInsert,
                nonce: aaiData.nonce
            },
            timeout: 30000,
            success: function(response) {
                $progressFill.removeClass('is-active').css('width', '100%');
                
                if (response.success) {
                    var msg = response.data.message;
                    
                    // Dodaj info o nieudanych jeśli były
                    if (failedCount > 0) {
                        msg += ' (' + failedCount + ' nie udało się wygenerować)';
                    }
                    
                    // Pokaż podsumowanie źródeł
                    var sourceSummary = getSourceSummary(state.generatedImages);
                    if (sourceSummary) {
                        msg += ' ' + sourceSummary;
                    }
                    
                    if (state.totalTokens > 0) {
                        msg += ' (Użyto tokenów: ' + state.totalTokens + ')';
                    }
                    showContentMessage(failedCount > 0 ? 'info' : 'success', msg);
                    $progressText.text('Gotowe!');
                    
                    // Zaktualizuj stan przycisku
                    $btn.data('has-images', '1');
                    $btn.find('.aai-btn-text').text('Regeneruj obrazki w treści');
                    
                    // Odśwież edytor Gutenberga
                    refreshGutenbergContent();
                } else {
                    showContentMessage('error', response.data.message);
                    $progressText.text('Błąd wstawiania');
                }
                
                // Przywróć przycisk
                resetContentButton($btn, $progress, btnOriginalText);
            },
            error: function() {
                handleContentError('Błąd wstawiania obrazków.', $btn, $progress, $progressFill, btnOriginalText);
            }
        });
    }

    /**
     * Obsługa błędu w procesie generowania treści
     */
    function handleContentError(message, $btn, $progress, $progressFill, btnOriginalText) {
        showContentMessage('error', message);
        $progressFill.removeClass('is-active').css('width', '0%');
        resetContentButton($btn, $progress, btnOriginalText);
    }

    /**
     * Reset przycisku po zakończeniu
     */
    function resetContentButton($btn, $progress, btnOriginalText) {
        $btn.removeClass('is-loading');
        // Jeśli nie zmieniliśmy tekstu dynamicznie na "Regeneruj", przywróć oryginał
        if ($btn.find('.aai-btn-text').text().indexOf('Regeneruj') === -1) {
             $btn.find('.aai-btn-text').text('Generuj obrazki w treści'); // Domyślny fallback
        }
        
        setTimeout(function() {
            $progress.fadeOut();
        }, 3000);
    }

    /**
     * Wyświetlanie komunikatów dla sekcji obrazków w treści
     */
    function showContentMessage(type, message) {
        var $message = $('#aai-content-message');
        
        $message
            .removeClass('success error info')
            .addClass(type)
            .text(message)
            .fadeIn();
        
        if (type === 'success') {
            setTimeout(function() {
                $message.fadeOut();
            }, 15000);
        }
    }

    /**
     * Odświeżenie treści w edytorze Gutenberga
     */
    function refreshGutenbergContent() {
        if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
            try {
                // Wymuś odświeżenie posta
                wp.data.dispatch('core/editor').refreshPost();
            } catch (e) {
                console.log('AAI: Could not refresh Gutenberg content', e);
                // Pokaż komunikat o konieczności odświeżenia
                showContentMessage('info', 'Odśwież stronę, aby zobaczyć wstawione obrazki.');
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

})(jQuery);
