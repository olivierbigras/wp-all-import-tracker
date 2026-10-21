<?php
/**
 * Uninstall cleanup for Oli Import Tracker for WP All Import.
 *
 * @package Oli_Import_Tracker
 * @since 1.0.0
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete plugin options, transients, and cron events.
 *
 * @return void
 */
function oliit_uninstall() {
    $oliit_option_keys = array(
        'oliit_import_id',
        'oliit_import_ids',
        'oliit_enable_auto_refresh',
        'oliit_enable_email_alerts',
        'oliit_alert_email',
        'oliit_stale_threshold',
        'oliit_max_execution_time',
        'oliit_show_setup_notice',
        'oliit_first_issues_handled',
        'oliit_auto_enabled_reason',
        'oliit_auto_enabled_time',
        'oliit_current_issues',
        'oliit_issues_detected_time',
        'oliit_last_longterm_alert',
        'oliit_dismissed_notifications',
        'oliit_options_migrated',
        'wpait_import_id',
        'wpait_import_ids',
        'wpait_enable_auto_refresh',
        'wpait_enable_email_alerts',
        'wpait_alert_email',
        'wpait_stale_threshold',
        'wpait_max_execution_time',
        'wpait_show_setup_notice',
        'wpait_first_issues_handled',
        'wpait_auto_enabled_reason',
        'wpait_auto_enabled_time',
        'wpait_current_issues',
        'wpait_issues_detected_time',
        'wpait_last_longterm_alert',
        'wpait_dismissed_notifications',
    );

    foreach ($oliit_option_keys as $oliit_option_key) {
        delete_option($oliit_option_key);
    }

    global $wpdb;
    $oliit_like_patterns = array(
        'oliit_import_start_time_',
        'wpait_import_start_time_',
        '_transient_oliit_',
        '_transient_timeout_oliit_',
        '_transient_wpait_',
        '_transient_timeout_wpait_',
    );
    foreach ($oliit_like_patterns as $oliit_pattern) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($oliit_pattern) . '%'
            )
        );
    }

    wp_clear_scheduled_hook('oliit_check_import_status');
    wp_clear_scheduled_hook('wpait_check_import_status');
}

oliit_uninstall();
