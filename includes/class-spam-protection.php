<?php
/**
 * Spam Protection Class
 * Handles honeypot and time-based spam detection
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Spam_Protection {
    
    private $honeypot_field = 'website';
    private $rate_limit_window = 60; // seconds
    private $max_submissions = 3; // per window
    private $min_submit_time = 3; // seconds
    private $ai_engine;
    
    public function __construct() {
        // Initialize AI engine if available
        if (class_exists('SSCF_AI_Detection_Engine')) {
            $this->ai_engine = new SSCF_AI_Detection_Engine();
        }
    }

    /**
     * Check if submission is spam using multiple protection layers
     * 
     * SPAM PROTECTION EXPLAINED:
     * This plugin uses a multi-layered approach to block spam without affecting real users:
     * 
     * 1. HONEYPOT: Hidden field that humans can't see but bots fill out
     * 2. TIME VALIDATION: Bots submit forms instantly, humans take time to fill them
     * 3. RATE LIMITING: Prevents spam floods from same IP address
     * 4. CONTENT FILTERING: Blocks suspicious patterns (handled in form-handler.php)
     *
     * @param array $data Form submission data
     * @return array Result with 'is_spam' boolean and 'reason' string
     */
    public function is_spam($data) {
        $options = get_option('sscf_options', array());
        
        // PROTECTION LAYER 0: IP Blocklist Check
        // Check if the IP address is in the blocklist before any other processing
        $ip = $this->get_user_ip();
        if ($this->ai_engine && $this->ai_engine->is_ip_blocked($ip)) {
            $this->increment_spam_count();
            return array(
                'is_spam' => true,
                'reason' => 'blocked_ip',
                'spam_score' => 100,
                'message' => 'Your IP address has been blocked due to suspicious activity'
            );
        }
        
        // PROTECTION LAYER 1: Honeypot Field
        // Hidden field named "website" that bots fill out but humans can't see
        // If this field has any content, it's definitely a bot
        if (!empty($options['honeypot_enabled']) && $this->honeypot_triggered($data)) {
            $this->increment_spam_count();
            return array(
                'is_spam' => true,
                'reason' => 'honeypot'
            );
        }
        
        // PROTECTION LAYER 2: Time-Based Validation  
        // Humans need time to read and fill forms (default: 3+ seconds)
        // Bots typically submit forms instantly upon page load
        if (!empty($options['min_time_seconds']) && $this->submitted_too_fast($data, $options['min_time_seconds'])) {
            $this->increment_spam_count();
            return array(
                'is_spam' => true,
                'reason' => 'time'
            );
        }
        
        // PROTECTION LAYER 3: Rate Limiting
        // Prevents spam floods by limiting submissions per IP address
        // Maximum 5 submissions per minute per IP address
        if ($this->rate_limit_exceeded()) {
            $this->increment_spam_count();
            return array(
                'is_spam' => true,
                'reason' => 'rate_limit',
                'spam_score' => 100
            );
        }
        
        // PROTECTION LAYER 4: AI-Powered Analysis (if enabled)
        // Uses Google Gemini AI to analyze content patterns and detect sophisticated spam
        if ($this->ai_engine && $this->ai_engine->is_configured()) {
            $ai_content = array(
                'type' => $data['entry_type'] ?? 'form',
                'content' => $data['content'] ?? $data['message'] ?? '',
                'author' => $data['author'] ?? $data['name'] ?? '',
                'email' => $data['email'] ?? '',
                'url' => $data['url'] ?? $data['website'] ?? '',
                'ip' => $this->get_user_ip(),
                'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            
            $ai_result = $this->ai_engine->analyze_content($ai_content);
            
            if ($ai_result['is_spam']) {
                $this->increment_spam_count();
                return array(
                    'is_spam' => true,
                    'reason' => $ai_result['reason'],
                    'spam_score' => $ai_result['confidence'],
                    'detection_method' => $ai_result['detection_method'] ?? 'ai_analysis',
                    'ai_analysis' => $ai_result
                );
            }
            
            // Store AI confidence even for non-spam for learning
            $ai_confidence = $ai_result['confidence'] ?? 0;
        }
        
        return array(
            'is_spam' => false,
            'reason' => '',
            'spam_score' => $ai_confidence ?? 0,
            'ai_analysis' => $ai_result ?? null
        );
    }
    
    /**
     * Check if honeypot field was filled (indicates bot)
     *
     * @param array $data Form data
     * @return bool True if honeypot was triggered
     */
    private function honeypot_triggered($data) {
        // Check if the 'website' field (honeypot) has any content
        return !empty($data['website']);
    }
    
    /**
     * Check if form was submitted too quickly (indicates bot)
     *
     * @param array $data Form data
     * @param int $min_seconds Minimum time required
     * @return bool True if submitted too fast
     */
    private function submitted_too_fast($data, $min_seconds) {
        if (empty($data['sscf_timestamp'])) {
            return true; // No timestamp = suspicious
        }
        
        $form_load_time = intval($data['sscf_timestamp']);
        $current_time = time();
        $time_spent = $current_time - $form_load_time;
        
        // If less than minimum time elapsed, it's likely a bot
        return $time_spent < $min_seconds;
    }
    
    /**
     * Check if rate limit is exceeded for current IP
     *
     * @return bool True if rate limit exceeded
     */
    private function rate_limit_exceeded() {
        $ip = $this->get_user_ip();
        $rate_limit_key = 'sscf_rate_limit_' . md5($ip);
        $rate_limit_data = get_transient($rate_limit_key);
        
        if (false === $rate_limit_data) {
            // First submission from this IP in the last minute
            set_transient($rate_limit_key, 1, $this->rate_limit_window); // use class window
            return false;
        }
        
        // Check if exceeded 5 submissions per minute
        if ($rate_limit_data >= $this->max_submissions) {
            return true;
        }
        
        // Increment counter
        set_transient($rate_limit_key, $rate_limit_data + 1, $this->rate_limit_window);
        return false;
    }
    
    /**
     * Get user's IP address
     *
     * @return string IP address
     */
    private function get_user_ip() {
        // Check for various headers that might contain the real IP
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
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
                
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        // Fallback to REMOTE_ADDR
        return !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
    
    /**
     * Increment spam blocked counter
     */
    private function increment_spam_count() {
        $options = get_option('sscf_options', array());
        $current_count = !empty($options['spam_blocked_count']) ? intval($options['spam_blocked_count']) : 0;
        $options['spam_blocked_count'] = $current_count + 1;
        update_option('sscf_options', $options);

        // Increment monthly counter (simple aggregate by month key)
        $monthly_key = 'sscf_monthly_spam_' . date('Y-m');
        $monthly_count = intval(get_option($monthly_key, 0));
        update_option($monthly_key, $monthly_count + 1);
    }
    
    /**
     * Get spam statistics
     *
     * @return array Spam statistics
     */
    public function get_spam_stats() {
        $options = get_option('sscf_options', array());
        return array(
            'total_blocked' => !empty($options['spam_blocked_count']) ? intval($options['spam_blocked_count']) : 0,
            'blocked_this_month' => $this->get_monthly_spam_count()
        );
    }
    
    /**
     * Get spam count for current month
     * Note: This is a simplified version. For more accurate monthly tracking,
     * you'd want to store timestamps with each spam attempt.
     *
     * @return int Monthly spam count (simplified)
     */
    private function get_monthly_spam_count() {
        $monthly_key = 'sscf_monthly_spam_' . date('Y-m');
        return intval(get_option($monthly_key, 0));
    }
}
