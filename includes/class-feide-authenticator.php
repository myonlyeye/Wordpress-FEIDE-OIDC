<?php
/**
 * Håndterer FEIDE autentisering via OpenID Connect
 */

if (!defined('ABSPATH')) {
    exit;
}

class Feide_Authenticator {

    private $settings;

    public function __construct() {
        $this->settings = get_option('feide_wp_auth_settings', array());

        // Håndter callback fra FEIDE
        add_action('init', array($this, 'handle_callback'));

        // Håndter test-autentisering
        add_action('wp_ajax_feide_test_auth', array($this, 'handle_test_auth'));
    }

    /**
     * Håndter callback fra FEIDE
     */
    public function handle_callback() {
        if (!isset($_GET['feide-auth']) || $_GET['feide-auth'] !== 'callback') {
            return;
        }

        // Sjekk for feil
        if (isset($_GET['error'])) {
            $error_description = isset($_GET['error_description']) ? $_GET['error_description'] : $_GET['error'];

            // Hvis dette er en test, lagre feilen og redirect til admin
            $state = isset($_GET['state']) ? $_GET['state'] : '';
            if ($state && get_transient('feide_test_mode_' . $state)) {
                delete_transient('feide_test_mode_' . $state);
                set_transient('feide_test_error', $error_description, 60);
                wp_redirect(admin_url('admin.php?page=feide-wp-auth&tab=test'));
                exit;
            }

            wp_die('FEIDE autentiseringsfeil: ' . esc_html($error_description));
        }

        // Verifiser state
        if (!isset($_GET['state']) || !get_transient('feide_auth_state_' . $_GET['state'])) {
            wp_die('Ugyldig state-parameter. Mulig CSRF-angrep.');
        }

        $state = $_GET['state'];
        $is_test_mode = get_transient('feide_test_mode_' . $state);

        delete_transient('feide_auth_state_' . $state);
        if ($is_test_mode) {
            delete_transient('feide_test_mode_' . $state);
        }

        // Hent autorisasjonskode
        if (!isset($_GET['code'])) {
            wp_die('Mangler autorisasjonskode fra FEIDE.');
        }

        $code = sanitize_text_field($_GET['code']);

        // Bytt kode mot access token
        $token_data = $this->exchange_code_for_token($code);

        if (is_wp_error($token_data)) {
            if ($is_test_mode) {
                set_transient('feide_test_error', $token_data->get_error_message(), 60);
                wp_redirect(admin_url('admin.php?page=feide-wp-auth&tab=test'));
                exit;
            }
            wp_die('Feil ved henting av access token: ' . $token_data->get_error_message());
        }

        // Hent brukerinformasjon
        $user_info = $this->get_user_info($token_data['access_token']);

        if (is_wp_error($user_info)) {
            if ($is_test_mode) {
                set_transient('feide_test_error', $user_info->get_error_message(), 60);
                wp_redirect(admin_url('admin.php?page=feide-wp-auth&tab=test'));
                exit;
            }
            wp_die('Feil ved henting av brukerinformasjon: ' . $user_info->get_error_message());
        }

        // Hent gruppeinformasjon hvis konfigurert
        $group_info = array();
        if (!empty($this->settings['groupinfo_endpoint'])) {
            $group_info = $this->get_group_info($token_data['access_token']);
        }

        // Kombiner bruker- og gruppeinformasjon
        $all_attributes = array_merge($user_info, array('groups' => $group_info));

        // Hvis dette er test-modus, lagre resultatene og redirect til admin
        if ($is_test_mode) {
            $test_result = array(
                'user_info' => $user_info,
                'group_info' => $group_info,
                'token_info' => array(
                    'token_type' => isset($token_data['token_type']) ? $token_data['token_type'] : '',
                    'expires_in' => isset($token_data['expires_in']) ? $token_data['expires_in'] : '',
                    'scope' => isset($token_data['scope']) ? $token_data['scope'] : ''
                )
            );

            set_transient('feide_test_result', $test_result, 600);
            wp_redirect(admin_url('admin.php?page=feide-wp-auth&tab=test&test-success=1'));
            exit;
        }

        // Sjekk om brukeren oppfyller kriterier
        $role_check = $this->check_role_criteria($all_attributes);

        if (!$role_check['allowed']) {
            wp_die('Du har ikke tilgang til dette systemet. Kontakt administrator om du mener dette er feil.');
        }

        // Finn eller opprett bruker
        $user = $this->find_or_create_user($all_attributes, $role_check['roles']);

        if (is_wp_error($user)) {
            wp_die('Feil ved oppretting av bruker: ' . $user->get_error_message());
        }

        // Logg inn brukeren
        wp_set_auth_cookie($user->ID, true);

        // Omdiriger til dashboard
        wp_redirect(admin_url());
        exit;
    }

