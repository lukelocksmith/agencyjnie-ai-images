/**
 * AI Image Planner — PluginDocumentSettingPanel
 *
 * Analyzes article structure via Gemini Flash and suggests where to insert
 * aai/ai-image blocks with pre-filled prompts, content types, and art styles.
 */
(function (wp) {
    'use strict';

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useRef = wp.element.useRef;
    var useSelect = wp.data.useSelect;
    var __ = wp.i18n.__;

    var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var CheckboxControl = wp.components.CheckboxControl;
    var TextareaControl = wp.components.TextareaControl;
    var SelectControl = wp.components.SelectControl;

    // Options from PHP (shared with AI Image block via aaiBlockData)
    var CONTENT_TYPE_OPTIONS = window.aaiBlockData?.contentTypes || [];
    var ART_STYLE_OPTIONS = window.aaiBlockData?.artStyles || [];

    // Content type label map for display
    var contentTypeLabelMap = {};
    CONTENT_TYPE_OPTIONS.forEach(function (opt) {
        contentTypeLabelMap[opt.value] = opt.label;
    });

    var artStyleLabelMap = {};
    ART_STYLE_OPTIONS.forEach(function (opt) {
        artStyleLabelMap[opt.value] = opt.label;
    });

    /**
     * Block type to section type mapping
     */
    var BLOCK_TYPE_MAP = {
        'core/heading':   'HEADING',
        'core/paragraph': 'PARAGRAPH',
        'core/list':      'LIST',
        'core/quote':     'QUOTE',
        'core/table':     'TABLE',
    };

    /**
     * Extract plain text from a block's inner content
     */
    function getBlockText(block) {
        // For list blocks, extract items from inner blocks
        if (block.name === 'core/list' && block.innerBlocks.length > 0) {
            return block.innerBlocks.map(function (item) {
                var content = item.attributes.content || '';
                return '- ' + content.replace(/<[^>]+>/g, '');
            }).join('\n');
        }

        // For standard blocks, use the content attribute
        var content = block.attributes.content || block.attributes.citation || '';
        return content.replace(/<[^>]+>/g, '').trim();
    }

    /**
     * Parse HTML string (from Classic/freeform block) into sections
     * Uses DOMParser (sandboxed, no script execution) instead of innerHTML
     */
    function parseFreeformHtml(html, clientId) {
        var sections = [];
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var body = doc.body;

        var TAG_MAP = {
            'H1': 'HEADING', 'H2': 'HEADING', 'H3': 'HEADING',
            'H4': 'HEADING', 'H5': 'HEADING', 'H6': 'HEADING',
            'P': 'PARAGRAPH',
            'UL': 'LIST', 'OL': 'LIST',
            'BLOCKQUOTE': 'QUOTE',
            'TABLE': 'TABLE',
        };

        for (var i = 0; i < body.children.length; i++) {
            var node = body.children[i];
            var type = TAG_MAP[node.tagName];
            if (!type) continue;

            var text = (node.textContent || '').trim();
            if (!text) continue;

            sections.push({
                type: type,
                text: text,
                clientId: clientId,
                freeformIndex: i,
                isFreeform: true,
            });
        }

        return sections;
    }

    /**
     * Collect sections from editor blocks
     */
    function collectSections() {
        var blocks = wp.data.select('core/block-editor').getBlocks();
        var sections = [];

        blocks.forEach(function (block) {
            // Handle Classic/freeform blocks — parse their HTML content
            if (block.name === 'core/freeform') {
                var html = block.attributes.content || '';
                if (html) {
                    var freeformSections = parseFreeformHtml(html, block.clientId);
                    sections = sections.concat(freeformSections);
                }
                return;
            }

            var type = BLOCK_TYPE_MAP[block.name];
            if (!type) return;

            var text = getBlockText(block);
            if (!text) return;

            sections.push({
                type: type,
                text: text,
                clientId: block.clientId,
                blockName: block.name,
                isFreeform: false,
            });
        });

        return sections;
    }

    // ========================================================================
    // Panel states: 'idle' | 'analyzing' | 'results' | 'inserting' | 'generating'
    // ========================================================================

    function ImagePlannerPanel() {
        var phaseState = useState('idle');
        var phase = phaseState[0];
        var setPhase = phaseState[1];

        var suggestionsState = useState([]);
        var suggestions = suggestionsState[0];
        var setSuggestions = suggestionsState[1];

        var sectionsRef = useRef([]);

        // Reactively track pending AI Image blocks (re-renders when blocks change)
        var pendingAiBlocks = useSelect(function (select) {
            var blocks = select('core/block-editor').getBlocks();
            return blocks.filter(function (b) {
                return b.name === 'aai/ai-image' && !b.attributes.imageUrl && b.attributes.customPrompt;
            });
        }, []);

        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        var successState = useState('');
        var success = successState[0];
        var setSuccess = successState[1];

        var progressState = useState({ current: 0, total: 0 });
        var progress = progressState[0];
        var setProgress = progressState[1];

        var lockRef = useRef(false);

        // ----- Analyze article -----

        function handleAnalyze() {
            if (lockRef.current) return;
            lockRef.current = true;

            setError('');
            setSuccess('');
            setPhase('analyzing');

            var sections = collectSections();
            sectionsRef.current = sections;

            if (sections.length < 2) {
                setError(__('Artykuł jest za krótki do analizy (min. 2 sekcje z treścią).', 'agencyjnie-ai-images'));
                setPhase('idle');
                lockRef.current = false;
                return;
            }

            var title = wp.data.select('core/editor').getEditedPostAttribute('title') || '';

            // Send only type/text/clientId to PHP
            var sectionsForApi = sections.map(function (s) {
                return { type: s.type, text: s.text, clientId: s.clientId };
            });

            jQuery.ajax({
                url: window.aaiPlannerData?.ajaxUrl || window.aaiBlockData?.ajaxUrl || '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'aai_plan_content_images',
                    nonce: window.aaiPlannerData?.nonce || '',
                    title: title,
                    sections: JSON.stringify(sectionsForApi),
                },
                timeout: 60000,
                success: function (response) {
                    if (response.success && response.data.suggestions) {
                        // Add checked=true to each suggestion
                        var enriched = response.data.suggestions.map(function (s) {
                            s.checked = true;
                            return s;
                        });
                        setSuggestions(enriched);
                        setPhase('results');
                    } else {
                        setError(response.data?.message || __('Błąd analizy.', 'agencyjnie-ai-images'));
                        setPhase('idle');
                    }
                    lockRef.current = false;
                },
                error: function (xhr, status) {
                    if (status === 'timeout') {
                        setError(__('Przekroczono czas oczekiwania.', 'agencyjnie-ai-images'));
                    } else {
                        setError(__('Błąd połączenia z serwerem.', 'agencyjnie-ai-images'));
                    }
                    setPhase('idle');
                    lockRef.current = false;
                },
            });
        }

        // ----- Toggle suggestion checked -----

        function toggleSuggestion(index, checked) {
            var updated = suggestions.map(function (s, i) {
                if (i === index) {
                    return Object.assign({}, s, { checked: checked });
                }
                return s;
            });
            setSuggestions(updated);
        }

        // ----- Update suggestion field -----

        function updateSuggestionField(index, field, value) {
            var updated = suggestions.map(function (s, i) {
                if (i === index) {
                    var copy = Object.assign({}, s);
                    copy[field] = value;
                    return copy;
                }
                return s;
            });
            setSuggestions(updated);
        }

        // ----- Insert approved blocks -----

        function handleInsert() {
            var approved = suggestions.filter(function (s) { return s.checked; });

            if (approved.length === 0) {
                setError(__('Zaznacz przynajmniej jedną propozycję.', 'agencyjnie-ai-images'));
                return;
            }

            setPhase('inserting');
            setError('');

            var blockEditor = wp.data.dispatch('core/block-editor');
            var createBlock = wp.blocks.createBlock;
            var sections = sectionsRef.current;

            // Check if we're dealing with a freeform (Classic) article
            var hasFreeform = sections.some(function (s) { return s.isFreeform; });

            if (hasFreeform) {
                // Convert freeform block to Gutenberg blocks first, then insert AI images
                insertIntoFreeform(approved, sections, blockEditor, createBlock);
            } else {
                // Standard Gutenberg blocks — insert by clientId
                insertIntoGutenberg(approved, sections, blockEditor, createBlock);
            }
        }

        function insertIntoGutenberg(approved, sections, blockEditor, createBlock) {
            // Insert from bottom to top to preserve positions
            var sortedApproved = approved.slice().sort(function (a, b) {
                return b.after_index - a.after_index;
            });

            var insertedCount = 0;
            sortedApproved.forEach(function (s) {
                var newBlock = createBlock('aai/ai-image', {
                    customPrompt: s.prompt,
                    contentType: s.content_type,
                    artStyle: s.art_style,
                });

                var targetSection = sections[s.after_index];
                if (!targetSection) return;

                var currentBlocks = wp.data.select('core/block-editor').getBlocks();
                var position = -1;
                for (var i = 0; i < currentBlocks.length; i++) {
                    if (currentBlocks[i].clientId === targetSection.clientId) {
                        position = i;
                        break;
                    }
                }

                if (position >= 0) {
                    blockEditor.insertBlock(newBlock, position + 1);
                    insertedCount++;
                }
            });

            var skipped = approved.length - insertedCount;
            var msg = __('Wstawiono ' + insertedCount + ' blok(ów) AI Image.', 'agencyjnie-ai-images');
            if (skipped > 0) {
                msg += ' ' + skipped + ' ' + __('pominięto (artykuł zmieniony po analizie).', 'agencyjnie-ai-images');
            }
            setSuccess(msg);
            setPhase('idle');
            setSuggestions([]);
        }

        function insertIntoFreeform(approved, sections, blockEditor, createBlock) {
            // Find the freeform block
            var allBlocks = wp.data.select('core/block-editor').getBlocks();
            var freeformBlock = null;
            for (var i = 0; i < allBlocks.length; i++) {
                if (allBlocks[i].name === 'core/freeform') {
                    freeformBlock = allBlocks[i];
                    break;
                }
            }

            if (!freeformBlock) {
                setError(__('Nie znaleziono bloku treści.', 'agencyjnie-ai-images'));
                setPhase('idle');
                return;
            }

            // Convert Classic HTML to Gutenberg blocks using rawHandler
            var convertedBlocks = wp.blocks.rawHandler({ HTML: freeformBlock.attributes.content });

            // Build set of freeformIndex values where we want to insert AI images (after that index)
            var insertMap = {};
            approved.forEach(function (s) {
                var section = sections[s.after_index];
                if (section && section.isFreeform) {
                    insertMap[section.freeformIndex] = s;
                }
            });

            // Walk converted blocks and match them to freeform HTML elements by counting
            // matching block types in order
            var doc = new DOMParser().parseFromString(freeformBlock.attributes.content, 'text/html');
            var htmlElements = doc.body.children;

            // Build a map: converted block index → freeform HTML element index
            // Since rawHandler converts HTML sequentially, indices should roughly match
            var finalBlocks = [];
            var insertedCount = 0;

            // Match converted blocks to HTML element indices
            // rawHandler may merge or split elements, so we do best-effort sequential matching
            var htmlIdx = 0;
            for (var bi = 0; bi < convertedBlocks.length; bi++) {
                finalBlocks.push(convertedBlocks[bi]);

                // Try to find which HTML element this block corresponds to
                // Advance htmlIdx to find a matching element
                while (htmlIdx < htmlElements.length) {
                    var currentHtmlIdx = htmlIdx;
                    htmlIdx++;

                    if (insertMap[currentHtmlIdx]) {
                        var suggestion = insertMap[currentHtmlIdx];
                        finalBlocks.push(createBlock('aai/ai-image', {
                            customPrompt: suggestion.prompt,
                            contentType: suggestion.content_type,
                            artStyle: suggestion.art_style,
                        }));
                        insertedCount++;
                        delete insertMap[currentHtmlIdx];
                    }
                    break;
                }
            }

            // Replace freeform block with converted blocks + AI image blocks
            blockEditor.replaceBlock(freeformBlock.clientId, finalBlocks);

            var msg = __('Skonwertowano artykuł na bloki i wstawiono ', 'agencyjnie-ai-images')
                + insertedCount + __(' blok(ów) AI Image.', 'agencyjnie-ai-images');
            setSuccess(msg);
            setPhase('idle');
            setSuggestions([]);
        }

        // ----- Generate all ungenerated blocks -----

        function handleGenerateAll() {
            setPhase('generating');
            setError('');
            setSuccess('');

            // Snapshot pending blocks at the moment user clicks
            var aiBlocks = pendingAiBlocks.slice();

            if (aiBlocks.length === 0) {
                setError(__('Brak bloków AI Image do wygenerowania.', 'agencyjnie-ai-images'));
                setPhase('idle');
                return;
            }

            setProgress({ current: 0, total: aiBlocks.length });
            var postId = wp.data.select('core/editor').getCurrentPostId();

            // Sequential generation queue
            function generateNext(index) {
                if (index >= aiBlocks.length) {
                    setSuccess(__('Wygenerowano ' + aiBlocks.length + ' obrazków!', 'agencyjnie-ai-images'));
                    setPhase('idle');
                    return;
                }

                setProgress({ current: index + 1, total: aiBlocks.length });

                var block = aiBlocks[index];
                var attrs = block.attributes;

                jQuery.ajax({
                    url: window.aaiBlockData?.ajaxUrl || '/wp-admin/admin-ajax.php',
                    type: 'POST',
                    data: {
                        action: 'aai_generate_block_image',
                        nonce: window.aaiBlockData?.nonce || '',
                        post_id: postId,
                        custom_prompt: attrs.customPrompt,
                        override_style: '0',
                        art_style: attrs.artStyle || '',
                        aspect_ratio: '',
                        content_type: attrs.contentType || '',
                    },
                    timeout: 120000,
                    success: function (response) {
                        if (response.success) {
                            wp.data.dispatch('core/block-editor').updateBlockAttributes(
                                block.clientId,
                                {
                                    attachmentId: response.data.attachment_id,
                                    imageUrl: response.data.image_url,
                                    imageAlt: response.data.alt || attrs.customPrompt.substring(0, 100),
                                }
                            );
                        }
                        generateNext(index + 1);
                    },
                    error: function () {
                        // Skip failed block, continue with next
                        generateNext(index + 1);
                    },
                });
            }

            generateNext(0);
        }

        // ----- Reset to idle -----

        function handleReset() {
            setPhase('idle');
            setSuggestions([]);
            setError('');
            setSuccess('');
        }

        // ================================================================
        // Render
        // ================================================================

        var panelContent = [];

        // Error message
        if (error) {
            panelContent.push(
                el('div', { className: 'aai-planner-error', key: 'error' }, error)
            );
        }

        // Success message
        if (success) {
            panelContent.push(
                el('div', { className: 'aai-planner-success', key: 'success' }, success)
            );
        }

        // ----- IDLE state -----
        if (phase === 'idle') {
            panelContent.push(
                el(Button, {
                    key: 'analyze-btn',
                    variant: 'secondary',
                    onClick: handleAnalyze,
                    className: 'aai-planner-analyze-btn',
                    icon: 'images-alt2',
                }, __('Zaplanuj obrazki', 'agencyjnie-ai-images'))
            );

            // Show "Generate all" if there are ungenerated AI Image blocks
            if (pendingAiBlocks.length > 0) {
                panelContent.push(
                    el(Button, {
                        key: 'generate-all-btn',
                        variant: 'primary',
                        onClick: handleGenerateAll,
                        className: 'aai-planner-generate-all-btn',
                        style: { marginTop: '8px' },
                    }, __('Generuj wszystkie', 'agencyjnie-ai-images') + ' (' + pendingAiBlocks.length + ')')
                );
            }
        }

        // ----- ANALYZING state -----
        if (phase === 'analyzing') {
            panelContent.push(
                el('div', { className: 'aai-planner-loading', key: 'loading' },
                    el(Spinner, null),
                    el('span', null, ' ' + __('Analizuję artykuł...', 'agencyjnie-ai-images'))
                )
            );
        }

        // ----- RESULTS state -----
        if (phase === 'results' && suggestions.length > 0) {
            var checkedCount = suggestions.filter(function (s) { return s.checked; }).length;

            // Suggestion cards
            suggestions.forEach(function (s, idx) {
                var section = sectionsRef.current[s.after_index];
                var contextText = section
                    ? (section.type + ': ' + section.text.substring(0, 60) + (section.text.length > 60 ? '...' : ''))
                    : ('Sekcja ' + s.after_index);

                panelContent.push(
                    el('div', {
                        className: 'aai-planner-suggestion' + (s.checked ? ' is-checked' : ''),
                        key: 'suggestion-' + idx,
                    },
                        el(CheckboxControl, {
                            label: __('Po: ', 'agencyjnie-ai-images') + contextText,
                            checked: s.checked,
                            onChange: function (checked) { toggleSuggestion(idx, checked); },
                            className: 'aai-planner-checkbox',
                        }),
                        el('div', { className: 'aai-planner-controls' },
                            el(SelectControl, {
                                label: __('Typ', 'agencyjnie-ai-images'),
                                value: s.content_type,
                                options: CONTENT_TYPE_OPTIONS,
                                onChange: function (val) { updateSuggestionField(idx, 'content_type', val); },
                                __nextHasNoMarginBottom: true,
                            }),
                            el(SelectControl, {
                                label: __('Styl', 'agencyjnie-ai-images'),
                                value: s.art_style,
                                options: ART_STYLE_OPTIONS,
                                onChange: function (val) { updateSuggestionField(idx, 'art_style', val); },
                                __nextHasNoMarginBottom: true,
                            })
                        ),
                        el(TextareaControl, {
                            label: __('Prompt', 'agencyjnie-ai-images'),
                            value: s.prompt,
                            onChange: function (val) { updateSuggestionField(idx, 'prompt', val); },
                            rows: 3,
                            __nextHasNoMarginBottom: true,
                        }),
                        s.reason ? el('div', { className: 'aai-planner-reason' }, s.reason) : null
                    )
                );
            });

            // Action buttons
            panelContent.push(
                el('div', { className: 'aai-planner-actions', key: 'actions' },
                    el(Button, {
                        variant: 'primary',
                        onClick: handleInsert,
                        disabled: checkedCount === 0,
                    }, __('Wstaw zaznaczone', 'agencyjnie-ai-images') + ' (' + checkedCount + ')'),
                    el(Button, {
                        variant: 'tertiary',
                        onClick: handleReset,
                        style: { marginLeft: '8px' },
                    }, __('Anuluj', 'agencyjnie-ai-images'))
                )
            );
        }

        // ----- INSERTING state -----
        if (phase === 'inserting') {
            panelContent.push(
                el('div', { className: 'aai-planner-loading', key: 'inserting' },
                    el(Spinner, null),
                    el('span', null, ' ' + __('Wstawiam bloki...', 'agencyjnie-ai-images'))
                )
            );
        }

        // ----- GENERATING state -----
        if (phase === 'generating') {
            var pct = progress.total > 0 ? Math.round((progress.current / progress.total) * 100) : 0;
            panelContent.push(
                el('div', { className: 'aai-planner-loading', key: 'generating' },
                    el(Spinner, null),
                    el('span', null, ' ' + __('Generuję obrazki...', 'agencyjnie-ai-images') +
                        ' ' + progress.current + '/' + progress.total)
                ),
                el('div', { className: 'aai-planner-progress', key: 'progress-bar' },
                    el('div', {
                        className: 'aai-planner-progress-fill',
                        style: { width: pct + '%' },
                    })
                )
            );
        }

        return el(PluginDocumentSettingPanel, {
            name: 'aai-image-planner',
            title: __('AI Image Planner', 'agencyjnie-ai-images'),
            icon: 'images-alt2',
            className: 'aai-image-planner-panel',
        }, panelContent);
    }

    // Register plugin
    wp.plugins.registerPlugin('aai-image-planner', {
        render: ImagePlannerPanel,
        icon: 'images-alt2',
    });

})(window.wp);
