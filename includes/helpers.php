<?php
/**
 * Shared helpers for Oli Import Tracker for WP All Import.
 *
 * @package Oli_Import_Tracker
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check whether a database table exists.
 *
 * @param string $table_name Full table name, including prefix.
 * @return bool
 */
function oliit_table_exists($table_name) {
    global $wpdb;

    $table_name = (string) $table_name;
    $found      = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name)));

    return $found === $table_name;
}

/**
 * Sanitize a list of positive integer IDs from request data.
 *
 * @param mixed $ids Raw ID list.
 * @return int[]
 */
function oliit_sanitize_id_list($ids) {
    if (!is_array($ids)) {
        $ids = array($ids);
    }

    $ids = array_map('absint', $ids);
    $ids = array_filter($ids);

    return array_values($ids);
}

/**
 * Build a safe IN() placeholder string for $wpdb->prepare().
 *
 * @param int[] $ids Positive integer IDs.
 * @return string Comma-separated %d tokens, or empty string.
 */
function oliit_ids_placeholders($ids) {
    if (empty($ids)) {
        return '';
    }

    return implode(',', array_fill(0, count($ids), '%d'));
}

/**
 * Write a debug log line when WP_DEBUG is enabled.
 *
 * @param string $message Message without the plugin prefix.
 * @return void
 */
function oliit_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('OLIIT: ' . $message);
    }
}

/**
 * Map of legacy wpait_* option keys to oliit_* keys.
 *
 * @return array<string,string>
 */
function oliit_legacy_option_map() {
    return array(
        'wpait_import_id'               => 'oliit_import_id',
        'wpait_import_ids'              => 'oliit_import_ids',
        'wpait_enable_auto_refresh'     => 'oliit_enable_auto_refresh',
        'wpait_enable_email_alerts'     => 'oliit_enable_email_alerts',
        'wpait_alert_email'             => 'oliit_alert_email',
        'wpait_stale_threshold'         => 'oliit_stale_threshold',
        'wpait_max_execution_time'      => 'oliit_max_execution_time',
        'wpait_show_setup_notice'       => 'oliit_show_setup_notice',
        'wpait_first_issues_handled'    => 'oliit_first_issues_handled',
        'wpait_auto_enabled_reason'     => 'oliit_auto_enabled_reason',
        'wpait_auto_enabled_time'       => 'oliit_auto_enabled_time',
        'wpait_current_issues'          => 'oliit_current_issues',
        'wpait_issues_detected_time'    => 'oliit_issues_detected_time',
        'wpait_last_longterm_alert'     => 'oliit_last_longterm_alert',
        'wpait_dismissed_notifications' => 'oliit_dismissed_notifications',
    );
}

/**
 * Copy legacy wpait_* options and transients to oliit_*, then delete the old keys.
 *
 * @return void
 */
function oliit_migrate_legacy_options() {
    if (get_option('oliit_options_migrated')) {
        return;
    }

    foreach (oliit_legacy_option_map() as $old_key => $new_key) {
        $old_value = get_option($old_key, null);
        if (null === $old_value) {
            continue;
        }

        if (null === get_option($new_key, null)) {
            add_option($new_key, $old_value);
        }

        delete_option($old_key);
    }

    global $wpdb;

    $like = $wpdb->esc_like('wpait_import_start_time_') . '%';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        )
    );

    if ($rows) {
        foreach ($rows as $row) {
            $new_name = 'oliit_' . substr($row->option_name, strlen('wpait_'));
            if (null === get_option($new_name, null)) {
                add_option($new_name, maybe_unserialize($row->option_value));
            }
            delete_option($row->option_name);
        }
    }

    $old_single = get_option('oliit_import_id', false);
    if (false !== $old_single && false === get_option('oliit_import_ids', false)) {
        update_option('oliit_import_ids', array(absint($old_single)));
    }
    delete_option('oliit_import_id');

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_wpait_') . '%',
            $wpdb->esc_like('_transient_timeout_wpait_') . '%'
        )
    );

    if (wp_next_scheduled('wpait_check_import_status')) {
        wp_clear_scheduled_hook('wpait_check_import_status');
        if (!wp_next_scheduled('oliit_check_import_status')) {
            wp_schedule_event(time(), 'hourly', 'oliit_check_import_status');
        }
    }

    update_option('oliit_options_migrated', '1');
    oliit_log('Migrated legacy wpait_* options to oliit_*.');
}

