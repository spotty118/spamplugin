<?php
/**
 * Admin Settings Page
 * Simple settings interface for SpamShield Contact Form
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle form fields update
if (isset($_POST['sscf_save_fields']) && wp_verify_nonce($_POST['sscf_fields_nonce'], 'sscf_save_fields')) {
    $form_fields = array();
    
    if (!empty($_POST['fields'])) {
        $order = 1;
        foreach ($_POST['fields'] as $field_data) {
            if (!empty($field_data['label'])) {
                $form_fields[] = array(
                    'id' => sanitize_key($field_data['id']),
                    'label' => sanitize_text_field($field_data['label']),
                    'type' => sanitize_text_field($field_data['type']),
                    'required' => !empty($field_data['required']),
                    'placeholder' => sanitize_text_field($field_data['placeholder']),
                    'order' => $order++
                );
            }
        }
    }
    
    update_option('sscf_form_fields', $form_fields);
    echo '<div class="notice notice-success"><p>' . __('Form fields updated successfully!', 'spamshield-cf') . '</p></div>';
}

// Handle form submission
if (isset($_POST['sscf_save_settings']) && wp_verify_nonce($_POST['sscf_settings_nonce'], 'sscf_save_settings')) {
    $options = get_option('sscf_options', array());
    $options['honeypot_enabled'] = !empty($_POST['honeypot_enabled']);
    $options['min_time_seconds'] = intval($_POST['min_time_seconds']);
    $options['email_recipient'] = sanitize_email($_POST['email_recipient']);
    $options['success_message'] = sanitize_textarea_field($_POST['success_message']);
    $options['spam_blocked_count'] = intval($_POST['spam_blocked_count']); // Preserve existing count
    $options['daily_reports_enabled'] = !empty($_POST['daily_reports_enabled']);
    
    update_option('sscf_options', $options);
    echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'spamshield-cf') . '</p></div>';
}

// Handle test email
if (isset($_POST['sscf_send_test']) && wp_verify_nonce($_POST['sscf_test_nonce'], 'sscf_send_test')) {
    $test_email = sanitize_email($_POST['test_email']);
    if ($test_email) {
        $email_sender = new SSCF_Email_Sender();
        $result = $email_sender->send_test_email($test_email);
        
        if ($result['success']) {
            echo '<div class="notice notice-success"><p>' . __('Test email sent successfully!', 'spamshield-cf') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('Failed to send test email: ', 'spamshield-cf') . esc_html($result['message']) . '</p></div>';
        }
    }
}

// Get current options
$options = get_option('sscf_options', array());
$spam_protection = new SSCF_Spam_Protection();
$spam_stats = $spam_protection->get_spam_stats();
$form_fields = get_option('sscf_form_fields', array());

// Default values
$honeypot_enabled = !empty($options['honeypot_enabled']);
$min_time_seconds = !empty($options['min_time_seconds']) ? intval($options['min_time_seconds']) : 3;
$email_recipient = !empty($options['email_recipient']) ? $options['email_recipient'] : get_option('admin_email');
$success_message = !empty($options['success_message']) ? $options['success_message'] : __('Thank you! Your message has been sent successfully.', 'spamshield-cf');
?>

<div class="wrap">
    <h1><?php _e('SpamShield Contact Form Settings', 'spamshield-cf'); ?></h1>
    
    <!-- Quick Start Guide -->
    <div class="card" style="max-width: none;">
        <h2 class="title"><?php _e('Quick Start Guide', 'spamshield-cf'); ?></h2>
        <p><?php _e('Your contact form is ready to use! Follow these simple steps:', 'spamshield-cf'); ?></p>
        <ol>
            <li><strong><?php _e('Add the form to any page or post:', 'spamshield-cf'); ?></strong> <?php _e('Use the shortcode', 'spamshield-cf'); ?> <code>[spamshield_form]</code></li>
            <li><strong><?php _e('Test the form:', 'spamshield-cf'); ?></strong> <?php _e('Submit a test message to make sure emails are working', 'spamshield-cf'); ?></li>
            <li><strong><?php _e('Customize settings below:', 'spamshield-cf'); ?></strong> <?php _e('Adjust spam protection and email settings as needed', 'spamshield-cf'); ?></li>
        </ol>
        <p><em><?php _e('No configuration required - your form works immediately with smart spam protection!', 'spamshield-cf'); ?></em></p>
    </div>

    <!-- Form Fields Manager -->
    <div class="card" style="max-width: none;">
        <h2 class="title"><?php _e('Customize Form Fields', 'spamshield-cf'); ?></h2>
        <p><?php _e('Add, remove, and reorder your contact form fields. Changes will apply to all instances of [spamshield_form] on your site.', 'spamshield-cf'); ?></p>
        
        <form method="post" action="" id="sscf-fields-form">
            <?php wp_nonce_field('sscf_save_fields', 'sscf_fields_nonce'); ?>
            
            <div id="sscf-fields-container">
                <?php 
                usort($form_fields, function($a, $b) { return $a['order'] - $b['order']; });
                foreach ($form_fields as $index => $field): 
                ?>
                <div class="sscf-field-row" data-index="<?php echo $index; ?>">
                    <div class="sscf-field-handle">⋮⋮</div>
                    <div class="sscf-field-content">
                        <input type="hidden" name="fields[<?php echo $index; ?>][id]" value="<?php echo esc_attr($field['id']); ?>" />
                        
                        <div class="sscf-field-group">
                            <label><?php _e('Label:', 'spamshield-cf'); ?></label>
                            <input type="text" name="fields[<?php echo $index; ?>][label]" value="<?php echo esc_attr($field['label']); ?>" required />
                        </div>
                        
                        <div class="sscf-field-group">
                            <label><?php _e('Type:', 'spamshield-cf'); ?></label>
                            <select name="fields[<?php echo $index; ?>][type]" class="sscf-field-type-selector">
                                <optgroup label="<?php _e('Basic Fields', 'spamshield-cf'); ?>">
                                    <option value="text" <?php selected($field['type'], 'text'); ?>><?php _e('Text', 'spamshield-cf'); ?></option>
                                    <option value="email" <?php selected($field['type'], 'email'); ?>><?php _e('Email', 'spamshield-cf'); ?></option>
                                    <option value="tel" <?php selected($field['type'], 'tel'); ?>><?php _e('Phone', 'spamshield-cf'); ?></option>
                                    <option value="url" <?php selected($field['type'], 'url'); ?>><?php _e('URL', 'spamshield-cf'); ?></option>
                                    <option value="textarea" <?php selected($field['type'], 'textarea'); ?>><?php _e('Textarea', 'spamshield-cf'); ?></option>
                                </optgroup>
                                <optgroup label="<?php _e('Choice Fields', 'spamshield-cf'); ?>">
                                    <option value="select" <?php selected($field['type'], 'select'); ?>><?php _e('Dropdown', 'spamshield-cf'); ?></option>
                                    <option value="radio" <?php selected($field['type'], 'radio'); ?>><?php _e('Radio Buttons', 'spamshield-cf'); ?></option>
                                    <option value="checkbox" <?php selected($field['type'], 'checkbox'); ?>><?php _e('Checkboxes', 'spamshield-cf'); ?></option>
                                </optgroup>
                                <optgroup label="<?php _e('Advanced Fields', 'spamshield-cf'); ?>">
                                    <option value="date" <?php selected($field['type'], 'date'); ?>><?php _e('Date', 'spamshield-cf'); ?></option>
                                    <option value="number" <?php selected($field['type'], 'number'); ?>><?php _e('Number', 'spamshield-cf'); ?></option>
                                </optgroup>
                            </select>
                        </div>
                        
                        <div class="sscf-field-group">
                            <label><?php _e('Placeholder:', 'spamshield-cf'); ?></label>
                            <input type="text" name="fields[<?php echo $index; ?>][placeholder]" value="<?php echo esc_attr($field['placeholder'] ?? ''); ?>" />
                        </div>
                        
                        <div class="sscf-field-group sscf-options-field" style="<?php echo in_array($field['type'], ['select', 'radio', 'checkbox']) ? '' : 'display:none;'; ?>">
                            <label><?php _e('Options (one per line):', 'spamshield-cf'); ?></label>
                            <textarea name="fields[<?php echo $index; ?>][options]" rows="4" placeholder="<?php _e('Option 1&#10;Option 2&#10;Option 3', 'spamshield-cf'); ?>"><?php echo esc_textarea($field['options'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="sscf-field-group">
                            <label>
                                <input type="checkbox" name="fields[<?php echo $index; ?>][required]" value="1" <?php checked($field['required']); ?> />
                                <?php _e('Required', 'spamshield-cf'); ?>
                            </label>
                        </div>
                        
                        <div class="sscf-field-actions">
                            <button type="button" class="button sscf-remove-field"><?php _e('Remove', 'spamshield-cf'); ?></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="sscf-fields-actions">
                <button type="button" class="button" id="sscf-add-field"><?php _e('Add Field', 'spamshield-cf'); ?></button>
                <?php submit_button(__('Save Form Fields', 'spamshield-cf'), 'primary', 'sscf_save_fields', false); ?>
            </div>
        </form>
    </div>

    <!-- Statistics Dashboard -->
    <div class="card" style="max-width: none;">
        <h2 class="title"><?php _e('Spam Protection Statistics', 'spamshield-cf'); ?></h2>
        <table class="widefat" style="max-width: 500px;">
            <tbody>
                <tr>
                    <td><strong><?php _e('Total Spam Blocked:', 'spamshield-cf'); ?></strong></td>
                    <td><span style="color: #e74c3c; font-size: 18px; font-weight: bold;"><?php echo esc_html($spam_stats['total_blocked']); ?></span></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Status:', 'spamshield-cf'); ?></strong></td>
                    <td>
                        <?php if ($honeypot_enabled): ?>
                            <span style="color: #27ae60;">✓ <?php _e('Protected', 'spamshield-cf'); ?></span>
                        <?php else: ?>
                            <span style="color: #e74c3c;">⚠ <?php _e('Protection Disabled', 'spamshield-cf'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php _e('Email Recipient:', 'spamshield-cf'); ?></strong></td>
                    <td><?php echo esc_html($email_recipient); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Settings Form -->
    <form method="post" action="">
        <?php wp_nonce_field('sscf_save_settings', 'sscf_settings_nonce'); ?>
        
        <table class="form-table">
            <tbody>
                <!-- Spam Protection Settings -->
                <tr>
                    <th colspan="2"><h2><?php _e('Spam Protection Settings', 'spamshield-cf'); ?></h2></th>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="daily_reports_enabled"><?php _e('Daily Email Reports', 'spamshield-cf'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="daily_reports_enabled" id="daily_reports_enabled" value="1" <?php checked(!empty($options['daily_reports_enabled'])); ?> />
                            <?php _e('Send daily summary email with protection stats', 'spamshield-cf'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="honeypot_enabled"><?php _e('Honeypot Protection', 'spamshield-cf'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" name="honeypot_enabled" id="honeypot_enabled" value="1" <?php checked($honeypot_enabled); ?> />
                        <label for="honeypot_enabled"><?php _e('Enable honeypot spam protection', 'spamshield-cf'); ?></label>
                        <p class="description"><?php _e('Adds a hidden field that bots fill out but humans cannot see. Highly effective against automated spam.', 'spamshield-cf'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="min_time_seconds"><?php _e('Minimum Submission Time', 'spamshield-cf'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="min_time_seconds" id="min_time_seconds" value="<?php echo esc_attr($min_time_seconds); ?>" min="0" max="60" />
                        <label for="min_time_seconds"><?php _e('seconds', 'spamshield-cf'); ?></label>
                        <p class="description"><?php _e('Minimum time required between form load and submission. Bots typically submit forms instantly. Set to 0 to disable.', 'spamshield-cf'); ?></p>
                    </td>
                </tr>

                <!-- Email Settings -->
                <tr>
                    <th colspan="2"><h2><?php _e('Email Settings', 'spamshield-cf'); ?></h2></th>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="email_recipient"><?php _e('Email Recipient', 'spamshield-cf'); ?></label>
                    </th>
                    <td>
                        <input type="email" name="email_recipient" id="email_recipient" value="<?php echo esc_attr($email_recipient); ?>" class="regular-text" required />
                        <p class="description"><?php _e('Email address where contact form submissions will be sent.', 'spamshield-cf'); ?></p>
                    </td>
                </tr>

                <!-- Messages -->
                <tr>
                    <th colspan="2"><h2><?php _e('Form Messages', 'spamshield-cf'); ?></h2></th>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="success_message"><?php _e('Success Message', 'spamshield-cf'); ?></label>
                    </th>
                    <td>
                        <textarea name="success_message" id="success_message" rows="3" class="large-text"><?php echo esc_textarea($success_message); ?></textarea>
                        <p class="description"><?php _e('Message shown to users after successful form submission.', 'spamshield-cf'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Hidden field to preserve spam count -->
        <input type="hidden" name="spam_blocked_count" value="<?php echo esc_attr($spam_stats['total_blocked']); ?>" />

        <?php submit_button(__('Save Settings', 'spamshield-cf'), 'primary', 'sscf_save_settings'); ?>
    </form>

    <!-- Test Email Section -->
    <div class="card" style="max-width: none;">
        <h2 class="title"><?php _e('Test Email Functionality', 'spamshield-cf'); ?></h2>
        <p><?php _e('Send a test email to verify your contact form is working correctly.', 'spamshield-cf'); ?></p>
        
        <form method="post" action="" style="display: inline-block;">
            <?php wp_nonce_field('sscf_send_test', 'sscf_test_nonce'); ?>
            <input type="email" name="test_email" placeholder="<?php esc_attr_e('Enter email address', 'spamshield-cf'); ?>" value="<?php echo esc_attr($email_recipient); ?>" required style="width: 250px;" />
            <?php submit_button(__('Send Test Email', 'spamshield-cf'), 'secondary', 'sscf_send_test', false); ?>
        </form>
    </div>

    <!-- Support Section -->
    <div class="card" style="max-width: none;">
        <h2 class="title"><?php _e('Support & Documentation', 'spamshield-cf'); ?></h2>
        <h4><?php _e('How Spam Protection Works:', 'spamshield-cf'); ?></h4>
        <ul>
            <li><strong><?php _e('Honeypot Field:', 'spamshield-cf'); ?></strong> <?php _e('A hidden field that bots fill out but humans cannot see', 'spamshield-cf'); ?></li>
            <li><strong><?php _e('Time Validation:', 'spamshield-cf'); ?></strong> <?php _e('Rejects forms submitted too quickly (typically by bots)', 'spamshield-cf'); ?></li>
            <li><strong><?php _e('Rate Limiting:', 'spamshield-cf'); ?></strong> <?php _e('Prevents more than 5 submissions per minute from the same IP', 'spamshield-cf'); ?></li>
            <li><strong><?php _e('Content Filtering:', 'spamshield-cf'); ?></strong> <?php _e('Blocks submissions with suspicious patterns or excessive URLs', 'spamshield-cf'); ?></li>
        </ul>
        
        <h4><?php _e('Shortcode Usage:', 'spamshield-cf'); ?></h4>
        <p><?php _e('Add', 'spamshield-cf'); ?> <code>[spamshield_form]</code> <?php _e('to any page, post, or widget area to display the contact form.', 'spamshield-cf'); ?></p>
        
        <h4><?php _e('Troubleshooting:', 'spamshield-cf'); ?></h4>
        <ul>
            <li><strong><?php _e('Not receiving emails?', 'spamshield-cf'); ?></strong> <?php _e('Use the test email feature above and check your spam folder.', 'spamshield-cf'); ?></li>
            <li><strong><?php _e('Form not displaying?', 'spamshield-cf'); ?></strong> <?php _e('Make sure you\'ve added the [spamshield_form] shortcode to your page.', 'spamshield-cf'); ?></li>
            <li><strong><?php _e('Styling issues?', 'spamshield-cf'); ?></strong> <?php _e('The form is designed to work with most themes, but you can add custom CSS if needed.', 'spamshield-cf'); ?></li>
        </ul>
    </div>
</div>

<style>
/* Admin page specific styles */
.card h2.title {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}

