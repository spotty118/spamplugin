<?php
/**
 * Database Status Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

// Initialize database utilities
$db_utils = new SSCF_Database_Utilities();
$status = $db_utils->get_database_status();
$summary = $db_utils->get_database_summary();

// Handle cleanup action
if (isset($_POST['cleanup_data']) && wp_verify_nonce($_POST['cleanup_nonce'], 'sscf_cleanup_data')) {
    $days = intval($_POST['cleanup_days'] ?? 90);
    $cleanup_results = $db_utils->cleanup_old_data($days);
    echo '<div class="notice notice-success"><p>' . 
         sprintf(__('Cleanup completed! Removed %d old analytics records and %d outdated threat patterns.', 'spamshield-cf'), 
         $cleanup_results['analytics_cleaned'], $cleanup_results['patterns_cleaned']) . 
         '</p></div>';
    
    // Refresh status after cleanup
    $status = $db_utils->get_database_status();
    $summary = $db_utils->get_database_summary();
}
?>

<div class="wrap sscf-database-status">
    <h1><?php _e('Database Status & Utilities', 'spamshield-cf'); ?></h1>
    
    <div class="sscf-database-header">
        <p class="description">
            <?php _e('Monitor and manage your SpamShield database tables. This page shows the health status of all plugin tables and provides utilities for maintenance.', 'spamshield-cf'); ?>
        </p>
    </div>

    <!-- Database Summary Cards -->
    <div class="sscf-db-summary">
        <div class="sscf-summary-card <?php echo esc_attr($status['overall_status']); ?>">
            <div class="sscf-card-icon">
                <?php if ($status['overall_status'] === 'healthy'): ?>
                    <span class="dashicons dashicons-yes-alt"></span>
                <?php elseif ($status['overall_status'] === 'warning'): ?>
                    <span class="dashicons dashicons-warning"></span>
                <?php else: ?>
                    <span class="dashicons dashicons-dismiss"></span>
                <?php endif; ?>
            </div>
            <div class="sscf-card-content">
                <h3><?php _e('Overall Status', 'spamshield-cf'); ?></h3>
                <p class="sscf-status-text">
                    <?php 
                    switch ($status['overall_status']) {
                        case 'healthy':
                            _e('All systems operational', 'spamshield-cf');
                            break;
                        case 'warning':
                            _e('Minor issues detected', 'spamshield-cf');
                            break;
                        case 'critical':
                            _e('Critical issues found', 'spamshield-cf');
                            break;
                    }
                    ?>
                </p>
            </div>
        </div>
        
        <div class="sscf-summary-card">
            <div class="sscf-card-icon">
                <span class="dashicons dashicons-database"></span>
            </div>
            <div class="sscf-card-content">
                <h3><?php _e('Tables', 'spamshield-cf'); ?></h3>
                <p><?php echo esc_html($summary['tables_count']); ?> <?php _e('healthy', 'spamshield-cf'); ?></p>
            </div>
        </div>
        
        <div class="sscf-summary-card">
            <div class="sscf-card-icon">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
            <div class="sscf-card-content">
                <h3><?php _e('Total Records', 'spamshield-cf'); ?></h3>
                <p><?php echo number_format($summary['total_rows']); ?> <?php _e('entries', 'spamshield-cf'); ?></p>
            </div>
        </div>
        
        <div class="sscf-summary-card">
            <div class="sscf-card-icon">
                <span class="dashicons dashicons-admin-tools"></span>
            </div>
            <div class="sscf-card-content">
                <h3><?php _e('Database Size', 'spamshield-cf'); ?></h3>
                <p><?php echo number_format($summary['total_size_mb'], 2); ?> MB</p>
            </div>
        </div>
    </div>

    <!-- Issues Alert -->
    <?php if (!empty($status['issues'])): ?>
    <div class="sscf-issues-alert">
        <div class="notice notice-<?php echo $status['overall_status'] === 'critical' ? 'error' : 'warning'; ?>">
            <h4><?php _e('Database Issues Detected', 'spamshield-cf'); ?></h4>
            <ul>
                <?php foreach ($status['issues'] as $issue): ?>
                    <li><?php echo esc_html($issue); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Database Actions -->
    <div class="sscf-db-actions">
        <h2><?php _e('Database Actions', 'spamshield-cf'); ?></h2>
        
        <div class="sscf-actions-grid">
            <div class="sscf-action-card">
                <h3><?php _e('Rebuild Database', 'spamshield-cf'); ?></h3>
                <p><?php _e('Recreate all plugin tables. This will fix missing or corrupted tables without losing data.', 'spamshield-cf'); ?></p>
                <button type="button" id="sscf-rebuild-database" class="button button-primary">
                    <?php _e('Rebuild Tables', 'spamshield-cf'); ?>
                </button>
                <div id="sscf-rebuild-result" style="margin-top: 10px;"></div>
            </div>
            
            <div class="sscf-action-card">
                <h3><?php _e('Check Status', 'spamshield-cf'); ?></h3>
                <p><?php _e('Refresh database status and check for any new issues or changes.', 'spamshield-cf'); ?></p>
                <button type="button" id="sscf-check-status" class="button button-secondary">
                    <?php _e('Refresh Status', 'spamshield-cf'); ?>
                </button>
                <div id="sscf-status-result" style="margin-top: 10px;"></div>
            </div>
            
            <div class="sscf-action-card">
                <form method="post">
                    <?php wp_nonce_field('sscf_cleanup_data', 'cleanup_nonce'); ?>
                    <h3><?php _e('Cleanup Old Data', 'spamshield-cf'); ?></h3>
                    <p><?php _e('Remove old analytics data and outdated threat patterns to optimize database size.', 'spamshield-cf'); ?></p>
                    <label for="cleanup_days"><?php _e('Remove data older than:', 'spamshield-cf'); ?></label>
                    <select name="cleanup_days" id="cleanup_days">
                        <option value="30">30 <?php _e('days', 'spamshield-cf'); ?></option>
                        <option value="60">60 <?php _e('days', 'spamshield-cf'); ?></option>
                        <option value="90" selected>90 <?php _e('days', 'spamshield-cf'); ?></option>
                        <option value="180">180 <?php _e('days', 'spamshield-cf'); ?></option>
                    </select>
                    <button type="submit" name="cleanup_data" class="button button-secondary">
                        <?php _e('Cleanup Data', 'spamshield-cf'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Detailed Table Status -->
    <div class="sscf-table-details">
        <h2><?php _e('Table Details', 'spamshield-cf'); ?></h2>
        
        <div class="sscf-tables-grid">
            <?php foreach ($status['tables'] as $key => $table): ?>
            <div class="sscf-table-card <?php echo esc_attr($table['status']); ?>">
                <div class="sscf-table-header">
                    <h4><?php echo esc_html($table['label']); ?></h4>
                    <span class="sscf-table-status sscf-status-<?php echo esc_attr($table['status']); ?>">
                        <?php echo esc_html(ucfirst($table['status'])); ?>
                    </span>
                </div>
                
                <div class="sscf-table-info">
                    <p class="sscf-table-description"><?php echo esc_html($table['description']); ?></p>
                    
                    <?php if ($table['exists']): ?>
                    <div class="sscf-table-stats">
                        <div class="sscf-stat">
                            <span class="sscf-stat-label"><?php _e('Records:', 'spamshield-cf'); ?></span>
                            <span class="sscf-stat-value"><?php echo number_format($table['row_count']); ?></span>
                        </div>
                        <div class="sscf-stat">
                            <span class="sscf-stat-label"><?php _e('Size:', 'spamshield-cf'); ?></span>
                            <span class="sscf-stat-value"><?php echo number_format($table['size_mb'], 2); ?> MB</span>
                        </div>
                        <?php if ($table['created_at']): ?>
                        <div class="sscf-stat">
                            <span class="sscf-stat-label"><?php _e('Created:', 'spamshield-cf'); ?></span>
                            <span class="sscf-stat-value"><?php echo esc_html(date('M j, Y', strtotime($table['created_at']))); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="sscf-missing-table">
                        <p><strong><?php _e('Table Missing', 'spamshield-cf'); ?></strong></p>
                        <p><?php _e('This table needs to be created for the plugin to function properly.', 'spamshield-cf'); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Technical Information -->
    <div class="sscf-tech-info">
        <h2><?php _e('Technical Information', 'spamshield-cf'); ?></h2>
        
        <div class="sscf-tech-grid">
            <div class="sscf-tech-card">
                <h4><?php _e('Database Details', 'spamshield-cf'); ?></h4>
                <table class="widefat">
                    <tr>
                        <td><?php _e('Database Type:', 'spamshield-cf'); ?></td>
                        <td>MySQL/MariaDB</td>
                    </tr>
                    <tr>
                        <td><?php _e('Database Name:', 'spamshield-cf'); ?></td>
                        <td><?php echo esc_html(DB_NAME); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Table Prefix:', 'spamshield-cf'); ?></td>
                        <td><?php global $wpdb; echo esc_html($wpdb->prefix); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Plugin Version:', 'spamshield-cf'); ?></td>
                        <td><?php echo esc_html(get_option('sscf_db_version', '1.0')); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="sscf-tech-card">
                <h4><?php _e('Maintenance Tips', 'spamshield-cf'); ?></h4>
                <ul>
                    <li><?php _e('Regular cleanup keeps database optimized', 'spamshield-cf'); ?></li>
                    <li><?php _e('Monitor table sizes for unusual growth', 'spamshield-cf'); ?></li>
                    <li><?php _e('Backup before rebuilding tables', 'spamshield-cf'); ?></li>
                    <li><?php _e('Check status after plugin updates', 'spamshield-cf'); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Rebuild Database
    $('#sscf-rebuild-database').on('click', function() {
        var $button = $(this);
        var $result = $('#sscf-rebuild-result');
        
        $button.prop('disabled', true).text('Rebuilding...');
        $result.html('<div class="notice notice-info"><p>Rebuilding database tables...</p></div>');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'sscf_rebuild_database',
                nonce: '<?php echo wp_create_nonce('sscf_database_rebuild'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p><strong>Success!</strong> ' + response.data.message + '</p></div>');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $result.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Failed to rebuild database. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Rebuild Tables');
            }
        });
    });
    
    // Check Status
    $('#sscf-check-status').on('click', function() {
        var $button = $(this);
        var $result = $('#sscf-status-result');
        
        $button.prop('disabled', true).text('Checking...');
        $result.html('<div class="notice notice-info"><p>Checking database status...</p></div>');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'sscf_check_database_status',
                nonce: '<?php echo wp_create_nonce('sscf_database_status'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var status = response.data;
                    var statusText = status.overall_status === 'healthy' ? 'All systems healthy!' : 
                                    status.overall_status === 'warning' ? 'Minor issues detected' : 
                                    'Critical issues found';
                    $result.html('<div class="notice notice-info"><p><strong>Status:</strong> ' + statusText + '</p></div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $result.html('<div class="notice notice-error"><p>Failed to check status.</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Failed to check database status.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Refresh Status');
            }
        });
    });
    
    // Rebuild from notice
    $(document).on('click', '#sscf-rebuild-database-notice', function(e) {
        e.preventDefault();
        $('#sscf-rebuild-database').click();
    });
});
</script>

<style>
.sscf-database-status .sscf-database-header {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
    border-left: 4px solid #007cba;
}

.sscf-db-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.sscf-summary-card {
    background: white;
    border: 1px solid #e1e1e1;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.sscf-summary-card.healthy {
    border-color: #00a32a;
    background: #f6fff8;
}

.sscf-summary-card.warning {
    border-color: #dba617;
    background: #fffbf0;
}

.sscf-summary-card.critical {
    border-color: #dc3545;
    background: #fff5f5;
}

.sscf-card-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
}

.sscf-summary-card.healthy .dashicons {
    color: #00a32a;
}

.sscf-summary-card.warning .dashicons {
    color: #dba617;
}

.sscf-summary-card.critical .dashicons {
    color: #dc3545;
}

.sscf-card-content h3 {
    margin: 0 0 5px 0;
    font-size: 16px;
    font-weight: 600;
}

.sscf-card-content p {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.sscf-issues-alert {
    margin-bottom: 30px;
}

.sscf-db-actions,
.sscf-table-details,
.sscf-tech-info {
    background: white;
    border: 1px solid #e1e1e1;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 30px;
}

.sscf-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.sscf-action-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
}

.sscf-action-card h3 {
    margin-top: 0;
}

.sscf-tables-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.sscf-table-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    border: 2px solid transparent;
}

.sscf-table-card.healthy {
    border-color: #00a32a;
    background: #f6fff8;
}

.sscf-table-card.warning {
    border-color: #dba617;
    background: #fffbf0;
}

.sscf-table-card.missing,
.sscf-table-card.corrupted {
    border-color: #dc3545;
    background: #fff5f5;
}

.sscf-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.sscf-table-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.sscf-status-healthy {
    background: #00a32a;
    color: white;
}

.sscf-status-warning {
    background: #dba617;
    color: white;
}

.sscf-status-missing,
.sscf-status-corrupted {
    background: #dc3545;
    color: white;
}

.sscf-table-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
    gap: 10px;
    margin-top: 15px;
}

.sscf-stat {
    display: flex;
    flex-direction: column;
}

.sscf-stat-label {
    font-size: 12px;
    color: #666;
    margin-bottom: 2px;
}

.sscf-stat-value {
    font-weight: bold;
    color: #333;
}

.sscf-tech-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.sscf-tech-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
}

.sscf-tech-card h4 {
    margin-top: 0;
}

.sscf-tech-card table {
    margin-top: 15px;
}

.sscf-tech-card ul {
    margin: 15px 0 0 20px;
}

.sscf-tech-card li {
    margin-bottom: 8px;
    font-size: 14px;
    color: #666;
}
</style>
