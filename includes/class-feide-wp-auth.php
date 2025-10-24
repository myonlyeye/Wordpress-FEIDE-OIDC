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

        // Legg til login-knapp basert på innstilling
        $button_position = isset($this->settings['login_button_position']) ? $this->settings['login_button_position'] : 'below';

        if ($button_position === 'above') {
            // Over innloggingsskjemaet
            add_action('login_header', array($this, 'add_login_button_above'), 100);
        } else {
            // Under innloggingsskjemaet (etter submit-knappen)
            add_action('login_form', array($this, 'add_login_button_below'), 100);
        }

        add_action('login_enqueue_scripts', array($this, 'enqueue_login_styles'));
    }

    /**
     * Legg til FEIDE login-knapp over innloggingsskjemaet
     */
    public function add_login_button_above() {
        if (!$this->is_configured()) {
            return;
        }

        $auth_url = $this->get_authorization_url();
        ?>
        <div class="feide-login-container feide-above">
            <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary button-large feide-button">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 8px;">
                    <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM12 5C13.66 5 15 6.34 15 8C15 9.66 13.66 11 12 11C10.34 11 9 9.66 9 8C9 6.34 10.34 5 12 5ZM12 19.2C9.5 19.2 7.29 17.92 6 15.98C6.03 13.99 10 12.9 12 12.9C13.99 12.9 17.97 13.99 18 15.98C16.71 17.92 14.5 19.2 12 19.2Z" fill="currentColor"/>
                </svg>
                Logg inn med FEIDE
            </a>
            <div class="feide-divider">
                <span>eller logg inn med WordPress</span>
            </div>
        </div>
        <?php
    }

    /**
     * Legg til FEIDE login-knapp under innloggingsskjemaet
     */
    public function add_login_button_below() {
        if (!$this->is_configured()) {
            return;
        }

        $auth_url = $this->get_authorization_url();
        ?>
        <div class="feide-login-container feide-below">
            <div class="feide-divider">
                <span>eller</span>
            </div>
            <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary button-large feide-button">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 8px;">
                    <path d="M12 2C6.48 2 2 6.48 2 12C2 17.52 6.48 22 12 22C17.52 22 22 17.52 22 12C22 6.48 17.52 2 12 2ZM12 5C13.66 5 15 6.34 15 8C15 9.66 13.66 11 12 11C10.34 11 9 9.66 9 8C9 6.34 10.34 5 12 5ZM12 19.2C9.5 19.2 7.29 17.92 6 15.98C6.03 13.99 10 12.9 12 12.9C13.99 12.9 17.97 13.99 18 15.98C16.71 17.92 14.5 19.2 12 19.2Z" fill="currentColor"/>
                </svg>
                Logg inn med FEIDE
            </a>
        </div>
        <?php
    }

    /**
     * Last inn CSS for innloggingsside
     */
    public function enqueue_login_styles() {
        ?>
        <style>
            .feide-login-container {
                margin: 20px 0;
            }

            .feide-login-container.feide-above {
                margin-bottom: 24px;
            }

            .feide-login-container.feide-below {
                margin-top: 24px;
            }

            .feide-button {
                background-color: #0066cc !important;
                border-color: #0055aa !important;
                text-shadow: none !important;
                box-shadow: none !important;
                width: 100%;
                text-align: center;
                font-size: 16px !important;
                padding: 8px 12px !important;
                height: auto !important;
                display: flex !important;
                align-items: center;
                justify-content: center;
                transition: all 0.2s ease;
            }

            .feide-button:hover,
            .feide-button:focus {
                background-color: #0055aa !important;
                border-color: #004488 !important;
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
            }

            .feide-button svg {
                flex-shrink: 0;
            }

            .feide-divider {
                position: relative;
                text-align: center;
                margin: 20px 0;
            }

            .feide-divider::before {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                top: 50%;
                height: 1px;
                background: linear-gradient(to right, transparent, #dcdcde 20%, #dcdcde 80%, transparent);
            }

            .feide-divider span {
                position: relative;
                background: #fff;
                padding: 0 16px;
                color: #666;
                font-size: 14px;
                font-weight: 400;
            }

            .feide-above .feide-divider {
                margin-top: 16px;
                margin-bottom: 0;
            }

            .feide-below .feide-divider {
                margin-top: 0;
                margin-bottom: 16px;
            }

            /* Responsive */
            @media (max-width: 500px) {
                .feide-button {
                    font-size: 14px !important;
                }

                .feide-button svg {
                    width: 16px;
                    height: 16px;
                }
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
