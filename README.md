# FEIDE WordPress Authentication Plugin

A WordPress plugin that authenticates users against FEIDE via OpenID Connect/OAuth 2.0.

## Description

This plugin allows you to integrate FEIDE authentication into WordPress, with advanced capabilities for:
- Automatic user creation
- Flexible attribute mapping
- Role assignment based on FEIDE attributes
- Test functionality to view all received attributes

## Features

### OpenID Connect Integration
- Full support for OAuth 2.0 / OpenID Connect flow
- Secure token handling
- CSRF protection with state parameter

### Configurable Admin Panel
- Easy setup of all OpenID parameters
- Client ID and Client Secret configuration
- Configurable endpoints
- Redirect/Callback URL management

### Test Functionality
- Test login directly from admin panel
- View all attributes received from FEIDE
- Debugging tools for attribute mapping

### Attribute Mapping
- Map FEIDE attributes to WordPress user fields
- Support for nested attributes (e.g., `user:id`)
- Configurable fields:
  - Username
  - Email
  - First name
  - Last name
  - Display name

### Advanced Role Assignment
- Define criteria based on FEIDE attributes
- Support for AND/OR logic
- Multiple comparison operators:
  - Equals
  - Contains
  - Starts with
  - Ends with
  - Not equals
- Assign different WordPress roles based on attributes
- Flexible system with multiple rules
- Custom names for each rule

### Wildcard Pattern Matching
- Use `*` as wildcard in attribute paths
- Example: `groups:*:id` matches if ANY group has the specified ID
- Recursive wildcard support for nested structures
- Returns all matching values as array

### Automatic User Creation
- Create new users automatically on first login
- Configurable on/off
- Automatic role assignment based on criteria

### Security Features
- Toggleable debug logging (privacy-friendly)
- Client credentials validation
- Secure error handling (no sensitive data exposure)
- Configurable redirect after login

### Settings Import/Export
- **Export configurations** - Create JSON backups of your settings
  - Granular control: Choose what to export (credentials, mappings, rules)
  - Security warnings for sensitive data
  - Version tracking in export files
- **Import configurations** - Restore or migrate settings
  - Preview changes before importing
  - Automatic backup creation
  - JSON validation
- **URL Replacement Tool** - Migrate between environments
  - Batch replace URLs (dev → staging → prod)
  - Updates redirect URIs, endpoints, and custom URLs
- **Automatic Backup System**
  - Pre-import backups
  - One-click restore
  - Download/delete backups

## Installation

1. Download or clone this repository to your WordPress plugins folder:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/myonlyeye/fida.git feide-wordpress-auth
   ```

2. Activate the plugin in WordPress admin panel under "Plugins"

3. Go to "Settings" → "FEIDE Authentication" to configure

## Configuration

### 1. OpenID Settings

Go to the admin panel and fill in the following fields:

#### Required fields:
- **Client ID**: Your application's Client ID from FEIDE
- **Client Secret**: Your application's Client Secret from FEIDE
- **Redirect/Callback URL**: URL where FEIDE should redirect the user (must be registered with FEIDE)

#### Default FEIDE endpoints (can be changed if needed):
- **Authorize Endpoint**: `https://auth.dataporten.no/oauth/authorization`
- **Access Token Endpoint**: `https://auth.dataporten.no/oauth/token`
- **Get User Info Endpoint**: `https://auth.dataporten.no/userinfo`
- **Group User Info Endpoint**: `https://groups-api.dataporten.no/groups/me/groups`

#### Other settings:
- **Scope**: `openid profile email` (can be extended as needed)
- **Automatic user creation**: Check to enable
- **Redirect after login**: URL where users are sent after successful login (default: homepage)
- **Enable debug logging**: Toggle debug data collection on/off

### 2. Test Authentication

1. Go to the "Test Authentication" tab
2. Click "Test FEIDE Login"
3. Log in with your FEIDE account
4. View all attributes received from FEIDE
5. Use this information to configure attribute mapping and role assignment

