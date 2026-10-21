<?php
/**
 * Admin list-table helpers.
 *
 * @package Oli_Import_Tracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin functionality.
 */
class OLIIT_Admin {

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
        add_filter('views_edit-product', array($this, 'add_import_status_links'));
        add_filter('plugin_action_links_' . OLIIT_PLUGIN_BASENAME, array($this, 'add_settings_link'));
        add_action('pmxi_import_complete', array($this, 'clear_counts_cache'));
        add_action('pmxi_import_failed', array($this, 'clear_counts_cache'));
    }

    /**
     * Add import status links to the product list views.
     *
     * @param array $views Existing views.
     * @return array
     */
    public function add_import_status_links($views) {
        global $wpdb;
        $configured_import_ids = oliit()->get_import_ids();

        $tables = array(
            $wpdb->prefix . 'pmxi_imports',
            $wpdb->prefix . 'pmxi_posts',
            $wpdb->prefix . 'pmxi_history',
        );

        foreach ($tables as $table) {
            if (!oliit_table_exists($table)) {
                return $views;
            }
        }

        $total_counts = array('updated' => 0, 'skipped' => 0);
        foreach ($configured_import_ids as $import_id) {
            $counts = $this->get_import_status_counts($import_id);
            $total_counts['updated'] += $counts['updated'];
            $total_counts['skipped'] += $counts['skipped'];
        }

        if (0 === $total_counts['updated'] && 0 === $total_counts['skipped']) {
            return $views;
        }

        $base_url       = admin_url('edit.php?post_type=product');
        $current_filter = isset($_GET['import_filter']) ? sanitize_text_field(wp_unslash($_GET['import_filter'])) : '';

        $updated_url   = add_query_arg('import_filter', 'updated', $base_url);
        $updated_class = ('updated' === $current_filter) ? 'current' : '';
        $updated_aria  = ('updated' === $current_filter) ? ' aria-current="page"' : '';

        $views['import_updated'] = sprintf(
            '<a href="%s" class="%s"%s>%s <span class="count">(%s)</span></a>',
            esc_url($updated_url),
            esc_attr($updated_class),
            $updated_aria,
            esc_html__('Updated', 'oli-import-tracker'),
            esc_html(number_format_i18n($total_counts['updated']))
        );

        $skipped_url   = add_query_arg('import_filter', 'skipped', $base_url);
        $skipped_class = ('skipped' === $current_filter) ? 'current' : '';
        $skipped_aria  = ('skipped' === $current_filter) ? ' aria-current="page"' : '';

        $views['import_skipped'] = sprintf(
            '<a href="%s" class="%s"%s>%s <span class="count">(%s)</span></a>',
            esc_url($skipped_url),
            esc_attr($skipped_class),
            $skipped_aria,
            esc_html__('Skipped', 'oli-import-tracker'),
            esc_html(number_format_i18n($total_counts['skipped']))
        );

        return $views;
    }

    /**
     * Get import status counts.
     *
     * @param int $import_id Import ID.
     * @return array{updated:int,skipped:int}
     */
    public function get_import_status_counts($import_id) {
        global $wpdb;

        $import_id = absint($import_id);
        $cache_key = 'oliit_counts_' . $import_id;
        $cached_counts = get_transient($cache_key);

        $current_import_run = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(h.date) FROM {$wpdb->prefix}pmxi_history h WHERE h.import_id = %d",
            $import_id
        ));

        if (!$current_import_run) {
            return array('updated' => 0, 'skipped' => 0);
        }

        if ($cached_counts && isset($cached_counts['import_date']) && $cached_counts['import_date'] === $current_import_run) {
            oliit_log("Using cached counts for import {$import_id} - Updated: {$cached_counts['updated']}, Skipped: {$cached_counts['skipped']}");
            return array(
                'updated' => $cached_counts['updated'],
                'skipped' => $cached_counts['skipped'],
            );
        }

        oliit_log("Calculating fresh counts for import {$import_id} - last run: {$current_import_run}");

        $updated_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->prefix}pmxi_posts pmxi ON p.ID = pmxi.post_id
             WHERE p.post_type = 'product'
             AND p.post_status = 'publish'
             AND pmxi.import_id = %d",
            $import_id
        ));

        $skipped_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->prefix}pmxi_posts pmxi ON p.ID = pmxi.post_id AND pmxi.import_id = %d
             WHERE p.post_type = 'product'
             AND p.post_status = 'publish'
             AND pmxi.post_id IS NULL",
            $import_id
        ));

        $counts = array(
            'updated'     => intval($updated_count),
            'skipped'     => intval($skipped_count),
            'import_date' => $current_import_run,
        );

        set_transient($cache_key, $counts, HOUR_IN_SECONDS);
        oliit_log("Cached new counts - Updated: {$counts['updated']}, Skipped: {$counts['skipped']}");

        return array(
            'updated' => $counts['updated'],
            'skipped' => $counts['skipped'],
        );
    }

    /**
     * Clear counts cache when an import completes.
     *
     * @param int $import_id Import ID.
     * @return void
     */
    public function clear_counts_cache($import_id) {
        delete_transient('oliit_counts_' . absint($import_id));
        oliit_log('Cleared counts cache for import ' . absint($import_id));
    }

    /**
     * Add settings link to the plugins list.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=oliit-settings')),
            esc_html__('Settings', 'oli-import-tracker')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
}
