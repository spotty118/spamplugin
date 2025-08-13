<?php
/**
 * Frontend Form Handler Class
 * Handles custom form rendering and submission on frontend
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Frontend_Form_Handler {
    
    private $forms_table;
    private $entries_table;
    private $analytics_table;
    
    public function __construct() {
        global $wpdb;
        $this->forms_table = $wpdb->prefix . 'sscf_forms';
        $this->entries_table = $wpdb->prefix . 'sscf_entries';
        $this->analytics_table = $wpdb->prefix . 'sscf_comment_analytics';
        
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_ajax_sscf_submit_custom_form', array($this, 'handle_form_submission'));
        add_action('wp_ajax_nopriv_sscf_submit_custom_form', array($this, 'handle_form_submission'));
        
        // Enhanced shortcode
        add_shortcode('spamshield_custom_form', array($this, 'render_form_shortcode'));
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only enqueue on pages with forms
        if ($this->page_has_custom_forms()) {
            wp_enqueue_style(
                'sscf-frontend-forms',
                SSCF_PLUGIN_URL . 'assets/css/frontend-forms.css',
                array(),
                SSCF_VERSION
            );
            
            wp_enqueue_script(
                'sscf-frontend-forms',
                SSCF_PLUGIN_URL . 'assets/js/frontend-forms.js',
                array('jquery'),
                SSCF_VERSION,
                true
            );
            
            wp_localize_script('sscf-frontend-forms', 'sscf_frontend', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sscf_frontend_nonce'),
                'strings' => array(
                    'sending' => __('Sending...', 'spamshield-cf'),
                    'error' => __('There was an error. Please try again.', 'spamshield-cf'),
                    'required' => __('This field is required.', 'spamshield-cf'),
                    'invalid_email' => __('Please enter a valid email address.', 'spamshield-cf'),
                    'invalid_url' => __('Please enter a valid URL.', 'spamshield-cf'),
                    'file_too_large' => __('File is too large.', 'spamshield-cf'),
                    'invalid_file_type' => __('File type not allowed.', 'spamshield-cf')
                )
            ));
        }
    }
    
    /**
     * Check if current page has custom forms
     */
    private function page_has_custom_forms() {
        global $post;
        
        if (!$post) return false;
        
        // Check for shortcode in post content
        return has_shortcode($post->post_content, 'spamshield_custom_form');
    }
    
    /**
     * Render form shortcode
     */
    public function render_form_shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'title' => 'true',
            'description' => 'true'
        ), $atts);
        
        $form_id = intval($atts['id']);
        if ($form_id <= 0) {
            return '<p class="sscf-error">' . __('Form ID is required.', 'spamshield-cf') . '</p>';
        }
        
        $form = $this->get_form($form_id);
        if (!$form || !$form->is_active) {
            return '<p class="sscf-error">' . __('Form not found or inactive.', 'spamshield-cf') . '</p>';
        }
        
        $form_fields = json_decode($form->form_fields, true);
        $form_settings = json_decode($form->form_settings, true);
        
        if (empty($form_fields)) {
            return '<p class="sscf-error">' . __('This form has no fields configured.', 'spamshield-cf') . '</p>';
        }
        
        ob_start();
        $this->render_form_html($form, $form_fields, $form_settings, $atts);
        return ob_get_clean();
    }
    
    /**
     * Get form data
     */
    private function get_form($form_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->forms_table} WHERE id = %d",
            $form_id
        ));
    }
    
    /**
     * Render complete form HTML
     */
    private function render_form_html($form, $form_fields, $form_settings, $atts) {
        $show_title = $atts['title'] === 'true';
        $show_description = $atts['description'] === 'true';
        $submit_text = $form_settings['submit_text'] ?? __('Send Message', 'spamshield-cf');
        
        echo '<div class="sscf-custom-form-container" id="sscf-form-' . esc_attr($form->id) . '">';
        
        // Form header
        if ($show_title && $form->form_name) {
            echo '<h3 class="sscf-form-title">' . esc_html($form->form_name) . '</h3>';
        }
        
        if ($show_description && $form->form_description) {
            echo '<div class="sscf-form-description">' . wpautop(esc_html($form->form_description)) . '</div>';
        }
        
        // Success/error messages
        echo '<div class="sscf-form-messages" style="display: none;">';
        echo '<div class="sscf-success-message"></div>';
        echo '<div class="sscf-error-message"></div>';
        echo '</div>';
        
        // Form
        echo '<form class="sscf-custom-form" data-form-id="' . esc_attr($form->id) . '" novalidate>';
        wp_nonce_field('sscf_frontend_nonce', 'sscf_nonce');
        
        // Spam protection fields
        if ($form_settings['spam_protection'] ?? true) {
            echo $this->render_spam_protection_fields();
        }
        
        // Form fields
        foreach ($form_fields as $field) {
            echo $this->render_frontend_field($field);
        }
        
        // Submit button
        echo '<div class="sscf-form-actions">';
        echo '<button type="submit" class="sscf-submit-btn">';
        echo '<span class="sscf-submit-text">' . esc_html($submit_text) . '</span>';
        echo '<span class="sscf-submit-spinner" style="display: none;">⟳</span>';
        echo '</button>';
        echo '</div>';
        
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Render spam protection fields
     */
    private function render_spam_protection_fields() {
        $timestamp = time();
        
        $html = '<div class="sscf-spam-protection" style="display: none !important;">';
        $html .= '<label for="sscf_website">' . esc_html__('Website (leave blank):', 'spamshield-cf') . '</label>';
        $html .= '<input type="text" id="sscf_website" name="sscf_website" value="" tabindex="-1" autocomplete="off">';
        $html .= '</div>';
        $html .= '<input type="hidden" name="sscf_timestamp" value="' . $timestamp . '">';
        $html .= '<input type="hidden" name="sscf_form_loaded" value="' . $timestamp . '">';
        
        return $html;
    }
    
    /**
     * Render individual field for frontend
     */
    private function render_frontend_field($field) {
        $field_id = 'sscf_' . $field['id'];
        $field_name = 'sscf_fields[' . $field['id'] . ']';
        $field_type = $field['type'];
        $field_label = $field['label'] ?? '';
        $required = !empty($field['required']);
        $placeholder = $field['placeholder'] ?? '';
        
        // Skip layout fields that don't need form wrapping
        if (in_array($field_type, array('html', 'divider', 'heading'))) {
            return $this->render_layout_field($field);
        }
        
        $html = '<div class="sscf-form-field sscf-field-type-' . esc_attr($field_type) . '"';
        if ($required) {
            $html .= ' data-required="true"';
        }
        $html .= '>';
        
        // Label
        if ($field_label) {
            $html .= '<label for="' . esc_attr($field_id) . '" class="sscf-field-label">';
            $html .= esc_html($field_label);
            if ($required) {
                $html .= ' <span class="sscf-required" aria-label="required">*</span>';
            }
            $html .= '</label>';
        }
        
        // Field input
        $html .= $this->render_field_input($field, $field_id, $field_name, $placeholder, $required);
        
        // Validation message
        $html .= '<div class="sscf-field-error" style="display: none;"></div>';
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render layout fields (HTML, divider, heading)
     */
    private function render_layout_field($field) {
        $field_type = $field['type'];
        
        switch ($field_type) {
            case 'html':
                return '<div class="sscf-html-field">' . wp_kses_post($field['content'] ?? '') . '</div>';
                
            case 'divider':
                return '<hr class="sscf-divider-field">';
                
            case 'heading':
                $level = $field['level'] ?? 'h3';
                $text = $field['text'] ?? 'Section Heading';
                return '<' . esc_attr($level) . ' class="sscf-heading-field">' . esc_html($text) . '</' . esc_attr($level) . '>';
        }
        
        return '';
    }
    
    /**
     * Render field input element
     */
    private function render_field_input($field, $field_id, $field_name, $placeholder, $required) {
        $field_type = $field['type'];
        $html = '';
        
        switch ($field_type) {
            case 'text':
            case 'email':
            case 'tel':
            case 'url':
            case 'number':
                $html = '<input type="' . esc_attr($field_type) . '" ';
                $html .= 'id="' . esc_attr($field_id) . '" ';
                $html .= 'name="' . esc_attr($field_name) . '" ';
                $html .= 'class="sscf-field-input" ';
                if ($placeholder) {
                    $html .= 'placeholder="' . esc_attr($placeholder) . '" ';
                }
                if ($required) {
                    $html .= 'required ';
                }
                $html .= '>';
                break;
                
            case 'textarea':
                $html = '<textarea ';
                $html .= 'id="' . esc_attr($field_id) . '" ';
                $html .= 'name="' . esc_attr($field_name) . '" ';
                $html .= 'class="sscf-field-input" ';
                $html .= 'rows="4" ';
                if ($placeholder) {
                    $html .= 'placeholder="' . esc_attr($placeholder) . '" ';
                }
                if ($required) {
                    $html .= 'required ';
                }
                $html .= '></textarea>';
                break;
                
            case 'select':
                $html = '<select ';
                $html .= 'id="' . esc_attr($field_id) . '" ';
                $html .= 'name="' . esc_attr($field_name) . '" ';
                $html .= 'class="sscf-field-input" ';
                if ($required) {
                    $html .= 'required ';
                }
                $html .= '>';
                $html .= '<option value="">' . __('Select an option...', 'spamshield-cf') . '</option>';
                
                if (!empty($field['options'])) {
                    foreach ($field['options'] as $option) {
                        $html .= '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
                    }
                }
                $html .= '</select>';
                break;
                
            case 'radio':
                if (!empty($field['options'])) {
                    $html .= '<div class="sscf-radio-group">';
                    foreach ($field['options'] as $i => $option) {
                        $option_id = $field_id . '_' . $i;
                        $html .= '<label class="sscf-radio-option">';
                        $html .= '<input type="radio" ';
                        $html .= 'id="' . esc_attr($option_id) . '" ';
                        $html .= 'name="' . esc_attr($field_name) . '" ';
                        $html .= 'value="' . esc_attr($option) . '" ';
                        if ($required) {
                            $html .= 'required ';
                        }
                        $html .= '>';
                        $html .= '<span class="sscf-radio-text">' . esc_html($option) . '</span>';
                        $html .= '</label>';
                    }
                    $html .= '</div>';
                }
                break;
                
            case 'checkbox':
                if (!empty($field['options'])) {
                    $html .= '<div class="sscf-checkbox-group">';
                    foreach ($field['options'] as $i => $option) {
                        $option_id = $field_id . '_' . $i;
                        $option_name = $field_name . '[' . $i . ']';
                        $html .= '<label class="sscf-checkbox-option">';
                        $html .= '<input type="checkbox" ';
                        $html .= 'id="' . esc_attr($option_id) . '" ';
                        $html .= 'name="' . esc_attr($option_name) . '" ';
                        $html .= 'value="' . esc_attr($option) . '" ';
                        $html .= '>';
                        $html .= '<span class="sscf-checkbox-text">' . esc_html($option) . '</span>';
                        $html .= '</label>';
                    }
                    $html .= '</div>';
                }
                break;
                
            case 'file':
                $html = '<input type="file" ';
                $html .= 'id="' . esc_attr($field_id) . '" ';
                $html .= 'name="' . esc_attr($field_name) . '" ';
                $html .= 'class="sscf-field-input" ';
                if ($required) {
                    $html .= 'required ';
                }
                
                // Add file restrictions if configured
                if (!empty($field['accept'])) {
                    $html .= 'accept="' . esc_attr($field['accept']) . '" ';
                }
                
                $html .= '>';
                
                // File upload help text
                $html .= '<small class="sscf-file-help">';
                $html .= __('Max file size: 10MB. Allowed types: PDF, DOC, DOCX, JPG, PNG, GIF', 'spamshield-cf');
                $html .= '</small>';
                break;
                
            case 'date':
                $html = '<input type="date" ';
                $html .= 'id="' . esc_attr($field_id) . '" ';
                $html .= 'name="' . esc_attr($field_name) . '" ';
                $html .= 'class="sscf-field-input" ';
                if ($required) {
                    $html .= 'required ';
                }
                $html .= '>';
                break;
                
            case 'time':
                $html = '<input type="time" ';
                $html .= 'id="' . esc_attr($field_id) . '" ';
                $html .= 'name="' . esc_attr($field_name) . '" ';
                $html .= 'class="sscf-field-input" ';
                if ($required) {
                    $html .= 'required ';
                }
                $html .= '>';
                break;
        }
        
        return $html;
    }
    
    /**
     * Handle form submission
     */
    public function handle_form_submission() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['sscf_nonce'], 'sscf_frontend_nonce')) {
            wp_send_json_error(__('Security check failed. Please refresh and try again.', 'spamshield-cf'));
        }
        
        $form_id = intval($_POST['form_id'] ?? 0);
        if ($form_id <= 0) {
            wp_send_json_error(__('Invalid form.', 'spamshield-cf'));
        }
        
        // Get form configuration
        $form = $this->get_form($form_id);
        if (!$form || !$form->is_active) {
            wp_send_json_error(__('Form not found or inactive.', 'spamshield-cf'));
        }
        
        $form_fields = json_decode($form->form_fields, true);
        $form_settings = json_decode($form->form_settings, true);
        
        // Spam protection check
        if ($form_settings['spam_protection'] ?? true) {
            $spam_check = $this->check_spam_protection($_POST);
            if ($spam_check['is_spam']) {
                // Log spam attempt
                $this->log_spam_attempt($form_id, $spam_check);
                wp_send_json_error(__('Spam detected. Submission blocked.', 'spamshield-cf'));
            }
        }
        
        // Validate and process form data
        $form_data = $this->validate_form_data($_POST['sscf_fields'] ?? array(), $form_fields);
        
        if (!empty($form_data['errors'])) {
            wp_send_json_error(array(
                'message' => __('Please correct the errors below.', 'spamshield-cf'),
                'field_errors' => $form_data['errors']
            ));
        }
        
        // Save form entry
        $entry_id = $this->save_form_entry($form_id, $form_data['data'], $form_fields);
        
        if ($entry_id) {
            // Send email notifications
            $this->send_form_notifications($form, $form_data['data'], $form_settings);
            
            // Log successful submission
            $this->log_successful_submission($form_id);
            
            $success_message = $form_settings['success_message'] ?? __('Thank you! Your message has been sent successfully.', 'spamshield-cf');
            
            wp_send_json_success(array(
                'message' => $success_message,
                'entry_id' => $entry_id
            ));
        } else {
            wp_send_json_error(__('There was an error saving your submission. Please try again.', 'spamshield-cf'));
        }
    }
    
    /**
     * Check spam protection
     */
    private function check_spam_protection($post_data) {
        $spam_protection = new SSCF_Spam_Protection();
        
        $submission_data = array(
            'website' => sanitize_text_field($post_data['sscf_website'] ?? ''),
            'sscf_timestamp' => intval($post_data['sscf_timestamp'] ?? 0),
            'form_loaded' => intval($post_data['sscf_form_loaded'] ?? 0),
            'user_ip' => $this->get_user_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'submission_time' => time()
        );
        
        return $spam_protection->is_spam($submission_data);
    }
    
    /**
     * Validate form data
     */
    private function validate_form_data($submitted_data, $form_fields) {
        $validated_data = array();
        $errors = array();
        
        foreach ($form_fields as $field) {
            $field_id = $field['id'];
            $field_type = $field['type'];
            $field_label = $field['label'] ?? 'Field';
            $required = !empty($field['required']);
            
            // Skip layout fields
            if (in_array($field_type, array('html', 'divider', 'heading'))) {
                continue;
            }
            
            $value = $submitted_data[$field_id] ?? '';
            
            // Handle array values (checkboxes)
            if (is_array($value)) {
                $value = array_filter($value);
                $value = implode(', ', $value);
            }
            
            $value = sanitize_textarea_field($value);
            
            // Required field validation
            if ($required && empty($value)) {
                $errors[$field_id] = sprintf(__('%s is required.', 'spamshield-cf'), $field_label);
                continue;
            }
            
            // Type-specific validation
            if (!empty($value)) {
                switch ($field_type) {
                    case 'email':
                        if (!is_email($value)) {
                            $errors[$field_id] = sprintf(__('%s must be a valid email address.', 'spamshield-cf'), $field_label);
                        }
                        break;
                        
                    case 'url':
                        if (!filter_var($value, FILTER_VALIDATE_URL)) {
                            $errors[$field_id] = sprintf(__('%s must be a valid URL.', 'spamshield-cf'), $field_label);
                        }
                        break;
                        
                    case 'number':
                        if (!is_numeric($value)) {
                            $errors[$field_id] = sprintf(__('%s must be a number.', 'spamshield-cf'), $field_label);
                        }
                        break;
                        
                    case 'tel':
                        // Basic phone validation
                        if (!preg_match('/^[\d\s\-\+\(\)\.]+$/', $value)) {
                            $errors[$field_id] = sprintf(__('%s must be a valid phone number.', 'spamshield-cf'), $field_label);
                        }
                        break;
                }
            }
            
            $validated_data[$field_id] = array(
                'label' => $field_label,
                'value' => $value,
                'type' => $field_type
            );
        }
        
        return array(
            'data' => $validated_data,
            'errors' => $errors
        );
    }
    
    /**
     * Save form entry to database
     */
    private function save_form_entry($form_id, $form_data, $form_fields) {
        global $wpdb;
        
        $entry_data = array(
            'form_id' => $form_id,
            'fields' => $form_data,
            'submitted_at' => current_time('mysql'),
            'user_ip' => $this->get_user_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        
        $result = $wpdb->insert(
            $this->entries_table,
            array(
                'form_fields_hash' => md5('custom_form_' . $form_id),
                'entry_data' => wp_json_encode($entry_data),
                'user_ip' => $this->get_user_ip(),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'spam_score' => 0,
                'status' => 'submitted',
                'created_at' => current_time('mysql')
            )
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Send form notifications
     */
    private function send_form_notifications($form, $form_data, $form_settings) {
        $notification_email = $form_settings['notification_email'] ?? get_option('admin_email');
        
        if (empty($notification_email)) {
            return;
        }
        
        $subject = sprintf(__('New form submission: %s', 'spamshield-cf'), $form->form_name);
        
        $message = sprintf(__("New form submission received:\n\nForm: %s\n\n", 'spamshield-cf'), $form->form_name);
        
        foreach ($form_data as $field_id => $field_data) {
            $message .= sprintf("%s: %s\n", $field_data['label'], $field_data['value']);
        }
        
        $message .= sprintf(__("\n\nSubmitted: %s", 'spamshield-cf'), current_time('Y-m-d H:i:s'));
        $message .= sprintf(__("\nIP Address: %s", 'spamshield-cf'), $this->get_user_ip());
        
        wp_mail($notification_email, $subject, $message);
    }
    
    /**
     * Log successful submission
     */
    private function log_successful_submission($form_id) {
        global $wpdb;
        
        $wpdb->insert($this->analytics_table, array(
            'site_id' => get_current_blog_id(),
            'entry_type' => 'custom_form',
            'spam_score' => 0,
            'detection_method' => 'clean',
            'user_ip' => $this->get_user_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'post_id' => $form_id,
            'content_preview' => 'Custom form submission',
            'timestamp' => current_time('mysql'),
            'is_spam' => 0
        ));
    }
    
    /**
     * Log spam attempt
     */
    private function log_spam_attempt($form_id, $spam_result) {
        global $wpdb;
        
        $wpdb->insert($this->analytics_table, array(
            'site_id' => get_current_blog_id(),
            'entry_type' => 'custom_form',
            'spam_score' => $spam_result['spam_score'] ?? 100,
            'detection_method' => $spam_result['reason'] ?? 'spam_protection',
            'user_ip' => $this->get_user_ip(),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'post_id' => $form_id,
            'content_preview' => 'Spam attempt on custom form',
            'timestamp' => current_time('mysql'),
            'is_spam' => 1
        ));
    }
    
    /**
     * Get user IP address
     */
    private function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        }
    }
}