### 3. Attribute Mapping

Go to the "Attribute Mapping" tab to define how FEIDE attributes should be mapped to WordPress user fields:

- **Username**: Default `sub` (FEIDE user ID)
- **Email**: Default `email`
- **First name**: Default `given_name`
- **Last name**: Default `family_name`
- **Display name**: Default `name`

For nested attributes, use colon as separator: `parent:child:value`

### 4. Role Assignment

Go to the "Role Assignment" tab to define which users get access and which roles they should be assigned.

#### Example 1: Municipality Employee
Create a role rule with the following criteria:
- **Rule name**: "Municipality Employees" (optional but helpful)
- **WordPress role**: Select or create appropriate role
- **Operator**: AND (all criteria must be met)
- **Criteria**:
  - Attribute: `eduPersonOrgUnitDN:norEduOrgUnitUniqueIdentifier`
  - Comparison: Equals
  - Value: `[value for municipality]`

#### Example 2: School Staff
Create a new role rule:
- **Rule name**: "School Staff"
- **WordPress role**: Select or create appropriate role
- **Operator**: AND
- **Criteria**:
  - Attribute: `eduPersonOrgDN:norEduOrgNIN`
  - Comparison: Equals
  - Value: `[value for school]`

#### Example 3: Multiple Options (OR logic)
To allow multiple attributes that grant the same role:
- **Operator**: OR (at least one criterion must be met)
- Add multiple criteria with "Add Criterion"

#### Example 4: Wildcard for Group Membership
Use wildcard (`*`) to check group membership without knowing exact index:
- **WordPress role**: Editor
- **Operator**: AND
- **Criteria**:
  - Attribute: `groups:*:id`
  - Comparison: Equals
  - Value: `fc:adhoc:abc-123-def-456`

This matches if the user is a member of ONE OR MORE groups where at least one group has `id = fc:adhoc:abc-123-def-456`.

**Other wildcard examples:**
- `groups:*:displayName` - Match group name (e.g., "Teachers", "Administration")
- `groups:*:membership:basic` - Match membership type
- `user:orgs:*:role` - Match role in any organization

**Benefits of wildcards:**
- ✅ No need to create separate rules for each group index
- ✅ Works automatically even if number of groups changes
- ✅ Easier maintenance

### 5. Import/Export

Go to the "Import/Export" tab to manage configuration backups and migrations.

#### Exporting Settings
1. Select what to export:
   - ☑ OpenID Settings (endpoints, scope) - **Recommended**
   - ☐ Client ID and Secret - **Use with caution** (sensitive data)
   - ☑ Attribute Mapping - **Recommended**
   - ☑ Role Rules - **Recommended**
   - ☑ User Settings - **Recommended**
2. Click "Download settings (JSON)"
3. Save the JSON file securely

**Security Note:** Only check "Client ID and Secret" if you need to migrate complete credentials. Never commit this file to public repositories.

#### Importing Settings
1. Click "Choose JSON file" and select your exported settings file
2. Review the preview of what will be imported
3. Click "Import settings"
4. Automatic backup is created before import
5. Page refreshes with new settings applied

**Note:** Import will overwrite existing settings. Use the automatic backup feature to restore if needed.

#### URL Replacement Tool
Perfect for migrating between environments (dev → staging → prod):

1. **Find URL**: Enter the old URL (e.g., `https://dev.example.com`)
2. **Replace with**: Enter the new URL (e.g., `https://prod.example.com`)
3. Click "Replace URLs"

This will update:
- Redirect URI
- All OAuth endpoints
- Custom redirect after login

#### Backup Management
- **Automatic backups**: Created before each import
- **Restore**: One-click restoration from backup
- **Download**: Save backup as JSON file
- **Delete**: Remove old backups when no longer needed

