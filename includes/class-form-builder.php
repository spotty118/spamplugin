<?php
/**
 * Advanced Form Builder Class
 * Drag-and-drop form builder with unlimited forms and field types
 */

if (!defined('ABSPATH')) {
    exit;
}

class SSCF_Form_Builder {
    
    private $forms_table;
    
    public function __construct() {
        global $wpdb;
        $this->forms_table = $wpdb->prefix . 'sscf_forms';
        
        // Admin hooks - menu registration handled by main analytics dashboard
        // add_action('admin_menu', array($this, 'add_admin_menu'), 15); // Disabled - integrated into main menu
        add_action('admin_enqueue_scripts', array($this, 'enqueue_builder_assets'));
        
        // AJAX hooks
        add_action('wp_ajax_sscf_save_form', array($this, 'save_form_ajax'));
        add_action('wp_ajax_sscf_delete_form', array($this, 'delete_form_ajax'));
        add_action('wp_ajax_sscf_get_form_data', array($this, 'get_form_data_ajax'));
        
        // Frontend hooks are handled by SSCF_Frontend_Form_Handler to avoid conflicts
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
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
     * Enqueue builder assets
     */
    public function enqueue_builder_assets($hook) {
        if (strpos($hook, 'spamshield-form-builder') === false && strpos($hook, 'spamshield-all-forms') === false) {
            return;
        }
        
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('jquery-ui-draggable');
        
        wp_enqueue_script(
            'sscf-form-builder',
            SSCF_PLUGIN_URL . 'assets/js/form-builder.js',
            array('jquery', 'jquery-ui-sortable', 'jquery-ui-draggable'),
            SSCF_VERSION,
            true
        );
        
        wp_enqueue_style(
            'sscf-form-builder',
            SSCF_PLUGIN_URL . 'assets/css/form-builder.css',
            array(),
            SSCF_VERSION
        );
        
        wp_localize_script('sscf-form-builder', 'sscf_builder', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sscf_builder_nonce'),
            'strings' => array(
                'saving' => __('Saving...', 'spamshield-cf'),
                'saved' => __('Form saved!', 'spamshield-cf'),
                'error' => __('Error saving form', 'spamshield-cf')
            ),
            'field_types' => $this->get_field_types()
        ));
    }
    
    /**
     * Get field types
     */
    private function get_field_types() {
        return array(
            'text' => __('Text Field', 'spamshield-cf'),
            'textarea' => __('Textarea', 'spamshield-cf'),
            'email' => __('Email', 'spamshield-cf'),
            'number' => __('Number', 'spamshield-cf'),
            'tel' => __('Phone', 'spamshield-cf'),
            'select' => __('Dropdown', 'spamshield-cf'),
            'radio' => __('Radio Buttons', 'spamshield-cf'),
            'checkbox' => __('Checkboxes', 'spamshield-cf'),
            'file' => __('File Upload', 'spamshield-cf'),
            'date' => __('Date', 'spamshield-cf')
        );
    }
    
    /**
     * Form builder page
     */
    public function form_builder_page() {
        $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $form_data = $form_id > 0 ? $this->get_form($form_id) : null;
        
        echo '<div class="wrap sscf-form-builder">';
        echo '<h1>' . ($form_id > 0 ? __('Edit Form', 'spamshield-cf') : __('Create Form', 'spamshield-cf')) . '</h1>';
        
        $this->render_form_builder($form_data);
        
        echo '</div>';
    }
    
