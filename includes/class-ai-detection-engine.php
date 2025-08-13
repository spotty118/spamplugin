<?php
/**
 * AI-Powered Spam Detection Engine
 * Integrates with Google AI Studio (Gemini API) for advanced spam detection
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_AI_Detection_Engine {
    
    private $api_key;
    private $api_endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent';
    private $cache_duration = 3600; // 1 hour cache for similar content
    private $rate_limit_window = 60; // 1 minute
    private $max_requests_per_window = 100;
    private $analytics_table;
    private $threat_patterns_table;
    
    public function __construct() {
        global $wpdb;
        
        $this->analytics_table = $wpdb->prefix . 'sscf_comment_analytics';
        $this->threat_patterns_table = $wpdb->prefix . 'sscf_threat_patterns';
        $this->api_key = get_option('sscf_google_ai_api_key', '');
        
        // Initialize hooks
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_sscf_test_ai_connection', array($this, 'test_ai_connection'));
        add_action('wp_ajax_sscf_export_threats', array($this, 'ajax_export_threats'));
        add_action('wp_ajax_sscf_block_ip', array($this, 'ajax_block_ip'));
        
        // Create threat patterns table on activation
        add_action('plugins_loaded', array($this, 'create_threat_patterns_table'));
    }
    
    /**
     * Initialize AI detection system
     */
    public function init() {
        // Only initialize if API key is configured
        if ($this->is_configured()) {
            $this->setup_threat_intelligence();
        }
    }
    
    /**
     * Register admin settings
     */
    public function register_settings() {
        register_setting('sscf_ai_settings', 'sscf_google_ai_api_key');
        register_setting('sscf_ai_settings', 'sscf_ai_detection_threshold');
        register_setting('sscf_ai_settings', 'sscf_ai_auto_learning');
        register_setting('sscf_ai_settings', 'sscf_ai_detection_enabled');
    }
    
    /**
     * Check if AI detection is configured
     */
    public function is_configured() {
        return !empty($this->api_key) && get_option('sscf_ai_detection_enabled', false);
    }
    
    /**
     * Create threat patterns table
     */
    public function create_threat_patterns_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->threat_patterns_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            pattern_hash varchar(32) NOT NULL,
            pattern_type varchar(50) NOT NULL,
            confidence_score float NOT NULL,
            detection_count int(11) DEFAULT 1,
            last_detected datetime DEFAULT CURRENT_TIMESTAMP,
            pattern_data text NOT NULL,
            ai_analysis text,
            is_verified boolean DEFAULT false,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY pattern_hash (pattern_hash),
            KEY pattern_type (pattern_type),
            KEY confidence_score (confidence_score),
            KEY last_detected (last_detected)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Analyze content using AI
     */
    public function analyze_content($content_data) {
        if (!$this->is_configured()) {
            return array(
                'is_spam' => false,
                'confidence' => 0,
                'reason' => 'AI detection not configured',
                'ai_analysis' => null
            );
        }
        
        // Check rate limiting
        if (!$this->check_rate_limit()) {
            return array(
                'is_spam' => false,
                'confidence' => 0,
                'reason' => 'Rate limit exceeded',
                'ai_analysis' => null
            );
        }
        
        // Check cache first
        $cache_key = 'sscf_ai_' . md5(serialize($content_data));
        $cached_result = get_transient($cache_key);
        
        if ($cached_result !== false) {
            return $cached_result;
        }
        
        try {
            // Prepare content for AI analysis
            $analysis_prompt = $this->build_analysis_prompt($content_data);
            
            // Call Gemini API
            $ai_response = $this->call_gemini_api($analysis_prompt);
            
            if ($ai_response && isset($ai_response['analysis'])) {
                $result = $this->process_ai_response($ai_response, $content_data);
                
                // Cache successful result
                set_transient($cache_key, $result, $this->cache_duration);
                
                // Learn from the analysis
                if (get_option('sscf_ai_auto_learning', true)) {
                    $this->learn_from_analysis($content_data, $result);
                }
                
                return $result;
            }
            
        } catch (Exception $e) {
            error_log('SSCF AI Detection Error: ' . $e->getMessage());
            
            // Fallback to pattern matching
            return $this->fallback_pattern_detection($content_data);
        }
        
        // Default safe response
        return array(
            'is_spam' => false,
            'confidence' => 0,
            'reason' => 'AI analysis failed',
            'ai_analysis' => null
        );
    }
    
    /**
     * Build analysis prompt for Gemini
     */
    private function build_analysis_prompt($content_data) {
        $threat_patterns = $this->get_known_threat_patterns();
        $recent_spam_indicators = $this->get_recent_spam_indicators();
        
        $prompt = "You are an advanced spam detection system. Analyze the following content and determine if it's spam.\n\n";
        
        $prompt .= "CONTENT TO ANALYZE:\n";
        $prompt .= "Type: " . ($content_data['type'] ?? 'unknown') . "\n";
        $prompt .= "Content: " . wp_strip_all_tags($content_data['content'] ?? '') . "\n";
        $prompt .= "Author: " . ($content_data['author'] ?? 'unknown') . "\n";
        $prompt .= "Email: " . ($content_data['email'] ?? 'unknown') . "\n";
        $prompt .= "URL: " . ($content_data['url'] ?? 'none') . "\n";
        $prompt .= "IP: " . ($content_data['ip'] ?? 'unknown') . "\n";
        $prompt .= "User Agent: " . ($content_data['user_agent'] ?? 'unknown') . "\n";
        
        if (!empty($threat_patterns)) {
            $prompt .= "\nKNOWN SPAM PATTERNS (learn from these):\n";
            foreach (array_slice($threat_patterns, 0, 5) as $pattern) {
                $prompt .= "- " . $pattern['description'] . " (confidence: " . $pattern['confidence_score'] . ")\n";
            }
        }
        
        if (!empty($recent_spam_indicators)) {
            $prompt .= "\nRECENT SPAM INDICATORS:\n";
            foreach (array_slice($recent_spam_indicators, 0, 3) as $indicator) {
                $prompt .= "- " . $indicator . "\n";
            }
        }
        
        $prompt .= "\nANALYSIS REQUIREMENTS:\n";
        $prompt .= "1. Examine content for spam indicators: promotional language, suspicious links, gibberish, repetitive patterns\n";
        $prompt .= "2. Check author details for legitimacy\n";
        $prompt .= "3. Analyze IP and user agent for bot patterns\n";
        $prompt .= "4. Consider context and intent\n";
        $prompt .= "5. Look for social engineering attempts\n";
        
        $prompt .= "\nRespond with JSON in this exact format:\n";
        $prompt .= "{\n";
        $prompt .= '  "is_spam": boolean,'. "\n";
        $prompt .= '  "confidence": number (0-100),'. "\n";
        $prompt .= '  "spam_indicators": ["indicator1", "indicator2"],'. "\n";
        $prompt .= '  "threat_type": "string (promotional/malicious/bot/legitimate)",'. "\n";
        $prompt .= '  "reasoning": "detailed explanation",'. "\n";
        $prompt .= '  "recommended_action": "block/flag/allow"'. "\n";
        $prompt .= "}\n";
        
        return $prompt;
    }
    
    /**
     * Call Gemini API
     */
    private function call_gemini_api($prompt) {
        $request_body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => 0.1,
                'maxOutputTokens' => 1000,
                'topP' => 0.8,
                'topK' => 10
            ),
            'safetySettings' => array(
                array(
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ),
                array(
                    'category' => 'HARM_CATEGORY_HATE_SPEECH', 
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                )
            )
        );
        
        $response = wp_remote_post($this->api_endpoint . '?key=' . $this->api_key, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($request_body)
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            throw new Exception('API returned error code: ' . $response_code . ' - ' . $response_body);
        }
        
        $data = json_decode($response_body, true);
        
        if (!$data || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('Invalid API response format');
        }
        
        $ai_text = $data['candidates'][0]['content']['parts'][0]['text'];
        
        // Clean up the AI response text
        $ai_text = trim($ai_text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/si', $ai_text, $m)) {
            $ai_text = trim($m[1]);
        }
        $ai_text = preg_replace('/^```|```$/', '', $ai_text);

        
        // Try multiple JSON extraction methods
        $json_string = null;
        
        // Method 1: Look for complete JSON blocks
        if (preg_match('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $ai_text, $matches)) {
            $json_string = $matches[0];
        }
        
        // Method 2: Extract between first { and last }
        if (!$json_string && preg_match('/\{.*\}/s', $ai_text, $matches)) {
            $json_string = $matches[0];
        }
        
        // Method 3: If the entire response looks like JSON, use it
        if (!$json_string && (strpos($ai_text, '{') === 0 && strrpos($ai_text, '}') === strlen($ai_text) - 1)) {
            $json_string = $ai_text;
        }
        
        if (!$json_string) {
            // Fallback: Create a safe response structure
            error_log('SpamShield AI: No JSON found in response: ' . $ai_text);
            $analysis = array(
                'is_spam' => false,
                'confidence' => 0,
                'spam_indicators' => array(),
                'threat_type' => 'legitimate',
                'reasoning' => 'AI response parsing failed, defaulting to safe',
                'recommended_action' => 'allow'
            );
        } else {
            // Clean up common JSON formatting issues
            $json_string = str_replace(array("\n", "\r", "\t"), '', $json_string);
            $json_string = preg_replace('/,(\s*[}\]])/', '$1', $json_string); // Remove trailing commas
            
            $analysis = json_decode($json_string, true);
            
            if (!$analysis) {
                // Log the problematic JSON for debugging
                error_log('SpamShield AI: Invalid JSON: ' . $json_string);
                
                // Create safe fallback response
                $analysis = array(
                    'is_spam' => false,
                    'confidence' => 0,
                    'spam_indicators' => array(),
                    'threat_type' => 'legitimate', 
                    'reasoning' => 'JSON parsing failed, defaulting to safe',
                    'recommended_action' => 'allow'
                );
            }
        }
        
        // Validate required fields and provide defaults
        $analysis = array_merge(array(
            'is_spam' => false,
            'confidence' => 0,
            'spam_indicators' => array(),
            'threat_type' => 'legitimate',
            'reasoning' => 'Default safe response',
            'recommended_action' => 'allow'
        ), $analysis);
        
        return array(
            'analysis' => $analysis,
            'raw_response' => $ai_text
        );
    }
    
    /**
     * Process AI response into standard format
     */
    private function process_ai_response($ai_response, $content_data) {
        $analysis = $ai_response['analysis'];
        $threshold = intval(get_option('sscf_ai_detection_threshold', 75));
        
        $is_spam = ($analysis['is_spam'] ?? false) && ($analysis['confidence'] ?? 0) >= $threshold;
        
        return array(
            'is_spam' => $is_spam,
            'confidence' => intval($analysis['confidence'] ?? 0),
            'reason' => 'AI analysis: ' . ($analysis['reasoning'] ?? 'No reasoning provided'),
            'threat_type' => $analysis['threat_type'] ?? 'unknown',
            'spam_indicators' => $analysis['spam_indicators'] ?? array(),
            'recommended_action' => $analysis['recommended_action'] ?? 'allow',
            'ai_analysis' => $analysis,
            'detection_method' => 'gemini_ai'
        );
    }
    
    /**
     * Learn from AI analysis
     */
    private function learn_from_analysis($content_data, $result) {
        if ($result['is_spam'] && $result['confidence'] > 80) {
            $this->store_threat_pattern($content_data, $result);
        }
    }
    
    /**
     * Store threat pattern for future reference
     */
    private function store_threat_pattern($content_data, $result) {
        global $wpdb;
        
        $pattern_data = array(
            'content_length' => strlen($content_data['content'] ?? ''),
            'has_links' => preg_match('/https?:\/\//', $content_data['content'] ?? '') ? 1 : 0,
            'spam_indicators' => $result['spam_indicators'] ?? array(),
            'threat_type' => $result['threat_type'] ?? 'unknown',
            'ip' => $content_data['ip'] ?? '',
            'user_agent_pattern' => $this->extract_user_agent_pattern($content_data['user_agent'] ?? '')
        );
        
        $pattern_hash = md5(serialize($pattern_data));
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, detection_count FROM {$this->threat_patterns_table} WHERE pattern_hash = %s",
            $pattern_hash
        ));
        
        if ($existing) {
            // Update existing pattern
            $wpdb->update(
                $this->threat_patterns_table,
                array(
                    'detection_count' => $existing->detection_count + 1,
                    'last_detected' => current_time('mysql'),
                    'confidence_score' => min(100, $result['confidence'] + 5)
                ),
                array('id' => $existing->id)
            );
        } else {
            // Create new pattern
            $wpdb->insert(
                $this->threat_patterns_table,
                array(
                    'pattern_hash' => $pattern_hash,
                    'pattern_type' => $result['threat_type'],
                    'confidence_score' => $result['confidence'],
                    'pattern_data' => wp_json_encode($pattern_data),
                    'ai_analysis' => wp_json_encode($result['ai_analysis']),
                    'created_at' => current_time('mysql')
                )
            );
        }
    }
    
    /**
     * Get known threat patterns
     */
    private function get_known_threat_patterns() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT pattern_type as description, confidence_score 
             FROM {$this->threat_patterns_table} 
             WHERE confidence_score > 70 
             ORDER BY confidence_score DESC, detection_count DESC 
             LIMIT 10",
            ARRAY_A
        );
    }
    
    /**
     * Get recent spam indicators
     */
    private function get_recent_spam_indicators() {
        global $wpdb;
        
        $recent_spam = $wpdb->get_results(
            "SELECT content_preview 
             FROM {$this->analytics_table} 
             WHERE is_spam = 1 AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
             ORDER BY timestamp DESC 
             LIMIT 5",
            ARRAY_A
        );
        
        return array_column($recent_spam, 'content_preview');
    }
    
    /**
     * Fallback pattern detection
     */
    private function fallback_pattern_detection($content_data) {
        global $wpdb;
        
        $content = $content_data['content'] ?? '';
        $ip = $content_data['ip'] ?? '';
        
        // Check against known threat patterns
        $threat_patterns = $wpdb->get_results(
            "SELECT * FROM {$this->threat_patterns_table} WHERE confidence_score > 80",
            ARRAY_A
        );
        
        foreach ($threat_patterns as $pattern) {
            $pattern_data = json_decode($pattern['pattern_data'], true);
            
            // Simple pattern matching
            if ($pattern_data['ip'] === $ip || 
                (strlen($content) > 0 && strlen($content) === $pattern_data['content_length'] && 
                 $pattern_data['has_links'] === (preg_match('/https?:\/\//', $content) ? 1 : 0))) {
                
                return array(
                    'is_spam' => true,
                    'confidence' => intval($pattern['confidence_score']),
                    'reason' => 'Pattern match: ' . $pattern['pattern_type'],
                    'detection_method' => 'pattern_fallback'
                );
            }
        }
        
        return array(
            'is_spam' => false,
            'confidence' => 0,
            'reason' => 'No patterns matched',
            'detection_method' => 'pattern_fallback'
        );
    }
    
    /**
     * Check API rate limiting
     */
    private function check_rate_limit() {
        $rate_limit_key = 'sscf_ai_rate_limit_' . floor(time() / $this->rate_limit_window);
        $current_requests = get_transient($rate_limit_key) ?: 0;
        
        if ($current_requests >= $this->max_requests_per_window) {
            return false;
        }
        
        set_transient($rate_limit_key, $current_requests + 1, $this->rate_limit_window);
        return true;
    }
    
    /**
     * Extract user agent pattern
     */
    private function extract_user_agent_pattern($user_agent) {
        // Extract browser and version pattern
        if (preg_match('/Chrome\/[\d\.]+/', $user_agent, $matches)) {
            return $matches[0];
        } elseif (preg_match('/Firefox\/[\d\.]+/', $user_agent, $matches)) {
            return $matches[0];
        } elseif (preg_match('/Safari\/[\d\.]+/', $user_agent, $matches)) {
            return $matches[0];
        }
        
        return 'unknown';
    }
    
    /**
     * Test AI connection
     */
    public function test_ai_connection() {
        if (!wp_verify_nonce($_POST['nonce'], 'sscf_ai_test_nonce')) {
            wp_send_json_error(__('Security check failed', 'spamshield-cf'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'spamshield-cf'));
        }
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        
        if (empty($api_key)) {
            wp_send_json_error(__('API key is required', 'spamshield-cf'));
        }
        
        $original_key = $this->api_key;
        $this->api_key = $api_key;
        
        try {
            $prompt = 'Return strict JSON only with keys: {"is_spam":boolean,"confidence":number,"spam_indicators":array,"threat_type":string,"reasoning":string,"recommended_action":string}. Example: {"is_spam":false,"confidence":0,"spam_indicators":[],"threat_type":"legitimate","reasoning":"connectivity test","recommended_action":"allow"}';
            $ai = $this->call_gemini_api($prompt);
            
            if (is_array($ai) && isset($ai['analysis']) && is_array($ai['analysis'])) {
                $confidence = intval($ai['analysis']['confidence'] ?? 0);
                wp_send_json_success(array(
                    'message' => __('AI connection successful!', 'spamshield-cf'),
                    'confidence' => $confidence,
                    'recommended_action' => $ai['analysis']['recommended_action'] ?? 'allow'
                ));
            }
            
            wp_send_json_error(__('API responded but format was unexpected', 'spamshield-cf'));
            
        } catch (Exception $e) {
            wp_send_json_error(sprintf(__('Connection failed: %s', 'spamshield-cf'), $e->getMessage()));
        } finally {
            $this->api_key = $original_key;
        }
    }
    
    /**
     * Get threat intelligence stats
     */
    public function get_threat_intelligence_stats() {
        global $wpdb;
        
        $stats = array(
            'total_threats' => 0,
            'recent_threats' => 0,
            'top_threats' => array()
        );
        
        // Total threats detected
        $stats['total_threats'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->threat_patterns_table}"
        );
        
        // Recent threats (last 24 hours)
        $stats['recent_threats'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->threat_patterns_table} 
             WHERE last_detected > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        // Top threat types
        $stats['top_threats'] = $wpdb->get_results(
            "SELECT pattern_type, COUNT(*) as count, AVG(confidence_score) as avg_confidence 
             FROM {$this->threat_patterns_table} 
             GROUP BY pattern_type 
             ORDER BY count DESC 
             LIMIT 5",
            ARRAY_A
        );
        
        return $stats;
    }
    
    /**
     * Get threat intelligence summary for dashboard
     */
    public function get_threat_intelligence_summary() {
        global $wpdb;
        
        $summary = array(
            'total_threats' => 0,
            'recent_threats' => 0,
            'blocked_today' => 0,
            'active_patterns' => 0,
            'top_threats' => array(),
            'threat_timeline' => array(),
            'ip_blacklist' => array(),
            'detection_accuracy' => 0
        );
        
        // Total threats detected
        $summary['total_threats'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->threat_patterns_table}"
        ) ?: 0;
        
        // Recent threats (last 24 hours)
        $summary['recent_threats'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->threat_patterns_table} 
             WHERE last_detected > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        ) ?: 0;
        
        // Blocked today
        $summary['blocked_today'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->analytics_table} 
             WHERE is_spam = 1 AND DATE(timestamp) = CURDATE()"
        ) ?: 0;
        
        // Active patterns (high confidence)
        $summary['active_patterns'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->threat_patterns_table} 
             WHERE confidence_score >= 75"
        ) ?: 0;
        
        // Top threat types with enhanced data
        $summary['top_threats'] = $wpdb->get_results(
            "SELECT 
                pattern_type, 
                COUNT(*) as count, 
                AVG(confidence_score) as avg_confidence,
                MAX(last_detected) as last_seen
             FROM {$this->threat_patterns_table} 
             GROUP BY pattern_type 
             ORDER BY count DESC 
             LIMIT 5",
            ARRAY_A
        );
        
        // Threat timeline (last 7 days)
        $summary['threat_timeline'] = $wpdb->get_results(
            "SELECT 
                DATE(last_detected) as date,
                COUNT(*) as threat_count
             FROM {$this->threat_patterns_table}
             WHERE last_detected > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(last_detected)
             ORDER BY date DESC",
            ARRAY_A
        );
        
        // Top malicious IPs
        $summary['ip_blacklist'] = $wpdb->get_results(
            "SELECT 
                JSON_UNQUOTE(JSON_EXTRACT(pattern_data, '$.ip')) as ip,
                COUNT(*) as threat_count,
                MAX(confidence_score) as max_confidence
             FROM {$this->threat_patterns_table}
             WHERE JSON_EXTRACT(pattern_data, '$.ip') IS NOT NULL
             GROUP BY JSON_EXTRACT(pattern_data, '$.ip')
             HAVING threat_count > 2
             ORDER BY threat_count DESC
             LIMIT 10",
            ARRAY_A
        );
        
        if (!empty($wpdb->last_error)) {
            $rows = $wpdb->get_results(
                "SELECT pattern_data, confidence_score FROM {$this->threat_patterns_table}",
                ARRAY_A
            );
            $agg = array();
            foreach ($rows as $row) {
                $pd = json_decode(isset($row['pattern_data']) ? $row['pattern_data'] : '', true);
                if (!is_array($pd) || empty($pd['ip'])) {
                    continue;
                }
                $ip = $pd['ip'];
                if (!isset($agg[$ip])) {
                    $agg[$ip] = array('ip' => $ip, 'threat_count' => 0, 'max_confidence' => 0.0);
                }
                $agg[$ip]['threat_count'] += 1;
                $agg[$ip]['max_confidence'] = max($agg[$ip]['max_confidence'], floatval($row['confidence_score']));
            }
            $agg = array_values(array_filter($agg, function($x){ return $x['threat_count'] > 2; }));
            usort($agg, function($a, $b){ return $b['threat_count'] <=> $a['threat_count']; });
            $summary['ip_blacklist'] = array_slice($agg, 0, 10);
        }
        
        // Calculate detection accuracy (based on verified patterns)
        $verified_patterns = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->threat_patterns_table} WHERE is_verified = 1"
        ) ?: 0;
        
        if ($summary['total_threats'] > 0) {
            $summary['detection_accuracy'] = round(($verified_patterns / $summary['total_threats']) * 100, 1);
        }
        
        return $summary;
    }
    
    /**
     * Setup threat intelligence background processing
     */
    private function setup_threat_intelligence() {
        // Schedule daily threat pattern cleanup
        if (!wp_next_scheduled('sscf_cleanup_threat_patterns')) {
            wp_schedule_event(time(), 'daily', 'sscf_cleanup_threat_patterns');
        }
        
        add_action('sscf_cleanup_threat_patterns', array($this, 'cleanup_old_threat_patterns'));
    }
    
    /**
     * Cleanup old threat patterns
     */
    public function cleanup_old_threat_patterns() {
        global $wpdb;
        
        // Remove patterns older than 90 days with low confidence
        $table_name = esc_sql($this->threat_patterns_table);
        $wpdb->query(
            "DELETE FROM `{$table_name}` 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY) 
             AND confidence_score < 60"
        );
        
        // Remove patterns with very low detection count after 30 days
        $wpdb->query(
            "DELETE FROM `{$table_name}` 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) 
             AND detection_count = 1 
             AND confidence_score < 75"
        );
    }
    
    /**
     * AJAX handler for exporting threats to CSV
     */
    public function ajax_export_threats() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'sscf_threat_export_nonce')) {
            wp_send_json_error(__('Security check failed', 'spamshield-cf'));
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'spamshield-cf'));
        }
        
        global $wpdb;
        
        // Get all threat patterns
        $threats = $wpdb->get_results(
            "SELECT 
                pattern_type,
                confidence_score,
                detection_count,
                last_detected,
                pattern_data,
                is_verified,
                created_at
             FROM {$this->threat_patterns_table}
             ORDER BY confidence_score DESC, detection_count DESC",
            ARRAY_A
        );
        
        // Build CSV content safely using fputcsv
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            wp_send_json_error(__('Failed to build CSV', 'spamshield-cf'));
        }
        
        $header = array('Pattern Type','Confidence Score','Detection Count','Last Detected','Verified','Created At','IP Address','User Agent Pattern');
        fputcsv($fh, $header);
        
        foreach ($threats as $threat) {
            $pattern_data = json_decode($threat['pattern_data'], true);
            $ip = isset($pattern_data['ip']) ? $pattern_data['ip'] : '';
            $user_agent = isset($pattern_data['user_agent_pattern']) ? $pattern_data['user_agent_pattern'] : '';
            $row = array(
                $threat['pattern_type'],
                number_format((float)$threat['confidence_score'], 1, '.', ''),
                (int)$threat['detection_count'],
                $threat['last_detected'],
                $threat['is_verified'] ? 'Yes' : 'No',
                $threat['created_at'],
                $ip,
                $user_agent
            );
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        
        wp_send_json_success(array(
            'csv' => $csv,
            'count' => count($threats)
        ));
    }
    
    /**
     * AJAX handler for blocking an IP address
     */
    public function ajax_block_ip() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'sscf_block_ip_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $ip = sanitize_text_field($_POST['ip']);
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address');
        }
        
        // Get existing blocked IPs
        $blocked_ips = get_option('sscf_blocked_ips', array());
        
        if (!is_array($blocked_ips)) {
            $blocked_ips = array();
        }
        
        // Add IP if not already blocked
        if (!in_array($ip, $blocked_ips)) {
            $blocked_ips[] = $ip;
            update_option('sscf_blocked_ips', $blocked_ips);
            
            // Also mark all patterns from this IP as verified threats
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->threat_patterns_table} 
                 SET is_verified = 1, confidence_score = 100 
                 WHERE JSON_EXTRACT(pattern_data, '$.ip') = %s",
                $ip
            ));
            
            wp_send_json_success(array(
                'message' => sprintf(__('IP %s has been blocked', 'spamshield-cf'), $ip),
                'blocked_count' => count($blocked_ips)
            ));
        } else {
            wp_send_json_error('IP is already blocked');
        }
    }
    
    /**
     * Check if an IP is blocked
     */
    public function is_ip_blocked($ip) {
        $blocked_ips = get_option('sscf_blocked_ips', array());
        return is_array($blocked_ips) && in_array($ip, $blocked_ips);
    }
}