**Use Cases:**
- 🔄 **Environment Migration**: Move from dev to staging to production
- 💾 **Configuration Backup**: Create snapshots before making changes
- 🏢 **Multi-site Setup**: Replicate settings across installations
- 🚑 **Disaster Recovery**: Quickly restore working configurations
- 👥 **Team Collaboration**: Share configuration templates (excluding credentials)

## Usage

### For End Users
1. Go to WordPress login page
2. Click "Login with FEIDE"
3. Log in with your FEIDE account
4. Automatically created and logged into WordPress

### For Administrators
- Manage roles and access criteria in admin panel
- Test configuration without affecting production
- Monitor which attributes are received from FEIDE

## Security

The plugin implements several security measures:
- CSRF protection with state parameter and nonces
- Secure token handling
- Transient-based session management
- Sanitization of all user input
- WordPress nonces for AJAX calls
- **Secure error handling**: No sensitive data exposed in error messages
- **Client secret protection**: Not logged (even in debug mode)
- **Toggleable debug logging**: Privacy-friendly data collection

### Security Updates

**Version 2.4.0** adds settings management:
- **Import/Export with security controls** - Export settings with granular control over sensitive data
- Security warnings when exporting credentials
- Automatic backups before imports
- URL replacement for safe environment migration

**Version 2.3.0** includes critical OAuth security fix:
- **Fixed OAuth state parameter generation** - Now uses cryptographically secure random values
- Prevents state parameter prediction attacks
- Protects against authorization code interception and replay
- Eliminates MITM attack vectors in OAuth flow

**Version 2.2.0** included critical security fixes:
- Removed client secret from debug logs
- Reduced information leakage in error messages
- Enhanced security for credential handling

## Troubleshooting

### Common Issues and Solutions

#### Issue: "Invalid state parameter" or "Possible CSRF attack"
**Cause:** State parameter has expired (10 minutes) or cookies are blocked.

**Solution:**
1. Try logging in again
2. Check that the browser allows cookies
3. If the problem persists, check if server time is correctly synchronized

#### Issue: "Did not receive access token from FEIDE" or "Wrong client credentials"
**Cause:** Incorrect Client ID/Secret or redirect URI mismatch.

**Solution:**
1. Verify that **Client ID** and **Client Secret** are correct in Settings tab
2. Check that **Redirect URI** in WordPress matches **exactly** what is registered with FEIDE
   - Default: `https://yourdomain.com/wp-login.php?feide-auth=callback`
   - Must be identical (including http vs https)
3. Test with "Test FEIDE Login" function first
4. Check debug tab for detailed error message

#### Issue: "You do not have access to this system"
**Cause:** User does not meet any of the configured role rules.

**Solution:**
1. Go to **Debug tab** and see "Role Rule Evaluation" - shows exactly why access was denied
2. Compare received attribute values with expected values in role rules
3. **Quick fix:** Enable "Allow all authenticated FEIDE users" in Settings tab
4. Check that attribute paths are correct (e.g., `groups:0:id` not `group_info:0:id`)
5. Remember that comparison is case-insensitive

**Example debug output:**
```
Attribute: groups:0:displayName
Actual value: "Teachers"
Expected value: "teachers"
Result: MATCH (case-insensitive)
```

#### Issue: User is not created automatically
**Cause:** Auto-create is disabled or user doesn't meet criteria.

**Solution:**
1. Go to **Settings → Automatic user creation** and enable
2. Check that user meets at least one role rule OR "Allow all" is enabled
3. Check WordPress debug log (`wp-content/debug.log`) for error messages
4. Verify that email address is received from FEIDE (see Test tab)

#### Issue: FEIDE button doesn't appear on login page
**Cause:** Plugin not configured or JavaScript/CSS not loaded.

**Solution:**
1. Check that plugin is activated in WordPress
2. Go to Settings and fill in at least Client ID, Client Secret, and Authorize Endpoint
3. Clear browser cache and WordPress cache
4. Check that `assets/css/login.css` and `assets/js/login.js` exist and are readable

