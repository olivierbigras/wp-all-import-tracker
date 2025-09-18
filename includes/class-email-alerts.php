<?php
/**
 * WP All Import Tracker - Email Alerts Class
 * 
 * @package WP_All_Import_Tracker
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email alerts functionality
 */
class WPAIT_Email_Alerts {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Always track imports and check status (for admin notices)
        add_action('pmxi_before_xml_import', array($this, 'track_import_start'), 10, 1);
        add_action('pmxi_after_xml_import', array($this, 'track_import_end'), 10, 1);
        add_action('wp_loaded', array($this, 'schedule_cron_job'));
        add_action('wpait_check_import_status', array($this, 'check_import_status'));
        
        // Admin notices for stuck imports (always show)
        add_action('admin_notices', array($this, 'show_stuck_import_notices'));
        
        // Setup wizard and one-click activation
        add_action('admin_notices', array($this, 'show_setup_notice'));
        add_action('admin_init', array($this, 'handle_quick_actions'));
        
        // Always hook deactivation to clean up
        add_action('wpait_deactivate', array($this, 'cleanup_cron_job'));
        
        // Hook for handling cancel import requests
        add_action('wp_loaded', array($this, 'handle_cancel_import_request'));
        
        // Clean up old dismissed notifications periodically
        add_action('wpait_check_import_status', array($this, 'cleanup_old_dismissed_notifications'));
    }
    
    /**
     * Track import start time
     */
    public function track_import_start($import_id) {
        update_option("wpait_import_start_time_{$import_id}", current_time('timestamp'));
        error_log("WPAIT: Import {$import_id} started at " . current_time('Y-m-d H:i:s'));
    }
    
    /**
     * Clear import start time on completion
     */
    public function track_import_end($import_id) {
        delete_option("wpait_import_start_time_{$import_id}");
        error_log("WPAIT: Import {$import_id} completed at " . current_time('Y-m-d H:i:s'));
    }
    
    /**
     * Schedule the cron job
     */
    public function schedule_cron_job() {
        if (!wp_next_scheduled('wpait_check_import_status')) {
            wp_schedule_event(time(), 'hourly', 'wpait_check_import_status');
            error_log('WPAIT: Scheduled import monitoring cron job');
        }
    }
    
    /**
     * Clean up cron job on deactivation
     */
    public function cleanup_cron_job() {
        wp_clear_scheduled_hook('wpait_check_import_status');
        error_log('WPAIT: Cleaned up import monitoring cron job');
    }
    
    /**
     * Check import status and send alerts if needed
     */
    public function check_import_status() {
        global $wpdb;
        
        // Get settings
        $stale_threshold_minutes = get_option('wpait_stale_threshold', 150);
        $max_execution_minutes = get_option('wpait_max_execution_time', 180);
        
        $now = current_time('timestamp');
        $issues = [];
        
        // Check if WP All Import tables exist
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pmxi_imports'")) {
            error_log('WPAIT: WP All Import tables not found, skipping monitoring');
            return;
        }
        
        // Get configured import IDs to monitor
        $configured_import_ids = WPAIT()->get_import_ids();
        
        if (empty($configured_import_ids)) {
            error_log('WPAIT: No import IDs configured for monitoring');
            return;
        }
        
        // Build placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($configured_import_ids), '%d'));
        
        // Get only configured imports with additional fields to detect processing/stuck states
        $imports = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, triggered, executing, last_activity, processing, failed, canceled FROM {$wpdb->prefix}pmxi_imports WHERE id IN ($placeholders)",
            $configured_import_ids
        ));
        
        if (empty($imports)) {
            error_log('WPAIT: No configured imports found in database');
            return;
        }
        
        foreach ($imports as $import) {
            $import_id = $import->id;
            $import_name = !empty($import->name) ? $import->name : "Import #{$import_id}";
            $last_activity = strtotime($import->last_activity);
            $minutes_since_last_activity = max(0, ($now - $last_activity) / 60);
            
            // Retrieve tracked start time
            $start_time = get_option("wpait_import_start_time_{$import_id}", null);
            $minutes_since_start = $start_time ? max(0, ($now - $start_time) / 60) : 0;
            
            // Case 1: executing, but stale activity
            if ($import->executing && $minutes_since_last_activity > $stale_threshold_minutes) {
                /* translators: %1$s: import name, %2$d: import ID, %3$.1f: minutes since last activity */
                $issues[] = sprintf(
                    __('Import "%1$s" (ID: %2$d) is executing but last activity was %3$.1f minutes ago.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $minutes_since_last_activity
                );
            }
            
            // Case 2: executing for unusually long time (based on tracked start time)
            if ($import->executing && $start_time && $minutes_since_start > $max_execution_minutes) {
                /* translators: %1$s: import name, %2$d: import ID, %3$.1f: minutes since start */
                $issues[] = sprintf(
                    __('Import "%1$s" (ID: %2$d) has been executing for over %3$.1f minutes (possible overrun).', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $minutes_since_start
                );
            }
            
            // Case 3: triggered but never started executing
            if ($import->triggered && !$import->executing && $minutes_since_last_activity > $stale_threshold_minutes) {
                /* translators: %1$s: import name, %2$d: import ID, %3$.1f: minutes since last activity */
                $issues[] = sprintf(
                    __('Import "%1$s" (ID: %2$d) was triggered but has not started executing for over %3$.1f minutes.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $minutes_since_last_activity
                );
            }
            
            // Case 4: stuck in processing state (like your current issue!)
            if ($import->processing && !$import->executing && $minutes_since_last_activity > $stale_threshold_minutes) {
                /* translators: %1$s: import name, %2$d: import ID, %3$.1f: minutes since last activity */
                $issues[] = sprintf(
                    __('Import "%1$s" (ID: %2$d) is stuck in PROCESSING state with no activity for %3$.1f minutes.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $minutes_since_last_activity
                );
            }
            
            // Case 5: failed imports
            $hours_since_last_activity = $minutes_since_last_activity / 60;
            if ($import->failed && $hours_since_last_activity > 1) {
                /* translators: %1$s: import name, %2$d: import ID, %3$.1f: hours since last activity */
                $issues[] = sprintf(
                    __('Import "%1$s" (ID: %2$d) has been in FAILED state for %3$.1f hours.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $hours_since_last_activity
                );
            }
            
            // Case 6: canceled imports that haven't been restarted
            if ($import->canceled && $hours_since_last_activity > 1) {
                /* translators: %1$s: import name, %2$d: import ID, %3$.1f: hours since last activity */
                $issues[] = sprintf(
                    __('Import "%1$s" (ID: %2$d) has been CANCELED for %3$.1f hours.', 'wp-all-import-tracker'),
                    $import_name,
                    $import_id,
                    $hours_since_last_activity
                );
            }
        }
        
        // Handle issues with smart auto-activation
        if (!empty($issues)) {
            $this->handle_detected_issues($issues);
        } else {
            // Clear stored issues if no problems found
            delete_option('wpait_current_issues');
            delete_option('wpait_issues_detected_time');
            error_log('WPAIT: Import monitoring check completed - no issues found');
        }
    }
    
    /**
     * Get Action Scheduler job details for WP All Import
     */
    private function get_action_scheduler_details() {
        global $wpdb;
        
        $extra_info = '';
        
        // Check if Action Scheduler tables exist
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $logs_table = $wpdb->prefix . 'actionscheduler_logs';
        
        if (!$wpdb->get_var("SHOW TABLES LIKE '$actions_table'")) {
            return $extra_info;
        }
        
        // First try to get job with logs (most recent with activity)
        $last_job_with_logs = $wpdb->get_row("
            SELECT a.action_id, a.hook, a.status, a.last_attempt_gmt, l.log_date_gmt, l.message
            FROM {$wpdb->prefix}actionscheduler_actions a
            INNER JOIN {$wpdb->prefix}actionscheduler_logs l ON a.action_id = l.action_id
            WHERE a.hook LIKE 'wp_all_import%'
            ORDER BY l.log_date_gmt DESC
            LIMIT 1
        ");
        
        // If no job with logs, get the most recent job regardless of logs
        if (!$last_job_with_logs) {
            $last_job_with_logs = $wpdb->get_row("
                SELECT a.action_id, a.hook, a.status, a.last_attempt_gmt, NULL as log_date_gmt, NULL as message
                FROM {$wpdb->prefix}actionscheduler_actions a
                WHERE a.hook LIKE 'wp_all_import%'
                ORDER BY a.last_attempt_gmt DESC
                LIMIT 1
            ");
        }
        
        if ($last_job_with_logs) {
            $extra_info = "\n\n" . __("Last Import Job Info:", 'wp-all-import-tracker') . "\n";
            $extra_info .= sprintf(__("Action ID: %s", 'wp-all-import-tracker'), $last_job_with_logs->action_id) . "\n";
            $extra_info .= sprintf(__("Hook: %s", 'wp-all-import-tracker'), $last_job_with_logs->hook) . "\n";
            $extra_info .= sprintf(__("Status: %s", 'wp-all-import-tracker'), $last_job_with_logs->status) . "\n";
            $extra_info .= sprintf(__("Last Attempt: %s", 'wp-all-import-tracker'), $last_job_with_logs->last_attempt_gmt) . "\n";
            
            if (!empty($last_job_with_logs->message)) {
                $extra_info .= sprintf(__("Last Log: %s", 'wp-all-import-tracker'), $last_job_with_logs->message) . "\n";
            }
        }
        
        return $extra_info;
    }
    
    /**
     * Send alert email
     */
    private function send_alert_email($issues, $first_time = false) {
        // Get email address
        $alert_email = get_option('wpait_alert_email', '');
        if (empty($alert_email)) {
            $alert_email = get_option('admin_email');
        }
        
        if (empty($alert_email)) {
            error_log('WPAIT: No email address configured for alerts');
            return false;
        }
        
        // Prepare email content
        $site_name = get_bloginfo('name');
        $site_url = get_site_url();
        
        if ($first_time) {
            /* translators: %s: site name */
            $subject = sprintf(
                __('[%s] WP All Import Tracker Auto-Activated: Issues Detected', 'wp-all-import-tracker'),
                $site_name
            );
            
            /* translators: %s: site name */
            $message = sprintf(
                __("Hello,\n\nWP All Import Tracker has automatically activated email alerts because we detected issues with your imports on %s.\n\nThis is the first time issues have been detected, so we've enabled monitoring to help prevent data sync problems.\n\nCurrent Issues:\n\n", 'wp-all-import-tracker'),
                $site_name
            );
        } else {
            /* translators: %s: site name */
            $subject = sprintf(
                __('[%s] WP All Import Alert: Import Issues Detected', 'wp-all-import-tracker'),
                $site_name
            );
            
            /* translators: %s: site name */
            $message = sprintf(
                __("Hello,\n\nWP All Import Tracker has detected the following issues with your imports on %s:\n\n", 'wp-all-import-tracker'),
                $site_name
            );
        }
        
        foreach ($issues as $issue) {
            $message .= "• " . $issue . "\n";
        }
        
        // Add Action Scheduler details
        $action_scheduler_info = $this->get_action_scheduler_details();
        $message .= $action_scheduler_info;
        
        // Add cancel links for stuck imports
        $cancel_links = $this->get_cancel_links_for_stuck_imports();
        if (!empty($cancel_links)) {
            $message .= "\n" . __("Quick Actions - Click to cancel stuck imports:", 'wp-all-import-tracker') . "\n\n";
            foreach ($cancel_links as $import_id => $cancel_info) {
                /* translators: %1$s: import name, %2$d: import ID, %3$s: cancel URL */
                $message .= sprintf(
                    __("• Cancel Import \"%1\$s\" (ID: %2\$d): %3\$s", 'wp-all-import-tracker'),
                    $cancel_info['name'],
                    $import_id,
                    $cancel_info['url']
                ) . "\n";
            }
        }
        
        /* translators: %s: site URL */
        $message .= sprintf(
            __("\nPlease check your WP All Import dashboard at: %s/wp-admin/admin.php?page=pmxi-admin-manage\n\n", 'wp-all-import-tracker'),
            $site_url
        );
        
        /* translators: %s: site URL */
        $message .= sprintf(
            __("You can configure these alerts in: %s/wp-admin/options-general.php?page=wpait-settings\n\n", 'wp-all-import-tracker'),
            $site_url
        );
        
        $message .= __("Best regards,\nWP All Import Tracker", 'wp-all-import-tracker');
        
        // Send email
        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );
        
        $mail_sent = wp_mail($alert_email, $subject, $message, $headers);
        
        if ($mail_sent) {
            error_log('WPAIT: Alert email sent successfully to ' . $alert_email);
            
            // Log the issues for debugging
            foreach ($issues as $issue) {
                error_log('WPAIT: Issue detected - ' . $issue);
            }
        } else {
            error_log('WPAIT: Failed to send alert email to ' . $alert_email);
        }
        
        return $mail_sent;
    }
    
    /**
     * Handle detected issues with smart auto-activation
     */
    private function handle_detected_issues($issues) {
        // Always store issues for admin notices
        update_option('wpait_current_issues', $issues);
        update_option('wpait_issues_detected_time', current_time('timestamp'));
        
        $alerts_enabled = get_option('wpait_enable_email_alerts', 0);
        $first_issues_detected = !get_option('wpait_first_issues_handled', 0);
        
        // Smart auto-activation: Enable alerts on first issue detection
        if (!$alerts_enabled && $first_issues_detected) {
            error_log('WPAIT: First import issues detected - attempting smart auto-activation');
            
            // Try sending email first to test if it works
            $test_email_sent = $this->send_alert_email($issues, true); // true = first time
            
            if ($test_email_sent) {
                // Email works! Enable alerts permanently
                update_option('wpait_enable_email_alerts', 1);
                update_option('wpait_auto_enabled_reason', 'first_issue_detected');
                update_option('wpait_auto_enabled_time', current_time('timestamp'));
                
                error_log('WPAIT: Smart auto-activation successful - email alerts enabled');
                
                // Show success notice
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<h3>🚀 ' . __('WP All Import Tracker Auto-Activated!', 'wp-all-import-tracker') . '</h3>';
                    echo '<p>' . __('We detected import issues and automatically enabled email alerts. You\'ll now be notified when imports get stuck or fail.', 'wp-all-import-tracker') . '</p>';
                    echo '<p><strong>' . sprintf(__('Alerts sent to: %s', 'wp-all-import-tracker'), get_option('admin_email')) . '</strong></p>';
                    echo '</div>';
                });
                
            } else {
                // Email failed - keep alerts disabled, rely on admin notices
                error_log('WPAIT: Smart auto-activation failed - email delivery issue, keeping alerts disabled');
                
                // Show helpful notice
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning is-dismissible">';
                    echo '<h3>📧 ' . __('Email Configuration Needed', 'wp-all-import-tracker') . '</h3>';
                    echo '<p>' . __('We detected import issues but couldn\'t send email alerts. You\'ll see admin notices instead.', 'wp-all-import-tracker') . '</p>';
                    echo '<p>' . sprintf(
                        __('To enable email alerts, %scheck your email configuration%s first.', 'wp-all-import-tracker'),
                        '<a href="' . esc_url(admin_url('options-general.php?page=wpait-settings')) . '">',
                        '</a>'
                    ) . '</p>';
                    echo '</div>';
                });
            }
            
            // Mark first issues as handled
            update_option('wpait_first_issues_handled', 1);
            
        } elseif ($alerts_enabled) {
            // Alerts already enabled - send normally
            $this->send_alert_email($issues);
        }
        
        // Always log issues for debugging
        foreach ($issues as $issue) {
            error_log('WPAIT: Issue detected - ' . $issue);
        }
    }
    
    /**
     * Test email functionality
     */
    public function send_test_email() {
        $test_issues = array(
            __('This is a test email from WP All Import Tracker.', 'wp-all-import-tracker'),
            __('If you receive this email, the alert system is working correctly.', 'wp-all-import-tracker')
        );
        
        return $this->send_alert_email($test_issues);
    }
    
    /**
     * Get import status summary for admin
     */
    public function get_import_status_summary() {
        global $wpdb;
        
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pmxi_imports'")) {
            return __('WP All Import not detected', 'wp-all-import-tracker');
        }
        
        // Get configured import IDs to monitor
        $configured_import_ids = WPAIT()->get_import_ids();
        
        if (empty($configured_import_ids)) {
            return __('No imports configured', 'wp-all-import-tracker');
        }
        
        // Build placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($configured_import_ids), '%d'));
        
        // Get only configured imports
        $imports = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, triggered, executing, last_activity FROM {$wpdb->prefix}pmxi_imports WHERE id IN ($placeholders)",
            $configured_import_ids
        ));
        
        if (empty($imports)) {
            return __('No configured imports found', 'wp-all-import-tracker');
        }
        
        $executing_count = 0;
        $triggered_count = 0;
        $idle_count = 0;
        
        foreach ($imports as $import) {
            if ($import->executing) {
                $executing_count++;
            } elseif ($import->triggered) {
                $triggered_count++;
            } else {
                $idle_count++;
            }
        }
        
        /* translators: %1$d: total imports, %2$d: executing count, %3$d: triggered count, %4$d: idle count */
        return sprintf(
            __('%1$d configured imports: %2$d executing, %3$d triggered, %4$d idle', 'wp-all-import-tracker'),
            count($imports),
            $executing_count,
            $triggered_count,
            $idle_count
        );
    }
    
    /**
     * Get cancel links for stuck imports
     */
    private function get_cancel_links_for_stuck_imports() {
        global $wpdb;
        
        // Get settings
        $stale_threshold_minutes = get_option('wpait_stale_threshold', 150);
        $max_execution_minutes = get_option('wpait_max_execution_time', 180);
        
        $now = current_time('timestamp');
        $cancel_links = array();
        
        // Check if WP All Import tables exist
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pmxi_imports'")) {
            return $cancel_links;
        }
        
        // Get configured import IDs to monitor
        $configured_import_ids = WPAIT()->get_import_ids();
        
        if (empty($configured_import_ids)) {
            return $cancel_links;
        }
        
        // Build placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($configured_import_ids), '%d'));
        
        // Get only configured imports that might be stuck
        $imports = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, triggered, executing, last_activity FROM {$wpdb->prefix}pmxi_imports WHERE id IN ($placeholders)",
            $configured_import_ids
        ));
        
        foreach ($imports as $import) {
            $import_id = $import->id;
            $import_name = !empty($import->name) ? $import->name : "Import #{$import_id}";
            $last_activity = strtotime($import->last_activity);
            $minutes_since_last_activity = max(0, ($now - $last_activity) / 60);
            
            $start_time = get_option("wpait_import_start_time_{$import_id}", null);
            $minutes_since_start = $start_time ? max(0, ($now - $start_time) / 60) : 0;
            
            $is_stuck = false;
            
            // Check if import is stuck based on same criteria as alerts
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
                    'url' => $this->generate_cancel_url($import_id)
                );
            }
        }
        
        return $cancel_links;
    }
    
    /**
     * Generate secure cancel URL for import
     */
    private function generate_cancel_url($import_id) {
        $nonce = wp_create_nonce('wpait_cancel_import_' . $import_id);
        $base_url = get_site_url();
        
        return add_query_arg(array(
            'wpait_action' => 'cancel_import',
            'import_id' => $import_id,
            'nonce' => $nonce
        ), $base_url);
    }
    
    /**
     * Handle cancel import request from email link
     */
    public function handle_cancel_import_request() {
        // Check if this is a cancel request
        if (!isset($_GET['wpait_action']) || $_GET['wpait_action'] !== 'cancel_import') {
            return;
        }
        
        // Get parameters
        $import_id = intval($_GET['import_id']);
        $nonce = sanitize_text_field($_GET['nonce']);
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, 'wpait_cancel_import_' . $import_id)) {
            wp_die(__('Security check failed. Invalid or expired link.', 'wp-all-import-tracker'));
        }
        
        // Verify import exists
        global $wpdb;
        $import = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, executing, triggered FROM {$wpdb->prefix}pmxi_imports WHERE id = %d",
            $import_id
        ));
        
        if (!$import) {
            wp_die(__('Import not found.', 'wp-all-import-tracker'));
        }
        
        // Cancel the import
        $result = $this->cancel_import($import_id);
        
        if ($result) {
            $import_name = !empty($import->name) ? $import->name : "Import #{$import_id}";
            
            // Log the cancellation
            error_log("WPAIT: Import {$import_id} ({$import_name}) cancelled via email link");
            
            // Show success message
            /* translators: %1$s: import name, %2$d: import ID */
            wp_die(
                sprintf(
                    __('Import "%1$s" (ID: %2$d) has been successfully cancelled.<br><br>The import will be reset and can be restarted from the WP All Import dashboard.', 'wp-all-import-tracker'),
                    esc_html($import_name),
                    $import_id
                ),
                __('Import Cancelled Successfully', 'wp-all-import-tracker'),
                array('response' => 200)
            );
        } else {
            wp_die(__('Failed to cancel import. Please try again or cancel manually from WP All Import dashboard.', 'wp-all-import-tracker'));
        }
    }
    
    /**
     * Cancel import (same logic as WP All Import cancel button)
     */
    private function cancel_import($import_id) {
        global $wpdb;
        
        try {
            // Update import status to stop execution
            $updated = $wpdb->update(
                $wpdb->prefix . 'pmxi_imports',
                array(
                    'executing' => 0,
                    'triggered' => 0,
                    'canceled' => 1,
                    'canceled_on' => current_time('mysql')
                ),
                array('id' => $import_id),
                array('%d', '%d', '%d', '%s'),
                array('%d')
            );
            
            if ($updated === false) {
                error_log("WPAIT: Failed to update import status for import {$import_id}");
                return false;
            }
            
            // Clear any running processes
            delete_option("wpait_import_start_time_{$import_id}");
            
            // Clear WP All Import processing files if they exist
            $processing_file = wp_upload_dir()['basedir'] . "/wpallimport/processing/{$import_id}.txt";
            if (file_exists($processing_file)) {
                unlink($processing_file);
            }
            
            // Clear any WP All Import locks
            delete_option("wp_all_import_cron_job_key_{$import_id}");
            delete_option("wp_all_import_processing_{$import_id}");
            
            return true;
            
        } catch (Exception $e) {
            error_log("WPAIT: Exception while cancelling import {$import_id}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Show setup notice for first-time users
     */
    public function show_setup_notice() {
        if (!get_option('wpait_show_setup_notice', 0)) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $enable_url = add_query_arg(array(
            'wpait_action' => 'enable_alerts',
            'nonce' => wp_create_nonce('wpait_enable_alerts')
        ));
        
        $dismiss_url = add_query_arg(array(
            'wpait_action' => 'dismiss_setup',
            'nonce' => wp_create_nonce('wpait_dismiss_setup')
        ));
        
        ?>
        <div class="notice notice-info is-dismissible">
            <h3><?php _e('🚀 WP All Import Tracker Active', 'wp-all-import-tracker'); ?></h3>
            <p>
                <?php _e('Your WP All Import jobs are now being monitored. If any issues are detected, we\'ll automatically enable email alerts and notify you.', 'wp-all-import-tracker'); ?>
            </p>
            <p>
                <strong><?php _e('Zero configuration required!', 'wp-all-import-tracker'); ?></strong>
                <?php _e('The plugin works automatically and will enable alerts when needed.', 'wp-all-import-tracker'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($enable_url); ?>" class="button button-secondary">
                    <?php _e('Enable Alerts Now (Optional)', 'wp-all-import-tracker'); ?>
                </a>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-primary">
                    <?php _e('Got It, Thanks!', 'wp-all-import-tracker'); ?>
                </a>
            </p>
            <p><small>
                <?php printf(
                    __('Monitoring active • Alerts will be sent to: %s • %sChange settings%s', 'wp-all-import-tracker'),
                    '<strong>' . esc_html(get_option('admin_email')) . '</strong>',
                    '<a href="' . esc_url(admin_url('options-general.php?page=wpait-settings')) . '">',
                    '</a>'
                ); ?>
            </small></p>
        </div>
        <?php
    }
    
    /**
     * Show admin notices for stuck imports
     */
    public function show_stuck_import_notices() {
        $issues = get_option('wpait_current_issues', array());
        $issues_time = get_option('wpait_issues_detected_time', 0);
        
        if (empty($issues) || !current_user_can('manage_options')) {
            return;
        }
        
        // Don't show if issues are older than 6 hours (stale)
        if ((current_time('timestamp') - $issues_time) > (6 * HOUR_IN_SECONDS)) {
            return;
        }
        
        // Check if user has dismissed this specific notification
        $dismissed_notifications = get_option('wpait_dismissed_notifications', array());
        $notification_hash = md5(serialize($issues) . $issues_time);
        
        if (in_array($notification_hash, $dismissed_notifications)) {
            return;
        }
        
        $enable_alerts_url = add_query_arg(array(
            'wpait_action' => 'enable_alerts',
            'nonce' => wp_create_nonce('wpait_enable_alerts')
        ));
        
        $dismiss_url = add_query_arg(array(
            'wpait_action' => 'dismiss_notification',
            'notification_hash' => $notification_hash,
            'nonce' => wp_create_nonce('wpait_dismiss_notification')
        ));
        
        $alerts_enabled = get_option('wpait_enable_email_alerts', 0);
        
        ?>
        <div class="notice notice-warning" id="wpait-import-notice">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">
                    <h3><?php _e('⚠️ WP All Import Issues Detected', 'wp-all-import-tracker'); ?></h3>
                    <div style="max-height: 150px; overflow-y: auto; margin: 10px 0;">
                        <?php foreach ($issues as $issue) : ?>
                            <p style="margin: 5px 0;">• <?php echo esc_html($issue); ?></p>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (!$alerts_enabled) : ?>
                        <p>
                            <a href="<?php echo esc_url($enable_alerts_url); ?>" class="button button-primary">
                                <?php _e('🔔 Enable Email Alerts for Future Issues', 'wp-all-import-tracker'); ?>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pmxi-admin-manage')); ?>" class="button">
                                <?php _e('🔧 Fix in WP All Import', 'wp-all-import-tracker'); ?>
                            </a>
                        </p>
                    <?php else : ?>
                        <p>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=pmxi-admin-manage')); ?>" class="button button-primary">
                                <?php _e('🔧 Fix in WP All Import', 'wp-all-import-tracker'); ?>
                            </a>
                            <span style="color: #46b450;">✅ <?php _e('Email alerts are enabled', 'wp-all-import-tracker'); ?></span>
                        </p>
                    <?php endif; ?>
                    
                    <p><small>
                        <?php printf(
                            __('Issues detected at: %s | Next check in ~%d minutes', 'wp-all-import-tracker'),
                            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $issues_time),
                            60 - (int) ((current_time('timestamp') - $issues_time) / 60)
                        ); ?>
                    </small></p>
                </div>
                <div style="margin-left: 15px;">
                    <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-small" style="color: #666; text-decoration: none;" title="<?php _e('Dismiss this notification', 'wp-all-import-tracker'); ?>">
                        ✕
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handle quick actions (enable alerts, dismiss notices, etc.)
     */
    public function handle_quick_actions() {
        if (!isset($_GET['wpait_action']) || !current_user_can('manage_options')) {
            return;
        }
        
        $action = sanitize_text_field($_GET['wpait_action']);
        $nonce = sanitize_text_field($_GET['nonce']);
        
        switch ($action) {
            case 'enable_alerts':
                if (wp_verify_nonce($nonce, 'wpait_enable_alerts')) {
                    update_option('wpait_enable_email_alerts', 1);
                    update_option('wpait_show_setup_notice', 0); // Hide setup notice
                    
                    // Send test email to confirm it works
                    $test_sent = $this->send_test_email();
                    
                    if ($test_sent) {
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-success is-dismissible">';
                            echo '<p>✅ ' . __('Email alerts enabled! A test email was sent to confirm delivery.', 'wp-all-import-tracker') . '</p>';
                            echo '</div>';
                        });
                    } else {
                        add_action('admin_notices', function() {
                            echo '<div class="notice notice-warning is-dismissible">';
                            echo '<p>⚠️ ' . __('Email alerts enabled, but test email failed. Please check your email configuration.', 'wp-all-import-tracker') . '</p>';
                            echo '</div>';
                        });
                    }
                    
                    error_log('WPAIT: Email alerts enabled via quick action');
                }
                break;
                
            case 'dismiss_setup':
                if (wp_verify_nonce($nonce, 'wpait_dismiss_setup')) {
                    update_option('wpait_show_setup_notice', 0);
                    
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-info is-dismissible">';
                        echo '<p>' . sprintf(
                            __('Setup dismissed. You can enable email alerts anytime in %sSettings%s.', 'wp-all-import-tracker'),
                            '<a href="' . esc_url(admin_url('options-general.php?page=wpait-settings')) . '">',
                            '</a>'
                        ) . '</p>';
                        echo '</div>';
                    });
                }
                break;
                
            case 'dismiss_notification':
                if (wp_verify_nonce($nonce, 'wpait_dismiss_notification')) {
                    $notification_hash = sanitize_text_field($_GET['notification_hash']);
                    $dismissed_notifications = get_option('wpait_dismissed_notifications', array());
                    
                    // Add this notification hash to dismissed list
                    if (!in_array($notification_hash, $dismissed_notifications)) {
                        $dismissed_notifications[] = $notification_hash;
                        update_option('wpait_dismissed_notifications', $dismissed_notifications);
                    }
                    
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-success is-dismissible">';
                        echo '<p>✅ ' . __('Import notification dismissed. You can still check import status in WP All Import.', 'wp-all-import-tracker') . '</p>';
                        echo '</div>';
                    });
                    
                    error_log('WPAIT: Import notification dismissed by user');
                }
                break;
        }
        
        // Redirect to clean URL
        if (isset($_GET['wpait_action'])) {
            wp_redirect(remove_query_arg(array('wpait_action', 'nonce')));
            exit;
        }
    }
    
    /**
     * Clean up old dismissed notifications to prevent the list from growing too large
     */
    public function cleanup_old_dismissed_notifications() {
        $dismissed_notifications = get_option('wpait_dismissed_notifications', array());
        
        // If we have more than 50 dismissed notifications, clean up old ones
        if (count($dismissed_notifications) > 50) {
            // Keep only the last 25 dismissed notifications
            $dismissed_notifications = array_slice($dismissed_notifications, -25);
            update_option('wpait_dismissed_notifications', $dismissed_notifications);
            error_log('WPAIT: Cleaned up old dismissed notifications');
        }
    }
} 