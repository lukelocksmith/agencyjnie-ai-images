/**
 * Gutenberg Block: AI Image
 * Allows inserting a custom AI-generated image with optional style overrides.
 */
(function (wp) {
    'use strict';

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;

    var registerBlockType = wp.blocks.registerBlockType;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var InspectorControls = wp.blockEditor.InspectorControls;

    var PanelBody = wp.components.PanelBody;
    var TextareaControl = wp.components.TextareaControl;
    var ToggleControl = wp.components.ToggleControl;
    var SelectControl = wp.components.SelectControl;
    var Button = wp.components.Button;
    var Spinner = wp.components.Spinner;
    var Placeholder = wp.components.Placeholder;

    var useState = wp.element.useState;
    var useRef = wp.element.useRef;
    var __ = wp.i18n.__;

    // Art style options (matching PHP settings)
    var ART_STYLE_OPTIONS = [
        { value: '', label: __('Użyj globalnego stylu', 'agencyjnie-ai-images') },
        { value: 'photorealistic', label: 'Photorealistic' },
        { value: 'digital_art', label: 'Digital Art' },
        { value: 'isometric', label: 'Isometric 3D' },
        { value: 'minimalist', label: 'Minimalist' },
        { value: 'cyberpunk', label: 'Cyberpunk' },
        { value: 'watercolor', label: 'Watercolor' },
        { value: 'sketch', label: 'Sketch' },
        { value: 'pop_art', label: 'Pop Art' },
        { value: 'abstract', label: 'Abstract' },
        { value: 'flat_illustration', label: 'Flat Illustration' },
        { value: '3d_render', label: '3D Render' },
        { value: 'retro_vintage', label: 'Retro / Vintage' },
        { value: 'neon_glow', label: 'Neon Glow' },
        { value: 'paper_cut', label: 'Paper Cut' },
        { value: 'pixel_art', label: 'Pixel Art' },
        { value: 'line_art', label: 'Line Art' },
        { value: 'gradient_mesh', label: 'Gradient Mesh' },
        { value: 'collage', label: 'Collage' },
    ];

    var ASPECT_RATIO_OPTIONS = [
        { value: '', label: __('Użyj globalnych proporcji', 'agencyjnie-ai-images') },
        { value: '16:9', label: '16:9 (Panoramiczny)' },
        { value: '4:3', label: '4:3 (Standardowy)' },
        { value: '1:1', label: '1:1 (Kwadrat)' },
        { value: '3:4', label: '3:4 (Portret)' },
        { value: '9:16', label: '9:16 (Pionowy)' },
    ];

    /**
     * Edit component for the AI Image block
     */
    function EditBlock(props) {
        var attributes = props.attributes;
        var setAttributes = props.setAttributes;

        var customPrompt = attributes.customPrompt;
        var attachmentId = attributes.attachmentId;
        var imageUrl = attributes.imageUrl;
        var imageAlt = attributes.imageAlt;
        var overrideStyle = attributes.overrideStyle;
        var artStyle = attributes.artStyle;
        var aspectRatio = attributes.aspectRatio;

        // Local UI state — NOT persisted to DB
        var generatingState = useState(false);
        var isGenerating = generatingState[0];
        var setIsGenerating = generatingState[1];

        var statusState = useState('');
        var statusText = statusState[0];
        var setStatusText = statusState[1];

        var errorState = useState('');
        var error = errorState[0];
        var setError = errorState[1];

        var successState = useState('');
        var successMessage = successState[0];
        var setSuccessMessage = successState[1];

        // Debounce lock ref — survives re-renders without triggering them
        var lockRef = useRef(false);

        var blockProps = useBlockProps({
            className: 'aai-block-ai-image',
        });

        /**
         * Handle image generation with debounce lock
         */
        var handleGenerate = function () {
            // Debounce — prevent double-clicks
            if (lockRef.current) return;

            if (!customPrompt || customPrompt.trim() === '') {
                setError(__('Wprowadź prompt dla obrazka.', 'agencyjnie-ai-images'));
                return;
            }

            lockRef.current = true;
            setError('');
            setSuccessMessage('');
            setIsGenerating(true);
            setStatusText(__('Wysyłam prompt...', 'agencyjnie-ai-images'));

            // Get post ID from editor
            var postId = wp.data.select('core/editor').getCurrentPostId();

            // AJAX request
            jQuery.ajax({
                url: window.aaiBlockData?.ajaxUrl || '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: {
                    action: 'aai_generate_block_image',
                    nonce: window.aaiBlockData?.nonce || '',
                    post_id: postId,
                    custom_prompt: customPrompt,
                    override_style: overrideStyle ? '1' : '0',
                    art_style: artStyle,
                    aspect_ratio: aspectRatio,
                },
                timeout: 120000,
                beforeSend: function () {
                    // Short delay then update status to show AI is working
                    setTimeout(function () {
                        setStatusText(__('AI generuje obrazek...', 'agencyjnie-ai-images'));
                    }, 1500);
                },
                success: function (response) {
                    if (response.success) {
                        setStatusText(__('Zapisuję obrazek...', 'agencyjnie-ai-images'));
                        setAttributes({
                            attachmentId: response.data.attachment_id,
                            imageUrl: response.data.image_url,
                            imageAlt: response.data.alt || customPrompt.substring(0, 100),
                        });
                        setSuccessMessage(__('Obrazek wygenerowany!', 'agencyjnie-ai-images'));
                        setTimeout(function () { setSuccessMessage(''); }, 3000);
                    } else {
                        setError(response.data?.message || __('Błąd generowania.', 'agencyjnie-ai-images'));
                    }

                    setIsGenerating(false);
                    setStatusText('');
                    lockRef.current = false;
                },
                error: function (xhr, status) {
                    if (status === 'timeout') {
                        setError(__('Przekroczono czas oczekiwania (2 min).', 'agencyjnie-ai-images'));
                    } else {
                        setError(__('Błąd połączenia z serwerem.', 'agencyjnie-ai-images'));
                    }

                    setIsGenerating(false);
                    setStatusText('');
                    lockRef.current = false;
                }
            });
        };

        /**
         * Remove image
         */
        var handleRemoveImage = function () {
            setAttributes({
                attachmentId: 0,
                imageUrl: '',
                imageAlt: '',
            });
        };

        // --- Generate button label (with progressive status) ---

        var generateLabel = statusText || (imageUrl
            ? __('Regeneruj obrazek', 'agencyjnie-ai-images')
            : __('Generuj obrazek', 'agencyjnie-ai-images'));

        // --- Sidebar (InspectorControls) ---

        var sidebarStyleOverrides = overrideStyle
            ? el(Fragment, null,
                el(SelectControl, {
                    label: __('Styl artystyczny', 'agencyjnie-ai-images'),
                    value: artStyle,
                    options: ART_STYLE_OPTIONS,
                    onChange: function (value) { setAttributes({ artStyle: value }); }
                }),
                el(SelectControl, {
                    label: __('Proporcje', 'agencyjnie-ai-images'),
                    value: aspectRatio,
                    options: ASPECT_RATIO_OPTIONS,
                    onChange: function (value) { setAttributes({ aspectRatio: value }); }
                })
            )
            : null;

        var sidebarGenerateButtonContent = isGenerating
            ? el(Fragment, null, el(Spinner, null), ' ' + generateLabel)
            : generateLabel;

        var sidebar = el(InspectorControls, null,
            el(PanelBody, { title: __('Ustawienia obrazka', 'agencyjnie-ai-images'), initialOpen: true },
                el(TextareaControl, {
                    label: __('Prompt', 'agencyjnie-ai-images'),
                    help: __('Opisz jaki obrazek chcesz wygenerować.', 'agencyjnie-ai-images'),
                    value: customPrompt,
                    onChange: function (value) { setAttributes({ customPrompt: value }); },
                    rows: 4
                }),
                el(ToggleControl, {
                    label: __('Nadpisz globalne style', 'agencyjnie-ai-images'),
                    checked: overrideStyle,
                    onChange: function (value) { setAttributes({ overrideStyle: value }); }
                }),
                sidebarStyleOverrides,
                el(Button, {
                    variant: 'primary',
                    onClick: handleGenerate,
                    disabled: isGenerating || !customPrompt,
                    style: { marginTop: '10px', width: '100%' }
                }, sidebarGenerateButtonContent)
            )
        );

        // --- Block content ---

        var placeholderButtonContent = isGenerating
            ? el(Fragment, null, el(Spinner, null), ' ' + (statusText || __('Generowanie...', 'agencyjnie-ai-images')))
            : __('Generuj obrazek', 'agencyjnie-ai-images');

        var blockContent;

        if (!imageUrl) {
            // Placeholder state — no image yet
            blockContent = el(Placeholder, {
                    icon: 'format-image',
                    label: __('AI Image', 'agencyjnie-ai-images'),
                    instructions: __('Wpisz prompt w panelu bocznym i kliknij "Generuj obrazek".', 'agencyjnie-ai-images')
                },
                el(TextareaControl, {
                    placeholder: __('Opisz obrazek, który chcesz wygenerować...', 'agencyjnie-ai-images'),
                    value: customPrompt,
                    onChange: function (value) { setAttributes({ customPrompt: value }); },
                    rows: 3
                }),
                el(Button, {
                    variant: 'primary',
                    onClick: handleGenerate,
                    disabled: isGenerating || !customPrompt
                }, placeholderButtonContent),
                error ? el('p', { className: 'aai-block-error' }, error) : null
            );
        } else {
            // Image preview state
            blockContent = el('figure', { className: 'aai-block-figure' },
                el('img', { src: imageUrl, alt: imageAlt, className: 'aai-block-image' }),
                el('div', { className: 'aai-block-overlay' },
                    el(Button, {
                        variant: 'secondary',
                        onClick: handleGenerate,
                        disabled: isGenerating,
                        className: 'aai-block-btn'
                    }, isGenerating ? el(Spinner, null) : __('Regeneruj', 'agencyjnie-ai-images')),
                    el(Button, {
                        variant: 'secondary',
                        onClick: handleRemoveImage,
                        isDestructive: true,
                        className: 'aai-block-btn'
                    }, __('Usuń', 'agencyjnie-ai-images'))
                ),
                successMessage ? el('p', { className: 'aai-block-success' }, successMessage) : null,
                error ? el('p', { className: 'aai-block-error' }, error) : null
            );
        }

        return el(Fragment, null,
            sidebar,
            el('div', blockProps, blockContent)
        );
    }

    /**
     * Save component - renders the image on the frontend
     */
    function SaveBlock(props) {
        var attributes = props.attributes;
        var imageUrl = attributes.imageUrl;
        var imageAlt = attributes.imageAlt;
        var attachmentId = attributes.attachmentId;

        var blockProps = useBlockProps.save({
            className: 'aai-block-ai-image',
        });

        if (!imageUrl) {
            return null; // Don't render empty block
        }

        return el('figure', blockProps,
            el('img', {
                src: imageUrl,
                alt: imageAlt || '',
                className: 'aai-generated-image',
                'data-attachment-id': attachmentId
            })
        );
    }

    // Register the block
    registerBlockType('aai/ai-image', {
        edit: EditBlock,
        save: SaveBlock,
    });

})(window.wp);
