/**
 * JavaScript dla panelu admina wtyczki AI Images
 */

(function ($) {
    'use strict';

    /**
     * Inicjalizacja po załadowaniu DOM
     */
    $(document).ready(function () {
        initBulkGenerate();
        initReferenceImages();
        initGenerateButton();
        initTestConnection();
        initTestOpenAIConnection();
        initStyleToggle();
        initColorPickers();
        initPasswordToggle();
        initPromptToggle();
    });

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
     * Toggle podglądu promptu (tryb debug)
     */
    function initPromptToggle() {
        $('#aai-toggle-prompt').on('click', function (e) {
            e.preventDefault();
            $('#aai-prompt-preview').slideToggle();
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
