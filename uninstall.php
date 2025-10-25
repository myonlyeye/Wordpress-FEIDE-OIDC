<?php
/**
 * Uninstall script for FEIDE WordPress Authentication
 *
 * This file runs when the plugin is uninstalled (deleted) from WordPress.
 * It cleans up all plugin data from the database.
 *
 * @package FEIDE_WP_Auth
 */

// Exit if accessed directly or not being uninstalled
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete plugin options
 */
delete_option('feide_wp_auth_settings');

/**
 * Delete all FEIDE-related transients
 */
global $wpdb;

// Delete all transients with feide_ prefix
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_feide_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_feide_%'");

/**
 * Delete FEIDE user meta from all users
 */
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'feide_attributes'");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'feide_last_login'");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'feide_sub'");

/**
 * Clear any scheduled cron jobs
 */
wp_clear_scheduled_hook('feide_cleanup_old_transients');

/**
 * Flush rewrite rules one last time
 */
flush_rewrite_rules();
