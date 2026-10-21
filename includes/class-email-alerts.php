<?php
/**
 * Email alerts class.
 *
 * @package Oli_Import_Tracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email alerts functionality.
 */
class OLIIT_Email_Alerts {

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
        add_action('pmxi_before_xml_import', array($this, 'track_import_start'), 10, 1);
        add_action('pmxi_after_xml_import', array($this, 'track_import_end'), 10, 1);
        add_action('wp_loaded', array($this, 'schedule_cron_job'));
        add_action('oliit_check_import_status', array($this, 'check_import_status'));
        add_action('admin_notices', array($this, 'show_stuck_import_notices'));
        add_action('admin_notices', array($this, 'show_setup_notice'));
        add_action('admin_init', array($this, 'handle_quick_actions'));
        add_action('oliit_deactivate', array($this, 'cleanup_cron_job'));
        add_action('wp_loaded', array($this, 'handle_cancel_import_request'));
        add_action('oliit_check_import_status', array($this, 'cleanup_old_dismissed_notifications'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_notice_styles'));
    }

    /**
     * Enqueue admin notice styles.
     *
     * @return void
     */
    public function enqueue_notice_styles() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style(
            'oliit-admin-notices',
            OLIIT_PLUGIN_URL . 'assets/css/admin-notices.css',
            array(),
            OLIIT_VERSION
        );
    }

    /**
     * Track import start time.
     *
     * @param int $import_id Import ID.
     * @return void
     */
    public function track_import_start($import_id) {
        update_option('oliit_import_start_time_' . absint($import_id), current_time('timestamp'));
        oliit_log('Import ' . absint($import_id) . ' started at ' . current_time('Y-m-d H:i:s'));
    }

    /**
     * Clear import start time on completion.
     *
     * @param int $import_id Import ID.
     * @return void
     */
    public function track_import_end($import_id) {
        delete_option('oliit_import_start_time_' . absint($import_id));
        oliit_log('Import ' . absint($import_id) . ' completed at ' . current_time('Y-m-d H:i:s'));
    }

    /**
     * Schedule the cron job.
     *
     * @return void
     */
    public function schedule_cron_job() {
        if (!wp_next_scheduled('oliit_check_import_status')) {
            wp_schedule_event(time(), 'hourly', 'oliit_check_import_status');
            oliit_log('Scheduled import monitoring cron job');
        }
    }

    /**
     * Clean up cron job on deactivation.
     *
     * @return void
     */
    public function cleanup_cron_job() {
        wp_clear_scheduled_hook('oliit_check_import_status');
        wp_clear_scheduled_hook('wpait_check_import_status');
        oliit_log('Cleaned up import monitoring cron job');
    }

