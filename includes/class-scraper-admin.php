<?php
/**
 * Web Scraper Admin Settings Page - With Profiles Support
 * 
 * INSTALLATION:
 * Place this file in: wp-content/mu-plugins/scraper-admin.php
 * 
 * USAGE:
 * 1. Go to Tools → Scraper Settings in WordPress admin
 * 2. Create and save multiple scraper profiles
 * 3. Use in WP-CLI: wp scraper run --profile=profile-name --live
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Web_Scraper_Admin_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_form_submission']); // Handle forms BEFORE output
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
        add_action('wp_ajax_delete_scraper_profile', [$this, 'ajax_delete_profile']);
        add_action('wp_ajax_duplicate_scraper_profile', [$this, 'ajax_duplicate_profile']);
        add_action('wp_ajax_test_run_scraper_profile', [$this, 'ajax_test_run_profile']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            'Scraper Settings',
            'Scraper Settings',
            'manage_options',
            'scraper-settings',
            [$this, 'render_settings_page']
        );
    }
    
    
    /**
     * AJAX handler for deleting a profile
     */
    public function ajax_delete_profile() {
        check_ajax_referer('scraper_profile_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $profile_key = sanitize_key($_POST['profile_key']);
        $profiles = get_option('scraper_profiles', []);
        
        if (isset($profiles[$profile_key])) {
            unset($profiles[$profile_key]);
            update_option('scraper_profiles', $profiles);

            // Update cron schedules after deletion
            Scraper_Cron::update_schedules();

            wp_send_json_success(['message' => 'Profile deleted successfully']);
        } else {
            wp_send_json_error(['message' => 'Profile not found']);
        }
    }
    
    /**
     * AJAX handler for duplicating a profile
     */
    public function ajax_duplicate_profile() {
        check_ajax_referer('scraper_profile_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $profile_key = sanitize_key($_POST['profile_key']);
        $profiles = get_option('scraper_profiles', []);
        
        if (isset($profiles[$profile_key])) {
            $original = $profiles[$profile_key];
            $new_name = $original['profile_name'] . ' (Copy)';
            $new_key = sanitize_key($new_name . '-' . time());
            
            $profiles[$new_key] = $original;
            $profiles[$new_key]['profile_name'] = $new_name;
            
            update_option('scraper_profiles', $profiles);
            wp_send_json_success([
                'message' => 'Profile duplicated successfully',
                'redirect' => admin_url('tools.php?page=scraper-settings&profile=' . $new_key)
            ]);
        } else {
            wp_send_json_error(['message' => 'Profile not found']);
        }
    }

    /**
     * AJAX handler for test running a profile
     */
    public function ajax_test_run_profile() {
        // Log the start of the AJAX request
        error_log('Test run AJAX handler called');

        check_ajax_referer('scraper_profile_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('Test run: Insufficient permissions');
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $profile_key = sanitize_key($_POST['profile_key']);
        $live_mode = isset($_POST['live']) && filter_var($_POST['live'], FILTER_VALIDATE_BOOLEAN);

        error_log("Test run: Profile key = {$profile_key}, Live mode = " . ($live_mode ? 'true' : 'false'));

        $profiles = get_option('scraper_profiles', []);

        if (!isset($profiles[$profile_key])) {
            error_log("Test run: Profile '{$profile_key}' not found");
            wp_send_json_error(['message' => 'Profile not found']);
        }

        // Validate profile has required fields
        $profile = $profiles[$profile_key];
        $required_fields = ['source_url', 'listing_xpath', 'title_xpath', 'content_xpath', 'date_xpath'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (empty($profile[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            $error_msg = 'Profile is missing required fields: ' . implode(', ', $missing_fields);
            error_log("Test run: {$error_msg}");
            wp_send_json_error(['message' => $error_msg]);
        }

        error_log('Test run: Loading WP-CLI shim and scraper command');

        // Load WP-CLI shim and scraper command
        try {
            require_once GWS_PLUGIN_DIR . 'includes/wp-cli-shim.php';
            error_log('Test run: WP-CLI shim loaded');

            // Verify the function exists
            if (!method_exists('WP_CLI\Utils', 'get_flag_value')) {
                error_log('Test run: get_flag_value() method does NOT exist in WP_CLI\Utils');
                // Try to check what methods do exist
                if (class_exists('WP_CLI\Utils')) {
                    $methods = get_class_methods('WP_CLI\Utils');
                    error_log('Test run: WP_CLI\Utils methods: ' . implode(', ', $methods));
                }
            } else {
                error_log('Test run: get_flag_value() method EXISTS in WP_CLI\Utils');
            }

            require_once GWS_PLUGIN_DIR . 'includes/class-scraper-command.php';
            error_log('Test run: Scraper command class loaded');
        } catch (Exception $e) {
            error_log('Test run: Failed to load classes: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Failed to load scraper classes: ' . $e->getMessage()]);
        }

        // Check if class exists
        if (!class_exists('Generic_Scraper_Command')) {
            error_log('Test run: Generic_Scraper_Command class does not exist');
            wp_send_json_error(['message' => 'Scraper command class not found']);
        }

        // Set longer timeout for scraping
        set_time_limit(300); // 5 minutes
        error_log('Test run: Starting scraper execution');

        try {
            // Create command instance
            $command = new Generic_Scraper_Command();
            error_log('Test run: Command instance created');

            // Build arguments
            $args = [];
            $assoc_args = [
                'profile' => $profile_key,
                'live' => $live_mode,
            ];

            // Capture output
            ob_start();
            $command->run($args, $assoc_args);
            $output = ob_get_clean();

            error_log('Test run: Scraper execution completed');

            // Get stats from command
            $stats = $command->get_stats();
            error_log('Test run: Stats retrieved: ' . json_encode($stats));

            wp_send_json_success([
                'message' => $live_mode ? 'Scrape completed successfully' : 'Dry run completed successfully',
                'stats' => $stats,
                'output' => $output,
                'live_mode' => $live_mode,
            ]);

        } catch (Exception $e) {
            error_log('Test run: Exception caught: ' . $e->getMessage());
            error_log('Test run: Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => 'Scrape failed: ' . $e->getMessage(),
            ]);
        } catch (Error $e) {
            error_log('Test run: Fatal error caught: ' . $e->getMessage());
            error_log('Test run: Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => 'Fatal error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle form submission early (before any output)
     */
    public function handle_form_submission() {
        // Only process on our settings page
        if (!isset($_GET['page']) || $_GET['page'] !== 'scraper-settings') {
            return;
        }
        
        // Check if form was submitted
        if (!isset($_POST['scraper_profiles']) || !isset($_POST['scraper_profile_nonce'])) {
            return;
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['scraper_profile_nonce'], 'scraper_save_profile')) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get existing profiles
        $profiles = get_option('scraper_profiles', []);
        if (!is_array($profiles)) {
            $profiles = [];
        }
        
        $submitted_data = $_POST['scraper_profiles'];
        
        // Get the profile key we're editing
        $editing_profile_key = isset($_GET['profile']) ? sanitize_key($_GET['profile']) : 'default';
        
        // Sanitize and save
        $profile_name = sanitize_text_field($submitted_data['profile_name']);
        $new_profile_key = sanitize_key($profile_name);
        
        // Fallback if sanitize_key returns empty (e.g., all special chars)
        if (empty($new_profile_key)) {
            $new_profile_key = 'profile-' . time();
        }
        
        // If profile name changed, remove old key (but only if it was a temp "new-" key)
        if ($new_profile_key !== $editing_profile_key && strpos($editing_profile_key, 'new-') === 0) {
            unset($profiles[$editing_profile_key]);
        }

        // Save the profile data
        $profiles[$new_profile_key] = $this->sanitize_profile_data($submitted_data);
        update_option('scraper_profiles', $profiles);

        // Update cron schedules after saving
        Scraper_Cron::update_schedules();

        // Redirect to avoid resubmission
        wp_redirect(admin_url('tools.php?page=scraper-settings&profile=' . $new_profile_key . '&updated=true'));
        exit;
    }
    
    /**
     * Enqueue styles
     */
    public function enqueue_styles($hook) {
        if ($hook !== 'tools_page_scraper-settings') {
            return;
        }
        
        $css = "
        .scraper-settings-wrap {
            max-width: 900px;
        }
        .scraper-settings-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 25px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .scraper-settings-card h2 {
            margin-top: 0;
            color: #2563eb;
            border-bottom: 2px solid #dbeafe;
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* Profile Management Styles */
        .profile-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 2px solid #dbeafe;
            padding-bottom: 10px;
        }
        .profile-tab {
            padding: 10px 20px;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 6px 6px 0 0;
            cursor: pointer;
            text-decoration: none;
            color: #374151;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .profile-tab:hover {
            background: #e5e7eb;
            color: #1f2937;
        }
        .profile-tab.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        .profile-tab-icon {
            font-size: 16px;
        }
        .new-profile-tab {
            background: #10b981;
            color: white;
            border-color: #10b981;
        }
        .new-profile-tab:hover {
            background: #059669;
            color: white;
        }
        
        .profile-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .profile-action-btn {
            padding: 8px 16px;
            border-radius: 4px;
            border: 1px solid;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-duplicate {
            background: #f3f4f6;
            border-color: #d1d5db;
            color: #374151;
        }
        .btn-duplicate:hover {
            background: #e5e7eb;
        }
        .btn-delete {
            background: #fee;
            border-color: #fcc;
            color: #dc2626;
        }
        .btn-delete:hover {
            background: #fdd;
        }
        .btn-test-run {
            background: #10b981;
            border-color: #059669;
            color: white;
            font-weight: 600;
        }
        .btn-test-run:hover {
            background: #059669;
        }
        .btn-test-run:disabled {
            background: #9ca3af;
            border-color: #6b7280;
            cursor: not-allowed;
        }

        /* Test Run Modal */
        .test-run-modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
        }
        .test-run-modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border: 1px solid #888;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        }
        .test-run-modal-header {
            padding: 20px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .test-run-modal-header h2 {
            margin: 0;
            color: white;
            border: none;
            padding: 0;
        }
        .test-run-modal-close {
            color: white;
            font-size: 32px;
            font-weight: bold;
            line-height: 1;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s;
        }
        .test-run-modal-close:hover {
            background: rgba(255,255,255,0.2);
        }
        .test-run-modal-body {
            padding: 30px;
        }
        .test-run-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        .stat-card.success {
            background: #d1fae5;
            border-color: #10b981;
        }
        .stat-card.warning {
            background: #fef3c7;
            border-color: #f59e0b;
        }
        .stat-card.error {
            background: #fee2e2;
            border-color: #ef4444;
        }
        .stat-card .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
        }
        .dry-run-checkbox {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dry-run-checkbox label {
            font-weight: 600;
            color: #92400e;
            margin: 0;
            cursor: pointer;
        }
        .dry-run-checkbox input[type='checkbox'] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .live-mode-warning {
            background: #fee2e2;
            border: 2px solid #ef4444;
            color: #991b1b;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: none;
        }
        .live-mode-warning.show {
            display: block;
        }

        .profile-name-field {
            background: #f0f9ff;
            border: 2px solid #2563eb;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .profile-name-field label {
            display: block;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 8px;
        }
        .profile-name-field input {
            width: 100%;
            max-width: 500px;
            padding: 8px 12px;
            font-size: 16px;
            border: 1px solid #93c5fd;
            border-radius: 4px;
        }
        .profile-name-help {
            font-size: 13px;
            color: #1e40af;
            margin-top: 5px;
        }
        
        .form-table th {
            padding: 15px 10px 15px 0;
            width: 200px;
        }
        .form-table td input[type='text'],
        .form-table td input[type='url'],
        .form-table td select {
            width: 100%;
            max-width: 600px;
        }
            
        .form-table td input[type='text'].small-text {
            max-width: 120px;
        }

         .form-table td[colspan] {
            padding-left: 0;            
            }
        .form-table tr:not(:first-child) td[colspan] {
            border-top: 2px solid #dbeafe;
        }

         .form-table h3 {

            margin: 0;            
        }
        .help-text {
            color: #6b7280;
            font-size: 13px;
            font-style: italic;
            margin-top: 5px;
        }
        .command-preview {
            background: #1f2937;
            color: #f3f4f6;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 13px;
        }
        .command-preview code {
            color: #10b981;
        }
        .command-preview .profile-name-highlight {
            color: #fbbf24;
            font-weight: bold;
        }
        .notice-info {
            border-left-color: #2563eb;
        }
        
        /* Modal Styles */
        .xpath-modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6);
        }
        .xpath-modal-content {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 0;
            border: 1px solid #888;
            border-radius: 8px;
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        }
        .xpath-modal-header {
            padding: 20px;
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .xpath-modal-header h2 {
            margin: 0;
            color: white;
            border: none;
            padding: 0;
        }
        .xpath-modal-close {
            color: white;
            font-size: 32px;
            font-weight: bold;
            line-height: 1;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s;
        }
        .xpath-modal-close:hover,
        .xpath-modal-close:focus {
            background: rgba(255,255,255,0.2);
        }
        .xpath-modal-body {
            padding: 30px;
            line-height: 1.6;
        }
        .xpath-modal-body h3 {
            color: #1f2937;
            margin-top: 30px;
            margin-bottom: 15px;
            font-size: 1.3em;
        }
        .xpath-modal-body h3:first-child {
            margin-top: 0;
        }
        .xpath-modal-body table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
        }
        .xpath-modal-body th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
        }
        .xpath-modal-body td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .xpath-modal-body tr:last-child td {
            border-bottom: none;
        }
        .xpath-modal-body code {
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.9em;
            color: #ef4444;
        }
        .xpath-modal-body pre {
            background: #1f2937;
            color: #f3f4f6;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            margin: 15px 0;
        }
        .xpath-modal-body pre code {
            background: none;
            padding: 0;
            color: #f3f4f6;
        }
        .xpath-modal-body .example-box {
            background: #f0f9ff;
            border-left: 4px solid #2563eb;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .xpath-modal-body .callout {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .xpath-help-link {
            font-size: 14px;
            font-weight: normal;
            color: #2563eb;
            text-decoration: none;
            border: 1px solid #2563eb;
            padding: 4px 12px;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .xpath-help-link:hover {
            background: #2563eb;
            color: white;
        }
        .filter-toggle-btn {
            padding: 8px 16px;
            border: 1px solid #d1d5db;
            background: #f3f4f6;
            color: #374151;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .filter-toggle-btn:first-child {
            border-radius: 4px 0 0 4px;
        }
        .filter-toggle-btn:last-child {
            border-radius: 0 4px 4px 0;
        }
        .filter-toggle-btn.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        .filter-toggle-btn:hover:not(.active) {
            background: #e5e7eb;
        }

        /* Cron Scheduling Styles */
        .cron-settings-row {
            background: #f9fafb;
        }
        .cron-settings-row th,
        .cron-settings-row td {
            padding-top: 20px;
            padding-bottom: 20px;
        }
        ";

        wp_add_inline_style('wp-admin', $css);
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Get all profiles - LOAD FIRST before any processing
        $profiles = get_option('scraper_profiles', []);
        
        // Ensure $profiles is always an array
        if (!is_array($profiles)) {
            $profiles = [];
        }
        
        // Clean up any unnamed or invalid profiles that may have been created accidentally
        // BUT ONLY if we're not currently submitting a form
        if (!isset($_POST['scraper_profiles'])) {
            $cleaned = false;
            foreach ($profiles as $key => $profile) {
                if (!is_array($profile) || 
                    empty($profile['profile_name']) || 
                    $profile['profile_name'] === 'Unnamed Profile') {
                    unset($profiles[$key]);
                    $cleaned = true;
                }
            }
            if ($cleaned) {
                update_option('scraper_profiles', $profiles);
            }
        }
        
        // Determine current profile
        $current_profile_key = isset($_GET['profile']) ? sanitize_key($_GET['profile']) : '';
        
        // Check if this is a "new profile" request
        $is_new_profile = !empty($current_profile_key) && strpos($current_profile_key, 'new-') === 0;
        
        // If no profile specified or it's a new profile request
        if (empty($current_profile_key) || $is_new_profile || !isset($profiles[$current_profile_key])) {
            if ($is_new_profile) {
                // Creating a new profile - use empty template
                $current_profile_key = 'new-' . time();
                $current_profile = $this->get_default_profile_data();
                $current_profile['profile_name'] = 'New Profile';
                // DON'T save to database yet - only save when form is submitted
            } elseif (empty($profiles)) {
                // No profiles exist at all - create first one
                $current_profile_key = 'default';
                $current_profile = $this->get_default_profile_data();
                // DON'T save yet - wait for user to fill in and submit
            } else {
                // Use first existing profile
                $current_profile_key = array_key_first($profiles);
                $current_profile = $profiles[$current_profile_key];
            }
        } else {
            // Load existing profile
            $current_profile = $profiles[$current_profile_key];
        }
        
        // Ensure current_profile is always an array
        if (!is_array($current_profile)) {
            $current_profile = $this->get_default_profile_data();
            $current_profile['profile_name'] = 'Default Profile';
        }
        
        ?>
        <div class="wrap scraper-settings-wrap">
            <h1>🔧 Web Scraper Settings</h1>
            
            <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Profile saved successfully!</strong></p>
            </div>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <p><strong>How to use:</strong> Save your profile, then run: <code>wp scraper run --profile=<span class="profile-name-highlight"><?php echo esc_html($current_profile_key); ?></span> --live</code></p>
            </div>
            
            <!-- Profile Tabs -->
            <div class="profile-tabs">
                <?php if (is_array($profiles) && !empty($profiles)): ?>
                    <?php foreach ($profiles as $key => $profile): ?>
                    <a href="<?php echo admin_url('tools.php?page=scraper-settings&profile=' . $key); ?>" 
                       class="profile-tab <?php echo ($key === $current_profile_key) ? 'active' : ''; ?>">
                        <span class="profile-tab-icon">📋</span>
                        <?php echo esc_html(is_array($profile) && isset($profile['profile_name']) ? $profile['profile_name'] : 'Unnamed Profile'); ?>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <a href="<?php echo admin_url('tools.php?page=scraper-settings&profile=new-' . time()); ?>" 
                   class="profile-tab new-profile-tab">
                    <span class="profile-tab-icon">➕</span>
                    New Profile
                </a>
            </div>
            
            <div class="scraper-settings-card">
                <!-- Profile Actions -->
                <?php if (count($profiles) > 0): ?>
                <div class="profile-actions">
                    <?php if (!empty($current_profile['source_url'])): ?>
                    <button type="button" class="profile-action-btn btn-test-run" data-profile="<?php echo esc_attr($current_profile_key); ?>">
                        ▶️ Test Run Now
                    </button>
                    <?php endif; ?>
                    <button type="button" class="profile-action-btn btn-duplicate" data-profile="<?php echo esc_attr($current_profile_key); ?>">
                        📑 Duplicate Profile
                    </button>
                    <?php if (count($profiles) > 1): ?>
                    <button type="button" class="profile-action-btn btn-delete" data-profile="<?php echo esc_attr($current_profile_key); ?>">
                        🗑️ Delete Profile
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <?php wp_nonce_field('scraper_save_profile', 'scraper_profile_nonce'); ?>
                    
                    <!-- Profile Name (Prominent) -->
                    <div class="profile-name-field">
                        <label for="profile_name">Profile Name *</label>
                        <input type="text" 
                               name="scraper_profiles[profile_name]" 
                               id="profile_name" 
                               value="<?php echo esc_attr($current_profile['profile_name'] ?? ''); ?>"
                               required
                               placeholder="e.g., Press Releases, Blog Posts, News Articles">
                        <p class="profile-name-help">This name will be used in the command: <code>--profile=<?php echo esc_attr($current_profile_key); ?></code></p>
                    </div>
                    
                    <h2>Basic Settings</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="source_url">Source URL *</label>
                            </th>
                            <td>
                                <input type="url" 
                                       name="scraper_profiles[source_url]" 
                                       id="source_url" 
                                       value="<?php echo esc_attr($current_profile['source_url'] ?? ''); ?>"
                                       class="regular-text" 
                                       placeholder="https://example.com/news">
                                <p class="help-text">URL of the archive to scrape</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="post_type">Post Type *</label>
                            </th>
                            <td>
                                <select name="scraper_profiles[post_type]" id="post_type">
                                    <option value="post" <?php selected($current_profile['post_type'] ?? 'post', 'post'); ?>>Post</option>
                                    <option value="page" <?php selected($current_profile['post_type'] ?? 'post', 'page'); ?>>Page</option>
                                    <?php
                                    $custom_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
                                    foreach ($custom_types as $type) {
                                        echo '<option value="' . esc_attr($type->name) . '" ' . selected($current_profile['post_type'] ?? 'post', $type->name, false) . '>' . esc_html($type->label) . '</option>';
                                    }
                                    ?>
                                </select>
                                <p class="help-text">WordPress post type to create</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h2>
                        <span>XPath Selectors</span>
                        <a href="#" class="xpath-help-link" id="open-xpath-help">📖 How to find XPath selectors</a>
                    </h2>
                    <table class="form-table">
                        <tr>
                            
                            <td colspan="2">
                                <h3>From the Source Archive</h3>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="listing_xpath">Post XPath *</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[listing_xpath]" 
                                       id="listing_xpath" 
                                       value="<?php echo esc_attr($current_profile['listing_xpath'] ?? ''); ?>"
                                       class="regular-text" 
                                       placeholder="//a[contains(@href, '/articles/')]">
                                <p class="help-text">XPath to find links to each post on the archive</p>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <h3>From the Source Post Single</h3>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="title_xpath">Title XPath *</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[title_xpath]" 
                                       id="title_xpath" 
                                       value="<?php echo esc_attr($current_profile['title_xpath'] ?? ''); ?>"
                                       class="regular-text" 
                                       placeholder="//h1">
                                <p class="help-text">XPath to extract post title</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="content_xpath">Content XPath *</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[content_xpath]" 
                                       id="content_xpath" 
                                       value="<?php echo esc_attr($current_profile['content_xpath'] ?? ''); ?>"
                                       class="regular-text" 
                                       placeholder="//article">
                                <p class="help-text">XPath to extract post content</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="date_xpath">Date XPath *</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[date_xpath]" 
                                       id="date_xpath" 
                                       value="<?php echo esc_attr($current_profile['date_xpath'] ?? ''); ?>"
                                       class="regular-text" 
                                       placeholder="//time">
                                <p class="help-text">XPath to extract post date</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="excerpt_xpath">Excerpt XPath</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[excerpt_xpath]" 
                                       id="excerpt_xpath" 
                                       value="<?php echo esc_attr($current_profile['excerpt_xpath'] ?? ''); ?>"
                                       class="regular-text"
                                       placeholder="//p[@class='excerpt']">
                                <p class="help-text">Optional: Auto-generated if empty</p>
                            </td>
                        </tr>
                         <tr>
                            <th scope="row">
                                <label for="image_xpath">Featured Image XPath</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[image_xpath]" 
                                       id="image_xpath" 
                                       value="<?php echo esc_attr($current_profile['image_xpath'] ?? ''); ?>"
                                       class="regular-text"
                                       placeholder="//img[@class='featured-image']">
                                <p class="help-text">Optional: Auto-detects first image if empty</p>
                            </td>
                        </tr>
                         <tr>
                            <th scope="row">
                                <label for="content_gate_xpath">Content Gate XPath</label>
                            </th>
                            <td>
                                <input type="text"
                                       name="scraper_profiles[content_gate_xpath]"
                                       id="content_gate_xpath"
                                       value="<?php echo esc_attr($current_profile['content_gate_xpath'] ?? ''); ?>"
                                       class="regular-text"
                                       placeholder="//span[@class='release-type']">
                                <p class="help-text">Optional: XPath to check before importing. If set, the element's text must match one of the allowed values below.</p>
                            </td>
                        </tr>
                        <tr id="content-gate-values-row" style="<?php echo empty($current_profile['content_gate_xpath'] ?? '') ? 'display:none;' : ''; ?>">
                            <th scope="row">
                                <label for="content_gate_values">Allowed Values</label>
                            </th>
                            <td>
                                <textarea
                                       name="scraper_profiles[content_gate_values]"
                                       id="content_gate_values"
                                       rows="3"
                                       class="large-text"
                                       placeholder="Press Release
Earnings Report
Product Launch"><?php echo esc_textarea($current_profile['content_gate_values'] ?? ''); ?></textarea>
                                <p class="help-text">One value per line. Article is skipped if the Content Gate XPath text doesn't exactly match any of these.</p>
                            </td>
                        </tr>
                         <tr>
                            <th scope="row">Content Filter</th>
                            <td>
                                <?php $filter_mode = $current_profile['content_filter_mode'] ?? 'exclude'; ?>
                                <input type="hidden" name="scraper_profiles[content_filter_mode]" id="content_filter_mode" value="<?php echo esc_attr($filter_mode); ?>">
                                <div style="display: flex; gap: 4px; margin-bottom: 12px;">
                                    <button type="button" class="filter-toggle-btn <?php echo $filter_mode === 'exclude' ? 'active' : ''; ?>" data-mode="exclude">Exclude Elements</button>
                                    <button type="button" class="filter-toggle-btn <?php echo $filter_mode === 'include' ? 'active' : ''; ?>" data-mode="include">Only Include Elements</button>
                                </div>
                                <div id="exclude-fields" style="<?php echo $filter_mode === 'include' ? 'display:none;' : ''; ?>">
                                    <textarea
                                           name="scraper_profiles[remove_xpaths]"
                                           id="remove_xpaths"
                                           rows="3"
                                           class="large-text"
                                           placeholder="//div[@class='ads']
//script
//iframe"><?php echo esc_textarea($current_profile['remove_xpaths'] ?? ''); ?></textarea>
                                    <p class="help-text">XPath selectors for elements to <strong>remove</strong> from content (one per line)</p>
                                </div>
                                <div id="include-fields" style="<?php echo $filter_mode === 'exclude' ? 'display:none;' : ''; ?>">
                                    <textarea
                                           name="scraper_profiles[include_xpaths]"
                                           id="include_xpaths"
                                           rows="6"
                                           class="large-text"
                                           placeholder="//p
//h2
//ul"><?php echo esc_textarea($current_profile['include_xpaths'] ?? ''); ?></textarea>
                                    <div class="help-text" style="margin-top: 10px;">
                                        <p>Only elements matching these selectors (within the Content XPath container) will be kept. Everything else is discarded. One selector per line.</p>
                                        <p style="margin-top: 8px;"><strong>Three syntax options:</strong></p>
                                        <table style="border-collapse: collapse; margin-top: 4px; font-size: 13px; width: 100%;">
                                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                                <td style="padding: 6px 10px; font-weight: 600; white-space: nowrap; vertical-align: top;">Basic</td>
                                                <td style="padding: 6px 10px;"><code>//p</code><br>Includes matching elements as-is</td>
                                            </tr>
                                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                                <td style="padding: 6px 10px; font-weight: 600; white-space: nowrap; vertical-align: top;">Wrap</td>
                                                <td style="padding: 6px 10px;"><code>//img[1] | &lt;figure&gt; | &lt;/figure&gt;</code><br>Wraps each matched element in the given opening/closing HTML (pipe-separated)</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 6px 10px; font-weight: 600; white-space: nowrap; vertical-align: top;">Raw HTML</td>
                                                <td style="padding: 6px 10px;"><code>html: &lt;div class="wrapper"&gt;</code><br>Injects literal HTML into the output. Use to group multiple elements in a container.</td>
                                            </tr>
                                        </table>
                                        <p style="margin-top: 10px;"><strong>Example</strong> &mdash; image + caption grouped in a figure:</p>
                                        <pre style="background: #1f2937; color: #f3f4f6; padding: 10px 12px; border-radius: 4px; font-size: 12px; margin-top: 4px; line-height: 1.6;">html: &lt;figure class="wp-block-image"&gt;
//img[1]
//figcaption
html: &lt;/figure&gt;
//p
//h2</pre>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>
                    
                    <h2>Additional Options</h2>
                    <table class="form-table">
                        <tr class="taxonomy-row">
                            <th scope="row">
                                <label for="categories">Categories</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[categories]" 
                                       id="categories" 
                                       value="<?php echo esc_attr($current_profile['categories'] ?? ''); ?>"
                                       class="regular-text"
                                       placeholder="News, Press Releases, Updates">
                                <p class="help-text">Category names for imported posts (comma-separated)</p>
                            </td>
                        </tr>
                        
                        <tr class="taxonomy-row">
                            <th scope="row">
                                <label for="tags">Tags</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[tags]" 
                                       id="tags" 
                                       value="<?php echo esc_attr($current_profile['tags'] ?? ''); ?>"
                                       class="regular-text"
                                       placeholder="Industry News, Featured, Breaking">
                                <p class="help-text">Tag names for imported posts (comma-separated)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="post_status">Post Status</label>
                            </th>
                            <td>
                                <select name="scraper_profiles[post_status]" id="post_status">
                                    <option value="publish" <?php selected($current_profile['post_status'] ?? 'publish', 'publish'); ?>>Publish</option>
                                    <option value="draft" <?php selected($current_profile['post_status'] ?? 'publish', 'draft'); ?>>Draft</option>
                                </select>
                                <p class="help-text">Default status for imported posts</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="start_page">Start Page</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[start_page]" 
                                       id="start_page" 
                                       value="<?php echo esc_attr($current_profile['start_page'] ?? '1'); ?>"
                                       min="1"
                                       class="small-text">
                                       <p class="help-text">Archive page number from which the extraction should begin (if unknown, or not used on source site use 1)</p>                                       
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="max_pages">Number of Pages</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[max_pages]" 
                                       id="max_pages" 
                                       value="<?php echo esc_attr($current_profile['max_pages'] ?? '1'); ?>"
                                       min="1"
                                       class="small-text">
                                       <p class="help-text">Number of pages to pull (Default is 1)</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="posts_per_batch">Posts Per Batch</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[posts_per_batch]" 
                                       id="posts_per_batch" 
                                       value="<?php echo esc_attr($current_profile['posts_per_batch'] ?? '9'); ?>"
                                       min="1"
                                       class="small-text">
                                       <p class="help-text">a value of 0 or leaving this blank will grab all posts within the limits set above </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="page_param">Pagination Format</label>
                            </th>
                            <td>
                                <?php
                                $current_page_param = $current_profile['page_param'] ?? 'page';
                                $common_formats = [
                                    'page' => 'Query Parameter (?page=2)',
                                    '/page/{page}/' => 'Path-Based (/page/2/)',
                                ];
                                
                                // Check if current value is a common format or custom
                                $is_custom = !array_key_exists($current_page_param, $common_formats);
                                $select_value = $is_custom ? 'custom' : $current_page_param;
                                ?>
                                
                                <select name="scraper_profiles[page_param]" 
                                        id="page_param_select" 
                                        class="regular-text"
                                        onchange="toggleCustomPageParam(this)">
                                    <option value="page" <?php selected($select_value, 'page'); ?>>
                                        Query Parameter (?page=2)
                                    </option>
                                    <option value="/page/{page}/" <?php selected($select_value, '/page/{page}/'); ?>>
                                        Path-Based (/page/2/)
                                    </option>
                                    <option value="__custom__" <?php selected($select_value, 'custom'); ?>>
                                        Custom Format...
                                    </option>
                                </select>
                                
                                <div id="custom_page_param_wrapper" style="margin-top: 10px; <?php echo $is_custom ? '' : 'display: none;'; ?>">
                                    <input type="text" 
                                           id="page_param_custom" 
                                           value="<?php echo $is_custom ? esc_attr($current_page_param) : ''; ?>"
                                           class="regular-text"
                                           placeholder="e.g., p or /{page}/">
                                    <p class="help-text">
                                        <strong>Query examples:</strong> <code>p</code>, <code>pg</code>, <code>offset</code><br>
                                        <strong>Path examples:</strong> <code>/{page}/</code>, <code>/p/{page}/</code>
                                    </p>
                                </div>
                                
                                <script>
                                function toggleCustomPageParam(selectElement) {
                                    var customWrapper = document.getElementById('custom_page_param_wrapper');
                                    var customInput = document.getElementById('page_param_custom');
                                    
                                    if (selectElement.value === '__custom__') {
                                        customWrapper.style.display = 'block';
                                        // If custom input is empty, set a default
                                        if (!customInput.value) {
                                            customInput.value = 'p';
                                        }
                                        // Update select value to the custom input
                                        selectElement.value = customInput.value;
                                    } else {
                                        customWrapper.style.display = 'none';
                                    }
                                }
                                
                                // Handle custom input changes
                                document.addEventListener('DOMContentLoaded', function() {
                                    var selectElement = document.getElementById('page_param_select');
                                    var customInput = document.getElementById('page_param_custom');
                                    
                                    if (customInput) {
                                        customInput.addEventListener('input', function() {
                                            if (selectElement.value === '__custom__' || !['page', '/page/{page}/'].includes(selectElement.value)) {
                                                selectElement.value = this.value;
                                            }
                                        });
                                    }
                                    
                                    // Before form submit, make sure custom value is in select
                                    var form = document.querySelector('form');
                                    if (form) {
                                        form.addEventListener('submit', function(e) {
                                            if (customInput.parentElement.style.display !== 'none' && customInput.value) {
                                                selectElement.value = customInput.value;
                                            }
                                        });
                                    }
                                });
                                </script>
                                
                                <p class="help-text" style="margin-top: 10px;">
                                    Choose how the site paginates its archive pages
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="fallback_image_id">Fallback Image ID</label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="scraper_profiles[fallback_image_id]" 
                                       id="fallback_image_id" 
                                       value="<?php echo esc_attr($current_profile['fallback_image_id'] ?? '0'); ?>"
                                       min="0"
                                       class="small-text">
                                <p class="help-text">WordPress media ID for fallback featured image</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="custom_css">Custom CSS</label>
                            </th>
                            <td>
                                <textarea
                                       name="scraper_profiles[custom_css]"
                                       id="custom_css"
                                       rows="6"
                                       class="large-text"
                                       style="font-family: monospace; font-size: 13px;"
                                       placeholder=".wp-caption { max-width: 300px; }
.wp-caption-test { font-size: 12px; }"><?php echo esc_textarea($current_profile['custom_css'] ?? ''); ?></textarea>
                                <p class="help-text">Optional: CSS injected into the post content as a <code>&lt;style&gt;</code> block before the content body</p>
                            </td>
                        </tr>
                    </table>

                    <h2>⏰ Automated Scheduling</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="cron_enabled">Enable Automatic Scraping</label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox"
                                           name="scraper_profiles[cron_enabled]"
                                           id="cron_enabled"
                                           value="1"
                                           <?php checked($current_profile['cron_enabled'] ?? '0', '1'); ?>>
                                    Run this profile automatically on a schedule
                                </label>
                                <p class="help-text">When enabled, WordPress cron will automatically scrape this profile</p>
                            </td>
                        </tr>

                        <tr class="cron-settings-row">
                            <th scope="row">
                                <label for="cron_frequency">Frequency</label>
                            </th>
                            <td>
                                <input type="number"
                                       name="scraper_profiles[cron_frequency]"
                                       id="cron_frequency"
                                       value="<?php echo esc_attr($current_profile['cron_frequency'] ?? '1'); ?>"
                                       min="1"
                                       max="24"
                                       class="small-text">
                                <span style="margin: 0 10px;">time(s) per</span>
                                <select name="scraper_profiles[cron_period]" id="cron_period" style="width: auto;">
                                    <option value="hour" <?php selected($current_profile['cron_period'] ?? 'day', 'hour'); ?>>Hour</option>
                                    <option value="day" <?php selected($current_profile['cron_period'] ?? 'day', 'day'); ?>>Day</option>
                                    <option value="week" <?php selected($current_profile['cron_period'] ?? 'day', 'week'); ?>>Week</option>
                                </select>
                                <p class="help-text">How often to run the scraper (between 1-24 times per period)</p>
                            </td>
                        </tr>

                        <?php if (!empty($current_profile['cron_enabled']) && $current_profile['cron_enabled'] == '1'): ?>
                        <tr class="cron-settings-row">
                            <th scope="row">
                                Next Scheduled Run
                            </th>
                            <td>
                                <?php
                                $hook_name = "dgd_scraper_cron_{$current_profile_key}";
                                $next_run = wp_next_scheduled($hook_name);
                                if ($next_run) {
                                    $time_until = human_time_diff($next_run, time());
                                    echo '<strong style="color: #10b981;">' . wp_date('M j, Y @ g:i a', $next_run) . '</strong>';
                                    echo '<br><span style="color: #6b7280; font-size: 13px;">(' . $time_until . ' from now)</span>';
                                } else {
                                    echo '<span style="color: #f59e0b;">Not scheduled yet - save this profile to schedule</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php submit_button('Save Profile', 'primary large'); ?>
                </form>
            </div>

            <?php if (!empty($current_profile['source_url'])): ?>
            <div class="scraper-settings-card">
                <h2>🚀 How to Use This Profile</h2>
                <p>Run this command in your terminal:</p>
                <div class="command-preview">
                    <strong>Dry Run (preview only):</strong><br>
                    <code>wp scraper run --profile=<span class="profile-name-highlight"><?php echo esc_html($current_profile_key); ?></span></code>
                    <br><br>
                    <strong>Live Run (create posts):</strong><br>
                    <code>wp scraper run --profile=<span class="profile-name-highlight"><?php echo esc_html($current_profile_key); ?></span> --live</code>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- XPath Help Modal -->
        <div id="xpath-modal" class="xpath-modal">
            <div class="xpath-modal-content">
                <div class="xpath-modal-header">
                    <h2>📍 Quick Guide to XPath Selectors</h2>
                    <button class="xpath-modal-close" id="close-xpath-modal">&times;</button>
                </div>
                <div class="xpath-modal-body">
                    <h3>🔍 How to Find XPath Selectors</h3>
                    
                    <h4>Method 1: Browser Developer Tools (Easiest)</h4>
                    <ol>
                        <li>Open the webpage you want to scrape</li>
                        <li><strong>Right-click</strong> on the element (title, date, content, etc.)</li>
                        <li>Select <strong>"Inspect"</strong> or <strong>"Inspect Element"</strong></li>
                        <li>In DevTools, <strong>right-click</strong> the highlighted HTML</li>
                        <li>Select <strong>Copy → Copy XPath</strong></li>
                        <li>Paste into the field above</li>
                    </ol>
                    
                    <div class="callout">
                        <strong>💡 Pro Tip:</strong> Browser-generated XPath can be overly specific. Simplify it for better results!
                    </div>
                    
                    <h4>Method 2: Test XPath in Browser Console</h4>
                    <p>Open the browser console (F12) and test your XPath:</p>
                    <pre><code>// Test an XPath selector
$x('//article//h1')

// Returns array of matching elements
// If you see results, it works!</code></pre>
                    
                    <h3>📝 Basic XPath Syntax</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Syntax</th>
                                <th>Meaning</th>
                                <th>Example</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>//</code></td>
                                <td>Select anywhere</td>
                                <td><code>//h1</code> finds all h1 tags</td>
                            </tr>
                            <tr>
                                <td><code>/</code></td>
                                <td>Direct child</td>
                                <td><code>//div/h1</code></td>
                            </tr>
                            <tr>
                                <td><code>[@attribute]</code></td>
                                <td>By attribute</td>
                                <td><code>//div[@class="content"]</code></td>
                            </tr>
                            <tr>
                                <td><code>[contains()]</code></td>
                                <td>Partial match</td>
                                <td><code>//a[contains(@href, "/news/")]</code></td>
                            </tr>
                            <tr>
                                <td><code>[0]</code></td>
                                <td>First match (index based)</td>
                                <td><code>//div[0]</code></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <h3>🎯 Common Examples</h3>
                    
                    <div class="example-box">
                        <strong>Finding Article Links:</strong>
                        <pre><code>// All links
//a

// Links with specific class
//a[@class="article-link"]

// Links containing "/news/" in href
//a[contains(@href, "/news/")]</code></pre>
                    </div>
                    
                    <div class="example-box">
                        <strong>Extracting Title:</strong>
                        <pre><code>// Simple - any h1
//h1

// h1 inside article tag
//article//h1

// h1 with specific class
//h1[@class="article-title"]</code></pre>
                    </div>
                    
                    <div class="example-box">
                        <strong>Extracting Content:</strong>
                        <pre><code>// Simple article tag
//article

// Div with class "content"
//div[@class="content"]

// Article with specific class
//article[@class="post-content"]</code></pre>
                    </div>
                    
                    <div class="example-box">
                        <strong>Extracting Date:</strong>
                        <pre><code>// Simple time tag
//time

// Span with class "date"
//span[@class="date"]

// Meta tag with date
//meta[@property="article:published_time"]</code></pre>
                    </div>
                    
                    <h3>💡 Pro Tips</h3>
                    <div class="callout">
                        <p><strong>1. Start Simple:</strong> Begin with <code>//h1</code> and only add specificity if needed</p>
                        <p><strong>2. Test First:</strong> Use browser console <code>$x('your-xpath')</code> to verify</p>
                        <p><strong>3. Use contains():</strong> <code>contains(@class, "article")</code> matches multiple variations</p>
                        <p><strong>4. Avoid Overly Specific:</strong> Bad: <code>/html/body/div[3]/div[2]/article/h1</code> | Good: <code>//article//h1</code></p>
                    </div>
                    
                    <h3>🚀 Quick Reference</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>What You Want</th>
                                <th>XPath Pattern</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Any h1 tag</td>
                                <td><code>//h1</code></td>
                            </tr>
                            <tr>
                                <td>Element with class</td>
                                <td><code>//div[@class="content"]</code></td>
                            </tr>
                            <tr>
                                <td>Element with ID</td>
                                <td><code>//div[@id="main"]</code></td>
                            </tr>
                            <tr>
                                <td>Link containing text</td>
                                <td><code>//a[contains(@href, "/article/")]</code></td>
                            </tr>
                            <tr>
                                <td>First paragraph</td>
                                <td><code>(//p)[1]</code></td>
                            </tr>
                            <tr>
                                <td>All links in article</td>
                                <td><code>//article//a</code></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Test Run Modal -->
        <div id="test-run-modal" class="test-run-modal">
            <div class="test-run-modal-content">
                <div class="test-run-modal-header">
                    <h2>🚀 Test Run Scraper</h2>
                    <button class="test-run-modal-close" id="close-test-run-modal">&times;</button>
                </div>
                <div class="test-run-modal-body">
                    <div class="dry-run-checkbox">
                        <input type="checkbox" id="dry-run-mode" checked>
                        <label for="dry-run-mode">
                            Dry Run Mode (Preview only - don't create posts)
                        </label>
                    </div>

                    <div class="live-mode-warning" id="live-mode-warning">
                        <strong>⚠️ Warning:</strong> Live mode will create actual posts in your WordPress site!
                    </div>

                    <div id="test-run-status" style="margin: 20px 0; text-align: center; font-size: 16px;"></div>

                    <div id="test-run-results" style="display: none;">
                        <h3 style="margin-top: 0;">Results</h3>
                        <div class="test-run-stats" id="test-run-stats"></div>

                        <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 6px;">
                            <p style="margin: 0; font-size: 14px; color: #6b7280;" id="test-run-message"></p>
                        </div>
                    </div>

                    <div style="margin-top: 20px; text-align: center;">
                        <button type="button" class="button button-primary button-large" id="start-test-run" style="display: none;">
                            Start Scraping
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var modal = $('#xpath-modal');
            var testRunModal = $('#test-run-modal');

            // Toggle content filter mode (include/exclude)
            $('.filter-toggle-btn').on('click', function() {
                var mode = $(this).data('mode');
                $('.filter-toggle-btn').removeClass('active');
                $(this).addClass('active');
                $('#content_filter_mode').val(mode);
                if (mode === 'include') {
                    $('#exclude-fields').hide();
                    $('#include-fields').show();
                } else {
                    $('#include-fields').hide();
                    $('#exclude-fields').show();
                }
            });

            // Show/hide Content Gate values when XPath field changes
            $('#content_gate_xpath').on('input', function() {
                if ($(this).val().trim() !== '') {
                    $('#content-gate-values-row').show();
                } else {
                    $('#content-gate-values-row').hide();
                }
            });

            // Toggle taxonomy fields based on post type
            function toggleTaxonomyFields() {
                var postType = $('#post_type').val();
                if (postType === 'page') {
                    $('.taxonomy-row').hide();
                } else {
                    $('.taxonomy-row').show();
                }
            }
            
            // Run on page load
            toggleTaxonomyFields();
            
            // Run when post type changes
            $('#post_type').on('change', function() {
                toggleTaxonomyFields();
            });
            
            // Open modal
            $('#open-xpath-help').on('click', function(e) {
                e.preventDefault();
                modal.fadeIn(200);
                $('body').css('overflow', 'hidden');
            });
            
            // Close modal
            $('#close-xpath-modal').on('click', function() {
                modal.fadeOut(200);
                $('body').css('overflow', 'auto');
            });
            
            // Close on outside click
            $(window).on('click', function(e) {
                if ($(e.target).is('#xpath-modal')) {
                    modal.fadeOut(200);
                    $('body').css('overflow', 'auto');
                }
            });
            
            // Close on ESC key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && modal.is(':visible')) {
                    modal.fadeOut(200);
                    $('body').css('overflow', 'auto');
                }
            });

            // Delete profile
            $('.btn-delete').on('click', function() {
                var profileKey = $(this).data('profile');
                if (confirm('Are you sure you want to delete this profile? This cannot be undone.')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'delete_scraper_profile',
                            profile_key: profileKey,
                            nonce: '<?php echo wp_create_nonce('scraper_profile_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                window.location.href = '<?php echo admin_url('tools.php?page=scraper-settings'); ?>';
                            } else {
                                alert('Error: ' + response.data.message);
                            }
                        }
                    });
                }
            });
            
            // Duplicate profile
            $('.btn-duplicate').on('click', function() {
                var profileKey = $(this).data('profile');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'duplicate_scraper_profile',
                        profile_key: profileKey,
                        nonce: '<?php echo wp_create_nonce('scraper_profile_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            window.location.href = response.data.redirect;
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    }
                });
            });

            // Toggle cron settings visibility
            function toggleCronSettings() {
                if ($('#cron_enabled').is(':checked')) {
                    $('.cron-settings-row').show();
                } else {
                    $('.cron-settings-row').hide();
                }
            }

            // Run on page load
            toggleCronSettings();

            // Run when checkbox changes
            $('#cron_enabled').on('change', function() {
                toggleCronSettings();
            });

            // Test Run functionality
            var currentProfileKey = '';

            // Open test run modal
            $('.btn-test-run').on('click', function() {
                currentProfileKey = $(this).data('profile');
                testRunModal.fadeIn(200);
                $('body').css('overflow', 'hidden');
                $('#test-run-results').hide();
                $('#test-run-status').html('Ready to start scraping. Choose your mode above and click "Start Scraping".');
                $('#start-test-run').show();
            });

            // Close test run modal
            $('#close-test-run-modal').on('click', function() {
                testRunModal.fadeOut(200);
                $('body').css('overflow', 'auto');
            });

            // Close on outside click
            $(window).on('click', function(e) {
                if ($(e.target).is('#test-run-modal')) {
                    testRunModal.fadeOut(200);
                    $('body').css('overflow', 'auto');
                }
            });

            // Toggle warning when dry run mode changes
            $('#dry-run-mode').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#live-mode-warning').removeClass('show');
                } else {
                    $('#live-mode-warning').addClass('show');
                }
            });

            // Start test run
            $('#start-test-run').on('click', function() {
                var $btn = $(this);
                var isDryRun = $('#dry-run-mode').is(':checked');
                var mode = isDryRun ? 'Dry Run' : 'Live Mode';

                // Disable button
                $btn.prop('disabled', true);
                $('#test-run-status').html('⏳ Running scraper in ' + mode + '... This may take a few minutes.');
                $('#test-run-results').hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    timeout: 300000, // 5 minutes
                    data: {
                        action: 'test_run_scraper_profile',
                        profile_key: currentProfileKey,
                        live: !isDryRun,
                        nonce: '<?php echo wp_create_nonce('scraper_profile_nonce'); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false);

                        if (response.success) {
                            var stats = response.data.stats;
                            var liveMode = response.data.live_mode;

                            $('#test-run-status').html(
                                liveMode
                                    ? '✅ Live scrape completed!'
                                    : '✅ Dry run completed!'
                            );

                            // Build stats display
                            var statsHtml = '';
                            statsHtml += '<div class="stat-card"><div class="stat-label">Processed</div><div class="stat-number">' + stats.processed + '</div></div>';
                            statsHtml += '<div class="stat-card success"><div class="stat-label">Created</div><div class="stat-number">' + stats.created + '</div></div>';
                            statsHtml += '<div class="stat-card warning"><div class="stat-label">Skipped</div><div class="stat-number">' + stats.skipped + '</div></div>';
                            statsHtml += '<div class="stat-card error"><div class="stat-label">Errors</div><div class="stat-number">' + stats.errors + '</div></div>';

                            $('#test-run-stats').html(statsHtml);

                            var message = liveMode
                                ? 'Posts have been created in WordPress. Check your Posts list to see them.'
                                : 'This was a preview. No posts were created. Uncheck "Dry Run Mode" to create actual posts.';

                            $('#test-run-message').text(message);
                            $('#test-run-results').fadeIn(200);

                            // If live mode and posts were created, offer to reload
                            if (liveMode && stats.created > 0) {
                                setTimeout(function() {
                                    if (confirm('Would you like to reload the page to see updated cron schedules?')) {
                                        location.reload();
                                    }
                                }, 1500);
                            }
                        } else {
                            $('#test-run-status').html('❌ Error: ' + response.data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        $btn.prop('disabled', false);
                        $('#test-run-status').html('❌ Error: ' + error + ' (The request may have timed out - check your error logs)');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Sanitize XPath expression
     * Removes dangerous content but preserves XPath syntax (quotes, brackets, etc.)
     */
    private function sanitize_xpath($xpath) {
        // Remove slashes added by WordPress
        $xpath = wp_unslash($xpath);
        
        // Trim whitespace
        $xpath = trim($xpath);
        
        // Remove any HTML tags
        $xpath = strip_tags($xpath);
        
        // Remove any script-like content
        $xpath = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $xpath);
        
        // But preserve XPath special characters: / @ [ ] ( ) ' " = 
        // These are essential for XPath syntax
        
        return $xpath;
    }
    
    /**
     * Sanitize multiple XPath expressions (one per line)
     * Used for remove_xpaths field
     */
    private function sanitize_xpaths_multiline($xpaths_text) {
        // Remove slashes added by WordPress
        $xpaths_text = wp_unslash($xpaths_text);
        
        // Trim whitespace
        $xpaths_text = trim($xpaths_text);
        
        // Remove any HTML tags
        $xpaths_text = strip_tags($xpaths_text);
        
        // Remove any script-like content
        $xpaths_text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $xpaths_text);
        
        // Preserve XPath special characters and newlines
        
        return $xpaths_text;
    }
    
    /**
     * Sanitize include XPaths field
     * Preserves HTML in "html:" lines while sanitizing XPath lines
     */
    private function sanitize_include_xpaths($text) {
        $text = wp_unslash($text);
        $text = trim($text);

        // Process line by line
        $lines = explode("\n", $text);
        $sanitized_lines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') continue;

            if (strpos($trimmed, 'html:') === 0) {
                // html: lines — allow safe HTML tags only
                $html_part = trim(substr($trimmed, 5));
                $html_part = wp_kses($html_part, [
                    'div' => ['class' => [], 'id' => [], 'style' => []],
                    'figure' => ['class' => [], 'id' => [], 'style' => []],
                    'figcaption' => ['class' => [], 'id' => [], 'style' => []],
                    'section' => ['class' => [], 'id' => [], 'style' => []],
                    'article' => ['class' => [], 'id' => [], 'style' => []],
                    'span' => ['class' => [], 'id' => [], 'style' => []],
                    'p' => ['class' => [], 'id' => [], 'style' => []],
                    'h1' => ['class' => [], 'id' => [], 'style' => []], 'h2' => ['class' => [], 'id' => [], 'style' => []],
                    'h3' => ['class' => [], 'id' => [], 'style' => []], 'h4' => ['class' => [], 'id' => [], 'style' => []],
                    'h5' => ['class' => [], 'id' => [], 'style' => []], 'h6' => ['class' => [], 'id' => [], 'style' => []],
                    'ul' => ['class' => [], 'id' => [], 'style' => []], 'ol' => ['class' => [], 'id' => [], 'style' => []], 'li' => ['class' => [], 'id' => [], 'style' => []],
                    'blockquote' => ['class' => [], 'id' => [], 'style' => []],
                    'em' => ['class' => [], 'id' => [], 'style' => []], 'strong' => ['class' => [], 'id' => [], 'style' => []], 'b' => ['class' => [], 'id' => [], 'style' => []], 'i' => ['class' => [], 'id' => [], 'style' => []],
                    'a' => ['href' => [], 'class' => [], 'id' => [], 'style' => [], 'target' => []],
                    'br' => ['class' => []],
                    'hr' => ['class' => [], 'id' => [], 'style' => []],
                    'img' => ['src' => [], 'alt' => [], 'class' => [], 'id' => [], 'style' => [], 'width' => [], 'height' => []],
                ]);
                $sanitized_lines[] = 'html: ' . $html_part;
            } else {
                // XPath lines — check for pipe syntax (xpath | <open> | </close>)
                $parts = explode('|', $trimmed);
                if (count($parts) >= 2) {
                    // Pipe syntax: sanitize XPath with strip_tags, HTML wrappers with wp_kses
                    $allowed_tags = [
                        'div' => ['class' => [], 'id' => [], 'style' => []],
                        'figure' => ['class' => [], 'id' => [], 'style' => []],
                        'figcaption' => ['class' => [], 'id' => [], 'style' => []],
                        'section' => ['class' => [], 'id' => [], 'style' => []],
                        'article' => ['class' => [], 'id' => [], 'style' => []],
                        'span' => ['class' => [], 'id' => [], 'style' => []],
                        'p' => ['class' => [], 'id' => [], 'style' => []],
                        'h1' => ['class' => [], 'id' => [], 'style' => []], 'h2' => ['class' => [], 'id' => [], 'style' => []],
                        'h3' => ['class' => [], 'id' => [], 'style' => []], 'h4' => ['class' => [], 'id' => [], 'style' => []],
                        'h5' => ['class' => [], 'id' => [], 'style' => []], 'h6' => ['class' => [], 'id' => [], 'style' => []],
                        'ul' => ['class' => [], 'id' => [], 'style' => []], 'ol' => ['class' => [], 'id' => [], 'style' => []], 'li' => ['class' => [], 'id' => [], 'style' => []],
                        'blockquote' => ['class' => [], 'id' => [], 'style' => []],
                        'em' => ['class' => [], 'id' => [], 'style' => []], 'strong' => ['class' => [], 'id' => [], 'style' => []], 'b' => ['class' => [], 'id' => [], 'style' => []], 'i' => ['class' => [], 'id' => [], 'style' => []],
                        'a' => ['href' => [], 'class' => [], 'id' => [], 'style' => [], 'target' => []],
                        'br' => ['class' => []],
                        'hr' => ['class' => [], 'id' => [], 'style' => []],
                        'img' => ['src' => [], 'alt' => [], 'class' => [], 'id' => [], 'style' => [], 'width' => [], 'height' => []],
                    ];
                    $sanitized_parts = [strip_tags(trim($parts[0]))];
                    for ($i = 1; $i < count($parts); $i++) {
                        $sanitized_parts[] = wp_kses(trim($parts[$i]), $allowed_tags);
                    }
                    $sanitized_lines[] = implode(' | ', $sanitized_parts);
                } else {
                    // Plain XPath — strip tags
                    $sanitized_lines[] = strip_tags($trimmed);
                }
            }
        }

        return implode("\n", $sanitized_lines);
    }

    /**
     * Get default profile data structure
     */
    private function get_default_profile_data() {
        return [
            'profile_name' => 'Default Profile',
            'source_url' => '',
            'post_type' => 'post',
            'listing_xpath' => '',
            'title_xpath' => '',
            'content_xpath' => '',
            'date_xpath' => '',
            'excerpt_xpath' => '',
            'image_xpath' => '',
            'content_gate_xpath' => '',
            'content_gate_values' => '',
            'content_filter_mode' => 'exclude',
            'include_xpaths' => '',
            'remove_xpaths' => '',
            'start_page' => '1',
            'max_pages' => '1',
            'posts_per_batch' => '9',
            'post_status' => 'publish',
            'categories' => '',
            'tags' => '',
            'page_param' => 'page',
            'fallback_image_id' => '0',
            'custom_css' => '',
            'cron_enabled' => '0',
            'cron_frequency' => '1',
            'cron_period' => 'day',
        ];
    }
    
    /**
     * Sanitize profile data
     */
    private function sanitize_profile_data($data) {
        // DEBUG: Log what we're receiving
        error_log('RAW page_param input: ' . var_export($data['page_param'] ?? 'NOT SET', true));
        
        $sanitized = [
            'profile_name' => sanitize_text_field($data['profile_name'] ?? ''),
            'source_url' => esc_url_raw($data['source_url'] ?? ''),
            'post_type' => sanitize_key($data['post_type'] ?? 'post'),
            // XPath fields - use custom sanitization that preserves XPath syntax
            'listing_xpath' => $this->sanitize_xpath($data['listing_xpath'] ?? ''),
            'title_xpath' => $this->sanitize_xpath($data['title_xpath'] ?? ''),
            'content_xpath' => $this->sanitize_xpath($data['content_xpath'] ?? ''),
            'date_xpath' => $this->sanitize_xpath($data['date_xpath'] ?? ''),
            'excerpt_xpath' => $this->sanitize_xpath($data['excerpt_xpath'] ?? ''),
            'image_xpath' => $this->sanitize_xpath($data['image_xpath'] ?? ''),
            'content_gate_xpath' => $this->sanitize_xpath($data['content_gate_xpath'] ?? ''),
            'content_gate_values' => $this->sanitize_xpaths_multiline($data['content_gate_values'] ?? ''),
            'content_filter_mode' => in_array($data['content_filter_mode'] ?? 'exclude', ['include', 'exclude']) ? $data['content_filter_mode'] : 'exclude',
            'include_xpaths' => $this->sanitize_include_xpaths($data['include_xpaths'] ?? ''),
            'remove_xpaths' => $this->sanitize_xpaths_multiline($data['remove_xpaths'] ?? ''),
            'start_page' => max(1, intval($data['start_page'] ?? 1)),
            'max_pages' => max(1, intval($data['max_pages'] ?? 1)),
            'posts_per_batch' => intval($data['posts_per_batch'] ?? 9),
            'post_status' => in_array($data['post_status'] ?? 'publish', ['publish', 'draft']) ? $data['post_status'] : 'publish',
            'categories' => sanitize_text_field($data['categories'] ?? ''),
            'tags' => sanitize_text_field($data['tags'] ?? ''),
            'page_param' => $this->sanitize_page_param($data['page_param'] ?? 'page'),
            'fallback_image_id' => max(0, intval($data['fallback_image_id'] ?? 0)),
            'custom_css' => wp_strip_all_tags($data['custom_css'] ?? ''),
            'cron_enabled' => !empty($data['cron_enabled']) ? '1' : '0',
            'cron_frequency' => max(1, min(24, intval($data['cron_frequency'] ?? 1))),
            'cron_period' => in_array($data['cron_period'] ?? 'day', ['hour', 'day', 'week']) ? $data['cron_period'] : 'day',
        ];
        
        // DEBUG: Log what we're saving
        error_log('SANITIZED page_param output: ' . var_export($sanitized['page_param'], true));
        
        return $sanitized;
    }
    
    /**
     * Sanitize page_param - preserves slashes and curly braces needed for path-based pagination
     */
    private function sanitize_page_param($param) {
        // Remove slashes added by WordPress
        $param = wp_unslash($param);
        
        // Trim whitespace
        $param = trim($param);
        
        // Remove any HTML tags
        $param = strip_tags($param);
        
        // Remove any script-like content
        $param = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $param);
        
        // Preserve: / { } (needed for path-based pagination like /page/{page}/)
        // Also preserve query param characters: letters, numbers, underscore, dash

        return $param;
    }
}

// Initialize
new Web_Scraper_Admin_Settings();