    /**
     * All forms page
     */
    public function all_forms_page() {
        global $wpdb;
        
        $forms = $wpdb->get_results("SELECT * FROM {$this->forms_table} ORDER BY created_at DESC");
        
        echo '<div class="wrap">';
        echo '<h1>' . __('All Forms', 'spamshield-cf');
        echo '<a href="' . admin_url('admin.php?page=spamshield-form-builder') . '" class="page-title-action">' . __('Add New', 'spamshield-cf') . '</a>';
        echo '</h1>';
        
        if (empty($forms)) {
            echo '<p>' . __('No forms created yet.', 'spamshield-cf') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . __('Form Name', 'spamshield-cf') . '</th>';
            echo '<th>' . __('Shortcode', 'spamshield-cf') . '</th>';
            echo '<th>' . __('Created', 'spamshield-cf') . '</th>';
            echo '<th>' . __('Actions', 'spamshield-cf') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($forms as $form) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($form->form_name) . '</strong></td>';
                echo '<td><code>[spamshield_custom_form id="' . $form->id . '"]</code></td>';
                echo '<td>' . mysql2date('Y/m/d', $form->created_at) . '</td>';
                echo '<td>';
                echo '<a href="' . admin_url('admin.php?page=spamshield-form-builder&form_id=' . $form->id) . '">' . __('Edit', 'spamshield-cf') . '</a> | ';
                echo '<a href="#" class="delete-form" data-form-id="' . $form->id . '">' . __('Delete', 'spamshield-cf') . '</a>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '</div>';
    }
    
    /**
     * Render form builder
     */
    private function render_form_builder($form_data) {
        $form_name = $form_data ? $form_data->form_name : '';
        $form_fields = $form_data ? json_decode($form_data->form_fields, true) : array();
        
        echo '<div class="sscf-builder-container">';
        
        // Header
        echo '<div class="sscf-builder-header">';
        echo '<input type="text" id="form-name" placeholder="Form Name" value="' . esc_attr($form_name) . '">';
        echo '<button id="save-form" class="button button-primary">Save Form</button>';
        echo '</div>';
        
        // Field palette
        echo '<div class="sscf-field-palette">';
        echo '<h3>Add Fields</h3>';
        $field_types = $this->get_field_types();
        foreach ($field_types as $type => $label) {
            echo '<button class="sscf-add-field button" data-field-type="' . esc_attr($type) . '">' . esc_html($label) . '</button>';
        }
        echo '</div>';
        
        // Form canvas
        echo '<div class="sscf-form-canvas">';
        echo '<div id="form-canvas" class="sscf-canvas-area">';
        if (empty($form_fields)) {
            echo '<p>Drag fields here to build your form</p>';
        } else {
            foreach ($form_fields as $field) {
                echo $this->render_builder_field($field);
            }
        }
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        
        if ($form_data) {
            echo '<input type="hidden" id="form-id" value="' . esc_attr($form_data->id) . '">';
        }
    }
    
    /**
     * Render field in builder
     */
    private function render_builder_field($field) {
        $field_id = 'field_' . uniqid();
        $field_type = $field['type'];
        $field_label = $field['label'] ?? 'Field Label';
        
        $html = '<div class="sscf-builder-field" data-field-type="' . esc_attr($field_type) . '">';
        $html .= '<div class="sscf-field-controls">';
        $html .= '<button class="sscf-field-delete">×</button>';
        $html .= '</div>';
        $html .= '<div class="sscf-field-content">';
        $html .= '<label>' . esc_html($field_label) . '</label>';
        
        switch ($field_type) {
            case 'text':
            case 'email':
            case 'number':
            case 'tel':
                $html .= '<input type="' . esc_attr($field_type) . '" disabled>';
                break;
            case 'textarea':
                $html .= '<textarea rows="4" disabled></textarea>';
                break;
            case 'select':
                $html .= '<select disabled><option>Select option...</option></select>';
                break;
            case 'radio':
            case 'checkbox':
                $html .= '<input type="' . esc_attr($field_type) . '" disabled> Option 1';
                break;
            case 'file':
                $html .= '<input type="file" disabled>';
                break;
            case 'date':
                $html .= '<input type="date" disabled>';
                break;
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get form data
     */
    private function get_form($form_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->forms_table} WHERE id = %d", $form_id));
    }
    
    /**
     * AJAX: Save form
     */
    public function save_form_ajax() {
        check_ajax_referer('sscf_builder_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $form_id = intval($_POST['form_id'] ?? 0);
        $form_name = sanitize_text_field($_POST['form_name'] ?? '');
        $form_fields = $_POST['form_fields'] ?? array();
        $form_description = sanitize_textarea_field($_POST['form_description'] ?? '');
        $raw_settings = $_POST['form_settings'] ?? array();
        $form_settings = array();
        if (is_array($raw_settings)) {
            // Shallow sanitize known keys
            foreach ($raw_settings as $key => $val) {
                if (is_array($val)) {
                    $form_settings[$key] = array_map('sanitize_text_field', $val);
                } else {
                    $form_settings[$key] = sanitize_text_field($val);
                }
            }
        }
        
        global $wpdb;
        
        $data = array(
            'form_name' => $form_name,
            'form_description' => $form_description,
            'form_fields' => wp_json_encode($form_fields),
            'form_settings' => wp_json_encode($form_settings),
            'updated_at' => current_time('mysql')
        );
        
        if ($form_id > 0) {
            $result = $wpdb->update($this->forms_table, $data, array('id' => $form_id));
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($this->forms_table, $data);
            $form_id = $wpdb->insert_id;
        }
        
        if ($result !== false) {
            wp_send_json_success(array('form_id' => $form_id));
        } else {
            wp_send_json_error('Save failed');
        }
    }
    
    /**
     * AJAX: Delete form
     */
    public function delete_form_ajax() {
        check_ajax_referer('sscf_builder_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $form_id = intval($_POST['form_id']);
        
        global $wpdb;
        $result = $wpdb->delete($this->forms_table, array('id' => $form_id));
        
        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Delete failed');
        }
    }

    /**
     * AJAX: Get form data
     */
    public function get_form_data_ajax() {
        check_ajax_referer('sscf_builder_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $form_id = intval($_POST['form_id'] ?? 0);
        if ($form_id <= 0) {
            wp_send_json_error('Invalid form id');
        }
        
        $form = $this->get_form($form_id);
        if (!$form) {
            wp_send_json_error('Form not found');
        }
        
        $response = array(
            'id' => (int) $form->id,
            'form_name' => $form->form_name,
            'form_description' => $form->form_description,
            'form_fields' => json_decode($form->form_fields, true) ?: array(),
            'form_settings' => json_decode($form->form_settings, true) ?: array(),
            'is_active' => (int) $form->is_active
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * Shortcode: Render custom form
     */
    public function render_custom_form_shortcode($atts) {
        $atts = shortcode_atts(array('id' => 0), $atts);
        $form_id = intval($atts['id']);
        
        if ($form_id <= 0) {
            return '';
        }
        
        $form = $this->get_form($form_id);
        if (!$form) {
            return '';
        }
        
        $form_fields = json_decode($form->form_fields, true);
        if (empty($form_fields)) {
            return '';
        }
        
        $output = '<form class="sscf-custom-form" data-form-id="' . esc_attr($form_id) . '">';
        
        foreach ($form_fields as $field) {
            $output .= $this->render_frontend_field($field);
        }
        
        $output .= '<div class="sscf-form-actions">';
        $output .= '<button type="submit" class="sscf-submit-btn">Send Message</button>';
        $output .= '</div>';
        $output .= '</form>';
        
        return $output;
    }
    
    /**
     * Render frontend field
     */
    private function render_frontend_field($field) {
        $field_id = 'field_' . uniqid();
        $field_type = $field['type'];
        $field_label = $field['label'] ?? '';
        $required = !empty($field['required']);
        
        $html = '<div class="sscf-form-field">';
        if ($field_label) {
            $html .= '<label for="' . esc_attr($field_id) . '">' . esc_html($field_label);
            if ($required) $html .= ' *';
            $html .= '</label>';
        }
        
        switch ($field_type) {
            case 'text':
            case 'email':
            case 'number':
            case 'tel':
            case 'date':
                $html .= '<input type="' . esc_attr($field_type) . '" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '"' . ($required ? ' required' : '') . '>';
                break;
            case 'textarea':
                $html .= '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '" rows="4"' . ($required ? ' required' : '') . '></textarea>';
                break;
            case 'select':
                $html .= '<select id="' . esc_attr($field_id) . '" name="' . esc_attr($field_id) . '"' . ($required ? ' required' : '') . '>';
                $html .= '<option value="">Select...</option>';
                if (!empty($field['options'])) {
                    foreach ($field['options'] as $option) {
                        $html .= '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
                    }
                }
                $html .= '</select>';
                break;
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Handle form submission
     */
    public function handle_custom_form_submission() {
        // Basic submission handling
        wp_send_json_success(array('message' => 'Form submitted successfully!'));
    }
}
