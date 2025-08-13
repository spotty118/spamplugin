<?php
/**
 * Report Generator Class
 * Handles CSV/PDF export and scheduled reporting
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Report_Generator {
    
    private $analytics_table;
    private $entries_table;
    
    public function __construct() {
        global $wpdb;
        $this->analytics_table = $wpdb->prefix . 'sscf_comment_analytics';
        $this->entries_table = $wpdb->prefix . 'sscf_entries';
        
        // AJAX hooks for exports
        add_action('wp_ajax_sscf_export_csv', array($this, 'export_csv_ajax'));
        add_action('wp_ajax_sscf_export_pdf', array($this, 'export_pdf_ajax'));
        add_action('wp_ajax_sscf_schedule_report', array($this, 'schedule_report_ajax'));
        
        // Cron hooks for scheduled reports
        add_action('sscf_weekly_report', array($this, 'send_weekly_report'));
        add_action('sscf_monthly_report', array($this, 'send_monthly_report'));
        
        // Admin page hooks - menu registration handled by unified SpamShield menu
        // add_action('admin_menu', array($this, 'add_reports_menu'), 25); // Disabled - integrated into main menu
    }
    
    /**
     * Add reports admin menu
     */
    public function add_reports_menu() {
        add_submenu_page(
            'spamshield-analytics',
            __('Reports & Export', 'spamshield-cf'),
            __('Reports', 'spamshield-cf'),
            'manage_options',
            'spamshield-reports-detailed',
            array($this, 'reports_page')
        );
    }
    
    /**
     * Reports admin page
     */
    public function reports_page() {
        echo '<div class="wrap sscf-reports">';
        echo '<h1>' . __('SpamShield Reports & Export', 'spamshield-cf') . '</h1>';
        
        $this->render_quick_stats();
        $this->render_export_section();
        $this->render_scheduled_reports();
        $this->render_report_history();
        
        echo '</div>';
    }
    
    /**
     * Render quick statistics
     */
    private function render_quick_stats() {
        global $wpdb;
        
        // Sanitize table name and use prepared statement
        $analytics_table = esc_sql($this->analytics_table);
        
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_events,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_blocked,
                SUM(CASE WHEN is_spam = 0 THEN 1 ELSE 0 END) as legitimate,
                COUNT(DISTINCT user_ip) as unique_ips,
                COUNT(DISTINCT DATE(timestamp)) as active_days
            FROM `{$analytics_table}`
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL %d DAY)
        ", 30));
        
        // Add error handling for database query
        if ($wpdb->last_error) {
            error_log('SpamShield: Database error in render_quick_stats: ' . $wpdb->last_error);
            echo '<div class="notice notice-error"><p>' . __('Error loading statistics. Please try again later.', 'spamshield-cf') . '</p></div>';
            return;
        }
        
        // Handle null result
        if (!$stats) {
            echo '<div class="notice notice-info"><p>' . __('No data available yet.', 'spamshield-cf') . '</p></div>';
            return;
        }
        
        echo '<div class="sscf-report-stats">';
        echo '<div class="stats-grid">';
        
        echo '<div class="stat-card">';
        echo '<h3>' . number_format($stats->spam_blocked) . '</h3>';
        echo '<p>' . __('Spam Blocked (30 Days)', 'spamshield-cf') . '</p>';
        echo '</div>';
        
        echo '<div class="stat-card">';
        echo '<h3>' . number_format($stats->legitimate) . '</h3>';
        echo '<p>' . __('Clean Submissions (30 Days)', 'spamshield-cf') . '</p>';
        echo '</div>';
        
        echo '<div class="stat-card">';
        echo '<h3>' . number_format($stats->unique_ips) . '</h3>';
        echo '<p>' . __('Unique IP Addresses', 'spamshield-cf') . '</p>';
        echo '</div>';
        
        echo '<div class="stat-card">';
        echo '<h3>' . number_format($stats->active_days) . '</h3>';
        echo '<p>' . __('Active Days', 'spamshield-cf') . '</p>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render export section
     */
    private function render_export_section() {
        echo '<div class="sscf-export-section">';
        echo '<h2>' . __('Export Data', 'spamshield-cf') . '</h2>';
        
        echo '<form id="sscf-export-form" class="export-form">';
        wp_nonce_field('sscf_export_nonce');
        
        echo '<table class="form-table">';
        
        // Date range
        echo '<tr>';
        echo '<th scope="row">' . __('Date Range', 'spamshield-cf') . '</th>';
        echo '<td>';
        echo '<select name="date_range" id="export-date-range">';
        echo '<option value="7">' . __('Last 7 Days', 'spamshield-cf') . '</option>';
        echo '<option value="30" selected>' . __('Last 30 Days', 'spamshield-cf') . '</option>';
        echo '<option value="90">' . __('Last 90 Days', 'spamshield-cf') . '</option>';
        echo '<option value="365">' . __('Last Year', 'spamshield-cf') . '</option>';
        echo '<option value="custom">' . __('Custom Range', 'spamshield-cf') . '</option>';
        echo '</select>';
        
        echo '<div id="custom-date-range" style="display: none; margin-top: 10px;">';
        echo '<input type="date" name="start_date" id="start_date">';
        echo ' ' . __('to', 'spamshield-cf') . ' ';
        echo '<input type="date" name="end_date" id="end_date">';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
        
        // Data type
        echo '<tr>';
        echo '<th scope="row">' . __('Include Data', 'spamshield-cf') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="include_spam" value="1" checked> ' . __('Spam Attempts', 'spamshield-cf') . '</label><br>';
        echo '<label><input type="checkbox" name="include_clean" value="1" checked> ' . __('Clean Submissions', 'spamshield-cf') . '</label><br>';
        echo '<label><input type="checkbox" name="include_ip_data" value="1"> ' . __('IP Address Data', 'spamshield-cf') . '</label><br>';
        echo '<label><input type="checkbox" name="include_user_agents" value="1"> ' . __('User Agent Strings', 'spamshield-cf') . '</label>';
        echo '</td>';
        echo '</tr>';
        
        // Format
        echo '<tr>';
        echo '<th scope="row">' . __('Export Format', 'spamshield-cf') . '</th>';
        echo '<td>';
        echo '<label><input type="radio" name="export_format" value="csv" checked> ' . __('CSV (Excel Compatible)', 'spamshield-cf') . '</label><br>';
        echo '<label><input type="radio" name="export_format" value="pdf"> ' . __('PDF Report', 'spamshield-cf') . '</label><br>';
        echo '<label><input type="radio" name="export_format" value="json"> ' . __('JSON Data', 'spamshield-cf') . '</label>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        echo '<p class="submit">';
        echo '<button type="button" id="generate-export" class="button button-primary">' . __('Generate Export', 'spamshield-cf') . '</button>';
        echo '<span class="spinner" id="export-spinner"></span>';
        echo '</p>';
        
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Render scheduled reports section
     */
    private function render_scheduled_reports() {
        echo '<div class="sscf-scheduled-reports">';
        echo '<h2>' . __('Scheduled Reports', 'spamshield-cf') . '</h2>';
        
        $options = get_option('sscf_options', array());
        $weekly_enabled = !empty($options['weekly_reports_enabled']);
        $monthly_enabled = !empty($options['monthly_reports_enabled']);
        
        echo '<form method="post" action="options.php">';
        settings_fields('sscf_options');
        
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th scope="row">' . __('Weekly Reports', 'spamshield-cf') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="sscf_options[weekly_reports_enabled]" value="1"' . checked(1, $weekly_enabled, false) . '>';
        echo ' ' . __('Send weekly summary reports', 'spamshield-cf');
        echo '</label>';
        echo '<p class="description">' . __('Sent every Monday morning with the previous week\'s statistics.', 'spamshield-cf') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . __('Monthly Reports', 'spamshield-cf') . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="sscf_options[monthly_reports_enabled]" value="1"' . checked(1, $monthly_enabled, false) . '>';
        echo ' ' . __('Send monthly detailed reports', 'spamshield-cf');
        echo '</label>';
        echo '<p class="description">' . __('Sent on the 1st of each month with comprehensive analytics.', 'spamshield-cf') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . __('Report Recipients', 'spamshield-cf') . '</th>';
        echo '<td>';
        $recipients = $options['report_recipients'] ?? get_option('admin_email');
        echo '<input type="text" name="sscf_options[report_recipients]" value="' . esc_attr($recipients) . '" class="regular-text" placeholder="email@example.com, another@example.com">';
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const emailInput = document.querySelector("input[name=\"sscf_options[report_recipients]\"]");
            if (emailInput) {
                emailInput.addEventListener("blur", function() {
                    const emails = this.value.split(",").map(email => email.trim()).filter(email => email);
                    const invalidEmails = emails.filter(email => !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));
                    if (invalidEmails.length > 0) {
                        alert("Invalid email format(s): " + invalidEmails.join(", "));
                        this.focus();
                    }
                });
            }
        });
        </script>';
        echo '<p class="description">' . __('Email addresses to receive reports (comma-separated for multiple recipients).', 'spamshield-cf') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        submit_button(__('Save Report Settings', 'spamshield-cf'));
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Render report history
     */
    private function render_report_history() {
        echo '<div class="sscf-report-history">';
        echo '<h2>' . __('Recent Exports', 'spamshield-cf') . '</h2>';
        
        $reports = get_option('sscf_export_history', array());
        
        if (empty($reports)) {
            echo '<p>' . __('No exports generated yet.', 'spamshield-cf') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . __('Date', 'spamshield-cf') . '</th>';
            echo '<th>' . __('Format', 'spamshield-cf') . '</th>';
            echo '<th>' . __('Date Range', 'spamshield-cf') . '</th>';
            echo '<th>' . __('Records', 'spamshield-cf') . '</th>';
            echo '<th>' . __('Download', 'spamshield-cf') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach (array_slice(array_reverse($reports), 0, 10) as $report) {
                echo '<tr>';
                echo '<td>' . esc_html($report['created']) . '</td>';
                echo '<td>' . strtoupper(esc_html($report['format'])) . '</td>';
                echo '<td>' . esc_html($report['date_range']) . '</td>';
                echo '<td>' . number_format($report['record_count']) . '</td>';
                echo '<td>';
                if (!empty($report['file_path']) && file_exists($report['file_path'])) {
                    // Secure file path handling - ensure file is in allowed export directory
                    $upload_dir = wp_upload_dir();
                    $export_dir = $upload_dir['basedir'] . '/spamshield-exports/';
                    
                    // Normalize paths and check if file is within export directory
                    $real_export_dir = realpath($export_dir);
                    $real_file_path = realpath($report['file_path']);
                    
                    if ($real_file_path && $real_export_dir && strpos($real_file_path, $real_export_dir) === 0) {
                        // Safe to serve - file is in exports directory
                        $relative_path = str_replace($upload_dir['basedir'], '', $report['file_path']);
                        $file_url = $upload_dir['baseurl'] . $relative_path;
                        echo '<a href="' . esc_url($file_url) . '" class="button button-small">' . __('Download', 'spamshield-cf') . '</a>';
                    } else {
                        echo '<span class="description">' . __('File access denied for security', 'spamshield-cf') . '</span>';
                    }
                } else {
                    echo '<span class="description">' . __('File no longer available', 'spamshield-cf') . '</span>';
                }
            echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
        }
        
        echo '</div>';
    }
    
    /**
     * AJAX handler for CSV export
     */
    public function export_csv_ajax() {
        check_ajax_referer('sscf_export_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'spamshield-cf'));
        }
        
        $params = $this->sanitize_export_params($_POST);
        if (is_wp_error($params)) {
            wp_send_json_error($params->get_error_message());
        }
        
        $csv_data = $this->generate_csv_data($params);
        
        if (empty($csv_data)) {
            wp_send_json_error(__('No data found for the specified criteria.', 'spamshield-cf'));
        }
        
        // Create export file with secure directory handling
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/spamshield-exports/';
        
        if (!file_exists($export_dir)) {
            if (!wp_mkdir_p($export_dir)) {
                wp_send_json_error(__('Failed to create export directory.', 'spamshield-cf'));
            }
            
            // Add security file to prevent direct access
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($export_dir . '.htaccess', $htaccess_content);
            
            $index_content = "<?php\n// Silence is golden.\n";
            file_put_contents($export_dir . 'index.php', $index_content);
        }
        
        $filename = 'spamshield-export-' . date('Y-m-d-H-i-s') . '.csv';
        $file_path = $export_dir . $filename;
        
        // Write CSV data
        $fp = fopen($file_path, 'w');
        foreach ($csv_data as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        
        // Save to export history
        $this->save_export_history('csv', $params, count($csv_data) - 1, $file_path); // -1 for header row
        
        // Return download URL
        $file_url = $upload_dir['baseurl'] . '/spamshield-exports/' . $filename;
        wp_send_json_success(array(
            'download_url' => $file_url,
            'filename' => $filename,
            'records' => count($csv_data) - 1
        ));
    }
    
    /**
     * AJAX handler for PDF export
     */
    public function export_pdf_ajax() {
        check_ajax_referer('sscf_export_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'spamshield-cf'));
        }
        
        $params = $this->sanitize_export_params($_POST);
        
        // For now, we'll create a simple HTML-to-PDF conversion
        // In a production environment, you might want to use a library like TCPDF or DOMPDF
        $html_content = $this->generate_pdf_html($params);
        
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/spamshield-exports/';
        
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }
        
        $filename = 'spamshield-report-' . date('Y-m-d-H-i-s') . '.html';
        $file_path = $export_dir . $filename;
        
        file_put_contents($file_path, $html_content);
        
        $file_url = $upload_dir['baseurl'] . '/spamshield-exports/' . $filename;
        wp_send_json_success(array(
            'download_url' => $file_url,
            'filename' => $filename,
            'message' => __('HTML report generated. For PDF conversion, please use your browser\'s print-to-PDF feature.', 'spamshield-cf')
        ));
    }
    
    /**
     * Sanitize export parameters with comprehensive validation
     */
    private function sanitize_export_params($post_data) {
        $date_range = intval($post_data['date_range'] ?? 30);
        $start_date = sanitize_text_field($post_data['start_date'] ?? '');
        $end_date = sanitize_text_field($post_data['end_date'] ?? '');
        $export_format = sanitize_text_field($post_data['export_format'] ?? 'csv');
        
        // Validate date range
        if ($date_range < 1 || $date_range > 3650) { // Max 10 years
            return new WP_Error('invalid_date_range', __('Date range must be between 1 and 3650 days.', 'spamshield-cf'));
        }
        
        // Validate custom date range if provided
        if (!empty($start_date) || !empty($end_date)) {
            if (!empty($start_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
                return new WP_Error('invalid_start_date', __('Invalid start date format. Use YYYY-MM-DD.', 'spamshield-cf'));
            }
            if (!empty($end_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                return new WP_Error('invalid_end_date', __('Invalid end date format. Use YYYY-MM-DD.', 'spamshield-cf'));
            }
            if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
                return new WP_Error('invalid_date_order', __('Start date must be before end date.', 'spamshield-cf'));
            }
        }
        
        // Validate export format
        if (!in_array($export_format, ['csv', 'html', 'pdf'])) {
            return new WP_Error('invalid_format', __('Invalid export format.', 'spamshield-cf'));
        }
        
        return array(
            'date_range' => $date_range,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'include_spam' => !empty($post_data['include_spam']),
            'include_clean' => !empty($post_data['include_clean']),
            'include_ip_data' => !empty($post_data['include_ip_data']),
            'include_user_agents' => !empty($post_data['include_user_agents']),
            'export_format' => $export_format
        );
    }
    
    /**
     * Generate CSV data
     */
    private function generate_csv_data($params) {
        global $wpdb;
        
        // Build WHERE clause
        $where_clauses = array();
        
        if ($params['date_range'] && empty($params['start_date'])) {
            $where_clauses[] = $wpdb->prepare("timestamp > DATE_SUB(NOW(), INTERVAL %d DAY)", $params['date_range']);
        } elseif (!empty($params['start_date']) && !empty($params['end_date'])) {
            $where_clauses[] = $wpdb->prepare("DATE(timestamp) BETWEEN %s AND %s", $params['start_date'], $params['end_date']);
        }
        
        $spam_filters = array();
        if ($params['include_spam']) $spam_filters[] = '1';
        if ($params['include_clean']) $spam_filters[] = '0';
        
        if (!empty($spam_filters)) {
            $where_clauses[] = "is_spam IN (" . implode(',', $spam_filters) . ")";
        }
        
        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        
        // Select columns based on options
        $columns = array('id', 'entry_type', 'spam_score', 'detection_method', 'timestamp', 'is_spam');
        if ($params['include_ip_data']) $columns[] = 'user_ip';
        if ($params['include_user_agents']) $columns[] = 'user_agent';
        $columns[] = 'content_preview';
        
        $select_sql = implode(', ', $columns);
        
        $data = $wpdb->get_results("SELECT {$select_sql} FROM {$this->analytics_table} {$where_sql} ORDER BY timestamp DESC", ARRAY_A);
        
        if (empty($data)) {
            return array();
        }
        
        // Create CSV header
        $csv_data = array();
        $header = array(
            'ID', 'Type', 'Spam Score', 'Detection Method', 'Timestamp', 'Status'
        );
        
        if ($params['include_ip_data']) $header[] = 'IP Address';
        if ($params['include_user_agents']) $header[] = 'User Agent';
        $header[] = 'Content Preview';
        
        $csv_data[] = $header;
        
        // Add data rows
        foreach ($data as $row) {
            $csv_row = array(
                $row['id'],
                ucfirst($row['entry_type']),
                $row['spam_score'],
                $row['detection_method'],
                $row['timestamp'],
                $row['is_spam'] ? 'Spam' : 'Clean'
            );
            
            if ($params['include_ip_data']) $csv_row[] = $row['user_ip'] ?? '';
            if ($params['include_user_agents']) $csv_row[] = $row['user_agent'] ?? '';
            $csv_row[] = $row['content_preview'];
            
            $csv_data[] = $csv_row;
        }
        
        return $csv_data;
    }
    
    /**
     * Generate PDF HTML content
     */
    private function generate_pdf_html($params) {
        global $wpdb;
        
        $site_name = get_bloginfo('name');
        $report_date = date('F j, Y');
        
        $html = '<!DOCTYPE html><html><head>';
        $html .= '<title>SpamShield Protection Report - ' . esc_html($site_name) . '</title>';
        $html .= '<style>';
        $html .= 'body { font-family: Arial, sans-serif; margin: 40px; }';
        $html .= 'h1, h2 { color: #0073aa; }';
        $html .= '.header { border-bottom: 2px solid #0073aa; padding-bottom: 20px; margin-bottom: 30px; }';
        $html .= '.stats { display: flex; justify-content: space-between; margin: 20px 0; }';
        $html .= '.stat-box { text-align: center; padding: 15px; background: #f9f9f9; border-radius: 5px; }';
        $html .= '.stat-number { font-size: 2em; font-weight: bold; color: #0073aa; }';
        $html .= 'table { width: 100%; border-collapse: collapse; margin: 20px 0; }';
        $html .= 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
        $html .= 'th { background-color: #f2f2f2; }';
        $html .= '.spam { color: #dc3232; font-weight: bold; }';
        $html .= '.clean { color: #46b450; font-weight: bold; }';
        $html .= '</style>';
        $html .= '</head><body>';
        
        $html .= '<div class="header">';
        $html .= '<h1>SpamShield Protection Report</h1>';
        $html .= '<p><strong>' . esc_html($site_name) . '</strong> - Generated on ' . $report_date . '</p>';
        $html .= '</div>';
        
        // Add statistics summary
        $stats = $this->get_report_statistics($params);
        $html .= '<h2>Protection Summary</h2>';
        $html .= '<div class="stats">';
        $html .= '<div class="stat-box"><div class="stat-number">' . number_format($stats['spam_blocked']) . '</div><div>Spam Blocked</div></div>';
        $html .= '<div class="stat-box"><div class="stat-number">' . number_format($stats['clean_submissions']) . '</div><div>Clean Submissions</div></div>';
        $html .= '<div class="stat-box"><div class="stat-number">' . number_format($stats['protection_rate'], 1) . '%</div><div>Protection Rate</div></div>';
        $html .= '</div>';
        
        $html .= '</body></html>';
        
        return $html;
    }
    
    /**
     * Get report statistics
     */
    private function get_report_statistics($params) {
        global $wpdb;
        
        $where_clause = "WHERE timestamp > DATE_SUB(NOW(), INTERVAL {$params['date_range']} DAY)";
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_blocked,
                SUM(CASE WHEN is_spam = 0 THEN 1 ELSE 0 END) as clean_submissions
            FROM {$this->analytics_table} 
            {$where_clause}
        ");
        
        $protection_rate = $stats->total > 0 ? ($stats->spam_blocked / $stats->total) * 100 : 0;
        
        return array(
            'total' => intval($stats->total),
            'spam_blocked' => intval($stats->spam_blocked),
            'clean_submissions' => intval($stats->clean_submissions),
            'protection_rate' => floatval($protection_rate)
        );
    }
    
    /**
     * Save export to history
     */
    private function save_export_history($format, $params, $record_count, $file_path) {
        $history = get_option('sscf_export_history', array());
        
        $date_range_text = $params['date_range'] . ' days';
        if (!empty($params['start_date']) && !empty($params['end_date'])) {
            $date_range_text = $params['start_date'] . ' to ' . $params['end_date'];
        }
        
        $history[] = array(
            'created' => current_time('Y-m-d H:i:s'),
            'format' => $format,
            'date_range' => $date_range_text,
            'record_count' => $record_count,
            'file_path' => $file_path
        );
        
        // Keep only last 50 exports
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }
        
        update_option('sscf_export_history', $history);
    }
    
    /**
     * Send weekly report
     */
    public function send_weekly_report() {
        $options = get_option('sscf_options', array());
        
        if (empty($options['weekly_reports_enabled'])) {
            return;
        }
        
        $stats = $this->get_report_statistics(array('date_range' => 7));
        $recipients = explode(',', $options['report_recipients'] ?? get_option('admin_email'));
        
        $subject = sprintf(__('[%s] Weekly SpamShield Report', 'spamshield-cf'), get_bloginfo('name'));
        $message = $this->generate_email_report($stats, 'weekly');
        
        foreach ($recipients as $recipient) {
            wp_mail(trim($recipient), $subject, $message);
        }
    }
    
    /**
     * Send monthly report
     */
    public function send_monthly_report() {
        $options = get_option('sscf_options', array());
        
        if (empty($options['monthly_reports_enabled'])) {
            return;
        }
        
        $stats = $this->get_report_statistics(array('date_range' => 30));
        $recipients = explode(',', $options['report_recipients'] ?? get_option('admin_email'));
        
        $subject = sprintf(__('[%s] Monthly SpamShield Report', 'spamshield-cf'), get_bloginfo('name'));
        $message = $this->generate_email_report($stats, 'monthly');
        
        foreach ($recipients as $recipient) {
            wp_mail(trim($recipient), $subject, $message);
        }
    }
    
    /**
     * Generate email report content
     */
    private function generate_email_report($stats, $period = 'weekly') {
        $site_name = get_bloginfo('name');
        $period_text = ucfirst($period);
        
        $message = sprintf(__("%s SpamShield Protection Report\n\n", 'spamshield-cf'), $period_text);
        $message .= sprintf(__("Site: %s\n", 'spamshield-cf'), $site_name);
        $message .= sprintf(__("Report Period: Last %s\n\n", 'spamshield-cf'), $period === 'weekly' ? '7 days' : '30 days');
        
        $message .= __("PROTECTION SUMMARY\n", 'spamshield-cf');
        $message .= str_repeat('=', 50) . "\n";
        $message .= sprintf(__("Spam Blocked: %s\n", 'spamshield-cf'), number_format($stats['spam_blocked']));
        $message .= sprintf(__("Clean Submissions: %s\n", 'spamshield-cf'), number_format($stats['clean_submissions']));
        $message .= sprintf(__("Protection Rate: %.1f%%\n", 'spamshield-cf'), $stats['protection_rate']);
        $message .= sprintf(__("Total Events: %s\n\n", 'spamshield-cf'), number_format($stats['total']));
        
        $message .= __("Your website is protected by SpamShield!\n\n", 'spamshield-cf');
        $message .= sprintf(__("View detailed analytics: %s\n", 'spamshield-cf'), admin_url('admin.php?page=spamshield-analytics'));
        
        return $message;
    }
}
