<?php
/**
 * WP All Import Tracker - Admin Class
 * 
 * @package WP_All_Import_Tracker
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin functionality
 */
class WPAIT_Admin {
    
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
        // Add import links to subsubsub navigation
        add_filter('views_edit-product', array($this, 'add_import_status_links'));
        
        // Add settings link to plugins page
        add_filter('plugin_action_links_' . WPAIT_PLUGIN_BASENAME, array($this, 'add_settings_link'));
        
        // Clear counts cache when imports complete
        add_action('pmxi_import_complete', array($this, 'clear_counts_cache'));
        add_action('pmxi_import_failed', array($this, 'clear_counts_cache'));
    }
    
    /**
     * Add import status links to subsubsub navigation
     */
    public function add_import_status_links($views) {
        global $wpdb;
        $configured_import_ids = WPAIT()->get_import_ids();
        
        // Check if WP All Import tables exist
        $tables = array(
            $wpdb->prefix . 'pmxi_imports',
            $wpdb->prefix . 'pmxi_posts',
            $wpdb->prefix . 'pmxi_history'
        );
        
        foreach ($tables as $table) {
            if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) {
                return $views;
            }
        }
        
        // Get combined counts from all configured imports
        $total_counts = array('updated' => 0, 'skipped' => 0);
        foreach ($configured_import_ids as $import_id) {
            $counts = $this->get_import_status_counts($import_id);
            $total_counts['updated'] += $counts['updated'];
            $total_counts['skipped'] += $counts['skipped'];
        }
        
        if ($total_counts['updated'] === 0 && $total_counts['skipped'] === 0) {
            return $views;
        }
        
        // Build URLs
        $base_url = admin_url('edit.php?post_type=product');
        $current_filter = isset($_GET['import_filter']) ? $_GET['import_filter'] : '';
        
        // Add Updated link
        $updated_url = add_query_arg('import_filter', 'updated', $base_url);
        $updated_class = ($current_filter === 'updated') ? 'current' : '';
        $updated_aria = ($current_filter === 'updated') ? ' aria-current="page"' : '';
        
        $views['import_updated'] = sprintf(
            '<a href="%s" class="%s"%s>%s <span class="count">(%s)</span></a>',
            esc_url($updated_url),
            $updated_class,
            $updated_aria,
            __('Updated', 'wp-all-import-tracker'),
            number_format_i18n($total_counts['updated'])
        );
        
        // Add Skipped link
        $skipped_url = add_query_arg('import_filter', 'skipped', $base_url);
        $skipped_class = ($current_filter === 'skipped') ? 'current' : '';
        $skipped_aria = ($current_filter === 'skipped') ? ' aria-current="page"' : '';
        
        $views['import_skipped'] = sprintf(
            '<a href="%s" class="%s"%s>%s <span class="count">(%s)</span></a>',
            esc_url($skipped_url),
            $skipped_class,
            $skipped_aria,
            __('Skipped', 'wp-all-import-tracker'),
            number_format_i18n($total_counts['skipped'])
        );
        
        return $views;
    }
    
    /**
     * Get import status counts
     */
    private function get_import_status_counts($import_id) {
        global $wpdb;
        
        // Check for cached counts first
        $cache_key = "wpait_counts_{$import_id}";
        $cached_counts = get_transient($cache_key);
        
        // Get current import run date to check if cache is valid
        $current_import_run = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(h.date) FROM {$wpdb->prefix}pmxi_history h WHERE h.import_id = %d",
            $import_id
        ));
        
        if (!$current_import_run) {
            return array('updated' => 0, 'skipped' => 0);
        }
        
        // Check if cached data is valid (same import run date)
        if ($cached_counts && isset($cached_counts['import_date']) && $cached_counts['import_date'] === $current_import_run) {
            error_log("WPAIT: Using cached counts for import {$import_id} - Updated: {$cached_counts['updated']}, Skipped: {$cached_counts['skipped']}, Cache date: {$cached_counts['import_date']}");
            return array(
                'updated' => $cached_counts['updated'],
                'skipped' => $cached_counts['skipped']
            );
        }
        
        // Debug cache invalidation
        if ($cached_counts) {
            error_log("WPAIT: Cache invalidated for import {$import_id} - Cache date: " . (isset($cached_counts['import_date']) ? $cached_counts['import_date'] : 'missing') . " vs Current: {$current_import_run}");
        } else {
            error_log("WPAIT: No cached data found for import {$import_id}");
        }
        
        error_log("WPAIT: Calculating fresh counts for import {$import_id} - last run: {$current_import_run}");
        
        // Debug: Check what's in pmxi_history for this import
        $history_debug = $wpdb->get_results($wpdb->prepare(
            "SELECT id, date, summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 3",
            $import_id
        ));
        error_log("WPAIT: Recent history for import {$import_id}: " . print_r($history_debug, true));
        
        // Debug: Check total products in pmxi_posts for this import
        $total_in_pmxi = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d",
            $import_id
        ));
        error_log("WPAIT: Total products in pmxi_posts for import {$import_id}: {$total_in_pmxi}");
        
        // Debug: Check total published products on site
        $total_published = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
        );
        error_log("WPAIT: Total published products on site: {$total_published}");
        
        // Get updated products count (only published products, same as filtering logic)
        $updated_query = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->prefix}pmxi_posts pmxi ON p.ID = pmxi.post_id
             WHERE p.post_type = 'product' 
             AND p.post_status = 'publish'
             AND pmxi.import_id = %d",
            $import_id
        );
        error_log("WPAIT: Updated query: {$updated_query}");
        $updated_count = $wpdb->get_var($updated_query);
        error_log("WPAIT: Updated count result: {$updated_count}");
        
        // Get skipped products count (only published products, same as filtering logic)
        $skipped_query = $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) 
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->prefix}pmxi_posts pmxi ON p.ID = pmxi.post_id AND pmxi.import_id = %d
             WHERE p.post_type = 'product' 
             AND p.post_status = 'publish'
             AND pmxi.post_id IS NULL",
            $import_id
        );
        error_log("WPAIT: Skipped query: {$skipped_query}");
        $skipped_count = $wpdb->get_var($skipped_query);
        error_log("WPAIT: Skipped count result: {$skipped_count}");
        
        // Debug: Verify the math
        $total_calculated = intval($updated_count) + intval($skipped_count);
        error_log("WPAIT: Math check - Updated: {$updated_count} + Skipped: {$skipped_count} = {$total_calculated} (should equal {$total_published})");
        
        // Debug: Check if there are products in pmxi_posts that are not published
        $unpublished_in_pmxi = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->prefix}pmxi_posts pmxi ON p.ID = pmxi.post_id
             WHERE p.post_type = 'product' 
             AND p.post_status != 'publish'
             AND pmxi.import_id = %d",
            $import_id
        ));
        error_log("WPAIT: Unpublished products in pmxi_posts: {$unpublished_in_pmxi}");
        
        // Debug: Check some sample products in pmxi_posts to see their status
        $sample_products = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status, p.post_modified 
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->prefix}pmxi_posts pmxi ON p.ID = pmxi.post_id
             WHERE p.post_type = 'product' 
             AND pmxi.import_id = %d
             ORDER BY p.post_modified DESC
             LIMIT 5",
            $import_id
        ));
        error_log("WPAIT: Sample products in pmxi_posts for import {$import_id}: " . print_r($sample_products, true));
        
        // Debug: Check the latest import summary again to understand what happened
        $latest_summary = $wpdb->get_var($wpdb->prepare(
            "SELECT summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 1",
            $import_id
        ));
        error_log("WPAIT: Latest import summary: {$latest_summary}");
        
        // CRITICAL DEBUG: If the import had 0 updated, pmxi_posts should be empty or very small
        if (strpos($latest_summary, '0 updated') !== false && $total_in_pmxi > 0) {
            error_log("WPAIT: INCONSISTENCY DETECTED! Import shows 0 updated but pmxi_posts has {$total_in_pmxi} entries. This suggests WP All Import didn't clear the table properly.");
        }
        
        $counts = array(
            'updated' => intval($updated_count),
            'skipped' => intval($skipped_count),
            'import_date' => $current_import_run
        );
        
        // Cache the results for 1 hour
        set_transient($cache_key, $counts, HOUR_IN_SECONDS);
        
        error_log("WPAIT: Cached new counts - Updated: {$counts['updated']}, Skipped: {$counts['skipped']}");
        
        return array(
            'updated' => $counts['updated'],
            'skipped' => $counts['skipped']
        );
    }
    

    
    /**
     * Clear counts cache when import completes
     */
    public function clear_counts_cache($import_id) {
        $cache_key = "wpait_counts_{$import_id}";
        delete_transient($cache_key);
        error_log("WPAIT Admin: Cleared counts cache for import {$import_id}");
    }
    
    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=wpait-settings'),
            __('Settings', 'wp-all-import-tracker')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
} 