    /**
     * Bytt autorisasjonskode mot access token
     */
    private function exchange_code_for_token($code) {
        $response = wp_remote_post($this->settings['token_endpoint'], array(
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->settings['redirect_uri'],
                'client_id' => $this->settings['client_id'],
                'client_secret' => $this->settings['client_secret']
            ),
            'headers' => array(
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['access_token'])) {
            return new WP_Error('token_error', 'Mottok ikke access token fra FEIDE. Respons: ' . $body);
        }

        return $data;
    }

    /**
     * Hent brukerinformasjon fra FEIDE
     */
    private function get_user_info($access_token) {
        $response = wp_remote_get($this->settings['userinfo_endpoint'], array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data)) {
            return new WP_Error('userinfo_error', 'Mottok tom respons fra userinfo endpoint.');
        }

        return $data;
    }

    /**
     * Hent gruppeinformasjon fra FEIDE
     */
    private function get_group_info($access_token) {
        if (empty($this->settings['groupinfo_endpoint'])) {
            return array();
        }

        $response = wp_remote_get($this->settings['groupinfo_endpoint'], array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return is_array($data) ? $data : array();
    }

    /**
     * Sjekk om brukerens attributter oppfyller rolle-kriterier
     */
    private function check_role_criteria($attributes) {
        $role_mappings = isset($this->settings['role_mappings']) ? $this->settings['role_mappings'] : array();

        if (empty($role_mappings)) {
            // Hvis ingen rolle-mappinger er definert, gi standard tilgang
            return array('allowed' => true, 'roles' => array('subscriber'));
        }

        $matched_roles = array();

        foreach ($role_mappings as $mapping) {
            if (!isset($mapping['criteria']) || !isset($mapping['role'])) {
                continue;
            }

            $criteria_met = $this->check_criteria($attributes, $mapping['criteria'], $mapping['operator']);

            if ($criteria_met) {
                $matched_roles[] = $mapping['role'];
            }
        }

        if (empty($matched_roles)) {
            return array('allowed' => false, 'roles' => array());
        }

        return array('allowed' => true, 'roles' => array_unique($matched_roles));
    }

    /**
     * Sjekk om kriterier er oppfylt
     */
    private function check_criteria($attributes, $criteria, $operator = 'AND') {
        if (empty($criteria)) {
            return false;
        }

        $results = array();

        foreach ($criteria as $criterion) {
            $attribute_path = isset($criterion['attribute']) ? $criterion['attribute'] : '';
            $expected_value = isset($criterion['value']) ? $criterion['value'] : '';
            $comparison = isset($criterion['comparison']) ? $criterion['comparison'] : 'equals';

            $actual_value = $this->get_nested_attribute($attributes, $attribute_path);

            $results[] = $this->compare_values($actual_value, $expected_value, $comparison);
        }

        if ($operator === 'OR') {
            return in_array(true, $results, true);
        } else {
            return !in_array(false, $results, true);
        }
    }

    /**
     * Hent nested attributt-verdi
     */
    private function get_nested_attribute($data, $path) {
        $keys = explode(':', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } elseif (is_object($value) && isset($value->$key)) {
                $value = $value->$key;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Sammenlign verdier
     */
    private function compare_values($actual, $expected, $comparison) {
        if (is_array($actual)) {
            // Hvis actual er array, sjekk om expected finnes i arrayet
            return in_array($expected, $actual, true);
        }

        switch ($comparison) {
            case 'equals':
                return $actual === $expected;
            case 'contains':
                return is_string($actual) && strpos($actual, $expected) !== false;
            case 'starts_with':
                return is_string($actual) && strpos($actual, $expected) === 0;
            case 'ends_with':
                return is_string($actual) && substr($actual, -strlen($expected)) === $expected;
            case 'not_equals':
                return $actual !== $expected;
            default:
                return $actual === $expected;
        }
    }

    /**
     * Finn eller opprett WordPress-bruker
     */
    private function find_or_create_user($attributes, $roles) {
        $attribute_mapping = isset($this->settings['attribute_mapping']) ? $this->settings['attribute_mapping'] : array();

        // Hent brukernavn fra attributter
        $username_attr = isset($attribute_mapping['username']) ? $attribute_mapping['username'] : 'sub';
        $username = $this->get_nested_attribute($attributes, $username_attr);

        if (empty($username)) {
            return new WP_Error('no_username', 'Kunne ikke finne brukernavn i FEIDE-attributter.');
        }

        // Sanitize brukernavn
        $username = sanitize_user($username, true);

        // Sjekk om brukeren allerede eksisterer
        $user = get_user_by('login', $username);

        if (!$user) {
            // Sjekk om auto-oppretting er aktivert
            if (empty($this->settings['auto_create_users'])) {
                return new WP_Error('user_not_found', 'Brukeren finnes ikke og automatisk oppretting er deaktivert.');
            }

            // Opprett ny bruker
            $email_attr = isset($attribute_mapping['email']) ? $attribute_mapping['email'] : 'email';
            $email = $this->get_nested_attribute($attributes, $email_attr);

            if (empty($email)) {
                $email = $username . '@feide.no'; // Fallback email
            }

            $user_data = array(
                'user_login' => $username,
                'user_email' => sanitize_email($email),
                'user_pass' => wp_generate_password(20, true, true),
                'role' => !empty($roles) ? $roles[0] : 'subscriber'
            );

            // Legg til ekstra feltmapping
            if (isset($attribute_mapping['first_name'])) {
                $first_name = $this->get_nested_attribute($attributes, $attribute_mapping['first_name']);
                if ($first_name) {
                    $user_data['first_name'] = sanitize_text_field($first_name);
                }
            }

            if (isset($attribute_mapping['last_name'])) {
                $last_name = $this->get_nested_attribute($attributes, $attribute_mapping['last_name']);
                if ($last_name) {
                    $user_data['last_name'] = sanitize_text_field($last_name);
                }
            }

            if (isset($attribute_mapping['display_name'])) {
                $display_name = $this->get_nested_attribute($attributes, $attribute_mapping['display_name']);
                if ($display_name) {
                    $user_data['display_name'] = sanitize_text_field($display_name);
                }
            }

            $user_id = wp_insert_user($user_data);

            if (is_wp_error($user_id)) {
                return $user_id;
            }

            $user = get_user_by('ID', $user_id);
        }

        // Oppdater brukerens roller
        if (!empty($roles)) {
            $user->set_role($roles[0]); // Sett primær rolle

            // Legg til eventuelle ekstra roller
            for ($i = 1; $i < count($roles); $i++) {
                $user->add_role($roles[$i]);
            }
        }

        // Lagre FEIDE-attributter som user meta
        update_user_meta($user->ID, 'feide_attributes', $attributes);
        update_user_meta($user->ID, 'feide_last_login', current_time('mysql'));

        return $user;
    }

    /**
     * Test-autentisering (AJAX)
     */
    public function handle_test_auth() {
        check_ajax_referer('feide_test_auth', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Ingen tilgang');
        }

        // Start OAuth-flyt men med test-flagg
        set_transient('feide_test_mode', true, 600);

        $auth_url = $this->get_test_authorization_url();

        wp_send_json_success(array('redirect_url' => $auth_url));
    }

    /**
     * Generer test-autoriserings-URL
     */
    private function get_test_authorization_url() {
        $state = wp_create_nonce('feide_test_state');
        set_transient('feide_auth_state_' . $state, true, 600);
        set_transient('feide_test_mode_' . $state, true, 600);

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
