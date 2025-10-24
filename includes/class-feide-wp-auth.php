<?php
/**
 * Hovedklasse for FEIDE WordPress Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class Feide_WP_Auth {

    private $settings;

    public function __construct() {
        $this->settings = get_option('feide_wp_auth_settings', array());
    }

    /**
     * Initialiser plugin
     */
    public function init() {
        // Last inn admin-panel
        if (is_admin()) {
            require_once FEIDE_WP_AUTH_PLUGIN_DIR . 'admin/class-feide-admin.php';
            new Feide_WP_Auth_Admin();
        }

        // Last inn autentiseringshåndterer
        require_once FEIDE_WP_AUTH_PLUGIN_DIR . 'includes/class-feide-authenticator.php';
        new Feide_Authenticator();

        // Legg til login-knapp INNE i innloggingsskjemaet
        add_action('login_form', array($this, 'add_login_button'));
        add_action('login_enqueue_scripts', array($this, 'enqueue_login_styles'));
    }

    /**
     * Legg til FEIDE login-knapp i innloggingsskjemaet
     */
    public function add_login_button() {
        if (!$this->is_configured()) {
            return;
        }

        $auth_url = $this->get_authorization_url();
        ?>
        <div class="feide-separator">
            <span>eller</span>
        </div>
        <p class="feide-login-button">
            <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary button-large">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 8px;">
                    <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM12 5C13.66 5 15 6.34 15 8C15 9.66 13.66 11 12 11C10.34 11 9 9.66 9 8C9 6.34 10.34 5 12 5ZM12 19.2C9.5 19.2 7.29 17.92 6 15.98C6.03 13.99 10 12.9 12 12.9C13.99 12.9 17.97 13.99 18 15.98C16.71 17.92 14.5 19.2 12 19.2Z" fill="currentColor"/>
                </svg>
                Logg inn med FEIDE
            </a>
        </p>
        <?php
    }

    /**
     * Last inn CSS for innloggingsside
     */
    public function enqueue_login_styles() {
        ?>
        <style>
            /* Skillestrek mellom WordPress og FEIDE innlogging */
            .feide-separator {
                position: relative;
                text-align: center;
                margin: 20px 0 16px 0;
            }

            .feide-separator::before {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                top: 50%;
                height: 1px;
                background: #dcdcde;
            }

            .feide-separator span {
                position: relative;
                background: #fff;
                padding: 0 12px;
                color: #646970;
                font-size: 13px;
            }

            /* FEIDE innloggingsknapp */
            .feide-login-button {
                margin: 0 0 16px 0;
            }

            .feide-login-button .button {
                width: 100%;
                height: auto;
                padding: 8px;
                background: #0066cc;
                border-color: #0066cc;
                text-shadow: none;
                box-shadow: none;
                font-size: 14px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.15s ease;
            }

            .feide-login-button .button:hover,
            .feide-login-button .button:focus {
                background: #0055aa;
                border-color: #0055aa;
            }

            .feide-login-button .button svg {
                flex-shrink: 0;
            }
        </style>
        <?php
    }

    /**
     * Sjekk om plugin er konfigurert
     */
    private function is_configured() {
        return !empty($this->settings['client_id']) &&
               !empty($this->settings['client_secret']) &&
               !empty($this->settings['authorize_endpoint']);
    }

    /**
     * Generer autoriserings-URL
     */
    private function get_authorization_url() {
        $state = wp_create_nonce('feide_auth_state');
        set_transient('feide_auth_state_' . $state, true, 600); // 10 minutter

        $params = array(
            'client_id' => $this->settings['client_id'],
            'redirect_uri' => $this->settings['redirect_uri'],
            'response_type' => 'code',
            'scope' => $this->settings['scope'],
            'state' => $state
        );

        return $this->settings['authorize_endpoint'] . '?' . http_build_query($params);
    }
}