/**
 * Whether WP All Import tables are present.
 *
 * @return bool
 */
function oliit_wpai_is_available() {
    global $wpdb;

    return oliit_table_exists($wpdb->prefix . 'pmxi_imports');
}

/**
 * Get WP All Import jobs with last-run info.
 *
 * @return array<int,array{id:int,name:string,last_run:string,last_run_label:string,scheduled:bool}>
 */
function oliit_get_available_imports() {
    global $wpdb;

    if (!oliit_wpai_is_available()) {
        return array();
    }

    $table = $wpdb->prefix . 'pmxi_imports';
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix + a fixed identifier.
    $columns = $wpdb->get_col("DESCRIBE {$table}");
    $select  = array('id', 'name', 'path');

    if (is_array($columns)) {
        foreach (array('last_activity', 'options', 'triggered') as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = $column;
            }
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . " FROM {$table} ORDER BY id DESC";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column list is hard-coded identifiers.
    $rows = $wpdb->get_results($sql);
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $imports = array();

    if (!$rows) {
        return $imports;
    }

    foreach ($rows as $row) {
        $name = '';
        if (!empty($row->name)) {
            $name = $row->name;
        } elseif (!empty($row->path)) {
            $name = basename($row->path);
        } else {
            $name = sprintf(
                /* translators: %d: import ID */
                __('Import #%d', 'oli-import-tracker'),
                (int) $row->id
            );
        }

        $last_run = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(date) FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d",
                $row->id
            )
        );

        $imports[] = array(
            'id'             => (int) $row->id,
            'name'           => $name,
            'last_run'       => $last_run ? (string) $last_run : '',
            'last_run_label' => oliit_human_last_run($last_run),
            'scheduled'      => oliit_import_is_scheduled($row),
        );
    }

    return $imports;
}

/**
 * Human-readable last-run label.
 *
 * @param string|null $last_run MySQL datetime.
 * @return string
 */
function oliit_human_last_run($last_run) {
    if (empty($last_run)) {
        return __('never run', 'oli-import-tracker');
    }

    $timestamp = strtotime($last_run);
    if (!$timestamp) {
        return __('never run', 'oli-import-tracker');
    }

    return sprintf(
        /* translators: %s: human time difference */
        __('%s ago', 'oli-import-tracker'),
        human_time_diff($timestamp, (int) current_time('timestamp'))
    );
}

/**
 * Whether an import looks scheduled via cron.
 *
 * @param object $row Import row.
 * @return bool
 */
function oliit_import_is_scheduled($row) {
    if (!empty($row->triggered)) {
        return true;
    }

    if (!empty($row->options)) {
        $options = maybe_unserialize($row->options);
        if (is_array($options)) {
            foreach (array('scheduling_enable', 'enable_scheduling', 'cron', 'is_scheduled') as $key) {
                if (!empty($options[$key])) {
                    return true;
                }
            }
        } elseif (is_string($row->options) && false !== stripos($row->options, 'schedul')) {
            return true;
        }
    }

    return (bool) get_option('wp_all_import_cron_job_key_' . (int) $row->id);
}

/**
 * IDs of scheduled WP All Import jobs.
 *
 * @return int[]
 */
function oliit_get_scheduled_import_ids() {
    $ids = array();
    foreach (oliit_get_available_imports() as $import) {
        if (!empty($import['scheduled'])) {
            $ids[] = (int) $import['id'];
        }
    }

    return $ids;
}

/**
 * Correct a leftover default of import ID 1 when that import does not exist.
 *
 * @return void
 */
function oliit_maybe_reset_default_import_one() {
    if (get_option('oliit_ux_defaults_migrated')) {
        return;
    }

    $ids = get_option('oliit_import_ids', null);
    if (null !== $ids && false !== $ids) {
        $ids = array_map('intval', (array) $ids);
        if (array(1) === $ids && oliit_wpai_is_available()) {
            global $wpdb;
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}pmxi_imports WHERE id = %d",
                    1
                )
            );
            if (!$exists) {
                update_option('oliit_import_ids', oliit_get_scheduled_import_ids());
            }
        }
    }

    update_option('oliit_ux_defaults_migrated', '1');
}
