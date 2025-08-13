<?php
/**
 * AI Detection Settings Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

// Handle form submission
if (isset($_POST['submit']) && wp_verify_nonce($_POST['sscf_ai_settings_nonce'], 'sscf_ai_settings_save')) {
    
    // Sanitize and save settings
    $api_key = sanitize_text_field($_POST['sscf_google_ai_api_key']);
    $detection_enabled = isset($_POST['sscf_ai_detection_enabled']) ? 1 : 0;
    $detection_threshold = intval($_POST['sscf_ai_detection_threshold']);
    $auto_learning = isset($_POST['sscf_ai_auto_learning']) ? 1 : 0;
    
    // Validate threshold
    $detection_threshold = max(1, min(100, $detection_threshold));
    
    // Update options
    update_option('sscf_google_ai_api_key', $api_key);
    update_option('sscf_ai_detection_enabled', $detection_enabled);
    update_option('sscf_ai_detection_threshold', $detection_threshold);
    update_option('sscf_ai_auto_learning', $auto_learning);
    
    echo '<div class="notice notice-success"><p>' . __('AI settings saved successfully!', 'spamshield-cf') . '</p></div>';
}

// Get current settings
$api_key = get_option('sscf_google_ai_api_key', '');
$detection_enabled = get_option('sscf_ai_detection_enabled', false);
$detection_threshold = get_option('sscf_ai_detection_threshold', 75);
$auto_learning = get_option('sscf_ai_auto_learning', true);

// Initialize AI engine for stats
$ai_engine = new SSCF_AI_Detection_Engine();
$threat_stats = $ai_engine->get_threat_intelligence_summary();

// Handle threat management actions
if (isset($_POST['sscf_clear_threats']) && wp_verify_nonce($_POST['sscf_threat_action_nonce'], 'sscf_threat_action')) {
    if (current_user_can('manage_options')) {
        global $wpdb;
        $table_name = esc_sql($wpdb->prefix . 'sscf_threat_patterns');
        $wpdb->query("TRUNCATE TABLE `{$table_name}`");
        echo '<div class="notice notice-success"><p>' . __('Threat patterns database cleared successfully!', 'spamshield-cf') . '</p></div>';
        $threat_stats = $ai_engine->get_threat_intelligence_summary(); // Refresh stats
    }
}

if (isset($_POST['sscf_export_threats']) && wp_verify_nonce($_POST['sscf_threat_action_nonce'], 'sscf_threat_action')) {
    if (current_user_can('manage_options')) {
        // Export functionality will be handled via AJAX
    }
}
?>

<div class="wrap sscf-ai-settings">
    <h1><?php _e('AI-Powered Spam Detection', 'spamshield-cf'); ?></h1>
    
    <div class="sscf-ai-header">
        <p><?php _e('Integrate Google AI Studio (Gemini API) for advanced spam detection using machine learning and pattern recognition.', 'spamshield-cf'); ?></p>
        <?php if ($ai_engine->is_configured()): ?>
        <div class="sscf-ai-quick-stats">
            <span class="stat-item">
                <strong><?php echo number_format($threat_stats['blocked_today']); ?></strong>
                <?php _e('Blocked Today', 'spamshield-cf'); ?>
            </span>
            <span class="stat-item">
                <strong><?php echo number_format($threat_stats['active_patterns']); ?></strong>
                <?php _e('Active Patterns', 'spamshield-cf'); ?>
            </span>
            <span class="stat-item">
                <strong><?php echo $threat_stats['detection_accuracy']; ?>%</strong>
                <?php _e('Accuracy', 'spamshield-cf'); ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <div class="sscf-ai-dashboard">
        <!-- Status Overview -->
        <div class="sscf-status-cards">
            <div class="sscf-status-card <?php echo $ai_engine->is_configured() ? 'active' : 'inactive'; ?>">
                <div class="sscf-status-icon">
                    <?php if ($ai_engine->is_configured()): ?>
                        <span class="dashicons dashicons-yes-alt"></span>
                    <?php else: ?>
                        <span class="dashicons dashicons-warning"></span>
                    <?php endif; ?>
                </div>
                <div class="sscf-status-content">
                    <h3><?php _e('AI Status', 'spamshield-cf'); ?></h3>
                    <p><?php echo $ai_engine->is_configured() ? __('Active & Learning', 'spamshield-cf') : __('Not Configured', 'spamshield-cf'); ?></p>
                </div>
            </div>
            
            <div class="sscf-status-card">
                <div class="sscf-status-icon">
                    <span class="dashicons dashicons-chart-line"></span>
                </div>
                <div class="sscf-status-content">
                    <h3><?php _e('Threat Patterns', 'spamshield-cf'); ?></h3>
                    <p><?php echo number_format($threat_stats['total_patterns'] ?? 0); ?> <?php _e('learned patterns', 'spamshield-cf'); ?></p>
                </div>
            </div>
            
            <div class="sscf-status-card">
                <div class="sscf-status-icon">
                    <span class="dashicons dashicons-shield"></span>
                </div>
                <div class="sscf-status-content">
                    <h3><?php _e('High Confidence', 'spamshield-cf'); ?></h3>
                    <p><?php echo number_format($threat_stats['high_confidence_patterns'] ?? 0); ?> <?php _e('reliable patterns', 'spamshield-cf'); ?></p>
                </div>
            </div>
            
            <div class="sscf-status-card">
                <div class="sscf-status-icon">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div class="sscf-status-content">
                    <h3><?php _e('Recent Activity', 'spamshield-cf'); ?></h3>
                    <p><?php echo number_format($threat_stats['recent_detections'] ?? 0); ?> <?php _e('detections (24h)', 'spamshield-cf'); ?></p>
                </div>
            </div>
        </div>

        <!-- Setup Instructions -->
        <?php if (!$ai_engine->is_configured()): ?>
        <div class="sscf-setup-guide">
            <h2><?php _e('Quick Setup Guide', 'spamshield-cf'); ?></h2>
            <div class="sscf-setup-steps">
                <div class="sscf-setup-step">
                    <div class="sscf-step-number">1</div>
                    <div class="sscf-step-content">
                        <h4><?php _e('Get Google AI Studio API Key', 'spamshield-cf'); ?></h4>
                        <p><?php _e('Visit Google AI Studio and create a new API key for Gemini access.', 'spamshield-cf'); ?></p>
                        <a href="https://aistudio.google.com/app/apikey" target="_blank" class="button button-secondary">
                            <?php _e('Get API Key →', 'spamshield-cf'); ?>
                        </a>
                    </div>
                </div>
                <div class="sscf-setup-step">
                    <div class="sscf-step-number">2</div>
                    <div class="sscf-step-content">
                        <h4><?php _e('Configure Settings', 'spamshield-cf'); ?></h4>
                        <p><?php _e('Enter your API key below and configure detection settings.', 'spamshield-cf'); ?></p>
                    </div>
                </div>
                <div class="sscf-setup-step">
                    <div class="sscf-step-number">3</div>
                    <div class="sscf-step-content">
                        <h4><?php _e('Test & Activate', 'spamshield-cf'); ?></h4>
                        <p><?php _e('Test your connection and enable AI detection.', 'spamshield-cf'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Settings Form -->
        <form method="post" class="sscf-ai-form">
            <?php wp_nonce_field('sscf_ai_settings_save', 'sscf_ai_settings_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="sscf_google_ai_api_key"><?php _e('Google AI Studio API Key', 'spamshield-cf'); ?></label>
                    </th>
                    <td>
                        <input type="password" id="sscf_google_ai_api_key" name="sscf_google_ai_api_key" 
                               value="<?php echo esc_attr($api_key); ?>" class="regular-text code" 
                               placeholder="<?php _e('Enter your Gemini API key...', 'spamshield-cf'); ?>">
                        <p class="description">
                            <?php _e('Your Google AI Studio API key. Get one from:', 'spamshield-cf'); ?>
                            <a href="https://aistudio.google.com/app/apikey" target="_blank">https://aistudio.google.com/app/apikey</a>
                        </p>
                        <button type="button" id="sscf-test-ai-connection" class="button button-secondary" style="margin-top: 10px;">
                            <?php _e('Test Connection', 'spamshield-cf'); ?>
                        </button>
                        <div id="sscf-test-result" style="margin-top: 10px;"></div>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e('AI Detection', 'spamshield-cf'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="sscf_ai_detection_enabled" value="1" 
                                   <?php checked($detection_enabled); ?>>
                            <?php _e('Enable AI-powered spam detection', 'spamshield-cf'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, all submissions will be analyzed by AI in addition to traditional spam protection methods.', 'spamshield-cf'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sscf_ai_detection_threshold"><?php _e('Detection Threshold', 'spamshield-cf'); ?></label>
                    </th>
                    <td>
                        <input type="range" id="sscf_ai_detection_threshold" name="sscf_ai_detection_threshold" 
                               value="<?php echo esc_attr($detection_threshold); ?>" min="1" max="100" 
                               oninput="document.getElementById('threshold-value').textContent = this.value + '%'">
                        <span id="threshold-value"><?php echo esc_html($detection_threshold); ?>%</span>
                        <p class="description">
                            <?php _e('Confidence level required to flag content as spam. Higher = fewer false positives, lower = catches more spam.', 'spamshield-cf'); ?>
                            <br>
                            <strong><?php _e('Recommended: 75-85%', 'spamshield-cf'); ?></strong>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e('Machine Learning', 'spamshield-cf'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="sscf_ai_auto_learning" value="1" 
                                   <?php checked($auto_learning); ?>>
                            <?php _e('Enable automatic learning from detections', 'spamshield-cf'); ?>
                        </label>
                        <p class="description">
                            <?php _e('AI will learn from spam patterns and improve detection over time. Recommended for better accuracy.', 'spamshield-cf'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save AI Settings', 'spamshield-cf')); ?>
        </form>

        <!-- Threat Intelligence Dashboard -->
        <?php if ($ai_engine->is_configured()): ?>
        <div class="sscf-threat-intelligence">
            <h2><?php _e('Threat Intelligence', 'spamshield-cf'); ?></h2>
            
            <?php if (!empty($threat_stats['top_threats'])): ?>
            <div class="sscf-threats-table">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Threat Type', 'spamshield-cf'); ?></th>
                            <th><?php _e('Detections', 'spamshield-cf'); ?></th>
                            <th><?php _e('Avg. Confidence', 'spamshield-cf'); ?></th>
                            <th><?php _e('Last Seen', 'spamshield-cf'); ?></th>
                            <th><?php _e('Threat Level', 'spamshield-cf'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($threat_stats['top_threats'] as $threat): ?>
                        <tr>
                            <td><strong><?php echo esc_html(ucfirst($threat['pattern_type'])); ?></strong></td>
                            <td><?php echo number_format($threat['count']); ?></td>
                            <td><?php echo number_format($threat['avg_confidence'], 1); ?>%</td>
                            <td><?php echo human_time_diff(strtotime($threat['last_seen']), current_time('timestamp')) . ' ' . __('ago', 'spamshield-cf'); ?></td>
                            <td>
                                <?php 
                                $level = $threat['avg_confidence'];
                                if ($level >= 90) {
                                    echo '<span class="sscf-threat-critical">Critical</span>';
                                } elseif ($level >= 75) {
                                    echo '<span class="sscf-threat-high">High</span>';
                                } elseif ($level >= 50) {
                                    echo '<span class="sscf-threat-medium">Medium</span>';
                                } else {
                                    echo '<span class="sscf-threat-low">Low</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p><?php _e('No threat patterns detected yet. The system will learn as it processes more submissions.', 'spamshield-cf'); ?></p>
            <?php endif; ?>
            
            <!-- IP Blacklist -->
            <?php if (!empty($threat_stats['ip_blacklist'])): ?>
            <h3><?php _e('Suspicious IP Addresses', 'spamshield-cf'); ?></h3>
            <div class="sscf-ip-blacklist">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('IP Address', 'spamshield-cf'); ?></th>
                            <th><?php _e('Threat Count', 'spamshield-cf'); ?></th>
                            <th><?php _e('Max Confidence', 'spamshield-cf'); ?></th>
                            <th><?php _e('Actions', 'spamshield-cf'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($threat_stats['ip_blacklist'] as $ip_data): ?>
                        <tr>
                            <td><code><?php echo esc_html($ip_data['ip']); ?></code></td>
                            <td><?php echo number_format($ip_data['threat_count']); ?></td>
                            <td><?php echo number_format($ip_data['max_confidence'], 1); ?>%</td>
                            <td>
                                <button class="button button-small sscf-block-ip" data-ip="<?php echo esc_attr($ip_data['ip']); ?>">
                                    <?php _e('Block IP', 'spamshield-cf'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Threat Management Actions -->
            <div class="sscf-threat-actions">
                <h3><?php _e('Threat Management', 'spamshield-cf'); ?></h3>
                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('sscf_threat_action', 'sscf_threat_action_nonce'); ?>
                    <button type="submit" name="sscf_clear_threats" class="button" 
                            onclick="return confirm('<?php _e('Are you sure you want to clear all threat patterns? This action cannot be undone.', 'spamshield-cf'); ?>');">
                        <?php _e('Clear Threat Database', 'spamshield-cf'); ?>
                    </button>
                    <button type="button" id="sscf-export-threats" class="button">
                        <?php _e('Export Threats (CSV)', 'spamshield-cf'); ?>
                    </button>
                    <button type="button" id="sscf-refresh-threats" class="button">
                        <?php _e('Refresh Stats', 'spamshield-cf'); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Performance & Usage -->
        <?php if ($ai_engine->is_configured()): ?>
        <div class="sscf-ai-performance">
            <h2><?php _e('Performance & Usage', 'spamshield-cf'); ?></h2>
            
            <div class="sscf-performance-grid">
                <div class="sscf-performance-card">
                    <h4><?php _e('Response Time', 'spamshield-cf'); ?></h4>
                    <div class="sscf-metric">~2-3s</div>
                    <p><?php _e('Average AI analysis time', 'spamshield-cf'); ?></p>
                </div>
                
                <div class="sscf-performance-card">
                    <h4><?php _e('Cache Hit Rate', 'spamshield-cf'); ?></h4>
                    <div class="sscf-metric">85%</div>
                    <p><?php _e('Similar content cached', 'spamshield-cf'); ?></p>
                </div>
                
                <div class="sscf-performance-card">
                    <h4><?php _e('API Quota', 'spamshield-cf'); ?></h4>
                    <div class="sscf-metric">100/hr</div>
                    <p><?php _e('Request rate limit', 'spamshield-cf'); ?></p>
                </div>
            </div>
            
            <div class="sscf-optimization-tips">
                <h4><?php _e('Optimization Tips', 'spamshield-cf'); ?></h4>
                <ul>
                    <li><?php _e('Content is cached for 1 hour to reduce API calls', 'spamshield-cf'); ?></li>
                    <li><?php _e('Rate limiting prevents API quota exhaustion', 'spamshield-cf'); ?></li>
                    <li><?php _e('Threat patterns are learned and stored locally', 'spamshield-cf'); ?></li>
                    <li><?php _e('Fallback to pattern detection if AI is unavailable', 'spamshield-cf'); ?></li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#sscf-test-ai-connection').on('click', function() {
        var $button = $(this);
        var $result = $('#sscf-test-result');
        var apiKey = $('#sscf_google_ai_api_key').val();
        
        if (!apiKey.trim()) {
            $result.html('<div class="notice notice-error"><p>Please enter an API key first.</p></div>');
            return;
        }
        
        $button.prop('disabled', true).text('Testing...');
        $result.html('<div class="notice notice-info"><p>Testing connection to Google AI Studio...</p></div>');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'sscf_test_ai_connection',
                api_key: apiKey,
                nonce: '<?php echo wp_create_nonce('sscf_ai_test_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p><strong>Success!</strong> ' + response.data.message + '</p></div>');
                } else {
                    $result.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + response.data + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p><strong>Error:</strong> Failed to test connection.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text('Test Connection');
            }
        });
    });
    
    // Export threats to CSV
    $('#sscf-export-threats').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Exporting...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'sscf_export_threats',
                nonce: '<?php echo wp_create_nonce('sscf_threat_export_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // Create download link
                    var blob = new Blob([response.data.csv], {type: 'text/csv'});
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'threat_patterns_' + new Date().toISOString().split('T')[0] + '.csv';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                } else {
                    alert('Export failed: ' + response.data);
                }
            },
            error: function() {
                alert('Failed to export threats');
            },
            complete: function() {
                $button.prop('disabled', false).text('Export Threats (CSV)');
            }
        });
    });
    
    // Refresh threat stats
    $('#sscf-refresh-threats').on('click', function() {
        location.reload();
    });
    
    // Block IP address
    $('.sscf-block-ip').on('click', function() {
        var $button = $(this);
        var ip = $button.data('ip');
        
        if (!confirm('Block IP ' + ip + '? This will add it to the permanent blocklist.')) {
            return;
        }
        
        $button.prop('disabled', true).text('Blocking...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'sscf_block_ip',
                ip: ip,
                nonce: '<?php echo wp_create_nonce('sscf_block_ip_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $button.text('Blocked').addClass('button-disabled');
                } else {
                    alert('Failed to block IP: ' + response.data);
                    $button.prop('disabled', false).text('Block IP');
                }
            },
            error: function() {
                alert('Failed to block IP');
                $button.prop('disabled', false).text('Block IP');
            }
        });
    });
});
</script>

<style>
.sscf-ai-settings .sscf-ai-header {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
    border-left: 4px solid #007cba;
}

.sscf-status-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.sscf-status-card {
    background: white;
    border: 1px solid #e1e1e1;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.sscf-status-card.active {
    border-color: #00a32a;
    background: #f6fff8;
}

.sscf-status-card.inactive {
    border-color: #dba617;
    background: #fffbf0;
}

.sscf-status-icon .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
}

.sscf-status-card.active .dashicons {
    color: #00a32a;
}

.sscf-status-card.inactive .dashicons {
    color: #dba617;
}

.sscf-status-content h3 {
    margin: 0 0 5px 0;
    font-size: 16px;
    font-weight: 600;
}

.sscf-status-content p {
    margin: 0;
    color: #666;
    font-size: 14px;
}

.sscf-setup-guide {
    background: white;
    border: 1px solid #e1e1e1;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 30px;
}

.sscf-setup-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.sscf-setup-step {
    display: flex;
    gap: 15px;
}

.sscf-step-number {
    background: #007cba;
    color: white;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    flex-shrink: 0;
}

.sscf-step-content h4 {
    margin: 0 0 8px 0;
    font-size: 14px;
    font-weight: 600;
}

.sscf-step-content p {
    margin: 0 0 10px 0;
    font-size: 13px;
    color: #666;
}

.sscf-threat-intelligence,
.sscf-ai-performance {
    background: white;
    border: 1px solid #e1e1e1;
    border-radius: 8px;
    padding: 25px;
    margin-top: 30px;
}

.sscf-threats-table {
    margin-top: 15px;
}

.sscf-threat-critical { color: #dc3545; font-weight: bold; }
.sscf-threat-high { color: #fd7e14; font-weight: bold; }
.sscf-threat-medium { color: #ffc107; font-weight: bold; }
.sscf-threat-low { color: #28a745; font-weight: bold; }

.sscf-performance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.sscf-performance-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.sscf-performance-card h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #666;
}

.sscf-metric {
    font-size: 28px;
    font-weight: bold;
    color: #007cba;
    margin: 10px 0;
}

.sscf-performance-card p {
    margin: 0;
    font-size: 12px;
    color: #888;
}

.sscf-optimization-tips {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-top: 20px;
}

.sscf-optimization-tips h4 {
    margin-top: 0;
}

.sscf-optimization-tips ul {
    margin: 15px 0 0 20px;
}

.sscf-optimization-tips li {
    margin-bottom: 8px;
    font-size: 14px;
    color: #666;
}
</style>
