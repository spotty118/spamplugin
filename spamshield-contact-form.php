<?php
/*
Plugin Name: SpamShield Contact Form
Plugin URI: https://example.com/spamshield-contact-form
Description: Simple, spam-free contact forms that just work. No complex setup, no spam headaches.
Version: 1.0.0
Author: Your Name
License: GPL v2 or later
Text Domain: spamshield-cf
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SSCF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SSCF_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SSCF_VERSION', '1.0.0');

/**
 * Main SpamShield Contact Form Class
 */
class SpamShield_Contact_Form {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        
        // Create database tables on activation
        register_activation_hook(__FILE__, array($this, 'create_tables'));
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Load includes
        $this->load_includes();
        
        // Initialize comment protection
        new SSCF_Comment_Protection();
        
        // Initialize analytics dashboard
        new SSCF_Analytics_Dashboard();
        
        // Initialize report generator
        new SSCF_Report_Generator();
        
        // Initialize form builder
        new SSCF_Form_Builder();
        
        // Initialize frontend form handler
        new SSCF_Frontend_Form_Handler();
        
        // Initialize AI detection engine
        new SSCF_AI_Detection_Engine();
        
        // Initialize database utilities
        new SSCF_Database_Utilities();
        
        // Register shortcode
        add_shortcode('spamshield_form', array($this, 'render_contact_form'));
        
