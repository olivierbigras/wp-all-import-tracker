<?php
/**
 * Settings class.
 *
 * @package Oli_Import_Tracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings functionality.
 */
class OLIIT_Settings {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_oliit_test_email', array($this, 'handle_test_email'));
        add_action('wp_ajax_oliit_refresh_cache', array($this, 'handle_refresh_cache'));
        add_action('wp_ajax_oliit_debug_import', array($this, 'handle_debug_import'));
        add_action('wp_ajax_oliit_reset_notifications', array($this, 'handle_reset_notifications'));
    }

    /**
     * Enqueue settings page assets.
     *
     * @param string $hook Current admin hook.
     * @return void
     */
    public function enqueue_assets($hook) {
        if ('settings_page_oliit-settings' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'oliit-admin-settings',
            OLIIT_PLUGIN_URL . 'assets/css/admin-settings.css',
            array(),
            OLIIT_VERSION
        );

        wp_enqueue_script(
            'oliit-admin-settings',
            OLIIT_PLUGIN_URL . 'assets/js/admin-settings.js',
            array('jquery'),
            OLIIT_VERSION,
            true
        );

        wp_localize_script(
            'oliit-admin-settings',
            'oliitSettings',
            array(
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'adminEmail' => get_option('admin_email'),
                'importIds'  => oliit()->get_import_ids(),
                'nonces'     => array(
                    'testEmail'           => wp_create_nonce('oliit_test_email'),
                    'refreshCache'        => wp_create_nonce('oliit_refresh_cache'),
                    'debugImport'         => wp_create_nonce('oliit_debug_import'),
                    'resetNotifications'  => wp_create_nonce('oliit_reset_notifications'),
                ),
                'i18n'       => array(
                    'remove'          => __('Remove', 'wp-all-import-tracker'),
                    'detectImports'   => __('Detect Available Imports', 'wp-all-import-tracker'),
                    'hideImports'     => __('Hide Available Imports', 'wp-all-import-tracker'),
                    'tracked'         => __('Tracked', 'wp-all-import-tracker'),
                    'sending'         => __('Sending...', 'wp-all-import-tracker'),
                    'testEmailError'  => __('Error sending test email. Please try again.', 'wp-all-import-tracker'),
                    'refreshing'      => __('Refreshing...', 'wp-all-import-tracker'),
                    'refreshError'    => __('Error refreshing cache. Please try again.', 'wp-all-import-tracker'),
                    'checking'        => __('Checking...', 'wp-all-import-tracker'),
                    'debugError'      => __('Error checking import data.', 'wp-all-import-tracker'),
                    'resetting'       => __('Resetting...', 'wp-all-import-tracker'),
                    'resetLabel'      => __('Reset All Dismissed Notifications', 'wp-all-import-tracker'),
                    'resetError'      => __('Error occurred', 'wp-all-import-tracker'),
                ),
            )
        );
    }

    /**
     * Add admin menu.
     *
     * @return void
     */
    public function add_admin_menu() {
        add_options_page(
            __('Oli Import Tracker Settings', 'wp-all-import-tracker'),
            __('Import Tracker', 'wp-all-import-tracker'),
            'manage_options',
            'oliit-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Register settings.
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            'oliit_settings',
            'oliit_import_ids',
            array(
                'type'              => 'array',
                'default'           => array(1),
                'sanitize_callback' => array($this, 'validate_import_ids'),
            )
        );

        register_setting(
            'oliit_settings',
            'oliit_enable_auto_refresh',
            array(
                'type'              => 'boolean',
                'default'           => true,
                'sanitize_callback' => 'absint',
            )
        );

        register_setting(
            'oliit_settings',
            'oliit_enable_email_alerts',
            array(
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => 'absint',
            )
        );

        register_setting(
            'oliit_settings',
            'oliit_alert_email',
            array(
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_email',
            )
        );

        register_setting(
            'oliit_settings',
            'oliit_stale_threshold',
            array(
                'type'              => 'integer',
                'default'           => 150,
                'sanitize_callback' => 'absint',
            )
        );

        register_setting(
            'oliit_settings',
            'oliit_max_execution_time',
            array(
                'type'              => 'integer',
                'default'           => 180,
                'sanitize_callback' => 'absint',
            )
        );

        add_settings_section(
            'oliit_general_section',
            __('General Settings', 'wp-all-import-tracker'),
            array($this, 'general_section_callback'),
            'oliit-settings'
        );

        add_settings_field(
            'oliit_import_ids',
            __('Import IDs', 'wp-all-import-tracker'),
            array($this, 'import_ids_callback'),
            'oliit-settings',
            'oliit_general_section'
        );

        add_settings_field(
            'oliit_enable_auto_refresh',
            __('Enable Auto Refresh', 'wp-all-import-tracker'),
            array($this, 'enable_auto_refresh_callback'),
            'oliit-settings',
            'oliit_general_section'
        );

        add_settings_field(
            'oliit_refresh_cache',
            __('Refresh Cache', 'wp-all-import-tracker'),
            array($this, 'refresh_cache_callback'),
            'oliit-settings',
            'oliit_general_section'
        );

        add_settings_field(
            'oliit_debug_import',
            __('Debug Import Status', 'wp-all-import-tracker'),
            array($this, 'debug_import_callback'),
            'oliit-settings',
            'oliit_general_section'
        );

        add_settings_section(
            'oliit_email_section',
            __('Email Alerts', 'wp-all-import-tracker'),
            array($this, 'email_section_callback'),
            'oliit-settings'
        );

        add_settings_field(
            'oliit_enable_email_alerts',
            __('Enable Email Alerts', 'wp-all-import-tracker'),
            array($this, 'enable_email_alerts_callback'),
            'oliit-settings',
            'oliit_email_section'
        );

        add_settings_field(
            'oliit_alert_email',
            __('Alert Email Address', 'wp-all-import-tracker'),
            array($this, 'alert_email_callback'),
            'oliit-settings',
            'oliit_email_section'
        );

        add_settings_field(
            'oliit_stale_threshold',
            __('Stale Activity Threshold (minutes)', 'wp-all-import-tracker'),
            array($this, 'stale_threshold_callback'),
            'oliit-settings',
            'oliit_email_section'
        );

        add_settings_field(
            'oliit_max_execution_time',
            __('Max Execution Time (minutes)', 'wp-all-import-tracker'),
            array($this, 'max_execution_time_callback'),
            'oliit-settings',
            'oliit_email_section'
        );

        add_settings_section(
            'oliit_notifications_section',
            __('Notification Management', 'wp-all-import-tracker'),
            array($this, 'notifications_section_callback'),
            'oliit-settings'
        );

        add_settings_field(
            'oliit_reset_dismissed_notifications',
            __('Reset Dismissed Notifications', 'wp-all-import-tracker'),
            array($this, 'reset_dismissed_notifications_callback'),
            'oliit-settings',
            'oliit_notifications_section'
        );
    }

    /**
     * Settings page.
     *
     * @return void
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated'])) {
            add_settings_error('oliit_messages', 'oliit_message', __('Settings saved.', 'wp-all-import-tracker'), 'updated');
        }

        settings_errors('oliit_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="oliit-settings-wrapper">
                <div class="oliit-settings-main">
                    <form action="options.php" method="post">
                        <?php
                        settings_fields('oliit_settings');
                        do_settings_sections('oliit-settings');
                        submit_button(__('Save Settings', 'wp-all-import-tracker'));
                        ?>
                    </form>
                </div>

                <div class="oliit-settings-sidebar">
                    <?php $this->render_tracking_box(); ?>
                    <?php $this->render_about_box(); ?>
                    <?php $this->render_email_status_box(); ?>
                    <?php $this->render_import_id_help_box(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render currently tracking sidebar box.
     *
     * @return void
     */
    private function render_tracking_box() {
        global $wpdb;
        $configured_import_ids = oliit()->get_import_ids();
        ?>
        <div class="oliit-settings-box">
            <h3><?php esc_html_e('Currently Tracking', 'wp-all-import-tracker'); ?></h3>
            <?php
            if (oliit_table_exists($wpdb->prefix . 'pmxi_imports')) {
                $not_found_ids = array();

                foreach ($configured_import_ids as $import_id) {
                    $import_details = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, name, path, count FROM {$wpdb->prefix}pmxi_imports WHERE id = %d",
                        $import_id
                    ));

                    if ($import_details) {
                        $import_name   = !empty($import_details->name) ? $import_details->name : basename($import_details->path);
                        $product_count = $import_details->count ? $import_details->count : __('Unknown', 'wp-all-import-tracker');
                        ?>
                        <div class="oliit-import-card">
                            <p><strong><?php esc_html_e('Import Name:', 'wp-all-import-tracker'); ?></strong> <?php echo esc_html($import_name); ?></p>
                            <p><strong><?php esc_html_e('Import ID:', 'wp-all-import-tracker'); ?></strong> <?php echo esc_html((string) $import_id); ?></p>
                            <p><strong><?php esc_html_e('Import Rows:', 'wp-all-import-tracker'); ?></strong> <?php echo esc_html((string) $product_count); ?></p>
                            <?php
                            $last_import = $wpdb->get_var($wpdb->prepare(
                                "SELECT MAX(date) FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d",
                                $import_id
                            ));
                            if ($last_import) :
                                ?>
                                <p><strong><?php esc_html_e('Last Import:', 'wp-all-import-tracker'); ?></strong> <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_import))); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php
                    } else {
                        $not_found_ids[] = $import_id;
                    }
                }

                if (!empty($not_found_ids)) {
                    echo '<p class="oliit-warning"><strong>' . esc_html__('Warning:', 'wp-all-import-tracker') . '</strong> ';
                    echo esc_html(
                        sprintf(
                            /* translators: %s: import IDs */
                            __('Import ID(s) %s not found!', 'wp-all-import-tracker'),
                            implode(', ', $not_found_ids)
                        )
                    );
                    echo '</p>';
                    echo '<p>' . esc_html__('Please update the Import IDs below.', 'wp-all-import-tracker') . '</p>';
                }
            } else {
                echo '<p class="oliit-warning"><strong>' . esc_html__('Warning:', 'wp-all-import-tracker') . '</strong> ' . esc_html__('WP All Import not detected!', 'wp-all-import-tracker') . '</p>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render about sidebar box.
     *
     * @return void
     */
    private function render_about_box() {
        ?>
        <div class="oliit-settings-box">
            <h3><?php esc_html_e('About This Plugin', 'wp-all-import-tracker'); ?></h3>
            <p><?php esc_html_e('Oli Import Tracker for WP All Import helps you monitor which WooCommerce products were updated or skipped during WP All Import runs.', 'wp-all-import-tracker'); ?></p>
            <h4><?php esc_html_e('Features:', 'wp-all-import-tracker'); ?></h4>
            <ul>
                <li><?php esc_html_e('Import column in product list', 'wp-all-import-tracker'); ?></li>
                <li><?php esc_html_e('Filter products by import status', 'wp-all-import-tracker'); ?></li>
                <li><?php esc_html_e('Precise timestamps for updated products', 'wp-all-import-tracker'); ?></li>
                <li><?php esc_html_e('Automatic cache refresh after imports', 'wp-all-import-tracker'); ?></li>
            </ul>
        </div>
        <?php
    }

    /**
     * Render email status sidebar box.
     *
     * @return void
     */
    private function render_email_status_box() {
        $email_alerts_enabled = get_option('oliit_enable_email_alerts', 0);
        $alert_email          = get_option('oliit_alert_email', '');
        ?>
        <div class="oliit-settings-box">
            <h3><?php esc_html_e('Email Alerts Status', 'wp-all-import-tracker'); ?></h3>
            <?php if ($email_alerts_enabled) : ?>
                <p class="oliit-success"><strong><?php esc_html_e('Status:', 'wp-all-import-tracker'); ?></strong> <?php esc_html_e('Enabled', 'wp-all-import-tracker'); ?></p>
                <?php
                $email_to_use = !empty($alert_email) ? $alert_email : get_option('admin_email');
                ?>
                <p><strong><?php esc_html_e('Alert Email:', 'wp-all-import-tracker'); ?></strong> <?php echo esc_html($email_to_use); ?></p>
                <?php
                if (class_exists('OLIIT_Email_Alerts')) {
                    $status = OLIIT_Email_Alerts::get_import_status_summary();
                    echo '<p><strong>' . esc_html__('Import Status:', 'wp-all-import-tracker') . '</strong> ' . esc_html($status) . '</p>';
                }

                $next_cron = wp_next_scheduled('oliit_check_import_status');
                if ($next_cron) {
                    echo '<p><strong>' . esc_html__('Next Check:', 'wp-all-import-tracker') . '</strong> ' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_cron)) . '</p>';
                }
                ?>
            <?php else : ?>
                <p class="oliit-warning"><strong><?php esc_html_e('Status:', 'wp-all-import-tracker'); ?></strong> <?php esc_html_e('Disabled', 'wp-all-import-tracker'); ?></p>
                <p><?php esc_html_e('Enable email alerts below to monitor import failures.', 'wp-all-import-tracker'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render import ID help box.
     *
     * @return void
     */
    private function render_import_id_help_box() {
        ?>
        <div class="oliit-settings-box">
            <h3><?php esc_html_e('How to Find Import ID', 'wp-all-import-tracker'); ?></h3>
            <p><?php esc_html_e('To find your WP All Import ID:', 'wp-all-import-tracker'); ?></p>
            <ol>
                <li><?php esc_html_e('Go to All Import > Manage Imports', 'wp-all-import-tracker'); ?></li>
                <li><?php esc_html_e('Look at the URL when editing an import', 'wp-all-import-tracker'); ?></li>
                <li><?php esc_html_e('The ID is the number in "id=" parameter', 'wp-all-import-tracker'); ?></li>
            </ol>
            <p><em><?php esc_html_e('Example: ...&id=11 means Import ID is 11', 'wp-all-import-tracker'); ?></em></p>
        </div>
        <?php
    }

    /**
     * General section callback.
     *
     * @return void
     */
    public function general_section_callback() {
        echo '<p>' . esc_html__('Configure the basic settings for Oli Import Tracker for WP All Import.', 'wp-all-import-tracker') . '</p>';
    }

    /**
     * Import IDs field callback.
     *
     * @return void
     */
    public function import_ids_callback() {
        global $wpdb;
        $values = get_option('oliit_import_ids', array(1));
        if (!is_array($values)) {
            $values = array($values);
        }

        echo '<div id="oliit_import_ids_container">';
        foreach ($values as $value) {
            echo '<div class="oliit-import-id-row">';
            echo '<input type="number" name="oliit_import_ids[]" value="' . esc_attr($value) . '" min="1" class="regular-text oliit-import-id-input" />';
            if (count($values) > 1) {
                echo '<button type="button" class="button oliit-remove-import-id">' . esc_html__('Remove', 'wp-all-import-tracker') . '</button>';
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<button type="button" id="oliit_add_import_id" class="button oliit-add-import-id">' . esc_html__('Add Another Import ID', 'wp-all-import-tracker') . '</button>';
        echo '<button type="button" id="oliit_detect_imports" class="button oliit-detect-imports">' . esc_html__('Detect Available Imports', 'wp-all-import-tracker') . '</button>';
        echo '<p class="description">' . esc_html__('The WP All Import IDs to track. You can track multiple imports by adding more IDs.', 'wp-all-import-tracker') . '</p>';

        if (oliit_table_exists($wpdb->prefix . 'pmxi_imports')) {
            echo '<div id="oliit_available_imports" class="oliit-available-imports">';
            echo '<h4>' . esc_html__('Available Imports:', 'wp-all-import-tracker') . '</h4>';

            $imports = $wpdb->get_results("SELECT id, name, path FROM {$wpdb->prefix}pmxi_imports ORDER BY id DESC LIMIT 10");

            if ($imports) {
                echo '<table class="widefat oliit-available-imports-table">';
                echo '<thead><tr><th>' . esc_html__('ID', 'wp-all-import-tracker') . '</th><th>' . esc_html__('Import Name', 'wp-all-import-tracker') . '</th><th>' . esc_html__('Action', 'wp-all-import-tracker') . '</th></tr></thead>';
                echo '<tbody>';

                foreach ($imports as $import) {
                    $import_name = !empty($import->name) ? $import->name : basename($import->path);
                    $is_tracked  = in_array((int) $import->id, array_map('intval', $values), true) ? ' ' . __('(Tracked)', 'wp-all-import-tracker') : '';
                    echo '<tr>';
                    echo '<td><strong>' . esc_html((string) $import->id) . '</strong></td>';
                    echo '<td>' . esc_html($import_name . $is_tracked) . '</td>';
                    echo '<td><button type="button" class="button-secondary oliit-add-import" data-import-id="' . esc_attr($import->id) . '">' . esc_html__('Add', 'wp-all-import-tracker') . '</button></td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<p><em>' . esc_html__('No imports found. Make sure you have created at least one import in WP All Import.', 'wp-all-import-tracker') . '</em></p>';
            }

            echo '</div>';
        }
    }

    /**
     * Enable auto refresh field callback.
     *
     * @return void
     */
    public function enable_auto_refresh_callback() {
        $value = get_option('oliit_enable_auto_refresh', 1);
        echo '<input type="checkbox" id="oliit_enable_auto_refresh" name="oliit_enable_auto_refresh" value="1"' . checked(1, $value, false) . ' />';
        echo '<label for="oliit_enable_auto_refresh">' . esc_html__('Automatically refresh cache when imports start/finish', 'wp-all-import-tracker') . '</label>';
        echo '<p class="description">' . esc_html__('Recommended to keep enabled for automatic updates.', 'wp-all-import-tracker') . '</p>';
    }

    /**
     * Email section callback.
     *
     * @return void
     */
    public function email_section_callback() {
        echo '<p>' . esc_html__('Configure email alerts for import failures and issues. The system will monitor your imports and send alerts when problems are detected.', 'wp-all-import-tracker') . '</p>';
    }

    /**
     * Enable email alerts field callback.
     *
     * @return void
     */
    public function enable_email_alerts_callback() {
        $value = get_option('oliit_enable_email_alerts', 0);
        echo '<input type="checkbox" id="oliit_enable_email_alerts" name="oliit_enable_email_alerts" value="1"' . checked(1, $value, false) . ' />';
        echo '<label for="oliit_enable_email_alerts">' . esc_html__('Enable email alerts for import failures', 'wp-all-import-tracker') . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, the system will monitor imports and send email alerts when issues are detected.', 'wp-all-import-tracker') . '</p>';
    }

    /**
     * Alert email field callback.
     *
     * @return void
     */
    public function alert_email_callback() {
        $value = get_option('oliit_alert_email', '');
        echo '<input type="email" id="oliit_alert_email" name="oliit_alert_email" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<button type="button" id="oliit_test_email" class="button oliit-test-email">' . esc_html__('Send Test Email', 'wp-all-import-tracker') . '</button>';
        echo '<p class="description">' . esc_html__('Email address to receive import failure notifications. Leave empty to use the admin email.', 'wp-all-import-tracker') . '</p>';
        echo '<div id="oliit_test_email_result" class="oliit-ajax-result"></div>';
    }

    /**
     * Stale threshold field callback.
     *
     * @return void
     */
    public function stale_threshold_callback() {
        $value = get_option('oliit_stale_threshold', 150);
        echo '<input type="number" id="oliit_stale_threshold" name="oliit_stale_threshold" value="' . esc_attr($value) . '" min="30" max="1440" class="small-text" />';
        echo '<p class="description">' . esc_html__('Minutes of inactivity before considering an import as stale. Default: 150 minutes.', 'wp-all-import-tracker') . '</p>';
    }

    /**
     * Max execution time field callback.
     *
     * @return void
     */
    public function max_execution_time_callback() {
        $value = get_option('oliit_max_execution_time', 180);
        echo '<input type="number" id="oliit_max_execution_time" name="oliit_max_execution_time" value="' . esc_attr($value) . '" min="60" max="1440" class="small-text" />';
        echo '<p class="description">' . esc_html__('Maximum execution time in minutes before considering an import as overrunning. Default: 180 minutes.', 'wp-all-import-tracker') . '</p>';
    }

    /**
     * Refresh cache field callback.
     *
     * @return void
     */
    public function refresh_cache_callback() {
        echo '<button type="button" id="oliit_refresh_cache" class="button">' . esc_html__('Refresh Import Cache Now', 'wp-all-import-tracker') . '</button>';
        echo '<p class="description">' . esc_html__('Click to immediately refresh the import status cache and update the product counts.', 'wp-all-import-tracker') . '</p>';
        echo '<div id="oliit_refresh_result" class="oliit-ajax-result"></div>';
    }

    /**
     * Debug import callback.
     *
     * @return void
     */
    public function debug_import_callback() {
        global $wpdb;
        $configured_import_ids = oliit()->get_import_ids();

        echo '<button type="button" id="oliit_debug_import" class="button">' . esc_html__('Check Import Data Consistency', 'wp-all-import-tracker') . '</button>';
        echo '<p class="description">' . esc_html__('Check for inconsistencies between import history and pmxi_posts table.', 'wp-all-import-tracker') . '</p>';
        echo '<div id="oliit_debug_result" class="oliit-ajax-result"></div>';

        echo '<div class="oliit-debug-status">';
        echo '<strong>' . esc_html__('Current Status:', 'wp-all-import-tracker') . '</strong><br><br>';

        foreach ($configured_import_ids as $import_id) {
            $latest_summary = $wpdb->get_var($wpdb->prepare(
                "SELECT summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 1",
                $import_id
            ));

            $total_in_pmxi = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d",
                $import_id
            ));

            echo '<strong>' . esc_html(sprintf(/* translators: %d: import ID */ __('Import ID %d:', 'wp-all-import-tracker'), $import_id)) . '</strong><br>';
            echo esc_html__('Latest import summary:', 'wp-all-import-tracker') . ' ' . esc_html((string) $latest_summary) . '<br>';
            echo esc_html__('Products in pmxi_posts table:', 'wp-all-import-tracker') . ' ' . esc_html((string) $total_in_pmxi) . '<br>';

            if (is_string($latest_summary) && false !== strpos($latest_summary, '0 updated') && $total_in_pmxi > 0) {
                echo '<span class="oliit-warning"><strong>' . esc_html__('Inconsistency detected.', 'wp-all-import-tracker') . '</strong></span>';
            } else {
                echo '<span class="oliit-success"><strong>' . esc_html__('Data looks consistent', 'wp-all-import-tracker') . '</strong></span>';
            }
            echo '<br><br>';
        }
        echo '</div>';
    }

    /**
     * Notifications section callback.
     *
     * @return void
     */
    public function notifications_section_callback() {
        echo '<p>' . esc_html__('Manage import error notifications and their dismissal settings.', 'wp-all-import-tracker') . '</p>';
    }

    /**
     * Reset dismissed notifications callback.
     *
     * @return void
     */
    public function reset_dismissed_notifications_callback() {
        $dismissed_count = count(get_option('oliit_dismissed_notifications', array()));
        ?>
        <div>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: dismissed notification count */
                        __('Currently have %d dismissed notifications stored.', 'wp-all-import-tracker'),
                        $dismissed_count
                    )
                );
                ?>
            </p>
            <button type="button" id="oliit-reset-notifications" class="button button-secondary">
                <?php esc_html_e('Reset All Dismissed Notifications', 'wp-all-import-tracker'); ?>
            </button>
            <span id="oliit-reset-notifications-result" class="oliit-reset-result"></span>
            <p class="description">
                <?php esc_html_e('This will show all previously dismissed import error notifications again. Use this if you want to see notifications for issues that were previously dismissed.', 'wp-all-import-tracker'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Validate import IDs.
     *
     * @param mixed $values Submitted values.
     * @return array
     */
    public function validate_import_ids($values) {
        global $wpdb;

        if (!is_array($values)) {
            $values = array($values);
        }

        $valid_import_ids = array();
        $invalid_ids      = array();

        foreach ($values as $value) {
            $import_id = absint($value);
            if ($import_id > 0) {
                $valid_import_ids[] = $import_id;
            } else {
                $invalid_ids[] = $value;
            }
        }

        if (!empty($invalid_ids)) {
            add_settings_error(
                'oliit_import_ids',
                'invalid_import_ids',
                __('Some import IDs are invalid. Only positive numbers are allowed.', 'wp-all-import-tracker'),
                'error'
            );
            return get_option('oliit_import_ids', array(1));
        }

        if (empty($valid_import_ids)) {
            add_settings_error(
                'oliit_import_ids',
                'no_import_ids',
                __('At least one import ID is required.', 'wp-all-import-tracker'),
                'error'
            );
            return get_option('oliit_import_ids', array(1));
        }

        $valid_import_ids = array_values(array_unique($valid_import_ids));

        if (!oliit_table_exists($wpdb->prefix . 'pmxi_imports')) {
            add_settings_error(
                'oliit_import_ids',
                'no_wpai_tables',
                __('WP All Import tables not found. Please make sure WP All Import is installed and activated.', 'wp-all-import-tracker'),
                'error'
            );
            return get_option('oliit_import_ids', array(1));
        }

        $not_found_ids = array();
        $found_imports = array();

        foreach ($valid_import_ids as $import_id) {
            $import_exists = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, path, count FROM {$wpdb->prefix}pmxi_imports WHERE id = %d",
                $import_id
            ));

            if ($import_exists) {
                $found_imports[] = $import_exists;
            } else {
                $not_found_ids[] = $import_id;
            }
        }

        if (!empty($not_found_ids)) {
            add_settings_error(
                'oliit_import_ids',
                'import_ids_not_found',
                sprintf(
                    /* translators: %s: import IDs */
                    __('Import ID(s) %s not found in WP All Import. Please check the IDs and try again.', 'wp-all-import-tracker'),
                    implode(', ', $not_found_ids)
                ),
                'error'
            );
            return get_option('oliit_import_ids', array(1));
        }

        $import_details = array();
        foreach ($found_imports as $import) {
            $import_name   = !empty($import->name) ? $import->name : basename($import->path);
            $product_count = $import->count ? $import->count : __('Unknown', 'wp-all-import-tracker');
            $import_details[] = sprintf('"%s" (ID: %d, Products: %s)', $import_name, $import->id, $product_count);
        }

        add_settings_error(
            'oliit_import_ids',
            'import_ids_updated',
            sprintf(
                /* translators: %s: import details */
                __('Import IDs updated successfully! Now tracking: %s', 'wp-all-import-tracker'),
                implode(', ', $import_details)
            ),
            'updated'
        );

        return $valid_import_ids;
    }

    /**
     * Handle test email AJAX request.
     *
     * @return void
     */
    public function handle_test_email() {
        check_ajax_referer('oliit_test_email');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-all-import-tracker'));
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (empty($email)) {
            wp_send_json_error(__('Please enter a valid email address.', 'wp-all-import-tracker'));
        }

        require_once OLIIT_PLUGIN_DIR . 'includes/class-email-alerts.php';
        $email_alerts = new OLIIT_Email_Alerts();

        $original_email = get_option('oliit_alert_email', '');
        update_option('oliit_alert_email', $email);

        $result = $email_alerts->send_test_email();

        if (empty($original_email)) {
            delete_option('oliit_alert_email');
        } else {
            update_option('oliit_alert_email', $original_email);
        }

        if ($result) {
            wp_send_json_success(
                sprintf(
                    /* translators: %s: email address */
                    __('Test email sent successfully to %s!', 'wp-all-import-tracker'),
                    $email
                )
            );
        }

        wp_send_json_error(__('Failed to send test email. Please check your email configuration.', 'wp-all-import-tracker'));
    }

    /**
     * Handle refresh cache AJAX request.
     *
     * @return void
     */
    public function handle_refresh_cache() {
        check_ajax_referer('oliit_refresh_cache');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-all-import-tracker'));
        }

        $import_ids = array();
        if (isset($_POST['import_ids'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by oliit_sanitize_id_list().
            $import_ids = oliit_sanitize_id_list(wp_unslash($_POST['import_ids']));
        }

        $total_updated = 0;
        $total_skipped = 0;
        $results       = array();

        require_once OLIIT_PLUGIN_DIR . 'includes/class-admin.php';
        $admin = new OLIIT_Admin();

        foreach ($import_ids as $import_id) {
            if ($import_id <= 0) {
                continue;
            }

            delete_transient('oliit_counts_' . $import_id);

            $counts = $admin->get_import_status_counts($import_id);
            $total_updated += $counts['updated'];
            $total_skipped += $counts['skipped'];
            $results[] = sprintf(
                /* translators: 1: import ID, 2: updated count, 3: skipped count */
                __('Import ID %1$d: Updated: %2$d, Skipped: %3$d', 'wp-all-import-tracker'),
                $import_id,
                $counts['updated'],
                $counts['skipped']
            );
        }

        $message  = __('Cache refreshed successfully!', 'wp-all-import-tracker') . "\n" . implode("\n", $results);
        $message .= "\n" . sprintf(
            /* translators: 1: total updated count, 2: total skipped count */
            __('Total - Updated: %1$d, Skipped: %2$d', 'wp-all-import-tracker'),
            $total_updated,
            $total_skipped
        );

        wp_send_json_success($message);
    }

    /**
     * Handle debug import AJAX request.
     *
     * @return void
     */
    public function handle_debug_import() {
        global $wpdb;

        check_ajax_referer('oliit_debug_import');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-all-import-tracker'));
        }

        $import_ids = array();
        if (isset($_POST['import_ids'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by oliit_sanitize_id_list().
            $import_ids = oliit_sanitize_id_list(wp_unslash($_POST['import_ids']));
        }

        $message     = "Debug Results:\n";
        $total_fixed = 0;

        foreach ($import_ids as $import_id) {
            if ($import_id <= 0) {
                continue;
            }

            $message .= "\n--- Import ID {$import_id} ---\n";

            $latest_summary = $wpdb->get_var($wpdb->prepare(
                "SELECT summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 1",
                $import_id
            ));

            $total_in_pmxi = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d",
                $import_id
            ));

            $message .= "Latest import: {$latest_summary}\n";
            $message .= "Products in pmxi_posts: {$total_in_pmxi}\n";

            if (is_string($latest_summary) && false !== strpos($latest_summary, '0 updated') && $total_in_pmxi > 0) {
                $message .= "\nINCONSISTENCY DETECTED!\n";
                $message .= "Import shows 0 updated but pmxi_posts table has {$total_in_pmxi} entries.\n";
                $message .= "This suggests WP All Import didn't clear the table properly.\n\n";
                $message .= "FIXING: Clearing pmxi_posts table for this import...\n";

                $deleted = $wpdb->delete(
                    $wpdb->prefix . 'pmxi_posts',
                    array('import_id' => $import_id),
                    array('%d')
                );

                $message .= "Deleted {$deleted} entries from pmxi_posts table.\n";
                $message .= "Cache cleared. Counts should now be correct.\n";

                delete_transient('oliit_counts_' . $import_id);
                $total_fixed++;
            } else {
                $message .= "\nData looks consistent. No action needed.";
            }
        }

        if ($total_fixed > 0) {
            $message .= "\n\nTotal inconsistencies fixed: {$total_fixed}";
        }

        wp_send_json_success($message);
    }

    /**
     * Handle reset notifications AJAX request.
     *
     * @return void
     */
    public function handle_reset_notifications() {
        check_ajax_referer('oliit_reset_notifications');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-all-import-tracker'));
        }

        delete_option('oliit_dismissed_notifications');

        wp_send_json_success(__('All dismissed notifications have been reset. You will see import error notifications again.', 'wp-all-import-tracker'));
    }
}
