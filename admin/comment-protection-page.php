<?php
/**
 * Comment Protection Admin Page
 * Displays spam comment queue and bulk management
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Comment_Protection_Admin {
    
    private $analytics_table;
    
    public function __construct() {
        global $wpdb;
        $this->analytics_table = $wpdb->prefix . 'sscf_comment_analytics';
        
        // Handle bulk actions
        if (isset($_POST['action']) && isset($_POST['comment_ids'])) {
            $this->handle_bulk_action();
        }
        
        // Handle individual actions
        if (isset($_GET['action']) && isset($_GET['id'])) {
            $this->handle_single_action();
        }
    }
    
    /**
     * Main admin page display
     */
    public function display_page() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Comment Protection Dashboard', 'spamshield-cf') . '</h1>';
        
        $this->display_summary_stats();
        $this->display_spam_queue();
        
        echo '</div>';
    }
    
    /**
     * Display summary statistics
     */
    private function display_summary_stats() {
        global $wpdb;
        
        // Get stats for different time periods
        $stats_today = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_blocked,
                SUM(CASE WHEN is_spam = 0 THEN 1 ELSE 0 END) as legitimate
             FROM {$this->analytics_table} 
             WHERE entry_type = 'comment' 
             AND DATE(timestamp) = CURDATE()"
        );
        
        $stats_week = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_blocked,
                SUM(CASE WHEN is_spam = 0 THEN 1 ELSE 0 END) as legitimate
             FROM {$this->analytics_table} 
             WHERE entry_type = 'comment' 
             AND timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        $stats_month = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_blocked,
                SUM(CASE WHEN is_spam = 0 THEN 1 ELSE 0 END) as legitimate
             FROM {$this->analytics_table} 
             WHERE entry_type = 'comment' 
             AND timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        echo '<div class="sscf-dashboard-stats">';
        echo '<style>
        .sscf-dashboard-stats { display: flex; gap: 20px; margin: 20px 0; }
        .sscf-stat-card { 
            background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; 
            flex: 1; text-align: center; box-shadow: 0 1px 1px rgba(0,0,0,0.04);
        }
        .sscf-stat-number { font-size: 2em; font-weight: bold; color: #0073aa; margin-bottom: 5px; }
        .sscf-stat-label { color: #666; font-size: 14px; }
        .sscf-stat-spam { color: #dc3232; }
        .sscf-stat-clean { color: #46b450; }
        </style>';
        
        // Today
        echo '<div class="sscf-stat-card">';
        echo '<div class="sscf-stat-number">' . number_format($stats_today->spam_blocked ?: 0) . '</div>';
        echo '<div class="sscf-stat-label sscf-stat-spam">' . __('Spam Blocked Today', 'spamshield-cf') . '</div>';
        echo '</div>';
        
        // This Week
        echo '<div class="sscf-stat-card">';
        echo '<div class="sscf-stat-number">' . number_format($stats_week->spam_blocked ?: 0) . '</div>';
        echo '<div class="sscf-stat-label sscf-stat-spam">' . __('Spam Blocked (7 Days)', 'spamshield-cf') . '</div>';
        echo '</div>';
        
        // This Month
        echo '<div class="sscf-stat-card">';
        echo '<div class="sscf-stat-number">' . number_format($stats_month->legitimate ?: 0) . '</div>';
        echo '<div class="sscf-stat-label sscf-stat-clean">' . __('Clean Comments (30 Days)', 'spamshield-cf') . '</div>';
        echo '</div>';
        
        // Protection Rate
        if ($stats_month && $stats_month->total > 0) {
            $protection_rate = round(($stats_month->spam_blocked / $stats_month->total) * 100, 1);
            echo '<div class="sscf-stat-card">';
            echo '<div class="sscf-stat-number">' . $protection_rate . '%</div>';
            echo '<div class="sscf-stat-label">' . __('Protection Rate (30 Days)', 'spamshield-cf') . '</div>';
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Display spam comment queue
     */
    private function display_spam_queue() {
        global $wpdb;
        
        // Pagination
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Filter options
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'spam';
        $date_filter = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : '';
        
        // Build query
        $where_clauses = array("entry_type = 'comment'");
        
        if ($filter === 'spam') {
            $where_clauses[] = "is_spam = 1";
        } elseif ($filter === 'clean') {
            $where_clauses[] = "is_spam = 0";
        }
        
        if ($date_filter) {
            switch ($date_filter) {
                case 'today':
                    $where_clauses[] = "DATE(timestamp) = CURDATE()";
                    break;
                case 'week':
                    $where_clauses[] = "timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    break;
                case 'month':
                    $where_clauses[] = "timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    break;
            }
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Get total count
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$this->analytics_table} WHERE {$where_sql}");
        
        // Get items for current page
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->analytics_table} 
             WHERE {$where_sql} 
             ORDER BY timestamp DESC 
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        // Display filters
        echo '<div class="sscf-filters" style="background: #fff; padding: 10px; border: 1px solid #ccd0d4; margin-bottom: 20px;">';
        echo '<form method="get" style="display: flex; gap: 10px; align-items: center;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '">';
        
        // Filter by type
        echo '<select name="filter">';
        echo '<option value="all"' . selected('all', $filter, false) . '>' . __('All Comments', 'spamshield-cf') . '</option>';
        echo '<option value="spam"' . selected('spam', $filter, false) . '>' . __('Spam Only', 'spamshield-cf') . '</option>';
        echo '<option value="clean"' . selected('clean', $filter, false) . '>' . __('Clean Only', 'spamshield-cf') . '</option>';
        echo '</select>';
        
        // Filter by date
        echo '<select name="date_filter">';
        echo '<option value=""' . selected('', $date_filter, false) . '>' . __('All Time', 'spamshield-cf') . '</option>';
        echo '<option value="today"' . selected('today', $date_filter, false) . '>' . __('Today', 'spamshield-cf') . '</option>';
        echo '<option value="week"' . selected('week', $date_filter, false) . '>' . __('Last 7 Days', 'spamshield-cf') . '</option>';
        echo '<option value="month"' . selected('month', $date_filter, false) . '>' . __('Last 30 Days', 'spamshield-cf') . '</option>';
        echo '</select>';
        
        submit_button(__('Filter', 'spamshield-cf'), 'secondary', 'filter_submit', false, array('style' => 'margin: 0;'));
        echo '</form>';
        echo '</div>';
        
        // Bulk actions form
        echo '<form method="post" id="sscf-comments-form">';
        wp_nonce_field('sscf_bulk_comment_action');
        
        // Bulk actions dropdown
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions bulkactions">';
        echo '<select name="action">';
        echo '<option value="">' . __('Bulk Actions', 'spamshield-cf') . '</option>';
        echo '<option value="delete">' . __('Delete', 'spamshield-cf') . '</option>';
        echo '<option value="mark_spam">' . __('Mark as Spam', 'spamshield-cf') . '</option>';
        echo '<option value="mark_clean">' . __('Mark as Clean', 'spamshield-cf') . '</option>';
        echo '</select>';
        submit_button(__('Apply', 'spamshield-cf'), 'action', '', false, array('id' => 'doaction'));
        echo '</div>';
        
        // Pagination
        if ($total_items > $per_page) {
            $total_pages = ceil($total_items / $per_page);
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . sprintf(_n('%s item', '%s items', $total_items, 'spamshield-cf'), number_format_i18n($total_items)) . '</span>';
            
            if ($total_pages > 1) {
                $page_links = paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $current_page
                ));
                echo '<span class="pagination-links">' . $page_links . '</span>';
            }
            echo '</div>';
        }
        echo '</div>';
        
        // Comments table
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></td>';
        echo '<th class="manage-column">' . __('Content', 'spamshield-cf') . '</th>';
        echo '<th class="manage-column">' . __('Status', 'spamshield-cf') . '</th>';
        echo '<th class="manage-column">' . __('Spam Score', 'spamshield-cf') . '</th>';
        echo '<th class="manage-column">' . __('Detection Method', 'spamshield-cf') . '</th>';
        echo '<th class="manage-column">' . __('IP Address', 'spamshield-cf') . '</th>';
        echo '<th class="manage-column">' . __('Post', 'spamshield-cf') . '</th>';
        echo '<th class="manage-column">' . __('Date', 'spamshield-cf') . '</th>';
        echo '<th class="manage-column">' . __('Actions', 'spamshield-cf') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        if (empty($items)) {
            echo '<tr><td colspan="8">' . __('No comments found.', 'spamshield-cf') . '</td></tr>';
        } else {
            foreach ($items as $item) {
                echo '<tr>';
                echo '<th scope="row" class="check-column"><input type="checkbox" name="comment_ids[]" value="' . esc_attr($item->id) . '"></th>';
                
                // Content preview
                echo '<td>';
                echo '<strong>' . esc_html(substr($item->content_preview, 0, 60)) . '</strong>';
                if (strlen($item->content_preview) > 60) {
                    echo '...';
                }
                echo '<br><small>User Agent: ' . esc_html(substr($item->user_agent, 0, 50)) . '</small>';
                echo '</td>';
                
                // Status
                echo '<td>';
                if ($item->is_spam) {
                    echo '<span style="color: #dc3232; font-weight: bold;">' . __('SPAM', 'spamshield-cf') . '</span>';
                } else {
                    echo '<span style="color: #46b450; font-weight: bold;">' . __('CLEAN', 'spamshield-cf') . '</span>';
                }
                echo '</td>';
                
                // Spam score
                echo '<td><strong>' . intval($item->spam_score) . '</strong></td>';
                
                // Detection method
                echo '<td>' . esc_html($item->detection_method) . '</td>';
                
                // IP Address
                echo '<td><code>' . esc_html($item->user_ip) . '</code></td>';
                
                // Post link
                echo '<td>';
                if ($item->post_id) {
                    $post = get_post($item->post_id);
                    if ($post) {
                        echo '<a href="' . get_permalink($item->post_id) . '" target="_blank">' . esc_html(get_the_title($item->post_id)) . '</a>';
                    } else {
                        echo __('Post not found', 'spamshield-cf');
                    }
                } else {
                    echo '-';
                }
                echo '</td>';
                
                // Date
                echo '<td>' . esc_html(mysql2date('Y/m/d g:i:s A', $item->timestamp)) . '</td>';
                
                // Actions
                echo '<td>';
                $base_url = admin_url('admin.php?page=sscf-comment-protection');
                echo '<a href="' . wp_nonce_url($base_url . '&action=delete&id=' . $item->id, 'sscf_single_action') . '" class="button button-small" onclick="return confirm(\'' . __('Are you sure you want to delete this entry?', 'spamshield-cf') . '\')">' . __('Delete', 'spamshield-cf') . '</a>';
                echo '</td>';
                
                echo '</tr>';
            }
        }
        
        echo '</tbody>';
        echo '</table>';
        
        echo '</form>';
        
        // JavaScript for select all functionality
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const selectAll = document.getElementById("cb-select-all-1");
            if (selectAll) {
                selectAll.addEventListener("change", function() {
                    const checkboxes = document.querySelectorAll("input[name=\'comment_ids[]\']");
                    checkboxes.forEach(cb => cb.checked = this.checked);
                });
            }
        });
        </script>';
    }
    
    /**
     * Handle bulk actions
     */
    private function handle_bulk_action() {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'sscf_bulk_comment_action')) {
            wp_die(__('Security check failed.', 'spamshield-cf'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'spamshield-cf'));
        }
        
        $action = sanitize_text_field($_POST['action']);
        $comment_ids = array_map('intval', $_POST['comment_ids']);
        
        if (empty($comment_ids) || empty($action)) {
            return;
        }
        
        global $wpdb;
        
        switch ($action) {
            case 'delete':
                $placeholders = implode(',', array_fill(0, count($comment_ids), '%d'));
                $table_name = esc_sql($this->analytics_table);
                $query = $wpdb->prepare("DELETE FROM `{$table_name}` WHERE id IN ({$placeholders})", $comment_ids);
                $deleted = $wpdb->query($query);
                
                echo '<div class="notice notice-success"><p>' . sprintf(__('%d entries deleted successfully.', 'spamshield-cf'), $deleted) . '</p></div>';
                break;
                
            case 'mark_spam':
                $placeholders = implode(',', array_fill(0, count($comment_ids), '%d'));
                $table_name = esc_sql($this->analytics_table);
                $query = $wpdb->prepare("UPDATE `{$table_name}` SET is_spam = 1 WHERE id IN ({$placeholders})", $comment_ids);
                $updated = $wpdb->query($query);
                
                echo '<div class="notice notice-success"><p>' . sprintf(__('%d entries marked as spam.', 'spamshield-cf'), $updated) . '</p></div>';
                break;
                
            case 'mark_clean':
                $placeholders = implode(',', array_fill(0, count($comment_ids), '%d'));
                $table_name = esc_sql($this->analytics_table);
                $query = $wpdb->prepare("UPDATE `{$table_name}` SET is_spam = 0 WHERE id IN ({$placeholders})", $comment_ids);
                $updated = $wpdb->query($query);
                
                echo '<div class="notice notice-success"><p>' . sprintf(__('%d entries marked as clean.', 'spamshield-cf'), $updated) . '</p></div>';
                break;
        }
    }
    
    /**
     * Handle single item actions
     */
    private function handle_single_action() {
        if (!wp_verify_nonce($_GET['_wpnonce'], 'sscf_single_action')) {
            wp_die(__('Security check failed.', 'spamshield-cf'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'spamshield-cf'));
        }
        
        $action = sanitize_text_field($_GET['action']);
        $id = intval($_GET['id']);
        
        global $wpdb;
        
        if ($action === 'delete' && $id > 0) {
            $deleted = $wpdb->delete($this->analytics_table, array('id' => $id), array('%d'));
            
            if ($deleted) {
                echo '<div class="notice notice-success"><p>' . __('Entry deleted successfully.', 'spamshield-cf') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . __('Failed to delete entry.', 'spamshield-cf') . '</p></div>';
            }
        }
    }
}

// Initialize and display the page
$admin_page = new SSCF_Comment_Protection_Admin();
$admin_page->display_page();
