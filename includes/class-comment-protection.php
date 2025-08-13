<?php
/**
 * Comment Protection Class
 * Extends spam protection to WordPress comments
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Comment_Protection {
    
    private $spam_protection;
    private $analytics_table;
    
    public function __construct() {
        $this->spam_protection = new SSCF_Spam_Protection();
        $this->analytics_table = 'sscf_comment_analytics';
        
        // Hook into WordPress comment system
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks for comment protection
     */
    private function init_hooks() {
        // Pre-process comments before insertion
        add_filter('preprocess_comment', array($this, 'check_comment_spam'), 10, 1);
        
        // Filter comment text for additional checks
        add_filter('comment_text', array($this, 'filter_comment_text'), 10, 2);
        
        // Add honeypot field to comment form
        add_action('comment_form_after_fields', array($this, 'add_comment_honeypot'));
        
        // Rate limiting for comments
        add_action('comment_post', array($this, 'track_comment_rate'), 10, 3);
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'), 20);
        add_action('wp_ajax_sscf_bulk_comment_action', array($this, 'handle_bulk_comment_action'));
    }
    
    /**
     * Check comment for spam before processing
     */
    public function check_comment_spam($commentdata) {
        $options = get_option('sscf_options', array());
        
        // Skip if comment protection is disabled
        if (empty($options['comment_protection_enabled'])) {
            return $commentdata;
        }
        
        // Skip for admin users
        if (current_user_can('moderate_comments')) {
            return $commentdata;
        }
        
        // Prepare data for spam check
        $form_data = array(
            'comment_content' => $commentdata['comment_content'],
            'comment_author' => $commentdata['comment_author'],
            'comment_author_email' => $commentdata['comment_author_email'],
            'comment_author_url' => $commentdata['comment_author_url'],
            'user_ip' => $commentdata['comment_author_IP'],
            'user_agent' => $commentdata['comment_agent'],
            'website' => isset($_POST['sscf_website']) ? sanitize_text_field($_POST['sscf_website']) : '', // Honeypot
            'form_timestamp' => isset($_POST['sscf_timestamp']) ? intval($_POST['sscf_timestamp']) : time()
        );
        
        // Run spam detection
        $spam_result = $this->detect_comment_spam($form_data);
        
        if ($spam_result['is_spam']) {
            // Log the spam attempt
            $this->log_spam_comment($commentdata, $spam_result);
            
            // Handle spam comment based on settings
            $action = isset($options['spam_comment_action']) ? $options['spam_comment_action'] : 'reject';
            
            switch ($action) {
                case 'trash':
                    $commentdata['comment_approved'] = 'trash';
                    break;
                case 'spam':
                    $commentdata['comment_approved'] = 'spam';
                    break;
                case 'reject':
                default:
                    wp_die(__('Your comment has been flagged as spam and cannot be posted.', 'spamshield-cf'));
                    break;
            }
        } else {
            // Log legitimate comment for analytics
            $this->log_legitimate_comment($commentdata);
        }
        
        return $commentdata;
    }
    
    /**
     * Detect spam in comment using multiple methods
     */
    private function detect_comment_spam($data) {
        $options = get_option('sscf_options', array());
        $spam_score = 0;
        $detection_methods = array();
        
        // 1. Honeypot check
        if (!empty($data['website'])) {
            $spam_score += 100; // Definitive spam
            $detection_methods[] = 'honeypot';
        }
        
        // 2. Rate limiting check
        if ($this->is_rate_limited($data['user_ip'])) {
            $spam_score += 80;
            $detection_methods[] = 'rate_limit';
        }
        
        // 3. Time validation
        $min_time = isset($options['comment_min_time']) ? intval($options['comment_min_time']) : 3;
        if ((time() - $data['form_timestamp']) < $min_time) {
            $spam_score += 60;
            $detection_methods[] = 'time_validation';
        }
        
        // 4. Content analysis
        $content_score = $this->analyze_comment_content($data['comment_content']);
        $spam_score += $content_score;
        if ($content_score > 20) {
            $detection_methods[] = 'content_analysis';
        }
        
        // 5. Author analysis
        if ($this->is_suspicious_author($data)) {
            $spam_score += 30;
            $detection_methods[] = 'author_analysis';
        }
        
        // 6. URL analysis
        if (!empty($data['comment_author_url']) && $this->is_suspicious_url($data['comment_author_url'])) {
            $spam_score += 40;
            $detection_methods[] = 'url_analysis';
        }
        
        $spam_threshold = isset($options['comment_spam_threshold']) ? intval($options['comment_spam_threshold']) : 50;
        $is_spam = $spam_score >= $spam_threshold;
        
        return array(
            'is_spam' => $is_spam,
            'spam_score' => $spam_score,
            'detection_methods' => $detection_methods,
            'reason' => $is_spam ? implode(', ', $detection_methods) : 'clean'
        );
    }
    
    /**
     * Analyze comment content for spam indicators
     */
    private function analyze_comment_content($content) {
        $score = 0;
        $content_lower = strtolower($content);
        
        // Common spam patterns
        $spam_patterns = array(
            'casino' => 20,
            'viagra' => 25,
            'cialis' => 25,
            'poker' => 15,
            'loan' => 10,
            'buy now' => 15,
            'click here' => 10,
            'make money' => 20,
            'work from home' => 15,
            'guaranteed' => 10
        );
        
        foreach ($spam_patterns as $pattern => $points) {
            if (strpos($content_lower, $pattern) !== false) {
                $score += $points;
            }
        }
        
        // Multiple links
        $link_count = preg_match_all('/<a\s+href/i', $content);
        if ($link_count > 2) {
            $score += $link_count * 10;
        }
        
        // All caps (shouting)
        if (strlen($content) > 20 && $content === strtoupper($content)) {
            $score += 15;
        }
        
        // Excessive punctuation
        if (preg_match('/[!]{3,}/', $content) || preg_match('/[?]{3,}/', $content)) {
            $score += 10;
        }
        
        return $score;
    }
    
    /**
     * Check if author details are suspicious
     */
    private function is_suspicious_author($data) {
        // Empty or single character name
        if (empty($data['comment_author']) || strlen(trim($data['comment_author'])) < 2) {
            return true;
        }
        
        // Name contains URLs
        if (preg_match('/https?:\/\//', $data['comment_author'])) {
            return true;
        }
        
        // Email contains suspicious patterns
        if (!empty($data['comment_author_email'])) {
            $suspicious_domains = array('.tk', '.ml', '.ga', '.cf', 'tempmail', '10minute');
            foreach ($suspicious_domains as $domain) {
                if (strpos($data['comment_author_email'], $domain) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if URL is suspicious
     */
    private function is_suspicious_url($url) {
        // Multiple subdomains
        if (substr_count($url, '.') > 3) {
            return true;
        }
        
        // Suspicious TLDs
        $suspicious_tlds = array('.tk', '.ml', '.ga', '.cf', '.download', '.click');
        foreach ($suspicious_tlds as $tld) {
            if (strpos($url, $tld) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if IP is rate limited
     */
    private function is_rate_limited($ip) {
        $options = get_option('sscf_options', array());
        $max_comments = isset($options['comment_rate_limit']) ? intval($options['comment_rate_limit']) : 5;
        $time_window = isset($options['comment_rate_window']) ? intval($options['comment_rate_window']) : 3600; // 1 hour
        
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} 
             WHERE comment_author_IP = %s 
             AND comment_date > DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $ip,
            $time_window
        ));
        
        return $count >= $max_comments;
    }
    
    /**
     * Add honeypot field to comment form
     */
    public function add_comment_honeypot() {
        $options = get_option('sscf_options', array());
        
        if (empty($options['comment_protection_enabled'])) {
            return;
        }
        
        echo '<p style="display: none !important;">';
        echo '<label for="sscf_website">Website (leave blank): </label>';
        echo '<input type="text" id="sscf_website" name="sscf_website" value="" tabindex="-1" autocomplete="off">';
        echo '</p>';
        echo '<input type="hidden" name="sscf_timestamp" value="' . time() . '">';
    }
    
    /**
     * Log spam comment attempt
     */
    private function log_spam_comment($commentdata, $spam_result) {
        global $wpdb;
        
        $table = $wpdb->prefix . $this->analytics_table;
        
        $wpdb->insert($table, array(
            'site_id' => get_current_blog_id(),
            'entry_type' => 'comment',
            'spam_score' => $spam_result['spam_score'],
            'detection_method' => $spam_result['reason'],
            'user_ip' => $commentdata['comment_author_IP'],
            'user_agent' => $commentdata['comment_agent'],
            'post_id' => $commentdata['comment_post_ID'],
            'content_preview' => substr($commentdata['comment_content'], 0, 100),
            'timestamp' => current_time('mysql'),
            'is_spam' => 1
        ));
    }
    
    /**
     * Log legitimate comment for analytics
     */
    private function log_legitimate_comment($commentdata) {
        global $wpdb;
        
        $table = $wpdb->prefix . $this->analytics_table;
        
        $wpdb->insert($table, array(
            'site_id' => get_current_blog_id(),
            'entry_type' => 'comment',
            'spam_score' => 0,
            'detection_method' => 'clean',
            'user_ip' => $commentdata['comment_author_IP'],
            'user_agent' => $commentdata['comment_agent'],
            'post_id' => $commentdata['comment_post_ID'],
            'content_preview' => substr($commentdata['comment_content'], 0, 100),
            'timestamp' => current_time('mysql'),
            'is_spam' => 0
        ));
    }
    
    /**
     * Track comment rate for rate limiting
     */
    public function track_comment_rate($comment_id, $approved, $commentdata) {
        // This is handled by the log methods above
        // Additional rate tracking could be added here if needed
    }
    
    /**
     * Filter comment text (additional processing)
     */
    public function filter_comment_text($text, $comment) {
        // Additional filtering can be added here
        return $text;
    }
    
    /**
     * Add admin menu for comment protection
     */
    public function add_admin_menu() {
        add_submenu_page(
            'options-general.php',
            __('Comment Protection', 'spamshield-cf'),
            __('Comment Protection', 'spamshield-cf'),
            'manage_options',
            'sscf-comment-protection',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Admin page for comment protection
     */
    public function admin_page() {
        require_once SSCF_PLUGIN_PATH . 'admin/comment-protection-page.php';
    }
    
    /**
     * Display comment protection statistics
     */
    private function display_stats() {
        global $wpdb;
        
        $table = $wpdb->prefix . $this->analytics_table;
        
        // Get stats for last 30 days
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_comments,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_blocked,
                SUM(CASE WHEN is_spam = 0 THEN 1 ELSE 0 END) as legitimate_comments
             FROM {$table} 
             WHERE entry_type = 'comment' 
             AND timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ));
        
        if ($stats && $stats->total_comments > 0) {
            $spam_percentage = round(($stats->spam_blocked / $stats->total_comments) * 100, 1);
            
            echo '<div class="notice notice-info">';
            echo '<h3>' . __('Comment Protection Stats (Last 30 Days)', 'spamshield-cf') . '</h3>';
            echo '<p>';
            echo '<strong>' . number_format($stats->spam_blocked) . '</strong> spam comments blocked | ';
            echo '<strong>' . number_format($stats->legitimate_comments) . '</strong> legitimate comments allowed | ';
            echo '<strong>' . $spam_percentage . '%</strong> spam rate';
            echo '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Save comment protection settings
     */
    private function save_settings() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'sscf_comment_settings')) {
            return;
        }
        
        $options = get_option('sscf_options', array());
        
        $options['comment_protection_enabled'] = isset($_POST['comment_protection_enabled']) ? 1 : 0;
        $options['comment_spam_threshold'] = isset($_POST['comment_spam_threshold']) ? intval($_POST['comment_spam_threshold']) : 50;
        $options['comment_rate_limit'] = isset($_POST['comment_rate_limit']) ? intval($_POST['comment_rate_limit']) : 5;
        $options['comment_rate_window'] = isset($_POST['comment_rate_window']) ? intval($_POST['comment_rate_window']) : 3600;
        $options['comment_min_time'] = isset($_POST['comment_min_time']) ? intval($_POST['comment_min_time']) : 3;
        $options['spam_comment_action'] = isset($_POST['spam_comment_action']) ? sanitize_text_field($_POST['spam_comment_action']) : 'reject';
        
        update_option('sscf_options', $options);
        
        echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'spamshield-cf') . '</p></div>';
    }
    
    /**
     * Handle bulk comment actions
     */
    public function handle_bulk_comment_action() {
        // Implementation for bulk comment management
        // This can be expanded based on requirements
        wp_die();
    }
}
