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
     * How long a state parameter stays valid for completing a login, in seconds.
     *
     * A federated login can take a while: Feide shows a consent screen the first
     * time a user visits a service, the home organization may bounce the user to
     * Entra ID, and MFA enrolment adds more steps. 10 minutes was too tight and
     * made first-time logins fail.
     *
     * @since 2.7.0
     */
    const STATE_LIFETIME = 1800;

    /**
     * How long the state transient is kept in storage, in seconds.
     *
     * Deliberately longer than STATE_LIFETIME. WordPress makes an expired
     * transient simply disappear, so if storage expired at the same moment the
     * state became invalid we could never tell "this login took too long" apart
     * from "this state never existed" - and every slow login was reported to the
     * user as a possible CSRF attack.
     *
     * @since 2.7.0
     */
    const STATE_STORAGE_TTL = 3600;

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

        // Stored longer than the state is valid - see STATE_STORAGE_TTL
        $result = set_transient('feide_auth_state_' . $state, $state_data, self::STATE_STORAGE_TTL);

        if (!$result && WP_DEBUG) {
            error_log('FEIDE Auth: Failed to store state parameter');
        }

        return $state;
    }

    /**
     * Validate and consume an OAuth state parameter
     *
     * Validates the state parameter format, checks if it exists and is still within
     * STATE_LIFETIME, then immediately deletes it (preventing replay attacks).
     *
     * Returns distinct error codes so the caller can react sensibly:
     * - invalid_state_format: not a state this plugin could ever have issued
     * - invalid_state:        unknown or already consumed (double callback)
     * - expired_state:        issued here, but the login took too long
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

        // State finnes ikke i det hele tatt. Enten er den allerede brukt (dobbel
        // callback: oppdatering, tilbakeknapp, forhåndslasting), eller så er den
        // aldri utstedt herfra. Callback-handleren skiller på disse to.
        if (!$state_data || !is_array($state_data)) {
            return new WP_Error(
                'invalid_state',
                'Innloggingen kunne ikke fullføres. Dette skjer vanligvis hvis siden ble '
                . 'oppdatert eller du brukte tilbakeknappen underveis. Prøv å logge inn på nytt.'
            );
        }

        // State finnes, men er for gammel. Fordi lagringen varer lenger enn
        // gyldigheten (se STATE_STORAGE_TTL) kan vi faktisk nå denne grenen og gi
        // brukeren en ærlig forklaring i stedet for en sikkerhetsadvarsel.
        if (!isset($state_data['created']) || (time() - $state_data['created']) > self::STATE_LIFETIME) {
            delete_transient('feide_auth_state_' . $state);
            return new WP_Error(
                'expired_state',
                'Innloggingen tok for lang tid og må gjentas. Prøv å logge inn på nytt.'
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
