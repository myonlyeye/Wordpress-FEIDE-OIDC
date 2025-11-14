# Changelog

All notable changes to the FEIDE WordPress Authentication plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Credits

**Created by:** Odin & Claude

This plugin is a collaboration between:
- **Odin** - Project vision, requirements, testing, and domain expertise
- **Claude** (Anthropic) - Code implementation, architecture, and documentation

A testament to what human creativity and AI capabilities can achieve together! 🚀

## [2.4.0] - 2025-11-14

### Added
- **Settings Import/Export functionality** - New dedicated tab for migrating configurations between environments
  - **Export with granular control** - Choose exactly what to export:
    - OpenID Connect settings (endpoints, scope)
    - Credentials (Client ID/Secret) with security warning
    - Attribute mapping configuration
    - Role assignment rules
    - User settings (auto-create, default role, etc.)
  - **Smart Import with preview** - See what will be imported before confirming
    - JSON file upload with validation
    - Visual preview of changes
    - Automatic backup before import
    - Error handling and validation
  - **URL Replacement Tool** - Migrate between dev/staging/prod environments
    - Find and replace URLs across all settings
    - Batch update redirect URIs, endpoints, and custom URLs
    - Perfect for environment migrations
  - **Automatic Backup System**
    - Backup created automatically before each import
    - One-click restore from backup
    - Download backup as JSON file
    - Manual backup deletion

### Features
- **Export formats** - Clean JSON files with version tracking
- **Import validation** - Ensures data integrity before applying changes
- **Security warnings** - Clear alerts when exporting sensitive credentials
- **User-friendly interface** - Two-column layout with clear instructions
- **Progress feedback** - Success/error messages for all operations

### Use Cases
- **Environment Migration** - Easily move settings from dev to staging to production
- **Configuration Backup** - Create snapshots of your configuration
- **Multi-site Setup** - Replicate settings across multiple WordPress installations
- **Disaster Recovery** - Quickly restore working configurations
- **Team Collaboration** - Share configuration templates (excluding credentials)

### Technical Implementation
- 6 new AJAX endpoints for import/export operations
- Nonce-protected with capability checks
- JSON-based configuration format
- Automatic WordPress options backup
- Clean error handling and user feedback

## [2.3.0] - 2025-11-14

### Security
- **CRITICAL: Fixed OAuth state parameter generation** - State parameter now uses cryptographically secure random values instead of deterministic nonces
  - Replaced `wp_create_nonce()` with `wp_generate_password(32, false)` for true randomness
  - Prevents potential race condition attacks where authorization codes could be intercepted and replayed
  - Eliminates predictability in state values that could enable MITM attacks
  - Each authentication request now has a unique, unguessable state parameter

### Changed
- **Enhanced OAuth security** - State parameter generation now provides proper entropy for OAuth 2.0 security
  - Protects against authorization code interception
  - Prevents state parameter prediction
  - Maintains backward compatibility with existing callback handler

### Technical Details
The previous implementation used `wp_create_nonce('feide_auth_state')` which generates the same value for all unauthenticated users within a 12-hour window. This created a security vulnerability where:
- State values were predictable and could be guessed
- Race condition existed where intercepted authorization codes could be used before the legitimate user
- MITM attackers could potentially intercept callbacks on insecure networks

The new implementation generates 32 characters of cryptographically secure random data, ensuring each authentication attempt has a unique, unguessable state parameter.

### Recommended Actions
- **Update immediately** - This is a critical security fix for OAuth flow
- No configuration changes required
- Existing functionality remains unchanged

## [2.2.0] - 2025-01-27

### Security
- **CRITICAL: Removed client secret from debug logs** - Client secret is no longer logged (not even preview). Only logs if secret is configured and its length.
- **Reduced information leakage in error messages** - Error responses from FEIDE no longer expose full response body to users. Only structured error/error_description fields are shown.
- **Enhanced credential protection** - Improved handling of sensitive data throughout the codebase

### Added
- **Toggleable debug logging** - New setting to enable/disable debug data collection for privacy
  - Located in OpenID Settings → Debug Settings
  - When disabled, no sensitive debug data is stored
  - Can be toggled without affecting functionality
- **Debug data cleanup** - New button in Debug tab to delete all stored debug information
  - Clears all FEIDE-related transients from database
  - Includes confirmation dialog
  - Useful for privacy compliance
- **Debug status indicators** - Debug tab now shows whether logging is enabled or disabled
- **Configurable redirect after login** - Set custom URL where users are sent after successful authentication
  - Default: Homepage
  - Can be any page (e.g., dashboard, profile page, custom portal)
  - Prevents "access denied" for users without admin permissions
