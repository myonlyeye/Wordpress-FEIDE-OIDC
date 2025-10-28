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
            if (WP_DEBUG) {
                error_log('FEIDE Auth: Token exchange failed - ' . $token_data->get_error_message());
            }
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
            if (WP_DEBUG) {
                error_log('FEIDE Auth: Failed to get user info - ' . $user_info->get_error_message());
            }
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

        // Lagre attributter for debugging (alltid, ikke bare ved feil)
        set_transient('feide_last_attributes', array(
            'user_info' => $user_info,
            'group_info' => $group_info,
            'all_attributes' => $all_attributes,
            'timestamp' => current_time('mysql')
        ), 3600);

        // Hvis dette er test-modus, lagre resultatene og redirect til admin
        if ($is_test_mode) {
            // Lagre i samme struktur som brukes i rolle-sjekk
            $test_result = array_merge($all_attributes, array(
                '_meta' => array(
                    'token_type' => isset($token_data['token_type']) ? $token_data['token_type'] : '',
                    'expires_in' => isset($token_data['expires_in']) ? $token_data['expires_in'] : '',
                    'scope' => isset($token_data['scope']) ? $token_data['scope'] : ''
                )
            ));

            set_transient('feide_test_result', $test_result, 600);
            wp_redirect(admin_url('admin.php?page=feide-wp-auth&tab=test&test-success=1'));
            exit;
        }

        // Sjekk om brukeren oppfyller kriterier
        $role_check = $this->check_role_criteria($all_attributes);

        if (!$role_check['allowed']) {
            if (WP_DEBUG) {
                error_log('FEIDE Auth: Access denied for user (sub: ' . ($user_info['sub'] ?? 'unknown') . ') - no matching role criteria');
            }

            // Lagre omfattende debug-info
            $debug_info = array(
                'attributes' => $all_attributes,
                'settings' => array(
                    'allow_all_authenticated' => isset($this->settings['allow_all_authenticated']) ? $this->settings['allow_all_authenticated'] : 'NOT SET',
                    'default_role' => isset($this->settings['default_role']) ? $this->settings['default_role'] : 'NOT SET',
                    'role_mappings' => isset($this->settings['role_mappings']) ? $this->settings['role_mappings'] : 'NOT SET',
                ),
                'role_check_result' => $role_check,
                'timestamp' => current_time('mysql')
            );
            set_transient('feide_access_denied_debug', $debug_info, 3600);

            // Hvis bruker er admin, vis detaljert info
            if (current_user_can('manage_options')) {
                $message = '<h1>Tilgang nektet - Debug-modus (kun synlig for administratorer)</h1>';

                // Hent debug-info fra criteria checks
                $all_criteria_checks = get_transient('feide_all_criteria_checks');

                // Analyser hvorfor tilgang ble nektet
                $message .= '<div style="background: #fff3cd; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107;">';
                $message .= '<h2>Årsak til tilgangsnekting:</h2>';

                if (empty($this->settings['allow_all_authenticated'])) {
                    $message .= '<p>❌ "Tillat alle autentiserte brukere" er IKKE aktivert</p>';
                } else {
                    $message .= '<p>✅ "Tillat alle autentiserte brukere" ER aktivert (men virket ikke?)</p>';
                }

                $role_mappings = isset($this->settings['role_mappings']) ? $this->settings['role_mappings'] : array();
                if (empty($role_mappings)) {
                    $message .= '<p>ℹ️ Ingen rolle-regler er definert</p>';
                } else {
                    $message .= '<p>ℹ️ ' . count($role_mappings) . ' rolle-regel(er) ble sjekket</p>';

                    // Vis resultat fra criteria checks
                    if ($all_criteria_checks) {
                        $message .= '<h3>Resultat av regeleval uering:</h3>';
                        $message .= '<ul style="background: #fff; padding: 15px; margin: 10px 0;">';
                        foreach ($all_criteria_checks as $check) {
                            $color = $check['criteria_met'] ? 'green' : 'red';
                            $icon = $check['criteria_met'] ? '✅' : '❌';
                            $message .= '<li><strong>' . $icon . ' ' . esc_html($check['rule_name']) . '</strong> ';
                            $message .= '(Rolle: ' . esc_html($check['role']) . ', ';
                            $message .= 'Operator: ' . esc_html($check['operator']) . ') ';
                            $message .= '<span style="color: ' . $color . ';">' . ($check['criteria_met'] ? 'MATCHET' : 'MATCHET IKKE') . '</span></li>';
                        }
                        $message .= '</ul>';
                    }

                    $message .= '<p><strong>Role check result:</strong> ' . esc_html(print_r($role_check, true)) . '</p>';
                }

                $message .= '</div>';

                $message .= '<h3>Hurtigfiks:</h3>';
                $message .= '<ol>';
                $message .= '<li><a href="' . admin_url('admin.php?page=feide-wp-auth&tab=settings') . '">Gå til innstillinger</a> og aktiver "Gi alle autentiserte FEIDE-brukere tilgang"</li>';
                $message .= '<li>Eller <a href="' . admin_url('admin.php?page=feide-wp-auth&tab=debug') . '">gå til Debug-fanen</a> for fullstendig analyse</li>';
                $message .= '</ol>';

                $message .= '<details open><summary><strong>Fullstendig debug-informasjon</strong></summary>';
                $message .= '<pre style="background: #f5f5f5; padding: 15px; overflow: auto;">' . esc_html(print_r($debug_info, true)) . '</pre>';
                $message .= '</details>';

                wp_die($message, 'Tilgang nektet - Debug', array('response' => 403));
            } else {
                wp_die('Du har ikke tilgang til dette systemet. Kontakt administrator om du mener dette er feil.');
            }
        }

        // Finn eller opprett bruker
        $user = $this->find_or_create_user($all_attributes, $role_check['roles']);

        if (is_wp_error($user)) {
            wp_die('Feil ved oppretting av bruker: ' . $user->get_error_message());
        }

        // Logg inn brukeren
        wp_set_auth_cookie($user->ID, true);

        // Omdiriger til konfigurert URL (standard: hjemmeside)
        $redirect_url = isset($this->settings['redirect_after_login']) && !empty($this->settings['redirect_after_login'])
            ? $this->settings['redirect_after_login']
            : home_url();

        if (WP_DEBUG) {
            error_log('FEIDE Auth: Redirecting user ' . $user->user_login . ' to: ' . $redirect_url);
        }

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Bytt autorisasjonskode mot access token
     */
    private function exchange_code_for_token($code) {
        // FEIDE/Dataporten krever HTTP Basic Authentication
        $client_id = $this->settings['client_id'];
        $client_secret = $this->settings['client_secret'];

        // Debug logging
        if (WP_DEBUG) {
            error_log('FEIDE Auth: Using Client ID - Length: ' . strlen($client_id) . ', Value: ' . $client_id);
            $secret_len = strlen($client_secret);
            $secret_preview = $secret_len > 0 ? substr($client_secret, 0, 8) . '...' . substr($client_secret, -4) : 'EMPTY';
            error_log('FEIDE Auth: Using Client Secret - Length: ' . $secret_len . ', Preview: ' . $secret_preview);
        }

        $auth = base64_encode($client_id . ':' . $client_secret);

        $response = wp_remote_post($this->settings['token_endpoint'], array(
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->settings['redirect_uri']
            ),
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            if (WP_DEBUG) {
                error_log('FEIDE Auth: HTTP error during token exchange - ' . $response->get_error_message());
            }
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['access_token'])) {
            if (WP_DEBUG) {
                error_log('FEIDE Auth: No access token in response - ' . substr($body, 0, 200));
            }
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
            ),
            'timeout' => 15
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
            ),
            'timeout' => 15
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
        // Sjekk om alle autentiserte brukere skal gis tilgang
        if (!empty($this->settings['allow_all_authenticated'])) {
            $default_role = isset($this->settings['default_role']) ? $this->settings['default_role'] : 'subscriber';
            return array('allowed' => true, 'roles' => array($default_role));
        }

        $role_mappings = isset($this->settings['role_mappings']) ? $this->settings['role_mappings'] : array();

        // Filtrer ut tomme/ugyldige mappinger
        $valid_mappings = array();
        foreach ($role_mappings as $mapping) {
            if (isset($mapping['criteria']) && isset($mapping['role']) && !empty($mapping['criteria'])) {
                // Sjekk om minst ett kriterium har både attribute og value
                $has_valid_criterion = false;
                foreach ($mapping['criteria'] as $criterion) {
                    if (!empty($criterion['attribute']) && !empty($criterion['value'])) {
                        $has_valid_criterion = true;
                        break;
                    }
                }
                if ($has_valid_criterion) {
                    $valid_mappings[] = $mapping;
                }
            }
        }

        if (empty($valid_mappings)) {
            // Hvis ingen gyldige rolle-mappinger er definert, gi standard tilgang
            $default_role = isset($this->settings['default_role']) ? $this->settings['default_role'] : 'subscriber';
            return array('allowed' => true, 'roles' => array($default_role));
        }

        $matched_roles = array();
        $all_debug_info = array(); // Lagre debug-info for alle regler

        if (WP_DEBUG) {
            error_log('FEIDE Auth: Starting evaluation of ' . count($valid_mappings) . ' valid role rules');
        }

        foreach ($valid_mappings as $mapping_index => $mapping) {
            $rule_name = !empty($mapping['name']) ? $mapping['name'] : 'Rolleregel #' . ($mapping_index + 1);

            if (WP_DEBUG) {
                error_log('FEIDE Auth: Evaluating rule "' . $rule_name . '" (index: ' . $mapping_index . ', role: ' . $mapping['role'] . ', operator: ' . ($mapping['operator'] ?? 'AND') . ')');
            }

            $criteria_met = $this->check_criteria($attributes, $mapping['criteria'], $mapping['operator'], $mapping_index);

            if (WP_DEBUG) {
                error_log('FEIDE Auth: Rule "' . $rule_name . '" result: ' . ($criteria_met ? 'MATCHED' : 'NOT MATCHED'));
            }

            // Hent debug-info for denne regelen
            $debug_key = 'feide_criteria_check_' . $mapping_index;
            $debug_info = get_transient($debug_key);
            if ($debug_info) {
                $all_debug_info[] = array(
                    'rule_name' => $rule_name,
                    'role' => $mapping['role'],
                    'operator' => $mapping['operator'] ?? 'AND',
                    'criteria_met' => $criteria_met,
                    'comparisons' => $debug_info
                );
                delete_transient($debug_key); // Rydd opp
            }

            if ($criteria_met) {
                $matched_roles[] = $mapping['role'];
                if (WP_DEBUG) {
                    error_log('FEIDE Auth: Added role "' . $mapping['role'] . '" to matched_roles. Total matched roles so far: ' . count($matched_roles));
                }
            }
        }

        // Lagre samlet debug-info for alle regler
        set_transient('feide_all_criteria_checks', $all_debug_info, 3600);

        // Debug logging
        if (WP_DEBUG) {
            error_log('FEIDE Auth: Evaluated ' . count($valid_mappings) . ' role rules, matched ' . count($matched_roles) . ' roles: ' . print_r($matched_roles, true));
            error_log('FEIDE Auth: matched_roles empty? ' . (empty($matched_roles) ? 'YES' : 'NO'));
        }

        if (empty($matched_roles)) {
            if (WP_DEBUG) {
                error_log('FEIDE Auth: NO ROLES MATCHED - DENYING ACCESS');
            }
            return array('allowed' => false, 'roles' => array());
        }

        if (WP_DEBUG) {
            error_log('FEIDE Auth: AT LEAST ONE ROLE MATCHED - GRANTING ACCESS with roles: ' . implode(', ', array_unique($matched_roles)));
        }

        return array('allowed' => true, 'roles' => array_unique($matched_roles));
    }

    /**
     * Sjekk om kriterier er oppfylt
     */
    private function check_criteria($attributes, $criteria, $operator = 'AND', $mapping_index = null) {
        if (empty($criteria)) {
            return false;
        }

        $results = array();
        $debug_comparisons = array();

        foreach ($criteria as $criterion) {
            $attribute_path = isset($criterion['attribute']) ? $criterion['attribute'] : '';
            $expected_value = isset($criterion['value']) ? $criterion['value'] : '';
            $comparison = isset($criterion['comparison']) ? $criterion['comparison'] : 'equals';

            $actual_value = $this->get_nested_attribute($attributes, $attribute_path);

            $result = $this->compare_values($actual_value, $expected_value, $comparison);
            $results[] = $result;

            // Lagre debug-info for hver sammenligning
            $debug_comparisons[] = array(
                'attribute_path' => $attribute_path,
                'actual_value' => $actual_value,
                'actual_type' => gettype($actual_value),
                'expected_value' => $expected_value,
                'comparison' => $comparison,
                'result' => $result ? 'MATCH' : 'NO MATCH'
            );
        }

        // Lagre debug-info med unik nøkkel for denne regelen
        if ($mapping_index !== null) {
            set_transient('feide_criteria_check_' . $mapping_index, $debug_comparisons, 3600);
        }

        // Behold også gammel transient for bakoverkompatibilitet
        set_transient('feide_last_criteria_check', $debug_comparisons, 3600);

        // Debug logging
        if (WP_DEBUG) {
            error_log('FEIDE Auth: check_criteria - Operator: ' . $operator . ', Total criteria: ' . count($criteria) . ', Results: ' . print_r($results, true));
        }

        if ($operator === 'OR') {
            $final_result = in_array(true, $results, true);
            if (WP_DEBUG) {
                error_log('FEIDE Auth: check_criteria - OR operator, at least one true? ' . ($final_result ? 'YES' : 'NO'));
            }
            return $final_result;
        } else {
            $final_result = !in_array(false, $results, true);
            if (WP_DEBUG) {
                error_log('FEIDE Auth: check_criteria - AND operator, no false values? ' . ($final_result ? 'YES' : 'NO'));
            }
            return $final_result;
        }
    }

    /**
     * Hent nested attributt-verdi med wildcard-støtte
     *
     * Støtter wildcard (*) for å matche alle elementer i et array.
     * Eksempel: "groups:*:id" vil returnere alle id-verdier fra alle grupper
     *
     * @param array|object $data Data å søke i
     * @param string $path Path med kolon-separerte nøkler (kan inneholde *)
     * @return mixed|array Verdi eller array av verdier hvis wildcard brukes
     */
    private function get_nested_attribute($data, $path) {
        $keys = explode(':', $path);
        $value = $data;

        foreach ($keys as $key) {
            // Sjekk for wildcard
            if ($key === '*') {
                if (!is_array($value)) {
                    return null;
                }

                // Samle verdier fra alle elementer i arrayet
                $collected_values = array();

                foreach ($value as $item) {
                    // Hvis det er flere keys etter wildcard, fortsett navigeringen
                    $remaining_keys = array_slice($keys, array_search($key, $keys) + 1);

                    if (empty($remaining_keys)) {
                        // Ingen flere keys, returner elementet direkte
                        $collected_values[] = $item;
                    } else {
                        // Fortsett å navigere med resterende keys
                        $remaining_path = implode(':', $remaining_keys);
                        $nested_value = $this->get_nested_attribute($item, $remaining_path);

                        if ($nested_value !== null) {
                            // Hvis nested value også er en array (fra nested wildcard), flatten den
                            if (is_array($nested_value) && $this->is_indexed_array($nested_value)) {
                                $collected_values = array_merge($collected_values, $nested_value);
                            } else {
                                $collected_values[] = $nested_value;
                            }
                        }
                    }
                }

                return !empty($collected_values) ? $collected_values : null;
            }

            // Normal navigering (ingen wildcard)
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
     * Sjekk om et array er indeksert (numerisk) i stedet for assosiativt
     *
     * @param array $arr Array å sjekke
     * @return bool True hvis array er indeksert
     */
    private function is_indexed_array($arr) {
        if (!is_array($arr)) {
            return false;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Sammenlign verdier med støtte for wildcard-resultater
     *
     * Når wildcard brukes, kan $actual være en array av verdier.
     * Returnerer true hvis MINST ÉN verdi i arrayet matcher.
     *
     * @param mixed $actual Faktisk verdi (kan være array fra wildcard)
     * @param mixed $expected Forventet verdi
     * @param string $comparison Sammenligningsoperatør
     * @return bool True hvis match
     */
    private function compare_values($actual, $expected, $comparison) {
        // Normaliser verdier: trim whitespace og konverter til streng for sammenligning
        if (is_string($actual)) {
            $actual = trim($actual);
        }
        if (is_string($expected)) {
            $expected = trim($expected);
        }

        // Hvis actual er array (fra wildcard eller multi-value attributt)
        if (is_array($actual)) {
            // Hvis array har ett element, bruk det elementet direkte
            if (count($actual) === 1) {
                $actual = reset($actual);
                if (is_string($actual)) {
                    $actual = trim($actual);
                }
            } else {
                // Array har flere elementer - sjekk om MINST ÉN matcher
                // Dette er spesielt viktig for wildcard-resultater (f.eks. groups:*:id)
                foreach ($actual as $value) {
                    // Rekursivt kall for å sjekke hver verdi mot forventet verdi
                    if ($this->compare_values($value, $expected, $comparison)) {
                        return true; // Minst én verdi matcher!
                    }
                }
                // Ingen verdier matchet
                return false;
            }
        }

        // Konverter til string for sammenligning hvis nødvendig
        $actual_str = is_scalar($actual) ? (string)$actual : '';
        $expected_str = is_scalar($expected) ? (string)$expected : '';

        switch ($comparison) {
            case 'equals':
                // Prøv først strict comparison, deretter case-insensitive
                if ($actual_str === $expected_str) {
                    return true;
                }
                return strcasecmp($actual_str, $expected_str) === 0;

            case 'contains':
                return stripos($actual_str, $expected_str) !== false;

            case 'starts_with':
                return stripos($actual_str, $expected_str) === 0;

            case 'ends_with':
                $len = strlen($expected_str);
                if ($len === 0) {
                    return true;
                }
                return strcasecmp(substr($actual_str, -$len), $expected_str) === 0;

            case 'not_equals':
                if ($actual_str === $expected_str) {
                    return false;
                }
                return strcasecmp($actual_str, $expected_str) !== 0;

            default:
                if ($actual_str === $expected_str) {
                    return true;
                }
                return strcasecmp($actual_str, $expected_str) === 0;
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

        if ($user) {
            // Bruker eksisterer - oppdater informasjon fra FEIDE
            $user_data = array(
                'ID' => $user->ID
            );

            $email_attr = isset($attribute_mapping['email']) ? $attribute_mapping['email'] : 'email';
            $email = $this->get_nested_attribute($attributes, $email_attr);
            if ($email && is_email($email)) {
                $user_data['user_email'] = sanitize_email($email);
            }

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

            // Oppdater bruker hvis vi har endringer
            if (count($user_data) > 1) {
                $result = wp_update_user($user_data);
                if (is_wp_error($result)) {
                    if (WP_DEBUG) {
                        error_log('FEIDE Auth: Failed to update user ' . $username . ': ' . $result->get_error_message());
                    }
                }
            }
        } else {
            // Bruker finnes ikke - sjekk om auto-oppretting er aktivert
            if (empty($this->settings['auto_create_users'])) {
                if (WP_DEBUG) {
                    error_log('FEIDE Auth: User ' . $username . ' not found and auto-create is disabled');
                }
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
                if (WP_DEBUG) {
                    error_log('FEIDE Auth: Failed to create user ' . $username . ' - ' . $user_id->get_error_message());
                }
                return $user_id;
            }

            if (WP_DEBUG) {
                error_log('FEIDE Auth: Created new user ' . $username . ' (ID: ' . $user_id . ')');
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

            // Debug logging
            if (WP_DEBUG) {
                error_log('FEIDE Auth: Assigned roles to user ' . $username . ': ' . implode(', ', $roles));
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
