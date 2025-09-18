<?php
/**
 * Plugin Name: WP All Import Tracker
 * Plugin URI: https://wordpress.org/plugins/wp-all-import-tracker/
 * Description: Track and display WP All Import status for WooCommerce products. Shows which products were updated or skipped during imports with precise timestamps. Now supports monitoring multiple imports simultaneously.
 * Version: 1.1.0
 * Author: Olivier Bigras
 * Author URI: https://olivierbigras.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-all-import-tracker
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPAIT_VERSION', '1.1.0');
define('WPAIT_PLUGIN_FILE', __FILE__);
define('WPAIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPAIT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPAIT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class WP_All_Import_Tracker {
    
    /**
     * Single instance of the class
     */
    private static $instance = null;
    
    /**
     * Default import IDs (array to support multiple imports)
     */
    private $default_import_ids = array(1);
    
    /**
     * Get single instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Check dependencies with lower priority to avoid conflicts
        add_action('admin_init', array($this, 'check_dependencies'), 20);
        
        // Clean up any conflicting hooks on activation
        add_action('activated_plugin', array($this, 'cleanup_conflicts'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Check if dependencies are met
        if (!$this->dependencies_met()) {
            return;
        }
        
        // Load plugin features
        $this->load_features();
    }
    
    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('wp-all-import-tracker', false, dirname(WPAIT_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Check if dependencies are met
     */
    private function dependencies_met() {
        // Check if WooCommerce is active and loaded
        if (!class_exists('WooCommerce') && !function_exists('WC')) {
            return false;
        }
        
        // Check if WP All Import is active (multiple ways to detect)
        if (!class_exists('PMXI_Plugin') && !defined('PMXI_VERSION') && !function_exists('pmxi_plugin_loaded')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check dependencies and show admin notices
     */
    public function check_dependencies() {
        if (!$this->dependencies_met()) {
            add_action('admin_notices', array($this, 'dependency_notice'));
            // Don't auto-deactivate to avoid WooCommerce compatibility issues
            // Users can manually deactivate if needed
        }
    }
    
    /**
     * Show dependency notice
     */
    public function dependency_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php 
                echo wp_kses_post(
                    sprintf(
                        __('<strong>WP All Import Tracker</strong> requires <strong>WooCommerce</strong> and <strong>WP All Import</strong> to be installed and activated. Please install and activate these plugins first.', 'wp-all-import-tracker')
                    )
                );
                ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Load plugin features
     */
    private function load_features() {
        // Load admin features
        if (is_admin()) {
            require_once WPAIT_PLUGIN_DIR . 'includes/class-admin.php';
            new WPAIT_Admin();
        }
        
        // Load core tracker
        require_once WPAIT_PLUGIN_DIR . 'includes/class-tracker.php';
        new WPAIT_Tracker();
        
        // Load settings
        require_once WPAIT_PLUGIN_DIR . 'includes/class-settings.php';
        new WPAIT_Settings();
        
        // Load email alerts
        require_once WPAIT_PLUGIN_DIR . 'includes/class-email-alerts.php';
        new WPAIT_Email_Alerts();
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Migration: Convert old single import_id to new import_ids array
        $old_import_id = get_option('wpait_import_id', false);
        if ($old_import_id !== false && !get_option('wpait_import_ids', false)) {
            // Migrate old single import ID to new array format
            update_option('wpait_import_ids', array($old_import_id));
            error_log('WPAIT: Migrated old import_id (' . $old_import_id . ') to new import_ids format');
        }
        
        // Set default options
        add_option('wpait_import_ids', $this->default_import_ids);
        add_option('wpait_enable_auto_refresh', 1);
        
        // Email alerts options (disabled by default for WordPress.org compliance)
        add_option('wpait_enable_email_alerts', 0);
        add_option('wpait_alert_email', get_option('admin_email')); // Pre-fill admin email
        add_option('wpait_stale_threshold', 150);
        add_option('wpait_max_execution_time', 180);
        
        // Setup wizard disabled - smart auto-activation handles everything
        add_option('wpait_show_setup_notice', 0);
        
        // Notification dismissal tracking
        add_option('wpait_dismissed_notifications', array());
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up if needed
        flush_rewrite_rules();
        
        // Trigger cleanup for email alerts
        do_action('wpait_deactivate');
    }
    
    /**
     * Clean up any conflicts when plugin is activated
     */
    public function cleanup_conflicts($plugin) {
        if ($plugin === WPAIT_PLUGIN_BASENAME) {
            // Remove any old cron jobs that might conflict
            wp_clear_scheduled_hook('check_wpai_import_status');
            
            // Clean up old import tracking options
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpai_import_start_time_%'");
            
            error_log('WP All Import Tracker: Cleaned up potential conflicts on activation');
        }
    }
    
    /**
     * Get import IDs from settings (returns array)
     */
    public function get_import_ids() {
        $import_ids = get_option('wpait_import_ids', false);
        
        // Migration: If new format doesn't exist, try to migrate from old format
        if ($import_ids === false) {
            $old_import_id = get_option('wpait_import_id', false);
            if ($old_import_id !== false) {
                // Migrate old single import ID to new array format
                $import_ids = array($old_import_id);
                update_option('wpait_import_ids', $import_ids);
                error_log('WPAIT: Auto-migrated old import_id (' . $old_import_id . ') to new import_ids format');
            } else {
                $import_ids = $this->default_import_ids;
            }
        }
        
        // Ensure we always return an array
        if (!is_array($import_ids)) {
            $import_ids = array($import_ids);
        }
        return array_map('intval', $import_ids);
    }
    
    /**
     * Get primary import ID (first in the list for backward compatibility)
     */
    public function get_import_id() {
        $import_ids = $this->get_import_ids();
        return !empty($import_ids) ? $import_ids[0] : $this->default_import_ids[0];
    }
}

/**
 * Initialize the plugin
 */
function WPAIT() {
    return WP_All_Import_Tracker::instance();
}

// Declare WooCommerce compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Start the plugin
WPAIT(); 