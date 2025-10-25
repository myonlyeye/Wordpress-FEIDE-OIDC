# Changelog

All notable changes to the FEIDE WordPress Authentication plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2025-01-26

### Added
- **External CSS and JavaScript files** - Moved inline styles and scripts to separate files for better performance and caching
  - `assets/css/login.css` for login page styles
  - `assets/js/login.js` for login page functionality
- **Automatic transient cleanup** - Daily WordPress cron job to clean up expired FEIDE transients from database
- **Uninstall script** - Complete cleanup of all plugin data when plugin is deleted (`uninstall.php`)
- **API timeout configuration** - All external API calls now have 15-second timeout to prevent hanging
- **Security improvements**:
  - Added `autocomplete="off"` to Client Secret field
  - Improved transient expiration handling
- **Settings versioning** - Added version tracking to settings for future migrations
- **Internationalization (i18n)** - Added text domain support for translations on login page
- **Changelog file** - This file! Track all changes between versions
- **Comprehensive error logging** - All critical errors now logged to WordPress debug.log when WP_DEBUG is enabled
- **User data updates** - Existing users automatically get updated information from FEIDE on each login (email, name, etc.)

### Changed
- **Login button placement** - Moved from inline JavaScript to proper WordPress enqueue system
- **FEIDE button branding** - Updated to official FEIDE orange/red gradient colors (#E84E0F → #D63D00)
- **Button icon** - Changed to education-themed graduation cap icon
- **Improved accessibility**:
  - Better keyboard navigation support
  - High contrast mode compatibility
  - Dark mode support
- **Better performance** - Reduced page load time by externalizing CSS/JS
- **User updates** - Existing WordPress users now get their profile data updated from FEIDE on each login
- **Enhanced README** - Comprehensive troubleshooting section with solutions to common problems

### Fixed
- Removed obsolete `login_button_position` setting that was no longer in use
- Fixed potential memory issues from accumulated transients
- Improved error handling in API calls

### Removed
- Inline CSS and JavaScript from login page (now in external files)
- Obsolete `login_button_position` configuration option

## [1.1.0] - 2025-01-XX

### Added
- Improved FEIDE login button design with modern gradient
- Login button repositioning to top of form
- Better visual separation between FEIDE and WordPress login

### Changed
- Button now appears above username field instead of below WordPress login button
- Updated button styling for better brand consistency

### Fixed
- Fixed attribute path display in test results to match role-check format
- Corrected separator placement

## [1.0.0] - 2025-01-XX

### Added
- Initial release
- OpenID Connect / OAuth 2.0 authentication with FEIDE
- Configurable admin panel with 5 tabs:
  - OpenID Settings
  - Test Authentication
  - Attribute Mapping
  - Role Assignment
  - Debug Information
- HTTP Basic Authentication for token exchange
- Comprehensive test functionality showing all FEIDE attributes
- Flexible role assignment based on FEIDE attributes
- Support for AND/OR logic in role criteria
- Multiple comparison operators (equals, contains, starts_with, ends_with, not_equals)
- Automatic user creation on first login
- Nested attribute support (e.g., `groups:0:displayName`)
- Case-insensitive attribute comparison
- Debug logging for troubleshooting
- CSRF protection with state parameter
- Integration with FEIDE Dataporten endpoints

### Security
- HTTP Basic Authentication for OAuth token exchange
- State parameter for CSRF protection
- Secure transient-based session management (10-minute timeout)
- Sanitized user input throughout

## [Unreleased]

Features planned for future releases:

- Single Logout (SLO) support
- Token refresh functionality
- User profile integration showing FEIDE data
- Export/import of plugin settings
- Webhook notifications on user creation
- Multisite network support
- Password reset integration for FEIDE users
- PHPUnit automated tests
- Enhanced error logging
- Settings migration system

---

## Version History Summary

- **2.0.0** - Major refactoring: external assets, cron cleanup, uninstall script, security improvements
- **1.1.0** - Improved button design and positioning
- **1.0.0** - Initial release with full FEIDE authentication

For upgrade instructions and breaking changes, see [README.md](README.md).
