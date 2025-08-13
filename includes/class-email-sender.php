<?php
/**
 * Email Sender Class
 * Handles email formatting and sending via wp_mail()
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Email_Sender {
    
    /**
     * Send contact form email
     *
     * @param array $data Sanitized form data
     * @return array Result with 'success' boolean and 'message' string
     */
    public function send_contact_email($data) {
        $options = get_option('sscf_options', array());
        
        // Get recipient email (default to admin email)
        $to_email = !empty($options['email_recipient']) 
            ? $options['email_recipient'] 
            : get_option('admin_email');
        
        // Validate recipient email
        if (!is_email($to_email)) {
            return array(
                'success' => false,
                'message' => 'Invalid recipient email address configured.'
            );
        }
        
        // Prepare email components
        $subject = $this->prepare_email_subject($data);
        $message = $this->prepare_email_message($data);
        $headers = $this->prepare_email_headers($data);
        
        // Send email using WordPress wp_mail function
        $email_sent = wp_mail($to_email, $subject, $message, $headers);
        
        if ($email_sent) {
            $this->increment_email_count();
            return array(
                'success' => true,
                'message' => 'Email sent successfully.'
            );
        } else {
            // Log error for debugging (admin only)
            if (current_user_can('manage_options')) {
                error_log('SpamShield Contact Form: Failed to send email to ' . $to_email);
            }
            
            return array(
                'success' => false,
                'message' => 'Failed to send email.'
            );
        }
    }
    
    /**
     * Prepare email subject
     *
     * @param array $data Form data
     * @return string Email subject
     */
    private function prepare_email_subject($data) {
        $site_name = get_bloginfo('name');
        
        // Try to find a subject field dynamically
        $form_subject = '';
        $form_fields = get_option('sscf_form_fields', array());
        
        foreach ($form_fields as $field) {
            if (in_array($field['type'], array('text', 'textarea')) && 
                (strpos(strtolower($field['label']), 'subject') !== false || 
                 strpos(strtolower($field['id']), 'subject') !== false)) {
                $field_name = 'sscf_' . $field['id'];
                if (isset($data[$field_name]) && !empty($data[$field_name])) {
                    $form_subject = esc_html($data[$field_name]);
                    break;
                }
            }
        }
        
        // Fallback: use generic subject if no subject field found
        if (empty($form_subject)) {
            $form_subject = 'New Contact Form Submission';
        }
        
        return sprintf(
            '[%s] Contact Form: %s',
            $site_name,
            $form_subject
        );
    }
    
    /**
     * Prepare email message (HTML format)
     *
     * @param array $data Form data
     * @return string HTML email message
     */
    private function prepare_email_message($data) {
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        $form_fields = get_option('sscf_form_fields', array());
        
        // Sort fields by order
        usort($form_fields, function($a, $b) {
            return $a['order'] - $b['order'];
        });
        
        // Get current date/time
        $date_time = current_time('F j, Y \a\t g:i A');
        
        // Build form fields HTML
        $fields_html = '';
        foreach ($form_fields as $field) {
            $field_name = 'sscf_' . $field['id'];
            $field_value = $data[$field_name] ?? '';
            
            if (!empty($field_value)) {
                $escaped_label = esc_html($field['label']);
                
                if ($field['type'] === 'textarea') {
                    $escaped_value = wpautop(esc_html($field_value)); // Convert line breaks to paragraphs
                    $field_class = 'message-content';
                } else {
                    $escaped_value = esc_html($field_value);
                    $field_class = 'value';
                }
                
                $fields_html .= "
                <div class='field'>
                    <div class='label'>{$escaped_label}:</div>
                    <div class='{$field_class}'>{$escaped_value}</div>
                </div>";
            }
        }
        
        // Build HTML email template
        $html_message = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Contact Form Submission</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
                .field { margin-bottom: 15px; }
                .label { font-weight: bold; color: #555; }
                .value { margin-top: 5px; }
                .message-content { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 5px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>New Contact Form Submission</h2>
                    <p>Received on: {$date_time}</p>
                </div>
                
                {$fields_html}
                
                <div class='footer'>
                    <p>This email was sent from the contact form on <a href='{$site_url}'>{$site_name}</a> using SpamShield Contact Form plugin.</p>
                    <p>To reply to this message, simply reply to this email.</p>
                </div>
            </div>
        </body>
        </html>";
        
        return $html_message;
    }
    
    /**
     * Prepare email headers
     *
     * @param array $data Form data
     * @return array Email headers
     */
    private function prepare_email_headers($data) {
        $from_name = get_bloginfo('name');
        $from_email = $this->get_from_email();
        
        // Find reply-to email and name dynamically
        $reply_to_email = '';
        $reply_to_name = '';
        $form_fields = get_option('sscf_form_fields', array());
        
        foreach ($form_fields as $field) {
            $field_name = 'sscf_' . $field['id'];
            
            // Look for email field
            if ($field['type'] === 'email' && empty($reply_to_email) && isset($data[$field_name])) {
                $maybe_email = sanitize_email($data[$field_name]);
                if (is_email($maybe_email)) {
                    $reply_to_email = $maybe_email;
                }
            }
            
            // Look for name field
            if (empty($reply_to_name) && isset($data[$field_name]) && 
                (strpos(strtolower($field['label']), 'name') !== false || 
                 strpos(strtolower($field['id']), 'name') !== false)) {
                $reply_to_name = sanitize_text_field($data[$field_name]);
            }
        }
        
        // Fallbacks
        if (empty($reply_to_email)) {
            $reply_to_email = $from_email;
        }
        if (empty($reply_to_name)) {
            $reply_to_name = 'Contact Form User';
        }
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
            "Reply-To: {$reply_to_name} <{$reply_to_email}>"
        );
        
        return $headers;
    }
    
    /**
     * Get appropriate 'from' email address
     *
     * @return string From email address
     */
    private function get_from_email() {
        // Try to use a no-reply email from the same domain
        $site_url = get_site_url();
        $parsed_url = parse_url($site_url);
        $domain = $parsed_url['host'] ?? '';
        
        if ($domain) {
            // Remove 'www.' if present
            $domain = preg_replace('/^www\./', '', $domain);
            $from_email = "noreply@{$domain}";
            
            // Validate the constructed email
            if (is_email($from_email)) {
                return $from_email;
            }
        }
        
        // Fallback to admin email
        return get_option('admin_email');
    }
    
    /**
     * Send test email (for admin testing)
     *
     * @param string $to_email Recipient email
     * @return array Result
     */
    public function send_test_email($to_email) {
        if (!is_email($to_email)) {
            return array(
                'success' => false,
                'message' => 'Invalid email address provided.'
            );
        }
        
        // Prepare test data
        $test_data = array(
            'sscf_name' => 'Test User',
            'sscf_email' => $to_email,
            'sscf_subject' => 'SpamShield Contact Form Test',
            'sscf_message' => 'This is a test message to verify that your SpamShield Contact Form is working correctly. If you receive this email, your contact form is configured properly!'
        );
        
        return $this->send_contact_email($test_data);
    }
    
    /**
     * Get email delivery statistics
     *
     * @return array Email statistics
     */
    public function get_email_stats() {
        // This is a simplified version. For detailed stats, you'd want to track
        // successful/failed emails in the database
        return array(
            'emails_sent_today' => $this->get_daily_email_count(),
            'last_email_sent' => get_option('sscf_last_email_sent', 'Never')
        );
    }
    
    /**
     * Get count of emails sent today
     *
     * @return int Email count for today
     */
    private function get_daily_email_count() {
        $today_key = 'sscf_emails_sent_' . date('Y-m-d');
        return intval(get_option($today_key, 0));
    }
    
    /**
     * Increment daily email counter
     */
    public function increment_email_count() {
        $today_key = 'sscf_emails_sent_' . date('Y-m-d');
        $current_count = intval(get_option($today_key, 0));
        update_option($today_key, $current_count + 1);
        update_option('sscf_last_email_sent', current_time('mysql'));
    }
}