        // Enqueue styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Handle form submission
        add_action('wp_ajax_sscf_submit_form', array($this, 'handle_form_submission'));
        add_action('wp_ajax_nopriv_sscf_submit_form', array($this, 'handle_form_submission'));
        
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Initialize default options
        add_action('admin_init', array($this, 'init_options'));
    }
    
    /**
     * Create database tables for storing form entries
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create form entries table
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
        
        // Create comment analytics table
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
        
        // Create custom forms table
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
        
        // Create threat patterns table for AI detection engine
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
        
        // Update database version to include threat patterns table
        add_option('sscf_db_version', '1.3');
    }
    
    /**
     * Load include files
     */
    private function load_includes() {
        require_once SSCF_PLUGIN_PATH . 'includes/class-form-handler.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-spam-protection.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-email-sender.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-comment-protection.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-analytics-dashboard.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-report-generator.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-form-builder.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-frontend-form-handler.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-ai-detection-engine.php';
        require_once SSCF_PLUGIN_PATH . 'includes/class-database-utilities.php';
    }
    
    /**
     * Enqueue CSS and JavaScript files
     */
    public function enqueue_assets() {
        wp_enqueue_style('sscf-form-styles', SSCF_PLUGIN_URL . 'assets/css/form-styles.css', array(), SSCF_VERSION);
        wp_enqueue_script('sscf-form-ajax', SSCF_PLUGIN_URL . 'assets/js/form-ajax.js', array(), SSCF_VERSION, true);
        
        // Localize script for AJAX
        wp_localize_script('sscf-form-ajax', 'sscf_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sscf_form_submit'),
            'loading_text' => __('Sending...', 'spamshield-cf'),
            'error_text' => __('There was an error sending your message. Please try again.', 'spamshield-cf')
        ));
    }
    
    /**
     * Render the contact form shortcode
     */
    public function render_contact_form($atts = array()) {
        // Get plugin options and form fields
        $options = $this->get_options();
        $form_fields = $this->get_form_fields();
        
        // Generate unique form ID for multiple forms on same page
        static $form_counter = 0;
        $form_counter++;
        $form_id = 'sscf-form-' . $form_counter;
        
        // Generate timestamp for spam protection
        $timestamp = time();
        
        ob_start();
        ?>
        <div class="sscf-form-container" id="<?php echo esc_attr($form_id); ?>-container">
            <form class="sscf-form" id="<?php echo esc_attr($form_id); ?>" method="post" action="">
                
                <!-- Nonce field for security -->
                <?php wp_nonce_field('sscf_form_submit', 'sscf_nonce'); ?>
                
                <!-- Hidden timestamp field for time-based spam protection -->
                <input type="hidden" name="sscf_timestamp" value="<?php echo esc_attr($timestamp); ?>" />
                
                <!-- Honeypot field - hidden from users but visible to bots -->
                <div class="sscf-honeypot">
                    <label for="website">Website (leave blank):</label>
                    <input type="text" name="website" id="website" value="" autocomplete="off" tabindex="-1" />
                </div>
                
                <!-- Dynamic form fields -->
                <?php foreach ($form_fields as $field): ?>
                    <div class="sscf-field">
                        <label for="sscf_<?php echo esc_attr($field['id']); ?>">
                            <?php echo esc_html($field['label']); ?>
                            <?php if ($field['required']): ?>
                                <span class="sscf-required">*</span>
                            <?php endif; ?>
                        </label>
                        
                        <?php if ($field['type'] === 'textarea'): ?>
                            <textarea 
                                name="sscf_<?php echo esc_attr($field['id']); ?>" 
                                id="sscf_<?php echo esc_attr($field['id']); ?>" 
                                rows="6"
                                <?php if ($field['required']) echo 'required'; ?>
                                <?php if (!empty($field['placeholder'])): ?>
                                    placeholder="<?php echo esc_attr($field['placeholder']); ?>"
                                <?php endif; ?>
                            ></textarea>
                        <?php elseif ($field['type'] === 'select'): ?>
                            <select 
                                name="sscf_<?php echo esc_attr($field['id']); ?>" 
                                id="sscf_<?php echo esc_attr($field['id']); ?>"
                                <?php if ($field['required']) echo 'required'; ?>
                            >
                                <?php if (!empty($field['placeholder'])): ?>
                                    <option value=""><?php echo esc_html($field['placeholder']); ?></option>
                                <?php endif; ?>
                                <?php 
                                $options = !empty($field['options']) ? explode("\n", $field['options']) : array();
                                foreach ($options as $option): 
                                    $option = trim($option);
                                    if (!empty($option)):
                                ?>
                                    <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </select>
                        <?php elseif ($field['type'] === 'radio'): ?>
                            <?php 
                            $options = !empty($field['options']) ? explode("\n", $field['options']) : array();
                            foreach ($options as $index => $option): 
                                $option = trim($option);
                                if (!empty($option)):
                            ?>
                                <div class="sscf-radio-option">
                                    <input 
                                        type="radio" 
                                        name="sscf_<?php echo esc_attr($field['id']); ?>" 
                                        id="sscf_<?php echo esc_attr($field['id']); ?>_<?php echo $index; ?>"
                                        value="<?php echo esc_attr($option); ?>"
                                        <?php if ($field['required']) echo 'required'; ?>
                                    />
                                    <label for="sscf_<?php echo esc_attr($field['id']); ?>_<?php echo $index; ?>"><?php echo esc_html($option); ?></label>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        <?php elseif ($field['type'] === 'checkbox'): ?>
                            <?php 
                            $options = !empty($field['options']) ? explode("\n", $field['options']) : array();
                            foreach ($options as $index => $option): 
                                $option = trim($option);
                                if (!empty($option)):
                            ?>
                                <div class="sscf-checkbox-option">
                                    <input 
                                        type="checkbox" 
                                        name="sscf_<?php echo esc_attr($field['id']); ?>[]" 
                                        id="sscf_<?php echo esc_attr($field['id']); ?>_<?php echo $index; ?>"
                                        value="<?php echo esc_attr($option); ?>"
                                    />
                                    <label for="sscf_<?php echo esc_attr($field['id']); ?>_<?php echo $index; ?>"><?php echo esc_html($option); ?></label>
                                </div>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        <?php elseif ($field['type'] === 'date'): ?>
                            <input 
                                type="date" 
                                name="sscf_<?php echo esc_attr($field['id']); ?>" 
                                id="sscf_<?php echo esc_attr($field['id']); ?>"
                                <?php if ($field['required']) echo 'required'; ?>
                            />
                        <?php else: ?>
                            <input 
                                type="<?php echo esc_attr($field['type']); ?>" 
                                name="sscf_<?php echo esc_attr($field['id']); ?>" 
                                id="sscf_<?php echo esc_attr($field['id']); ?>"
                                <?php if ($field['required']) echo 'required'; ?>
                                <?php if (!empty($field['placeholder'])): ?>
                                    placeholder="<?php echo esc_attr($field['placeholder']); ?>"
                                <?php endif; ?>
                            />
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <!-- Submit button -->
                <div class="sscf-field">
                    <button type="submit" class="sscf-submit-btn">
                        <span class="sscf-submit-text"><?php _e('Send Message', 'spamshield-cf'); ?></span>
                        <span class="sscf-loading-text" style="display: none;"><?php _e('Sending...', 'spamshield-cf'); ?></span>
                    </button>
                </div>
                
                <!-- Message area for success/error messages -->
                <div class="sscf-message" style="display: none;"></div>
                
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle AJAX form submission
     */
    public function handle_form_submission() {
        // Verify nonce
        if (empty($_POST['sscf_nonce']) || !wp_verify_nonce($_POST['sscf_nonce'], 'sscf_form_submit')) {
            wp_die('Security check failed', 'Unauthorized', array('response' => 401));
        }
        
        $form_handler = new SSCF_Form_Handler();
        $result = $form_handler->process_submission($_POST);
        
        wp_send_json($result);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main settings page
        add_options_page(
            __('SpamShield Contact Form', 'spamshield-cf'),
            __('SpamShield Contact Form', 'spamshield-cf'),
            'manage_options',
            'spamshield-contact-form',
            array($this, 'admin_page')
        );
        
        // Entries viewer page - now handled by unified SpamShield menu
        // add_management_page() removed to prevent duplicate menu items
    }
    
    /**
     * Admin page callback
     */
    public function admin_page() {
        require_once SSCF_PLUGIN_PATH . 'admin/settings-page.php';
    }
    
    /**
     * Entries page callback
     */
    public function entries_page() {
        require_once SSCF_PLUGIN_PATH . 'admin/entries-page.php';
    }
    
    /**
     * Initialize default options
     */
    public function init_options() {
        $default_options = array(
            'honeypot_enabled' => true,
            'min_time_seconds' => 3,
            'email_recipient' => get_option('admin_email'),
            'success_message' => __('Thank you! Your message has been sent successfully.', 'spamshield-cf'),
            'spam_blocked_count' => 0
        );
        
        if (!get_option('sscf_options')) {
            add_option('sscf_options', $default_options);
        }
        
        // Initialize default form fields if not set
        if (!get_option('sscf_form_fields')) {
            $default_fields = array(
                array(
                    'id' => 'name',
                    'label' => __('Name', 'spamshield-cf'),
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => '',
                    'order' => 1
                ),
                array(
                    'id' => 'email',
                    'label' => __('Email', 'spamshield-cf'),
                    'type' => 'email',
                    'required' => true,
                    'placeholder' => '',
                    'order' => 2
                ),
                array(
                    'id' => 'subject',
                    'label' => __('Subject', 'spamshield-cf'),
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => '',
                    'order' => 3
                ),
                array(
                    'id' => 'message',
                    'label' => __('Message', 'spamshield-cf'),
                    'type' => 'textarea',
                    'required' => true,
                    'placeholder' => '',
                    'order' => 4,
                    'options' => ''
                )
            );
            add_option('sscf_form_fields', $default_fields);
        }
    }
    
    /**
     * Get plugin options with caching
     */
    public function get_options() {
        static $cached_options = null;
        
        if ($cached_options === null) {
            $cached_options = get_option('sscf_options', array());
        }
        
        return $cached_options;
    }
    
    /**
     * Get form fields with caching
     */
    public function get_form_fields() {
        static $cached_fields = null;
        
        if ($cached_fields === null) {
            $cached_fields = get_option('sscf_form_fields', array());
            
            // Sort fields by order for consistency
            usort($cached_fields, function($a, $b) {
                return ($a['order'] ?? 0) - ($b['order'] ?? 0);
            });
        }
        
        return $cached_fields;
    }
}

// Initialize the plugin
global $spamshield_contact_form;
$spamshield_contact_form = new SpamShield_Contact_Form();