    /**
     * Check import status and send alerts if needed.
     *
     * @return void
     */
    public function check_import_status() {
        global $wpdb;

        $stale_threshold_minutes = get_option('oliit_stale_threshold', 150);
        $max_execution_minutes   = get_option('oliit_max_execution_time', 180);
        $now                     = current_time('timestamp');
        $issues                  = array();

        if (!oliit_table_exists($wpdb->prefix . 'pmxi_imports')) {
            oliit_log('WP All Import tables not found, skipping monitoring');
            return;
        }

        $configured_import_ids = oliit()->get_import_ids();
        if (empty($configured_import_ids)) {
            oliit_log('No import IDs configured for monitoring');
            return;
        }

        $placeholders = oliit_ids_placeholders($configured_import_ids);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %d tokens.
        $imports = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, triggered, executing, last_activity, processing, failed, canceled FROM {$wpdb->prefix}pmxi_imports WHERE id IN ($placeholders)",
            $configured_import_ids
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (empty($imports)) {
            oliit_log('No configured imports found in database');
            return;
        }

        foreach ($imports as $import) {
            $import_id                   = $import->id;
            $import_name                 = !empty($import->name) ? $import->name : sprintf(/* translators: %d: import ID */ __('Import #%d', 'wp-all-import-tracker'), $import_id);
            $last_activity               = strtotime($import->last_activity);
            $minutes_since_last_activity = max(0, ($now - $last_activity) / 60);
            $start_time                  = get_option('oliit_import_start_time_' . $import_id, null);
            $minutes_since_start         = $start_time ? max(0, ($now - $start_time) / 60) : 0;

            if ($import->executing && $minutes_since_last_activity > $stale_threshold_minutes) {
                $issues[] = sprintf(
                    /* translators: 1: import name, 2: import ID, 3: minutes since last activity */
                    __('Import "%1$s" (ID: %2$d) is executing but last activity was %3$.1f minutes ago.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $minutes_since_last_activity
                );
            }

            if ($import->executing && $start_time && $minutes_since_start > $max_execution_minutes) {
                $issues[] = sprintf(
                    /* translators: 1: import name, 2: import ID, 3: minutes since start */
                    __('Import "%1$s" (ID: %2$d) has been executing for over %3$.1f minutes (possible overrun).', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $minutes_since_start
                );
            }

            if ($import->triggered && !$import->executing && $minutes_since_last_activity > $stale_threshold_minutes) {
                $issues[] = sprintf(
                    /* translators: 1: import name, 2: import ID, 3: minutes since last activity */
                    __('Import "%1$s" (ID: %2$d) was triggered but has not started executing for over %3$.1f minutes.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $minutes_since_last_activity
                );
            }

            if ($import->processing && !$import->executing && $minutes_since_last_activity > $stale_threshold_minutes) {
                $issues[] = sprintf(
                    /* translators: 1: import name, 2: import ID, 3: minutes since last activity */
                    __('Import "%1$s" (ID: %2$d) is stuck in PROCESSING state with no activity for %3$.1f minutes.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $minutes_since_last_activity
                );
            }

            $hours_since_last_activity = $minutes_since_last_activity / 60;
            if ($import->failed && $hours_since_last_activity > 1) {
                $issues[] = sprintf(
                    /* translators: 1: import name, 2: import ID, 3: hours since last activity */
                    __('Import "%1$s" (ID: %2$d) has been in FAILED state for %3$.1f hours.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $hours_since_last_activity
                );
            }

            if ($import->canceled && $hours_since_last_activity > 1) {
                $issues[] = sprintf(
                    /* translators: 1: import name, 2: import ID, 3: hours since last activity */
                    __('Import "%1$s" (ID: %2$d) has been CANCELED for %3$.1f hours.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $hours_since_last_activity
                );
            }
        }

        if (!empty($issues)) {
            $this->handle_detected_issues($issues);
        } else {
            delete_option('oliit_current_issues');
            delete_option('oliit_issues_detected_time');
            oliit_log('Import monitoring check completed - no issues found');
        }
    }

    /**
     * Get Action Scheduler job details for WP All Import.
     *
     * @return string
     */
    private function get_action_scheduler_details() {
        global $wpdb;

        $extra_info    = '';
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';

        if (!oliit_table_exists($actions_table)) {
            return $extra_info;
        }

        $like = $wpdb->esc_like('wp_all_import') . '%';

        $last_job_with_logs = $wpdb->get_row($wpdb->prepare(
            "SELECT a.action_id, a.hook, a.status, a.last_attempt_gmt, l.log_date_gmt, l.message
            FROM {$wpdb->prefix}actionscheduler_actions a
            INNER JOIN {$wpdb->prefix}actionscheduler_logs l ON a.action_id = l.action_id
            WHERE a.hook LIKE %s
            ORDER BY l.log_date_gmt DESC
            LIMIT 1",
            $like
        ));

        if (!$last_job_with_logs) {
            $last_job_with_logs = $wpdb->get_row($wpdb->prepare(
                "SELECT a.action_id, a.hook, a.status, a.last_attempt_gmt, NULL as log_date_gmt, NULL as message
                FROM {$wpdb->prefix}actionscheduler_actions a
                WHERE a.hook LIKE %s
                ORDER BY a.last_attempt_gmt DESC
                LIMIT 1",
                $like
            ));
        }

        if ($last_job_with_logs) {
            $extra_info  = "\n\n" . __('Last Import Job Info:', 'wp-all-import-tracker') . "\n";
            $extra_info .= sprintf(/* translators: %s: action ID */ __('Action ID: %s', 'wp-all-import-tracker'), $last_job_with_logs->action_id) . "\n";
            $extra_info .= sprintf(/* translators: %s: hook name */ __('Hook: %s', 'wp-all-import-tracker'), $last_job_with_logs->hook) . "\n";
            $extra_info .= sprintf(/* translators: %s: status */ __('Status: %s', 'wp-all-import-tracker'), $last_job_with_logs->status) . "\n";
            $extra_info .= sprintf(/* translators: %s: last attempt timestamp */ __('Last Attempt: %s', 'wp-all-import-tracker'), $last_job_with_logs->last_attempt_gmt) . "\n";

            if (!empty($last_job_with_logs->message)) {
                $extra_info .= sprintf(/* translators: %s: log message */ __('Last Log: %s', 'wp-all-import-tracker'), $last_job_with_logs->message) . "\n";
            }
        }

        return $extra_info;
    }

    /**
     * Send alert email.
     *
     * @param string[] $issues     Issue messages.
     * @param bool     $first_time Whether this is the first auto-activation email.
     * @return bool
     */
    private function send_alert_email($issues, $first_time = false) {
        $alert_email = get_option('oliit_alert_email', '');
        if (empty($alert_email)) {
            $alert_email = get_option('admin_email');
        }

        if (empty($alert_email)) {
            oliit_log('No email address configured for alerts');
            return false;
        }

        $site_name = get_bloginfo('name');
        $site_url  = get_site_url();

        if ($first_time) {
            $subject = sprintf(
                /* translators: %s: site name */
                __('[%s] Oli Import Tracker Auto-Activated: Issues Detected', 'wp-all-import-tracker'),
                $site_name
            );
            $message = sprintf(
                /* translators: %s: site name */
                __("Hello,\n\nOli Import Tracker for WP All Import has automatically activated email alerts because we detected issues with your imports on %s.\n\nThis is the first time issues have been detected, so we've enabled monitoring to help prevent data sync problems.\n\nCurrent Issues:\n\n", 'wp-all-import-tracker'),
                $site_name
            );
        } else {
            $subject = sprintf(
                /* translators: %s: site name */
                __('[%s] Import Alert: Import Issues Detected', 'wp-all-import-tracker'),
                $site_name
            );
            $message = sprintf(
                /* translators: %s: site name */
                __("Hello,\n\nOli Import Tracker for WP All Import has detected the following issues with your imports on %s:\n\n", 'wp-all-import-tracker'),
                $site_name
            );
        }

        foreach ($issues as $issue) {
            $message .= '• ' . $issue . "\n";
        }

        $message .= $this->get_action_scheduler_details();

        $cancel_links = $this->get_cancel_links_for_stuck_imports();
        if (!empty($cancel_links)) {
            $message .= "\n" . __('Quick Actions - Click to cancel stuck imports:', 'wp-all-import-tracker') . "\n\n";
            foreach ($cancel_links as $import_id => $cancel_info) {
                $message .= sprintf(
                    /* translators: 1: import name, 2: import ID, 3: cancel URL */
                    __('• Cancel Import "%1$s" (ID: %2$d): %3$s', 'wp-all-import-tracker'),
                    $cancel_info['name'],
                    $import_id,
                    $cancel_info['url']
                ) . "\n";
            }
        }

        $message .= sprintf(
            /* translators: %s: site URL */
            __("\nPlease check your WP All Import dashboard at: %s/wp-admin/admin.php?page=pmxi-admin-manage\n\n", 'wp-all-import-tracker'),
            $site_url
        );

        $message .= sprintf(
            /* translators: %s: site URL */
            __("You can configure these alerts in: %s/wp-admin/options-general.php?page=oliit-settings\n\n", 'wp-all-import-tracker'),
            $site_url
        );

        $message .= __("Best regards,\nOli Import Tracker for WP All Import", 'wp-all-import-tracker');

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
        );

        $mail_sent = wp_mail($alert_email, $subject, $message, $headers);

        if ($mail_sent) {
            oliit_log('Alert email sent successfully to ' . $alert_email);
            foreach ($issues as $issue) {
                oliit_log('Issue detected - ' . $issue);
            }
        } else {
            oliit_log('Failed to send alert email to ' . $alert_email);
        }

        return $mail_sent;
    }

    /**
     * Handle detected issues with smart auto-activation.
     *
     * @param string[] $issues Issue messages.
     * @return void
     */
    private function handle_detected_issues($issues) {
        update_option('oliit_current_issues', $issues);
        update_option('oliit_issues_detected_time', current_time('timestamp'));

        $alerts_enabled        = get_option('oliit_enable_email_alerts', 0);
        $first_issues_detected = !get_option('oliit_first_issues_handled', 0);

        if (!$alerts_enabled && $first_issues_detected) {
            oliit_log('First import issues detected - attempting smart auto-activation');

            $test_email_sent = $this->send_alert_email($issues, true);

            if ($test_email_sent) {
                update_option('oliit_enable_email_alerts', 1);
                update_option('oliit_auto_enabled_reason', 'first_issue_detected');
                update_option('oliit_auto_enabled_time', current_time('timestamp'));
                oliit_log('Smart auto-activation successful - email alerts enabled');
                add_action('admin_notices', array($this, 'notice_auto_activated'));
            } else {
                oliit_log('Smart auto-activation failed - email delivery issue, keeping alerts disabled');
                add_action('admin_notices', array($this, 'notice_email_config_needed'));
            }

            update_option('oliit_first_issues_handled', 1);
        } elseif ($alerts_enabled) {
            $this->send_alert_email($issues);
        }

        foreach ($issues as $issue) {
            oliit_log('Issue detected - ' . $issue);
        }
    }

    /**
     * Notice after successful auto-activation.
     *
     * @return void
     */
    public function notice_auto_activated() {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<h3>' . esc_html__('Oli Import Tracker Auto-Activated!', 'wp-all-import-tracker') . '</h3>';
        echo '<p>' . esc_html__('We detected import issues and automatically enabled email alerts. You will now be notified when imports get stuck or fail.', 'wp-all-import-tracker') . '</p>';
        echo '<p><strong>' . esc_html(sprintf(/* translators: %s: email address */ __('Alerts sent to: %s', 'wp-all-import-tracker'), get_option('admin_email'))) . '</strong></p>';
        echo '</div>';
    }

    /**
     * Notice when email delivery failed.
     *
     * @return void
     */
    public function notice_email_config_needed() {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<h3>' . esc_html__('Email Configuration Needed', 'wp-all-import-tracker') . '</h3>';
        echo '<p>' . esc_html__('We detected import issues but could not send email alerts. You will see admin notices instead.', 'wp-all-import-tracker') . '</p>';
        echo '<p>' . wp_kses_post(
            sprintf(
                /* translators: 1: opening anchor, 2: closing anchor */
                __('To enable email alerts, %1$scheck your email configuration%2$s first.', 'wp-all-import-tracker'),
                '<a href="' . esc_url(admin_url('options-general.php?page=oliit-settings')) . '">',
                '</a>'
            )
        ) . '</p>';
        echo '</div>';
    }

    /**
     * Test email functionality.
     *
     * @return bool
     */
    public function send_test_email() {
        $test_issues = array(
            __('This is a test email from Oli Import Tracker for WP All Import.', 'wp-all-import-tracker'),
            __('If you receive this email, the alert system is working correctly.', 'wp-all-import-tracker'),
        );

        return $this->send_alert_email($test_issues);
    }

    /**
     * Get import status summary for admin.
     *
     * @return string
     */
    public static function get_import_status_summary() {
        global $wpdb;

        if (!oliit_table_exists($wpdb->prefix . 'pmxi_imports')) {
            return __('WP All Import not detected', 'wp-all-import-tracker');
        }

        $configured_import_ids = oliit()->get_import_ids();
        if (empty($configured_import_ids)) {
            return __('No imports configured', 'wp-all-import-tracker');
        }

        $placeholders = oliit_ids_placeholders($configured_import_ids);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %d tokens.
        $imports = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, triggered, executing, last_activity FROM {$wpdb->prefix}pmxi_imports WHERE id IN ($placeholders)",
            $configured_import_ids
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if (empty($imports)) {
            return __('No configured imports found', 'wp-all-import-tracker');
        }

        $executing_count = 0;
        $triggered_count = 0;
        $idle_count      = 0;

        foreach ($imports as $import) {
            if ($import->executing) {
                $executing_count++;
            } elseif ($import->triggered) {
                $triggered_count++;
            } else {
                $idle_count++;
            }
        }

        return sprintf(
            /* translators: 1: total imports, 2: executing count, 3: triggered count, 4: idle count */
            __('%1$d configured imports: %2$d executing, %3$d triggered, %4$d idle', 'wp-all-import-tracker'),
            count($imports),
            $executing_count,
            $triggered_count,
            $idle_count
        );
    }

    /**
     * Get cancel links for stuck imports.
     *
     * @return array<int,array{name:string,url:string}>
     */
    private function get_cancel_links_for_stuck_imports() {
        global $wpdb;

        $stale_threshold_minutes = get_option('oliit_stale_threshold', 150);
        $max_execution_minutes   = get_option('oliit_max_execution_time', 180);
        $now                     = current_time('timestamp');
        $cancel_links            = array();

        if (!oliit_table_exists($wpdb->prefix . 'pmxi_imports')) {
            return $cancel_links;
        }

        $configured_import_ids = oliit()->get_import_ids();
        if (empty($configured_import_ids)) {
            return $cancel_links;
        }

        $placeholders = oliit_ids_placeholders($configured_import_ids);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %d tokens.
        $imports = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, triggered, executing, last_activity FROM {$wpdb->prefix}pmxi_imports WHERE id IN ($placeholders)",
            $configured_import_ids
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ($imports as $import) {
            $import_id                   = $import->id;
            $import_name                 = !empty($import->name) ? $import->name : sprintf(/* translators: %d: import ID */ __('Import #%d', 'wp-all-import-tracker'), $import_id);
            $last_activity               = strtotime($import->last_activity);
            $minutes_since_last_activity = max(0, ($now - $last_activity) / 60);
            $start_time                  = get_option('oliit_import_start_time_' . $import_id, null);
            $minutes_since_start         = $start_time ? max(0, ($now - $start_time) / 60) : 0;
            $is_stuck                    = false;

            if ($import->executing && $minutes_since_last_activity > $stale_threshold_minutes) {
                $is_stuck = true;
            } elseif ($import->executing && $start_time && $minutes_since_start > $max_execution_minutes) {
                $is_stuck = true;
            } elseif ($import->triggered && !$import->executing && $minutes_since_last_activity > $stale_threshold_minutes) {
                $is_stuck = true;
            }

            if ($is_stuck) {
                $cancel_links[$import_id] = array(
                    'name' => $import_name,
                    'url'  => $this->generate_cancel_url($import_id),
                );
            }
        }

        return $cancel_links;
    }

    /**
     * Generate secure cancel URL for an import.
     *
     * @param int $import_id Import ID.
     * @return string
     */
    private function generate_cancel_url($import_id) {
        $nonce    = wp_create_nonce('oliit_cancel_import_' . $import_id);
        $base_url = get_site_url();

        return add_query_arg(
            array(
                'oliit_action' => 'cancel_import',
                'import_id'    => $import_id,
                'nonce'        => $nonce,
            ),
            $base_url
        );
    }

    /**
     * Handle cancel import request from an email link.
     *
     * @return void
     */
    public function handle_cancel_import_request() {
        if (!isset($_GET['oliit_action']) || 'cancel_import' !== $_GET['oliit_action']) {
            return;
        }

        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to cancel imports.', 'wp-all-import-tracker'));
        }

        $import_id = isset($_GET['import_id']) ? absint($_GET['import_id']) : 0;
        $nonce     = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'oliit_cancel_import_' . $import_id)) {
            wp_die(esc_html__('Security check failed. Invalid or expired link.', 'wp-all-import-tracker'));
        }

        global $wpdb;
        $import = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, executing, triggered FROM {$wpdb->prefix}pmxi_imports WHERE id = %d",
            $import_id
        ));

        if (!$import) {
            wp_die(esc_html__('Import not found.', 'wp-all-import-tracker'));
        }

        $result = $this->cancel_import($import_id);

        if ($result) {
            $import_name = !empty($import->name) ? $import->name : sprintf(/* translators: %d: import ID */ __('Import #%d', 'wp-all-import-tracker'), $import_id);
            oliit_log("Import {$import_id} ({$import_name}) cancelled via email link");

            wp_die(
                wp_kses_post(
                    sprintf(
                        /* translators: 1: import name, 2: import ID */
                        __('Import "%1$s" (ID: %2$d) has been successfully cancelled.<br><br>The import will be reset and can be restarted from the WP All Import dashboard.', 'wp-all-import-tracker'),
                        esc_html($import_name),
                        $import_id
                    )
                ),
                esc_html__('Import Cancelled Successfully', 'wp-all-import-tracker'),
                array('response' => 200)
            );
        }

        wp_die(esc_html__('Failed to cancel import. Please try again or cancel manually from WP All Import dashboard.', 'wp-all-import-tracker'));
    }

