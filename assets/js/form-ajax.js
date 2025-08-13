/**
 * SpamShield Contact Form AJAX Handler
 * Provides enhanced form submission with AJAX while maintaining fallback support
 */

(function() {
    'use strict';
    
    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
        initContactForms();
    });
    
    /**
     * Initialize all contact forms on the page
     */
    function initContactForms() {
        const forms = document.querySelectorAll('.sscf-form');
        
        forms.forEach(function(form) {
            enhanceForm(form);
        });
    }
    
    /**
     * Enhance a single form with AJAX functionality
     */
    function enhanceForm(form) {
        // Prevent multiple enhancements
        if (form.hasAttribute('data-sscf-enhanced')) {
            return;
        }
        
        form.setAttribute('data-sscf-enhanced', 'true');
        
        // Add submit event listener
        form.addEventListener('submit', handleFormSubmit);
        
        // Add real-time validation
        addFieldValidation(form);
        
        // Update form action for AJAX
        form.setAttribute('data-original-action', form.action);
        form.action = '#'; // Prevent normal form submission
    }
    
    /**
     * Handle form submission
     */
    function handleFormSubmit(event) {
        event.preventDefault();
        
        const form = event.target;
        const formData = new FormData(form);
        
        // Add AJAX action
        formData.append('action', 'sscf_submit_form');
        
        // Show loading state
        setFormLoading(form, true);
        
        // Clear previous messages
        clearMessages(form);
        
        // Submit via AJAX
        submitFormAjax(form, formData)
            .then(function(response) {
                handleAjaxResponse(form, response);
            })
            .catch(function(error) {
                handleAjaxError(form, error);
            })
            .finally(function() {
                setFormLoading(form, false);
            });
    }
    
    /**
     * Submit form via AJAX
     */
    function submitFormAjax(form, formData) {
        return new Promise(function(resolve, reject) {
            const xhr = new XMLHttpRequest();
            
            xhr.open('POST', sscf_ajax.ajax_url, true);
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        resolve(response);
                    } catch (e) {
                        reject(new Error('Invalid JSON response'));
                    }
                } else {
                    reject(new Error('HTTP Error: ' + xhr.status));
                }
            };
            
            xhr.onerror = function() {
                reject(new Error('Network error'));
            };
            
            xhr.ontimeout = function() {
                reject(new Error('Request timeout'));
            };
            
            xhr.timeout = 30000; // 30 second timeout
            
            xhr.send(formData);
        });
    }
    
    /**
     * Handle successful AJAX response
     */
    function handleAjaxResponse(form, response) {
        if (response.success) {
            showMessage(form, response.message, 'success');
            resetForm(form);
        } else {
            showMessage(form, response.message, 'error');
        }
    }
    
    /**
     * Handle AJAX error
     */
    function handleAjaxError(form, error) {
        console.error('SpamShield Contact Form Error:', error);
        
        // Show user-friendly error message
        const errorMessage = sscf_ajax.error_text || 'There was an error sending your message. Please try again.';
        showMessage(form, errorMessage, 'error');
        
        // Fallback: try normal form submission
        if (error.message.includes('Network error') || error.message.includes('timeout')) {
            setTimeout(function() {
                fallbackToNormalSubmit(form);
            }, 2000);
        }
    }
    
    /**
     * Set form loading state
     */
    function setFormLoading(form, isLoading) {
        const submitBtn = form.querySelector('.sscf-submit-btn');
        const submitText = submitBtn.querySelector('.sscf-submit-text');
        const loadingText = submitBtn.querySelector('.sscf-loading-text');
        
        if (isLoading) {
            form.classList.add('loading');
            submitBtn.disabled = true;
            
            if (submitText) submitText.style.display = 'none';
            if (loadingText) {
                loadingText.style.display = 'inline';
                
                // Add spinner if not present
                if (!loadingText.querySelector('.sscf-loading-spinner')) {
                    const spinner = document.createElement('span');
                    spinner.className = 'sscf-loading-spinner';
                    loadingText.insertBefore(spinner, loadingText.firstChild);
                }
            }
        } else {
            form.classList.remove('loading');
            submitBtn.disabled = false;
            
            if (submitText) submitText.style.display = 'inline';
            if (loadingText) loadingText.style.display = 'none';
        }
    }
    
    /**
     * Show message to user
     */
    function showMessage(form, message, type) {
        const messageContainer = form.querySelector('.sscf-message');
        
        if (messageContainer) {
            messageContainer.textContent = message;
            messageContainer.className = 'sscf-message ' + type;
            messageContainer.style.display = 'block';
            
            // Scroll to message
            messageContainer.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'nearest' 
            });
            
            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(function() {
                    if (messageContainer.classList.contains('success')) {
                        hideMessage(messageContainer);
                    }
                }, 5000);
            }
        }
    }
    
    /**
     * Clear all messages
     */
    function clearMessages(form) {
        const messageContainer = form.querySelector('.sscf-message');
        if (messageContainer) {
            hideMessage(messageContainer);
        }
    }
    
    /**
     * Hide message container
     */
    function hideMessage(messageContainer) {
        messageContainer.style.display = 'none';
        messageContainer.textContent = '';
        messageContainer.className = 'sscf-message';
    }
    
    /**
     * Reset form after successful submission
     */
    function resetForm(form) {
        // Reset all form fields except hidden fields
        const fields = form.querySelectorAll('input[type="text"], input[type="email"], textarea');
        fields.forEach(function(field) {
            field.value = '';
            field.classList.remove('error');
        });
        
        // Update timestamp for new submission
        const timestampField = form.querySelector('input[name="sscf_timestamp"]');
        if (timestampField) {
            timestampField.value = Math.floor(Date.now() / 1000);
        }
        
        // Focus on first field
        const firstField = form.querySelector('input[type="text"], input[type="email"]');
        if (firstField) {
            firstField.focus();
        }
    }
    
    /**
     * Add real-time field validation
     */
    function addFieldValidation(form) {
        const fields = form.querySelectorAll('input[required], textarea[required]');
        
        fields.forEach(function(field) {
            // Validate on blur
            field.addEventListener('blur', function() {
                validateField(field);
            });
            
            // Clear error on input
            field.addEventListener('input', function() {
                if (field.classList.contains('error')) {
                    field.classList.remove('error');
                }
            });
        });
    }
    
    /**
     * Validate individual field
     */
    function validateField(field) {
        const value = field.value.trim();
        let isValid = true;
        
        // Check if required field is empty
        if (field.hasAttribute('required') && !value) {
            isValid = false;
        }
        
        // Email specific validation
        if (field.type === 'email' && value) {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(value)) {
                isValid = false;
            }
        }
        
        // Add/remove error class
        if (!isValid) {
            field.classList.add('error');
        } else {
            field.classList.remove('error');
        }
        
        return isValid;
    }
    
    /**
     * Fallback to normal form submission
     */
    function fallbackToNormalSubmit(form) {
        // Restore original action if available
        const originalAction = form.getAttribute('data-original-action');
        if (originalAction) {
            form.action = originalAction;
        }
        
        // Remove AJAX enhancement
        form.removeAttribute('data-sscf-enhanced');
        
        // Submit normally
        form.submit();
    }
    
    /**
     * Accessibility improvements
     */
    function enhanceAccessibility(form) {
        // Add ARIA labels and descriptions
        const fields = form.querySelectorAll('input, textarea');
        
        fields.forEach(function(field) {
            // Add ARIA required attribute
            if (field.hasAttribute('required')) {
                field.setAttribute('aria-required', 'true');
            }
            
            // Connect labels with fields
            const label = form.querySelector('label[for="' + field.id + '"]');
            if (label && !field.hasAttribute('aria-labelledby')) {
                field.setAttribute('aria-labelledby', field.id + '-label');
                label.id = field.id + '-label';
            }
        });
        
        // Make message container a live region
        const messageContainer = form.querySelector('.sscf-message');
        if (messageContainer) {
            messageContainer.setAttribute('aria-live', 'polite');
            messageContainer.setAttribute('aria-atomic', 'true');
        }
    }
    
    // Initialize accessibility enhancements
    document.addEventListener('DOMContentLoaded', function() {
        const forms = document.querySelectorAll('.sscf-form');
        forms.forEach(enhanceAccessibility);
    });
    
})();
