# Changelog

All notable changes to Oli Import Tracker for WP All Import will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-10-21

### Added
- Option migration from the legacy `wpait_*` keys to `oliit_*` on update (copy, then delete)
- Enqueued CSS/JS assets for the settings page, product list, and admin notices
- External services documentation in `readme.txt`
- Settings tabs: Tracked imports + Email alerts, and a Tools tab for cache/diagnose/notices

### Changed
- Plugin display name is now **Oli Import Tracker for WP All Import**
- Slug, text domain, and main file are `oli-import-tracker`
- Prefix for functions, classes, options, transients, hooks, and script handles is `oliit_` / `OLIIT_`
- Default tracked imports are scheduled jobs (or none), not import ID 1
- Email alerts are never enabled automatically
- Stable tag now matches the plugin version
- Tested up to WordPress 7.1 and WooCommerce 11.2
- Settings and AJAX actions use `wp_localize_script` instead of inline scripts
- Cancel-import email links require a logged-in user with `manage_options`

### Fixed
- Stable tag was still 1.0.0 while the plugin version was 1.1.0
- GitHub support link pointed at a placeholder username
- Missing `wpait_import_ids` cleanup on uninstall (now covered for both prefixes)
- Setup notice claimed alerts would turn themselves on

## [1.1.0] - 2024-01-XX

### Added
- Multiple import tracking support
- Email alert system for import monitoring
- Debug tools for data consistency checking
- Enhanced cache management system
- Improved user interface with better feedback

### Changed
- Migrated from single import ID to multiple import IDs array
- Updated settings page to handle multiple imports
- Enhanced product filtering to work with all tracked imports
- Improved error messages and user guidance

### Fixed
- Fixed import ID validation issues
- Resolved cache inconsistency problems
- Fixed product count calculations
- Improved import status detection

### Security
- Enhanced nonce verification for all AJAX requests
- Improved input sanitization and validation
- Better capability checks for admin functions

## [1.0.0] - 2023-XX-XX

### Added
- Initial release
- Single import tracking functionality
- Import column in WooCommerce product list
- Product filtering by import status (Updated/Skipped)
- Basic cache management
- Import timestamp display
- Settings page for configuration
- Automatic cache refresh on import completion
