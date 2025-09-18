<?php
/**
 * WP All Import Tracker - Settings Class
 * 
 * @package WP_All_Import_Tracker
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings functionality
 */
class WPAIT_Settings {
    
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_wpait_test_email', array($this, 'handle_test_email'));
        add_action('wp_ajax_wpait_refresh_cache', array($this, 'handle_refresh_cache'));
        add_action('wp_ajax_wpait_debug_import', array($this, 'handle_debug_import'));
        add_action('wp_ajax_wpait_reset_notifications', array($this, 'handle_reset_notifications'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('WP All Import Tracker Settings', 'wp-all-import-tracker'),
            __('Import Tracker', 'wp-all-import-tracker'),
            'manage_options',
            'wpait-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings
        register_setting('wpait_settings', 'wpait_import_ids', array(
            'type' => 'array',
            'default' => array(1),
            'sanitize_callback' => array($this, 'validate_import_ids')
        ));
        

        
        register_setting('wpait_settings', 'wpait_enable_auto_refresh', array(
            'type' => 'boolean',
            'default' => true
        ));
        
        register_setting('wpait_settings', 'wpait_enable_email_alerts', array(
            'type' => 'boolean',
            'default' => false
        ));
        
        register_setting('wpait_settings', 'wpait_alert_email', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_email'
        ));
        
        register_setting('wpait_settings', 'wpait_stale_threshold', array(
            'type' => 'integer',
            'default' => 150,
            'sanitize_callback' => 'absint'
        ));
        
        register_setting('wpait_settings', 'wpait_max_execution_time', array(
            'type' => 'integer',
            'default' => 180,
            'sanitize_callback' => 'absint'
        ));
        
        // Add settings sections
        add_settings_section(
            'wpait_general_section',
            __('General Settings', 'wp-all-import-tracker'),
            array($this, 'general_section_callback'),
            'wpait-settings'
        );
        
        // Add settings fields
        add_settings_field(
            'wpait_import_ids',
            __('Import IDs', 'wp-all-import-tracker'),
            array($this, 'import_ids_callback'),
            'wpait-settings',
            'wpait_general_section'
        );
        

        
        add_settings_field(
            'wpait_enable_auto_refresh',
            __('Enable Auto Refresh', 'wp-all-import-tracker'),
            array($this, 'enable_auto_refresh_callback'),
            'wpait-settings',
            'wpait_general_section'
        );
        
        // Add email alerts section
        add_settings_section(
            'wpait_email_section',
            __('Email Alerts', 'wp-all-import-tracker'),
            array($this, 'email_section_callback'),
            'wpait-settings'
        );
        
        add_settings_field(
            'wpait_enable_email_alerts',
            __('Enable Email Alerts', 'wp-all-import-tracker'),
            array($this, 'enable_email_alerts_callback'),
            'wpait-settings',
            'wpait_email_section'
        );
        
        add_settings_field(
            'wpait_alert_email',
            __('Alert Email Address', 'wp-all-import-tracker'),
            array($this, 'alert_email_callback'),
            'wpait-settings',
            'wpait_email_section'
        );
        
        add_settings_field(
            'wpait_stale_threshold',
            __('Stale Activity Threshold (minutes)', 'wp-all-import-tracker'),
            array($this, 'stale_threshold_callback'),
            'wpait-settings',
            'wpait_email_section'
        );
        
        add_settings_field(
            'wpait_max_execution_time',
            __('Max Execution Time (minutes)', 'wp-all-import-tracker'),
            array($this, 'max_execution_time_callback'),
            'wpait-settings',
            'wpait_email_section'
        );
        
        // Add notifications section
        add_settings_section(
            'wpait_notifications_section',
            __('Notification Management', 'wp-all-import-tracker'),
            array($this, 'notifications_section_callback'),
            'wpait-settings'
        );
        
        add_settings_field(
            'wpait_reset_dismissed_notifications',
            __('Reset Dismissed Notifications', 'wp-all-import-tracker'),
            array($this, 'reset_dismissed_notifications_callback'),
            'wpait-settings',
            'wpait_notifications_section'
        );
        
        add_settings_field(
            'wpait_refresh_cache',
            __('Refresh Cache', 'wp-all-import-tracker'),
            array($this, 'refresh_cache_callback'),
            'wpait-settings',
            'wpait_general_section'
        );
        
        add_settings_field(
            'wpait_debug_import',
            __('Debug Import Status', 'wp-all-import-tracker'),
            array($this, 'debug_import_callback'),
            'wpait-settings',
            'wpait_general_section'
        );
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Show success message
        if (isset($_GET['settings-updated'])) {
            add_settings_error('wpait_messages', 'wpait_message', __('Settings saved.', 'wp-all-import-tracker'), 'updated');
        }
        
        settings_errors('wpait_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="wpait-settings-wrapper">
                <div class="wpait-settings-main">
                    <form action="options.php" method="post">
                        <?php
                        settings_fields('wpait_settings');
                        do_settings_sections('wpait-settings');
                        submit_button(__('Save Settings', 'wp-all-import-tracker'));
                        ?>
                    </form>
                </div>
                
                <div class="wpait-settings-sidebar">
                    <div class="wpait-settings-box">
                        <h3><?php _e('Currently Tracking', 'wp-all-import-tracker'); ?></h3>
                        <?php 
                        global $wpdb;
                        $configured_import_ids = WPAIT()->get_import_ids();
                        
                        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pmxi_imports'")) {
                            $all_found = true;
                            $not_found_ids = array();
                            
                            foreach ($configured_import_ids as $import_id) {
                                $import_details = $wpdb->get_row($wpdb->prepare(
                                    "SELECT id, name, path, count FROM {$wpdb->prefix}pmxi_imports WHERE id = %d",
                                    $import_id
                                ));
                                
                                if ($import_details) {
                                    $import_name = !empty($import_details->name) ? $import_details->name : basename($import_details->path);
                                    $product_count = $import_details->count ? $import_details->count : __('Unknown', 'wp-all-import-tracker');
                                    
                                    echo '<div style="border-left: 3px solid #00a32a; padding-left: 10px; margin-bottom: 15px;">';
                                    echo '<p><strong>' . __('Import Name:', 'wp-all-import-tracker') . '</strong> ' . esc_html($import_name) . '</p>';
                                    echo '<p><strong>' . __('Import ID:', 'wp-all-import-tracker') . '</strong> ' . esc_html($import_id) . '</p>';
                                    echo '<p><strong>' . __('Import Rows:', 'wp-all-import-tracker') . '</strong> ' . esc_html($product_count) . '</p>';
                                    
                                    // Show last import date if available
                                    $last_import = $wpdb->get_var($wpdb->prepare(
                                        "SELECT MAX(date) FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d",
                                        $import_id
                                    ));
                                    
                                    if ($last_import) {
                                        echo '<p><strong>' . __('Last Import:', 'wp-all-import-tracker') . '</strong> ' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_import))) . '</p>';
                                    }
                                    echo '</div>';
                                } else {
                                    $not_found_ids[] = $import_id;
                                    $all_found = false;
                                }
                            }
                            
                            if (!empty($not_found_ids)) {
                                /* translators: %s: import IDs */
                                echo '<p style="color: #d63638;"><strong>' . esc_html__('Warning:', 'wp-all-import-tracker') . '</strong> ' . esc_html(sprintf(__('Import ID(s) %s not found!', 'wp-all-import-tracker'), implode(', ', $not_found_ids))) . '</p>';
                                echo '<p>' . __('Please update the Import IDs below.', 'wp-all-import-tracker') . '</p>';
                            }
                        } else {
                            echo '<p style="color: #d63638;"><strong>' . __('Warning:', 'wp-all-import-tracker') . '</strong> ' . __('WP All Import not detected!', 'wp-all-import-tracker') . '</p>';
                        }
                        ?>
                    </div>
                    
                    <div class="wpait-settings-box">
                        <h3><?php _e('About This Plugin', 'wp-all-import-tracker'); ?></h3>
                        <p><?php _e('WP All Import Tracker helps you monitor which WooCommerce products were updated or skipped during WP All Import runs.', 'wp-all-import-tracker'); ?></p>
                        
                        <h4><?php _e('Features:', 'wp-all-import-tracker'); ?></h4>
                        <ul>
                            <li><?php _e('Import column in product list', 'wp-all-import-tracker'); ?></li>
                            <li><?php _e('Filter products by import status', 'wp-all-import-tracker'); ?></li>
                            <li><?php _e('Precise timestamps for updated products', 'wp-all-import-tracker'); ?></li>
                            <li><?php _e('Automatic cache refresh after imports', 'wp-all-import-tracker'); ?></li>
                        </ul>
                    </div>
                    
                    <div class="wpait-settings-box">
                        <h3><?php _e('Email Alerts Status', 'wp-all-import-tracker'); ?></h3>
                        <?php
                        $email_alerts_enabled = get_option('wpait_enable_email_alerts', 0);
                        $alert_email = get_option('wpait_alert_email', '');
                        
                        if ($email_alerts_enabled) {
                            echo '<p style="color: #00a32a;"><strong>' . __('Status:', 'wp-all-import-tracker') . '</strong> ' . __('Enabled', 'wp-all-import-tracker') . '</p>';
                            $email_to_use = !empty($alert_email) ? $alert_email : get_option('admin_email');
                            echo '<p><strong>' . __('Alert Email:', 'wp-all-import-tracker') . '</strong> ' . esc_html($email_to_use) . '</p>';
                            
                            // Show import monitoring status
                            if (class_exists('WPAIT_Email_Alerts')) {
                                require_once WPAIT_PLUGIN_DIR . 'includes/class-email-alerts.php';
                                $email_alerts = new WPAIT_Email_Alerts();
                                $status = $email_alerts->get_import_status_summary();
                                echo '<p><strong>' . __('Import Status:', 'wp-all-import-tracker') . '</strong> ' . esc_html($status) . '</p>';
                            }
                            
                            // Show next cron run
                            $next_cron = wp_next_scheduled('wpait_check_import_status');
                            if ($next_cron) {
                                echo '<p><strong>' . __('Next Check:', 'wp-all-import-tracker') . '</strong> ' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_cron)) . '</p>';
                            }
                        } else {
                            echo '<p style="color: #d63638;"><strong>' . __('Status:', 'wp-all-import-tracker') . '</strong> ' . __('Disabled', 'wp-all-import-tracker') . '</p>';
                            echo '<p>' . __('Enable email alerts below to monitor import failures.', 'wp-all-import-tracker') . '</p>';
                        }
                        ?>
                    </div>
                    
                    <div class="wpait-settings-box">
                        <h3><?php _e('How to Find Import ID', 'wp-all-import-tracker'); ?></h3>
                        <p><?php _e('To find your WP All Import ID:', 'wp-all-import-tracker'); ?></p>
                        <ol>
                            <li><?php _e('Go to All Import > Manage Imports', 'wp-all-import-tracker'); ?></li>
                            <li><?php _e('Look at the URL when editing an import', 'wp-all-import-tracker'); ?></li>
                            <li><?php _e('The ID is the number in "id=" parameter', 'wp-all-import-tracker'); ?></li>
                        </ol>
                        <p><em><?php _e('Example: ...&id=11 means Import ID is 11', 'wp-all-import-tracker'); ?></em></p>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .wpait-settings-wrapper {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        
        .wpait-settings-main {
            flex: 2;
        }
        
        .wpait-settings-sidebar {
            flex: 1;
        }
        
        .wpait-settings-box {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .wpait-settings-box h3 {
            margin-top: 0;
            color: #1d2327;
        }
        
        .wpait-settings-box h4 {
            color: #1d2327;
            margin-bottom: 10px;
        }
        
        .wpait-settings-box ul {
            margin-left: 20px;
        }
        
        .wpait-settings-box ol {
            margin-left: 20px;
        }
        </style>
        <?php
    }
    
    /**
     * General section callback
     */
    public function general_section_callback() {
        echo '<p>' . __('Configure the basic settings for WP All Import Tracker.', 'wp-all-import-tracker') . '</p>';
    }
    
    /**
     * Import IDs field callback
     */
    public function import_ids_callback() {
        global $wpdb;
        $values = get_option('wpait_import_ids', array(1));
        if (!is_array($values)) {
            $values = array($values);
        }
        
        echo '<div id="wpait_import_ids_container">';
        foreach ($values as $index => $value) {
            echo '<div class="wpait_import_id_row" style="margin-bottom: 10px; display: flex; align-items: center;">';
            echo '<input type="number" name="wpait_import_ids[]" value="' . esc_attr($value) . '" min="1" class="regular-text" style="margin-right: 10px;" />';
            if (count($values) > 1) {
                echo '<button type="button" class="button wpait_remove_import_id" style="margin-right: 10px;">' . __('Remove', 'wp-all-import-tracker') . '</button>';
            }
            echo '</div>';
        }
        echo '</div>';
        
        echo '<button type="button" id="wpait_add_import_id" class="button" style="margin-top: 10px;">' . __('Add Another Import ID', 'wp-all-import-tracker') . '</button>';
        echo '<button type="button" id="wpait_detect_imports" class="button" style="margin-left: 10px;">' . __('Detect Available Imports', 'wp-all-import-tracker') . '</button>';
        echo '<p class="description">' . __('The WP All Import IDs to track. You can track multiple imports by adding more IDs.', 'wp-all-import-tracker') . '</p>';
        
        // Show available imports if WP All Import tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pmxi_imports'")) {
            echo '<div id="wpait_available_imports" style="margin-top: 10px; display: none;">';
            echo '<h4>' . __('Available Imports:', 'wp-all-import-tracker') . '</h4>';
            
            $imports = $wpdb->get_results("SELECT id, name, path FROM {$wpdb->prefix}pmxi_imports ORDER BY id DESC LIMIT 10");
            
            if ($imports) {
                echo '<table class="widefat" style="max-width: 600px;">';
                echo '<thead><tr><th>' . __('ID', 'wp-all-import-tracker') . '</th><th>' . __('Import Name', 'wp-all-import-tracker') . '</th><th>' . __('Action', 'wp-all-import-tracker') . '</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($imports as $import) {
                    $import_name = !empty($import->name) ? $import->name : basename($import->path);
                    $is_tracked = in_array($import->id, $values) ? ' (Tracked)' : '';
                    echo '<tr>';
                    echo '<td><strong>' . esc_html($import->id) . '</strong></td>';
                    echo '<td>' . esc_html($import_name) . esc_html($is_tracked) . '</td>';
                    echo '<td><button type="button" class="button-secondary wpait_add_import" data-import-id="' . esc_attr($import->id) . '">' . __('Add', 'wp-all-import-tracker') . '</button></td>';
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
            } else {
                echo '<p><em>' . __('No imports found. Make sure you have created at least one import in WP All Import.', 'wp-all-import-tracker') . '</em></p>';
            }
            
            echo '</div>';
        }
        
        // Add JavaScript for the functionality
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Add new import ID row
            $('#wpait_add_import_id').click(function() {
                var newRow = '<div class="wpait_import_id_row" style="margin-bottom: 10px; display: flex; align-items: center;">' +
                    '<input type="number" name="wpait_import_ids[]" value="" min="1" class="regular-text" style="margin-right: 10px;" />' +
                    '<button type="button" class="button wpait_remove_import_id" style="margin-right: 10px;"><?php echo esc_js(__('Remove', 'wp-all-import-tracker')); ?></button>' +
                    '</div>';
                $('#wpait_import_ids_container').append(newRow);
            });
            
            // Remove import ID row
            $(document).on('click', '.wpait_remove_import_id', function() {
                if ($('.wpait_import_id_row').length > 1) {
                    $(this).closest('.wpait_import_id_row').remove();
                }
            });
            
            // Detect available imports
            $('#wpait_detect_imports').click(function() {
                $('#wpait_available_imports').toggle();
                $(this).text($(this).text() == '<?php echo esc_js(__('Detect Available Imports', 'wp-all-import-tracker')); ?>' ? 
                    '<?php echo esc_js(__('Hide Available Imports', 'wp-all-import-tracker')); ?>' : 
                    '<?php echo esc_js(__('Detect Available Imports', 'wp-all-import-tracker')); ?>');
            });
            
            // Add import from available list
            $(document).on('click', '.wpait_add_import', function() {
                var importId = $(this).data('import-id');
                var newRow = '<div class="wpait_import_id_row" style="margin-bottom: 10px; display: flex; align-items: center;">' +
                    '<input type="number" name="wpait_import_ids[]" value="' + importId + '" min="1" class="regular-text" style="margin-right: 10px;" />' +
                    '<button type="button" class="button wpait_remove_import_id" style="margin-right: 10px;"><?php echo esc_js(__('Remove', 'wp-all-import-tracker')); ?></button>' +
                    '</div>';
                $('#wpait_import_ids_container').append(newRow);
                
                // Update the table to show this import is now tracked
                $(this).closest('tr').find('td:last').html('<span style="color: #46b450;">✓ Tracked</span>');
            });
        });
        </script>
        <?php
    }
    

    
    /**
     * Enable auto refresh field callback
     */
    public function enable_auto_refresh_callback() {
        $value = get_option('wpait_enable_auto_refresh', 1);
        echo '<input type="checkbox" id="wpait_enable_auto_refresh" name="wpait_enable_auto_refresh" value="1"' . checked(1, $value, false) . ' />';
        echo '<label for="wpait_enable_auto_refresh">' . __('Automatically refresh cache when imports start/finish', 'wp-all-import-tracker') . '</label>';
        echo '<p class="description">' . __('Recommended to keep enabled for automatic updates.', 'wp-all-import-tracker') . '</p>';
    }
    
    /**
     * Email section callback
     */
    public function email_section_callback() {
        echo '<p>' . __('Configure email alerts for import failures and issues. The system will monitor your imports and send alerts when problems are detected.', 'wp-all-import-tracker') . '</p>';
    }
    
    /**
     * Enable email alerts field callback
     */
    public function enable_email_alerts_callback() {
        $value = get_option('wpait_enable_email_alerts', 0);
        echo '<input type="checkbox" id="wpait_enable_email_alerts" name="wpait_enable_email_alerts" value="1"' . checked(1, $value, false) . ' />';
        echo '<label for="wpait_enable_email_alerts">' . __('Enable email alerts for import failures', 'wp-all-import-tracker') . '</label>';
        echo '<p class="description">' . __('When enabled, the system will monitor imports and send email alerts when issues are detected.', 'wp-all-import-tracker') . '</p>';
    }
    
    /**
     * Alert email field callback
     */
    public function alert_email_callback() {
        $value = get_option('wpait_alert_email', '');
        echo '<input type="email" id="wpait_alert_email" name="wpait_alert_email" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<button type="button" id="wpait_test_email" class="button" style="margin-left: 10px;">' . __('Send Test Email', 'wp-all-import-tracker') . '</button>';
        echo '<p class="description">' . __('Email address to receive import failure notifications. Leave empty to use the admin email.', 'wp-all-import-tracker') . '</p>';
        echo '<div id="wpait_test_email_result" style="margin-top: 10px;"></div>';
        
        // Add JavaScript for test email
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#wpait_test_email').click(function() {
                var button = $(this);
                var originalText = button.text();
                var email = $('#wpait_alert_email').val() || '<?php echo esc_js(get_option('admin_email')); ?>';
                
                // Disable button and show loading
                button.prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'wp-all-import-tracker')); ?>');
                $('#wpait_test_email_result').html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpait_test_email',
                        email: email,
                        _wpnonce: '<?php echo wp_create_nonce('wpait_test_email'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#wpait_test_email_result').html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                        } else {
                            $('#wpait_test_email_result').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#wpait_test_email_result').html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Error sending test email. Please try again.', 'wp-all-import-tracker')); ?></p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Stale threshold field callback
     */
    public function stale_threshold_callback() {
        $value = get_option('wpait_stale_threshold', 150);
        echo '<input type="number" id="wpait_stale_threshold" name="wpait_stale_threshold" value="' . esc_attr($value) . '" min="30" max="1440" class="small-text" />';
        echo '<p class="description">' . __('Minutes of inactivity before considering an import as stale. Default: 150 minutes.', 'wp-all-import-tracker') . '</p>';
    }
    
    /**
     * Max execution time field callback
     */
    public function max_execution_time_callback() {
        $value = get_option('wpait_max_execution_time', 180);
        echo '<input type="number" id="wpait_max_execution_time" name="wpait_max_execution_time" value="' . esc_attr($value) . '" min="60" max="1440" class="small-text" />';
        echo '<p class="description">' . __('Maximum execution time in minutes before considering an import as overrunning. Default: 180 minutes.', 'wp-all-import-tracker') . '</p>';
    }
    
    /**
     * Refresh cache field callback
     */
    public function refresh_cache_callback() {
        $configured_import_ids = WPAIT()->get_import_ids();
        echo '<button type="button" id="wpait_refresh_cache" class="button">' . __('Refresh Import Cache Now', 'wp-all-import-tracker') . '</button>';
        echo '<p class="description">' . __('Click to immediately refresh the import status cache and update the product counts.', 'wp-all-import-tracker') . '</p>';
        echo '<div id="wpait_refresh_result" style="margin-top: 10px;"></div>';
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#wpait_refresh_cache').click(function() {
                var button = $(this);
                var originalText = button.text();
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Refreshing...', 'wp-all-import-tracker')); ?>');
                $('#wpait_refresh_result').html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpait_refresh_cache',
                        import_ids: <?php echo json_encode($configured_import_ids); ?>,
                        _wpnonce: '<?php echo wp_create_nonce('wpait_refresh_cache'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#wpait_refresh_result').html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                            // Reload the page after 2 seconds to show updated counts
                            setTimeout(function() {
                                window.location.reload();
                            }, 2000);
                        } else {
                            $('#wpait_refresh_result').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#wpait_refresh_result').html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Error refreshing cache. Please try again.', 'wp-all-import-tracker')); ?></p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Debug import callback
     */
    public function debug_import_callback() {
        global $wpdb;
        $configured_import_ids = WPAIT()->get_import_ids();
        
        echo '<button type="button" id="wpait_debug_import" class="button">' . __('Check Import Data Consistency', 'wp-all-import-tracker') . '</button>';
        echo '<p class="description">' . __('Check for inconsistencies between import history and pmxi_posts table.', 'wp-all-import-tracker') . '</p>';
        echo '<div id="wpait_debug_result" style="margin-top: 10px;"></div>';
        
        // Show current status for all configured imports
        echo '<div style="background: #f0f0f1; padding: 10px; margin-top: 10px; border-left: 4px solid #0073aa;">';
        echo '<strong>' . __('Current Status:', 'wp-all-import-tracker') . '</strong><br><br>';
        
        foreach ($configured_import_ids as $import_id) {
            $latest_summary = $wpdb->get_var($wpdb->prepare(
                "SELECT summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 1",
                $import_id
            ));
            
            $total_in_pmxi = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d",
                $import_id
            ));
            
            echo '<strong>Import ID ' . $import_id . ':</strong><br>';
            echo __('Latest import summary:', 'wp-all-import-tracker') . ' ' . esc_html($latest_summary) . '<br>';
            echo __('Products in pmxi_posts table:', 'wp-all-import-tracker') . ' ' . esc_html($total_in_pmxi) . '<br>';
            
            if (strpos($latest_summary, '0 updated') !== false && $total_in_pmxi > 0) {
                echo '<span style="color: #d63638;"><strong>' . __('⚠️ INCONSISTENCY DETECTED!', 'wp-all-import-tracker') . '</strong></span>';
            } else {
                echo '<span style="color: #00a32a;"><strong>' . __('✅ Data looks consistent', 'wp-all-import-tracker') . '</strong></span>';
            }
            echo '<br><br>';
        }
        echo '</div>';
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#wpait_debug_import').click(function() {
                var button = $(this);
                var originalText = button.text();
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Checking...', 'wp-all-import-tracker')); ?>');
                $('#wpait_debug_result').html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpait_debug_import',
                        import_ids: <?php echo json_encode($configured_import_ids); ?>,
                        _wpnonce: '<?php echo wp_create_nonce('wpait_debug_import'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#wpait_debug_result').html('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                        } else {
                            $('#wpait_debug_result').html('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#wpait_debug_result').html('<div class="notice notice-error inline"><p><?php echo esc_js(__('Error checking import data.', 'wp-all-import-tracker')); ?></p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text(originalText);
                        // Reload page after 3 seconds to show updated status
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Notifications section callback
     */
    public function notifications_section_callback() {
        ?>
        <p><?php _e('Manage import error notifications and their dismissal settings.', 'wp-all-import-tracker'); ?></p>
        <?php
    }
    
    /**
     * Reset dismissed notifications callback
     */
    public function reset_dismissed_notifications_callback() {
        $dismissed_count = count(get_option('wpait_dismissed_notifications', array()));
        ?>
        <div>
            <p>
                <?php printf(
                    __('Currently have %d dismissed notifications stored.', 'wp-all-import-tracker'),
                    $dismissed_count
                ); ?>
            </p>
            <button type="button" id="wpait-reset-notifications" class="button button-secondary">
                <?php _e('Reset All Dismissed Notifications', 'wp-all-import-tracker'); ?>
            </button>
            <span id="wpait-reset-notifications-result" style="margin-left: 10px;"></span>
            <p class="description">
                <?php _e('This will show all previously dismissed import error notifications again. Use this if you want to see notifications for issues that were previously dismissed.', 'wp-all-import-tracker'); ?>
            </p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#wpait-reset-notifications').on('click', function() {
                var button = $(this);
                var resultSpan = $('#wpait-reset-notifications-result');
                
                button.prop('disabled', true).text('<?php _e('Resetting...', 'wp-all-import-tracker'); ?>');
                resultSpan.html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wpait_reset_notifications',
                        _wpnonce: '<?php echo wp_create_nonce('wpait_reset_notifications'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            resultSpan.html('<span style="color: green;">✅ ' + response.data + '</span>');
                            button.text('<?php _e('Reset All Dismissed Notifications', 'wp-all-import-tracker'); ?>');
                        } else {
                            resultSpan.html('<span style="color: red;">❌ ' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        resultSpan.html('<span style="color: red;">❌ <?php _e('Error occurred', 'wp-all-import-tracker'); ?></span>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Validate import IDs
     */
    public function validate_import_ids($values) {
        global $wpdb;
        
        // Ensure we have an array
        if (!is_array($values)) {
            $values = array($values);
        }
        
        // Remove empty values and ensure they are positive integers
        $valid_import_ids = array();
        $invalid_ids = array();
        
        foreach ($values as $value) {
            $import_id = absint($value);
            if ($import_id > 0) {
                $valid_import_ids[] = $import_id;
            } else {
                $invalid_ids[] = $value;
            }
        }
        
        // Check for invalid IDs
        if (!empty($invalid_ids)) {
            add_settings_error(
                'wpait_import_ids',
                'invalid_import_ids',
                __('Some import IDs are invalid. Only positive numbers are allowed.', 'wp-all-import-tracker'),
                'error'
            );
            return get_option('wpait_import_ids', array(1));
        }
        
        // Check if we have at least one import ID
        if (empty($valid_import_ids)) {
            add_settings_error(
                'wpait_import_ids',
                'no_import_ids',
                __('At least one import ID is required.', 'wp-all-import-tracker'),
                'error'
            );
            return get_option('wpait_import_ids', array(1));
        }
        
        // Remove duplicates
        $valid_import_ids = array_unique($valid_import_ids);
        
        // Check if WP All Import tables exist
        if (!$wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}pmxi_imports'")) {
            add_settings_error(
                'wpait_import_ids',
                'no_wpai_tables',
                __('WP All Import tables not found. Please make sure WP All Import is installed and activated.', 'wp-all-import-tracker'),
                'error'
            );
            return get_option('wpait_import_ids', array(1));
        }
        
        // Check if all import IDs exist
        $not_found_ids = array();
        $found_imports = array();
        
        foreach ($valid_import_ids as $import_id) {
            $import_exists = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, path, count FROM {$wpdb->prefix}pmxi_imports WHERE id = %d",
                $import_id
            ));
            
            if ($import_exists) {
                $found_imports[] = $import_exists;
            } else {
                $not_found_ids[] = $import_id;
            }
        }
        
        // Show errors for not found IDs
        if (!empty($not_found_ids)) {
            add_settings_error(
                'wpait_import_ids',
                'import_ids_not_found',
                sprintf(
                    __('Import ID(s) %s not found in WP All Import. Please check the IDs and try again.', 'wp-all-import-tracker'),
                    implode(', ', $not_found_ids)
                ),
                'error'
            );
            return get_option('wpait_import_ids', array(1));
        }
        
        // Success - show confirmation with import details
        $import_details = array();
        foreach ($found_imports as $import) {
            $import_name = !empty($import->name) ? $import->name : basename($import->path);
            $product_count = $import->count ? $import->count : __('Unknown', 'wp-all-import-tracker');
            $import_details[] = sprintf('"%s" (ID: %d, Products: %s)', $import_name, $import->id, $product_count);
        }
        
        add_settings_error(
            'wpait_import_ids',
            'import_ids_updated',
            sprintf(
                __('Import IDs updated successfully! Now tracking: %s', 'wp-all-import-tracker'),
                implode(', ', $import_details)
            ),
            'updated'
        );
        
        return $valid_import_ids;
    }
    
    /**
     * Handle test email AJAX request
     */
    public function handle_test_email() {
        // Check nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wpait_test_email')) {
            wp_send_json_error(__('Security check failed.', 'wp-all-import-tracker'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-all-import-tracker'));
        }
        
        // Get email address
        $email = sanitize_email($_POST['email']);
        if (empty($email)) {
            wp_send_json_error(__('Please enter a valid email address.', 'wp-all-import-tracker'));
        }
        
        // Load email alerts class
        require_once WPAIT_PLUGIN_DIR . 'includes/class-email-alerts.php';
        $email_alerts = new WPAIT_Email_Alerts();
        
        // Temporarily set the email for testing
        $original_email = get_option('wpait_alert_email', '');
        update_option('wpait_alert_email', $email);
        
        // Send test email
        $result = $email_alerts->send_test_email();
        
        // Restore original email
        if (empty($original_email)) {
            delete_option('wpait_alert_email');
        } else {
            update_option('wpait_alert_email', $original_email);
        }
        
        // Return result
        if ($result) {
            /* translators: %s: email address */
            wp_send_json_success(sprintf(__('Test email sent successfully to %s!', 'wp-all-import-tracker'), $email));
        } else {
            wp_send_json_error(__('Failed to send test email. Please check your email configuration.', 'wp-all-import-tracker'));
        }
    }
    
    /**
     * Handle refresh cache AJAX request
     */
    public function handle_refresh_cache() {
        // Check nonce
        if (!wp_verify_nonce($_POST['_wpnonce'], 'wpait_refresh_cache')) {
            wp_send_json_error(__('Security check failed.', 'wp-all-import-tracker'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'wp-all-import-tracker'));
        }
        
        $import_ids = isset($_POST['import_ids']) ? $_POST['import_ids'] : array();
        if (!is_array($import_ids)) {
            $import_ids = array($import_ids);
        }
        
        $total_updated = 0;
        $total_skipped = 0;
        $results = array();
        
        // Clear all caches for all import IDs
        foreach ($import_ids as $import_id) {
            $import_id = intval($import_id);
            $cache_key = "wpait_counts_{$import_id}";
            delete_transient($cache_key);
            
            // Clear static caches in tracker
            if (class_exists('WPAIT_Tracker')) {
                // Force cache clear by calling the method
                do_action('pmxi_import_complete', $import_id);
            }
            
            // Get fresh counts
            require_once WPAIT_PLUGIN_DIR . 'includes/class-admin.php';
            $admin = new WPAIT_Admin();
            $reflection = new ReflectionClass($admin);
            $method = $reflection->getMethod('get_import_status_counts');
            $method->setAccessible(true);
            $counts = $method->invoke($admin, $import_id);
            
            $total_updated += $counts['updated'];
            $total_skipped += $counts['skipped'];
            $results[] = sprintf(__('Import ID %d: Updated: %d, Skipped: %d', 'wp-all-import-tracker'), $import_id, $counts['updated'], $counts['skipped']);
        }
        
        $message = __('Cache refreshed successfully!', 'wp-all-import-tracker') . "\n" . implode("\n", $results);
        /* translators: %1$d: total updated count, %2$d: total skipped count */
        $message .= "\n" . sprintf(__('Total - Updated: %1$d, Skipped: %2$d', 'wp-all-import-tracker'), $total_updated, $total_skipped);
        
        wp_send_json_success($message);
    }
     
     /**
      * Handle debug import AJAX request
      */
     public function handle_debug_import() {
         global $wpdb;
         
         // Check nonce
         if (!wp_verify_nonce($_POST['_wpnonce'], 'wpait_debug_import')) {
             wp_send_json_error(__('Security check failed.', 'wp-all-import-tracker'));
         }
         
         // Check user capabilities
         if (!current_user_can('manage_options')) {
             wp_send_json_error(__('Insufficient permissions.', 'wp-all-import-tracker'));
         }
         
         $import_ids = isset($_POST['import_ids']) ? $_POST['import_ids'] : array();
         if (!is_array($import_ids)) {
             $import_ids = array($import_ids);
         }
         
         $message = "Debug Results:\n";
         $total_fixed = 0;
         
         foreach ($import_ids as $import_id) {
             $import_id = intval($import_id);
             
             $message .= "\n--- Import ID {$import_id} ---\n";
             
             // Get latest import summary
             $latest_summary = $wpdb->get_var($wpdb->prepare(
                 "SELECT summary FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY date DESC LIMIT 1",
                 $import_id
             ));
             
             $total_in_pmxi = $wpdb->get_var($wpdb->prepare(
                 "SELECT COUNT(*) FROM {$wpdb->prefix}pmxi_posts WHERE import_id = %d",
                 $import_id
             ));
             
             $message .= "Latest import: {$latest_summary}\n";
             $message .= "Products in pmxi_posts: {$total_in_pmxi}\n";
             
             // Check for inconsistency
             if (strpos($latest_summary, '0 updated') !== false && $total_in_pmxi > 0) {
                 $message .= "\n⚠️ INCONSISTENCY DETECTED!\n";
                 $message .= "Import shows 0 updated but pmxi_posts table has {$total_in_pmxi} entries.\n";
                 $message .= "This suggests WP All Import didn't clear the table properly.\n\n";
                 
                 // Offer to clean up
                 $message .= "FIXING: Clearing pmxi_posts table for this import...\n";
                 
                 $deleted = $wpdb->delete(
                     $wpdb->prefix . 'pmxi_posts',
                     array('import_id' => $import_id),
                     array('%d')
                 );
                 
                 $message .= "Deleted {$deleted} entries from pmxi_posts table.\n";
                 $message .= "Cache cleared. Counts should now be correct.\n";
                 
                 // Clear cache
                 delete_transient("wpait_counts_{$import_id}");
                 $total_fixed++;
             } else {
                 $message .= "\n✅ Data looks consistent. No action needed.";
             }
         }
         
         if ($total_fixed > 0) {
             $message .= "\n\nTotal inconsistencies fixed: {$total_fixed}";
         }
         
         wp_send_json_success($message);
     }
     
     /**
      * Handle reset notifications AJAX request
      */
     public function handle_reset_notifications() {
         // Check nonce
         if (!wp_verify_nonce($_POST['_wpnonce'], 'wpait_reset_notifications')) {
             wp_send_json_error(__('Security check failed.', 'wp-all-import-tracker'));
         }
         
         // Check user capabilities
         if (!current_user_can('manage_options')) {
             wp_send_json_error(__('Insufficient permissions.', 'wp-all-import-tracker'));
         }
         
         // Reset dismissed notifications
         delete_option('wpait_dismissed_notifications');
         
         wp_send_json_success(__('All dismissed notifications have been reset. You will see import error notifications again.', 'wp-all-import-tracker'));
     }
} 