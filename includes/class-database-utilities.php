<?php
/**
 * Database Utilities Class
 * Handles database status checking, rebuilding, and maintenance
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Database_Utilities {
    
    private $tables = array();
    
    public function __construct() {
        global $wpdb;
        
        // Define all plugin tables
        $this->tables = array(
            'entries' => array(
                'name' => $wpdb->prefix . 'sscf_entries',
                'label' => 'Form Entries',
                'description' => 'Stores all form submissions and contact form entries'
            ),
            'analytics' => array(
                'name' => $wpdb->prefix . 'sscf_comment_analytics', 
                'label' => 'Analytics Data',
                'description' => 'Tracks spam detection statistics and analytics'
            ),
            'forms' => array(
                'name' => $wpdb->prefix . 'sscf_forms',
                'label' => 'Custom Forms',
                'description' => 'Stores drag-and-drop form builder configurations'
            ),
            'threat_patterns' => array(
                'name' => $wpdb->prefix . 'sscf_threat_patterns',
                'label' => 'Threat Patterns',
                'description' => 'AI-learned spam patterns and threat intelligence'
            )
        );
        
        // Initialize hooks
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_sscf_rebuild_database', array($this, 'handle_rebuild_database'));
        add_action('wp_ajax_sscf_check_database_status', array($this, 'handle_check_database_status'));
    }
    
    public function init() {
        // Check database integrity on admin pages
        if (is_admin()) {
            add_action('admin_notices', array($this, 'show_database_notices'));
        }
    }
    
    /**
     * Get database status for all tables
     */
    public function get_database_status() {
        global $wpdb;
        
        $status = array(
            'overall_status' => 'healthy',
            'tables' => array(),
            'issues' => array(),
            'stats' => array(
                'total_tables' => count($this->tables),
                'healthy_tables' => 0,
                'missing_tables' => 0,
                'corrupted_tables' => 0
            )
        );
        
        foreach ($this->tables as $key => $table_info) {
            $table_name = $table_info['name'];
            $table_status = $this->check_single_table($table_name, $key);
            
            $status['tables'][$key] = array_merge($table_info, $table_status);
            
            // Update overall stats
            switch ($table_status['status']) {
                case 'healthy':
                    $status['stats']['healthy_tables']++;
                    break;
                case 'missing':
                    $status['stats']['missing_tables']++;
                    $status['issues'][] = sprintf(__('Table %s is missing', 'spamshield-cf'), $table_info['label']);
                    break;
                case 'corrupted':
                    $status['stats']['corrupted_tables']++;
                    $status['issues'][] = sprintf(__('Table %s may be corrupted', 'spamshield-cf'), $table_info['label']);
                    break;
            }
        }
        
        // Determine overall status
        if ($status['stats']['missing_tables'] > 0 || $status['stats']['corrupted_tables'] > 0) {
            $status['overall_status'] = $status['stats']['missing_tables'] > 0 ? 'critical' : 'warning';
        }
        
        return $status;
    }
    
    /**
     * Check status of a single table
     */
    private function check_single_table($table_name, $table_key) {
        global $wpdb;
        
        $status = array(
            'status' => 'healthy',
            'exists' => false,
            'row_count' => 0,
            'size_mb' => 0,
            'created_at' => '',
            'last_updated' => ''
        );
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));
        
        if (!$table_exists) {
            $status['status'] = 'missing';
            return $status;
        }
        
        $status['exists'] = true;
        
        // Get table statistics
        try {
            // Row count
            $status['row_count'] = intval($wpdb->get_var("SELECT COUNT(*) FROM `$table_name`"));
            
            // Table size and info
            $table_info = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb',
                    create_time,
                    update_time
                FROM information_schema.TABLES 
                WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table_name
            ));
            
            if ($table_info) {
                $status['size_mb'] = floatval($table_info->size_mb);
                $status['created_at'] = $table_info->create_time ?? '';
                $status['last_updated'] = $table_info->update_time ?? '';
            }
            
            // Basic integrity check - try to describe table
            $columns = $wpdb->get_results("DESCRIBE `$table_name`");
            if (empty($columns)) {
                $status['status'] = 'corrupted';
            }
            
        } catch (Exception $e) {
            $status['status'] = 'corrupted';
            error_log('SSCF Database Check Error: ' . $e->getMessage());
        }
        
        return $status;
    }
    
    /**
     * Rebuild all database tables
     */
    public function rebuild_database() {
        $results = array(
            'success' => true,
            'tables_created' => array(),
            'errors' => array(),
            'warnings' => array()
        );
        
        try {
            // Get the main plugin instance to access create_tables method
            global $spamshield_contact_form;
            
            if (!$spamshield_contact_form) {
                // Fallback - create tables manually
                $this->create_tables_fallback();
            } else {
                // Use main plugin's create_tables method
                $spamshield_contact_form->create_tables();
            }
            
            // Verify tables were created
            foreach ($this->tables as $key => $table_info) {
                $table_status = $this->check_single_table($table_info['name'], $key);
                
                if ($table_status['exists']) {
                    $results['tables_created'][] = $table_info['label'];
                } else {
                    $results['errors'][] = sprintf(__('Failed to create table: %s', 'spamshield-cf'), $table_info['label']);
                    $results['success'] = false;
                }
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = __('Database rebuild failed: ', 'spamshield-cf') . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Fallback method to create tables
     */
    private function create_tables_fallback() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Form entries table
        $entries_table = $wpdb->prefix . 'sscf_entries';
        $sql1 = "CREATE TABLE $entries_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            form_fields_hash varchar(32) NOT NULL,
            entry_data longtext NOT NULL,
            user_ip varchar(45) DEFAULT '',
            user_agent text DEFAULT '',
            spam_score int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'submitted',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_hash_idx (form_fields_hash),
            KEY created_at_idx (created_at)
        ) $charset_collate;";
        
        // Analytics table
        $analytics_table = $wpdb->prefix . 'sscf_comment_analytics';
        $sql2 = "CREATE TABLE $analytics_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            site_id int(11) DEFAULT 1,
            entry_type varchar(50) NOT NULL DEFAULT 'comment',
            spam_score int(11) DEFAULT 0,
            detection_method varchar(255) DEFAULT '',
            user_ip varchar(45) DEFAULT '',
            user_agent text DEFAULT '',
            post_id bigint(20) DEFAULT 0,
            content_preview text DEFAULT '',
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            is_spam tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY entry_type_idx (entry_type),
            KEY timestamp_idx (timestamp),
            KEY is_spam_idx (is_spam),
            KEY user_ip_idx (user_ip)
        ) $charset_collate;";
        
        // Custom forms table
        $forms_table = $wpdb->prefix . 'sscf_forms';
        $sql3 = "CREATE TABLE $forms_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            form_name varchar(255) NOT NULL,
            form_description text DEFAULT '',
            form_fields longtext NOT NULL,
            form_settings longtext DEFAULT '',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_name_idx (form_name),
            KEY is_active_idx (is_active),
            KEY created_at_idx (created_at)
        ) $charset_collate;";
        
        // Threat patterns table
        $threat_patterns_table = $wpdb->prefix . 'sscf_threat_patterns';
        $sql4 = "CREATE TABLE $threat_patterns_table (
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
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
    }
    
    /**
     * Handle AJAX database rebuild request
     */
    public function handle_rebuild_database() {
        // Security checks
        if (!wp_verify_nonce($_POST['nonce'], 'sscf_database_rebuild')) {
            wp_send_json_error(__('Security check failed', 'spamshield-cf'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'spamshield-cf'));
        }
        
        $results = $this->rebuild_database();
        
        if ($results['success']) {
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Database rebuilt successfully! Created %d tables: %s', 'spamshield-cf'),
                    count($results['tables_created']),
                    implode(', ', $results['tables_created'])
                ),
                'details' => $results
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Database rebuild encountered errors', 'spamshield-cf'),
                'errors' => $results['errors'],
                'details' => $results
            ));
        }
    }
    
    /**
     * Handle AJAX database status check
     */
    public function handle_check_database_status() {
        // Security checks
        if (!wp_verify_nonce($_POST['nonce'], 'sscf_database_status')) {
            wp_send_json_error(__('Security check failed', 'spamshield-cf'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions', 'spamshield-cf'));
        }
        
        $status = $this->get_database_status();
        wp_send_json_success($status);
    }
    
    /**
     * Show admin notices for database issues
     */
    public function show_database_notices() {
        // Only check on SpamShield pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'spamshield') === false) {
            return;
        }
        
        $status = $this->get_database_status();
        
        if ($status['overall_status'] === 'critical') {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . __('SpamShield Database Issue:', 'spamshield-cf') . '</strong></p>';
            echo '<p>' . __('Critical database problems detected. Some features may not work properly.', 'spamshield-cf') . '</p>';
            echo '<ul>';
            foreach ($status['issues'] as $issue) {
                echo '<li>' . esc_html($issue) . '</li>';
            }
            echo '</ul>';
            echo '<p><a href="#" id="sscf-rebuild-database-notice" class="button button-primary">' . __('Rebuild Database', 'spamshield-cf') . '</a></p>';
            echo '</div>';
        } elseif ($status['overall_status'] === 'warning') {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>' . __('SpamShield Database Warning:', 'spamshield-cf') . '</strong></p>';
            echo '<p>' . __('Minor database issues detected.', 'spamshield-cf') . '</p>';
            echo '<ul>';
            foreach ($status['issues'] as $issue) {
                echo '<li>' . esc_html($issue) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
    }
    
    /**
     * Get database summary for dashboard
     */
    public function get_database_summary() {
        $status = $this->get_database_status();
        
        return array(
            'status' => $status['overall_status'],
            'tables_count' => $status['stats']['healthy_tables'] . '/' . $status['stats']['total_tables'],
            'issues_count' => count($status['issues']),
            'total_rows' => array_sum(array_column($status['tables'], 'row_count')),
            'total_size_mb' => array_sum(array_column($status['tables'], 'size_mb'))
        );
    }
    
    /**
     * Clean up old data
     */
    public function cleanup_old_data($days = 90) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Clean old analytics data
        $analytics_table = $wpdb->prefix . 'sscf_comment_analytics';
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM `$analytics_table` WHERE timestamp < %s AND is_spam = 0",
            $cutoff_date
        ));
        
        // Clean old threat patterns with low confidence
        $threat_table = $wpdb->prefix . 'sscf_threat_patterns';
        $deleted_patterns = $wpdb->query($wpdb->prepare(
            "DELETE FROM `$threat_table` WHERE created_at < %s AND confidence_score < 60 AND detection_count = 1",
            date('Y-m-d H:i:s', strtotime("-30 days"))
        ));
        
        return array(
            'analytics_cleaned' => $deleted,
            'patterns_cleaned' => $deleted_patterns
        );
    }
}
