<?php
/**
 * WP All Import Tracker Uninstall
 * 
 * @package WP_All_Import_Tracker
 * @since 1.0.0
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('wpait_import_id');
delete_option('wpait_enable_auto_refresh');
delete_option('wpait_enable_email_alerts');
delete_option('wpait_alert_email');
delete_option('wpait_stale_threshold');
delete_option('wpait_max_execution_time');
delete_option('wpait_show_setup_notice');
delete_option('wpait_first_issues_handled');
delete_option('wpait_auto_enabled_reason');
delete_option('wpait_auto_enabled_time');
delete_option('wpait_current_issues');
delete_option('wpait_issues_detected_time');
delete_option('wpait_last_longterm_alert');
delete_option('wpait_dismissed_notifications');

// Clean up import tracking options (dynamic options)
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpait_import_start_time_%'");

// Clear scheduled hooks
wp_clear_scheduled_hook('wpait_check_import_status');

// Optional: Clear user meta if we stored any user-specific settings
// $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wpait_%'");

// Optional: Clear transients if we used any
// $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpait_%'");
// $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpait_%'"); 