<?php
/**
 * Plugin Name: FEIDE WordPress Authentication
 * Plugin URI: https://github.com/myonlyeye/fida
 * Description: Authenticates WordPress users against FEIDE via OpenID Connect/OAuth 2.0
 * Version: 2.6.1
 * Author: Odin & Claude
 * Author URI: https://github.com/myonlyeye
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: feide-wp-auth
 * Domain Path: /languages
 *
 * Created by Odin with assistance from Claude (Anthropic)
 * A collaboration between human creativity and AI capabilities
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FEIDE_WP_AUTH_VERSION', '2.6.1');
define('FEIDE_WP_AUTH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FEIDE_WP_AUTH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FEIDE_WP_AUTH_PLUGIN_FILE', __FILE__);

// Last inn klasser
require_once FEIDE_WP_AUTH_PLUGIN_DIR . 'includes/class-feide-state-manager.php';
require_once FEIDE_WP_AUTH_PLUGIN_DIR . 'includes/class-feide-wp-auth.php';

/**
 * Aktivering av plugin
 */
function feide_wp_auth_activate() {
    // Opprett nødvendige databasetabeller og innstillinger
    $default_options = array(
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => site_url('/wp-login.php?feide-auth=callback'),
        'scope' => 'openid profile email',
        'authorize_endpoint' => 'https://auth.dataporten.no/oauth/authorization',
        'token_endpoint' => 'https://auth.dataporten.no/oauth/token',
        'userinfo_endpoint' => 'https://auth.dataporten.no/userinfo',
        'groupinfo_endpoint' => 'https://groups-api.dataporten.no/groups/me/groups',
        'auto_create_users' => true,
        'allow_all_authenticated' => false,
        'default_role' => 'subscriber',
        'attribute_mapping' => array(
            'username' => 'sub',
            'email' => 'email',
            'first_name' => 'given_name',
            'last_name' => 'family_name',
            'display_name' => 'name'
        ),
        'role_mappings' => array(),
        'settings_version' => '2.2.0'
    );

    add_option('feide_wp_auth_settings', $default_options);

    // Schedule transient cleanup (daily)
    if (!wp_next_scheduled('feide_cleanup_old_transients')) {
        wp_schedule_event(time(), 'daily', 'feide_cleanup_old_transients');
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'feide_wp_auth_activate');

/**
 * Deaktivering av plugin
 */
function feide_wp_auth_deactivate() {
    // Clear scheduled cleanup
    $timestamp = wp_next_scheduled('feide_cleanup_old_transients');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'feide_cleanup_old_transients');
    }

    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'feide_wp_auth_deactivate');

/**
 * Start plugin
 */
function feide_wp_auth_init() {
    $plugin = new Feide_WP_Auth();
    $plugin->init();
}
add_action('plugins_loaded', 'feide_wp_auth_init');

/**
 * Cleanup old FEIDE transients (runs daily via cron)
 */
function feide_cleanup_old_transients() {
    global $wpdb;

    // Get all expired FEIDE transients
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE %s
            AND option_name NOT LIKE %s
            AND option_value < %d",
            '_transient_timeout_feide_%',
            '_transient_timeout_feide_last_%', // Keep debug transients longer
            time()
        )
    );

    // Log cleanup results
    if ($deleted === false) {
        if (WP_DEBUG) {
            error_log('FEIDE Auth: Failed to cleanup expired transients - ' . $wpdb->last_error);
        }
    } elseif (WP_DEBUG && $deleted > 0) {
        error_log('FEIDE Auth: Cleaned up ' . $deleted . ' expired transient timeouts');
    }

    // Also delete the transient values for those expired timeouts
    $deleted_values = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE %s
            AND option_name NOT IN (
                SELECT REPLACE(option_name, '_transient_timeout_', '_transient_')
                FROM {$wpdb->options}
                WHERE option_name LIKE %s
            )",
            '_transient_feide_%',
            '_transient_timeout_feide_%'
        )
    );

    // Log cleanup results for values
    if ($deleted_values === false) {
        if (WP_DEBUG) {
            error_log('FEIDE Auth: Failed to cleanup orphaned transient values - ' . $wpdb->last_error);
        }
    } elseif (WP_DEBUG && $deleted_values > 0) {
        error_log('FEIDE Auth: Cleaned up ' . $deleted_values . ' orphaned transient values');
    }
}
add_action('feide_cleanup_old_transients', 'feide_cleanup_old_transients');
