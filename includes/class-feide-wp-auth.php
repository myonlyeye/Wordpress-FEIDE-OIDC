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
        <div id="feide-login-wrapper">
            <p class="feide-login-button">
                <a href="<?php echo esc_url($auth_url); ?>" class="button button-large feide-button">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="feide-icon">
                        <path d="M12 3L1 9L5 11.18V17.18L12 21L19 17.18V11.18L21 10.09V17H23V9L12 3ZM18.82 9L12 12.72L5.18 9L12 5.28L18.82 9ZM17 15.99L12 18.72L7 15.99V12.27L12 15L17 12.27V15.99Z" fill="currentColor"/>
                    </svg>
                    <span>Logg inn med FEIDE</span>
                </a>
            </p>
            <div class="feide-separator">
                <span>eller</span>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var feideWrapper = document.getElementById('feide-login-wrapper');
                var loginForm = document.getElementById('loginform');

                if (feideWrapper && loginForm) {
                    // Flytt FEIDE-knappen til toppen av skjemaet
                    loginForm.insertBefore(feideWrapper, loginForm.firstChild);
                }
            });
        </script>
        <?php
    }

    /**
     * Last inn CSS for innloggingsside
     */
    public function enqueue_login_styles() {
        ?>
        <style>
            /* FEIDE login wrapper */
            #feide-login-wrapper {
                margin-bottom: 24px;
            }

            /* Skillestrek mellom FEIDE og WordPress innlogging */
            .feide-separator {
                position: relative;
                text-align: center;
                margin: 20px 0 0 0;
                overflow: hidden;
            }

            .feide-separator::before {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                top: 50%;
                height: 1px;
                background: linear-gradient(to right, transparent 0%, #dcdcde 10%, #dcdcde 90%, transparent 100%);
            }

            .feide-separator span {
                position: relative;
                background: #fff;
                padding: 0 16px;
                color: #646970;
                font-size: 13px;
                font-weight: 500;
                letter-spacing: 0.3px;
                text-transform: lowercase;
            }

            /* FEIDE innloggingsknapp */
            .feide-login-button {
                margin: 0;
            }

            .feide-button {
                width: 100%;
                height: auto;
                padding: 12px 16px !important;
                background: linear-gradient(135deg, #E84E0F 0%, #D63D00 100%) !important;
                border: none !important;
                border-radius: 4px !important;
                text-shadow: none !important;
                box-shadow: 0 2px 4px rgba(232, 78, 15, 0.2), 0 1px 2px rgba(0, 0, 0, 0.1) !important;
                font-size: 15px !important;
                font-weight: 600 !important;
                letter-spacing: 0.3px;
                color: #fff !important;
                text-decoration: none !important;
                display: flex !important;
                align-items: center;
                justify-content: center;
                gap: 10px;
                cursor: pointer;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                position: relative;
                overflow: hidden;
            }

            .feide-button::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 100%);
                opacity: 0;
                transition: opacity 0.2s ease;
            }

            .feide-button:hover::before,
            .feide-button:focus::before {
                opacity: 1;
            }

            .feide-button:hover,
            .feide-button:focus {
                background: linear-gradient(135deg, #D63D00 0%, #C23500 100%) !important;
                box-shadow: 0 4px 8px rgba(232, 78, 15, 0.3), 0 2px 4px rgba(0, 0, 0, 0.15) !important;
                transform: translateY(-1px);
                color: #fff !important;
            }

            .feide-button:active {
                transform: translateY(0);
                box-shadow: 0 1px 2px rgba(232, 78, 15, 0.2), 0 1px 2px rgba(0, 0, 0, 0.1) !important;
            }

            .feide-button:focus {
                outline: 2px solid #E84E0F;
                outline-offset: 2px;
            }

            .feide-icon {
                flex-shrink: 0;
                opacity: 0.95;
                transition: transform 0.2s ease;
            }

            .feide-button:hover .feide-icon {
                transform: scale(1.05);
                opacity: 1;
            }

            .feide-button span {
                position: relative;
                z-index: 1;
            }

            /* Responsiv design */
            @media (max-width: 400px) {
                .feide-button {
                    font-size: 14px !important;
                    padding: 10px 12px !important;
                }

                .feide-icon {
                    width: 18px;
                    height: 18px;
                }

                .feide-separator span {
                    font-size: 12px;
                }
            }

            /* Tilgjengelighet - høykontrast modus */
            @media (prefers-contrast: high) {
                .feide-button {
                    border: 2px solid #000 !important;
                }

                .feide-separator::before {
                    background: #000;
                }
            }

            /* Mørk modus støtte */
            @media (prefers-color-scheme: dark) {
                .feide-separator span {
                    background: #1e1e1e;
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
