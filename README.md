# Oli Import Tracker for WP All Import

A WordPress plugin that helps you monitor which WooCommerce products were updated or skipped during WP All Import runs. Shows precise timestamps and supports monitoring multiple imports simultaneously.

## Features

- **Import Column**: Adds an "Import" column to your WooCommerce product list showing the last import date and status
- **Product Filtering**: Filter products by import status (Updated/Skipped) using submenu links
- **Multiple Import Support**: Track multiple WP All Import configurations simultaneously
- **Precise Timestamps**: Shows exact modification dates for updated products
- **Automatic Cache Management**: Automatically refreshes cache when imports start/finish
- **Email Alerts**: Optional email notifications for import failures and issues
- **Debug Tools**: Built-in tools to check import data consistency

## Requirements

- WordPress 5.0 or higher
- WooCommerce 5.0 or higher
- WP All Import plugin
- PHP 7.4 or higher

## Installation

1. Download the plugin files
2. Upload the `oli-import-tracker` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings > Import Tracker to configure your import IDs

## Configuration

### Finding Your Import ID

1. Go to **All Import > Manage Imports** in your WordPress admin
2. Click on an import to edit it
3. Look at the URL - the ID is the number in the `id=` parameter
4. Example: `...&id=11` means Import ID is 11

### Setting Up Multiple Imports

1. Go to **Settings > Import Tracker**
2. Add your import IDs (one per line)
3. Click "Detect Available Imports" to see all your configured imports
4. Save your settings

### Email Alerts (Optional)

Enable email alerts to receive notifications when:

- Imports become stale (no activity for X minutes)
- Imports run too long (exceed maximum execution time)
- Import failures are detected

## How It Works

### Product Status Detection

The plugin tracks products in two categories:

- **Updated**: Products that were processed and updated during the import
- **Skipped**: Products that existed but were not updated (skipped by import rules)

### Import Column

The "Import" column in your product list shows:

- Last modification date for updated products
- Import status (Updated/Skipped)
- Import ID (when tracking multiple imports)

### Filtering

Use the "Updated" and "Skipped" links in the product list to filter products by their import status.

## Troubleshooting

### Import ID Not Found

If you see "Import ID X not found!", make sure:

1. The import ID exists in WP All Import
2. WP All Import is installed and activated
3. The import has been run at least once

### Inconsistent Data

If the counts don't match your expectations:

1. Click "Check Import Data Consistency" in settings
2. The plugin will automatically fix common inconsistencies
3. Use "Refresh Import Cache Now" to update counts

## Changelog

See [CHANGELOG.md](CHANGELOG.md) and the changelog in `readme.txt`.

## Support

For support, feature requests, or bug reports, please visit the [GitHub Issues](https://github.com/olivierbigras/wp-all-import-tracker/issues) page.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [Olivier Bigras](https://olivierbigras.com)

## Contributing

Contributions are welcome. Please feel free to submit a Pull Request.
