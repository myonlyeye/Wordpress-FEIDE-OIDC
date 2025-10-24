<?php
/**
 * Plugin Name: FEIDE WordPress Authentication
 * Plugin URI: https://github.com/myonlyeye/fida
 * Description: Autentiserer WordPress-brukere mot FEIDE via OpenID Connect/OAuth 2.0
 * Version: 1.1.0
 * Author: Your Name
 * Author URI: https://github.com/myonlyeye
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: feide-wp-auth
 * Domain Path: /languages
 */

// Forhindre direkte tilgang
if (!defined('ABSPATH')) {
    exit;
}

// Definer plugin-konstanter
define('FEIDE_WP_AUTH_VERSION', '1.1.0');
define('FEIDE_WP_AUTH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FEIDE_WP_AUTH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FEIDE_WP_AUTH_PLUGIN_FILE', __FILE__);

// Last inn hovedklassen
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
        'login_button_position' => 'below',
        'attribute_mapping' => array(
            'username' => 'sub',
            'email' => 'email',
            'first_name' => 'given_name',
            'last_name' => 'family_name',
            'display_name' => 'name'
        ),
        'role_mappings' => array()
    );

    add_option('feide_wp_auth_settings', $default_options);

    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'feide_wp_auth_activate');

/**
 * Deaktivering av plugin
 */
function feide_wp_auth_deactivate() {
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
