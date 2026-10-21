<?php
/**
 * Plugin Name: Oli Import Tracker for WP All Import
 * Plugin URI: https://github.com/olivierbigras/wp-all-import-tracker
 * Description: Track and display WP All Import status for WooCommerce products. Shows which products were updated or skipped during imports with precise timestamps. Supports monitoring multiple imports simultaneously.
 * Version: 1.2.0
 * Author: Olivier Bigras
 * Author URI: https://olivierbigras.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oli-import-tracker
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 11.2
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('OLIIT_VERSION')) {
    return;
}

define('OLIIT_VERSION', '1.2.0');
define('OLIIT_PLUGIN_FILE', __FILE__);
define('OLIIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OLIIT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OLIIT_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once OLIIT_PLUGIN_DIR . 'includes/helpers.php';

/**
 * Main plugin class.
 */
class OLIIT_Plugin {

    /**
     * Single instance of the class.
     *
     * @var OLIIT_Plugin|null
     */
    private static $instance = null;

    /**
     * Get single instance.
     *
     * @return OLIIT_Plugin
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('plugins_loaded', 'oliit_migrate_legacy_options', 5);
        add_action('plugins_loaded', 'oliit_maybe_reset_default_import_one', 6);

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('admin_init', array($this, 'check_dependencies'), 20);
        add_action('admin_init', array($this, 'switch_legacy_plugin_file'));
        add_action('activated_plugin', array($this, 'cleanup_conflicts'));
    }

    /**
     * Initialize plugin features.
     *
     * @return void
     */
    public function init() {
        if (!$this->dependencies_met()) {
            return;
        }

        $this->load_features();
    }

    /**
     * Load text domain.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain('oli-import-tracker', false, dirname(OLIIT_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Check if dependencies are met.
     *
     * @return bool
     */
    private function dependencies_met() {
        if (!class_exists('WooCommerce') && !function_exists('WC')) {
            return false;
        }

        if (!class_exists('PMXI_Plugin') && !defined('PMXI_VERSION') && !function_exists('pmxi_plugin_loaded')) {
            return false;
        }

        return true;
    }

    /**
     * Check dependencies and show admin notices.
     *
     * @return void
     */
    public function check_dependencies() {
        if (!$this->dependencies_met()) {
            add_action('admin_notices', array($this, 'dependency_notice'));
        }
    }

    /**
     * Show dependency notice.
     *
     * @return void
     */
    public function dependency_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                echo wp_kses_post(
                    sprintf(
                        /* translators: Plugin name is wrapped in strong tags. */
                        __('<strong>Oli Import Tracker for WP All Import</strong> requires <strong>WooCommerce</strong> and <strong>WP All Import</strong> to be installed and activated. Please install and activate these plugins first.', 'oli-import-tracker')
                    )
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Load plugin features.
     *
     * @return void
     */
    private function load_features() {
        if (is_admin()) {
            require_once OLIIT_PLUGIN_DIR . 'includes/class-admin.php';
            new OLIIT_Admin();
        }

        require_once OLIIT_PLUGIN_DIR . 'includes/class-tracker.php';
        new OLIIT_Tracker();

        require_once OLIIT_PLUGIN_DIR . 'includes/class-settings.php';
        new OLIIT_Settings();

        require_once OLIIT_PLUGIN_DIR . 'includes/class-email-alerts.php';
        new OLIIT_Email_Alerts();
    }

    /**
     * Move active installs from the old main file to oli-import-tracker.php.
     *
     * @return void
     */
    public function switch_legacy_plugin_file() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $directory = dirname(OLIIT_PLUGIN_BASENAME);
        $old_file  = $directory . '/wp-all-import-tracker.php';
        $new_file  = $directory . '/oli-import-tracker.php';

        if ($old_file === $new_file || !is_plugin_active($old_file) || is_plugin_active($new_file)) {
            return;
        }

        activate_plugin($new_file, '', is_network_admin(), true);
        deactivate_plugins($old_file, true, is_network_admin());
    }

    /**
     * Plugin activation.
     *
     * @return void
     */
    public function activate() {
        oliit_migrate_legacy_options();

        $old_import_id = get_option('oliit_import_id', false);
        if (false !== $old_import_id && false === get_option('oliit_import_ids', false)) {
            update_option('oliit_import_ids', array(absint($old_import_id)));
        }

        if (false === get_option('oliit_import_ids', false)) {
            add_option('oliit_import_ids', oliit_get_scheduled_import_ids());
        }

        add_option('oliit_enable_auto_refresh', 1);
        add_option('oliit_enable_email_alerts', 0);
        add_option('oliit_alert_email', '');
        add_option('oliit_stale_threshold', 150);
        add_option('oliit_max_execution_time', 180);
        add_option('oliit_show_setup_notice', 1);
        add_option('oliit_dismissed_notifications', array());
        add_option('oliit_ux_defaults_migrated', '1');

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     *
     * @return void
     */
    public function deactivate() {
        flush_rewrite_rules();
        do_action('oliit_deactivate');
    }

    /**
     * Clean up leftover hooks when this plugin is activated.
     *
     * @param string $plugin Plugin basename that was just activated.
     * @return void
     */
    public function cleanup_conflicts($plugin) {
        if ($plugin !== OLIIT_PLUGIN_BASENAME) {
            return;
        }

        wp_clear_scheduled_hook('check_wpai_import_status');
        wp_clear_scheduled_hook('wpait_check_import_status');

        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('wpai_import_start_time_') . '%'
            )
        );

        oliit_log('Cleaned up potential conflicts on activation');
    }

    /**
     * Get import IDs from settings.
     *
     * @return int[]
     */
    public function get_import_ids() {
        $import_ids = get_option('oliit_import_ids', false);

        if (false === $import_ids) {
            $old_import_id = get_option('oliit_import_id', false);
            if (false !== $old_import_id) {
                $import_ids = array(absint($old_import_id));
                update_option('oliit_import_ids', $import_ids);
            } else {
                $import_ids = array();
            }
        }

        if (!is_array($import_ids)) {
            $import_ids = array($import_ids);
        }

        return array_values(array_filter(array_map('intval', $import_ids)));
    }

    /**
     * Get primary import ID (first in the list).
     *
     * @return int
     */
    public function get_import_id() {
        $import_ids = $this->get_import_ids();
        return !empty($import_ids) ? $import_ids[0] : 0;
    }
}

/**
 * Initialize the plugin.
 *
 * @return OLIIT_Plugin
 */
function oliit() {
    return OLIIT_Plugin::instance();
}

/**
 * Declare WooCommerce feature compatibility.
 *
 * @return void
 */
function oliit_declare_wc_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', OLIIT_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', OLIIT_PLUGIN_FILE, true);
    }
}
add_action('before_woocommerce_init', 'oliit_declare_wc_compatibility');

oliit();
