<?php
/**
 * Core tracker class.
 *
 * @package Oli_Import_Tracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core tracker functionality.
 */
class OLIIT_Tracker {

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
        add_filter('manage_product_posts_columns', array($this, 'add_import_column'));
        add_action('manage_product_posts_custom_column', array($this, 'add_import_column_content'), 10, 2);
        add_action('pre_get_posts', array($this, 'handle_import_filtering'));
        add_action('pmxi_import_complete', array($this, 'clear_cache_on_import_complete'));
        add_action('pmxi_import_failed', array($this, 'clear_cache_on_import_complete'));
        add_action('pmxi_import_started', array($this, 'clear_cache_on_import_start'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }

    /**
     * Enqueue product-list styles.
     *
     * @param string $hook Current admin hook.
     * @return void
     */
    public function enqueue_admin_styles($hook) {
        if ('edit.php' !== $hook) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || 'edit-product' !== $screen->id) {
            return;
        }

        wp_enqueue_style(
            'oliit-admin-products',
            OLIIT_PLUGIN_URL . 'assets/css/admin-products.css',
            array(),
            OLIIT_VERSION
        );
    }

    /**
     * Add import column to the product list.
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_import_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            if ('date' === $key) {
                $new_columns['import'] = esc_html__('Import', 'oli-import-tracker');
            }
            $new_columns[$key] = $value;
        }
        return $new_columns;
    }

    /**
     * Add content to the import column.
     *
     * @param string $column  Column key.
     * @param int    $post_id Product ID.
     * @return void
     */
    public function add_import_column_content($column, $post_id) {
        if ('import' !== $column) {
            return;
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return;
        }

        $configured_import_ids = oliit()->get_import_ids();
        $last_import_info      = $this->get_last_import_info($post_id, $configured_import_ids);

        echo '<div class="import-column-content">';
        echo '<div class="last-import-date">';
        if ($last_import_info) {
            echo '<span>' . esc_html($last_import_info['date']) . '</span>';
            if (count($configured_import_ids) > 1) {
                echo '<br><small class="oliit-import-id-note">' . esc_html(
                    sprintf(
                        /* translators: %d: import ID */
                        __('Import #%d', 'oli-import-tracker'),
                        absint($last_import_info['import_id'])
                    )
                ) . '</small>';
            }
        } else {
            echo '<span>' . esc_html__('Never imported', 'oli-import-tracker') . '</span>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Get last import info for a product across configured imports.
     *
     * @param int   $post_id               Product ID.
     * @param int[] $configured_import_ids Import IDs.
     * @return array|false
     */
    private function get_last_import_info($post_id, $configured_import_ids) {
        global $wpdb;

        if (empty($configured_import_ids)) {
            return false;
        }

        $tables = array(
            $wpdb->prefix . 'pmxi_imports',
            $wpdb->prefix . 'pmxi_posts',
            $wpdb->prefix . 'pmxi_history',
        );

        foreach ($tables as $table) {
            if (!oliit_table_exists($table)) {
                return false;
            }
        }

        $latest_import_info = null;
        $latest_date        = null;

        foreach ($configured_import_ids as $import_id) {
            $import_info = $this->get_import_info_for_product($post_id, $import_id);
            if ($import_info && (!$latest_date || $import_info['date'] > $latest_date)) {
                $latest_import_info = $import_info;
                $latest_date        = $import_info['date'];
            }
        }

        return $latest_import_info;
    }

    /**
     * Get import info for a product from a specific import.
     *
     * @param int $post_id   Product ID.
     * @param int $import_id Import ID.
     * @return array|false
     */
    private function get_import_info_for_product($post_id, $import_id) {
        global $wpdb;

        $current_last_import_run = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(h.date) FROM {$wpdb->prefix}pmxi_history h WHERE h.import_id = %d",
            $import_id
        ));

        if (!$current_last_import_run) {
            return false;
        }

        $product_imported = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d AND post_id = %d",
            $import_id,
            $post_id
        ));

        if ($product_imported > 0) {
            $skipped_products = $this->get_skipped_products_from_logs($import_id);
            $product_title    = get_the_title($post_id);
            $was_updated      = !in_array($product_title, $skipped_products, true);
            $post_modified    = get_post_modified_time('M j, Y g:i A', false, $post_id);
            $status_text      = $was_updated ? __('Updated', 'oli-import-tracker') : __('Skipped', 'oli-import-tracker');

            return array(
                'date'      => $post_modified . ' (' . $status_text . ')',
                'import_id' => $import_id,
                'status'    => $was_updated ? 'updated' : 'skipped',
            );
        }

        return false;
    }

    /**
     * Get skipped products from import logs.
     *
     * @param int $import_id Import ID.
     * @return string[]
     */
    private function get_skipped_products_from_logs($import_id) {
        global $wpdb;
        $skipped_products = array();

        if (!is_numeric($import_id) || $import_id <= 0) {
            return $skipped_products;
        }

        if (!oliit_table_exists($wpdb->prefix . 'pmxi_history')) {
            return $skipped_products;
        }

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

        $updated_count = 0;
        if (preg_match('/(\d+)\s+updated/', $latest_run->summary, $matches)) {
            $updated_count = intval($matches[1]);
        }

        if ($updated_count > 0) {
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
     * Handle import filtering.
     *
     * @param WP_Query $query Query.
     * @return void
     */
    public function handle_import_filtering($query) {
        if (!is_admin() || !$query->is_main_query() || !isset($_GET['post_type']) || 'product' !== $_GET['post_type']) {
            return;
        }

        if (isset($_GET['import_filter'])) {
            add_filter('posts_join', array($this, 'custom_import_join'), 10, 2);
            add_filter('posts_where', array($this, 'custom_import_where'), 10, 2);
        }
    }

    /**
     * Custom JOIN for import filtering.
     *
     * @param string   $join  Join SQL.
     * @param WP_Query $query Query.
     * @return string
     */
    public function custom_import_join($join, $query) {
        global $wpdb;

        if (!is_admin() || !$query->is_main_query() || !isset($_GET['import_filter'])) {
            return $join;
        }

        $import_filter = sanitize_text_field(wp_unslash($_GET['import_filter']));
        $import_id     = oliit()->get_import_id();
        if ($import_id <= 0) {
            return $join;
        }

        if ('updated' === $import_filter) {
            $join .= $wpdb->prepare(
                " INNER JOIN {$wpdb->prefix}pmxi_posts pmxi ON {$wpdb->posts}.ID = pmxi.post_id AND pmxi.import_id = %d",
                $import_id
            );
        } elseif ('skipped' === $import_filter) {
            $join .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->prefix}pmxi_posts pmxi ON {$wpdb->posts}.ID = pmxi.post_id AND pmxi.import_id = %d",
                $import_id
            );
        }

        return $join;
    }

    /**
     * Custom WHERE for import filtering.
     *
     * @param string   $where Where SQL.
     * @param WP_Query $query Query.
     * @return string
     */
    public function custom_import_where($where, $query) {
        global $wpdb;

        if (!is_admin() || !$query->is_main_query() || !isset($_GET['import_filter'])) {
            return $where;
        }

        $import_filter = sanitize_text_field(wp_unslash($_GET['import_filter']));

        if ('skipped' === $import_filter) {
            $where .= " AND pmxi.post_id IS NULL AND {$wpdb->posts}.post_status = 'publish'";
        } elseif ('updated' === $import_filter) {
            $where .= " AND {$wpdb->posts}.post_status = 'publish'";
        }

        return $where;
    }

    /**
     * Clear cache when an import completes.
     *
     * @param int $import_id Import ID.
     * @return void
     */
    public function clear_cache_on_import_complete($import_id) {
        delete_transient('oliit_counts_' . absint($import_id));
        oliit_log('Cache cleared on import complete for import ' . absint($import_id));
        $this->auto_detect_and_fix_inconsistencies($import_id);
    }

    /**
     * Clear cache when an import starts.
     *
     * @param int $import_id Import ID.
     * @return void
     */
    public function clear_cache_on_import_start($import_id) {
        delete_transient('oliit_counts_' . absint($import_id));
        oliit_log('Cache cleared on import start for import ' . absint($import_id));
    }

    /**
     * Detect and fix pmxi_posts inconsistencies after import completion.
     *
     * @param int $import_id Import ID.
     * @return void
     */
    private function auto_detect_and_fix_inconsistencies($import_id) {
        global $wpdb;

        $tracked_import_id = oliit()->get_import_id();
        if ((int) $import_id !== (int) $tracked_import_id) {
            return;
        }

        oliit_log("Running automatic inconsistency detection for import {$import_id}");

        $latest_summary = $wpdb->get_var($wpdb->prepare(
            "SELECT summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 1",
            $import_id
        ));

        $total_in_pmxi = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d",
            $import_id
        ));

        oliit_log("Auto-check - Latest import: {$latest_summary}");
        oliit_log("Auto-check - Products in pmxi_posts: {$total_in_pmxi}");

        if (is_string($latest_summary) && false !== strpos($latest_summary, '0 updated') && $total_in_pmxi > 0) {
            oliit_log("AUTO-INCONSISTENCY DETECTED! Import shows 0 updated but pmxi_posts has {$total_in_pmxi} entries. Auto-fixing...");

            $deleted = $wpdb->delete(
                $wpdb->prefix . 'pmxi_posts',
                array('import_id' => $import_id),
                array('%d')
            );

            oliit_log("Auto-cleanup - Deleted {$deleted} stale entries from pmxi_posts table");

            delete_transient('oliit_counts_' . absint($import_id));

            oliit_log('Auto-cleanup completed successfully');
        } else {
            oliit_log('Auto-check - Data looks consistent, no cleanup needed');
        }
    }
}
