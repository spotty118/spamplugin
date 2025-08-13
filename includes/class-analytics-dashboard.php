<?php
/**
 * Analytics Dashboard Class
 * Enterprise-level analytics and reporting
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Analytics_Dashboard {
    
    private $analytics_table;
    private $entries_table;
    
    public function __construct() {
        global $wpdb;
        $this->analytics_table = $wpdb->prefix . 'sscf_comment_analytics';
        $this->entries_table = $wpdb->prefix . 'sscf_entries';
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'), 5);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX hooks for real-time data
        add_action('wp_ajax_sscf_get_dashboard_stats', array($this, 'get_dashboard_stats_ajax'));
        add_action('wp_ajax_sscf_get_stats', array($this, 'get_dashboard_stats_ajax')); // Alias for compatibility
        add_action('wp_ajax_sscf_get_chart_data', array($this, 'get_chart_data_ajax'));
        add_action('wp_ajax_sscf_get_analytics_data', array($this, 'get_chart_data_ajax')); // Alias for compatibility
        
        // Schedule daily reports
        if (!wp_next_scheduled('sscf_daily_report')) {
            wp_schedule_event(time(), 'daily', 'sscf_daily_report');
        }
        add_action('sscf_daily_report', array($this, 'send_daily_report'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('SpamShield Analytics', 'spamshield-cf'),
            __('SpamShield', 'spamshield-cf'),
            'manage_options',
            'spamshield-analytics',
            array($this, 'dashboard_page'),
            'dashicons-shield-alt',
            30
        );
        
        // Add submenu pages
        add_submenu_page(
            'spamshield-analytics',
            __('Dashboard', 'spamshield-cf'),
            __('Dashboard', 'spamshield-cf'),
            'manage_options',
            'spamshield-analytics',
            array($this, 'dashboard_page')
        );
        
        add_submenu_page(
            'spamshield-analytics',
            __('Reports', 'spamshield-cf'),
            __('Reports', 'spamshield-cf'),
            'manage_options',
            'spamshield-reports',
            array($this, 'reports_page')
        );
        
        add_submenu_page(
            'spamshield-analytics',
            __('Threat Intelligence', 'spamshield-cf'),
            __('Threat Intel', 'spamshield-cf'),
            'manage_options',
            'spamshield-threats',
            array($this, 'threats_page')
        );
        
        add_submenu_page(
            'spamshield-analytics',
            __('AI Detection Settings', 'spamshield-cf'),
            __('AI Settings', 'spamshield-cf'),
            'manage_options',
            'spamshield-ai-settings',
            array($this, 'ai_settings_page')
        );
        
        add_submenu_page(
            'spamshield-analytics',
            __('Database Status & Utilities', 'spamshield-cf'),
            __('Database', 'spamshield-cf'),
            'manage_options',
            'spamshield-database',
            array($this, 'database_status_page')
        );
        
        // Additional admin pages
        add_submenu_page(
            'spamshield-analytics',
            __('Contact Form Settings', 'spamshield-cf'),
            __('Form Settings', 'spamshield-cf'),
            'manage_options',
            'spamshield-settings',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'spamshield-analytics',
            __('Form Entries', 'spamshield-cf'),
            __('Form Entries', 'spamshield-cf'),
            'manage_options',
            'spamshield-entries',
            array($this, 'entries_page')
        );
        
        add_submenu_page(
            'spamshield-analytics',
            __('Comment Protection', 'spamshield-cf'),
            __('Comments', 'spamshield-cf'),
            'manage_options',
            'spamshield-comments',
            array($this, 'comment_protection_page')
        );
        
        // Form Builder pages
        add_submenu_page(
            'spamshield-analytics',
            __('Form Builder', 'spamshield-cf'),
            __('Form Builder', 'spamshield-cf'),
            'manage_options',
            'spamshield-form-builder',
            array($this, 'form_builder_page')
        );
        
        add_submenu_page(
            'spamshield-analytics',
            __('All Forms', 'spamshield-cf'),
            __('All Forms', 'spamshield-cf'),
            'manage_options',
            'spamshield-all-forms',
            array($this, 'all_forms_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'spamshield') === false) {
            return;
        }
        
        // Chart.js for data visualization
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.9.1', true);
        
        // Custom dashboard JS
        wp_enqueue_script(
            'sscf-dashboard',
            SSCF_PLUGIN_URL . 'assets/js/dashboard.js',
            array('jquery', 'chartjs'),
            SSCF_VERSION,
            true
        );
        
        // Custom dashboard CSS
        wp_enqueue_style(
            'sscf-dashboard',
            SSCF_PLUGIN_URL . 'assets/css/dashboard.css',
            array(),
            SSCF_VERSION
        );
        
        // Localize script
        wp_localize_script('sscf-dashboard', 'sscf_dashboard', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sscf_dashboard_nonce'),
            'strings' => array(
                'loading' => __('Loading...', 'spamshield-cf'),
                'error' => __('Error loading data', 'spamshield-cf'),
                'no_data' => __('No data available', 'spamshield-cf')
            )
        ));
    }
    
    /**
     * Main dashboard page
     */
    public function dashboard_page() {
        echo '<div class="wrap sscf-dashboard">';
        echo '<div class="sscf-dashboard-header">';
        echo '<h1>' . __('SpamShield Analytics Dashboard', 'spamshield-cf') . '</h1>';
        echo '<div class="sscf-theme-toggle">';
        echo '<button id="sscf-theme-toggle" class="button button-secondary" title="Toggle Light/Dark Mode">';
        echo '<span class="dashicons dashicons-visibility"></span> Theme';
        echo '</button>';
        echo '</div>';
        echo '</div>';
        
        // Dashboard widgets
        $this->render_summary_widgets();
        $this->render_charts_section();
        $this->render_recent_activity();
        $this->render_threat_intelligence();
        
        echo '</div>';
    }
    
    /**
     * Render summary widgets
     */
    private function render_summary_widgets() {
        $stats = $this->get_summary_stats();
        
        echo '<div class="sscf-widget-grid">';
        
        // Today's protection
        echo '<div class="sscf-widget sscf-widget-primary">';
        echo '<div class="sscf-widget-icon"><span class="dashicons dashicons-shield-alt"></span></div>';
        echo '<div class="sscf-widget-content">';
        echo '<h3>' . number_format($stats['today_blocked']) . '</h3>';
        echo '<p>' . __('Spam Blocked Today', 'spamshield-cf') . '</p>';
        echo '<div class="sscf-widget-trend">';
        $trend = $this->calculate_trend($stats['today_blocked'], $stats['yesterday_blocked']);
        echo '<span class="sscf-trend ' . ($trend >= 0 ? 'up' : 'down') . '">';
        echo ($trend >= 0 ? '↑' : '↓') . ' ' . abs($trend) . '%';
        echo '</span>';
        echo '<small>' . __('vs yesterday', 'spamshield-cf') . '</small>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Total protection
        echo '<div class="sscf-widget sscf-widget-success">';
        echo '<div class="sscf-widget-icon"><span class="dashicons dashicons-yes-alt"></span></div>';
        echo '<div class="sscf-widget-content">';
        echo '<h3>' . number_format($stats['total_blocked']) . '</h3>';
        echo '<p>' . __('Total Spam Blocked', 'spamshield-cf') . '</p>';
        echo '<div class="sscf-widget-trend">';
        echo '<span class="sscf-metric">' . __('All time', 'spamshield-cf') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Protection rate
        echo '<div class="sscf-widget sscf-widget-info">';
        echo '<div class="sscf-widget-icon"><span class="dashicons dashicons-chart-area"></span></div>';
        echo '<div class="sscf-widget-content">';
        echo '<h3>' . number_format($stats['protection_rate'], 1) . '%</h3>';
        echo '<p>' . __('Protection Rate (30 days)', 'spamshield-cf') . '</p>';
        echo '<div class="sscf-widget-trend">';
        echo '<span class="sscf-metric">' . number_format($stats['month_total']) . ' ' . __('total submissions', 'spamshield-cf') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Top threat source
        echo '<div class="sscf-widget sscf-widget-warning">';
        echo '<div class="sscf-widget-icon"><span class="dashicons dashicons-warning"></span></div>';
        echo '<div class="sscf-widget-content">';
        echo '<h3>' . esc_html($stats['top_threat_country']) . '</h3>';
        echo '<p>' . __('Top Threat Source', 'spamshield-cf') . '</p>';
        echo '<div class="sscf-widget-trend">';
        echo '<span class="sscf-metric">' . number_format($stats['top_threat_count']) . ' ' . __('attacks', 'spamshield-cf') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render charts section
     */
    private function render_charts_section() {
        echo '<div class="sscf-charts-section">';
        
        // Main chart
        echo '<div class="sscf-chart-container sscf-chart-main">';
        echo '<h2>' . __('Spam Protection Over Time', 'spamshield-cf') . '</h2>';
        echo '<div class="sscf-chart-controls">';
        echo '<button class="button" data-period="7">' . __('7 Days', 'spamshield-cf') . '</button>';
        echo '<button class="button button-primary" data-period="30">' . __('30 Days', 'spamshield-cf') . '</button>';
        echo '<button class="button" data-period="90">' . __('90 Days', 'spamshield-cf') . '</button>';
        echo '</div>';
        echo '<canvas id="sscf-timeline-chart" width="400" height="150"></canvas>';
        echo '</div>';
        
        // Side charts
        echo '<div class="sscf-chart-sidebar">';
        
        echo '<div class="sscf-chart-container">';
        echo '<h3>' . __('Detection Methods', 'spamshield-cf') . '</h3>';
        echo '<canvas id="sscf-methods-chart" width="200" height="200"></canvas>';
        echo '</div>';
        
        echo '<div class="sscf-chart-container">';
        echo '<h3>' . __('Entry Types', 'spamshield-cf') . '</h3>';
        echo '<canvas id="sscf-types-chart" width="200" height="200"></canvas>';
        echo '</div>';
        
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render recent activity
     */
    private function render_recent_activity() {
        echo '<div class="sscf-recent-activity">';
        echo '<h2>' . __('Recent Activity', 'spamshield-cf') . '</h2>';
        
        $recent_activity = $this->get_recent_activity();
        
        echo '<div class="sscf-activity-feed">';
        
        if (empty($recent_activity)) {
            echo '<p>' . __('No recent activity to display.', 'spamshield-cf') . '</p>';
        } else {
            foreach ($recent_activity as $activity) {
                echo '<div class="sscf-activity-item ' . ($activity->is_spam ? 'spam' : 'clean') . '">';
                echo '<div class="sscf-activity-icon">';
                echo $activity->is_spam ? '🛡️' : '✅';
                echo '</div>';
                echo '<div class="sscf-activity-content">';
                echo '<strong>' . ($activity->is_spam ? __('Spam Blocked', 'spamshield-cf') : __('Clean Submission', 'spamshield-cf')) . '</strong>';
                echo '<p>' . esc_html(substr($activity->content_preview, 0, 80)) . '...</p>';
                echo '<div class="sscf-activity-meta">';
                echo '<span>' . esc_html($activity->detection_method) . '</span>';
                echo '<span>IP: ' . esc_html($activity->user_ip) . '</span>';
                echo '<span>' . human_time_diff(strtotime($activity->timestamp), current_time('timestamp')) . ' ' . __('ago', 'spamshield-cf') . '</span>';
                echo '</div>';
                echo '</div>';
                echo '<div class="sscf-activity-score">';
                echo '<span class="sscf-score-badge">' . intval($activity->spam_score) . '</span>';
                echo '</div>';
                echo '</div>';
            }
        }
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render threat intelligence section
     */
    private function render_threat_intelligence() {
        echo '<div class="sscf-threat-intel">';
        echo '<h2>' . __('Threat Intelligence', 'spamshield-cf') . '</h2>';
        
        $threat_data = $this->get_threat_intelligence();
        
        echo '<div class="sscf-intel-grid">';
        
        // Top IPs
        echo '<div class="sscf-intel-widget">';
        echo '<h3>' . __('Top Threat IPs', 'spamshield-cf') . '</h3>';
        echo '<div class="sscf-threat-list">';
        foreach (array_slice($threat_data['top_ips'], 0, 5) as $ip_data) {
            echo '<div class="sscf-threat-item">';
            echo '<code>' . esc_html($ip_data->user_ip) . '</code>';
            echo '<span class="sscf-threat-count">' . number_format($ip_data->attack_count) . ' attacks</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
        
        // Attack patterns
        echo '<div class="sscf-intel-widget">';
        echo '<h3>' . __('Attack Patterns', 'spamshield-cf') . '</h3>';
        echo '<div class="sscf-pattern-list">';
        foreach (array_slice($threat_data['attack_patterns'], 0, 5) as $pattern) {
            echo '<div class="sscf-pattern-item">';
            echo '<strong>' . esc_html($pattern->detection_method) . '</strong>';
            echo '<div class="sscf-pattern-bar">';
            echo '<div class="sscf-pattern-fill" style="width: ' . ($pattern->count / $threat_data['max_pattern'] * 100) . '%"></div>';
            echo '</div>';
            echo '<span>' . number_format($pattern->count) . '</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Get summary statistics
     */
    private function get_summary_stats() {
        global $wpdb;
        
        // Today's stats
        $today_blocked = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->analytics_table} 
            WHERE is_spam = 1 AND DATE(timestamp) = CURDATE()
        ");
        
        $yesterday_blocked = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->analytics_table} 
            WHERE is_spam = 1 AND DATE(timestamp) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ");
        
        $total_blocked = $wpdb->get_var("
            SELECT COUNT(*) FROM {$this->analytics_table} WHERE is_spam = 1
        ");
        
        // 30-day stats for protection rate
        $month_stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as blocked
            FROM {$this->analytics_table} 
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $protection_rate = $month_stats && $month_stats->total > 0 
            ? ($month_stats->blocked / $month_stats->total) * 100 
            : 0;
        
        // Top threat source (simplified - would need IP geolocation for real countries)
        $top_ip = $wpdb->get_row("
            SELECT user_ip, COUNT(*) as count 
            FROM {$this->analytics_table} 
            WHERE is_spam = 1 AND timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY user_ip 
            ORDER BY count DESC 
            LIMIT 1
        ");
        
        return array(
            'today_blocked' => intval($today_blocked),
            'yesterday_blocked' => intval($yesterday_blocked),
            'total_blocked' => intval($total_blocked),
            'protection_rate' => floatval($protection_rate),
            'month_total' => intval($month_stats->total ?? 0),
            'top_threat_country' => $top_ip ? substr($top_ip->user_ip, 0, -3) . '***' : __('Unknown', 'spamshield-cf'),
            'top_threat_count' => intval($top_ip->count ?? 0)
        );
    }
    
    /**
     * Calculate trend percentage
     */
    private function calculate_trend($current, $previous) {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }
    
    /**
     * Get recent activity
     */
    private function get_recent_activity() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT * FROM {$this->analytics_table} 
            ORDER BY timestamp DESC 
            LIMIT 10
        ");
    }
    
    /**
     * Get threat intelligence data
     */
    private function get_threat_intelligence() {
        global $wpdb;
        
        $top_ips = $wpdb->get_results("
            SELECT user_ip, COUNT(*) as attack_count 
            FROM {$this->analytics_table} 
            WHERE is_spam = 1 AND timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY user_ip 
            ORDER BY attack_count DESC 
            LIMIT 10
        ");
        
        $attack_patterns = $wpdb->get_results("
            SELECT detection_method, COUNT(*) as count 
            FROM {$this->analytics_table} 
            WHERE is_spam = 1 AND timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY detection_method 
            ORDER BY count DESC
        ");
        
        $max_pattern = $attack_patterns ? max(array_column($attack_patterns, 'count')) : 1;
        
        return array(
            'top_ips' => $top_ips,
            'attack_patterns' => $attack_patterns,
            'max_pattern' => $max_pattern
        );
    }
    
    /**
     * AJAX handler for dashboard stats
     */
    public function get_dashboard_stats_ajax() {
        check_ajax_referer('sscf_dashboard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'spamshield-cf'));
        }
        
        $stats = $this->get_summary_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX handler for chart data
     */
    public function get_chart_data_ajax() {
        check_ajax_referer('sscf_dashboard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'spamshield-cf'));
        }
        
        $period = intval($_POST['period'] ?? 30);
        $chart_data = $this->get_chart_data($period);
        wp_send_json_success($chart_data);
    }
    
    /**
     * Get chart data for specified period
     */
    private function get_chart_data($days = 30) {
        global $wpdb;
        
        // Check if table exists and has data
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->analytics_table}'") == $this->analytics_table;
        $has_data = false;
        
        if ($table_exists) {
            $record_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->analytics_table}");
            $has_data = $record_count > 0;
        }
        
        if (!$has_data) {
            // Return empty data structure when table is empty - starts at 0
            return array(
                'timeline' => array(
                    'labels' => array(),
                    'spam' => array(),
                    'clean' => array()
                ),
                'methods' => array(
                    'labels' => array(),
                    'values' => array()
                ),
                'types' => array(
                    'labels' => array(),
                    'values' => array()
                )
            );
        }
        
        // Timeline data
        $timeline = $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(timestamp) as date,
                COUNT(*) as total,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_blocked,
                SUM(CASE WHEN is_spam = 0 THEN 1 ELSE 0 END) as clean_submissions
            FROM {$this->analytics_table} 
            WHERE timestamp > DATE_SUB(CURDATE(), INTERVAL %d DAY)
            GROUP BY DATE(timestamp) 
            ORDER BY date ASC",
            $days
        ));
        
        // Detection methods pie chart
        $methods = $wpdb->get_results($wpdb->prepare("
            SELECT detection_method, COUNT(*) as count
            FROM {$this->analytics_table} 
            WHERE is_spam = 1 AND timestamp > DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY detection_method 
            ORDER BY count DESC",
            $days
        ));
        
        // Entry types pie chart
        $types = $wpdb->get_results($wpdb->prepare("
            SELECT entry_type, COUNT(*) as count
            FROM {$this->analytics_table} 
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY entry_type 
            ORDER BY count DESC",
            $days
        ));
        
        // Format timeline data for Chart.js
        $timeline_labels = array();
        $timeline_spam = array();
        $timeline_clean = array();
        
        if ($timeline) {
            foreach ($timeline as $row) {
                $timeline_labels[] = date('M d', strtotime($row->date));
                $timeline_spam[] = intval($row->spam_blocked);
                $timeline_clean[] = intval($row->clean_submissions);
            }
        }
        
        // Format methods data for Chart.js
        $methods_labels = array();
        $methods_values = array();
        
        if ($methods) {
            foreach ($methods as $row) {
                $methods_labels[] = ucfirst($row->detection_method ?: 'Unknown');
                $methods_values[] = intval($row->count);
            }
        }
        
        // Format types data for Chart.js
        $types_labels = array();
        $types_values = array();
        
        if ($types) {
            foreach ($types as $row) {
                $types_labels[] = ucfirst($row->entry_type ?: 'Unknown');
                $types_values[] = intval($row->count);
            }
        }
        
        return array(
            'timeline' => array(
                'labels' => $timeline_labels,
                'spam' => $timeline_spam,
                'clean' => $timeline_clean
            ),
            'methods' => array(
                'labels' => $methods_labels,
                'values' => $methods_values
            ),
            'types' => array(
                'labels' => $types_labels,
                'values' => $types_values
            )
        );
    }
    
    /**
     * Generate sample chart data when analytics table is empty
     */
    private function generate_sample_chart_data($days = 30) {
        $timeline_labels = array();
        $timeline_spam = array();
        $timeline_clean = array();
        
        // Generate realistic timeline data for the last 7 days
        $display_days = min($days, 14); // Show up to 14 days of sample data
        for ($i = $display_days - 1; $i >= 0; $i--) {
            $date = date('M j', strtotime("-{$i} days"));
            $timeline_labels[] = $date;
            $timeline_spam[] = rand(5, 25); // Random spam count
            $timeline_clean[] = rand(15, 60); // Random clean submissions
        }
        
        // Sample detection methods
        $methods_labels = array('Honeypot', 'Rate Limiting', 'AI Detection', 'Time Validation');
        $methods_values = array(
            rand(10, 30),
            rand(8, 20),
            rand(15, 35),
            rand(5, 15)
        );
        
        // Sample entry types
        $types_labels = array('Contact Form', 'Comment Spam', 'Newsletter', 'Other');
        $types_values = array(
            rand(25, 50),
            rand(15, 30),
            rand(5, 15),
            rand(3, 10)
        );
        
        return array(
            'timeline' => array(
                'labels' => $timeline_labels,
                'spam' => $timeline_spam,
                'clean' => $timeline_clean
            ),
            'methods' => array(
                'labels' => $methods_labels,
                'values' => $methods_values
            ),
            'types' => array(
                'labels' => $types_labels,
                'values' => $types_values
            )
        );
    }
    
    /**
     * Reports page
     */
    public function reports_page() {
        global $wpdb;
        
        // Handle export request
        if (isset($_POST['export_data']) && wp_verify_nonce($_POST['export_nonce'], 'sscf_export_data')) {
            $this->handle_data_export();
            return;
        }
        
        // Get report data
        $report_data = $this->get_reports_data();
        
        echo '<div class="wrap sscf-reports-page">';
        echo '<h1>' . __('SpamShield Reports', 'spamshield-cf') . '</h1>';
        
        echo '<div class="sscf-reports-header">';
        echo '<p class="description">' . __('Generate comprehensive reports and export your spam protection analytics data.', 'spamshield-cf') . '</p>';
        echo '</div>';

        // Report Summary Cards
        echo '<div class="sscf-report-summary">';
        
        echo '<div class="sscf-summary-card">';
        echo '<div class="sscf-card-icon"><span class="dashicons dashicons-shield-alt"></span></div>';
        echo '<div class="sscf-card-content">';
        echo '<h3>' . number_format($report_data['total_blocks']) . '</h3>';
        echo '<p>' . __('Total Blocks', 'spamshield-cf') . '</p>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="sscf-summary-card">';
        echo '<div class="sscf-card-icon"><span class="dashicons dashicons-warning"></span></div>';
        echo '<div class="sscf-card-content">';
        echo '<h3>' . number_format($report_data['unique_threats']) . '</h3>';
        echo '<p>' . __('Unique Threats', 'spamshield-cf') . '</p>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="sscf-summary-card">';
        echo '<div class="sscf-card-icon"><span class="dashicons dashicons-calendar-alt"></span></div>';
        echo '<div class="sscf-card-content">';
        echo '<h3>' . number_format($report_data['blocks_today']) . '</h3>';
        echo '<p>' . __('Blocks Today', 'spamshield-cf') . '</p>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="sscf-summary-card">';
        echo '<div class="sscf-card-icon"><span class="dashicons dashicons-chart-line"></span></div>';
        echo '<div class="sscf-card-content">';
        echo '<h3>' . number_format($report_data['avg_daily_blocks']) . '</h3>';
        echo '<p>' . __('Avg Daily Blocks', 'spamshield-cf') . '</p>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';

        // Export Section
        echo '<div class="sscf-export-section">';
        echo '<h2>' . __('Export Data', 'spamshield-cf') . '</h2>';
        echo '<form method="post" class="sscf-export-form">';
        wp_nonce_field('sscf_export_data', 'export_nonce');
        
        echo '<div class="sscf-export-options">';
        echo '<div class="sscf-option-group">';
        echo '<label><strong>' . __('Date Range:', 'spamshield-cf') . '</strong></label>';
        echo '<select name="date_range">';
        echo '<option value="7">' . __('Last 7 days', 'spamshield-cf') . '</option>';
        echo '<option value="30" selected>' . __('Last 30 days', 'spamshield-cf') . '</option>';
        echo '<option value="90">' . __('Last 90 days', 'spamshield-cf') . '</option>';
        echo '<option value="365">' . __('Last year', 'spamshield-cf') . '</option>';
        echo '<option value="all">' . __('All time', 'spamshield-cf') . '</option>';
        echo '</select>';
        echo '</div>';
        
        echo '<div class="sscf-option-group">';
        echo '<label><strong>' . __('Export Format:', 'spamshield-cf') . '</strong></label>';
        echo '<select name="export_format">';
        echo '<option value="csv">' . __('CSV (Comma Separated)', 'spamshield-cf') . '</option>';
        echo '<option value="json">' . __('JSON (JavaScript Object)', 'spamshield-cf') . '</option>';
        echo '</select>';
        echo '</div>';
        
        echo '<div class="sscf-option-group">';
        echo '<label><strong>' . __('Include:', 'spamshield-cf') . '</strong></label>';
        echo '<label><input type="checkbox" name="include_ips" value="1" checked> ' . __('IP Addresses', 'spamshield-cf') . '</label>';
        echo '<label><input type="checkbox" name="include_methods" value="1" checked> ' . __('Detection Methods', 'spamshield-cf') . '</label>';
        echo '<label><input type="checkbox" name="include_timestamps" value="1" checked> ' . __('Timestamps', 'spamshield-cf') . '</label>';
        echo '<label><input type="checkbox" name="include_user_agents" value="1"> ' . __('User Agents', 'spamshield-cf') . '</label>';
        echo '</div>';
        echo '</div>';

        echo '<p class="submit">';
        echo '<input type="submit" name="export_data" class="button-primary" value="' . __('Export Data', 'spamshield-cf') . '">';
        echo '</p>';
        echo '</form>';
        echo '</div>';

        // Recent Activity Report
        echo '<div class="sscf-recent-report">';
        echo '<h2>' . __('Recent Activity Report', 'spamshield-cf') . '</h2>';
        echo '<div class="sscf-report-table-container">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . __('Date/Time', 'spamshield-cf') . '</th>';
        echo '<th>' . __('IP Address', 'spamshield-cf') . '</th>';
        echo '<th>' . __('Detection Method', 'spamshield-cf') . '</th>';
        echo '<th>' . __('Status', 'spamshield-cf') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($report_data['recent_activity'] as $activity) {
            echo '<tr>';
            echo '<td>' . esc_html(date('M j, Y H:i:s', strtotime($activity->timestamp))) . '</td>';
            echo '<td><code>' . esc_html($activity->user_ip) . '</code></td>';
            echo '<td>' . esc_html($activity->detection_method) . '</td>';
            echo '<td><span class="sscf-status-badge ' . ($activity->is_spam ? 'blocked' : 'allowed') . '">';
            echo ($activity->is_spam ? __('Blocked', 'spamshield-cf') : __('Allowed', 'spamshield-cf'));
            echo '</span></td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }
    
    /**
     * Threats page
     */
    public function threats_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Threat Intelligence', 'spamshield-cf') . '</h1>';
        echo '<div class="sscf-threats-page">';
        
        // Display threat intelligence data
        $this->render_threat_intelligence();
        
        // Additional threat analysis section
        echo '<div class="sscf-threat-analysis">';
        echo '<h2>' . __('Threat Analysis Summary', 'spamshield-cf') . '</h2>';
        
        $threat_data = $this->get_threat_intelligence();
        $total_threats = count($threat_data['top_ips']);
        $total_patterns = count($threat_data['attack_patterns']);
        
        if ($total_threats > 0 || $total_patterns > 0) {
            echo '<div class="sscf-analysis-grid">';
            
            // Threat summary
            echo '<div class="sscf-analysis-card">';
            echo '<h3>' . __('Threat Summary (Last 30 Days)', 'spamshield-cf') . '</h3>';
            echo '<ul>';
            echo '<li><strong>' . number_format($total_threats) . '</strong> ' . __('unique threat IPs identified', 'spamshield-cf') . '</li>';
            echo '<li><strong>' . number_format($total_patterns) . '</strong> ' . __('different attack patterns detected', 'spamshield-cf') . '</li>';
            
            if ($threat_data['top_ips']) {
                $top_threat = $threat_data['top_ips'][0];
                echo '<li>' . __('Most active threat IP:', 'spamshield-cf') . ' <code>' . esc_html($top_threat->user_ip) . '</code> (' . number_format($top_threat->attack_count) . ' attacks)</li>';
            }
            
            echo '</ul>';
            echo '</div>';
            
            // Detection methods breakdown
            if ($threat_data['attack_patterns']) {
                echo '<div class="sscf-analysis-card">';
                echo '<h3>' . __('Detection Methods', 'spamshield-cf') . '</h3>';
                echo '<div class="sscf-detection-methods">';
                foreach ($threat_data['attack_patterns'] as $pattern) {
                    $percentage = round(($pattern->count / $threat_data['max_pattern']) * 100, 1);
                    echo '<div class="sscf-detection-item">';
                    echo '<span class="method-name">' . esc_html($pattern->detection_method) . '</span>';
                    echo '<span class="method-count">(' . number_format($pattern->count) . ' - ' . $percentage . '%)</span>';
                    echo '</div>';
                }
                echo '</div>';
                echo '</div>';
            }
            
            echo '</div>';
        } else {
            echo '<div class="sscf-no-threats">';
            echo '<p>' . __('No threat data available for the last 30 days. This is good news - your site appears to be secure!', 'spamshield-cf') . '</p>';
            echo '<p><em>' . __('Threat intelligence data is collected as the plugin blocks spam and malicious activities.', 'spamshield-cf') . '</em></p>';
            echo '</div>';
        }
        
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * AI Settings page
     */
    public function ai_settings_page() {
        require_once SSCF_PLUGIN_PATH . 'admin/ai-settings-page.php';
    }
    
    /**
     * Database Status page
     */
    public function database_status_page() {
        require_once SSCF_PLUGIN_PATH . 'admin/database-status-page.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        require_once SSCF_PLUGIN_PATH . 'admin/settings-page.php';
    }
    
    /**
     * Form Entries page
     */
    public function entries_page() {
        require_once SSCF_PLUGIN_PATH . 'admin/entries-page.php';
    }
    
    /**
     * Comment Protection page
     */
    public function comment_protection_page() {
        require_once SSCF_PLUGIN_PATH . 'admin/comment-protection-page.php';
    }
    
    /**
     * Form Builder page
     */
    public function form_builder_page() {
        // Get existing form builder instance
        global $sscf_form_builder;
        if (!$sscf_form_builder) {
            $sscf_form_builder = new SSCF_Form_Builder();
        }
        $sscf_form_builder->form_builder_page();
    }
    
    /**
     * All Forms page
     */
    public function all_forms_page() {
        // Get existing form builder instance
        global $sscf_form_builder;
        if (!$sscf_form_builder) {
            $sscf_form_builder = new SSCF_Form_Builder();
        }
        $sscf_form_builder->all_forms_page();
    }
    
    /**
     * Send daily report email
     */
    public function send_daily_report() {
        $options = get_option('sscf_options', array());
        
        if (empty($options['daily_reports_enabled'])) {
            return;
        }
        
        $stats = $this->get_summary_stats();
        
        $subject = sprintf(__('[%s] Daily SpamShield Report', 'spamshield-cf'), get_bloginfo('name'));
        
        $message = sprintf(
            __("Daily SpamShield Protection Report\n\n" .
               "Spam Blocked Today: %d\n" .
               "Total Protection Rate: %.1f%%\n" .
               "Total Spam Blocked: %d\n\n" .
               "Your site is protected by SpamShield!\n", 'spamshield-cf'),
            $stats['today_blocked'],
            $stats['protection_rate'],
            $stats['total_blocked']
        );
        
        $recipient = $options['email_recipient'] ?? get_option('admin_email');
        wp_mail($recipient, $subject, $message);
    }
    
    /**
     * Get reports data
     */
    private function get_reports_data() {
        global $wpdb;
        
        // Basic statistics
        $total_blocks = $wpdb->get_var("SELECT COUNT(*) FROM {$this->analytics_table} WHERE is_spam = 1");
        $unique_threats = $wpdb->get_var("SELECT COUNT(DISTINCT user_ip) FROM {$this->analytics_table} WHERE is_spam = 1");
        $blocks_today = $wpdb->get_var("SELECT COUNT(*) FROM {$this->analytics_table} WHERE is_spam = 1 AND DATE(timestamp) = CURDATE()");
        
        // Average daily blocks (last 30 days)
        $avg_daily = $wpdb->get_var("
            SELECT AVG(daily_count) FROM (
                SELECT COUNT(*) as daily_count 
                FROM {$this->analytics_table} 
                WHERE is_spam = 1 AND timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(timestamp)
            ) as daily_stats
        ");
        
        // Recent activity (last 50 records)
        $recent_activity = $wpdb->get_results("
            SELECT timestamp, user_ip, detection_method, is_spam 
            FROM {$this->analytics_table} 
            ORDER BY timestamp DESC 
            LIMIT 50
        ");
        
        return array(
            'total_blocks' => intval($total_blocks),
            'unique_threats' => intval($unique_threats),
            'blocks_today' => intval($blocks_today),
            'avg_daily_blocks' => intval($avg_daily),
            'recent_activity' => $recent_activity ?: array()
        );
    }
    
    /**
     * Handle data export
     */
    private function handle_data_export() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'spamshield-cf'));
        }
        
        global $wpdb;
        
        $date_range = sanitize_text_field($_POST['date_range'] ?? '30');
        $format = sanitize_text_field($_POST['export_format'] ?? 'csv');
        $include_ips = isset($_POST['include_ips']);
        $include_methods = isset($_POST['include_methods']);
        $include_timestamps = isset($_POST['include_timestamps']);
        $include_user_agents = isset($_POST['include_user_agents']);
        
        // Build query
        $select_fields = array('id');
        if ($include_timestamps) $select_fields[] = 'timestamp';
        if ($include_ips) $select_fields[] = 'user_ip';
        if ($include_methods) $select_fields[] = 'detection_method';
        if ($include_user_agents) $select_fields[] = 'user_agent';
        $select_fields[] = 'is_spam';
        
        $where_clause = '';
        if ($date_range !== 'all') {
            $days = intval($date_range);
            $where_clause = "WHERE timestamp > DATE_SUB(NOW(), INTERVAL {$days} DAY)";
        }
        
        $query = "SELECT " . implode(', ', $select_fields) . " FROM {$this->analytics_table} {$where_clause} ORDER BY timestamp DESC";
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            wp_die(__('No data to export for the selected criteria.', 'spamshield-cf'));
        }
        
        $filename = 'spamshield-export-' . date('Y-m-d-H-i-s');
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Add headers
            fputcsv($output, array_keys($results[0]));
            
            // Add data
            foreach ($results as $row) {
                // Convert is_spam to readable format
                $row['is_spam'] = $row['is_spam'] ? 'Blocked' : 'Allowed';
                fputcsv($output, $row);
            }
            
            fclose($output);
        } else {
            // JSON format
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '.json"');
            
            // Convert is_spam to readable format
            foreach ($results as &$row) {
                $row['is_spam'] = $row['is_spam'] ? 'Blocked' : 'Allowed';
            }
            
            echo json_encode(array(
                'exported_at' => date('Y-m-d H:i:s'),
                'date_range' => $date_range,
                'total_records' => count($results),
                'data' => $results
            ), JSON_PRETTY_PRINT);
        }
        
        exit;
    }
}
