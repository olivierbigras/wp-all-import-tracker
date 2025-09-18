=== WP All Import Tracker ===
Contributors: olivierbigras
Tags: woocommerce, wp-all-import, import, tracking, products
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track and display WP All Import status for WooCommerce products. Shows which products were updated or skipped during imports with precise timestamps.

== Description ==

**WP All Import Tracker** is a powerful plugin that helps you monitor and track the status of your WooCommerce product imports when using WP All Import. Get real-time notifications when imports get stuck or fail, and never wonder again which products were updated or skipped during your import runs!

### ✨ Key Features

* **🚨 Smart Import Monitoring**: Automatically detects when imports get stuck, fail, or take too long
* **📧 Intelligent Email Alerts**: Auto-enables email notifications when issues are first detected (zero configuration!)
* **📊 Import Status Column**: Adds an "Import" column to your WooCommerce product list showing the last import status
* **⏰ Precise Timestamps**: Shows exact modification times for updated products and import start time for skipped products
* **🔍 Smart Filtering**: Filter your product list to show only updated or skipped products
* **🔄 Real-time Updates**: Automatically refreshes data when imports start or complete
* **⚡ Performance Optimized**: Works seamlessly even during WooCommerce background processing
* **🎯 Zero Configuration**: Works immediately after installation with smart auto-activation

### 🚨 Import Issue Detection

* **Stuck Processing**: Detects imports stuck in processing state with no activity
* **Long Running Imports**: Alerts when imports run longer than expected
* **Failed Imports**: Monitors for imports that have failed and need attention
* **Triggered But Not Starting**: Catches imports that are scheduled but never start
* **Admin Dashboard Alerts**: Shows prominent notices in WordPress admin when issues occur

### 🎯 Perfect For

* E-commerce stores with frequent product updates
* Automated import workflows requiring reliability monitoring
* Inventory management systems
* Product data synchronization
* Import troubleshooting and monitoring
* Preventing data sync issues before they impact your store

### 🚀 How It Works

1. **Install and activate** the plugin - monitoring starts immediately!
2. **Zero configuration needed** - the plugin automatically detects your imports
3. **First issue detected?** - Email alerts auto-enable with smart testing
4. **View import status** in your WooCommerce product list
5. **Filter products** by "Updated" or "Skipped" status
6. **Get notified** via email and admin notices when imports need attention

### 📊 Import Status Information

* **Updated Products**: Shows the exact time each product was modified during the import
* **Skipped Products**: Shows products that exist on your site but weren't modified (data unchanged)
* **Import Summary**: Displays the last import date and quick filter links

### ⚙️ Settings

* **Import ID**: Configure which WP All Import to track
* **Cache Refresh Button**: Show/hide manual cache refresh option
* **Auto Refresh**: Automatically update data when imports run

### 🔧 Requirements

* WordPress 5.0 or higher
* WooCommerce 5.0 or higher
* WP All Import plugin
* PHP 7.4 or higher

### 💡 Pro Tips

* Use the filter links to quickly identify problematic products
* Monitor import frequency with the timestamp information
* Use manual cache refresh for debugging import issues
* Check the status summary to verify import success

== Installation ==

### Automatic Installation

1. Login to your WordPress admin panel
2. Go to Plugins > Add New
3. Search for "WP All Import Tracker"
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

= What is WP All Import Tracker? =

WP All Import Tracker is a plugin that monitors and displays the status of WooCommerce product imports when using WP All Import. It shows which products were updated or skipped during import runs.

= Do I need WP All Import for this to work? =

Yes, this plugin requires both WooCommerce and WP All Import to be installed and activated.

= How do I find my Import ID? =

Go to All Import > Manage Imports, edit your import, and look at the URL. The ID is the number after "id=" in the URL.

= Will this slow down my imports? =

No, the plugin is designed to be lightweight and doesn't interfere with the import process. It only reads data after imports complete.

= Can I track multiple imports? =

Currently, the plugin tracks one import at a time. You can change the Import ID in settings to track a different import.

= Does this work with frequent imports? =

Yes! The plugin is specifically designed to handle frequent imports (every 15-30 minutes) without performance issues.

= Why do I see "Never imported" for some products? =

This appears for products that haven't been processed by your configured WP All Import, or products created outside of the import process.

= Can I hide the cache refresh button? =

Yes, you can disable the cache refresh button in Settings > Import Tracker.

= Does this work with WooCommerce variations? =

Yes, the plugin works with all WooCommerce product types including variations.

= Is this compatible with other import plugins? =

This plugin is specifically designed for WP All Import and may not work with other import plugins.

== Screenshots ==

1. **Product List with Import Column** - Shows the import status for each product
2. **Import Status Summary** - Quick overview and filter links at the top of the product list
3. **Filter by Status** - Easily filter products by updated or skipped status
4. **Plugin Settings** - Configure Import ID and other options
5. **Precise Timestamps** - See exactly when each product was last modified

== Changelog ==

= 1.0.0 =
* Initial release
* Import status column for WooCommerce products
* Filter products by import status (updated/skipped)
* Precise timestamps for product modifications
* Automatic cache refresh on import completion
* Settings page for configuration
* Support for frequent imports
* Performance optimizations for large product catalogs

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP All Import Tracker. Track your WooCommerce product imports with ease!

== Support ==

For support, feature requests, or bug reports, please visit [Olivier Bigras](https://olivierbigras.com) or contact us through the WordPress.org plugin support forum.

== Privacy Policy ==

WP All Import Tracker does not collect, store, or transmit any personal data. It only reads import data from your local WP All Import tables to display import status information.

== Credits ==

Developed by [Olivier Bigras](https://olivierbigras.com) - WordPress and WooCommerce Developer. 