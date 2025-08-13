/**
 * SpamShield Custom Forms - Frontend JavaScript
 * Handles form submission, validation, and user experience
 */

(function($) {
    'use strict';
    
    // Form handler class
    function SSCFFormHandler() {
        this.init();
    }
    
    SSCFFormHandler.prototype = {
        
        init: function() {
            this.bindEvents();
            this.initializeForms();
        },
        
        bindEvents: function() {
            $(document).on('submit', '.sscf-custom-form', this.handleFormSubmit.bind(this));
            $(document).on('input change', '.sscf-field-input', this.handleFieldValidation.bind(this));
            $(document).on('blur', '.sscf-field-input', this.validateField.bind(this));
        },
        
        initializeForms: function() {
            $('.sscf-custom-form').each(function() {
                var $form = $(this);
                var formId = $form.data('form-id');
                
                // Set form loaded timestamp
                $form.find('input[name="sscf_form_loaded"]').val(Math.floor(Date.now() / 1000));
                
                // Initialize file inputs
                $form.find('input[type="file"]').each(function() {
                    $(this).on('change', function() {
                        var files = this.files;
                        var maxSize = 10 * 1024 * 1024; // 10MB
                        var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                        
                        for (var i = 0; i < files.length; i++) {
                            var file = files[i];
                            
                            if (file.size > maxSize) {
                                showNotification(sscf_frontend.strings.file_too_large, 'error');
                                $(this).val('');
                                return false;
                            }
                            
                            if (allowedTypes.length > 0 && allowedTypes.indexOf(file.type) === -1) {
                                showNotification(sscf_frontend.strings.invalid_file_type, 'error');
                                $(this).val('');
                                return false;
                            }
                        }
                    });
                });
            });
        },
        
        handleFormSubmit: function(e) {
            e.preventDefault();
            
            var $form = $(e.target);
            var formId = $form.data('form-id');
            
            if (!formId) {
                this.showError($form, sscf_frontend.strings.error);
                return;
            }
            
            // Validate form
            if (!this.validateForm($form)) {
                return;
            }
            
            this.submitForm($form, formId);
        },
        
        validateForm: function($form) {
            var isValid = true;
            var firstInvalidField = null;
            
            // Clear previous errors
            $form.find('.sscf-form-field').removeClass('sscf-has-error');
            $form.find('.sscf-field-error').hide().text('');
            
            // Validate required fields
            $form.find('.sscf-form-field[data-required="true"]').each(function() {
                var $field = $(this);
                var $input = $field.find('.sscf-field-input');
                var fieldType = $input.attr('type') || $input.prop('tagName').toLowerCase();
                var value = '';
                
                // Get field value based on type
                if (fieldType === 'radio') {
                    value = $field.find('input[type="radio"]:checked').val() || '';
                } else if (fieldType === 'checkbox') {
                    var checkedValues = [];
                    $field.find('input[type="checkbox"]:checked').each(function() {
                        checkedValues.push($(this).val());
                    });
                    value = checkedValues.join(',');
                } else {
                    value = $input.val() || '';
                }
                
                if (!value.trim()) {
                    isValid = false;
                    $field.addClass('sscf-has-error');
                    $field.find('.sscf-field-error').text(sscf_frontend.strings.required).show();
                    
                    if (!firstInvalidField) {
                        firstInvalidField = $input;
                    }
                }
            });
            
            // Validate field types
            $form.find('.sscf-field-input').each(function() {
                var $input = $(this);
                var $field = $input.closest('.sscf-form-field');
                var fieldType = $input.attr('type');
                var value = $input.val();
                
                if (value.trim()) {
                    var fieldValid = true;
                    var errorMessage = '';
                    
                    switch (fieldType) {
                        case 'email':
                            if (!this.isValidEmail(value)) {
                                fieldValid = false;
                                errorMessage = sscf_frontend.strings.invalid_email;
                            }
                            break;
                            
                        case 'url':
                            if (!this.isValidURL(value)) {
                                fieldValid = false;
                                errorMessage = sscf_frontend.strings.invalid_url;
                            }
                            break;
                            
                        case 'number':
                            if (isNaN(value) || value.trim() === '') {
                                fieldValid = false;
                                errorMessage = 'Please enter a valid number.';
                            }
                            break;
                            
                        case 'tel':
                            if (!this.isValidPhone(value)) {
                                fieldValid = false;
                                errorMessage = 'Please enter a valid phone number.';
                            }
                            break;
                    }
                    
                    if (!fieldValid) {
                        isValid = false;
                        $field.addClass('sscf-has-error');
                        $field.find('.sscf-field-error').text(errorMessage).show();
                        
                        if (!firstInvalidField) {
                            firstInvalidField = $input;
                        }
                    }
                }
            }.bind(this));
            
            // Scroll to first error
            if (!isValid && firstInvalidField) {
                $('html, body').animate({
                    scrollTop: firstInvalidField.offset().top - 100
                }, 300);
                firstInvalidField.focus();
            }
            
            return isValid;
        },
        
        validateField: function(e) {
            var $input = $(e.target);
            var $field = $input.closest('.sscf-form-field');
            var fieldType = $input.attr('type');
            var value = $input.val();
            var isRequired = $field.data('required');
            
            // Clear previous error
            $field.removeClass('sscf-has-error');
            $field.find('.sscf-field-error').hide().text('');
            
            // Check if required and empty
            if (isRequired && !value.trim()) {
                $field.addClass('sscf-has-error');
                $field.find('.sscf-field-error').text(sscf_frontend.strings.required).show();
                return;
            }
            
            // Validate field type if value exists
            if (value.trim()) {
                var errorMessage = '';
                
                switch (fieldType) {
                    case 'email':
                        if (!this.isValidEmail(value)) {
                            errorMessage = sscf_frontend.strings.invalid_email;
                        }
                        break;
                        
                    case 'url':
                        if (!this.isValidURL(value)) {
                            errorMessage = sscf_frontend.strings.invalid_url;
                        }
                        break;
                        
                    case 'number':
                        if (isNaN(value)) {
                            errorMessage = 'Please enter a valid number.';
                        }
                        break;
                        
                    case 'tel':
                        if (!this.isValidPhone(value)) {
                            errorMessage = 'Please enter a valid phone number.';
                        }
                        break;
                }
                
                if (errorMessage) {
                    $field.addClass('sscf-has-error');
                    $field.find('.sscf-field-error').text(errorMessage).show();
                }
            }
        },
        
        handleFieldValidation: function(e) {
            // Real-time validation for immediate feedback
            var $input = $(e.target);
            var $field = $input.closest('.sscf-form-field');
            
            // Remove error state when user starts typing
            if ($field.hasClass('sscf-has-error')) {
                $field.removeClass('sscf-has-error');
                $field.find('.sscf-field-error').hide();
            }
        },
        
        submitForm: function($form, formId) {
            var $submitBtn = $form.find('.sscf-submit-btn');
            var $submitText = $submitBtn.find('.sscf-submit-text');
            var $submitSpinner = $submitBtn.find('.sscf-submit-spinner');
            var $messages = $form.closest('.sscf-custom-form-container').find('.sscf-form-messages');
            
            // Hide previous messages
            $messages.hide();
            $messages.find('.sscf-success-message, .sscf-error-message').empty();
            
            // Show loading state
            $form.addClass('sscf-submitting');
            $submitBtn.prop('disabled', true);
            $submitText.hide();
            $submitSpinner.show();
            
            // Prepare form data
            var formData = new FormData($form[0]);
            formData.append('action', 'sscf_submit_custom_form');
            formData.append('form_id', formId);
            
            // Submit via AJAX
            $.ajax({
                url: sscf_frontend.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 30000,
                success: function(response) {
                    if (response.success) {
                        this.showSuccess($form, response.data.message);
                        this.resetForm($form);
                        
                        // Optional: Hide form after success
                        setTimeout(function() {
                            $form.addClass('sscf-submitted');
                        }, 1000);
                        
                    } else {
                        this.showError($form, response.data.message || sscf_frontend.strings.error, response.data.field_errors);
                    }
                }.bind(this),
                error: function(xhr, status, error) {
                    console.error('Form submission error:', error);
                    this.showError($form, sscf_frontend.strings.error);
                }.bind(this),
                complete: function() {
                    // Reset loading state
                    $form.removeClass('sscf-submitting');
                    $submitBtn.prop('disabled', false);
                    $submitText.show();
                    $submitSpinner.hide();
                }
            });
        },
        
        showSuccess: function($form, message) {
            var $container = $form.closest('.sscf-custom-form-container');
            var $messages = $container.find('.sscf-form-messages');
            var $successMsg = $messages.find('.sscf-success-message');
            
            $successMsg.html(message);
            $messages.show();
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $messages.offset().top - 50
            }, 300);
        },
        
        showError: function($form, message, fieldErrors) {
            var $container = $form.closest('.sscf-custom-form-container');
            var $messages = $container.find('.sscf-form-messages');
            var $errorMsg = $messages.find('.sscf-error-message');
            
            $errorMsg.html(message);
            $messages.show();
            
            // Show field-specific errors
            if (fieldErrors) {
                $.each(fieldErrors, function(fieldId, errorMessage) {
                    var $field = $form.find('[name="sscf_fields[' + fieldId + ']"]').closest('.sscf-form-field');
                    $field.addClass('sscf-has-error');
                    $field.find('.sscf-field-error').text(errorMessage).show();
                });
            }
            
            // Scroll to message
            $('html, body').animate({
                scrollTop: $messages.offset().top - 50
            }, 300);
        },
        
        resetForm: function($form) {
            // Reset form fields
            $form[0].reset();
            
            // Clear validation states
            $form.find('.sscf-form-field').removeClass('sscf-has-error');
            $form.find('.sscf-field-error').hide().text('');
            
            // Reset file inputs specifically
            $form.find('input[type="file"]').val('');
        },
        
        // Validation helpers
        isValidEmail: function(email) {
            var pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return pattern.test(email);
        },
        
        isValidURL: function(url) {
            try {
                new URL(url);
                return true;
            } catch (e) {
                return false;
            }
        },
        
        isValidPhone: function(phone) {
            var pattern = /^[\d\s\-\+\(\)\.]+$/;
            return pattern.test(phone) && phone.replace(/\D/g, '').length >= 7;
        },

        // Notification system (similar to form-builder.js)
        showNotification: function(message, type) {
            type = type || 'info';
            
            // Remove existing notifications
            $('.sscf-notification').remove();
            
            // Create notification element
            var notificationClass = 'sscf-notification sscf-notification-' + type;
            var notification = $('<div class="' + notificationClass + '">' +
                '<span class="sscf-notification-message"></span>' +
                '<button class="sscf-notification-close">&times;</button>' +
                '</div>');
            
            notification.find('.sscf-notification-message').text(message);
            
            // Append to body or first form container
            var $container = $('.sscf-custom-form-container').first();
            if ($container.length) {
                $container.prepend(notification);
            } else {
                $('body').prepend(notification);
            }
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Close button handler
            notification.find('.sscf-notification-close').on('click', function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            });
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        if (typeof sscf_frontend !== 'undefined') {
            new SSCFFormHandler();
        }
    });
    
    // Expose handler for external use
    window.SSCFFormHandler = SSCFFormHandler;
    
})(jQuery);
