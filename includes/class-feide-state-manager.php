<?php
/**
 * FEIDE State Manager
 *
 * Manages OAuth state parameter generation, validation, and lifecycle.
 * Provides centralized, secure state management with CSRF protection.
 *
 * @package FEIDE_WordPress_Auth
 * @since 2.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Feide_State_Manager {

    /**
     * Generate a secure OAuth state parameter
     *
     * Creates a cryptographically secure random state parameter and stores it
     * as a transient with metadata (timestamp, test mode flag).
     *
     * @since 2.5.0
     * @param bool $is_test_mode Whether this is a test authentication
     * @return string The generated state parameter (32 alphanumeric characters)
     */
    public static function generate_state($is_test_mode = false) {
        // Generate cryptographically secure random state (32 alphanumeric characters)
        $state = wp_generate_password(32, false);

        // Store state data with metadata
        $state_data = array(
            'created' => time(),
            'test_mode' => $is_test_mode
        );

        // Store for 10 minutes (600 seconds)
        $result = set_transient('feide_auth_state_' . $state, $state_data, 600);

        if (!$result && WP_DEBUG) {
            error_log('FEIDE Auth: Failed to store state parameter');
        }

        return $state;
    }

    /**
     * Validate and consume an OAuth state parameter
     *
     * Validates the state parameter format, checks if it exists and hasn't expired,
     * then immediately deletes it (preventing replay attacks).
     *
     * @since 2.5.0
     * @param string $state The state parameter to validate
     * @return array|WP_Error State data on success, WP_Error on failure
     */
    public static function validate_and_consume_state($state) {
        // Sanitize input
        $state = sanitize_text_field($state);

        // Validate format (must be 32 alphanumeric characters)
        if (!preg_match('/^[a-zA-Z0-9]{32}$/', $state)) {
            return new WP_Error(
                'invalid_state_format',
                'Ugyldig state-parameter format.'
            );
        }

        // Retrieve state data
        $state_data = get_transient('feide_auth_state_' . $state);

        // Check if state exists and has valid structure
        if (!$state_data || !is_array($state_data)) {
            return new WP_Error(
                'invalid_state',
                'Ugyldig state-parameter. Mulig CSRF-angrep.'
            );
        }

        // Validate timestamp (10 minutes max)
        if (!isset($state_data['created']) || (time() - $state_data['created']) > 600) {
            delete_transient('feide_auth_state_' . $state);
            return new WP_Error(
                'expired_state',
                'State-parameter har utløpt. Vennligst prøv igjen.'
            );
        }

        // Immediately delete state (atomic consume operation)
        $deleted = delete_transient('feide_auth_state_' . $state);

        if (!$deleted && WP_DEBUG) {
            error_log('FEIDE Auth: Warning - Failed to delete state after validation');
        }

        // Return state data
        return array(
            'test_mode' => isset($state_data['test_mode']) ? $state_data['test_mode'] : false,
            'created' => $state_data['created']
        );
    }

    /**
     * Check if a state parameter exists (without consuming it)
     *
     * Useful for debugging or pre-validation checks.
     *
     * @since 2.5.0
     * @param string $state The state parameter to check
     * @return bool True if state exists and is valid
     */
    public static function state_exists($state) {
        $state = sanitize_text_field($state);

        if (!preg_match('/^[a-zA-Z0-9]{32}$/', $state)) {
            return false;
        }

        $state_data = get_transient('feide_auth_state_' . $state);

        return ($state_data && is_array($state_data));
    }

    /**
     * Clean up all expired state parameters
     *
     * Called by cron job or manually for maintenance.
     *
     * @since 2.5.0
     * @return int Number of expired states cleaned up
     */
    public static function cleanup_expired_states() {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                WHERE option_name LIKE %s
                AND option_value < %d",
                '_transient_timeout_feide_auth_state_%',
                time()
            )
        );

        if ($deleted === false) {
            if (WP_DEBUG) {
                error_log('FEIDE Auth: State cleanup failed - ' . $wpdb->last_error);
            }
            return 0;
        }

        if (WP_DEBUG && $deleted > 0) {
            error_log('FEIDE Auth: Cleaned up ' . $deleted . ' expired state parameters');
        }

        return intval($deleted);
    }
}
