# Changelog

All notable changes to WP All Import Tracker will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Support for tracking multiple import configurations simultaneously
- Enhanced email alert system with configurable thresholds
- Automatic inconsistency detection and repair
- Improved debug tools for troubleshooting import issues

### Changed
- Updated settings interface to support multiple import IDs
- Enhanced "Currently Tracking" section to show all configured imports
- Improved cache management for better performance
- Better error handling and user feedback

### Fixed
- Fixed "Import ID 1 not found" warning when using multiple imports
- Resolved cache invalidation issues
- Fixed product count inconsistencies
- Improved import status detection accuracy

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

### Features
- Tracks which products were updated or skipped during imports
- Shows precise timestamps for updated products
- Filters products by import status
- Displays import information in product list
- Automatic cache management
