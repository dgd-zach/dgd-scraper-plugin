<?php
/**
 * Plugin Name: DGD Web Scraper - w/ Cron
 * Plugin URI: https://github.com/yourusername/generic-web-scraper
 * Description: Import content from any website into WordPress using XPath selectors. Supports multiple profiles, path-based pagination, and intelligent content extraction.
 * Version: 2.0.0
 * Author: Zach Bines
 * Author URI: doinggooddigital.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: generic-web-scraper
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * 
 * @package GenericWebScraper
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GWS_VERSION', '2.0.0');
define('GWS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GWS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GWS_PLUGIN_FILE', __FILE__);

/**
 * Main Plugin Class
 */
class Generic_Web_Scraper_Plugin {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load admin interface
        require_once GWS_PLUGIN_DIR . 'includes/class-scraper-admin.php';

        // Load cron scheduling
        require_once GWS_PLUGIN_DIR . 'includes/class-scraper-cron.php';
        new Scraper_Cron();

        // Load WP-CLI command if WP-CLI is available
        if (defined('WP_CLI') && WP_CLI) {
            require_once GWS_PLUGIN_DIR . 'includes/class-scraper-command.php';
        }
        
        // Register activation/deactivation hooks
        register_activation_hook(GWS_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(GWS_PLUGIN_FILE, [$this, 'deactivate']);

          
        // Add plugin action links
        add_filter('plugin_action_links_' . plugin_basename(GWS_PLUGIN_FILE), [$this, 'add_action_links']);
    }

    /**
     * Add custom links to plugin page
     */
    public function add_action_links($links) {
        $custom_links = [
            '<a href="' . admin_url('tools.php?page=scraper-settings') . '">Settings</a>',
            '<a href="' . GWS_PLUGIN_URL . 'docs/USER-DOCUMENTATION.html" target="_blank">Documentation</a>',
        ];
        
        return array_merge($custom_links, $links);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Initialize default settings if needed
        if (!get_option('scraper_profiles')) {
            update_option('scraper_profiles', []);
        }
        
        // Flush rewrite rules if needed
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear all scheduled cron events
        Scraper_Cron::clear_all_schedules();

        flush_rewrite_rules();
    }
}

// Initialize the plugin
new Generic_Web_Scraper_Plugin();