- **Named role rules** - Add custom names to role rules for easier management
  - Optional but helpful for organizations with many rules
  - Names shown in rule headers and debug output

### Changed
- **Enhanced role evaluation debugging** - All role rules now show detailed evaluation results
  - Visual indicators (✅/❌) for matched/unmatched rules
  - Shows operator and all criteria per rule
  - Makes troubleshooting much easier
- **Improved access denied messages** - Admins now see detailed rule evaluation when users are denied access
- **Better logging practices** - All debug logging now respects the enable_debug_logging setting

### Security Fixes
- Client secret no longer appears in logs (addresses potential credential reconstruction)
- Error messages sanitized to prevent information disclosure
- Full response bodies only logged to error_log, never shown to users

### Recommended Actions
- **Update immediately** if you have WP_DEBUG enabled in production
- **Enable debug logging only when troubleshooting**
- **Clear debug data** after troubleshooting sessions

## [2.1.0] - 2025-01-26

### Added
- **Wildcard support in attribute paths** - Use `*` to match all elements in an array
  - Example: `groups:*:id` matches if ANY group has the specified ID
  - Example: `groups:*:displayName` matches if ANY group has the specified name
  - Works with all comparison operators (equals, contains, starts_with, ends_with, not_equals)
  - Eliminates need for multiple identical rules with different array indices
- **Interactive help in admin panel** - New info box in Role Assignment tab explaining wildcard usage with examples
- **Enhanced placeholder text** - Input fields now show wildcard examples
- **Tooltip documentation** - Hover tooltips on attribute input fields explain wildcard syntax

### Changed
- `get_nested_attribute()` - Now recursively processes wildcards and returns array of matching values
- `compare_values()` - Enhanced to handle arrays from wildcard matching (returns true if ANY value matches)

### Improved
- Role criteria matching is now more flexible and powerful
- Reduced need for duplicate role rules
- Better support for dynamic group memberships

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

| Version | Date | Highlights |
|---------|------|------------|
| **2.4.0** | 2025-11-14 | 📦 Settings Import/Export: Environment migration & backup system |
| **2.3.0** | 2025-11-14 | 🔐 Critical OAuth security fix: Cryptographically secure state generation |
| **2.2.0** | 2025-01-27 | 🔒 Security fixes: Client secret protection, debug toggle |
| **2.1.0** | 2025-01-26 | 🌟 Wildcard support for attribute matching |
| **2.0.0** | 2025-01-26 | 🔄 Major refactoring: external assets, cron cleanup, error logging |
| **1.1.0** | 2025-01-XX | 🎨 Improved button design with FEIDE branding |
| **1.0.0** | 2025-01-XX | 🎉 Initial release with full FEIDE authentication |

## Upgrade Path

### From 2.3 to 2.4
- **New features** - Import/Export functionality for easy configuration management
- No breaking changes - fully backward compatible
- No action required after update
- New capabilities:
  - Export settings for backup or migration
  - Import settings from other installations
  - URL replacement tool for environment migration
  - Automatic backup before imports

### From 2.2 to 2.3 (CRITICAL SECURITY UPDATE)
- **Critical OAuth security fix** - Update immediately to fix state parameter vulnerability
- No breaking changes - fully backward compatible
- No action required after update
- Protects against:
  - State parameter prediction attacks
  - Authorization code interception and replay
  - MITM attacks on OAuth flow

### From 2.1 to 2.2 (RECOMMENDED SECURITY UPDATE)
- **Critical security fixes** - Update immediately if using WP_DEBUG in production
- No breaking changes - fully backward compatible
- New features:
  - Debug logging is now opt-in (disabled by default)
  - Configure redirect URL after login
  - Name your role rules for better organization
- Action items:
  - Review and enable debug logging only when needed
  - Clear old debug data if privacy is a concern
  - Configure redirect URL if users don't need admin access

### From 2.0 to 2.1
- Seamless upgrade - no action required
- Start using wildcards in new role rules
- Old rules without wildcards still work perfectly

### From 1.x to 2.x
- No breaking changes - fully backward compatible
- New features are opt-in
- Existing role rules continue to work
- Wildcards are optional enhancement

## Statistics

- **Total commits:** 10+
- **Lines of code:** ~3000+
- **Files:** 9 core files + assets
- **Test coverage:** Manual testing with FEIDE Dataporten
- **Documentation:** Comprehensive README + CHANGELOG

For upgrade instructions and breaking changes, see [README.md](README.md).

---

*Made with ❤️ by Odin & Claude*