#### Issue: "Failed login" or timeout errors
**Cause:** FEIDE servers are slow or unavailable.

**Solution:**
1. All API calls have 15-second timeout - wait and try again
2. Check that FEIDE Dataporten is available: https://status.dataporten.no/
3. Contact FEIDE support if problem persists

#### Issue: Attributes show as NULL in test results
**Cause:** Wrong scope or attribute doesn't exist for user.

**Solution:**
1. Check that scope includes `openid profile email` (minimum)
2. Some attributes require extra scopes (e.g., `groups` for group information)
3. Test with another FEIDE user who has the attributes

### Debug Tools

#### Enable WordPress Debug Logging
Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Log file: `wp-content/debug.log`

#### Using Test Function
1. Go to **Test Authentication tab**
2. Click "Test FEIDE Login"
3. View all attributes received from FEIDE
4. Copy attribute paths directly to role rules

#### Using Debug Tab
Debug tab shows:
- **Latest attributes received from FEIDE** - full JSON dump
- **Role rule evaluation** - detailed comparison of each rule
- **Latest access denial** - why a user was denied access
- **Saved settings** - current configuration

**Note:** Debug logging must be enabled in Settings for data to be collected.

### Contact and Support

**For FEIDE-related questions:**
- FEIDE support: https://www.feide.no/
- FEIDE documentation: https://docs.feide.no/

**For plugin issues:**
- GitHub Issues: https://github.com/myonlyeye/fida/issues
- Check CHANGELOG.md for known issues

## Technical Details

### File Structure
```
feide-wordpress-auth/
├── feide-wordpress-auth.php    # Main file with activation/deactivation hooks
├── uninstall.php                # Cleanup on uninstall
├── includes/
│   ├── class-feide-wp-auth.php        # Main class
│   └── class-feide-authenticator.php  # Authentication logic
├── admin/
│   └── class-feide-admin.php          # Admin panel (6 tabs)
├── assets/
│   ├── css/
│   │   ├── admin.css                  # Admin panel styling
│   │   └── login.css                  # Login page styling
│   └── js/
│       ├── admin.js                   # Admin panel JavaScript
│       └── login.js                   # Login page JavaScript
├── README.md                           # This file
└── CHANGELOG.md                        # Version history
```

### Hooks and Filters
The plugin uses standard WordPress hooks:
- `plugins_loaded`: Initializes plugin
- `admin_menu`: Adds admin menu
- `admin_init`: Registers settings
- `init`: Handles OAuth callback
- `login_form`: Adds FEIDE button to login page

### Database
The plugin stores settings in WordPress options table:
- `feide_wp_auth_settings`: All plugin settings

User metadata is stored per user:
- `feide_attributes`: All FEIDE attributes from last login
- `feide_last_login`: Timestamp of last login

## Changelog

For full version history and detailed changes, see [CHANGELOG.md](CHANGELOG.md).

**Latest versions:**
- **v2.4** - Settings Import/Export: Environment migration & backup system
- **v2.3** - Critical OAuth security fix: Cryptographically secure state generation
- **v2.2** - Security fixes: Client secret protection, reduced info leakage
- **v2.1** - Wildcard support for attribute matching
- **v2.0** - Major refactoring: external assets, cron cleanup, error logging
- **v1.1** - Improved FEIDE button design and placement
- **v1.0** - Initial release

## Credits

**Created by:** Odin & Claude

This plugin is a collaboration between:
- **Odin** - Project vision, requirements, testing, and domain expertise
- **Claude** (Anthropic) - Code implementation, architecture, and documentation

An example of what human creativity and AI capabilities can achieve together! 🚀

## License

GPL v2 or later

## Support

For questions or issues, create an issue on GitHub.

## Contributing

Contributions are welcome! Feel free to send pull requests.

---

*Made with ❤️ by Odin & Claude*
