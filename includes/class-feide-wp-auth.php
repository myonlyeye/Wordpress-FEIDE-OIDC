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

        // Legg til login-knapp
        add_action('login_form', array($this, 'add_login_button'));
        add_action('login_enqueue_scripts', array($this, 'enqueue_login_styles'));
    }

    /**
     * Legg til FEIDE login-knapp på innloggingssiden
     */
    public function add_login_button() {
        if (!$this->is_configured()) {
            return;
        }

        $auth_url = $this->get_authorization_url();
        ?>
        <div class="feide-login-button" style="margin-bottom: 20px;">
            <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary button-large" style="width: 100%; text-align: center;">
                Logg inn med FEIDE
            </a>
        </div>
        <div style="text-align: center; margin: 10px 0;">
            <span style="color: #666;">eller</span>
        </div>
        <?php
    }

    /**
     * Last inn CSS for innloggingsside
     */
    public function enqueue_login_styles() {
        ?>
        <style>
            .feide-login-button {
                margin-bottom: 16px;
            }
            .feide-login-button .button {
                background-color: #0066cc;
                border-color: #0055aa;
                text-shadow: none;
                box-shadow: none;
            }
            .feide-login-button .button:hover {
                background-color: #0055aa;
                border-color: #004488;
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
