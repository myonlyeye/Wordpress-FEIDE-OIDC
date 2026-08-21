# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This repository contains a **WordPress plugin for FEIDE authentication** using OpenID Connect/OAuth 2.0. FEIDE (Federated Electronic Identity) is Norway's common login system for education and research. The plugin enables automatic user creation, flexible attribute mapping, role assignment based on FEIDE attributes, and comprehensive debugging tools.

## Key Features

- **OAuth 2.0/OpenID Connect flow** with cryptographically secure state parameters
- **Automatic user creation** with configurable role assignment
- **Flexible attribute mapping** from FEIDE to WordPress user fields
- **Advanced role assignment** with wildcard support (e.g., `groups:*:id`)
- **Settings import/export** for environment migration and backup
- **Comprehensive debugging** with toggleable logging for privacy
- **Test authentication** without affecting production users

## Architecture

### Plugin Type
Standard WordPress plugin with admin panel and login integration.

### Core Components

1. **Main Plugin File** (`feide-wordpress-auth.php`)
   - Defines constants, activation/deactivation hooks
   - Schedules daily transient cleanup via WP Cron
   - Entry point that loads all other components

2. **Main Class** (`includes/class-feide-wp-auth.php`)
   - Initializes plugin on `plugins_loaded`
   - Loads admin panel (if `is_admin()`)
   - Loads authenticator for OAuth flow
   - Adds FEIDE login button to WordPress login page
   - Generates authorization URL with secure state parameter

3. **Authenticator** (`includes/class-feide-authenticator.php`)
   - Handles OAuth callback (`?feide-auth=callback`)
   - Exchanges authorization code for access token
   - Fetches user info and group info from FEIDE endpoints
   - Evaluates role criteria with AND/OR logic
   - Creates or updates WordPress users
   - Supports wildcard attribute matching (`groups:*:id`)
   - Handles test authentication mode

4. **State Manager** (`includes/class-feide-state-manager.php`)
   - Centralized OAuth state parameter management
   - Cryptographically secure state generation
   - Atomic state validation and consumption (prevents TOCTOU)
   - Methods: `generate_state()`, `validate_and_consume_state()`, `state_exists()`, `cleanup_expired_states()`

5. **Admin Panel** (`admin/class-feide-admin.php`)
   - 6 tabs: Settings, Test, Attribute Mapping, Role Assignment, Import/Export, Debug
   - AJAX handlers for test auth, import/export, URL replacement, backup management
   - Settings sanitization and validation
   - Renders admin interface with WordPress UI components

5. **Assets**
   - `assets/css/login.css` - Login page styling
   - `assets/js/login.js` - Login page functionality
   - `assets/css/admin.css` - Admin panel styling
   - `assets/js/admin.js` - Admin panel JavaScript (AJAX, dynamic UI)

### Authentication Flow

1. User clicks "Logg inn med FEIDE" button on login page
2. Plugin generates secure state parameter using `wp_generate_password(32, false)`
3. User redirected to FEIDE authorization endpoint
4. After FEIDE login, user redirected back to `?feide-auth=callback`
5. Plugin validates state parameter (CSRF protection)
6. Plugin exchanges authorization code for access token (HTTP Basic Auth)
7. Plugin fetches user info and group info using access token
8. Plugin evaluates role criteria (with wildcard support)
9. Plugin creates/updates WordPress user with assigned roles
10. User logged in and redirected to configured destination

### Attribute Matching

The plugin uses colon-separated paths to access nested attributes:
- `sub` - User ID
- `email` - Email address
- `groups:0:id` - First group's ID
- `groups:*:id` - **Wildcard**: All group IDs (returns array)
- `eduPersonOrgDN:norEduOrgNIN` - Nested organization identifier

Wildcard matching returns arrays and checks if **at least one** value matches.

### Role Assignment Logic

- Each role rule has: name, role, operator (AND/OR), criteria list
- Each criterion has: attribute path, comparison operator, expected value
- Comparison operators: equals, contains, starts_with, ends_with, not_equals
- All comparisons are **case-insensitive**
- Wildcard results: matches if any value in array satisfies comparison
- If `allow_all_authenticated` is enabled, default role is assigned to all FEIDE users

### Settings Storage

All settings stored in single WordPress option: `feide_wp_auth_settings`

Contains:
- OAuth endpoints and credentials
- Attribute mapping configuration
- Role assignment rules
- User creation settings
- Debug logging toggle

User metadata (per user):
- `feide_attributes` - All FEIDE attributes from last login
- `feide_last_login` - Timestamp

### Transients (for OAuth state and debugging)

- `feide_auth_state_{state}` - Valid for 30 minutes, stored for 60 (CSRF protection; see `Feide_State_Manager::STATE_LIFETIME`)
- `feide_test_mode_{state}` - Test authentication flag
- `feide_last_attributes` - Debug info (requires debug enabled)
- `feide_last_criteria_check` - Role evaluation debug
- `feide_access_denied_debug` - Access denial details

## Development

### Testing Locally

1. Copy plugin to WordPress installation:
   ```bash
   cp -r /path/to/Wordpress-FEIDE-OIDC /path/to/wordpress/wp-content/plugins/feide-wordpress-auth
   ```

2. Activate plugin:
   ```bash
   cd /path/to/wordpress
   php wp-cli.phar plugin activate feide-wordpress-auth
   # OR: Activate via WordPress admin panel under "Plugins"
   ```

