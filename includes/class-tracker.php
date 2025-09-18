<?php
/**
 * WP All Import Tracker - Core Tracker Class
 * 
 * @package WP_All_Import_Tracker
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core tracker functionality
 */
class WPAIT_Tracker {
    
    /**
     * Cache for import data
     */
    private static $import_cache = array();
    private static $last_import_run_cache = null;
    private static $skipped_products_cache = null;
    private static $cached_import_id = null;
    
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
        // WooCommerce product list customization
        add_filter('manage_product_posts_columns', array($this, 'add_import_column'));
        add_action('manage_product_posts_custom_column', array($this, 'add_import_column_content'), 10, 2);
        
        // Import filtering
        add_action('pre_get_posts', array($this, 'handle_import_filtering'));
        
        // WP All Import hooks for cache management
        add_action('pmxi_import_complete', array($this, 'clear_cache_on_import_complete'));
        add_action('pmxi_import_failed', array($this, 'clear_cache_on_import_complete'));
        add_action('pmxi_import_started', array($this, 'clear_cache_on_import_start'));
        
        // Admin styles
        add_action('admin_head', array($this, 'add_admin_styles'));
    }
    
    /**
     * Add import column to product list
     */
    public function add_import_column($columns) {
        // Add import column before date
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ($key === 'date') {
                $new_columns['import'] = esc_html__('Import', 'wp-all-import-tracker');
            }
            $new_columns[$key] = $value;
        }
        return $new_columns;
    }
    
    /**
     * Add content to import column
     */
    public function add_import_column_content($column, $post_id) {
        if ($column === 'import') {
            $product = wc_get_product($post_id);
            if ($product) {
                $configured_import_ids = WPAIT()->get_import_ids();
                $last_import_info = $this->get_last_import_info($post_id, $configured_import_ids);
                
                echo '<div class="import-column-content">';
                if ($last_import_info) {
                    echo '<div class="last-import-date">';
                    echo '<span>' . esc_html($last_import_info['date']) . '</span>';
                    if (count($configured_import_ids) > 1) {
                        echo '<br><small style="color: #666;">Import #' . $last_import_info['import_id'] . '</small>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="last-import-date">';
                    echo '<span>' . __('Never imported', 'wp-all-import-tracker') . '</span>';
                    echo '</div>';
                }
                echo '</div>';
            }
        }
    }
    
    /**
     * Get last import info for a product across all configured imports
     */
    private function get_last_import_info($post_id, $configured_import_ids) {
        global $wpdb;
        
        if (empty($configured_import_ids)) {
            return false;
        }
        
        // Check if WP All Import tables exist
        $tables = array(
            $wpdb->prefix . 'pmxi_imports',
            $wpdb->prefix . 'pmxi_posts',
            $wpdb->prefix . 'pmxi_history'
        );
        
        foreach ($tables as $table) {
            if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) {
                return false;
            }
        }
        
        $latest_import_info = null;
        $latest_date = null;
        
        foreach ($configured_import_ids as $import_id) {
            $import_info = $this->get_import_info_for_product($post_id, $import_id);
            if ($import_info && (!$latest_date || $import_info['date'] > $latest_date)) {
                $latest_import_info = $import_info;
                $latest_date = $import_info['date'];
            }
        }
        
        return $latest_import_info;
    }
    
    /**
     * Get import info for a product from a specific import
     */
    private function get_import_info_for_product($post_id, $import_id) {
        global $wpdb;
        
        // Get current last import run date for this import
        $current_last_import_run = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(h.date) FROM {$wpdb->prefix}pmxi_history h WHERE h.import_id = %d",
            $import_id
        ));
        
        if (!$current_last_import_run) {
            return false;
        }
        
        // Check product import status
        $product_imported = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d AND post_id = %d",
            $import_id,
            $post_id
        ));
        
        if ($product_imported > 0) {
            // Get skipped products for this import
            $skipped_products = $this->get_skipped_products_from_logs($import_id);
            $product_title = get_the_title($post_id);
            $was_updated = !in_array($product_title, $skipped_products);
            
            // For UPDATED products: use WordPress modification date
            $post_modified = get_post_modified_time('M j, Y g:i A', false, $post_id);
            $status_text = $was_updated ? __('Updated', 'wp-all-import-tracker') : __('Skipped', 'wp-all-import-tracker');
            
            return array(
                'date' => $post_modified . ' (' . $status_text . ')',
                'import_id' => $import_id,
                'status' => $was_updated ? 'updated' : 'skipped'
            );
        }
        
        return false;
    }
    
    /**
     * Get last import date for a product (legacy method for backward compatibility)
     */
    private function get_last_import_date($post_id, $import_id = 1) {
        global $wpdb;
        

        
        // Check if WP All Import tables exist
        $tables = array(
            $wpdb->prefix . 'pmxi_imports',
            $wpdb->prefix . 'pmxi_posts',
            $wpdb->prefix . 'pmxi_history'
        );
        
        foreach ($tables as $table) {
            if (!$wpdb->get_var("SHOW TABLES LIKE '$table'")) {
                return false;
            }
        }
        
        // Get current last import run date
        $current_last_import_run = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(h.date) FROM {$wpdb->prefix}pmxi_history h WHERE h.import_id = %d",
            $import_id
        ));
        
        // Check if we need to refresh cache
        if (self::$last_import_run_cache === null || 
            $current_last_import_run !== self::$last_import_run_cache || 
            self::$cached_import_id !== $import_id) {
            
            self::$last_import_run_cache = $current_last_import_run;
            self::$skipped_products_cache = null;
            self::$cached_import_id = $import_id;
        }
        
        if (!self::$last_import_run_cache) {
            return false;
        }
        
        // Cache skipped products list
        if (self::$skipped_products_cache === null) {
            self::$skipped_products_cache = $this->get_skipped_products_from_logs($import_id);
        }
        
        // Check product import status
        if (!isset(self::$import_cache[$post_id])) {
            $product_imported = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d AND post_id = %d",
                $import_id,
                $post_id
            ));
            
            if ($product_imported > 0) {
                $product_title = get_the_title($post_id);
                self::$import_cache[$post_id] = !in_array($product_title, self::$skipped_products_cache);
            } else {
                self::$import_cache[$post_id] = false;
            }
        }
        
        if (self::$import_cache[$post_id]) {
            // For UPDATED products: use WordPress modification date
            $post_modified = get_post_modified_time('M j, Y g:i A', false, $post_id);
            return $post_modified . ' (' . __('Updated', 'wp-all-import-tracker') . ')';
        } else {
            // For SKIPPED products: also use WordPress modification date (last time product was actually modified)
            $post_modified = get_post_modified_time('M j, Y g:i A', false, $post_id);
            return $post_modified . ' (' . __('Skipped', 'wp-all-import-tracker') . ')';
        }
    }
    
    /**
     * Get skipped products from import logs
     */
    private function get_skipped_products_from_logs($import_id) {
        global $wpdb;
        $skipped_products = array();
        
        // Validate input
        if (!is_numeric($import_id) || $import_id <= 0) {
            return $skipped_products;
        }
        
        // Validate table exists
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pmxi_history'")) {
            return $skipped_products;
        }
        
        // Get latest run info
        $latest_run = $wpdb->get_row($wpdb->prepare(
            "SELECT h.id as run_id, h.summary, h.date 
             FROM {$wpdb->prefix}pmxi_history h 
             WHERE h.import_id = %d 
             ORDER BY h.date DESC 
             LIMIT 1",
            $import_id
        ));
        
        if (!$latest_run) {
            return $skipped_products;
        }
        
        // Parse summary for updated count
        $updated_count = 0;
        if (preg_match('/(\d+)\s+updated/', $latest_run->summary, $matches)) {
            $updated_count = intval($matches[1]);
        }
        
        if ($updated_count > 0) {
            // Get products NOT updated in this import (real skipped products)
            $all_site_products = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title 
                 FROM {$wpdb->posts} p 
                 LEFT JOIN {$wpdb->prefix}pmxi_posts pmxi ON p.ID = pmxi.post_id AND pmxi.import_id = %d
                 WHERE p.post_type = 'product' 
                 AND p.post_status = 'publish'
                 AND pmxi.post_id IS NULL
                 ORDER BY p.post_modified DESC",
                $import_id
            ));
            
            foreach ($all_site_products as $product) {
                $skipped_products[] = $product->post_title;
            }
        }
        
        return array_unique($skipped_products);
    }
    
    /**
     * Handle import filtering
     */
    public function handle_import_filtering($query) {
        if (!is_admin() || !$query->is_main_query() || !isset($_GET['post_type']) || $_GET['post_type'] !== 'product') {
            return;
        }
        
        if (isset($_GET['import_filter'])) {
            add_filter('posts_join', array($this, 'custom_import_join'), 10, 2);
            add_filter('posts_where', array($this, 'custom_import_where'), 10, 2);
        }
    }
    
    /**
     * Custom JOIN for import filtering
     */
    public function custom_import_join($join, $query) {
        global $wpdb;
        
        if (is_admin() && $query->is_main_query() && isset($_GET['import_filter'])) {
            $import_filter = sanitize_text_field($_GET['import_filter']);
            // Use primary import ID for filtering (first in the list)
            $import_id = WPAIT()->get_import_id();
            
            if ($import_filter === 'skipped' || $import_filter === 'updated') {
                if ($import_filter === 'updated') {
                    $join .= " INNER JOIN {$wpdb->prefix}pmxi_posts pmxi ON {$wpdb->posts}.ID = pmxi.post_id AND pmxi.import_id = " . intval($import_id);
                } elseif ($import_filter === 'skipped') {
                    $join .= " LEFT JOIN {$wpdb->prefix}pmxi_posts pmxi ON {$wpdb->posts}.ID = pmxi.post_id AND pmxi.import_id = " . intval($import_id);
                }
            }
        }
        
        return $join;
    }
    
    /**
     * Custom WHERE for import filtering
     */
    public function custom_import_where($where, $query) {
        global $wpdb;
        
        if (is_admin() && $query->is_main_query() && isset($_GET['import_filter'])) {
            $import_filter = sanitize_text_field($_GET['import_filter']);
            
            if ($import_filter === 'skipped') {
                // Only show published products that are skipped (same logic as counting)
                $where .= " AND pmxi.post_id IS NULL AND {$wpdb->posts}.post_status = 'publish'";
            } elseif ($import_filter === 'updated') {
                // Only show published products that are updated
                $where .= " AND {$wpdb->posts}.post_status = 'publish'";
            }
        }
        
        return $where;
    }
    
    /**
     * Clear cache when import completes
     */
    public function clear_cache_on_import_complete($import_id) {
        $this->clear_cache();
        // Force immediate cache refresh for the specific import
        delete_transient("wpait_counts_{$import_id}");
        error_log("WPAIT: Cache cleared on import complete for import {$import_id}");
        
        // Automatically run inconsistency detection and cleanup
        $this->auto_detect_and_fix_inconsistencies($import_id);
    }
    
    /**
     * Clear cache when import starts
     */
    public function clear_cache_on_import_start($import_id) {
        $this->clear_cache();
        // Force immediate cache refresh for the specific import
        delete_transient("wpait_counts_{$import_id}");
        error_log("WPAIT: Cache cleared on import start for import {$import_id}");
    }
    
    /**
     * Clear all caches
     */
    private function clear_cache() {
        self::$import_cache = array();
        self::$last_import_run_cache = null;
        self::$skipped_products_cache = null;
        self::$cached_import_id = null;
    }
    
    /**
     * Automatically detect and fix inconsistencies after import completion
     */
    private function auto_detect_and_fix_inconsistencies($import_id) {
        global $wpdb;
        
        // Only run for the tracked import ID
        $tracked_import_id = WPAIT()->get_import_id();
        if ($import_id != $tracked_import_id) {
            return;
        }
        
        error_log("WPAIT: Running automatic inconsistency detection for import {$import_id}");
        
        // Get latest import summary
        $latest_summary = $wpdb->get_var($wpdb->prepare(
            "SELECT summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 1",
            $import_id
        ));
        
        $total_in_pmxi = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d",
            $import_id
        ));
        
        error_log("WPAIT: Auto-check - Latest import: {$latest_summary}");
        error_log("WPAIT: Auto-check - Products in pmxi_posts: {$total_in_pmxi}");
        
        // Check for inconsistency (same logic as manual check)
        if (strpos($latest_summary, '0 updated') !== false && $total_in_pmxi > 0) {
            error_log("WPAIT: AUTO-INCONSISTENCY DETECTED! Import shows 0 updated but pmxi_posts has {$total_in_pmxi} entries. Auto-fixing...");
            
            // Auto-cleanup: Clear pmxi_posts table for this import
            $deleted = $wpdb->delete(
                $wpdb->prefix . 'pmxi_posts',
                array('import_id' => $import_id),
                array('%d')
            );
            
            error_log("WPAIT: Auto-cleanup - Deleted {$deleted} stale entries from pmxi_posts table");
            
            // Clear cache again after cleanup
            delete_transient("wpait_counts_{$import_id}");
            $this->clear_cache();
            
            error_log("WPAIT: Auto-cleanup completed successfully");
        } else {
            error_log("WPAIT: Auto-check - Data looks consistent, no cleanup needed");
        }
    }
    
    /**
     * Add admin styles
     */
    public function add_admin_styles() {
        if (isset($_GET['post_type']) && $_GET['post_type'] === 'product') {
            ?>
            <style>
            .column-import {
                width: 100px;
                text-align: center;
            }
            
            .import-column-content {
                display: flex;
                justify-content: center;
                align-items: center;
            }
            
            .last-import-date {
                text-align: center;
                line-height: 1.2;
            }
            
            .last-import-date span {
                color: #666;
                font-size: 11px;
                display: block;
            }
            </style>
            <?php
        }
    }
} 