    /**
     * Cancel import.
     *
     * @param int $import_id Import ID.
     * @return bool
     */
    private function cancel_import($import_id) {
        global $wpdb;

        try {
            $updated = $wpdb->update(
                $wpdb->prefix . 'pmxi_imports',
                array(
                    'executing'   => 0,
                    'triggered'   => 0,
                    'canceled'    => 1,
                    'canceled_on' => current_time('mysql'),
                ),
                array('id' => $import_id),
                array('%d', '%d', '%d', '%s'),
                array('%d')
            );

            if (false === $updated) {
                oliit_log("Failed to update import status for import {$import_id}");
                return false;
            }

            delete_option('oliit_import_start_time_' . $import_id);

            $processing_file = wp_upload_dir()['basedir'] . '/wpallimport/processing/' . $import_id . '.txt';
            if (file_exists($processing_file)) {
                wp_delete_file($processing_file);
            }

            delete_option('wp_all_import_cron_job_key_' . $import_id);
            delete_option('wp_all_import_processing_' . $import_id);

            return true;
        } catch (Exception $e) {
            oliit_log("Exception while cancelling import {$import_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Show setup notice for first-time users.
     *
     * @return void
     */
    public function show_setup_notice() {
        if (!get_option('oliit_show_setup_notice', 0)) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $enable_url = add_query_arg(
            array(
                'oliit_action' => 'enable_alerts',
                'nonce'        => wp_create_nonce('oliit_enable_alerts'),
            )
        );

        $dismiss_url = add_query_arg(
            array(
                'oliit_action' => 'dismiss_setup',
                'nonce'        => wp_create_nonce('oliit_dismiss_setup'),
            )
        );
        ?>
        <div class="notice notice-info is-dismissible">
            <h3><?php esc_html_e('Oli Import Tracker Active', 'wp-all-import-tracker'); ?></h3>
            <p>
                <?php esc_html_e('Your WP All Import jobs are now being monitored. If any issues are detected, we will automatically enable email alerts and notify you.', 'wp-all-import-tracker'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Zero configuration required!', 'wp-all-import-tracker'); ?></strong>
                <?php esc_html_e('The plugin works automatically and will enable alerts when needed.', 'wp-all-import-tracker'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($enable_url); ?>" class="button button-secondary">
                    <?php esc_html_e('Enable Alerts Now (Optional)', 'wp-all-import-tracker'); ?>
                </a>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-primary">
                    <?php esc_html_e('Got It, Thanks!', 'wp-all-import-tracker'); ?>
                </a>
            </p>
            <p><small>
                <?php
                echo wp_kses_post(
                    sprintf(
                        /* translators: 1: admin email, 2: opening settings link, 3: closing settings link */
                        __('Monitoring active • Alerts will be sent to: %1$s • %2$sChange settings%3$s', 'wp-all-import-tracker'),
                        '<strong>' . esc_html(get_option('admin_email')) . '</strong>',
                        '<a href="' . esc_url(admin_url('options-general.php?page=oliit-settings')) . '">',
                        '</a>'
                    )
                );
                ?>
            </small></p>
        </div>
        <?php
    }

    /**
     * Show admin notices for stuck imports.
     *
     * @return void
     */
    public function show_stuck_import_notices() {
        $issues      = get_option('oliit_current_issues', array());
        $issues_time = get_option('oliit_issues_detected_time', 0);

        if (empty($issues) || !current_user_can('manage_options')) {
            return;
        }

        if ((current_time('timestamp') - $issues_time) > (6 * HOUR_IN_SECONDS)) {
            return;
        }

        $dismissed_notifications = get_option('oliit_dismissed_notifications', array());
        $notification_hash       = md5(serialize($issues) . $issues_time);

        if (in_array($notification_hash, $dismissed_notifications, true)) {
            return;
        }

        $enable_alerts_url = add_query_arg(
            array(
                'oliit_action' => 'enable_alerts',
                'nonce'        => wp_create_nonce('oliit_enable_alerts'),
            )
        );

        $dismiss_url = add_query_arg(
            array(
                'oliit_action'       => 'dismiss_notification',
                'notification_hash'  => $notification_hash,
                'nonce'              => wp_create_nonce('oliit_dismiss_notification'),
            )
        );

        $alerts_enabled = get_option('oliit_enable_email_alerts', 0);
        ?>
        <div class="notice notice-warning" id="oliit-import-notice">
            <div class="oliit-notice-layout">
                <div class="oliit-notice-body">
                    <h3><?php esc_html_e('WP All Import Issues Detected', 'wp-all-import-tracker'); ?></h3>
                    <div class="oliit-notice-issues">
                        <?php foreach ($issues as $issue) : ?>
                            <p class="oliit-notice-issue">• <?php echo esc_html($issue); ?></p>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$alerts_enabled) : ?>
                        <p>
                            <a href="<?php echo esc_url($enable_alerts_url); ?>" class="button button-primary">
                                <?php esc_html_e('Enable Email Alerts for Future Issues', 'wp-all-import-tracker'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pmxi-admin-manage')); ?>" class="button">
                                <?php esc_html_e('Fix in WP All Import', 'wp-all-import-tracker'); ?>
                            </a>
                        </p>
                    <?php else : ?>
                        <p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pmxi-admin-manage')); ?>" class="button button-primary">
                                <?php esc_html_e('Fix in WP All Import', 'wp-all-import-tracker'); ?>
                            </a>
                            <span class="oliit-notice-ok"><?php esc_html_e('Email alerts are enabled', 'wp-all-import-tracker'); ?></span>
                        </p>
                    <?php endif; ?>

                    <p><small>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: 1: date/time, 2: minutes until next check */
                                __('Issues detected at: %1$s | Next check in ~%2$d minutes', 'wp-all-import-tracker'),
                                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $issues_time),
                                max(0, 60 - (int) ((current_time('timestamp') - $issues_time) / 60))
                            )
                        );
                        ?>
                    </small></p>
                </div>
                <div class="oliit-notice-dismiss-wrap">
                    <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-small oliit-notice-dismiss" title="<?php esc_attr_e('Dismiss this notification', 'wp-all-import-tracker'); ?>">
                        <?php esc_html_e('Dismiss', 'wp-all-import-tracker'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle quick actions (enable alerts, dismiss notices, etc.).
     *
     * @return void
     */
    public function handle_quick_actions() {
        if (!isset($_GET['oliit_action']) || !current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_GET['oliit_action']));
        $nonce  = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';

        switch ($action) {
            case 'enable_alerts':
                if (wp_verify_nonce($nonce, 'oliit_enable_alerts')) {
                    update_option('oliit_enable_email_alerts', 1);
                    update_option('oliit_show_setup_notice', 0);
                    $test_sent = $this->send_test_email();
                    if ($test_sent) {
                        add_action('admin_notices', array($this, 'notice_alerts_enabled'));
                    } else {
                        add_action('admin_notices', array($this, 'notice_alerts_enabled_mail_failed'));
                    }
                    oliit_log('Email alerts enabled via quick action');
                }
                break;

            case 'dismiss_setup':
                if (wp_verify_nonce($nonce, 'oliit_dismiss_setup')) {
                    update_option('oliit_show_setup_notice', 0);
                    add_action('admin_notices', array($this, 'notice_setup_dismissed'));
                }
                break;

            case 'dismiss_notification':
                if (wp_verify_nonce($nonce, 'oliit_dismiss_notification')) {
                    $notification_hash       = isset($_GET['notification_hash']) ? sanitize_text_field(wp_unslash($_GET['notification_hash'])) : '';
                    $dismissed_notifications = get_option('oliit_dismissed_notifications', array());

                    if ($notification_hash && !in_array($notification_hash, $dismissed_notifications, true)) {
                        $dismissed_notifications[] = $notification_hash;
                        update_option('oliit_dismissed_notifications', $dismissed_notifications);
                    }

                    add_action('admin_notices', array($this, 'notice_notification_dismissed'));
                    oliit_log('Import notification dismissed by user');
                }
                break;
        }

        if (isset($_GET['oliit_action'])) {
            wp_safe_redirect(remove_query_arg(array('oliit_action', 'nonce', 'notification_hash')));
            exit;
        }
    }

    /**
     * Notice after alerts were enabled and a test email was sent.
     *
     * @return void
     */
    public function notice_alerts_enabled() {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Email alerts enabled! A test email was sent to confirm delivery.', 'wp-all-import-tracker') . '</p></div>';
    }

    /**
     * Notice after alerts were enabled but the test email failed.
     *
     * @return void
     */
    public function notice_alerts_enabled_mail_failed() {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Email alerts enabled, but test email failed. Please check your email configuration.', 'wp-all-import-tracker') . '</p></div>';
    }

    /**
     * Notice after the setup banner was dismissed.
     *
     * @return void
     */
    public function notice_setup_dismissed() {
        echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post(
            sprintf(
                /* translators: 1: opening settings link, 2: closing settings link */
                __('Setup dismissed. You can enable email alerts anytime in %1$sSettings%2$s.', 'wp-all-import-tracker'),
                '<a href="' . esc_url(admin_url('options-general.php?page=oliit-settings')) . '">',
                '</a>'
            )
        ) . '</p></div>';
    }

    /**
     * Notice after an import error notification was dismissed.
     *
     * @return void
     */
    public function notice_notification_dismissed() {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Import notification dismissed. You can still check import status in WP All Import.', 'wp-all-import-tracker') . '</p></div>';
    }

    /**
     * Clean up old dismissed notifications.
     *
     * @return void
     */
    public function cleanup_old_dismissed_notifications() {
        $dismissed_notifications = get_option('oliit_dismissed_notifications', array());

        if (count($dismissed_notifications) > 50) {
            $dismissed_notifications = array_slice($dismissed_notifications, -25);
            update_option('oliit_dismissed_notifications', $dismissed_notifications);
            oliit_log('Cleaned up old dismissed notifications');
        }
    }
}
