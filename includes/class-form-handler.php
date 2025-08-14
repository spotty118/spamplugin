<?php
/**
 * Form Handler Class
 * Processes form submissions, validates data, and coordinates with other classes
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Form_Handler {
    
    private $spam_protection;
    private $email_sender;
    
    public function __construct() {
        $this->spam_protection = new SSCF_Spam_Protection();
        $this->email_sender = new SSCF_Email_Sender();
    }
    
    /**
     * Process form submission
     *
     * @param array $data POST data from form
     * @return array Response data for AJAX
     */
    public function process_submission($data) {
        // Sanitize input data
        $sanitized_data = $this->sanitize_form_data($data);
        
        // Validate required fields
        $validation_result = $this->validate_form_data($sanitized_data);
        if (!$validation_result['valid']) {
            return array(
                'success' => false,
                'message' => $validation_result['message']
            );
        }
        
        // Check for spam (augment with canonical fields for AI/content analysis)
        $canonical_data = $this->augment_with_canonical_fields($sanitized_data);
        $spam_check = $this->spam_protection->is_spam($canonical_data);
        if ($spam_check['is_spam']) {
            // For spam submissions, we return success to not tip off bots
            // but don't actually send the email
            $this->log_spam_attempt_analytics($canonical_data, $spam_check);
            return array(
                'success' => true,
                'message' => $this->get_success_message()
            );
        }
        
        // Save entry to database
        $entry_saved = $this->save_entry($sanitized_data, $spam_check['is_spam']);
        
        // Send email (only if not spam)
        if (!$spam_check['is_spam']) {
            $email_result = $this->email_sender->send_contact_email($sanitized_data);
            
            if (!$email_result['success'] && $entry_saved) {
                // Entry saved but email failed - still show success to user
                error_log('SpamShield: Entry saved but email failed: ' . $email_result['message']);
            }
        }
        
        $this->log_successful_submission_analytics($canonical_data);
        return array(
            'success' => true,
            'message' => $this->get_success_message()
        );
    }
    
    /**
     * Sanitize form data
     *
     * @param array $data Raw form data
     * @return array Sanitized data
     */
    private function sanitize_form_data($data) {
        $sanitized = array(
            'website' => sanitize_text_field($data['website'] ?? ''), // Honeypot field
            'sscf_timestamp' => sanitize_text_field($data['sscf_timestamp'] ?? '')
        );
        
        // Get form fields configuration
        global $spamshield_contact_form;
        $form_fields = $spamshield_contact_form ? $spamshield_contact_form->get_form_fields() : get_option('sscf_form_fields', array());
        
        // Sanitize each configured form field
        foreach ($form_fields as $field) {
            $field_name = 'sscf_' . $field['id'];
            if (isset($data[$field_name])) {
                if ($field['type'] === 'email') {
                    $sanitized[$field_name] = sanitize_email($data[$field_name]);
                } elseif ($field['type'] === 'textarea') {
                    $sanitized[$field_name] = sanitize_textarea_field($data[$field_name]);
                } else {
                    $sanitized[$field_name] = sanitize_text_field($data[$field_name]);
                }
            } else {
                $sanitized[$field_name] = '';
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Validate form data
     *
     * @param array $data Sanitized form data
     * @return array Validation result
     */
    private function validate_form_data($data) {
        $errors = array();
        global $spamshield_contact_form;
        $form_fields = $spamshield_contact_form ? $spamshield_contact_form->get_form_fields() : get_option('sscf_form_fields', array());
        
        // Validate each configured form field
        foreach ($form_fields as $field) {
            $field_name = 'sscf_' . $field['id'];
            $field_value = $data[$field_name] ?? '';
            
            // Check required fields
            if ($field['required'] && empty($field_value)) {
                $errors[] = sprintf(__('%s is required.', 'spamshield-cf'), $field['label']);
                continue;
            }
            
            // Skip validation if field is empty and not required
            if (empty($field_value)) {
                continue;
            }
            
            // Field-specific validation
            switch ($field['type']) {
                case 'email':
                    if (!is_email($field_value)) {
                        $errors[] = sprintf(__('Please enter a valid email address for %s.', 'spamshield-cf'), $field['label']);
                    }
                    break;
                    
                case 'tel':
                    // Basic phone validation
                    if (!preg_match('/^[+]?[0-9\s\-\(\)]+$/', $field_value)) {
                        $errors[] = sprintf(__('Please enter a valid phone number for %s.', 'spamshield-cf'), $field['label']);
                    }
                    break;
                    
                case 'url':
                    if (!filter_var($field_value, FILTER_VALIDATE_URL)) {
                        $errors[] = sprintf(__('Please enter a valid URL for %s.', 'spamshield-cf'), $field['label']);
                    }
                    break;
            }
            
            // Length validation
            $max_length = ($field['type'] === 'textarea') ? 5000 : 500;
            if (strlen($field_value) > $max_length) {
                $errors[] = sprintf(__('%s must be less than %d characters.', 'spamshield-cf'), $field['label'], $max_length);
            }
        }
        
        // Additional security checks
        if ($this->contains_suspicious_content($data)) {
            $errors[] = __('Your message contains content that cannot be processed.', 'spamshield-cf');
        }
        
        if (!empty($errors)) {
            return array(
                'valid' => false,
                'message' => implode(' ', $errors)
            );
        }
        
        return array(
            'valid' => true,
            'message' => ''
        );
    }
    
    /**
     * Check for suspicious content patterns
     *
     * @param array $data Form data
     * @return bool True if suspicious content found
     */
    private function contains_suspicious_content($data) {
        $suspicious_patterns = array(
            '/\[url=/', // BBCode links
            '/\[link=/', // BBCode links
            '/<script/', // Script tags
            '/javascript:/', // JavaScript protocol
            '/vbscript:/', // VBScript protocol
            '/onload=/', // Event handlers
            '/onclick=/', // Event handlers
            '/href=.*javascript/', // JavaScript in links
        );
        
        // Collect all form field values dynamically
        $content_values = array();
        global $spamshield_contact_form;
        $form_fields = $spamshield_contact_form ? $spamshield_contact_form->get_form_fields() : get_option('sscf_form_fields', array());
        
        foreach ($form_fields as $field) {
            $field_name = 'sscf_' . $field['id'];
            if (isset($data[$field_name]) && !empty($data[$field_name])) {
                $content_values[] = $data[$field_name];
            }
        }
        
        // If no dynamic fields found, fallback to checking all data values
        if (empty($content_values)) {
            foreach ($data as $key => $value) {
                if (strpos($key, 'sscf_') === 0 && !empty($value)) {
                    $content_values[] = $value;
                }
            }
        }
        
        $all_content = implode(' ', $content_values);
        
        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $all_content)) {
                return true;
            }
        }
        
        // Check for excessive URLs (more than 3 URLs might be spam)
        $url_count = preg_match_all('/https?:\/\/[^\s]+/', $all_content);
        if ($url_count > 3) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get success message from options
     *
     * @return string Success message
     */
    private function get_success_message() {
        $options = get_option('sscf_options', array());
        return !empty($options['success_message']) 
            ? $options['success_message'] 
            : __('Thank you! Your message has been sent successfully.', 'spamshield-cf');
    }
    
    /**
     * Handle non-AJAX form submission (fallback)
     *
     * @param array $data POST data
     * @return void
     */
    public function handle_non_ajax_submission($data) {
        $result = $this->process_submission($data);
        
        // Store result in session/transient for display
        $message_key = 'sscf_form_message_' . uniqid();
        set_transient($message_key, $result, 300); // 5 minutes
        
        // Redirect back to form with message parameter
        $redirect_url = add_query_arg('sscf_msg', $message_key, wp_get_referer());
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    /**
     * Get form message for non-AJAX submissions
     *
     * @return array|null Message data or null if no message
     */
    public function get_form_message() {
        if (!empty($_GET['sscf_msg'])) {
            $message_key = sanitize_text_field($_GET['sscf_msg']);
            $message_data = get_transient($message_key);
            
            if ($message_data) {
                // Delete the transient so message only shows once
                delete_transient($message_key);
                return $message_data;
            }
        }
        
        return null;
    }
    
    /**
     * Save form entry to database
     *
     * @param array $data Sanitized form data
     * @param bool $is_spam Whether entry is spam
     * @return bool Success status
     */
    private function save_entry($data, $is_spam = false) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sscf_entries';
        
        // Get form fields configuration for hash
        global $spamshield_contact_form;
        $form_fields = $spamshield_contact_form ? $spamshield_contact_form->get_form_fields() : get_option('sscf_form_fields', array());
        $form_fields_hash = md5(json_encode($form_fields));
        
        // Prepare entry data (exclude honeypot and timestamp)
        $entry_data = array();
        foreach ($form_fields as $field) {
            $field_name = 'sscf_' . $field['id'];
            if (isset($data[$field_name])) {
                $value = $data[$field_name];
                
                // Handle checkbox arrays
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                
                $entry_data[$field['label']] = $value;
            }
        }
        
        // Get user info
        $user_ip = $this->get_user_ip();
        $user_agent = !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        
        // Insert into database
        $result = $wpdb->insert(
            $table_name,
            array(
                'form_fields_hash' => $form_fields_hash,
                'entry_data' => wp_json_encode($entry_data),
                'user_ip' => $user_ip,
                'user_agent' => $user_agent,
                'spam_score' => $is_spam ? 100 : 0,
                'status' => $is_spam ? 'spam' : 'submitted',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        return $result !== false;
    }

    /**
     * Augment sanitized form data with canonical keys used by AI/content analysis
     *
     * @param array $data
     * @return array
     */
    private function augment_with_canonical_fields(array $data) {
        global $spamshield_contact_form;
        $form_fields = $spamshield_contact_form ? $spamshield_contact_form->get_form_fields() : get_option('sscf_form_fields', array());
        $canonical = $data;
        $content = '';
        $author = '';
        $email = '';

        foreach ($form_fields as $field) {
            $id = 'sscf_' . $field['id'];
            $label_l = strtolower($field['label'] ?? '');
            $id_l = strtolower($field['id'] ?? '');
            $value = $data[$id] ?? '';

            if ($value === '') {
                continue;
            }

            if ($field['type'] === 'textarea' || strpos($label_l, 'message') !== false || strpos($id_l, 'message') !== false) {
                $content = $content ?: $value;
            }

            if (strpos($label_l, 'name') !== false || strpos($id_l, 'name') !== false) {
                $author = $author ?: $value;
            }

            if ($field['type'] === 'email' || strpos($label_l, 'email') !== false || strpos($id_l, 'email') !== false) {
                $maybe_email = sanitize_email($value);
                if (is_email($maybe_email)) {
                    $email = $email ?: $maybe_email;
                }
            }
        }

        if ($content !== '') {
            $canonical['content'] = $content;
        }
        if ($author !== '') {
            $canonical['author'] = $author;
        }
        if ($email !== '') {
            $canonical['email'] = $email;
        }

        return $canonical;
    }
    
    /**
     * Get user IP address
     *
     * @return string IP address
     */
    private function get_user_ip() {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    private function log_successful_submission_analytics(array $canonical) {
        global $wpdb;
        $table = $wpdb->prefix . 'sscf_comment_analytics';
        $content_preview = isset($canonical['content']) ? substr(wp_strip_all_tags($canonical['content']), 0, 100) : 'Contact form submission';
        $wpdb->insert($table, array(
            'site_id' => get_current_blog_id(),
            'entry_type' => 'contact_form',
            'spam_score' => 0,
            'detection_method' => 'clean',
            'user_ip' => $this->get_user_ip(),
            'user_agent' => !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
            'post_id' => 0,
            'content_preview' => $content_preview,
            'timestamp' => current_time('mysql'),
            'is_spam' => 0
        ));
    }
    
    private function log_spam_attempt_analytics(array $canonical, array $spam_check) {
        global $wpdb;
        $table = $wpdb->prefix . 'sscf_comment_analytics';
        $content_preview = isset($canonical['content']) ? substr(wp_strip_all_tags($canonical['content']), 0, 100) : 'Spam attempt on contact form';
        $spam_score = isset($spam_check['spam_score']) ? intval($spam_check['spam_score']) : 100;
        $reason = !empty($spam_check['reason']) ? $spam_check['reason'] : 'spam_protection';
        $wpdb->insert($table, array(
            'site_id' => get_current_blog_id(),
            'entry_type' => 'contact_form',
            'spam_score' => $spam_score,
            'detection_method' => $reason,
            'user_ip' => $this->get_user_ip(),
            'user_agent' => !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
            'post_id' => 0,
            'content_preview' => $content_preview,
            'timestamp' => current_time('mysql'),
            'is_spam' => 1
        ));
    }

        
        return !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}
