<?php
/**
 * Form Entries Viewer Page
 * Display and manage form submissions
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle entry actions
if (isset($_POST['action']) && wp_verify_nonce($_POST['entries_nonce'], 'sscf_entries_action')) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sscf_entries';
    
    switch ($_POST['action']) {
        case 'delete_entry':
            if (isset($_POST['entry_id'])) {
                $entry_id = intval($_POST['entry_id']);
                $wpdb->delete($table_name, array('id' => $entry_id), array('%d'));
                echo '<div class="notice notice-success"><p>' . __('Entry deleted successfully.', 'spamshield-cf') . '</p></div>';
            }
            break;
            
        case 'mark_spam':
            if (isset($_POST['entry_id'])) {
                $entry_id = intval($_POST['entry_id']);
                $wpdb->update($table_name, array('status' => 'spam'), array('id' => $entry_id), array('%s'), array('%d'));
                
                // Learn from this spam entry for AI training
                $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $entry_id));
                if ($entry && class_exists('SSCF_AI_Detection_Engine')) {
                    $ai_engine = new SSCF_AI_Detection_Engine();
                    if ($ai_engine->is_configured()) {
                        $entry_data = json_decode($entry->entry_data, true);
                        $ai_engine->learn_from_analysis(
                            array(
                                'content' => isset($entry_data['message']) ? $entry_data['message'] : '',
                                'email' => isset($entry_data['email']) ? $entry_data['email'] : '',
                                'ip' => $entry->user_ip
                            ),
                            array('is_spam' => true, 'confidence' => 95, 'verified' => true)
                        );
                    }
                }
                
                echo '<div class="notice notice-success"><p>' . __('Entry marked as spam and AI model updated.', 'spamshield-cf') . '</p></div>';
            }
            break;
            
        case 'mark_not_spam':
            if (isset($_POST['entry_id'])) {
                $entry_id = intval($_POST['entry_id']);
                $wpdb->update($table_name, array('status' => 'submitted'), array('id' => $entry_id), array('%s'), array('%d'));
                echo '<div class="notice notice-success"><p>' . __('Entry marked as not spam.', 'spamshield-cf') . '</p></div>';
            }
            break;
            
        case 'bulk_action':
            if (isset($_POST['bulk_action_type']) && isset($_POST['entry_ids'])) {
                $action_type = sanitize_text_field($_POST['bulk_action_type']);
                $entry_ids = array_map('intval', $_POST['entry_ids']);
                
                if (!empty($entry_ids)) {
                    switch ($action_type) {
                        case 'delete':
                            foreach ($entry_ids as $id) {
                                $wpdb->delete($table_name, array('id' => $id), array('%d'));
                            }
                            echo '<div class="notice notice-success"><p>' . sprintf(__('%d entries deleted.', 'spamshield-cf'), count($entry_ids)) . '</p></div>';
                            break;
                        case 'mark_spam':
                            foreach ($entry_ids as $id) {
                                $wpdb->update($table_name, array('status' => 'spam'), array('id' => $id), array('%s'), array('%d'));
                            }
                            echo '<div class="notice notice-success"><p>' . sprintf(__('%d entries marked as spam.', 'spamshield-cf'), count($entry_ids)) . '</p></div>';
                            break;
                        case 'mark_not_spam':
                            foreach ($entry_ids as $id) {
                                $wpdb->update($table_name, array('status' => 'submitted'), array('id' => $id), array('%s'), array('%d'));
                            }
                            echo '<div class="notice notice-success"><p>' . sprintf(__('%d entries marked as not spam.', 'spamshield-cf'), count($entry_ids)) . '</p></div>';
                            break;
                    }
                }
            }
            break;
            
        case 'export_csv':
            export_entries_csv();
            break;
    }
}

// Handle CSV export
function export_entries_csv() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sscf_entries';
    
    $entries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    
    if (empty($entries)) {
        echo '<div class="notice notice-error"><p>' . __('No entries found to export.', 'spamshield-cf') . '</p></div>';
        return;
    }
    
    // Prepare CSV content
    $filename = 'spamshield-entries-' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    $headers = array('ID', 'Status', 'IP Address', 'Created At');
    
    // Get all unique field names from entries
    $all_fields = array();
    foreach ($entries as $entry) {
        $entry_data = json_decode($entry->entry_data, true);
        if ($entry_data) {
            $all_fields = array_merge($all_fields, array_keys($entry_data));
        }
    }
    $all_fields = array_unique($all_fields);
    $headers = array_merge($headers, $all_fields);
    
    fputcsv($output, $headers);
    
    // CSV data
    foreach ($entries as $entry) {
        $entry_data = json_decode($entry->entry_data, true);
        $row = array(
            $entry->id,
            $entry->status,
            $entry->user_ip,
            $entry->created_at
        );
        
        foreach ($all_fields as $field) {
            $row[] = isset($entry_data[$field]) ? $entry_data[$field] : '';
        }
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

// Get entries from database
global $wpdb;
$table_name = $wpdb->prefix . 'sscf_entries';

// Filter parameters
$filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$date_filter = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : '';

// Build WHERE clause
$where_clauses = array();
$where_values = array();

if ($filter_status) {
    $where_clauses[] = "status = %s";
    $where_values[] = $filter_status;
}

if ($search_query) {
    $where_clauses[] = "(entry_data LIKE %s OR user_ip LIKE %s)";
    $where_values[] = '%' . $wpdb->esc_like($search_query) . '%';
    $where_values[] = '%' . $wpdb->esc_like($search_query) . '%';
}

if ($date_filter) {
    switch ($date_filter) {
        case 'today':
            $where_clauses[] = "DATE(created_at) = CURDATE()";
            break;
        case 'yesterday':
            $where_clauses[] = "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'week':
            $where_clauses[] = "created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'month':
            $where_clauses[] = "created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
    }
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Pagination
$page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Total entries with filters
$count_query = "SELECT COUNT(*) FROM $table_name $where_sql";
if (!empty($where_values)) {
    $count_query = $wpdb->prepare($count_query, $where_values);
}
$total_entries = $wpdb->get_var($count_query);
$total_pages = ceil($total_entries / $per_page);

// Get entries with pagination and filters
$query = "SELECT * FROM $table_name $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
$query_values = array_merge($where_values, array($per_page, $offset));
$entries = $wpdb->get_results($wpdb->prepare($query, $query_values));

// Get statistics
$stats = array(
    'total' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name"),
    'submitted' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'submitted'"),
    'spam' => $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'spam'"),
    'today' => $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE DATE(created_at) = %s", date('Y-m-d')))
);
?>

<div class="wrap">
    <h1>
        <?php _e('Form Entries', 'spamshield-cf'); ?>
        <a href="#" class="page-title-action" id="sscf-refresh-entries"><?php _e('Refresh', 'spamshield-cf'); ?></a>
    </h1>
    
    <!-- Filters Bar -->
    <div class="sscf-filters-bar">
        <form method="get" action="">
            <input type="hidden" name="page" value="sscf-entries" />
            
            <select name="status" id="filter-status">
                <option value=""><?php _e('All Statuses', 'spamshield-cf'); ?></option>
                <option value="submitted" <?php selected($filter_status, 'submitted'); ?>><?php _e('Submitted', 'spamshield-cf'); ?></option>
                <option value="spam" <?php selected($filter_status, 'spam'); ?>><?php _e('Spam', 'spamshield-cf'); ?></option>
                <option value="read" <?php selected($filter_status, 'read'); ?>><?php _e('Read', 'spamshield-cf'); ?></option>
            </select>
            
            <select name="date" id="filter-date">
                <option value=""><?php _e('All Dates', 'spamshield-cf'); ?></option>
                <option value="today" <?php selected($date_filter, 'today'); ?>><?php _e('Today', 'spamshield-cf'); ?></option>
                <option value="yesterday" <?php selected($date_filter, 'yesterday'); ?>><?php _e('Yesterday', 'spamshield-cf'); ?></option>
                <option value="week" <?php selected($date_filter, 'week'); ?>><?php _e('Last 7 Days', 'spamshield-cf'); ?></option>
                <option value="month" <?php selected($date_filter, 'month'); ?>><?php _e('Last 30 Days', 'spamshield-cf'); ?></option>
            </select>
            
            <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php _e('Search entries...', 'spamshield-cf'); ?>" />
            
            <input type="submit" class="button" value="<?php _e('Filter', 'spamshield-cf'); ?>" />
            
            <?php if ($filter_status || $search_query || $date_filter): ?>
                <a href="?page=sscf-entries" class="button"><?php _e('Clear Filters', 'spamshield-cf'); ?></a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Statistics Cards -->
    <div class="sscf-stats-row">
        <div class="sscf-stat-card">
            <div class="sscf-stat-number"><?php echo esc_html($stats['total']); ?></div>
            <div class="sscf-stat-label"><?php _e('Total Entries', 'spamshield-cf'); ?></div>
        </div>
        <div class="sscf-stat-card">
            <div class="sscf-stat-number"><?php echo esc_html($stats['submitted']); ?></div>
            <div class="sscf-stat-label"><?php _e('Valid Submissions', 'spamshield-cf'); ?></div>
        </div>
        <div class="sscf-stat-card">
            <div class="sscf-stat-number"><?php echo esc_html($stats['spam']); ?></div>
            <div class="sscf-stat-label"><?php _e('Spam Blocked', 'spamshield-cf'); ?></div>
        </div>
        <div class="sscf-stat-card">
            <div class="sscf-stat-number"><?php echo esc_html($stats['today']); ?></div>
            <div class="sscf-stat-label"><?php _e('Today', 'spamshield-cf'); ?></div>
        </div>
    </div>
    
    <!-- Filters and Search -->
    <div class="sscf-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="spamshield-entries" />
            
            <div class="sscf-filter-row">
                <select name="status">
                    <option value=""><?php _e('All Statuses', 'spamshield-cf'); ?></option>
                    <option value="submitted" <?php selected($filter_status, 'submitted'); ?>><?php _e('Valid Submissions', 'spamshield-cf'); ?></option>
                    <option value="spam" <?php selected($filter_status, 'spam'); ?>><?php _e('Spam', 'spamshield-cf'); ?></option>
                </select>
                
                <input type="text" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php _e('Search entries...', 'spamshield-cf'); ?>" />
                
                <button type="submit" class="button"><?php _e('Filter', 'spamshield-cf'); ?></button>
            </div>
        </form>
        
        <!-- Export CSV form (separate, not nested) -->
        <form method="post" style="display: inline-block; margin-left: 10px;">
            <?php wp_nonce_field('sscf_entries_action', 'entries_nonce'); ?>
            <input type="hidden" name="action" value="export_csv" />
            <button type="submit" class="button button-secondary"><?php _e('Export CSV', 'spamshield-cf'); ?></button>
        </form>
    </div>
    
    <!-- Entries Table -->
    <?php if (empty($entries)): ?>
        <div class="sscf-no-entries">
            <h3><?php _e('No entries found', 'spamshield-cf'); ?></h3>
            <p><?php _e('Form submissions will appear here once users start submitting your contact form.', 'spamshield-cf'); ?></p>
        </div>
    <?php else: ?>
        <!-- Bulk Actions Bar -->
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select id="bulk-action-selector">
                    <option value=""><?php _e('Bulk Actions', 'spamshield-cf'); ?></option>
                    <option value="delete"><?php _e('Delete', 'spamshield-cf'); ?></option>
                    <option value="mark_spam"><?php _e('Mark as Spam', 'spamshield-cf'); ?></option>
                    <option value="mark_not_spam"><?php _e('Mark as Not Spam', 'spamshield-cf'); ?></option>
                </select>
                <button class="button action" id="do-bulk-action"><?php _e('Apply', 'spamshield-cf'); ?></button>
            </div>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="check-column">
                        <input type="checkbox" id="select-all-entries" />
                    </td>
                    <th><?php _e('ID', 'spamshield-cf'); ?></th>
                    <th><?php _e('Status', 'spamshield-cf'); ?></th>
                    <th><?php _e('Entry Data', 'spamshield-cf'); ?></th>
                    <th><?php _e('IP Address', 'spamshield-cf'); ?></th>
                    <th><?php _e('Date', 'spamshield-cf'); ?></th>
                    <th><?php _e('Actions', 'spamshield-cf'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php $entry_data = json_decode($entry->entry_data, true); ?>
                    <tr class="<?php echo $entry->status === 'spam' ? 'sscf-spam-entry' : ''; ?>">
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="entry_checkbox[]" value="<?php echo esc_attr($entry->id); ?>" />
                        </th>
                        <td><strong>#<?php echo esc_html($entry->id); ?></strong></td>
                        <td>
                            <span class="sscf-status sscf-status-<?php echo esc_attr($entry->status); ?>">
                                <?php echo esc_html(ucfirst($entry->status)); ?>
                            </span>
                        </td>
                        <td>
                            <div class="sscf-entry-preview">
                                <?php if ($entry_data): ?>
                                    <?php foreach ($entry_data as $label => $value): ?>
                                        <?php if (!empty($value)): ?>
                                            <div class="sscf-field-preview">
                                                <strong><?php echo esc_html($label); ?>:</strong>
                                                <span><?php echo esc_html(mb_strlen($value) > 50 ? mb_substr($value, 0, 50) . '...' : $value); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?php echo esc_html($entry->user_ip); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at))); ?></td>
                        <td>
                            <div class="sscf-entry-actions">
                                <button type="button" class="button button-small sscf-view-entry" data-entry-id="<?php echo esc_attr($entry->id); ?>">
                                    <?php _e('View', 'spamshield-cf'); ?>
                                </button>
                                
                                <?php if ($entry->status === 'spam'): ?>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('sscf_entries_action', 'entries_nonce'); ?>
                                        <input type="hidden" name="action" value="mark_not_spam" />
                                        <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry->id); ?>" />
                                        <button type="submit" class="button button-small"><?php _e('Not Spam', 'spamshield-cf'); ?></button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display: inline;">
                                        <?php wp_nonce_field('sscf_entries_action', 'entries_nonce'); ?>
                                        <input type="hidden" name="action" value="mark_spam" />
                                        <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry->id); ?>" />
                                        <button type="submit" class="button button-small"><?php _e('Mark Spam', 'spamshield-cf'); ?></button>
                                    </form>
                                <?php endif; ?>
                                
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('sscf_entries_action', 'entries_nonce'); ?>
                                    <input type="hidden" name="action" value="delete_entry" />
                                    <input type="hidden" name="entry_id" value="<?php echo esc_attr($entry->id); ?>" />
                                    <button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php _e('Are you sure you want to delete this entry?', 'spamshield-cf'); ?>')">
                                        <?php _e('Delete', 'spamshield-cf'); ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Hidden row with full entry details -->
                    <tr class="sscf-entry-details" id="sscf-entry-<?php echo esc_attr($entry->id); ?>" style="display: none;">
                        <td colspan="6">
                            <div class="sscf-entry-full">
                                <h4><?php _e('Full Entry Details', 'spamshield-cf'); ?></h4>
                                <?php if ($entry_data): ?>
                                    <table class="sscf-entry-table">
                                        <?php foreach ($entry_data as $label => $value): ?>
                                            <tr>
                                                <td><strong><?php echo esc_html($label); ?></strong></td>
                                                <td><?php echo nl2br(esc_html($value)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php endif; ?>
                                <div class="sscf-entry-meta">
                                    <p><strong><?php _e('IP Address:', 'spamshield-cf'); ?></strong> <?php echo esc_html($entry->user_ip); ?></p>
                                    <p><strong><?php _e('User Agent:', 'spamshield-cf'); ?></strong> <?php echo esc_html($entry->user_agent); ?></p>
                                    <p><strong><?php _e('Spam Score:', 'spamshield-cf'); ?></strong> <?php echo esc_html($entry->spam_score); ?></p>
                                    <p><strong><?php _e('Submitted:', 'spamshield-cf'); ?></strong> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry->created_at))); ?></p>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    $page_links = paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo; Previous'),
                        'next_text' => __('Next &raquo;'),
                        'total' => $total_pages,
                        'current' => $page
                    ));
                    
                    if ($page_links) {
                        echo $page_links;
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- CSS Styles -->
<style>
.sscf-stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.sscf-stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.sscf-stat-number {
    font-size: 32px;
    font-weight: bold;
    color: #2271b1;
    margin-bottom: 5px;
}

.sscf-stat-label {
    font-size: 14px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sscf-filters {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin: 20px 0;
}

.sscf-filter-row {
    display: flex;
    gap: 10px;
    align-items: center;
}

.sscf-filter-row input[type="text"] {
    min-width: 200px;
}

.sscf-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.sscf-status-submitted {
    background: #d1f2eb;
    color: #0e5f3a;
}

.sscf-status-spam {
    background: #fdeaea;
    color: #c53030;
}

.sscf-entry-preview {
    max-width: 300px;
}

.sscf-field-preview {
    margin-bottom: 5px;
    font-size: 13px;
}

.sscf-field-preview strong {
    color: #333;
}

.sscf-entry-actions {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.sscf-entry-actions .button {
    margin: 0;
}

.sscf-spam-entry {
    background-color: #fdf2f2;
}

.sscf-entry-full {
    background: #f9f9f9;
    border-radius: 4px;
    padding: 20px;
    margin: 10px 0;
}

.sscf-entry-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.sscf-entry-table td {
    padding: 10px;
    border-bottom: 1px solid #ddd;
    vertical-align: top;
}

.sscf-entry-table td:first-child {
    width: 150px;
    background: #f0f0f0;
}

.sscf-entry-meta {
    background: #fff;
    border-radius: 4px;
    padding: 15px;
    border: 1px solid #ddd;
}

.sscf-entry-meta p {
    margin: 5px 0;
}

.sscf-no-entries {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.sscf-no-entries h3 {
    color: #666;
    margin-bottom: 10px;
}
</style>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle entry details
    document.querySelectorAll('.sscf-view-entry').forEach(function(button) {
        button.addEventListener('click', function() {
            const entryId = this.getAttribute('data-entry-id');
            const detailsRow = document.getElementById('sscf-entry-' + entryId);
            
            if (detailsRow.style.display === 'none') {
                detailsRow.style.display = 'table-row';
                this.textContent = '<?php echo esc_js(__('Hide', 'spamshield-cf')); ?>';
            } else {
                detailsRow.style.display = 'none';
                this.textContent = '<?php echo esc_js(__('View', 'spamshield-cf')); ?>';
            }
        });
    });
    
    // Refresh button
    const refreshButton = document.getElementById('sscf-refresh-entries');
    if (refreshButton) {
        refreshButton.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.reload();
        });
    }
    
    // Bulk actions
    const bulkActionButton = document.getElementById('do-bulk-action');
    if (bulkActionButton) {
        bulkActionButton.addEventListener('click', function() {
            const action = document.getElementById('bulk-action-selector').value;
            if (!action) {
                alert('<?php echo esc_js(__('Please select a bulk action', 'spamshield-cf')); ?>');
                return;
            }
            
            const checkboxes = document.querySelectorAll('input[name="entry_checkbox[]"]:checked');
            if (checkboxes.length === 0) {
                alert('<?php echo esc_js(__('Please select at least one entry', 'spamshield-cf')); ?>');
                return;
            }
            
            let confirmMsg = '';
            switch(action) {
                case 'delete':
                    confirmMsg = '<?php echo esc_js(__('Are you sure you want to delete the selected entries?', 'spamshield-cf')); ?>';
                    break;
                case 'mark_spam':
                    confirmMsg = '<?php echo esc_js(__('Mark selected entries as spam?', 'spamshield-cf')); ?>';
                    break;
                case 'mark_not_spam':
                    confirmMsg = '<?php echo esc_js(__('Mark selected entries as not spam?', 'spamshield-cf')); ?>';
                    break;
            }
            
            if (confirm(confirmMsg)) {
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                // Add nonce
                const nonceField = document.createElement('input');
                nonceField.type = 'hidden';
                nonceField.name = 'entries_nonce';
                nonceField.value = '<?php echo wp_create_nonce('sscf_entries_action'); ?>';
                form.appendChild(nonceField);
                
                // Add action
                const actionField = document.createElement('input');
                actionField.type = 'hidden';
                actionField.name = 'action';
                actionField.value = 'bulk_action';
                form.appendChild(actionField);
                
                // Add bulk action type
                const bulkTypeField = document.createElement('input');
                bulkTypeField.type = 'hidden';
                bulkTypeField.name = 'bulk_action_type';
                bulkTypeField.value = action;
                form.appendChild(bulkTypeField);
                
                // Add selected entry IDs
                checkboxes.forEach(function(checkbox) {
                    const idField = document.createElement('input');
                    idField.type = 'hidden';
                    idField.name = 'entry_ids[]';
                    idField.value = checkbox.value;
                    form.appendChild(idField);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    // Select all checkbox
    const selectAllCheckbox = document.getElementById('select-all-entries');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="entry_checkbox[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
    }
});
</script>
