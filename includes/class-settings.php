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
     * Current settings tab.
     *
     * @return string
     */
    private function current_tab() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
        return in_array($tab, array('settings', 'tools'), true) ? $tab : 'settings';
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
                    'testEmail'          => wp_create_nonce('oliit_test_email'),
                    'refreshCache'       => wp_create_nonce('oliit_refresh_cache'),
                    'debugImport'        => wp_create_nonce('oliit_debug_import'),
                    'resetNotifications' => wp_create_nonce('oliit_reset_notifications'),
                ),
                'i18n'       => array(
                    'sending'        => __('Sending…', 'oli-import-tracker'),
                    'testEmailError' => __('Error sending test email. Please try again.', 'oli-import-tracker'),
                    'invalidEmail'   => __('Enter a valid email address.', 'oli-import-tracker'),
                    'refreshing'     => __('Refreshing…', 'oli-import-tracker'),
                    'refreshError'   => __('Error refreshing cache. Please try again.', 'oli-import-tracker'),
                    'checking'       => __('Checking…', 'oli-import-tracker'),
                    'debugError'     => __('Error checking import data.', 'oli-import-tracker'),
                    'resetting'      => __('Resetting…', 'oli-import-tracker'),
                    'resetLabel'     => __('Show dismissed notices again', 'oli-import-tracker'),
                    'resetError'     => __('The request failed.', 'oli-import-tracker'),
                    'neverRun'       => __('never run', 'oli-import-tracker'),
                    'thresholdError' => __('Maximum run time must be greater than or equal to the inactivity delay.', 'oli-import-tracker'),
                    'colImport'      => __('Import', 'oli-import-tracker'),
                    'colSummary'     => __('Latest summary', 'oli-import-tracker'),
                    'colCount'       => __('Rows in pmxi_posts', 'oli-import-tracker'),
                    'colStatus'      => __('Status', 'oli-import-tracker'),
                    'statusOk'       => __('Consistent', 'oli-import-tracker'),
                    'statusBad'      => __('Inconsistency fixed', 'oli-import-tracker'),
                    'addId'          => __('Add import ID', 'oli-import-tracker'),
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
            __('Import Tracker', 'oli-import-tracker'),
            __('Import Tracker', 'oli-import-tracker'),
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
                'default'           => array(),
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
                'sanitize_callback' => array($this, 'validate_alert_email'),
            )
        );

        register_setting(
            'oliit_settings',
            'oliit_stale_threshold',
            array(
                'type'              => 'integer',
                'default'           => 150,
                'sanitize_callback' => array($this, 'validate_stale_threshold'),
            )
        );

        register_setting(
            'oliit_settings',
            'oliit_max_execution_time',
            array(
                'type'              => 'integer',
                'default'           => 180,
                'sanitize_callback' => array($this, 'validate_max_execution_time'),
            )
        );

        add_settings_section(
            'oliit_imports_section',
            __('Tracked imports', 'oli-import-tracker'),
            array($this, 'imports_section_callback'),
            'oliit-settings'
        );

        add_settings_field(
            'oliit_import_ids',
            __('Imports to track', 'oli-import-tracker'),
            array($this, 'import_ids_callback'),
            'oliit-settings',
            'oliit_imports_section'
        );

        add_settings_field(
            'oliit_enable_auto_refresh',
            __('Refresh status automatically', 'oli-import-tracker'),
            array($this, 'enable_auto_refresh_callback'),
            'oliit-settings',
            'oliit_imports_section'
        );

        add_settings_section(
            'oliit_email_section',
            __('Email alerts', 'oli-import-tracker'),
            array($this, 'email_section_callback'),
            'oliit-settings'
        );

        add_settings_field(
            'oliit_enable_email_alerts',
            __('Email alerts', 'oli-import-tracker'),
            array($this, 'enable_email_alerts_callback'),
            'oliit-settings',
            'oliit_email_section'
        );

        add_settings_field(
            'oliit_alert_email',
            __('Recipient', 'oli-import-tracker'),
            array($this, 'alert_email_callback'),
            'oliit-settings',
            'oliit_email_section'
        );

        add_settings_field(
            'oliit_alert_advanced',
            __('Advanced', 'oli-import-tracker'),
            array($this, 'alert_advanced_callback'),
            'oliit-settings',
            'oliit_email_section'
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

        $tab = $this->current_tab();
        $base = admin_url('options-general.php?page=oliit-settings');

        if (isset($_GET['settings-updated'])) {
            add_settings_error('oliit_messages', 'oliit_message', __('Settings saved.', 'oli-import-tracker'), 'updated');
        }

        settings_errors('oliit_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url($base); ?>" class="nav-tab<?php echo 'settings' === $tab ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'oli-import-tracker'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'tools', $base)); ?>" class="nav-tab<?php echo 'tools' === $tab ? ' nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Tools', 'oli-import-tracker'); ?>
                </a>
            </nav>

            <?php
            if ('tools' === $tab) {
                $this->render_tools_tab();
                echo '</div>';
                return;
            }

            $this->render_status_box();
            ?>
            <form action="options.php" method="post" id="oliit-settings-form">
                <?php
                settings_fields('oliit_settings');
                do_settings_sections('oliit-settings');
                submit_button(__('Save Settings', 'oli-import-tracker'));
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Status box at the top of the settings tab.
     *
     * @return void
     */
    private function render_status_box() {
        $imports = oliit_get_available_imports();
        $tracked = oliit()->get_import_ids();
        $alerts  = (bool) get_option('oliit_enable_email_alerts', 0);
        $email   = get_option('oliit_alert_email', '');
        if (empty($email)) {
            $email = get_option('admin_email');
        }

        if (!oliit_wpai_is_available()) {
            echo '<div class="notice notice-error inline"><p>';
            echo esc_html__('WP All Import must be active.', 'oli-import-tracker');
            echo ' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Open plugins', 'oli-import-tracker') . '</a>';
            echo '</p></div>';
            return;
        }

        if (empty($imports)) {
            echo '<div class="notice notice-info inline"><p>';
            echo esc_html__('No WP All Import jobs found. Create an import, then come back here.', 'oli-import-tracker');
            echo '</p></div>';
            return;
        }

        echo '<div class="notice notice-info inline"><p>';
        if ($alerts) {
            echo esc_html(
                sprintf(
                    /* translators: 1: number of imports, 2: email address */
                    _n(
                        'Monitoring active for %1$d import · Alerts sent to %2$s',
                        'Monitoring active for %1$d imports · Alerts sent to %2$s',
                        count($tracked),
                        'oli-import-tracker'
                    ),
                    count($tracked),
                    $email
                )
            );
        } else {
            echo esc_html(
                sprintf(
                    /* translators: %d: number of imports */
                    _n(
                        'Monitoring active for %d import · Email alerts are off',
                        'Monitoring active for %d imports · Email alerts are off',
                        count($tracked),
                        'oli-import-tracker'
                    ),
                    count($tracked)
                )
            );
        }
        echo '</p></div>';
    }

    /**
     * Tools tab (actions only).
     *
     * @return void
     */
    private function render_tools_tab() {
        $dismissed_count = count(get_option('oliit_dismissed_notifications', array()));
        ?>
        <div class="oliit-tools">
            <h2><?php esc_html_e('Tools', 'oli-import-tracker'); ?></h2>
            <p><?php esc_html_e('These actions do not change your saved settings.', 'oli-import-tracker'); ?></p>

            <p>
                <button type="button" id="oliit_refresh_cache" class="button button-secondary">
                    <?php esc_html_e('Refresh now', 'oli-import-tracker'); ?>
                </button>
            </p>
            <div id="oliit_refresh_result" class="oliit-ajax-result" role="status" aria-live="polite"></div>

            <p>
                <button type="button" id="oliit_debug_import" class="button button-secondary">
                    <?php esc_html_e('Diagnose imports', 'oli-import-tracker'); ?>
                </button>
            </p>
            <div id="oliit_debug_result" class="oliit-ajax-result" role="status" aria-live="polite"></div>

            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d: dismissed notice count */
                        _n('%d dismissed notice', '%d dismissed notices', $dismissed_count, 'oli-import-tracker'),
                        $dismissed_count
                    )
                );
                ?>
            </p>
            <p>
                <button type="button" id="oliit-reset-notifications" class="button button-secondary">
                    <?php esc_html_e('Show dismissed notices again', 'oli-import-tracker'); ?>
                </button>
            </p>
            <div id="oliit-reset-notifications-result" class="oliit-ajax-result" role="status" aria-live="polite"></div>
        </div>
        <?php
    }

    /**
     * Tracked imports section.
     *
     * @return void
     */
    public function imports_section_callback() {
        echo '<p>' . esc_html__('Choose which WP All Import jobs to monitor. Scheduled (cron) jobs are selected on first activation when they can be detected.', 'oli-import-tracker') . '</p>';
    }

    /**
     * Import checkboxes.
     *
     * @return void
     */
    public function import_ids_callback() {
        $imports = oliit_get_available_imports();
        $values  = oliit()->get_import_ids();
        $known   = array();

        echo '<input type="hidden" name="oliit_import_ids[]" value="" />';
        echo '<fieldset class="oliit-import-fieldset">';
        echo '<legend class="screen-reader-text">' . esc_html__('Imports to track', 'oli-import-tracker') . '</legend>';

        if (count($imports) > 10) {
            echo '<p><label for="oliit-import-filter" class="screen-reader-text">' . esc_html__('Filter imports', 'oli-import-tracker') . '</label>';
            echo '<input type="search" id="oliit-import-filter" class="regular-text" placeholder="' . esc_attr__('Filter imports', 'oli-import-tracker') . '" /></p>';
        }

        echo '<div id="oliit-import-list">';
        foreach ($imports as $import) {
            $known[] = (int) $import['id'];
            $label   = sprintf(
                /* translators: 1: import ID, 2: import name, 3: last run label */
                __('#%1$d — %2$s (last run: %3$s)', 'oli-import-tracker'),
                $import['id'],
                $import['name'],
                $import['last_run_label']
            );
            echo '<label class="oliit-import-choice">';
            echo '<input type="checkbox" name="oliit_import_ids[]" value="' . esc_attr((string) $import['id']) . '" ' . checked(in_array((int) $import['id'], $values, true), true, false) . ' /> ';
            echo esc_html($label);
            echo '</label>';
        }

        $orphans = array_diff($values, $known);
        foreach ($orphans as $orphan_id) {
            $label = sprintf(
                /* translators: %d: import ID */
                __('#%d — saved ID (not in WP All Import)', 'oli-import-tracker'),
                $orphan_id
            );
            echo '<label class="oliit-import-choice">';
            echo '<input type="checkbox" name="oliit_import_ids[]" value="' . esc_attr((string) $orphan_id) . '" checked="checked" /> ';
            echo esc_html($label);
            echo '</label>';
        }

        if (empty($imports) && empty($orphans)) {
            echo '<p>' . esc_html__('No imports to list yet.', 'oli-import-tracker') . '</p>';
        }
        echo '</div>';
        echo '</fieldset>';

        echo '<details class="oliit-advanced">';
        echo '<summary>' . esc_html__('Advanced: add an import ID manually', 'oli-import-tracker') . '</summary>';
        echo '<p class="description">' . esc_html__('Use this if an import was deleted and recreated with a new ID.', 'oli-import-tracker') . '</p>';
        echo '<p><label for="oliit-manual-import-id">' . esc_html__('Import ID', 'oli-import-tracker') . '</label> ';
        echo '<input type="number" id="oliit-manual-import-id" min="1" class="small-text" /> ';
        echo '<button type="button" id="oliit-add-manual-id" class="button">' . esc_html__('Add import ID', 'oli-import-tracker') . '</button></p>';
        echo '</details>';
    }

    /**
     * Auto refresh field.
     *
     * @return void
     */
    public function enable_auto_refresh_callback() {
        $value = get_option('oliit_enable_auto_refresh', 1);
        echo '<input type="hidden" name="oliit_enable_auto_refresh" value="0" />';
        echo '<label for="oliit_enable_auto_refresh">';
        echo '<input type="checkbox" id="oliit_enable_auto_refresh" name="oliit_enable_auto_refresh" value="1"' . checked(1, $value, false) . ' /> ';
        echo esc_html__('Refresh status automatically', 'oli-import-tracker');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Updates the status in the admin without reloading the page.', 'oli-import-tracker') . '</p>';
    }

    /**
     * Email section intro.
     *
     * @return void
     */
    public function email_section_callback() {
        echo '<p>' . esc_html__('Email alerts stay off until you enable them.', 'oli-import-tracker') . '</p>';
    }

    /**
     * Enable email alerts field.
     *
     * @return void
     */
    public function enable_email_alerts_callback() {
        $value = get_option('oliit_enable_email_alerts', 0);
        echo '<input type="hidden" name="oliit_enable_email_alerts" value="0" />';
        echo '<label for="oliit_enable_email_alerts">';
        echo '<input type="checkbox" id="oliit_enable_email_alerts" name="oliit_enable_email_alerts" value="1"' . checked(1, $value, false) . ' /> ';
        echo esc_html__('Email alerts', 'oli-import-tracker');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Notify me when an import fails, gets stuck, or runs longer than expected.', 'oli-import-tracker') . '</p>';
    }

    /**
     * Alert email field.
     *
     * @return void
     */
    public function alert_email_callback() {
        $value = get_option('oliit_alert_email', '');
        echo '<div id="oliit-alert-email-wrap"' . (get_option('oliit_enable_email_alerts', 0) ? '' : ' hidden') . '>';
        echo '<input type="email" id="oliit_alert_email" name="oliit_alert_email" value="' . esc_attr($value) . '" class="regular-text" placeholder="' . esc_attr(get_option('admin_email')) . '" />';
        echo '<button type="button" id="oliit_test_email" class="button oliit-test-email">' . esc_html__('Send a test email', 'oli-import-tracker') . '</button>';
        echo '<p class="description">' . esc_html__('Leave empty to use the site admin email.', 'oli-import-tracker') . '</p>';
        echo '<p id="oliit_alert_email_error" class="oliit-field-error" hidden></p>';
        echo '<div id="oliit_test_email_result" class="oliit-ajax-result" role="status" aria-live="polite"></div>';
        echo '</div>';
    }

    /**
     * Advanced alert thresholds.
     *
     * @return void
     */
    public function alert_advanced_callback() {
        $stale   = get_option('oliit_stale_threshold', 150);
        $maximum = get_option('oliit_max_execution_time', 180);
        $hidden  = get_option('oliit_enable_email_alerts', 0) ? '' : ' hidden';
        ?>
        <div id="oliit-alert-advanced-wrap"<?php echo $hidden ? ' hidden' : ''; ?>>
            <details class="oliit-advanced">
                <summary><?php esc_html_e('Thresholds', 'oli-import-tracker'); ?></summary>
                <p>
                    <label for="oliit_stale_threshold"><?php esc_html_e('Inactivity before alert (minutes)', 'oli-import-tracker'); ?></label><br />
                    <input type="number" id="oliit_stale_threshold" name="oliit_stale_threshold" value="<?php echo esc_attr((string) $stale); ?>" min="15" max="1440" class="small-text" />
                </p>
                <p class="description"><?php esc_html_e('No records processed for this long.', 'oli-import-tracker'); ?></p>
                <p>
                    <label for="oliit_max_execution_time"><?php esc_html_e('Maximum run time (minutes)', 'oli-import-tracker'); ?></label><br />
                    <input type="number" id="oliit_max_execution_time" name="oliit_max_execution_time" value="<?php echo esc_attr((string) $maximum); ?>" min="30" max="1440" class="small-text" />
                </p>
                <p class="description"><?php esc_html_e('Must be greater than or equal to the inactivity delay.', 'oli-import-tracker'); ?></p>
            </details>
        </div>
        <?php
    }

    /**
     * Validate import IDs. Empty is allowed.
     *
     * @param mixed $values Submitted values.
     * @return int[]
     */
    public function validate_import_ids($values) {
        $ids = oliit_sanitize_id_list($values);
        return array_values(array_unique($ids));
    }

    /**
     * Validate alert email.
     *
     * @param string $value Submitted email.
     * @return string
     */
    public function validate_alert_email($value) {
        $value = sanitize_email($value);
        if ('' !== $value && !is_email($value)) {
            add_settings_error(
                'oliit_alert_email',
                'oliit_invalid_email',
                __('Enter a valid email address.', 'oli-import-tracker'),
                'error'
            );
            return get_option('oliit_alert_email', '');
        }
        return $value;
    }

    /**
     * Validate stale threshold (minimum 15).
     *
     * @param mixed $value Submitted value.
     * @return int
     */
    public function validate_stale_threshold($value) {
        return max(15, absint($value));
    }

    /**
     * Validate max execution time (minimum 30, >= stale).
     *
     * @param mixed $value Submitted value.
     * @return int
     */
    public function validate_max_execution_time($value) {
        $value = max(30, absint($value));
        $stale = (int) get_option('oliit_stale_threshold', 150);
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Settings API verifies the nonce before this callback.
        if (isset($_POST['oliit_stale_threshold'])) {
            $stale = max(15, absint(wp_unslash($_POST['oliit_stale_threshold'])));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($value < $stale) {
            add_settings_error(
                'oliit_max_execution_time',
                'oliit_threshold_mismatch',
                __('Maximum run time must be greater than or equal to the inactivity delay.', 'oli-import-tracker'),
                'error'
            );
            return $stale;
        }

        return $value;
    }

    /**
     * Handle test email AJAX request.
     *
     * @return void
     */
    public function handle_test_email() {
        check_ajax_referer('oliit_test_email');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'oli-import-tracker'));
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (empty($email)) {
            $email = get_option('admin_email');
        }
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(__('Enter a valid email address.', 'oli-import-tracker'));
        }

        require_once OLIIT_PLUGIN_DIR . 'includes/class-email-alerts.php';
        $email_alerts   = new OLIIT_Email_Alerts();
        $original_email = get_option('oliit_alert_email', '');
        update_option('oliit_alert_email', $email);
        $result = $email_alerts->send_test_email();

        if ('' === $original_email) {
            delete_option('oliit_alert_email');
        } else {
            update_option('oliit_alert_email', $original_email);
        }

        if ($result) {
            wp_send_json_success(
                sprintf(
                    /* translators: %s: email address */
                    __('Test email sent to %s.', 'oli-import-tracker'),
                    $email
                )
            );
        }

        wp_send_json_error(__('Failed to send the test email. Check the site mailer.', 'oli-import-tracker'));
    }

    /**
     * Handle refresh cache AJAX request.
     *
     * @return void
     */
    public function handle_refresh_cache() {
        check_ajax_referer('oliit_refresh_cache');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'oli-import-tracker'));
        }

        $import_ids = oliit()->get_import_ids();
        if (isset($_POST['import_ids'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $posted = oliit_sanitize_id_list(wp_unslash($_POST['import_ids']));
            if (!empty($posted)) {
                $import_ids = $posted;
            }
        }

        require_once OLIIT_PLUGIN_DIR . 'includes/class-admin.php';
        $admin         = new OLIIT_Admin();
        $total_updated = 0;
        $total_skipped = 0;
        $lines         = array();

        foreach ($import_ids as $import_id) {
            delete_transient('oliit_counts_' . $import_id);
            $counts         = $admin->get_import_status_counts($import_id);
            $total_updated += $counts['updated'];
            $total_skipped += $counts['skipped'];
            $lines[]        = sprintf(
                /* translators: 1: import ID, 2: updated count, 3: skipped count */
                __('Import #%1$d: %2$d updated, %3$d skipped', 'oli-import-tracker'),
                $import_id,
                $counts['updated'],
                $counts['skipped']
            );
        }

        $message = __('Cache refreshed.', 'oli-import-tracker');
        if ($lines) {
            $message .= ' ' . implode(' · ', $lines);
        }

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
            wp_send_json_error(__('Insufficient permissions.', 'oli-import-tracker'));
        }

        $import_ids = oliit()->get_import_ids();
        $rows       = array();

        foreach ($import_ids as $import_id) {
            $latest_summary = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 1",
                    $import_id
                )
            );
            $total_in_pmxi = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d",
                    $import_id
                )
            );

            $fixed = false;
            if (is_string($latest_summary) && false !== strpos($latest_summary, '0 updated') && $total_in_pmxi > 0) {
                $wpdb->delete(
                    $wpdb->prefix . 'pmxi_posts',
                    array('import_id' => $import_id),
                    array('%d')
                );
                delete_transient('oliit_counts_' . $import_id);
                $fixed         = true;
                $total_in_pmxi = 0;
            }

            $rows[] = array(
                'id'      => $import_id,
                'summary' => $latest_summary ? (string) $latest_summary : '',
                'count'   => (int) $total_in_pmxi,
                'status'  => $fixed ? 'fixed' : 'ok',
            );
        }

        wp_send_json_success(array('rows' => $rows));
    }

    /**
     * Handle reset notifications AJAX request.
     *
     * @return void
     */
    public function handle_reset_notifications() {
        check_ajax_referer('oliit_reset_notifications');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'oli-import-tracker'));
        }

        delete_option('oliit_dismissed_notifications');

        wp_send_json_success(__('Dismissed notices will show again.', 'oli-import-tracker'));
    }
}