.card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}

.form-table th {
    padding-top: 20px;
    padding-bottom: 15px;
}

.form-table td {
    padding-bottom: 15px;
}

code {
    background: #f1f1f1;
    padding: 2px 4px;
    border-radius: 3px;
    font-family: Consolas, Monaco, monospace;
}

/* Field Management Styles */
.sscf-field-row {
    display: flex;
    align-items: flex-start;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 10px;
    padding: 15px;
    position: relative;
}

.sscf-field-handle {
    cursor: move;
    color: #666;
    font-size: 18px;
    margin-right: 15px;
    padding: 5px;
    user-select: none;
}

.sscf-field-handle:hover {
    color: #333;
}

.sscf-field-content {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 120px auto;
    gap: 15px;
    align-items: center;
}

.sscf-field-group {
    display: flex;
    flex-direction: column;
}

.sscf-field-group label {
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 12px;
    color: #666;
}

.sscf-field-group input,
.sscf-field-group select {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.sscf-field-actions {
    display: flex;
    align-items: center;
}

.sscf-fields-actions {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}

.sscf-fields-actions .button {
    margin-right: 10px;
}

.sscf-field-row.dragging {
    opacity: 0.5;
}

.sscf-field-row.drag-over {
    border-color: #0073aa;
    background: #f0f6fc;
}

@media (max-width: 782px) {
    .sscf-field-content {
        grid-template-columns: 1fr;
        gap: 10px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let fieldIndex = <?php echo count($form_fields); ?>;
    
    // Add field functionality
    document.getElementById('sscf-add-field').addEventListener('click', function() {
        const container = document.getElementById('sscf-fields-container');
        const newFieldHtml = `
            <div class="sscf-field-row" data-index="${fieldIndex}">
                <div class="sscf-field-handle">⋮⋮</div>
                <div class="sscf-field-content">
                    <input type="hidden" name="fields[${fieldIndex}][id]" value="field_${fieldIndex}" />
                    
                    <div class="sscf-field-group">
                        <label><?php _e('Label:', 'spamshield-cf'); ?></label>
                        <input type="text" name="fields[${fieldIndex}][label]" value="" required />
                    </div>
                    
                    <div class="sscf-field-group">
                        <label><?php _e('Type:', 'spamshield-cf'); ?></label>
                        <select name="fields[${fieldIndex}][type]">
                            <option value="text"><?php _e('Text', 'spamshield-cf'); ?></option>
                            <option value="email"><?php _e('Email', 'spamshield-cf'); ?></option>
                            <option value="tel"><?php _e('Phone', 'spamshield-cf'); ?></option>
                            <option value="url"><?php _e('URL', 'spamshield-cf'); ?></option>
                            <option value="textarea"><?php _e('Textarea', 'spamshield-cf'); ?></option>
                        </select>
                    </div>
                    
                    <div class="sscf-field-group">
                        <label><?php _e('Placeholder:', 'spamshield-cf'); ?></label>
                        <input type="text" name="fields[${fieldIndex}][placeholder]" value="" />
                    </div>
                    
                    <div class="sscf-field-group">
                        <label>
                            <input type="checkbox" name="fields[${fieldIndex}][required]" value="1" />
                            <?php _e('Required', 'spamshield-cf'); ?>
                        </label>
                    </div>
                    
                    <div class="sscf-field-actions">
                        <button type="button" class="button sscf-remove-field"><?php _e('Remove', 'spamshield-cf'); ?></button>
                    </div>
                </div>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', newFieldHtml);
        fieldIndex++;
        
        // Re-initialize event listeners for new field
        initializeFieldEvents();
    });
    
    // Initialize field events
    function initializeFieldEvents() {
        // Remove field functionality
        document.querySelectorAll('.sscf-remove-field').forEach(function(button) {
            button.addEventListener('click', function() {
                if (confirm('<?php _e('Are you sure you want to remove this field?', 'spamshield-cf'); ?>')) {
                    button.closest('.sscf-field-row').remove();
                    updateFieldIndexes();
                }
            });
        });
        
        // Drag and drop functionality
        const fieldRows = document.querySelectorAll('.sscf-field-row');
        fieldRows.forEach(function(row) {
            row.draggable = true;
            
            row.addEventListener('dragstart', function(e) {
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/html', this.outerHTML);
            });
            
            row.addEventListener('dragend', function(e) {
                this.classList.remove('dragging');
            });
            
            row.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                this.classList.add('drag-over');
            });
            
            row.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over');
            });
            
            row.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
                
                const draggedElement = document.querySelector('.dragging');
                if (draggedElement && draggedElement !== this) {
                    const container = document.getElementById('sscf-fields-container');
                    const draggedIndex = Array.from(container.children).indexOf(draggedElement);
                    const targetIndex = Array.from(container.children).indexOf(this);
                    
                    if (draggedIndex < targetIndex) {
                        this.parentNode.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        this.parentNode.insertBefore(draggedElement, this);
                    }
                    
                    updateFieldIndexes();
                }
            });
        });
    }
    
    // Update field indexes after reordering
    function updateFieldIndexes() {
        document.querySelectorAll('.sscf-field-row').forEach(function(row, index) {
            row.dataset.index = index;
            
            // Update all input names
            row.querySelectorAll('input, select').forEach(function(input) {
                if (input.name) {
                    input.name = input.name.replace(/fields\[\d+\]/, `fields[${index}]`);
                }
            });
        });
    }
    
    // Initialize on page load
    initializeFieldEvents();
});
</script>
