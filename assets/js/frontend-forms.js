/**
 * Podcast Intelligence Frontend Forms
 * Handles AJAX form submissions for adding data to podcast intelligence tables.
 */
(function($) {
    'use strict';

    // Initialize when document is ready
    $(document).ready(function() {
        initForms();
    });

    /**
     * Initialize all Guestify forms
     */
    function initForms() {
        // Handle form submissions
        $(document).on('submit', '[data-action^="pit_"]', handleFormSubmit);

        // Auto-fill first/last name from full name
        $(document).on('blur', '#contact_name', function() {
            var fullName = $(this).val();
            if (fullName && !$('#contact_first_name').val() && !$('#contact_last_name').val()) {
                var parts = fullName.split(' ');
                $('#contact_first_name').val(parts[0] || '');
                $('#contact_last_name').val(parts.slice(1).join(' ') || '');
            }
        });

        // Auto-extract username from social URL
        $(document).on('blur', '#social_url', function() {
            var url = $(this).val();
            var platform = $('#social_platform').val();
            if (url && platform && !$('#social_username').val()) {
                var username = extractUsername(url, platform);
                if (username) {
                    $('#social_username').val(username);
                }
            }
        });
    }

    /**
     * Handle form submission via AJAX
     */
    function handleFormSubmit(e) {
        e.preventDefault();

        var $form = $(this);
        var $submitBtn = $form.find('.guestify-submit-btn');
        var $message = $form.find('.guestify-form-message');
        var action = $form.data('action');

        // Validate required fields
        var isValid = true;
        $form.find('[required]').each(function() {
            if (!$(this).val()) {
                isValid = false;
                $(this).addClass('error');
            } else {
                $(this).removeClass('error');
            }
        });

        if (!isValid) {
            showMessage($message, pitForms.messages.required, 'error');
            return;
        }

        // Disable submit button
        $submitBtn.prop('disabled', true).addClass('loading');
        $message.removeClass('success error').text('');

        // Gather form data
        var formData = $form.serialize();
        formData += '&action=' + action;

        // Submit via AJAX
        $.ajax({
            url: pitForms.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    showMessage($message, response.data.message, 'success');

                    // Reset form on success
                    $form[0].reset();

                    // Redirect if specified
                    if (response.data.redirect) {
                        setTimeout(function() {
                            window.location.href = response.data.redirect;
                        }, 1000);
                    }

                    // Trigger custom event
                    $form.trigger('guestify:success', [response.data]);
                } else {
                    showMessage($message, response.data.message || pitForms.messages.error, 'error');
                }
            },
            error: function() {
                showMessage($message, pitForms.messages.error, 'error');
            },
            complete: function() {
                $submitBtn.prop('disabled', false).removeClass('loading');
            }
        });
    }

    /**
     * Show message in form
     */
    function showMessage($element, message, type) {
        $element
            .removeClass('success error')
            .addClass(type)
            .text(message)
            .fadeIn();

        // Auto-hide success messages
        if (type === 'success') {
            setTimeout(function() {
                $element.fadeOut();
            }, 5000);
        }
    }

    /**
     * Extract username from social media URL
     */
    function extractUsername(url, platform) {
        var patterns = {
            twitter: /(?:twitter\.com|x\.com)\/(?:@)?([a-zA-Z0-9_]+)/i,
            instagram: /instagram\.com\/([a-zA-Z0-9_.]+)/i,
            facebook: /facebook\.com\/([a-zA-Z0-9.]+)/i,
            youtube: /youtube\.com\/(?:@|channel\/|c\/)?([a-zA-Z0-9_-]+)/i,
            linkedin: /linkedin\.com\/(?:in|company)\/([a-zA-Z0-9_-]+)/i,
            tiktok: /tiktok\.com\/@?([a-zA-Z0-9_.]+)/i
        };

        var pattern = patterns[platform];
        if (pattern) {
            var match = url.match(pattern);
            if (match && match[1]) {
                return '@' + match[1];
            }
        }
        return null;
    }

})(jQuery);