3. Configure plugin:
   - Go to Settings → FEIDE Authentication
   - Fill in Client ID, Client Secret, and endpoints
   - Configure attribute mapping and role rules
   - Test with "Test Authentication" tab

### WordPress Development Environment

This plugin requires:
- **WordPress 5.0+** (uses modern WP functions)
- **PHP 7.4+** (uses modern PHP syntax)
- No external dependencies (uses WordPress HTTP API)

### File Modifications

When editing code:
- **Security**: Never log client secrets, even partially
- **State parameters**: Always use `wp_generate_password()` for randomness, never `wp_create_nonce()`
- **Sanitization**: Use `sanitize_text_field()`, `esc_url_raw()`, `sanitize_email()`, etc.
- **Nonces**: All AJAX calls must verify nonces with `check_ajax_referer()`
- **Capabilities**: Admin functions must check `current_user_can('manage_options')`

### Debugging

Enable WordPress debug mode in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Enable plugin debug logging:
- Go to Settings tab → Enable "Debug-logging"
- Check Debug tab for collected data
- Logs written to `wp-content/debug.log`

Test authentication without affecting production:
- Use "Test Authentication" tab
- Results saved in transients, not user records
- Shows all received FEIDE attributes

### Common Tasks

**Add new OAuth endpoint:**
1. Add field to admin form in `class-feide-admin.php` (Settings tab)
2. Add sanitization in `sanitize_settings()` method
3. Add to default options in `feide-wordpress-auth.php` activation hook
4. Use in authenticator: `$this->settings['your_endpoint']`

**Add new attribute mapping:**
1. Add field to Attribute Mapping tab in `render_mapping_tab()`
2. Add to default `attribute_mapping` array in activation hook
3. Use in `find_or_create_user()` method

**Modify role evaluation logic:**
- Edit `check_role_criteria()` in `class-feide-authenticator.php`
- Edit `check_criteria()` for criterion logic
- Edit `compare_values()` for comparison operators
- Wildcard logic in `get_nested_attribute()`

**Add new comparison operator:**
1. Add option to comparison dropdown in Role Assignment tab
2. Add case to `compare_values()` switch statement
3. Update README.md documentation

## Security Considerations

### Critical Security Rules

1. **Never use `wp_create_nonce()` for OAuth state** - Not random, same value for all unauthenticated users
2. **Always use `wp_generate_password(32, false)`** for state parameters - Cryptographically secure
3. **Never log client secrets** - Not even first/last characters (changed in v2.2.0)
4. **Validate all input** - Use WordPress sanitization functions
5. **Check capabilities** - All admin actions require `manage_options`
6. **Verify nonces** - All AJAX calls must verify with `check_ajax_referer()`
7. **Use HTTPS** - OAuth requires secure connections
8. **Sanitize error messages** - Don't expose sensitive data in user-facing errors

### Security Updates History

- **v2.7.0**: State lifetime raised to 30 min (stored 60) so first-time federated logins no longer fail; expired vs unknown state now distinguishable; replayed callbacks redirect logged-in users instead of erroring
- **v2.6.1**: Import/Export `role_rules` → `role_mappings` fix (rules were silently dropped), import sanitization via `sanitize_import()`, `redirect_after_login` URL validation
- **v2.6.0**: Fail-closed access control, removed remaining client secret exposure, fixed `not_equals` wildcard semantics, backup options deleted on uninstall, direct login start endpoint (`?feide-auth=start`)
- **v2.5.0**: TOCTOU fix for state validation, HTTP status code validation, JSON schema validation
- **v2.4.0**: Settings import/export with security warnings
- **v2.3.0**: Critical fix for OAuth state generation (cryptographic randomness)
- **v2.2.0**: Removed client secret from debug logs, reduced error leakage
- **v2.1.0**: Wildcard support (security neutral)

## Import/Export System

The plugin includes environment migration tools:

- **Export**: JSON format with version tracking, granular selection of what to export
- **Import**: Validates JSON, creates automatic backup, shows preview
- **URL Replacement**: Batch replace URLs for environment migration (dev → prod)
- **Backup Management**: Automatic backups before imports, one-click restore

AJAX endpoints (all require nonce + capability check):
- `feide_export_settings` - Generate JSON export
- `feide_import_settings` - Import from JSON
- `feide_replace_urls` - Find/replace URLs
- `feide_restore_backup` - Restore from backup
- `feide_download_backup` - Download backup as JSON
- `feide_delete_backup` - Delete backup

## Admin Panel Tabs

1. **Settings (OpenID Innstillinger)** - OAuth credentials and endpoints
2. **Test Authentication (Test Autentisering)** - Test login without affecting users
3. **Attribute Mapping (Attributt-mapping)** - Map FEIDE attributes to WordPress fields
4. **Role Assignment (Rolletildeling)** - Define role criteria with AND/OR logic
5. **Import/Export (Import/Export)** - Backup, migrate, restore settings
6. **Debug** - View collected debug data (requires debug logging enabled)

## Version Information

Current version: **2.7.0** (defined in `feide-wordpress-auth.php`)

Version history in `CHANGELOG.md`.

## Credits

Created by **Odin & Claude** - A collaboration between human creativity (Odin) and AI capabilities (Claude/Anthropic).
