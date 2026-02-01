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
                    <span><?php _e('Logg inn med FEIDE', 'feide-wp-auth'); ?></span>
                </a>
            </p>
            <div class="feide-separator">
                <span><?php _e('eller', 'feide-wp-auth'); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Last inn CSS og JavaScript for innloggingsside
     */
    public function enqueue_login_styles() {
        wp_enqueue_style(
            'feide-login-css',
            FEIDE_WP_AUTH_PLUGIN_URL . 'assets/css/login.css',
            array(),
            FEIDE_WP_AUTH_VERSION
        );

        wp_enqueue_script(
            'feide-login-js',
            FEIDE_WP_AUTH_PLUGIN_URL . 'assets/js/login.js',
            array(),
            FEIDE_WP_AUTH_VERSION,
            true
        );
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
        // Generer kryptografisk sikker tilfeldig state-parameter
        $state = Feide_State_Manager::generate_state(false);

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
