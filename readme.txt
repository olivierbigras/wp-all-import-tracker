=== Oli Import Tracker for WP All Import ===
Contributors: olivierbigras
Tags: woocommerce, wp-all-import, import, tracking, products
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track and display WP All Import status for WooCommerce products. Shows which products were updated or skipped during imports with precise timestamps.

== Description ==

**Oli Import Tracker for WP All Import** helps you monitor and track the status of your WooCommerce product imports when using WP All Import. Get notifications when imports get stuck or fail, and see which products were updated or skipped during your import runs.

### Key Features

* **Smart Import Monitoring**: Automatically detects when imports get stuck, fail, or take too long
* **Optional Email Alerts**: Email notifications when issues are detected (off until you enable them)
* **Import Status Column**: Adds an "Import" column to your WooCommerce product list showing the last import status
* **Precise Timestamps**: Shows exact modification times for updated products and import start time for skipped products
* **Smart Filtering**: Filter your product list to show only updated or skipped products
* **Real-time Updates**: Automatically refreshes data when imports start or complete
* **Performance Optimized**: Works during WooCommerce background processing
* **Multiple Imports**: Track more than one WP All Import configuration at once

### Import Issue Detection

* **Stuck Processing**: Detects imports stuck in processing state with no activity
* **Long Running Imports**: Alerts when imports run longer than expected
* **Failed Imports**: Monitors for imports that have failed and need attention
* **Triggered But Not Starting**: Catches imports that are scheduled but never start
* **Admin Dashboard Alerts**: Shows notices in WordPress admin when issues occur

### Perfect For

* E-commerce stores with frequent product updates
* Automated import workflows requiring reliability monitoring
* Inventory management systems
* Product data synchronization
* Import troubleshooting and monitoring
* Preventing data sync issues before they impact your store

### How It Works

1. **Install and activate** the plugin
2. Configure the WP All Import IDs to track under Settings > Import Tracker
3. **View import status** in your WooCommerce product list
4. **Filter products** by "Updated" or "Skipped" status
5. Optionally **enable email alerts** when you want notifications

### Import Status Information

* **Updated Products**: Shows the exact time each product was modified during the import
* **Skipped Products**: Shows products that exist on your site but were not modified (data unchanged)
* **Import Summary**: Displays the last import date and quick filter links

### Settings

* **Import IDs**: Configure which WP All Import jobs to track
* **Auto Refresh**: Automatically update data when imports run
* **Email Alerts**: Optional notifications for stale, failed, or long-running imports

### Requirements

* WordPress 5.0 or higher
* WooCommerce 5.0 or higher
* WP All Import plugin
* PHP 7.4 or higher

== Installation ==

### Automatic Installation

1. Login to your WordPress admin panel
2. Go to Plugins > Add New
3. Search for "Oli Import Tracker for WP All Import"
4. Click "Install Now" and then "Activate"

### Manual Installation

1. Download the plugin ZIP file
2. Login to your WordPress admin panel
3. Go to Plugins > Add New > Upload Plugin
4. Choose the ZIP file and click "Install Now"
5. Activate the plugin

### Configuration

1. Go to Settings > Import Tracker
2. Enter your WP All Import ID (found in your import URL)
3. Configure other settings as needed
4. Save settings

### Finding Your Import ID

1. Go to All Import > Manage Imports
2. Click "Edit" on your import
3. Look at the URL - the ID is the number after "id="
4. Example: `...&id=11` means your Import ID is 11

== Frequently Asked Questions ==

= What is Oli Import Tracker for WP All Import? =

It is a plugin that monitors and displays the status of WooCommerce product imports when using WP All Import. It shows which products were updated or skipped during import runs.

= Do I need WP All Import for this to work? =

Yes, this plugin requires both WooCommerce and WP All Import to be installed and activated.

= How do I find my Import ID? =

Go to All Import > Manage Imports, edit your import, and look at the URL. The ID is the number after "id=" in the URL.

= Will this slow down my imports? =

No, the plugin is designed to be lightweight and does not interfere with the import process. It only reads data after imports complete.

= Can I track multiple imports? =

Yes. Add more than one Import ID in Settings > Import Tracker.

= Does this work with frequent imports? =

Yes. The plugin is designed to handle frequent imports (every 15-30 minutes) without performance issues.

= Why do I see "Never imported" for some products? =

This appears for products that have not been processed by your configured WP All Import job, or products created outside of the import process.

= Does this work with WooCommerce variations? =

Yes, the plugin works with all WooCommerce product types including variations.

= Is this compatible with other import plugins? =

This plugin is specifically designed for WP All Import and may not work with other import plugins.

== Screenshots ==

1. Product list with the import status column
2. Import status summary and filter links at the top of the product list
3. Filter products by updated or skipped status
4. Plugin settings for Import IDs and other options
5. Precise timestamps for each product

== Changelog ==

= 1.2.0 =
* Renamed the plugin to Oli Import Tracker for WP All Import
* Slug and text domain are now oli-import-tracker (main file oli-import-tracker.php)
* Migrated options, transients, and cron hooks from the wpait_ prefix to oliit_
* Settings screen: Tracked imports + Email alerts, with a separate Tools tab
* Import checkboxes instead of a default import ID of 1; scheduled jobs are preselected when detected
* Email alerts stay off until the site owner enables them
* Moved all inline JavaScript and CSS into enqueued asset files
* Aligned Stable tag, Tested up to, and plugin version
* Hardened admin AJAX, cancel-import links, and output escaping
* Exclude development files from the distributable ZIP (.distignore and .gitattributes)

= 1.1.0 =
* Support for tracking multiple import configurations
* Email alert system for import monitoring
* Debug tools for data consistency checking
* Enhanced cache management

= 1.0.0 =
* Initial release
* Import status column for WooCommerce products
* Filter products by import status (updated/skipped)
* Precise timestamps for product modifications
* Automatic cache refresh on import completion
* Settings page for configuration

== Upgrade Notice ==

= 1.2.0 =
Renames the plugin and migrates stored settings from wpait_ options to oliit_ options. No manual action is required.

= 1.0.0 =
Initial release.

== External services ==

This plugin does not call third-party HTTP APIs (no analytics, fonts, maps, captchas, or remote dashboards).

= Site email (optional) =

When email alerts are enabled, notifications are sent with WordPress `wp_mail()`. Delivery uses the site's existing mailer (the host, or an SMTP plugin if you installed one). Alerts stay disabled until you turn them on.

Data sent in an alert: site name, site URL, import names and IDs, a short issue description, and optional Action Scheduler job details already stored on the site (hook, status, last attempt). The recipient is the address you configure, or the WordPress admin email.

No data is sent to the plugin author.

WordPress privacy policy: https://wordpress.org/about/privacy/

== Privacy Policy ==

Oli Import Tracker for WP All Import does not collect, store, or transmit personal data to the plugin author. It reads import data from your local WP All Import tables to display import status. Optional email alerts use your site's mailer as described under External services.

== Support ==

For support, feature requests, or bug reports, please visit [Olivier Bigras](https://olivierbigras.com), the [GitHub repository](https://github.com/olivierbigras/wp-all-import-tracker), or the WordPress.org plugin support forum.

== Credits ==

Developed by [Olivier Bigras](https://olivierbigras.com).
