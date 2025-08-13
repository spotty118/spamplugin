/**
 * SpamShield Form Builder JavaScript
 * Drag-and-drop form builder functionality
 */

(function($) {
    'use strict';
    
    const SSCFFormBuilder = {
        
        formData: {
            fields: [],
            settings: {}
        },
        
        selectedField: null,
        fieldCounter: 0,
        
        init: function() {
            this.bindEvents();
            this.initializeDragDrop();
            this.loadExistingForm();
        },
        
        bindEvents: function() {
            // Add field buttons
            $(document).on('click', '.sscf-add-field', this.addField.bind(this));
            
            // Field controls
            $(document).on('click', '.sscf-field-settings', this.openFieldSettings.bind(this));
            $(document).on('click', '.sscf-field-duplicate', this.duplicateField.bind(this));
            $(document).on('click', '.sscf-field-delete', this.deleteField.bind(this));
            
            // Form actions
            $('#save-form').on('click', this.saveForm.bind(this));
            $('#preview-form').on('click', this.previewForm.bind(this));
            $('#clear-form').on('click', this.clearForm.bind(this));
            
            // Settings modal
            $(document).on('click', '.sscf-modal-close', this.closeModal.bind(this));
            $(document).on('click', '.sscf-field-settings-modal', function(e) {
                if (e.target === this) {
                    SSCFFormBuilder.closeModal();
                }
            });
            
            // Field selection
            $(document).on('click', '.sscf-builder-field', this.selectField.bind(this));
            
            // Copy shortcode functionality
            $(document).on('click', '.copy-shortcode', this.copyShortcode.bind(this));
            
            // Delete form
            $(document).on('click', '.delete-form', this.deleteForm.bind(this));
            
            // Date range selector
            $('#export-date-range').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#custom-date-range').show();
                } else {
                    $('#custom-date-range').hide();
                }
            });
        },
        
        initializeDragDrop: function() {
            // Make field palette buttons draggable
            $('.sscf-add-field').draggable({
                helper: 'clone',
                connectToSortable: '#form-canvas',
                start: function(event, ui) {
                    ui.helper.addClass('dragging');
                }
            });
            
            // Make form canvas sortable
            $('#form-canvas').sortable({
                placeholder: 'sscf-field-placeholder active',
                handle: '.sscf-field-drag-handle',
                tolerance: 'pointer',
                receive: function(event, ui) {
                    const fieldType = ui.item.data('field-type');
                    if (fieldType) {
                        // Replace dragged button with actual field
                        ui.item.replaceWith(SSCFFormBuilder.createField(fieldType));
                        SSCFFormBuilder.updateFormData();
                    }
                },
                update: function(event, ui) {
                    SSCFFormBuilder.updateFormData();
                }
            });
            
            // Remove placeholder when not dragging
            $('#form-canvas').on('sortout', function() {
                $('.sscf-field-placeholder').removeClass('active');
            });
        },
        
        addField: function(e) {
            e.preventDefault();
            const fieldType = $(e.currentTarget).data('field-type');
            const field = this.createField(fieldType);
            
            $('#form-canvas .sscf-canvas-placeholder').remove();
            $('#form-canvas').append(field);
            this.updateFormData();
        },
        
        createField: function(fieldType) {
            this.fieldCounter++;
            const fieldId = 'field_' + this.fieldCounter;
            
            const fieldConfig = this.getFieldConfig(fieldType);
            const fieldData = {
                id: fieldId,
                type: fieldType,
                label: fieldConfig.label,
                placeholder: fieldConfig.placeholder || '',
                required: false,
                options: fieldConfig.options || [],
                settings: {}
            };
            
            return this.renderBuilderField(fieldData);
        },
        
        getFieldConfig: function(fieldType) {
            const configs = {
                'text': {
                    label: 'Text Field',
                    placeholder: 'Enter text here...'
                },
                'textarea': {
                    label: 'Message',
                    placeholder: 'Enter your message...'
                },
                'email': {
                    label: 'Email Address',
                    placeholder: 'your@email.com'
                },
                'number': {
                    label: 'Number',
                    placeholder: 'Enter number...'
                },
                'tel': {
                    label: 'Phone Number',
                    placeholder: '(555) 123-4567'
                },
                'url': {
                    label: 'Website URL',
                    placeholder: 'https://example.com'
                },
                'select': {
                    label: 'Dropdown Selection',
                    options: ['Option 1', 'Option 2', 'Option 3']
                },
                'radio': {
                    label: 'Choose One',
                    options: ['Option 1', 'Option 2', 'Option 3']
                },
                'checkbox': {
                    label: 'Select All That Apply',
                    options: ['Option 1', 'Option 2', 'Option 3']
                },
                'file': {
                    label: 'File Upload'
                },
                'date': {
                    label: 'Date'
                },
                'time': {
                    label: 'Time'
                },
                'html': {
                    label: 'HTML Content',
                    content: '<p>Custom HTML content goes here</p>'
                },
                'divider': {
                    label: 'Section Divider'
                },
                'heading': {
                    label: 'Section Heading',
                    text: 'Section Title',
                    level: 'h3'
                }
            };
            
            return configs[fieldType] || { label: 'Field' };
        },
        
        renderBuilderField: function(fieldData) {
            let html = '<div class="sscf-builder-field" data-field-type="' + fieldData.type + '" data-field-id="' + fieldData.id + '">';
            
            // Field controls
            html += '<div class="sscf-field-controls">';
            html += '<button class="sscf-field-settings" title="Settings"><span class="dashicons dashicons-admin-generic"></span></button>';
            html += '<button class="sscf-field-duplicate" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>';
            html += '<button class="sscf-field-delete" title="Delete"><span class="dashicons dashicons-trash"></span></button>';
            html += '<div class="sscf-field-drag-handle" title="Drag to reorder"><span class="dashicons dashicons-sort"></span></div>';
            html += '</div>';
            
            // Field content
            html += '<div class="sscf-field-content">';
            
            if (fieldData.type !== 'divider' && fieldData.type !== 'html' && fieldData.type !== 'heading') {
                html += '<label class="sscf-field-label">';
                html += fieldData.label;
                if (fieldData.required) {
                    html += ' <span class="required">*</span>';
                }
                html += '</label>';
            }
            
            // Render field input based on type
            html += this.renderFieldInput(fieldData);
            
            html += '</div></div>';
            
            return $(html);
        },
        
        renderFieldInput: function(fieldData) {
            let html = '';
            
            switch (fieldData.type) {
                case 'text':
                case 'email':
                case 'tel':
                case 'url':
                case 'number':
                    html = '<input type="' + fieldData.type + '" class="sscf-field-input" placeholder="' + (fieldData.placeholder || '') + '" disabled>';
                    break;
                    
                case 'textarea':
                    html = '<textarea class="sscf-field-input" rows="4" placeholder="' + (fieldData.placeholder || '') + '" disabled></textarea>';
                    break;
                    
                case 'select':
                    html = '<select class="sscf-field-input" disabled>';
                    html += '<option>Select an option...</option>';
                    if (fieldData.options && fieldData.options.length) {
                        fieldData.options.forEach(function(option) {
                            html += '<option>' + option + '</option>';
                        });
                    }
                    html += '</select>';
                    break;
                    
                case 'radio':
                    if (fieldData.options && fieldData.options.length) {
                        fieldData.options.forEach(function(option) {
                            html += '<label class="sscf-radio-option">';
                            html += '<input type="radio" name="' + fieldData.id + '" disabled> ' + option;
                            html += '</label>';
                        });
                    }
                    break;
                    
                case 'checkbox':
                    if (fieldData.options && fieldData.options.length) {
                        fieldData.options.forEach(function(option) {
                            html += '<label class="sscf-checkbox-option">';
                            html += '<input type="checkbox" disabled> ' + option;
                            html += '</label>';
                        });
                    }
                    break;
                    
                case 'file':
                    html = '<input type="file" class="sscf-field-input" disabled>';
                    break;
                    
                case 'date':
                    html = '<input type="date" class="sscf-field-input" disabled>';
                    break;
                    
                case 'time':
                    html = '<input type="time" class="sscf-field-input" disabled>';
                    break;
                    
                case 'html':
                    html = '<div class="sscf-html-content">' + (fieldData.content || '<p>HTML content</p>') + '</div>';
                    break;
                    
                case 'divider':
                    html = '<hr class="sscf-divider">';
                    break;
                    
                case 'heading':
                    const level = fieldData.level || 'h3';
                    html = '<' + level + ' class="sscf-heading">' + (fieldData.text || 'Section Heading') + '</' + level + '>';
                    break;
            }
            
            return html;
        },
        
        selectField: function(e) {
            e.stopPropagation();
            
            $('.sscf-builder-field').removeClass('sscf-field-selected');
            const $field = $(e.currentTarget);
            $field.addClass('sscf-field-selected');
            
            this.selectedField = $field.data('field-id');
        },
        
        openFieldSettings: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const $field = $(e.currentTarget).closest('.sscf-builder-field');
            const fieldId = $field.data('field-id');
            const fieldType = $field.data('field-type');
            
            this.showFieldSettingsModal(fieldId, fieldType, $field);
        },
        
        showFieldSettingsModal: function(fieldId, fieldType, $field) {
            // Create modal if it doesn't exist
            if (!$('.sscf-field-settings-modal').length) {
                $('body').append(this.createSettingsModal());
            }
            
            const $modal = $('.sscf-field-settings-modal');
            const $content = $modal.find('.sscf-modal-body');
            
            // Populate modal content based on field type
            $content.html(this.generateFieldSettings(fieldType, $field));
            
            $modal.addClass('active');
        },
        
        createSettingsModal: function() {
            return `
                <div class="sscf-field-settings-modal">
                    <div class="sscf-modal-content">
                        <div class="sscf-modal-header">
                            <h3>Field Settings</h3>
                            <button class="sscf-modal-close">&times;</button>
                        </div>
                        <div class="sscf-modal-body"></div>
                        <div class="sscf-modal-actions">
                            <button class="button button-secondary sscf-modal-close">Cancel</button>
                            <button class="button button-primary" id="save-field-settings">Save Settings</button>
                        </div>
                    </div>
                </div>
            `;
        },
        
        generateFieldSettings: function(fieldType, $field) {
            let html = '';
            
            // Common settings
            html += '<div class="setting-group">';
            html += '<label>Field Label</label>';
            html += '<input type="text" id="field-label" value="' + $field.find('.sscf-field-label').text().replace(' *', '') + '">';
            html += '</div>';
            
            html += '<div class="setting-group">';
            html += '<label><input type="checkbox" id="field-required"' + ($field.find('.required').length ? ' checked' : '') + '> Required field</label>';
            html += '</div>';
            
            // Type-specific settings
            if (['text', 'textarea', 'email', 'tel', 'number', 'url'].includes(fieldType)) {
                html += '<div class="setting-group">';
                html += '<label>Placeholder Text</label>';
                html += '<input type="text" id="field-placeholder" value="' + ($field.find('.sscf-field-input').attr('placeholder') || '') + '">';
                html += '</div>';
            }
            
            if (['select', 'radio', 'checkbox'].includes(fieldType)) {
                html += '<div class="setting-group">';
                html += '<label>Options (one per line)</label>';
                html += '<textarea id="field-options" rows="5">';
                $field.find('option:not(:first), .sscf-radio-option, .sscf-checkbox-option').each(function() {
                    const text = $(this).text().trim() || $(this).find('input').next().text().trim();
                    if (text) html += text + '\n';
                });
                html += '</textarea>';
                html += '</div>';
            }
            
            if (fieldType === 'html') {
                html += '<div class="setting-group">';
                html += '<label>HTML Content</label>';
                html += '<textarea id="field-html-content" rows="6">' + $field.find('.sscf-html-content').html() + '</textarea>';
                html += '</div>';
            }
            
            if (fieldType === 'heading') {
                html += '<div class="setting-group">';
                html += '<label>Heading Text</label>';
                html += '<input type="text" id="field-heading-text" value="' + $field.find('.sscf-heading').text() + '">';
                html += '</div>';
                
                html += '<div class="setting-group">';
                html += '<label>Heading Level</label>';
                html += '<select id="field-heading-level">';
                ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].forEach(function(level) {
                    const selected = $field.find('.' + level).length ? ' selected' : '';
                    html += '<option value="' + level + '"' + selected + '>' + level.toUpperCase() + '</option>';
                });
                html += '</select>';
                html += '</div>';
            }
            
            return html;
        },
        
        closeModal: function() {
            $('.sscf-field-settings-modal').removeClass('active');
        },
        
        duplicateField: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const $field = $(e.currentTarget).closest('.sscf-builder-field');
            const $clone = $field.clone();
            
            // Update field ID
            this.fieldCounter++;
            const newFieldId = 'field_' + this.fieldCounter;
            $clone.attr('data-field-id', newFieldId);
            
            $field.after($clone);
            this.updateFormData();
        },
        
        deleteField: function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (confirm(sscf_builder.strings.delete_confirm || 'Delete this field?')) {
                $(e.currentTarget).closest('.sscf-builder-field').remove();
                this.updateFormData();
                
                // Show placeholder if no fields left
                if (!$('#form-canvas .sscf-builder-field').length) {
                    $('#form-canvas').html('<div class="sscf-canvas-placeholder"><p>Drag fields here to build your form</p></div>');
                }
            }
        },
        
        clearForm: function(e) {
            e.preventDefault();
            
            if (confirm('Clear all fields from this form?')) {
                $('#form-canvas').html('<div class="sscf-canvas-placeholder"><p>Drag fields here to build your form</p></div>');
                this.formData.fields = [];
            }
        },
        
        updateFormData: function() {
            this.formData.fields = [];
            
            $('#form-canvas .sscf-builder-field').each((index, element) => {
                const $field = $(element);
                const fieldData = {
                    id: $field.data('field-id'),
                    type: $field.data('field-type'),
                    label: $field.find('.sscf-field-label').text().replace(' *', ''),
                    required: $field.find('.required').length > 0,
                    order: index
                };
                
                // Add type-specific data
                const fieldType = fieldData.type;
                
                if (['text', 'textarea', 'email', 'tel', 'number', 'url'].includes(fieldType)) {
                    fieldData.placeholder = $field.find('.sscf-field-input').attr('placeholder') || '';
                }
                
                if (['select', 'radio', 'checkbox'].includes(fieldType)) {
                    fieldData.options = [];
                    if (fieldType === 'select') {
                        $field.find('option:not(:first)').each(function() {
                            fieldData.options.push($(this).text());
                        });
                    } else {
                        $field.find('.sscf-' + fieldType + '-option').each(function() {
                            const text = $(this).text().trim();
                            if (text) fieldData.options.push(text);
                        });
                    }
                }
                
                if (fieldType === 'html') {
                    fieldData.content = $field.find('.sscf-html-content').html();
                }
                
                if (fieldType === 'heading') {
                    fieldData.text = $field.find('.sscf-heading').text();
                    fieldData.level = $field.find('.sscf-heading')[0].tagName.toLowerCase();
                }
                
                this.formData.fields.push(fieldData);
            });
        },
        
        saveForm: function(e) {
            e.preventDefault();
            
            const formName = $('#form-name').val().trim();
            if (!formName) {
                this.showNotification('Please enter a form name.', 'error');
                return;
            }
            
            // Validate form name for security
            if (!/^[a-zA-Z0-9\s\-_]+$/.test(formName)) {
                this.showNotification('Form name contains invalid characters. Use only letters, numbers, spaces, hyphens and underscores.', 'error');
                return;
            }
            
            this.updateFormData();
            
            const formData = {
                action: 'sscf_save_form',
                nonce: sscf_builder.nonce,
                form_id: $('#form-id').val() || 0,
                form_name: formName,
                form_description: ($('#form-description').val() || '').toString().trim(),
                form_fields: this.formData.fields,
                form_settings: {
                    success_message: $('textarea[name="success_message"]').val() || '',
                    submit_text: $('input[name="submit_text"]').val() || 'Submit',
                    notification_email: $('input[name="notification_email"]').val() || '',
                    spam_protection: $('input[name="spam_protection"]').is(':checked') || true
                }
            };
            
            const $saveBtn = $('#save-form');
            const $spinner = $('#save-spinner');
            
            $saveBtn.addClass('sscf-saving').prop('disabled', true);
            $spinner.addClass('is-active');
            
            $.ajax({
                url: sscf_builder.ajax_url,
                type: 'POST',
                data: formData,
                success: (response) => {
                    if (response.success) {
                        $saveBtn.removeClass('sscf-saving').addClass('sscf-save-success');
                        $saveBtn.text(sscf_builder.strings.saved);
                        
                        // Update form ID if new form
                        if (response.data.form_id && !$('#form-id').val()) {
                            $('#form-id').val(response.data.form_id);
                            
                            // Update URL without reload - fix URL manipulation bug
                            const urlParams = new URLSearchParams(window.location.search);
                            urlParams.set('form_id', response.data.form_id);
                            const newUrl = window.location.pathname + '?' + urlParams.toString();
                            window.history.pushState({}, '', newUrl);
                        }
                        
                        setTimeout(() => {
                            $saveBtn.removeClass('sscf-save-success').text('Save Form');
                        }, 2000);
                    } else {
                        this.showNotification(response.data || sscf_builder.strings.error, 'error');
                    }
                },
                error: () => {
                    this.showNotification(sscf_builder.strings.error, 'error');
                },
                complete: () => {
                    $saveBtn.removeClass('sscf-saving').prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        },
        
        previewForm: function(e) {
            e.preventDefault();
            
            this.updateFormData();
            
            if (!this.formData.fields.length) {
                this.showNotification('Add some fields to preview the form.', 'warning');
                return;
            }
            
            // Create preview modal
            const previewHtml = this.generatePreviewHtml();
            this.showPreviewModal(previewHtml);
        },
        
        generatePreviewHtml: function() {
            let html = '<form class="sscf-form-preview">';
            
            this.formData.fields.forEach((field) => {
                html += '<div class="sscf-form-field">';
                
                if (field.type !== 'divider' && field.type !== 'html' && field.type !== 'heading') {
                    html += '<label>' + field.label;
                    if (field.required) html += ' *';
                    html += '</label>';
                }
                
                html += this.renderPreviewField(field);
                html += '</div>';
            });
            
            html += '<div class="sscf-form-actions">';
            html += '<button type="button" class="button button-primary">Send Message</button>';
            html += '</div>';
            html += '</form>';
            
            return html;
        },
        
        renderPreviewField: function(field) {
            // Similar to renderFieldInput but without disabled attribute
            let html = '';
            
            switch (field.type) {
                case 'text':
                case 'email':
                case 'tel':
                case 'url':
                case 'number':
                    html = '<input type="' + field.type + '" placeholder="' + (field.placeholder || '') + '">';
                    break;
                    
                case 'textarea':
                    html = '<textarea rows="4" placeholder="' + (field.placeholder || '') + '"></textarea>';
                    break;
                    
                case 'select':
                    html = '<select><option>Select an option...</option>';
                    if (field.options) {
                        field.options.forEach(function(option) {
                            html += '<option>' + option + '</option>';
                        });
                    }
                    html += '</select>';
                    break;
                    
                case 'radio':
                    if (field.options) {
                        field.options.forEach(function(option) {
                            html += '<label><input type="radio" name="' + field.id + '"> ' + option + '</label>';
                        });
                    }
                    break;
                    
                case 'checkbox':
                    if (field.options) {
                        field.options.forEach(function(option) {
                            html += '<label><input type="checkbox"> ' + option + '</label>';
                        });
                    }
                    break;
                    
                case 'file':
                    html = '<input type="file">';
                    break;
                    
                case 'date':
                    html = '<input type="date">';
                    break;
                    
                case 'time':
                    html = '<input type="time">';
                    break;
                    
                case 'html':
                    html = '<div>' + (field.content || '') + '</div>';
                    break;
                    
                case 'divider':
                    html = '<hr>';
                    break;
                    
                case 'heading':
                    const level = field.level || 'h3';
                    html = '<' + level + '>' + (field.text || 'Section Heading') + '</' + level + '>';
                    break;
            }
            
            return html;
        },
        
        showPreviewModal: function(html) {
            const modalHtml = `
                <div class="sscf-preview-modal">
                    <div class="sscf-modal-content">
                        <div class="sscf-modal-header">
                            <h3>Form Preview</h3>
                            <button class="sscf-modal-close">&times;</button>
                        </div>
                        <div class="sscf-modal-body">
                            ${html}
                        </div>
                    </div>
                </div>
            `;
            
            $('body').append(modalHtml);
            $('.sscf-preview-modal').addClass('active');
            
            // Remove modal on close
            $(document).on('click', '.sscf-preview-modal .sscf-modal-close, .sscf-preview-modal', function(e) {
                if (e.target === this) {
                    $('.sscf-preview-modal').remove();
                }
            });
        },
        
        loadExistingForm: function() {
            const formId = $('#form-id').val();
            if (!formId) return;
            
            // Form data would already be loaded in PHP, this is just for consistency
            this.updateFormData();
        },
        
        copyShortcode: function(e) {
            e.preventDefault();
            
            const $btn = $(e.currentTarget);
            const shortcode = $btn.data('shortcode');
            
            navigator.clipboard.writeText(shortcode).then(() => {
                $btn.addClass('copied').text('Copied!');
                setTimeout(() => {
                    $btn.removeClass('copied').text('Copy');
                }, 2000);
            });
        },
        
        showNotification: function(message, type = 'info') {
            // Remove existing notifications
            $('.sscf-notification').remove();
            
            // Create notification element with proper escaping
            const notificationClass = `sscf-notification sscf-notification-${type}`;
            const notification = $(`
                <div class="${notificationClass}">
                    <span class="sscf-notification-message"></span>
                    <button class="sscf-notification-close">&times;</button>
                </div>
            `);
            
            // Safely set the message content
            notification.find('.sscf-notification-message').text(message);
            
            // Add to top of form builder
            $('.sscf-form-builder').prepend(notification);
            
            // Auto-hide after 5 seconds for success/info messages
            if (type === 'success' || type === 'info') {
                setTimeout(() => {
                    notification.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
            
            // Handle close button
            notification.find('.sscf-notification-close').on('click', function() {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },
        
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        deleteForm: function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to delete this form?')) {
                return;
            }
            
            const formId = $(e.currentTarget).data('form-id');
            
            $.ajax({
                url: sscf_builder.ajax_url,
                type: 'POST',
                data: {
                    action: 'sscf_delete_form',
                    nonce: sscf_builder.nonce,
                    form_id: formId
                },
                success: (response) => {
                    if (response.success) {
                        $(e.currentTarget).closest('tr').fadeOut(() => {
                            $(e.currentTarget).closest('tr').remove();
                        });
                        this.showNotification('Form deleted successfully.', 'success');
                    } else {
                        this.showNotification(response.data || 'Error deleting form', 'error');
                    }
                },
                error: () => {
                    this.showNotification('Error deleting form', 'error');
                }
            });
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        if ($('.sscf-form-builder').length) {
            SSCFFormBuilder.init();
        }
    });
    
    // Make available globally for debugging
    window.SSCFFormBuilder = SSCFFormBuilder;
    
})(jQuery);
